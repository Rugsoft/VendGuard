<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

/**
 * ShiftHandoverResultDto
 *
 * DTO que resume el resultado de la operación atómica de cierre de turno y relevo de ruta (Módulo 21).
 */
final class ShiftHandoverResultDto
{
    /**
     * @param list<array<string, mixed>> $transferredIncidents
     * @param list<array<string, mixed>> $retainedIncidents
     * @param list<array<string, mixed>> $sanitaryEmergencyTransfers
     * @param list<array<string, mixed>> $sanitaryEmergencyUnassigned
     */
    public function __construct(
        public readonly TechnicianShiftDto $shift,
        public readonly array $transferredIncidents,
        public readonly array $retainedIncidents,
        public readonly array $sanitaryEmergencyTransfers,
        public readonly array $sanitaryEmergencyUnassigned,
        public readonly ?int $targetTechnicianId,
        public readonly ?string $targetTechnicianName,
        public readonly string $summary
    ) {
    }

    public function toArray(): array
    {
        return [
            'shift' => $this->shift->toArray(),
            'transferred_incidents' => $this->transferredIncidents,
            'retained_incidents' => $this->retainedIncidents,
            'sanitary_emergency_transfers' => $this->sanitaryEmergencyTransfers,
            'sanitary_emergency_unassigned' => $this->sanitaryEmergencyUnassigned,
            'target_technician_id' => $this->targetTechnicianId,
            'target_technician_name' => $this->targetTechnicianName,
            'summary' => $this->summary,
        ];
    }
}
