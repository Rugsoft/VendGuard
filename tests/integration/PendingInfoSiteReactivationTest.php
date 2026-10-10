<?php

declare(strict_types=1);

/**
 * PendingInfoSiteReactivationTest (T-PAUSE-14)
 *
 * Verifica la condición "Hecho cuando" de T-PAUSE-14 contra MariaDB real y a través
 * del enrutador con sus middlewares de verdad (no se invoca el controlador a mano):
 *
 *   1. `LocationPortalController::addComment()` encadena la reactivación condicional
 *      `IncidentPauseService::handleSiteCommentReactivation(...)` cuando el comentario
 *      público de la sede llega a un expediente en `PENDING_INFO`, y la respuesta 201
 *      devuelve el flag `auto_resumed: true` con el bloque de pausa (plan.md §2.3).
 *   2. La reactivación respeta las dos ramas de RF-02.1: en caliente (< 60 min) vuelve a
 *      `IN_PROGRESS`; desfasada (>= 60 min) devuelve el expediente a `ASSIGNED` con el
 *      vencimiento contractual desplazado en horario comercial y el rastro inmutable del
 *      actor de sede (Art. III).
 *   3. La ráfaga es idempotente (RF-05.3): sólo el primer mensaje transiciona y los
 *      siguientes se anexan al hilo como seguimiento normal sin tocar el estado.
 *   4. El expediente `CANCELLED` bloquea el comentario con HTTP 403 `CONVERSATION_SEALED`
 *      sin escribir una sola fila (RF-05.4, Art. III): el hilo queda sellado en solo lectura
 *      y, cuando la máquina sigue bloqueada por falta de acceso, el rechazo indica a la sede
 *      cómo solicitar una nueva asistencia (confirmar el acceso, RF-04.6).
 *   5. La proyección ciudadana del QR (escaneo y reporte concurrente) publica
 *      `PENDING_INFO` como «En proceso de atención técnica», sin el código interno del
 *      estado, sin motivo de pausa ni bloqueo de acceso (RF-06.1, Art. V.4).
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
echo " VendGuard: Integración - PendingInfoSiteReactivationTest (T-PAUSE-14)\n";
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
    "SELECT m.`id` AS machine_id, m.`code` AS machine_code, m.`location_id`
       FROM `machines` m
       JOIN `locations` l ON l.`id` = m.`location_id`
      WHERE m.`is_active` = 1
        AND m.`is_blocked_no_access` = 0
        AND m.`deleted_at` IS NULL
        AND l.`is_active` = 1
        AND l.`deleted_at` IS NULL
        AND NOT EXISTS (
            SELECT 1 FROM `incidents` i
             WHERE i.`machine_id` = m.`id`
               AND i.`deleted_at` IS NULL
               AND i.`status` NOT IN ('CLOSED', 'CANCELLED')
        )
      ORDER BY m.`id`"
)->fetchAll(PDO::FETCH_ASSOC);

$assert(
    '0.1 Flota activa y libre suficiente para el banco de pruebas (>= 5 máquinas)',
    count($machinePool) >= 5,
    sprintf('El parque expone %d máquinas activas y libres.', count($machinePool))
);

if (count($machinePool) < 5) {
    echo "ERROR FATAL: no hay máquinas libres suficientes para el banco de pruebas.\n";
    exit(1);
}

$technicianRows = $pdo->query(
    "SELECT `id` FROM `users`
      WHERE `role` = 'TECHNICIAN' AND `deleted_at` IS NULL
      ORDER BY `id` LIMIT 2"
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

// El técnico primario no debe arrastrar otra intervención activa: sería él quien
// impidiera la reanudación en caliente y la suite mediría una causa ajena a la tarea.
$activeForPrimary = (int)$pdo->query(
    "SELECT COUNT(*) FROM `incidents`
      WHERE `assigned_technician_id` = {$techId}
        AND `deleted_at` IS NULL
        AND `status` NOT IN ('CLOSED', 'CANCELLED')"
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
    $ticketCode = sprintf('TST-P14-%s-%02d', $suffix, $sequence);

    $created = $incidentRepo->create(new Incident(
        id: null,
        ticketCode: $ticketCode,
        machineId: $machine['machine_id'],
        locationId: $machine['location_id'],
        category: IncidentCategory::ELECTRICAL_OFF,
        description: 'Avería del banco de pruebas del gancho reactivador de sede del módulo 11.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));

    $incidentRepo->assign((int)$created->getId(), $technicianId, $coordinatorId);

    return [(int)$created->getId(), $ticketCode, $machine['machine_id'], $machine['location_id']];
};

$incidentRow = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM `incidents` WHERE `id` = :id');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$historyRows = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM `incident_history` WHERE `incident_id` = :id ORDER BY `id` ASC');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$commentCount = function (int $incidentId) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `incident_comments` WHERE `incident_id` = :id');
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
$validComment = 'La conserjería ya ha abierto la sala; el técnico puede acceder cuando llegue.';

$cancelledMachineIds = [];

try {
    // =========================================================================
    // GRUPO 1: gancho reactivador en caliente (RF-02.1, plan.md §2.3)
    // =========================================================================
    echo "\n--- Grupo 1: comentario de sede en caliente => IN_PROGRESS ---\n";

    [$hotIncidentId, $hotTicketCode, , $hotLocationId] = $createAssignedTicket($techId);
    $pause($hotIncidentId, $techId);

    $assert(
        '1.1 El expediente queda pausado en PENDING_INFO por la vía canónica',
        ($incidentRow($hotIncidentId)['status'] ?? '') === 'PENDING_INFO'
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $commentPath($hotIncidentId),
        parsedBody: ['comment_text' => $validComment],
        headers: $siteHeader($siteToken($hotLocationId))
    ));
    $body = $res->getDecodedBody();
    $data = $body['data'] ?? [];

    $assert(
        '1.2 Comentario de sede sobre expediente pausado => 201 Created',
        $res->getStatusCode() === 201 && ($body['success'] ?? false) === true
    );
    $assert(
        '1.3 La respuesta publica el flag auto_resumed: true (RF-02.1)',
        ($data['auto_resumed'] ?? false) === true
    );
    $assert(
        '1.4 El bloque de pausa devuelve el expediente a IN_PROGRESS en caliente',
        ($data['pause']['status'] ?? '') === 'IN_PROGRESS'
            && ($data['pause']['is_sla_paused'] ?? true) === false
            && ($data['pause']['ticket_code'] ?? '') === $hotTicketCode
    );
    $assert(
        '1.5 La cabecera del hilo publica el estado real tras la reanudación (sin ficción)',
        ($data['incident']['status'] ?? '') === 'IN_PROGRESS'
            && ($data['incident']['status_label'] ?? '') === 'En curso'
    );
    $assert(
        '1.6 El mensaje de la sede viaja en el hilo publicado',
        str_contains(json_encode($data['comments'] ?? [], JSON_UNESCAPED_UNICODE), $validComment)
    );

    $hotRow = $incidentRow($hotIncidentId);
    $assert(
        '1.7 Persistencia real: IN_PROGRESS con la pausa viva cerrada',
        ($hotRow['status'] ?? '') === 'IN_PROGRESS'
            && $hotRow['paused_at'] === null
            && (int)($hotRow['total_pending_info_seconds'] ?? -1) >= 0
    );

    $hotHistory = array_values(array_filter(
        $historyRows($hotIncidentId),
        static fn (array $row): bool => $row['from_status'] === 'PENDING_INFO' && $row['to_status'] === 'IN_PROGRESS'
    ));
    $assert(
        '1.8 El rastro inmutable sella la reactivación con el actor de sede referenciado',
        count($hotHistory) === 1
            && $hotHistory[0]['user_id'] === null
            && str_contains((string)$hotHistory[0]['action_note'], 'Reactivación automática por comentario público de sede')
            && str_contains((string)$hotHistory[0]['action_note'], sprintf('actor de sede #%d', $hotLocationId))
    );

    // =========================================================================
    // GRUPO 2: idempotencia de la ráfaga (RF-05.3)
    // =========================================================================
    echo "\n--- Grupo 2: ráfaga de mensajes idempotente (RF-05.3) ---\n";

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $commentPath($hotIncidentId),
        parsedBody: ['comment_text' => 'Insisto: la puerta queda abierta y el conserje espera al técnico.'],
        headers: $siteHeader($siteToken($hotLocationId))
    ));
    $burstData = $res->getDecodedBody()['data'] ?? [];

    $assert(
        '2.1 El segundo mensaje se publica como seguimiento sin reactivar de nuevo',
        $res->getStatusCode() === 201
            && ($burstData['auto_resumed'] ?? true) === false
            && !array_key_exists('pause', $burstData)
    );
    $assert(
        '2.2 Sólo existe un rastro de transición PENDING_INFO -> IN_PROGRESS',
        count(array_filter(
            $historyRows($hotIncidentId),
            static fn (array $row): bool => $row['from_status'] === 'PENDING_INFO'
        )) === 1
    );
    $assert(
        '2.3 El estado sigue en IN_PROGRESS y el acumulador no se altera en la ráfaga',
        ($incidentRow($hotIncidentId)['status'] ?? '') === 'IN_PROGRESS'
            && (int)($incidentRow($hotIncidentId)['total_pending_info_seconds'] ?? -1)
                === (int)($hotRow['total_pending_info_seconds'] ?? -2)
    );
    $assert('2.4 Los dos mensajes quedan anexados al hilo', $commentCount($hotIncidentId) === 2);

    // =========================================================================
    // GRUPO 3: reanudación desfasada y desplazamiento comercial (RF-02.1, RF-03.3)
    // =========================================================================
    echo "\n--- Grupo 3: comentario desfasado => ASSIGNED con SLA desplazado ---\n";

    [$coldIncidentId, $coldTicketCode, , $coldLocationId] = $createAssignedTicket($otherTechId);
    $pause($coldIncidentId, $otherTechId);

    $madrid = new DateTimeZone('Europe/Madrid');
    // Martes a las 12:15, dentro de la jornada comercial: el desplazamiento de la pausa
    // debe quedarse en el mismo día hábil y equivaler a los segundos acumulados.
    $originalDeadline = (new DateTimeImmutable('next tuesday 12:15', $madrid))->format('Y-m-d H:i:s');
    $pdo->prepare('UPDATE `incidents` SET `sla_target_at` = :deadline, `paused_at` = DATE_SUB(NOW(), INTERVAL 90 MINUTE) WHERE `id` = :id')
        ->execute([':deadline' => $originalDeadline, ':id' => $coldIncidentId]);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $commentPath($coldIncidentId),
        parsedBody: ['comment_text' => $validComment],
        headers: $siteHeader($siteToken($coldLocationId))
    ));
    $coldData = $res->getDecodedBody()['data'] ?? [];
    $coldRow  = $incidentRow($coldIncidentId);

    $assert(
        '3.1 La respuesta reactiva y devuelve el expediente a ASSIGNED (>= 60 min)',
        $res->getStatusCode() === 201
            && ($coldData['auto_resumed'] ?? false) === true
            && ($coldData['pause']['status'] ?? '') === 'ASSIGNED'
    );
    $assert(
        '3.2 La cabecera del hilo publica el estado real ASSIGNED',
        ($coldData['incident']['status'] ?? '') === 'ASSIGNED'
    );
    $assert(
        '3.3 Persistencia real del retorno a asignada',
        ($coldRow['status'] ?? '') === 'ASSIGNED' && $coldRow['paused_at'] === null
    );

    $shiftedDeadline = (string)($coldData['pause']['sla_target_at_shifted'] ?? '');
    $originalMoment  = new DateTimeImmutable($originalDeadline, $madrid);
    $shiftedMoment   = new DateTimeImmutable($shiftedDeadline, $madrid);
    $shiftSeconds    = $shiftedMoment->getTimestamp() - $originalMoment->getTimestamp();
    $shiftAccumulated = (int)($coldRow['total_pending_info_seconds'] ?? 0);

    $assert(
        '3.4 El desplazamiento equivale a los segundos exactos de pausa (RNF-01)',
        $shiftedDeadline !== ''
            && ($coldData['pause']['sla_target_at_original'] ?? '') === $originalDeadline
            && $shiftSeconds === $shiftAccumulated
            && $shiftAccumulated >= 5400,
        sprintf('desplazamiento=%d s, acumulado=%d s', $shiftSeconds, $shiftAccumulated)
    );
    $assert(
        '3.5 El nuevo vencimiento cae en día hábil dentro de la jornada comercial',
        (int)$shiftedMoment->format('N') <= 5
            && $shiftedMoment->format('Y-m-d') === $originalMoment->format('Y-m-d')
            && (int)$shiftedMoment->format('G') >= 8
            && (int)$shiftedMoment->format('G') <= 18,
        'Vencimiento desplazado: ' . $shiftedDeadline
    );
    $assert(
        '3.6 El vencimiento desplazado queda persistido y el rastro explica la causa',
        ($coldRow['sla_target_at'] ?? '') === $shiftedDeadline
            && count(array_filter(
                $historyRows($coldIncidentId),
                static fn (array $row): bool => $row['from_status'] === 'PENDING_INFO'
                    && $row['to_status'] === 'ASSIGNED'
                    && str_contains((string)$row['action_note'], 'fuera de la ventana de 60 minutos')
            )) === 1
    );

    // =========================================================================
    // GRUPO 4: comentario normal sin pausa (no fabrica reactivaciones)
    // =========================================================================
    echo "\n--- Grupo 4: comentario de sede sin pausa abierta ---\n";

    [$plainIncidentId, , , $plainLocationId] = $createAssignedTicket($techId);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $commentPath($plainIncidentId),
        parsedBody: ['comment_text' => $validComment],
        headers: $siteHeader($siteToken($plainLocationId))
    ));
    $plainData = $res->getDecodedBody()['data'] ?? [];

    $assert(
        '4.1 Un expediente sin pausa publica el comentario sin flag de reactivación',
        $res->getStatusCode() === 201
            && ($plainData['auto_resumed'] ?? true) === false
            && !array_key_exists('pause', $plainData)
    );
    $assert(
        '4.2 El estado ASSIGNED no se altera y no se escribe rastro de pausa',
        ($incidentRow($plainIncidentId)['status'] ?? '') === 'ASSIGNED'
            && count(array_filter(
                $historyRows($plainIncidentId),
                static fn (array $row): bool => $row['to_status'] === 'PENDING_INFO' || $row['from_status'] === 'PENDING_INFO'
            )) === 0
    );

    // =========================================================================
    // GRUPO 5: expediente cancelado => 403 CONVERSATION_SEALED (RF-05.4, Art. III)
    // =========================================================================
    echo "\n--- Grupo 5: expediente CANCELLED sella el hilo (403) ---\n";

    [$cancelledId, , $cancelledMachineId, $cancelledLocationId] = $createAssignedTicket($techId);
    $cancelledMachineIds[] = $cancelledMachineId;
    $pause($cancelledId, $techId);
    $pauseService->cancelByInactivity(
        $cancelledId,
        $coordinatorId,
        'Cierre administrativo por inactividad y falta de acceso del cliente tras 72h hábiles.'
    );

    $cancelledRow   = $incidentRow($cancelledId);
    $commentsBefore = $commentCount($cancelledId);
    $historyBefore  = count($historyRows($cancelledId));

    $assert(
        '5.0 El expediente queda cancelado y la máquina bloqueada sin acceso (RF-04.4)',
        ($cancelledRow['status'] ?? '') === 'CANCELLED'
            && (int)($pdo->query("SELECT `is_active` FROM `machines` WHERE `id` = {$cancelledMachineId}")->fetchColumn()) === 0
            && (int)($pdo->query("SELECT `is_blocked_no_access` FROM `machines` WHERE `id` = {$cancelledMachineId}")->fetchColumn()) === 1
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $commentPath($cancelledId),
        parsedBody: ['comment_text' => $validComment],
        headers: $siteHeader($siteToken($cancelledLocationId))
    ));

    $assert(
        '5.1 Comentar sobre un expediente cancelado => 403 CONVERSATION_SEALED (RF-05.4)',
        $res->getStatusCode() === 403
            && ($res->getDecodedBody()['error']['code'] ?? '') === 'CONVERSATION_SEALED'
            && $res->getDecodedBody()['error']['message'] !== ''
    );

    $sealedMessage = (string)($res->getDecodedBody()['error']['message'] ?? '');
    $assert(
        '5.1.b RF-05.4: el rechazo indica a la sede confirmar el acceso para solicitar una nueva asistencia',
        str_contains($sealedMessage, 'confirme el acceso a la máquina')
            && str_contains($sealedMessage, 'nueva asistencia')
            && str_contains($sealedMessage, 'abiertas y accesibles para el servicio técnico')
    );

    $assert(
        '5.2 El rechazo no escribe comentario ni rastro alguno (Art. III)',
        $commentCount($cancelledId) === $commentsBefore
            && count($historyRows($cancelledId)) === $historyBefore
    );
    $assert(
        '5.3 La pausa acumulada del expediente cancelado permanece intacta',
        ($incidentRow($cancelledId)['total_pending_info_seconds'] ?? -1)
            === (int)($cancelledRow['total_pending_info_seconds'] ?? -2)
            && ($incidentRow($cancelledId)['status'] ?? '') === 'CANCELLED'
    );

    // =========================================================================
    // GRUPO 6: proyección ciudadana del QR (RF-06.1, Art. V.4)
    // =========================================================================
    echo "\n--- Grupo 6: proyección pública del QR sin rastro de pausa (Art. V.4) ---\n";

    [$qrIncidentId, $qrTicketCode, $qrMachineId] = $createAssignedTicket($techId);
    $pause($qrIncidentId, $techId);
    $qrMachineCode = (string)$pdo->query("SELECT `code` FROM `machines` WHERE `id` = {$qrMachineId}")->fetchColumn();

    $resScan = $router->dispatch(new Request('GET', "/api/qr/scan/{$qrMachineCode}"));
    $scanRaw = $resScan->getBody();
    $scanData = $resScan->getDecodedBody()['data'] ?? [];

    $assert(
        '6.1 El escaneo ciudadano sigue leyendo la avería activa y sin poder reportar de nuevo',
        $resScan->getStatusCode() === 200
            && ($scanData['status_mode'] ?? '') === 'ACTIVE_INCIDENT'
            && ($scanData['can_report'] ?? true) === false
    );
    $assert(
        '6.2 El estado público es «En proceso de atención técnica» y nunca PENDING_INFO',
        ($scanData['active_incident']['status_label'] ?? '') === 'En proceso de atención técnica'
            && ($scanData['active_incident']['public_status'] ?? '') === 'IN_PROGRESS'
            && !str_contains($scanRaw, 'PENDING_INFO')
    );
    $assert(
        '6.3 La carga útil no filtra motivo de pausa, causa tipificada ni bloqueo de acceso',
        !str_contains($scanRaw, 'paused_at')
            && !str_contains($scanRaw, 'reason_category')
            && !str_contains($scanRaw, 'reason_text')
            && !str_contains($scanRaw, 'BUILDING_CLOSED_NO_ACCESS')
            && !str_contains($scanRaw, 'is_blocked_no_access')
            && !str_contains($scanRaw, 'BLOCKED_NO_ACCESS')
    );
    $assert(
        '6.4 El expediente sigue siendo el mismo y la máquina permanece en el parque',
        ($scanData['active_incident']['ticket_code'] ?? '') === $qrTicketCode
            && (int)($pdo->query("SELECT `is_active` FROM `machines` WHERE `id` = {$qrMachineId}")->fetchColumn()) === 1
    );

    // El reporte ciudadano sobre una máquina cuya avería está pausada se fusiona en el
    // expediente vivo (EARS 4.5) y tampoco puede publicar el estado interno de pausa.
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
        '6.5 El reporte concurrente se fusiona en el expediente pausado',
        $resReport->getStatusCode() === 200
            && ($reportData['merged'] ?? false) === true
            && ($reportData['ticket_code'] ?? '') === $qrTicketCode
    );
    $assert(
        '6.6 La fusión tampoco publica el estado interno de pausa al ciudadano',
        ($reportData['status'] ?? '') === 'IN_PROGRESS'
            && ($reportData['status_label'] ?? '') === 'En proceso de atención técnica'
    );

} finally {
    echo "\n--- Limpieza de los expedientes propios de la suite ---\n";

    // El protocolo de RF-04.4 deja la máquina fuera de servicio: se restituye para no
    // desviar el inventario de las suites QR posteriores (filas maestras protegidas).
    foreach ($cancelledMachineIds as $machineId) {
        $pdo->prepare('UPDATE `machines` SET `is_active` = 1, `is_blocked_no_access` = 0 WHERE `id` = :id')
            ->execute([':id' => $machineId]);
    }

    $purged = TestDataCleaner::purgeIncidentsMatchingTicket($pdo, 'TST-P14-%');
    echo '  [OK] Expedientes purgados con TestDataCleaner (patrón TST-P14-%) · filas de incidencias eliminadas: '
        . array_sum($purged) . " (máquinas y sedes intactas)\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-14 CUMPLIDA.\n"
    : " RESULTADO: HAY FALLOS. CONDICIÓN T-PAUSE-14 NO CUMPLIDA.\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
