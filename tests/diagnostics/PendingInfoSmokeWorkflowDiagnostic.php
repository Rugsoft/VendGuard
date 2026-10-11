<?php

declare(strict_types=1);

/**
 * VendGuard — Sonda exploratoria del módulo 11 (RF-01 … RF-06, RNF-01 … RNF-06)
 *
 * Ejecuta tres bloques sobre el sistema REAL (enrutador y middlewares de verdad contra
 * MariaDB, sin dobles):
 *
 *   A. **Humo:** el servidor de desarrollo responde, los assets del módulo se sirven con
 *      su MIME y ninguna respuesta de la superficie del módulo devuelve un 5xx.
 *   B. **Funcionales:** ramas de la especificación sin cobertura previa — origen
 *      `REOPENED`, técnico ocupado en otra parada, reactivación sin técnico asignado,
 *      comentario interno que NO debe reactivar, resolución prohibida desde
 *      `PENDING_INFO`, doble reanudación sin duplicar rastro y el descuento de pausas
 *      en el MTTR (RF-03.1).
 *   C. **Flujo de trabajo completo:** reporte de sede ➔ triaje y asignación ➔ pausa del
 *      técnico ➔ banner de sede ➔ reactivación automática ➔ resolución ➔ reapertura ➔
 *      pausa desde `REOPENED` ➔ 72 h hábiles sin respuesta ➔ cancelación por inactividad ➔
 *      máquina fuera de servicio ➔ aviso nuevo con confirmación de acceso ➔ hilo sellado ➔
 *      seguimiento ciudadano neutral, cerrando con las invariantes de base de datos.
 *
 * **Por qué vive en `tests/diagnostics/` y no en la batería.** `tests/run_all.php` descubre
 * sus suites con glob sobre `tests/unit`, `tests/integration` y `tests/Manual`; una suite
 * más ahí mueve los totales y `DocMetricsGuard` exige sincronizar la frase canónica. Esta
 * sonda es exploratoria (además necesita un servidor HTTP vivo para el bloque de humo), así
 * que se mantiene fuera del recuento canónico hasta que se promueva con sus cifras ya
 * sincronizadas. Cumple igualmente el contrato de limpieza: todo expediente que crea se
 * borra por `TestDataCleaner`, nunca con un `DELETE` ingenuo.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

$bold = "\033[1m";
$green = "\033[32m";
$red = "\033[31m";
$amber = "\033[33m";
$cyan = "\033[36m";
$reset = "\033[0m";

echo "{$bold}======================================================================{$reset}\n";
echo "{$bold} VendGuard: Sonda de humo, funcionales y flujo del módulo 11 (PENDING_INFO){$reset}\n";
echo "{$bold}======================================================================{$reset}\n\n";

// ─── Arnés ──────────────────────────────────────────────────────────────────────
$assertions = 0;
$failures = 0;
$observations = [];
$skips = 0;

$assert = function (string $title, bool $condition, string $detail = '') use (&$assertions, &$failures, $green, $red, $reset): void {
    $assertions++;
    if ($condition) {
        echo "  {$green}[PASS]{$reset} {$title}\n";
        return;
    }

    echo "  {$red}[FAIL]{$reset} {$title}\n";
    if ($detail !== '') {
        echo "         Motivo: {$detail}\n";
    }
    $failures++;
};

/** Hallazgo informativo: no es un fallo de requisito, pero merece figurar en el informe. */
$observe = function (string $title, string $detail) use (&$observations, $amber, $reset): void {
    $observations[] = $title;
    echo "  {$amber}[OBS]{$reset} {$title}\n         {$detail}\n";
};

$skip = function (string $title, string $reason) use (&$skips, $amber, $reset): void {
    $skips++;
    echo "  {$amber}[SKIP]{$reset} {$title}\n         {$reason}\n";
};

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$router = AppRouter::create();
$incidentRepo = new PdoIncidentRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$authService = new AuthService($locationRepo, $userRepo);

/** @var User $coordinator */
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$tech = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$otherTech = $userRepo->findByEmail('marta.ruta@vendguard.internal');

if (!$coordinator instanceof User || !$tech instanceof User || !$otherTech instanceof User) {
    echo "ERROR FATAL: la semilla no expone coordinador y dos técnicos.\n";
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$techToken = $authService->generateInternalToken($tech);
$otherTechToken = $authService->generateInternalToken($otherTech);
$techId = (int)$tech->getId();
$otherTechId = (int)$otherTech->getId();
$coordinatorId = (int)$coordinator->getId();

$api = static fn (string $method, string $path, array $body = [], ?string $token = null, array $query = []) => $router->dispatch(
    new Request(
        method: $method,
        path: $path,
        queryParams: $query,
        parsedBody: $body,
        headers: $token !== null ? ['Authorization' => 'Bearer ' . $token] : []
    )
);

// El seeder deja una avería viva sobre una máquina sembrada y otras suites pueden haber
// dejado más. La sonda libera el tablero UNA vez, antes de crear nada propio: así la
// selección nunca tropieza con un expediente ajeno ni corre el riesgo de borrar uno suyo
// mientras está en juego. Solo se retiran las averías VIVAS; el historial resuelto y las
// muestras de métricas se conservan intactos.
foreach ($pdo->query("SELECT `id` FROM `incidents` WHERE `deleted_at` IS NULL AND `status` NOT IN ('CLOSED', 'CANCELLED')")->fetchAll(PDO::FETCH_COLUMN) as $liveId) {
    TestDataCleaner::purgeIncident($pdo, (int)$liveId);
}

// Un parque con máquinas bloqueadas por falta de acceso (RF-04.4) intercepta cualquier
// aviso nuevo con la casilla de RF-04.6 y falsearía las premisas del banco. La sonda lo
// deja en el estado de partida del protocolo: ninguna máquina bloqueada sin avería viva.
//
// «Fuera de servicio» son DOS marcas, no una: `blockForNoAccess()` pone
// `is_blocked_no_access = 1` y ADEMÁS `is_active = 0`, y la máquina no vuelve sola a
// «Operativa». Deshacer solo el bloqueo deja el parque cojo en silencio —una sede
// sembrada con dos máquinas se queda con una— y todas las suites que exigen dos máquinas
// activas en la misma sede caen por culpa del banco, no del módulo. Se restauran las dos
// marcas. `deleted_at IS NULL` distingue esta avería operativa de la baja administrativa
// deliberada, que sí sella `deleted_at` y no se toca.
$pdo->exec(
    "UPDATE `machines` m SET m.`is_blocked_no_access` = 0, m.`is_active` = 1
      WHERE m.`is_blocked_no_access` = 1
        AND m.`deleted_at` IS NULL
        AND NOT EXISTS (
            SELECT 1 FROM `incidents` i
             WHERE i.`machine_id` = m.`id`
               AND i.`deleted_at` IS NULL
               AND i.`status` NOT IN ('CLOSED', 'CANCELLED')
        )"
);

/**
 * Elige una máquina libre.
 *
 * `$needsPortal` exige que la sede tenga clave de centro sembrada: sin ella el portal
 * responde 401 y la premisa del bloque (una respuesta de sede de verdad) no se sostiene.
 * Una sonda que falla por su propio banco mide el banco, no el sistema, así que la
 * restricción es explícita en lugar de silenciosa.
 *
 * `'AUTO'` recorre las tipologías por orden de conveniencia —primero las que no arrastran
 * vigilancia sanitaria, para no mezclar el reloj biológico con el contractual— y devuelve
 * la primera con hueco: el parque sembrado no siempre expone todas.
 */
$freeMachine = function (string $machineType, bool $needsPortal) use ($pdo): array {
    $portalClause = $needsPortal ? "AND l.`access_code_hash` IS NOT NULL" : '';
    $types = $machineType === 'AUTO'
        ? ['HOT_DRINKS', 'COLD_DRINKS', 'SNACKS', 'PERISHABLE_FOOD', 'COMBO']
        : [$machineType];

    foreach ($types as $candidateType) {
        $machine = $pdo->query(sprintf(
            "SELECT m.`id` AS machine_id, m.`location_id` AS location_id, l.`site_code` AS site_code
               FROM `machines` m
               INNER JOIN `locations` l ON l.`id` = m.`location_id`
              WHERE m.`is_active` = 1
                AND m.`machine_type` = '%s'
                %s
                AND NOT EXISTS (
                    SELECT 1 FROM `incidents` i
                     WHERE i.`machine_id` = m.`id`
                       AND i.`deleted_at` IS NULL
                       AND i.`status` NOT IN ('CLOSED', 'CANCELLED')
                )
              ORDER BY m.`id` ASC LIMIT 1",
            $candidateType,
            $portalClause
        ))->fetch(PDO::FETCH_ASSOC);

        if ($machine !== false) {
            return $machine;
        }
    }

    throw new RuntimeException(
        "Sin máquina libre de tipo {$machineType}" . ($needsPortal ? ' con clave de sede sembrada' : '') . ' para el banco de pruebas.'
    );
};

$createTicket = function (?int $technicianId, string $machineType = 'AUTO', bool $needsPortal = false) use ($freeMachine, $incidentRepo, $coordinatorId): array {
    static $sequence = 0;
    $sequence++;

    $machine = $freeMachine($machineType, $needsPortal);

    $ticketCode = sprintf('DIAG11-%s-%02d', date('His'), $sequence);
    $created = $incidentRepo->create(new Incident(
        id: null,
        ticketCode: $ticketCode,
        machineId: (int)$machine['machine_id'],
        locationId: (int)$machine['location_id'],
        category: IncidentCategory::ELECTRICAL_OFF,
        description: 'Expediente de la sonda de humo, funcionales y flujo del módulo 11.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));

    if ($technicianId !== null) {
        $incidentRepo->assign((int)$created->getId(), $technicianId, $coordinatorId);
    }

    return [
        'id'        => (int)$created->getId(),
        'ticket'    => $ticketCode,
        'site_code' => (string)$machine['site_code'],
        'machine_id' => (int)$machine['machine_id'],
    ];
};

/** Devuelve el token de sede del portal (dos credenciales: código de sede + clave de centro). */
$siteToken = function (string $siteCode) use ($api): ?string {
    $res = $api('POST', '/api/auth/site-login', [
        'site_code'   => $siteCode,
        'access_code' => SeedRunner::devAccessCode($siteCode),
    ]);

    return $res->getStatusCode() === 200 ? ($res->getDecodedBody()['data']['token'] ?? null) : null;
};

$row = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM `incidents` WHERE `id` = :id');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$history = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM `incident_history` WHERE `incident_id` = :id ORDER BY `id` ASC');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$pauseTicket = static fn (int $id, string $category, string $text, string $token) => $api(
    'POST',
    "/api/technician/incidents/{$id}/pause-pending-info",
    ['reason_category' => $category, 'reason_text' => $text],
    $token
);

$resumeTicket = static fn (int $id, string $token) => $api(
    'POST',
    "/api/technician/incidents/{$id}/resume-pending-info",
    [],
    $token
);

$siteComment = static function (string $ticket, string $text, ?string $token) use ($api) {
    if ($token === null) {
        return null;
    }

    return $api('POST', "/api/incidents/{$ticket}/comments", ['comment_text' => $text], $token);
};

/** Libera las máquinas del banco borrando SOLO los expedientes de la sonda. */
$cleanProbe = static function () use ($pdo): void {
    TestDataCleaner::purgeIncidentsMatchingTicket($pdo, 'DIAG11-%');
};

$reason = 'El edificio de consultas externas está cerrado por festivo local; conserjería sin personal.';

echo "{$cyan}--- Bloque A: pruebas de humo (servidor vivo, assets y superficie del módulo) ---{$reset}\n";

// ─── A.1 Servidor de desarrollo vivo ────────────────────────────────────────────
$liveBase = null;
$liveProcess = null;
$liveBasePorts = [8000, 8099];

$liveGet = static function (string $path, string $base): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HEADER => true,
    ]);
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return ['status' => $status, 'type' => $type, 'body' => substr($raw, $headerSize)];
};

