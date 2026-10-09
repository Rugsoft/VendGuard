<?php

declare(strict_types=1);

/**
 * IncidentPauseSanitaryClockTest
 *
 * Suite unitaria del Algoritmo 4 del módulo 11 (tarea T-PAUSE-07): el Reloj
 * Sanitario Biológico y la cuarentena automática de máquinas de alimentos
 * perecederos (RF-03.4, RF-03.5, Constitución Art. II). Certifica:
 *
 * 1. El reloj sólo muerde en máquinas perecederas: bebidas y snacks siguen
 *    rigiéndose exclusivamente por el reloj contractual.
 * 2. El cómputo es natural y continuo 24/7 (4 horas = 14.400 segundos exactos),
 *    sin descuento de noches ni de fines de semana.
 * 3. Al alcanzar el umbral, la máquina pasa a `QUARANTINE` y se registra un evento
 *    de auditoría inmutable `SANITARY_QUARANTINE_AUTO_TRIGGERED` con la espera
 *    acumulada y la exigencia del checklist sanitario.
 * 4. La evaluación es idempotente y nunca dispara sobre averías liquidadas, de modo
 *    que una segunda vuelta no duplica ni la escritura ni el rastro.
 * 5. Sin tipología determinable o sin marca de apertura, el método se detiene con
 *    excepción en lugar de asumir un tipo de máquina (Fail-Fast, Art. II).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni red. El repositorio de
 * ajustes preventivos se sustituye por un doble en memoria y la auditoría se
 * captura con el `AuditLogger` real sobre un repositorio espía, de modo que la
 * suite verifica el evento tal y como se persiste, sin tocar MariaDB.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - IncidentPauseSanitaryClockTest (T-PAUSE-07)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

/** @return array{threw: bool, class: string, message: string} */
$capture = static function (callable $action): array {
    try {
        $action();
    } catch (Throwable $exception) {
        return ['threw' => true, 'class' => $exception::class, 'message' => $exception->getMessage()];
    }

    return ['threw' => false, 'class' => '', 'message' => ''];
};

/**
 * Doble en memoria del repositorio de ajustes preventivos (módulo 05).
 * Registra cada escritura de estado sanitario para poder certificar que la
 * cuarentena sólo se persiste cuando el reloj biológico la exige.
 */
final class SanitaryClockSettingsRepo implements PreventiveSettingsRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $machines = [];

    /** @var list<array{machine_id: int, status: string}> */
    public array $statusWrites = [];

    public bool $failWrites = false;

    public function register(int $machineId, string $machineType, string $sanitaryStatus = 'OK'): void
    {
        $this->machines[$machineId] = [
            'machine_id' => $machineId,
            'machine_code' => sprintf('VEND-%04d', $machineId),
            'machine_type' => $machineType,
            'sanitary_status' => $sanitaryStatus,
            'sanitary_frequency_days' => 15,
            'default_frequency_days' => 15,
            'is_seasonal_pause' => false,
        ];
    }

    public function findAll(): array
    {
        return [];
    }

    public function findByMachineType(string $machineType): ?PreventiveSetting
    {
        return null;
    }

    public function updateTypeSettings(
        string $machineType,
        int $defaultFrequencyDays,
        int $maxAllowedDays,
        int $advanceWarningDays = 5
    ): bool {
        return true;
    }

    public function updateMachineConfig(
        int $machineId,
        ?int $sanitaryFrequencyDays,
        ?string $nextSanitaryInspectionDue = null
    ): bool {
        return true;
    }

    public function setSeasonalPause(int $machineId, string $reason, ?string $pauseUntil = null): bool
    {
        return true;
    }

    public function resumeSeasonalPause(int $machineId): bool
    {
        return true;
    }

    public function getMachineSettings(int $machineId): ?array
    {
        return $this->machines[$machineId] ?? null;
    }

    public function updateSanitaryStatus(int $machineId, string $status): bool
    {
        if ($this->failWrites) {
            return false;
        }

        $this->statusWrites[] = ['machine_id' => $machineId, 'status' => $status];
        if (isset($this->machines[$machineId])) {
            $this->machines[$machineId]['sanitary_status'] = $status;
        }

        return true;
    }
}

/** Repositorio de auditoría espía: captura los eventos tal y como los emite el logger real. */
final class SanitaryClockAuditRepo implements AuditLogRepositoryInterface
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
        return $this->events;
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

$madrid = new DateTimeZone('Europe/Madrid');

/** Marca temporal de apertura expresada en la zona operativa de la sede. */
$agoInSeconds = static function (int $seconds) use ($madrid): string {
    return (new DateTimeImmutable('now', $madrid))->modify(sprintf('-%d seconds', $seconds))->format('Y-m-d H:i:s');
};

