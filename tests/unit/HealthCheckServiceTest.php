<?php

declare(strict_types=1);

/**
 * HealthCheckServiceTest — certificación del caso de uso del diagnóstico de enlace
 * (T-HYBRID-19 · RF-07, EARS-07.1 a EARS-07.4, RNF-04).
 *
 * QUÉ CERTIFICA, Y POR QUÉ ASÍ
 *
 * El servicio ya se ejercitaba de punta a punta por HTTP en
 * [`HealthEndpointTest.php`](../integration/HealthEndpointTest.php) (46 aserciones). Aquí se
 * certifica **la unidad**, que es otra cosa: lo que el servicio hace cuando el motor responde,
 * cuando responde tarde, cuando revienta, y qué es exactamente lo que publica en cada caso.
 *
 *   1. **La sonda toca una tabla real del dominio (EARS-03.2).** No basta con leer la constante:
 *      se intercepta la conexión y se mira **el texto que viaja** al motor. Una verificación que
 *      no toca ninguna tabla no registra actividad efectiva ante el proveedor, y un `SELECT 1`
 *      sintético se leería como «todo bien» sin haber demostrado nada. Se certifica además que la
 *      ida y vuelta se **consume** (`fetchColumn`), porque una consulta lanzada y no leída deja la
 *      verificación a medias.
 *
 *   2. **La latencia se mide y decide estado (EARS-07.1).** El presupuesto es de 1,0 s y su borde
 *      se certifica con el reloj real: una respuesta que tarda más se publica como `DEGRADED` con
 *      motivo `SLOW_RESPONSE`, y una respuesta normal se publica como `HEALTHY` **sin** motivo, que
 *      es el invariante del DTO.
 *
 *   3. **La proyección pública no filtra arquitectura (EARS-07.2, Art. V.4 y Art. VI.1).** La
 *      proyección por defecto es exactamente `status` y `timestamp_utc`, y —lo que de verdad
 *      protege— `json_encode($dto)` produce esa misma proyección: un `json_encode` despistado en
 *      cualquier controlador futuro **no puede** publicar el motor, el agrupador ni la latencia.
 *
 *   4. **Ningún fallo puede publicar credenciales (EARS-07.4).** Se provocan fallos cuyos textos
 *      llevan DSN, host, usuario y contraseña dentro, y se comprueba que **nada** de eso aparece en
 *      la respuesta serializada, ni en la pública ni en la detallada. El motivo viaja como etiqueta
 *      de conjunto cerrado deducida del **tipo** de la excepción, nunca de su texto.
 *
 * QUÉ NO SE CERTIFICA AQUÍ (declarado, no omitido)
 *
 * La traducción del endpoint a códigos `200`/`503` y la decisión de credencial que elige la
 * proyección pertenecen a `HealthEndpointTest.php` (T-HYBRID-14), que las certifica por el
 * enrutador real. Esta suite no las duplica: certifica el servicio que ese controlador invoca.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas ni bundlers.
 * Dualismo Lingüístico: identificadores y código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\DTO\HealthCheckResponseDto;
use VendGuard\Application\Service\HealthCheckService;
use VendGuard\Core\Domain\Model\SanitarySweepContention;
use VendGuard\Core\Domain\Model\SanitarySweepPass;
use VendGuard\Core\Domain\Repository\SanitarySweepVisibilityRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

// =====================================================================
// Arnés mínimo de aserciones (mismo contrato que el resto de la batería)
// =====================================================================

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - HealthCheckServiceTest (T-HYBRID-19)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }
    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
    $failures++;
};

// =====================================================================
// Dobles: sentencia, conexión y libro de pasadas
// =====================================================================

/**
 * Sentencia de mentira: sólo necesita responder a lo que el servicio consume de verdad
 * (`fetchColumn`), y contar cuántas veces se ha leído: una consulta lanzada y no consumida deja
 * la verificación a medias, así que «se consumió» es parte de lo que se certifica.
 */
final class FakeProbeStatement extends PDOStatement
{
    public int $fetchColumnCalls = 0;

    public function __construct(private mixed $value = 1)
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $this->fetchColumnCalls++;

