<?php

declare(strict_types=1);

/**
 * PreventiveOrderSchedulerServiceTest
 *
 * Suite de pruebas unitarias para PreventiveOrderSchedulerService (T-PREV-09).
 * Valida:
 * 1. Generación anticipada a <= 5 días del vencimiento (EARS 2.1).
 * 2. Omisión de máquinas en Pausa Estacional documentada (EARS 1.4).
 * 3. Omisión de máquinas con orden activa previa para evitar duplicados.
 * 4. Inicialización del ciclo preventivo en altas de máquinas (RF-PREV-01, EARS 1.1).
 * 5. Blindaje del tope de 15 días en inicialización de perecederos (Art. II).
 * 6. Recálculo de vigencias tras inspección completada (RF-PREV-04).
 * 7. Expiración de órdenes vencidas (EARS 2.4).
 * 8. Traslado de sede: cancelación puramente lógica previa (Art. III) y nueva orden en destino (EARS 2.5).
 *
 * Dogma Vanilla: PHP 8.2+ puro sin Composer ni dependencias externas.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\PreventiveOrderSchedulerService;
use VendGuard\Core\Domain\Exception\PerishableFrequencyLimitException;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;

echo "============================================================================\n";
echo " VendGuard: Pruebas Unitarias de PreventiveOrderSchedulerService (T-PREV-09)\n";
echo "============================================================================\n\n";

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
 * Doble de prueba en memoria para PreventiveOrderRepositoryInterface
 */
class InMemoryPreventiveOrderRepository implements PreventiveOrderRepositoryInterface
{
    /** @var array<int, PreventiveOrder> */
    public array $orders = [];
    private int $nextId = 1;

    public function create(array $data): PreventiveOrder
    {
        $id = $this->nextId++;
        $code = sprintf('PREV-%s-%04d', date('Y'), $id);

        $order = new PreventiveOrder(
            $id,
            $code,
            (int)$data['machine_id'],
            (int)$data['location_id'],
            $data['assigned_technician_id'] ?? null,
            $data['status'] ?? 'PENDING_ASSIGNMENT',
            $data['order_type'] ?? 'ROUTINE',
            $data['scheduled_date'] ?? date('Y-m-d'),
            $data['due_date'] ?? date('Y-m-d'),
            null,
            null,
            null,
            null,
            null,
            false,
            $data['notes'] ?? null,
            null,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            null
        );

        $this->orders[$id] = $order;
        return $order;
    }

    public function findById(int $id, bool $allowCancelled = true): ?PreventiveOrder
    {
        return $this->orders[$id] ?? null;
    }

    public function findByCode(string $orderCode, bool $allowCancelled = true): ?PreventiveOrder
    {
        foreach ($this->orders as $o) {
            if ($o->getOrderCode() === $orderCode) {
                return $o;
            }
        }
        return null;
    }

