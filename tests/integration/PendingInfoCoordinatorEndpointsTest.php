<?php

declare(strict_types=1);

/**
 * PendingInfoCoordinatorEndpointsTest (T-PAUSE-13)
 *
 * Verifica la condición "Hecho cuando" de T-PAUSE-13 contra MariaDB real, a través del
 * enrutador y de los middlewares de verdad (no se invoca el controlador a mano):
 *
 *   - `CoordinatorController::pausePendingInfo()` en POST .../pause-pending-info.
 *   - `CoordinatorController::resumePendingInfo()` en POST .../resume-pending-info.
 *   - `CoordinatorController::cancelInactivity()` en POST .../cancel-inactivity.
 *   - Códigos del contrato de `plan.md` §2.1.B, §2.2.B y §2.4: 200, 400, 401, 403, 404, 422.
 *   - Protocolo completo de inactividad de 72 h hábiles: ticket cancelado, máquina fuera de
 *     servicio por falta de acceso y reintegro del consumidor desvinculado y preservado
 *     (RF-04.3 a RF-04.5, Art. V.1).
 *   - RF-06.3: la reasignación de un expediente pausado conserva `PENDING_INFO`, el contexto
 *     del bloqueo y deja la huella estructural que la reactivación por comentario de sede
 *     lee para devolver la avería a `ASSIGNED`.
 *
 * Cada expediente estrena máquina sembrada (`uq_machine_active_ticket` impide dos tickets
 * activos por equipo y todo el banco se purga con `TestDataCleaner`); las filas maestras
 * sólo se LEEN y los reintegros propios se reenganchan a su avería antes del purgado.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
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
echo " VendGuard: Integración - PendingInfoCoordinatorEndpointsTest (T-PAUSE-13)\n";
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

$technicianRows = $pdo->query(
    "SELECT `id` FROM `users` WHERE `role` = 'TECHNICIAN' AND `deleted_at` IS NULL ORDER BY `id` LIMIT 2"
)->fetchAll(PDO::FETCH_COLUMN);

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$primaryTech = isset($technicianRows[0]) ? $userRepo->findById((int)$technicianRows[0]) : null;
$otherTech   = isset($technicianRows[1]) ? $userRepo->findById((int)$technicianRows[1]) : null;

$assert(
    '0.1 Semilla disponible: coordinador y dos técnicos distintos',
    $coordinator instanceof User && $primaryTech instanceof User && $otherTech instanceof User
);

if (!$coordinator instanceof User || !$primaryTech instanceof User || !$otherTech instanceof User) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

$machinePool = $pdo->query(
    "SELECT m.`id` AS machine_id, m.`location_id` AS location_id
       FROM `machines` m
      WHERE m.`is_active` = 1
        AND NOT EXISTS (
            SELECT 1 FROM `incidents` i
             WHERE i.`machine_id` = m.`id`
               AND i.`deleted_at` IS NULL
               AND i.`status` NOT IN ('CLOSED', 'CANCELLED')
        )
      ORDER BY m.`id`"
)->fetchAll(PDO::FETCH_ASSOC);

$assert(
    '0.2 Flota sembrada suficiente para el banco de pruebas (>= 8 máquinas activas y libres)',
    count($machinePool) >= 8,
    sprintf('El parque sembrado expone %d máquinas libres.', count($machinePool))
);

if (count($machinePool) < 8) {
    echo "ERROR FATAL: flota sembrada insuficiente para el banco de pruebas.\n";
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken  = $authService->generateInternalToken($primaryTech);
$coordinatorId    = (int)$coordinator->getId();
$techId           = (int)$primaryTech->getId();
$otherTechId      = (int)$otherTech->getId();
$authHeader       = static fn (string $token): array => ['Authorization' => 'Bearer ' . $token];

$suffix   = strtoupper(bin2hex(random_bytes(3)));
$sequence = 0;

/** @return array{machine_id: int, location_id: int} */
$nextMachine = function () use (&$machinePool): array {
    $machine = array_shift($machinePool);
    if ($machine === null) {
        throw new RuntimeException('Banco de pruebas agotado: la suite necesita una máquina sembrada por expediente.');
    }

    return [
        'machine_id'  => (int)$machine['machine_id'],
        'location_id' => (int)$machine['location_id'],
    ];
};

