<?php

declare(strict_types=1);

/**
 * VendGuard - Puerto de stock de furgoneta que falla al descontar (arnés de pruebas)
 *
 * Existe para certificar qué ocurre cuando el descuento del Módulo 18 **no puede** ejecutarse por una
 * caída real del motor, no por una regla de negocio. La diferencia importa: no hay ninguna regla que
 * pueda rechazar un consumo (el saldo admite negativos y nunca se consulta antes), así que el único
 * fallo posible es de infraestructura, y su semántica debe quedar escrita en una prueba: la línea de
 * la pieza ya está persistida —se escribe antes, en autocommit— y la avería **no** queda resuelta.
 *
 * Lo mismo vale para el sentido contrario: el descuento no atrapa la excepción en silencio, porque un
 * inventario que miente sobre lo que se consumió es peor que un error visible.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class ThrowingVanStockRepository implements \VendGuard\Core\Domain\Repository\VanStockRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function findByPair(int $technicianId, int $sparePartId): ?\VendGuard\Core\Domain\Model\VanStockItem
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function adjust(
        \VendGuard\Core\Domain\Model\VanStockItem $item,
        ?int $previousQuantity,
        ?int $actorUserId
    ): \VendGuard\Core\Domain\Model\VanStockItem {
        throw new \LogicException('El ajuste del coordinador no forma parte del flujo de declaración.');
    }

    /**
     * {@inheritDoc}
     */
    public function consume(int $technicianId, int $sparePartId, int $quantity): void
    {
        throw new \RuntimeException('fallo simulado del motor en el descuento');
    }

    /**
     * {@inheritDoc}
     */
    public function listByTechnician(int $technicianId): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function listInventory(?int $technicianId = null): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function listBelowThreshold(?int $technicianId = null): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function findTechnician(int $technicianId): ?array
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function sparePartExists(int $sparePartId): bool
    {
        return true;
    }
}
