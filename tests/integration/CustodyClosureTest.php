<?php

declare(strict_types=1);

/**
 * VendGuard - CustodyClosureTest (Módulo 26)
 *
 * Certifica los cierres que no pasan por Coordinación y la continuidad del tramo:
 *   1. Una pausa por repuestos no divide ni reescribe el tramo activo (EARS 02.5).
 *   2. El descarte en triaje cierra el tramo con CANCELLED (EARS 02.6.2).
 *   3. El cierre automático a las 48 h sella un tramo legado con RESOLVED (QA 4).
 *   4. El suelo temporal `GREATEST(assigned_at, now)` deja retención 0 cuando el
 *      reloj del sistema va por detrás del inicio del tramo (QA 4, caso límite 8).
 *   5. La resolución con hora efectiva sella `resolved_at` y el tramo en ese
 *      instante, no en el de llegada del lote offline (RF-04.3, QA 4).
 *
 * Dogma Vanilla: PHP 8.2 estricto, PDO nativo, sin dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard · Módulo 26 · Cierres de custodia y continuidad\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$incidentRepo = new PdoIncidentRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);

$failures = 0;
$assert = function (string $title, bool $condition, string $message = '') use (&$failures): void {
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

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$jordi       = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$marta       = $userRepo->findByEmail('marta.ruta@vendguard.internal');
$machineA    = $machineRepo->findByCode('VEND-0301');
$machineB    = $machineRepo->findByCode('VEND-0302');

$assert(
    '0.1 Semillas suficientes',
    $coordinator !== null && $jordi !== null && $marta !== null && $machineA !== null && $machineB !== null
);
if ($coordinator === null || $jordi === null || $marta === null || $machineA === null || $machineB === null) {
    echo "ERROR FATAL: sin semillas no se puede certificar.\n";
    exit(1);
}

$makeRegistered = function (Machine $machine) use ($incidentRepo): Incident {
    return $incidentRepo->create(new Incident(
        id: null,
        ticketCode: 'T26X-' . strtoupper(uniqid()),
        machineId: $machine->getId(),
        locationId: $machine->getLocationId(),
        category: IncidentCategory::PRODUCT_JAM,
        description: 'Fixture de cierres de custodia.',
        urgency: UrgencyLevel::MEDIUM,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));
};

/** @return list<array<string, mixed>> */
$custodyRows = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare(
        "SELECT id, assigned_at, unassigned_at, assignment_source, unassignment_reason_type
         FROM incident_assignments WHERE incident_id = ? ORDER BY id ASC"
    );
    $stmt->execute([$incidentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

// ─────────────────────────────────────────────────────────────────────────────
// Caso 1 · La pausa no divide el tramo
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 1: continuidad durante la pausa ---\n";
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machineA->getId());
$paused = $makeRegistered($machineA);
$pausedId = (int)$paused->getId();
$incidentRepo->assign($pausedId, (int)$jordi->getId());
$pdo->prepare("UPDATE incidents SET status = 'IN_PROGRESS', started_at = NOW() WHERE id = ?")->execute([$pausedId]);
$before = $custodyRows($pausedId);

$incidentRepo->pauseIntervention($pausedId, (int)$jordi->getId(), 'Rodamiento del espiral B4 agotado.');
$after = $custodyRows($pausedId);

$assert('1.1 La pausa mantiene un único tramo', count($after) === 1);
$assert(
    '1.2 El tramo es el mismo y sigue abierto, con el mismo inicio',
    count($before) === 1
        && (int)$after[0]['id'] === (int)$before[0]['id']
        && $after[0]['unassigned_at'] === null
        && $after[0]['assigned_at'] === $before[0]['assigned_at']
);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 2 · Descarte en triaje
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 2: descarte en triaje ---\n";
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machineB->getId());
$cancelled = $makeRegistered($machineB);
$cancelledId = (int)$cancelled->getId();
$incidentRepo->assign($cancelledId, (int)$jordi->getId());
$incidentRepo->cancel($cancelledId, 'Aviso duplicado sobre la misma máquina y misma avería.', (int)$coordinator->getId());
$rows = $custodyRows($cancelledId);