$makeIncident = static function (
    IncidentStatus $status,
    ?string $createdAt,
    ?string $machineType = 'PERISHABLE_FOOD',
    int $machineId = 10
): Incident {
    return new Incident(
        id: 142,
        ticketCode: 'INC-2026-00142',
        machineId: $machineId,
        locationId: 1,
        category: IncidentCategory::TEMPERATURE_COLD,
        description: 'Pérdida de frío: cámara sin refrigeración y producto fresco expuesto al público.',
        urgency: UrgencyLevel::CRITICAL,
        status: $status,
        assignedTechnicianId: 7,
        assignedAt: '2026-10-08 09:00:00',
        machineType: $machineType,
        createdAt: $createdAt
    );
};

$makeService = static function (
    SanitaryClockSettingsRepo $settings,
    SanitaryClockAuditRepo $auditRepo
): IncidentPauseService {
    return new IncidentPauseService($settings, new AuditLogger($auditRepo));
};

// =====================================================================
// GRUPO 1: Sólo las máquinas perecederas soportan el reloj biológico
// =====================================================================
echo "--- Grupo 1: Ámbito del reloj biológico (sólo perecederos) ---\n";

foreach ([['SNACKS', 'snacks'], ['COLD_DRINKS', 'bebidas frías'], ['HOT_DRINKS', 'bebidas calientes']] as [$type, $label]) {
    $settings = new SanitaryClockSettingsRepo();
    $settings->register(10, $type);
    $auditRepo = new SanitaryClockAuditRepo();
    $service = $makeService($settings, $auditRepo);

    $result = $service->evaluateSanitaryBiologicalClock(
        $makeIncident(IncidentStatus::IN_PROGRESS, $agoInSeconds(36000))
    );

    $assert(
        "1.x Una máquina de {$label} jamás entra en cuarentena por este reloj (10 h de avería)",
        $result === false && $settings->statusWrites === [] && $auditRepo->events === [],
        sprintf('resultado=%s, escrituras=%d, eventos=%d', var_export($result, true), count($settings->statusWrites), count($auditRepo->events))
    );
}

// =====================================================================
// GRUPO 2: Umbral exacto de 4 horas naturales (14.400 s)
// =====================================================================
echo "\n--- Grupo 2: Umbral legal de 4 horas naturales ---\n";

$assert(
    '2.1 El umbral del reloj biológico son 14.400 segundos exactos (4 horas)',
    IncidentPauseService::SANITARY_BIOLOGICAL_CLOCK_SECONDS === 14400,
    (string)IncidentPauseService::SANITARY_BIOLOGICAL_CLOCK_SECONDS
);

$settings = new SanitaryClockSettingsRepo();
$settings->register(10, 'PERISHABLE_FOOD');
$auditRepo = new SanitaryClockAuditRepo();
$service = $makeService($settings, $auditRepo);

$belowThreshold = $service->evaluateSanitaryBiologicalClock(
    $makeIncident(IncidentStatus::PENDING_INFO, $agoInSeconds(14300))
);
$assert(
    '2.2 Un perecedero con 3 h 58 min de avería todavía no se pone en cuarentena',
    $belowThreshold === false && $settings->statusWrites === [] && $auditRepo->events === [],
    sprintf('resultado=%s, escrituras=%d', var_export($belowThreshold, true), count($settings->statusWrites))
);

$assert(
    '2.3 El reloj es natural: no descuenta noches ni fines de semana (mismo umbral que un día laborable)',
    IncidentPauseService::SANITARY_BIOLOGICAL_CLOCK_SECONDS * 1 === 14400
    && (new DateTimeImmutable('2026-10-11 23:00:00', $madrid))->format('N') === '7'
);

// =====================================================================
// GRUPO 3: Disparo de la cuarentena y evento inmutable (Art. II)
// =====================================================================
echo "\n--- Grupo 3: Disparo de la cuarentena automática ---\n";

$settings = new SanitaryClockSettingsRepo();
$settings->register(10, 'PERISHABLE_FOOD', 'OK');
$auditRepo = new SanitaryClockAuditRepo();
$service = $makeService($settings, $auditRepo);

$triggered = $service->evaluateSanitaryBiologicalClock(
    $makeIncident(IncidentStatus::PENDING_INFO, $agoInSeconds(18000))
);

$assert(
    '3.1 Un perecedero con 5 h naturales sin frío devuelve true',
    $triggered === true,
    var_export($triggered, true)
);

$assert(
    '3.2 La máquina queda persistida en QUARANTINE',
    ($settings->machines[10]['sanitary_status'] ?? null) === 'QUARANTINE',
    (string)($settings->machines[10]['sanitary_status'] ?? 'sin estado')
);

