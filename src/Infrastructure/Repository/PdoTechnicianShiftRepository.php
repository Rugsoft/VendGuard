<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use PDO;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;

/**
 * PdoTechnicianShiftRepository
 *
 * Implementación PDO nativa de persistencia para el ciclo de jornada y turnos
 * de técnicos (Módulo 21) sobre PostgreSQL (Supabase).
 *
 * Dogma Vanilla: Consultas indexadas, cerrojos atómicos con FOR UPDATE para
 * transiciones de estado y protección frente a condiciones de carrera.
 */
final class PdoTechnicianShiftRepository implements TechnicianShiftRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
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

        if (!is_array($row)) {
            return null;
        }

        return TechnicianShift::fromDatabaseRow($row);
    }

    public function startShift(int $technicianId, ?DateTimeImmutable $startedAt = null): TechnicianShift
    {
        // Comprobar idempotencia: si ya tiene un turno abierto, se devuelve
        $active = $this->findActiveShiftByTechnician($technicianId);
        if ($active !== null) {
            return $active;
        }

        $now = $startedAt ?? new DateTimeImmutable('now');

        $stmt = $this->pdo->prepare(
            'INSERT INTO technician_shifts (
                technician_id, shift_date, status, started_at
            ) VALUES (
                :technician_id, :shift_date, :status, :started_at
            ) RETURNING *'
        );

        $stmt->execute([
            ':technician_id' => $technicianId,
            ':shift_date' => $now->format('Y-m-d'),
            ':status' => ShiftStatus::ON_SHIFT->value,
            ':started_at' => $now->format(DateTimeImmutable::ATOM),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Error al iniciar turno de técnico en la base de datos.');
        }

        return TechnicianShift::fromDatabaseRow($row);
    }

    public function pauseMeal(int $shiftId, ?DateTimeImmutable $pausedAt = null): TechnicianShift
    {
        $stmt = $this->pdo->prepare('SELECT * FROM technician_shifts WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $shiftId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new \RuntimeException("No se encontró el turno con ID {$shiftId}.");
        }

        $shift = TechnicianShift::fromDatabaseRow($row);
        $updated = $shift->pauseMeal($pausedAt);

        $updateStmt = $this->pdo->prepare(
            'UPDATE technician_shifts
                SET status = :status,
                    meal_break_started_at = :meal_break_started_at
              WHERE id = :id
              RETURNING *'
        );

        $updateStmt->execute([
            ':id' => $shiftId,
            ':status' => $updated->status()->value,
            ':meal_break_started_at' => $updated->mealBreakStartedAt()?->format(DateTimeImmutable::ATOM),
        ]);

        $updatedRow = $updateStmt->fetch(PDO::FETCH_ASSOC);
        return TechnicianShift::fromDatabaseRow($updatedRow);
    }

    public function resumeShift(int $shiftId, ?DateTimeImmutable $resumedAt = null): TechnicianShift
    {
        $stmt = $this->pdo->prepare('SELECT * FROM technician_shifts WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $shiftId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new \RuntimeException("No se encontró el turno con ID {$shiftId}.");
        }

        $shift = TechnicianShift::fromDatabaseRow($row);
        $updated = $shift->resume($resumedAt);

        $updateStmt = $this->pdo->prepare(
            'UPDATE technician_shifts
                SET status = :status,
                    meal_break_ended_at = :meal_break_ended_at
              WHERE id = :id
              RETURNING *'
        );

        $updateStmt->execute([
            ':id' => $shiftId,
            ':status' => $updated->status()->value,
            ':meal_break_ended_at' => $updated->mealBreakEndedAt()?->format(DateTimeImmutable::ATOM),
        ]);

        $updatedRow = $updateStmt->fetch(PDO::FETCH_ASSOC);
        return TechnicianShift::fromDatabaseRow($updatedRow);
    }

    public function closeShift(
        int $shiftId,
        int $closedBy,
        ?string $closeReason = null,
        ?DateTimeImmutable $closedAt = null
    ): TechnicianShift {
        $stmt = $this->pdo->prepare('SELECT * FROM technician_shifts WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $shiftId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new \RuntimeException("No se encontró el turno con ID {$shiftId}.");
        }

        $shift = TechnicianShift::fromDatabaseRow($row);
        $updated = $shift->close($closedBy, $closeReason, $closedAt);

        $updateStmt = $this->pdo->prepare(
            'UPDATE technician_shifts
                SET status = :status,
                    closed_at = :closed_at,
                    closed_by = :closed_by,
                    close_reason = :close_reason
              WHERE id = :id
              RETURNING *'
        );

        $updateStmt->execute([
            ':id' => $shiftId,
            ':status' => $updated->status()->value,
            ':closed_at' => $updated->closedAt()?->format(DateTimeImmutable::ATOM),
            ':closed_by' => $updated->closedBy(),
            ':close_reason' => $updated->closeReason(),
        ]);

        $updatedRow = $updateStmt->fetch(PDO::FETCH_ASSOC);
        return TechnicianShift::fromDatabaseRow($updatedRow);
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
}
