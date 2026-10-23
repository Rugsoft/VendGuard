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

use VendGuard\Application\Service\ShiftHandoverService;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoOnCallTechnicianRepository;
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
$auditRepo = new PdoAuditLogRepository($pdo);

$service = new ShiftHandoverService(
    pdo: $pdo,
    shiftRepo: $shiftRepo,
    userRepo: $userRepo,
    incidentRepo: $incidentRepo,
    machineRepo: $machineRepo,
    onCallRepo: $onCallRepo,
    auditRepo: $auditRepo
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

} finally {
    TestDataCleaner::purge($pdo);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