foreach ($liveBasePorts as $port) {
    $socket = @fsockopen('127.0.0.1', $port, $errNo, $errStr, 0.4);
    if (is_resource($socket)) {
        fclose($socket);
        $liveBase = "http://127.0.0.1:{$port}";
        break;
    }
}

if ($liveBase === null) {
    $publicDir = realpath(__DIR__ . '/../../public');
    $nullDevice = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
    $cmd = 'php -S 127.0.0.1:8099 -t "' . $publicDir . '" "' . $publicDir . DIRECTORY_SEPARATOR . 'index.php"';
    $liveProcess = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $nullDevice, 'w'], 2 => ['file', $nullDevice, 'w']], $pipes);
    if (is_resource($liveProcess)) {
        usleep(700000);
        $socket = @fsockopen('127.0.0.1', 8099, $errNo, $errStr, 1.0);
        if (is_resource($socket)) {
            fclose($socket);
            $liveBase = 'http://127.0.0.1:8099';
        }
    }
}

$assert('A.1 El servidor de desarrollo responde en HTTP (no se asume: se comprueba el socket)', $liveBase !== null, 'ningún puerto de sonda quedó a la escucha');

if ($liveBase !== null) {
    $root = $liveGet('/', $liveBase);
    $assert(
        'A.2 El servidor vivo se identifica como la API de VendGuard (200 y envoltura JSON propia)',
        $root['status'] === 200 && str_contains($root['body'], 'VendGuard API') && str_contains(strtolower($root['type']), 'json'),
        "status={$root['status']} type={$root['type']}"
    );

    $landing = $liveGet('/index.html', $liveBase);
    $assert(
        'A.2.b La portada estática se sirve con 200 y contenido HTML',
        $landing['status'] === 200 && str_contains(strtolower($landing['type']), 'html'),
        "status={$landing['status']} type={$landing['type']}"
    );

    foreach ([
        '/assets/js/components/PendingInfoPauseModal.js',
        '/assets/js/components/IncidentCommentThreadModal.js',
        '/assets/js/views/LocationPortalView.js',
        '/assets/js/views/TechnicianRouteView.js',
    ] as $asset) {
        $res = $liveGet($asset, $liveBase);
        $assert(
            'A.3 Asset del módulo servido con 200 y MIME JavaScript: ' . basename($asset),
            $res['status'] === 200 && str_contains(strtolower($res['type']), 'javascript'),
            "status={$res['status']} type={$res['type']}"
        );
    }

    $unauthenticated = $liveGet('/api/coordinator/incidents', $liveBase);
    $assert('A.4 El front controller encamina la API sin sesión con 401 (no 404 ni 500)', $unauthenticated['status'] === 401, "status={$unauthenticated['status']}");
}

// ─── A.5 Superficie completa del módulo: ningún 5xx ────────────────────────────
$moduleSurfaces = [
    ['POST', '/api/technician/incidents/1/pause-pending-info', null],
    ['POST', '/api/technician/incidents/1/resume-pending-info', null],
    ['POST', '/api/coordinator/incidents/1/pause-pending-info', null],
    ['POST', '/api/coordinator/incidents/1/resume-pending-info', null],
    ['POST', '/api/coordinator/incidents/1/cancel-inactivity', null],
    ['GET', '/api/technician/my-route', null],
    ['GET', '/api/coordinator/incidents', null],
    ['GET', '/api/coordinator/metrics/summary', null],
    ['GET', '/api/coordinator/metrics/breakdown', null],
    ['GET', '/api/coordinator/incidents/1/detail', null],
    ['GET', '/api/locations/SEDE-BCN-01/machines', null],
];

$surfaceStatuses = [];
$fiveHundreds = [];
foreach ($moduleSurfaces as [$method, $path, $token]) {
    $res = $api($method, $path, [], $token);
    $status = $res->getStatusCode();
    $surfaceStatuses[] = "{$method} {$path} => {$status}";
    if ($status >= 500) {
        $fiveHundreds[] = "{$method} {$path} => {$status}";
    }
}

$assert(
    'A.5 Las 11 rutas del módulo sin cuerpo válido responden con un código documentado y ninguna devuelve 5xx',
    $fiveHundreds === [],
    implode(' | ', $fiveHundreds)
);

$assert(
    'A.6 Toda la superficie sin sesión se cierra con 401 (el módulo no filtra trabajo sin auth)',
    count(array_filter(array_map(
        static fn (array $surface): int => $api($surface[0], $surface[1], [], null)->getStatusCode(),
        $moduleSurfaces
    ), static fn (int $status): bool => $status === 401)) === count($moduleSurfaces),
    implode(' | ', $surfaceStatuses)
);

// ─────────────────────────────────────────────────────────────────────────────────
// BLOQUE B — Funcionales: ramas sin cobertura previa
// ─────────────────────────────────────────────────────────────────────────────────
echo "\n{$cyan}--- Bloque B: funcionales (ramas no cubiertas por la batería) ---{$reset}\n";

