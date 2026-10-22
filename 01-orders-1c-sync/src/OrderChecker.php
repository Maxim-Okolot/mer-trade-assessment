<?php

declare(strict_types=1);

namespace App;

/**
 * Сверка заказов маркетплейса с номенклатурой 1С.
 *
 * Правила:
 *  - отменённые заказы и повторные записи заказов не передаются и не резервируют остаток;
 *  - остаток считается кумулятивно: заказы обрабатываются в хронологическом порядке,
 *    каждая позиция уменьшает свободный остаток карточки 1С;
 *  - дубли строк внутри заказа объединяются в одну позицию;
 *  - артикулы нормализуются (пробелы, регистр);
 *  - при нескольких карточках 1С с одним артикулом выбирается наиболее подходящая,
 *    но заказ помечается предупреждением.
 */
final class OrderChecker
{
    public const OK = 'OK';
    public const CHECK = 'CHECK';
    public const BLOCKED = 'BLOCKED';
    public const SKIPPED = 'SKIPPED';

    /**
     * @param list<mixed> $rawOrders
     */
    public function check(array $rawOrders, NomenclatureRepository $nomenclature, Logger $logger): CheckResult
    {
        $rows = [];
        $orders = [];
        $prepared = [];
        $lineCount = 0;

        foreach ($rawOrders as $index => $raw) {
            try {
                $order = $this->prepare($raw, (int) $index);
            } catch (\Throwable $e) {
                // Одна плохая запись не должна ронять обработку остальных.
                $logger->error('Заказ не разобран, запись пропущена', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);

                $key = 'invalid#' . $index;
                $orders[] = [
                    'key' => $key,
                    'id' => '?',
                    'createdAt' => '',
                    'status' => '?',
                    'verdict' => self::BLOCKED,
                ];
                $rows[] = new Row(
                    orderKey: $key,
                    orderId: '?',
                    createdAt: '',
                    orderStatus: '?',
                    verdict: self::BLOCKED,
                    lineNo: null,
                    sku: null,
                    title: null,
                    qty: null,
                    orderPrice: null,
                    price1c: null,
                    stock: null,
                    available: null,
                    code: 'INVALID_RECORD',
                    severity: 'error',
                    problem: 'Запись не удалось разобрать: ' . $e->getMessage(),
                    action: 'Проверить выгрузку площадки и повторить обработку.',
                );
                continue;
            }

            $lineCount += count($order['items']);
            $prepared[] = $order;
        }

        usort(
            $prepared,
            static fn (array $a, array $b): int => [$a['ts'], $a['id'], $a['index']] <=> [$b['ts'], $b['id'], $b['index']]
        );

        $seenIds = [];
        $reserved = [];

        foreach ($prepared as $order) {
            $key = $order['id'] . '#' . $order['index'];

            if (isset($seenIds[$order['id']])) {
                $issues = [
                    $this->issue(
                        'DUPLICATE_ORDER',
                        'warning',
                        'Повторная запись заказа в выгрузке',
                        'Игнорировать повтор; обработать первый экземпляр.',
                        ['comment' => 'первый экземпляр обработан выше'],
                    ),
                ];
                $orders[] = $this->orderEntry($key, $order, self::SKIPPED);
                $rows = array_merge($rows, $this->makeRows($key, $order, self::SKIPPED, $issues));
                $logger->info('Заказ пропущен: повторная запись', ['order' => $order['id']]);
                continue;
            }
            $seenIds[$order['id']] = true;

            $checked = $this->checkOrder($order, $nomenclature, $reserved, $logger);
            $verdict = $checked['verdict'];

            $orders[] = $this->orderEntry($key, $order, $verdict);
            $rows = array_merge($rows, $this->makeRows($key, $order, $verdict, $checked['issues']));

            if ($verdict === self::BLOCKED) {
                $logger->warning('Заказ заблокирован', ['order' => $order['id']]);
            }
        }

        return new CheckResult($rows, $orders, $lineCount, $nomenclature->count());
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, float> $reserved
     * @return array{issues: list<array<string, mixed>>, verdict: string}
     */
    private function checkOrder(array $order, NomenclatureRepository $nomenclature, array &$reserved, Logger $logger): array
    {
        $issues = [];

        if ($order['status'] === 'cancelled') {
            $issues[] = $this->issue(
                'CANCELLED',
                'info',
                'Заказ отменён площадкой',
                'Не передавать в 1С; остаток не резервировать.',
            );

            return ['issues' => $issues, 'verdict' => self::SKIPPED];
        }

        if ($order['currency'] !== '' && $order['currency'] !== 'BYN') {
            $issues[] = $this->issue(
                'CURRENCY',
                'warning',
                'Валюта заказа ' . $order['currency'] . ', ожидается BYN',
                'Проверить валюту заказа перед передачей в 1С.',
            );
        }

        if (!in_array($order['status'], ['new', 'confirmed'], true)) {
            $issues[] = $this->issue(
                'UNKNOWN_STATUS',
                'warning',
                'Неизвестный статус «' . $order['status'] . '»',
                'Уточнить статус заказа у площадки.',
            );
        }

        if ($order['items'] === []) {
            $issues[] = $this->issue(
                'EMPTY_ORDER',
                'error',
                'В заказе нет ни одной позиции',
                'Связаться с площадкой и уточнить состав заказа.',
            );

            return ['issues' => $issues, 'verdict' => self::BLOCKED];
        }

        $merged = $this->mergeLines($order['items']);
        $hasMissingPrice = false;

        foreach ($merged as $line) {
            $sku = $line['sku'];
            $lineContext = [
                'line' => $line['line'],
                'sku' => $sku,
                'title' => $line['title'],
                'qty' => $line['qty'],
            ];

            if ($line['rawSku'] !== $sku) {
                $issues[] = $this->issue(
                    'SKU_NORMALIZED',
                    'info',
                    'Артикул записан как «' . $line['rawSku'] . '»',
                    'Ничего делать не нужно: артикул нормализован при сопоставлении.',
                    $lineContext,
                );
            }

            if (count($line['lines']) > 1) {
                $issues[] = $this->issue(
                    'DUPLICATE_LINE',
                    'warning',
                    'Артикул ' . $sku . ' повторяется в заказе',
                    'Объединить количества и проверить, нет ли задвоения позиции.',
                    $lineContext + [
                        'comment' => 'строки ' . implode(', ', $line['lines']) . '; количества объединены',
                    ],
                );
            }

            if ($line['price'] === null) {
                $hasMissingPrice = true;
                $issues[] = $this->issue(
                    'MISSING_PRICE',
                    'error',
                    'В строке не указана цена',
                    'Запросить цену у площадки; заказ не передавать, пока цена не подтверждена.',
                    $lineContext,
                );
                continue;
            }

            $cards = $nomenclature->find($sku);
            if ($cards === []) {
                $issues[] = $this->issue(
                    'SKU_NOT_FOUND',
                    'error',
                    'Артикула ' . $sku . ' нет в выгрузке 1С',
                    'Проверить артикул; завести номенклатуру в 1С или отклонить позицию.',
                    $lineContext + ['price' => $line['price']],
                );
                continue;
            }

            $card = $this->pickCard($cards, $line['price']);
            if (count($cards) > 1) {
                $issues[] = $this->issue(
                    'AMBIGUOUS_NOMENCLATURE',
                    'warning',
                    'В 1С ' . count($cards) . ' карточки с артикулом ' . $sku,
                    'Выбрать актуальную карточку и почистить дубли в справочнике 1С.',
                    $lineContext + [
                        'price' => $line['price'],
                        'price1c' => $card->price,
                        'stock' => $card->stock,
                        'comment' => 'выбрана карточка ' . $card->code . ', ' . $card->name,
                    ],
                );
                $logger->warning('Неоднозначное сопоставление артикула', [
                    'order' => $order['id'],
                    'sku' => $sku,
                    'cards' => count($cards),
                    'picked' => $card->code,
                ]);
            }

            if (abs($line['price'] - $card->price) > 0.005) {
                $diff = $line['price'] - $card->price;
                $percent = $card->price > 0 ? $diff / $card->price * 100 : 0.0;
                $issues[] = $this->issue(
                    'PRICE_MISMATCH',
                    'warning',
                    'Цена в заказе ' . self::money($line['price']) . ', в 1С ' . self::money($card->price),
                    'Согласовать цену с площадкой; передавать по цене 1С или исправить заказ.',
                    $lineContext + [
                        'price' => $line['price'],
                        'price1c' => $card->price,
                        'stock' => $card->stock,
                        'comment' => 'разница ' . ($diff >= 0 ? '+' : '−') . self::money(abs($diff))
                            . ' (' . ($percent >= 0 ? '+' : '−') . number_format(abs($percent), 1, ',', '') . '%)',
                    ],
                );
            }

            $alreadyReserved = $reserved[$card->code] ?? 0.0;
            $free = $card->stock - $alreadyReserved;
            if ($line['qty'] > $free) {
                $shortage = $line['qty'] - max(0.0, $free);
                $issues[] = $this->issue(
                    'INSUFFICIENT_STOCK',
                    'warning',
                    'Не хватает ' . $shortage . ' шт.: нужно ' . $line['qty'] . ', свободно ' . max(0.0, $free),
                    'Частичная отгрузка, замена или уведомление клиента о переносе.',
                    $lineContext + [
                        'price' => $line['price'],
                        'price1c' => $card->price,
                        'stock' => $card->stock,
                        'available' => $free,
                        'comment' => 'остаток по 1С ' . self::money($card->stock),
                    ],
                );
            }
            $reserved[$card->code] = $alreadyReserved + $line['qty'];
        }

        if (!$hasMissingPrice && $order['total'] !== null) {
            $calculated = 0.0;
            foreach ($merged as $line) {
                if ($line['price'] !== null) {
                    $calculated += $line['price'] * $line['qty'];
                }
            }
            $calculated = round($calculated, 2);

            if (abs($calculated - $order['total']) > 0.005) {
                $issues[] = $this->issue(
                    'TOTAL_MISMATCH',
                    'warning',
                    'Сумма заказа ' . self::money($order['total']) . ' не равна сумме строк ' . self::money($calculated),
                    'Пересчитать заказ и уточнить сумму у площадки.',
                    ['comment' => 'разница ' . self::money(abs($order['total'] - $calculated))],
                );
            }
        }

        $hasError = false;
        $hasWarning = false;
        foreach ($issues as $issue) {
            if ($issue['severity'] === 'error') {
                $hasError = true;
            } elseif ($issue['severity'] === 'warning') {
                $hasWarning = true;
            }
        }

        $verdict = $hasError ? self::BLOCKED : ($hasWarning ? self::CHECK : self::OK);

        if ($issues === []) {
            $issues[] = $this->issue('OK', 'info', 'Расхождений нет', 'Передавать в 1С.');
        }

        return ['issues' => $issues, 'verdict' => $verdict];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function prepare(mixed $raw, int $index): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('заказ не является объектом');
        }

        $id = trim((string) ($raw['id'] ?? ''));
        if ($id === '') {
            throw new \InvalidArgumentException('не указан id заказа');
        }

        $createdAt = trim((string) ($raw['created_at'] ?? ''));
        $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;
        if ($timestamp === false) {
            throw new \InvalidArgumentException('некорректная дата создания');
        }

        $status = strtolower(trim((string) ($raw['status'] ?? 'unknown')));
        if ($status === '') {
            $status = 'unknown';
        }

        $items = $raw['items'] ?? [];
        if (!is_array($items)) {
            throw new \InvalidArgumentException('items не является массивом');
        }

        $preparedItems = [];
        foreach (array_values($items) as $lineIndex => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('позиция #' . ($lineIndex + 1) . ' не является объектом');
            }

            $rawSku = (string) ($item['sku'] ?? '');
            $sku = NomenclatureRepository::normalizeSku($rawSku);
            if ($sku === '') {
                throw new \InvalidArgumentException('позиция #' . ($lineIndex + 1) . ': пустой артикул');
            }

            $quantity = $item['quantity'] ?? null;
            if (!is_numeric($quantity) || (int) $quantity <= 0) {
                throw new \InvalidArgumentException('позиция #' . ($lineIndex + 1) . ': некорректное количество');
            }

            $price = null;
            if (array_key_exists('price', $item) && $item['price'] !== null) {
                if (!is_numeric($item['price'])) {
                    throw new \InvalidArgumentException('позиция #' . ($lineIndex + 1) . ': некорректная цена');
                }
                $price = (float) $item['price'];
            }

            $preparedItems[] = [
                'sku' => $sku,
                'rawSku' => $rawSku,
                'title' => trim((string) ($item['title'] ?? '')),
                'qty' => (int) $quantity,
                'price' => $price,
                'line' => $lineIndex + 1,
            ];
        }

