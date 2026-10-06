<?php

declare(strict_types=1);

/**
 * TechnicianMachineHistoryApiTest
 *
 * Integration suite for the technician machine incident history endpoint
 * (RF-07 / EARS H.1-H.6, specs/technical/technician_machine_history_contracts.md).
 *
 * Validates "Hecho cuando" over the real HTTP router and MariaDB:
 * - GET /api/technician/machines/{id}/history returns 200 with the machine block
 *   and the full machine history ordered newest-first, excluding CANCELLED (EARS H.1),
 *   with reopen counts per ticket (EARS H.2).
 * - Authorization: only the technician with the machine's active route incident
 *   may read the history; another technician gets 403 NOT_ASSIGNED_TO_TECHNICIAN
 *   (EARS H.3); unknown machine gets 404 MACHINE_NOT_FOUND (EARS H.4).
 * - RBAC perimeter: no token => 401; coordinator token => 403 FORBIDDEN.
 * - Privacy: no private claimant/refund/economic fields in the payload
 *   (EARS H.5 / Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2 + PDO + cURL-free in-process router dispatch.
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
echo " VendGuard: Test de Integración - TechnicianMachineHistoryApiTest\n";
echo "======================================================================\n\n";

// ─── Bootstrap ───────────────────────────────────────────────────────────────
$pdo = ConnectionFactory::getConnection();

TestDataCleaner::purge($pdo);

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router       = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);

$failures = 0;

$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
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

// ─── Usuarios de Prueba ───────────────────────────────────────────────────────
$tech1 = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$tech2 = $userRepo->findByEmail('marc.ruta@vendguard.internal');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

$assert("0. Usuarios semilla encontrados", $tech1 !== null && $tech2 !== null && $coordinator !== null);
if ($tech1 === null || $tech2 === null || $coordinator === null) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

$tech1Token       = $authService->generateInternalToken($tech1);
$tech2Token       = $authService->generateInternalToken($tech2);
$coordinatorToken = $authService->generateInternalToken($coordinator);

$tech1Id = (int)$tech1->getId();
$tech2Id = (int)$tech2->getId();

// ─── Máquina bajo prueba ─────────────────────────────────────────────────────
$location1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$machines1 = $machineRepo->findActiveByLocationId($location1->getId());
$machine   = $machines1[0];
$machineId = (int)$machine->getId();

// ─── Helper: crear avería y asignarla directamente en BD ────────────────────
$makeAssignedIncident = function (
    int $machineId,
    int $locationId,
    int $technicianId,
    IncidentStatus $status = IncidentStatus::ASSIGNED,
    UrgencyLevel $urgency = UrgencyLevel::HIGH
) use ($incidentRepo, $pdo): Incident {
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW() WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")
        ->execute([$machineId]);

    $inc = new Incident(
        id: null,
        ticketCode: 'HIST-' . uniqid(),
        machineId: $machineId,
        locationId: $locationId,
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'Avería para el historial de máquina del técnico.',
        urgency: $urgency,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    );
    $created = $incidentRepo->create($inc);

    $pdo->prepare("
        UPDATE incidents
        SET status = :status, assigned_technician_id = :tech_id, assigned_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ")->execute([
        ':status'  => $status->value,
        ':tech_id' => $technicianId,
        ':id'      => $created->getId(),
    ]);

    return $incidentRepo->findById((int)$created->getId());
};

/**
 * Crea una avería histórica cerrada en BD (sin afectar al ticket activo de la máquina):
 * deleted_at se deja a NULL y el estado es terminal, por lo que no compite como ticket activo.
 */
$makeClosedHistoryIncident = function (
    int $machineId,
    int $locationId,
    string $createdAt,
    bool $withReopenEvent = false
) use ($incidentRepo, $pdo): Incident {
    $inc = new Incident(
        id: null,
        ticketCode: 'HIST-' . uniqid(),
        machineId: $machineId,
        locationId: $locationId,
        category: IncidentCategory::PRODUCT_JAM,
        description: 'Avería histórica cerrada de la máquina.',
        urgency: UrgencyLevel::MEDIUM,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: $createdAt
    );
    $created = $incidentRepo->create($inc);

    $pdo->prepare("
        UPDATE incidents
        SET status = 'CLOSED',
            assigned_technician_id = :tech_id,
            resolved_at = :resolved_at,
            closed_at = :closed_at
        WHERE id = :id
    ")->execute([
        ':tech_id'    => null,
        ':resolved_at' => $createdAt,
        ':closed_at'   => $createdAt,
        ':id'          => $created->getId(),
    ]);

    if ($withReopenEvent) {
        $incidentRepo->insertHistory(
            (int)$created->getId(),
            null,
            'RESOLVED',
            'REOPENED',
            'Reapertura en ventana de garantía de 48h por fallo persistente.'
        );
    }

    return $incidentRepo->findById((int)$created->getId());
};

$dispatch = function (string $path, string $token) use ($router): \VendGuard\Presentation\Http\Response {
    return $router->dispatch(new Request(
        method: 'GET',
        path: $path,
        headers: ['Authorization' => "Bearer {$token}"]
    ));
};

// =========================================================================
// CASO 1: RBAC y máquina inexistente (401 / 403 / 404)
// =========================================================================
echo "\n--- Caso 1: RBAC y máquina inexistente (401 / 403 / 404) ---\n";

