<?php

declare(strict_types=1);

/**
 * SparePartCatalogServiceTest
 * 
 * Test Unitario para SparePartCatalogService (Tarea T-SPARE-07).
 * Valida reglas de negocio de catálogo de repuestos (RF-REP-01, RF-REP-02, RNF-REP-01):
 * - Validación de invariantes (nombres, categorías, costes >= 0.00).
 * - Rechazo de duplicidad de código con SparePartCodeExistsException (HTTP 409).
 * - Actualización controlada de datos y compatibilidades.
 * - Baja lógica (soft delete) y reactivación sin borrado físico (Constitución Art. III).
 * - Consultas de catálogo y compatibilidades por modelo de máquina.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\SparePartCatalogService;
use VendGuard\Core\Domain\Exception\SparePartCodeExistsException;
use VendGuard\Core\Domain\Exception\SparePartNotFoundException;
use VendGuard\Core\Domain\Model\SparePart;
use VendGuard\Core\Domain\Model\SparePartCategory;
use VendGuard\Core\Domain\Repository\SparePartRepositoryInterface;

echo "======================================================================\n";
echo " VendGuard: Test Unitario - SparePartCatalogServiceTest (T-SPARE-07)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

/**
 * Mock en memoria de SparePartRepositoryInterface para pruebas unitarias puras y aisladas.
 */
class InMemorySparePartRepository implements SparePartRepositoryInterface
{
    /** @var array<int, SparePart> */
    public array $parts = [];
    private int $nextId = 1;

    public function create(SparePart $sparePart): SparePart
    {
        $id = $this->nextId++;
        $created = new SparePart(
            $id,
            $sparePart->getPartCode(),
            $sparePart->getName(),
            $sparePart->getCategory(),
            $sparePart->getManufacturer(),
            $sparePart->getReferenceCost(),
            $sparePart->isActive(),
            $sparePart->getNotes(),
            $sparePart->getCompatibleModels(),
            0,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            null
        );
        $this->parts[$id] = $created;
        return $created;
    }

    public function update(SparePart $sparePart): bool
    {
        $id = (int)$sparePart->getId();
        if (!isset($this->parts[$id])) {
            return false;
        }

        $this->parts[$id] = new SparePart(
            $id,
            $sparePart->getPartCode(),
            $sparePart->getName(),
            $sparePart->getCategory(),
            $sparePart->getManufacturer(),
            $sparePart->getReferenceCost(),
            $sparePart->isActive(),
            $sparePart->getNotes(),
            $sparePart->getCompatibleModels(),
            $this->parts[$id]->getTotalInstalledUnits(),
            $this->parts[$id]->getCreatedAt(),
            date('Y-m-d H:i:s'),
            $this->parts[$id]->getDeletedAt()
        );

        return true;
    }

    public function softDelete(int $id): bool
    {
        if (!isset($this->parts[$id])) {
            return false;
        }
        $p = $this->parts[$id];
        $this->parts[$id] = new SparePart(
            $id,
            $p->getPartCode(),
            $p->getName(),
            $p->getCategory(),
            $p->getManufacturer(),
            $p->getReferenceCost(),
            false, // baja lógica
            $p->getNotes(),
            $p->getCompatibleModels(),
            $p->getTotalInstalledUnits(),
            $p->getCreatedAt(),
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s') // deleted_at establecido
        );
        return true;
    }

    public function updateStatus(int $id, bool $isActive): bool
    {
        if (!isset($this->parts[$id])) {
            return false;
        }
        $p = $this->parts[$id];
        $this->parts[$id] = new SparePart(
            $id,
            $p->getPartCode(),
            $p->getName(),
            $p->getCategory(),
            $p->getManufacturer(),
            $p->getReferenceCost(),
            $isActive,
            $p->getNotes(),
            $p->getCompatibleModels(),
            $p->getTotalInstalledUnits(),
            $p->getCreatedAt(),
            date('Y-m-d H:i:s'),
            $isActive ? null : date('Y-m-d H:i:s')
        );
        return true;
    }

    public function findById(int $id): ?SparePart
    {
        return $this->parts[$id] ?? null;
    }

    public function findByCode(string $partCode): ?SparePart
    {
        $code = trim($partCode);
        foreach ($this->parts as $p) {
            if ($p->getPartCode() === $code) {
                return $p;
            }
        }
        return null;
    }

    public function isCodeExists(string $partCode, ?int $excludeId = null): bool
    {
        $code = trim($partCode);
        foreach ($this->parts as $p) {
            if ($p->getPartCode() === $code && ($excludeId === null || $p->getId() !== $excludeId)) {
                return true;
            }
        }
        return false;
    }

