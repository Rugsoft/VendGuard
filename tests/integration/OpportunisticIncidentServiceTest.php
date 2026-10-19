<?php

declare(strict_types=1);

/**
 * OpportunisticIncidentServiceTest (Módulo 16 · T-OPP-08)
 *
 * Certifica contra PostgreSQL real que `OpportunisticIncidentService` orquesta,
 * en una sola unidad de trabajo, las tres superficies del módulo:
 *
 *   1. **Catálogo de sede (`getLocationOpportunisticCatalog`):** revalida la
 *      habilitación, clasifica cada máquina con su acción exacta, publica el
 *      conflicto de coexistencia de otra sede (RF-02.2) y la advertencia
 *      sanitaria de ruta (RF-03.1).
 *   2. **Alta Oportunista (`createOpportunisticIncident`, Flujo A):** rechaza
 *      campos bancarios con `422 CASH_REFUND_PRIVACY_VIOLATION`, abre el
 *      expediente `NEW` en curso con su `sla_target_at`, remapea el choque del
 *      índice único a `409 ACTIVE_INCIDENT_ALREADY_EXISTS_ASSUME_INSTEAD` con el
 *      ticket existente y firma el hecho en `audit_log`.
 *   3. **Asunción y reapertura (`assumeOpportunisticIncident`, Flujo B):**
 *      asunción directa sin correo redundante a la Sede, traspaso con
 *      confirmación expresa y nota interna, reapertura en garantía y sustitución
 *      atómica por Avería Crónica en la 3.ª reincidencia.
 *
 * Las validaciones de habilitación, coexistencia y cadena de frío se ejecutan a
 * través del servicio (no de las políticas aisladas): es lo que exige la tarea
 * T-OPP-08. Los bordes exactos de esas políticas ya están certificados por las
 * suites unitarias de T-OPP-04.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en español.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\NotificationOutboxEnqueuerService;
use VendGuard\Application\Service\OpportunisticIncidentService;
use VendGuard\Core\Domain\Exception\ActiveInterventionConflictException;
use VendGuard\Core\Domain\Exception\CashRefundPrivacyViolationException;
use VendGuard\Core\Domain\Exception\ColdChainRouteWarningConfirmationException;
use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Exception\MachineInactiveException;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Exception\TakeoverConfirmationRequiredException;
use VendGuard\Core\Domain\Exception\WarrantyExpiredException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Domain\ValueObject\OpportunisticActionType;
use VendGuard\Core\Domain\ValueObject\OpportunisticMode;
use VendGuard\Core\Domain\ValueObject\OpportunisticOriginReason;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Notification\NotificationOutboxEnqueuerFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard · Módulo 16 · T-OPP-08 · Servicio de avería oportunista\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();

$incidentRepo = new PdoIncidentRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$auditRepo = new PdoAuditLogRepository($pdo);

$enqueuer = NotificationOutboxEnqueuerFactory::create();
$service = new OpportunisticIncidentService(
    opportunistic: $incidentRepo,
    machines: $machineRepo,
    locations: $locationRepo,
    incidents: $incidentRepo,
    transactions: new VendGuard\Infrastructure\Database\PdoTransactionManager($pdo),
    auditLogger: new AuditLogger($auditRepo),
    notifications: $enqueuer
);

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

$jordi = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$marta = $userRepo->findByEmail('marta.ruta@vendguard.internal');
$assert('0.1 Técnicos semilla disponibles (Jordi y Marta)', $jordi !== null && $marta !== null);
if ($jordi === null || $marta === null) {
    echo "\nNo se puede certificar sin los técnicos semilla.\n";
    exit(1);
}

$machineCodes = ['VEND-0301', 'VEND-0302', 'VEND-0501', 'VEND-0502'];
$machines = [];
$purge = static function (PDO $pdo, array $machines): void {
    foreach ($machines as $machine) {
        TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machine->getId());
    }
};

foreach ($machineCodes as $code) {
    $machine = $machineRepo->findByCode($code);
    $assert("0.2 Máquina semilla {$code} disponible", $machine !== null);
    if ($machine !== null) {
        $machines[$code] = $machine;
    }
}
$purge($pdo, $machines);

$locationThree = (int)$machines['VEND-0301']->getLocationId();
$locationFive = (int)$machines['VEND-0501']->getLocationId();
$assert('0.3 Las máquinas 03xx comparten sede y las 05xx otra distinta', $locationThree !== $locationFive);

$stamp = static fn (int $offsetSeconds = 0): string => EngineClock::shift($offsetSeconds);

/**
 * Crea un expediente de fixture sobre la máquina indicada.
 *
 * @param array<string, mixed> $overrides
 */
