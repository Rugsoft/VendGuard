<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\MachinePlanogramSlot;

/**
 * MachinePlanogramRepositoryInterface
 *
 * Contrato de persistencia de los carriles del planograma (Módulo 32, Tabla 43,
 * RF-02.1–RF-02.3, RF-05.2, C1, C2, QA 2, QA 3, Art. III.1).
 *
 * El contrato no expone `DELETE`: guardar un carril es un `UPSERT` por
 * `(machine_id, slot_code)` y retirar un carril es `is_active = FALSE`. Las
 * lecturas devuelven los carriles en orden físico de bandeja (`QA 3`) y sin
 * `unit_cost_eur`, de modo que ninguna superficie de reposición pueda filtrar el
 * coste interno por descuido (Art. V.4).
 */
interface MachinePlanogramRepositoryInterface
{
    /**
     * Máquina del parque con su sede y zona, sin planograma.
     *
     * @return array<string, mixed>|null
     */
    public function findMachineById(int $machineId): ?array;

    /**
     * Carriles de una máquina en orden físico, con producto y semáforo.
     *
     * @return list<array<string, mixed>>
     */
    public function findSlotsByMachine(int $machineId, bool $onlyActive = true): array;

    /**
     * Un carril concreto por su código normalizado.
     *
     * @return array<string, mixed>|null
     */
    public function findSlot(int $machineId, string $slotCode): ?array;

    /**
     * Alta o actualización de un carril por `(machine_id, slot_code)`.
     *
     * @return array<string, mixed>
     */
    public function upsertSlot(MachinePlanogramSlot $slot): array;

    /**
     * Reemplaza el mapa completo de una máquina: `UPSERT` de los carriles
     * recibidos y baja lógica de los activos que ya no figuran (C1).
     *
     * @param list<MachinePlanogramSlot> $slots
     *
     * @return list<array<string, mixed>> Carriles activos resultantes
     */
    public function replaceMachinePlanogram(int $machineId, array $slots): array;

    /**
     * Baja lógica de un carril sin borrar la fila (Art. III.1).
     */
    public function deactivateSlot(int $machineId, string $slotCode): bool;

    /**
     * Resumen determinista de los carriles activos (RF-02.3).
     *
     * @return array{active_slots_count: int, stockout_slots_count: int, low_stock_slots_count: int, ok_slots_count: int, inactive_product_slots_count: int, total_capacity: int, total_estimated_qty: int, fill_rate_percent: float}
     */
    public function summarize(int $machineId): array;

    /**
     * Máquinas activas de las zonas operativas vivas del reponedor, con el
     * resumen de su planograma (QA 2).
     *
     * @return list<array<string, mixed>>
     */
    public function findMachinesForRestocker(int $restockerId): array;

    /**
     * Una máquina concreta si pertenece a una zona viva del reponedor; `null`
     * en caso contrario (el controlador responde 403 MACHINE_OUTSIDE_RESTOCKER_ZONES).
     *
     * @return array<string, mixed>|null
     */
    public function findMachineForRestocker(int $machineId, int $restockerId): ?array;
}
