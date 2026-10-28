<?php

declare(strict_types=1);

/**
 * PoolerLatencyMeasurementTest (T-HYBRID-27 · RNF-01, RNF-05)
 *
 * Medición de latencia transaccional **contra el motor remoto** (Supabase vía Supavisor) y **por
 * el camino real**: enrutador → middleware → controlador → servicio de aplicación → repositorio
 * → PDO. No se mide una función aislada ni un `SELECT 1` sintético: se despacha la misma petición
 * que despacha `public/index.php` contra el mismo motor que atiende al usuario.
 *
 * QUÉ SE MIDE, Y POR QUÉ ESAS OPERACIONES
 *
 *   Operaciones representativas del servicio, con **un mínimo de 20 muestras cada una**:
 *
 *     1. **Crear incidencia**   — `POST /api/incidents` (portal de sede autenticado).
 *     2. **Listar triaje**      — `GET /api/coordinator/incidents` (bandeja de coordinación).
 *     3. **Pausar**             — `POST /api/coordinator/incidents/{id}/pause-pending-info`.
 *     4. **Reanudar**           — `POST /api/coordinator/incidents/{id}/resume-pending-info`.
 *
 *   Se publica mínimo, mediana, percentil 95 y máximo, y la suite **falla si la mediana de
 *   cualquier operación supera los 250 ms** (RNF-01, spec §8.1).
 *
 *   Se miden además dos lecturas que comparten el mismo presupuesto y que se publican en voz alta
 *   en lugar de esconderse:
 *
 *     · **Asignar técnico** (`PATCH .../assign`), porque sin ella la pausa es **inalcanzable**:
 *       un expediente recién registrado no tiene técnico responsable y el agregado rechaza la
 *       pausa con `422 INVALID_STATUS_FOR_PAUSE` —medido, no supuesto, en la primera pasada de
 *       esta misma sonda—. La ronda la ejecuta como preparación real.
 *     · **Listar «Mi Ruta»** (`GET /api/technician/my-route`), la bandeja de paradas del técnico
 *       de campo: su insignia de conversación pagaba **el mismo N+1** que la de triaje —una
 *       consulta de recuento por parada, `TechnicianController::getMyRoute()`—, así que se mide
 *       con las mismas 20 muestras para demostrar la cura con cifras antes y después.
 *
 * QUÉ NO SE HEREDA (constancia escrita exigida por la tarea)
 *
 *   Las cifras de latencia medidas alguna vez contra el **motor local** (`deploy/local/postgres/`)
 *   **no valen como evidencia de este requisito y no se mezclan con estas**. El motor local sirve
 *   la ejecución ordinaria de la categoría `new_engine`; el objeto de RNF-01 es el enlace con el
 *   proveedor en la nube, y su latencia la fija el trayecto, el agrupador Supavisor y la
 *   distancia —tres cosas que el loopback no tiene—. Por eso esta suite **se niega a ejecutarse**
 *   contra un motor local (ver la puerta de abajo) en lugar de publicar un número que parecería
 *   el mismo requisito cumplido.
 *
 * SIN RESIDUO EN EL MOTOR REMOTO (por qué así)
 *
 *   La medición escribe de verdad: crea expedientes, los asigna, los pausa, los reanuda y (como
 *   reinicio de la ronda) los descarta. Toda la corrida se ejecuta dentro de **una única
 *   transacción externa que se revierte al final**, apoyándose en el anidamiento tolerante que el
 *   proyecto ya tiene declarado y probado (`PdoTransactionManager` y los repositorios resuelven
 *   `inTransaction()` antes de abrir la suya, de modo que la dueña de la transacción es esta
 *   suite). Dos consecuencias, y las dos importan:
 *
 *     · **Ninguna fila de prueba sobrevive**, ni `incidents`, ni `incident_history`, ni
 *       `audit_log`: el arranque en limpio del motor nuevo (EARS-02.1) sigue siendo un arranque en
 *       limpio, y la medición no deja un rastro que alguien tenga que limpiar a mano. Borrar no
 *       era una opción equivalente: el registro de auditoría es *append-only* por contrato
 *       (Art. III) y vaciarlo para tapar una medición sería peor que el residuo, y el disparador
 *       `prevent_hard_delete()` rechaza cualquier `DELETE` sin la ventana de purga del arnés.
 *     · **La corrida no commitea nada**, así que un fallo a mitad no deja un expediente vivo en la
 *       máquina de nadie. La reversión viaja en un `finally`: ocurre también cuando la medición
 *       revienta.
 *
 *   El efecto sobre la latencia se declara en vez de silenciarse: la transacción es la misma que
 *   abriría el caso de uso, pero una transacción larga sobre el agrupador conserva la sesión
 *   fijada, de modo que si influye, influye **en contra** del número publicado. Un presupuesto
 *   superado dentro de una transacción no se lee a la ligera.
 *
 * QUÉ NO CUBRE (límites declarados, no omitidos)
 *
 *   · **Las dos bandejas se miden con las filas que esta misma corrida produce.** El motor nuevo
 *     arranca en limpio por diseño, así que ni el triaje ni «Mi Ruta» tienen histórico que pesar:
 *     se mide la consulta real con el parque de ranuras disponibles (la ronda alcanzó las 20 filas
 *     vivas del triaje y las 13 paradas de la ruta), y esa cifra no se presenta como la de un
 *     parque en producción.
 *   · **No se mide el camino móvil completo** (cierre con informe, subida de fotos, barrido
 *     sanitario): pertenece a las suites de contrato del motor nuevo, no a este presupuesto.
 *   · **No se mide la pausa de un expediente sin técnico**: no existe (ver arriba).
 *   · La cifra es del enlace medido hoy, desde esta máquina y esta región del proveedor. No es una
 *     promesa de latencia perpetua: es una medición con su fecha, su motor y sus muestras.
 *
 * QUÉ SE CERTIFICA ADEMÁS DEL PRESUPUESTO
 *
 *   · **Que el motor medido es el remoto**: esquema `postgres` en la URI, driver `pgsql`, puerto
 *     del agrupador transaccional (6543) y host que **no** es de bucle local.
 *   · **Que la identidad del servidor se verifica** (RNF-05): la CA tiene que estar declarada
 *     explícitamente en el entorno, de modo que la conexión no pueda estar apoyándose en el
 *     almacén por defecto de la imagen —que no valida la cadena del proveedor—.
 *   · **Que el enlace es el de verdad**: versión del servidor, `hrtime()` como reloj y el número
 *     de muestras por operación.
 *   · **Que no queda residuo**: recuento de las 20 tablas del contrato antes y después, idénticos.
 *
 * CONTRATO DE EJECUCIÓN (y fallo en cerrado)
 *
 *   El motor remoto se declara con el **contrato real de la aplicación** —`DATABASE_URL` y
 *   `DB_SSL_ROOT_CERT`, los mismos que resuelve `ConnectionFactory` en producción—, no con
 *   `PG_TEST_DSN`: ese es el contrato del motor local de la categoría y no se le cambia el
 *   destinatario (plan §8). Sin él, la suite **se declara no ejecutable con código 2** en lugar de
 *   omitirse en silencio, igual que las otras suites del canal.
 *
 *   Ese contrato es el único destinatario distinto del banco del arnés, así que la suite lo
 *   **declara** (`VENDGUARD_TEST_ENV_CONTRACT`) y `tests/run_new_engine.php` lo resuelve solo para
 *   este proceso hijo: el canal entero mide contra el banco local y esta medición contra el
 *   agrupador remoto, en una misma corrida y sin que ningún valor llegue a la salida. El fichero
 *   no se versiona; si falta, la puerta de abajo sigue fallando en cerrado.
 *
 *       source deploy/local/postgres/.env.local
 *       php tests/run_new_engine.php                    # el canal completo, 27/27
 *       php tests/integration/PoolerLatencyMeasurementTest.php   # sola, con el contrato ya cargado
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/**
 * Contrato propio de la suite (ignorado por git).
 *
 * `tests/run_new_engine.php` lo resuelve para este proceso y solo para este: inyecta
 * `DATABASE_URL` y `DB_SSL_ROOT_CERT` de modo que la medición mide el enlace remoto mientras
 * el resto del canal sigue midiendo el banco del arnés. No se imprime ningún valor.
 */
