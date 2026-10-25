<?php

declare(strict_types=1);

/**
 * TechnicianWorkHoursServiceTest · T-TIME-08
 *
 * Certifica el motor de cálculo del Módulo 22 sin base de datos:
 *
 *   1. Informe: presencia por técnico, cruce con intervenciones, tiempo en ruta
 *      con suelo cero, cobertura de plantilla activa, técnicos inactivos con
 *      actividad, turnos abiertos huérfanos y contadores de insignias.
 *   2. Rectificación: éxito auditado y los rechazos del contrato (turno abierto,
 *      motivo corto, formato inválido y solapamiento).
 *   3. Bitácora, resumen mensual propio y CSV con BOM, `;` y dos secciones.
 *
 * CATEGORÍA: hermética (sin motor).
 *
 * Uso:
 *   php tests/unit/TechnicianWorkHoursServiceTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, dobles en memoria, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\TechnicianWorkHoursService;
use VendGuard\Core\Domain\Exception\ShiftAdjustmentValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\Model\TechnicianShiftLog;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\ShiftEventType;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? ' · ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$expectCode = static function (string $what, string $expectedCode, callable $operation) use ($assert): void {
    try {
        $operation();
        $assert($what, false, 'la operación fue admitida');
    } catch (Throwable $exception) {
        $code = method_exists($exception, 'getErrorCode')
            ? $exception->getErrorCode()
            : get_class($exception);
        $assert($what, $code === $expectedCode, 'código=' . $code);
    }
};

echo "======================================================================\n";
echo " VendGuard · T-TIME-08 · TechnicianWorkHoursService (Unit)\n";
echo "======================================================================\n";

$madrid = new DateTimeZone('Europe/Madrid');
$at = static fn (string $local): DateTimeImmutable => new DateTimeImmutable($local, $madrid);

// ─────────────────────────────────────────────────────────────────────────
// Sujetos de prueba
// ─────────────────────────────────────────────────────────────────────────
$coordinator = new User(1, 'Laura Coordinadora', 'coord@vending.test', 'hash', UserRole::COORDINATOR);
$techOne = new User(2, 'Carlos Técnico', 'carlos@vending.test', 'hash', UserRole::TECHNICIAN);
$techTwo = new User(3, 'Marta Técnica', 'marta@vending.test', 'hash', UserRole::TECHNICIAN);
$techInactive = new User(4, 'Jordi Baja', 'jordi@vending.test', 'hash', UserRole::TECHNICIAN, isActive: false);
$techWithoutActivity = new User(5, 'Elena Sin Ruta', 'elena@vending.test', 'hash', UserRole::TECHNICIAN, isActive: false);

$closedShift = static fn (
    int $id,
    int $technicianId,
    string $start,
    string $end,
    int $mealSeconds,
    bool $adjusted = false
): TechnicianShift => new TechnicianShift(
    id: $id,
    technicianId: $technicianId,
    status: ShiftStatus::OFF_SHIFT,
    startedAt: $at($start),
    closedAt: $at($end),
    closedBy: $technicianId,
    shiftDate: substr($start, 0, 10),
    mealBreakSeconds: $mealSeconds,
    originalStartedAt: $at($start),
    originalClosedAt: $at($end),
    originalMealBreakSeconds: $mealSeconds,
    isAdjusted: $adjusted,
    adjustedAt: $adjusted ? $at($end) : null,
    adjustedBy: $adjusted ? 1 : null,
    adjustmentReason: $adjusted ? 'Rectificación de certificación del turno cerrado.' : null
);

$normalShift = $closedShift(10, 2, '2026-10-20 08:00:00', '2026-10-20 18:00:00', 3600);
$nightShift = $closedShift(11, 2, '2026-10-21 22:00:00', '2026-10-22 09:00:00', 0);
$anomalousShift = $closedShift(12, 2, '2026-10-22 06:00:00', '2026-10-22 20:00:00', 0, true);
$inactiveShift = $closedShift(13, 4, '2026-10-23 09:00:00', '2026-10-23 12:00:00', 0);
$openShift = new TechnicianShift(
    id: 14,
    technicianId: 2,
    status: ShiftStatus::ON_SHIFT,
    startedAt: (new DateTimeImmutable('now', $madrid))->modify('-1 hour'),
    shiftDate: (new DateTimeImmutable('now', $madrid))->format('Y-m-d'),
    originalStartedAt: (new DateTimeImmutable('now', $madrid))->modify('-1 hour')
);

$reportShifts = [$normalShift, $nightShift, $anomalousShift, $inactiveShift, $openShift];
$metrics = [
    2 => ['intervention_seconds' => 7200, 'resolved_interventions_count' => 5],
    3 => ['intervention_seconds' => 0, 'resolved_interventions_count' => 2],
    4 => ['intervention_seconds' => 100, 'resolved_interventions_count' => 1],
];

// ─────────────────────────────────────────────────────────────────────────
// Dobles en memoria
// ─────────────────────────────────────────────────────────────────────────
$shiftRepo = new class($reportShifts, $metrics) implements TechnicianShiftRepositoryInterface {
    /** @var array<int, TechnicianShift> */
    public array $byId = [];

    public ?int $conflictId = null;

    /** @var list<TechnicianShift> */
    public array $savedAdjustments = [];

    /**
     * @param list<TechnicianShift> $rangeShifts
     * @param array<int, array{intervention_seconds: int, resolved_interventions_count: int}> $metrics
     */
    public function __construct(public array $rangeShifts, public array $metrics)
    {
        foreach ($rangeShifts as $shift) {
            if ($shift->id() !== null) {
                $this->byId[(int)$shift->id()] = $shift;
            }
        }
    }

    public function findActiveShiftByTechnician(int $technicianId): ?TechnicianShift
    {
        foreach ($this->byId as $shift) {
            if ($shift->technicianId() === $technicianId && $shift->isOpen()) {
                return $shift;
            }
        }

        return null;
    }

    public function findById(int $shiftId): ?TechnicianShift
    {
        return $this->byId[$shiftId] ?? null;
    }

    public function startShift(int $technicianId, ?DateTimeImmutable $startedAt = null): TechnicianShift
    {
        throw new LogicException('unused');
    }

    public function pauseMeal(int $shiftId, ?DateTimeImmutable $pausedAt = null): TechnicianShift
    {
        throw new LogicException('unused');
    }

    public function resumeShift(int $shiftId, ?DateTimeImmutable $resumedAt = null): TechnicianShift
    {
        throw new LogicException('unused');
    }

    public function closeShift(
        int $shiftId,
        int $closedBy,
        ?string $closeReason = null,
        ?DateTimeImmutable $closedAt = null,
        bool $isForceClosed = false,
        ?DateTimeImmutable $executionTime = null
    ): TechnicianShift {
        throw new LogicException('unused');
    }

    public function saveAdjustment(TechnicianShift $adjustedShift): TechnicianShift
    {
        $this->savedAdjustments[] = $adjustedShift;
        $this->byId[(int)$adjustedShift->id()] = $adjustedShift;

        return $adjustedShift;
    }

    public function findOverlappingShiftId(
        int $technicianId,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $closedAt,
        ?int $excludeShiftId = null
    ): ?int {
        return $this->conflictId;
    }

    public function findShiftsByDateRange(string $fromDate, string $toDate, ?int $technicianId = null): array
    {
        if ($technicianId === null) {
            return $this->rangeShifts;
        }

        return array_values(array_filter(
            $this->rangeShifts,
            static fn (TechnicianShift $shift): bool => $shift->technicianId() === $technicianId
        ));
    }

    public function findOrphanOpenShiftsBefore(string $beforeDate, ?int $technicianId = null): array
    {
        return $technicianId === null || $technicianId === 2 ? [$this->byId[14]] : [];
    }

    public function getClosedInterventionMetricsByTechnician(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $technicianId = null
    ): array {
        if ($technicianId === null) {
            return $this->metrics;
        }

        return isset($this->metrics[$technicianId]) ? [$technicianId => $this->metrics[$technicianId]] : [];
    }

    public function getActiveShiftsMap(): array
    {
        return [];
    }
};