// B.1 RF-02.1 — El técnico ya inició otra parada: la respuesta en caliente NO puede
//             devolver el expediente a EN CURSO, porque nadie está delante del equipo.
$busyPaused = $createTicket($techId, 'AUTO', true);
$busyOther = $createTicket($techId);
$busySite = $siteToken($busyPaused['site_code']);

$res = $pauseTicket($busyPaused['id'], 'BUILDING_CLOSED_NO_ACCESS', $reason, $techToken);
$assert('B.1.1 Premisa: el expediente queda pausado por el técnico asignado', $res->getStatusCode() === 200 && ($row($busyPaused['id'])['status'] ?? '') === 'PENDING_INFO');

$res = $api('PATCH', "/api/technician/incidents/{$busyOther['id']}/start", [], $techToken);
$assert('B.1.2 Premisa: el mismo técnico arranca otra intervención de su jornada', $res->getStatusCode() === 200 && ($row($busyOther['id'])['status'] ?? '') === 'IN_PROGRESS');

$res = $busySite !== null ? $siteComment($busyPaused['ticket'], 'La sala ya está abierta, puede pasar cuando quiera.', $busySite) : null;
$busyBody = $res?->getDecodedBody() ?? [];
$assert(
    'B.1.3 RF-02.1: con el técnico ocupado en otra parada y respuesta en caliente, el retorno es ASIGNADA (no EN CURSO)',
    ($busyBody['data']['pause']['status'] ?? $busyBody['data']['incident']['status'] ?? '') === 'ASSIGNED',
    'estado observado: ' . ($busyBody['data']['pause']['status'] ?? $busyBody['data']['incident']['status'] ?? 'n/a')
);
$assert(
    'B.1.4 RF-02.1: la parada queda cerrada y el expediente ya no figura en pausa',
    ($row($busyPaused['id'])['status'] ?? '') === 'ASSIGNED' && ($row($busyPaused['id'])['paused_at'] ?? null) === null
);
$cleanProbe();

// B.2 §6.4 — Reanudación sin técnico asignado: reingresa a REGISTERED (cola de asignación)
//             y nunca declara EN CURSO ni ASIGNADA (la ficción que cerró F-5).
$orphan = $createTicket($techId, 'AUTO', true);
$orphanSite = $siteToken($orphan['site_code']);
$pauseTicket($orphan['id'], 'PENDING_SITE_AUTHORIZATION', $reason, $techToken);
// Intervalo real de cinco minutos: una pausa de milisegundos no permite comprobar que el
// acumulador recibe el intervalo cerrado.
$pdo->exec('UPDATE `incidents` SET `paused_at` = DATE_SUB(NOW(), INTERVAL 300 SECOND) WHERE `id` = ' . $orphan['id']);
$pdo->exec('UPDATE `incidents` SET `assigned_technician_id` = NULL WHERE `id` = ' . $orphan['id']);
$res = $orphanSite !== null ? $siteComment($orphan['ticket'], 'Aportamos la autorización firmada para entrar a la sala técnica.', $orphanSite) : null;
$orphanStatus = $row($orphan['id'])['status'] ?? '';

$assert(
    'B.2.1 §6.4: sin técnico responsable la reanudación reingresa a REGISTERED (ni EN CURSO ni ASIGNADA)',
    $orphanStatus === 'REGISTERED',
    "estado observado: {$orphanStatus}"
);
$assert(
    'B.2.2 §6.4: la pausa se cierra (paused_at nulo) y el intervalo de cinco minutos queda contabilizado',
    ($row($orphan['id'])['paused_at'] ?? null) === null && (int)($row($orphan['id'])['total_pending_info_seconds'] ?? 0) >= 290,
    'paused_at=' . json_encode($row($orphan['id'])['paused_at'] ?? null) . ' acumulado=' . ($row($orphan['id'])['total_pending_info_seconds'] ?? 'n/a')
);
$orphanRow = $row($orphan['id']);
$orphanResumeHistory = array_values(array_filter(
    $history($orphan['id']),
    static fn (array $row): bool => ($row['from_status'] ?? '') === 'PENDING_INFO'
        && ($row['to_status'] ?? '') === 'REGISTERED'
));
$assert(
    'B.2.3 Coherencia de estado (cierre de F-5): el rastro sella PENDING_INFO -> REGISTERED, declara la causa y no inventa responsable',
    count($orphanResumeHistory) === 1
        && str_contains((string)($orphanResumeHistory[0]['action_note'] ?? ''), 'sin técnico responsable')
        && array_key_exists('assigned_technician_id', $orphanRow)
        && $orphanRow['assigned_technician_id'] === null,
    'estado=' . $orphanStatus . ' técnico=' . json_encode($orphanRow['assigned_technician_id'] ?? null)
);
$cleanProbe();

// B.3 §6.5 — El comentario interno de coordinación NO reactiva la avería.
$internal = $createTicket($techId);
$pauseTicket($internal['id'], 'EXTERNAL_POWER_CUT', $reason, $techToken);
$beforeInternal = $row($internal['id']);
$res = $api('POST', "/api/coordinator/incidents/{$internal['id']}/comments", [
    'comment_text' => 'Nota interna de taller: se solicita al fabricante el esquema del cuadro eléctrico.',
    'is_internal'  => true,
], $coordinatorToken);
$afterInternal = $row($internal['id']);

$assert('B.3.1 §6.5: la nota interna se publica (200/201)', in_array($res->getStatusCode(), [200, 201], true), "status={$res->getStatusCode()}");
$assert(
    'B.3.2 §6.5: la avería PERMANECE en pausa tras la nota interna (solo la sede reactiva)',
    ($afterInternal['status'] ?? '') === 'PENDING_INFO' && $afterInternal['paused_at'] === $beforeInternal['paused_at'],
    'estado observado: ' . ($afterInternal['status'] ?? 'n/a')
);
$cleanProbe();

// B.4 RF-06.2 — Resolver sin reanudar está prohibido también por HTTP.
$noResolve = $createTicket($techId);
$pauseTicket($noResolve['id'], 'MACHINE_LOCATION_NOT_FOUND', $reason, $techToken);
$res = $api('POST', "/api/technician/incidents/{$noResolve['id']}/resolve", [
    'resolution_diagnosis' => 'Se localizó la máquina reubicada en la planta segunda ala este del edificio.',
    'resolution_action'    => 'Se restablece el suministro en el cuadro de la sala técnica y se prueba el equipo.',
], $techToken);
$afterNoResolve = $row($noResolve['id']);

$assert('B.4.1 RF-06.2: resolver desde PENDING_INFO se rechaza (4xx), no se ejecuta', $res->getStatusCode() >= 400 && $res->getStatusCode() < 500, "status={$res->getStatusCode()}");
$assert(
    'B.4.2 RF-06.2: el rechazo deja el expediente en pausa, sin RESOLVED ni resolved_at',
    ($afterNoResolve['status'] ?? '') === 'PENDING_INFO' && $afterNoResolve['resolved_at'] === null
);
$cleanProbe();

// B.5 RF-02.4 / RNF-05 — Dos reanudaciones: la segunda no duplica transición ni rastro.
$double = $createTicket($techId);
$pauseTicket($double['id'], 'BUILDING_CLOSED_NO_ACCESS', $reason, $techToken);
$res = $resumeTicket($double['id'], $techToken);
$assert('B.5.1 Premisa: la reanudación manual del técnico devuelve 200 EN CURSO', $res->getStatusCode() === 200 && ($row($double['id'])['status'] ?? '') === 'IN_PROGRESS');
$historyAfterFirst = count($history($double['id']));
$totalAfterFirst = (int)($row($double['id'])['total_pending_info_seconds'] ?? 0);

$second = $api('POST', "/api/coordinator/incidents/{$double['id']}/resume-pending-info", ['target_status' => 'IN_PROGRESS'], $coordinatorToken);
$assert('B.5.2 RF-02.4: la segunda reanudación se rechaza (4xx)', $second->getStatusCode() >= 400 && $second->getStatusCode() < 500, "status={$second->getStatusCode()}");
$assert(
    'B.5.3 RNF-05: el rechazo no añade filas al historial ni altera el acumulador de pausas',
    count($history($double['id'])) === $historyAfterFirst && (int)($row($double['id'])['total_pending_info_seconds'] ?? 0) === $totalAfterFirst
);
$cleanProbe();