$fixtureIncident = static function (array $overrides = []) use ($stamp): Incident {
    $base = [
        'id' => null,
        'ticketCode' => 'INC-2026-S08' . random_int(10, 99),
        'machineId' => 1,
        'locationId' => 1,
        'category' => IncidentCategory::PRODUCT_JAM,
        'description' => 'Atasco en espiral B4 detectado durante la visita a la sede.',
        'urgency' => UrgencyLevel::MEDIUM,
        'status' => IncidentStatus::ASSIGNED,
        'assignedTechnicianId' => null,
        'reporterName' => 'Fixture T-OPP-08',
        'assignedAt' => $stamp(),
        'cycleStartedAt' => $stamp(),
    ];

    return new Incident(...array_merge($base, $overrides));
};

/** @return list<array<string, mixed>> */
$outboxRows = static function (int $incidentId, ?string $template = null) use ($pdo): array {
    $sql = 'SELECT * FROM notification_outbox WHERE incident_id = :id AND deleted_at IS NULL';
    $params = [':id' => $incidentId];
    if ($template !== null) {
        $sql .= ' AND template = :template';
        $params[':template'] = $template;
    }
    $sql .= ' ORDER BY id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$hasAudit = static function (int $incidentId, string $action) use ($auditRepo): bool {
    return $auditRepo->findEvents([
        'entity_type' => 'TICKET',
        'entity_id' => $incidentId,
        'action' => $action,
    ], 1) !== [];
};

$rawRow = static function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM incidents WHERE id = :id');
    $stmt->execute([':id' => $incidentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : [];
};

// ---------------------------------------------------------------------
// ANCLA DE HABILITACIÓN: una parada ASSIGNED del técnico en la sede 3.
// Sin ella no existe habilitación y todo el módulo responde 403.
// ---------------------------------------------------------------------
$anchor = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-S08AN',
        'machineId' => $machines['VEND-0301']->getId(),
        'locationId' => $locationThree,
        'status' => IncidentStatus::ASSIGNED,
        'assignedTechnicianId' => $jordi->getId(),
    ]),
    $jordi->getId(),
    'Parada ancla de la certificación T-OPP-08'
);
$assert('0.4 Parada ancla ASSIGNED creada para habilitar la sede', $anchor->getId() !== null);

// ---------------------------------------------------------------------
// CASO 1 · Catálogo de la sede
// ---------------------------------------------------------------------
echo "\n--- Caso 1: Catálogo de máquinas de la sede habilitada (RF-01/RF-03) ---\n";

$catalog = $service->getLocationOpportunisticCatalog($jordi->getId(), $locationThree);
$catalogPayload = $catalog->toArray();

$assert(
    '1.1 La sede se habilita por parada activa',
    ($catalogPayload['location']['enablement_type'] ?? null) === 'ACTIVE_STOP'
);
$assert(
    '1.2 La máquina sin expediente propone CREATE_NEW',
    ($catalog->machineById((int)$machines['VEND-0302']->getId())['opportunistic_action'] ?? null)
        === OpportunisticActionType::CREATE_NEW->value
);
$assert(
    '1.3 La máquina con parada propia propone ASSUME_DIRECT',
    ($catalog->machineById((int)$machines['VEND-0301']->getId())['opportunistic_action'] ?? null)
        === OpportunisticActionType::ASSUME_DIRECT->value
);
$assert(
    '1.4 El contrato del catálogo expone las cuatro raíces',
    array_keys($catalogPayload) === ['location', 'active_intervention_conflict', 'cold_chain_route_warning', 'machines']
);
$assert('1.5 Sin conflicto de coexistencia', $catalog->hasActiveInterventionConflict() === false);

// ---------------------------------------------------------------------
// CASO 2 · Privacidad del Art. V.4 y validaciones de máquina
// ---------------------------------------------------------------------
echo "\n--- Caso 2: Bloqueo de datos bancarios y de máquina inválida (Art. V.4, RF-01.5) ---\n";

$baseCreatePayload = [
    'machine_id' => $machines['VEND-0302']->getId(),
    'category' => 'PRODUCT_JAM',
    'origin_reason' => 'TECHNICIAN_DETECTION',
    'description' => 'Atasco en la espiral B4 detectado durante la visita.',
];

