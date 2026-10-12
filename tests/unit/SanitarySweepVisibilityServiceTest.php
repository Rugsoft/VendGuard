<?php

declare(strict_types=1);

/**
 * SanitarySweepVisibilityServiceTest
 *
 * Suite unitaria de la **superficie operativa de la contención del barrido
 * sanitario** (T-PAUSE-41, RF-07.1 a RF-07.5, Constitución Art. II y Art. III).
 * Certifica las dos piezas nuevas sin tocar la base de datos:
 *
 * 1. **La política del escalado** (`SanitarySweepEscalationPolicy`): única
 *    autoridad de *cuándo* un aplazamiento deja de ser operación normal —3
 *    pasadas consecutivas, o más de 5 aplazamientos en una pasada— y de *cuándo*
 *    esa alerta se apaga sola. Incluye el ciclo de episodios: la detección
 *    continúa una racha, sella el escalado UNA vez y abre un episodio nuevo
 *    conservando el acumulado histórico.
 * 2. **El caso de uso de la superficie** (`SanitarySweepVisibilityService`): es
 *    de **sólo lectura** —lo certifica un doble que registra cada llamada de
 *    escritura y no recibe ninguna—, compone el payload canónico con la
 *    ventana de tendencia, distingue «nunca ha corrido» de «ha dejado de
 *    latir», mide todas las antigüedades contra un instante de referencia
 *    ÚNICO y no reimplementa ni un umbral: los pide a la política.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni base de datos. El
 * puerto del libro de pasadas se sustituye por un doble en memoria; la política
 * y el servicio son los reales.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\DTO\SanitarySweepEscalationDto;
use VendGuard\Application\DTO\SanitarySweepVisibilityDto;
use VendGuard\Application\DTO\SanitarySweepWindowDto;
use VendGuard\Application\Service\SanitarySweepVisibilityService;
use VendGuard\Core\Domain\Model\SanitarySweepContention;
use VendGuard\Core\Domain\Model\SanitarySweepPass;
use VendGuard\Core\Domain\Repository\SanitarySweepVisibilityRepositoryInterface;
use VendGuard\Core\Service\SanitarySweepEscalationPolicy;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - SanitarySweepVisibilityServiceTest (T-PAUSE-41)\n";
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
 * Doble del libro de pasadas que **cuenta las escrituras**: la superficie es de
 * sólo lectura y este doble lo delata. Las lecturas devuelven exactamente lo que
 * la suite le entrega.
 */
final class VisibilityBookDouble implements SanitarySweepVisibilityRepositoryInterface
{
    /** @var list<SanitarySweepPass> */
    public array $passes = [];

    /** @var list<SanitarySweepContention> */
    public array $openContentions = [];

    /** Escrituras observadas: si alguna ocurre, la superficie dejó de ser de sólo lectura. */
    public array $writes = [];

    public function recordPass(SanitarySweepPass $pass): SanitarySweepPass
    {
        $this->writes[] = 'recordPass';

        return $pass;
    }

    public function findContentionByIncident(int $incidentId): ?SanitarySweepContention
    {
        $this->writes[] = 'findContentionByIncident';

        return null;
    }

    public function saveContentionDetection(SanitarySweepContention $contention): void
    {
        $this->writes[] = 'saveContentionDetection';
    }

    public function resolveContentionsNotDetectedIn(array $detectedIncidentIds, DateTimeImmutable $resolvedAt): int
    {
        $this->writes[] = 'resolveContentionsNotDetectedIn';

        return 0;
    }

    public function findOpenContentions(): array
    {
        return $this->openContentions;
    }

    public function findLatestPass(): ?SanitarySweepPass
    {
        return $this->passes === [] ? null : $this->passes[count($this->passes) - 1];
    }

    public function findPassesSince(DateTimeImmutable $since): array
    {
        $from = $since->format('Y-m-d H:i:s');

        return array_values(array_filter(
            $this->passes,
            static fn (SanitarySweepPass $pass): bool => $pass->startedAt >= $from
        ));
    }
}

$madrid = new DateTimeZone('Europe/Madrid');
$reference = new DateTimeImmutable('2026-10-12 18:20:00', $madrid);

/** Marca `Y-m-d H:i:s` desplazada respecto al instante de referencia de la suite. */
$ago = static function (int $minutes) use ($reference): string {
    return $reference->modify(sprintf('-%d minutes', $minutes))->format('Y-m-d H:i:s');
};

