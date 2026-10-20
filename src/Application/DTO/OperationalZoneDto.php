<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\Model\OperationalZone;

/**
 * OperationalZoneDto
 *
 * Proyección de lectura de una zona operativa tal y como la pinta el panel de
 * Coordinación (RF-01.1, contrato `GET /api/coordinator/zones`).
 *
 * La entidad `OperationalZone` no conoce sus contadores: cuántas sedes, titulares,
 * suplentes y códigos postales cuelgan de ella lo resuelve el repositorio en una
 * sola sentencia (RNF-01). Este DTO es el punto donde ambas mitades se juntan, para
 * que el controlador no componga la respuesta a mano y el frontend no dependa de
 * la forma interna de la entidad.
 *
 * `postal_codes_count` y `postal_codes` describen el mismo universo: los códigos
 * **vivos**. Un CP liberado pertenece al historial y no aparece en la ficha de la
 * zona (QA 13).
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class OperationalZoneDto implements JsonSerializable
{
    /**
     * @param int $id Identificador de la zona.
     * @param string $code Código identificativo normalizado en mayúsculas.
     * @param string $name Nombre representativo.
     * @param string $colorHex Color corporativo `#RRGGBB`.
     * @param string|null $description Descripción funcional opcional.
     * @param bool $isActive Estado operativo; la baja es lógica (Art. III.1).
     * @param int $locationsCount Sedes cuya zona efectiva es esta.
     * @param int $primaryTechniciansCount Titulares vivos del cuadrante.
     * @param int $backupTechniciansCount Suplentes vivos del cuadrante.
     * @param int $postalCodesCount Códigos postales vivos gobernados por la zona.
     * @param list<string> $postalCodes Códigos postales vivos, ordenados.
     * @param string|null $createdAt Marca de creación tal y como la da el motor.
     * @param string|null $updatedAt Marca de última modificación.
     *
     * @throws InvalidArgumentException Si algún contador es negativo.
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $colorHex,
        public ?string $description,
        public bool $isActive,
        public int $locationsCount,
        public int $primaryTechniciansCount,
        public int $backupTechniciansCount,
        public int $postalCodesCount,
        public array $postalCodes,
        public ?string $createdAt,
        public ?string $updatedAt
    ) {
        foreach ([
            'locations_count' => $locationsCount,
            'primary_technicians_count' => $primaryTechniciansCount,
            'backup_technicians_count' => $backupTechniciansCount,
            'postal_codes_count' => $postalCodesCount,
        ] as $field => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(
                    sprintf('El contador %s de la zona %d no puede ser negativo.', $field, $id)
                );
            }
        }
    }

    /**
     * Compone la proyección desde la fila agregada del repositorio y sus CP vivos.
     *
     * @param array{
     *     zone: OperationalZone,
     *     locations_count: int,
     *     primary_technicians_count: int,
     *     backup_technicians_count: int,
     *     postal_codes_count: int
     * } $row
     * @param list<string> $postalCodes
     */
    public static function fromRow(array $row, array $postalCodes): self
    {
        $zone = $row['zone'];

        return new self(
            (int)$zone->getId(),
            $zone->getCode(),
            $zone->getName(),
            $zone->getColorHex(),
            $zone->getDescription(),
            $zone->isActive(),
            (int)$row['locations_count'],
            (int)$row['primary_technicians_count'],
            (int)$row['backup_technicians_count'],
            (int)$row['postal_codes_count'],
            array_values($postalCodes),
            $zone->getCreatedAt(),
            $zone->getUpdatedAt()
        );
    }

    /**
     * Proyección desde la entidad, para operaciones que no necesitan contadores
     * agregados (alta, edición o desactivación de la propia zona).
     *
     * @param list<string> $postalCodes
     */
    public static function fromZone(OperationalZone $zone, array $postalCodes = []): self
    {
        return new self(
            (int)$zone->getId(),
            $zone->getCode(),
            $zone->getName(),
            $zone->getColorHex(),
            $zone->getDescription(),
            $zone->isActive(),
            0,
            0,
            0,
            count($postalCodes),
            array_values($postalCodes),
            $zone->getCreatedAt(),
            $zone->getUpdatedAt()
        );
    }

    /**
     * Forma del contrato REST (snake_case).
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
            'locations_count' => $this->locationsCount,
            'primary_technicians_count' => $this->primaryTechniciansCount,
            'backup_technicians_count' => $this->backupTechniciansCount,
            'postal_codes_count' => $this->postalCodesCount,
            'postal_codes' => $this->postalCodes,
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
