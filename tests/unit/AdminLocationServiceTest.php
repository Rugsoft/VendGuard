<?php

declare(strict_types=1);

/**
 * AdminLocationServiceTest
 * 
 * Suite de pruebas unitarias para AdminLocationService (Tarea T-ADM-07).
 * Valida validación de código de sede, colisiones, alta, edición, baja con bloqueo
 * por máquinas activas, reactivación y emisión de auditoría inmutable (RF-01, RF-05).
 * 
 * Dogma Vanilla: Cero dependencias externas, PHP 8.2+ puro.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AdminLocationService;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Core\Domain\Exception\ActiveMachinesBlockedException;
use VendGuard\Core\Domain\Exception\InactiveRecordCollisionException;
use VendGuard\Core\Domain\Exception\LocationNotFoundException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;

echo "======================================================================\n";
echo " VendGuard: Test Unitario - AdminLocationServiceTest (T-ADM-07)\n";
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
 * Repositorio en memoria para Sedes.
 */
class InMemoryLocationRepository implements LocationRepositoryInterface
{
    /** @var array<int, Location> */
    public array $locations = [];
    /** @var array<int, int> locationId => count */
    public array $activeMachinesCount = [];
    private int $nextId = 1;

    public function findBySiteCode(string $siteCode, bool $onlyActive = true, bool $allowDeleted = false): ?Location
    {
        $normalized = strtoupper(trim($siteCode));
        foreach ($this->locations as $loc) {
            if ($loc->getSiteCode() === $normalized) {
                if (!$allowDeleted && $loc->getDeletedAt() !== null) {
                    continue;
                }
                if ($onlyActive && !$loc->isActive()) {
                    continue;
                }
                return $loc;
            }
        }
        return null;
    }

    public function findById(int $id, bool $allowDeleted = false): ?Location
    {
        $loc = $this->locations[$id] ?? null;
        if ($loc === null) {
            return null;
        }
        if (!$allowDeleted && $loc->getDeletedAt() !== null) {
            return null;
        }
        return $loc;
    }

    public function findAllActive(): array
    {
        return array_values(array_filter($this->locations, fn(Location $l) => $l->isActive() && $l->getDeletedAt() === null));
    }

    public function findAll(string $status = 'all', ?string $search = null): array
    {
        $result = [];
        foreach ($this->locations as $loc) {
            $isAct = $loc->isActive() && $loc->getDeletedAt() === null;
            if ($status === 'active' && !$isAct) continue;
            if ($status === 'inactive' && $isAct) continue;

            if ($search !== null && $search !== '') {
                $term = strtolower(trim($search));
                $matches = str_contains(strtolower($loc->getSiteCode()), $term)
                    || str_contains(strtolower($loc->getName()), $term)
                    || str_contains(strtolower($loc->getAddress()), $term);
                if (!$matches) continue;
            }

            $result[] = [
                'id'                    => $loc->getId(),
                'site_code'             => $loc->getSiteCode(),
                'name'                  => $loc->getName(),
                'address'               => $loc->getAddress(),
                'contact_name'          => $loc->getContactName(),
                'contact_phone'         => $loc->getContactPhone(),
                'is_active'             => $isAct,
                'created_at'            => $loc->getCreatedAt() ?? '2026-09-01 00:00:00',
                'updated_at'            => $loc->getUpdatedAt(),
                'deleted_at'            => $loc->getDeletedAt(),
                'active_machines_count' => $this->activeMachinesCount[$loc->getId()] ?? 0,
                'total_machines_count'  => $this->activeMachinesCount[$loc->getId()] ?? 0,
            ];
        }
        return $result;
    }

    public function create(array $data): Location
    {
        $id = $this->nextId++;
        $loc = new Location(
            $id,
            $data['site_code'],
            $data['name'],
            $data['address'],
            $data['contact_name'] ?? null,
            $data['contact_phone'] ?? null,
            true,
            '2026-09-23 20:00:00',
            '2026-09-23 20:00:00',
            null
        );
        $this->locations[$id] = $loc;
        return $loc;
    }