    public function findAll(
        ?string $search = null,
        ?string $category = null,
        ?string $machineModel = null,
        ?bool $isActive = null
    ): array {
        $results = [];
        foreach ($this->parts as $p) {
            if ($search !== null && $search !== '') {
                $term = mb_strtolower($search);
                if (!str_contains(mb_strtolower($p->getName()), $term) &&
                    !str_contains(mb_strtolower($p->getPartCode()), $term)) {
                    continue;
                }
            }
            if ($category !== null && $p->getCategory()->value !== $category) {
                continue;
            }
            if ($machineModel !== null && !in_array($machineModel, $p->getCompatibleModels(), true)) {
                continue;
            }
            if ($isActive !== null && $p->isActive() !== $isActive) {
                continue;
            }
            $results[] = $p;
        }
        return $results;
    }

    public function findCompatibleWithModel(string $machineModel, bool $onlyActive = true): array
    {
        $results = [];
        foreach ($this->parts as $p) {
            if ($onlyActive && !$p->isActive()) {
                continue;
            }
            if (in_array($machineModel, $p->getCompatibleModels(), true)) {
                $results[] = $p;
            }
        }
        return $results;
    }

    public function findDistinctMachineModels(): array
    {
        $models = [];
        foreach ($this->parts as $p) {
            foreach ($p->getCompatibleModels() as $m) {
                $models[$m] = true;
            }
        }
        return array_keys($models);
    }
}

$repo = new InMemorySparePartRepository();
$service = new SparePartCatalogService($repo);

// =====================================================================
// 1. Pruebas de Creación de Repuesto (createSparePart)
// =====================================================================
echo "--- 1. Pruebas de Creación de Repuesto ---\n";

$validData = [
    'part_code'         => 'VALV-ULKA-01',
    'name'              => 'Electroválvula 24V Ulka',
    'category'          => 'HYDRAULIC',
    'manufacturer'      => 'Ulka',
    'reference_cost'    => 28.50,
    'compatible_models' => ['Azkoyen Palma+', 'Fas Perla'],
    'notes'             => 'Válvula de presión estándar',
];

$created = $service->createSparePart($validData);
$assert("1.1 createSparePart genera entidad persistida con ID", $created->getId() === 1);
$assert("1.2 Código asignado correctamente", $created->getPartCode() === 'VALV-ULKA-01');
$assert("1.3 Coste de referencia 28.50", abs($created->getReferenceCost() - 28.50) < 0.001);
$assert("1.4 Categoría enum asignada", $created->getCategory() === SparePartCategory::HYDRAULIC);
$assert("1.5 Modelos compatibles guardados", count($created->getCompatibleModels()) === 2);
$assert("1.6 Estado inicial activo por defecto", $created->isActive() === true);

// Intento de duplicidad de código
$duplicateCaught = false;
try {
    $service->createSparePart($validData);
} catch (SparePartCodeExistsException $e) {
    $duplicateCaught = true;
    $assert("1.7 Código duplicado lanza SparePartCodeExistsException (HTTP 409)", $e->getCode() === 409);
    $assert("1.8 Código de error técnico es SPARE_PART_CODE_EXISTS", $e->getErrorCode() === 'SPARE_PART_CODE_EXISTS');
}
$assert("1.9 Se rechazó el código duplicado", $duplicateCaught);

// Validaciones de formato
$invalidCodeCaught = false;
try {
    $service->createSparePart(array_merge($validData, ['part_code' => 'AB']));
} catch (InvalidArgumentException) {
    $invalidCodeCaught = true;
}
$assert("1.10 Rechaza código con menos de 3 caracteres", $invalidCodeCaught);

$invalidNameCaught = false;
try {
    $service->createSparePart(array_merge($validData, ['part_code' => 'VALV-02', 'name' => 'Ab']));
} catch (InvalidArgumentException) {
    $invalidNameCaught = true;
}
$assert("1.11 Rechaza nombre con menos de 3 caracteres", $invalidNameCaught);

$invalidCatCaught = false;
try {
    $service->createSparePart(array_merge($validData, ['part_code' => 'VALV-03', 'category' => 'INVALIDA']));
} catch (InvalidArgumentException) {
    $invalidCatCaught = true;
}
$assert("1.12 Rechaza categoría no contemplada en el enum", $invalidCatCaught);

$negativeCostCaught = false;
try {
    $service->createSparePart(array_merge($validData, ['part_code' => 'VALV-04', 'reference_cost' => -5.00]));
} catch (InvalidArgumentException) {
    $negativeCostCaught = true;
}
$assert("1.13 Rechaza coste de referencia negativo", $negativeCostCaught);

$emptyModelsCaught = false;
try {
    $service->createSparePart(array_merge($validData, ['part_code' => 'VALV-05', 'compatible_models' => []]));
} catch (InvalidArgumentException) {
    $emptyModelsCaught = true;
}
$assert("1.14 Rechaza lista de modelos compatibles vacía", $emptyModelsCaught);

// =====================================================================
// 2. Pruebas de Actualización de Repuesto (updateSparePart)
// =====================================================================
echo "\n--- 2. Pruebas de Actualización de Repuesto ---\n";

// Crear segundo repuesto para probar colisiones
$secondPart = $service->createSparePart([
    'part_code'         => 'BOMB-VIB-02',
    'name'              => 'Bomba Vibratoria 230V',
    'category'          => 'HYDRAULIC',
    'manufacturer'      => 'Ulka / CEME',
    'reference_cost'    => 36.00,
    'compatible_models' => ['Azkoyen Palma+'],
]);