$logRepo = new class implements TechnicianShiftLogRepositoryInterface {
    /** @var list<TechnicianShiftLog> */
    public array $logs = [];

    public function record(
        int $shiftId,
        int $technicianId,
        ShiftEventType $eventType,
        int $actorUserId,
        DateTimeImmutable $effectiveAt,
        ?array $previousState,
        array $newState,
        ?string $reason = null
    ): TechnicianShiftLog {
        $log = new TechnicianShiftLog(
            id: count($this->logs) + 1,
            shiftId: $shiftId,
            technicianId: $technicianId,
            eventType: $eventType,
            actorUserId: $actorUserId,
            effectiveAt: $effectiveAt,
            previousState: $previousState,
            newState: $newState,
            reason: $reason
        );
        $this->logs[] = $log;

        return $log;
    }

    public function findByShiftId(int $shiftId): array
    {
        return array_values(array_filter(
            $this->logs,
            static fn (TechnicianShiftLog $log): bool => $log->shiftId() === $shiftId
        ));
    }
};

$userRepo = new class([$coordinator, $techOne, $techTwo, $techInactive, $techWithoutActivity]) implements UserRepositoryInterface {
    /** @param list<User> $users */
    public function __construct(public array $users)
    {
    }

    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        return null;
    }

    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        foreach ($this->users as $user) {
            if ($user->getId() === $id) {
                return $user;
            }
        }

        return null;
    }

    public function findAllTechnicians(bool $onlyActive = true): array
    {
        return array_values(array_filter(
            $this->users,
            static fn (User $user): bool => $user->getRole() === UserRole::TECHNICIAN
                && (!$onlyActive || $user->isActive())
        ));
    }

    public function create(array $data): User
    {
        throw new LogicException('unused');
    }

    public function update(int $id, array $data): bool
    {
        return false;
    }

    public function updatePassword(int $id, string $newPassword): bool
    {
        return false;
    }

    public function resetPassword(int $id, string $newPassword): bool
    {
        return false;
    }

    public function softDelete(int $id): bool
    {
        return false;
    }

    public function restore(int $id): bool
    {
        return false;
    }

    public function findAll(array $filters = []): array
    {
        return [];
    }

    public function countActiveByRole(UserRole|string $role): int
    {
        return 0;
    }

    public function countActiveAssignedIncidents(int $userId): int
    {
        return 0;
    }

    public function countPendingIncidents(int $technicianId): int
    {
        return 0;
    }
};

