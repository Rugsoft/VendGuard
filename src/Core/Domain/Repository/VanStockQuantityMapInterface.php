<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * VanStockQuantityMapInterface
 *
 * Contrato segregado para la lectura acotada de saldos disponibles por técnico y pieza
 * [technician_id => [spare_part_id => quantity_available]] sin hidratar DTOs completos (RNF-01, Módulo 23).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 */
interface VanStockQuantityMapInterface
{
    /**
     * Mapa de saldos disponibles para los técnicos indicados.
     *
     * @param list<int> $technicianIds
     * @return array<int, array<int, int>>
     */
    public function findQuantityMapByTechnicianIds(array $technicianIds): array;
}
