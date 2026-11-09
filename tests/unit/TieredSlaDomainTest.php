<?php

declare(strict_types=1);

/**
 * TieredSlaDomainTest · T-SLA-05
 *
 * Certifica el núcleo determinista del Módulo 30 sin base de datos ni reloj:
 *
 *   1. **Catálogo contractual (`RF-01.1`, `D1`):** los tres perfiles oficiales,
 *      sus etiquetas, horas nominales, validación y conversión desde texto.
 *   2. **Matriz `SlaTier × UrgencyLevel` (`RF-01.3`):** las 12 celdas exactas
 *      para avisos sin riesgo de cadena de frío.
 *   3. **Techo sanitario innegociable (`Art. II`, `QA 1`):** cualquier avería
 *      de `PERISHABLE_FOOD`, y toda `COMBO` con fallo térmico o prioridad
 *      `CRITICAL`, recibe 240 minutos con independencia del perfil y la urgencia.
 *   4. **Vencimiento efectivo (`C2`, `C4`, `QA 3`):** los segundos descontables
 *      desplazan la hora límite sin reiniciar el reloj ni acortar el plazo.
 *   5. **Escalado contractual 24/7 (`RF-05`, `D4`, `QA 2`):** el bono de guardia
 *      es +60 y nunca se suma al +100 sanitario, que conserva la prelación.
 *
 * Uso:
 *   php tests/unit/TieredSlaDomainTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\GeoDistanceService;
use VendGuard\Application\Service\MetricsCalculationService;
use VendGuard\Application\Service\MetricsExportService;
use VendGuard\Application\Service\RouteOptimizationService;
use VendGuard\Application\Service\TechnicianAssignmentSuggester;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\Model\RouteSettings;
use VendGuard\Core\Domain\Model\StopPriority;
use VendGuard\Core\Domain\Repository\MetricsRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\SlaTier;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Core\Domain\ValueObject\ZoneAffinityType;
use VendGuard\Core\Service\SlaTargetCalculator;
use VendGuard\Core\Service\TechnicianWorkloadScorer;

/**
 * Repositorio mínimo para certificar la composición analítica sin PostgreSQL.
 */
final class TieredSlaMetricsRepository implements MetricsRepositoryInterface
{
    public function getGrossMttrSeconds(MetricFilter $filter, ?MachineType $machineType = null): ?int
    {
        return null;
    }

    public function getAveragePendingInfoSeconds(MetricFilter $filter, ?MachineType $machineType = null): int
    {
        return 0;
    }

    public function countCreatedTickets(MetricFilter $filter): int
    {
        return 0;
    }

    public function countResolvedTickets(MetricFilter $filter): int
    {
        return 0;
    }

    public function countActiveBacklog(): int
    {
        return 0;
    }

    public function countCriticalSlaBreaches(MetricFilter $filter): int
    {
        return 0;
    }

    public function getBreakdownByLocation(MetricFilter $filter): array
    {
        return [
            [
                'location_id' => 1,
                'site_code' => 'SLA-CRITICA',
                'location_name' => 'Infraestructura Crítica',
                'sla_tier' => 'CRITICAL_INFRASTRUCTURE_24_7',
                'is_active' => true,
                'tickets_resolved' => 5,
                'mttr_gross_seconds' => 7200,
                'mttr_pending_info_seconds' => 0,
                'sla_breaches' => 2,
            ],
            [
                'location_id' => 2,
                'site_code' => 'SLA-ESTANDAR',
                'location_name' => 'Sede Estándar',
                'sla_tier' => 'STANDARD',
                'is_active' => true,
                'tickets_resolved' => 3,
                'mttr_gross_seconds' => 3600,
                'mttr_pending_info_seconds' => 0,
                'sla_breaches' => 0,
            ],
            [
                'location_id' => 3,
                'site_code' => 'SLA-SIN-DATOS',
                'location_name' => 'Sede sin resoluciones',
                'sla_tier' => 'PRIORITY_CORPORATE',
                'is_active' => true,
                'tickets_resolved' => 0,
                'mttr_gross_seconds' => null,
                'mttr_pending_info_seconds' => 0,
                'sla_breaches' => 0,
            ],
        ];
    }

    public function getBreakdownByTechnician(MetricFilter $filter): array
    {
        return [];
    }

    public function getBreakdownByMachineType(MetricFilter $filter): array
    {
        return [];
    }

    public function getBreakdownByCategory(MetricFilter $filter): array
    {
        return [];
    }

    public function getTechnicianMetrics(int $technicianId, MetricFilter $filter): array
    {
        return [];
    }

    public function getOpportunisticInterventions(MetricFilter $filter): array
    {
        return [];
    }
}

function tieredSlaLocation(int $id, SlaTier $tier, float $latitude = 41.3900, float $longitude = 2.1900): Location
{
    return new Location(
        $id,
        'SLA-' . $id,
        'Sede SLA ' . $id,
        'Calle SLA ' . $id,
        null,
        null,
        true,
        null,
        null,
        null,
        $latitude,
        $longitude,
        true,
        'sede' . $id . '@test.es',
        '08001',
        7,
        false,
        $tier
    );
}

function tieredSlaTask(Location $location, int $id, array $overrides = []): array
{
    return array_replace([
        'location' => $location,
        'type' => 'CORRECTIVE',
        'id' => $id,
        'machine_id' => 7000 + $id,
        'machine_code' => 'SLA-M-' . $id,
        'machine_type' => 'HOT_DRINKS',
        'category' => 'OTHER',
        'floor_location' => 'Planta 1',
        'status' => 'ASSIGNED',
        'urgency' => 'HIGH',
        'is_critical' => false,
        'sla_due_at' => null,
        'sla_target_minutes' => null,
        'total_pending_info_seconds' => 0,
        'paused_at' => null,
        'created_at' => '2026-11-09T08:00:00+01:00',
    ], $overrides);
}

