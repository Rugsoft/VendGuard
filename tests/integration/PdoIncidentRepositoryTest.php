<?php

declare(strict_types=1);

/**
 * PdoIncidentRepositoryTest
 * 
 * Test de Integración para PdoIncidentRepository (Tarea T-14).
 * Valida la creación atómica de incidencias, inserción automática de su primer registro
 * en incident_history dentro de una transacción PDO, consultas con datos enriquecidos
 * y cumplimiento estricto de Soft Delete (RNF-03).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentHistory;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
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
echo " VendGuard: Test de Integración - PdoIncidentRepositoryTest (T-14)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Asegurar semillas
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);

$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
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

// Preparar entorno de prueba: buscar máquina VEND-0102
$loc = $locationRepo->findBySiteCode('SEDE-BCN-01');
$assert("1. Sede SEDE-BCN-01 encontrada para las pruebas", $loc !== null);

$machines = $machineRepo->findActiveByLocationId($loc->getId());
$testMachine = null;
foreach ($machines as $m) {
    if ($m->getCode() === 'VEND-0102') {
        $testMachine = $m;
        break;
    }
}
$assert("2. Máquina de pruebas VEND-0102 encontrada", $testMachine !== null);

$tech = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$assert("3. Técnico Jordi encontrado para auditoría", $tech !== null);

if ($testMachine !== null && $loc !== null && $tech !== null) {
    // Limpiar incidencias previas de prueba sobre esta máquina para estado inicial limpio
    $pdo->prepare("DELETE FROM incident_history WHERE incident_id IN (SELECT id FROM incidents WHERE ticket_code LIKE 'INC-TEST-T14%')")->execute();
    $pdo->prepare("DELETE FROM incidents WHERE ticket_code LIKE 'INC-TEST-T14%'")->execute();

    // =====================================================================
    // CASO 1: Creación atómica de Incidencia y primer registro en Historial
    // =====================================================================
    echo "\n--- Caso 1: Creación atómica de Incidencia e Historial (Condición Hecho cuando:) ---\n";

    $newIncident = new Incident(
        null,
        'INC-TEST-T1401',
        $testMachine->getId(),
        $loc->getId(),
        IncidentCategory::TEMPERATURE_COLD,
        'Pérdida de frío detectada en carril de bocadillos. Temperatura 15C.',
        UrgencyLevel::CRITICAL,
        IncidentStatus::REGISTERED,
        null,
        'Carles Responsable',
        '611223344'
    );

    $initialNote = 'Aviso creado por el responsable de sede';
    $createdIncident = $incidentRepo->create($newIncident, null, $initialNote);

    $assert(
        "1.1 create() devuelve una instancia de Incident con ID asignado (> 0)",
        $createdIncident->getId() !== null && $createdIncident->getId() > 0,
        "ID devuelto: " . var_export($createdIncident->getId(), true)
    );

    $assert(
        "1.2 ticket_code coincide con 'INC-TEST-T1401'",
        $createdIncident->getTicketCode() === 'INC-TEST-T1401'
    );

    $assert(
        "1.3 status inicial es REGISTERED",
        $createdIncident->getStatus() === IncidentStatus::REGISTERED
    );

    $assert(
        "1.4 urgency es CRITICAL",
        $createdIncident->getUrgency() === UrgencyLevel::CRITICAL
    );

    $assert(
        "1.5 category es TEMPERATURE_COLD",
        $createdIncident->getCategory() === IncidentCategory::TEMPERATURE_COLD
    );

    $assert(
        "1.6 reporter_name y reporter_phone guardados con éxito",
        $createdIncident->getReporterName() === 'Carles Responsable' &&
        $createdIncident->getReporterPhone() === '611223344'
    );

    // Verificación directa en base de datos: el primer registro en incident_history fue creado
    $historyStmt = $pdo->prepare("SELECT * FROM incident_history WHERE incident_id = :inc_id ORDER BY id ASC");
    $historyStmt->bindValue(':inc_id', $createdIncident->getId(), PDO::PARAM_INT);
    $historyStmt->execute();
    $rawHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

    $assert(
        "1.7 Se insertó exactamente 1 registro en incident_history durante create()",
        count($rawHistory) === 1,
        "Total registros encontrados en historial: " . count($rawHistory)
    );

    if (count($rawHistory) === 1) {
        $hRow = $rawHistory[0];
        $assert(
            "1.8 El registro de auditoría tiene from_status NULL",
            $hRow['from_status'] === null
        );
        $assert(
            "1.9 El registro de auditoría tiene to_status = 'REGISTERED'",
            $hRow['to_status'] === 'REGISTERED'
        );
        $assert(
            "1.10 action_note coincide con la nota inicial de creación",
            $hRow['action_note'] === $initialNote
        );
    }

    // =====================================================================
    // CASO 2: getHistory() y consultas enriquecidas con JOIN
    // =====================================================================
    echo "\n--- Caso 2: getHistory() y consultas enriquecidas con JOIN ---\n";

    $historyObjects = $incidentRepo->getHistory($createdIncident->getId());
    $assert(
        "2.1 getHistory() devuelve listado de objetos IncidentHistory",
        count($historyObjects) === 1 && $historyObjects[0] instanceof IncidentHistory
    );

    $foundById = $incidentRepo->findById($createdIncident->getId());
    $assert(
        "2.2 findById() recupera la incidencia con campos enriquecidos",
        $foundById !== null &&
        $foundById->getMachineCode() === 'VEND-0102' &&
        $foundById->getLocationName() !== null &&
        $foundById->isActive() === true
    );

    $foundByTicket = $incidentRepo->findByTicketCode('inc-test-t1401');
    $assert(
        "2.3 findByTicketCode() es insensible a mayúsculas y espacios",
        $foundByTicket !== null && $foundByTicket->getId() === $createdIncident->getId()
    );

    // =====================================================================
    // CASO 3: findActiveByMachineId()
    // =====================================================================
    echo "\n--- Caso 3: findActiveByMachineId() ---\n";

    $activeForMachine = $incidentRepo->findActiveByMachineId($testMachine->getId());
    $assert(
        "3.1 findActiveByMachineId() localiza la incidencia recién creada",
        $activeForMachine !== null && $activeForMachine->getId() === $createdIncident->getId()
    );

    // =====================================================================
    // CASO 4: insertHistory() en transiciones posteriores
    // =====================================================================
    echo "\n--- Caso 4: insertHistory() para transiciones posteriores ---\n";

    $newHistId = $incidentRepo->insertHistory(
        $createdIncident->getId(),
        $tech->getId(),
        'REGISTERED',
        'ASSIGNED',
        'Asignado a técnico Jordi por el coordinador'
    );

    $assert("4.1 insertHistory() devuelve un ID autoincremental (> 0)", $newHistId > 0);

    $updatedHistory = $incidentRepo->getHistory($createdIncident->getId());
    $assert("4.2 getHistory() ahora contiene 2 registros ordenados", count($updatedHistory) === 2);
    if (count($updatedHistory) === 2) {
        $second = $updatedHistory[1];
        $assert("4.3 Segundo registro tiene from_status REGISTERED", $second->getFromStatus() === 'REGISTERED');
        $assert("4.4 Segundo registro tiene to_status ASSIGNED", $second->getToStatus() === 'ASSIGNED');
        $assert("4.5 Segundo registro tiene user_name de Jordi", $second->getUserName() === $tech->getName());
    }

    // =====================================================================
    // CASO 5: Inviolabilidad de Datos y Soft Delete (RNF-03)
    // =====================================================================
    echo "\n--- Caso 5: Respeto estricto de Soft Delete (RNF-03) ---\n";

    $deletedOk = $incidentRepo->softDelete($createdIncident->getId());
    $assert("5.1 softDelete() devuelve true", $deletedOk);

    $afterDelete = $incidentRepo->findById($createdIncident->getId());
    $assert("5.2 findById() devuelve null tras softDelete", $afterDelete === null);

    $afterDeleteByTicket = $incidentRepo->findByTicketCode('INC-TEST-T1401');
    $assert("5.3 findByTicketCode() devuelve null tras softDelete", $afterDeleteByTicket === null);

    $rawCheck = $pdo->prepare("SELECT id, deleted_at FROM incidents WHERE id = :id");
    $rawCheck->bindValue(':id', $createdIncident->getId(), PDO::PARAM_INT);
    $rawCheck->execute();
    $rawRow = $rawCheck->fetch(PDO::FETCH_ASSOC);

    $assert(
        "5.4 Fila física intacta en la tabla incidents (Prohibido DELETE FROM según Art. III)",
        $rawRow !== false && $rawRow['deleted_at'] !== null
    );

    // Limpieza final de registros de test
    $pdo->prepare("DELETE FROM incident_history WHERE incident_id = :id")->execute([':id' => $createdIncident->getId()]);
    $pdo->prepare("DELETE FROM incidents WHERE id = :id")->execute([':id' => $createdIncident->getId()]);
}

// Resumen del test
echo "\n======================================================================\n";
echo " Total Aserciones Verificadas | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-14 CUMPLIDA CON ÉXITO.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
