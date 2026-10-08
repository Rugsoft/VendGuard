<?php

declare(strict_types=1);

/**
 * QrSanitaryQuarantineModeTest
 * 
 * Suite de pruebas unitarias para la verificación de los modos SANITARY_QUARANTINE
 * y SEASONAL_PAUSE en la resolución de escaneo QR y control de reportes (Tarea T-PREV-16).
 * 
 * Requisitos:
 * - RF-PREV-01, EARS 7.3, Artículos II y IV de la Constitución de VendGuard.
 * - Hecho cuando:
 *   1. GET /api/qr/scan/{code} sobre máquina en cuarentena sanitaria retorna HTTP 200 con
 *      status_mode = "SANITARY_QUARANTINE", alerta de peligro para la salud pública y compras/reportes bloqueados.
 *   2. GET /api/qr/scan/{code} sobre máquina en pausa estacional retorna HTTP 200 con
 *      status_mode = "SEASONAL_PAUSE" con aviso informativo y compras/reportes bloqueados.
 *   3. Blindaje estricto de privacidad (Art. IV): Cero notas de taller, técnicos asignados ni datos privados.
 *   4. POST /api/qr/report bloquea reportes ordinarios en máquinas en cuarentena o pausa estacional (HTTP 422).
 *   5. Compatibilidad total preservada con modos CAN_REPORT, ACTIVE_INCIDENT y UNDER_WARRANTY.
 * 
 * Dogma Vanilla: Cero dependencias externas (PHP 8.2+ puro).
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Application\Service\QrScanService;
use VendGuard\Presentation\Controller\QrScanController;
use VendGuard\Presentation\Http\Request;

echo "======================================================================\n";
echo " VendGuard: Verificación Unitaria de Modos QR Sanitarios (T-PREV-16)\n";
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
// Mocks en Memoria para Pruebas Unitarias
// -------------------------------------------------------------

class MockMachineRepository implements MachineRepositoryInterface
{
    /** @var array<string, Machine> */
    public array $machines = [];

    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return $this->machines[strtoupper(trim($code))] ?? null;
    }

    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        foreach ($this->machines as $m) {
            if ($m->getId() === $id) return $m;
        }
        return null;
    }

    public function findActiveByLocationId(int $locationId): array { return []; }
    public function create(array $data): Machine { throw new \BadMethodCallException(); }
    public function update(int $id, array $data): bool { return true; }
    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool { return true; }
    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool { return true; }
    public function softDelete(int $id): bool { return true; }
    public function countActiveByLocation(int $locationId): int { return 0; }
    public function findAll(array $filters = []): array { return array_values($this->machines); }
    public function hasActiveTicketOrWarranty(int $machineId): bool { return false; }
    public function getActiveTicketOrWarranty(int $machineId): ?array { return null; }
}

class MockLocationRepository implements LocationRepositoryInterface
{
    /** @var array<int, Location> */
    public array $locations = [];

    public function findById(int $id): ?Location
    {
        return $this->locations[$id] ?? null;
    }

    public function findBySiteCode(string $siteCode): ?Location
    {
        foreach ($this->locations as $l) {
            if ($l->getSiteCode() === $siteCode) return $l;
        }
        return null;
    }

    public function findAllActive(): array { return array_values($this->locations); }
    public function findAll(string $status = 'all', ?string $search = null): array { return []; }
    public function create(array $data): Location { throw new \BadMethodCallException(); }
    public function update(int $id, array $data): bool { return true; }
    public function softDelete(int $id): bool { return true; }
    public function restore(int $id): bool { return true; }
    public function updateContactPhone(int $id, string $contactPhone): bool { return true; }
    public function countActiveMachines(int $locationId): int { return 0; }
}

class MockIncidentRepository implements IncidentRepositoryInterface
{
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
    /** @var array<int, Incident> */
    public array $incidents = [];

    public function findActiveByMachineId(int $machineId): ?Incident
    {
        foreach ($this->incidents as $inc) {
            if ($inc->getMachineId() === $machineId && !$inc->getStatus()->isTerminal() && !$inc->getStatus()->isResolved()) {
                return $inc;
            }
        }
        return null;
    }

    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident
    {
        foreach ($this->incidents as $inc) {
            if ($inc->getMachineId() === $machineId && !$inc->getStatus()->isTerminal()) {
                return $inc;
            }
        }
        return null;
    }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident
    {
        $this->incidents[] = $incident;
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
    public function addComment(\VendGuard\Core\Domain\Model\IncidentComment $comment): \VendGuard\Core\Domain\Model\IncidentComment { return $comment; }
    public function getComments(int $incidentId, bool $includeInternal = true): array { return []; }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }
    public function countReopenEvents(int $incidentId): int { return 0; }
    public function hasActiveIncidentForMachine(int $machineId, ?int $excludeIncidentId = null): bool { return false; }
    public function updateStatus(int $id, IncidentStatus $status, ?int $userId = null, ?string $note = null): bool { return true; }
    public function markAsChronic(int $incidentId): bool { return true; }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null): Incident { return $this->incidents[0]; }
    public function reopen(int $incidentId, string $reasonText): Incident { return $this->incidents[0]; }
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident { return $this->incidents[0]; }
    public function startIntervention(int $incidentId, int $technicianId): Incident { return $this->incidents[0]; }
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident { return $this->incidents[0]; }
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident { return $this->incidents[0]; }
    public function autoCloseResolvedIncidents(int $hours = 48): array { return []; }
}

