<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use VendGuard\Application\DTO\TechnicianShiftDto;
use VendGuard\Application\DTO\WorkHoursReportDto;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Exception\ShiftAdjustmentValidationException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\ShiftEventType;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftLogRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

/**
 * TechnicianWorkHoursService
 *
 * Motor de cálculo del registro de jornada y horas efectivas (Módulo 22,
 * contrato técnico §4.1). Reúne cuatro casos de uso que comparten las mismas
 * cifras y, por tanto, las mismas reglas:
 *
 *   1. Informe de Coordinación (`getWorkHoursReport`): presencia por técnico,
 *      cruce con el Tiempo en Intervención In Situ y Tiempo en Ruta con suelo
 *      cero, turnos abiertos huérfanos y cobertura de toda la plantilla activa.
 *   2. Rectificación auditada (`adjustShift`): transacción con cerrojo por
 *      técnico, validación cuádruple en el dominio, comprobación de
 *      solapamiento, evento `ADJUST_SHIFT` en la bitácora y rastro
 *      `ADJUST_TECHNICIAN_SHIFT` en auditoría.
 *   3. Bitácora de una sesión (`getShiftLogs`).
 *   4. Horas propias del técnico (`getTechnicianOwnMonthlyHours`) y exportación
 *      CSV oficial (`exportWorkHoursCsv`).
 *
 * El servicio no decide reglas nuevas: las horas efectivas salen de la entidad
 * `TechnicianShift` y las de intervención del repositorio de turnos, que las
 * agrega en una sola consulta. Aquí solo se combinan y se formatean, para que
 * la pantalla y el CSV no puedan discrepar.
 *
 * Nota de alcance: `operator_code` viaja a `null` porque ningún puerto de
 * usuarios lo expone; la columna existe en el motor y podrá incorporarse sin
 * cambiar la forma del informe.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, claves, etiquetas y CSV en castellano.
 */
final class TechnicianWorkHoursService
{
    /** Zona operativa del parque para fechas naturales y representaciones locales. */
    private const OPERATIONAL_TIMEZONE = 'Europe/Madrid';

    /** Código determinista para marcas horarias con formato inválido (contrato §5.3). */
    private const INVALID_TIMESTAMPS_CODE = 'INVALID_SHIFT_TIMESTAMPS';

    private readonly TechnicianShiftRepositoryInterface $shiftRepo;

    private readonly TechnicianShiftLogRepositoryInterface $shiftLogRepo;

    private readonly UserRepositoryInterface $userRepo;

    private readonly TransactionManagerInterface $transactions;

    private readonly AuditLogRepositoryInterface $auditRepo;

    private readonly TechnicianLockRepositoryInterface $lockRepo;

    public function __construct(
        ?TechnicianShiftRepositoryInterface $shiftRepo = null,
        ?TechnicianShiftLogRepositoryInterface $shiftLogRepo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?TransactionManagerInterface $transactions = null,
        ?AuditLogRepositoryInterface $auditRepo = null,
        ?TechnicianLockRepositoryInterface $lockRepo = null
    ) {
        $this->shiftRepo = $shiftRepo ?? new PdoTechnicianShiftRepository();
        $this->shiftLogRepo = $shiftLogRepo ?? new PdoTechnicianShiftLogRepository();
        $this->userRepo = $userRepo ?? new PdoUserRepository();
        $this->transactions = $transactions ?? new PdoTransactionManager();
        $this->auditRepo = $auditRepo ?? new PdoAuditLogRepository();
        $this->lockRepo = $lockRepo ?? new PdoUserRepository();
    }