$privacyFailure = null;
try {
    $service->createOpportunisticIncident($jordi, $baseCreatePayload + ['iban' => 'ES00 0000 0000 0000 0000 0000']);
} catch (CashRefundPrivacyViolationException $e) {
    $privacyFailure = $e;
}
$assert(
    '2.1 Un IBAN en el parte se rechaza con 422 CASH_REFUND_PRIVACY_VIOLATION',
    $privacyFailure instanceof CashRefundPrivacyViolationException
        && $privacyFailure->getHttpStatusCode() === 422
        && $privacyFailure->getViolatingFields() === ['iban']
);

$emptyFieldFailure = null;
try {
    $service->createOpportunisticIncident($jordi, $baseCreatePayload + ['refund_requested' => '']);
} catch (CashRefundPrivacyViolationException $e) {
    $emptyFieldFailure = $e;
}
$assert(
    '2.2 El campo prohibido se rechaza aunque llegue vacío',
    $emptyFieldFailure instanceof CashRefundPrivacyViolationException
);

$missingMachineFailure = null;
try {
    $service->createOpportunisticIncident($jordi, ['machine_id' => 999999] + $baseCreatePayload);
} catch (MachineNotFoundException $e) {
    $missingMachineFailure = $e;
}
$assert(
    '2.3 Una máquina inexistente responde 404 MACHINE_NOT_FOUND',
    $missingMachineFailure instanceof MachineNotFoundException
        && $missingMachineFailure->getErrorCode() === 'MACHINE_NOT_FOUND'
);

$deactivated = $machines['VEND-0502'];
$pdo->prepare('UPDATE machines SET is_active = FALSE, is_blocked_no_access = FALSE WHERE id = :id')
    ->execute([':id' => $deactivated->getId()]);

$inactiveFailure = null;
try {
    $service->createOpportunisticIncident($jordi, ['machine_id' => $deactivated->getId()] + $baseCreatePayload);
} catch (MachineInactiveException $e) {
    $inactiveFailure = $e;
}
$assert(
    '2.4 Una baja definitiva responde 422 MACHINE_INACTIVE (RF-01.5)',
    $inactiveFailure instanceof MachineInactiveException
        && $inactiveFailure->getHttpStatusCode() === 422
);

$pdo->prepare('UPDATE machines SET is_active = TRUE WHERE id = :id')->execute([':id' => $deactivated->getId()]);

// ---------------------------------------------------------------------
// CASO 3 · Alta Oportunista NEW
// ---------------------------------------------------------------------
echo "\n--- Caso 3: Alta Oportunista In Situ (Flujo A · RF-04) ---\n";

$created = $service->createOpportunisticIncident($jordi, $baseCreatePayload);
$createdId = (int)$created['id'];

$assert(
    '3.1 El alta nace directamente en IN_PROGRESS y marcada NEW',
    $created['status'] === 'IN_PROGRESS'
        && $created['opportunistic_mode'] === 'NEW'
        && $created['opportunistic_mode_label'] === 'Oportunista In Situ · Nueva'
);
$assert(
    '3.2 El submotivo y el técnico quedan persistidos',
    $created['opportunistic_origin_reason'] === 'TECHNICIAN_DETECTION'
        && (int)$created['assigned_technician_id'] === $jordi->getId()
);
$assert(
    '3.3 El vencimiento contractual viaja con el expediente',
    is_string($created['sla_target_at']) && $created['sla_target_at'] !== ''
);

$rawCreated = $rawRow($createdId);
$assert(
    '3.4 La fila de incidents refleja modalidad y SLA',
    ($rawCreated['opportunistic_mode'] ?? null) === 'NEW'
        && ($rawCreated['sla_target_at'] ?? null) !== null
);
$assert(
    '3.5 El alta deja su primer evento inmutable de historial',
    count($incidentRepo->getHistory($createdId)) === 1
);
$assert(
    '3.6 La auditoría firma INCIDENT_CREATED_OPPORTUNISTIC (Art. III)',
    $hasAudit($createdId, 'INCIDENT_CREATED_OPPORTUNISTIC')
);
$assert(
    '3.7 La Sede recibe INCIDENT_OPENED_SITE dentro de la misma transacción',
    $outboxRows($createdId, NotificationTemplate::INCIDENT_OPENED_SITE->value) !== []
);

// ---------------------------------------------------------------------
// CASO 4 · Alta concurrente sobre la misma máquina
// ---------------------------------------------------------------------
echo "\n--- Caso 4: Alta concurrente → 409 ACTIVE_INCIDENT_ALREADY_EXISTS_ASSUME_INSTEAD ---\n";

