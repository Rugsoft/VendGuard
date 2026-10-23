<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use VendGuard\Core\Domain\Exception\InvalidAbsenceException;
use VendGuard\Core\Domain\ValueObject\AbsenceReason;

/**
 * TechnicianAbsence
 *
 * Entidad de dominio inmutable que representa una ausencia temporal planificada o médica
 * de un técnico (Módulo 21).
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades readonly, métodos puros.
 * Inmutabilidad: cancelación produce una nueva instancia con cancelled_at auditado.
 */
final class TechnicianAbsence
{
    public const MAX_NOTES_LENGTH = 500;
    public const MIN_CANCELLATION_REASON_LENGTH = 5;

    public function __construct(
        private readonly ?int $id,
        private readonly int $technicianId,
        private readonly AbsenceReason $reason,
        private readonly DateTimeImmutable $startsAt,
        private readonly DateTimeImmutable $endsAt,
        private readonly ?string $notes,
        private readonly int $createdBy,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $cancelledAt = null,
        private readonly ?int $cancelledBy = null,
        private readonly ?string $cancellationReason = null
    ) {
    }

    public static function create(
        int $technicianId,
        AbsenceReason $reason,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?string $notes,
        int $createdBy
    ): self {
        if ($endsAt < $startsAt) {
            throw InvalidAbsenceException::invalidDateRange();
        }

        $cleanNotes = $notes !== null ? trim($notes) : null;
        if ($cleanNotes !== null && $cleanNotes !== '') {
            if (mb_strlen($cleanNotes) > self::MAX_NOTES_LENGTH) {
                throw InvalidAbsenceException::notesTooLong(self::MAX_NOTES_LENGTH);
            }
        } else {
            $cleanNotes = null;
        }

        return new self(
            id: null,
            technicianId: $technicianId,
            reason: $reason,
            startsAt: $startsAt,
            endsAt: $endsAt,
            notes: $cleanNotes,
            createdBy: $createdBy,
            createdAt: new DateTimeImmutable('now')
        );
    }

    public function cancel(int $cancelledBy, string $cancellationReason, ?DateTimeImmutable $cancelledAt = null): self
    {
        if ($this->isCancelled()) {
            throw InvalidAbsenceException::alreadyCancelled();
        }

        $cleanReason = trim($cancellationReason);
        if (mb_strlen($cleanReason) < self::MIN_CANCELLATION_REASON_LENGTH) {
            throw InvalidAbsenceException::cancellationReasonTooShort(self::MIN_CANCELLATION_REASON_LENGTH);
        }

        return new self(
            id: $this->id,
            technicianId: $this->technicianId,
            reason: $this->reason,
            startsAt: $this->startsAt,
            endsAt: $this->endsAt,
            notes: $this->notes,
            createdBy: $this->createdBy,
            createdAt: $this->createdAt,
            cancelledAt: $cancelledAt ?? new DateTimeImmutable('now'),
            cancelledBy: $cancelledBy,
            cancellationReason: $cleanReason
        );
    }

    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null;
    }

    public function isActiveAt(DateTimeImmutable $time): bool
    {
        if ($this->isCancelled()) {
            return false;
        }

        return $time >= $this->startsAt && $time <= $this->endsAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function technicianId(): int
    {
        return $this->technicianId;
    }

    public function reason(): AbsenceReason
    {
        return $this->reason;
    }

    public function startsAt(): DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function createdBy(): int
    {
        return $this->createdBy;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function cancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function cancelledBy(): ?int
    {
        return $this->cancelledBy;
    }

    public function cancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int)$row['id'] : null,
            technicianId: (int)$row['technician_id'],
            reason: AbsenceReason::from((string)$row['reason']),
            startsAt: new DateTimeImmutable((string)$row['starts_at']),
            endsAt: new DateTimeImmutable((string)$row['ends_at']),
            notes: isset($row['notes']) && $row['notes'] !== null ? (string)$row['notes'] : null,
            createdBy: (int)$row['created_by'],
            createdAt: isset($row['created_at']) && $row['created_at'] !== null ? new DateTimeImmutable((string)$row['created_at']) : null,
            cancelledAt: isset($row['cancelled_at']) && $row['cancelled_at'] !== null ? new DateTimeImmutable((string)$row['cancelled_at']) : null,
            cancelledBy: isset($row['cancelled_by']) && $row['cancelled_by'] !== null ? (int)$row['cancelled_by'] : null,
            cancellationReason: isset($row['cancellation_reason']) && $row['cancellation_reason'] !== null ? (string)$row['cancellation_reason'] : null
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'technician_id' => $this->technicianId,
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'starts_at' => $this->startsAt->format(DateTimeImmutable::ATOM),
            'ends_at' => $this->endsAt->format(DateTimeImmutable::ATOM),
            'notes' => $this->notes,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'is_cancelled' => $this->isCancelled(),
            'cancelled_at' => $this->cancelledAt?->format(DateTimeImmutable::ATOM),
            'cancelled_by' => $this->cancelledBy,
            'cancellation_reason' => $this->cancellationReason,
        ];
    }
}
