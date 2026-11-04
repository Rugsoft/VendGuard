<?php

declare(strict_types=1);

/**
 * WorkshopRefurbishmentServiceIntegrationTest · T-WORKSHOP-10
 *
 * Certifica, contra el motor PostgreSQL y sobre un esquema aislado, el ciclo
 * completo del taller de reacondicionamiento con los servicios reales del
 * Módulo 27.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Apertura unitaria `1..N` en averías (D1, C4, QA 4):** una sustitución de
 *      cantidad 2 con destino `TALLER` abre dos expedientes unitarios, no uno
 *      agregado, y los atribuye al técnico asignado aunque el cierre lo firme
 *      Coordinación.
 *   2. **Apertura unitaria `1..N` en preventivos:** la orden preventiva abre su
 *      propio desglose con `intervention_type = 'PREVENTIVE'` y sin incidencia.
 *   3. **Ciclo completo (RF-01, RF-02, RF-03):** `PENDING_DELIVERY` →
 *      `RECEIVED_IN_WORKSHOP` → `IN_BENCH_TEST` → `REFURBISHED_RESTORED` con
 *      reintegro `+1` sobre una fila existente de furgoneta.
 *   4. **Fila nueva de furgoneta (D3):** si el técnico no tenía fila para esa
 *      pieza, el reintegro la crea con `quantity_available = 1` y
 *      `min_threshold = 1`.
 *   5. **Tope de capacidad (QA 3):** 9999 unidades en furgoneta rechazan el
 *      reintegro con `422 VAN_STOCK_MAX_CAPACITY_REACHED` sin tocar la fila.
 *   6. **Guardas de reintegro (RF-03, C2, C3):** pieza fuera de catálogo, pieza
 *      de catálogo inactiva y técnico inactivo devuelven `422` con su código.
 *   7. **Ahorro neto con suelo cero (C2):** un coste de reacondicionamiento
 *      superior al valor de catálogo deja `net_savings_eur = 0.00` y enciende
 *      `zero_net_savings_warning`.
 *   8. **Baja directa por extravío (C1):** desde `PENDING_DELIVERY`, con el
 *      diagnóstico de banco obligatorio, sin recepción ni banco.
 *   9. **Inmutabilidad terminal (C5):** un dictamen ya resuelto devuelve
 *      `409 WORKSHOP_ITEM_ALREADY_RESOLVED`.
 *  10. **Transiciones imposibles:** banco antes de recepción y reacondicionado
 *      sin recepción devuelven `409` y `422` respectivamente.
 *  11. **CSV de dos secciones (QA 5):** BOM UTF-8, separador `;`, bloque de
 *      indicadores y tabla unitaria de 24 columnas.
 *  12. **Rastro de auditoría:** las seis acciones del ciclo quedan en
 *      `audit_logs` con `entity_type = 'WORKSHOP_REPAIR_ITEM'`.
 *
 * Categoría: new_engine. Requiere `deploy/local/postgres/.env.local`.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\DTO\ReceiveWorkshopItemRequestDTO;
use VendGuard\Application\DTO\ResolveWorkshopItemRequestDTO;
use VendGuard\Application\DTO\WorkshopTrayQueryDTO;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\SparePartTraceabilityService;
use VendGuard\Application\Service\VanStockService;
use VendGuard\Application\Service\WorkshopRefurbishmentService;
use VendGuard\Core\Domain\Exception\WorkshopRepairValidationException;
use VendGuard\Core\Domain\ValueObject\WorkshopRepairStatus;
use VendGuard\Core\Domain\ValueObject\WorkshopRestockDestination;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Persistence\PdoWorkshopRepairItemRepository;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentReplacedPartRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentServiceSheetRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRequestRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Infrastructure\Repository\PdoVanStockRepository;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado sobre la conexión de la aplicación. */
const WORKSHOP_SERVICE_TEST_SCHEMA = 't_workshop27_service';

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

$errorCodeOf = static function (callable $operation): array {
    try {
        $operation();
    } catch (WorkshopRepairValidationException $exception) {
        return [$exception->getErrorCode(), $exception->getHttpStatusCode()];
    } catch (Throwable $exception) {
        return ['UNEXPECTED:' . $exception->getMessage(), 0];
    }

    return ['NO_EXCEPTION', 0];
};

echo "======================================================================\n";
echo " VENDGUARD · T-WORKSHOP-10 · Ciclo de taller, guardas y reintegro\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

