<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use DateTimeZone;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Exception\ShiftAdjustmentValidationException;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;

/**
 * TechnicianShift
 *
 * Entidad de dominio inmutable que representa una sesión de jornada o turno
 * de un técnico (Módulos 21 y 22).
 *
 * Máquina de estados:
 *   - [Nuevo] -> ON_SHIFT (start)
 *   - ON_SHIFT -> MEAL_BREAK (pauseMeal)
 *   - MEAL_BREAK -> ON_SHIFT (resume)
 *   - ON_SHIFT -> OFF_SHIFT (close)
 *   - MEAL_BREAK -> OFF_SHIFT (close, cierre directo desde pausa)
 *   - OFF_SHIFT -> OFF_SHIFT (adjust, rectificación auditada)
 *
 * Invariantes de jornada:
 *   - Las pausas de comida se acumulan al segundo y nunca se sobrescriben.
 *   - Un cierre desde MEAL_BREAK descuenta el tramo final como pausa.
 *   - `originalStartedAt`, `originalClosedAt` y `originalMealBreakSeconds`
 *     conservan el primer fichaje del técnico aunque se rectifique varias veces
 *     (Art. III.3).
 *   - La rectificación no admite borrado ni edición de la bitácora: produce una
 *     nueva versión del turno y el servicio registra el evento append-only.
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades readonly y métodos puros.
 */
final class TechnicianShift
{
    /** Umbral de alerta por duración bruta anómala: 12 horas (RF-02.4, QA 4). */
    public const ANOMALOUS_DURATION_THRESHOLD_SECONDS = 43200;

    /** Duración bruta máxima admitida en una sesión rectificada: 24 horas. */
    public const MAX_SHIFT_DURATION_SECONDS = 86400;

    /** Longitud mínima real del motivo de rectificación (Art. V.1). */
    public const MIN_ADJUSTMENT_REASON_LENGTH = 20;

    public function __construct(
        private readonly ?int $id,
        private readonly int $technicianId,
        private readonly ShiftStatus $status,
        private readonly DateTimeImmutable $startedAt,
        private readonly ?DateTimeImmutable $mealBreakStartedAt = null,
        private readonly ?DateTimeImmutable $mealBreakEndedAt = null,
        private readonly ?DateTimeImmutable $closedAt = null,
        private readonly ?int $closedBy = null,
        private readonly ?string $closeReason = null,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
        private readonly ?string $shiftDate = null,
        private readonly int $mealBreakSeconds = 0,
        private readonly ?DateTimeImmutable $originalStartedAt = null,
        private readonly ?DateTimeImmutable $originalClosedAt = null,
        private readonly ?int $originalMealBreakSeconds = null,
        private readonly bool $isForceClosed = false,
        private readonly bool $isAdjusted = false,
        private readonly ?DateTimeImmutable $adjustedAt = null,
        private readonly ?int $adjustedBy = null,
        private readonly ?string $adjustmentReason = null
    ) {
    }

    public static function start(int $technicianId, ?DateTimeImmutable $startedAt = null): self
    {
        $now = $startedAt ?? new DateTimeImmutable('now');

        return new self(
            id: null,
            technicianId: $technicianId,
            status: ShiftStatus::ON_SHIFT,
            startedAt: $now,
            mealBreakStartedAt: null,
            mealBreakEndedAt: null,
            closedAt: null,
            closedBy: null,
            closeReason: null,
            createdAt: $now,
            updatedAt: $now,
            shiftDate: $now->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y-m-d'),
            mealBreakSeconds: 0,
            originalStartedAt: $now,
            originalClosedAt: null,
            originalMealBreakSeconds: null,
            isForceClosed: false,
            isAdjusted: false,
            adjustedAt: null,
            adjustedBy: null,
            adjustmentReason: null
        );
    }

