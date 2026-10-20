<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use ArrayAccess;
use InvalidArgumentException;
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
    private float $latitude;
    private float $longitude;
    private ?string $contactName;
    private ?string $contactPhone;
    private string $contactEmail;
    private bool $isActive;
    private ?string $createdAt;
    private ?string $updatedAt;
    private ?string $deletedAt;
    private bool $hasPhysicalReception;

    /** Código postal de la sede: llave de la herencia territorial (Módulo 17, RF-03.1). */
    private ?string $postalCode;

    /** Zona operativa efectiva: heredada por CP o forzada por el coordinador (RF-03.2). */
    private ?int $zoneId;

    /** True cuando la zona fue fijada a mano y no debe recalcularse con el CP. */
    private bool $isManualOverride;

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
        ?string $deletedAt = null,
        float $latitude = 41.3850640,
        float $longitude = 2.1734035,
        bool $hasPhysicalReception = true,
        string $contactEmail = 'recepcion@sede.vendguard.es',
        ?string $postalCode = null,
        ?int $zoneId = null,
        bool $isManualOverride = false
    ) {
        $this->id = $id;
        $this->siteCode = strtoupper(trim($siteCode));
        $this->name = trim($name);
        $this->address = trim($address);
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        if (!$this->hasValidCoordinates()) {
            throw new InvalidArgumentException('Las coordenadas de la sede deben estar dentro del territorio operativo.');
        }
        $this->contactName = $contactName !== null ? trim($contactName) : null;
        $this->contactPhone = $contactPhone !== null ? trim($contactPhone) : null;
        $this->contactEmail = trim($contactEmail) !== '' ? trim($contactEmail) : 'recepcion@sede.vendguard.es';
        $this->isActive = $isActive;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->deletedAt = $deletedAt;
        $this->hasPhysicalReception = $hasPhysicalReception;

        // El CP vacío es ausencia de dato, no una cadena vacía: las sedes
        // históricas no tienen CP y la migración nunca lo inventa (QA 7).
        $normalizedPostalCode = $postalCode !== null ? trim($postalCode) : null;
        $this->postalCode = $normalizedPostalCode === '' ? null : $normalizedPostalCode;
        $this->zoneId = $zoneId !== null && $zoneId > 0 ? $zoneId : null;
        $this->isManualOverride = $isManualOverride;
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
            isset($row['deleted_at']) && $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
            isset($row['latitude']) ? (float)$row['latitude'] : 41.3850640,
            isset($row['longitude']) ? (float)$row['longitude'] : 2.1734035,
            (bool)($row['has_physical_reception'] ?? true),
            isset($row['contact_email']) && trim((string)$row['contact_email']) !== '' ? (string)$row['contact_email'] : 'recepcion@sede.vendguard.es',
            isset($row['postal_code']) && $row['postal_code'] !== null ? (string)$row['postal_code'] : null,
            isset($row['zone_id']) && $row['zone_id'] !== null ? (int)$row['zone_id'] : null,
            self::toBoolean($row['is_manual_override'] ?? false)
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

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function hasValidCoordinates(): bool
    {
        return is_finite($this->latitude)
            && is_finite($this->longitude)
            && $this->latitude >= 27.0
            && $this->latitude <= 44.5
            && $this->longitude >= -18.5
            && $this->longitude <= 5.0
            && !($this->latitude === 0.0 && $this->longitude === 0.0);
    }

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function getContactEmail(): string
    {
        return $this->contactEmail;
    }

    public function hasPhysicalReception(): bool
    {
        return $this->hasPhysicalReception;
    }

    /**
     * Código postal de la sede, o null si es una sede histórica sin demarcación.
     */
    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    /**
     * Zona operativa efectiva de la sede, o null si está en «Sin Zona Asignada».
     */
    public function getZoneId(): ?int
    {
        return $this->zoneId;
    }

    /**
     * ¿La zona fue fijada manualmente? Si es true, cambiar el CP no la recoloca (RF-03.3).
     */
    public function isManualOverride(): bool
    {
        return $this->isManualOverride;
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
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'contact_name' => $this->contactName,
            'contact_phone' => $this->contactPhone,
            'contact_email' => $this->contactEmail,
            'has_physical_reception' => $this->hasPhysicalReception,
            'postal_code' => $this->postalCode,
            'zone_id' => $this->zoneId,
            'is_manual_override' => $this->isManualOverride,
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

    /**
     * Booleano del motor, sin suponer la forma que devuelve el driver (`t`/`f`, `1`/`0`).
     */
    private static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
