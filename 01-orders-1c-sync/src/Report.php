<?php

declare(strict_types=1);

namespace App;

/**
 * Отчёт для менеджера: консоль (кратко, по заказам и действиям) и CSV для Excel.
 */
final class Report
{
    private const VERDICT_LABELS = [
        OrderChecker::OK => 'ГОТОВ',
        OrderChecker::CHECK => 'ПРОВЕРИТЬ',
        OrderChecker::BLOCKED => 'ЗАБЛОКИРОВАН',
        OrderChecker::SKIPPED => 'ПРОПУЩЕН',
    ];

    private const STATUS_LABELS = [
        'new' => 'новый',
        'confirmed' => 'подтверждён',
        'cancelled' => 'отменён',
        'unknown' => 'неизвестен',
        '?' => '?',
    ];

    private const SEVERITY_LABELS = [
        'info' => 'инфо',
        'warning' => 'важно',
        'error' => 'ошибка',
    ];

    private const SEVERITY_MARKERS = [
        'info' => '[i]',
        'warning' => '[!]',
        'error' => '[x]',
    ];

    /**
     * CSV с BOM и ";" — открывается в Excel без настройки импорта.
     * Числа с десятичной запятой, как привычно в 1С.
     */
    public static function writeCsv(CheckResult $result, string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Не удалось создать каталог для отчёта: {$dir}");
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось открыть файл отчёта: {$path}");
        }

        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Заказ',
                'Создан',
                'Статус заказа',
                'Вердикт',
                'Строка',
                'Артикул',
                'Товар',
                'Кол-во',
                'Цена в заказе',
                'Цена 1С',
                'Остаток 1С',
                'Свободно до заказа',
                'Проблема',
                'Важность',
                'Что делать',
                'Комментарий',
            ], ';', '"', '', "\r\n");

            foreach ($result->rows as $row) {
                fputcsv($handle, [
                    $row->orderId,
                    self::date($row->createdAt),
                    self::status($row->orderStatus),
                    self::VERDICT_LABELS[$row->verdict] ?? $row->verdict,
                    $row->lineNo ?? '',
                    $row->sku ?? '',
                    $row->title ?? '',
                    $row->qty ?? '',
                    self::number($row->orderPrice),
                    self::number($row->price1c),
                    self::number($row->stock),
                    self::number($row->available),
                    $row->problem,
                    self::SEVERITY_LABELS[$row->severity] ?? $row->severity,
                    $row->action,
                    $row->comment,
                ], ';', '"', '', "\r\n");
            }
        } finally {
            fclose($handle);
        }
    }

    public static function toConsole(CheckResult $result): string
    {
        $counts = $result->countByVerdict();
        $lines = [];

        $lines[] = 'ПРОВЕРКА ЗАКАЗОВ МАРКЕТПЛЕЙСА ПРОТИВ НОМЕНКЛАТУРЫ 1С';
        $lines[] = str_repeat('=', 78);
        $lines[] = sprintf(
            'Заказов в выгрузке: %d | позиций: %d | строк номенклатуры: %d',
            count($result->orders),
            $result->lineCount,
            $result->nomenclatureRows,
        );
        $lines[] = sprintf(
            'Готовы: %d | требуют проверки: %d | заблокированы: %d | пропущены: %d',
            $counts[OrderChecker::OK],
            $counts[OrderChecker::CHECK],
            $counts[OrderChecker::BLOCKED],
            $counts[OrderChecker::SKIPPED],
        );
        $lines[] = '';

        $sections = [
            OrderChecker::BLOCKED => 'ЗАБЛОКИРОВАНЫ',
            OrderChecker::CHECK => 'ТРЕБУЮТ ПРОВЕРКИ',
            OrderChecker::SKIPPED => 'ПРОПУЩЕНЫ',
        ];

        foreach ($sections as $verdict => $title) {
            $orders = array_values(array_filter(
                $result->orders,
                static fn (array $order): bool => $order['verdict'] === $verdict,
            ));
            if ($orders === []) {
                continue;
            }

            $lines[] = $title . ' (' . count($orders) . ')';
            $lines[] = str_repeat('-', 78);

            foreach ($orders as $order) {
                $lines[] = self::orderHeader($order);
                foreach ($result->rowsFor($order['key']) as $row) {
                    array_push($lines, ...self::issueLines($row));
                }
                $lines[] = '';
            }
        }

        $okOrders = array_values(array_filter(
            $result->orders,
            static fn (array $order): bool => $order['verdict'] === OrderChecker::OK,
        ));
        if ($okOrders !== []) {
            $lines[] = 'ГОТОВЫ К ПЕРЕДАЧЕ (' . count($okOrders) . ')';
            $lines[] = str_repeat('-', 78);
            $lines[] = implode(', ', array_column($okOrders, 'id'));
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array{key: string, id: string, createdAt: string, status: string, verdict: string} $order
     */
    private static function orderHeader(array $order): string
    {
        if ($order['id'] === '?') {
            return 'Некорректная запись выгрузки';
        }

        return sprintf(
            '%s  %s  %s',
            $order['id'],
            self::date($order['createdAt']),
            self::status($order['status']),
        );
    }

    /**
     * @return list<string>
     */
    private static function issueLines(Row $row): array
    {
        $marker = self::SEVERITY_MARKERS[$row->severity] ?? '   ';

        $prefix = '';
        if ($row->lineNo !== null) {
            $prefix .= 'стр. ' . $row->lineNo . ' ';
        }
        if ($row->sku !== null) {
            $prefix .= $row->sku;
            if ($row->title !== null && $row->title !== '') {
                $prefix .= ' «' . $row->title . '»';
            }
            $prefix .= ': ';
        }

        $line = '  ' . $marker . ' ' . $prefix . $row->problem;
        if ($row->comment !== '') {
            $line .= ' (' . $row->comment . ')';
        }

        return [$line, '      → ' . $row->action];
    }

    private static function date(string $value): string
    {
        if ($value === '') {
            return '—';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date('d.m.Y H:i', $timestamp);
    }

    private static function status(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    private static function number(?float $value): string
    {
        return $value === null ? '' : number_format($value, 2, ',', '');
    }
}
