<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Core\Domain\Repository\TechnicianSuggestionRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\ZoneAffinityType;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

/**
 * PdoTechnicianSuggestionRepository
 *
 * Lectura del recomendador de asignación contra PostgreSQL (Módulo 17, RF-04 a
 * RF-06). Compone la foto operativa en **cinco viajes fijos**, independientes del
 * número de técnicos del parque:
 *
 *   1. la avería con su sede, su zona y el tipo de máquina;
 *   2. la base central de ruta (`route_settings`);
 *   3. los técnicos activos con su afinidad territorial viva;
 *   4. las paradas vivas (y las resueltas de hoy) de esos técnicos;
 *   5. los preventivos de hoy de esos técnicos.
 *
 * El N+1 se evita agrupando en PHP las dos últimas consultas, que llegan
 * acotadas al conjunto de candidatos: la carga de trabajo de un técnico son sus
 * paradas vivas —unas pocas—, no su histórico completo. Se prefiere agregar en
 * PHP, y no con subconsultas correlacionadas por técnico, porque así el SQL se
 * lee como lo que consulta y no como una máquina de estados de contadores.
 *
 * Las consultas no escriben, no abren transacción y no leen el reloj del proceso:
 * la ventana «hoy» la resuelve el motor (`CURRENT_DATE`), de modo que dos
 * peticiones simultáneas ven la misma frontera.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoTechnicianSuggestionRepository implements TechnicianSuggestionRepositoryInterface
{
    /** Estados que cuentan como carga viva del técnico (contrato §4.1). */
    private const WORKLOAD_STATUSES = ['ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS'];

    /** Estados con presencia física en curso o pendiente de hoy (contrato §4.1). */
    private const LIVE_STOP_STATUSES = ['ASSIGNED', 'IN_PROGRESS'];

    /** Estados preventivos que consumen el día del técnico (contrato §4.1). */
    private const PREVENTIVE_STATUSES = ['IN_INSPECTION', 'SCHEDULED'];

    private PDO $pdo;

    private SqlDialectAdapter $dialect;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
        $this->dialect = SqlDialectAdapter::forConnection($this->pdo);
    }

    /**
     * {@inheritDoc}
     */
    public function findSuggestionPhoto(int $incidentId): ?array
    {
        if ($incidentId < 1) {
            return null;
        }

        $incident = $this->findIncident($incidentId);
        if ($incident === null) {
            return null;
        }

        $location = $incident['location'];
        $zoneId = $location['zone_id'];
        $targetLocationId = $location['id'];

        $technicians = $this->findTechnicians($zoneId);
        $technicianIds = array_column($technicians, 'technician_id');

        $stopsByTechnician = $this->findStopRows($technicianIds);
        $preventiveByTechnician = $this->findPreventiveCounts($technicianIds);

        $rows = [];
        foreach ($technicians as $technician) {
            $technicianId = $technician['technician_id'];
            $rows[] = $this->projectTechnician(
                $technician,
                $stopsByTechnician[$technicianId] ?? [],
                $preventiveByTechnician[$technicianId] ?? 0,
                $targetLocationId,
                $zoneId
            );
        }

        return [
            'incident' => [
                'id' => $incident['id'],
                'ticket_code' => $incident['ticket_code'],
                'priority' => $incident['priority'],
                'machine_type' => $incident['machine_type'],
                'location' => $location,
            ],
            'technicians' => $rows,
            'options' => $this->findBaseCoordinates(),
        ];
    }

    /**
     * Avería con su sede, su zona y el tipo de máquina de la instantánea.
     *
     * La prioridad viaja como `urgency` del expediente, que es el vocabulario
     * canónico del dominio (`LOW`, `MEDIUM`, `HIGH`, `CRITICAL`). El tipo de
     * máquina prefiere el maestro vivo y cae a la instantánea del expediente si
     * la máquina ya no existe: el riesgo sanitario del aviso no cambia porque el
     * activo se haya retirado del parque.
     *
     * @return array<string, mixed>|null
     */
    private function findIncident(int $incidentId): ?array
    {
        $sql = "
            SELECT
                i.id,
                i.ticket_code,
                i.urgency,
                i.location_id,
                l.site_code AS location_site_code,
                l.name AS location_name,
                l.postal_code AS location_postal_code,
                l.zone_id AS location_zone_id,
                l.latitude AS location_latitude,
                l.longitude AS location_longitude,
                z.name AS zone_name,
                COALESCE(m.machine_type, i.machine_type_snapshot) AS machine_type
            FROM incidents i
            LEFT JOIN locations l ON l.id = i.location_id
            LEFT JOIN operational_zones z ON z.id = l.zone_id
            LEFT JOIN machines m ON m.id = i.machine_id
            WHERE i.id = :id
              AND i.deleted_at IS NULL
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $incidentId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $machineType = $row['machine_type'] !== null ? (string)$row['machine_type'] : null;

        return [
            'id' => (int)$row['id'],
            'ticket_code' => (string)$row['ticket_code'],
            'priority' => strtoupper((string)$row['urgency']),
            'machine_type' => $machineType,
            'location' => [
                'id' => $row['location_id'] !== null ? (int)$row['location_id'] : null,
                'site_code' => $row['location_site_code'] !== null ? (string)$row['location_site_code'] : null,
                'name' => $row['location_name'] !== null ? (string)$row['location_name'] : null,
                'postal_code' => $row['location_postal_code'] !== null ? (string)$row['location_postal_code'] : null,
                'zone_id' => $row['location_zone_id'] !== null ? (int)$row['location_zone_id'] : null,
                'zone_name' => $row['zone_name'] !== null ? (string)$row['zone_name'] : null,
                'latitude' => $row['location_latitude'] !== null ? (float)$row['location_latitude'] : null,
                'longitude' => $row['location_longitude'] !== null ? (float)$row['location_longitude'] : null,
            ],
        ];
    }

    /**
     * Base central del día, o coordenadas ausentes si la configuración no existe.
     *
     * @return array{base_latitude: float|null, base_longitude: float|null}
     */
    private function findBaseCoordinates(): array
    {
        $stmt = $this->pdo->query('SELECT base_latitude, base_longitude FROM route_settings WHERE id = 1 LIMIT 1');
        $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return ['base_latitude' => null, 'base_longitude' => null];
        }

        return [
            'base_latitude' => $row['base_latitude'] !== null ? (float)$row['base_latitude'] : null,
            'base_longitude' => $row['base_longitude'] !== null ? (float)$row['base_longitude'] : null,
        ];
    }

    /**
     * Técnicos activos con su afinidad viva respecto a la zona de la sede.
     *
     * La vinculación se filtra por la zona de la avería y por `removed_at IS NULL`:
     * una titularidad extinguida no premia a nadie (QA 10). Con la sede sin zona, el
     * `LEFT JOIN` no encuentra fila y todos los candidatos quedan en `NONE`, que es
     * exactamente el escenario de zona desatendida de RF-06.1.
     *
     * @return list<array{technician_id: int, name: string, operator_code: string|null, is_on_call: bool, is_active: bool, affinity: string}>
     */
    private function findTechnicians(?int $zoneId): array
    {
        $sql = "
            SELECT
                u.id AS technician_id,
                u.name,
                u.operator_code,
                u.is_on_call,
                u.is_active,
                a.is_primary
            FROM users u
            LEFT JOIN technician_zone_assignments a
                   ON a.technician_id = u.id
                  AND a.removed_at IS NULL
                  AND a.zone_id = :zone_id
            WHERE u.role = 'TECHNICIAN'
              AND u.is_active = " . $this->dialect->booleanLiteral(true) . "
              AND u.deleted_at IS NULL
            ORDER BY u.id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':zone_id', $zoneId, $zoneId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'technician_id' => (int)$row['technician_id'],
                'name' => (string)$row['name'],
                'operator_code' => $row['operator_code'] !== null ? (string)$row['operator_code'] : null,
                'is_on_call' => $this->toBoolean($row['is_on_call'] ?? false),
                'is_active' => $this->toBoolean($row['is_active'] ?? false),
                'affinity' => $this->affinityFrom($row['is_primary']),
            ];
        }

        return $rows;
    }

    /**
     * Paradas vivas de los candidatos y las resueltas de hoy.
     *
     * Una parada resuelta viaja sólo para resolver la referencia de distancia (QA 5)
     * y la firma de la ruta: no suma carga ni penaliza. `assigned_today` lo decide el
     * motor con `CURRENT_DATE`, que es la frontera que el contrato §4.2 usa para el
     * desempate por fatiga.
     *
     * @param list<int> $technicianIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function findStopRows(array $technicianIds): array
    {
        if ($technicianIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($technicianIds as $index => $technicianId) {
            $key = ':tech_' . $index;
            $placeholders[] = $key;
            $params[$key] = $technicianId;
        }

        $liveStatuses = $this->quoteList(array_merge(self::WORKLOAD_STATUSES, ['PENDING_INFO']));
        $statusList = implode(', ', $liveStatuses);

        $sql = "
            SELECT
                i.assigned_technician_id AS technician_id,
                i.status,
                i.location_id,
                l.name AS location_name,
                l.zone_id AS location_zone_id,
                l.latitude AS location_latitude,
                l.longitude AS location_longitude,
                i.assigned_at,
                i.resolved_at,
                CASE WHEN i.assigned_at >= CURRENT_DATE THEN 1 ELSE 0 END AS assigned_today
            FROM incidents i
            LEFT JOIN locations l ON l.id = i.location_id
            WHERE i.deleted_at IS NULL
              AND i.assigned_technician_id IN (" . implode(', ', $placeholders) . ")
              AND (
                    i.status IN (" . $statusList . ")
                 OR (i.status = 'RESOLVED' AND i.resolved_at >= CURRENT_DATE)
              )
            ORDER BY i.assigned_at DESC, i.id DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $technicianId = (int)$row['technician_id'];
            $grouped[$technicianId][] = [
                'status' => strtoupper((string)$row['status']),
                'location_id' => $row['location_id'] !== null ? (int)$row['location_id'] : null,
                'location_name' => $row['location_name'] !== null ? (string)$row['location_name'] : null,
                'location_zone_id' => $row['location_zone_id'] !== null ? (int)$row['location_zone_id'] : null,
                'latitude' => $row['location_latitude'] !== null ? (float)$row['location_latitude'] : null,
                'longitude' => $row['location_longitude'] !== null ? (float)$row['location_longitude'] : null,
                'assigned_at' => $row['assigned_at'] !== null ? (string)$row['assigned_at'] : null,
                'resolved_at' => $row['resolved_at'] !== null ? (string)$row['resolved_at'] : null,
                'assigned_today' => (int)($row['assigned_today'] ?? 0) === 1,
            ];
        }

        return $grouped;
    }

    /**
     * Preventivos de hoy por técnico (contrato §4.1, penalización −5 por orden).
     *
     * @param list<int> $technicianIds
     * @return array<int, int>
     */
    private function findPreventiveCounts(array $technicianIds): array
    {
        if ($technicianIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($technicianIds as $index => $technicianId) {
            $key = ':prev_tech_' . $index;
            $placeholders[] = $key;
            $params[$key] = $technicianId;
        }

        $statusList = implode(', ', $this->quoteList(self::PREVENTIVE_STATUSES));

        $sql = "
            SELECT assigned_technician_id AS technician_id, COUNT(*) AS total
            FROM preventive_orders
            WHERE deleted_at IS NULL
              AND scheduled_date = CURRENT_DATE
              AND status IN (" . $statusList . ")
              AND assigned_technician_id IN (" . implode(', ', $placeholders) . ")
            GROUP BY assigned_technician_id
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(int)$row['technician_id']] = (int)$row['total'];
        }

        return $counts;
    }

    /**
     * Proyecta un técnico a la foto que consume el caso de uso.
     *
     * @param array<string, mixed> $technician
     * @param list<array<string, mixed>> $stops
     * @return array<string, mixed>
     */
    private function projectTechnician(
        array $technician,
        array $stops,
        int $preventiveOrdersToday,
        ?int $targetLocationId,
        ?int $zoneId
    ): array {
        $activeIncidents = 0;
        $pendingInfo = 0;
        $liveStopsAtTarget = 0;
        $otherZoneLocations = [];
        $inProgress = null;
        $lastCompleted = null;
        $lastAssignedAt = null;

        foreach ($stops as $stop) {
            $status = $stop['status'];

            if (in_array($status, self::WORKLOAD_STATUSES, true)) {
                $activeIncidents++;
            } elseif ($status === 'PENDING_INFO') {
                $pendingInfo++;
            }

            if (in_array($status, self::LIVE_STOP_STATUSES, true)) {
                if ($stop['location_id'] === $targetLocationId) {
                    $liveStopsAtTarget++;
                } elseif ($zoneId !== null && $stop['location_zone_id'] === $zoneId && $stop['location_id'] !== null) {
                    $otherZoneLocations[$stop['location_id']] = true;
                }
            }

            // La referencia de posición es la parada EN CURSO más reciente; el
            // recorrido llega ordenado por asignación descendente, así que la primera
            // que se encuentra es la vigente.
            if ($status === 'IN_PROGRESS' && $inProgress === null) {
                $inProgress = $stop;
            }

            if ($status === 'RESOLVED' && $stop['resolved_at'] !== null) {
                if ($lastCompleted === null || $stop['resolved_at'] > $lastCompleted['resolved_at']) {
                    $lastCompleted = $stop;
                }
            }

            if ($stop['assigned_today'] && $stop['assigned_at'] !== null) {
                if ($lastAssignedAt === null || $stop['assigned_at'] > $lastAssignedAt) {
                    $lastAssignedAt = $stop['assigned_at'];
                }
            }
        }

        return [
            'technician_id' => $technician['technician_id'],
            'name' => $technician['name'],
            'operator_code' => $technician['operator_code'],
            'is_on_call' => $technician['is_on_call'],
            'is_active' => $technician['is_active'],
            'affinity' => $technician['affinity'],
            'active_incidents' => $activeIncidents,
            'pending_info_incidents' => $pendingInfo,
            'preventive_orders_today' => $preventiveOrdersToday,
            'has_live_stop_at_target_location' => $liveStopsAtTarget > 0,
            'live_stops_in_zone_other_locations' => count($otherZoneLocations),
            // La incompatibilidad inter-sede del contrato §4.1 es una parada EN CURSO
            // en otra sede; una parada asignada sin empezar no bloquea al técnico, sólo
            // suma carga.
            'has_in_progress_elsewhere' => $inProgress !== null
                && $inProgress['location_id'] !== $targetLocationId,
            'last_assigned_at' => $lastAssignedAt,
            'in_progress_location' => $this->referenceFrom($inProgress),
            'last_completed_location' => $this->referenceFrom($lastCompleted),
        ];
    }

    /**
     * Referencia de distancia de una parada (contrato §4.3).
     *
     * @param array<string, mixed>|null $stop
     * @return array{location_id: int|null, location_name: string|null, latitude: float|null, longitude: float|null}|null
     */
    private function referenceFrom(?array $stop): ?array
    {
        if ($stop === null) {
            return null;
        }

        return [
            'location_id' => $stop['location_id'],
            'location_name' => $stop['location_name'],
            'latitude' => $stop['latitude'],
            'longitude' => $stop['longitude'],
        ];
    }

    /**
     * Afinidad territorial de la fila del cuadrante.
     */
    private function affinityFrom(mixed $isPrimary): string
    {
        if ($isPrimary === null) {
            return ZoneAffinityType::NONE->value;
        }

        return $this->toBoolean($isPrimary)
            ? ZoneAffinityType::PRIMARY->value
            : ZoneAffinityType::BACKUP->value;
    }

    /**
     * Lista de literales SQL del vocabulario cerrado del repositorio.
     *
     * Los valores son constantes de clase y nunca provienen del cliente: la lista
     * se interpola para que el motor pueda usar el índice de estado.
     *
     * @param list<string> $values
     * @return list<string>
     */
    private function quoteList(array $values): array
    {
        return array_map(static fn (string $value): string => "'" . $value . "'", $values);
    }

    /**
     * Booleano del motor, sin suponer la forma que devuelve el driver (`t`/`f`, `1`/`0`).
     */
    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
