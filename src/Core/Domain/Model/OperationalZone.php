<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use JsonSerializable;
use VendGuard\Core\Domain\Exception\ZoneValidationException;

/**
 * OperationalZone
 *
 * Entidad inmutable de una zona operativa: la demarcación territorial con la que
 * el coordinador organiza el parque de sedes (RF-01.1, Módulo 17).
 *
 * Una zona **nunca se borra**: se desactiva (`is_active = false`) y conserva su
 * historial, porque el borrado físico de entidades maestras está prohibido por el
 * Art. III.1. Por eso el estado es un atributo de la entidad y no la existencia
 * de la fila.
 *
 * Invariantes que se validan al construir la entidad, no al persistirla:
 *  - El código se normaliza a mayúsculas y tiene al menos tres caracteres. Es el
 *    identificador legible con el que el coordinador nombra la demarcación.
 *  - El nombre tiene al menos tres caracteres; un nombre vacío dejaría la zona
 *    sin etiqueta en el mapa y en los desplegables.
 *  - El color es un hexadecimal `#RRGGBB`, que es el token con el que el mapa
 *    territorial pinta la zona. Se normaliza a minúsculas para que dos formas de
 *    escribir el mismo color no produzcan dos tokens distintos.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class OperationalZone implements JsonSerializable
{
    /** Color por defecto del sistema de diseño (azul eléctrico Docker). */
    public const DEFAULT_COLOR_HEX = '#2560ff';

    /** Longitud mínima del código identificativo (RF-01.1). */
    public const MIN_CODE_LENGTH = 3;

    /** Longitud mínima del nombre representativo (RF-01.1). */
    public const MIN_NAME_LENGTH = 3;

    /** Forma canónica del color: `#RRGGBB`, ya normalizado a minúsculas. */
    public const COLOR_PATTERN = '/^#[0-9a-f]{6}$/';

    private readonly ?int $id;
    private readonly string $code;
    private readonly string $name;
    private readonly string $colorHex;
    private readonly ?string $description;
    private readonly bool $isActive;
    private readonly ?string $createdAt;
    private readonly ?string $updatedAt;

    /**
     * @param int|null $id Identificador de fila; `null` antes de persistir.
     * @param string $code Código alfanumérico único (ej. `ZONA-NORTE-BCN`).
     * @param string $name Nombre representativo (mínimo 3 caracteres).
     * @param string $colorHex Color corporativo `#RRGGBB`.
     * @param string|null $description Descripción funcional opcional.
     * @param bool $isActive Estado operativo; la baja es lógica (Art. III.1).
     * @param string|null $createdAt Marca de creación tal y como la da el motor.
     * @param string|null $updatedAt Marca de última modificación.
     *
     * @throws ZoneValidationException Si el código, el nombre, el color o el id no cumplen el contrato.
     */
    public function __construct(
        ?int $id,
        string $code,
        string $name,
        string $colorHex = self::DEFAULT_COLOR_HEX,
        ?string $description = null,
        bool $isActive = true,
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        $normalizedCode = mb_strtoupper(trim($code));
        if (mb_strlen($normalizedCode) < self::MIN_CODE_LENGTH) {
            throw ZoneValidationException::forField(
                'code',
                sprintf('El código de zona debe contener al menos %d caracteres.', self::MIN_CODE_LENGTH)
            );
        }

        $normalizedName = trim($name);
        if (mb_strlen($normalizedName) < self::MIN_NAME_LENGTH) {
            throw ZoneValidationException::forField(
                'name',
                sprintf('El nombre de la zona debe contener al menos %d caracteres.', self::MIN_NAME_LENGTH)
            );
        }

        $normalizedColor = strtolower(trim($colorHex));
        if (preg_match(self::COLOR_PATTERN, $normalizedColor) !== 1) {
            throw ZoneValidationException::forField(
                'color_hex',
                'El color de la zona debe tener el formato hexadecimal #RRGGBB (ej. #2560ff).'
            );
        }

        if ($id !== null && $id <= 0) {
            throw ZoneValidationException::forField(
                'id',
                'El identificador de la zona debe ser un entero positivo.'
            );
        }

        $normalizedDescription = $description !== null ? trim($description) : null;

        // Las propiedades son `readonly`: este es el único punto donde se escriben,
        // y lo hacen ya normalizadas. No hay setters por diseño (inmutabilidad).
        $this->id = $id;
        $this->code = $normalizedCode;
        $this->name = $normalizedName;
        $this->colorHex = $normalizedColor;
        $this->description = $normalizedDescription === '' ? null : $normalizedDescription;
        $this->isActive = $isActive;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Reconstruye la entidad desde una fila asociativa del motor.
     *
     * El `is_active` de PostgreSQL puede llegar como booleano nativo o como la
     * cadena que devuelve alguna configuración de PDO (`t`/`f`/`1`/`0`); se acepta
     * cualquiera de las dos formas porque la entidad no debe depender del driver.
     *
     * @param array<string, mixed> $row
     *
     * @throws ZoneValidationException Si la fila no contiene un código, nombre o color válidos.
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int)$row['id'] : null,
            (string)($row['code'] ?? ''),
            (string)($row['name'] ?? ''),
            (string)($row['color_hex'] ?? self::DEFAULT_COLOR_HEX),
            isset($row['description']) && $row['description'] !== null ? (string)$row['description'] : null,
            self::toBoolean($row['is_active'] ?? true),
            isset($row['created_at']) ? (string)$row['created_at'] : null,
            isset($row['updated_at']) ? (string)$row['updated_at'] : null
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getColorHex(): string
    {
        return $this->colorHex;
    }

    public function getDescription(): ?string
    {
        return $this->description;
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

    /**
     * Proyección del contrato de la API (snake_case), sin contadores derivados:
     * las agregaciones de sedes, técnicos y CPs las añade la capa de aplicación.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'color_hex' => $this->colorHex,
            'description' => $this->description,
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
