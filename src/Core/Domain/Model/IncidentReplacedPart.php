<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;

/**
 * IncidentReplacedPart
 * 
 * Entidad de dominio que representa un componente sustituido durante una intervención
 * correctiva o preventiva, con snapshot inmutable de coste de referencia (Art. III).
 */
class IncidentReplacedPart
{
    private int $id;
    private string $interventionType; // 'INCIDENT' | 'PREVENTIVE'
    private ?int $incidentId;
    private ?int $preventiveOrderId;
    private int $machineId;
    private int $locationId;
    private int $technicianId;
    private ?int $sparePartId;
    private bool $isOutOfCatalog;
    private ?string $customPartName;
    private int $quantity;
    private float $unitCostSnapshot;
    private float $totalCostSnapshot;
    private OldPartDestination $oldPartDestination;
    private ?string $notes;
    private string $installedAt;
    private string $createdAt;

    // Campos complementarios de solo lectura para agregación y vistas
    private ?string $partCode;
    private ?string $partName;
    private ?string $machineCode;
    private ?string $machineModel;
    private ?string $locationName;
    private ?string $technicianName;

    public function __construct(
        int $id,
        string $interventionType,
        ?int $incidentId,
        ?int $preventiveOrderId,
        int $machineId,
        int $locationId,
        int $technicianId,
        ?int $sparePartId,
        bool $isOutOfCatalog,
        ?string $customPartName,
        int $quantity,
        float $unitCostSnapshot,
        OldPartDestination $oldPartDestination,
        ?string $notes = null,
        string $installedAt = '',
        string $createdAt = '',
        ?string $partCode = null,
        ?string $partName = null,
        ?string $machineCode = null,
        ?string $machineModel = null,
        ?string $locationName = null,
        ?string $technicianName = null
    ) {
        $typeUpper = strtoupper(trim($interventionType));
        if (!in_array($typeUpper, ['INCIDENT', 'PREVENTIVE'], true)) {
            throw new InvalidArgumentException("Tipo de intervención inválido: {$interventionType}");
        }

        if ($typeUpper === 'INCIDENT' && ($incidentId === null || $incidentId <= 0)) {
            throw new InvalidArgumentException('El ID de incidencia es obligatorio para intervenciones de correctivo.');
        }

        if ($typeUpper === 'PREVENTIVE' && ($preventiveOrderId === null || $preventiveOrderId <= 0)) {
            throw new InvalidArgumentException('El ID de orden preventiva es obligatorio para intervenciones de preventivo.');
        }

        if ($machineId <= 0 || $locationId <= 0 || $technicianId <= 0) {
            throw new InvalidArgumentException('Los identificadores de máquina, sede y técnico son obligatorios.');
        }

        if ($quantity < 1 || $quantity > 50) {
            throw new InvalidArgumentException('La cantidad debe estar comprendida entre 1 y 50 unidades.');
        }

        if ($unitCostSnapshot < 0.0) {
            throw new InvalidArgumentException('El coste unitario congelado no puede ser negativo.');
        }

        $this->id = $id;
        $this->interventionType = $typeUpper;
        $this->incidentId = $incidentId;
        $this->preventiveOrderId = $preventiveOrderId;
        $this->machineId = $machineId;
        $this->locationId = $locationId;
        $this->technicianId = $technicianId;
        $this->sparePartId = $sparePartId;
        $this->isOutOfCatalog = $isOutOfCatalog;
        $this->customPartName = $customPartName !== null ? trim($customPartName) : null;
        $this->quantity = $quantity;
        $this->unitCostSnapshot = round($unitCostSnapshot, 2);
        $this->totalCostSnapshot = round($this->quantity * $this->unitCostSnapshot, 2);
        $this->oldPartDestination = $oldPartDestination;
        $this->notes = $notes !== null ? trim($notes) : null;
        $this->installedAt = $installedAt;
        $this->createdAt = $createdAt;
        $this->partCode = $partCode;
        $this->partName = $partName;
        $this->machineCode = $machineCode;
        $this->machineModel = $machineModel;
        $this->locationName = $locationName;
        $this->technicianName = $technicianName;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getInterventionType(): string
    {
        return $this->interventionType;
    }

    public function getIncidentId(): ?int
    {
        return $this->incidentId;
    }

    public function getPreventiveOrderId(): ?int
    {
        return $this->preventiveOrderId;
    }

    public function getMachineId(): int
    {
        return $this->machineId;
    }

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function getTechnicianId(): int
    {
        return $this->technicianId;
    }

    public function getSparePartId(): ?int
    {
        return $this->sparePartId;
    }

    public function isOutOfCatalog(): bool
    {
        return $this->isOutOfCatalog;
    }

    public function getCustomPartName(): ?string
    {
        return $this->customPartName;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitCostSnapshot(): float
    {
        return $this->unitCostSnapshot;
    }

    public function getTotalCostSnapshot(): float
    {
        return $this->totalCostSnapshot;
    }

    public function getOldPartDestination(): OldPartDestination
    {
        return $this->oldPartDestination;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getInstalledAt(): string
    {
        return $this->installedAt;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getPartCode(): ?string
    {
        return $this->partCode;
    }

    public function getPartName(): ?string
    {
        return $this->partName;
    }

    public function getMachineCode(): ?string
    {
        return $this->machineCode;
    }

    public function getMachineModel(): ?string
    {
        return $this->machineModel;
    }

    public function getLocationName(): ?string
    {
        return $this->locationName;
    }

    public function getTechnicianName(): ?string
    {
        return $this->technicianName;
    }

    /**
     * Serialización a array para respuestas API REST.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                     => $this->id,
            'intervention_type'      => $this->interventionType,
            'incident_id'            => $this->incidentId,
            'preventive_order_id'    => $this->preventiveOrderId,
            'machine_id'             => $this->machineId,
            'location_id'            => $this->locationId,
            'technician_id'          => $this->technicianId,
            'spare_part_id'          => $this->sparePartId,
            'is_out_of_catalog'      => $this->isOutOfCatalog,
            'custom_part_name'       => $this->customPartName,
            'quantity'               => $this->quantity,
            'unit_cost_snapshot'     => $this->unitCostSnapshot,
            'total_cost_snapshot'    => $this->totalCostSnapshot,
            'old_part_destination'   => $this->oldPartDestination->value,
            'destination_label'      => $this->oldPartDestination->label(),
            'notes'                  => $this->notes,
            'installed_at'           => $this->installedAt,
            'created_at'             => $this->createdAt,
            'part_code'              => $this->partCode,
            'part_name'              => $this->partName,
            'machine_code'           => $this->machineCode,
            'machine_model'          => $this->machineModel,
            'location_name'          => $this->locationName,
            'technician_name'        => $this->technicianName,
        ];
    }
}
