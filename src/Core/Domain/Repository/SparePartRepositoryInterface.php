<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\SparePart;

/**
 * SparePartRepositoryInterface
 * 
 * Contrato de persistencia para el catálogo maestro de repuestos y sus
 * compatibilidades asociadas por modelo de máquina (RF-REP-01 / RF-REP-02).
 */
interface SparePartRepositoryInterface
{
    /**
     * Registra un nuevo repuesto en el catálogo y sus modelos compatibles.
     *
     * @param SparePart $sparePart
     * @return SparePart
     */
    public function create(SparePart $sparePart): SparePart;

    /**
     * Actualiza los datos descriptivos, coste de referencia y modelos compatibles de un repuesto.
     *
     * @param SparePart $sparePart
     * @return bool
     */
    public function update(SparePart $sparePart): bool;

    /**
     * Realiza la baja lógica de un repuesto desactivándolo y marcando deleted_at (Art. III).
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool;

    /**
     * Actualiza el estado activo/inactivo de un repuesto.
     *
     * @param int $id
     * @param bool $isActive
     * @return bool
     */
    public function updateStatus(int $id, bool $isActive): bool;

    /**
     * Busca un repuesto por su identificador único primario.
     *
     * @param int $id
     * @return SparePart|null
     */
    public function findById(int $id): ?SparePart;

    /**
     * Busca un repuesto por su código de pieza normalizado.
     *
     * @param string $partCode
     * @return SparePart|null
     */
    public function findByCode(string $partCode): ?SparePart;

    /**
     * Comprueba si ya existe un repuesto con el código especificado.
     *
     * @param string $partCode
     * @param int|null $excludeId ID a excluir en caso de comprobación durante edición
     * @return bool
     */
    public function isCodeExists(string $partCode, ?int $excludeId = null): bool;

    /**
     * Obtiene el listado de repuestos aplicando filtros opcionales.
     *
     * @param string|null $search Búsqueda libre en código o nombre
     * @param string|null $category Categoría técnica
     * @param string|null $machineModel Modelo de máquina compatible
     * @param bool|null $isActive Filtrar por estado activo/inactivo (null para todos)
     * @return SparePart[]
     */
    public function findAll(
        ?string $search = null,
        ?string $category = null,
        ?string $machineModel = null,
        ?bool $isActive = null
    ): array;

    /**
     * Obtiene los repuestos compatibles con un modelo de máquina dispensadora.
     *
     * @param string $machineModel
     * @param bool $onlyActive Si true, excluye repuestos dados de baja lógica
     * @return SparePart[]
     */
    public function findCompatibleWithModel(string $machineModel, bool $onlyActive = true): array;

    /**
     * Obtiene la lista única de modelos de máquina dispensadora existentes en el parque.
     *
     * @return string[]
     */
    public function findDistinctMachineModels(): array;
}
