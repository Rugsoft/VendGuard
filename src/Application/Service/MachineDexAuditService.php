<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use VendGuard\Core\Domain\Enum\DexAuditStatus;
use VendGuard\Core\Domain\Enum\DexImportSource;
use VendGuard\Core\Domain\Exception\DexAuditConflictException;
use VendGuard\Core\Domain\Exception\DexAuditForbiddenException;
use VendGuard\Core\Domain\Exception\DexAuditValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\MachineDexAudit;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\Repository\MachineDexAuditGatewayInterface;
use VendGuard\Core\Domain\Repository\MachineDexAuditRepositoryInterface;
use VendGuard\Core\Domain\Repository\NotificationOutboxRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Service\DexAuditValidator;
use VendGuard\Core\Service\DexSelectionMatcher;
use VendGuard\Core\Service\EvaDtsParser;

/**
 * MachineDexAuditService
 *
 * Orquestación del Módulo 39 (RF-02–RF-07, RNF-01–RNF-04, D1–D6, C1–C6,
 * QA-1–QA-5, Art. II, Art. III.3, Art. V.1 y Art. V.4).
 *
 * **Importación en tres puertas.** El origen declarado manda: `RESTOCKER_ROUTE`
 * solo lo usa un Reponedor, `TECHNICIAN_ROUTE` un Técnico y
 * `COORDINATOR_CONSOLE` Coordinación. La máquina se bloquea con
 * `lockMachineContext()`, se aplican perímetro y primacía sanitaria y solo
 * después se parsea, se emparejan las selecciones y se calculan deltas.
 *
 * **Deltas y desorden (QA-2, C1).** El predecesor es el último `ACTIVE` con
 * `captured_at <=`; `out_of_order_sync` se marca si ya existe uno posterior. Un
 * retroceso usa los parciales `VA103`/`VA104`/`PA203` o queda `UNAVAILABLE`.
 *
 * **Offline idempotente (C5).** La misma `(imported_by, client_mutation_id)` en
 * la misma máquina devuelve el volcado existente con `idempotent_replay = true`;
 * en otra máquina es `409 CLIENT_MUTATION_ID_ALREADY_USED`.
 *
 * **Alerta térmica anti-spam (QA-4, Art. II).** Solo en `PERISHABLE_FOOD` o
 * `COMBO`, y solo si el evento térmico es nuevo o su `count_since_init`
 * incrementa respecto al predecesor, se encola `DEX_THERMAL_EVENT_ALERT` con
 * `dedup_key = dex_thermal:{machineId}:{auditId}`. Nunca crea tickets ni cambia
 * `sanitary_status`.
 *
 * **Sugerencia en un toque (D4).** Los eventos `CRITICAL`/`WARNING` generan
 * `suggested_escalation_payload` con la categoría de avería, la nota interna con
 * los códigos y la acción de UI según el rol.
 *
 * **Anulación y CSV (C6, Art. V.1).** `voidAudit()` valida el motivo y delega la
 * transición terminal; `exportCsv()` emite BOM UTF-8, separador `;`, fechas en
 * `Europe/Madrid` y celdas neutralizadas contra fórmulas.
 *
 * **Fecha de captura (PR25-04).** `captured_at` se acepta como ISO 8601 con
 * offset/Z o como fecha local sin offset, que se interpreta en `Europe/Madrid`.
 * Se normaliza a UTC y se rechaza si supera el reloj del servidor en más de
 * `CAPTURED_AT_MAX_FUTURE_SECONDS`.
 *
 * **Vínculos operativos (PR25-05).** `restock_visit_id`,
 * `cash_collection_id` e `incident_id` se resuelven dentro de la transacción
 * con los cerrojos del gateway: deben existir, pertenecer a la máquina y, en la
 * puerta del Reponedor, ser de su propia visita o recaudación. Se rechazan con
 * `422 DEX_LINK_TARGET_NOT_FOUND`/`DEX_LINK_ID_INVALID` y `409
 * DEX_LINK_TARGET_NOT_ELIGIBLE`; un segundo volcado vigente sobre la misma
 * visita o recaudación responde `409 DEX_AUDIT_ALREADY_LINKED`.
 *
 * El servicio no escribe SQL ni conoce PDO: compone los puertos de dominio.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class MachineDexAuditService
{
    public const ACTION_ESCALATE_TO_SAT = 'ESCALATE_TO_SAT';
    public const ACTION_OPEN_OPPORTUNISTIC_INCIDENT = 'OPEN_OPPORTUNISTIC_INCIDENT';
    public const ACTION_VIEW_IN_COORDINATION = 'VIEW_IN_COORDINATION';
    public const THERMAL_DEDUP_PREFIX = 'dex_thermal:';
    public const COLD_CHAIN_MACHINE_TYPES = ['PERISHABLE_FOOD', 'COMBO'];
    public const MAX_CSV_ROWS = 500;

    /** Tolerancia de reloj para una captura fechada por el cliente (PR25-04). */
    public const CAPTURED_AT_MAX_FUTURE_SECONDS = 300;

    private const OPERATIONAL_TIMEZONE = 'Europe/Madrid';

    /** Formatos aceptados para `captured_at`; sin offset se interpreta en Madrid. */
    private const CAPTURED_AT_FORMATS = [
        'Y-m-d\TH:i:s.uP',
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s.uO',
        'Y-m-d\TH:i:sO',
        'Y-m-d H:i:s.uP',
        'Y-m-d H:i:sP',
        'Y-m-d H:i:s.uO',
        'Y-m-d H:i:sO',
        'Y-m-d\TH:i:s.u',
        'Y-m-d\TH:i:s',
        'Y-m-d H:i:s',
        'Y-m-d\TH:i',
        'Y-m-d H:i',
    ];

    /** Puerta de entrada → rol autorizado (RF-03.1). */
    private const SOURCE_ROLE_MAP = [
        'RESTOCKER_ROUTE' => 'RESTOCKER',
        'TECHNICIAN_ROUTE' => 'TECHNICIAN',
        'COORDINATOR_CONSOLE' => 'COORDINATOR',
    ];

    /** Familia de evento → categoría de avería sugerida (D4). */
    private const CATEGORY_TO_INCIDENT = [
        'THERMAL_REFRIGERATION' => 'TEMPERATURE_COLD',
        'PAYMENT_MDB' => 'PAYMENT_SYSTEM',
        'MECHANICAL_VEND' => 'PRODUCT_JAM',
        'POWER_CONTROLLER' => 'ELECTRICAL_OFF',
        'OTHER' => 'OTHER',
    ];

    public function __construct(
        private readonly MachineDexAuditRepositoryInterface $repository,
        private readonly MachineDexAuditGatewayInterface $gateway,
        private readonly EvaDtsParser $parser,
        private readonly DexSelectionMatcher $matcher,
        private readonly DexAuditValidator $validator,
        private readonly NotificationOutboxRepositoryInterface $outbox,
        private readonly TransactionManagerInterface $transactions,
        private readonly ?AuditLogger $auditLogger = null
    ) {
    }

    /**
     * Importa un `.dex` por cualquiera de las tres puertas.
     *
     * Claves obligatorias: `machine_id`, `actor_id`, `import_source`,
     * `raw_payload`, `original_filename`, `captured_at`. Opcionales:
     * `client_mutation_id`, `restock_visit_id`, `cash_collection_id`,
     * `incident_id`. El `expected_machine_code` del cliente se ignora: la
     * identidad `ID1` se contrasta siempre contra `machines.code`.
     *
     * @param array<string, mixed> $request
     * @return array{
     *     audit: array<string, mixed>,
     *     idempotent_replay: bool,
     *     has_thermal_alert: bool,
     *     thermal_alert_enqueued: bool,
     *     suggested_escalation_payload: array<string, mixed>|null,
     *     prefill_sales_counter_reading_eur: float|null
     * }
     */
    public function importDex(array $request): array
    {
        $capturedAt = trim((string)($request['captured_at'] ?? ''));
        if ($capturedAt !== '') {
            $request['captured_at'] = $this->normalizeCapturedAt($capturedAt);
        }

        return $this->transactions->runInTransaction(
            fn (): array => $this->importDexAtomically($request)
        );
    }

    /**
     * Cuerpo del alta dentro de una única unidad de trabajo.
     *
     * El bloqueo de máquina, las lecturas de predecesor, la reserva del código,
     * las líneas, el rastro de auditoría y el outbox participan en la misma
     * transacción. Así el `FOR UPDATE` sigue vigente hasta el commit y dos altas
     * válidas distintas no pueden calcular deltas desde el mismo predecesor.
     *
     * @param array<string, mixed> $request
     * @return array{
     *     audit: array<string, mixed>,
     *     idempotent_replay: bool,
     *     has_thermal_alert: bool,
     *     thermal_alert_enqueued: bool,
     *     suggested_escalation_payload: array<string, mixed>|null,
     *     prefill_sales_counter_reading_eur: float|null
     * }
     */
    private function importDexAtomically(array $request): array
    {
        $machineId = (int)($request['machine_id'] ?? 0);
        $actorId = (int)($request['actor_id'] ?? 0);
        $rawPayload = (string)($request['raw_payload'] ?? '');
        $originalFilename = trim((string)($request['original_filename'] ?? ''));
        $capturedAt = trim((string)($request['captured_at'] ?? ''));
        $source = DexImportSource::tryFrom(strtoupper(trim((string)($request['import_source'] ?? ''))));

        if ($machineId <= 0 || $actorId <= 0 || $rawPayload === ''
            || $originalFilename === '' || $capturedAt === '' || $source === null) {
            throw new DexAuditValidationException(
                'La solicitud de importación .dex no trae máquina, actor, origen, fichero ni instante de captura válidos.',
                'DEX_INVALID_IMPORT_REQUEST'
            );
        }

        $context = $this->gateway->lockMachineContext($machineId, $actorId);
        if ($context === null) {
            throw new DexAuditValidationException(
                'La máquina destino no existe, no está activa o el actor no existe.',
                'MACHINE_NOT_FOUND',
                ['machine_id' => $machineId, 'actor_id' => $actorId]
            );
        }

        $role = strtoupper((string)($context['actor_role'] ?? ''));
        if (($context['actor_is_active'] ?? false) !== true) {
            throw new DexAuditForbiddenException(
                'El usuario que intenta importar el .dex no está activo.',
                'DEX_ACTOR_INACTIVE',
                ['actor_id' => $actorId]
            );
        }

        $expectedRole = self::SOURCE_ROLE_MAP[$source->value] ?? null;
        if ($expectedRole === null || $expectedRole !== $role) {
            throw new DexAuditForbiddenException(
                sprintf('El origen %s no corresponde al rol %s.', $source->value, $role !== '' ? $role : 'desconocido'),
                'DEX_IMPORT_SOURCE_ROLE_MISMATCH',
                ['import_source' => $source->value, 'role' => $role]
            );
        }

        $coversTerritory = match ($role) {
            'RESTOCKER' => (bool)($context['is_covered_restocker'] ?? false),
            'TECHNICIAN' => (bool)($context['is_covered_technician'] ?? false),
            default => true,
        };
        $this->validator->assertImportAuthorized(
            $role,
            $coversTerritory,
            (string)($context['sanitary_status'] ?? 'OK'),
            (bool)($context['has_open_thermal_breakdown'] ?? false)
        );

        $links = $this->resolveLinkTargets($request, $machineId, $role, $actorId);

        $parsed = $this->parser->parse($rawPayload, (string)($context['machine_code'] ?? ''));

        $mutationId = isset($request['client_mutation_id']) && trim((string)$request['client_mutation_id']) !== ''
            ? trim((string)$request['client_mutation_id'])
            : null;

        if ($mutationId !== null) {
            $existing = $this->repository->findByActorMutation($actorId, $mutationId);
            if ($existing !== null) {
                if ($existing['machine_id'] !== $machineId) {
                    throw new DexAuditConflictException(
                        'El identificador de mutación ya se usó en otra máquina.',
                        'CLIENT_MUTATION_ID_ALREADY_USED',
                        ['client_mutation_id' => $mutationId, 'machine_id' => $machineId]
                    );
                }

                $detail = $this->repository->findById($existing['id']);
                if ($detail === null) {
                    throw new DexAuditConflictException(
                        'El volcado del replay no pudo releerse.',
                        'DEX_AUDIT_NOT_FOUND',
                        ['audit_id' => $existing['id']]
                    );
                }

                if (!hash_equals((string)($detail['sha256_hash'] ?? ''), (string)$parsed['sha256_hash'])) {
                    throw new DexAuditConflictException(
                        'El identificador de mutación ya se usó con otro contenido en esta máquina.',
                        'CLIENT_MUTATION_ID_ALREADY_USED',
                        [
                            'client_mutation_id' => $mutationId,
                            'machine_id' => $machineId,
                            'audit_id' => (int)$existing['id'],
                        ]
                    );
                }

                return [
                    'audit' => $detail,
                    'idempotent_replay' => true,
                    'has_thermal_alert' => (bool)($detail['has_thermal_alert'] ?? false),
                    'thermal_alert_enqueued' => false,
                    'suggested_escalation_payload' => null,
                    'prefill_sales_counter_reading_eur' => $role === 'RESTOCKER'
                        ? (float)($detail['va1_total_sales_eur'] ?? 0.0)
                        : null,
                ];
            }
        }

        $existingId = $this->repository->findActiveIdByHash($machineId, $parsed['sha256_hash']);
        if ($existingId !== null) {
            throw new DexAuditConflictException(
                'Ese mismo fichero ya está importado como volcado vigente en esta máquina.',
                'DEX_DUPLICATE_HASH',
                ['audit_id' => $existingId]
            );
        }

        $previous = $this->repository->findPreviousActive($machineId, $capturedAt);
        $previousDetail = $previous !== null ? $this->repository->findById((int)$previous['id']) : null;
        $hasLater = $this->repository->hasLaterActive($machineId, $capturedAt);

        $deltas = $this->validator->resolveDeltas(
            (float)$parsed['va1_total_sales_eur'],
            (int)$parsed['va1_total_vend_units'],
            $parsed['va1_reset_sales_eur'],
            $parsed['va1_reset_vend_units'],
            $previous === null ? null : [
                'id' => (int)$previous['id'],
                'va1_total_sales_eur' => (float)$previous['va1_total_sales_eur'],
                'va1_total_vend_units' => (int)$previous['va1_total_vend_units'],
            ],
            $hasLater
        );

        $matchedSelections = $this->matcher->match(
            (string)($context['machine_type'] ?? ''),
            $this->gateway->findActivePlanogramSlots($machineId),
            $parsed['selections']
        );
        $matchedSelections = $this->applySelectionDeltas(
            $matchedSelections,
            $previousDetail['selections'] ?? [],
            $deltas['counter_reset_detected']
        );

        $auditCode = $this->repository->reserveAuditCode(new DateTimeImmutable($capturedAt));
        $audit = new MachineDexAudit(
            id: null,
            auditCode: $auditCode,
            machineId: $machineId,
            machineCodeSnapshot: (string)$context['machine_code'],
            machineTypeSnapshot: (string)$context['machine_type'],
            importedBy: $actorId,
            importerRoleSnapshot: $role,
            importerNameSnapshot: (string)($context['actor_name'] ?? 'Usuario'),
            importSource: $source,
            originalFilename: $originalFilename,
            fileSizeBytes: (int)$parsed['byte_size'],
            sha256Hash: (string)$parsed['sha256_hash'],
            rawPayload: (string)$parsed['raw_payload_normalized'],
            va1TotalSalesEur: (float)$parsed['va1_total_sales_eur'],
            va1TotalVendUnits: (int)$parsed['va1_total_vend_units'],
            deltaSource: (string)$deltas['delta_source'],
            capturedAt: $capturedAt,
            dxsCommId: $parsed['dxs_comm_id'],
            dxsVersion: $parsed['dxs_version'],
            dexMachineIdentifier: $parsed['dex_machine_identifier'],
            identityMismatchWarning: (bool)$parsed['identity_mismatch_warning'],
            id4DecimalPlaces: (int)$parsed['id4_decimal_places'],
            ignoredUnknownLinesCount: (int)$parsed['ignored_unknown_lines_count'],
            va1ResetSalesEur: $parsed['va1_reset_sales_eur'],
            va1ResetVendUnits: $parsed['va1_reset_vend_units'],
            ca3CashboxEur: $parsed['ca3_cashbox_eur'],
            ca3CoinTubesEur: $parsed['ca3_coin_tubes_eur'],
            ca3BillsInEur: $parsed['ca3_bills_in_eur'],
            previousAuditId: $deltas['previous_audit_id'],
            counterResetDetected: (bool)$deltas['counter_reset_detected'],
            outOfOrderSync: (bool)$deltas['out_of_order_sync'],
            deltaSalesEur: $deltas['delta_sales_eur'],
            deltaVendUnits: $deltas['delta_vend_units'],
            clientMutationId: $mutationId,
            status: DexAuditStatus::ACTIVE,
            restockVisitId: $links['restock_visit_id'],
            cashCollectionId: $links['cash_collection_id'],
            incidentId: $links['incident_id']
        );

        // El correlativo anual se reserva con un `MAX()` y el alta se confirma en
        // otra transacción. Dos importaciones simultáneas pueden reservar el mismo
        // `audit_code`; el índice único lo rechaza y aquí se reintenta con el
        // siguiente código para que el conflicto visible sea el real (SHA-256 o
        // `client_mutation_id`), no una colisión interna de correlativo.
        $persisted = null;
        for ($attempt = 1; ; $attempt++) {
            try {
                $persisted = $this->repository->create($audit, $matchedSelections, $parsed['events']);
                break;
            } catch (DexAuditConflictException $conflict) {
                if ($conflict->getErrorCode() !== 'DEX_DUPLICATE_AUDIT_CODE' || $attempt >= 5) {
                    throw $conflict;
                }

                $audit = $audit->withAuditCode($this->repository->reserveAuditCode(new DateTimeImmutable($capturedAt)));
            }
        }

        $auditCode = $persisted->getAuditCode();
        $auditId = (int)$persisted->getId();
        $detail = $this->repository->findById($auditId) ?? [
            'id' => $auditId,
            'audit_code' => $auditCode,
            'status' => 'ACTIVE',
            'va1_total_sales_eur' => (float)$parsed['va1_total_sales_eur'],
            'has_thermal_alert' => false,
        ];

        $this->auditLogger?->logMachineDexAuditEvent(
            $auditId,
            'DEX_AUDIT_IMPORTED',
            [
                'id' => $actorId,
                'role' => $role,
                'name' => (string)($context['actor_name'] ?? 'Usuario'),
            ],
            null,
            [
                'status' => 'ACTIVE',
                'audit_code' => $auditCode,
                'machine_id' => $machineId,
                'import_source' => $source->value,
            ],
            [
                'audit_code' => $auditCode,
                'machine_id' => $machineId,
                'import_source' => $source->value,
                'original_filename' => $originalFilename,
            ]
        );

        $thermal = $this->assessThermal(
            (string)$context['machine_type'],
            $previousDetail['events'] ?? [],
            $parsed['events']
        );

        $enqueued = false;
        if ($thermal['novel'] && $thermal['has_alert']) {
            $enqueued = $this->enqueueThermalAlert($auditId, $auditCode, $machineId, $context, $thermal, $capturedAt, $parsed);
        }

        return [
            'audit' => $detail,
            'idempotent_replay' => false,
            'has_thermal_alert' => $thermal['has_alert'],
            'thermal_alert_enqueued' => $enqueued,
            'suggested_escalation_payload' => $this->buildEscalationPayload($role, $auditId, $auditCode, $parsed['events']),
            'prefill_sales_counter_reading_eur' => $role === 'RESTOCKER'
                ? (float)$parsed['va1_total_sales_eur']
                : null,
        ];
    }

    /**
     * Resuelve y bloquea los vínculos M34/M35/M16 del alta (PR25-05).
     *
     * Los cerrojos se toman dentro de la misma transacción que el alta, de modo
     * que la visita, la recaudación o la incidencia no pueden desaparecer ni
     * cambiar de máquina entre la comprobación y el `INSERT`. Un Reponedor solo
     * puede enlazar su propia visita o recaudación; Técnico y Coordinación
     * conservan el acceso forense sobre cualquier operación de la máquina.
     *
     * @param array<string, mixed> $request
     * @return array{restock_visit_id: int|null, cash_collection_id: int|null, incident_id: int|null}
     */
    private function resolveLinkTargets(array $request, int $machineId, string $role, int $actorId): array
    {
        $visitId = $this->optionalLinkId($request, 'restock_visit_id');
        $collectionId = $this->optionalLinkId($request, 'cash_collection_id');
        $incidentId = $this->optionalLinkId($request, 'incident_id');

        if ($visitId !== null) {
            $visit = $this->gateway->lockRestockVisit($visitId, $machineId);
            if ($visit === null) {
                throw new DexAuditValidationException(
                    'La visita de reposición vinculada no existe o pertenece a otra máquina.',
                    'DEX_LINK_TARGET_NOT_FOUND',
                    ['field' => 'restock_visit_id', 'restock_visit_id' => $visitId, 'machine_id' => $machineId]
                );
            }
            if (strtoupper((string)($visit['status'] ?? '')) !== 'COMPLETED') {
                throw new DexAuditConflictException(
                    'La visita de reposición vinculada no está completada.',
                    'DEX_LINK_TARGET_NOT_ELIGIBLE',
                    [
                        'field' => 'restock_visit_id',
                        'restock_visit_id' => $visitId,
                        'status' => (string)($visit['status'] ?? ''),
                        'expected_status' => 'COMPLETED',
                    ]
                );
            }
            if ($role === 'RESTOCKER' && (int)($visit['restocker_id'] ?? 0) !== $actorId) {
                throw new DexAuditConflictException(
                    'La visita de reposición vinculada pertenece a otro Reponedor.',
                    'DEX_LINK_TARGET_NOT_ELIGIBLE',
                    [
                        'field' => 'restock_visit_id',
                        'restock_visit_id' => $visitId,
                        'restocker_id' => (int)($visit['restocker_id'] ?? 0),
                        'actor_id' => $actorId,
                    ]
                );
            }
        }

        if ($collectionId !== null) {
            $collection = $this->gateway->lockCashCollection($collectionId, $machineId);
            if ($collection === null) {
                throw new DexAuditValidationException(
                    'La recaudación vinculada no existe o pertenece a otra máquina.',
                    'DEX_LINK_TARGET_NOT_FOUND',
                    ['field' => 'cash_collection_id', 'cash_collection_id' => $collectionId, 'machine_id' => $machineId]
                );
            }
            if ($role === 'RESTOCKER' && (int)($collection['restocker_id'] ?? 0) !== $actorId) {
                throw new DexAuditConflictException(
                    'La recaudación vinculada pertenece a otro Reponedor.',
                    'DEX_LINK_TARGET_NOT_ELIGIBLE',
                    [
                        'field' => 'cash_collection_id',
                        'cash_collection_id' => $collectionId,
                        'restocker_id' => (int)($collection['restocker_id'] ?? 0),
                        'actor_id' => $actorId,
                    ]
                );
            }
        }

        if ($incidentId !== null && $this->gateway->lockIncident($incidentId, $machineId) === null) {
            throw new DexAuditValidationException(
                'La incidencia vinculada no existe, está borrada o pertenece a otra máquina.',
                'DEX_LINK_TARGET_NOT_FOUND',
                ['field' => 'incident_id', 'incident_id' => $incidentId, 'machine_id' => $machineId]
            );
        }

        return [
            'restock_visit_id' => $visitId,
            'cash_collection_id' => $collectionId,
            'incident_id' => $incidentId,
        ];
    }

    /**
     * Lee un vínculo opcional del cuerpo: ausente o vacío es `null` y cualquier
     * otro valor debe ser un entero positivo (`422 DEX_LINK_ID_INVALID`).
     *
     * @param array<string, mixed> $request
     */
    private function optionalLinkId(array $request, string $field): ?int
    {
        if (!array_key_exists($field, $request) || $request[$field] === null) {
            return null;
        }

        $raw = $request[$field];
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        if ($raw === '') {
            return null;
        }

        $id = is_int($raw) ? $raw : (is_string($raw) && ctype_digit($raw) ? (int)$raw : 0);
        if ($id <= 0) {
            throw new DexAuditValidationException(
                sprintf('El campo %s debe ser un identificador positivo.', $field),
                'DEX_LINK_ID_INVALID',
                ['field' => $field, 'value' => $request[$field]]
            );
        }

        return $id;
    }

    /**
     * Anula lógicamente un volcado con motivo real (C6, Art. V.1).
     *
     * @return array<string, mixed>
     */
    public function voidAudit(
        int $auditId,
        string $reason,
        int $voidedBy,
        string $voidedByName,
        string $voidedAt,
        ?array $actor = null
    ): array {
        $detail = $this->repository->findById($auditId);
        if ($detail === null) {
            throw new DexAuditConflictException(
                'El volcado que se pretende anular no existe.',
                'DEX_AUDIT_NOT_FOUND',
                ['audit_id' => $auditId]
            );
        }

        $this->validator->assertVoidable((string)$detail['status']);
        $cleanReason = $this->validator->validateVoidReason($reason);
        $cleanName = trim($voidedByName);
        if ($voidedBy <= 0 || $cleanName === '' || trim($voidedAt) === '') {
            throw new DexAuditValidationException(
                'La anulación exige autor, nombre y fecha válidos.',
                'DEX_INVALID_VOID_ACTOR',
                ['audit_id' => $auditId]
            );
        }

        $voided = $this->repository->void($auditId, $cleanReason, $voidedBy, $cleanName, $voidedAt);

        $this->auditLogger?->logMachineDexAuditEvent(
            $auditId,
            'DEX_AUDIT_VOIDED',
            [
                'id' => (int)($actor['id'] ?? $voidedBy),
                'role' => (string)($actor['role'] ?? 'COORDINATOR'),
                'name' => (string)($actor['name'] ?? $cleanName),
            ],
            ['status' => (string)$detail['status']],
            [
                'status' => (string)($voided['status'] ?? 'VOIDED'),
                'void_reason' => $cleanReason,
            ],
            [
                'dex_audit_id' => $auditId,
                'audit_code' => (string)($voided['audit_code'] ?? $detail['audit_code'] ?? ''),
                'machine_id' => (int)($voided['machine_id'] ?? $detail['machine_id'] ?? 0),
                'void_reason' => $cleanReason,
            ]
        );

        return $voided;
    }

    /**
     * Exporta el listado filtrado a CSV UTF-8 BOM con separador `;` (RF-07.2).
     *
     * @param array<string, mixed> $filters
     */
    public function exportCsv(array $filters = [], ?array $actor = null): string
    {
        $rows = $this->repository->listForCoordinator($filters, self::MAX_CSV_ROWS, 0);

        $headers = [
            'audit_code', 'machine_code', 'captured_at', 'status', 'import_source',
            'va1_total_sales_eur', 'va1_total_vend_units', 'ca3_cashbox_eur',
            'delta_sales_eur', 'delta_vend_units', 'identity_mismatch_warning',
            'counter_reset_detected', 'out_of_order_sync', 'has_thermal_alert',
        ];
        $lines = [implode(';', $headers)];

        foreach ($rows as $row) {
            $values = [
                (string)($row['audit_code'] ?? ''),
                (string)($row['machine_code_snapshot'] ?? ''),
                $this->formatCapturedAt((string)($row['captured_at'] ?? '')),
                (string)($row['status'] ?? ''),
                (string)($row['import_source'] ?? ''),
                $this->decimal($row['va1_total_sales_eur'] ?? null),
                (string)($row['va1_total_vend_units'] ?? ''),
                $this->decimal($row['ca3_cashbox_eur'] ?? null),
                $this->decimal($row['delta_sales_eur'] ?? null),
                $this->decimal($row['delta_vend_units'] ?? null),
                !empty($row['identity_mismatch_warning']) ? 'SI' : 'NO',
                !empty($row['counter_reset_detected']) ? 'SI' : 'NO',
                !empty($row['out_of_order_sync']) ? 'SI' : 'NO',
                !empty($row['has_thermal_alert']) ? 'SI' : 'NO',
            ];
            $lines[] = implode(';', array_map(fn (string $value): string => $this->csvCell($value), $values));
        }

        $exportedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d\TH:i:sP');
        if ($this->auditLogger !== null && $actor !== null && (int)($actor['id'] ?? 0) > 0) {
            $this->auditLogger->logMachineDexAuditExport(
                AuditEvent::GLOBAL_SCOPE_ENTITY_ID,
                'EXPORT_DEX_AUDITS_CSV',
                $actor,
                null,
                array_merge($filters, [
                    'row_count' => count($rows),
                    'exported_at' => $exportedAt,
                ]),
                [
                    'filters' => $filters,
                    'row_count' => count($rows),
                    'exported_at' => $exportedAt,
                ]
            );
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Listado de volcados `ACTIVE` de una máquina para la puerta móvil indicada.
     *
     * El perímetro se resuelve antes de consultar el historial: la máquina debe
     * existir y estar viva (`404 MACHINE_NOT_FOUND`) y el actor debe cubrirla
     * (`403 MACHINE_OUTSIDE_RESTOCKER_ZONES`/`MACHINE_OUTSIDE_TECHNICIAN_SCOPE`).
     * Es una lectura sin `FOR UPDATE`: no compite con una importación en curso.
     *
     * @return list<array<string, mixed>>
     */
    public function listForRole(int $machineId, string $role, int $actorId, int $limit = 50): array
    {
        $normalizedRole = strtoupper(trim($role));
        if (!in_array($normalizedRole, ['RESTOCKER', 'TECHNICIAN'], true)) {
            throw new DexAuditValidationException(
                sprintf("El rol '%s' no puede consultar el historial .dex móvil.", $role),
                'DEX_INVALID_READER_ROLE',
                ['role' => $role]
            );
        }

        if ($machineId <= 0 || $actorId <= 0) {
            throw new DexAuditValidationException(
                'La consulta del historial .dex no trae una máquina y un actor válidos.',
                'DEX_INVALID_READ_REQUEST',
                ['machine_id' => $machineId, 'actor_id' => $actorId]
            );
        }

        $context = $this->gateway->findMachineContext($machineId, $actorId);
        if ($context === null) {
            throw new DexAuditValidationException(
                'La máquina solicitada no existe o está inactiva.',
                'MACHINE_NOT_FOUND',
                ['machine_id' => $machineId],
                404
            );
        }

        if (($context['actor_is_active'] ?? false) !== true) {
            throw new DexAuditForbiddenException(
                'El usuario que intenta consultar el historial .dex no está activo.',
                'DEX_ACTOR_INACTIVE',
                ['actor_id' => $actorId]
            );
        }

        $coversTerritory = $normalizedRole === 'RESTOCKER'
            ? (bool)($context['is_covered_restocker'] ?? false)
            : (bool)($context['is_covered_technician'] ?? false);
        if (!$coversTerritory) {
            throw new DexAuditForbiddenException(
                $normalizedRole === 'RESTOCKER'
                    ? 'La máquina queda fuera de las zonas operativas activas del Reponedor.'
                    : 'El Técnico no tiene intervención activa ni cobertura de la zona de la sede.',
                $normalizedRole === 'RESTOCKER'
                    ? 'MACHINE_OUTSIDE_RESTOCKER_ZONES'
                    : 'MACHINE_OUTSIDE_TECHNICIAN_SCOPE',
                ['machine_id' => $machineId, 'actor_id' => $actorId, 'role' => $normalizedRole]
            );
        }

        $rows = $this->repository->listForCoordinator(
            ['machine_id' => $machineId, 'status' => 'ACTIVE'],
            max(1, min($limit, 100)),
            0
        );

        return array_values(array_map(
            fn (array $row): array => $this->projectByRole($row, $normalizedRole, false),
            $rows
        ));
    }

    /**
     * Listado paginado de Coordinación con sus KPIs (RF-07.1).
     *
     * @param array<string, mixed> $filters
     * @return array{audits: list<array<string, mixed>>, kpis: array<string, int>}
     */
    public function listForCoordinator(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        return [
            'audits' => $this->repository->listForCoordinator($filters, max(1, min($limit, 500)), max(0, $offset)),
            'kpis' => $this->repository->kpisForCoordinator($filters),
        ];
    }

    /**
     * Detalle de un volcado con la proyección cerrada del rol consultante.
     *
     * @return array<string, mixed>|null
     */
    public function findForRole(int $auditId, string $role, ?int $machineId = null): ?array
    {
        $row = $this->repository->findById($auditId);
        if ($row === null || ($machineId !== null && (int)$row['machine_id'] !== $machineId)) {
            return null;
        }

        if (strtoupper(trim($role)) === 'COORDINATOR') {
            $row = $this->attachCoordinatorReadModel($row);
        }

        return $this->projectByRole($row, $role, false);
    }

    /**
     * Modelo de lectura de Coordinación: delta efectivo tras `VOIDED` y cruces
     * M34/M35. No escribe en ninguna tabla (QA-2, QA-3, Art. III.3).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function attachCoordinatorReadModel(array $row): array
    {
        $row = $this->attachEffectiveDeltaAfterVoid($row);
        $row['m34_cross_check'] = $this->buildM34CrossCheck($row);
        $row['m35_cross_check'] = $this->buildM35CrossCheck($row);

        return $row;
    }

    /**
     * Recalcula en lectura el delta efectivo cuando el predecesor persistido ya
     * está `VOIDED` (QA-2).
     *
     * Se resuelve el último `ACTIVE` anterior a `captured_at` y se aplica la
     * misma semántica de reset que en el alta (`RESET_FALLBACK`/`UNAVAILABLE`),
     * sin reescribir la cabecera ni las líneas del volcado (Art. III.3).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function attachEffectiveDeltaAfterVoid(array $row): array
    {
        $row['previous_audit_voided'] = false;
        $row['effective_previous_audit_id'] = null;
        $row['effective_delta_sales_eur'] = null;
        $row['effective_delta_vend_units'] = null;

        $previousAuditId = isset($row['previous_audit_id']) && $row['previous_audit_id'] !== null
            ? (int)$row['previous_audit_id']
            : null;
        if ($previousAuditId === null || $previousAuditId <= 0) {
            return $row;
        }

        $previous = $this->repository->findById($previousAuditId);
        if ($previous === null || strtoupper((string)($previous['status'] ?? '')) !== 'VOIDED') {
            return $row;
        }

        $row['previous_audit_voided'] = true;
        $machineId = (int)($row['machine_id'] ?? 0);
        $capturedAt = trim((string)($row['captured_at'] ?? ''));
        $currentAuditId = isset($row['id']) ? (int)$row['id'] : null;
        $effectivePrevious = $machineId > 0 && $capturedAt !== ''
            ? $this->repository->findPreviousActive($machineId, $capturedAt, $currentAuditId)
            : null;
        if ($effectivePrevious === null) {
            return $row;
        }

        $effectivePreviousId = (int)($effectivePrevious['id'] ?? 0);
        if ($effectivePreviousId <= 0) {
            return $row;
        }

        $resolved = $this->validator->resolveDeltas(
            (float)($row['va1_total_sales_eur'] ?? 0.0),
            (int)($row['va1_total_vend_units'] ?? 0),
            isset($row['va1_reset_sales_eur']) && $row['va1_reset_sales_eur'] !== null
                ? (float)$row['va1_reset_sales_eur']
                : null,
            isset($row['va1_reset_vend_units']) && $row['va1_reset_vend_units'] !== null
                ? (int)$row['va1_reset_vend_units']
                : null,
            [
                'id' => $effectivePreviousId,
                'va1_total_sales_eur' => (float)($effectivePrevious['va1_total_sales_eur'] ?? 0.0),
                'va1_total_vend_units' => (int)($effectivePrevious['va1_total_vend_units'] ?? 0),
            ]
        );

        $row['effective_previous_audit_id'] = $effectivePreviousId;
        $row['effective_delta_sales_eur'] = $resolved['delta_sales_eur'];
        $row['effective_delta_vend_units'] = $resolved['delta_vend_units'];

        return $row;
    }

    /**
     * Cruces no destructivos con M34 y M35 en la lectura de Coordinación (QA-3).
     *
     * No escribe en `machine_planogram_slots`, en las visitas ni en las
     * recaudaciones: toma los hechos del gateway y los proyecta. Si no hay visita
     * o recaudación aplicable, el cruce correspondiente queda en `null`.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function attachCoordinatorCrossChecks(array $row): array
    {
        $row['m34_cross_check'] = $this->buildM34CrossCheck($row);
        $row['m35_cross_check'] = $this->buildM35CrossCheck($row);

        return $row;
    }

    /**
     * Cruce financiero M35 (QA-3): prioriza `CA3_VS_VAULT` y cae a
     * `VA1_VS_EXPECTED`; la desviación se marca por encima de ±1,00 €.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function buildM35CrossCheck(array $row): ?array
    {
        $machineId = (int)($row['machine_id'] ?? 0);
        $capturedAt = trim((string)($row['captured_at'] ?? ''));
        if ($machineId <= 0 || $capturedAt === '') {
            return null;
        }

        $linkedCashCollectionId = isset($row['cash_collection_id']) && $row['cash_collection_id'] !== null
            ? (int)$row['cash_collection_id']
            : null;
        $collection = $this->gateway->findNearestCashCollectionSameDay($machineId, $capturedAt, $linkedCashCollectionId);
        if ($collection === null) {
            return null;
        }

        $ca3 = isset($row['ca3_cashbox_eur']) && $row['ca3_cashbox_eur'] !== null
            ? (float)$row['ca3_cashbox_eur']
            : null;
        $vault = isset($collection['vault_counted_eur']) && $collection['vault_counted_eur'] !== null
            ? (float)$collection['vault_counted_eur']
            : null;
        $expected = isset($collection['expected_cashbox_eur']) && $collection['expected_cashbox_eur'] !== null
            ? (float)$collection['expected_cashbox_eur']
            : null;

        $deltaSales = null;
        foreach (['effective_delta_sales_eur', 'delta_sales_eur'] as $deltaKey) {
            if (isset($row[$deltaKey]) && $row[$deltaKey] !== null) {
                $deltaSales = (float)$row[$deltaKey];
                break;
            }
        }

        $basis = null;
        $diff = null;
        if ($ca3 !== null && $vault !== null) {
            $basis = 'CA3_VS_VAULT';
            $diff = round($vault - $ca3, 2);
        } elseif ($deltaSales !== null && $expected !== null) {
            $basis = 'VA1_VS_EXPECTED';
            $diff = round($expected - $deltaSales, 2);
        }

        return [
            'cash_collection_id' => (int)$collection['id'],
            'collected_at' => (string)($collection['collected_at'] ?? ''),
            'comparison_basis' => $basis,
            'ca3_cashbox_eur' => $ca3,
            'vault_counted_eur' => $vault,
            'expected_cashbox_eur' => $expected,
            'delta_sales_eur' => $deltaSales,
            'diff_eur' => $diff,
            'tolerance_eur' => 1.00,
            'is_deviation' => $diff !== null && abs($diff) > 1.00,
        ];
    }

    /**
     * Cruce de carril M34 (QA-3): consumo observado entre la visita vinculada o
     * del día y su predecesora `COMPLETED` inmediata.
     *
     * `observed_consumption_units = prev_item.qty_after - curr_item.qty_before`.
     * La diferencia frente a `delta_vend_units` queda en `null` si falta alguna
     * de las dos visitas, el carril o el delta del volcado.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function buildM34CrossCheck(array $row): ?array
    {
        $machineId = (int)($row['machine_id'] ?? 0);
        $capturedAt = trim((string)($row['captured_at'] ?? ''));
        if ($machineId <= 0 || $capturedAt === '') {
            return null;
        }

        $linkedVisitId = isset($row['restock_visit_id']) && $row['restock_visit_id'] !== null
            ? (int)$row['restock_visit_id']
            : null;
        $visit = $this->gateway->findCompletedRestockVisitSameDay($machineId, $capturedAt, $linkedVisitId);
        if ($visit === null) {
            return null;
        }

        $previous = $this->gateway->findPreviousCompletedRestockVisit($machineId, (int)$visit['id']);
        $currentQuantities = $this->indexVisitQuantities(
            $this->gateway->findVisitSlotQuantities((int)$visit['id'])
        );
        $previousQuantities = $previous === null
            ? []
            : $this->indexVisitQuantities(
                $this->gateway->findVisitSlotQuantities((int)$previous['id'])
            );

        $lines = [];
        foreach ((array)($row['selections'] ?? []) as $selection) {
            if (!is_array($selection) || ($selection['matched_slot_id'] ?? null) === null) {
                continue;
            }

            $slotId = (int)$selection['matched_slot_id'];
            $current = $currentQuantities[$slotId] ?? null;
            $previousItem = $previousQuantities[$slotId] ?? null;
            $observed = ($current !== null && $previousItem !== null)
                ? (int)$previousItem['qty_after'] - (int)$current['qty_before']
                : null;
            $deltaVendUnits = isset($selection['delta_vend_units']) && $selection['delta_vend_units'] !== null
                ? (int)$selection['delta_vend_units']
                : null;

            $lines[] = [
                'slot_id' => $slotId,
                'slot_code' => (string)($selection['matched_slot_code_snapshot'] ?? ($current['slot_code'] ?? '')),
                'total_vend_units' => (int)($selection['total_vend_units'] ?? 0),
                'delta_vend_units' => $deltaVendUnits,
                'observed_consumption_units' => $observed,
                'difference_units' => ($observed !== null && $deltaVendUnits !== null)
                    ? $deltaVendUnits - $observed
                    : null,
            ];
        }

        return [
            'visit_id' => (int)$visit['id'],
            'completed_at' => (string)($visit['completed_at'] ?? ''),
            'previous_visit_id' => $previous !== null ? (int)$previous['id'] : null,
            'previous_completed_at' => $previous !== null ? (string)($previous['completed_at'] ?? '') : null,
            'lines' => $lines,
        ];
    }

    /**
     * @param list<array{slot_id:int, slot_code:string, qty_before:int, qty_after:int}> $quantities
     * @return array<int, array{slot_id:int, slot_code:string, qty_before:int, qty_after:int}>
     */
    private function indexVisitQuantities(array $quantities): array
    {
        $indexed = [];
        foreach ($quantities as $quantity) {
            $indexed[(int)$quantity['slot_id']] = $quantity;
        }

        return $indexed;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRawPayload(int $auditId): ?array
    {
        return $this->repository->findRawPayload($auditId);
    }

    /**
     * Proyecta el resultado del `POST` inmediato según la puerta de importación.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function presentImportResult(array $result, string $role): array
    {
        $audit = is_array($result['audit'] ?? null) ? $result['audit'] : [];
        $presented = [
            'audit' => $this->projectByRole($audit, $role, $role === 'RESTOCKER'),
            'idempotent_replay' => (bool)($result['idempotent_replay'] ?? false),
            'has_thermal_alert' => (bool)($result['has_thermal_alert'] ?? false),
            'thermal_alert_enqueued' => (bool)($result['thermal_alert_enqueued'] ?? false),
            'suggested_escalation_payload' => $result['suggested_escalation_payload'] ?? null,
        ];

        if ($role === 'RESTOCKER') {
            $presented['prefill_sales_counter_reading_eur'] = $result['prefill_sales_counter_reading_eur'] ?? null;
        }

        return $presented;
    }

    /**
     * Proyecciones Art. V.4: el Reponedor y el Técnico nunca reciben euros.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function projectByRole(array $row, string $role, bool $immediatePrefill): array
    {
        $normalizedRole = strtoupper(trim($role));
        if ($normalizedRole === 'COORDINATOR') {
            // El repositorio entrega la instantánea de la máquina; la consola de
            // Coordinación consume `machine_code`, igual que `toCoordinatorArray()`.
            $row['machine_code'] = $row['machine_code_snapshot'] ?? $row['machine_code'] ?? null;
            $row['machine_type'] = $row['machine_type_snapshot'] ?? $row['machine_type'] ?? null;
            $row['can_download_raw'] = true;

            return $row;
        }

        $selections = [];
        foreach ((array)($row['selections'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $selections[] = [
                'normalized_selection_code' => $line['normalized_selection_code'] ?? null,
                'matched_slot_code' => $line['matched_slot_code_snapshot'] ?? null,
                'match_status' => $line['match_status'] ?? null,
                'slot_current_estimated_qty' => $line['slot_current_estimated_qty'] ?? null,
                'total_vend_units' => $line['total_vend_units'] ?? null,
                'delta_vend_units' => $line['delta_vend_units'] ?? null,
            ];
        }

        $events = [];
        foreach ((array)($row['events'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $events[] = [
                'event_code' => $event['event_code'] ?? null,
                'event_category' => $event['event_category'] ?? null,
                'severity' => $event['severity'] ?? null,
                'occurrence_count' => $event['occurrence_count'] ?? null,
                'count_since_reset' => $event['count_since_reset'] ?? null,
                'count_since_init' => $event['count_since_init'] ?? null,
                'is_active_flag' => $event['is_active_flag'] ?? null,
                'is_thermal_alert' => $event['is_thermal_alert'] ?? null,
                'description_es' => $event['description_es'] ?? null,
            ];
        }

        $projected = [
            'id' => $row['id'] ?? null,
            'audit_code' => $row['audit_code'] ?? null,
            'machine_id' => $row['machine_id'] ?? null,
            'machine_code' => $row['machine_code_snapshot'] ?? null,
            'captured_at' => $row['captured_at'] ?? null,
            'identity_mismatch_warning' => (bool)($row['identity_mismatch_warning'] ?? false),
            'counter_reset_detected' => (bool)($row['counter_reset_detected'] ?? false),
            'out_of_order_sync' => (bool)($row['out_of_order_sync'] ?? false),
            'va1_total_vend_units' => $row['va1_total_vend_units'] ?? null,
            'delta_vend_units' => $row['delta_vend_units'] ?? null,
            'has_thermal_alert' => (bool)($row['has_thermal_alert'] ?? false),
            'selections' => $selections,
            'events' => $events,
        ];

        if ($normalizedRole === 'RESTOCKER' && $immediatePrefill && isset($row['va1_total_sales_eur'])) {
            $projected['prefill_sales_counter_reading_eur'] = (float)$row['va1_total_sales_eur'];
        }

        return $projected;
    }

    /**
     * @param list<array<string, mixed>> $selections
     * @param list<array<string, mixed>> $previousSelections
     * @return list<array<string, mixed>>
     */
    private function applySelectionDeltas(array $selections, array $previousSelections, bool $resetDetected): array
    {
        $previousByCode = [];
        foreach ($previousSelections as $line) {
            $code = (string)($line['normalized_selection_code'] ?? '');
            if ($code !== '') {
                $previousByCode[$code] = $line;
            }
        }

        foreach ($selections as $index => $line) {
            $selections[$index]['delta_vend_units'] = null;
            $selections[$index]['delta_sales_eur'] = null;

            if ($resetDetected) {
                $selections[$index]['delta_vend_units'] = $line['reset_vend_units'] ?? null;
                $selections[$index]['delta_sales_eur'] = $line['reset_sales_eur'] ?? null;
                continue;
            }

            $previous = $previousByCode[(string)($line['normalized_selection_code'] ?? '')] ?? null;
            if ($previous === null) {
                continue;
            }

            $currentUnits = (int)($line['total_vend_units'] ?? 0);
            $previousUnits = (int)($previous['total_vend_units'] ?? 0);
            $currentSales = (float)($line['total_sales_eur'] ?? 0.0);
            $previousSales = (float)($previous['total_sales_eur'] ?? 0.0);
            if ($currentUnits < $previousUnits || $currentSales < $previousSales) {
                continue;
            }

            $selections[$index]['delta_vend_units'] = $currentUnits - $previousUnits;
            $selections[$index]['delta_sales_eur'] = round($currentSales - $previousSales, 2);
        }

        return $selections;
    }

    /**
     * @param list<array<string, mixed>> $previousEvents
     * @param list<array<string, mixed>> $newEvents
     * @return array{has_alert: bool, novel: bool, codes: list<string>, severity: string}
     */
    private function assessThermal(string $machineType, array $previousEvents, array $newEvents): array
    {
        $thermal = array_values(array_filter(
            $newEvents,
            static fn (array $event): bool => ($event['is_thermal_alert'] ?? false) === true
        ));
        if ($thermal === []) {
            return ['has_alert' => false, 'novel' => false, 'codes' => [], 'severity' => 'INFO'];
        }

        $codes = array_values(array_unique(array_map(
            static fn (array $event): string => (string)($event['event_code'] ?? ''),
            $thermal
        )));
        $hasAlert = in_array(strtoupper(trim($machineType)), self::COLD_CHAIN_MACHINE_TYPES, true);
        if (!$hasAlert) {
            return ['has_alert' => false, 'novel' => false, 'codes' => $codes, 'severity' => 'CRITICAL'];
        }

        $previousThermal = array_values(array_filter(
            $previousEvents,
            static fn (array $event): bool => ($event['is_thermal_alert'] ?? false) === true
        ));

        if ($previousThermal === []) {
            return ['has_alert' => true, 'novel' => true, 'codes' => $codes, 'severity' => 'CRITICAL'];
        }

        $previousByCode = [];
        foreach ($previousThermal as $event) {
            $code = (string)($event['event_code'] ?? '');
            $previousByCode[$code] = max(
                (int)($event['count_since_init'] ?? 0),
                (int)($event['count_since_reset'] ?? 0)
            );
        }

        foreach ($thermal as $event) {
            $code = (string)($event['event_code'] ?? '');
            if (!array_key_exists($code, $previousByCode)) {
                return ['has_alert' => true, 'novel' => true, 'codes' => $codes, 'severity' => 'CRITICAL'];
            }
            $currentCount = (int)($event['count_since_init'] ?? 0);
            if ($currentCount > $previousByCode[$code]) {
                return ['has_alert' => true, 'novel' => true, 'codes' => $codes, 'severity' => 'CRITICAL'];
            }
        }

        return ['has_alert' => true, 'novel' => false, 'codes' => $codes, 'severity' => 'CRITICAL'];
    }

    /**
     * @param array<string, mixed> $context
     * @param array{has_alert: bool, novel: bool, codes: list<string>, severity: string} $thermal
     * @param array<string, mixed> $parsed
     */
    private function enqueueThermalAlert(
        int $auditId,
        string $auditCode,
        int $machineId,
        array $context,
        array $thermal,
        string $capturedAt,
        array $parsed
    ): bool {
        $item = new NotificationOutboxItem(
            id: null,
            incidentId: null,
            channel: NotificationChannel::WEBHOOK,
            priority: NotificationPriority::CRITICAL_SANITARY,
            template: NotificationTemplate::DEX_THERMAL_EVENT_ALERT,
            recipient: 'coordinacion@vendguard.es',
            recipientRole: 'COORDINATOR',
            recipientUserId: null,
            recipientLocationId: null,
            subject: 'Alerta térmica .dex · ' . $auditCode,
            payload: [
                'audit_id' => $auditId,
                'audit_code' => $auditCode,
                'machine_id' => $machineId,
                'machine_code' => (string)($context['machine_code'] ?? ''),
                'captured_at' => $capturedAt,
                'event_codes' => implode(', ', $thermal['codes']),
                'severity' => $thermal['severity'],
                'id4_decimal_places' => (int)$parsed['id4_decimal_places'],
            ],
            dedupKey: self::THERMAL_DEDUP_PREFIX . $machineId . ':' . $auditId
        );

        return $this->outbox->enqueue($item) !== null;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>|null
     */
    private function buildEscalationPayload(string $role, int $auditId, string $auditCode, array $events): ?array
    {
        $actionable = array_values(array_filter(
            $events,
            static fn (array $event): bool => in_array(
                strtoupper((string)($event['severity'] ?? '')),
                ['CRITICAL', 'WARNING'],
                true
            )
        ));
        if ($actionable === []) {
            return null;
        }

        $codes = array_values(array_unique(array_map(
            static fn (array $event): string => (string)($event['event_code'] ?? ''),
            $actionable
        )));
        $hasCritical = false;
        foreach ($actionable as $event) {
            if (strtoupper((string)($event['severity'] ?? '')) === 'CRITICAL') {
                $hasCritical = true;
                break;
            }
        }

        $firstCategory = strtoupper((string)($actionable[0]['event_category'] ?? 'OTHER'));
        $action = match ($role) {
            'RESTOCKER' => self::ACTION_ESCALATE_TO_SAT,
            'TECHNICIAN' => self::ACTION_OPEN_OPPORTUNISTIC_INCIDENT,
            default => self::ACTION_VIEW_IN_COORDINATION,
        };

        return [
            'action' => $action,
            'breakdown_category' => self::CATEGORY_TO_INCIDENT[$firstCategory] ?? 'OTHER',
            'urgency' => $hasCritical ? 'CRITICAL' : 'HIGH',
            'internal_note' => sprintf('[DEX %s] Códigos EA1/EA2: %s', $auditCode, implode(', ', $codes)),
            'dex_audit_id' => $auditId,
            'is_internal' => true,
        ];
    }

    /**
     * Normaliza la captura del cliente y la fija en UTC para toda la unidad de trabajo.
     *
     * Los formatos sin offset son la hora local de la sede (`Europe/Madrid`), que
     * es lo que emite el `datetime-local` de la consola de Coordinación. Los
     * formatos con offset/Z conservan el instante enviado. La tolerancia futura
     * protege la cadena de deltas sin castigar un pequeño desfase de reloj móvil.
     */
    private function normalizeCapturedAt(string $raw): string
    {
        $raw = trim($raw);
        $captured = null;
        foreach (self::CAPTURED_AT_FORMATS as $format) {
            $candidate = DateTimeImmutable::createFromFormat(
                $format,
                $raw,
                new DateTimeZone(self::OPERATIONAL_TIMEZONE)
            );
            $errors = DateTimeImmutable::getLastErrors();
            if ($candidate === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }

            $captured = $candidate;
            break;
        }

        if ($captured === null) {
            throw new DexAuditValidationException(
                'La fecha de captura no es una fecha ISO 8601 válida ni una fecha local interpretable.',
                'DEX_INVALID_CAPTURED_AT',
                ['field' => 'captured_at', 'value' => $raw, 'timezone' => self::OPERATIONAL_TIMEZONE]
            );
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $latest = $now->modify('+' . self::CAPTURED_AT_MAX_FUTURE_SECONDS . ' seconds');
        if ($captured > $latest) {
            throw new DexAuditValidationException(
                'La fecha de captura no puede estar más de cinco minutos por delante del reloj del servidor.',
                'DEX_CAPTURED_AT_IN_FUTURE',
                [
                    'field' => 'captured_at',
                    'value' => $raw,
                    'max_future_seconds' => self::CAPTURED_AT_MAX_FUTURE_SECONDS,
                ]
            );
        }

        return $captured
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:sP');
    }

    private function formatCapturedAt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('Europe/Madrid'))
                ->format('d/m/Y H:i');
        } catch (\Exception) {
            return $value;
        }
    }

    private function decimal(mixed $value): string
    {
        return $value === null || $value === '' ? '' : number_format((float)$value, 2, '.', '');
    }

    private function csvCell(string $value): string
    {
        $safe = $this->validator->sanitizeCsvCell($value);
        if (strpbrk($safe, ";\"\n\r") !== false) {
            return '"' . str_replace('"', '""', $safe) . '"';
        }

        return $safe;
    }
}
