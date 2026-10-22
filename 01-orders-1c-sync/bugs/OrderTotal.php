<?php
/**
 * Учебный код для тестового задания. Не относится к реальным системам.
 *
 * Расчёт итогов заказа: сумма строк, скидка в процентах, выделение НДС.
 * Цены в заказе указаны С УЧЁТОМ НДС 20 %.
 *
 * Запуск самопроверки: php OrderTotal.php
 * В файле 3 ошибки. Найдите, исправьте и кратко объясните каждую.
 */

declare(strict_types=1);

final class OrderTotal
{
    public const VAT_RATE = 20; // ставка НДС, %

    /**
     * @param array<int, array{sku: string, qty: int, price: float}> $items
     */
    public static function subtotal(array $items): float
    {
        $sum = 0.0;
        foreach ($items as $item) {
            // БАГ 1 (исправлен): цена приводилась к int, поэтому 3,20 превращалось в 3,
            // а 18,50 — в 18. Сумма заказа занижалась, и расчёт не сходился с ожидаемым.
            $sum += (float) $item['price'] * $item['qty'];
        }

        return round($sum, 2);
    }

    /**
     * Скидка в процентах, допустимо от 0 до 100 включительно.
     * Недопустимая скидка должна приводить к исключению.
     */
    public static function applyDiscount(float $subtotal, int $percent): float
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException("Недопустимая скидка: {$percent}%");
        }

        return round($subtotal * (100 - $percent) / 100, 2);
    }

    /**
     * Сумма НДС, которая уже содержится в цене (цена включает НДС).
     */
    public static function vatIncluded(float $total): float
    {
        // БАГ 2 (исправлен): НДС брался как 20 % от суммы, хотя цена уже включает НДС.
        // Правильно выделять его из суммы по ставке 20/120, иначе НДС завышен в 1,2 раза.
        return round($total * self::VAT_RATE / (100 + self::VAT_RATE), 2);
    }

    /**
     * @param array<int, array{sku: string, qty: int, price: float}> $items
     * @return array{subtotal: float, total: float, vat: float}
     */
    public static function calculate(array $items, int $discountPercent = 0): array
    {
        $subtotal = self::subtotal($items);
        $total    = self::applyDiscount($subtotal, $discountPercent);

        return [
            'subtotal' => $subtotal,
            'total'    => $total,
            // БАГ 3 (исправлен): НДС считался от суммы до скидки, поэтому после скидки
            // он оставался прежним. Теперь база — итог к оплате, и НДС уменьшается вместе с ним.
            'vat'      => self::vatIncluded($total),
        ];
    }
}

// ---------------------------------------------------------------------------
// Самопроверка. Ожидаемые значения посчитаны вручную и верны.
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $items = [
        ['sku' => 'TS-1001', 'qty' => 2, 'price' => 3.20],
        ['sku' => 'TS-1006', 'qty' => 1, 'price' => 18.50],
        ['sku' => 'TS-1034', 'qty' => 1, 'price' => 1890.00],
    ];

    $cases = [
        ['discount' => 0,  'expect' => ['subtotal' => 1914.90, 'total' => 1914.90, 'vat' => 319.15]],
        ['discount' => 10, 'expect' => ['subtotal' => 1914.90, 'total' => 1723.41, 'vat' => 287.24]],
    ];

    $failed = 0;
    foreach ($cases as $case) {
        $got = OrderTotal::calculate($items, $case['discount']);
        foreach ($case['expect'] as $key => $value) {
            $ok = abs($got[$key] - $value) < 0.005;
            $failed += $ok ? 0 : 1;
            printf("[%s] скидка %d%%, %s: ожидалось %.2f, получено %.2f\n",
                $ok ? 'OK  ' : 'FAIL', $case['discount'], $key, $value, $got[$key]);
        }
    }

    try {
        OrderTotal::applyDiscount(100.0, 150);
        echo "[FAIL] скидка 150% должна вызывать исключение\n";
        $failed++;
    } catch (InvalidArgumentException) {
        echo "[OK  ] скидка 150% отклонена\n";
    }

    echo $failed === 0 ? "Все проверки пройдены\n" : "Ошибок: {$failed}\n";
    exit($failed === 0 ? 0 : 1);
}
