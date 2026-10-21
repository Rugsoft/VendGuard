<?php

declare(strict_types=1);

/**
 * run_new_engine.php · Canal propio de la categoría `new_engine` (hallazgo H-4)
 *
 * La batería hermética (`tests/run_all.php`) **no ejecuta** las suites que declaran
 * `const VENDGUARD_TEST_CATEGORY = 'new_engine';`: las enumera y las deriva a este
 * canal, porque exigen un PostgreSQL real y trabajan sobre esquemas aislados. Hasta
 * la verificación funcional del 2026-10-21 no existía un comando propio para
 * ejecutarlas: había que invocarlas una a una a mano, así que el canal se quedaba
 * sin cerrar en la práctica.
 *
 * Contrato:
 *   - Lee `PG_TEST_DSN` del entorno. **No tiene valor por defecto**: sin la variable
 *     sale con código 2 y lo dice, en lugar de omitir suites en silencio.
 *   - Antes de ejecutar nada, abre una conexión real y pregunta la versión del
 *     motor. Un DSN presente pero inalcanzable también sale con código 2.
 *   - Descubre las suites leyendo su marca de categoría, no una lista paralela que
 *     se degrade en silencio cuando alguien añada una suite nueva.
 *   - Cada suite corre en su propio proceso, con la salida y el error capturados en
 *     ficheros temporales (nunca por tubería: una traza larga por `stderr` puede
 *     bloquear al ejecutor esperando `stdout`).
 *   - Códigos de salida: 0 todo en verde · 1 alguna suite falló · 2 canal no
 *     ejecutable (sin DSN, motor inalcanzable o canal vacío).
 *
 * Uso:
 *   source deploy/local/postgres/.env.local
 *   php tests/run_new_engine.php              # todas las suites del canal
 *   php tests/run_new_engine.php Zone         # filtra por subcadena del nombre
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes y documentación en castellano.
 */

$projectRoot = dirname(__DIR__);
$integrationDir = __DIR__ . '/integration';

const EXIT_OK = 0;
const EXIT_FAILURES = 1;
const EXIT_NOT_EXECUTABLE = 2;

/**
 * Marca de categoría declarada por una suite, o null si no declara ninguna.
 */
function newEngineCategory(string $filePath): ?string
{
    $source = (string)file_get_contents($filePath);

    if (preg_match('/const\s+VENDGUARD_TEST_CATEGORY\s*=\s*\'([a-z0-9_]+)\';/i', $source, $match) !== 1) {
        return null;
    }

    return $match[1];
}

/**
 * Aserciones declaradas por una suite en su resumen final.
 */
function countAssertions(string $output): int
{
    if (preg_match('/(?:Total Aserciones|Total Assertions):\s*(\d+)/i', $output, $match) === 1) {
        return (int)$match[1];
    }

    $passes = substr_count($output, '[PASS]');
    if ($passes > 0) {
        return $passes;
    }

    return 1;
}

/**
 * Ejecuta una suite en un proceso hijo.
 *
 * El código 2 es una declaración explícita de la propia suite: «esta certificación
 * necesita otro contrato de entorno» —el caso de la medición contra el motor
 * remoto, que exige `DB_SSL_ROOT_CERT` y no se puede sustituir por el motor local—.
 * No es un fallo, pero tampoco se calla: el resumen lo lista aparte.
 *
 * @return array{0: bool, 1: string, 2: int, 3: int}
 */
function runSuite(string $filePath): array
{
    $stdoutPath = (string)tempnam(sys_get_temp_dir(), 'vendguard_new_engine_out_');
    $stderrPath = (string)tempnam(sys_get_temp_dir(), 'vendguard_new_engine_err_');

    $process = proc_open(
        [PHP_BINARY, $filePath],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        @unlink($stdoutPath);
        @unlink($stderrPath);

        return [false, 'No se pudo abrir el proceso de la suite.', 0, 1];
    }

    fclose($pipes[0]);
    $exitCode = proc_close($process);

    $stdout = (string)file_get_contents($stdoutPath);
    $stderr = (string)file_get_contents($stderrPath);
    @unlink($stdoutPath);
    @unlink($stderrPath);

    $combined = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));

    return [$exitCode === 0, $combined, countAssertions($stdout), $exitCode];
}

$dsn = trim((string)getenv('PG_TEST_DSN'));
if ($dsn === '') {
    fwrite(STDERR, "\n[CANAL NO EJECUTABLE] Falta PG_TEST_DSN en el entorno.\n");
    fwrite(STDERR, "  source deploy/local/postgres/.env.local && php tests/run_new_engine.php\n");
    fwrite(STDERR, "  (o .env.docker-tls para el motor TLS en contenedor)\n\n");
    exit(EXIT_NOT_EXECUTABLE);
}

