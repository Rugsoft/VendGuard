<?php

declare(strict_types=1);

/**
 * PostgresTieredSlaMetricsPerformanceTest · T-SLA-11 / H-04
 *
 * Certifica RNF-01 en su mitad analítica sobre PostgreSQL real:
 *
 *   1. **Latencia del cuadro de mandos.** Mide el camino completo de los tres
 *      endpoints de métricas —router, middleware de Coordinación, controlador,
 *      `MetricFilter`, servicio, repositorio PDO y exportador CSV— con un
 *      parque representativo: seis sedes de los tres perfiles, 480 máquinas de
 *      las cinco tipologías, cinco técnicos, 3.000 expedientes resueltos en el
 *      periodo, 240 avisos abiertos y 600 resoluciones del periodo anterior.
 *      La siembra respeta `uq_machine_active_ticket`: solo los 240 expedientes
 *      `RESOLVED` —uno por máquina, dentro de su ventana de reapertura— y los
 *      240 avisos abiertos ocupan máquina; el histórico va en `CLOSED`.
 *      El sembrado queda fuera de la medición; se hacen tres pasadas de
 *      calentamiento y veinte muestras por endpoint. El criterio es mediana y
 *      p95 inferiores a 1,5 s, y se publica también el máximo observado.
 *
 *   2. **Determinismo del cálculo.** Dos ejecuciones de `summary`, `breakdown`
 *      y `export` con el mismo rango fijo devuelven el mismo cuerpo, byte a
 *      byte. Además, el servicio se ejecuta dos veces con el mismo
 *      `DateTimeImmutable` de referencia y periodo relativo `last_30_days`,
 *      comparando el resumen, el desglose y el CSV resultantes. Un cálculo que
 *      dependiera del reloj real o del orden de llegada de las filas fallaría
 *      aquí aunque la primera pasada diera cifras plausibles.
 *
 * CATEGORÍA: `new_engine`
 *   Exige PostgreSQL a través de `PG_TEST_DSN`, sin valor por defecto. Sin
 *   motor, la suite se declara no ejecutable con código 2 y no se omite.
 *
 * LIMPIEZA: el escenario vive en un esquema aislado que se borra con `CASCADE`
 * en el `finally`, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local   # o .env.docker-tls
 *   php tests/integration/PostgresTieredSlaMetricsPerformanceTest.php
 *
 * La suite fija `DATABASE_URL` desde `PG_TEST_DSN` antes de cargar el bootstrap,
 * de modo que `ConnectionFactory`, el fixture y `AppRouter` miden el mismo motor
 * y no dependen del contrato de `.env.battery`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

$pgTestDsn = trim((string)getenv('PG_TEST_DSN'));
if ($pgTestDsn === '') {
    fwrite(STDERR, PHP_EOL . '[CATEGORÍA NO EJECUTABLE] Falta PG_TEST_DSN: el motor nuevo no está declarado.' . PHP_EOL);
    fwrite(STDERR, 'Aprovisione deploy/local/postgres/ y exporte su contrato de entorno; la suite no se omite en silencio.' . PHP_EOL);
    exit(2);
}

// H-04 mide el camino real de la aplicación: el fixture, ConnectionFactory y
// AppRouter deben compartir el motor que declara la categoría. Se fija
// DATABASE_URL antes del bootstrap para que el arnés no resuelva .env.battery
// por su cuenta y la conexión cacheada no quede apuntando a otro banco.
putenv('DATABASE_URL=' . $pgTestDsn);
$_ENV['DATABASE_URL'] = $pgTestDsn;
$_SERVER['DATABASE_URL'] = $pgTestDsn;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\MetricsCalculationService;
use VendGuard\Application\Service\MetricsExportService;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMetricsRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

/** Marca de categoría: `run_all.php` la lee para no incluirla en la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado de esta certificación. */
const SLA_METRICS_PERF_SCHEMA = 't_sla_h04_metrics';

/** Presupuesto de respuesta de RNF-01, en milisegundos. */
const SLA_METRICS_LATENCY_BUDGET_MS = 1500.0;