$res = $router->dispatch(new Request(method: 'GET', path: "/api/technician/machines/{$machineId}/history"));
$assert("1.1 Sin token => 401 UNAUTHORIZED",
    $res->getStatusCode() === 401 && ($res->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED');

$res = $dispatch("/api/technician/machines/{$machineId}/history", $coordinatorToken);
$assert("1.2 Coordinador en endpoint de técnico => 403 FORBIDDEN",
    $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN');

$res = $dispatch('/api/technician/machines/999999/history', $tech1Token);
$assert("1.3 Máquina inexistente => 404 MACHINE_NOT_FOUND (EARS H.4)",
    $res->getStatusCode() === 404 && ($res->getDecodedBody()['error']['code'] ?? '') === 'MACHINE_NOT_FOUND');

// =========================================================================
// CASO 2: Autorización de pertenencia a la ruta activa (EARS H.3)
// =========================================================================
echo "\n--- Caso 2: Autorización de pertenencia a la ruta activa (EARS H.3) ---\n";

// Sin avería activa de la máquina para tech1 => 403.
$res = $dispatch("/api/technician/machines/{$machineId}/history", $tech1Token);
$assert("2.1 Máquina sin avería activa asignada al técnico => 403 NOT_ASSIGNED_TO_TECHNICIAN",
    $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'NOT_ASSIGNED_TO_TECHNICIAN');

// Historial real en BD: dos averías cerradas (una con reapertura). Se crean ANTES del
// ticket activo porque `create()` bloquea duplicados mientras exista un aviso activo.
$oldClosed = $makeClosedHistoryIncident($machineId, $location1->getId(), '2026-08-01 08:00:00', false);
$recentClosed = $makeClosedHistoryIncident($machineId, $location1->getId(), '2026-09-15 08:00:00', true);

// El ticket activo de la máquina pasa a pertenecer a tech2 => tech1 sigue sin acceso.
$activeIncident = $makeAssignedIncident($machineId, $location1->getId(), $tech2Id);
$res = $dispatch("/api/technician/machines/{$machineId}/history", $tech1Token);
$assert("2.2 Avería activa de OTRO técnico => 403 NOT_ASSIGNED_TO_TECHNICIAN",
    $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'NOT_ASSIGNED_TO_TECHNICIAN');

$res = $dispatch("/api/technician/machines/{$machineId}/history", $tech2Token);
$assert("2.3 Técnico con la avería activa de la máquina => 200 OK",
    $res->getStatusCode() === 200 && ($res->getDecodedBody()['success'] ?? false) === true);

// =========================================================================
// CASO 3: Contenido del historial (EARS H.1, H.2) [CONDICIÓN "HECHO CUANDO"]
// =========================================================================
echo "\n--- Caso 3: Contenido del historial (EARS H.1, H.2) ---\n";

$res = $dispatch("/api/technician/machines/{$machineId}/history", $tech2Token);
$body = $res->getDecodedBody();
$data = $body['data'] ?? [];

$assert("3.1 Bloque machine con id, code y model",
    ((int)($data['machine']['id'] ?? 0)) === $machineId
        && ($data['machine']['code'] ?? '') === $machine->getCode()
        && ($data['machine']['model'] ?? '') === $machine->getModel());

$history = $data['history'] ?? [];
$historyIds = array_map(fn(array $e): int => (int)$e['id'], $history);

$assert("3.2 El historial incluye el ticket activo y las dos averías cerradas (excluye descartes, EARS H.1)",
    in_array((int)$activeIncident->getId(), $historyIds, true)
        && in_array((int)$oldClosed->getId(), $historyIds, true)
        && in_array((int)$recentClosed->getId(), $historyIds, true)
        && count($historyIds) === 3);

$assert("3.3 Ordenado de más reciente a más antigua (activo, 15-09, 01-08)",
    ($historyIds[0] ?? null) === (int)$activeIncident->getId()
        && ($historyIds[1] ?? null) === (int)$recentClosed->getId()
        && ($historyIds[2] ?? null) === (int)$oldClosed->getId());

$entryByKey = [];
foreach ($history as $entry) {
    $entryByKey[(int)$entry['id']] = $entry;
}
$reopenEntry = $entryByKey[(int)$recentClosed->getId()] ?? [];
$assert("3.4 Recuento de reaperturas por expediente (EARS H.2)",
    ((int)($reopenEntry['reopen_count'] ?? -1)) === 1
        && ((int)($entryByKey[(int)$oldClosed->getId()]['reopen_count'] ?? -1)) === 0);

$assert("3.5 Entrada con campos del contrato (EARS H.2)",
    isset($reopenEntry['ticket_code'], $reopenEntry['status'], $reopenEntry['urgency'], $reopenEntry['category'], $reopenEntry['description'], $reopenEntry['created_at'], $reopenEntry['resolved_at'], $reopenEntry['closed_at'])
        && array_key_exists('technician_name', $reopenEntry));

$assert("3.6 Fechas de resolución y cierre presentes en la avería cerrada (EARS H.2)",
    ($reopenEntry['resolved_at'] ?? '') !== '' && ($reopenEntry['closed_at'] ?? '') !== '');

// =========================================================================
// CASO 4: Privacidad — cero datos privados (EARS H.5 / Art. V.4)
// =========================================================================
echo "\n--- Caso 4: Privacidad (EARS H.5 / Art. V.4) ---\n";

$serialized = strtolower(json_encode($body) ?: '');
$privateFragments = ['claimant_name', 'claimant_contact', 'claimed_amount', 'bizum_phone', 'iban', 'payment_reference', 'refund_status'];
$found = [];
foreach ($privateFragments as $fragment) {
    if (str_contains($serialized, $fragment)) {
        $found[] = $fragment;
    }
}
$assert("4.1 El payload no contiene ningún dato privado de reintegros (Art. V.4)", $found === []);

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
echo "\n======================================================================\n";
echo " Total Assertions: {$assertions} | Passed: " . ($assertions - $failures) . " | Failed: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% IN GREEN. MACHINE HISTORY API (EARS H.1-H.6) FULFILLED.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FAILURES DETECTED IN TEST SUITE.\n";
echo "======================================================================\n";
exit(1);
