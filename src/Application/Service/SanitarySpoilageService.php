<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use PDO;
use RuntimeException;
use Throwable;
use VendGuard\Core\Domain\Exception\SanitarySpoilageValidationException;
use VendGuard\Core\Domain\Model\SanitarySpoilageRecord;
use VendGuard\Core\Domain\Repository\SanitarySpoilageRecordRepositoryInterface;
use VendGuard\Core\Service\SanitarySpoilageValidator;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoSanitarySpoilageRecordRepository;

/**
 * SanitarySpoilageService — orquestación del Acta de Merma APPCC (Módulo 29).
 *
 * El servicio concentra los cinco casos de uso del módulo y deja en el
 * repositorio PDO las lecturas bloqueantes y la persistencia:
 *
 *   1. **Levantamiento atómico (`RF-03.1`, `C1`, `C6`, `QA 1`).** Comprueba
 *      máquina activa en cuarentena, ausencia de averías abiertas, autoría del
 *      técnico in situ y techo térmico; después emite el acta, cierra la
 *      reinspección, restituye la máquina a `OK`, expide el certificado y deja
 *      el rastro de auditoría en la misma transacción.
 *
 *   2. **Acta Oficial A4 (`RF-05.1`, `C4`).** Devuelve la serialización oficial
 *      de la entidad, que nunca contiene importes ni motivos de ajuste, y
 *      registra `VIEW_SANITARY_SPOILAGE_ACT`.
 *
 *   3. **Historial por máquina (`QA 3`).** Publica el estado sanitario actual y
 *      las actas con la serialización que corresponde al rol: completa para
 *      Coordinación y pública para el Técnico.
 *
 *   4. **Panel y ajuste (`RF-05.2`, `C5`, `QA 4`).** Los KPIs son globales e
 *      inmunes a los filtros; el ajuste se valida contra el importe vigente y
 *      se audita con el antes y el después bajo bloqueo de fila.
 *
 *   5. **CSV seguro (`QA 4`).** Genera un libro UTF-8 BOM con dos secciones,
 *      separador `;`, cabeceras estables y neutralización de celdas textuales
 *      que comiencen por `=`, `+`, `-` o `@`.
 *
 * La auditoría se escribe directamente en `audit_log` con el tipo
 * `SANITARY_SPOILAGE_RECORD` que añadió la migración 025. No se reutiliza
 * `AuditLogger::logMachineEvent()` porque ese camino etiqueta el hecho como
 * `MACHINE` y rompería la trazabilidad propia del acta (Art. III.3).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class SanitarySpoilageService
{
    private const ALLOWED_ACTORS = ['TECHNICIAN', 'COORDINATOR'];

    private SanitarySpoilageRecordRepositoryInterface $recordRepository;

    private PDO $pdo;

    public function __construct(
        ?SanitarySpoilageRecordRepositoryInterface $recordRepository = null,
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
        $this->recordRepository = $recordRepository
            ?? new PdoSanitarySpoilageRecordRepository($this->pdo);
    }

    /**
     * Levanta la cuarentena sanitaria y emite el Acta de Merma en un solo acto.
     *
     * @param int $machineId Máquina sobre la que se actúa.
     * @param array<string, mixed> $payload Campos del formulario ya recibidos.
     * @param string $evidencePhotoUrl URL de la evidencia previamente subida.
     * @param array<string, mixed> $actor Usuario autenticado (`id`, `role`, `name`).
     * @return array<string, mixed> Acta serializada, estado de la máquina y certificado.
     *
     * @throws SanitarySpoilageValidationException
     */
    public function liftQuarantineWithSpoilageRecord(
        int $machineId,
        array $payload,
        string $evidencePhotoUrl,
        array $actor
    ): array {
        return $this->transactional(function () use ($machineId, $payload, $evidencePhotoUrl, $actor): array {
            $role = $this->requireRole($actor, self::ALLOWED_ACTORS);
            $actorId = $this->requireActorId($actor);
            $actorName = $this->actorName($actor);

            $machine = $this->recordRepository->lockAndInspectMachineQuarantineState($machineId);
            if ($machine === null) {
                $this->fail(
                    'MACHINE_NOT_FOUND',
                    sprintf('No existe la máquina con ID %d.', $machineId),
                    404,
                    'machine_id'
                );
            }

            if (($machine['is_active'] ?? false) !== true || ($machine['deleted_at'] ?? null) !== null) {
                $this->fail(
                    'MACHINE_INACTIVE',
                    sprintf('La máquina «%s» está dada de baja y no puede levantar su cuarentena.', (string)($machine['machine_code'] ?? $machineId)),
                    409,
                    'machine_id',
                    ['machine_id' => $machineId]
                );
            }

            if (strtoupper((string)($machine['sanitary_status'] ?? '')) !== 'QUARANTINE') {
                $this->fail(
                    'MACHINE_NOT_IN_QUARANTINE',
                    sprintf('La máquina «%s» no se encuentra actualmente en cuarentena sanitaria.', (string)($machine['machine_code'] ?? $machineId)),
                    409,
                    'machine_id',
                    ['sanitary_status' => $machine['sanitary_status'] ?? null]
                );
            }

            if ($this->recordRepository->hasOpenCorrectiveIncident($machineId)) {
                $this->fail(
                    'ACTIVE_INCIDENT_BLOCKS_QUARANTINE_LIFT',
                    'La máquina conserva una avería correctiva abierta; debe resolverse antes de certificar la reapertura sanitaria (C1).',
                    409,
                    'machine_id',
                    ['machine_id' => $machineId]
                );
            }

            $technicianId = $this->resolveExecutingTechnician($role, $actorId, $payload);
            $validated = SanitarySpoilageValidator::validateCreation(
                $payload,
                (string)($machine['machine_type'] ?? ''),
                $evidencePhotoUrl
            );

            $links = $this->recordRepository->resolveLatestQuarantineEpisodeLinks($machineId);
            $year = (int)date('Y');
            $recordCode = $this->recordRepository->nextRecordCode($year);
            $certificateCode = $this->generateCertificateCode($year);

            $record = $this->recordRepository->create([
                'record_code' => $recordCode,
                'machine_id' => $machineId,
                'incident_id' => $links['incident_id'] ?? null,
                'maintenance_order_id' => $links['maintenance_order_id'] ?? null,
                'technician_id' => $technicianId,
                'registered_by' => $actorId,
                'quarantine_origin' => $links['quarantine_origin'] ?? SanitarySpoilageRecord::QUARANTINE_ORIGIN_MANUAL_SANITARY_LOCK,
                'discarded_units_count' => $validated['discarded_units_count'],
                'empty_machine_confirmed' => $validated['empty_machine_confirmed'],
                'affected_batch_codes' => $validated['affected_batch_codes'],
                'cabin_temp_at_removal' => $validated['cabin_temp_at_removal'],
                'restored_stabilized_temp_c' => $validated['restored_stabilized_temp_c'],
                'disposal_method' => $validated['disposal_method'],
                'evidence_photo_url' => $evidencePhotoUrl,
                'sanitary_notes' => $validated['sanitary_notes'],
                'estimated_merchandise_loss_eur' => $validated['estimated_merchandise_loss_eur'],
                'certificate_code' => $certificateCode,
            ]);

            $lift = $this->recordRepository->completePendingReinspectionAndRestoreMachineOk(
                $machineId,
                $technicianId,
                (float)$validated['restored_stabilized_temp_c'],
                $certificateCode,
                (string)$validated['sanitary_notes']
            );

            $recordId = $record->getId();
            if ($recordId === null) {
                throw new RuntimeException('El acta APPCC se insertó sin devolver una clave primaria.');
            }

            $this->logSpoilageAudit(
                $recordId,
                'CREATE_SPOILAGE_RECORD_AND_LIFT_QUARANTINE',
                $actor,
                [
                    'sanitary_status' => (string)($machine['sanitary_status'] ?? 'QUARANTINE'),
                    'is_active' => (bool)($machine['is_active'] ?? true),
                ],
                [
                    'sanitary_status' => 'OK',
                    'record_code' => $record->getRecordCode(),
                    'certificate_code' => $certificateCode,
                    'qr_unblocked' => true,
                ],
                [
                    'machine_id' => $machineId,
                    'technician_id' => $technicianId,
                    'registered_by' => $actorId,
                    'registered_by_name' => $actorName,
                    'quarantine_origin' => $links['quarantine_origin'] ?? null,
                    'incident_id' => $links['incident_id'] ?? null,
                    'maintenance_order_id' => $links['maintenance_order_id'] ?? null,
                    'discarded_units_count' => $validated['discarded_units_count'],
                ]
            );

            return [
                'spoilage_record' => $role === 'COORDINATOR'
                    ? $record->toCoordinatorArray()
                    : $record->toPublicArray(),
                'machine_sanitary_status' => 'OK',
                'qr_unblocked' => true,
                'certificate' => [
                    'certificate_id' => $lift['certificate_id'] ?? null,
                    'certificate_code' => $certificateCode,
                    'valid_until' => $lift['valid_until'] ?? null,
                ],
            ];
        });
    }

    /**
     * Recupera el Acta Oficial A4 y deja rastro de su lectura.
     *
     * @param int $recordId Acta solicitada.
     * @param array<string, mixed> $actor Usuario autenticado.
     * @return array<string, mixed> Documento normalizado sin datos financieros.
     *
     * @throws SanitarySpoilageValidationException
     */
    public function getOfficialAct(int $recordId, array $actor): array
    {
        $this->requireRole($actor, self::ALLOWED_ACTORS);
        $this->requireActorId($actor);

        $record = $this->recordRepository->findById($recordId);
        if ($record === null) {
            $this->fail(
                'SPOILAGE_RECORD_NOT_FOUND',
                sprintf('No existe el acta de merma APPCC con ID %d.', $recordId),
                404,
                'record_id'
            );
        }

        $this->logSpoilageAudit(
            $recordId,
            'VIEW_SANITARY_SPOILAGE_ACT',
            $actor,
            null,
            [
                'record_code' => $record->getRecordCode(),
                'official_act' => true,
            ],
            [
                'machine_id' => $record->getMachineId(),
                'certificate_code' => $record->getCertificateCode(),
            ]
        );

        return $record->toOfficialActArray();
    }

    /**
     * Historial de actas de una máquina, segregado por rol.
     *
     * @param int $machineId Máquina consultada.
     * @param string $actorRole `TECHNICIAN` o `COORDINATOR`.
     * @return array<string, mixed> Estado actual y actas serializadas.
     *
     * @throws SanitarySpoilageValidationException
     */
    public function listByMachine(int $machineId, string $actorRole): array
    {
        $role = strtoupper(trim($actorRole));
        if (!in_array($role, self::ALLOWED_ACTORS, true)) {
            $this->fail('FORBIDDEN', 'El rol no está autorizado a consultar el historial de mermas.', 403, 'role');
        }

        $machine = $this->recordRepository->lockAndInspectMachineQuarantineState($machineId);
        if ($machine === null || ($machine['deleted_at'] ?? null) !== null) {
            $this->fail(
                'MACHINE_NOT_FOUND',
                sprintf('No existe la máquina con ID %d.', $machineId),
                404,
                'machine_id'
            );
        }

        $records = $this->recordRepository->findByMachineId($machineId);
        $serialized = [];
        foreach ($records as $record) {
            $serialized[] = $role === 'COORDINATOR'
                ? $record->toCoordinatorArray()
                : $record->toPublicArray();
        }

        return [
            'machine_id' => $machineId,
            'machine_code' => (string)($machine['machine_code'] ?? ''),
            'machine_type' => (string)($machine['machine_type'] ?? ''),
            'is_in_quarantine' => strtoupper((string)($machine['sanitary_status'] ?? '')) === 'QUARANTINE',
            'has_open_incidents' => $this->recordRepository->hasOpenCorrectiveIncident($machineId),
            'records' => $serialized,
        ];
    }

    /**
     * Panel de Coordinación: KPIs globales, máquinas en cuarentena y actas.
     *
     * @param array<string, mixed> $filters Filtros opcionales del listado.
     * @return array<string, mixed>
     *
     * @throws SanitarySpoilageValidationException
     */
    public function listForCoordinator(array $filters): array
    {
        $dateFrom = $this->dateFilter($filters, 'date_from');
        $dateTo = $this->dateFilter($filters, 'date_to');
        SanitarySpoilageValidator::validateDateRange($dateFrom, $dateTo);

        $records = $this->recordRepository->findFilteredForCoordinator($filters);
        $serialized = [];
        foreach ($records as $record) {
            $serialized[] = $record->toCoordinatorArray();
        }

        return [
            'kpis' => $this->recordRepository->fetchKpiSummary(),
            'active_quarantined_machines' => $this->recordRepository->fetchActiveQuarantinedMachines(),
            'records' => $serialized,
        ];
    }

    /**
     * Ajuste económico justificado de Coordinación bajo bloqueo de fila.
     *
     * @param int $recordId Acta que se ajusta.
     * @param array<string, mixed> $payload `estimated_merchandise_loss_eur` y `loss_adjustment_reason`.
     * @param array<string, mixed> $actor Coordinador autenticado.
     * @return array<string, mixed> Acta con los importes actualizados.
     *
     * @throws SanitarySpoilageValidationException
     */
    public function adjustLossEstimate(int $recordId, array $payload, array $actor): array
    {
        return $this->transactional(function () use ($recordId, $payload, $actor): array {
            $this->requireRole($actor, ['COORDINATOR']);
            $actorId = $this->requireActorId($actor);

            $record = $this->recordRepository->findByIdForUpdate($recordId);
            if ($record === null) {
                $this->fail(
                    'SPOILAGE_RECORD_NOT_FOUND',
                    sprintf('No existe el acta de merma APPCC con ID %d.', $recordId),
                    404,
                    'record_id'
                );
            }

            $rawReason = $payload['loss_adjustment_reason'] ?? null;
            $validated = SanitarySpoilageValidator::validateLossAdjustment(
                $record->getDiscardedUnitsCount(),
                $record->getEstimatedMerchandiseLossEur(),
                $payload['estimated_merchandise_loss_eur'] ?? null,
                is_string($rawReason) ? $rawReason : ''
            );

            $updated = $this->recordRepository->updateLossEstimate(
                $recordId,
                (float)$validated['estimated_merchandise_loss_eur'],
                $actorId,
                (string)$validated['loss_adjustment_reason']
            );

            $this->logSpoilageAudit(
                $recordId,
                'ADJUST_SPOILAGE_MERCHANDISE_LOSS',
                $actor,
                [
                    'estimated_merchandise_loss_eur' => $record->getEstimatedMerchandiseLossEur(),
                    'loss_adjusted_by' => $record->getLossAdjustedBy(),
                    'loss_adjustment_reason' => $record->getLossAdjustmentReason(),
                ],
                [
                    'estimated_merchandise_loss_eur' => $updated->getEstimatedMerchandiseLossEur(),
                    'loss_adjusted_by' => $updated->getLossAdjustedBy(),
                    'loss_adjustment_reason' => $updated->getLossAdjustmentReason(),
                ],
                [
                    'record_code' => $updated->getRecordCode(),
                    'machine_id' => $updated->getMachineId(),
                ]
            );

            return ['spoilage_record' => $updated->toCoordinatorArray()];
        });
    }

    /**
     * Exporta el libro de actas filtrado con BOM y neutralización de fórmulas.
     *
     * @param array<string, mixed> $filters Filtros del panel.
     * @param array<string, mixed> $actor Coordinador autenticado.
     * @return string Contenido CSV completo.
     *
     * @throws SanitarySpoilageValidationException
     */
    public function exportCsvForCoordinator(array $filters, array $actor): string
    {
        $this->requireRole($actor, ['COORDINATOR']);
        $this->requireActorId($actor);

        $dateFrom = $this->dateFilter($filters, 'date_from');
        $dateTo = $this->dateFilter($filters, 'date_to');
        SanitarySpoilageValidator::validateDateRange($dateFrom, $dateTo);

        $kpis = $this->recordRepository->fetchKpiSummary();
        $records = $this->recordRepository->findFilteredForCoordinator($filters);
        $csv = $this->buildCsv($kpis, $records);

        $firstRecordId = $records !== [] ? ($records[0]->getId() ?? 0) : 0;
        $this->logSpoilageAudit(
            $firstRecordId,
            'EXPORT_SPOILAGE_RECORDS_CSV',
            $actor,
            null,
            [
                'records_count' => count($records),
                'filters' => $this->auditableFilters($filters),
            ],
            ['format' => 'CSV_UTF8_BOM']
        );

        return $csv;
    }

    /**
     * @param array<string, mixed> $actor
     * @param list<string> $allowed
     */
    private function requireRole(array $actor, array $allowed): string
    {
        $role = strtoupper(trim((string)($actor['role'] ?? '')));
        if (!in_array($role, $allowed, true)) {
            $this->fail(
                'FORBIDDEN',
                'El rol del usuario no está autorizado para esta operación del Acta de Merma APPCC.',
                403,
                'role',
                ['role' => $role]
            );
        }

        return $role;
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function requireActorId(array $actor): int
    {
        $actorId = (int)($actor['id'] ?? 0);
        if ($actorId <= 0) {
            $this->fail('FORBIDDEN', 'La sesión no identifica al usuario que ejecuta la operación.', 403, 'actor');
        }

        return $actorId;
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function actorName(array $actor): string
    {
        $name = trim((string)($actor['name'] ?? ''));

        return $name !== '' ? $name : 'Usuario';
    }

    /**
     * Resuelve el técnico certificador in situ y aplica la segregación de autoría
     * del Coordinador (`QA 1`).
     *
     * @param array<string, mixed> $payload
     */
    private function resolveExecutingTechnician(string $role, int $actorId, array $payload): int
    {
        if ($role === 'TECHNICIAN') {
            if (!$this->recordRepository->isActiveTechnician($actorId)) {
                $this->fail(
                    'EXECUTING_TECHNICIAN_REQUIRED',
                    'El técnico de la sesión no está activo y no puede certificar el levantamiento.',
                    422,
                    'technician_id'
                );
            }

            return $actorId;
        }

        $technicianId = (int)($payload['technician_id'] ?? 0);
        if ($technicianId <= 0 || !$this->recordRepository->isActiveTechnician($technicianId)) {
            $this->fail(
                'EXECUTING_TECHNICIAN_REQUIRED',
                'Coordinación debe indicar el técnico activo que ejecutó la retirada in situ (QA 1).',
                422,
                'technician_id'
            );
        }

        return $technicianId;
    }

    private function generateCertificateCode(int $year): string
    {
        return sprintf('CERT-%04d-%04d', $year, random_int(1000, 9999));
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function dateFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function auditableFilters(array $filters): array
    {
        $allowed = ['machine_id', 'quarantine_origin', 'disposal_method', 'date_from', 'date_to', 'search'];
        $auditable = [];

        foreach ($allowed as $key) {
            $value = $filters[$key] ?? null;
            if (is_scalar($value)) {
                $auditable[$key] = $value;
            }
        }

        return $auditable;
    }

    /**
     * Inserta el rastro inmutable del módulo bajo `SANITARY_SPOILAGE_RECORD`.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed>|null $previousState
     * @param array<string, mixed> $newState
     * @param array<string, mixed>|null $metadata
     */
    private function logSpoilageAudit(
        int $entityId,
        string $action,
        array $actor,
        ?array $previousState,
        array $newState,
        ?array $metadata = null
    ): void {
        $actorId = (int)($actor['id'] ?? 0);
        $actorRole = strtoupper(trim((string)($actor['role'] ?? 'SYSTEM')));
        $actorName = $this->actorName($actor);

        $stmt = $this->pdo->prepare("
            INSERT INTO audit_log (
                entity_type,
                entity_id,
                action,
                user_id,
                user_role,
                user_name,
                previous_state,
                new_state,
                metadata
            ) VALUES (
                'SANITARY_SPOILAGE_RECORD',
                :entity_id,
                :action,
                :user_id,
                :user_role,
                :user_name,
                :previous_state,
                :new_state,
                :metadata
            )
        ");

        $previousJson = $previousState !== null
            ? $this->encodeAuditState($previousState)
            : null;
        $newJson = $this->encodeAuditState($newState);
        $metadataJson = $metadata !== null ? $this->encodeAuditState($metadata) : null;

        $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
        $stmt->bindValue(':action', strtoupper(trim($action)), PDO::PARAM_STR);
        $stmt->bindValue(
            ':user_id',
            $actorId > 0 ? $actorId : null,
            $actorId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL
        );
        $stmt->bindValue(':user_role', mb_substr($actorRole, 0, 32), PDO::PARAM_STR);
        $stmt->bindValue(':user_name', mb_substr($actorName, 0, 100), PDO::PARAM_STR);
        $stmt->bindValue(
            ':previous_state',
            $previousJson,
            $previousJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $stmt->bindValue(':new_state', $newJson, PDO::PARAM_STR);
        $stmt->bindValue(
            ':metadata',
            $metadataJson,
            $metadataJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $stmt->execute();
    }

    /**
     * @param array<string, mixed> $state
     */
    private function encodeAuditState(array $state): string
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar el estado de auditoría del Acta de Merma.');
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $kpis
     * @param list<SanitarySpoilageRecord> $records
     */
    private function buildCsv(array $kpis, array $records): string
    {
        $lines = [];
        $lines[] = 'SECCIÓN 1 · RESUMEN DE INDICADORES GLOBALES';
        $lines[] = $this->csvRow(['Indicador', 'Valor']);
        $lines[] = $this->csvRow(['Máquinas en Cuarentena Activa', (int)$kpis['active_quarantined_machines_count']]);
        $lines[] = $this->csvRow(['Total de Actas Emitidas', (int)$kpis['total_records_count']]);
        $lines[] = $this->csvRow(['Unidades Perecederas Destruidas', (int)$kpis['total_discarded_units_count']]);
        $lines[] = $this->csvRow([
            'Merma Económica Estimada (€)',
            number_format((float)$kpis['total_estimated_loss_eur'], 2, '.', ''),
        ]);
        $lines[] = '';
        $lines[] = 'SECCIÓN 2 · DETALLE DE ACTAS FILTRADAS';
        $lines[] = $this->csvRow([
            'Código de Acta',
            'Fecha de Emisión',
            'Máquina',
            'Modelo',
            'Sede',
            'Origen de Cuarentena',
            'Unidades Destruidas',
            'Máquina Vacía Confirmada',
            'Lotes Afectados',
            'Temperatura de Retirada (°C)',
            'Temperatura Estabilizada (°C)',
            'Método de Destrucción',
            'Certificado Higiénico',
            'Técnico Certificador',
            'Observaciones Sanitarias',
            'Coste Estimado (€)',
            'Ajustado Por',
            'Motivo del Ajuste',
        ]);

        foreach ($records as $record) {
            $data = $record->toCoordinatorArray();
            $lines[] = $this->csvRow([
                $data['record_code'] ?? '',
                $data['created_at'] ?? '',
                $data['machine_code'] ?? '',
                $data['machine_model'] ?? '',
                $data['location_name'] ?? '',
                $data['quarantine_origin_label'] ?? ($data['quarantine_origin'] ?? ''),
                $data['discarded_units_count'] ?? 0,
                !empty($data['empty_machine_confirmed']) ? 'Sí' : 'No',
                $data['affected_batch_codes'] ?? '',
                $data['cabin_temp_at_removal'] ?? '',
                $data['restored_stabilized_temp_c'] ?? '',
                $data['disposal_method_label'] ?? ($data['disposal_method'] ?? ''),
                $data['certificate_code'] ?? '',
                $data['technician_name'] ?? '',
                $data['sanitary_notes'] ?? '',
                number_format((float)($data['estimated_merchandise_loss_eur'] ?? 0), 2, '.', ''),
                $data['loss_adjusted_by_name'] ?? '',
                $data['loss_adjustment_reason'] ?? '',
            ]);
        }

        $csv = '';
        foreach ($lines as $line) {
            $csv .= $line . "\r\n";
        }

        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * @param list<mixed> $cells
     */
    private function csvRow(array $cells): string
    {
        return implode(';', array_map(fn(mixed $cell): string => $this->csvCell($cell), $cells));
    }

    private function csvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            $value = $value ? 'Sí' : 'No';
        }

        if (is_float($value)) {
            $text = number_format($value, 2, '.', '');
        } else {
            $text = (string)$value;
        }

        if (is_string($value) && $text !== '' && in_array($text[0], ['=', '+', '-', '@'], true)) {
            $text = "'" . $text;
        }

        if (strpbrk($text, ";\"\r\n") !== false) {
            $text = '"' . str_replace('"', '""', $text) . '"';
        }

        return $text;
    }

    /**
     * Ejecuta la operación dentro de una transacción propia o se une a la que
     * ya esté abierta por el llamador.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $operation();

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function fail(
        string $code,
        string $message,
        int $httpStatus,
        ?string $field = null,
        array $context = []
    ): never {
        throw new SanitarySpoilageValidationException($code, $message, $httpStatus, $field, $context);
    }
}
