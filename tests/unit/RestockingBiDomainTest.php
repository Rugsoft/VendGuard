<?php

declare(strict_types=1);

/**
 * RestockingBiDomainTest · T-RBI-01
 *
 * Certifica el dominio puro del Cuadro de Mando de Reposición, Roturas de Stock
 * y Mermas Comerciales (Módulo 38; `D1`–`D3`, `C1`–`C5`, `QA-2`, `QA-4`, `QA-5`).
 *
 * Bloques que cubre:
 *
 *   1. **Validación de filtros (`RF-01.1`, `RF-01.2`, `C5`, `QA-5`).** Ventana
 *      por defecto de 30 días en `Europe/Madrid`, par obligatorio, formato
 *      estricto, fecha civil real, orden, techo de 366 días, admisión de
 *      `to > today` e identificadores enteros positivos. Cada rechazo se
 *      comprueba por el código `INVALID_FILTER_PARAMS` y por el campo culpable.
 *   2. **DTO (`RestockingBiFilterDto`).** Ventana semiabierta DST-safe
 *      `[from 00:00:00, (to + 1 día) 00:00:00)`, nulabilidad de zona/sede/
 *      reponedor y bloque `filters` del contrato.
 *   3. **Disponibilidad dual (`D1`, `RF-02`).** Cierre exacto al `100,00 %`,
 *      visitas sin líneas contadas como *In-Stock*, tasas sin denominador en
 *      `null` y fracaso térmico fuera del denominador de stock.
 *   4. **Mermas (`D2`, `RF-03`).** FEFO y dañado exactos al céntimo, bloqueo
 *      térmico por reponedor con su aviso explícito y actas M29 vacías contadas.
 *   5. **Planograma sobredimensionado (`D3`, `RF-04`).** Las tres condiciones
 *      simultáneas y la exclusión de dañados del cálculo.
 *   6. **Rendimiento (`C3`, `C4`, `RF-05`).** `machines_per_shift` nulo por
 *      territorio o sin turnos, duración media real, L1 efectivas frente a
 *      escalados y exactitud de arqueos sobre bolsas cerradas.
 *   7. **Orden y series (`QA-2`, `QA-4`, `D5`).** Prioridad sanitaria en
 *      `by_machine`, inclusiones contables, tablas ordenadas y Top 10 de los dos
 *      gráficos.
 *
 * CATEGORÍA: hermética (`tests/unit/`). No toca la base de datos ni el reloj del
 * sistema: la fecha de referencia y todas las cifras se declaran como argumento
 * explícito.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\RestockingBiFilterDto;
use VendGuard\Application\DTO\RestockingBiReportDto;
use VendGuard\Core\Service\RestockingBiCalculator;
use VendGuard\Core\Service\RestockingBiFilterValidator;

$assertions = 0;

function assertBiCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertBiFloat(float $expected, float $actual, string $message): void
{
    assertBiCondition(
        abs($expected - $actual) < 0.00001,
        $message . ' (esperado ' . $expected . ', recibido ' . $actual . ')'
    );
}

function assertBiNull(mixed $value, string $message): void
{
    assertBiCondition($value === null, $message . ' (recibido ' . var_export($value, true) . ')');
}

/**
 * Comprueba que el validador rechaza con `INVALID_FILTER_PARAMS` y el campo
 * exacto que el controlador publicará en `error.details.field`.
 */
function assertBiFilterRejects(callable $action, string $expectedField, string $message): void
{
    try {
        $action();
    } catch (InvalidArgumentException $exception) {
        assertBiCondition(
            str_contains($exception->getMessage(), RestockingBiFilterValidator::INVALID_FILTER_PARAMS),
            $message . ' (falta el código INVALID_FILTER_PARAMS)'
        );
        assertBiCondition(
            str_contains($exception->getMessage(), 'field: ' . $expectedField),
            $message . ' (campo esperado ' . $expectedField . ': ' . $exception->getMessage() . ')'
        );
        return;
    } catch (Throwable $exception) {
        assertBiCondition(false, $message . ' (se lanzó ' . $exception::class . ': ' . $exception->getMessage() . ')');
        return;
    }

    assertBiCondition(false, $message . ' (no se lanzó ninguna excepción)');
}

