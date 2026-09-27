<?php

declare(strict_types=1);

/**
 * PreventiveChecklistEvaluationServiceTest
 *
 * Suite de pruebas unitarias para PreventiveChecklistEvaluationService (T-PREV-10).
 * Valida:
 * 1. Control de estado previo: imposibilidad de evaluar checklist sin iniciar inspección (409 Conflict).
 * 2. Validación de completitud del checklist por tipología (422 Unprocessable Entity).
 * 3. Obligatoriedad de medición térmica en tipologías de frío (EARS 3.2).
 * 4. Blindaje del rango físico térmico [-5.0, 25.0] °C (RNF-06).
 * 5. Dictamen CONFORME con temperatura en margen.
 * 6. Dictamen CONFORME_CON_OBSERVACIONES ante advertencias secundarias no críticas.
 * 7. Dictamen NO_CONFORME y activación de CUARENTENA por rotura de frío (> 4.0 °C, Art. II).
 * 8. Dictamen NO_CONFORME y CUARENTENA por fallo en ítem normativo crítico.
 * 9. Inicio formal de inspección (startInspection).
 *
 * Dogma Vanilla: PHP 8.2+ puro sin Composer ni dependencias externas.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\PreventiveChecklistEvaluationService;
use VendGuard\Core\Domain\Exception\ChecklistIncompleteException;
use VendGuard\Core\Domain\Exception\InvalidTemperatureRangeException;
use VendGuard\Core\Domain\Exception\PreventiveOrderNotInInspectionException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Model\PreventiveOrderItem;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveItemRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;

echo "===================================================================================\n";
echo " VendGuard: Pruebas Unitarias de PreventiveChecklistEvaluationService (T-PREV-10)\n";
echo "===================================================================================\n\n";

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
 * Doble de prueba para PreventiveOrderRepositoryInterface
 */
class InMemoryOrderRepoForEval implements PreventiveOrderRepositoryInterface
{
    /** @var array<int, PreventiveOrder> */
    public array $orders = [];

    public function create(array $data): PreventiveOrder { throw new \RuntimeException('Not used'); }

    public function findById(int $id, bool $allowCancelled = true): ?PreventiveOrder
    {
        return $this->orders[$id] ?? null;
    }

    public function findByCode(string $orderCode, bool $allowCancelled = true): ?PreventiveOrder { return null; }
    public function hasActiveOrPendingOrder(int $machineId): bool { return false; }
    public function assignTechnician(int $orderId, int $technicianId, ?string $scheduledDate = null): bool { return true; }
    public function claimOrderOpportunistically(int $orderId, int $technicianId): bool { return true; }

    public function startInspection(int $orderId, int $technicianId): bool
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
            $technicianId,
            'IN_INSPECTION',
            $o->getOrderType(),
            $o->getScheduledDate(),
            $o->getDueDate(),
            date('Y-m-d H:i:s'),
            null,
            null,
            null,
            null,
            false,
            $o->getNotes(),
            null,
            $o->getCreatedAt(),
            date('Y-m-d H:i:s'),
            null
        );
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
            'COMPLETED',
            $o->getOrderType(),
            $o->getScheduledDate(),
            $o->getDueDate(),
            $o->getStartedAt(),
            date('Y-m-d H:i:s'),
            $temperatureMeasured,
            $result,
            $linkedIncidentId,
            $isQuarantineTriggered,
            $notes ?? $o->getNotes(),
            null,
            $o->getCreatedAt(),
            date('Y-m-d H:i:s'),
            null
        );
        return true;
    }

    public function linkIncident(int $orderId, int $incidentId): bool { return true; }
    public function softCancel(int $orderId, string $reason): bool { return true; }
    public function findForCoordinatorList(array $filters = []): array { return []; }
    public function countForCoordinatorList(array $filters = []): int { return 0; }
    public function findForTechnicianRoute(int $technicianId, ?int $locationId = null): array { return []; }
    public function getDashboardSummary(): array { return []; }
    public function expireOverdueOrders(): int { return 0; }
}

