<?php

declare(strict_types=1);

/**
 * MachineCashCollectionValidatorTest · T-CCV-04
 *
 * Certifica el validador puro de la recaudación de efectivo, la carga de tubos
 * de cambio y el arqueo de caja (Módulo 35). Cada regla se prueba por el código
 * exacto del contrato REST, no por el texto del mensaje:
 *
 *   1. **Precinto (C1, RF-01.2).** Normalización a mayúsculas, patrón cerrado y
 *      bloque numérico de tres dígitos: `422 INVALID_BAG_SEAL_CODE`.
 *   2. **Primacía sanitaria (C4, Art. II.2).** Cuarentena, fuera de servicio y
 *      avería térmica abierta bloquean con `409 SANITARY_CASH_COLLECTION_BLOCKED`;
 *      una visita `ABORTED_THERMAL_BREACH` se rechaza con
 *      `422 THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN`.
 *   3. **Contador y Arqueo Ciego (D2, C3, Art. V.1).** La lectura regresiva sin
 *      reseteo es `422 SALES_COUNTER_REGRESSION`; el reseteo exige nota real
 *      (`422 COUNTER_RESET_NOTES_REQUIRED`); `expected_cashbox_eur` se calcula en
 *      los tres caminos y las cotas económicas son `422 INVALID_CASH_AMOUNT_BOUNDS`.
 *   4. **Conserjería (D3).** El tope de 10,00 €, el conserje de 3 a 120
 *      caracteres y la sede con recepción física se traducen a
 *      `422 RECEPTION_CASH_LIMIT_EXCEEDED`, `422 RECEPTIONIST_NAME_REQUIRED` y
 *      `409 LOCATION_HAS_NO_PHYSICAL_RECEPTION`.
 *   5. **Arqueo terminal (D2, D4, Art. V.1).** El descuadre es la resta exacta;
 *      ±1,00 € decide entre `RECONCILED_OK` y `DISCREPANCY_FLAGGED`, que exige
 *      justificación real (`422 DISCREPANCY_JUSTIFICATION_REQUIRED`); una fila
 *      terminal no se vuelve a arquear (`409 CASH_COLLECTION_ALREADY_RECONCILED`).
 *
 * CATEGORÍA: hermética (`tests/unit/`). No toca la base de datos ni el reloj del
 * sistema: todas las cifras se declaran como argumento explícito.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Enum\CashCollectionStatus;
use VendGuard\Core\Domain\Exception\MachineCashCollectionValidationException;
use VendGuard\Core\Service\MachineCashCollectionValidator;

$assertions = 0;

function assertValidatorCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Captura la excepción del validador y comprueba código, estado HTTP y campo,
 * que es lo que la capa de presentación va a leer.
 */
function assertValidatorRejects(
    callable $action,
    string $expectedCode,
    int $expectedHttpStatus,
    string $message,
    ?string $expectedField = null
): void {
    try {
        $action();
    } catch (MachineCashCollectionValidationException $exception) {
        assertValidatorCondition(
            $exception->getErrorCode() === $expectedCode,
            $message . ' (código esperado ' . $expectedCode . ', recibido ' . $exception->getErrorCode() . ')'
        );
        assertValidatorCondition(
            $exception->getHttpStatusCode() === $expectedHttpStatus,
            $message . ' (HTTP esperado ' . $expectedHttpStatus . ', recibido ' . $exception->getHttpStatusCode() . ')'
        );
        if ($expectedField !== null) {
            assertValidatorCondition(
                $exception->getField() === $expectedField,
                $message . ' (campo esperado ' . $expectedField . ', recibido ' . (string)$exception->getField() . ')'
            );
        }
        return;
    } catch (Throwable $exception) {
        assertValidatorCondition(
            false,
            $message . ' (se lanzó ' . $exception::class . ': ' . $exception->getMessage() . ')'
        );
        return;
    }

    assertValidatorCondition(false, $message . ' (no se lanzó ninguna excepción)');
}

