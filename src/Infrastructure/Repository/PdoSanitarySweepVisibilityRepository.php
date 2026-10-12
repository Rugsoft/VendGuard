<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use VendGuard\Core\Domain\Model\SanitarySweepContention;
use VendGuard\Core\Domain\Model\SanitarySweepPass;
use VendGuard\Core\Domain\Repository\SanitarySweepVisibilityRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoSanitarySweepVisibilityRepository — Adaptador PDO del libro de pasadas y de
 * los seguimientos de contención del barrido sanitario (T-PAUSE-41, RF-07).
 *
 * Sin dependencias: PDO nativo sobre la misma conexión compartida que el resto
 * de la infraestructura (`ConnectionFactory`), resuelta de forma perezosa para
 * que el objeto sea construible sin base de datos, como todos los adaptadores
 * del proyecto.
 *
 * Decisiones de implementación, todas razonadas en el contrato del puerto:
 * 1. **Upsert por expediente** (`ON DUPLICATE KEY UPDATE`): la clave única
 *    `uq_sanitary_sweep_contention_incident` reutiliza la fila, así que un
 *    episodio nuevo reescribe los contadores y limpia las marcas en lugar de
 *    acumular una fila por aplazamiento.
 * 2. **Resolución por identidad**: se cierran los seguimientos abiertos cuyo
 *    expediente no viaja en la lista de aplazados de la pasada que resuelve. El
 *    barrido sabe exactamente a quién tocó, así que no hace falta comparar marcas
 *    temporales —que además sólo tienen resolución de segundo— para saber qué
 *    dejó de detectar.
 * 3. **Lectura de la superficie con `INNER JOIN`** a `incidents` y `machines`: la
 *    fila viaja con su presentación y el orden pone primero lo escalado y, dentro
 *    de cada grupo, lo más antiguo.
 * 4. **Sin borrado físico** (Art. III.1): no hay un solo `DELETE` en este
 *    adaptador; el cierre de un episodio es una marca (`resolved_at`), y el
 *    histórico completo queda en la tabla.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class PdoSanitarySweepVisibilityRepository implements SanitarySweepVisibilityRepositoryInterface
{
    /** Conexión compartida; se resuelve en el primer uso si no se inyecta. */
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * {@inheritDoc}
     */
    public function recordPass(SanitarySweepPass $pass): SanitarySweepPass
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO `sanitary_sweep_passes`
                (`started_at`, `finished_at`, `candidates`, `evaluated`,
                 `quarantined_count`, `contended_count`, `failures_count`, `duration_ms`)
             VALUES
                (:started_at, :finished_at, :candidates, :evaluated,
                 :quarantined_count, :contended_count, :failures_count, :duration_ms)'
        );

        $statement->execute([
            ':started_at' => $pass->startedAt,
            ':finished_at' => $pass->finishedAt,
            ':candidates' => $pass->candidates,
            ':evaluated' => $pass->evaluated,
            ':quarantined_count' => $pass->quarantinedCount,
            ':contended_count' => $pass->contendedCount,
            ':failures_count' => $pass->failuresCount,
            ':duration_ms' => $pass->durationMs,
        ]);

        return new SanitarySweepPass(
            id: (int)$this->connection()->lastInsertId(),
            startedAt: $pass->startedAt,
            finishedAt: $pass->finishedAt,
            candidates: $pass->candidates,
            evaluated: $pass->evaluated,
            quarantinedCount: $pass->quarantinedCount,
            contendedCount: $pass->contendedCount,
            failuresCount: $pass->failuresCount,
            durationMs: $pass->durationMs,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function findContentionByIncident(int $incidentId): ?SanitarySweepContention
    {
        if ($incidentId < 1) {
            throw new InvalidArgumentException('El seguimiento de contención exige un expediente válido (Art. III).');
        }

        $statement = $this->connection()->prepare(
            'SELECT * FROM `sanitary_sweep_contentions` WHERE `incident_id` = :incident_id LIMIT 1'
        );
        $statement->execute([':incident_id' => $incidentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function saveContentionDetection(SanitarySweepContention $contention): void
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO `sanitary_sweep_contentions`
                (`incident_id`, `machine_id`, `first_detected_at`, `last_detected_at`,
                 `consecutive_passes`, `total_passes`, `escalated_at`, `resolved_at`)
             VALUES
                (:incident_id, :machine_id, :first_detected_at, :last_detected_at,
                 :consecutive_passes, :total_passes, :escalated_at, NULL)
             ON DUPLICATE KEY UPDATE
                `machine_id` = VALUES(`machine_id`),
                `first_detected_at` = VALUES(`first_detected_at`),
                `last_detected_at` = VALUES(`last_detected_at`),
                `consecutive_passes` = VALUES(`consecutive_passes`),
                `total_passes` = VALUES(`total_passes`),
                `escalated_at` = VALUES(`escalated_at`),
                `resolved_at` = NULL'
        );

        $statement->execute([
            ':incident_id' => $contention->incidentId,
            ':machine_id' => $contention->machineId,
            ':first_detected_at' => $contention->firstDetectedAt,
            ':last_detected_at' => $contention->lastDetectedAt,
            ':consecutive_passes' => $contention->consecutivePasses,
            ':total_passes' => $contention->totalPasses,
            ':escalated_at' => $contention->escalatedAt,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function resolveContentionsNotDetectedIn(array $detectedIncidentIds, DateTimeImmutable $resolvedAt): int
    {
        $params = [':resolved_at' => $resolvedAt->format('Y-m-d H:i:s')];
        $exclusion = '';

        $placeholders = [];
        $position = 0;
        foreach ($detectedIncidentIds as $incidentId) {
            $placeholder = ':detected_' . $position;
            $placeholders[] = $placeholder;
            $params[$placeholder] = (int)$incidentId;
            $position++;
        }

        if ($placeholders !== []) {
            $exclusion = ' AND `incident_id` NOT IN (' . implode(', ', $placeholders) . ')';
        }

        $statement = $this->connection()->prepare(
            'UPDATE `sanitary_sweep_contentions`
                SET `resolved_at` = :resolved_at
              WHERE `resolved_at` IS NULL' . $exclusion
        );

        $statement->execute($params);

        return $statement->rowCount();
    }

    /**
     * {@inheritDoc}
     */
    public function findOpenContentions(): array
    {
        $statement = $this->connection()->query(
            "SELECT c.*,
                    i.`ticket_code` AS ticket_code,
                    i.`status` AS incident_status,
                    m.`code` AS machine_code,
                    m.`sanitary_status` AS machine_sanitary_status,
                    l.`name` AS location_name,
                    m.`floor_wing` AS floor_wing
               FROM `sanitary_sweep_contentions` c
               INNER JOIN `incidents` i ON i.`id` = c.`incident_id`
               INNER JOIN `machines` m ON m.`id` = c.`machine_id`
               LEFT JOIN `locations` l ON l.`id` = m.`location_id`
              WHERE c.`resolved_at` IS NULL
              ORDER BY (c.`escalated_at` IS NULL) ASC, c.`escalated_at` ASC,
                       c.`first_detected_at` ASC, c.`id` ASC"
        );

        if ($statement === false) {
            return [];
        }

        $contentions = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $contentions[] = self::hydrate($row);
        }

        return $contentions;
    }

    /**
     * {@inheritDoc}
     */
    public function findLatestPass(): ?SanitarySweepPass
    {
        $statement = $this->connection()->query(
            'SELECT * FROM `sanitary_sweep_passes` ORDER BY `id` DESC LIMIT 1'
        );

        if ($statement === false) {
            return null;
        }

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::hydratePass($row) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function findPassesSince(DateTimeImmutable $since): array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM `sanitary_sweep_passes`
              WHERE `started_at` >= :since
              ORDER BY `started_at` ASC, `id` ASC'
        );
        $statement->execute([':since' => $since->format('Y-m-d H:i:s')]);

        $passes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $passes[] = self::hydratePass($row);
        }

        return $passes;
    }

    /**
     * Hidrata un seguimiento desde una fila del libro.
     *
     * Las columnas de presentación son opcionales: la lectura de seguimiento
     * (barrido) no las trae y la de superficie (coordinación) sí.
     *
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): SanitarySweepContention
    {
        return new SanitarySweepContention(
            id: (int)$row['id'],
            incidentId: (int)$row['incident_id'],
            machineId: (int)$row['machine_id'],
            firstDetectedAt: (string)$row['first_detected_at'],
            lastDetectedAt: (string)$row['last_detected_at'],
            consecutivePasses: (int)$row['consecutive_passes'],
            totalPasses: (int)$row['total_passes'],
            escalatedAt: self::nullableString($row['escalated_at'] ?? null),
            resolvedAt: self::nullableString($row['resolved_at'] ?? null),
            ticketCode: self::nullableString($row['ticket_code'] ?? null),
            machineCode: self::nullableString($row['machine_code'] ?? null),
            locationName: self::nullableString($row['location_name'] ?? null),
            floorWing: self::nullableString($row['floor_wing'] ?? null),
            incidentStatus: self::nullableString($row['incident_status'] ?? null),
            machineSanitaryStatus: self::nullableString($row['machine_sanitary_status'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydratePass(array $row): SanitarySweepPass
    {
        return new SanitarySweepPass(
            id: (int)$row['id'],
            startedAt: (string)$row['started_at'],
            finishedAt: self::nullableString($row['finished_at'] ?? null),
            candidates: (int)$row['candidates'],
            evaluated: (int)$row['evaluated'],
            quarantinedCount: (int)$row['quarantined_count'],
            contendedCount: (int)$row['contended_count'],
            failuresCount: (int)$row['failures_count'],
            durationMs: (float)$row['duration_ms'],
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }

    /**
     * Conexión PDO compartida con el resto de la infraestructura.
     */
    private function connection(): PDO
    {
        return $this->pdo ??= ConnectionFactory::getConnection();
    }
}
