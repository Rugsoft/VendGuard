<?php

declare(strict_types=1);

/**
 * VendGuard - OpportunisticCustodyChainTest (Módulo 26, EARS 02.3 / 02.6.3 / 06)
 *
 * Certifica contra PostgreSQL real que las cuatro vías oportunistas abren su tramo
 * de custodia con el canal correcto y cierran el saliente con la causa que les
 * corresponde:
 *   1. Alta in situ -> OPPORTUNISTIC_NEW.
 *   2. Asunción de un aviso sin técnico -> OPPORTUNISTIC_ASSUMED.
 *   3. Traspaso de un compañero -> cierre TAKEN_OVER_IN_SITU (con la leyenda
 *      estándar cuando no hay nota) y apertura OPPORTUNISTIC_TAKEOVER.
 *   4. Reapertura en garantía in situ -> cierre WARRANTY_REOPENED y apertura
 *      OPPORTUNISTIC_WARRANTY_REOPEN.
 *   5. Tercera reincidencia -> cierre CHRONIC_REPLACEMENT_CLOSED del predecesor y
 *      sucesor activo con OPPORTUNISTIC_NEW.
 *
 * Dogma Vanilla: PHP 8.2 estricto, PDO nativo, sin dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\OpportunisticIncidentService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Notification\NotificationOutboxEnqueuerFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard · Módulo 26 · Canales oportunistas de custodia\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$incidentRepo = new PdoIncidentRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$auditRepo    = new PdoAuditLogRepository($pdo);

$service = new OpportunisticIncidentService(
    opportunistic: $incidentRepo,
    machines: $machineRepo,
    locations: $locationRepo,
    incidents: $incidentRepo,
    transactions: new PdoTransactionManager($pdo),
    auditLogger: new AuditLogger($auditRepo),
    notifications: NotificationOutboxEnqueuerFactory::create()
);

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

$jordi = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$marta = $userRepo->findByEmail('marta.ruta@vendguard.internal');
$assert('0.1 Técnicos semilla disponibles', $jordi !== null && $marta !== null);
if ($jordi === null || $marta === null) {
    echo "ERROR FATAL: sin técnicos no se puede certificar.\n";
    exit(1);
}

$machineA = $machineRepo->findByCode('VEND-0301');
$machineB = $machineRepo->findByCode('VEND-0302');
$assert('0.2 Máquinas semilla disponibles', $machineA !== null && $machineB !== null);
if ($machineA === null || $machineB === null) {
    echo "ERROR FATAL: sin parque semilla no se puede certificar.\n";
    exit(1);
}

$makeRegistered = function (Machine $machine) use ($incidentRepo): Incident {
    return $incidentRepo->create(new Incident(
        id: null,
        ticketCode: 'T26C-' . strtoupper(uniqid()),
        machineId: $machine->getId(),
        locationId: $machine->getLocationId(),
        category: IncidentCategory::PRODUCT_JAM,
        description: 'Fixture de cadena de custodia oportunista.',
        urgency: UrgencyLevel::MEDIUM,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));
};

/**
 * Ancla la ventana de gracia de 30 minutos con una parada previa resuelta del
 * mismo técnico en la sede. La elegibilidad no se simula: se ejerce el camino
 * real que habilita la intervención oportunista.
 */
$grantGrace = function (Machine $anchor) use ($incidentRepo, $jordi, $pdo, $makeRegistered): void {
    TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$anchor->getId());
    $prior = $makeRegistered($anchor);
    $incidentRepo->assign((int)$prior->getId(), (int)$jordi->getId());
    $pdo->prepare("UPDATE incidents SET status = 'IN_PROGRESS', started_at = NOW() WHERE id = ?")
        ->execute([(int)$prior->getId()]);
    $incidentRepo->resolve(
        (int)$prior->getId(),
        (int)$jordi->getId(),
        'Parada previa de anclaje para habilitar la ventana de gracia.',
        'Resolución de la parada previa de anclaje.'
    );
};

