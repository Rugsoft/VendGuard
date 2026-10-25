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
 * `today_summary` viaja de forma aditiva (Módulo 22): el Portal de Sede no lo
 * consume y la vista móvil del técnico lo usa para mostrar el acumulado del día
 * sin una segunda llamada.
 */
final class TechnicianShiftDto
{
    /**
     * @param array{date: string, sessions_count: int, gross_seconds: int, gross_formatted: string, meal_break_seconds: int, meal_break_formatted: string, effective_seconds: int, effective_formatted: string}|null $todaySummary
     */
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
        public readonly ?array $todaySummary = null,
        public readonly int $grossSeconds = 0,
        public readonly string $grossFormatted = '00h 00m',
        public readonly int $mealBreakSeconds = 0,
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

    public static function fromEntity(
        TechnicianShift $shift,
        ?string $technicianName = null,
        ?array $todaySummary = null
    ): self {
        $referenceNow = new DateTimeImmutable('now');

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
            todaySummary: $todaySummary,
            grossSeconds: $shift->grossDurationSeconds($referenceNow),
            grossFormatted: self::formatSeconds($shift->grossDurationSeconds($referenceNow)),
            mealBreakSeconds: $shift->totalMealBreakSeconds($referenceNow),
            mealBreakFormatted: self::formatSeconds($shift->totalMealBreakSeconds($referenceNow)),
            effectiveSeconds: $shift->effectiveWorkSeconds($referenceNow),
            effectiveFormatted: self::formatSeconds($shift->effectiveWorkSeconds($referenceNow)),
            isAnomalous: $shift->isAnomalousDuration($referenceNow),
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

    public static function offShift(
        int $technicianId,
        ?string $technicianName = null,
        ?array $todaySummary = null
    ): self {
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
            updatedAt: null,
            todaySummary: $todaySummary
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
            'today_summary' => $this->todaySummary,
            'gross_seconds' => $this->grossSeconds,
            'gross_formatted' => $this->grossFormatted,
            'meal_break_seconds' => $this->mealBreakSeconds,
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

    /** Formato de duración del contrato: `HHh MMm`. */
    private static function formatSeconds(int $seconds): string
    {
        $safeSeconds = max(0, $seconds);

        return sprintf('%02dh %02dm', intdiv($safeSeconds, 3600), intdiv($safeSeconds % 3600, 60));
    }
}
