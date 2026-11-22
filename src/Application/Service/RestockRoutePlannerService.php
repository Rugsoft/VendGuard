<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use VendGuard\Core\Domain\Exception\RestockRoutePlannerValidationException;
use VendGuard\Core\Domain\Model\MachineRestockSchedule;
use VendGuard\Core\Domain\Repository\RestockRouteReadModelInterface;
use VendGuard\Core\Domain\ValueObject\SlaTier;
use VendGuard\Core\Service\RestockCadenceEvaluator;
use VendGuard\Core\Service\RestockRouteOptimizer;

/**
 * RestockRoutePlannerService
 *
 * Composición diaria de la ruta de reposición (Módulo 37; RF-03.1, RF-05.1,
 * RF-05.2, D1–D7, C1–C3, QA-1, QA-5, QA-6, Art. II, Art. V.4 y Art. VI).
 *
 * El servicio no escribe: lee una foto del parque, decide la propiedad efectiva
 * de cada parada, clasifica sus disparadores y entrega dos vistas coherentes:
 *
 *   - **Hoja móvil del reponedor.** Solo sus paradas, agrupadas por sede y
 *     ordenadas por `RestockRouteOptimizer` en los tres bloques canónicos. La
 *     respuesta omite `reason_text` internos de Coordinación, costes y notas
 *     ajenas (Art. V.4); las excepciones `SKIPPED` se publican como hechos del
 *     día, no como texto libre.
 *   - **Tablero «Ruta del Día».** Contadores instantáneos exclusivos de un
 *     `service_date` (Art. VI), bandeja de paradas sin cobertura, rutas por
 *     reponedor y excepciones vivas. Con `service_date > today` proyecta la
 *     titularidad futura sin exigir turno abierto (QA-6).
 *
 * Precedencia de propiedad (D2, D5, QA-3, QA-5):
 *
 *   1. `REASSIGNED` viva con destino activo.
 *   2. Aviso L1 abierto con reponedor asignado y activo.
 *   3. Cobertura de zona: titular o suplente activo, sin ausencia vigente y,
 *      para el día en curso, con turno abierto.
 *
 * Un `SKIPPED` vivo retira la parada de la ruta y la publica en `skipped_stops`;
 * no la convierte en cobertura de otro reponedor.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockRoutePlannerService
{
    public const BLOCK_LABELS = [
        RestockRouteOptimizer::BLOCK_FEFO_SANITARY => 'Bloque 1 · Riesgo sanitario FEFO',
        RestockRouteOptimizer::BLOCK_DEMAND_L1_STOCKOUT => 'Bloque 2 · Demanda L1 o stockout',
        RestockRouteOptimizer::BLOCK_SCHEDULED_CADENCE => 'Bloque 3 · Cadencia programada',
    ];

    public const TRIGGER_LABELS = [
        RestockRouteOptimizer::TRIGGER_FEFO_ALERT => 'FEFO próximo o vencido',
        RestockRouteOptimizer::TRIGGER_L1_INCIDENT => 'Aviso L1 abierto',
        RestockRouteOptimizer::TRIGGER_EMPTY_SLOT => 'Carril vacío',
        RestockRouteOptimizer::TRIGGER_SCHEDULED => 'Cadencia programada',
        RestockRouteOptimizer::TRIGGER_INCOMING_REASSIGNMENT => 'Desvío entrante',
    ];

    public const BLOCK_REASON_LABELS = [
        'SANITARY_QUARANTINE' => 'Bloqueada por cuarentena sanitaria activa',
        'NO_ACCESS' => 'Acceso bloqueado por SAT o conserjería',
        'OPEN_THERMAL_BREAKDOWN' => 'Avería térmica abierta: no manipular la máquina',
        'SEASONAL_PAUSE' => 'Pausa estacional vigente sin FEFO que la justifique',
        'MACHINE_INACTIVE' => 'Máquina o sede inactiva',
    ];

    /** Estados de turno que cubren el día en curso. */
    public const ACTIVE_SHIFT_STATUSES = ['ON_SHIFT', 'MEAL_BREAK'];

    private RestockRouteReadModelInterface $readModel;
    private RestockRouteOptimizer $optimizer;

    public function __construct(
        RestockRouteReadModelInterface $readModel,
        ?RestockRouteOptimizer $optimizer = null
    ) {
        $this->readModel = $readModel;
        $this->optimizer = $optimizer ?? new RestockRouteOptimizer(new GeoDistanceService());
    }

    /**
     * Hoja de ruta del reponedor para una fecha civil.
     *
     * @param array{source?: string, latitude?: float|null, longitude?: float|null}|null $origin
     *
     * @return array<string, mixed>
     */
    public function buildRestockerRoute(
        int $restockerId,
        ?string $serviceDate = null,
        ?array $origin = null,
        ?DateTimeImmutable $now = null
    ): array {
        $today = RestockCadenceEvaluator::today($now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')));
        $date = $serviceDate !== null
            ? RestockCadenceEvaluator::normalizeServiceDate($serviceDate)
            : $today;
        $isFuture = $date > $today;

        if ($restockerId <= 0) {
            throw RestockRoutePlannerValidationException::invalidTargetRestocker($restockerId);
        }

        $snapshot = $this->readModel->loadParkSnapshot($date);
        $restockersById = $this->indexRestockers($snapshot['restockers']);
        $restocker = $restockersById[$restockerId] ?? null;

        if ($restocker === null) {
            throw RestockRoutePlannerValidationException::restockerNotFound($restockerId);
        }

        $pendingStops = [];
        $completed = [];
        $skipped = [];
        $blocked = [];

        foreach ($snapshot['machines'] as $machine) {
            if (!$this->isOperable($machine)) {
                continue;
            }

            $ownerId = $this->resolveOwnerId($machine, $restockersById, $date, $isFuture);
            if ($ownerId !== $restockerId) {
                continue;
            }

            $classification = $this->classifyMachine($machine, $date, $isFuture);
            if ($classification['status'] === 'COMPLETED') {
                $completed[] = $this->buildCompletedStop($machine);
                continue;
            }

            if ($classification['status'] === 'SKIPPED') {
                $skipped[] = $this->buildSkippedStop($machine, false);
                continue;
            }

            if ($classification['status'] === 'BLOCKED') {
                $blocked[] = $this->buildBlockedStop($machine, $classification);
                continue;
            }

            if (!$classification['has_work']) {
                continue;
            }

            $pendingStops[] = $this->buildPendingStop($machine, $classification, $restockerId);
        }

        $optimized = $this->optimizer->optimize($pendingStops, $origin);

        $summary = [
            'pending_stops_count' => count($optimized['stops']),
            'pending_machines_count' => $this->countMachines($optimized['stops']),
            'completed_machines_count' => count($completed),
            'skipped_machines_count' => count($skipped),
            'blocked_machines_count' => count($blocked),
            'fefo_alerts_count' => $this->countFefoAlerts($optimized['stops']),
            'total_distance_km' => $optimized['total_distance_km'],
            'unmapped_stops_count' => $optimized['unmapped_stops_count'],
        ];

        return [
            'service_date' => $date,
            'is_future_projection' => $isFuture,
            'restocker' => [
                'id' => $restocker['id'],
                'name' => $restocker['name'],
                'operator_code' => $restocker['operator_code'],
                'shift_status' => $restocker['shift_status'],
            ],
            'route_plan' => [
                'origin' => $optimized['origin'],
                'summary' => $summary,
                'full_route_navigation_url' => $optimized['full_route_navigation_url'],
                'waypoints_truncated' => $optimized['waypoints_truncated'],
                'stops' => $optimized['stops'],
                'completed_stops' => $completed,
                'skipped_stops' => $skipped,
                'blocked_stops' => $blocked,
            ],
        ];
    }

    /**
     * Tablero «Ruta del Día» de Coordinación.
     *
     * @param array{restocker_id?: int|null, zone_id?: int|null} $filters
     *
     * @return array<string, mixed>
     */
    public function buildCoordinatorDay(
        ?string $serviceDate = null,
        array $filters = [],
        ?DateTimeImmutable $now = null
    ): array {
        $today = RestockCadenceEvaluator::today($now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')));
        $date = $serviceDate !== null
            ? RestockCadenceEvaluator::normalizeServiceDate($serviceDate)
            : $today;
        $isFuture = $date > $today;

        $restockerFilter = isset($filters['restocker_id']) && $filters['restocker_id'] !== null
            ? (int)$filters['restocker_id']
            : null;
        $zoneFilter = isset($filters['zone_id']) && $filters['zone_id'] !== null
            ? (int)$filters['zone_id']
            : null;

        $snapshot = $this->readModel->loadParkSnapshot($date);
        $restockersById = $this->indexRestockers($snapshot['restockers']);

        $kpis = [
            'scheduled_machines_count' => 0,
            'injected_fefo_count' => 0,
            'injected_demand_count' => 0,
            'completed_count' => 0,
            'skipped_count' => 0,
            'blocked_count' => 0,
            'uncovered_count' => 0,
        ];

        $uncovered = [];
        $blockedStops = [];
        $completedStops = [];
        $skippedStops = [];
        /** @var array<int, array{pending: list<array<string, mixed>>, completed: list<array<string, mixed>>, skipped: list<array<string, mixed>>, blocked: list<array<string, mixed>>}> $byRestocker */
        $byRestocker = [];
        $activeOverrides = [];

        foreach ($snapshot['overrides'] as $overrideRow) {
            if ($restockerFilter !== null
                && (int)($overrideRow['target_restocker_id'] ?? 0) !== $restockerFilter
                && (int)($overrideRow['original_restocker_id'] ?? 0) !== $restockerFilter) {
                continue;
            }
            $activeOverrides[] = $this->sanitizeOverride($overrideRow);
        }

        foreach ($snapshot['machines'] as $machine) {
            if (!$this->isOperable($machine)) {
                continue;
            }

            if ($zoneFilter !== null && (int)($machine['zone_id'] ?? 0) !== $zoneFilter) {
                continue;
            }

            $classification = $this->classifyMachine($machine, $date, $isFuture);

            if ($classification['has_schedule']) {
                $kpis['scheduled_machines_count']++;
            }
            if ($classification['has_fefo_alert']) {
                $kpis['injected_fefo_count']++;
            }
            if ($classification['has_demand']) {
                $kpis['injected_demand_count']++;
            }

            $ownerId = $this->resolveOwnerId($machine, $restockersById, $date, $isFuture);
            if ($restockerFilter !== null && $ownerId !== $restockerFilter) {
                continue;
            }

            if ($ownerId !== null && !isset($byRestocker[$ownerId])) {
                $byRestocker[$ownerId] = [
                    'pending' => [],
                    'completed' => [],
                    'skipped' => [],
                    'blocked' => [],
                ];
            }

            if ($classification['status'] === 'COMPLETED') {
                $kpis['completed_count']++;
                $completed = $this->buildCompletedStop($machine);
                $completedStops[] = $completed;
                if ($ownerId !== null) {
                    $byRestocker[$ownerId]['completed'][] = $completed;
                }
                continue;
            }

            if ($classification['status'] === 'SKIPPED') {
                $kpis['skipped_count']++;
                $skipped = $this->buildSkippedStop($machine, true);
                $skippedStops[] = $skipped;
                if ($ownerId !== null) {
                    $byRestocker[$ownerId]['skipped'][] = $skipped;
                }
                continue;
            }

            if ($classification['status'] === 'BLOCKED') {
                $kpis['blocked_count']++;
                $blocked = $this->buildBlockedStop($machine, $classification);
                $blockedStops[] = $blocked;
                if ($ownerId !== null) {
                    $byRestocker[$ownerId]['blocked'][] = $blocked;
                }
                continue;
            }

            if (!$classification['has_work']) {
                continue;
            }

            if ($ownerId === null) {
                $kpis['uncovered_count']++;
                $uncovered[] = $this->buildUncoveredStop($machine, $classification);
                continue;
            }

            $byRestocker[$ownerId]['pending'][] = $machine;
        }

        $routes = [];
        foreach ($byRestocker as $ownerId => $bucket) {
            $restocker = $restockersById[$ownerId] ?? null;
            if ($restocker === null) {
                continue;
            }

            $pendingStops = [];
            foreach ($bucket['pending'] as $machine) {
                $pendingStops[] = $this->buildPendingStop(
                    $machine,
                    $this->classifyMachine($machine, $date, $isFuture),
                    $ownerId
                );
            }

            $optimized = $this->optimizer->optimize($pendingStops);

            $routes[] = [
                'restocker_id' => $ownerId,
                'restocker_name' => $restocker['name'],
                'operator_code' => $restocker['operator_code'],
                'shift_status' => $restocker['shift_status'],
                'summary' => [
                    'pending_stops_count' => count($optimized['stops']),
                    'pending_machines_count' => $this->countMachines($optimized['stops']),
                    'fefo_alerts_count' => $this->countFefoAlerts($optimized['stops']),
                    'completed_machines_count' => count($bucket['completed']),
                    'skipped_machines_count' => count($bucket['skipped']),
                    'blocked_machines_count' => count($bucket['blocked']),
                    'total_distance_km' => $optimized['total_distance_km'],
                    'unmapped_stops_count' => $optimized['unmapped_stops_count'],
                ],
                'stops' => $optimized['stops'],
                'completed_stops' => $bucket['completed'],
                'skipped_stops' => $bucket['skipped'],
                'blocked_stops' => $bucket['blocked'],
            ];
        }

        usort($routes, static fn (array $a, array $b): int => $a['restocker_name'] <=> $b['restocker_name']);

        usort($uncovered, static function (array $a, array $b): int {
            $rank = [
                RestockRouteOptimizer::BLOCK_FEFO_SANITARY => 0,
                RestockRouteOptimizer::BLOCK_DEMAND_L1_STOCKOUT => 1,
                RestockRouteOptimizer::BLOCK_SCHEDULED_CADENCE => 2,
            ];

            return ($rank[$a['priority_block']] ?? 9) <=> ($rank[$b['priority_block']] ?? 9)
                ?: $a['machine_id'] <=> $b['machine_id'];
        });

        return [
            'service_date' => $date,
            'is_future_projection' => $isFuture,
            'kpis' => $kpis,
            'uncovered_stops' => $uncovered,
            'restocker_routes' => $routes,
            'active_overrides' => $activeOverrides,
            'blocked_stops' => $blockedStops,
            'completed_stops' => $completedStops,
            'skipped_stops' => $skippedStops,
        ];
    }

    /**
     * Propietario efectivo de una máquina en la fecha indicada.
     *
     * @param array<string, mixed> $machine
     * @param array<int, array<string, mixed>> $restockersById
     */
    public function resolveOwnerId(
        array $machine,
        array $restockersById,
        string $serviceDate,
        bool $isFutureProjection = false
    ): ?int {
        $override = $machine['active_override'] ?? null;
        if (is_array($override)
            && ($override['override_type'] ?? null) === 'REASSIGNED'
            && isset($override['target_restocker_id'])) {
            $targetId = (int)$override['target_restocker_id'];
            if (isset($restockersById[$targetId]) && $restockersById[$targetId]['is_active']) {
                return $targetId;
            }
        }

        $l1 = $machine['active_l1_incident'] ?? null;
        if (is_array($l1) && isset($l1['assigned_restocker_id'])) {
            $assignedId = (int)$l1['assigned_restocker_id'];
            if (isset($restockersById[$assignedId]) && $restockersById[$assignedId]['is_active']) {
                return $assignedId;
            }
        }

        $zoneId = $machine['zone_id'] !== null ? (int)$machine['zone_id'] : null;
        if ($zoneId === null || !$machine['zone_active']) {
            return null;
        }

        $primary = null;
        $fallback = null;

        foreach ($restockersById as $candidate) {
            if (!$candidate['is_active'] || $candidate['has_absence']) {
                continue;
            }
            if (!in_array($zoneId, $candidate['zone_ids'], true)) {
                continue;
            }
            if (!$isFutureProjection && !in_array($candidate['shift_status'], self::ACTIVE_SHIFT_STATUSES, true)) {
                continue;
            }

            if ($candidate['is_primary']) {
                $primary = $candidate['id'];
                break;
            }
            if ($fallback === null) {
                $fallback = $candidate['id'];
            }
        }

        return $primary ?? $fallback;
    }

    /**
     * Motivos que segregan una parada de la ruta (Art. II, C1, QA-6).
     *
     * @param array<string, mixed> $machine
     *
     * @return list<string>
     */
    public function blockedReasons(array $machine, string $serviceDate): array
    {
        $reasons = [];

        if (!$machine['is_active'] || !$machine['location_active']) {
            $reasons[] = 'MACHINE_INACTIVE';
        }

        if (($machine['sanitary_status'] ?? '') === 'QUARANTINE') {
            $reasons[] = 'SANITARY_QUARANTINE';
        }

        if (($machine['is_blocked_no_access'] ?? false) === true) {
            $reasons[] = 'NO_ACCESS';
        }

        if (($machine['has_open_thermal_breakdown'] ?? false) === true) {
            $reasons[] = 'OPEN_THERMAL_BREAKDOWN';
        }

        $paused = RestockCadenceEvaluator::isSeasonalPauseActive(
            (bool)$machine['is_seasonal_pause'],
            $machine['seasonal_pause_until'] !== null ? (string)$machine['seasonal_pause_until'] : null,
            $serviceDate
        );

        if ($paused && !$this->hasFefoAlert($machine, $serviceDate)) {
            $reasons[] = 'SEASONAL_PAUSE';
        }

        return $reasons;
    }

    /**
     * ¿La máquina conserva una alerta FEFO real en la fecha? (Art. II)
     *
     * Solo cuentan los lotes refrigerados: la consulta de lectura ya excluye
     * `requires_cold_chain = FALSE`.
     *
     * @param array<string, mixed> $machine
     */
    public function hasFefoAlert(array $machine, string $serviceDate): bool
    {
        $expiration = $machine['earliest_fefo_expiration_date'] ?? null;
        if ($expiration === null) {
            return false;
        }

        $expiration = (string)$expiration;
        if ($expiration <= $serviceDate) {
            return true;
        }

        $schedule = $this->scheduleFromMachine($machine);
        $horizon = null;

        if ($schedule !== null) {
            $horizon = RestockCadenceEvaluator::nextScheduledDate(
                $schedule,
                $serviceDate,
                SlaTier::fromString((string)$machine['sla_tier']),
                RestockCadenceEvaluator::isSeasonalPauseActive(
                    (bool)$machine['is_seasonal_pause'],
                    $machine['seasonal_pause_until'] !== null ? (string)$machine['seasonal_pause_until'] : null,
                    $serviceDate
                )
            );
        }

        $limit = $horizon ?? (new DateTimeImmutable($serviceDate, new DateTimeZone('UTC')))
            ->modify('+2 days')
            ->format('Y-m-d');

        return $expiration <= $limit;
    }

    /**
     * Clasificación interna de una máquina para la composición diaria.
     *
     * @param array<string, mixed> $machine
     *
     * @return array{
     *     status: string,
     *     triggers: list<string>,
     *     block_reasons: list<string>,
     *     suppressed_triggers: list<string>,
     *     has_schedule: bool,
     *     has_fefo_alert: bool,
     *     has_demand: bool,
     *     has_work: bool
     * }
     */
    private function classifyMachine(array $machine, string $serviceDate, bool $isFuture): array
    {
        $schedule = $this->scheduleFromMachine($machine);
        $slaTier = SlaTier::fromString((string)$machine['sla_tier']);
        $paused = RestockCadenceEvaluator::isSeasonalPauseActive(
            (bool)$machine['is_seasonal_pause'],
            $machine['seasonal_pause_until'] !== null ? (string)$machine['seasonal_pause_until'] : null,
            $serviceDate
        );

        $scheduleWouldApply = $schedule !== null
            && RestockCadenceEvaluator::appliesOnDate($schedule, $serviceDate, $slaTier, false);
        $hasSchedule = $schedule !== null
            && RestockCadenceEvaluator::appliesOnDate($schedule, $serviceDate, $slaTier, $paused);

        $fefo = $this->hasFefoAlert($machine, $serviceDate);
        $l1 = is_array($machine['active_l1_incident'] ?? null);
        $empty = ((int)($machine['empty_slots_count'] ?? 0)) > 0;
        $reassigned = is_array($machine['active_override'] ?? null)
            && ($machine['active_override']['override_type'] ?? null) === 'REASSIGNED';

        $suppressedTriggers = [];
        if ($paused) {
            if ($l1) {
                $suppressedTriggers[] = RestockRouteOptimizer::TRIGGER_L1_INCIDENT;
            }
            if ($empty) {
                $suppressedTriggers[] = RestockRouteOptimizer::TRIGGER_EMPTY_SLOT;
            }
            if ($scheduleWouldApply) {
                $suppressedTriggers[] = RestockRouteOptimizer::TRIGGER_SCHEDULED;
            }
            if ($reassigned) {
                $suppressedTriggers[] = RestockRouteOptimizer::TRIGGER_INCOMING_REASSIGNMENT;
            }
        }

        $triggers = [];
        if ($fefo) {
            $triggers[] = RestockRouteOptimizer::TRIGGER_FEFO_ALERT;
        }
        if (!$paused) {
            if ($l1) {
                $triggers[] = RestockRouteOptimizer::TRIGGER_L1_INCIDENT;
            }
            if ($empty) {
                $triggers[] = RestockRouteOptimizer::TRIGGER_EMPTY_SLOT;
            }
            if ($hasSchedule) {
                $triggers[] = RestockRouteOptimizer::TRIGGER_SCHEDULED;
            }
            if ($reassigned) {
                $triggers[] = RestockRouteOptimizer::TRIGGER_INCOMING_REASSIGNMENT;
            }
        }

        $blockReasons = $this->blockedReasons($machine, $serviceDate);

        $status = 'PENDING';
        $visit = $machine['visit_today'] ?? null;
        if (is_array($visit) && in_array((string)$visit['status'], ['COMPLETED', 'ABORTED_THERMAL_BREACH'], true)) {
            $status = 'COMPLETED';
        } elseif (is_array($machine['active_override'] ?? null)
            && ($machine['active_override']['override_type'] ?? null) === 'SKIPPED') {
            $status = 'SKIPPED';
        } elseif ($blockReasons !== []) {
            $status = 'BLOCKED';
        }

        if ($isFuture) {
            // La proyección futura no hereda visitas ni excepciones del día real.
            $status = $blockReasons === [] ? 'PENDING' : 'BLOCKED';
        }

        return [
            'status' => $status,
            'triggers' => $triggers,
            'block_reasons' => $blockReasons,
            'suppressed_triggers' => $suppressedTriggers,
            'has_schedule' => $hasSchedule,
            'has_fefo_alert' => $fefo,
            'has_demand' => $status !== 'BLOCKED' && !$paused && ($l1 || $empty),
            'has_work' => $triggers !== [],
        ];
    }

    /**
     * @param array<string, mixed> $machine
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private function buildPendingStop(array $machine, array $classification, int $restockerId): array
    {
        $schedule = $machine['schedule'] ?? null;
        $preferredTimeWindow = is_array($schedule)
            ? (string)($schedule['preferred_time_window'] ?? 'ANY')
            : 'ANY';

        return [
            'location_id' => (int)$machine['location_id'],
            'location_name' => (string)$machine['location_name'],
            'address' => (string)$machine['address'],
            'sla_tier' => (string)$machine['sla_tier'],
            'latitude' => (float)$machine['latitude'],
            'longitude' => (float)$machine['longitude'],
            'machines' => [[
                'machine_id' => (int)$machine['id'],
                'machine_code' => (string)$machine['machine_code'],
                'machine_type' => (string)$machine['machine_type'],
                'schedule_id' => is_array($schedule) ? (int)$schedule['id'] : null,
                'triggers' => $classification['triggers'],
                'has_fefo_alert' => $classification['has_fefo_alert'],
                'has_expired_cold_batch' => (bool)$machine['has_expired_cold_batch'],
                'empty_slots_count' => (int)$machine['empty_slots_count'],
                'active_l1_incident' => is_array($machine['active_l1_incident'] ?? null)
                    ? [
                        'id' => (int)$machine['active_l1_incident']['id'],
                        'assigned_restocker_id' => $machine['active_l1_incident']['assigned_restocker_id'],
                    ]
                    : null,
                'is_temporary_reassignment' => is_array($machine['active_override'] ?? null)
                    && ($machine['active_override']['override_type'] ?? null) === 'REASSIGNED'
                    && (int)($machine['active_override']['target_restocker_id'] ?? 0) === $restockerId,
                'earliest_fefo_expiration_date' => $machine['earliest_fefo_expiration_date'],
                'preferred_time_window' => $preferredTimeWindow,
                'status' => RestockRouteOptimizer::MACHINE_STATUS_PENDING,
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $machine
     *
     * @return array<string, mixed>
     */
    private function buildCompletedStop(array $machine): array
    {
        $visit = $machine['visit_today'] ?? [];

        return [
            'machine_id' => (int)$machine['id'],
            'machine_code' => (string)$machine['machine_code'],
            'machine_type' => (string)$machine['machine_type'],
            'location_id' => (int)$machine['location_id'],
            'location_name' => (string)$machine['location_name'],
            'visit_id' => isset($visit['id']) ? (int)$visit['id'] : null,
            'visit_status' => isset($visit['status']) ? (string)$visit['status'] : null,
            'restocker_id' => isset($visit['restocker_id']) ? $visit['restocker_id'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $machine
     *
     * @return array<string, mixed>
     */
    private function buildSkippedStop(array $machine, bool $includeInternalNotes): array
    {
        $override = is_array($machine['active_override'] ?? null) ? $machine['active_override'] : [];
        $row = $this->findActiveOverrideRow($machine);

        $stop = [
            'machine_id' => (int)$machine['id'],
            'machine_code' => (string)$machine['machine_code'],
            'machine_type' => (string)$machine['machine_type'],
            'location_id' => (int)$machine['location_id'],
            'location_name' => (string)$machine['location_name'],
            'override_id' => isset($override['id']) ? (int)$override['id'] : null,
            'skip_reason_code' => $row['skip_reason_code'] ?? null,
            'skip_reason_label' => $row['skip_reason_label'] ?? null,
            'had_active_fefo_alert' => (bool)($row['had_active_fefo_alert'] ?? false),
        ];

        if ($includeInternalNotes) {
            $stop['reason_text'] = $row['reason_text'] ?? null;
            $stop['created_at'] = $row['created_at'] ?? null;
        }

        return $stop;
    }

    /**
     * @param array<string, mixed> $machine
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private function buildBlockedStop(array $machine, array $classification): array
    {
        $reasons = $classification['block_reasons'];
        $primary = $reasons[0] ?? 'MACHINE_INACTIVE';

        return [
            'machine_id' => (int)$machine['id'],
            'machine_code' => (string)$machine['machine_code'],
            'machine_type' => (string)$machine['machine_type'],
            'location_id' => (int)$machine['location_id'],
            'location_name' => (string)$machine['location_name'],
            'block_reason' => $primary,
            'block_reason_label' => self::BLOCK_REASON_LABELS[$primary] ?? $primary,
            'block_reasons' => $reasons,
            'suppressed_triggers' => $classification['suppressed_triggers'],
        ];
    }

    /**
     * @param array<string, mixed> $machine
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private function buildUncoveredStop(array $machine, array $classification): array
    {
        $block = RestockRouteOptimizer::BLOCK_SCHEDULED_CADENCE;
        if ($classification['has_fefo_alert']) {
            $block = RestockRouteOptimizer::BLOCK_FEFO_SANITARY;
        } elseif ($classification['has_demand']) {
            $block = RestockRouteOptimizer::BLOCK_DEMAND_L1_STOCKOUT;
        }

        return [
            'machine_id' => (int)$machine['id'],
            'machine_code' => (string)$machine['machine_code'],
            'machine_type' => (string)$machine['machine_type'],
            'location_id' => (int)$machine['location_id'],
            'location_name' => (string)$machine['location_name'],
            'sla_tier' => (string)$machine['sla_tier'],
            'zone_id' => $machine['zone_id'],
            'zone_name' => $machine['zone_name'],
            'coverage_status' => 'UNCOVERED',
            'priority_block' => $block,
            'triggers' => $classification['triggers'],
            'earliest_fefo_expiration_date' => $machine['earliest_fefo_expiration_date'],
        ];
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function sanitizeOverride(array $override): array
    {
        $allowed = [
            'id',
            'machine_id',
            'service_date',
            'override_type',
            'override_type_label',
            'status',
            'status_label',
            'original_restocker_id',
            'target_restocker_id',
            'skip_reason_code',
            'skip_reason_label',
            'reassignment_reason_code',
            'reassignment_reason_label',
            'reason_text',
            'had_active_fefo_alert',
            'triggered_notification_id',
            'created_by',
            'created_at',
            'resolved_by',
            'resolved_at',
        ];

        return array_intersect_key($override, array_flip($allowed));
    }

    /**
     * @param array<string, mixed> $machine
     *
     * @return array<string, mixed>|null
     */
    private function findActiveOverrideRow(array $machine): ?array
    {
        $override = $machine['active_override'] ?? null;
        if (!is_array($override) || !isset($override['id'])) {
            return null;
        }

        $row = $this->readModel->findOverrideById((int)$override['id']);

        return $row?->toArray();
    }

    /**
     * @param array<string, mixed> $machine
     */
    private function scheduleFromMachine(array $machine): ?MachineRestockSchedule
    {
        $schedule = $machine['schedule'] ?? null;
        if (!is_array($schedule)) {
            return null;
        }

        return MachineRestockSchedule::fromDatabaseRow($schedule);
    }

    /**
     * @param array<string, mixed> $machine
     */
    private function isOperable(array $machine): bool
    {
        return (bool)$machine['is_active'] && (bool)$machine['location_active'];
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
     * @param list<array<string, mixed>> $stops
     */
    private function countMachines(array $stops): int
    {
        $count = 0;
        foreach ($stops as $stop) {
            $count += count($stop['machines'] ?? []);
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $stops
     */
    private function countFefoAlerts(array $stops): int
    {
        $count = 0;
        foreach ($stops as $stop) {
            foreach ($stop['machines'] ?? [] as $machine) {
                if (($machine['has_fefo_alert'] ?? false) === true) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