    /**
     * Informe de jornada y horas efectivas del periodo (contrato §5.1).
     */
    public function getWorkHoursReport(MetricFilter $filter, ?int $technicianId = null): WorkHoursReportDto
    {
        $now = new DateTimeImmutable('now');
        $timezone = new DateTimeZone(self::OPERATIONAL_TIMEZONE);
        $scopeTechnicianId = $technicianId ?? $filter->getTechnicianId();

        $fromDate = $filter->getFrom()->setTimezone($timezone)->format('Y-m-d');
        $toDate = $filter->getTo()->setTimezone($timezone)->format('Y-m-d');

        $shifts = $this->shiftRepo->findShiftsByDateRange($fromDate, $toDate, $scopeTechnicianId);
        $orphanShifts = $this->shiftRepo->findOrphanOpenShiftsBefore($fromDate, $scopeTechnicianId);
        $metrics = $this->shiftRepo->getClosedInterventionMetricsByTechnician(
            $filter->getFrom(),
            $filter->getTo(),
            $scopeTechnicianId
        );

        $profiles = $this->loadTechnicianProfiles($scopeTechnicianId);
        $namesById = [];
        foreach ($profiles as $profile) {
            $namesById[$profile->getId()] = $profile->getName();
        }

        $sessions = [];
        $shiftsByTechnician = [];
        foreach ($shifts as $shift) {
            $shiftsByTechnician[$shift->technicianId()][] = $shift;
            $sessions[] = $this->sessionToArray($shift, $namesById[$shift->technicianId()] ?? null, $now);
        }

        $byTechnician = [];
        foreach ($profiles as $profile) {
            $profileShifts = $shiftsByTechnician[$profile->getId()] ?? [];
            $profileMetrics = $metrics[$profile->getId()] ?? [
                'intervention_seconds' => 0,
                'resolved_interventions_count' => 0,
            ];

            $row = $this->aggregateTechnician($profile, $profileShifts, $profileMetrics, $now);

            // RF-05.2: la plantilla activa aparece aunque no tenga actividad; el
            // técnico inactivo solo si trabajó en el periodo.
            if (!$profile->isActive()
                && $row['sessions_count'] === 0
                && $row['resolved_interventions_count'] === 0
            ) {
                continue;
            }

            $byTechnician[] = $row;
        }

        $orphanOpenShifts = [];
        foreach ($orphanShifts as $orphan) {
            $orphanOpenShifts[] = [
                'id' => (int)$orphan->id(),
                'technician_id' => $orphan->technicianId(),
                'technician_name' => $namesById[$orphan->technicianId()] ?? null,
                'shift_date' => $orphan->shiftDate(),
                'started_at' => $this->atom($orphan->startedAt()),
                'gross_seconds' => $orphan->grossDurationSeconds($now),
                'gross_formatted' => self::formatSeconds($orphan->grossDurationSeconds($now)),
            ];
        }

        $summary = $this->buildSummary($shifts, $metrics, $orphanShifts, $now);

        return new WorkHoursReportDto(
            filter: [
                'period' => $filter->getPeriodKey(),
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'technician_id' => $scopeTechnicianId,
                'timezone' => self::OPERATIONAL_TIMEZONE,
            ],
            summary: $summary,
            orphanOpenShifts: $orphanOpenShifts,
            byTechnician: $byTechnician,
            sessions: $sessions
        );
    }

