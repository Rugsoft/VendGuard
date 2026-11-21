<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Enum\RestockCadenceType;
use VendGuard\Core\Domain\Enum\RestockReassignmentReasonCode;
use VendGuard\Core\Domain\Enum\RestockSkipReasonCode;
use VendGuard\Core\Domain\Exception\RestockRoutePlannerValidationException;

/**
 * RestockRoutePlannerValidator
 *
 * Validador puro de las excepciones diarias y de la coherencia de agendas del
 * Planificador de Rutas (Módulo 37; RF-01.1, RF-02.2, RF-02.3, D4, D6, QA-4,
 * QA-6, Art. V.1 y Art. V.4).
 *
 * Reglas que muerde, en el orden en que las aplica el servicio:
 *
 *   1. **Rol y perímetro (403).** `SKIPPED` lo registran Coordinación y el
 *      reponedor titular; `REASSIGNED` es exclusivo de Coordinación. Omitir una
 *      parada ajena se rechaza con `RESTOCK_STOP_NOT_IN_RESTOCKER_ROUTE`.
 *   2. **Fechas civiles (422).** Nadie registra excepciones en el pasado; el
 *      reponedor solo puede omitir paradas de hoy (`QA-4`). La fecha se ancla a
 *      `Europe/Madrid` mediante el calculador, sin releer el reloj aquí.
 *   3. **Rigor textual de la omisión (422, Art. V.1).** `skip_reason_code`
 *      obligatorio y `reason_text` de `20..500` caracteres reales tras colapsar
 *      espacios, con al menos `3` bloques alfanuméricos. Un texto de guiones o
 *      signos no cuenta como justificación.
 *   4. **Desvío puntual (422, QA-4).** Destino activo con rol `RESTOCKER`,
 *      prohibición de auto-desvío al propietario efectivo y
 *      `reassignment_reason_code` del enum `#50`; la nota libre es opcional y
 *      nunca supera `500` caracteres.
 *   5. **Idempotencia offline (422, C3).** `client_mutation_id` opcional en
 *      línea; si viaja, exige `8..64` caracteres y `^[A-Za-z0-9_.:-]+$`.
 *   6. **Coherencia de agenda (422, RF-01.1).** `WEEKLY` exige día ISO y prohíbe
 *      ancla; `BIWEEKLY` exige ancla válida y prohíbe día; las cadencias fijas
 *      prohíben ambos; `include_weekends` solo cabe en `DAILY`.
 *   7. **Conflictos del día (409).** La parada ya visitada, la segregada por SAT
 *      o cuarentena y la excepción que ya no está viva se traducen al código
 *      exacto del contrato, sin que el servicio componga mensajes.
 *
 * El validador no escribe ni consulta la base: entran datos ya leídos y salen
 * valores normalizados, o una excepción con el código HTTP del contrato.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class RestockRoutePlannerValidator
{
    /** Rigor textual de la omisión (Art. V.1). */
    public const MIN_SKIP_REASON_LENGTH = 20;
    public const MAX_REASON_LENGTH = 500;
    public const MIN_ALPHANUMERIC_BLOCKS = 3;

    /** Idempotencia de la cola offline (C3). */
    public const MIN_MUTATION_ID_LENGTH = 8;
    public const MAX_MUTATION_ID_LENGTH = 64;
    public const MUTATION_ID_PATTERN = '/^[A-Za-z0-9_.:-]+$/';

    /** Roles del módulo. */
    public const ROLE_COORDINATOR = 'COORDINATOR';
    public const ROLE_RESTOCKER = 'RESTOCKER';

    /**
     * Valida una omisión `SKIPPED` y devuelve el motivo normalizado.
     *
     * @throws RestockRoutePlannerValidationException
     * @throws InvalidArgumentException Si alguna fecha no es civilmente válida.
     */
    public static function assertSkipRequest(
        string $role,
        string $serviceDate,
        string $today,
        ?RestockSkipReasonCode $skipReasonCode,
        ?string $reasonText,
        ?string $clientMutationId = null
    ): string {
        $normalizedRole = self::normalizeRole($role);
        self::assertRole($normalizedRole, [self::ROLE_COORDINATOR, self::ROLE_RESTOCKER]);

        $normalizedDate = RestockCadenceEvaluator::normalizeServiceDate($serviceDate);
        $normalizedToday = RestockCadenceEvaluator::normalizeServiceDate($today);

        if ($normalizedDate < $normalizedToday) {
            throw RestockRoutePlannerValidationException::pastServiceDateForbidden($normalizedDate, $normalizedToday);
        }

        if ($normalizedRole === self::ROLE_RESTOCKER && $normalizedDate !== $normalizedToday) {
            throw RestockRoutePlannerValidationException::skipFutureDateForbiddenForRestocker(
                $normalizedDate,
                $normalizedToday
            );
        }

        if ($skipReasonCode === null) {
            throw RestockRoutePlannerValidationException::skipReasonInvalid(
                'skip_reason_code es obligatorio y debe pertenecer al enum #49.'
            );
        }

        $normalizedReason = self::normalizeReasonText($reasonText);
        self::assertSkipReasonText($normalizedReason);

        self::normalizeMutationId($clientMutationId, false);

        return $normalizedReason;
    }

    /**
     * Valida un desvío puntual `REASSIGNED` y devuelve la nota normalizada.
     *
     * @param int|null $currentOwnerId Propietario efectivo actual de la parada en
     *                                `service_date`; `null` si no había ninguno.
     *
     * @throws RestockRoutePlannerValidationException
     * @throws InvalidArgumentException Si alguna fecha no es civilmente válida.
     */
    public static function assertReassignmentRequest(
        string $role,
        string $serviceDate,
        string $today,
        int $targetRestockerId,
        bool $targetIsActiveRestocker,
        ?int $currentOwnerId,
        ?RestockReassignmentReasonCode $reassignmentReasonCode,
        ?string $reasonText = null,
        ?string $clientMutationId = null
    ): ?string {
        $normalizedRole = self::normalizeRole($role);
        self::assertRole($normalizedRole, [self::ROLE_COORDINATOR]);

        $normalizedDate = RestockCadenceEvaluator::normalizeServiceDate($serviceDate);
        $normalizedToday = RestockCadenceEvaluator::normalizeServiceDate($today);

        if ($normalizedDate < $normalizedToday) {
            throw RestockRoutePlannerValidationException::pastServiceDateForbidden($normalizedDate, $normalizedToday);
        }

        if ($targetRestockerId <= 0 || !$targetIsActiveRestocker) {
            throw RestockRoutePlannerValidationException::invalidTargetRestocker($targetRestockerId);
        }

        if ($currentOwnerId !== null && $currentOwnerId === $targetRestockerId) {
            throw RestockRoutePlannerValidationException::selfReassignmentForbidden(
                $targetRestockerId,
                $normalizedDate
            );
        }

        if ($reassignmentReasonCode === null) {
            throw RestockRoutePlannerValidationException::reassignmentReasonInvalid(
                'reassignment_reason_code es obligatorio y debe pertenecer al enum #50.'
            );
        }

        $normalizedNote = self::normalizeReasonText($reasonText);
        if ($normalizedNote !== null && mb_strlen($normalizedNote, 'UTF-8') > self::MAX_REASON_LENGTH) {
            throw RestockRoutePlannerValidationException::reassignmentReasonInvalid(
                sprintf('reason_text no puede superar %d caracteres reales.', self::MAX_REASON_LENGTH),
                ['length' => mb_strlen($normalizedNote, 'UTF-8'), 'maximum' => self::MAX_REASON_LENGTH]
            );
        }

        self::normalizeMutationId($clientMutationId, false);

        return $normalizedNote;
    }

    /**
     * Coherencia de la cadencia antes de versionar una agenda (RF-01.1, QA-2).
     *
     * @throws RestockRoutePlannerValidationException
     * @throws InvalidArgumentException Si el ancla no es una fecha civil válida.
     */
    public static function assertScheduleCadence(
        RestockCadenceType $cadenceType,
        ?int $dayOfWeek,
        ?string $anchorDate,
        bool $includeWeekends
    ): void {
        if ($cadenceType->requiresDayOfWeek()) {
            if ($dayOfWeek === null || $dayOfWeek < 1 || $dayOfWeek > 7) {
                throw RestockRoutePlannerValidationException::scheduleCadenceFieldsInvalid(
                    'WEEKLY exige day_of_week entre 1 y 7.',
                    ['cadence_type' => $cadenceType->value, 'day_of_week' => $dayOfWeek]
                );
            }
            if ($anchorDate !== null) {
                throw RestockRoutePlannerValidationException::scheduleCadenceFieldsInvalid(
                    'WEEKLY no admite anchor_date.',
                    ['cadence_type' => $cadenceType->value, 'anchor_date' => $anchorDate]
                );
            }
        } elseif ($cadenceType->requiresAnchorDate()) {
            if ($anchorDate === null) {
                throw RestockRoutePlannerValidationException::scheduleCadenceFieldsInvalid(
                    'BIWEEKLY exige anchor_date.',
                    ['cadence_type' => $cadenceType->value]
                );
            }
            RestockCadenceEvaluator::normalizeServiceDate($anchorDate);
            if ($dayOfWeek !== null) {
                throw RestockRoutePlannerValidationException::scheduleCadenceFieldsInvalid(
                    'BIWEEKLY no admite day_of_week.',
                    ['cadence_type' => $cadenceType->value, 'day_of_week' => $dayOfWeek]
                );
            }
        } else {
            if ($dayOfWeek !== null || $anchorDate !== null) {
                throw RestockRoutePlannerValidationException::scheduleCadenceFieldsInvalid(
                    sprintf('%s no admite day_of_week ni anchor_date.', $cadenceType->value),
                    ['cadence_type' => $cadenceType->value]
                );
            }
        }

        if ($includeWeekends && !$cadenceType->allowsWeekends()) {
            throw RestockRoutePlannerValidationException::scheduleCadenceFieldsInvalid(
                'include_weekends solo se admite en la cadencia DAILY.',
                ['cadence_type' => $cadenceType->value, 'include_weekends' => true]
            );
        }
    }

    /**
     * El reponedor solo omite paradas de su ruta efectiva de hoy (403).
     *
     * @throws RestockRoutePlannerValidationException
     */
    public static function assertRestockerOwnsStop(
        bool $ownsStop,
        int $machineId,
        int $restockerId
    ): void {
        if (!$ownsStop) {
            throw RestockRoutePlannerValidationException::restockStopNotInRestockerRoute($machineId, $restockerId);
        }
    }

    /**
     * Una visita `COMPLETED` o `ABORTED_THERMAL_BREACH` del día cierra la puerta.
     *
     * @throws RestockRoutePlannerValidationException
     */
    public static function assertStopNotCompletedToday(
        bool $hasCompletedOrAbortedVisitToday,
        int $machineId,
        string $serviceDate
    ): void {
        if ($hasCompletedOrAbortedVisitToday) {
            throw RestockRoutePlannerValidationException::restockStopAlreadyCompletedToday(
                $machineId,
                $serviceDate
            );
        }
    }

    /**
     * Una parada segregada por SAT o cuarentena no admite omisión del reponedor.
     *
     * @param list<string> $reasons
     *
     * @throws RestockRoutePlannerValidationException
     */
    public static function assertStopNotBlocked(
        bool $isBlocked,
        int $machineId,
        array $reasons = []
    ): void {
        if ($isBlocked) {
            throw RestockRoutePlannerValidationException::restockStopBlockedBySanitaryOrSat(
                $machineId,
                $reasons
            );
        }
    }

    /**
     * Solo se revoca una excepción viva (`ACTIVE`).
     *
     * @throws RestockRoutePlannerValidationException
     */
    public static function assertOverrideActive(
        bool $isActive,
        int $overrideId,
        string $status
    ): void {
        if (!$isActive) {
            throw RestockRoutePlannerValidationException::overrideNotActive($overrideId, $status);
        }
    }

    /**
     * Normaliza y valida el identificador de mutación offline.
     *
     * @throws RestockRoutePlannerValidationException
     */
    public static function normalizeMutationId(?string $value, bool $required): ?string
    {
        if ($value === null || trim($value) === '') {
            if ($required) {
                throw RestockRoutePlannerValidationException::invalidClientMutationId(
                    'es obligatorio en una mutación móvil.'
                );
            }

            return null;
        }

        $clean = trim($value);
        $length = mb_strlen($clean, 'UTF-8');

        if ($length < self::MIN_MUTATION_ID_LENGTH || $length > self::MAX_MUTATION_ID_LENGTH) {
            throw RestockRoutePlannerValidationException::invalidClientMutationId(
                sprintf(
                    'debe tener entre %d y %d caracteres; se recibieron %d.',
                    self::MIN_MUTATION_ID_LENGTH,
                    self::MAX_MUTATION_ID_LENGTH,
                    $length
                ),
                $length
            );
        }

        if (preg_match(self::MUTATION_ID_PATTERN, $clean) !== 1) {
            throw RestockRoutePlannerValidationException::invalidClientMutationId(
                'solo admite letras, dígitos, punto, guion bajo, dos puntos y guion.',
                $length
            );
        }

        return $clean;
    }

    /**
     * Bloques reales de letras o números separados por cualquier otra cosa.
     */
    public static function countAlphanumericBlocks(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $blocks);

        return count($blocks[0]);
    }

    /**
     * Recorta extremos y colapsa cualquier secuencia de espacios a uno solo.
     */
    public static function normalizeReasonText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', trim($value));
        if ($collapsed === null) {
            return null;
        }

        return $collapsed === '' ? null : $collapsed;
    }

    /**
     * @param list<string> $allowedRoles
     *
     * @throws RestockRoutePlannerValidationException
     */
    private static function assertRole(string $role, array $allowedRoles): void
    {
        if (!in_array($role, $allowedRoles, true)) {
            throw RestockRoutePlannerValidationException::roleForbidden($role, $allowedRoles);
        }
    }

    private static function normalizeRole(string $role): string
    {
        return strtoupper(trim($role));
    }

    /**
     * @throws RestockRoutePlannerValidationException
     */
    private static function assertSkipReasonText(?string $reasonText): void
    {
        if ($reasonText === null) {
            throw RestockRoutePlannerValidationException::skipReasonInvalid(
                'reason_text es obligatorio en una omisión SKIPPED.',
                ['length' => 0]
            );
        }

        $length = mb_strlen($reasonText, 'UTF-8');
        if ($length < self::MIN_SKIP_REASON_LENGTH || $length > self::MAX_REASON_LENGTH) {
            throw RestockRoutePlannerValidationException::skipReasonInvalid(
                sprintf(
                    'reason_text debe tener entre %d y %d caracteres reales; se recibieron %d.',
                    self::MIN_SKIP_REASON_LENGTH,
                    self::MAX_REASON_LENGTH,
                    $length
                ),
                ['length' => $length, 'minimum' => self::MIN_SKIP_REASON_LENGTH, 'maximum' => self::MAX_REASON_LENGTH]
            );
        }

        $blocks = self::countAlphanumericBlocks($reasonText);
        if ($blocks < self::MIN_ALPHANUMERIC_BLOCKS) {
            throw RestockRoutePlannerValidationException::skipReasonInvalid(
                sprintf(
                    'reason_text debe contener al menos %d bloques alfanuméricos; se recibieron %d.',
                    self::MIN_ALPHANUMERIC_BLOCKS,
                    $blocks
                ),
                ['blocks' => $blocks, 'minimum_blocks' => self::MIN_ALPHANUMERIC_BLOCKS]
            );
        }
    }
}
