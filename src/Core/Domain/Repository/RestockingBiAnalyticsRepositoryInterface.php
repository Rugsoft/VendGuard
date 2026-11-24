<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Application\DTO\RestockingBiFilterDto;

/**
 * RestockingBiAnalyticsRepositoryInterface
 *
 * Contrato de lectura agregada del Cuadro de Mando de Reposición, Roturas de
 * Stock y Mermas Comerciales (Módulo 38; `RF-01`–`RF-05`, `RNF-01`, `RNF-02`,
 * `C1`–`C4`, `QA-2`, `QA-4`, `QA-5`, Art. III, Art. IV).
 *
 * **Solo lee.** No existe aquí ningún método de escritura, ninguna migración y
 * ninguna vista materializada: el esquema canónico permanece intacto en 51
 * tablas, 50 tipos `ENUM` y 34 migraciones (`RNF-01`, Art. IV). Esa es la razón
 * por la que el módulo vive de los registros históricos inmutables de M22/M29/
 * M31/M32/M34/M35/M36 y no de una caché propia.
 *
 * **Consultas independientes, nunca un producto cartesiano (`RNF-02`).** Cada
 * método devuelve los agregados de **una sola fuente**, agrupados por su clave
 * natural, y `RestockingBiCalculator` los cruza en memoria PHP. Unir visitas,
 * carriles, actas, turnos, arqueos e incidencias en una sola sentencia
 * multiplicaría las filas `1:N` y falsearía todos los importes; por eso no hay
 * ningún método que lo haga.
 *
 * **Ventana y fechas de corte (`C1`).** Todas las fuentes se filtran por el
 * intervalo semiabierto `[fromAt, toExclusiveAt)` que ya resuelve
 * `RestockingBiFilterDto`, cada una por la marca de su hecho operativo en campo:
 * `completed_at` en visitas, `created_at` en actas M29, `started_at` en turnos,
 * `collected_at` en arqueos, `resolved_at` / `escalated_to_sat_at` en M36.
 *
 * **Históricos y bajas lógicas (`QA-4`, Art. III).** Las filas nunca se filtran
 * por `is_active` ni por `deleted_at`: una máquina, sede o reponedor dado de
 * baja que tenga actividad en la ventana entra en los agregados y se publica
 * con `is_active = false` para que la UI muestre la insignia *«Baja»*.
 *
 * **Térmica no segmentable (`D2`).** `fetchThermalRecords()` devuelve una lista
 * vacía cuando el filtro selecciona un reponedor: la merma APPCC del Módulo 29
 * es de ámbito máquina/SAT y no se puede atribuir a una persona.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
interface RestockingBiAnalyticsRepositoryInterface
{
    /**
     * Cabecera de máquinas con actividad en la ventana (`QA-4`).
     *
     * Incluye toda máquina con al menos una visita (`COMPLETED` o
     * `ABORTED_THERMAL_BREACH`) y, cuando la térmica es segmentable, también la
     * que solo tenga un acta M29. Se incluyen las máquinas y sedes con baja
     * lógica, publicando el estado real (`is_active = false`).
     *
     * @return list<array{
     *   machine_id: int,
     *   machine_code: string,
     *   machine_type: string,
     *   location_id: int,
     *   location_name: string,
     *   zone_id: int|null,
     *   zone_name: string|null,
     *   is_active: bool
     * }>
     */
    public function fetchMachineCatalog(RestockingBiFilterDto $filter): array;

    /**
     * Agregados de visitas por `(machine_id, restocker_id)` (M34, `D1`).
     *
     * `clean_in_stock_visits_count` se resuelve con `NOT EXISTS` sobre las líneas
     * con `qty_before = 0`, de modo que una visita `COMPLETED` sin líneas cuenta
     * como *In-Stock* y una visita con varias roturas cuenta una sola vez. Los
     * abortos térmicos se cuentan aparte y nunca entran en el denominador.
     *
     * @return list<array{
     *   machine_id: int,
     *   restocker_id: int,
     *   completed_visits_count: int,
     *   clean_in_stock_visits_count: int,
     *   thermal_aborted_visits_count: int,
     *   completed_duration_seconds_sum: float
     * }>
     */
    public function fetchVisitHeaders(RestockingBiFilterDto $filter): array;

    /**
     * Agregados de carriles por `(machine_id, product_id)` (M34 + M32).
     *
     * `low_threshold_slots_count` exige `machine_planogram_slots.product_id =
     * machine_restock_visit_items.product_id`: tras un cambio de producto en el
     * carril, el umbral vigente no se aplica a una referencia que ya no ocupa
     * ese hueco (`QA-5`).
     *
     * @return list<array{
     *   machine_id: int,
     *   product_id: int,
     *   audited_slots_count: int,
     *   stockout_slots_count: int,
     *   low_threshold_slots_count: int,
     *   added_units: int,
     *   expired_units: int,
     *   damaged_units: int,
     *   expired_loss_eur: float,
     *   damaged_loss_eur: float,
     *   commercial_spoilage_loss_eur: float
     * }>
     */
    public function fetchSlotItemsByMachineProduct(RestockingBiFilterDto $filter): array;

    /**
     * Catálogo de productos con carriles auditados en la ventana (M32 + M34).
     *
     * Devuelve la identidad vigente del producto (SKU, nombre, categoría y
     * estado) para que `by_product` y la Sección 4 del CSV no inventen etiquetas.
     * Incluye los productos con baja lógica que conservan actividad, publicando
     * `is_active = false` (`QA-4`, Art. III).
     *
     * @return list<array{
     *   product_id: int,
     *   sku: string,
     *   name: string,
     *   category: string,
     *   is_active: bool
     * }>
     */
    public function fetchProductCatalog(RestockingBiFilterDto $filter): array;

    /**
     * Agregados de carriles por `restocker_id`, sin producto (M34, `RF-05`).
     *
     * @return list<array{
     *   restocker_id: int,
     *   audited_slots_count: int,
     *   stockout_slots_count: int,
     *   low_threshold_slots_count: int,
     *   added_units: int,
     *   expired_units: int,
     *   damaged_units: int,
     *   expired_loss_eur: float,
     *   damaged_loss_eur: float,
     *   commercial_spoilage_loss_eur: float
     * }>
     */
    public function fetchSlotItemsByRestocker(RestockingBiFilterDto $filter): array;

    /**
     * Actas sanitarias térmicas por máquina (M29, `QA-2`).
     *
     * Cuenta **todas** las actas del periodo —incluidas las de máquina vacía
     * (`empty_machine_confirmed = TRUE`) y los tres orígenes de cuarentena—.
     * Devuelve una lista vacía cuando `restocker_id` está seleccionado (`D2`).
     *
     * @return list<array{
     *   machine_id: int,
     *   records_count: int,
     *   discarded_units_count: int,
     *   estimated_merchandise_loss_eur: float
     * }>
     */
    public function fetchThermalRecords(RestockingBiFilterDto $filter): array;

    /**
     * Turnos válidos por reponedor (M22/M31, `C3`).
     *
     * Excluye los anulados (`status = 'OFF_SHIFT' AND closed_at IS NOT NULL AND
     * closed_at = started_at`) y a quien no tiene rol `RESTOCKER`. Devuelve una
     * lista vacía con filtro territorial: un turno no es segmentable por zona.
     *
     * @return list<array{technician_id: int, valid_shifts_count: int}>
     */
    public function fetchValidShifts(RestockingBiFilterDto $filter): array;

    /**
     * Arqueos de efectivo por reponedor (M35, `RF-05.3`).
     *
     * Incluye las recaudaciones vinculadas a una visita y las independientes
     * (`restock_visit_id IS NULL`), separando bolsas cerradas, descuadres y
     * pendientes, y sumando el descuadre absoluto solo de las cerradas.
     *
     * @return list<array{
     *   restocker_id: int,
     *   reconciled_ok_count: int,
     *   discrepancy_flagged_count: int,
     *   pending_audit_count: int,
     *   total_abs_discrepancy_eur: float
     * }>
     */
    public function fetchCashCollections(RestockingBiFilterDto $filter): array;

    /**
     * Sinergia Nivel 1 y escalados al SAT por reponedor (M36, `C4`, `QA-5`).
     *
     * Solo cuenta usuarios con rol `RESTOCKER` e incidencias vivas
     * (`deleted_at IS NULL`): L1 resueltas efectivas por `resolved_at` y
     * escalados por `escalated_to_sat_at`, en columnas separadas para que
     * `summary` sea exactamente la suma de `by_restocker`.
     *
     * @return list<array{
     *   restocker_id: int,
     *   l1_resolved_count: int,
     *   escalated_to_sat_count: int
     * }>
     */
    public function fetchL1Incidents(RestockingBiFilterDto $filter): array;

    /**
     * Catálogos estables de filtros (`QA-4`, Art. III).
     *
     * Cada catálogo publica (a) las entidades activas, (b) las inactivas con
     * actividad en la ventana y (c) la entidad seleccionada en el filtro si
     * existe en base de datos, para que el selector no pierda su valor al
     * cambiar el rango de fechas.
     *
     * @return array{
     *   zones: list<array{id: int, code: string, name: string, is_active: bool}>,
     *   locations: list<array{id: int, site_code: string, name: string, zone_id: int|null, is_active: bool}>,
     *   restockers: list<array{id: int, operator_code: string|null, name: string, is_active: bool}>
     * }
     */
    public function fetchAvailableFilters(RestockingBiFilterDto $filter): array;
}
