<?php

declare(strict_types=1);

/**
 * UrgencyCalculatorTest
 * 
 * Suite de pruebas unitarias para el calculador de severidad de incidencias (RF-03 / T-07).
 * Valida los 6 casos de prueba de severidad definidos en la especificación funcional y el plan técnico.
 * 
 * Dogma Vanilla: Ejecutable nativamente sin PHPUnit ni dependencias de terceros.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Service\UrgencyCalculator;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

echo "====================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - UrgencyCalculatorTest (T-07)\n";
echo "====================================================================\n\n";

$assertions = 0;
$failures = 0;

/**
 * Función auxiliar para verificar aserciones unitarias con reporte formateado.
 *
 * @param string $caseTitle Título descriptivo del caso.
 * @param bool $condition Condición booleana esperada.
 * @param string $message Mensaje de error si falla.
 */
$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
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

// =====================================================================
// CASO 1: EARS 3.2 - Fallo de frío en alimentos perecederos (Mandato Sanitario)
// =====================================================================
echo "--- Caso 1: Pérdida de frío en PERISHABLE_FOOD (Riesgo Alimentario) ---\n";

$res1Enum = UrgencyCalculator::calculate(MachineType::PERISHABLE_FOOD, IncidentCategory::TEMPERATURE_COLD);
$res1Str = UrgencyCalculator::calculate('PERISHABLE_FOOD', 'TEMPERATURE_COLD');
$res1Val = UrgencyCalculator::calculateValue('PERISHABLE_FOOD', 'TEMPERATURE_COLD');

$assert(
    "1.1 Entrada con Enums devuelve UrgencyLevel::CRITICAL",
    $res1Enum === UrgencyLevel::CRITICAL,
    "Esperado CRITICAL, obtenido {$res1Enum->value}"
);
$assert(
    "1.2 Entrada con Strings devuelve UrgencyLevel::CRITICAL",
    $res1Str === UrgencyLevel::CRITICAL,
    "Esperado CRITICAL, obtenido {$res1Str->value}"
);
$assert(
    "1.3 calculateValue() devuelve string 'CRITICAL'",
    $res1Val === 'CRITICAL',
    "Esperado 'CRITICAL', obtenido '{$res1Val}'"
);

// =====================================================================
// CASO 2: EARS 3.3 - Fallo de frío en bebidas frías o calientes
// =====================================================================
echo "\n--- Caso 2: Pérdida de frío en COLD_DRINKS / HOT_DRINKS (No perecederos) ---\n";

$res2Cold = UrgencyCalculator::calculate(MachineType::COLD_DRINKS, IncidentCategory::TEMPERATURE_COLD);
$res2Hot = UrgencyCalculator::calculate('HOT_DRINKS', 'TEMPERATURE_COLD');

$assert(
    "2.1 Pérdida de frío en COLD_DRINKS asigna UrgencyLevel::MEDIUM",
    $res2Cold === UrgencyLevel::MEDIUM,
    "Esperado MEDIUM, obtenido {$res2Cold->value}"
);
$assert(
    "2.2 Pérdida de frío en HOT_DRINKS asigna UrgencyLevel::MEDIUM",
    $res2Hot === UrgencyLevel::MEDIUM,
    "Esperado MEDIUM, obtenido {$res2Hot->value}"
);

// =====================================================================
// CASO 3: EARS 3.4 - Fallo en sistemas de pago / monederos / datáfonos
// =====================================================================
echo "\n--- Caso 3: Fallo en medios de pago (Bloqueo comercial de venta) ---\n";

$res3Combo = UrgencyCalculator::calculate(MachineType::COMBO, IncidentCategory::PAYMENT_SYSTEM);
$res3Snack = UrgencyCalculator::calculate('SNACKS', 'PAYMENT_SYSTEM');