function tieredSlaTechnician(int $id, array $overrides = []): array
{
    return array_replace([
        'technician_id' => $id,
        'name' => 'Técnico SLA ' . $id,
        'operator_code' => 'SLA-OP-' . $id,
        'is_on_call' => false,
        'is_active' => true,
        'affinity' => 'NONE',
        'shift_status' => 'ON_SHIFT',
        'active_incidents' => 0,
        'pending_info_incidents' => 0,
        'preventive_orders_today' => 0,
        'has_live_stop_at_target_location' => false,
        'live_stops_in_zone_other_locations' => 0,
        'last_assigned_at' => null,
        'in_progress_location' => null,
        'last_completed_location' => null,
    ], $overrides);
}

function tieredSlaIncident(array $overrides = []): array
{
    return array_replace([
        'id' => 700,
        'ticket_code' => 'INC-SLA-700',
        'priority' => 'HIGH',
        'machine_type' => 'SNACKS',
        'category' => 'PRODUCT_JAM',
        'sla_tier_snapshot' => 'CRITICAL_INFRASTRUCTURE_24_7',
        'sla_target_minutes' => 480,
        'location' => [
            'id' => 70,
            'site_code' => 'SLA-70',
            'name' => 'Sede Crítica 70',
            'postal_code' => '08001',
            'zone_id' => 7,
            'zone_name' => 'Zona Crítica',
            'latitude' => 41.3900,
            'longitude' => 2.1900,
        ],
    ], $overrides);
}

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-SLA-05 · Dominio de SLA por perfil de sede (Módulo 30)\n";
echo "======================================================================\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. Catálogo contractual de perfiles
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 1. Catálogo SlaTier (RF-01.1, D1) ---\n";

$tierCatalog = [
    'STANDARD' => ['label' => 'Estándar', 'hours' => 24.0],
    'PRIORITY_CORPORATE' => ['label' => 'Prioritario Corporativo', 'hours' => 12.0],
    'CRITICAL_INFRASTRUCTURE_24_7' => ['label' => 'Infraestructura Crítica 24x7', 'hours' => 8.0],
];

$assert(
    '1.1 El catálogo declara exactamente los tres perfiles oficiales',
    array_column(SlaTier::cases(), 'value') === array_keys($tierCatalog),
    implode(', ', array_column(SlaTier::cases(), 'value'))
);

foreach ($tierCatalog as $value => $expected) {
    $tier = SlaTier::fromString($value);
    $assert(
        "1.2 {$value} publica su etiqueta y horas nominales",
        $tier->label() === $expected['label'] && $tier->nominalReferenceHours() === $expected['hours'],
        $tier->label() . ' · ' . $tier->nominalReferenceHours() . ' h'
    );
}

$assert(
    '1.3 La validación normaliza mayúsculas y espacios, y rechaza un perfil desconocido',
    SlaTier::isValid(' priority_corporate ') === true
        && SlaTier::isValid('GOLD') === false
        && SlaTier::fromString('critical_infrastructure_24_7') === SlaTier::CRITICAL_INFRASTRUCTURE_24_7
);

