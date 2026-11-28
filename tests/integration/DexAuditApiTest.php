<?php

declare(strict_types=1);

/**
 * DexAuditApiTest · T-DEX-09
 *
 * Certifica la superficie HTTP del Módulo 39 sobre el router real, el middleware
 * de rol, los controladores, el servicio y los repositorios PDO:
 *
 *   1. RBAC de las tres puertas: sin token 401; cada rol solo entra en la suya.
 *   2. Importación `.dex` del Reponedor (201, prefill inmediato) y listado
 *      histórico sin ningún importe en euros (`QA-1`, Art. V.4).
 *   3. Carga manual de Coordinación, listado con KPIs y detalle completo.
 *   4. `/raw` con `text/plain`, `nosniff` y `DEX-AAAA-NNNN.dex`.
 *   5. Anulación lógica con motivo real y `export-csv` UTF-8 BOM.
 *   6. Volcado de conflicto Offline auditado.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL y trabaja en un esquema aislado; sin
 * motor responde código 2, nunca un verde silencioso. El esquema se retira con
 * `CASCADE` en el `finally`.
 *
 * Uso:
 *   php tests/integration/DexAuditApiTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Application\Service\DexAuditLinker;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineDexAuditRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Controller\RestockerRouteController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

const VENDGUARD_TEST_CATEGORY = 'new_engine';
const DEX09_API_SCHEMA = 't_dex09_api';

/** Espía del `AuditLogger` inyectado en la puerta del Reponedor (PR25-06). */
final class Dex09SpyAuditLogger extends AuditLogger
{
    /** @var list<array<string, mixed>> */
    public array $machineEvents = [];

    public function __construct()
    {
    }

