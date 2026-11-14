<?php

declare(strict_types=1);

/**
 * InSituRestockVisitConstitutionalTest · T-RVF-10
 *
 * Certifica el blindaje constitucional del Módulo 34 sobre el router real, el
 * servicio de visitas, los repositorios PDO y el motor PostgreSQL:
 *
 *   1. **Art. V.4.** RBAC de las dos superficies y opacidad de los snapshots
 *      contables: el reponedor, el técnico SAT y el QR público nunca reciben
 *      `unit_cost_snapshot_eur` ni `commercial_spoilage_loss_eur`.
 *   2. **Art. II / D2 / QA 2.** El techo térmico se aplica por tipología
 *      (4,0 °C en perecedero/mixta y 8,0 °C en bebidas frías), el aborto exige
 *      precinto, foto y nota de 20 caracteres reales, deja la máquina en
 *      cuarentena y abre o escala un único ticket `CRITICAL`.
 *   3. **Art. II / QA 1.** El doble FEFO bloquea la carga sobre un carril
 *      caducado o a punto de caducar sin retirada total, el lote caducado de la
 *      furgoneta y el lote más antiguo que aún tiene saldo.
 *   4. **Art. III.1 / III.3.** El motor rechaza `DELETE` y `UPDATE` sobre la
 *      visita y su desglose, y cada alta o volcado deja rastro en `audit_log`
 *      sin costes.
 *   5. **Art. V.5.** La evidencia respeta el límite de 5 MB y se valida por
 *      magic bytes reales, no por la extensión declarada.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL y un esquema aislado; sin motor sale
 * con código 2. El esquema se retira con `CASCADE` en el `finally` y los
 * ficheros de evidencia se borran al terminar.
 *
 * Uso:
 *   php tests/integration/InSituRestockVisitConstitutionalTest.php
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

const RVF10_CONST_SCHEMA = 't_rvf10_const';

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
echo " VENDGUARD · T-RVF-10 · Blindaje constitucional de la visita in situ\n";
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
$uploadedFiles = [];
$tempFiles = [];
$exitCode = 1;

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, RVF10_CONST_SCHEMA);
    $schemaReady = true;
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $createUser = static function (PDO $pdo, string $suffix, string $role, string $name): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE, FALSE)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => $name,
            ':email' => 'rvf10.' . $suffix . '@vendguard.internal',
            ':email_key' => 'rvf10.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('rvf10-' . $suffix, PASSWORD_BCRYPT, ['cost' => 4]),
            ':role' => $role,
            ':operator' => 'RVF10-' . strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser($pdo, 'coord', 'COORDINATOR', 'Coordinación T-RVF-10');
    $ownerId = $createUser($pdo, 'owner', 'RESTOCKER', 'Reposición Titular T-RVF-10');
    $otherId = $createUser($pdo, 'other', 'RESTOCKER', 'Reposición Ajena T-RVF-10');
    $technicianId = $createUser($pdo, 'sat', 'TECHNICIAN', 'Técnico SAT T-RVF-10');

    $zoneId = (int)$pdo->query(
        "INSERT INTO operational_zones (code, name, color_hex)
         VALUES ('ZONA-RVF10', 'Zona de certificación T-RVF-10', '#2560ff')
         RETURNING id"
    )->fetchColumn();

    $locationStmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, zone_id)
         VALUES (:site_code, :site_code_key, :name, :address, :zone_id)
         RETURNING id'
    );
    $locationStmt->execute([
        ':site_code' => 'SEDE-RVF10',
        ':site_code_key' => 'sede-rvf10',
        ':name' => 'Sede de certificación T-RVF-10',
        ':address' => 'Carrer de la Constitució 10, Barcelona',
        ':zone_id' => $zoneId,
    ]);
    $locationId = (int)$locationStmt->fetchColumn();

    $createMachine = static function (PDO $pdo, int $locationId, string $code, string $type): int {
        $stmt = $pdo->prepare(
            'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
             VALUES (:location_id, :code, :model, :machine_type, :floor_wing)
             RETURNING id'
        );
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => 'Modelo de certificación ' . $code,
            ':machine_type' => $type,
            ':floor_wing' => 'Planta baja',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $foodMachineId = $createMachine($pdo, $locationId, 'RVF10-FOOD', 'PERISHABLE_FOOD');
    $incidentMachineId = $createMachine($pdo, $locationId, 'RVF10-INCIDENT', 'COMBO');
    $coldMachineId = $createMachine($pdo, $locationId, 'RVF10-COLD', 'COLD_DRINKS');
    $fefoMachineId = $createMachine($pdo, $locationId, 'RVF10-FEFO', 'PERISHABLE_FOOD');
    $soonMachineId = $createMachine($pdo, $locationId, 'RVF10-SOON', 'PERISHABLE_FOOD');
    $yogurtMachineId = $createMachine($pdo, $locationId, 'RVF10-YOGURT', 'PERISHABLE_FOOD');
    $juiceMachineId = $createMachine($pdo, $locationId, 'RVF10-JUICE', 'COLD_DRINKS');
    $freshMachineId = $createMachine($pdo, $locationId, 'RVF10-FRESH', 'PERISHABLE_FOOD');

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

    $pdo->prepare(
        'INSERT INTO technician_zone_assignments (technician_id, zone_id, is_primary)
         VALUES (:technician_id, :zone_id, TRUE)'
    )->execute([':technician_id' => $technicianId, ':zone_id' => $zoneId]);
    $pdo->prepare(
        "INSERT INTO technician_shifts (technician_id, status)
         VALUES (:technician_id, 'ON_SHIFT')"
    )->execute([':technician_id' => $technicianId]);

    $createProduct = static function (
        PDO $pdo,
        string $sku,
        string $name,
        string $category,
        string $cost,
        bool $requiresColdChain
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO vending_products
                 (sku, name, category, unit_type, requires_cold_chain, max_shelf_life_days, unit_cost_eur, sale_price_eur)
             VALUES (:sku, :name, :category, 'UNIT', :requires_cold_chain, 5, :unit_cost, 2.40)
             RETURNING id"
        );
        $stmt->execute([
            ':sku' => $sku,
            ':name' => $name,
            ':category' => $category,
            ':unit_cost' => $cost,
            ':requires_cold_chain' => $requiresColdChain ? 'TRUE' : 'FALSE',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $sandwichId = $createProduct($pdo, 'RVF10-SANDWICH', 'Sándwich T-RVF-10', 'PERISHABLE_FOOD', '1.25', true);
    $coldDrinkId = $createProduct($pdo, 'RVF10-COLD-DRINK', 'Bebida fría T-RVF-10', 'COLD_DRINK', '0.85', false);
    $yogurtId = $createProduct($pdo, 'RVF10-YOGURT', 'Yogur T-RVF-10', 'PERISHABLE_FOOD', '0.70', true);
    $juiceId = $createProduct($pdo, 'RVF10-JUICE', 'Zumo T-RVF-10', 'COLD_DRINK', '1.60', false);

    $createSlot = static function (
        PDO $pdo,
        int $machineId,
        int $productId,
        int $qty,
        ?string $batchCode,
        ?string $expirationExpression
    ): int {
        $sql = 'INSERT INTO machine_planogram_slots
                    (machine_id, slot_code, product_id, max_capacity, current_estimated_qty, min_threshold,
                     current_batch_code, current_expiration_date)
                VALUES (:machine_id, \'11\', :product_id, 12, :qty, 2, :batch_code, ';
        $sql .= $expirationExpression === null ? 'NULL' : $expirationExpression;
        $sql .= ') RETURNING id';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':machine_id' => $machineId,
            ':product_id' => $productId,
            ':qty' => $qty,
            ':batch_code' => $batchCode,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $fefoSlotId = $createSlot($pdo, $fefoMachineId, $sandwichId, 3, 'MACH-OLD', 'CURRENT_DATE - 1');
    $soonSlotId = $createSlot($pdo, $soonMachineId, $sandwichId, 2, 'MACH-SOON', 'CURRENT_DATE + 2');
    $yogurtSlotId = $createSlot($pdo, $yogurtMachineId, $yogurtId, 0, null, null);
    $juiceSlotId = $createSlot($pdo, $juiceMachineId, $juiceId, 0, null, null);
    $freshSlotId = $createSlot($pdo, $freshMachineId, $sandwichId, 0, null, null);
    $createSlot($pdo, $coldMachineId, $coldDrinkId, 0, null, null);

    $createVanLot = static function (
        PDO $pdo,
        int $restockerId,
        int $productId,
        string $batchCode,
        string $expirationExpression,
        int $quantity
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO restocker_van_product_stocks
                 (restocker_id, product_id, batch_code, expiration_date, quantity_available)
             VALUES (:restocker_id, :product_id, :batch_code, {$expirationExpression}, :quantity)
             RETURNING id"
        );
        $stmt->execute([
            ':restocker_id' => $restockerId,
            ':product_id' => $productId,
            ':batch_code' => $batchCode,
            ':quantity' => $quantity,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createVanLot($pdo, $ownerId, $sandwichId, 'VAN-FRESH', 'CURRENT_DATE + 5', 10);
    $createVanLot($pdo, $ownerId, $yogurtId, 'YOG-EXPIRED', 'CURRENT_DATE - 1', 10);
    $createVanLot($pdo, $ownerId, $juiceId, 'JUICE-OLD', 'CURRENT_DATE + 2', 1);
    $createVanLot($pdo, $ownerId, $juiceId, 'JUICE-NEW', 'CURRENT_DATE + 5', 5);

    $hygiene = [
        'dispensing_tray_and_glass_cleaned' => true,
        'cabin_spills_checked' => true,
    ];

    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService(null, $userRepo);
    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $ownerToken = $authService->generateInternalToken($userRepo->findById($ownerId));
    $otherToken = $authService->generateInternalToken($userRepo->findById($otherId));
    $technicianToken = $authService->generateInternalToken($userRepo->findById($technicianId));

    $router = AppRouter::create();

    $call = static function (
        string $method,
        string $path,
        array $body = [],
        ?string $token = null,
        array $query = [],
        array $files = []
    ) use ($router): Response {
        $headers = ['Content-Type' => $files === [] ? 'application/json' : 'multipart/form-data'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $router->dispatch(new Request($method, $path, $query, $body, $headers, $files));
    };

    $dbRejects = static function (string $sql, array $params, string $needle) use ($pdo): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return [false, 'la sentencia fue aceptada'];
        } catch (PDOException $exception) {
            return [str_contains($exception->getMessage(), $needle), $exception->getMessage()];
        }
    };

    $visitPayload = static function (
        string $mutation,
        string $status,
        float $temp,
        array $items = [],
        ?string $photo = null,
        ?string $notes = null,
        bool $seal = false
    ) use ($hygiene): array {
        $payload = [
            'status' => $status,
            'cabin_temp_celsius' => $temp,
            'client_mutation_id' => $mutation,
            'hygiene_checklist_json' => $hygiene,
            'items' => $items,
            'precautionary_seal_applied' => $seal,
        ];

        if ($photo !== null) {
            $payload['photo_evidence_url'] = $photo;
        }
        if ($notes !== null) {
            $payload['internal_notes'] = $notes;
        }

        return $payload;
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. Art. V.5 · evidencia real y límite de 5 MB
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Art. V.5 · evidencia fotográfica ---\n";

    $pngPath = tempnam(sys_get_temp_dir(), 'rvf10_png_');
    $pngBinary = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true
    );
    file_put_contents($pngPath, $pngBinary);
    $tempFiles[] = $pngPath;

    $photo = $call('POST', '/api/restocker/visits/photo', [], $ownerToken, [], [
        'photo' => [
            'name' => 'evidence.png',
            'tmp_name' => $pngPath,
            'size' => filesize($pngPath),
            'error' => UPLOAD_ERR_OK,
            'type' => 'image/png',
        ],
    ]);
    $photoUrl = (string)($data($photo)['photo_evidence_url'] ?? '');
    if ($photoUrl !== '') {
        $uploadedFiles[] = $projectRoot . '/public' . $photoUrl;
    }
    $assert(
        '1.1 Una imagen PNG real se acepta y devuelve una URL de /uploads/',
        $photo->getStatusCode() === 201
            && str_starts_with($photoUrl, '/uploads/')
            && !in_array('unit_cost_snapshot_eur', $collectKeys($data($photo)), true),
        $errorCode($photo)
    );

    $invalidPath = tempnam(sys_get_temp_dir(), 'rvf10_txt_');
    file_put_contents($invalidPath, 'esto no es una imagen');
    $tempFiles[] = $invalidPath;
    $invalidPhoto = $call('POST', '/api/restocker/visits/photo', [], $ownerToken, [], [
        'photo' => [
            'name' => 'evidence.png',
            'tmp_name' => $invalidPath,
            'size' => filesize($invalidPath),
            'error' => UPLOAD_ERR_OK,
            'type' => 'image/png',
        ],
    ]);
    $assert(
        '1.2 Un archivo con extensión de imagen pero sin magic bytes responde 422 INVALID_FILE_TYPE',
        $invalidPhoto->getStatusCode() === 422 && $errorCode($invalidPhoto) === 'INVALID_FILE_TYPE',
        $errorCode($invalidPhoto)
    );

    $oversizePath = tempnam(sys_get_temp_dir(), 'rvf10_big_');
    file_put_contents($oversizePath, str_repeat('A', 5 * 1024 * 1024 + 1));
    $tempFiles[] = $oversizePath;
    $oversizePhoto = $call('POST', '/api/restocker/visits/photo', [], $ownerToken, [], [
        'photo' => [
            'name' => 'evidence.png',
            'tmp_name' => $oversizePath,
            'size' => filesize($oversizePath),
            'error' => UPLOAD_ERR_OK,
            'type' => 'image/png',
        ],
    ]);
    $assert(
        '1.3 Un archivo de más de 5 MB responde 422 FILE_TOO_LARGE',
        $oversizePhoto->getStatusCode() === 422 && $errorCode($oversizePhoto) === 'FILE_TOO_LARGE',
        $errorCode($oversizePhoto)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Art. V.4 · RBAC y opacidad
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Art. V.4 · RBAC y opacidad de costes ---\n";

    $anonymous = $call('GET', '/api/restocker/visits');
    $coordinatorOnMobile = $call('GET', '/api/restocker/visits', [], $coordinatorToken);
    $technicianOnHistory = $call('GET', '/api/restocker/visits', [], $technicianToken);
    $restockerOnCoordinator = $call('GET', '/api/coordinator/restock-visits', [], $ownerToken);
    $technicianOnCoordinator = $call('GET', '/api/coordinator/restock-visits', [], $technicianToken);
    $assert(
        '2.1 Sin sesión el historial móvil responde 401 UNAUTHORIZED',
        $anonymous->getStatusCode() === 401 && $errorCode($anonymous) === 'UNAUTHORIZED'
    );
    $assert(
        '2.2 Coordinación no entra en la superficie móvil (403 FORBIDDEN)',
        $coordinatorOnMobile->getStatusCode() === 403 && $errorCode($coordinatorOnMobile) === 'FORBIDDEN'
    );
    $assert(
        '2.3 El técnico SAT no registra ni consulta visitas (403 FORBIDDEN)',
        $technicianOnHistory->getStatusCode() === 403
            && $errorCode($technicianOnHistory) === 'FORBIDDEN'
            && $technicianOnCoordinator->getStatusCode() === 403
    );
    $assert(
        '2.4 El reponedor no entra en el libro de Coordinación (403 FORBIDDEN)',
        $restockerOnCoordinator->getStatusCode() === 403
            && $errorCode($restockerOnCoordinator) === 'FORBIDDEN'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Art. II / QA 2 · aborto térmico, cuarentena y ticket idempotente
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Art. II / QA 2 · aborto térmico y ticket SAT ---\n";

    $abortNotes = 'Rotura de cadena de frío confirmada al abrir la cabina.';
    $noPhoto = $call(
        'POST',
        '/api/restocker/machines/' . $foodMachineId . '/visits',
        $visitPayload('rvf10-abort-nophoto', 'ABORTED_THERMAL_BREACH', 6.5, [], null, $abortNotes, true),
        $ownerToken
    );
    $shortNotes = $call(
        'POST',
        '/api/restocker/machines/' . $foodMachineId . '/visits',
        $visitPayload('rvf10-abort-short', 'ABORTED_THERMAL_BREACH', 6.5, [], $photoUrl, 'Fallo de frío.', true),
        $ownerToken
    );
    $abortWithMovement = $call(
        'POST',
        '/api/restocker/machines/' . $foodMachineId . '/visits',
        $visitPayload(
            'rvf10-abort-move',
            'ABORTED_THERMAL_BREACH',
            3.5,
            [['slot_code' => '11', 'qty_added' => 1, 'loaded_batch_code' => 'VAN-FRESH']],
            $photoUrl,
            $abortNotes,
            true
        ),
        $ownerToken
    );
    $assert(
        '3.1 El aborto sin foto se rechaza con 422 PHOTO_EVIDENCE_REQUIRED',
        $noPhoto->getStatusCode() === 422 && $errorCode($noPhoto) === 'PHOTO_EVIDENCE_REQUIRED',
        $errorCode($noPhoto)
    );
    $assert(
        '3.2 Una nota interna de menos de 20 caracteres se rechaza (Art. V.1)',
        $shortNotes->getStatusCode() === 422 && $errorCode($shortNotes) === 'INVALID_JUSTIFICATION_LENGTH',
        $errorCode($shortNotes)
    );
    $assert(
        '3.3 Un aborto térmico no admite movimientos de carril',
        $abortWithMovement->getStatusCode() === 422
            && $errorCode($abortWithMovement) === 'SLOT_CAPACITY_OR_WITHDRAWAL_INVALID',
        $errorCode($abortWithMovement)
    );

    $abortPayload = $visitPayload(
        'rvf10-abort-0001',
        'ABORTED_THERMAL_BREACH',
        6.5,
        [],
        $photoUrl,
        $abortNotes,
        true
    );
    $abort = $call('POST', '/api/restocker/machines/' . $foodMachineId . '/visits', $abortPayload, $ownerToken);
    $abortData = $data($abort);
    $abortVisitId = (int)($abortData['id'] ?? 0);
    $triggeredIncidentId = (int)($abortData['triggered_incident_id'] ?? 0);
    $assert(
        '3.4 El aborto válido responde 201, sin costes y con ticket SAT vinculado',
        $abort->getStatusCode() === 201
            && $abortVisitId > 0
            && $triggeredIncidentId > 0
            && ($abortData['status'] ?? '') === 'ABORTED_THERMAL_BREACH'
            && !in_array('unit_cost_snapshot_eur', $collectKeys($abortData), true)
            && !in_array('commercial_spoilage_loss_eur', $collectKeys($abortData), true),
        $errorCode($abort)
    );

    $foodMachine = $pdo->query(
        'SELECT sanitary_status FROM machines WHERE id = ' . $foodMachineId
    )->fetch(PDO::FETCH_ASSOC);
    $incident = $pdo->query(
        'SELECT ticket_code, category, urgency, status, assigned_technician_id, assigned_at, photo_path, description
           FROM incidents WHERE id = ' . $triggeredIncidentId
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        '3.5 La máquina queda en cuarentena y el ticket CRITICAL se asigna al SAT disponible',
        ($foodMachine['sanitary_status'] ?? '') === 'QUARANTINE'
            && ($incident['urgency'] ?? '') === 'CRITICAL'
            && ($incident['category'] ?? '') === 'TEMPERATURE_COLD'
            && ($incident['status'] ?? '') === 'ASSIGNED'
            && (int)($incident['assigned_technician_id'] ?? 0) === $technicianId
            && ($incident['assigned_at'] ?? null) !== null,
        (string)($incident['ticket_code'] ?? 'sin ticket')
    );

    $custody = $pdo->query(
        'SELECT technician_id, assignment_source, unassigned_at
           FROM incident_assignments WHERE incident_id = ' . $triggeredIncidentId
    )->fetchAll(PDO::FETCH_ASSOC);
    $assert(
        '3.5b La asignación deja un único tramo de custodia abierto con la fuente existente',
        count($custody) === 1
            && (int)($custody[0]['technician_id'] ?? 0) === $technicianId
            && ($custody[0]['assignment_source'] ?? '') === 'COORDINATOR_ASSIGN'
            && ($custody[0]['unassigned_at'] ?? null) === null,
        'tramos=' . count($custody)
    );

    $assert(
        '3.6 El ticket nuevo conserva la temperatura y la foto de la visita',
        ($incident['photo_path'] ?? '') === $photoUrl
            && str_contains((string)($incident['description'] ?? ''), '6.5 °C'),
        (string)($incident['ticket_code'] ?? '')
    );

    $coordinatorEmail = (string)$pdo->query(
        'SELECT email FROM users WHERE id = ' . $coordinatorId
    )->fetchColumn();
    $technicianEmail = (string)$pdo->query(
        'SELECT email FROM users WHERE id = ' . $technicianId
    )->fetchColumn();
    $newTicketOutbox = $pdo->query(
        "SELECT template, recipient, recipient_role, dedup_key, status
           FROM notification_outbox
          WHERE incident_id = " . $triggeredIncidentId
    )->fetchAll(PDO::FETCH_ASSOC);
    $coldAlertRows = array_values(array_filter(
        $newTicketOutbox,
        static fn(array $row): bool => ($row['template'] ?? '') === 'COLD_CHAIN_IMMEDIATE_ALERT'
    ));
    $coldAlertRecipients = array_map(
        static fn(array $row): string => strtolower((string)$row['recipient']),
        $coldAlertRows
    );
    $coldAlertsComplete = $coldAlertRows !== [];
    foreach ($coldAlertRows as $row) {
        $coldAlertsComplete = $coldAlertsComplete
            && trim((string)($row['dedup_key'] ?? '')) !== ''
            && ($row['status'] ?? '') === 'PENDING';
    }
    $newTicketOutboxCount = count($newTicketOutbox);
    $assert(
        '3.6b El ticket nuevo encola la alerta de frío para Coordinación y el técnico asignado',
        $newTicketOutboxCount > 0
            && $coldAlertsComplete
            && in_array(strtolower($coordinatorEmail), $coldAlertRecipients, true)
            && in_array(strtolower($technicianEmail), $coldAlertRecipients, true),
        'outbox=' . $newTicketOutboxCount
    );

    $repeatedAbort = $call('POST', '/api/restocker/machines/' . $foodMachineId . '/visits', $abortPayload, $ownerToken);
    $abortTicketCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM incidents WHERE machine_id = ' . $foodMachineId
    )->fetchColumn();
    $repeatedOutboxCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM notification_outbox WHERE incident_id = ' . $triggeredIncidentId
    )->fetchColumn();
    $assignmentCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM incident_assignments WHERE incident_id = ' . $triggeredIncidentId
    )->fetchColumn();
    $assert(
        '3.7 El reenvío idempotente no duplica la visita, el ticket SAT, la asignación ni la alerta encolada',
        $repeatedAbort->getStatusCode() === 201
            && (int)($data($repeatedAbort)['id'] ?? 0) === $abortVisitId
            && $abortTicketCount === 1
            && $assignmentCount === 1
            && $repeatedOutboxCount === $newTicketOutboxCount
    );

    $blockedAfterAbort = $call(
        'POST',
        '/api/restocker/machines/' . $foodMachineId . '/visits',
        $visitPayload('rvf10-after-abort', 'COMPLETED', 3.0, [], null, null),
        $ownerToken
    );
    $assert(
        '3.8 Una máquina en cuarentena responde 409 SANITARY_RESTOCK_BLOCKED',
        $blockedAfterAbort->getStatusCode() === 409
            && $errorCode($blockedAfterAbort) === 'SANITARY_RESTOCK_BLOCKED',
        $errorCode($blockedAfterAbort)
    );

    $existingTicketId = (int)$pdo->query(
        "INSERT INTO incidents (ticket_code, machine_id, location_id, category, description, urgency, status)
         VALUES ('TCK-RVF10-EXIST', " . $incidentMachineId . ', ' . $locationId . ",
                 'PRODUCT_JAM', 'Atasco de producto previo a la visita in situ.', 'LOW', 'REGISTERED')
         RETURNING id"
    )->fetchColumn();
    $escalatedAbort = $call(
        'POST',
        '/api/restocker/machines/' . $incidentMachineId . '/visits',
        $visitPayload(
            'rvf10-abort-0002',
            'ABORTED_THERMAL_BREACH',
            6.8,
            [],
            $photoUrl,
            $abortNotes,
            true
        ),
        $ownerToken
    );
    $escalatedData = $data($escalatedAbort);
    $escalatedIncident = $pdo->query(
        'SELECT urgency, status FROM incidents WHERE id = ' . $existingTicketId
    )->fetch(PDO::FETCH_ASSOC);
    $machineTicketCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM incidents WHERE machine_id = ' . $incidentMachineId
    )->fetchColumn();
    $escalatedComment = $pdo->query(
        'SELECT is_internal, photo_path FROM incident_comments
          WHERE incident_id = ' . $existingTicketId . ' ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        '3.9 Un ticket activo preexistente se eleva a CRITICAL sin duplicarse (QA 2)',
        $escalatedAbort->getStatusCode() === 201
            && (int)($escalatedData['triggered_incident_id'] ?? 0) === $existingTicketId
            && ($escalatedIncident['urgency'] ?? '') === 'CRITICAL'
            && $machineTicketCount === 1
            && ($escalatedComment['is_internal'] ?? false) === true
            && ($escalatedComment['photo_path'] ?? '') === $photoUrl,
        $errorCode($escalatedAbort)
    );
    $escalatedOutbox = $pdo->query(
        "SELECT template, recipient_role, dedup_key FROM notification_outbox
          WHERE incident_id = " . $existingTicketId
    )->fetchAll(PDO::FETCH_ASSOC);
    $escalatedColdRows = array_values(array_filter(
        $escalatedOutbox,
        static fn(array $row): bool => ($row['template'] ?? '') === 'COLD_CHAIN_IMMEDIATE_ALERT'
    ));
    $assert(
        '3.9b La elevación de un ticket existente vuelve a encolar la alerta de frío',
        $escalatedColdRows !== []
            && count(array_filter(
                $escalatedColdRows,
                static fn(array $row): bool => trim((string)($row['dedup_key'] ?? '')) !== ''
            )) === count($escalatedColdRows),
        'outbox=' . count($escalatedOutbox)
    );

    $coldOk = $call(
        'POST',
        '/api/restocker/machines/' . $coldMachineId . '/visits',
        $visitPayload('rvf10-cold-ok', 'COMPLETED', 7.0, [], null, null),
        $ownerToken
    );
    $coldTooWarm = $call(
        'POST',
        '/api/restocker/machines/' . $coldMachineId . '/visits',
        $visitPayload('rvf10-cold-hot', 'COMPLETED', 8.5, [], null, null),
        $ownerToken
    );
    $assert(
        '3.10 El techo de bebidas frías es 8,0 °C: 7,0 °C pasa y 8,5 °C se rechaza',
        $coldOk->getStatusCode() === 201
            && $coldTooWarm->getStatusCode() === 422
            && $errorCode($coldTooWarm) === 'CABIN_TEMP_EXCEEDS_SANITARY_LIMIT',
        $errorCode($coldTooWarm)
    );

    $pdo->prepare(
        'UPDATE technician_shifts
            SET closed_at = CURRENT_TIMESTAMP
          WHERE technician_id = :technician_id AND closed_at IS NULL'
    )->execute([':technician_id' => $technicianId]);
    $noTechAbort = $call(
        'POST',
        '/api/restocker/machines/' . $coldMachineId . '/visits',
        $visitPayload('rvf10-no-tech', 'ABORTED_THERMAL_BREACH', 8.5, [], $photoUrl, $abortNotes, true),
        $ownerToken
    );
    $noTechData = $data($noTechAbort);
    $noTechIncidentId = (int)($noTechData['triggered_incident_id'] ?? 0);
    $noTechIncident = $pdo->query(
        'SELECT status, assigned_technician_id FROM incidents WHERE id = ' . $noTechIncidentId
    )->fetch(PDO::FETCH_ASSOC);
    $noTechOutboxCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM notification_outbox WHERE incident_id = ' . $noTechIncidentId
    )->fetchColumn();
    $assert(
        '3.11 Sin técnico disponible el ticket queda REGISTERED, pero la alerta se encola igualmente',
        $noTechAbort->getStatusCode() === 201
            && $noTechIncidentId > 0
            && ($noTechIncident['status'] ?? '') === 'REGISTERED'
            && ($noTechIncident['assigned_technician_id'] ?? null) === null
            && $noTechOutboxCount > 0,
        $errorCode($noTechAbort)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Art. II / QA 1 · doble regla FEFO
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Art. II / QA 1 · doble FEFO en carril y furgoneta ---\n";

    $expiredSlotAttempt = $call(
        'POST',
        '/api/restocker/machines/' . $fefoMachineId . '/visits',
        $visitPayload(
            'rvf10-fefo-expired',
            'COMPLETED',
            3.0,
            [['slot_code' => '11', 'qty_added' => 1, 'loaded_batch_code' => 'VAN-FRESH']]
        ),
        $ownerToken
    );
    $assert(
        '4.1 Cargar sobre un carril caducado sin retirar todo el remanente responde 422 FEFO_WITHDRAWAL_REQUIRED',
        $expiredSlotAttempt->getStatusCode() === 422
            && $errorCode($expiredSlotAttempt) === 'FEFO_WITHDRAWAL_REQUIRED',
        $errorCode($expiredSlotAttempt)
    );

    $completedFefo = $call(
        'POST',
        '/api/restocker/machines/' . $fefoMachineId . '/visits',
        $visitPayload(
            'rvf10-fefo-valid',
            'COMPLETED',
            3.0,
            [[
                'slot_code' => '11',
                'qty_withdrawn_expired' => 3,
                'qty_added' => 1,
                'loaded_batch_code' => 'VAN-FRESH',
            ]],
            $photoUrl,
            $abortNotes
        ),
        $ownerToken
    );
    $completedFefoData = $data($completedFefo);
    $completedFefoId = (int)($completedFefoData['id'] ?? 0);
    $fefoSlotAfter = $pdo->query(
        'SELECT current_estimated_qty, current_batch_code, current_expiration_date
           FROM machine_planogram_slots WHERE id = ' . $fefoSlotId
    )->fetch(PDO::FETCH_ASSOC);
    $vanFreshAfter = $pdo->query(
        "SELECT quantity_available FROM restocker_van_product_stocks
          WHERE restocker_id = " . $ownerId . " AND product_id = " . $sandwichId . " AND batch_code = 'VAN-FRESH'"
    )->fetchColumn();
    $fefoItem = $pdo->query(
        'SELECT qty_before, qty_withdrawn_expired, qty_added, qty_after,
                unit_cost_snapshot_eur, commercial_spoilage_loss_eur
           FROM machine_restock_visit_items WHERE visit_id = ' . $completedFefoId
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        '4.2 La retirada total del lote caducado permite cargar y descuenta la furgoneta',
        $completedFefo->getStatusCode() === 201
            && (int)($fefoSlotAfter['current_estimated_qty'] ?? -1) === 1
            && ($fefoSlotAfter['current_batch_code'] ?? '') === 'VAN-FRESH'
            && (int)$vanFreshAfter === 9
            && !in_array('unit_cost_snapshot_eur', $collectKeys($completedFefoData), true),
        $errorCode($completedFefo)
    );
    $assert(
        '4.3 El snapshot contable del carril se congela con el coste del producto (QA 3)',
        (int)($fefoItem['qty_before'] ?? -1) === 3
            && (int)($fefoItem['qty_withdrawn_expired'] ?? -1) === 3
            && (int)($fefoItem['qty_added'] ?? -1) === 1
            && (int)($fefoItem['qty_after'] ?? -1) === 1
            && (float)($fefoItem['unit_cost_snapshot_eur'] ?? -1) === 1.25
            && (float)($fefoItem['commercial_spoilage_loss_eur'] ?? -1) === 3.75
    );

    $soonAttempt = $call(
        'POST',
        '/api/restocker/machines/' . $soonMachineId . '/visits',
        $visitPayload(
            'rvf10-fefo-soon',
            'COMPLETED',
            3.0,
            [['slot_code' => '11', 'qty_added' => 1, 'loaded_batch_code' => 'VAN-FRESH']]
        ),
        $ownerToken
    );
    $assert(
        '4.4 Un lote que caduca en 48 h bloquea la carga sin retirada total',
        $soonAttempt->getStatusCode() === 422
            && $errorCode($soonAttempt) === 'FEFO_WITHDRAWAL_REQUIRED',
        $errorCode($soonAttempt)
    );

    $expiredVanAttempt = $call(
        'POST',
        '/api/restocker/machines/' . $yogurtMachineId . '/visits',
        $visitPayload(
            'rvf10-van-expired',
            'COMPLETED',
            3.0,
            [['slot_code' => '11', 'qty_added' => 1, 'loaded_batch_code' => 'YOG-EXPIRED']]
        ),
        $ownerToken
    );
    $assert(
        '4.5 Un lote caducado de la furgoneta responde 422 EXPIRED_VAN_BATCH_FORBIDDEN',
        $expiredVanAttempt->getStatusCode() === 422
            && $errorCode($expiredVanAttempt) === 'EXPIRED_VAN_BATCH_FORBIDDEN',
        $errorCode($expiredVanAttempt)
    );

    $olderVanAttempt = $call(
        'POST',
        '/api/restocker/machines/' . $juiceMachineId . '/visits',
        $visitPayload(
            'rvf10-van-older',
            'COMPLETED',
            3.0,
            [['slot_code' => '11', 'qty_added' => 1, 'loaded_batch_code' => 'JUICE-NEW']]
        ),
        $ownerToken
    );
    $assert(
        '4.6 Un lote más antiguo con saldo obliga a agotarlo primero (422 FEFO_OLDER_VAN_BATCH_AVAILABLE)',
        $olderVanAttempt->getStatusCode() === 422
            && $errorCode($olderVanAttempt) === 'FEFO_OLDER_VAN_BATCH_AVAILABLE',
        $errorCode($olderVanAttempt)
    );

    $insufficientAttempt = $call(
        'POST',
        '/api/restocker/machines/' . $freshMachineId . '/visits',
        $visitPayload(
            'rvf10-van-insufficient',
            'COMPLETED',
            3.0,
            [['slot_code' => '11', 'qty_added' => 12, 'loaded_batch_code' => 'VAN-FRESH']]
        ),
        $ownerToken
    );
    $assert(
        '4.7 Cargar más unidades de las disponibles responde 422 INSUFFICIENT_VAN_BATCH_STOCK',
        $insufficientAttempt->getStatusCode() === 422
            && $errorCode($insufficientAttempt) === 'INSUFFICIENT_VAN_BATCH_STOCK',
        $errorCode($insufficientAttempt)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Art. V.4 · opacidad en móvil, técnico y QR
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Art. V.4 · superficie pública sin costes ni notas ---\n";

    $restockerHistory = $call('GET', '/api/restocker/visits', [], $ownerToken);
    $restockerHistoryRaw = (string)$restockerHistory->getBody();
    $myRoute = $call('GET', '/api/restocker/my-route', [], $ownerToken);
    $myRouteRaw = (string)$myRoute->getBody();
    $assert(
        '5.1 El historial móvil y el bootstrap no publican claves de coste',
        $restockerHistory->getStatusCode() === 200
            && $myRoute->getStatusCode() === 200
            && !str_contains($restockerHistoryRaw, 'unit_cost_snapshot_eur')
            && !str_contains($restockerHistoryRaw, 'commercial_spoilage_loss_eur')
            && !str_contains($myRouteRaw, 'unit_cost_snapshot_eur')
            && !str_contains($myRouteRaw, 'commercial_spoilage_loss_eur')
    );

    $coordinatorList = $call('GET', '/api/coordinator/restock-visits', [], $coordinatorToken, [
        'restocker_id' => $ownerId,
        'machine_id' => $fefoMachineId,
    ]);
    $coordinatorListData = $data($coordinatorList);
    $coordinatorVisitRow = $coordinatorListData['visits'][0] ?? [];
    $assert(
        '5.2 Coordinación sí recibe la merma comercial agregada de la visita',
        $coordinatorList->getStatusCode() === 200
            && (float)($coordinatorVisitRow['commercial_spoilage_loss_eur'] ?? -1) === 3.75
            && (int)($coordinatorVisitRow['units_withdrawn'] ?? -1) === 3
    );

    $coordinatorDetail = $call(
        'GET',
        '/api/coordinator/restock-visits/' . $completedFefoId,
        [],
        $coordinatorToken
    );
    $coordinatorItems = $data($coordinatorDetail)['items'] ?? [];
    $assert(
        '5.3 El detalle de Coordinación publica el snapshot contable por carril',
        $coordinatorDetail->getStatusCode() === 200
            && (float)($coordinatorItems[0]['unit_cost_snapshot_eur'] ?? -1) === 1.25
            && (float)($coordinatorItems[0]['commercial_spoilage_loss_eur'] ?? -1) === 3.75
    );

    $qr = $call('GET', '/api/qr/scan/RVF10-FOOD');
    $qrRaw = (string)$qr->getBody();
    $assert(
        '5.4 El canal QR público no publica costes, notas internas ni la visita',
        $qr->getStatusCode() === 200
            && !str_contains($qrRaw, 'unit_cost_snapshot_eur')
            && !str_contains($qrRaw, 'commercial_spoilage_loss_eur')
            && !str_contains($qrRaw, 'internal_notes')
            && !str_contains($qrRaw, 'machine_restock_visits'),
        $errorCode($qr)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Art. III.1 / III.3 · inmutabilidad y auditoría
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Art. III.1 / III.3 · inmutabilidad y rastro auditable ---\n";

    [$visitUpdated, $visitUpdateDetail] = $dbRejects(
        'UPDATE machine_restock_visits SET internal_notes = internal_notes WHERE id = :id',
        [':id' => $completedFefoId],
        'ART_III_3_IMMUTABLE_VISIT_VIOLATION'
    );
    $assert('6.1 El motor rechaza cualquier UPDATE de la cabecera de visita', $visitUpdated, $visitUpdateDetail);

    $fefoItemId = (int)$pdo->query(
        'SELECT id FROM machine_restock_visit_items WHERE visit_id = ' . $completedFefoId
    )->fetchColumn();
    [$itemUpdated, $itemUpdateDetail] = $dbRejects(
        'UPDATE machine_restock_visit_items SET qty_added = qty_added WHERE id = :id',
        [':id' => $fefoItemId],
        'ART_III_3_IMMUTABLE_VISIT_VIOLATION'
    );
    $assert('6.2 El motor rechaza cualquier UPDATE del desglose de la visita', $itemUpdated, $itemUpdateDetail);

    [$visitDeleted, $visitDeleteDetail] = $dbRejects(
        'DELETE FROM machine_restock_visits WHERE id = :id',
        [':id' => $completedFefoId],
        'ART_III_1_VIOLATION'
    );
    $assert('6.3 El motor rechaza el DELETE físico de la cabecera', $visitDeleted, $visitDeleteDetail);

    [$itemDeleted, $itemDeleteDetail] = $dbRejects(
        'DELETE FROM machine_restock_visit_items WHERE id = :id',
        [':id' => $fefoItemId],
        'ART_III_1_VIOLATION'
    );
    $assert('6.4 El motor rechaza el DELETE físico del desglose', $itemDeleted, $itemDeleteDetail);

    $survivingVisit = (int)$pdo->query(
        'SELECT COUNT(*) FROM machine_restock_visits WHERE id = ' . $completedFefoId
    )->fetchColumn();
    $survivingItem = (int)$pdo->query(
        'SELECT COUNT(*) FROM machine_restock_visit_items WHERE id = ' . $fefoItemId
    )->fetchColumn();
    $assert(
        '6.5 La visita y su línea sobreviven intactas a los intentos de borrado',
        $survivingVisit === 1 && $survivingItem === 1
    );

    $completedAudit = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log
          WHERE entity_type = 'MACHINE_RESTOCK_VISIT'
            AND entity_id = " . $completedFefoId . "
            AND action = 'RESTOCK_VISIT_COMPLETED'
            AND user_id = " . $ownerId . "
            AND user_role = 'RESTOCKER'"
    )->fetchColumn();
    $abortAudit = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log
          WHERE entity_type = 'MACHINE_RESTOCK_VISIT'
            AND entity_id = " . $abortVisitId . "
            AND action = 'RESTOCK_VISIT_ABORTED_THERMAL_BREACH'"
    )->fetchColumn();
    $auditPayload = (string)$pdo->query(
        "SELECT string_agg(new_state::text, ' ') FROM audit_log
          WHERE entity_type = 'MACHINE_RESTOCK_VISIT'"
    )->fetchColumn();
    $assert(
        '6.6 Cada visita deja su evento auditable con actor y sin costes (Art. III.3)',
        $completedAudit === 1
            && $abortAudit === 1
            && !str_contains($auditPayload, 'unit_cost_snapshot_eur')
            && !str_contains($auditPayload, 'commercial_spoilage_loss_eur')
    );

    $auditDelete = $dbRejects(
        "DELETE FROM audit_log WHERE entity_type = 'MACHINE_RESTOCK_VISIT'",
        [],
        'ART_III_1_VIOLATION'
    );
    $assert('6.7 La auditoría tampoco admite borrado físico', $auditDelete[0], $auditDelete[1]);

    // ─────────────────────────────────────────────────────────────────────
    // 7. C3 · conflicto offline auditado sin tocar inventario
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 7. C3 · volcado de conflicto offline ---\n";

    $visitsBeforeConflict = (int)$pdo->query('SELECT COUNT(*) FROM machine_restock_visits')->fetchColumn();
    $conflict = $call('POST', '/api/restocker/visits/conflict-dump', [
        'machine_id' => $freshMachineId,
        'client_mutation_id' => 'rvf10-offline-0001',
        'reason' => 'La máquina entró en cuarentena antes de recuperar cobertura.',
    ], $ownerToken);
    $conflictData = $data($conflict);
    $visitsAfterConflict = (int)$pdo->query('SELECT COUNT(*) FROM machine_restock_visits')->fetchColumn();
    $conflictAudit = $pdo->query(
        "SELECT user_id, user_role, new_state::text AS payload
           FROM audit_log
          WHERE entity_type = 'MACHINE_RESTOCK_VISIT'
            AND entity_id = " . $freshMachineId . "
            AND action = 'OFFLINE_RESTOCK_VISIT_CONFLICT_DUMP'
          ORDER BY id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        '7.1 El conflicto offline se archiva en auditoría sin crear una visita',
        $conflict->getStatusCode() === 200
            && ($conflictData['status'] ?? '') === 'ARCHIVED'
            && $visitsBeforeConflict === $visitsAfterConflict
            && (int)($conflictAudit['user_id'] ?? 0) === $ownerId
            && ($conflictAudit['user_role'] ?? '') === 'RESTOCKER'
            && str_contains((string)($conflictAudit['payload'] ?? ''), 'rvf10-offline-0001')
            && !str_contains((string)($conflictAudit['payload'] ?? ''), 'unit_cost_snapshot_eur')
    );

    // ─────────────────────────────────────────────────────────────────────
    // 8. Defensa en profundidad del motor (Art. II)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 8. Defensa en profundidad del motor ---\n";

    $dbThermal = $dbRejects(
        "INSERT INTO machine_restock_visits
             (visit_code, machine_id, restocker_id, status, cabin_temp_celsius,
              hygiene_checklist_json, client_mutation_id)
         VALUES ('MRV-2026-91001', :machine_id, :restocker_id, 'COMPLETED', 9.0,
                 '{}'::jsonb, 'rvf10-db-thermal')",
        [':machine_id' => $coldMachineId, ':restocker_id' => $ownerId],
        'ART_II_CABIN_TEMP_EXCEEDED'
    );
    $assert(
        '8.1 Una consola SQL no puede saltarse el techo térmico de 8,0 °C',
        $dbThermal[0],
        $dbThermal[1]
    );

    $outsideZone = $call(
        'POST',
        '/api/restocker/machines/' . $freshMachineId . '/visits',
        $visitPayload('rvf10-outside', 'COMPLETED', 3.0, [], null, null),
        $otherToken
    );
    $assert(
        '8.2 Un reponedor fuera de las zonas activas recibe 403 MACHINE_OUTSIDE_RESTOCKER_ZONES',
        $outsideZone->getStatusCode() === 403
            && $errorCode($outsideZone) === 'MACHINE_OUTSIDE_RESTOCKER_ZONES',
        $errorCode($outsideZone)
    );

    // ─────────────────────────────────────────────────────────────────────
    // RESUMEN
    // ─────────────────────────────────────────────────────────────────────
    echo "\n======================================================================\n";
    echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

    if ($failures === 0) {
        echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-RVF-10 (blindaje constitucional) CUMPLIDA.\n";
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
    foreach (array_merge($uploadedFiles, $tempFiles) as $tempFile) {
        if (is_string($tempFile) && is_file($tempFile)) {
            @unlink($tempFile);
        }
    }
    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, RVF10_CONST_SCHEMA);
        } catch (Throwable $cleanupException) {
            fwrite(STDERR, "[LIMPIEZA] No se pudo retirar el esquema aislado: " . $cleanupException->getMessage() . "\n");
        }
    }
}

exit($exitCode);