/** Muestras medidas por endpoint, después del calentamiento. */
const SLA_METRICS_LATENCY_SAMPLES = 20;

/** Pasadas de calentamiento para que la primera consulta no pague el plan en frío. */
const SLA_METRICS_WARMUP_RUNS = 3;

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-SLA-11 / H-04 · Latencia y determinismo de métricas\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

try {
    // El bootstrap ya recibió DATABASE_URL desde PG_TEST_DSN; se descarta una
    // instancia previa para que la conexión compartida nazca con ese contrato.
    ConnectionFactory::reset();
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor nuevo no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

try {
    echo "\n--- 1. Esquema aislado y siembra representativa ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, SLA_METRICS_PERF_SCHEMA);
    $schema = PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);
    echo '  DDL base y ' . count($schema['migrations']) . " migraciones aplicadas.\n";

    $timezone = new DateTimeZone('Europe/Madrid');
    $periodStart = new DateTimeImmutable('2026-05-01 00:00:00', $timezone);
    $previousStart = new DateTimeImmutable('2025-10-30 00:00:00', $timezone);
    $format = static fn (DateTimeImmutable $instant): string => $instant->format('Y-m-d H:i:sP');

    $insertLocation = static function (string $code, string $name, string $tier) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO locations (site_code, site_code_key, name, address, postal_code, contact_email, sla_tier)
             VALUES (:site_code, :site_code_key, :name, :address, :postal_code, :contact_email, :sla_tier)
             RETURNING id'
        );
        $stmt->execute([
            ':site_code' => $code,
            ':site_code_key' => strtolower($code),
            ':name' => $name,
            ':address' => 'Calle de Certificación H-04, 1',
            ':postal_code' => '08001',
            ':contact_email' => strtolower($code) . '@vendguard.internal',
            ':sla_tier' => $tier,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $insertMachine = static function (int $locationId, string $code, string $type) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
             VALUES (:location_id, :code, :model, :machine_type, :floor_wing)
             RETURNING id'
        );
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => 'Modelo H-04',
            ':machine_type' => $type,
            ':floor_wing' => 'Planta baja',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $insertUser = static function (string $suffix, string $role) use ($pdo): int {
        $email = 'h04.' . $suffix . '@vendguard.internal';
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code)
             VALUES (:name, :email, :email_key, :password_hash, :role, :operator_code)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => 'Usuario H-04 ' . ucfirst($suffix),
            ':email' => $email,
            ':email_key' => $email,
            ':password_hash' => password_hash('h04-' . $suffix, PASSWORD_BCRYPT),
            ':role' => $role,
            ':operator_code' => 'H04-' . strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $pdo->beginTransaction();

    $tierCycle = ['STANDARD', 'PRIORITY_CORPORATE', 'CRITICAL_INFRASTRUCTURE_24_7'];
    $locations = [];
    for ($i = 0; $i < 6; $i++) {
        $tier = $tierCycle[$i % count($tierCycle)];
        $code = sprintf('H04-SEDE-%02d', $i + 1);
        $locations[] = [
            'id' => $insertLocation($code, 'Sede H-04 ' . ($i + 1), $tier),
            'tier' => $tier,
        ];
    }

    $machineTypeCycle = ['PERISHABLE_FOOD', 'COMBO', 'COLD_DRINKS', 'HOT_DRINKS', 'SNACKS'];
    $machines = [];
    for ($i = 0; $i < 480; $i++) {
        $location = $locations[$i % count($locations)];
        $type = $machineTypeCycle[$i % count($machineTypeCycle)];
        $machines[] = [
            'id' => $insertMachine($location['id'], sprintf('H04-MAQ-%02d', $i + 1), $type),
            'type' => $type,
            'location_id' => $location['id'],
            'tier' => $location['tier'],
        ];
    }

    $coordinatorId = $insertUser('coord', 'COORDINATOR');
    $technicianIds = [];
    for ($i = 0; $i < 5; $i++) {
        $technicianIds[] = $insertUser('tech' . ($i + 1), 'TECHNICIAN');
    }

    $targetMinutesByTier = [
        'STANDARD' => 1440,
        'PRIORITY_CORPORATE' => 720,
        'CRITICAL_INFRASTRUCTURE_24_7' => 480,
    ];
    $categories = ['TEMPERATURE_COLD', 'PAYMENT_SYSTEM', 'PRODUCT_JAM', 'ELECTRICAL_OFF', 'OTHER'];
    $urgencies = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    $incidentStmt = $pdo->prepare(
        'INSERT INTO incidents (
            ticket_code, machine_id, machine_type_snapshot, location_id, assigned_technician_id,
            category, description, urgency, status, assigned_at, started_at, resolved_at, closed_at,
            resolution_diagnosis, resolution_action, sla_tier_snapshot, sla_target_minutes,
            sla_target_at, total_pending_info_seconds, opportunistic_mode, opportunistic_origin_reason,
            created_at
         ) VALUES (
            :ticket_code, :machine_id, :machine_type_snapshot, :location_id, :assigned_technician_id,
            :category, :description, :urgency, :status, :assigned_at, :started_at, :resolved_at, :closed_at,
            :resolution_diagnosis, :resolution_action, :sla_tier_snapshot, :sla_target_minutes,
            :sla_target_at, :total_pending_info_seconds, :opportunistic_mode, :opportunistic_origin_reason,
            :created_at
         ) RETURNING id'
    );

    $insertIncident = static function (array $data) use ($incidentStmt): int {
        $incidentStmt->execute([
            ':ticket_code' => $data['ticket_code'],
            ':machine_id' => $data['machine_id'],
            ':machine_type_snapshot' => $data['machine_type_snapshot'],
            ':location_id' => $data['location_id'],
            ':assigned_technician_id' => $data['assigned_technician_id'] ?? null,
            ':category' => $data['category'],
            ':description' => $data['description'],
            ':urgency' => $data['urgency'],
            ':status' => $data['status'],
            ':assigned_at' => $data['assigned_at'] ?? null,
            ':started_at' => $data['started_at'] ?? null,
            ':resolved_at' => $data['resolved_at'] ?? null,
            ':closed_at' => $data['closed_at'] ?? null,
            ':resolution_diagnosis' => $data['resolution_diagnosis'] ?? null,
            ':resolution_action' => $data['resolution_action'] ?? null,
            ':sla_tier_snapshot' => $data['sla_tier_snapshot'],
            ':sla_target_minutes' => $data['sla_target_minutes'],
            ':sla_target_at' => $data['sla_target_at'],
            ':total_pending_info_seconds' => $data['total_pending_info_seconds'] ?? 0,
            ':opportunistic_mode' => $data['opportunistic_mode'] ?? null,
            ':opportunistic_origin_reason' => $data['opportunistic_origin_reason'] ?? null,
            ':created_at' => $data['created_at'],
        ]);

        return (int)$incidentStmt->fetchColumn();
    };

    $machineCount = count($machines);
    $technicianCount = count($technicianIds);
    $categoryCount = count($categories);
    $urgencyCount = count($urgencies);
    $reopenCandidates = [];

    // 3.000 resoluciones dentro del periodo. Los márgenes de tres días al
    // principio y al final evitan que un desfase de zona horaria entre el
    // filtro (Europe/Madrid) y el motor mueva expedientes fuera del rango.
    for ($i = 0; $i < 3000; $i++) {
        $machine = $machines[$i % $machineCount];
        $technicianId = $technicianIds[$i % $technicianCount];
        $createdAt = $periodStart
            ->modify('+' . (($i % 168) + 3) . ' days')
            ->modify('+' . (($i * 7) % 600) . ' minutes');
        $durationMinutes = 20 + (($i * 37) % 2380);
        $resolvedAt = $createdAt->modify('+' . $durationMinutes . ' minutes');
        // `RESOLVED` sigue ocupando la máquina durante la ventana de 48 h de
        // reapertura (Art. V.6): solo los primeros 240 expedientes, uno por
        // máquina, quedan en ese estado. El resto se cierra para liberar el
        // índice único parcial sin dejar de contar como resuelto en las métricas.
        $status = $i < 240 ? 'RESOLVED' : 'CLOSED';
        $pendingSeconds = $i % 6 === 0 ? (($i % 5) * 300) : 0;

        $opportunisticMode = null;
        $opportunisticOrigin = null;
        if ($i % 97 === 0) {
            $opportunisticMode = 'NEW';
            $opportunisticOrigin = 'TECHNICIAN_DETECTION';
        } elseif ($i % 131 === 0) {
            $opportunisticMode = 'ASSUMED';
            $opportunisticOrigin = 'EXISTING_TICKET_CLAIM';
        }

        $targetMinutes = $targetMinutesByTier[$machine['tier']];
        $incidentId = $insertIncident([
            'ticket_code' => sprintf('H04-R-%05d', $i + 1),
            'machine_id' => $machine['id'],
            'machine_type_snapshot' => $machine['type'],
            'location_id' => $machine['location_id'],
            'assigned_technician_id' => $technicianId,
            'category' => $categories[$i % $categoryCount],
            'description' => 'Incidencia sintética número ' . ($i + 1) . ' para la certificación H-04.',
            'urgency' => $urgencies[$i % $urgencyCount],
            'status' => $status,
            'assigned_at' => $format($createdAt->modify('+5 minutes')),
            'started_at' => $format($createdAt->modify('+15 minutes')),
            'resolved_at' => $format($resolvedAt),
            'closed_at' => $status === 'CLOSED' ? $format($resolvedAt) : null,
            'resolution_diagnosis' => 'Diagnóstico sintético de la avería ' . ($i + 1) . ' para medir métricas.',
            'resolution_action' => 'Solución sintética aplicada en la avería ' . ($i + 1) . ' del banco.',
            'sla_tier_snapshot' => $machine['tier'],
            'sla_target_minutes' => $targetMinutes,
            'sla_target_at' => $format($createdAt->modify('+' . $targetMinutes . ' minutes')),
            'total_pending_info_seconds' => $pendingSeconds,
            'opportunistic_mode' => $opportunisticMode,
            'opportunistic_origin_reason' => $opportunisticOrigin,
            'created_at' => $format($createdAt),
        ]);

        // Una de cada 25 resoluciones deja una reapertura de garantía en el
        // historial: la consulta del desglose por técnico cruza esa tabla y
        // debe medirse con filas reales, no con el `LEFT JOIN` vacío.
        if ($i % 25 === 0) {
            $reopenCandidates[] = [
                'incident_id' => $incidentId,
                'technician_id' => $technicianId,
                'resolved_at' => $resolvedAt,
            ];
        }
    }

    // 240 avisos abiertos: alimentan el backlog activo del resumen.
    $openStatuses = ['REGISTERED', 'ASSIGNED', 'IN_PROGRESS'];
    for ($i = 0; $i < 240; $i++) {
        // Las 240 máquinas restantes del parque albergan una avería abierta
        // cada una: la regla de negocio no admite dos avisos vivos por máquina.
        $machine = $machines[240 + $i];
        $createdAt = $periodStart
            ->modify('+' . ((($i * 3) % 165) + 3) . ' days')
            ->modify('+' . (($i * 11) % 600) . ' minutes');
        $status = $openStatuses[$i % 3];
        $technicianId = $status === 'REGISTERED' ? null : $technicianIds[$i % $technicianCount];
        $targetMinutes = $targetMinutesByTier[$machine['tier']];

        $insertIncident([
            'ticket_code' => sprintf('H04-O-%05d', $i + 1),
            'machine_id' => $machine['id'],
            'machine_type_snapshot' => $machine['type'],
            'location_id' => $machine['location_id'],
            'assigned_technician_id' => $technicianId,
            'category' => $categories[$i % $categoryCount],
            'description' => 'Aviso abierto sintético ' . ($i + 1) . ' para el backlog de H-04.',
            'urgency' => $urgencies[$i % $urgencyCount],
            'status' => $status,
            'assigned_at' => $technicianId !== null ? $format($createdAt->modify('+5 minutes')) : null,
            'started_at' => $status === 'IN_PROGRESS' ? $format($createdAt->modify('+15 minutes')) : null,
            'sla_tier_snapshot' => $machine['tier'],
            'sla_target_minutes' => $targetMinutes,
            'sla_target_at' => $format($createdAt->modify('+' . $targetMinutes . ' minutes')),
            'created_at' => $format($createdAt),
        ]);
    }

    // 600 resoluciones del periodo equivalente anterior: el resumen compara
    // MTTR neto contra MTTR neto y necesita una muestra previa real.
    for ($i = 0; $i < 600; $i++) {
        $machine = $machines[$i % $machineCount];
        $technicianId = $technicianIds[$i % $technicianCount];
        $createdAt = $previousStart
            ->modify('+' . (($i % 170) + 3) . ' days')
            ->modify('+' . (($i * 13) % 600) . ' minutes');
        $durationMinutes = 25 + (($i * 41) % 2200);
        $resolvedAt = $createdAt->modify('+' . $durationMinutes . ' minutes');
        $targetMinutes = $targetMinutesByTier[$machine['tier']];

        $insertIncident([
            'ticket_code' => sprintf('H04-P-%05d', $i + 1),
            'machine_id' => $machine['id'],
            'machine_type_snapshot' => $machine['type'],
            'location_id' => $machine['location_id'],
            'assigned_technician_id' => $technicianId,
            'category' => $categories[$i % $categoryCount],
            'description' => 'Incidencia sintética del periodo anterior ' . ($i + 1) . ' para H-04.',
            'urgency' => $urgencies[$i % $urgencyCount],
            'status' => 'CLOSED',
            'assigned_at' => $format($createdAt->modify('+5 minutes')),
            'started_at' => $format($createdAt->modify('+15 minutes')),
            'resolved_at' => $format($resolvedAt),
            'closed_at' => $format($resolvedAt),
            'resolution_diagnosis' => 'Diagnóstico sintético del periodo anterior ' . ($i + 1) . '.',
            'resolution_action' => 'Solución sintética del periodo anterior ' . ($i + 1) . '.',
            'sla_tier_snapshot' => $machine['tier'],
            'sla_target_minutes' => $targetMinutes,
            'sla_target_at' => $format($createdAt->modify('+' . $targetMinutes . ' minutes')),
            'created_at' => $format($createdAt),
        ]);
    }

    $historyStmt = $pdo->prepare(
        'INSERT INTO incident_history (incident_id, user_id, from_status, to_status, action_note, created_at)
         VALUES (:incident_id, :user_id, :from_status, :to_status, :action_note, :created_at)'
    );

    foreach ($reopenCandidates as $candidate) {
        $historyStmt->execute([
            ':incident_id' => $candidate['incident_id'],
            ':user_id' => $candidate['technician_id'],
            ':from_status' => 'IN_PROGRESS',
            ':to_status' => 'RESOLVED',
            ':action_note' => 'Resolución sintética previa a la reapertura de garantía.',
            ':created_at' => $format($candidate['resolved_at']),
        ]);
        $historyStmt->execute([
            ':incident_id' => $candidate['incident_id'],
            ':user_id' => null,
            ':from_status' => 'RESOLVED',
            ':to_status' => 'REOPENED',
            ':action_note' => 'Reapertura sintética dentro de la ventana de garantía.',
            ':created_at' => $format($candidate['resolved_at']->modify('+1 hour')),
        ]);
    }

    $pdo->commit();

    $totalMachines = (int)$pdo->query('SELECT COUNT(*) FROM machines')->fetchColumn();
    $totalIncidents = (int)$pdo->query('SELECT COUNT(*) FROM incidents')->fetchColumn();
    $totalResolved = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE status IN ('RESOLVED', 'CLOSED')")->fetchColumn();
    $totalResolvedOpen = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE status = 'RESOLVED'")->fetchColumn();
    $totalOpen = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE status IN ('REGISTERED', 'ASSIGNED', 'IN_PROGRESS')")->fetchColumn();
    $totalHistory = (int)$pdo->query('SELECT COUNT(*) FROM incident_history')->fetchColumn();

    $assert('El parque sembrado tiene 480 máquinas', $totalMachines === 480, 'máquinas=' . $totalMachines);
    $assert('Se han sembrado 3.840 expedientes', $totalIncidents === 3840, 'total=' . $totalIncidents);
    $assert('La muestra resuelta es de 3.600 expedientes', $totalResolved === 3600, 'resueltos=' . $totalResolved);
    $assert('240 expedientes ocupan su máquina en estado RESOLVED', $totalResolvedOpen === 240, 'resolved=' . $totalResolvedOpen);
    $assert('El backlog abierto es de 240 expedientes', $totalOpen === 240, 'abiertos=' . $totalOpen);
    $assert('El historial contiene 240 eventos de resolución y reapertura', $totalHistory === 240, 'eventos=' . $totalHistory);

    echo "\n--- 2. Autenticación de Coordinación y rango fijo ---\n";

    $locationRepo = new PdoLocationRepository($pdo);
    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService($locationRepo, $userRepo);
    $coordinator = $userRepo->findById($coordinatorId);
    $assert('El coordinador sembrado se resuelve por PDO', $coordinator !== null);
    if ($coordinator === null) {
        throw new RuntimeException('No se pudo resolver el coordinador sembrado para la suite H-04.');
    }

    $coordinatorToken = $authService->generateInternalToken($coordinator);
    $router = AppRouter::create();

    $call = static function (string $path, array $query) use ($router, $coordinatorToken): Response {
        return $router->dispatch(new Request(
            'GET',
            $path,
            $query,
            [],
            ['Authorization' => 'Bearer ' . $coordinatorToken]
        ));
    };

    $query = ['from' => '2026-05-01', 'to' => '2026-10-31'];

    echo "\n--- 3. Doble ejecución idéntica de los tres endpoints ---\n";

    $firstSummary = $call('/api/coordinator/metrics/summary', $query);
    $secondSummary = $call('/api/coordinator/metrics/summary', $query);
    $assert(
        'summary responde 200 en las dos ejecuciones',
        $firstSummary->getStatusCode() === 200 && $secondSummary->getStatusCode() === 200,
        'HTTP ' . $firstSummary->getStatusCode() . ' / ' . $secondSummary->getStatusCode()
    );
    $assert(
        'Dos ejecuciones de summary con el mismo rango devuelven el mismo JSON',
        $firstSummary->getBody() === $secondSummary->getBody()
    );
    $summaryPayload = json_decode($firstSummary->getBody(), true);
    $assert(
        'summary devuelve KPIs y alertas de SLA',
        is_array($summaryPayload)
            && ($summaryPayload['success'] ?? false) === true
            && isset($summaryPayload['data']['kpis']['mttr_global_minutes'])
            && isset($summaryPayload['data']['sla_alerts']['general']),
        'claves: ' . implode(', ', array_keys($summaryPayload['data'] ?? []))
    );

    $firstBreakdown = $call('/api/coordinator/metrics/breakdown', $query);
    $secondBreakdown = $call('/api/coordinator/metrics/breakdown', $query);
    $assert(
        'breakdown responde 200 en las dos ejecuciones',
        $firstBreakdown->getStatusCode() === 200 && $secondBreakdown->getStatusCode() === 200,
        'HTTP ' . $firstBreakdown->getStatusCode() . ' / ' . $secondBreakdown->getStatusCode()
    );
    $assert(
        'Dos ejecuciones de breakdown con el mismo rango devuelven el mismo JSON',
        $firstBreakdown->getBody() === $secondBreakdown->getBody()
    );
    $breakdownPayload = json_decode($firstBreakdown->getBody(), true);
    $breakdownData = $breakdownPayload['data'] ?? [];
    $assert(
        'breakdown devuelve los cuatro desgloses completos',
        isset($breakdownData['by_location'], $breakdownData['by_technician'], $breakdownData['by_machine_type'], $breakdownData['by_category'])
            && count($breakdownData['by_location']) === 6
            && count($breakdownData['by_technician']) === 5
            && count($breakdownData['by_machine_type']) === 5
            && count($breakdownData['by_category']) === 5,
        'filas: ' . count($breakdownData['by_location'] ?? []) . '/'
            . count($breakdownData['by_technician'] ?? []) . '/'
            . count($breakdownData['by_machine_type'] ?? []) . '/'
            . count($breakdownData['by_category'] ?? [])
    );

    $firstExport = $call('/api/coordinator/metrics/export', $query);
    $secondExport = $call('/api/coordinator/metrics/export', $query);
    $assert(
        'export responde 200 en las dos ejecuciones',
        $firstExport->getStatusCode() === 200 && $secondExport->getStatusCode() === 200,
        'HTTP ' . $firstExport->getStatusCode() . ' / ' . $secondExport->getStatusCode()
    );
    $assert(
        'Dos ejecuciones de export con el mismo rango devuelven el mismo CSV',
        $firstExport->getBody() === $secondExport->getBody()
    );
    $assert(
        'export entrega un CSV con BOM UTF-8 y cabecera de métricas',
        str_starts_with($firstExport->getBody(), "\xEF\xBB\xBF")
            && str_contains($firstExport->getBody(), 'Identificador')
            && str_contains($firstExport->getBody(), 'Sede'),
        'bytes=' . strlen($firstExport->getBody())
    );

    $kpis = $summaryPayload['data']['kpis'] ?? [];
    $assert(
        'El resumen medida contiene la muestra sembrada, no una respuesta vacía',
        (int)($kpis['total_tickets_resolved'] ?? 0) === 3000
            && (int)($kpis['total_tickets_created'] ?? 0) === 3240
            && (int)($kpis['active_backlog'] ?? 0) === 240
            && (int)($kpis['critical_sla_breaches'] ?? 0) > 0,
        'resueltos=' . ($kpis['total_tickets_resolved'] ?? 'n/a')
            . ' · creados=' . ($kpis['total_tickets_created'] ?? 'n/a')
            . ' · backlog=' . ($kpis['active_backlog'] ?? 'n/a')
            . ' · brechas=' . ($kpis['critical_sla_breaches'] ?? 'n/a')
    );

    echo "\n--- 4. Latencia de los tres endpoints (camino real completo) ---\n";

    $measure = static function (callable $operation, string $label) use ($assert): array {
        for ($i = 0; $i < SLA_METRICS_WARMUP_RUNS; $i++) {
            $operation();
        }

        $durations = [];
        $bodies = [];
        $allOk = true;

        for ($i = 0; $i < SLA_METRICS_LATENCY_SAMPLES; $i++) {
            $startedAt = hrtime(true);
            $response = $operation();
            $durations[] = (hrtime(true) - $startedAt) / 1_000_000;
            $allOk = $allOk && $response->getStatusCode() === 200;
            $bodies[] = $response->getBody();
        }

        sort($durations, SORT_NUMERIC);
        $count = count($durations);
        $median = $count % 2 === 0
            ? ($durations[intdiv($count, 2) - 1] + $durations[intdiv($count, 2)]) / 2.0
            : $durations[intdiv($count, 2)];
        $p95 = $durations[max(0, (int)ceil($count * 0.95) - 1)];
        $max = $durations[$count - 1];

        echo sprintf(
            "  [MEDICIÓN] %s · mediana %.1f ms · p95 %.1f ms · máx %.1f ms (%d muestras, %d warm-up)\n",
            $label,
            $median,
            $p95,
            $max,
            $count,
            SLA_METRICS_WARMUP_RUNS
        );

        $assert("{$label}: las {$count} muestras responden 200", $allOk);
        $assert(
            "{$label}: mediana inferior a 1,5 s",
            $median < SLA_METRICS_LATENCY_BUDGET_MS,
            sprintf('%.1f ms', $median)
        );
        $assert(
            "{$label}: p95 inferior a 1,5 s",
            $p95 < SLA_METRICS_LATENCY_BUDGET_MS,
            sprintf('%.1f ms', $p95)
        );
        $assert(
            "{$label}: las {$count} ejecuciones devuelven el mismo cuerpo",
            count(array_unique($bodies)) === 1
        );

        return ['median' => $median, 'p95' => $p95, 'max' => $max];
    };

    $summaryMeasurement = $measure(
        static fn (): Response => $call('/api/coordinator/metrics/summary', $query),
        'summary'
    );
    $breakdownMeasurement = $measure(
        static fn (): Response => $call('/api/coordinator/metrics/breakdown', $query),
        'breakdown'
    );
    $exportMeasurement = $measure(
        static fn (): Response => $call('/api/coordinator/metrics/export', $query),
        'export'
    );

    echo "\n--- 5. Determinismo con el mismo instante de referencia ---\n";

    $metricsService = new MetricsCalculationService(new PdoMetricsRepository($pdo));
    $exportService = new MetricsExportService();
    $fixedNow = new DateTimeImmutable('2026-11-09 12:00:00', $timezone);
    $filterA = MetricFilter::fromQueryParams(['period' => MetricFilter::PERIOD_LAST_30_DAYS], $fixedNow);
    $filterB = MetricFilter::fromQueryParams(['period' => MetricFilter::PERIOD_LAST_30_DAYS], $fixedNow);

    $assert(
        'Los dos filtros resuelven exactamente el mismo rango',
        $filterA->toArray() === $filterB->toArray(),
        $filterA->getFrom()->format(DateTimeInterface::ATOM) . ' → ' . $filterA->getTo()->format(DateTimeInterface::ATOM)
    );

    $serviceSummaryA = $metricsService->getKpiSummary($filterA)->toArray();
    $serviceSummaryB = $metricsService->getKpiSummary($filterB)->toArray();
    $assert(
        'El resumen es idéntico con el mismo instante de referencia',
        $serviceSummaryA === $serviceSummaryB,
        'mttr=' . json_encode($serviceSummaryA['kpis']['mttr_global_minutes'] ?? null)
    );

    $serviceBreakdownA = $metricsService->getBreakdown($filterA);
    $serviceBreakdownB = $metricsService->getBreakdown($filterB);
    $assert(
        'El desglose es idéntico con el mismo instante de referencia',
        $serviceBreakdownA === $serviceBreakdownB,
        'filas=' . count($serviceBreakdownA['by_location'])
    );

    $assert(
        'El CSV es idéntico con el mismo instante de referencia',
        $exportService->exportMetricsCsv($serviceBreakdownA) === $exportService->exportMetricsCsv($serviceBreakdownB)
    );

    echo "\n  [RESUMEN LATENCIA] summary "
        . number_format($summaryMeasurement['median'], 1, ',', '.') . ' ms · breakdown '
        . number_format($breakdownMeasurement['median'], 1, ',', '.') . ' ms · export '
        . number_format($exportMeasurement['median'], 1, ',', '.') . " ms (medianas)\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $failures++;
    echo "\n  [FAIL] El escenario se interrumpió: " . $exception->getMessage() . "\n";
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, SLA_METRICS_PERF_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $failures++;
        echo "\n  [FAIL] No se pudo retirar el esquema aislado: " . $cleanupFailure->getMessage() . "\n";
    }

    $residue = $pdo->query(
        "SELECT to_regclass('" . SLA_METRICS_PERF_SCHEMA . ".incidents')"
    )->fetchColumn();
    $assert(
        'El escenario no deja tablas en el motor',
        $residue === null,
        'residuo: ' . json_encode($residue)
    );
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% EN VERDE. H-04 (LATENCIA DE MÉTRICAS Y DETERMINISMO) CUMPLIDO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLOS DETECTADOS EN LA MEDICIÓN DE MÉTRICAS.\n";
echo "======================================================================\n";
exit(1);