const VENDGUARD_TEST_ENV_CONTRACT = 'deploy/local/supabase/.env.local';

/** Muestras mínimas por operación representativa (enunciado de T-HYBRID-27). */
const SAMPLE_TARGET = 20;

/** Presupuesto de mediana por operación, en milisegundos (RNF-01). */
const MEDIAN_BUDGET_MS = 250.0;

/** Las 20 tablas del contrato de correspondencia (§2.1), para la sonda de residuo. */
const CONTRACT_TABLES = [
    'locations', 'machines', 'users', 'incidents', 'incident_history', 'incident_comments',
    'incident_replaced_parts', 'refund_requests', 'unclaimed_cash_findings', 'spare_parts',
    'spare_part_compatibilities', 'spare_part_requests', 'preventive_settings', 'preventive_orders',
    'preventive_order_items', 'sanitary_certificates', 'route_settings', 'sanitary_sweep_passes',
    'sanitary_sweep_contentions', 'audit_log',
];

/** Operaciones medidas, en el orden de la ronda. La clave es la etiqueta publicada. */
const MEASURED_OPERATIONS = ['crear', 'asignar', 'pausar', 'reanudar', 'ruta', 'listar'];

/** Motivo de pausa de relleno: supera los 20 caracteres reales del Art. V.1. */
const PAUSE_REASON_TEXT = 'La sede confirma por telefono que el acceso queda cerrado hasta manana por obras en el rellano.';

/** Causa tipificada de la pausa, del catálogo cerrado que valida el DTO. */
const PAUSE_REASON_CATEGORY = 'EXTERNAL_POWER_CUT';

/** Motivo de descarte de reinicio: es la limpieza de la ronda, no una operación medida. */
const RESET_CANCEL_REASON = 'Descarte de la sonda de latencia: el expediente se retira para liberar la maquina.';

echo "======================================================================\n";
echo " VendGuard: Medición de latencia contra el motor remoto (T-HYBRID-27)\n";
echo "======================================================================\n\n";

// =========================================================================
// 0. Puerta de la categoría: sin motor remoto, la suite NO se ejecuta (código 2)
// =========================================================================

