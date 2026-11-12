<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use JsonSerializable;
use VendGuard\Core\Domain\Exception\ZoneValidationException;

/**
 * RestockerZoneAssignment
 *
 * Vinculación de carga de un reponedor con una zona operativa (Tabla 41,
 * `restocker_zone_assignments`, Módulo 31).
 *
 * Es una entidad **distinta** de `technician_zone_assignments`: el cuadrante SAT y
 * el cuadrante de carga conviven en la misma zona sin interferirse (D3). Una
 * `PRIMARY` es la titular de la zona y una `BACKUP` su suplente.
 *
 * Invariantes que se validan al construir la entidad, no al persistirla:
 *  - `role_in_zone` solo admite `PRIMARY` o `BACKUP`, y `is_primary` es su forma
 *    booleana: si se declara explícitamente y contradice al rol, la fila sería
 *    imposible en la base de datos (`chk_restocker_zone_role_coherence`) y aquí
 *    falla en voz alta antes de intentarlo (QA 1).
 *  - La baja es **lógica** (`removedAt`) y nunca anterior al alta: la línea de
 *    tiempo del cuadrante debe ser legible (`chk_restocker_zone_removed_order`).
 *  - El identificador de reponedor y el de zona son positivos: una vinculación sin
 *    extremos no describe nada.
 *
 * La entidad no decide la titularidad única: esa regla es de la base de datos
 * (`uq_restocker_primary_zone`) porque solo el motor puede ver las demás filas
 * vivas del mismo reponedor.
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades readonly y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockerZoneAssignment implements JsonSerializable
{
    /** Rol de titular de la zona (una sola zona viva por reponedor). */
    public const ROLE_PRIMARY = 'PRIMARY';

    /** Rol de suplente (sin límite de zonas vivas). */
    public const ROLE_BACKUP = 'BACKUP';

    /** Catálogo cerrado de `role_in_zone`. */
    public const ROLES = [self::ROLE_PRIMARY, self::ROLE_BACKUP];

    private readonly ?int $id;
    private readonly int $restockerId;
    private readonly int $zoneId;
    private readonly string $roleInZone;
    private readonly bool $isPrimary;
    private readonly DateTimeImmutable $assignedAt;
    private readonly ?DateTimeImmutable $removedAt;

    /**
     * @param int|null $id Identificador de fila; `null` antes de persistir.
     * @param int $restockerId Usuario `RESTOCKER` vinculado.
     * @param int $zoneId Zona operativa del cuadrante de carga.
     * @param string $roleInZone `PRIMARY` o `BACKUP`.
     * @param bool|null $isPrimary Forma booleana del rol; si es `null` se deriva.
     * @param DateTimeImmutable|null $assignedAt Alta en la zona; por defecto, ahora.
     * @param DateTimeImmutable|null $removedAt Baja lógica, o `null` si sigue viva.
     *
     * @throws ZoneValidationException Si el rol, la coherencia, los extremos o el
     *                                 orden temporal no cumplen el contrato.
     */
    public function __construct(
        ?int $id,
        int $restockerId,
        int $zoneId,
        string $roleInZone,
        ?bool $isPrimary = null,
        ?DateTimeImmutable $assignedAt = null,
        ?DateTimeImmutable $removedAt = null
    ) {
        if ($id !== null && $id <= 0) {
            throw ZoneValidationException::forField(
                'id',
                'El identificador de la vinculación de carga debe ser un entero positivo.'
            );
        }

        if ($restockerId <= 0) {
            throw ZoneValidationException::forField(
                'restocker_id',
                'La vinculación de carga debe referenciar un reponedor existente.'
            );
        }

        if ($zoneId <= 0) {
            throw ZoneValidationException::forField(
                'zone_id',
                'La vinculación de carga debe referenciar una zona operativa existente.'
            );
        }

        $normalizedRole = mb_strtoupper(trim($roleInZone));
        if (!in_array($normalizedRole, self::ROLES, true)) {
            throw ZoneValidationException::forField(
                'role_in_zone',
                sprintf(
                    "El rol en la zona debe ser '%s' o '%s'.",
                    self::ROLE_PRIMARY,
                    self::ROLE_BACKUP
                )
            );
        }

        $derivedPrimary = $normalizedRole === self::ROLE_PRIMARY;
        if ($isPrimary !== null && $isPrimary !== $derivedPrimary) {
            throw ZoneValidationException::forField(
                'is_primary',
                sprintf(
                    "El rol '%s' exige is_primary = %s; la pareja declarada se contradice.",
                    $normalizedRole,
                    $derivedPrimary ? 'TRUE' : 'FALSE'
                )
            );
        }

        $effectiveAssignedAt = $assignedAt ?? new DateTimeImmutable('now');

        if ($removedAt !== null && $removedAt < $effectiveAssignedAt) {
            throw ZoneValidationException::forField(
                'removed_at',
                'La baja de la vinculación de carga no puede ser anterior a su alta.'
            );
        }

        $this->id = $id;
        $this->restockerId = $restockerId;
        $this->zoneId = $zoneId;
        $this->roleInZone = $normalizedRole;
        $this->isPrimary = $derivedPrimary;
        $this->assignedAt = $effectiveAssignedAt;
        $this->removedAt = $removedAt;
    }

    /**
     * Reconstruye la entidad desde una fila del motor.
     *
     * El booleano puede llegar nativo o como `t`/`f`/`1`/`0` según el driver; la
     * entidad no depende de esa forma.
     *
     * @param array<string, mixed> $row
     *
     * @throws ZoneValidationException Si la fila no describe una vinculación válida.
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int)$row['id'] : null,
            (int)($row['restocker_id'] ?? 0),
            (int)($row['zone_id'] ?? 0),
            (string)($row['role_in_zone'] ?? ''),
            array_key_exists('is_primary', $row) && $row['is_primary'] !== null
                ? self::toBoolean($row['is_primary'])
                : null,
            isset($row['assigned_at']) ? new DateTimeImmutable((string)$row['assigned_at']) : null,
            isset($row['removed_at']) && $row['removed_at'] !== null
                ? new DateTimeImmutable((string)$row['removed_at'])
                : null
        );
    }

    /**
     * Baja lógica: devuelve la misma vinculación marcada como extinta (Art. III.1).
     *
     * @throws ZoneValidationException Si ya estaba extinta.
     */
    public function remove(?DateTimeImmutable $removedAt = null): self
    {
        if (!$this->isLive()) {
            throw ZoneValidationException::forField(
                'removed_at',
                'La vinculación de carga ya está extinta: la baja lógica no se repite.'
            );
        }

        return new self(
            $this->id,
            $this->restockerId,
            $this->zoneId,
            $this->roleInZone,
            $this->isPrimary,
            $this->assignedAt,
            $removedAt ?? new DateTimeImmutable('now')
        );
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function isBackup(): bool
    {
        return !$this->isPrimary;
    }

    public function isLive(): bool
    {
        return $this->removedAt === null;
    }

    /**
     * Etiqueta legible del rol en la zona.
     */
    public function roleLabel(): string
    {
        return $this->isPrimary ? 'Titular' : 'Suplente';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRestockerId(): int
    {
        return $this->restockerId;
    }

    public function getZoneId(): int
    {
        return $this->zoneId;
    }

    public function getRoleInZone(): string
    {
        return $this->roleInZone;
    }

    public function getAssignedAt(): DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function getRemovedAt(): ?DateTimeImmutable
    {
        return $this->removedAt;
    }

    /**
     * Proyección del contrato de la API para una fila del cuadrante de carga.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'restocker_id' => $this->restockerId,
            'zone_id' => $this->zoneId,
            'role_in_zone' => $this->roleInZone,
            'role_label' => $this->roleLabel(),
            'is_primary' => $this->isPrimary,
            'assigned_at' => $this->assignedAt->format(DateTimeImmutable::ATOM),
            'removed_at' => $this->removedAt?->format(DateTimeImmutable::ATOM),
            'is_live' => $this->isLive(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Booleano del motor, sin suponer la forma que devuelve el driver.
     */
    private static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