/**
 * Doble de prueba para PreventiveItemRepositoryInterface
 */
class InMemoryItemRepoForEval implements PreventiveItemRepositoryInterface
{
    /** @var array<int, list<PreventiveOrderItem>> */
    public array $itemsByOrder = [];

    public function saveOrderItems(int $orderId, array $items): bool
    {
        $this->itemsByOrder[$orderId] = $items;
        return true;
    }

    public function findByOrderId(int $orderId): array
    {
        return $this->itemsByOrder[$orderId] ?? [];
    }

    public function findById(int $itemId): ?PreventiveOrderItem { return null; }
    public function attachPhoto(int $itemId, string $photoPath): bool { return true; }
    public function attachPhotoByItemCode(int $orderId, string $itemCode, string $photoPath): bool { return true; }
    public function findCriticalFailures(int $orderId): array { return []; }
    public function hasCriticalFailures(int $orderId): bool { return false; }
}

/**
 * Doble de prueba para PreventiveSettingsRepositoryInterface
 */
class InMemorySettingsRepoForEval implements PreventiveSettingsRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $machineConfigs = [];

    public function findAll(): array { return []; }
    public function findByMachineType(string $machineType): ?PreventiveSetting { return null; }
    public function updateTypeSettings(string $machineType, int $defaultFrequencyDays, int $maxAllowedDays, int $advanceWarningDays = 5): bool { return true; }
    public function updateMachineConfig(int $machineId, ?int $sanitaryFrequencyDays, ?string $nextSanitaryInspectionDue = null): bool { return true; }
    public function setSeasonalPause(int $machineId, string $reason, ?string $pauseUntil = null): bool { return true; }
    public function resumeSeasonalPause(int $machineId): bool { return true; }

    public function getMachineSettings(int $machineId): ?array
    {
        return $this->machineConfigs[$machineId] ?? [
            'machine_id' => $machineId,
            'machine_type' => 'PERISHABLE_FOOD',
            'sanitary_status' => 'OK',
            'effective_frequency_days' => 15,
        ];
    }

    public function updateSanitaryStatus(int $machineId, string $status): bool
    {
        if (isset($this->machineConfigs[$machineId])) {
            $this->machineConfigs[$machineId]['sanitary_status'] = $status;
        }
        return true;
    }
}

/**
 * Doble de prueba para AuditLogRepositoryInterface
 */
class InMemoryAuditRepoForEval implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];
    public function log(AuditEvent $event): AuditEvent { $this->events[] = $event; return $event; }
    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array { return $this->events; }
    public function countEvents(array $filters = []): int { return count($this->events); }
    public function findByEntity(string $entityType, int $entityId): array { return []; }
}

// Inicialización de componentes
$orderRepo = new InMemoryOrderRepoForEval();
$itemRepo = new InMemoryItemRepoForEval();
$settingsRepo = new InMemorySettingsRepoForEval();
$auditRepo = new InMemoryAuditRepoForEval();
$auditLogger = new AuditLogger($auditRepo);

$service = new PreventiveChecklistEvaluationService($orderRepo, $itemRepo, $settingsRepo, null, $auditLogger);

// Configuración de máquina 1 (Perecederos) y orden 101 en SCHEDULED
$settingsRepo->machineConfigs[1] = [
    'machine_id' => 1,
    'machine_type' => 'PERISHABLE_FOOD',
    'sanitary_status' => 'OK',
    'effective_frequency_days' => 15,
];

$order101 = new PreventiveOrder(
    101,
    'PREV-2026-0101',
    1,
    10,
    3,
    'SCHEDULED',
    'ROUTINE',
    date('Y-m-d'),
    date('Y-m-d')
);
$orderRepo->orders[101] = $order101;