/** @return list<array<string, mixed>> */
$custodyRows = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare(
        "SELECT id, technician_id, assignment_source, unassigned_at, unassignment_reason_type, reassignment_reason
         FROM incident_assignments WHERE incident_id = ? ORDER BY id ASC"
    );
    $stmt->execute([$incidentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$reopenReason = 'La avería reaparece en la misma máquina tras la reparación anterior.';

// ─────────────────────────────────────────────────────────────────────────────
// Caso 1 · Alta oportunista in situ
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 1: alta in situ ---\n";
$grantGrace($machineB);
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machineA->getId());
$created = $service->createOpportunisticIncident($jordi, [
    'machine_id' => $machineA->getId(),
    'category' => 'PRODUCT_JAM',
    'origin_reason' => 'TECHNICIAN_DETECTION',
    'description' => 'Atasco detectado durante la visita a la sede.',
]);
$rows = $custodyRows((int)$created['id']);
$assert('1.1 El alta abre exactamente un tramo', count($rows) === 1);
$assert(
    '1.2 El canal es OPPORTUNISTIC_NEW y el custodio es el técnico',
    ($rows[0]['assignment_source'] ?? '') === 'OPPORTUNISTIC_NEW'
        && (int)($rows[0]['technician_id'] ?? 0) === $jordi->getId()
        && $rows[0]['unassigned_at'] === null
);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 2 · Asunción de un aviso sin técnico
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 2: asunción de aviso en bandeja ---\n";
$grantGrace($machineA);
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machineB->getId());
$registered = $makeRegistered($machineB);
$service->assumeOpportunisticIncident($jordi, (int)$registered->getId(), []);
$rows = $custodyRows((int)$registered->getId());
$assert('2.1 La asunción abre un único tramo', count($rows) === 1);
$assert(
    '2.2 El canal es OPPORTUNISTIC_ASSUMED',
    ($rows[0]['assignment_source'] ?? '') === 'OPPORTUNISTIC_ASSUMED'
        && $rows[0]['unassigned_at'] === null
);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 3 · Traspaso de un compañero (cierre con leyenda estándar)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 3: traspaso in situ sin nota ---\n";
$grantGrace($machineA);
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machineB->getId());
$assigned = $makeRegistered($machineB);
$incidentRepo->assign((int)$assigned->getId(), (int)$marta->getId());
$service->assumeOpportunisticIncident($jordi, (int)$assigned->getId(), [
    'confirm_takeover_from_other_tech' => true,
]);
$rows = $custodyRows((int)$assigned->getId());
$assert('3.1 El traspaso deja dos tramos', count($rows) === 2);
$assert(
    '3.2 El tramo saliente se cierra con TAKEN_OVER_IN_SITU',
    ($rows[0]['unassignment_reason_type'] ?? '') === 'TAKEN_OVER_IN_SITU'
);
$assert(
    '3.3 Sin nota se sella la leyenda estándar',
    ($rows[0]['reassignment_reason'] ?? '') === 'Asunción oportunista por presencia física del técnico en la sede.'
);
$assert(
    '3.4 El tramo entrante es OPPORTUNISTIC_TAKEOVER y está activo',
    ($rows[1]['assignment_source'] ?? '') === 'OPPORTUNISTIC_TAKEOVER'
        && $rows[1]['unassigned_at'] === null
        && (int)($rows[1]['technician_id'] ?? 0) === $jordi->getId()
);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 4 · Reapertura en garantía in situ
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 4: reapertura en garantía in situ ---\n";
$grantGrace($machineA);
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machineB->getId());
$reopening = $service->createOpportunisticIncident($jordi, [
    'machine_id' => $machineB->getId(),
    'category' => 'PRODUCT_JAM',
    'origin_reason' => 'TECHNICIAN_DETECTION',
    'description' => 'Avería inicial que se resolverá para reabrirla en garantía.',
]);
$reopeningId = (int)$reopening['id'];
// Estado legado: el expediente quedó RESOLVED sin cerrar su tramo (camino anterior
// al módulo 26). La reapertura debe sellarlo defensivamente con WARRANTY_REOPENED.
$pdo->prepare(
    "UPDATE incidents
     SET status = 'RESOLVED', resolved_at = NOW(),
         resolution_diagnosis = 'Diagnóstico inicial de la avería.',
         resolution_action = 'Acción reparadora inicial.'
     WHERE id = ?"
)->execute([$reopeningId]);
$reopened = $service->assumeOpportunisticIncident($jordi, $reopeningId, ['reopen_reason' => $reopenReason]);
$rows = $custodyRows($reopeningId);
$assert('4.1 La reapertura devuelve el mismo expediente en curso', (int)$reopened['id'] === $reopeningId && $reopened['status'] === 'IN_PROGRESS');
$assert('4.2 La reapertura deja dos tramos', count($rows) === 2);
$assert(
    '4.3 El tramo legado abierto se sella con WARRANTY_REOPENED',
    ($rows[0]['unassignment_reason_type'] ?? '') === 'WARRANTY_REOPENED'
);
$assert(
    '4.4 El tramo entrante es OPPORTUNISTIC_WARRANTY_REOPEN y está activo',
    ($rows[1]['assignment_source'] ?? '') === 'OPPORTUNISTIC_WARRANTY_REOPEN'
        && $rows[1]['unassigned_at'] === null
);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 5 · Tercera reincidencia: sustitución crónica
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 5: tercera reincidencia crónica ---\n";
$grantGrace($machineB);
TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machineA->getId());
$chronic = $service->createOpportunisticIncident($jordi, [
    'machine_id' => $machineA->getId(),
    'category' => 'PRODUCT_JAM',
    'origin_reason' => 'TECHNICIAN_DETECTION',
    'description' => 'Avería reincidente que acabará sustituida por Avería Crónica.',
]);
$chronicId = (int)$chronic['id'];
for ($round = 1; $round <= 2; $round++) {
    $incidentRepo->resolve($chronicId, $jordi->getId(), "Diagnóstico de la ronda {$round}.", "Acción reparadora de la ronda {$round}.");
    $service->assumeOpportunisticIncident($jordi, $chronicId, ['reopen_reason' => $reopenReason]);
}
// Tercera resolución con el tramo aún abierto: la sustitución crónica debe
// sellarlo con CHRONIC_REPLACEMENT_CLOSED, no dejarlo huérfano.
$pdo->prepare(
    "UPDATE incidents
     SET status = 'RESOLVED', resolved_at = NOW(),
         resolution_diagnosis = 'Diagnóstico de la tercera reincidencia.',
         resolution_action = 'Acción reparadora de la tercera reincidencia.'
     WHERE id = ?"
)->execute([$chronicId]);
$successor = $service->assumeOpportunisticIncident($jordi, $chronicId, ['reopen_reason' => $reopenReason]);

$predecessorRow = $pdo->query("SELECT status FROM incidents WHERE id = {$chronicId}")->fetch(PDO::FETCH_ASSOC);
$predecessorCustody = $custodyRows($chronicId);
$successorCustody = $custodyRows((int)$successor['id']);

$assert('5.1 La tercera reincidencia crea un sucesor', $successor['chronic_replacement_created'] === true && (int)$successor['id'] !== $chronicId);
$assert('5.2 El predecesor queda CLOSED', ($predecessorRow['status'] ?? '') === 'CLOSED');
$assert(
    '5.3 El último tramo del predecesor se sella con CHRONIC_REPLACEMENT_CLOSED',
    ($predecessorCustody[count($predecessorCustody) - 1]['unassignment_reason_type'] ?? '') === 'CHRONIC_REPLACEMENT_CLOSED'
);
$assert(
    '5.4 El sucesor abre un tramo OPPORTUNISTIC_NEW activo',
    count($successorCustody) === 1
        && ($successorCustody[0]['assignment_source'] ?? '') === 'OPPORTUNISTIC_NEW'
        && $successorCustody[0]['unassigned_at'] === null
);

echo "\n======================================================================\n";
echo " RESULTADO: {$failures} FALLO(S)\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
