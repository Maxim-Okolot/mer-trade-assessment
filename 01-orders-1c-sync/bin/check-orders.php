#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\CheckResult;
use App\JsonOrderSource;
use App\Logger;
use App\NomenclatureRepository;
use App\OrderChecker;
use App\Report;

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set('Europe/Moscow');

$options = getopt('', ['orders:', 'nomenclature:', 'csv:', 'txt:', 'log:', 'help']);

if ($options === false) {
    fwrite(STDERR, 'Не удалось разобрать параметры запуска' . PHP_EOL);
    exit(2);
}

if (isset($options['help'])) {
    echo usage($root);
    exit(0);
}

$ordersPath = option($options, 'orders', $root . '/data/orders.json');
$nomenclaturePath = option($options, 'nomenclature', $root . '/data/nomenclature_1c.csv');
$csvPath = option($options, 'csv', $root . '/output/report.csv');
$txtPath = option($options, 'txt', $root . '/output/report.txt');
$logPath = option($options, 'log', $root . '/output/app.log');

$logger = null;

try {
    $logger = new Logger($logPath);
    $logger->info('Старт проверки заказов', [
        'orders' => $ordersPath,
        'nomenclature' => $nomenclaturePath,
    ]);

    $source = new JsonOrderSource($ordersPath);
    $rawOrders = $source->fetch();
    $logger->info('Заказы загружены', ['count' => count($rawOrders)]);

    if ($rawOrders === []) {
        throw new RuntimeException('Источник вернул пустой список заказов');
    }

    $nomenclature = NomenclatureRepository::fromCsv($nomenclaturePath, $logger);

    $checker = new OrderChecker();
    $result = $checker->check($rawOrders, $nomenclature, $logger);

    Report::writeCsv($result, $csvPath);

    $console = Report::toConsole($result);
    writeText($txtPath, $console);

    echo $console;
    echo 'Текстовый отчёт: ' . $txtPath . PHP_EOL;
    echo 'CSV-отчёт:       ' . $csvPath . PHP_EOL;
    echo 'Лог:             ' . $logPath . PHP_EOL;

    $counts = $result->countByVerdict();
    $logger->info('Проверка завершена', [
        'orders' => count($result->orders),
        'ok' => $counts[OrderChecker::OK],
        'check' => $counts[OrderChecker::CHECK],
        'blocked' => $counts[OrderChecker::BLOCKED],
        'skipped' => $counts[OrderChecker::SKIPPED],
        'csv' => $csvPath,
    ]);

    exit($counts[OrderChecker::BLOCKED] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . PHP_EOL);
    if ($logger !== null) {
        try {
            $logger->error('Фатальная ошибка', ['error' => $e->getMessage()]);
        } catch (Throwable) {
            // Лог недоступен — сообщение уже выведено в stderr.
        }
    }
    exit(2);
}

/**
 * @param array<string, mixed> $options
 */
function option(array $options, string $name, string $default): string
{
    $value = $options[$name] ?? null;
    if ($value === null || $value === false || $value === '') {
        return $default;
    }

    return (string) $value;
}

function writeText(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("Не удалось создать каталог для отчёта: {$dir}");
    }

    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Не удалось записать текстовый отчёт: {$path}");
    }
}

function usage(string $root): string
{
    return <<<TXT
    Проверка заказов маркетплейса против выгрузки номенклатуры 1С.

    Использование:
      php bin/check-orders.php [параметры]

    Параметры:
      --orders=PATH        JSON-файл или URL с заказами      (по умолчанию data/orders.json)
      --nomenclature=PATH  CSV-выгрузка номенклатуры 1С      (по умолчанию data/nomenclature_1c.csv)
      --csv=PATH           куда сохранить CSV-отчёт          (по умолчанию output/report.csv)
      --txt=PATH           куда сохранить текстовый отчёт    (по умолчанию output/report.txt)
      --log=PATH           файл лога                         (по умолчанию output/app.log)
      --help               эта справка

    Коды возврата:
      0 — обработка прошла, заблокированных заказов нет
      1 — обработка прошла, но есть заблокированные заказы
      2 — фатальная ошибка (нет файла, битый JSON и т.п.)

    TXT
        . PHP_EOL . 'Пример: php bin/check-orders.php --orders=data/orders.json' . PHP_EOL;
}
