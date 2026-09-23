<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;

/**
 * QrLabelConfig
 * 
 * DTO inmutable que modela la configuración integral y los metadatos necesarios
 * para renderizar la etiqueta física adhesiva con código QR.
 * 
 * Contiene información de la máquina, ubicación, teléfono editable de asistencia
 * técnica y proporciones físicas estandarizadas (400x600 px por defecto).
 * 
 * Cumple con Dogma Vanilla y los Artículos II, IV y V de la Constitución.
 */
class QrLabelConfig implements JsonSerializable
{
    private string $machineCode;
    private string $machineModel;
    private string $machineType;
    private string $locationName;
    private string $floorWing;
    private string $supportPhone;
    private string $targetUrl;
    private bool $isPerishable;
    private int $width;
    private int $height;

    /**
     * @param string $machineCode Código de máquina visible (ej: 'VEND-0101').
     * @param string $machineModel Modelo de la máquina (ej: 'Sanden Vendo G-Drink').
     * @param string $machineType Tipo de máquina (ej: 'PERISHABLE_FOOD', 'HOT_DRINKS', etc.).
     * @param string $locationName Nombre de la sede (ej: 'Hospital del Mar - Edificio Central').
     * @param string $floorWing Ubicación específica de planta/ala (ej: 'Planta Baja - Urgencias').
     * @param string $supportPhone Teléfono editable de asistencia técnica (ej: '600111222').
     * @param string $targetUrl URL completa codificada en el QR.
     * @param bool|null $isPerishable Indica si expende alimentos frescos perecederos (si es null se deduce del tipo).
     * @param int $width Ancho nominal en píxeles para el renderizado vectorial (por defecto 400).
     * @param int $height Alto nominal en píxeles para el renderizado vectorial (por defecto 600).
     * @throws InvalidArgumentException Si alguno de los datos obligatorios está vacío o las dimensiones son inválidas.
     */
    public function __construct(
        string $machineCode,
        string $machineModel,
        string $machineType,
        string $locationName,
        string $floorWing,
        string $supportPhone,
        string $targetUrl,
        ?bool $isPerishable = null,
        int $width = 400,
        int $height = 600
    ) {
        $cleanMachineCode = strtoupper(trim($machineCode));
        if ($cleanMachineCode === '') {
            throw new InvalidArgumentException('El código de máquina no puede estar vacío.');
        }

        $cleanModel = trim($machineModel);
        if ($cleanModel === '') {
            throw new InvalidArgumentException('El modelo de máquina no puede estar vacío.');
        }

        $cleanType = strtoupper(trim($machineType));
        if ($cleanType === '') {
            throw new InvalidArgumentException('El tipo de máquina no puede estar vacío.');
        }

        $cleanLocation = trim($locationName);
        if ($cleanLocation === '') {
            throw new InvalidArgumentException('El nombre de la sede no puede estar vacío.');
        }

        $cleanFloorWing = trim($floorWing);
        if ($cleanFloorWing === '') {
            throw new InvalidArgumentException('La planta o ala física no puede estar vacía.');
        }

        $cleanPhone = trim($supportPhone);
        if ($cleanPhone === '') {
            throw new InvalidArgumentException('El teléfono de asistencia técnica no puede estar vacío.');
        }

        $cleanTargetUrl = trim($targetUrl);
        if ($cleanTargetUrl === '') {
            throw new InvalidArgumentException('La URL de destino del código QR no puede estar vacía.');
        }

        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException('Las dimensiones de la etiqueta deben ser enteros positivos.');
        }

        $this->machineCode = $cleanMachineCode;
        $this->machineModel = $cleanModel;
        $this->machineType = $cleanType;
        $this->locationName = $cleanLocation;
        $this->floorWing = $cleanFloorWing;
        $this->supportPhone = $cleanPhone;
        $this->targetUrl = $cleanTargetUrl;
        $this->isPerishable = $isPerishable ?? ($cleanType === 'PERISHABLE_FOOD');
        $this->width = $width;
        $this->height = $height;
    }

    public function getMachineCode(): string
    {
        return $this->machineCode;
    }

    public function getMachineModel(): string
    {
        return $this->machineModel;
    }

    public function getMachineType(): string
    {
        return $this->machineType;
    }

    public function getLocationName(): string
    {
        return $this->locationName;
    }

    public function getFloorWing(): string
    {
        return $this->floorWing;
    }

    public function getSupportPhone(): string
    {
        return $this->supportPhone;
    }

    public function getTargetUrl(): string
    {
        return $this->targetUrl;
    }

    public function isPerishable(): bool
    {
        return $this->isPerishable;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Retorna una nueva instancia inmutable modificando únicamente el teléfono de soporte.
     */
    public function withSupportPhone(string $newPhone): self
    {
        return new self(
            $this->machineCode,
            $this->machineModel,
            $this->machineType,
            $this->locationName,
            $this->floorWing,
            $newPhone,
            $this->targetUrl,
            $this->isPerishable,
            $this->width,
            $this->height
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'machine_code' => $this->machineCode,
            'machine_model' => $this->machineModel,
            'machine_type' => $this->machineType,
            'location_name' => $this->locationName,
            'floor_wing' => $this->floorWing,
            'support_phone' => $this->supportPhone,
            'target_url' => $this->targetUrl,
            'is_perishable' => $this->isPerishable,
            'width' => $this->width,
            'height' => $this->height,
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