        return $this->value;
    }
}

/**
 * Conexión de mentira con el comportamiento que cada caso necesita: registrar el SQL que viaja,
 * responder una fila, devolver `false` (sin excepción y sin resultado), tardar de más o reventar.
 */
final class FakeProbeConnection extends PDO
{
    /** @var list<string> */
    public array $executedQueries = [];

    public ?FakeProbeStatement $lastStatement = null;

    public function __construct(
        private string $driver = ConnectionFactory::DRIVER_PGSQL,
        private string $behavior = 'ok',
        private int $sleepMicroseconds = 0,
        private ?Throwable $failure = null,
    ) {
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? $this->driver : null;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->executedQueries[] = $query;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->sleepMicroseconds > 0) {
            usleep($this->sleepMicroseconds);
        }

        if ($this->behavior === 'refuse') {
            return false;
        }

        $this->lastStatement = new FakeProbeStatement(1);

        return $this->lastStatement;
    }
}

/**
 * Libro de pasadas de mentira: cuenta las lecturas y puede devolver una pasada o reventar.
 * Es lo que permite certificar cuándo el servicio **lee** la señal del vigilante y qué publica
 * cuando no puede leerla, sin depender del contenido del libro real.
 */
final class FakeSweepBook implements SanitarySweepVisibilityRepositoryInterface
{
    public int $findLatestPassCalls = 0;

    public function __construct(
        private ?SanitarySweepPass $latestPass = null,
        private ?Throwable $failure = null,
    ) {
    }

    public function findLatestPass(): ?SanitarySweepPass
    {
        $this->findLatestPassCalls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->latestPass;
    }

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

    /** @return list<SanitarySweepContention> */
    public function findOpenContentions(): array
    {
        return [];
    }

    /** @return list<SanitarySweepPass> */
    public function findPassesSince(DateTimeImmutable $since): array
    {
        return [];
    }
}

/** Pasada del barrido de mentira, cerrada y sin fallos: el desenlace que no alerta. */
$healthyPass = new SanitarySweepPass(
    id: 7,
    startedAt: '2026-10-14 10:00:00+00:00',
    finishedAt: '2026-10-14 10:00:04+00:00',
    candidates: 3,
    evaluated: 3,
    quarantinedCount: 0,
    contendedCount: 0,
    failuresCount: 0,
    durationMs: 4120.0,
);

$utcInstantPattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

// =====================================================================
// GRUPO 1 — La sonda toca una tabla real del dominio (EARS-03.2)
// =====================================================================

echo "--- Grupo 1: la sonda consulta una tabla real, y se consume ---\n";

$assert(
    '1.1 La sonda declarada es un recuento sobre la tabla maestra del parque',
    str_contains(HealthCheckService::PROBE_SQL, 'machines')
        && stripos(HealthCheckService::PROBE_SQL, 'count(') !== false,
    HealthCheckService::PROBE_SQL
);

$assert(
    '1.2 La sonda no es un SELECT sintético ni una constante de sistema',
    !preg_match('/select\s+1\b/i', HealthCheckService::PROBE_SQL) === true
        && !str_contains(HealthCheckService::PROBE_SQL, 'current_setting')
        && !str_contains(HealthCheckService::PROBE_SQL, 'SELECT DATABASE'),
    HealthCheckService::PROBE_SQL
);

$recordingConnection = new FakeProbeConnection();
$recorded = (new HealthCheckService($recordingConnection, new FakeSweepBook()))->check(false);

$assert(
    '1.3 El SQL que viaja de verdad al motor es exactamente la sonda declarada',
    $recordingConnection->executedQueries === [HealthCheckService::PROBE_SQL],
    json_encode($recordingConnection->executedQueries)
);

$assert(
    '1.4 El SQL que viaja nombra la tabla del DDL versionado',
    str_contains($recordingConnection->executedQueries[0] ?? '', 'machines')
);

$assert(
    '1.5 La verificación consume la fila: sin lectura, la ida y vuelta queda a medias',
    $recordingConnection->lastStatement instanceof FakeProbeStatement
        && $recordingConnection->lastStatement->fetchColumnCalls === 1
);