$duplicateFailure = null;
try {
    $service->createOpportunisticIncident($jordi, $baseCreatePayload);
} catch (DuplicateIncidentException $e) {
    $duplicateFailure = $e;
}
$assert(
    '4.1 El choque de índice único se remapea al código de asunción inmediata',
    $duplicateFailure instanceof DuplicateIncidentException
        && $duplicateFailure->getErrorCode() === 'ACTIVE_INCIDENT_ALREADY_EXISTS_ASSUME_INSTEAD'
        && $duplicateFailure->getHttpStatusCode() === 409
);
$assert(
    '4.2 La respuesta transporta el ticket existente y su acción',
    $duplicateFailure !== null
        && ($duplicateFailure->getDetails()['existing_incident']['id'] ?? null) === $createdId
        && ($duplicateFailure->getDetails()['opportunistic_action'] ?? null)
            === OpportunisticActionType::CURRENT_TECHNICIAN_IN_PROGRESS->value
);

// ---------------------------------------------------------------------
// CASO 5 · Asunción directa sin técnico previo
// ---------------------------------------------------------------------
echo "\n--- Caso 5: Asunción directa ASSUMED sin correo redundante a la Sede (RF-05.2) ---\n";

TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machines['VEND-0302']->getId());
$registered = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-S08B1',
        'machineId' => $machines['VEND-0302']->getId(),
        'locationId' => $locationThree,
        'status' => IncidentStatus::REGISTERED,
        'assignedTechnicianId' => null,
        'assignedAt' => null,
    ]),
    null,
    'Aviso registrado por la sede'
);

$claimed = $service->assumeOpportunisticIncident($jordi, (int)$registered->getId(), []);
$assert(
    '5.1 La asunción pasa el expediente a curso marcado ASSUMED',
    $claimed['status'] === 'IN_PROGRESS'
        && $claimed['opportunistic_mode'] === 'ASSUMED'
        && $claimed['opportunistic_origin_reason'] === 'EXISTING_TICKET_CLAIM'
);
$assert(
    '5.2 Se firma INCIDENT_ASSUMED_OPPORTUNISTIC',
    $hasAudit((int)$registered->getId(), 'INCIDENT_ASSUMED_OPPORTUNISTIC')
);
$assert(
    '5.3 Cero correo redundante a la Sede (RF-06.3.1)',
    $outboxRows((int)$registered->getId(), NotificationTemplate::INCIDENT_OPENED_SITE->value) === []
);

// ---------------------------------------------------------------------
// CASO 6 · Traspaso desde otro técnico
// ---------------------------------------------------------------------
echo "\n--- Caso 6: Traspaso con confirmación, nota interna y desasignación solo por EMAIL (RF-05.3) ---\n";

TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machines['VEND-0302']->getId());
$assignedToMarta = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-S08B2',
        'machineId' => $machines['VEND-0302']->getId(),
        'locationId' => $locationThree,
        'status' => IncidentStatus::ASSIGNED,
        'assignedTechnicianId' => $marta->getId(),
    ]),
    null,
    'Asignación ordinaria a Marta'
);

$takeoverFailure = null;
try {
    $service->assumeOpportunisticIncident($jordi, (int)$assignedToMarta->getId(), []);
} catch (TakeoverConfirmationRequiredException $e) {
    $takeoverFailure = $e;
}
$assert(
    '6.1 Sin confirmación expresa el traspaso responde 409',
    $takeoverFailure instanceof TakeoverConfirmationRequiredException
        && $takeoverFailure->getPreviousTechnicianId() === $marta->getId()
);

$takenOver = $service->assumeOpportunisticIncident($jordi, (int)$assignedToMarta->getId(), [
    'confirm_takeover_from_other_tech' => true,
    'takeover_internal_note' => 'La máquina está en el pasillo contiguo; reviso el monedero por presencia física.',
]);
$assert(
    '6.2 El traspaso confirmado deja el expediente a nombre del técnico presente',
    $takenOver['status'] === 'IN_PROGRESS'
        && (int)$takenOver['assigned_technician_id'] === $jordi->getId()
        && (int)$takenOver['previous_technician_id'] === $marta->getId()
);

$noteStmt = $pdo->prepare('SELECT * FROM incident_comments WHERE incident_id = :id ORDER BY id DESC LIMIT 1');
$noteStmt->execute([':id' => $assignedToMarta->getId()]);
$noteRow = $noteStmt->fetch(PDO::FETCH_ASSOC);
$assert(
    '6.3 La nota de traspaso queda como comentario interno (Art. V.4)',
    $noteRow !== false && !empty($noteRow['is_internal'])
);

