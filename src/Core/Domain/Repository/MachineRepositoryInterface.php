<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\Machine;

/**
 * MachineRepositoryInterface
 * 
 * Contrato de persistencia para el acceso y gestión del parque de máquinas.
 */
interface MachineRepositoryInterface
{
    /**
     * Recupera el parque de máquinas activas de una sede concreta,
     * enriqueciendo cada máquina con la información de su aviso activo o en garantía si existe.
     *
     * @param int $locationId
     * @return array<Machine>
     */
    public function findActiveByLocationId(int $locationId): array;

    /**
     * Recupera una máquina por su identificador primario.
     *
     * @param int $id
     * @return Machine|null
     */
    public function findById(int $id): ?Machine;

    /**
     * Recupera una máquina por su código alfanumérico rotulado (ej: VEND-0101).
     *
     * @param string $code
     * @return Machine|null
     */
    public function findByCode(string $code): ?Machine;

    /**
     * Aplica borrado lógico marcando deleted_at = CURRENT_TIMESTAMP (RNF-03).
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool;
}