$assert(
    '1.6 Una sonda lanzada y consumida se publica sana y sin motivo',
    $recorded->status === HealthCheckResponseDto::STATUS_HEALTHY
        && $recorded->failureReason === null
        && $recorded->isAvailable() === true
);

$refusingConnection = new FakeProbeConnection(behavior: 'refuse');
$refused = (new HealthCheckService($refusingConnection, new FakeSweepBook()))->check(false);

$assert(
    '1.7 Un `query()` que devuelve `false` (sin excepción y sin resultado) no se interpreta como enlace sano',
    $refused->status === HealthCheckResponseDto::STATUS_UNHEALTHY
        && $refused->failureReason === HealthCheckService::REASON_QUERY_FAILED
        && $refused->isAvailable() === false
);

// =====================================================================
// GRUPO 2 — Latencia medida y su borde (EARS-07.1)
// =====================================================================

echo "\n--- Grupo 2: la latencia se mide y decide estado ---\n";

$assert(
    '2.1 El presupuesto declarado es el de EARS-07.1 (1,0 s)',
    HealthCheckService::RESPONSE_BUDGET_MS === 1000.0
);

$liveService = new HealthCheckService(ConnectionFactory::getConnection());
$liveDetails = $liveService->check(true);

$assert(
    '2.2 La latencia es un número medido, no una constante ni un cero de relleno',
    is_float($liveDetails->responseTimeMs) && $liveDetails->responseTimeMs >= 0.0
        && $liveDetails->responseTimeMs < HealthCheckService::RESPONSE_BUDGET_MS,
    sprintf('latencia: %s ms', var_export($liveDetails->responseTimeMs, true))
);

$assert(
    '2.3 Una verificación dentro del presupuesto se publica sana y sin motivo',
    $liveDetails->status === HealthCheckResponseDto::STATUS_HEALTHY
        && $liveDetails->failureReason === null
        && $liveDetails->isAvailable() === true,
    sprintf('estado: %s · motivo: %s', $liveDetails->status, var_export($liveDetails->failureReason, true))
);

$slowService = new HealthCheckService(new FakeProbeConnection(sleepMicroseconds: 1_050_000), new FakeSweepBook());
$slow = $slowService->check(false);

$assert(
    '2.4 Una respuesta por encima del presupuesto se degrada con motivo SLOW_RESPONSE',
    $slow->status === HealthCheckResponseDto::STATUS_DEGRADED
        && $slow->failureReason === HealthCheckService::REASON_SLOW_RESPONSE,
    sprintf('estado: %s · motivo: %s', $slow->status, var_export($slow->failureReason, true))
);

$assert(
    '2.5 Degradado no es caído: el enlace sigue disponible para el monitor (200, no 503)',
    $slow->isAvailable() === true && $slow->responseTimeMs > HealthCheckService::RESPONSE_BUDGET_MS,
    sprintf('latencia medida: %s ms', var_export($slow->responseTimeMs, true))
);

// =====================================================================
// GRUPO 3 — Proyección pública frente a telemetría (EARS-07.2, EARS-07.3)
// =====================================================================

echo "\n--- Grupo 3: proyección pública mínima y telemetría que hay que pedir ---\n";

$publicProjection = $liveService->check(false)->toArray(false);

$assert(
    '3.1 La proyección pública declara exactamente `status` y `timestamp_utc`',
    array_keys($publicProjection) === ['status', 'timestamp_utc'],
    json_encode(array_keys($publicProjection))
);

$assert(
    '3.2 El instante viaja en ISO-8601 UTC, que es lo que compara el vigilante externo',
    preg_match($utcInstantPattern, (string)$publicProjection['timestamp_utc']) === 1,
    (string)$publicProjection['timestamp_utc']
);

$encodedDto = json_encode($liveService->check(false));

$assert(
    '3.3 Un `json_encode` despistado del DTO no puede publicar telemetría (lo seguro es el defecto)',
    $encodedDto === json_encode($publicProjection, JSON_UNESCAPED_UNICODE)
        && !str_contains((string)$encodedDto, 'database_driver')
        && !str_contains((string)$encodedDto, 'response_time_ms')
        && !str_contains((string)$encodedDto, 'sanitary_clock'),
    (string)$encodedDto
);