    public function pauseMeal(?DateTimeImmutable $pausedAt = null): self
    {
        if ($this->status === ShiftStatus::MEAL_BREAK) {
            throw InvalidShiftTransitionException::alreadyPaused();
        }

        if ($this->status !== ShiftStatus::ON_SHIFT) {
            throw InvalidShiftTransitionException::noOpenShift('pausar para comida');
        }

        $now = $pausedAt ?? new DateTimeImmutable('now');

        return new self(
            id: $this->id,
            technicianId: $this->technicianId,
            status: ShiftStatus::MEAL_BREAK,
            startedAt: $this->startedAt,
            mealBreakStartedAt: $now,
            mealBreakEndedAt: $this->mealBreakEndedAt,
            closedAt: $this->closedAt,
            closedBy: $this->closedBy,
            closeReason: $this->closeReason,
            createdAt: $this->createdAt,
            updatedAt: $now,
            shiftDate: $this->shiftDate,
            mealBreakSeconds: $this->mealBreakSeconds,
            originalStartedAt: $this->originalStartedAt(),
            originalClosedAt: $this->originalClosedAt,
            originalMealBreakSeconds: $this->originalMealBreakSeconds,
            isForceClosed: $this->isForceClosed,
            isAdjusted: $this->isAdjusted,
            adjustedAt: $this->adjustedAt,
            adjustedBy: $this->adjustedBy,
            adjustmentReason: $this->adjustmentReason
        );
    }

    public function resume(?DateTimeImmutable $resumedAt = null): self
    {
        if ($this->status === ShiftStatus::ON_SHIFT) {
            throw InvalidShiftTransitionException::notPaused();
        }

        if ($this->status !== ShiftStatus::MEAL_BREAK || $this->mealBreakStartedAt === null) {
            throw InvalidShiftTransitionException::noOpenShift('reanudar la jornada');
        }

        $now = $resumedAt ?? new DateTimeImmutable('now');
        $delta = max(0, $now->getTimestamp() - $this->mealBreakStartedAt->getTimestamp());

        return new self(
            id: $this->id,
            technicianId: $this->technicianId,
            status: ShiftStatus::ON_SHIFT,
            startedAt: $this->startedAt,
            mealBreakStartedAt: $this->mealBreakStartedAt,
            mealBreakEndedAt: $now,
            closedAt: $this->closedAt,
            closedBy: $this->closedBy,
            closeReason: $this->closeReason,
            createdAt: $this->createdAt,
            updatedAt: $now,
            shiftDate: $this->shiftDate,
            mealBreakSeconds: $this->mealBreakSeconds + $delta,
            originalStartedAt: $this->originalStartedAt(),
            originalClosedAt: $this->originalClosedAt,
            originalMealBreakSeconds: $this->originalMealBreakSeconds,
            isForceClosed: $this->isForceClosed,
            isAdjusted: $this->isAdjusted,
            adjustedAt: $this->adjustedAt,
            adjustedBy: $this->adjustedBy,
            adjustmentReason: $this->adjustmentReason
        );
    }

