<?php

declare(strict_types=1);

/**
 * VendGuard - CustodyChainConstitutionalTest (T-CUSTODY-11)
 *
 * Certificación de los blindajes del módulo 26 contra PostgreSQL real:
 *   1. `trg_no_delete_incident_assignments` impide el borrado físico (Art. III.1).
 *   2. `trg_immutable_incident_assignments` bloquea la edición de tramos cerrados y
 *      de los campos de apertura y snapshots (Art. III.3, D2).
 *   3. `uq_incident_assignments_active_custody` impide dos custodios activos sobre
 *      la misma incidencia (Art. V.3).
 *   4. `custody_chain` y el motivo interno de reasignación no aparecen en las
 *      respuestas de Técnico ni del Portal de Sede, ni existe el literal en la
 *      capa de presentación (Art. V.4, D3, QA 5).
 *
 * Dogma Vanilla: PHP 8.2 estricto, PDO nativo, sin dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
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
echo " VendGuard: CustodyChainConstitutionalTest (T-CUSTODY-11)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$router       = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);

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
$technician  = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$location    = $locationRepo->findBySiteCode('SEDE-BCN-01');
$assert('0.1 Semillas de coordinación, técnico y sede disponibles', $coordinator !== null && $technician !== null && $location !== null);
if ($coordinator === null || $technician === null || $location === null) {
    echo "ERROR FATAL: semillas no disponibles.\n";
    exit(1);
}

$technicianId  = (int)$technician->getId();
$coordinatorId = (int)$coordinator->getId();
$machines      = $machineRepo->findActiveByLocationId($location->getId());

$makeIncident = function () use ($incidentRepo, $pdo, $location, $machines): Incident {
    $machineId = (int)$machines[0]->getId();
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW() WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")->execute([$machineId]);

    return $incidentRepo->create(new Incident(
        id: null,
        ticketCode: 'T26C-' . strtoupper(uniqid()),
        machineId: $machineId,
        locationId: $location->getId(),
        category: IncidentCategory::OTHER,
        description: 'Expediente de certificación constitucional de la cadena de custodia.',
        urgency: UrgencyLevel::CRITICAL,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));
};

$incident = $makeIncident();
$incidentId = (int)$incident->getId();

$insertOpen = function () use ($pdo, $incidentId, $technicianId, $coordinatorId): int {
    $stmt = $pdo->prepare(
        "INSERT INTO incident_assignments (incident_id, technician_id, technician_name_snapshot, technician_code_snapshot, assigned_by, assigned_by_name_snapshot, assignment_source) "
        . "VALUES (:incident, :tech, 'Jordi Cruz', 'OP-BCN-04', :by, 'Coordinación Central', 'COORDINATOR_ASSIGN') RETURNING id"
    );
    $stmt->execute([':incident' => $incidentId, ':tech' => $technicianId, ':by' => $coordinatorId]);

    return (int)$stmt->fetchColumn();
};

$assignmentId = $insertOpen();

// ---------------------------------------------------------------------------
echo "\n--- Caso 1: prohibición de borrado físico (Art. III.1) ---\n";
// ---------------------------------------------------------------------------
$deleteBlocked = false;
$deleteMessage = '';
try {
    $pdo->prepare("DELETE FROM incident_assignments WHERE id = :id")->execute([':id' => $assignmentId]);
} catch (PDOException $e) {
    $deleteBlocked = true;
    $deleteMessage = $e->getMessage();
}
$survivors = (int)$pdo->query("SELECT COUNT(*) FROM incident_assignments WHERE id = {$assignmentId}")->fetchColumn();
$assert('1.1 DELETE directo rechazado por el disparador', $deleteBlocked, $deleteMessage);
$assert('1.2 La fila sobrevive íntegra al intento de borrado', $survivors === 1);

// ---------------------------------------------------------------------------
echo "\n--- Caso 2: inmutabilidad de apertura y snapshots (Art. III.3) ---\n";
// ---------------------------------------------------------------------------
$openingBlocked = false;
try {
    $pdo->prepare("UPDATE incident_assignments SET technician_name_snapshot = 'Nombre reescrito' WHERE id = :id")
        ->execute([':id' => $assignmentId]);
} catch (PDOException $e) {
    $openingBlocked = str_contains($e->getMessage(), 'ART_III_3') || $e->getCode() === '45000';
}
$assert('2.1 Reescribir un snapshot de apertura está bloqueado (D2)', $openingBlocked);

$pdo->prepare(
    "UPDATE incident_assignments SET unassigned_at = assigned_at + INTERVAL '1 minute', "
    . "unassigned_by_name_snapshot = 'Coordinación / Sistema', "
    . "unassignment_reason_type = 'RESOLVED', reassignment_reason = 'Cierre de prueba' "
    . "WHERE id = :id"
)->execute([':id' => $assignmentId]);

$closedBlocked = false;
try {
    $pdo->prepare("UPDATE incident_assignments SET reassignment_reason = 'Reescritura del pasado' WHERE id = :id")
        ->execute([':id' => $assignmentId]);
} catch (PDOException $e) {
    $closedBlocked = str_contains($e->getMessage(), 'ART_III_3') || $e->getCode() === '45000';
}
$assert('2.2 Un tramo cerrado no admite ninguna modificación', $closedBlocked);

$reasonAfter = $pdo->query("SELECT reassignment_reason FROM incident_assignments WHERE id = {$assignmentId}")->fetchColumn();
$assert('2.3 El motivo original permanece intacto', $reasonAfter === 'Cierre de prueba');

// ---------------------------------------------------------------------------
echo "\n--- Caso 3: un único custodio activo (Art. V.3) ---\n";
// ---------------------------------------------------------------------------
$insertOpen();

$doubleBlocked = false;
$doubleCode = '';
try {
    $insertOpen();
} catch (PDOException $e) {
    $doubleBlocked = true;
    $doubleCode = (string)$e->getCode();
}
$assert('3.1 Dos tramos abiertos sobre la misma incidencia => violación única', $doubleBlocked && $doubleCode === '23505', $doubleCode);

$activeCount = (int)$pdo->query("SELECT COUNT(*) FROM incident_assignments WHERE incident_id = {$incidentId} AND unassigned_at IS NULL")->fetchColumn();
$assert('3.2 Exactamente un custodio activo', $activeCount === 1);

$indexExists = (bool)$pdo->query("SELECT 1 FROM pg_indexes WHERE indexname = 'uq_incident_assignments_active_custody'")->fetchColumn();
$assert('3.3 El índice parcial constitucional existe en el motor', $indexExists);

// ---------------------------------------------------------------------------
echo "\n--- Caso 4: opacidad en Técnico y Portal de Sede (Art. V.4, QA 5) ---\n";
// ---------------------------------------------------------------------------
$privacyIncident = $makeIncident();
$privacyId = (int)$privacyIncident->getId();
$incidentRepo->assign($privacyId, $technicianId, $coordinatorId);

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken  = $authService->generateInternalToken($technician);
$siteToken        = $authService->generateSiteToken($location);

$responses = [
    'detalle técnico' => $router->dispatch(new Request(
        method: 'GET',
        path: "/api/technician/incidents/{$privacyId}/detail",
        headers: ['Authorization' => "Bearer {$technicianToken}"]
    )),
    'parte de servicio' => $router->dispatch(new Request(
        method: 'GET',
        path: "/api/technician/incidents/{$privacyId}/service-sheet",
        headers: ['Authorization' => "Bearer {$technicianToken}"]
    )),
    'portal de sede' => $router->dispatch(new Request(
        method: 'GET',
        path: "/api/location/incidents/{$privacyId}/comments",
        headers: ['Authorization' => "Bearer {$siteToken}"]
    )),
];

$assert(
    '4.1 El detalle del técnico responde 200 y no publica la cadena',
    $responses['detalle técnico']->getStatusCode() === 200
        && !str_contains((string)json_encode($responses['detalle técnico']->getDecodedBody()), 'custody_chain'),
    'HTTP ' . $responses['detalle técnico']->getStatusCode()
);

$leaks = [];
foreach ($responses as $label => $response) {
    $body = (string)$response->getBody();
    if (str_contains($body, 'custody_chain') || str_contains($body, 'reassignment_reason')) {
        $leaks[] = $label;
    }
}
$assert('4.2 Ninguna superficie móvil o de sede publica cadena ni motivo interno', $leaks === [], implode(', ', $leaks));

$presentationLeaks = [];
$directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src/Presentation'));
foreach ($directory as $file) {
    if ($file->isFile() && str_contains((string)file_get_contents($file->getPathname()), 'custody_chain')) {
        $presentationLeaks[] = $file->getFilename();
    }
}
$assert('4.3 El literal custody_chain no existe en la capa de presentación', $presentationLeaks === [], implode(', ', $presentationLeaks));

$coordinatorDetail = $router->dispatch(new Request(
    method: 'GET',
    path: "/api/coordinator/incidents/{$privacyId}/detail",
    headers: ['Authorization' => "Bearer {$coordinatorToken}"]
));
$assert(
    '4.4 Coordinación sí recibe custody_chain en la ficha integral (D3)',
    $coordinatorDetail->getStatusCode() === 200
        && str_contains((string)json_encode($coordinatorDetail->getDecodedBody()), '"custody_chain"')
);

echo "\n======================================================================\n";
echo " Total aserciones fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. T-CUSTODY-11 (CONSTITUCIONAL) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}
echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
