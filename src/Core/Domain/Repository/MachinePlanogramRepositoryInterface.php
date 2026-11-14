<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineType;

/**
 * MachinePlanogramRepositoryInterface
 *
 * Contrato de persistencia de los carriles del planograma (Módulo 32, Tabla 43,
 * Módulo 34, `D1`; RF-02.1–RF-02.3, RF-05.2, C1, C2, QA 1, QA 2, QA 3,
 * Art. III.1, Art. V.4).
 *
 * El contrato no expone `DELETE`: guardar un carril es un `UPSERT` por
 * `(machine_id, slot_code)` y retirar un carril es `is_active = FALSE`. Las
 * lecturas devuelven los carriles en orden físico de bandeja (`QA 3`) y sin
 * `unit_cost_eur`, de modo que ninguna superficie de reposición pueda filtrar el
 * coste interno por descuido (Art. V.4).
 *
 * Desde el Módulo 34 el carril conoce su lote y caducidad vigentes y se actualiza
 * al cerrar una visita in situ: `applyVisitSlotMovement()` aplica la regla `D1`
 * dentro de una transacción, bloquea el carril con `FOR UPDATE` y nunca borra.
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
     * Carriles de una máquina en orden físico, con producto, semáforo de
     * existencias y semáforo FEFO de lote (`QA 1`).
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
     * Máquinas activas compatibles para clonar el planograma de una máquina.
     *
     * Devuelve las máquinas de la misma tipología, excluyendo la propia, con el
     * número de carriles activos (D1, RF-04).
     *
     * @return list<array{id: int, code: string, model: string, machine_type: MachineType, active_slots_count: int}>
     */
    public function findCompatibleSourceMachines(int $machineId, MachineType $machineType): array;

    /**
     * Máquinas activas de las zonas operativas vivas del reponedor, con el
     * resumen de su planograma y los contadores FEFO de carril (`QA 1`, `QA 2`).
     *
     * Cada tarjeta declara `expired_slots_count`, `expiring_soon_slots_count` y
     * `can_restock`, de modo que el móvil pueda alertar sin pedir el planograma
     * completo. `can_restock` replica la regla de disponibilidad del Módulo 33:
     * cuarentena sanitaria, certificado vencido, pausa estacional, sin acceso o
     * pausa explícita desactivan la reposición (Art. II).
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

    /**
     * Aplica al carril el movimiento de una visita in situ y resuelve el lote
     * vigente conforme a `D1` (RF-01.1, RF-03.3, QA 1, Art. V.4).
     *
     * Calcula `qty_after = qty_before - retiradas + cargadas`, exige que el carril
     * esté activo y que el resultado quepa en su capacidad, y actualiza
     * `current_estimated_qty`, `current_batch_code` y `current_expiration_date`:
     *
     *   - `qty_after = 0` deja el carril sin lote ni caducidad.
     *   - Si se retiró todo el remanente y se cargó producto, adopta el lote
     *     cargado.
     *   - Si quedan unidades sanas y se añade producto, conserva la caducidad más
     *     próxima (`LEAST`) con su código de lote asociado.
     *
     * Se ejecuta en la transacción del llamador cuando ya hay una abierta —la
     * visita completa es atómica— y abre la suya propia cuando se invoca suelto.
     * La fila se bloquea con `FOR UPDATE` y nunca se borra (Art. III.1).
     *
     * @return array<string, mixed> Carril resultante con su semáforo FEFO
     *
     * @throws \VendGuard\Core\Domain\Exception\PlanogramValidationException
     *         `422 INVALID_PLANOGRAM_SLOT_BOUNDS` si el carril no está activo o
     *         las cotas del movimiento no se sostienen.
     */
    public function applyVisitSlotMovement(
        int $machineId,
        string $slotCode,
        int $qtyWithdrawnExpired,
        int $qtyWithdrawnDamaged,
        int $qtyAdded,
        ?string $loadedBatchCode = null,
        ?DateTimeImmutable $loadedExpirationDate = null,
        ?DateTimeImmutable $today = null
    ): array;
}