$makePass = static function (
    string $startedAt,
    int $candidates = 4,
    int $evaluated = 2,
    int $quarantined = 1,
    int $contended = 0,
    int $failures = 0,
    float $durationMs = 812.34
): SanitarySweepPass {
    return new SanitarySweepPass(
        id: null,
        startedAt: $startedAt,
        finishedAt: $startedAt,
        candidates: $candidates,
        evaluated: $evaluated,
        quarantinedCount: $quarantined,
        contendedCount: $contended,
        failuresCount: $failures,
        durationMs: $durationMs,
    );
};

$makeContention = static function (
    int $incidentId,
    int $machineId,
    string $firstDetectedAt,
    string $lastDetectedAt,
    int $consecutivePasses,
    int $totalPasses,
    ?string $escalatedAt = null,
    ?string $resolvedAt = null
): SanitarySweepContention {
    return new SanitarySweepContention(
        id: $incidentId,
        incidentId: $incidentId,
        machineId: $machineId,
        firstDetectedAt: $firstDetectedAt,
        lastDetectedAt: $lastDetectedAt,
        consecutivePasses: $consecutivePasses,
        totalPasses: $totalPasses,
        escalatedAt: $escalatedAt,
        resolvedAt: $resolvedAt,
        ticketCode: sprintf('INC-2026-%05d', $incidentId),
        machineCode: sprintf('VEND-%04d', $machineId),
        locationName: 'Hospital del Mar',
        floorWing: 'Planta Baja - Urgencias',
        incidentStatus: 'IN_PROGRESS',
        machineSanitaryStatus: 'OK',
    );
};

$makeBench = static function (): array {
    $book = new VisibilityBookDouble();

    return [
        'book' => $book,
        'service' => new SanitarySweepVisibilityService($book, new SanitarySweepEscalationPolicy()),
        'policy' => new SanitarySweepEscalationPolicy(),
    ];
};

// =====================================================================
// GRUPO 1: La regla del escalado (política pura)
// =====================================================================
echo "--- Grupo 1: La regla del escalado ---\n";

$policy = new SanitarySweepEscalationPolicy();

$assert(
    '1.1 Los umbrales ratificados son los de la Capa D: 3 pasadas consecutivas y más de 5 aplazamientos por pasada',
    SanitarySweepEscalationPolicy::CONSECUTIVE_PASSES_THRESHOLD === 3
    && SanitarySweepEscalationPolicy::PER_PASS_THRESHOLD === 5
    && SanitarySweepEscalationPolicy::CADENCE_MINUTES === 15
);

$assert(
    '1.2 Dos pasadas consecutivas no escalan y la tercera sí (el umbral muerde exactamente en 3)',
    $policy->shouldEscalate(1) === false
    && $policy->shouldEscalate(2) === false
    && $policy->shouldEscalate(3) === true
    && $policy->shouldEscalate(9) === true
);

$assert(
    '1.3 El umbral de parque es estricto: cinco aplazamientos no son escalado de parque, seis sí',
    $policy->isFleetEscalation(0) === false
    && $policy->isFleetEscalation(5) === false
    && $policy->isFleetEscalation(6) === true
);

// 1.4 El nivel lo decide la política y la superficie no vuelve a derivarlo: un epsodio escalado
// o una última pasada por encima del umbral de parque elevan la alerta aunque no haya nada más.
$assert(
    '1.4 El nivel sube a ESCALATED con un episodio escalado o con la última pasada por encima del umbral de parque',
    $policy->level(2, 1, false) === SanitarySweepEscalationPolicy::LEVEL_ESCALATED
    && $policy->level(0, 0, true) === SanitarySweepEscalationPolicy::LEVEL_ESCALATED
    && $policy->level(1, 0, false) === SanitarySweepEscalationPolicy::LEVEL_MONITORED
    && $policy->level(0, 0, false) === SanitarySweepEscalationPolicy::LEVEL_CLEAR
);

$assert(
    '1.5 `requiresAttention` es autoridad única: sólo el nivel ESCALATED la enciende',
    $policy->requiresAttention(SanitarySweepEscalationPolicy::LEVEL_ESCALATED) === true
    && $policy->requiresAttention(SanitarySweepEscalationPolicy::LEVEL_MONITORED) === false
    && $policy->requiresAttention(SanitarySweepEscalationPolicy::LEVEL_CLEAR) === false
);

