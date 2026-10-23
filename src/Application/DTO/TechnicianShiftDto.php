<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;

/**
 * TechnicianShiftDto
 *
 * DTO para la transferencia del estado de jornada y turnos de técnicos.
 */
final class TechnicianShiftDto
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $technicianId,
        public readonly ?string $technicianName,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly bool $isAvailable,
        public readonly bool $isOpen,
        public readonly ?string $shiftDate,
        public readonly ?string $startedAt,
        public readonly ?string $mealBreakStartedAt,
        public readonly ?string $mealBreakEndedAt,
        public readonly ?string $closedAt,
        public readonly ?int $closedBy,
        public readonly ?string $closeReason,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt
    ) {
    }

    public static function fromEntity(TechnicianShift $shift, ?string $technicianName = null): self
    {
        return new self(
            id: $shift->id(),
            technicianId: $shift->technicianId(),
            technicianName: $technicianName,
            status: $shift->status()->value,
            statusLabel: $shift->status()->label(),
            isAvailable: $shift->status()->isAvailable(),
            isOpen: $shift->isOpen(),
            shiftDate: $shift->shiftDate(),
            startedAt: $shift->startedAt()->format(DateTimeImmutable::ATOM),
            mealBreakStartedAt: $shift->mealBreakStartedAt()?->format(DateTimeImmutable::ATOM),
            mealBreakEndedAt: $shift->mealBreakEndedAt()?->format(DateTimeImmutable::ATOM),
            closedAt: $shift->closedAt()?->format(DateTimeImmutable::ATOM),
            closedBy: $shift->closedBy(),
            closeReason: $shift->closeReason(),
            createdAt: $shift->createdAt()?->format(DateTimeImmutable::ATOM),
            updatedAt: $shift->updatedAt()?->format(DateTimeImmutable::ATOM)
        );
    }

    public static function offShift(int $technicianId, ?string $technicianName = null): self
    {
        return new self(
            id: null,
            technicianId: $technicianId,
            technicianName: $technicianName,
            status: ShiftStatus::OFF_SHIFT->value,
            statusLabel: ShiftStatus::OFF_SHIFT->label(),
            isAvailable: false,
            isOpen: false,
            shiftDate: null,
            startedAt: null,
            mealBreakStartedAt: null,
            mealBreakEndedAt: null,
            closedAt: null,
            closedBy: null,
            closeReason: null,
            createdAt: null,
            updatedAt: null
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'technician_id' => $this->technicianId,
            'technician_name' => $this->technicianName,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'is_available' => $this->isAvailable,
            'is_open' => $this->isOpen,
            'shift_date' => $this->shiftDate,
            'started_at' => $this->startedAt,
            'meal_break_started_at' => $this->mealBreakStartedAt,
            'meal_break_ended_at' => $this->mealBreakEndedAt,
            'closed_at' => $this->closedAt,
            'closed_by' => $this->closedBy,
            'close_reason' => $this->closeReason,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
