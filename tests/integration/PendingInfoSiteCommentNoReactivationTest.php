<?php

declare(strict_types=1);

/**
 * PendingInfoSiteCommentNoReactivationTest (T-PAUSE-32, enmienda E-1 del 2026-10-11)
 *
 * Certifica por el camino real —MariaDB y el enrutador con sus middlewares de verdad,
 * sin invocar ningún controlador a mano— el comportamiento que el Product Owner declaró
 * correcto el 2026-10-11, después de reportar el defecto:
 *
 *   «cuando la sede envía un mensaje con el técnico pausado (por ejemplo, por estar la
 *    puerta cerrada), la avería se despausaba sola y volvía a En curso».
 *
 * La especificación vigente dice ahora lo contrario y esta suite lo custodia:
 *
 *   1. Un comentario público de la sede sobre un expediente en `PENDING_INFO` **nunca**
 *      transiciona el estado: ni en caliente (< 60 min) ni desfasado (>= 60 min). El
 *      expediente sigue pausado, con su `paused_at` vivo, su acumulador y su vencimiento
 *      contractual intactos y sin una sola fila de transición en `incident_history`.
 *   2. La respuesta de la sede no se pierde: enciende la marca derivada RF-05.6
 *      (`site_response_since_pause`) de la ruta del técnico, que pinta el aviso
 *      «✉️ La sede ha respondido · pendiente de tu reanudación».
 *   3. La ráfaga de mensajes es idempotente (RF-05.3): se anexan al hilo y no duplican
 *      pausas, acumuladores ni rastros.
 *   4. La pausa sólo la cierra un acto explícito —la reanudación in situ del técnico
 *      (RF-02.2/RF-02.3)— o la cancelación justificada por inactividad (RF-04.3).
 *   5. El expediente `CANCELLED` sigue sellado: comentar devuelve 403
 *      `CONVERSATION_SEALED` sin escribir una sola fila (RF-05.4, Art. III).
 *   6. La proyección ciudadana del QR sigue sin publicar el estado interno de pausa ni
 *      el bloqueo de acceso (RF-06.1, Art. V.4).
 *
 * El banco se toma de la flota activa y libre del parque (la clave
 * `uq_machine_active_ticket` impide dos tickets activos por equipo), así que cada
 * escenario estrena máquina y la sesión de sede se emite para la ubicación de la máquina
 * que le toca. Todo el banco se purga al terminar con `TestDataCleaner`; la máquina
 * cancelada en el banco se restituye en el bloque `finally` porque el protocolo de RF-04.4
 * la deja fuera de servicio y las filas maestras las protege el arnés.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Comentario de sede sin despausar (T-PAUSE-32, E-1)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$router       = AppRouter::create();
$incidentRepo = new PdoIncidentRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);
$pauseService = new IncidentPauseService(
    incidentRepo: $incidentRepo,
    machineRepo: $machineRepo
);

$assertions = 0;
$failures   = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }

    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
    $failures++;
};

// -----------------------------------------------------------------------
// Grupo 0: banco de pruebas sobre la flota activa y libre del parque
// -----------------------------------------------------------------------
$machinePool = $pdo->query(
    "SELECT m.id AS machine_id, m.code AS machine_code, m.location_id
       FROM machines m
       JOIN locations l ON l.id = m.location_id
      WHERE m.is_active = 1
        AND m.is_blocked_no_access = 0
        AND m.deleted_at IS NULL
        AND l.is_active = 1
        AND l.deleted_at IS NULL
        AND NOT EXISTS (
            SELECT 1 FROM incidents i
             WHERE i.machine_id = m.id
               AND i.deleted_at IS NULL
               AND i.status NOT IN ('CLOSED', 'CANCELLED')
        )
      ORDER BY m.id"
)->fetchAll(PDO::FETCH_ASSOC);

$assert(
    '0.1 Flota activa y libre suficiente para el banco de pruebas (>= 6 máquinas)',
    count($machinePool) >= 6,
    sprintf('El parque expone %d máquinas activas y libres.', count($machinePool))
);

if (count($machinePool) < 6) {
    echo "ERROR FATAL: no hay máquinas libres suficientes para el banco de pruebas.\n";
    exit(1);
}

$technicianRows = $pdo->query(
    "SELECT id FROM users
      WHERE role = 'TECHNICIAN' AND deleted_at IS NULL
      ORDER BY id LIMIT 2"
)->fetchAll(PDO::FETCH_COLUMN);

$primaryTech = isset($technicianRows[0]) ? $userRepo->findById((int)$technicianRows[0]) : null;
$otherTech   = isset($technicianRows[1]) ? $userRepo->findById((int)$technicianRows[1]) : null;
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

$assert(
    '0.2 Semilla disponible: coordinador y dos técnicos distintos',
    $primaryTech instanceof User && $otherTech instanceof User && $coordinator instanceof User
);

if (!$primaryTech instanceof User || !$otherTech instanceof User || !$coordinator instanceof User) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

$techId        = (int)$primaryTech->getId();
$otherTechId   = (int)$otherTech->getId();
$coordinatorId = (int)$coordinator->getId();

$technicianToken = $authService->generateInternalToken($primaryTech);
$otherTechToken  = $authService->generateInternalToken($otherTech);

// El técnico primario no debe arrastrar otra intervención activa: la suite mide que la
// pausa sobrevive, y una intervención ajena previa contaminaría el escenario.
$activeForPrimary = (int)$pdo->query(
    "SELECT COUNT(*) FROM incidents
      WHERE assigned_technician_id = {$techId}
        AND deleted_at IS NULL
        AND status NOT IN ('CLOSED', 'CANCELLED')"
)->fetchColumn();

$assert('0.3 El técnico primario no arrastra intervenciones activas previas', $activeForPrimary === 0);

/** Caché de sesiones de sede: una por ubicación, emitida bajo demanda. */
$siteTokenCache = [];
$siteToken = function (int $locationId) use (&$siteTokenCache, $authService, $locationRepo): string {
    if (!isset($siteTokenCache[$locationId])) {
        $location = $locationRepo->findById($locationId, true);
        if (!$location instanceof Location) {
            throw new RuntimeException("La sede {$locationId} del banco de pruebas no existe.");
        }
        $siteTokenCache[$locationId] = $authService->generateSiteToken($location);
    }

    return $siteTokenCache[$locationId];
};