$auditRepo = new class implements AuditLogRepositoryInterface {
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
        return 0;
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return [];
    }
};

$transactions = new class implements TransactionManagerInterface {
    public function runInTransaction(callable $operation): mixed
    {
        return $operation();
    }
};

$lockRepo = new class implements TechnicianLockRepositoryInterface {
    public function lockTechnicianForUpdate(int $technicianId): bool
    {
        return true;
    }
};

$service = new TechnicianWorkHoursService(
    shiftRepo: $shiftRepo,
    shiftLogRepo: $logRepo,
    userRepo: $userRepo,
    transactions: $transactions,
    auditRepo: $auditRepo,
    lockRepo: $lockRepo
);

// ─────────────────────────────────────────────────────────────────────────
// 1. Informe de jornada
// ─────────────────────────────────────────────────────────────────────────
echo "\n1. Informe de jornada y horas efectivas\n";

$filter = new MetricFilter(
    MetricFilter::PERIOD_CURRENT_MONTH,
    $at('2026-10-01 00:00:00'),
    $at('2026-10-31 23:59:59')
);
$report = $service->getWorkHoursReport($filter);
$payload = $report->toArray();

$assert('El filtro publicado declara la zona operativa', $payload['filter']['timezone'] === 'Europe/Madrid');
$assert('El filtro publica el rango en fechas naturales', $payload['filter']['from_date'] === '2026-10-01' && $payload['filter']['to_date'] === '2026-10-31');
$assert('Se cuentan todas las sesiones del periodo', $payload['summary']['total_sessions'] === 5, 'total=' . $payload['summary']['total_sessions']);
$assert('Se cuenta la sesión abierta', $payload['summary']['open_sessions_count'] === 1);
$assert('Se cuenta el turno anómalo de más de 12 h', $payload['summary']['anomalous_sessions_count'] === 1);
$assert('Se cuenta la sesión ajustada', $payload['summary']['adjusted_sessions_count'] === 1);
$assert('Se cuenta el turno abierto huérfano', $payload['summary']['orphan_open_sessions_count'] === 1);
$assert('Las horas efectivas son brutas menos pausa', $payload['summary']['effective_seconds'] === $payload['summary']['gross_seconds'] - $payload['summary']['meal_break_seconds']);
$assert(
    'La intervención suma las métricas de todos los técnicos',
    $payload['summary']['intervention_seconds'] === 7300,
    'segundos=' . $payload['summary']['intervention_seconds']
);
$assert(
    'El tiempo en ruta nunca baja de cero',
    $payload['summary']['route_seconds'] === max(0, $payload['summary']['effective_seconds'] - 7300)
);
$assert('El resumen publica formatos HHh MMm', preg_match('/^\d{2,}h \d{2}m$/', (string)$payload['summary']['gross_formatted']) === 1);
$assert(
    'El turno nocturno se imputa a su fecha de apertura',
    $payload['sessions'][1]['shift_date'] === '2026-10-21',
    (string)$payload['sessions'][1]['shift_date']
);
$assert('La sesión abierta publica is_open', $payload['sessions'][4]['is_open'] === true);
$assert('La sesión de 14 h se marca anómala', $payload['sessions'][2]['is_anomalous'] === true);
$assert('La sesión ajustada conserva sus marcas originales', $payload['sessions'][2]['is_adjusted'] === true && $payload['sessions'][2]['original_started_at'] !== null);
$assert('El huérfano viaja con su técnico y fecha', $payload['orphan_open_shifts'][0]['technician_id'] === 2 && $payload['orphan_open_shifts'][0]['shift_date'] !== null);

