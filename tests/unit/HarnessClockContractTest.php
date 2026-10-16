<?php

declare(strict_types=1);

/**
 * HarnessClockContractTest
 *
 * Guarda del **reloj del arnés** y de los instantes que cruzan al motor (contrato del arnés §9;
 * EARS-13.5, EARS-13.6 y EARS-13.10).
 *
 * Qué certifica:
 *
 *   1. **La declaración.** El arnés declara su reloj (`date_default_timezone_set`) en la zona
 *      operativa del parque en lugar de heredar el `date.timezone` del `php.ini` de la máquina.
 *      Antes de la declaración, el veredicto de la batería dependía del huso del desarrollador: la
 *      misma suite fallaba sus 4 comprobaciones de reloj bajo `Europe/Berlin` y las pasaba bajo
 *      `UTC`. La batería es hermética
 *      (EARS-13.5) y no puede improvisar la zona con la que mide.
 *   2. **La sesión viva.** La zona que la guarda declara para el motor (`EngineClock`) es la que la
 *      conexión informa (`SHOW TimeZone`), y una marca `Y-m-d H:i:s` de `EngineClock` vuelve del
 *      motor en su instante real (deriva < 2 s).
 *   3. **La magnitud del defecto.** Un render en hora local escrito en una columna con zona queda
 *      exactamente el huso por delante del instante que la suite quería sembrar. Es la medida que
 *      hacía que «49 horas» fueran 47 para el motor de cierre y que los minutos de espera de un
 *      tablero salieran 120 minutos cortos.
 *   4. **Las lecturas no dependen de la máquina.** Una marca `timestamptz` viaja con su desfase, de
 *      modo que `strtotime()` la interpreta en su instante real con cualquier reloj local.
 *   5. **El censo.** Ningún fichero de pruebas con conexión viva al motor puede quedar con un
 *      render de instante en hora local fuera del ratchet declarado, y **ninguna entrada del
 *      ratchet puede quedar obsoleta**: si un lote repara un sitio, la guarda exige retirar su
 *      entrada en el mismo commit en lugar de dejar el saldo desfasado. Cada entrada declara,
 *      además de su motivo y su dueño, su **naturaleza**: la clase no es homogénea —una siembra que
 *      no cruzaba anclada es una deuda del lote que la porta, y la **pared de sede publicada** que
 *      una suite usa como expectativa es la forma que el Contrato del Reloj Operativo fija para la
 *      respuesta—, y declarar las dos como si fueran lo mismo convertiría el ratchet en un cajón.
 *
 * Fuera de su alcance, declarado: los ficheros que no abren conexión al motor (los que resuelven su
 * persistencia con repositorios en memoria) no cruzan ningún instante al banco; su determinismo lo
 * da la declaración del reloj, y por eso no entran en el censo.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;

echo "======================================================================\n";
echo " VendGuard: Guarda del arnés - reloj declarado e instantes que cruzan\n";
echo "======================================================================\n\n";

$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

$root = dirname(__DIR__, 2);

/**
 * Naturaleza de la **pared de sede publicada**: el render no siembra ni escribe nada en el motor,
 * es la hora de la jornada que el Contrato del Reloj Operativo fija como forma de la respuesta (el
 * vencimiento contractual) y la suite lo usa como expectativa. No hay tarea que lo cierre porque no
 * es deuda: es la forma publicada.
 */
const NATURALEZA_PUBLICADA = 'pared publicada';

/**
 * Naturaleza de **deuda**: un sitio que cruza —o puede cruzar— un instante al motor sin anclarlo y
 * cuyo lote lo porta. Nombra la tarea abierta que lo cierra.
 */
const NATURALEZA_DEUDA = 'deuda de un lote';

/**
 * Naturaleza **sin cruce al motor**: el fichero abre conexión, pero este render alimenta una
 * expectativa que nunca llega a una columna.
 */
const NATURALEZA_SIN_MOTOR = 'sin cruce al motor';

