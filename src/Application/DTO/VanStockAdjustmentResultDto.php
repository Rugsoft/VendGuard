<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use JsonSerializable;
use VendGuard\Core\Domain\Model\VanStockItem;

/**
 * VanStockAdjustmentResultDto
 *
 * Resultado del ajuste absoluto del coordinador (RF-01.1, RF-05.1). El panel necesita tres cosas
 * que la fila sola no dice: el saldo resultante, **cuánto había antes** y el delta aplicado. Hoy
 * el coordinador teclea el valor absoluto, no la diferencia; devolver la cantidad previa es lo
 * que le permite confirmar que el ajuste hizo lo que esperaba.
 *
 * Un alta no tiene cantidad previa: `previousQuantityAvailable` es `null`, no `0`, porque «no
 * existía fila» y «había cero unidades» son hechos distintos.
 *
 * Dogma Vanilla: `final readonly`, PHP 8.2+ puro, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class VanStockAdjustmentResultDto implements JsonSerializable
{
    /**
     * @param VanStockItem $item Saldo persistido con su identificador.
     * @param int|null $previousQuantityAvailable Unidades declaradas antes del ajuste; `null` en un alta.
     * @param int $delta Diferencia aplicada (nueva cantidad menos previa, o la cantidad en un alta).
     */
    public function __construct(
        public VanStockItem $item,
        public ?int $previousQuantityAvailable,
        public int $delta
    ) {
    }

    /**
     * Forma de la respuesta `200 OK` del contrato §4.3.
     *
     * @return array<string, int|bool|null>
     */
    public function jsonSerialize(): array
    {
        return [
            'technician_id' => $this->item->technicianId,
            'spare_part_id' => $this->item->sparePartId,
            'quantity_available' => $this->item->quantityAvailable,
            'min_threshold' => $this->item->minThreshold,
            'is_below_threshold' => $this->item->isBelowThreshold(),
            'previous_quantity_available' => $this->previousQuantityAvailable,
        ];
    }
}
