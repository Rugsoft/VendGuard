<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\SparePartCodeExistsException;
use VendGuard\Core\Domain\Exception\SparePartNotFoundException;
use VendGuard\Core\Domain\Model\SparePart;
use VendGuard\Core\Domain\Model\SparePartCategory;
use VendGuard\Core\Domain\Repository\SparePartRepositoryInterface;

/**
 * SparePartCatalogService
 * 
 * Servicio de Aplicación responsable de la gestión y mantenimiento del catálogo maestro
 * de repuestos, componentes técnicos y compatibilidades por modelo de máquina (RF-REP-01 / RF-REP-02).
 * 
 * Aplica validaciones estrictas de unicidad, consistencia monetaria, integridad referencial
 * y preservación de datos sin borrado físico (Constitución Art. III).
 */
class SparePartCatalogService
{
    private SparePartRepositoryInterface $sparePartRepo;
    private ?AuditLogger $auditLogger;

    public function __construct(
        SparePartRepositoryInterface $sparePartRepo,
        ?AuditLogger $auditLogger = null
    ) {
        $this->sparePartRepo = $sparePartRepo;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Registra un nuevo repuesto en el catálogo maestro validando invariantes y compatibilidades.
     *
     * @param array<string, mixed> $data
     * @param array{id: int|null, role: string, name: string}|null $actor
     * @return SparePart
     * @throws SparePartCodeExistsException Si el código de repuesto ya existe (HTTP 409).
     * @throws InvalidArgumentException Si algún dato no cumple las especificaciones de formato.
     */
    public function createSparePart(array $data, ?array $actor = null): SparePart
    {
        $partCode = trim((string)($data['part_code'] ?? ''));
        if (mb_strlen($partCode) < 3 || mb_strlen($partCode) > 50) {
            throw new InvalidArgumentException('El código del repuesto debe tener entre 3 y 50 caracteres alfanuméricos.');
        }

        if ($this->sparePartRepo->isCodeExists($partCode)) {
            throw new SparePartCodeExistsException("Ya existe un repuesto registrado con el código '{$partCode}'.");
        }

        $name = trim((string)($data['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            throw new InvalidArgumentException('El nombre del repuesto debe tener entre 3 y 150 caracteres.');
        }

        $categoryRaw = (string)($data['category'] ?? '');
        if (!SparePartCategory::isValid($categoryRaw)) {
            throw new InvalidArgumentException("La categoría '{$categoryRaw}' no es válida en el catálogo de repuestos.");
        }
        $category = SparePartCategory::from($categoryRaw);

        $manufacturer = trim((string)($data['manufacturer'] ?? ''));
        if (mb_strlen($manufacturer) < 2 || mb_strlen($manufacturer) > 100) {
            throw new InvalidArgumentException('El fabricante debe tener entre 2 y 100 caracteres.');
        }

        if (!isset($data['reference_cost']) || !is_numeric($data['reference_cost'])) {
            throw new InvalidArgumentException('El coste de referencia debe ser un valor numérico.');
        }
        $referenceCost = (float)$data['reference_cost'];
        if ($referenceCost < 0.0) {
            throw new InvalidArgumentException('El coste de referencia no puede ser negativo.');
        }
        $referenceCost = round($referenceCost, 2);

        $compatibleModelsRaw = $data['compatible_models'] ?? [];
        if (!is_array($compatibleModelsRaw) || empty($compatibleModelsRaw)) {
            throw new InvalidArgumentException('Debe asociar al menos un modelo de máquina compatible con el repuesto.');
        }

        $cleanModels = [];
        foreach ($compatibleModelsRaw as $model) {
            $m = trim((string)$model);
            if (mb_strlen($m) >= 2) {
                $cleanModels[] = $m;
            }
        }
        $cleanModels = array_values(array_unique($cleanModels));
        if (empty($cleanModels)) {
            throw new InvalidArgumentException('Cada modelo compatible debe contener al menos 2 caracteres.');
        }

        $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;
        $notes = isset($data['notes']) && trim((string)$data['notes']) !== '' ? trim((string)$data['notes']) : null;

        $sparePart = new SparePart(
            null,
            $partCode,
            $name,
            $category,
            $manufacturer,
            $referenceCost,
            $isActive,
            $notes,
            $cleanModels
        );

        $created = $this->sparePartRepo->create($sparePart);

        return $created;
    }

    /**
     * Actualiza los datos maestros y relaciones de compatibilidad de un repuesto existente.
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @param array{id: int|null, role: string, name: string}|null $actor
     * @return SparePart
     * @throws SparePartNotFoundException Si el repuesto no existe (HTTP 404).
     * @throws SparePartCodeExistsException Si el nuevo código colisiona con otro repuesto (HTTP 409).
     * @throws InvalidArgumentException Si algún dato no cumple las especificaciones de formato.
     */
    public function updateSparePart(int $id, array $data, ?array $actor = null): SparePart
    {
        $existing = $this->sparePartRepo->findById($id);
        if ($existing === null) {
            throw new SparePartNotFoundException("El repuesto con ID {$id} no existe en el catálogo.");
        }

        $partCode = $existing->getPartCode();
        if (isset($data['part_code'])) {
            $candidateCode = trim((string)$data['part_code']);
            if (mb_strlen($candidateCode) < 3 || mb_strlen($candidateCode) > 50) {
                throw new InvalidArgumentException('El código del repuesto debe tener entre 3 y 50 caracteres alfanuméricos.');
            }
            if ($this->sparePartRepo->isCodeExists($candidateCode, $id)) {
                throw new SparePartCodeExistsException("Ya existe otro repuesto registrado con el código '{$candidateCode}'.");
            }
            $partCode = $candidateCode;
        }

        $name = $existing->getName();
        if (isset($data['name'])) {
            $candidateName = trim((string)$data['name']);
            if (mb_strlen($candidateName) < 3 || mb_strlen($candidateName) > 150) {
                throw new InvalidArgumentException('El nombre del repuesto debe tener entre 3 y 150 caracteres.');
            }
            $name = $candidateName;
        }

        $category = $existing->getCategory();
        if (isset($data['category'])) {
            $catRaw = (string)$data['category'];
            if (!SparePartCategory::isValid($catRaw)) {
                throw new InvalidArgumentException("La categoría '{$catRaw}' no es válida en el catálogo.");
            }
            $category = SparePartCategory::from($catRaw);
        }

        $manufacturer = $existing->getManufacturer();
        if (isset($data['manufacturer'])) {
            $manuCandidate = trim((string)$data['manufacturer']);
            if (mb_strlen($manuCandidate) < 2 || mb_strlen($manuCandidate) > 100) {
                throw new InvalidArgumentException('El fabricante debe tener entre 2 y 100 caracteres.');
            }
            $manufacturer = $manuCandidate;
        }

        $referenceCost = $existing->getReferenceCost();
        if (isset($data['reference_cost'])) {
            if (!is_numeric($data['reference_cost'])) {
                throw new InvalidArgumentException('El coste de referencia debe ser un valor numérico.');
            }
            $costCandidate = (float)$data['reference_cost'];
            if ($costCandidate < 0.0) {
                throw new InvalidArgumentException('El coste de referencia no puede ser negativo.');
            }
            $referenceCost = round($costCandidate, 2);
        }

        $compatibleModels = $existing->getCompatibleModels();
        if (isset($data['compatible_models'])) {
            if (!is_array($data['compatible_models']) || empty($data['compatible_models'])) {
                throw new InvalidArgumentException('Debe asociar al menos un modelo de máquina compatible con el repuesto.');
            }
            $cleanModels = [];
            foreach ($data['compatible_models'] as $model) {
                $m = trim((string)$model);
                if (mb_strlen($m) >= 2) {
                    $cleanModels[] = $m;
                }
            }
            $cleanModels = array_values(array_unique($cleanModels));
            if (empty($cleanModels)) {
                throw new InvalidArgumentException('Cada modelo compatible debe contener al menos 2 caracteres.');
            }
            $compatibleModels = $cleanModels;
        }

        $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : $existing->isActive();
        $notes = array_key_exists('notes', $data)
            ? ($data['notes'] !== null && trim((string)$data['notes']) !== '' ? trim((string)$data['notes']) : null)
            : $existing->getNotes();

        $updatedEntity = new SparePart(
            $id,
            $partCode,
            $name,
            $category,
            $manufacturer,
            $referenceCost,
            $isActive,
            $notes,
            $compatibleModels,
            $existing->getTotalInstalledUnits()
        );

        $this->sparePartRepo->update($updatedEntity);

        return $this->sparePartRepo->findById($id) ?? $updatedEntity;
    }

    /**
     * Aplica la baja lógica a un repuesto sin borrado físico (Constitución Art. III).
     *
     * @param int $id
     * @param array{id: int|null, role: string, name: string}|null $actor
     * @return bool
     * @throws SparePartNotFoundException Si el repuesto no existe (HTTP 404).
     */
    public function softDeleteSparePart(int $id, ?array $actor = null): bool
    {
        $existing = $this->sparePartRepo->findById($id);
        if ($existing === null) {
            throw new SparePartNotFoundException("El repuesto con ID {$id} no existe en el catálogo.");
        }

        return $this->sparePartRepo->softDelete($id);
    }

    /**
     * Actualiza el estado operativo (activo/inactivo) de un repuesto.
     *
     * @param int $id
     * @param bool $isActive
     * @param array{id: int|null, role: string, name: string}|null $actor
     * @return bool
     * @throws SparePartNotFoundException Si el repuesto no existe (HTTP 404).
     */
    public function setSparePartStatus(int $id, bool $isActive, ?array $actor = null): bool
    {
        $existing = $this->sparePartRepo->findById($id);
        if ($existing === null) {
            throw new SparePartNotFoundException("El repuesto con ID {$id} no existe en el catálogo.");
        }

        return $this->sparePartRepo->updateStatus($id, $isActive);
    }

    /**
     * Obtiene un repuesto por su identificador único.
     *
     * @param int $id
     * @return SparePart|null
     */
    public function getSparePart(int $id): ?SparePart
    {
        return $this->sparePartRepo->findById($id);
    }

    /**
     * Obtiene un repuesto por su código normalizado.
     *
     * @param string $partCode
     * @return SparePart|null
     */
    public function getSparePartByCode(string $partCode): ?SparePart
    {
        return $this->sparePartRepo->findByCode(trim($partCode));
    }

    /**
     * Obtiene el listado de repuestos aplicando filtros opcionales de búsqueda, categoría y modelo.
     *
     * @param string|null $search
     * @param string|null $category
     * @param string|null $machineModel
     * @param bool|null $isActive
     * @return SparePart[]
     */
    public function listCatalog(
        ?string $search = null,
        ?string $category = null,
        ?string $machineModel = null,
        ?bool $isActive = null
    ): array {
        return $this->sparePartRepo->findAll($search, $category, $machineModel, $isActive);
    }

    /**
     * Obtiene el listado de repuestos compatibles con un modelo de máquina dispensadora.
     *
     * @param string $machineModel
     * @param bool $onlyActive
     * @return SparePart[]
     */
    public function listCompatibleParts(string $machineModel, bool $onlyActive = true): array
    {
        return $this->sparePartRepo->findCompatibleWithModel(trim($machineModel), $onlyActive);
    }

    /**
     * Obtiene la lista única de modelos de máquina dispensadora existentes en el parque.
     *
     * @return string[]
     */
    public function getDistinctMachineModels(): array
    {
        return $this->sparePartRepo->findDistinctMachineModels();
    }
}
