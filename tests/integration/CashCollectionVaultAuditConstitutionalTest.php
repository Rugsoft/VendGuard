<?php

declare(strict_types=1);

/**
 * CashCollectionVaultAuditConstitutionalTest · T-CCV-09
 *
 * Certifica el blindaje constitucional del Módulo 35 sobre el router real, el
 * servicio de recaudación, los repositorios PDO y el motor PostgreSQL:
 *
 *   1. **Art. II.2 / C4.** La aplicación rechaza una visita
 *      `ABORTED_THERMAL_BREACH` con `cash_collection` (`422
 *      THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN`) y el disparador SQL rechaza la
 *      captura sobre una máquina en cuarentena (`SQLSTATE 45000`).
 *   2. **Art. V.4 / QA 2.** El reponedor nunca recibe las cinco claves del
 *      Arqueo Ciego; el recibo de conserjería tiene exactamente nueve campos y
 *      no filtra bolsa, PIN ni PII; otro reponedor y el técnico SAT quedan
 *      fuera.
 *   3. **Art. III.1 / III.3.** El motor rechaza el `DELETE` físico, la
 *      reescritura de los datos declarados en ruta y cualquier `UPDATE` sobre
 *      una fila ya arqueada; la aplicación bloquea el re-arqueo con `409`.
 *   4. **Art. V.1.** El reseteo de contador sin nota real y el descuadre sin
 *      justificación se rechazan en aplicación y en el `CHECK` del motor.
 *   5. **QA 1 / Art. III.3.** Una bolsa en tránsito se recepciona y arquea
 *      incluso después de la baja lógica del reponedor y de la máquina,
 *      conservando los snapshots históricos de máquina, reponedor y revisor de
 *      caja incluso tras la baja lógica y cambio de nombre del revisor.
 *   6. **Art. III.3 / RF-04.1.** El alta, la recepción en cámara
 *      (`CASH_COLLECTION_DELIVERED_TO_VAULT`) y el arqueo dejan eventos
 *      auditables `MACHINE_CASH_COLLECTION` con actor y rol `COORDINATOR`.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL y trabaja en un esquema aislado; sin
 * motor responde código 2. El esquema se retira con `CASCADE` en el `finally`.
 *
 * Uso:
 *   php tests/integration/CashCollectionVaultAuditConstitutionalTest.php
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
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

const VENDGUARD_TEST_CATEGORY = 'new_engine';

const CCV09_CONST_SCHEMA = 't_ccv09_const';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? ' · ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-CCV-09 · Blindaje constitucional de la recaudación\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

try {
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

/** @return array<string, mixed> */
$json = static fn (Response $response): array => $response->getDecodedBody() ?? [];

/** @return array<string, mixed> */
$data = static fn (Response $response): array => $json($response)['data'] ?? [];

$errorCode = static fn (Response $response): string => (string)($json($response)['error']['code'] ?? '');

/** @return list<string> */
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

