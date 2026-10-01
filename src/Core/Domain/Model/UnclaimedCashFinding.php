<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable record of cash found stuck inside a machine with no prior consumer
 * claim (RF-REF-04).
 *
 * The money never reaches the claimant, so the only accountability requirement
 * is traceability: which technician pulled it out, how much, and under which
 * incident (Constitution Art. III).
 */
final readonly class UnclaimedCashFinding implements JsonSerializable
{
    public function __construct(
        private int $id,
        private int $incidentId,
        private int $machineId,
        private int $technicianId,
        private float $amount,
        private string $notes,
        private string $createdAt
    ) {
        if ($id < 1 || $incidentId < 1 || $machineId < 1 || $technicianId < 1) {
            throw new InvalidArgumentException('Los identificadores del hallazgo deben ser enteros positivos.');
        }

        if (!is_finite($amount)) {
            throw new InvalidArgumentException('El importe recuperado debe ser un valor finito.');
        }

        // A zero finding records nothing, so it is rejected at the boundary.
        if ($amount <= 0.0) {
            throw new InvalidArgumentException('El importe del hallazgo debe ser estrictamente mayor que 0,00 €.');
        }

        if (trim($createdAt) === '') {
            throw new InvalidArgumentException('El hallazgo requiere su marca temporal de registro.');
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getIncidentId(): int
    {
        return $this->incidentId;
    }

    public function getMachineId(): int
    {
        return $this->machineId;
    }

    public function getTechnicianId(): int
    {
        return $this->technicianId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getNotes(): string
    {
        return $this->notes;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'incident_id' => $this->incidentId,
            'machine_id' => $this->machineId,
            'technician_id' => $this->technicianId,
            'amount' => $this->amount,
            'notes' => $this->notes,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}