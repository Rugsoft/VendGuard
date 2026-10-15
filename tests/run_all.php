<?php

declare(strict_types=1);

/**
 * VendGuard — Batería Completa de Pruebas Automatizadas (T-39)
 * 
 * Ejecutor unificado de todas las suites de prueba:
 * 1. Pruebas Unitarias Backend y Contratos Frontend (PHP CLI).
 * 2. Pruebas Unitarias Dinámicas Reactivas Frontend (Node.js ESM).
 * 3. Pruebas de Integración con el motor único (PostgreSQL) y API REST HTTP real (PHP CLI / cURL).
 * 4. Guion E2E de extremo a extremo de los 3 perfiles (tests/Manual, T-40).
 * 
 * Requisitos:
 * - RNF-03 (Inviolabilidad de datos, cero borrado físico).
 * - Criterios de Finalización (Definition of Done).
 * - Hecho cuando: Todas las pruebas unitarias y de integración se ejecutan exitosamente con cero fallos (0 errors, 0 failures).
 * 
 * Además, la ejecución termina con una guarda de coherencia entre las cifras que
 * declara la documentación versionada (insignia y resumen del README, y frases
 * canónicas) y las realmente ejecutadas: un desfase documental también pone la
 * batería en rojo (ver `tests/Support/DocMetricsGuard.php`).
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
    'manual_e2e' => ['total' => 0, 'passed' => 0, 'failed' => 0],
];

$failedFiles = [];
$totalAssertions = 0;

/**
 * Suites declaradas con marca de categoría propia.
 *
 * Algunas suites exigen un motor que la batería hermética no tiene: la categoría
 * `new_engine` necesita un PostgreSQL real además del MariaDB local (RNF-04, spec
 * §8.1). Se declaran con `const VENDGUARD_TEST_CATEGORY = '<categoria>';` y esta
 * batería **no las ejecuta**: las enumera en voz alta y las deriva a su canal, de
 * modo que ninguna se cuele en el verde hermético ni se omita en silencio.
 *
 * @var array<string, string> fichero => categoría declarada
 */
$categorySuites = [];

/**
 * Ejecuta un archivo de prueba en un proceso aislado y captura su resultado.
 *
 * Las dos salidas se recogen en ficheros temporales y **no** en tuberías: el runner leía
 * `stdout` hasta el fin de flujo y sólo después tocaba `stderr`, así que una suite que
 * escribiera por el canal de errores más de un búfer de tubería —una traza por cada
 * expediente que el escenario repite— se quedaba bloqueada escribiendo mientras el runner
 * la esperaba leyendo, y la batería entera se colgaba sin plazo ni aviso. Ya ocurrió: 203
 * trazas del mismo expediente (646 KB por `stderr`) dejaron la corrida parada en
 * `LocationRefundDeliveryApiTest` hasta que se mató el proceso a mano.
 */
function runTestProcess(string $command, string $label, string &$outputSummary = ''): bool
{
    $stdoutPath = (string)tempnam(sys_get_temp_dir(), 'vendguard_test_out_');
    $stderrPath = (string)tempnam(sys_get_temp_dir(), 'vendguard_test_err_');

    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["file", $stdoutPath, "w"],
        2 => ["file", $stderrPath, "w"]
    ];

    $process = proc_open($command, $descriptorspec, $pipes);
    if (!is_resource($process)) {
        @unlink($stdoutPath);
        @unlink($stderrPath);
        $outputSummary = "No se pudo iniciar el proceso de prueba.";
        return false;
    }

    fclose($pipes[0]);
    $exitCode = proc_close($process);

    $stdout = (string)file_get_contents($stdoutPath);
    $stderr = (string)file_get_contents($stderrPath);
    @unlink($stdoutPath);
    @unlink($stderrPath);

    $combinedOutput = $stdout . $stderr;
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
    // Guion E2E (T-40): " Total Aserciones E2E Evaluadas : X"
    if (preg_match('/Total Aserciones E2E Evaluadas\s*:\s*(\d+)/i', $output, $m)) {
        return (int)$m[1];
    }
    // O conteo de [PASS]
    $passes = substr_count($output, '[PASS]');
    if ($passes > 0) {
        return $passes;
    }
    return 1;
}

