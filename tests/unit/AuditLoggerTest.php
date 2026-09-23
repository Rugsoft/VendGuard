<?php

declare(strict_types=1);

/**
 * AuditLoggerTest
 * 
 * Suite de pruebas unitarias para el servicio de aplicación AuditLogger (T-MET-04).
 * Valida el registro de eventos de tickets, sedes y máquinas, y verifica el cumplimiento
 * estricto de los mandatos constitucionales:
 * - Artículo III.3: Trazabilidad de diagnóstico, solución y piezas sustituidas.
 * - Artículo V.1: Obligatoriedad de justificación (rechazo de resoluciones sin texto suficiente).
 * 
 * Cumple con RF-05 (EARS 5.1 a 5.4), RNF-03 y el Dogma Vanilla de VendGuard.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Core\Domain\Exception\InvalidResolutionException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;

echo "======================================================================\n";
echo " VendGuard: Verificación Unitaria de AuditLogger (T-MET-04)\n";
echo "======================================================================\n\n";

$assertions = 0;

function assertCondition(bool $cond, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$cond) {
        echo "  [FALLO] {$message}\n";
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

/**
 * Repositorio InMemory para aislamiento riguroso en pruebas unitarias puras.
 */
class InMemoryAuditLogRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];
    private int $nextId = 1;

    public function log(AuditEvent $event): AuditEvent
    {
        $saved = new AuditEvent(
            id: $this->nextId++,
            entityType: $event->getEntityType(),
            entityId: $event->getEntityId(),
            action: $event->getAction(),
            userId: $event->getUserId(),
            userRole: $event->getUserRole(),
            userName: $event->getUserName(),
            previousState: $event->getPreviousState(),
            newState: $event->getNewState(),
            metadata: $event->getMetadata(),
            createdAt: $event->getCreatedAt()
        );
        $this->events[] = $saved;
        return $saved;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return array_slice($this->events, $offset, $limit);
    }

    public function countEvents(array $filters = []): int
    {
        return count($this->events);
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return array_filter($this->events, fn(AuditEvent $e) => $e->getEntityType() === $entityType && $e->getEntityId() === $entityId);
    }
}

