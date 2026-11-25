<?php

declare(strict_types=1);

/**
 * DexSelectionMatcherAndValidatorTest · T-DEX-05
 *
 * Certifica el emparejamiento `PA1`/`PA2` con carriles y las guardas puras de
 * importación, deltas, anulación y CSV del Módulo 39 (RF-03.1, RF-04.1,
 * RF-04.2, RF-07.2, C1, C2, C4, C6, QA-1, QA-2, Art. II.2 y Art. V.1).
 *
 * QUÉ SE MIDE, Y POR QUÉ ASÍ
 *
 *   1. **Emparejamiento en dos pasos (C4).** Exacta, ceros a la izquierda,
 *      ambigüedad por colisión entre dos `PA1`, ambigüedad por carriles
 *      duplicados, ausencia, carriles inactivos y café informativo. El matcher
 *      nunca inventa un carril.
 *   2. **Perímetro y sanidad (RF-03.1, C2, QA-1).** Reponedor fuera de zona
 *      (`403`), Reponedor en cuarentena o con avería térmica abierta (`409`),
 *      permitido en `EXPIRED`; Técnico y Coordinación conservan el acceso
 *      forense.
 *   3. **Cadena de deltas (RF-04.1, C1, QA-2).** Base inicial, resta normal,
 *      retroceso con parciales de reseteo, retroceso sin parciales
 *      (`UNAVAILABLE`), redondeo a dos decimales y `out_of_order_sync`.
 *   4. **Anulación y CSV (RF-07.2, C6, Art. V.1).** Motivo de 20 a 500
 *      caracteres con tres bloques alfanuméricos, estado terminal y
 *      neutralización de fórmulas sin mutar el contenido legítimo.
 *
 * Uso:
 *   php tests/unit/DexSelectionMatcherAndValidatorTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Enum\DexSelectionMatchStatus;
use VendGuard\Core\Domain\Exception\DexAuditConflictException;
use VendGuard\Core\Domain\Exception\DexAuditForbiddenException;
use VendGuard\Core\Domain\Exception\DexAuditValidationException;
use VendGuard\Core\Service\DexAuditValidator;
use VendGuard\Core\Service\DexSelectionMatcher;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$matcher = new DexSelectionMatcher();
$validator = new DexAuditValidator();

$selection = static fn (string $code, int $units = 1, float $sales = 1.00): array => [
    'line_sequence' => 1,
    'raw_selection_code' => $code,
    'normalized_selection_code' => strtoupper($code),
    'unit_price_eur' => 1.25,
    'total_vend_units' => $units,
    'total_sales_eur' => $sales,
];

$expectException = static function (
    callable $action,
    string $errorCode,
    int $httpStatus,
    string $what
) use ($assert): void {
    try {
        $action();
        $assert($what, false, 'no se lanzó ninguna excepción');
    } catch (DexAuditForbiddenException | DexAuditConflictException | DexAuditValidationException $exception) {
        $assert(
            $what,
            $exception->getErrorCode() === $errorCode && $exception->getHttpStatusCode() === $httpStatus,
            sprintf('código=%s · http=%d', $exception->getErrorCode(), $exception->getHttpStatusCode())
        );
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-DEX-05 · Emparejamiento PA1 y guardas del volcado .dex\n";
echo "======================================================================\n";

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 1. Emparejamiento PA1/PA2 con carriles (C4, RF-04.2) ---\n";
// ─────────────────────────────────────────────────────────────────────────

$slots = [
    ['id' => 1, 'slot_code' => 'PA101'],
    ['id' => 3, 'slot_code' => '11'],
    ['id' => 4, 'slot_code' => '0'],
    ['id' => 5, 'slot_code' => 'PA200', 'is_active' => false],
];

$exact = $matcher->match('SNACKS', $slots, [$selection('pa101', 4, 5.00)]);
$assert(
    '1.1 El código se pliega a mayúsculas y la coincidencia exacta asigna carril y snapshot',
    $exact[0]['match_status'] === DexSelectionMatchStatus::EXACT_MATCH
        && $exact[0]['matched_slot_id'] === 1
        && $exact[0]['matched_slot_code_snapshot'] === 'PA101'
);

$leading = $matcher->match('SNACKS', $slots, [$selection('011')]);
$assert(
    '1.2 Sin exacta, el recorte de ceros a la izquierda empareja con un único carril',
    $leading[0]['match_status'] === DexSelectionMatchStatus::LEADING_ZERO_MATCH
        && $leading[0]['matched_slot_id'] === 3
        && $leading[0]['matched_slot_code_snapshot'] === '11'
);

$zeroOnly = $matcher->match('SNACKS', $slots, [$selection('000')]);
$assert(
    '1.3 Un código todo ceros se reduce a "0" y no desaparece',
    $zeroOnly[0]['match_status'] === DexSelectionMatchStatus::LEADING_ZERO_MATCH
        && $zeroOnly[0]['matched_slot_id'] === 4
);

$collision = $matcher->match('SNACKS', $slots, [$selection('011'), $selection('0011')]);
$assert(
    '1.4 Dos PA1 que resuelven al mismo carril se degradan ambos a AMBIGUOUS sin carril',
    $collision[0]['match_status'] === DexSelectionMatchStatus::AMBIGUOUS
        && $collision[1]['match_status'] === DexSelectionMatchStatus::AMBIGUOUS
        && $collision[0]['matched_slot_id'] === null
        && $collision[1]['matched_slot_id'] === null
);

$exactPlusLeading = $matcher->match('SNACKS', $slots, [$selection('11'), $selection('011')]);
$assert(
    '1.5 Una exacta y una con ceros sobre el mismo carril también colisionan',
    $exactPlusLeading[0]['match_status'] === DexSelectionMatchStatus::AMBIGUOUS
        && $exactPlusLeading[1]['match_status'] === DexSelectionMatchStatus::AMBIGUOUS
);

$duplicatedSlot = $matcher->match(
    'SNACKS',
    [['id' => 6, 'slot_code' => 'DUP'], ['id' => 7, 'slot_code' => 'DUP']],
    [$selection('DUP')]
);
$assert(
    '1.6 Dos carriles con el mismo código no permiten elegir: AMBIGUOUS',
    $duplicatedSlot[0]['match_status'] === DexSelectionMatchStatus::AMBIGUOUS
        && $duplicatedSlot[0]['matched_slot_id'] === null
);

$unmapped = $matcher->match('SNACKS', $slots, [$selection('ZZZ9')]);
$assert(
    '1.7 Sin coincidencia exacta ni con ceros el estado es UNMAPPED',
    $unmapped[0]['match_status'] === DexSelectionMatchStatus::UNMAPPED
        && $unmapped[0]['matched_slot_id'] === null
);

$inactive = $matcher->match('SNACKS', $slots, [$selection('PA200')]);
$assert(
    '1.8 Los carriles inactivos no participan en el emparejamiento',
    $inactive[0]['match_status'] === DexSelectionMatchStatus::UNMAPPED
);

$coffee = $matcher->match('hot_drinks', $slots, [$selection('PA101'), $selection('011')]);
$assert(
    '1.9 En HOT_DRINKS todas las selecciones son informativas, sin carril ni falsa alerta',
    $coffee[0]['match_status'] === DexSelectionMatchStatus::HOT_DRINKS_SELECTION
        && $coffee[1]['match_status'] === DexSelectionMatchStatus::HOT_DRINKS_SELECTION
        && $coffee[0]['matched_slot_id'] === null
        && $coffee[1]['matched_slot_id'] === null
);

$assert(
    '1.10 El matcher conserva el resto de campos de la selección',
    $exact[0]['total_vend_units'] === 4
        && $exact[0]['total_sales_eur'] === 5.00
        && $exact[0]['raw_selection_code'] === 'pa101'
);

$assert(
    '1.11 Un planograma vacío deja todas las selecciones sin carril',
    $matcher->match('COLD_DRINKS', [], [$selection('A1')])[0]['match_status'] === DexSelectionMatchStatus::UNMAPPED
);

$expectException(
    static fn () => $matcher->match('SNACKS', $slots, [['normalized_selection_code' => '']]),
    'DEX_INVALID_SELECTION_LINE',
    422,
    '1.12 Una selección sin código normalizado se rechaza (límite del parser)'
);
$expectException(
    static fn () => $matcher->match('SNACKS', [['id' => 0, 'slot_code' => 'A1']], [$selection('A1')]),
    'DEX_INVALID_PLANOGRAM_SLOT',
    422,
    '1.13 Un carril sin identificador positivo se rechaza'
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 2. Máquina, perímetro y primacía sanitaria (RF-03.1, C2, QA-1) ---\n";
// ─────────────────────────────────────────────────────────────────────────

$activeMachine = ['id' => 10, 'is_active' => true, 'deleted_at' => null];
$assert(
    '2.1 Una máquina activa y no borrada es importable',
    (static function () use ($validator, $activeMachine): bool {
        $validator->assertMachineImportable($activeMachine);

        return true;
    })()
);
$expectException(
    static fn () => $validator->assertMachineImportable(['id' => 0, 'is_active' => true]),
    'MACHINE_NOT_FOUND',
    422,
    '2.2 Sin identificador no hay máquina destino'
);
$expectException(
    static fn () => $validator->assertMachineImportable(['id' => 10, 'is_active' => false]),
    'MACHINE_INACTIVE',
    422,
    '2.3 Una máquina inactiva no admite auditoría'
);
$expectException(
    static fn () => $validator->assertMachineImportable(['id' => 10, 'is_active' => true, 'deleted_at' => '2026-01-01 00:00:00']),
    'MACHINE_INACTIVE',
    422,
    '2.4 Una máquina borrada lógicamente tampoco'
);

$assert(
    '2.5 El Reponedor que cubre la máquina puede importar en estado operativo',
    (static function () use ($validator): bool {
        $validator->assertImportAuthorized('RESTOCKER', true, 'OPERATIONAL');

        return true;
    })()
);
$expectException(
    static fn () => $validator->assertImportAuthorized('RESTOCKER', false),
    'MACHINE_OUTSIDE_RESTOCKER_ZONES',
    403,
    '2.6 El Reponedor fuera de sus zonas activas recibe 403'
);
$expectException(
    static fn () => $validator->assertImportAuthorized('RESTOCKER', true, 'QUARANTINE'),
    'SANITARY_DEX_BLOCKED',
    409,
    '2.7 El Reponedor choca con la cuarentena sanitaria (409, Art. II.2)'
);
$expectException(
    static fn () => $validator->assertImportAuthorized('RESTOCKER', true, 'OPERATIONAL', true),
    'SANITARY_DEX_BLOCKED',
    409,
    '2.8 Una avería TEMPERATURE_COLD abierta bloquea igual al Reponedor'
);
$assert(
    '2.9 El Reponedor puede documentar un escalado en EXPIRED',
    (static function () use ($validator): bool {
        $validator->assertImportAuthorized('RESTOCKER', true, 'EXPIRED');

        return true;
    })()
);
$assert(
    '2.10 El Técnico conserva el acceso forense en cuarentena y con avería térmica',
    (static function () use ($validator): bool {
        $validator->assertImportAuthorized('TECHNICIAN', true, 'QUARANTINE', true);

        return true;
    })()
);
$expectException(
    static fn () => $validator->assertImportAuthorized('TECHNICIAN', false),
    'MACHINE_OUTSIDE_TECHNICIAN_SCOPE',
    403,
    '2.11 El Técnico sin intervención ni zona recibe 403'
);
$assert(
    '2.12 Coordinación importa sobre cualquier estado sanitario',
    (static function () use ($validator): bool {
        $validator->assertImportAuthorized('coordinator', false, 'QUARANTINE', true);

        return true;
    })()
);
$expectException(
    static fn () => $validator->assertImportAuthorized('AUDITOR', true),
    'DEX_INVALID_IMPORTER_ROLE',
    422,
    '2.13 Un rol ajeno al módulo no puede importar'
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Cadena de deltas (RF-04.1, C1, QA-2) ---\n";
// ─────────────────────────────────────────────────────────────────────────

$baseline = $validator->resolveDeltas(25.50, 41, null, null);
$assert(
    '3.1 Sin predecesor el volcado es INITIAL_BASELINE con deltas NULL',
    $baseline['previous_audit_id'] === null
        && $baseline['counter_reset_detected'] === false
        && $baseline['out_of_order_sync'] === false
        && $baseline['delta_source'] === 'INITIAL_BASELINE'
        && $baseline['delta_sales_eur'] === null
        && $baseline['delta_vend_units'] === null
);
$outOfOrder = $validator->resolveDeltas(25.50, 41, null, null, null, true);
$assert(
    '3.2 Un volcado con sucesor ACTIVO marca out_of_order_sync (QA-2)',
    $outOfOrder['out_of_order_sync'] === true
        && $outOfOrder['delta_source'] === 'INITIAL_BASELINE'
);

$normal = $validator->resolveDeltas(
    100.50,
    50,
    null,
    null,
    ['id' => 7, 'va1_total_sales_eur' => 80.25, 'va1_total_vend_units' => 30]
);
$assert(
    '3.3 Sin retroceso el delta es la resta de ambos contadores',
    $normal['previous_audit_id'] === 7
        && $normal['counter_reset_detected'] === false
        && $normal['delta_source'] === 'PREVIOUS_AUDIT'
        && $normal['delta_sales_eur'] === 20.25
        && $normal['delta_vend_units'] === 20
);
$rounded = $validator->resolveDeltas(
    100.00,
    10,
    null,
    null,
    ['id' => 8, 'va1_total_sales_eur' => 90.05, 'va1_total_vend_units' => 5]
);
$assert(
    '3.4 El delta monetario se redondea a dos decimales (QA-2)',
    $rounded['delta_sales_eur'] === 9.95
);

$resetSales = $validator->resolveDeltas(
    10.00,
    25,
    10.00,
    null,
    ['id' => 7, 'va1_total_sales_eur' => 80.25, 'va1_total_vend_units' => 30]
);
$assert(
    '3.5 Un retroceso usa el parcial de ventas como RESET_FALLBACK (C1)',
    $resetSales['counter_reset_detected'] === true
        && $resetSales['delta_source'] === 'RESET_FALLBACK'
        && $resetSales['delta_sales_eur'] === 10.00
        && $resetSales['delta_vend_units'] === null
);
$resetUnits = $validator->resolveDeltas(
    100.00,
    5,
    null,
    5,
    ['id' => 7, 'va1_total_sales_eur' => 80.25, 'va1_total_vend_units' => 30]
);
$assert(
    '3.6 Un retroceso solo de unidades usa el parcial de unidades',
    $resetUnits['delta_source'] === 'RESET_FALLBACK'
        && $resetUnits['delta_sales_eur'] === null
        && $resetUnits['delta_vend_units'] === 5
);
$resetBoth = $validator->resolveDeltas(
    10.00,
    5,
    10.00,
    5,
    ['id' => 7, 'va1_total_sales_eur' => 80.25, 'va1_total_vend_units' => 30]
);
$assert(
    '3.7 Con ambos parciales el fallback conserva los dos deltas',
    $resetBoth['delta_sales_eur'] === 10.00 && $resetBoth['delta_vend_units'] === 5
);
$unavailable = $validator->resolveDeltas(
    10.00,
    5,
    null,
    null,
    ['id' => 7, 'va1_total_sales_eur' => 80.25, 'va1_total_vend_units' => 30]
);
$assert(
    '3.8 Sin parciales el reseteo es UNAVAILABLE y no inventa cifras (C1)',
    $unavailable['counter_reset_detected'] === true
        && $unavailable['delta_source'] === 'UNAVAILABLE'
        && $unavailable['delta_sales_eur'] === null
        && $unavailable['delta_vend_units'] === null
        && $unavailable['previous_audit_id'] === 7
);
$assert(
    '3.9 Un retroceso de unidades también activa counter_reset_detected',
    $validator->resolveDeltas(
        100.00,
        2,
        null,
        null,
        ['id' => 7, 'va1_total_sales_eur' => 50.00, 'va1_total_vend_units' => 30]
    )['counter_reset_detected'] === true
);
$assert(
    '3.10 El out_of_order se conserva con predecesor y reseteo',
    $validator->resolveDeltas(
        10.00,
        2,
        null,
        null,
        ['id' => 7, 'va1_total_sales_eur' => 50.00, 'va1_total_vend_units' => 30],
        true
    )['out_of_order_sync'] === true
);
$expectException(
    static fn () => $validator->resolveDeltas(-1.0, 0, null, null),
    'DEX_INVALID_NUMERIC_BOUNDS',
    422,
    '3.11 Un contador negativo se rechaza'
);
$expectException(
    static fn () => $validator->resolveDeltas(1.0, 0, null, null, ['id' => 0]),
    'DEX_INVALID_PREVIOUS_AUDIT',
    422,
    '3.12 Un predecesor sin identificador válido se rechaza'
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Anulación lógica y CSV seguro (RF-07.2, C6, Art. V.1) ---\n";
// ─────────────────────────────────────────────────────────────────────────

$assert(
    '4.1 Un motivo de 20 caracteres con tres bloques se acepta y se recorta',
    $validator->validateVoidReason('  Motivo operativo uno  ') === 'Motivo operativo uno'
);
$expectException(
    static fn () => $validator->validateVoidReason('Motivo corto'),
    'VOID_REASON_REQUIRED',
    422,
    '4.2 Un motivo por debajo de 20 caracteres se rechaza'
);
$expectException(
    static fn () => $validator->validateVoidReason('Motivo operativounodos'),
    'VOID_REASON_REQUIRED',
    422,
    '4.3 Un motivo con menos de tres bloques alfanuméricos se rechaza'
);
$expectException(
    static fn () => $validator->validateVoidReason(str_repeat('a', 501)),
    'VOID_REASON_REQUIRED',
    422,
    '4.4 Un motivo de más de 500 caracteres se rechaza'
);
$assert(
    '4.5 Los guiones bajos separan bloques alfanuméricos (Art. V.1)',
    $validator->validateVoidReason('revision_manual_de_volcado_duplicado') === 'revision_manual_de_volcado_duplicado'
);
$expectException(
    static fn () => $validator->assertVoidable('VOIDED'),
    'DEX_AUDIT_ALREADY_VOIDED',
    409,
    '4.6 Un volcado ya anulado no se vuelve a anular (terminal)'
);
$assert(
    '4.7 Un volcado ACTIVE sí es anulable',
    (static function () use ($validator): bool {
        $validator->assertVoidable('active');

        return true;
    })()
);
$expectException(
    static fn () => $validator->assertVoidable('ARCHIVED'),
    'DEX_INVALID_AUDIT_STATUS',
    422,
    '4.8 Un estado fuera del dominio se rechaza'
);
$assert(
    '4.9 Una celda que empieza por = se neutraliza con apóstrofo',
    $validator->sanitizeCsvCell('=cmd|calc') === "'=cmd|calc"
);
$assert(
    '4.10 +, -, @, tabulador y retorno de carro también se neutralizan',
    $validator->sanitizeCsvCell('+34 600') === "'+34 600"
        && $validator->sanitizeCsvCell('-10') === "'-10"
        && $validator->sanitizeCsvCell('@SUM(A1)') === "'@SUM(A1)"
        && $validator->sanitizeCsvCell("\tfoo") === "'\tfoo"
        && $validator->sanitizeCsvCell("\rfoo") === "'\rfoo"
);
$assert(
    '4.11 El contenido legítimo no se muta y NULL se convierte en celda vacía',
    $validator->sanitizeCsvCell('DEX-2026-0001') === 'DEX-2026-0001'
        && $validator->sanitizeCsvCell('Café con leche') === 'Café con leche'
        && $validator->sanitizeCsvCell(null) === ''
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. T-DEX-05 (emparejamiento PA1 y guardas .dex) CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN EL EMPAREJADOR O EN LAS GUARDAS.\n";
echo "======================================================================\n";
exit(1);
