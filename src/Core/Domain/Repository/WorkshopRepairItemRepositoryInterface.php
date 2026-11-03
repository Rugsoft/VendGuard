<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * WorkshopRepairItemRepositoryInterface — contrato de persistencia de la bandeja
 * de taller central (Módulo 27, Tabla 38).
 *
 * El repositorio **no decide el ciclo**: recibe estados ya validados por el
 * dominio y los escribe. Lo que sí garantiza es la forma del dato y el orden de
 * los hechos:
 *
 *   - El alta unitaria usa `ON CONFLICT (incident_replaced_part_id, unit_index)
 *     DO NOTHING`, de modo que repetir el cierre de una intervención —un reintento
 *     de red, una sincronización offline duplicada— no genera una segunda unidad
 *     ni reescribe la existente (`D1`, `C4`, `C6`, `QA 4`).
 *   - `findByIdForUpdate()` bloquea la fila con `SELECT ... FOR UPDATE` y **debe
 *     llamarse dentro de una transacción abierta por el caso de uso**: el bloqueo
 *     es la base del dictamen único y del incremento atómico del stock de
 *     furgoneta (`C5`, `QA 3`).
 *   - Los `update*` condicionan la escritura al estado esperado. Si otra sesión
 *     cambió el expediente entre la lectura y la escritura, devuelven una lista
 *     vacía en lugar de forzar un estado imposible; el caso de uso relee y
 *     responde `409`.
 *
 * Todas las lecturas enriquecidas devuelven la forma del contrato
 * `WorkshopRepairItemSummary` (§2.1): identificadores numéricos, importes como
 * `float`, sellos temporales en ISO 8601 y las etiquetas `unit_badge` y
 * `status_label` ya resueltas.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
interface WorkshopRepairItemRepositoryInterface
{
    /**
     * Abre una unidad del desglose `1..N` a partir de la línea de sustitución.
     *
     * El payload llega con los snapshots ya resueltos por el servicio (código,
     * nombre y categoría de pieza, máquina, sede, técnico y coste unitario): el
     * repositorio no consulta el catálogo para congelarlo, porque la foto debe
     * tomarse en el instante de la extracción y bajo el bloqueo de la intervención.
     *
     * Claves admitidas: `incident_replaced_part_id`, `unit_index`,
     * `total_units_in_line`, `intervention_type`, `incident_id`,
     * `preventive_order_id`, `machine_id`, `location_id`,
     * `extracted_by_technician_id`, `spare_part_id`, `is_out_of_catalog`,
     * `part_code`, `part_name`, `part_category`, `machine_code`,
     * `machine_model`, `location_name`, `technician_name`, `unit_cost`.
     *
     * @param array<string, mixed> $breakdownPayload
     * @return array<string, mixed> La fila creada con la forma del resumen, o `[]`
     *                              si esa unidad ya existía.
     */
    public function createUnitBreakdownForReplacedPart(array $breakdownPayload): array;

    /**
     * Expediente enriquecido por identificador, o `null` si no existe.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array;

    /**
     * Igual que `findById()`, pero con `SELECT ... FOR UPDATE`.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdForUpdate(int $id): ?array;

    /**
     * `PENDING_DELIVERY → RECEIVED_IN_WORKSHOP`.
     *
     * @return array<string, mixed> La fila actualizada, o `[]` si el expediente ya
     *                              no estaba pendiente de entrega.
     */
    public function updateReception(int $id, int $receivedByUserId, ?string $receptionNotes, string $receivedAt): array;

    /**
     * `RECEIVED_IN_WORKSHOP → IN_BENCH_TEST`.
     *
     * El actor de la transición no tiene columna propia en la Tabla 38: viaja al
     * `audit_log` que escribe el caso de uso en la misma transacción.
     *
     * @return array<string, mixed> La fila actualizada, o `[]` si no estaba recibida.
     */
    public function updateStartBench(int $id, int $benchStartedByUserId, string $benchStartedAt): array;

    /**
     * Sella el dictamen terminal con sus costes y su destino de reintegro.
     *
     * Claves admitidas: `status`, `bench_diagnosis`, `refurbishment_cost_eur`,
     * `net_savings_eur`, `restock_destination`, `restocked_to_technician_id`,
     * `resolved_at`, `resolved_by_user_id`.
     *
     * @param array<string, mixed> $resolutionData
     * @return array<string, mixed> La fila dictaminada, o `[]` si ya tenía dictamen.
     */
    public function updateResolution(int $id, array $resolutionData): array;

    /**
     * Piezas pendientes de entrega del técnico autenticado, para el bloque
     * `pending_workshop_deliveries` de `GET /api/technician/van-stock`.
     *
     * La consulta **no selecciona ninguna columna monetaria** (Art. V.4): el
     * técnico ve qué piezas debe llevar al taller, no cuánto valen ni cuánto
     * costó reacondicionarlas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPendingDeliveriesForTechnician(int $technicianId): array;
}