$schemaReady = false;
$exitCode = 1;

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, CCV09_CONST_SCHEMA);
    $schemaReady = true;
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $createUser = static function (string $suffix, string $role, string $name) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE, FALSE)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => $name,
            ':email' => 'ccv09.' . $suffix . '@vendguard.internal',
            ':email_key' => 'ccv09.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('ccv09-' . $suffix, PASSWORD_BCRYPT, ['cost' => 4]),
            ':role' => $role,
            ':operator' => 'CCV9-' . strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR', 'Coordinación T-CCV-09');
    $reviewerCoordinatorId = $createUser('reviewer', 'COORDINATOR', 'Revisor Caja T-CCV-09');
    $ownerId = $createUser('owner', 'RESTOCKER', 'Reposición Titular T-CCV-09');
    $otherId = $createUser('other', 'RESTOCKER', 'Reposición Ajena T-CCV-09');
    $technicianId = $createUser('sat', 'TECHNICIAN', 'Técnico SAT T-CCV-09');

    $zoneId = (int)$pdo->query(
        "INSERT INTO operational_zones (code, name, color_hex)
         VALUES ('ZONA-CCV9', 'Zona de certificación T-CCV-09', '#2560ff')
         RETURNING id"
    )->fetchColumn();

    $locationStmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, zone_id, has_physical_reception)
         VALUES (:site_code, :site_code_key, :name, :address, :zone_id, TRUE)
         RETURNING id'
    );
    $locationStmt->execute([
        ':site_code' => 'SEDE-CCV9',
        ':site_code_key' => 'sede-ccv9',
        ':name' => 'Sede de certificación T-CCV-09',
        ':address' => 'Carrer de la Custòdia 9, Barcelona',
        ':zone_id' => $zoneId,
    ]);
    $locationId = (int)$locationStmt->fetchColumn();

    $createMachine = static function (string $code) use ($pdo, $locationId): int {
        $stmt = $pdo->prepare(
            "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
             VALUES (:location_id, :code, :model, 'SNACKS', :floor_wing)
             RETURNING id"
        );
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => 'Modelo de certificación ' . $code,
            ':floor_wing' => 'Planta baja',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $machineId = $createMachine('CCV9-SNACK-01');
    $quarantineMachineId = $createMachine('CCV9-SNACK-02');

    $assignStmt = $pdo->prepare(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES (:restocker_id, :zone_id, 'PRIMARY', TRUE)"
    );
    $assignStmt->execute([':restocker_id' => $ownerId, ':zone_id' => $zoneId]);
    $assignStmt->execute([':restocker_id' => $otherId, ':zone_id' => $zoneId]);
    $pdo->prepare(
        'UPDATE restocker_zone_assignments
            SET removed_at = CURRENT_TIMESTAMP
          WHERE restocker_id = :restocker_id AND is_primary = TRUE'
    )->execute([':restocker_id' => $otherId]);

    $refundStmt = $pdo->prepare(
        "INSERT INTO refund_requests
             (machine_id, location_id, claimant_name, claimant_contact, claimed_amount,
              compensation_method, tracking_token, status)
         VALUES (:machine_id, :location_id, 'Reclamante de prueba', 'ccv09@example.test', 5.00,
                 'EN_MANO_SEDE', 'ccv09-refund-token-0001', 'PENDING_INSPECTION')
         RETURNING id"
    );
    $refundStmt->execute([':machine_id' => $machineId, ':location_id' => $locationId]);
    $refundId = (int)$refundStmt->fetchColumn();

    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService(null, $userRepo);
    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $reviewerToken = $authService->generateInternalToken($userRepo->findById($reviewerCoordinatorId));
    $ownerToken = $authService->generateInternalToken($userRepo->findById($ownerId));
    $otherToken = $authService->generateInternalToken($userRepo->findById($otherId));
    $technicianToken = $authService->generateInternalToken($userRepo->findById($technicianId));

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

    $reject = static function (callable $operation): ?array {
        try {
            $operation();
        } catch (PDOException $exception) {
            return ['state' => (string)$exception->getCode(), 'message' => $exception->getMessage()];
        }

        return null;
    };

    $insertSqlCash = static function (
        int $targetMachineId,
        string $machineCode,
        string $collectionCode,
        string $seal,
        string $mutation
    ) use ($pdo, $ownerId): int {
        $stmt = $pdo->prepare(
            "INSERT INTO machine_cash_collections
                 (collection_code, restock_visit_id, machine_id, machine_code_snapshot,
                  restocker_id, restocker_name_snapshot, restocker_code_snapshot,
                  bag_seal_code, change_tubes_loaded_eur, sales_counter_reading,
                  initial_baseline_reading_eur, expected_cashbox_eur,
                  collected_at, client_mutation_id)
             VALUES (:collection_code, NULL, :machine_id, :machine_code,
                     :restocker_id, 'Reposición Constitucional', 'CCV9-R',
                     :bag_seal, 10.00, 100.00, 0.00, 100.00,
                     CURRENT_TIMESTAMP, :client_mutation_id)
             RETURNING id"
        );
        $stmt->execute([
            ':collection_code' => $collectionCode,
            ':machine_id' => $targetMachineId,
            ':machine_code' => $machineCode,
            ':restocker_id' => $ownerId,
            ':bag_seal' => $seal,
            ':client_mutation_id' => $mutation,
        ]);

        return (int)$stmt->fetchColumn();
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. Art. II.2 · primacía sanitaria en aplicación y motor
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Primacía sanitaria (Art. II.2, C4) ---\n";

    $thermal = $call('POST', '/api/restocker/machines/' . $machineId . '/visits', [
        'status' => 'ABORTED_THERMAL_BREACH',
        'client_mutation_id' => 'ccv09-visit-thermal-0001',
        'cabin_temp_celsius' => 9.5,
        'cash_collection' => [
            'client_mutation_id' => 'ccv09-thermal-cash-0001',
            'bag_seal_code' => 'BAG-CCV9-0001',
            'sales_counter_reading' => 10.00,
        ],
    ], $ownerToken);
    $assert(
        '1.1 La aplicación rechaza recaudación en aborto térmico (422 THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN)',
        $thermal->getStatusCode() === 422
            && $errorCode($thermal) === 'THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN',
        $errorCode($thermal)
    );
    $thermalVisitCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM machine_restock_visits WHERE client_mutation_id = 'ccv09-visit-thermal-0001'"
    )->fetchColumn();
    $assert('1.2 El rechazo no deja visita residual', $thermalVisitCount === 0);

    $pdo->prepare("UPDATE machines SET sanitary_status = 'QUARANTINE' WHERE id = :id")
        ->execute([':id' => $quarantineMachineId]);
    $quarantine = $call('POST', '/api/restocker/machines/' . $quarantineMachineId . '/cash-collections', [
        'client_mutation_id' => 'ccv09-quarantine-cash-0001',
        'bag_seal_code' => 'BAG-CCV9-0002',
        'sales_counter_reading' => 10.00,
    ], $ownerToken);
    $assert(
        '1.3 La aplicación rechaza la captura en cuarentena (409 SANITARY_CASH_COLLECTION_BLOCKED)',
        $quarantine->getStatusCode() === 409
            && $errorCode($quarantine) === 'SANITARY_CASH_COLLECTION_BLOCKED',
        $errorCode($quarantine)
    );

    $sqlQuarantine = $reject(static fn () => $insertSqlCash(
        $quarantineMachineId,
        'CCV9-SNACK-02',
        'MCC-2026-9001',
        'BAG-CCV9-0003',
        'ccv09-quarantine-sql-0001'
    ));
    $assert(
        '1.4 El motor rechaza la misma captura en cuarentena (45000 ART_II)',
        $sqlQuarantine !== null
            && $sqlQuarantine['state'] === '45000'
            && str_contains($sqlQuarantine['message'], 'ART_II_SANITARY_CASH_COLLECTION_BLOCKED'),
        (string)($sqlQuarantine['state'] ?? 'sin rechazo')
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Art. V.4 · Arqueo Ciego, recibo opaco y RBAC
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Opacidad financiera y recibo (Art. V.4, QA 2) ---\n";

    $capturePayload = [
        'client_mutation_id' => 'ccv09-cash-0001',
        'bag_seal_code' => 'BAG-CCV9-0004',
        'sales_counter_reading' => 120.00,
        'change_tubes_loaded_eur' => 10.00,
        'reception_cash_delivered_eur' => 5.00,
        'receptionist_name' => 'Conserje T-CCV-09',
        'linked_refund_request_id' => $refundId,
    ];
    $created = $call('POST', '/api/restocker/machines/' . $machineId . '/cash-collections', $capturePayload, $ownerToken);
    $createdData = $data($created);
    $collectionId = (int)($createdData['id'] ?? 0);
    $privateKeys = [
        'previous_sales_counter_reading',
        'expected_cashbox_eur',
        'vault_counted_eur',
        'discrepancy_eur',
        'discrepancy_justification',
    ];
    $createdKeys = $collectKeys($createdData);
    $assert(
        '2.1 La captura del reponedor no filtra ninguna de las cinco claves privadas',
        $created->getStatusCode() === 201
            && $collectionId > 0
            && array_intersect($privateKeys, $createdKeys) === []
    );

    $history = $call('GET', '/api/restocker/cash-collections', [], $ownerToken);
    $assert(
        '2.2 El historial móvil conserva el Arqueo Ciego',
        $history->getStatusCode() === 200
            && array_intersect($privateKeys, $collectKeys($data($history))) === []
    );

    $receipt = $call('GET', '/api/restocker/cash-collections/' . $collectionId . '/reception-receipt', [], $ownerToken);
    $receiptData = $data($receipt);
    $receiptKeys = array_keys($receiptData);
    sort($receiptKeys);
    $expectedReceiptKeys = [
        'collection_code',
        'collected_at',
        'linked_refund_request_id',
        'location_code',
        'location_name',
        'machine_code',
        'reception_cash_delivered_eur',
        'receptionist_name',
        'restocker_name',
    ];
    sort($expectedReceiptKeys);
    $assert(
        '2.3 El recibo tiene exactamente los nueve campos del contrato',
        $receipt->getStatusCode() === 200
            && count($receiptData) === 9
            && $receiptKeys === $expectedReceiptKeys
    );
    $assert(
        '2.4 El recibo no contiene bolsa, PIN, PII ni datos del arqueo',
        array_intersect(
            ['bag_seal_code', 'pickup_pin', 'claimant_name', 'claimant_contact', 'iban', 'bizum_phone', ...$privateKeys],
            array_keys($receiptData)
        ) === []
    );

    $otherReceipt = $call('GET', '/api/restocker/cash-collections/' . $collectionId . '/reception-receipt', [], $otherToken);
    $assert(
        '2.5 Otro reponedor no puede leer el recibo ajeno (403)',
        $otherReceipt->getStatusCode() === 403
            && $errorCode($otherReceipt) === 'CASH_COLLECTION_FORBIDDEN_FOR_ROLE'
    );

    $technicianList = $call('GET', '/api/restocker/cash-collections', [], $technicianToken);
    $assert('2.6 El técnico SAT no alcanza el libro móvil (403)', $technicianList->getStatusCode() === 403);

    // ─────────────────────────────────────────────────────────────────────
    // 3. Art. V.1 · contador y justificaciones reales
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Justificaciones reales (Art. V.1) ---\n";

    $shortReset = $call('POST', '/api/restocker/machines/' . $machineId . '/cash-collections', [
        'client_mutation_id' => 'ccv09-reset-short-0001',
        'bag_seal_code' => 'BAG-CCV9-0005',
        'sales_counter_reading' => 150.00,
        'is_counter_reset' => true,
        'counter_reset_notes' => 'corto',
    ], $ownerToken);
    $assert(
        '3.1 Un reseteo con nota irreal se rechaza (422 COUNTER_RESET_NOTES_REQUIRED)',
        $shortReset->getStatusCode() === 422
            && $errorCode($shortReset) === 'COUNTER_RESET_NOTES_REQUIRED',
        $errorCode($shortReset)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Art. III.1 / III.3 · inmutabilidad y bloqueo terminal
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Inmutabilidad por etapas (Art. III.1 y III.3) ---\n";

    $routeRewrite = $reject(static function () use ($pdo, $collectionId): void {
        $pdo->prepare('UPDATE machine_cash_collections SET bag_seal_code = :seal WHERE id = :id')
            ->execute([':seal' => 'BAG-CCV9-9999', ':id' => $collectionId]);
    });
    $assert(
        '4.1 El motor rechaza reescribir el precinto declarado en ruta (45000 ART_III_3_ROUTE)',
        $routeRewrite !== null
            && $routeRewrite['state'] === '45000'
            && str_contains($routeRewrite['message'], 'ART_III_3_ROUTE_CAPTURE_IMMUTABLE'),
        (string)($routeRewrite['state'] ?? 'sin rechazo')
    );

    $hardDelete = $reject(static function () use ($pdo, $collectionId): void {
        $pdo->prepare('DELETE FROM machine_cash_collections WHERE id = :id')
            ->execute([':id' => $collectionId]);
    });
    $assert(
        '4.2 El motor rechaza el borrado físico (45000 ART_III_1)',
        $hardDelete !== null
            && $hardDelete['state'] === '45000'
            && str_contains($hardDelete['message'], 'ART_III_1_VIOLATION'),
        (string)($hardDelete['state'] ?? 'sin rechazo')
    );

    $reconciled = $call('POST', '/api/coordinator/cash-collections/' . $collectionId . '/reconcile', [
        'vault_counted_eur' => 120.00,
    ], $coordinatorToken);
    $assert(
        '4.3 El arqueo cuadrado sella RECONCILED_OK',
        $reconciled->getStatusCode() === 200
            && (($data($reconciled)['status'] ?? '') === 'RECONCILED_OK')
    );

    $terminalRewrite = $reject(static function () use ($pdo, $collectionId): void {
        $pdo->prepare('UPDATE machine_cash_collections SET route_notes = :notes WHERE id = :id')
            ->execute([':notes' => 'Reescritura tardía', ':id' => $collectionId]);
    });
    $assert(
        '4.4 El motor bloquea cualquier UPDATE tras el arqueo (45000 ART_III_3_CASH_COLLECTION_IMMUTABLE)',
        $terminalRewrite !== null
            && $terminalRewrite['state'] === '45000'
            && str_contains($terminalRewrite['message'], 'ART_III_3_CASH_COLLECTION_IMMUTABLE'),
        (string)($terminalRewrite['state'] ?? 'sin rechazo')
    );

    $reReconciled = $call('POST', '/api/coordinator/cash-collections/' . $collectionId . '/reconcile', [
        'vault_counted_eur' => 120.00,
    ], $coordinatorToken);
    $assert(
        '4.5 La aplicación rechaza el re-arqueo (409 CASH_COLLECTION_ALREADY_RECONCILED)',
        $reReconciled->getStatusCode() === 409
            && $errorCode($reReconciled) === 'CASH_COLLECTION_ALREADY_RECONCILED'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. QA 1 · baja lógica, snapshots y arqueo en tránsito
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Snapshots históricos y baja lógica (QA 1) ---\n";

    $transitId = $insertSqlCash($machineId, 'CCV9-SNACK-01', 'MCC-2026-9002', 'BAG-CCV9-0006', 'ccv09-transit-0001');
    $delivered = $call('POST', '/api/coordinator/cash-collections/' . $transitId . '/deliver-to-vault', [], $coordinatorToken);
    $assert(
        '5.1 La bolsa en tránsito se recepciona antes de la baja',
        $delivered->getStatusCode() === 200
            && (($data($delivered)['status'] ?? '') === 'DELIVERED_TO_VAULT')
    );

    $pdo->prepare('UPDATE users SET is_active = FALSE WHERE id = :id')->execute([':id' => $ownerId]);
    $pdo->prepare('UPDATE machines SET is_active = FALSE WHERE id = :id')->execute([':id' => $machineId]);

    $transitReconciled = $call('POST', '/api/coordinator/cash-collections/' . $transitId . '/reconcile', [
        'vault_counted_eur' => 100.00,
    ], $reviewerToken);
    $assert(
        '5.2 Una bolsa en tránsito se arquea aunque máquina y reponedor estén de baja',
        $transitReconciled->getStatusCode() === 200
            && (($data($transitReconciled)['status'] ?? '') === 'RECONCILED_OK')
            && (($data($transitReconciled)['vault_reconciled_by_name_snapshot'] ?? '') === 'Revisor Caja T-CCV-09')
    );

    $pdo->prepare('UPDATE users SET is_active = FALSE, name = :renamed WHERE id = :id')
        ->execute([':renamed' => 'Revisor Renombrado Tras Baja', ':id' => $reviewerCoordinatorId]);

    $snapshots = $pdo->prepare(
        'SELECT machine_code_snapshot, restocker_name_snapshot, restocker_code_snapshot, vault_reconciled_by_name_snapshot
           FROM machine_cash_collections WHERE id = :id'
    );
    $snapshots->execute([':id' => $transitId]);
    $snapshotRow = $snapshots->fetch(PDO::FETCH_ASSOC);
    $assert(
        '5.3 Los snapshots de máquina, reponedor y revisor de caja sobreviven a la baja lógica y cambio de nombre',
        is_array($snapshotRow)
            && ($snapshotRow['machine_code_snapshot'] ?? '') === 'CCV9-SNACK-01'
            && ($snapshotRow['restocker_name_snapshot'] ?? '') === 'Reposición Constitucional'
            && ($snapshotRow['restocker_code_snapshot'] ?? '') === 'CCV9-R'
            && ($snapshotRow['vault_reconciled_by_name_snapshot'] ?? '') === 'Revisor Caja T-CCV-09'
    );

    $historicalDetail = $call('GET', '/api/coordinator/cash-collections/' . $transitId, [], $coordinatorToken);
    $historicalData = $data($historicalDetail);
    $assert(
        '5.4 La lectura histórica de Coordinación conserva el snapshot del revisor sin recalcularlo desde users',
        $historicalDetail->getStatusCode() === 200
            && ($historicalData['machine_code_snapshot'] ?? '') === 'CCV9-SNACK-01'
            && ($historicalData['restocker_name_snapshot'] ?? '') === 'Reposición Constitucional'
            && ($historicalData['restocker_code_snapshot'] ?? '') === 'CCV9-R'
            && ($historicalData['vault_reconciled_by_name_snapshot'] ?? '') === 'Revisor Caja T-CCV-09'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Art. III.3 / RF-04.1 · auditoría de alta, recepción en cámara y arqueo
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Trazabilidad en audit_log (Art. III.3, RF-04.1) ---\n";

    $actions = $pdo->query(
        "SELECT action, COUNT(*) AS total
           FROM audit_log
          WHERE entity_type = 'MACHINE_CASH_COLLECTION'
          GROUP BY action"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert(
        '6.1 El alta, la recepción en cámara y el arqueo dejan eventos de auditoría del módulo',
        (int)($actions['CASH_COLLECTION_SEALED'] ?? 0) >= 1
            && (int)($actions['CASH_COLLECTION_DELIVERED_TO_VAULT'] ?? 0) >= 1
            && (int)($actions['CASH_COLLECTION_RECONCILED'] ?? 0) >= 1
    );

    $coordinatorActor = $pdo->query(
        "SELECT COUNT(*) FROM audit_log
          WHERE entity_type = 'MACHINE_CASH_COLLECTION'
            AND action = 'CASH_COLLECTION_RECONCILED'
            AND user_role = 'COORDINATOR'"
    )->fetchColumn();
    $assert('6.2 El arqueo se audita con el rol COORDINATOR', (int)$coordinatorActor >= 1);

    $deliveredAuditStmt = $pdo->prepare(
        "SELECT user_id, user_role, user_name
           FROM audit_log
          WHERE entity_type = 'MACHINE_CASH_COLLECTION'
            AND entity_id = :entity_id
            AND action = 'CASH_COLLECTION_DELIVERED_TO_VAULT'
          ORDER BY id DESC"
    );
    $deliveredAuditStmt->execute([':entity_id' => $transitId]);
    $deliveredAuditRows = $deliveredAuditStmt->fetchAll(PDO::FETCH_ASSOC);
    $assert(
        '6.3 La recepción en cámara deja CASH_COLLECTION_DELIVERED_TO_VAULT con actor y rol COORDINATOR sobre la bolsa correcta',
        count($deliveredAuditRows) === 1
            && (int)($deliveredAuditRows[0]['user_id'] ?? 0) === $coordinatorId
            && ($deliveredAuditRows[0]['user_role'] ?? '') === 'COORDINATOR'
            && ($deliveredAuditRows[0]['user_name'] ?? '') === 'Coordinación T-CCV-09'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. V.1 · el CHECK del motor exige justificación en el descuadre
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 7. CHECK de justificación en el motor (Art. V.1) ---\n";

    $checkId = $insertSqlCash($machineId, 'CCV9-SNACK-01', 'MCC-2026-9003', 'BAG-CCV9-0007', 'ccv09-check-0001');
    $checkDelivered = $call('POST', '/api/coordinator/cash-collections/' . $checkId . '/deliver-to-vault', [], $coordinatorToken);
    $assert(
        '7.1 La fila de control se recepciona para ejercer el CHECK terminal',
        $checkDelivered->getStatusCode() === 200
    );

    $flaggedWithoutJustification = $reject(static function () use ($pdo, $checkId, $coordinatorId): void {
        $pdo->prepare(
            "UPDATE machine_cash_collections
                SET status = 'DISCREPANCY_FLAGGED',
                    vault_counted_eur = 110.00,
                    discrepancy_eur = 10.00,
                    vault_reconciled_by = :coordinator_id,
                    vault_reconciled_by_name_snapshot = 'Coordinación T-CCV-09',
                    vault_reconciled_at = CURRENT_TIMESTAMP,
                    discrepancy_justification = NULL
              WHERE id = :id"
        )->execute([':coordinator_id' => $coordinatorId, ':id' => $checkId]);
    });
    $assert(
        '7.2 El CHECK rechaza un descuadre > 1,00 € sin justificación (23514)',
        $flaggedWithoutJustification !== null
            && $flaggedWithoutJustification['state'] === '23514'
            && str_contains($flaggedWithoutJustification['message'], 'chk_machine_cash_collections_vault_lifecycle'),
        (string)($flaggedWithoutJustification['state'] ?? 'sin rechazo')
    );

    $ok = $failures === 0;
    echo "\n======================================================================\n";
    echo ' Total Aserciones: ' . $assertions . ' | Fallidas: ' . $failures . "\n";
    if ($ok) {
        echo " RESULTADO: 100% EN VERDE. BLINDAJE CONSTITUCIONAL CERTIFICADO (T-CCV-09).\n";
        echo "======================================================================\n";
        $exitCode = 0;
    } else {
        echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
        echo "======================================================================\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[ERROR] " . $exception::class . ': ' . $exception->getMessage() . "\n");
    fwrite(STDERR, $exception->getTraceAsString() . "\n");
} finally {
    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, CCV09_CONST_SCHEMA);
        } catch (Throwable $cleanupException) {
            fwrite(STDERR, '[LIMPIEZA] No se pudo retirar el esquema aislado: ' . $cleanupException->getMessage() . "\n");
        }
    }
}

exit($exitCode);