// --- 1. Control de Estado Previo (409 Conflict) ---
echo "--- 1. Control de Estado Previo (409 Conflict) ---\n";
$caughtNotInInspection = false;
try {
    $service->evaluateChecklist(101, 3, ['temperature_measured' => 3.4, 'items' => []]);
} catch (PreventiveOrderNotInInspectionException $e) {
    $caughtNotInInspection = true;
    assertCondition($e->getErrorCode() === 'ORDER_NOT_IN_INSPECTION', "1.1 Código ORDER_NOT_IN_INSPECTION");
    assertCondition($e->getHttpStatusCode() === 409, "1.2 Código HTTP 409 Conflict");
}
assertCondition($caughtNotInInspection, "1.3 Bloquea evaluación de checklist si orden está en SCHEDULED");

// Iniciar inspección correctamente
$started = $service->startInspection(101, 3);
assertCondition($started, "1.4 startInspection() transiciona exitosamente la orden");
assertCondition($orderRepo->orders[101]->getStatus() === 'IN_INSPECTION', "1.5 Orden 101 ahora está en IN_INSPECTION");

// --- 2. Validación de Completitud del Checklist (422) ---
echo "\n--- 2. Validación de Completitud del Checklist (422) ---\n";
$caughtIncomplete = false;
try {
    $service->evaluateChecklist(101, 3, [
        'temperature_measured' => 3.2,
        'items' => [
            ['item_code' => 'TEMP_PROBE', 'status' => 'PASS'],
            // Omitiendo el resto de ítems obligatorios
        ],
    ]);
} catch (ChecklistIncompleteException $e) {
    $caughtIncomplete = true;
    assertCondition($e->getErrorCode() === 'CHECKLIST_INCOMPLETE', "2.1 Código CHECKLIST_INCOMPLETE");
    assertCondition($e->getHttpStatusCode() === 422, "2.2 Código HTTP 422");
    assertCondition(count($e->getMissingItems()) >= 4, "2.3 Detecta comprobaciones omitidas");
}
assertCondition($caughtIncomplete, "2.4 Bloquea checklist incompleto por omisión de ítems normativos");

// --- 3. Medición Térmica Obligatoria en Máquinas Refrigeradas (EARS 3.2) ---
echo "\n--- 3. Medición Térmica Obligatoria en Máquinas Refrigeradas (EARS 3.2) ---\n";
$caughtOmittedTemp = false;
try {
    $service->evaluateChecklist(101, 3, [
        'temperature_measured' => null, // Omitida
        'items' => [
            ['item_code' => 'TEMP_PROBE', 'status' => 'PASS'],
            ['item_code' => 'SEALS_GASKET', 'status' => 'PASS'],
            ['item_code' => 'EVAPORATOR_FROST', 'status' => 'PASS'],
            ['item_code' => 'DISINFECTION_TRAYS', 'status' => 'PASS'],
            ['item_code' => 'EXPIRATION_DATES', 'status' => 'PASS'],
            ['item_code' => 'ELECTRICAL_SAFETY', 'status' => 'PASS'],
        ],
    ]);
} catch (ChecklistIncompleteException $e) {
    $caughtOmittedTemp = true;
    assertCondition(in_array('TEMP_PROBE', $e->getMissingItems(), true), "3.1 Señala TEMP_PROBE por temperatura omitida");
}
assertCondition($caughtOmittedTemp, "3.2 Bloquea checklist con temperatura nula en perecederos");

// --- 4. Blindaje de Rango Físico [-5.0, 25.0] °C (RNF-06) ---
echo "\n--- 4. Blindaje de Rango Físico [-5.0, 25.0] °C (RNF-06) ---\n";
$caughtBelowPhysical = false;
try {
    $service->evaluateChecklist(101, 3, [
        'temperature_measured' => -10.5, // Por debajo de -5.0 °C
        'items' => [],
    ]);
} catch (InvalidTemperatureRangeException $e) {
    $caughtBelowPhysical = true;
    assertCondition($e->getErrorCode() === 'INVALID_TEMPERATURE_RANGE', "4.1 Código INVALID_TEMPERATURE_RANGE en -10.5 °C");
    assertCondition($e->getMeasuredTemperature() === -10.5, "4.2 Captura temperatura -10.5 °C");
}
assertCondition($caughtBelowPhysical, "4.3 Rechaza temperatura por debajo del límite físico (-5.0 °C)");

