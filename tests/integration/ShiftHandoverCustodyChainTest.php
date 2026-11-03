<?php

declare(strict_types=1);

/**
 * ShiftHandoverCustodyChainTest · F-01, F-02, F-03 y F-05
 *
 * Certifica en PostgreSQL real que el cierre de turno tipifica sus tramos de
 * custodia en lugar de reutilizar el canal de Coordinación:
 *   1. Relevo manual a un compañero  -> `SHIFT_HANDOVER` en cierre y apertura.
 *   2. Protocolo sanitario Nivel 1   -> `SANITARY_FAILSAFE_GUARD` en ambos lados.
 *   3. Protocolo sanitario Nivel 2   -> `SANITARY_FAILSAFE_UNASSIGNED`, sin tramo
 *      abierto sobre la avería liberada.
 *   4. Expediente heredado sin tramo -> la liberación N2 no falla y no inventa
 *      custodia.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\HandoverTargetService;
use VendGuard\Application\Service\ShiftHandoverService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Notification\NotificationOutboxEnqueuerFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard: ShiftHandoverCustodyChainTest (F-01/F-02/F-03/F-05)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$shiftRepo = new PdoTechnicianShiftRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$absenceRepo = new PdoTechnicianAbsenceRepository($pdo);
$auditRepo = new PdoAuditLogRepository($pdo);
$onCallRepo = $userRepo;

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

$assert = function (string $title, bool $condition, string $message = '') use (&$failures, &$assertions): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$title}\n";
    } else {
        echo "  [FAIL] {$title}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

$segmentsOf = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM incident_assignments WHERE incident_id = :id ORDER BY id');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

try {
    $techRows = $pdo->query(
        "SELECT id, name FROM users WHERE role = 'TECHNICIAN' AND deleted_at IS NULL ORDER BY id LIMIT 3"
    )->fetchAll(PDO::FETCH_ASSOC);
    if (count($techRows) < 3) {
        echo "ERROR FATAL: se necesitan tres técnicos semilla.\n";
        exit(1);
    }
    $tech1 = (int)$techRows[0]['id'];
    $tech2 = (int)$techRows[1]['id'];
    $guard = (int)$techRows[2]['id'];

    $perishableMachineId = (int)$pdo->query(
        "SELECT id FROM machines WHERE machine_type IN ('PERISHABLE_FOOD', 'COMBO') AND deleted_at IS NULL ORDER BY id LIMIT 1"
    )->fetchColumn();
    $secondPerishableMachineId = (int)$pdo->query(
        "SELECT id FROM machines WHERE machine_type IN ('PERISHABLE_FOOD', 'COMBO') AND deleted_at IS NULL AND id <> {$perishableMachineId} ORDER BY id LIMIT 1"
    )->fetchColumn();
    $snacksMachineId = (int)$pdo->query(
        "SELECT id FROM machines WHERE machine_type = 'SNACKS' AND deleted_at IS NULL ORDER BY id LIMIT 1"
    )->fetchColumn();
    $locationId = (int)$pdo->query(
        'SELECT id FROM locations WHERE deleted_at IS NULL ORDER BY id LIMIT 1'
    )->fetchColumn();

    /** Crea el expediente y abre su tramo inicial por la puerta real del repositorio. */
    $makeAssignedIncident = function (string $code, int $machineId, int $technicianId) use ($pdo, $locationId, $incidentRepo): int {
        $stmt = $pdo->prepare(
            "INSERT INTO incidents (ticket_code, location_id, machine_id, category, description, urgency, status)
             VALUES (:code, :location, :machine, 'OTHER', 'Expediente de certificación de la cadena de custodia en cierre de turno.', 'HIGH', 'REGISTERED')
             RETURNING id"
        );
        $stmt->execute([':code' => $code, ':location' => $locationId, ':machine' => $machineId]);
        $id = (int)$stmt->fetchColumn();
        $incidentRepo->assign($id, $technicianId, null, null, null, null, null);

        return $id;
    };

    /** Expediente heredado: asignado por datos, sin tramo de custodia. */
    $makeLegacyIncident = function (string $code, int $machineId, int $technicianId) use ($pdo, $locationId): int {
        $stmt = $pdo->prepare(
            "INSERT INTO incidents (ticket_code, location_id, machine_id, category, description, urgency, status, assigned_technician_id, assigned_at)
             VALUES (:code, :location, :machine, 'OTHER', 'Expediente heredado sin cadena de custodia.', 'HIGH', 'ASSIGNED', :technician, NOW())
             RETURNING id"
        );
        $stmt->execute([
            ':code' => $code,
            ':location' => $locationId,
            ':machine' => $machineId,
            ':technician' => $technicianId,
        ]);

        return (int)$stmt->fetchColumn();
    };

    // ─────────────────────────────────────────────────────────────────────────
    // Caso 1: relevo manual de fin de turno
    // ─────────────────────────────────────────────────────────────────────────
    $shiftRepo->startShift($tech1);
    $shiftRepo->startShift($tech2);
    $manualId = $makeAssignedIncident('CUS-MAN-' . strtoupper(uniqid()), $snacksMachineId, $tech1);
    $notes = 'Traslado de la avería al compañero de ruta con instrucciones de acceso';

    $manual = $service->closeAndHandover(
        technicianId: $tech1,
        targetTechnicianId: $tech2,
        incidentIdsToTransfer: [$manualId],
        handoverNotes: $notes,
        closeReason: 'Fin de jornada con relevo'
    );

    $manualSegments = $segmentsOf($manualId);
    $assert('1.1 El turno se cierra con el relevo aplicado', $manual->shift->status === 'OFF_SHIFT');
    $assert(
        '1.2 El tramo saliente se cierra con SHIFT_HANDOVER y el entrante abre con SHIFT_HANDOVER',
        count($manualSegments) === 2
            && $manualSegments[0]['unassignment_reason_type'] === 'SHIFT_HANDOVER'
            && $manualSegments[0]['unassigned_at'] !== null
            && $manualSegments[1]['assignment_source'] === 'SHIFT_HANDOVER'
            && $manualSegments[1]['unassigned_at'] === null
            && (int)$manualSegments[1]['technician_id'] === $tech2,
        json_encode($manualSegments)
    );
    $assert(
        '1.3 El cierre lo firma el técnico saliente y la nota no se duplica',
        (int)$manualSegments[0]['unassigned_by'] === $tech1
            && $manualSegments[0]['reassignment_reason'] === $notes,
        (string)($manualSegments[0]['reassignment_reason'] ?? 'sin tramo cerrado')
    );

    // ─────────────────────────────────────────────────────────────────────────
    // Caso 2: protocolo sanitario Nivel 1 (guardia 24/7 disponible)
    // ─────────────────────────────────────────────────────────────────────────
    $pdo->exec("UPDATE users SET is_on_call = FALSE WHERE role = 'TECHNICIAN'");
    $onCallRepo->setOnCall($guard, true);
    $shiftRepo->startShift($tech1);
    $sanitaryId = $makeAssignedIncident('CUS-N1-' . strtoupper(uniqid()), $perishableMachineId, $tech1);

    $level1 = $service->closeAndHandover(
        technicianId: $tech1,
        targetTechnicianId: null,
        incidentIdsToTransfer: [],
        handoverNotes: null,
        closeReason: 'Cierre de jornada con guardia disponible'
    );

    $level1Segments = $segmentsOf($sanitaryId);
    $assert(
        '2.1 La perecedera se traspasa al guardia disponible',
        count($level1->sanitaryEmergencyTransfers) === 1,
        json_encode($level1->sanitaryEmergencyTransfers)
    );
    $assert(
        '2.2 Cierre y apertura usan SANITARY_FAILSAFE_GUARD y firma el técnico saliente',
        count($level1Segments) === 2
            && $level1Segments[0]['unassignment_reason_type'] === 'SANITARY_FAILSAFE_GUARD'
            && (int)$level1Segments[0]['unassigned_by'] === $tech1
            && $level1Segments[1]['assignment_source'] === 'SANITARY_FAILSAFE_GUARD'
            && (int)$level1Segments[1]['technician_id'] === $guard,
        json_encode($level1Segments)
    );

    // ─────────────────────────────────────────────────────────────────────────
    // Caso 3: protocolo sanitario Nivel 2 (sin guardia disponible)
    // ─────────────────────────────────────────────────────────────────────────
    $pdo->exec("UPDATE users SET is_on_call = FALSE WHERE role = 'TECHNICIAN'");
    $shiftRepo->startShift($tech1);
    $incidentRepo->assign($sanitaryId, $tech1, null, null, null, 'Retoma de la avería para el cierre sin guardia.');

    $level2 = $service->closeAndHandover(
        technicianId: $tech1,
        targetTechnicianId: null,
        incidentIdsToTransfer: [],
        handoverNotes: null,
        closeReason: 'Cierre de jornada sin guardia disponible'
    );

    $level2Segments = $segmentsOf($sanitaryId);
    $open = array_values(array_filter($level2Segments, static fn (array $s): bool => $s['unassigned_at'] === null));
    $lastClosed = $level2Segments !== [] ? $level2Segments[count($level2Segments) - 1] : null;
    $assert(
        '3.1 La perecedera se libera a bandeja',
        count($level2->sanitaryEmergencyUnassigned) === 1,
        json_encode($level2->sanitaryEmergencyUnassigned)
    );
    $assert(
        '3.2 El último tramo se cierra con SANITARY_FAILSAFE_UNASSIGNED y firma el técnico saliente',
        is_array($lastClosed)
            && $lastClosed['unassignment_reason_type'] === 'SANITARY_FAILSAFE_UNASSIGNED'
            && (int)$lastClosed['unassigned_by'] === $tech1
            && str_contains((string)$lastClosed['reassignment_reason'], 'Protocolo Sanitario Art. II'),
        json_encode($lastClosed)
    );
    $assert('3.3 La avería liberada no conserva ningún tramo abierto (EARS 01.1)', $open === [], json_encode($open));

    $released = $pdo->prepare('SELECT status, assigned_technician_id FROM incidents WHERE id = :id');
    $released->execute([':id' => $sanitaryId]);
    $releasedRow = $released->fetch(PDO::FETCH_ASSOC);
    $assert(
        '3.4 La avería queda en REGISTERED y sin técnico',
        $releasedRow['status'] === 'REGISTERED' && $releasedRow['assigned_technician_id'] === null
    );

    // ─────────────────────────────────────────────────────────────────────────
    // Caso 4: expediente heredado sin tramo previo
    // ─────────────────────────────────────────────────────────────────────────
    $pdo->exec("UPDATE users SET is_on_call = FALSE WHERE role = 'TECHNICIAN'");
    $shiftRepo->startShift($tech1);
    $legacyMachineId = $secondPerishableMachineId > 0 ? $secondPerishableMachineId : $perishableMachineId;
    $legacyId = $makeLegacyIncident('CUS-LEG-' . strtoupper(uniqid()), $legacyMachineId, $tech1);

    $legacyRelease = $service->closeAndHandover(
        technicianId: $tech1,
        targetTechnicianId: null,
        incidentIdsToTransfer: [],
        handoverNotes: null,
        closeReason: 'Cierre de jornada sobre expediente heredado'
    );

    $legacyRow = $pdo->prepare('SELECT status, assigned_technician_id FROM incidents WHERE id = :id');
    $legacyRow->execute([':id' => $legacyId]);
    $legacyData = $legacyRow->fetch(PDO::FETCH_ASSOC);
    $assert(
        '4.1 La liberación de un expediente sin tramo no falla y lo deja en bandeja',
        count($legacyRelease->sanitaryEmergencyUnassigned) === 1
            && $legacyData['status'] === 'REGISTERED'
            && $legacyData['assigned_technician_id'] === null,
        json_encode($legacyData)
    );
    $assert('4.2 Sin custodia previa no se inventan tramos', $segmentsOf($legacyId) === []);
} finally {
    TestDataCleaner::purge($pdo);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
