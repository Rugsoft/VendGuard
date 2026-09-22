<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use ArrayAccess;
use JsonSerializable;

/**
 * Location
 * 
 * Entidad de Dominio que representa una sede cliente de VendGuard.
 * Almacena el código alfanumérico único (site_code), datos de contacto y estado.
 * 
 * Implementa ArrayAccess y JsonSerializable para compatibilidad bidireccional.
 */
class Location implements ArrayAccess, JsonSerializable
{
    private int $id;
    private string $siteCode;
    private string $name;
    private string $address;
    private ?string $contactName;
    private ?string $contactPhone;
    private bool $isActive;
    private ?string $createdAt;
    private ?string $updatedAt;
    private ?string $deletedAt;

    public function __construct(
        int $id,
        string $siteCode,
        string $name,
        string $address,
        ?string $contactName = null,
        ?string $contactPhone = null,
        bool $isActive = true,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?string $deletedAt = null
    ) {
        $this->id = $id;
        $this->siteCode = strtoupper(trim($siteCode));
        $this->name = trim($name);
        $this->address = trim($address);
        $this->contactName = $contactName !== null ? trim($contactName) : null;
        $this->contactPhone = $contactPhone !== null ? trim($contactPhone) : null;
        $this->isActive = $isActive;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->deletedAt = $deletedAt;
    }

    /**
     * Factoría para reconstruir la entidad desde una fila asociativa de base de datos.
     *
     * @param array<string, mixed> $row
     * @return self
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            (int)$row['id'],
            (string)$row['site_code'],
            (string)$row['name'],
            (string)$row['address'],
            isset($row['contact_name']) && $row['contact_name'] !== null ? (string)$row['contact_name'] : null,
            isset($row['contact_phone']) && $row['contact_phone'] !== null ? (string)$row['contact_phone'] : null,
            (bool)($row['is_active'] ?? 1),
            isset($row['created_at']) ? (string)$row['created_at'] : null,
            isset($row['updated_at']) ? (string)$row['updated_at'] : null,
            isset($row['deleted_at']) && $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getSiteCode(): string
    {
        return $this->siteCode;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function isActive(): bool
    {
        return $this->isActive && $this->deletedAt === null;
    }

    public function isSoftDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?string
    {
        return $this->deletedAt;
    }

    /**
     * Convierte la entidad a un array estructurado (snake_case para contratos API).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'site_code' => $this->siteCode,
            'name' => $this->name,
            'address' => $this->address,
            'contact_name' => $this->contactName,
            'contact_phone' => $this->contactPhone,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // -------------------------------------------------------------
    // Implementación de ArrayAccess para soporte bidireccional
    // -------------------------------------------------------------

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string)$offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        $data = $this->toArray();
        return $data[(string)$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Entidad inmutable vía array access
    }

    public function offsetUnset(mixed $offset): void
    {
        // Entidad inmutable vía array access
    }
}