    public function hasActiveOrPendingOrder(int $machineId): bool
    {
        foreach ($this->orders as $o) {
            if ($o->getMachineId() === $machineId && $o->getDeletedAt() === null) {
                if (in_array($o->getStatus(), ['PENDING_ASSIGNMENT', 'SCHEDULED', 'IN_INSPECTION'], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function assignTechnician(int $orderId, int $technicianId, ?string $scheduledDate = null): bool
    {
        return true;
    }

    public function claimOrderOpportunistically(int $orderId, int $technicianId): bool
    {
        return true;
    }

    public function startInspection(int $orderId, int $technicianId): bool
    {
        return true;
    }

    public function completeOrder(
        int $orderId,
        string $result,
        ?float $temperatureMeasured,
        bool $isQuarantineTriggered,
        ?int $linkedIncidentId = null,
        ?string $notes = null
    ): bool {
        return true;
    }

    public function linkIncident(int $orderId, int $incidentId): bool
    {
        return true;
    }

    public function softCancel(int $orderId, string $reason): bool
    {
        if (!isset($this->orders[$orderId])) {
            return false;
        }

        $o = $this->orders[$orderId];
        $this->orders[$orderId] = new PreventiveOrder(
            $o->getId(),
            $o->getOrderCode(),
            $o->getMachineId(),
            $o->getLocationId(),
            $o->getAssignedTechnicianId(),
            'CANCELLED',
            $o->getOrderType(),
            $o->getScheduledDate(),
            $o->getDueDate(),
            $o->getStartedAt(),
            $o->getCompletedAt(),
            $o->getTemperatureMeasured(),
            $o->getResult(),
            $o->getLinkedIncidentId(),
            $o->isQuarantineTriggered(),
            $o->getNotes(),
            $reason,
            $o->getCreatedAt(),
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s')
        );

        return true;
    }

    public function findForCoordinatorList(array $filters = []): array
    {
        $res = [];
        foreach ($this->orders as $o) {
            if (isset($filters['machine_id']) && $o->getMachineId() !== (int)$filters['machine_id']) {
                continue;
            }
            $res[] = $o;
        }
        return $res;
    }

    public function countForCoordinatorList(array $filters = []): int
    {
        return count($this->findForCoordinatorList($filters));
    }

    public function findForTechnicianRoute(int $technicianId, ?int $locationId = null): array
    {
        return [];
    }

    public function getDashboardSummary(): array
    {
        return [];
    }

    public function expireOverdueOrders(): int
    {
        $today = date('Y-m-d');
        $count = 0;
        foreach ($this->orders as $id => $o) {
            if (in_array($o->getStatus(), ['PENDING_ASSIGNMENT', 'SCHEDULED'], true) && $o->getDueDate() < $today && $o->getDeletedAt() === null) {
                $this->orders[$id] = new PreventiveOrder(
                    $o->getId(),
                    $o->getOrderCode(),
                    $o->getMachineId(),
                    $o->getLocationId(),
                    $o->getAssignedTechnicianId(),
                    'EXPIRED',
                    $o->getOrderType(),
                    $o->getScheduledDate(),
                    $o->getDueDate(),
                    $o->getStartedAt(),
                    $o->getCompletedAt(),
                    $o->getTemperatureMeasured(),
                    $o->getResult(),
                    $o->getLinkedIncidentId(),
                    $o->isQuarantineTriggered(),
                    $o->getNotes(),
                    $o->getCancellationReason(),
                    $o->getCreatedAt(),
                    date('Y-m-d H:i:s'),
                    $o->getDeletedAt()
                );
                $count++;
            }
        }
        return $count;
    }
}

/**
 * Doble de prueba en memoria para PreventiveSettingsRepositoryInterface
 */
class InMemorySchedulerSettingsRepository implements PreventiveSettingsRepositoryInterface
{
    /** @var array<string, PreventiveSetting> */
    public array $settings = [];

    /** @var array<int, array<string, mixed>> */
    public array $machines = [];

    public function __construct()
    {
        $this->settings['PERISHABLE_FOOD'] = new PreventiveSetting(1, 'PERISHABLE_FOOD', 15, 15, 5);
        $this->settings['HOT_DRINKS'] = new PreventiveSetting(2, 'HOT_DRINKS', 30, 60, 5);
        $this->settings['COLD_DRINKS'] = new PreventiveSetting(3, 'COLD_DRINKS', 45, 90, 5);
        $this->settings['SNACKS'] = new PreventiveSetting(4, 'SNACKS', 60, 90, 5);
        $this->settings['COMBO'] = new PreventiveSetting(5, 'COMBO', 15, 45, 5);
    }

    public function findAll(): array
    {
        return array_values($this->settings);
    }

    public function findByMachineType(string $machineType): ?PreventiveSetting
    {
        return $this->settings[$machineType] ?? null;
    }

    public function updateTypeSettings(
        string $machineType,
        int $defaultFrequencyDays,
        int $maxAllowedDays,
        int $advanceWarningDays = 5
    ): bool {
        return true;
    }

    public function updateMachineConfig(
        int $machineId,
        ?int $sanitaryFrequencyDays,
        ?string $nextSanitaryInspectionDue = null
    ): bool {
        if (!isset($this->machines[$machineId])) {
            $this->machines[$machineId] = [
                'machine_id' => $machineId,
                'machine_type' => 'PERISHABLE_FOOD',
                'sanitary_status' => 'OK',
                'custom_frequency_days' => $sanitaryFrequencyDays,
                'next_sanitary_inspection_due' => $nextSanitaryInspectionDue,
                'is_seasonal_pause' => 0,
                'seasonal_pause_reason' => null,
                'seasonal_pause_until' => null,
            ];
            return true;
        }

        $this->machines[$machineId]['custom_frequency_days'] = $sanitaryFrequencyDays;
        if ($nextSanitaryInspectionDue !== null) {
            $this->machines[$machineId]['next_sanitary_inspection_due'] = $nextSanitaryInspectionDue;
        }

        return true;
    }

    public function setSeasonalPause(int $machineId, string $reason, ?string $pauseUntil = null): bool
    {
        return true;
    }

    public function resumeSeasonalPause(int $machineId): bool
    {
        return true;
    }

    public function updateSanitaryStatus(int $machineId, string $status): bool
    {
        if (isset($this->machines[$machineId])) {
            $this->machines[$machineId]['sanitary_status'] = $status;
        }
        return true;
    }

    public function getMachineSettings(int $machineId): ?array
    {
        if (!isset($this->machines[$machineId])) {
            return null;
        }
        $m = $this->machines[$machineId];
        $type = $m['machine_type'];
        $typeSetting = $this->settings[$type] ?? null;

        return [
            'machine_id' => $m['machine_id'],
            'machine_type' => $type,
            'sanitary_status' => $m['sanitary_status'],
            'custom_frequency_days' => $m['custom_frequency_days'],
            'effective_frequency_days' => $m['custom_frequency_days'] ?? ($typeSetting?->getDefaultFrequencyDays() ?? 15),
            'next_sanitary_inspection_due' => $m['next_sanitary_inspection_due'],
            'is_seasonal_pause' => (bool)$m['is_seasonal_pause'],
            'seasonal_pause_reason' => $m['seasonal_pause_reason'],
            'seasonal_pause_until' => $m['seasonal_pause_until'],
        ];
    }
}

/**
 * Doble de prueba en memoria para MachineRepositoryInterface
 */
class InMemoryMachineRepository implements MachineRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $machinesList = [];

    public function findActiveByLocationId(int $locationId): array { return []; }
    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?\VendGuard\Core\Domain\Model\Machine { return null; }
    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?\VendGuard\Core\Domain\Model\Machine { return null; }
    public function create(array $data): \VendGuard\Core\Domain\Model\Machine { throw new \RuntimeException('Not implemented'); }
    public function update(int $id, array $data): bool { return true; }
    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool { return true; }
    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool { return true; }
    public function hasActiveTicketOrWarranty(int $machineId): bool { return false; }
    public function getActiveTicketOrWarranty(int $machineId): ?array { return null; }
    public function clearNoAccessBlock(int $machineId, string $ticketCode): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la lógica
        // del levantamiento vive en Machine y se certifica en su suite dedicada.
        return true;
    }
    public function blockForNoAccess(int $machineId, string $ticketCode): bool
    {
        // Doble de prueba: el contrato de persistencia exige el método; la lógica
        // del bloqueo vive en Machine y se certifica en su suite dedicada.
        return true;
    }

    public function softDelete(int $id): bool { return true; }

    public function findAll(array $filters = []): array
    {
        return $this->machinesList;
    }
}

// Configuración del entorno de pruebas
$orderRepo = new InMemoryPreventiveOrderRepository();
$settingsRepo = new InMemorySchedulerSettingsRepository();
$machineRepo = new InMemoryMachineRepository();

$service = new PreventiveOrderSchedulerService($orderRepo, $settingsRepo, $machineRepo);

// --- 1. Inicialización de Ciclo en Alta (RF-PREV-01, EARS 1.1) ---
echo "--- 1. Inicialización de Ciclo en Alta (RF-PREV-01, EARS 1.1) ---\n";
$today = date('Y-m-d');
$dueMach1 = $service->initializeMachinePreventiveCycle(1, 'PERISHABLE_FOOD', null, $today);
$expectedDue1 = date('Y-m-d', strtotime("{$today} + 15 days"));
assertCondition($dueMach1 === $expectedDue1, "1.1 Inicializa perecederos a 15 días ({$dueMach1})");

// Blindaje Art. II: intento de inicializar perecederos > 15 días
$caughtPerishableInit = false;
try {
    $service->initializeMachinePreventiveCycle(1, 'PERISHABLE_FOOD', 20, $today);
} catch (PerishableFrequencyLimitException $e) {
    $caughtPerishableInit = true;
    assertCondition($e->getAttemptedDays() === 20, "1.2 Captura attemptedDays = 20");
}
assertCondition($caughtPerishableInit, "1.3 Bloquea inicialización de perecederos con periodicidad > 15 días");

$dueMach2 = $service->initializeMachinePreventiveCycle(2, 'HOT_DRINKS', null, $today);
$expectedDue2 = date('Y-m-d', strtotime("{$today} + 30 days"));
assertCondition($dueMach2 === $expectedDue2, "1.4 Inicializa bebidas calientes a 30 días ({$dueMach2})");

// --- 2. Generación Anticipada (Horizonte 5 días, EARS 2.1) ---
echo "\n--- 2. Generación Anticipada (Horizonte 5 días, EARS 2.1) ---\n";
$machineRepo->machinesList = [
    // Máquina 10: Vence en 3 días (debe generar orden)
    [
        'id' => 10,
        'location_id' => 1,
        'code' => 'VEND-10',
        'machine_type' => 'PERISHABLE_FOOD',
        'sanitary_status' => 'OK',
        'next_sanitary_inspection_due' => date('Y-m-d', strtotime('+3 days')),
        'is_seasonal_pause' => 0,
    ],
    // Máquina 20: Vence en 12 días (> 5 días, NO debe generar orden)
    [
        'id' => 20,
        'location_id' => 1,
        'code' => 'VEND-20',
        'machine_type' => 'HOT_DRINKS',
        'sanitary_status' => 'OK',
        'next_sanitary_inspection_due' => date('Y-m-d', strtotime('+12 days')),
        'is_seasonal_pause' => 0,
    ],
    // Máquina 30: En Pausa Estacional con vencimiento a 2 días (debe omitirse)
    [
        'id' => 30,
        'location_id' => 2,
        'code' => 'VEND-30',
        'machine_type' => 'PERISHABLE_FOOD',
        'sanitary_status' => 'SEASONAL_PAUSE',
        'next_sanitary_inspection_due' => date('Y-m-d', strtotime('+2 days')),
        'is_seasonal_pause' => 1,
    ],
    // Máquina 40: Vence hoy pero YA TIENE orden previa activa (debe omitirse)
    [
        'id' => 40,
        'location_id' => 1,
        'code' => 'VEND-40',
        'machine_type' => 'PERISHABLE_FOOD',
        'sanitary_status' => 'OK',
        'next_sanitary_inspection_due' => date('Y-m-d'),
        'is_seasonal_pause' => 0,
    ],
];

// Crear orden previa activa para la máquina 40
$orderRepo->create([
    'machine_id' => 40,
    'location_id' => 1,
    'status' => 'SCHEDULED',
    'due_date' => date('Y-m-d'),
]);

$report = $service->generateDueOrders(advanceDays: 5);

assertCondition($report['scanned_machines_count'] === 4, "2.1 Escaneó 4 máquinas activas");
assertCondition($report['generated_orders_count'] === 1, "2.2 Generó exactamente 1 orden (Máquina 10)");
assertCondition($report['skipped_seasonal_pause'] === 1, "2.3 Omitió 1 máquina en pausa estacional (Máquina 30)");
assertCondition($report['skipped_already_active'] === 1, "2.4 Omitió 1 máquina con orden activa previa (Máquina 40)");

$genOrder = $report['generated_orders'][0];
assertCondition($genOrder->getMachineId() === 10, "2.5 La orden generada corresponde a la máquina 10");
assertCondition($genOrder->getStatus() === 'PENDING_ASSIGNMENT', "2.6 La orden generada está en PENDING_ASSIGNMENT");

// --- 3. Recálculo de Nueva Fecha tras Inspección (RF-PREV-04) ---
echo "\n--- 3. Recálculo de Nueva Fecha tras Inspección (RF-PREV-04) ---\n";
// Configurar máquina 10 en settings repo
$settingsRepo->machines[10] = [
    'machine_id' => 10,
    'machine_type' => 'PERISHABLE_FOOD',
    'sanitary_status' => 'OK',
    'custom_frequency_days' => 14,
    'next_sanitary_inspection_due' => date('Y-m-d', strtotime('+3 days')),
    'is_seasonal_pause' => 0,
    'seasonal_pause_reason' => null,
    'seasonal_pause_until' => null,
];
$newDue = $service->recalculateNextDueDate(10, '2026-09-27');
assertCondition($newDue === '2026-10-11', "3.1 Recalcula nueva fecha sumando 14 días (2026-09-27 + 14d = 2026-10-11)");

// --- 4. Expiración de Órdenes Vencidas (EARS 2.4) ---
echo "\n--- 4. Expiración de Órdenes Vencidas (EARS 2.4) ---\n";
// Crear una orden con due_date en el pasado
$overdueOrder = $orderRepo->create([
    'machine_id' => 50,
    'location_id' => 1,
    'status' => 'PENDING_ASSIGNMENT',
    'due_date' => date('Y-m-d', strtotime('-2 days')),
]);
$expiredCount = $service->expireOverdueOrders();
assertCondition($expiredCount === 1, "4.1 Expira 1 orden con fecha límite vencida");
$refreshedOverdue = $orderRepo->findById($overdueOrder->getId());
assertCondition($refreshedOverdue->getStatus() === 'EXPIRED', "4.2 Estado de la orden transicionado a EXPIRED");

// --- 5. Traslado de Máquina: Cancelación Lógica y Nueva Orden (Art. III y EARS 2.5) ---
echo "\n--- 5. Traslado de Máquina: Cancelación Lógica y Nueva Orden (Art. III y EARS 2.5) ---\n";
// Crear orden activa en sede 1
$activeBeforeTransfer = $orderRepo->create([
    'machine_id' => 60,
    'location_id' => 1,
    'status' => 'SCHEDULED',
    'due_date' => date('Y-m-d', strtotime('+8 days')),
]);

$newOrderAfterTransfer = $service->handleMachineTransfer(60, 2);
assertCondition($newOrderAfterTransfer !== null, "5.1 Genera nueva orden preventiva en sede destino (sede 2)");
assertCondition($newOrderAfterTransfer->getLocationId() === 2, "5.2 Nueva orden asociada a la sede 2");

// Verificar que la orden previa fue cancelada lógicamente (cero DELETE FROM)
$prevOrderCancelled = $orderRepo->findById($activeBeforeTransfer->getId());
assertCondition($prevOrderCancelled->getStatus() === 'CANCELLED', "5.3 Orden previa cancelada lógicamente (status = CANCELLED)");
assertCondition(!empty($prevOrderCancelled->getDeletedAt()), "5.4 Orden previa contiene deleted_at estampado");
assertCondition(str_contains($prevOrderCancelled->getCancellationReason(), 'traslado'), "5.5 Motivo de cancelación registra traslado de máquina");

echo "\n============================================================================\n";
echo " Total Aserciones: {$assertions}\n";
echo " RESULTADO: 100% EN VERDE. Todas las reglas de PreventiveOrderSchedulerService verificadas.\n";
echo " Condición T-PREV-09 CUMPLIDA SATISFACTORIAMENTE.\n";
echo "============================================================================\n";