        $total = null;
        if (array_key_exists('total', $raw) && $raw['total'] !== null) {
            if (!is_numeric($raw['total'])) {
                throw new \InvalidArgumentException('некорректная сумма заказа');
            }
            $total = (float) $raw['total'];
        }

        return [
            'index' => $index,
            'id' => $id,
            'createdAt' => $createdAt,
            'ts' => $timestamp,
            'status' => $status,
            'currency' => strtoupper(trim((string) ($raw['currency'] ?? ''))),
            'total' => $total,
            'items' => $preparedItems,
        ];
    }

    /**
     * @param list<array{sku: string, rawSku: string, title: string, qty: int, price: ?float, line: int}> $items
     * @return list<array{sku: string, rawSku: string, title: string, qty: int, price: ?float, line: int, lines: list<int>}>
     */
    private function mergeLines(array $items): array
    {
        $merged = [];
        foreach ($items as $item) {
            $sku = $item['sku'];
            if (!isset($merged[$sku])) {
                $merged[$sku] = $item;
                $merged[$sku]['lines'] = [$item['line']];
                continue;
            }

            $merged[$sku]['qty'] += $item['qty'];
            $merged[$sku]['lines'][] = $item['line'];
            if ($merged[$sku]['price'] === null) {
                $merged[$sku]['price'] = $item['price'];
            }
        }

        return array_values($merged);
    }

    /**
     * @param list<NomenclatureItem> $cards
     */
    private function pickCard(array $cards, ?float $price): NomenclatureItem
    {
        if ($price !== null) {
            foreach ($cards as $card) {
                if (abs($card->price - $price) < 0.005 && $card->stock > 0) {
                    return $card;
                }
            }
            foreach ($cards as $card) {
                if (abs($card->price - $price) < 0.005) {
                    return $card;
                }
            }
        }

        foreach ($cards as $card) {
            if ($card->stock > 0) {
                return $card;
            }
        }

        usort($cards, static fn (NomenclatureItem $a, NomenclatureItem $b): int => $b->stock <=> $a->stock);

        return $cards[0];
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $issues
     * @return list<Row>
     */
    private function makeRows(string $key, array $order, string $verdict, array $issues): array
    {
        $rows = [];
        foreach ($issues as $issue) {
            $rows[] = new Row(
                orderKey: $key,
                orderId: $order['id'],
                createdAt: $order['createdAt'],
                orderStatus: $order['status'],
                verdict: $verdict,
                lineNo: $issue['line'],
                sku: $issue['sku'],
                title: $issue['title'],
                qty: $issue['qty'],
                orderPrice: $issue['price'],
                price1c: $issue['price1c'],
                stock: $issue['stock'],
                available: $issue['available'],
                code: $issue['code'],
                severity: $issue['severity'],
                problem: $issue['message'],
                action: $issue['action'],
                comment: $issue['comment'],
            );
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $order
     * @return array{key: string, id: string, createdAt: string, status: string, verdict: string}
     */
    private function orderEntry(string $key, array $order, string $verdict): array
    {
        return [
            'key' => $key,
            'id' => $order['id'],
            'createdAt' => $order['createdAt'],
            'status' => $order['status'],
            'verdict' => $verdict,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function issue(string $code, string $severity, string $message, string $action, array $context = []): array
    {
        return $context + [
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'action' => $action,
            'comment' => '',
            'line' => null,
            'sku' => null,
            'title' => null,
            'qty' => null,
            'price' => null,
            'price1c' => null,
            'stock' => null,
            'available' => null,
        ];
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