/**
 * Ratchet de la clase: sitios con render en hora local que hoy **no** cruzan al motor o cuya
 * reconciliación pertenece a otro lote. Cada entrada declara su naturaleza, su motivo y su dueño; la
 * guarda exige que el censo real coincida exactamente con este saldo, de modo que reparar un sitio
 * sin retirar su entrada la pone en rojo (nada de saldos desfasados) y añadir un sitio nuevo también.
 *
 * El saldo del lote T-13-11: las cinco suites y la sonda que sembraban el vencimiento de sede como
 * pared local siembran y miden ya en la lengua del motor —anclado al escribir, instante al comparar—,
 * y lo único que queda son las **paredes publicadas** que las suites usan como expectativa de la
 * respuesta. La decisión está declarada en `specs/technical/operational_clock_contract.md`.
 *
 * @var array<string, array{hits: int, naturaleza: string, motivo: string, dueno: string}>
 */
$ratchet = [
    'tests/integration/PendingInfoApiTest.php' => [
        'hits' => 1,
        'naturaleza' => NATURALEZA_PUBLICADA,
        'motivo' => 'El vencimiento comercial de la suite (martes 12:15) se siembra **anclado** a la '
            . 'zona operativa y este render es su **pared de sede**: la hora de la jornada que la API '
            . 'publica y la suite compara como expectativa, no una marca que cruce al motor.',
        'dueno' => '— (la forma la fija el Contrato del Reloj Operativo, §4)',
    ],
    'tests/integration/PendingInfoSiteCommentNoReactivationTest.php' => [
        'hits' => 1,
        'naturaleza' => NATURALEZA_PUBLICADA,
        'motivo' => 'Mismo vencimiento publicado que `PendingInfoApiTest`: la expectativa es la pared '
            . 'de sede de la marca que se sembró anclada.',
        'dueno' => '— (Contrato del Reloj Operativo, §4)',
    ],
    'tests/integration/PendingInfoTechnicianEndpointsTest.php' => [
        'hits' => 1,
        'naturaleza' => NATURALEZA_PUBLICADA,
        'motivo' => 'Mismo vencimiento publicado que `PendingInfoApiTest`.',
        'dueno' => '— (Contrato del Reloj Operativo, §4)',
    ],
    'tests/diagnostics/PendingInfoSmokeWorkflowDiagnostic.php' => [
        'hits' => 1,
        'naturaleza' => NATURALEZA_PUBLICADA,
        'motivo' => 'La sonda siembra el vencimiento del viernes **anclado** y compara la respuesta '
            . 'con su **pared de sede**: es la expectativa publicada, no una marca que cruce al motor.',
        'dueno' => '— (Contrato del Reloj Operativo, §4)',
    ],
];

/**
 * Censo de la clase sobre los ficheros de prueba con conexión viva al motor.
 *
 * Detector declarado y deliberadamente conservador: marca todo render de instante `Y-m-d H:i:s`
 * que no provenga de `EngineClock` ni de una raíz construida en UTC, dentro de un fichero que abre
 * conexión al motor. No distingue el valor que acaba en un `execute()` del que sólo alimenta una
 * expectativa: por eso el ratchet declara caso a caso en lugar de callar el censo.
 *
 * @param string $root Raíz del repositorio.
 * @return array<string, array<int, string>> Fichero relativo => líneas con su marca.
 */
