<?php

declare(strict_types=1);

namespace App;

/**
 * Простой файловый лог: одна строка на событие, контекст — JSON.
 * Ошибки обработки данных не должны ронять процесс, поэтому лог
 * используется как основной канал диагностики.
 */
final class Logger
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Не удалось создать каталог для лога: {$dir}");
        }

        // Каждый запуск — отдельный лог, иначе повторная проверка смешает прогоны.
        if (file_put_contents($this->file, '') === false) {
            throw new \RuntimeException("Не удалось открыть лог: {$file}");
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $line = sprintf('[%s] %-5s %s', date('Y-m-d H:i:s'), $level, $message);
        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $line .= ' ' . $encoded;
            }
        }

        $written = file_put_contents($this->file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException("Не удалось записать лог: {$this->file}");
        }
    }
}
