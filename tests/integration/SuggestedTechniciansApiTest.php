<?php

declare(strict_types=1);

/**
 * SuggestedTechniciansApiTest · T-ZONE-10
 *
 * Certifica la superficie HTTP del recomendador de idoneidad
 * (`GET /api/coordinator/incidents/{id}/suggested-technicians`, contrato §3.6)
 * de punta a punta: router real, middleware de Coordinación, controlador,
 * servicio, repositorio de lectura y motor PostgreSQL.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **RBAC y validación de la ruta (Art. V.4, RF-07.1):** sin token `401`,
 *      con token de técnico `403`, id no numérico `400` y expediente inexistente
 *      `404`. El identificador de ticket (`INC-...`) también resuelve.
 *   2. **Contrato §3.6:** los tres bloques, las seis claves del desglose, la
 *      identidad recomendación ↔ primer puesto, las insignias y las etiquetas de
 *      afinidad. Los técnicos inactivos no aparecen.
 *   3. **Algoritmo contra datos reales:** el titular saturado no está libre; el
 *      escalado sanitario del Art. II antepone al técnico de guardia; la presencia
 *      in situ, la proximidad de zona, la incompatibilidad inter-sede, la carga
 *      viva, la pausa por sede y el preventivo del día puntúan lo que fija el
 *      contrato, cada uno en su propio técnico.
 *   4. **Desempate determinista (QA 5):** dos suplentes con la misma puntuación
 *      ordenan primero al que lleva más tiempo sin asignación formal.
 *   5. **Zona desatendida (RF-06.1 / QA 5):** sin zona, todos los candidatos
 *      quedan en `NONE` y el orden es por proximidad Haversine.
 *   6. **RNF-01 medido, no prometido:** veinte repeticiones reales de la misma
 *      consulta, mediana por debajo de 100 ms y ranking idéntico en las veinte.
 *
 * CATEGORÍA: `new_engine`
 *   Exige el PostgreSQL local. El escenario vive en un esquema aislado sobre la
 *   conexión de la aplicación, de modo que el router y los repositorios que éste
 *   construye trabajan sobre las mismas tablas efímeras. Sin motor, sale con
 *   código 2 en lugar de omitirse en silencio.
 *
 * LIMPIEZA: el esquema se retira con `CASCADE` al terminar, incluso si falla una
 * aserción.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/SuggestedTechniciansApiTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\GeoDistanceService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado sobre la conexión de la aplicación. */
const SUGGEST_API_TEST_SCHEMA = 't_zone10_suggest';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;
$crash = null;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-ZONE-10 · Recomendador de técnicos (Módulo 17)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

