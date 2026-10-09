<?php

declare(strict_types=1);

/**
 * PendingInfoTechnicianEndpointsTest (T-PAUSE-12)
 *
 * Verifica la condición "Hecho cuando" de T-PAUSE-12 contra MariaDB real, a través del
 * enrutador y de los middlewares de verdad (no se invoca el controlador a mano):
 *
 *   - `TechnicianController::pausePendingInfo()` en
 *     POST /api/technician/incidents/{id}/pause-pending-info.
 *   - `TechnicianController::resumePendingInfo()` en
 *     POST /api/technician/incidents/{id}/resume-pending-info.
 *   - Códigos del contrato formal de `plan.md` §2.1/§2.2: 200, 400, 401, 403, 404 y 422.
 *   - Autorización: middleware TECHNICIAN y un único técnico responsable activo (Art. V.2).
 *   - Persistencia real: estado `PENDING_INFO`, `paused_at`, causa tipificada, justificación
 *     de al menos 20 caracteres reales (Art. V.1), acumulador de segundos, desplazamiento
 *     comercial del vencimiento (RF-03.3) y rastro inmutable de solo adición en
 *     `incident_history` (Art. III).
 *
 * Cada expediente del banco de pruebas estrena máquina sembrada (la clave única condicional
 * `uq_machine_active_ticket` impide dos tickets activos por equipo) y todos se purgan con
 * `TestDataCleaner`, que es el único camino autorizado para borrar incidencias. Las filas
 * maestras (sedes, máquinas, usuarios) sólo se LEEN.
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

echo "======================================================================\n";
echo " VendGuard: Integración - PendingInfoTechnicianEndpointsTest (T-PAUSE-12)\n";
echo "======================================================================\n\n";

// ─── Bootstrap: base limpia y sembrada ──────────────────────────────────────────
$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$router       = AppRouter::create();
$incidentRepo = new PdoIncidentRepository($pdo);
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

// ─── Usuarios, tokens y flota sembrada ─────────────────────────────────────────
$technicianRows = $pdo->query(
    "SELECT `id` FROM `users` WHERE `role` = 'TECHNICIAN' AND `deleted_at` IS NULL ORDER BY `id` LIMIT 2"
)->fetchAll(PDO::FETCH_COLUMN);

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$primaryTech = isset($technicianRows[0]) ? $userRepo->findById((int)$technicianRows[0]) : null;
$otherTech   = isset($technicianRows[1]) ? $userRepo->findById((int)$technicianRows[1]) : null;

$assert(
    '0.1 Semilla disponible: coordinador y dos técnicos distintos',
    $coordinator instanceof User && $primaryTech instanceof User && $otherTech instanceof User,
    'Sin dos técnicos no se puede probar la guarda de asignación única (Art. V.2).'
);

if (!$coordinator instanceof User || !$primaryTech instanceof User || !$otherTech instanceof User) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

// Flota sembrada libre de tickets activos: la clave única condicional
// `uq_machine_active_ticket` impide dos averías vivas sobre el mismo equipo, así que
// cada expediente del banco de pruebas estrena máquina y hereda la sede real de ésta.
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
    sprintf('El parque sembrado expone %d máquinas libres; la suite estrena una por expediente.', count($machinePool))
);

if (count($machinePool) < 8) {
    echo "ERROR FATAL: flota sembrada insuficiente para el banco de pruebas.\n";
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken  = $authService->generateInternalToken($primaryTech);
$otherTechToken   = $authService->generateInternalToken($otherTech);
$techId           = (int)$primaryTech->getId();
$otherTechId      = (int)$otherTech->getId();
$coordinatorId    = (int)$coordinator->getId();
$authHeader       = static fn (string $token): array => ['Authorization' => 'Bearer ' . $token];

$suffix   = strtoupper(bin2hex(random_bytes(3)));
$sequence = 0;

/**
 * Estrena la siguiente máquina libre del parque, junto con su sede real.
 *
 * @return array{machine_id: int, location_id: int}
 */
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

