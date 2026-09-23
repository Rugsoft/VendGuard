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
     * @param bool $withIncident Si es true, adjunta la información de la avería activa o en garantía.
     * @param bool $allowDeleted Si es true, permite recuperar máquinas dadas de baja lógica.
     * @return Machine|null
     */
    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine;

    /**
     * Recupera una máquina por su código alfanumérico rotulado (ej: VEND-0101).
     *
     * @param string $code
     * @param bool $withIncident Si es true, adjunta la información de la avería activa o en garantía.
     * @param bool $allowDeleted Si es true, permite recuperar máquinas dadas de baja lógica.
     * @return Machine|null
     */
    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine;

    /**
     * Da de alta una nueva máquina dispensadora en una sede cliente activa (RF-02, EARS 2.1).
     *
     * @param array<string, mixed> $data
     * @return Machine
     */
    public function create(array $data): Machine;

    /**
     * Actualiza los datos descriptivos y de tipología sanitaria de una máquina (RF-02, EARS 2.3).
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return bool
     */
    public function update(int $id, array $data): bool;

    /**
     * Traslada una máquina operativa a otra sede cliente activa (RF-02, EARS 2.7).
     *
     * @param int $id
     * @param int $targetLocationId
     * @param string $floorWing
     * @param string|null $notes
     * @return bool
     */
    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool;

    /**
     * Reactiva una máquina dada de baja lógica, permitiendo reubicación si la sede original está inactiva (RF-02, EARS 2.12).
     *
     * @param int $id
     * @param int|null $newLocationId
     * @param string|null $newFloorWing
     * @return bool
     */
    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool;

    /**
     * Recupera el catálogo integral de máquinas con filtros avanzados de sede, tipo, estado y búsqueda (RF-02, EARS 2.2).
     *
     * @param array<string, mixed> $filters
     * @return array<array<string, mixed>>
     */
    public function findAll(array $filters = []): array;

    /**
     * Comprueba atómicamente si una máquina tiene tickets activos o en ventana de garantía de 48h (Art. V.6).
     *
     * @param int $machineId
     * @return bool
     */
    public function hasActiveTicketOrWarranty(int $machineId): bool;

    /**
     * Obtiene el ticket activo o en garantía de 48h de una máquina si existe.
     *
     * @param int $machineId
     * @return array<string, string>|null ['ticket_code' => string, 'status' => string]
     */
    public function getActiveTicketOrWarranty(int $machineId): ?array;

    /**
     * Aplica borrado lógico marcando is_active = 0 y deleted_at = CURRENT_TIMESTAMP (RNF-03, Art. III.1).
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool;
}