$suffix   = strtoupper(bin2hex(random_bytes(3)));
$sequence = 0;

/** @return array{machine_id: int, machine_code: string, location_id: int} */
$nextMachine = function () use (&$machinePool): array {
    $machine = array_shift($machinePool);
    if ($machine === null) {
        throw new RuntimeException('Banco de pruebas agotado: la suite necesita una máquina libre por expediente.');
    }

    return [
        'machine_id'   => (int)$machine['machine_id'],
        'machine_code' => (string)$machine['machine_code'],
        'location_id'  => (int)$machine['location_id'],
    ];
};

/** @return array{0: int, 1: string, 2: int, 3: int} Id, ticket, máquina y sede. */
$createAssignedTicket = function (int $technicianId) use (
    $incidentRepo,
    $coordinatorId,
    $suffix,
    &$sequence,
    $nextMachine
): array {
    $sequence++;
    $machine = $nextMachine();
    $ticketCode = sprintf('TST-P32-%s-%02d', $suffix, $sequence);

    $created = $incidentRepo->create(new Incident(
        id: null,
        ticketCode: $ticketCode,
        machineId: $machine['machine_id'],
        locationId: $machine['location_id'],
        category: IncidentCategory::ELECTRICAL_OFF,
        description: 'Avería del banco de pruebas del comentario de sede del módulo 11.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));

    $incidentRepo->assign((int)$created->getId(), $technicianId, $coordinatorId);

    return [(int)$created->getId(), $ticketCode, $machine['machine_id'], $machine['location_id']];
};

$incidentRow = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM incidents WHERE id = :id');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$historyRows = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM incident_history WHERE incident_id = :id ORDER BY id ASC');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

/** Filas de historial que describen una transición DESDE la pausa (una reanudación). */
$resumeHistoryRows = function (int $incidentId) use ($historyRows): array {
    return array_values(array_filter(
        $historyRows($incidentId),
        static fn (array $row): bool => $row['from_status'] === 'PENDING_INFO'
    ));
};

$commentCount = function (int $incidentId) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM incident_comments WHERE incident_id = :id');
    $stmt->execute([':id' => $incidentId]);

    return (int)$stmt->fetchColumn();
};

