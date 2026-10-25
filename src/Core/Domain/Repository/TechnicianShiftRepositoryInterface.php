<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\TechnicianShift;

/**
 * TechnicianShiftRepositoryInterface
 *
 * Puerto de persistencia para el ciclo de jornada y turnos de técnicos
 * (Módulos 21 y 22, contrato técnico §3.1).
 *
 * Art. III.1: la tabla de turnos no admite borrado físico. Una corrección de
 * horario entra por `saveAdjustment()`, que reescribe las marcas vigentes y deja
 * intactas las originales del trabajador.
 *
 * Dogma Vanilla: interfaz pura de dominio.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
interface TechnicianShiftRepositoryInterface
{
    public function findActiveShiftByTechnician(int $technicianId): ?TechnicianShift;

    public function findById(int $shiftId): ?TechnicianShift;

    public function startShift(int $technicianId, ?DateTimeImmutable $startedAt = null): TechnicianShift;

    public function pauseMeal(int $shiftId, ?DateTimeImmutable $pausedAt = null): TechnicianShift;

    public function resumeShift(int $shiftId, ?DateTimeImmutable $resumedAt = null): TechnicianShift;

    /**
     * @param DateTimeImmutable|null $closedAt      Hora efectiva de salida.
     * @param bool                   $isForceClosed Cierre ordenado por Coordinación.
     * @param DateTimeImmutable|null $executionTime Instante real de la operación;
     *        es la marca original del primer cierre y el reloj de auditoría.
     */
    public function closeShift(
        int $shiftId,
        int $closedBy,
        ?string $closeReason = null,
        ?DateTimeImmutable $closedAt = null,
        bool $isForceClosed = false,
        ?DateTimeImmutable $executionTime = null
    ): TechnicianShift;

    /**
     * Persiste un turno rectificado sin tocar las marcas originales del técnico
     * (Art. III.3).
     */
    public function saveAdjustment(TechnicianShift $adjustedShift): TechnicianShift;

    /**
     * Comprueba si el rango [startedAt, closedAt) se solapa con otro turno del
     * mismo técnico. Un turno abierto (`closed_at IS NULL`) se considera
     * vigente hasta el infinito.
     */
    public function findOverlappingShiftId(
        int $technicianId,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $closedAt,
        ?int $excludeShiftId = null
    ): ?int;

    /**
     * Devuelve los turnos imputados en [fromDate, toDate] (inclusive, por
     * `shift_date`), en orden cronológico.
     *
     * @return list<TechnicianShift>
     */
    public function findShiftsByDateRange(
        string $fromDate,
        string $toDate,
        ?int $technicianId = null
    ): array;

    /**
     * Devuelve los turnos abiertos (`closed_at IS NULL`) cuya `shift_date` sea
     * estrictamente anterior a $beforeDate: turnos huérfanos que nadie fichó
     * fuera (RF-04.2, Resolución QA 4).
     *
     * @return list<TechnicianShift>
     */
    public function findOrphanOpenShiftsBefore(
        string $beforeDate,
        ?int $technicianId = null
    ): array;

    /**
     * Tiempo de intervención cerrada por técnico en el intervalo [from, to].
     *
     * Suma las averías correctivas resueltas en el periodo descontando su espera
     * acumulada de sede y las órdenes preventivas completadas cuyo trabajo en
     * máquina no deriva a una avería vinculada (`linked_incident_id IS NULL`,
     * Resolución QA 1).
     *
     * @return array<int, array{intervention_seconds: int, resolved_interventions_count: int}>
     *         technicianId => métricas del periodo
     */
    public function getClosedInterventionMetricsByTechnician(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $technicianId = null
    ): array;

    /**
     * @return array<int, string> technicianId => shift_status ('ON_SHIFT'|'MEAL_BREAK'|'OFF_SHIFT')
     */
    public function getActiveShiftsMap(): array;
}