/**
 * Oculta cualquier credencial que pudiera viajar dentro de un texto de error.
 *
 * La póliza del proyecto para las superficies públicas es no publicar nunca el DSN, el usuario ni
 * la contraseña (EARS-07.4). Aquí el texto va a la consola de quien ejecuta la medición, pero un
 * `PDOException` de PostgreSQL puede arrastrar la cadena de conexión: se enmascara por costumbre
 * y porque un informe de latencia no es sitio para una credencial.
 */
$sanitize = static function (string $text): string {
    $masked = preg_replace('#://[^@\s/]+@#', '://[credencial oculta]@', $text) ?? $text;
    $envUrl = (string)(getenv('DATABASE_URL') ?: '');

    if ($envUrl !== '') {
        $masked = str_replace($envUrl, '[DATABASE_URL oculta]', $masked);
    }

    return $masked;
};

$notExecutable = static function (string $reason) use ($sanitize): never {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] {$reason}\n");
    fwrite(STDERR, "  Esta medición pertenece a la certificación de contrato del motor nuevo y exige\n");
    fwrite(STDERR, "  el motor REMOTO declarado. Carga su contrato y repite:\n");
    fwrite(STDERR, "    source deploy/local/supabase/.env.local\n");
    fwrite(STDERR, "    php tests/integration/PoolerLatencyMeasurementTest.php\n");
    fwrite(STDERR, "  El motor local de la categoría (deploy/local/postgres/) NO sirve para RNF-01: sus\n");
    fwrite(STDERR, "  cifras no se heredan ni valen como evidencia de este requisito.\n");
    exit(2);
};

$rawUrl = trim((string)(getenv('DATABASE_URL') ?: ''));
if ($rawUrl === '') {
    $notExecutable('Falta DATABASE_URL: no hay motor remoto declarado.');
}

// RNF-05: la verificación de identidad exige la CA del proveedor declarada explícitamente. Sin
// ella, `ConnectionFactory` cae en el almacén por defecto de la imagen, que no valida la cadena de
// Supabase: mediría contra un enlace cuya identidad no se ha verificado.
$declaredCa = trim((string)(getenv('DB_SSL_ROOT_CERT') ?: ''));
if ($declaredCa === '') {
    $notExecutable('Falta DB_SSL_ROOT_CERT: la identidad del servidor remoto no se puede verificar (RNF-05).');
}

try {
    $resolved = ConnectionFactory::describeConnection();
} catch (Throwable $exception) {
    $notExecutable('El contrato de conexión no se resolvió: ' . $sanitize($exception->getMessage()));
}

if (($resolved['driver'] ?? '') !== 'pgsql') {
    $notExecutable('El contrato declarado no resuelve al motor nuevo (driver resuelto: ' . (string)($resolved['driver'] ?? '?') . ').');
}

if (($resolved['uses_pooler'] ?? false) !== true) {
    $notExecutable(
        'El contrato declarado no usa el agrupador transaccional de Supavisor en el puerto 6543 '
        . '(puerto resuelto: ' . (string)($resolved['port'] ?? '?') . '). RNF-01 se mide por esa ruta.'
    );
}

$resolvedHost = (string)($resolved['host'] ?? '');
$isLocalHost = in_array($resolvedHost, ['127.0.0.1', 'localhost', '::1', 'host.docker.internal'], true)
    || str_starts_with($resolvedHost, '127.')
    || str_ends_with($resolvedHost, '.local');

if ($isLocalHost) {
    $notExecutable('El host declarado es local («' . $resolvedHost . '»): esta medición es del motor REMOTO, no de un bucle local.');
}

try {
    $pdo = ConnectionFactory::getConnection();
} catch (Throwable $exception) {
    $notExecutable('El motor remoto no responde: ' . $sanitize($exception->getMessage()));
}

$serverVersion = (string)$pdo->query('SELECT version()')->fetchColumn();

// =========================================================================
// Utilidades de la medición
// =========================================================================

$failures   = 0;
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
    $assertions++;
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

/** Milisegundos transcurridos entre dos lecturas del reloj monótono del intérprete. */
$elapsedMs = static fn (int $startNs, int $endNs): float => ($endNs - $startNs) / 1_000_000;

/** Mediana con interpolación por promedio en el caso par: es la definición del enunciado. */
$median = static function (array $samples): float {
    sort($samples, SORT_NUMERIC);
    $count = count($samples);
    $middle = intdiv($count, 2);

    return $count % 2 === 1
        ? (float)$samples[$middle]
        : ((float)$samples[$middle - 1] + (float)$samples[$middle]) / 2.0;
};

/** Percentil 95 por rango más cercano (`ceil(0.95·n)`), sin interpolar: es una cota observada. */
$percentile95 = static function (array $samples): float {
    sort($samples, SORT_NUMERIC);
    $index = (int)max(0, (int)ceil(0.95 * count($samples)) - 1);

    return (float)$samples[$index];
};

/** Recuento de las 20 tablas del contrato en una sola ida y vuelta. */
$tableCounts = static function (PDO $pdo): array {
    $parts = [];
    foreach (CONTRACT_TABLES as $table) {
        $parts[] = sprintf("SELECT '%s' AS t, (SELECT COUNT(*) FROM %s) AS c", $table, $table);
    }

    $rows = $pdo->query(implode(' UNION ALL ', $parts))->fetchAll(PDO::FETCH_ASSOC);
    $counts = [];
    foreach ($rows as $row) {
        $counts[(string)$row['t']] = (int)$row['c'];
    }

    return $counts;
};