$caughtAbovePhysical = false;
try {
    $service->evaluateChecklist(101, 3, [
        'temperature_measured' => 38.0, // Errata tipográfica al omitir coma (3.8 -> 38)
        'items' => [],
    ]);
} catch (InvalidTemperatureRangeException $e) {
    $caughtAbovePhysical = true;
    assertCondition($e->getMeasuredTemperature() === 38.0, "4.4 Captura errata de digitación 38.0 °C");
}
assertCondition($caughtAbovePhysical, "4.5 Rechaza temperatura inverosímil por encima del límite físico (25.0 °C)");

// --- 5. Dictamen CONFORME (Apta 100%) ---
echo "\n--- 5. Dictamen CONFORME (Apta 100%) ---\n";
$fullPassItems = [
    ['item_code' => 'TEMP_PROBE', 'status' => 'PASS', 'observations' => 'Estabilizada en 3.4 °C'],
    ['item_code' => 'SEALS_GASKET', 'status' => 'PASS', 'observations' => null],
    ['item_code' => 'EVAPORATOR_FROST', 'status' => 'PASS', 'observations' => null],
    ['item_code' => 'DISINFECTION_TRAYS', 'status' => 'PASS', 'observations' => 'Desinfectado'],
    ['item_code' => 'EXPIRATION_DATES', 'status' => 'PASS', 'observations' => 'Fechas conformes'],
    ['item_code' => 'ELECTRICAL_SAFETY', 'status' => 'PASS', 'observations' => 'Toma de tierra OK'],
];

$resConforme = $service->evaluateChecklist(101, 3, [
    'temperature_measured' => 3.4,
    'items' => $fullPassItems,
    'general_notes' => 'Inspección periódica satisfactoria.',
]);

assertCondition($resConforme['result'] === 'CONFORME', "5.1 Dictamen CONFORME asignado");
assertCondition($resConforme['status'] === 'COMPLETED', "5.2 Orden finalizada en COMPLETED");
assertCondition($resConforme['is_quarantine_triggered'] === false, "5.3 is_quarantine_triggered = false");
assertCondition($resConforme['machine_sanitary_status'] === 'OK', "5.4 sanitary_status de máquina = OK");
assertCondition($orderRepo->orders[101]->getResult() === 'CONFORME', "5.5 Orden persistida con resultado CONFORME");

// --- 6. Dictamen CONFORME_CON_OBSERVACIONES (Apta Condicionada) ---
echo "\n--- 6. Dictamen CONFORME_CON_OBSERVACIONES (Apta Condicionada) ---\n";
// Crear nueva orden en IN_INSPECTION
$order102 = new PreventiveOrder(102, 'PREV-2026-0102', 1, 10, 3, 'IN_INSPECTION', 'ROUTINE', date('Y-m-d'), date('Y-m-d'));
$orderRepo->orders[102] = $order102;

$warningItems = $fullPassItems;
$warningItems[1] = ['item_code' => 'SEALS_GASKET', 'status' => 'WARN', 'observations' => 'Ligero desgaste exterior en goma sin fuga térmica'];

$resObs = $service->evaluateChecklist(102, 3, [
    'temperature_measured' => 3.6,
    'items' => $warningItems,
]);

assertCondition($resObs['result'] === 'CONFORME_CON_OBSERVACIONES', "6.1 Dictamen CONFORME_CON_OBSERVACIONES asignado");
assertCondition($resObs['is_quarantine_triggered'] === false, "6.2 No activa cuarentena ante observación leve");
assertCondition($resObs['machine_sanitary_status'] === 'OK', "6.3 Máquina permanece operativa (sanitary_status = OK)");

