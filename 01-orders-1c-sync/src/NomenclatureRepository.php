<?php

declare(strict_types=1);

namespace App;

/**
 * Номенклатура из выгрузки 1С.
 *
 * Формат файла: UTF-8 (возможно с BOM), разделитель ";",
 * десятичная запятая, пробел (в т.ч. неразрывный) как разделитель тысяч.
 * Пример: "1 250,00".
 */
final class NomenclatureRepository
{
    /** @var array<string, list<NomenclatureItem>> */
    private array $bySku = [];

    private int $rowCount = 0;

    /**
     * Нормализация артикула для сопоставления: обрезаем пробелы и приводим к верхнему регистру.
     * В данных встречается " ts-1002", который должен совпасть с "TS-1002".
     */
    public static function normalizeSku(string $sku): string
    {
        $trimmed = preg_replace('/\s+/u', ' ', $sku) ?? $sku;

        return strtoupper(trim($trimmed));
    }

    public static function fromCsv(string $path, Logger $logger): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Файл номенклатуры не найден: {$path}");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось открыть файл номенклатуры: {$path}");
        }

        try {
            $repository = new self();

            $header = fgetcsv($handle, null, ';', '"', '');
            if ($header === false || $header === [null]) {
                throw new \RuntimeException("Пустая выгрузка номенклатуры: {$path}");
            }

            // Excel сохраняет UTF-8 с BOM — срезаем его у первой колонки.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $columns = [];
            foreach ($header as $position => $name) {
                $columns[trim((string) $name)] = $position;
            }

            foreach (['Код', 'Артикул', 'Наименование', 'Цена', 'Остаток'] as $required) {
                if (!array_key_exists($required, $columns)) {
                    throw new \RuntimeException("В выгрузке 1С нет колонки «{$required}»");
                }
            }

            $lineNumber = 1;
            while (($row = fgetcsv($handle, null, ';', '"', '')) !== false) {
                $lineNumber++;

                if ($row === [null]) {
                    continue;
                }

                $isBlank = true;
                foreach ($row as $value) {
                    if (trim((string) $value) !== '') {
                        $isBlank = false;
                        break;
                    }
                }
                if ($isBlank) {
                    continue;
                }

                try {
                    $sku = self::normalizeSku((string) ($row[$columns['Артикул']] ?? ''));
                    if ($sku === '') {
                        throw new \InvalidArgumentException('пустой артикул');
                    }

                    $item = new NomenclatureItem(
                        code: trim((string) ($row[$columns['Код']] ?? '')),
                        sku: $sku,
                        name: trim((string) ($row[$columns['Наименование']] ?? '')),
                        unit: isset($columns['ЕдИзм']) ? trim((string) ($row[$columns['ЕдИзм']] ?? '')) : '',
                        price: self::parseDecimal($row[$columns['Цена']] ?? '', 'Цена', $lineNumber),
                        stock: self::parseDecimal($row[$columns['Остаток']] ?? '', 'Остаток', $lineNumber),
                    );

                    $repository->bySku[$sku][] = $item;
                    $repository->rowCount++;

                    if (count($repository->bySku[$sku]) > 1) {
                        $logger->warning('Дубль артикула в выгрузке 1С', [
                            'sku' => $sku,
                            'code' => $item->code,
                            'line' => $lineNumber,
                            'cards' => count($repository->bySku[$sku]),
                        ]);
                    }
                } catch (\Throwable $e) {
                    // Одна битая строка не должна останавливать загрузку всей выгрузки.
                    $logger->error('Строка номенклатуры пропущена', [
                        'line' => $lineNumber,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            fclose($handle);
        }

        if ($repository->rowCount === 0) {
            throw new \RuntimeException("В выгрузке номенклатуры нет ни одной корректной строки: {$path}");
        }

        $logger->info('Номенклатура загружена', [
            'rows' => $repository->rowCount,
            'sku' => count($repository->bySku),
        ]);

        return $repository;
    }

    /** @return list<NomenclatureItem> */
    public function find(string $sku): array
    {
        return $this->bySku[$sku] ?? [];
    }

    public function count(): int
    {
        return $this->rowCount;
    }

    public function skuCount(): int
    {
        return count($this->bySku);
    }

    /**
     * 1С отдаёт числа в локализованном виде: "1 250,00", "3,20", "13,000".
     */
    private static function parseDecimal(mixed $value, string $field, int $lineNumber): float
    {
        $raw = trim((string) $value);
        $raw = str_replace(["\xC2\xA0", "\u{2009}", "\u{202F}", ' '], '', $raw);
        $raw = str_replace(',', '.', $raw);

        if ($raw === '' || !is_numeric($raw)) {
            throw new \InvalidArgumentException(
                "строка {$lineNumber}: поле «{$field}» не является числом: «{$value}»"
            );
        }

        return (float) $raw;
    }
}