$assert(
    '1.6 Cada nivel tiene su copy castellano en la política (la interfaz no traduce en dos sitios)',
    $policy->levelLabel(SanitarySweepEscalationPolicy::LEVEL_ESCALATED) !== ''
    && str_contains($policy->levelLabel(SanitarySweepEscalationPolicy::LEVEL_ESCALATED), 'Contención persistente')
    && str_contains($policy->levelLabel(SanitarySweepEscalationPolicy::LEVEL_MONITORED), 'operación normal')
    && str_contains($policy->levelLabel(SanitarySweepEscalationPolicy::LEVEL_CLEAR), 'Sin aplazamientos')
);

$assert(
    '1.7 El latido se declara perdido pasadas más de dos cadencias (nunca con `null`: eso es «no ha corrido»)',
    $policy->isSweepStale(null) === false
    && $policy->isSweepStale(30) === false
    && $policy->isSweepStale(31) === true
);

// =====================================================================
// GRUPO 2: Ciclo de episodios del seguimiento (política pura)
// =====================================================================
echo "\n--- Grupo 2: Ciclo de episodios del seguimiento ---\n";

$first = $policy->nextDetection(null, 142, 14, $reference);
$second = $policy->nextDetection($first, 142, 14, $reference->modify('+15 minutes'));
$third = $policy->nextDetection($second, 142, 14, $reference->modify('+30 minutes'));
$fourth = $policy->nextDetection($third, 142, 14, $reference->modify('+45 minutes'));

$assert(
    '2.1 La primera detección abre el episodio: una pasada, sin escalado y abierto',
    $first->consecutivePasses === 1
    && $first->totalPasses === 1
    && $first->escalatedAt === null
    && $first->resolvedAt === null
    && $first->firstDetectedAt === $reference->format('Y-m-d H:i:s')
    && $first->machineId === 14
    && $first->incidentId === 142
);

$assert(
    '2.2 La racha acumula pasadas consecutivas y sella el escalado una sola vez, a la tercera',
    $second->consecutivePasses === 2
    && $second->isEscalated() === false
    && $third->consecutivePasses === 3
    && $third->isEscalated() === true
    && $third->escalatedAt === $reference->modify('+30 minutes')->format('Y-m-d H:i:s')
    && $fourth->consecutivePasses === 4
    && $fourth->escalatedAt === $third->escalatedAt
    && $fourth->firstDetectedAt === $first->firstDetectedAt
    && $fourth->totalPasses === 4
);

// 2.3 Un episodio cerrado no se reabre: el aplazamiento posterior arranca uno nuevo con el
// episodio a 1 y el acumulado histórico intacto, y las marcas del anterior quedan limpias.
$closed = new SanitarySweepContention(
    id: 9,
    incidentId: 142,
    machineId: 14,
    firstDetectedAt: $ago(120),
    lastDetectedAt: $ago(60),
    consecutivePasses: 3,
    totalPasses: 3,
    escalatedAt: $ago(90),
    resolvedAt: $ago(45),
);
$reopened = $policy->nextDetection($closed, 142, 14, $reference);

$assert(
    '2.3 Un episodio cerrado reabre uno nuevo (contadores del episodio a 1) sin perder el acumulado',
    $reopened->consecutivePasses === 1
    && $reopened->totalPasses === 4
    && $reopened->isEscalated() === false
    && $reopened->escalatedAt === null
    && $reopened->resolvedAt === null
    && $reopened->firstDetectedAt === $reference->format('Y-m-d H:i:s')
    && $reopened->machineId === 14
);

$badIncident = $capture(static fn () => $policy->nextDetection(null, 0, 14, $reference));
$assert(
    '2.4 Un seguimiento sin expediente válido se rechaza en lugar de crear una fila huérfana (Art. III)',
    $badIncident['threw'] === true && $badIncident['class'] === InvalidArgumentException::class,
    $badIncident['message']
);

// =====================================================================
// GRUPO 3: Superficie vacía y contrato del payload
// =====================================================================
echo "\n--- Grupo 3: Superficie vacía y contrato del payload ---\n";

$bench = $makeBench();
$empty = $bench['service']->snapshot($reference);
$payload = $empty->toArray();

