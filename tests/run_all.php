<?php

declare(strict_types=1);

/**
 * VendGuard — Batería Completa de Pruebas Automatizadas (T-39)
 * 
 * Ejecutor unificado de todas las suites de prueba:
 * 1. Pruebas Unitarias Backend y Contratos Frontend (PHP CLI).
 * 2. Pruebas Unitarias Dinámicas Reactivas Frontend (Node.js ESM).
 * 3. Pruebas de Integración con MariaDB y API REST HTTP real (PHP CLI / cURL).
 * 
 * Requisitos:
 * - RNF-03 (Inviolabilidad de datos, cero borrado físico).
 * - Criterios de Finalización (Definition of Done).
 * - Hecho cuando: Todas las pruebas unitarias y de integración se ejecutan exitosamente con cero fallos (0 errors, 0 failures).
 * 
 * Dogma Vanilla: Cero dependencias externas (PHP 8.2+ puro).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;

// Configuración de visualización en terminal
$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
$colorGreen = "\033[32m";
$colorRed   = "\033[31m";
$colorBold  = "\033[1m";
$colorReset = "\033[0m";

echo "{$colorBold}======================================================================{$colorReset}\n";
echo "{$colorBold} VendGuard: Batería Completa de Pruebas Automatizadas (T-39){$colorReset}\n";
echo "{$colorBold}======================================================================{$colorReset}\n\n";

$startTime = microtime(true);

$stats = [
    'unit_php' => ['total' => 0, 'passed' => 0, 'failed' => 0],
    'unit_mjs' => ['total' => 0, 'passed' => 0, 'failed' => 0],
    'integration_php' => ['total' => 0, 'passed' => 0, 'failed' => 0],
];

$failedFiles = [];
$totalAssertions = 0;

/**
 * Ejecuta un archivo de prueba en un proceso aislado y captura su resultado.
 */
function runTestProcess(string $command, string $label, string &$outputSummary = ''): bool
{
    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];

    $process = proc_open($command, $descriptorspec, $pipes);
    if (!is_resource($process)) {
        $outputSummary = "No se pudo iniciar el proceso de prueba.";
        return false;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $combinedOutput = (string)$stdout . (string)$stderr;
    $outputSummary = $combinedOutput;

    return $exitCode === 0;
}

/**
 * Extrae conteo de aserciones de la salida estándar del test si está disponible.
 */
function extractAssertionsCount(string $output): int
{
    // Busca patrones tipo: Total Aserciones: X | Total Assertions: X
    if (preg_match('/(?:Total Aserciones|Total Assertions):\s*(\d+)/i', $output, $m)) {
        return (int)$m[1];
    }
    // O conteo de [PASS]
    $passes = substr_count($output, '[PASS]');
    if ($passes > 0) {
        return $passes;
    }
    return 1;
}

// =====================================================================
// FASE 1: Pruebas Unitarias Backend y Contratos Frontend (PHP)
// =====================================================================
echo "{$colorBold}--- Fase 1: Pruebas Unitarias PHP (Lógica de Dominio y Contratos) ---{$colorReset}\n";

$unitPhpFiles = glob(__DIR__ . '/unit/*.php');
sort($unitPhpFiles);

foreach ($unitPhpFiles as $filePath) {
    $fileName = basename($filePath);
    $stats['unit_php']['total']++;

    $cmd = 'php "' . $filePath . '"';
    $output = '';
    $success = runTestProcess($cmd, $fileName, $output);
    $assertions = extractAssertionsCount($output);
    $totalAssertions += $assertions;

    if ($success) {
        $stats['unit_php']['passed']++;
        echo "  [PASS] {$fileName} ({$assertions} aserciones)\n";
    } else {
        $stats['unit_php']['failed']++;
        $failedFiles[] = "unit/{$fileName}";
        echo "  {$colorRed}[FAIL] {$fileName}{$colorReset}\n";
    }
}

// =====================================================================
// FASE 2: Pruebas Unitarias Dinámicas Reactivas Frontend (Node.js ESM)
// =====================================================================
echo "\n{$colorBold}--- Fase 2: Pruebas Unitarias Reactivas Frontend (Node.js ESM) ---{$colorReset}\n";

$unitMjsFiles = glob(__DIR__ . '/unit/*.mjs');
sort($unitMjsFiles);

foreach ($unitMjsFiles as $filePath) {
    $fileName = basename($filePath);
    $stats['unit_mjs']['total']++;

    $cmd = 'node "' . $filePath . '"';
    $output = '';
    $success = runTestProcess($cmd, $fileName, $output);
    $assertions = extractAssertionsCount($output);
    $totalAssertions += $assertions;

    if ($success) {
        $stats['unit_mjs']['passed']++;
        echo "  [PASS] {$fileName} ({$assertions} aserciones)\n";
    } else {
        $stats['unit_mjs']['failed']++;
        $failedFiles[] = "unit/{$fileName}";
        echo "  {$colorRed}[FAIL] {$fileName}{$colorReset}\n";
    }
}

// =====================================================================
// FASE 3: Pruebas de Integración con MariaDB y API REST (PHP)
// =====================================================================
echo "\n{$colorBold}--- Fase 3: Pruebas de Integración (MariaDB, Repositorios y API HTTP) ---{$colorReset}\n";

