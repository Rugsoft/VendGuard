<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * CashCollectionRegistrarInterface
 *
 * Puerto que el libro de visitas in situ usa para vincular una recaudación a
 * una visita `COMPLETED` sin abrir una segunda transacción (Módulo 35; RF-02.1,
 * RF-05.2, RF-05.3, D1, C4, QA 3, Art. II.2, Art. V.4).
 *
 * El servicio de visita no conoce PDO ni las reglas del Arqueo Ciego: pide el
 * alta dentro de la transacción que ya sostiene la visita, el resumen opaco de
 * un corte para el detalle y el contexto de efectivo de la ruta del reponedor.
 */
interface CashCollectionRegistrarInterface
{
    /**
     * Registra la captura dentro de una transacción que el llamante ya posee.
     *
     * `$restockVisitId` fija el vínculo `D1` cuando el payload no lo trae; si
     * ambos vienen y difieren, el alta se rechaza.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $actor
     *
     * @return array<string, mixed> Proyección del reponedor
     */
    public function registerCollectionInTransaction(
        int $machineId,
        array $payload,
        array $actor,
        ?int $restockVisitId = null
    ): array;

    /**
     * Resumen opaco del corte vinculado a la visita, o `null` si no existe.
     *
     * No incluye `expected_cashbox_eur`, `vault_counted_eur`, `discrepancy_eur`
     * ni la lectura previa del contador (`C2`, Art. V.4).
     *
     * @return array<string, mixed>|null
     */
    public function summarizeForVisit(int $restockVisitId): ?array;

    /**
     * Contexto de efectivo de la ruta del reponedor (`QA 3`).
     *
     * @return array{recent_cash_collections: list<array<string, mixed>>, cash_route_summary: array<string, mixed>}
     */
    public function routeCashContext(int $restockerId, int $limit = 5): array;
}
