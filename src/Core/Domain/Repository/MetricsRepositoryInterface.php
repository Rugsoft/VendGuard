<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\Model\MttrMetric;

/**
 * MetricsRepositoryInterface
 * 
 * Contrato de repositorio de dominio para la extracción y agregación analítica de métricas.
 * Soporta consultas de MTTR en tiempo continuo 24/7 filtradas por fecha de resolución (RF-01, RF-02),
 * y desgloses multidimensionales por sede histórica, técnico resolutor, tipología de máquina y avería.
 */
interface MetricsRepositoryInterface
{
    /**
     * Calcula el MTTR global para el filtro temporal dado (filtrando por resolved_at, EARS 1.2).
     * 
     * @param MetricFilter $filter
     * @return MttrMetric
     */
    public function getGlobalMttr(MetricFilter $filter): MttrMetric;

    /**
     * Calcula el MTTR específico para máquinas de alimentos perecederos (PERISHABLE_FOOD, Art. II).
     * 
     * @param MetricFilter $filter
     * @return MttrMetric
     */
    public function getPerishableMttr(MetricFilter $filter): MttrMetric;

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
     * Obtiene el número de tickets resueltos en el periodo que superaron su umbral de SLA (4h perecederos, 24h general).
     * 
     * @param MetricFilter $filter
     * @return int
     */
    public function countCriticalSlaBreaches(MetricFilter $filter): int;

    /**
     * Desglose analítico agrupado por sede histórica (EARS 2.2).
     * 
     * @param MetricFilter $filter
     * @return list<array{
     *   location_id: int,
     *   site_code: string,
     *   location_name: string,
     *   is_active: bool,
     *   tickets_resolved: int,
     *   mttr_minutes: int|null,
     *   mttr_formatted: string,
     *   mttr_hours: float|null,
     *   sla_target_hours: float,
     *   sla_status: string
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
     *   mttr_minutes: int|null,
     *   mttr_formatted: string,
     *   mttr_hours: float|null
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
     *   mttr_minutes: int|null,
     *   mttr_formatted: string,
     *   mttr_hours: float|null,
     *   sla_target_hours: float,
     *   sla_status: string
     * }>
     */
    public function getBreakdownByMachineType(MetricFilter $filter): array;

    /**
     * Desglose analítico agrupado por categoría de avería (EARS 2.5).
     * 
     * @param MetricFilter $filter
     * @return list<array{
     *   category: string,
     *   tickets_resolved: int,
     *   mttr_minutes: int|null,
     *   mttr_formatted: string
     * }>
     */
    public function getBreakdownByCategory(MetricFilter $filter): array;

    /**
     * Métricas individuales para autoconsulta del técnico autenticado (RF-04).
     * 
     * @param int $technicianId
     * @param MetricFilter $filter
     * @return array{
     *   technician_id: int,
     *   my_mttr: MttrMetric,
     *   total_resolved: int,
     *   current_in_progress: int,
     *   avg_first_response_minutes: int|null,
     *   avg_first_response_formatted: string
     * }
     */
    public function getTechnicianMetrics(int $technicianId, MetricFilter $filter): array;
}
