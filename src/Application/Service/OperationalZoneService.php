<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Application\DTO\OperationalZoneDto;
use VendGuard\Application\DTO\PostalCodeTransferResultDto;
use VendGuard\Application\DTO\TechnicianQuadrantDto;
use VendGuard\Application\DTO\ZoneTechnicianAssignmentDto;
use VendGuard\Core\Domain\Exception\LocationNotFoundException;
use VendGuard\Core\Domain\Exception\PostalCodeConflictException;
use VendGuard\Core\Domain\Exception\PrimaryTechnicianConflictException;
use VendGuard\Core\Domain\Exception\ZoneNotFoundException;
use VendGuard\Core\Domain\Exception\ZoneValidationException;
use VendGuard\Core\Domain\Model\OperationalZone;
use VendGuard\Core\Domain\Repository\LocationZoneRepositoryInterface;
use VendGuard\Core\Domain\Repository\OperationalZoneRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\PostalCode;

/**
 * OperationalZoneService
 *
 * Caso de uso del catálogo territorial del Módulo 17: ciclo de vida de las zonas,
 * administración de sus códigos postales, cuadrante técnico y excepciones manuales
 * de sede (RF-01.1 a RF-03.3).
 *
 * El servicio **no escribe SQL**: orquesta el puerto de persistencia y decide qué
 * exige confirmación humana, dejando la atomicidad donde debe estar —en la
 * transacción del repositorio— para que una decisión de negocio a medias nunca
 * quede a medio persistir (RNF-04).
 *
 * Reglas de confirmación que vive aquí y en ningún otro sitio:
 *
 *  - **Traslado de CP (`confirm_transfers`).** Un CP pertenece a una única zona
 *    viva, así que moverlo arrastra sedes. Si el lote pisa otra zona se responde
 *    `409 POSTAL_CODES_REQUIRE_CONFIRMATION` con el detalle que pinta el modal; solo
 *    la confirmación expresa ejecuta el movimiento atómico (RF-01.3).
 *  - **Titularidad única (`confirm_primary_override`).** Sin confirmación, la
 *    segunda titularidad de un técnico se rechaza con
 *    `409 TECHNICIAN_ALREADY_PRIMARY_IN_OTHER_ZONE`; al confirmarla, la anterior se
 *    degrada a suplencia y queda auditada con `TECHNICIAN_ZONE_PRIMARY_DEMOTED`
 *    (RF-02.2, QA 9).
 *  - **Liberación de territorio (`confirm_release`).** Desactivar una zona con CP,
 *    sedes o cuadrante exige confirmación expresa y, en la misma transacción, deja
 *    las sedes en «Sin Zona Asignada», libera los CP con `released_at` y extingue
 *    las vinculaciones con `removed_at` (RF-01.3, QA 7, QA 10, QA 13).
 *
 * El alta de un lote mezcla dos caminos porque son dos hechos distintos: los CP
 * **libres** se dan de alta (`assignPostalCodes`) y los que ya viven en otra zona
 * se **trasladan** (`transferPostalCodes`). El servicio parte el lote y no deja que
 * ninguno de los dos caminos decida por el otro (RNF-04).
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
class OperationalZoneService
{
    private OperationalZoneRepositoryInterface $zoneRepo;

    private LocationZoneRepositoryInterface $locationRepo;

    public function __construct(
        OperationalZoneRepositoryInterface $zoneRepo,
        LocationZoneRepositoryInterface $locationRepo
    ) {
        $this->zoneRepo = $zoneRepo;
        $this->locationRepo = $locationRepo;
    }

    /**
     * Catálogo de zonas con sus contadores agregados (RF-01.1, RNF-01).
     *
     * @param bool $includeInactive Si es false, solo devuelve zonas activas.
     * @return list<OperationalZoneDto>
     */
    public function listZones(bool $includeInactive = true): array
    {
        $zones = [];

        foreach ($this->zoneRepo->findAllWithCounts($includeInactive) as $row) {
            $zones[] = OperationalZoneDto::fromRow(
                $row,
                $this->zoneRepo->findPostalCodes((int)$row['zone']->getId())
            );
        }

        return $zones;
    }

    /**
     * Sedes activas sin demarcación, que el panel muestra como trabajo pendiente.
     *
     * @return int Número de sedes en «Sin Zona Asignada».
     */
    public function countUnassignedLocations(): int
    {
        return $this->zoneRepo->countUnassignedLocations();
    }

    /**
     * Ficha completa de una zona, activa o desactivada (RF-01.1).
     *
     * @throws ZoneNotFoundException Si la zona no existe.
     */
    public function getZone(int $zoneId): OperationalZoneDto
    {
        return $this->toDto($this->requireZone($zoneId));
    }

    /**
     * Cuadrante vivo de una zona, con la identidad de cada vinculación (RF-02.3, QA 15).
     *
     * Se lee **bajo demanda**: el listado del catálogo sigue publicando contadores y no
     * paga una consulta por zona (RNF-01). Una zona desactivada responde con su cuadrante
     * real —normalmente vacío, porque la desactivación lo extingue— y una inexistente con
     * `ZONE_NOT_FOUND`.
     *
     * @param int $zoneId Identificador de la zona.
     * @return array{zone_id: int, technicians: list<array<string, mixed>>}
     *
     * @throws ZoneNotFoundException Si la zona no existe.
     */
    public function listZoneQuadrant(int $zoneId): array
    {
        $this->requireZone($zoneId);

        return [
            'zone_id' => $zoneId,
            'technicians' => array_map(
                static fn (array $row): array => ZoneTechnicianAssignmentDto::fromRow($row)->toArray(),
                $this->zoneRepo->findTechnicianAssignments($zoneId)
            ),
        ];
    }

    /**
     * Lectura inversa del cuadrante: técnico → zonas vivas (RF-02.3, QA 15).
     *
     * No decide nada: el repositorio agrupa en una sola sentencia y el servicio solo
     * proyecta el contrato. Los técnicos activos sin zona viajan con `zones: []` para que
     * el panel pueda señalarlos como trabajo pendiente.
     *
     * @return list<array<string, mixed>>
     */
    public function listTechnicianQuadrants(): array
    {
        return array_map(
            static fn (array $row): array => TechnicianQuadrantDto::fromRow($row)->toArray(),
            $this->zoneRepo->findQuadrantsByTechnician()
        );
    }

    /**
     * Alta de una zona operativa (RF-01.1).
     *
     * @param array<string, mixed> $data Claves: `code`, `name`, `color_hex`, `description`.
     * @param int $userId Coordinador que firma el alta.
     * @return OperationalZoneDto Zona persistida con sus contadores a cero.
     *
     * @throws ZoneValidationException Si algún atributo no cumple el contrato.
     */
    public function createZone(array $data, int $userId): OperationalZoneDto
    {
        $zone = $this->zoneRepo->create($data, $userId);

        return $this->toDto($zone);
    }

    /**
     * Edición de los metadatos y del estado operativo de una zona (RF-01.1).
     *
     * Desactivar no es un `UPDATE` más: delega en `deactivateZone()` para que la
     * liberación del territorio y su confirmación no puedan saltarse por esta vía.
     *
     * @param int $zoneId Identificador de la zona.
     * @param array<string, mixed> $data Claves admitidas: `name`, `color_hex`, `description`, `is_active`.
     * @param int $userId Coordinador que firma la edición.
     * @param bool $confirmRelease Confirmación expresa de liberar el territorio al desactivar.
     * @return OperationalZoneDto Zona resultante.
     *
     * @throws ZoneNotFoundException Si la zona no existe.
     * @throws ZoneValidationException Si los metadatos no son válidos o falta `confirmRelease`.
     */
    public function updateZone(int $zoneId, array $data, int $userId, bool $confirmRelease = false): OperationalZoneDto
    {
        $current = $this->requireZone($zoneId);
        $wantsInactive = array_key_exists('is_active', $data) && (bool)$data['is_active'] === false;

        if ($wantsInactive && $current->isActive()) {
            $metadata = array_intersect_key($data, array_flip(['name', 'color_hex', 'description']));
            if ($metadata !== []) {
                $this->zoneRepo->update($zoneId, $metadata, $userId);
            }

            return $this->deactivateZone($zoneId, $userId, $confirmRelease);
        }

        if (!$this->zoneRepo->update($zoneId, $data, $userId)) {
            throw new ZoneNotFoundException($zoneId);
        }

        return $this->toDto($this->requireZone($zoneId));
    }

    /**
     * Desactiva una zona liberando su territorio (RF-01.3, QA 7, QA 10, QA 13).
     *
     * Reactivar después **no** recupera códigos postales ni cuadrante: la
     * liberación es una decisión con consecuencias, no un interruptor.
     *
     * @param int $zoneId Identificador de la zona.
     * @param int $userId Coordinador que firma la desactivación.
     * @param bool $confirmRelease Confirmación expresa de liberar CP, sedes y cuadrante.
     * @return OperationalZoneDto Zona desactivada.
     *
     * @throws ZoneNotFoundException Si la zona no existe.
     * @throws ZoneValidationException Si conserva territorio y falta `confirmRelease`.
     */
    public function deactivateZone(int $zoneId, int $userId, bool $confirmRelease = false): OperationalZoneDto
    {
        $current = $this->requireZone($zoneId);

        if (!$current->isActive()) {
            return $this->toDto($current);
        }

        $impact = $this->zoneRepo->inspectReleaseImpact($zoneId);
        $total = $impact['postal_codes'] + $impact['technicians'] + $impact['locations'];

        if ($total > 0 && !$confirmRelease) {
            throw ZoneValidationException::unprocessable(
                sprintf(
                    'La zona «%s» conserva %d códigos postales, %d sedes y %d técnicos vinculados. '
                        . 'Desactivarla los libera en la misma operación; confirma con confirm_release.',
                    $current->getName(),
                    $impact['postal_codes'],
                    $impact['locations'],
                    $impact['technicians']
                ),
                ['confirm_release' => 'Se requiere confirmación para liberar el territorio de la zona.']
            );
        }

        $this->zoneRepo->deactivateZone($zoneId, $userId);

        return $this->toDto($this->requireZone($zoneId));
    }

    /**
     * Asigna un lote de códigos postales a una zona (RF-01.2, RF-01.3).
     *
     * Los CP libres se dan de alta; los que ya pertenecen a otra zona se trasladan
     * solo con confirmación expresa y arrastran sus sedes heredadas en la misma
     * transacción (QA 6).
     *
     * @param int $zoneId Zona activa de destino.
     * @param array<int, mixed> $postalCodes Lote de códigos postales.
     * @param int $userId Coordinador que firma la operación.
     * @param bool $confirmTransfers Confirmación expresa de mover CP de otras zonas.
     * @return PostalCodeTransferResultDto Balance de la operación.
     *
     * @throws ZoneNotFoundException Si la zona no existe.
     * @throws ZoneValidationException Si la zona está inactiva o un CP es inválido.
     * @throws PostalCodeConflictException Si hay CP de otras zonas y no se confirmó.
     */
    public function assignPostalCodes(
        int $zoneId,
        array $postalCodes,
        int $userId,
        bool $confirmTransfers = false
    ): PostalCodeTransferResultDto {
        $this->requireActiveZone($zoneId);

        $codes = self::normalizePostalCodes($postalCodes);
        if ($codes === []) {
            throw ZoneValidationException::forField(
                'postal_codes',
                'El lote debe contener al menos un código postal de cinco dígitos.'
            );
        }

        $conflicts = $this->zoneRepo->findPostalCodeConflicts($codes, $zoneId);

        if ($conflicts !== [] && !$confirmTransfers) {
            throw new PostalCodeConflictException($conflicts);
        }

        $transferredCodes = array_column($conflicts, 'postal_code');
        $freeCodes = array_values(array_diff($codes, $transferredCodes));

        $affectedLocations = 0;
        if ($transferredCodes !== []) {
            $affectedLocations = $this->zoneRepo->transferPostalCodes($zoneId, $transferredCodes, $userId);
        }

        if ($freeCodes !== []) {
            $this->zoneRepo->assignPostalCodes($zoneId, $freeCodes, $userId);
        }

        $transferred = array_map(static fn (array $conflict): array => [
            'postal_code' => $conflict['postal_code'],
            'previous_zone_id' => $conflict['current_zone_id'],
            'previous_zone_name' => $conflict['current_zone_name'],
        ], $conflicts);

        return new PostalCodeTransferResultDto($zoneId, $codes, $transferred, $affectedLocations);
    }

    /**
     * Vincula a un técnico con la zona como titular o suplente (RF-02.1, RF-02.2).
     *
     * @param int $technicianId Usuario con rol `TECHNICIAN` activo.
     * @param int $zoneId Zona activa de destino.
     * @param bool $isPrimary True para titular, false para suplente.
     * @param int $userId Coordinador que firma la vinculación.
     * @param bool $confirmPrimaryOverride Degrada la titularidad previa en lugar de rechazarla.
     * @return array<string, mixed> Vinculación resultante con los datos del técnico.
     *
     * @throws ZoneNotFoundException Si la zona no existe.
     * @throws ZoneValidationException Si la zona está inactiva o el usuario no es técnico activo.
     * @throws PrimaryTechnicianConflictException Si el técnico ya es titular de otra zona y no se confirmó.
     */
    public function assignTechnician(
        int $technicianId,
        int $zoneId,
        bool $isPrimary,
        int $userId,
        bool $confirmPrimaryOverride = false
    ): array {
        $this->requireActiveZone($zoneId);

        $assignmentId = $this->zoneRepo->assignTechnician(
            $technicianId,
            $zoneId,
            $isPrimary,
            $userId,
            $isPrimary && $confirmPrimaryOverride
        );

        foreach ($this->zoneRepo->findTechnicianAssignments($zoneId) as $assignment) {
            if ($assignment['technician_id'] === $technicianId) {
                return [
                    'assignment' => [
                        'id' => $assignmentId,
                        'technician_id' => $technicianId,
                        'technician_name' => $assignment['name'],
                        'zone_id' => $zoneId,
                        'is_primary' => $assignment['is_primary'],
                        'assigned_at' => $assignment['assigned_at'],
                    ],
                ];
            }
        }

        return [
            'assignment' => [
                'id' => $assignmentId,
                'technician_id' => $technicianId,
                'technician_name' => null,
                'zone_id' => $zoneId,
                'is_primary' => $isPrimary,
                'assigned_at' => null,
            ],
        ];
    }

    /**
     * Extingue lógicamente la vinculación de un técnico con una zona (QA 10).
     *
     * @throws ZoneNotFoundException Si la zona no existe.
     */
    public function removeTechnician(int $technicianId, int $zoneId, int $userId): bool
    {
        $this->requireZone($zoneId);

        return $this->zoneRepo->removeTechnician($technicianId, $zoneId, $userId);
    }

    /**
     * Aplica o retira la excepción manual de una sede (RF-03.2, RF-03.3).
     *
     * Con `$manualOverride = true` la sede queda forzada a la zona indicada (o a
     * «Sin Zona Asignada» si se omite). Con `false` recupera la herencia de su
     * código postal. En ambos casos el cambio queda auditado con
     * `LOCATION_ZONE_MANUAL_OVERRIDE`.
     *
     * @param int $locationId Identificador de la sede.
     * @param int|null $zoneId Zona forzada; `null` deja la sede sin demarcación.
     * @param int $userId Coordinador que firma la excepción.
     * @param bool $manualOverride True para forzar, false para restablecer la herencia.
     * @return array<string, mixed> Sede resultante con su modo de resolución.
     *
     * @throws LocationNotFoundException Si la sede no existe.
     * @throws ZoneNotFoundException Si la zona forzada no existe.
     * @throws ZoneValidationException Si la zona forzada está inactiva.
     */
    public function overrideLocationZone(
        int $locationId,
        ?int $zoneId,
        int $userId,
        bool $manualOverride = true
    ): array {
        $location = $this->locationRepo->findById($locationId);
        if ($location === null) {
            throw new LocationNotFoundException($locationId);
        }

        if ($manualOverride) {
            if ($zoneId !== null) {
                $this->requireActiveZone($zoneId);
            }

            $this->locationRepo->setManualOverride($locationId, $zoneId, $userId);
            $resolutionMode = 'MANUAL_OVERRIDE';
        } else {
            $zoneId = $this->locationRepo->clearManualOverride($locationId, $userId);
            $resolutionMode = 'INHERITED_BY_POSTAL_CODE';
        }

        $updated = $this->locationRepo->findById($locationId);
        if ($updated === null) {
            throw new LocationNotFoundException($locationId);
        }

        $zoneName = null;
        if ($zoneId !== null) {
            $zoneName = $this->zoneRepo->findById($zoneId, true)?->getName();
        }

        return [
            'location_id' => $updated->getId(),
            'site_code' => $updated->getSiteCode(),
            'postal_code' => $updated->getPostalCode(),
            'zone_id' => $updated->getZoneId(),
            'zone_name' => $zoneName,
            'is_manual_override' => $updated->isManualOverride(),
            'resolution_mode' => $resolutionMode,
        ];
    }

    /**
     * Zona existente, activa o no, o 404.
     *
     * @throws ZoneNotFoundException
     */
    private function requireZone(int $zoneId): OperationalZone
    {
        $zone = $this->zoneRepo->findById($zoneId, true);
        if ($zone === null) {
            throw new ZoneNotFoundException($zoneId);
        }

        return $zone;
    }

    /**
     * Zona existente y activa, o el rechazo que corresponde al motivo real.
     *
     * @throws ZoneNotFoundException Si la zona no existe.
     * @throws ZoneValidationException Si está desactivada.
     */
    private function requireActiveZone(int $zoneId): OperationalZone
    {
        $zone = $this->requireZone($zoneId);
        if (!$zone->isActive()) {
            throw ZoneValidationException::unprocessable(
                'La zona está desactivada: reactívala antes de modificar su territorio o su cuadrante.',
                ['zone_id' => 'La zona está inactiva.']
            );
        }

        return $zone;
    }

    /**
     * Ficha completa con contadores agregados.
     */
    private function toDto(OperationalZone $zone): OperationalZoneDto
    {
        $zoneId = (int)$zone->getId();
        $row = $this->zoneRepo->findWithCounts($zoneId);

        if ($row === null) {
            return OperationalZoneDto::fromZone($zone, $this->zoneRepo->findPostalCodes($zoneId));
        }

        return OperationalZoneDto::fromRow($row, $this->zoneRepo->findPostalCodes($zoneId));
    }

    /**
     * Normaliza, deduplica y ordena el lote con el Value Object canónico.
     *
     * @param array<int, mixed> $postalCodes
     * @return list<string>
     *
     * @throws ZoneValidationException Si algún código postal no tiene cinco dígitos.
     */
    private static function normalizePostalCodes(array $postalCodes): array
    {
        $normalized = [];
        foreach ($postalCodes as $raw) {
            if (!is_int($raw) && !is_string($raw)) {
                throw ZoneValidationException::forField(
                    'postal_codes',
                    'Cada código postal debe ser un número de cinco dígitos.'
                );
            }

            $value = (new PostalCode($raw))->value();
            $normalized[$value] = $value;
        }

        $codes = array_values($normalized);
        sort($codes, SORT_STRING);

        return $codes;
    }
}
