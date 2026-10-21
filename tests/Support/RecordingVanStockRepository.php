<?php

declare(strict_types=1);

/**
 * VendGuard - Puerto de stock de furgoneta que solo recuerda lo que le piden (arnés de pruebas)
 *
 * El descuento automático del Módulo 18 se certifica por su **efecto sobre el puerto**, no por la
 * tabla: lo que importa en las suites del flujo de averías es cuántas unidades se descontaron, de
 * qué pareja técnico/pieza y cuántas veces. Este doble registra exactamente eso y responde a las
 * lecturas con valores neutros, de modo que ninguna suite dependa de un motor real para probar una
 * regla de negocio.
 *
 * `findByPair()` cuenta sus llamadas a propósito: el flujo de declaración **no puede** consultar la
 * disponibilidad antes de descontar, porque el inventario nunca bloquea una resolución (RD-04,
 * Art. V.1). Una suite que vea esa llamada tiene un fallo de diseño, no un fallo de datos.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class RecordingVanStockRepository implements \VendGuard\Core\Domain\Repository\VanStockRepositoryInterface
{
    /** @var list<array{technician_id: int, spare_part_id: int, quantity: int}> */
    public array $consumptions = [];

    /** Llamadas a la lectura de saldo: en el flujo de declaración deben ser cero (RD-04). */
    public int $findByPairCalls = 0;

    /**
     * {@inheritDoc}
     */
    public function findByPair(int $technicianId, int $sparePartId): ?\VendGuard\Core\Domain\Model\VanStockItem
    {
        $this->findByPairCalls++;

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
        $this->consumptions[] = [
            'technician_id' => $technicianId,
            'spare_part_id' => $sparePartId,
            'quantity' => $quantity,
        ];
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