/** @return array{0: int, 1: string} Identificador y código de ticket. */
$createAssignedTicket = function (int $technicianId) use (
    $incidentRepo,
    $coordinatorId,
    $suffix,
    &$sequence,
    $nextMachine
): array {
    $sequence++;
    $machine = $nextMachine();
    $ticketCode = sprintf('TST-P13-%s-%02d', $suffix, $sequence);

    $created = $incidentRepo->create(new Incident(
        id: null,
        ticketCode: $ticketCode,
        machineId: $machine['machine_id'],
        locationId: $machine['location_id'],
        category: IncidentCategory::ELECTRICAL_OFF,
        description: 'Avería del banco de pruebas de los endpoints de coordinación del módulo 11.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));

    $incidentRepo->assign((int)$created->getId(), $technicianId, $coordinatorId);

    return [(int)$created->getId(), $ticketCode];
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

/** Reintegros propios de la suite, para reengancharlos antes del purgado dirigido. */
$refundIds    = [];
$refundOwners = [];

$pausePath    = static fn (int $id): string => "/api/coordinator/incidents/{$id}/pause-pending-info";
$resumePath   = static fn (int $id): string => "/api/coordinator/incidents/{$id}/resume-pending-info";
$cancelPath   = static fn (int $id): string => "/api/coordinator/incidents/{$id}/cancel-inactivity";
$assignPath   = static fn (int $id): string => "/api/coordinator/incidents/{$id}/assign";

$pauseBody   = static fn (string $category, string $text): array => [
    'reason_category' => $category,
    'reason_text'     => $text,
];
$validReason = 'La sede confirma por teléfono que el acceso quedará cerrado hasta mañana por obras en el rellano.';

try {
    // =========================================================================
    // GRUPO 1: Control de acceso del canal de coordinación (401 / 403)
    // =========================================================================
    echo "\n--- Grupo 1: Control de acceso del canal de coordinación (401 / 403) ---\n";

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath(1),
        parsedBody: $pauseBody('EXTERNAL_POWER_CUT', $validReason)
    ));
    $assert(
        '1.1 Pausa sin token => 401 UNAUTHORIZED',
        $res->getStatusCode() === 401 && ($res->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED'
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $cancelPath(1),
        parsedBody: ['cancellation_reason' => $validReason],
        headers: $authHeader('tok_invalido_xyz')
    ));
    $assert('1.2 Cancelación por inactividad con token inválido => 401 UNAUTHORIZED', $res->getStatusCode() === 401);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath(1),
        parsedBody: $pauseBody('EXTERNAL_POWER_CUT', $validReason),
        headers: $authHeader($technicianToken)
    ));
    $assert(
        '1.3 Técnico de ruta en el canal de coordinación => 403 FORBIDDEN',
        $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $resumePath(1),
        headers: $authHeader($technicianToken)
    ));
    $assert('1.4 La reanudación de coordinación también exige rol COORDINATOR', $res->getStatusCode() === 403);

    // =========================================================================
    // GRUPO 2: Declaración de pausa desde central (RF-01.1 a RF-01.4)
    // =========================================================================
    echo "\n--- Grupo 2: Pausa declarada por el coordinador ---\n";

    [$mainIncidentId, $mainTicketCode] = $createAssignedTicket($techId);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/coordinator/incidents/abc/pause-pending-info',
        parsedBody: $pauseBody('EXTERNAL_POWER_CUT', $validReason),
        headers: $authHeader($coordinatorToken)
    ));
    $assert(
        '2.1 Identificador no numérico => 400 INVALID_INCIDENT_ID',
        $res->getStatusCode() === 400 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_INCIDENT_ID'
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath(999999999),
        parsedBody: $pauseBody('EXTERNAL_POWER_CUT', $validReason),
        headers: $authHeader($coordinatorToken)
    ));
    $assert('2.2 Expediente inexistente => 404 INCIDENT_NOT_FOUND', $res->getStatusCode() === 404);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($mainIncidentId),
        parsedBody: ['reason_category' => 'EXTERNAL_POWER_CUT'],
        headers: $authHeader($coordinatorToken)
    ));
    $assert(
        '2.3 Cuerpo sin justificación => 400 MISSING_PAUSE_FIELDS',
        $res->getStatusCode() === 400 && ($res->getDecodedBody()['error']['code'] ?? '') === 'MISSING_PAUSE_FIELDS'
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($mainIncidentId),
        parsedBody: $pauseBody('NOISE_COMPLAINT', $validReason),
        headers: $authHeader($coordinatorToken)
    ));
    $assert(
        '2.4 Causa fuera del catálogo => 422 INVALID_PAUSE_REQUEST (RF-01.2)',
        $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_PAUSE_REQUEST'
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($mainIncidentId),
        parsedBody: $pauseBody('PENDING_SITE_AUTHORIZATION', 'Sin acceso a la sed'),
        headers: $authHeader($coordinatorToken)
    ));
    $assert(
        '2.5 Justificación de 19 caracteres reales => 422 (RF-01.3, Art. V.1)',
        $res->getStatusCode() === 422
    );

    // Pausa legal desde ASSIGNED sin pasar por la ruta técnica.
    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($mainIncidentId),
        parsedBody: $pauseBody('BUILDING_CLOSED_NO_ACCESS', '  ' . $validReason . '  '),
        headers: $authHeader($coordinatorToken)
    ));
    $data = $res->getDecodedBody()['data'] ?? [];
    $pausedRow = $incidentRow($mainIncidentId);
    $assert(
        '2.6 Pausa de coordinación declarada => 200 OK en PENDING_INFO',
        $res->getStatusCode() === 200
            && ($data['status'] ?? '') === 'PENDING_INFO'
            && ($data['is_sla_paused'] ?? false) === true
    );
    $assert(
        '2.7 La justificación viaja recortada y la causa con su etiqueta',
        ($data['reason_text'] ?? '') === $validReason
            && ($data['reason_category_label'] ?? '') === 'Edificio cerrado / Sin acceso a instalaciones'
    );
    $assert(
        '2.8 Persistencia real de la pausa declarada desde central',
        ($pausedRow['status'] ?? '') === 'PENDING_INFO'
            && ($pausedRow['paused_at'] ?? null) !== null
            && ($pausedRow['pending_info_reason_category'] ?? '') === 'BUILDING_CLOSED_NO_ACCESS'
    );

    $pauseRows = array_values(array_filter(
        $historyRows($mainIncidentId),
        static fn (array $row): bool => $row['to_status'] === 'PENDING_INFO'
    ));
    $assert(
        '2.9 El rastro inmutable sella al coordinador y su estado de origen',
        count($pauseRows) === 1
            && ($pauseRows[0]['from_status'] ?? '') === 'ASSIGNED'
            && (int)($pauseRows[0]['user_id'] ?? 0) === $coordinatorId
    );

    // Doble pausa prohibida (RF-06.2): el expediente ya está en PENDING_INFO.
    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($mainIncidentId),
        parsedBody: $pauseBody('EXTERNAL_POWER_CUT', 'Segundo intento de pausa sobre un expediente ya pausado.'),
        headers: $authHeader($coordinatorToken)
    ));
    $rowAfterDoublePause = $incidentRow($mainIncidentId);
    $assert(
        '2.10 Doble pausa => 422 INVALID_STATUS_FOR_PAUSE sin reescribir la bitácora',
        $res->getStatusCode() === 422
            && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_STATUS_FOR_PAUSE'
            && ($rowAfterDoublePause['paused_at'] ?? '') === ($pausedRow['paused_at'] ?? null)
            && count($pauseRows) === 1
    );

    // Pausa legal desde PENDING_PARTS (punto 9 del QA): el técnico espera repuesto.
    [$partsIncidentId] = $createAssignedTicket($techId);
    $incidentRepo->startIntervention($partsIncidentId, $techId);
    $incidentRepo->pauseIntervention($partsIncidentId, $techId, 'Rodamiento del módulo de pago pedido al almacén central.');
    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($partsIncidentId),
        parsedBody: $pauseBody('PENDING_SITE_AUTHORIZATION', 'La sede aún no autoriza el acceso al cuarto de máquinas.'),
        headers: $authHeader($coordinatorToken)
    ));
    $partsPauseRows = array_values(array_filter(
        $historyRows($partsIncidentId),
        static fn (array $row): bool => $row['to_status'] === 'PENDING_INFO'
    ));
    $assert(
        '2.11 Pausa desde PENDING_PARTS => 200 con origen real auditado',
        $res->getStatusCode() === 200 && ($partsPauseRows[0]['from_status'] ?? '') === 'PENDING_PARTS'
    );

    // =========================================================================
    // GRUPO 3: Reanudación manual desde central con destino elegido (RF-02.2, RF-02.3)
    // =========================================================================
    echo "\n--- Grupo 3: Reanudación de coordinación con destino elegido ---\n";

    // Sin `target_status`: el valor por defecto es ASSIGNED (el técnico debe desplazarse).
    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $resumePath($mainIncidentId),
        parsedBody: ['resume_note' => 'Conserjería confirma apertura a primera hora de la mañana.'],
        headers: $authHeader($coordinatorToken)
    ));
    $defaultData = $res->getDecodedBody()['data'] ?? [];
    $assert(
        '3.1 Reanudación sin destino explícito => 200 OK en ASSIGNED',
        $res->getStatusCode() === 200
            && ($defaultData['status'] ?? '') === 'ASSIGNED'
            && ($defaultData['status_label'] ?? '') === 'Asignada'
            && ($defaultData['is_sla_paused'] ?? true) === false
    );
    $assert(
        '3.2 El mensaje indica que el técnico debe personarse',
        str_contains((string)($res->getDecodedBody()['message'] ?? ''), 'personarse')
    );

    $resumedRow = $incidentRow($mainIncidentId);
    $assert(
        '3.3 La pausa queda cerrada en base de datos con su acumulador',
        array_key_exists('paused_at', $resumedRow)
            && $resumedRow['paused_at'] === null
            && ($resumedRow['status'] ?? '') === 'ASSIGNED'
    );

    $resumeRows = array_values(array_filter(
        $historyRows($mainIncidentId),
        static fn (array $row): bool => $row['from_status'] === 'PENDING_INFO' && $row['to_status'] === 'ASSIGNED'
    ));
    $assert(
        '3.4 El rastro registra la reanudación desfasada con el coordinador como actor',
        count($resumeRows) === 1
            && (int)($resumeRows[0]['user_id'] ?? 0) === $coordinatorId
            && str_contains((string)($resumeRows[0]['action_note'] ?? ''), ' Duración del intervalo de pausa: ')
    );

    // Destino explícito IN_PROGRESS: coordinación confirma que el técnico ya está en la máquina.
    [$inProgressId] = $createAssignedTicket($techId);
    $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($inProgressId),
        parsedBody: $pauseBody('MACHINE_LOCATION_NOT_FOUND', 'La máquina no aparece en la planta indicada por la sede.'.PHP_EOL),
        headers: $authHeader($coordinatorToken)
    ));

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $resumePath($inProgressId),
        parsedBody: ['target_status' => 'in_progress'],
        headers: $authHeader($coordinatorToken)
    ));
    $assert(
        '3.5 Destino explícito IN_PROGRESS (normalizado) => 200 en curso',
        $res->getStatusCode() === 200 && ($res->getDecodedBody()['data']['status'] ?? '') === 'IN_PROGRESS'
    );

    // Destino prohibido: RESOLVED exige intervención física documentada (RF-06.2, Art. V.1).
    [$guardIncidentId] = $createAssignedTicket($techId);
    $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($guardIncidentId),
        parsedBody: $pauseBody('EXTERNAL_POWER_CUT', 'Corte de suministro ajeno a la máquina según la sede.'),
        headers: $authHeader($coordinatorToken)
    ));
    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $resumePath($guardIncidentId),
        parsedBody: ['target_status' => 'RESOLVED'],
        headers: $authHeader($coordinatorToken)
    ));
    $guardRow = $incidentRow($guardIncidentId);
    $assert(
        '3.6 Destino RESOLVED => 422 INVALID_RESUME_TARGET con la pausa intacta',
        $res->getStatusCode() === 422
            && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_RESUME_TARGET'
            && ($guardRow['status'] ?? '') === 'PENDING_INFO'
            && ($guardRow['paused_at'] ?? null) !== null
    );

    // Expediente sin pausa abierta.
    [$idleIncidentId] = $createAssignedTicket($techId);
    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $resumePath($idleIncidentId),
        headers: $authHeader($coordinatorToken)
    ));
    $assert(
        '3.7 Reanudar un expediente sin pausa => 422 INVALID_STATUS_FOR_RESUME sin escrituras',
        $res->getStatusCode() === 422
            && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_STATUS_FOR_RESUME'
            && ($incidentRow($idleIncidentId)['status'] ?? '') === 'ASSIGNED'
    );
    $assert(
        '3.8 Identificador inválido y expediente inexistente en la reanudación',
        $router->dispatch(new Request(method: 'POST', path: '/api/coordinator/incidents/xyz/resume-pending-info', headers: $authHeader($coordinatorToken)))->getStatusCode() === 400
            && $router->dispatch(new Request(method: 'POST', path: $resumePath(999999999), headers: $authHeader($coordinatorToken)))->getStatusCode() === 404
    );

    // =========================================================================
    // GRUPO 4: Cancelación por inactividad con protocolo completo (RF-04.3 a RF-04.5)
    // =========================================================================
    echo "\n--- Grupo 4: Cancelación por inactividad de 72 h hábiles ---\n";

    $auditCount = function (string $action, int $entityId, string $entityType = 'TICKET') use ($pdo): int {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM `audit_log` WHERE `action` = :action AND `entity_type` = :type AND `entity_id` = :entity');
        $stmt->execute([':action' => $action, ':type' => $entityType, ':entity' => $entityId]);

        return (int)$stmt->fetchColumn();
    };

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $cancelPath($guardIncidentId),
        parsedBody: [],
        headers: $authHeader($coordinatorToken)
    ));
    $assert(
        '4.1 Motivo ausente => 422 MISSING_CANCELLATION_REASON',
        $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'MISSING_CANCELLATION_REASON'
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $cancelPath($guardIncidentId),
        parsedBody: ['cancellation_reason' => 'Sin acceso'],
        headers: $authHeader($coordinatorToken)
    ));
    $assert(
        '4.2 Motivo de menos de 20 caracteres reales => 422 sin tocar el expediente',
        $res->getStatusCode() === 422
            && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_CANCELLATION_REASON'
            && ($incidentRow($guardIncidentId)['status'] ?? '') === 'PENDING_INFO'
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $cancelPath(999999999),
        parsedBody: ['cancellation_reason' => $validReason],
        headers: $authHeader($coordinatorToken)
    ));
    $assert('4.3 Expediente inexistente => 404 INCIDENT_NOT_FOUND', $res->getStatusCode() === 404);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $cancelPath($idleIncidentId),
        parsedBody: ['cancellation_reason' => 'Cierre administrativo por inactividad prolongada de la sede.'.PHP_EOL],
        headers: $authHeader($coordinatorToken)
    ));
    $assert(
        '4.4 Cancelar un expediente que no está en pausa => 422 INVALID_STATUS_FOR_INACTIVITY_CANCELLATION',
        $res->getStatusCode() === 422
            && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_STATUS_FOR_INACTIVITY_CANCELLATION'
            && ($incidentRow($idleIncidentId)['status'] ?? '') === 'ASSIGNED'
    );

    // Reintegro del consumidor en inspección: debe sobrevivir a la cancelación de la avería.
    $guardMachineId = (int)($incidentRow($guardIncidentId)['machine_id'] ?? 0);
    $guardLocationId = (int)($incidentRow($guardIncidentId)['location_id'] ?? 0);
    $pdo->prepare("
        INSERT INTO `refund_requests` (
            `incident_id`, `machine_id`, `location_id`, `claimant_name`, `claimant_contact`,
            `claimed_amount`, `product_attempted`, `compensation_method`, `bizum_phone`,
            `tracking_token`, `status`
        ) VALUES (
            :incident, :machine, :location, 'Consumidor de la suite de coordinación', '600000022',
            3.20, 'Sándwich de pavo', 'BIZUM', '600000022',
            :token, 'PENDING_INSPECTION'
        )
    ")->execute([
        ':incident' => $guardIncidentId,
        ':machine'  => $guardMachineId,
        ':location' => $guardLocationId,
        ':token'    => 'tstp13-' . $suffix,
    ]);
    $refundId = (int)$pdo->lastInsertId();
    $refundIds[] = $refundId;
    $refundOwners[$refundId] = $guardIncidentId;
    $assert('4.5 Reintegro de partida vinculado a la avería en inspección', $refundId > 0);

    // La cancelación se ejecuta aunque la pausa sea reciente: «facultar» no es un cerrojo
    // (decisión anotada en T-PAUSE-09) y la decisión sigue siendo humana y justificada.
    //
    // La cuenta de rastros se toma como DELTA sobre la foto previa: `audit_log` es
    // append-only y el arnés no la purga (inviolabilidad del Art. III), así que una
    // corrida anterior puede dejar filas huérfanas que reutilicen el mismo identificador
    // de expediente tras el resembrado; contar por identificador a secas medía esas
    // sobras ajenas además de las de esta suite.
    $auditBaseline = [
        'incident' => $auditCount('INCIDENT_CANCELLED', $guardIncidentId),
        'machine'  => $auditCount('MACHINE_BLOCKED_NO_ACCESS', $guardMachineId, 'MACHINE'),
        'refund'   => $auditCount('REFUND_DETACHED_BY_INACTIVITY', $refundId, 'REFUND_REQUEST'),
    ];

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $cancelPath($guardIncidentId),
        parsedBody: ['cancellation_reason' => 'Cierre administrativo por inactividad y falta de acceso del cliente tras el plazo hábil.'.PHP_EOL],
        headers: $authHeader($coordinatorToken)
    ));
    $cancelledData = $res->getDecodedBody()['data'] ?? [];
    $assert(
        '4.6 Cancelación por inactividad => 200 con el protocolo confirmado',
        $res->getStatusCode() === 200
            && ($cancelledData['status'] ?? '') === 'CANCELLED'
            && ($cancelledData['machine']['is_blocked_no_access'] ?? false) === true
            && ($cancelledData['refunds_preserved'] ?? false) === true
    );

    $cancelledRow = $incidentRow($guardIncidentId);
    $assert(
        '4.7 El ticket queda cancelado con su motivo y fecha (RF-04.3)',
        ($cancelledRow['status'] ?? '') === 'CANCELLED'
            && ($cancelledRow['cancelled_at'] ?? null) !== null
            && str_contains((string)($cancelledRow['cancellation_reason'] ?? ''), 'Cierre administrativo')
    );

    $machineRow = $pdo->query('SELECT `is_active`, `is_blocked_no_access` FROM `machines` WHERE `id` = ' . $guardMachineId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        '4.8 La máquina NO vuelve a Operativa: fuera de servicio por falta de acceso (RF-04.4, Art. V.1)',
        (int)($machineRow['is_active'] ?? 1) === 0 && (int)($machineRow['is_blocked_no_access'] ?? 0) === 1
            && ($cancelledData['machine']['operational_status'] ?? '') === 'BLOCKED_NO_ACCESS'
    );

    $refundRow = $pdo->query('SELECT * FROM `refund_requests` WHERE `id` = ' . $refundId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        '4.9 El reintegro del consumidor se preserva desvinculado y activo (RF-04.5)',
        array_key_exists('incident_id', $refundRow)
            && $refundRow['incident_id'] === null
            && ($refundRow['status'] ?? '') === 'REQUIRES_COORDINATOR_APPROVAL'
            && (int)($refundRow['is_active'] ?? 0) === 1
    );

    $assert(
        '4.10 El protocolo deja sus tres rastros inmutables (Art. III)',
        $auditCount('INCIDENT_CANCELLED', $guardIncidentId) - $auditBaseline['incident'] === 1
            && $auditCount('MACHINE_BLOCKED_NO_ACCESS', $guardMachineId, 'MACHINE') - $auditBaseline['machine'] === 1
            && $auditCount('REFUND_DETACHED_BY_INACTIVITY', $refundId, 'REFUND_REQUEST') - $auditBaseline['refund'] === 1
    );
    $assert(
        '4.11 El hilo del expediente cancelado queda sellado en solo lectura (RF-05.4)',
        (new VendGuard\Application\Service\IncidentCommentService($incidentRepo))->acceptsNewComments(['status' => 'CANCELLED']) === false
    );

    // =========================================================================
    // GRUPO 5: Reasignación en pausa que conserva el bloqueo (RF-06.3)
    // =========================================================================
    echo "\n--- Grupo 5: Reasignación de un expediente en PENDING_INFO (RF-06.3) ---\n";

    $beforeReassignment = $incidentRow($partsIncidentId);
    $res = $router->dispatch(new Request(
        method: 'PATCH',
        path: $assignPath($partsIncidentId),
        parsedBody: [
            'technician_id'       => $otherTechId,
            'reassignment_reason' => 'Reasignación por cercanía: el nuevo técnico cubre la zona donde está el edificio bloqueado.',
        ],
        headers: $authHeader($coordinatorToken)
    ));
    $reassignedData = $res->getDecodedBody()['data'] ?? [];
    $afterReassignment = $incidentRow($partsIncidentId);
    $assert(
        '5.1 Reasignación técnica de un expediente pausado => 200 OK',
        $res->getStatusCode() === 200 && (int)($afterReassignment['assigned_technician_id'] ?? 0) === $otherTechId
    );
    $assert(
        '5.2 El estado PENDING_INFO se conserva con el nuevo responsable (RF-06.3)',
        ($reassignedData['status'] ?? '') === 'PENDING_INFO'
            && ($afterReassignment['status'] ?? '') === 'PENDING_INFO'
            && (int)($afterReassignment['assigned_technician_id'] ?? 0) !== $techId
    );
    $assert(
        '5.3 El nuevo técnico hereda el contexto y el motivo del bloqueo intactos',
        ($afterReassignment['paused_at'] ?? '') === ($beforeReassignment['paused_at'] ?? null)
            && ($afterReassignment['pending_info_reason_category'] ?? '') === ($beforeReassignment['pending_info_reason_category'] ?? null)
            && ($afterReassignment['pending_info_reason_text'] ?? '') === ($beforeReassignment['pending_info_reason_text'] ?? null)
            && (int)($afterReassignment['total_pending_info_seconds'] ?? -1) === (int)($beforeReassignment['total_pending_info_seconds'] ?? 0)
    );

    $structuralRows = array_values(array_filter(
        $historyRows($partsIncidentId),
        static fn (array $row): bool => $row['from_status'] === 'PENDING_INFO' && $row['to_status'] === 'PENDING_INFO'
    ));
    $assert(
        '5.4 La huella estructural de la reasignación en pausa queda auditada con su motivo',
        count($structuralRows) === 1
            && str_contains((string)($structuralRows[0]['action_note'] ?? ''), ' Motivo: ')
            && (int)($structuralRows[0]['user_id'] ?? 0) === $coordinatorId
    );

    // La huella ya es alcanzable en producción: la respuesta de sede sobre un expediente
    // reasignado durante la pausa devuelve la avería a ASSIGNED (RF-02.1) en lugar de
    // afirmar que el nuevo técnico está delante de la máquina.
    $pauseService = new IncidentPauseService(
        incidentRepo: $incidentRepo,
        machineRepo: $machineRepo
    );
    $reactivation = $pauseService->handleSiteCommentReactivation(
        $partsIncidentId,
        'La sede confirma que el conserje ya ha abierto el cuarto de máquinas.',
        1
    );
    $assert(
        '5.5 La reactivación por comentario de sede detecta la reasignación y devuelve ASSIGNED',
        $reactivation->status === IncidentStatus::ASSIGNED
            && ($incidentRow($partsIncidentId)['status'] ?? '') === 'ASSIGNED'
    );

} finally {
    echo "\n--- Limpieza de los expedientes propios de la suite ---\n";
    foreach ($refundIds as $refundId) {
        $owner = $refundOwners[$refundId] ?? null;
        if ($owner === null) {
            continue;
        }

        // Los reintegros desvinculados quedan con `incident_id = NULL`: se reenganchan a su
        // avería para que el purgado dirigido los arrastre como hijas dentro de la ventana
        // que abre `TestDataCleaner` (mismo procedimiento que las suites de T-PAUSE-09/11).
        $pdo->prepare('UPDATE `refund_requests` SET `incident_id` = :incident WHERE `id` = :refund')
            ->execute([':incident' => $owner, ':refund' => $refundId]);
    }

    $purged = TestDataCleaner::purgeIncidentsMatchingTicket($pdo, 'TST-P13-%');
    if ($guardMachineId > 0) {
        $pdo->prepare('UPDATE `machines` SET `is_active` = 1, `is_blocked_no_access` = 0 WHERE `id` = :id')
            ->execute([':id' => $guardMachineId]);
    }
    echo '  [OK] Expedientes purgados con TestDataCleaner (patrón TST-P13-%) · filas de incidencias eliminadas: '
        . array_sum($purged) . " (máquinas y sedes intactas)\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-13 CUMPLIDA.\n"
    : " RESULTADO: HAY FALLOS. CONDICIÓN T-PAUSE-13 NO CUMPLIDA.\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
