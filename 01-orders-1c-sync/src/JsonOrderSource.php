<?php

declare(strict_types=1);

namespace App;

/**
 * Заказы из JSON-файла или HTTP(S)-эндпоинта маркетплейса.
 * Поддерживается как ответ вида {"data": {"orders": [...]}}, так и плоский список.
 */
final class JsonOrderSource implements OrderSource
{
    public function __construct(private readonly string $location)
    {
    }

    public function fetch(): array
    {
        $raw = $this->read();

        try {
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Некорректный JSON с заказами: ' . $e->getMessage());
        }

        if (!is_array($document)) {
            throw new \RuntimeException('Ожидался JSON-объект или массив с заказами');
        }

        if (isset($document['data']['orders']) && is_array($document['data']['orders'])) {
            $orders = $document['data']['orders'];
        } elseif (isset($document['orders']) && is_array($document['orders'])) {
            $orders = $document['orders'];
        } elseif (array_is_list($document)) {
            $orders = $document;
        } else {
            throw new \RuntimeException('В ответе нет списка заказов (ожидался data.orders)');
        }

        return array_values($orders);
    }

    private function read(): string
    {
        if (preg_match('~^https?://~i', $this->location) === 1) {
            $context = stream_context_create([
                'http' => ['timeout' => 20, 'ignore_errors' => false],
            ]);
            $raw = @file_get_contents($this->location, false, $context);
            if ($raw === false) {
                throw new \RuntimeException("Не удалось получить заказы по URL: {$this->location}");
            }

            return $raw;
        }

        if (!is_file($this->location)) {
            throw new \RuntimeException("Файл заказов не найден: {$this->location}");
        }

        $raw = @file_get_contents($this->location);
        if ($raw === false) {
            throw new \RuntimeException("Не удалось прочитать файл заказов: {$this->location}");
        }

        return $raw;
    }
}
