<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use VendGuard\Core\Domain\Exception\DexAuditConflictException;
use VendGuard\Core\Domain\Model\MachineDexAudit;
use VendGuard\Core\Domain\Repository\MachineDexAuditRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoMachineDexAuditRepository
 *
 * Persistencia PDO del libro de auditorías EVA-DTS `.dex` (Módulo 39, Tablas 52,
 * 53 y 54; RF-01.1, RF-04.1, RF-07.1, RF-07.2, RNF-01, D1, C1, C3, C6, QA-1,
 * QA-2, QA-3, Art. III.1, Art. III.3 y Art. V.1).
 *
 * Decisiones que sostiene:
 *   1. **Alta atómica.** Cabecera, selecciones y eventos entran en una única
 *      transacción; si una línea falla no queda un volcado huérfano. Nunca se
 *      reescribe la captura: el motor lo impide con `45000`.
 *   2. **Payload fuera de las lecturas.** `HEADER_SELECT` no incluye
 *      `raw_payload`; solo `findRawPayload()` lo lee, para la descarga aislada.
 *   3. **Deltas y desorden (QA-2).** `findPreviousActive()` respeta
 *      `captured_at <=` y el orden `captured_at DESC, id DESC`;
 *      `hasLaterActive()` detecta el volcado posterior. Los `VOIDED` quedan
 *      fuera de ambos.
 *   4. **KPIs y filtros (RF-07.1).** Listado y KPIs comparten filtros de fecha,
 *      zona, sede, máquina, fuente y alertas, y el KPI de desviación de efectivo
 *      compara `CA3` con la recaudación más cercana del mismo día civil
 *      `Europe/Madrid` con tolerancia de ±1,00 € (QA-3).
 *   5. **Anulación terminal (Art. III.3 y V.1).** `void()` bloquea la fila,
 *      exige `ACTIVE` y sella motivo, autor y fecha; una segunda anulación
 *      devuelve `409 DEX_AUDIT_ALREADY_VOIDED`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoMachineDexAuditRepository implements MachineDexAuditRepositoryInterface
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 500;
    private const OPERATIONAL_TIMEZONE = 'Europe/Madrid';
    private const LINE_INSERT_BATCH_SIZE = 2000;

    private const HEADER_SELECT = "
        a.id,
        a.audit_code,
        a.machine_id,
        a.machine_code_snapshot,
        a.machine_type_snapshot,
        a.imported_by,
        a.importer_role_snapshot,
        a.importer_name_snapshot,
        a.import_source,
        a.status,
        a.restock_visit_id,
        a.cash_collection_id,
        a.incident_id,
        a.original_filename,
        a.file_size_bytes,
        a.sha256_hash,
        a.dxs_comm_id,
        a.dxs_version,
        a.dex_machine_identifier,
        a.identity_mismatch_warning,
        a.id4_decimal_places,
        a.ignored_unknown_lines_count,
        a.va1_total_sales_eur,
        a.va1_total_vend_units,
        a.va1_reset_sales_eur,
        a.va1_reset_vend_units,
        a.ca3_cashbox_eur,
        a.ca3_coin_tubes_eur,
        a.ca3_bills_in_eur,
        a.previous_audit_id,
        a.counter_reset_detected,
        a.out_of_order_sync,
        a.delta_source,
        a.delta_sales_eur,
        a.delta_vend_units,
        a.client_mutation_id,
        a.captured_at,
        a.void_reason,
        a.voided_by,
        a.voided_by_name_snapshot,
        a.voided_at,
        a.created_at,
        a.updated_at
    ";

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function reserveAuditCode(?DateTimeImmutable $now = null): string
    {
        $year = (int)($now ?? new DateTimeImmutable())
            ->setTimezone(new DateTimeZone(self::OPERATIONAL_TIMEZONE))
            ->format('Y');
        $prefix = sprintf('DEX-%d-', $year);

        $stmt = $this->pdo->prepare(
            'SELECT MAX(audit_code) FROM machine_dex_audits WHERE audit_code LIKE :prefix'
        );
        $stmt->bindValue(':prefix', $prefix . '%', PDO::PARAM_STR);
        $stmt->execute();
        $max = $stmt->fetchColumn();

        $next = $max === false || $max === null
            ? 1
            : ((int)substr((string)$max, strlen($prefix))) + 1;

        return sprintf('%s%04d', $prefix, $next);
    }

    /**
     * {@inheritdoc}
     */
    public function create(
        MachineDexAudit $audit,
        array $selectionLines = [],
        array $eventLines = []
    ): MachineDexAudit {
        // Participa en la unidad de trabajo del servicio si ya existe. El
        // SAVEPOINT permite reintentar el correlativo tras una violación única
        // sin abortar la transacción exterior ni liberar el FOR UPDATE de la
        // máquina.
        $ownsTransaction = !$this->pdo->inTransaction();
        $savepoint = 'dex_audit_create_' . bin2hex(random_bytes(4));
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO machine_dex_audits (
                    audit_code, machine_id, machine_code_snapshot, machine_type_snapshot,
                    imported_by, importer_role_snapshot, importer_name_snapshot,
                    import_source, status, restock_visit_id, cash_collection_id, incident_id,
                    original_filename, file_size_bytes, sha256_hash, raw_payload,
                    dxs_comm_id, dxs_version, dex_machine_identifier, identity_mismatch_warning,
                    id4_decimal_places, ignored_unknown_lines_count,
                    va1_total_sales_eur, va1_total_vend_units,
                    va1_reset_sales_eur, va1_reset_vend_units,
                    ca3_cashbox_eur, ca3_coin_tubes_eur, ca3_bills_in_eur,
                    previous_audit_id, counter_reset_detected, out_of_order_sync,
                    delta_source, delta_sales_eur, delta_vend_units,
                    client_mutation_id, captured_at
                ) VALUES (
                    :audit_code, :machine_id, :machine_code_snapshot, :machine_type_snapshot,
                    :imported_by, :importer_role_snapshot, :importer_name_snapshot,
                    :import_source, :status, :restock_visit_id, :cash_collection_id, :incident_id,
                    :original_filename, :file_size_bytes, :sha256_hash, :raw_payload,
                    :dxs_comm_id, :dxs_version, :dex_machine_identifier, :identity_mismatch_warning,
                    :id4_decimal_places, :ignored_unknown_lines_count,
                    :va1_total_sales_eur, :va1_total_vend_units,
                    :va1_reset_sales_eur, :va1_reset_vend_units,
                    :ca3_cashbox_eur, :ca3_coin_tubes_eur, :ca3_bills_in_eur,
                    :previous_audit_id, :counter_reset_detected, :out_of_order_sync,
                    :delta_source, :delta_sales_eur, :delta_vend_units,
                    :client_mutation_id, :captured_at::timestamptz
                ) RETURNING id
            ');

            $stmt->bindValue(':audit_code', $audit->getAuditCode(), PDO::PARAM_STR);
            $stmt->bindValue(':machine_id', $audit->getMachineId(), PDO::PARAM_INT);
            $stmt->bindValue(':machine_code_snapshot', $audit->getMachineCodeSnapshot(), PDO::PARAM_STR);
            $stmt->bindValue(':machine_type_snapshot', $audit->getMachineTypeSnapshot(), PDO::PARAM_STR);
            $stmt->bindValue(':imported_by', $audit->getImportedBy(), PDO::PARAM_INT);
            $stmt->bindValue(':importer_role_snapshot', $audit->getImporterRoleSnapshot(), PDO::PARAM_STR);
            $stmt->bindValue(':importer_name_snapshot', $audit->getImporterNameSnapshot(), PDO::PARAM_STR);
            $stmt->bindValue(':import_source', $audit->getImportSource()->value, PDO::PARAM_STR);
            $stmt->bindValue(':status', $audit->getStatus()->value, PDO::PARAM_STR);
            $this->bindNullableInt($stmt, ':restock_visit_id', $audit->getRestockVisitId());
            $this->bindNullableInt($stmt, ':cash_collection_id', $audit->getCashCollectionId());
            $this->bindNullableInt($stmt, ':incident_id', $audit->getIncidentId());
            $stmt->bindValue(':original_filename', $audit->getOriginalFilename(), PDO::PARAM_STR);
            $stmt->bindValue(':file_size_bytes', $audit->getFileSizeBytes(), PDO::PARAM_INT);
            $stmt->bindValue(':sha256_hash', $audit->getSha256Hash(), PDO::PARAM_STR);
            $stmt->bindValue(':raw_payload', $audit->getRawPayload(), PDO::PARAM_STR);
            $this->bindNullableText($stmt, ':dxs_comm_id', $audit->getDxsCommId());
            $this->bindNullableText($stmt, ':dxs_version', $audit->getDxsVersion());
            $this->bindNullableText($stmt, ':dex_machine_identifier', $audit->getDexMachineIdentifier());
            $stmt->bindValue(':identity_mismatch_warning', self::boolParam($audit->hasIdentityMismatchWarning()));
            $stmt->bindValue(':id4_decimal_places', $audit->getId4DecimalPlaces(), PDO::PARAM_INT);
            $stmt->bindValue(':ignored_unknown_lines_count', $audit->getIgnoredUnknownLinesCount(), PDO::PARAM_INT);
            $stmt->bindValue(':va1_total_sales_eur', (string)$audit->getVa1TotalSalesEur(), PDO::PARAM_STR);
            $stmt->bindValue(':va1_total_vend_units', $audit->getVa1TotalVendUnits(), PDO::PARAM_INT);
            $this->bindNullableFloat($stmt, ':va1_reset_sales_eur', $audit->getVa1ResetSalesEur());
            $this->bindNullableInt($stmt, ':va1_reset_vend_units', $audit->getVa1ResetVendUnits());
            $this->bindNullableFloat($stmt, ':ca3_cashbox_eur', $audit->getCa3CashboxEur());
            $this->bindNullableFloat($stmt, ':ca3_coin_tubes_eur', $audit->getCa3CoinTubesEur());
            $this->bindNullableFloat($stmt, ':ca3_bills_in_eur', $audit->getCa3BillsInEur());
            $this->bindNullableInt($stmt, ':previous_audit_id', $audit->getPreviousAuditId());
            $stmt->bindValue(':counter_reset_detected', self::boolParam($audit->isCounterResetDetected()));
            $stmt->bindValue(':out_of_order_sync', self::boolParam($audit->isOutOfOrderSync()));
            $stmt->bindValue(':delta_source', $audit->getDeltaSource(), PDO::PARAM_STR);
            $this->bindNullableFloat($stmt, ':delta_sales_eur', $audit->getDeltaSalesEur());
            $this->bindNullableInt($stmt, ':delta_vend_units', $audit->getDeltaVendUnits());
            $this->bindNullableText($stmt, ':client_mutation_id', $audit->getClientMutationId());
            $stmt->bindValue(':captured_at', $audit->getCapturedAt(), PDO::PARAM_STR);
            $stmt->execute();

            $auditId = (int)$stmt->fetchColumn();
            if ($auditId <= 0) {
                throw new \RuntimeException('El motor no devolvió el identificador del volcado insertado.');
            }

            $this->insertSelectionLines($auditId, $selectionLines);
            $this->insertEventLines($auditId, $eventLines);

            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $audit->withId($auditId);
        } catch (PDOException $exception) {
            $this->rollbackCreate($ownsTransaction, $savepoint);
            $conflict = $this->translateUniqueViolation($exception);
            if ($conflict !== null) {
                throw $conflict;
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollbackCreate($ownsTransaction, $savepoint);
            throw $exception;
        }
    }

    /**
     * Deshace solo el intento de alta, no la transacción exterior.
     */
    private function rollbackCreate(bool $ownsTransaction, string $savepoint): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }

        if ($ownsTransaction) {
            $this->pdo->rollBack();
            return;
        }

        $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
        $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $auditId): ?array
    {
        if ($auditId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::HEADER_SELECT . '
             FROM machine_dex_audits a
             INNER JOIN machines m ON m.id = a.machine_id
             INNER JOIN locations l ON l.id = m.location_id
             WHERE a.id = :audit_id'
        );
        $stmt->bindValue(':audit_id', $auditId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $audit = $this->mapHeader($row);
        $audit['selections'] = $this->findSelectionsByAudit($auditId);
        $audit['events'] = $this->findEventsByAudit($auditId);
        $audit['has_thermal_alert'] = $this->hasThermalAlert($auditId);

        return $audit;
    }

    /**
     * {@inheritdoc}
     */
    public function findRawPayload(int $auditId): ?array
    {
        if ($auditId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT raw_payload, audit_code, original_filename, captured_at
             FROM machine_dex_audits
             WHERE id = :audit_id'
        );
        $stmt->bindValue(':audit_id', $auditId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'raw_payload' => (string)$row['raw_payload'],
            'audit_code' => (string)$row['audit_code'],
            'original_filename' => (string)$row['original_filename'],
            'captured_at' => (string)$row['captured_at'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function findActiveIdByHash(int $machineId, string $sha256Hash): ?int
    {
        if ($machineId <= 0 || trim($sha256Hash) === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT id FROM machine_dex_audits
             WHERE machine_id = :machine_id
               AND sha256_hash = :sha256_hash
               AND status = 'ACTIVE'
             LIMIT 1"
        );
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':sha256_hash', strtolower(trim($sha256Hash)), PDO::PARAM_STR);
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int)$id;
    }

    /**
     * {@inheritdoc}
     */
    public function findByActorMutation(int $importedBy, string $clientMutationId): ?array
    {
        $mutation = trim($clientMutationId);
        if ($importedBy <= 0 || $mutation === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, machine_id, status FROM machine_dex_audits
             WHERE imported_by = :imported_by AND client_mutation_id = :client_mutation_id
             LIMIT 1'
        );
        $stmt->bindValue(':imported_by', $importedBy, PDO::PARAM_INT);
        $stmt->bindValue(':client_mutation_id', $mutation, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'id' => (int)$row['id'],
            'machine_id' => (int)$row['machine_id'],
            'status' => (string)$row['status'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function findPreviousActive(int $machineId, string $capturedAt, ?int $excludeAuditId = null): ?array
    {
        if ($machineId <= 0 || trim($capturedAt) === '') {
            return null;
        }

        $sql = "SELECT id, audit_code, captured_at, va1_total_sales_eur, va1_total_vend_units,
                    delta_source, status
             FROM machine_dex_audits
             WHERE machine_id = :machine_id
               AND status = 'ACTIVE'
               AND captured_at <= :captured_at::timestamptz";
        if ($excludeAuditId !== null && $excludeAuditId > 0) {
            $sql .= ' AND id <> :exclude_audit_id';
        }
        $sql .= ' ORDER BY captured_at DESC, id DESC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':captured_at', $capturedAt, PDO::PARAM_STR);
        if ($excludeAuditId !== null && $excludeAuditId > 0) {
            $stmt->bindValue(':exclude_audit_id', $excludeAuditId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'audit_code' => (string)$row['audit_code'],
            'captured_at' => (string)$row['captured_at'],
            'va1_total_sales_eur' => (float)$row['va1_total_sales_eur'],
            'va1_total_vend_units' => (int)$row['va1_total_vend_units'],
            'delta_source' => (string)$row['delta_source'],
            'status' => (string)$row['status'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function hasLaterActive(int $machineId, string $capturedAt): bool
    {
        if ($machineId <= 0 || trim($capturedAt) === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "SELECT EXISTS (
                SELECT 1 FROM machine_dex_audits
                WHERE machine_id = :machine_id
                  AND status = 'ACTIVE'
                  AND captured_at > :captured_at::timestamptz
            )"
        );
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':captured_at', $capturedAt, PDO::PARAM_STR);
        $stmt->execute();

        return self::toBool($stmt->fetchColumn());
    }

    /**
     * {@inheritdoc}
     */
    public function findSelectionsByAudit(int $auditId): array
    {
        if ($auditId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, dex_audit_id, line_sequence, raw_selection_code, normalized_selection_code,
                    matched_slot_id, matched_slot_code_snapshot, match_status,
                    unit_price_eur, total_vend_units, total_sales_eur,
                    reset_vend_units, reset_sales_eur, delta_vend_units, delta_sales_eur
             FROM machine_dex_selection_lines
             WHERE dex_audit_id = :audit_id
             ORDER BY line_sequence ASC'
        );
        $stmt->bindValue(':audit_id', $auditId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'id' => (int)$row['id'],
            'dex_audit_id' => (int)$row['dex_audit_id'],
            'line_sequence' => (int)$row['line_sequence'],
            'raw_selection_code' => (string)$row['raw_selection_code'],
            'normalized_selection_code' => (string)$row['normalized_selection_code'],
            'matched_slot_id' => $row['matched_slot_id'] === null ? null : (int)$row['matched_slot_id'],
            'matched_slot_code_snapshot' => $row['matched_slot_code_snapshot'] === null
                ? null
                : (string)$row['matched_slot_code_snapshot'],
            'match_status' => (string)$row['match_status'],
            'unit_price_eur' => self::nullableFloat($row['unit_price_eur']),
            'total_vend_units' => (int)$row['total_vend_units'],
            'total_sales_eur' => (float)$row['total_sales_eur'],
            'reset_vend_units' => $row['reset_vend_units'] === null ? null : (int)$row['reset_vend_units'],
            'reset_sales_eur' => self::nullableFloat($row['reset_sales_eur']),
            'delta_vend_units' => $row['delta_vend_units'] === null ? null : (int)$row['delta_vend_units'],
            'delta_sales_eur' => self::nullableFloat($row['delta_sales_eur']),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * {@inheritdoc}
     */
    public function listForCoordinator(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min($limit > 0 ? $limit : self::DEFAULT_LIMIT, self::MAX_LIMIT));
        $offset = max(0, $offset);
        [$where, $params] = $this->buildFilterClause($filters);

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::HEADER_SELECT . ",
                    l.name AS location_name,
                    l.site_code AS location_code,
                    (SELECT COUNT(*) FROM machine_dex_selection_lines s WHERE s.dex_audit_id = a.id) AS selections_count,
                    (SELECT COUNT(*) FROM machine_dex_event_lines e WHERE e.dex_audit_id = a.id) AS events_count,
                    EXISTS (
                        SELECT 1 FROM machine_dex_event_lines e
                        WHERE e.dex_audit_id = a.id AND e.is_thermal_alert = TRUE
                    ) AS has_thermal_alert
             FROM machine_dex_audits a
             INNER JOIN machines m ON m.id = a.machine_id
             INNER JOIN locations l ON l.id = m.location_id
             WHERE {$where}
             ORDER BY a.captured_at DESC, a.id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(function (array $row): array {
            $audit = $this->mapHeader($row);
            $audit['location_name'] = (string)$row['location_name'];
            $audit['location_code'] = (string)$row['location_code'];
            $audit['selections_count'] = (int)$row['selections_count'];
            $audit['events_count'] = (int)$row['events_count'];
            $audit['has_thermal_alert'] = self::toBool($row['has_thermal_alert']);

            return $audit;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * {@inheritdoc}
     */
    public function kpisForCoordinator(array $filters = []): array
    {
        [$where, $params] = $this->buildFilterClause($filters, false);

        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) FILTER (WHERE a.status = 'ACTIVE') AS total_active,
                COUNT(*) FILTER (
                    WHERE a.status = 'ACTIVE' AND EXISTS (
                        SELECT 1 FROM machine_dex_event_lines e
                        WHERE e.dex_audit_id = a.id AND e.is_thermal_alert = TRUE
                    )
                ) AS thermal_alert_count,
                COUNT(*) FILTER (
                    WHERE a.status = 'ACTIVE' AND (
                        a.identity_mismatch_warning = TRUE
                        OR a.counter_reset_detected = TRUE
                        OR a.out_of_order_sync = TRUE
                    )
                ) AS warning_count,
                COUNT(*) FILTER (
                    WHERE a.status = 'ACTIVE'
                      AND a.ca3_cashbox_eur IS NOT NULL
                      AND ABS(
                          COALESCE((
                              SELECT c.vault_counted_eur
                              FROM machine_cash_collections c
                              WHERE c.machine_id = a.machine_id
                                AND c.vault_counted_eur IS NOT NULL
                                AND (c.collected_at AT TIME ZONE 'Europe/Madrid')::date
                                    = (a.captured_at AT TIME ZONE 'Europe/Madrid')::date
                              ORDER BY ABS(EXTRACT(EPOCH FROM (c.collected_at - a.captured_at))) ASC, c.id ASC
                              LIMIT 1
                          ), a.ca3_cashbox_eur) - a.ca3_cashbox_eur
                      ) > 1.00
                ) AS cash_deviation_count,
                COUNT(*) FILTER (WHERE a.status = 'VOIDED') AS voided_count
             FROM machine_dex_audits a
             INNER JOIN machines m ON m.id = a.machine_id
             INNER JOIN locations l ON l.id = m.location_id
             WHERE {$where}"
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total_active' => (int)($row['total_active'] ?? 0),
            'thermal_alert_count' => (int)($row['thermal_alert_count'] ?? 0),
            'warning_count' => (int)($row['warning_count'] ?? 0),
            'cash_deviation_count' => (int)($row['cash_deviation_count'] ?? 0),
            'voided_count' => (int)($row['voided_count'] ?? 0),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function findLinkTargetForUpdate(int $auditId): ?array
    {
        if ($auditId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, audit_code, machine_id, status, restock_visit_id, cash_collection_id, incident_id
             FROM machine_dex_audits
             WHERE id = :audit_id
             FOR UPDATE'
        );
        $stmt->bindValue(':audit_id', $auditId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'id' => (int)$row['id'],
            'audit_code' => (string)$row['audit_code'],
            'machine_id' => (int)$row['machine_id'],
            'status' => (string)$row['status'],
            'restock_visit_id' => $row['restock_visit_id'] === null ? null : (int)$row['restock_visit_id'],
            'cash_collection_id' => $row['cash_collection_id'] === null ? null : (int)$row['cash_collection_id'],
            'incident_id' => $row['incident_id'] === null ? null : (int)$row['incident_id'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function linkToRestockVisit(int $auditId, int $restockVisitId): void
    {
        $this->linkColumn($auditId, 'restock_visit_id', $restockVisitId);
    }

    /**
     * {@inheritdoc}
     */
    public function linkToCashCollection(int $auditId, int $cashCollectionId): void
    {
        $this->linkColumn($auditId, 'cash_collection_id', $cashCollectionId);
    }

    /**
     * {@inheritdoc}
     */
    public function linkToIncident(int $auditId, int $incidentId): void
    {
        $this->linkColumn($auditId, 'incident_id', $incidentId);
    }

    private function linkColumn(int $auditId, string $column, int $targetId): void
    {
        $allowed = ['restock_visit_id', 'cash_collection_id', 'incident_id'];
        if ($auditId <= 0 || $targetId <= 0 || !in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException('El vínculo del volcado no es válido.');
        }

        try {
            $stmt = $this->pdo->prepare(sprintf(
                "UPDATE machine_dex_audits
                 SET %s = :target_id, updated_at = NOW()
                 WHERE id = :audit_id AND status = 'ACTIVE' AND %s IS NULL",
                $column,
                $column
            ));
            $stmt->bindValue(':target_id', $targetId, PDO::PARAM_INT);
            $stmt->bindValue(':audit_id', $auditId, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $exception) {
            if (($exception->getCode() === '23505' || (int)($exception->errorInfo[1] ?? 0) === 23505)) {
                throw new DexAuditConflictException(
                    'El volcado ya está vinculado a otro registro operativo.',
                    'DEX_AUDIT_ALREADY_LINKED',
                    ['dex_audit_id' => $auditId, 'target' => $column, 'target_id' => $targetId],
                    409,
                    $exception
                );
            }

            throw $exception;
        }

        if ($stmt->rowCount() === 0) {
            throw new DexAuditConflictException(
                'El volcado ya está vinculado a otro registro operativo.',
                'DEX_AUDIT_ALREADY_LINKED',
                ['dex_audit_id' => $auditId, 'target' => $column, 'target_id' => $targetId],
                409
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function void(
        int $auditId,
        string $reason,
        int $voidedBy,
        string $voidedByName,
        string $voidedAt
    ): array {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT status FROM machine_dex_audits WHERE id = :audit_id FOR UPDATE'
            );
            $stmt->bindValue(':audit_id', $auditId, PDO::PARAM_INT);
            $stmt->execute();
            $status = $stmt->fetchColumn();

            if ($status === false) {
                throw new DexAuditConflictException(
                    'El volcado que se pretende anular no existe.',
                    'DEX_AUDIT_NOT_FOUND',
                    ['audit_id' => $auditId]
                );
            }

            if (strtoupper((string)$status) !== 'ACTIVE') {
                throw new DexAuditConflictException(
                    'El volcado ya está anulado: la transición es terminal.',
                    'DEX_AUDIT_ALREADY_VOIDED',
                    ['audit_id' => $auditId, 'status' => (string)$status]
                );
            }

            $update = $this->pdo->prepare(
                "UPDATE machine_dex_audits
                 SET status = 'VOIDED',
                     void_reason = :void_reason,
                     voided_by = :voided_by,
                     voided_by_name_snapshot = :voided_by_name,
                     voided_at = :voided_at::timestamptz
                 WHERE id = :audit_id AND status = 'ACTIVE'"
            );
            $update->bindValue(':void_reason', trim($reason), PDO::PARAM_STR);
            $update->bindValue(':voided_by', $voidedBy, PDO::PARAM_INT);
            $update->bindValue(':voided_by_name', trim($voidedByName), PDO::PARAM_STR);
            $update->bindValue(':voided_at', $voidedAt, PDO::PARAM_STR);
            $update->bindValue(':audit_id', $auditId, PDO::PARAM_INT);
            $update->execute();

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        $detail = $this->findById($auditId);
        if ($detail === null) {
            throw new \RuntimeException('El volcado anulado no pudo releerse.');
        }

        return $detail;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private function insertSelectionLines(int $auditId, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $rows = [];
        foreach ($lines as $line) {
            $status = $line['match_status'] ?? null;
            $statusValue = is_object($status) && property_exists($status, 'value')
                ? (string)$status->value
                : strtoupper((string)$status);
            $snapshot = $line['matched_slot_code_snapshot'] ?? null;
            $snapshot = $snapshot === null || trim((string)$snapshot) === '' ? null : (string)$snapshot;
            $unitPrice = $line['unit_price_eur'] ?? null;
            $resetSales = $line['reset_sales_eur'] ?? null;
            $deltaSales = $line['delta_sales_eur'] ?? null;

            $rows[] = implode("\t", [
                (string)$auditId,
                (string)(int)($line['line_sequence'] ?? 1),
                self::copyText((string)($line['raw_selection_code'] ?? '')),
                self::copyText((string)($line['normalized_selection_code'] ?? '')),
                self::copyText(isset($line['matched_slot_id']) ? (int)$line['matched_slot_id'] : null),
                self::copyText($snapshot),
                self::copyText($statusValue),
                self::copyText($unitPrice === null ? null : (string)(float)$unitPrice),
                (string)(int)($line['total_vend_units'] ?? 0),
                (string)(float)($line['total_sales_eur'] ?? 0.0),
                self::copyText(isset($line['reset_vend_units']) ? (int)$line['reset_vend_units'] : null),
                self::copyText($resetSales === null ? null : (string)(float)$resetSales),
                self::copyText(isset($line['delta_vend_units']) ? (int)$line['delta_vend_units'] : null),
                self::copyText($deltaSales === null ? null : (string)(float)$deltaSales),
            ]);
        }

        $columns = 'dex_audit_id, line_sequence, raw_selection_code, normalized_selection_code, '
            . 'matched_slot_id, matched_slot_code_snapshot, match_status, unit_price_eur, '
            . 'total_vend_units, total_sales_eur, reset_vend_units, reset_sales_eur, '
            . 'delta_vend_units, delta_sales_eur';

        foreach (array_chunk($rows, self::LINE_INSERT_BATCH_SIZE) as $chunk) {
            $this->copyRows('machine_dex_selection_lines', $columns, $chunk);
        }

    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private function insertEventLines(int $auditId, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $rows = [];
        foreach ($lines as $line) {
            $category = $line['event_category'] ?? null;
            $severity = $line['severity'] ?? null;
            $categoryValue = is_object($category) && property_exists($category, 'value')
                ? (string)$category->value
                : strtoupper((string)$category);
            $severityValue = is_object($severity) && property_exists($severity, 'value')
                ? (string)$severity->value
                : strtoupper((string)$severity);
            $occurredAtRaw = $line['event_occurred_at_raw'] ?? null;
            $occurredAtRaw = $occurredAtRaw === null || trim((string)$occurredAtRaw) === '' ? null : (string)$occurredAtRaw;

            $rows[] = implode("\t", [
                (string)$auditId,
                (string)(int)($line['line_sequence'] ?? 1),
                self::copyText((string)($line['source_block'] ?? 'EA1')),
                self::copyText((string)($line['event_code'] ?? '')),
                self::copyText($categoryValue),
                self::copyText($severityValue),
                (string)max(1, (int)($line['occurrence_count'] ?? 1)),
                self::copyText(isset($line['count_since_reset']) ? (int)$line['count_since_reset'] : null),
                self::copyText(isset($line['count_since_init']) ? (int)$line['count_since_init'] : null),
                (bool)($line['is_active_flag'] ?? false) ? 't' : 'f',
                self::copyText($occurredAtRaw),
                self::copyText((string)($line['description_es'] ?? '')),
                (bool)($line['is_thermal_alert'] ?? false) ? 't' : 'f',
            ]);
        }

        $columns = 'dex_audit_id, line_sequence, source_block, event_code, event_category, '
            . 'severity, occurrence_count, count_since_reset, count_since_init, is_active_flag, '
            . 'event_occurred_at_raw, description_es, is_thermal_alert';

        foreach (array_chunk($rows, self::LINE_INSERT_BATCH_SIZE) as $chunk) {
            $this->copyRows('machine_dex_event_lines', $columns, $chunk);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findEventsByAudit(int $auditId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, line_sequence, source_block, event_code, event_category, severity,
                    occurrence_count, count_since_reset, count_since_init, is_active_flag,
                    event_occurred_at_raw, description_es, is_thermal_alert
             FROM machine_dex_event_lines
             WHERE dex_audit_id = :audit_id
             ORDER BY line_sequence ASC'
        );
        $stmt->bindValue(':audit_id', $auditId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'id' => (int)$row['id'],
            'line_sequence' => (int)$row['line_sequence'],
            'source_block' => (string)$row['source_block'],
            'event_code' => (string)$row['event_code'],
            'event_category' => (string)$row['event_category'],
            'severity' => (string)$row['severity'],
            'occurrence_count' => (int)$row['occurrence_count'],
            'count_since_reset' => $row['count_since_reset'] === null ? null : (int)$row['count_since_reset'],
            'count_since_init' => $row['count_since_init'] === null ? null : (int)$row['count_since_init'],
            'is_active_flag' => self::toBool($row['is_active_flag']),
            'event_occurred_at_raw' => $row['event_occurred_at_raw'] === null
                ? null
                : (string)$row['event_occurred_at_raw'],
            'description_es' => (string)$row['description_es'],
            'is_thermal_alert' => self::toBool($row['is_thermal_alert']),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function hasThermalAlert(int $auditId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM machine_dex_event_lines
                WHERE dex_audit_id = :audit_id AND is_thermal_alert = TRUE
            )'
        );
        $stmt->bindValue(':audit_id', $auditId, PDO::PARAM_INT);
        $stmt->execute();

        return self::toBool($stmt->fetchColumn());
    }

    /**
     * @return array<string, mixed>
     */
    private function mapHeader(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'audit_code' => (string)$row['audit_code'],
            'machine_id' => (int)$row['machine_id'],
            'machine_code_snapshot' => (string)$row['machine_code_snapshot'],
            'machine_type_snapshot' => (string)$row['machine_type_snapshot'],
            'imported_by' => (int)$row['imported_by'],
            'importer_role_snapshot' => (string)$row['importer_role_snapshot'],
            'importer_name_snapshot' => (string)$row['importer_name_snapshot'],
            'import_source' => (string)$row['import_source'],
            'status' => (string)$row['status'],
            'restock_visit_id' => $row['restock_visit_id'] === null ? null : (int)$row['restock_visit_id'],
            'cash_collection_id' => $row['cash_collection_id'] === null ? null : (int)$row['cash_collection_id'],
            'incident_id' => $row['incident_id'] === null ? null : (int)$row['incident_id'],
            'original_filename' => (string)$row['original_filename'],
            'file_size_bytes' => (int)$row['file_size_bytes'],
            'sha256_hash' => (string)$row['sha256_hash'],
            'dxs_comm_id' => $row['dxs_comm_id'] === null ? null : (string)$row['dxs_comm_id'],
            'dxs_version' => $row['dxs_version'] === null ? null : (string)$row['dxs_version'],
            'dex_machine_identifier' => $row['dex_machine_identifier'] === null
                ? null
                : (string)$row['dex_machine_identifier'],
            'identity_mismatch_warning' => self::toBool($row['identity_mismatch_warning']),
            'id4_decimal_places' => (int)$row['id4_decimal_places'],
            'ignored_unknown_lines_count' => (int)$row['ignored_unknown_lines_count'],
            'va1_total_sales_eur' => (float)$row['va1_total_sales_eur'],
            'va1_total_vend_units' => (int)$row['va1_total_vend_units'],
            'va1_reset_sales_eur' => self::nullableFloat($row['va1_reset_sales_eur']),
            'va1_reset_vend_units' => $row['va1_reset_vend_units'] === null ? null : (int)$row['va1_reset_vend_units'],
            'ca3_cashbox_eur' => self::nullableFloat($row['ca3_cashbox_eur']),
            'ca3_coin_tubes_eur' => self::nullableFloat($row['ca3_coin_tubes_eur']),
            'ca3_bills_in_eur' => self::nullableFloat($row['ca3_bills_in_eur']),
            'previous_audit_id' => $row['previous_audit_id'] === null ? null : (int)$row['previous_audit_id'],
            'counter_reset_detected' => self::toBool($row['counter_reset_detected']),
            'out_of_order_sync' => self::toBool($row['out_of_order_sync']),
            'delta_source' => (string)$row['delta_source'],
            'delta_sales_eur' => self::nullableFloat($row['delta_sales_eur']),
            'delta_vend_units' => $row['delta_vend_units'] === null ? null : (int)$row['delta_vend_units'],
            'client_mutation_id' => $row['client_mutation_id'] === null ? null : (string)$row['client_mutation_id'],
            'captured_at' => (string)$row['captured_at'],
            'void_reason' => $row['void_reason'] === null ? null : (string)$row['void_reason'],
            'voided_by' => $row['voided_by'] === null ? null : (int)$row['voided_by'],
            'voided_by_name_snapshot' => $row['voided_by_name_snapshot'] === null
                ? null
                : (string)$row['voided_by_name_snapshot'],
            'voided_at' => $row['voided_at'] === null ? null : (string)$row['voided_at'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilterClause(array $filters, bool $includeStatus = true): array
    {
        $where = ['1 = 1'];
        $params = [];

        if ($includeStatus) {
            $status = strtoupper(trim((string)($filters['status'] ?? 'ACTIVE')));
            if ($status !== '' && $status !== 'ALL') {
                $where[] = 'a.status = :status';
                $params[':status'] = $status;
            }
        }

        foreach ([
            'machine_id' => 'a.machine_id = :machine_id',
            'location_id' => 'm.location_id = :location_id',
            'zone_id' => 'l.zone_id = :zone_id',
            'import_source' => 'a.import_source = :import_source',
        ] as $key => $clause) {
            if (isset($filters[$key]) && (string)$filters[$key] !== '') {
                $where[] = $clause;
                $params[':' . $key] = $filters[$key];
            }
        }

        if (isset($filters['date_from']) && trim((string)$filters['date_from']) !== '') {
            $where[] = "(a.captured_at AT TIME ZONE 'Europe/Madrid')::date >= :date_from::date";
            $params[':date_from'] = (string)$filters['date_from'];
        }

        if (isset($filters['date_to']) && trim((string)$filters['date_to']) !== '') {
            $where[] = "(a.captured_at AT TIME ZONE 'Europe/Madrid')::date <= :date_to::date";
            $params[':date_to'] = (string)$filters['date_to'];
        }

        if (($filters['alerts_only'] ?? false) === true) {
            $where[] = "(
                a.identity_mismatch_warning = TRUE
                OR a.counter_reset_detected = TRUE
                OR a.out_of_order_sync = TRUE
                OR EXISTS (
                    SELECT 1 FROM machine_dex_event_lines e
                    WHERE e.dex_audit_id = a.id AND e.is_thermal_alert = TRUE
                )
            )";
        }

        return [implode(' AND ', $where), $params];
    }

    private function translateUniqueViolation(PDOException $exception): ?DexAuditConflictException
    {
        if ((string)$exception->getCode() !== '23505') {
            return null;
        }

        $message = $exception->getMessage();
        if (str_contains($message, 'uq_machine_dex_audits_machine_hash')) {
            return new DexAuditConflictException(
                'Ese mismo fichero ya está importado como volcado vigente en esta máquina.',
                'DEX_DUPLICATE_HASH'
            );
        }
        if (str_contains($message, 'uq_machine_dex_audits_actor_mutation')) {
            return new DexAuditConflictException(
                'El identificador de mutación ya se usó para otra importación.',
                'CLIENT_MUTATION_ID_ALREADY_USED'
            );
        }
        if (str_contains($message, 'uq_machine_dex_audits_code')) {
            return new DexAuditConflictException(
                'El código de volcado ya existe.',
                'DEX_DUPLICATE_AUDIT_CODE'
            );
        }
        if (str_contains($message, 'uq_machine_dex_selection_lines_code')) {
            return new DexAuditConflictException(
                'El código de selección ya existe dentro del volcado.',
                'DEX_DUPLICATE_SELECTION_CODE'
            );
        }

        return null;
    }

    private function bindNullableText(PDOStatement $stmt, int|string $param, mixed $value): void
    {
        if ($value === null || trim((string)$value) === '') {
            $stmt->bindValue($param, null, PDO::PARAM_NULL);
            return;
        }
        $stmt->bindValue($param, (string)$value, PDO::PARAM_STR);
    }

    private function bindNullableInt(PDOStatement $stmt, int|string $param, mixed $value): void
    {
        if ($value === null) {
            $stmt->bindValue($param, null, PDO::PARAM_NULL);
            return;
        }
        $stmt->bindValue($param, (int)$value, PDO::PARAM_INT);
    }

    private function bindNullableFloat(PDOStatement $stmt, int|string $param, mixed $value): void
    {
        if ($value === null) {
            $stmt->bindValue($param, null, PDO::PARAM_NULL);
            return;
        }
        $stmt->bindValue($param, (string)(float)$value, PDO::PARAM_STR);
    }

    private static function boolParam(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Inserta líneas con `COPY FROM STDIN`, el camino masivo nativo de PostgreSQL.
     *
     * `COPY` participa en la transacción abierta por `create()`: si una línea
     * incumple una restricción, el alta completa se revierte igual que con los
     * `INSERT` fila a fila. El coste fijo evita que 2.000 selecciones conviertan
     * la importación en miles de viajes al motor (RNF-01).
     *
     * @param list<string> $rows Filas ya serializadas en formato de texto de COPY.
     */
    private function copyRows(string $table, string $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        if (!method_exists($this->pdo, 'pgsqlCopyFromArray')) {
            throw new \RuntimeException('El motor no expone COPY FROM STDIN para persistir las líneas DEX.');
        }

        if (!$this->pdo->pgsqlCopyFromArray($table, $rows, "\t", "\\\\N", $columns)) {
            $error = $this->pdo->errorInfo();
            throw new PDOException((string)($error[2] ?? 'COPY FROM STDIN falló sin detalle del motor.'));
        }
    }

    private static function copyText(mixed $value): string
    {
        if ($value === null) {
            return "\\N";
        }
        if (is_bool($value)) {
            return $value ? 't' : 'f';
        }

        $text = (string)$value;
        if (strpbrk($text, "\\\t\n\r") === false) {
            return $text;
        }

        return str_replace(
            ["\\", "\t", "\n", "\r"],
            ["\\\\", "\\t", "\\n", "\\r"],
            $text
        );
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 't', 'true', 'y', 'yes'], true);
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float)$value;
    }
}
