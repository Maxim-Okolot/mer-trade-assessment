<#
    run.ps1 — проверка работ одной командой.

    Примеры:
      .\run.ps1                 # обе задачи
      .\run.ps1 -Task 01        # только сверка заказов с 1С
      .\run.ps1 -Task 06        # только мониторинг расхождений
      .\run.ps1 -NoBootstrap    # не скачивать PHP, если его нет

    Если PHP 8.1+ не найден, скрипт сам скачает портативную сборку в .tools\php.
    Скрипт не трогает исходные данные: отчёты и состояние пишутся в var\.
#>

[CmdletBinding()]
param(
    [ValidateSet('all', '01', '06')]
    [string]$Task = 'all',

    [switch]$NoBootstrap
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $root

try { chcp 65001 | Out-Null } catch { }
try { [Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false) } catch { }

function Write-Step([string]$text) {
    Write-Host ''
    Write-Host "== $text" -ForegroundColor Cyan
}

function Stop-WithError([string]$text) {
    Write-Host "Ошибка: $text" -ForegroundColor Red
    exit 1
}

function Resolve-Php {
    $candidates = @()

    $command = Get-Command php -ErrorAction SilentlyContinue
    if ($command -ne $null) {
        $candidates += $command.Source
    }

    $candidates += @(
        (Join-Path $root '.tools\php\php.exe'),
        'C:\php\php.exe',
        'C:\xampp\php\php.exe',
        'C:\laragon\bin\php\php.exe'
    )

    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path $candidate)) {
            return $candidate
        }
    }

    return $null
}

function Install-Php {
    Write-Step 'PHP не найден — скачиваю портативную сборку 8.4'

    $var = Join-Path $root 'var'
    New-Item -ItemType Directory -Force -Path $var | Out-Null
    $zip = Join-Path $var 'php.zip'

    $urls = @(
        'https://windows.php.net/downloads/releases/latest/php-8.4-nts-Win32-vs17-x64-latest.zip',
        'https://downloads.php.net/~windows/releases/latest/php-8.4-nts-Win32-vs17-x64-latest.zip'
    )

    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

    $downloaded = $false
    foreach ($url in $urls) {
        try {
            Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing
            $downloaded = $true
            break
        } catch {
            Write-Host "  не удалось скачать: $url" -ForegroundColor DarkYellow
        }
    }

    if (-not $downloaded -or -not (Test-Path $zip)) {
        Stop-WithError 'не удалось скачать PHP. Установите PHP 8.1+ вручную (https://windows.php.net/download/) и повторите.'
    }

    $destination = Join-Path $root '.tools\php'
    New-Item -ItemType Directory -Force -Path $destination | Out-Null
    Expand-Archive -Path $zip -DestinationPath $destination -Force
    Remove-Item $zip -Force

    $php = Join-Path $destination 'php.exe'
    if (-not (Test-Path $php)) {
        Stop-WithError 'архив скачан, но php.exe в нём не найден'
    }

    return $php
}

$php = Resolve-Php
if (-not $php) {
    if ($NoBootstrap) {
        Stop-WithError 'PHP 8.1+ не найден. Установите PHP вручную или запустите без -NoBootstrap.'
    }
    $php = Install-Php
}

$version = & $php -r "echo PHP_VERSION;" 2>&1
Write-Host "PHP: $version ($php)"

New-Item -ItemType Directory -Force -Path (Join-Path $root 'var') | Out-Null

function Invoke-Task01 {
    Write-Step 'Задание 01: сверка заказов маркетплейса с номенклатурой 1С'

    $csv = Join-Path $root 'var\01-report.csv'
    $txt = Join-Path $root 'var\01-report.txt'
    $log = Join-Path $root 'var\01-app.log'

    & $php (Join-Path $root '01-orders-1c-sync\bin\check-orders.php') "--csv=$csv" "--txt=$txt" "--log=$log"
    $code = $LASTEXITCODE

    Write-Host ''
    Write-Host "Код возврата: $code (1 = есть заблокированные заказы, для этих данных это норма)"
    Write-Host "Отчёт: var\01-report.csv, var\01-report.txt; лог: var\01-app.log"

    if ($code -ne 0 -and $code -ne 1) {
        Stop-WithError "задание 01 завершилось с кодом $code"
    }
}

function Invoke-Task06 {
    Write-Step 'Задание 06: мониторинг расхождений заказов с 1С'

    $state = Join-Path $root 'var\monitor-state.json'
    if (Test-Path $state) {
        Remove-Item $state -Force
    }

    Write-Host '--- первый запуск: базовая линия'
    & $php (Join-Path $root '06-open-improvement\prototype\monitor-discrepancies.php') --baseline
    $baselineCode = $LASTEXITCODE

    if ($baselineCode -ne 0) {
        Stop-WithError "не удалось построить базовую линию (код $baselineCode)"
    }

    Write-Host ''
    Write-Host '--- второй запуск: новый срез заказов'
    & $php (Join-Path $root '06-open-improvement\prototype\monitor-discrepancies.php') `
        '--orders=06-open-improvement\prototype\fixtures\orders-next.json'
    $diffCode = $LASTEXITCODE

    Write-Host ''
    Write-Host "Код возврата: $diffCode (2 = есть новые расхождения, так и задумано)"
    Write-Host 'Состояние: var\monitor-state.json; лог: var\monitor.log'

    if ($diffCode -ne 2 -and $diffCode -ne 0) {
        Stop-WithError "мониторинг завершился с кодом $diffCode"
    }
}

if ($Task -eq 'all' -or $Task -eq '01') {
    Invoke-Task01
}

if ($Task -eq 'all' -or $Task -eq '06') {
    Invoke-Task06
}

Write-Step 'Готово'
Write-Host 'Все отчёты и состояние — в папке var\.'