/**
 * FASE 0 — Guardia de integridad de la limpieza de datos.
 *
 * Prohíbe que una suite vuelva a borrar `incidents` "a pelo" (sin WHERE). Ese
 * borrado sólo funciona mientras ninguna tabla hija con ON DELETE RESTRICT
 * tenga filas, y hoy hay cuatro: incident_replaced_parts, refund_requests,
 * spare_part_requests y unclaimed_cash_findings. Una única fila huérfana
 * reventaba 22 suites a la vez con el error 1451.
 *
 * Las variantes con WHERE sí se permiten: son borrados dirigidos de la fila
 * propia del test, y deben pasar por TestDataCleaner::purgeIncident*() para
 * arrastrar también a sus hijas.
 *
 * La única excepción es TestDataCleanerIsolationTest, que reproduce el borrado
 * ingenuo a propósito para demostrar que falla (prueba de mordida).
 *
 * @return list<string> lista de infracciones "fichero:línea"
 */
function auditSuitesCleanupDiscipline(string $testRoot): array
{
    $violations = [];
    $scanFiles = array_merge(
        glob($testRoot . '/integration/*.php') ?: [],
        glob($testRoot . '/Manual/*.php') ?: []
    );

    // `DELETE FROM incidents` / `DELETE FROM `incidents`` sin cláusula WHERE.
    $nakedDelete = '/DELETE\s+FROM\s+`?incidents`?\s*(?!.*\bWHERE\b)/i';

    foreach ($scanFiles as $filePath) {
        $fileName = basename($filePath);
        if ($fileName === 'TestDataCleanerIsolationTest.php') {
            continue; // Reproduce el patrón a propósito para probar que muerde.
        }
        $lines = file($filePath);
        if ($lines === false) {
            continue;
        }
        foreach ($lines as $index => $line) {
            if (stripos($line, 'TestDataCleaner') !== false) {
                continue;
            }
            if (preg_match($nakedDelete, $line)) {
                $violations[] = 'tests/' . str_replace('\\', '/', substr($filePath, strlen($testRoot) + 1)) . ':' . ($index + 1);
            }
        }
    }

    return $violations;
}

// =====================================================================
// FASE 0: Guardia de limpieza de datos (aborta antes de ejecutar nada)
// =====================================================================
echo "{$colorBold}--- Fase 0: Guardia de limpieza de datos ---{$colorReset}\n";

$cleanupViolations = auditSuitesCleanupDiscipline(__DIR__);
if ($cleanupViolations !== []) {
    echo "  {$colorRed}[BLOQUEO] {$colorReset}" . count($cleanupViolations) . " suite(s) borran `incidents` sin usar TestDataCleaner:\n";
    foreach ($cleanupViolations as $violation) {
        echo "         - {$violation}\n";
    }
    echo "\n{$colorBold}{$colorRed} BATERÍA ABORTADA. Usa TestDataCleaner::purge() o purgeIncident*(){$colorReset}\n";
    echo "{$colorBold}{$colorRed} para que el orden de borrado respete las claves foráneas RESTRICT.{$colorReset}\n";
    echo "{$colorBold}======================================================================{$colorReset}\n";
    exit(1);
}
echo "  [OK] Todas las suites respetan el contrato de limpieza.\n";

// =====================================================================
// FASE 0b: Disponibilidad del banco de la batería (motor único)
// =====================================================================
// EARS-13.6: si el motor del arnés no responde, la batería lo declara en voz alta y
// sale con código distinto de cero. No omite suites en silencio ni degrada la corrida a
// verde, y no se apoya en que el motor «esté ahí»: pregunta tres veces, con espera, y si
// las tres fallan, esto termina aquí.
echo "\n{$colorBold}--- Fase 0b: Disponibilidad del banco de la batería ---{$colorReset}\n";

