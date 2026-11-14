<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\ProductLoadingValidationException;
use VendGuard\Core\Domain\Model\ProductLoadingOrder;
use VendGuard\Core\Domain\Model\ProductLoadingOrderItem;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;
use VendGuard\Core\Domain\Model\VendingProduct;

/**
 * ProductLoadingValidator
 *
 * Validador puro de las órdenes de carga de furgoneta y del stock por lote
 * (Módulo 33; RF-01.3, RF-03.3, RF-03.4, RF-04.2; D2, D3, C1, QA 2, QA 3, QA 4;
 * Art. II y Art. V.1).
 *
 * Reglas que muerde:
 *   1. **Cerrojo térmico de cámara (Art. II).** Si la orden lleva al menos una
 *      línea activa refrigerada con `dispatched_qty > 0`, la temperatura de
 *      salida de cámara es obligatoria y debe estar en `[-5,0, 4,0] °C`. Sin
 *      líneas refrigeradas es opcional, pero si se declara también debe caber en
 *      el rango físico.
 *   2. **Lote y caducidad (QA 2, QA 3).** En producto refrigerado el lote debe
 *      ser real (distinto de `'SIN-LOTE'`), cumplir `^[A-Z0-9_-]{2,40}$` y la
 *      caducidad debe ser estrictamente posterior a hoy y no superar
 *      `CURRENT_DATE + max_shelf_life_days`. Un lote ya existente no puede
 *      cambiar de fecha (`409 BATCH_EXPIRATION_MISMATCH`). Un lote caducado solo
 *      se admite para reducirlo o dejarlo en `0`, nunca para incrementarlo.
 *   3. **Cotas y líneas (C1).** `dispatched_qty` en `0..99999`, al menos una
 *      unidad despachada y ninguna orden sin líneas activas.
 *   4. **Estados (RF-03.4, RF-04.2).** Despachar exige `PENDING_PICKING`,
 *      cancelar `PENDING_PICKING` o `DISPATCHED`, y liquidar
 *      `CONFIRMED_BY_RESTOCKER`.
 *   5. **Rigor textual (Art. V.1).** Toda justificación obligatoria —motivo de
 *      cancelación, de ajuste de furgoneta o nota de descuadre— exige al menos
 *      **20 caracteres reales**; los espacios repetidos no cuentan de más.
 *   6. **Cuadre de liquidación (D3, QA 4).** Las líneas no despachadas se sellan
 *      en `0`; en las despachadas, abastecidas + mermas no pueden superar el
 *      saldo previo y el descuadre exige la nota justificativa.
 *
 * El validador no escribe ni consulta la base: entran las entidades y el payload,
 * y salen los valores normalizados que el servicio persistirá, o una excepción
 * con el código exacto del contrato. Esa es la única traducción de reglas a
 * códigos HTTP del módulo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class ProductLoadingValidator
{
    public const MIN_JUSTIFICATION_LENGTH = 20;
    public const MAX_TEXT_LENGTH = 500;

    public const MIN_TEMPERATURE_C = -5.0;
    public const MAX_TEMPERATURE_C = 4.0;

    public const DEFAULT_BATCH_CODE = 'SIN-LOTE';
    public const BATCH_PATTERN = '/^[A-Z0-9_-]{2,40}$/';

    public const MIN_QUANTITY = 0;
    public const MAX_QUANTITY = 99999;

    /**
     * Temperatura de salida de cámara normalizada (Art. II).
     *
     * @throws ProductLoadingValidationException Si es obligatoria y falta, o si
     *                                           queda fuera de `[-5,0, 4,0] °C`.
     */
    public static function assertColdChamberTemperature(
        ?float $temperature,
        bool $required,
        ?string $orderCode = null
    ): ?float {
        if ($temperature === null) {
            if ($required) {
                throw ProductLoadingValidationException::coldChamberTempExceeded(null, $orderCode);
            }

            return null;
        }

        if ($temperature < self::MIN_TEMPERATURE_C || $temperature > self::MAX_TEMPERATURE_C) {
            throw ProductLoadingValidationException::coldChamberTempExceeded($temperature, $orderCode);
        }

        return round($temperature, 1);
    }

    /**
     * Normaliza el código de lote y exige lote real cuando hay cadena de frío.
     *
     * @throws ProductLoadingValidationException
     */
    public static function normalizeBatchCode(?string $batchCode, bool $required): string
    {
        $batch = $batchCode === null ? '' : strtoupper(trim($batchCode));
        if ($batch === '') {
            $batch = self::DEFAULT_BATCH_CODE;
        }

        if ($required && $batch === self::DEFAULT_BATCH_CODE) {
            throw ProductLoadingValidationException::expiredOrInvalidBatch(
                'el producto refrigerado exige un lote real distinto de SIN-LOTE.',
                ['batch_code' => $batchCode]
            );
        }

        if (preg_match(self::BATCH_PATTERN, $batch) !== 1) {
            throw ProductLoadingValidationException::expiredOrInvalidBatch(
                sprintf('el lote «%s» debe cumplir ^[A-Z0-9_-]{2,40}$.', $batch),
                ['batch_code' => $batchCode]
            );
        }

        return $batch;
    }

    /**
     * Justificación obligatoria de al menos 20 caracteres reales (Art. V.1).
     *
     * @throws ProductLoadingValidationException
     */
    public static function assertJustification(?string $reason, string $field = 'reason'): string
    {
        $normalized = self::normalizeText((string)($reason ?? ''));
        $length = mb_strlen($normalized, 'UTF-8');

        if ($length < self::MIN_JUSTIFICATION_LENGTH || $length > self::MAX_TEXT_LENGTH) {
            throw ProductLoadingValidationException::invalidJustificationLength(
                $field,
                $length,
                self::MIN_JUSTIFICATION_LENGTH,
                self::MAX_TEXT_LENGTH
            );
        }

        return $normalized;
    }

    /**
     * Ajuste manual de un lote en la furgoneta desde Coordinación (EARS 01.3).
     *
     * Admite dejar el lote en `0` o reducirlo aunque ya esté caducado, porque esa
     * es la vía legítima de retirada; bloquea darlo de alta o incrementarlo.
     *
     * @param array<string, mixed> $payload Claves `reason`, `quantity_available`,
     *                                      `batch_code` y `expiration_date`.
     *
     * @return array{
     *     batch_code: string,
     *     expiration_date: DateTimeImmutable|null,
     *     quantity_available: int,
     *     reason: string
     * }
     *
     * @throws ProductLoadingValidationException
     */
    public static function assertAdjustment(
        array $payload,
        VendingProduct $product,
        ?RestockerVanProductStock $existingLot = null,
        ?DateTimeImmutable $today = null
    ): array {
        $reference = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $reason = self::assertJustification($payload['reason'] ?? null);

        $quantity = self::requireInteger(
            $payload['quantity_available'] ?? null,
            'quantity_available',
            self::MIN_QUANTITY,
            self::MAX_QUANTITY
        );

        $requiresColdChain = $product->requiresColdChain();
        $batch = self::normalizeBatchCode(
            isset($payload['batch_code']) && is_string($payload['batch_code']) ? $payload['batch_code'] : null,
            $requiresColdChain
        );
        $expirationDate = self::parseDate($payload['expiration_date'] ?? null);

        if ($requiresColdChain && $expirationDate === null) {
            throw ProductLoadingValidationException::expiredOrInvalidBatch(
                'el producto refrigerado exige fecha de caducidad.',
                ['sku' => $product->getSku(), 'batch_code' => $batch]
            );
        }

        if ($expirationDate !== null) {
            $maxShelfLifeDays = $product->getMaxShelfLifeDays();
            if ($maxShelfLifeDays !== null
                && $expirationDate->setTime(0, 0) > $reference->modify('+' . $maxShelfLifeDays . ' days')) {
                throw ProductLoadingValidationException::expirationExceedsMaxShelfLife(
                    $expirationDate->format('Y-m-d'),
                    $maxShelfLifeDays,
                    $reference->format('Y-m-d')
                );
            }

            if ($expirationDate->setTime(0, 0) <= $reference) {
                $existingQuantity = $existingLot?->getQuantityAvailable();
                $growsExpiredLot = $quantity > 0
                    && ($existingQuantity === null || $quantity > $existingQuantity);

                if ($growsExpiredLot) {
                    throw ProductLoadingValidationException::expiredOrInvalidBatch(
                        sprintf(
                            'el lote caducado %s solo puede reducirse o ajustarse a 0, no incrementarse.',
                            $batch
                        ),
                        ['sku' => $product->getSku(), 'batch_code' => $batch, 'quantity' => $quantity]
                    );
                }
            }

            if ($existingLot !== null
                && $batch !== self::DEFAULT_BATCH_CODE
                && $existingLot->getExpirationDate() !== null
                && $existingLot->getExpirationDate()->format('Y-m-d') !== $expirationDate->format('Y-m-d')) {
                throw ProductLoadingValidationException::batchExpirationMismatch(
                    $batch,
                    $existingLot->getExpirationDate()->format('Y-m-d'),
                    $expirationDate->format('Y-m-d')
                );
            }
        }

        return [
            'batch_code' => $batch,
            'expiration_date' => $expirationDate,
            'quantity_available' => $quantity,
            'reason' => $reason,
        ];
    }

    /**
     * Cerrojo sanitario del despacho de una orden (EARS 03.3).
     *
     * @param list<array{item: ProductLoadingOrderItem, product: VendingProduct}> $items
     *
     * @throws ProductLoadingValidationException
     */
    public static function assertDispatch(
        ProductLoadingOrder $order,
        array $items,
        ?float $temperature,
        ?DateTimeImmutable $today = null
    ): void {
        if (!$order->canBeDispatched()) {
            throw ProductLoadingValidationException::invalidOrderStateTransition(
                $order->getStatus()->value,
                'dispatch'
            );
        }

        $reference = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $dispatchedTotal = 0;
        /** @var list<array{item: ProductLoadingOrderItem, product: VendingProduct}> $coldLines */
        $coldLines = [];

        foreach ($items as $entry) {
            $item = $entry['item'] ?? null;
            $product = $entry['product'] ?? null;

            if (!$item instanceof ProductLoadingOrderItem || !$product instanceof VendingProduct) {
                throw new InvalidArgumentException(
                    'Cada línea de despacho debe declarar su ProductLoadingOrderItem y su VendingProduct.'
                );
            }

            if (!$item->isActive()) {
                continue;
            }

            $quantity = $item->getDispatchedQty() ?? 0;
            if ($quantity < self::MIN_QUANTITY || $quantity > self::MAX_QUANTITY) {
                throw ProductLoadingValidationException::invalidLoadingQuantity(
                    'dispatched_qty',
                    $quantity,
                    self::MIN_QUANTITY,
                    self::MAX_QUANTITY
                );
            }

            $dispatchedTotal += $quantity;

            if ($quantity > 0 && $product->requiresColdChain()) {
                $coldLines[] = ['item' => $item, 'product' => $product];
            }
        }

        if ($dispatchedTotal < 1) {
            throw ProductLoadingValidationException::emptyLoadingOrderItems($order->getOrderCode());
        }

        self::assertColdChamberTemperature($temperature, $coldLines !== [], $order->getOrderCode());

        foreach ($coldLines as $coldLine) {
            $item = $coldLine['item'];
            $product = $coldLine['product'];

            $batch = $item->getBatchCode();
            if ($batch === null
                || $batch === self::DEFAULT_BATCH_CODE
                || preg_match(self::BATCH_PATTERN, $batch) !== 1) {
                throw ProductLoadingValidationException::expiredOrInvalidBatch(
                    sprintf(
                        'la línea del producto refrigerado %s no declara un lote real y válido.',
                        $product->getSku()
                    ),
                    ['sku' => $product->getSku(), 'batch_code' => $batch, 'field' => 'batch_code']
                );
            }

            $expirationDate = $item->getExpirationDate();
            if ($expirationDate === null || $expirationDate->setTime(0, 0) <= $reference) {
                throw ProductLoadingValidationException::expiredOrInvalidBatch(
                    sprintf(
                        'el lote %s del producto refrigerado %s no declara una caducidad posterior a hoy.',
                        $batch,
                        $product->getSku()
                    ),
                    [
                        'sku' => $product->getSku(),
                        'batch_code' => $batch,
                        'expiration_date' => $expirationDate?->format('Y-m-d'),
                        'field' => 'expiration_date',
                    ]
                );
            }

            $maxShelfLifeDays = $product->getMaxShelfLifeDays();
            if ($maxShelfLifeDays !== null
                && $expirationDate->setTime(0, 0) > $reference->modify('+' . $maxShelfLifeDays . ' days')) {
                throw ProductLoadingValidationException::expirationExceedsMaxShelfLife(
                    $expirationDate->format('Y-m-d'),
                    $maxShelfLifeDays,
                    $reference->format('Y-m-d')
                );
            }
        }
    }

    /**
     * Cancelación justificada de una orden (EARS 03.4, Art. V.1).
     *
     * @throws ProductLoadingValidationException
     */
    public static function assertCancellation(ProductLoadingOrder $order, ?string $reason): string
    {
        if (!$order->canBeCancelled()) {
            throw ProductLoadingValidationException::orderCancellationNotAllowed(
                $order->getStatus()->value
            );
        }

        return self::assertJustification($reason, 'cancel_reason');
    }

    /**
     * Cuadre de la liquidación al cierre (EARS 04.2, D3, QA 4, Art. V.1).
     *
     * Las líneas no despachadas se sellan en `0` sin exigir entrada manual; en
     * las despachadas se valida el saldo previo y la aritmética del retorno. Si
     * alguna línea declara descuadre, la nota de liquidación pasa a ser
     * obligatoria con al menos 20 caracteres reales.
     *
     * @param list<array{
     *     item: ProductLoadingOrderItem,
     *     van_stock_before_reconcile?: int|string|null,
     *     restocked_in_machines_qty?: int|string|null,
     *     withdrawn_spoilage_qty?: int|string|null,
     *     counted_return_qty?: int|string|null
     * }> $items
     *
     * @return array{
     *     items: list<array{item: ProductLoadingOrderItem, line: ProductLoadingOrderItem}>,
     *     reconciliation_notes: string|null,
     *     discrepancy_lines_count: int
     * }
     *
     * @throws ProductLoadingValidationException
     */
    public static function assertReconciliation(
        ProductLoadingOrder $order,
        array $items,
        ?string $notes
    ): array {
        if (!$order->canBeReconciled()) {
            throw ProductLoadingValidationException::invalidOrderStateTransition(
                $order->getStatus()->value,
                'reconcile'
            );
        }

        $rows = [];
        $discrepancyLines = 0;

        foreach ($items as $entry) {
            $item = $entry['item'] ?? null;

            if (!$item instanceof ProductLoadingOrderItem) {
                throw new InvalidArgumentException(
                    'Cada línea de liquidación debe declarar su ProductLoadingOrderItem.'
                );
            }

            if (!$item->isActive()) {
                continue;
            }

            if (!$item->isDispatched()) {
                $rows[] = ['item' => $item, 'line' => $item->sealedAtZero()];
                continue;
            }

            $before = self::requireInteger(
                $entry['van_stock_before_reconcile'] ?? null,
                'van_stock_before_reconcile',
                self::MIN_QUANTITY,
                self::MAX_QUANTITY
            );
            $restocked = self::requireInteger(
                $entry['restocked_in_machines_qty'] ?? null,
                'restocked_in_machines_qty',
                self::MIN_QUANTITY,
                self::MAX_QUANTITY
            );
            $withdrawn = self::requireInteger(
                $entry['withdrawn_spoilage_qty'] ?? null,
                'withdrawn_spoilage_qty',
                self::MIN_QUANTITY,
                self::MAX_QUANTITY
            );
            $counted = self::requireInteger(
                $entry['counted_return_qty'] ?? null,
                'counted_return_qty',
                self::MIN_QUANTITY,
                self::MAX_QUANTITY
            );

            if ($restocked + $withdrawn > $before) {
                throw ProductLoadingValidationException::reconciliationExceedsAvailableStock(
                    $before,
                    $restocked,
                    $withdrawn
                );
            }

            $line = $item->reconciled($before, $restocked, $withdrawn, $counted);
            if ($line->hasDiscrepancy()) {
                $discrepancyLines++;
            }

            $rows[] = ['item' => $item, 'line' => $line];
        }

        if ($rows === []) {
            throw ProductLoadingValidationException::emptyLoadingOrderItems($order->getOrderCode());
        }

        $normalizedNotes = null;
        if ($discrepancyLines > 0) {
            // El contrato tiene un código propio para este caso: un descuadre sin
            // nota suficiente no es una justificación corta cualquiera, es una
            // liquidación que exige motivo (RECONCILIATION_DISCREPANCY_REASON_REQUIRED).
            $normalizedNotes = self::normalizeText((string)($notes ?? ''));
            $notesLength = mb_strlen($normalizedNotes, 'UTF-8');

            if ($notesLength < self::MIN_JUSTIFICATION_LENGTH) {
                throw ProductLoadingValidationException::reconciliationDiscrepancyReasonRequired(
                    $discrepancyLines
                );
            }

            if ($notesLength > self::MAX_TEXT_LENGTH) {
                throw ProductLoadingValidationException::invalidJustificationLength(
                    'reconciliation_notes',
                    $notesLength,
                    self::MIN_JUSTIFICATION_LENGTH,
                    self::MAX_TEXT_LENGTH
                );
            }
        } elseif ($notes !== null) {
            $normalizedNotes = self::normalizeText($notes);
            if (mb_strlen($normalizedNotes, 'UTF-8') > self::MAX_TEXT_LENGTH) {
                throw ProductLoadingValidationException::invalidJustificationLength(
                    'reconciliation_notes',
                    mb_strlen($normalizedNotes, 'UTF-8'),
                    0,
                    self::MAX_TEXT_LENGTH
                );
            }
        }

        return [
            'items' => $rows,
            'reconciliation_notes' => $normalizedNotes,
            'discrepancy_lines_count' => $discrepancyLines,
        ];
    }

    /**
     * Recorta extremos y colapsa espacios repetidos sin tocar los acentos.
     */
    public static function normalizeText(string $value): string
    {
        $trimmed = trim($value);
        $collapsed = preg_replace('/\s+/u', ' ', $trimmed);

        return $collapsed ?? $trimmed;
    }

    /**
     * @throws ProductLoadingValidationException
     */
    private static function requireInteger(mixed $rawValue, string $field, int $minimum, int $maximum): int
    {
        $value = self::normalizeInteger($rawValue);
        if ($value === null || $value < $minimum || $value > $maximum) {
            throw ProductLoadingValidationException::invalidLoadingQuantity(
                $field,
                $rawValue,
                $minimum,
                $maximum
            );
        }

        return $value;
    }

    /**
     * @throws ProductLoadingValidationException
     */
    private static function parseDate(mixed $rawValue): ?DateTimeImmutable
    {
        if ($rawValue === null) {
            return null;
        }

        if ($rawValue instanceof DateTimeImmutable) {
            return $rawValue->setTime(0, 0);
        }

        if (is_string($rawValue) && trim($rawValue) !== '') {
            $value = trim($rawValue);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if ($date !== false && $date->format('Y-m-d') === $value) {
                    return $date;
                }
            }
        }

        throw ProductLoadingValidationException::expiredOrInvalidBatch(
            sprintf(
                'la fecha de caducidad «%s» no es una fecha real con formato YYYY-MM-DD.',
                is_scalar($rawValue) ? (string)$rawValue : gettype($rawValue)
            ),
            ['field' => 'expiration_date']
        );
    }

    private static function normalizeInteger(mixed $rawValue): ?int
    {
        if (is_int($rawValue)) {
            return $rawValue;
        }

        if (is_float($rawValue) && is_finite($rawValue) && floor($rawValue) === $rawValue) {
            return (int)$rawValue;
        }

        if (is_string($rawValue) && preg_match('/^-?\d+$/', trim($rawValue)) === 1) {
            return (int)trim($rawValue);
        }

        return null;
    }
}