class MockPreventiveSettingsRepository implements PreventiveSettingsRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $machineSettings = [];

    public function findAll(): array { return []; }
    public function findByMachineType(string $machineType): ?PreventiveSetting { return null; }
    public function updateTypeSettings(string $machineType, int $defaultFrequencyDays, int $maxAllowedDays, int $advanceWarningDays = 5): bool { return true; }
    public function updateMachineConfig(int $machineId, ?int $sanitaryFrequencyDays, ?string $nextSanitaryInspectionDue = null): bool { return true; }
    public function setSeasonalPause(int $machineId, string $reason, ?string $pauseUntil = null): bool { return true; }
    public function resumeSeasonalPause(int $machineId): bool { return true; }
    public function updateSanitaryStatus(int $machineId, string $status): bool { return true; }

    public function getMachineSettings(int $machineId): ?array
    {
        return $this->machineSettings[$machineId] ?? null;
    }
}

// -------------------------------------------------------------
// Configuración de Datos de Prueba
// -------------------------------------------------------------

$machineRepo = new MockMachineRepository();
$locationRepo = new MockLocationRepository();
$incidentRepo = new MockIncidentRepository();
$settingsRepo = new MockPreventiveSettingsRepository();

$location = new Location(10, 'SEDE-HOSP-01', 'Hospital del Mar - Edificio Central', 'Passeig Marítim 25');
$locationRepo->locations[10] = $location;

// Máquina 1: Cuarentena Sanitaria
$machQuarantine = new Machine(
    14,
    10,
    'VEND-BCN-101',
    'Sanden Vendo G-Drink',
    MachineType::PERISHABLE_FOOD,
    'Planta Baja - Urgencias',
    null,
    true
);
$machineRepo->machines['VEND-BCN-101'] = $machQuarantine;
$settingsRepo->machineSettings[14] = [
    'machine_id' => 14,
    'machine_code' => 'VEND-BCN-101',
    'machine_type' => 'PERISHABLE_FOOD',
    'sanitary_status' => 'QUARANTINE',
    'is_seasonal_pause' => false,
    'seasonal_pause_reason' => null,
    'seasonal_pause_until' => null,
];

// Incidencia correctiva vinculada a la cuarentena (rotura de frío)
$incidentQuarantine = new Incident(
    91,
    'INC-2026-0091',
    14,
    10,
    IncidentCategory::TEMPERATURE_COLD,
    'Rotura térmica detectada en inspección preventiva. Temperatura 6.8 °C.',
    UrgencyLevel::CRITICAL,
    IncidentStatus::IN_PROGRESS,
    3, // ID técnico (CONFIDENCIAL Art. IV)
    'Sistema Preventivo',
    '000000000',
    null,
    null,
    '2026-09-27 10:00:00',
    '2026-09-27 10:05:00',
    '2026-09-27 10:10:00',
    null,
    'Compresor averiado (CONFIDENCIAL)',
    'En proceso de cambio (CONFIDENCIAL)'
);
$incidentRepo->incidents[] = $incidentQuarantine;

// Máquina 2: Pausa Estacional
$machPause = new Machine(
    15,
    10,
    'VEND-BCN-105',
    'Sanden Vendo G-Drink',
    MachineType::PERISHABLE_FOOD,
    'Colegio Mayor Universitario',
    null,
    true
);
$machineRepo->machines['VEND-BCN-105'] = $machPause;
$settingsRepo->machineSettings[15] = [
    'machine_id' => 15,
    'machine_code' => 'VEND-BCN-105',
    'machine_type' => 'PERISHABLE_FOOD',
    'sanitary_status' => 'SEASONAL_PAUSE',
    'is_seasonal_pause' => true,
    'seasonal_pause_reason' => 'Cierre vacacional por periodo estival',
    'seasonal_pause_until' => '2026-09-30',
];