$byTechnician = [];
foreach ($payload['by_technician'] as $row) {
    $byTechnician[$row['technician_id']] = $row;
}

$assert('La plantilla activa aparece aunque no tenga sesiones', isset($byTechnician[3]));
$assert('El técnico inactivo con actividad aparece', isset($byTechnician[4]));
$assert('El técnico inactivo sin actividad no aparece', !isset($byTechnician[5]));
$assert('El técnico con intervenciones y sin sesiones se marca fuera de turno', $byTechnician[3]['has_out_of_shift_interventions'] === true);
$assert('El técnico con actividad dentro de turno no se marca', $byTechnician[2]['has_out_of_shift_interventions'] === false);
$assert('El informe por técnico publica formatos HHh MMm', preg_match('/^\d{2,}h \d{2}m$/', (string)$byTechnician[2]['effective_formatted']) === 1);
$assert('operator_code viaja de forma estable', array_key_exists('operator_code', $byTechnician[2]));
$assert('Sin filtro de técnico no se recorta la plantilla', count($payload['by_technician']) === 3);

$scoped = $service->getWorkHoursReport($filter, 2);
$assert('El filtro por técnico recorta sesiones y filas', count($scoped->byTechnician()) === 1 && count($scoped->sessions()) === 4);

// ─────────────────────────────────────────────────────────────────────────
// 2. Rectificación auditada
// ─────────────────────────────────────────────────────────────────────────
echo "\n2. Rectificación auditada\n";