    /**
     * Cierra la jornada, descontando el tramo abierto si el turno está en pausa.
     *
     * @param DateTimeImmutable|null $closedAt Hora efectiva de salida. Si es
     *        retroactiva respecto a $executionTime, marca el turno como ajustado.
     * @param DateTimeImmutable|null $executionTime Instante real de la operación;
     *        se conserva como marca original y como reloj de auditoría.
     */
    public function close(
        int $closedBy,
        ?string $closeReason = null,
        ?DateTimeImmutable $closedAt = null,
        bool $isForceClosed = false,
        ?DateTimeImmutable $executionTime = null
    ): self {
        if ($this->status === ShiftStatus::OFF_SHIFT || $this->closedAt !== null) {
            throw InvalidShiftTransitionException::alreadyClosed();
        }

        $effectiveClosedAt = $closedAt ?? $executionTime ?? new DateTimeImmutable('now');
        $newMealBreakSeconds = $this->mealBreakSeconds;

        // RF-01.3: el tramo que va desde el inicio de la pausa hasta el cierre
        // efectivo es descanso, no trabajo, cuando se cierra desde MEAL_BREAK.
        if ($this->status === ShiftStatus::MEAL_BREAK && $this->mealBreakStartedAt !== null) {
            $finalBreakDelta = max(
                0,
                $effectiveClosedAt->getTimestamp() - $this->mealBreakStartedAt->getTimestamp()
            );
            $newMealBreakSeconds += $finalBreakDelta;
        }

        $hasRetroactiveClose = $isForceClosed
            && $closedAt !== null
            && $executionTime !== null
            && $closedAt->getTimestamp() !== $executionTime->getTimestamp();

        $cleanReason = $closeReason !== null ? trim($closeReason) : null;

        return new self(
            id: $this->id,
            technicianId: $this->technicianId,
            status: ShiftStatus::OFF_SHIFT,
            startedAt: $this->startedAt,
            mealBreakStartedAt: $this->mealBreakStartedAt,
            mealBreakEndedAt: $this->mealBreakEndedAt,
            closedAt: $effectiveClosedAt,
            closedBy: $closedBy,
            closeReason: $cleanReason !== '' ? $cleanReason : null,
            createdAt: $this->createdAt,
            updatedAt: $executionTime ?? $effectiveClosedAt,
            shiftDate: $this->shiftDate,
            mealBreakSeconds: $newMealBreakSeconds,
            originalStartedAt: $this->originalStartedAt(),
            originalClosedAt: $this->originalClosedAt ?? ($executionTime ?? $effectiveClosedAt),
            originalMealBreakSeconds: $this->originalMealBreakSeconds ?? $newMealBreakSeconds,
            isForceClosed: $this->isForceClosed || $isForceClosed,
            isAdjusted: $this->isAdjusted || $hasRetroactiveClose,
            adjustedAt: $hasRetroactiveClose ? $executionTime : $this->adjustedAt,
            adjustedBy: $hasRetroactiveClose ? $closedBy : $this->adjustedBy,
            adjustmentReason: $hasRetroactiveClose
                ? ($cleanReason !== '' ? $cleanReason : null)
                : $this->adjustmentReason
        );
    }

    /**
     * Rectifica un turno cerrado sin alterar las marcas originales del técnico.
     *
     * La cuarta regla de la validación cuádruple (solapamiento con otro turno)
     * no se puede evaluar dentro de la entidad: el servicio la comprueba contra
     * el repositorio antes de invocar este método.
     *
     * @throws ShiftAdjustmentValidationException
     */
    public function adjust(
        DateTimeImmutable $newStartedAt,
        DateTimeImmutable $newClosedAt,
        int $newMealBreakSeconds,
        int $coordinatorId,
        string $reason,
        ?DateTimeImmutable $adjustedAt = null
    ): self {
        $cleanReason = trim($reason);
        if (mb_strlen($cleanReason) < self::MIN_ADJUSTMENT_REASON_LENGTH) {
            throw ShiftAdjustmentValidationException::reasonTooShort(self::MIN_ADJUSTMENT_REASON_LENGTH);
        }

        if ($this->isOpen()) {
            throw ShiftAdjustmentValidationException::shiftStillOpen();
        }

        $now = $adjustedAt ?? new DateTimeImmutable('now');

        if ($newStartedAt > $now || $newClosedAt > $now) {
            throw ShiftAdjustmentValidationException::futureTimestampNotAllowed();
        }

        if ($newClosedAt <= $newStartedAt) {
            throw ShiftAdjustmentValidationException::invalidCloseOrder();
        }

        $grossSeconds = $newClosedAt->getTimestamp() - $newStartedAt->getTimestamp();

        if ($newMealBreakSeconds < 0 || $newMealBreakSeconds > $grossSeconds) {
            throw ShiftAdjustmentValidationException::invalidMealBreakDuration();
        }

        if ($grossSeconds > self::MAX_SHIFT_DURATION_SECONDS) {
            throw ShiftAdjustmentValidationException::exceedsMaxShiftDuration(self::MAX_SHIFT_DURATION_SECONDS);
        }

        return new self(
            id: $this->id,
            technicianId: $this->technicianId,
            status: $this->status,
            startedAt: $newStartedAt,
            mealBreakStartedAt: $this->mealBreakStartedAt,
            mealBreakEndedAt: $this->mealBreakEndedAt,
            closedAt: $newClosedAt,
            closedBy: $this->closedBy,
            closeReason: $this->closeReason,
            createdAt: $this->createdAt,
            updatedAt: $now,
            shiftDate: $newStartedAt->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y-m-d'),
            mealBreakSeconds: $newMealBreakSeconds,
            originalStartedAt: $this->originalStartedAt(),
            originalClosedAt: $this->originalClosedAt(),
            originalMealBreakSeconds: $this->originalMealBreakSeconds(),
            isForceClosed: $this->isForceClosed,
            isAdjusted: true,
            adjustedAt: $now,
            adjustedBy: $coordinatorId,
            adjustmentReason: $cleanReason
        );
    }