// B.6 RF-03.1 — El MTTR de la ruta descuenta la pausa imputable a la sede.
$metricsTechId = (int)$pdo->query(
    "SELECT u.`id` FROM `users` u
      WHERE u.`role` = 'TECHNICIAN' AND u.`deleted_at` IS NULL
        AND NOT EXISTS (
            SELECT 1 FROM `incidents` i
             WHERE i.`assigned_technician_id` = u.`id`
               AND i.`resolved_at` >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
        )
      ORDER BY u.`id` LIMIT 1"
)->fetchColumn();

$effectiveMttrTechId = $metricsTechId > 0 ? $metricsTechId : $techId;
$effectiveMttrTech = $userRepo->findById($effectiveMttrTechId);
$effectiveMttrToken = $effectiveMttrTechId === $techId
    ? $techToken
    : $authService->generateInternalToken($effectiveMttrTech);

$mttrTicket = $createTicket($effectiveMttrTechId);
$pauseTicket($mttrTicket['id'], 'EXTERNAL_POWER_CUT', $reason, $effectiveMttrToken);

// Pausa de una hora real y vida bruta de tres horas.
$pdo->exec("UPDATE `incidents` SET `paused_at` = DATE_SUB(NOW(), INTERVAL 3600 SECOND), `created_at` = DATE_SUB(NOW(), INTERVAL 10800 SECOND) WHERE `id` = " . $mttrTicket['id']);
$api('POST', "/api/technician/incidents/{$mttrTicket['id']}/resume-pending-info", [], $effectiveMttrToken);
$api('POST', "/api/technician/incidents/{$mttrTicket['id']}/resolve", [
    'resolution_diagnosis' => 'El cuadro eléctrico de la sala general disparó por sobretensión en la acometida del inmueble.',
    'resolution_action'    => 'Se restablece la protección diferencial y se verifica el ciclo de refrigeración de la máquina.',
], $effectiveMttrToken);

$mttrRow = $row($mttrTicket['id']);
$pauseSeconds = (int)$mttrRow['total_pending_info_seconds'];
$ownGross = (int)$pdo->query("SELECT TIMESTAMPDIFF(SECOND, `created_at`, `resolved_at`) FROM `incidents` WHERE `id` = {$mttrTicket['id']}")->fetchColumn();

// La esperanza se replica con la MISMA forma de la consulta analítica (mismo técnico y
// misma ventana de 30 días), de modo que la comparación es exacta aunque la muestra
// agregada incluya expedientes sembrados.
$zone = new DateTimeZone('Europe/Madrid');
$windowFrom = (new DateTimeImmutable('now', $zone))->modify('-29 days')->setTime(0, 0, 0);
$windowTo = (new DateTimeImmutable('now', $zone))->setTime(23, 59, 59);
$scope = $pdo->prepare(
    "SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND, i.`created_at`, i.`resolved_at`))) AS gross,
            ROUND(AVG(i.`total_pending_info_seconds`)) AS pause,
            COUNT(*) AS n
       FROM `incidents` i
      WHERE i.`resolved_at` IS NOT NULL
        AND i.`status` IN ('RESOLVED', 'CLOSED')
        AND i.`resolved_at` >= :from_date
        AND i.`resolved_at` <= :to_date
        AND i.`assigned_technician_id` = :tech"
);
$scope->execute([
    ':from_date' => $windowFrom->format('Y-m-d H:i:s'),
    ':to_date'   => $windowTo->format('Y-m-d H:i:s'),
    ':tech'      => $effectiveMttrTechId,
]);
$scopeRow = $scope->fetch(PDO::FETCH_ASSOC) ?: [];
$scopeGross = (int)($scopeRow['gross'] ?? 0);
$scopePause = (int)($scopeRow['pause'] ?? 0);
$sampleSize = (int)($scopeRow['n'] ?? 0);
$expectedNetMinutes = (int)round(max(0, $scopeGross - $scopePause) / 60);
$undeductedMinutes = (int)round(max(0, $scopeGross) / 60);

$breakdown = $api('GET', '/api/coordinator/metrics/breakdown', [], $coordinatorToken);
$rows = $breakdown->getDecodedBody()['data']['by_technician'] ?? [];
$mttrRowApi = null;
foreach ($rows as $candidate) {
    if ((int)($candidate['technician_id'] ?? 0) === $effectiveMttrTechId) {
        $mttrRowApi = $candidate;
        break;
    }
}

$assert(
    'B.6.1 RF-03.1: la pausa queda cerrada y acumulada al segundo en el expediente',
    $pauseSeconds >= 3590 && $pauseSeconds <= 3700 && ($mttrRow['status'] ?? '') === 'RESOLVED',
    "segundos acumulados={$pauseSeconds} estado=" . ($mttrRow['status'] ?? 'n/a') . " brutos propios={$ownGross}s"
);
$assert(
    'B.6.2 RF-03.1: el MTTR del desglose es el bruto menos las pausas de sede (descuento material)',
    $mttrRowApi !== null && (int)($mttrRowApi['mttr_minutes'] ?? -1) === $expectedNetMinutes && $expectedNetMinutes !== $undeductedMinutes,
    'esperado=' . $expectedNetMinutes . ' observado=' . ($mttrRowApi['mttr_minutes'] ?? 'n/a')
        . " (sin descontar sería {$undeductedMinutes}) muestra={$sampleSize} brutos={$scopeGross}s pausas={$scopePause}s"
);
$cleanProbe();

// ─────────────────────────────────────────────────────────────────────────────────
// BLOQUE C — Flujo de trabajo de extremo a extremo con los tres perfiles
// ─────────────────────────────────────────────────────────────────────────────────
echo "\n{$cyan}--- Bloque C: flujo de trabajo completo de extremo a extremo ---{$reset}\n";

$flowMachine = $freeMachine('AUTO', true);
$flowSite = $siteToken($flowMachine['site_code']);
$assert('C.1 Semilla: la sede de la máquina elegida obtiene su token de portal', $flowSite !== null, "sede={$flowMachine['site_code']}");

$created = $api('POST', '/api/incidents', [
    'machine_id'     => $flowMachine['machine_id'],
    'site_code'      => $flowMachine['site_code'],
    'category'       => 'ELECTRICAL_OFF',
    'description'    => 'La máquina se ha quedado sin suministro y la pantalla no enciende desde esta mañana.',
    'reporter_name'  => 'Laura Sede',
    'reporter_phone' => '600111222',
], $flowSite);
$flowTicket = $created->getDecodedBody()['data']['ticket_code'] ?? '';
$flowId = (int)($created->getDecodedBody()['data']['id'] ?? 0);
$flow = $flowMachine;
$assert('C.2 El reporte de sede se registra (201) en estado REGISTRADA', $created->getStatusCode() === 201 && ($row($flowId)['status'] ?? '') === 'REGISTERED', "status={$created->getStatusCode()} estado=" . ($row($flowId)['status'] ?? 'n/a'));

$triage = $api('GET', '/api/coordinator/incidents', [], $coordinatorToken);
$triageRow = null;
foreach ($triage->getDecodedBody()['data'] ?? [] as $candidate) {
    if ((int)($candidate['id'] ?? 0) === $flowId) {
        $triageRow = $candidate;
        break;
    }
}
$assert('C.3 El expediente aparece en la bandeja de triaje sin técnico', $triageRow !== null && empty($triageRow['assigned_technician_id']));

$assigned = $api('PATCH', "/api/coordinator/incidents/{$flowId}/assign", ['technician_id' => $techId], $coordinatorToken);
$assert('C.4 La asignación técnica devuelve 200 ASIGNADA al titular', $assigned->getStatusCode() === 200 && ($row($flowId)['status'] ?? '') === 'ASSIGNED');

$paused = $pauseTicket($flowId, 'BUILDING_CLOSED_NO_ACCESS', $reason, $techToken);
$pausedData = $paused->getDecodedBody()['data'] ?? [];
// El intervalo vivo se retrasa cinco minutos para que el desplazamiento de SLA y el
// acumulador sean medibles (una pausa de milisegundos redondea a cero minutos).
$pdo->exec("UPDATE `incidents` SET `paused_at` = DATE_SUB(NOW(), INTERVAL 300 SECOND) WHERE `id` = {$flowId}");
$assert(
    'C.5 RF-01.2/01.3: la pausa se declara con causa tipificada y reloj congelado',
    $paused->getStatusCode() === 200 && ($pausedData['status'] ?? '') === 'PENDING_INFO' && ($pausedData['is_sla_paused'] ?? false) === true,
    "status={$paused->getStatusCode()} estado=" . ($pausedData['status'] ?? 'n/a')
);

