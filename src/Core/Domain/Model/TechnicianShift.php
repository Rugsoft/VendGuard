<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;

/**
 * TechnicianShift
 *
 * Entidad de dominio inmutable que representa una sesión de jornada o turno
 * de un técnico (Módulo 21).
 *
 * Máquina de estados:
 *   - [Nuevo] -> ON_SHIFT (start)
 *   - ON_SHIFT -> MEAL_BREAK (pauseMeal)
 *   - MEAL_BREAK -> ON_SHIFT (resume)
 *   - ON_SHIFT -> OFF_SHIFT (close)
 *   - MEAL_BREAK -> OFF_SHIFT (close, soporte de cierre directo R10)
 *
 * Soporte de turno partido (R3): Múltiples sesiones en el mismo día son admitidas,
 * con la invariante de máximo un turno abierto simultáneo (`isOpen() === true`).
 */
final class TechnicianShift
{
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
        private readonly ?string $shiftDate = null
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
            shiftDate: $now->format('Y-m-d')
        );
    }

    public function pauseMeal(?DateTimeImmutable $pausedAt = null): self
    {
        if ($this->status !== ShiftStatus::ON_SHIFT) {
            throw InvalidShiftTransitionException::notOnShift('pausar para comida');
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
            shiftDate: $this->shiftDate
        );
    }

    public function resume(?DateTimeImmutable $resumedAt = null): self
    {
        if ($this->status !== ShiftStatus::MEAL_BREAK) {
            throw InvalidShiftTransitionException::notInMealBreak();
        }

        $now = $resumedAt ?? new DateTimeImmutable('now');

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
            shiftDate: $this->shiftDate
        );
    }

    public function close(int $closedBy, ?string $closeReason = null, ?DateTimeImmutable $closedAt = null): self
    {
        if ($this->status === ShiftStatus::OFF_SHIFT || $this->closedAt !== null) {
            throw InvalidShiftTransitionException::alreadyClosed();
        }

        $now = $closedAt ?? new DateTimeImmutable('now');

        return new self(
            id: $this->id,
            technicianId: $this->technicianId,
            status: ShiftStatus::OFF_SHIFT,
            startedAt: $this->startedAt,
            mealBreakStartedAt: $this->mealBreakStartedAt,
            mealBreakEndedAt: $this->mealBreakEndedAt,
            closedAt: $now,
            closedBy: $closedBy,
            closeReason: $closeReason !== null ? trim($closeReason) : null,
            createdAt: $this->createdAt,
            updatedAt: $now,
            shiftDate: $this->shiftDate
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
            shiftDate: isset($row['shift_date']) ? (string)$row['shift_date'] : null
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
        ];
    }
}
