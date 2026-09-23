<?php

declare(strict_types=1);

/**
 * QrScanServiceTest
 * 
 * Suite de pruebas unitarias para el servicio de resolución de escaneo QR (T-QR-07).
 * Valida de forma rigurosa el Blindaje Constitucional de Privacidad (Art. V.4),
 * la clasificación de estados (CAN_REPORT, ACTIVE_INCIDENT, UNDER_WARRANTY),
 * la resolución transparente ante reubicaciones de máquinas (EARS 5.1) y el control
 * de anomalías y máquinas inactivas (EARS 5.2).
 * 
 * Cumple con Dogma Vanilla y los Artículos II, IV y V de la Constitución de VendGuard.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Core\Service\QrScanService;

echo "======================================================================\n";
echo " VendGuard: Verificación Unitaria de QrScanService (T-QR-07)\n";
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

// -------------------------------------------------------------
// Repositorios Mock en Memoria (Dogma Vanilla)
// -------------------------------------------------------------

class InMemoryMachineRepository implements MachineRepositoryInterface
{
    /** @var array<string, Machine> */
    private array $machines = [];

    public function add(Machine $machine): void
    {
        $this->machines[$machine->getCode()] = $machine;
    }

    public function findByCode(string $code): ?Machine
    {
        return $this->machines[strtoupper(trim($code))] ?? null;
    }

    public function findById(int $id): ?Machine
    {
        foreach ($this->machines as $machine) {
            if ($machine->getId() === $id) {
                return $machine;
            }
        }
        return null;
    }

    public function findActiveByLocationId(int $locationId): array
    {
        return array_values(array_filter($this->machines, fn(Machine $m) => $m->getLocationId() === $locationId && $m->isActive()));
    }

    public function softDelete(int $id): bool
    {
        return true;
    }
}

class InMemoryLocationRepository implements LocationRepositoryInterface
{
    /** @var array<int, Location> */
    private array $locations = [];

    public function add(Location $location): void
    {
        $this->locations[$location->getId()] = $location;
    }

    public function findById(int $id): ?Location
    {
        return $this->locations[$id] ?? null;
    }

    public function findBySiteCode(string $siteCode): ?Location
    {
        foreach ($this->locations as $loc) {
            if ($loc->getSiteCode() === strtoupper(trim($siteCode))) {
                return $loc;
            }
        }
        return null;
    }

    public function findAllActive(): array
    {
        return array_values(array_filter($this->locations, fn(Location $l) => $l->isActive()));
    }

    public function softDelete(int $id): bool
    {
        return true;
    }

    public function updateContactPhone(int $id, string $contactPhone): bool
    {
        if (isset($this->locations[$id])) {
            $loc = $this->locations[$id];
            $this->locations[$id] = new Location(
                $loc->getId(),
                $loc->getSiteCode(),
                $loc->getName(),
                $loc->getAddress(),
                $loc->getContactName(),
                $contactPhone,
                $loc->isActive()
            );
        }
        return true;
    }
}

class InMemoryIncidentRepository implements IncidentRepositoryInterface
{
    public ?Incident $activeIncident = null;
    public ?Incident $resolvedIncident = null;

    public function findActiveByMachineId(int $machineId): ?Incident
    {
        return $this->activeIncident;
    }

    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident
    {
        return $this->resolvedIncident ?? $this->activeIncident;
    }

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident
    {
        return $incident;
    }

    public function findById(int $id): ?Incident { return null; }
    public function findByTicketCode(string $ticketCode): ?Incident { return null; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { return []; }
    public function update(Incident $incident): bool { return true; }
    public function softDelete(int $id): bool { return true; }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return 1; }
    public function getHistory(int $incidentId): array { return []; }
    public function addComment(IncidentComment $comment): IncidentComment { return $comment; }
    public function getComments(int $incidentId, bool $includeInternal = true): array { return []; }
    public function countReopenEvents(int $incidentId): int { return 0; }
    public function hasActiveIncidentForMachine(int $machineId, ?int $excludeIncidentId = null): bool { return $this->activeIncident !== null; }
    public function updateStatus(int $id, IncidentStatus $status, ?int $userId = null, ?string $note = null): bool { return true; }
    public function markAsChronic(int $incidentId): bool { return true; }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null): Incident { return $this->activeIncident; }
    public function reopen(int $incidentId, string $reasonText): Incident { return $this->activeIncident; }
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident { return $this->activeIncident; }
    public function startIntervention(int $incidentId, int $technicianId): Incident { return $this->activeIncident; }
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident { return $this->activeIncident; }
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident { return $this->activeIncident; }
    public function autoCloseResolvedIncidents(int $hours = 48): array { return []; }
}