    public function update(int $id, array $data): bool
    {
        $loc = $this->locations[$id] ?? null;
        if ($loc === null) return false;

        $this->locations[$id] = new Location(
            $loc->getId(),
            $loc->getSiteCode(),
            $data['name'] ?? $loc->getName(),
            $data['address'] ?? $loc->getAddress(),
            array_key_exists('contact_name', $data) ? $data['contact_name'] : $loc->getContactName(),
            array_key_exists('contact_phone', $data) ? $data['contact_phone'] : $loc->getContactPhone(),
            $loc->isActive(),
            $loc->getCreatedAt(),
            '2026-09-23 20:05:00',
            $loc->getDeletedAt()
        );
        return true;
    }

    public function softDelete(int $id): bool
    {
        $loc = $this->locations[$id] ?? null;
        if ($loc === null) return false;

        $this->locations[$id] = new Location(
            $loc->getId(),
            $loc->getSiteCode(),
            $loc->getName(),
            $loc->getAddress(),
            $loc->getContactName(),
            $loc->getContactPhone(),
            false,
            $loc->getCreatedAt(),
            '2026-09-23 20:10:00',
            '2026-09-23 20:10:00'
        );
        return true;
    }

    public function restore(int $id): bool
    {
        $loc = $this->locations[$id] ?? null;
        if ($loc === null) return false;

        $this->locations[$id] = new Location(
            $loc->getId(),
            $loc->getSiteCode(),
            $loc->getName(),
            $loc->getAddress(),
            $loc->getContactName(),
            $loc->getContactPhone(),
            true,
            $loc->getCreatedAt(),
            '2026-09-23 20:15:00',
            null
        );
        return true;
    }

    public function updateContactPhone(int $id, string $contactPhone): bool
    {
        return $this->update($id, ['contact_phone' => $contactPhone]);
    }

    public function countActiveMachines(int $locationId): int
    {
        return $this->activeMachinesCount[$locationId] ?? 0;
    }
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
        return array_values(array_filter(
            $this->events,
            fn(AuditEvent $e) => $e->getEntityType() === $entityType && $e->getEntityId() === $entityId
        ));
    }
}

// -------------------------------------------------------------
// Inicialización del servicio y actores de prueba
// -------------------------------------------------------------
$locRepo = new InMemoryLocationRepository();
$auditRepo = new InMemoryAuditLogRepository();
$auditLogger = new AuditLogger($auditRepo);
$service = new AdminLocationService($locRepo, $auditLogger);

$coordinatorActor = [
    'id'   => 1,
    'role' => 'COORDINATOR',
    'name' => 'Elena Coordinadora',
];

// =====================================================================
// CASO 1: Creación exitosa de sede y registro de auditoría
// =====================================================================
echo "--- Caso 1: Creación exitosa de sede (createLocation) ---\n";

$created = $service->createLocation([
    'site_code'     => 'SEDE-VAL-01',
    'name'          => 'Politécnico de Valencia - Rectorado',
    'address'       => 'Camino de Vera s/n, 46022 Valencia',
    'contact_name'  => 'Laura Navarro',
    'contact_phone' => '633445566',
], $coordinatorActor, '192.168.1.50');

assertCondition($created instanceof Location, "1.1 createLocation() devuelve instancia de Location");
assertCondition($created->getSiteCode() === 'SEDE-VAL-01', "1.2 site_code coincide en mayúsculas");
assertCondition($created->isActive() === true, "1.3 is_active es true por defecto");

// Verificar auditoría
assertCondition(count($auditRepo->events) === 1, "1.4 Se registró 1 evento en audit_log");
$ev1 = $auditRepo->events[0];
assertCondition($ev1->getAction() === 'LOCATION_CREATED', "1.5 Acción es LOCATION_CREATED");
assertCondition($ev1->getEntityType() === 'LOCATION', "1.6 Entidad es LOCATION");
assertCondition($ev1->getEntityId() === $created->getId(), "1.7 entity_id coincide con el ID creado");
assertCondition($ev1->getPreviousState() === null, "1.8 previous_state es null en alta");
assertCondition(($ev1->getNewState()['site_code'] ?? '') === 'SEDE-VAL-01', "1.9 new_state contiene site_code");
assertCondition(($ev1->getMetadata()['ip'] ?? '') === '192.168.1.50', "1.10 Metadatos registran IP del cliente");