$machines = $api('GET', "/api/locations/{$flow['site_code']}/machines", [], $flowSite);
$portalMachine = null;
foreach ($machines->getDecodedBody()['data'] ?? [] as $candidate) {
    if ((int)($candidate['id'] ?? 0) === $flow['machine_id']) {
        $portalMachine = $candidate;
        break;
    }
}
$portalIncident = $portalMachine['active_incident'] ?? [];
$assert(
    'C.6 RF-05.1: el portal de sede publica el motivo tipificado del bloqueo en la tarjeta de la máquina',
    ($portalIncident['pending_info_reason_category'] ?? '') === 'BUILDING_CLOSED_NO_ACCESS'
        && ($portalIncident['pending_info_reason_category_label'] ?? '') === 'Edificio cerrado / Sin acceso a instalaciones'
        && ($portalIncident['pending_info_reason_text'] ?? '') !== '',
    'bloque observado: ' . json_encode($portalIncident['pending_info_reason_category'] ?? null, JSON_UNESCAPED_UNICODE)
);

$route = $api('GET', '/api/technician/my-route', [], $techToken);
$routeStop = null;
foreach ($route->getDecodedBody()['data'] ?? [] as $candidate) {
    if ((int)($candidate['id'] ?? 0) === $flowId) {
        $routeStop = $candidate;
        break;
    }
}
$assert(
    'C.7 RF-05.5: la parada pausada sigue en Mi Ruta, con su marca temporal y su contador',
    $routeStop !== null && ($routeStop['status'] ?? '') === 'PENDING_INFO' && !empty($routeStop['paused_at'])
        && array_key_exists('total_pending_info_seconds', $routeStop),
    'parada: ' . json_encode($routeStop === null ? null : ['status' => $routeStop['status'], 'paused_at' => $routeStop['paused_at']], JSON_UNESCAPED_UNICODE)
);

$reactivated = $siteComment($flowTicket, 'Ya hemos abierto la sala de contadores; el técnico puede acceder cuando llegue.', $flowSite);
$reactivatedData = $reactivated?->getDecodedBody()['data'] ?? [];
$assert(
    'C.8 RF-02.1: el comentario de sede en caliente reactiva a EN CURSO con auto_resumed',
    $reactivated !== null && in_array($reactivated->getStatusCode(), [200, 201], true)
        && ($reactivatedData['auto_resumed'] ?? false) === true
        && ($reactivatedData['pause']['status'] ?? '') === 'IN_PROGRESS',
    'status=' . ($reactivated?->getStatusCode() ?? 'sin respuesta') . ' cuerpo=' . json_encode($reactivatedData['pause']['status'] ?? null)
);

$detail = $api('GET', "/api/coordinator/incidents/{$flowId}/detail", [], $coordinatorToken);
$detailData = $detail->getDecodedBody()['data'] ?? [];
$detailPause = $detailData['technical_intervention']['pause'] ?? $detailData['technicalIntervention']['pause'] ?? [];
$assert(
    'C.9 RF-03.2/03.3: tras reanudar, la ficha de coordinación descongela el reloj y publica los minutos descontados',
    $detail->getStatusCode() === 200
        && (($detailPause['is_sla_paused'] ?? true) === false)
        && (int)($detailPause['accumulated_pause_minutes'] ?? -1) >= 5,
    'bloque de pausa: ' . json_encode(array_slice($detailPause, 0, 8, true), JSON_UNESCAPED_UNICODE)
);

$resolved = $api('POST', "/api/technician/incidents/{$flowId}/resolve", [
    'resolution_diagnosis' => 'El diferencial de la sala de contadores disparó por una fuga en el circuito de iluminación.',
    'resolution_action'    => 'Se aísla el circuito defectuoso, se repone el suministro y se valida el arranque completo del equipo.',
], $techToken);
$assert('C.10 El técnico cierra con diagnóstico y solución documentados (200 RESUELTA)', $resolved->getStatusCode() === 200 && ($row($flowId)['status'] ?? '') === 'RESOLVED', "status={$resolved->getStatusCode()}");

$reopened = $api('POST', "/api/incidents/{$flowTicket}/reopen", [
    'reopen_reason' => 'La máquina vuelve a apagarse tras dos horas, la avería no está resuelta del todo.',
], $flowSite);
$assert(
    'C.11 La reapertura en garantía devuelve 200 REABIERTA y desasigna al técnico',
    $reopened->getStatusCode() === 200 && ($row($flowId)['status'] ?? '') === 'REOPENED' && $row($flowId)['assigned_technician_id'] === null,
    "status={$reopened->getStatusCode()} estado=" . ($row($flowId)['status'] ?? 'n/a')
);

// La reapertura desasigna al técnico (C.11) y RF-01.1 declara REOPENED como origen legal
// de la pausa, así que la declara coordinación sobre el expediente ya sin responsable: es
// la combinación que la batería no cubría.
$pauseFromReopened = $api('POST', "/api/coordinator/incidents/{$flowId}/pause-pending-info", [
    'reason_category' => 'EXTERNAL_POWER_CUT',
    'reason_text'     => 'El edificio entero está sin luz por una avería de la acometida general del inmueble.',
], $coordinatorToken);
$reopenedHistory = $history($flowId);
$pauseOrigin = null;
foreach ($reopenedHistory as $entry) {
    if (($entry['to_status'] ?? '') === 'PENDING_INFO') {
        $pauseOrigin = (string)$entry['from_status'];
    }
}
$assert(
    'C.12 RF-01.1: una avería reabierta también se pausa (200) y el origen REABIERTA queda auditado',
    $pauseFromReopened->getStatusCode() === 200 && ($row($flowId)['status'] ?? '') === 'PENDING_INFO' && $pauseOrigin === 'REOPENED',
    "status={$pauseFromReopened->getStatusCode()} origen auditado=" . ($pauseOrigin ?? 'n/a')
);

// RF-04.2: se envejece la pausa viva para superar con holgura las 72 h hábiles de silencio.
$pdo->exec("UPDATE `incidents` SET `paused_at` = DATE_SUB(NOW(), INTERVAL 12 DAY) WHERE `id` = {$flowId}");
$triageAfter = $api('GET', '/api/coordinator/incidents', [], $coordinatorToken);
$prolongedRow = null;
foreach ($triageAfter->getDecodedBody()['data'] ?? [] as $candidate) {
    if ((int)($candidate['id'] ?? 0) === $flowId) {
        $prolongedRow = $candidate;
        break;
    }
}
$assert(
    'C.13 RF-04.2: superadas las 72 h hábiles, el triaje enciende la espera prolongada y el reloj congelado',
    $prolongedRow !== null && ($prolongedRow['is_prolonged_inactivity'] ?? false) === true && ($prolongedRow['is_sla_paused'] ?? false) === true,
    'fila: ' . json_encode($prolongedRow === null ? null : ['prolongada' => $prolongedRow['is_prolonged_inactivity'] ?? null, 'congelado' => $prolongedRow['is_sla_paused'] ?? null])
);

$cancelled = $api('POST', "/api/coordinator/incidents/{$flowId}/cancel-inactivity", [
    'cancellation_reason' => 'Cierre administrativo por inactividad y falta de acceso del cliente tras 72 h hábiles sin respuesta.',
], $coordinatorToken);
$flowRow = $row($flowId);
$machineRow = $pdo->query("SELECT `is_blocked_no_access` FROM `machines` WHERE `id` = {$flow['machine_id']}")->fetch(PDO::FETCH_ASSOC);
$assert('C.14 RF-04.3: la cancelación por inactividad se ejecuta (200 CANCELADA)', $cancelled->getStatusCode() === 200 && ($flowRow['status'] ?? '') === 'CANCELLED', "status={$cancelled->getStatusCode()} estado=" . ($flowRow['status'] ?? 'n/a'));
$assert(
    'C.15 RF-04.4: la máquina NO vuelve a Operativa: queda fuera de servicio por falta de acceso',
    (int)($machineRow['is_blocked_no_access'] ?? 0) === 1,
    'is_blocked_no_access=' . json_encode($machineRow['is_blocked_no_access'] ?? null)
);

