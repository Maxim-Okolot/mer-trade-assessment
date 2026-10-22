<?php

declare(strict_types=1);

namespace App;

/**
 * Результат проверки: строки отчёта и список заказов с вердиктами.
 */
final class CheckResult
{
    /**
     * @param list<Row>                                                                        $rows
     * @param list<array{key: string, id: string, createdAt: string, status: string, verdict: string}> $orders
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $orders,
        public readonly int $lineCount,
        public readonly int $nomenclatureRows,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function countByVerdict(): array
    {
        $counts = [
            OrderChecker::OK => 0,
            OrderChecker::CHECK => 0,
            OrderChecker::BLOCKED => 0,
            OrderChecker::SKIPPED => 0,
        ];

        foreach ($this->orders as $order) {
            $counts[$order['verdict']] = ($counts[$order['verdict']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return list<Row>
     */
    public function rowsFor(string $orderKey): array
    {
        $result = [];
        foreach ($this->rows as $row) {
            if ($row->orderKey === $orderKey) {
                $result[] = $row;
            }
        }

        return $result;
    }
}
