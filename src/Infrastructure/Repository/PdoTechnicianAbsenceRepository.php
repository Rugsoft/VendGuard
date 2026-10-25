<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use PDO;
use PDOException;
use VendGuard\Core\Domain\Exception\InvalidAbsenceException;
use VendGuard\Core\Domain\Model\TechnicianAbsence;
use VendGuard\Core\Domain\Repository\TechnicianAbsenceRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\AbsenceReason;

/**
 * PdoTechnicianAbsenceRepository
 *
 * Implementación PDO nativa de persistencia para ausencias de técnicos (Módulo 21)
 * sobre PostgreSQL (Supabase).
 *
 * Dogma Vanilla: Cero ORMs, consultas preparadas indexadas, hidratación directa a entidades.
 */
final class PdoTechnicianAbsenceRepository implements TechnicianAbsenceRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(
        int $technicianId,
        AbsenceReason $reason,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?string $notes,
        int $createdBy
    ): TechnicianAbsence {
        $stmt = $this->pdo->prepare(
            'INSERT INTO technician_absences (
                technician_id, reason, starts_at, ends_at, notes, created_by
            ) VALUES (
                :technician_id, :reason, :starts_at, :ends_at, :notes, :created_by
            ) RETURNING *'
        );

        try {
            $stmt->execute([
                ':technician_id' => $technicianId,
                ':reason' => $reason->value,
                ':starts_at' => $startsAt->format(DateTimeImmutable::ATOM),
                ':ends_at' => $endsAt->format(DateTimeImmutable::ATOM),
                ':notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
                ':created_by' => $createdBy,
            ]);
        } catch (PDOException $exception) {
            // La constraint de exclusión es la última autoridad frente a dos
            // altas concurrentes. El mensaje del motor no se propaga al cliente.
            if ($exception->getCode() === '23P01' || (string)($exception->errorInfo[0] ?? '') === '23P01') {
                throw InvalidAbsenceException::overlap();
            }

            throw $exception;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Error al insertar registro de ausencia en la base de datos.');
        }

        return TechnicianAbsence::fromDatabaseRow($row);
    }

    public function findById(int $id): ?TechnicianAbsence
    {
        $stmt = $this->pdo->prepare('SELECT * FROM technician_absences WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return TechnicianAbsence::fromDatabaseRow($row);
    }

    /**
     * @return list<TechnicianAbsence>
     */
    public function findAll(?int $technicianId = null, ?string $status = null): array
    {
        $where = [];
        $params = [];

        if ($technicianId !== null) {
            $where[] = 'technician_id = :technician_id';
            $params[':technician_id'] = $technicianId;
        }

        $nowIso = (new DateTimeImmutable('now'))->format(DateTimeImmutable::ATOM);

        match ($status) {
            'active' => (function () use (&$where, &$params, $nowIso) {
                $where[] = 'cancelled_at IS NULL AND starts_at <= :now AND ends_at > :now';
                $params[':now'] = $nowIso;
            })(),
            'future' => (function () use (&$where, &$params, $nowIso) {
                $where[] = 'cancelled_at IS NULL AND starts_at > :now';
                $params[':now'] = $nowIso;
            })(),
            'cancelled' => (function () use (&$where) {
                $where[] = 'cancelled_at IS NOT NULL';
            })(),
            default => null, // 'all' o null no añade restricción de estado
        };

        $sql = 'SELECT * FROM technician_absences';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY starts_at DESC, id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $absences = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $absences[] = TechnicianAbsence::fromDatabaseRow($row);
        }

        return $absences;
    }

    public function findActiveAbsenceForTechnician(int $technicianId, ?DateTimeImmutable $referenceTime = null): ?TechnicianAbsence
    {
        $time = $referenceTime ?? new DateTimeImmutable('now');

        $stmt = $this->pdo->prepare(
            'SELECT * FROM technician_absences
              WHERE technician_id = :technician_id
                AND cancelled_at IS NULL
                AND starts_at <= :ref_time
                AND ends_at > :ref_time
              ORDER BY id DESC
              LIMIT 1'
        );

        $stmt->execute([
            ':technician_id' => $technicianId,
            ':ref_time' => $time->format(DateTimeImmutable::ATOM),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return TechnicianAbsence::fromDatabaseRow($row);
    }

    /**
     * @return array<int, true>
     */
    public function findActiveAbsencesMap(DateTimeImmutable $at): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT technician_id FROM technician_absences
              WHERE cancelled_at IS NULL
                AND starts_at <= :at
                AND ends_at > :at'
        );
        $stmt->execute([':at' => $at->format(DateTimeImmutable::ATOM)]);

        $map = [];
        while (($technicianId = $stmt->fetchColumn()) !== false) {
            $map[(int)$technicianId] = true;
        }

        return $map;
    }

    public function hasOverlappingAbsence(
        int $technicianId,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?int $excludeAbsenceId = null
    ): bool {
        // Semántica semiabierta [starts_at, ends_at): dos ausencias que solo
        // comparten el instante límite (09:00-13:00 y 13:00-17:00) no se solapan.
        // Comparar con <=/>= rechazaba ese contacto exacto (m-1).
        $sql = 'SELECT 1 FROM technician_absences
                 WHERE technician_id = :technician_id
                   AND cancelled_at IS NULL
                   AND starts_at < :ends_at
                   AND ends_at > :starts_at';

        $params = [
            ':technician_id' => $technicianId,
            ':starts_at' => $startsAt->format(DateTimeImmutable::ATOM),
            ':ends_at' => $endsAt->format(DateTimeImmutable::ATOM),
        ];

        if ($excludeAbsenceId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params[':exclude_id'] = $excludeAbsenceId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    public function cancel(int $id, int $cancelledBy, string $cancellationReason): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE technician_absences
                SET cancelled_at = CURRENT_TIMESTAMP,
                    cancelled_by = :cancelled_by,
                    cancellation_reason = :cancellation_reason
              WHERE id = :id
                AND cancelled_at IS NULL'
        );

        $stmt->execute([
            ':id' => $id,
            ':cancelled_by' => $cancelledBy,
            ':cancellation_reason' => trim($cancellationReason),
        ]);

        return $stmt->rowCount() > 0;
    }
}
