<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;

/**
 * SparePart
 * 
 * Entidad de dominio que representa un repuesto oficial catalogado en el maestro de piezas.
 * Preserva compatibilidad con modelos de máquina y control de baja lógica (Art. III).
 */
class SparePart
{
    private int $id;
    private string $partCode;
    private string $name;
    private SparePartCategory $category;
    private string $manufacturer;
    private float $referenceCost;
    private bool $isActive;
    private ?string $notes;
    /** @var string[] */
    private array $compatibleModels;
    private int $totalInstalledUnits;
    private string $createdAt;
    private string $updatedAt;
    private ?string $deletedAt;

    /**
     * @param string[] $compatibleModels
     */
    public function __construct(
        int $id,
        string $partCode,
        string $name,
        SparePartCategory $category,
        string $manufacturer,
        float $referenceCost,
        bool $isActive = true,
        ?string $notes = null,
        array $compatibleModels = [],
        int $totalInstalledUnits = 0,
        string $createdAt = '',
        string $updatedAt = '',
        ?string $deletedAt = null
    ) {
        $cleanCode = trim($partCode);
        if ($cleanCode === '') {
            throw new InvalidArgumentException('El código del repuesto no puede estar vacío.');
        }

        $cleanName = trim($name);
        if (mb_strlen($cleanName) < 3) {
            throw new InvalidArgumentException('El nombre del repuesto debe tener al menos 3 caracteres.');
        }

        if ($referenceCost < 0.0) {
            throw new InvalidArgumentException('El coste de referencia no puede ser negativo.');
        }

        $this->id = $id;
        $this->partCode = $cleanCode;
        $this->name = $cleanName;
        $this->category = $category;
        $this->manufacturer = trim($manufacturer);
        $this->referenceCost = round($referenceCost, 2);
        $this->isActive = $isActive;
        $this->notes = $notes !== null ? trim($notes) : null;
        $this->compatibleModels = array_values(array_unique(array_filter(array_map('trim', $compatibleModels))));
        $this->totalInstalledUnits = max(0, $totalInstalledUnits);
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->deletedAt = $deletedAt;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPartCode(): string
    {
        return $this->partCode;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCategory(): SparePartCategory
    {
        return $this->category;
    }

    public function getManufacturer(): string
    {
        return $this->manufacturer;
    }

    public function getReferenceCost(): float
    {
        return $this->referenceCost;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * @return string[]
     */
    public function getCompatibleModels(): array
    {
        return $this->compatibleModels;
    }

    public function isCompatibleWithModel(string $model): bool
    {
        return in_array(trim($model), $this->compatibleModels, true);
    }

    public function getTotalInstalledUnits(): int
    {
        return $this->totalInstalledUnits;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?string
    {
        return $this->deletedAt;
    }

    /**
     * Serialización a array para respuestas API REST.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                    => $this->id,
            'part_code'             => $this->partCode,
            'name'                  => $this->name,
            'category'              => $this->category->value,
            'category_label'        => $this->category->label(),
            'manufacturer'          => $this->manufacturer,
            'reference_cost'        => $this->referenceCost,
            'is_active'             => $this->isActive,
            'notes'                 => $this->notes,
            'compatible_models'     => $this->compatibleModels,
            'total_installed_units' => $this->totalInstalledUnits,
            'created_at'            => $this->createdAt,
            'updated_at'            => $this->updatedAt,
        ];
    }
}
