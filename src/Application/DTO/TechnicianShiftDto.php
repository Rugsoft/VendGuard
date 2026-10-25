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
 *
 * Proyecta el cómputo del Módulo 22 en segundos enteros y en el formato
 * operativo `HHh MMm`, además de las insignias de cierre forzado, rectificación
 * y duración anómala. Ningún dato laboral adicional se expone fuera del turno.
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
        public readonly ?string $updatedAt,
        public readonly int $mealBreakSeconds = 0,
        public readonly int $grossSeconds = 0,
        public readonly string $grossFormatted = '00h 00m',
        public readonly string $mealBreakFormatted = '00h 00m',
        public readonly int $effectiveSeconds = 0,
        public readonly string $effectiveFormatted = '00h 00m',
        public readonly bool $isAnomalous = false,
        public readonly bool $isForceClosed = false,
        public readonly bool $isAdjusted = false,
        public readonly ?string $originalStartedAt = null,
        public readonly ?string $originalClosedAt = null,
        public readonly ?int $originalMealBreakSeconds = null,
        public readonly ?string $adjustedAt = null,
        public readonly ?int $adjustedBy = null,
        public readonly ?string $adjustmentReason = null
    ) {
    }

    public static function fromEntity(TechnicianShift $shift, ?string $technicianName = null): self
    {
        $now = new DateTimeImmutable('now');
        $grossSeconds = $shift->grossDurationSeconds($now);
        $mealBreakSeconds = $shift->totalMealBreakSeconds($now);
        $effectiveSeconds = max(0, $grossSeconds - $mealBreakSeconds);

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
            updatedAt: $shift->updatedAt()?->format(DateTimeImmutable::ATOM),
            mealBreakSeconds: $mealBreakSeconds,
            grossSeconds: $grossSeconds,
            grossFormatted: self::formatDuration($grossSeconds),
            mealBreakFormatted: self::formatDuration($mealBreakSeconds),
            effectiveSeconds: $effectiveSeconds,
            effectiveFormatted: self::formatDuration($effectiveSeconds),
            isAnomalous: $grossSeconds > TechnicianShift::ANOMALOUS_DURATION_THRESHOLD_SECONDS,
            isForceClosed: $shift->isForceClosed(),
            isAdjusted: $shift->isAdjusted(),
            originalStartedAt: $shift->originalStartedAt()->format(DateTimeImmutable::ATOM),
            originalClosedAt: $shift->originalClosedAt()?->format(DateTimeImmutable::ATOM),
            originalMealBreakSeconds: $shift->originalMealBreakSeconds(),
            adjustedAt: $shift->adjustedAt()?->format(DateTimeImmutable::ATOM),
            adjustedBy: $shift->adjustedBy(),
            adjustmentReason: $shift->adjustmentReason()
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
            'meal_break_seconds' => $this->mealBreakSeconds,
            'gross_seconds' => $this->grossSeconds,
            'gross_formatted' => $this->grossFormatted,
            'meal_break_formatted' => $this->mealBreakFormatted,
            'effective_seconds' => $this->effectiveSeconds,
            'effective_formatted' => $this->effectiveFormatted,
            'is_anomalous' => $this->isAnomalous,
            'is_force_closed' => $this->isForceClosed,
            'is_adjusted' => $this->isAdjusted,
            'original_started_at' => $this->originalStartedAt,
            'original_closed_at' => $this->originalClosedAt,
            'original_meal_break_seconds' => $this->originalMealBreakSeconds,
            'adjusted_at' => $this->adjustedAt,
            'adjusted_by' => $this->adjustedBy,
            'adjustment_reason' => $this->adjustmentReason,
        ];
    }

    /**
     * Formatea segundos enteros en el patrón operativo `HHh MMm`.
     */
    private static function formatDuration(int $seconds): string
    {
        $safeSeconds = max(0, $seconds);
        $hours = intdiv($safeSeconds, 3600);
        $minutes = intdiv($safeSeconds % 3600, 60);

        return sprintf('%02dh %02dm', $hours, $minutes);
    }
}
