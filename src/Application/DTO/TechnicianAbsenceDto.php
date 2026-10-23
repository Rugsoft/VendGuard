<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\TechnicianAbsence;

/**
 * TechnicianAbsenceDto
 *
 * DTO para la transferencia de información de ausencias de técnicos.
 */
final class TechnicianAbsenceDto
{
    public function __construct(
        public readonly int $id,
        public readonly int $technicianId,
        public readonly string $technicianName,
        public readonly string $reason,
        public readonly string $reasonLabel,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly ?string $notes,
        public readonly int $createdBy,
        public readonly ?string $creatorName,
        public readonly ?string $createdAt,
        public readonly bool $isCancelled,
        public readonly ?string $cancelledAt,
        public readonly ?int $cancelledBy,
        public readonly ?string $cancellerName,
        public readonly ?string $cancellationReason
    ) {
    }

    public static function fromEntity(
        TechnicianAbsence $absence,
        ?string $technicianName = null,
        ?string $creatorName = null,
        ?string $cancellerName = null
    ): self {
        return new self(
            id: (int)$absence->id(),
            technicianId: $absence->technicianId(),
            technicianName: $technicianName ?? ('Técnico #' . $absence->technicianId()),
            reason: $absence->reason()->value,
            reasonLabel: $absence->reason()->label(),
            startsAt: $absence->startsAt()->format(DateTimeImmutable::ATOM),
            endsAt: $absence->endsAt()->format(DateTimeImmutable::ATOM),
            notes: $absence->notes(),
            createdBy: $absence->createdBy(),
            creatorName: $creatorName,
            createdAt: $absence->createdAt()?->format(DateTimeImmutable::ATOM),
            isCancelled: $absence->isCancelled(),
            cancelledAt: $absence->cancelledAt()?->format(DateTimeImmutable::ATOM),
            cancelledBy: $absence->cancelledBy(),
            cancellerName: $cancellerName,
            cancellationReason: $absence->cancellationReason()
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'technician_id' => $this->technicianId,
            'technician_name' => $this->technicianName,
            'reason' => $this->reason,
            'reason_label' => $this->reasonLabel,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'notes' => $this->notes,
            'created_by' => $this->createdBy,
            'creator_name' => $this->creatorName,
            'created_at' => $this->createdAt,
            'is_cancelled' => $this->isCancelled,
            'cancelled_at' => $this->cancelledAt,
            'cancelled_by' => $this->cancelledBy,
            'canceller_name' => $this->cancellerName,
            'cancellation_reason' => $this->cancellationReason,
        ];
    }
}
