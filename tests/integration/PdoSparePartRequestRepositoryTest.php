<?php

declare(strict_types=1);

/**
 * PdoSparePartRequestRepositoryTest
 * 
 * Test de Integración para PdoSparePartRequestRepository (Tarea T-SPARE-05).
 * Valida la persistencia de solicitudes de repuestos en pausa técnica (RF-REP-03, RF-REP-04),
 * transición atómica a ATTENDED / CANCELLED y consulta de piezas fuera de catálogo pendientes de revisión.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoSparePartRequestRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRepository;
use VendGuard\Core\Domain\Model\SparePartRequest;
use VendGuard\Core\Domain\Model\SparePartRequestStatus;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - PdoSparePartRequestRepositoryTest (T-SPARE-05)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Asegurar semillas limpias
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$requestRepo = new PdoSparePartRequestRepository($pdo);
$sparePartRepo = new PdoSparePartRepository($pdo);

// Obtener datos existentes para el test
$incStmt = $pdo->query("SELECT id, ticket_code FROM `incidents` LIMIT 2");
$incidents = $incStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($incidents) < 2) {
    echo "  [ERROR] Se requieren al menos 2 incidencias sembradas para ejecutar el test.\n";
    exit(1);
}

$inc1Id = (int)$incidents[0]['id'];
$inc2Id = (int)$incidents[1]['id'];

$techStmt = $pdo->query("SELECT id, name FROM `users` WHERE role = 'TECHNICIAN' LIMIT 1");
$tech = $techStmt->fetch(PDO::FETCH_ASSOC);
$techId = (int)$tech['id'];

$part = $sparePartRepo->findByCode('VALV-ULKA-01');
if ($part === null || $part->getId() === null) {
    echo "  [ERROR] No se encontró el repuesto de catálogo VALV-ULKA-01.\n";
    exit(1);
}
$partId = $part->getId();

// Limpiar solicitudes previas de estas incidencias para aislamiento del test
$pdo->prepare("DELETE FROM `spare_part_requests` WHERE `incident_id` IN (?, ?)")->execute([$inc1Id, $inc2Id]);

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

// =====================================================================
// CASO 1: Registro de solicitud de repuesto de catálogo (createRequest)
// =====================================================================
$catalogReq = new SparePartRequest(
    null,
    $inc1Id,
    $partId,
    false,
    null,
    2,
    SparePartRequestStatus::PENDING,
    $techId
);

$createdCatalog = $requestRepo->createRequest($catalogReq);
$assert("1.1 createRequest asigna ID autoincremental a solicitud de catálogo", $createdCatalog->getId() !== null && $createdCatalog->getId() > 0);
$assert("1.2 Status inicial es PENDING", $createdCatalog->getStatus() === SparePartRequestStatus::PENDING);
$assert("1.3 Cantidad solicitada es 2", $createdCatalog->getQuantity() === 2);
$assert("1.4 isOutOfCatalog es false", $createdCatalog->isOutOfCatalog() === false);

// =====================================================================
// CASO 2: Registro de solicitud fuera de catálogo (createRequest)
// =====================================================================
$customJustification = "Sensor de caída infrarrojo especial de tercera generación para canal 4";
$outOfCatalogReq = new SparePartRequest(
    null,
    $inc1Id,
    null,
    true,
    $customJustification,
    1,
    SparePartRequestStatus::PENDING,
    $techId
);

$createdCustom = $requestRepo->createRequest($outOfCatalogReq);
$assert("2.1 createRequest asigna ID a solicitud fuera de catálogo", $createdCustom->getId() !== null && $createdCustom->getId() > 0);
$assert("2.2 isOutOfCatalog es true", $createdCustom->isOutOfCatalog() === true);
$assert("2.3 spare_part_id es null", $createdCustom->getSparePartId() === null);
$assert("2.4 custom_part_description almacenada exactamente", $createdCustom->getCustomPartDescription() === $customJustification);

// =====================================================================
// CASO 3: Búsqueda individual por ID (findById)
// =====================================================================
$foundCatalog = $requestRepo->findById((int)$createdCatalog->getId());
$assert("3.1 findById recupera solicitud de catálogo", $foundCatalog !== null && $foundCatalog->getId() === $createdCatalog->getId());
$assert("3.2 Enriquecido con part_code", $foundCatalog !== null && $foundCatalog->getPartCode() === 'VALV-ULKA-01');
$assert("3.3 Enriquecido con part_name", $foundCatalog !== null && str_contains($foundCatalog->getPartName() ?? '', 'Electroválvula'));
$assert("3.4 Enriquecido con technician_name", $foundCatalog !== null && !empty($foundCatalog->getTechnicianName()));

// =====================================================================
// CASO 4: Listado de solicitudes por incidencia (findByIncidentId)
// =====================================================================
$inc1Requests = $requestRepo->findByIncidentId($inc1Id);
$assert("4.1 findByIncidentId devuelve 2 solicitudes para la incidencia 1", count($inc1Requests) === 2);
$assert("4.2 Primera solicitud corresponde a la pieza de catálogo", $inc1Requests[0]->getId() === $createdCatalog->getId());
$assert("4.3 Segunda solicitud corresponde a fuera de catálogo", $inc1Requests[1]->getId() === $createdCustom->getId());

// =====================================================================
// CASO 5: Bandeja de piezas fuera de catálogo pendientes de revisión (findPendingOutOfCatalogReviews)
// =====================================================================
$pendingReviews = $requestRepo->findPendingOutOfCatalogReviews();
$matchingReview = null;
foreach ($pendingReviews as $rev) {
    if ((int)$rev['request_id'] === $createdCustom->getId()) {
        $matchingReview = $rev;
        break;
    }
}

$assert("5.1 findPendingOutOfCatalogReviews incluye la solicitud fuera de catálogo PENDING", $matchingReview !== null);
if ($matchingReview !== null) {
    $assert("5.2 Contiene ticket_code", !empty($matchingReview['ticket_code']));
    $assert("5.3 Contiene machine_code y machine_model", !empty($matchingReview['machine_code']) && !empty($matchingReview['machine_model']));
    $assert("5.4 Contiene location_name", !empty($matchingReview['location_name']));
    $assert("5.5 Contiene technician_name", !empty($matchingReview['technician_name']));
    $assert("5.6 Contiene custom_part_description", $matchingReview['custom_part_description'] === $customJustification);
}

// =====================================================================
// CASO 6: Transición atómica a ATTENDED (markAttendedByIncident)
// =====================================================================
$attendedCount = $requestRepo->markAttendedByIncident($inc1Id);
$assert("6.1 markAttendedByIncident actualiza 2 solicitudes", $attendedCount === 2);

$attendedAgainCount = $requestRepo->markAttendedByIncident($inc1Id);
$assert("6.2 Invocación idempotente devuelve 0 actualizadas", $attendedAgainCount === 0);

$reloadedCatalog = $requestRepo->findById((int)$createdCatalog->getId());
$assert("6.3 Solicitud de catálogo ahora tiene estado ATTENDED", $reloadedCatalog !== null && $reloadedCatalog->getStatus() === SparePartRequestStatus::ATTENDED);

$reloadedCustom = $requestRepo->findById((int)$createdCustom->getId());
$assert("6.4 Solicitud fuera de catálogo ahora tiene estado ATTENDED", $reloadedCustom !== null && $reloadedCustom->getStatus() === SparePartRequestStatus::ATTENDED);

// Verificar que ya no figura en pendientes de revisión del coordinador
$pendingAfterAttended = $requestRepo->findPendingOutOfCatalogReviews();
$stillInPending = false;
foreach ($pendingAfterAttended as $rev) {
    if ((int)$rev['request_id'] === $createdCustom->getId()) {
        $stillInPending = true;
        break;
    }
}
$assert("6.5 Solicitud atendida ya no aparece en pendientes de revisión", !$stillInPending);

// =====================================================================
// CASO 7: Transición atómica a CANCELLED (markCancelledByIncident)
// =====================================================================
$reqForInc2 = new SparePartRequest(
    null,
    $inc2Id,
    $partId,
    false,
    null,
    1,
    SparePartRequestStatus::PENDING,
    $techId
);
$createdInc2 = $requestRepo->createRequest($reqForInc2);

$cancelledCount = $requestRepo->markCancelledByIncident($inc2Id);
$assert("7.1 markCancelledByIncident actualiza 1 solicitud a CANCELLED", $cancelledCount === 1);

$reloadedInc2 = $requestRepo->findById((int)$createdInc2->getId());
$assert("7.2 Estado de solicitud cancelada es CANCELLED", $reloadedInc2 !== null && $reloadedInc2->getStatus() === SparePartRequestStatus::CANCELLED);

// Limpieza de datos de prueba
$pdo->prepare("DELETE FROM `spare_part_requests` WHERE `incident_id` IN (?, ?)")->execute([$inc1Id, $inc2Id]);

echo "\n----------------------------------------------------------------------\n";
echo "Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo "Resultado: [OK] Todos los tests de PdoSparePartRequestRepository pasaron con éxito.\n";
    exit(0);
} else {
    echo "Resultado: [FALLO] Se detectaron {$failures} fallos en el repositorio de solicitudes.\n";
    exit(1);
}
