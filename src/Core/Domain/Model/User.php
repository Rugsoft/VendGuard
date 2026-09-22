<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use ArrayAccess;
use JsonSerializable;

/**
 * User
 * 
 * Entidad de Dominio que representa al personal interno del sistema (Coordinador o Técnico de Ruta).
 * Encapsula la verificación de contraseñas Bcrypt y oculta hashes en serializaciones.
 * 
 * Implementa ArrayAccess y JsonSerializable para compatibilidad fluida.
 */
class User implements ArrayAccess, JsonSerializable
{
    private int $id;
    private string $name;
    private string $email;
    private string $passwordHash;
    private UserRole $role;
    private ?string $phone;
    private bool $isActive;
    private ?string $createdAt;
    private ?string $updatedAt;
    private ?string $deletedAt;

    public function __construct(
        int $id,
        string $name,
        string $email,
        string $passwordHash,
        UserRole $role,
        ?string $phone = null,
        bool $isActive = true,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?string $deletedAt = null
    ) {
        $this->id = $id;
        $this->name = trim($name);
        $this->email = strtolower(trim($email));
        $this->passwordHash = $passwordHash;
        $this->role = $role;
        $this->phone = $phone !== null ? trim($phone) : null;
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
        $role = UserRole::fromString((string)$row['role']);

        return new self(
            (int)$row['id'],
            (string)$row['name'],
            (string)$row['email'],
            (string)$row['password_hash'],
            $role,
            isset($row['phone']) && $row['phone'] !== null ? (string)$row['phone'] : null,
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function isActive(): bool
    {
        return $this->isActive && $this->deletedAt === null;
    }

    public function isSoftDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function isCoordinator(): bool
    {
        return $this->role->isCoordinator();
    }

    public function isTechnician(): bool
    {
        return $this->role->isTechnician();
    }

    /**
     * Verifica de forma segura si una contraseña en texto plano coincide con el hash Bcrypt almacenado.
     *
     * @param string $plainPassword
     * @return bool
     */
    public function verifyPassword(string $plainPassword): bool
    {
        return password_verify($plainPassword, $this->passwordHash);
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
     * Convierte la entidad a array estructurado.
     * Por defecto excluye la contraseña cifrada por seguridad (Defense-in-depth).
     *
     * @param bool $includeHash
     * @return array<string, mixed>
     */
    public function toArray(bool $includeHash = false): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'phone' => $this->phone,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
        ];

        if ($includeHash) {
            $data['password_hash'] = $this->passwordHash;
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray(false);
    }

    // -------------------------------------------------------------
    // Implementación de ArrayAccess
    // -------------------------------------------------------------

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string)$offset, $this->toArray(true));
    }

    public function offsetGet(mixed $offset): mixed
    {
        $data = $this->toArray(true);
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
