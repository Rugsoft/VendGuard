<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\PreventiveOrder;

/**
 * PreventiveOrderRepositoryInterface
 * 
 * Contrato de persistencia para órdenes de mantenimiento preventivo y checklists sanitarios.
 */
interface PreventiveOrderRepositoryInterface
{
    /**
     * Da de alta una nueva orden preventiva.
     *
     * @param array<string, mixed> $data
     * @return PreventiveOrder
     */
    public function create(array $data): PreventiveOrder;

    /**
     * Recupera una orden preventiva por su identificador primario.
     *
     * @param int $id
     * @param bool $allowCancelled Si es true, incluye órdenes canceladas lógicamente.
     * @return PreventiveOrder|null
     */
    public function findById(int $id, bool $allowCancelled = true): ?PreventiveOrder;

    /**
     * Recupera una orden preventiva por su código alfanumérico (ej: PREV-2026-0001).
     *
     * @param string $orderCode
     * @param bool $allowCancelled
     * @return PreventiveOrder|null
     */
    public function findByCode(string $orderCode, bool $allowCancelled = true): ?PreventiveOrder;

    /**
     * Comprueba si una máquina ya tiene una orden preventiva activa o en curso
     * ('PENDING_ASSIGNMENT', 'SCHEDULED', 'IN_INSPECTION').
     *
     * @param int $machineId
     * @return bool
     */
    public function hasActiveOrPendingOrder(int $machineId): bool;

    /**
     * Asigna una orden preventiva a un técnico de ruta fijando fecha programada.
     *
     * @param int $orderId
     * @param int $technicianId
     * @param string $scheduledDate
     * @return bool
     */
    public function assignTechnician(int $orderId, int $technicianId, string $scheduledDate): bool;

    /**
     * Autoasignación in situ (Visita Oportunista) de una orden en PENDING_ASSIGNMENT (EARS 2.3).
     * Si la orden ya no está en PENDING_ASSIGNMENT, debe lanzar PreventiveOrderAlreadyAssignedException.
     *
     * @param int $orderId
     * @param int $technicianId
     * @return bool
     */
    public function claimOrderOpportunistically(int $orderId, int $technicianId): bool;

    /**
     * Transiciona una orden preventiva a estado 'IN_INSPECTION'.
     *
     * @param int $orderId
     * @param int $technicianId
     * @return bool
     */
    public function startInspection(int $orderId, int $technicianId): bool;

    /**
     * Finaliza la orden preventiva guardando el resultado, temperatura, estado de cuarentena y correctivo vinculado.
     *
     * @param int $orderId
     * @param string $result 'CONFORME', 'CONFORME_CON_OBSERVACIONES', 'NO_CONFORME', 'NO_EVALUABLE_POR_CAUSA_EXTERNA'
     * @param float|null $temperatureMeasured
     * @param bool $isQuarantineTriggered
     * @param int|null $linkedIncidentId
     * @param string|null $notes
     * @return bool
     */
    public function completeOrder(
        int $orderId,
        string $result,
        ?float $temperatureMeasured,
        bool $isQuarantineTriggered,
        ?int $linkedIncidentId = null,
        ?string $notes = null
    ): bool;

    /**
     * Cancela lógicamente una orden preventiva preservando la trazabilidad histórica (Art. III).
     *
     * @param int $orderId
     * @param string $reason
     * @return bool
     */
    public function softCancel(int $orderId, string $reason): bool;

    /**
     * Obtiene el listado de órdenes preventivas para la vista de Coordinación con filtros y paginación.
     *
     * @param array<string, mixed> $filters
     * @return array<PreventiveOrder>
     */
    public function findForCoordinatorList(array $filters = []): array;

    /**
     * Cuenta el total de órdenes que coinciden con los filtros para paginación.
     *
     * @param array<string, mixed> $filters
     * @return int
     */
    public function countForCoordinatorList(array $filters = []): int;

    /**
     * Obtiene las órdenes para la ruta móvil de un técnico:
     * - Sus órdenes programadas (`SCHEDULED`, `IN_INSPECTION`).
     * - Opcionalmente, órdenes pendientes (`PENDING_ASSIGNMENT`) en la misma sede si se proporciona locationId.
     *
     * @param int $technicianId
     * @param int|null $locationId
     * @return array<PreventiveOrder>
     */
    public function findForTechnicianRoute(int $technicianId, ?int $locationId = null): array;

    /**
     * Genera el resumen métrico de cumplimiento preventivo para el dashboard de Coordinación.
     *
     * @return array<string, mixed>
     */
    public function getDashboardSummary(): array;

    /**
     * Marca como EXPIRED las órdenes preventivas pendientes o programadas cuya fecha límite ha expirado (EARS 2.4).
     *
     * @return int Número de órdenes actualizadas a EXPIRED
     */
    public function expireOverdueOrders(): int;
}
