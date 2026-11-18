<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Enum\CashCollectionStatus;
use VendGuard\Core\Domain\Model\MachineCashCollection;

/**
 * MachineCashCollectionRepositoryInterface
 *
 * Contrato de persistencia del libro de recaudaciones y arqueos (Módulo 35,
 * Tabla 49 `machine_cash_collections`; RF-01.1–RF-01.3, RF-02.2, RF-04.1,
 * RF-04.2, RF-05.2, RF-05.3, D1, D2, D4, C1, QA 1, QA 3, Art. III.1,
 * Art. III.3, Art. V.4).
 *
 * **Solo se inserta y se avanza el ciclo de custodia.** La captura en ruta es
 * inmutable desde el `INSERT` (Art. III.3) y una fila arqueada no vuelve a
 * tocarse: no existe aquí ningún método para reescribir datos de ruta ni para
 * borrar historia (Art. III.1). Las dos únicas mutaciones legítimas son las
 * transiciones `SEALED_IN_ROUTE → DELIVERED_TO_VAULT` y
 * `→ RECONCILED_OK | DISCREPANCY_FLAGGED`.
 *
 * **Idempotencia y precinto (D4, C1).** `findByClientMutationId()` resuelve la
 * repetición de la cola offline por `(restocker_id, client_mutation_id)` y
 * `findByBagSealCode()` detecta un precinto ya consumido. La repetición solo es
 * replay si pertenece a la misma máquina; el servicio rechaza con `409` una
 * mutación reutilizada en otra. Ambas son consultas previas: el índice único
 * del motor sigue siendo la última palabra ante una carrera.
 *
 * **Arqueo Ciego (C2, Art. V.4).** El repositorio devuelve entidades, nunca
 * arrays financieros sueltos; cada superficie decide su proyección con
 * `toRestockerArray()`, `toCoordinatorArray()` o `toReceptionReceiptArray()`.
 */
interface MachineCashCollectionRepositoryInterface
{
    /**
     * Reserva el siguiente código `MCC-AAAA-NNNN` del año en curso.
     *
     * Debe invocarse dentro de la misma transacción que el `insert()`: la
     * implementación toma el cerrojo de transacción del año y calcula el hueco
     * con el máximo ya persistido, de modo que dos capturas simultáneas no
     * reciben nunca el mismo número y un rollback no deja huecos (H-5).
     */
    public function reserveCollectionCode(): string;

    /**
     * Inserta la captura en ruta y devuelve la fila hidratada con su `id` y sus
     * sellos del motor.
     *
     * No abre transacción: el servicio compone el alta, la transición del
     * reintegro y el registro de auditoría en una sola transacción (D3).
     */
    public function insert(MachineCashCollection $collection): MachineCashCollection;

    /**
     * Expediente completo de una recaudación, con la sede resuelta.
     */
    public function findById(int $collectionId): ?MachineCashCollection;

    /**
     * Recaudación ya registrada por el reponedor con esa mutación (D4).
     *
     * La consulta no filtra por máquina para que el servicio pueda distinguir
     * un replay legítimo de una mutación reutilizada en otra captura.
     */
    public function findByClientMutationId(int $restockerId, string $clientMutationId): ?MachineCashCollection;

    /**
     * Recaudación histórica que consumió el precinto (C1), si existe.
     */
    public function findByBagSealCode(string $bagSealCode): ?MachineCashCollection;

    /**
     * Recaudación vinculada a una visita in situ (`D1`), si existe.
     */
    public function findByRestockVisitId(int $restockVisitId): ?MachineCashCollection;

    /**
     * Última lectura de contador de la máquina, sea cual sea su estado de
     * custodia: es la referencia privada del arqueo ciego (D2, C2).
     */
    public function findLastForMachine(int $machineId): ?MachineCashCollection;

    /**
     * Historial del propio reponedor, sin datos financieros privados.
     *
     * Filtros: `machine_id`, `status`, `bag_seal_code`, `date_from`,
     * `date_to`, `limit` y `offset`.
     *
     * @param array<string, mixed> $filters
     *
     * @return list<MachineCashCollection>
     */
    public function listForRestocker(int $restockerId, array $filters = []): array;

    /**
     * Libro completo de Caja Central con los seis KPIs reactivos al filtro.
     *
     * Filtros: `restocker_id`, `machine_id`, `status`, `bag_seal_code`,
     * `date_from`, `date_to`, `limit` y `offset`.
     *
     * @param array<string, mixed> $filters
     *
     * @return array{collections: list<MachineCashCollection>, kpis: array{pending_vault_bags_count: int, reconciled_ok_count: int, discrepancy_flagged_count: int, total_vault_counted_eur: float, net_discrepancy_eur: float, total_route_cash_deployed_eur: float}}
     */
    public function listForCoordinator(array $filters = []): array;

    /**
     * Libro completo de Caja Central para la exportación CSV, sin paginación.
     *
     * `listForCoordinator()` conserva el tope de página de la consola; la
     * exportación debe entregar todas las filas del filtro activo (QA 4), sin
     * que el tamaño de la página recorte el fichero en silencio. Los filtros
     * `limit` y `offset` se ignoran a propósito.
     *
     * @param array<string, mixed> $filters Los mismos filtros del libro
     *
     * @return list<MachineCashCollection>
     */
    public function listAllForCoordinator(array $filters = []): array;

    /**
     * Resumen de efectivo movilizado en la ruta del reponedor (`QA 3`).
     *
     * Solo suma magnitudes que el propio reponedor declaró (cambio cargado y
     * conserjería) y cuenta las bolsas aún pendientes de entrega en cámara. El
     * contador de pendientes es un subconjunto del filtro activo (máquina,
     * precinto, fechas y estado) y solo cuenta las bolsas que siguen en
     * `SEALED_IN_ROUTE`. No incluye `expected_cashbox_eur` ni ningún dato del
     * arqueo (C2, Art. V.4).
     *
     * @param array<string, mixed> $filters `machine_id`, `status`, `bag_seal_code`, `date_from`, `date_to`
     *
     * @return array{total_collections_count: int, total_change_tubes_loaded_eur: float, total_reception_cash_delivered_eur: float, pending_vault_bags_count: int, last_collected_at: string|null}
     */
    public function summarizeForRestocker(int $restockerId, array $filters = []): array;

    /**
     * Recepción física de la bolsa intacta en Caja Central (RF-04.1, D4).
     *
     * Devuelve `null` cuando la fila ya no estaba en `SEALED_IN_ROUTE`; el
     * servicio lo traduce a `409 INVALID_CASH_COLLECTION_STATE_TRANSITION`.
     */
    public function markDeliveredToVault(
        int $collectionId,
        int $coordinatorId,
        ?string $deliveredAt = null
    ): ?MachineCashCollection;

    /**
     * Sella el arqueo terminal (RF-04.2, D2, D4, Art. V.1).
     *
     * Si la bolsa aún estaba en ruta, sella también su recepción en cámara en el
     * mismo instante (`«Recepcionar y Arquear»`). Devuelve `null` cuando la fila
     * ya era terminal; el servicio lo traduce a
     * `409 CASH_COLLECTION_ALREADY_RECONCILED`.
     *
     * @param CashCollectionStatus $status Solo `RECONCILED_OK` o `DISCREPANCY_FLAGGED`
     */
    public function reconcile(
        int $collectionId,
        int $coordinatorId,
        string $coordinatorName,
        float $vaultCountedEur,
        float $discrepancyEur,
        CashCollectionStatus $status,
        ?string $discrepancyJustification = null,
        ?string $reconciledAt = null
    ): ?MachineCashCollection;
}
