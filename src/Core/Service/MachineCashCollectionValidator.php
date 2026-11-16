<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Enum\CashCollectionStatus;
use VendGuard\Core\Domain\Exception\MachineCashCollectionValidationException;
use VendGuard\Core\Domain\Model\MachineCashCollection;

/**
 * MachineCashCollectionValidator
 *
 * Validador puro de la recaudación de efectivo, la carga de tubos de cambio y el
 * arqueo de caja (Módulo 35; RF-01.2, RF-02.1, RF-02.2, RF-03.1, RF-04.2; D2,
 * D3; C1, C3, C4; QA 1; Art. II.2, Art. V.1 y Art. V.4).
 *
 * Reglas que muerde, en el orden en que las aplica el servicio:
 *
 *   1. **Primacía sanitaria (409, C4, Art. II.2).** Una máquina en cuarentena,
 *      fuera de servicio o con una avería térmica abierta no admite el corte de
 *      bolsa; una visita `ABORTED_THERMAL_BREACH` tampoco (422). Es la misma
 *      matriz que impone `enforce_cash_collection_sanitary_guard()` en el motor.
 *   2. **Precinto (422, C1).** `bag_seal_code` se normaliza a mayúsculas sin
 *      espacios extremos y debe cumplir `^[A-Z0-9_-]{6,40}$` con un bloque de al
 *      menos tres dígitos consecutivos.
 *   3. **Contador y Arqueo Ciego (422, D2, C3, Art. V.1).** La lectura no puede
 *      retroceder sin reseteo; el reseteo exige una nota de 20 a 500 caracteres
 *      reales con tres bloques alfanuméricos. `expected_cashbox_eur` se
 *      **calcula** aquí y nunca se acepta del cliente: lectura completa con
 *      reseteo, diferencia con la lectura previa o diferencia con la base inicial
 *      de la primera recaudación. Las cotas económicas se comprueban en la misma
 *      pasada.
 *   4. **Entrega en conserjería (422/409, D3).** El importe se acota a
 *      `[0,00, 10,00] €`; con entrega positiva se exige conserje de 3 a 120
 *      caracteres reales y una sede con `has_physical_reception = TRUE`.
 *   5. **Arqueo terminal (422/409, D2, D4, Art. V.1).** El descuadre es la resta
 *      exacta `vault_counted_eur - expected_cashbox_eur`; dentro de ±1,00 € el
 *      dictamen es `RECONCILED_OK` con observación opcional de 3 a 1000
 *      caracteres, y por encima `DISCREPANCY_FLAGGED` con justificación de 20 a
 *      1000 caracteres y tres bloques alfanuméricos. Una fila ya terminal no se
 *      vuelve a arquear.
 *
 * El validador no escribe ni consulta la base: entran cifras y salen los valores
 * normalizados que el servicio persistirá, o una excepción con el código exacto
 * del contrato. El perímetro territorial, el rol y la idempotencia pertenecen al
 * servicio (`T-CCV-06`); el precinto ya usado en la historia pertenece al
 * repositorio (`T-CCV-05`).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class MachineCashCollectionValidator
{
    /** Estatus sanitarios del motor que bloquean la recaudación (RF-02.1). */
    public const BLOCKING_SANITARY_STATUSES = ['QUARANTINE', 'SANITARY_QUARANTINE'];

    /** Cota de la entrega en conserjería (D3). */
    public const MAX_RECEPTION_CASH_DELIVERED_EUR = 10.00;
    public const MIN_RECEPTIONIST_NAME_LENGTH = 3;
    public const MAX_RECEPTIONIST_NAME_LENGTH = 120;

    /** Rigor textual de las notas justificadas (Art. V.1). */
    public const MIN_COUNTER_RESET_NOTES_LENGTH = 20;
    public const MAX_COUNTER_RESET_NOTES_LENGTH = 500;
    public const MIN_DISCREPANCY_JUSTIFICATION_LENGTH = 20;
    public const MAX_DISCREPANCY_JUSTIFICATION_LENGTH = 1000;
    public const MIN_OPTIONAL_DISCREPANCY_NOTE_LENGTH = 3;
    public const MIN_ALNUM_BLOCKS = 3;

    /** Tolerancia del arqueo ciego (D2). */
    public const RECONCILIATION_TOLERANCE_EUR = 1.00;

    /**
     * Cerrojo sanitario: ¿la máquina admite el corte de bolsa? (C4, Art. II.2)
     *
     * @param string|null $sanitaryStatus Valor de `machines.sanitary_status` o el
     *                                    estado funcional `SANITARY_QUARANTINE`.
     * @param bool $isOutOfService `machines.is_blocked_no_access`.
     * @param bool $hasOpenThermalBreakdown Avería `TEMPERATURE_COLD` no cerrada.
     *
     * @throws MachineCashCollectionValidationException `409 SANITARY_CASH_COLLECTION_BLOCKED`
     */
    public static function assertMachineAvailable(
        ?string $sanitaryStatus,
        bool $isOutOfService,
        bool $hasOpenThermalBreakdown,
        int $machineId = 0
    ): void {
        $reasons = [];
        $normalizedSanitary = strtoupper(trim((string)$sanitaryStatus));

        if (in_array($normalizedSanitary, self::BLOCKING_SANITARY_STATUSES, true)) {
            $reasons[] = sprintf('está en cuarentena sanitaria (%s)', $normalizedSanitary);
        }

        if ($isOutOfService) {
            $reasons[] = 'está fuera de servicio';
        }

        if ($hasOpenThermalBreakdown) {
            $reasons[] = 'tiene una avería térmica abierta (TEMPERATURE_COLD)';
        }

        if ($reasons !== []) {
            throw MachineCashCollectionValidationException::sanitaryCashCollectionBlocked($reasons, $machineId);
        }
    }

    /**
     * Una visita abortada por rotura de frío no admite recaudación (C4, Art. II.2).
     *
     * @throws MachineCashCollectionValidationException `422 THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN`
     */
    public static function assertVisitAllowsCashCollection(
        ?string $visitStatus,
        int $machineId,
        ?int $visitId = null
    ): void {
        if (strtoupper(trim((string)$visitStatus)) === 'ABORTED_THERMAL_BREACH') {
            throw MachineCashCollectionValidationException::thermalAbortCashCollectionForbidden($machineId, $visitId);
        }
    }

    /**
     * Normaliza y valida el precinto global (C1, RF-01.2).
     *
     * @throws MachineCashCollectionValidationException `422 INVALID_BAG_SEAL_CODE`
     */
    public static function normalizeBagSeal(string $bagSealCode): string
    {
        $clean = strtoupper(trim($bagSealCode));

        if ($clean === '') {
            throw MachineCashCollectionValidationException::invalidBagSealCode(
                $bagSealCode,
                'el precinto es obligatorio en todo corte de bolsa'
            );
        }

        if (preg_match(MachineCashCollection::BAG_SEAL_CODE_PATTERN, $clean) !== 1) {
            throw MachineCashCollectionValidationException::invalidBagSealCode(
                $bagSealCode,
                'debe cumplir ^[A-Z0-9_-]{6,40}$ sin espacios'
            );
        }

        if (preg_match(MachineCashCollection::BAG_SEAL_CODE_DIGITS_PATTERN, $clean) !== 1) {
            throw MachineCashCollectionValidationException::invalidBagSealCode(
                $bagSealCode,
                'debe contener al menos tres dígitos numéricos consecutivos'
            );
        }

        return $clean;
    }

    /**
     * Cerrojo del contador y cálculo privado del arqueo ciego (D2, C3).
     *
     * Devuelve los tres campos que el servicio persistirá. `previous` se fuerza a
     * `NULL` cuando hay reseteo, y la base inicial a `0.00` salvo en la primera
     * recaudación histórica sin lectura previa y sin reseteo.
     *
     * @return array{
     *     previous_sales_counter_reading: float|null,
     *     initial_baseline_reading_eur: float,
     *     expected_cashbox_eur: float
     * }
     *
     * @throws MachineCashCollectionValidationException
     */
    public static function resolveCounterReading(
        bool $isCounterReset,
        ?float $previousSalesCounterReading,
        float $salesCounterReading,
        float $initialBaselineReadingEur = 0.00,
        ?string $counterResetNotes = null
    ): array {
        $sales = round($salesCounterReading, 2);
        $previous = $previousSalesCounterReading === null ? null : round($previousSalesCounterReading, 2);
        $baseline = round($initialBaselineReadingEur, 2);
        $notes = self::normalizeText($counterResetNotes);

        self::assertBounds('sales_counter_reading', $sales, 0.00, MachineCashCollection::MAX_SALES_COUNTER_READING);
        if ($previous !== null) {
            self::assertBounds('previous_sales_counter_reading', $previous, 0.00, MachineCashCollection::MAX_SALES_COUNTER_READING);
        }

        if ($isCounterReset) {
            self::assertJustifiedText(
                $notes,
                'counter_reset_notes',
                self::MIN_COUNTER_RESET_NOTES_LENGTH,
                self::MAX_COUNTER_RESET_NOTES_LENGTH,
                MachineCashCollectionValidationException::COUNTER_RESET_NOTES_REQUIRED
            );

            $expected = round($sales, 2);

            return [
                'previous_sales_counter_reading' => null,
                'initial_baseline_reading_eur' => 0.00,
                'expected_cashbox_eur' => self::assertExpectedBounds($expected),
            ];
        }

        if ($previous !== null) {
            if ($sales < $previous) {
                throw MachineCashCollectionValidationException::salesCounterRegression($previous, $sales);
            }

            $expected = round($sales - $previous, 2);

            return [
                'previous_sales_counter_reading' => $previous,
                'initial_baseline_reading_eur' => 0.00,
                'expected_cashbox_eur' => self::assertExpectedBounds($expected),
            ];
        }

        self::assertBounds('initial_baseline_reading_eur', $baseline, 0.00, MachineCashCollection::MAX_SALES_COUNTER_READING);
        if ($baseline > $sales) {
            throw MachineCashCollectionValidationException::invalidCashAmountBounds(
                'initial_baseline_reading_eur',
                $baseline,
                0.00,
                $sales
            );
        }

        $expected = round($sales - $baseline, 2);

        return [
            'previous_sales_counter_reading' => null,
            'initial_baseline_reading_eur' => $baseline,
            'expected_cashbox_eur' => self::assertExpectedBounds($expected),
        ];
    }

    /**
     * Cotas económicas de la captura en ruta (D2, C3).
     *
     * @throws MachineCashCollectionValidationException `422 INVALID_CASH_AMOUNT_BOUNDS`
     */
    public static function assertAmountBounds(
        float $changeTubesLoadedEur,
        float $salesCounterReading,
        ?float $expectedCashboxEur,
        ?float $previousSalesCounterReading = null,
        ?float $vaultCountedEur = null
    ): void {
        self::assertBounds(
            'change_tubes_loaded_eur',
            round($changeTubesLoadedEur, 2),
            0.00,
            MachineCashCollection::MAX_CHANGE_TUBES_LOADED_EUR
        );
        self::assertBounds(
            'sales_counter_reading',
            round($salesCounterReading, 2),
            0.00,
            MachineCashCollection::MAX_SALES_COUNTER_READING
        );

        if ($previousSalesCounterReading !== null) {
            self::assertBounds(
                'previous_sales_counter_reading',
                round($previousSalesCounterReading, 2),
                0.00,
                MachineCashCollection::MAX_SALES_COUNTER_READING
            );
        }

        if ($expectedCashboxEur !== null) {
            self::assertBounds(
                'expected_cashbox_eur',
                round($expectedCashboxEur, 2),
                0.00,
                MachineCashCollection::MAX_EXPECTED_CASHBOX_EUR
            );
        }

        if ($vaultCountedEur !== null) {
            self::assertBounds(
                'vault_counted_eur',
                round($vaultCountedEur, 2),
                0.00,
                MachineCashCollection::MAX_EXPECTED_CASHBOX_EUR
            );
        }
    }

    /**
     * Entrega de efectivo en conserjería (D3, RF-03.1).
     *
     * @return array{reception_cash_delivered_eur: float, receptionist_name: string|null}
     *
     * @throws MachineCashCollectionValidationException
     */
    public static function assertReceptionDelivery(
        float $receptionCashDeliveredEur,
        ?string $receptionistName,
        bool $locationHasPhysicalReception,
        int $machineId = 0
    ): array {
        $amount = round($receptionCashDeliveredEur, 2);

        if ($amount < 0.00 || $amount > self::MAX_RECEPTION_CASH_DELIVERED_EUR) {
            throw MachineCashCollectionValidationException::receptionCashLimitExceeded($amount);
        }

        $cleanName = self::normalizeText($receptionistName);

        if ($amount === 0.00) {
            return [
                'reception_cash_delivered_eur' => 0.00,
                'receptionist_name' => null,
            ];
        }

        if ($cleanName === null) {
            throw MachineCashCollectionValidationException::receptionistNameRequired($amount);
        }

        $length = mb_strlen($cleanName, 'UTF-8');
        if ($length < self::MIN_RECEPTIONIST_NAME_LENGTH || $length > self::MAX_RECEPTIONIST_NAME_LENGTH) {
            throw MachineCashCollectionValidationException::receptionistNameRequired($amount);
        }

        if (!$locationHasPhysicalReception) {
            throw MachineCashCollectionValidationException::locationHasNoPhysicalReception($machineId);
        }

        return [
            'reception_cash_delivered_eur' => $amount,
            'receptionist_name' => $cleanName,
        ];
    }

    /**
     * Arqueo ciego de Caja Central (D2, D4, QA 1, Art. V.1).
     *
     * @return array{
     *     status: CashCollectionStatus,
     *     discrepancy_eur: float,
     *     discrepancy_justification: string|null
     * }
     *
     * @throws MachineCashCollectionValidationException
     */
    public static function resolveReconciliation(
        float $expectedCashboxEur,
        float $vaultCountedEur,
        CashCollectionStatus $currentStatus,
        ?string $discrepancyJustification = null
    ): array {
        if ($currentStatus->isTerminal()) {
            throw MachineCashCollectionValidationException::cashCollectionAlreadyReconciled($currentStatus->value);
        }

        $expected = round($expectedCashboxEur, 2);
        $counted = round($vaultCountedEur, 2);

        self::assertBounds('vault_counted_eur', $counted, 0.00, MachineCashCollection::MAX_EXPECTED_CASHBOX_EUR);

        $discrepancy = round($counted - $expected, 2);
        $cleanJustification = self::normalizeText($discrepancyJustification);

        if (abs($discrepancy) <= self::RECONCILIATION_TOLERANCE_EUR) {
            if ($cleanJustification !== null) {
                self::assertJustifiedText(
                    $cleanJustification,
                    'discrepancy_justification',
                    self::MIN_OPTIONAL_DISCREPANCY_NOTE_LENGTH,
                    self::MAX_DISCREPANCY_JUSTIFICATION_LENGTH,
                    MachineCashCollectionValidationException::DISCREPANCY_JUSTIFICATION_REQUIRED
                );
            }

            return [
                'status' => CashCollectionStatus::RECONCILED_OK,
                'discrepancy_eur' => $discrepancy,
                'discrepancy_justification' => $cleanJustification,
            ];
        }

        self::assertJustifiedText(
            $cleanJustification,
            'discrepancy_justification',
            self::MIN_DISCREPANCY_JUSTIFICATION_LENGTH,
            self::MAX_DISCREPANCY_JUSTIFICATION_LENGTH,
            MachineCashCollectionValidationException::DISCREPANCY_JUSTIFICATION_REQUIRED,
            $discrepancy
        );

        return [
            'status' => CashCollectionStatus::DISCREPANCY_FLAGGED,
            'discrepancy_eur' => $discrepancy,
            'discrepancy_justification' => $cleanJustification,
        ];
    }

    /**
     * @throws MachineCashCollectionValidationException
     */
    private static function assertBounds(string $field, float $value, float $minimum, float $maximum): void
    {
        if ($value < $minimum || $value > $maximum) {
            throw MachineCashCollectionValidationException::invalidCashAmountBounds(
                $field,
                $value,
                $minimum,
                $maximum
            );
        }
    }

    private static function assertExpectedBounds(float $expected): float
    {
        self::assertBounds(
            'expected_cashbox_eur',
            $expected,
            0.00,
            MachineCashCollection::MAX_EXPECTED_CASHBOX_EUR
        );

        return $expected;
    }

    /**
     * Nota justificada del Art. V.1: longitud real y al menos tres bloques
     * alfanuméricos, la misma regla que los `CHECK` del motor.
     *
     * @throws MachineCashCollectionValidationException
     */
    private static function assertJustifiedText(
        ?string $text,
        string $field,
        int $minLength,
        int $maxLength,
        string $errorCode,
        float $discrepancy = 0.00
    ): void {
        $clean = self::normalizeText($text);
        $length = $clean === null ? null : mb_strlen($clean, 'UTF-8');

        if ($clean === null || $length < $minLength || $length > $maxLength) {
            throw $errorCode === MachineCashCollectionValidationException::COUNTER_RESET_NOTES_REQUIRED
                ? MachineCashCollectionValidationException::counterResetNotesRequired($length)
                : MachineCashCollectionValidationException::discrepancyJustificationRequired($discrepancy, $length);
        }

        preg_match_all('/[\p{L}\p{N}]+/u', $clean, $blocks);
        if (count($blocks[0]) < self::MIN_ALNUM_BLOCKS) {
            throw $errorCode === MachineCashCollectionValidationException::COUNTER_RESET_NOTES_REQUIRED
                ? MachineCashCollectionValidationException::counterResetNotesRequired($length)
                : MachineCashCollectionValidationException::discrepancyJustificationRequired($discrepancy, $length);
        }
    }

    private static function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);

        return $clean === '' ? null : $clean;
    }
}