$assert(
    '3.1 Un parque sin libro publica CLEAR, sin latido y sin antigüedad: «no ha corrido» no es «está escalado»',
    $empty instanceof SanitarySweepVisibilityDto
    && $payload['escalation']['level'] === 'CLEAR'
    && $payload['escalation']['requires_attention'] === false
    && $payload['sweep']['last_pass'] === null
    && $payload['sweep']['minutes_since_last_pass'] === null
    && $payload['sweep']['is_stale'] === false
    && $payload['contentions'] === []
    && $empty->requiresAttention() === false,
    json_encode($payload, JSON_UNESCAPED_UNICODE)
);

$assert(
    '3.2 El payload publica exactamente las claves del contrato, en orden, sin campos extra',
    array_keys($payload) === ['generated_at', 'cadence_minutes', 'escalation', 'sweep', 'contentions']
    && array_keys($payload['sweep']) === [
        'last_pass',
        'minutes_since_last_pass',
        'is_stale',
        'window_hours',
        'passes_in_window',
        'passes_with_contention',
        'deferrals_in_window',
        'failures_in_window',
        'quarantines_in_window',
        'contention_rate_percent',
    ]
    && array_keys($payload['escalation']) === [
        'level',
        'requires_attention',
        'consecutive_passes_threshold',
        'per_pass_threshold',
        'open_contentions',
        'escalated_contentions',
        'fleet_escalation',
        'level_label',
    ],
    json_encode(array_keys($payload), JSON_UNESCAPED_UNICODE)
);

$assert(
    '3.3 La ventana de tendencia declarada es de 24 horas y la cadencia ratificada viaja en la respuesta',
    SanitarySweepVisibilityService::TREND_WINDOW_HOURS === 24
    && $payload['sweep']['window_hours'] === 24
    && $payload['cadence_minutes'] === SanitarySweepEscalationPolicy::CADENCE_MINUTES
    && $payload['escalation']['consecutive_passes_threshold'] === 3
    && $payload['escalation']['per_pass_threshold'] === 5,
    json_encode($payload['sweep'], JSON_UNESCAPED_UNICODE)
);

$assert(
    '3.4 La superficie es de SÓLO lectura: compone la respuesta sin una sola llamada de escritura',
    $bench['book']->writes === [],
    json_encode($bench['book']->writes, JSON_UNESCAPED_UNICODE)
);

// =====================================================================
// GRUPO 4: Latido del barrido
// =====================================================================
echo "\n--- Grupo 4: Latido del barrido ---\n";

$bench = $makeBench();
$bench['book']->passes = [$makePass($ago(5))];
$fresh = $bench['service']->snapshot($reference);

$assert(
    '4.1 Un barrido que acaba de latir publica sus minutos y no se declara parado',
    $fresh->minutesSinceLastPass === 5
    && $fresh->isStale === false
    && $fresh->lastPass?->contendedCount === 0
    && $fresh->toArray()['sweep']['last_pass']['pass_id'] === null
    && $fresh->toArray()['sweep']['passes_in_window'] === 1,
    json_encode($fresh->toArray()['sweep'], JSON_UNESCAPED_UNICODE)
);

$bench = $makeBench();
$bench['book']->passes = [$makePass($ago(31))];
$quiet = $bench['service']->snapshot($reference);

$assert(
    '4.2 Más de dos cadencias sin latir encienden `is_stale`: la salvaguarda no está corriendo',
    $quiet->isStale === true
    && $quiet->minutesSinceLastPass === 31
    && $quiet->toArray()['sweep']['is_stale'] === true
);

// 4.3 Una pasada interrumpida (sin cierre) sigue siendo un latido real: la superficie la publica
// como tal en lugar de fingir que el barrido no corrió.
$interrupted = new SanitarySweepPass(
    id: 3,
    startedAt: $ago(10),
    finishedAt: null,
    candidates: 2,
    evaluated: 1,
    quarantinedCount: 1,
    contendedCount: 1,
    failuresCount: 0,
    durationMs: 4.5,
);

$bench = $makeBench();
$bench['book']->passes = [$interrupted];
$interruptedView = $bench['service']->snapshot($reference);
$interruptedPayload = $interruptedView->toArray()['sweep']['last_pass'];