$assert(
    '3.3 Se escribió exactamente una transición de estado sanitario, para la máquina correcta',
    $settings->statusWrites === [['machine_id' => 10, 'status' => 'QUARANTINE']],
    json_encode($settings->statusWrites, JSON_UNESCAPED_UNICODE)
);

$assert(
    '3.4 Se registró exactamente un evento de auditoría inmutable',
    count($auditRepo->events) === 1,
    sprintf('%d eventos', count($auditRepo->events))
);

$event = $auditRepo->events[0] ?? null;

$assert(
    '3.5 El evento es SANITARY_QUARANTINE_AUTO_TRIGGERED sobre la máquina auditada',
    $event instanceof AuditEvent
    && $event->getAction() === IncidentPauseService::ACTION_SANITARY_QUARANTINE_AUTO_TRIGGERED
    && $event->getAction() === 'SANITARY_QUARANTINE_AUTO_TRIGGERED'
    && $event->getEntityType() === 'MACHINE'
    && $event->getEntityId() === 10,
    $event instanceof AuditEvent
        ? sprintf('%s / %s / %d', $event->getAction(), $event->getEntityType(), $event->getEntityId())
        : 'sin evento'
);

$assert(
    '3.6 El estado anterior y el nuevo viajan en el evento (OK -> QUARANTINE)',
    $event !== null
    && ($event->getPreviousState()['sanitary_status'] ?? null) === 'OK'
    && ($event->getNewState()['sanitary_status'] ?? null) === 'QUARANTINE'
);

$assert(
    '3.7 El evento declara exigible el checklist sanitario antes de devolver la máquina al servicio',
    $event !== null && ($event->getNewState()['sanitary_checklist_required'] ?? null) === true
);

$metadata = $event?->getMetadata() ?? [];
$assert(
    '3.8 El evento enlaza la incidencia, el ticket y el umbral constitucional',
    ($metadata['incident_id'] ?? null) === 142
    && ($metadata['ticket_code'] ?? null) === 'INC-2026-00142'
    && ($metadata['machine_id'] ?? null) === 10
    && ($metadata['threshold_seconds'] ?? null) === 14400
);

$assert(
    '3.9 La espera natural acumulada queda auditada en segundos (5 h ≈ 18.000 s)',
    isset($metadata['elapsed_natural_seconds'])
    && $metadata['elapsed_natural_seconds'] >= 18000
    && $metadata['elapsed_natural_seconds'] <= 18120,
    (string)($metadata['elapsed_natural_seconds'] ?? 'sin dato')
);

$assert(
    '3.10 El motivo del evento cita el artículo constitucional que lo ordena',
    is_string($metadata['reason'] ?? null) && str_contains($metadata['reason'], 'Art. II')
);

$assert(
    '3.11 El actor del evento es el propio sistema (sin usuario humano detrás)',
    $event !== null && $event->getUserId() === null && $event->getUserRole() === 'SYSTEM'
);

// =====================================================================
// GRUPO 4: Idempotencia y averías ya liquidadas
// =====================================================================
echo "\n--- Grupo 4: Idempotencia y averías liquidadas ---\n";

$secondEvaluation = $service->evaluateSanitaryBiologicalClock(
    $makeIncident(IncidentStatus::PENDING_INFO, $agoInSeconds(18000))
);
$assert(
    '4.1 Reevaluar la misma máquina no duplica la escritura ni el rastro de auditoría',
    $secondEvaluation === true
    && count($settings->statusWrites) === 1
    && count($auditRepo->events) === 1,
    sprintf('escrituras=%d, eventos=%d', count($settings->statusWrites), count($auditRepo->events))
);

$alreadyQuarantinedSettings = new SanitaryClockSettingsRepo();
$alreadyQuarantinedSettings->register(10, 'PERISHABLE_FOOD', 'QUARANTINE');
$alreadyQuarantinedAudit = new SanitaryClockAuditRepo();
$alreadyQuarantinedService = $makeService($alreadyQuarantinedSettings, $alreadyQuarantinedAudit);

$assert(
    '4.2 Una máquina ya en cuarentena se confirma sin volver a escribir ni auditar',
    $alreadyQuarantinedService->evaluateSanitaryBiologicalClock(
        $makeIncident(IncidentStatus::PENDING_INFO, $agoInSeconds(18000))
    ) === true
    && $alreadyQuarantinedSettings->statusWrites === []
    && $alreadyQuarantinedAudit->events === []
);

