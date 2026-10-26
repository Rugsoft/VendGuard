<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Core\Domain\Repository\TechnicianSuggestionRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\AbsenceReason;
use VendGuard\Core\Domain\ValueObject\ZoneAffinityType;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

/**
 * PdoTechnicianSuggestionRepository
 *
 * Lectura del recomendador de asignación contra PostgreSQL (Módulo 17, RF-04 a
 * RF-06, enriquecido por el Módulo 23). Compone la foto operativa en **ocho
 * viajes fijos**, independientes del número de técnicos del parque:
 *
 *   1. la avería con su sede, su zona, su tipo de máquina, su modelo y su categoría;
 *   2. la base central de ruta (`route_settings`);
 *   3. los técnicos activos con su afinidad territorial viva;
 *   4. las paradas vivas (y las resueltas de hoy) de esos técnicos;
 *   5. los preventivos de hoy de esos técnicos;
 *   6. las solicitudes `PENDING` de la avería (referencias y fuera de catálogo);
 *   7. el universo de repuestos activos compatibles con el modelo y la categoría;
 *   8. los saldos de furgoneta de los candidatos para esas piezas.
 *
 * El N+1 se evita agrupando en PHP las consultas por candidato, que llegan
 * acotadas al conjunto de candidatos y al de piezas relevantes: la carga de
 * trabajo de un técnico son sus paradas vivas —unas pocas—, no su histórico
 * completo. Se prefiere agregar en PHP, y no con subconsultas correlacionadas por
 * técnico, porque así el SQL se lee como lo que consulta y no como una máquina de
 * estados de contadores.
 *
 * El cruce de furgoneta es **solo lectura**: ninguna de estas consultas escribe,
 * abre transacción ni cambia el estado de la avería, y el coste del repuesto no
 * sale del catálogo (`Art. V.4`).
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

    /**
     * Mapeo de la categoría de la avería a la categoría del catálogo de repuestos
     * (Módulo 23, contrato §3.2). `OTHER` no filtra: cualquier categoría compatible
     * con el modelo vale.
     */
    private const INCIDENT_CATEGORY_TO_PART_CATEGORY = [
        'TEMPERATURE_COLD' => 'THERMAL',
        'PAYMENT_SYSTEM' => 'PAYMENT_SYSTEM',
        'PRODUCT_JAM' => 'MECHANICAL',
        'ELECTRICAL_OFF' => 'ELECTRONIC',
        'OTHER' => null,
    ];

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

        $pendingRequests = $this->findPendingRequests($incidentId);
        $compatibleParts = $this->findCompatibleParts($incident['machine_model'], $incident['category']);
        $vanStockByTechnician = $this->findVanStockForCandidates(
            $technicianIds,
            $this->relevantSparePartIds($pendingRequests['catalog'], $compatibleParts)
        );

        $rows = [];
        foreach ($technicians as $technician) {
            $technicianId = $technician['technician_id'];
            $rows[] = $this->projectTechnician(
                $technician,
                $stopsByTechnician[$technicianId] ?? [],
                $preventiveByTechnician[$technicianId] ?? 0,
                $targetLocationId,
                $zoneId,
                $vanStockByTechnician[$technicianId] ?? []
            );
        }

        return [
            'incident' => [
                'id' => $incident['id'],
                'ticket_code' => $incident['ticket_code'],
                'priority' => $incident['priority'],
                'machine_type' => $incident['machine_type'],
                'category' => $incident['category'],
                'machine_model' => $incident['machine_model'],
                'pending_catalog_requests' => $pendingRequests['catalog'],
                'has_pending_out_of_catalog_requests' => $pendingRequests['has_out_of_catalog'],
                'compatible_parts' => $compatibleParts,
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
                i.category,
                i.location_id,
                l.site_code AS location_site_code,
                l.name AS location_name,
                l.postal_code AS location_postal_code,
                l.zone_id AS location_zone_id,
                l.latitude AS location_latitude,
                l.longitude AS location_longitude,
                z.name AS zone_name,
                COALESCE(m.machine_type, i.machine_type_snapshot) AS machine_type,
                m.model AS machine_model
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
        $machineModel = isset($row['machine_model']) && $row['machine_model'] !== null
            ? trim((string)$row['machine_model'])
            : null;

        return [
            'id' => (int)$row['id'],
            'ticket_code' => (string)$row['ticket_code'],
            'priority' => strtoupper((string)$row['urgency']),
            'machine_type' => $machineType,
            'category' => $row['category'] !== null ? strtoupper((string)$row['category']) : null,
            'machine_model' => $machineModel !== null && $machineModel !== '' ? $machineModel : null,
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
     * @return list<array{technician_id: int, name: string, operator_code: string|null, is_on_call: bool, is_active: bool, affinity: string, shift_status: string, active_absence?: array<string, string>}>
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
                a.is_primary,
                ts.status AS shift_status,
                tab.reason AS absence_reason
            FROM users u
            LEFT JOIN technician_zone_assignments a
                   ON a.technician_id = u.id
                  AND a.removed_at IS NULL
                  AND a.zone_id = :zone_id
            LEFT JOIN technician_shifts ts
                   ON ts.technician_id = u.id
                  AND ts.closed_at IS NULL
            LEFT JOIN technician_absences tab
                   ON tab.technician_id = u.id
                  AND tab.cancelled_at IS NULL
                  AND CURRENT_TIMESTAMP BETWEEN tab.starts_at AND tab.ends_at
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
            $absenceReason = isset($row['absence_reason']) && $row['absence_reason'] !== null ? (string)$row['absence_reason'] : null;
            $absenceReasonEnum = $absenceReason !== null ? AbsenceReason::tryFrom($absenceReason) : null;

            $rowItem = [
                'technician_id' => (int)$row['technician_id'],
                'name' => (string)$row['name'],
                'operator_code' => $row['operator_code'] !== null ? (string)$row['operator_code'] : null,
                'is_on_call' => $this->toBoolean($row['is_on_call'] ?? false),
                'is_active' => $this->toBoolean($row['is_active'] ?? false),
                'affinity' => $this->affinityFrom($row['is_primary']),
            ];

            // RF-03.1: la ausencia de fila de turno abierto no es «sin dato», es
            // OFF_SHIFT. Se explicita en la foto para que el recomendador no tenga
            // que adivinar la disponibilidad por la ausencia de una clave.
            $rowItem['shift_status'] = $row['shift_status'] !== null
                ? (string)$row['shift_status']
                : 'OFF_SHIFT';
            if ($absenceReasonEnum !== null) {
                $rowItem['active_absence'] = [
                    'reason' => $absenceReasonEnum->value,
                    'reason_label' => $absenceReasonEnum->label(),
                ];
            }

            $rows[] = $rowItem;
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
     * Solicitudes `PENDING` de la avería (Módulo 23, contrato §3.2).
     *
     * Una sola consulta devuelve las referencias catalogadas con su cantidad
     * acumulada y cuántas filas fuera de catálogo hay: el caso de uso distingue
     * ambas cosas sin un segundo viaje.
     *
     * @return array{catalog: array<int, int>, has_out_of_catalog: bool}
     */
    private function findPendingRequests(int $incidentId): array
    {
        $sql = "
            SELECT
                spare_part_id,
                SUM(quantity) AS requested_qty,
                SUM(CASE WHEN spare_part_id IS NULL THEN 1 ELSE 0 END) AS out_of_catalog_rows
            FROM spare_part_requests
            WHERE incident_id = :incident_id
              AND status = 'PENDING'
            GROUP BY spare_part_id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':incident_id', $incidentId, PDO::PARAM_INT);
        $stmt->execute();

        $catalog = [];
        $hasOutOfCatalog = false;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['spare_part_id'] === null) {
                $hasOutOfCatalog = (int)($row['out_of_catalog_rows'] ?? 0) > 0;
                continue;
            }

            $catalog[(int)$row['spare_part_id']] = (int)($row['requested_qty'] ?? 0);
        }

        return ['catalog' => $catalog, 'has_out_of_catalog' => $hasOutOfCatalog];
    }

    /**
     * Universo de repuestos activos compatibles con el modelo y la categoría del
     * fallo (Módulo 23, contrato §3.2).
     *
     * La categoría de la avería se traduce aquí al vocabulario del catálogo; `OTHER`
     * —y una categoría ausente— no filtra por categoría. Sin modelo de máquina no hay
     * compatibilidad que consultar y el universo es vacío: el caso de uso lo trata
     * como «sin repuestos catalogados para este modelo».
     *
     * @return list<array{spare_part_id: int, part_code: string, part_name: string, category: string}>
     */
    private function findCompatibleParts(?string $machineModel, ?string $incidentCategory): array
    {
        if ($machineModel === null || trim($machineModel) === '') {
            return [];
        }

        $partCategory = self::sparePartCategoryFor($incidentCategory);

        $sql = "
            SELECT DISTINCT
                sp.id AS spare_part_id,
                sp.part_code,
                sp.name AS part_name,
                sp.category
            FROM spare_parts sp
            INNER JOIN spare_part_compatibilities spc
                    ON spc.spare_part_id = sp.id
                   AND spc.is_active = " . $this->dialect->booleanLiteral(true) . "
                   AND spc.deleted_at IS NULL
                   AND TRIM(LOWER(spc.machine_model)) = TRIM(LOWER(:machine_model))
            WHERE sp.is_active = " . $this->dialect->booleanLiteral(true) . "
              AND sp.deleted_at IS NULL
        ";

        // Vocabulario cerrado del repositorio, nunca dato del cliente.
        if ($partCategory !== null) {
            $sql .= " AND sp.category = '" . $partCategory . "'";
        }

        $sql .= ' ORDER BY sp.part_code ASC, sp.id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':machine_model', trim($machineModel), PDO::PARAM_STR);
        $stmt->execute();

        $parts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $parts[] = [
                'spare_part_id' => (int)$row['spare_part_id'],
                'part_code' => (string)$row['part_code'],
                'part_name' => (string)$row['part_name'],
                'category' => (string)$row['category'],
            ];
        }

        return $parts;
    }

    /**
     * Saldos de furgoneta de los candidatos para las piezas relevantes.
     *
     * «Relevantes» son las solicitadas en la avería y las del universo compatible:
     * el cruce de nivel 1 y el de nivel 2 se resuelven con este único viaje.
     *
     * @param list<int> $technicianIds
     * @param list<int> $sparePartIds
     * @return array<int, array<int, int>> técnico => pieza => unidades
     */
    private function findVanStockForCandidates(array $technicianIds, array $sparePartIds): array
    {
        if ($technicianIds === [] || $sparePartIds === []) {
            return [];
        }

        $technicianPlaceholders = [];
        $partPlaceholders = [];
        $params = [];

        foreach ($technicianIds as $index => $technicianId) {
            $key = ':stock_tech_' . $index;
            $technicianPlaceholders[] = $key;
            $params[$key] = $technicianId;
        }
        foreach ($sparePartIds as $index => $sparePartId) {
            $key = ':stock_part_' . $index;
            $partPlaceholders[] = $key;
            $params[$key] = $sparePartId;
        }

        $sql = "
            SELECT technician_id, spare_part_id, quantity_available
            FROM technician_van_stocks
            WHERE technician_id IN (" . implode(', ', $technicianPlaceholders) . ")
              AND spare_part_id IN (" . implode(', ', $partPlaceholders) . ")
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $stock = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stock[(int)$row['technician_id']][(int)$row['spare_part_id']] = (int)$row['quantity_available'];
        }

        return $stock;
    }

    /**
     * Unión de las piezas solicitadas en la avería y las compatibles con el modelo.
     *
     * @param array<int, int> $pendingCatalogRequests
     * @param list<array{spare_part_id: int}> $compatibleParts
     * @return list<int>
     */
    private function relevantSparePartIds(array $pendingCatalogRequests, array $compatibleParts): array
    {
        $ids = array_map('intval', array_keys($pendingCatalogRequests));
        foreach ($compatibleParts as $part) {
            $ids[] = (int)$part['spare_part_id'];
        }

        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Traduce la categoría de la avería al vocabulario de `spare_parts.category`
     * (contrato §3.2). Una categoría desconocida no filtra, igual que `OTHER`.
     */
    private static function sparePartCategoryFor(?string $incidentCategory): ?string
    {
        if ($incidentCategory === null) {
            return null;
        }

        return self::INCIDENT_CATEGORY_TO_PART_CATEGORY[strtoupper(trim($incidentCategory))] ?? null;
    }

    /**
     * Proyecta un técnico a la foto que consume el caso de uso.
     *
     * @param array<string, mixed> $technician
     * @param list<array<string, mixed>> $stops
     * @param array<int, int> $vanStock Saldo por pieza de la furgoneta del candidato.
     * @return array<string, mixed>
     */
    private function projectTechnician(
        array $technician,
        array $stops,
        int $preventiveOrdersToday,
        ?int $targetLocationId,
        ?int $zoneId,
        array $vanStock = []
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
            'shift_status' => $technician['shift_status'] ?? null,
            'active_absence' => $technician['active_absence'] ?? null,
            'van_stock' => $vanStock,
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
