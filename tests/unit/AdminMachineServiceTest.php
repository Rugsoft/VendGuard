<?php

declare(strict_types=1);

/**
 * AdminMachineServiceTest
 * 
 * Suite de pruebas unitarias para AdminMachineService (Tarea T-ADM-08).
 * Valida validación de código, tipología sanitaria, colisiones, traslados entre sedes,
 * bloqueo ante averías abiertas o en garantía de 48h, reactivación condicionada y auditoría (RF-02, RF-05, Art. II y V.6).
 * 
 * Dogma Vanilla: Cero dependencias externas, PHP 8.2+ puro.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AdminMachineService;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Core\Domain\Exception\InactiveRecordCollisionException;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Exception\MachineTransferBlockedException;
use VendGuard\Core\Domain\Exception\MachineTypeChangeBlockedException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;

echo "======================================================================\n";
echo " VendGuard: Test Unitario - AdminMachineServiceTest (T-ADM-08)\n";
echo "======================================================================\n\n";

$assertions = 0;

function assertCondition(bool $cond, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$cond) {
        echo "  [FAIL] {$message}\n";
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

/**
 * Repositorio en memoria para Máquinas.
 */
class InMemoryMachineRepository implements MachineRepositoryInterface
{
    /** @var array<int, Machine> */
    public array $machines = [];
    /** @var array<int, array{ticket_code: string, status: string}> */
    public array $activeTickets = [];
    private int $nextId = 1;

    public function findActiveByLocationId(int $locationId): array
    {
        return array_values(array_filter(
            $this->machines,
            fn(Machine $m) => $m->getLocationId() === $locationId && $m->isActive() && $m->getDeletedAt() === null
        ));
    }

    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        $m = $this->machines[$id] ?? null;
        if ($m === null) return null;
        if (!$allowDeleted && $m->getDeletedAt() !== null) return null;
        return $m;
    }

    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        $norm = strtoupper(trim($code));
        foreach ($this->machines as $m) {
            if ($m->getCode() === $norm) {
                if (!$allowDeleted && $m->getDeletedAt() !== null) continue;
                return $m;
            }
        }
        return null;
    }

    public function create(array $data): Machine
    {
        $id = $this->nextId++;
        $m = new Machine(
            $id,
            (int)$data['location_id'],
            $data['code'],
            $data['model'],
            MachineType::fromString($data['machine_type']),
            $data['floor_wing'],
            $data['notes'] ?? null,
            true,
            '2026-09-23 20:00:00',
            '2026-09-23 20:00:00',
            null
        );
        $this->machines[$id] = $m;
        return $m;
    }

    public function update(int $id, array $data): bool
    {
        $m = $this->machines[$id] ?? null;
        if ($m === null) return false;

        $type = array_key_exists('machine_type', $data)
            ? MachineType::fromString($data['machine_type'])
            : $m->getMachineType();

        $this->machines[$id] = new Machine(
            $m->getId(),
            $m->getLocationId(),
            $m->getCode(),
            $data['model'] ?? $m->getModel(),
            $type,
            $data['floor_wing'] ?? $m->getFloorWing(),
            array_key_exists('notes', $data) ? $data['notes'] : $m->getNotes(),
            $m->isActive(),
            $m->getCreatedAt(),
            '2026-09-23 20:05:00',
            $m->getDeletedAt()
        );
        return true;
    }

    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool
    {
        $m = $this->machines[$id] ?? null;
        if ($m === null) return false;

        $this->machines[$id] = new Machine(
            $m->getId(),
            $targetLocationId,
            $m->getCode(),
            $m->getModel(),
            $m->getMachineType(),
            $floorWing,
            $notes !== null ? $notes : $m->getNotes(),
            $m->isActive(),
            $m->getCreatedAt(),
            '2026-09-23 20:06:00',
            $m->getDeletedAt()
        );
        return true;
    }

    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool
    {
        $m = $this->machines[$id] ?? null;
        if ($m === null) return false;

        $this->machines[$id] = new Machine(
            $m->getId(),
            $newLocationId ?? $m->getLocationId(),
            $m->getCode(),
            $m->getModel(),
            $m->getMachineType(),
            $newFloorWing ?? $m->getFloorWing(),
            $m->getNotes(),
            true,
            $m->getCreatedAt(),
            '2026-09-23 20:07:00',
            null
        );
        return true;
    }

    public function findAll(array $filters = []): array
    {
        return [];
    }

    public function hasActiveTicketOrWarranty(int $machineId): bool
    {
        return isset($this->activeTickets[$machineId]);
    }

    public function getActiveTicketOrWarranty(int $machineId): ?array
    {
        return $this->activeTickets[$machineId] ?? null;
    }

    public function softDelete(int $id): bool
    {
        $m = $this->machines[$id] ?? null;
        if ($m === null) return false;

        $this->machines[$id] = new Machine(
            $m->getId(),
            $m->getLocationId(),
            $m->getCode(),
            $m->getModel(),
            $m->getMachineType(),
            $m->getFloorWing(),
            $m->getNotes(),
            false,
            $m->getCreatedAt(),
            '2026-09-23 20:10:00',
            '2026-09-23 20:10:00'
        );
        return true;
    }
}