$portalAfter = $api('GET', "/api/locations/{$flow['site_code']}/machines", [], $flowSite);
$blockedMachine = null;
foreach ($portalAfter->getDecodedBody()['data'] ?? [] as $candidate) {
    if ((int)($candidate['id'] ?? 0) === $flow['machine_id']) {
        $blockedMachine = $candidate;
        break;
    }
}
$assert(
    'C.16 RF-04.4/Art. V.1: el portal de sede muestra la máquina bloqueada, no "Operativa"',
    $blockedMachine !== null && (bool)($blockedMachine['is_blocked_no_access'] ?? false) === true,
    json_encode($blockedMachine === null ? null : $blockedMachine['is_blocked_no_access'])
);

$withoutConfirmation = $api('POST', '/api/incidents', [
    'machine_id'     => $flow['machine_id'],
    'site_code'      => $flow['site_code'],
    'category'       => 'OTHER',
    'description'    => 'La máquina sigue apagada y necesitamos asistencia técnica cuanto antes.',
    'reporter_name'  => 'Laura Sede',
    'reporter_phone' => '600111222',
], $flowSite);
$assert(
    'C.17 RF-04.6: un aviso nuevo sobre la máquina bloqueada se intercepta sin la casilla de acceso (422)',
    $withoutConfirmation->getStatusCode() === 422,
    "status={$withoutConfirmation->getStatusCode()} código=" . json_encode($withoutConfirmation->getDecodedBody()['error']['code'] ?? null)
);

$withConfirmation = $api('POST', '/api/incidents', [
    'machine_id'       => $flow['machine_id'],
    'site_code'        => $flow['site_code'],
    'category'         => 'OTHER',
    'description'      => 'Confirmamos el acceso a la sala: la máquina sigue apagada y pedimos asistencia.',
    'reporter_name'    => 'Laura Sede',
    'reporter_phone'   => '600111222',
    'access_confirmed' => true,
], $flowSite);
$newTicket = $withConfirmation->getDecodedBody()['data']['ticket_code'] ?? null;
$machineAfter = $pdo->query("SELECT `is_blocked_no_access` FROM `machines` WHERE `id` = {$flow['machine_id']}")->fetch(PDO::FETCH_ASSOC);
$assert(
    'C.18 RF-04.6: con la confirmación marcada el aviso se registra (201) y la máquina vuelve al servicio',
    $withConfirmation->getStatusCode() === 201 && (int)($machineAfter['is_blocked_no_access'] ?? 1) === 0,
    "status={$withConfirmation->getStatusCode()} bloqueada=" . json_encode($machineAfter['is_blocked_no_access'] ?? null)
);

$sealedComment = $siteComment($flowTicket, '¿Podemos reabrir esta avería antigua para añadir una nota más?', $flowSite);
$assert(
    'C.19 RF-05.4: el hilo del expediente cancelado queda sellado (403 CONVERSATION_SEALED)',
    $sealedComment !== null && $sealedComment->getStatusCode() === 403
        && ($sealedComment->getDecodedBody()['error']['code'] ?? '') === 'CONVERSATION_SEALED',
    'status=' . ($sealedComment?->getStatusCode() ?? 'sin respuesta') . ' código=' . json_encode($sealedComment?->getDecodedBody()['error']['code'] ?? null)
);

if ($newTicket !== null) {
    $qrMachineCode = (string)$pdo->query("SELECT `code` FROM `machines` WHERE `id` = {$flow['machine_id']}")->fetchColumn();
    $public = $api('GET', '/api/qr/scan/' . $qrMachineCode);
    $publicRaw = json_encode($public->getDecodedBody(), JSON_UNESCAPED_UNICODE) ?: '';
    $assert(
        'C.20 RF-06.1/Art. V.4: el seguimiento ciudadano no filtra el estado de pausa ni los motivos internos',
        $public->getStatusCode() === 200 && !str_contains($publicRaw, 'PENDING_INFO') && !str_contains($publicRaw, 'BUILDING_CLOSED_NO_ACCESS'),
        "status={$public->getStatusCode()} rastro de fuga=" . (str_contains($publicRaw, 'PENDING_INFO') ? 'PENDING_INFO' : (str_contains($publicRaw, 'BUILDING_CLOSED_NO_ACCESS') ? 'causa tipificada' : 'ninguno'))
    );
} else {
    $skip('C.20 RF-06.1: seguimiento ciudadano neutral', 'sin expediente nuevo no hay código QR que consultar');
}

// Invariantes de cierre sobre el expediente que recorrió todo el ciclo.
$flowHistory = $history($flowId);
$transitionsToPause = 0;
foreach ($flowHistory as $entry) {
    if (($entry['to_status'] ?? '') === 'PENDING_INFO') {
        $transitionsToPause++;
    }
}

$assert(
    'C.21 RF-04.1/RNF-05: las dos pausas del ciclo quedan como filas de solo adición en el historial',
    $transitionsToPause === 2,
    "transiciones a pausa observadas={$transitionsToPause}"
);
$assert(
    'C.22 Art. III: el historial conserva todas las transiciones del ciclo completo (>= 8 hitos)',
    count($flowHistory) >= 8,
    'hitos=' . count($flowHistory)
);
$accumulated = (int)($row($flowId)['total_pending_info_seconds'] ?? 0);
$assert(
    'C.23 RF-04.1: el acumulador conserva el intervalo cerrado por la sede (≈300 s), no lo pierde al cancelar',
    $accumulated >= 290,
    "acumulador={$accumulated}s"
);
if ($accumulated >= 100000) {
    $observe(
        'C.24 La cancelación por inactividad suma también el intervalo vivo de doce días',
        'El acumulador incluye la espera abierta (' . $accumulated . ' s). La spec exige no perder los intervalos (RF-04.1) y no se pronuncia sobre el vivo; conviene fijar por escrito si debe sumarse, porque infla el descuento de MTTR con la espera que jamás se respondió.'
    );
}

// ─────────────────────────────────────────────────────────────────────────────────
// BLOQUE D — Segunda oleada: validación hostil y desplazamiento de SLA fuera de jornada
// ─────────────────────────────────────────────────────────────────────────────────
echo "\n{$cyan}--- Bloque D: validación hostil y el desplazamiento de SLA fuera de jornada ---{$reset}\n";

// El ciclo del bloqueo C ya está medido: se liberan sus máquinas para que la segunda
// oleada no herede un expediente vivo en la única máquina con portal de esta base. El
// aviso que creó el portal en C.18 no lleva el prefijo de la sonda (lo emite el sistema),
// así que se retira por su máquina además de por el patrón de tickets.
$cleanProbe();
if (isset($newTicket) && is_string($newTicket) && $newTicket !== '') {
    TestDataCleaner::purgeIncidentByTicket($pdo, $newTicket);
}
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$flowMachine['machine_id']);

// D.1 RF-01.3 — La justificación debe ser TEXTO real, no espacios. La spec excluye
// explícitamente «espacios en blanco superfluos»; 25 espacios no informan de nada.
$blankPause = $createTicket($techId);
$blankAttempt = $pauseTicket($blankPause['id'], 'EXTERNAL_POWER_CUT', str_repeat(' ', 25), $techToken);
$blankRow = $row($blankPause['id']);
$assert(
    'D.1.1 RF-01.3: una justificación de 25 espacios se rechaza con 4xx',
    $blankAttempt->getStatusCode() >= 400 && $blankAttempt->getStatusCode() < 500,
    "status={$blankAttempt->getStatusCode()} código=" . json_encode($blankAttempt->getDecodedBody()['error']['code'] ?? null)
);
$assert(
    'D.1.2 RF-01.3: el rechazo no pausa el expediente ni escribe justificación',
    ($blankRow['status'] ?? '') === 'ASSIGNED' && $blankRow['paused_at'] === null && $blankRow['pending_info_reason_text'] === null
);

$tabsOnly = str_repeat("\t\n ", 10);
$tabAttempt = $pauseTicket($blankPause['id'], 'EXTERNAL_POWER_CUT', $tabsOnly, $techToken);
$assert(
    'D.1.3 RF-01.3: tampoco cuela una justificación de tabuladores y saltos (30 caracteres)',
    $tabAttempt->getStatusCode() >= 400 && $tabAttempt->getStatusCode() < 500 && ($row($blankPause['id'])['status'] ?? '') === 'ASSIGNED',
    "status={$tabAttempt->getStatusCode()}"
);
$cleanProbe();

