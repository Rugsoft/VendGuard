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

use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\SlaTier;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Core\Domain\ValueObject\ZoneAffinityType;
use VendGuard\Core\Service\SlaTargetCalculator;
use VendGuard\Core\Service\TechnicianWorkloadScorer;

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