// --- 7. Dictamen NO_CONFORME y CUARENTENA por Rotura de Frío (> 4.0 °C, Art. II) ---
echo "\n--- 7. Dictamen NO_CONFORME y CUARENTENA por Rotura de Frío (> 4.0 °C, Art. II) ---\n";
$order103 = new PreventiveOrder(103, 'PREV-2026-0103', 1, 10, 3, 'IN_INSPECTION', 'ROUTINE', date('Y-m-d'), date('Y-m-d'));
$orderRepo->orders[103] = $order103;

$resColdBreak = $service->evaluateChecklist(103, 3, [
    'temperature_measured' => 5.8, // Supera 4.0 °C legal
    'items' => $fullPassItems,     // Aunque el técnico pusiera PASS en ítems, la temperatura fuerza fallo crítico
]);

assertCondition($resColdBreak['result'] === 'NO_CONFORME', "7.1 Dictamen NO_CONFORME forzado por temperatura > 4.0 °C");
assertCondition($resColdBreak['is_quarantine_triggered'] === true, "7.2 Activa cuarentena sanitaria (is_quarantine_triggered = true)");
assertCondition($resColdBreak['machine_sanitary_status'] === 'QUARANTINE', "7.3 sanitary_status de máquina pasa a QUARANTINE");
assertCondition($settingsRepo->machineConfigs[1]['sanitary_status'] === 'QUARANTINE', "7.4 Persiste QUARANTINE en máquina");

// --- 8. Dictamen NO_CONFORME por Fallo en Ítem Normativo Crítico ---
echo "\n--- 8. Dictamen NO_CONFORME por Fallo en Ítem Normativo Crítico ---\n";
// Configurar máquina de bebidas calientes
$settingsRepo->machineConfigs[2] = [
    'machine_id' => 2,
    'machine_type' => 'HOT_DRINKS',
    'sanitary_status' => 'OK',
    'effective_frequency_days' => 30,
];
$order104 = new PreventiveOrder(104, 'PREV-2026-0104', 2, 10, 3, 'IN_INSPECTION', 'ROUTINE', date('Y-m-d'), date('Y-m-d'));
$orderRepo->orders[104] = $order104;

$hotDrinksItems = [
    ['item_code' => 'BOILER_HYDRAULIC', 'status' => 'FAIL', 'observations' => 'Fuga activa de agua a presión'], // Crítico
    ['item_code' => 'DISINFECTION_WHIPPERS', 'status' => 'PASS', 'observations' => null],
    ['item_code' => 'WATER_FILTER', 'status' => 'PASS', 'observations' => null],
    ['item_code' => 'WASTE_TRAY', 'status' => 'PASS', 'observations' => null],
    ['item_code' => 'ELECTRICAL_SAFETY', 'status' => 'PASS', 'observations' => null],
];

$resCritical = $service->evaluateChecklist(104, 3, [
    'temperature_measured' => null, // Máquina no refrigerada
    'items' => $hotDrinksItems,
]);

assertCondition($resCritical['result'] === 'NO_CONFORME', "8.1 Dictamen NO_CONFORME por fallo en caldera");
assertCondition($resCritical['is_quarantine_triggered'] === true, "8.2 Activa cuarentena sanitaria inmediata");
assertCondition($resCritical['machine_sanitary_status'] === 'QUARANTINE', "8.3 Máquina de bebidas calientes queda en QUARANTINE");

echo "\n===================================================================================\n";
echo " Total Aserciones: {$assertions}\n";
echo " RESULTADO: 100% EN VERDE. Todas las reglas de PreventiveChecklistEvaluationService verificadas.\n";
echo " Condición T-PREV-10 CUMPLIDA SATISFACTORIAMENTE.\n";
echo "===================================================================================\n";