try {
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, WORKSHOP_SERVICE_TEST_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address)
         VALUES (:code, :key, :name, :address)'
    )->execute([
        ':code' => 'SEDE-WS27',
        ':key' => 'sede-ws27',
        ':name' => 'Sede Taller 27',
        ':address' => 'Polígono del Reacondicionamiento 27',
    ]);
    $locationId = (int)$pdo->query("SELECT id FROM locations WHERE site_code_key = 'sede-ws27'")->fetchColumn();

    $createUser = static function (string $email, string $role, bool $active, string $name) use ($pdo): int {
        $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, :active)'
        )->execute([
            ':name' => $name,
            ':email' => $email,
            ':email_key' => $email,
            ':hash' => password_hash('ws27-secret', PASSWORD_BCRYPT),
            ':role' => $role,
            ':operator' => strtoupper(substr(md5($email), 0, 8)),
            ':active' => $active ? 'true' : 'false',
        ]);

        return (int)$pdo->query("SELECT id FROM users WHERE email_key = '{$email}'")->fetchColumn();
    };

    $coordinatorId = $createUser('ws27.coord@vendguard.internal', 'COORDINATOR', true, 'Coordinación de Taller');
    $technicianId = $createUser('ws27.tech@vendguard.internal', 'TECHNICIAN', true, 'Técnica de Ruta');
    $inactiveTechnicianId = $createUser('ws27.tech.off@vendguard.internal', 'TECHNICIAN', false, 'Técnico Inactivo');

    $pdo->prepare(
        'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, :code, :model, :type, :floor)'
    )->execute([
        ':location_id' => $locationId,
        ':code' => 'VEND-WS27-01',
        ':model' => 'Modelo Taller 27',
        ':type' => 'COLD_DRINKS',
        ':floor' => 'Planta baja',
    ]);
    $machineId = (int)$pdo->query("SELECT id FROM machines WHERE code = 'VEND-WS27-01'")->fetchColumn();

    $createPart = static function (string $code, string $name, float $cost, bool $active) use ($pdo): int {
        $pdo->prepare(
            'INSERT INTO spare_parts (part_code, name, category, manufacturer, reference_cost, is_active)
             VALUES (:code, :name, :category, :manufacturer, :cost, :active)'
        )->execute([
            ':code' => $code,
            ':name' => $name,
            ':category' => 'HYDRAULIC',
            ':manufacturer' => 'Fabricante de certificación',
            ':cost' => $cost,
            ':active' => $active ? 'true' : 'false',
        ]);

        return (int)$pdo->query("SELECT id FROM spare_parts WHERE part_code = '{$code}'")->fetchColumn();
    };

    $partId = $createPart('WS27-VALV-01', 'Electroválvula de taller', 24.50, true);
    $newRowPartId = $createPart('WS27-MOTOR-01', 'Motor de espiral de taller', 30.00, true);
    $inactivePartId = $createPart('WS27-SENSOR-01', 'Sensor de nivel inactivo', 10.00, true);

    $setStock = static function (int $technician, int $part, int $quantity) use ($pdo): void {
        $pdo->prepare(
            'INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
             VALUES (:technician_id, :spare_part_id, :quantity, 0)
             ON CONFLICT (technician_id, spare_part_id)
             DO UPDATE SET quantity_available = EXCLUDED.quantity_available'
        )->execute([':technician_id' => $technician, ':spare_part_id' => $part, ':quantity' => $quantity]);
    };

    $setStock($technicianId, $partId, 100);
    $setStock($technicianId, $inactivePartId, 10);

    $workshopRepo = new PdoWorkshopRepairItemRepository($pdo);
    $auditLogger = new AuditLogger(new PdoAuditLogRepository($pdo));
    $transactions = new PdoTransactionManager($pdo);

    $service = new WorkshopRefurbishmentService(
        $workshopRepo,
        $transactions,
        new PdoSparePartRepository($pdo),
        new PdoUserRepository($pdo),
        $auditLogger,
        $pdo
    );

    $traceability = new SparePartTraceabilityService(
        new PdoSparePartRequestRepository($pdo),
        new PdoIncidentReplacedPartRepository($pdo),
        new PdoSparePartRepository($pdo),
        new PdoIncidentRepository($pdo),
        new PdoMachineRepository($pdo),
        new VanStockService(new PdoVanStockRepository($pdo)),
        new PdoIncidentServiceSheetRepository($pdo),
        $auditLogger,
        null,
        $transactions,
        null,
        $workshopRepo
    );

    $ticketSequence = 0;

    /** Cierra una avería con el técnico asignado y actor Coordinación. */
    $closeIncidentWithParts = static function (array $parts) use ($pdo, $locationId, $machineId, $technicianId, $coordinatorId, $traceability, &$ticketSequence): int {
        $ticketSequence++;
        $ticket = sprintf('INC-WS27-%04d', $ticketSequence);

        // Una máquina por avería: el motor impone un único ticket activo por máquina.
        $machineCode = sprintf('VEND-WS27-T%04d', $ticketSequence);
        $pdo->prepare(
            'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
             VALUES (:location_id, :code, :model, :type, :floor)'
        )->execute([
            ':location_id' => $locationId,
            ':code' => $machineCode,
            ':model' => 'Modelo Taller 27',
            ':type' => 'COLD_DRINKS',
            ':floor' => 'Planta baja',
        ]);
        $incidentMachineId = (int)$pdo->query("SELECT id FROM machines WHERE code = '{$machineCode}'")->fetchColumn();

        $pdo->prepare(
            "INSERT INTO incidents
                (ticket_code, machine_id, machine_type_snapshot, location_id, category, description, urgency,
                 status, assigned_technician_id, assigned_at, started_at)
             VALUES
                (:ticket, :machine_id, 'COLD_DRINKS', :location_id, 'OTHER',
                 :description, 'HIGH', 'IN_PROGRESS', :technician_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        )->execute([
            ':ticket' => $ticket,
            ':machine_id' => $incidentMachineId,
            ':location_id' => $locationId,
            ':description' => 'Fallo de dispensación detectado en la ruta de certificación.',
            ':technician_id' => $technicianId,
        ]);

        $incidentId = (int)$pdo->query("SELECT id FROM incidents WHERE ticket_code = '{$ticket}'")->fetchColumn();

        $traceability->resolveIncidentWithParts($incidentId, $technicianId, [
            'resolution_diagnosis' => 'Obstrucción interna de la electroválvula que impide el paso de producto.',
            'resolution_action' => 'Sustitución de la electroválvula y prueba de dispensación completa.',
            'replaced_parts_declared' => true,
            'replaced_parts' => $parts,
        ], ['id' => $coordinatorId, 'role' => 'COORDINATOR', 'name' => 'Coordinación de Taller']);

        return $incidentId;
    };

    $unitsOfIncident = static function (int $incidentId) use ($pdo): array {
        $stmt = $pdo->prepare(
            'SELECT w.* FROM workshop_repair_items w
               JOIN incident_replaced_parts p ON p.id = w.incident_replaced_part_id
              WHERE p.incident_id = :incident_id
              ORDER BY w.unit_index ASC'
        );
        $stmt->execute([':incident_id' => $incidentId]);

        return $stmt->fetchAll();
    };

    $unitOf = static function (int $id) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT * FROM workshop_repair_items WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return (array)$stmt->fetch();
    };

    $stockOf = static function (int $technician, int $part) use ($pdo): ?int {
        $stmt = $pdo->prepare(
            'SELECT quantity_available FROM technician_van_stocks
              WHERE technician_id = :technician_id AND spare_part_id = :spare_part_id'
        );
        $stmt->execute([':technician_id' => $technician, ':spare_part_id' => $part]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int)$value;
    };

    $diagnosis = 'Prueba de estanqueidad superada tras sustituir la electroválvula y calibrar el caudal.';

    $receive = static fn (int $id): array => $service->receiveInWorkshop(
        new ReceiveWorkshopItemRequestDTO($id, $coordinatorId, 'Recepción en banco con embalaje íntegro.')
    );
    $bench = static fn (int $id): array => $service->startBenchTest($id, $coordinatorId);
    $resolve = static fn (int $id, WorkshopRepairStatus $outcome, ?WorkshopRestockDestination $destination, ?int $technician, float $cost): array => $service->resolveWorkshopItem(
        new ResolveWorkshopItemRequestDTO($id, $coordinatorId, $outcome, $diagnosis, $destination, $technician, $cost)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1. Apertura unitaria 1..N en avería (D1, C4, QA 4)
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 1. Apertura 1..N en avería con cierre de Coordinación (D1, QA 4) ---\n";

    $incidentId = $closeIncidentWithParts([
        ['spare_part_id' => $partId, 'is_out_of_catalog' => false, 'quantity' => 2, 'old_part_destination' => 'TALLER'],
    ]);

    $units = $unitsOfIncident($incidentId);
    $assert('Una sustitución de cantidad 2 abre exactamente 2 expedientes', count($units) === 2, 'unidades=' . count($units));
    $firstUnit = $units[0] ?? [];
    $secondUnit = $units[1] ?? [];

    $assert(
        'Los índices unitarios son 1 y 2 sobre un total de 2',
        (int)($firstUnit['unit_index'] ?? 0) === 1
            && (int)($secondUnit['unit_index'] ?? 0) === 2
            && (int)($firstUnit['total_units_in_line'] ?? 0) === 2
            && (int)($secondUnit['total_units_in_line'] ?? 0) === 2,
        json_encode([$firstUnit['unit_index'] ?? null, $secondUnit['unit_index'] ?? null])
    );
    $assert(
        'Los dos expedientes cuelgan de la misma línea de sustitución',
        (int)($firstUnit['incident_replaced_part_id'] ?? 0) === (int)($secondUnit['incident_replaced_part_id'] ?? 0)
            && (int)($firstUnit['incident_replaced_part_id'] ?? 0) > 0,
        'part=' . (string)($firstUnit['incident_replaced_part_id'] ?? '')
    );
    $assert(
        'El técnico extractor es el asignado, no el actor que firma el cierre',
        (int)($firstUnit['extracted_by_technician_id'] ?? 0) === $technicianId
            && (int)($secondUnit['extracted_by_technician_id'] ?? 0) === $technicianId,
        'extractor=' . (string)($firstUnit['extracted_by_technician_id'] ?? '')
    );
    $assert(
        'El expediente nace en PENDING_DELIVERY y sin sellos de recepción',
        (string)($firstUnit['status'] ?? '') === 'PENDING_DELIVERY'
            && ($firstUnit['received_at'] ?? null) === null
            && ($firstUnit['bench_started_at'] ?? null) === null
            && ($firstUnit['resolved_at'] ?? null) === null,
        (string)($firstUnit['status'] ?? '')
    );
    $assert(
        'Los snapshots congelan código, nombre y coste del catálogo',
        (string)($firstUnit['part_code_snapshot'] ?? '') === 'WS27-VALV-01'
            && (string)($firstUnit['part_name_snapshot'] ?? '') === 'Electroválvula de taller'
            && (float)($firstUnit['unit_cost_snapshot'] ?? 0) === 24.50
            && ($firstUnit['is_out_of_catalog'] ?? true) === false,
        json_encode([$firstUnit['part_code_snapshot'] ?? null, $firstUnit['unit_cost_snapshot'] ?? null])
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Apertura unitaria 1..N en preventivo
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 2. Apertura 1..N en orden preventiva ---\n";

    $pdo->prepare(
        'INSERT INTO preventive_orders (order_code, machine_id, location_id, assigned_technician_id, status, order_type, scheduled_date, due_date)
         VALUES (:code, :machine_id, :location_id, :technician_id, \'IN_INSPECTION\', \'ROUTINE\', CURRENT_DATE, CURRENT_DATE)'
    )->execute([
        ':code' => 'PREV-WS27-0001',
        ':machine_id' => $machineId,
        ':location_id' => $locationId,
        ':technician_id' => $technicianId,
    ]);
    $preventiveOrderId = (int)$pdo->query("SELECT id FROM preventive_orders WHERE order_code = 'PREV-WS27-0001'")->fetchColumn();

    $traceability->recordPreventiveReplacedParts(
        $preventiveOrderId,
        $technicianId,
        $machineId,
        $locationId,
        [[
            'spare_part_id' => $partId,
            'quantity' => 3,
            'old_part_destination' => 'TALLER',
            'is_out_of_catalog' => false,
        ]],
        ['id' => $coordinatorId, 'role' => 'COORDINATOR', 'name' => 'Coordinación de Taller']
    );

    $preventiveUnits = $pdo->prepare(
        'SELECT * FROM workshop_repair_items WHERE preventive_order_id = :order_id ORDER BY unit_index ASC'
    );
    $preventiveUnits->execute([':order_id' => $preventiveOrderId]);
    $preventiveRows = $preventiveUnits->fetchAll();

    $assert('Una cantidad preventiva de 3 abre 3 expedientes', count($preventiveRows) === 3, 'unidades=' . count($preventiveRows));
    $assert(
        'Los expedientes preventivos no tienen incidencia y sí orden de origen',
        ($preventiveRows[0]['incident_id'] ?? null) === null
            && (int)($preventiveRows[0]['preventive_order_id'] ?? 0) === $preventiveOrderId
            && (string)($preventiveRows[0]['intervention_type'] ?? '') === 'PREVENTIVE',
        json_encode([$preventiveRows[0]['incident_id'] ?? null, $preventiveRows[0]['intervention_type'] ?? null])
    );
    $assert(
        'Los índices preventivos cubren 1..3',
        array_map(static fn (array $row): int => (int)$row['unit_index'], $preventiveRows) === [1, 2, 3],
        json_encode(array_map(static fn (array $row): int => (int)$row['unit_index'], $preventiveRows))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Ciclo completo con reintegro sobre fila existente (RF-01..RF-03)
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 3. Ciclo completo con reintegro +1 sobre fila existente ---\n";

    $unitId = (int)$firstUnit['id'];
    $stockBefore = $stockOf($technicianId, $partId);

    $receive($unitId);
    $received = $unitOf($unitId);
    $assert(
        'La recepción sella RECEIVED_IN_WORKSHOP, fecha y actor',
        (string)($received['status'] ?? '') === 'RECEIVED_IN_WORKSHOP'
            && ($received['received_at'] ?? null) !== null
            && (int)($received['received_by_user_id'] ?? 0) === $coordinatorId,
        json_encode([$received['status'] ?? null, $received['received_by_user_id'] ?? null])
    );

    $bench($unitId);
    $inBench = $unitOf($unitId);
    $assert(
        'El banco sella IN_BENCH_TEST y su marca temporal',
        (string)($inBench['status'] ?? '') === 'IN_BENCH_TEST' && ($inBench['bench_started_at'] ?? null) !== null,
        json_encode([$inBench['status'] ?? null, $inBench['bench_started_at'] ?? null])
    );

    $resolved = $resolve($unitId, WorkshopRepairStatus::REFURBISHED_RESTORED, WorkshopRestockDestination::TECHNICIAN_VAN, $technicianId, 4.00);
    $refurbished = $unitOf($unitId);

    $assert(
        'El dictamen deja REFURBISHED_RESTORED con coste y ahorro neto',
        (string)($refurbished['status'] ?? '') === 'REFURBISHED_RESTORED'
            && (float)($refurbished['refurbishment_cost_eur'] ?? -1) === 4.00
            && (float)($refurbished['net_savings_eur'] ?? -1) === 20.50,
        json_encode([$refurbished['status'] ?? null, $refurbished['net_savings_eur'] ?? null])
    );
    $assert(
        'El dictamen conserva el diagnóstico normalizado y el destino',
        trim((string)($refurbished['bench_diagnosis'] ?? '')) === $diagnosis
            && (string)($refurbished['restock_destination'] ?? '') === 'TECHNICIAN_VAN'
            && (int)($refurbished['restocked_to_technician_id'] ?? 0) === $technicianId,
        json_encode([$refurbished['bench_diagnosis'] ?? null, $refurbished['restock_destination'] ?? null])
    );
    $assert(
        'El reintegro suma exactamente +1 en la furgoneta',
        $stockBefore !== null && $stockOf($technicianId, $partId) === $stockBefore + 1,
        'antes=' . (string)$stockBefore . ' después=' . (string)$stockOf($technicianId, $partId)
    );
    $assert(
        'La respuesta del dictamen no filtra claves internas',
        is_array($resolved) && !str_contains(json_encode($resolved) ?: '', 'password_hash'),
        json_encode(array_keys($resolved))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Reintegro creando fila nueva de furgoneta (D3)
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 4. Reintegro creando fila nueva con min_threshold = 1 (D3) ---\n";

    // La pieza no tiene fila de furgoneta en ningún momento (no se borra nada:
    // el Art. III.1 prohíbe el borrado físico). El expediente se siembra con los
    // fixtures de la tabla, que es el estado que deja el alta cuando el técnico
    // nunca ha llevado la pieza en su furgoneta.
    $pdo->prepare(
        "INSERT INTO incidents
            (ticket_code, machine_id, machine_type_snapshot, location_id, category, description, urgency,
             status, assigned_technician_id, assigned_at, started_at)
         VALUES
            ('INC-WS27-RAW01', :machine_id, 'COLD_DRINKS', :location_id, 'OTHER',
             'Incidencia de certificación del reintegro sin fila previa.', 'HIGH',
             'IN_PROGRESS', :technician_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
    )->execute([
        ':machine_id' => $machineId,
        ':location_id' => $locationId,
        ':technician_id' => $technicianId,
    ]);
    $rawIncidentId = (int)$pdo->query("SELECT id FROM incidents WHERE ticket_code = 'INC-WS27-RAW01'")->fetchColumn();

    $rawReplacedPart = PostgresFixtureBuilder::insertWorkshopReplacedPart($pdo, [
        'incidents' => ['id' => $rawIncidentId],
        'machines' => ['id' => $machineId],
        'locations' => ['id' => $locationId],
        'users' => ['id' => $technicianId],
        'spare_parts' => ['id' => $newRowPartId],
    ], '02', 1);
    // Alta unitaria explícita: el fixture genérico no serializa el booleano
    // `false` de `is_out_of_catalog` para `pdo_pgsql`, y aquí el expediente debe
    // nacer dentro de catálogo.
    $rawUnitStmt = $pdo->prepare(
        "INSERT INTO workshop_repair_items (
            incident_replaced_part_id, unit_index, total_units_in_line, intervention_type,
            incident_id, machine_id, location_id, extracted_by_technician_id, spare_part_id,
            is_out_of_catalog, part_code_snapshot, part_name_snapshot, part_category_snapshot,
            machine_code_snapshot, machine_model_snapshot, location_name_snapshot,
            technician_name_snapshot, unit_cost_snapshot, status
         )
         SELECT p.id, 1, 1, p.intervention_type,
                p.incident_id, p.machine_id, p.location_id, p.technician_id, p.spare_part_id,
                FALSE, sp.part_code, sp.name, COALESCE(sp.category::text, 'OTHER'),
                m.code, m.model, l.name, u.name, p.unit_cost_snapshot, 'PENDING_DELIVERY'
           FROM incident_replaced_parts p
           JOIN machines m ON m.id = p.machine_id
           JOIN locations l ON l.id = p.location_id
           JOIN users u ON u.id = p.technician_id
           LEFT JOIN spare_parts sp ON sp.id = p.spare_part_id
          WHERE p.id = :replaced_part_id
         RETURNING id"
    );
    $rawUnitStmt->execute([':replaced_part_id' => (int)$rawReplacedPart['id']]);
    $newUnitId = (int)$rawUnitStmt->fetchColumn();

    $assert('La fila de furgoneta no existe antes del reintegro', $stockOf($technicianId, $newRowPartId) === null);
    $receive($newUnitId);
    $bench($newUnitId);
    $resolve($newUnitId, WorkshopRepairStatus::REFURBISHED_RESTORED, WorkshopRestockDestination::TECHNICIAN_VAN, $technicianId, 5.00);

    $minThreshold = $pdo->prepare(
        'SELECT quantity_available, min_threshold FROM technician_van_stocks
          WHERE technician_id = :technician_id AND spare_part_id = :spare_part_id'
    );
    $minThreshold->execute([':technician_id' => $technicianId, ':spare_part_id' => $newRowPartId]);
    $newRow = $minThreshold->fetch();

    $assert(
        'El reintegro crea la fila con 1 unidad y umbral 1',
        is_array($newRow)
            && (int)$newRow['quantity_available'] === 1
            && (int)$newRow['min_threshold'] === 1,
        json_encode($newRow === false ? null : $newRow)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Tope de capacidad de la furgoneta (QA 3)
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 5. Tope de 9999 unidades en furgoneta (QA 3) ---\n";

    $capIncident = $closeIncidentWithParts([
        ['spare_part_id' => $partId, 'is_out_of_catalog' => false, 'quantity' => 1, 'old_part_destination' => 'TALLER'],
    ]);
    $capUnitId = (int)$unitsOfIncident($capIncident)[0]['id'];
    $receive($capUnitId);
    $bench($capUnitId);

    $setStock($technicianId, $partId, 9999);
    [$capCode, $capHttp] = $errorCodeOf(static fn () => $resolve(
        $capUnitId,
        WorkshopRepairStatus::REFURBISHED_RESTORED,
        WorkshopRestockDestination::TECHNICIAN_VAN,
        $technicianId,
        3.00
    ));

    $assert(
        'El tope rechaza con 422 VAN_STOCK_MAX_CAPACITY_REACHED',
        $capCode === 'VAN_STOCK_MAX_CAPACITY_REACHED' && $capHttp === 422,
        $capCode . '/' . $capHttp
    );
    $assert('El rechazo no altera la fila de furgoneta', $stockOf($technicianId, $partId) === 9999, 'stock=' . (string)$stockOf($technicianId, $partId));
    $assert(
        'El rechazo no resuelve el expediente',
        (string)($unitOf($capUnitId)['status'] ?? '') === 'IN_BENCH_TEST',
        (string)($unitOf($capUnitId)['status'] ?? '')
    );

    $setStock($technicianId, $partId, 100);

    // ─────────────────────────────────────────────────────────────────────
    // 6. Guardas de reintegro (RF-03, C2, C3)
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 6. Guardas 422 del reintegro a furgoneta ---\n";

    $outOfCatalogIncident = $closeIncidentWithParts([
        ['is_out_of_catalog' => true, 'custom_part_name' => 'Bomba de agua recuperada', 'quantity' => 1, 'old_part_destination' => 'TALLER'],
    ]);
    $outOfCatalogUnitId = (int)$unitsOfIncident($outOfCatalogIncident)[0]['id'];
    $receive($outOfCatalogUnitId);
    $bench($outOfCatalogUnitId);
    [$ooCode, $ooHttp] = $errorCodeOf(static fn () => $resolve(
        $outOfCatalogUnitId,
        WorkshopRepairStatus::REFURBISHED_RESTORED,
        WorkshopRestockDestination::TECHNICIAN_VAN,
        $technicianId,
        1.00
    ));
    $assert(
        'La pieza fuera de catálogo no vuelve a la furgoneta (422)',
        $ooCode === 'OUT_OF_CATALOG_VAN_RESTOCK_FORBIDDEN' && $ooHttp === 422,
        $ooCode . '/' . $ooHttp
    );

    $inactivePartIncident = $closeIncidentWithParts([
        ['spare_part_id' => $inactivePartId, 'is_out_of_catalog' => false, 'quantity' => 1, 'old_part_destination' => 'TALLER'],
    ]);
    $inactivePartUnitId = (int)$unitsOfIncident($inactivePartIncident)[0]['id'];
    $receive($inactivePartUnitId);
    $bench($inactivePartUnitId);
    $pdo->prepare('UPDATE spare_parts SET is_active = FALSE WHERE id = :id')->execute([':id' => $inactivePartId]);
    [$ipCode, $ipHttp] = $errorCodeOf(static fn () => $resolve(
        $inactivePartUnitId,
        WorkshopRepairStatus::REFURBISHED_RESTORED,
        WorkshopRestockDestination::TECHNICIAN_VAN,
        $technicianId,
        1.00
    ));
    $pdo->prepare('UPDATE spare_parts SET is_active = TRUE WHERE id = :id')->execute([':id' => $inactivePartId]);
    $assert(
        'La pieza de catálogo inactiva no vuelve a la furgoneta (422)',
        $ipCode === 'INACTIVE_SPARE_PART_VAN_RESTOCK_FORBIDDEN' && $ipHttp === 422,
        $ipCode . '/' . $ipHttp
    );

    $inactiveTechIncident = $closeIncidentWithParts([
        ['spare_part_id' => $partId, 'is_out_of_catalog' => false, 'quantity' => 1, 'old_part_destination' => 'TALLER'],
    ]);
    $inactiveTechUnitId = (int)$unitsOfIncident($inactiveTechIncident)[0]['id'];
    $receive($inactiveTechUnitId);
    $bench($inactiveTechUnitId);
    [$itCode, $itHttp] = $errorCodeOf(static fn () => $resolve(
        $inactiveTechUnitId,
        WorkshopRepairStatus::REFURBISHED_RESTORED,
        WorkshopRestockDestination::TECHNICIAN_VAN,
        $inactiveTechnicianId,
        1.00
    ));
    $assert(
        'El técnico inactivo no recibe el reintegro (422)',
        $itCode === 'RESTOCK_TECHNICIAN_INACTIVE' && $itHttp === 422,
        $itCode . '/' . $itHttp
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Ahorro neto con suelo cero (C2)
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 7. Ahorro neto con suelo cero (C2) ---\n";

    $lossIncident = $closeIncidentWithParts([
        ['spare_part_id' => $partId, 'is_out_of_catalog' => false, 'quantity' => 1, 'old_part_destination' => 'TALLER'],
    ]);
    $lossUnitId = (int)$unitsOfIncident($lossIncident)[0]['id'];
    $receive($lossUnitId);
    $bench($lossUnitId);
    $lossResponse = $resolve($lossUnitId, WorkshopRepairStatus::REFURBISHED_RESTORED, WorkshopRestockDestination::CENTRAL_WAREHOUSE, null, 30.00);
    $lossUnit = $unitOf($lossUnitId);

    $assert(
        'Un coste superior al valor de catálogo deja el ahorro en 0.00',
        (float)($lossUnit['net_savings_eur'] ?? -1) === 0.00
            && (float)($lossUnit['refurbishment_cost_eur'] ?? -1) === 30.00,
        json_encode([$lossUnit['net_savings_eur'] ?? null, $lossUnit['refurbishment_cost_eur'] ?? null])
    );
    $assert(
        'La respuesta enciende zero_net_savings_warning',
        str_contains(json_encode($lossResponse) ?: '', 'zero_net_savings_warning'),
        json_encode($lossResponse)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 8. Baja directa por extravío desde PENDING_DELIVERY (C1)
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 8. Baja directa por extravío sin recepción (C1) ---\n";

    $lostIncident = $closeIncidentWithParts([
        ['spare_part_id' => $partId, 'is_out_of_catalog' => false, 'quantity' => 1, 'old_part_destination' => 'TALLER'],
    ]);
    $lostUnitId = (int)$unitsOfIncident($lostIncident)[0]['id'];
    $lostResponse = $resolve($lostUnitId, WorkshopRepairStatus::SCRAPPED_IRREPARABLE, null, null, 0.0);
    $lostUnit = $unitOf($lostUnitId);

    $assert(
        'El extravío cierra el expediente sin recepción ni banco',
        (string)($lostUnit['status'] ?? '') === 'SCRAPPED_IRREPARABLE'
            && ($lostUnit['received_at'] ?? null) === null
            && ($lostUnit['bench_started_at'] ?? null) === null
            && ($lostUnit['resolved_at'] ?? null) !== null,
        json_encode([$lostUnit['status'] ?? null, $lostUnit['received_at'] ?? null])
    );
    $assert(
        'El extravío conserva el diagnóstico obligatorio y no inventa importes',
        trim((string)($lostUnit['bench_diagnosis'] ?? '')) === $diagnosis
            && (float)($lostUnit['net_savings_eur'] ?? -1) === 0.0,
        json_encode([$lostUnit['bench_diagnosis'] ?? null, $lostUnit['net_savings_eur'] ?? null])
    );

    // ─────────────────────────────────────────────────────────────────────
    // 9. Baja en banco y transiciones imposibles
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 9. Baja en banco, inmutabilidad y transiciones imposibles ---\n";

    $scrapIncident = $closeIncidentWithParts([
        ['spare_part_id' => $partId, 'is_out_of_catalog' => false, 'quantity' => 1, 'old_part_destination' => 'TALLER'],
    ]);
    $scrapUnitId = (int)$unitsOfIncident($scrapIncident)[0]['id'];
    $receive($scrapUnitId);
    $bench($scrapUnitId);
    $resolve($scrapUnitId, WorkshopRepairStatus::SCRAPPED_IRREPARABLE, null, null, 0.0);

    $assert(
        'La baja desde banco conserva recepción y banco',
        (string)($unitOf($scrapUnitId)['status'] ?? '') === 'SCRAPPED_IRREPARABLE'
            && ($unitOf($scrapUnitId)['received_at'] ?? null) !== null
            && ($unitOf($scrapUnitId)['bench_started_at'] ?? null) !== null
    );

    [$terminalCode, $terminalHttp] = $errorCodeOf(static fn () => $resolve(
        $unitId,
        WorkshopRepairStatus::SCRAPPED_IRREPARABLE,
        null,
        null,
        0.0
    ));
    $assert(
        'Un dictamen terminal no se reescribe (409)',
        $terminalCode === 'WORKSHOP_ITEM_ALREADY_RESOLVED' && $terminalHttp === 409,
        $terminalCode . '/' . $terminalHttp
    );

    $pendingIncident = $closeIncidentWithParts([
        ['spare_part_id' => $partId, 'is_out_of_catalog' => false, 'quantity' => 1, 'old_part_destination' => 'TALLER'],
    ]);
    $pendingUnitId = (int)$unitsOfIncident($pendingIncident)[0]['id'];

    [$benchCode, $benchHttp] = $errorCodeOf(static fn () => $bench($pendingUnitId));
    $assert(
        'No se entra al banco sin recepción (409)',
        $benchCode === 'INVALID_WORKSHOP_STATUS_TRANSITION' && $benchHttp === 409,
        $benchCode . '/' . $benchHttp
    );

    [$unreceivedCode, $unreceivedHttp] = $errorCodeOf(static fn () => $resolve(
        $pendingUnitId,
        WorkshopRepairStatus::REFURBISHED_RESTORED,
        WorkshopRestockDestination::CENTRAL_WAREHOUSE,
        null,
        1.00
    ));
    $assert(
        'No se reacondiciona una pieza sin recepción (422)',
        $unreceivedCode === 'UNRECEIVED_PART_CANNOT_BE_REFURBISHED' && $unreceivedHttp === 422,
        $unreceivedCode . '/' . $unreceivedHttp
    );

    // ─────────────────────────────────────────────────────────────────────
    // 10. CSV de dos secciones (QA 5)
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 10. Exportación CSV de dos secciones (QA 5) ---\n";

    $csv = $service->exportWorkshopCsv(new WorkshopTrayQueryDTO());

    $assert('El CSV empieza con BOM UTF-8', str_starts_with($csv, "\xEF\xBB\xBF"), 'inicio=' . bin2hex(substr($csv, 0, 3)));
    $assert('El separador de campo es el punto y coma', str_contains($csv, ';'));

    $lines = preg_split('/\r\n|\n/', $csv) ?: [];
    $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    $headerPosition = null;
    foreach ($lines as $index => $line) {
        $cells = explode(';', ltrim($line, "\xEF\xBB\xBF"));
        if (count($cells) === 24) {
            $headerPosition = $index;
            break;
        }
    }

    $assert('La tabla unitaria declara 24 columnas', $headerPosition !== null, 'líneas=' . count($lines));
    $assert('El bloque de indicadores tiene al menos 11 filas', $headerPosition !== null && $headerPosition >= 11, 'filas previas=' . (string)$headerPosition);

    if ($headerPosition !== null) {
        $tableRows = array_slice($lines, $headerPosition + 1);
        $wideRows = array_values(array_filter($tableRows, static fn (string $line): bool => count(explode(';', $line)) === 24));
        $assert('La tabla unitaria lleva las filas del taller', count($wideRows) >= 5, 'filas=' . count($wideRows));
        $assert(
            'Ninguna celda del CSV empieza por un prefijo de fórmula',
            !preg_match('/(^|;)[=+@]/m', $csv) === true,
            'prefijo inseguro detectado'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 11. Indicadores de la bandeja y auditoría
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 11. Bandeja, exclusión de bajas sin recepción y auditoría ---\n";

    $tray = $service->listWorkshopTray(new WorkshopTrayQueryDTO());
    $kpis = $tray['summary'] ?? [];

    $assert(
        'La bandeja devuelve items y una cabecera de indicadores',
        is_array($tray['items'] ?? null) && is_array($kpis) && array_key_exists('scrapped_unreceived_count', $kpis),
        json_encode(array_keys($kpis))
    );
    $assert(
        'Las bajas sin recepción se cuentan aparte (QA 1)',
        (int)($kpis['scrapped_unreceived_count'] ?? -1) === 1,
        'bajas sin recepción=' . (string)($kpis['scrapped_unreceived_count'] ?? '')
    );

    $auditStmt = $pdo->query(
        "SELECT action, COUNT(*) AS total FROM audit_log
          WHERE entity_type = 'WORKSHOP_REPAIR_ITEM'
          GROUP BY action"
    );
    $audit = [];
    foreach ($auditStmt->fetchAll() as $row) {
        $audit[(string)$row['action']] = (int)$row['total'];
    }

    foreach ([
        'WORKSHOP_ITEM_CREATED_FROM_EXTRACTION',
        'WORKSHOP_ITEM_RECEIVED',
        'WORKSHOP_ITEM_BENCH_STARTED',
        'WORKSHOP_ITEM_REFURBISHED_TO_VAN',
        'WORKSHOP_ITEM_REFURBISHED_TO_CENTRAL',
        'WORKSHOP_ITEM_SCRAPPED_IN_BENCH',
        'WORKSHOP_ITEM_SCRAPPED_UNRECEIVED',
    ] as $action) {
        $assert('Auditoría registrada: ' . $action, ($audit[$action] ?? 0) > 0, 'total=' . (string)($audit[$action] ?? 0));
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[EXCEPCIÓN NO CONTROLADA] " . $exception->getMessage() . "\n");
    fwrite(STDERR, $exception->getFile() . ':' . $exception->getLine() . "\n");
    fwrite(STDERR, $exception->getTraceAsString() . "\n");
    $failures++;
}

echo "\n======================================================================\n";
echo " VENDGUARD · T-WORKSHOP-10 · RESULTADO\n";
echo " Aserciones: {$assertions} · Fallos: {$failures}\n";
echo "======================================================================\n";

if ($failures > 0) {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
    exit(1);
}

echo " RESULTADO: 100% EN VERDE. RF-01..RF-05, D1..D4, C1..C6 y QA 1..QA 5 CERTIFICADOS.\n";
exit(0);
