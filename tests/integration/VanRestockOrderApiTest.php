<?php

declare(strict_types=1);

/**
 * VanRestockOrderApiTest · T-RESTOCK-11
 *
 * Certifica de punta a punta la superficie HTTP del Módulo 23 —órdenes de
 * carga de furgoneta y cruce de stock en triaje— contra PostgreSQL, con el
 * router real, el middleware de rol, los controladores, los servicios y los
 * repositorios PDO. El escenario vive en un esquema aislado sobre la conexión
 * de la aplicación, de modo que las rutas construidas por `AppRouter` trabajan
 * sobre las mismas tablas efímeras y no queda residuo entre corridas.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Flujo completo del albarán (RF-01 a RF-05, D1, D3, QA 1, QA 5).**
 *      Propuesta con saldo negativo y solicitud `PENDING` → generación por
 *      lotes excluyendo al técnico con ausencia vigente → edición en
 *      `PENDING_PICKING` con baja lógica de una línea → expedición parcial →
 *      recepción en el inventario móvil → confirmación en un toque. Se lee el
 *      saldo actualizado, el comprobante `last_confirmed_order` y los eventos
 *      de `audit_log` directamente del motor.
 *
 *   2. **Art. III.1 y QA 1.** La línea retirada no desaparece: sigue en
 *      `van_restock_order_items` con `removed_at` y `removed_by`, y la
 *      proyección operativa la excluye. No se ejecuta un solo `DELETE`.
 *
 *   3. **Sin efecto colateral sobre averías (C5, QA 3).** La confirmación móvil
 *      no altera el estado de las incidencias ni de las solicitudes de repuesto:
 *      se capturan antes y después y deben ser idénticas.
 *
 *   4. **Cruce de stock en los dos niveles (RF-06, D2, Art. II).** El endpoint
 *      enriquecido publica `EXPLICIT_REQUESTS` y `CATEGORY_COMPATIBILITY`, y
 *      `total_score` y `score_breakdown` de todos los candidatos son idénticos
 *      antes y después de mover el stock de la furgoneta: el cruce es
 *      informativo y jamás modifica el ranking ni el escalado sanitario.
 *
 *   5. **Concurrencia y transiciones (D1, D3).** Una segunda orden abierta
 *      responde `409 OPEN_RESTOCK_ORDER_ALREADY_EXISTS`; confirmar dos veces la
 *      misma orden responde `409 INVALID_RESTOCK_ORDER_TRANSITION` y no vuelve
 *      a sumar unidades.
 *
 *   6. **Aislamiento y privacidad (Art. V.4, RNF-03, D4).** Otro técnico no
 *      puede confirmar la orden (`403 RESTOCK_ORDER_OWNERSHIP_MISMATCH`); la
 *      sesión de sede, que vive en otro realm de autenticación, es rechazada
 *      por la puerta interna antes de evaluar roles (`401 UNAUTHORIZED`) y no
 *      entra ni en la superficie de Coordinación ni en la del técnico. El
 *      saneador del portal de sede retira el cruce de furgoneta y ninguna
 *      respuesta del módulo transporta coste o precio.
 *
 *   7. **Cancelación justificada (Art. V.1, RF-04.2).** Menos de 20 caracteres
 *      reales se rechaza con `422`; una justificación válida cancela la orden
 *      sin tocar el saldo y deja su rastro de auditoría.
 *
 * CATEGORÍA: `new_engine`
 *   Exige el PostgreSQL local. Sin motor responde código 2, nunca un verde
 *   silencioso.
 *
 * LIMPIEZA: el esquema aislado se retira con `CASCADE` al terminar, incluso si
 * una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.battery && php tests/integration/VanRestockOrderApiTest.php
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
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado sobre la conexión de la aplicación. */
const RESTOCK_API_TEST_SCHEMA = 't_restock23_api';

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
echo " VENDGUARD · T-RESTOCK-11 · API de órdenes de carga (Módulo 23)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

