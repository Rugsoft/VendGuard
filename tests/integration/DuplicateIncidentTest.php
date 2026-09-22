<?php

declare(strict_types=1);

/**
 * DuplicateIncidentTest
 * 
 * Test de Integración para la restricción de incidencias duplicadas (Tarea T-15).
 * Requisitos: RF-02, Artículo V de la Constitución.
 * 
 * Valida que:
 * 1. El intento de insertar dos incidencias activas consecutivas para la misma máquina
 *    lanza la excepción de dominio DuplicateIncidentException con código 409 Conflict.
 * 2. La restricción de unicidad relacional de MariaDB (uq_machine_active_ticket) protege
 *    incluso ante condiciones de carrera o intentos de bypass.
 * 3. Al transicionar una incidencia a CLOSED o CANCELLED, el candado se libera y se
 *    permite un nuevo aviso.
 * 4. Si la incidencia previa está RESUELTA en ventana de garantía (< 48h), se lanza 409
 *    indicando que debe solicitarse una reapertura en lugar de duplicar ticket.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - DuplicateIncidentTest (T-15)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Inicializar semillas estándar
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
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

// Localizar sede y máquina para las pruebas (VEND-0102 en SEDE-BCN-01)
$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$assert("1. Sede SEDE-BCN-01 encontrada", $location !== null);

$machines = $machineRepo->findActiveByLocationId($location->getId());
$targetMachine = null;
$secondMachine = null;
foreach ($machines as $m) {
    if ($m->getCode() === 'VEND-0102') {
        $targetMachine = $m;
    } elseif ($m->getCode() === 'VEND-0101') {
        $secondMachine = $m;
    }
}
$assert("2. Máquina objetivo VEND-0102 encontrada", $targetMachine !== null);
$assert("3. Segunda máquina VEND-0101 encontrada", $secondMachine !== null);

if ($location !== null && $targetMachine !== null && $secondMachine !== null) {
    $locId = $location->getId();
    $machineId = $targetMachine->getId();

    // Limpiar incidencias previas del test
    $pdo->prepare("DELETE FROM incident_history WHERE incident_id IN (SELECT id FROM incidents WHERE ticket_code LIKE 'INC-DUP-%')")->execute();
    $pdo->prepare("DELETE FROM incidents WHERE ticket_code LIKE 'INC-DUP-%'")->execute();

    // =====================================================================
    // CASO 1: Inserción consecutiva de dos incidencias activas (Condición Hecho cuando:)
    // =====================================================================
    echo "\n--- Caso 1: Detección y bloqueo de segunda incidencia activa (409 Conflict) ---\n";

    // 1.1 Insertar primer ticket activo en la máquina
    $ticket1 = new Incident(
        null,
        'INC-DUP-0001',
        $machineId,
        $locId,
        IncidentCategory::PAYMENT_SYSTEM,
        'Lector de monedas atascado no devuelve cambio',
        UrgencyLevel::HIGH,
        IncidentStatus::REGISTERED,
        null,
        'Encargado Turno Mañana',
        '611000111'
    );

    $created1 = $incidentRepo->create($ticket1, null, 'Aviso inicial creado por encargado');
    $assert(
        "1.1 Primera incidencia activa creada con éxito (Ticket #INC-DUP-0001)",
        $created1->getId() !== null && $created1->getId() > 0
    );

    // 1.2 Intentar insertar una segunda incidencia activa para la MISMA máquina
    $ticket2 = new Incident(
        null,
        'INC-DUP-0002',
        $machineId,
        $locId,
        IncidentCategory::PRODUCT_JAM,
        'Segundo aviso para la misma máquina mientras la primera sigue abierta',
        UrgencyLevel::MEDIUM,
        IncidentStatus::REGISTERED,
        null,
        'Otro empleado',
        '622000222'
    );

    $exceptionCaught = false;
    $httpCode = 0;
    $errorCode = '';
    $errorMessage = '';

    try {
        $incidentRepo->create($ticket2);
    } catch (DuplicateIncidentException $e) {
        $exceptionCaught = true;
        $httpCode = $e->getHttpStatusCode();
        $errorCode = $e->getErrorCode();
        $errorMessage = $e->getMessage();
    } catch (Throwable $e) {
        $errorMessage = "Excepción inesperada: " . get_class($e) . " - " . $e->getMessage();
    }

    $assert(
        "1.2 La segunda inserción consecutiva lanza DuplicateIncidentException",
        $exceptionCaught,
        $errorMessage
    );

    $assert(
        "1.3 El código HTTP de la excepción es 409 Conflict",
        $httpCode === 409,
        "Código HTTP devuelto: {$httpCode}"
    );

    $assert(
        "1.4 El código de error de dominio es MACHINE_HAS_ACTIVE_INCIDENT",
        $errorCode === 'MACHINE_HAS_ACTIVE_INCIDENT',
        "Código de error: {$errorCode}"
    );

    $assert(
        "1.5 El mensaje de error informa del ticket activo preexistente (#INC-DUP-0001)",
        str_contains($errorMessage, 'INC-DUP-0001') && str_contains($errorMessage, 'REGISTERED'),
        "Mensaje: {$errorMessage}"
    );

    // =====================================================================
    // CASO 2: Restricción a nivel de motor MariaDB (uq_machine_active_ticket)
    // =====================================================================
    echo "\n--- Caso 2: Restricción relacional atómica en MariaDB (Blindaje contra carreras) ---\n";

    // Intentar una inserción directa por SQL saltándose cualquier validación PHP
    $dbConstraintCaught = false;
    $dbErrorCode = 0;

    try {
        $rawStmt = $pdo->prepare("
            INSERT INTO incidents (
                ticket_code, machine_id, location_id, category, description, urgency, status
            ) VALUES (
                'INC-DUP-RAW-01', :machine_id, :location_id, 'OTHER', 'Bypass test directo en BD', 'LOW', 'REGISTERED'
            )
        ");
        $rawStmt->execute([':machine_id' => $machineId, ':location_id' => $locId]);
    } catch (PDOException $e) {
        $dbConstraintCaught = true;
        $dbErrorCode = (int)($e->errorInfo[1] ?? 0);
    }

    $assert(
        "2.1 MariaDB rechaza la inserción con error 1062 (Duplicate entry)",
        $dbConstraintCaught && $dbErrorCode === 1062,
        "Error BD capturado: {$dbErrorCode}"
    );

    // =====================================================================
    // CASO 3: Cierre definitivo (CLOSED) libera el candado de la máquina
    // =====================================================================
    echo "\n--- Caso 3: Estado terminal CLOSED libera el candado para nuevos avisos ---\n";

    // Actualizar ticket1 a CLOSED
    $updateStmt = $pdo->prepare("UPDATE incidents SET status = 'CLOSED', closed_at = NOW() WHERE id = :id");
    $updateStmt->execute([':id' => $created1->getId()]);

    // Verificar que is_active_ticket en MariaDB es NULL para ese ticket
    $checkStmt = $pdo->prepare("SELECT is_active_ticket, status FROM incidents WHERE id = :id");
    $checkStmt->execute([':id' => $created1->getId()]);
    $rowClosed = $checkStmt->fetch(PDO::FETCH_ASSOC);

    $assert(
        "3.1 Columna virtual is_active_ticket es NULL cuando status = CLOSED",
        $rowClosed !== false && $rowClosed['is_active_ticket'] === null
    );

    // Intentar crear un nuevo aviso tras el cierre
    $ticket3 = new Incident(
        null,
        'INC-DUP-0003',
        $machineId,
        $locId,
        IncidentCategory::TEMPERATURE_COLD,
        'Nueva avería producida semanas después del cierre de la anterior',
        UrgencyLevel::CRITICAL,
        IncidentStatus::REGISTERED,
        null,
        'Conserje',
        '633000333'
    );

    $created3 = null;
    $createError = '';
    try {
        $created3 = $incidentRepo->create($ticket3);
    } catch (Throwable $e) {
        $createError = $e->getMessage();
    }

    $assert(
        "3.2 Nuevo ticket creado con éxito tras el cierre del anterior",
        $created3 !== null && $created3->getId() > 0,
        "Error al crear: {$createError}"
    );

    // =====================================================================
    // CASO 4: Máquina con aviso en garantía (RESOLVED < 48h)
    // =====================================================================
    echo "\n--- Caso 4: Control de garantía para tickets RESUELTOS recientemente ---\n";

    // Cambiar ticket3 a RESOLVED con timestamp actual
    $pdo->prepare("
        UPDATE incidents 
        SET status = 'RESOLVED', 
            resolved_at = NOW(),
            resolution_diagnosis = 'Sensor de temperatura reemplazado en evaporador',
            resolution_action = 'Prueba de frio completada con exito durante 30 minutos'
        WHERE id = :id
    ")->execute([':id' => $created3->getId()]);

    // Intentar crear un nuevo ticket para la misma máquina mientras está en garantía
    $ticket4 = new Incident(
        null,
        'INC-DUP-0004',
        $machineId,
        $locId,
        IncidentCategory::TEMPERATURE_COLD,
        'El usuario intenta crear otro ticket en vez de reabrir el resuelto',
        UrgencyLevel::CRITICAL,
        IncidentStatus::REGISTERED
    );

    $warrantyCaught = false;
    $warrantyErrorCode = '';
    $warrantyHttpCode = 0;
    $warrantyMsg = '';

    try {
        $incidentRepo->create($ticket4);
    } catch (DuplicateIncidentException $e) {
        $warrantyCaught = true;
        $warrantyErrorCode = $e->getErrorCode();
        $warrantyHttpCode = $e->getHttpStatusCode();
        $warrantyMsg = $e->getMessage();
    }

    $assert(
        "4.1 Intento de crear aviso sobre máquina en garantía lanza DuplicateIncidentException",
        $warrantyCaught
    );

    $assert(
        "4.2 El código de error es MACHINE_IN_WARRANTY",
        $warrantyErrorCode === 'MACHINE_IN_WARRANTY',
        "Código devuelto: {$warrantyErrorCode}"
    );

    $assert(
        "4.3 Código HTTP devuelto es 409 Conflict",
        $warrantyHttpCode === 409
    );

    $assert(
        "4.4 El mensaje instruye al informador a pulsar en 'Reabrir incidencia'",
        str_contains($warrantyMsg, 'Reabrir incidencia') && str_contains($warrantyMsg, 'INC-DUP-0003'),
        "Mensaje: {$warrantyMsg}"
    );

    // =====================================================================
    // CASO 5: Dos máquinas diferentes pueden tener incidencias activas simultáneas
    // =====================================================================
    echo "\n--- Caso 5: Máquinas independientes tienen candados aislados ---\n";

    // Poner ticket3 en CLOSED para limpiar VEND-0102
    $pdo->prepare("UPDATE incidents SET status = 'CLOSED', closed_at = NOW() WHERE id = :id")->execute([':id' => $created3->getId()]);

    // Crear ticket para VEND-0102
    $ticketA = $incidentRepo->create(new Incident(
        null,
        'INC-DUP-MACH-A',
        $targetMachine->getId(),
        $locId,
        IncidentCategory::PRODUCT_JAM,
        'Atasco en máquina A',
        UrgencyLevel::LOW
    ));

    // Limpiar posibles incidencias en máquina B (VEND-0101)
    $pdo->prepare("UPDATE incidents SET status = 'CLOSED' WHERE machine_id = :mid AND is_active_ticket = 1")
        ->execute([':mid' => $secondMachine->getId()]);

    // Crear ticket simultáneo para VEND-0101 (máquina B)
    $ticketB = $incidentRepo->create(new Incident(
        null,
        'INC-DUP-MACH-B',
        $secondMachine->getId(),
        $locId,
        IncidentCategory::OTHER,
        'Luz parpadeando en máquina B',
        UrgencyLevel::LOW
    ));

    $assert(
        "5.1 Máquina A y Máquina B pueden tener incidencias activas simultáneas sin colisión",
        $ticketA->getId() > 0 && $ticketB->getId() > 0 && $ticketA->getId() !== $ticketB->getId()
    );

    // Limpieza final de datos de prueba
    $pdo->prepare("DELETE FROM incident_history WHERE incident_id IN (SELECT id FROM incidents WHERE ticket_code LIKE 'INC-DUP-%')")->execute();
    $pdo->prepare("DELETE FROM incidents WHERE ticket_code LIKE 'INC-DUP-%'")->execute();
}

// Resumen del test
echo "\n======================================================================\n";
echo " Total Aserciones Verificadas | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-15 CUMPLIDA CON ÉXITO.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
