<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;

/**
 * SparePartRequest
 * 
 * Entidad de dominio que representa una solicitud estructurada de repuesto
 * emitida durante la pausa técnica de una avería correctiva (RF-REP-03 / RF-REP-04).
 */
class SparePartRequest
{
    private int $id;
    private int $incidentId;
    private ?int $sparePartId;
    private bool $isOutOfCatalog;
    private ?string $customPartDescription;
    private int $quantity;
    private SparePartRequestStatus $status;
    private int $requestedByUserId;
    private ?string $partCode;
    private ?string $partName;
    private ?string $technicianName;
    private string $createdAt;
    private string $updatedAt;

    public function __construct(
        int $id,
        int $incidentId,
        ?int $sparePartId,
        bool $isOutOfCatalog,
        ?string $customPartDescription,
        int $quantity,
        SparePartRequestStatus $status,
        int $requestedByUserId,
        ?string $partCode = null,
        ?string $partName = null,
        ?string $technicianName = null,
        string $createdAt = '',
        string $updatedAt = ''
    ) {
        if ($incidentId <= 0) {
            throw new InvalidArgumentException('El ID de incidencia debe ser un entero positivo.');
        }

        if ($quantity < 1 || $quantity > 50) {
            throw new InvalidArgumentException('La cantidad debe estar comprendida entre 1 y 50 unidades.');
        }

        if ($isOutOfCatalog) {
            $desc = $customPartDescription !== null ? trim($customPartDescription) : '';
            if (mb_strlen($desc) < 20) {
                throw new InvalidArgumentException('La justificación técnica de pieza fuera de catálogo debe tener al menos 20 caracteres.');
            }
            $this->customPartDescription = $desc;
            $this->sparePartId = null;
        } else {
            if ($sparePartId === null || $sparePartId <= 0) {
                throw new InvalidArgumentException('Debe especificar un ID de repuesto válido del catálogo.');
            }
            $this->sparePartId = $sparePartId;
            $this->customPartDescription = null;
        }

        $this->id = $id;
        $this->incidentId = $incidentId;
        $this->isOutOfCatalog = $isOutOfCatalog;
        $this->quantity = $quantity;
        $this->status = $status;
        $this->requestedByUserId = $requestedByUserId;
        $this->partCode = $partCode;
        $this->partName = $partName;
        $this->technicianName = $technicianName;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getIncidentId(): int
    {
        return $this->incidentId;
    }

    public function getSparePartId(): ?int
    {
        return $this->sparePartId;
    }

    public function isOutOfCatalog(): bool
    {
        return $this->isOutOfCatalog;
    }

    public function getCustomPartDescription(): ?string
    {
        return $this->customPartDescription;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getStatus(): SparePartRequestStatus
    {
        return $this->status;
    }

    public function getRequestedByUserId(): int
    {
        return $this->requestedByUserId;
    }

    public function getPartCode(): ?string
    {
        return $this->partCode;
    }

    public function getPartName(): ?string
    {
        return $this->partName;
    }

    public function getTechnicianName(): ?string
    {
        return $this->technicianName;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    /**
     * Serialización a array para respuestas API REST.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                      => $this->id,
            'incident_id'             => $this->incidentId,
            'spare_part_id'           => $this->sparePartId,
            'is_out_of_catalog'       => $this->isOutOfCatalog,
            'custom_part_description' => $this->customPartDescription,
            'quantity'                => $this->quantity,
            'status'                  => $this->status->value,
            'status_label'            => $this->status->label(),
            'requested_by_user_id'    => $this->requestedByUserId,
            'part_code'               => $this->partCode,
            'part_name'               => $this->partName,
            'technician_name'         => $this->technicianName,
            'created_at'              => $this->createdAt,
            'updated_at'              => $this->updatedAt,
        ];
    }
}
