<?php

declare(strict_types=1);

/**
 * VanStockApiTest · T-VAN-06
 *
 * Certifica la superficie HTTP del stock de repuestos de las furgonetas (Módulo 18) de punta a
 * punta: router real, middleware de rol, controladores, servicio, repositorio PDO y motor
 * PostgreSQL, sobre un esquema aislado.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **RBAC (RF-04.3, Art. V.4):** sin token `401`; un técnico no entra en la superficie de
 *      coordinación y un coordinador no entra en la del técnico (`403`).
 *   2. **Inventario del técnico (contrato §4.1):** solo sus parejas, la proyección exacta de siete
 *      claves, sin identidad de técnico ni coste, y el identificador de otro técnico por query se
 *      ignora en silencio —no se puede leer la furgoneta ajena ni equivocándose de parámetro—.
 *   3. **Alerta de coordinación (§4.2):** `below_threshold=1` devuelve solo las parejas bajo
 *      umbral, con el descuadre negativo primero, la proyección de diez claves y el filtro por
 *      técnico funcionando.
 *   4. **Inventario de coordinación (§4.2):** sin parámetros devuelve el parque completo; el
 *      filtro por técnico y las dos validaciones de query (`422`) responden como el contrato.
 *   5. **Ajuste absoluto y su auditoría (§4.3, RF-05.1):** el alta no tiene cantidad previa, el
 *      ajuste sí; el *upsert* no duplica la pareja; y el evento `VAN_STOCK_ADJUSTED` guarda el
 *      actor, la cantidad previa y el delta. Esto cierra la certificación que WU-2 dejó pendiente
 *      contra el motor real.
 *   6. **Catálogo de errores (§5):** payload incompleto, cantidad fuera de cotas por arriba y por
 *      abajo, el suelo `-999` admitido, técnico y pieza inexistentes y destino con rol ajeno.
 *   7. **Privacidad (RF-06):** ninguna respuesta del módulo transporta importes ni costes.
 *
 * CATEGORÍA: `new_engine`
 *   Exige el PostgreSQL local. El escenario vive en un esquema aislado sobre la conexión de la
 *   aplicación, de modo que el router y los repositorios que éste construye trabajan sobre las
 *   mismas tablas efímeras. Sin motor, sale con código 2 en lugar de omitirse en silencio.
 *
 * LIMPIEZA: el esquema se retira con `CASCADE` al terminar, incluso si falla una aserción.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/VanStockApiTest.php
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

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado sobre la conexión de la aplicación. */
const VAN_STOCK_API_TEST_SCHEMA = 't_van18_api';

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
echo " VENDGUARD · T-VAN-06 · API del stock de furgonetas (Módulo 18)\n";
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

/** Bloque `data` del sobre de éxito. */
$data = static fn (Response $response): array => $json($response)['data'] ?? [];