    public function isOpen(): bool
    {
        return $this->closedAt === null && $this->status !== ShiftStatus::OFF_SHIFT;
    }

    public function isOnShift(): bool
    {
        return $this->status === ShiftStatus::ON_SHIFT;
    }

    public function isMealBreak(): bool
    {
        return $this->status === ShiftStatus::MEAL_BREAK;
    }

    public function isOffShift(): bool
    {
        return $this->status === ShiftStatus::OFF_SHIFT;
    }

    /**
     * Duración bruta de la sesión, en segundos enteros (RNF-01).
     */
    public function grossDurationSeconds(?DateTimeImmutable $referenceNow = null): int
    {
        $end = $this->closedAt ?? $referenceNow ?? new DateTimeImmutable('now');

        return max(0, $end->getTimestamp() - $this->startedAt->getTimestamp());
    }

    /**
     * Pausa de comida total, incluyendo el tramo abierto si el turno sigue en
     * MEAL_BREAK.
     */
    public function totalMealBreakSeconds(?DateTimeImmutable $referenceNow = null): int
    {
        if (
            $this->status === ShiftStatus::MEAL_BREAK
            && $this->closedAt === null
            && $this->mealBreakStartedAt !== null
        ) {
            $now = $referenceNow ?? new DateTimeImmutable('now');

            return $this->mealBreakSeconds + max(
                0,
                $now->getTimestamp() - $this->mealBreakStartedAt->getTimestamp()
            );
        }

        return $this->mealBreakSeconds;
    }

    /**
     * Horas efectivas netas, nunca negativas.
     */
    public function effectiveWorkSeconds(?DateTimeImmutable $referenceNow = null): int
    {
        return max(
            0,
            $this->grossDurationSeconds($referenceNow) - $this->totalMealBreakSeconds($referenceNow)
        );
    }