$assert('2.1 El descarte deja el tramo cerrado', count($rows) === 1 && $rows[0]['unassigned_at'] !== null);
$assert(
    '2.2 La causa del cierre es CANCELLED',
    ($rows[0]['unassignment_reason_type'] ?? '') === 'CANCELLED'
);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 3 · Cierre automático a las 48 h de un tramo legado
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 3: cierre automático a las 48 h ---\n";
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machineA->getId());
$legacy = $makeRegistered($machineA);
$legacyId = (int)$legacy->getId();
$incidentRepo->assign($legacyId, (int)$jordi->getId());
$pdo->prepare(
    "UPDATE incidents
     SET status = 'RESOLVED',
         resolved_at = NOW() - INTERVAL '49 hours',
         resolution_diagnosis = 'Diagnóstico legado sin cierre de custodia.',
         resolution_action = 'Acción legada sin cierre de custodia.'
     WHERE id = ?"
)->execute([$legacyId]);

$closed = $incidentRepo->autoCloseResolvedIncidents(48);
$legacyRow = $pdo->query("SELECT status, closed_at FROM incidents WHERE id = {$legacyId}")->fetch(PDO::FETCH_ASSOC);
$rows = $custodyRows($legacyId);

$assert('3.1 El barrido alcanza el expediente legado', count($closed) >= 1 && ($legacyRow['status'] ?? '') === 'CLOSED');
$assert('3.2 El cierre deja fecha de archivo', ($legacyRow['closed_at'] ?? null) !== null);
$assert(
    '3.3 El tramo legado se sella con RESOLVED',
    count($rows) === 1
        && $rows[0]['unassigned_at'] !== null
        && ($rows[0]['unassignment_reason_type'] ?? '') === 'RESOLVED'
);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 4 · Suelo temporal con reloj atrasado
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 4: suelo temporal (retención 0) ---\n";
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machineB->getId());
$skewed = $makeRegistered($machineB);
$skewedId = (int)$skewed->getId();
$incidentRepo->assign($skewedId, (int)$marta->getId());
$future = new DateTimeImmutable('+1 hour');
$incidentRepo->takeOverOpportunisticIncident($skewedId, (int)$jordi->getId(), null, $future);
$incidentRepo->cancel($skewedId, 'Descarte tras detectar el desfase de reloj en la prueba.', (int)$coordinator->getId());

$floor = $pdo->query(
    "SELECT assigned_at, unassigned_at, EXTRACT(EPOCH FROM (unassigned_at - assigned_at)) AS diff
     FROM incident_assignments WHERE incident_id = {$skewedId} AND unassigned_at IS NOT NULL ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

$assert('4.1 El tramo abierto en el futuro queda cerrado', is_array($floor) && $floor['unassigned_at'] !== null);
$assert(
    '4.2 El cierre nunca queda antes del inicio (retención 0)',
    is_array($floor) && (float)$floor['diff'] === 0.0 && $floor['unassigned_at'] === $floor['assigned_at']
);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 5 · Resolución con la hora efectiva del evento
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 5: resolución con hora efectiva ---\n";
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machineA->getId());
$deferred = $makeRegistered($machineA);
$deferredId = (int)$deferred->getId();
$incidentRepo->assign($deferredId, (int)$jordi->getId());
$incidentRepo->startIntervention($deferredId, (int)$jordi->getId());
// El trigger de inmutabilidad impide retrasar `assigned_at`; se adelanta el
// cierre 5 min para comprobar que la hora efectiva manda sobre el reloj actual.
$effectiveResolvedAt = new DateTimeImmutable('+5 minutes');
$incidentRepo->resolve(
    $deferredId,
    (int)$jordi->getId(),
    'Diagnóstico verificado en la prueba de hora efectiva.',
    'Sustitución completada y verificada en la prueba de hora efectiva.',
    $effectiveResolvedAt
);

$deferredRow = $pdo->query(
    "SELECT resolved_at FROM incidents WHERE id = {$deferredId}"
)->fetch(PDO::FETCH_ASSOC);
$deferredCustody = $custodyRows($deferredId)[0] ?? [];

$assert(
    '5.1 La resolución conserva la hora efectiva del cierre',
    is_array($deferredRow)
        && abs(strtotime((string)$deferredRow['resolved_at']) - $effectiveResolvedAt->getTimestamp()) <= 1
);
$assert(
    '5.2 El tramo se sella en la hora efectiva y no en la de llegada del lote',
    ($deferredCustody['unassigned_at'] ?? null) !== null
        && abs(strtotime((string)$deferredCustody['unassigned_at']) - $effectiveResolvedAt->getTimestamp()) <= 1
        && abs(
            strtotime((string)$deferredCustody['unassigned_at'])
            - strtotime((string)$deferredCustody['assigned_at'])
        ) >= 295
);

echo "\n======================================================================\n";
echo " RESULTADO: {$failures} FALLO(S)\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
