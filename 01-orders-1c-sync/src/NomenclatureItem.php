<?php

declare(strict_types=1);

namespace App;

/**
 * Строка выгрузки номенклатуры 1С.
 */
final class NomenclatureItem
{
    public function __construct(
        public readonly string $code,
        public readonly string $sku,
        public readonly string $name,
        public readonly string $unit,
        public readonly float $price,
        public readonly float $stock,
    ) {
    }
}