try {
    // ─────────────────────────────────────────────────────────────────────────
    // 1. Precinto global (C1, RF-01.2)
    // ─────────────────────────────────────────────────────────────────────────
    assertValidatorCondition(
        MachineCashCollectionValidator::normalizeBagSeal(' bag-2026-000001 ') === 'BAG-2026-000001',
        'The seal is trimmed and normalized to uppercase (C1)'
    );
    assertValidatorCondition(
        MachineCashCollectionValidator::normalizeBagSeal('BAG_2026-ABC_001') === 'BAG_2026-ABC_001',
        'The closed alphabet of the seal accepts underscores and hyphens'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::normalizeBagSeal(''),
        MachineCashCollectionValidationException::INVALID_BAG_SEAL_CODE,
        422,
        'An empty seal is rejected (C1)',
        'bag_seal_code'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::normalizeBagSeal('BAG 2026 000001'),
        MachineCashCollectionValidationException::INVALID_BAG_SEAL_CODE,
        422,
        'A seal with spaces is rejected (C1)',
        'bag_seal_code'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::normalizeBagSeal('BAG-ABCDEF'),
        MachineCashCollectionValidationException::INVALID_BAG_SEAL_CODE,
        422,
        'A seal without three consecutive digits is rejected (C1)',
        'bag_seal_code'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::normalizeBagSeal(str_repeat('B', 41)),
        MachineCashCollectionValidationException::INVALID_BAG_SEAL_CODE,
        422,
        'A seal longer than forty characters is rejected (C1)',
        'bag_seal_code'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Primacía sanitaria (C4, Art. II.2)
    // ─────────────────────────────────────────────────────────────────────────
    MachineCashCollectionValidator::assertMachineAvailable('OK', false, false, 41);
    assertValidatorCondition(true, 'A healthy, available machine accepts the cash collection');

    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::assertMachineAvailable('quarantine', false, false, 41),
        MachineCashCollectionValidationException::SANITARY_CASH_COLLECTION_BLOCKED,
        409,
        'A quarantined machine is blocked (C4)',
        'machine_id'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::assertMachineAvailable('OK', true, false, 41),
        MachineCashCollectionValidationException::SANITARY_CASH_COLLECTION_BLOCKED,
        409,
        'An out-of-service machine is blocked (C4)',
        'machine_id'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::assertMachineAvailable('OK', false, true, 41),
        MachineCashCollectionValidationException::SANITARY_CASH_COLLECTION_BLOCKED,
        409,
        'A machine with an open thermal incident is blocked (C4)',
        'machine_id'
    );

    try {
        MachineCashCollectionValidator::assertMachineAvailable('QUARANTINE', true, true, 41);
        assertValidatorCondition(false, 'The combined sanitary block did not fire');
    } catch (MachineCashCollectionValidationException $exception) {
        assertValidatorCondition(
            $exception->getErrorCode() === MachineCashCollectionValidationException::SANITARY_CASH_COLLECTION_BLOCKED
                && count($exception->getContext()['reasons'] ?? []) === 3,
            'The sanitary block reports every concurrent reason (C4)'
        );
    }

    MachineCashCollectionValidator::assertVisitAllowsCashCollection('COMPLETED', 41, 9);
    MachineCashCollectionValidator::assertVisitAllowsCashCollection(null, 41, null);
    assertValidatorCondition(true, 'A completed or independent collection passes the thermal visit guard');

    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::assertVisitAllowsCashCollection('ABORTED_THERMAL_BREACH', 41, 9),
        MachineCashCollectionValidationException::THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN,
        422,
        'A thermally aborted visit forbids cash collection (C4)',
        'cash_collection'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Contador y Arqueo Ciego (D2, C3, Art. V.1)
    // ─────────────────────────────────────────────────────────────────────────
    $ordinary = MachineCashCollectionValidator::resolveCounterReading(false, 100.00, 125.50);
    assertValidatorCondition(
        $ordinary === [
            'previous_sales_counter_reading' => 100.00,
            'initial_baseline_reading_eur' => 0.00,
            'expected_cashbox_eur' => 25.50,
        ],
        'An ordinary collection subtracts the previous counter reading (D2)'
    );

    $zeroCash = MachineCashCollectionValidator::resolveCounterReading(false, 100.00, 100.00);
    assertValidatorCondition(
        $zeroCash['expected_cashbox_eur'] === 0.0,
        'A change-only visit can produce an expected cashbox of 0,00 € (D2)'
    );

    $reset = MachineCashCollectionValidator::resolveCounterReading(
        true,
        840.00,
        810.00,
        0.00,
        '  Reseteo autorizado del contador tras sustitución del monedero.  '
    );
    assertValidatorCondition(
        $reset === [
            'previous_sales_counter_reading' => null,
            'initial_baseline_reading_eur' => 0.00,
            'expected_cashbox_eur' => 810.00,
        ],
        'A justified reset starts from zero and ignores the previous reading (D2, C3)'
    );

    $first = MachineCashCollectionValidator::resolveCounterReading(false, null, 180.00, 50.00);
    assertValidatorCondition(
        $first === [
            'previous_sales_counter_reading' => null,
            'initial_baseline_reading_eur' => 50.00,
            'expected_cashbox_eur' => 130.00,
        ],
        'The first historical collection subtracts its declared baseline (D2)'
    );

    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveCounterReading(false, 840.00, 810.00),
        MachineCashCollectionValidationException::SALES_COUNTER_REGRESSION,
        422,
        'A counter regression without reset is rejected (C3)',
        'sales_counter_reading'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveCounterReading(true, 840.00, 810.00, 0.00, 'Monedero nuevo.'),
        MachineCashCollectionValidationException::COUNTER_RESET_NOTES_REQUIRED,
        422,
        'A reset with a short note is rejected (Art. V.1)',
        'counter_reset_notes'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveCounterReading(true, 840.00, 810.00, 0.00, null),
        MachineCashCollectionValidationException::COUNTER_RESET_NOTES_REQUIRED,
        422,
        'A reset without a note is rejected (C3)',
        'counter_reset_notes'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveCounterReading(true, 840.00, 810.00, 0.00, str_repeat('A', 30)),
        MachineCashCollectionValidationException::COUNTER_RESET_NOTES_REQUIRED,
        422,
        'A reset note without three alphanumeric blocks is rejected (Art. V.1)',
        'counter_reset_notes'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveCounterReading(false, null, 40.00, 50.00),
        MachineCashCollectionValidationException::INVALID_CASH_AMOUNT_BOUNDS,
        422,
        'A baseline above the counter reading is rejected (D2)',
        'initial_baseline_reading_eur'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveCounterReading(false, 0.00, 20000.00),
        MachineCashCollectionValidationException::INVALID_CASH_AMOUNT_BOUNDS,
        422,
        'An expected cashbox above 10.000,00 € is rejected (D2)',
        'expected_cashbox_eur'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveCounterReading(false, 100.00, -1.00),
        MachineCashCollectionValidationException::INVALID_CASH_AMOUNT_BOUNDS,
        422,
        'A negative counter reading is rejected (C3)',
        'sales_counter_reading'
    );

    MachineCashCollectionValidator::assertAmountBounds(20.00, 125.50, 25.50, 100.00, 26.00);
    assertValidatorCondition(true, 'The economic bounds accept the ordinary capture');
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::assertAmountBounds(501.00, 125.50, 25.50),
        MachineCashCollectionValidationException::INVALID_CASH_AMOUNT_BOUNDS,
        422,
        'Change tubes above 500,00 € are rejected (D2)',
        'change_tubes_loaded_eur'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::assertAmountBounds(20.00, 125.50, -1.00),
        MachineCashCollectionValidationException::INVALID_CASH_AMOUNT_BOUNDS,
        422,
        'A negative expected cashbox is rejected (D2)',
        'expected_cashbox_eur'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::assertAmountBounds(20.00, 125.50, 25.50, 100.00, 10000.01),
        MachineCashCollectionValidationException::INVALID_CASH_AMOUNT_BOUNDS,
        422,
        'A vault count above 10.000,00 € is rejected (D2)',
        'vault_counted_eur'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Entrega en conserjería (D3, RF-03.1)
    // ─────────────────────────────────────────────────────────────────────────
    $noDelivery = MachineCashCollectionValidator::assertReceptionDelivery(0.00, 'Carlos Ruiz', false, 41);
    assertValidatorCondition(
        $noDelivery === ['reception_cash_delivered_eur' => 0.00, 'receptionist_name' => null],
        'Without a delivery the receptionist is normalized away (D3)'
    );

    $delivery = MachineCashCollectionValidator::assertReceptionDelivery(4.50, '  Carlos Ruiz  ', true, 41);
    assertValidatorCondition(
        $delivery === ['reception_cash_delivered_eur' => 4.50, 'receptionist_name' => 'Carlos Ruiz'],
        'A valid delivery keeps the amount and trims the receptionist name (D3)'
    );

    $limitDelivery = MachineCashCollectionValidator::assertReceptionDelivery(10.00, 'Carlos Ruiz', true, 41);
    assertValidatorCondition(
        $limitDelivery['reception_cash_delivered_eur'] === 10.0,
        'The 10,00 € reception ceiling is inclusive (D3)'
    );

    $longName = str_repeat('A', 120);
    assertValidatorCondition(
        MachineCashCollectionValidator::assertReceptionDelivery(2.50, $longName, true, 41)['receptionist_name'] === $longName,
        'A receptionist name of 120 characters is accepted (D3)'
    );

    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::assertReceptionDelivery(12.00, 'Carlos Ruiz', true, 41),
        MachineCashCollectionValidationException::RECEPTION_CASH_LIMIT_EXCEEDED,
        422,
        'A delivery above 10,00 € is rejected (D3)',
        'reception_cash_delivered_eur'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::assertReceptionDelivery(-0.01, 'Carlos Ruiz', true, 41),
        MachineCashCollectionValidationException::RECEPTION_CASH_LIMIT_EXCEEDED,
        422,
        'A negative delivery is rejected (D3)',
        'reception_cash_delivered_eur'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::assertReceptionDelivery(4.50, null, true, 41),
        MachineCashCollectionValidationException::RECEPTIONIST_NAME_REQUIRED,
        422,
        'A delivery without a receptionist is rejected (D3)',
        'receptionist_name'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::assertReceptionDelivery(4.50, 'AB', true, 41),
        MachineCashCollectionValidationException::RECEPTIONIST_NAME_REQUIRED,
        422,
        'A receptionist name shorter than three characters is rejected (D3)',
        'receptionist_name'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::assertReceptionDelivery(4.50, str_repeat('A', 121), true, 41),
        MachineCashCollectionValidationException::RECEPTIONIST_NAME_REQUIRED,
        422,
        'A receptionist name longer than 120 characters is rejected (D3)',
        'receptionist_name'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::assertReceptionDelivery(4.50, 'Carlos Ruiz', false, 41),
        MachineCashCollectionValidationException::LOCATION_HAS_NO_PHYSICAL_RECEPTION,
        409,
        'A site without physical reception rejects the delivery (D3)',
        'reception_cash_delivered_eur'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Arqueo terminal (D2, D4, Art. V.1)
    // ─────────────────────────────────────────────────────────────────────────
    $ok = MachineCashCollectionValidator::resolveReconciliation(
        25.50,
        26.00,
        CashCollectionStatus::DELIVERED_TO_VAULT
    );
    assertValidatorCondition(
        $ok === [
            'status' => CashCollectionStatus::RECONCILED_OK,
            'discrepancy_eur' => 0.50,
            'discrepancy_justification' => null,
        ],
        'A reconciliation inside tolerance is approved without justification (D2)'
    );

    $boundary = MachineCashCollectionValidator::resolveReconciliation(
        25.50,
        24.50,
        CashCollectionStatus::SEALED_IN_ROUTE
    );
    assertValidatorCondition(
        $boundary['status'] === CashCollectionStatus::RECONCILED_OK
            && $boundary['discrepancy_eur'] === -1.00,
        'The -1,00 € tolerance boundary is still RECONCILED_OK (D2)'
    );

    $optionalNote = MachineCashCollectionValidator::resolveReconciliation(
        25.50,
        26.00,
        CashCollectionStatus::DELIVERED_TO_VAULT,
        '  Ajuste de cambio.  '
    );
    assertValidatorCondition(
        $optionalNote['status'] === CashCollectionStatus::RECONCILED_OK
            && $optionalNote['discrepancy_justification'] === 'Ajuste de cambio.',
        'A reconciled bag accepts an optional trimmed observation (D2)'
    );

    $flagged = MachineCashCollectionValidator::resolveReconciliation(
        25.50,
        27.50,
        CashCollectionStatus::DELIVERED_TO_VAULT,
        '  Faltan dos euros en la bolsa precintada de la ruta.  '
    );
    assertValidatorCondition(
        $flagged === [
            'status' => CashCollectionStatus::DISCREPANCY_FLAGGED,
            'discrepancy_eur' => 2.00,
            'discrepancy_justification' => 'Faltan dos euros en la bolsa precintada de la ruta.',
        ],
        'A discrepancy above tolerance is flagged with its real justification (D2, Art. V.1)'
    );

    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveReconciliation(
            25.50,
            27.50,
            CashCollectionStatus::DELIVERED_TO_VAULT
        ),
        MachineCashCollectionValidationException::DISCREPANCY_JUSTIFICATION_REQUIRED,
        422,
        'A discrepancy without justification is rejected (D2, Art. V.1)',
        'discrepancy_justification'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveReconciliation(
            25.50,
            27.50,
            CashCollectionStatus::DELIVERED_TO_VAULT,
            'Faltan euros.'
        ),
        MachineCashCollectionValidationException::DISCREPANCY_JUSTIFICATION_REQUIRED,
        422,
        'A justification shorter than twenty characters is rejected (Art. V.1)',
        'discrepancy_justification'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveReconciliation(
            25.50,
            27.50,
            CashCollectionStatus::DELIVERED_TO_VAULT,
            str_repeat('A', 30)
        ),
        MachineCashCollectionValidationException::DISCREPANCY_JUSTIFICATION_REQUIRED,
        422,
        'A justification without three alphanumeric blocks is rejected (Art. V.1)',
        'discrepancy_justification'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveReconciliation(
            25.50,
            26.00,
            CashCollectionStatus::DELIVERED_TO_VAULT,
            'AB'
        ),
        MachineCashCollectionValidationException::DISCREPANCY_JUSTIFICATION_REQUIRED,
        422,
        'An optional observation shorter than three characters is rejected (D2)',
        'discrepancy_justification'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveReconciliation(
            25.50,
            26.00,
            CashCollectionStatus::RECONCILED_OK
        ),
        MachineCashCollectionValidationException::CASH_COLLECTION_ALREADY_RECONCILED,
        409,
        'A reconciled bag cannot be reconciled again (D4)',
        'status'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveReconciliation(
            25.50,
            20.00,
            CashCollectionStatus::DISCREPANCY_FLAGGED
        ),
        MachineCashCollectionValidationException::CASH_COLLECTION_ALREADY_RECONCILED,
        409,
        'A flagged bag is terminal and cannot be reconciled again (D4)',
        'status'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveReconciliation(
            25.50,
            -0.01,
            CashCollectionStatus::DELIVERED_TO_VAULT
        ),
        MachineCashCollectionValidationException::INVALID_CASH_AMOUNT_BOUNDS,
        422,
        'A negative vault count is rejected (D2)',
        'vault_counted_eur'
    );
    assertValidatorRejects(
        static fn() => MachineCashCollectionValidator::resolveReconciliation(
            25.50,
            10000.01,
            CashCollectionStatus::DELIVERED_TO_VAULT
        ),
        MachineCashCollectionValidationException::INVALID_CASH_AMOUNT_BOUNDS,
        422,
        'A vault count above 10.000,00 € is rejected (D2)',
        'vault_counted_eur'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[FAIL] " . $exception->getMessage() . "\n");
    exit(1);
}

echo "======================================================================\n";
echo " VENDGUARD · T-CCV-04 · Validador de la recaudación y el arqueo\n";
echo " Aserciones: {$assertions} | Resultado: 100% EN VERDE\n";
echo "======================================================================\n";
exit(0);
