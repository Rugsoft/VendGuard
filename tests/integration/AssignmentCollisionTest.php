<?php

declare(strict_types=1);

/**
 * VendGuard - AssignmentCollisionTest (Módulo 26, EARS 03.4 / R4)
 *
 * Certifica que una asignación o reasignación enviada con una foto obsoleta de la
 * incidencia se rechaza como colisión concurrente, sin abrir ni cerrar tramos de
 * custodia y sin mover al titular vigente:
 *   1. La avería pasa a ASSIGNED con otro técnico entre la lectura y el envío.
 *   2. La avería cambia de titular entre la lectura y el envío.
 *   3. La avería se resuelve en paralelo antes del envío.
 *   4. El controlador traduce la excepción a HTTP 409 con el estado y el custodio
 *      vigentes en `details`.
 *
 * Dogma Vanilla: PHP 8.2 estricto, PDO nativo, sin dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Core\Domain\Exception\AssignmentStateChangedException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Enum\IncidentAssignmentSource;
use VendGuard\Core\Domain\Enum\IncidentUnassignmentReasonType;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Controller\CoordinatorController;
use VendGuard\Presentation\Http\Request;

echo "======================================================================\n";
echo " VendGuard: AssignmentCollisionTest (EARS 03.4 / R4)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$incidentRepo = new PdoIncidentRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);

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
$technicianA = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$technicianB = $pdo->query(
    "SELECT id FROM users WHERE role = 'TECHNICIAN' AND is_active = TRUE AND id <> " . (int)$technicianA->getId() . ' ORDER BY id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

$assert('0.1 Coordinador y primer técnico disponibles', $coordinator !== null && $technicianA !== null);
$assert('0.2 Segundo técnico activo disponible', is_array($technicianB));
if ($coordinator === null || $technicianA === null || !is_array($technicianB)) {
    echo "ERROR FATAL: semillas insuficientes para la prueba.\n";
    exit(1);
}

$coordinatorId = (int)$coordinator->getId();
$techAId       = (int)$technicianA->getId();
$techBId       = (int)$technicianB['id'];

$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$machines = $machineRepo->findActiveByLocationId($location->getId());
$assert('0.3 Sede y máquinas semilla disponibles', $location !== null && count($machines) >= 2);
if ($location === null || count($machines) < 2) {
    echo "ERROR FATAL: parque semilla insuficiente.\n";
    exit(1);
}

$makeIncident = function (int $index) use ($incidentRepo, $pdo, $machines, $location): Incident {
    $machineId = $machines[$index]->getId();
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW() WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")->execute([$machineId]);

    return $incidentRepo->create(new Incident(
        id: null,
        ticketCode: 'TCOL-' . strtoupper(uniqid()),
        machineId: $machineId,
        locationId: $location->getId(),
        category: IncidentCategory::ELECTRICAL_OFF,
        description: 'Prueba de colisión concurrente de asignación (Módulo 26).',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));
};

$custodyRows = function (int $incidentId) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM incident_assignments WHERE incident_id = ?');
    $stmt->execute([$incidentId]);

    return (int)$stmt->fetchColumn();
};

$openCustodyRows = function (int $incidentId) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM incident_assignments WHERE incident_id = ? AND unassigned_at IS NULL');
    $stmt->execute([$incidentId]);

    return (int)$stmt->fetchColumn();
};

// ─────────────────────────────────────────────────────────────────────────────
// Caso 1: la avería se asigna a otro técnico entre la lectura y el envío
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 1: cambio de estado y titular en paralelo ---\n";

$inc1 = $makeIncident(0);
$snapshot1 = $incidentRepo->findById((int)$inc1->getId());
$incidentRepo->assign((int)$inc1->getId(), $techAId);

$collision1 = null;
try {
    $incidentRepo->assign(
        $snapshot1,
        $techBId,
        $coordinatorId,
        null,
        null,
        'Reasignación enviada con la ficha ya obsoleta'
    );
} catch (AssignmentStateChangedException $e) {
    $collision1 = $e;
}

$assert('1.1 La foto obsoleta se rechaza con AssignmentStateChangedException', $collision1 !== null);
$assert(
    '1.2 El código y el estado HTTP son los del contrato',
    $collision1 !== null
        && $collision1->getErrorCode() === 'ASSIGNMENT_STATE_CHANGED'
        && $collision1->getHttpStatusCode() === 409
);
$assert(
    '1.3 El detalle informa del estado y del custodio vigentes',
    $collision1 !== null
        && $collision1->getDetails()['current_status'] === 'ASSIGNED'
        && $collision1->getDetails()['current_technician_id'] === $techAId
        && $collision1->getDetails()['expected_status'] === 'REGISTERED'
        && $collision1->getDetails()['expected_technician_id'] === null
);

$row1 = $pdo->query("SELECT status, assigned_technician_id FROM incidents WHERE id = {$inc1->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert('1.4 El titular vigente no se movió', (int)($row1['assigned_technician_id'] ?? 0) === $techAId);
$assert('1.5 No se abrió un segundo tramo de custodia', $custodyRows((int)$inc1->getId()) === 1);
$assert('1.6 El tramo del titular vigente sigue abierto', $openCustodyRows((int)$inc1->getId()) === 1);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 2: la avería cambia de titular entre la lectura y el envío
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 2: cambio de titular en paralelo ---\n";

$inc2 = $makeIncident(1);
$incidentRepo->assign((int)$inc2->getId(), $techAId);
$snapshot2 = $incidentRepo->findById((int)$inc2->getId());
$incidentRepo->assign((int)$inc2->getId(), $techBId, $coordinatorId, null, null, 'Cambio de titular previo a la colisión');

$collision2 = null;
try {
    $incidentRepo->assign(
        $snapshot2,
        $techBId,
        $coordinatorId,
        null,
        null,
        'Intento de asignar a quien ya asumió la custodia en paralelo'
    );
} catch (AssignmentStateChangedException $e) {
    $collision2 = $e;
}

$assert('2.1 El cambio de titular se detecta', $collision2 !== null);
$assert(
    '2.2 El detalle apunta al nuevo custodio',
    $collision2 !== null && $collision2->getDetails()['current_technician_id'] === $techBId
);
$assert('2.3 Solo hay dos tramos: el cerrado de A y el abierto de B', $custodyRows((int)$inc2->getId()) === 2);
$assert('2.4 El tramo abierto sigue siendo uno', $openCustodyRows((int)$inc2->getId()) === 1);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 3: la avería se resuelve en paralelo antes del envío
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 3: resolución en paralelo ---\n";

$inc3 = $makeIncident(1);
$incidentRepo->assign((int)$inc3->getId(), $techAId);
$pdo->prepare("UPDATE incidents SET status = 'IN_PROGRESS', started_at = COALESCE(started_at, NOW()) WHERE id = ?")
    ->execute([(int)$inc3->getId()]);
$snapshot3 = $incidentRepo->findById((int)$inc3->getId());
$incidentRepo->resolve((int)$inc3->getId(), $techAId, 'Diagnóstico de prueba de colisión', 'Acción de prueba de colisión');

$collision3 = null;
try {
    $incidentRepo->assign(
        $snapshot3,
        $techBId,
        $coordinatorId,
        null,
        null,
        'Reasignación enviada tras la resolución paralela'
    );
} catch (AssignmentStateChangedException $e) {
    $collision3 = $e;
}

$assert('3.1 La resolución paralela se detecta como colisión', $collision3 !== null);
$assert(
    '3.2 El detalle informa del estado final vigente',
    $collision3 !== null && $collision3->getDetails()['current_status'] === 'RESOLVED'
);
$assert('3.3 No queda ningún tramo abierto tras la resolución', $openCustodyRows((int)$inc3->getId()) === 0);
$assert('3.4 No se añadió un tramo nuevo al intento rechazado', $custodyRows((int)$inc3->getId()) === 1);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 4: el controlador traduce la colisión a 409 ASSIGNMENT_STATE_CHANGED
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 4: mapeo HTTP 409 ---\n";

$inc4 = $makeIncident(0);
$collisionRepo = new class($pdo) extends PdoIncidentRepository {
    public function assign(
        int|Incident $incidentId,
        int $technicianId,
        ?int $coordinatorId = null,
        ?string $urgencyOverride = null,
        ?string $urgencyReason = null,
        ?string $reassignmentReason = null,
        ?AuditEvent $auditEvent = null,
        ?IncidentAssignmentSource $assignmentSource = null,
        ?IncidentUnassignmentReasonType $unassignmentReason = null,
        ?string $actorNameSnapshot = null
    ): Incident {
        throw new AssignmentStateChangedException(
            IncidentStatus::REGISTERED,
            null,
            IncidentStatus::RESOLVED,
            7,
            'Técnico vigente'
        );
    }

    public function assignWithAudit(
        int|Incident $incidentId,
        int $technicianId,
        ?int $coordinatorId,
        ?string $urgencyOverride,
        ?string $urgencyReason,
        ?string $reassignmentReason,
        AuditEvent $auditEvent,
        ?IncidentAssignmentSource $assignmentSource = null,
        ?IncidentUnassignmentReasonType $unassignmentReason = null,
        ?string $actorNameSnapshot = null
    ): Incident {
        throw new AssignmentStateChangedException(
            IncidentStatus::REGISTERED,
            null,
            IncidentStatus::RESOLVED,
            7,
            'Técnico vigente'
        );
    }
};

$controller = new CoordinatorController(
    incidentRepo: $collisionRepo,
    userRepo: $userRepo,
    locationRepo: $locationRepo,
    machineRepo: $machineRepo
);

$request = (new Request(
    method: 'PATCH',
    path: '/api/coordinator/incidents/' . $inc4->getId() . '/assign',
    parsedBody: ['technician_id' => $techAId]
))
    ->setRouteParams(['id' => (string)$inc4->getId()])
    ->setAttribute('user_id', $coordinatorId)
    ->setAttribute('user_role', 'COORDINATOR');

$response = $controller->assignTechnician($request);
$body = $response->getDecodedBody() ?? [];

$assert('4.1 HTTP 409', $response->getStatusCode() === 409, 'Código: ' . $response->getStatusCode());
$assert(
    '4.2 Código de error ASSIGNMENT_STATE_CHANGED',
    ($body['error']['code'] ?? '') === 'ASSIGNMENT_STATE_CHANGED'
);
$assert(
    '4.3 El cuerpo trae el estado y el custodio vigentes',
    ($body['error']['details']['current_status'] ?? '') === 'RESOLVED'
        && ($body['error']['details']['current_technician_id'] ?? null) === 7
);

echo "\n======================================================================\n";
echo " RESULTADO: {$failures} FALLO(S)\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