$assert(
    '4.3 Una pasada sin cierre se publica como latido con su cierre nulo, sin inventar la marca',
    $interruptedView->lastPass?->isInterrupted() === true
    && $interruptedPayload['finished_at'] === null
    && $interruptedPayload['pass_id'] === 3
    && $interruptedPayload['contended_count'] === 1
    && $interruptedPayload['duration_ms'] === 4.5,
    json_encode($interruptedPayload, JSON_UNESCAPED_UNICODE)
);

// =====================================================================
// GRUPO 5: Tendencia de la ventana de 24 h
// =====================================================================
echo "\n--- Grupo 5: Tendencia de la ventana ---\n";

$bench = $makeBench();
$bench['book']->passes = [
    $makePass($ago(1500), contended: 7),   // fuera de la ventana (25 h): no puede contar
    $makePass($ago(1400), contended: 9),   // 23 h 20 min: dentro
    $makePass($ago(600), contended: 2),
    $makePass($ago(300)),
    $makePass($ago(15), failures: 1, quarantined: 1),
];
$window = $bench['service']->snapshot($reference);
$windowPayload = $window->toArray()['sweep'];

$assert(
    '5.1 La ventana agrega las últimas 24 h y deja fuera lo anterior (ni sus aplazamientos ni sus pasadas cuentan)',
    $windowPayload['passes_in_window'] === 4
    && $windowPayload['passes_with_contention'] === 2
    && $windowPayload['deferrals_in_window'] === 11
    && $windowPayload['failures_in_window'] === 1
    && $windowPayload['quarantines_in_window'] === 4
    && $windowPayload['contention_rate_percent'] === 50.0,
    json_encode($windowPayload, JSON_UNESCAPED_UNICODE)
);

// 5.2 El corte de la ventana lo aplica el puerto, no el servicio: se le pide exactamente el
// instante de referencia menos las horas declaradas, y el servicio no re-filtra por su cuenta.
$bench = $makeBench();
$recordingBook = new class implements SanitarySweepVisibilityRepositoryInterface {
    /** @var list<string> */
    public array $since = [];

    public function recordPass(SanitarySweepPass $pass): SanitarySweepPass
    {
        return $pass;
    }

    public function findContentionByIncident(int $incidentId): ?SanitarySweepContention
    {
        return null;
    }

    public function saveContentionDetection(SanitarySweepContention $contention): void
    {
    }

    public function resolveContentionsNotDetectedIn(array $detectedIncidentIds, DateTimeImmutable $resolvedAt): int
    {
        return 0;
    }

    public function findOpenContentions(): array
    {
        return [];
    }

    public function findLatestPass(): ?SanitarySweepPass
    {
        return null;
    }

    public function findPassesSince(DateTimeImmutable $since): array
    {
        $this->since[] = $since->format('Y-m-d H:i:s');

        return [];
    }
};

(new SanitarySweepVisibilityService($recordingBook, new SanitarySweepEscalationPolicy()))->snapshot($reference);

$assert(
    '5.2 La ventana se pide al libro con el corte exacto (referencia − 24 h) y sin segunda pasada',
    $recordingBook->since === [$reference->modify('-24 hours')->format('Y-m-d H:i:s')],
    json_encode($recordingBook->since, JSON_UNESCAPED_UNICODE)
);

$zeroPasses = SanitarySweepWindowDto::ratePercent(0, 0);
$assert(
    '5.3 Una ventana sin pasadas no divide por cero: su tasa es 0 y el contrato la admite',
    $zeroPasses === 0.0
    && (new SanitarySweepWindowDto(24, 0, 0, 0, 0, 0, $zeroPasses))->toArray()['contention_rate_percent'] === 0.0
);

// =====================================================================
// GRUPO 6: Escalado del aviso
// =====================================================================
echo "\n--- Grupo 6: Escalado del aviso ---\n";

// 6.1 Un aplazamiento vivo que no ha alcanzado el umbral se observa sin alarmar: la contención
// no es un problema por sí misma (T-PAUSE-39), sólo lo es cuando persiste.
$bench = $makeBench();
$bench['book']->passes = [$makePass($ago(5), contended: 1)];
$bench['book']->openContentions = [$makeContention(142, 14, $ago(20), $ago(5), 2, 2)];
$monitored = $bench['service']->snapshot($reference);
$monitoredPayload = $monitored->toArray();

