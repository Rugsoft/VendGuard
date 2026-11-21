<?php

declare(strict_types=1);

/**
 * RestockRoutePlannerServicesTest · T-RRP-07
 *
 * Certifica los tres servicios de aplicación del Módulo 37 con dobles en
 * memoria: `RestockRouteScheduleService`, `RestockRoutePlannerService` y
 * `RestockRouteOverrideService`.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Agendas versionadas (RF-01.1, QA-2, Art. III.3).** Guardar cierra la
 *      versión vigente y crea otra; la anterior conserva su fila. La cadencia
 *      incoherente y el rol ajeno se rechazan antes de escribir.
 *   2. **Alta masiva atómica (RF-01.3).** `overwrite_existing = FALSE` conserva
 *      las agendas existentes y las cuenta como omitidas; el lote se ejecuta en
 *      una única unidad de trabajo.
 *   3. **Composición diaria (RF-03.1, D1–D7, QA-6).** La propiedad efectiva
 *      respeta `REASSIGNED` > L1 > zona; la proyección futura no exige turno
 *      abierto; la foto se acota a un `service_date` (Art. VI).
 *   4. **FEFO solo frío (Art. II).** Solo los lotes refrigerados disparan el
 *      Bloque 1; una máquina sin FEFO cae en demanda o cadencia.
 *   5. **Omisión rigurosa (Art. V.1).** `reason_text` de 20..500 caracteres y
 *      ≥ 3 bloques alfanuméricos; el reponedor solo omite su parada; la visita
 *      cerrada y la máquina segregada se rechazan.
 *   6. **Salvaguarda FEFO y sello EXPIRED (Art. II).** Omitir con FEFO encola el
 *      aviso interno y vincula la excepción; el lote vencido sella `EXPIRED` sin
 *      bloquear el flujo del Módulo 34.
 *   7. **Idempotencia offline (C3).** El mismo `client_mutation_id` devuelve la
 *      respuesta original sin duplicar la excepción ni la mutación.
 *   8. **Opacidad (Art. V.4).** La hoja del reponedor no publica `reason_text`
 *      internos, costes ni notas ajenas; Coordinación sí conserva el motivo.
 *
 * CATEGORÍA: hermética (`tests/unit/`). No toca la base de datos ni el reloj.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\RestockRouteOverrideService;
use VendGuard\Application\Service\RestockRoutePlannerService;
use VendGuard\Application\Service\RestockRouteScheduleService;
use VendGuard\Core\Domain\Exception\RestockRoutePlannerValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\MachineRestockSchedule;
use VendGuard\Core\Domain\Model\RestockRouteDailyOverride;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\RestockRoutePlannerGatewayInterface;
use VendGuard\Core\Domain\Repository\RestockRouteReadModelInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Application\Service\GeoDistanceService;
use VendGuard\Core\Service\RestockRouteOptimizer;

$assertions = 0;

function assertRrpCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertRrpSame(mixed $expected, mixed $actual, string $message): void
{
    assertRrpCondition($expected === $actual, $message . ' (esperado: ' . var_export($expected, true) . ', real: ' . var_export($actual, true) . ')');
}

/**
 * @param callable(): void $operation
 */
function assertRrpThrows(callable $operation, string $expectedCode, string $message): void
{
    global $assertions;
    $assertions++;

    try {
        $operation();
    } catch (RestockRoutePlannerValidationException $exception) {
        if ($exception->getErrorCode() !== $expectedCode) {
            throw new RuntimeException($message . ' (código real: ' . $exception->getErrorCode() . ')');
        }

        return;
    }

    throw new RuntimeException($message . ' (no lanzó excepción)');
}

/**
 * @return array<string, mixed>
 */