    /**
     * Rectificación administrativa de un turno cerrado (contrato §5.3, RF-03).
     *
     * @throws ShiftAdjustmentValidationException
     */
    public function adjustShift(
        int $shiftId,
        string $startedAtRaw,
        string $closedAtRaw,
        int $mealBreakSeconds,
        int $coordinatorId,
        string $reason
    ): TechnicianShiftDto {
        $existing = $shiftId >= 1 ? $this->shiftRepo->findById($shiftId) : null;
        if ($existing === null) {
            throw new InvalidShiftTransitionException(
                "No se encontró el turno con ID {$shiftId}.",
                'SHIFT_NOT_FOUND',
                404
            );
        }

        return $this->transactions->runInTransaction(function () use (
            $shiftId,
            $existing,
            $startedAtRaw,
            $closedAtRaw,
            $mealBreakSeconds,
            $coordinatorId,
            $reason
        ): TechnicianShiftDto {
            // Cerrojo de aplicación por técnico: serializa la comprobación de
            // solapamiento con cualquier otra rectificación concurrente.
            if (!$this->lockRepo->lockTechnicianForUpdate($existing->technicianId())) {
                throw new UserNotFoundException(
                    $existing->technicianId(),
                    "No se encontró el técnico con ID {$existing->technicianId()}."
                );
            }

            $current = $this->shiftRepo->findById($shiftId) ?? $existing;

            // Precedencia del contrato §5.3: turno, estado, motivo, fechas.
            if ($current->isOpen()) {
                throw ShiftAdjustmentValidationException::shiftStillOpen();
            }

            $cleanReason = trim($reason);
            if (mb_strlen($cleanReason) < ShiftAdjustmentValidationException::MINIMUM_REASON_LENGTH) {
                throw ShiftAdjustmentValidationException::reasonTooShort();
            }

            $newStartedAt = $this->parseTimestamp($startedAtRaw);
            $newClosedAt = $this->parseTimestamp($closedAtRaw);

            // La validación cuádruple temporal vive en el dominio; la cuarta
            // regla (solapamiento) necesita el repositorio y va después.
            $adjusted = $current->adjust(
                $newStartedAt,
                $newClosedAt,
                $mealBreakSeconds,
                $coordinatorId,
                $cleanReason
            );

            $conflictId = $this->shiftRepo->findOverlappingShiftId(
                $current->technicianId(),
                $newStartedAt,
                $newClosedAt,
                $shiftId
            );
            if ($conflictId !== null) {
                throw ShiftAdjustmentValidationException::overlappingShift($conflictId);
            }

            $saved = $this->shiftRepo->saveAdjustment($adjusted);

            $this->shiftLogRepo->record(
                shiftId: $shiftId,
                technicianId: $saved->technicianId(),
                eventType: ShiftEventType::ADJUST_SHIFT,
                actorUserId: $coordinatorId,
                effectiveAt: $saved->adjustedAt() ?? new DateTimeImmutable('now'),
                previousState: $current->toArray(),
                newState: $saved->toArray(),
                reason: $saved->adjustmentReason()
            );

            $coordinator = $this->userRepo->findById($coordinatorId, false, true);
            $technician = $this->userRepo->findById($saved->technicianId(), false, true);

            $this->auditRepo->log(new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_USER,
                entityId: $saved->technicianId(),
                action: 'ADJUST_TECHNICIAN_SHIFT',
                userId: $coordinatorId,
                userRole: $coordinator?->getRole()->value ?? 'COORDINATOR',
                userName: $coordinator?->getName() ?? 'Coordinación',
                previousState: [
                    'started_at' => $this->atom($current->startedAt()),
                    'closed_at' => $this->atom($current->closedAt()),
                    'meal_break_seconds' => $current->mealBreakSeconds(),
                ],
                newState: [
                    'started_at' => $this->atom($saved->startedAt()),
                    'closed_at' => $this->atom($saved->closedAt()),
                    'meal_break_seconds' => $saved->mealBreakSeconds(),
                ],
                metadata: [
                    'technician_name' => $technician?->getName(),
                    'reason' => $saved->adjustmentReason(),
                ]
            ));

            return TechnicianShiftDto::fromEntity($saved, $technician?->getName());
        });
    }

    /**
     * Bitácora cronológica de una sesión de turno (contrato §5.4).
     *
     * @return list<array<string, mixed>>
     */
    public function getShiftLogs(int $shiftId): array
    {
        return array_map(
            static fn ($log): array => $log->toArray(),
            $this->shiftLogRepo->findByShiftId($shiftId)
        );
    }

    /**
     * Resumen y sesiones propias del mes en curso (contrato §5.6).
     *
     * El aislamiento por técnico es responsabilidad del controlador: este método
     * recibe el `user_id` del token y no acepta ningún otro alcance (Art. V.4).
     *
     * @return array<string, mixed>
     */
    public function getTechnicianOwnMonthlyHours(int $technicianId): array
    {
        $filter = MetricFilter::fromQueryParams(['period' => MetricFilter::PERIOD_CURRENT_MONTH]);
        $report = $this->getWorkHoursReport($filter, $technicianId);

        return [
            'month' => $filter->getFrom()->format('Y-m'),
            'from_date' => $report->filter()['from_date'],
            'to_date' => $report->filter()['to_date'],
            'summary' => $report->summary(),
            'sessions' => $report->sessions(),
        ];
    }

    /**
     * CSV oficial del registro de jornada (contrato §5.2, Resolución QA 5).
     *
     * UTF-8 BOM, separador `;` y dos secciones separadas por una fila en blanco:
     * el resumen por técnico y el detalle cronológico de sesiones en hora local.
     */
    public function exportWorkHoursCsv(MetricFilter $filter, ?int $technicianId = null): string
    {
        $report = $this->getWorkHoursReport($filter, $technicianId);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return MetricsExportService::UTF8_BOM;
        }

        fputcsv($handle, [
            'Técnico',
            'Estado en Plantilla',
            'Sesiones',
            'Horas Brutas',
            'Tiempo Comida',
            'Horas Efectivas Netas',
            'Tiempo en Intervención',
            'Tiempo en Ruta',
            'Intervenciones Resueltas',
            'Turnos Anómalos (>12h)',
            'Intervención Fuera de Turno',
        ], ';');

        foreach ($report->byTechnician() as $row) {
            fputcsv($handle, [
                (string)($row['technician_name'] ?? ''),
                ($row['is_active'] ?? false) ? 'Activo' : 'Inactivo',
                (string)$row['sessions_count'],
                (string)$row['gross_formatted'],
                (string)$row['meal_break_formatted'],
                (string)$row['effective_formatted'],
                (string)$row['intervention_formatted'],
                (string)$row['route_formatted'],
                (string)$row['resolved_interventions_count'],
                (string)$row['anomalous_sessions_count'],
                ($row['has_out_of_shift_interventions'] ?? false) ? 'Sí' : 'No',
            ], ';');
        }

        fputcsv($handle, [], ';');

        fputcsv($handle, [
            'ID Sesión',
            'Fecha Imputación',
            'Técnico',
            'Entrada Vigente',
            'Salida Vigente',
            'Duración Bruta',
            'Pausa Comida',
            'Horas Efectivas Netas',
            'Estado',
            'Cierre Forzado',
            'Ajustado por Coordinación',
            'Entrada Original Fichada',
            'Salida Original Fichada',
            'Motivo Cierre o Ajuste',
        ], ';');

        foreach ($report->sessions() as $session) {
            fputcsv($handle, [
                (string)$session['id'],
                (string)$session['shift_date'],
                (string)($session['technician_name'] ?? ''),
                $this->csvTimestamp($session['started_at'] ?? null),
                $this->csvTimestamp($session['closed_at'] ?? null),
                (string)$session['gross_formatted'],
                (string)$session['meal_break_formatted'],
                (string)$session['effective_formatted'],
                (string)$session['status_label'],
                ($session['is_force_closed'] ?? false) ? 'Sí' : 'No',
                ($session['is_adjusted'] ?? false) ? 'Sí' : 'No',
                $this->csvTimestamp($session['original_started_at'] ?? null),
                $this->csvTimestamp($session['original_closed_at'] ?? null),
                (string)($session['adjustment_reason'] ?? $session['close_reason'] ?? ''),
            ], ';');
        }

        rewind($handle);
        $csvBody = stream_get_contents($handle);
        fclose($handle);

        return MetricsExportService::UTF8_BOM . ($csvBody !== false ? $csvBody : '');
    }

    /**
     * @return list<User>
     */
    private function loadTechnicianProfiles(?int $scopeTechnicianId): array
    {
        // `false` incluye a los técnicos dados de baja lógica que el motor aún
        // conserva: RF-05.2 exige ver a quien tuvo actividad en el periodo.
        $profiles = $this->userRepo->findAllTechnicians(false);

        if ($scopeTechnicianId === null) {
            return $profiles;
        }

        $filtered = array_values(array_filter(
            $profiles,
            static fn (User $profile): bool => $profile->getId() === $scopeTechnicianId
        ));

        if ($filtered === []) {
            $explicit = $this->userRepo->findById($scopeTechnicianId, false, true);
            if ($explicit !== null) {
                $filtered[] = $explicit;
            }
        }

        return $filtered;
    }

    /**
     * @param list<TechnicianShift> $shifts
     * @param array{intervention_seconds: int, resolved_interventions_count: int} $metrics
     * @return array<string, mixed>
     */
    private function aggregateTechnician(
        User $profile,
        array $shifts,
        array $metrics,
        DateTimeImmutable $now
    ): array {
        $grossSeconds = 0;
        $mealBreakSeconds = 0;
        $effectiveSeconds = 0;
        $openSessions = 0;
        $anomalousSessions = 0;
        $adjustedSessions = 0;

        foreach ($shifts as $shift) {
            $grossSeconds += $shift->grossDurationSeconds($now);
            $mealBreakSeconds += $shift->totalMealBreakSeconds($now);
            $effectiveSeconds += $shift->effectiveWorkSeconds($now);

            if ($shift->isOpen()) {
                $openSessions++;
            }
            if ($shift->isAnomalousDuration($now)) {
                $anomalousSessions++;
            }
            if ($shift->isAdjusted()) {
                $adjustedSessions++;
            }
        }

        $interventionSeconds = $metrics['intervention_seconds'];
        $resolvedCount = $metrics['resolved_interventions_count'];
        $routeSeconds = max(0, $effectiveSeconds - $interventionSeconds);

        $sessionsCount = count($shifts);
        $hasOutOfShiftInterventions = $interventionSeconds > $effectiveSeconds
            || ($resolvedCount > 0 && $sessionsCount === 0);

        return [
            'technician_id' => $profile->getId(),
            'technician_name' => $profile->getName(),
            'operator_code' => null,
            'is_active' => $profile->isActive(),
            'sessions_count' => $sessionsCount,
            'open_sessions_count' => $openSessions,
            'anomalous_sessions_count' => $anomalousSessions,
            'adjusted_sessions_count' => $adjustedSessions,
            'gross_seconds' => $grossSeconds,
            'gross_formatted' => self::formatSeconds($grossSeconds),
            'meal_break_seconds' => $mealBreakSeconds,
            'meal_break_formatted' => self::formatSeconds($mealBreakSeconds),
            'effective_seconds' => $effectiveSeconds,
            'effective_formatted' => self::formatSeconds($effectiveSeconds),
            'intervention_seconds' => $interventionSeconds,
            'intervention_formatted' => self::formatSeconds($interventionSeconds),
            'route_seconds' => $routeSeconds,
            'route_formatted' => self::formatSeconds($routeSeconds),
            'resolved_interventions_count' => $resolvedCount,
            'has_out_of_shift_interventions' => $hasOutOfShiftInterventions,
        ];
    }

    /**
     * @param list<TechnicianShift> $shifts
     * @param array<int, array{intervention_seconds: int, resolved_interventions_count: int}> $metrics
     * @param list<TechnicianShift> $orphanShifts
     * @return array<string, int|string>
     */
    private function buildSummary(
        array $shifts,
        array $metrics,
        array $orphanShifts,
        DateTimeImmutable $now
    ): array {
        $grossSeconds = 0;
        $mealBreakSeconds = 0;
        $effectiveSeconds = 0;
        $openSessions = 0;
        $anomalousSessions = 0;
        $adjustedSessions = 0;

        foreach ($shifts as $shift) {
            $grossSeconds += $shift->grossDurationSeconds($now);
            $mealBreakSeconds += $shift->totalMealBreakSeconds($now);
            $effectiveSeconds += $shift->effectiveWorkSeconds($now);

            if ($shift->isOpen()) {
                $openSessions++;
            }
            if ($shift->isAnomalousDuration($now)) {
                $anomalousSessions++;
            }
            if ($shift->isAdjusted()) {
                $adjustedSessions++;
            }
        }

        $interventionSeconds = 0;
        $resolvedCount = 0;
        foreach ($metrics as $technicianMetrics) {
            $interventionSeconds += $technicianMetrics['intervention_seconds'];
            $resolvedCount += $technicianMetrics['resolved_interventions_count'];
        }

        $routeSeconds = max(0, $effectiveSeconds - $interventionSeconds);

        return [
            'total_sessions' => count($shifts),
            'open_sessions_count' => $openSessions,
            'orphan_open_sessions_count' => count($orphanShifts),
            'anomalous_sessions_count' => $anomalousSessions,
            'adjusted_sessions_count' => $adjustedSessions,
            'gross_seconds' => $grossSeconds,
            'gross_formatted' => self::formatSeconds($grossSeconds),
            'meal_break_seconds' => $mealBreakSeconds,
            'meal_break_formatted' => self::formatSeconds($mealBreakSeconds),
            'effective_seconds' => $effectiveSeconds,
            'effective_formatted' => self::formatSeconds($effectiveSeconds),
            'intervention_seconds' => $interventionSeconds,
            'intervention_formatted' => self::formatSeconds($interventionSeconds),
            'route_seconds' => $routeSeconds,
            'route_formatted' => self::formatSeconds($routeSeconds),
            'resolved_interventions_count' => $resolvedCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionToArray(TechnicianShift $shift, ?string $technicianName, DateTimeImmutable $now): array
    {
        $grossSeconds = $shift->grossDurationSeconds($now);
        $mealBreakSeconds = $shift->totalMealBreakSeconds($now);
        $effectiveSeconds = $shift->effectiveWorkSeconds($now);

        return [
            'id' => (int)$shift->id(),
            'technician_id' => $shift->technicianId(),
            'technician_name' => $technicianName,
            'shift_date' => $shift->shiftDate(),
            'status' => $shift->status()->value,
            'status_label' => $shift->status()->label(),
            'is_open' => $shift->isOpen(),
            'started_at' => $this->atom($shift->startedAt()),
            'closed_at' => $this->atom($shift->closedAt()),
            'gross_seconds' => $grossSeconds,
            'gross_formatted' => self::formatSeconds($grossSeconds),
            'meal_break_seconds' => $mealBreakSeconds,
            'meal_break_formatted' => self::formatSeconds($mealBreakSeconds),
            'effective_seconds' => $effectiveSeconds,
            'effective_formatted' => self::formatSeconds($effectiveSeconds),
            'is_anomalous' => $shift->isAnomalousDuration($now),
            'is_force_closed' => $shift->isForceClosed(),
            'is_adjusted' => $shift->isAdjusted(),
            'original_started_at' => $this->atom($shift->originalStartedAt()),
            'original_closed_at' => $this->atom($shift->originalClosedAt()),
            'original_meal_break_seconds' => $shift->originalMealBreakSeconds(),
            'close_reason' => $shift->closeReason(),
            'adjusted_at' => $this->atom($shift->adjustedAt()),
            'adjusted_by' => $shift->adjustedBy(),
            'adjustment_reason' => $shift->adjustmentReason(),
        ];
    }

    /**
     * @throws ShiftAdjustmentValidationException
     */
    private function parseTimestamp(string $raw): DateTimeImmutable
    {
        $clean = trim($raw);
        if ($clean === '') {
            throw new ShiftAdjustmentValidationException(
                'Las marcas horarias del turno son obligatorias.',
                self::INVALID_TIMESTAMPS_CODE,
                ShiftAdjustmentValidationException::UNPROCESSABLE_HTTP_STATUS
            );
        }

        try {
            return new DateTimeImmutable($clean);
        } catch (Exception) {
            throw new ShiftAdjustmentValidationException(
                'Las fechas del turno no tienen un formato válido.',
                self::INVALID_TIMESTAMPS_CODE,
                ShiftAdjustmentValidationException::UNPROCESSABLE_HTTP_STATUS
            );
        }
    }

    /** Instante en ISO 8601 con desfase operativo (`Europe/Madrid`). */
    private function atom(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone(self::OPERATIONAL_TIMEZONE))->format(DateTimeImmutable::ATOM);
    }

    /** Instante local de la exportación, sin desfase (contrato §5.2). */
    private function csvTimestamp(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone(self::OPERATIONAL_TIMEZONE))
            ->format('Y-m-d H:i');
    }

    /** Formato de duración del contrato: `HHh MMm`, con horas sin tope de 24. */
    private static function formatSeconds(int $seconds): string
    {
        $safeSeconds = max(0, $seconds);

        return sprintf('%02dh %02dm', intdiv($safeSeconds, 3600), intdiv($safeSeconds % 3600, 60));
    }
}
