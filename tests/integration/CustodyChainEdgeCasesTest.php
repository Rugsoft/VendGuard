<?php

declare(strict_types=1);

/**
 * VendGuard - CustodyChainEdgeCasesTest (Módulo 26)
 *
 * Cubre los dos criterios que faltaban en la certificación HTTP:
 *   1. Ida y vuelta A -> B -> A: tres tramos, dos reasignaciones y A como
 *      custodio activo, con la fórmula del resumen sin doble conteo (D4, caso 2).
 *   2. Huso horario operativo: las marcas de la cadena se publican con el offset
 *      vigente de Europe/Madrid, no con el UTC del motor (RNF-04).
 *
 * Dogma Vanilla: PHP 8.2 estricto, PDO nativo, sin dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\CoordinatorIncidentDetailService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard · Módulo 26 · Ida y vuelta y huso operativo\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$incidentRepo = new PdoIncidentRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$detailService = new CoordinatorIncidentDetailService($incidentRepo);

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
$techA       = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$techB       = $userRepo->findByEmail('marta.ruta@vendguard.internal');
$machine     = $machineRepo->findByCode('VEND-0301');
$assert('0.1 Semillas suficientes', $coordinator !== null && $techA !== null && $techB !== null && $machine !== null);
if ($coordinator === null || $techA === null || $techB === null || $machine === null) {
    echo "ERROR FATAL: sin semillas no se puede certificar.\n";
    exit(1);
}

TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machine->getId());

$incident = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: 'T26E-' . strtoupper(uniqid()),
    machineId: $machine->getId(),
    locationId: $machine->getLocationId(),
    category: IncidentCategory::ELECTRICAL_OFF,
    description: 'Fixture de ida y vuelta de custodia.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    createdAt: null
));
$incidentId = (int)$incident->getId();

$incidentRepo->assign($incidentId, (int)$techA->getId());
$incidentRepo->assign(
    $incidentId,
    (int)$techB->getId(),
    (int)$coordinator->getId(),
    null,
    null,
    'Reasignación de ida hacia el segundo técnico de la prueba.'
);
$incidentRepo->assign(
    $incidentId,
    (int)$techA->getId(),
    (int)$coordinator->getId(),
    null,
    null,
    'Reasignación de vuelta al primer técnico de la prueba.'
);

$stmt = $pdo->prepare(
    'SELECT technician_id, unassigned_at, assignment_source FROM incident_assignments WHERE incident_id = ? ORDER BY id ASC'
);
$stmt->execute([$incidentId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$assert('1.1 La ida y vuelta produce tres tramos', count($rows) === 3);
$assert(
    '1.2 Solo el último tramo está abierto y pertenece a A',
    count($rows) === 3
        && $rows[0]['unassigned_at'] !== null
        && $rows[1]['unassigned_at'] !== null
        && $rows[2]['unassigned_at'] === null
        && (int)$rows[2]['technician_id'] === (int)$techA->getId()
);

$dto = $detailService->buildDetail($incidentId);
$assert('1.3 La ficha de detalle se compone', $dto !== null);
$payload = $dto?->toArray() ?? [];
$chain = is_array($payload['custody_chain'] ?? null) ? $payload['custody_chain'] : [];
$summary = is_array($chain['summary'] ?? null) ? $chain['summary'] : [];

$assert('1.4 El resumen cuenta tres asignaciones', (int)($summary['total_assignments_count'] ?? 0) === 3);
$assert('1.5 El resumen cuenta dos reasignaciones', (int)($summary['reassignments_count'] ?? 0) === 2);
$assert('1.6 El resumen distingue dos técnicos', (int)($summary['distinct_technicians_count'] ?? 0) === 2);
$assert('1.7 El resumen declara custodio activo', ($summary['has_active_custodian'] ?? false) === true);

// ─────────────────────────────────────────────────────────────────────────────
// Huso horario operativo
// ─────────────────────────────────────────────────────────────────────────────
$badrid = new DateTimeZone('Europe/Madrid');
$timezoneOk = true;
foreach (is_array($chain['segments'] ?? null) ? $chain['segments'] : [] as $segment) {
    foreach (['assigned_at', 'unassigned_at'] as $field) {
        $value = $segment[$field] ?? null;
        if ($value === null) {
            continue;
        }
        $instant = new DateTimeImmutable((string)$value);
        $expectedOffset = $instant->setTimezone($badrid)->format('P');
        if (!str_ends_with((string)$value, $expectedOffset)) {
            $timezoneOk = false;
            break 2;
        }
    }
}

$assert('2.1 Las marcas de la cadena llevan el offset de Europe/Madrid', $timezoneOk);
$assert(
    '2.2 La primera marca no se publica en UTC',
    isset($chain['segments'][0]['assigned_at'])
        && !str_ends_with((string)$chain['segments'][0]['assigned_at'], '+00:00')
);

echo "\n======================================================================\n";
echo " RESULTADO: {$failures} FALLO(S)\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
