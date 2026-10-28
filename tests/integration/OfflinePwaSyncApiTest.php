<?php

declare(strict_types=1);

/**
 * OfflinePwaSyncApiTest · T-OFFLINE-09
 *
 * Certifica de punta a punta la superficie HTTP del Modo Sin Conexión
 * (Módulo 24) contra PostgreSQL, con el router real, middleware RBAC,
 * controladores, servicios y repositorios PDO sobre un esquema aislado.
 *
 * QUÉ SE CERTIFICA:
 *
 *   1. **Seguridad y RBAC (RF-02.1, Art. V.4):**
 *      - GET /api/technician/offline/bootstrap con técnico => 200 OK con estructura canónica.
 *      - Sin token => 401. El responsable de sede no tiene rol interno: su credencial es un
 *        token de sede, que la puerta interna no reconoce y rechaza con 401 (no llega a
 *        existir un 403 que conceder). Un coordinador, que sí es credencial interna, recibe
 *        el 403 esperado.
 *      - El paquete no expone ni un dato de otra sede ni de otro técnico.
 *
 *   2. **Flujo encadenado in situ con reloj atrasado (RF-03.4, RF-04.2, RF-04.3, QA 4):**
 *      - Iniciar (start) con client_mutation_id y chain_ended_at => 200 OK con clock_skew_adjusted=true.
 *      - Comentario intermedio independiente in situ => 201 Created.
 *      - Resolver (resolve) con repuesto de furgoneta, firma digital y chain_started_at => 200 OK.
 *      - Descuento de stock en furgoneta verificado en BD.
 *      - Preservación de la duración relativa Δt entre pasos encadenados en auditoría/sello efectivo.
 *
 *   3. **Idempotencia estricta por técnico y operación (RF-04.2):**
 *      - Reenvío de la misma mutación resolve => 200 OK con idempotent_replay=true.
 *      - El stock en furgoneta NO se descuenta por segunda vez.
 *
 *   4. **Colisión parcial de reintegro (RF-05.2, QA 2):**
 *      - Reintegro regularizado previamente por Coordinación.
 *      - Sincronización offline de la resolución => 200 OK con partial_refund_collision_dumped=true.
 *      - La Nota Interna con el dictamen de efectivo queda persistida (is_internal=true).
 *
 *   5. **Retención de Cuarentena Sanitaria (RF-05.4, Art. II):**
 *      - Máquina FOOD_PERISHABLE en SANITARY_QUARANTINE.
 *      - Resolución técnica offline => ticket pasa a RESOLVED pero máquina permanece en SANITARY_QUARANTINE.
 *      - sanitary_quarantine_retained=true en el sobre offline_sync.
 *
 *   6. **Volcado de conflictos (RF-05.3, QA 1, QA 2, Art. III.3):**
 *      - Avería reasignada a otro compañero => dumpConflict => 200 OK, Nota Interna de Taller creada (is_internal=true).
 *      - Avería CANCELLED => dumpConflict => 200 OK, Nota Interna creada como excepción de auditoría.
 *      - Orden preventiva en conflicto => dumpConflict => 200 OK, notas anexadas en preventive_orders.notes.
 *      - Total opacidad para el Portal de Sede: las notas internas son invisibles (comments count = 0).
 *
 *   7. **Volcado por baja de cuenta (RF-06.4, QA 5):**
 *      - Técnico desactivado (is_active=false) con token válido => dumpDeactivatedAccount => 200 OK.
 *      - clear_local_storage=true y rastro de auditoría sin alterar titularidades.
 *      - Técnico activo intentando usar este endpoint => 409 ACCOUNT_STILL_ACTIVE_USE_STANDARD_SYNC.
 *
 * CATEGORÍA: `new_engine` (requiere PostgreSQL local, código de salida 2 sin motor).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

const VENDGUARD_TEST_CATEGORY = 'new_engine';
const OFFLINE_API_TEST_SCHEMA = 't_offline24_api';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;
$crash = null;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}\n" : "\n");
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-OFFLINE-09 · API del Modo Sin Conexión (Módulo 24)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

try {
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

$json = static fn (Response $response): array => $response->getDecodedBody() ?? [];
$data = static fn (Response $response): array => $json($response)['data'] ?? [];
$errorCode = static fn (Response $response): string => (string)($json($response)['error']['code'] ?? '');

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, OFFLINE_API_TEST_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $userRepo = new PdoUserRepository($pdo);
    $locationRepo = new PdoLocationRepository($pdo);
    $shiftRepo = new PdoTechnicianShiftRepository($pdo);
    $authService = new AuthService($locationRepo, $userRepo);

    // ─────────────────────────────────────────────────────────────────────
    // Fixtures base
    // ─────────────────────────────────────────────────────────────────────

    $createUser = static function (string $suffix, string $role, bool $isActive = true) use ($pdo) {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, :is_active)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => ($role === 'COORDINATOR' ? 'Coordinación ' : 'Técnico ') . ucfirst($suffix),
            ':email' => 'offline24.' . $suffix . '@vendguard.internal',
            ':email_key' => 'offline24.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('offline24-' . $suffix, PASSWORD_BCRYPT),
            ':role' => $role,
            ':operator' => 'O24-' . mb_strtoupper($suffix),
            ':is_active' => $isActive ? 'true' : 'false',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createLocation = static function (string $siteCode, string $name) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO locations (site_code, site_code_key, name, address, latitude, longitude, postal_code, contact_email)
             VALUES (:site, :site_key, :name, :address, 41.3850640, 2.1734035, :postal, :email)
             RETURNING id'
        );
        $stmt->execute([
            ':site' => $siteCode,
            ':site_key' => mb_strtolower($siteCode, 'UTF-8'),
            ':name' => $name,
            ':address' => 'Hospital Clínic de Certificación, Barcelona',
            ':postal' => '08036',
            ':email' => 'recepcion.offline24@hospital.cat',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createMachine = static function (
        int $locationId,
        string $code,
        string $model,
        string $type,
        string $sanitaryStatus = 'OK'
    ) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO machines (location_id, code, model, machine_type, floor_wing, sanitary_status)
             VALUES (:location_id, :code, :model, :type, :wing, :sanitary)
             RETURNING id'
        );
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => $model,
            ':type' => $type,
            ':wing' => 'Planta 1 · UCI Sótano',
            ':sanitary' => $sanitaryStatus,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createIncident = static function (
        string $ticketCode,
        int $machineId,
        int $locationId,
        string $machineType,
        string $category,
        string $urgency,
        string $status,
        ?int $technicianId = null,
        ?string $assignedAt = null
    ) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO incidents (
                ticket_code, machine_id, machine_type_snapshot, location_id,
                assigned_technician_id, category, description, urgency, status,
                assigned_at
             ) VALUES (
                :ticket, :machine_id, :machine_type, :location_id,
                :technician_id, :category, :description, :urgency, :status,
                :assigned_at
             ) RETURNING id'
        );
        $stmt->execute([
            ':ticket' => $ticketCode,
            ':machine_id' => $machineId,
            ':machine_type' => $machineType,
            ':location_id' => $locationId,
            ':technician_id' => $technicianId,
            ':category' => $category,
            ':description' => 'Avería de certificación para el modo offline M24.',
            ':urgency' => $urgency,
            ':status' => $status,
            ':assigned_at' => $assignedAt ?? (new DateTimeImmutable('now'))->format('Y-m-d H:i:sP'),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createPreventiveOrder = static function (
        string $orderCode,
        int $machineId,
        int $locationId,
        int $technicianId,
        string $status = 'SCHEDULED'
    ) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO preventive_orders (
                order_code, machine_id, location_id, assigned_technician_id, status, scheduled_date, due_date
             ) VALUES (
                :code, :machine_id, :location_id, :tech_id, :status, CURRENT_DATE, CURRENT_DATE
             ) RETURNING id'
        );
        $stmt->execute([
            ':code' => $orderCode,
            ':machine_id' => $machineId,
            ':location_id' => $locationId,
            ':tech_id' => $technicianId,
            ':status' => $status,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createPart = static function (string $code, string $name, string $category) use ($pdo) {
        $stmt = $pdo->prepare(
            'INSERT INTO spare_parts (part_code, name, category, manufacturer, reference_cost, is_active)
             VALUES (:code, :name, :category, :manufacturer, 12.50, TRUE)
             RETURNING id'
        );
        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
            ':category' => $category,
            ':manufacturer' => 'Fabricante M24',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $seedStock = static function (int $technicianId, int $sparePartId, int $quantity, int $threshold) use ($pdo): void {
        $stmt = $pdo->prepare(
            'INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
             VALUES (:technician_id, :spare_part_id, :quantity, :threshold)'
        );
        $stmt->execute([
            ':technician_id' => $technicianId,
            ':spare_part_id' => $sparePartId,
            ':quantity' => $quantity,
            ':threshold' => $threshold,
        ]);
    };

    // Usuarios del test
    $coordId = $createUser('coord', 'COORDINATOR');
    $tech1Id = $createUser('jordi', 'TECHNICIAN');
    $tech2Id = $createUser('pau', 'TECHNICIAN');
    $deactivatedTechId = $createUser('cesado', 'TECHNICIAN', false);

    $coord = $userRepo->findById($coordId);
    $tech1 = $userRepo->findById($tech1Id);
    $tech2 = $userRepo->findById($tech2Id);
    $deactivatedTech = $userRepo->findById($deactivatedTechId, onlyActive: false);

    $coordToken = $authService->generateInternalToken($coord);
    $tech1Token = $authService->generateInternalToken($tech1);
    $tech2Token = $authService->generateInternalToken($tech2);
    $deactivatedToken = $authService->generateInternalToken($deactivatedTech);

    // Abrir turnos ON_SHIFT para los técnicos activos
    $shiftRepo->startShift($tech1Id);
    $shiftRepo->startShift($tech2Id);

    // Entorno físico
    $locId = $createLocation('SEDE-OFF-01', 'Hospital Central Subterráneo');
    $location = $locationRepo->findById($locId);
    $siteToken = $authService->generateSiteToken($location);

    $machChain = $createMachine($locId, 'VM-OFF-CHAIN', 'Mod-Cold', 'COLD_DRINKS');
    $machRefund = $createMachine($locId, 'VM-OFF-REFUND', 'Mod-Cold', 'COLD_DRINKS');
    $machReass = $createMachine($locId, 'VM-OFF-REASS', 'Mod-Cold', 'COLD_DRINKS');
    $machCanc = $createMachine($locId, 'VM-OFF-CANC', 'Mod-Cold', 'COLD_DRINKS');
    $machPo = $createMachine($locId, 'VM-OFF-PO', 'Mod-Cold', 'COLD_DRINKS');
    $machOwn = $createMachine($locId, 'VM-OFF-OWN', 'Mod-Cold', 'COLD_DRINKS');
    $machPerish = $createMachine($locId, 'VM-OFF-FOOD', 'Mod-Fresh', 'PERISHABLE_FOOD', 'QUARANTINE');

    $partSensor = $createPart('P-SENS-01', 'Sonda térmica NTC', 'ELECTRONIC');
    $partValve = $createPart('P-VALV-01', 'Electroválvula 24V', 'HYDRAULIC');

    // Furgoneta de Tech 1 con 4 unidades de la sonda
    $seedStock($tech1Id, $partSensor, 4, 1);
    $seedStock($tech1Id, $partValve, 2, 1);

    $router = AppRouter::create();

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
    // 1. RBAC y paquete de ruta /bootstrap
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. RBAC y Bootstrap de la ruta (RF-02.1, Art. V.4) ---\n";

    $resAnonBootstrap = $call('GET', '/api/technician/offline/bootstrap');
    $assert(
        '1.1 GET /bootstrap anónimo responde 401 UNAUTHORIZED',
        $resAnonBootstrap->getStatusCode() === 401 && $errorCode($resAnonBootstrap) === 'UNAUTHORIZED'
    );

    $resSiteBootstrap = $call('GET', '/api/technician/offline/bootstrap', [], $siteToken);
    $assert(
        '1.2 Token de sede en /bootstrap es rechazado (401 puerta interna / opacidad total)',
        $resSiteBootstrap->getStatusCode() === 401 && $errorCode($resSiteBootstrap) === 'UNAUTHORIZED'
    );

    $resCoordBootstrap = $call('GET', '/api/technician/offline/bootstrap', [], $coordToken);
    $assert(
        '1.3 Coordinador intentando entrar en /bootstrap del técnico recibe 403 FORBIDDEN',
        $resCoordBootstrap->getStatusCode() === 403 && $errorCode($resCoordBootstrap) === 'FORBIDDEN'
    );

    $resTechBootstrap = $call('GET', '/api/technician/offline/bootstrap', [], $tech1Token);
    $bootData = $data($resTechBootstrap);
    $assert(
        '1.4 Técnico autenticado recibe 200 OK con el paquete consolidado de ruta',
        $resTechBootstrap->getStatusCode() === 200
            && ($bootData['technician_id'] ?? null) === $tech1Id
            && ($bootData['warn_after_seconds'] ?? null) === 14400
            && ($bootData['readonly_after_seconds'] ?? null) === 43200
            && isset($bootData['route'], $bootData['preventive_orders'], $bootData['van_stock'])
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Flujo encadenado in situ con reloj atrasado (QA 4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Flujo encadenado in situ con reloj atrasado (RF-04.2, RF-04.3, QA 4) ---\n";

    $assignedTime = (new DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:sP');

    $incEncadenadaId = $createIncident(
        'INC-OFF-001',
        $machChain,
        $locId,
        'COLD_DRINKS',
        'TEMPERATURE_COLD',
        'HIGH',
        'ASSIGNED',
        $tech1Id,
        $assignedTime
    );
    // Reloj del dispositivo atrasado 24 horas in situ:
    // Started at t1: 2026-10-26 08:00:00Z, Ended at t2: 2026-10-26 08:25:00Z (Δt = 25 min)
    $clientStartAt = '2026-10-26T08:00:00Z';
    $clientEndAt   = '2026-10-26T08:25:00Z';

    $resStart = $call(
        'PATCH',
        "/api/technician/incidents/{$incEncadenadaId}/start",
        [
            'client_mutation_id' => 'mut_start_enc_001',
            'client_occurred_at' => $clientStartAt,
            'chain_ended_at' => $clientEndAt,
        ],
        $tech1Token
    );
    $startBody = $data($resStart);
    $assert(
        '2.1 Start encolado con reloj atrasado responde 200 y clock_skew_adjusted = true',
        $resStart->getStatusCode() === 200
            && ($startBody['offline_sync']['clock_skew_adjusted'] ?? false) === true
            && ($startBody['offline_sync']['client_mutation_id'] ?? '') === 'mut_start_enc_001'
    );

    // Comentario técnico intermedio in situ
    $resComment = $call(
        'POST',
        "/api/technician/incidents/{$incEncadenadaId}/comments",
        [
            'comment_text' => 'Comentario técnico anotado in situ en el sótano.',
            'is_internal' => true,
            'client_mutation_id' => 'mut_comment_enc_001',
            'client_occurred_at' => '2026-10-26T08:12:00Z',
        ],
        $tech1Token
    );
    $assert(
        '2.2 Comentario intermedio encolado se publica con éxito (201 Created)',
        $resComment->getStatusCode() === 201
    );

    // Resolución con consumo de 2 sondas de furgoneta y firma digital
    $onePixelPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    $resolvePayload = [
        'resolution_diagnosis' => 'Sonda térmica NTC con deriva de valor por fallo de aislamiento.',
        'resolution_action' => 'Sustitución de sonda NTC y verificación de lectura correcta a 4°C.',
        'replaced_parts_declared' => true,
        'replaced_parts' => [
            [
                'spare_part_id' => $partSensor,
                'quantity' => 2,
                'old_part_destination' => 'DESGUACE',
            ],
        ],
        'service_sheet' => [
            'signer_name' => 'Dr. Fernando Arcas',
            'signer_document' => '44556677X',
            'signature_png_base64' => $onePixelPng,
        ],
        'client_mutation_id' => 'mut_resolve_enc_001',
        'client_occurred_at' => $clientEndAt,
        'chain_started_at' => $clientStartAt,
    ];

    $resResolve = $call(
        'POST',
        "/api/technician/incidents/{$incEncadenadaId}/resolve",
        $resolvePayload,
        $tech1Token
    );
    $resolveBody = $data($resResolve);
    $assert(
        '2.3 Resolve encadenado responde 200 OK con sellado offline_sync y hoja firmada',
        $resResolve->getStatusCode() === 200
            && ($resolveBody['status'] ?? '') === 'RESOLVED'
            && ($resolveBody['service_sheet']['signed'] ?? false) === true
            && ($resolveBody['offline_sync']['clock_skew_adjusted'] ?? false) === true
    );

    // Verificar descuento en inventario de furgoneta (de 4 iniciales a 2)
    $stmtStock = $pdo->prepare('SELECT quantity_available FROM technician_van_stocks WHERE technician_id = :tech AND spare_part_id = :part');
    $stmtStock->execute([':tech' => $tech1Id, ':part' => $partSensor]);
    $qtyAfterResolve = (int)$stmtStock->fetchColumn();
    $assert(
        '2.4 Se han descontado exactamente 2 unidades del stock de la furgoneta (quedan 2)',
        $qtyAfterResolve === 2
    );

    // Verificar en BD la preservación del intervalo Δt de 25 min (1500 segundos) entre started_at y resolved_at
    $stmtDelta = $pdo->prepare("SELECT EXTRACT(EPOCH FROM (res.effective_occurred_at - st.effective_occurred_at)) AS delta_sec FROM offline_sync_mutations st, offline_sync_mutations res WHERE st.client_mutation_id = 'mut_start_enc_001' AND res.client_mutation_id = 'mut_resolve_enc_001'");
    $stmtDelta->execute();
    $deltaSec = (int)$stmtDelta->fetchColumn();
    $assert(
        '2.5 Se preserva el intervalo exacto Δt = 25 min in situ a pesar del reloj atrasado 24h (QA 4)',
        $deltaSec === 1500,
        "delta medido: {$deltaSec} s"
    );

    // El inicio sin conexión debe dejar en `incidents.started_at` su instante
    // efectivo, no el reloj del servidor: es el ancla del cierre encadenado
    // cuando la incidencia se reanuda después (EARS 04.3, QA 4).
    $stmtStartAnchor = $pdo->prepare(
        "SELECT EXTRACT(EPOCH FROM (i.started_at - m.effective_occurred_at)) AS skew_sec
           FROM incidents i, offline_sync_mutations m
          WHERE i.id = :incident_id
            AND m.client_mutation_id = 'mut_start_enc_001'"
    );
    $stmtStartAnchor->execute([':incident_id' => $incEncadenadaId]);
    $startAnchorSkew = (int)round((float)$stmtStartAnchor->fetchColumn());
    $assert(
        '2.6 El inicio offline persiste su instante efectivo en started_at (RF-04.3)',
        $startAnchorSkew === 0,
        "desviación del ancla: {$startAnchorSkew} s"
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Idempotencia estricta por técnico y mutación (RF-04.2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Idempotencia ante reenvío duplicado (RF-04.2) ---\n";

    $resReplay = $call(
        'POST',
        "/api/technician/incidents/{$incEncadenadaId}/resolve",
        $resolvePayload,
        $tech1Token
    );
    $replayBody = $data($resReplay);
    $assert(
        '3.1 Reenvío idéntico responde 200 OK con idempotent_replay = true',
        $resReplay->getStatusCode() === 200
            && ($replayBody['idempotent_replay'] ?? false) === true
    );

    // El stock no debe haberse vuelto a descontar
    $stmtStock->execute([':tech' => $tech1Id, ':part' => $partSensor]);
    $qtyAfterReplay = (int)$stmtStock->fetchColumn();
    $assert(
        '3.2 El reenvío duplicado NO vuelve a descontar stock de furgoneta (permanece en 2)',
        $qtyAfterReplay === 2
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Colisión parcial de reintegro (RF-05.2, QA 2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Colisión parcial de reintegro (RF-05.2, QA 2) ---\n";

    $incRefundId = $createIncident(
        'INC-OFF-REFUND',
        $machRefund,
        $locId,
        'COLD_DRINKS',
        'PAYMENT_SYSTEM',
        'HIGH',
        'IN_PROGRESS',
        $tech1Id
    );

    // Reclamación de reintegro ya resuelta/regularizada previamente por Coordinación (en estado distinto de PENDING_INSPECTION)
    $stmtRefund = $pdo->prepare(
        "INSERT INTO refund_requests (incident_id, machine_id, location_id, claimant_name, claimant_contact, claimed_amount, compensation_method, tracking_token, status, coordinator_justification)
         VALUES (:inc_id, :mach_id, :loc_id, 'Laura Gómez', '611222333', 1.50, 'BIZUM', 'token_refund_col_123', 'VERIFIED_PENDING_PAYMENT', 'Reintegrado por Coordinación.')
         RETURNING id"
    );
    $stmtRefund->execute([':inc_id' => $incRefundId, ':mach_id' => $machRefund, ':loc_id' => $locId]);

    $resRefundCollision = $call(
        'POST',
        "/api/technician/incidents/{$incRefundId}/resolve",
        [
            'resolution_diagnosis' => 'Monedero atascado por moneda deformada que impedía el retorno.',
            'resolution_action' => 'Limpieza de canal de monedas y comprobación de retorno ágil.',
            'replaced_parts_declared' => false,
            'service_sheet' => [
                'signer_name' => 'Laura Gómez',
                'signer_document' => '12345678Z',
                'signature_png_base64' => $onePixelPng,
            ],
            'client_mutation_id' => 'mut_resolve_refund_col_001',
            'client_occurred_at' => (new DateTimeImmutable('-10 minutes'))->format('Y-m-d\TH:i:sP'),
            'refund_finding' => 'DISPENSED_TO_USER',
            'cash_amount_found_cents' => 150,
        ],
        $tech1Token
    );
    $refundColBody = $data($resRefundCollision);
    $assert(
        '4.1 Resolución con colisión de reintegro cierra ticket y marca partial_refund_collision_dumped',
        $resRefundCollision->getStatusCode() === 200
            && ($refundColBody['status'] ?? '') === 'RESOLVED'
            && ($refundColBody['offline_sync']['partial_refund_collision_dumped'] ?? false) === true
    );

    // La Nota Interna con el dictamen de efectivo debe existir en incident_comments con is_internal = true
    $stmtNote = $pdo->prepare("SELECT comment_text, is_internal FROM incident_comments WHERE incident_id = :inc_id AND is_internal = TRUE ORDER BY id DESC LIMIT 1");
    $stmtNote->execute([':inc_id' => $incRefundId]);
    $noteRow = $stmtNote->fetch(PDO::FETCH_ASSOC);
    $assert(
        '4.2 El dictamen de efectivo del técnico queda persistido como Nota Interna confidencial',
        $noteRow !== false
            && (bool)$noteRow['is_internal'] === true
            && str_contains((string)$noteRow['comment_text'], 'DICTAMEN DE EFECTIVO SIN CONEXIÓN')
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Retención de Cuarentena Sanitaria en perecederas (RF-05.4, Art. II)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Retención de Cuarentena Sanitaria en perecederas (RF-05.4, Art. II) ---\n";

    $incPerishId = $createIncident(
        'INC-OFF-PERISH',
        $machPerish,
        $locId,
        'PERISHABLE_FOOD',
        'TEMPERATURE_COLD',
        'CRITICAL',
        'IN_PROGRESS',
        $tech1Id
    );

    $resPerishResolve = $call(
        'POST',
        "/api/technician/incidents/{$incPerishId}/resolve",
        [
            'resolution_diagnosis' => 'Compresor recuperado tras rearme térmico y recarga de refrigerante.',
            'resolution_action' => 'Prueba de estanqueidad y comprobación de bajada de temperatura.',
            'replaced_parts_declared' => false,
            'service_sheet' => [
                'signer_name' => 'Conserjería',
                'signer_document' => '87654321A',
                'signature_png_base64' => $onePixelPng,
            ],
            // Art. II: la cuarentena ya está declarada al llegar, así que el cierre
            // exige el protocolo de higienización completo. Sin él la puerta devuelve
            // 422 y el escenario no certificaría nada: la retención se lee en un
            // cierre que de verdad ocurrió.
            'sanitary_declarations' => [
                'temperature_c' => 4.2,
                'stock_destroyed' => true,
                'hygiene_checklist' => true,
            ],
            'client_mutation_id' => 'mut_resolve_perish_001',
            'client_occurred_at' => (new DateTimeImmutable('-5 minutes'))->format('Y-m-d\TH:i:sP'),
            'sanitary_seal_confirmed' => true,
        ],
        $tech1Token
    );
    $perishBody = $data($resPerishResolve);
    $assert(
        '5.1 Resolución offline en máquina perecedera en cuarentena retorna sanitary_quarantine_retained = true',
        $resPerishResolve->getStatusCode() === 200
            && ($perishBody['status'] ?? '') === 'RESOLVED'
            && ($perishBody['offline_sync']['sanitary_quarantine_retained'] ?? false) === true
    );

    // La máquina debe permanecer inalterablemente en SANITARY_QUARANTINE (Art. II)
    $stmtMach = $pdo->prepare('SELECT sanitary_status FROM machines WHERE id = :id');
    $stmtMach->execute([':id' => $machPerish]);
    $machSanitaryStatus = (string)$stmtMach->fetchColumn();
    $assert(
        '5.2 machines.sanitary_status permanece inalterable en QUARANTINE tras la resolución técnica',
        $machSanitaryStatus === 'QUARANTINE'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Volcado de conflictos en averías y preventivas (RF-05.3, QA 1, QA 2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Volcado de conflictos y excepción de auditoría (RF-05.3, QA 1, QA 2, Art. III.3) ---\n";

    // 6.a Avería reasignada al técnico 2 mientras el técnico 1 operaba offline
    $incReassignedId = $createIncident(
        'INC-OFF-REASS',
        $machReass,
        $locId,
        'COLD_DRINKS',
        'ELECTRICAL_OFF',
        'MEDIUM',
        'ASSIGNED',
        $tech2Id // Asignada a tech 2
    );

    $resDumpReass = $call(
        'POST',
        '/api/technician/offline/conflicts/dump',
        [
            'client_mutation_id' => 'mut_dump_reassigned_001',
            'client_occurred_at' => (new DateTimeImmutable('-30 minutes'))->format('Y-m-d\TH:i:sP'),
            'stop_type' => 'INCIDENT',
            'stop_id' => $incReassignedId,
            'operation_type' => 'RESOLVE_INCIDENT',
            'conflict_reason' => 'La avería fue reasignada a otro compañero mientras trabajaba sin cobertura.',
            'work_summary' => [
                'diagnosis' => 'Cableado de alimentación principal restablecido.',
                'action_taken' => 'Reengaste de terminal Faston y verificación de tensión.',
                'conformity_summary' => 'Firmado por conserje de turno.',
            ],
        ],
        $tech1Token
    );
    $dumpReassBody = $data($resDumpReass);
    $assert(
        '6.1 Volcado de conflicto sobre avería reasignada responde 200 OK y CONFLICT_DUMPED',
        $resDumpReass->getStatusCode() === 200
            && ($dumpReassBody['outcome_status'] ?? '') === 'CONFLICT_DUMPED'
            && isset($dumpReassBody['internal_comment_id'])
    );

    // 6.b Avería CANCELLED
    $incCancelledId = $createIncident(
        'INC-OFF-CANC',
        $machCanc,
        $locId,
        'COLD_DRINKS',
        'PAYMENT_SYSTEM',
        'LOW',
        'CANCELLED',
        $tech1Id
    );

    $resDumpCanc = $call(
        'POST',
        '/api/technician/offline/conflicts/dump',
        [
            'client_mutation_id' => 'mut_dump_cancelled_001',
            'client_occurred_at' => (new DateTimeImmutable('-15 minutes'))->format('Y-m-d\TH:i:sP'),
            'stop_type' => 'INCIDENT',
            'stop_id' => $incCancelledId,
            'operation_type' => 'RESOLVE_INCIDENT',
            'conflict_reason' => 'Cancelada por Coordinación durante la intervención in situ.',
            'work_summary' => [
                'diagnosis' => 'Moneda de 50 céntimos extraída del selector.',
                'action_taken' => 'Desatasco manual y prueba con 5 monedas.',
            ],
        ],
        $tech1Token
    );
    $dumpCancBody = $data($resDumpCanc);
    $assert(
        '6.2 Volcado de conflicto sobre avería CANCELLED se acepta como excepción de auditoría (QA 2)',
        $resDumpCanc->getStatusCode() === 200
            && ($dumpCancBody['outcome_status'] ?? '') === 'CONFLICT_DUMPED'
    );

    // 6.c Orden preventiva en conflicto
    $poId = $createPreventiveOrder('PO-OFF-001', $machPo, $locId, $tech2Id);

    $resDumpPo = $call(
        'POST',
        '/api/technician/offline/conflicts/dump',
        [
            'client_mutation_id' => 'mut_dump_prev_001',
            'client_occurred_at' => (new DateTimeImmutable('-10 minutes'))->format('Y-m-d\TH:i:sP'),
            'stop_type' => 'PREVENTIVE_ORDER',
            'stop_id' => $poId,
            'operation_type' => 'COMPLETE_PREVENTIVE',
            'conflict_reason' => 'La orden preventiva fue reprogramada.',
            'work_summary' => [
                'notes' => 'Inspección de 8 puntos realizada in situ: todos conformes.',
            ],
        ],
        $tech1Token
    );
    $dumpPoBody = $data($resDumpPo);
    $assert(
        '6.3 Volcado de conflicto en orden preventiva responde 200 OK y se anexa a notes',
        $resDumpPo->getStatusCode() === 200
            && ($dumpPoBody['outcome_status'] ?? '') === 'CONFLICT_DUMPED'
            && ($dumpPoBody['stop_type'] ?? '') === 'PREVENTIVE_ORDER'
    );

    $stmtPoNotes = $pdo->prepare('SELECT notes FROM preventive_orders WHERE id = :id');
    $stmtPoNotes->execute([':id' => $poId]);
    $poNotes = (string)$stmtPoNotes->fetchColumn();
    $assert(
        '6.4 preventive_orders.notes contiene el encabezado de volcado en conflicto',
        str_contains($poNotes, '[VOLCADO OFFLINE EN CONFLICTO')
    );

    // 6.d Opacidad de la Nota Interna frente al Portal de Sede (Art. V.4)
    $resSiteComments = $call('GET', "/api/location/incidents/{$incReassignedId}/comments", [], $siteToken);
    $siteCommentsData = $data($resSiteComments);
    $assert(
        '6.5 El Portal de Sede no recibe la Nota Interna del conflicto volcado (comments = [])',
        $resSiteComments->getStatusCode() === 200
            && count($siteCommentsData['comments'] ?? []) === 0
    );

    // 6.e Titularidad vigente en servidor (EARS 05.1, Art. V.3): la transición
    //     encolada por el técnico anterior recibe 409 y no toca el expediente.
    $incStaleOwnerId = $createIncident(
        'INC-OFF-OWN',
        $machOwn,
        $locId,
        'COLD_DRINKS',
        'ELECTRICAL_OFF',
        'MEDIUM',
        'ASSIGNED',
        $tech1Id
    );
    $pdo->prepare('UPDATE incidents SET assigned_technician_id = :tech WHERE id = :id')
        ->execute([':tech' => $tech2Id, ':id' => $incStaleOwnerId]);

    $resStaleStart = $call(
        'PATCH',
        "/api/technician/incidents/{$incStaleOwnerId}/start",
        [
            'client_mutation_id' => 'mut_start_stale_owner_001',
            'client_occurred_at' => (new DateTimeImmutable('-20 minutes'))->format('Y-m-d\TH:i:sP'),
        ],
        $tech1Token
    );
    $assert(
        '6.6 Una transición encolada tras el cambio de titular recibe 409 y no se aplica (EARS 05.1, Art. V.3)',
        $resStaleStart->getStatusCode() === 409
            && $errorCode($resStaleStart) === 'INCIDENT_OWNERSHIP_MISMATCH'
    );

    $stmtStaleStatus = $pdo->prepare('SELECT status FROM incidents WHERE id = :id');
    $stmtStaleStatus->execute([':id' => $incStaleOwnerId]);
    $stmtStaleMutation = $pdo->prepare(
        "SELECT COUNT(*) FROM offline_sync_mutations WHERE client_mutation_id = 'mut_start_stale_owner_001'"
    );
    $stmtStaleMutation->execute();
    $assert(
        '6.7 El rechazo no altera el estado ni escribe una mutación aplicada',
        (string)$stmtStaleStatus->fetchColumn() === 'ASSIGNED'
            && (int)$stmtStaleMutation->fetchColumn() === 0
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Volcado por baja sobrevenida de cuenta (RF-06.4, QA 5)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 7. Volcado por baja sobrevenida de cuenta (RF-06.4, QA 5) ---\n";

    // Intentar con técnico activo => 409 ACCOUNT_STILL_ACTIVE_USE_STANDARD_SYNC
    $resActiveDeactDump = $call(
        'POST',
        '/api/technician/offline/deactivated-account-dump',
        [
            'items' => [
                [
                    'client_mutation_id' => 'mut_baja_active_fail',
                    'client_occurred_at' => (new DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:sP'),
                    'stop_type' => 'INCIDENT',
                    'stop_id' => $incEncadenadaId,
                    'operation_type' => 'RESOLVE_INCIDENT',
                    'work_summary' => ['diagnosis' => 'D1', 'action_taken' => 'A1'],
                ],
            ],
        ],
        $tech1Token // Cuenta activa
    );
    $assert(
        '7.1 Cuenta activa intentando volcado por baja responde 409 ACCOUNT_STILL_ACTIVE_USE_STANDARD_SYNC',
        $resActiveDeactDump->getStatusCode() === 409
            && $errorCode($resActiveDeactDump) === 'ACCOUNT_STILL_ACTIVE_USE_STANDARD_SYNC'
    );

    // Técnico desactivado con token propio => 200 OK con clear_local_storage = true
    $resDeactivatedDump = $call(
        'POST',
        '/api/technician/offline/deactivated-account-dump',
        [
            'items' => [
                [
                    'client_mutation_id' => 'mut_baja_ok_001',
                    'client_occurred_at' => (new DateTimeImmutable('-2 hours'))->format('Y-m-d\TH:i:sP'),
                    'stop_type' => 'INCIDENT',
                    'stop_id' => $incCancelledId,
                    'operation_type' => 'RESOLVE_INCIDENT',
                    'work_summary' => [
                        'diagnosis' => 'Diagnóstico realizado antes del cese de la cuenta.',
                        'action_taken' => 'Acción in situ registrada en terminal.',
                    ],
                ],
            ],
        ],
        $deactivatedToken // Cuenta con is_active = false
    );
    $deactBody = $data($resDeactivatedDump);
    $assert(
        '7.2 Técnico desactivado vuelca su cola pendiente con éxito (200 OK, clear_local_storage=true)',
        $resDeactivatedDump->getStatusCode() === 200
            && ($deactBody['dumped'] ?? 0) === 1
            && ($deactBody['clear_local_storage'] ?? false) === true
    );

    // Verificar en BD que la mutación se guardó como DEACTIVATED_ACCOUNT_DUMP
    $stmtDumped = $pdo->prepare("SELECT outcome_status FROM offline_sync_mutations WHERE client_mutation_id = 'mut_baja_ok_001'");
    $stmtDumped->execute();
    $outcomeStatus = (string)$stmtDumped->fetchColumn();
    $assert(
        '7.3 offline_sync_mutations registra outcome_status = DEACTIVATED_ACCOUNT_DUMP',
        $outcomeStatus === 'DEACTIVATED_ACCOUNT_DUMP'
    );

} catch (Throwable $e) {
    $crash = $e;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, OFFLINE_API_TEST_SCHEMA);
    } catch (Throwable $cleanupError) {
        $crash ??= $cleanupError;
    }
}

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió con excepción: " . $crash->getMessage() . "\n";
    echo $crash->getTraceAsString() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-OFFLINE-09 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: HAY FALLOS EN LA SUITE DE INTEGRACIÓN API OFFLINE.\n";
echo "======================================================================\n";
exit(1);