/**
 * Repositorio en memoria para Sedes.
 */
class InMemoryLocationRepository implements LocationRepositoryInterface
{
    /** @var array<int, Location> */
    public array $locations = [];

    public function findBySiteCode(string $siteCode, bool $onlyActive = true, bool $allowDeleted = false): ?Location
    {
        foreach ($this->locations as $l) {
            if ($l->getSiteCode() === strtoupper(trim($siteCode))) {
                if (!$allowDeleted && $l->getDeletedAt() !== null) continue;
                if ($onlyActive && !$l->isActive()) continue;
                return $l;
            }
        }
        return null;
    }

    public function findById(int $id, bool $allowDeleted = false): ?Location
    {
        $l = $this->locations[$id] ?? null;
        if ($l === null) return null;
        if (!$allowDeleted && $l->getDeletedAt() !== null) return null;
        return $l;
    }

    public function findAllActive(): array { return []; }
    public function findAll(string $status = 'all', ?string $search = null): array { return []; }
    public function create(array $data): Location { throw new \BadMethodCallException('Unused'); }
    public function update(int $id, array $data): bool { return true; }
    public function softDelete(int $id): bool { return true; }
    public function restore(int $id): bool { return true; }
    public function updateContactPhone(int $id, string $contactPhone): bool { return true; }
    public function countActiveMachines(int $locationId): int { return 0; }
}