function rrpMachine(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'machine_code' => sprintf('VEND-%03d', $id),
        'machine_type' => 'PERISHABLE_FOOD',
        'location_id' => 10,
        'location_name' => 'Hospital Central',
        'address' => 'Av. Diagonal 450',
        'site_code' => 'SITE-10',
        'zone_id' => 1,
        'zone_name' => 'Zona Norte',
        'zone_active' => true,
        'sla_tier' => 'STANDARD',
        'latitude' => 41.4036299,
        'longitude' => 2.1743558,
        'sanitary_status' => 'OK',
        'is_blocked_no_access' => false,
        'is_seasonal_pause' => false,
        'seasonal_pause_until' => null,
        'is_active' => true,
        'location_active' => true,
        'schedule' => null,
        'earliest_fefo_expiration_date' => null,
        'has_expired_cold_batch' => false,
        'empty_slots_count' => 0,
        'active_l1_incident' => null,
        'has_open_thermal_breakdown' => false,
        'visit_today' => null,
        'active_override' => null,
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function rrpScheduleRow(int $machineId, array $overrides = []): array
{
    return array_merge([
        'id' => 100 + $machineId,
        'machine_id' => $machineId,
        'cadence_type' => 'DAILY',
        'day_of_week' => null,
        'anchor_date' => null,
        'preferred_time_window' => 'MORNING',
        'include_weekends' => true,
        'notes' => null,
        'is_active' => true,
        'created_by' => 99,
        'updated_by' => 99,
        'deactivated_by' => null,
        'created_at' => '2026-11-01T09:00:00+01:00',
        'updated_at' => '2026-11-01T09:00:00+01:00',
        'deactivated_at' => null,
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function rrpRestocker(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'name' => 'Reponedor ' . $id,
        'operator_code' => 'REP-' . $id,
        'is_active' => true,
        'zone_ids' => [1],
        'is_primary' => true,
        'shift_status' => 'ON_SHIFT',
        'has_absence' => false,
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function rrpActor(int $id, string $role): array
{
    return ['id' => $id, 'role' => $role, 'name' => $role . ' ' . $id];
}

final class FakeRestockRouteGateway implements RestockRoutePlannerGatewayInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $machines = [];

    /** @var array<int, MachineRestockSchedule> */
    public array $schedules = [];

    /** @var array<int, RestockRouteDailyOverride> */
    public array $overrides = [];

    /** @var array<string, array{id: int, http_status: int, payload: array<string, mixed>}> */
    public array $offlineMutations = [];

    /** @var array<int, array<string, mixed>> */
    public array $notifications = [];

    public int $nextScheduleId = 1;
    public int $nextOverrideId = 1;
    public int $nextNotificationId = 1;
    public int $nextMutationId = 1;

    public function lockMachine(int $machineId): ?array
    {
        return $this->machines[$machineId] ?? null;
    }

    public function findActiveSchedule(int $machineId): ?MachineRestockSchedule
    {
        foreach ($this->schedules as $schedule) {
            if ($schedule->getMachineId() === $machineId && $schedule->isActive()) {
                return $schedule;
            }
        }

        return null;
    }

    public function listActiveSchedules(): array
    {
        $active = [];
        foreach ($this->schedules as $schedule) {
            if ($schedule->isActive()) {
                $active[$schedule->getMachineId()] = $schedule;
            }
        }

        return $active;
    }

    public function saveScheduleVersion(MachineRestockSchedule $schedule): int
    {
        foreach ($this->schedules as $id => $existing) {
            if ($existing->getMachineId() === $schedule->getMachineId() && $existing->isActive()) {
                $this->schedules[$id] = $existing->deactivate(
                    $schedule->getUpdatedBy(),
                    '2026-11-21T10:00:00+01:00'
                );
            }
        }

        $id = $this->nextScheduleId++;
        $this->schedules[$id] = $schedule->withId($id);

        return $id;
    }

    public function deactivateSchedule(int $scheduleId, int $actorId, string $deactivatedAt): bool
    {
        if (!isset($this->schedules[$scheduleId]) || !$this->schedules[$scheduleId]->isActive()) {
            return false;
        }

        $this->schedules[$scheduleId] = $this->schedules[$scheduleId]->deactivate($actorId, $deactivatedAt);

        return true;
    }

    public function findActiveOverride(int $machineId, string $serviceDate): ?RestockRouteDailyOverride
    {
        foreach ($this->overrides as $override) {
            if ($override->getMachineId() === $machineId
                && $override->getServiceDate() === $serviceDate
                && $override->isActive()) {
                return $override;
            }
        }

        return null;
    }

    public function findOverrideByClientMutationId(string $clientMutationId): ?RestockRouteDailyOverride
    {
        foreach ($this->overrides as $override) {
            if ($override->getClientMutationId() === $clientMutationId) {
                return $override;
            }
        }

        return null;
    }

    public function insertOverride(RestockRouteDailyOverride $override): int
    {
        $id = $this->nextOverrideId++;
        $this->overrides[$id] = $override->withId($id);

        return $id;
    }

    public function revokeActiveOverrides(
        int $machineId,
        string $serviceDate,
        int $resolverId,
        string $resolvedAt
    ): int {
        $count = 0;
        foreach ($this->overrides as $id => $override) {
            if ($override->getMachineId() === $machineId
                && $override->getServiceDate() === $serviceDate
                && $override->isActive()) {
                $this->overrides[$id] = $override->revoke($resolverId, $resolvedAt);
                $count++;
            }
        }

        return $count;
    }

    public function supersedeSkippedOverrides(
        int $machineId,
        string $serviceDate,
        int $visitId,
        ?int $resolverId,
        string $resolvedAt
    ): int {
        $count = 0;
        foreach ($this->overrides as $id => $override) {
            if ($override->getMachineId() === $machineId
                && $override->getServiceDate() === $serviceDate
                && $override->isActive()
                && $override->isSkipped()) {
                $this->overrides[$id] = $override->supersede($visitId, $resolverId, $resolvedAt);
                $count++;
            }
        }

        return $count;
    }

    public function markMachineSanitaryExpired(int $machineId): bool
    {
        if (!isset($this->machines[$machineId])) {
            return false;
        }

        $previous = (string)($this->machines[$machineId]['sanitary_status'] ?? 'OK');
        $this->machines[$machineId]['sanitary_status'] = 'EXPIRED';

        return $previous !== 'EXPIRED';
    }

    public function restoreMachineSanitaryOkIfClear(int $machineId, string $serviceDate): bool
    {
        if (($this->machines[$machineId]['sanitary_status'] ?? null) !== 'EXPIRED') {
            return false;
        }

        $this->machines[$machineId]['sanitary_status'] = 'OK';

        return true;
    }

    public function findActiveReassignmentsForRestocker(int $restockerId, string $serviceDate): array
    {
        $matches = [];
        foreach ($this->overrides as $override) {
            if ($override->getTargetRestockerId() !== $restockerId
                || $override->getServiceDate() !== $serviceDate
                || $override->getStatus()->value !== 'ACTIVE'
                || $override->getOverrideType()->value !== 'REASSIGNED') {
                continue;
            }

            $matches[] = $override;
        }

        return $matches;
    }

    public function enqueueFefoSkipAlert(
        int $machineId,
        string $serviceDate,
        int $overrideId,
        string $recipient,
        string $channel = 'EMAIL',
        array $extraPayload = []
    ): int {
        $id = $this->nextNotificationId++;
        $this->notifications[$id] = [
            'machine_id' => $machineId,
            'service_date' => $serviceDate,
            'override_id' => $overrideId,
            'recipient' => $recipient,
            'channel' => $channel,
            'payload' => $extraPayload,
        ];

        return $id;
    }

    public function attachNotificationToOverride(int $overrideId, int $notificationId): bool
    {
        if (!isset($this->overrides[$overrideId])) {
            return false;
        }

        $this->overrides[$overrideId] = $this->overrides[$overrideId]->withNotification($notificationId);

        return true;
    }

    public function findOfflineMutation(int $restockerId, string $clientMutationId): ?array
    {
        return $this->offlineMutations[$restockerId . ':' . $clientMutationId] ?? null;
    }

    public function recordOfflineMutation(
        int $restockerId,
        string $clientMutationId,
        int $machineId,
        string $clientOccurredAt,
        array $responsePayload,
        int $httpStatus = 200
    ): int {
        $key = $restockerId . ':' . $clientMutationId;
        if (isset($this->offlineMutations[$key])) {
            return $this->offlineMutations[$key]['id'];
        }

        $id = $this->nextMutationId++;
        $this->offlineMutations[$key] = [
            'id' => $id,
            'http_status' => $httpStatus,
            'payload' => $responsePayload,
        ];

        return $id;
    }
}

final class FakeRestockRouteReadModel implements RestockRouteReadModelInterface
{
    /** @var list<array<string, mixed>> */
    public array $machines = [];

    /** @var list<array<string, mixed>> */
    public array $restockers = [];

    /** @var array<int, MachineRestockSchedule> */
    public array $schedules = [];

    /** @var array<int, RestockRouteDailyOverride> */
    public array $overrides = [];

    public ?FakeRestockRouteGateway $gateway = null;

    public function loadParkSnapshot(string $serviceDate): array
    {
        $overrides = [];
        if ($this->gateway !== null) {
            foreach ($this->gateway->overrides as $override) {
                if ($override->isActive() && $override->getServiceDate() === $serviceDate) {
                    $overrides[] = $override->toArray();
                }
            }
        } else {
            foreach ($this->overrides as $override) {
                if ($override->isActive() && $override->getServiceDate() === $serviceDate) {
                    $overrides[] = $override->toArray();
                }
            }
        }

        return [
            'machines' => $this->machines,
            'restockers' => $this->restockers,
            'overrides' => $overrides,
        ];
    }

    public function loadMachineFacts(int $machineId, string $serviceDate): ?array
    {
        foreach ($this->machines as $machine) {
            if ((int)$machine['id'] === $machineId) {
                return $machine;
            }
        }

        return null;
    }

    public function listRestockers(string $serviceDate): array
    {
        return $this->restockers;
    }

    public function findRestocker(int $restockerId): ?array
    {
        foreach ($this->restockers as $restocker) {
            if ((int)$restocker['id'] === $restockerId) {
                return $restocker;
            }
        }

        return null;
    }

    public function findScheduleById(int $scheduleId): ?MachineRestockSchedule
    {
        if ($this->gateway !== null && isset($this->gateway->schedules[$scheduleId])) {
            return $this->gateway->schedules[$scheduleId];
        }

        return $this->schedules[$scheduleId] ?? null;
    }

    public function findOverrideById(int $overrideId): ?RestockRouteDailyOverride
    {
        if ($this->gateway !== null && isset($this->gateway->overrides[$overrideId])) {
            return $this->gateway->overrides[$overrideId];
        }

        return $this->overrides[$overrideId] ?? null;
    }
}

final class FakeAuditLogRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

        return $event;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return [];
    }

    public function countEvents(array $filters = []): int
    {
        return count($this->events);
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (AuditEvent $event): bool => $event->getEntityType() === $entityType
                && $event->getEntityId() === $entityId
        ));
    }
}

