<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;

/**
 * StandardSolution
 *
 * Entidad inmutable de una solución estandarizada vinculada a una causa raíz
 * (Módulo 25, RF-01).
 *
 * Invariantes:
 *   1. `code` comparte la forma canónica del catálogo (`^[A-Z0-9-]{3,32}$`) y es
 *      único en todo el sistema, no sólo dentro de su causa (C4, QA 1).
 *   2. `rootCauseId` es obligatorio y no se puede reasignar: la causa padre se
 *      fija al crear la solución (C4).
 *   3. `isDefault = TRUE` exige `isActive = TRUE`, espejo de
 *      `chk_standard_solutions_default_active`: una solución inactiva jamás
 *      conserva la marca de predeterminada (QA 1).
 *
 * La entidad es inmutable: `withId()` devuelve una copia con el identificador
 * asignado por el motor.
 */
final class StandardSolution implements JsonSerializable
{
    /** Forma canónica del código de solución (C4, QA 1). */
    public const CODE_PATTERN = '/^[A-Z0-9-]{3,32}$/';

    public const MIN_NAME_LENGTH = 5;

    public const MAX_NAME_LENGTH = 140;

    private ?int $id;

    private int $rootCauseId;

    private string $code;

    private string $name;

    private bool $isDefault;

    private bool $isActive;

    private ?string $createdAt;

    private ?string $updatedAt;

    public function __construct(
        ?int $id,
        int $rootCauseId,
        string $code,
        string $name,
        bool $isDefault = false,
        bool $isActive = true,
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        if ($rootCauseId <= 0) {
            throw new InvalidArgumentException('Toda solución estandarizada debe pertenecer a una causa raíz persistida.');
        }

        $cleanCode = strtoupper(trim($code));
        if (preg_match(self::CODE_PATTERN, $cleanCode) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'El código de la solución estandarizada debe cumplir el formato ^[A-Z0-9-]{3,32}$; se recibió «%s».',
                $code
            ));
        }

        $cleanName = trim($name);
        $nameLength = mb_strlen($cleanName, 'UTF-8');
        if ($nameLength < self::MIN_NAME_LENGTH || $nameLength > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El nombre de la solución estandarizada debe contener entre %d y %d caracteres reales; se recibieron %d.',
                self::MIN_NAME_LENGTH,
                self::MAX_NAME_LENGTH,
                $nameLength
            ));
        }

        // Espejo exacto de `chk_standard_solutions_default_active`: la marca de
        // predeterminada no sobrevive a la baja lógica de su solución.
        if ($isDefault && !$isActive) {
            throw new InvalidArgumentException(
                'Una solución estandarizada inactiva no puede conservar la marca de predeterminada.'
            );
        }

        $this->id = $id;
        $this->rootCauseId = $rootCauseId;
        $this->code = $cleanCode;
        $this->name = $cleanName;
        $this->isDefault = $isDefault;
        $this->isActive = $isActive;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRootCauseId(): int
    {
        return $this->rootCauseId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function withId(int $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'root_cause_id' => $this->rootCauseId,
            'code' => $this->code,
            'name' => $this->name,
            'is_default' => $this->isDefault,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
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