// Asegurar servidor HTTP para pruebas con cURL
$serverProcess = null;
$serverPipes = [];
$serverStartedByRunner = false;
$socket = @fsockopen('127.0.0.1', 8000, $errNo, $errStr, 0.5);
if (is_resource($socket)) {
    fclose($socket);
} else {
    $publicDir = realpath(__DIR__ . '/../public');
    $nullDevice = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
    $serverCmd = 'php -S 127.0.0.1:8000 -t "' . $publicDir . '"';
    $serverProcess = proc_open($serverCmd, [
        0 => ["pipe", "r"],
        1 => ["file", $nullDevice, "w"],
        2 => ["file", $nullDevice, "w"]
    ], $serverPipes);
    if (is_resource($serverProcess)) {
        $serverStartedByRunner = true;
        usleep(500000); // 500ms para permitir que el socket quede en escucha
    }
}

$integrationFiles = glob(__DIR__ . '/integration/*.php');
sort($integrationFiles);

foreach ($integrationFiles as $filePath) {
    $fileName = basename($filePath);
    $stats['integration_php']['total']++;

    $cmd = 'php "' . $filePath . '"';
    $output = '';
    $success = runTestProcess($cmd, $fileName, $output);
    $assertions = extractAssertionsCount($output);
    $totalAssertions += $assertions;

    if ($success) {
        $stats['integration_php']['passed']++;
        echo "  [PASS] {$fileName} ({$assertions} aserciones)\n";
    } else {
        $stats['integration_php']['failed']++;
        $failedFiles[] = "integration/{$fileName}";
        echo "  {$colorRed}[FAIL] {$fileName}{$colorReset}\n";
    }
}

// Cierre del servidor HTTP temporal si fue iniciado por el ejecutor
if ($serverStartedByRunner && is_resource($serverProcess)) {
    if (isset($serverPipes[0]) && is_resource($serverPipes[0])) {
        fclose($serverPipes[0]);
    }
    proc_terminate($serverProcess);
    proc_close($serverProcess);
}

// =====================================================================
// FASE 4: Re-sembrado final para dejar la BD en estado limpio operativo
// =====================================================================
try {
    $pdo = ConnectionFactory::getConnection();
    $seedRunner = new SeedRunner($pdo);
    $seedRunner->seedAll();

    require_once __DIR__ . '/../database/DemoMetricsSeeder.php';
    $demoSeeder = new \VendGuard\Database\DemoMetricsSeeder($pdo);
    $demoSeeder->seed();

    $dbResetOk = true;
} catch (\Throwable $e) {
    $dbResetOk = false;
}

$elapsedTime = round(microtime(true) - $startTime, 2);

// =====================================================================
// RESUMEN GLOBAL DE RESULTADOS
// =====================================================================
$totalSuites = $stats['unit_php']['total'] + $stats['unit_mjs']['total'] + $stats['integration_php']['total'];
$totalPassed = $stats['unit_php']['passed'] + $stats['unit_mjs']['passed'] + $stats['integration_php']['passed'];
$totalFailed = $stats['unit_php']['failed'] + $stats['unit_mjs']['failed'] + $stats['integration_php']['failed'];

echo "\n{$colorBold}======================================================================{$colorReset}\n";
echo "{$colorBold} RESUMEN DE EJECUCIÓN GLOBAL (T-39){$colorReset}\n";
echo "{$colorBold}======================================================================{$colorReset}\n";
echo " Tiempo de ejecución total : {$elapsedTime} segundos\n";
echo " Suites de pruebas PHP Unit : {$stats['unit_php']['passed']} / {$stats['unit_php']['total']} pasadas\n";
echo " Suites de pruebas JS Unit  : {$stats['unit_mjs']['passed']} / {$stats['unit_mjs']['total']} pasadas\n";
echo " Suites de Integración PHP  : {$stats['integration_php']['passed']} / {$stats['integration_php']['total']} pasadas\n";
echo " ──────────────────────────────────────────────────────────────────\n";
echo " Total Suites Ejecutadas    : {$totalSuites}\n";
echo " Total Aserciones Evaluadas : {$totalAssertions}\n";
echo " Fallos Detectados          : {$totalFailed}\n";
echo " Base de datos restablecida : " . ($dbResetOk ? "SÍ (Semillas intactas)" : "ADVERTENCIA") . "\n";
echo "{$colorBold}======================================================================{$colorReset}\n";

if ($totalFailed === 0) {
    echo "{$colorBold}{$colorGreen} RESULTADO: 100% EN VERDE. (0 errors, 0 failures){$colorReset}\n";
    echo "{$colorBold}{$colorGreen} CONDICIÓN T-39 CUMPLIDA SATISFACTORIAMENTE.{$colorReset}\n";
    echo "{$colorBold}======================================================================{$colorReset}\n";
    exit(0);
} else {
    echo "{$colorBold}{$colorRed} RESULTADO: {$totalFailed} SUITE(S) FALLIDAS:{$colorReset}\n";
    foreach ($failedFiles as $ff) {
        echo "   - {$ff}\n";
    }
    echo "{$colorBold}======================================================================{$colorReset}\n";
    exit(1);
}
