<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Exception\SanitarySpoilageValidationException;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\SanitarySpoilageRecord;

/**
 * SanitarySpoilageValidator
 *
 * Validador puro del Acta de Merma y Destrucción de Lotes por Cuarentena
 * Sanitaria (Módulo 29, RF-01.3, RF-02.1 a RF-02.3, RF-04.1 y RF-04.2; Art. II,
 * Art. V.1, Art. V.4 y Art. V.5; D2, D3, C4, C5; QA 3 y QA 4).
 *
 * Reglas que muerde:
 *   1. **Temperaturas físicas y techo de reapertura (Art. II, QA 3).** Ambas
 *      lecturas deben ser numéricas dentro de `[-5.0, +35.0] °C`. La temperatura
 *      estabilizada de reapertura añade el techo sanitario de la tipología:
 *      `4.0 °C` en `PERISHABLE_FOOD` y `COMBO`, `8.0 °C` en `COLD_DRINKS`; las
 *      máquinas de calientes y snacks sólo responden al rango físico.
 *   2. **Dos ramas de acta (D3, C5).** Cero unidades exige confirmación expresa
 *      de máquina vacía y fuerza lotes `SIN_STOCK_AFECTADO`, método
 *      `NOT_APPLICABLE_EMPTY` y coste `0.00 €`. Con producto destruido se fuerza
 *      `empty_machine_confirmed = false`, se exige un método real y lotes de
 *      `3..500` caracteres con al menos un bloque alfanumérico de dos o más
 *      caracteres reales (`QA 4`), y se aplica el baremo `2.50 €/ud.` (`D2`).
 *   3. **Rigor textual (Art. V.1).** Las observaciones sanitarias miden de `20`
 *      a `1000` caracteres normalizados y contienen al menos tres bloques
 *      alfanuméricos reales; los signos repetidos no cuentan como palabra.
 *   4. **Ajuste financiero auditado (C5, QA 4).** Sólo se admite sobre actas con
 *      unidades destruidas, con importe en `(0.00, 99999.99]`, distinto al
 *      vigente y motivo de `20..500` caracteres con tres bloques reales.
 *   5. **Rango de fechas (QA 4).** `date_from` y `date_to` deben ser fechas
 *      `YYYY-MM-DD` reales y el inicio no puede ser posterior al fin.
 *
 * El validador no escribe ni consulta la base: entra el payload y sale el bloque
 * normalizado que el servicio persistirá, o una excepción con el código exacto
 * del contrato.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class SanitarySpoilageValidator
{
    /** Baremo estándar por unidad destruida (D2). */
    public const DEFAULT_UNIT_MERCHANDISE_COST_EUR = 2.50;

    public const MIN_TEMPERATURE_C = -5.0;
    public const MAX_TEMPERATURE_C = 35.0;

    public const MAX_PERISHABLE_RESTORED_TEMPERATURE_C = 4.0;
    public const MAX_COLD_DRINKS_RESTORED_TEMPERATURE_C = 8.0;

    public const MIN_DISCARDED_UNITS = 0;
    public const MAX_DISCARDED_UNITS = 500;

    public const MIN_BATCH_CODES_LENGTH = 3;
    public const MAX_BATCH_CODES_LENGTH = 500;

    public const MIN_SANITARY_NOTES_LENGTH = 20;
    public const MAX_SANITARY_NOTES_LENGTH = 1000;

    public const MIN_LOSS_ADJUSTMENT_REASON_LENGTH = 20;
    public const MAX_LOSS_ADJUSTMENT_REASON_LENGTH = 500;

    public const MIN_ALPHANUMERIC_BLOCKS = 3;

    public const MAX_LOSS_ESTIMATE_EUR = 99999.99;

    public const EMPTY_BATCH_CODES = 'SIN_STOCK_AFECTADO';

    /** @var list<string> Métodos de destrucción válidos cuando hay producto destruido. */
    public const VALID_DISPOSAL_METHODS = [
        SanitarySpoilageRecord::DISPOSAL_METHOD_ON_SITE_ORGANIC_SEALED,
        SanitarySpoilageRecord::DISPOSAL_METHOD_RETURN_TO_CENTRAL_BIOHAZARD,
        SanitarySpoilageRecord::DISPOSAL_METHOD_AUTHORIZED_WASTE_MANAGER,
    ];

    /**
     * Valida el alta de un acta y devuelve el bloque sanitario normalizado.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{
     *     discarded_units_count: int,
     *     empty_machine_confirmed: bool,
     *     affected_batch_codes: string,
     *     cabin_temp_at_removal: float,
     *     restored_stabilized_temp_c: float,
     *     disposal_method: string,
     *     sanitary_notes: string,
     *     estimated_merchandise_loss_eur: float
     * }
     *
     * @throws SanitarySpoilageValidationException
     */
    public static function validateCreation(
        array $payload,
        MachineType|string $machineType,
        string $evidencePhotoUrl
    ): array {
        $type = $machineType instanceof MachineType
            ? $machineType
            : MachineType::fromString($machineType);

        $cabinTemp = self::requireTemperature($payload['cabin_temp_at_removal'] ?? null, 'cabin_temp_at_removal');
        $restoredTemp = self::requireTemperature(
            $payload['restored_stabilized_temp_c'] ?? null,
            'restored_stabilized_temp_c'
        );
        self::assertRestoredTemperatureWithinSafeLimit($type, $restoredTemp);

        $discardedUnits = self::requireDiscardedUnits($payload['discarded_units_count'] ?? null);

        $photoUrl = self::normalizeText($evidencePhotoUrl);
        $photoLength = mb_strlen($photoUrl, 'UTF-8');
        if ($photoLength < 5 || $photoLength > 255) {
            throw SanitarySpoilageValidationException::evidencePhotoRequired(
                'la fotografía de evidencia es obligatoria en el 100 % de los actos (D3, Art. V.5).'
            );
        }

        $sanitaryNotes = self::requireSanitaryNotes($payload['sanitary_notes'] ?? null);

        if ($discardedUnits === 0) {
            if (!self::isConfirmedEmpty($payload['empty_machine_confirmed'] ?? false)) {
                throw SanitarySpoilageValidationException::emptyMachineConfirmationRequired();
            }

            return [
                'discarded_units_count' => 0,
                'empty_machine_confirmed' => true,
                'affected_batch_codes' => self::EMPTY_BATCH_CODES,
                'cabin_temp_at_removal' => $cabinTemp,
                'restored_stabilized_temp_c' => $restoredTemp,
                'disposal_method' => SanitarySpoilageRecord::DISPOSAL_METHOD_NOT_APPLICABLE_EMPTY,
                'sanitary_notes' => $sanitaryNotes,
                'estimated_merchandise_loss_eur' => 0.00,
            ];
        }

        $batchCodes = self::requireBatchCodes($payload['affected_batch_codes'] ?? null);
        $disposalMethod = self::requireDisposalMethod($payload['disposal_method'] ?? null);

        return [
            'discarded_units_count' => $discardedUnits,
            'empty_machine_confirmed' => false,
            'affected_batch_codes' => $batchCodes,
            'cabin_temp_at_removal' => $cabinTemp,
            'restored_stabilized_temp_c' => $restoredTemp,
            'disposal_method' => $disposalMethod,
            'sanitary_notes' => $sanitaryNotes,
            'estimated_merchandise_loss_eur' => round(
                $discardedUnits * self::DEFAULT_UNIT_MERCHANDISE_COST_EUR,
                2
            ),
        ];
    }

    /**
     * Valida un ajuste de coste solicitado por Coordinación (C5, QA 4).
     *
     * @return array{estimated_merchandise_loss_eur: float, loss_adjustment_reason: string}
     *
     * @throws SanitarySpoilageValidationException
     */
    public static function validateLossAdjustment(
        int $discardedUnitsCount,
        float $currentLossEur,
        mixed $newLossRaw,
        string $rawReason
    ): array {
        if ($discardedUnitsCount === 0) {
            throw SanitarySpoilageValidationException::emptyMachineLossCannotBeAdjusted();
        }

        $newLoss = self::normalizeFloat($newLossRaw);
        if ($newLoss === null || $newLoss <= 0.00 || $newLoss > self::MAX_LOSS_ESTIMATE_EUR) {
            throw SanitarySpoilageValidationException::invalidLossEstimateAmount($newLossRaw);
        }
        $newLoss = round($newLoss, 2);

        if (abs($newLoss - $currentLossEur) < 0.005) {
            throw SanitarySpoilageValidationException::identicalLossEstimate($newLoss);
        }

        $reason = self::normalizeText($rawReason);
        $reasonLength = mb_strlen($reason, 'UTF-8');
        if ($reasonLength < self::MIN_LOSS_ADJUSTMENT_REASON_LENGTH
            || $reasonLength > self::MAX_LOSS_ADJUSTMENT_REASON_LENGTH) {
            throw SanitarySpoilageValidationException::invalidLossAdjustmentReason(sprintf(
                'debe contener entre %d y %d caracteres reales; se recibieron %d.',
                self::MIN_LOSS_ADJUSTMENT_REASON_LENGTH,
                self::MAX_LOSS_ADJUSTMENT_REASON_LENGTH,
                $reasonLength
            ));
        }

        if (self::countAlphanumericBlocks($reason) < self::MIN_ALPHANUMERIC_BLOCKS) {
            throw SanitarySpoilageValidationException::invalidLossAdjustmentReason(sprintf(
                'debe contener al menos %d bloques alfanuméricos reales (Art. V.1).',
                self::MIN_ALPHANUMERIC_BLOCKS
            ));
        }

        return [
            'estimated_merchandise_loss_eur' => $newLoss,
            'loss_adjustment_reason' => $reason,
        ];
    }

    /**
     * Valida el rango de fechas de los filtros de Coordinación (QA 4).
     *
     * @throws SanitarySpoilageValidationException
     */
    public static function validateDateRange(?string $dateFrom, ?string $dateTo): void
    {
        $from = self::normalizeDate($dateFrom, 'date_from');
        $to = self::normalizeDate($dateTo, 'date_to');

        if ($from !== null && $to !== null && $from > $to) {
            throw SanitarySpoilageValidationException::invalidDateRange($from, $to);
        }
    }

    /**
     * Normaliza texto libre: recorta extremos y colapsa espacios repetidos.
     */
    public static function normalizeText(string $value): string
    {
        $trimmed = trim($value);
        $collapsed = preg_replace('/\s+/u', ' ', $trimmed);

        return $collapsed ?? $trimmed;
    }

    /**
     * Bloques alfanuméricos reales de un texto (Art. V.1): secuencias de letras o
     * números separadas por cualquier otro carácter. Los signos repetidos no
     * cuentan como palabra.
     */
    public static function countAlphanumericBlocks(string $text): int
    {
        $blocks = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $blocks === false ? 0 : count($blocks);
    }

    /**
     * @throws SanitarySpoilageValidationException
     */
    private static function requireTemperature(mixed $rawValue, string $field): float
    {
        $value = self::normalizeFloat($rawValue);
        if ($value === null || $value < self::MIN_TEMPERATURE_C || $value > self::MAX_TEMPERATURE_C) {
            throw SanitarySpoilageValidationException::invalidTemperatureRange(
                $field,
                $rawValue,
                self::MIN_TEMPERATURE_C,
                self::MAX_TEMPERATURE_C
            );
        }

        return round($value, 1);
    }

    /**
     * @throws SanitarySpoilageValidationException
     */
    private static function assertRestoredTemperatureWithinSafeLimit(MachineType $machineType, float $restoredTemp): void
    {
        $safeLimit = match ($machineType) {
            MachineType::PERISHABLE_FOOD, MachineType::COMBO => self::MAX_PERISHABLE_RESTORED_TEMPERATURE_C,
            MachineType::COLD_DRINKS => self::MAX_COLD_DRINKS_RESTORED_TEMPERATURE_C,
            MachineType::HOT_DRINKS, MachineType::SNACKS => null,
        };

        if ($safeLimit !== null && $restoredTemp > $safeLimit) {
            throw SanitarySpoilageValidationException::restoredTemperatureExceedsSafeLimit(
                $machineType->value,
                $restoredTemp,
                $safeLimit
            );
        }
    }

    /**
     * @throws SanitarySpoilageValidationException
     */
    private static function requireDiscardedUnits(mixed $rawValue): int
    {
        $units = self::normalizeUnits($rawValue);
        if ($units === null || $units < self::MIN_DISCARDED_UNITS || $units > self::MAX_DISCARDED_UNITS) {
            throw SanitarySpoilageValidationException::invalidDiscardedUnits($rawValue);
        }

        return $units;
    }

    /**
     * @throws SanitarySpoilageValidationException
     */
    private static function requireBatchCodes(mixed $rawValue): string
    {
        $batchCodes = is_string($rawValue) ? self::normalizeText($rawValue) : '';
        $length = mb_strlen($batchCodes, 'UTF-8');

        if ($length < self::MIN_BATCH_CODES_LENGTH || $length > self::MAX_BATCH_CODES_LENGTH) {
            throw SanitarySpoilageValidationException::invalidBatchCodes(sprintf(
                'debe contener entre %d y %d caracteres reales; se recibieron %d.',
                self::MIN_BATCH_CODES_LENGTH,
                self::MAX_BATCH_CODES_LENGTH,
                $length
            ));
        }

        if (preg_match('/[\p{L}\p{N}]{2,}/u', $batchCodes) !== 1) {
            throw SanitarySpoilageValidationException::invalidBatchCodes(
                'debe incluir al menos un bloque alfanumérico de dos o más caracteres reales; '
                . 'los signos repetidos no identifican un lote (QA 4).'
            );
        }

        return $batchCodes;
    }

    /**
     * @throws SanitarySpoilageValidationException
     */
    private static function requireDisposalMethod(mixed $rawValue): string
    {
        $method = is_string($rawValue) ? strtoupper(trim($rawValue)) : '';

        if (!in_array($method, self::VALID_DISPOSAL_METHODS, true)) {
            throw SanitarySpoilageValidationException::invalidDisposalMethod($rawValue);
        }

        return $method;
    }

    /**
     * @throws SanitarySpoilageValidationException
     */
    private static function requireSanitaryNotes(mixed $rawValue): string
    {
        $notes = is_string($rawValue) ? self::normalizeText($rawValue) : '';
        $length = mb_strlen($notes, 'UTF-8');

        if ($length < self::MIN_SANITARY_NOTES_LENGTH || $length > self::MAX_SANITARY_NOTES_LENGTH) {
            throw SanitarySpoilageValidationException::invalidSanitaryNotes(sprintf(
                'debe contener entre %d y %d caracteres reales; se recibieron %d (Art. V.1).',
                self::MIN_SANITARY_NOTES_LENGTH,
                self::MAX_SANITARY_NOTES_LENGTH,
                $length
            ));
        }

        if (self::countAlphanumericBlocks($notes) < self::MIN_ALPHANUMERIC_BLOCKS) {
            throw SanitarySpoilageValidationException::invalidSanitaryNotes(sprintf(
                'debe contener al menos %d bloques alfanuméricos reales (Art. V.1).',
                self::MIN_ALPHANUMERIC_BLOCKS
            ));
        }

        return $notes;
    }

    /**
     * @throws SanitarySpoilageValidationException
     */
    private static function normalizeDate(?string $rawValue, string $field): ?string
    {
        if ($rawValue === null) {
            return null;
        }

        $value = trim($rawValue);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1
            || !checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])) {
            throw SanitarySpoilageValidationException::invalidDateRange(
                $field,
                $value,
                sprintf('«%s» no es una fecha real con formato YYYY-MM-DD.', $field)
            );
        }

        return $value;
    }

    private static function isConfirmedEmpty(mixed $rawValue): bool
    {
        return $rawValue === true
            || $rawValue === 1
            || $rawValue === '1'
            || (is_string($rawValue) && strtolower(trim($rawValue)) === 'true');
    }

    private static function normalizeFloat(mixed $rawValue): ?float
    {
        if (is_int($rawValue) || is_float($rawValue)) {
            $value = (float)$rawValue;
        } elseif (is_string($rawValue) && is_numeric(trim($rawValue))) {
            $value = (float)trim($rawValue);
        } else {
            return null;
        }

        return is_finite($value) ? $value : null;
    }

    private static function normalizeUnits(mixed $rawValue): ?int
    {
        if (is_int($rawValue)) {
            return $rawValue;
        }

        if (is_float($rawValue) && floor($rawValue) === $rawValue && is_finite($rawValue)) {
            return (int)$rawValue;
        }

        if (is_string($rawValue) && preg_match('/^\d+$/', trim($rawValue)) === 1) {
            return (int)trim($rawValue);
        }

        return null;
    }
}