$assert(
    '6.1 Un aplazamiento vivo sin umbral alcanzado queda en MONITORED, sin encender la alerta',
    $monitoredPayload['escalation']['level'] === 'MONITORED'
    && $monitoredPayload['escalation']['requires_attention'] === false
    && $monitoredPayload['escalation']['open_contentions'] === 1
    && $monitoredPayload['escalation']['escalated_contentions'] === 0
    && $monitored->requiresAttention() === false,
    json_encode($monitoredPayload['escalation'], JSON_UNESCAPED_UNICODE)
);

// 6.2 El expediente que alcanzó las tres pasadas consecutivas eleva la superficie a ESCALATED y
// la respuesta lo publica con su marca, sin que la interfaz tenga que derivar la regla.
$bench = $makeBench();
$bench['book']->passes = [$makePass($ago(5), contended: 1)];
$bench['book']->openContentions = [
    $makeContention(142, 14, $ago(20), $ago(5), 2, 2),
    $makeContention(143, 15, $ago(65), $ago(5), 5, 5, $ago(40)),
];
$escalated = $bench['service']->snapshot($reference);
$escalatedPayload = $escalated->toArray();

$assert(
    '6.2 Un episodio escalado eleva el aviso a ESCALATED con su marca y su recuento',
    $escalatedPayload['escalation']['level'] === 'ESCALATED'
    && $escalatedPayload['escalation']['requires_attention'] === true
    && $escalatedPayload['escalation']['open_contentions'] === 2
    && $escalatedPayload['escalation']['escalated_contentions'] === 1
    && $escalated->requiresAttention() === true
    && $escalatedPayload['escalation']['level_label'] !== '',
    json_encode($escalatedPayload['escalation'], JSON_UNESCAPED_UNICODE)
);

$row = $escalatedPayload['contentions'][1];
$assert(
    '6.3 La fila escalada publica sus antigüedades medidas contra el instante de referencia ÚNICO',
    $row['ticket_code'] === 'INC-2026-00143'
    && $row['machine_code'] === 'VEND-0015'
    && $row['location_name'] === 'Hospital del Mar'
    && $row['floor_wing'] === 'Planta Baja - Urgencias'
    && $row['incident_status'] === 'IN_PROGRESS'
    && $row['machine_sanitary_status'] === 'OK'
    && $row['consecutive_passes'] === 5
    && $row['total_passes'] === 5
    && $row['escalated'] === true
    && $row['age_minutes'] === 65
    && $row['minutes_since_last_detection'] === 5,
    json_encode($row, JSON_UNESCAPED_UNICODE)
);

// 6.4 Sin ningún seguimiento abierto, una última pasada por encima del umbral de parque también
// escala: es la señal de un escritor reteniendo filas de varias máquinas a la vez.
$bench = $makeBench();
$bench['book']->passes = [$makePass($ago(5), contended: 6)];
$fleet = $bench['service']->snapshot($reference);
$fleetPayload = $fleet->toArray();

$assert(
    '6.4 Una última pasada por encima del umbral de parque escala sin seguimientos abiertos',
    $fleetPayload['escalation']['fleet_escalation'] === true
    && $fleetPayload['escalation']['level'] === 'ESCALATED'
    && $fleetPayload['escalation']['open_contentions'] === 0
    && $fleetPayload['escalation']['escalated_contentions'] === 0
    && str_contains($fleetPayload['escalation']['level_label'], 'Contención persistente'),
    json_encode($fleetPayload['escalation'], JSON_UNESCAPED_UNICODE)
);

$bench = $makeBench();
$bench['book']->passes = [$makePass($ago(5), contended: 5)];
$borderline = $bench['service']->snapshot($reference);

$assert(
    '6.5 El umbral de parque no se alcanza con cinco aplazamientos (escalado de verdad, sin ruido)',
    $borderline->toArray()['escalation']['fleet_escalation'] === false
    && $borderline->toArray()['escalation']['level'] === 'CLEAR'
);

// =====================================================================
// GRUPO 7: Autoridad única de la regla y guardas del contrato
// =====================================================================
echo "\n--- Grupo 7: Autoridad única y guardas del contrato ---\n";

$serviceSource = (string)file_get_contents(
    dirname(__DIR__, 2) . '/src/Application/Service/SanitarySweepVisibilityService.php'
);
$policySource = (string)file_get_contents(
    dirname(__DIR__, 2) . '/src/Core/Service/SanitarySweepEscalationPolicy.php'
);

