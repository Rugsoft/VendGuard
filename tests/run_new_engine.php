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
 *   - Una suite puede declarar `VENDGUARD_TEST_ENV_CONTRACT` para medir contra un
 *     destinatario distinto del banco del arnés. El canal lee ese fichero (ignorado
 *     por git), inyecta solo `DATABASE_URL` y `DB_SSL_ROOT_CERT` en su proceso hijo y
 *     deja intacto el contrato del resto. Sin el fichero, la suite decide: la medición
 *     remota sigue declarándose no ejecutable, nunca se sustituye en silencio.
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

// La misma clase que usa la guarda documental: el runner y el README no pueden
// contar cosas distintas si comparten el descubrimiento.
require_once __DIR__ . '/Support/NewEngineChannel.php';

const EXIT_OK = 0;
const EXIT_FAILURES = 1;
const EXIT_NOT_EXECUTABLE = 2;

/**
 * Aserciones declaradas por una suite en su resumen final.
 *
 * Acepta los dos formatos que el canal admite —el canónico `Total Aserciones: N` y la
 * variante `RESULTADO: N aserciones`— y, si la suite no publica resumen, cuenta sus
 * marcas de aserción. Antes devolvía 1 cuando no reconocía el texto, de modo que una
 * suite con 17 aserciones publicaba 1: una cifra inventada es peor que una cifra baja
 * que se puede ver.
 */
function countAssertions(string $output): int
{
    foreach ([
        '/(?:Total Aserciones|Total Assertions):\s*(\d+)/i',
        '/RESULTADO:\s*(\d+)\s+aserciones/i',
    ] as $pattern) {
        if (preg_match($pattern, $output, $match) === 1) {
            return (int)$match[1];
        }
    }

    return substr_count($output, '[PASS]') + substr_count($output, '[OK]');
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
function runSuite(string $filePath, array $environmentOverlay = []): array
{
    $stdoutPath = (string)tempnam(sys_get_temp_dir(), 'vendguard_new_engine_out_');
    $stderrPath = (string)tempnam(sys_get_temp_dir(), 'vendguard_new_engine_err_');

    // `proc_open` recibe el entorno completo cuando se le pasa el quinto argumento:
    // se parte del heredado y se superponen las claves del contrato propio de la
    // suite, sin reemplazar el resto (PATH, PG_TEST_DSN, HOME...).
    $environment = null;
    if ($environmentOverlay !== []) {
        $inherited = getenv();
        $environment = is_array($inherited) ? $inherited : [];
        foreach ($environmentOverlay as $key => $value) {
            $environment[$key] = $value;
        }
    }

    $process = proc_open(
        [PHP_BINARY, $filePath],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ],
        $pipes,
        null,
        $environment
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
$suites = NewEngineChannel::discover($integrationDir);
if ($filter !== '') {
    foreach (array_keys($suites) as $name) {
        if (stripos($name, $filter) === false) {
            unset($suites[$name]);
        }
    }
}

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

    // Contrato propio: solo se inyectan las dos claves que la suite declara medir.
    // El fichero no se versiona y sus valores no se publican nunca en la salida.
    $overlay = [];
    $declaredContract = NewEngineChannel::environmentContractOf($filePath);
    if ($declaredContract !== null) {
        $contractFile = $projectRoot . '/' . ltrim($declaredContract, '/\\');
        if (is_file($contractFile)) {
            $overlay = NewEngineChannel::readEnvironmentContract(
                $contractFile,
                ['DATABASE_URL', 'DB_SSL_ROOT_CERT']
            );
            echo '  · contrato propio: ' . $declaredContract
                . ' (' . count($overlay) . " clave(s) inyectada(s))\n";
        } else {
            echo '  · contrato propio declarado pero ausente: ' . $declaredContract
                . " · la suite decidirá si puede medir\n";
        }
    }

    [$ok, $output, $count, $exitCode] = runSuite($filePath, $overlay);

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