    /**
     * Alerta de duración anómala medida siempre sobre la duración bruta.
     */
    public function isAnomalousDuration(?DateTimeImmutable $referenceNow = null): bool
    {
        return $this->grossDurationSeconds($referenceNow) > self::ANOMALOUS_DURATION_THRESHOLD_SECONDS;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function technicianId(): int
    {
        return $this->technicianId;
    }

    public function status(): ShiftStatus
    {
        return $this->status;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function mealBreakStartedAt(): ?DateTimeImmutable
    {
        return $this->mealBreakStartedAt;
    }

    public function mealBreakEndedAt(): ?DateTimeImmutable
    {
        return $this->mealBreakEndedAt;
    }

    public function closedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function closedBy(): ?int
    {
        return $this->closedBy;
    }

    public function closeReason(): ?string
    {
        return $this->closeReason;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function shiftDate(): ?string
    {
        return $this->shiftDate;
    }

    public function mealBreakSeconds(): int
    {
        return $this->mealBreakSeconds;
    }

    /**
     * Marca de entrada del primer fichaje del técnico, inmutable ante ajustes.
     */
    public function originalStartedAt(): DateTimeImmutable
    {
        return $this->originalStartedAt ?? $this->startedAt;
    }

    /**
     * Marca de salida original del primer cierre, o null si sigue abierto.
     */
    public function originalClosedAt(): ?DateTimeImmutable
    {
        return $this->originalClosedAt ?? $this->closedAt;
    }

    /**
     * Pausa acumulada en el primer cierre, o null si sigue abierto.
     */
    public function originalMealBreakSeconds(): ?int
    {
        if ($this->originalMealBreakSeconds !== null) {
            return $this->originalMealBreakSeconds;
        }

        return $this->closedAt !== null ? $this->mealBreakSeconds : null;
    }

    public function isForceClosed(): bool
    {
        return $this->isForceClosed;
    }

    public function isAdjusted(): bool
    {
        return $this->isAdjusted;
    }

    public function adjustedAt(): ?DateTimeImmutable
    {
        return $this->adjustedAt;
    }

    public function adjustedBy(): ?int
    {
        return $this->adjustedBy;
    }

    public function adjustmentReason(): ?string
    {
        return $this->adjustmentReason;
    }

    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int)$row['id'] : null,
            technicianId: (int)$row['technician_id'],
            status: ShiftStatus::from((string)$row['status']),
            startedAt: new DateTimeImmutable((string)$row['started_at']),
            mealBreakStartedAt: isset($row['meal_break_started_at']) && $row['meal_break_started_at'] !== null ? new DateTimeImmutable((string)$row['meal_break_started_at']) : null,
            mealBreakEndedAt: isset($row['meal_break_ended_at']) && $row['meal_break_ended_at'] !== null ? new DateTimeImmutable((string)$row['meal_break_ended_at']) : null,
            closedAt: isset($row['closed_at']) && $row['closed_at'] !== null ? new DateTimeImmutable((string)$row['closed_at']) : null,
            closedBy: isset($row['closed_by']) && $row['closed_by'] !== null ? (int)$row['closed_by'] : null,
            closeReason: isset($row['close_reason']) && $row['close_reason'] !== null ? (string)$row['close_reason'] : null,
            createdAt: isset($row['created_at']) && $row['created_at'] !== null ? new DateTimeImmutable((string)$row['created_at']) : null,
            updatedAt: isset($row['updated_at']) && $row['updated_at'] !== null ? new DateTimeImmutable((string)$row['updated_at']) : null,
            shiftDate: isset($row['shift_date']) ? (string)$row['shift_date'] : null,
            mealBreakSeconds: isset($row['meal_break_seconds']) ? (int)$row['meal_break_seconds'] : 0,
            originalStartedAt: isset($row['original_started_at']) && $row['original_started_at'] !== null ? new DateTimeImmutable((string)$row['original_started_at']) : null,
            originalClosedAt: isset($row['original_closed_at']) && $row['original_closed_at'] !== null ? new DateTimeImmutable((string)$row['original_closed_at']) : null,
            originalMealBreakSeconds: isset($row['original_meal_break_seconds']) && $row['original_meal_break_seconds'] !== null ? (int)$row['original_meal_break_seconds'] : null,
            isForceClosed: (bool)($row['is_force_closed'] ?? false),
            isAdjusted: (bool)($row['is_adjusted'] ?? false),
            adjustedAt: isset($row['adjusted_at']) && $row['adjusted_at'] !== null ? new DateTimeImmutable((string)$row['adjusted_at']) : null,
            adjustedBy: isset($row['adjusted_by']) && $row['adjusted_by'] !== null ? (int)$row['adjusted_by'] : null,
            adjustmentReason: isset($row['adjustment_reason']) && $row['adjustment_reason'] !== null ? (string)$row['adjustment_reason'] : null
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'technician_id' => $this->technicianId,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_available' => $this->status->isAvailable(),
            'is_open' => $this->isOpen(),
            'shift_date' => $this->shiftDate,
            'started_at' => $this->startedAt->format(DateTimeImmutable::ATOM),
            'meal_break_started_at' => $this->mealBreakStartedAt?->format(DateTimeImmutable::ATOM),
            'meal_break_ended_at' => $this->mealBreakEndedAt?->format(DateTimeImmutable::ATOM),
            'closed_at' => $this->closedAt?->format(DateTimeImmutable::ATOM),
            'closed_by' => $this->closedBy,
            'close_reason' => $this->closeReason,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
            'meal_break_seconds' => $this->mealBreakSeconds,
            'original_started_at' => $this->originalStartedAt()->format(DateTimeImmutable::ATOM),
            'original_closed_at' => $this->originalClosedAt()?->format(DateTimeImmutable::ATOM),
            'original_meal_break_seconds' => $this->originalMealBreakSeconds(),
            'is_force_closed' => $this->isForceClosed,
            'is_adjusted' => $this->isAdjusted,
            'adjusted_at' => $this->adjustedAt?->format(DateTimeImmutable::ATOM),
            'adjusted_by' => $this->adjustedBy,
            'adjustment_reason' => $this->adjustmentReason,
        ];
    }
}