foreach ([
    ['RESOLVED', IncidentStatus::RESOLVED],
    ['CLOSED', IncidentStatus::CLOSED],
    ['CANCELLED', IncidentStatus::CANCELLED],
] as [$label, $settledStatus]) {
    $settledSettings = new SanitaryClockSettingsRepo();
    $settledSettings->register(10, 'PERISHABLE_FOOD');
    $settledAuditRepo = new SanitaryClockAuditRepo();
    $settledService = $makeService($settledSettings, $settledAuditRepo);

    $settledResult = $settledService->evaluateSanitaryBiologicalClock(
        $makeIncident($settledStatus, $agoInSeconds(36000))
    );

    $assert(
        "4.x Un expediente {$label} con 10 h de antigüedad no dispara la cuarentena automática",
        $settledResult === false && $settledSettings->statusWrites === [] && $settledAuditRepo->events === [],
        sprintf('resultado=%s, escrituras=%d', var_export($settledResult, true), count($settledSettings->statusWrites))
    );
}

// =====================================================================
// GRUPO 5: Resolución de tipología y validación Fail-Fast
// =====================================================================
echo "\n--- Grupo 5: Resolución de tipología y Fail-Fast ---\n";

$noSettings = new SanitaryClockSettingsRepo();
$fallbackAudit = new SanitaryClockAuditRepo();
$fallbackService = $makeService($noSettings, $fallbackAudit);

$assert(
    '5.1 Sin ficha de máquina se usa la tipología enriquecida del expediente y el reloj sigue mordiendo',
    $fallbackService->evaluateSanitaryBiologicalClock(
        $makeIncident(IncidentStatus::IN_PROGRESS, $agoInSeconds(18000), 'PERISHABLE_FOOD')
    ) === true
    && count($fallbackAudit->events) === 1
);

$unknownType = $capture(static fn () => $fallbackService->evaluateSanitaryBiologicalClock(
    $makeIncident(IncidentStatus::IN_PROGRESS, $agoInSeconds(18000), null)
));
$assert(
    '5.2 Sin tipología resoluble el método se detiene en lugar de asumir que es perecedera',
    $unknownType['threw'] === true && $unknownType['class'] === DomainException::class,
    $unknownType['message']
);

$noOpening = $capture(static fn () => $service->evaluateSanitaryBiologicalClock(
    $makeIncident(IncidentStatus::IN_PROGRESS, null)
));
$assert(
    '5.3 Sin marca de apertura no hay reloj que evaluar y la omisión se declara con excepción',
    $noOpening['threw'] === true && $noOpening['class'] === DomainException::class,
    $noOpening['message']
);

$normalizedSettings = new SanitaryClockSettingsRepo();
$normalizedSettings->register(10, '  perishable_food  ');
$normalizedAudit = new SanitaryClockAuditRepo();
$normalizedService = $makeService($normalizedSettings, $normalizedAudit);
$assert(
    '5.4 La tipología se normaliza antes de compararla (mayúsculas y espacios)',
    $normalizedService->evaluateSanitaryBiologicalClock(
        $makeIncident(IncidentStatus::IN_PROGRESS, $agoInSeconds(18000))
    ) === true
);

$writeFailureSettings = new SanitaryClockSettingsRepo();
$writeFailureSettings->register(10, 'PERISHABLE_FOOD');
$writeFailureSettings->failWrites = true;
$writeFailureService = $makeService($writeFailureSettings, new SanitaryClockAuditRepo());
$writeFailure = $capture(static fn () => $writeFailureService->evaluateSanitaryBiologicalClock(
    $makeIncident(IncidentStatus::IN_PROGRESS, $agoInSeconds(18000))
));
$assert(
    '5.5 Si la cuarentena no se puede persistir se lanza excepción en lugar de fingir que se activó',
    $writeFailure['threw'] === true && $writeFailure['class'] === RuntimeException::class,
    $writeFailure['message']
);

// =====================================================================
// GRUPO 6: Contrato de vocabulario de auditoría con la interfaz
// =====================================================================
echo "\n--- Grupo 6: Contrato de vocabulario de auditoría ---\n";

$assert(
    '6.1 El estado sanitario declarado es el literal del esquema (QUARANTINE)',
    IncidentPauseService::SANITARY_STATUS_QUARANTINE === 'QUARANTINE'
);

$catalogSource = (string)file_get_contents(
    dirname(__DIR__, 2) . '/public/assets/js/utils/AuditActionLabels.js'
);
$assert(
    '6.2 El nombre canónico de la acción coincide con el literal que se audita (sin deriva)',
    IncidentPauseService::ACTION_SANITARY_QUARANTINE_AUTO_TRIGGERED === 'SANITARY_QUARANTINE_AUTO_TRIGGERED'
);

$assert(
    '6.3 El catálogo del visor de auditoría conoce la acción nueva (guarda bidireccional)',
    str_contains($catalogSource, "code: 'SANITARY_QUARANTINE_AUTO_TRIGGERED'"),
    'Falta la entrada SANITARY_QUARANTINE_AUTO_TRIGGERED en AuditActionLabels.js'
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-07 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