try {
    // ─────────────────────────────────────────────────────────────────────────
    // 1. Ventana por defecto y fechas de corte (`RF-01.1`, `C1`)
    // ─────────────────────────────────────────────────────────────────────────
    $default = RestockingBiFilterValidator::validate([], '2026-11-24');
    assertBiCondition($default->from() === '2026-10-26', 'The default window starts 29 days before today');
    assertBiCondition($default->to() === '2026-11-24', 'The default window ends today');
    assertBiCondition($default->inclusiveDays() === 30, 'The default window covers 30 inclusive calendar days');
    assertBiCondition(
        $default->fromAt()->format('Y-m-d H:i:s') === '2026-10-26 00:00:00',
        'The lower bound is civil midnight in Europe/Madrid (C1)'
    );
    assertBiCondition(
        $default->toExclusiveAt()->format('Y-m-d H:i:s') === '2026-11-25 00:00:00',
        'The upper bound is the next civil midnight, exclusive (C1)'
    );
    assertBiCondition(
        $default->fromAt()->getTimezone()->getName() === 'Europe/Madrid',
        'The query instants carry the business timezone (C1)'
    );
    assertBiCondition($default->timezone() === 'Europe/Madrid', 'The filter publishes the business timezone');
    assertBiNull($default->zoneId(), 'An absent zone_id normalizes to null');
    assertBiNull($default->locationId(), 'An absent location_id normalizes to null');
    assertBiNull($default->restockerId(), 'An absent restocker_id normalizes to null');
    assertBiCondition(!$default->hasTerritorialFilter(), 'Without zone or site there is no territorial filter');
    assertBiCondition(!$default->isRestockerScoped(), 'Without restocker_id the report is not restocker scoped');

    $explicit = RestockingBiFilterValidator::validate(
        ['from' => '2026-11-01', 'to' => '2026-11-10'],
        '2026-11-24'
    );
    assertBiCondition(
        $explicit->inclusiveDays() === 10 && $explicit->from() === '2026-11-01',
        'An explicit pair keeps its civil bounds'
    );

    $future = RestockingBiFilterValidator::validate(
        ['from' => '2026-12-01', 'to' => '2026-12-31'],
        '2026-11-24'
    );
    assertBiCondition(
        $future->to() === '2026-12-31',
        'to > today is expressly admitted (QA-5)'
    );

    $annual = RestockingBiFilterValidator::validate(
        ['from' => '2025-11-24', 'to' => '2026-11-24'],
        '2026-11-24'
    );
    assertBiCondition($annual->inclusiveDays() === 366, 'The 366-day ceiling is inclusive');

    // El cambio de hora de octubre no puede acortar la ventana civil (C1).
    $dst = RestockingBiFilterValidator::validate(
        ['from' => '2026-10-24', 'to' => '2026-10-26'],
        '2026-11-24'
    );
    assertBiCondition($dst->inclusiveDays() === 3, 'A window crossing the October DST change keeps 3 civil days');
    assertBiCondition(
        $dst->toExclusiveAt()->format('Y-m-d H:i:s') === '2026-10-27 00:00:00',
        'The exclusive upper bound stays at the next civil midnight across DST'
    );
    assertBiCondition(
        (int)round(($dst->toExclusiveAt()->getTimestamp() - $dst->fromAt()->getTimestamp()) / 3600) === 73,
        'The semi-open window covers the 73 real hours of the three civil days'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Validación fail-fast (`RF-01.2`, `C5`, `QA-5`)
    // ─────────────────────────────────────────────────────────────────────────
    assertBiFilterRejects(
        static fn() => RestockingBiFilterValidator::validate(['from' => '2026-11-01'], '2026-11-24'),
        'to',
        'An incomplete pair with only from is rejected on the missing to (C5)'
    );
    assertBiFilterRejects(
        static fn() => RestockingBiFilterValidator::validate(['to' => '2026-11-24'], '2026-11-24'),
        'from',
        'An incomplete pair with only to is rejected (C5)'
    );
    assertBiFilterRejects(
        static fn() => RestockingBiFilterValidator::validate(['from' => '24-11-2026', 'to' => '2026-11-24'], '2026-11-24'),
        'from',
        'A non ISO date is rejected (C5)'
    );
    assertBiFilterRejects(
        static fn() => RestockingBiFilterValidator::validate(['from' => '2026-1-1', 'to' => '2026-11-24'], '2026-11-24'),
        'from',
        'A date without zero padding is rejected (C5)'
    );
    assertBiFilterRejects(
        static fn() => RestockingBiFilterValidator::validate(['from' => '2026-02-30', 'to' => '2026-03-10'], '2026-11-24'),
        'from',
        'An impossible civil date is rejected instead of being silently corrected (C5)'
    );
    assertBiFilterRejects(
        static fn() => RestockingBiFilterValidator::validate(['from' => ['2026-11-01'], 'to' => '2026-11-24'], '2026-11-24'),
        'from',
        'A non scalar date parameter is rejected (C5)'
    );
    assertBiFilterRejects(
        static fn() => RestockingBiFilterValidator::validate(['from' => '2026-11-25', 'to' => '2026-11-24'], '2026-11-24'),
        'from',
        'from > to is rejected (RF-01.2)'
    );
    assertBiFilterRejects(
        static fn() => RestockingBiFilterValidator::validate(['from' => '2025-11-23', 'to' => '2026-11-24'], '2026-11-24'),
        'to',
        'A 367-day range exceeds the inclusive ceiling (RF-01.2)'
    );

    assertBiFilterRejects(
        static fn() => RestockingBiFilterValidator::validate(['zone_id' => '0'], '2026-11-24'),
        'zone_id',
        'zone_id = 0 is not a positive identifier (RF-01.2)'
    );
    assertBiFilterRejects(
        static fn() => RestockingBiFilterValidator::validate(['zone_id' => '-3'], '2026-11-24'),
        'zone_id',
        'A negative zone_id is rejected (RF-01.2)'
    );
    assertBiFilterRejects(
        static fn() => RestockingBiFilterValidator::validate(['location_id' => 'abc'], '2026-11-24'),
        'location_id',
        'A non numeric location_id is rejected (RF-01.2)'
    );
    assertBiFilterRejects(
        static fn() => RestockingBiFilterValidator::validate(['restocker_id' => '1.5'], '2026-11-24'),
        'restocker_id',
        'A decimal restocker_id is rejected (RF-01.2)'
    );
    assertBiFilterRejects(
        static fn() => RestockingBiFilterValidator::validate(['restocker_id' => 0], '2026-11-24'),
        'restocker_id',
        'An integer zero restocker_id is rejected (RF-01.2)'
    );

    $ids = RestockingBiFilterValidator::validate(
        ['zone_id' => '1', 'location_id' => ' 3 ', 'restocker_id' => 7],
        '2026-11-24'
    );
    assertBiCondition(
        $ids->zoneId() === 1 && $ids->locationId() === 3 && $ids->restockerId() === 7,
        'Positive identifiers are normalized to integers'
    );
    assertBiCondition($ids->hasTerritorialFilter(), 'A zone or site activates the territorial filter');
    assertBiCondition($ids->isRestockerScoped(), 'A restocker activates the individual scope');

    $emptyIds = RestockingBiFilterValidator::validate(
        ['zone_id' => '', 'location_id' => '', 'restocker_id' => '', 'from' => '', 'to' => ''],
        '2026-11-24'
    );
    assertBiCondition(
        $emptyIds->zoneId() === null && $emptyIds->locationId() === null && $emptyIds->restockerId() === null,
        'Empty optional identifiers normalize to null'
    );
    assertBiCondition(
        $emptyIds->from() === '2026-10-26' && $emptyIds->to() === '2026-11-24',
        'Empty date fields fall back to the default window (RF-01.1)'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 3. DTO de filtros (`RestockingBiFilterDto`)
    // ─────────────────────────────────────────────────────────────────────────
    assertBiFilterRejects(
        static fn() => new RestockingBiFilterDto('2026-11-25', '2026-11-24'),
        'from',
        'The DTO itself rejects an inverted range'
    );
    assertBiFilterRejects(
        static fn() => new RestockingBiFilterDto('2026-02-30', '2026-03-01'),
        'from',
        'The DTO itself rejects an impossible date'
    );
    assertBiFilterRejects(
        static fn() => new RestockingBiFilterDto('2025-11-23', '2026-11-24'),
        'to',
        'The DTO itself enforces the 366-day ceiling'
    );
    assertBiFilterRejects(
        static fn() => new RestockingBiFilterDto('2026-11-01', '2026-11-10', 0),
        'zone_id',
        'The DTO itself enforces positive identifiers'
    );

    $filterDto = new RestockingBiFilterDto('2026-11-01', '2026-11-10', 2, null, 9);
    assertBiCondition(
        $filterDto->toArray() === [
            'from' => '2026-11-01',
            'to' => '2026-11-10',
            'zone_id' => 2,
            'location_id' => null,
            'restocker_id' => 9,
            'timezone' => 'Europe/Madrid',
        ],
        'The filter DTO publishes the contract filters block in order'
    );
    assertBiCondition(
        $filterDto->jsonSerialize() === $filterDto->toArray(),
        'The filter DTO serializes to the same filters block'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Disponibilidad dual y umbrales (`D1`, `RF-02`, `D3`, `C3`)
    // ─────────────────────────────────────────────────────────────────────────
    $rates = [
        'completed_visits_count' => 20,
        'clean_in_stock_visits_count' => 17,
    ];
    $inStock = RestockingBiCalculator::ratePct($rates['clean_in_stock_visits_count'], $rates['completed_visits_count']);
    assertBiFloat(85.0, (float)$inStock, '17 of 20 clean visits give 85,00 % in-stock (D1)');
    assertBiFloat(15.0, (float)RestockingBiCalculator::complementRatePct($inStock), 'The stockout rate closes the visit to 100,00 % (D1)');
    assertBiNull(RestockingBiCalculator::ratePct(0, 0), 'A rate without denominator is null, never 0 % (C5)');
    assertBiNull(RestockingBiCalculator::complementRatePct(null), 'The complement of a null rate is null (C5)');

    $third = RestockingBiCalculator::ratePct(1, 3);
    assertBiFloat(33.33, (float)$third, 'One clean visit out of three rounds to 33,33 %');
    assertBiFloat(
        100.0,
        (float)$third + (float)RestockingBiCalculator::complementRatePct($third),
        'In-stock plus stockout equals exactly 100,00 % with rounding (D1)'
    );

    assertBiFloat(7.81, (float)RestockingBiCalculator::ratePct(5, 64), '5 stockout lanes out of 64 audited is 7,81 % (RF-02.2)');
    assertBiNull(RestockingBiCalculator::ratePct(0, 0), 'Without audited lanes the slot rate is null (RF-02.2)');

    assertBiFloat(20.0, (float)RestockingBiCalculator::expiryRatePct(9, 36), '9 expired of 45 moved units is 20,00 % (D3)');
    assertBiNull(RestockingBiCalculator::expiryRatePct(0, 0), 'Without moved units the expiry rate is null (D3, RF-04.1)');
    assertBiFloat(
        20.0,
        (float)RestockingBiCalculator::expiryRatePct(9, 36),
        'Damaged units do not enter the expiry denominator (D3)'
    );

    assertBiCondition(
        RestockingBiCalculator::isOversizedPlanogram(20.0, 2, 3),
        'The badge requires the three conditions and all are met (D3)'
    );
    assertBiCondition(
        RestockingBiCalculator::isOversizedPlanogram(15.0, 2, 3),
        'The 15,00 % threshold is inclusive (D3)'
    );
    assertBiCondition(
        !RestockingBiCalculator::isOversizedPlanogram(14.99, 5, 9),
        'Below 15,00 % there is no badge (D3)'
    );
    assertBiCondition(
        !RestockingBiCalculator::isOversizedPlanogram(20.0, 1, 3),
        'A single completed visit cannot raise the badge (D3)'
    );
    assertBiCondition(
        !RestockingBiCalculator::isOversizedPlanogram(20.0, 2, 2),
        'Two expired units cannot raise the badge (D3)'
    );
    assertBiCondition(
        !RestockingBiCalculator::isOversizedPlanogram(null, 9, 9),
        'A null expiry rate never raises the badge (D3)'
    );

    $perShift = RestockingBiCalculator::machinesPerShift(14, 5, false);
    assertBiFloat(2.8, (float)$perShift['machines_per_shift'], '14 machines over 5 valid shifts is 2,80 (C3)');
    assertBiNull($perShift['machines_per_shift_na_reason'], 'A computed ratio has no NA reason (C3)');

    $noShifts = RestockingBiCalculator::machinesPerShift(14, 0, false);
    assertBiNull($noShifts['machines_per_shift'], 'Without valid shifts the ratio is null (C3)');
    assertBiCondition(
        $noShifts['machines_per_shift_na_reason'] === RestockingBiCalculator::MACHINES_PER_SHIFT_NO_VALID_SHIFTS,
        'The NA reason is NO_VALID_SHIFTS (C3)'
    );

    $territorial = RestockingBiCalculator::machinesPerShift(14, 5, true);
    assertBiNull($territorial['machines_per_shift'], 'A territorial filter blocks the ratio (C3)');
    assertBiCondition(
        $territorial['machines_per_shift_na_reason'] === RestockingBiCalculator::MACHINES_PER_SHIFT_NOT_SEGMENTABLE_BY_TERRITORY,
        'The NA reason is NOT_SEGMENTABLE_BY_TERRITORY (C3)'
    );

    assertBiFloat(
        18.5,
        (float)RestockingBiCalculator::averageStopDurationMinutes(6, 6660.0),
        'The average stop duration is the real elapsed time (RF-05.1)'
    );
    assertBiNull(
        RestockingBiCalculator::averageStopDurationMinutes(0, 0.0),
        'Without completed visits the average stop duration is null (RF-05.1)'
    );
    assertBiFloat(
        5.6,
        (float)RestockingBiCalculator::averageStopDurationMinutes(3, 1000.0),
        'The average stop duration rounds to one decimal without trimming outliers (RF-05.1)'
    );

    assertBiFloat(91.67, (float)RestockingBiCalculator::cashAccuracyRatePct(11, 1), '11 of 12 sealed bags is 91,67 % (RF-05.3)');
    assertBiNull(
        RestockingBiCalculator::cashAccuracyRatePct(0, 0),
        'Without sealed bags the cash accuracy is null (RF-05.3)'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Dataset compartido del informe
    // ─────────────────────────────────────────────────────────────────────────
    $machineCatalog = [
        [
            'machine_id' => 5,
            'machine_code' => 'MAQ-HOSP-02',
            'machine_type' => 'PERISHABLE_FOOD',
            'location_id' => 3,
            'location_name' => 'Hospital Universitario',
            'zone_id' => 1,
            'zone_name' => 'Zona Norte',
            'is_active' => true,
        ],
        [
            'machine_id' => 6,
            'machine_code' => 'MAQ-HOSP-01',
            'machine_type' => 'SNACKS',
            'location_id' => 3,
            'location_name' => 'Hospital Universitario',
            'zone_id' => 1,
            'zone_name' => 'Zona Norte',
            'is_active' => true,
        ],
        [
            'machine_id' => 9,
            'machine_code' => 'MAQ-EXT-04',
            'machine_type' => 'COLD_DRINKS',
            'location_id' => 8,
            'location_name' => 'Sede Sin Zona',
            'zone_id' => null,
            'zone_name' => null,
            'is_active' => false,
        ],
        [
            'machine_id' => 12,
            'machine_code' => 'MAQ-THERM-01',
            'machine_type' => 'PERISHABLE_FOOD',
            'location_id' => 4,
            'location_name' => 'CEIP Norte',
            'zone_id' => 2,
            'zone_name' => 'Zona Sur',
            'is_active' => true,
        ],
    ];

    $visitHeaders = [
        ['machine_id' => 5, 'restocker_id' => 7, 'completed_visits_count' => 6, 'clean_in_stock_visits_count' => 4, 'thermal_aborted_visits_count' => 1, 'completed_duration_seconds_sum' => 6660.0],
        ['machine_id' => 5, 'restocker_id' => 12, 'completed_visits_count' => 1, 'clean_in_stock_visits_count' => 0, 'thermal_aborted_visits_count' => 0, 'completed_duration_seconds_sum' => 900.0],
        ['machine_id' => 6, 'restocker_id' => 12, 'completed_visits_count' => 4, 'clean_in_stock_visits_count' => 3, 'thermal_aborted_visits_count' => 0, 'completed_duration_seconds_sum' => 3600.0],
        ['machine_id' => 9, 'restocker_id' => 12, 'completed_visits_count' => 2, 'clean_in_stock_visits_count' => 2, 'thermal_aborted_visits_count' => 0, 'completed_duration_seconds_sum' => 1800.0],
    ];

    $slotItems = [
        [
            'machine_id' => 5,
            'product_id' => 3,
            'audited_slots_count' => 12,
            'stockout_slots_count' => 3,
            'low_threshold_slots_count' => 2,
            'added_units' => 36,
            'expired_units' => 9,
            'damaged_units' => 1,
            'expired_loss_eur' => 22.50,
            'damaged_loss_eur' => 2.50,
        ],
        [
            'machine_id' => 6,
            'product_id' => 4,
            'audited_slots_count' => 20,
            'stockout_slots_count' => 2,
            'low_threshold_slots_count' => 3,
            'added_units' => 60,
            'expired_units' => 1,
            'damaged_units' => 0,
            'expired_loss_eur' => 1.50,
            'damaged_loss_eur' => 0.00,
        ],
        [
            'machine_id' => 9,
            'product_id' => 5,
            'audited_slots_count' => 8,
            'stockout_slots_count' => 0,
            'low_threshold_slots_count' => 1,
            'added_units' => 24,
            'expired_units' => 0,
            'damaged_units' => 2,
            'expired_loss_eur' => 0.00,
            'damaged_loss_eur' => 4.00,
        ],
    ];

    $restockerSlotItems = [
        ['restocker_id' => 7, 'expired_units' => 9, 'damaged_units' => 1, 'commercial_spoilage_loss_eur' => 25.00],
        ['restocker_id' => 12, 'expired_units' => 1, 'damaged_units' => 2, 'commercial_spoilage_loss_eur' => 5.50],
    ];

    $thermalRecords = [
        ['machine_id' => 5, 'records_count' => 1, 'discarded_units_count' => 9, 'estimated_merchandise_loss_eur' => 31.50],
        ['machine_id' => 5, 'records_count' => 1, 'discarded_units_count' => 0, 'estimated_merchandise_loss_eur' => 0.00],
        ['machine_id' => 12, 'records_count' => 1, 'discarded_units_count' => 3, 'estimated_merchandise_loss_eur' => 10.00],
    ];

    $productCatalog = [
        ['product_id' => 3, 'sku' => 'SAND-MIXTO-01', 'name' => 'Sándwich Mixto Fresco', 'category' => 'PERISHABLE_FOOD', 'is_active' => true],
        ['product_id' => 4, 'sku' => 'PATATAS-01', 'name' => 'Patatas Fritas', 'category' => 'SNACKS', 'is_active' => true],
        ['product_id' => 5, 'sku' => 'AGUA-50', 'name' => 'Agua Mineral 50cl', 'category' => 'COLD_DRINKS', 'is_active' => false],
    ];

    $restockerCatalog = [
        ['restocker_id' => 7, 'operator_code' => 'REP-01', 'name' => 'Lucía Navarro', 'is_active' => true],
        ['restocker_id' => 12, 'operator_code' => null, 'name' => 'Carlos Méndez', 'is_active' => false],
        ['restocker_id' => 20, 'operator_code' => 'REP-03', 'name' => 'Ana Ruiz', 'is_active' => true],
    ];

    $shiftRows = [
        ['technician_id' => 7, 'valid_shifts_count' => 5],
        ['technician_id' => 20, 'valid_shifts_count' => 2],
    ];

    $cashRows = [
        ['restocker_id' => 7, 'reconciled_ok_count' => 11, 'discrepancy_flagged_count' => 1, 'pending_audit_count' => 1, 'total_abs_discrepancy_eur' => 2.40],
        ['restocker_id' => 12, 'reconciled_ok_count' => 0, 'discrepancy_flagged_count' => 0, 'pending_audit_count' => 2, 'total_abs_discrepancy_eur' => 0.00],
    ];

    $l1Rows = [
        ['restocker_id' => 7, 'l1_resolved_count' => 3, 'escalated_to_sat_count' => 1],
        ['restocker_id' => 12, 'l1_resolved_count' => 1, 'escalated_to_sat_count' => 1],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Totales comerciales y térmicos (`RF-03`, `QA-2`)
    // ─────────────────────────────────────────────────────────────────────────
    $commercialTotals = RestockingBiCalculator::totalCommercial($slotItems);
    assertBiCondition($commercialTotals['fefo_units'] === 10, 'FEFO units sum every expired line (RF-03.1)');
    assertBiFloat(24.00, $commercialTotals['fefo_loss_eur'], 'FEFO loss is exact to the cent (RF-03.1)');
    assertBiCondition($commercialTotals['damaged_units'] === 3, 'Damaged units are counted apart (RF-03.1)');
    assertBiFloat(6.50, $commercialTotals['damaged_loss_eur'], 'Damaged loss is exact to the cent (RF-03.1)');
    assertBiCondition($commercialTotals['total_units'] === 13, 'Commercial units add FEFO and damaged (RF-03.1)');
    assertBiFloat(30.50, $commercialTotals['total_loss_eur'], 'Commercial loss adds FEFO and damaged exactly (RF-03.1)');
    assertBiFloat(
        $commercialTotals['fefo_loss_eur'] + $commercialTotals['damaged_loss_eur'],
        $commercialTotals['total_loss_eur'],
        'Commercial total matches expired + damaged at the cent (QA-A4)'
    );

    $thermalTotals = RestockingBiCalculator::totalThermal($thermalRecords);
    assertBiCondition($thermalTotals['records_count'] === 3, 'Every M29 record counts, including the empty machine one (QA-2)');
    assertBiCondition($thermalTotals['units'] === 12, 'Thermal units sum every discarded unit (QA-2)');
    assertBiFloat(41.50, $thermalTotals['loss_eur'], 'Thermal loss is rounded to the cent (QA-2)');

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Ranking de máquinas (`QA-2`, `QA-4`, Art. II.2)
    // ─────────────────────────────────────────────────────────────────────────
    $machineRows = RestockingBiCalculator::buildMachineRows(
        $machineCatalog,
        $visitHeaders,
        $slotItems,
        $thermalRecords,
        true
    );

    assertBiCondition(count($machineRows) === 4, 'Every machine with activity enters the ranking (QA-4)');
    assertBiCondition(
        array_column($machineRows, 'machine_id') === [5, 12, 9, 6],
        'Sanitary priority wins over commercial loss in by_machine (QA-2, Art. II.2)'
    );

    $machine5 = $machineRows[0];
    assertBiCondition($machine5['machine_id'] === 5, 'The sanitary and oversized machine leads the ranking');
    assertBiCondition($machine5['completed_visits_count'] === 7, 'Visits are aggregated across restockers');
    assertBiCondition($machine5['clean_in_stock_visits_count'] === 4, 'Clean visits are aggregated across restockers');
    assertBiFloat(57.14, (float)$machine5['visit_in_stock_rate_pct'], '4 of 7 stay in stock (D1)');
    assertBiCondition($machine5['thermal_aborted_visits_count'] === 1, 'Thermal aborts are exposed apart (Art. II)');
    assertBiCondition($machine5['audited_slots_count'] === 12, 'Audited lanes come from the lane aggregate (RF-02.2)');
    assertBiFloat(25.0, (float)$machine5['slot_stockout_rate_pct'], '3 of 12 lanes in stockout is 25,00 % (RF-02.2)');
    assertBiCondition($machine5['low_threshold_slots_count'] === 2, 'Lanes under threshold are exposed (RF-02.2)');
    assertBiCondition($machine5['added_units'] === 36 && $machine5['expired_units'] === 9, 'Moved and expired units are exposed (D3)');
    assertBiFloat(22.50, (float)$machine5['expired_loss_eur'], 'FEFO loss keeps the frozen unit cost (D2)');
    assertBiFloat(2.50, (float)$machine5['damaged_loss_eur'], 'Damaged loss is exposed apart (D2)');
    assertBiFloat(25.00, (float)$machine5['commercial_spoilage_loss_eur'], 'Commercial loss adds FEFO and damaged (D2)');
    assertBiFloat(20.0, (float)$machine5['expiry_rate_pct'], '9 of 45 moved units expired is 20,00 % (D3)');
    assertBiCondition($machine5['is_oversized_planogram'] === true, 'The machine carries the oversized badge (D3)');
    assertBiCondition($machine5['thermal_spoilage_units'] === 9, 'Thermal units are published when segmentable (D2, QA-2)');
    assertBiFloat(31.50, (float)$machine5['thermal_spoilage_loss_eur'], 'Thermal loss is published when segmentable (D2)');

    $thermalOnly = $machineRows[1];
    assertBiCondition(
        $thermalOnly['machine_id'] === 12 && $thermalOnly['completed_visits_count'] === 0,
        'A machine with only an M29 record still enters by_machine (QA-4)'
    );
    assertBiCondition($thermalOnly['thermal_spoilage_units'] === 3, 'The thermal-only machine keeps its units (QA-2)');
    assertBiNull($thermalOnly['visit_in_stock_rate_pct'], 'A machine without visits has a null in-stock rate (C5)');
    assertBiNull($thermalOnly['slot_stockout_rate_pct'], 'A machine without audited lanes has a null slot rate (C5)');
    assertBiNull($thermalOnly['expiry_rate_pct'], 'A machine without moved units has a null expiry rate (D3)');
    assertBiCondition($thermalOnly['is_oversized_planogram'] === false, 'A machine without expiry is never oversized (D3)');

    $inactiveMachine = $machineRows[2];
    assertBiCondition(
        $inactiveMachine['machine_id'] === 9 && $inactiveMachine['is_active'] === false,
        'A logically deleted machine with activity stays visible with is_active = false (C2, Art. III)'
    );
    assertBiNull($inactiveMachine['zone_id'], 'A site without zone keeps zone_id null (QA-A3)');
    assertBiNull($inactiveMachine['zone_name'], 'A site without zone keeps zone_name null (QA-A3)');

    // ─────────────────────────────────────────────────────────────────────────
    // 8. Ranking de productos (`RF-04`)
    // ─────────────────────────────────────────────────────────────────────────
    $productRows = RestockingBiCalculator::buildProductRows($productCatalog, $slotItems);
    assertBiCondition(
        array_column($productRows, 'product_id') === [3, 5, 4],
        'by_product orders by commercial loss, stockout lanes and SKU (RF-04)'
    );

    $product3 = $productRows[0];
    assertBiCondition($product3['sku'] === 'SAND-MIXTO-01', 'The product keeps its SKU');
    assertBiFloat(25.00, (float)$product3['commercial_spoilage_loss_eur'], 'The product exposes its commercial loss');
    assertBiFloat(20.0, (float)$product3['expiry_rate_pct'], 'The product exposes its FEFO rate (D3)');
    assertBiFloat(25.0, (float)$product3['slot_stockout_rate_pct'], 'The product exposes its stockout rate (RF-02.2)');

    $inactiveProduct = $productRows[1];
    assertBiCondition(
        $inactiveProduct['product_id'] === 5 && $inactiveProduct['is_active'] === false,
        'A logically deleted product with rotation stays visible (C2, Art. III)'
    );
    assertBiFloat(4.00, (float)$inactiveProduct['commercial_spoilage_loss_eur'], 'The damaged-only product reports its loss');

    // ─────────────────────────────────────────────────────────────────────────
    // 9. Rendimiento por reponedor (`C3`, `C4`, `QA-4`)
    // ─────────────────────────────────────────────────────────────────────────
    $restockerRows = RestockingBiCalculator::buildRestockerRows(
        $restockerCatalog,
        $visitHeaders,
        $restockerSlotItems,
        $shiftRows,
        $cashRows,
        $l1Rows,
        false
    );

    assertBiCondition(
        array_column($restockerRows, 'restocker_id') === [12, 7, 20],
        'Without a territorial filter every restocker with any activity enters, ordered by visits (QA-4)'
    );

    $restocker12 = $restockerRows[0];
    assertBiCondition($restocker12['completed_visits_count'] === 7, 'Visits are aggregated per restocker (RF-05.1)');
    assertBiNull($restocker12['operator_code'], 'A restocker without operator code keeps it null (QA-A3)');
    assertBiCondition($restocker12['is_active'] === false, 'A logically deleted restocker keeps its badge (C2)');
    assertBiNull($restocker12['machines_per_shift'], 'Without valid shifts the ratio is null (C3)');
    assertBiCondition(
        $restocker12['machines_per_shift_na_reason'] === RestockingBiCalculator::MACHINES_PER_SHIFT_NO_VALID_SHIFTS,
        'The restocker publishes NO_VALID_SHIFTS (C3)'
    );
    assertBiFloat(15.0, (float)$restocker12['avg_stop_duration_minutes'], 'The real average stop duration is published (RF-05.1)');
    assertBiNull($restocker12['cash_accuracy_rate_pct'], 'Without sealed bags the cash accuracy is null (RF-05.3)');
    assertBiCondition($restocker12['pending_audit_count'] === 2, 'Open bags stay pending (RF-05.3)');
    assertBiCondition($restocker12['l1_resolved_count'] === 1, 'L1 resolved tickets are exposed (C4)');
    assertBiCondition($restocker12['escalated_to_sat_count'] === 1, 'SAT escalations travel in their own column (C4)');
    assertBiCondition(
        $restocker12['expired_units'] === 1
        && $restocker12['damaged_units'] === 2
        && abs((float)$restocker12['commercial_spoilage_loss_eur'] - 5.50) < 0.00001,
        'The restocker keeps their own commercial spoilage split (RF-03.1)'
    );

    $restocker7 = $restockerRows[1];
    assertBiFloat(1.2, (float)$restocker7['machines_per_shift'], '6 machines over 5 valid shifts is 1,20 (C3)');
    assertBiNull($restocker7['machines_per_shift_na_reason'], 'A computed ratio has no NA reason (C3)');
    assertBiFloat(18.5, (float)$restocker7['avg_stop_duration_minutes'], 'The first restocker keeps the real mean (RF-05.1)');
    assertBiFloat(91.67, (float)$restocker7['cash_accuracy_rate_pct'], '11 of 12 sealed bags is 91,67 % (RF-05.3)');
    assertBiFloat(2.40, (float)$restocker7['total_abs_discrepancy_eur'], 'The absolute discrepancy is exposed (RF-05.3)');
    assertBiCondition(
        $restocker7['operator_code'] === 'REP-01',
        'A restocker with operator code publishes it'
    );

    $restocker20 = $restockerRows[2];
    assertBiCondition($restocker20['completed_visits_count'] === 0, 'A restocker with only shifts enters the table (QA-4)');
    assertBiCondition($restocker20['valid_shifts_count'] === 2, 'Their valid shifts are kept (C3)');
    assertBiFloat(0.0, (float)$restocker20['machines_per_shift'], 'Without visits the ratio is 0,00 over valid shifts (C3)');
    assertBiNull($restocker20['avg_stop_duration_minutes'], 'Without visits the stop duration is null (RF-05.1)');

    $territorialRows = RestockingBiCalculator::buildRestockerRows(
        $restockerCatalog,
        $visitHeaders,
        $restockerSlotItems,
        $shiftRows,
        $cashRows,
        $l1Rows,
        true
    );
    assertBiCondition(
        array_column($territorialRows, 'restocker_id') === [12, 7],
        'With a territorial filter only territorial activity counts, so the shift-only restocker drops out (QA-4)'
    );
    foreach ($territorialRows as $row) {
        assertBiNull($row['machines_per_shift'], 'A territorial filter always nullifies machines_per_shift (C3)');
        assertBiCondition(
            $row['machines_per_shift_na_reason'] === RestockingBiCalculator::MACHINES_PER_SHIFT_NOT_SEGMENTABLE_BY_TERRITORY,
            'The territorial NA reason is published (C3)'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10. Resumen global (`RF-02`, `RF-03`, `RF-05`, `C5`, `QA-5`)
    // ─────────────────────────────────────────────────────────────────────────
    $summary = RestockingBiCalculator::buildSummary(
        $machineRows,
        $restockerRows,
        $commercialTotals,
        $thermalTotals,
        true
    );

    assertBiCondition($summary['completed_visits_count'] === 13, 'The summary adds every completed visit');
    assertBiCondition($summary['clean_in_stock_visits_count'] === 9, 'The summary adds every clean visit');
    assertBiCondition($summary['stockout_visits_count'] === 4, 'Stockout visits are completed minus clean');
    assertBiFloat(69.23, (float)$summary['visit_in_stock_rate_pct'], '9 of 13 visits is 69,23 % (D1)');
    assertBiFloat(30.77, (float)$summary['visit_stockout_rate_pct'], 'The stockout rate closes to 100,00 % (D1)');
    assertBiFloat(
        100.0,
        (float)$summary['visit_in_stock_rate_pct'] + (float)$summary['visit_stockout_rate_pct'],
        'The dual availability closes exactly at 100,00 % (D1)'
    );
    assertBiCondition($summary['thermal_aborted_visits_count'] === 1, 'Thermal aborts stay outside the stock denominator (Art. II)');
    assertBiCondition($summary['audited_slots_count'] === 40, 'Audited lanes are added globally (RF-02.2)');
    assertBiCondition($summary['stockout_slots_count'] === 5, 'Stockout lanes are added globally (RF-02.2)');
    assertBiFloat(12.5, (float)$summary['slot_stockout_rate_pct'], '5 of 40 lanes is 12,50 % (RF-02.2)');
    assertBiCondition($summary['low_threshold_slots_count'] === 6, 'Lanes under threshold are added globally (RF-02.2)');
    assertBiCondition($summary['oversized_machines_count'] === 1, 'Only the machine meeting the three conditions counts (RF-04.2)');
    assertBiFloat(30.50, (float)$summary['commercial_spoilage_eur'], 'The commercial loss is the exact cent total (D2)');
    assertBiCondition($summary['commercial_spoilage_units'] === 13, 'The commercial units add FEFO and damaged (D2)');
    assertBiFloat(41.50, (float)$summary['thermal_spoilage_eur'], 'The thermal loss is published when segmentable (D2)');
    assertBiCondition($summary['thermal_spoilage_units'] === 12, 'The thermal units are published when segmentable (D2)');
    assertBiFloat(72.00, (float)$summary['total_spoilage_eur'], 'Total spoilage adds commercial and thermal (D2)');
    assertBiCondition($summary['cash_reconciled_ok_count'] === 11, 'Sealed bags reconciled appear in summary (RF-05.3)');
    assertBiCondition($summary['cash_discrepancy_flagged_count'] === 1, 'Flagged discrepancies appear in summary (RF-05.3)');
    assertBiCondition($summary['cash_reconciled_count'] === 12, 'Both terminal cash states add up (RF-05.3)');
    assertBiCondition($summary['cash_pending_audit_count'] === 3, 'Open bags stay pending in summary (RF-05.3)');
    assertBiFloat(91.67, (float)$summary['cash_accuracy_rate_pct'], 'The cash accuracy is global (RF-05.3)');
    assertBiCondition(
        $summary['l1_resolved_count'] === array_sum(array_column($restockerRows, 'l1_resolved_count')),
        'summary.l1_resolved_count equals the sum of by_restocker (QA-5)'
    );
    assertBiCondition($summary['l1_resolved_count'] === 4, 'L1 resolved tickets are added globally (C4)');
    assertBiCondition(
        $summary['escalated_to_sat_count'] === array_sum(array_column($restockerRows, 'escalated_to_sat_count')),
        'summary.escalated_to_sat_count equals the sum of by_restocker (QA-5)'
    );
    assertBiCondition($summary['escalated_to_sat_count'] === 2, 'SAT escalations are added globally (C4)');

    // ─────────────────────────────────────────────────────────────────────────
    // 11. Comparativa de mermas y bloqueo térmico (`D2`, `RF-03.2`)
    // ─────────────────────────────────────────────────────────────────────────
    $comparison = RestockingBiCalculator::buildSpoilageComparison($commercialTotals, $thermalTotals, true);
    assertBiCondition($comparison['commercial_fefo_expired']['units'] === 10, 'The comparison publishes FEFO units (D2)');
    assertBiFloat(24.00, (float)$comparison['commercial_fefo_expired']['loss_eur'], 'The comparison publishes FEFO euros (D2)');
    assertBiCondition($comparison['commercial_damaged']['units'] === 3, 'The comparison publishes damaged units (D2)');
    assertBiFloat(6.50, (float)$comparison['commercial_damaged']['loss_eur'], 'The comparison publishes damaged euros (D2)');
    assertBiFloat(30.50, (float)$comparison['commercial_total']['loss_eur'], 'The comparison publishes the commercial total (D2)');
    assertBiCondition($comparison['thermal_haccp']['segmentable'] === true, 'Without a restocker the thermal block is segmentable (D2)');
    assertBiNull($comparison['thermal_haccp']['non_segmentable_reason'], 'A segmentable block has no reason (D2)');
    assertBiNull($comparison['thermal_haccp']['non_segmentable_message'], 'A segmentable block has no message (D2)');
    assertBiCondition($comparison['thermal_haccp']['records_count'] === 3, 'The thermal records count is published (QA-2)');
    assertBiCondition($comparison['thermal_haccp']['units'] === 12, 'The thermal units are published (D2)');
    assertBiFloat(41.50, (float)$comparison['thermal_haccp']['loss_eur'], 'The thermal euros are published (D2)');

    $nonSegmentable = RestockingBiCalculator::buildSpoilageComparison($commercialTotals, $thermalTotals, false);
    assertBiCondition($nonSegmentable['thermal_haccp']['segmentable'] === false, 'A restocker filter blocks the thermal block (D2)');
    assertBiCondition(
        $nonSegmentable['thermal_haccp']['non_segmentable_reason'] === RestockingBiCalculator::THERMAL_NON_SEGMENTABLE_REASON,
        'The blocking reason is NOT_SEGMENTABLE_BY_RESTOCKER (D2)'
    );
    assertBiCondition(
        $nonSegmentable['thermal_haccp']['non_segmentable_message'] === 'No segmentable por reponedor (ámbito máquina/SAT)',
        'The exact warning of the contract is published (D2)'
    );
    assertBiNull($nonSegmentable['thermal_haccp']['records_count'], 'The blocked thermal records count is null (D2)');
    assertBiNull($nonSegmentable['thermal_haccp']['units'], 'The blocked thermal units are null (D2)');
    assertBiNull($nonSegmentable['thermal_haccp']['loss_eur'], 'The blocked thermal euros are null (D2)');
    assertBiFloat(
        30.50,
        (float)$nonSegmentable['commercial_total']['loss_eur'],
        'Commercial spoilage survives the thermal block (D2)'
    );

    $scopedMachineRows = RestockingBiCalculator::buildMachineRows(
        $machineCatalog,
        $visitHeaders,
        $slotItems,
        $thermalRecords,
        false
    );
    $scopedMachine5 = $scopedMachineRows[0];
    assertBiNull($scopedMachine5['thermal_spoilage_units'], 'Machine thermal units are null under a restocker filter (D2)');
    assertBiNull($scopedMachine5['thermal_spoilage_loss_eur'], 'Machine thermal euros are null under a restocker filter (D2)');
    assertBiFloat(
        25.00,
        (float)$scopedMachine5['commercial_spoilage_loss_eur'],
        'Machine commercial loss survives the thermal block (D2)'
    );

    $scopedSummary = RestockingBiCalculator::buildSummary(
        $scopedMachineRows,
        $restockerRows,
        $commercialTotals,
        $thermalTotals,
        false
    );
    assertBiNull($scopedSummary['thermal_spoilage_eur'], 'The scoped summary thermal euros are null (D2)');
    assertBiNull($scopedSummary['thermal_spoilage_units'], 'The scoped summary thermal units are null (D2)');
    assertBiNull($scopedSummary['total_spoilage_eur'], 'The scoped total spoilage is null (D2)');
    assertBiFloat(30.50, (float)$scopedSummary['commercial_spoilage_eur'], 'The scoped summary keeps commercial loss (D2)');

    // ─────────────────────────────────────────────────────────────────────────
    // 12. Series de gráficos (`D5`, `QA-2`)
    // ─────────────────────────────────────────────────────────────────────────
    $charts = RestockingBiCalculator::buildCharts($machineRows, $productRows, $comparison, true);
    assertBiCondition(
        $charts['spoilage_bars']['labels'] === ['Caducidad FEFO', 'Dañado en ruta', 'Avería Térmica APPCC'],
        'The spoilage chart publishes the three bars when segmentable (D5)'
    );
    assertBiCondition(
        $charts['spoilage_bars']['keys'] === ['commercial_fefo_expired', 'commercial_damaged', 'thermal_haccp'],
        'The spoilage chart publishes the three contract keys (D5)'
    );
    assertBiCondition(
        $charts['spoilage_bars']['eur_values'] === [24.0, 6.5, 41.5],
        'The spoilage chart publishes the euro series in order (D5)'
    );
    assertBiCondition(
        $charts['spoilage_bars']['unit_values'] === [10, 3, 12],
        'The spoilage chart publishes the unit series in order (D5)'
    );
    assertBiCondition($charts['spoilage_bars']['thermal_segmentable'] === true, 'The chart flags the thermal as segmentable (D5)');

    $scopedCharts = RestockingBiCalculator::buildCharts($machineRows, $productRows, $nonSegmentable, false);
    assertBiCondition(
        $scopedCharts['spoilage_bars']['labels'] === ['Caducidad FEFO', 'Dañado en ruta'],
        'Under a restocker filter only the two commercial bars remain (D5)'
    );
    assertBiCondition(
        $scopedCharts['spoilage_bars']['eur_values'] === [24.0, 6.5],
        'The scoped chart keeps the commercial euros (D5)'
    );
    assertBiCondition($scopedCharts['spoilage_bars']['thermal_segmentable'] === false, 'The scoped chart flags thermal as blocked (D5)');

    assertBiCondition(
        array_column($charts['top_critical_stockout']['by_machine'], 'code') === ['MAQ-HOSP-02', 'MAQ-HOSP-01'],
        'The stockout top tens drop machines without stockout and order by rate (D5)'
    );
    assertBiCondition(
        array_column($charts['top_critical_stockout']['by_product'], 'code') === ['SAND-MIXTO-01', 'PATATAS-01'],
        'The product stockout series is ordered by rate and drops zero stockout (D5)'
    );
    assertBiCondition(
        $charts['top_critical_stockout']['by_machine'][0]['label'] === 'MAQ-HOSP-02 · Hospital Universitario',
        'The machine series publishes the readable label (D5)'
    );
    assertBiCondition(
        $charts['top_critical_expiry']['by_machine'][0]['is_oversized_planogram'] === true,
        'The expiry series carries the oversized badge on machines (D3, D5)'
    );
    assertBiCondition(
        array_column($charts['top_critical_expiry']['by_machine'], 'code') === ['MAQ-HOSP-02', 'MAQ-HOSP-01'],
        'The expiry series drops machines without expired units (D5)'
    );
    assertBiCondition(
        array_column($charts['top_critical_expiry']['by_product'], 'code') === ['SAND-MIXTO-01', 'PATATAS-01'],
        'The expiry product series is ordered by rate (D5)'
    );

    $manyMachines = [];
    $manySlots = [];
    for ($index = 0; $index < 12; $index++) {
        $machineId = 100 + $index;
        $manyMachines[] = [
            'machine_id' => $machineId,
            'machine_code' => sprintf('MAQ-BULK-%02d', $index),
            'machine_type' => 'SNACKS',
            'location_id' => 1,
            'location_name' => 'Sede Masiva',
            'zone_id' => 1,
            'zone_name' => 'Zona Norte',
            'is_active' => true,
        ];
        $manySlots[] = [
            'machine_id' => $machineId,
            'product_id' => 1,
            'audited_slots_count' => 100,
            'stockout_slots_count' => 12 - $index,
            'low_threshold_slots_count' => 0,
            'added_units' => 0,
            'expired_units' => 0,
            'damaged_units' => 0,
            'expired_loss_eur' => 0.0,
            'damaged_loss_eur' => 0.0,
        ];
    }
    $bulkCharts = RestockingBiCalculator::buildCharts(
        RestockingBiCalculator::buildMachineRows($manyMachines, [], $manySlots, [], true),
        [],
        $comparison,
        true
    );
    assertBiCondition(
        count($bulkCharts['top_critical_stockout']['by_machine']) === RestockingBiCalculator::TOP_CRITICAL_LIMIT,
        'The stockout series is cut at ten entries (D5)'
    );
    assertBiCondition(
        $bulkCharts['top_critical_stockout']['by_machine'][0]['code'] === 'MAQ-BULK-00',
        'The stockout series starts with the highest rate (D5)'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 13. Periodo vacío (`RF-01.4`, `C5`)
    // ─────────────────────────────────────────────────────────────────────────
    $emptyCommercial = RestockingBiCalculator::totalCommercial([]);
    $emptyThermal = RestockingBiCalculator::totalThermal([]);
    $emptySummary = RestockingBiCalculator::buildSummary([], [], $emptyCommercial, $emptyThermal, true);
    assertBiCondition($emptySummary['completed_visits_count'] === 0, 'An empty period counts zero visits (RF-01.4)');
    assertBiNull($emptySummary['visit_in_stock_rate_pct'], 'An empty period has a null in-stock rate, never 0 % (C5)');
    assertBiNull($emptySummary['visit_stockout_rate_pct'], 'An empty period has a null stockout rate, never 100 % (C5)');
    assertBiNull($emptySummary['slot_stockout_rate_pct'], 'An empty period has a null slot rate (C5)');
    assertBiNull($emptySummary['cash_accuracy_rate_pct'], 'An empty period has a null cash accuracy (C5)');
    assertBiCondition($emptySummary['oversized_machines_count'] === 0, 'An empty period has no oversized machine (C5)');
    assertBiFloat(0.0, (float)$emptySummary['commercial_spoilage_eur'], 'An empty period keeps monetary totals at 0,00 (RF-01.4)');
    assertBiFloat(0.0, (float)$emptySummary['thermal_spoilage_eur'], 'An empty segmentable period keeps thermal at 0,00 (RF-01.4)');
    assertBiFloat(0.0, (float)$emptySummary['total_spoilage_eur'], 'An empty period keeps total spoilage at 0,00 (RF-01.4)');
    assertBiCondition($emptySummary['l1_resolved_count'] === 0, 'An empty period counts zero L1 resolutions (C5)');

    $emptyCharts = RestockingBiCalculator::buildCharts([], [], RestockingBiCalculator::buildSpoilageComparison(
        $emptyCommercial,
        $emptyThermal,
        true
    ), true);
    assertBiCondition($emptyCharts['top_critical_stockout']['by_machine'] === [], 'An empty period has no stockout machine series (C5)');
    assertBiCondition($emptyCharts['top_critical_expiry']['by_product'] === [], 'An empty period has no expiry product series (C5)');
    assertBiCondition(
        $emptyCharts['spoilage_bars']['eur_values'] === [0.0, 0.0, 0.0],
        'The empty spoilage chart keeps its three zero bars (D5)'
    );

    $emptyMachineRows = RestockingBiCalculator::buildMachineRows([], [], [], [], true);
    assertBiCondition($emptyMachineRows === [], 'An empty catalogue produces an empty ranking (C5)');
    $emptyRestockerRows = RestockingBiCalculator::buildRestockerRows([], [], [], [], [], [], false);
    assertBiCondition($emptyRestockerRows === [], 'An empty restocker catalogue produces an empty table (C5)');

    // ─────────────────────────────────────────────────────────────────────────
    // 14. DTO del informe (`RestockingBiReportDto`)
    // ─────────────────────────────────────────────────────────────────────────
    $report = new RestockingBiReportDto(
        $default->toArray(),
        ['zones' => [], 'locations' => [], 'restockers' => []],
        $summary,
        $comparison,
        $charts,
        $machineRows,
        $productRows,
        $restockerRows
    );
    assertBiCondition(
        array_keys($report->toArray()) === [
            'filters',
            'available_filters',
            'summary',
            'spoilage_comparison',
            'charts',
            'by_machine',
            'by_product',
            'by_restocker',
        ],
        'The report DTO publishes the seven contract blocks in order'
    );
    assertBiCondition($report->filters() === $default->toArray(), 'The report DTO keeps the filters block');
    assertBiCondition($report->summary() === $summary, 'The report DTO keeps the summary block');
    assertBiCondition($report->spoilageComparison() === $comparison, 'The report DTO keeps the spoilage block');
    assertBiCondition($report->charts() === $charts, 'The report DTO keeps the charts block');
    assertBiCondition($report->byMachine() === $machineRows, 'The report DTO keeps the machine table');
    assertBiCondition($report->byProduct() === $productRows, 'The report DTO keeps the product table');
    assertBiCondition($report->byRestocker() === $restockerRows, 'The report DTO keeps the restocker table');
    assertBiCondition(
        $report->availableFilters() === ['zones' => [], 'locations' => [], 'restockers' => []],
        'The report DTO keeps the available filters block'
    );
    assertBiCondition($report->jsonSerialize() === $report->toArray(), 'The report DTO serializes as the contract payload');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[FAIL] " . $exception->getMessage() . "\n");
    exit(1);
}

echo "======================================================================\n";
echo " VENDGUARD · T-RBI-01 · Dominio BI de reposición, roturas y mermas\n";
echo " Total Aserciones: {$assertions}\n";
echo " RESULTADO: 100% EN VERDE\n";
echo "======================================================================\n";
exit(0);
