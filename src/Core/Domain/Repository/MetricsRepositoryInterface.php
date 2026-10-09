<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\MetricFilter;

/**
 * MetricsRepositoryInterface
 * 
 * Contrato de repositorio de dominio para la extracción y agregación analítica de métricas.
 * Soporta consultas de MTTR en tiempo continuo 24/7 filtradas por fecha de resolución (RF-01, RF-02),
 * y desgloses multidimensionales por sede histórica, técnico resolutor, tipología de máquina y avería.
 * 
 * Reparto de responsabilidades del descuento de pausas (RF-03.1, Módulo 11)
 * -------------------------------------------------------------------------
 * El repositorio NO decide el MTTR neto: extrae la muestra bruta de resolución
 * (`TIMESTAMPDIFF(SECOND, created_at, resolved_at)`) y la media de segundos de
 * pausa `total_pending_info_seconds`, ambas en segundos. La resta, el formateo y
 * la evaluación de SLA viven en `MetricsCalculationService`, único punto donde la
 * aritmética del descuento puede auditarse y probarse de forma determinista.
 * Única excepción: `countCriticalSlaBreaches()`, donde el umbral es una decisión
 * ticket a ticket que no puede descomponerse en una media; allí la resta se
 * aplica dentro del SQL, con suelo en cero.
 */
interface MetricsRepositoryInterface
{
    /**
     * Tiempo medio BRUTO de resolución en segundos para el filtro dado, filtrando por
     * `resolved_at` (EARS 1.2) y excluyendo cancelados/duplicados.
     * 
     * Devuelve null cuando no hay muestra. El descuento de pausas PENDING_INFO todavía
     * no se aplica aquí: es `MetricsCalculationService` quien lo resta con
     * `getAveragePendingInfoSeconds()` (RF-03.1).
     * 
     * @param MetricFilter $filter
     * @param MachineType|null $machineType Restringe la muestra a una tipología (p. ej. perecederos, Art. II).
     * @return int|null
     */
    public function getGrossMttrSeconds(MetricFilter $filter, ?MachineType $machineType = null): ?int;

    /**
     * Media de segundos en estado `PENDING_INFO` descontables por cada ticket resuelto
     * del periodo (RF-03.1, RF-04.1). Es el descuento acumulado multi-pausa del
     * expediente que `MetricsCalculationService` resta del MTTR bruto.
     * 
     * Devuelve 0 cuando no hay muestra o ningún ticket del periodo acumuló pausas.
     * 
     * @param MetricFilter $filter
     * @param MachineType|null $machineType Restringe la muestra a una tipología (p. ej. perecederos, Art. II).
     * @return int
     */
    public function getAveragePendingInfoSeconds(MetricFilter $filter, ?MachineType $machineType = null): int;

    /**
     * Obtiene el total de tickets creados dentro del periodo del filtro (filtrando por created_at).
     * 
     * @param MetricFilter $filter
     * @return int
     */
    public function countCreatedTickets(MetricFilter $filter): int;

    /**
     * Obtiene el total de tickets resueltos o cerrados dentro del periodo del filtro (filtrando por resolved_at).
     * Excluye tickets en estado CANCELLED o DUPLICATE (EARS 1.4).
     * 
     * @param MetricFilter $filter
     * @return int
     */
    public function countResolvedTickets(MetricFilter $filter): int;

    /**
     * Obtiene el número total de tickets actualmente sin resolver (backlog activo: REGISTERED, ASSIGNED, IN_PROGRESS, PENDING_PARTS).
     * 
     * @return int
     */
    public function countActiveBacklog(): int;

    /**
     * Obtiene el número de tickets resueltos en el periodo que superaron su umbral de SLA
     * (4h perecederos, 24h general). Aquí el descuento de pausas se aplica dentro del SQL,
     * ticket a ticket, porque un umbral no puede descomponerse en una media (RF-03.1).
     * 
     * @param MetricFilter $filter
     * @return int
     */
    public function countCriticalSlaBreaches(MetricFilter $filter): int;

    /**
     * Desglose analítico agrupado por sede histórica (EARS 2.2).
     * 
     * Cada fila entrega la muestra bruta en segundos; el neto contractual lo compone
     * `MetricsCalculationService::getBreakdown()` aplicando el descuento de pausas.
     * 
     * @param MetricFilter $filter
     * @return list<array{
     *   location_id: int,
     *   site_code: string,
     *   location_name: string,
     *   is_active: bool,
     *   tickets_resolved: int,
     *   mttr_gross_seconds: int|null,
     *   mttr_pending_info_seconds: int,
     *   sla_target_hours: float
     * }>
     */
    public function getBreakdownByLocation(MetricFilter $filter): array;

    /**
     * Desglose analítico agrupado por técnico resolutor final (EARS 2.3).
     * 
     * @param MetricFilter $filter
     * @return list<array{
     *   technician_id: int,
     *   technician_name: string,
     *   is_active: bool,
     *   display_name: string,
     *   tickets_resolved: int,
     *   warranty_reopens: int,
     *   mttr_gross_seconds: int|null,
     *   mttr_pending_info_seconds: int
     * }>
     */
    public function getBreakdownByTechnician(MetricFilter $filter): array;

    /**
     * Desglose analítico agrupado por tipología de máquina (EARS 2.4).
     * 
     * @param MetricFilter $filter
     * @return list<array{
     *   machine_type: string,
     *   display_name: string,
     *   is_perishable: bool,
     *   tickets_resolved: int,
     *   mttr_gross_seconds: int|null,
     *   mttr_pending_info_seconds: int,
     *   sla_target_hours: float
     * }>
     */
    public function getBreakdownByMachineType(MetricFilter $filter): array;

    /**
     * Desglose analítico agrupado por categoría de avería (EARS 2.5).
     * 
     * @param MetricFilter $filter
     * @return list<array{
     *   category: string,
     *   display_name: string,
     *   tickets_resolved: int,
     *   mttr_gross_seconds: int|null,
     *   mttr_pending_info_seconds: int
     * }>
     */
    public function getBreakdownByCategory(MetricFilter $filter): array;

    /**
     * Métricas individuales para autoconsulta del técnico autenticado (RF-04).
     * 
     * Devuelve la muestra bruta de resolución para que el servicio aplique el
     * descuento de pausas y componga el MTTR neto personal (RF-03.1).
     * 
     * @param int $technicianId
     * @param MetricFilter $filter
     * @return array{
     *   technician_id: int,
     *   my_gross_mttr_seconds: int|null,
     *   my_pending_info_seconds: int,
     *   total_resolved: int,
     *   current_in_progress: int,
     *   avg_first_response_minutes: int|null,
     *   avg_first_response_formatted: string
     * }
     */
    public function getTechnicianMetrics(int $technicianId, MetricFilter $filter): array;
}
