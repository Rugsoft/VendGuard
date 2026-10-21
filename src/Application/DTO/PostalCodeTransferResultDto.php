<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use JsonSerializable;

/**
 * PostalCodeTransferResultDto
 *
 * Balance de la asignación de un lote de códigos postales a una zona operativa
 * (RF-01.3, contrato `POST /api/coordinator/zones/{id}/postal-codes`).
 *
 * Distingue tres hechos que el modal del coordinador necesita separar:
 *
 *  - `assigned_postal_codes`: el lote tal y como quedó gobernado por la zona tras
 *    la operación. Incluye los CP que ya estaban en ella, porque el resultado es
 *    el estado final, no un delta.
 *  - `transferred_postal_codes`: solo los CP que **cambiaron** de demarcación, con
 *    la zona de la que salieron. Es lo que se anuncia en el aviso de sedes
 *    afectadas.
 *  - `released_postal_codes`: códigos que la zona gobernaba y ya **no** forman
 *    parte de la colección enviada. Se liberan lógicamente (`released_at`) en vez
 *    de borrarse, y sus sedes heredadas recalculan su zona (RF-01.4, hallazgo H-1).
 *  - `affected_locations_count`: sedes heredadas cuya zona se recalculó, ya sea
 *    por un traslado o por una liberación. Las forzadas a mano no cuentan porque
 *    conservan su zona (RF-03.3).
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class PostalCodeTransferResultDto implements JsonSerializable
{
    /**
     * @param int $zoneId Zona de destino del lote.
     * @param list<string> $assignedPostalCodes Códigos vivos de la zona tras la operación.
     * @param list<array{postal_code: string, previous_zone_id: int, previous_zone_name: string}> $transferredPostalCodes
     *   Códigos que cambiaron de zona, con su demarcación de origen.
     * @param int $affectedLocationsCount Sedes heredadas cuya zona se recalculó.
     * @param list<string> $releasedPostalCodes Códigos que la zona dejó de gobernar.
     *
     * @throws InvalidArgumentException Si el identificador de zona no es positivo, si
     *                                  algún CP no tiene cinco dígitos o si el recuento
     *                                  de sedes es negativo.
     */
    public function __construct(
        public int $zoneId,
        public array $assignedPostalCodes,
        public array $transferredPostalCodes,
        public int $affectedLocationsCount,
        public array $releasedPostalCodes = []
    ) {
        if ($zoneId <= 0) {
            throw new InvalidArgumentException('El balance de códigos postales exige una zona de destino válida.');
        }

        if ($affectedLocationsCount < 0) {
            throw new InvalidArgumentException('El número de sedes afectadas no puede ser negativo.');
        }

        foreach ([
            ...$assignedPostalCodes,
            ...array_column($transferredPostalCodes, 'postal_code'),
            ...$releasedPostalCodes,
        ] as $postalCode) {
            if (preg_match('/^[0-9]{5}$/', (string)$postalCode) !== 1) {
                throw new InvalidArgumentException(
                    sprintf("El código postal '%s' del balance no tiene cinco dígitos.", (string)$postalCode)
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'zone_id' => $this->zoneId,
            'assigned_postal_codes' => $this->assignedPostalCodes,
            'transferred_postal_codes' => $this->transferredPostalCodes,
            'released_postal_codes' => $this->releasedPostalCodes,
            'affected_locations_count' => $this->affectedLocationsCount,
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