/** Bloque `error` del sobre de error. */
$error = static fn (Response $response): array => $json($response)['error'] ?? [];

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, VAN_STOCK_API_TEST_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $createUser = static function (string $suffix, string $role) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => ($role === 'COORDINATOR' ? 'Coordinación ' : 'Técnico ') . ucfirst($suffix),
            ':email' => 'van18.' . $suffix . '@vendguard.internal',
            ':email_key' => 'van18.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('van18-' . $suffix, PASSWORD_BCRYPT),
            ':role' => $role,
            ':operator' => 'V18-' . mb_strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createPart = static function (string $code, string $name, string $category, float $cost) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO spare_parts (part_code, name, category, manufacturer, reference_cost, is_active)
             VALUES (:code, :name, :category, :manufacturer, :cost, TRUE)
             RETURNING id'
        );
        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
            ':category' => $category,
            ':manufacturer' => 'Fabricante de certificación',
            ':cost' => $cost,
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

    // ─────────────────────────────────────────────────────────────────────
    // Semilla
    // ─────────────────────────────────────────────────────────────────────

    $coordinatorId = $createUser('coord', 'COORDINATOR');
    $technicianA = $createUser('alfa', 'TECHNICIAN');
    $technicianB = $createUser('beta', 'TECHNICIAN');
    $technicianEmpty = $createUser('gamma', 'TECHNICIAN');

    $partValve = $createPart('VALV-ULKA-01', 'Electroválvula 24V Ulka', 'HYDRAULIC', 28.50);
    $partGasket = $createPart('JUN-TEFL-09', 'Junta de teflón del grupo de infusión', 'CONSUMABLE', 3.20);
    $partThermal = $createPart('SOND-NTC-02', 'Sonda Térmica NTC Frío 10k', 'THERMAL', 15.20);

    // Técnico A: una pareja sana y un descuadre declarado (RD-04).
    $seedStock($technicianA, $partValve, 5, 2);
    $seedStock($technicianA, $partGasket, -1, 0);
    // Técnico B: bajo umbral con saldo positivo, para distinguirlo del descuadre.
    $seedStock($technicianB, $partThermal, 1, 3);

    // ─────────────────────────────────────────────────────────────────────
    // Router real y tokens
    // ─────────────────────────────────────────────────────────────────────

    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService(new PdoLocationRepository($pdo), $userRepo);

    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $technicianToken = $authService->generateInternalToken($userRepo->findById($technicianA));
    $emptyTechnicianToken = $authService->generateInternalToken($userRepo->findById($technicianEmpty));

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
    // 1. RBAC
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. RBAC de las dos superficies (RF-04.3, Art. V.4) ---\n";

    $anonymous = $call('GET', '/api/technician/van-stock');
    $assert(
        '1.1 Sin token responde 401 UNAUTHORIZED',
        $anonymous->getStatusCode() === 401 && ($error($anonymous)['code'] ?? '') === 'UNAUTHORIZED'
    );

    $coordinatorInTechnicianSurface = $call('GET', '/api/technician/van-stock', [], $coordinatorToken);
    $assert(
        '1.2 Un coordinador no entra en el inventario del técnico (403)',
        $coordinatorInTechnicianSurface->getStatusCode() === 403
    );

    $technicianInCoordinatorSurface = $call('GET', '/api/coordinator/van-stocks', [], $technicianToken);
    $assert(
        '1.3 Un técnico no entra en la superficie de coordinación (403)',
        $technicianInCoordinatorSurface->getStatusCode() === 403
    );

    $technicianAdjust = $call('PUT', '/api/coordinator/van-stocks', [
        'technician_id' => $technicianA,
        'spare_part_id' => $partValve,
        'quantity_available' => 9,
        'min_threshold' => 2,
    ], $technicianToken);
    $assert(
        '1.4 Un técnico no puede ajustar stock ni con un cuerpo válido (403)',
        $technicianAdjust->getStatusCode() === 403
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Inventario del técnico (contrato §4.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Inventario del técnico autenticado (§4.1) ---\n";

    $ownInventory = $call('GET', '/api/technician/van-stock', [], $technicianToken);
    $ownData = $data($ownInventory);
    $ownItems = $ownData['items'] ?? [];
    $assert(
        '2.1 Devuelve solo las parejas del técnico autenticado',
        $ownInventory->getStatusCode() === 200
            && ($ownData['technician_id'] ?? null) === $technicianA
            && count($ownItems) === 2,
        'items=' . count($ownItems)
    );

    $expectedTechnicianKeys = [
        'spare_part_id', 'part_code', 'name', 'category',
        'quantity_available', 'min_threshold', 'is_below_threshold',
    ];
    $ownKeys = array_keys($ownItems[0] ?? []);
    sort($ownKeys);
    $expectedTechnicianKeysSorted = $expectedTechnicianKeys;
    sort($expectedTechnicianKeysSorted);
    $assert(
        '2.2 La proyección del técnico tiene exactamente las siete claves del contrato',
        $ownKeys === $expectedTechnicianKeysSorted,
        implode(',', $ownKeys)
    );

    $crossTechnicianRead = $call('GET', '/api/technician/van-stock', [], $technicianToken, ['technician_id' => $technicianB]);
    $crossItems = $data($crossTechnicianRead)['items'] ?? [];
    $assert(
        '2.3 El identificador de otro técnico por query se ignora (RF-04.3)',
        ($data($crossTechnicianRead)['technician_id'] ?? null) === $technicianA
            && count($crossItems) === 2
    );

    $emptyInventory = $call('GET', '/api/technician/van-stock', [], $emptyTechnicianToken);
    $assert(
        '2.4 Un técnico sin stock declarado recibe 200 con la lista vacía',
        $emptyInventory->getStatusCode() === 200 && ($data($emptyInventory)['items'] ?? null) === []
    );

    $negativeEntry = null;
    foreach ($ownItems as $item) {
        if (($item['spare_part_id'] ?? null) === $partGasket) {
            $negativeEntry = $item;
        }
    }
    $assert(
        '2.5 El descuadre negativo viaja con su marca de bajo umbral',
        $negativeEntry !== null
            && $negativeEntry['quantity_available'] === -1
            && $negativeEntry['is_below_threshold'] === true
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Alerta de coordinación (contrato §4.2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Alerta de coordinación (§4.2) ---\n";

    $alert = $call('GET', '/api/coordinator/van-stocks', [], $coordinatorToken, ['below_threshold' => '1']);
    $alertData = $data($alert);
    $alertItems = $alertData['items'] ?? [];
    $assert(
        '3.1 below_threshold=1 devuelve solo las parejas bajo umbral',
        $alert->getStatusCode() === 200
            && ($alertData['below_threshold'] ?? null) === true
            && count($alertItems) === 2,
        'items=' . count($alertItems)
    );

    $assert(
        '3.2 El descuadre negativo encabeza la alerta por severidad (RF-03.3)',
        ($alertItems[0]['quantity_available'] ?? null) === -1
            && ($alertItems[0]['is_negative'] ?? null) === true
            && ($alertItems[1]['is_negative'] ?? null) === false
    );

    $expectedCoordinatorKeys = [
        'technician_id', 'technician_name', 'operator_code', 'spare_part_id', 'part_code',
        'name', 'quantity_available', 'min_threshold', 'is_below_threshold', 'is_negative',
    ];
    $alertKeys = array_keys($alertItems[0] ?? []);
    sort($alertKeys);
    sort($expectedCoordinatorKeys);
    $assert(
        '3.3 La proyección del coordinador tiene exactamente las diez claves del contrato',
        $alertKeys === $expectedCoordinatorKeys,
        implode(',', $alertKeys)
    );

    $alertForB = $call('GET', '/api/coordinator/van-stocks', [], $coordinatorToken, [
        'below_threshold' => '1',
        'technician_id' => (string)$technicianB,
    ]);
    $alertForBItems = $data($alertForB)['items'] ?? [];
    $assert(
        '3.4 La alerta se puede limitar a una furgoneta',
        count($alertForBItems) === 1
            && ($alertForBItems[0]['technician_id'] ?? null) === $technicianB
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Inventario de coordinación (§4.2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Inventario de coordinación (§4.2) ---\n";

    $inventory = $call('GET', '/api/coordinator/van-stocks', [], $coordinatorToken);
    $inventoryData = $data($inventory);
    $assert(
        '4.1 Sin parámetros devuelve el parque completo en modo inventario',
        $inventory->getStatusCode() === 200
            && ($inventoryData['below_threshold'] ?? null) === false
            && count($inventoryData['items'] ?? []) === 3,
        'items=' . count($inventoryData['items'] ?? [])
    );

    $inventoryForA = $call('GET', '/api/coordinator/van-stocks', [], $coordinatorToken, [
        'technician_id' => (string)$technicianA,
    ]);
    $assert(
        '4.2 El inventario se puede limitar a una furgoneta',
        count($data($inventoryForA)['items'] ?? []) === 2
    );

    $badTechnicianQuery = $call('GET', '/api/coordinator/van-stocks', [], $coordinatorToken, [
        'technician_id' => 'alfa',
    ]);
    $assert(
        '4.3 Un technician_id no numérico responde 422 INVALID_VAN_STOCK_PAYLOAD',
        $badTechnicianQuery->getStatusCode() === 422
            && ($error($badTechnicianQuery)['code'] ?? '') === 'INVALID_VAN_STOCK_PAYLOAD'
    );

    $badFlagQuery = $call('GET', '/api/coordinator/van-stocks', [], $coordinatorToken, [
        'below_threshold' => '2',
    ]);
    $assert(
        '4.4 Una bandera below_threshold no reconocible responde 422',
        $badFlagQuery->getStatusCode() === 422
            && ($error($badFlagQuery)['code'] ?? '') === 'INVALID_VAN_STOCK_PAYLOAD'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Ajuste absoluto y auditoría del delta (§4.3, RF-05.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Ajuste absoluto y auditoría del delta (§4.3) ---\n";

    $created = $call('PUT', '/api/coordinator/van-stocks', [
        'technician_id' => $technicianB,
        'spare_part_id' => $partGasket,
        'quantity_available' => 4,
        'min_threshold' => 1,
    ], $coordinatorToken);
    $createdData = $data($created);
    $assert(
        '5.1 El alta responde 200 sin cantidad previa y con la marca de umbral calculada',
        $created->getStatusCode() === 200
            && ($createdData['quantity_available'] ?? null) === 4
            && array_key_exists('previous_quantity_available', $createdData)
            && $createdData['previous_quantity_available'] === null
            && ($createdData['is_below_threshold'] ?? null) === false,
        json_encode($createdData)
    );

    $adjusted = $call('PUT', '/api/coordinator/van-stocks', [
        'technician_id' => $technicianB,
        'spare_part_id' => $partGasket,
        'quantity_available' => 1,
        'min_threshold' => 1,
    ], $coordinatorToken);
    $adjustedData = $data($adjusted);
    $assert(
        '5.2 El ajuste devuelve la cantidad previa y recalcula la marca de umbral',
        $adjusted->getStatusCode() === 200
            && ($adjustedData['previous_quantity_available'] ?? null) === 4
            && ($adjustedData['is_below_threshold'] ?? null) === true
    );

    $pairRows = $pdo->prepare(
        'SELECT COUNT(*) FROM technician_van_stocks WHERE technician_id = :technician_id AND spare_part_id = :spare_part_id'
    );
    $pairRows->execute([':technician_id' => $technicianB, ':spare_part_id' => $partGasket]);
    $assert(
        '5.3 El ajuste es un upsert: una sola fila por pareja',
        (int)$pairRows->fetchColumn() === 1
    );

    $auditRow = $pdo->query(
        "SELECT entity_type, entity_id, action, user_id, user_role, previous_state, new_state, metadata
           FROM audit_log
          WHERE action = 'VAN_STOCK_ADJUSTED'
          ORDER BY id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $auditMetadata = is_array($auditRow) ? json_decode((string)$auditRow['metadata'], true) : null;
    $auditPrevious = is_array($auditRow) ? json_decode((string)$auditRow['previous_state'], true) : null;
    $auditNew = is_array($auditRow) ? json_decode((string)$auditRow['new_state'], true) : null;
    $assert(
        '5.4 El evento de auditoría registra entidad, acción y actor de coordinación',
        is_array($auditRow)
            && $auditRow['entity_type'] === 'TECHNICIAN_VAN_STOCK'
            && (int)$auditRow['user_id'] === $coordinatorId
            && $auditRow['user_role'] === 'COORDINATOR'
            && $auditRow['action'] === 'VAN_STOCK_ADJUSTED'
    );
    $assert(
        '5.5 El evento conserva la cantidad previa y el delta aplicado (RF-05.1)',
        $auditPrevious === ['quantity_available' => 4]
            && $auditMetadata === ['delta' => -3]
            && ($auditNew['technician_id'] ?? null) === $technicianB
            && ($auditNew['spare_part_id'] ?? null) === $partGasket
            && ($auditNew['quantity_available'] ?? null) === 1,
        'previous=' . json_encode($auditPrevious) . ' metadata=' . json_encode($auditMetadata)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Catálogo de errores (§5)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Catálogo de errores (§5) ---\n";

    $incomplete = $call('PUT', '/api/coordinator/van-stocks', [
        'technician_id' => $technicianB,
        'quantity_available' => 2,
    ], $coordinatorToken);
    $incompleteError = $error($incomplete);
    $assert(
        '6.1 Un payload incompleto responde 422 con los campos que faltan',
        $incomplete->getStatusCode() === 422
            && ($incompleteError['code'] ?? '') === 'INVALID_VAN_STOCK_PAYLOAD'
            && isset($incompleteError['details']['spare_part_id'], $incompleteError['details']['min_threshold'])
    );

    $overMax = $call('PUT', '/api/coordinator/van-stocks', [
        'technician_id' => $technicianB,
        'spare_part_id' => $partGasket,
        'quantity_available' => 10000,
        'min_threshold' => 1,
    ], $coordinatorToken);
    $assert(
        '6.2 Una cantidad por encima del techo responde 422 INVALID_VAN_STOCK_QUANTITY',
        $overMax->getStatusCode() === 422
            && ($error($overMax)['code'] ?? '') === 'INVALID_VAN_STOCK_QUANTITY'
    );

    $underFloor = $call('PUT', '/api/coordinator/van-stocks', [
        'technician_id' => $technicianB,
        'spare_part_id' => $partGasket,
        'quantity_available' => -1000,
        'min_threshold' => 1,
    ], $coordinatorToken);
    $assert(
        '6.3 Una cantidad por debajo del suelo -999 responde 422',
        $underFloor->getStatusCode() === 422
            && ($error($underFloor)['code'] ?? '') === 'INVALID_VAN_STOCK_QUANTITY'
    );

    $atFloor = $call('PUT', '/api/coordinator/van-stocks', [
        'technician_id' => $technicianB,
        'spare_part_id' => $partThermal,
        'quantity_available' => -999,
        'min_threshold' => 0,
    ], $coordinatorToken);
    $assert(
        '6.4 El suelo -999 se admite: es un descuadre declarado, no un error (RD-04)',
        $atFloor->getStatusCode() === 200 && ($data($atFloor)['quantity_available'] ?? null) === -999
    );

    $unknownTechnician = $call('PUT', '/api/coordinator/van-stocks', [
        'technician_id' => 999999,
        'spare_part_id' => $partGasket,
        'quantity_available' => 1,
        'min_threshold' => 0,
    ], $coordinatorToken);
    $assert(
        '6.5 Un técnico inexistente responde 404 VAN_STOCK_TARGET_NOT_FOUND',
        $unknownTechnician->getStatusCode() === 404
            && ($error($unknownTechnician)['code'] ?? '') === 'VAN_STOCK_TARGET_NOT_FOUND'
    );

    $unknownPart = $call('PUT', '/api/coordinator/van-stocks', [
        'technician_id' => $technicianA,
        'spare_part_id' => 999999,
        'quantity_available' => 1,
        'min_threshold' => 0,
    ], $coordinatorToken);
    $assert(
        '6.6 Una pieza inexistente responde 404 VAN_STOCK_TARGET_NOT_FOUND',
        $unknownPart->getStatusCode() === 404
            && ($error($unknownPart)['code'] ?? '') === 'VAN_STOCK_TARGET_NOT_FOUND'
    );

    $wrongRole = $call('PUT', '/api/coordinator/van-stocks', [
        'technician_id' => $coordinatorId,
        'spare_part_id' => $partGasket,
        'quantity_available' => 1,
        'min_threshold' => 0,
    ], $coordinatorToken);
    $assert(
        '6.7 Un destino con rol ajeno responde 422 VAN_STOCK_ROLE_NOT_TECHNICIAN',
        $wrongRole->getStatusCode() === 422
            && ($error($wrongRole)['code'] ?? '') === 'VAN_STOCK_ROLE_NOT_TECHNICIAN'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Privacidad (RF-06.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 7. Privacidad de las proyecciones (RF-06.1) ---\n";

    $costKeyPattern = '/cost|price|importe|reference_cost/i';
    $costLeaks = [];
    foreach ([$ownInventory, $alert, $inventory, $adjusted] as $moduleResponse) {
        $payload = $json($moduleResponse);
        foreach (array_keys($payload) as $topKey) {
            if (preg_match($costKeyPattern, (string)$topKey) === 1) {
                $costLeaks[] = (string)$topKey;
            }
        }
        array_walk_recursive($payload, static function ($value, $key) use (&$costLeaks, $costKeyPattern): void {
            if (preg_match($costKeyPattern, (string)$key) === 1) {
                $costLeaks[] = (string)$key;
            }
        });
    }
    $assert(
        '7.1 Ninguna respuesta del módulo transporta importes ni costes',
        $costLeaks === [],
        implode(',', array_unique($costLeaks))
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, VAN_STOCK_API_TEST_SCHEMA);
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
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-VAN-06 (API DE STOCK) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: HAY FALLOS. REVISAR EL DETALLE ANTERIOR.\n";
echo "======================================================================\n";
exit(1);
