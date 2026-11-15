<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\MachineRestockVisit;
use VendGuard\Core\Domain\Model\MachineRestockVisitItem;

/**
 * MachineRestockVisitRepositoryInterface
 *
 * Contrato de persistencia del libro de visitas de reposición in situ (Módulo 34,
 * Tablas 47 y 48; RF-01.2, RF-03.3, RF-03.4, RF-05.2, D4, C1, C4, QA 3,
 * Art. III.1, Art. III.3, Art. V.4).
 *
 * El contrato **no expone `UPDATE` ni `DELETE`**: una visita y sus líneas son
 * historia inmutable (Art. III.3) y el motor las protege con
 * `prevent_restock_visit_mutation()` y `prevent_hard_delete()` (Art. III.1). Solo
 * se insertan filas, y la lectura se proyecta en dos modos:
 *
 *   - **Reponedor (`listForRestocker`, `findById(..., false)`).** Los costes
 *     `unit_cost_snapshot_eur` y `commercial_spoilage_loss_eur` no se seleccionan
 *     siquiera: la opacidad es estructural, no un filtro que alguien pueda
 *     olvidar (Art. V.4, QA 3).
 *   - **Coordinación (`listForCoordinator`, `findById(..., true)`).** Incluye el
 *     snapshot contable y los cinco KPIs ejecutivos (RF-05.2).
 *
 * `suggestLiquidationQuantities()` materializa el pre-cálculo `C4`: las unidades
 * abastecidas por lote y las mermas retiradas por producto/lote desde el
 * `confirmed_at` de la orden de carga, para precargar la liquidación del Módulo 33.
 */
interface MachineRestockVisitRepositoryInterface
{
    /**
     * Inserta la cabecera inmutable de una visita (Tabla 47).
     *
     * No abre transacción: el servicio de visita compone el alta de cabecera,
     * líneas, stock y carriles dentro de una única transacción (D4). La clave
     * `(restocker_id, client_mutation_id)` es única y el servicio consulta antes
     * con `findByClientMutationId()` para responder una repetición idempotente.
     *
     * @return MachineRestockVisit La misma visita con el `id` asignado por el motor
     */
    public function insertVisit(MachineRestockVisit $visit): MachineRestockVisit;

    /**
     * Inserta una línea inmutable de la visita (Tabla 48).
     *
     * @return MachineRestockVisitItem La misma línea con el `id` asignado
     */
    public function insertItem(MachineRestockVisitItem $item): MachineRestockVisitItem;

    /**
     * Expediente íntegro de una visita: cabecera, checklist, ticket SAT y líneas.
     *
     * @param bool $includeCosts `true` solo para Coordinación (Art. V.4, QA 3).
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $visitId, bool $includeCosts = false): ?array;

    /**
     * Visita ya registrada por `(restocker_id, client_mutation_id)`, para resolver
     * la repetición idempotente de la cola offline (D4).
     *
     * @return array<string, mixed>|null
     */
    public function findByClientMutationId(
        int $restockerId,
        string $clientMutationId,
        bool $includeCosts = false
    ): ?array;

    /**
     * Historial del propio reponedor, sin ningún campo de coste (Art. V.4).
     *
     * Filtros admitidos: `machine_id`, `status` (`COMPLETED`,
     * `ABORTED_THERMAL_BREACH` o `ALL`), `date_from`, `date_to`, `limit` y
     * `offset`. Las fechas son `Y-m-d` e incluyen el día completo.
     *
     * @param array<string, mixed> $filters
     *
     * @return list<array<string, mixed>>
     */
    public function listForRestocker(int $restockerId, array $filters = []): array;

    /**
     * Libro histórico para Coordinación con los cinco KPIs ejecutivos (RF-05.2).
     *
     * Filtros admitidos: `restocker_id`, `machine_id`, `status`, `date_from`,
     * `date_to`, `limit` y `offset`. Devuelve `visits` (con el snapshot contable
     * agregado por visita) y `kpis` con `completed_visits_count`,
     * `aborted_thermal_visits_count`, `total_units_added`,
     * `total_units_withdrawn` y `total_commercial_spoilage_loss_eur`.
     *
     * @param array<string, mixed> $filters
     *
     * @return array{visits: list<array<string, mixed>>, kpis: array{completed_visits_count: int, aborted_thermal_visits_count: int, total_units_added: int, total_units_withdrawn: int, total_commercial_spoilage_loss_eur: float}}
     */
    public function listForCoordinator(array $filters = []): array;

    /**
     * Pre-cálculo `C4` por producto y lote desde el `confirmed_at` de la orden.
     *
     * Agrupa las unidades cargadas (`SUM(qty_added)` por `loaded_batch_code`) y las
     * retiradas (`SUM(qty_withdrawn_expired + qty_withdrawn_damaged)` por
     * `withdrawn_batch_code`) de las visitas del reponedor vinculadas a la orden o
     * sin orden desde su confirmación. Devuelve una fila por producto/lote con
     * ambas cifras, lista para precargar el formulario de liquidación del Módulo 33.
     *
     * @return list<array{product_id: int, batch_code: string|null, suggested_restocked_in_machines_qty: int, suggested_withdrawn_spoilage_qty: int}>
     */
    public function suggestLiquidationQuantities(int $loadingOrderId, int $restockerId): array;
}
