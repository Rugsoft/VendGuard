<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\Location;

/**
 * LocationRepositoryInterface
 * 
 * Contrato de persistencia para el acceso y gestión de sedes clientes.
 * Prohíbe borrados físicos según mandato constitucional (RNF-03).
 */
interface LocationRepositoryInterface
{
    /**
     * Recupera una sede activa por su código alfanumérico único.
     * Si la sede no existe o ha sido dada de baja por soft delete (deleted_at IS NOT NULL),
     * debe devolver obligatoriamente null.
     *
     * @param string $siteCode Código de sede (ej: SEDE-BCN-01).
     * @return Location|null
     */
    public function findBySiteCode(string $siteCode): ?Location;

    /**
     * Recupera una sede activa por su ID numérico.
     *
     * @param int $id
     * @return Location|null
     */
    public function findById(int $id): ?Location;

    /**
     * Lista todas las sedes activas no eliminadas.
     *
     * @return array<Location>
     */
    public function findAllActive(): array;

    /**
     * Realiza un borrado lógico (Soft Delete) fijando deleted_at a NOW().
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool;

    /**
     * Actualiza el teléfono de contacto maestro de una sede (RF-01, EARS 1.3).
     *
     * @param int $id Identificador primario de la sede.
     * @param string $contactPhone Nuevo número de contacto.
     * @return bool True si se actualizó con éxito.
     */
    public function updateContactPhone(int $id, string $contactPhone): bool;
}