$unassignedRows = $outboxRows((int)$assignedToMarta->getId(), NotificationTemplate::TECHNICIAN_UNASSIGNED->value);
$assert(
    '6.4 La desasignación viaja SOLO por EMAIL, sin Telegram (RF-TG-06)',
    $unassignedRows !== []
        && array_values(array_unique(array_column($unassignedRows, 'channel')))
            === [NotificationChannel::EMAIL->value]
);
$assert(
    '6.5 La auditoría firma INCIDENT_TAKEOVER_OPPORTUNISTIC',
    $hasAudit((int)$assignedToMarta->getId(), 'INCIDENT_TAKEOVER_OPPORTUNISTIC')
);

// ---------------------------------------------------------------------
// CASO 7 · Reapertura in situ en garantía
// ---------------------------------------------------------------------
echo "\n--- Caso 7: Reapertura en garantía (RF-05.4) ---\n";

TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machines['VEND-0302']->getId());
$resolved = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-S08C1',
        'machineId' => $machines['VEND-0302']->getId(),
        'locationId' => $locationThree,
        'status' => IncidentStatus::RESOLVED,
        'assignedTechnicianId' => $jordi->getId(),
        'resolvedAt' => $stamp(-3600),
        'resolutionDiagnosis' => 'Diagnóstico de la primera reparación del monedero.',
        'resolutionAction' => 'Acción correctiva aplicada en el primer servicio técnico.',
    ]),
    $jordi->getId(),
    'Aviso resuelto que reaparecerá en garantía'
);

$shortReasonFailure = null;
try {
    $service->assumeOpportunisticIncident($jordi, (int)$resolved->getId(), ['reopen_reason' => 'falla']);
} catch (InvalidArgumentException $e) {
    $shortReasonFailure = $e;
}
$assert('7.1 Un motivo de reincidencia corto se rechaza', $shortReasonFailure !== null);

$missingReasonFailure = null;
try {
    $service->assumeOpportunisticIncident($jordi, (int)$resolved->getId(), []);
} catch (InvalidArgumentException $e) {
    $missingReasonFailure = $e;
}
$assert('7.2 Sin motivo de reincidencia la reapertura se rechaza', $missingReasonFailure !== null);

$reopened = $service->assumeOpportunisticIncident($jordi, (int)$resolved->getId(), [
    'reopen_reason' => 'El monedero vuelve a no devolver cambio tras la reparación.',
]);
$assert(
    '7.3 La 1.ª reincidencia reabre el mismo ticket marcado ASSUMED',
    (int)$reopened['id'] === (int)$resolved->getId()
        && $reopened['status'] === 'IN_PROGRESS'
        && $reopened['opportunistic_mode'] === 'ASSUMED'
        && $reopened['opportunistic_origin_reason'] === 'WARRANTY_REOPEN_IN_SITU'
        && $reopened['chronic_replacement_created'] === false
);
$assert(
    '7.4 La auditoría firma INCIDENT_REOPENED_IN_SITU',
    $hasAudit((int)$resolved->getId(), 'INCIDENT_REOPENED_IN_SITU')
);
$assert(
    '7.5 La Sede recibe el aviso de reapertura in situ',
    $outboxRows((int)$resolved->getId(), NotificationTemplate::INCIDENT_REOPENED_IN_SITU_SITE->value) !== []
);

// Garantía expirada: 49 horas después de la resolución.
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machines['VEND-0302']->getId());
$expired = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-S08C2',
        'machineId' => $machines['VEND-0302']->getId(),
        'locationId' => $locationThree,
        'status' => IncidentStatus::RESOLVED,
        'assignedTechnicianId' => $jordi->getId(),
        'resolvedAt' => $stamp(-49 * 3600),
        'resolutionDiagnosis' => 'Diagnóstico de la reparación caducada en garantía.',
        'resolutionAction' => 'Acción correctiva aplicada antes de caducar la garantía.',
    ]),
    $jordi->getId(),
    'Aviso resuelto fuera de la garantía de 48 h'
);

$expiredFailure = null;
try {
    $service->assumeOpportunisticIncident($jordi, (int)$expired->getId(), [
        'reopen_reason' => 'El fallo persiste fuera del plazo de garantía de 48 horas.',
    ]);
} catch (WarrantyExpiredException $e) {
    $expiredFailure = $e;
}
$assert(
    '7.6 Fuera de las 48 h responde 422 REOPEN_WINDOW_EXPIRED (Art. V.6)',
    $expiredFailure instanceof WarrantyExpiredException
        && $expiredFailure->getErrorCode() === 'REOPEN_WINDOW_EXPIRED'
);