$targetShift = $closedShift(20, 2, '2026-10-24 08:00:00', '2026-10-24 17:00:00', 1800);
$shiftRepo->byId[20] = $targetShift;

$adjusted = $service->adjustShift(
    20,
    '2026-10-24T08:15:00+02:00',
    '2026-10-24T16:45:00+02:00',
    1200,
    1,
    'El técnico olvidó fichar su salida real y la notificó a primera hora.'
);

$assert('La rectificación devuelve el turno cerrado', $adjusted->status === 'OFF_SHIFT');
$assert('La rectificación mueve las marcas vigentes', $adjusted->startedAt === '2026-10-24T08:15:00+02:00' && $adjusted->mealBreakSeconds === 1200);
$assert('La rectificación conserva las marcas originales', $adjusted->originalStartedAt === '2026-10-24T08:00:00+02:00' && $adjusted->originalMealBreakSeconds === 1800);
$assert('La rectificación proyecta las métricas recalculadas', $adjusted->grossSeconds === 30600 && $adjusted->effectiveSeconds === 29400);
$assert('La rectificación queda marcada y atribuida', $adjusted->isAdjusted === true && $adjusted->adjustedBy === 1);
$assert('La rectificación se persiste a través del puerto', count($shiftRepo->savedAdjustments) === 1);
$assert(
    'La bitácora registra ADJUST_SHIFT con estado previo y motivo',
    count($logRepo->logs) === 1
        && $logRepo->logs[0]->eventType() === ShiftEventType::ADJUST_SHIFT
        && $logRepo->logs[0]->previousState() !== null
        && $logRepo->logs[0]->reason() !== null
);
$assert(
    'La auditoría registra ADJUST_TECHNICIAN_SHIFT',
    count($auditRepo->events) === 1 && $auditRepo->events[0]->getAction() === 'ADJUST_TECHNICIAN_SHIFT'
);

$openTargetShift = new TechnicianShift(
    id: 21,
    technicianId: 2,
    status: ShiftStatus::ON_SHIFT,
    startedAt: $at('2026-10-24 08:00:00'),
    shiftDate: '2026-10-24',
    originalStartedAt: $at('2026-10-24 08:00:00')
);
$shiftRepo->byId[21] = $openTargetShift;

$longReason = 'Rectificación con motivo suficientemente largo para el contrato.';

$expectCode(
    'Se rechaza rectificar un turno abierto',
    ShiftAdjustmentValidationException::SHIFT_STILL_OPEN_CODE,
    static fn () => $service->adjustShift(21, '2026-10-24T08:00:00+02:00', '2026-10-24T09:00:00+02:00', 0, 1, $longReason)
);
$expectCode(
    'Se rechaza una rectificación con motivo corto',
    ShiftAdjustmentValidationException::INVALID_ADJUSTMENT_REASON_CODE,
    static fn () => $service->adjustShift(20, '2026-10-24T08:00:00+02:00', '2026-10-24T09:00:00+02:00', 0, 1, 'corto')
);
$expectCode(
    'Se rechaza una marca horaria con formato inválido',
    'INVALID_SHIFT_TIMESTAMPS',
    static fn () => $service->adjustShift(20, 'no-es-una-fecha', '2026-10-24T09:00:00+02:00', 0, 1, $longReason)
);
$expectCode(
    'Se rechaza una rectificación en el futuro',
    ShiftAdjustmentValidationException::SHIFT_FUTURE_TIMESTAMP_CODE,
    static fn () => $service->adjustShift(20, '2026-10-24T08:00:00+02:00', '+2 days', 0, 1, $longReason)
);

$shiftRepo->conflictId = 99;
$expectCode(
    'Se rechaza una rectificación que solapa con otro turno',
    ShiftAdjustmentValidationException::SHIFT_OVERLAP_CONFLICT_CODE,
    static fn () => $service->adjustShift(20, '2026-10-24T08:00:00+02:00', '2026-10-24T16:00:00+02:00', 0, 1, $longReason)
);
$assert('El solapamiento no escribe una segunda rectificación', count($shiftRepo->savedAdjustments) === 1);
$shiftRepo->conflictId = null;

