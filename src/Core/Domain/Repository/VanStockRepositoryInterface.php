<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Application\DTO\VanStockEntryDto;
use VendGuard\Core\Domain\Model\VanStockItem;

/**
 * VanStockRepositoryInterface
 *
 * Contrato de persistencia del stock de repuestos de la furgoneta (Módulo 18, RF-01 a RF-05).
 *
 * El puerto expone exactamente lo que el módulo necesita, incluidas las dos comprobaciones de
 * destino (`findTechnician`, `sparePartExists`): el listado ya une `users` y `spare_parts`, así
 * que el módulo no gana nada dependiendo de los puertos completos de usuarios y repuestos —con
 * más de veinte métodos entre ambos— para dos lecturas de existencia.
 *
 * Reglas que este contrato impone a cualquier implementación:
 *
 *  - **Una sola sentencia para el consumo (RD-02).** `consume()` es un `INSERT ... ON CONFLICT ...
 *    DO UPDATE` atómico: no lee antes, no abre transacción y no puede perder una carrera entre
 *    dos declaraciones simultáneas. Es la forma de que el descuento no pueda, por sí mismo,
 *    impedir el cierre de una avería (Art. V.1).
 *  - **El ajuste sí es una unidad de escritura (RF-05.1).** `adjust()` escribe la fila y su
 *    evento de auditoría en la misma transacción: o queda el saldo con su rastro, o no queda
 *    ninguno de los dos.
 *  - **Nunca se borra.** No hay método de baja: la baja de una pieza es cantidad `0`, y el
 *    disparador `trg_no_delete_technician_van_stocks` lo impone también en el motor (Art. III.1).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
interface VanStockRepositoryInterface
{
    /**
     * Saldo declarado de una pareja técnico/pieza.
     */
    public function findByPair(int $technicianId, int $sparePartId): ?VanStockItem;

    /**
     * Ajuste absoluto del coordinador con auditoría del delta (RF-01.1, RF-05.1).
     *
     * @param VanStockItem $item Saldo que se desea dejar declarado.
     * @param int|null $previousQuantity Unidades declaradas antes del ajuste; `null` si no había fila.
     * @param int|null $actorUserId Usuario que ejecuta el ajuste (se resuelve a rol y nombre).
     * @return VanStockItem Saldo persistido con su identificador.
     */
    public function adjust(VanStockItem $item, ?int $previousQuantity, ?int $actorUserId): VanStockItem;

    /**
     * Descuento por consumo declarado (RF-02.1, RD-02): atomic, sin lectura previa.
     *
     * @param int $quantity Unidades instaladas que se descuentan (siempre positivo).
     */
    public function consume(int $technicianId, int $sparePartId, int $quantity): void;

    /**
     * Inventario completo de una furgoneta (RF-04.1), ordenado por nombre de pieza.
     *
     * @return list<VanStockEntryDto>
     */
    public function listByTechnician(int $technicianId): array;

    /**
     * Inventario completo del parque, opcionalmente limitado a un técnico (contrato §4.2).
     *
     * Es la lectura que sostiene el modo «inventario» del panel de coordinación, distinto de la
     * alerta: la alerta solo devuelve parejas bajo umbral y viene ordenada por severidad; aquí el
     * orden es estable por técnico y pieza para que la tabla no cambie de forma entre consultas.
     *
     * @param int|null $technicianId Limita el inventario a un técnico; `null` recorre todo el parque.
     * @return list<VanStockEntryDto>
     */
    public function listInventory(?int $technicianId = null): array;

    /**
     * Parejas bajo umbral, ya ordenadas por severidad: primero los descuadres negativos y después
     * por cantidad disponible ascendente (RF-03.2, contratos §6.3).
     *
     * @param int|null $technicianId Limita la alerta a un técnico; `null` recorre todo el parque.
     * @return list<VanStockEntryDto>
     */
    public function listBelowThreshold(?int $technicianId = null): array;

    /**
     * Técnico destino de un ajuste.
     *
     * @return array{id: int, name: string, role: string, operator_code: string|null}|null
     *   `null` si no existe o está dado de baja.
     */
    public function findTechnician(int $technicianId): ?array;

    /**
     * Indica si la pieza existe en el catálogo maestro.
     *
     * Se admiten piezas con baja lógica: una furgoneta puede conservar una pieza descatalogada.
     */
    public function sparePartExists(int $sparePartId): bool;
}
