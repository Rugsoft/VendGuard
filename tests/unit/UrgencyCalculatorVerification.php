<?php

declare(strict_types=1);

/**
 * UrgencyCalculatorVerification
 * 
 * Verificación específica para la condición "Hecho cuando:" de la tarea T-06.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Service\UrgencyCalculator;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

echo "========================================================\n";
echo " VendGuard: Verificación de UrgencyCalculator (T-06)\n";
echo "========================================================\n\n";

$failures = 0;

// Caso 1: PERISHABLE_FOOD + TEMPERATURE_COLD => CRITICAL
$res1 = UrgencyCalculator::calculate('PERISHABLE_FOOD', 'TEMPERATURE_COLD');
if ($res1 === UrgencyLevel::CRITICAL && $res1->value === 'CRITICAL') {
    echo "1. calculate('PERISHABLE_FOOD', 'TEMPERATURE_COLD') => CRITICAL: [OK]\n";
} else {
    echo "1. calculate('PERISHABLE_FOOD', 'TEMPERATURE_COLD') => [FALLO] (Obtenido: {$res1->value})\n";
    $failures++;
}

// Caso 2: COLD_DRINKS + TEMPERATURE_COLD => MEDIUM
$res2 = UrgencyCalculator::calculate('COLD_DRINKS', 'TEMPERATURE_COLD');
if ($res2 === UrgencyLevel::MEDIUM && $res2->value === 'MEDIUM') {
    echo "2. calculate('COLD_DRINKS', 'TEMPERATURE_COLD') => MEDIUM: [OK]\n";
} else {
    echo "2. calculate('COLD_DRINKS', 'TEMPERATURE_COLD') => [FALLO] (Obtenido: {$res2->value})\n";
    $failures++;
}

// Caso 3: En PAYMENT_SYSTEM => HIGH
$res3 = UrgencyCalculator::calculate('COMBO', 'PAYMENT_SYSTEM');
if ($res3 === UrgencyLevel::HIGH && $res3->value === 'HIGH') {
    echo "3. calculate('COMBO', 'PAYMENT_SYSTEM') => HIGH: [OK]\n";
} else {
    echo "3. calculate('COMBO', 'PAYMENT_SYSTEM') => [FALLO] (Obtenido: {$res3->value})\n";
    $failures++;
}

// Caso 4: En OTHER => MEDIUM
$res4 = UrgencyCalculator::calculate('HOT_DRINKS', 'OTHER');
if ($res4 === UrgencyLevel::MEDIUM && $res4->value === 'MEDIUM') {
    echo "4. calculate('HOT_DRINKS', 'OTHER') => MEDIUM: [OK]\n";
} else {
    echo "4. calculate('HOT_DRINKS', 'OTHER') => [FALLO] (Obtenido: {$res4->value})\n";
    $failures++;
}

// Casos adicionales de robustez
$res5 = UrgencyCalculator::calculate('PERISHABLE_FOOD', 'ELECTRICAL_OFF');
if ($res5 === UrgencyLevel::CRITICAL) {
    echo "5. calculate('PERISHABLE_FOOD', 'ELECTRICAL_OFF') => CRITICAL (Mandato Art. II): [OK]\n";
} else {
    echo "5. Fallo en ELECTRICAL_OFF para perecederos: [FALLO]\n";
    $failures++;
}

$res6 = UrgencyCalculator::calculate('SNACKS', 'PRODUCT_JAM');
if ($res6 === UrgencyLevel::MEDIUM) {
    echo "6. calculate('SNACKS', 'PRODUCT_JAM') => MEDIUM: [OK]\n";
} else {
    echo "6. Fallo en PRODUCT_JAM: [FALLO]\n";
    $failures++;
}

echo "\n========================================================\n";
if ($failures === 0) {
    echo " TODAS LAS CONDICIONES DE T-06 CUMPLIDAS CON ÉXITO.\n";
    echo "========================================================\n";
    exit(0);
} else {
    echo " ERROR: Se detectaron {$failures} fallos en la verificación de T-06.\n";
    echo "========================================================\n";
    exit(1);
}
