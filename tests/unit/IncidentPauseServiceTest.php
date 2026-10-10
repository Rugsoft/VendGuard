<?php

declare(strict_types=1);

/**
 * IncidentPauseServiceTest
 *
 * Suite integral del ciclo de vida de la pausa por bloqueo imputable a la sede
 * (T-PAUSE-11). A diferencia de las suites de cada tarea —que protegen el detalle
 * de su algoritmo (T-PAUSE-06 calendario comercial, T-PAUSE-07 reloj sanitario,
 * T-PAUSE-08 reactivación condicional, T-PAUSE-09 cancelación por inactividad)—,
 * ésta recorre el PIVOTE completo del servicio sobre sus colaboradores reales:
 *
 *  GRUPO 1. Declaración legal de pausa (RF-01.1, RF-01.2, RF-01.4, RF-06.2).
 *  GRUPO 2. Rechazo de justificaciones con menos de 20 caracteres reales (RF-01.3, Art. V.1).
 *  GRUPO 3. Reactivación en caliente tras comentario de sede: < 60 min a IN_PROGRESS (RF-02.1, RF-02.3, RF-03.3).
 *  GRUPO 4. Reactivación desfasada: >= 60 min (o técnico ocupado, o sin técnico) a ASSIGNED (RF-02.1, RF-05.3).
 *  GRUPO 5. Cuarentena sanitaria automática a las 4 horas naturales continuas (RF-03.4, RF-03.5, Art. II).
 *  GRUPO 6. Cancelación por inactividad de más de 72 horas HÁBILES con la máquina fuera de servicio (RF-04.2, RF-04.3, RF-04.4, Art. V.1).
 *  GRUPO 7. Preservación de los reintegros económicos del consumidor (RF-04.5, Módulo 08).
 *
 * Los grupos 3 a 7 trabajan contra MariaDB real porque su veredicto es un ESTADO
 * PERSISTIDO (ticket reanudado, máquina en cuarentena, máquina fuera de servicio,
 * reintegro desvinculado), no un valor de retorno: un doble en memoria no puede
 * certificar que el parque quedó como dice el expediente. Las filas de prueba son
 * propias de la suite (máquinas y expedientes con código TST-P11) y se purgan en
 * el `finally`; sólo se LEE de las tablas maestras sembradas.
 *
 * Cumple con RF-01, RF-02, RF-03, RF-04, RNF-01 y el Dogma Vanilla de VendGuard.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IncidentCommentService;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;

echo "======================================================================\n";
echo " VendGuard: Suite Integral del Ciclo de Pausa por Bloqueo de Sede (T-PAUSE-11)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $label, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$label}\n";
        return;
    }

    $failures++;
    echo "  [FALLO] {$label}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
};

/** Captura el resultado de una llamada sin dejar que una excepción aborte la suite. */
$capture = function (callable $call): array {
    try {
        $value = $call();
        return ['threw' => false, 'class' => null, 'message' => '', 'value' => $value];
    } catch (Throwable $e) {
        return ['threw' => true, 'class' => get_class($e), 'message' => $e->getMessage(), 'value' => null];
    }
};

$pdo = ConnectionFactory::getConnection();
$incidentRepo = new PdoIncidentRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$refundRepo = new PdoRefundRequestRepository($pdo);
$settingsRepo = new PdoPreventiveSettingsRepository($pdo);
$auditLogger = new AuditLogger(new PdoAuditLogRepository($pdo));
$transactionManager = new PdoTransactionManager($pdo);
$commentService = new IncidentCommentService();

$service = new IncidentPauseService(
    settingsRepo: $settingsRepo,
    auditLogger: $auditLogger,
    incidentRepo: $incidentRepo,
    machineRepo: $machineRepo,
    refundRepo: $refundRepo,
    transactionManager: $transactionManager
);

$madrid = new DateTimeZone('Europe/Madrid');
$now = new DateTimeImmutable('now', $madrid);
$agoSeconds = static fn (int $seconds): string => $now->modify(sprintf('-%d seconds', $seconds))->format('Y-m-d H:i:s');
$aheadSeconds = static fn (int $seconds): string => $now->modify(sprintf('+%d seconds', $seconds))->format('Y-m-d H:i:s');

$suffix = strtoupper(bin2hex(random_bytes(3)));
$sequence = 0;

