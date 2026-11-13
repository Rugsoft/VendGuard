<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Enum\ProductLoadingItemReason;
use VendGuard\Core\Domain\Enum\ProductLoadingOrderStatus;
use VendGuard\Core\Domain\Exception\ProductLoadingValidationException;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductLoadingOrder;
use VendGuard\Core\Domain\Model\ProductLoadingOrderItem;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Core\Service\ProductLoadingValidator;

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
 * Afirma que la acción lanza la excepción del contrato con el código y estado
 * HTTP exactos; un código distinto es un fallo, no un aprobado.
 */
function assertValidatorThrows(
    callable $action,
    string $expectedCode,
    int $expectedHttpStatus,
    string $message
): void {
    try {
        $action();
    } catch (ProductLoadingValidationException $exception) {
        assertValidatorCondition(
            $exception->getErrorCode() === $expectedCode
                && $exception->getHttpStatusCode() === $expectedHttpStatus,
            $message . sprintf(
                ' (se esperaba %s/%d y llegó %s/%d)',
                $expectedCode,
                $expectedHttpStatus,
                $exception->getErrorCode(),
                $exception->getHttpStatusCode()
            )
        );

        return;
    }

    assertValidatorCondition(false, $message . ' (no se lanzó ninguna excepción de contrato)');
}