try {
    // Inicialización del entorno de pruebas
    $machineRepo = new InMemoryMachineRepository();
    $locationRepo = new InMemoryLocationRepository();
    $incidentRepo = new InMemoryIncidentRepository();

    // Sede Central
    $location1 = new Location(1, 'SEDE-BCN-01', 'Hospital del Mar - Edificio Central', 'Paseo Marítimo 25', 'Carlos Coordinador', '600111222');
    $locationRepo->add($location1);

    // Sede Secundaria
    $location2 = new Location(2, 'SEDE-MAD-01', 'Campus Chamartín', 'Calle Mateo Inurria 12', 'Marta Coordinadora', '611222333');
    $locationRepo->add($location2);

    // Máquina perecedera
    $mPerishable = new Machine(1, 1, 'VEND-0101', 'Sanden Vendo G-Drink', MachineType::PERISHABLE_FOOD, 'Planta Baja - Urgencias', 'Máquina de sándwiches');
    $machineRepo->add($mPerishable);

    // Máquina estándar no perecedera
    $mHotDrinks = new Machine(2, 1, 'VEND-0102', 'Necta Canto Touch', MachineType::HOT_DRINKS, 'Planta 1 - Sala Espera');
    $machineRepo->add($mHotDrinks);

    // Máquina inactiva
    $mInactive = new Machine(3, 1, 'VEND-INACTIVE', 'BVM 972', MachineType::HOT_DRINKS, 'Almacén', null, false);
    $machineRepo->add($mInactive);

    // Máquina soft-deleted
    $mDeleted = new Machine(4, 1, 'VEND-DELETED', 'FAS Perla', MachineType::SNACKS, 'Taller', null, true, null, null, '2026-09-01 00:00:00');
    $machineRepo->add($mDeleted);

    $service = new QrScanService($machineRepo, $locationRepo, $incidentRepo);

    // -------------------------------------------------------------
    // 1. Caso A: Máquina limpia sin avisos (CAN_REPORT)
    // -------------------------------------------------------------
    echo "--- 1. Modo CAN_REPORT (Máquina Limpia) ---\n";

    // 1.1 Perecedera: detecta is_perishable = true
    $resCleanPerishable = $service->resolve('VEND-0101');
    assertCondition($resCleanPerishable['status_mode'] === 'CAN_REPORT', "1.1 status_mode es 'CAN_REPORT'");
    assertCondition($resCleanPerishable['machine']['is_perishable'] === true, "1.2 Máquina perecedera reporta is_perishable=true (Art. II)");
    assertCondition($resCleanPerishable['active_incident'] === null, "1.3 active_incident es estrictamente null");
    assertCondition($resCleanPerishable['location']['site_code'] === 'SEDE-BCN-01', "1.4 Sede resuelta correctamente");

    // 1.2 No perecedera: detecta is_perishable = false
    $resCleanDrinks = $service->resolve('VEND-0102');
    assertCondition($resCleanDrinks['status_mode'] === 'CAN_REPORT', "1.5 status_mode es 'CAN_REPORT' en máquina de bebidas");
    assertCondition($resCleanDrinks['machine']['is_perishable'] === false, "1.6 Máquina de bebidas reporta is_perishable=false");

    // Normalización de código en minúsculas
    $resLower = $service->resolve('vend-0101');
    assertCondition($resLower['machine']['code'] === 'VEND-0101', "1.7 Código recibido en minúsculas se normaliza a mayúsculas");

    // -------------------------------------------------------------
    // 2. Caso B: Blindaje Constitucional de Privacidad (Art. V.4)
    // -------------------------------------------------------------
    echo "\n--- 2. Modo ACTIVE_INCIDENT y Blindaje de Privacidad (Art. V.4) ---\n";

    // Simulamos un ticket con multitud de datos técnicos confidenciales
    $confidentialIncident = new Incident(
        100,
        'TICK-2026-7788',
        1,
        1,
        IncidentCategory::TEMPERATURE_COLD,
        'Compresor congelado con pérdida de gas refrigerante',
        UrgencyLevel::CRITICAL,
        IncidentStatus::IN_PROGRESS,
        88, // assignedTechnicianId (CONFIDENCIAL)
        'Dra. Ana López (Informadora)',
        '699112233',
        null,
        '/uploads/compresor.jpg',
        '2026-09-23 07:45:00',
        '2026-09-23 08:00:00',
        null,
        'Fuga en evaporador secundario',
        'Sustitución de válvula y recarga R134a',
        null,
        null,
        null,
        null,
        null,
        null,
        1,
        '2026-09-23 07:15:00',
        '2026-09-23 08:05:00',
        null,
        'VEND-0101',
        'Sanden Vendo G-Drink',
        'PERISHABLE_FOOD',
        'Hospital del Mar - Edificio Central',
        'SEDE-BCN-01',
        'Roberto Técnico (Datos Privados Taller)'
    );

    $incidentRepo->activeIncident = $confidentialIncident;

    $resActive = $service->resolve('VEND-0101');

    assertCondition($resActive['status_mode'] === 'ACTIVE_INCIDENT', "2.1 status_mode es 'ACTIVE_INCIDENT'");
    assertCondition(isset($resActive['active_incident']), "2.2 active_incident está presente en la respuesta");

    $payloadIncident = $resActive['active_incident'];

    // Verificación estricta del Art. V.4: Los campos privados JAMÁS deben figurar
    assertCondition(!array_key_exists('assigned_technician', $payloadIncident), "2.3 Art. V.4: assigned_technician NO existe en el payload");
    assertCondition(!array_key_exists('assigned_technician_id', $payloadIncident), "2.4 Art. V.4: assigned_technician_id NO existe en el payload");
    assertCondition(!array_key_exists('technician_name', $payloadIncident), "2.5 Art. V.4: technician_name NO existe en el payload");
    assertCondition(!array_key_exists('reporter_name', $payloadIncident), "2.6 Art. V.4: reporter_name de terceros NO existe en el payload");
    assertCondition(!array_key_exists('reporter_phone', $payloadIncident), "2.7 Art. V.4: reporter_phone de terceros NO existe en el payload");
    assertCondition(!array_key_exists('internal_notes', $payloadIncident), "2.8 Art. V.4: internal_notes NO existe en el payload");
    assertCondition(!array_key_exists('resolution_diagnosis', $payloadIncident), "2.9 Art. V.4: resolution_diagnosis NO existe en el payload");
    assertCondition(!array_key_exists('repair_cost', $payloadIncident), "2.10 Art. V.4: repair_cost NO existe en el payload");

    // Verificación de los únicos 5 campos públicos permitidos por el contrato
    assertCondition($payloadIncident['ticket_code'] === 'TICK-2026-7788', "2.11 Contiene ticket_code público");
    assertCondition($payloadIncident['category'] === 'TEMPERATURE_COLD', "2.12 Contiene category pública");
    assertCondition($payloadIncident['public_status'] === 'IN_PROGRESS', "2.13 Contiene public_status");
    assertCondition($payloadIncident['reported_at'] === '2026-09-23 07:15:00', "2.14 Contiene reported_at");
    assertCondition(
        $payloadIncident['status_label'] === 'Técnico interviniendo en la máquina',
        "2.15 Contiene status_label explicativo y tranquilizador"
    );

    // -------------------------------------------------------------
    // 3. Caso C: Ventana Legal de Garantía de 48h (UNDER_WARRANTY)
    // -------------------------------------------------------------
    echo "\n--- 3. Modo UNDER_WARRANTY (Garantía de 48h - EARS 4.3) ---\n";

    $incidentRepo->activeIncident = null;

    // Avería resuelta hace 2 horas (dentro de garantía de 48h)
    $resolvedInWarranty = new Incident(
        101,
        'TICK-2026-5500',
        1,
        1,
        IncidentCategory::PRODUCT_JAM,
        'Atasco de bandeja',
        UrgencyLevel::LOW,
        IncidentStatus::RESOLVED,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        'Guía lubricada',
        'Ajuste motor',
        date('Y-m-d H:i:s', time() - 7200) // resuelta hace 2 horas
    );

    $incidentRepo->resolvedIncident = $resolvedInWarranty;

    $resWarranty = $service->resolve('VEND-0101');
    assertCondition($resWarranty['status_mode'] === 'UNDER_WARRANTY', "3.1 status_mode es 'UNDER_WARRANTY' si está resuelta hace < 48h");
    assertCondition(isset($resWarranty['resolved_incident']), "3.2 resolved_incident está presente");
    assertCondition($resWarranty['resolved_incident']['ticket_code'] === 'TICK-2026-5500', "3.3 Código de ticket resuelto correcto");
    assertCondition(!empty($resWarranty['resolved_incident']['warranty_expires_at']), "3.4 warranty_expires_at calculada correctamente");

    // -------------------------------------------------------------
    // 4. Caso D: Vencimiento de Garantía (> 48h - EARS 4.4)
    // -------------------------------------------------------------
    echo "\n--- 4. Vencimiento de Garantía (> 48h - EARS 4.4) ---\n";

    // Avería resuelta hace 50 horas (garantía vencida)
    $resolvedExpired = new Incident(
        102,
        'TICK-2026-4000',
        1,
        1,
        IncidentCategory::PAYMENT_SYSTEM,
        'Moneda atascada',
        UrgencyLevel::LOW,
        IncidentStatus::RESOLVED,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        'Monedero calibrado',
        'Cambio sensor óptico',
        date('Y-m-d H:i:s', time() - (50 * 3600)) // resuelta hace 50 horas
    );

    $incidentRepo->resolvedIncident = $resolvedExpired;

    $resExpiredWarranty = $service->resolve('VEND-0101');
    assertCondition(
        $resExpiredWarranty['status_mode'] === 'CAN_REPORT',
        "4.1 Máquina resuelta hace > 48h se considera limpia y pasa a CAN_REPORT"
    );

    // -------------------------------------------------------------
    // 5. Caso E: Historial Pasado Terminal (CLOSED / CANCELLED)
    // -------------------------------------------------------------
    echo "\n--- 5. Historial Pasado Terminal (CLOSED / CANCELLED) ---\n";

    $closedIncident = new Incident(
        103,
        'TICK-2026-1000',
        1,
        1,
        IncidentCategory::ELECTRICAL_OFF,
        'Diferencial saltado',
        UrgencyLevel::HIGH,
        IncidentStatus::CLOSED
    );
    $incidentRepo->resolvedIncident = $closedIncident;
    $incidentRepo->activeIncident = null;

    $resClosed = $service->resolve('VEND-0101');
    assertCondition($resClosed['status_mode'] === 'CAN_REPORT', "5.1 Máquina con ticket CLOSED previo está limpia para nuevo reporte");

    $cancelledIncident = new Incident(
        104,
        'TICK-2026-1001',
        1,
        1,
        IncidentCategory::OTHER,
        'Aviso erróneo',
        UrgencyLevel::LOW,
        IncidentStatus::CANCELLED
    );
    $incidentRepo->resolvedIncident = $cancelledIncident;

    $resCancelled = $service->resolve('VEND-0101');
    assertCondition($resCancelled['status_mode'] === 'CAN_REPORT', "5.2 Máquina con ticket CANCELLED previo está limpia para nuevo reporte");

    // Limpiar incidencias mock
    $incidentRepo->resolvedIncident = null;
    $incidentRepo->activeIncident = null;

    // -------------------------------------------------------------
    // 6. Caso F: Resolución Transparente de Reubicación (EARS 5.1)
    // -------------------------------------------------------------
    echo "\n--- 6. Reubicación Transparente de Máquinas (EARS 5.1) ---\n";

    // La máquina VEND-0101 pertenece a SEDE-BCN-01, pero el usuario escaneó una pegatina antigua con SEDE-MAD-01
    $resRelocated = $service->resolve('VEND-0101', 'SEDE-MAD-01');
    assertCondition(
        $resRelocated['location']['site_code'] === 'SEDE-BCN-01',
        "6.1 Resuelve la sede real vigente (SEDE-BCN-01) ignorando el código antiguo de la pegatina"
    );

    // -------------------------------------------------------------
    // 7. Caso G: Control de Excepciones y Máquinas Inactivas (EARS 5.2)
    // -------------------------------------------------------------
    echo "\n--- 7. Máquinas No Encontradas o Inactivas (EARS 5.2) ---\n";

    // 7.1 Máquina inexistente
    $caughtNotFound = false;
    try {
        $service->resolve('VEND-INEXISTENTE-999');
    } catch (MachineNotFoundException $e) {
        $caughtNotFound = true;
        assertCondition($e->getErrorCode() === 'MACHINE_NOT_FOUND_OR_INACTIVE', "7.1 Código de error coincide con el contrato");
        assertCondition($e->getCode() === 404, "7.2 Código HTTP es 404 Not Found");
    }
    assertCondition($caughtNotFound, "7.3 Máquina inexistente lanza MachineNotFoundException");

    // 7.2 Máquina inactiva (is_active = false)
    $caughtInactive = false;
    try {
        $service->resolve('VEND-INACTIVE');
    } catch (MachineNotFoundException $e) {
        $caughtInactive = true;
    }
    assertCondition($caughtInactive, "7.4 Máquina inactiva lanza MachineNotFoundException");

    // 7.3 Máquina borrada lógicamente (soft deleted)
    $caughtDeleted = false;
    try {
        $service->resolve('VEND-DELETED');
    } catch (MachineNotFoundException $e) {
        $caughtDeleted = true;
    }
    assertCondition($caughtDeleted, "7.5 Máquina con borrado lógico lanza MachineNotFoundException");

    // 7.4 Código de máquina vacío
    $caughtEmpty = false;
    try {
        $service->resolve('   ');
    } catch (MachineNotFoundException $e) {
        $caughtEmpty = true;
    }
    assertCondition($caughtEmpty, "7.6 Código de máquina vacío lanza MachineNotFoundException");

    echo "\n======================================================================\n";
    echo " RESULTADO: {$assertions} aserciones pasadas con éxito. CONDICIÓN T-QR-07 CUMPLIDA.\n";
    echo "======================================================================\n";

} catch (Throwable $t) {
    echo "\n[ERROR INESPERADO]: " . $t->getMessage() . "\n";
    echo $t->getTraceAsString() . "\n";
    exit(1);
}