// D.2 Art. V.4 — Un comentario de OTRA sede sobre el expediente pausado no puede reactivarlo.
$foreign = $createTicket($techId, 'AUTO', true);
$pauseTicket($foreign['id'], 'BUILDING_CLOSED_NO_ACCESS', $reason, $techToken);
$foreignSiteCodes = $pdo->query(
    "SELECT `site_code` FROM `locations`
      WHERE `site_code` LIKE 'SEDE-BCN-%' AND `access_code_hash` IS NOT NULL AND `site_code` <> '" . $foreign['site_code'] . "'
      ORDER BY `site_code` LIMIT 1"
)->fetchColumn();
$foreignToken = $foreignSiteCodes !== false ? $siteToken((string)$foreignSiteCodes) : null;
$foreignAttempt = $foreignToken !== null
    ? $siteComment($foreign['ticket'], 'Aportamos la información que nos piden para desbloquear la avería.', $foreignToken)
    : null;
$assert(
    'D.2.1 Art. V.4: una sede ajena no puede comentar el expediente (403 SITE_MISMATCH)',
    $foreignAttempt !== null && $foreignAttempt->getStatusCode() === 403,
    'status=' . ($foreignAttempt?->getStatusCode() ?? 'sin respuesta') . ' código=' . json_encode($foreignAttempt?->getDecodedBody()['error']['code'] ?? null)
);
$assert(
    'D.2.2 Art. V.4: el intento ajeno deja el expediente en pausa y sin transición alguna',
    ($row($foreign['id'])['status'] ?? '') === 'PENDING_INFO' && $row($foreign['id'])['paused_at'] !== null
);
$cleanProbe();

// D.3 RF-03.2/03.3/RNF-01 — Medición del desplazamiento cuando la pausa atraviesa la
//         noche y el fin de semana, que es el escenario real de RF-01.1 (sede cerrada).
//         Esta es la medición que descubrió F-3 (dilatación de 4,15×) y hoy certifica su
//         cierre: el vencimiento avanza EXACTAMENTE el tiempo hábil perdido en la ventana
//         de la pausa, contado por el calendario independiente del arnés.
$weekend = $createTicket($techId);
$zone = new DateTimeZone('Europe/Madrid');
$fridayDeadline = (new DateTimeImmutable('now', $zone))->modify('next friday')->setTime(17, 0, 0);
$pdo->exec(
    "UPDATE `incidents` SET `sla_target_at` = '" . $fridayDeadline->format('Y-m-d H:i:s') . "' WHERE `id` = " . $weekend['id']
);

$declared = $pauseTicket($weekend['id'], 'BUILDING_CLOSED_NO_ACCESS', $reason, $techToken);
$declaredData = $declared->getDecodedBody()['data'] ?? [];
$pauseSecondsSeeded = 62 * 3600; // De viernes 18:00 a lunes 08:00: dos días y medio de espera real.
$pdo->exec("UPDATE `incidents` SET `paused_at` = DATE_SUB(NOW(), INTERVAL 62 HOUR) WHERE `id` = " . $weekend['id']);
$f3PausedAt = (string)$pdo->query("SELECT `paused_at` FROM `incidents` WHERE `id` = " . $weekend['id'])->fetchColumn();
// Orillas del reloj real: el servicio lee su instante entre ambas (cierre de F-3).
$f3Before = new DateTimeImmutable('now', $zone);
$resumedWeekend = $resumeTicket($weekend['id'], $techToken);
$f3After = new DateTimeImmutable('now', $zone);
$resumedData = $resumedWeekend->getDecodedBody()['data'] ?? [];
$shiftedAt = $resumedData['sla_target_at_shifted'] ?? ($declaredData['sla_target_at_original'] ?? null);
$shiftedMoment = is_string($shiftedAt) ? (new DateTimeImmutable($shiftedAt, $zone)) : null;
$shiftedBusinessDay = $shiftedMoment !== null && (int)$shiftedMoment->format('N') <= 5;
// El cierre de jornada (18:00) es el último segundo hábil del día y se admite como borde
// legal; lo que no se admite es un vencimiento de madrugada ni en fin de semana.
$shiftedSecondOfDay = $shiftedMoment !== null
    ? (int)$shiftedMoment->format('G') * 3600 + (int)$shiftedMoment->format('i') * 60 + (int)$shiftedMoment->format('s')
    : -1;

$assert(
    'D.3.1 RF-01.2/RNF-01: la pausa arranca con el vencimiento comercial intacto como extremo original',
    ($declaredData['sla_target_at_original'] ?? '') === $fridayDeadline->format('Y-m-d H:i:s') && ($declaredData['is_sla_paused'] ?? false) === true,
    'original=' . json_encode($declaredData['sla_target_at_original'] ?? null)
);
$assert(
    'D.3.2 RF-03.3: el vencimiento desplazado cae en día hábil y dentro de la jornada 08:00-18:00 (nunca en horario inhábil)',
    $resumedWeekend->getStatusCode() === 200 && $shiftedMoment !== null
        && $shiftedBusinessDay && $shiftedSecondOfDay >= 28800 && $shiftedSecondOfDay <= 64800,
    'desplazado a=' . ($shiftedAt ?? 'n/a')
);
$assert(
    'D.3.3 RF-03.3: el vencimiento desplazado siempre avanza (nunca retrocede respecto al original)',
    $shiftedMoment !== null && $shiftedMoment->getTimestamp() >= $fridayDeadline->getTimestamp()
);
// Cierre de F-3: el vencimiento avanza el tiempo HÁBIL realmente perdido, nunca la duración
// de pared de la espera. La expectativa la calcula el calendario independiente del arnés
// contando los segundos de jornada que hay entre el vencimiento original y el desplazado,
// que es la comprobación inversa de la que destapó la dilatación de 4,15×.
$f3WallSeconds = (int)($row($weekend['id'])['total_pending_info_seconds'] ?? 0);
$f3BusinessLostMin = BusinessCalendar::secondsBetween(new DateTimeImmutable($f3PausedAt, $zone), $f3Before);
$f3BusinessLostMax = BusinessCalendar::secondsBetween(new DateTimeImmutable($f3PausedAt, $zone), $f3After);
$f3BusinessMoved = $shiftedMoment !== null
    ? BusinessCalendar::secondsBetween($fridayDeadline, $shiftedMoment)
    : -1;

$assert(
    'D.3.4 (cierre de F-3) El vencimiento avanza el tiempo hábil perdido y nunca la duración de pared de la espera',
    $shiftedMoment !== null
        && $f3BusinessMoved >= $f3BusinessLostMin
        && $f3BusinessMoved <= $f3BusinessLostMax
        && $f3BusinessMoved <= $f3WallSeconds,
    sprintf(
        'espera de pared=%d s (%d h), tiempo hábil perdido=[%d..%d] s, vencimiento avanzado=%d s (%.2f× la espera de pared)',
        $f3WallSeconds,
        $pauseSecondsSeeded / 3600,
        $f3BusinessLostMin,
        $f3BusinessLostMax,
        $f3BusinessMoved,
        $f3WallSeconds > 0 ? $f3BusinessMoved / $f3WallSeconds : 0
    )
);
$cleanProbe();

// ─────────────────────────────────────────────────────────────────────────────────
// BLOQUE E — Fronteras: el umbral de 72 h HÁBILES y la ventana de garantía de 48 h
// ─────────────────────────────────────────────────────────────────────────────────
echo "\n{$cyan}--- Bloque E: fronteras de 72 h hábiles y de la garantía de 48 h ---{$reset}\n";

/**
 * Devuelve el instante que queda a `$businessSeconds` segundos HÁBILES del momento actual,
 * caminando hacia atrás por el calendario comercial de la sede (lun-vie, 08:00-18:00).
 *
 * Es la traducción inversa de `countBusinessSecondsBetween()` y permite colocar una pausa
 * justo a un lado y a otro del umbral: si la implementación contase horas de calendario en
 * lugar de horas hábiles, la sonda de 71 h hábiles (~10 días naturales) lo delataría.
 */
