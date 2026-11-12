<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Repository\MachinePlanogramRepositoryInterface;
use VendGuard\Core\Domain\Repository\VendingProductRepositoryInterface;
use VendGuard\Core\Service\MachineTypePlanogramGuardInterface;
use VendGuard\Core\Service\PlanogramCompatibilityValidator;

/**
 * PdoMachineTypePlanogramGuard
 *
 * Implementación del cerrojo inverso de la máquina (Módulo 32, RF-03.2, QA 4,
 * D3, Art. II): antes de persistir un cambio de `machine_type`, lee los carriles
 * activos de la máquina, hidrata cada uno con su producto y llama a
 * `PlanogramCompatibilityValidator::assertMachineTypeChange()`.
 *
 * La consulta no filtra por producto activo: un carril activo con una referencia
 * dada de baja sigue ocupando espacio físico y su categoría/frío también debe
 * ser compatible con la nueva tipología.
 */
final class PdoMachineTypePlanogramGuard implements MachineTypePlanogramGuardInterface
{
    private MachinePlanogramRepositoryInterface $planogramRepository;

    private VendingProductRepositoryInterface $productRepository;

    public function __construct(
        ?MachinePlanogramRepositoryInterface $planogramRepository = null,
        ?VendingProductRepositoryInterface $productRepository = null
    ) {
        $this->planogramRepository = $planogramRepository ?? new PdoMachinePlanogramRepository();
        $this->productRepository = $productRepository ?? new PdoVendingProductRepository();
    }

    public function assertMachineTypeChange(int $machineId, MachineType $newMachineType): void
    {
        $rows = $this->planogramRepository->findSlotsByMachine($machineId, true);
        if ($rows === []) {
            return;
        }

        $activeSlots = [];
        foreach ($rows as $row) {
            $product = $this->productRepository->findById((int)$row['product_id']);
            if ($product === null) {
                continue;
            }

            $activeSlots[] = [
                'slot' => new MachinePlanogramSlot(
                    isset($row['id']) ? (int)$row['id'] : null,
                    isset($row['machine_id']) ? (int)$row['machine_id'] : $machineId,
                    (string)$row['slot_code'],
                    (int)$row['product_id'],
                    (int)$row['max_capacity'],
                    (int)$row['current_estimated_qty'],
                    (int)$row['min_threshold'],
                    (bool)$row['is_active']
                ),
                'product' => $product,
            ];
        }

        PlanogramCompatibilityValidator::assertMachineTypeChange($newMachineType, $activeSlots);
    }
}