    public function logMachineEvent(
        int $machineId,
        string $action,
        array $user,
        ?array $previousState,
        array $newState,
        ?array $metadata = null
    ): AuditEvent {
        $this->machineEvents[] = [
            'machine_id' => $machineId,
            'action' => strtoupper(trim($action)),
            'user' => $user,
            'new_state' => $newState,
            'metadata' => $metadata,
        ];

        return new AuditEvent(
            null,
            AuditEvent::ENTITY_MACHINE,
            $machineId,
            $action,
            isset($user['id']) ? (int)$user['id'] : null,
            (string)($user['role'] ?? 'RESTOCKER'),
            (string)($user['name'] ?? 'Reposición'),
            $previousState,
            $newState,
            $metadata
        );
    }
}

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
echo " VENDGUARD · T-DEX-09 · API REST de auditorías EVA-DTS (.dex)\n";
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
    PostgresFixtureBuilder::createIsolationSchema($pdo, DEX09_API_SCHEMA);
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
            ':email' => 'dex09.' . $suffix . '@vendguard.internal',
            ':email_key' => 'dex09.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('dex09-' . $suffix, PASSWORD_BCRYPT, ['cost' => 4]),
            ':role' => $role,
            ':operator' => 'DX9-' . strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR', 'Coordinación DEX 09');
    $restockerId = $createUser('repo', 'RESTOCKER', 'Reposición DEX 09');
    $technicianId = $createUser('sat', 'TECHNICIAN', 'Técnico DEX 09');

    $zoneId = (int)$pdo->query(
        "INSERT INTO operational_zones (code, name, color_hex)
         VALUES ('ZONA-DEX9', 'Zona DEX 09', '#2560ff') RETURNING id"
    )->fetchColumn();
    $locationStmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, zone_id)
         VALUES (:site_code, :site_code_key, :name, :address, :zone_id) RETURNING id'
    );
    $locationStmt->execute([
        ':site_code' => 'SEDE-DEX9',
        ':site_code_key' => 'sede-dex9',
        ':name' => 'Sede DEX 09',
        ':address' => 'Carrer DEX 9, Barcelona',
        ':zone_id' => $zoneId,
    ]);
    $locationId = (int)$locationStmt->fetchColumn();
    $machineStmt = $pdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, :code, 'Modelo DEX 9', 'PERISHABLE_FOOD', 'Planta baja') RETURNING id"
    );
    $machineStmt->execute([':location_id' => $locationId, ':code' => 'DEX9-FOOD-01']);
    $machineId = (int)$machineStmt->fetchColumn();
    $machineStmt->execute([':location_id' => $locationId, ':code' => 'DEX9-FOOD-02']);
    $secondMachineId = (int)$machineStmt->fetchColumn();
    $machineStmt->execute([':location_id' => $locationId, ':code' => 'DEX9-FOOD-03']);
    $identityMachineId = (int)$machineStmt->fetchColumn();
    $machineStmt->execute([':location_id' => $locationId, ':code' => 'DEX9-FOOD-04']);
    $fourthMachineId = (int)$machineStmt->fetchColumn();
    $machineStmt->execute([':location_id' => $locationId, ':code' => 'DEX9-FOOD-05']);
    $fifthMachineId = (int)$machineStmt->fetchColumn();

    // Máquina viva fuera del perímetro de los dos actores móviles: su sede
    // pertenece a una zona sin asignación de Reponedor ni de Técnico (PR25-01).
    $outsideZoneId = (int)$pdo->query(
        "INSERT INTO operational_zones (code, name, color_hex)
         VALUES ('ZONA-DEX9-OUT', 'Zona DEX 09 fuera', '#2560ff') RETURNING id"
    )->fetchColumn();
    $locationStmt->execute([
        ':site_code' => 'SEDE-DEX9-OUT',
        ':site_code_key' => 'sede-dex9-out',
        ':name' => 'Sede DEX 09 fuera de perímetro',
        ':address' => 'Carrer DEX 9, Girona',
        ':zone_id' => $outsideZoneId,
    ]);
    $outsideLocationId = (int)$locationStmt->fetchColumn();
    $machineStmt->execute([':location_id' => $outsideLocationId, ':code' => 'DEX9-FOOD-OUT']);
    $outsideMachineId = (int)$machineStmt->fetchColumn();
    $pdo->prepare(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES (:restocker_id, :zone_id, 'PRIMARY', TRUE)"
    )->execute([':restocker_id' => $restockerId, ':zone_id' => $zoneId]);
    $pdo->prepare(
        'INSERT INTO technician_zone_assignments (technician_id, zone_id, is_primary)
         VALUES (:technician_id, :zone_id, TRUE)'
    )->execute([':technician_id' => $technicianId, ':zone_id' => $zoneId]);

    // Hechos M34/M35 reales para el cruce del detalle (QA-3, RF-04.3.3):
    // un carril A1, dos visitas COMPLETED del mismo día y dos recaudaciones.
    $productId = (int)$pdo->query(
        "INSERT INTO vending_products (sku, name, category, unit_type, requires_cold_chain, max_shelf_life_days, unit_cost_eur, sale_price_eur)
         VALUES ('DEX9-YOGUR', 'Yogur DEX 09', 'PERISHABLE_FOOD', 'UNIT', TRUE, 30, 1.00, 2.00) RETURNING id"
    )->fetchColumn();
    $slotStmt = $pdo->prepare(
        "INSERT INTO machine_planogram_slots (machine_id, slot_code, product_id, max_capacity, current_estimated_qty)
         VALUES (:machine_id, 'A1', :product_id, 20, 10) RETURNING id"
    );
    $slotStmt->execute([':machine_id' => $machineId, ':product_id' => $productId]);
    $slotId = (int)$slotStmt->fetchColumn();

    $visitStmt = $pdo->prepare(
        "INSERT INTO machine_restock_visits (visit_code, machine_id, restocker_id, status, started_at, completed_at, cabin_temp_celsius, client_mutation_id)
         VALUES (:visit_code, :machine_id, :restocker_id, 'COMPLETED', :started_at, :completed_at, 4.0, :mutation) RETURNING id"
    );
    $visitStmt->execute([
        ':visit_code' => 'MRV-2026-0001',
        ':machine_id' => $machineId,
        ':restocker_id' => $restockerId,
        ':started_at' => '2026-11-24 06:30:00+00',
        ':completed_at' => '2026-11-24 07:00:00+00',
        ':mutation' => 'dex09-visit-0001',
    ]);
    $previousVisitId = (int)$visitStmt->fetchColumn();
    $visitStmt->execute([
        ':visit_code' => 'MRV-2026-0002',
        ':machine_id' => $machineId,
        ':restocker_id' => $restockerId,
        ':started_at' => '2026-11-24 08:00:00+00',
        ':completed_at' => '2026-11-24 08:30:00+00',
        ':mutation' => 'dex09-visit-0002',
    ]);
    $currentVisitId = (int)$visitStmt->fetchColumn();
    $visitStmt->execute([
        ':visit_code' => 'MRV-2026-0003',
        ':machine_id' => $machineId,
        ':restocker_id' => $restockerId,
        ':started_at' => '2026-11-24 09:15:00+00',
        ':completed_at' => '2026-11-24 09:45:00+00',
        ':mutation' => 'dex09-visit-0003',
    ]);
    $secondVisitId = (int)$visitStmt->fetchColumn();
    // Visita COMPLETED de otra máquina para el cruce de vínculos (PR25-05).
    $visitStmt->execute([
        ':visit_code' => 'MRV-2026-0004',
        ':machine_id' => $secondMachineId,
        ':restocker_id' => $restockerId,
        ':started_at' => '2026-11-24 09:00:00+00',
        ':completed_at' => '2026-11-24 09:30:00+00',
        ':mutation' => 'dex09-visit-0004',
    ]);
    $foreignVisitId = (int)$visitStmt->fetchColumn();

    $itemStmt = $pdo->prepare(
        "INSERT INTO machine_restock_visit_items (visit_id, slot_id, slot_code, product_id, qty_before, qty_added, qty_after, loaded_batch_code)
         VALUES (:visit_id, :slot_id, 'A1', :product_id, :qty_before, :qty_added, :qty_after, :loaded_batch)"
    );
    $itemStmt->execute([
        ':visit_id' => $previousVisitId,
        ':slot_id' => $slotId,
        ':product_id' => $productId,
        ':qty_before' => 12,
        ':qty_added' => 0,
        ':qty_after' => 12,
        ':loaded_batch' => 'LOT-DEX9-001',
    ]);
    $itemStmt->execute([
        ':visit_id' => $currentVisitId,
        ':slot_id' => $slotId,
        ':product_id' => $productId,
        ':qty_before' => 5,
        ':qty_added' => 10,
        ':qty_after' => 15,
        ':loaded_batch' => 'LOT-DEX9-002',
    ]);
    $itemStmt->execute([
        ':visit_id' => $secondVisitId,
        ':slot_id' => $slotId,
        ':product_id' => $productId,
        ':qty_before' => 8,
        ':qty_added' => 0,
        ':qty_after' => 8,
        ':loaded_batch' => 'LOT-DEX9-003',
    ]);

    $cashStmt = $pdo->prepare(
        "INSERT INTO machine_cash_collections
         (collection_code, restock_visit_id, machine_id, machine_code_snapshot, restocker_id,
          restocker_name_snapshot, restocker_code_snapshot, bag_seal_code, sales_counter_reading,
          previous_sales_counter_reading, initial_baseline_reading_eur, expected_cashbox_eur,
          status, collected_at, delivered_to_vault_at, delivered_to_vault_by, vault_counted_eur,
          discrepancy_eur, vault_reconciled_by, vault_reconciled_by_name_snapshot, vault_reconciled_at,
          discrepancy_justification, client_mutation_id)
         VALUES ('MCC-2026-0001', :visit_id, :machine_id, 'DEX9-FOOD-01', :restocker_id,
          'Reposición DEX 09', 'DX9-REPO', 'BAG-2026-001', 9.00, 0.00, 0.00, 9.00,
          'DISCREPANCY_FLAGGED', '2026-11-24 09:30:00+00', '2026-11-24 11:00:00+00', :delivered_by, 10.50,
          1.50, :reconciled_by, 'Coordinación DEX 09', '2026-11-24 12:00:00+00',
          'Descuadre de prueba con tres bloques', :mutation) RETURNING id"
    );
    $cashStmt->execute([
        ':visit_id' => $currentVisitId,
        ':machine_id' => $machineId,
        ':restocker_id' => $restockerId,
        ':delivered_by' => $coordinatorId,
        ':reconciled_by' => $coordinatorId,
        ':mutation' => 'dex09-cash-0001',
    ]);
    $cashCollection1Id = (int)$cashStmt->fetchColumn();
    $sealedStmt = $pdo->prepare(
        "INSERT INTO machine_cash_collections
         (collection_code, restock_visit_id, machine_id, machine_code_snapshot, restocker_id,
          restocker_name_snapshot, restocker_code_snapshot, bag_seal_code, sales_counter_reading,
          previous_sales_counter_reading, initial_baseline_reading_eur, expected_cashbox_eur,
          status, collected_at, client_mutation_id)
         VALUES ('MCC-2026-0002', NULL, :machine_id, 'DEX9-FOOD-01', :restocker_id,
          'Reposición DEX 09', 'DX9-REPO', 'BAG-2026-002', 9.00, 0.00, 0.00, 9.00,
          'SEALED_IN_ROUTE', '2026-11-24 09:45:00+00', :mutation) RETURNING id"
    );
    $sealedStmt->execute([
        ':machine_id' => $machineId,
        ':restocker_id' => $restockerId,
        ':mutation' => 'dex09-cash-0002',
    ]);
    $cashCollection2Id = (int)$sealedStmt->fetchColumn();

    $userRepo = new PdoUserRepository($pdo);
    $authService = new AuthService(null, $userRepo);
    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $restockerToken = $authService->generateInternalToken($userRepo->findById($restockerId));
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

    echo "\n--- 1. RBAC de las tres puertas ---\n";
    $anonymous = $call('GET', '/api/restocker/machines/' . $machineId . '/dex-audits');
    $assert('1.1 Sin token responde 401 UNAUTHORIZED', $anonymous->getStatusCode() === 401 && $errorCode($anonymous) === 'UNAUTHORIZED');
    $coordinatorOnMobile = $call('GET', '/api/restocker/machines/' . $machineId . '/dex-audits', [], $coordinatorToken);
    $assert('1.2 Coordinación no entra en la puerta móvil (403)', $coordinatorOnMobile->getStatusCode() === 403);
    $restockerOnCoordinator = $call('GET', '/api/coordinator/dex-audits', [], $restockerToken);
    $assert('1.3 El Reponedor no entra en la consola de Coordinación (403)', $restockerOnCoordinator->getStatusCode() === 403);
    $technicianOnRestocker = $call('GET', '/api/restocker/machines/' . $machineId . '/dex-audits', [], $technicianToken);
    $assert('1.4 El Técnico no entra en la puerta del Reponedor (403)', $technicianOnRestocker->getStatusCode() === 403);

    $outsideRestocker = $call('GET', '/api/restocker/machines/' . $outsideMachineId . '/dex-audits', [], $restockerToken);
    $assert(
        '1.5 Un Reponedor fuera de sus zonas recibe 403 MACHINE_OUTSIDE_RESTOCKER_ZONES',
        $outsideRestocker->getStatusCode() === 403 && $errorCode($outsideRestocker) === 'MACHINE_OUTSIDE_RESTOCKER_ZONES',
        $errorCode($outsideRestocker)
    );
    $outsideTechnician = $call('GET', '/api/technician/machines/' . $outsideMachineId . '/dex-audits', [], $technicianToken);
    $assert(
        '1.6 Un Técnico sin intervención ni zona recibe 403 MACHINE_OUTSIDE_TECHNICIAN_SCOPE',
        $outsideTechnician->getStatusCode() === 403 && $errorCode($outsideTechnician) === 'MACHINE_OUTSIDE_TECHNICIAN_SCOPE',
        $errorCode($outsideTechnician)
    );
    $missingRestocker = $call('GET', '/api/restocker/machines/999999/dex-audits', [], $restockerToken);
    $assert(
        '1.7 Una máquina inexistente en la puerta móvil responde 404 MACHINE_NOT_FOUND',
        $missingRestocker->getStatusCode() === 404 && $errorCode($missingRestocker) === 'MACHINE_NOT_FOUND',
        $errorCode($missingRestocker)
    );
    $missingTechnician = $call('GET', '/api/technician/machines/999999/dex-audits', [], $technicianToken);
    $assert(
        '1.8 La misma guarda de existencia aplica a la puerta del Técnico',
        $missingTechnician->getStatusCode() === 404 && $errorCode($missingTechnician) === 'MACHINE_NOT_FOUND',
        $errorCode($missingTechnician)
    );

    echo "\n--- 2. Importación y Arqueo Ciego del Reponedor (QA-1) ---\n";
    $dexPayload = "DXS*COMM-DEX09*VA*DEX 6.0\nID1*DEX9-FOOD-01\nID4*2\nVA1*1250*25\nCA3*900*0*0\nPA1*A1*150\nPA2*10*1250\nEA2*EG01*1*1*0*1\nDXE*FIN";
    $importPayload = [
        'raw_payload' => $dexPayload,
        'original_filename' => 'ruta.dex',
        'captured_at' => '2026-11-24 09:00:00+00',
        'client_mutation_id' => 'dex09-api-0001',
        'restock_visit_id' => $currentVisitId,
        'cash_collection_id' => $cashCollection1Id,
    ];
    $imported = $call('POST', '/api/restocker/machines/' . $machineId . '/dex-audits', $importPayload, $restockerToken);
    $importedData = $data($imported);
    $assert('2.1 La importación del Reponedor responde 201', $imported->getStatusCode() === 201, $errorCode($imported));
    $assert('2.2 El POST inmediato trae prefill VA1', ($importedData['prefill_sales_counter_reading_eur'] ?? null) === 12.50);
    $assert(
        '2.3 El POST no filtra CA3 ni euros por carril (Art. V.4)',
        $importedData !== []
            && !in_array('ca3_cashbox_eur', $collectKeys($importedData), true)
            && !in_array('total_sales_eur', $collectKeys($importedData), true)
    );

    $history = $call('GET', '/api/restocker/machines/' . $machineId . '/dex-audits', [], $restockerToken);
    $historyData = $data($history);
    $historyAudits = $historyData['audits'] ?? [];
    $assert(
        '2.4 El histórico del Reponedor no contiene ningún importe en euros',
        $history->getStatusCode() === 200
            && $historyAudits !== []
            && !in_array('prefill_sales_counter_reading_eur', $collectKeys($historyData), true)
            && !in_array('ca3_cashbox_eur', $collectKeys($historyData), true)
            && !in_array('total_sales_eur', $collectKeys($historyData), true)
            && !in_array('unit_price_eur', $collectKeys($historyData), true)
    );

    $duplicated = $call('POST', '/api/restocker/machines/' . $machineId . '/dex-audits', [
        'raw_payload' => $dexPayload,
        'original_filename' => 'ruta-2.dex',
        'captured_at' => '2026-11-24 10:00:00+00',
        'client_mutation_id' => 'dex09-api-0002',
    ], $restockerToken);
    $assert('2.5 La misma huella responde 409 DEX_DUPLICATE_HASH', $duplicated->getStatusCode() === 409 && $errorCode($duplicated) === 'DEX_DUPLICATE_HASH');

    $forgedIdentity = $call('POST', '/api/restocker/machines/' . $identityMachineId . '/dex-audits', [
        'raw_payload' => "DXS*COMM-DEX09-IDENTITY*VA*DEX 6.0\nID1*DEX9-FOOD-01\nID4*2\nVA1*1000*10\nDXE*FIN",
        'original_filename' => 'identidad-forzada.dex',
        'captured_at' => '2026-11-24 10:15:00+00',
        'client_mutation_id' => 'dex09-api-identity-0001',
        'expected_machine_code' => 'DEX9-FOOD-01',
    ], $restockerToken);
    $forgedIdentityData = $data($forgedIdentity);
    $assert(
        '2.6 El código esperado del cliente no oculta un ID1 ajeno (PR25-03)',
        $forgedIdentity->getStatusCode() === 201
            && ($forgedIdentityData['audit']['identity_mismatch_warning'] ?? null) === true
            && (int)($forgedIdentityData['audit']['machine_id'] ?? 0) === $identityMachineId,
        json_encode($forgedIdentityData)
    );

    $invalidCaptured = $call('POST', '/api/restocker/machines/' . $identityMachineId . '/dex-audits', [
        'raw_payload' => "DXS*COMM-DEX09-INVALID-DATE*VA*DEX 6.0\nID4*2\nVA1*1000*10\nDXE*FIN",
        'original_filename' => 'fecha-invalida.dex',
        'captured_at' => 'no-es-una-fecha',
        'client_mutation_id' => 'dex09-api-invalid-date-0001',
    ], $restockerToken);
    $assert(
        '2.7 Una captura no interpretable responde 422 DEX_INVALID_CAPTURED_AT',
        $invalidCaptured->getStatusCode() === 422 && $errorCode($invalidCaptured) === 'DEX_INVALID_CAPTURED_AT',
        $errorCode($invalidCaptured)
    );

    $futureCapturedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
        ->modify('+1 day')
        ->format('Y-m-d\TH:i:sP');
    $futureCaptured = $call('POST', '/api/restocker/machines/' . $identityMachineId . '/dex-audits', [
        'raw_payload' => "DXS*COMM-DEX09-FUTURE-DATE*VA*DEX 6.0\nID4*2\nVA1*1000*10\nDXE*FIN",
        'original_filename' => 'fecha-futura.dex',
        'captured_at' => $futureCapturedAt,
        'client_mutation_id' => 'dex09-api-future-date-0001',
    ], $restockerToken);
    $assert(
        '2.8 Una captura futura fuera de la tolerancia responde 422 DEX_CAPTURED_AT_IN_FUTURE',
        $futureCaptured->getStatusCode() === 422 && $errorCode($futureCaptured) === 'DEX_CAPTURED_AT_IN_FUTURE',
        $errorCode($futureCaptured)
    );

    $localCaptured = $call('POST', '/api/coordinator/machines/' . $identityMachineId . '/dex-audits', [
        'raw_payload' => "DXS*COMM-DEX09-LOCAL-DATE*VA*DEX 6.0\nID4*2\nVA1*1100*11\nDXE*FIN",
        'original_filename' => 'fecha-local.dex',
        'captured_at' => '2026-11-24T23:30',
        'client_mutation_id' => 'dex09-api-local-date-0001',
    ], $coordinatorToken);
    $localAuditId = (int)($data($localCaptured)['audit']['id'] ?? 0);
    $capturedStmt = $pdo->prepare('SELECT EXTRACT(EPOCH FROM captured_at) FROM machine_dex_audits WHERE id = :id');
    $capturedStmt->execute([':id' => $localAuditId]);
    $storedCapturedEpoch = (int)$capturedStmt->fetchColumn();
    $expectedCaptured = new \DateTimeImmutable('2026-11-24T23:30:00', new \DateTimeZone('Europe/Madrid'));
    $assert(
        '2.9 Una captura local de Coordinación se interpreta en Europe/Madrid',
        $localCaptured->getStatusCode() === 201
            && $localAuditId > 0
            && $storedCapturedEpoch === $expectedCaptured->getTimestamp(),
        json_encode(['status' => $localCaptured->getStatusCode(), 'stored_epoch' => $storedCapturedEpoch, 'expected_epoch' => $expectedCaptured->getTimestamp()])
    );

    $missingLink = $call('POST', '/api/restocker/machines/' . $machineId . '/dex-audits', [
        'raw_payload' => "DXS*COMM-DEX09-LINK-MISSING*VA*DEX 6.0\nID4*2\nVA1*1300*26\nDXE*FIN",
        'original_filename' => 'vinculo-inexistente.dex',
        'captured_at' => '2026-11-24 13:00:00+00',
        'client_mutation_id' => 'dex09-api-link-0001',
        'restock_visit_id' => 999999,
    ], $restockerToken);
    $assert(
        '2.10 Un vínculo inexistente responde 422 DEX_LINK_TARGET_NOT_FOUND',
        $missingLink->getStatusCode() === 422 && $errorCode($missingLink) === 'DEX_LINK_TARGET_NOT_FOUND',
        $errorCode($missingLink)
    );

    $foreignLink = $call('POST', '/api/restocker/machines/' . $machineId . '/dex-audits', [
        'raw_payload' => "DXS*COMM-DEX09-LINK-FOREIGN*VA*DEX 6.0\nID4*2\nVA1*1301*26\nDXE*FIN",
        'original_filename' => 'vinculo-ajeno.dex',
        'captured_at' => '2026-11-24 13:05:00+00',
        'client_mutation_id' => 'dex09-api-link-0002',
        'restock_visit_id' => $foreignVisitId,
    ], $restockerToken);
    $assert(
        '2.11 Un vínculo de otra máquina responde 422 DEX_LINK_TARGET_NOT_FOUND',
        $foreignLink->getStatusCode() === 422 && $errorCode($foreignLink) === 'DEX_LINK_TARGET_NOT_FOUND',
        $errorCode($foreignLink)
    );

    $occupiedVisit = $call('POST', '/api/restocker/machines/' . $machineId . '/dex-audits', [
        'raw_payload' => "DXS*COMM-DEX09-LINK-VISIT*VA*DEX 6.0\nID4*2\nVA1*1302*26\nDXE*FIN",
        'original_filename' => 'visita-ocupada.dex',
        'captured_at' => '2026-11-24 13:10:00+00',
        'client_mutation_id' => 'dex09-api-link-0003',
        'restock_visit_id' => $currentVisitId,
    ], $restockerToken);
    $assert(
        '2.12 Una visita ya vinculada responde 409 DEX_AUDIT_ALREADY_LINKED',
        $occupiedVisit->getStatusCode() === 409 && $errorCode($occupiedVisit) === 'DEX_AUDIT_ALREADY_LINKED',
        $errorCode($occupiedVisit)
    );

    $occupiedCash = $call('POST', '/api/restocker/machines/' . $machineId . '/dex-audits', [
        'raw_payload' => "DXS*COMM-DEX09-LINK-CASH*VA*DEX 6.0\nID4*2\nVA1*1303*26\nDXE*FIN",
        'original_filename' => 'recaudacion-ocupada.dex',
        'captured_at' => '2026-11-24 13:15:00+00',
        'client_mutation_id' => 'dex09-api-link-0004',
        'cash_collection_id' => $cashCollection1Id,
    ], $restockerToken);
    $assert(
        '2.13 Una recaudación ya vinculada responde 409 DEX_AUDIT_ALREADY_LINKED',
        $occupiedCash->getStatusCode() === 409 && $errorCode($occupiedCash) === 'DEX_AUDIT_ALREADY_LINKED',
        $errorCode($occupiedCash)
    );

    echo "\n--- 3. Consola de Coordinación, detalle y carga manual ---\n";
    $list = $call('GET', '/api/coordinator/dex-audits', [], $coordinatorToken, ['machine_id' => (string)$machineId]);
    $listData = $data($list);
    $assert(
        '3.1 El listado devuelve volcados, total, tope de exportación y los cinco KPIs',
        $list->getStatusCode() === 200
            && count($listData['audits'] ?? []) === 1
            && (int)($listData['total'] ?? 0) === 1
            && (int)($listData['limit'] ?? 0) === 100
            && (int)($listData['offset'] ?? -1) === 0
            && (int)($listData['export_limit'] ?? 0) === 500
            && isset($listData['kpis']['total_active'], $listData['kpis']['thermal_alert_count'], $listData['kpis']['warning_count'], $listData['kpis']['cash_deviation_count'], $listData['kpis']['voided_count'])
    );
    $invalidFilter = $call('GET', '/api/coordinator/dex-audits', [], $coordinatorToken, ['status' => 'BORRADO']);
    $assert('3.1.b Un filtro status fuera del contrato responde 422', $invalidFilter->getStatusCode() === 422 && $errorCode($invalidFilter) === 'VALIDATION_ERROR');

    $pageOne = $data($call('GET', '/api/coordinator/dex-audits', [], $coordinatorToken, ['status' => 'ALL', 'limit' => '1', 'offset' => '0']));
    $pageTwo = $data($call('GET', '/api/coordinator/dex-audits', [], $coordinatorToken, ['status' => 'ALL', 'limit' => '1', 'offset' => '1']));
    $assert(
        '3.1.c limit y offset paginan sin repetir y conservan el total',
        count($pageOne['audits'] ?? []) === 1
            && count($pageTwo['audits'] ?? []) === 1
            && (int)($pageOne['audits'][0]['id'] ?? 0) !== (int)($pageTwo['audits'][0]['id'] ?? 0)
            && (int)($pageOne['total'] ?? 0) >= 2
            && (int)($pageTwo['total'] ?? -1) === (int)($pageOne['total'] ?? 0)
    );
    $byZone = $data($call('GET', '/api/coordinator/dex-audits', [], $coordinatorToken, ['status' => 'ALL', 'zone_id' => (string)$zoneId]));
    $byLocation = $data($call('GET', '/api/coordinator/dex-audits', [], $coordinatorToken, ['status' => 'ALL', 'location_id' => (string)$locationId]));
    $outsideZone = $data($call('GET', '/api/coordinator/dex-audits', [], $coordinatorToken, ['status' => 'ALL', 'zone_id' => (string)$outsideZoneId]));
    $outsideLocation = $data($call('GET', '/api/coordinator/dex-audits', [], $coordinatorToken, ['status' => 'ALL', 'location_id' => (string)$outsideLocationId]));
    $assert(
        '3.1.d Los filtros de zona y sede acotan listado y total',
        (int)($byZone['total'] ?? 0) >= 2
            && (int)($byZone['total'] ?? -1) === (int)($byLocation['total'] ?? 0)
            && (int)($outsideZone['total'] ?? -1) === 0
            && (int)($outsideLocation['total'] ?? -1) === 0
            && !in_array($outsideMachineId, array_column($byZone['audits'] ?? [], 'machine_id'), true)
    );
    $auditId = (int)($listData['audits'][0]['id'] ?? 0);
    $auditCode = (string)($listData['audits'][0]['audit_code'] ?? '');
    $detail = $call('GET', '/api/coordinator/dex-audits/' . $auditId, [], $coordinatorToken);
    $detailData = $data($detail);
    $assert(
        '3.2 El detalle completo conserva VA1/CA3 y PA1 sin raw_payload',
        $detail->getStatusCode() === 200
            && ($detailData['va1_total_sales_eur'] ?? null) === 12.50
            && (float)($detailData['ca3_cashbox_eur'] ?? 0) === 9.0
            && ($detailData['machine_code'] ?? '') !== ''
            && ($detailData['machine_code'] ?? null) === ($detailData['machine_code_snapshot'] ?? null)
            && ($detailData['can_download_raw'] ?? false) === true
            && !in_array('raw_payload', $collectKeys($detailData), true),
        json_encode($detailData)
    );

    $manual = $call('POST', '/api/coordinator/machines/' . $machineId . '/dex-audits', [
        'raw_payload' => "DXS*COMM-DEX09*VA*DEX 6.0\nID4*2\nVA1*2000*40\nDXE*FIN",
        'original_filename' => 'consola.dex',
        'captured_at' => '2026-11-24 11:00:00+00',
        'client_mutation_id' => 'dex09-api-0003',
    ], $coordinatorToken);
    $assert('3.3 La carga manual de Coordinación responde 201', $manual->getStatusCode() === 201, $errorCode($manual));
    $assert('3.4 La consola recibe la vista completa', in_array('ca3_coin_tubes_eur', $collectKeys($data($manual)), true));

    $technicianImport = $call('POST', '/api/technician/machines/' . $machineId . '/dex-audits', [
        'raw_payload' => "DXS*COMM-DEX09*VA*DEX 6.0\nID4*2\nVA1*1500*30\nDXE*FIN",
        'original_filename' => 'sat.dex',
        'captured_at' => '2026-11-24 12:00:00+00',
        'client_mutation_id' => 'dex09-api-0004',
    ], $technicianToken);
    $assert(
        '3.5 El Técnico importa en su zona con 201 y sin prefill en euros',
        $technicianImport->getStatusCode() === 201
            && ($data($technicianImport)['audit']['id'] ?? 0) > 0
            && !in_array('prefill_sales_counter_reading_eur', $collectKeys($data($technicianImport)), true)
            && !in_array('ca3_cashbox_eur', $collectKeys($data($technicianImport)), true),
        (string)$technicianImport->getStatusCode()
    );
    $technicianList = $call('GET', '/api/technician/machines/' . $machineId . '/dex-audits', [], $technicianToken);
    $assert(
        '3.6 El listado del Técnico no filtra euros (Art. V.4)',
        $technicianList->getStatusCode() === 200
            && count($data($technicianList)['audits'] ?? []) === 3
            && !in_array('prefill_sales_counter_reading_eur', $collectKeys($data($technicianList)), true)
            && !in_array('ca3_cashbox_eur', $collectKeys($data($technicianList)), true)
    );

    echo "\n--- 3.7. Cruces M34/M35 del detalle de Coordinación (QA-3) ---\n";
    $assert(
        '3.7 El detalle compone M35 con prioridad CA3_VS_VAULT y umbral ±1,00 €',
        ($detailData['m35_cross_check']['cash_collection_id'] ?? 0) === $cashCollection1Id
            && ($detailData['m35_cross_check']['comparison_basis'] ?? '') === 'CA3_VS_VAULT'
            && ($detailData['m35_cross_check']['diff_eur'] ?? null) === 1.50
            && ($detailData['m35_cross_check']['is_deviation'] ?? false) === true,
        json_encode($detailData['m35_cross_check'] ?? null)
    );
    $assert(
        '3.8 El detalle compone el consumo observado M34 y deja la diferencia nula sin delta',
        ($detailData['m34_cross_check']['visit_id'] ?? 0) === $currentVisitId
            && ($detailData['m34_cross_check']['previous_visit_id'] ?? 0) === $previousVisitId
            && ($detailData['m34_cross_check']['lines'][0]['observed_consumption_units'] ?? null) === 7
            && array_key_exists('difference_units', $detailData['m34_cross_check']['lines'][0] ?? [])
            && $detailData['m34_cross_check']['lines'][0]['difference_units'] === null,
        json_encode($detailData['m34_cross_check'] ?? null)
    );

    $secondImport = $call('POST', '/api/restocker/machines/' . $machineId . '/dex-audits', [
        'raw_payload' => "DXS*COMM-DEX09*VA*DEX 6.0\nID1*DEX9-FOOD-01\nID4*2\nVA1*2000*40\nPA1*A1*200\nPA2*16*2000\nDXE*FIN",
        'original_filename' => 'ruta-segunda.dex',
        'captured_at' => '2026-11-24 10:00:00+00',
        'client_mutation_id' => 'dex09-api-0005',
        'restock_visit_id' => $secondVisitId,
        'cash_collection_id' => $cashCollection2Id,
    ], $restockerToken);
    $secondAuditId = (int)($data($secondImport)['audit']['id'] ?? 0);
    $secondDetail = $call('GET', '/api/coordinator/dex-audits/' . $secondAuditId, [], $coordinatorToken);
    $secondData = $data($secondDetail);
    $assert(
        '3.9 La segunda carga cae a VA1_VS_EXPECTED y calcula la diferencia por carril',
        $secondAuditId > 0
            && ($secondData['m35_cross_check']['comparison_basis'] ?? '') === 'VA1_VS_EXPECTED'
            && ($secondData['m35_cross_check']['diff_eur'] ?? null) === 1.50
            && ($secondData['m35_cross_check']['is_deviation'] ?? false) === true
            && ($secondData['m34_cross_check']['lines'][0]['delta_vend_units'] ?? null) === 6
            && ($secondData['m34_cross_check']['lines'][0]['observed_consumption_units'] ?? null) === 7
            && ($secondData['m34_cross_check']['lines'][0]['difference_units'] ?? null) === -1,
        json_encode(['m35' => $secondData['m35_cross_check'] ?? null, 'm34' => $secondData['m34_cross_check'] ?? null])
    );

    echo "\n--- 4. Descarga segura del .dex original (QA-5) ---\n";
    $raw = $call('GET', '/api/coordinator/dex-audits/' . $auditId . '/raw', [], $coordinatorToken);
    $assert(
        '4.1 /raw responde text/plain con nosniff y filename DEX-AAAA-NNNN.dex',
        $raw->getStatusCode() === 200
            && $raw->getHeader('Content-Type') === 'text/plain; charset=utf-8'
            && $raw->getHeader('X-Content-Type-Options') === 'nosniff'
            && preg_match('/filename="DEX-\d{4}-\d{4}\.dex"/', (string)$raw->getHeader('Content-Disposition')) === 1
    );
    $assert('4.2 /raw entrega el fichero original', $raw->getBody() === $dexPayload);
    $missingRaw = $call('GET', '/api/coordinator/dex-audits/999999/raw', [], $coordinatorToken);
    $assert('4.3 Un volcado inexistente responde 404', $missingRaw->getStatusCode() === 404 && $errorCode($missingRaw) === 'DEX_AUDIT_NOT_FOUND');

    echo "\n--- 5. Anulación lógica y CSV ---\n";
    $void = $call('POST', '/api/coordinator/dex-audits/' . $auditId . '/void', [
        'void_reason' => 'Anulación por duplicado operativo en la ruta de certificación',
    ], $coordinatorToken);
    $voidData = $data($void);
    $assert('5.1 La anulación válida responde 200 y VOIDED', $void->getStatusCode() === 200 && ($voidData['status'] ?? '') === 'VOIDED');
    $again = $call('POST', '/api/coordinator/dex-audits/' . $auditId . '/void', [
        'void_reason' => 'Segundo intento de anulación del mismo volcado',
    ], $coordinatorToken);
    $assert('5.2 Una segunda anulación responde 409 DEX_AUDIT_ALREADY_VOIDED', $again->getStatusCode() === 409 && $errorCode($again) === 'DEX_AUDIT_ALREADY_VOIDED');
    $manualAuditId = (int)($data($manual)['audit']['id'] ?? 0);
    $manualAuditCode = (string)($data($manual)['audit']['audit_code'] ?? '');
    $shortVoid = $call('POST', '/api/coordinator/dex-audits/' . $manualAuditId . '/void', ['void_reason' => 'Corto'], $coordinatorToken);
    $assert('5.3 Un motivo corto responde 422 VOID_REASON_REQUIRED', $shortVoid->getStatusCode() === 422 && $errorCode($shortVoid) === 'VOID_REASON_REQUIRED');

    $csvCellsFor = static function (string $body, string $auditCode): ?array {
        foreach (explode("\r\n", $body) as $line) {
            $cells = explode(';', $line);
            if (($cells[0] ?? '') === $auditCode) {
                return $cells;
            }
        }

        return null;
    };
    $csv = $call('GET', '/api/coordinator/dex-audits/export-csv', [], $coordinatorToken, ['machine_id' => (string)$machineId]);
    $assert(
        '5.4 El CSV lleva BOM UTF-8 y separador ;',
        $csv->getStatusCode() === 200
            && str_starts_with($csv->getBody(), "\xEF\xBB\xBF")
            && str_contains($csv->getBody(), 'audit_code;machine_code;captured_at')
    );

    $manualCsvRow = $csvCellsFor($csv->getBody(), $manualAuditCode);
    $assert(
        '5.5 Las columnas de unidades del CSV salen como enteros, sin decimales',
        is_array($manualCsvRow)
            && preg_match('/^\d+$/', (string)($manualCsvRow[6] ?? '')) === 1
            && preg_match('/^\d+$/', (string)($manualCsvRow[9] ?? '')) === 1,
        json_encode($manualCsvRow)
    );

    echo "\n--- 6. Conflict-dump Offline ---\n";
    $dumpReason = 'El volcado .dex offline ya no puede aplicarse.';
    $dump = $call('POST', '/api/restocker/dex-audits/conflict-dump', [
        'machine_id' => $machineId,
        'reason' => 'DEX_DUPLICATE_HASH',
        'client_mutation_id' => 'dex09-api-conflict-0001',
    ], $restockerToken);
    $assert('6.1 El volcado de conflicto responde 200 CONFLICT_DUMPED', $dump->getStatusCode() === 200 && (($data($dump)['outcome_status'] ?? '') === 'CONFLICT_DUMPED'));
    $badDump = $call('POST', '/api/technician/dex-audits/conflict-dump', ['machine_id' => $machineId], $technicianToken);
    $assert(
        '6.2 Un conflict-dump sin motivo responde 422 DEX_INVALID_CONFLICT_REASON',
        $badDump->getStatusCode() === 422 && $errorCode($badDump) === 'DEX_INVALID_CONFLICT_REASON',
        $errorCode($badDump)
    );
    $longDump = $call('POST', '/api/restocker/dex-audits/conflict-dump', [
        'machine_id' => $machineId,
        'reason' => str_repeat('x', 501),
    ], $restockerToken);
    $assert(
        '6.3 Un motivo de más de 500 caracteres responde 422 DEX_INVALID_CONFLICT_REASON',
        $longDump->getStatusCode() === 422 && $errorCode($longDump) === 'DEX_INVALID_CONFLICT_REASON',
        $errorCode($longDump)
    );
    $missingDump = $call('POST', '/api/restocker/dex-audits/conflict-dump', [
        'machine_id' => 999999,
        'reason' => $dumpReason,
    ], $restockerToken);
    $assert(
        '6.4 Una máquina inexistente responde 404 MACHINE_NOT_FOUND',
        $missingDump->getStatusCode() === 404 && $errorCode($missingDump) === 'MACHINE_NOT_FOUND',
        $errorCode($missingDump)
    );
    $outsideDump = $call('POST', '/api/restocker/dex-audits/conflict-dump', [
        'machine_id' => $outsideMachineId,
        'reason' => $dumpReason,
    ], $restockerToken);
    $assert(
        '6.5 Una máquina fuera de las zonas del Reponedor responde 403',
        $outsideDump->getStatusCode() === 403 && $errorCode($outsideDump) === 'MACHINE_OUTSIDE_RESTOCKER_ZONES',
        $errorCode($outsideDump)
    );
    $outsideTechnicianDump = $call('POST', '/api/technician/dex-audits/conflict-dump', [
        'machine_id' => $outsideMachineId,
        'reason' => $dumpReason,
    ], $technicianToken);
    $assert(
        '6.6 Una máquina fuera del alcance del Técnico responde 403',
        $outsideTechnicianDump->getStatusCode() === 403
            && $errorCode($outsideTechnicianDump) === 'MACHINE_OUTSIDE_TECHNICIAN_SCOPE',
        $errorCode($outsideTechnicianDump)
    );
    $rejectedRows = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_log
         WHERE action = 'OFFLINE_DEX_AUDIT_CONFLICT_DUMP'
           AND entity_type = 'MACHINE'
           AND entity_id IN (999999, :outside_machine_id)"
    );
    $rejectedRows->execute([':outside_machine_id' => $outsideMachineId]);
    $assert(
        '6.7 Los volcados rechazados no escriben en audit_log',
        (int)$rejectedRows->fetchColumn() === 0
    );

    $spyLogger = new Dex09SpyAuditLogger();
    $spyController = new RestockerRouteController($pdo, auditLogger: $spyLogger);
    $spyRequest = new Request('POST', '/api/restocker/dex-audits/conflict-dump', [], [
        'machine_id' => $machineId,
        'reason' => 'Motivo de conflicto con el logger inyectado.',
        'client_mutation_id' => 'dex09-api-conflict-0002',
    ], ['Content-Type' => 'application/json']);
    $spyRequest->setAttribute('user_id', $restockerId);
    $spyRequest->setAttribute('user_role', 'RESTOCKER');
    $spyResponse = $spyController->dumpDexConflict($spyRequest);
    $assert(
        '6.8 El conflict-dump usa el AuditLogger inyectado',
        $spyResponse->getStatusCode() === 200
            && ($spyLogger->machineEvents[0]['action'] ?? '') === 'OFFLINE_DEX_AUDIT_CONFLICT_DUMP'
            && (int)($spyLogger->machineEvents[0]['machine_id'] ?? 0) === $machineId,
        (string)$spyResponse->getStatusCode()
    );

    echo "\n--- 7. Delta efectivo tras VOIDED (AUD-39-02, QA-2) ---\n";
    $chainPayload = static fn (string $comm, int $salesCents, int $units): string =>
        "DXS*{$comm}*VA*DEX 6.0\nID1*DEX9-FOOD-02\nID4*2\nVA1*{$salesCents}*{$units}\nDXE*FIN";
    $importChain = static function (string $mutation, string $capturedAt, int $salesCents, int $units) use (
        $call,
        $coordinatorToken,
        $secondMachineId,
        $chainPayload
    ): Response {
        return $call('POST', '/api/coordinator/machines/' . $secondMachineId . '/dex-audits', [
            'raw_payload' => $chainPayload('COMM-' . strtoupper($mutation), $salesCents, $units),
            'original_filename' => $mutation . '.dex',
            'captured_at' => $capturedAt,
            'client_mutation_id' => $mutation,
        ], $coordinatorToken);
    };

    $baseImport = $importChain('aud3902-base', '2026-11-20 08:00:00+00', 1000, 10);
    $baseAudit = $data($baseImport)['audit'] ?? [];
    $baseAuditId = (int)($baseAudit['id'] ?? 0);
    $baseDetail = $data($call('GET', '/api/coordinator/dex-audits/' . $baseAuditId, [], $coordinatorToken));
    $assert(
        '7.1 La carga base nace sin predecesor y con el bloque efectivo explícito',
        $baseImport->getStatusCode() === 201
            && $baseAuditId > 0
            && array_key_exists('previous_audit_id', $baseDetail)
            && $baseDetail['previous_audit_id'] === null
            && array_key_exists('previous_audit_voided', $baseDetail)
            && $baseDetail['previous_audit_voided'] === false
            && array_key_exists('effective_previous_audit_id', $baseDetail)
            && $baseDetail['effective_previous_audit_id'] === null
            && array_key_exists('effective_delta_sales_eur', $baseDetail)
            && $baseDetail['effective_delta_sales_eur'] === null,
        json_encode(['status' => $baseImport->getStatusCode(), 'audit' => $baseDetail])
    );

    $middleImport = $importChain('aud3902-middle', '2026-11-21 08:00:00+00', 1500, 15);
    $middleAudit = $data($middleImport)['audit'] ?? [];
    $middleAuditId = (int)($middleAudit['id'] ?? 0);
    $assert(
        '7.2 La carga intermedia enlaza la base con delta persistido',
        $middleImport->getStatusCode() === 201
            && $middleAuditId > 0
            && ($middleAudit['previous_audit_id'] ?? 0) === $baseAuditId
            && (float)($middleAudit['delta_sales_eur'] ?? 0) === 5.0
            && ($middleAudit['delta_vend_units'] ?? 0) === 5,
        json_encode(['status' => $middleImport->getStatusCode(), 'audit' => $middleAudit])
    );

    $lastImport = $importChain('aud3902-last', '2026-11-22 08:00:00+00', 2200, 22);
    $lastAudit = $data($lastImport)['audit'] ?? [];
    $lastAuditId = (int)($lastAudit['id'] ?? 0);
    $assert(
        '7.3 La carga final enlaza la intermedia con delta persistido',
        $lastImport->getStatusCode() === 201
            && $lastAuditId > 0
            && ($lastAudit['previous_audit_id'] ?? 0) === $middleAuditId
            && (float)($lastAudit['delta_sales_eur'] ?? 0) === 7.0
            && ($lastAudit['delta_vend_units'] ?? 0) === 7,
        json_encode(['status' => $lastImport->getStatusCode(), 'audit' => $lastAudit])
    );

    $lastBeforeVoid = $data($call('GET', '/api/coordinator/dex-audits/' . $lastAuditId, [], $coordinatorToken));
    $assert(
        '7.4 Antes de anular, la lectura no marca predecesor anulado',
        $lastBeforeVoid !== []
            && array_key_exists('previous_audit_voided', $lastBeforeVoid)
            && $lastBeforeVoid['previous_audit_voided'] === false
            && array_key_exists('effective_previous_audit_id', $lastBeforeVoid)
            && $lastBeforeVoid['effective_previous_audit_id'] === null
            && array_key_exists('effective_delta_sales_eur', $lastBeforeVoid)
            && $lastBeforeVoid['effective_delta_sales_eur'] === null
            && array_key_exists('effective_delta_vend_units', $lastBeforeVoid)
            && $lastBeforeVoid['effective_delta_vend_units'] === null,
        json_encode($lastBeforeVoid)
    );

    $snapshotStmt = $pdo->prepare(
        'SELECT status, previous_audit_id, delta_source, delta_sales_eur, delta_vend_units,
                va1_total_sales_eur, va1_total_vend_units
         FROM machine_dex_audits WHERE id = :id'
    );
    $snapshot = static function (int $id) use ($snapshotStmt): array {
        $snapshotStmt->execute([':id' => $id]);
        return $snapshotStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $beforeMiddle = $snapshot($middleAuditId);
    $beforeLast = $snapshot($lastAuditId);

    $voidMiddle = $call('POST', '/api/coordinator/dex-audits/' . $middleAuditId . '/void', [
        'void_reason' => 'Anulación AUD-39-02 del volcado intermedio A',
    ], $coordinatorToken);
    $assert(
        '7.5 La anulación del volcado intermedio responde 200 y VOIDED',
        $voidMiddle->getStatusCode() === 200 && ($data($voidMiddle)['status'] ?? '') === 'VOIDED',
        $errorCode($voidMiddle)
    );

    $lastAfterVoid = $data($call('GET', '/api/coordinator/dex-audits/' . $lastAuditId, [], $coordinatorToken));
    $assert(
        '7.6 La lectura recalcula el delta efectivo contra la base activa',
        ($lastAfterVoid['previous_audit_voided'] ?? false) === true
            && ($lastAfterVoid['effective_previous_audit_id'] ?? 0) === $baseAuditId
            && (float)($lastAfterVoid['effective_delta_sales_eur'] ?? 0) === 12.0
            && ($lastAfterVoid['effective_delta_vend_units'] ?? 0) === 12
            && ($lastAfterVoid['previous_audit_id'] ?? 0) === $middleAuditId
            && (float)($lastAfterVoid['delta_sales_eur'] ?? 0) === 7.0
            && ($lastAfterVoid['delta_vend_units'] ?? 0) === 7,
        json_encode($lastAfterVoid)
    );

    // H25-03: el listado y el CSV resuelven el delta vigente sin abrir el detalle.
    $listAfterVoid = $data($call('GET', '/api/coordinator/dex-audits', [], $coordinatorToken, [
        'machine_id' => (string)$secondMachineId,
    ]));
    $lastListRow = null;
    foreach (($listAfterVoid['audits'] ?? []) as $listedRow) {
        if ((int)($listedRow['id'] ?? 0) === $lastAuditId) {
            $lastListRow = $listedRow;
            break;
        }
    }
    $assert(
        '7.7 El listado expone el delta efectivo cuando el predecesor está anulado',
        is_array($lastListRow)
            && ($lastListRow['previous_audit_voided'] ?? false) === true
            && ($lastListRow['effective_previous_audit_id'] ?? 0) === $baseAuditId
            && (float)($lastListRow['effective_delta_sales_eur'] ?? 0) === 12.0
            && ($lastListRow['effective_delta_vend_units'] ?? 0) === 12
            && ($lastListRow['previous_audit_id'] ?? 0) === $middleAuditId
            && (float)($lastListRow['delta_sales_eur'] ?? 0) === 7.0,
        json_encode($lastListRow)
    );

    $lastAuditCode = (string)($lastAudit['audit_code'] ?? '');
    $csvAfterVoid = $call('GET', '/api/coordinator/dex-audits/export-csv', [], $coordinatorToken, [
        'machine_id' => (string)$secondMachineId,
    ]);
    $csvRowAfterVoid = $csvCellsFor($csvAfterVoid->getBody(), $lastAuditCode);
    $assert(
        '7.8 El CSV publica el delta efectivo y las unidades como enteros',
        $csvAfterVoid->getStatusCode() === 200
            && is_array($csvRowAfterVoid)
            && ($csvRowAfterVoid[8] ?? null) === '12.00'
            && ($csvRowAfterVoid[9] ?? null) === '12',
        json_encode(['csv' => $csvRowAfterVoid, 'body' => $csvAfterVoid->getBody()])
    );

    $afterMiddle = $snapshot($middleAuditId);
    $afterLast = $snapshot($lastAuditId);
    $middleBeforeBusiness = $beforeMiddle;
    $middleAfterBusiness = $afterMiddle;
    unset($middleBeforeBusiness['status'], $middleAfterBusiness['status']);
    $assert(
        '7.9 Las filas persistidas de A y B permanecen intactas (Art. III.3)',
        ($afterMiddle['status'] ?? '') === 'VOIDED'
            && $middleAfterBusiness === $middleBeforeBusiness
            && $afterLast === $beforeLast,
        json_encode(['before_middle' => $beforeMiddle, 'after_middle' => $afterMiddle, 'before_last' => $beforeLast, 'after_last' => $afterLast])
    );

    $voidBase = $call('POST', '/api/coordinator/dex-audits/' . $baseAuditId . '/void', [
        'void_reason' => 'Anulación AUD-39-02 del volcado base A0',
    ], $coordinatorToken);
    $assert(
        '7.10 La anulación de la base responde 200 y VOIDED',
        $voidBase->getStatusCode() === 200 && ($data($voidBase)['status'] ?? '') === 'VOIDED',
        $errorCode($voidBase)
    );

    $lastWithoutActive = $data($call('GET', '/api/coordinator/dex-audits/' . $lastAuditId, [], $coordinatorToken));
    $assert(
        '7.11 Sin predecesor activo, el delta efectivo queda nulo y explícito',
        ($lastWithoutActive['previous_audit_voided'] ?? false) === true
            && array_key_exists('effective_previous_audit_id', $lastWithoutActive)
            && $lastWithoutActive['effective_previous_audit_id'] === null
            && array_key_exists('effective_delta_sales_eur', $lastWithoutActive)
            && $lastWithoutActive['effective_delta_sales_eur'] === null
            && array_key_exists('effective_delta_vend_units', $lastWithoutActive)
            && $lastWithoutActive['effective_delta_vend_units'] === null
            && ($lastWithoutActive['previous_audit_id'] ?? 0) === $middleAuditId
            && (float)($lastWithoutActive['delta_sales_eur'] ?? 0) === 7.0,
        json_encode($lastWithoutActive)
    );

    $listWithoutActive = $data($call('GET', '/api/coordinator/dex-audits', [], $coordinatorToken, [
        'machine_id' => (string)$secondMachineId,
    ]));
    $lastListWithoutActive = null;
    foreach (($listWithoutActive['audits'] ?? []) as $listedRow) {
        if ((int)($listedRow['id'] ?? 0) === $lastAuditId) {
            $lastListWithoutActive = $listedRow;
            break;
        }
    }
    $assert(
        '7.12 Sin cadena activa, el listado expone el bloque efectivo nulo y la bandera',
        is_array($lastListWithoutActive)
            && ($lastListWithoutActive['previous_audit_voided'] ?? false) === true
            && array_key_exists('effective_previous_audit_id', $lastListWithoutActive)
            && $lastListWithoutActive['effective_previous_audit_id'] === null
            && array_key_exists('effective_delta_sales_eur', $lastListWithoutActive)
            && $lastListWithoutActive['effective_delta_sales_eur'] === null
            && array_key_exists('effective_delta_vend_units', $lastListWithoutActive)
            && $lastListWithoutActive['effective_delta_vend_units'] === null,
        json_encode($lastListWithoutActive)
    );

    $csvWithoutActive = $call('GET', '/api/coordinator/dex-audits/export-csv', [], $coordinatorToken, [
        'machine_id' => (string)$secondMachineId,
    ]);
    $csvRowWithoutActive = $csvCellsFor($csvWithoutActive->getBody(), $lastAuditCode);
    $assert(
        '7.13 Sin delta vigente, el CSV deja las celdas vacías en vez del obsoleto',
        $csvWithoutActive->getStatusCode() === 200
            && is_array($csvRowWithoutActive)
            && array_key_exists(8, $csvRowWithoutActive)
            && $csvRowWithoutActive[8] === ''
            && array_key_exists(9, $csvRowWithoutActive)
            && $csvRowWithoutActive[9] === '',
        json_encode(['csv' => $csvRowWithoutActive])
    );

    echo "\n--- 8. Rastro MACHINE_DEX_AUDIT (AUD-39-03, Art. III.3) ---\n";
    $findAuditRow = static function (
        string $action,
        string $entityType,
        int $entityId,
        ?int $stateMachineId = null
    ) use ($pdo): ?array {
        // El escenario puede exportar varias veces; el filtro de perímetro deja
        // localizar la firma concreta en vez de asumir que la última es la suya.
        $stmt = $pdo->prepare(
            'SELECT entity_type, entity_id, action, user_id, user_role, user_name,
                    previous_state, new_state, metadata
             FROM audit_log
             WHERE action = :action AND entity_type = :entity_type AND entity_id = :entity_id'
            . ($stateMachineId === null ? '' : " AND new_state->>'machine_id' = :state_machine_id")
            . ' ORDER BY id DESC LIMIT 1'
        );
        $params = [
            ':action' => $action,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
        ];
        if ($stateMachineId !== null) {
            $params[':state_machine_id'] = (string)$stateMachineId;
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        foreach (['previous_state', 'new_state', 'metadata'] as $jsonColumn) {
            $row[$jsonColumn] = $row[$jsonColumn] === null ? null : json_decode((string)$row[$jsonColumn], true);
        }

        return $row;
    };

    $importAuditRow = $findAuditRow('DEX_AUDIT_IMPORTED', 'MACHINE_DEX_AUDIT', $auditId);
    $assert(
        '8.1 El alta escribe MACHINE_DEX_AUDIT con actor, estado y metadatos',
        $importAuditRow !== null
            && (int)($importAuditRow['user_id'] ?? 0) === $restockerId
            && ($importAuditRow['previous_state'] ?? null) === null
            && ($importAuditRow['new_state']['status'] ?? '') === 'ACTIVE'
            && ($importAuditRow['metadata']['audit_code'] ?? '') === $auditCode
            && (int)($importAuditRow['metadata']['machine_id'] ?? 0) === $machineId,
        json_encode($importAuditRow)
    );

    $linker = new DexAuditLinker(
        new PdoMachineDexAuditRepository($pdo),
        new AuditLogger(new PdoAuditLogRepository($pdo))
    );
    $linker->linkToRestockVisit(
        $manualAuditId,
        $machineId,
        $previousVisitId,
        ['id' => $coordinatorId, 'role' => 'COORDINATOR', 'name' => 'Coordinación DEX 09']
    );
    $linkAuditRow = $findAuditRow('DEX_AUDIT_LINKED', 'MACHINE_DEX_AUDIT', $manualAuditId);
    $assert(
        '8.2 El enlace escribe MACHINE_DEX_AUDIT con el destino y el actor',
        $linkAuditRow !== null
            && (int)($linkAuditRow['user_id'] ?? 0) === $coordinatorId
            && array_key_exists('restock_visit_id', $linkAuditRow['previous_state'] ?? [])
            && ($linkAuditRow['previous_state']['restock_visit_id'] ?? null) === null
            && (int)($linkAuditRow['new_state']['restock_visit_id'] ?? 0) === $previousVisitId
            && ($linkAuditRow['metadata']['audit_code'] ?? '') === $manualAuditCode
            && ($linkAuditRow['metadata']['target'] ?? '') === 'restock_visit'
            && (int)($linkAuditRow['metadata']['target_id'] ?? 0) === $previousVisitId,
        json_encode($linkAuditRow)
    );

    $voidAuditRow = $findAuditRow('DEX_AUDIT_VOIDED', 'MACHINE_DEX_AUDIT', $auditId);
    $assert(
        '8.3 La anulación deja de firmarse como MACHINE y conserva estado y motivo',
        $voidAuditRow !== null
            && (int)($voidAuditRow['user_id'] ?? 0) === $coordinatorId
            && ($voidAuditRow['previous_state']['status'] ?? '') === 'ACTIVE'
            && ($voidAuditRow['new_state']['status'] ?? '') === 'VOIDED'
            && ($voidAuditRow['metadata']['audit_code'] ?? '') === $auditCode
            && ($voidAuditRow['metadata']['void_reason'] ?? '') !== '',
        json_encode($voidAuditRow)
    );

    $csvAuditRow = $findAuditRow('EXPORT_DEX_AUDITS_CSV', 'MACHINE_DEX_AUDIT', 0, $machineId);
    $assert(
        '8.4 La exportación CSV registra su acción global con el perímetro aplicado',
        $csvAuditRow !== null
            && (int)($csvAuditRow['user_id'] ?? 0) === $coordinatorId
            && (int)($csvAuditRow['new_state']['machine_id'] ?? 0) === $machineId,
        json_encode($csvAuditRow)
    );

    $conflictAuditRow = $findAuditRow('OFFLINE_DEX_AUDIT_CONFLICT_DUMP', 'MACHINE', $machineId);
    $assert(
        '8.5 El conflict-dump Offline conserva la entidad MACHINE de C5',
        $conflictAuditRow !== null && (int)($conflictAuditRow['user_id'] ?? 0) === $restockerId,
        json_encode($conflictAuditRow)
    );

    echo "\n--- 9. Cableado real de M34/M35/M36 (AUD-39-03) ---\n";
    $evidenceDir = $projectRoot . '/public/uploads/incidents';
    if (!is_dir($evidenceDir) && !mkdir($evidenceDir, 0777, true) && !is_dir($evidenceDir)) {
        throw new RuntimeException('No se pudo preparar la evidencia de la sección 9.');
    }
    $evidenceFile = $evidenceDir . '/aud3903_escalation.png';
    file_put_contents(
        $evidenceFile,
        hex2bin('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000a49444154789c63000100000500010d0a2db40000000049454e44ae426082')
    );

    $importProductionAudit = static function (string $mutation, string $capturedAt, string $comm, ?int $targetMachineId = null) use (
        $call,
        $coordinatorToken,
        $machineId,
        $data
    ): int {
        $response = $call('POST', '/api/coordinator/machines/' . ($targetMachineId ?? $machineId) . '/dex-audits', [
            'raw_payload' => "DXS*{$comm}*VA*DEX 6.0\nID1*DEX9-FOOD-01\nID4*2\nVA1*3000*60\nDXE*FIN",
            'original_filename' => $mutation . '.dex',
            'captured_at' => $capturedAt,
            'client_mutation_id' => $mutation,
        ], $coordinatorToken);

        return $response->getStatusCode() === 201 ? (int)($data($response)['audit']['id'] ?? 0) : 0;
    };

    $m34AuditId = $importProductionAudit('aud3903-m34', '2026-11-24 14:00:00+00', 'COMM-AUD3903-M34');
    $m35AuditId = $importProductionAudit('aud3903-m35', '2026-11-24 15:00:00+00', 'COMM-AUD3903-M35');
    $m36AuditId = $importProductionAudit('aud3903-m36', '2026-11-24 16:00:00+00', 'COMM-AUD3903-M36');
    $assert(
        '9.0 Las tres auditorías de producción se importan ACTIVE',
        $m34AuditId > 0 && $m35AuditId > 0 && $m36AuditId > 0,
        json_encode(['m34' => $m34AuditId, 'm35' => $m35AuditId, 'm36' => $m36AuditId])
    );

    $linkedTarget = static function (string $column, int $auditId) use ($pdo): ?int {
        if (!in_array($column, ['restock_visit_id', 'cash_collection_id', 'incident_id'], true)) {
            throw new InvalidArgumentException('Columna de vínculo no permitida en el test.');
        }
        $stmt = $pdo->prepare("SELECT {$column} FROM machine_dex_audits WHERE id = :id");
        $stmt->execute([':id' => $auditId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (int)$value;
    };

    $m34Visit = $call('POST', '/api/restocker/machines/' . $machineId . '/visits', [
        'status' => 'COMPLETED',
        'cabin_temp_celsius' => 3.0,
        'client_mutation_id' => 'aud3903-m34-visit',
        'hygiene_checklist_json' => [
            'dispensing_tray_and_glass_cleaned' => true,
            'cabin_spills_checked' => true,
        ],
        'items' => [],
        'dex_audit_id' => $m34AuditId,
    ], $restockerToken);
    $m34VisitId = (int)($data($m34Visit)['id'] ?? 0);
    $m34LinkAudit = $findAuditRow('DEX_AUDIT_LINKED', 'MACHINE_DEX_AUDIT', $m34AuditId);
    $assert(
        '9.1 M34 enlaza la visita y firma DEX_AUDIT_LINKED por HTTP',
        $m34Visit->getStatusCode() === 201
            && $m34VisitId > 0
            && $linkedTarget('restock_visit_id', $m34AuditId) === $m34VisitId
            && $m34LinkAudit !== null
            && (int)($m34LinkAudit['user_id'] ?? 0) === $restockerId
            && ($m34LinkAudit['metadata']['target'] ?? '') === 'restock_visit'
            && (int)($m34LinkAudit['metadata']['target_id'] ?? 0) === $m34VisitId,
        json_encode(['status' => $m34Visit->getStatusCode(), 'error' => $errorCode($m34Visit), 'audit' => $m34LinkAudit])
    );

    $m35Collection = $call('POST', '/api/restocker/machines/' . $machineId . '/cash-collections', [
        'client_mutation_id' => 'aud3903-m35-cash',
        'bag_seal_code' => 'bag-aud3903-m35',
        'sales_counter_reading' => 120.00,
        'change_tubes_loaded_eur' => 10.00,
        'reception_cash_delivered_eur' => 5.00,
        'receptionist_name' => 'Conserje AUD 3903',
        'dex_audit_id' => $m35AuditId,
    ], $restockerToken);
    $m35CollectionId = (int)($data($m35Collection)['id'] ?? 0);
    $m35LinkAudit = $findAuditRow('DEX_AUDIT_LINKED', 'MACHINE_DEX_AUDIT', $m35AuditId);
    $assert(
        '9.2 M35 enlaza la recaudación y firma DEX_AUDIT_LINKED por HTTP',
        $m35Collection->getStatusCode() === 201
            && $m35CollectionId > 0
            && $linkedTarget('cash_collection_id', $m35AuditId) === $m35CollectionId
            && $m35LinkAudit !== null
            && (int)($m35LinkAudit['user_id'] ?? 0) === $restockerId
            && ($m35LinkAudit['metadata']['target'] ?? '') === 'cash_collection'
            && (int)($m35LinkAudit['metadata']['target_id'] ?? 0) === $m35CollectionId,
        json_encode(['status' => $m35Collection->getStatusCode(), 'error' => $errorCode($m35Collection), 'audit' => $m35LinkAudit])
    );

    $m36Escalation = $call('POST', '/api/restocker/machines/' . $machineId . '/escalate-to-sat', [
        'category' => 'PRODUCT_JAM',
        'workshop_internal_note' => 'El carril queda bloqueado y la espiral no gira al pulsar el botón.',
        'photo_path' => '/uploads/incidents/aud3903_escalation.png',
        'urgency' => 'HIGH',
        'client_mutation_id' => 'aud3903-m36-escalation',
        'client_occurred_at' => '2026-11-24 16:00:00+00',
        'dex_audit_id' => $m36AuditId,
    ], $restockerToken);
    $m36IncidentId = (int)($data($m36Escalation)['incident_id'] ?? 0);
    $m36LinkAudit = $findAuditRow('DEX_AUDIT_LINKED', 'MACHINE_DEX_AUDIT', $m36AuditId);
    $assert(
        '9.3 M36 enlaza el expediente SAT y firma DEX_AUDIT_LINKED por HTTP',
        $m36Escalation->getStatusCode() === 201
            && $m36IncidentId > 0
            && $linkedTarget('incident_id', $m36AuditId) === $m36IncidentId
            && $m36LinkAudit !== null
            && (int)($m36LinkAudit['user_id'] ?? 0) === $restockerId
            && ($m36LinkAudit['metadata']['target'] ?? '') === 'incident'
            && (int)($m36LinkAudit['metadata']['target_id'] ?? 0) === $m36IncidentId,
        json_encode(['status' => $m36Escalation->getStatusCode(), 'error' => $errorCode($m36Escalation), 'audit' => $m36LinkAudit])
    );

    echo "\n--- 10. Vínculo .dex del M16 (H25-01) ---\n";
    $incidentRepo = new PdoIncidentRepository($pdo);
    $anchor = $incidentRepo->create(
        new Incident(
            id: null,
            ticketCode: 'INC-2026-D9M16',
            machineId: $secondMachineId,
            locationId: $locationId,
            category: IncidentCategory::PRODUCT_JAM,
            description: 'Parada ancla ASSIGNED para habilitar la sede en el alta oportunista.',
            urgency: UrgencyLevel::MEDIUM,
            status: IncidentStatus::ASSIGNED,
            assignedTechnicianId: $technicianId,
            reporterName: 'Técnico DEX 09',
            assignedAt: '2026-11-24T08:00:00+00:00',
            cycleStartedAt: '2026-11-24T08:00:00+00:00'
        ),
        $technicianId,
        'Parada ancla de la certificación H25-01.'
    );
    $anchorId = (int)$anchor->getId();
    $assert('10.0 Parada ancla ASSIGNED creada para habilitar la sede', $anchorId > 0);

    $m16CreateAuditId = $importProductionAudit('h2501-create', '2026-11-24 17:00:00+00', 'COMM-H2501-CREATE', $fourthMachineId);
    $m16AssumeAuditId = $importProductionAudit('h2501-assume', '2026-11-24 17:05:00+00', 'COMM-H2501-ASSUME', $secondMachineId);
    $m16VoidedAuditId = $importProductionAudit('h2501-voided', '2026-11-24 17:10:00+00', 'COMM-H2501-VOIDED', $identityMachineId);
    $m16ForeignAuditId = $importProductionAudit('h2501-foreign', '2026-11-24 17:15:00+00', 'COMM-H2501-FOREIGN', $fourthMachineId);
    $assert(
        '10.1 Las cuatro auditorías del M16 se importan ACTIVE',
        $m16CreateAuditId > 0 && $m16AssumeAuditId > 0 && $m16VoidedAuditId > 0 && $m16ForeignAuditId > 0
    );

    $m16Create = $call('POST', '/api/technician/opportunistic/incidents', [
        'machine_id' => $fourthMachineId,
        'category' => 'PRODUCT_JAM',
        'origin_reason' => 'TECHNICIAN_DETECTION',
        'description' => 'Espiral bloqueada detectada al revisar el volcado .dex de la máquina.',
        'dex_audit_id' => $m16CreateAuditId,
    ], $technicianToken);
    $m16CreateData = $data($m16Create);
    $m16CreateIncidentId = (int)($m16CreateData['id'] ?? 0);
    $m16CreateLinkAudit = $findAuditRow('DEX_AUDIT_LINKED', 'MACHINE_DEX_AUDIT', $m16CreateAuditId);
    $assert(
        '10.2 El alta oportunista persiste machine_dex_audits.incident_id',
        $m16Create->getStatusCode() === 201
            && $m16CreateIncidentId > 0
            && $linkedTarget('incident_id', $m16CreateAuditId) === $m16CreateIncidentId
            && $m16CreateLinkAudit !== null
            && (int)($m16CreateLinkAudit['user_id'] ?? 0) === $technicianId
            && ($m16CreateLinkAudit['metadata']['target'] ?? '') === 'incident',
        json_encode(['status' => $m16Create->getStatusCode(), 'error' => $errorCode($m16Create), 'audit' => $m16CreateLinkAudit])
    );

    $m16Assume = $call('POST', '/api/technician/opportunistic/incidents/' . $anchorId . '/assume', [
        'dex_audit_id' => $m16AssumeAuditId,
    ], $technicianToken);
    $m16AssumeLinkAudit = $findAuditRow('DEX_AUDIT_LINKED', 'MACHINE_DEX_AUDIT', $m16AssumeAuditId);
    $assert(
        '10.3 La asunción oportunista persiste machine_dex_audits.incident_id',
        $m16Assume->getStatusCode() === 200
            && $linkedTarget('incident_id', $m16AssumeAuditId) === $anchorId
            && $m16AssumeLinkAudit !== null
            && (int)($m16AssumeLinkAudit['user_id'] ?? 0) === $technicianId,
        json_encode(['status' => $m16Assume->getStatusCode(), 'error' => $errorCode($m16Assume), 'audit' => $m16AssumeLinkAudit])
    );

    $voidedM16 = $call('POST', '/api/coordinator/dex-audits/' . $m16VoidedAuditId . '/void', [
        'void_reason' => 'Anulación previa a un intento de alta oportunista con vínculo inválido.',
    ], $coordinatorToken);
    $m16WithVoided = $call('POST', '/api/technician/opportunistic/incidents', [
        'machine_id' => $identityMachineId,
        'category' => 'PRODUCT_JAM',
        'origin_reason' => 'TECHNICIAN_DETECTION',
        'description' => 'Alta que declara un volcado anulado y no debe persistirse.',
        'dex_audit_id' => $m16VoidedAuditId,
    ], $technicianToken);
    $thirdMachineIncidents = $pdo->prepare('SELECT COUNT(*) FROM incidents WHERE machine_id = :id AND deleted_at IS NULL');
    $thirdMachineIncidents->execute([':id' => $identityMachineId]);
    $assert(
        '10.4 Un volcado anulado responde 409 y deshace el alta oportunista',
        $voidedM16->getStatusCode() === 200
            && $m16WithVoided->getStatusCode() === 409
            && $errorCode($m16WithVoided) === 'DEX_AUDIT_VOIDED'
            && (int)$thirdMachineIncidents->fetchColumn() === 0,
        json_encode(['status' => $m16WithVoided->getStatusCode(), 'error' => $errorCode($m16WithVoided)])
    );

    $m16WithForeign = $call('POST', '/api/technician/opportunistic/incidents', [
        'machine_id' => $identityMachineId,
        'category' => 'PRODUCT_JAM',
        'origin_reason' => 'TECHNICIAN_DETECTION',
        'description' => 'Alta que declara un volcado de otra máquina y no debe persistirse.',
        'dex_audit_id' => $m16ForeignAuditId,
    ], $technicianToken);
    $thirdMachineIncidents->execute([':id' => $identityMachineId]);
    $assert(
        '10.5 Un volcado de otra máquina responde 422 y deshace el alta oportunista',
        $m16WithForeign->getStatusCode() === 422
            && $errorCode($m16WithForeign) === 'DEX_AUDIT_MACHINE_MISMATCH'
            && (int)$thirdMachineIncidents->fetchColumn() === 0,
        json_encode(['status' => $m16WithForeign->getStatusCode(), 'error' => $errorCode($m16WithForeign)])
    );

    // Reapertura en garantía (RF-05.4.1): el expediente nace RESOLVED dentro de
    // las 48 h y el técnico lo reabre con el volcado que documenta la reincidencia.
    $reopenIncident = $incidentRepo->create(
        new Incident(
            id: null,
            ticketCode: 'INC-2026-D9M16R',
            machineId: $identityMachineId,
            locationId: $locationId,
            category: IncidentCategory::PRODUCT_JAM,
            description: 'Avería resuelta hace una hora que reincide durante la visita.',
            urgency: UrgencyLevel::MEDIUM,
            status: IncidentStatus::RESOLVED,
            assignedTechnicianId: $technicianId,
            reporterName: 'Técnico DEX 09',
            assignedAt: gmdate('Y-m-d\TH:i:sP', time() - 7200),
            startedAt: gmdate('Y-m-d\TH:i:sP', time() - 7200),
            resolvedAt: gmdate('Y-m-d\TH:i:sP', time() - 3600),
            resolutionDiagnosis: 'Diagnóstico de la reparación previa del mecanismo.',
            resolutionAction: 'Sustitución del muelle de la espiral en la visita anterior.'
        ),
        $technicianId,
        'Expediente resuelto para la reapertura en garantía H25-01.'
    );
    $reopenIncidentId = (int)$reopenIncident->getId();
    $m16ReopenAuditId = $importProductionAudit('h2501-reopen', '2026-11-24 17:20:00+00', 'COMM-H2501-REOPEN', $identityMachineId);
    $m16Reopen = $call('POST', '/api/technician/opportunistic/incidents/' . $reopenIncidentId . '/assume', [
        'reopen_reason' => 'Reincidencia del mismo atasco una hora después de la reparación.',
        'dex_audit_id' => $m16ReopenAuditId,
    ], $technicianToken);
    $m16ReopenData = $data($m16Reopen);
    $m16ReopenLinkAudit = $findAuditRow('DEX_AUDIT_LINKED', 'MACHINE_DEX_AUDIT', $m16ReopenAuditId);
    $assert(
        '10.6 La reapertura en garantía enlaza el expediente reabierto',
        $reopenIncidentId > 0
            && $m16ReopenAuditId > 0
            && $m16Reopen->getStatusCode() === 200
            && (int)($m16ReopenData['id'] ?? 0) === $reopenIncidentId
            && ($m16ReopenData['status'] ?? '') === 'IN_PROGRESS'
            && ($m16ReopenData['opportunistic_origin_reason'] ?? '') === 'WARRANTY_REOPEN_IN_SITU'
            && ($m16ReopenData['dex_audit_id'] ?? null) === $m16ReopenAuditId
            && $linkedTarget('incident_id', $m16ReopenAuditId) === $reopenIncidentId
            && $m16ReopenLinkAudit !== null
            && (int)($m16ReopenLinkAudit['user_id'] ?? 0) === $technicianId
            && (int)($m16ReopenLinkAudit['metadata']['target_id'] ?? 0) === $reopenIncidentId,
        json_encode(['status' => $m16Reopen->getStatusCode(), 'error' => $errorCode($m16Reopen), 'data' => $m16ReopenData, 'audit' => $m16ReopenLinkAudit])
    );

    echo "\n--- 11. Escalado M36 atómico con el vínculo .dex (H25-02) ---\n";
    // Anula el volcado justo en el INSERT del expediente: es la ventana exacta
    // entre la validación previa y el enlace posterior que denunciaba H25-02.
    $pdo->exec(<<<'SQL'
CREATE OR REPLACE FUNCTION h2502_void_dex_on_incident() RETURNS trigger AS $$
DECLARE
    target BIGINT;
    void_user BIGINT;
BEGIN
    target := NULLIF(current_setting('vendguard.h2502_dex', true), '')::bigint;
    IF target IS NOT NULL THEN
        SELECT imported_by INTO void_user FROM machine_dex_audits WHERE id = target;
        UPDATE machine_dex_audits
           SET status = 'VOIDED',
               void_reason = 'Anulación concurrente simulada durante el escalado atómico.',
               voided_by = void_user,
               voided_by_name_snapshot = 'Prueba H25-02',
               voided_at = CURRENT_TIMESTAMP
         WHERE id = target AND status = 'ACTIVE';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_h2502_void_dex
BEFORE INSERT ON incidents
FOR EACH ROW EXECUTE FUNCTION h2502_void_dex_on_incident();
SQL);
    $m36AtomicAuditId = $importProductionAudit('h2502-atomic', '2026-11-24 17:30:00+00', 'COMM-H2502-ATOMIC', $fifthMachineId);
    $pdo->exec("SET vendguard.h2502_dex = '" . $m36AtomicAuditId . "'");
    $m36Atomic = $call('POST', '/api/restocker/machines/' . $fifthMachineId . '/escalate-to-sat', [
        'category' => 'PRODUCT_JAM',
        'workshop_internal_note' => 'El carril queda bloqueado y la espiral no gira al pulsar el botón.',
        'photo_path' => '/uploads/incidents/aud3903_escalation.png',
        'urgency' => 'HIGH',
        'client_mutation_id' => 'h2502-atomic-escalation',
        'client_occurred_at' => '2026-11-24 17:30:00+00',
        'dex_audit_id' => $m36AtomicAuditId,
    ], $restockerToken);
    $pdo->exec("SET vendguard.h2502_dex = ''");
    $fifthMachineIncidents = $pdo->prepare('SELECT COUNT(*) FROM incidents WHERE machine_id = :id AND deleted_at IS NULL');
    $fifthMachineIncidents->execute([':id' => $fifthMachineId]);
    $atomicAuditState = $pdo->prepare('SELECT status, incident_id FROM machine_dex_audits WHERE id = :id');
    $atomicAuditState->execute([':id' => $m36AtomicAuditId]);
    $atomicAuditRow = $atomicAuditState->fetch(PDO::FETCH_ASSOC) ?: [];
    $fifthIncidentCount = (int)$fifthMachineIncidents->fetchColumn();
    $assert(
        '11.1 Un vínculo imposible deshace el ticket SAT y devuelve 409 DEX_AUDIT_VOIDED',
        $m36AtomicAuditId > 0
            && $m36Atomic->getStatusCode() === 409
            && $errorCode($m36Atomic) === 'DEX_AUDIT_VOIDED'
            && $fifthIncidentCount === 0
            && ($atomicAuditRow['status'] ?? '') === 'ACTIVE'
            && ($atomicAuditRow['incident_id'] ?? null) === null,
        json_encode([
            'status' => $m36Atomic->getStatusCode(),
            'error' => $errorCode($m36Atomic),
            'incidents' => $fifthIncidentCount,
            'audit' => $atomicAuditRow,
        ])
    );

    $exitCode = $failures === 0 ? 0 : 1;
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[EXCEPCIÓN] " . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    $exitCode = 1;
} finally {
    if (isset($evidenceFile) && is_file($evidenceFile)) {
        @unlink($evidenceFile);
    }
    if ($schemaReady) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, DEX09_API_SCHEMA);
        } catch (Throwable $exception) {
            fwrite(STDERR, "\n[RESIDUO] No se pudo borrar el esquema: " . $exception->getMessage() . "\n");
            $exitCode = 1;
        }
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. T-DEX-09 (API REST .dex) CERTIFICADO.\n"
    : " RESULTADO: FALLOS DETECTADOS EN LA API .DEX.\n";
echo "======================================================================\n";
exit($exitCode);
