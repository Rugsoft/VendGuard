<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use VendGuard\Core\Domain\Enum\RestockOverrideType;
use VendGuard\Core\Domain\Enum\RestockReassignmentReasonCode;
use VendGuard\Core\Domain\Enum\RestockSkipReasonCode;
use VendGuard\Core\Domain\Exception\RestockRoutePlannerValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\RestockRouteDailyOverride;
use VendGuard\Core\Domain\Repository\RestockRoutePlannerGatewayInterface;
use VendGuard\Core\Domain\Repository\RestockRouteReadModelInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Service\RestockCadenceEvaluator;
use VendGuard\Core\Service\RestockRoutePlannerValidator;

/**
 * RestockRouteOverrideService
 *
 * Casos de uso de las excepciones diarias de ruta (Módulo 37; RF-02.1–RF-02.3,
 * C1, C3, QA-1, QA-3, QA-4, Art. II, Art. III.1, Art. III.3 y Art. V.1).
 *
 * Reglas que el servicio hace cumplir:
 *
 *   1. **`SKIPPED` con rigor textual.** Solo Coordinación o el reponedor
 *      propietario; motivo tipificado y `reason_text` de `20..500` caracteres
 *      reales con al menos tres bloques alfanuméricos (Art. V.1). El reponedor
 *      solo omite paradas de su ruta efectiva de hoy.
 *   2. **`REASSIGNED` solo Coordinación.** Destino activo, causa tipificada y
 *      prohibición de auto-desvío.
 *   3. **Una sola excepción viva por máquina y día.** Registrar una nueva
 *      revoca la anterior en la misma transacción y deja su rastro; nunca se
 *      borra (Art. III.1).
 *   4. **Salvaguarda FEFO.** Si la parada tenía alerta FEFO viva, se encola el
 *      aviso interno `RESTOCK_FEFO_STOP_SKIPPED_ALERT` para Coordinación; si el
 *      lote refrigerado ya venció, la máquina se sella `EXPIRED` sin bloquear el
 *      flujo del Módulo 34 (Art. II).
 *   5. **Idempotencia offline.** Un reintento con el mismo
 *      `client_mutation_id` devuelve la respuesta original sin duplicar la
 *      excepción ni la mutación (`C3`).
 *   6. **Opacidad.** El texto libre de Coordinación no se publica en la hoja
 *      móvil de otro reponedor (Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockRouteOverrideService
{
    public const ACTION_SKIP = 'SKIP_RESTOCK_STOP';
    public const ACTION_REASSIGN = 'REASSIGN_RESTOCK_STOP';
    public const ACTION_REVOKE = 'REVOKE_RESTOCK_ROUTE_OVERRIDE';
    public const ACTION_CONFLICT_DUMP = 'DUMP_RESTOCK_OVERRIDE_CONFLICT';

    private RestockRoutePlannerGatewayInterface $gateway;
    private RestockRouteReadModelInterface $readModel;
    private RestockRoutePlannerService $planner;
    private AuditLogger $auditLogger;
    private TransactionManagerInterface $transactions;
    private string $alertRecipient;
    private string $alertChannel;

    public function __construct(
        RestockRoutePlannerGatewayInterface $gateway,
        RestockRouteReadModelInterface $readModel,
        RestockRoutePlannerService $planner,
        AuditLogger $auditLogger,
        TransactionManagerInterface $transactions,
        string $alertRecipient = 'coordinacion@vendguard.local',
        string $alertChannel = 'EMAIL'
    ) {
        $this->gateway = $gateway;
        $this->readModel = $readModel;
        $this->planner = $planner;
        $this->auditLogger = $auditLogger;
        $this->transactions = $transactions;
        $this->alertRecipient = $alertRecipient;
        $this->alertChannel = $alertChannel;
    }

    /**
     * Registra una omisión `SKIPPED`.
     *
     * @param array<string, mixed> $payload
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array<string, mixed>
     */
    public function skip(int $machineId, array $payload, array $actor, ?DateTimeImmutable $now = null): array
    {
        $instant = $now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid'));
        $today = RestockCadenceEvaluator::today($instant);
        $role = strtoupper(trim((string)($actor['role'] ?? '')));
        $actorId = $this->actorId($actor);

        $serviceDate = isset($payload['service_date']) && trim((string)$payload['service_date']) !== ''
            ? RestockCadenceEvaluator::normalizeServiceDate((string)$payload['service_date'])
            : $today;

        $skipReason = $this->parseSkipReason($payload['skip_reason_code'] ?? null);
        $reasonText = RestockRoutePlannerValidator::assertSkipRequest(
            $role,
            $serviceDate,
            $today,
            $skipReason,
            isset($payload['reason_text']) ? (string)$payload['reason_text'] : null,
            isset($payload['client_mutation_id']) ? (string)$payload['client_mutation_id'] : null
        );
        $mutationId = RestockRoutePlannerValidator::normalizeMutationId(
            isset($payload['client_mutation_id']) ? (string)$payload['client_mutation_id'] : null,
            false
        );

        $replay = $this->replayOffline($actorId, $mutationId);
        if ($replay !== null) {
            return $replay;
        }

        $machine = $this->requireMachine($machineId, $serviceDate);
        $this->assertStopAvailable($machine, $serviceDate);

        if ($role === RestockRoutePlannerValidator::ROLE_RESTOCKER) {
            $restockersById = $this->indexRestockers($this->readModel->listRestockers($serviceDate));
            $ownerId = $this->planner->resolveOwnerId(
                $machine,
                $restockersById,
                $serviceDate,
                $serviceDate > $today
            );
            RestockRoutePlannerValidator::assertRestockerOwnsStop($ownerId === $actorId, $machineId, $actorId);
        }

        $restockersById = $this->indexRestockers($this->readModel->listRestockers($serviceDate));
        $originalOwnerId = $this->planner->resolveOwnerId(
            $machine,
            $restockersById,
            $serviceDate,
            $serviceDate > $today
        );
        $hasFefoAlert = $this->planner->hasFefoAlert($machine, $serviceDate);
        $expired = (bool)$machine['has_expired_cold_batch'];
        $clientOccurredAt = isset($payload['client_occurred_at']) && trim((string)$payload['client_occurred_at']) !== ''
            ? (string)$payload['client_occurred_at']
            : $instant->format(DATE_ATOM);

        /** @var array<string, mixed> $result */
        $result = $this->transactions->runInTransaction(function () use (
            $machine,
            $machineId,
            $serviceDate,
            $skipReason,
            $reasonText,
            $hasFefoAlert,
            $expired,
            $originalOwnerId,
            $mutationId,
            $clientOccurredAt,
            $actor,
            $actorId
        ): array {
            $previous = $this->gateway->findActiveOverride($machineId, $serviceDate);
            $previousId = $previous?->getId();
            if ($previous !== null) {
                $this->gateway->revokeActiveOverrides(
                    $machineId,
                    $serviceDate,
                    $actorId,
                    $this->timestamp()
                );
                $this->audit(
                    AuditEvent::ENTITY_RESTOCK_ROUTE_DAILY_OVERRIDE,
                    (int)$previousId,
                    self::ACTION_REVOKE,
                    $actor,
                    $previous->toArray(),
                    $previous->revoke($actorId, $this->timestamp())->toArray(),
                    ['machine_id' => $machineId, 'service_date' => $serviceDate, 'cause' => 'SUPERSEDED_BY_NEW_OVERRIDE']
                );
            }

            $override = new RestockRouteDailyOverride(
                null,
                $machineId,
                $serviceDate,
                RestockOverrideType::SKIPPED,
                \VendGuard\Core\Domain\Enum\RestockOverrideStatus::ACTIVE,
                $originalOwnerId,
                null,
                $skipReason,
                null,
                $reasonText,
                $hasFefoAlert,
                null,
                $mutationId,
                null,
                $actorId
            );

            $overrideId = $this->gateway->insertOverride($override);
            $persisted = $override->withId($overrideId);

            $notificationId = null;
            if ($hasFefoAlert) {
                $notificationId = $this->gateway->enqueueFefoSkipAlert(
                    $machineId,
                    $serviceDate,
                    $overrideId,
                    $this->alertRecipient,
                    $this->alertChannel,
                    [
                        'machine_code' => (string)$machine['machine_code'],
                        'location_name' => (string)$machine['location_name'],
                        'original_restocker_id' => $originalOwnerId,
                        'override_id' => $overrideId,
                    ]
                );
                $this->gateway->attachNotificationToOverride($overrideId, $notificationId);
                $persisted = $persisted->withNotification($notificationId);
            }

            $expiredStamp = false;
            if ($expired) {
                $expiredStamp = $this->gateway->markMachineSanitaryExpired($machineId);
            }

            if ($mutationId !== null) {
                $this->gateway->recordOfflineMutation(
                    $actorId,
                    $mutationId,
                    $machineId,
                    $clientOccurredAt,
                    [
                        'override_id' => $overrideId,
                        'status' => 'ACTIVE',
                        'override_type' => 'SKIPPED',
                        'machine_id' => $machineId,
                        'service_date' => $serviceDate,
                    ],
                    201
                );
            }

            $this->audit(
                AuditEvent::ENTITY_RESTOCK_ROUTE_DAILY_OVERRIDE,
                $overrideId,
                self::ACTION_SKIP,
                $actor,
                null,
                $persisted->toArray(),
                [
                    'machine_id' => $machineId,
                    'service_date' => $serviceDate,
                    'fefo_alert' => $hasFefoAlert,
                    'expired_stamp' => $expiredStamp,
                    'notification_id' => $notificationId,
                    'revoked_previous_override_id' => $previousId,
                ]
            );

            return [
                'override' => array_merge($persisted->toArray(), [
                    'revoked_previous_override_id' => $previousId,
                ]),
                'fefo_alert' => $hasFefoAlert,
                'expired_stamp' => $expiredStamp,
                'notification_id' => $notificationId,
                'idempotent_replay' => false,
            ];
        });

        return $result;
    }

    /**
     * Registra un desvío puntual `REASSIGNED` (solo Coordinación).
     *
     * @param array<string, mixed> $payload
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array<string, mixed>
     */
    public function reassign(array $payload, array $actor, ?DateTimeImmutable $now = null): array
    {
        $instant = $now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid'));
        $today = RestockCadenceEvaluator::today($instant);
        $role = strtoupper(trim((string)($actor['role'] ?? '')));
        $actorId = $this->actorId($actor);

        $machineId = (int)($payload['machine_id'] ?? 0);
        if ($machineId <= 0) {
            throw RestockRoutePlannerValidationException::machineNotFound($machineId);
        }

        $serviceDate = isset($payload['service_date']) && trim((string)$payload['service_date']) !== ''
            ? RestockCadenceEvaluator::normalizeServiceDate((string)$payload['service_date'])
            : $today;

        $targetId = (int)($payload['target_restocker_id'] ?? 0);
        $target = $targetId > 0 ? $this->readModel->findRestocker($targetId) : null;
        $targetIsActive = $target !== null && $target['is_active'];

        $reassignmentReason = $this->parseReassignmentReason($payload['reassignment_reason_code'] ?? null);
        $note = RestockRoutePlannerValidator::assertReassignmentRequest(
            $role,
            $serviceDate,
            $today,
            $targetId,
            $targetIsActive,
            null,
            $reassignmentReason,
            isset($payload['reason_text']) ? (string)$payload['reason_text'] : null,
            isset($payload['client_mutation_id']) ? (string)$payload['client_mutation_id'] : null
        );
        $mutationId = RestockRoutePlannerValidator::normalizeMutationId(
            isset($payload['client_mutation_id']) ? (string)$payload['client_mutation_id'] : null,
            false
        );

        $replay = $this->replayOffline($actorId, $mutationId);
        if ($replay !== null) {
            return $replay;
        }

        $machine = $this->requireMachine($machineId, $serviceDate);
        $this->assertStopAvailable($machine, $serviceDate);

        $restockersById = $this->indexRestockers($this->readModel->listRestockers($serviceDate));
        $currentOwnerId = $this->planner->resolveOwnerId(
            $machine,
            $restockersById,
            $serviceDate,
            $serviceDate > $today
        );

        // La validación de auto-desvío necesita el propietario efectivo ya resuelto.
        RestockRoutePlannerValidator::assertReassignmentRequest(
            $role,
            $serviceDate,
            $today,
            $targetId,
            $targetIsActive,
            $currentOwnerId,
            $reassignmentReason,
            $note,
            $mutationId
        );

        $hasFefoAlert = $this->planner->hasFefoAlert($machine, $serviceDate);
        $clientOccurredAt = isset($payload['client_occurred_at']) && trim((string)$payload['client_occurred_at']) !== ''
            ? (string)$payload['client_occurred_at']
            : $instant->format(DATE_ATOM);

        /** @var array<string, mixed> $result */
        $result = $this->transactions->runInTransaction(function () use (
            $machineId,
            $serviceDate,
            $targetId,
            $currentOwnerId,
            $reassignmentReason,
            $note,
            $hasFefoAlert,
            $mutationId,
            $clientOccurredAt,
            $actor,
            $actorId
        ): array {
            $previous = $this->gateway->findActiveOverride($machineId, $serviceDate);
            $previousId = $previous?->getId();
            if ($previous !== null) {
                $this->gateway->revokeActiveOverrides($machineId, $serviceDate, $actorId, $this->timestamp());
                $this->audit(
                    AuditEvent::ENTITY_RESTOCK_ROUTE_DAILY_OVERRIDE,
                    (int)$previousId,
                    self::ACTION_REVOKE,
                    $actor,
                    $previous->toArray(),
                    $previous->revoke($actorId, $this->timestamp())->toArray(),
                    ['machine_id' => $machineId, 'service_date' => $serviceDate, 'cause' => 'SUPERSEDED_BY_NEW_OVERRIDE']
                );
            }

            $override = new RestockRouteDailyOverride(
                null,
                $machineId,
                $serviceDate,
                RestockOverrideType::REASSIGNED,
                \VendGuard\Core\Domain\Enum\RestockOverrideStatus::ACTIVE,
                $currentOwnerId,
                $targetId,
                null,
                $reassignmentReason,
                $note,
                $hasFefoAlert,
                null,
                $mutationId,
                null,
                $actorId
            );

            $overrideId = $this->gateway->insertOverride($override);
            $persisted = $override->withId($overrideId);

            if ($mutationId !== null) {
                $this->gateway->recordOfflineMutation(
                    $actorId,
                    $mutationId,
                    $machineId,
                    $clientOccurredAt,
                    [
                        'override_id' => $overrideId,
                        'status' => 'ACTIVE',
                        'override_type' => 'REASSIGNED',
                        'machine_id' => $machineId,
                        'service_date' => $serviceDate,
                    ],
                    201
                );
            }

            $this->audit(
                AuditEvent::ENTITY_RESTOCK_ROUTE_DAILY_OVERRIDE,
                $overrideId,
                self::ACTION_REASSIGN,
                $actor,
                null,
                $persisted->toArray(),
                [
                    'machine_id' => $machineId,
                    'service_date' => $serviceDate,
                    'current_owner_id' => $currentOwnerId,
                    'target_restocker_id' => $targetId,
                    'revoked_previous_override_id' => $previousId,
                ]
            );

            return [
                'override' => array_merge($persisted->toArray(), [
                    'revoked_previous_override_id' => $previousId,
                ]),
                'idempotent_replay' => false,
            ];
        });

        return $result;
    }

    /**
     * Revoca una excepción viva (solo Coordinación).
     *
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array<string, mixed>
     */
    public function revoke(int $overrideId, array $actor, ?DateTimeImmutable $now = null): array
    {
        $role = strtoupper(trim((string)($actor['role'] ?? '')));
        if ($role !== RestockRoutePlannerValidator::ROLE_COORDINATOR) {
            throw RestockRoutePlannerValidationException::roleForbidden(
                $role,
                [RestockRoutePlannerValidator::ROLE_COORDINATOR]
            );
        }
        $actorId = $this->actorId($actor);

        $override = $this->readModel->findOverrideById($overrideId);
        if ($override === null) {
            throw RestockRoutePlannerValidationException::overrideNotFound($overrideId);
        }
        RestockRoutePlannerValidator::assertOverrideActive(
            $override->isActive(),
            $overrideId,
            $override->getStatus()->value
        );

        $machine = $this->readModel->loadMachineFacts($override->getMachineId(), $override->getServiceDate());
        if ($machine !== null) {
            RestockRoutePlannerValidator::assertStopNotCompletedToday(
                is_array($machine['visit_today'] ?? null),
                $override->getMachineId(),
                $override->getServiceDate()
            );
        }

        $resolvedAt = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))
            ->format('Y-m-d\TH:i:sP');
        $revoked = $override->revoke($actorId, $resolvedAt);

        $this->transactions->runInTransaction(function () use ($override, $revoked, $actorId, $resolvedAt, $actor): void {
            $this->gateway->revokeActiveOverrides(
                $override->getMachineId(),
                $override->getServiceDate(),
                $actorId,
                $resolvedAt
            );
            $this->audit(
                AuditEvent::ENTITY_RESTOCK_ROUTE_DAILY_OVERRIDE,
                (int)$override->getId(),
                self::ACTION_REVOKE,
                $actor,
                $override->toArray(),
                $revoked->toArray(),
                [
                    'machine_id' => $override->getMachineId(),
                    'service_date' => $override->getServiceDate(),
                    'cause' => 'MANUAL_REVOCATION',
                ]
            );
        });

        return [
            'override' => $revoked->toArray(),
            'revoked' => true,
        ];
    }

    /**
     * Archiva un conflicto de la cola offline sin reintentar la mutación.
     *
     * @param array<string, mixed> $payload
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array<string, mixed>
     */
    public function dumpOfflineConflict(array $payload, array $actor, ?DateTimeImmutable $now = null): array
    {
        $role = strtoupper(trim((string)($actor['role'] ?? '')));
        if (!in_array($role, [
            RestockRoutePlannerValidator::ROLE_RESTOCKER,
            RestockRoutePlannerValidator::ROLE_COORDINATOR,
        ], true)) {
            throw RestockRoutePlannerValidationException::roleForbidden(
                $role,
                [RestockRoutePlannerValidator::ROLE_RESTOCKER, RestockRoutePlannerValidator::ROLE_COORDINATOR]
            );
        }

        $machineId = (int)($payload['machine_id'] ?? 0);
        if ($machineId <= 0) {
            throw RestockRoutePlannerValidationException::machineNotFound($machineId);
        }

        $httpStatus = (int)($payload['http_status'] ?? 409);
        if (!in_array($httpStatus, [409, 422], true)) {
            $httpStatus = 409;
        }

        $overrideId = isset($payload['override_id']) ? (int)$payload['override_id'] : 0;
        $entityId = $overrideId > 0 ? $overrideId : $machineId;
        $instant = $now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid'));

        $archivedPayload = [
            'machine_id' => $machineId,
            'override_id' => $overrideId > 0 ? $overrideId : null,
            'http_status' => $httpStatus,
            'error_code' => isset($payload['error_code']) ? (string)$payload['error_code'] : 'OFFLINE_CONFLICT',
            'message' => isset($payload['message']) ? (string)$payload['message'] : null,
            'client_mutation_id' => isset($payload['client_mutation_id'])
                ? (string)$payload['client_mutation_id']
                : null,
            'client_occurred_at' => isset($payload['client_occurred_at'])
                ? (string)$payload['client_occurred_at']
                : null,
            'dumped_at' => $instant->format(DATE_ATOM),
        ];

        $this->audit(
            AuditEvent::ENTITY_RESTOCK_ROUTE_DAILY_OVERRIDE,
            $entityId,
            self::ACTION_CONFLICT_DUMP,
            $actor,
            null,
            $archivedPayload,
            ['source' => 'OFFLINE_QUEUE', 'entity_id_fallback' => $overrideId <= 0]
        );

        return [
            'archived' => true,
            'machine_id' => $machineId,
            'http_status' => $httpStatus,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function register(array $payload, array $actor, ?DateTimeImmutable $now = null): array
    {
        $type = strtoupper(trim((string)($payload['override_type'] ?? '')));

        if ($type === RestockOverrideType::SKIPPED->value) {
            return $this->skip((int)($payload['machine_id'] ?? 0), $payload, $actor, $now);
        }

        if ($type === RestockOverrideType::REASSIGNED->value) {
            return $this->reassign($payload, $actor, $now);
        }

        throw RestockRoutePlannerValidationException::reassignmentReasonInvalid(
            'override_type debe ser SKIPPED o REASSIGNED.'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function replayOffline(int $actorId, ?string $mutationId): ?array
    {
        if ($mutationId === null) {
            return null;
        }

        $existing = $this->gateway->findOfflineMutation($actorId, $mutationId);
        if ($existing !== null) {
            return [
                'idempotent_replay' => true,
                'http_status' => $existing['http_status'],
                'response' => $existing['payload'],
            ];
        }

        $override = $this->gateway->findOverrideByClientMutationId($mutationId);
        if ($override !== null) {
            return [
                'idempotent_replay' => true,
                'http_status' => 200,
                'override' => $override->toArray(),
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMachine(int $machineId, string $serviceDate): array
    {
        $machine = $this->readModel->loadMachineFacts($machineId, $serviceDate);
        if ($machine === null) {
            throw RestockRoutePlannerValidationException::machineNotFound($machineId);
        }
        if (!$machine['is_active'] || !$machine['location_active']) {
            throw RestockRoutePlannerValidationException::machineInactiveOrDeleted($machineId);
        }

        return $machine;
    }

    /**
     * @param array<string, mixed> $machine
     */
    private function assertStopAvailable(array $machine, string $serviceDate): void
    {
        RestockRoutePlannerValidator::assertStopNotCompletedToday(
            is_array($machine['visit_today'] ?? null),
            (int)$machine['id'],
            $serviceDate
        );

        $reasons = $this->planner->blockedReasons($machine, $serviceDate);
        RestockRoutePlannerValidator::assertStopNotBlocked($reasons !== [], (int)$machine['id'], $reasons);
    }

    private function parseSkipReason(mixed $value): ?RestockSkipReasonCode
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }

        try {
            return RestockSkipReasonCode::fromString((string)$value);
        } catch (InvalidArgumentException) {
            throw RestockRoutePlannerValidationException::skipReasonInvalid(
                'skip_reason_code debe pertenecer al enum #49.'
            );
        }
    }

    private function parseReassignmentReason(mixed $value): ?RestockReassignmentReasonCode
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }

        try {
            return RestockReassignmentReasonCode::fromString((string)$value);
        } catch (InvalidArgumentException) {
            throw RestockRoutePlannerValidationException::reassignmentReasonInvalid(
                'reassignment_reason_code debe pertenecer al enum #50.'
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $restockers
     *
     * @return array<int, array<string, mixed>>
     */
    private function indexRestockers(array $restockers): array
    {
        $indexed = [];
        foreach ($restockers as $restocker) {
            $indexed[(int)$restocker['id']] = $restocker;
        }

        return $indexed;
    }

    /**
     * @param array{id?: int|null, role?: string, name?: string} $actor
     */
    private function actorId(array $actor): int
    {
        $actorId = (int)($actor['id'] ?? 0);
        if ($actorId <= 0) {
            throw RestockRoutePlannerValidationException::roleForbidden(
                (string)($actor['role'] ?? ''),
                [RestockRoutePlannerValidator::ROLE_COORDINATOR, RestockRoutePlannerValidator::ROLE_RESTOCKER]
            );
        }

        return $actorId;
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d\TH:i:sP');
    }

    /**
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $new
     * @param array<string, mixed>|null $metadata
     */
    private function audit(
        string $entityType,
        int $entityId,
        string $action,
        array $actor,
        ?array $previous,
        array $new,
        ?array $metadata = null
    ): void {
        $this->auditLogger->logEvent(new AuditEvent(
            null,
            $entityType,
            $entityId,
            $action,
            isset($actor['id']) ? (int)$actor['id'] : null,
            (string)($actor['role'] ?? 'SYSTEM'),
            (string)($actor['name'] ?? 'Sistema'),
            $previous,
            $new,
            $metadata
        ));
    }
}
