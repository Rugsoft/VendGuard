<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoTechnicianShiftRepository
 *
 * Implementación PDO nativa de persistencia para el ciclo de jornada y turnos
 * de técnicos (Módulos 21 y 22) sobre PostgreSQL (Supabase).
 *
 * Invariantes de persistencia:
 *   - `shift_date` se imputa en `Europe/Madrid` a partir del inicio efectivo,
 *     de modo que un turno nocturno pertenece al día en que se fichó (RF-01.4).
 *   - El acumulador `meal_break_seconds` se reescribe en cada reanudación y
 *     cierre: nunca se reconstruye leyendo la bitácora (RF-01.2).
 *   - `original_started_at`, `original_closed_at` y `original_meal_break_seconds`
 *     solo se sellan si aún están vacíos (`COALESCE`), así que ninguna
 *     rectificación ni cierre forzado puede perder el primer fichaje del
 *     trabajador (Art. III.3).
 *
 * Dogma Vanilla: consultas indexadas, cerrojos `FOR UPDATE` en las
 * transiciones de estado y cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoTechnicianShiftRepository implements TechnicianShiftRepositoryInterface
{
    private const INSERT_SHIFT_SQL = <<<'SQL'
        INSERT INTO technician_shifts (
            technician_id,
            shift_date,
            status,
            started_at,
            meal_break_seconds,
            original_started_at
        ) VALUES (
            :technician_id,
            :shift_date,
            :status,
            :started_at,
            :meal_break_seconds,
            :original_started_at
        )
        RETURNING *
        SQL;

    private const CLOSE_SHIFT_SQL = <<<'SQL'
        UPDATE technician_shifts
           SET status = :status,
               closed_at = :closed_at,
               closed_by = :closed_by,
               close_reason = :close_reason,
               meal_break_seconds = :meal_break_seconds,
               original_closed_at = COALESCE(original_closed_at, :original_closed_at),
               original_meal_break_seconds = COALESCE(original_meal_break_seconds, :original_meal_break_seconds),
               is_force_closed = :is_force_closed,
               is_adjusted = :is_adjusted,
               adjusted_at = :adjusted_at,
               adjusted_by = :adjusted_by,
               adjustment_reason = :adjustment_reason
         WHERE id = :id
         RETURNING *
        SQL;

    private const SAVE_ADJUSTMENT_SQL = <<<'SQL'
        UPDATE technician_shifts
           SET started_at = :started_at,
               closed_at = :closed_at,
               shift_date = :shift_date,
               meal_break_seconds = :meal_break_seconds,
               original_started_at = COALESCE(original_started_at, :original_started_at),
               original_closed_at = COALESCE(original_closed_at, :original_closed_at),
               original_meal_break_seconds = COALESCE(original_meal_break_seconds, :original_meal_break_seconds),
               is_adjusted = :is_adjusted,
               adjusted_at = :adjusted_at,
               adjusted_by = :adjusted_by,
               adjustment_reason = :adjustment_reason
         WHERE id = :id
         RETURNING *
        SQL;

    /**
     * Métricas de intervención cerrada por técnico (contrato técnico §4.1).
     *
     * Las averías correctivas descuentan la espera acumulada de sede
     * (`total_pending_info_seconds`); las órdenes preventivas solo suman tiempo
     * de máquina cuando no derivaron en una avería vinculada (Resolución QA 1).
     */
    private const INTERVENTION_METRICS_SQL = <<<'SQL'
        -- Los dos filtros opcionales por técnico se inyectan como marcadores.
        WITH resolved_incidents AS (
            SELECT i.assigned_technician_id AS technician_id,
                   GREATEST(
                       0,
                       EXTRACT(EPOCH FROM (i.resolved_at - i.started_at))::BIGINT
                       - GREATEST(0, i.total_pending_info_seconds)
                   ) AS intervention_seconds,
                   1 AS resolved_count
              FROM incidents i
             WHERE i.deleted_at IS NULL
               AND i.assigned_technician_id IS NOT NULL
               AND i.status::text IN ('RESOLVED', 'CLOSED')
               AND i.started_at IS NOT NULL
               AND i.resolved_at BETWEEN :from_incident AND :to_incident%s
        ),
        completed_preventives AS (
            SELECT po.assigned_technician_id AS technician_id,
                   CASE
                       WHEN po.started_at IS NOT NULL AND po.linked_incident_id IS NULL
                           THEN GREATEST(
                               0,
                               EXTRACT(EPOCH FROM (po.completed_at - po.started_at))::BIGINT
                           )
                       ELSE 0
                   END AS intervention_seconds,
                   1 AS resolved_count
              FROM preventive_orders po
             WHERE po.deleted_at IS NULL
               AND po.assigned_technician_id IS NOT NULL
               AND po.status::text = 'COMPLETED'
               AND po.completed_at BETWEEN :from_preventive AND :to_preventive%s
        )
        SELECT technician_id,
               SUM(intervention_seconds)::BIGINT AS intervention_seconds,
               SUM(resolved_count)::BIGINT AS resolved_interventions_count
          FROM (
                SELECT technician_id, intervention_seconds, resolved_count FROM resolved_incidents
                UNION ALL
                SELECT technician_id, intervention_seconds, resolved_count FROM completed_preventives
               ) AS metrics
         GROUP BY technician_id
         ORDER BY technician_id ASC
        SQL;

    private readonly PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    public function findActiveShiftByTechnician(int $technicianId): ?TechnicianShift
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM technician_shifts
              WHERE technician_id = :technician_id
                AND closed_at IS NULL
              ORDER BY id DESC
              LIMIT 1'
        );

        $stmt->execute([':technician_id' => $technicianId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? TechnicianShift::fromDatabaseRow($row) : null;
    }

    public function findById(int $shiftId): ?TechnicianShift
    {
        $stmt = $this->pdo->prepare('SELECT * FROM technician_shifts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $shiftId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? TechnicianShift::fromDatabaseRow($row) : null;
    }

    public function startShift(int $technicianId, ?DateTimeImmutable $startedAt = null): TechnicianShift
    {
        // Idempotencia: un técnico no puede tener dos sesiones abiertas; si ya
        // tiene una, se devuelve la vigente (uq_tech_shifts_single_active).
        $active = $this->findActiveShiftByTechnician($technicianId);
        if ($active !== null) {
            return $active;
        }

        $shift = TechnicianShift::start($technicianId, $startedAt);

        $stmt = $this->pdo->prepare(self::INSERT_SHIFT_SQL);
        $stmt->execute([
            ':technician_id' => $shift->technicianId(),
            ':shift_date' => $shift->shiftDate(),
            ':status' => $shift->status()->value,
            ':started_at' => $this->timestamp($shift->startedAt()),
            ':meal_break_seconds' => $shift->mealBreakSeconds(),
            ':original_started_at' => $this->timestamp($shift->originalStartedAt()),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Error al iniciar turno de técnico en la base de datos.');
        }

        return TechnicianShift::fromDatabaseRow($row);
    }

    public function pauseMeal(int $shiftId, ?DateTimeImmutable $pausedAt = null): TechnicianShift
    {
        $shift = $this->lockShift($shiftId);
        $updated = $shift->pauseMeal($pausedAt);

        // `meal_break_ended_at` describe el cierre de la última pausa: al abrir
        // una nueva deja de haber un tramo cerrado vigente y conservarlo rompería
        // la coherencia temporal (`chk_tech_shifts_meal_order`). La pausa
        // acumulada vive en `meal_break_seconds`, no en este par de marcas.
        $stmt = $this->pdo->prepare(
            'UPDATE technician_shifts
                SET status = :status,
                    meal_break_started_at = :meal_break_started_at,
                    meal_break_ended_at = NULL
              WHERE id = :id
              RETURNING *'
        );

        $stmt->execute([
            ':id' => $shiftId,
            ':status' => $updated->status()->value,
            ':meal_break_started_at' => $this->timestamp($updated->mealBreakStartedAt()),
        ]);

        return $this->requireUpdatedRow($stmt, $shiftId);
    }

    public function resumeShift(int $shiftId, ?DateTimeImmutable $resumedAt = null): TechnicianShift
    {
        $shift = $this->lockShift($shiftId);
        $updated = $shift->resume($resumedAt);

        // El acumulador de pausa se persiste al reanudar: es la única fuente de
        // verdad de las horas efectivas y no se reconstruye desde la bitácora.
        $stmt = $this->pdo->prepare(
            'UPDATE technician_shifts
                SET status = :status,
                    meal_break_started_at = :meal_break_started_at,
                    meal_break_ended_at = :meal_break_ended_at,
                    meal_break_seconds = :meal_break_seconds
              WHERE id = :id
              RETURNING *'
        );

        $stmt->execute([
            ':id' => $shiftId,
            ':status' => $updated->status()->value,
            ':meal_break_started_at' => $this->timestamp($updated->mealBreakStartedAt()),
            ':meal_break_ended_at' => $this->timestamp($updated->mealBreakEndedAt()),
            ':meal_break_seconds' => $updated->mealBreakSeconds(),
        ]);

        return $this->requireUpdatedRow($stmt, $shiftId);
    }

    public function closeShift(
        int $shiftId,
        int $closedBy,
        ?string $closeReason = null,
        ?DateTimeImmutable $closedAt = null,
        bool $isForceClosed = false,
        ?DateTimeImmutable $executionTime = null
    ): TechnicianShift {
        $shift = $this->lockShift($shiftId);
        $updated = $shift->close($closedBy, $closeReason, $closedAt, $isForceClosed, $executionTime);

        $stmt = $this->pdo->prepare(self::CLOSE_SHIFT_SQL);
        $stmt->execute([
            ':id' => $shiftId,
            ':status' => $updated->status()->value,
            ':closed_at' => $this->timestamp($updated->closedAt()),
            ':closed_by' => $updated->closedBy(),
            ':close_reason' => $updated->closeReason(),
            ':meal_break_seconds' => $updated->mealBreakSeconds(),
            ':original_closed_at' => $this->timestamp($updated->originalClosedAt()),
            ':original_meal_break_seconds' => $updated->originalMealBreakSeconds(),
            ':is_force_closed' => $this->boolean($updated->isForceClosed()),
            ':is_adjusted' => $this->boolean($updated->isAdjusted()),
            ':adjusted_at' => $this->timestamp($updated->adjustedAt()),
            ':adjusted_by' => $updated->adjustedBy(),
            ':adjustment_reason' => $updated->adjustmentReason(),
        ]);

        return $this->requireUpdatedRow($stmt, $shiftId);
    }

    public function saveAdjustment(TechnicianShift $adjustedShift): TechnicianShift
    {
        $shiftId = $adjustedShift->id();
        if ($shiftId === null) {
            throw new RuntimeException('No se puede rectificar un turno que aún no existe en la base de datos.');
        }

        $stmt = $this->pdo->prepare(self::SAVE_ADJUSTMENT_SQL);
        $stmt->execute([
            ':id' => $shiftId,
            ':started_at' => $this->timestamp($adjustedShift->startedAt()),
            ':closed_at' => $this->timestamp($adjustedShift->closedAt()),
            ':shift_date' => $adjustedShift->shiftDate(),
            ':meal_break_seconds' => $adjustedShift->mealBreakSeconds(),
            ':original_started_at' => $this->timestamp($adjustedShift->originalStartedAt()),
            ':original_closed_at' => $this->timestamp($adjustedShift->originalClosedAt()),
            ':original_meal_break_seconds' => $adjustedShift->originalMealBreakSeconds(),
            ':is_adjusted' => $this->boolean($adjustedShift->isAdjusted()),
            ':adjusted_at' => $this->timestamp($adjustedShift->adjustedAt()),
            ':adjusted_by' => $adjustedShift->adjustedBy(),
            ':adjustment_reason' => $adjustedShift->adjustmentReason(),
        ]);

        return $this->requireUpdatedRow($stmt, $shiftId);
    }

    public function findOverlappingShiftId(
        int $technicianId,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $closedAt,
        ?int $excludeShiftId = null
    ): ?int {
        $sql = 'SELECT id FROM technician_shifts
                 WHERE technician_id = :technician_id
                   AND started_at < :closed_at
                   AND (closed_at IS NULL OR closed_at > :started_at)';

        $params = [
            ':technician_id' => $technicianId,
            ':started_at' => $this->timestamp($startedAt),
            ':closed_at' => $this->timestamp($closedAt),
        ];

        if ($excludeShiftId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeShiftId;
        }

        $sql .= ' ORDER BY started_at ASC, id ASC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $conflictId = $stmt->fetchColumn();

        return $conflictId === false ? null : (int)$conflictId;
    }

    /**
     * @return list<TechnicianShift>
     */
    public function findShiftsByDateRange(
        string $fromDate,
        string $toDate,
        ?int $technicianId = null
    ): array {
        $sql = 'SELECT * FROM technician_shifts
                 WHERE shift_date BETWEEN :from_date AND :to_date';
        $params = [
            ':from_date' => $fromDate,
            ':to_date' => $toDate,
        ];

        if ($technicianId !== null) {
            $sql .= ' AND technician_id = :technician_id';
            $params[':technician_id'] = $technicianId;
        }

        $sql .= ' ORDER BY shift_date ASC, started_at ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->hydrateMany($stmt);
    }

    /**
     * @return list<TechnicianShift>
     */
    public function findOrphanOpenShiftsBefore(
        string $beforeDate,
        ?int $technicianId = null
    ): array {
        $sql = 'SELECT * FROM technician_shifts
                 WHERE closed_at IS NULL
                   AND shift_date < :before_date';
        $params = [':before_date' => $beforeDate];

        if ($technicianId !== null) {
            $sql .= ' AND technician_id = :technician_id';
            $params[':technician_id'] = $technicianId;
        }

        $sql .= ' ORDER BY shift_date ASC, started_at ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->hydrateMany($stmt);
    }

    /**
     * @return array<int, array{intervention_seconds: int, resolved_interventions_count: int}>
     */
    public function getClosedInterventionMetricsByTechnician(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $technicianId = null
    ): array {
        $params = [
            ':from_incident' => $this->timestamp($from),
            ':to_incident' => $this->timestamp($to),
            ':from_preventive' => $this->timestamp($from),
            ':to_preventive' => $this->timestamp($to),
        ];

        if ($technicianId !== null) {
            $params[':incident_technician'] = $technicianId;
            $params[':preventive_technician'] = $technicianId;
        }

        $sql = sprintf(
            self::INTERVENTION_METRICS_SQL,
            $technicianId !== null ? "\n               AND i.assigned_technician_id = :incident_technician" : '',
            $technicianId !== null ? "\n               AND po.assigned_technician_id = :preventive_technician" : ''
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $metrics = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $metrics[(int)$row['technician_id']] = [
                'intervention_seconds' => (int)$row['intervention_seconds'],
                'resolved_interventions_count' => (int)$row['resolved_interventions_count'],
            ];
        }

        return $metrics;
    }

    /**
     * @return array<int, string> technicianId => shift_status ('ON_SHIFT'|'MEAL_BREAK'|'OFF_SHIFT')
     */
    public function getActiveShiftsMap(): array
    {
        $stmt = $this->pdo->query(
            'SELECT technician_id, status FROM technician_shifts
              WHERE closed_at IS NULL'
        );

        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(int)$row['technician_id']] = (string)$row['status'];
        }

        return $map;
    }

    /**
     * Carga la fila con cerrojo de escritura para serializar la transición.
     */
    private function lockShift(int $shiftId): TechnicianShift
    {
        $stmt = $this->pdo->prepare('SELECT * FROM technician_shifts WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $shiftId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new RuntimeException("No se encontró el turno con ID {$shiftId}.");
        }

        return TechnicianShift::fromDatabaseRow($row);
    }

    /**
     * @param \PDOStatement $stmt Sentencia con `RETURNING *` ya ejecutada.
     */
    private function requireUpdatedRow(\PDOStatement $stmt, int $shiftId): TechnicianShift
    {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException("No se pudo actualizar el turno con ID {$shiftId}.");
        }

        return TechnicianShift::fromDatabaseRow($row);
    }

    /**
     * @param \PDOStatement $stmt
     * @return list<TechnicianShift>
     */
    private function hydrateMany(\PDOStatement $stmt): array
    {
        $shifts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $shifts[] = TechnicianShift::fromDatabaseRow($row);
        }

        return $shifts;
    }

    /**
     * Marca temporal con desfase, en la forma que el motor interpreta sin ambigüedad.
     */
    private function timestamp(?DateTimeImmutable $value): ?string
    {
        return $value?->format(DateTimeImmutable::ATOM);
    }

    /**
     * PostgreSQL no admite booleanos PHP directamente en todos los drivers;
     * se envía el literal que el motor reconoce.
     */
    private function boolean(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
