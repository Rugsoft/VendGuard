<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use VendGuard\Core\Domain\Enum\RestockCadenceType;
use VendGuard\Core\Domain\Enum\RestockTimeWindow;
use VendGuard\Core\Domain\Exception\RestockRoutePlannerValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\MachineRestockSchedule;
use VendGuard\Core\Domain\Repository\RestockRoutePlannerGatewayInterface;
use VendGuard\Core\Domain\Repository\RestockRouteReadModelInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\ValueObject\SlaTier;
use VendGuard\Core\Service\RestockCadenceEvaluator;
use VendGuard\Core\Service\RestockRoutePlannerValidator;

/**
 * RestockRouteScheduleService
 *
 * Casos de uso de configuración de agendas periódicas de reposición (Módulo 37;
 * RF-01.1, RF-01.3, RF-05.1, QA-2, QA-6, Art. III.1 y Art. III.3).
 *
 * Reglas que el servicio hace cumplir:
 *
 *   1. **Solo Coordinación configura.** Cualquier otro rol recibe `403` con el
 *      código del contrato, antes de tocar la base.
 *   2. **Versionado inmutable.** Guardar cierra la versión vigente e inserta una
 *      nueva dentro de la misma transacción; la fila cerrada no se reescribe ni
 *      se borra (cero `DELETE FROM`, `prevent_hard_delete()`).
 *   3. **Alta masiva atómica.** O se aplican todas las agendas del lote o
 *      ninguna; `overwrite_existing = FALSE` conserva las que ya tenían agenda y
 *      las cuenta como `skipped_count`.
 *   4. **Máquina operativa.** Una máquina inexistente, eliminada, inactiva o de
 *      sede inactiva no recibe agenda (`422`/`404`).
 *   5. **Auditoría propia.** Cada versión y cada baja firman
 *      `MACHINE_RESTOCK_SCHEDULE` en `audit_log`; nunca se confunde con la
 *      máquina ni con la visita.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockRouteScheduleService
{
    public const ACTION_UPSERT = 'UPSERT_RESTOCK_SCHEDULE';
    public const ACTION_DEACTIVATE = 'DEACTIVATE_RESTOCK_SCHEDULE';
    public const ACTION_BULK = 'BULK_RESTOCK_SCHEDULE';

    private const MAX_PAGE_SIZE = 200;

    private RestockRoutePlannerGatewayInterface $gateway;
    private RestockRouteReadModelInterface $readModel;
    private AuditLogger $auditLogger;
    private TransactionManagerInterface $transactions;

    public function __construct(
        RestockRoutePlannerGatewayInterface $gateway,
        RestockRouteReadModelInterface $readModel,
        AuditLogger $auditLogger,
        TransactionManagerInterface $transactions
    ) {
        $this->gateway = $gateway;
        $this->readModel = $readModel;
        $this->auditLogger = $auditLogger;
        $this->transactions = $transactions;
    }

    /**
     * Catálogo paginado de agendas con su próxima parada calculada.
     *
     * @param array{
     *     machine_id?: int|null,
     *     zone_id?: int|null,
     *     machine_type?: string|null,
     *     cadence_type?: string|null,
     *     search?: string|null,
     *     include_inactive?: bool
     * } $filters
     *
     * @return array<string, mixed>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0, ?DateTimeImmutable $now = null): array
    {
        $today = RestockCadenceEvaluator::today($now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')));
        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
        $offset = max(0, $offset);

        $snapshot = $this->readModel->loadParkSnapshot($today);
        $includeInactive = (bool)($filters['include_inactive'] ?? false);
        $machineId = isset($filters['machine_id']) && $filters['machine_id'] !== null
            ? (int)$filters['machine_id']
            : null;
        $zoneId = isset($filters['zone_id']) && $filters['zone_id'] !== null
            ? (int)$filters['zone_id']
            : null;
        $machineType = isset($filters['machine_type']) && $filters['machine_type'] !== null
            ? strtoupper(trim((string)$filters['machine_type']))
            : null;
        $cadenceType = isset($filters['cadence_type']) && $filters['cadence_type'] !== null
            ? strtoupper(trim((string)$filters['cadence_type']))
            : null;
        $search = isset($filters['search']) && $filters['search'] !== null
            ? mb_strtolower(trim((string)$filters['search']), 'UTF-8')
            : null;

        $items = [];
        foreach ($snapshot['machines'] as $machine) {
            if ($machineId !== null && (int)$machine['id'] !== $machineId) {
                continue;
            }
            if ($zoneId !== null && (int)($machine['zone_id'] ?? 0) !== $zoneId) {
                continue;
            }
            if ($machineType !== null && (string)$machine['machine_type'] !== $machineType) {
                continue;
            }

            $schedule = $machine['schedule'] ?? null;
            if (!is_array($schedule)) {
                continue;
            }
            if (!$includeInactive && ($schedule['is_active'] ?? true) !== true) {
                continue;
            }
            if ($cadenceType !== null && (string)$schedule['cadence_type'] !== $cadenceType) {
                continue;
            }

            if ($search !== null) {
                $haystack = mb_strtolower(
                    $machine['machine_code'] . ' ' . $machine['location_name'] . ' ' . $machine['site_code'],
                    'UTF-8'
                );
                if (!str_contains($haystack, $search)) {
                    continue;
                }
            }

            $entity = MachineRestockSchedule::fromDatabaseRow($schedule);
            $items[] = [
                'machine_id' => (int)$machine['id'],
                'machine_code' => (string)$machine['machine_code'],
                'machine_type' => (string)$machine['machine_type'],
                'location_id' => (int)$machine['location_id'],
                'location_name' => (string)$machine['location_name'],
                'zone_id' => $machine['zone_id'],
                'zone_name' => $machine['zone_name'],
                'schedule' => $entity->toArray(),
                'next_scheduled_date' => $this->nextScheduledDate($entity, $today, $machine),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['machine_code'] <=> $b['machine_code']);
        $total = count($items);
        $page = array_slice($items, $offset, $limit);

        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'items' => $page,
        ];
    }

    /**
     * Alta o versionado individual de la agenda de una máquina.
     *
     * @param array<string, mixed> $payload
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array<string, mixed>
     */
    public function save(int $machineId, array $payload, array $actor, ?DateTimeImmutable $now = null): array
    {
        $this->assertCoordinator($actor);
        $today = RestockCadenceEvaluator::today($now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')));
        $actorId = $this->actorId($actor);

        $cadence = RestockCadenceType::fromString((string)($payload['cadence_type'] ?? 'DAILY'));
        $dayOfWeek = isset($payload['day_of_week']) && $payload['day_of_week'] !== null
            ? (int)$payload['day_of_week']
            : null;
        $anchorDate = isset($payload['anchor_date']) && $payload['anchor_date'] !== null
            ? trim((string)$payload['anchor_date'])
            : null;
        $timeWindow = RestockTimeWindow::fromString((string)($payload['preferred_time_window'] ?? 'ANY'));
        $includeWeekends = (bool)($payload['include_weekends'] ?? false);
        $notes = isset($payload['notes']) && $payload['notes'] !== null ? trim((string)$payload['notes']) : null;

        RestockRoutePlannerValidator::assertScheduleCadence($cadence, $dayOfWeek, $anchorDate, $includeWeekends);

        $machine = $this->gateway->lockMachine($machineId);
        if ($machine === null) {
            throw RestockRoutePlannerValidationException::machineNotFound($machineId);
        }
        if (!$machine['is_active']) {
            throw RestockRoutePlannerValidationException::machineInactiveOrDeleted($machineId);
        }

        $previous = $this->gateway->findActiveSchedule($machineId);

        $schedule = new MachineRestockSchedule(
            null,
            $machineId,
            $cadence,
            $dayOfWeek,
            $anchorDate,
            $timeWindow,
            $includeWeekends,
            $notes,
            true,
            $actorId,
            $actorId
        );

        $saved = $this->transactions->runInTransaction(function () use ($schedule, $previous, $actor, $actorId): MachineRestockSchedule {
            $newId = $this->gateway->saveScheduleVersion($schedule);
            $persisted = $schedule->withId($newId);

            $this->audit(
                AuditEvent::ENTITY_MACHINE_RESTOCK_SCHEDULE,
                $newId,
                self::ACTION_UPSERT,
                $actor,
                $previous?->toArray(),
                $persisted->toArray(),
                [
                    'previous_version_id' => $previous?->getId(),
                    'machine_id' => $persisted->getMachineId(),
                    'actor_id' => $actorId,
                ]
            );

            return $persisted;
        });

        return [
            'schedule' => array_merge($saved->toArray(), [
                'previous_version_id' => $previous?->getId(),
                'next_scheduled_date' => $this->nextScheduledDate($saved, $today, $machine),
            ]),
        ];
    }

    /**
     * Baja lógica de una versión vigente.
     *
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array<string, mixed>
     */
    public function deactivate(int $scheduleId, array $actor, ?DateTimeImmutable $now = null): array
    {
        $this->assertCoordinator($actor);
        $actorId = $this->actorId($actor);
        $instant = $now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid'));
        $deactivatedAt = $instant->format('Y-m-d\TH:i:sP');

        $schedule = $this->readModel->findScheduleById($scheduleId);
        if ($schedule === null || !$schedule->isActive()) {
            throw RestockRoutePlannerValidationException::scheduleNotFound($scheduleId);
        }

        $closed = $schedule->deactivate($actorId, $deactivatedAt);

        $this->transactions->runInTransaction(function () use ($schedule, $scheduleId, $actorId, $deactivatedAt, $closed, $actor): void {
            $this->gateway->deactivateSchedule($scheduleId, $actorId, $deactivatedAt);
            $this->audit(
                AuditEvent::ENTITY_MACHINE_RESTOCK_SCHEDULE,
                $scheduleId,
                self::ACTION_DEACTIVATE,
                $actor,
                $schedule->toArray(),
                $closed->toArray(),
                ['machine_id' => $closed->getMachineId(), 'actor_id' => $actorId]
            );
        });

        return [
            'schedule_id' => $scheduleId,
            'machine_id' => $schedule->getMachineId(),
            'deactivated' => true,
            'deactivated_at' => $deactivatedAt,
        ];
    }

    /**
     * Configuración masiva atómica por zona, tipo de máquina o lista explícita.
     *
     * @param array<string, mixed> $payload
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array<string, mixed>
     */
    public function bulk(array $payload, array $actor, ?DateTimeImmutable $now = null): array
    {
        $this->assertCoordinator($actor);
        $today = RestockCadenceEvaluator::today($now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')));
        $actorId = $this->actorId($actor);

        $cadence = RestockCadenceType::fromString((string)($payload['cadence_type'] ?? 'DAILY'));
        $dayOfWeek = isset($payload['day_of_week']) && $payload['day_of_week'] !== null
            ? (int)$payload['day_of_week']
            : null;
        $anchorDate = isset($payload['anchor_date']) && $payload['anchor_date'] !== null
            ? trim((string)$payload['anchor_date'])
            : null;
        $timeWindow = RestockTimeWindow::fromString((string)($payload['preferred_time_window'] ?? 'ANY'));
        $includeWeekends = (bool)($payload['include_weekends'] ?? false);
        $notes = isset($payload['notes']) && $payload['notes'] !== null ? trim((string)$payload['notes']) : null;
        $overwrite = (bool)($payload['overwrite_existing'] ?? false);

        RestockRoutePlannerValidator::assertScheduleCadence($cadence, $dayOfWeek, $anchorDate, $includeWeekends);

        $targets = $this->resolveBulkTargets($payload, $today);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $machineIds = [];

        $this->transactions->runInTransaction(function () use (
            $targets,
            $cadence,
            $dayOfWeek,
            $anchorDate,
            $timeWindow,
            $includeWeekends,
            $notes,
            $overwrite,
            $actor,
            $actorId,
            &$created,
            &$updated,
            &$skipped,
            &$machineIds
        ): void {
            foreach ($targets as $machine) {
                $machineId = (int)$machine['id'];
                $previous = $this->gateway->findActiveSchedule($machineId);

                if ($previous !== null && !$overwrite) {
                    $skipped++;
                    continue;
                }

                $schedule = new MachineRestockSchedule(
                    null,
                    $machineId,
                    $cadence,
                    $dayOfWeek,
                    $anchorDate,
                    $timeWindow,
                    $includeWeekends,
                    $notes,
                    true,
                    $actorId,
                    $actorId
                );

                $newId = $this->gateway->saveScheduleVersion($schedule);
                $persisted = $schedule->withId($newId);
                $machineIds[] = $machineId;

                if ($previous !== null) {
                    $updated++;
                } else {
                    $created++;
                }

                $this->audit(
                    AuditEvent::ENTITY_MACHINE_RESTOCK_SCHEDULE,
                    $newId,
                    self::ACTION_BULK,
                    $actor,
                    $previous?->toArray(),
                    $persisted->toArray(),
                    [
                        'previous_version_id' => $previous?->getId(),
                        'machine_id' => $machineId,
                        'zone_id' => $machine['zone_id'],
                        'machine_type' => $machine['machine_type'],
                        'overwrite_existing' => $overwrite,
                    ]
                );
            }
        });

        return [
            'matched_count' => count($targets),
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'failed_count' => 0,
            'machine_ids' => $machineIds,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function resolveBulkTargets(array $payload, string $today): array
    {
        $explicitIds = $payload['machine_ids'] ?? null;
        if (is_array($explicitIds) && $explicitIds !== []) {
            $targets = [];
            $seen = [];
            foreach ($explicitIds as $rawId) {
                $machineId = (int)$rawId;
                if ($machineId <= 0 || isset($seen[$machineId])) {
                    continue;
                }
                $seen[$machineId] = true;

                $machine = $this->readModel->loadMachineFacts($machineId, $today);
                if ($machine === null) {
                    throw RestockRoutePlannerValidationException::machineNotFound($machineId);
                }
                if (!$machine['is_active'] || !$machine['location_active']) {
                    throw RestockRoutePlannerValidationException::machineInactiveOrDeleted($machineId);
                }
                $targets[] = $machine;
            }

            return $targets;
        }

        $zoneId = isset($payload['zone_id']) && $payload['zone_id'] !== null
            ? (int)$payload['zone_id']
            : null;
        $machineType = isset($payload['machine_type']) && $payload['machine_type'] !== null
            ? strtoupper(trim((string)$payload['machine_type']))
            : null;

        $snapshot = $this->readModel->loadParkSnapshot($today);
        $targets = [];
        foreach ($snapshot['machines'] as $machine) {
            if (!$machine['is_active'] || !$machine['location_active']) {
                continue;
            }
            if ($zoneId !== null && (int)($machine['zone_id'] ?? 0) !== $zoneId) {
                continue;
            }
            if ($machineType !== null && (string)$machine['machine_type'] !== $machineType) {
                continue;
            }
            $targets[] = $machine;
        }

        return $targets;
    }

    /**
     * @param array<string, mixed> $machine
     */
    private function nextScheduledDate(MachineRestockSchedule $schedule, string $today, array $machine): ?string
    {
        return RestockCadenceEvaluator::nextScheduledDate(
            $schedule,
            $today,
            SlaTier::fromString((string)$machine['sla_tier']),
            RestockCadenceEvaluator::isSeasonalPauseActive(
                (bool)$machine['is_seasonal_pause'],
                $machine['seasonal_pause_until'] !== null ? (string)$machine['seasonal_pause_until'] : null,
                $today
            )
        );
    }

    /**
     * @param array{id?: int|null, role?: string, name?: string} $actor
     */
    private function assertCoordinator(array $actor): void
    {
        $role = strtoupper(trim((string)($actor['role'] ?? '')));
        if ($role !== RestockRoutePlannerValidator::ROLE_COORDINATOR) {
            throw RestockRoutePlannerValidationException::roleForbidden(
                $role,
                [RestockRoutePlannerValidator::ROLE_COORDINATOR]
            );
        }
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
                [RestockRoutePlannerValidator::ROLE_COORDINATOR]
            );
        }

        return $actorId;
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