$driver = null;
try {
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $version = (string)$pdo->query('SELECT version()')->fetchColumn();
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CANAL NO EJECUTABLE] El motor declarado por PG_TEST_DSN no responde: "
        . $exception->getMessage() . "\n\n");
    exit(EXIT_NOT_EXECUTABLE);
}

if ($driver !== 'pgsql') {
    fwrite(STDERR, "\n[CANAL NO EJECUTABLE] PG_TEST_DSN apunta a «{$driver}», no a pgsql.\n\n");
    exit(EXIT_NOT_EXECUTABLE);
}

// Los dos contratos conviven a propósito y no se pisan: las suites de esquema abren su
// fixture con `PG_TEST_DSN`, y las de flujo HTTP usan `ConnectionFactory`, que resuelve
// `DATABASE_URL` (el banco del arnés) porque miden el camino real de la aplicación. El
// canal solo exige el primero; el segundo lo resuelve `tests/bootstrap.php` desde su
// contrato y, si falta, la suite lo declara en lugar de medir a ciegas.

$filter = trim((string)($argv[1] ?? ''));
$suites = [];
foreach (glob($integrationDir . '/*.php') ?: [] as $filePath) {
    if (newEngineCategory($filePath) !== 'new_engine') {
        continue;
    }

    $name = basename($filePath);
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }

    $suites[$name] = $filePath;
}

ksort($suites);

if ($suites === []) {
    fwrite(STDERR, "\n[CANAL NO EJECUTABLE] Ninguna suite del canal «new_engine» casa con el filtro"
        . ($filter !== '' ? " «{$filter}»" : '') . ".\n\n");
    exit(EXIT_NOT_EXECUTABLE);
}

echo "======================================================================\n";
echo " VENDGUARD · Canal de certificación del motor nuevo (categoría new_engine)\n";
echo " Motor: {$version}\n";
echo " Suites: " . count($suites) . " · Fuera de la batería hermética (RNF-04)\n";
echo "======================================================================\n\n";

$failed = [];
$notExecutable = [];
$assertions = 0;

foreach ($suites as $name => $filePath) {
    echo "▶ {$name}\n";
    [$ok, $output, $count, $exitCode] = runSuite($filePath);

    if ($ok) {
        $assertions += $count;
        echo "  [PASS] {$name} ({$count} aserciones)\n";
        continue;
    }

    if ($exitCode === 2) {
        $notExecutable[$name] = $output;
        echo "  [NO EJECUTABLE] {$name} · la suite declara otro contrato de entorno\n";
        $indented = preg_replace('/^/m', '         ', (string)$output);
        echo ($indented !== null ? $indented : $output) . "\n";
        continue;
    }

    $assertions += $count;
    $failed[] = $name;
    echo "  [FAIL] {$name}\n";
    $indented = preg_replace('/^/m', '         ', (string)$output);
    echo ($indented !== null ? $indented : $output) . "\n";
}

echo "\n======================================================================\n";
echo " RESUMEN DEL CANAL new_engine\n";
echo " Suites evaluadas    : " . count($suites) . "\n";
echo " Suites en verde     : " . (count($suites) - count($failed) - count($notExecutable)) . "\n";
echo " No ejecutables      : " . count($notExecutable) . "\n";
echo " Aserciones          : {$assertions}\n";
echo " Fallos              : " . count($failed) . "\n";

foreach ($notExecutable as $name => $reason) {
    // La suite explica el motivo en una línea marcada; la cabecera del arnés no es
    // el motivo y no debe ocupar el resumen.
    $reasonLine = '';
    foreach (explode("\n", $reason) as $line) {
        if (str_contains($line, 'NO EJECUTABLE') || str_contains($line, 'NO DISPONIBLE')) {
            $reasonLine = trim($line);
            break;
        }
    }
    if ($reasonLine === '') {
        $reasonLine = trim((string)strtok($reason, "\n"));
    }
    echo " - {$name}: {$reasonLine}\n";
}

if ($failed !== []) {
    echo " RESULTADO: FALLO. Revisa: " . implode(', ', $failed) . "\n";
    echo "======================================================================\n";
    exit(EXIT_FAILURES);
}

if ($notExecutable !== []) {
    echo " RESULTADO: CANAL CERRADO · " . count($notExecutable)
        . " suite(s) declararon otro contrato y se listan arriba (no se omiten en silencio).\n";
    echo "======================================================================\n";
    exit(EXIT_OK);
}

echo " RESULTADO: 100% EN VERDE. CANAL new_engine CERRADO.\n";
echo "======================================================================\n";
exit(EXIT_OK);