$detailedProjection = $liveService->check(true)->toArray(true);

$assert(
    '3.4 La telemetría detallada es aditiva: conserva las dos claves públicas',
    array_key_exists('status', $detailedProjection) && array_key_exists('timestamp_utc', $detailedProjection)
);

$assert(
    '3.5 La telemetría publica el motor, el agrupador, la latencia y el latido del barrido',
    array_key_exists('database_driver', $detailedProjection)
        && array_key_exists('connection_pooler', $detailedProjection)
        && array_key_exists('response_time_ms', $detailedProjection)
        && array_key_exists('sanitary_clock', $detailedProjection)
        && array_keys($detailedProjection['sanitary_clock']) === ['last_pass_at', 'last_result']
);

$assert(
    '3.6 El motor publicado es el que declara la factoría, no una suposición del servicio',
    $detailedProjection['database_driver'] === ConnectionFactory::describeConnection()['driver']
);

$assert(
    '3.7 El camino local se declara `direct`, sin inventarse un agrupador',
    $detailedProjection['connection_pooler'] === HealthCheckService::DIRECT_CONNECTION_LABEL
        && HealthCheckService::connectionPoolerLabel(['uses_pooler' => true]) === HealthCheckService::POOLER_LABEL_PREFIX . ConnectionFactory::POOLER_PORT
);

$bookNotRead = new FakeSweepBook($healthyPass);
(new HealthCheckService(new FakeProbeConnection(), $bookNotRead))->check(false);

$assert(
    '3.8 Sin telemetría pedida no se lee el libro del barrido: no se duplica la espera',
    $bookNotRead->findLatestPassCalls === 0
);

$bookRead = new FakeSweepBook($healthyPass);
$withSweep = (new HealthCheckService(new FakeProbeConnection(), $bookRead))->check(true)->toArray(true);

$assert(
    '3.9 Con telemetría pedida el latido del barrido se publica convertido a UTC',
    $bookRead->findLatestPassCalls === 1
        && $withSweep['sanitary_clock']['last_pass_at'] === '2026-10-14T10:00:00Z'
        && $withSweep['sanitary_clock']['last_result'] === HealthCheckService::PASS_RESULT_OK,
    json_encode($withSweep['sanitary_clock'])
);

$assert(
    '3.10 La conversión horaria publica siempre UTC a partir de la marca del motor',
    HealthCheckService::sweepInstantToUtc('2031-01-01 12:00:00+00') === '2031-01-01T12:00:00Z'
        && HealthCheckService::sweepInstantToUtc('2031-01-01T12:00:00+00:00', ConnectionFactory::DRIVER_PGSQL) === '2031-01-01T12:00:00Z'
);

$unreadableBook = new FakeSweepBook(null, new RuntimeException('el libro no responde'));
$degraded = (new HealthCheckService(new FakeProbeConnection(), $unreadableBook))->check(true);

$assert(
    '3.11 Un libro de barrido ilegible es una degradación declarada, nunca un silencio',
    $degraded->status === HealthCheckResponseDto::STATUS_DEGRADED
        && $degraded->failureReason === HealthCheckService::REASON_SWEEP_BOOK_UNREADABLE
        && $degraded->isAvailable() === true
);

// =====================================================================
// GRUPO 4 — Ofuscación de credenciales ante cualquier fallo (EARS-07.4)
// =====================================================================

echo "\n--- Grupo 4: ningún fallo publica arquitectura ni credenciales ---\n";

$leakedSecrets = [
    'dsn' => 'pgsql:host=db.pooler.supabase.com;port=6543;dbname=vendguard',
    'user' => 'vendguard_app',
    'password' => 'contraseña-que-no-debe-publicarse',
];

$queryFailure = new FakeProbeConnection(failure: new PDOException(sprintf(
    'SQLSTATE[08006] %s usuario %s contraseña %s',
    $leakedSecrets['dsn'],
    $leakedSecrets['user'],
    $leakedSecrets['password']
)));
$unhealthy = (new HealthCheckService($queryFailure, new FakeSweepBook()))->check(true);