/** Declara una pausa real a través del servicio canónico del módulo. */
$pause = function (int $incidentId, int $actorId) use ($pauseService): void {
    $pauseService->declarePendingInfoPause(
        $incidentId,
        $actorId,
        IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS,
        'La sede confirma que el cuarto de máquinas está cerrado y no hay acceso al equipo.'
    );
};

$commentPath = static fn (int $id): string => "/api/location/incidents/{$id}/comments";
$siteHeader  = static fn (string $token): array => ['Authorization' => 'Bearer ' . $token];
$techHeader  = static fn (string $token): array => ['Authorization' => 'Bearer ' . $token];
$validComment = 'La conserjería ya ha abierto la sala; el técnico puede acceder cuando llegue.';

/**
 * Publica un comentario de sede por el camino real y devuelve la respuesta.
 */
$postSiteComment = function (int $incidentId, int $locationId, string $text) use (
    $router,
    $commentPath,
    $siteToken,
    $siteHeader
): array {
    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $commentPath($incidentId),
        parsedBody: ['comment_text' => $text],
        headers: $siteHeader($siteToken($locationId))
    ));

    return ['status' => $res->getStatusCode(), 'body' => $res->getDecodedBody()];
};

/**
 * Lee la parada del expediente indicado en la ruta del técnico autenticado.
 *
 * @return array<string, mixed> La parada completa, o `[]` si no viaja en la ruta.
 */
$routeStop = function (int $incidentId, ?string $token = null) use ($router, $techHeader, $technicianToken): array {
    $res = $router->dispatch(new Request(
        method: 'GET',
        path: '/api/technician/my-route',
        headers: $techHeader($token ?? $technicianToken)
    ));

    foreach (($res->getDecodedBody()['data'] ?? []) as $stop) {
        if ((int)($stop['id'] ?? 0) === $incidentId) {
            return (array)$stop;
        }
    }

    return [];
};

$cancelledMachineIds = [];

