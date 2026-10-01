<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\Location;

/**
 * LocationRepositoryInterface
 * 
 * Contrato de persistencia para el acceso y gestión integral de sedes clientes.
 * Prohíbe terminantemente borrados físicos según mandato constitucional (Art. III.1).
 */
interface LocationRepositoryInterface
{
    /**
     * Recupera una sede por su código alfanumérico único.
     *
     * @param string $siteCode Código de sede (ej: SEDE-BCN-01).
     * @return Location|null
     */
    public function findBySiteCode(string $siteCode): ?Location;

    /**
     * Recupera una sede por su ID numérico primario.
     *
     * @param int $id Identificador de sede.
     * @return Location|null
     */
    public function findById(int $id): ?Location;

    /**
     * Lista todas las sedes activas y no eliminadas (para retrocompatibilidad).
     *
     * @return array<Location>
     */
    public function findAllActive(): array;

    /**
     * Lista sedes permitiendo filtrar por estado y búsqueda de texto (RF-01, EARS 1.1).
     *
     * @param string $status 'active', 'inactive' o 'all'.
     * @param string|null $search Búsqueda parcial en código, nombre, dirección o contacto.
     * @return array<array<string, mixed>> Lista estructurada con conteos de máquinas.
     */
    public function findAll(string $status = 'all', ?string $search = null): array;

    /**
     * Da de alta una nueva sede cliente con código único inmutable (RF-01, EARS 1.2).
     *
     * @param array<string, mixed> $data Datos de la sede, incluyendo opcionalmente latitude y longitude.
     *        Los llamadores heredados que omitan coordenadas conservan los valores por defecto del dominio.
     * @return Location Entidad persistida con su ID autogenerado.
     */
    public function create(array $data): Location;

    /**
     * Actualiza datos descriptivos y, cuando se suministran, las coordenadas de una sede (RF-01, RF-MAP-01).
     *
     * @param int $id Identificador primario.
     * @param array<string, mixed> $data Campos a modificar (name, address, latitude, longitude, contact_name, contact_phone).
     * @return bool True si se actualizó con éxito.
     */
    public function update(int $id, array $data): bool;

    /**
     * Aplica baja lógica fijando is_active = 0 y deleted_at = NOW() (RF-01, EARS 1.4).
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool;

    /**
     * Reactiva una sede previamente dada de baja lógica (RF-01, EARS 1.5).
     *
     * @param int $id
     * @return bool
     */
    public function restore(int $id): bool;

    /**
     * Actualiza el teléfono de contacto maestro de una sede.
     *
     * @param int $id
     * @param string $contactPhone
     * @return bool
     */
    public function updateContactPhone(int $id, string $contactPhone): bool;

    /**
     * Cuenta el número de máquinas activas vinculadas a una sede para evaluar bloqueos (RF-01, EARS 1.4).
     *
     * @param int $locationId
     * @return int
     */
    public function countActiveMachines(int $locationId): int;
}