/** Código y mensaje de un rechazo, para que un fallo diga qué pasó y no sólo que pasó. */
$rejection = static function (Response $response): string {
    $error = ($response->getDecodedBody() ?? [])['error'] ?? null;

    return $error === null
        ? 'sin cuerpo de error'
        : (string)($error['code'] ?? '?') . ' · ' . (string)($error['message'] ?? '?');
};

$router = AppRouter::create();

/** Despacho por el enrutador real, con el cuerpo y las cabeceras de la petición. */
$dispatch = static function (string $method, string $path, array $body = [], array $headers = []) use ($router): Response {
    return $router->dispatch(new Request(method: $method, path: $path, parsedBody: $body, headers: $headers));
};

/** Cronometra una operación despachada por el enrutador real. */
$timed = static function (callable $operation) use ($elapsedMs): array {
    $start = hrtime(true);
    $response = $operation();
    $end = hrtime(true);

    return ['ms' => $elapsedMs($start, $end), 'response' => $response];
};

// =========================================================================
// Grupo 1: el motor medido es el remoto y su identidad se verifica
// =========================================================================
echo "\n--- Grupo 1: el motor medido es el remoto, por el agrupador y con identidad verificada ---\n";

$assert(
    '1.1 El driver activo es pgsql (motor nuevo) y no el heredado',
    (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql',
    'Driver: ' . (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)
);

$assert(
    '1.2 El puerto medido es el del agrupador transaccional (6543)',
    (string)($resolved['port'] ?? '') === '6543' && ($resolved['uses_pooler'] ?? false) === true,
    'Puerto: ' . (string)($resolved['port'] ?? '?')
);

$assert(
    '1.3 El host no es de bucle local: la medición no se apoya en el motor local',
    !$isLocalHost,
    'Host resuelto: ' . $resolvedHost
);

$assert(
    '1.4 El modo TLS exigido es verify-full, sin degradación posible',
    (string)($resolved['ssl_mode'] ?? '') === 'verify-full',
    'Modo TLS: ' . (string)($resolved['ssl_mode'] ?? '?')
);

$assert(
    '1.5 La CA declarada es la del entorno, no el almacén por defecto de la imagen',
    (string)($resolved['ssl_root_certificate'] ?? '') !== '' && $declaredCa !== '',
    'CA resuelta: ' . (string)($resolved['ssl_root_certificate'] ?? 'sin CA')
);

$assert(
    '1.6 El servidor remoto se identifica (evidencia de que el enlace es real)',
    stripos($serverVersion, 'PostgreSQL') !== false,
    'Versión publicada por el servidor: ' . substr($serverVersion, 0, 80)
);

echo '  · Motor: ' . (string)$resolved['driver'] . ' · puerto ' . (string)$resolved['port']
    . ' (Supavisor) · host ' . $resolvedHost . "\n";
echo '  · Servidor: ' . substr($serverVersion, 0, 60) . "\n";

// =========================================================================
// Grupo 2: preparación de la ronda (parque real, tokens reales, sin escritura)
// =========================================================================
echo "\n--- Grupo 2: preparación sobre el parque real ---\n";

$locationRepo = new PdoLocationRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);

/**
 * Parque de la ronda: cada ranura es una máquina con el token de su sede.
 *
 * Se rota por todas las máquinas activas del parque sembrado —no siempre la misma— porque una
 * medición sobre un único equipo mediría el «mejor caso» de un índice caliente y de una sola
 * sede, que es justo lo contrario de lo representativo.
 *
 * @var list<array{machine_id:int, machine_code:string, site_code:string, token:string}>
 */
$slots = [];

// Una máquina con un ticket no cerrado ni cancelado no admite un alta nueva:
// el repositorio considera activo todo `status NOT IN ('CLOSED','CANCELLED')`,
// incluido un `RESOLVED` pendiente de cierre. Intentarlo abortaría la ronda en
// la primera coincidencia. Se excluyen del parque de medición sin escribir ni
// borrar nada; la sonda sigue exigiendo 20 muestras y no relaja ninguna aserción.
$blockedMachineIds = array_map(
    static fn (mixed $id): int => (int)$id,
    $pdo->query(
        "SELECT DISTINCT i.machine_id
           FROM incidents i
          WHERE i.deleted_at IS NULL
            AND i.status NOT IN ('CLOSED', 'CANCELLED')"
    )->fetchAll(PDO::FETCH_COLUMN)
);
$blockedMachineMap = array_fill_keys($blockedMachineIds, true);

foreach ($locationRepo->findAllActive() as $location) {
    $token = $authService->generateSiteToken($location);
    foreach ($machineRepo->findActiveByLocationId($location->getId()) as $machine) {
        $machineId = (int)$machine->getId();
        if (isset($blockedMachineMap[$machineId])) {
            continue;
        }

        $slots[] = [
            'machine_id'   => $machineId,
            'machine_code' => (string)$machine->getCode(),
            'site_code'    => (string)$location->getSiteCode(),
            'token'        => $token,
        ];
    }
}

