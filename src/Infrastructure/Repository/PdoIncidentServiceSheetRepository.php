<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use PDOStatement;
use VendGuard\Core\Domain\Exception\ServiceSheetPersistenceException;
use VendGuard\Core\Domain\Model\IncidentServiceSheet;
use VendGuard\Core\Domain\Repository\IncidentServiceSheetRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoIncidentServiceSheetRepository
 *
 * Persistencia de las hojas de servicio del Módulo 19 sobre PostgreSQL.
 *
 * Decisiones que el código hace explícitas:
 *
 *  - **La fotografía la compone el motor, no PHP.** La inserción es una sola sentencia
 *    `INSERT ... SELECT` (contratos §6.1) que lee las filas que la transacción del cierre
 *    acaba de escribir y guarda su instantánea. Si PHP compusiera el documento, el papel
 *    firmado podría contar algo distinto de lo guardado (RD-10).
 *  - **La hoja se ancla a la resolución.** `resolution_at` copia `incidents.resolved_at` y
 *    el `SELECT` exige que exista: si el cierre no llegó a escribirse, la sentencia no
 *    escribe nada y el cierre entero revierte (S5). Sin esa marca, una reapertura dejaría
 *    dos hojas indistinguibles (Q2).
 *  - **No hay `update` ni `delete`.** El puerto no los declara y el motor los rechaza con
 *    disparadores: la inmutabilidad es de base de datos, no una convención del código.
 *  - **Una consulta para la hoja y la proyección viva.** `findDocumentByIncidentId()` sirve
 *    el albarán pedido o el vigente, y compone la proyección viva cuando todavía no hay
 *    ninguna hoja (contratos §6.2). El `JOIN` de la hoja filtra por `incident_id`, así que
 *    un `sheet_id` de otra avería no puede servirse (RD-17). Sin `sheet_id`, la hoja
 *    servida es la **anclada a la resolución vigente** (`resolution_at = resolved_at`):
 *    tras reabrir y volver a cerrar sin firma, la firma anterior no se reutiliza (S7).
 */
