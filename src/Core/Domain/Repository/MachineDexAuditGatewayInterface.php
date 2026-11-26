<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * MachineDexAuditGatewayInterface
 *
 * Cerrojos y hechos de otras tablas que la importación `.dex` necesita leer sin
 * poseerlas (Módulo 39; RF-03.1, RF-04.1, RF-04.3, QA-1, QA-2, QA-3, D1, C2,
 * Art. II.2 y Art. III).
 *
 * `lockMachineContext()` bloquea la fila de `machines` con `FOR UPDATE` para
 * serializar la cadena de deltas y devuelve, en la misma fotografía, el estado
 * sanitario, la cobertura del Reponedor y del Técnico, la avería térmica abierta
 * y los snapshots del actor. Los demás métodos bloquean o leen la visita M34, la
 * recaudación M35 y la incidencia M16/M36 que se van a enlazar, más las
 * cantidades por carril y el cruce del mismo día civil `Europe/Madrid` (QA-3).
 *
 * No decide ni escribe: devuelve hechos. Las guardas viven en
 * `DexAuditValidator` y la orquestación en `MachineDexAuditService`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, contrato sin dependencias de infraestructura.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
interface MachineDexAuditGatewayInterface
{
    /**
     * Máquina viva + actor + coberturas + avería térmica, bajo `FOR UPDATE OF m`.
     *
     * @return array<string, mixed>|null
     */
    public function lockMachineContext(int $machineId, int $actorId): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function lockRestockVisit(int $visitId, int $machineId): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function lockCashCollection(int $cashCollectionId, int $machineId): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function lockIncident(int $incidentId, int $machineId): ?array;

    /**
     * Recaudación vinculada o, en su defecto, la más cercana del mismo día civil
     * `Europe/Madrid` (QA-3). Incluye `expected_cashbox_eur` para el cruce
     * `VA1_VS_EXPECTED`.
     *
     * @return array<string, mixed>|null
     */
    public function findNearestCashCollectionSameDay(
        int $machineId,
        string $capturedAt,
        ?int $linkedCashCollectionId = null
    ): ?array;

    /**
     * Visita M34 `COMPLETED` vinculada o la más cercana del mismo día civil (QA-3).
     *
     * @return array<string, mixed>|null
     */
    public function findCompletedRestockVisitSameDay(
        int $machineId,
        string $capturedAt,
        ?int $linkedVisitId = null
    ): ?array;

    /**
     * Visita `COMPLETED` inmediatamente anterior a la de referencia (QA-3).
     *
     * @return array<string, mixed>|null
     */
    public function findPreviousCompletedRestockVisit(int $machineId, int $referenceVisitId): ?array;

    /**
     * @return list<array{slot_id:int, slot_code:string, qty_before:int, qty_after:int}>
     */
    public function findVisitSlotQuantities(int $visitId): array;

    /**
     * @return list<array{id:int, slot_code:string, current_estimated_qty:int}>
     */
    public function findActivePlanogramSlots(int $machineId): array;
}
