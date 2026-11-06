<?php

declare(strict_types=1);

/**
 * SanitarySpoilageValidatorTest · T-SPOILAGE-05
 *
 * Suite unitaria del validador del Acta de Merma APPCC (Módulo 29). Certifica
 * que la puerta del dominio muerde exactamente donde la especificación manda,
 * sin base de datos ni red:
 *
 *   1. **Techo térmico por tipología (`QA 3`, Art. II):** `PERISHABLE_FOOD` y
 *      `COMBO` aceptan `4.0 °C` y rechazan `4.1 °C`; `COLD_DRINKS` acepta
 *      `8.0 °C` y rechaza `8.1 °C`; `HOT_DRINKS` y `SNACKS` sólo responden al
 *      rango físico `[-5.0, +35.0] °C`.
 *   2. **Rama de máquina vacía (`D3`, `C5`):** cero unidades exige confirmación
 *      expresa, autocompleta `SIN_STOCK_AFECTADO` y `NOT_APPLICABLE_EMPTY`, fija
 *      el coste en `0.00 €` y no admite una fotografía ausente.
 *   3. **Rama con stock destruido (`D2`, `QA 4`):** lotes de `3..500`
 *      caracteres con al menos un bloque alfanumérico real —`---` y `...` no
 *      identifican un lote—, método de destrucción real y baremo automático de
 *      `2.50 €/ud.`.
 *   4. **Rigor textual y ajuste financiero (`Art. V.1`, `C5`, `QA 4`):**
 *      observaciones y motivo del ajuste de `20..500` caracteres con tres
 *      bloques reales; el ajuste se bloquea en actas de cero unidades, importes
 *      fuera de `(0.00, 99999.99]` o idénticos al vigente.
 *   5. **Filtros de fecha (`QA 4`):** sólo fechas reales `YYYY-MM-DD` y un
 *      rango con inicio no posterior al fin.
 *   6. **Segregación de privacidad (`Art. V.4`):** `toPublicArray()` y el Acta
 *      Oficial A4 omiten los cuatro campos económicos; sólo
 *      `toCoordinatorArray()` los incorpora.
 *
 * Los rechazos se capturan por la excepción del contrato y se comprueba su
 * código y su estado HTTP: cualquier otro fallo se propaga y rompe la suite, no
 * se disfraza de rechazo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni red.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Exception\SanitarySpoilageValidationException;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\SanitarySpoilageRecord;
use VendGuard\Core\Service\SanitarySpoilageValidator;

echo "======================================================================\n";
echo " VENDGUARD · Suite unitaria · SanitarySpoilageValidatorTest (T-SPOILAGE-05)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

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

$rejectionOf = static function (callable $operation): ?SanitarySpoilageValidationException {
    try {
        $operation();
    } catch (SanitarySpoilageValidationException $exception) {
        return $exception;
    }

    return null;
};

$assertRejects = function (string $caseTitle, callable $operation, string $expectedCode) use ($assert, $rejectionOf): void {
    $exception = $rejectionOf($operation);
    $assert(
        $caseTitle . ' · ' . $expectedCode,
        $exception !== null
        && $exception->getErrorCode() === $expectedCode
        && $exception->getHttpStatusCode() === 422,
        $exception === null
            ? 'la operación se aceptó'
            : sprintf('código %s · HTTP %d', $exception->getErrorCode(), $exception->getHttpStatusCode())
    );
};

// ─────────────────────────────────────────────────────────────────────
// Escenario de referencia
// ─────────────────────────────────────────────────────────────────────
$evidencePhotoUrl = '/uploads/a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg';

$basePayload = [
    'discarded_units_count' => 14,
    'empty_machine_confirmed' => false,
    'affected_batch_codes' => 'LOTE-2026-11A (8 uds), LOTE-2026-11B (6 uds)',
    'cabin_temp_at_removal' => 11.4,
    'restored_stabilized_temp_c' => 3.2,
    'disposal_method' => 'ON_SITE_ORGANIC_SEALED',
    'sanitary_notes' => 'Retirada completa de 14 sándwiches y ensaladas expuestos a más de 4 h sin frío, '
        . 'desinfección con amonio cuaternario y verificación de sonda a 3.2 °C.',
];

$payload = static fn (array $overrides = []): array => array_merge($basePayload, $overrides);

$validate = static fn (
    array $overrides = [],
    MachineType|string $machineType = MachineType::PERISHABLE_FOOD,
    ?string $photoUrl = null
): array => SanitarySpoilageValidator::validateCreation(
    $payload($overrides),
    $machineType,
    $photoUrl ?? $evidencePhotoUrl
);

// ─────────────────────────────────────────────────────────────────────
// 1. Alta válida y baremo automático (D2)
// ─────────────────────────────────────────────────────────────────────
echo "--- 1. Alta válida y baremo automático (D2) ---\n";

$valid = $validate();
$assert(
    '1.1 Un acta con 14 unidades se valida y calcula 35.00 €',
    $valid['discarded_units_count'] === 14
    && $valid['empty_machine_confirmed'] === false
    && abs($valid['estimated_merchandise_loss_eur'] - 35.0) < 0.001,
    json_encode($valid, JSON_UNESCAPED_UNICODE)
);

$assert(
    '1.2 El alta fuerza empty_machine_confirmed a false cuando hay producto',
    $validate(['empty_machine_confirmed' => true])['empty_machine_confirmed'] === false
);

$assert(
    '1.3 El baremo se redondea a dos decimales',
    abs($validate(['discarded_units_count' => 3])['estimated_merchandise_loss_eur'] - 7.5) < 0.001
);

$assert(
    '1.4 Una máquina acepta el tipo como cadena del catálogo',
    $validate([], 'PERISHABLE_FOOD')['discarded_units_count'] === 14
);

$normalized = $validate([
    'affected_batch_codes' => '  LOTE-2026-11A   (8 uds),   LOTE-2026-11B (6 uds) ',
    'sanitary_notes' => '  Retirada   completa de 14 sándwiches y ensaladas expuestos a más de 4 h sin frío, '
        . 'desinfección con amonio cuaternario y verificación de sonda a 3.2 °C.  ',
]);
$assert(
    '1.5 Lotes y observaciones se normalizan antes de persistir',
    $normalized['affected_batch_codes'] === 'LOTE-2026-11A (8 uds), LOTE-2026-11B (6 uds)'
    && !str_contains($normalized['sanitary_notes'], '  '),
    'lotes: «' . $normalized['affected_batch_codes'] . '»'
);

// ─────────────────────────────────────────────────────────────────────
// 2. Techos térmicos por tipología (QA 3, Art. II)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 2. Techos térmicos de reapertura por tipología (QA 3) ---\n";

$assert(
    '2.1 PERISHABLE_FOOD acepta 4.0 °C',
    abs($validate(['restored_stabilized_temp_c' => 4.0], MachineType::PERISHABLE_FOOD)['restored_stabilized_temp_c'] - 4.0) < 0.001
);

$assertRejects(
    '2.2 PERISHABLE_FOOD rechaza 4.1 °C',
    static fn (): array => $validate(['restored_stabilized_temp_c' => 4.1], MachineType::PERISHABLE_FOOD),
    SanitarySpoilageValidationException::RESTORED_TEMPERATURE_EXCEEDS_SAFE_LIMIT
);

$assert(
    '2.3 COMBO acepta 4.0 °C',
    abs($validate(['restored_stabilized_temp_c' => 4.0], MachineType::COMBO)['restored_stabilized_temp_c'] - 4.0) < 0.001
);

$assertRejects(
    '2.4 COMBO rechaza 4.1 °C',
    static fn (): array => $validate(['restored_stabilized_temp_c' => 4.1], MachineType::COMBO),
    SanitarySpoilageValidationException::RESTORED_TEMPERATURE_EXCEEDS_SAFE_LIMIT
);

$assert(
    '2.5 COLD_DRINKS acepta 8.0 °C',
    abs($validate(['restored_stabilized_temp_c' => 8.0], MachineType::COLD_DRINKS)['restored_stabilized_temp_c'] - 8.0) < 0.001
);

$assertRejects(
    '2.6 COLD_DRINKS rechaza 8.1 °C',
    static fn (): array => $validate(['restored_stabilized_temp_c' => 8.1], MachineType::COLD_DRINKS),
    SanitarySpoilageValidationException::RESTORED_TEMPERATURE_EXCEEDS_SAFE_LIMIT
);

$assert(
    '2.7 HOT_DRINKS acepta 22.0 °C sin techo de refrigeración',
    abs($validate([
        'cabin_temp_at_removal' => 22.0,
        'restored_stabilized_temp_c' => 22.0,
    ], MachineType::HOT_DRINKS)['restored_stabilized_temp_c'] - 22.0) < 0.001
);

$assert(
    '2.8 SNACKS acepta 22.0 °C sin techo de refrigeración',
    abs($validate([
        'cabin_temp_at_removal' => 22.0,
        'restored_stabilized_temp_c' => 22.0,
    ], MachineType::SNACKS)['restored_stabilized_temp_c'] - 22.0) < 0.001
);

$assertRejects(
    '2.9 HOT_DRINKS rechaza una lectura fuera del rango físico',
    static fn (): array => $validate(['restored_stabilized_temp_c' => 35.1], MachineType::HOT_DRINKS),
    SanitarySpoilageValidationException::INVALID_TEMPERATURE_RANGE
);

$assert(
    '2.10 El rango físico acepta sus dos extremos -5.0 y 35.0 °C',
    abs($validate(['cabin_temp_at_removal' => -5.0, 'restored_stabilized_temp_c' => 35.0], MachineType::SNACKS)['cabin_temp_at_removal'] + 5.0) < 0.001
);

$assertRejects(
    '2.11 Una temperatura de retirada por debajo de -5.0 °C se rechaza',
    static fn (): array => $validate(['cabin_temp_at_removal' => -5.1]),
    SanitarySpoilageValidationException::INVALID_TEMPERATURE_RANGE
);

$assertRejects(
    '2.12 Una temperatura no numérica se rechaza',
    static fn (): array => $validate(['cabin_temp_at_removal' => 'frío']),
    SanitarySpoilageValidationException::INVALID_TEMPERATURE_RANGE
);

$assertRejects(
    '2.13 Una temperatura de reapertura ausente se rechaza',
    static fn (): array => SanitarySpoilageValidator::validateCreation(
        [
            'discarded_units_count' => 14,
            'affected_batch_codes' => 'LOTE-2026-11A',
            'cabin_temp_at_removal' => 11.4,
            'disposal_method' => 'ON_SITE_ORGANIC_SEALED',
            'sanitary_notes' => 'Retirada completa del producto y desinfección de la cabina afectada.',
        ],
        MachineType::PERISHABLE_FOOD,
        $evidencePhotoUrl
    ),
    SanitarySpoilageValidationException::INVALID_TEMPERATURE_RANGE
);

// ─────────────────────────────────────────────────────────────────────
// 3. Unidades y ramas del acta (D3, C5)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 3. Unidades y ramas del acta (D3, C5) ---\n";

$assertRejects(
    '3.1 Una unidad negativa se rechaza',
    static fn (): array => $validate(['discarded_units_count' => -1]),
    SanitarySpoilageValidationException::INVALID_DISCARDED_UNITS
);

$assertRejects(
    '3.2 Más de 500 unidades se rechazan',
    static fn (): array => $validate(['discarded_units_count' => 501]),
    SanitarySpoilageValidationException::INVALID_DISCARDED_UNITS
);

$assertRejects(
    '3.3 Una fracción de unidad se rechaza',
    static fn (): array => $validate(['discarded_units_count' => 14.5]),
    SanitarySpoilageValidationException::INVALID_DISCARDED_UNITS
);

$assertRejects(
    '3.4 Un texto no numérico en unidades se rechaza',
    static fn (): array => $validate(['discarded_units_count' => 'catorce']),
    SanitarySpoilageValidationException::INVALID_DISCARDED_UNITS
);

$assert(
    '3.5 500 unidades es el máximo aceptado y calcula 1 250.00 €',
    abs($validate(['discarded_units_count' => 500])['estimated_merchandise_loss_eur'] - 1250.0) < 0.001
);

$empty = $validate([
    'discarded_units_count' => 0,
    'empty_machine_confirmed' => true,
    'affected_batch_codes' => 'texto que no debe persistirse',
    'disposal_method' => 'AUTHORIZED_WASTE_MANAGER',
]);
$assert(
    '3.6 La máquina vacía autocompleta lotes, método y coste',
    $empty['discarded_units_count'] === 0
    && $empty['empty_machine_confirmed'] === true
    && $empty['affected_batch_codes'] === 'SIN_STOCK_AFECTADO'
    && $empty['disposal_method'] === 'NOT_APPLICABLE_EMPTY'
    && $empty['estimated_merchandise_loss_eur'] === 0.0,
    json_encode($empty, JSON_UNESCAPED_UNICODE)
);

$assertRejects(
    '3.7 Cero unidades sin confirmación expresa se rechaza',
    static fn (): array => $validate(['discarded_units_count' => 0, 'empty_machine_confirmed' => false]),
    SanitarySpoilageValidationException::EMPTY_MACHINE_CONFIRMATION_REQUIRED
);

$assertRejects(
    '3.8 Cero unidades sin la clave de confirmación se rechaza',
    static fn (): array => SanitarySpoilageValidator::validateCreation(
        [
            'discarded_units_count' => 0,
            'affected_batch_codes' => 'SIN_STOCK_AFECTADO',
            'cabin_temp_at_removal' => 9.4,
            'restored_stabilized_temp_c' => 3.2,
            'disposal_method' => 'NOT_APPLICABLE_EMPTY',
            'sanitary_notes' => 'Cabina verificada sin stock afectado tras la inspección visual y la lectura de sonda.',
        ],
        MachineType::COMBO,
        $evidencePhotoUrl
    ),
    SanitarySpoilageValidationException::EMPTY_MACHINE_CONFIRMATION_REQUIRED
);

// ─────────────────────────────────────────────────────────────────────
// 4. Evidencia fotográfica y observaciones (D3, Art. V.1, Art. V.5)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 4. Evidencia fotográfica y observaciones (Art. V.1, V.5) ---\n";

$assertRejects(
    '4.1 Una fotografía vacía se rechaza',
    static fn (): array => $validate([], MachineType::PERISHABLE_FOOD, ''),
    SanitarySpoilageValidationException::EVIDENCE_PHOTO_REQUIRED
);

$assertRejects(
    '4.2 Una fotografía de sólo espacios se rechaza',
    static fn (): array => $validate([], MachineType::PERISHABLE_FOOD, '    '),
    SanitarySpoilageValidationException::EVIDENCE_PHOTO_REQUIRED
);

$assertRejects(
    '4.3 Una referencia de fotografía demasiado corta se rechaza',
    static fn (): array => $validate([], MachineType::PERISHABLE_FOOD, 'abc'),
    SanitarySpoilageValidationException::EVIDENCE_PHOTO_REQUIRED
);

$assertRejects(
    '4.4 La fotografía también es obligatoria en la máquina vacía',
    static fn (): array => $validate(['discarded_units_count' => 0, 'empty_machine_confirmed' => true], MachineType::COMBO, ''),
    SanitarySpoilageValidationException::EVIDENCE_PHOTO_REQUIRED
);

$assert(
    '4.5 Una fotografía válida se acepta en la rama con producto y en la vacía',
    $validate()['discarded_units_count'] === 14
    && $validate(['discarded_units_count' => 0, 'empty_machine_confirmed' => true])['discarded_units_count'] === 0
);

$assertRejects(
    '4.6 Observaciones de 19 caracteres se rechazan (Art. V.1)',
    static fn (): array => $validate(['sanitary_notes' => str_repeat('a', 19)]),
    SanitarySpoilageValidationException::INVALID_SANITARY_NOTES
);

$assertRejects(
    '4.7 Observaciones de 1001 caracteres se rechazan',
    static fn (): array => $validate(['sanitary_notes' => str_repeat('a', 1001)]),
    SanitarySpoilageValidationException::INVALID_SANITARY_NOTES
);

$assertRejects(
    '4.8 El relleno de signos sin bloques reales se rechaza (Art. V.1)',
    static fn (): array => $validate(['sanitary_notes' => str_repeat('.', 30)]),
    SanitarySpoilageValidationException::INVALID_SANITARY_NOTES
);

$assertRejects(
    '4.9 Menos de tres bloques alfanuméricos se rechazan',
    static fn (): array => $validate(['sanitary_notes' => 'Lote retirado............']),
    SanitarySpoilageValidationException::INVALID_SANITARY_NOTES
);

$assert(
    '4.10 Veinte caracteres con tres bloques reales se aceptan',
    $validate(['sanitary_notes' => 'Lote 11A retirado ya'])['sanitary_notes'] === 'Lote 11A retirado ya'
);

// ─────────────────────────────────────────────────────────────────────
// 5. Lotes y método de destrucción (QA 4, D3)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 5. Lotes y método de destrucción (QA 4, D3) ---\n";

$assertRejects(
    '5.1 El lote «---» sin bloque alfanumérico se rechaza',
    static fn (): array => $validate(['affected_batch_codes' => '---']),
    SanitarySpoilageValidationException::INVALID_BATCH_CODES
);

$assertRejects(
    '5.2 El lote «...» sin bloque alfanumérico se rechaza',
    static fn (): array => $validate(['affected_batch_codes' => '...']),
    SanitarySpoilageValidationException::INVALID_BATCH_CODES
);

$assertRejects(
    '5.3 Un lote de dos caracteres se rechaza',
    static fn (): array => $validate(['affected_batch_codes' => 'AB']),
    SanitarySpoilageValidationException::INVALID_BATCH_CODES
);

$assertRejects(
    '5.4 Un lote de 501 caracteres se rechaza',
    static fn (): array => $validate(['affected_batch_codes' => str_repeat('A', 501)]),
    SanitarySpoilageValidationException::INVALID_BATCH_CODES
);

$assertRejects(
    '5.5 Bloques de un solo carácter separados por signos se rechazan',
    static fn (): array => $validate(['affected_batch_codes' => 'A-1']),
    SanitarySpoilageValidationException::INVALID_BATCH_CODES
);

$assert(
    '5.6 Un lote con un bloque alfanumérico de dos caracteres se acepta',
    $validate(['affected_batch_codes' => 'AB-1'])['affected_batch_codes'] === 'AB-1'
);

$assertRejects(
    '5.7 El método NOT_APPLICABLE_EMPTY con unidades se rechaza',
    static fn (): array => $validate(['disposal_method' => 'NOT_APPLICABLE_EMPTY']),
    SanitarySpoilageValidationException::INVALID_DISPOSAL_METHOD
);

$assertRejects(
    '5.8 Un método inventado se rechaza',
    static fn (): array => $validate(['disposal_method' => 'INCINERACION_CASERA']),
    SanitarySpoilageValidationException::INVALID_DISPOSAL_METHOD
);

$assertRejects(
    '5.9 Un método ausente se rechaza cuando hay unidades',
    static fn (): array => SanitarySpoilageValidator::validateCreation(
        [
            'discarded_units_count' => 14,
            'affected_batch_codes' => 'LOTE-2026-11A',
            'cabin_temp_at_removal' => 11.4,
            'restored_stabilized_temp_c' => 3.2,
            'sanitary_notes' => 'Retirada completa del producto y desinfección de la cabina afectada.',
        ],
        MachineType::PERISHABLE_FOOD,
        $evidencePhotoUrl
    ),
    SanitarySpoilageValidationException::INVALID_DISPOSAL_METHOD
);

$assert(
    '5.10 El método se normaliza a mayúsculas',
    $validate(['disposal_method' => ' return_to_central_biohazard '])['disposal_method'] === 'RETURN_TO_CENTRAL_BIOHAZARD'
);

// ─────────────────────────────────────────────────────────────────────
// 6. Ajuste financiero de Coordinación (C5, QA 4)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 6. Ajuste financiero de Coordinación (C5, QA 4) ---\n";

$adjustmentReason = 'Ajuste según albarán de carga del lote LOTE-2026-11A compuesto por 14 ensaladas gourmet.';

$assertRejects(
    '6.1 Un acta de cero unidades no admite ajuste (C5)',
    static fn (): array => SanitarySpoilageValidator::validateLossAdjustment(0, 0.0, 10.0, $adjustmentReason),
    SanitarySpoilageValidationException::EMPTY_MACHINE_LOSS_CANNOT_BE_ADJUSTED
);

$assertRejects(
    '6.2 Un importe de 0.00 € se rechaza con unidades (C5)',
    static fn (): array => SanitarySpoilageValidator::validateLossAdjustment(14, 35.0, 0.00, $adjustmentReason),
    SanitarySpoilageValidationException::INVALID_LOSS_ESTIMATE_AMOUNT
);

$assertRejects(
    '6.3 Un importe negativo se rechaza',
    static fn (): array => SanitarySpoilageValidator::validateLossAdjustment(14, 35.0, -5.0, $adjustmentReason),
    SanitarySpoilageValidationException::INVALID_LOSS_ESTIMATE_AMOUNT
);

$assertRejects(
    '6.4 Un importe superior a 99 999.99 € se rechaza',
    static fn (): array => SanitarySpoilageValidator::validateLossAdjustment(14, 35.0, 100000.00, $adjustmentReason),
    SanitarySpoilageValidationException::INVALID_LOSS_ESTIMATE_AMOUNT
);

$assertRejects(
    '6.5 Un importe no numérico se rechaza',
    static fn (): array => SanitarySpoilageValidator::validateLossAdjustment(14, 35.0, 'cincuenta', $adjustmentReason),
    SanitarySpoilageValidationException::INVALID_LOSS_ESTIMATE_AMOUNT
);

$assertRejects(
    '6.6 Un importe idéntico al vigente se rechaza (QA 4)',
    static fn (): array => SanitarySpoilageValidator::validateLossAdjustment(14, 35.0, 35.00, $adjustmentReason),
    SanitarySpoilageValidationException::IDENTICAL_LOSS_ESTIMATE
);

$assertRejects(
    '6.7 Una diferencia menor a medio céntimo también se considera idéntica',
    static fn (): array => SanitarySpoilageValidator::validateLossAdjustment(14, 35.0, 35.004, $adjustmentReason),
    SanitarySpoilageValidationException::IDENTICAL_LOSS_ESTIMATE
);

$assertRejects(
    '6.8 Un motivo de 19 caracteres se rechaza (Art. V.1)',
    static fn (): array => SanitarySpoilageValidator::validateLossAdjustment(14, 35.0, 48.50, str_repeat('a', 19)),
    SanitarySpoilageValidationException::INVALID_LOSS_ADJUSTMENT_REASON
);

$assertRejects(
    '6.9 Un motivo largo sin tres bloques reales se rechaza',
    static fn (): array => SanitarySpoilageValidator::validateLossAdjustment(14, 35.0, 48.50, 'Ajuste....................'),
    SanitarySpoilageValidationException::INVALID_LOSS_ADJUSTMENT_REASON
);

$adjusted = SanitarySpoilageValidator::validateLossAdjustment(14, 35.0, 48.50, '  ' . $adjustmentReason . '  ');
$assert(
    '6.10 Un ajuste válido devuelve importe redondeado y motivo normalizado',
    abs($adjusted['estimated_merchandise_loss_eur'] - 48.5) < 0.001
    && $adjusted['loss_adjustment_reason'] === $adjustmentReason,
    json_encode($adjusted, JSON_UNESCAPED_UNICODE)
);

$upperBound = SanitarySpoilageValidator::validateLossAdjustment(14, 35.0, 99999.99, $adjustmentReason);
$assert(
    '6.11 El importe máximo 99 999.99 € se acepta',
    abs($upperBound['estimated_merchandise_loss_eur'] - 99999.99) < 0.001
);

// ─────────────────────────────────────────────────────────────────────
// 7. Filtros de fecha (QA 4)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 7. Filtros de fecha (QA 4) ---\n";

$assert(
    '7.1 Un rango válido y los nulos se aceptan',
    (static function (): bool {
        SanitarySpoilageValidator::validateDateRange('2026-01-01', '2026-12-31');
        SanitarySpoilageValidator::validateDateRange(null, null);
        SanitarySpoilageValidator::validateDateRange('2026-05-05', null);

        return true;
    })()
);

$assertRejects(
    '7.2 Un rango con inicio posterior al fin se rechaza',
    static fn (): mixed => SanitarySpoilageValidator::validateDateRange('2026-12-31', '2026-01-01'),
    SanitarySpoilageValidationException::INVALID_DATE_RANGE
);

$assertRejects(
    '7.3 Una fecha con formato incorrecto se rechaza',
    static fn (): mixed => SanitarySpoilageValidator::validateDateRange('31/12/2026', null),
    SanitarySpoilageValidationException::INVALID_DATE_RANGE
);

$assertRejects(
    '7.4 Una fecha real inexistente se rechaza',
    static fn (): mixed => SanitarySpoilageValidator::validateDateRange('2026-02-30', null),
    SanitarySpoilageValidationException::INVALID_DATE_RANGE
);

// ─────────────────────────────────────────────────────────────────────
// 8. Segregación de serialización (Art. V.4, C4)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 8. Segregación de serialización (Art. V.4, C4) ---\n";

$record = new SanitarySpoilageRecord(
    null,
    'ACTA-MERMA-2026-0001',
    7,
    null,
    null,
    2,
    2,
    SanitarySpoilageRecord::QUARANTINE_ORIGIN_BIOLOGICAL_CLOCK,
    14,
    false,
    'LOTE-2026-11A (8 uds), LOTE-2026-11B (6 uds)',
    11.4,
    3.2,
    SanitarySpoilageRecord::DISPOSAL_METHOD_ON_SITE_ORGANIC_SEALED,
    $evidencePhotoUrl,
    $basePayload['sanitary_notes'],
    35.0,
    9,
    $adjustmentReason,
    'CERT-2026-0001',
    '2026-11-05 18:10:00',
    '2026-11-05 18:10:00',
    ['machine_code' => 'VM-MAD-002', 'machine_model' => 'Necta Samba Combo', 'location_name' => 'Hospital Universitario Central'],
    ['name' => 'Carlos Gómez', 'operator_code' => 'OP-0042'],
    'Coordinación',
    'Marta Ruiz'
);

$public = $record->toPublicArray();
$coordinator = $record->toCoordinatorArray();
$official = $record->toOfficialActArray();

$financialKeys = [
    'estimated_merchandise_loss_eur',
    'loss_adjusted_by',
    'loss_adjusted_by_name',
    'loss_adjustment_reason',
];

$assert(
    '8.1 toPublicArray omite los cuatro campos económicos',
    array_intersect($financialKeys, array_keys($public)) === []
    && $public['record_code'] === 'ACTA-MERMA-2026-0001'
    && $public['machine_code'] === 'VM-MAD-002'
    && $public['technician_name'] === 'Carlos Gómez',
    json_encode(array_keys($public), JSON_UNESCAPED_UNICODE)
);

$assert(
    '8.2 toCoordinatorArray incorpora los cuatro campos económicos',
    array_intersect($financialKeys, array_keys($coordinator)) === $financialKeys
    && abs($coordinator['estimated_merchandise_loss_eur'] - 35.0) < 0.001
    && $coordinator['loss_adjusted_by'] === 9
    && $coordinator['loss_adjusted_by_name'] === 'Marta Ruiz'
    && $coordinator['loss_adjustment_reason'] === $adjustmentReason,
    json_encode($coordinator, JSON_UNESCAPED_UNICODE)
);

$assert(
    '8.3 El Acta Oficial A4 no contiene ningún campo económico',
    array_intersect($financialKeys, array_keys($official)) === []
    && !str_contains((string)json_encode($official), 'merchandise')
    && !str_contains((string)json_encode($official), 'loss_adjusted')
    && $official['document_title'] === SanitarySpoilageRecord::OFFICIAL_ACT_TITLE
    && $official['legal_notice'] === SanitarySpoilageRecord::OFFICIAL_ACT_LEGAL_NOTICE,
    json_encode(array_keys($official), JSON_UNESCAPED_UNICODE)
);

$assert(
    '8.4 El acta oficial etiqueta origen y método en castellano',
    $official['quarantine_origin_label'] === 'Reloj biológico > 4 h (Avería)'
    && $official['disposal_method_label'] === 'Destrucción e inutilización en contenedor sellado in situ'
);

$emptyRecord = new SanitarySpoilageRecord(
    null,
    'ACTA-MERMA-2026-0003',
    8,
    null,
    null,
    2,
    2,
    SanitarySpoilageRecord::QUARANTINE_ORIGIN_BIOLOGICAL_CLOCK,
    0,
    true,
    SanitarySpoilageValidator::EMPTY_BATCH_CODES,
    18.6,
    3.0,
    SanitarySpoilageRecord::DISPOSAL_METHOD_NOT_APPLICABLE_EMPTY,
    $evidencePhotoUrl,
    'Máquina vacía verificada, sin género perecedero expuesto; cabina higienizada y fotografiada antes de reabrir.',
    0.0,
    null,
    null,
    'CERT-2026-0003'
);
$emptyOfficial = $emptyRecord->toOfficialActArray();
$assert(
    '8.4-bis El acta vacía conserva el token y usa la etiqueta de método aprobada',
    $emptyOfficial['affected_batch_codes'] === 'SIN_STOCK_AFECTADO'
    && $emptyOfficial['disposal_method_label'] === 'No aplicable (máquina vacía)'
);

$assert(
    '8.5 La serialización JSON por defecto también oculta el coste',
    !str_contains((string)json_encode($record), 'merchandise')
    && !str_contains((string)json_encode($record), 'loss_adjusted')
);

$copy = $record->withId(42);
$assert(
    '8.6 withId devuelve una copia con identidad sin mutar la original',
    $record->getId() === null && $copy->getId() === 42
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-SPOILAGE-05 (validador del acta) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