$updated = $service->updateSparePart(1, [
    'name'              => 'Electroválvula 24V Ulka Actualizada',
    'reference_cost'    => 31.25,
    'compatible_models' => ['Fas Fast 900'],
]);
$assert("2.1 updateSparePart actualiza nombre", $updated->getName() === 'Electroválvula 24V Ulka Actualizada');
$assert("2.2 updateSparePart actualiza coste de referencia a 31.25", abs($updated->getReferenceCost() - 31.25) < 0.001);
$assert("2.3 updateSparePart sincroniza modelos compatibles", $updated->getCompatibleModels() === ['Fas Fast 900']);

// Intento de actualizar part_code colisionando con otro repuesto existente
$updateCollisionCaught = false;
try {
    $service->updateSparePart(1, ['part_code' => 'BOMB-VIB-02']);
} catch (SparePartCodeExistsException $e) {
    $updateCollisionCaught = true;
    $assert("2.4 Colisión de código en actualización lanza SparePartCodeExistsException", $e->getCode() === 409);
}
$assert("2.5 Se bloqueó la colisión de código en actualización", $updateCollisionCaught);

// Repuesto no encontrado al actualizar
$notFoundUpdateCaught = false;
try {
    $service->updateSparePart(999, ['name' => 'Inexistente']);
} catch (SparePartNotFoundException $e) {
    $notFoundUpdateCaught = true;
    $assert("2.6 Repuesto no existente lanza SparePartNotFoundException (HTTP 404)", $e->getCode() === 404);
}
$assert("2.7 Se detectó 404 al intentar actualizar repuesto no existente", $notFoundUpdateCaught);

// =====================================================================
// 3. Pruebas de Baja Lógica y Cambio de Estado (Art. III)
// =====================================================================
echo "\n--- 3. Pruebas de Baja Lógica y Cambio de Estado ---\n";

$softDeleteOk = $service->softDeleteSparePart(1);
$assert("3.1 softDeleteSparePart devuelve true", $softDeleteOk === true);

$softDeleted = $service->getSparePart(1);
$assert("3.2 El repuesto sigue existiendo en el repositorio (cero borrado físico)", $softDeleted !== null);
$assert("3.3 is_active pasa a ser false", $softDeleted !== null && $softDeleted->isActive() === false);
$assert("3.4 deleted_at queda establecido", $softDeleted !== null && $softDeleted->getDeletedAt() !== null);

// Reactivación
$reactivateOk = $service->setSparePartStatus(1, true);
$assert("3.5 setSparePartStatus(true) devuelve true", $reactivateOk === true);

$reactivated = $service->getSparePart(1);
$assert("3.6 El repuesto vuelve a estar activo (is_active = true)", $reactivated !== null && $reactivated->isActive() === true);
$assert("3.7 deleted_at se limpia a null", $reactivated !== null && $reactivated->getDeletedAt() === null);

// Baja lógica de repuesto inexistente lanza 404
$notFoundDeleteCaught = false;
try {
    $service->softDeleteSparePart(999);
} catch (SparePartNotFoundException) {
    $notFoundDeleteCaught = true;
}
$assert("3.8 softDelete de repuesto no existente lanza SparePartNotFoundException", $notFoundDeleteCaught);

// =====================================================================
// 4. Pruebas de Consulta y Filtros de Catálogo
// =====================================================================
echo "\n--- 4. Pruebas de Consulta y Filtros de Catálogo ---\n";

$foundByCode = $service->getSparePartByCode('VALV-ULKA-01');
$assert("4.1 getSparePartByCode recupera la pieza correcta", $foundByCode !== null && $foundByCode->getId() === 1);

$allCatalog = $service->listCatalog();
$assert("4.2 listCatalog devuelve todos los repuestos registrados", count($allCatalog) === 2);

$filteredSearch = $service->listCatalog(search: 'Bomba');
$assert("4.3 listCatalog con search 'Bomba' filtra correctamente", count($filteredSearch) === 1 && $filteredSearch[0]->getPartCode() === 'BOMB-VIB-02');

$filteredModel = $service->listCompatibleParts('Fas Fast 900', onlyActive: true);
$assert("4.4 listCompatibleParts('Fas Fast 900') devuelve compatibles activos", count($filteredModel) === 1 && $filteredModel[0]->getId() === 1);

$models = $service->getDistinctMachineModels();
$assert("4.5 getDistinctMachineModels retorna modelos disponibles", count($models) >= 2 && in_array('Fas Fast 900', $models, true));

echo "\n----------------------------------------------------------------------\n";
echo "Total Aserciones Evaluadas: {$assertions}\n";

if ($failures === 0) {
    echo "Resultado: [OK] Todos los tests de SparePartCatalogService pasaron al 100% en verde.\n";
    exit(0);
} else {
    echo "Resultado: [FALLO] Se detectaron {$failures} fallos en el servicio de catálogo.\n";
    exit(1);
}