$engineReady = false;
$engineFailure = '';
for ($engineAttempt = 1; $engineAttempt <= 3; $engineAttempt++) {
    try {
        $enginePdo = ConnectionFactory::getConnection([], true);
        $enginePdo->query('SELECT 1');
        $engineName = (string)$enginePdo->query('SELECT current_database()')->fetchColumn();
        $engineReady = true;
        break;
    } catch (\Throwable $engineException) {
        $engineFailure = $engineException->getMessage();
        if ($engineAttempt < 3) {
            usleep(500000);
        }
    }
}

if (!$engineReady) {
    echo "  {$colorRed}[BLOQUEO] El banco de la batería no responde: {$engineFailure}{$colorReset}\n";
    echo "         Arranca y carga el contrato del banco:\n";
    echo "           bash deploy/local/postgres/provision-tls-engine.sh\n";
    echo "           source deploy/local/postgres/.env.battery\n";
    echo "\n{$colorBold}{$colorRed} BATERÍA ABORTADA: el arnés no se ejecuta sin su motor.{$colorReset}\n";
    echo "{$colorBold}======================================================================{$colorReset}\n";
    exit(1);
}

echo "  [OK] Banco de la batería disponible: {$engineName} (motor único, sin red).\n";

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
echo "\n{$colorBold}--- Fase 3: Pruebas de Integración (motor único, Repositorios y API HTTP) ---{$colorReset}\n";

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

/** Categorías propias declaradas por las suites que esta batería hermética no ejecuta. */
$categorySuites = [];

foreach ($integrationFiles as $filePath) {
    $fileName = basename($filePath);

    // Marca de categoría propia: ni se ejecuta aquí ni se calla. Su canal la ejecuta
    // con el motor que necesita y su informe va aparte (T-HYBRID-21/22).
    if (preg_match('/const\s+VENDGUARD_TEST_CATEGORY\s*=\s*\'([a-z0-9_]+)\';/i', (string)file_get_contents($filePath), $categoryMatch) === 1) {
        $categorySuites[$fileName] = $categoryMatch[1];
        continue;
    }

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
        echo "         " . str_replace("\n", "\n         ", trim($output)) . "\n";
    }
}

// =====================================================================
// FASE 4: Guion E2E de extremo a extremo de los 3 perfiles (tests/Manual, T-40)
// =====================================================================
// Se ejecuta con el servidor HTTP todavía en marcha. Contrato de la carpeta:
// cada guion de tests/Manual/*.php debe ser no interactivo, autosuficiente y
// responsable de su propio estado (purga y re-siembra), de modo que corre en
// último lugar para no interferir con las suites de integración. La guardia de
// la Fase 0 ya audita esta carpeta contra borrados ingenuos de `incidents`.
echo "\n{$colorBold}--- Fase 4: Guion E2E de los 3 Perfiles (tests/Manual) ---{$colorReset}\n";

$manualFiles = glob(__DIR__ . '/Manual/*.php');
sort($manualFiles);

