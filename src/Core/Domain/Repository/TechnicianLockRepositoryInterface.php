<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * TechnicianLockRepositoryInterface
 *
 * Cerrojo de aplicación por técnico. Serializa las operaciones que deben
 * comprobar una invariante antes de escribir: alta de ausencia y transiciones
 * de turno. La constraint de base de datos sigue siendo la última autoridad.
 */
interface TechnicianLockRepositoryInterface
{
    /**
     * Bloquea la fila del técnico hasta el final de la transacción actual.
     *
     * @return bool False si el técnico no existe, está borrado o no es un TECHNICIAN.
     */
    public function lockTechnicianForUpdate(int $technicianId): bool;
}
