<?php

declare(strict_types=1);

/**
 * Прототип мониторинга расхождений заказов с 1С.
 *
 * Переиспользует движок сверки из задания 01: читает заказы и выгрузку
 * номенклатуры, получает список расхождений, сравнивает с предыдущим
 * снимком и показывает только новые/изменившиеся/исправленные.
 *
 * Запуск:
 *   php monitor-discrepancies.php --orders=... --nomenclature=... --state=...
 */

use App\JsonOrderSource;
use App\Logger;
use App\NomenclatureRepository;
use App\OrderChecker;

date_default_timezone_set('Europe/Moscow');

$root = dirname(__DIR__, 2);
$project = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = $root . '/01-orders-1c-sync/src/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * @return never
 */
function fail(string $message): never
{
    fwrite(STDERR, 'Ошибка: ' . $message . PHP_EOL);
    exit(1);
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

function usage(): string
{
    return <<<TXT
    Мониторинг расхождений заказов с 1С (прототип).

    Использование:
      php monitor-discrepancies.php --state=FILE [параметры]

    Параметры:
      --orders=PATH        заказы (JSON-файл или URL)      (по умолчанию data задания 01)
      --nomenclature=PATH  выгрузка номенклатуры 1С        (по умолчанию data задания 01)
      --state=FILE         снимок прошлого запуска (JSON)
      --save=FILE          куда сохранить новый снимок
      --baseline           первый запуск: сохранить снимок без алертов
      --log=FILE           лог сверки
      --json               вывести изменения в JSON
      --help               эта справка

    Коды возврата: 0 — изменений нет, 2 — есть новые/изменившиеся расхождения,
    1 — ошибка входных данных.

    TXT
        . PHP_EOL . 'Пример: php monitor-discrepancies.php --state=fixtures/state-baseline.json --orders=fixtures/orders-next.json' . PHP_EOL;
}

$options = getopt('', ['orders:', 'nomenclature:', 'state:', 'save:', 'baseline', 'log:', 'json', 'help']);

if ($options === false) {
    fail('не удалось разобрать параметры запуска');
}

if (isset($options['help'])) {
    echo usage();
    exit(0);
}

$ordersPath = option($options, 'orders', $root . '/01-orders-1c-sync/data/orders.json');
$nomenclaturePath = option($options, 'nomenclature', $root . '/01-orders-1c-sync/data/nomenclature_1c.csv');
$statePath = option($options, 'state', $root . '/var/monitor-state.json');
$logPath = option($options, 'log', $root . '/var/monitor.log');
$isBaseline = isset($options['baseline']);

$savePath = $options['save'] ?? null;
if (!is_string($savePath) || $savePath === '') {
    $savePath = $isBaseline ? $statePath : null;
}

$previous = [];
$previousGeneratedAt = null;
if (is_file($statePath)) {
    $rawState = @file_get_contents($statePath);
    if ($rawState === false) {
        fail("не удалось прочитать снимок состояния: {$statePath}");
    }

    try {
        $state = json_decode($rawState, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fail('некорректный JSON со снимком состояния: ' . $e->getMessage());
    }

    if (is_array($state) && isset($state['findings']) && is_array($state['findings'])) {
        $previous = $state['findings'];
        $previousGeneratedAt = $state['generated_at'] ?? null;
    }
}

$logger = null;
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$logCandidates = array_unique([
    $logPath,
    $root . '/var/monitor.log',
    sys_get_temp_dir() . '/monitor.log',
    $nullDevice,
]);

foreach ($logCandidates as $candidate) {
    try {
        $logDir = dirname($candidate);
        if ($logDir !== '.' && !is_dir($logDir) && !@mkdir($logDir, 0777, true) && !is_dir($logDir)) {
            continue;
        }
        if (@file_put_contents($candidate, '', FILE_APPEND) === false) {
            continue;
        }

        $logger = new Logger($candidate);
        break;
    } catch (Throwable) {
        continue;
    }
}

if ($logger === null) {
    fail('не удалось открыть лог ни по одному пути');
}

try {
    $orders = (new JsonOrderSource($ordersPath))->fetch();
    $nomenclature = NomenclatureRepository::fromCsv($nomenclaturePath, $logger);
    $result = (new OrderChecker())->check($orders, $nomenclature, $logger);
} catch (Throwable $e) {
    fail($e->getMessage());
}

$ignoredCodes = ['OK', 'SKU_NORMALIZED', 'CANCELLED', 'DUPLICATE_ORDER'];
$current = [];

foreach ($result->rows as $row) {
    if (in_array($row->code, $ignoredCodes, true)) {
        continue;
    }

    $key = $row->orderId . '|' . ($row->sku ?? '') . '|' . $row->code;
    $current[$key] = [
        'order' => $row->orderId,
        'sku' => $row->sku,
        'code' => $row->code,
        'severity' => $row->severity,
        'message' => $row->problem,
        'action' => $row->action,
        'comment' => $row->comment,
        'first_seen' => $previous[$key]['first_seen'] ?? date(DATE_ATOM),
    ];
}

$new = [];
$changed = [];
foreach ($current as $key => $finding) {
    if (!isset($previous[$key])) {
        $new[$key] = $finding;
        continue;
    }

    if ($previous[$key]['message'] !== $finding['message']) {
        $changed[$key] = $finding;
    }
}

$fixed = [];
foreach ($previous as $key => $finding) {
    if (!isset($current[$key])) {
        $fixed[$key] = $finding;
    }
}

// При первом запуске расхождения не «новые» — это базовая линия.
if ($isBaseline) {
    $new = [];
    $changed = [];
    $fixed = [];
}

$stateData = [
    'generated_at' => date(DATE_ATOM),
    'source' => [
        'orders' => $ordersPath,
        'nomenclature' => $nomenclaturePath,
        'orders_count' => count($orders),
    ],
    'findings' => $current,
];

if (is_string($savePath)) {
    $dir = dirname($savePath);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fail("не удалось создать каталог для снимка: {$dir}");
    }

    $encoded = json_encode($stateData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($encoded === false || file_put_contents($savePath, $encoded . PHP_EOL) === false) {
        fail("не удалось сохранить снимок: {$savePath}");
    }
}

$ordersInFindings = [];
foreach ($current as $finding) {
    $ordersInFindings[$finding['order']] = true;
}

$ordersWithIssues = count($ordersInFindings);
$totalOrders = count($orders);
$findingsCount = count($current);
$newCount = count($new);
$changedCount = count($changed);
$fixedCount = count($fixed);

$lines = [];
$lines[] = 'МОНИТОРИНГ РАСХОЖДЕНИЙ ЗАКАЗОВ С 1С';
$lines[] = str_repeat('=', 64);
$lines[] = sprintf(
    'Заказов: %d | с расхождениями: %d | расхождений: %d',
    $totalOrders,
    $ordersWithIssues,
    $findingsCount,
);

if ($isBaseline) {
    $lines[] = sprintf('Базовая линия сохранена: %d расхождений по %d заказам. Алертов нет.', $findingsCount, $ordersWithIssues);
} else {
    $lines[] = sprintf('Новых: %d | изменилось: %d | исправлено: %d', $newCount, $changedCount, $fixedCount);
    if ($previousGeneratedAt !== null) {
        $previousAt = strtotime((string) $previousGeneratedAt);
        if ($previousAt !== false) {
            $lines[] = 'Прошлый запуск: ' . date('d.m.Y H:i', $previousAt);
        }
    }
}

$lines[] = '';

$renderFinding = static function (array $finding): array {
    $parts = [];
    $prefix = $finding['order'];
    if (($finding['sku'] ?? null) !== null) {
        $prefix .= ' / ' . $finding['sku'];
    }
    $parts[] = '  ' . $prefix . ': ' . $finding['message'];
    if (($finding['comment'] ?? '') !== '') {
        $parts[] = '      ' . $finding['comment'];
    }
    $parts[] = '      → ' . $finding['action'];

    return $parts;
};

if ($new !== []) {
    $lines[] = 'НОВЫЕ РАСХОЖДЕНИЯ (' . $newCount . ')';
    $lines[] = str_repeat('-', 64);
    foreach ($new as $finding) {
        array_push($lines, ...$renderFinding($finding));
        $lines[] = '';
    }
}

if ($changed !== []) {
    $lines[] = 'ИЗМЕНИЛИСЬ (' . $changedCount . ')';
    $lines[] = str_repeat('-', 64);
    foreach ($changed as $finding) {
        array_push($lines, ...$renderFinding($finding));
        $lines[] = '';
    }
}

if ($fixed !== []) {
    $lines[] = 'ИСПРАВЛЕНО (' . $fixedCount . ')';
    $lines[] = str_repeat('-', 64);
    foreach ($fixed as $finding) {
        $prefix = $finding['order'];
        if (($finding['sku'] ?? null) !== null) {
            $prefix .= ' / ' . $finding['sku'];
        }
        $lines[] = '  ' . $prefix . ' — ' . $finding['code'] . ' (расхождение больше не найдено)';
    }
    $lines[] = '';
}

$payload = [
    'status' => ($newCount + $changedCount) > 0 ? 'ALERT' : 'OK',
    'orders' => $totalOrders,
    'orders_with_issues' => $ordersWithIssues,
    'findings' => $findingsCount,
    'new' => $newCount,
    'changed' => $changedCount,
    'fixed' => $fixedCount,
    'generated_at' => $stateData['generated_at'],
    'alerts' => array_values($new + $changed),
    'fixed_items' => array_values($fixed),
];

if (isset($options['json'])) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
} else {
    echo implode(PHP_EOL, $lines), PHP_EOL;
}

if ($isBaseline) {
    exit(0);
}

exit(($newCount + $changedCount) > 0 ? 2 : 0);