/**
 * Crea un expediente propio de la suite y lo asigna al técnico indicado.
 *
 * @return array{0: int, 1: string} Identificador y código de ticket.
 */
$createAssignedTicket = function (int $technicianId) use (
    $incidentRepo,
    $coordinatorId,
    $suffix,
    &$sequence,
    $nextMachine
): array {
    $sequence++;
    $machine = $nextMachine();
    $ticketCode = sprintf('TST-P12-%s-%02d', $suffix, $sequence);

    $created = $incidentRepo->create(new Incident(
        id: null,
        ticketCode: $ticketCode,
        machineId: $machine['machine_id'],
        locationId: $machine['location_id'],
        category: IncidentCategory::ELECTRICAL_OFF,
        description: 'Avería del banco de pruebas de los endpoints de pausa del módulo 11.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));

    $incidentRepo->assign((int)$created->getId(), $technicianId, $coordinatorId);

    return [(int)$created->getId(), $ticketCode];
};

/** Fila cruda del expediente, para verificar lo que quedó persistido. */
$incidentRow = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM `incidents` WHERE `id` = :id');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

/** Filas de historial inmutable del expediente, en orden de escritura (Art. III). */
$historyRows = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM `incident_history` WHERE `incident_id` = :id ORDER BY `id` ASC');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$pauseBody = static fn (string $category, string $text): array => [
    'reason_category' => $category,
    'reason_text'     => $text,
];

$validReason = 'El edificio de consultas externas está cerrado por festivo local; conserjería sin personal.';

$pausePath  = static fn (int $id): string => "/api/technician/incidents/{$id}/pause-pending-info";
$resumePath = static fn (int $id): string => "/api/technician/incidents/{$id}/resume-pending-info";

/** Cuenta las filas de pausa declaradas en la bitácora inmutable del expediente. */
$pauseEvents = static function (array $history): int {
    return count(array_filter(
        $history,
        static fn (array $row): bool => $row['to_status'] === IncidentStatus::PENDING_INFO->value
    ));
};

try {
    // =========================================================================
    // GRUPO 1: Control de acceso y alcance del técnico (401 / 403)
    // =========================================================================
    echo "\n--- Grupo 1: Control de acceso y alcance del técnico (401 / 403) ---\n";

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
        path: $pausePath(1),
        parsedBody: $pauseBody('EXTERNAL_POWER_CUT', $validReason),
        headers: $authHeader('tok_invalido_xyz')
    ));
    $assert('1.2 Pausa con token inválido => 401 UNAUTHORIZED', $res->getStatusCode() === 401);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath(1),
        parsedBody: $pauseBody('EXTERNAL_POWER_CUT', $validReason),
        headers: $authHeader($coordinatorToken)
    ));
    $assert(
        '1.3 Coordinador en la ruta del técnico => 403 FORBIDDEN',
        $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
    );

    $res = $router->dispatch(new Request(method: 'POST', path: $resumePath(1), headers: $authHeader('tok_invalido_xyz')));
    $assert('1.4 Reanudación sin sesión válida => 401 UNAUTHORIZED', $res->getStatusCode() === 401);

    // Expediente principal: asignado al técnico titular, se reutiliza en los grupos 2 a 5.
    [$mainIncidentId, $mainTicketCode] = $createAssignedTicket($techId);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($mainIncidentId),
        parsedBody: $pauseBody('EXTERNAL_POWER_CUT', $validReason),
        headers: $authHeader($otherTechToken)
    ));
    $rowAfterForeignAttempt = $incidentRow($mainIncidentId);
    $assert(
        '1.5 Técnico ajeno al expediente => 403 FORBIDDEN',
        $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
    );
    $assert(
        '1.6 El rechazo por asignación no escribe nada en la pausa',
        $rowAfterForeignAttempt['status'] === IncidentStatus::ASSIGNED->value
            && $rowAfterForeignAttempt['paused_at'] === null
            && $rowAfterForeignAttempt['pending_info_reason_text'] === null,
        'El expediente quedó en ' . ($rowAfterForeignAttempt['status'] ?? 'N/A')
    );
    $assert(
        '1.7 Un técnico ajeno tampoco puede reanudar la pausa de otro (Art. V.2)',
        $router->dispatch(new Request(
            method: 'POST',
            path: $resumePath($mainIncidentId),
            headers: $authHeader($otherTechToken)
        ))->getStatusCode() === 403
    );

    // Expediente cerrado: la pausa no debe poder reabrir un ticket terminal.
    [$terminalIncidentId] = $createAssignedTicket($techId);
    $incidentRepo->cancel(
        $terminalIncidentId,
        'Cancelación del banco de pruebas: expediente sellado para probar el rechazo de la pausa.',
        $coordinatorId
    );
    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($terminalIncidentId),
        parsedBody: $pauseBody('PENDING_SITE_AUTHORIZATION', $validReason),
        headers: $authHeader($technicianToken)
    ));
    $assert(
        '1.8 Expediente en estado terminal => 403 FORBIDDEN',
        $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
    );
    $assert(
        '1.9 El rechazo del expediente terminal no altera su estado',
        ($incidentRow($terminalIncidentId)['status'] ?? '') === IncidentStatus::CANCELLED->value
    );

    // =========================================================================
    // GRUPO 2: Validación de entrada (400 / 404 / 422)
    // =========================================================================
    echo "\n--- Grupo 2: Validación de entrada (400 / 404 / 422) ---\n";

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/incidents/abc/pause-pending-info',
        parsedBody: $pauseBody('EXTERNAL_POWER_CUT', $validReason),
        headers: $authHeader($technicianToken)
    ));
    $assert(
        '2.1 Identificador no numérico en la URL => 400 INVALID_INCIDENT_ID',
        $res->getStatusCode() === 400 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_INCIDENT_ID'
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath(999999999),
        parsedBody: $pauseBody('EXTERNAL_POWER_CUT', $validReason),
        headers: $authHeader($technicianToken)
    ));
    $assert(
        '2.2 Expediente inexistente => 404 INCIDENT_NOT_FOUND',
        $res->getStatusCode() === 404 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INCIDENT_NOT_FOUND'
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($mainIncidentId),
        parsedBody: [],
        headers: $authHeader($technicianToken)
    ));
    $assert(
        '2.3 Cuerpo vacío => 400 MISSING_PAUSE_FIELDS',
        $res->getStatusCode() === 400 && ($res->getDecodedBody()['error']['code'] ?? '') === 'MISSING_PAUSE_FIELDS'
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($mainIncidentId),
        parsedBody: ['reason_category' => 'EXTERNAL_POWER_CUT'],
        headers: $authHeader($technicianToken)
    ));
    $assert(
        '2.4 Falta la justificación (reason_text) => 400 MISSING_PAUSE_FIELDS',
        $res->getStatusCode() === 400 && ($res->getDecodedBody()['error']['code'] ?? '') === 'MISSING_PAUSE_FIELDS'
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($mainIncidentId),
        parsedBody: $pauseBody('NOISE_COMPLAINT', $validReason),
        headers: $authHeader($technicianToken)
    ));
    $assert(
        '2.5 Causa fuera del catálogo cerrado => 422 INVALID_PAUSE_REQUEST (RF-01.2)',
        $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_PAUSE_REQUEST'
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($mainIncidentId),
        parsedBody: $pauseBody('BUILDING_CLOSED_NO_ACCESS', 'Sin acceso a la sed'),
        headers: $authHeader($technicianToken)
    ));
    $assert(
        '2.6 Justificación de 19 caracteres reales => 422 INVALID_PAUSE_REQUEST (RF-01.3, Art. V.1)',
        $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_PAUSE_REQUEST'
    );

    $rowBeforeValidPause = $incidentRow($mainIncidentId);
    $assert(
        '2.7 Los rechazos de validación no tocaron la pausa del expediente',
        $rowBeforeValidPause['status'] === IncidentStatus::ASSIGNED->value
            && $rowBeforeValidPause['paused_at'] === null
            && $pauseEvents($historyRows($mainIncidentId)) === 0
    );

    // =========================================================================
    // GRUPO 3: Declaración de pausa (200) y persistencia real (RF-01)
    // =========================================================================
    echo "\n--- Grupo 3: Declaración de pausa y persistencia real (RF-01) ---\n";

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($mainIncidentId),
        parsedBody: $pauseBody('BUILDING_CLOSED_NO_ACCESS', '  ' . $validReason . '  '),
        headers: $authHeader($technicianToken)
    ));
    $payload = $res->getDecodedBody();
    $data = $payload['data'] ?? [];

    $assert('3.1 Pausa declarada por el técnico asignado => 200 OK', $res->getStatusCode() === 200 && ($payload['success'] ?? false) === true);
    $assert('3.2 El mensaje anuncia el reloj contractual congelado', str_contains((string)($payload['message'] ?? ''), 'congelado'));
    $assert('3.3 El expediente queda en PENDING_INFO con su etiqueta', ($data['status'] ?? '') === 'PENDING_INFO' && ($data['status_label'] ?? '') === 'Pendiente de información');
    $assert('3.4 El reloj de SLA viaja congelado y sin minutos previos', ($data['is_sla_paused'] ?? false) === true && (int)($data['accumulated_pause_minutes'] ?? -1) === 0);
    $assert('3.5 La marca de la pausa viva viaja en la respuesta', is_string($data['paused_at'] ?? null) && $data['paused_at'] !== '');
    $assert('3.6 La causa tipificada viaja con su etiqueta castellana', ($data['reason_category'] ?? '') === 'BUILDING_CLOSED_NO_ACCESS' && ($data['reason_category_label'] ?? '') === 'Edificio cerrado / Sin acceso a instalaciones');
    $assert('3.7 La justificación viaja recortada (sin espacios sobrantes)', ($data['reason_text'] ?? '') === $validReason);
    $assert('3.8 El contrato emite ambos extremos del vencimiento de SLA', array_key_exists('sla_target_at_original', $data) && array_key_exists('sla_target_at_shifted', $data));

    $pausedRow = $incidentRow($mainIncidentId);
    $machineActive = $pdo->query('SELECT `is_active` FROM `machines` WHERE `id` = ' . (int)$pausedRow['machine_id'])->fetchColumn();
    $assert('3.9 Persistencia: estado PENDING_INFO con pausa viva', ($pausedRow['status'] ?? '') === 'PENDING_INFO' && ($pausedRow['paused_at'] ?? null) !== null);
    $assert('3.10 Persistencia: causa tipificada y justificación íntegra', ($pausedRow['pending_info_reason_category'] ?? '') === 'BUILDING_CLOSED_NO_ACCESS' && ($pausedRow['pending_info_reason_text'] ?? '') === $validReason);
    $assert('3.11 Persistencia: el acumulador arranca en cero (intervalo vivo)', (int)($pausedRow['total_pending_info_seconds'] ?? -1) === 0);
    $assert('3.12 La pausa no da de baja la máquina del parque', (int)$machineActive === 1);

    $pauseRows = array_values(array_filter($historyRows($mainIncidentId), static fn (array $row): bool => $row['to_status'] === 'PENDING_INFO'));
    $pauseNote = (string)($pauseRows[0]['action_note'] ?? '');
    $assert('3.13 El rastro inmutable registra la pausa con su estado de origen', count($pauseRows) === 1 && ($pauseRows[0]['from_status'] ?? '') === 'ASSIGNED');
    $assert('3.14 El rastro sella al técnico que declara la pausa (Art. III)', (int)($pauseRows[0]['user_id'] ?? 0) === $techId);
    $assert('3.15 La nota del rastro documenta causa y justificación', str_contains($pauseNote, ' Causa: ') && str_contains($pauseNote, $validReason));

    // =========================================================================
    // GRUPO 4: La doble pausa está prohibida (RF-06.2)
    // =========================================================================
    echo "\n--- Grupo 4: Doble pausa rechazada sin reescribir la bitácora (RF-06.2) ---\n";

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($mainIncidentId),
        parsedBody: $pauseBody('EXTERNAL_POWER_CUT', 'Segundo intento de pausa sobre el mismo expediente ya pausado.'),
        headers: $authHeader($technicianToken)
    ));
    $assert(
        '4.1 Doble pausa (PENDING_INFO -> PENDING_INFO) => 422 INVALID_STATUS_FOR_PAUSE',
        $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_STATUS_FOR_PAUSE'
    );
    $rowAfterDoublePause = $incidentRow($mainIncidentId);
    $assert(
        '4.2 La pausa original queda intacta',
        ($rowAfterDoublePause['paused_at'] ?? '') === ($pausedRow['paused_at'] ?? null)
            && ($rowAfterDoublePause['pending_info_reason_category'] ?? '') === 'BUILDING_CLOSED_NO_ACCESS'
    );
    $assert('4.3 El rechazo no añade un segundo rastro de pausa', $pauseEvents($historyRows($mainIncidentId)) === 1);

    // =========================================================================
    // GRUPO 5: Reanudación manual in situ (RF-02.2, RF-02.3)
    // =========================================================================
    echo "\n--- Grupo 5: Reanudación manual in situ (RF-02.2, RF-02.3) ---\n";

    // Intervalo real no nulo: RNF-01 exige precisión de segundos en el acumulador.
    sleep(2);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $resumePath($mainIncidentId),
        parsedBody: ['resume_note' => 'El conserje ha abierto la sala donde está la máquina.'],
        headers: $authHeader($technicianToken)
    ));
    $resumePayload = $res->getDecodedBody();
    $resumeData = $resumePayload['data'] ?? [];
    $assert('5.1 Reanudación del técnico in situ => 200 OK', $res->getStatusCode() === 200 && ($resumePayload['success'] ?? false) === true);
    $assert(
        '5.2 El expediente vuelve a "En curso" con el reloj reactivado',
        ($resumeData['status'] ?? '') === 'IN_PROGRESS' && ($resumeData['status_label'] ?? '') === 'En curso' && ($resumeData['is_sla_paused'] ?? true) === false
    );
    $assert('5.3 La pausa viva se cierra en la respuesta', array_key_exists('paused_at', $resumeData) && $resumeData['paused_at'] === null);

    $resumedRow = $incidentRow($mainIncidentId);
    $accumulatedSeconds = (int)($resumedRow['total_pending_info_seconds'] ?? 0);
    $assert(
        '5.4 Persistencia: pausa cerrada, "En curso" y acumulador real (2 a 8 s)',
        array_key_exists('paused_at', $resumedRow) && $resumedRow['paused_at'] === null
            && ($resumedRow['status'] ?? '') === 'IN_PROGRESS'
            && $accumulatedSeconds >= 2
            && $accumulatedSeconds <= 8,
        'Acumulado: ' . $accumulatedSeconds . ' s'
    );
    $assert(
        '5.5 La causa del bloqueo se conserva como último motivo conocido',
        ($resumedRow['pending_info_reason_category'] ?? '') === 'BUILDING_CLOSED_NO_ACCESS'
    );

    $resumeRows = array_values(array_filter(
        $historyRows($mainIncidentId),
        static fn (array $row): bool => $row['from_status'] === 'PENDING_INFO' && $row['to_status'] === 'IN_PROGRESS'
    ));
    $resumeNote = (string)($resumeRows[0]['action_note'] ?? '');
    $assert('5.6 El rastro inmutable cierra el intervalo con el técnico como actor', count($resumeRows) === 1 && (int)($resumeRows[0]['user_id'] ?? 0) === $techId);
    $assert('5.7 La nota documenta la duración exacta del intervalo', str_contains($resumeNote, ' Duración del intervalo de pausa: '));
    preg_match('/Duración del intervalo de pausa: (\d+) s/', $resumeNote, $durationMatch);
    $assert(
        '5.8 La duración auditada coincide al segundo con el acumulador descontable (RF-04.1)',
        (int)($durationMatch[1] ?? -1) === $accumulatedSeconds,
        'Nota: ' . $resumeNote
    );
    $assert(
        '5.9 La nota libre del técnico viaja al historial inmutable',
        str_contains($resumeNote, ' Nota: ') && str_contains($resumeNote, 'El conserje ha abierto la sala donde está la máquina.')
    );
    $assert('5.10 El expediente sigue vivo tras reanudar (no es terminal)', $incidentRepo->findById($mainIncidentId)?->getStatus()->isTerminal() === false);

    // =========================================================================
    // GRUPO 6: Reanudaciones imposibles y destino forzado del técnico
    // =========================================================================
    echo "\n--- Grupo 6: Reanudaciones imposibles y destino forzado ---\n";

    [$noPauseId] = $createAssignedTicket($techId);
    $res = $router->dispatch(new Request(method: 'POST', path: $resumePath($noPauseId), headers: $authHeader($technicianToken)));
    $assert(
        '6.1 Reanudar un expediente sin pausa abierta => 422 INVALID_STATUS_FOR_RESUME',
        $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_STATUS_FOR_RESUME'
    );
    $assert(
        '6.2 El rechazo no altera el expediente asignado ni su bitácora',
        ($incidentRow($noPauseId)['status'] ?? '') === 'ASSIGNED' && $pauseEvents($historyRows($noPauseId)) === 0
    );

    // Pausa desde una intervención en curso: el técnico ya estaba trabajando.
    [$inProgressId] = $createAssignedTicket($techId);
    $incidentRepo->startIntervention($inProgressId, $techId);
    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($inProgressId),
        parsedBody: $pauseBody('PENDING_SITE_AUTHORIZATION', 'La sede no autoriza el acceso al cuarto de máquinas hasta mañana.'),
        headers: $authHeader($technicianToken)
    ));
    $assert(
        '6.3 Pausa desde una intervención en curso => 200 OK',
        $res->getStatusCode() === 200 && ($res->getDecodedBody()['data']['status'] ?? '') === 'PENDING_INFO'
    );
    $pauseFromProgress = array_values(array_filter(
        $historyRows($inProgressId),
        static fn (array $row): bool => $row['to_status'] === 'PENDING_INFO'
    ));
    $assert('6.4 El rastro conserva el origen real IN_PROGRESS (RF-01.1)', ($pauseFromProgress[0]['from_status'] ?? '') === 'IN_PROGRESS');

    // El técnico in situ no puede devolver el ticket a "Asignada": eso es reanudación desfasada de coordinación.
    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $resumePath($inProgressId),
        parsedBody: ['target_status' => 'ASSIGNED'],
        headers: $authHeader($technicianToken)
    ));
    $assert(
        '6.5 target_status ASSIGNED en la ruta del técnico => 422 INVALID_RESUME_TARGET',
        $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_RESUME_TARGET'
    );
    $rejectedResumeRow = $incidentRow($inProgressId);
    $assert(
        '6.6 El rechazo mantiene la pausa viva y el expediente intacto',
        ($rejectedResumeRow['status'] ?? '') === 'PENDING_INFO' && ($rejectedResumeRow['paused_at'] ?? null) !== null
    );
    $assert(
        '6.7 Un destino inexistente tampoco se acepta',
        $router->dispatch(new Request(
            method: 'POST',
            path: $resumePath($inProgressId),
            parsedBody: ['target_status' => 'FULLY_CLOSED'],
            headers: $authHeader($technicianToken)
        ))->getStatusCode() === 422
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $resumePath($inProgressId),
        parsedBody: ['target_status' => 'in_progress', 'resume_note' => 'Acceso confirmado por la sede.'.PHP_EOL],
        headers: $authHeader($technicianToken)
    ));
    $assert(
        '6.8 Destino explícito IN_PROGRESS (normalizado) => 200 OK',
        $res->getStatusCode() === 200 && ($res->getDecodedBody()['data']['status'] ?? '') === 'IN_PROGRESS'
    );

    // =========================================================================
    // GRUPO 7: Desplazamiento comercial del vencimiento a través del endpoint (RF-03.3)
    // =========================================================================
    echo "\n--- Grupo 7: Desplazamiento comercial del vencimiento (RF-03.3) ---\n";

    [$shiftTicketId] = $createAssignedTicket($techId);
    $madrid = new DateTimeZone('Europe/Madrid');
    // Martes a las 12:15, dentro de la jornada comercial (08:00-18:00, lunes a viernes):
    // el desplazamiento de unos segundos debe quedarse en el mismo día hábil.
    $originalDeadline = (new DateTimeImmutable('next tuesday 12:15', $madrid))->format('Y-m-d H:i:s');
    $pdo->prepare('UPDATE `incidents` SET `sla_target_at` = :deadline WHERE `id` = :id')
        ->execute([':deadline' => $originalDeadline, ':id' => $shiftTicketId]);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($shiftTicketId),
        parsedBody: $pauseBody('MACHINE_LOCATION_NOT_FOUND', 'La máquina no está en la planta indicada por la sede; se busca con el conserje.'),
        headers: $authHeader($technicianToken)
    ));
    $shiftPauseData = $res->getDecodedBody()['data'] ?? [];
    $assert(
        '7.1 La pausa publica el vencimiento congelado sin desplazarlo',
        $res->getStatusCode() === 200
            && ($shiftPauseData['sla_target_at_original'] ?? '') === $originalDeadline
            && array_key_exists('sla_target_at_shifted', $shiftPauseData)
            && $shiftPauseData['sla_target_at_shifted'] === null
    );

    sleep(2);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $resumePath($shiftTicketId),
        headers: $authHeader($technicianToken)
    ));
    $shiftData = $res->getDecodedBody()['data'] ?? [];
    $shiftedDeadline = (string)($shiftData['sla_target_at_shifted'] ?? '');
    $originalMoment = new DateTimeImmutable($originalDeadline, $madrid);
    $shiftedMoment = new DateTimeImmutable($shiftedDeadline, $madrid);
    $shiftSeconds = $shiftedMoment->getTimestamp() - $originalMoment->getTimestamp();
    $shiftAccumulated = (int)($incidentRow($shiftTicketId)['total_pending_info_seconds'] ?? 0);

    $assert(
        '7.2 La reanudación publica el vencimiento desplazado y conserva el original',
        $res->getStatusCode() === 200
            && ($shiftData['sla_target_at_original'] ?? '') === $originalDeadline
            && $shiftedDeadline !== ''
    );
    $assert(
        '7.3 El desplazamiento equivale a los segundos exactos de pausa (RNF-01)',
        $shiftSeconds === $shiftAccumulated && $shiftAccumulated >= 2,
        sprintf('desplazamiento=%d s, acumulado=%d s', $shiftSeconds, $shiftAccumulated)
    );
    $assert(
        '7.4 El nuevo vencimiento cae en día hábil y dentro de la jornada comercial',
        (int)$shiftedMoment->format('N') <= 5
            && $shiftedMoment->format('Y-m-d') === $originalMoment->format('Y-m-d')
            && (int)$shiftedMoment->format('G') >= 8
            && (int)$shiftedMoment->format('G') <= 18,
        'Vencimiento desplazado: ' . $shiftedDeadline
    );
    $assert(
        '7.5 El vencimiento desplazado queda persistido en el expediente',
        ($incidentRow($shiftTicketId)['sla_target_at'] ?? '') === $shiftedDeadline
    );
    $assert('7.6 El reloj contractual queda reactivado tras el desplazamiento', ($shiftData['is_sla_paused'] ?? true) === false);
} finally {
    echo "\n--- Limpieza de los expedientes propios de la suite ---\n";
    $purged = TestDataCleaner::purgeIncidentsMatchingTicket($pdo, 'TST-P12-%');
    echo '  [OK] Expedientes purgados con TestDataCleaner (patrón TST-P12-%) · filas de incidencias eliminadas: '
        . array_sum($purged) . " (máquinas y sedes intactas)\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-12 CUMPLIDA.\n"
    : " RESULTADO: HAY FALLOS. CONDICIÓN T-PAUSE-12 NO CUMPLIDA.\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);

