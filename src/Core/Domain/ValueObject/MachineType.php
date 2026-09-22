<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use VendGuard\Core\Domain\Model\MachineType as ModelMachineType;

/**
 * MachineType (ValueObject alias)
 * 
 * Permite el acceso transparente al Enum MachineType tanto desde
 * VendGuard\Core\Domain\Model como desde VendGuard\Core\Domain\ValueObject.
 */
class_alias(ModelMachineType::class, 'VendGuard\Core\Domain\ValueObject\MachineType');