$assert(
    "3.1 Fallo de pago en COMBO asigna UrgencyLevel::HIGH",
    $res3Combo === UrgencyLevel::HIGH,
    "Esperado HIGH, obtenido {$res3Combo->value}"
);
$assert(
    "3.2 Fallo de pago en SNACKS asigna UrgencyLevel::HIGH",
    $res3Snack === UrgencyLevel::HIGH,
    "Esperado HIGH, obtenido {$res3Snack->value}"
);

// =====================================================================
// CASO 4: EARS 3.5 - Atasco mecánico de producto en espiral
// =====================================================================
echo "\n--- Caso 4: Atasco de producto en espiral (Afectación parcial) ---\n";

$res4 = UrgencyCalculator::calculate(MachineType::SNACKS, IncidentCategory::PRODUCT_JAM);
$res4Str = UrgencyCalculator::calculate('COMBO', 'PRODUCT_JAM');

$assert(
    "4.1 Atasco en SNACKS asigna UrgencyLevel::MEDIUM",
    $res4 === UrgencyLevel::MEDIUM,
    "Esperado MEDIUM, obtenido {$res4->value}"
);
$assert(
    "4.2 Atasco en COMBO asigna UrgencyLevel::MEDIUM",
    $res4Str === UrgencyLevel::MEDIUM,
    "Esperado MEDIUM, obtenido {$res4Str->value}"
);

// =====================================================================
// CASO 5: EARS 3.2 / Art. II - Máquina apagada / Fallo de suministro eléctrico
// =====================================================================
echo "\n--- Caso 5: Máquina apagada / ELECTRICAL_OFF ---\n";

$res5Perishable = UrgencyCalculator::calculate('PERISHABLE_FOOD', 'ELECTRICAL_OFF');
$res5NonPerishable = UrgencyCalculator::calculate('SNACKS', 'ELECTRICAL_OFF');

$assert(
    "5.1 Máquina PERISHABLE_FOOD apagada asigna UrgencyLevel::CRITICAL (Pérdida de frío inminente)",
    $res5Perishable === UrgencyLevel::CRITICAL,
    "Esperado CRITICAL, obtenido {$res5Perishable->value}"
);
$assert(
    "5.2 Máquina no perecedera (SNACKS) apagada asigna UrgencyLevel::HIGH (Detiene venta)",
    $res5NonPerishable === UrgencyLevel::HIGH,
    "Esperado HIGH, obtenido {$res5NonPerishable->value}"
);

// =====================================================================
// CASO 6: EARS 3.6 & 3.7 - Fallo cosmético / Iluminación y Categoría OTHER
// =====================================================================
echo "\n--- Caso 6: Desperfectos cosméticos y Categoría 'OTHER' ---\n";

$res6Cosmetic = UrgencyCalculator::calculate('HOT_DRINKS', 'COSMETIC_LIGHTING');
$res6Other = UrgencyCalculator::calculate(MachineType::HOT_DRINKS, IncidentCategory::OTHER);
$res6Default = UrgencyCalculator::calculate('UNKNOWN_MACHINE', 'UNKNOWN_CATEGORY');

$assert(
    "6.1 Iluminación cosmética (COSMETIC_LIGHTING) asigna UrgencyLevel::LOW",
    $res6Cosmetic === UrgencyLevel::LOW,
    "Esperado LOW, obtenido {$res6Cosmetic->value}"
);
$assert(
    "6.2 Categoría OTHER asigna UrgencyLevel::MEDIUM",
    $res6Other === UrgencyLevel::MEDIUM,
    "Esperado MEDIUM, obtenido {$res6Other->value}"
);
$assert(
    "6.3 Categoría desconocida asigna prudencialmente UrgencyLevel::MEDIUM",
    $res6Default === UrgencyLevel::MEDIUM,
    "Esperado MEDIUM, obtenido {$res6Default->value}"
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n====================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-07 CUMPLIDA CON ÉXITO.\n";
    echo "====================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
    echo "====================================================================\n";
    exit(1);
}