final class FakeRestockTransactionManager implements TransactionManagerInterface
{
    public int $calls = 0;

    public function runInTransaction(callable $operation): mixed
    {
        $this->calls++;

        return $operation();
    }
}

/**
 * @return array{gateway: FakeRestockRouteGateway, readModel: FakeRestockRouteReadModel, audit: FakeAuditLogRepository, transactions: FakeRestockTransactionManager}
 */
function rrpHarness(): array
{
    return [
        'gateway' => new FakeRestockRouteGateway(),
        'readModel' => new FakeRestockRouteReadModel(),
        'audit' => new FakeAuditLogRepository(),
        'transactions' => new FakeRestockTransactionManager(),
    ];
}

try {
    echo "[1/9] Auditoría y versionado de agendas\n";

    assertRrpCondition(
        AuditEvent::isValidEntityType(AuditEvent::ENTITY_MACHINE_RESTOCK_SCHEDULE),
        'MACHINE_RESTOCK_SCHEDULE debe estar en la whitelist de auditoría'
    );
    assertRrpCondition(
        AuditEvent::isValidEntityType(AuditEvent::ENTITY_RESTOCK_ROUTE_DAILY_OVERRIDE),
        'RESTOCK_ROUTE_DAILY_OVERRIDE debe estar en la whitelist de auditoría'
    );

    $harness = rrpHarness();
    $harness['gateway']->machines[1] = rrpMachine(1);
    $harness['readModel']->gateway = $harness['gateway'];

    $scheduleService = new RestockRouteScheduleService(
        $harness['gateway'],
        $harness['readModel'],
        new AuditLogger($harness['audit']),
        $harness['transactions']
    );

    $coordinator = rrpActor(99, 'COORDINATOR');
    $now = new DateTimeImmutable('2026-11-21T09:00:00+01:00');

    $first = $scheduleService->save(1, [
        'cadence_type' => 'WEEKLY',
        'day_of_week' => 3,
        'preferred_time_window' => 'MORNING',
        'notes' => 'Acceso por muelle de carga antes de las 11:30.',
    ], $coordinator, $now);

    assertRrpSame(1, $first['schedule']['id'], 'La primera agenda recibe el identificador 1');
    assertRrpSame(null, $first['schedule']['previous_version_id'], 'La primera versión no tiene versión previa');
    assertRrpSame('2026-11-25', $first['schedule']['next_scheduled_date'], 'La próxima parada semanal es el miércoles siguiente');

    $second = $scheduleService->save(1, [
        'cadence_type' => 'WEEKLY',
        'day_of_week' => 3,
        'preferred_time_window' => 'MORNING',
        'notes' => 'Acceso por muelle de carga antes de las 11:30.',
    ], $coordinator, $now);

    assertRrpSame(2, $second['schedule']['id'], 'La segunda edición crea la versión 2');
    assertRrpSame(1, $second['schedule']['previous_version_id'], 'La versión 2 declara a la 1 como previa');
    assertRrpCondition(
        $harness['gateway']->schedules[1]->isActive() === false
            && $harness['gateway']->schedules[2]->isActive() === true,
        'El versionado cierra la versión anterior sin borrarla'
    );
    assertRrpSame(2, count($harness['audit']->events), 'Cada versión firma su evento de auditoría');
    assertRrpSame(
        AuditEvent::ENTITY_MACHINE_RESTOCK_SCHEDULE,
        $harness['audit']->events[0]->getEntityType(),
        'La auditoría usa la entidad de agenda, no la máquina'
    );

    assertRrpThrows(
        static fn () => $scheduleService->save(1, [
            'cadence_type' => 'WEEKLY',
            'day_of_week' => null,
        ], $coordinator, $now),
        RestockRoutePlannerValidationException::SCHEDULE_CADENCE_FIELDS_INVALID,
        'WEEKLY sin day_of_week se rechaza'
    );

    assertRrpThrows(
        static fn () => $scheduleService->save(1, [
            'cadence_type' => 'DAILY',
            'include_weekends' => true,
        ], rrpActor(7, 'RESTOCKER'), $now),
        RestockRoutePlannerValidationException::FORBIDDEN,
        'Un reponedor no configura agendas'
    );

    $deactivated = $scheduleService->deactivate(2, $coordinator, $now);
    assertRrpCondition($deactivated['deactivated'] === true, 'La baja lógica responde con deactivated=true');
    assertRrpCondition($harness['gateway']->schedules[2]->isActive() === false, 'La versión 2 queda cerrada');
    assertRrpThrows(
        static fn () => $scheduleService->deactivate(2, $coordinator, $now),
        RestockRoutePlannerValidationException::SCHEDULE_NOT_FOUND,
        'Una agenda ya cerrada no se desactiva dos veces'
    );

    echo "  [OK]   Versionado inmutable, cadencia, rol y baja lógica\n";

    echo "[2/9] Alta masiva atómica\n";

    $harness = rrpHarness();
    $harness['gateway']->machines[1] = rrpMachine(1);
    $harness['gateway']->machines[2] = rrpMachine(2);
    $harness['gateway']->saveScheduleVersion(new MachineRestockSchedule(
        null,
        1,
        VendGuard\Core\Domain\Enum\RestockCadenceType::WEEKLY,
        3,
        null,
        VendGuard\Core\Domain\Enum\RestockTimeWindow::MORNING,
        false,
        null,
        true,
        99,
        99
    ));
    $harness['readModel']->machines = [
        rrpMachine(1),
        rrpMachine(2),
    ];

    $scheduleService = new RestockRouteScheduleService(
        $harness['gateway'],
        $harness['readModel'],
        new AuditLogger($harness['audit']),
        $harness['transactions']
    );

    $bulk = $scheduleService->bulk([
        'zone_id' => 1,
        'cadence_type' => 'DAILY',
        'include_weekends' => true,
        'overwrite_existing' => false,
    ], $coordinator, $now);

    assertRrpSame(2, $bulk['matched_count'], 'El lote alcanza las dos máquinas de la zona');
    assertRrpSame(1, $bulk['created_count'], 'Solo se crea la agenda de la máquina sin configuración');
    assertRrpSame(1, $bulk['skipped_count'], 'La agenda existente se conserva y se cuenta como omitida');
    assertRrpSame(0, $bulk['failed_count'], 'El lote no registra fallos');
    assertRrpSame(1, $harness['transactions']->calls, 'El lote se ejecuta en una única unidad de trabajo');
    assertRrpSame(1, count($harness['audit']->events), 'Solo la agenda creada firma auditoría');

    echo "  [OK]   Lote por zona, overwrite=false y una sola transacción\n";

    echo "[3/9] Propiedad efectiva y composición de la ruta\n";

    $harness = rrpHarness();
    $harness['readModel']->restockers = [
        rrpRestocker(7, ['zone_ids' => [1], 'is_primary' => true]),
        rrpRestocker(8, ['zone_ids' => [1], 'is_primary' => false]),
        rrpRestocker(9, ['zone_ids' => [2], 'is_primary' => true]),
    ];
    $harness['readModel']->machines = [
        rrpMachine(1, ['earliest_fefo_expiration_date' => '2026-11-21']),
        rrpMachine(2, ['empty_slots_count' => 2]),
        rrpMachine(3, ['active_l1_incident' => ['id' => 50, 'assigned_restocker_id' => 8], 'empty_slots_count' => 1]),
        rrpMachine(4, ['active_override' => ['id' => 60, 'override_type' => 'REASSIGNED', 'target_restocker_id' => 9], 'empty_slots_count' => 1]),
        rrpMachine(5, ['zone_id' => 3, 'zone_name' => 'Zona Sur', 'empty_slots_count' => 1]),
        rrpMachine(6, ['sanitary_status' => 'QUARANTINE', 'empty_slots_count' => 1]),
        rrpMachine(7, ['visit_today' => ['id' => 70, 'status' => 'COMPLETED', 'restocker_id' => 7], 'empty_slots_count' => 1]),
        rrpMachine(8, ['active_override' => ['id' => 80, 'override_type' => 'SKIPPED', 'target_restocker_id' => null], 'empty_slots_count' => 1]),
        rrpMachine(9, ['schedule' => rrpScheduleRow(9)]),
    ];
    $harness['readModel']->overrides[60] = new RestockRouteDailyOverride(
        60,
        4,
        '2026-11-21',
        VendGuard\Core\Domain\Enum\RestockOverrideType::REASSIGNED,
        VendGuard\Core\Domain\Enum\RestockOverrideStatus::ACTIVE,
        7,
        9,
        null,
        VendGuard\Core\Domain\Enum\RestockReassignmentReasonCode::ZONE_UNCOVERED,
        'Zona Norte sin cobertura.',
        false,
        null,
        null,
        null,
        99
    );
    $harness['readModel']->overrides[80] = new RestockRouteDailyOverride(
        80,
        8,
        '2026-11-21',
        VendGuard\Core\Domain\Enum\RestockOverrideType::SKIPPED,
        VendGuard\Core\Domain\Enum\RestockOverrideStatus::ACTIVE,
        7,
        null,
        VendGuard\Core\Domain\Enum\RestockSkipReasonCode::LOCATION_CLOSED,
        null,
        'La conserjería permanece cerrada sin personal de acceso.',
        false,
        null,
        null,
        null,
        99
    );

    $planner = new RestockRoutePlannerService($harness['readModel'], new RestockRouteOptimizer(new GeoDistanceService()));

    $route = $planner->buildRestockerRoute(7, '2026-11-21', [
        'source' => 'BASE_CENTRAL',
        'latitude' => 41.385064,
        'longitude' => 2.1734035,
    ], $now);

    assertRrpSame(3, $route['route_plan']['summary']['pending_machines_count'], 'La ruta de 7 contiene sus tres paradas pendientes');
    assertRrpSame(1, $route['route_plan']['summary']['pending_stops_count'], 'Las tres máquinas comparten sede y forman una parada');
    assertRrpSame(
        RestockRouteOptimizer::BLOCK_FEFO_SANITARY,
        $route['route_plan']['stops'][0]['priority_block'],
        'El FEFO refrigerado abre el Bloque 1'
    );
    assertRrpSame(1, $route['route_plan']['summary']['fefo_alerts_count'], 'Solo la máquina con FEFO cuenta como alerta');
    assertRrpSame(1, $route['route_plan']['summary']['completed_machines_count'], 'La visita completada se publica aparte');
    assertRrpSame(1, $route['route_plan']['summary']['blocked_machines_count'], 'La cuarentena se publica en blocked_stops');
    assertRrpSame(1, $route['route_plan']['summary']['skipped_machines_count'], 'La omisión viva se publica en skipped_stops');
    assertRrpSame(
        'SANITARY_QUARANTINE',
        $route['route_plan']['blocked_stops'][0]['block_reason'],
        'La cuarentena usa el código exacto del contrato'
    );
    assertRrpCondition(
        !array_key_exists('reason_text', $route['route_plan']['skipped_stops'][0]),
        'La hoja del reponedor no publica reason_text internos'
    );
    assertRrpCondition(
        !str_contains(json_encode($route, JSON_UNESCAPED_UNICODE) ?: '', 'unit_cost_eur')
            && !str_contains(json_encode($route, JSON_UNESCAPED_UNICODE) ?: '', 'commercial_spoilage_loss_eur'),
        'La ruta no filtra costes ni pérdidas comerciales'
    );

    $restockersById = [
        7 => rrpRestocker(7),
        8 => rrpRestocker(8, ['zone_ids' => [1], 'is_primary' => false]),
        9 => rrpRestocker(9, ['zone_ids' => [2]]),
    ];
    assertRrpSame(8, $planner->resolveOwnerId($harness['readModel']->machines[2], $restockersById, '2026-11-21'), 'El aviso L1 prevalece sobre la zona');
    assertRrpSame(9, $planner->resolveOwnerId($harness['readModel']->machines[3], $restockersById, '2026-11-21'), 'El REASSIGNED prevalece sobre el L1 y la zona');
    assertRrpSame(7, $planner->resolveOwnerId($harness['readModel']->machines[1], $restockersById, '2026-11-21'), 'Sin excepción, cubre el titular de la zona');

    $offShift = [7 => rrpRestocker(7, ['shift_status' => 'OFF_SHIFT'])];
    assertRrpSame(null, $planner->resolveOwnerId($harness['readModel']->machines[1], $offShift, '2026-11-21'), 'El día en curso exige turno abierto');
    assertRrpSame(7, $planner->resolveOwnerId($harness['readModel']->machines[1], $offShift, '2026-11-24', true), 'La proyección futura no exige turno abierto');

    $onAbsence = [7 => rrpRestocker(7, ['has_absence' => true])];
    assertRrpSame(null, $planner->resolveOwnerId($harness['readModel']->machines[1], $onAbsence, '2026-11-24', true), 'La ausencia vigente retira la cobertura futura');

    echo "  [OK]   REASSIGNED > L1 > zona, turno y ausencia\n";

    echo "[4/9] Tablero de Coordinación y opacidad\n";

    $day = $planner->buildCoordinatorDay('2026-11-21', [], $now);

    assertRrpSame(1, $day['kpis']['scheduled_machines_count'], 'Solo una máquina tiene cadencia aplicable');
    assertRrpSame(1, $day['kpis']['injected_fefo_count'], 'Solo una máquina inyecta FEFO');
    assertRrpSame(1, $day['kpis']['completed_count'], 'El contador de completadas es de la fecha');
    assertRrpSame(1, $day['kpis']['skipped_count'], 'El contador de omitidas es de la fecha');
    assertRrpSame(1, $day['kpis']['blocked_count'], 'El contador de bloqueadas es de la fecha');
    assertRrpSame(1, $day['kpis']['uncovered_count'], 'La zona sin reponedor queda en la bandeja prioritaria');
    assertRrpSame(3, count($day['restocker_routes']), 'Coordinación recibe una ruta por reponedor con trabajo');
    assertRrpSame(2, count($day['active_overrides']), 'El tablero publica las excepciones vivas del día');
    assertRrpCondition(
        isset($day['skipped_stops'][0]['reason_text']),
        'Coordinación sí conserva el motivo escrito de la omisión'
    );
    assertRrpSame('UNCOVERED', $day['uncovered_stops'][0]['coverage_status'], 'La bandeja usa el estado de cobertura del contrato');
    assertRrpSame(false, $day['is_future_projection'], 'La fecha de hoy no se marca como proyección');

    $future = $planner->buildCoordinatorDay('2026-11-24', [], $now);
    assertRrpSame(true, $future['is_future_projection'], 'Una fecha posterior se marca como proyección');
    assertRrpSame(0, $future['kpis']['completed_count'], 'La proyección no hereda visitas del día real');
    assertRrpSame(0, $future['kpis']['skipped_count'], 'La proyección no hereda omisiones del día real');

    echo "  [OK]   Contadores instantáneos, bandeja UNCOVERED y opacidad\n";

    echo "[5/9] Omisión SKIPPED, FEFO y sello EXPIRED\n";

    $harness = rrpHarness();
    $harness['gateway']->machines[1] = rrpMachine(1, ['sanitary_status' => 'OK']);
    $harness['readModel']->machines = [
        rrpMachine(1, [
            'earliest_fefo_expiration_date' => '2026-11-21',
            'has_expired_cold_batch' => true,
        ]),
    ];
    $harness['readModel']->restockers = [rrpRestocker(7)];
    $harness['readModel']->gateway = $harness['gateway'];

    $planner = new RestockRoutePlannerService($harness['readModel'], new RestockRouteOptimizer(new GeoDistanceService()));
    $overrideService = new RestockRouteOverrideService(
        $harness['gateway'],
        $harness['readModel'],
        $planner,
        new AuditLogger($harness['audit']),
        $harness['transactions'],
        'coordinacion@vendguard.local',
        'EMAIL'
    );

    $payload = [
        'service_date' => '2026-11-21',
        'skip_reason_code' => 'LOCATION_CLOSED',
        'reason_text' => 'La conserjería permanece cerrada sin personal de acceso.',
        'client_mutation_id' => 'mut-skip-20261121-0001',
        'client_occurred_at' => '2026-11-21T08:45:00+01:00',
    ];

    $skip = $overrideService->skip(1, $payload, rrpActor(7, 'RESTOCKER'), $now);

    assertRrpSame('SKIPPED', $skip['override']['override_type'], 'La excepción se registra como SKIPPED');
    assertRrpSame('ACTIVE', $skip['override']['status'], 'La excepción nace ACTIVE');
    assertRrpSame(true, $skip['fefo_alert'], 'El FEFO refrigerado dispara la salvaguarda');
    assertRrpSame(true, $skip['expired_stamp'], 'El lote vencido sella la máquina');
    assertRrpSame('EXPIRED', $harness['gateway']->machines[1]['sanitary_status'], 'La máquina queda EXPIRED sin bloquear el flujo');
    assertRrpSame(1, count($harness['gateway']->notifications), 'Se encola un único aviso interno');
    assertRrpSame(
        'EMAIL',
        $harness['gateway']->notifications[1]['channel'],
        'El aviso usa el canal de Coordinación, no el de la sede'
    );
    assertRrpSame(
        $harness['gateway']->notifications[1]['override_id'],
        $skip['override']['triggered_notification_id'],
        'La excepción queda vinculada a su aviso'
    );
    assertRrpSame(1, count($harness['audit']->events), 'La omisión firma su evento de auditoría');
    assertRrpSame(
        AuditEvent::ENTITY_RESTOCK_ROUTE_DAILY_OVERRIDE,
        $harness['audit']->events[0]->getEntityType(),
        'La auditoría usa la entidad de excepción diaria'
    );

    $replay = $overrideService->skip(1, $payload, rrpActor(7, 'RESTOCKER'), $now);
    assertRrpSame(true, $replay['idempotent_replay'], 'El reintento offline se reconoce como idempotente');
    assertRrpSame(1, count($harness['gateway']->overrides), 'El reintento no duplica la excepción');
    assertRrpSame(1, count($harness['gateway']->notifications), 'El reintento no duplica el aviso');

    assertRrpThrows(
        static fn () => $overrideService->skip(1, [
            'service_date' => '2026-11-21',
            'skip_reason_code' => 'LOCATION_CLOSED',
            'reason_text' => 'Cerrado hoy.',
        ], rrpActor(7, 'RESTOCKER'), $now),
        RestockRoutePlannerValidationException::SKIP_REASON_INVALID,
        'Un motivo de menos de 20 caracteres se rechaza'
    );

    assertRrpThrows(
        static fn () => $overrideService->skip(1, [
            'service_date' => '2026-11-21',
            'skip_reason_code' => 'LOCATION_CLOSED',
            'reason_text' => '--- ... /// 111',
        ], rrpActor(7, 'RESTOCKER'), $now),
        RestockRoutePlannerValidationException::SKIP_REASON_INVALID,
        'Un motivo sin tres bloques alfanuméricos se rechaza'
    );

    echo "  [OK]   SKIPPED, aviso FEFO, EXPIRED e idempotencia\n";

    echo "[6/9] Perímetro del reponedor y verdad del día\n";

    $harness = rrpHarness();
    $harness['gateway']->machines[1] = rrpMachine(1);
    $harness['readModel']->machines = [rrpMachine(1, ['empty_slots_count' => 1])];
    $harness['readModel']->restockers = [
        rrpRestocker(7),
        rrpRestocker(9, ['zone_ids' => [2]]),
    ];
    $harness['readModel']->gateway = $harness['gateway'];

    $planner = new RestockRoutePlannerService($harness['readModel'], new RestockRouteOptimizer(new GeoDistanceService()));
    $overrideService = new RestockRouteOverrideService(
        $harness['gateway'],
        $harness['readModel'],
        $planner,
        new AuditLogger($harness['audit']),
        $harness['transactions']
    );

    $validPayload = [
        'service_date' => '2026-11-21',
        'skip_reason_code' => 'NO_KEY_OR_ACCESS',
        'reason_text' => 'La llave del cuarto de contadores no está disponible en conserjería.',
    ];

    assertRrpThrows(
        static fn () => $overrideService->skip(1, $validPayload, rrpActor(9, 'RESTOCKER'), $now),
        RestockRoutePlannerValidationException::RESTOCK_STOP_NOT_IN_RESTOCKER_ROUTE,
        'Un reponedor ajeno no omite la parada'
    );

    $harness['readModel']->machines[0]['visit_today'] = [
        'id' => 70,
        'status' => 'COMPLETED',
        'restocker_id' => 7,
    ];
    assertRrpThrows(
        static fn () => $overrideService->skip(1, $validPayload, rrpActor(7, 'RESTOCKER'), $now),
        RestockRoutePlannerValidationException::RESTOCK_STOP_ALREADY_COMPLETED_TODAY,
        'Una visita cerrada impide omitir'
    );

    $harness['readModel']->machines[0]['visit_today'] = null;
    $harness['readModel']->machines[0]['sanitary_status'] = 'QUARANTINE';
    assertRrpThrows(
        static fn () => $overrideService->skip(1, $validPayload, rrpActor(7, 'RESTOCKER'), $now),
        RestockRoutePlannerValidationException::RESTOCK_STOP_BLOCKED_BY_SANITARY_OR_SAT,
        'Una máquina en cuarentena no se omite'
    );

    assertRrpThrows(
        static fn () => $overrideService->skip(1, [
            'service_date' => '2026-11-20',
            'skip_reason_code' => 'LOCATION_CLOSED',
            'reason_text' => 'La conserjería permanece cerrada sin personal de acceso.',
        ], rrpActor(7, 'RESTOCKER'), $now),
        RestockRoutePlannerValidationException::PAST_SERVICE_DATE_FORBIDDEN,
        'El reponedor no registra omisiones del pasado'
    );

    echo "  [OK]   403 de perímetro, 409 de visita/cuarentena y fecha pasada\n";

    echo "[7/9] Desvío REASSIGNED y revocación\n";

    $harness = rrpHarness();
    $harness['gateway']->machines[1] = rrpMachine(1);
    $harness['readModel']->machines = [rrpMachine(1, ['empty_slots_count' => 1])];
    $harness['readModel']->restockers = [
        rrpRestocker(7),
        rrpRestocker(9, ['zone_ids' => [2]]),
    ];
    $harness['readModel']->gateway = $harness['gateway'];

    $planner = new RestockRoutePlannerService($harness['readModel'], new RestockRouteOptimizer(new GeoDistanceService()));
    $overrideService = new RestockRouteOverrideService(
        $harness['gateway'],
        $harness['readModel'],
        $planner,
        new AuditLogger($harness['audit']),
        $harness['transactions']
    );

    $reassignPayload = [
        'machine_id' => 1,
        'service_date' => '2026-11-21',
        'target_restocker_id' => 9,
        'reassignment_reason_code' => 'ZONE_UNCOVERED',
        'reason_text' => 'Zona Norte sin cobertura por baja simultánea de titular y suplente.',
    ];

    assertRrpThrows(
        static fn () => $overrideService->reassign(
            array_merge($reassignPayload, ['target_restocker_id' => 7]),
            rrpActor(99, 'COORDINATOR'),
            $now
        ),
        RestockRoutePlannerValidationException::SELF_REASSIGNMENT_FORBIDDEN,
        'No se desvía una parada a su propio propietario'
    );

    assertRrpThrows(
        static fn () => $overrideService->reassign(
            $reassignPayload,
            rrpActor(7, 'RESTOCKER'),
            $now
        ),
        RestockRoutePlannerValidationException::FORBIDDEN,
        'Solo Coordinación desvía paradas'
    );

    $reassigned = $overrideService->reassign($reassignPayload, rrpActor(99, 'COORDINATOR'), $now);
    assertRrpSame('REASSIGNED', $reassigned['override']['override_type'], 'El desvío se registra como REASSIGNED');
    assertRrpSame(9, $reassigned['override']['target_restocker_id'], 'El destino efectivo es el reponedor 9');
    assertRrpSame(7, $reassigned['override']['original_restocker_id'], 'Se conserva el titular previo');
    assertRrpSame(null, $reassigned['override']['revoked_previous_override_id'], 'El primer desvío no revoca nada');

    $revoked = $overrideService->revoke((int)$reassigned['override']['id'], rrpActor(99, 'COORDINATOR'), $now);
    assertRrpSame('REVOKED', $revoked['override']['status'], 'La revocación deja la excepción en REVOKED');
    assertRrpCondition($revoked['revoked'] === true, 'La revocación responde con revoked=true');
    assertRrpThrows(
        static fn () => $overrideService->revoke((int)$reassigned['override']['id'], rrpActor(99, 'COORDINATOR'), $now),
        RestockRoutePlannerValidationException::OVERRIDE_NOT_ACTIVE,
        'Una excepción revocada no se revoca dos veces'
    );

    echo "  [OK]   REASSIGNED, auto-desvío, rol y revocación\n";

    echo "[8/9] Revocación de la excepción anterior y volcado offline\n";

    $harness = rrpHarness();
    $harness['gateway']->machines[1] = rrpMachine(1);
    $harness['readModel']->machines = [rrpMachine(1, ['empty_slots_count' => 1])];
    $harness['readModel']->restockers = [
        rrpRestocker(7),
        rrpRestocker(9, ['zone_ids' => [2]]),
    ];
    $harness['readModel']->gateway = $harness['gateway'];

    $planner = new RestockRoutePlannerService($harness['readModel'], new RestockRouteOptimizer(new GeoDistanceService()));
    $overrideService = new RestockRouteOverrideService(
        $harness['gateway'],
        $harness['readModel'],
        $planner,
        new AuditLogger($harness['audit']),
        $harness['transactions']
    );

    $skipPayload = [
        'service_date' => '2026-11-21',
        'skip_reason_code' => 'LOCATION_CLOSED',
        'reason_text' => 'La conserjería permanece cerrada sin personal de acceso.',
    ];
    $skip = $overrideService->skip(1, $skipPayload, rrpActor(7, 'RESTOCKER'), $now);

    $reassigned = $overrideService->reassign([
        'machine_id' => 1,
        'service_date' => '2026-11-21',
        'target_restocker_id' => 9,
        'reassignment_reason_code' => 'ZONE_UNCOVERED',
    ], rrpActor(99, 'COORDINATOR'), $now);

    assertRrpSame(
        (int)$skip['override']['id'],
        $reassigned['override']['revoked_previous_override_id'],
        'El nuevo desvío declara la omisión revocada'
    );
    assertRrpSame(
        'REVOKED',
        $harness['gateway']->overrides[(int)$skip['override']['id']]->getStatus()->value,
        'La omisión anterior queda REVOKED sin borrarse'
    );
    assertRrpSame(2, count($harness['gateway']->overrides), 'El histórico conserva las dos excepciones');

    $dump = $overrideService->dumpOfflineConflict([
        'machine_id' => 1,
        'http_status' => 409,
        'error_code' => 'RESTOCK_STOP_ALREADY_COMPLETED_TODAY',
        'message' => 'La parada ya fue visitada.',
        'client_mutation_id' => 'mut-conflict-0001',
    ], rrpActor(7, 'RESTOCKER'), $now);

    assertRrpSame(true, $dump['archived'], 'El conflicto offline se archiva');
    assertRrpSame(409, $dump['http_status'], 'Se conserva el estado HTTP original');
    $lastEvent = $harness['audit']->events[count($harness['audit']->events) - 1];
    assertRrpSame(
        RestockRouteOverrideService::ACTION_CONFLICT_DUMP,
        $lastEvent->getAction(),
        'El volcado firma su acción de auditoría'
    );

    echo "  [OK]   Una sola excepción viva y archivo de conflictos\n";

    echo "[9/9] Regresión de los contratos ya certificados\n";

    assertRrpCondition(
        count(AuditEvent::ENTITY_TYPES) === count(array_unique(AuditEvent::ENTITY_TYPES)),
        'La whitelist de auditoría no contiene duplicados'
    );
    $weeklyValid = true;
    try {
        VendGuard\Core\Service\RestockRoutePlannerValidator::assertScheduleCadence(
            VendGuard\Core\Domain\Enum\RestockCadenceType::WEEKLY,
            3,
            null,
            false
        );
    } catch (Throwable) {
        $weeklyValid = false;
    }
    assertRrpCondition($weeklyValid, 'La cadencia WEEKLY con día sigue siendo válida');
    assertRrpSame(
        '2026-11-25',
        VendGuard\Core\Service\RestockCadenceEvaluator::nextScheduledDate(
            new MachineRestockSchedule(
                null,
                1,
                VendGuard\Core\Domain\Enum\RestockCadenceType::WEEKLY,
                3,
                null,
                VendGuard\Core\Domain\Enum\RestockTimeWindow::MORNING,
                false,
                null,
                true,
                99,
                99
            ),
            '2026-11-21',
            VendGuard\Core\Domain\ValueObject\SlaTier::STANDARD
        ),
        'La aritmética de cadencia conserva su próxima parada'
    );

    echo "  [OK]   Whitelist, cadencia y próxima parada\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[FAIL] " . $exception->getMessage() . "\n");
    exit(1);
}

echo "======================================================================\n";
echo " VENDGUARD · T-RRP-07 · Servicios del planificador de rutas\n";
echo " Total Aserciones: {$assertions}\n";
echo " RESULTADO: 100% EN VERDE\n";
echo "======================================================================\n";
exit(0);