$assert(
    '7.1 El servicio no reimplementa el escalado: lo delega en la política, que es su única autoridad',
    str_contains($serviceSource, 'SanitarySweepEscalationPolicy')
    && str_contains($serviceSource, '->level(')
    && str_contains($serviceSource, '->isFleetEscalation(')
    && !str_contains($serviceSource, '>= 3')
    && !str_contains($serviceSource, '> 5'),
    'La superficie debe preguntar a la política, no contar por su cuenta'
);

$assert(
    '7.2 El servicio no deriva los umbrales: los lee de las constantes de la política',
    substr_count($serviceSource, 'SanitarySweepEscalationPolicy::') >= 3
    && str_contains($serviceSource, 'SanitarySweepEscalationPolicy::CONSECUTIVE_PASSES_THRESHOLD')
    && str_contains($serviceSource, 'SanitarySweepEscalationPolicy::PER_PASS_THRESHOLD')
    && str_contains($serviceSource, 'SanitarySweepEscalationPolicy::CADENCE_MINUTES')
    && !str_contains($serviceSource, 'INSERT')
    && !str_contains($serviceSource, 'UPDATE'),
    'Los umbrales viven sólo en la política y la superficie no escribe SQL'
);

$assert(
    '7.3 La política es pura: sin dependencias de infraestructura ni de base de datos',
    str_contains($policySource, 'final class SanitarySweepEscalationPolicy')
    && !str_contains($policySource, 'PDO')
    && !str_contains($policySource, 'use VendGuard\\Infrastructure'),
    'La regla del escalado no puede depender de la base de datos'
);

$bench = $makeBench();
$badCadence = $capture(static fn () => new SanitarySweepVisibilityDto(
    generatedAt: $reference->format('Y-m-d H:i:s'),
    cadenceMinutes: 0,
    escalation: new SanitarySweepEscalationDto('CLEAR', false, 3, 5, 0, 0, false, 'Sin aplazamientos'),
    lastPass: null,
    minutesSinceLastPass: null,
    isStale: false,
    window: new SanitarySweepWindowDto(24, 0, 0, 0, 0, 0, 0.0),
    contentions: [],
));

$assert(
    '7.4 Una cadencia no positiva se rechaza: sin cadencia no hay latido que medir',
    $badCadence['threw'] === true && $badCadence['class'] === InvalidArgumentException::class,
    $badCadence['message']
);

$bench = $makeBench();
$badHeartbeat = $capture(static fn () => new SanitarySweepVisibilityDto(
    generatedAt: $reference->format('Y-m-d H:i:s'),
    cadenceMinutes: 15,
    escalation: new SanitarySweepEscalationDto('CLEAR', false, 3, 5, 0, 0, false, 'Sin aplazamientos'),
    lastPass: null,
    minutesSinceLastPass: 12,
    isStale: false,
    window: new SanitarySweepWindowDto(24, 0, 0, 0, 0, 0, 0.0),
    contentions: [],
));

$assert(
    '7.5 No se puede declarar antigüedad sin pasada: «nunca corrió» y «dejó de latir» no se mezclan',
    $badHeartbeat['threw'] === true && $badHeartbeat['class'] === InvalidArgumentException::class,
    $badHeartbeat['message']
);

$badLevel = $capture(static fn () => new SanitarySweepEscalationDto('PANIC', true, 3, 5, 0, 0, false, 'x'));
$badCounts = $capture(static fn () => new SanitarySweepEscalationDto('ESCALATED', true, 3, 5, 1, 2, false, 'x'));
$badRate = $capture(static fn () => new SanitarySweepWindowDto(24, 1, 2, 0, 0, 0, 0.0));

$assert(
    '7.6 Los bloques del payload rechazan niveles fuera del catálogo y recuentos incoherentes',
    $badLevel['threw'] === true
    && $badCounts['threw'] === true
    && $badRate['threw'] === true
    && $badLevel['class'] === InvalidArgumentException::class
    && $badCounts['class'] === InvalidArgumentException::class
    && $badRate['class'] === InvalidArgumentException::class,
    json_encode([$badLevel['message'], $badCounts['message'], $badRate['message']], JSON_UNESCAPED_UNICODE)
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-41 (UNIDAD) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
