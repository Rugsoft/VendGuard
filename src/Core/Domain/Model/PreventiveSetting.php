<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * PreventiveSetting
 * 
 * Entidad de dominio que representa la configuración y periodicidad normativa
 * de inspecciones preventivas para una tipología de dispensación (Art. II).
 */
class PreventiveSetting
{
    private int $id;
    private string $machineType;
    private int $defaultFrequencyDays;
    private int $maxAllowedDays;
    private int $advanceWarningDays;
    private string $createdAt;
    private string $updatedAt;

    public function __construct(
        int $id,
        string $machineType,
        int $defaultFrequencyDays,
        int $maxAllowedDays,
        int $advanceWarningDays,
        string $createdAt = '',
        string $updatedAt = ''
    ) {
        $this->id = $id;
        $this->machineType = $machineType;
        $this->defaultFrequencyDays = $defaultFrequencyDays;
        $this->maxAllowedDays = $maxAllowedDays;
        $this->advanceWarningDays = $advanceWarningDays;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getMachineType(): string
    {
        return $this->machineType;
    }

    public function getDefaultFrequencyDays(): int
    {
        return $this->defaultFrequencyDays;
    }

    public function getMaxAllowedDays(): int
    {
        return $this->maxAllowedDays;
    }

    public function getAdvanceWarningDays(): int
    {
        return $this->advanceWarningDays;
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'machine_type' => $this->machineType,
            'default_frequency_days' => $this->defaultFrequencyDays,
            'max_allowed_days' => $this->maxAllowedDays,
            'advance_warning_days' => $this->advanceWarningDays,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