try {
    $inMemoryRepo = new InMemoryAuditLogRepository();
    $logger = new AuditLogger($inMemoryRepo);

    $techUser = ['id' => 2, 'role' => 'TECHNICIAN', 'name' => 'Jordi Técnico'];
    $coordUser = ['id' => 1, 'role' => 'COORDINATOR', 'name' => 'Sara Coordinadora'];

    // -------------------------------------------------------------
    // 1. Registro de Ciclo de Vida de Ticket (EARS 5.1)
    // -------------------------------------------------------------
    echo "--- 1. Registro de Eventos de Ticket ---\n";

    $eventCreate = $logger->logTicketEvent(
        ticketId: 101,
        action: 'CREATE_TICKET',
        user: ['id' => null, 'role' => 'PUBLIC', 'name' => 'Usuario QR'],
        previousState: null,
        newState: ['status' => 'REGISTERED', 'category' => 'PRODUCT_JAM']
    );
    assertCondition($eventCreate->getId() === 1, "1.1 Ticket creado auditado con ID 1");
    assertCondition($eventCreate->getAction() === 'CREATE_TICKET', "1.2 Acción normalizada 'CREATE_TICKET'");
    assertCondition($eventCreate->getUserRole() === 'PUBLIC', "1.3 Rol de usuario registrado");

    // -------------------------------------------------------------
    // 2. Obligatoriedad Constitucional de Justificación al Resolver (Art. V.1)
    // -------------------------------------------------------------
    echo "\n--- 2. Cumplimiento Art. V.1 (Cierre Obligatoriamente Justificado) ---\n";

    // Intento 1: Sin diagnóstico ni solución -> Debe arrojar InvalidResolutionException
    $failedResolution = false;
    try {
        $logger->logTicketEvent(
            ticketId: 101,
            action: 'RESOLVE_INCIDENT',
            user: $techUser,
            previousState: ['status' => 'IN_PROGRESS'],
            newState: [
                'status' => 'RESOLVED',
                'diagnosis' => 'ok', // Inválido (< 20 caracteres)
                'solution' => 'reparado' // Inválido (< 20 caracteres)
            ]
        );
    } catch (InvalidResolutionException $e) {
        $failedResolution = true;
    }
    assertCondition($failedResolution === true, "2.1 Resolución con textos cortos (< 20 caracteres) rechazada por Art. V.1");

    // Intento 2: Resolución válida completa con piezas sustituidas (Art. III.3 y Art. V.1)
    $validDiagnosis = "Sonda de temperatura descalibrada marcando +12ºC en cuba de frescos."; // > 20 chars
    $validSolution = "Sustitución de sonda NTC y reprogramación de punto de consigna a +3ºC."; // > 20 chars
    $partsReplaced = ["Sonda térmica NTC Sanden", "Conector estanco IP67"];

    $eventResolve = $logger->logTicketEvent(
        ticketId: 101,
        action: 'RESOLVE_INCIDENT',
        user: $techUser,
        previousState: ['status' => 'IN_PROGRESS'],
        newState: [
            'status' => 'RESOLVED',
            'diagnosis' => $validDiagnosis,
            'solution' => $validSolution,
            'parts_replaced' => $partsReplaced,
            'resolution_time_minutes' => 110
        ]
    );
    assertCondition($eventResolve->getId() === 2, "2.2 Resolución válida auditada con ID 2");
    assertCondition($eventResolve->getNewState()['diagnosis'] === $validDiagnosis, "2.3 Diagnóstico técnico capturado");
    assertCondition($eventResolve->getNewState()['parts_replaced'] === $partsReplaced, "2.4 Piezas sustituidas registradas (Art. III.3)");

    // -------------------------------------------------------------
    // 3. Registro de Eventos Maestros de Máquinas y Sedes (EARS 5.2)
    // -------------------------------------------------------------
    echo "\n--- 3. Registro de Eventos Maestros de Máquinas y Sedes ---\n";

    $eventMachine = $logger->logMachineEvent(
        machineId: 5,
        action: 'UPDATE_MACHINE_LOCATION',
        user: $coordUser,
        previousState: ['floor_wing' => 'Planta Baja'],
        newState: ['floor_wing' => 'Planta 1 - Sala Médica']
    );
    assertCondition($eventMachine->getEntityType() === 'MACHINE', "3.1 Entidad MACHINE auditada");
    assertCondition($eventMachine->getAction() === 'UPDATE_MACHINE_LOCATION', "3.2 Acción máquina registrada");

    $eventLocation = $logger->logLocationEvent(
        locationId: 1,
        action: 'UPDATE_PHONE',
        user: $coordUser,
        previousState: ['contact_phone' => '600111222'],
        newState: ['contact_phone' => '600999888']
    );
    assertCondition($eventLocation->getEntityType() === 'LOCATION', "3.3 Entidad LOCATION auditada");
    assertCondition($eventLocation->getAction() === 'UPDATE_PHONE', "3.4 Acción sede registrada");

    // -------------------------------------------------------------
    // 4. Verificación de Historial en Repositorio
    // -------------------------------------------------------------
    echo "\n--- 4. Integridad de Historial Append-Only ---\n";
    assertCondition($inMemoryRepo->countEvents() === 4, "4.1 Se registraron exactamente 4 eventos sin borrado posible");

    echo "\n======================================================================\n";
    echo " RESULTADO: {$assertions}/{$assertions} aserciones pasadas exitosamente [100% VERDE]\n";
    echo "======================================================================\n";

} catch (Throwable $e) {
    echo "\n[ERROR INESPERADO]: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