$serialized = (string)json_encode($unhealthy->toArray(true));
$serializedPublic = (string)json_encode($unhealthy->toArray(false));

$assert(
    '4.1 Un fallo de consulta se clasifica por TIPO de excepción, no por su texto',
    $unhealthy->status === HealthCheckResponseDto::STATUS_UNHEALTHY
        && $unhealthy->failureReason === HealthCheckService::REASON_QUERY_FAILED
);

$assert(
    '4.2 El DSN, el usuario y la contraseña no aparecen en ninguna de las dos proyecciones',
    !str_contains($serialized, $leakedSecrets['dsn'])
        && !str_contains($serialized, $leakedSecrets['user'])
        && !str_contains($serialized, $leakedSecrets['password'])
        && !str_contains($serializedPublic, 'supabase')
        && !str_contains($serializedPublic, 'SQLSTATE'),
    $serialized
);

$assert(
    '4.3 La respuesta no tiene ningún campo capaz de transportar un DSN o una credencial',
    !preg_match('/dsn|password|passwd|secret|host/i', $serialized) === true,
    $serialized
);

$connectFailure = new FakeProbeConnection(failure: new RuntimeException('Fallo de conexión a la base de datos: SQLSTATE[HY000] host db.local usuario root contraseña secreta'));
$unreachable = (new HealthCheckService($connectFailure, new FakeSweepBook()))->check(true);

$assert(
    '4.4 El fallo de conexión se distingue del fallo de consulta por su tipo',
    $unreachable->status === HealthCheckResponseDto::STATUS_UNHEALTHY
        && $unreachable->failureReason === HealthCheckService::REASON_CONNECT_FAILED
);

$assert(
    '4.5 El texto del fallo de conexión tampoco se publica en la telemetría',
    !str_contains((string)json_encode($unreachable->toArray(true)), 'SQLSTATE')
        && !str_contains((string)json_encode($unreachable->toArray(true)), 'secreta')
);

$unexpected = (new HealthCheckService(
    new FakeProbeConnection(failure: new LogicException('algo inesperado con host y usuario dentro')),
    new FakeSweepBook()
))->check(true);

$assert(
    '4.6 Un fallo que el diagnóstico no distingue se etiqueta como UNEXPECTED, sin publicar su texto',
    $unexpected->failureReason === HealthCheckService::REASON_UNEXPECTED
        && !str_contains((string)json_encode($unexpected->toArray(true)), 'inesperado')
);

$closedReasonSet = [
    HealthCheckService::REASON_CONNECT_FAILED,
    HealthCheckService::REASON_QUERY_FAILED,
    HealthCheckService::REASON_UNEXPECTED,
    HealthCheckService::REASON_SWEEP_BOOK_UNREADABLE,
    HealthCheckService::REASON_SLOW_RESPONSE,
];

$assert(
    '4.7 El motivo pertenece siempre a un conjunto cerrado de etiquetas',
    in_array($unhealthy->failureReason, $closedReasonSet, true)
        && in_array($unreachable->failureReason, $closedReasonSet, true)
        && in_array($unexpected->failureReason, $closedReasonSet, true)
        && in_array($degraded->failureReason, $closedReasonSet, true)
        && in_array($slow->failureReason, $closedReasonSet, true)
        && count(array_unique($closedReasonSet)) === 5,
    json_encode($closedReasonSet)
);

$assert(
    '4.8 Un diagnóstico no disponible nunca declara un motivo fuera de la proyección pública',
    array_keys($unhealthy->toArray(false)) === ['status', 'timestamp_utc']
        && $unhealthy->toArray(false)['status'] === HealthCheckResponseDto::STATUS_UNHEALTHY
);

// =====================================================================
// Cierre
// =====================================================================

echo "\n======================================================================\n";
echo " Aserciones: {$assertions} | Fallos: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. El diagnóstico mide, clasifica y no publica lo que no debe.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
echo "======================================================================\n";
exit(1);