$expectCode(
    'Se rechaza un turno inexistente',
    'SHIFT_NOT_FOUND',
    static fn () => $service->adjustShift(999, '2026-10-24T08:00:00+02:00', '2026-10-24T09:00:00+02:00', 0, 1, $longReason)
);

// ─────────────────────────────────────────────────────────────────────────
// 3. Bitácora, horas propias y CSV
// ─────────────────────────────────────────────────────────────────────────
echo "\n3. Bitácora, resumen mensual propio y exportación CSV\n";

$logs = $service->getShiftLogs(20);
$assert('La bitácora de la sesión se publica como arrays', isset($logs[0]['event_type']) && $logs[0]['event_type'] === 'ADJUST_SHIFT');
$assert('La bitácora publica la etiqueta castellana del evento', ($logs[0]['event_type_label'] ?? '') === 'Rectificación horaria por Coordinación');

$own = $service->getTechnicianOwnMonthlyHours(2);
$assert('El resumen propio declara el mes en curso', ($own['month'] ?? '') === (new DateTimeImmutable('now', $madrid))->format('Y-m'));
$assert('El resumen propio solo incluye sesiones del técnico', count($own['sessions']) === 4);
$assert('El resumen propio publica totales del periodo', $own['summary']['total_sessions'] === 4);
$assert('El resumen propio no expone datos de otros técnicos', !array_key_exists('by_technician', $own));

$csv = $service->exportWorkHoursCsv($filter);
$assert('El CSV arranca con UTF-8 BOM', str_starts_with($csv, "\xEF\xBB\xBF"));
$assert('El CSV usa punto y coma como separador', substr_count($csv, ';') > 0);

$csvHandle = fopen('php://temp', 'r+');
fwrite($csvHandle, substr($csv, 3));
rewind($csvHandle);
$csvRows = [];
while (($csvRow = fgetcsv($csvHandle, 0, ';')) !== false) {
    $csvRows[] = $csvRow;
}
fclose($csvHandle);

$summaryHeader = $csvRows[0] ?? [];
$assert(
    'El CSV declara la cabecera de resumen por técnico',
    $summaryHeader === [
        'Técnico', 'Estado en Plantilla', 'Sesiones', 'Horas Brutas', 'Tiempo Comida',
        'Horas Efectivas Netas', 'Tiempo en Intervención', 'Tiempo en Ruta',
        'Intervenciones Resueltas', 'Turnos Anómalos (>12h)', 'Intervención Fuera de Turno',
    ],
    implode('|', $summaryHeader)
);

$detailIndex = null;
foreach ($csvRows as $index => $csvRow) {
    if (($csvRow[0] ?? null) === 'ID Sesión') {
        $detailIndex = $index;
        break;
    }
}

$assert('El CSV declara la cabecera de detalle de sesiones', $detailIndex !== null && ($csvRows[$detailIndex][1] ?? null) === 'Fecha Imputación');
$assert('El CSV separa las dos secciones con una fila en blanco', $detailIndex !== null && ($csvRows[$detailIndex - 1] ?? null) === [null]);
$assert('El CSV marca el estado en plantilla en castellano', in_array('Activo', array_column($csvRows, 1), true) && in_array('Inactivo', array_column($csvRows, 1), true));
$assert(
    'El CSV marca los cierres forzados y ajustes',
    $detailIndex !== null
        && in_array('Cierre Forzado', $csvRows[$detailIndex], true)
        && in_array('Ajustado por Coordinación', $csvRows[$detailIndex], true)
);
$assert('El CSV exporta las sesiones en hora local sin desfase', str_contains($csv, '2026-10-20 08:00'));

echo "\n======================================================================\n";
echo ' Total Aserciones: ' . $assertions . ' | Fallidas: ' . $failures . "\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