final class PdoIncidentServiceSheetRepository implements IncidentServiceSheetRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * Inserción con el documento congelado (contratos §6.1).
     */
    private const INSERT_SQL = <<<'SQL'
        INSERT INTO incident_service_sheets
            (incident_id, technician_id, signer_name, signer_document,
             signature_png, absence_reason, resolution_at, signed_document)
        SELECT i.id,
               :technician_id,
               :signer_name,
               :signer_document,
               :signature_png,
               :absence_reason,
               i.resolved_at,
               jsonb_build_object(
                   'version', 1,
                   'ticket_code', i.ticket_code,
                   'resolved_at', i.resolved_at,
                   'location', jsonb_build_object('name', l.name, 'address', l.address),
                   'machine',  jsonb_build_object('code', m.code, 'model', m.model),
                   'technician', jsonb_build_object('name', u.name),
                   'diagnosis', i.resolution_diagnosis,
                   'action', i.resolution_action,
                   'parts', COALESCE((
                       SELECT jsonb_agg(
                                  jsonb_build_object(
                                      'name', COALESCE(sp.name, rp.custom_part_name),
                                      'quantity', rp.quantity
                                  )
                                  ORDER BY rp.installed_at ASC, rp.id ASC
                              )
                         FROM incident_replaced_parts rp
                         LEFT JOIN spare_parts sp ON sp.id = rp.spare_part_id
                        WHERE rp.incident_id = i.id
                   ), '[]'::jsonb)
               )
          FROM incidents i
          JOIN locations l ON l.id = i.location_id
          JOIN machines  m ON m.id = i.machine_id
          JOIN users     u ON u.id = :technician_id
         WHERE i.id = :incident_id
           AND i.resolved_at IS NOT NULL
        RETURNING id, recorded_at, resolution_at, signed_document
        SQL;

    /**
     * Albarán vigente o histórico y proyección viva (contratos §6.2).
     */
    private const READ_SQL = <<<'SQL'
        SELECT i.id,
               i.ticket_code,
               i.status::text AS status,
               i.resolved_at,
               jsonb_build_object(
                   'version', 1,
                   'ticket_code', i.ticket_code,
                   'resolved_at', i.resolved_at,
                   'location', jsonb_build_object('name', l.name, 'address', l.address),
                   'machine',  jsonb_build_object('code', m.code, 'model', m.model),
                   'technician', jsonb_build_object('name', u.name),
                   'diagnosis', i.resolution_diagnosis,
                   'action', i.resolution_action,
                   'parts', COALESCE((
                       SELECT jsonb_agg(
                                  jsonb_build_object(
                                      'name', COALESCE(sp.name, rp.custom_part_name),
                                      'quantity', rp.quantity
                                  )
                                  ORDER BY rp.installed_at ASC, rp.id ASC
                              )
                         FROM incident_replaced_parts rp
                         LEFT JOIN spare_parts sp ON sp.id = rp.spare_part_id
                        WHERE rp.incident_id = i.id
                   ), '[]'::jsonb)
               ) AS live_document,
               s.id            AS sheet_id,
               s.recorded_at   AS sheet_recorded_at,
               s.resolution_at AS sheet_resolution_at,
               s.signer_name,
               s.signer_document,
               s.signature_png,
               s.absence_reason,
               s.signed_document,
               CASE
                   WHEN s.id IS NULL THEN i.status::text IN ('RESOLVED', 'CLOSED')
                   ELSE (s.resolution_at = i.resolved_at
                         AND i.status::text IN ('RESOLVED', 'CLOSED'))
               END AS is_current
          FROM incidents i
          JOIN locations l ON l.id = i.location_id
          JOIN machines  m ON m.id = i.machine_id
          LEFT JOIN users u ON u.id = i.assigned_technician_id
          LEFT JOIN LATERAL (
              SELECT sh.*
                FROM incident_service_sheets sh
               WHERE sh.incident_id = i.id
                 AND (
                       (:sheet_id::bigint IS NOT NULL AND sh.id = :sheet_id::bigint)
                    OR (:sheet_id::bigint IS NULL AND (
                           sh.resolution_at = i.resolved_at
                        OR i.status::text NOT IN ('RESOLVED', 'CLOSED')
                       ))
                 )
               ORDER BY sh.recorded_at DESC, sh.id DESC
               LIMIT 1
          ) s ON TRUE
         WHERE i.id = :incident_id
        SQL;

    /**
     * Historial de hojas de la avería (contratos §6.3).
     */
    private const HISTORY_SQL = <<<'SQL'
        SELECT sh.id,
               sh.recorded_at,
               sh.resolution_at,
               (sh.signature_png IS NOT NULL) AS signed,
               COALESCE(
                   sh.resolution_at = i.resolved_at
                   AND i.status::text IN ('RESOLVED', 'CLOSED'),
                   false
               ) AS is_current
          FROM incident_service_sheets sh
          JOIN incidents i ON i.id = sh.incident_id
         WHERE sh.incident_id = :incident_id
         ORDER BY sh.recorded_at DESC, sh.id DESC
        SQL;

    /**
     * Autorización de lectura del técnico (contratos §6.4, RD-17).
     */
    private const READ_AUTHORIZATION_SQL = <<<'SQL'
        SELECT 1
          FROM incidents i
         WHERE i.id = :incident_id
           AND (
                i.assigned_technician_id = :technician_id
                OR EXISTS (
                    SELECT 1 FROM incident_service_sheets sh
                     WHERE sh.incident_id = i.id
                       AND sh.technician_id = :technician_id
                )
           )
        SQL;

    /**
     * {@inheritdoc}
     */
    public function insert(IncidentServiceSheet $sheet): IncidentServiceSheet
    {
        $stmt = $this->pdo->prepare(self::INSERT_SQL);

        $stmt->bindValue(':incident_id', $sheet->getIncidentId(), PDO::PARAM_INT);
        $stmt->bindValue(':technician_id', $sheet->getTechnicianId(), PDO::PARAM_INT);
        $this->bindNullableText($stmt, ':signer_name', $sheet->getSignerName());
        $this->bindNullableText($stmt, ':signer_document', $sheet->getSignerDocument());
        $this->bindNullableText($stmt, ':absence_reason', $sheet->getAbsenceReason());

        if ($sheet->getSignaturePng() === null) {
            $stmt->bindValue(':signature_png', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':signature_png', $sheet->getSignaturePng(), PDO::PARAM_LOB);
        }

        try {
            $stmt->execute();
        } catch (\PDOException $exception) {
            // El motor rechaza la fila (CHECK de rama, firma de PNG, FK, conexión). El
            // cierre entero revierte y el técnico recibe un 500 reintentable en vez de
            // un error de persistencia sin código técnico (§5, R3-PERSISTENCE-EXC).
            throw new ServiceSheetPersistenceException(previous: $exception);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !isset($row['id'], $row['recorded_at'])) {
            throw new ServiceSheetPersistenceException();
        }

        return new IncidentServiceSheet(
            id: (int)$row['id'],
            incidentId: $sheet->getIncidentId(),
            technicianId: $sheet->getTechnicianId(),
            signerName: $sheet->getSignerName(),
            signerDocument: $sheet->getSignerDocument(),
            signaturePng: $sheet->getSignaturePng(),
            absenceReason: $sheet->getAbsenceReason(),
            resolutionAt: $row['resolution_at'] !== null ? (string)$row['resolution_at'] : null,
            signedDocument: $this->decodeDocument($row['signed_document'] ?? null),
            recordedAt: (string)$row['recorded_at'],
        );
    }

    /**
     * {@inheritdoc}
     */
    public function findDocumentByIncidentId(int $incidentId, ?int $sheetId = null): ?array
    {
        $stmt = $this->pdo->prepare(self::READ_SQL);
        $stmt->bindValue(':incident_id', $incidentId, PDO::PARAM_INT);
        $stmt->bindValue(':sheet_id', $sheetId, $sheetId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $isCurrent = self::asBool($row['is_current'] ?? false);

        $sheet = null;
        if ($row['sheet_id'] !== null) {
            $png = self::binaryOf($row['signature_png']);

            $sheet = [
                'id' => (int)$row['sheet_id'],
                'recorded_at' => $row['sheet_recorded_at'] !== null ? (string)$row['sheet_recorded_at'] : null,
                'resolved_at' => $row['sheet_resolution_at'] !== null ? (string)$row['sheet_resolution_at'] : null,
                'current' => $isCurrent,
                'signed' => $png !== null,
                'signer_name' => $row['signer_name'] !== null ? (string)$row['signer_name'] : null,
                'signer_document' => $row['signer_document'] !== null ? (string)$row['signer_document'] : null,
                'png_base64' => $png === null ? null : base64_encode($png),
                'absence_reason' => $row['absence_reason'] !== null ? (string)$row['absence_reason'] : null,
            ];
        }

        $document = $sheet === null
            ? $this->decodeDocument($row['live_document'] ?? null)
            : $this->decodeDocument($row['signed_document'] ?? null);

        return [
            'ticket_code' => (string)$row['ticket_code'],
            'status' => (string)$row['status'],
            'current' => $isCurrent,
            'document' => $document ?? [],
            'sheet' => $sheet,
            'history' => $this->listByIncidentId($incidentId),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function listByIncidentId(int $incidentId): array
    {
        $stmt = $this->pdo->prepare(self::HISTORY_SQL);
        $stmt->bindValue(':incident_id', $incidentId, PDO::PARAM_INT);
        $stmt->execute();

        $history = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $history[] = [
                'id' => (int)$row['id'],
                'recorded_at' => $row['recorded_at'] !== null ? (string)$row['recorded_at'] : null,
                'resolved_at' => $row['resolution_at'] !== null ? (string)$row['resolution_at'] : null,
                'signed' => self::asBool($row['signed'] ?? false),
                'current' => self::asBool($row['is_current'] ?? false),
            ];
        }

        return $history;
    }

    /**
     * {@inheritdoc}
     */
    public function isReadableByTechnician(int $incidentId, int $technicianId): bool
    {
        $stmt = $this->pdo->prepare(self::READ_AUTHORIZATION_SQL);
        $stmt->bindValue(':incident_id', $incidentId, PDO::PARAM_INT);
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    private function bindNullableText(PDOStatement $stmt, string $placeholder, ?string $value): void
    {
        $stmt->bindValue(
            $placeholder,
            $value,
            $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
    }

    /**
     * `pdo_pgsql` devuelve `bytea` como cadena o como flujo según la versión del driver.
     */
    private static function binaryOf(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? null : $contents;
        }

        return (string)$value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeDocument(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function asBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