$census = static function (string $root): array {
    $censusHits = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tests'));

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (str_starts_with($relative, 'tests/Support/') || $relative === 'tests/run_all.php') {
            continue;
        }
        // La propia guarda queda fuera del censo: contiene el espécimen con el defecto, porque es
        // justo lo que mide (la deriva de un instante local y la marca curada del reloj del motor).
        if ($relative === 'tests/unit/HarnessClockContractTest.php') {
            continue;
        }

        $text = (string)file_get_contents($file->getPathname());

        // Dominio: ficheros que abren conexión viva al motor. Los que resuelven su persistencia
        // con repositorios en memoria no cruzan ningún instante al banco.
        if (!str_contains($text, 'ConnectionFactory::getConnection()') && !str_contains($text, 'new PDO(')) {
            continue;
        }

        // Raíces limpias: variables que ya viven en la zona del motor, con propagación por cadena
        // para que `$created75m = $now->sub(...)` no cuente como render local cuando `$now` lo
        // declaró `EngineClock`.
        $cleanRoots = [];
        if (preg_match_all('/\$(\w+)\s*=\s*EngineClock::(?:now|zone)\(/', $text, $matches) > 0) {
            $cleanRoots = $matches[1];
        }
        if (preg_match_all('/\$(\w+)\s*=\s*new DateTimeImmutable\([^;]*?DateTimeZone\(\'UTC\'\)/', $text, $matches) > 0) {
            $cleanRoots = array_merge($cleanRoots, $matches[1]);
        }
        for ($pass = 0; $pass < 4; $pass++) {
            if (preg_match_all('/\$(\w+)\s*=\s*\$(\w+)/', $text, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $pair) {
                    if (in_array($pair[2], $cleanRoots, true) && !in_array($pair[1], $cleanRoots, true)) {
                        $cleanRoots[] = $pair[1];
                    }
                }
            }
        }

        $lines = preg_split('/\r\n|\n/', $text) ?: [];
        foreach ($lines as $index => $line) {
            $render = preg_match("/->format\('Y-m-d H:i:s'\)/", $line) === 1
                || preg_match("/\bdate\('Y-m-d H:i:s'/", $line) === 1;
            if (!$render) {
                continue;
            }
            if (str_contains($line, 'EngineClock')) {
                continue;
            }
            // Cualquier raíz limpia presente en la línea la declara curada: cubre tanto la cadena
            // directa (`$now->sub(...)->format(...)`) como el instante limpio que viaja como
            // argumento de un ayudante de la suite.
            $lineRoots = preg_match_all('/\$(\w+)/', $line, $rootMatches) > 0 ? $rootMatches[1] : [];
            if (array_intersect($lineRoots, $cleanRoots) !== []) {
                continue;
            }

            $censusHits[$relative][] = sprintf('L%d: %s', $index + 1, trim($line));
        }
    }

    ksort($censusHits);

    return $censusHits;
};

// =========================================================================
// 1. La declaración y la sesión viva
// =========================================================================
echo "\n--- 1. El reloj del arnés se declara y coincide con la sesión del motor ---\n";

$declaredZone = date_default_timezone_get();
$assert(
    '1.1 El reloj del arnés está declarado en la zona operativa del parque, no heredado de la máquina',
    $declaredZone === EngineClock::OPERATIONAL_TIMEZONE,
    "zona declarada: {$declaredZone} (esperada: " . EngineClock::OPERATIONAL_TIMEZONE . ')'
);

$pdo = ConnectionFactory::getConnection();
$sessionZone = (string)$pdo->query('SHOW TimeZone')->fetchColumn();
$assert(
    '1.2 La zona de la sesión del motor es la que declara el arnés para sus columnas',
    $sessionZone === EngineClock::SESSION_TIMEZONE,
    "sesión: {$sessionZone} (declarada: " . EngineClock::SESSION_TIMEZONE . ')'
);

// =========================================================================
// 2. La magnitud del defecto y su cura, medidas contra el motor
// =========================================================================
echo "\n--- 2. Lo que el motor mide de un instante local y de uno de la zona del motor ---\n";

$pdo->exec('CREATE TEMP TABLE harness_clock_probe (label text, moment timestamptz)');
$insertProbe = $pdo->prepare('INSERT INTO harness_clock_probe (label, moment) VALUES (:label, :moment)');
$driftSeconds = static function (string $label) use ($pdo): float {
    $statement = $pdo->prepare(
        'SELECT EXTRACT(EPOCH FROM (now() - moment)) FROM harness_clock_probe WHERE label = :label'
    );
    $statement->execute([':label' => $label]);

    return (float)$statement->fetchColumn();
};