$invalidTier = null;
try {
    SlaTier::fromString('GOLD');
} catch (InvalidArgumentException $exception) {
    $invalidTier = $exception->getMessage();
}
$assert(
    '1.4 Un perfil fuera del catálogo se rechaza en voz alta',
    $invalidTier !== null && str_contains($invalidTier, 'GOLD'),
    $invalidTier ?? 'no se lanzó la excepción'
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Matriz de 12 celdas para avisos sin riesgo de cadena de frío
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. Matriz SlaTier × UrgencyLevel (RF-01.3) ---\n";

$matrix = [
    'STANDARD' => [
        'CRITICAL' => 480,
        'HIGH' => 720,
        'MEDIUM' => 1440,
        'LOW' => 1440,
    ],
    'PRIORITY_CORPORATE' => [
        'CRITICAL' => 360,
        'HIGH' => 480,
        'MEDIUM' => 720,
        'LOW' => 720,
    ],
    'CRITICAL_INFRASTRUCTURE_24_7' => [
        'CRITICAL' => 240,
        'HIGH' => 240,
        'MEDIUM' => 480,
        'LOW' => 480,
    ],
];

$matrixCells = 0;
$matrixMismatches = [];
foreach ($matrix as $tierValue => $expectations) {
    $tier = SlaTier::fromString($tierValue);
    foreach (UrgencyLevel::cases() as $urgency) {
        $matrixCells++;
        $expected = $expectations[$urgency->value];
        $fromTier = $tier->contractualTargetMinutes($urgency);
        $fromCalculator = SlaTargetCalculator::calculateTargetMinutes(
            $tier,
            MachineType::SNACKS,
            IncidentCategory::PRODUCT_JAM,
            $urgency
        );

        if ($fromTier !== $expected || $fromCalculator !== $expected) {
            $matrixMismatches[] = sprintf(
                '%s/%s esperado %d, tier %d, calculator %d',
                $tierValue,
                $urgency->value,
                $expected,
                $fromTier,
                $fromCalculator
            );
        }

        $assert(
            sprintf('2.%d %s · %s = %d min', $matrixCells, $tier->label(), $urgency->value, $expected),
            $fromTier === $expected && $fromCalculator === $expected,
            $fromTier . ' min'
        );
    }
}

$assert(
    '2.13 La matriz cubre sus 12 celdas y ninguna queda por debajo del techo sanitario',
    $matrixCells === 12
        && $matrixMismatches === []
        && min(array_merge(...array_values($matrix))) >= SlaTargetCalculator::COLD_CHAIN_CEILING_MINUTES,
    $matrixCells . ' celdas'
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Techo sanitario innegociable del Art. II
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Techo sanitario de 240 min (Art. II, QA 1) ---\n";

$perishableCombinations = 0;
$perishableMismatches = [];
foreach (SlaTier::cases() as $tier) {
    foreach (UrgencyLevel::cases() as $urgency) {
        foreach (IncidentCategory::cases() as $category) {
            $perishableCombinations++;
            $risk = SlaTargetCalculator::isColdChainRisk(MachineType::PERISHABLE_FOOD, $category, $urgency);
            $target = SlaTargetCalculator::calculateTargetMinutes(
                $tier,
                MachineType::PERISHABLE_FOOD,
                $category,
                $urgency
            );

            if (!$risk || $target !== 240) {
                $perishableMismatches[] = sprintf(
                    '%s/%s/%s riesgo=%s objetivo=%d',
                    $tier->value,
                    $category->value,
                    $urgency->value,
                    $risk ? 'true' : 'false',
                    $target
                );
            }
        }
    }
}
$assert(
    '3.1 Toda combinación de perfil, categoría y prioridad en PERISHABLE_FOOD recibe 240 min',
    $perishableCombinations === 60 && $perishableMismatches === [],
    $perishableCombinations . ' combinaciones' . ($perishableMismatches === [] ? '' : ' · ' . implode('; ', $perishableMismatches))
);

$comboThermalCombinations = 0;
$comboThermalMismatches = [];
foreach (SlaTier::cases() as $tier) {
    foreach (UrgencyLevel::cases() as $urgency) {
        $comboThermalCombinations++;
        $risk = SlaTargetCalculator::isColdChainRisk(
            MachineType::COMBO,
            IncidentCategory::TEMPERATURE_COLD,
            $urgency
        );
        $target = SlaTargetCalculator::calculateTargetMinutes(
            $tier,
            MachineType::COMBO,
            IncidentCategory::TEMPERATURE_COLD,
            $urgency
        );

        if (!$risk || $target !== 240) {
            $comboThermalMismatches[] = sprintf('%s/%s objetivo=%d', $tier->value, $urgency->value, $target);
        }
    }
}
$assert(
    '3.2 Una COMBO con TEMPERATURE_COLD recibe 240 min en cualquier perfil y prioridad',
    $comboThermalCombinations === 12 && $comboThermalMismatches === [],
    $comboThermalCombinations . ' combinaciones'
);

$comboCriticalCombinations = 0;
$comboCriticalMismatches = [];
foreach (SlaTier::cases() as $tier) {
    foreach (IncidentCategory::cases() as $category) {
        $comboCriticalCombinations++;
        $risk = SlaTargetCalculator::isColdChainRisk(
            MachineType::COMBO,
            $category,
            UrgencyLevel::CRITICAL
        );
        $target = SlaTargetCalculator::calculateTargetMinutes(
            $tier,
            MachineType::COMBO,
            $category,
            UrgencyLevel::CRITICAL
        );

        if (!$risk || $target !== 240) {
            $comboCriticalMismatches[] = sprintf('%s/%s objetivo=%d', $tier->value, $category->value, $target);
        }
    }
}
$assert(
    '3.3 Una COMBO con prioridad CRITICAL recibe 240 min sea cual sea la categoría',
    $comboCriticalCombinations === 15 && $comboCriticalMismatches === [],
    $comboCriticalCombinations . ' combinaciones'
);

$comboOrdinaryRisk = SlaTargetCalculator::isColdChainRisk(
    MachineType::COMBO,
    IncidentCategory::PRODUCT_JAM,
    UrgencyLevel::MEDIUM
);
$comboOrdinaryTarget = SlaTargetCalculator::calculateTargetMinutes(
    SlaTier::STANDARD,
    MachineType::COMBO,
    IncidentCategory::PRODUCT_JAM,
    UrgencyLevel::MEDIUM
);
$assert(
    '3.4 Una COMBO sin fallo térmico ni prioridad crítica sigue la matriz (1440 min)',
    $comboOrdinaryRisk === false && $comboOrdinaryTarget === 1440,
    'riesgo=' . ($comboOrdinaryRisk ? 'true' : 'false') . ' · ' . $comboOrdinaryTarget . ' min'
);

$comboHighRisk = SlaTargetCalculator::isColdChainRisk(
    MachineType::COMBO,
    IncidentCategory::PRODUCT_JAM,
    UrgencyLevel::HIGH
);
$comboHighTarget = SlaTargetCalculator::calculateTargetMinutes(
    SlaTier::STANDARD,
    MachineType::COMBO,
    IncidentCategory::PRODUCT_JAM,
    UrgencyLevel::HIGH
);
$assert(
    '3.5 Una COMBO no térmica de prioridad HIGH conserva el objetivo contractual (720 min)',
    $comboHighRisk === false && $comboHighTarget === 720,
    'riesgo=' . ($comboHighRisk ? 'true' : 'false') . ' · ' . $comboHighTarget . ' min'
);

$coldDrinksRisk = SlaTargetCalculator::isColdChainRisk(
    MachineType::COLD_DRINKS,
    IncidentCategory::TEMPERATURE_COLD,
    UrgencyLevel::CRITICAL
);
$coldDrinksTarget = SlaTargetCalculator::calculateTargetMinutes(
    SlaTier::STANDARD,
    MachineType::COLD_DRINKS,
    IncidentCategory::TEMPERATURE_COLD,
    UrgencyLevel::CRITICAL
);
$assert(
    '3.6 El techo sanitario no se extiende a COLD_DRINKS: aplica la matriz contractual',
    $coldDrinksRisk === false && $coldDrinksTarget === 480,
    'riesgo=' . ($coldDrinksRisk ? 'true' : 'false') . ' · ' . $coldDrinksTarget . ' min'
);

$criticalInfrastructureRisk = SlaTargetCalculator::isColdChainRisk(
    MachineType::SNACKS,
    IncidentCategory::PRODUCT_JAM,
    UrgencyLevel::CRITICAL
);
$criticalInfrastructureTarget = SlaTargetCalculator::calculateTargetMinutes(
    SlaTier::CRITICAL_INFRASTRUCTURE_24_7,
    MachineType::SNACKS,
    IncidentCategory::PRODUCT_JAM,
    UrgencyLevel::CRITICAL
);
$assert(
    '3.7 Un objetivo contractual de 240 min no convierte por sí solo la avería en riesgo sanitario',
    $criticalInfrastructureRisk === false && $criticalInfrastructureTarget === 240,
    'riesgo=' . ($criticalInfrastructureRisk ? 'true' : 'false') . ' · ' . $criticalInfrastructureTarget . ' min'
);

$assert(
    '3.8 La constante pública del techo sanitario es exactamente 240 min',
    SlaTargetCalculator::COLD_CHAIN_CEILING_MINUTES === 240
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Vencimiento efectivo y segundos descontables
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Vencimiento efectivo (C2, C4, QA 3) ---\n";

$createdAt = new DateTimeImmutable('2026-11-09T08:00:00+01:00');
$withoutDiscount = SlaTargetCalculator::computeEffectiveTargetAt($createdAt, 240);
$assert(
    '4.1 Un objetivo de 240 min sin descuentos vence a las 4 horas naturales',
    $withoutDiscount->format('Y-m-d H:i:sP') === '2026-11-09 12:00:00+01:00',
    $withoutDiscount->format('Y-m-d H:i:sP')
);

$withDiscount = SlaTargetCalculator::computeEffectiveTargetAt($createdAt, 240, 5400);
$assert(
    '4.2 Los segundos descontables desplazan el vencimiento en la misma proporción',
    $withDiscount->format('Y-m-d H:i:sP') === '2026-11-09 13:30:00+01:00',
    $withDiscount->format('Y-m-d H:i:sP')
);

$withZero = SlaTargetCalculator::computeEffectiveTargetAt($createdAt, 240, 0);
$assert(
    '4.3 Un descuento explícito de cero equivale a no descontar',
    $withZero->format('Y-m-d H:i:sP') === $withoutDiscount->format('Y-m-d H:i:sP')
);

$withNegative = SlaTargetCalculator::computeEffectiveTargetAt($createdAt, 240, -3600);
$assert(
    '4.4 Un descuento negativo se satura en cero y nunca acorta el plazo',
    $withNegative->format('Y-m-d H:i:sP') === '2026-11-09 12:00:00+01:00',
    $withNegative->format('Y-m-d H:i:sP')
);

$withFullDay = SlaTargetCalculator::computeEffectiveTargetAt($createdAt, 1440, 86400);
$assert(
    '4.5 El vencimiento acumula objetivo y descuento sin reiniciar el reloj',
    $withFullDay->format('Y-m-d H:i:sP') === '2026-11-11 08:00:00+01:00',
    $withFullDay->format('Y-m-d H:i:sP')
);

$assert(
    '4.6 El cálculo es inmutable: la fecha de apertura conserva su instante original',
    $createdAt->format('Y-m-d H:i:sP') === '2026-11-09 08:00:00+01:00'
        && $withoutDiscount !== $createdAt
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Escalado contractual 24/7 frente al sanitario
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 5. Guardia 24/7: +60 contractual vs +100 sanitario (D4, QA 2) ---\n";

$assert(
    '5.1 Los dos bonos de guardia valen exactamente +60 y +100',
    TechnicianWorkloadScorer::BONUS_ON_CALL_CONTRACTUAL_24_7 === 60
        && TechnicianWorkloadScorer::COLD_CHAIN_ON_CALL_BONUS === 100
);

$contractualContext = [
    'affinity' => ZoneAffinityType::PRIMARY,
    'contractual_24_7_escalation_active' => true,
    'is_on_call' => true,
];
$contractualBreakdown = TechnicianWorkloadScorer::computeBreakdown($contractualContext);
$assert(
    '5.2 Una guardia con escalado contractual suma +60 y lo declara en su clave',
    $contractualBreakdown['contractual_24_7_on_call_bonus'] === 60
        && $contractualBreakdown['cold_chain_on_call_bonus'] === 0
        && TechnicianWorkloadScorer::computeScore($contractualContext) === 110,
    json_encode($contractualBreakdown)
);

$coldContext = [
    'is_cold_chain_risk' => true,
    'cold_chain_escalation_active' => true,
    'contractual_24_7_escalation_active' => true,
    'is_on_call' => true,
];
$coldBreakdown = TechnicianWorkloadScorer::computeBreakdown($coldContext);
$assert(
    '5.3 El escalado sanitario prevalece: +100 y el bono contractual queda en cero',
    $coldBreakdown['cold_chain_on_call_bonus'] === 100
        && $coldBreakdown['contractual_24_7_on_call_bonus'] === 0
        && TechnicianWorkloadScorer::computeScore($coldContext) === 100,
    json_encode($coldBreakdown)
);

$withoutOnCall = [
    'contractual_24_7_escalation_active' => true,
    'is_on_call' => false,
];
$assert(
    '5.4 El escalado contractual no reparte el bono sin técnico de guardia',
    TechnicianWorkloadScorer::computeBreakdown($withoutOnCall)['contractual_24_7_on_call_bonus'] === 0
);

$withoutContractualEscalation = [
    'contractual_24_7_escalation_active' => false,
    'is_on_call' => true,
];
$assert(
    '5.5 La guardia sin escalado contractual no suma el bono',
    TechnicianWorkloadScorer::computeBreakdown($withoutContractualEscalation)['contractual_24_7_on_call_bonus'] === 0
);

$assert(
    '5.6 El desglose publica siempre las siete claves del contrato, incluida la contractual',
    array_keys($contractualBreakdown) === TechnicianWorkloadScorer::BREAKDOWN_KEYS
        && in_array('contractual_24_7_on_call_bonus', TechnicianWorkloadScorer::BREAKDOWN_KEYS, true)
);

$assert(
    '5.7 El total es exactamente la suma del desglose, sin una segunda fórmula',
    array_sum($contractualBreakdown) === TechnicianWorkloadScorer::computeScore($contractualContext)
);

$contextSnapshot = $contractualContext;
TechnicianWorkloadScorer::computeScore($contractualContext);
$assert(
    '5.8 La política es pura y no muta el contexto recibido',
    $contractualContext === $contextSnapshot
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. Ruta: promoción inclusiva, congelado de pausa y prelación sanitaria
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 6. Ruta: SLA <= 4 h, pausa y multitarea (D3, C2, C3, QA 1) ---\n";

$routeService = new RouteOptimizationService(new GeoDistanceService());
$routeSettings = new RouteSettings();
$routeReference = new DateTimeImmutable('2026-11-09T10:00:00+01:00');
$routeOrigin = ['latitude' => 41.3900, 'longitude' => 2.1900];

$coldFourLocation = tieredSlaLocation(61, SlaTier::CRITICAL_INFRASTRUCTURE_24_7, 41.3910, 2.1910);
$coldFarLocation = tieredSlaLocation(62, SlaTier::STANDARD, 41.3920, 2.1920);
$contractLocation = tieredSlaLocation(63, SlaTier::PRIORITY_CORPORATE, 41.3930, 2.1930);
$boundaryLocation = tieredSlaLocation(64, SlaTier::CRITICAL_INFRASTRUCTURE_24_7, 41.3940, 2.1940);
$beyondLocation = tieredSlaLocation(65, SlaTier::STANDARD, 41.3950, 2.1950);
$pausedLocation = tieredSlaLocation(66, SlaTier::PRIORITY_CORPORATE, 41.3960, 2.1960);
$preventiveLocation = tieredSlaLocation(67, SlaTier::STANDARD, 41.3970, 2.1970);

$route = $routeService->optimizeRoute([
    tieredSlaTask($coldFourLocation, 610, [
        'machine_type' => 'PERISHABLE_FOOD',
        'category' => 'PRODUCT_JAM',
        'urgency' => 'MEDIUM',
        'sla_due_at' => '2026-11-09T14:00:00+01:00',
    ]),
    tieredSlaTask($coldFarLocation, 620, [
        'machine_type' => 'PERISHABLE_FOOD',
        'category' => 'PRODUCT_JAM',
        'urgency' => 'LOW',
        'sla_due_at' => '2026-11-09T16:00:00+01:00',
    ]),
    tieredSlaTask($contractLocation, 630, [
        'machine_type' => 'SNACKS',
        'urgency' => 'HIGH',
        'sla_due_at' => '2026-11-09T11:00:00+01:00',
    ]),
    tieredSlaTask($boundaryLocation, 640, [
        'machine_type' => 'SNACKS',
        'urgency' => 'HIGH',
        'sla_due_at' => '2026-11-09T14:00:00+01:00',
    ]),
    tieredSlaTask($beyondLocation, 650, [
        'machine_type' => 'SNACKS',
        'urgency' => 'HIGH',
        'sla_due_at' => '2026-11-09T14:00:01+01:00',
    ]),
    tieredSlaTask($pausedLocation, 660, [
        'machine_type' => 'SNACKS',
        'urgency' => 'HIGH',
        'status' => 'PENDING_INFO',
        'paused_at' => '2026-11-09T09:00:00+01:00',
        'sla_due_at' => '2026-11-09T14:00:00+01:00',
    ]),
    tieredSlaTask($preventiveLocation, 670, [
        'type' => 'PREVENTIVE',
        'machine_type' => 'SNACKS',
        'urgency' => 'HIGH',
        'sla_due_at' => '2026-11-09T11:00:00+01:00',
    ]),
], $routeOrigin, $routeSettings, $routeReference);

$stopsByCode = [];
$criticalCodes = [];
foreach ($route->getStops() as $stop) {
    $stopsByCode[$stop->getLocation()->getSiteCode()] = $stop;
    if ($stop->getPriority() === StopPriority::CRITICAL) {
        $criticalCodes[] = $stop->getLocation()->getSiteCode();
    }
}

$assert(
    '6.1 La ruta coloca primero las paradas de frío y después las contractuales',
    $criticalCodes === ['SLA-61', 'SLA-62', 'SLA-63', 'SLA-64'],
    implode(', ', $criticalCodes)
);
$assert(
    '6.2 Una parada perecedera es crítica aunque su vencimiento supere las 4 h',
    $stopsByCode['SLA-62']->getPriority() === StopPriority::CRITICAL
        && $stopsByCode['SLA-62']->isColdChainCritical()
        && !$stopsByCode['SLA-62']->isContractualCritical()
        && $stopsByCode['SLA-62']->getCriticalReason() === 'COLD_CHAIN'
);
$assert(
    '6.3 La frontera de 4 h es inclusiva y se marca como contractual',
    $stopsByCode['SLA-64']->getPriority() === StopPriority::CRITICAL
        && $stopsByCode['SLA-64']->isContractualCritical()
        && !$stopsByCode['SLA-64']->isColdChainCritical()
        && $stopsByCode['SLA-64']->getCriticalReason() === 'CONTRACTUAL_SLA'
        && $stopsByCode['SLA-64']->getSlaTier() === SlaTier::CRITICAL_INFRASTRUCTURE_24_7
);
$assert(
    '6.4 Un segundo por encima de 4 h queda fuera del bloque crítico',
    $stopsByCode['SLA-65']->getPriority() === StopPriority::ORDINARY
        && !$stopsByCode['SLA-65']->isContractualCritical()
);
$assert(
    '6.5 Una pausa congela el reloj en paused_at y no promueve por el tiempo actual',
    $stopsByCode['SLA-66']->getPriority() === StopPriority::ORDINARY
        && !$stopsByCode['SLA-66']->isContractualCritical()
);
$assert(
    '6.6 Una orden preventiva no gobierna ni promueve la parada',
    $stopsByCode['SLA-67']->getPriority() === StopPriority::ORDINARY
        && !$stopsByCode['SLA-67']->isContractualCritical()
);

$fallbackLocation = tieredSlaLocation(68, SlaTier::PRIORITY_CORPORATE, 41.3980, 2.1980);
$fallbackRoute = $routeService->optimizeRoute([
    tieredSlaTask($fallbackLocation, 680, [
        'machine_type' => 'SNACKS',
        'urgency' => 'HIGH',
        'sla_target_minutes' => 120,
        'created_at' => '2026-11-09T08:00:00+01:00',
    ]),
], $routeOrigin, $routeSettings, $routeReference);
$fallbackStop = $fallbackRoute->getStops()[0];
$assert(
    '6.7 Sin sla_due_at, el vencimiento se compone desde created_at y sla_target_minutes',
    $fallbackStop->getPriority() === StopPriority::CRITICAL
        && $fallbackStop->isContractualCritical()
        && $fallbackStop->getCriticalReason() === 'CONTRACTUAL_SLA'
);

$multiLocation = tieredSlaLocation(69, SlaTier::CRITICAL_INFRASTRUCTURE_24_7, 41.3990, 2.1990);
$multiRoute = $routeService->optimizeRoute([
    tieredSlaTask($multiLocation, 690, [
        'machine_type' => 'PERISHABLE_FOOD',
        'urgency' => 'HIGH',
        'sla_due_at' => '2026-11-09T16:00:00+01:00',
    ]),
    tieredSlaTask($multiLocation, 691, [
        'machine_type' => 'SNACKS',
        'urgency' => 'HIGH',
        'sla_due_at' => '2026-11-09T11:00:00+01:00',
    ]),
    tieredSlaTask($multiLocation, 692, [
        'type' => 'PREVENTIVE',
        'machine_type' => 'SNACKS',
        'sla_due_at' => '2026-11-09T10:30:00+01:00',
    ]),
], $routeOrigin, $routeSettings, $routeReference);
$multiStop = $multiRoute->getStops()[0];
$assert(
    '6.8 En multitarea gobierna la correctiva más restrictiva y el preventivo no la degrada',
    $multiStop->getTotalTasks() === 3
        && $multiStop->getPriority() === StopPriority::CRITICAL
        && $multiStop->isColdChainCritical()
        && $multiStop->getCriticalReason() === 'COLD_CHAIN'
);

$coldWithoutUrgencyLocation = tieredSlaLocation(70, SlaTier::STANDARD, 41.4000, 2.2000);
$coldWithoutUrgencyRoute = $routeService->optimizeRoute([
    tieredSlaTask($coldWithoutUrgencyLocation, 700, [
        'machine_type' => 'PERISHABLE_FOOD',
        'category' => 'PRODUCT_JAM',
        'urgency' => null,
        'sla_due_at' => '2026-11-09T18:00:00+01:00',
    ]),
], $routeOrigin, $routeSettings, $routeReference);
$coldWithoutUrgencyStop = $coldWithoutUrgencyRoute->getStops()[0];
$assert(
    '6.9 El riesgo sanitario no depende de que la urgencia venga informada',
    $coldWithoutUrgencyStop->getPriority() === StopPriority::CRITICAL
        && $coldWithoutUrgencyStop->isColdChainCritical()
        && $coldWithoutUrgencyStop->getCriticalReason() === 'COLD_CHAIN'
);

$tieOlderLocation = tieredSlaLocation(71, SlaTier::CRITICAL_INFRASTRUCTURE_24_7, 41.4010, 2.2010);
$tieNewerLocation = tieredSlaLocation(72, SlaTier::CRITICAL_INFRASTRUCTURE_24_7, 41.4020, 2.2020);

// Dos paradas contractuales con vencimiento idéntico (12:00): gana la más antigua (07:30 vs 08:30)
$tieRouteAge = $routeService->optimizeRoute([
    tieredSlaTask($tieNewerLocation, 720, [
        'machine_type' => 'HOT_DRINKS',
        'urgency' => 'HIGH',
        'sla_due_at' => '2026-11-09T12:00:00+01:00',
        'created_at' => '2026-11-09T08:30:00+01:00',
    ]),
    tieredSlaTask($tieOlderLocation, 710, [
        'machine_type' => 'HOT_DRINKS',
        'urgency' => 'HIGH',
        'sla_due_at' => '2026-11-09T12:00:00+01:00',
        'created_at' => '2026-11-09T07:30:00+01:00',
    ]),
], $routeOrigin, $routeSettings, $routeReference);

$tieStopsAge = array_map(fn($s) => $s->getLocation()->getSiteCode(), $tieRouteAge->getStops());
$assert(
    '6.10 EARS 03.2: Con empate exacto de vencimiento, antepone la parada más antigua',
    $tieStopsAge[0] === 'SLA-71' && $tieStopsAge[1] === 'SLA-72',
    implode(', ', $tieStopsAge)
);

// Dos paradas contractuales con vencimiento idéntico (12:00) y creación idéntica (08:00): gana menor id de sede (73 vs 74)
$tieLocA = tieredSlaLocation(73, SlaTier::CRITICAL_INFRASTRUCTURE_24_7, 41.4030, 2.2030);
$tieLocB = tieredSlaLocation(74, SlaTier::CRITICAL_INFRASTRUCTURE_24_7, 41.4040, 2.2040);

$tieRouteId = $routeService->optimizeRoute([
    tieredSlaTask($tieLocB, 740, [
        'machine_type' => 'HOT_DRINKS',
        'urgency' => 'HIGH',
        'sla_due_at' => '2026-11-09T12:00:00+01:00',
        'created_at' => '2026-11-09T08:00:00+01:00',
    ]),
    tieredSlaTask($tieLocA, 730, [
        'machine_type' => 'HOT_DRINKS',
        'urgency' => 'HIGH',
        'sla_due_at' => '2026-11-09T12:00:00+01:00',
        'created_at' => '2026-11-09T08:00:00+01:00',
    ]),
], $routeOrigin, $routeSettings, $routeReference);

$tieStopsId = array_map(fn($s) => $s->getLocation()->getSiteCode(), $tieRouteId->getStops());
$assert(
    '6.11 EARS 03.2: Con empate exacto de vencimiento y antigüedad, antepone el menor identificador de sede',
    $tieStopsId[0] === 'SLA-73' && $tieStopsId[1] === 'SLA-74',
    implode(', ', $tieStopsId)
);

// ─────────────────────────────────────────────────────────────────────────────
// 7. Recomendador: escalado contractual 24/7 y respaldos deterministas
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 7. Recomendador: +60 contractual, cobertura y saturación (D4, QA 2) ---\n";

$slaSuggester = new TechnicianAssignmentSuggester(new GeoDistanceService());
$slaBase = ['base_latitude' => 41.3800, 'base_longitude' => 2.1600];
$offPrimary = tieredSlaTechnician(72, ['affinity' => 'PRIMARY', 'shift_status' => 'OFF_SHIFT']);
$offBackup = tieredSlaTechnician(73, ['affinity' => 'BACKUP', 'shift_status' => 'OFF_SHIFT']);
$onCall = tieredSlaTechnician(71, ['affinity' => 'NONE', 'is_on_call' => true, 'shift_status' => 'OFF_SHIFT']);

$contractual = $slaSuggester->getSuggestionsForIncident(
    tieredSlaIncident(),
    [$offPrimary, $offBackup, $onCall],
    $slaBase
);
$assert(
    '7.1 Sin cobertura en turno, la guardia 24/7 encabeza con +60 y motivo contractual',
    $contractual->recommendation['contractual_24_7_escalation_active'] === true
        && $contractual->recommendation['on_call_available'] === true
        && $contractual->recommendation['top_technician_id'] === 71
        && $contractual->rankedTechnicians[0]['score_breakdown']['contractual_24_7_on_call_bonus'] === 60
        && $contractual->rankedTechnicians[0]['current_status']['selection_reason'] === 'Escalado contractual 24/7'
);
$assert(
    '7.2 El DTO publica el perfil sellado y su objetivo, no recalcula desde la sede actual',
    $contractual->incident['sla_tier_snapshot'] === 'CRITICAL_INFRASTRUCTURE_24_7'
        && $contractual->incident['sla_tier_label'] === 'Infraestructura Crítica 24x7'
        && $contractual->incident['sla_target_minutes'] === 480
);

$freeBackup = tieredSlaTechnician(74, ['affinity' => 'BACKUP', 'shift_status' => 'ON_SHIFT']);
$covered = $slaSuggester->getSuggestionsForIncident(
    tieredSlaIncident(),
    [$offPrimary, $freeBackup, $onCall],
    $slaBase
);
$assert(
    '7.3 Un suplente en turno y libre evita despertar a la guardia fuera de turno',
    $covered->recommendation['contractual_24_7_escalation_active'] === false
        && $covered->recommendation['top_technician_id'] === 74
        && $covered->rankedTechnicians[0]['score_breakdown']['contractual_24_7_on_call_bonus'] === 0
);

$busyPrimary = tieredSlaTechnician(75, ['affinity' => 'PRIMARY', 'shift_status' => 'ON_SHIFT', 'active_incidents' => 2]);
$busyBackup = tieredSlaTechnician(76, ['affinity' => 'BACKUP', 'shift_status' => 'ON_SHIFT', 'active_incidents' => 1]);
$urgent = $slaSuggester->getSuggestionsForIncident(
    tieredSlaIncident(['sla_target_minutes' => 240]),
    [$busyPrimary, $busyBackup, $onCall],
    $slaBase
);
$assert(
    '7.4 Con objetivo de 240 min y zona ocupada, se activa el escalado contractual',
    $urgent->recommendation['contractual_24_7_escalation_active'] === true
        && $urgent->recommendation['top_technician_id'] === 71
        && $urgent->rankedTechnicians[0]['score_breakdown']['contractual_24_7_on_call_bonus'] === 60
);

$saturatedA = tieredSlaTechnician(77, ['affinity' => 'PRIMARY', 'shift_status' => 'ON_SHIFT', 'active_incidents' => 4]);
$saturatedB = tieredSlaTechnician(78, ['affinity' => 'BACKUP', 'shift_status' => 'ON_SHIFT', 'active_incidents' => 3]);
$saturated = $slaSuggester->getSuggestionsForIncident(
    tieredSlaIncident(['sla_target_minutes' => 480]),
    [$saturatedA, $saturatedB],
    $slaBase
);
$assert(
    '7.5 Con todo el cuadrante en >= 3 paradas, manda la menor carga viva',
    array_column($saturated->rankedTechnicians, 'technician_id') === [78, 77]
        && str_contains((string)$saturated->recommendation['explanation'], 'saturado')
);

$fallbackBusy = tieredSlaTechnician(79, ['affinity' => 'PRIMARY', 'shift_status' => 'OFF_SHIFT', 'active_incidents' => 5]);
$fallbackFree = tieredSlaTechnician(80, ['affinity' => 'BACKUP', 'shift_status' => 'OFF_SHIFT', 'active_incidents' => 2]);
$noCallFallback = $slaSuggester->getSuggestionsForIncident(
    tieredSlaIncident(['sla_target_minutes' => 240]),
    [$fallbackBusy, $fallbackFree],
    $slaBase
);
$assert(
    '7.6 Sin guardia disponible, el respaldo ordena por menor carga viva',
    $noCallFallback->recommendation['contractual_24_7_escalation_active'] === true
        && $noCallFallback->recommendation['on_call_available'] === false
        && $noCallFallback->recommendation['top_technician_id'] === 80
);

$nonCriticalTier = $slaSuggester->getSuggestionsForIncident(
    tieredSlaIncident(['sla_tier_snapshot' => 'STANDARD', 'sla_target_minutes' => 240]),
    [$offPrimary, $offBackup, $onCall],
    $slaBase
);
$assert(
    '7.7 Una sede no crítica no activa el escalado contractual',
    $nonCriticalTier->recommendation['contractual_24_7_escalation_active'] === false
);

$freePrimary = tieredSlaTechnician(81, ['affinity' => 'PRIMARY', 'shift_status' => 'ON_SHIFT']);
$freePrimaryResult = $slaSuggester->getSuggestionsForIncident(
    tieredSlaIncident(['sla_target_minutes' => 240]),
    [$freePrimary, $onCall],
    $slaBase
);
$assert(
    '7.8 Un titular en turno y libre cubre el aviso sin bono contractual',
    $freePrimaryResult->recommendation['contractual_24_7_escalation_active'] === false
        && $freePrimaryResult->recommendation['top_technician_id'] === 81
        && $freePrimaryResult->rankedTechnicians[0]['score_breakdown']['contractual_24_7_on_call_bonus'] === 0
);

$coldContractual = $slaSuggester->getSuggestionsForIncident(
    tieredSlaIncident([
        'priority' => 'CRITICAL',
        'machine_type' => 'PERISHABLE_FOOD',
        'sla_target_minutes' => 240,
    ]),
    [$busyPrimary, $onCall],
    $slaBase
);
$assert(
    '7.9 El escalado sanitario prevalece: +100 y el bono contractual queda en cero',
    $coldContractual->recommendation['is_cold_chain_escalation'] === true
        && $coldContractual->recommendation['contractual_24_7_escalation_active'] === false
        && $coldContractual->rankedTechnicians[0]['score_breakdown']['cold_chain_on_call_bonus'] === 100
        && $coldContractual->rankedTechnicians[0]['score_breakdown']['contractual_24_7_on_call_bonus'] === 0
);

$comboNonThermal = $slaSuggester->getSuggestionsForIncident(
    tieredSlaIncident(['machine_type' => 'COMBO', 'category' => 'PRODUCT_JAM', 'priority' => 'HIGH']),
    [$freePrimary],
    $slaBase
);
$assert(
    '7.10 Una COMBO no térmica de prioridad alta no se trata como riesgo de frío',
    $comboNonThermal->incident['is_cold_chain_risk'] === false
);

// ─────────────────────────────────────────────────────────────────────────────
// 8. Métricas: brechas reales, estado y exportación por sede
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 8. Métricas por sede y CSV (RF-06, D6, QA 3) ---\n";

$metricsService = new MetricsCalculationService(new TieredSlaMetricsRepository());
$metricsBreakdown = $metricsService->getBreakdown(MetricFilter::fromQueryParams(['period' => 'last_30_days']));
$criticalRow = $metricsBreakdown['by_location'][0];
$standardRow = $metricsBreakdown['by_location'][1];
$noDataRow = $metricsBreakdown['by_location'][2];
$assert(
    '8.1 El desglose por sede publica perfil, etiqueta, horas nominales y brechas',
    $criticalRow['sla_tier'] === 'CRITICAL_INFRASTRUCTURE_24_7'
        && $criticalRow['sla_tier_label'] === 'Infraestructura Crítica 24x7'
        && $criticalRow['sla_target_hours'] === 8.0
        && $criticalRow['sla_breaches'] === 2
);
$assert(
    '8.2 Una brecha real marca BREACHED aunque el MTTR esté por debajo del objetivo',
    $criticalRow['sla_status'] === 'BREACHED'
        && $standardRow['sla_status'] === 'COMPLIANT'
        && $noDataRow['sla_status'] === 'NO_DATA'
);
$assert(
    '8.3 Las horas nominales se derivan del perfil vigente, no de un 24 h fijo',
    $standardRow['sla_target_hours'] === 24.0
        && $noDataRow['sla_target_hours'] === 12.0
);

$csv = (new MetricsExportService())->exportMetricsCsv($metricsBreakdown);
$csvLines = preg_split('/\R/', trim($csv));
$csvHeader = str_getcsv(ltrim((string)($csvLines[0] ?? ''), "\xEF\xBB\xBF"));
$csvLocation = str_getcsv((string)($csvLines[1] ?? ''));
$assert(
    '8.4 El CSV incorpora Nivel SLA, Objetivo SLA (Horas), Brechas SLA y Estado SLA',
    array_slice($csvHeader, -4) === ['Nivel SLA', 'Objetivo SLA (Horas)', 'Brechas SLA', 'Estado SLA']
        && ($csvLocation[9] ?? null) === 'Infraestructura Crítica 24x7'
        && ($csvLocation[10] ?? null) === '8.0'
        && ($csvLocation[11] ?? null) === '2'
        && ($csvLocation[12] ?? null) === 'BREACHED'
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-SLA-05 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
