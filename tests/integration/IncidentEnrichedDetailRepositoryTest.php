<?php

declare(strict_types=1);

/**
 * IncidentEnrichedDetailRepositoryTest
 *
 * Test de integración de la tarea T-IDM-02 (módulo 09, modal de detalle integral en
 * triaje). Valida la condición "Hecho cuando": `PdoIncidentRepository` expone
 * `findEnrichedDetailById(int|string $identifier): ?array`, que recupera en una única
 * transacción de lectura el expediente completo de la avería: incidencia, máquina,
 * sede cliente, técnico asignado, hitos de historial, piezas solicitadas en pausa,
 * piezas sustituidas con coste congelado, bitácora de comentarios y expediente de
 * reintegro vinculado.
 *
 * La limpieza pasa siempre por TestDataCleaner (contrato de limpieza, Art. III): la
 * suite no ejecuta ningún DELETE directo.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

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

echo "======================================================================\n";
echo " VendGuard: Integración - Expediente Enriquecido de Incidencia (T-IDM-02)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();

$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);

$assertions = 0;
$failures = 0;

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

// =====================================================================
// ARNÉS: semillas estables (sede, máquina, técnico y coordinador)
// =====================================================================
echo "--- Grupo 0: Arnés de datos y expediente de prueba ---\n";

$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$machine = null;
if ($location !== null) {
    foreach ($machineRepo->findActiveByLocationId($location->getId()) as $candidate) {
        if ($candidate->getCode() === 'VEND-0102') {
            $machine = $candidate;
            break;
        }
    }
}
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

$assert(
    '0.1 Semillas localizadas (sede SEDE-BCN-01, máquina VEND-0102, técnico y coordinador)',
    $location !== null && $machine !== null && $technician !== null && $coordinator !== null
);

if ($location === null || $machine === null || $technician === null || $coordinator === null) {
    echo "\n[ERROR CRITICO] No se pudieron resolver las semillas. Abortando pruebas.\n";
    exit(1);
}

$ticketCode = 'INC-TEST-TIDM02';
TestDataCleaner::purgeIncidentsMatchingTicket($pdo, $ticketCode . '%');

// Expediente de ejemplo del contrato: avería crítica reabierta en garantía, en pausa
// por repuestos, con pieza fuera de catálogo, sustitución declarada y reintegro.
$incident = new Incident(
    id: null,
    ticketCode: $ticketCode,
    machineId: $machine->getId(),
    locationId: $location->getId(),
    category: IncidentCategory::TEMPERATURE_COLD,
    description: 'El compresor no arranca y los sándwiches superan los 9°C.',
    urgency: UrgencyLevel::CRITICAL,
    status: IncidentStatus::REGISTERED,
    reporterName: 'Conserjería Hospital',
    reporterPhone: '611223344',
    photoPath: '/uploads/evidence/evidence_tidm02.jpg'
);

$created = $incidentRepo->create($incident, null, 'Aviso registrado mediante código QR');
$incidentId = (int)$created->getId();

$pdo->prepare(
    "UPDATE `incidents`
     SET `status` = 'PENDING_PARTS',
         `assigned_technician_id` = :technician_id,
         `assigned_at` = '2026-10-01 08:30:00',
         `started_at` = '2026-10-01 09:10:00',
         `pending_parts_reason` = :pause_reason,
         `reopen_reason` = :reopen_reason,
         `reopened_at` = '2026-10-02 11:30:00'
     WHERE `id` = :id"
)->execute([
    ':technician_id' => $technician->getId(),
    ':pause_reason' => 'Fallo en condensador de arranque y relé térmico del compresor.',
    ':reopen_reason' => 'La máquina volvió a fallar 2 horas después de la reparación del técnico.',
    ':id' => $incidentId,
]);

$incidentRepo->insertHistory(
    $incidentId,
    $coordinator->getId(),
    'REGISTERED',
    'ASSIGNED',
    'Incidencia asignada al técnico ID ' . $technician->getId() . '.'
);
$incidentRepo->insertHistory(
    $incidentId,
    $coordinator->getId(),
    'ASSIGNED',
    'PENDING_PARTS',
    'Pausa técnica por repuestos pendientes.'
);

$sparePartId = (int)$pdo->query("SELECT id FROM `spare_parts` WHERE `part_code` = 'VALV-ULKA-01'")->fetchColumn();

$pdo->prepare(
    "INSERT INTO `spare_part_requests`
        (`incident_id`, `spare_part_id`, `is_out_of_catalog`, `custom_part_description`, `quantity`, `status`, `requested_by_user_id`)
     VALUES (:incident_id, :spare_part_id, 0, NULL, 1, 'PENDING', :technician_id)"
)->execute([
    ':incident_id' => $incidentId,
    ':spare_part_id' => $sparePartId,
    ':technician_id' => $technician->getId(),
]);

$pdo->prepare(
    "INSERT INTO `spare_part_requests`
        (`incident_id`, `spare_part_id`, `is_out_of_catalog`, `custom_part_description`, `quantity`, `status`, `requested_by_user_id`)
     VALUES (:incident_id, NULL, 1, :custom_description, 1, 'PENDING', :technician_id)"
)->execute([
    ':incident_id' => $incidentId,
    ':custom_description' => 'Abrazadera reforzada antivibración para circuito de cobre',
    ':technician_id' => $technician->getId(),
]);

$pdo->prepare(
    "INSERT INTO `incident_replaced_parts`
        (`intervention_type`, `incident_id`, `machine_id`, `location_id`, `technician_id`, `spare_part_id`,
         `is_out_of_catalog`, `quantity`, `unit_cost_snapshot`, `old_part_destination`, `notes`)
     VALUES ('INCIDENT', :incident_id, :machine_id, :location_id, :technician_id, :spare_part_id,
             0, 1, 28.50, 'DESGUACE', :notes)"
)->execute([
    ':incident_id' => $incidentId,
    ':machine_id' => $machine->getId(),
    ':location_id' => $location->getId(),
    ':technician_id' => $technician->getId(),
    ':spare_part_id' => $sparePartId,
    ':notes' => 'Válvula sustituida y enviada a desguace según protocolo de residuos.',
]);

$pdo->prepare(
    "INSERT INTO `incident_comments`
        (`incident_id`, `author_type`, `user_id`, `author_name`, `comment_text`, `photo_path`, `is_internal`)
     VALUES (:incident_id, 'REPORTER', NULL, 'Conserjería Hospital', :text, NULL, 0)"
)->execute([
    ':incident_id' => $incidentId,
    ':text' => 'El agua gotea por debajo de la máquina.',
]);

$pdo->prepare(
    "INSERT INTO `incident_comments`
        (`incident_id`, `author_type`, `user_id`, `author_name`, `comment_text`, `photo_path`, `is_internal`)
     VALUES (:incident_id, 'TECHNICIAN', :user_id, :author_name, :text, NULL, 1)"
)->execute([
    ':incident_id' => $incidentId,
    ':user_id' => $technician->getId(),
    ':author_name' => $technician->getName(),
    ':text' => 'Comprobada fuga en bandeja de desescarche. Pauso aviso esperando recambio.',
]);

$pdo->prepare(
    "INSERT INTO `refund_requests`
        (`incident_id`, `machine_id`, `location_id`, `claimant_name`, `claimant_contact`, `claimed_amount`,
         `product_attempted`, `compensation_method`, `bizum_phone`, `status`, `tracking_token`)
     VALUES (:incident_id, :machine_id, :location_id, 'Ciudadano Anónimo', '600000000', 2.50,
             'Sándwich de pavo', 'BIZUM', '612345789', 'REQUIRES_COORDINATOR_APPROVAL', :tracking_token)"
)->execute([
    ':incident_id' => $incidentId,
    ':machine_id' => $machine->getId(),
    ':location_id' => $location->getId(),
    ':tracking_token' => 'tok-tidm02-' . $incidentId,
]);

$assert('0.2 Expediente de prueba creado con ID asignado', $incidentId > 0 && $sparePartId > 0);

// =====================================================================
// GRUPO 1: Identificador y contrato de lectura
// =====================================================================
echo "\n--- Grupo 1: Identificador, transacción y contrato de retorno ---\n";

$contractKeys = [
    'incident',
    'machine',
    'location',
    'technician',
    'history',
    'comments',
    'requested_parts',
    'replaced_parts',
    'refund',
];

$byId = $incidentRepo->findEnrichedDetailById($incidentId);
$assert(
    '1.1 Por ID entero devuelve los nueve bloques del expediente en orden',
    is_array($byId) && array_keys($byId) === $contractKeys,
    'Claves obtenidas: ' . (is_array($byId) ? implode(', ', array_keys($byId)) : 'null')
);

$byTicket = $incidentRepo->findEnrichedDetailById($ticketCode);
$assert(
    '1.2 Por código de ticket devuelve el mismo expediente',
    is_array($byTicket) && (int)$byTicket['incident']['id'] === $incidentId
);

$byHashedTicket = $incidentRepo->findEnrichedDetailById('#' . $ticketCode);
$assert(
    '1.3 Por código de ticket con prefijo # devuelve el mismo expediente',
    is_array($byHashedTicket) && (int)$byHashedTicket['incident']['id'] === $incidentId
);

$byNumericString = $incidentRepo->findEnrichedDetailById((string)$incidentId);
$assert(
    '1.4 Por ID como cadena numérica devuelve el mismo expediente',
    is_array($byNumericString) && (int)$byNumericString['incident']['id'] === $incidentId
);

$assert('1.5 ID inexistente devuelve null', $incidentRepo->findEnrichedDetailById(2147483647) === null);
$assert('1.6 Ticket inexistente devuelve null', $incidentRepo->findEnrichedDetailById('TICK-2099-99999') === null);
$assert('1.7 Identificador vacío devuelve null', $incidentRepo->findEnrichedDetailById('#') === null);
$assert('1.8 ID negativo devuelve null', $incidentRepo->findEnrichedDetailById(-5) === null);
$assert('1.9 La lectura no deja transacciones abiertas', $pdo->inTransaction() === false);

$pdo->beginTransaction();
$nestedRead = $incidentRepo->findEnrichedDetailById($incidentId);
$callerTransactionPreserved = $pdo->inTransaction();
$pdo->rollBack();
$assert(
    '1.10 Respeta la transacción del llamante sin confirmarla ni cerrarla',
    is_array($nestedRead) && $callerTransactionPreserved === true
);

// =====================================================================
// GRUPO 2: Contenido completo del expediente
// =====================================================================
echo "\n--- Grupo 2: Contenido del expediente enriquecido ---\n";

$detail = $byId;
$incidentRow = $detail['incident'];

$assert(
    '2.1 La incidencia conserva estado, urgencia, descripción y evidencia original',
    $incidentRow['ticket_code'] === $ticketCode
        && $incidentRow['status'] === 'PENDING_PARTS'
        && $incidentRow['urgency'] === 'CRITICAL'
        && $incidentRow['description'] === 'El compresor no arranca y los sándwiches superan los 9°C.'
        && $incidentRow['photo_path'] === '/uploads/evidence/evidence_tidm02.jpg'
);

$assert(
    '2.2 La incidencia conserva la reapertura en garantía con fecha y motivo',
    $incidentRow['reopened_at'] !== null
        && str_contains((string)$incidentRow['reopen_reason'], 'volvió a fallar')
);

$assert(
    '2.3 La incidencia conserva el motivo de la pausa técnica',
    str_contains((string)$incidentRow['pending_parts_reason'], 'condensador de arranque')
);

$assert(
    '2.4 La máquina llega con código, modelo, tipología y ubicación física',
    $detail['machine'] !== null
        && $detail['machine']['code'] === 'VEND-0102'
        && $detail['machine']['model'] === 'Bianchi Gaia Espresso'
        && $detail['machine']['machine_type'] === 'HOT_DRINKS'
        && $detail['machine']['floor_wing'] === 'Planta 1 - Sala Médica'
);

$assert(
    '2.5 La sede llega con código de centro, nombre, dirección y recepción física',
    $detail['location'] !== null
        && $detail['location']['site_code'] === 'SEDE-BCN-01'
        && $detail['location']['name'] !== ''
        && $detail['location']['address'] !== ''
        && (int)$detail['location']['has_physical_reception'] === 1
);

$assert(
    '2.6 El técnico asignado llega con nombre, código de operador y rol',
    $detail['technician'] !== null
        && (int)$detail['technician']['id'] === $technician->getId()
        && $detail['technician']['name'] === $technician->getName()
        && $detail['technician']['operator_code'] === 'OP-01'
);

$assignmentEvent = null;
foreach ($detail['history'] as $historyRow) {
    if ($historyRow['to_status'] === 'ASSIGNED') {
        $assignmentEvent = $historyRow;
        break;
    }
}
$assert(
    '2.7 El historial trae los hitos ordenados y el actor de la asignación',
    count($detail['history']) === 3
        && $assignmentEvent !== null
        && $assignmentEvent['user_name'] === $coordinator->getName()
        && $assignmentEvent['user_role'] === 'COORDINATOR'
);

$assert(
    '2.8 La bitácora distingue el comentario público de la nota interna de taller',
    count($detail['comments']) === 2
        && $detail['comments'][0]['author_type'] === 'REPORTER'
        && (int)$detail['comments'][0]['is_internal'] === 0
        && $detail['comments'][1]['author_type'] === 'TECHNICIAN'
        && (int)$detail['comments'][1]['is_internal'] === 1
        && $detail['comments'][1]['comment_text'] !== ''
);

$requestedParts = $detail['requested_parts'];
$catalogPart = null;
$outOfCatalogPart = null;
foreach ($requestedParts as $partRow) {
    if ((int)$partRow['is_out_of_catalog'] === 1) {
        $outOfCatalogPart = $partRow;
    } else {
        $catalogPart = $partRow;
    }
}
$assert(
    '2.9 La pausa trae la pieza de catálogo y la fuera de catálogo justificada',
    count($requestedParts) === 2
        && $catalogPart !== null
        && $catalogPart['part_code'] === 'VALV-ULKA-01'
        && $catalogPart['part_name'] !== null
        && (int)$catalogPart['quantity'] === 1
        && $outOfCatalogPart !== null
        && $outOfCatalogPart['spare_part_id'] === null
        && str_contains((string)$outOfCatalogPart['custom_part_description'], 'Abrazadera reforzada')
);

$replacedPart = $detail['replaced_parts'][0] ?? null;
$assert(
    '2.10 La sustitución declarada llega con coste unitario congelado y destino reglamentario',
    count($detail['replaced_parts']) === 1
        && $replacedPart !== null
        && $replacedPart['part_code'] === 'VALV-ULKA-01'
        && (float)$replacedPart['unit_cost_snapshot'] === 28.50
        && (float)$replacedPart['total_cost_snapshot'] === 28.50
        && $replacedPart['old_part_destination'] === 'DESGUACE'
);

$refundRow = $detail['refund'];
$assert(
    '2.11 El reintegro vinculado llega con importe, método, contacto y estado',
    $refundRow !== null
        && (float)$refundRow['claimed_amount'] === 2.50
        && $refundRow['compensation_method'] === 'BIZUM'
        && $refundRow['bizum_phone'] === '612345789'
        && $refundRow['status'] === 'REQUIRES_COORDINATOR_APPROVAL'
);

$forbiddenRefundKeys = ['pickup_pin', 'tracking_token', 'pickup_attempts', 'pickup_locked_until'];
$leakedKeys = array_intersect($forbiddenRefundKeys, array_keys((array)$refundRow));
$assert(
    '2.12 El reintegro no expone PIN, token de seguimiento ni contador de intentos (Art. V.4)',
    $leakedKeys === [],
    'Claves sensibles filtradas: ' . implode(', ', $leakedKeys)
);

// =====================================================================
// GRUPO 3: Soft delete (Art. III) y limpieza
// =====================================================================
echo "\n--- Grupo 3: Soft delete y limpieza del arnés ---\n";

$softDeleted = $incidentRepo->softDelete($incidentId);
$assert(
    '3.1 Tras el soft delete, el expediente deja de recuperarse por ID y por ticket',
    $softDeleted === true
        && $incidentRepo->findEnrichedDetailById($incidentId) === null
        && $incidentRepo->findEnrichedDetailById($ticketCode) === null
);

TestDataCleaner::purgeIncident($pdo, $incidentId);

$rawStmt = $pdo->prepare('SELECT COUNT(*) FROM `incidents` WHERE `id` = :id');
$rawStmt->execute([':id' => $incidentId]);
$assert('3.2 Limpieza del arnés completada (fila física eliminada por el limpiador)', (int)$rawStmt->fetchColumn() === 0);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-IDM-02 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