// Máquina 3: Limpia (CAN_REPORT)
$machClean = new Machine(
    16,
    10,
    'VEND-BCN-102',
    'Necta Krea Touch',
    MachineType::HOT_DRINKS,
    'Planta 1 - Sala Médica',
    null,
    true
);
$machineRepo->machines['VEND-BCN-102'] = $machClean;
$settingsRepo->machineSettings[16] = [
    'machine_id' => 16,
    'machine_code' => 'VEND-BCN-102',
    'machine_type' => 'HOT_DRINKS',
    'sanitary_status' => 'OK',
    'is_seasonal_pause' => false,
    'seasonal_pause_reason' => null,
    'seasonal_pause_until' => null,
];

// Instanciar Servicio y Controlador
$qrScanService = new QrScanService($machineRepo, $locationRepo, $incidentRepo, $settingsRepo);
$qrController = new QrScanController($qrScanService, null, null, $machineRepo, $settingsRepo);

// =========================================================================
// BLOQUE 1: Modo SANITARY_QUARANTINE (GET /api/qr/scan/{code})
// =========================================================================
echo "--- Bloque 1: Modo SANITARY_QUARANTINE ---\n";

$reqQuarantine = new Request('GET', '/api/qr/scan/VEND-BCN-101');
$reqQuarantine->setRouteParams(['code' => 'VEND-BCN-101']);
$respQuarantine = $qrController->scan($reqQuarantine);

assertCondition($respQuarantine->getStatusCode() === 200, "1.1 HTTP 200 retornado en modo SANITARY_QUARANTINE");
$dataQuarantine = json_decode($respQuarantine->getBody(), true);
assertCondition(($dataQuarantine['success'] ?? false) === true, "1.2 Envelope success = true");

$payloadQ = $dataQuarantine['data'] ?? [];
assertCondition(($payloadQ['status_mode'] ?? '') === 'SANITARY_QUARANTINE', "1.3 status_mode es SANITARY_QUARANTINE");
assertCondition(($payloadQ['can_report'] ?? true) === false, "1.4 can_report es estrictamente false");

// Alerta de peligro higiénico-sanitario
$alert = $payloadQ['alert'] ?? [];
assertCondition(($alert['severity'] ?? '') === 'CRITICAL_DANGER', "1.5 Alerta con gravedad CRITICAL_DANGER");
assertCondition(
    str_contains($alert['title'] ?? '', 'MÁQUINA FUERA DE SERVICIO POR CONTROL HIGIÉNICO-SANITARIO'),
    "1.6 Título de alerta sanitario correcto"
);
assertCondition(
    str_contains($alert['message'] ?? '', 'Artículo II de la Constitución (Seguridad Alimentaria)'),
    "1.7 Mensaje cita el imperativo del Artículo II de la Constitución"
);

// Datos de la máquina y sede
assertCondition(($payloadQ['machine']['code'] ?? '') === 'VEND-BCN-101', "1.8 Código de máquina coincide");
assertCondition(($payloadQ['machine']['is_perishable'] ?? false) === true, "1.9 is_perishable es true");
assertCondition(($payloadQ['location']['name'] ?? '') === 'Hospital del Mar - Edificio Central', "1.10 Nombre de sede resuelto");

// Datos de la incidencia correctiva activa asociada
$activeIncData = $payloadQ['active_incident'] ?? [];
assertCondition(($activeIncData['ticket_code'] ?? '') === 'INC-2026-0091', "1.11 Ticket code de correctivo asociado presente");
assertCondition(($activeIncData['status_label'] ?? '') === 'Intervención técnica prioritaria en curso', "1.12 status_label tranquilizador presente");

// Blindaje de Privacidad Art. IV en SANITARY_QUARANTINE
assertCondition(!isset($activeIncData['assigned_technician_id']), "1.13 Art. IV: assigned_technician_id NO revelado");
assertCondition(!isset($activeIncData['assigned_technician']), "1.14 Art. IV: assigned_technician NO revelado");
assertCondition(!isset($activeIncData['technician_name']), "1.15 Art. IV: technician_name NO revelado");
assertCondition(!isset($activeIncData['reporter_name']), "1.16 Art. IV: reporter_name NO revelado");
assertCondition(!isset($activeIncData['reporter_phone']), "1.17 Art. IV: reporter_phone NO revelado");
assertCondition(!isset($activeIncData['resolution_diagnosis']), "1.18 Art. IV: resolution_diagnosis NO revelado");
assertCondition(!isset($activeIncData['internal_notes']), "1.19 Art. IV: internal_notes NO revelado");

// =========================================================================
// BLOQUE 2: Modo SEASONAL_PAUSE (GET /api/qr/scan/{code})
// =========================================================================
echo "\n--- Bloque 2: Modo SEASONAL_PAUSE ---\n";