$businessSecondsAgo = function (int $businessSeconds) use ($zone): string {
    $cursor = new DateTimeImmutable('now', $zone);
    $remaining = $businessSeconds;

    while ($remaining > 0) {
        $secondOfDay = (int)$cursor->format('G') * 3600 + (int)$cursor->format('i') * 60 + (int)$cursor->format('s');

        if ((int)$cursor->format('N') > 5) {
            $cursor = $cursor->modify('-1 day')->setTime(18, 0, 0);
            continue;
        }

        if ($secondOfDay > 64800) {
            $cursor = $cursor->setTime(18, 0, 0);
            continue;
        }

        if ($secondOfDay < 28800) {
            $cursor = $cursor->modify('-1 day')->setTime(18, 0, 0);
            continue;
        }

        $available = $secondOfDay - 28800;
        if ($remaining <= $available) {
            return $cursor->modify("-{$remaining} seconds")->format('Y-m-d H:i:s');
        }

        $remaining -= $available;
        $cursor = $cursor->modify('-1 day')->setTime(18, 0, 0);
    }

    return $cursor->format('Y-m-d H:i:s');
};

$thresholdProbe = function (int $businessHours) use ($createTicket, $techId, $pauseTicket, $techToken, $reason, $pdo, $businessSecondsAgo, $api, $coordinatorToken, $row): array {
    $ticket = $createTicket($techId);
    $pauseTicket($ticket['id'], 'BUILDING_CLOSED_NO_ACCESS', $reason, $techToken);
    $pausedAt = $businessSecondsAgo($businessHours * 3600);
    $pdo->exec("UPDATE `incidents` SET `paused_at` = '{$pausedAt}' WHERE `id` = " . $ticket['id']);

    $triage = $api('GET', '/api/coordinator/incidents', [], $coordinatorToken);
    $flagged = false;
    foreach ($triage->getDecodedBody()['data'] ?? [] as $candidate) {
        if ((int)($candidate['id'] ?? 0) === $ticket['id']) {
            $flagged = (bool)($candidate['is_prolonged_inactivity'] ?? false);
            break;
        }
    }

    $cancel = $api('POST', "/api/coordinator/incidents/{$ticket['id']}/cancel-inactivity", [
        'cancellation_reason' => 'Intento de cierre administrativo del banco de pruebas de frontera del umbral hábil.',
    ], $coordinatorToken);
    $cancelData = $cancel->getDecodedBody()['data'] ?? [];

    return [
        'flagged' => $flagged,
        'cancel_status' => $cancel->getStatusCode(),
        'cancel_body' => $cancelData,
        'ticket' => $ticket,
        'paused_at' => $pausedAt,
    ];
};

$justBelow = $thresholdProbe(71);
$assert(
    'E.1 RF-04.2: con 71 h HÁBILES de espera el triaje NO enciende la espera prolongada',
    $justBelow['flagged'] === false,
    'paused_at=' . $justBelow['paused_at'] . ' (≈10 días naturales: una cuenta por horas de calendario lo marcaría)'
);
$assert(
    'E.2 RF-04.3: por debajo del umbral la cancelación por inactividad se rechaza sin tocar el expediente',
    $justBelow['cancel_status'] >= 400 && $justBelow['cancel_status'] < 500 && ($row($justBelow['ticket']['id'])['status'] ?? '') === 'PENDING_INFO',
    sprintf(
        'A 71 h HÁBILES (el propio triaje publica is_prolonged_inactivity=false) el protocolo se ejecutó igualmente: status=%d, estado=%s, máquina bloqueada=%s, reintegros conservados=%s. El evento inmutable se firma además como cancellation_channel=INACTIVITY_72_BUSINESS_HOURS.',
        $justBelow['cancel_status'],
        (string)($row($justBelow['ticket']['id'])['status'] ?? 'n/a'),
        json_encode($justBelow['cancel_body']['machine']['is_blocked_no_access'] ?? null),
        json_encode($justBelow['cancel_body']['refunds_preserved'] ?? null)
    )
);
$cleanProbe();

$justAbove = $thresholdProbe(80);
$assert(
    'E.3 RF-04.2: con 80 h HÁBILES la espera prolongada se enciende (control positivo del umbral)',
    $justAbove['flagged'] === true,
    'paused_at=' . $justAbove['paused_at'] . ' flag=' . var_export($justAbove['flagged'], true)
);
$assert(
    'E.4 RF-04.3: superado el umbral la cancelación por inactividad se autoriza (200)',
    $justAbove['cancel_status'] === 200
);
$cleanProbe();

// E.5/E.6 RF-05.4 — La frontera de la garantía de 48 h en el hilo: dentro se comenta,
//            fuera queda sellado. La spec distingue ambas orillas explícitamente.
$windowProbe = function (int $hoursAgo) use ($createTicket, $techId, $incidentRepo, $coordinatorId, $techToken, $pdo, $api, $siteToken, $siteComment) {
    $ticket = $createTicket($techId, 'AUTO', true);
    $incidentRepo->startIntervention($ticket['id'], $techId);
    $incidentRepo->resolve(
        $ticket['id'],
        $techId,
        'Diagnóstico del banco de pruebas de la frontera de garantía del hilo de conversación.',
        'Acción correctiva del banco de pruebas con la longitud mínima exigida por el pliego.'
    );
    $pdo->exec("UPDATE `incidents` SET `resolved_at` = DATE_SUB(NOW(), INTERVAL {$hoursAgo} HOUR) WHERE `id` = " . $ticket['id']);

    $token = $siteToken($ticket['site_code']);
    $comment = $siteComment($ticket['ticket'], 'Aportamos una nota de seguimiento sobre el cierre técnico reciente.', $token);

    return ['status' => $comment?->getStatusCode(), 'code' => $comment?->getDecodedBody()['error']['code'] ?? null, 'state' => $pdo->query("SELECT `status` FROM `incidents` WHERE `id` = {$ticket['id']}")->fetchColumn()];
};

$inside = $windowProbe(47);
$assert(
    'E.5 RF-05.4: a 47 h de la resolución la sede TODAVÍA puede comentar (201)',
    $inside['status'] === 201,
    'status=' . ($inside['status'] ?? 'sin respuesta') . ' código=' . json_encode($inside['code'])
);
$cleanProbe();

$outside = $windowProbe(49);
$assert(
    'E.6 RF-05.4: a 49 h de la resolución el hilo queda sellado (403 CONVERSATION_SEALED)',
    $outside['status'] === 403 && $outside['code'] === 'CONVERSATION_SEALED',
    'status=' . ($outside['status'] ?? 'sin respuesta') . ' código=' . json_encode($outside['code'])
);
$cleanProbe();

echo "\n{$bold}======================================================================{$reset}\n";
echo " Total Aserciones    : {$assertions}\n";
echo " Exitosas            : " . ($assertions - $failures) . "\n";
echo " Fallos              : {$failures}\n";
echo " Omisiones           : {$skips}\n";
echo " Observaciones       : " . count($observations) . "\n";
echo "{$bold}======================================================================{$reset}\n";

// Cierre limpio del servidor de sonda (solo si lo levantó esta ejecución).
if (is_resource($liveProcess)) {
    proc_terminate($liveProcess);
    proc_close($liveProcess);
}

$cleanProbe();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

// El protocolo de las 72 h deja la máquina en «fuera de servicio por falta de acceso» a
// propósito (RF-04.4), y una máquina bloqueada exige la casilla de acceso en el siguiente
// aviso (RF-04.6). La sonda ejerce ese protocolo, así que lo deshace en las máquinas que
// quedan sin avería viva: sin esto, el banco de pruebas dejaría el parque degradado para
// cualquier suite posterior.
//
// La restitución tiene que cubrir las DOS marcas de «fuera de servicio» (`is_active = 0`
// y `is_blocked_no_access = 1`). Deshacer solo el bloqueo es el defecto silencioso: la
// máquina queda de baja, el parque pierde capacidad en cada corrida y la batería acaba
// en rojo por un banco mutilado y no por el código. La baja administrativa deliberada
// (`deleted_at` sellado) queda fuera del restablecimiento a propósito.
$pdo->exec(
    "UPDATE `machines` m SET m.`is_blocked_no_access` = 0, m.`is_active` = 1
      WHERE m.`is_blocked_no_access` = 1
        AND m.`deleted_at` IS NULL
        AND NOT EXISTS (
            SELECT 1 FROM `incidents` i
             WHERE i.`machine_id` = m.`id`
               AND i.`deleted_at` IS NULL
               AND i.`status` NOT IN ('CLOSED', 'CANCELLED')
        )"
);

if ($failures === 0) {
    echo "{$green}{$bold} RESULTADO: sin fallos de requisito en la sonda del módulo 11.{$reset}\n";
    exit(0);
}

echo "{$red}{$bold} RESULTADO: {$failures} fallo(s) de requisito en la sonda del módulo 11.{$reset}\n";
exit(1);