foreach ($manualFiles as $filePath) {
    $fileName = basename($filePath);
    $stats['manual_e2e']['total']++;

    $cmd = 'php "' . $filePath . '"';
    $output = '';
    $success = runTestProcess($cmd, $fileName, $output);
    $assertions = extractAssertionsCount($output);
    $totalAssertions += $assertions;

    if ($success) {
        $stats['manual_e2e']['passed']++;
        echo "  [PASS] {$fileName} ({$assertions} aserciones)\n";
    } else {
        $stats['manual_e2e']['failed']++;
        $failedFiles[] = "Manual/{$fileName}";
        echo "  {$colorRed}[FAIL] {$fileName}{$colorReset}\n";
        echo "         " . str_replace("\n", "\n         ", trim($output)) . "\n";
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
// FASE 5: Reinicio operacional y re-sembrado final
// =====================================================================
// La purga previa garantiza que cada ejecución termine con la BD en un estado
// limpio conocido: pase lo que pase dentro de la corrida (incluido un fatal que
// salte un `finally`), la siguiente arranca sin filas huérfanas.
// Hallazgo de residuo del arnés, declarado antes del cierre para que exista
// aunque el propio cierre falle a mitad.
$harnessResidueFinding = null;

try {
    $pdo = ConnectionFactory::getConnection();

    // Red de cierre del residuo del parque (contrato de limpieza §8.3 y §8.5). Cada suite
    // creadora drena lo suyo en su gancho de apagado; si aquí queda algo, es que una suite
    // nueva (o un proceso muerto) no restituyó su estado, y el banco lo diría en silencio
    // acumulando filas corrida tras corrida —que fue exactamente el defecto F-4—. Se drena
    // igualmente, para que la base quede sana, pero la corrida se declara EN ROJO: este es el
    // único punto que puede ver una fuga de una suite sin gancho, porque el drenaje de cierre
    // borra la evidencia antes de que la guarda pueda volver a medirla.
    // El criterio es la identidad contra el catálogo canónico del sembrador, no una lista de
    // nombres: alcanza la sede que el prefijo no miraba, la máquina colgada de una sede semilla
    // y la cuenta que creó una suite.
    $residualMasters = TestDataCleaner::countParkResidue($pdo);
    $drainedMasters = TestDataCleaner::restorePark($pdo);
    $drainedMasterRows = array_sum(array_map('intval', $drainedMasters));
    if ($residualMasters['total'] > 0) {
        $harnessResidueFinding = sprintf(
            'el banco de pruebas dejó %d fila(s) fuera del catálogo canónico %s (%d fila(s) drenadas en el cierre, con sus subárboles). '
            . 'Alguna suite no restituyó su estado: revisa el contrato de limpieza §8.3.',
            $residualMasters['total'],
            json_encode(
                ['por_tabla' => $residualMasters['by_table'], 'muestras' => $residualMasters['samples']],
                JSON_UNESCAPED_UNICODE
            ),
            $drainedMasterRows
        );
        echo "  {$colorRed}[FAIL] {$harnessResidueFinding}{$colorReset}\n";
    } else {
        echo "  {$colorGreen}[OK] Ninguna maestra quedó fuera del catálogo canónico (contrato de limpieza §8).{$colorReset}\n";
    }

    // El freno de intentos de las cuentas del catálogo se restituye aquí también: es estado,
    // no residuo, y ninguna suite lo reinicia por sí sola (§8.5).
    TestDataCleaner::restituteCatalogUsers($pdo);

    TestDataCleaner::purge($pdo);

    // El catálogo del arnés lo restituye su propio sembrador, que escribe el dialecto del
    // motor único. El sembrador de métricas de demostración (`DemoMetricsSeeder`) queda
    // fuera a propósito: es del motor heredado y su histórico de demostración no forma
    // parte del contrato del arnés —el del despliegue es `DemoHistorySeeder`, que ya escribe
    // PostgreSQL y entra por el arranque, no por aquí—. Se retira con el resto del camino
    // heredado (T-13-16).
    $seedRunner = new SeedRunner($pdo);
    $seedRunner->seedAll();

    $dbResetOk = true;
} catch (\Throwable $e) {
    $dbResetOk = false;
    echo "  {$colorRed}[WARN] Reinicio final de base de datos: " . $e->getMessage() . "{$colorReset}\n";
}

$elapsedTime = round(microtime(true) - $startTime, 2);

// =====================================================================
// RESUMEN GLOBAL DE RESULTADOS
// =====================================================================
$totalSuites = $stats['unit_php']['total'] + $stats['unit_mjs']['total'] + $stats['integration_php']['total'] + $stats['manual_e2e']['total'];
$totalPassed = $stats['unit_php']['passed'] + $stats['unit_mjs']['passed'] + $stats['integration_php']['passed'] + $stats['manual_e2e']['passed'];
$totalFailed = $stats['unit_php']['failed'] + $stats['unit_mjs']['failed'] + $stats['integration_php']['failed'] + $stats['manual_e2e']['failed'];

// =====================================================================
// FASE 6: Guarda contra la deriva de las cifras documentadas
// =====================================================================
// Las cifras de la batería también viven escritas a mano en la documentación
// (insignia del README, resumen por fases, árbol de directorios y frases
// canónicas). La guarda convierte su desfase en un fallo de esta ejecución:
// tests/Support/DocMetricsGuard.php documenta qué se audita y qué no.
require_once __DIR__ . '/Support/DocMetricsGuard.php';
$docMetricsGuard = new DocMetricsGuard(dirname(__DIR__));
$docMetricsReport = $docMetricsGuard->verify($stats, $totalSuites, $totalAssertions);
$docGuardDeviations = $docMetricsReport['deviations'];

foreach ($docMetricsReport['warnings'] as $docWarning) {
    echo "  {$colorRed}[WARN] Guarda de cifras documentadas: {$docWarning}{$colorReset}\n";
}
if ($docGuardDeviations === []) {
    echo "  {$colorGreen}[OK] Cifras documentadas coherentes con la ejecución ({$totalSuites} suites / " . number_format($totalAssertions, 0, ',', '.') . " aserciones).{$colorReset}\n";
}

echo "\n{$colorBold}======================================================================{$colorReset}\n";
echo "{$colorBold} RESUMEN DE EJECUCIÓN GLOBAL (T-39){$colorReset}\n";
echo "{$colorBold}======================================================================{$colorReset}\n";
echo " Tiempo de ejecución total : {$elapsedTime} segundos\n";
echo " Suites de pruebas PHP Unit : {$stats['unit_php']['passed']} / {$stats['unit_php']['total']} pasadas\n";
echo " Suites de pruebas JS Unit  : {$stats['unit_mjs']['passed']} / {$stats['unit_mjs']['total']} pasadas\n";
echo " Suites de Integración PHP  : {$stats['integration_php']['passed']} / {$stats['integration_php']['total']} pasadas\n";
echo " Suites E2E Manuales (T-40): {$stats['manual_e2e']['passed']} / {$stats['manual_e2e']['total']} pasadas\n";
if ($categorySuites !== []) {
    foreach ($categorySuites as $suiteName => $categoryName) {
        echo " Categoría propia «{$categoryName}» : {$suiteName} (canal aparte, no ejecutada aquí)\n";
    }
}
echo " ──────────────────────────────────────────────────────────────────\n";
echo " Total Suites Ejecutadas    : {$totalSuites}\n";
echo " Total Aserciones Evaluadas : {$totalAssertions}\n";
echo " Fallos Detectados          : {$totalFailed}\n";
echo " Cifras documentadas        : " . ($docGuardDeviations === [] ? 'COHERENTES' : count($docGuardDeviations) . ' DESVIACIÓN(ES)') . "\n";
echo " Base de datos restablecida : " . ($dbResetOk ? "SÍ (Semillas intactas)" : "ADVERTENCIA") . "\n";
echo "{$colorBold}======================================================================{$colorReset}\n";

if ($totalFailed === 0 && $docGuardDeviations === [] && $harnessResidueFinding === null) {
    echo "{$colorBold}{$colorGreen} RESULTADO: 100% EN VERDE. (0 errors, 0 failures){$colorReset}\n";
    echo "{$colorBold}{$colorGreen} CONDICIÓN T-39 CUMPLIDA SATISFACTORIAMENTE.{$colorReset}\n";
    echo "{$colorBold}======================================================================{$colorReset}\n";
    exit(0);
}

if ($totalFailed > 0) {
    echo "{$colorBold}{$colorRed} RESULTADO: {$totalFailed} SUITE(S) FALLIDAS:{$colorReset}\n";
    foreach ($failedFiles as $ff) {
        echo "   - {$ff}\n";
    }
}
if ($docGuardDeviations !== []) {
    echo "{$colorBold}{$colorRed} RESULTADO: " . count($docGuardDeviations) . " DESVIACIÓN(ES) DE CIFRAS DOCUMENTADAS:{$colorReset}\n";
    foreach ($docGuardDeviations as $deviation) {
        echo "   - {$deviation}\n";
    }
}
if ($harnessResidueFinding !== null) {
    echo "{$colorBold}{$colorRed} RESULTADO: RESIDUO DE SEDES DEL ARNÉS — {$harnessResidueFinding}{$colorReset}\n";
}
echo "{$colorBold}======================================================================{$colorReset}\n";
exit(1);