// ---------------------------------------------------------------------
// CASO 8 · 3.ª reincidencia · Avería Crónica
// ---------------------------------------------------------------------
echo "\n--- Caso 8: 3.ª reincidencia · sustitución por Avería Crónica (RF-05.4.2) ---\n";

TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machines['VEND-0302']->getId());
$chronicCandidate = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-S08D1',
        'machineId' => $machines['VEND-0302']->getId(),
        'locationId' => $locationThree,
        'status' => IncidentStatus::RESOLVED,
        'assignedTechnicianId' => $jordi->getId(),
        'resolvedAt' => $stamp(-600),
        'resolutionDiagnosis' => 'Diagnóstico de la segunda reparación del monedero.',
        'resolutionAction' => 'Acción correctiva aplicada en el segundo servicio técnico.',
    ]),
    $jordi->getId(),
    'Aviso que acumulará dos reaperturas'
);

// Dos ciclos REOPENED -> RESOLVED que hacen crónica la tercera reincidencia.
$incidentRepo->insertHistory((int)$chronicCandidate->getId(), $jordi->getId(), 'RESOLVED', 'REOPENED', 'Primera reapertura simulada.');
$incidentRepo->insertHistory((int)$chronicCandidate->getId(), $jordi->getId(), 'REOPENED', 'IN_PROGRESS', 'Reanudación simulada.');
$incidentRepo->insertHistory((int)$chronicCandidate->getId(), $jordi->getId(), 'IN_PROGRESS', 'RESOLVED', 'Nueva resolución simulada.');
$incidentRepo->insertHistory((int)$chronicCandidate->getId(), $jordi->getId(), 'RESOLVED', 'REOPENED', 'Segunda reapertura simulada.');
$incidentRepo->insertHistory((int)$chronicCandidate->getId(), $jordi->getId(), 'REOPENED', 'IN_PROGRESS', 'Reanudación simulada.');
$incidentRepo->insertHistory((int)$chronicCandidate->getId(), $jordi->getId(), 'IN_PROGRESS', 'RESOLVED', 'Resolución final simulada.');
$assert(
    '8.1 El predecesor acumula las dos reaperturas que hacen crónica la tercera',
    $incidentRepo->countReopenEvents((int)$chronicCandidate->getId()) === 2
);

$chronic = $service->assumeOpportunisticIncident($jordi, (int)$chronicCandidate->getId(), [
    'reopen_reason' => 'Tercera reincidencia del mismo monedero en menos de 48 horas.',
]);
$successorId = (int)$chronic['id'];
$rawPredecessor = $rawRow((int)$chronicCandidate->getId());

$assert(
    '8.2 La 3.ª reincidencia devuelve el sucesor limpio en curso',
    $chronic['chronic_replacement_created'] === true
        && $successorId !== (int)$chronicCandidate->getId()
        && $chronic['status'] === 'IN_PROGRESS'
        && $chronic['opportunistic_mode'] === 'NEW'
        && $chronic['opportunistic_origin_reason'] === 'WARRANTY_REOPEN_IN_SITU'
);
$assert(
    '8.3 El predecesor queda CLOSED con la marca [AVERÍA CRÓNICA]',
    ($rawPredecessor['status'] ?? null) === 'CLOSED'
        && str_contains((string)($rawPredecessor['reopen_reason'] ?? ''), '[AVERÍA CRÓNICA]')
);
$assert(
    '8.4 El sucesor enlaza su predecesor y libera el índice único',
    $chronic['chronic_predecessor_incident_id'] === (int)$chronicCandidate->getId()
        && $incidentRepo->findActiveByMachineId((int)$machines['VEND-0302']->getId())?->getId() === $successorId
);
$assert(
    '8.5 La auditoría firma INCIDENT_CHRONIC_REPLACED sin romper el Art. V.1',
    $hasAudit((int)$chronicCandidate->getId(), 'INCIDENT_CHRONIC_REPLACED')
);
$assert(
    '8.6 Coordinación recibe la alerta crónica y la Sede el nuevo expediente',
    $outboxRows($successorId, NotificationTemplate::INCIDENT_OPENED_SITE->value) !== []
);

// ---------------------------------------------------------------------
// CASO 9 · Salvaguarda sanitaria de ruta (Art. II)
// ---------------------------------------------------------------------
echo "\n--- Caso 9: Advertencia de cadena de frío en otra sede (RF-03.1/RF-03.2) ---\n";

TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machines['VEND-0302']->getId());
$criticalStop = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-S08E1',
        'machineId' => $machines['VEND-0501']->getId(),
        'locationId' => $locationFive,
        'status' => IncidentStatus::ASSIGNED,
        'assignedTechnicianId' => $jordi->getId(),
        'category' => IncidentCategory::TEMPERATURE_COLD,
        'urgency' => UrgencyLevel::CRITICAL,
    ]),
    null,
    'Parada crítica de frío esperando en otra sede'
);

$catalogWithWarning = $service->getLocationOpportunisticCatalog($jordi->getId(), $locationThree);
$assert(
    '9.1 El catálogo anuncia la advertencia sanitaria pendiente',
    $catalogWithWarning->requiresColdChainConfirmation() === true
        && ($catalogWithWarning->toArray()['cold_chain_route_warning']['pending_critical_stops'][0]['ticket_code'] ?? null)
            === 'INC-2026-S08E1'
);
$assert('9.2 La parada crítica ajena no bloquea la coexistencia', $catalogWithWarning->hasActiveInterventionConflict() === false);

$coldFailure = null;
try {
    $service->createOpportunisticIncident($jordi, $baseCreatePayload);
} catch (ColdChainRouteWarningConfirmationException $e) {
    $coldFailure = $e;
}
$assert(
    '9.3 Sin confirmación expresa el alta responde 409 COLD_CHAIN_ROUTE_WARNING_CONFIRMATION_REQUIRED',
    $coldFailure instanceof ColdChainRouteWarningConfirmationException
        && $coldFailure->getPendingCriticalStops() !== []
);

$overridden = $service->createOpportunisticIncident($jordi, $baseCreatePayload + [
    'confirm_cold_chain_override' => true,
]);
$overriddenId = (int)$overridden['id'];
$assert(
    '9.4 Confirmada la postergación, el alta se ejecuta y se declara alertada',
    $overridden['cold_chain_override_alerted'] === true && $overridden['status'] === 'IN_PROGRESS'
);
$assert(
    '9.5 Se firma COLD_CHAIN_ROUTE_POSTPONED_CONFIRMED (RF-03.2.2)',
    $hasAudit($overriddenId, 'COLD_CHAIN_ROUTE_POSTPONED_CONFIRMED')
);
$assert(
    '9.6 Coordinación recibe OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT',
    $outboxRows($overriddenId, NotificationTemplate::OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT->value) !== []
);

// ---------------------------------------------------------------------
// CASO 10 · Coexistencia entre sedes (RF-02.2)
// ---------------------------------------------------------------------
echo "\n--- Caso 10: Intervención abierta en otra sede (RF-02.2) ---\n";

TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machines['VEND-0302']->getId());

$blocking = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-S08F1',
        'machineId' => $machines['VEND-0502']->getId(),
        'locationId' => $locationFive,
        'status' => IncidentStatus::IN_PROGRESS,
        'assignedTechnicianId' => $jordi->getId(),
        'startedAt' => $stamp(),
    ]),
    null,
    'Intervención en curso en otra sede'
);

$conflict = $service->getLocationOpportunisticCatalog($jordi->getId(), $locationThree);
$assert(
    '10.1 El catálogo publica el conflicto de coexistencia',
    $conflict->hasActiveInterventionConflict() === true
        && ($conflict->activeInterventionConflict['ticket_code'] ?? null) === 'INC-2026-S08F1'
);

$conflictFailure = null;
try {
    $service->createOpportunisticIncident($jordi, $baseCreatePayload);
} catch (ActiveInterventionConflictException $e) {
    $conflictFailure = $e;
}
$assert(
    '10.2 El alta en otra sede responde 409 ACTIVE_INTERVENTION_AT_DIFFERENT_LOCATION',
    $conflictFailure instanceof ActiveInterventionConflictException
        && $conflictFailure->getHttpStatusCode() === 409
);

// ---------------------------------------------------------------------
// CASO 11 · Reanudación de una parada PROPIA (RF-05.2, caso 2)
// ---------------------------------------------------------------------
echo "\n--- Caso 11: el técnico reanuda su propia parada sin convertirla en oportunista ---\n";

// El caso 10 deja una intervención del técnico EN CURSO en otra sede a propósito:
// aquí se releva para no heredar su conflicto de coexistencia (RF-02.2).
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machines['VEND-0502']->getId());
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machines['VEND-0501']->getId());
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machines['VEND-0302']->getId());

$ownAssigned = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-S08G1',
        'machineId' => $machines['VEND-0302']->getId(),
        'locationId' => $locationThree,
        'status' => IncidentStatus::ASSIGNED,
        'assignedTechnicianId' => $jordi->getId(),
    ]),
    $jordi->getId(),
    'Parada ordinaria propia que el técnico retoma in situ'
);
$ownAssignedId = (int)$ownAssigned->getId();