try {
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

/** @var array<string, mixed> */
$json = static fn (Response $response): array => $response->getDecodedBody() ?? [];

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, SUGGEST_API_TEST_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    // ─────────────────────────────────────────────────────────────────────
    // Semilla
    // ─────────────────────────────────────────────────────────────────────

    $createUser = static function (
        string $suffix,
        string $role,
        bool $isActive = true,
        bool $isOnCall = false
    ) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, :is_active, :is_on_call)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => ($role === 'COORDINATOR' ? 'Coordinación ' : 'Técnico ') . ucfirst($suffix),
            ':email' => 'zona10.' . $suffix . '@vendguard.internal',
            ':email_key' => 'zona10.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('zona10-' . $suffix, PASSWORD_BCRYPT),
            ':role' => $role,
            ':operator' => 'Z10-' . mb_strtoupper($suffix),
            ':is_active' => $isActive ? 'true' : 'false',
            ':is_on_call' => $isOnCall ? 'true' : 'false',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createZone = static function (string $code, string $name) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO operational_zones (code, name, color_hex) VALUES (:code, :name, :color) RETURNING id'
        );
        $stmt->execute([':code' => $code, ':name' => $name, ':color' => '#2560ff']);

        return (int)$stmt->fetchColumn();
    };

    $createLocation = static function (
        string $siteCode,
        string $name,
        string $postalCode,
        ?int $zoneId,
        float $latitude,
        float $longitude
    ) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO locations (site_code, site_code_key, name, address, latitude, longitude, postal_code, zone_id)
             VALUES (:site, :site_key, :name, :address, :lat, :lng, :cp, :zone_id)
             RETURNING id'
        );
        $stmt->execute([
            ':site' => $siteCode,
            ':site_key' => $siteCode,
            ':name' => $name,
            ':address' => 'Calle de Certificación 17',
            ':lat' => $latitude,
            ':lng' => $longitude,
            ':cp' => $postalCode,
            ':zone_id' => $zoneId,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createMachine = static function (int $locationId, string $code, string $type) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
             VALUES (:location_id, :code, :model, :type, :wing)
             RETURNING id'
        );
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => 'Modelo T10',
            ':type' => $type,
            ':wing' => 'Planta baja',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createIncident = static function (
        string $ticketCode,
        int $machineId,
        int $locationId,
        string $machineType,
        string $urgency,
        string $status,
        ?int $technicianId = null,
        ?string $assignedAt = null,
        ?string $startedAt = null,
        ?string $resolvedAt = null
    ) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO incidents (
                ticket_code, machine_id, machine_type_snapshot, location_id,
                assigned_technician_id, category, description, urgency, status,
                assigned_at, started_at, resolved_at
             ) VALUES (
                :ticket, :machine_id, :machine_type, :location_id,
                :technician_id, :category, :description, :urgency, :status,
                :assigned_at, :started_at, :resolved_at
             ) RETURNING id'
        );
        $stmt->execute([
            ':ticket' => $ticketCode,
            ':machine_id' => $machineId,
            ':machine_type' => $machineType,
            ':location_id' => $locationId,
            ':technician_id' => $technicianId,
            ':category' => $machineType === 'PERISHABLE_FOOD' ? 'TEMPERATURE_COLD' : 'PRODUCT_JAM',
            ':description' => 'Avería sembrada por la certificación T-ZONE-10.',
            ':urgency' => $urgency,
            ':status' => $status,
            ':assigned_at' => $assignedAt,
            ':started_at' => $startedAt,
            ':resolved_at' => $resolvedAt,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createPreventiveOrder = static function (
        string $orderCode,
        int $machineId,
        int $locationId,
        int $technicianId,
        string $status
    ) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO preventive_orders (order_code, machine_id, location_id, assigned_technician_id, status, scheduled_date, due_date)
             VALUES (:code, :machine_id, :location_id, :technician_id, :status, CURRENT_DATE, CURRENT_DATE)
             RETURNING id'
        );
        $stmt->execute([
            ':code' => $orderCode,
            ':machine_id' => $machineId,
            ':location_id' => $locationId,
            ':technician_id' => $technicianId,
            ':status' => $status,
        ]);

        return (int)$stmt->fetchColumn();
    };

    /** Instante relativo en formato de base de datos (el motor no acepta «-30 minutes» como marca). */
    $ago = static fn (string $relative): string => (new DateTimeImmutable($relative))->format('Y-m-d H:i:sP');

    $assignZone = static function (int $technicianId, int $zoneId, bool $isPrimary) use ($pdo): void {
        $stmt = $pdo->prepare(
            'INSERT INTO technician_zone_assignments (technician_id, zone_id, is_primary)
             VALUES (:technician_id, :zone_id, :is_primary)'
        );
        $stmt->execute([
            ':technician_id' => $technicianId,
            ':zone_id' => $zoneId,
            ':is_primary' => $isPrimary ? 'true' : 'false',
        ]);
    };

    // Base central explícita: id = 1 en un esquema recién creado.
    $pdo->exec("INSERT INTO route_settings (base_name, base_latitude, base_longitude) VALUES ('Base T10', 41.3935, 2.1701)");

    $coordinatorId = $createUser('coord', 'COORDINATOR');
    $primaryBusy = $createUser('titular', 'TECHNICIAN');
    $backupFree = $createUser('suplente', 'TECHNICIAN');
    $onCall = $createUser('guardia', 'TECHNICIAN', true, true);
    $onsiteTech = $createUser('insitu', 'TECHNICIAN');
    $preventiveTech = $createUser('preventivo', 'TECHNICIAN');
    $twinTech = $createUser('gemelo', 'TECHNICIAN');
    $inactiveTech = $createUser('baja', 'TECHNICIAN', false);

    $northZone = $createZone('ZONA-NORTE-T10', 'Zona Norte de Certificación T10');
    $southZone = $createZone('ZONA-SUR-T10', 'Zona Sur de Certificación T10');

    $assignZone($primaryBusy, $northZone, true);
    $assignZone($backupFree, $northZone, false);
    $assignZone($preventiveTech, $northZone, false);
    $assignZone($twinTech, $northZone, false);

    $targetLocation = $createLocation('SEDE-T10-01', 'Hospital del Mar T10', '08005', $northZone, 41.3900, 2.1800);
    $northTwo = $createLocation('SEDE-T10-02', 'Torre Glòries T10', '08006', $northZone, 41.3950, 2.1850);
    $northThree = $createLocation('SEDE-T10-03', 'Sede Norte Tres T10', '08007', $northZone, 41.4000, 2.1900);
    $southLocation = $createLocation('SEDE-T10-04', 'Sede Sur T10', '08020', $southZone, 41.4100, 2.2000);
    $unzonedLocation = $createLocation('SEDE-T10-05', 'Sede Sin Zona T10', '08030', null, 41.3700, 2.1600);

    $targetMachine = $createMachine($targetLocation, 'MAQ-T10-01', 'PERISHABLE_FOOD');
    $targetMachineTwo = $createMachine($targetLocation, 'MAQ-T10-02', 'COLD_DRINKS');
    $northTwoMachine = $createMachine($northTwo, 'MAQ-T10-03', 'COLD_DRINKS');
    $northThreeMachine = $createMachine($northThree, 'MAQ-T10-04', 'COLD_DRINKS');
    $southMachine = $createMachine($southLocation, 'MAQ-T10-05', 'COLD_DRINKS');
    $northTwoMachineTwo = $createMachine($northTwo, 'MAQ-T10-06', 'COLD_DRINKS');
    $northThreeMachineTwo = $createMachine($northThree, 'MAQ-T10-07', 'SNACKS');
    $unzonedMachine = $createMachine($unzonedLocation, 'MAQ-T10-08', 'COLD_DRINKS');
    $targetMachineThree = $createMachine($targetLocation, 'MAQ-T10-09', 'COLD_DRINKS');

    $criticalIncident = $createIncident(
        'INC-20261020-T1001',
        $targetMachine,
        $targetLocation,
        'PERISHABLE_FOOD',
        'CRITICAL',
        'REGISTERED'
    );
    $ordinaryIncident = $createIncident(
        'INC-20261020-T1002',
        $targetMachineThree,
        $targetLocation,
        'COLD_DRINKS',
        'HIGH',
        'REGISTERED'
    );
    $unattendedIncident = $createIncident(
        'INC-20261020-T1003',
        $unzonedMachine,
        $unzonedLocation,
        'COLD_DRINKS',
        'HIGH',
        'REGISTERED'
    );

    // Carga viva sembrada: el titular no está libre y el técnico de guardia ya rueda.
    $createIncident('INC-20261020-T1101', $northTwoMachine, $northTwo, 'COLD_DRINKS', 'MEDIUM', 'IN_PROGRESS', $primaryBusy, $ago('-30 minutes'), $ago('-20 minutes'));
    $createIncident('INC-20261020-T1102', $northThreeMachine, $northThree, 'COLD_DRINKS', 'MEDIUM', 'ASSIGNED', $primaryBusy, $ago('-10 minutes'));
    $createIncident('INC-20261020-T1103', $southMachine, $southLocation, 'COLD_DRINKS', 'MEDIUM', 'IN_PROGRESS', $onCall, $ago('-40 minutes'), $ago('-35 minutes'));
    $createIncident('INC-20261020-T1104', $targetMachineTwo, $targetLocation, 'COLD_DRINKS', 'MEDIUM', 'ASSIGNED', $onsiteTech, $ago('-5 minutes'));
    $createIncident('INC-20261020-T1105', $northTwoMachineTwo, $northTwo, 'COLD_DRINKS', 'LOW', 'PENDING_INFO', $preventiveTech, $ago('-3 hours'));
    $createIncident('INC-20261020-T1106', $northThreeMachineTwo, $northThree, 'SNACKS', 'LOW', 'RESOLVED', $twinTech, $ago('-40 minutes'), $ago('-35 minutes'), $ago('-2 hours'));
    $createPreventiveOrder('PREV-T10-01', $northThreeMachine, $northThree, $preventiveTech, 'SCHEDULED');

    // ─────────────────────────────────────────────────────────────────────
    // Router real
    // ─────────────────────────────────────────────────────────────────────

    $userRepo = new PdoUserRepository($pdo);
    $locationRepo = new PdoLocationRepository($pdo);
    $authService = new AuthService($locationRepo, $userRepo);

    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $technicianToken = $authService->generateInternalToken($userRepo->findById($primaryBusy));

    $router = AppRouter::create();

    /** Petición al router real con la cabecera y el cuerpo indicados. */
    $call = static function (
        string $method,
        string $path,
        array $body = [],
        ?string $token = null,
        array $query = []
    ) use ($router): Response {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $router->dispatch(new Request($method, $path, $query, $body, $headers));
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. RBAC y validación de la ruta
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. RBAC y validación de la ruta ---\n";

    $anonymous = $call('GET', '/api/coordinator/incidents/' . $criticalIncident . '/suggested-technicians');
    $assert(
        '1.1 Sin token responde 401 UNAUTHORIZED',
        $anonymous->getStatusCode() === 401 && ($json($anonymous)['error']['code'] ?? '') === 'UNAUTHORIZED'
    );

    $technicianAttempt = $call('GET', '/api/coordinator/incidents/' . $criticalIncident . '/suggested-technicians', [], $technicianToken);
    $assert(
        '1.2 Con token de técnico responde 403 FORBIDDEN',
        $technicianAttempt->getStatusCode() === 403 && ($json($technicianAttempt)['error']['code'] ?? '') === 'FORBIDDEN'
    );

    // 'ab' no es ni entero positivo ni código de ticket admitido (mínimo tres caracteres).
    $badId = $call('GET', '/api/coordinator/incidents/ab/suggested-technicians', [], $coordinatorToken);
    $assert(
        '1.3 Un identificador no válido responde 400 INVALID_INCIDENT_IDENTIFIER',
        $badId->getStatusCode() === 400
            && ($json($badId)['error']['code'] ?? '') === 'INVALID_INCIDENT_IDENTIFIER'
    );

    $missing = $call('GET', '/api/coordinator/incidents/999999/suggested-technicians', [], $coordinatorToken);
    $assert(
        '1.4 Un expediente inexistente responde 404 INCIDENT_NOT_FOUND',
        $missing->getStatusCode() === 404 && ($json($missing)['error']['code'] ?? '') === 'INCIDENT_NOT_FOUND'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Contrato §3.6 sobre una avería crítica perecedera
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Contrato del ranking sanitario ---\n";

    $response = $call('GET', '/api/coordinator/incidents/' . $criticalIncident . '/suggested-technicians', [], $coordinatorToken);
    $payload = $json($response);
    $data = $payload['data'] ?? [];
    $incidentBlock = $data['incident'] ?? [];
    $recommendation = $data['recommendation'] ?? [];
    $ranked = $data['ranked_technicians'] ?? [];
    $byTechnician = [];
    foreach ($ranked as $position => $row) {
        $byTechnician[(int)($row['technician_id'] ?? 0)] = $row + ['position' => $position];
    }

    $assert(
        '2.1 Responde 200 con la envolvente canónica y los tres bloques',
        $response->getStatusCode() === 200
            && ($payload['success'] ?? false) === true
            && is_array($incidentBlock) && is_array($recommendation) && is_array($ranked),
        json_encode(array_keys($data))
    );

    $assert(
        '2.2 El bloque de la avería publica prioridad, riesgo de frío y sede con su zona',
        ($incidentBlock['id'] ?? null) === $criticalIncident
            && ($incidentBlock['ticket_code'] ?? '') === 'INC-20261020-T1001'
            && ($incidentBlock['priority'] ?? '') === 'CRITICAL'
            && ($incidentBlock['is_cold_chain_risk'] ?? null) === true
            && ($incidentBlock['location']['site_code'] ?? '') === 'SEDE-T10-01'
            && ($incidentBlock['location']['postal_code'] ?? '') === '08005'
            && ($incidentBlock['location']['zone_id'] ?? null) === $northZone
            && ($incidentBlock['location']['zone_name'] ?? '') === 'Zona Norte de Certificación T10',
        json_encode($incidentBlock)
    );

    $assert(
        '2.3 El ranking excluye al técnico inactivo y cuenta a los seis activos',
        count($ranked) === 6 && !isset($byTechnician[$inactiveTech]),
        'candidatos=' . count($ranked)
    );

    $assert(
        '2.4 La recomendación coincide con el primer puesto (identidad contable)',
        ($recommendation['top_technician_id'] ?? null) === ($ranked[0]['technician_id'] ?? null)
            && ($ranked[0]['is_top_suggestion'] ?? null) === true
            && ($ranked[1]['is_top_suggestion'] ?? null) === false
    );

    $assert(
        '2.5 El escalado sanitario del Art. II está activo y se explica',
        ($recommendation['is_cold_chain_escalation'] ?? null) === true
            && ($recommendation['unattended_zone'] ?? null) === false
            && str_contains((string)($recommendation['explanation'] ?? ''), 'Art. II')
            && str_contains((string)($recommendation['explanation'] ?? ''), 'guardia'),
        (string)($recommendation['explanation'] ?? '')
    );

    $assert(
        '2.6 El técnico de guardia encabeza el ranking aunque otro puntúe más',
        ($ranked[0]['technician_id'] ?? null) === $onCall
            && ($ranked[0]['is_on_call'] ?? null) === true
            && in_array('RECOMENDADO', $ranked[0]['badges'] ?? [], true)
            && in_array('GUARDIA_24_7', $ranked[0]['badges'] ?? [], true)
            && in_array('EN_CURSO_OTRA_SEDE', $ranked[0]['badges'] ?? [], true)
            && ($ranked[0]['score_breakdown']['cold_chain_on_call_bonus'] ?? 0) === 100,
        json_encode($ranked[0]['badges'] ?? [])
    );

    $assert(
        '2.7 El desglose publica exactamente las seis claves del contrato',
        array_keys($ranked[0]['score_breakdown'] ?? []) === [
            'territorial_affinity',
            'onsite_presence',
            'same_zone_stops',
            'active_workload_penalty',
            'inter_location_penalty',
            'cold_chain_on_call_bonus',
        ],
        json_encode(array_keys($ranked[0]['score_breakdown'] ?? []))
    );

    $assert(
        '2.8 La carga viva y el estado de cada técnico viajan con sus claves',
        array_keys($ranked[0]['active_workload'] ?? []) === [
            'active_tickets_count',
            'pending_info_count',
            'preventive_orders_count',
        ]
            && array_keys($ranked[0]['current_status'] ?? []) === [
                'has_in_progress_stop',
                'in_progress_location_name',
                'distance_km',
            ]
    );

    $primaryRow = $byTechnician[$primaryBusy] ?? [];
    $assert(
        '2.9 El titular saturado acumula afinidad, zona, incompatibilidad y carga',
        ($primaryRow['affinity_type'] ?? '') === 'PRIMARY'
            && ($primaryRow['zone_affinity_label'] ?? '') === 'Titular de zona'
            && ($primaryRow['total_score'] ?? null) === 45
            && ($primaryRow['score_breakdown'] ?? []) === [
                'territorial_affinity' => 50,
                'onsite_presence' => 0,
                'same_zone_stops' => 20,
                'active_workload_penalty' => -10,
                'inter_location_penalty' => -15,
                'cold_chain_on_call_bonus' => 0,
            ]
            && ($primaryRow['active_workload']['active_tickets_count'] ?? null) === 2
            && in_array('TITULAR_ZONA', $primaryRow['badges'] ?? [], true)
            && in_array('EN_CURSO_OTRA_SEDE', $primaryRow['badges'] ?? [], true),
        json_encode($primaryRow['score_breakdown'] ?? [])
    );

    $onsiteRow = $byTechnician[$onsiteTech] ?? [];
    $assert(
        '2.10 La presencia in situ suma 40 y no duplica el bono de zona',
        ($onsiteRow['score_breakdown']['onsite_presence'] ?? null) === 40
            && ($onsiteRow['score_breakdown']['same_zone_stops'] ?? null) === 0
            && ($onsiteRow['total_score'] ?? null) === 35
            && ($onsiteRow['active_workload']['active_tickets_count'] ?? null) === 1
            && ($onsiteRow['current_status']['has_in_progress_stop'] ?? null) === false
            && !in_array('EN_CURSO_OTRA_SEDE', $onsiteRow['badges'] ?? [], true),
        json_encode([
            'breakdown' => $onsiteRow['score_breakdown'] ?? [],
            'total' => $onsiteRow['total_score'] ?? null,
        ])
    );

    $preventiveRow = $byTechnician[$preventiveTech] ?? [];
    $assert(
        '2.11 El preventivo del día y la pausa por sede penalizan 5 y 2',
        ($preventiveRow['score_breakdown']['active_workload_penalty'] ?? null) === -7
            && ($preventiveRow['total_score'] ?? null) === 18
            && ($preventiveRow['active_workload']['preventive_orders_count'] ?? null) === 1
            && ($preventiveRow['active_workload']['pending_info_count'] ?? null) === 1
            && in_array('SUPLENTE_ZONA', $preventiveRow['badges'] ?? [], true),
        json_encode($preventiveRow['score_breakdown'] ?? [])
    );

    $geo = new GeoDistanceService();
    $expectedDistance = $geo->calculateDistanceKm(41.3900, 2.1800, 41.4100, 2.2000);
    $onCallRow = $byTechnician[$onCall] ?? [];
    $assert(
        '2.12 La distancia Haversine usa la parada en curso del técnico (QA 5)',
        abs(((float)($onCallRow['current_status']['distance_km'] ?? 0.0)) - $expectedDistance) < 0.001
            && ($onCallRow['current_status']['has_in_progress_stop'] ?? null) === true
            && ($onCallRow['current_status']['in_progress_location_name'] ?? '') === 'Sede Sur T10',
        'esperado=' . $expectedDistance . ' · real=' . ($onCallRow['current_status']['distance_km'] ?? 'null')
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Desempate determinista (QA 5)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Desempate por fatiga ---\n";

    $backupRow = $byTechnician[$backupFree] ?? [];
    $twinRow = $byTechnician[$twinTech] ?? [];
    $assert(
        '3.1 Dos suplentes empatados a 25 ordenan primero al que lleva más tiempo sin asignación',
        ($backupRow['total_score'] ?? null) === 25
            && ($twinRow['total_score'] ?? null) === 25
            && ($backupRow['position'] ?? -1) < ($twinRow['position'] ?? -1),
        'suplente=' . ($backupRow['position'] ?? 'null') . ' · gemelo=' . ($twinRow['position'] ?? 'null')
    );

    $assert(
        '3.2 El técnico con una avería resuelta hoy conserva su referencia completada',
        ($twinRow['active_workload']['active_tickets_count'] ?? null) === 0
            && ((float)($twinRow['current_status']['distance_km'] ?? 0.0)) > 0.0
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Avería ordinaria y zona desatendida
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Sin riesgo sanitario y sin cuadrante territorial ---\n";

    $ordinary = $json($call(
        'GET',
        '/api/coordinator/incidents/' . $ordinaryIncident . '/suggested-technicians',
        [],
        $coordinatorToken
    ))['data'] ?? [];
    $ordinaryRanked = $ordinary['ranked_technicians'] ?? [];
    $assert(
        '4.1 Una avería de bebidas frías no activa el riesgo de frío ni la justificación',
        ($ordinary['incident']['is_cold_chain_risk'] ?? null) === false
            && ($ordinary['recommendation']['is_cold_chain_escalation'] ?? null) === false
            && ($ordinary['recommendation']['top_technician_id'] ?? null) === $primaryBusy
            && ($ordinaryRanked[0]['score_breakdown']['cold_chain_on_call_bonus'] ?? null) === 0,
        json_encode([
            'top' => $ordinary['recommendation']['top_technician_id'] ?? null,
            'esperado' => $primaryBusy,
        ])
    );

    $unattended = $json($call(
        'GET',
        '/api/coordinator/incidents/' . $unattendedIncident . '/suggested-technicians',
        [],
        $coordinatorToken
    ))['data'] ?? [];
    $unattendedRanked = $unattended['ranked_technicians'] ?? [];
    $affinities = array_values(array_unique(array_column($unattendedRanked, 'affinity_type')));
    $distances = array_map(static fn (array $row): float => (float)($row['current_status']['distance_km'] ?? 0.0), $unattendedRanked);
    $sortedDistances = $distances;
    sort($sortedDistances);

    $assert(
        '4.2 Sin zona asignada se declara zona desatendida (RF-06.1)',
        ($unattended['recommendation']['unattended_zone'] ?? null) === true
            && $affinities === ['NONE']
            && str_contains((string)($unattended['recommendation']['explanation'] ?? ''), 'Sin técnicos disponibles'),
        json_encode($affinities)
    );

    // Sin parada conocida, la referencia es la Base Central (QA 5): se compara con
    // la distancia real a la base sembrada, no con un número inventado por la suite.
    $unattendedIndex = array_search($backupFree, array_column($unattendedRanked, 'technician_id'), true);
    $expectedBaseDistance = $geo->calculateDistanceKm(41.3700, 2.1600, 41.3935, 2.1701);
    $backupDistance = $unattendedIndex !== false
        ? (float)($unattendedRanked[$unattendedIndex]['current_status']['distance_km'] ?? 0.0)
        : -1.0;

    $assert(
        '4.3 La zona desatendida ordena por proximidad Haversine desde la Base Central',
        $distances === $sortedDistances
            && abs($backupDistance - $expectedBaseDistance) < 0.001,
        'base esperada=' . $expectedBaseDistance . ' · real=' . $backupDistance . ' · orden=' . json_encode($distances)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Código de ticket y rendimiento medido (RNF-01)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Resolución por código de ticket y mediana de 20 repeticiones ---\n";

    $byTicket = $call(
        'GET',
        '/api/coordinator/incidents/INC-20261020-T1001/suggested-technicians',
        [],
        $coordinatorToken
    );
    $assert(
        '5.1 El código de ticket resuelve el mismo ranking que el identificador primario',
        $byTicket->getStatusCode() === 200
            && array_column($json($byTicket)['data']['ranked_technicians'] ?? [], 'technician_id')
                === array_column($ranked, 'technician_id')
    );

    $durations = [];
    $signatures = [];
    for ($i = 0; $i < 20; $i++) {
        $startedAt = hrtime(true);
        $repetition = $call('GET', '/api/coordinator/incidents/' . $criticalIncident . '/suggested-technicians', [], $coordinatorToken);
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
        $durations[] = $elapsedMs;
        $repetitionData = $json($repetition)['data'] ?? [];
        $signatures[] = implode(',', array_column($repetitionData['ranked_technicians'] ?? [], 'technician_id'))
            . '|' . implode(',', array_column($repetitionData['ranked_technicians'] ?? [], 'total_score'));
    }

    sort($durations);
    $median = $durations[intdiv(count($durations), 2)];
    $assert(
        '5.2 La mediana de 20 repeticiones reales queda por debajo de 100 ms (RNF-01)',
        count(array_unique($signatures)) === 1 && $median < 100.0,
        sprintf('mediana=%.2f ms · min=%.2f ms · max=%.2f ms', $median, $durations[0], $durations[19])
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, SUGGEST_API_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }
}

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-ZONE-10 (SUGERENCIAS) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
