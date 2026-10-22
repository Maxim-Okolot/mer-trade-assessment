<?php

declare(strict_types=1);

namespace App;

/**
 * Источник заказов. Отдельный интерфейс нужен, чтобы заменить файл
 * настоящим HTTP-запросом к API маркетплейса без переделки проверки.
 */
interface OrderSource
{
    /**
     * @return list<mixed>
     */
    public function fetch(): array;
}
