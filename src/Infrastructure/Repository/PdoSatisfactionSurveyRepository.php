<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use PDOStatement;
use VendGuard\Application\DTO\CsatReportDto;
use VendGuard\Core\Domain\Model\IncidentSatisfactionSurvey;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\Repository\SatisfactionSurveyRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoSatisfactionSurveyRepository
 *
 * Persistencia de las valoraciones de sede del Módulo 20 sobre PostgreSQL.
 *
 * Decisiones que el código hace explícitas:
 *
 *  - **El duplicado lo decide el motor, no una lectura previa.** `create()` usa
 *    `ON CONFLICT (incident_id, resolution_at) DO NOTHING RETURNING …`: dos
 *    peticiones simultáneas no pueden dejar dos filas y la segunda recibe
 *    `null`, que el servicio traduce a `409` (RF-02.7).
 *  - **Los pendientes se unen por la pareja del episodio.** El `LEFT JOIN` lleva
 *    `incident_id` **y** `resolution_at`: tras una reapertura la encuesta
 *    anterior no marca como valorado el servicio nuevo (RD-01).
 *  - **Los agregados no imputan ceros.** `AVG` se calcula solo sobre filas
 *    existentes; sin respuestas devuelve `null` y cero respuestas (RD-07).
 *  - **No hay `update` ni `delete`.** El puerto no los declara y los disparadores
 *    del motor los rechazan (Art. III.1).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoSatisfactionSurveyRepository implements SatisfactionSurveyRepositoryInterface
{
    /** Proyección completa de una fila para hidratar la entidad. */
    private const SURVEY_COLUMNS = 'id, incident_id, location_id, technician_id, technician_name_snapshot,
        resolution_at, rating, comment, created_at';

    /**
     * Episodios pendientes: la misma condición en el `SELECT` y en el `COUNT`,
     * para que el total y la página no puedan discrepar.
     */
    private const PENDING_FROM = 'FROM incidents i
        INNER JOIN machines m ON m.id = i.machine_id
        LEFT JOIN incident_satisfaction_surveys s
               ON s.incident_id = i.id
              AND s.resolution_at = i.resolved_at
        WHERE i.location_id = :location_id
          AND i.status IN (\'RESOLVED\', \'CLOSED\')
          AND i.resolved_at IS NOT NULL
          AND s.id IS NULL';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritDoc}
     */
    public function findEpisode(int $incidentId, string $resolutionAt): ?IncidentSatisfactionSurvey
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SURVEY_COLUMNS . '
               FROM incident_satisfaction_surveys
              WHERE incident_id = :incident_id
                AND resolution_at = :resolution_at'
        );
        $stmt->bindValue(':incident_id', $incidentId, PDO::PARAM_INT);
        $stmt->bindValue(':resolution_at', $resolutionAt, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : IncidentSatisfactionSurvey::fromDatabaseRow($row);
    }

    /**
     * {@inheritDoc}
     */
    public function create(IncidentSatisfactionSurvey $survey): ?IncidentSatisfactionSurvey
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO incident_satisfaction_surveys
                (incident_id, location_id, technician_id, technician_name_snapshot,
                 resolution_at, rating, comment)
             VALUES
                (:incident_id, :location_id, :technician_id, :technician_name_snapshot,
                 :resolution_at, :rating, :comment)
             ON CONFLICT (incident_id, resolution_at) DO NOTHING
             RETURNING ' . self::SURVEY_COLUMNS
        );
        $stmt->bindValue(':incident_id', $survey->getIncidentId(), PDO::PARAM_INT);
        $stmt->bindValue(':location_id', $survey->getLocationId(), PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':technician_id', $survey->getTechnicianId());
        $this->bindNullableText($stmt, ':technician_name_snapshot', $survey->getTechnicianNameSnapshot());
        $stmt->bindValue(':resolution_at', $survey->getResolutionAt(), PDO::PARAM_STR);
        $stmt->bindValue(':rating', $survey->getRating(), PDO::PARAM_INT);
        $this->bindNullableText($stmt, ':comment', $survey->getComment());
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : IncidentSatisfactionSurvey::fromDatabaseRow($row);
    }

    /**
     * {@inheritDoc}
     */
    public function findPendingByLocation(int $locationId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.id AS incident_id,
                    i.ticket_code,
                    m.code AS machine_code,
                    m.model AS machine_model,
                    i.resolved_at
               ' . self::PENDING_FROM . '
              ORDER BY i.resolved_at DESC, i.id DESC
              LIMIT :limit'
        );
        $stmt->bindValue(':location_id', $locationId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $items = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'incident_id' => (int)$row['incident_id'],
                'ticket_code' => (string)$row['ticket_code'],
                'machine_code' => (string)$row['machine_code'],
                'machine_model' => $row['machine_model'] !== null ? (string)$row['machine_model'] : null,
                'resolved_at' => (string)$row['resolved_at'],
            ];
        }

        return $items;
    }

    /**
     * {@inheritDoc}
     */
    public function countPendingByLocation(int $locationId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) ' . self::PENDING_FROM);
        $stmt->bindValue(':location_id', $locationId, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * {@inheritDoc}
     */
    public function aggregate(MetricFilter $filter): CsatReportDto
    {
        [$where, $params] = $this->buildScope($filter);

        $summary = $this->run(
            'SELECT ROUND(AVG(s.rating)::numeric, 2) AS average,
                    COUNT(*) AS responses
               FROM incident_satisfaction_surveys s
             ' . $where,
            $params
        )->fetch(PDO::FETCH_ASSOC);

        $distributionRows = $this->run(
            'SELECT s.rating, COUNT(*) AS responses
               FROM incident_satisfaction_surveys s
             ' . $where . '
              GROUP BY s.rating',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);

        $distribution = [];

        foreach ($distributionRows as $row) {
            $distribution[(string)(int)$row['rating']] = (int)$row['responses'];
        }

        $bySite = array_map(
            static fn(array $row): array => [
                'location_id' => (int)$row['location_id'],
                'site_code' => (string)$row['site_code'],
                'location_name' => (string)$row['location_name'],
                'average' => (float)$row['average'],
                'responses' => (int)$row['responses'],
            ],
            $this->run(
                'SELECT s.location_id,
                        l.site_code,
                        l.name AS location_name,
                        ROUND(AVG(s.rating)::numeric, 2) AS average,
                        COUNT(*) AS responses
                   FROM incident_satisfaction_surveys s
                   INNER JOIN locations l ON l.id = s.location_id
                 ' . $where . '
                  GROUP BY s.location_id, l.site_code, l.name
                  ORDER BY responses DESC, l.name ASC',
                $params
            )->fetchAll(PDO::FETCH_ASSOC)
        );

        // El nombre del técnico es el congelado en la encuesta más reciente del
        // grupo filtrado: un renombrado posterior no reescribe la métrica (R8).
        $byTechnician = array_map(
            static fn(array $row): array => [
                'technician_id' => $row['technician_id'] !== null ? (int)$row['technician_id'] : null,
                'technician_name' => (string)$row['technician_name'],
                'average' => (float)$row['average'],
                'responses' => (int)$row['responses'],
            ],
            $this->run(
                'WITH filtered AS (
                     SELECT * FROM incident_satisfaction_surveys s
                     ' . $where . '
                 ),
                 latest AS (
                     SELECT DISTINCT ON (technician_id) technician_id, technician_name_snapshot
                       FROM filtered
                      WHERE technician_id IS NOT NULL
                      ORDER BY technician_id, created_at DESC, id DESC
                 )
                 SELECT f.technician_id,
                        COALESCE(latest.technician_name_snapshot, \'Sin técnico\') AS technician_name,
                        ROUND(AVG(f.rating)::numeric, 2) AS average,
                        COUNT(*) AS responses
                   FROM filtered f
                   LEFT JOIN latest ON latest.technician_id = f.technician_id
                  GROUP BY f.technician_id, latest.technician_name_snapshot
                  ORDER BY responses DESC, technician_name ASC',
                $params
            )->fetchAll(PDO::FETCH_ASSOC)
        );

        $monthlyTrend = array_map(
            static fn(array $row): array => [
                'month' => (string)$row['month'],
                'average' => (float)$row['average'],
                'responses' => (int)$row['responses'],
            ],
            $this->run(
                'SELECT TO_CHAR(s.resolution_at AT TIME ZONE \'Europe/Madrid\', \'YYYY-MM\') AS month,
                        ROUND(AVG(s.rating)::numeric, 2) AS average,
                        COUNT(*) AS responses
                   FROM incident_satisfaction_surveys s
                 ' . $where . '
                  GROUP BY month
                  ORDER BY month ASC',
                $params
            )->fetchAll(PDO::FETCH_ASSOC)
        );

        return new CsatReportDto(
            average: $summary !== false && $summary['average'] !== null ? (float)$summary['average'] : null,
            responses: $summary !== false ? (int)$summary['responses'] : 0,
            distribution: $distribution,
            bySite: $bySite,
            byTechnician: $byTechnician,
            monthlyTrend: $monthlyTrend,
        );
    }

    /**
     * Condición temporal y dimensional común a todos los agregados (contratos §6.4).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildScope(MetricFilter $filter): array
    {
        $where = 'WHERE s.resolution_at >= :from_date
                    AND s.resolution_at <= :to_date';

        $params = [
            ':from_date' => $filter->getFrom()->format('Y-m-d H:i:s'),
            ':to_date' => $filter->getTo()->format('Y-m-d H:i:s'),
        ];

        if ($filter->getLocationId() !== null) {
            $where .= ' AND s.location_id = :location_id';
            $params[':location_id'] = $filter->getLocationId();
        }

        if ($filter->getTechnicianId() !== null) {
            $where .= ' AND s.technician_id = :technician_id';
            $params[':technician_id'] = $filter->getTechnicianId();
        }

        return [$where, $params];
    }

    /**
     * Ejecuta una consulta con sus parámetros ya normalizados.
     *
     * @param array<string, mixed> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    private function bindNullableInt(PDOStatement $stmt, string $placeholder, ?int $value): void
    {
        $stmt->bindValue($placeholder, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    }

    private function bindNullableText(PDOStatement $stmt, string $placeholder, ?string $value): void
    {
        $stmt->bindValue($placeholder, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }
}
