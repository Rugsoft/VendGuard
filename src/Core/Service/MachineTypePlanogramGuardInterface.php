<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Model\MachineType;

/**
 * MachineTypePlanogramGuardInterface
 *
 * Puerto de dominio para comprobar, antes de cambiar la tipología de una
 * máquina, que ninguno de sus carriles activos queda incompatible con la nueva
 * tipología (Módulo 32, RF-03.2, QA 4, D3, Art. II).
 *
 * La implementación PDO vive en infraestructura: carga los carriles activos y
 * sus productos, los hidrata como entidades y delega la decisión en
 * `PlanogramCompatibilityValidator`. El servicio de administración sólo conoce
 * este contrato, de modo que su suite unitaria no necesita base de datos.
 */
interface MachineTypePlanogramGuardInterface
{
    /**
     * @throws \VendGuard\Core\Domain\Exception\PlanogramValidationException
     *         con 409 `PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE` y el listado de
     *         conflictos cuando algún carril activo es incompatible.
     */
    public function assertMachineTypeChange(int $machineId, MachineType $newMachineType): void;
}
