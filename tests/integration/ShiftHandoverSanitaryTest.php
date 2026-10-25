<?php

declare(strict_types=1);

/**
 * ShiftHandoverSanitaryTest · T-ASH-05
 *
 * Certificación de integración del Protocolo Sanitario Fail-Safe Art. II al cierre de turno
 * (Módulo 21, Resolución R1):
 *
 *   1. Escenario Nivel 1: Máquina perecedera (FOOD_PERISHABLE) sin relevo manual y habiendo
 *      técnico de guardia (is_on_call = TRUE).
 *      -> Traspaso automático al técnico de guardia con nota interna (Art. V.4) y auditoría.
 *   2. Escenario Nivel 2: Máquina perecedera sin relevo manual y SIN guardia disponible.
 *      -> Desasignación inmediata a REGISTERED con alarma crítica y auditoría.
 *   3. Las averías ordinarias sobre máquinas no perecederas (SNACKS, HOT_DRINKS) permanecen
 *      retenidas con el técnico saliente para su siguiente jornada.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\HandoverTargetService;
use VendGuard\Application\Service\ShiftHandoverService;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Notification\NotificationOutboxEnqueuerFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoOnCallTechnicianRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - ShiftHandoverSanitaryTest (T-ASH-05)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

$shiftRepo = new PdoTechnicianShiftRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$onCallRepo = $userRepo;
$absenceRepo = new PdoTechnicianAbsenceRepository($pdo);
$auditRepo = new PdoAuditLogRepository($pdo);

$service = new ShiftHandoverService(
    pdo: $pdo,
    shiftRepo: $shiftRepo,
    userRepo: $userRepo,
    incidentRepo: $incidentRepo,
    machineRepo: $machineRepo,
    onCallRepo: $onCallRepo,
    auditRepo: $auditRepo,
    handoverTargets: new HandoverTargetService($userRepo, $shiftRepo, $absenceRepo),
    notifications: NotificationOutboxEnqueuerFactory::create($pdo)
);

$failures = 0;
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

try {
    // Localizar dos técnicos de prueba
    $techRows = $pdo->query("SELECT id FROM users WHERE role = 'TECHNICIAN' AND deleted_at IS NULL ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    $tech1 = (int)$techRows[0];
    $guardTech = (int)$techRows[1];

    // Localizar una máquina perecedera (PERISHABLE_FOOD o COMBO) y una ordinaria (SNACKS)
    $perishableMachineId = (int)$pdo->query("SELECT id FROM machines WHERE machine_type IN ('PERISHABLE_FOOD', 'COMBO') AND deleted_at IS NULL LIMIT 1")->fetchColumn();
    $snacksMachineId = (int)$pdo->query("SELECT id FROM machines WHERE machine_type = 'SNACKS' AND deleted_at IS NULL LIMIT 1")->fetchColumn();

    // ─────────────────────────────────────────────────────────────────────────
    // Escenario 1: Nivel 1 del Protocolo Sanitario (Traspaso a guardia 24/7)
    // ─────────────────────────────────────────────────────────────────────────
    // Marcar guardTech como técnico de guardia (is_on_call = TRUE)
    $onCallRepo->setOnCall($guardTech, true);

    // Abrir turno para tech1
    $shiftRepo->startShift($tech1);

    // Crear dos incidencias asignadas a tech1: una perecedera y una de snacks
    $stmtInc1 = $pdo->prepare("INSERT INTO incidents (ticket_code, location_id, machine_id, category, description, urgency, status, assigned_technician_id, assigned_at)
        VALUES ('INC-SAN-001', 1, :m_id, 'TEMPERATURE_COLD', 'Fallo termostato cámara refrigerada', 'HIGH', 'ASSIGNED', :tech_id, NOW())
        RETURNING id");
    $stmtInc1->execute([':m_id' => $perishableMachineId, ':tech_id' => $tech1]);
    $perishableIncidentId = (int)$stmtInc1->fetchColumn();

    $stmtInc2 = $pdo->prepare("INSERT INTO incidents (ticket_code, location_id, machine_id, category, description, urgency, status, assigned_technician_id, assigned_at)
        VALUES ('INC-SAN-002', 1, :m_id, 'PAYMENT_SYSTEM', 'Monedero atascado en snacks', 'LOW', 'ASSIGNED', :tech_id, NOW())
        RETURNING id");
    $stmtInc2->execute([':m_id' => $snacksMachineId, ':tech_id' => $tech1]);
    $snacksIncidentId = (int)$stmtInc2->fetchColumn();

    // Cierre de turno sin relevo manual
    $result1 = $service->closeAndHandover(
        technicianId: $tech1,
        targetTechnicianId: null,
        incidentIdsToTransfer: [],
        handoverNotes: null,
        closeReason: 'Cierre fin jornada Nivel 1'
    );

    $assert('Nivel 1: El turno de tech1 queda cerrado a OFF_SHIFT', $result1->shift->status === 'OFF_SHIFT');
    $assert('Nivel 1: La avería perecedera se deriva a guardia 24/7', count($result1->sanitaryEmergencyTransfers) === 1 && $result1->sanitaryEmergencyTransfers[0]['id'] === $perishableIncidentId);
    $assert('Nivel 1: La avería de snacks queda retenida con tech1', count($result1->retainedIncidents) === 1 && $result1->retainedIncidents[0]['id'] === $snacksIncidentId);

    // Verificar en BD que el ticket perecedero ahora pertenece al técnico de guardia
    $assignedToInDb = (int)$pdo->query("SELECT assigned_technician_id FROM incidents WHERE id = {$perishableIncidentId}")->fetchColumn();
    $assert('Nivel 1: BD confirma reasignación a guardTech', $assignedToInDb === $guardTech);

    $stmtLevel1Outbox = $pdo->prepare("SELECT COUNT(*) FROM notification_outbox
        WHERE incident_id = :incident_id
          AND template = 'TELEGRAM_INCIDENT_ASSIGNMENT'
          AND priority = 'PRIORITY'
          AND recipient_user_id = :technician_id
          AND deleted_at IS NULL");
    $stmtLevel1Outbox->execute([':incident_id' => $perishableIncidentId, ':technician_id' => $guardTech]);
    $assert(
        'Nivel 1: el guardia recibe el aviso propio y prioritario de relevo en notification_outbox',
        (int)$stmtLevel1Outbox->fetchColumn() >= 1
    );
    $stmtLevel1Ordinary = $pdo->prepare("SELECT COUNT(*) FROM notification_outbox
        WHERE incident_id = :incident_id
          AND template = 'TECHNICIAN_ASSIGNED'
          AND deleted_at IS NULL");
    $stmtLevel1Ordinary->execute([':incident_id' => $perishableIncidentId]);
    $assert(
        'Nivel 1: el traspaso sanitario no se degrada al aviso ordinario de asignación',
        (int)$stmtLevel1Ordinary->fetchColumn() === 0
    );

    // ─────────────────────────────────────────────────────────────────────────
    // Escenario 1b: guardia de vacaciones + guardia disponible
    // ─────────────────────────────────────────────────────────────────────────
    $secondGuard = (int)$pdo->query("SELECT id FROM users WHERE role = 'TECHNICIAN' AND id NOT IN ({$tech1}, {$guardTech}) AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("UPDATE users SET is_on_call = FALSE WHERE role = 'TECHNICIAN'");
    $onCallRepo->setOnCall($guardTech, true);
    $onCallRepo->setOnCall($secondGuard, true);

    $pdo->prepare(
        "INSERT INTO technician_absences (technician_id, reason, starts_at, ends_at, notes, created_by)
         VALUES (:technician_id, 'VACATION', NOW() - INTERVAL '1 hour', NOW() + INTERVAL '2 days',
                 'Vacaciones del guardia en la prueba sanitaria', :created_by)"
    )->execute([':technician_id' => $guardTech, ':created_by' => $tech1]);

    $shiftRepo->startShift($tech1);
    $pdo->exec("UPDATE incidents SET assigned_technician_id = {$tech1}, status = 'ASSIGNED' WHERE id = {$perishableIncidentId}");

    $stmtVacationOutbox = $pdo->prepare("SELECT COUNT(*) FROM notification_outbox
        WHERE incident_id = :incident_id
          AND template = 'TELEGRAM_INCIDENT_ASSIGNMENT'
          AND recipient_user_id = :technician_id
          AND deleted_at IS NULL");
    $stmtVacationOutbox->execute([':incident_id' => $perishableIncidentId, ':technician_id' => $guardTech]);
    $vacationOutboxBefore = (int)$stmtVacationOutbox->fetchColumn();

    $resultVacation = $service->closeAndHandover(
        technicianId: $tech1,
        targetTechnicianId: null,
        incidentIdsToTransfer: [],
        handoverNotes: null,
        closeReason: 'Cierre con guardia de vacaciones'
    );

    $assignedAfterVacation = (int)$pdo->query("SELECT assigned_technician_id FROM incidents WHERE id = {$perishableIncidentId}")->fetchColumn();
    $assert(
        'Guardia de vacaciones: la perecedera se deriva al guardia disponible, no al ausente',
        count($resultVacation->sanitaryEmergencyTransfers) === 1 && $assignedAfterVacation === $secondGuard,
        'asignada a=' . $assignedAfterVacation . ' esperada=' . $secondGuard
    );

    $stmtVacationOutbox->execute([':incident_id' => $perishableIncidentId, ':technician_id' => $guardTech]);
    $vacationOutboxAfter = (int)$stmtVacationOutbox->fetchColumn();
    $assert(
        'Guardia de vacaciones: el ausente no recibe un nuevo aviso del traspaso sanitario',
        $vacationOutboxAfter === $vacationOutboxBefore,
        'avisos=' . $vacationOutboxBefore . '→' . $vacationOutboxAfter
    );

    // ─────────────────────────────────────────────────────────────────────────
    // Escenario 1c: solo guardia de vacaciones => Nivel 2 con fallback auditado
    // ─────────────────────────────────────────────────────────────────────────
    $pdo->exec("UPDATE users SET is_on_call = FALSE WHERE role = 'TECHNICIAN'");
    $onCallRepo->setOnCall($guardTech, true);

    $shiftRepo->startShift($tech1);
    $pdo->exec("UPDATE incidents SET assigned_technician_id = {$tech1}, status = 'ASSIGNED' WHERE id = {$perishableIncidentId}");
    $resultNoAvailableGuard = $service->closeAndHandover(
        technicianId: $tech1,
        targetTechnicianId: null,
        incidentIdsToTransfer: [],
        handoverNotes: null,
        closeReason: 'Cierre sin guardia disponible'
    );

    $stmtFallbackAudit = $pdo->prepare("SELECT metadata::text FROM audit_log
        WHERE action = 'SANITARY_SHIFT_CLOSE_FAILSAFE'
          AND entity_id = :incident_id
        ORDER BY id DESC LIMIT 1");
    $stmtFallbackAudit->execute([':incident_id' => $perishableIncidentId]);
    $fallbackMetadata = (string)$stmtFallbackAudit->fetchColumn();

    $assert(
        'Solo guardia de vacaciones: la perecedera se desasigna con alarma (Nivel 2)',
        count($resultNoAvailableGuard->sanitaryEmergencyUnassigned) === 1
    );
    $assert(
        'El fallback sanitario se audita con NO_AVAILABLE_ON_CALL_TECHNICIAN',
        str_contains($fallbackMetadata, 'NO_AVAILABLE_ON_CALL_TECHNICIAN'),
        'metadata=' . $fallbackMetadata
    );

    // ─────────────────────────────────────────────────────────────────────────
    // Escenario 1d: solo guardia en pausa de comida => Nivel 2
    // ─────────────────────────────────────────────────────────────────────────
    TestDataCleaner::withPurgeWindow($pdo, static function () use ($pdo, $guardTech): void {
        $pdo->exec("DELETE FROM technician_absences WHERE technician_id = {$guardTech}");
    });

    $guardShift = $shiftRepo->startShift($guardTech);
    $shiftRepo->pauseMeal($guardShift->id());

    $shiftRepo->startShift($tech1);
    $pdo->exec("UPDATE incidents SET assigned_technician_id = {$tech1}, status = 'ASSIGNED' WHERE id = {$perishableIncidentId}");
    $resultGuardMeal = $service->closeAndHandover(
        technicianId: $tech1,
        targetTechnicianId: null,
        incidentIdsToTransfer: [],
        handoverNotes: null,
        closeReason: 'Cierre con guardia en pausa'
    );

    $assert(
        'Guardia en pausa de comida: la perecedera no se le traspasa y se desasigna (Nivel 2)',
        count($resultGuardMeal->sanitaryEmergencyUnassigned) === 1
    );

    // ─────────────────────────────────────────────────────────────────────────
    // Escenario 2: Nivel 2 del Protocolo Sanitario (Desasignación a REGISTERED)
    // ─────────────────────────────────────────────────────────────────────────
    // Desactivar guardia en todo el parque
    $pdo->exec("UPDATE users SET is_on_call = FALSE WHERE role = 'TECHNICIAN'");

    // Abrir nuevo turno para tech1 (segunda sesión del día, R3)
    $shiftRepo->startShift($tech1);

    // Reasignar la perecedera de nuevo a tech1 para probar Nivel 2
    $pdo->exec("UPDATE incidents SET assigned_technician_id = {$tech1}, status = 'ASSIGNED' WHERE id = {$perishableIncidentId}");

    $result2 = $service->closeAndHandover(
        technicianId: $tech1,
        targetTechnicianId: null,
        incidentIdsToTransfer: [],
        handoverNotes: null,
        closeReason: 'Cierre fin jornada Nivel 2 sin guardia'
    );

    $assert('Nivel 2: El turno de tech1 queda cerrado a OFF_SHIFT', $result2->shift->status === 'OFF_SHIFT');
    $assert('Nivel 2: La avería perecedera se desasigna por falta de guardia', count($result2->sanitaryEmergencyUnassigned) === 1 && $result2->sanitaryEmergencyUnassigned[0]['id'] === $perishableIncidentId);

    // Verificar en BD que el ticket perecedero quedó en REGISTERED y sin técnico
    $rowAfterNivel2 = $pdo->query("SELECT status, assigned_technician_id FROM incidents WHERE id = {$perishableIncidentId}")->fetch(PDO::FETCH_ASSOC);
    $assert('Nivel 2: BD confirma estado REGISTERED', $rowAfterNivel2['status'] === 'REGISTERED');
    $assert('Nivel 2: BD confirma assigned_technician_id IS NULL', $rowAfterNivel2['assigned_technician_id'] === null);

    $stmtLevel2Outbox = $pdo->prepare("SELECT COUNT(*) FROM notification_outbox
        WHERE incident_id = :incident_id
          AND template = 'COLD_CHAIN_IMMEDIATE_ALERT'
          AND recipient_role = 'COORDINATOR'
          AND deleted_at IS NULL");
    $stmtLevel2Outbox->execute([':incident_id' => $perishableIncidentId]);
    $level2AlertCount = (int)$stmtLevel2Outbox->fetchColumn();
    $assert('Nivel 2: Coordinación recibe la alerta sanitaria crítica', $level2AlertCount >= 1);

    // ─────────────────────────────────────────────────────────────────────────
    // Escenario 3: C2 · cierre forzado con perecedera y sin guardia
    // ─────────────────────────────────────────────────────────────────────────
    $shiftRepo->startShift($tech1);
    $pdo->exec("UPDATE incidents SET assigned_technician_id = {$tech1}, status = 'ASSIGNED' WHERE id = {$perishableIncidentId}");

    $coordinatorId = (int)$pdo->query("SELECT id FROM users WHERE role = 'COORDINATOR' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    $forced = $service->forceCloseShift(
        technicianId: $tech1,
        coordinatorId: $coordinatorId,
        reason: 'Cierre forzado por fin de guardia no reportado'
    );

    $assert('Forzado: el turno queda en OFF_SHIFT', $forced->shift->status === 'OFF_SHIFT');
    $assert('Forzado: la perecedera se desasigna sin guardia', count($forced->sanitaryEmergencyUnassigned) === 1);

    $rowAfterForce = $pdo->query("SELECT status, assigned_technician_id FROM incidents WHERE id = {$perishableIncidentId}")->fetch(PDO::FETCH_ASSOC);
    $assert(
        'Forzado: BD confirma REGISTERED y sin técnico',
        $rowAfterForce['status'] === 'REGISTERED' && $rowAfterForce['assigned_technician_id'] === null
    );

    $stmtLevel2Outbox->execute([':incident_id' => $perishableIncidentId]);
    $assert(
        'Forzado: Coordinación recibe una nueva alerta sanitaria del cierre forzado',
        (int)$stmtLevel2Outbox->fetchColumn() > $level2AlertCount
    );

    $stmtForceAudit = $pdo->prepare("SELECT COUNT(*) FROM audit_log
        WHERE action = 'FORCE_CLOSE_TECHNICIAN_SHIFT'
          AND entity_id = :technician_id");
    $stmtForceAudit->execute([':technician_id' => $tech1]);
    $assert('Forzado: se audita FORCE_CLOSE_TECHNICIAN_SHIFT', (int)$stmtForceAudit->fetchColumn() >= 1);

} finally {
    TestDataCleaner::purge($pdo);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