try {
    // =========================================================================
    // GRUPO 1: comentario en caliente (< 60 min) NO despausa (E-1, RF-02.1)
    // =========================================================================
    echo "\n--- Grupo 1: comentario de sede en caliente sin despausar (RF-02.1, E-1) ---\n";

    [$hotIncidentId, $hotTicketCode, , $hotLocationId] = $createAssignedTicket($techId);
    $pause($hotIncidentId, $techId);

    $hotRowBefore         = $incidentRow($hotIncidentId);
    $hotPausedAtBefore    = (string)$hotRowBefore['paused_at'];
    $hotSecondsBefore     = (int)$hotRowBefore['total_pending_info_seconds'];
    $hotDeadlineBefore    = $hotRowBefore['sla_target_at'];
    $hotHistoryBefore     = count($historyRows($hotIncidentId));

    $assert(
        '1.1 El expediente queda pausado en PENDING_INFO por la vía canónica',
        ($hotRowBefore['status'] ?? '') === 'PENDING_INFO' && $hotPausedAtBefore !== ''
    );

    $hot = $postSiteComment($hotIncidentId, $hotLocationId, $validComment);
    $hotData = $hot['body']['data'] ?? [];

    $assert(
        '1.2 Comentario de sede sobre expediente pausado => 201 Created',
        $hot['status'] === 201 && ($hot['body']['success'] ?? false) === true,
        'Código recibido: ' . $hot['status']
    );
    $assert(
        '1.3 La respuesta 201 es el hilo canónico: sin flag auto_resumed ni bloque de pausa',
        !array_key_exists('auto_resumed', $hotData)
            && !array_key_exists('pause', $hotData)
            && !str_contains((string)json_encode($hotData), 'auto_resumed')
    );
    $assert(
        '1.4 La cabecera del hilo sigue publicando el estado real de pausa',
        ($hotData['incident']['status'] ?? '') === 'PENDING_INFO'
            && ($hotData['incident']['status_label'] ?? '') === 'Pendiente de información'
    );
    $assert(
        '1.5 El mensaje de la sede viaja en el hilo publicado',
        str_contains(json_encode($hotData['comments'] ?? [], JSON_UNESCAPED_UNICODE), $validComment)
    );

    $hotRow = $incidentRow($hotIncidentId);
    $assert(
        '1.6 EL DEFECTO REPORTADO: la pausa sigue viva tras el mensaje de la sede',
        ($hotRow['status'] ?? '') === 'PENDING_INFO'
            && ($hotRow['paused_at'] ?? null) === $hotPausedAtBefore,
        'Estado: ' . ($hotRow['status'] ?? '?') . ' · paused_at: ' . var_export($hotRow['paused_at'] ?? null, true)
    );
    $assert(
        '1.7 El acumulador de pausa no se cierra ni se duplica con el comentario',
        (int)($hotRow['total_pending_info_seconds'] ?? -1) === $hotSecondsBefore,
        sprintf('antes=%d · después=%d', $hotSecondsBefore, (int)($hotRow['total_pending_info_seconds'] ?? -1))
    );
    $assert(
        '1.8 El vencimiento contractual no se desplaza: el reloj de SLA sigue congelado (RF-03.2)',
        ($hotRow['sla_target_at'] ?? null) === $hotDeadlineBefore
            && ($hotRow['sla_target_at_original'] ?? null) === ($hotRowBefore['sla_target_at_original'] ?? null)
    );
    $assert(
        '1.9 El comentario no escribe ninguna transición desde la pausa (Art. III)',
        $resumeHistoryRows($hotIncidentId) === []
            && count($historyRows($hotIncidentId)) === $hotHistoryBefore
    );
    $assert(
        '1.10 El expediente sigue apareciendo en la ruta del técnico, pausado y despriorizado (RF-05.5)',
        ($routeStop($hotIncidentId)['status'] ?? '') === 'PENDING_INFO'
    );

    // =========================================================================
    // GRUPO 2: la marca RF-05.6 enciende el aviso del técnico (E-1, RF-05.6)
    // =========================================================================
    echo "\n--- Grupo 2: marca site_response_since_pause en la ruta del técnico (RF-05.6) ---\n";
    $hotStop = $routeStop($hotIncidentId);

    $assert(
        '2.1 La parada pausada publica la marca derivada de respuesta de sede',
        array_key_exists('site_response_since_pause', $hotStop)
            && $hotStop['site_response_since_pause'] === true,
        'Valor recibido: ' . var_export($hotStop['site_response_since_pause'] ?? null, true)
    );
    $assert(
        '2.2 La marca convive con el contrato de pausa del que nace (RF-05.5)',
        ($hotStop['paused_at'] ?? null) === $hotPausedAtBefore
            && ($hotStop['pending_info_reason_category'] ?? '') === 'BUILDING_CLOSED_NO_ACCESS'
            && ($hotStop['status'] ?? '') === 'PENDING_INFO'
    );
    $assert(
        '2.3 La marca no publica la autoría ni el texto del comentario de la sede',
        !str_contains((string)json_encode($hotStop), 'author_type')
            && !str_contains((string)json_encode($hotStop), 'is_internal')
    );

    // Control: una segunda pausa viva, sobre otro expediente y sin respuesta de la sede,
    // no puede encender la marca (la marca describe la pausa de ESE expediente).
    [$silentIncidentId, , , $silentLocationId] = $createAssignedTicket($techId);

    // Además, un mensaje de la sede ANTERIOR al inicio de la pausa no la enciende: el
    // aviso señala la respuesta que el técnico esperaba DENTRO de la pausa vigente, no
    // cualquier mensaje del hilo.
    $prePauseComment = $postSiteComment($silentIncidentId, $silentLocationId, 'Mensaje previo del hilo antes de la pausa.');
    // `paused_at` tiene resolución de segundo: se separa el comentario de la pausa para
    // que el escenario de control sea determinista y no comparta segundo con el mensaje.
    usleep(1_200_000);
    $pause($silentIncidentId, $techId);
    $silentStop = $routeStop($silentIncidentId);

    $assert(
        '2.4 Sin respuesta de la sede la marca permanece apagada (control del escenario)',
        ($prePauseComment['status'] ?? 0) === 201
            && ($silentStop['site_response_since_pause'] ?? null) === false
            && ($silentStop['status'] ?? '') === 'PENDING_INFO',
        'Respuesta del comentario previo: ' . ($prePauseComment['status'] ?? 0)
            . ' · marca: ' . var_export($silentStop['site_response_since_pause'] ?? null, true)
    );

    // =========================================================================
    // GRUPO 3: comentario desfasado (>= 60 min) tampoco despausa (RF-02.1, E-1)
    // =========================================================================
    echo "\n--- Grupo 3: comentario desfasado sin despausar ni desplazar SLA ---\n";

    [$coldIncidentId, $coldTicketCode, , $coldLocationId] = $createAssignedTicket($otherTechId);
    $pause($coldIncidentId, $otherTechId);

    $madrid = new DateTimeZone('Europe/Madrid');
    // Martes a las 12:15, dentro de la jornada comercial: si el comentario desplazase el
    // vencimiento (como hacía la reactivación derogada), el cambio sería visible al instante.
    $originalDeadline = (new DateTimeImmutable('next tuesday 12:15', $madrid))->format('Y-m-d H:i:s');
    $pdo->prepare('UPDATE `incidents` SET `sla_target_at` = :deadline, `paused_at` = DATE_SUB(NOW(), INTERVAL 90 MINUTE) WHERE `id` = :id')
        ->execute([':deadline' => $originalDeadline, ':id' => $coldIncidentId]);

    $coldRowBefore      = $incidentRow($coldIncidentId);
    $coldPausedAtBefore = (string)$coldRowBefore['paused_at'];
    $coldSecondsBefore  = (int)$coldRowBefore['total_pending_info_seconds'];
    $coldHistoryBefore  = count($historyRows($coldIncidentId));

    $cold = $postSiteComment($coldIncidentId, $coldLocationId, $validComment);
    $coldRow = $incidentRow($coldIncidentId);

    $assert(
        '3.1 El comentario desfasado también se publica como hilo canónico',
        $cold['status'] === 201
            && !array_key_exists('auto_resumed', $cold['body']['data'] ?? [])
            && !array_key_exists('pause', $cold['body']['data'] ?? [])
    );
    $assert(
        '3.2 Con 90 minutos de pausa el expediente sigue en PENDING_INFO (rama desfasada derogada)',
        ($coldRow['status'] ?? '') === 'PENDING_INFO'
            && ($coldRow['paused_at'] ?? null) === $coldPausedAtBefore,
        'Estado: ' . ($coldRow['status'] ?? '?')
    );
    $assert(
        '3.3 El vencimiento contractual NO se desplaza con el comentario (RF-03.2, RF-03.3)',
        ($coldRow['sla_target_at'] ?? null) === $originalDeadline
            && ($coldRow['sla_target_at'] ?? null) === ($coldRowBefore['sla_target_at'] ?? null)
    );
    $assert(
        '3.4 El acumulador de pausa permanece abierto y sin duplicar',
        (int)($coldRow['total_pending_info_seconds'] ?? -1) === $coldSecondsBefore
    );
    $assert(
        '3.5 No hay rastro de reactivación ni nota alguna de ventana de 60 minutos',
        $resumeHistoryRows($coldIncidentId) === []
            && count($historyRows($coldIncidentId)) === $coldHistoryBefore
    );
    $assert(
        '3.6 La marca RF-05.6 se enciende también en la respuesta desfasada',
        ($routeStop($coldIncidentId, $otherTechToken)['site_response_since_pause'] ?? null) === true,
        'La parada del escenario desfasado pertenece al segundo técnico: se lee con SU sesión.'
    );

    // =========================================================================
    // GRUPO 4: ráfaga de mensajes idempotente (RF-05.3)
    // =========================================================================
    echo "\n--- Grupo 4: ráfaga de mensajes idempotente (RF-05.3) ---\n";

    $burstBefore = $incidentRow($hotIncidentId);
    $burst = $postSiteComment($hotIncidentId, $hotLocationId, 'Insisto: la puerta queda abierta y el conserje espera al técnico.');
    $burstRow = $incidentRow($hotIncidentId);

    $assert(
        '4.1 El segundo mensaje se anexa al hilo sin tocar el estado',
        $burst['status'] === 201
            && ($burstRow['status'] ?? '') === 'PENDING_INFO'
            && ($burstRow['paused_at'] ?? null) === ($burstBefore['paused_at'] ?? null)
    );
    $assert(
        '4.2 La ráfaga no duplica el intervalo de pausa ni el acumulador',
        (int)($burstRow['total_pending_info_seconds'] ?? -1) === (int)($burstBefore['total_pending_info_seconds'] ?? -2)
            && count(array_filter(
                $historyRows($hotIncidentId),
                static fn (array $row): bool => $row['to_status'] === 'PENDING_INFO'
            )) === 1
    );
    $assert(
        '4.3 Los dos mensajes quedan anexados al hilo del expediente',
        $commentCount($hotIncidentId) === 2
    );
    $assert(
        '4.4 La marca sigue encendida tras la ráfaga',
        ($routeStop($hotIncidentId)['site_response_since_pause'] ?? null) === true
    );

    // =========================================================================
    // GRUPO 5: la pausa sólo la cierra un acto explícito (RF-02.2, RF-02.3, E-1)
    // =========================================================================
    echo "\n--- Grupo 5: reanudación explícita del técnico tras la respuesta de la sede ---\n";

    $resume = $router->dispatch(new Request(
        method: 'POST',
        path: "/api/technician/incidents/{$hotIncidentId}/resume-pending-info",
        parsedBody: ['target_status' => 'IN_PROGRESS', 'resume_note' => 'La sede ha abierto la sala; reanudo la intervención.'],
        headers: $techHeader($technicianToken)
    ));
    $resumeData = $resume->getDecodedBody()['data'] ?? [];
    $resumedRow = $incidentRow($hotIncidentId);

    $assert(
        '5.1 La reanudación manual del técnico responde 200 y cierra la pausa (RF-02.2)',
        $resume->getStatusCode() === 200
            && ($resumeData['status'] ?? '') === 'IN_PROGRESS'
            && ($resumeData['paused_at'] ?? null) === null,
        'Código: ' . $resume->getStatusCode() . ' · estado: ' . var_export($resumeData['status'] ?? null, true)
    );
    $assert(
        '5.2 El acumulador suma el intervalo completo al cerrarse por acto explícito (RF-04.1)',
        ($resumedRow['status'] ?? '') === 'IN_PROGRESS'
            && $resumedRow['paused_at'] === null
            && (int)($resumedRow['total_pending_info_seconds'] ?? 0) >= $hotSecondsBefore
    );
    $assert(
        '5.3 El rastro inmutable del cierre lo firma el técnico, no un tercero (Art. III)',
        count($resumeHistoryRows($hotIncidentId)) === 1
            && (int)($resumeHistoryRows($hotIncidentId)[0]['user_id'] ?? 0) === $techId
            && ($resumeHistoryRows($hotIncidentId)[0]['to_status'] ?? '') === 'IN_PROGRESS'
    );
    $assert(
        '5.4 Cerrada la pausa, la marca de respuesta de sede se apaga (no hay pausa viva)',
        ($routeStop($hotIncidentId)['site_response_since_pause'] ?? null) === false,
        'Valor recibido: ' . var_export($routeStop($hotIncidentId)['site_response_since_pause'] ?? null, true)
    );

    // =========================================================================
    // GRUPO 6: expediente cancelado => 403 CONVERSATION_SEALED (RF-05.4, Art. III)
    // =========================================================================
    echo "\n--- Grupo 6: expediente CANCELLED sella el hilo (403) ---\n";

    [$cancelledId, , $cancelledMachineId, $cancelledLocationId] = $createAssignedTicket($techId);
    $cancelledMachineIds[] = $cancelledMachineId;
    $pause($cancelledId, $techId);

    // El cierre por inactividad exige superar las 72 h hábiles (RF-04.3): se siembra el
    // silencio de sede en lugar de esperar tres jornadas de reloj real.
    $pdo->prepare('UPDATE incidents SET paused_at = :paused WHERE id = :id')->execute([
        ':paused' => EngineClock::shift(-21 * 86400),
        ':id'     => $cancelledId,
    ]);

    $pauseService->cancelByInactivity(
        $cancelledId,
        $coordinatorId,
        'Cierre administrativo por inactividad y falta de acceso del cliente tras 72h hábiles.'
    );

    $cancelledRow   = $incidentRow($cancelledId);
    $commentsBefore = $commentCount($cancelledId);
    $historyBefore  = count($historyRows($cancelledId));

    $assert(
        '6.0 El expediente queda cancelado y la máquina bloqueada sin acceso (RF-04.4)',
        ($cancelledRow['status'] ?? '') === 'CANCELLED'
            && (int)($pdo->query("SELECT is_active FROM machines WHERE id = {$cancelledMachineId}")->fetchColumn()) === 0
            && (int)($pdo->query("SELECT is_blocked_no_access FROM machines WHERE id = {$cancelledMachineId}")->fetchColumn()) === 1
    );

    $sealed = $postSiteComment($cancelledId, $cancelledLocationId, $validComment);

    $assert(
        '6.1 Comentar sobre un expediente cancelado => 403 CONVERSATION_SEALED (RF-05.4)',
        $sealed['status'] === 403
            && ($sealed['body']['error']['code'] ?? '') === 'CONVERSATION_SEALED'
            && ($sealed['body']['error']['message'] ?? '') !== ''
    );

    $sealedMessage = (string)($sealed['body']['error']['message'] ?? '');
    $assert(
        '6.2 RF-05.4: el rechazo indica a la sede confirmar el acceso para pedir una nueva asistencia',
        str_contains($sealedMessage, 'confirme el acceso a la máquina')
            && str_contains($sealedMessage, 'nueva asistencia')
            && str_contains($sealedMessage, 'abiertas y accesibles para el servicio técnico')
    );
    $assert(
        '6.3 El rechazo no escribe comentario ni rastro alguno (Art. III)',
        $commentCount($cancelledId) === $commentsBefore
            && count($historyRows($cancelledId)) === $historyBefore
    );
    $assert(
        '6.4 La pausa acumulada del expediente cancelado permanece intacta',
        (int)($incidentRow($cancelledId)['total_pending_info_seconds'] ?? -1)
            === (int)($cancelledRow['total_pending_info_seconds'] ?? -2)
            && ($incidentRow($cancelledId)['status'] ?? '') === 'CANCELLED'
    );

    // =========================================================================
    // GRUPO 7: proyección ciudadana del QR (RF-06.1, Art. V.4)
    // =========================================================================
    echo "\n--- Grupo 7: proyección pública del QR sin rastro de pausa (Art. V.4) ---\n";

    [$qrIncidentId, $qrTicketCode, $qrMachineId, $qrLocationId] = $createAssignedTicket($techId);
    $pause($qrIncidentId, $techId);
    $qrMachineCode = (string)$pdo->query("SELECT code FROM machines WHERE id = {$qrMachineId}")->fetchColumn();

    // El reporte ciudadano en carrera (EARS 4.5) escribe su mensaje con el MISMO tipo de
    // autor que la sede (`REPORTER`, `user_id = NULL`). No puede encender el aviso: el
    // ciudadano no es la sede. Esta aserción custodia el discriminador del puerto.
    $resReport = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/qr/report',
        parsedBody: [
            'machine_code' => $qrMachineCode,
            'category'     => 'PRODUCT_JAM',
            'description'  => 'Un compañero tampoco pudo comprar: la espiral se quedó girada sin soltar el producto.',
        ],
        headers: ['content-type' => 'application/json']
    ));
    $reportData = $resReport->getDecodedBody()['data'] ?? [];

    $assert(
        '7.5 El reporte ciudadano concurrente se fusiona en el expediente pausado',
        $resReport->getStatusCode() === 200
            && ($reportData['merged'] ?? false) === true
            && ($reportData['ticket_code'] ?? '') === $qrTicketCode
    );
    $assert(
        '7.6 La fusión ciudadana tampoco despausa el expediente (sigue PENDING_INFO en la base)',
        ($reportData['status'] ?? '') === 'IN_PROGRESS'
            && ($incidentRow($qrIncidentId)['status'] ?? '') === 'PENDING_INFO'
            && ($incidentRow($qrIncidentId)['paused_at'] ?? null) !== null
    );
    $assert(
        '7.7 Un aviso ciudadano NO es una respuesta de la sede: la marca sigue apagada',
        ($routeStop($qrIncidentId)['site_response_since_pause'] ?? null) === false,
        'Valor recibido: ' . var_export($routeStop($qrIncidentId)['site_response_since_pause'] ?? null, true)
    );

    // Ahora sí responde la sede: la marca se enciende y el QR sigue sin verla.
    $postSiteComment($qrIncidentId, $qrLocationId, $validComment);
    $assert(
        '7.8 La respuesta de la sede sí enciende la marca sobre el mismo expediente',
        ($routeStop($qrIncidentId)['site_response_since_pause'] ?? null) === true
    );

    $resScan = $router->dispatch(new Request('GET', "/api/qr/scan/{$qrMachineCode}"));
    $scanRaw = $resScan->getBody();
    $scanData = $resScan->getDecodedBody()['data'] ?? [];

    $assert(
        '7.1 El escaneo ciudadano sigue leyendo la avería activa y sin poder reportar de nuevo',
        $resScan->getStatusCode() === 200
            && ($scanData['status_mode'] ?? '') === 'ACTIVE_INCIDENT'
            && ($scanData['can_report'] ?? true) === false
    );
    $assert(
        '7.2 El estado público es «En proceso de atención técnica» y nunca PENDING_INFO',
        ($scanData['active_incident']['status_label'] ?? '') === 'En proceso de atención técnica'
            && ($scanData['active_incident']['public_status'] ?? '') === 'IN_PROGRESS'
            && !str_contains($scanRaw, 'PENDING_INFO')
    );
    $assert(
        '7.3 La carga útil no filtra motivo de pausa, causa tipificada, marca de respuesta ni bloqueo',
        !str_contains($scanRaw, 'paused_at')
            && !str_contains($scanRaw, 'reason_category')
            && !str_contains($scanRaw, 'reason_text')
            && !str_contains($scanRaw, 'BUILDING_CLOSED_NO_ACCESS')
            && !str_contains($scanRaw, 'site_response_since_pause')
            && !str_contains($scanRaw, 'is_blocked_no_access')
    );
    $assert(
        '7.4 El expediente sigue siendo el mismo y la máquina permanece en el parque',
        ($scanData['active_incident']['ticket_code'] ?? '') === $qrTicketCode
            && (int)($pdo->query("SELECT is_active FROM machines WHERE id = {$qrMachineId}")->fetchColumn()) === 1
    );

} finally {
    // La máquina del escenario cancelado queda fuera de servicio (RF-04.4) y las filas
    // maestras las protege el arnés: se restituye antes de purgar los expedientes.
    foreach ($cancelledMachineIds as $machineId) {
        $pdo->prepare('UPDATE machines SET is_active = 1, is_blocked_no_access = 0 WHERE id = :id')
            ->execute([':id' => $machineId]);
    }

    $purged = TestDataCleaner::purge($pdo);
    echo "\n--- Limpieza del banco de pruebas ---\n";
    echo '  [OK] Filas de prueba purgadas en ' . count($purged) . " tablas (las filas maestras sembradas permanecen).\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. UN MENSAJE DE LA SEDE NUNCA DESPAUSA (E-1).\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