$resumedOwn = $service->assumeOpportunisticIncident($jordi, $ownAssignedId, []);
$rawResumedOwn = $rawRow($ownAssignedId);

$assert(
    '11.1 La parada propia asignada pasa a IN_PROGRESS sin pedir confirmación',
    $resumedOwn['status'] === 'IN_PROGRESS'
        && $resumedOwn['assigned_technician_id'] === $jordi->getId()
);
$assert(
    '11.2 No se inventa modalidad oportunista sobre una parada ordinaria',
    $resumedOwn['opportunistic_mode'] === null
        && ($rawResumedOwn['opportunistic_mode'] ?? null) === null
        && $resumedOwn['previous_technician_id'] === null
);
$assert(
    '11.3 El arranque real queda persistido en el expediente',
    ($rawResumedOwn['status'] ?? null) === 'IN_PROGRESS'
        && ($rawResumedOwn['started_at'] ?? null) !== null
);
$assert(
    '11.4 El historial documenta la reanudación desde el estado real',
    (static function () use ($incidentRepo, $ownAssignedId): bool {
        $history = $incidentRepo->getHistory($ownAssignedId);
        $last = end($history);

        return $last !== false
            && $last->getFromStatus() === 'ASSIGNED'
            && $last->getToStatus() === 'IN_PROGRESS';
    })()
);
$assert(
    '11.5 No se envía correo redundante a la Sede al reanudar lo propio (RF-06.3.1)',
    $outboxRows($ownAssignedId, NotificationTemplate::INCIDENT_OPENED_SITE->value) === []
);

// La variante pausada es la que cierra el intervalo vivo y desplaza el SLA:
// reanudarla por el camino oportunista no puede saltarse el servicio de pausas.
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machines['VEND-0302']->getId());
$ownPaused = $incidentRepo->create(
    $fixtureIncident([
        'ticketCode' => 'INC-2026-S08G2',
        'machineId' => $machines['VEND-0302']->getId(),
        'locationId' => $locationThree,
        'status' => IncidentStatus::PENDING_INFO,
        'assignedTechnicianId' => $jordi->getId(),
        'slaTargetAt' => $stamp(28800),
    ]),
    $jordi->getId(),
    'Parada propia pausada esperando información de la sede'
);
$ownPausedId = (int)$ownPaused->getId();
// `create()` no publica el intervalo vivo de pausa: se abre aquí con la misma
// marca que usaría una pausa real, para que el servicio de pausas tenga qué cerrar.
$pdo->prepare('UPDATE incidents SET paused_at = :paused_at WHERE id = :id')->execute([
    ':paused_at' => $stamp(-3600),
    ':id' => $ownPausedId,
]);

$resumedPaused = $service->assumeOpportunisticIncident($jordi, $ownPausedId, []);
$rawResumedPaused = $rawRow($ownPausedId);
$resumedPausedModel = $incidentRepo->findById($ownPausedId);

$assert(
    '11.6 La parada propia en PENDING_INFO vuelve a IN_PROGRESS',
    $resumedPaused['status'] === 'IN_PROGRESS'
        && ($rawResumedPaused['status'] ?? null) === 'IN_PROGRESS'
);
$assert(
    '11.7 El intervalo de pausa se cierra y se acumula para el MTTR',
    $resumedPausedModel !== null
        && $resumedPausedModel->getPausedAt() === null
        && $resumedPausedModel->getTotalPendingInfoSeconds() >= 3600
        && $resumedPausedModel->getTotalPendingInfoSeconds() < 3720,
    'acumulado=' . ($resumedPausedModel?->getTotalPendingInfoSeconds() ?? -1)
);
$assert(
    '11.8 El vencimiento contractual se desplaza en horario comercial (RF-03.3)',
    $resumedPausedModel !== null
        && $resumedPausedModel->getSlaTargetAt() !== null
        && strtotime((string)$resumedPausedModel->getSlaTargetAt()) > strtotime($stamp(28800))
);
$assert(
    '11.9 La reanudación pausada tampoco se etiqueta como oportunista',
    $resumedPaused['opportunistic_mode'] === null
        && $resumedPaused['previous_technician_id'] === null
        && $outboxRows($ownPausedId, NotificationTemplate::INCIDENT_OPENED_SITE->value) === []
);

// ---------------------------------------------------------------------
// LIMPIEZA
// ---------------------------------------------------------------------
$purge($pdo, $machines);

echo "\n======================================================================\n";
echo " Total Aserciones fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. T-OPP-08 CERTIFICADA.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