try {
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

/** Cuerpo JSON completo de una respuesta. @return array<string, mixed> */
$json = static fn (Response $response): array => $response->getDecodedBody() ?? [];

/** Bloque `data` del sobre de éxito. @return array<string, mixed> */
$data = static fn (Response $response): array => $json($response)['data'] ?? [];

/** Código canónico del bloque de error. */
$errorCode = static fn (Response $response): string => (string)($json($response)['error']['code'] ?? '');

/** Recolector recursivo de claves de un payload. @return list<string> */
$collectKeys = static function (array $payload) use (&$collectKeys): array {
    $keys = [];
    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        if (is_array($value)) {
            $keys = array_merge($keys, $collectKeys($value));
        }
    }

    return $keys;
};

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, RESTOCK_API_TEST_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    // ─────────────────────────────────────────────────────────────────────
    // Semilla
    // ─────────────────────────────────────────────────────────────────────

    $createUser = static function (string $suffix, string $role) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => ($role === 'COORDINATOR' ? 'Coordinación ' : 'Técnico ') . ucfirst($suffix),
            ':email' => 'restock23.' . $suffix . '@vendguard.internal',
            ':email_key' => 'restock23.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('restock23-' . $suffix, PASSWORD_BCRYPT),
            ':role' => $role,
            ':operator' => 'R23-' . mb_strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createPart = static function (string $code, string $name, string $category) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO spare_parts (part_code, name, category, manufacturer, reference_cost, is_active)
             VALUES (:code, :name, :category, :manufacturer, :cost, TRUE)
             RETURNING id'
        );
        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
            ':category' => $category,
            ':manufacturer' => 'Fabricante de certificación T23',
            ':cost' => 19.95,
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
            ':address' => 'Carrer de la Certificació 23, Barcelona',
            ':postal' => '08001',
            ':email' => 'recepcion.t23@vendguard.test',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createMachine = static function (int $locationId, string $code, string $model, string $type) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
             VALUES (:location_id, :code, :model, :type, :wing)
             RETURNING id'
        );
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => $model,
            ':type' => $type,
            ':wing' => 'Planta baja · Ala norte',
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
            ':description' => 'Avería sembrada por la certificación T-RESTOCK-11.',
            ':urgency' => $urgency,
            ':status' => $status,
            ':assigned_at' => $assignedAt,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createPendingRequest = static function (int $incidentId, int $sparePartId, int $quantity, int $requestedBy) use ($pdo): int {
        $stmt = $pdo->prepare(
            "INSERT INTO spare_part_requests (incident_id, spare_part_id, is_out_of_catalog, quantity, status, requested_by_user_id)
             VALUES (:incident_id, :spare_part_id, FALSE, :quantity, 'PENDING', :requested_by)
             RETURNING id"
        );
        $stmt->execute([
            ':incident_id' => $incidentId,
            ':spare_part_id' => $sparePartId,
            ':quantity' => $quantity,
            ':requested_by' => $requestedBy,
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

    $coordinatorId = $createUser('coord', 'COORDINATOR');
    $technicianId = $createUser('alfa', 'TECHNICIAN');
    $otherTechnicianId = $createUser('beta', 'TECHNICIAN');
    $absentTechnicianId = $createUser('ausente', 'TECHNICIAN');

    $partHeater = $createPart('R23-HEATER-01', 'Resistencia calefactora 230V', 'MECHANICAL');
    $partFilter = $createPart('R23-FILTER-01', 'Filtro de agua de 5 micras', 'MECHANICAL');
    $partFan = $createPart('R23-FAN-01', 'Ventilador de condensador', 'MECHANICAL');

    // Nivel 1: la avería principal pide la resistencia y la furgoneta arrastra un
    // descuadre negativo. El filtro está bajo umbral y la edición lo retirará.
    // Nivel 2: el ventilador no se pide en ninguna avería, pero es compatible con
    // el modelo de máquina y está en la furgoneta.
    $seedStock($technicianId, $partHeater, -2, 2);
    $seedStock($technicianId, $partFilter, 1, 3);
    $seedStock($technicianId, $partFan, 5, 0);
    $seedStock($otherTechnicianId, $partHeater, 10, 2);
    $seedStock($absentTechnicianId, $partFilter, 0, 2);

    $locationId = $createLocation('SEDE-T23', 'Hospital de Certificación T23');
    $machineMain = $createMachine($locationId, 'MAQ-T23-01', 'Modelo T23', 'COLD_DRINKS');
    $machineReady = $createMachine($locationId, 'MAQ-T23-02', 'Modelo T23', 'COLD_DRINKS');
    $machineShared = $createMachine($locationId, 'MAQ-T23-03', 'Modelo T23', 'COLD_DRINKS');
    $machineOther = $createMachine($locationId, 'MAQ-T23-04', 'Modelo T23', 'COLD_DRINKS');
    $machineLevelTwo = $createMachine($locationId, 'MAQ-T23-05', 'Modelo T23', 'COLD_DRINKS');

    $compat = $pdo->prepare(
        'INSERT INTO spare_part_compatibilities (spare_part_id, machine_model, is_active)
         VALUES (:spare_part_id, :machine_model, TRUE)'
    );
    $compat->execute([':spare_part_id' => $partFan, ':machine_model' => 'Modelo T23']);

    $now = (new DateTimeImmutable())->format('Y-m-d H:i:sP');

    $incidentMain = $createIncident(
        'INC-20261026-T2301',
        $machineMain,
        $locationId,
        'COLD_DRINKS',
        'PRODUCT_JAM',
        'HIGH',
        'ASSIGNED',
        $technicianId,
        $now
    );
    $incidentReady = $createIncident(
        'INC-20261026-T2302',
        $machineReady,
        $locationId,
        'COLD_DRINKS',
        'PRODUCT_JAM',
        'MEDIUM',
        'ASSIGNED',
        $technicianId,
        $now
    );
    $incidentShared = $createIncident(
        'INC-20261026-T2303',
        $machineShared,
        $locationId,
        'COLD_DRINKS',
        'PRODUCT_JAM',
        'MEDIUM',
        'ASSIGNED',
        $technicianId,
        $now
    );
    $incidentOther = $createIncident(
        'INC-20261026-T2304',
        $machineOther,
        $locationId,
        'COLD_DRINKS',
        'PRODUCT_JAM',
        'MEDIUM',
        'ASSIGNED',
        $technicianId,
        $now
    );
    $incidentLevelTwo = $createIncident(
        'INC-20261026-T2305',
        $machineLevelTwo,
        $locationId,
        'COLD_DRINKS',
        'PRODUCT_JAM',
        'LOW',
        'REGISTERED'
    );

    $createPendingRequest($incidentMain, $partHeater, 1, $technicianId);
    $createPendingRequest($incidentReady, $partFan, 1, $technicianId);
    $createPendingRequest($incidentShared, $partHeater, 1, $technicianId);
    $createPendingRequest($incidentOther, $partHeater, 2, $technicianId);

    $absence = $pdo->prepare(
        "INSERT INTO technician_absences (technician_id, reason, starts_at, ends_at, created_by)
         VALUES (:technician_id, 'VACATION', CURRENT_TIMESTAMP - INTERVAL '1 hour',
                 CURRENT_TIMESTAMP + INTERVAL '1 day', :created_by)"
    );
    $absence->execute([
        ':technician_id' => $absentTechnicianId,
        ':created_by' => $coordinatorId,
    ]);

    // ─────────────────────────────────────────────────────────────────────
    // Router real y tokens
    // ─────────────────────────────────────────────────────────────────────

    $userRepo = new PdoUserRepository($pdo);
    $locationRepo = new PdoLocationRepository($pdo);
    $authService = new AuthService($locationRepo, $userRepo);

    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $technicianToken = $authService->generateInternalToken($userRepo->findById($technicianId));
    $otherTechnicianToken = $authService->generateInternalToken($userRepo->findById($otherTechnicianId));

    $site = $locationRepo->findBySiteCode('SEDE-T23');
    $siteToken = $site !== null ? $authService->generateSiteToken($site) : '';

    $router = AppRouter::create();

    /** Petición al router real con cabecera y cuerpo indicados. */
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

    /** Escanea el payload en busca de claves monetarias. @return list<string> */
    $costLeaks = static function (array $payload) use ($collectKeys): array {
        return array_values(array_filter(
            $collectKeys($payload),
            static fn (string $key): bool => preg_match('/cost|price|importe|reference_cost/i', $key) === 1
        ));
    };

    /** Mapa técnico => puntuación y desglose del ranking. */
    $scoreMap = static function (array $responsePayload): array {
        $ranking = $responsePayload['data']['ranked_technicians'] ?? [];
        $map = [];
        foreach ($ranking as $row) {
            $map[(int)($row['technician_id'] ?? 0)] = [
                'total_score' => (int)($row['total_score'] ?? 0),
                'score_breakdown' => $row['score_breakdown'] ?? [],
            ];
        }

        return $map;
    };

    /** Fila del candidato en el ranking. */
    $rankingRow = static function (array $responsePayload, int $candidateId): ?array {
        foreach ($responsePayload['data']['ranked_technicians'] ?? [] as $row) {
            if ((int)($row['technician_id'] ?? 0) === $candidateId) {
                return $row;
            }
        }

        return null;
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. RBAC inicial
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. RBAC de las superficies (Art. V.4) ---\n";

    $anonymousProposals = $call('GET', '/api/coordinator/van-restock-orders/proposals');
    $assert(
        '1.1 Sin token, la propuesta de Coordinación responde 401 UNAUTHORIZED',
        $anonymousProposals->getStatusCode() === 401 && $errorCode($anonymousProposals) === 'UNAUTHORIZED'
    );

    $technicianProposals = $call('GET', '/api/coordinator/van-restock-orders/proposals', [], $technicianToken);
    $assert(
        '1.2 Un técnico no entra en la superficie de Coordinación (403)',
        $technicianProposals->getStatusCode() === 403
    );

    // El token de sede pertenece a otro realm de autenticación: la puerta interna
    // no lo interpreta como un usuario con rol insuficiente, lo rechaza antes de
    // evaluar roles (401). El bloqueo es total; el 403 por rol se certifica con un
    // token interno de técnico (1.2) y en la confirmación ajena (8.4).
    $siteProposals = $call('GET', '/api/coordinator/van-restock-orders/proposals', [], $siteToken);
    $assert(
        '1.3 Una sesión de sede no entra en la superficie de Coordinación (rechazo de la puerta interna)',
        $siteToken !== ''
            && $siteProposals->getStatusCode() === 401
            && $errorCode($siteProposals) === 'UNAUTHORIZED'
    );

    $coordinatorInventory = $call('GET', '/api/technician/van-stock', [], $coordinatorToken);
    $assert(
        '1.4 Un coordinador no entra en el inventario del técnico (403)',
        $coordinatorInventory->getStatusCode() === 403
    );

    $anonymousInventory = $call('GET', '/api/technician/van-stock');
    $assert(
        '1.5 Sin token, el inventario del técnico responde 401',
        $anonymousInventory->getStatusCode() === 401
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Cruce de stock en los dos niveles antes de mover saldo (D2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Cruce informativo en dos niveles (D2, Art. II) ---\n";

    $suggestLevelOneBaseline = $call(
        'GET',
        '/api/coordinator/incidents/' . $incidentMain . '/suggested-technicians',
        [],
        $coordinatorToken
    );
    $suggestLevelTwoBaseline = $call(
        'GET',
        '/api/coordinator/incidents/' . $incidentLevelTwo . '/suggested-technicians',
        [],
        $coordinatorToken
    );

    $levelOneBaselinePayload = $json($suggestLevelOneBaseline);
    $levelTwoBaselinePayload = $json($suggestLevelTwoBaseline);
    $levelOneBaselineScores = $scoreMap($levelOneBaselinePayload);
    $levelTwoBaselineScores = $scoreMap($levelTwoBaselinePayload);

    $levelOneRow = $rankingRow($levelOneBaselinePayload, $technicianId) ?? [];
    $levelOneMatch = $levelOneRow['van_stock_match'] ?? [];
    $assert(
        '2.1 El primer nivel evalúa las solicitudes explícitas y no encuentra la pieza',
        $suggestLevelOneBaseline->getStatusCode() === 200
            && ($levelOneMatch['evaluation_level'] ?? '') === 'EXPLICIT_REQUESTS'
            && ($levelOneMatch['status'] ?? '') === 'NO_STOCK'
            && ($levelOneMatch['matched_references'] ?? -1) === 0
            && ($levelOneMatch['total_references'] ?? -1) === 1,
        json_encode($levelOneMatch)
    );

    $levelTwoRow = $rankingRow($levelTwoBaselinePayload, $technicianId) ?? [];
    $levelTwoMatch = $levelTwoRow['van_stock_match'] ?? [];
    $assert(
        '2.2 El segundo nivel evalúa la compatibilidad por modelo y encuentra el ventilador',
        $suggestLevelTwoBaseline->getStatusCode() === 200
            && ($levelTwoMatch['evaluation_level'] ?? '') === 'CATEGORY_COMPATIBILITY'
            && ($levelTwoMatch['status'] ?? '') === 'FULL_MATCH'
            && ($levelTwoMatch['matched_references'] ?? -1) === 1
            && ($levelTwoMatch['total_references'] ?? -1) === 1,
        json_encode($levelTwoMatch)
    );

    $assert(
        '2.3 El ranking de ambos niveles puntúa a todos los técnicos activos',
        count($levelOneBaselineScores) === 3 && count($levelTwoBaselineScores) === 3,
        'nivel1=' . count($levelOneBaselineScores) . ' · nivel2=' . count($levelTwoBaselineScores)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Propuesta con saldo negativo y solicitud PENDING
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Propuesta pre-calculada (RF-02, QA 5) ---\n";

    $proposalResponse = $call('GET', '/api/coordinator/van-restock-orders/proposals', [], $coordinatorToken, [
        'technician_id' => (string)$technicianId,
    ]);
    $proposalData = $data($proposalResponse);
    $proposal = $proposalData['proposals'][0] ?? [];
    $proposalItems = $proposal['items'] ?? [];
    $heaterLine = $proposalItems[0] ?? [];
    $filterLine = $proposalItems[1] ?? [];

    $assert(
        '3.1 La propuesta del técnico responde 200 y publica su bandeja',
        $proposalResponse->getStatusCode() === 200
            && ($proposal['technician_id'] ?? null) === $technicianId
            && ($proposal['recipient_currently_absent'] ?? true) === false
    );

    $assert(
        '3.2 La línea con saldo negativo y solicitud PENDING encabeza con motivo BOTH',
        ($heaterLine['spare_part_id'] ?? null) === $partHeater
            && ($heaterLine['current_quantity'] ?? null) === -2
            && ($heaterLine['min_threshold'] ?? null) === 2
            && ($heaterLine['pending_request_qty'] ?? null) === 4
            && ($heaterLine['reason'] ?? '') === 'BOTH'
            && ($heaterLine['suggested_qty'] ?? null) === 9,
        json_encode($heaterLine)
    );

    $assert(
        '3.3 La segunda línea refleja el bajo umbral sin demanda',
        ($filterLine['spare_part_id'] ?? null) === $partFilter
            && ($filterLine['reason'] ?? '') === 'LOW_STOCK'
            && ($filterLine['suggested_qty'] ?? null) === 3,
        json_encode($filterLine)
    );

    $absentProposalResponse = $call('GET', '/api/coordinator/van-restock-orders/proposals', [], $coordinatorToken, [
        'technician_id' => (string)$absentTechnicianId,
    ]);
    $absentProposal = $data($absentProposalResponse)['proposals'][0] ?? [];
    $assert(
        '3.4 La propuesta del técnico ausente declara la alerta y conserva su déficit',
        ($absentProposal['recipient_currently_absent'] ?? false) === true
            && count($absentProposal['items'] ?? []) === 1,
        json_encode($absentProposal['items'] ?? [])
    );

    $assert(
        '3.5 Ninguna propuesta transporta coste o precio',
        $costLeaks($proposalData) === [] && $costLeaks($data($absentProposalResponse)) === []
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Generación por lotes con exclusión de ausencia activa (QA 4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Generación por lotes (RF-02.2, QA 4) ---\n";

    $batchResponse = $call('POST', '/api/coordinator/van-restock-orders/generate-batch', [], $coordinatorToken);
    $batchData = $data($batchResponse);
    $skipByTechnician = [];
    foreach ($batchData['skipped_technicians'] ?? [] as $skip) {
        $skipByTechnician[(int)($skip['technician_id'] ?? 0)] = $skip;
    }

    $assert(
        '4.1 El lote crea una sola orden: la del técnico con déficit y sin ausencia',
        $batchResponse->getStatusCode() === 200
            && ($batchData['created_count'] ?? null) === 1
            && ($batchData['created_orders'][0]['technician_id'] ?? null) === $technicianId,
        json_encode($batchData['created_orders'] ?? [])
    );

    $assert(
        '4.2 El técnico con ausencia vigente se omite con SKIPPED_ACTIVE_ABSENCE',
        ($skipByTechnician[$absentTechnicianId]['skip_reason'] ?? '') === 'SKIPPED_ACTIVE_ABSENCE',
        json_encode($skipByTechnician[$absentTechnicianId] ?? null)
    );

    $assert(
        '4.3 El técnico sin déficit se omite con SKIPPED_NO_DEFICIT',
        ($skipByTechnician[$otherTechnicianId]['skip_reason'] ?? '') === 'SKIPPED_NO_DEFICIT',
        json_encode($skipByTechnician[$otherTechnicianId] ?? null)
    );

    $orderId = (int)($batchData['created_orders'][0]['id'] ?? 0);
    $assert(
        '4.4 La orden creada tiene código RO- y queda en PENDING_PICKING',
        $orderId > 0
            && str_starts_with((string)($batchData['created_orders'][0]['order_code'] ?? ''), 'RO-')
            && ($batchData['created_orders'][0]['active_items_count'] ?? null) === 2
            && ($batchData['created_orders'][0]['total_requested_units'] ?? null) === 12
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Segunda orden abierta y detalle canónico (D3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Conflicto de orden abierta y detalle (D3) ---\n";

    $secondOrder = $call('POST', '/api/coordinator/van-restock-orders', [
        'technician_id' => $technicianId,
        'items' => [
            ['spare_part_id' => $partHeater, 'requested_qty' => 1, 'reason' => 'MANUAL'],
        ],
    ], $coordinatorToken);
    $assert(
        '5.1 Una segunda orden abierta responde 409 OPEN_RESTOCK_ORDER_ALREADY_EXISTS',
        $secondOrder->getStatusCode() === 409
            && $errorCode($secondOrder) === 'OPEN_RESTOCK_ORDER_ALREADY_EXISTS'
    );

    $detailResponse = $call('GET', '/api/coordinator/van-restock-orders/' . $orderId, [], $coordinatorToken);
    $detail = $data($detailResponse);
    $assert(
        '5.2 El detalle publica la cabecera y las dos líneas vigentes',
        $detailResponse->getStatusCode() === 200
            && ($detail['status'] ?? '') === 'PENDING_PICKING'
            && ($detail['active_items_count'] ?? null) === 2
            && count($detail['items'] ?? []) === 2
            && ($detail['total_requested_units'] ?? null) === 12,
        json_encode($detail)
    );

    $assert(
        '5.3 El detalle no publica coste ni precio',
        $costLeaks($detail) === []
    );

    $itemCountBeforeEdit = (int)$pdo->query(
        'SELECT COUNT(*) FROM van_restock_order_items WHERE order_id = ' . $orderId
    )->fetchColumn();

    // ─────────────────────────────────────────────────────────────────────
    // 6. Edición con baja lógica (QA 1, Art. III.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Edición y baja lógica de línea (QA 1, Art. III.1) ---\n";

    $updateResponse = $call('PUT', '/api/coordinator/van-restock-orders/' . $orderId, [
        'notes' => 'Se retira el filtro por exceso de existencias en almacén.',
        'items' => [
            ['spare_part_id' => $partHeater, 'requested_qty' => 6, 'reason' => 'BOTH'],
        ],
    ], $coordinatorToken);
    $updatedOrder = $data($updateResponse)['order'] ?? [];
    $assert(
        '6.1 La edición deja una sola línea vigente y conserva la cabecera',
        $updateResponse->getStatusCode() === 200
            && ($updatedOrder['status'] ?? '') === 'PENDING_PICKING'
            && ($updatedOrder['active_items_count'] ?? null) === 1
            && count($updatedOrder['items'] ?? []) === 1,
        json_encode($updatedOrder)
    );

    $removedRowStmt = $pdo->prepare(
        'SELECT removed_at, removed_by, dispatched_qty
           FROM van_restock_order_items
          WHERE order_id = :order_id AND spare_part_id = :spare_part_id'
    );
    $removedRowStmt->execute([':order_id' => $orderId, ':spare_part_id' => $partFilter]);
    $removedRow = $removedRowStmt->fetch(PDO::FETCH_ASSOC);

    $assert(
        '6.2 La línea retirada sigue existiendo con removed_at y removed_by',
        is_array($removedRow)
            && $removedRow['removed_at'] !== null
            && (int)$removedRow['removed_by'] === $coordinatorId
            && $removedRow['dispatched_qty'] === null,
        json_encode($removedRow)
    );

    $itemCountAfterEdit = (int)$pdo->query(
        'SELECT COUNT(*) FROM van_restock_order_items WHERE order_id = ' . $orderId
    )->fetchColumn();
    $assert(
        '6.3 No hay borrado físico: el número de filas no disminuye',
        $itemCountAfterEdit === $itemCountBeforeEdit && $itemCountAfterEdit === 2,
        'antes=' . $itemCountBeforeEdit . ' · después=' . $itemCountAfterEdit
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Expedición parcial (D1, QA 1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 7. Expedición parcial desde almacén (D1, QA 1) ---\n";

    $dispatchResponse = $call('POST', '/api/coordinator/van-restock-orders/' . $orderId . '/dispatch', [
        'items' => [
            ['spare_part_id' => $partHeater, 'dispatched_qty' => 3],
        ],
    ], $coordinatorToken);
    $dispatchedOrder = $data($dispatchResponse)['order'] ?? [];
    $dispatchedLine = $dispatchedOrder['items'][0] ?? [];
    $assert(
        '7.1 La expedición parcial pasa la orden a DISPATCHED con tres unidades',
        $dispatchResponse->getStatusCode() === 200
            && ($dispatchedOrder['status'] ?? '') === 'DISPATCHED'
            && ($dispatchedOrder['total_dispatched_units'] ?? null) === 3
            && ($dispatchedOrder['dispatched_at'] ?? null) !== null
            && ($dispatchedLine['dispatched_qty'] ?? null) === 3,
        json_encode($dispatchedOrder)
    );

    $assert(
        '7.2 La línea retirada sigue fuera de la expedición y sin cantidad servida',
        is_array($removedRow)
            && $removedRow['removed_at'] !== null
            && $removedRow['dispatched_qty'] === null
    );

    // ─────────────────────────────────────────────────────────────────────
    // 8. Recepción móvil e inventario (QA 5, RNF-02)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 8. Recepción en el inventario móvil (QA 5) ---\n";

    $inventoryBefore = $call('GET', '/api/technician/van-stock', [], $technicianToken);
    $inventoryBeforeData = $data($inventoryBefore);
    $activeOrder = $inventoryBeforeData['active_dispatched_order'] ?? null;

    $assert(
        '8.1 El inventario del titular publica active_dispatched_order',
        $inventoryBefore->getStatusCode() === 200
            && ($activeOrder['id'] ?? null) === $orderId
            && ($activeOrder['status'] ?? '') === 'DISPATCHED'
            && count($activeOrder['items'] ?? []) === 1
            && ($activeOrder['total_dispatched_units'] ?? null) === 3,
        json_encode($activeOrder)
    );

    $stockBeforeConfirm = null;
    foreach ($inventoryBeforeData['items'] ?? [] as $stockItem) {
        if (($stockItem['spare_part_id'] ?? null) === $partHeater) {
            $stockBeforeConfirm = $stockItem;
        }
    }

    $assert(
        '8.2 La expedición todavía no ha tocado el saldo de la furgoneta',
        ($stockBeforeConfirm['quantity_available'] ?? null) === -2,
        json_encode($stockBeforeConfirm)
    );

    $otherInventory = $call('GET', '/api/technician/van-stock', [], $otherTechnicianToken);
    $assert(
        '8.3 Otro técnico no ve la orden despachada ajena',
        ($data($otherInventory)['active_dispatched_order'] ?? null) === null
    );

    $incidentStatusBefore = $pdo->query(
        'SELECT id, status FROM incidents WHERE id IN (' . implode(',', [
            $incidentMain, $incidentReady, $incidentShared, $incidentOther,
        ]) . ') ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $requestStatusBefore = $pdo->query(
        'SELECT id, status FROM spare_part_requests WHERE incident_id IN (' . implode(',', [
            $incidentMain, $incidentReady, $incidentShared, $incidentOther,
        ]) . ') ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $wrongTechnicianConfirm = $call(
        'POST',
        '/api/technician/van-stock/restock-orders/' . $orderId . '/confirm',
        [],
        $otherTechnicianToken
    );
    $assert(
        '8.4 Otro técnico recibe 403 RESTOCK_ORDER_OWNERSHIP_MISMATCH',
        $wrongTechnicianConfirm->getStatusCode() === 403
            && $errorCode($wrongTechnicianConfirm) === 'RESTOCK_ORDER_OWNERSHIP_MISMATCH'
    );

    $stockAfterWrongConfirm = $pdo->prepare(
        'SELECT quantity_available FROM technician_van_stocks
          WHERE technician_id = :technician_id AND spare_part_id = :spare_part_id'
    );
    $stockAfterWrongConfirm->execute([
        ':technician_id' => $technicianId,
        ':spare_part_id' => $partHeater,
    ]);
    $assert(
        '8.5 El intento ajeno no mueve el saldo del titular',
        (int)$stockAfterWrongConfirm->fetchColumn() === -2
    );

    $confirmResponse = $call(
        'POST',
        '/api/technician/van-stock/restock-orders/' . $orderId . '/confirm',
        [],
        $technicianToken
    );
    $confirmData = $data($confirmResponse);
    $updatedStock = null;
    foreach ($confirmData['updated_van_stock'] ?? [] as $stockRow) {
        if (($stockRow['spare_part_id'] ?? null) === $partHeater) {
            $updatedStock = $stockRow;
        }
    }

    $assert(
        '8.6 La confirmación en un toque cierra la orden y suma las tres unidades',
        $confirmResponse->getStatusCode() === 200
            && ($confirmData['order']['status'] ?? '') === 'CONFIRMED_BY_TECH'
            && ($confirmData['order']['total_received_units'] ?? null) === 3
            && ($updatedStock['previous_quantity_available'] ?? null) === -2
            && ($updatedStock['dispatched_qty'] ?? null) === 3
            && ($updatedStock['new_quantity_available'] ?? null) === 1,
        json_encode($confirmData)
    );

    $stockStmt = $pdo->prepare(
        'SELECT quantity_available FROM technician_van_stocks
          WHERE technician_id = :technician_id AND spare_part_id = :spare_part_id'
    );
    $stockStmt->execute([':technician_id' => $technicianId, ':spare_part_id' => $partHeater]);
    $assert(
        '8.7 El saldo de la furgoneta queda en una unidad',
        (int)$stockStmt->fetchColumn() === 1
    );

    $inventoryAfter = $call('GET', '/api/technician/van-stock', [], $technicianToken);
    $inventoryAfterData = $data($inventoryAfter);
    $confirmationReceipt = $inventoryAfterData['last_confirmed_order'] ?? null;
    $stockAfterConfirm = null;
    foreach ($inventoryAfterData['items'] ?? [] as $stockItem) {
        if (($stockItem['spare_part_id'] ?? null) === $partHeater) {
            $stockAfterConfirm = $stockItem;
        }
    }

    $assert(
        '8.8 El inventario publica el comprobante de la última recepción y ya no la orden activa',
        ($inventoryAfterData['active_dispatched_order'] ?? null) === null
            && ($confirmationReceipt['id'] ?? null) === $orderId
            && ($confirmationReceipt['total_received_units'] ?? null) === 3
            && count($confirmationReceipt['items'] ?? []) === 1
            && ($stockAfterConfirm['quantity_available'] ?? null) === 1,
        json_encode($confirmationReceipt)
    );

    $doubleConfirm = $call(
        'POST',
        '/api/technician/van-stock/restock-orders/' . $orderId . '/confirm',
        [],
        $technicianToken
    );
    $assert(
        '8.9 Confirmar dos veces la misma orden responde 409 sin volver a sumar',
        $doubleConfirm->getStatusCode() === 409
            && $errorCode($doubleConfirm) === 'INVALID_RESTOCK_ORDER_TRANSITION'
    );

    $stockStmt->execute([':technician_id' => $technicianId, ':spare_part_id' => $partHeater]);
    $assert(
        '8.10 El doble toque no altera el saldo ya confirmado',
        (int)$stockStmt->fetchColumn() === 1
    );

    $incidentStatusAfter = $pdo->query(
        'SELECT id, status FROM incidents WHERE id IN (' . implode(',', [
            $incidentMain, $incidentReady, $incidentShared, $incidentOther,
        ]) . ') ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $requestStatusAfter = $pdo->query(
        'SELECT id, status FROM spare_part_requests WHERE incident_id IN (' . implode(',', [
            $incidentMain, $incidentReady, $incidentShared, $incidentOther,
        ]) . ') ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $assert(
        '8.11 La confirmación no altera estados de averías ni de solicitudes (C5, QA 3)',
        $incidentStatusBefore === $incidentStatusAfter
            && $requestStatusBefore === $requestStatusAfter,
        'incidencias=' . json_encode($incidentStatusAfter) . ' · solicitudes=' . json_encode($requestStatusAfter)
    );

    $auditStmt = $pdo->prepare(
        "SELECT action, user_id, user_role
           FROM audit_log
          WHERE entity_type = 'VAN_RESTOCK_ORDER' AND entity_id = :order_id
          ORDER BY id ASC"
    );
    $auditStmt->execute([':order_id' => $orderId]);
    $auditRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
    $auditActions = array_column($auditRows, 'action');

    $assert(
        '8.12 audit_log conserva creación, edición, expedición y confirmación',
        in_array('CREATE_VAN_RESTOCK_ORDER', $auditActions, true)
            && in_array('UPDATE_VAN_RESTOCK_ORDER', $auditActions, true)
            && in_array('DISPATCH_VAN_RESTOCK_ORDER', $auditActions, true)
            && in_array('CONFIRM_VAN_RESTOCK_ORDER', $auditActions, true),
        implode(',', $auditActions)
    );

    $confirmAudit = null;
    foreach ($auditRows as $row) {
        if ($row['action'] === 'CONFIRM_VAN_RESTOCK_ORDER') {
            $confirmAudit = $row;
        }
    }
    $assert(
        '8.13 El evento de confirmación identifica al técnico titular',
        is_array($confirmAudit)
            && (int)$confirmAudit['user_id'] === $technicianId
            && $confirmAudit['user_role'] === 'TECHNICIAN'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 9. Insignias de cobertura en las dos bandejas (RF-05.4, QA 3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 9. Insignias van_stock_readiness en Coordinación y Mi Ruta ---\n";

    $coordinatorIncidents = $call('GET', '/api/coordinator/incidents', [], $coordinatorToken, [
        'assigned_technician_id' => (string)$technicianId,
        'active_only' => '1',
    ]);
    $coordinatorRows = [];
    foreach ($data($coordinatorIncidents) as $row) {
        $coordinatorRows[(int)($row['id'] ?? 0)] = $row;
    }

    $readyBadge = $coordinatorRows[$incidentReady]['van_stock_readiness'] ?? [];
    $sharedBadge = $coordinatorRows[$incidentShared]['van_stock_readiness'] ?? [];
    $otherBadge = $coordinatorRows[$incidentOther]['van_stock_readiness'] ?? [];

    $assert(
        '9.1 Coordinación publica READY_IN_VAN cuando el saldo cubre toda la demanda',
        ($readyBadge['status'] ?? '') === 'READY_IN_VAN'
            && ($readyBadge['badge_label'] ?? '') === '📦 Repuesto ya en furgoneta'
            && ($readyBadge['covered_references'] ?? null) === 1
            && ($readyBadge['total_catalog_references'] ?? null) === 1,
        json_encode($readyBadge)
    );

    $assert(
        '9.2 Coordinación publica SHARED_IN_VAN cuando cubre la avería pero no la demanda agregada',
        ($sharedBadge['status'] ?? '') === 'SHARED_IN_VAN'
            && ($sharedBadge['badge_label'] ?? '') === '📦 Repuesto en furgoneta (stock compartido)',
        json_encode($sharedBadge)
    );

    $assert(
        '9.3 La avería que necesita más unidades que el saldo queda NOT_READY',
        ($otherBadge['status'] ?? '') === 'NOT_READY'
    );

    $myRoute = $call('GET', '/api/technician/my-route', [], $technicianToken);
    $routeRows = [];
    foreach ($data($myRoute) as $stop) {
        $routeRows[(int)($stop['id'] ?? 0)] = $stop;
    }

    $assert(
        '9.4 Mi Ruta replica READY_IN_VAN y SHARED_IN_VAN para el técnico titular',
        ($routeRows[$incidentReady]['van_stock_readiness']['status'] ?? '') === 'READY_IN_VAN'
            && ($routeRows[$incidentShared]['van_stock_readiness']['status'] ?? '') === 'SHARED_IN_VAN'
            && ($routeRows[$incidentOther]['van_stock_readiness']['status'] ?? '') === 'NOT_READY',
        json_encode([
            'ready' => $routeRows[$incidentReady]['van_stock_readiness'] ?? null,
            'shared' => $routeRows[$incidentShared]['van_stock_readiness'] ?? null,
        ])
    );

    // ─────────────────────────────────────────────────────────────────────
    // 10. D2: el cruce cambia de lectura, jamás la puntuación
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 10. Invariancia del ranking tras mover stock (D2, Art. II) ---\n";

    $suggestLevelOneAfter = $call(
        'GET',
        '/api/coordinator/incidents/' . $incidentMain . '/suggested-technicians',
        [],
        $coordinatorToken
    );
    $suggestLevelTwoAfter = $call(
        'GET',
        '/api/coordinator/incidents/' . $incidentLevelTwo . '/suggested-technicians',
        [],
        $coordinatorToken
    );

    $levelOneAfterPayload = $json($suggestLevelOneAfter);
    $levelTwoAfterPayload = $json($suggestLevelTwoAfter);
    $levelOneAfterScores = $scoreMap($levelOneAfterPayload);
    $levelTwoAfterScores = $scoreMap($levelTwoAfterPayload);

    $assert(
        '10.1 total_score y score_breakdown no cambian tras confirmar la carga (nivel explícito)',
        $levelOneAfterScores === $levelOneBaselineScores,
        'antes=' . json_encode($levelOneBaselineScores) . ' · después=' . json_encode($levelOneAfterScores)
    );

    $assert(
        '10.2 total_score y score_breakdown no cambian tras confirmar la carga (nivel de compatibilidad)',
        $levelTwoAfterScores === $levelTwoBaselineScores,
        'antes=' . json_encode($levelTwoBaselineScores) . ' · después=' . json_encode($levelTwoAfterScores)
    );

    $levelOneAfterRow = $rankingRow($levelOneAfterPayload, $technicianId) ?? [];
    $levelOneAfterMatch = $levelOneAfterRow['van_stock_match'] ?? [];
    $assert(
        '10.3 El primer nivel refleja el nuevo saldo sin ascender al técnico por ello',
        ($levelOneAfterMatch['evaluation_level'] ?? '') === 'EXPLICIT_REQUESTS'
            && ($levelOneAfterMatch['status'] ?? '') === 'FULL_MATCH'
            && ($levelOneAfterMatch['matched_references'] ?? null) === 1
            && ($levelOneAfterRow['total_score'] ?? null) === ($levelOneRow['total_score'] ?? null),
        json_encode($levelOneAfterMatch)
    );

    $levelTwoAfterRow = $rankingRow($levelTwoAfterPayload, $technicianId) ?? [];
    $assert(
        '10.4 El segundo nivel se mantiene informativo y estable',
        ($levelTwoAfterRow['van_stock_match']['evaluation_level'] ?? '') === 'CATEGORY_COMPATIBILITY'
            && ($levelTwoAfterRow['van_stock_match']['status'] ?? '') === 'FULL_MATCH'
            && ($levelTwoAfterRow['total_score'] ?? null) === ($levelTwoRow['total_score'] ?? null)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 11. Cancelación justificada (Art. V.1, RF-04.2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 11. Cancelación con justificación (Art. V.1) ---\n";

    $newOrderResponse = $call('POST', '/api/coordinator/van-restock-orders', [
        'technician_id' => $technicianId,
        'notes' => 'Reposición manual de una unidad para la certificación.',
        'items' => [
            ['spare_part_id' => $partFilter, 'requested_qty' => 1, 'reason' => 'MANUAL'],
        ],
    ], $coordinatorToken);
    $newOrder = $data($newOrderResponse);
    $newOrderId = (int)($newOrder['order']['id'] ?? 0);

    $assert(
        '11.1 La creación individual vuelve a admitirse cuando no hay orden abierta',
        $newOrderResponse->getStatusCode() === 201
            && ($newOrder['order']['status'] ?? '') === 'PENDING_PICKING'
            && (int)($newOrder['order']['technician_id'] ?? 0) === $technicianId
    );

    $shortReason = $call('POST', '/api/coordinator/van-restock-orders/' . $newOrderId . '/cancel', [
        'cancellation_reason' => str_repeat('x', 19),
    ], $coordinatorToken);
    $assert(
        '11.2 Una justificación de 19 caracteres reales responde 422',
        $shortReason->getStatusCode() === 422
            && $errorCode($shortReason) === 'INVALID_RESTOCK_ORDER_PAYLOAD'
    );

    $validReason = 'Se anula la carga manual porque almacén no dispone de la referencia solicitada.';
    $cancelResponse = $call('POST', '/api/coordinator/van-restock-orders/' . $newOrderId . '/cancel', [
        'cancellation_reason' => $validReason,
    ], $coordinatorToken);
    $cancelledOrder = $data($cancelResponse);

    $assert(
        '11.3 Una justificación válida cancela la orden y conserva el motivo',
        $cancelResponse->getStatusCode() === 200
            && ($cancelledOrder['order']['status'] ?? '') === 'CANCELLED'
            && ($cancelledOrder['order']['cancellation_reason'] ?? '') === $validReason
            && ($cancelledOrder['order']['cancelled_at'] ?? null) !== null,
        json_encode($cancelledOrder['order'] ?? [])
    );

    $stockStmt->execute([':technician_id' => $technicianId, ':spare_part_id' => $partFilter]);
    $assert(
        '11.4 Cancelar no toca el saldo de la furgoneta',
        (int)$stockStmt->fetchColumn() === 1
    );

    $openOrders = $call('GET', '/api/coordinator/van-restock-orders', [], $coordinatorToken, ['status' => 'OPEN']);
    $openOrderIds = array_map(
        static fn (array $order): int => (int)($order['id'] ?? 0),
        $data($openOrders)['items'] ?? []
    );
    $cancelledOrders = $call('GET', '/api/coordinator/van-restock-orders', [], $coordinatorToken, [
        'status' => 'CANCELLED',
    ]);
    $cancelledOrderIds = array_map(
        static fn (array $order): int => (int)($order['id'] ?? 0),
        $data($cancelledOrders)['items'] ?? []
    );

    $assert(
        '11.5 El listado ordena abiertas y cerradas, y el filtro excluye la cancelada de las abiertas',
        !in_array($newOrderId, $openOrderIds, true)
            && in_array($newOrderId, $cancelledOrderIds, true)
    );

    $cancelAudit = $pdo->prepare(
        "SELECT action, user_id FROM audit_log
          WHERE entity_type = 'VAN_RESTOCK_ORDER' AND entity_id = :order_id AND action = 'CANCEL_VAN_RESTOCK_ORDER'"
    );
    $cancelAudit->execute([':order_id' => $newOrderId]);
    $cancelAuditRow = $cancelAudit->fetch(PDO::FETCH_ASSOC);
    $assert(
        '11.6 La cancelación deja su evento de auditoría con el coordinador actor',
        is_array($cancelAuditRow) && (int)$cancelAuditRow['user_id'] === $coordinatorId
    );

    // ─────────────────────────────────────────────────────────────────────
    // 12. Privacidad del portal de sede y de las respuestas del módulo
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 12. Saneado del portal de sede y cero coste (Art. V.4, D4) ---\n";

    $siteConfirm = $call(
        'POST',
        '/api/technician/van-stock/restock-orders/' . $orderId . '/confirm',
        [],
        $siteToken
    );
    $assert(
        '12.1 La sesión de sede no puede confirmar la carga de un técnico (rechazo de la puerta interna)',
        $siteConfirm->getStatusCode() === 401
            && $errorCode($siteConfirm) === 'UNAUTHORIZED'
    );

    $siteMachines = $call('GET', '/api/locations/SEDE-T23/machines', [], $siteToken);
    $sitePayload = $data($siteMachines);
    $siteKeys = $collectKeys($sitePayload);
    $forbiddenSiteKeys = [
        'van_stock_match',
        'van_stock_readiness',
        'active_dispatched_order',
        'last_confirmed_order',
        'restock_order',
        'restock_orders',
        'reference_cost',
        'unit_cost_snapshot',
        'total_cost_snapshot',
    ];
    $leakedSiteKeys = array_values(array_intersect($forbiddenSiteKeys, $siteKeys));

    $siteTickets = [];
    foreach ($sitePayload as $machine) {
        $ticket = $machine['active_incident']['ticket_code'] ?? null;
        if ($ticket !== null) {
            $siteTickets[] = (string)$ticket;
        }
    }

    $assert(
        '12.2 El saneador de sede retira cruce de furgoneta, orden de carga y coste',
        $siteMachines->getStatusCode() === 200
            && $leakedSiteKeys === []
            && in_array('INC-20261026-T2301', $siteTickets, true),
        'claves filtradas=' . implode(',', $leakedSiteKeys)
    );

    $moduleResponses = [
        'propuestas' => $proposalResponse,
        'lote' => $batchResponse,
        'detalle' => $detailResponse,
        'edición' => $updateResponse,
        'expedición' => $dispatchResponse,
        'inventario' => $inventoryAfter,
        'confirmación' => $confirmResponse,
        'listado canceladas' => $cancelledOrders,
    ];

    $costLeaksFound = [];
    foreach ($moduleResponses as $label => $response) {
        foreach ($costLeaks($json($response)) as $key) {
            $costLeaksFound[] = $label . ':' . $key;
        }
    }

    $assert(
        '12.3 Ninguna respuesta del módulo transporta coste, precio o importe',
        $costLeaksFound === [],
        implode(',', $costLeaksFound)
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, RESTOCK_API_TEST_SCHEMA);
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
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-RESTOCK-11 (API DE ÓRDENES DE CARGA) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: HAY FALLOS. REVISAR EL DETALLE ANTERIOR.\n";
echo "======================================================================\n";
exit(1);