/**
 * Repositorio en memoria para Auditoría.
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

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array { return $this->events; }
    public function countEvents(array $filters = []): int { return count($this->events); }
    public function findByEntity(string $entityType, int $entityId): array { return []; }
}

// -------------------------------------------------------------
// Inicialización y preparación de fixtures
// -------------------------------------------------------------
$machineRepo = new InMemoryMachineRepository();
$locationRepo = new InMemoryLocationRepository();
$auditRepo = new InMemoryAuditLogRepository();
$auditLogger = new AuditLogger($auditRepo);
$service = new AdminMachineService($machineRepo, $locationRepo, $auditLogger);

// Sedes de prueba
$locationRepo->locations[1] = new Location(1, 'SEDE-BCN-01', 'Hospital del Mar', 'Passeig Marítim 25', 'Marta', '600112233', true, '2026-09-01', '2026-09-01', null);
$locationRepo->locations[2] = new Location(2, 'SEDE-MAD-01', 'Oficinas Madrid', 'Castellana 100', 'Carlos', '600223344', true, '2026-09-01', '2026-09-01', null);
$locationRepo->locations[3] = new Location(3, 'SEDE-INACTIVA', 'Sede Clausurada', 'Calle Cerrada 1', null, null, false, '2026-09-01', '2026-09-01', '2026-09-10');

$coordinatorActor = [
    'id'   => 1,
    'role' => 'COORDINATOR',
    'name' => 'Elena Coordinadora',
];

// =====================================================================
// CASO 1: Creación de máquina y evento MACHINE_CREATED
// =====================================================================
echo "--- Caso 1: Creación de máquina (createMachine) ---\n";

$created = $service->createMachine([
    'code'         => 'VEND-VAL-301',
    'model'        => 'FAS Fast 1050',
    'machine_type' => 'PERISHABLE_FOOD',
    'location_id'  => 1,
    'floor_wing'   => 'Planta 1 - Comedor',
    'notes'        => 'Control térmico estricto',
], $coordinatorActor, '192.168.1.100');

assertCondition($created instanceof Machine, "1.1 createMachine() devuelve instancia de Machine");
assertCondition($created->getCode() === 'VEND-VAL-301', "1.2 Código coincide en mayúsculas");
assertCondition($created->getMachineType()->value === 'PERISHABLE_FOOD', "1.3 Tipología asignada PERISHABLE_FOOD");
assertCondition($created->getMachineType()->isPerishable() === true, "1.4 Es perecedera bajo Art. II");

// Verificar auditoría
assertCondition(count($auditRepo->events) === 1, "1.5 Se registró evento en audit_log");
$ev1 = $auditRepo->events[0];
assertCondition($ev1->getAction() === 'MACHINE_CREATED', "1.6 Acción es MACHINE_CREATED");
assertCondition($ev1->getEntityType() === 'MACHINE', "1.7 Entidad es MACHINE");
assertCondition(($ev1->getNewState()['machine_type'] ?? '') === 'PERISHABLE_FOOD', "1.8 new_state contiene tipología");
assertCondition(($ev1->getMetadata()['ip'] ?? '') === '192.168.1.100', "1.9 Metadatos contienen IP");

// =====================================================================
// CASO 2: Validación de código, modelo y tipología
// =====================================================================
echo "\n--- Caso 2: Validación de datos de entrada ---\n";

// Código inválido
$threwBadCode = false;
try {
    $service->createMachine([
        'code'         => 'vend_invalido',
        'model'        => 'Test',
        'machine_type' => 'COMBO',
        'location_id'  => 1,
        'floor_wing'   => 'P1',
    ], $coordinatorActor);
} catch (\InvalidArgumentException $e) {
    $threwBadCode = true;
}
assertCondition($threwBadCode, "2.1 Rechaza código en minúsculas con guiones bajos");

// Tipología inválida
$threwBadType = false;
try {
    $service->createMachine([
        'code'         => 'VEND-TEST-002',
        'model'        => 'Test',
        'machine_type' => 'TIPO_INVENTADO',
        'location_id'  => 1,
        'floor_wing'   => 'P1',
    ], $coordinatorActor);
} catch (\InvalidArgumentException $e) {
    $threwBadType = true;
}
assertCondition($threwBadType, "2.2 Rechaza tipología sanitaria no permitida");

// Sede inactiva
$threwInactiveLoc = false;
try {
    $service->createMachine([
        'code'         => 'VEND-TEST-003',
        'model'        => 'Test',
        'machine_type' => 'COMBO',
        'location_id'  => 3, // Sede inactiva
        'floor_wing'   => 'P1',
    ], $coordinatorActor);
} catch (\InvalidArgumentException $e) {
    $threwInactiveLoc = true;
}
assertCondition($threwInactiveLoc, "2.3 Rechaza alta en sede cliente inactiva o dada de baja");

// =====================================================================
// CASO 3: Detección de colisiones de código de máquina (EARS 2.1)
// =====================================================================
echo "\n--- Caso 3: Colisión de código de máquina (activo e inactivo) ---\n";

// Colisión con activa
$threwAct = false;
try {
    $service->createMachine([
        'code'         => 'VEND-VAL-301',
        'model'        => 'Otro Modelo',
        'machine_type' => 'HOT_DRINKS',
        'location_id'  => 1,
        'floor_wing'   => 'P2',
    ], $coordinatorActor);
} catch (InactiveRecordCollisionException $e) {
    $threwAct = true;
    assertCondition($e->getErrorCode() === 'MACHINE_ALREADY_EXISTS_ACTIVE', "3.1 Código MACHINE_ALREADY_EXISTS_ACTIVE");
    assertCondition($e->canReactivate() === false, "3.2 canReactivate es false");
}
assertCondition($threwAct, "3.3 Colisión con máquina activa detectada");

// Dar de baja y comprobar colisión inactiva
$machineRepo->softDelete($created->getId());

$threwInact = false;
try {
    $service->createMachine([
        'code'         => 'VEND-VAL-301',
        'model'        => 'Otro Modelo',
        'machine_type' => 'HOT_DRINKS',
        'location_id'  => 1,
        'floor_wing'   => 'P2',
    ], $coordinatorActor);
} catch (InactiveRecordCollisionException $e) {
    $threwInact = true;
    assertCondition($e->getErrorCode() === 'MACHINE_ALREADY_EXISTS_INACTIVE', "3.4 Código MACHINE_ALREADY_EXISTS_INACTIVE");
    assertCondition($e->canReactivate() === true, "3.5 canReactivate es true");
    assertCondition($e->getEntityId() === $created->getId(), "3.6 entity_id señala la máquina inactiva");
}
assertCondition($threwInact, "3.7 Colisión con máquina inactiva detectada");

// Restaurar máquina para siguientes pruebas
$machineRepo->restoreWithLocation($created->getId());

// =====================================================================
// CASO 4: Actualización y bloqueo de tipología si hay avería activa
// =====================================================================
echo "\n--- Caso 4: Actualización y bloqueo sanitario de tipología (Art. II) ---\n";

// Simular avería activa en la máquina
$machineRepo->activeTickets[$created->getId()] = [
    'ticket_code' => 'INC-2026-0042',
    'status'      => 'IN_PROGRESS',
];

$threwTypeBlocked = false;
try {
    $service->updateMachine($created->getId(), [
        'machine_type' => 'HOT_DRINKS', // Intento de cambio
    ], $coordinatorActor);
} catch (MachineTypeChangeBlockedException $e) {
    $threwTypeBlocked = true;
    assertCondition($e->getErrorCode() === 'MACHINE_TYPE_CHANGE_BLOCKED', "4.1 Error MACHINE_TYPE_CHANGE_BLOCKED");
    assertCondition($e->getTicketCode() === 'INC-2026-0042', "4.2 Ticket capturado");
}
assertCondition($threwTypeBlocked, "4.3 Cambio de tipología bloqueado por avería activa");

// Cambiar otros campos (modelo/notas) sí debe permitirse
$updated = $service->updateMachine($created->getId(), [
    'model' => 'FAS Fast 1050 V2',
    'notes' => 'Notas modificadas',
], $coordinatorActor);
assertCondition($updated->getModel() === 'FAS Fast 1050 V2', "4.4 Modelo actualizado con avería activa");
assertCondition($updated->getMachineType()->value === 'PERISHABLE_FOOD', "4.5 Tipología intacta");

// Simular cierre de la avería
unset($machineRepo->activeTickets[$created->getId()]);

// Ahora sí debe permitir cambiar la tipología sanitaria
$updatedType = $service->updateMachine($created->getId(), [
    'machine_type' => 'COMBO',
], $coordinatorActor);
assertCondition($updatedType->getMachineType()->value === 'COMBO', "4.6 Tipología cambiada a COMBO tras cierre de avería");

// =====================================================================
// CASO 5: Traslado de máquina entre sedes y bloqueo por avería o garantía (Art. V.6)
// =====================================================================
echo "\n--- Caso 5: Traslado de sede y bloqueo por garantía (EARS 2.7, 2.8) ---\n";

// Simular ticket en garantía de 48h (RESOLVED)
$machineRepo->activeTickets[$created->getId()] = [
    'ticket_code' => 'INC-2026-0045',
    'status'      => 'RESOLVED',
];

$threwTransfer = false;
try {
    $service->transferMachine($created->getId(), 2, 'Planta 0 - Hall', null, $coordinatorActor);
} catch (MachineTransferBlockedException $e) {
    $threwTransfer = true;
    assertCondition($e->getErrorCode() === 'MACHINE_TRANSFER_BLOCKED', "5.1 Código MACHINE_TRANSFER_BLOCKED");
    assertCondition($e->getTicketStatus() === 'RESOLVED', "5.2 Estado en garantía capturado (RESOLVED)");
}
assertCondition($threwTransfer, "5.3 Traslado bloqueado por ticket en garantía de 48h");

// Liberar garantía
unset($machineRepo->activeTickets[$created->getId()]);

// Traslado exitoso a sede 2
$transferred = $service->transferMachine($created->getId(), 2, 'Planta 0 - Hall Central', 'Traslado exitoso', $coordinatorActor);
assertCondition($transferred->getLocationId() === 2, "5.4 Sede actualizada a SEDE-MAD-01 (ID 2)");
assertCondition($transferred->getFloorWing() === 'Planta 0 - Hall Central', "5.5 Planta/ala actualizada");

$evTransf = end($auditRepo->events);
assertCondition($evTransf->getAction() === 'MACHINE_TRANSFERRED', "5.6 Evento MACHINE_TRANSFERRED registrado");
assertCondition(($evTransf->getMetadata()['target_location_id'] ?? null) === 2, "5.7 Metadatos registran target_location_id = 2");

// =====================================================================
// CASO 6: Baja lógica y bloqueo por averías (EARS 2.10, 2.11)
// =====================================================================
echo "\n--- Caso 6: Baja lógica y bloqueo por averías (deactivateMachine) ---\n";

// Simular avería PENDING_PARTS
$machineRepo->activeTickets[$created->getId()] = [
    'ticket_code' => 'INC-2026-0048',
    'status'      => 'PENDING_PARTS',
];

$threwDeact = false;
try {
    $service->deactivateMachine($created->getId(), $coordinatorActor);
} catch (MachineTransferBlockedException $e) {
    $threwDeact = true;
    assertCondition($e->getErrorCode() === 'MACHINE_DEACTIVATION_BLOCKED', "6.1 Código MACHINE_DEACTIVATION_BLOCKED");
}
assertCondition($threwDeact, "6.2 Baja lógica bloqueada por ticket en PENDING_PARTS");

// Liberar ticket
unset($machineRepo->activeTickets[$created->getId()]);

$deactOk = $service->deactivateMachine($created->getId(), $coordinatorActor);
assertCondition($deactOk === true, "6.3 deactivateMachine() retorna true");
assertCondition($machineRepo->findById($created->getId(), allowDeleted: false) === null, "6.4 Máquina ya no es visible activamente");

$evDeact = end($auditRepo->events);
assertCondition($evDeact->getAction() === 'MACHINE_DEACTIVATED', "6.5 Evento MACHINE_DEACTIVATED registrado");

// =====================================================================
// CASO 7: Reactivación con reubicación obligatoria si sede original inactiva
// =====================================================================
echo "\n--- Caso 7: Reactivación condicionada a nueva sede (EARS 2.12, 2.13) ---\n";

// Supongamos que la sede donde estaba (ID 2) ahora se da de baja
$locationRepo->locations[2] = new Location(2, 'SEDE-MAD-01', 'Oficinas Madrid', 'Castellana 100', 'Carlos', '600223344', false, '2026-09-01', '2026-09-10', '2026-09-10');

// Intento de reactivar sin indicar nueva sede -> debe fallar
$threwReactivateNoLoc = false;
try {
    $service->reactivateMachine($created->getId(), $coordinatorActor);
} catch (\InvalidArgumentException $e) {
    $threwReactivateNoLoc = true;
    assertCondition(str_contains($e->getMessage(), 'sede original de la máquina está dada de baja'), "7.1 Mensaje exige reubicación obligatoria");
}
assertCondition($threwReactivateNoLoc, "7.2 Reactivación denegada si sede original está inactiva y no se provee nueva sede");

// Reactivar indicando sede activa 1 y nueva planta
$reactivated = $service->reactivateMachine($created->getId(), $coordinatorActor, targetLocationId: 1, floorWing: 'Planta Baja - Urgencias');
assertCondition($reactivated instanceof Machine, "7.3 reactivateMachine() devuelve Machine");
assertCondition($reactivated->isActive() === true, "7.4 Máquina vuelve a estar activa");
assertCondition($reactivated->getLocationId() === 1, "7.5 Reubicada a sede activa 1");
assertCondition($reactivated->getFloorWing() === 'Planta Baja - Urgencias', "7.6 Ubicación física actualizada");

$evReact = end($auditRepo->events);
assertCondition($evReact->getAction() === 'MACHINE_REACTIVATED', "7.7 Evento MACHINE_REACTIVATED registrado");
assertCondition(($evReact->getMetadata()['transferred_on_reactivation'] ?? false) === true, "7.8 transferred_on_reactivation es true");

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " RESULTADO: 100% EN VERDE. ({$assertions} aserciones pasadas)\n";
echo " CONDICIÓN T-ADM-08 CUMPLIDA SATISFACTORIAMENTE.\n";
echo "======================================================================\n";