try {
    $today = new DateTimeImmutable('2026-11-13');

    $coldProduct = new VendingProduct(
        null,
        'CERT-SAND-01',
        'Sándwich de certificación',
        ProductCategory::PERISHABLE_FOOD,
        ProductUnitType::UNIT,
        1.00,
        2.50,
        true,
        5
    );
    $ambientProduct = new VendingProduct(
        null,
        'CERT-COLA-01',
        'Refresco de certificación',
        ProductCategory::COLD_DRINK,
        ProductUnitType::UNIT,
        1.00,
        2.50
    );

    // ─────────────────────────────────────────────────────────────────────
    // Cerrojo térmico (Art. II)
    // ─────────────────────────────────────────────────────────────────────
    assertValidatorCondition(
        ProductLoadingValidator::assertColdChamberTemperature(3.5, true) === 3.5,
        'A cold chamber temperature inside the range is normalized'
    );
    assertValidatorCondition(
        ProductLoadingValidator::assertColdChamberTemperature(4.0, true) === 4.0
            && ProductLoadingValidator::assertColdChamberTemperature(-5.0, true) === -5.0,
        'Both temperature boundaries -5,0 and 4,0 °C are accepted'
    );
    assertValidatorCondition(
        ProductLoadingValidator::assertColdChamberTemperature(null, false) === null,
        'Without cold lines the temperature is optional'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertColdChamberTemperature(null, true, 'PLO-2026-8001'),
        ProductLoadingValidationException::COLD_CHAMBER_TEMP_EXCEEDED,
        422,
        'A cold order without temperature is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertColdChamberTemperature(4.1, true),
        ProductLoadingValidationException::COLD_CHAMBER_TEMP_EXCEEDED,
        422,
        'A temperature above 4,0 °C is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertColdChamberTemperature(-5.1, false),
        ProductLoadingValidationException::COLD_CHAMBER_TEMP_EXCEEDED,
        422,
        'An optional temperature still has to fit the physical range'
    );

    // ─────────────────────────────────────────────────────────────────────
    // Lote normalizado (QA 3, Art. II)
    // ─────────────────────────────────────────────────────────────────────
    assertValidatorCondition(
        ProductLoadingValidator::normalizeBatchCode(null, false) === 'SIN-LOTE',
        'A missing batch on a non-cold product normalizes to SIN-LOTE'
    );
    assertValidatorCondition(
        ProductLoadingValidator::normalizeBatchCode(' lote-sw-01 ', false) === 'LOTE-SW-01',
        'A batch code is trimmed and uppercased'
    );
    assertValidatorCondition(
        ProductLoadingValidator::normalizeBatchCode('SIN-LOTE', false) === 'SIN-LOTE',
        'SIN-LOTE stays valid for a non-cold product'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::normalizeBatchCode(null, true),
        ProductLoadingValidationException::EXPIRED_OR_INVALID_BATCH,
        422,
        'A cold product cannot use the normalized SIN-LOTE batch'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::normalizeBatchCode('LOTE SW 01', false),
        ProductLoadingValidationException::EXPIRED_OR_INVALID_BATCH,
        422,
        'An invalid batch format is rejected'
    );

    // ─────────────────────────────────────────────────────────────────────
    // Justificaciones ≥ 20 caracteres reales (Art. V.1)
    // ─────────────────────────────────────────────────────────────────────
    assertValidatorCondition(
        ProductLoadingValidator::assertJustification(str_repeat('á', 20)) === str_repeat('á', 20),
        'Twenty multibyte characters are twenty real characters'
    );
    assertValidatorCondition(
        ProductLoadingValidator::assertJustification('  Motivo   con   espacios  reales  ') === 'Motivo con espacios reales',
        'Justification normalization collapses repeated spaces'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertJustification(str_repeat('x', 19)),
        ProductLoadingValidationException::INVALID_JUSTIFICATION_LENGTH,
        422,
        'A 19-character justification is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertJustification(null),
        ProductLoadingValidationException::INVALID_JUSTIFICATION_LENGTH,
        422,
        'A missing justification is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertJustification(str_repeat('x', 501)),
        ProductLoadingValidationException::INVALID_JUSTIFICATION_LENGTH,
        422,
        'A justification above 500 characters is rejected'
    );

    $exception = ProductLoadingValidationException::invalidJustificationLength('cancel_reason', 7);
    assertValidatorCondition(
        $exception->getErrorCode() === ProductLoadingValidationException::INVALID_JUSTIFICATION_LENGTH
            && $exception->getHttpStatusCode() === 422
            && $exception->getField() === 'cancel_reason'
            && $exception->getContext()['length'] === 7,
        'The exception transports error code, HTTP status, field and context'
    );

    // ─────────────────────────────────────────────────────────────────────
    // Ajuste manual de lote en furgoneta (EARS 01.3, QA 2, QA 3)
    // ─────────────────────────────────────────────────────────────────────
    $validAdjustment = ProductLoadingValidator::assertAdjustment(
        [
            'reason' => 'Ajuste por recuento físico del almacén',
            'quantity_available' => '12',
            'batch_code' => 'lote-sw-01',
            'expiration_date' => '2026-11-16',
        ],
        $coldProduct,
        null,
        $today
    );
    assertValidatorCondition(
        $validAdjustment['batch_code'] === 'LOTE-SW-01'
            && $validAdjustment['quantity_available'] === 12
            && $validAdjustment['expiration_date']?->format('Y-m-d') === '2026-11-16'
            && $validAdjustment['reason'] === 'Ajuste por recuento físico del almacén',
        'A valid cold batch adjustment is normalized for persistence'
    );

    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertAdjustment(
            ['quantity_available' => 5, 'batch_code' => 'LOTE-SW-01', 'expiration_date' => '2026-11-16'],
            $coldProduct,
            null,
            $today
        ),
        ProductLoadingValidationException::INVALID_JUSTIFICATION_LENGTH,
        422,
        'The manual adjustment requires a justification'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertAdjustment(
            ['reason' => 'Ajuste por recuento físico del almacén', 'quantity_available' => -1, 'batch_code' => 'LOTE-SW-01', 'expiration_date' => '2026-11-16'],
            $coldProduct,
            null,
            $today
        ),
        ProductLoadingValidationException::INVALID_LOADING_QUANTITY,
        422,
        'A negative quantity is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertAdjustment(
            ['reason' => 'Ajuste por recuento físico del almacén', 'quantity_available' => 100000, 'batch_code' => 'LOTE-SW-01', 'expiration_date' => '2026-11-16'],
            $coldProduct,
            null,
            $today
        ),
        ProductLoadingValidationException::INVALID_LOADING_QUANTITY,
        422,
        'A quantity above 99999 is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertAdjustment(
            ['reason' => 'Ajuste por recuento físico del almacén', 'quantity_available' => 'doce', 'batch_code' => 'LOTE-SW-01', 'expiration_date' => '2026-11-16'],
            $coldProduct,
            null,
            $today
        ),
        ProductLoadingValidationException::INVALID_LOADING_QUANTITY,
        422,
        'A non-numeric quantity is rejected'
    );

    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertAdjustment(
            ['reason' => 'Ajuste por recuento físico del almacén', 'quantity_available' => 5, 'batch_code' => null, 'expiration_date' => '2026-11-16'],
            $coldProduct,
            null,
            $today
        ),
        ProductLoadingValidationException::EXPIRED_OR_INVALID_BATCH,
        422,
        'A cold product without real batch is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertAdjustment(
            ['reason' => 'Ajuste por recuento físico del almacén', 'quantity_available' => 5, 'batch_code' => 'LOTE-SW-01', 'expiration_date' => null],
            $coldProduct,
            null,
            $today
        ),
        ProductLoadingValidationException::EXPIRED_OR_INVALID_BATCH,
        422,
        'A cold product without expiration date is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertAdjustment(
            ['reason' => 'Ajuste por recuento físico del almacén', 'quantity_available' => 5, 'batch_code' => 'LOTE-SW-01', 'expiration_date' => '2026-13-40'],
            $coldProduct,
            null,
            $today
        ),
        ProductLoadingValidationException::EXPIRED_OR_INVALID_BATCH,
        422,
        'An impossible calendar date is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertAdjustment(
            ['reason' => 'Ajuste por recuento físico del almacén', 'quantity_available' => 5, 'batch_code' => 'LOTE-SW-01', 'expiration_date' => '2026-11-13'],
            $coldProduct,
            null,
            $today
        ),
        ProductLoadingValidationException::EXPIRED_OR_INVALID_BATCH,
        422,
        'An expired batch cannot be created with units'
    );
    assertValidatorCondition(
        ProductLoadingValidator::assertAdjustment(
            ['reason' => 'Retirada completa del lote caducado', 'quantity_available' => 0, 'batch_code' => 'LOTE-SW-01', 'expiration_date' => '2026-11-13'],
            $coldProduct,
            null,
            $today
        )['quantity_available'] === 0,
        'An expired batch can be recorded at zero for withdrawal'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertAdjustment(
            ['reason' => 'Ajuste por recuento físico del almacén', 'quantity_available' => 5, 'batch_code' => 'LOTE-SW-01', 'expiration_date' => '2026-11-19'],
            $coldProduct,
            null,
            $today
        ),
        ProductLoadingValidationException::EXPIRATION_EXCEEDS_MAX_SHELF_LIFE,
        422,
        'A date beyond max_shelf_life_days is rejected (QA 2)'
    );
    assertValidatorCondition(
        ProductLoadingValidator::assertAdjustment(
            ['reason' => 'Ajuste por recuento físico del almacén', 'quantity_available' => 5, 'batch_code' => 'LOTE-SW-01', 'expiration_date' => '2026-11-18'],
            $coldProduct,
            null,
            $today
        )['expiration_date']?->format('Y-m-d') === '2026-11-18',
        'The max_shelf_life boundary today + 5 days is accepted'
    );

    $expiredLot = new RestockerVanProductStock(
        3,
        12,
        7,
        'LOTE-OLD',
        $today->modify('-1 day'),
        4,
        true
    );
    assertValidatorCondition(
        ProductLoadingValidator::assertAdjustment(
            ['reason' => 'Reducción de lote caducado a cero', 'quantity_available' => 2, 'batch_code' => 'LOTE-OLD', 'expiration_date' => '2026-11-12'],
            $coldProduct,
            $expiredLot,
            $today
        )['quantity_available'] === 2,
        'An expired existing lot can be reduced'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertAdjustment(
            ['reason' => 'Incremento indebido de lote caducado', 'quantity_available' => 7, 'batch_code' => 'LOTE-OLD', 'expiration_date' => '2026-11-12'],
            $coldProduct,
            $expiredLot,
            $today
        ),
        ProductLoadingValidationException::EXPIRED_OR_INVALID_BATCH,
        422,
        'An expired existing lot cannot be increased'
    );

    $existingLot = new RestockerVanProductStock(
        4,
        12,
        7,
        'LOTE-SW-01',
        $today->modify('+2 days'),
        6,
        true
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertAdjustment(
            ['reason' => 'Ajuste por recuento físico del almacén', 'quantity_available' => 8, 'batch_code' => 'LOTE-SW-01', 'expiration_date' => '2026-11-17'],
            $coldProduct,
            $existingLot,
            $today
        ),
        ProductLoadingValidationException::BATCH_EXPIRATION_MISMATCH,
        409,
        'An existing batch cannot change its expiration date'
    );

    $ambientAdjustment = ProductLoadingValidator::assertAdjustment(
        ['reason' => 'Reposición de refrescos sin lote', 'quantity_available' => 30],
        $ambientProduct,
        null,
        $today
    );
    assertValidatorCondition(
        $ambientAdjustment['batch_code'] === 'SIN-LOTE'
            && $ambientAdjustment['expiration_date'] === null,
        'A non-cold product accepts the normalized batch and no expiration'
    );

    // ─────────────────────────────────────────────────────────────────────
    // Despacho (EARS 03.3, D2, QA 2)
    // ─────────────────────────────────────────────────────────────────────
    $pendingOrder = new ProductLoadingOrder(null, 'PLO-2026-8001', 12, 3);
    $dispatchedOrder = new ProductLoadingOrder(
        null,
        'PLO-2026-8002',
        12,
        3,
        ProductLoadingOrderStatus::DISPATCHED
    );

    $coldLine = new ProductLoadingOrderItem(
        null,
        1,
        101,
        ProductLoadingItemReason::STOCKOUT_ALERT,
        24,
        24,
        'LOTE-SW-01',
        $today->modify('+2 days')
    );

    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertDispatch(
            $dispatchedOrder,
            [['item' => $coldLine, 'product' => $coldProduct]],
            3.5,
            $today
        ),
        ProductLoadingValidationException::INVALID_ORDER_STATE_TRANSITION,
        409,
        'Only a pending order can be dispatched'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertDispatch($pendingOrder, [], null, $today),
        ProductLoadingValidationException::EMPTY_LOADING_ORDER_ITEMS,
        422,
        'An order without lines cannot be dispatched'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertDispatch(
            $pendingOrder,
            [['item' => $coldLine->withDispatch(0, 'LOTE-SW-01', $today->modify('+2 days')), 'product' => $coldProduct]],
            null,
            $today
        ),
        ProductLoadingValidationException::EMPTY_LOADING_ORDER_ITEMS,
        422,
        'An order with zero dispatched units cannot be dispatched'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertDispatch(
            $pendingOrder,
            [['item' => $coldLine, 'product' => $coldProduct]],
            null,
            $today
        ),
        ProductLoadingValidationException::COLD_CHAMBER_TEMP_EXCEEDED,
        422,
        'A cold dispatch without temperature is rejected (Art. II)'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertDispatch(
            $pendingOrder,
            [['item' => $coldLine, 'product' => $coldProduct]],
            4.1,
            $today
        ),
        ProductLoadingValidationException::COLD_CHAMBER_TEMP_EXCEEDED,
        422,
        'A cold dispatch above 4,0 °C is rejected'
    );

    $validDispatchError = null;
    try {
        ProductLoadingValidator::assertDispatch(
            $pendingOrder,
            [['item' => $coldLine, 'product' => $coldProduct]],
            4.0,
            $today
        );
    } catch (Throwable $exception) {
        $validDispatchError = $exception->getMessage();
    }
    assertValidatorCondition(
        $validDispatchError === null,
        'A cold dispatch at the 4,0 °C boundary passes the lock: ' . (string)$validDispatchError
    );

    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertDispatch(
            $pendingOrder,
            [['item' => $coldLine->withDispatch(24, 'SIN-LOTE', $today->modify('+2 days')), 'product' => $coldProduct]],
            3.5,
            $today
        ),
        ProductLoadingValidationException::EXPIRED_OR_INVALID_BATCH,
        422,
        'A cold dispatch with SIN-LOTE is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertDispatch(
            $pendingOrder,
            [['item' => $coldLine->withDispatch(24, 'LOTE-SW-01', $today), 'product' => $coldProduct]],
            3.5,
            $today
        ),
        ProductLoadingValidationException::EXPIRED_OR_INVALID_BATCH,
        422,
        'A cold dispatch with a batch that expires today is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertDispatch(
            $pendingOrder,
            [['item' => $coldLine->withDispatch(24, 'LOTE-SW-01', $today->modify('+6 days')), 'product' => $coldProduct]],
            3.5,
            $today
        ),
        ProductLoadingValidationException::EXPIRATION_EXCEEDS_MAX_SHELF_LIFE,
        422,
        'A cold dispatch beyond the shelf life is rejected (QA 2)'
    );

    $ambientLine = new ProductLoadingOrderItem(
        null,
        1,
        102,
        ProductLoadingItemReason::ROUTE_GAP_FILL,
        30,
        30,
        null,
        null
    );
    $ambientDispatchError = null;
    try {
        ProductLoadingValidator::assertDispatch(
            $pendingOrder,
            [['item' => $ambientLine, 'product' => $ambientProduct]],
            null,
            $today
        );
    } catch (Throwable $exception) {
        $ambientDispatchError = $exception->getMessage();
    }
    assertValidatorCondition(
        $ambientDispatchError === null,
        'An ambient dispatch needs no cold chamber temperature: ' . (string)$ambientDispatchError
    );

    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertDispatch(
            $pendingOrder,
            [['item' => $coldLine->withActive(false), 'product' => $coldProduct]],
            3.5,
            $today
        ),
        ProductLoadingValidationException::EMPTY_LOADING_ORDER_ITEMS,
        422,
        'Logically withdrawn lines do not count towards dispatch'
    );

    // ─────────────────────────────────────────────────────────────────────
    // Cancelación justificada (EARS 03.4, Art. V.1)
    // ─────────────────────────────────────────────────────────────────────
    assertValidatorCondition(
        ProductLoadingValidator::assertCancellation($pendingOrder, str_repeat('á', 20)) === str_repeat('á', 20),
        'A pending order is cancelled with twenty real characters'
    );
    $dispatchedOrderForCancel = new ProductLoadingOrder(
        null,
        'PLO-2026-8003',
        12,
        3,
        ProductLoadingOrderStatus::DISPATCHED
    );
    assertValidatorCondition(
        ProductLoadingValidator::assertCancellation(
            $dispatchedOrderForCancel,
            'Cancelada por falta de stock en cámara'
        ) === 'Cancelada por falta de stock en cámara',
        'A dispatched order can still be cancelled with a reason'
    );
    $confirmedOrder = new ProductLoadingOrder(
        null,
        'PLO-2026-8004',
        12,
        3,
        ProductLoadingOrderStatus::CONFIRMED_BY_RESTOCKER
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertCancellation(
            $confirmedOrder,
            'Cancelada por falta de stock en cámara'
        ),
        ProductLoadingValidationException::ORDER_CANCELLATION_NOT_ALLOWED,
        409,
        'A received order cannot be cancelled (RF-03.4)'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertCancellation($pendingOrder, 'falta stock'),
        ProductLoadingValidationException::INVALID_JUSTIFICATION_LENGTH,
        422,
        'A short cancellation reason is rejected'
    );

    // ─────────────────────────────────────────────────────────────────────
    // Liquidación al cierre (EARS 04.2, D3, QA 4, Art. V.1)
    // ─────────────────────────────────────────────────────────────────────
    $reconcilableOrder = new ProductLoadingOrder(
        null,
        'PLO-2026-8005',
        12,
        3,
        ProductLoadingOrderStatus::CONFIRMED_BY_RESTOCKER
    );

    $dispatchedLine = new ProductLoadingOrderItem(
        null,
        1,
        101,
        ProductLoadingItemReason::STOCKOUT_ALERT,
        24,
        24,
        'LOTE-SW-01',
        $today->modify('+2 days')
    );
    $pendingLine = new ProductLoadingOrderItem(
        null,
        1,
        102,
        ProductLoadingItemReason::ROUTE_GAP_FILL,
        10,
        0,
        null,
        null
    );

    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertReconciliation(
            $pendingOrder,
            [['item' => $dispatchedLine, 'van_stock_before_reconcile' => 24, 'restocked_in_machines_qty' => 20, 'withdrawn_spoilage_qty' => 0, 'counted_return_qty' => 4]],
            null
        ),
        ProductLoadingValidationException::INVALID_ORDER_STATE_TRANSITION,
        409,
        'Only a received order can be reconciled'
    );

    $reconciliation = ProductLoadingValidator::assertReconciliation(
        $reconcilableOrder,
        [
            [
                'item' => $dispatchedLine,
                'van_stock_before_reconcile' => 20,
                'restocked_in_machines_qty' => 12,
                'withdrawn_spoilage_qty' => 0,
                'counted_return_qty' => 7,
            ],
            ['item' => $pendingLine],
        ],
        str_repeat('á', 20)
    );
    assertValidatorCondition(
        count($reconciliation['items']) === 2
            && $reconciliation['discrepancy_lines_count'] === 1
            && $reconciliation['items'][0]['line']->getExpectedReturnQty() === 8
            && $reconciliation['items'][0]['line']->getDiscrepancyQty() === -1
            && $reconciliation['items'][1]['line']->getExpectedReturnQty() === 0
            && $reconciliation['reconciliation_notes'] === str_repeat('á', 20),
        'The reconciliation computes the arithmetic and seals the undispatched line at zero'
    );

    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertReconciliation(
            $reconcilableOrder,
            [[
                'item' => $dispatchedLine,
                'van_stock_before_reconcile' => 20,
                'restocked_in_machines_qty' => 12,
                'withdrawn_spoilage_qty' => 0,
                'counted_return_qty' => 7,
            ]],
            null
        ),
        ProductLoadingValidationException::RECONCILIATION_DISCREPANCY_REASON_REQUIRED,
        422,
        'A discrepancy without reconciliation note is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertReconciliation(
            $reconcilableOrder,
            [[
                'item' => $dispatchedLine,
                'van_stock_before_reconcile' => 20,
                'restocked_in_machines_qty' => 12,
                'withdrawn_spoilage_qty' => 0,
                'counted_return_qty' => 7,
            ]],
            'falta 1'
        ),
        ProductLoadingValidationException::RECONCILIATION_DISCREPANCY_REASON_REQUIRED,
        422,
        'A discrepancy with a short note is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertReconciliation(
            $reconcilableOrder,
            [[
                'item' => $dispatchedLine,
                'van_stock_before_reconcile' => 20,
                'restocked_in_machines_qty' => 18,
                'withdrawn_spoilage_qty' => 5,
                'counted_return_qty' => 0,
            ]],
            null
        ),
        ProductLoadingValidationException::RECONCILIATION_EXCEEDS_AVAILABLE_STOCK,
        422,
        'Restocked plus withdrawn above the previous balance is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertReconciliation(
            $reconcilableOrder,
            [[
                'item' => $dispatchedLine,
                'van_stock_before_reconcile' => 20,
                'restocked_in_machines_qty' => 12,
                'withdrawn_spoilage_qty' => 0,
                'counted_return_qty' => -1,
            ]],
            null
        ),
        ProductLoadingValidationException::INVALID_LOADING_QUANTITY,
        422,
        'A negative counted return is rejected'
    );
    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertReconciliation(
            $reconcilableOrder,
            [[
                'item' => $dispatchedLine,
                'van_stock_before_reconcile' => 20,
                'restocked_in_machines_qty' => 12,
                'withdrawn_spoilage_qty' => 0,
                'counted_return_qty' => 100000,
            ]],
            null
        ),
        ProductLoadingValidationException::INVALID_LOADING_QUANTITY,
        422,
        'A counted return above 99999 is rejected'
    );

    $cleanReconciliation = ProductLoadingValidator::assertReconciliation(
        $reconcilableOrder,
        [[
            'item' => $dispatchedLine,
            'van_stock_before_reconcile' => 20,
            'restocked_in_machines_qty' => 12,
            'withdrawn_spoilage_qty' => 0,
            'counted_return_qty' => 8,
        ]],
        null
    );
    assertValidatorCondition(
        $cleanReconciliation['discrepancy_lines_count'] === 0
            && $cleanReconciliation['items'][0]['line']->getDiscrepancyQty() === 0
            && $cleanReconciliation['reconciliation_notes'] === null,
        'A clean reconciliation needs no note'
    );

    assertValidatorThrows(
        static fn() => ProductLoadingValidator::assertReconciliation(
            $reconcilableOrder,
            [['item' => $pendingLine->withActive(false)]],
            null
        ),
        ProductLoadingValidationException::EMPTY_LOADING_ORDER_ITEMS,
        422,
        'An order with only withdrawn lines cannot be reconciled'
    );

    echo "Product loading validator tests passed. Total Assertions: {$assertions}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Product loading validator test failed: {$exception->getMessage()}\n");
    exit(1);
}
