<?php

declare(strict_types=1);

/**
 * PlanogramConstitutionalTest · T-PLN-11
 *
 * Certifica el blindaje constitucional del catálogo y del planograma (Módulo 32)
 * contra PostgreSQL real y el router real:
 *
 *   - Art. III.1: `DELETE` físico rechazado en `vending_products` y
 *     `machine_planogram_slots`; la baja lógica conserva la fila.
 *   - Art. II / D3 / QA 4: cerrojo directo del carril, cerrojo inverso del
 *     producto y de la máquina en el motor, y `409` del contrato en la API.
 *   - QA 1: `requires_cold_chain = TRUE` exige vida útil; sin ella, `422`.
 *   - Art. V.4 / QA 2 / D2: el reponedor no recibe coste ni margen, no puede
 *     mutar y pierde acceso en cuanto su zona deja de estar viva.
 *   - QA 3 / C1: orden físico de bandejas, `UPSERT` sin duplicar filas y
 *     clonación que omite productos en baja y falla con origen vacío.
 *   - Art. III.3: las mutaciones dejan rastro de auditoría.
 *
 * CATEGORÍA: `new_engine`. Sin PostgreSQL local sale con código 2.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php tests/integration/PlanogramConstitutionalTest.php
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
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

const VENDGUARD_TEST_CATEGORY = 'new_engine';
const PLANOGRAM_CONST_SCHEMA = 't_pln11_const';

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
echo " VENDGUARD · T-PLN-11 · Blindaje constitucional del planograma\n";
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
$schemaReady = false;

try {
    echo "\n--- Escenario aislado (DDL + semillas canónicas) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, PLANOGRAM_CONST_SCHEMA);
    $schemaReady = true;
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);
    $seedCounts = (new SeedRunner($pdo))->seedAll();
    $pdo->exec("SELECT set_config('vendguard.purge', 'off', false)");

    echo sprintf(
        "  Semillas: %d productos, %d carriles\n",
        (int)($seedCounts['vending_products'] ?? 0),
        (int)($seedCounts['machine_planogram_slots'] ?? 0)
    );

    $router = AppRouter::create();
    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService(new PdoLocationRepository($pdo), $userRepo);

    $coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
    $restocker = $userRepo->findByEmail('lucia.repostaje@vendguard.internal');
    $assert('0.1 Semilla con coordinación y reponedora', $coordinator !== null && $restocker !== null);
    if ($coordinator === null || $restocker === null) {
        throw new RuntimeException('La semilla no contiene las identidades necesarias.');
    }

    $coordinatorToken = $authService->generateInternalToken($coordinator);
    $restockerToken = $authService->generateInternalToken($restocker);
    $restockerId = (int)$restocker->getId();

    $call = static function (
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        ?string $token = null
    ) use ($router): Response {
        $headers = $token !== null ? ['Authorization' => "Bearer {$token}"] : [];

        return $router->dispatch(new Request($method, $path, $query, $body, $headers));
    };

    /** Ejecuta una sentencia que debe ser rechazada por el motor. */
    $dbRejects = static function (string $sql, array $params, string $needle) use ($pdo): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return [false, 'la sentencia fue aceptada'];
        } catch (PDOException $exception) {
            return [str_contains($exception->getMessage(), $needle), $exception->getMessage()];
        }
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. Art. III.1 · prohibición de borrado físico
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Art. III.1 · sin DELETE físico ---\n";

    $productId = (int)$pdo->query('SELECT id FROM vending_products ORDER BY id LIMIT 1')->fetchColumn();
    $slotRow = $pdo->query('SELECT id, machine_id, slot_code FROM machine_planogram_slots WHERE is_active = TRUE ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $assert('1.1 Hay producto y carril sembrados', $productId > 0 && is_array($slotRow));

    [$productDeleteRejected, $productDeleteMessage] = $dbRejects(
        'DELETE FROM vending_products WHERE id = :id',
        [':id' => $productId],
        'ART_III_1_VIOLATION'
    );
    $productStillThere = (int)$pdo->query('SELECT COUNT(*) FROM vending_products WHERE id = ' . $productId)->fetchColumn();
    $assert(
        '1.2 DELETE de vending_products rechazado por prevent_hard_delete',
        $productDeleteRejected && $productStillThere === 1,
        $productDeleteMessage
    );

    $slotId = (int)$slotRow['id'];
    [$slotDeleteRejected, $slotDeleteMessage] = $dbRejects(
        'DELETE FROM machine_planogram_slots WHERE id = :id',
        [':id' => $slotId],
        'ART_III_1_VIOLATION'
    );
    $slotStillThere = (int)$pdo->query('SELECT COUNT(*) FROM machine_planogram_slots WHERE id = ' . $slotId)->fetchColumn();
    $assert(
        '1.3 DELETE de machine_planogram_slots rechazado por prevent_hard_delete',
        $slotDeleteRejected && $slotStillThere === 1,
        $slotDeleteMessage
    );

    $pdo->prepare('UPDATE machine_planogram_slots SET is_active = FALSE WHERE id = :id')->execute([':id' => $slotId]);
    $logicalRow = $pdo->query('SELECT is_active FROM machine_planogram_slots WHERE id = ' . $slotId)->fetch(PDO::FETCH_ASSOC);
    $assert(
        '1.4 La baja lógica conserva la fila con is_active = FALSE',
        $logicalRow !== false && $logicalRow['is_active'] === false
    );
    $pdo->prepare('UPDATE machine_planogram_slots SET is_active = TRUE WHERE id = :id')->execute([':id' => $slotId]);

    // ─────────────────────────────────────────────────────────────────────
    // 2. Art. II / D3 · cerrojo bidireccional en el motor
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Art. II / D3 · cerrojo directo e inverso ---\n";

    $snacksMachine = $pdo->query(
        "SELECT m.id FROM machines m
          INNER JOIN machine_planogram_slots s ON s.machine_id = m.id AND s.is_active = TRUE
         WHERE m.machine_type = 'SNACKS' AND m.is_active = TRUE AND m.deleted_at IS NULL
         ORDER BY m.id LIMIT 1"
    )->fetchColumn();
    $coldProduct = $pdo->query(
        'SELECT id, sku FROM vending_products WHERE requires_cold_chain = TRUE AND is_active = TRUE ORDER BY id LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    $hotProduct = $pdo->query(
        "SELECT id, sku FROM vending_products
          WHERE category = 'HOT_BEVERAGE_INGREDIENT' AND is_active = TRUE ORDER BY id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        '2.1 Semilla con máquina SNACKS, producto refrigerado e ingrediente caliente',
        (int)$snacksMachine > 0 && is_array($coldProduct) && is_array($hotProduct)
    );

    [$coldInsertRejected, $coldInsertMessage] = $dbRejects(
        'INSERT INTO machine_planogram_slots (machine_id, slot_code, product_id, max_capacity, current_estimated_qty, min_threshold, is_active)
         VALUES (:machine, :slot, :product, 5, 0, 1, TRUE)',
        [':machine' => (int)$snacksMachine, ':slot' => 'C11', ':product' => (int)$coldProduct['id']],
        'COLD_CHAIN_MACHINE_INCOMPATIBLE'
    );
    $assert('2.2 Frío en máquina SNACKS rechazado por el disparador', $coldInsertRejected, $coldInsertMessage);

    [$hotInsertRejected, $hotInsertMessage] = $dbRejects(
        'INSERT INTO machine_planogram_slots (machine_id, slot_code, product_id, max_capacity, current_estimated_qty, min_threshold, is_active)
         VALUES (:machine, :slot, :product, 5, 0, 1, TRUE)',
        [':machine' => (int)$snacksMachine, ':slot' => 'C12', ':product' => (int)$hotProduct['id']],
        'PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE'
    );
    $assert('2.3 Categoría incompatible con SNACKS rechazada por el disparador', $hotInsertRejected, $hotInsertMessage);

    $snackAssignment = $pdo->query(
        "SELECT vp.id, vp.category
           FROM vending_products vp
           INNER JOIN machine_planogram_slots s ON s.product_id = vp.id AND s.is_active = TRUE
           INNER JOIN machines m ON m.id = s.machine_id
          WHERE m.machine_type = 'SNACKS' AND vp.category = 'SNACK'
          ORDER BY vp.id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $assert('2.4 Hay un snack activo asignado a la máquina SNACKS', is_array($snackAssignment));
    if (is_array($snackAssignment)) {
        $snackId = (int)$snackAssignment['id'];
        [$productLockRejected, $productLockMessage] = $dbRejects(
            "UPDATE vending_products SET category = 'HOT_BEVERAGE_INGREDIENT' WHERE id = :id",
            [':id' => $snackId],
            'COLD_CHAIN_MACHINE_INCOMPATIBLE'
        );
        $currentCategory = $pdo->query('SELECT category FROM vending_products WHERE id = ' . $snackId)->fetchColumn();
        $assert(
            '2.5 Cambiar la categoría de un producto asignado a SNACKS se rechaza y no persiste',
            $productLockRejected && $currentCategory === 'SNACK',
            $productLockMessage
        );
    }

    [$machineLockRejected, $machineLockMessage] = $dbRejects(
        "UPDATE machines SET machine_type = 'HOT_DRINKS' WHERE id = :id",
        [':id' => (int)$snacksMachine],
        'PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE'
    );
    $currentMachineType = $pdo->query('SELECT machine_type FROM machines WHERE id = ' . (int)$snacksMachine)->fetchColumn();
    $assert(
        '2.6 Cambiar machine_type con carriles activos incompatibles se rechaza (QA 4)',
        $machineLockRejected && $currentMachineType === 'SNACKS',
        $machineLockMessage
    );

    $coldMachine = $pdo->query(
        "SELECT m.id FROM machines m
          INNER JOIN machine_planogram_slots s ON s.machine_id = m.id AND s.is_active = TRUE
          INNER JOIN vending_products vp ON vp.id = s.product_id AND vp.requires_cold_chain = TRUE
         WHERE m.machine_type IN ('PERISHABLE_FOOD', 'COMBO') AND m.is_active = TRUE AND m.deleted_at IS NULL
         ORDER BY m.id LIMIT 1"
    )->fetchColumn();
    $apiTypeChange = $call('PUT', '/api/coordinator/admin/machines/' . (int)$coldMachine, [], [
        'machine_type' => 'SNACKS',
    ], $coordinatorToken);
    $apiTypeBody = $json($apiTypeChange);
    $assert(
        '2.7 La API traduce el cerrojo inverso a 409 con conflictos',
        $apiTypeChange->getStatusCode() === 409
        && ($apiTypeBody['error']['code'] ?? '') === 'PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE'
        && ($apiTypeBody['error']['conflicts'] ?? []) !== []
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. QA 1 · vida útil condicional
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. QA 1 · max_shelf_life_days condicional ---\n";

    $coldWithoutShelf = $call('POST', '/api/coordinator/products', [], [
        'sku' => 'T11-COLD-NOSHELF',
        'name' => 'Snack refrigerado sin vida útil',
        'category' => 'SNACK',
        'unit_type' => 'UNIT',
        'unit_cost_eur' => 1.0,
        'sale_price_eur' => 2.0,
        'requires_cold_chain' => true,
    ], $coordinatorToken);
    $assert(
        '3.1 requires_cold_chain sin max_shelf_life_days responde 422',
        $coldWithoutShelf->getStatusCode() === 422
        && ($json($coldWithoutShelf)['error']['code'] ?? '') === 'PERISHABLE_REQUIRES_COLD_CHAIN_AND_SHELF_LIFE'
    );

    $coldWithShelf = $call('POST', '/api/coordinator/products', [], [
        'sku' => 'T11-COLD-SHELF',
        'name' => 'Snack refrigerado con vida útil',
        'category' => 'SNACK',
        'unit_type' => 'UNIT',
        'unit_cost_eur' => 1.0,
        'sale_price_eur' => 2.0,
        'requires_cold_chain' => true,
        'max_shelf_life_days' => 5,
    ], $coordinatorToken);
    $assert(
        '3.2 requires_cold_chain con vida útil válida se crea con 201',
        $coldWithShelf->getStatusCode() === 201
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Art. V.4 / QA 2 · segregación del reponedor
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Art. V.4 / QA 2 · reponedor ---\n";

    $restockerFleet = $call('GET', '/api/restocker/machines', [], [], $restockerToken);
    $fleetData = $json($restockerFleet)['data']['machines'] ?? [];
    $assert('4.1 El reponedor ve máquinas de sus zonas vivas', $restockerFleet->getStatusCode() === 200 && $fleetData !== []);
    if ($fleetData === []) {
        throw new RuntimeException('La reponedora semilla no tiene máquinas visibles.');
    }

    $visibleMachineId = (int)$fleetData[0]['id'];
    $readPlanogram = $call('GET', "/api/restocker/machines/{$visibleMachineId}/planogram", [], [], $restockerToken);
    $readBody = $json($readPlanogram);
    $readJson = (string)json_encode($readBody, JSON_UNESCAPED_UNICODE);
    $assert(
        '4.2 Lectura 200 sin unit_cost_eur ni unit_margin_eur',
        $readPlanogram->getStatusCode() === 200
        && !str_contains($readJson, 'unit_cost_eur')
        && !str_contains($readJson, 'unit_margin_eur')
    );

    $restockerMutation = $call('PUT', "/api/coordinator/machines/{$visibleMachineId}/planogram/slots/11", [], [
        'product_id' => 1,
        'max_capacity' => 10,
    ], $restockerToken);
    $restockerDelete = $call('DELETE', "/api/coordinator/machines/{$visibleMachineId}/planogram/slots/11", [], [], $restockerToken);
    $assert(
        '4.3 El reponedor no puede mutar el planograma (403 en PUT y DELETE)',
        $restockerMutation->getStatusCode() === 403 && $restockerDelete->getStatusCode() === 403
    );

    $zoneIds = $pdo->query(
        'SELECT zone_id FROM restocker_zone_assignments WHERE restocker_id = ' . $restockerId . ' AND removed_at IS NULL'
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert('4.4 La reponedora tiene al menos una zona viva', $zoneIds !== []);
    $pdo->prepare(
        'UPDATE restocker_zone_assignments SET removed_at = CURRENT_TIMESTAMP WHERE restocker_id = :id AND removed_at IS NULL'
    )->execute([':id' => $restockerId]);

    $fleetWithoutZones = $call('GET', '/api/restocker/machines', [], [], $restockerToken);
    $blockedPlanogram = $call('GET', "/api/restocker/machines/{$visibleMachineId}/planogram", [], [], $restockerToken);
    $assert(
        '4.5 Al retirar la zona, el parque queda vacío y la lectura responde 403',
        $json($fleetWithoutZones)['data']['machines'] === []
        && $blockedPlanogram->getStatusCode() === 403
        && ($json($blockedPlanogram)['error']['code'] ?? '') === 'MACHINE_OUTSIDE_RESTOCKER_ZONES'
    );

    $pdo->prepare(
        'UPDATE restocker_zone_assignments SET removed_at = NULL WHERE restocker_id = :id'
    )->execute([':id' => $restockerId]);
    $restoredFleet = $call('GET', '/api/restocker/machines', [], [], $restockerToken);
    $assert(
        '4.6 Restaurada la zona viva, el parque vuelve a estar disponible',
        $restoredFleet->getStatusCode() === 200 && count($json($restoredFleet)['data']['machines'] ?? []) >= 1
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. QA 3 / C1 · orden físico, UPSERT y clonación
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. QA 3 / C1 · orden, UPSERT y clonación ---\n";

    $orderMachine = null;
    foreach ($pdo->query(
        'SELECT machine_id FROM machine_planogram_slots WHERE is_active = TRUE GROUP BY machine_id ORDER BY COUNT(*) DESC'
    )->fetchAll(PDO::FETCH_COLUMN) as $candidate) {
        $rows = $pdo->query(
            'SELECT slot_code FROM machine_planogram_slots WHERE machine_id = ' . (int)$candidate . ' AND is_active = TRUE'
        )->fetchAll(PDO::FETCH_COLUMN);
        $hasNumeric = array_filter($rows, static fn (string $code): bool => ctype_digit($code)) !== [];
        $hasAlpha = array_filter($rows, static fn (string $code): bool => !ctype_digit($code)) !== [];
        if ($hasNumeric && $hasAlpha) {
            $orderMachine = (int)$candidate;
            break;
        }
    }
    $assert('5.1 Hay una máquina con carriles numéricos y alfanuméricos', $orderMachine !== null);
    if ($orderMachine !== null) {
        $ordered = $json($call('GET', "/api/coordinator/machines/{$orderMachine}/planogram", [], [], $coordinatorToken))['data']['slots'] ?? [];
        $codes = array_column($ordered, 'slot_code');
        $expected = $codes;
        usort($expected, static function (string $a, string $b): int {
            $aNumeric = ctype_digit($a);
            $bNumeric = ctype_digit($b);
            if ($aNumeric && $bNumeric) {
                return (int)$a <=> (int)$b;
            }
            if ($aNumeric !== $bNumeric) {
                return $aNumeric ? -1 : 1;
            }

            return strcmp($a, $b);
        });
        $assert(
            '5.2 Los carriles llegan en orden físico (numéricos y después alfanuméricos)',
            $codes !== [] && $codes === $expected
        );
    }

    $comboMachines = $pdo->query(
        "SELECT m.id, COUNT(DISTINCT s.product_id) products
           FROM machines m
           INNER JOIN machine_planogram_slots s ON s.machine_id = m.id AND s.is_active = TRUE
          WHERE m.machine_type = 'COMBO' AND m.is_active = TRUE AND m.deleted_at IS NULL
          GROUP BY m.id
          ORDER BY products DESC, m.id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $assert('5.3 Hay al menos dos máquinas COMBO con carriles', count($comboMachines) >= 2);
    if (count($comboMachines) < 2) {
        throw new RuntimeException('No hay dos máquinas COMBO para certificar la clonación.');
    }

    $sourceMachine = (int)$comboMachines[0]['id'];
    $targetMachine = (int)$comboMachines[1]['id'];
    $slotProduct = $pdo->query(
        'SELECT product_id FROM machine_planogram_slots WHERE machine_id = ' . $sourceMachine . ' AND is_active = TRUE ORDER BY id LIMIT 1'
    )->fetchColumn();
    $slotCode = 'T11UP';
    $firstSave = $call('PUT', "/api/coordinator/machines/{$sourceMachine}/planogram/slots/{$slotCode}", [], [
        'product_id' => (int)$slotProduct,
        'max_capacity' => 10,
        'current_estimated_qty' => 4,
        'min_threshold' => 2,
    ], $coordinatorToken);
    $secondSave = $call('PUT', "/api/coordinator/machines/{$sourceMachine}/planogram/slots/{$slotCode}", [], [
        'product_id' => (int)$slotProduct,
        'max_capacity' => 10,
        'current_estimated_qty' => 6,
        'min_threshold' => 2,
    ], $coordinatorToken);
    $physicalRows = (int)$pdo->query(
        "SELECT COUNT(*) FROM machine_planogram_slots WHERE machine_id = {$sourceMachine} AND slot_code = '{$slotCode}'"
    )->fetchColumn();
    $storedQty = (int)$pdo->query(
        "SELECT current_estimated_qty FROM machine_planogram_slots WHERE machine_id = {$sourceMachine} AND slot_code = '{$slotCode}'"
    )->fetchColumn();
    $assert(
        '5.4 Dos UPSERT del mismo carril dejan una fila física con la última existencia',
        $firstSave->getStatusCode() === 200
        && $secondSave->getStatusCode() === 200
        && $physicalRows === 1
        && $storedQty === 6
    );

    $bounds = $call('PUT', "/api/coordinator/machines/{$sourceMachine}/planogram/slots/T11BAD", [], [
        'product_id' => (int)$slotProduct,
        'max_capacity' => 5,
        'current_estimated_qty' => 0,
        'min_threshold' => 5,
    ], $coordinatorToken);
    $assert(
        '5.5 Umbral igual a capacidad responde 422 INVALID_PLANOGRAM_SLOT_BOUNDS',
        $bounds->getStatusCode() === 422
        && ($json($bounds)['error']['code'] ?? '') === 'INVALID_PLANOGRAM_SLOT_BOUNDS'
    );

    $skippedProduct = $pdo->query(
        "SELECT vp.id, vp.sku FROM vending_products vp
          WHERE vp.is_active = TRUE
            AND EXISTS (SELECT 1 FROM machine_planogram_slots s WHERE s.product_id = vp.id AND s.machine_id = {$sourceMachine} AND s.is_active = TRUE)
          ORDER BY vp.id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $assert('5.6 Hay un producto activo en el origen para dar de baja', is_array($skippedProduct));
    if (is_array($skippedProduct)) {
        $pdo->prepare('UPDATE vending_products SET is_active = FALSE WHERE id = :id')
            ->execute([':id' => (int)$skippedProduct['id']]);

        $clone = $call('POST', "/api/coordinator/machines/{$targetMachine}/planogram/clone", [], [
            'source_machine_id' => $sourceMachine,
            'initial_stock_mode' => 'ZERO',
        ], $coordinatorToken);
        $cloneBody = $json($clone);
        $skippedSkus = array_column($cloneBody['data']['skipped_inactive_slots'] ?? [], 'product_sku');
        $targetProductIds = array_map(
            static fn (array $row): int => (int)$row['product_id'],
            $json($call('GET', "/api/coordinator/machines/{$targetMachine}/planogram", [], [], $coordinatorToken))['data']['slots'] ?? []
        );
        $assert(
            '5.7 La clonación omite el producto en baja y lo informa',
            $clone->getStatusCode() === 200
            && in_array($skippedProduct['sku'], $skippedSkus, true)
            && !in_array((int)$skippedProduct['id'], $targetProductIds, true)
        );
    }

    $pdo->prepare(
        'UPDATE vending_products SET is_active = FALSE
          WHERE id IN (SELECT DISTINCT product_id FROM machine_planogram_slots WHERE machine_id = :id AND is_active = TRUE)'
    )->execute([':id' => $sourceMachine]);
    $emptyClone = $call('POST', "/api/coordinator/machines/{$targetMachine}/planogram/clone", [], [
        'source_machine_id' => $sourceMachine,
        'initial_stock_mode' => 'ZERO',
    ], $coordinatorToken);
    $assert(
        '5.8 Origen sin productos activos responde 422 SOURCE_PLANOGRAM_EMPTY',
        $emptyClone->getStatusCode() === 422
        && ($json($emptyClone)['error']['code'] ?? '') === 'SOURCE_PLANOGRAM_EMPTY'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Art. III.3 · auditoría de las mutaciones
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Art. III.3 · auditoría ---\n";

    $actions = $pdo->query(
        "SELECT action, COUNT(*) AS total FROM audit_log
          WHERE entity_type IN ('VENDING_PRODUCT', 'MACHINE_PLANOGRAM')
          GROUP BY action"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert(
        '6.1 Alta de producto auditada como VENDING_PRODUCT_CREATED',
        (int)($actions['VENDING_PRODUCT_CREATED'] ?? 0) >= 1
    );
    $assert(
        '6.2 UPSERT de carril auditado como MACHINE_PLANOGRAM_SLOT_SAVED',
        (int)($actions['MACHINE_PLANOGRAM_SLOT_SAVED'] ?? 0) >= 2
    );
    $assert(
        '6.3 Clonación auditada como PLANOGRAM_CLONED',
        (int)($actions['PLANOGRAM_CLONED'] ?? 0) >= 1
    );
} catch (Throwable $exception) {
    $failures++;
    echo "\n[EXCEPCIÓN] " . $exception->getMessage() . "\n";
    echo $exception->getTraceAsString() . "\n";
} finally {
    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, PLANOGRAM_CONST_SCHEMA);
        } catch (Throwable) {
            // El cierre no debe ocultar el resultado de la suite.
        }
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PLN-11 (blindaje constitucional) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