// =====================================================================
// CASO 2: Validación estricta de formato de site_code
// =====================================================================
echo "\n--- Caso 2: Validación de formato de site_code ---\n";

$invalidCodes = ['ab', 'SEDE MINUSCULAS', 'SEDE_CON_GUION_BAJO', 'A', 'SEDE*INVALIDA!'];
foreach ($invalidCodes as $badCode) {
    $threw = false;
    try {
        $service->createLocation([
            'site_code' => $badCode,
            'name'      => 'Sede Test',
            'address'   => 'Calle Test 123',
        ], $coordinatorActor);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    assertCondition($threw, "2. Código inválido '{$badCode}' rechazado con InvalidArgumentException");
}

// =====================================================================
// CASO 3: Detección de colisiones de código de sede
// =====================================================================
echo "\n--- Caso 3: Colisión de código de sede (activo e inactivo) ---\n";

// 3.1 Colisión con sede activa
$threwActive = false;
try {
    $service->createLocation([
        'site_code' => 'SEDE-VAL-01', // Ya creada y activa
        'name'      => 'Otra Sede Valencia',
        'address'   => 'Calle Secundaria 45',
    ], $coordinatorActor);
} catch (InactiveRecordCollisionException $e) {
    $threwActive = true;
    assertCondition($e->getErrorCode() === 'LOCATION_ALREADY_EXISTS_ACTIVE', "3.1 Código de error LOCATION_ALREADY_EXISTS_ACTIVE");
    assertCondition($e->canReactivate() === false, "3.2 canReactivate es false para sede activa");
}
assertCondition($threwActive, "3.3 Colisión con sede activa lanza InactiveRecordCollisionException");

// 3.2 Dar de baja la sede y probar colisión con sede inactiva
$locRepo->softDelete($created->getId());

$threwInactive = false;
try {
    $service->createLocation([
        'site_code' => 'SEDE-VAL-01', // Ahora inactiva
        'name'      => 'Intento en Sede Inactiva',
        'address'   => 'Calle Inactiva 12',
    ], $coordinatorActor);
} catch (InactiveRecordCollisionException $e) {
    $threwInactive = true;
    assertCondition($e->getErrorCode() === 'LOCATION_ALREADY_EXISTS_INACTIVE', "3.4 Código de error LOCATION_ALREADY_EXISTS_INACTIVE");
    assertCondition($e->canReactivate() === true, "3.5 canReactivate es true para sugerir reactivación asistida");
    assertCondition($e->getEntityId() === $created->getId(), "3.6 entity_id señala la sede inactiva");
}
assertCondition($threwInactive, "3.7 Colisión con sede inactiva lanza InactiveRecordCollisionException");

// Reactivarla para siguientes tests
$locRepo->restore($created->getId());

// =====================================================================
// CASO 4: Actualización descriptiva y auditoría de cambios
// =====================================================================
echo "\n--- Caso 4: Actualización de sede (updateLocation) ---\n";

$updated = $service->updateLocation($created->getId(), [
    'name'          => 'Politécnico de Valencia - Campus Central',
    'contact_phone' => '633999888',
], $coordinatorActor);

assertCondition($updated->getName() === 'Politécnico de Valencia - Campus Central', "4.1 Nombre modificado con éxito");
assertCondition($updated->getContactPhone() === '633999888', "4.2 Teléfono modificado con éxito");
assertCondition($updated->getAddress() === 'Camino de Vera s/n, 46022 Valencia', "4.3 Dirección intacta");

// Verificar auditoría de actualización
$latestEvent = end($auditRepo->events);
assertCondition($latestEvent->getAction() === 'LOCATION_UPDATED', "4.4 Acción es LOCATION_UPDATED");
assertCondition(isset($latestEvent->getMetadata()['changed_fields']), "4.5 Metadatos contienen changed_fields");
$changed = $latestEvent->getMetadata()['changed_fields'];
assertCondition(in_array('name', $changed, true) && in_array('contact_phone', $changed, true), "4.6 changed_fields detecta 'name' y 'contact_phone'");

// =====================================================================
// CASO 5: Bloqueo de baja si tiene máquinas activas (EARS 1.4)
// =====================================================================
echo "\n--- Caso 5: Bloqueo de baja con máquinas activas (deactivateLocation) ---\n";

// Simular 3 máquinas activas vinculadas
$locRepo->activeMachinesCount[$created->getId()] = 3;

$threwMachines = false;
try {
    $service->deactivateLocation($created->getId(), $coordinatorActor);
} catch (ActiveMachinesBlockedException $e) {
    $threwMachines = true;
    assertCondition($e->getActiveMachinesCount() === 3, "5.1 Recuento de máquinas activas es 3");
    assertCondition($e->getErrorCode() === 'LOCATION_HAS_ACTIVE_MACHINES', "5.2 Código es LOCATION_HAS_ACTIVE_MACHINES");
    assertCondition($e->getHttpStatusCode() === 409, "5.3 HTTP 409 Conflict");
}
assertCondition($threwMachines, "5.4 Baja bloqueada lanza ActiveMachinesBlockedException");

// =====================================================================
// CASO 6: Baja lógica exitosa sin máquinas activas (EARS 1.5)
// =====================================================================
echo "\n--- Caso 6: Baja lógica exitosa (deactivateLocation) ---\n";

// Quitar máquinas
$locRepo->activeMachinesCount[$created->getId()] = 0;

$deactivatedOk = $service->deactivateLocation($created->getId(), $coordinatorActor);
assertCondition($deactivatedOk === true, "6.1 deactivateLocation() retorna true");

$latestDeactEvent = end($auditRepo->events);
assertCondition($latestDeactEvent->getAction() === 'LOCATION_DEACTIVATED', "6.2 Evento LOCATION_DEACTIVATED registrado");
assertCondition(($latestDeactEvent->getNewState()['is_active'] ?? null) === false, "6.3 new_state refleja is_active = false");

// Comprobar que ya no aparece como activa
assertCondition($locRepo->findById($created->getId(), allowDeleted: false) === null, "6.4 Sede ya no es visible activamente");

// =====================================================================
// CASO 7: Reactivación asistida (reactivateLocation)
// =====================================================================
echo "\n--- Caso 7: Reactivación asistida (reactivateLocation) ---\n";

$reactivated = $service->reactivateLocation($created->getId(), $coordinatorActor);
assertCondition($reactivated instanceof Location, "7.1 reactivateLocation() devuelve Location");
assertCondition($reactivated->isActive() === true, "7.2 Sede reactivada tiene is_active = true");

$latestReactEvent = end($auditRepo->events);
assertCondition($latestReactEvent->getAction() === 'LOCATION_REACTIVATED', "7.3 Evento LOCATION_REACTIVATED registrado");
assertCondition(($latestReactEvent->getNewState()['is_active'] ?? null) === true, "7.4 new_state refleja is_active = true");

// =====================================================================
// CASO 8: Sede inexistente lanza LocationNotFoundException
// =====================================================================
echo "\n--- Caso 8: Sede no encontrada (LocationNotFoundException) ---\n";

$threw404 = false;
try {
    $service->getLocation(99999);
} catch (LocationNotFoundException $e) {
    $threw404 = true;
    assertCondition($e->getHttpStatusCode() === 404, "8.1 Código HTTP 404");
    assertCondition($e->getErrorCode() === 'LOCATION_NOT_FOUND', "8.2 Error code LOCATION_NOT_FOUND");
}
assertCondition($threw404, "8.3 getLocation(inexistente) lanza LocationNotFoundException");

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " RESULTADO: 100% EN VERDE. ({$assertions} aserciones pasadas)\n";
echo " CONDICIÓN T-ADM-07 CUMPLIDA SATISFACTORIAMENTE.\n";
echo "======================================================================\n";