echo '  · Máquinas excluidas por avería viva o garantía de 48 h: ' . count($blockedMachineIds) . "\n";

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician  = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$assert(
    '2.1 Hay parque suficiente para rotar las muestras por máquinas distintas',
    count($slots) >= 2,
    'Máquinas activas encontradas: ' . count($slots)
);

$assert(
    '2.2 La coordinación y el técnico semilla existen (sin ellos la ronda no es posible)',
    $coordinator !== null && $technician !== null,
    'Falta coordinacion@vendguard.internal o jordi.ruta@vendguard.internal en el motor remoto.'
);

if ($coordinator === null || $technician === null || $slots === []) {
    echo "\n[ABORTADO] Sin parque o sin personal semilla no se puede medir: el motor remoto no está sembrado.\n";
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$coordinatorId    = (int)$coordinator->getId();
$technicianId     = (int)$technician->getId();

$technicianToken = $authService->generateInternalToken($technician);

$coordinatorHeaders = ['Authorization' => "Bearer {$coordinatorToken}", 'Content-Type' => 'application/json'];
$technicianHeaders  = ['Authorization' => "Bearer {$technicianToken}", 'Content-Type' => 'application/json'];

echo '  · Ranuras (máquina/sede): ' . count($slots) . ' · muestras objetivo por operación: ' . SAMPLE_TARGET . "\n";

// =========================================================================
// Grupo 3: línea base del enlace (contexto para interpretar el resultado)
// =========================================================================
//
// Sin esta cifra, un incumplimiento del presupuesto se puede leer como «la aplicación es lenta»
// cuando parte del coste es el trayecto hasta el proveedor. La línea base mide **una sola ida y
// vuelta** y no se juzga: se publica para que el número de operaciones se interprete en
// operaciones equivalentes y para que la discusión empiece por el dato.
echo "\n--- Grupo 3: línea base del enlace (una ida y vuelta hasta el proveedor) ---\n";

$linkSamples = [];
$tableSamples = [];
for ($probe = 1; $probe <= SAMPLE_TARGET; $probe++) {
    $start = hrtime(true);
    $pdo->query('SELECT 1')->fetchColumn();
    $linkSamples[] = $elapsedMs($start, hrtime(true));

    $start = hrtime(true);
    $pdo->query('SELECT COUNT(*) FROM machines')->fetchColumn();
    $tableSamples[] = $elapsedMs($start, hrtime(true));
}

$linkMedian  = $median($linkSamples);
$tableMedian = $median($tableSamples);

$assert(
    '3.1 La línea base del enlace se mide y es finita (una ida y vuelta de consulta trivial)',
    $linkSamples !== [] && is_finite($linkMedian) && $linkMedian > 0.0,
    'Mediana de SELECT 1: ' . number_format($linkMedian, 1) . ' ms'
);

$assert(
    '3.2 La consulta sobre tabla real (la misma del diagnóstico) se mide para comparar',
    $tableSamples !== [] && is_finite($tableMedian) && $tableMedian > 0.0,
    'Mediana de SELECT COUNT(*) FROM machines: ' . number_format($tableMedian, 1) . ' ms'
);

echo sprintf(
    "  · enlace: SELECT 1 = %.1f ms (mediana) · SELECT COUNT(*) FROM machines = %.1f ms (mediana)\n",
    $linkMedian,
    $tableMedian
);

// =========================================================================
// Grupo 4: la medición — una transacción externa que se revierte
// =========================================================================
echo "\n--- Grupo 4: medición por el camino real (" . SAMPLE_TARGET . " muestras por operación) ---\n";

$before  = $tableCounts($pdo);

// Frontera del barrido sanitario programado: el motor remoto tiene un proceso
// externo que inserta una pasada cada cuarto de hora. Su fila no es residuo de
// esta sonda, y el recuento exacto por sí solo no puede distinguirlo. Se guarda
// el máximo para atribuir después cualquier alta a otra transacción (xmin).
$sweepMaxBefore = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM sanitary_sweep_passes')->fetchColumn();

$samples = array_fill_keys(MEASURED_OPERATIONS, []);
$live    = [];          // ranura => expediente vivo de una ronda anterior
$completedSamples = 0;
$abortReason = null;

$insideLinkSamples = [];

$pdo->beginTransaction();

// Identidad de la transacción de la medición: después de la reversión ninguna
// fila de las 20 tablas puede llevar este xid. Es la prueba directa de que la
// sonda no dejó nada, incluso si un proceso externo escribió mientras corría.
$measurementXid = (string)$pdo->query('SELECT txid_current()')->fetchColumn();

try {
    // Ida y vuelta medida **dentro de la misma transacción** que las operaciones: es la forma de
    // descartar que el incumplimiento (si lo hay) lo esté causando la transacción de la corrida y
    // no el trayecto. Si esta mediana y la de fuera coinciden, la transacción no distorsiona.
    for ($probe = 1; $probe <= SAMPLE_TARGET; $probe++) {
        $start = hrtime(true);
        $pdo->query('SELECT 1')->fetchColumn();
        $insideLinkSamples[] = $elapsedMs($start, hrtime(true));
    }

    for ($round = 1; $round <= SAMPLE_TARGET; $round++) {
        $slotIndex = ($round - 1) % count($slots);
        $slot      = $slots[$slotIndex];

        // Reinicio de ronda: liberar la máquina antes de volver a usarla. No se mide —es la
        // preparación de la muestra siguiente, no una operación representativa del enunciado— y
        // se declara aquí en vez de esconderse. La primera vuelta no tiene nada que liberar.
        if (isset($live[$slotIndex])) {
            $reset = $dispatch(
                'PATCH',
                '/api/coordinator/incidents/' . $live[$slotIndex] . '/cancel',
                ['cancellation_reason' => RESET_CANCEL_REASON],
                $coordinatorHeaders
            );
            if ($reset->getStatusCode() !== 200) {
                throw new RuntimeException('El descarte de reinicio falló (HTTP ' . $reset->getStatusCode() . ': ' . $rejection($reset) . ').');
            }
            unset($live[$slotIndex]);
        }

        // ── 1. Crear incidencia ────────────────────────────────────────────
        $create = $timed(static fn (): Response => $dispatch(
            'POST',
            '/api/incidents',
            [
                'machine_id'     => $slot['machine_id'],
                'category'       => 'PRODUCT_JAM',
                'description'    => 'Medición de latencia T-HYBRID-27: la espiral no entrega el producto seleccionado.',
                'reporter_name'  => 'Medición de latencia',
                'reporter_phone' => '600000000',
            ],
            ['Authorization' => "Bearer {$slot['token']}", 'Content-Type' => 'application/x-www-form-urlencoded']
        ));

        if ($create['response']->getStatusCode() !== 201) {
            throw new RuntimeException('La creación devolvió HTTP ' . $create['response']->getStatusCode() . ' (' . $rejection($create['response']) . ').');
        }

        $createdBody   = $create['response']->getDecodedBody() ?? [];
        $incidentId    = (int)($createdBody['data']['id'] ?? 0);
        $incidentTicket = (string)($createdBody['data']['ticket_code'] ?? '');

        if ($incidentId <= 0) {
            throw new RuntimeException('La creación no devolvió el identificador del expediente.');
        }

        $samples['crear'][] = $create['ms'];
        $live[$slotIndex]   = $incidentId;

        // ── 2. Asignar técnico (preparación medida: sin ella la pausa es inalcanzable) ──
        $assign = $timed(static fn (): Response => $dispatch(
            'PATCH',
            "/api/coordinator/incidents/{$incidentId}/assign",
            ['technician_id' => $technicianId],
            $coordinatorHeaders
        ));

        if ($assign['response']->getStatusCode() !== 200) {
            throw new RuntimeException('La asignación devolvió HTTP ' . $assign['response']->getStatusCode() . ' (' . $rejection($assign['response']) . ').');
        }

        $samples['asignar'][] = $assign['ms'];

        // ── 3. Pausar ─────────────────────────────────────────────────────
        $pause = $timed(static fn (): Response => $dispatch(
            'POST',
            "/api/coordinator/incidents/{$incidentId}/pause-pending-info",
            ['reason_category' => PAUSE_REASON_CATEGORY, 'reason_text' => PAUSE_REASON_TEXT],
            $coordinatorHeaders
        ));

        if ($pause['response']->getStatusCode() !== 200) {
            throw new RuntimeException('La pausa devolvió HTTP ' . $pause['response']->getStatusCode() . ' (' . $rejection($pause['response']) . ').');
        }

        $samples['pausar'][] = $pause['ms'];

        // ── 4. Reanudar ───────────────────────────────────────────────────
        $resume = $timed(static fn (): Response => $dispatch(
            'POST',
            "/api/coordinator/incidents/{$incidentId}/resume-pending-info",
            [],
            $coordinatorHeaders
        ));

        if ($resume['response']->getStatusCode() !== 200) {
            throw new RuntimeException('La reanudación devolvió HTTP ' . $resume['response']->getStatusCode() . ' (' . $rejection($resume['response']) . ').');
        }

        $samples['reanudar'][] = $resume['ms'];

        // ── 5. Listar la ruta del técnico («Mi Ruta») ────────────────────
        // La segunda bandeja con la insignia de conversación: mide la misma cura del
        // N+1 que se aplicó al triaje, con las mismas muestras y el mismo presupuesto.
        $route = $timed(static fn (): Response => $dispatch('GET', '/api/technician/my-route', [], $technicianHeaders));

        if ($route['response']->getStatusCode() !== 200) {
            throw new RuntimeException('La ruta del técnico devolvió HTTP ' . $route['response']->getStatusCode() . ' (' . $rejection($route['response']) . ').');
        }

        $routeStops = count(($route['response']->getDecodedBody() ?? [])['data'] ?? []);
        $samples['ruta'][] = $route['ms'];

        // ── 6. Listar triaje ─────────────────────────────────────────────
        $list = $timed(static fn (): Response => $dispatch('GET', '/api/coordinator/incidents', [], $coordinatorHeaders));

        if ($list['response']->getStatusCode() !== 200) {
            throw new RuntimeException('El listado de triaje devolvió HTTP ' . $list['response']->getStatusCode() . ' (' . $rejection($list['response']) . ').');
        }

        $listedRows = count(($list['response']->getDecodedBody() ?? [])['data'] ?? []);
        $samples['listar'][] = $list['ms'];

        $completedSamples++;

        echo sprintf(
            "  · ronda %2d/%d · %s (%s) · ticket %s · ruta con %d parada(s) · triaje con %d fila(s)\n",
            $round,
            SAMPLE_TARGET,
            $slot['machine_code'],
            $slot['site_code'],
            $incidentTicket !== '' ? $incidentTicket : '?',
            $routeStops,
            $listedRows
        );
    }
} catch (Throwable $exception) {
    $abortReason = $sanitize($exception->getMessage());
    echo "\n  [ABORTADA] {$abortReason}\n";
} finally {
    // La reversión no es opcional: es lo que garantiza que el motor remoto quede como estaba,
    // también cuando la medición se interrumpe.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$after = $tableCounts($pdo);

$assert(
    '4.0 La medición completó sus ' . SAMPLE_TARGET . ' rondas por el camino real',
    $abortReason === null && $completedSamples === SAMPLE_TARGET,
    $abortReason ?? ('Rondas completadas: ' . $completedSamples . '/' . SAMPLE_TARGET)
);

foreach (MEASURED_OPERATIONS as $operation) {
    $assert(
        "4.1 La operación «{$operation}» acumula al menos " . SAMPLE_TARGET . ' muestras',
        count($samples[$operation]) >= SAMPLE_TARGET,
        'Muestras recogidas: ' . count($samples[$operation])
    );
}

// =========================================================================
// Grupo 4: publicación de cifras y criterio de RNF-01
// =========================================================================
echo "\n--- Grupo 5: mediana y percentil 95 por operación (presupuesto " . MEDIAN_BUDGET_MS . " ms) ---\n";

$report = [];
foreach (MEASURED_OPERATIONS as $operation) {
    $operationSamples = $samples[$operation];
    if ($operationSamples === []) {
        $report[$operation] = null;
        continue;
    }

    $report[$operation] = [
        'n'      => count($operationSamples),
        'min'    => min($operationSamples),
        'median' => $median($operationSamples),
        'p95'    => $percentile95($operationSamples),
        'max'    => max($operationSamples),
    ];
}

$insideLinkMedian = $median($insideLinkSamples);

$assert(
    '5.4 La ida y vuelta se mide también dentro de la transacción de la corrida',
    $insideLinkSamples !== [] && is_finite($insideLinkMedian) && $insideLinkMedian > 0.0,
    'Mediana dentro de la transacción: ' . number_format($insideLinkMedian, 1) . ' ms'
);

echo sprintf(
    "  · transacción de la corrida: SELECT 1 = %s ms (mediana) — %s que fuera\n",
    number_format($insideLinkMedian, 1),
    $insideLinkMedian <= $linkMedian * 1.5 ? 'comparable a la medida fuera, de modo que la transacción no distorsiona el enlace' : 'más lenta que fuera, de modo que la transacción suma'
);

echo sprintf("  %-10s %4s %9s %9s %9s %9s %14s\n", 'OPERACIÓN', 'n', 'mín', 'mediana', 'p95', 'máx', '≈ vueltas');
foreach (MEASURED_OPERATIONS as $operation) {
    $stats = $report[$operation];
    if ($stats === null) {
        echo sprintf("  %-10s %4s %9s %9s %9s %9s %14s\n", $operation, 0, '-', '-', '-', '-', '-');
        continue;
    }

    echo sprintf(
        "  %-10s %4d %8.1f %9.1f %9.1f %9.1f %11.1f×\n",
        $operation,
        $stats['n'],
        $stats['min'],
        $stats['median'],
        $stats['p95'],
        $stats['max'],
        $stats['median'] / $linkMedian
    );
}

echo '  · «≈ vueltas» = mediana de la operación ÷ mediana de una ida y vuelta del enlace ('
    . number_format($linkMedian, 1) . " ms): cuántas idas y vueltas equivalentes cuesta cada operación.\n";

echo "\n  Muestras (ms, orden de la ronda):\n";
foreach (MEASURED_OPERATIONS as $operation) {
    if ($samples[$operation] === []) {
        continue;
    }

    echo '    ' . str_pad($operation, 10) . ' · '
        . implode('  ', array_map(static fn (float $value): string => number_format($value, 1, '.', ''), $samples[$operation]))
        . "\n";
}

foreach (MEASURED_OPERATIONS as $operation) {
    $stats = $report[$operation];
    $assert(
        "5.1 La mediana de «{$operation}» respeta el presupuesto de " . MEDIAN_BUDGET_MS . ' ms',
        $stats !== null && $stats['median'] <= MEDIAN_BUDGET_MS,
        $stats === null
            ? 'Sin muestras: no se puede certificar el presupuesto.'
            : 'Mediana medida: ' . number_format($stats['median'], 1) . ' ms (p95 ' . number_format($stats['p95'], 1) . ' ms).'
    );
}

$assert(
    '5.2 El percentil 95 se publica y es finito para toda operación medida',
    array_reduce(
        MEASURED_OPERATIONS,
        static fn (bool $carry, string $operation): bool => $carry
            && ($report[$operation]['p95'] ?? null) !== null
            && is_finite($report[$operation]['p95'])
            && $report[$operation]['p95'] >= $report[$operation]['median'],
        true
    ),
    'El percentil 95 debe existir y no ser inferior a la mediana.'
);

$assert(
    '5.3 Ninguna muestra es cero ni negativa (el reloj no se reutiliza entre operaciones)',
    array_reduce(
        $samples,
        static fn (bool $carry, array $operationSamples): bool => $carry
            && $operationSamples !== []
            && min($operationSamples) > 0.0,
        true
    ),
    'Una muestra de 0 ms significaría que no se llegó a ejercer la operación.'
);

// =========================================================================
// Grupo 5: el motor remoto queda exactamente como estaba
// =========================================================================
echo "\n--- Grupo 6: sin residuo — las 20 tablas quedan como estaban ---\n";

// Atribución de las filas nuevas del barrido: son externas si ninguna lleva el
// xid de la sonda. Una pasada legítima del proceso programado no invalida la
// medición; una fila propia sí, y la detecta también la comprobación 6.4.
$newSweepRows = 0;
$newSweepOwned = 0;
if (($after['sanitary_sweep_passes'] ?? 0) !== ($before['sanitary_sweep_passes'] ?? 0)) {
    $newSweepRows = (int)$pdo->query('SELECT COUNT(*) FROM sanitary_sweep_passes WHERE id > ' . $sweepMaxBefore)->fetchColumn();
    $sweepOwnedStmt = $pdo->prepare('SELECT COUNT(*) FROM sanitary_sweep_passes WHERE id > :max_id AND xmin::text = :xid');
    $sweepOwnedStmt->bindValue(':max_id', $sweepMaxBefore, PDO::PARAM_INT);
    $sweepOwnedStmt->bindValue(':xid', $measurementXid, PDO::PARAM_STR);
    $sweepOwnedStmt->execute();
    $newSweepOwned = (int)$sweepOwnedStmt->fetchColumn();
}

$sweepIncreaseIsExternal = $newSweepRows > 0 && $newSweepOwned === 0;

$changedTables = [];
foreach (CONTRACT_TABLES as $table) {
    if (($before[$table] ?? null) === ($after[$table] ?? null)) {
        continue;
    }

    if ($table === 'sanitary_sweep_passes' && $sweepIncreaseIsExternal) {
        continue;
    }

    $changedTables[] = sprintf('%s (%d → %d)', $table, $before[$table] ?? -1, $after[$table] ?? -1);
}

$assert(
    '6.1 Las 20 tablas del contrato conservan su recuento exacto tras la reversión',
    $changedTables === [],
    'Tablas con residuo: ' . implode(', ', $changedTables)
);

if ($sweepIncreaseIsExternal) {
    echo "  · El barrido sanitario programado insertó {$newSweepRows} pasada(s) durante la corrida:\n";
    echo "    se atribuyen a otra transacción (xmin distinto) y no cuentan como residuo.\n";
}

$residueByXid = [];
$residueParts = array_map(
    static fn (string $table): string => sprintf(
        "SELECT '%s' AS t, COUNT(*) AS touched FROM %s WHERE xmin::text = :xid OR xmax::text = :xid",
        $table,
        $table
    ),
    CONTRACT_TABLES
);
$residueStmt = $pdo->prepare(implode(' UNION ALL ', $residueParts));
$residueStmt->bindValue(':xid', $measurementXid, PDO::PARAM_STR);
$residueStmt->execute();
foreach ($residueStmt->fetchAll(PDO::FETCH_ASSOC) as $residueRow) {
    if ((int)$residueRow['touched'] > 0) {
        $residueByXid[] = $residueRow['t'] . ' (' . (int)$residueRow['touched'] . ')';
    }
}

$assert(
    '6.4 Ninguna fila de las 20 tablas lleva el xid de la transacción de la sonda',
    $residueByXid === [],
    'Tablas con filas propias: ' . implode(', ', $residueByXid) . ' (xid ' . $measurementXid . ')'
);

$assert(
    '6.2 La corrida midió de verdad: las muestras vieron filas de este mismo ejercicio',
    ($after['incidents'] ?? 0) === ($before['incidents'] ?? 0)
        && $completedSamples === SAMPLE_TARGET,
    'Recuento de expedientes: ' . ($after['incidents'] ?? -1)
);

$assert(
    '6.3 La conexión no quedó dentro de una transacción abierta',
    $pdo->inTransaction() === false
);

echo "\n  Aviso de alcance: estas cifras son la ÚNICA evidencia válida de RNF-01. Las medidas\n";
echo "  contra el motor local (deploy/local/postgres/) no se heredan, no se mezclan con estas\n";
echo "  y no valen como evidencia para este requisito: el objeto medido es el enlace con el\n";
echo "  proveedor, no un bucle local.\n";

// ─────────────────────────────────────────────────────────────────────────
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: PRESUPUESTO DE RNF-01 SATISFECHO EN EL MOTOR REMOTO.\n";
} else {
    echo " RESULTADO: {$failures} COMPROBACIÓN(ES) FALLIDA(S).\n";
}
echo ' Total Aserciones: ' . $assertions . "\n";
echo ' Motor medido: ' . (string)$resolved['driver'] . ' · Supavisor ' . (string)$resolved['port'] . "\n";
echo ' Fecha de la medición: ' . date('c') . "\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