// El idioma que la clase usaba: un `new DateTimeImmutable()` desnudo, que tras la declaración es
// pared de sede (y antes era pared de la máquina, que es lo que hacía el defecto azaroso).
$insertProbe->execute([
    ':label' => 'sede',
    ':moment' => (new DateTimeImmutable('now', EngineClock::zone()))->setTimezone(
        new DateTimeZone(EngineClock::OPERATIONAL_TIMEZONE)
    )->format('Y-m-d H:i:s'),
]);
$sedeDrift = $driftSeconds('sede');
$operationalOffset = (new DateTimeZone(EngineClock::OPERATIONAL_TIMEZONE))->getOffset(EngineClock::now());

// El motor mide la antigüedad del instante guardado: si el render fue pared de sede, el valor
// guardado cae `offset` segundos *en el futuro*, así que la antigüedad sale negativa justo en el
// desfase operativo. Es la medida del defecto: «hace 49 horas» el motor lo leía como 47.
$assert(
    '2.1 Un instante local puesto en una columna con zona queda el huso entero por delante',
    abs($sedeDrift + $operationalOffset) <= 2,
    sprintf('antigüedad medida: %.0f s · desfase operativo: %d s', $sedeDrift, $operationalOffset)
);

$insertProbe->execute([
    ':label' => 'motor',
    ':moment' => EngineClock::stamp(EngineClock::now()),
]);
$engineDrift = $driftSeconds('motor');
$assert(
    '2.2 La marca del reloj del motor vuelve de la columna en su instante real',
    abs($engineDrift) <= 2,
    sprintf('deriva medida: %.0f s', $engineDrift)
);

$assert(
    '2.3 El defecto no es teórico: la pared de sede y la zona del motor no coinciden hoy',
    $operationalOffset !== 0,
    'el desfase operativo es 0: la clase no se manifestaría en este instante del calendario'
);

// =========================================================================
// 3. Las lecturas no dependen de la máquina
// =========================================================================
echo "\n--- 3. Las lecturas de columnas con zona viajan con su desfase ---\n";

$readBack = (string)$pdo->query("SELECT moment::text FROM harness_clock_probe WHERE label = 'motor'")->fetchColumn();
$assert(
    '3.1 La marca leída de una columna con zona trae su desfase (no es pared desnuda)',
    preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}([+-]\d{2}(:?\d{2})?)$/', $readBack) === 1,
    "marca leída: {$readBack}"
);
$assert(
    '3.2 `strtotime()` interpreta esa marca en su instante real, con cualquier reloj local',
    abs(strtotime($readBack) - EngineClock::now()->getTimestamp()) <= 2,
    sprintf('marca: %s · ahora: %d', $readBack, EngineClock::now()->getTimestamp())
);

// =========================================================================
// 4. El censo contra el ratchet declarado
// =========================================================================
echo "\n--- 4. Censo de instantes locales en ficheros con conexión viva al motor ---\n";

$hits = $census($root);
$totalHits = array_sum(array_map('count', $hits));

$undeclared = [];
$misdeclared = [];
foreach ($hits as $file => $fileHits) {
    $declared = $ratchet[$file]['hits'] ?? null;
    if ($declared === null) {
        $undeclared[$file] = $fileHits;
        continue;
    }
    if ($declared !== count($fileHits)) {
        $misdeclared[$file] = sprintf('declarado %d, censado %d', $declared, count($fileHits));
    }
}
$stale = [];
foreach ($ratchet as $file => $entry) {
    if (!isset($hits[$file])) {
        $stale[$file] = $entry['hits'];
    }
}

