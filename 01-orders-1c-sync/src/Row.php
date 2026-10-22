<?php

declare(strict_types=1);

namespace App;

/**
 * Одна строка отчёта: либо позиция заказа с проблемой, либо проблема уровня заказа,
 * либо строка "всё в порядке".
 */
final class Row
{
    public function __construct(
        public readonly string $orderKey,
        public readonly string $orderId,
        public readonly string $createdAt,
        public readonly string $orderStatus,
        public readonly string $verdict,
        public readonly ?int $lineNo,
        public readonly ?string $sku,
        public readonly ?string $title,
        public readonly ?int $qty,
        public readonly ?float $orderPrice,
        public readonly ?float $price1c,
        public readonly ?float $stock,
        public readonly ?float $available,
        public readonly string $code,
        public readonly string $severity,
        public readonly string $problem,
        public readonly string $action,
        public readonly string $comment = '',
    ) {
    }
}
