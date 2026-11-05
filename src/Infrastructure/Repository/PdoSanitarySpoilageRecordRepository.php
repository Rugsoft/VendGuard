<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use VendGuard\Core\Domain\Model\SanitarySpoilageRecord;
use VendGuard\Core\Domain\Repository\SanitarySpoilageRecordRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoSanitarySpoilageRecordRepository — persistencia PDO/PostgreSQL del Acta de
 * Merma APPCC (Módulo 29, Tabla 40).
 *
 * Decisiones de persistencia:
 *
 *   1. **La máquina se bloquea antes de decidir.** `lockAndInspectMachineQuarantineState()`
 *      usa `SELECT ... FOR UPDATE OF m` sobre `machines`. Dos levantamientos
 *      concurrentes de la misma máquina se serializan y el segundo ya no ve el
 *      estado `QUARANTINE` del primero.
 *
 *   2. **La configuración sanitaria se lee de `machines`.** El borrador de la
 *      tarea citaba `machine_sanitary_settings`, pero esa tabla no existe en el
 *      esquema canónico: el estado, la frecuencia y las fechas normativas viven
 *      en columnas de `machines`. La frecuencia efectiva se resuelve con
 *      `preventive_settings` por etiqueta de tipología, igual que
 *      `PdoPreventiveSettingsRepository`.
 *
 *   3. **El origen no se confía al cliente.** `resolveLatestQuarantineEpisodeLinks()`
 *      parte del último acta de la máquina y busca la avería térmica resuelta y
 *      la orden preventiva no conforme posteriores a esa frontera. La prioridad
 *      se aplica en PHP con tres constantes de la entidad (`QA 2`).
 *
 *   4. **La numeración oficial se reserva con bloqueo de transacción.**
 *      `nextRecordCode()` toma `pg_advisory_xact_lock(hashtext(...))` y calcula
 *      el siguiente correlativo del año. El caso de uso debe llamarlo dentro de
 *      la misma transacción que `create()`.
 *
 *   5. **El acta solo se actualiza donde el disparador lo permite.** El
 *      `UPDATE` de `updateLossEstimate()` toca exclusivamente el importe y sus
 *      dos columnas de auditoría; los hechos sanitarios quedan blindados por
 *      `trg_immutable_sanitary_spoilage_records` (Art. III.3, `C5`).
 *
 *   6. **El certificado siempre tiene un anclaje válido.**
 *      `sanitary_certificates.preventive_order_id` es `NOT NULL`. Si al
 *      levantar la cuarentena no había ninguna reinspección pendiente, se
 *      documenta la inspección con una orden `REINSPECTION` ya completada; no
 *      se reutiliza una orden de otro tipo ni se deja el certificado sin
 *      expedir (`RF-03.1`, `C6`).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class PdoSanitarySpoilageRecordRepository implements SanitarySpoilageRecordRepositoryInterface
{
    /**
     * Proyección única del acta con los metadatos de máquina, sede y usuarios.
     * Todos los JOIN son internos salvo el del coordinador que ajustó el coste,
     * porque un acta siempre tiene máquina, sede, técnico y registrador.
     */
    private const SELECT_RECORD = "
        SELECT
            r.id,
            r.record_code,
            r.machine_id,
            r.incident_id,
            r.maintenance_order_id,
            r.technician_id,
            r.registered_by,
            r.quarantine_origin,
            r.discarded_units_count,
            r.empty_machine_confirmed,
            r.affected_batch_codes,
            r.cabin_temp_at_removal,
            r.restored_stabilized_temp_c,
            r.disposal_method,
            r.evidence_photo_url,
            r.sanitary_notes,
            r.estimated_merchandise_loss_eur,
            r.loss_adjusted_by,
            r.loss_adjustment_reason,
            r.certificate_code,
            r.created_at,
            r.updated_at,
            m.code AS machine_code,
            m.model AS machine_model,
            l.name AS location_name,
            t.name AS technician_name,
            t.operator_code AS technician_operator_code,
            rb.name AS registered_by_name,
            la.name AS loss_adjusted_by_name
        FROM sanitary_spoilage_records r
        INNER JOIN machines m ON m.id = r.machine_id
        INNER JOIN locations l ON l.id = m.location_id
        INNER JOIN users t ON t.id = r.technician_id
        INNER JOIN users rb ON rb.id = r.registered_by
        LEFT JOIN users la ON la.id = r.loss_adjusted_by
    ";

    /** Estados de avería que ya no bloquean el levantamiento (`C1`). */
    private const CLOSED_INCIDENT_STATUSES = ['RESOLVED', 'CLOSED', 'CANCELLED'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function lockAndInspectMachineQuarantineState(int $machineId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                m.id,
                m.code AS machine_code,
                m.location_id,
                m.machine_type,
                m.sanitary_status,
                m.sanitary_frequency_days,
                m.is_active,
                m.deleted_at,
                COALESCE(m.sanitary_frequency_days, ps.default_frequency_days, 15) AS effective_frequency_days
            FROM machines m
            LEFT JOIN preventive_settings ps
                ON ps.machine_type::text = m.machine_type::text
            WHERE m.id = :machine_id
            FOR UPDATE OF m
        ");
        $stmt->execute([':machine_id' => $machineId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'machine_code' => (string)$row['machine_code'],
            'location_id' => (int)$row['location_id'],
            'machine_type' => (string)$row['machine_type'],
            'sanitary_status' => (string)$row['sanitary_status'],
            'sanitary_frequency_days' => $row['sanitary_frequency_days'] !== null
                ? (int)$row['sanitary_frequency_days']
                : null,
            'effective_frequency_days' => $row['effective_frequency_days'] !== null
                ? (int)$row['effective_frequency_days']
                : 15,
            'is_active' => $this->asBool($row['is_active']),
            'deleted_at' => $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function hasOpenCorrectiveIncident(int $machineId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM incidents
            WHERE machine_id = :machine_id
              AND deleted_at IS NULL
              AND status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')
            LIMIT 1
        ");
        $stmt->execute([':machine_id' => $machineId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function isActiveTechnician(int $userId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM users
            WHERE id = :user_id
              AND role = 'TECHNICIAN'
              AND is_active = TRUE
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function resolveLatestQuarantineEpisodeLinks(int $machineId): array
    {
        $stmtBoundary = $this->pdo->prepare("
            SELECT created_at
            FROM sanitary_spoilage_records
            WHERE machine_id = :machine_id
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stmtBoundary->execute([':machine_id' => $machineId]);
        $boundaryValue = $stmtBoundary->fetchColumn();
        $boundary = $boundaryValue !== false ? (string)$boundaryValue : null;

        $stmtIncident = $this->pdo->prepare("
            SELECT id, ticket_code, category, resolved_at, cold_alert_sent_at
            FROM incidents
            WHERE machine_id = :machine_id
              AND deleted_at IS NULL
              AND status = 'RESOLVED'
              AND resolved_at IS NOT NULL
              AND (cold_alert_sent_at IS NOT NULL OR category = 'TEMPERATURE_COLD')
              AND (:has_boundary = 0 OR resolved_at > CAST(:boundary AS timestamptz))
            ORDER BY resolved_at DESC, id DESC
            LIMIT 1
        ");
        $stmtIncident->execute([
            ':machine_id' => $machineId,
            ':has_boundary' => $boundary !== null ? 1 : 0,
            ':boundary' => $boundary,
        ]);
        $incidentRow = $stmtIncident->fetch(PDO::FETCH_ASSOC);

        $stmtOrder = $this->pdo->prepare("
            SELECT id, order_code, result, completed_at
            FROM preventive_orders
            WHERE machine_id = :machine_id
              AND deleted_at IS NULL
              AND status = 'COMPLETED'
              AND result = 'NO_CONFORME'
              AND completed_at IS NOT NULL
              AND (:has_boundary = 0 OR completed_at > CAST(:boundary AS timestamptz))
            ORDER BY completed_at DESC, id DESC
            LIMIT 1
        ");
        $stmtOrder->execute([
            ':machine_id' => $machineId,
            ':has_boundary' => $boundary !== null ? 1 : 0,
            ':boundary' => $boundary,
        ]);
        $orderRow = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        $incident = $incidentRow !== false
            ? [
                'id' => (int)$incidentRow['id'],
                'ticket_code' => (string)$incidentRow['ticket_code'],
                'category' => (string)$incidentRow['category'],
                'resolved_at' => (string)$incidentRow['resolved_at'],
                'cold_alert_sent_at' => $incidentRow['cold_alert_sent_at'] !== null
                    ? (string)$incidentRow['cold_alert_sent_at']
                    : null,
            ]
            : null;

        $order = $orderRow !== false
            ? [
                'id' => (int)$orderRow['id'],
                'order_code' => (string)$orderRow['order_code'],
                'result' => (string)$orderRow['result'],
                'completed_at' => (string)$orderRow['completed_at'],
            ]
            : null;

        if ($incident !== null) {
            $origin = SanitarySpoilageRecord::QUARANTINE_ORIGIN_BIOLOGICAL_CLOCK;
        } elseif ($order !== null) {
            $origin = SanitarySpoilageRecord::QUARANTINE_ORIGIN_PREVENTIVE_NON_CONFORMITY;
        } else {
            $origin = SanitarySpoilageRecord::QUARANTINE_ORIGIN_MANUAL_SANITARY_LOCK;
        }

        return [
            'quarantine_origin' => $origin,
            'incident_id' => $incident['id'] ?? null,
            'maintenance_order_id' => $order['id'] ?? null,
            'episode_started_at' => $boundary,
            'latest_resolved_incident' => $incident,
            'latest_non_conform_order' => $order,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function nextRecordCode(int $year): string
    {
        if ($year < 2000 || $year > 9999) {
            throw new InvalidArgumentException(
                sprintf('El año «%d» no es válido para numerar un acta APPCC.', $year)
            );
        }

        $prefix = sprintf('ACTA-MERMA-%04d-', $year);

        $stmtLock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key))');
        $stmtLock->execute([':lock_key' => 'sanitary_spoilage_record_code_' . $year]);

        $stmtSeq = $this->pdo->prepare("
            SELECT COALESCE(
                MAX(CAST(SUBSTRING(record_code FROM '([0-9]{4})$') AS INTEGER)),
                0
            ) + 1
            FROM sanitary_spoilage_records
            WHERE record_code LIKE :prefix
        ");
        $stmtSeq->execute([':prefix' => $prefix . '%']);
        $next = (int)$stmtSeq->fetchColumn();

        return sprintf('ACTA-MERMA-%04d-%04d', $year, $next);
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $data): SanitarySpoilageRecord
    {
        $required = [
            'record_code',
            'machine_id',
            'technician_id',
            'registered_by',
            'quarantine_origin',
            'discarded_units_count',
            'affected_batch_codes',
            'cabin_temp_at_removal',
            'restored_stabilized_temp_c',
            'disposal_method',
            'evidence_photo_url',
            'sanitary_notes',
            'certificate_code',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException(
                    sprintf('Falta el campo obligatorio «%s» para persistir el acta APPCC.', $field)
                );
            }
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO sanitary_spoilage_records (
                record_code,
                machine_id,
                incident_id,
                maintenance_order_id,
                technician_id,
                registered_by,
                quarantine_origin,
                discarded_units_count,
                empty_machine_confirmed,
                affected_batch_codes,
                cabin_temp_at_removal,
                restored_stabilized_temp_c,
                disposal_method,
                evidence_photo_url,
                sanitary_notes,
                estimated_merchandise_loss_eur,
                loss_adjusted_by,
                loss_adjustment_reason,
                certificate_code
            ) VALUES (
                :record_code,
                :machine_id,
                :incident_id,
                :maintenance_order_id,
                :technician_id,
                :registered_by,
                :quarantine_origin,
                :discarded_units_count,
                :empty_machine_confirmed,
                :affected_batch_codes,
                :cabin_temp_at_removal,
                :restored_stabilized_temp_c,
                :disposal_method,
                :evidence_photo_url,
                :sanitary_notes,
                :estimated_merchandise_loss_eur,
                :loss_adjusted_by,
                :loss_adjustment_reason,
                :certificate_code
            )
            RETURNING id
        ");

        $stmt->execute([
            ':record_code' => (string)$data['record_code'],
            ':machine_id' => (int)$data['machine_id'],
            ':incident_id' => isset($data['incident_id']) ? (int)$data['incident_id'] : null,
            ':maintenance_order_id' => isset($data['maintenance_order_id'])
                ? (int)$data['maintenance_order_id']
                : null,
            ':technician_id' => (int)$data['technician_id'],
            ':registered_by' => (int)$data['registered_by'],
            ':quarantine_origin' => (string)$data['quarantine_origin'],
            ':discarded_units_count' => (int)$data['discarded_units_count'],
            ':empty_machine_confirmed' => !empty($data['empty_machine_confirmed']) ? 1 : 0,
            ':affected_batch_codes' => (string)$data['affected_batch_codes'],
            ':cabin_temp_at_removal' => (float)$data['cabin_temp_at_removal'],
            ':restored_stabilized_temp_c' => (float)$data['restored_stabilized_temp_c'],
            ':disposal_method' => (string)$data['disposal_method'],
            ':evidence_photo_url' => (string)$data['evidence_photo_url'],
            ':sanitary_notes' => (string)$data['sanitary_notes'],
            ':estimated_merchandise_loss_eur' => (float)($data['estimated_merchandise_loss_eur'] ?? 0.0),
            ':loss_adjusted_by' => isset($data['loss_adjusted_by']) ? (int)$data['loss_adjusted_by'] : null,
            ':loss_adjustment_reason' => isset($data['loss_adjustment_reason'])
                ? (string)$data['loss_adjustment_reason']
                : null,
            ':certificate_code' => (string)$data['certificate_code'],
        ]);

        $newId = (int)$stmt->fetchColumn();
        $record = $this->findById($newId);
        if ($record === null) {
            throw new RuntimeException(
                sprintf('No se pudo recuperar el acta APPCC recién creada con ID %d.', $newId)
            );
        }

        return $record;
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?SanitarySpoilageRecord
    {
        $stmt = $this->pdo->prepare(self::SELECT_RECORD . ' WHERE r.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $this->hydrateOrNull($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * {@inheritdoc}
     */
    public function findByIdForUpdate(int $id): ?SanitarySpoilageRecord
    {
        $stmt = $this->pdo->prepare(self::SELECT_RECORD . ' WHERE r.id = :id FOR UPDATE OF r');
        $stmt->execute([':id' => $id]);

        return $this->hydrateOrNull($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * {@inheritdoc}
     */
    public function findByMachineId(int $machineId): array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT_RECORD . '
            WHERE r.machine_id = :machine_id
            ORDER BY r.created_at DESC, r.id DESC'
        );
        $stmt->execute([':machine_id' => $machineId]);

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * {@inheritdoc}
     *
     * El borrador de la tarea citaba `mss.sanitary_status`; la configuración
     * sanitaria vive en `machines`, así que la consulta lee la fuente real.
     */
    public function fetchKpiSummary(): array
    {
        $row = $this->pdo->query("
            SELECT
                (
                    SELECT COUNT(*)
                    FROM machines m
                    WHERE m.is_active = TRUE
                      AND m.deleted_at IS NULL
                      AND m.sanitary_status = 'QUARANTINE'
                ) AS active_quarantined_machines_count,
                (
                    SELECT COUNT(*)
                    FROM sanitary_spoilage_records
                ) AS total_records_count,
                (
                    SELECT COALESCE(SUM(discarded_units_count), 0)
                    FROM sanitary_spoilage_records
                ) AS total_discarded_units_count,
                (
                    SELECT COALESCE(ROUND(SUM(estimated_merchandise_loss_eur), 2), 0.00)
                    FROM sanitary_spoilage_records
                ) AS total_estimated_loss_eur
        ")->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException('No se pudieron calcular los indicadores globales del Acta de Merma.');
        }

        return [
            'active_quarantined_machines_count' => (int)$row['active_quarantined_machines_count'],
            'total_records_count' => (int)$row['total_records_count'],
            'total_discarded_units_count' => (int)$row['total_discarded_units_count'],
            'total_estimated_loss_eur' => round((float)$row['total_estimated_loss_eur'], 2),
        ];
    }

    /**
     * {@inheritdoc}
     *
     * `machines` no tiene `internal_code`: el código rotulado del parque es
     * `machines.code`, que es el que se ordena y se publica como
     * `machine_code`.
     */
    public function fetchActiveQuarantinedMachines(): array
    {
        $stmt = $this->pdo->query("
            SELECT
                m.id AS machine_id,
                m.code AS machine_code,
                m.model AS machine_model,
                m.machine_type,
                m.location_id,
                l.name AS location_name,
                m.sanitary_status,
                m.last_sanitary_inspection_at,
                m.next_sanitary_inspection_due,
                inc.id AS open_incident_id,
                inc.ticket_code AS open_incident_code,
                inc.status AS open_incident_status
            FROM machines m
            INNER JOIN locations l ON l.id = m.location_id
            LEFT JOIN LATERAL (
                SELECT i.id, i.ticket_code, i.status
                FROM incidents i
                WHERE i.machine_id = m.id
                  AND i.deleted_at IS NULL
                  AND i.status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')
                ORDER BY i.created_at DESC, i.id DESC
                LIMIT 1
            ) inc ON TRUE
            WHERE m.is_active = TRUE
              AND m.deleted_at IS NULL
              AND m.sanitary_status = 'QUARANTINE'
            ORDER BY m.code ASC
        ");

        $machines = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $machines[] = [
                'machine_id' => (int)$row['machine_id'],
                'machine_code' => (string)$row['machine_code'],
                'machine_model' => (string)$row['machine_model'],
                'machine_type' => (string)$row['machine_type'],
                'location_id' => (int)$row['location_id'],
                'location_name' => (string)$row['location_name'],
                'sanitary_status' => (string)$row['sanitary_status'],
                'last_sanitary_inspection_at' => $row['last_sanitary_inspection_at'] !== null
                    ? (string)$row['last_sanitary_inspection_at']
                    : null,
                'next_sanitary_inspection_due' => $row['next_sanitary_inspection_due'] !== null
                    ? (string)$row['next_sanitary_inspection_due']
                    : null,
                'has_open_incident' => $row['open_incident_id'] !== null,
                'open_incident_id' => $row['open_incident_id'] !== null
                    ? (int)$row['open_incident_id']
                    : null,
                'open_incident_code' => $row['open_incident_code'] !== null
                    ? (string)$row['open_incident_code']
                    : null,
                'open_incident_status' => $row['open_incident_status'] !== null
                    ? (string)$row['open_incident_status']
                    : null,
            ];
        }

        return $machines;
    }

    /**
     * {@inheritdoc}
     */
    public function findFilteredForCoordinator(array $filters): array
    {
        $where = [];
        $params = [];

        if (isset($filters['machine_id']) && $filters['machine_id'] !== '') {
            $machineId = (int)$filters['machine_id'];
            if ($machineId > 0) {
                $where[] = 'r.machine_id = :machine_id';
                $params[':machine_id'] = $machineId;
            }
        }

        $origin = isset($filters['quarantine_origin']) && is_string($filters['quarantine_origin'])
            ? strtoupper(trim($filters['quarantine_origin']))
            : '';
        if ($origin !== '') {
            $where[] = 'r.quarantine_origin = :quarantine_origin';
            $params[':quarantine_origin'] = $origin;
        }

        $disposalMethod = isset($filters['disposal_method']) && is_string($filters['disposal_method'])
            ? strtoupper(trim($filters['disposal_method']))
            : '';
        if ($disposalMethod !== '') {
            $where[] = 'r.disposal_method = :disposal_method';
            $params[':disposal_method'] = $disposalMethod;
        }

        $dateFrom = $this->normalizeDateBoundary($filters['date_from'] ?? null, '00:00:00');
        if ($dateFrom !== null) {
            $where[] = 'r.created_at >= CAST(:date_from AS timestamptz)';
            $params[':date_from'] = $dateFrom;
        }

        $dateTo = $this->normalizeDateBoundary($filters['date_to'] ?? null, '23:59:59');
        if ($dateTo !== null) {
            $where[] = 'r.created_at <= CAST(:date_to AS timestamptz)';
            $params[':date_to'] = $dateTo;
        }

        $search = isset($filters['search']) && is_string($filters['search'])
            ? trim($filters['search'])
            : '';
        if ($search !== '') {
            // `!` como escape: un `%` tecleado por Coordinación busca un porcentaje
            // y no «cualquier cosa».
            $where[] = "(
                r.record_code ILIKE :search ESCAPE '!'
                OR r.affected_batch_codes ILIKE :search ESCAPE '!'
                OR r.certificate_code ILIKE :search ESCAPE '!'
                OR m.code ILIKE :search ESCAPE '!'
                OR m.model ILIKE :search ESCAPE '!'
                OR l.name ILIKE :search ESCAPE '!'
            )";
            $params[':search'] = '%' . str_replace(
                ['!', '%', '_'],
                ['!!', '!%', '!_'],
                $search
            ) . '%';
        }

        $sql = self::SELECT_RECORD;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY r.created_at DESC, r.id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * {@inheritdoc}
     */
    public function updateLossEstimate(
        int $id,
        float $lossEur,
        int $adjustedByUserId,
        string $reason
    ): SanitarySpoilageRecord {
        $stmt = $this->pdo->prepare("
            UPDATE sanitary_spoilage_records
            SET estimated_merchandise_loss_eur = :loss_eur,
                loss_adjusted_by = :adjusted_by,
                loss_adjustment_reason = :reason,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':loss_eur' => round($lossEur, 2),
            ':adjusted_by' => $adjustedByUserId,
            ':reason' => trim($reason),
            ':id' => $id,
        ]);

        $record = $this->findById($id);
        if ($record === null) {
            throw new RuntimeException(
                sprintf('No existe el acta APPCC con ID %d que se pretendía ajustar.', $id)
            );
        }

        return $record;
    }

    /**
     * {@inheritdoc}
     */
    public function completePendingReinspectionAndRestoreMachineOk(
        int $machineId,
        int $technicianId,
        float $restoredTempC,
        string $certificateCode,
        string $notes
    ): array {
        $stmtMachine = $this->pdo->prepare("
            SELECT
                m.id,
                m.code AS machine_code,
                m.location_id,
                m.machine_type,
                m.sanitary_status,
                m.sanitary_frequency_days,
                m.deleted_at,
                COALESCE(m.sanitary_frequency_days, ps.default_frequency_days, 15) AS effective_frequency_days
            FROM machines m
            LEFT JOIN preventive_settings ps
                ON ps.machine_type::text = m.machine_type::text
            WHERE m.id = :machine_id
            FOR UPDATE OF m
        ");
        $stmtMachine->execute([':machine_id' => $machineId]);
        $machine = $stmtMachine->fetch(PDO::FETCH_ASSOC);

        if ($machine === false) {
            throw new RuntimeException(
                sprintf('No existe la máquina con ID %d para levantar su cuarentena.', $machineId)
            );
        }

        if ($machine['deleted_at'] !== null) {
            throw new RuntimeException(
                sprintf('La máquina «%s» está eliminada lógicamente y no puede levantarse.', (string)$machine['machine_code'])
            );
        }

        $stmtClose = $this->pdo->prepare("
            UPDATE preventive_orders
            SET status = 'COMPLETED',
                result = 'CONFORME',
                temperature_measured = :temperature_measured,
                is_quarantine_triggered = FALSE,
                notes = CASE
                    WHEN notes IS NULL OR btrim(notes) = '' THEN :notes_empty
                    ELSE notes || E'\\n' || :notes_append
                END,
                completed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE machine_id = :machine_id
              AND order_type = 'REINSPECTION'
              AND status NOT IN ('COMPLETED', 'CANCELLED')
              AND deleted_at IS NULL
            RETURNING id, order_code
        ");
        $stmtClose->execute([
            ':temperature_measured' => round($restoredTempC, 1),
            ':notes_empty' => trim($notes),
            ':notes_append' => trim($notes),
            ':machine_id' => $machineId,
        ]);
        $closedOrders = $stmtClose->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $anchorId = null;
        $anchorCode = null;
        $orderCreated = false;

        if ($closedOrders !== []) {
            usort(
                $closedOrders,
                static fn(array $left, array $right): int => (int)$right['id'] <=> (int)$left['id']
            );
            $anchorId = (int)$closedOrders[0]['id'];
            $anchorCode = (string)$closedOrders[0]['order_code'];
        } else {
            $orderCode = $this->nextPreventiveOrderCode();
            $stmtCreateOrder = $this->pdo->prepare("
                INSERT INTO preventive_orders (
                    order_code,
                    machine_id,
                    location_id,
                    assigned_technician_id,
                    status,
                    order_type,
                    scheduled_date,
                    due_date,
                    started_at,
                    completed_at,
                    temperature_measured,
                    result,
                    is_quarantine_triggered,
                    notes
                ) VALUES (
                    :order_code,
                    :machine_id,
                    :location_id,
                    :technician_id,
                    'COMPLETED',
                    'REINSPECTION',
                    CURRENT_DATE,
                    CURRENT_DATE,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP,
                    :temperature_measured,
                    'CONFORME',
                    FALSE,
                    :notes
                )
                RETURNING id, order_code
            ");
            $stmtCreateOrder->execute([
                ':order_code' => $orderCode,
                ':machine_id' => $machineId,
                ':location_id' => (int)$machine['location_id'],
                ':technician_id' => $technicianId,
                ':temperature_measured' => round($restoredTempC, 1),
                ':notes' => trim($notes),
            ]);
            $createdOrder = $stmtCreateOrder->fetch(PDO::FETCH_ASSOC);

            if ($createdOrder === false) {
                throw new RuntimeException(
                    'No se pudo documentar la reinspección que ancla el certificado sanitario.'
                );
            }

            $anchorId = (int)$createdOrder['id'];
            $anchorCode = (string)$createdOrder['order_code'];
            $orderCreated = true;
        }

        $frequencyDays = $machine['effective_frequency_days'] !== null
            ? (int)$machine['effective_frequency_days']
            : 15;
        if ($frequencyDays <= 0) {
            $frequencyDays = 15;
        }
        $validUntil = date('Y-m-d', strtotime("+{$frequencyDays} days"));

        $stmtRestore = $this->pdo->prepare("
            UPDATE machines
            SET sanitary_status = 'OK',
                last_sanitary_inspection_at = CURRENT_TIMESTAMP,
                next_sanitary_inspection_due = :next_due,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :machine_id
        ");
        $stmtRestore->execute([
            ':next_due' => $validUntil,
            ':machine_id' => $machineId,
        ]);

        $stmtTechnician = $this->pdo->prepare("
            SELECT name, operator_code
            FROM users
            WHERE id = :technician_id
            LIMIT 1
        ");
        $stmtTechnician->execute([':technician_id' => $technicianId]);
        $technician = $stmtTechnician->fetch(PDO::FETCH_ASSOC);

        if ($technician === false) {
            throw new RuntimeException(
                sprintf('No existe el técnico con ID %d que certifica el levantamiento.', $technicianId)
            );
        }

        $operatorCode = trim((string)($technician['operator_code'] ?? ''));
        if ($operatorCode === '') {
            $operatorCode = sprintf('OP-%02d', $technicianId);
        }

        $stmtCertificate = $this->pdo->prepare("
            INSERT INTO sanitary_certificates (
                certificate_code,
                preventive_order_id,
                machine_id,
                location_id,
                technician_id,
                technician_name,
                technician_operator_code,
                inspection_date,
                valid_until,
                temperature_measured,
                result,
                status
            ) VALUES (
                :certificate_code,
                :preventive_order_id,
                :machine_id,
                :location_id,
                :technician_id,
                :technician_name,
                :technician_operator_code,
                CURRENT_TIMESTAMP,
                :valid_until,
                :temperature_measured,
                'CONFORME',
                'VALID'
            )
            RETURNING id
        ");
        $stmtCertificate->execute([
            ':certificate_code' => $certificateCode,
            ':preventive_order_id' => $anchorId,
            ':machine_id' => $machineId,
            ':location_id' => (int)$machine['location_id'],
            ':technician_id' => $technicianId,
            ':technician_name' => (string)$technician['name'],
            ':technician_operator_code' => $operatorCode,
            ':valid_until' => $validUntil,
            ':temperature_measured' => round($restoredTempC, 1),
        ]);

        return [
            'machine_id' => $machineId,
            'machine_sanitary_status' => 'OK',
            'reinspection_orders_closed' => count($closedOrders),
            'reinspection_order_id' => $anchorId,
            'reinspection_order_code' => $anchorCode,
            'reinspection_order_created' => $orderCreated,
            'certificate_id' => (int)$stmtCertificate->fetchColumn(),
            'certificate_code' => $certificateCode,
            'valid_until' => $validUntil,
            'next_sanitary_inspection_due' => $validUntil,
            'last_sanitary_inspection_at' => date('Y-m-d H:i:s'),
            'frequency_days' => $frequencyDays,
        ];
    }

    /**
     * Reserva un código `PREV-AAAA-NNNN` para la orden de reinspección que
     * documenta el levantamiento cuando no había ninguna pendiente.
     *
     * Comparte la forma de bloqueo de `nextRecordCode()`: el hueco se calcula
     * bajo `pg_advisory_xact_lock` y la clave única del motor es la última red
     * de seguridad.
     */
    private function nextPreventiveOrderCode(): string
    {
        $year = (int)date('Y');
        $prefix = sprintf('PREV-%04d-', $year);

        $stmtLock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key))');
        $stmtLock->execute([':lock_key' => 'preventive_order_code_' . $year]);

        $stmtSeq = $this->pdo->prepare("
            SELECT COALESCE(
                MAX(CAST(SUBSTRING(order_code FROM '([0-9]{4})$') AS INTEGER)),
                0
            ) + 1
            FROM preventive_orders
            WHERE order_code LIKE :prefix
        ");
        $stmtSeq->execute([':prefix' => $prefix . '%']);
        $next = (int)$stmtSeq->fetchColumn();

        return sprintf('PREV-%04d-%04d', $year, $next);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<SanitarySpoilageRecord>
     */
    private function hydrateAll(array $rows): array
    {
        $records = [];
        foreach ($rows as $row) {
            $record = $this->hydrateOrNull($row);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Normaliza un filtro de fecha de día completo al límite temporal indicado.
     *
     * El contrato del panel acepta `YYYY-MM-DD`; se rechaza cualquier otra
     * forma antes de que llegue al motor para no depender de la conversión
     * implícita de PostgreSQL.
     */
    private function normalizeDateBoundary(mixed $value, string $time): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $date = trim($value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException(sprintf(
                'La fecha «%s» no usa el formato YYYY-MM-DD.',
                $date
            ));
        }

        return $date . ' ' . $time;
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function hydrateOrNull(array|false $row): ?SanitarySpoilageRecord
    {
        if ($row === false) {
            return null;
        }

        return new SanitarySpoilageRecord(
            (int)$row['id'],
            (string)$row['record_code'],
            (int)$row['machine_id'],
            $row['incident_id'] !== null ? (int)$row['incident_id'] : null,
            $row['maintenance_order_id'] !== null ? (int)$row['maintenance_order_id'] : null,
            (int)$row['technician_id'],
            (int)$row['registered_by'],
            (string)$row['quarantine_origin'],
            (int)$row['discarded_units_count'],
            $this->asBool($row['empty_machine_confirmed']),
            (string)$row['affected_batch_codes'],
            (float)$row['cabin_temp_at_removal'],
            (float)$row['restored_stabilized_temp_c'],
            (string)$row['disposal_method'],
            (string)$row['evidence_photo_url'],
            (string)$row['sanitary_notes'],
            (float)$row['estimated_merchandise_loss_eur'],
            $row['loss_adjusted_by'] !== null ? (int)$row['loss_adjusted_by'] : null,
            $row['loss_adjustment_reason'] !== null ? (string)$row['loss_adjustment_reason'] : null,
            (string)$row['certificate_code'],
            $row['created_at'] !== null ? (string)$row['created_at'] : null,
            $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
            [
                'machine_code' => (string)$row['machine_code'],
                'machine_model' => (string)$row['machine_model'],
                'location_name' => (string)$row['location_name'],
            ],
            [
                'name' => (string)$row['technician_name'],
                'operator_code' => $row['technician_operator_code'] !== null
                    ? (string)$row['technician_operator_code']
                    : null,
            ],
            $row['registered_by_name'] !== null ? (string)$row['registered_by_name'] : null,
            $row['loss_adjusted_by_name'] !== null ? (string)$row['loss_adjusted_by_name'] : null
        );
    }

    private function asBool(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't'
            || $value === 'true';
    }
}