// Sede, coordinador y técnico libre se toman de las tablas maestras sembradas
// (protegidas por el limpiador compartido, que jamás las borra). El técnico se
// elige SIN intervenciones en curso para que la reactivación en caliente no se
// degrade por una avería ajena del sembrado de demostración.
$locationId = (int)$pdo->query('SELECT id FROM locations WHERE deleted_at IS NULL ORDER BY id LIMIT 1')->fetchColumn();
$coordinatorId = (int)$pdo->query("SELECT id FROM users WHERE role = 'COORDINATOR' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
if ($coordinatorId === 0) {
    $coordinatorId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
}

// Se reservan TRES técnicos libres, uno por cada reactivación que debe caer en
// caliente: cada reanudación deja al profesional con una intervención en curso, y
// reutilizar el mismo convertiría las siguientes pruebas en "técnico ocupado".
$freeTechnicianIds = array_map('intval', $pdo->query("
    SELECT u.`id` FROM `users` u
    WHERE u.`role` = 'TECHNICIAN' AND u.`deleted_at` IS NULL
      AND NOT EXISTS (
          SELECT 1 FROM `incidents` i
          WHERE i.`assigned_technician_id` = u.`id` AND i.`status` = 'IN_PROGRESS'
      )
    ORDER BY u.`id` LIMIT 3
")->fetchAll(PDO::FETCH_COLUMN));

$machineIds = [];
$incidentIds = [];
$refundIds = [];
$refundOwners = [];

$createMachine = function (string $machineType) use ($pdo, $machineRepo, $locationId, $suffix, &$machineIds): int {
    $machine = $machineRepo->create([
        'location_id' => $locationId,
        'code' => 'TST-P11-' . $machineType . '-' . $suffix . '-' . count($machineIds),
        'model' => 'Suite T-PAUSE-11',
        'machine_type' => $machineType,
        'floor_wing' => 'Banco de pruebas del ciclo de pausa',
        'notes' => 'Máquina de la suite integral del módulo 11.',
    ]);

    $machineIds[] = $machine->getId();

    return $machine->getId();
};

/**
 * Inserta un expediente propio de la suite y devuelve su id.
 *
 * Cada expediente estrena MÁQUINA propia salvo que se indique otra: la clave única
 * condicional `uq_machine_active_ticket` impide que una máquina acumule dos tickets
 * activos (guarda de duplicados del Art. V.2), así que compartir máquina entre los
 * escenarios de la suite haría imposible tenerlos vivos a la vez.
 *
 * @param array<string, mixed> $overrides
 */
$insertIncident = function (array $overrides) use ($pdo, $locationId, $suffix, &$sequence, &$incidentIds, $createMachine): int {
    $sequence++;

    $machineType = (string)($overrides['machine_type_snapshot'] ?? 'SNACKS');
    $defaults = [
        'ticket_code' => 'TST-P11-' . $suffix . '-' . str_pad((string)$sequence, 2, '0', STR_PAD_LEFT),
        'machine_id' => isset($overrides['machine_id']) ? (int)$overrides['machine_id'] : $createMachine($machineType),
        'machine_type_snapshot' => 'SNACKS',
        'location_id' => $locationId,
        'category' => 'PAYMENT_SYSTEM',
        'description' => 'Avería de la suite integral del ciclo de pausa del módulo 11.',
        'urgency' => 'HIGH',
        'status' => 'PENDING_INFO',
        'assigned_technician_id' => null,
        'assigned_at' => null,
        'started_at' => null,
        'created_at' => null,
        'paused_at' => null,
        'pending_info_reason_category' => null,
        'pending_info_reason_text' => null,
        'total_pending_info_seconds' => 0,
        'sla_target_at' => null,
    ];

    $values = array_merge($defaults, $overrides);
    $values['created_at'] = $values['created_at'] ?? $values['paused_at'] ?? date('Y-m-d H:i:s');

    $columns = array_keys($values);
    $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

    $statement = $pdo->prepare(sprintf(
        'INSERT INTO `incidents` (`%s`) VALUES (%s)',
        implode('`, `', $columns),
        implode(', ', $placeholders)
    ));

    $statement->execute(array_combine($placeholders, array_values($values)));

    $id = (int)$pdo->lastInsertId();
    $incidentIds[] = $id;

    return $id;
};

/** Fila cruda del expediente, la verdad persistida que leen los veredictos. */
$incidentRow = function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM `incidents` WHERE `id` = :id');
    $stmt->execute([':id' => $id]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

/** Eventos inmutables de `incident_history` del expediente, en orden de escritura. */
$historyRows = function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM `incident_history` WHERE `incident_id` = :id ORDER BY `id` ASC');
    $stmt->execute([':id' => $id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

/** Contador de eventos de auditoría por entidad y acción. */
$auditCount = function (string $entityType, int $entityId, string $action) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `audit_log` WHERE `entity_type` = :type AND `entity_id` = :entity AND `action` = :action');
    $stmt->execute([':type' => $entityType, ':entity' => $entityId, ':action' => $action]);

    return (int)$stmt->fetchColumn();
};

/** Último evento de auditoría concreto, para leer su payload. */
$auditRow = function (string $entityType, int $entityId, string $action) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM `audit_log` WHERE `entity_type` = :type AND `entity_id` = :entity AND `action` = :action ORDER BY `id` DESC LIMIT 1');
    $stmt->execute([':type' => $entityType, ':entity' => $entityId, ':action' => $action]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

/** Factoría de expedientes de dominio puros (sin persistencia) para las reglas del agregado. */
$domainIncident = static function (IncidentStatus $status, ?string $pausedAt = null, ?int $assignedTechnicianId = 42) use ($madrid): Incident {
    return new Incident(
        id: 991001,
        ticketCode: 'TST-P11-DOM',
        machineId: 1,
        locationId: 1,
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'Expediente de dominio de la suite integral de pausa.',
        urgency: UrgencyLevel::HIGH,
        status: $status,
        assignedTechnicianId: $assignedTechnicianId,
        pausedAt: $pausedAt,
        slaTargetAt: (new DateTimeImmutable('2026-10-12 12:00:00', $madrid))->format('Y-m-d H:i:s')
    );
};

try {
    // =====================================================================
    // GRUPO 1: Declaración legal de pausa (RF-01.1, RF-01.2, RF-01.4, RF-06.2)
    // =====================================================================
    echo "--- Grupo 1: Declaración legal de pausa y máquina de estados ---\n";

    $legalOrigins = [
        'ASSIGNED' => IncidentStatus::ASSIGNED,
        'IN_PROGRESS' => IncidentStatus::IN_PROGRESS,
        'PENDING_PARTS' => IncidentStatus::PENDING_PARTS,
        'REOPENED' => IncidentStatus::REOPENED,
    ];
    foreach ($legalOrigins as $label => $status) {
        $assert(
            "1.1 Se pausa legalmente desde {$label}",
            $status->canTransitionTo(IncidentStatus::PENDING_INFO)
        );
    }

    $illegalOrigins = [
        'REGISTERED' => IncidentStatus::REGISTERED,
        'RESOLVED' => IncidentStatus::RESOLVED,
        'CLOSED' => IncidentStatus::CLOSED,
        'CANCELLED' => IncidentStatus::CANCELLED,
        'PENDING_INFO' => IncidentStatus::PENDING_INFO,
    ];
    foreach ($illegalOrigins as $label => $status) {
        $assert(
            "1.2 No se pausa desde {$label} (nada de dobles pausas ni reaperturas encubiertas)",
            !$status->canTransitionTo(IncidentStatus::PENDING_INFO)
        );
    }

    $pausedDeclaration = $domainIncident(IncidentStatus::IN_PROGRESS)->pausePendingInfo(
        IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS,
        '  El conserje no abrió el acceso al edificio durante la visita programada.  ',
        new DateTimeImmutable('2026-10-09 09:30:00', $madrid)
    );

    $assert('1.3 La declaración deja el expediente en PENDING_INFO', $pausedDeclaration->getStatus() === IncidentStatus::PENDING_INFO);
    $assert('1.4 PENDING_INFO es un estado activo y no terminal', $pausedDeclaration->isPendingInfo() && $pausedDeclaration->getStatus()->isActive() && !$pausedDeclaration->getStatus()->isTerminal());
    $assert('1.5 La causa tipificada se conserva', $pausedDeclaration->getPendingInfoReasonCategory() === IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS);
    $assert('1.6 La justificación se guarda recortada', $pausedDeclaration->getPendingInfoReasonText() === 'El conserje no abrió el acceso al edificio durante la visita programada.');
    $assert('1.7 El intervalo vivo arranca en la marca declarada', $pausedDeclaration->isPaused() && $pausedDeclaration->getPausedAt() === '2026-10-09 09:30:00');
    $assert('1.8 El acumulador de pausa nace intacto (sólo crece al cerrar el intervalo)', $pausedDeclaration->getTotalPendingInfoSeconds() === 0);
    $assert('1.9 La instancia receptora no muta: el agregado es inmutable', $domainIncident(IncidentStatus::IN_PROGRESS)->getStatus() === IncidentStatus::IN_PROGRESS);

    $assert('1.10 Está prohibido resolver directo desde la pausa (RF-06.2)', !IncidentStatus::PENDING_INFO->canTransitionTo(IncidentStatus::RESOLVED));
    $exitTargets = array_map(static fn (IncidentStatus $status): string => $status->value, IncidentStatus::PENDING_INFO->allowedTransitions());
    sort($exitTargets);
    $assert('1.11 Se sale de la pausa sólo hacia IN_PROGRESS, ASSIGNED o CANCELLED', $exitTargets === ['ASSIGNED', 'CANCELLED', 'IN_PROGRESS'], implode(',', $exitTargets));

    $illegalPause = $capture(fn () => $domainIncident(IncidentStatus::REGISTERED)->pausePendingInfo(
        IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS,
        'Intento de pausa sobre un expediente que aún no tiene técnico asignado.'
    ));
    $assert('1.12 Pausar un expediente recién registrado se rechaza con InvalidTransitionException', $illegalPause['threw'] && $illegalPause['class'] === InvalidTransitionException::class);

    $illegalResume = $capture(fn () => $domainIncident(IncidentStatus::PENDING_INFO, '2026-10-09 08:00:00')->resumePendingInfo(IncidentStatus::RESOLVED));
    $assert('1.13 Reanudar hacia RESOLVED se rechaza: la pausa no puede resolver sola una avería', $illegalResume['threw'] && $illegalResume['class'] === InvalidTransitionException::class);

    // =====================================================================
    // GRUPO 2: Justificación obligatoria de 20 caracteres reales (RF-01.3, Art. V.1)
    // =====================================================================
    echo "\n--- Grupo 2: Justificación obligatoria de 20 caracteres reales ---\n";

    $reasonCases = [
        '2.1 Una justificación de 19 caracteres se rechaza' => ['Exactamente_19_cara', false],
        '2.2 Una justificación vacía se rechaza' => ['', false],
        '2.3 Una justificación de sólo espacios se rechaza (no hay caracteres reales)' => [str_repeat(' ', 30), false],
        '2.4 Diecinueve caracteres con relleno de espacios al final se miden recortados' => ['Justificacion_19_ca' . '   ', false],
        '2.5 La justificación mínima de 20 caracteres exactos se acepta' => ['Justificacion_20_cars', true],
        '2.6 Los caracteres multibyte cuentan como caracteres reales (16 acentuados se rechazan)' => [str_repeat('ñá', 8), false],
        '2.7 Los caracteres multibyte alcanzan el mínimo con 20 reales' => [str_repeat('ñá', 10), true],
    ];

    foreach ($reasonCases as $label => [$reason, $shouldAccept]) {
        $attempt = $capture(fn () => $domainIncident(IncidentStatus::ASSIGNED)->pausePendingInfo(
            IncidentPauseReasonCategory::PENDING_SITE_AUTHORIZATION,
            $reason
        ));

        $assert(
            $label,
            $shouldAccept
                ? (!$attempt['threw'] && $attempt['value']->isPendingInfo())
                : ($attempt['threw'] && $attempt['class'] === \InvalidArgumentException::class),
            $attempt['threw'] ? $attempt['message'] : 'aceptada'
        );
    }

    $assert('2.8 El mínimo legal de la entidad es 20 caracteres', Incident::MIN_PAUSE_REASON_LENGTH === 20);

    // =====================================================================
    // GRUPO 3: Reactivación en caliente < 60 min -> IN_PROGRESS (RF-02.1, RF-02.3, RF-03.3)
    // =====================================================================
    echo "\n--- Grupo 3: Reactivación en caliente tras comentario de sede (< 60 min) ---\n";

    $assert(
        '3.0 Premisa de fixture: hay al menos tres técnicos sin intervención en curso para las reanudaciones en caliente',
        count($freeTechnicianIds) >= 3,
        'encontrados: ' . count($freeTechnicianIds)
    );

    $hotIncidentId = $insertIncident([
        'machine_type_snapshot' => 'SNACKS',
        'status' => 'PENDING_INFO',
        'assigned_technician_id' => $freeTechnicianIds[0] ?? null,
        'assigned_at' => $agoSeconds(3600),
        'created_at' => $agoSeconds(4000),
        'paused_at' => $agoSeconds(1200),                  // 20 minutos: respuesta en caliente
        'pending_info_reason_category' => 'BUILDING_CLOSED_NO_ACCESS',
        'pending_info_reason_text' => 'El responsable de sede no se presentó a abrir la sala técnica.',
        'total_pending_info_seconds' => 3600,              // una pausa anterior de 1 hora ya cerrada
        'sla_target_at' => $aheadSeconds(18000),
    ]);

    $siteActorId = 424242;

    $shortComment = $service->handleSiteCommentReactivation($hotIncidentId, 'Ok', $siteActorId);
    $assert('3.1 Un comentario sin sustancia no reanuda la avería', $shortComment->status === IncidentStatus::PENDING_INFO && $shortComment->isSlaPaused);
    $assert('3.2 El comentario corto no escribe historial alguno', count($historyRows($hotIncidentId)) === 0);

    $hot = $service->handleSiteCommentReactivation(
        $hotIncidentId,
        'El conserje ya está en la puerta con la llave del cuarto de máquinas.',
        $siteActorId
    );

    $assert('3.3 Respuesta en caliente: la avería vuelve a IN_PROGRESS', $hot->status === IncidentStatus::IN_PROGRESS);
    $assert('3.4 El contrato declara el reloj contractual reactivado', $hot->isSlaPaused === false);
    $assert('3.5 La pausa acumulada suma la hora previa y los 20 minutos en caliente', $hot->accumulatedPauseMinutes >= 80 && $hot->accumulatedPauseMinutes <= 81, (string)$hot->accumulatedPauseMinutes);
    $assert('3.6 El vencimiento original viaja en el contrato', $hot->slaTargetAtOriginal !== null);
    $assert('3.7 El vencimiento se desplaza hacia adelante en horario comercial', $hot->slaTargetAtShifted !== null && $hot->slaTargetAtShifted > $hot->slaTargetAtOriginal);
    $assert('3.8 El nuevo vencimiento jamás cae de madrugada ni en fin de semana', (function () use ($hot, $madrid): bool {
        $shifted = new DateTimeImmutable((string)$hot->slaTargetAtShifted, $madrid);
        return (int)$shifted->format('N') <= 5 && (int)$shifted->format('G') >= 8 && (int)$shifted->format('G') <= 18;
    })());

    $hotRow = $incidentRow($hotIncidentId);
    $assert('3.9 El estado reanudado queda persistido', $hotRow['status'] === 'IN_PROGRESS');
    $assert('3.10 El intervalo vivo se cierra en la fila', $hotRow['paused_at'] === null);
    $assert('3.11 El acumulador multi-pausa suma los segundos exactos del intervalo', (int)$hotRow['total_pending_info_seconds'] >= 4800 && (int)$hotRow['total_pending_info_seconds'] <= 4860, (string)$hotRow['total_pending_info_seconds']);
    $assert('3.12 El vencimiento desplazado es el que queda grabado', $hotRow['sla_target_at'] === $hot->slaTargetAtShifted);

    $hotHistory = $historyRows($hotIncidentId);
    $assert('3.13 La reanudación deja exactamente un rastro inmutable', count($hotHistory) === 1);
    $assert('3.14 El rastro apunta al destino operativo real', ($hotHistory[0]['to_status'] ?? '') === 'IN_PROGRESS');
    $hotNote = (string)($hotHistory[0]['action_note'] ?? '');
    $assert('3.15 El actor de sede no se escribe como usuario interno (columna FK a users)', array_key_exists('user_id', $hotHistory[0]) && $hotHistory[0]['user_id'] === null);
    $assert('3.16 La nota conserva la referencia del actor de sede', str_contains($hotNote, (string)$siteActorId));
    $assert('3.17 El rastro documenta la duración exacta del intervalo cerrado', preg_match('/Duración del intervalo de pausa: (12[0-9]{2}) s\./', $hotNote, $durationMatch) === 1, $hotNote);
    $assert('3.18 La duración auditada cuadra con los segundos acumulados por el agregado', (int)($durationMatch[1] ?? 0) === (int)$hotRow['total_pending_info_seconds'] - 3600);

    $burst = $service->handleSiteCommentReactivation($hotIncidentId, 'Insisto: ya está abierto, avisad al técnico.', $siteActorId);
    $assert('3.19 Idempotencia ante ráfagas: el segundo comentario no reescribe nada', count($historyRows($hotIncidentId)) === 1 && $burst->status === IncidentStatus::IN_PROGRESS);

    $noSlaIncidentId = $insertIncident([
        'status' => 'PENDING_INFO',
        'assigned_technician_id' => $freeTechnicianIds[1] ?? null,
        'paused_at' => $agoSeconds(600),
        'created_at' => $agoSeconds(1200),
        'sla_target_at' => null,
    ]);
    $noSla = $service->handleSiteCommentReactivation($noSlaIncidentId, 'Sede facilita el acceso al técnico ahora mismo.', $siteActorId);
    $assert('3.20 Un expediente sin SLA contractual no finge un vencimiento', $noSla->slaTargetAtOriginal === null && $noSla->slaTargetAtShifted === null);
    $assert('3.21 Sin SLA, la reactivación sigue ocurriendo y se persiste sin fecha', $noSla->status === IncidentStatus::IN_PROGRESS && $incidentRow($noSlaIncidentId)['sla_target_at'] === null);

    // =====================================================================
    // GRUPO 4: Reactivación desfasada >= 60 min -> ASSIGNED (RF-02.1, RF-05.3, §6.4)
    // =====================================================================
    echo "\n--- Grupo 4: Reactivación desfasada (>= 60 min) y reglas de contexto ---\n";

    $assert('4.1 La ventana de calidez es de 60 minutos', IncidentPauseService::HOT_REACTIVATION_WINDOW_SECONDS === 3600);

    $coldIncidentId = $insertIncident([
        'status' => 'PENDING_INFO',
        'assigned_technician_id' => $freeTechnicianIds[0] ?? null,
        'paused_at' => $agoSeconds(5400),                 // 90 minutos
        'created_at' => $agoSeconds(7200),
        'pending_info_reason_category' => 'PENDING_SITE_AUTHORIZATION',
        'pending_info_reason_text' => 'El responsable de sede no respondió a las llamadas del técnico.',
        'sla_target_at' => $aheadSeconds(3600),
    ]);

    $cold = $service->handleSiteCommentReactivation($coldIncidentId, 'La sala ya está abierta; el técnico puede pasar cuando quiera.', $siteActorId);
    $assert('4.2 Respuesta desfasada: la avería vuelve a ASSIGNED (requiere presencia física)', $cold->status === IncidentStatus::ASSIGNED);
    $assert('4.3 La reanudación desfasada también cierra el intervalo de pausa', $incidentRow($coldIncidentId)['paused_at'] === null);
    $assert('4.4 El acumulador refleja los 90 minutos completos', $cold->accumulatedPauseMinutes >= 90 && $cold->accumulatedPauseMinutes <= 91, (string)$cold->accumulatedPauseMinutes);
    $assert('4.5 El historial registra el retorno a ASSIGNED con su nota', ($historyRows($coldIncidentId)[0]['to_status'] ?? '') === 'ASSIGNED' && str_contains((string)($historyRows($coldIncidentId)[0]['action_note'] ?? ''), 'Nota:'));

    $boundaryColdId = $insertIncident([
        'status' => 'PENDING_INFO',
        'assigned_technician_id' => $freeTechnicianIds[0] ?? null,
        'paused_at' => $agoSeconds(3610),                 // un pelo por encima del minuto 60
        'created_at' => $agoSeconds(4000),
    ]);
    $boundaryCold = $service->handleSiteCommentReactivation($boundaryColdId, 'El acceso ya está habilitado para el técnico.', $siteActorId);
    $assert('4.6 Exactamente 60 minutos ya NO es respuesta en caliente', $boundaryCold->status === IncidentStatus::ASSIGNED);

    $hotBoundaryId = $insertIncident([
        'status' => 'PENDING_INFO',
        'assigned_technician_id' => $freeTechnicianIds[2] ?? null,
        'paused_at' => $agoSeconds(3500),                 // 58 minutos y 20 segundos
        'created_at' => $agoSeconds(4000),
    ]);
    $hotBoundary = $service->handleSiteCommentReactivation($hotBoundaryId, 'Ya pueden acceder a la sala de máquinas.', $siteActorId);
    $assert('4.7 Por debajo de la hora la reanudación sigue siendo en caliente', $hotBoundary->status === IncidentStatus::IN_PROGRESS);

    $busyIncidentId = $insertIncident([
        'status' => 'IN_PROGRESS',
        'assigned_technician_id' => $freeTechnicianIds[0] ?? null,
        'assigned_at' => $agoSeconds(1000),
        'started_at' => $agoSeconds(900),
        'created_at' => $agoSeconds(1000),
    ]);
    $busyPausedId = $insertIncident([
        'status' => 'PENDING_INFO',
        'assigned_technician_id' => $freeTechnicianIds[0] ?? null,
        'paused_at' => $agoSeconds(600),
        'created_at' => $agoSeconds(1200),
    ]);
    $busy = $service->handleSiteCommentReactivation($busyPausedId, 'La sede acaba de abrir la puerta al técnico.', $siteActorId);
    $assert('4.8 Con el técnico en otra intervención activa, la vuelta es a ASSIGNED aunque la respuesta sea rápida', $busy->status === IncidentStatus::ASSIGNED);
    $assert('4.9 El expediente que ocupaba al técnico sigue en curso', $incidentRow($busyIncidentId)['status'] === 'IN_PROGRESS');

    $unassignedId = $insertIncident([
        'status' => 'PENDING_INFO',
        'assigned_technician_id' => null,
        'paused_at' => $agoSeconds(300),
        'created_at' => $agoSeconds(900),
    ]);
    $unassigned = $service->handleSiteCommentReactivation($unassignedId, 'La sede responde pero la avería no tiene técnico responsable.', $siteActorId);
    $assert('4.10 Caso límite: sin técnico responsable la reanudación no puede afirmar que está en curso', $unassigned->status === IncidentStatus::ASSIGNED);

    $missing = $capture(fn () => $service->handleSiteCommentReactivation(99999999, 'Comentario sobre un expediente inexistente.', $siteActorId));
    $assert('4.11 Un comentario sobre un expediente inexistente se declara con excepción, no se silencia', $missing['threw'] && $missing['class'] === \DomainException::class);

    // =====================================================================
    // GRUPO 5: Cuarentena sanitaria a las 4 h naturales continuas (RF-03.4, Art. II)
    // =====================================================================
    echo "\n--- Grupo 5: Cuarentena sanitaria automática a las 4 horas naturales ---\n";

    $assert('5.1 El reloj biológico son 4 horas naturales exactas', IncidentPauseService::SANITARY_BIOLOGICAL_CLOCK_SECONDS === 14400);

    $belowThresholdId = $insertIncident([
        'machine_type_snapshot' => 'PERISHABLE_FOOD',
        'category' => 'TEMPERATURE_COLD',
        'status' => 'IN_PROGRESS',
        'assigned_technician_id' => $freeTechnicianIds[0] ?? null,
        'created_at' => $agoSeconds(12600),               // 3 h 30 min: por debajo del umbral
    ]);
    $belowThresholdMachineId = (int)$incidentRow($belowThresholdId)['machine_id'];
    $assert('5.2 A las 3 h 30 min la máquina perecedera sigue sin cuarentena', $service->evaluateSanitaryBiologicalClock($incidentRepo->findById($belowThresholdId)) === false);
    $assert('5.3 El estado sanitario persistido permanece OK antes del umbral', ($settingsRepo->getMachineSettings($belowThresholdMachineId)['sanitary_status'] ?? '') === 'OK');

    $quarantineIncidentId = $insertIncident([
        'machine_type_snapshot' => 'PERISHABLE_FOOD',
        'category' => 'TEMPERATURE_COLD',
        'status' => 'IN_PROGRESS',
        'assigned_technician_id' => $freeTechnicianIds[0] ?? null,
        'created_at' => $agoSeconds(14700),               // 4 h 05 min: umbral superado
    ]);
    $perishableMachineId = (int)$incidentRow($quarantineIncidentId)['machine_id'];
    $triggered = $service->evaluateSanitaryBiologicalClock($incidentRepo->findById($quarantineIncidentId));
    $assert('5.4 Superadas las 4 h naturales, el reloj biológico dispara la cuarentena', $triggered === true);
    $assert('5.5 La máquina queda en QUARANTINE (fuera de servicio para el ciudadano)', ($settingsRepo->getMachineSettings($perishableMachineId)['sanitary_status'] ?? '') === IncidentPauseService::SANITARY_STATUS_QUARANTINE);

    $sanitaryAudit = $auditRow(AuditEvent::ENTITY_MACHINE, $perishableMachineId, 'SANITARY_QUARANTINE_AUTO_TRIGGERED');
    $assert('5.6 La cuarentena deja su evento inmutable de auditoría', $sanitaryAudit !== []);
    $assert('5.7 El evento declara la obligación de reinspección (checklist sanitario)', str_contains((string)($sanitaryAudit['new_state'] ?? ''), 'sanitary_checklist_required') || str_contains((string)($sanitaryAudit['metadata'] ?? ''), 'sanitary_checklist_required'));
    $assert('5.8 El evento documenta el umbral de 4 horas y la espera natural real', str_contains((string)($sanitaryAudit['metadata'] ?? ''), '14400'));

    $again = $service->evaluateSanitaryBiologicalClock($incidentRepo->findById($quarantineIncidentId));
    $assert('5.9 Reevaluar no duplica el evento ni reescribe la máquina', $again === true && $auditCount(AuditEvent::ENTITY_MACHINE, $perishableMachineId, 'SANITARY_QUARANTINE_AUTO_TRIGGERED') === 1);

    $pausedPerishableId = $insertIncident([
        'machine_type_snapshot' => 'PERISHABLE_FOOD',
        'category' => 'TEMPERATURE_COLD',
        'status' => 'PENDING_INFO',
        'paused_at' => $agoSeconds(18000),
        'created_at' => $agoSeconds(18000),
    ]);
    $assert('5.10 El reloj biológico NO se pausa con el contractual: una avería en pausa sigue madurando la cuarentena', $service->evaluateSanitaryBiologicalClock($incidentRepo->findById($pausedPerishableId)) === true);

    $snackIncidentId = $insertIncident([
        'machine_type_snapshot' => 'SNACKS',
        'status' => 'IN_PROGRESS',
        'created_at' => $agoSeconds(36000),               // 10 horas: sólo el reloj contractual corre
    ]);
    $assert('5.11 Una máquina no perecedera no entra en cuarentena por el reloj biológico', $service->evaluateSanitaryBiologicalClock($incidentRepo->findById($snackIncidentId)) === false);
    $assert('5.12 La máquina de snacks conserva su estado sanitario OK', ($settingsRepo->getMachineSettings((int)$incidentRow($snackIncidentId)['machine_id'])['sanitary_status'] ?? '') === 'OK');

    // =====================================================================
    // GRUPO 6: Inactividad de más de 72 h HÁBILES y máquina fuera de servicio
    //          (RF-04.2, RF-04.3, RF-04.4, Art. V.1). Los dos primeros casos
    //          son puros (instante inyectado); el tercero es la cancelación real.
    // =====================================================================
    echo "\n--- Grupo 6: Cancelación por inactividad de más de 72 h hábiles ---\n";

    $assert('6.1 El umbral de inactividad son 72 horas hábiles', IncidentPauseService::PROLONGED_INACTIVITY_BUSINESS_HOURS === 72);
    $assert('6.2 El mínimo justificativo de la cancelación son 20 caracteres reales', IncidentPauseService::MIN_CANCELLATION_REASON_LENGTH === 20);

    // Premisa del calendario de referencia: 2026-09-28 es lunes y 2026-10-09 es viernes.
    $assert('6.3 Premisa de calendario: el 2026-09-28 es lunes y el 2026-10-09 es viernes', (new DateTimeImmutable('2026-09-28', $madrid))->format('N') === '1' && (new DateTimeImmutable('2026-10-09', $madrid))->format('N') === '5');

    $at = static fn (string $moment): DateTimeImmutable => new DateTimeImmutable($moment, $madrid);

    $twoDaysIdle = $service->isProlongedInactivity($domainIncident(IncidentStatus::PENDING_INFO, '2026-10-05 08:00:00'), $at('2026-10-07 08:00:00'));
    $assert('6.4 Dos jornadas completas de espera (20 h hábiles) no superan el umbral', $twoDaysIdle === false);

    $threeDaysIdle = $service->isProlongedInactivity($domainIncident(IncidentStatus::PENDING_INFO, '2026-10-05 08:00:00'), $at('2026-10-07 18:00:00'));
    $assert('6.5 Tres jornadas (30 h hábiles) siguen por debajo del umbral', $threeDaysIdle === false);

    $weekendIdle = $service->isProlongedInactivity($domainIncident(IncidentStatus::PENDING_INFO, '2026-10-02 17:00:00'), $at('2026-10-05 09:00:00'));
    $assert('6.6 El fin de semana no computa como espera hábil (viernes 17:00 a lunes 09:00 son 2 h)', $weekendIdle === false);

    $exactThreshold = $service->isProlongedInactivity($domainIncident(IncidentStatus::PENDING_INFO, '2026-09-28 08:00:00'), $at('2026-10-07 10:00:00'));
    $assert('6.7 Exactamente 72 horas hábiles no superan el umbral (la frontera es estricta)', $exactThreshold === false);

    $oneSecondOver = $service->isProlongedInactivity($domainIncident(IncidentStatus::PENDING_INFO, '2026-09-28 08:00:00'), $at('2026-10-07 10:00:01'));
    $assert('6.8 72 horas hábiles más un segundo encienden la alerta de espera prolongada', $oneSecondOver === true);

    $weekComplete = $service->isProlongedInactivity($domainIncident(IncidentStatus::PENDING_INFO, '2026-09-28 08:00:00'), $at('2026-10-09 18:00:00'));
    $assert('6.9 Diez jornadas hábiles (100 h) superan con holgura el umbral', $weekComplete === true);

    $unpaused = $service->isProlongedInactivity($domainIncident(IncidentStatus::IN_PROGRESS, null), $at('2026-10-09 18:00:00'));
    $assert('6.10 Un expediente sin pausa viva nunca está en espera prolongada', $unpaused === false);

    // ---- Entrada por columnas crudas (T-PAUSE-19) ---------------------------
    // Las superficies de lectura (bandeja de triaje y ficha de detalle) no hidratan el
    // agregado, así que la MISMA regla de negocio se publica sobre las columnas de la fila.
    $assert(
        '6.10.b La consulta por columnas crudas reproduce el umbral de la instancia de dominio (una sola regla)',
        $service->isProlongedInactivitySince('2026-09-28 08:00:00', true, $at('2026-10-07 10:00:00')) === false
            && $service->isProlongedInactivitySince('2026-09-28 08:00:00', true, $at('2026-10-07 10:00:01')) === true
            && $service->isProlongedInactivitySince('2026-09-28 08:00:00', true, $at('2026-10-09 18:00:00')) === true
    );
    $assert(
        '6.10.c Sin marca de pausa o sin pausa viva la consulta cruda es fail-safe',
        $service->isProlongedInactivitySince(null, true, $at('2026-10-09 18:00:00')) === false
            && $service->isProlongedInactivitySince('', true, $at('2026-10-09 18:00:00')) === false
            && $service->isProlongedInactivitySince('2026-09-28 08:00:00', false, $at('2026-10-09 18:00:00')) === false
    );

    // ---- Cancelación real contra MariaDB -------------------------------------
    $cancelIncidentId = $insertIncident([
        'machine_type_snapshot' => 'SNACKS',
        'status' => 'PENDING_INFO',
        'assigned_technician_id' => $freeTechnicianIds[0] ?? null,
        'paused_at' => $now->modify('-21 days')->format('Y-m-d H:i:s'),   // > 72 h hábiles con holgura
        'created_at' => $now->modify('-22 days')->format('Y-m-d H:i:s'),
        'pending_info_reason_category' => 'BUILDING_CLOSED_NO_ACCESS',
        'pending_info_reason_text' => 'El edificio permaneció cerrado sin facilitar acceso al técnico.',
        'sla_target_at' => $aheadSeconds(3600),
    ]);

    $cancelTicketCode = (string)$incidentRow($cancelIncidentId)['ticket_code'];
    $guardMachineId = (int)$incidentRow($cancelIncidentId)['machine_id'];

    // Reintegros del expediente: uno en inspección (camino natural de liquidación) y
    // otro con el efectivo YA entregado en recepción (su PIN no puede reiniciarse).
    $insertRefund = function (int $incidentId, string $status, string $token) use ($pdo, $guardMachineId, $locationId, &$refundIds, &$refundOwners): int {
        $pdo->prepare("
            INSERT INTO `refund_requests` (
                `incident_id`, `machine_id`, `location_id`, `claimant_name`, `claimant_contact`,
                `claimed_amount`, `product_attempted`, `compensation_method`, `bizum_phone`,
                `tracking_token`, `status`
            ) VALUES (
                :incident, :machine, :location, 'Consumidor de la suite integral', '600000011',
                2.50, 'Sándwich de pollo', 'BIZUM', '600000011',
                :token, :status
            )
        ")->execute([
            ':incident' => $incidentId,
            ':machine' => $guardMachineId,
            ':location' => $locationId,
            ':token' => $token,
            ':status' => $status,
        ]);

        $id = (int)$pdo->lastInsertId();
        $refundIds[] = $id;
        $refundOwners[$id] = $incidentId;

        return $id;
    };

    $inspectionRefundId = $insertRefund($cancelIncidentId, 'PENDING_INSPECTION', 'tstp11-' . $suffix . '-a');
    $depositedRefundId = $insertRefund($cancelIncidentId, 'DEPOSITED_AT_RECEPTION', 'tstp11-' . $suffix . '-b');

    $otherIncidentId = $insertIncident([
        'status' => 'ASSIGNED',
        'assigned_technician_id' => $freeTechnicianIds[0] ?? null,
        'created_at' => $agoSeconds(600),
    ]);
    $untouchedRefundId = $insertRefund($otherIncidentId, 'PENDING_INSPECTION', 'tstp11-' . $suffix . '-c');

    $shortReason = $capture(fn () => $service->cancelByInactivity($cancelIncidentId, $coordinatorId, 'Motivo de 19 caract'));
    $assert('6.11 Un motivo de 19 caracteres se rechaza antes de tocar la base', $shortReason['threw'] && $shortReason['class'] === \InvalidArgumentException::class);
    $assert('6.12 El rechazo del motivo no canceló nada', $incidentRow($cancelIncidentId)['status'] === 'PENDING_INFO');

    $notPaused = $capture(fn () => $service->cancelByInactivity($otherIncidentId, $coordinatorId, 'Cierre administrativo por inactividad y falta de acceso del cliente.'));
    $assert('6.13 Cancelar un expediente que no está en pausa se rechaza con InvalidTransitionException', $notPaused['threw'] && $notPaused['class'] === InvalidTransitionException::class);
    $assert('6.14 El rechazo por estado no bloqueó la máquina de otra avería', (int)($pdo->query('SELECT `is_blocked_no_access` FROM `machines` WHERE `id` = ' . $guardMachineId)->fetchColumn() ?: 0) === 0);

    $cancelReason = 'Cierre administrativo por inactividad y falta de acceso del cliente tras 72 horas hábiles.';
    $cancellation = $capture(fn () => $service->cancelByInactivity($cancelIncidentId, $coordinatorId, $cancelReason));
    $assert('6.15 El protocolo completo de cancelación se ejecuta sin excepciones', !$cancellation['threw'], $cancellation['threw'] ? $cancellation['message'] : '');

    $cancelledRow = $incidentRow($cancelIncidentId);
    $assert('6.16 El ticket queda formalmente CANCELLED', $cancelledRow['status'] === 'CANCELLED');
    $assert('6.17 La cancelación conserva su motivo justificado', str_contains((string)$cancelledRow['cancellation_reason'], 'falta de acceso del cliente'));
    $assert('6.18 La cancelación queda fechada', $cancelledRow['cancelled_at'] !== null);

    $machineRow = $pdo->query('SELECT * FROM `machines` WHERE `id` = ' . $guardMachineId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert('6.19 La máquina queda FUERA DE SERVICIO (nunca de vuelta a Operativa)', (int)$machineRow['is_active'] === 0);
    $assert('6.20 La máquina queda marcada como bloqueada por falta de acceso', (int)$machineRow['is_blocked_no_access'] === 1);
    $assert('6.21 La nota de la máquina cita el ticket que provocó el bloqueo', str_contains((string)$machineRow['notes'], $cancelTicketCode));
    $assert('6.22 El bloqueo deja su evento inmutable de auditoría', $auditCount('MACHINE', $guardMachineId, 'MACHINE_BLOCKED_NO_ACCESS') === 1);
    $blockedAudit = $auditRow('MACHINE', $guardMachineId, 'MACHINE_BLOCKED_NO_ACCESS');
    $assert('6.23 El evento documenta el estado previo y el resultante del parque', str_contains((string)$blockedAudit['previous_state'], 'ACTIVE_INCIDENT') && str_contains((string)$blockedAudit['new_state'], 'BLOCKED_NO_ACCESS'));

    $sealedOrder = $incidentRow($cancelIncidentId);
    $assert('6.24 El expediente cancelado queda sellado en solo lectura para comentarios (RF-05.4)', $commentService->acceptsNewComments(['status' => $sealedOrder['status']]) === false);

    // =====================================================================
    // GRUPO 7: Preservación de los reintegros económicos (RF-04.5, Módulo 08)
    // =====================================================================
    echo "\n--- Grupo 7: Preservación de reintegros del consumidor ---\n";

    $inspectionRefund = $pdo->query('SELECT * FROM `refund_requests` WHERE `id` = ' . $inspectionRefundId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $depositedRefund = $pdo->query('SELECT * FROM `refund_requests` WHERE `id` = ' . $depositedRefundId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $untouchedRefund = $pdo->query('SELECT * FROM `refund_requests` WHERE `id` = ' . $untouchedRefundId)->fetch(PDO::FETCH_ASSOC) ?: [];

    $assert('7.1 El reintegro no se borra: el consumidor conserva su expediente', $inspectionRefund !== [] && $depositedRefund !== []);
    $assert('7.2 El reintegro en inspección queda desvinculado de la avería', $inspectionRefund['incident_id'] === null);
    $assert('7.3 El reintegro desvinculado pasa a la bandeja de Coordinación para liquidación central', $inspectionRefund['status'] === RefundStatus::REQUIRES_COORDINATOR_APPROVAL->value);
    $assert('7.4 El reintegro sigue activo (nadie lo da de baja por cancelar la avería)', (int)$inspectionRefund['is_active'] === 1);
    $assert('7.5 La identidad del reclamante se preserva intacta', $inspectionRefund['claimant_name'] === 'Consumidor de la suite integral' && $inspectionRefund['claimed_amount'] !== null);
    $assert('7.6 El efectivo ya depositado con PIN se desvincula pero NO se reinicia', $depositedRefund['incident_id'] === null && $depositedRefund['status'] === RefundStatus::DEPOSITED_AT_RECEPTION->value);
    $assert('7.7 La desvinculación protege también al expediente con PIN entregado', (int)$depositedRefund['is_active'] === 1);
    $assert('7.8 La desvinculación es selectiva: el reintegro de otra avería conserva su vínculo', (int)$untouchedRefund['incident_id'] === $otherIncidentId && $untouchedRefund['status'] === RefundStatus::PENDING_INSPECTION->value);
    $assert('7.9 Cada desvinculación deja su evento inmutable', $auditCount('REFUND_REQUEST', $inspectionRefundId, 'REFUND_DETACHED_BY_INACTIVITY') === 1 && $auditCount('REFUND_REQUEST', $depositedRefundId, 'REFUND_DETACHED_BY_INACTIVITY') === 1);
    $refundAudit = $auditRow('REFUND_REQUEST', $inspectionRefundId, 'REFUND_DETACHED_BY_INACTIVITY');
    $assert('7.10 El evento atestigua el corte del vínculo y la conservación del estado', str_contains((string)$refundAudit['previous_state'], 'PENDING_INSPECTION') && str_contains((string)$refundAudit['new_state'], 'incident_id":null'));

} finally {
    echo "\n--- Limpieza de las filas propias de la suite ---\n";    // Los reintegros desvinculados quedan con `incident_id = NULL`, así que se
    // reenganchan a su avería para que el purgado dirigido los arrastre como hijas
    // dentro de la ventana que abre `TestDataCleaner` (el disparador del Art. III.1
    // prohíbe borrar un expediente de reintegro a pelo; la suite de T-PAUSE-09 usa
    // exactamente este procedimiento).
    foreach ($refundIds as $refundId) {
        $owner = $refundOwners[$refundId] ?? null;
        if ($owner === null) {
            continue;
        }

        $pdo->prepare('UPDATE `refund_requests` SET `incident_id` = :incident WHERE `id` = :refund')
            ->execute([':incident' => $owner, ':refund' => $refundId]);
    }

    foreach ($incidentIds as $incidentId) {
        TestDataCleaner::purgeIncident($pdo, $incidentId);
    }


    foreach ($machineIds as $machineId) {
        $pdo->prepare('DELETE FROM `sanitary_certificates` WHERE `machine_id` = :id')->execute([':id' => $machineId]);
        $pdo->prepare("DELETE FROM `audit_log` WHERE `entity_type` = 'MACHINE' AND `entity_id` = :id")->execute([':id' => $machineId]);
        $pdo->prepare('DELETE FROM `machines` WHERE `id` = :id')->execute([':id' => $machineId]);
    }

    echo '  [OK] Expedientes: ' . count($incidentIds) . ' · Reintegros: ' . count($refundIds) . ' · Máquinas: ' . count($machineIds) . " (purgados sin tocar las filas sembradas)\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-11 CUMPLIDA.\n"
    : " RESULTADO: HAY FALLOS. CONDICIÓN T-PAUSE-11 NO CUMPLIDA.\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