$assert(
    '4.1 El censo no encuentra sitios sin declarar (la clase no vuelve por la puerta de atrás)',
    $undeclared === [],
    $undeclared === [] ? '' : json_encode($undeclared, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
$assert(
    '4.2 El saldo declarado en el ratchet coincide con el censo real',
    $misdeclared === [],
    $misdeclared === [] ? '' : json_encode($misdeclared, JSON_UNESCAPED_UNICODE)
);
$assert(
    '4.3 Ninguna entrada del ratchet quedó obsoleta (un lote que repara un sitio retira su entrada)',
    $stale === [],
    $stale === [] ? '' : 'entradas sin censo: ' . json_encode($stale, JSON_UNESCAPED_UNICODE)
);
$naturalesValidas = [NATURALEZA_PUBLICADA, NATURALEZA_DEUDA, NATURALEZA_SIN_MOTOR];
$sinNaturaleza = array_keys(array_filter(
    $ratchet,
    static fn (array $entry): bool => !in_array($entry['naturaleza'], $naturalesValidas, true)
));
$assert(
    '4.4 El saldo de la clase está contado y cada entrada declara naturaleza, motivo y dueño',
    $totalHits === array_sum(array_map(static fn (array $entry): int => $entry['hits'], $ratchet))
        && count(array_filter($ratchet, static fn (array $entry): bool => $entry['dueno'] !== '' && $entry['motivo'] !== '')) === count($ratchet)
        && $sinNaturaleza === [],
    sprintf(
        'censo: %d · declarado: %d · naturalezas inválidas: %s',
        $totalHits,
        array_sum(array_map(static fn (array $entry): int => $entry['hits'], $ratchet)),
        json_encode($sinNaturaleza, JSON_UNESCAPED_UNICODE)
    )
);

echo "  Saldo declarado de la clase: {$totalHits} sitio(s) en " . count($hits) . " fichero(s).\n";
foreach ($ratchet as $file => $entry) {
    echo sprintf(
        "    · %s — %d sitio(s) · %s · dueño %s\n",
        $file,
        $entry['hits'],
        $entry['naturaleza'],
        $entry['dueno']
    );
}

// =========================================================================
// 5. El detector muerde (si no distingue, no certifica)
// =========================================================================
echo "\n--- 5. El detector distingue un espécimen con el defecto de uno curado ---\n";

$specimenDir = sys_get_temp_dir() . '/vendguard_clock_specimen_' . bin2hex(random_bytes(4));

/**
 * Escribe un espécimen y devuelve el censo que el detector hace de él.
 *
 * @return array<string, array<int, string>>
 */
$censusOfSpecimen = static function (string $specimenDir, string $body) use ($census): array {
    @mkdir($specimenDir . '/tests/integration', 0777, true);
    file_put_contents($specimenDir . '/tests/integration/Specimen.php', $body);

    return $census($specimenDir);
};
$removeSpecimen = static function (string $dir): void {
    foreach (['/tests/integration/Specimen.php', '/tests/integration', '/tests'] as $suffix) {
        @unlink($dir . $suffix);
        @rmdir($dir . $suffix);
    }
    @rmdir($dir);
};

$defectiveSpecimen = <<<'PHP'
<?php
$pdo = ConnectionFactory::getConnection();
$now = new DateTimeImmutable();
$created = $now->sub(new DateInterval('PT75M'))->format('Y-m-d H:i:s');
$pdo->prepare('UPDATE incidents SET created_at = :created')->execute([':created' => $created]);
PHP;

$curedSpecimen = <<<'PHP'
<?php
$pdo = ConnectionFactory::getConnection();
$now = EngineClock::now();
$created = $now->sub(new DateInterval('PT75M'))->format('Y-m-d H:i:s');
$pdo->prepare('UPDATE incidents SET created_at = :created')->execute([':created' => $created]);
PHP;

$defectiveHits = $censusOfSpecimen($specimenDir, $defectiveSpecimen);
$curedHits = $censusOfSpecimen($specimenDir, $curedSpecimen);
$removeSpecimen($specimenDir);

$assert(
    '5.1 El detector marca el espécimen con el render en hora local',
    array_sum(array_map('count', $defectiveHits)) === 1,
    'censo del espécimen defectuoso: ' . json_encode($defectiveHits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
$assert(
    '5.2 El detector deja limpio el mismo espécimen con el reloj del motor',
    $curedHits === [],
    'censo del espécimen curado: ' . json_encode($curedHits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

// Resumen final
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS PASARON EXITOSAMENTE (0 fallos)!\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S).\n";
}
echo "======================================================================\n";

if ($failures > 0) {
    exit(1);
}
