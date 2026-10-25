<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;
use VendGuard\Core\Domain\Model\TechnicianShiftLog;
use VendGuard\Core\Domain\Repository\TechnicianShiftLogRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\ShiftEventType;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoTechnicianShiftLogRepository
 *
 * Implementación PDO nativa de la bitácora inmutable de jornada
 * `technician_shift_logs` (Módulo 22) sobre PostgreSQL (Supabase).
 *
 * Art. III.1 y Art. III.3: solo se emiten `INSERT` y `SELECT`. La tabla tiene
 * disparadores que rechazan `UPDATE` y `DELETE`, y este repositorio ni siquiera
 * los intenta: una rectificación entra como una entrada nueva que documenta el
 * estado previo y el resultante.
 *
 * Los snapshots `previous_state` y `new_state` viajan como `JSONB`, de modo que
 * la evidencia de la bitácora conserva la forma del turno en el instante del
 * evento y no depende de cómo evolucione la tabla.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sentencias preparadas y cero
 * dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoTechnicianShiftLogRepository implements TechnicianShiftLogRepositoryInterface
{
    /** Orden cronológico normativo del historial (contrato técnico §3.2). */
    private const FIND_BY_SHIFT_SQL = <<<'SQL'
        SELECT *
          FROM technician_shift_logs
         WHERE shift_id = :shift_id
         ORDER BY created_at ASC, id ASC
        SQL;

    private const RECORD_SQL = <<<'SQL'
        INSERT INTO technician_shift_logs (
            shift_id,
            technician_id,
            event_type,
            actor_user_id,
            effective_at,
            previous_state,
            new_state,
            reason
        ) VALUES (
            :shift_id,
            :technician_id,
            :event_type,
            :actor_user_id,
            :effective_at,
            CAST(:previous_state AS JSONB),
            CAST(:new_state AS JSONB),
            :reason
        )
        RETURNING *
        SQL;

    private readonly PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    public function record(
        int $shiftId,
        int $technicianId,
        ShiftEventType $eventType,
        int $actorUserId,
        DateTimeImmutable $effectiveAt,
        ?array $previousState,
        array $newState,
        ?string $reason = null
    ): TechnicianShiftLog {
        $stmt = $this->pdo->prepare(self::RECORD_SQL);
        $stmt->execute([
            ':shift_id' => $shiftId,
            ':technician_id' => $technicianId,
            ':event_type' => $eventType->value,
            ':actor_user_id' => $actorUserId,
            ':effective_at' => $effectiveAt->format(DateTimeImmutable::ATOM),
            ':previous_state' => $this->encodeState($previousState),
            ':new_state' => $this->encodeState($newState),
            ':reason' => $reason,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Error al registrar un evento en la bitácora de jornada.');
        }

        return TechnicianShiftLog::fromDatabaseRow($row);
    }

    /**
     * @return list<TechnicianShiftLog>
     */
    public function findByShiftId(int $shiftId): array
    {
        $stmt = $this->pdo->prepare(self::FIND_BY_SHIFT_SQL);
        $stmt->execute([':shift_id' => $shiftId]);

        $logs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $logs[] = TechnicianShiftLog::fromDatabaseRow($row);
        }

        return $logs;
    }

    /**
     * Serializa un snapshot para la columna `JSONB`.
     *
     * `null` viaja como `NULL` SQL (estado previo inexistente, por ejemplo en el
     * primer fichaje del turno); un array vacío se conserva como objeto vacío.
     *
     * @param array<string, mixed>|null $state
     */
    private function encodeState(?array $state): ?string
    {
        if ($state === null) {
            return null;
        }

        try {
            return json_encode(
                $state,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'El estado de la bitácora de jornada no se pudo serializar a JSON: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }
}