$reqPause = new Request('GET', '/api/qr/scan/VEND-BCN-105');
$reqPause->setRouteParams(['code' => 'VEND-BCN-105']);
$respPause = $qrController->scan($reqPause);

assertCondition($respPause->getStatusCode() === 200, "2.1 HTTP 200 retornado en modo SEASONAL_PAUSE");
$dataPause = json_decode($respPause->getBody(), true);
$payloadP = $dataPause['data'] ?? [];

assertCondition(($payloadP['status_mode'] ?? '') === 'SEASONAL_PAUSE', "2.2 status_mode es SEASONAL_PAUSE");
assertCondition(($payloadP['can_report'] ?? true) === false, "2.3 can_report es false");

// Alerta informativa vacacional
$alertP = $payloadP['alert'] ?? [];
assertCondition(($alertP['severity'] ?? '') === 'INFO', "2.4 Severidad es INFO");
assertCondition(
    str_contains($alertP['title'] ?? '', 'DISPOSITIVO EN PAUSA ESTACIONAL PROGRAMADA'),
    "2.5 Título de pausa estacional correcto"
);
assertCondition(
    str_contains($alertP['message'] ?? '', 'periodo vacacional') && str_contains($alertP['message'] ?? '', 'revisión sanitaria previa'),
    "2.6 Mensaje informativo vacacional y revisión sanitaria previa presente"
);
assertCondition(($payloadP['machine']['code'] ?? '') === 'VEND-BCN-105', "2.7 Código de máquina coincide");
assertCondition(!isset($payloadP['active_incident']), "2.8 En pausa estacional active_incident no se incluye");

// =========================================================================
// BLOQUE 3: Bloqueo de Reporte en Quarentena y Pausa (POST /api/qr/report)
// =========================================================================
echo "\n--- Bloque 3: Bloqueo de POST /api/qr/report ---\n";

// 3.1 Intento de reporte sobre máquina en cuarentena
$reqReportQ = new Request(
    'POST',
    '/api/qr/report',
    [],
    [
        'machine_code' => 'VEND-BCN-101',
        'category' => 'PRODUCT_JAM',
        'description' => 'Intento de reporte ordinario sobre máquina en cuarentena.',
    ]
);
$respReportQ = $qrController->report($reqReportQ);
assertCondition($respReportQ->getStatusCode() === 422, "3.1 Reporte en máquina en cuarentena retorna HTTP 422");
$errQ = json_decode($respReportQ->getBody(), true);
assertCondition(($errQ['error']['code'] ?? '') === 'MACHINE_IN_QUARANTINE', "3.2 Código de error es MACHINE_IN_QUARANTINE");

// 3.2 Intento de reporte sobre máquina en pausa estacional
$reqReportP = new Request(
    'POST',
    '/api/qr/report',
    [],
    [
        'machine_code' => 'VEND-BCN-105',
        'category' => 'COIN_ACCEPTOR',
        'description' => 'Intento de reporte sobre máquina en pausa vacacional.',
    ]
);
$respReportP = $qrController->report($reqReportP);
assertCondition($respReportP->getStatusCode() === 422, "3.3 Reporte en máquina en pausa estacional retorna HTTP 422");
$errP = json_decode($respReportP->getBody(), true);
assertCondition(($errP['error']['code'] ?? '') === 'MACHINE_IN_SEASONAL_PAUSE', "3.4 Código de error es MACHINE_IN_SEASONAL_PAUSE");

// =========================================================================
// BLOQUE 4: Compatibilidad con Modos Preexistentes
// =========================================================================
echo "\n--- Bloque 4: Compatibilidad con Modos Preexistentes ---\n";

// 4.1 Máquina limpia (CAN_REPORT)
$reqClean = new Request('GET', '/api/qr/scan/VEND-BCN-102');
$reqClean->setRouteParams(['code' => 'VEND-BCN-102']);
$respClean = $qrController->scan($reqClean);
assertCondition($respClean->getStatusCode() === 200, "4.1 HTTP 200 en máquina limpia");
$dataClean = json_decode($respClean->getBody(), true);
assertCondition(($dataClean['data']['status_mode'] ?? '') === 'CAN_REPORT', "4.2 status_mode es CAN_REPORT");
assertCondition(($dataClean['data']['can_report'] ?? false) === true, "4.3 can_report es true en máquina limpia");
assertCondition(array_key_exists('active_incident', $dataClean['data'] ?? []) && $dataClean['data']['active_incident'] === null, "4.4 active_incident es null");

echo "\n======================================================================\n";
echo " RESULTADO: {$assertions} aserciones pasadas con éxito. T-PREV-16 CUMPLIDA.\n";
echo "======================================================================\n";
