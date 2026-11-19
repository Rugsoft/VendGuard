<?php

declare(strict_types=1);

/**
 * PostgresRestockerSatSynergySchemaTest · T-RSS-02
 *
 * Certifica en el motor real el esquema del Módulo 36 (`36-restocker-sat-synergy`): los tipos
 * nativos #43 `incident_dispatch_level` y #44 `level1_issue_type`, la evolución de `incidents`
 * con su guarda constitucional, la ampliación de `offline_sync_mutations` y las semillas de
 * taxonomía de Nivel 1. No comprueba servicios, gateway ni API —eso llega con las tareas
 * `T-RSS-03` a `T-RSS-12`— sino las garantías que la base impone por sí sola, porque son las
 * que ningún camino de código puede saltarse:
 *
 *   1. **Tipos nativos y ampliaciones (RF-01.1, QA-AMB-02).** `incident_dispatch_level` declara
 *      `L1_RESTOCKER` y `L2_SAT`; `level1_issue_type` declara los cuatro motivos; `RESTOCKER`
 *      firma las notas internas y la custodia gana sus cuatro canales y tres causas de salida.
 *   2. **Forma de `incidents` (D1, D2).** Cincuenta y seis columnas, con `dispatch_level` NOT NULL
 *      y defecto `L2_SAT`, `level1_issue_type` nulo, el custodio de origen del escalado con su
 *      clave foránea `RESTRICT` y `escalated_to_sat_at` opcional.
 *   3. **Guarda constitucional en el motor (Art. II, QA-LIM-03, QA-AMB-01, QA-CONST-01).**
 *      `L1_RESTOCKER` es imposible en `TEMPERATURE_COLD` y `ELECTRICAL_OFF`, imposible con
 *      `PENDING_PARTS` o con `pending_parts_reason`, la pareja `level1_issue_type`/`category`
 *      del alta L1 es determinista y el rastro de escalado es todo-o-nada. Un `L2_SAT` puede
 *      conservar el motivo L1 como rastro histórico aunque su categoría cambie.
 *   4. **Índice de cola (D1).** `idx_incidents_dispatch_level_status` sirve el filtro de
 *      Coordinación y la bandeja L1 del reponedor.
 *   5. **Cola Offline (RF-05.1, C2).** `offline_sync_mutations` acepta `MACHINE` como parada y las
 *      tres operaciones nuevas, y sigue rechazando lo que no declara el catálogo.
 *   6. **Semillas de taxonomía (RF-01.3, QA-AMB-01).** Las cuatro parejas `RC-L1-*` / `SS-L1-*`
 *      existen con la categoría y la solución predeterminada que exige el mapeo determinista.
 *   7. **Sin deriva estructural (Art. IV).** El esquema sigue en 49 tablas y `incidents` conserva
 *      su disparador constitucional `prevent_hard_delete()`.
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible (el motor de `deploy/local/postgres/`). NO pertenece a la
 *   batería hermética (RNF-04): se declara con su marca de categoría, se ejecuta en su canal
 *   propio y su informe va aparte. Sin `PG_TEST_DSN` la suite **no se omite en silencio**: lo dice
 *   y sale con código 2.
 *
 * LIMPIEZA: el escenario vive en su propio esquema y se borra con `CASCADE` al terminar, incluso
 * si una aserción falla. No deja una sola tabla en la base de certificación.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php tests/integration/PostgresRestockerSatSynergySchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Infrastructure\Database\PostgresMigrationRunner;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado de esta suite: no comparte escenario con las suites de otros módulos. */
const SYNERGY_TEST_SCHEMA = 't_rss02_sinergia';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-RSS-02 · Esquema de la sinergia reponedor ↔ SAT (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

$createLocation = static function (PDO $pdo, string $suffix): int {
    $stmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, has_physical_reception)
         VALUES (:site_code, :site_code_key, :name, :address, TRUE)
         RETURNING id'
    );
    $stmt->execute([
        ':site_code' => 'SEDE-RSS-' . strtoupper($suffix),
        ':site_code_key' => 'sede-rss-' . strtolower($suffix),
        ':name' => 'Sede de certificación de la sinergia ' . $suffix,
        ':address' => 'Carrer del Reponedor 36, Barcelona',
    ]);

    return (int)$stmt->fetchColumn();
};

$createMachine = static function (PDO $pdo, int $locationId, string $code): int {
    $stmt = $pdo->prepare(
        'INSERT INTO machines (location_id, code, model, machine_type, floor_wing, sanitary_status)
         VALUES (:location_id, :code, :model, \'COMBO\', :floor_wing, \'OK\')
         RETURNING id'
    );
    $stmt->execute([
        ':location_id' => $locationId,
        ':code' => $code,
        ':model' => 'Modelo de certificación ' . $code,
        ':floor_wing' => 'Planta de certificación',
    ]);

    return (int)$stmt->fetchColumn();
};

$createUser = static function (PDO $pdo, string $suffix, string $role): int {
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role)
         VALUES (:name, :email, :email_key, :password_hash, :role)
         RETURNING id'
    );
    $stmt->execute([
        ':name' => 'Actor de certificación ' . $suffix,
        ':email' => 'rss02.' . strtolower($suffix) . '@gestion-vending.test',
        ':email_key' => 'rss02.' . strtolower($suffix) . '@gestion-vending.test',
        ':password_hash' => password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 4]),
        ':role' => $role,
    ]);

    return (int)$stmt->fetchColumn();
};

$crash = null;
$residueProbe = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio entero en un esquema aislado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones, incluida la 032) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, SYNERGY_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base y la capa incremental aplican, incluida la migración 032 del Módulo 36',
        in_array('032_restocker_sat_synergy.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf(
            '%d sentencias · migraciones: %d · pendientes: %s',
            $schemaReport['statements'],
            count($schemaReport['migrations']),
            json_encode($convergence['pending'])
        )
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1. Tipos nativos y ampliaciones (RF-01.1, QA-AMB-02)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Tipos nativos del nivel de despacho y de la taxonomía L1 ---\n";

    $enumLabels = static function (PDO $pdo, string $type): array {
        $stmt = $pdo->prepare(
            "SELECT string_agg(e.enumlabel, ',' ORDER BY e.enumsortorder)
               FROM pg_type t JOIN pg_enum e ON e.enumtypid = t.oid
              WHERE t.typname = :type AND t.typnamespace = current_schema()::regnamespace"
        );
        $stmt->execute([':type' => $type]);
        $labels = (string)$stmt->fetchColumn();

        return $labels === '' ? [] : explode(',', $labels);
    };

    $assert(
        'incident_dispatch_level declara los dos niveles operativos del contrato (D1)',
        $enumLabels($pgPdo, 'incident_dispatch_level') === ['L1_RESTOCKER', 'L2_SAT'],
        implode(' · ', $enumLabels($pgPdo, 'incident_dispatch_level'))
    );

    $assert(
        'level1_issue_type declara los cuatro motivos operativos del contrato (D2, QA-AMB-01)',
        $enumLabels($pgPdo, 'level1_issue_type') === [
            'STOCKOUT_EMPTY_SLOT',
            'MISSING_CONSUMABLES',
            'EMPTY_CHANGE_TUBES',
            'MINOR_SPIRAL_JAM',
        ],
        implode(' · ', $enumLabels($pgPdo, 'level1_issue_type'))
    );

    $commentAuthors = $enumLabels($pgPdo, 'comment_author_type');
    $assert(
        'comment_author_type admite la autoría RESTOCKER de la Nota Interna (QA-AMB-02)',
        in_array('RESTOCKER', $commentAuthors, true) && count($commentAuthors) === 5,
        implode(' · ', $commentAuthors)
    );

    $assignmentSources = $enumLabels($pgPdo, 'incident_assignment_source');
    $assert(
        'incident_assignment_source declara los 13 canales, con los 4 de la sinergia (QA-AMB-02)',
        count($assignmentSources) === 13
            && array_values(array_intersect($assignmentSources, [
                'RESTOCKER_ESCALATION_TO_SAT',
                'RESTOCKER_SHIFT_HANDOVER',
                'QR_AUTO_ESCALATION_TO_SAT',
                'WARRANTY_REOPEN_ESCALATION_TO_SAT',
            ])) === [
                'RESTOCKER_ESCALATION_TO_SAT',
                'RESTOCKER_SHIFT_HANDOVER',
                'QR_AUTO_ESCALATION_TO_SAT',
                'WARRANTY_REOPEN_ESCALATION_TO_SAT',
            ],
        'canales: ' . count($assignmentSources)
    );

    $unassignmentReasons = $enumLabels($pgPdo, 'incident_unassignment_reason_type');
    $assert(
        'incident_unassignment_reason_type declara las 13 causas, con las 3 de la sinergia (QA-AMB-02)',
        count($unassignmentReasons) === 13
            && in_array('ESCALATED_TO_SAT_BY_RESTOCKER', $unassignmentReasons, true)
            && in_array('QR_AUTO_ESCALATED_TO_SAT', $unassignmentReasons, true)
            && in_array('RESTOCKER_SHIFT_HANDOVER', $unassignmentReasons, true),
        'causas: ' . count($unassignmentReasons)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Forma de incidents (D1, D2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Evolución de incidents ---\n";

    $incidentColumns = PostgresFixtureBuilder::columns($pgPdo, 'incidents');

    $assert(
        'incidents declara sus 56 columnas con las cuatro del Módulo 36',
        count($incidentColumns) === 56
            && array_key_exists('dispatch_level', $incidentColumns)
            && array_key_exists('level1_issue_type', $incidentColumns)
            && array_key_exists('escalated_from_restocker_id', $incidentColumns)
            && array_key_exists('escalated_to_sat_at', $incidentColumns),
        'columnas: ' . count($incidentColumns)
    );

    $assert(
        'dispatch_level nace NOT NULL con defecto L2_SAT y tipo incident_dispatch_level',
        $incidentColumns['dispatch_level']['udt_name'] === 'incident_dispatch_level'
            && $incidentColumns['dispatch_level']['nullable'] === false
            && str_contains((string)$incidentColumns['dispatch_level']['default'], "'L2_SAT'"),
        'udt=' . $incidentColumns['dispatch_level']['udt_name']
            . ' · defecto=' . (string)$incidentColumns['dispatch_level']['default']
    );

    $assert(
        'level1_issue_type es opcional y del tipo level1_issue_type',
        $incidentColumns['level1_issue_type']['udt_name'] === 'level1_issue_type'
            && $incidentColumns['level1_issue_type']['nullable'] === true
            && $incidentColumns['level1_issue_type']['default'] === null
    );

    $assert(
        'El rastro del escalado declara custodio BIGINT y marca TIMESTAMPTZ, ambos opcionales',
        $incidentColumns['escalated_from_restocker_id']['udt_name'] === 'int8'
            && $incidentColumns['escalated_from_restocker_id']['nullable'] === true
            && $incidentColumns['escalated_to_sat_at']['udt_name'] === 'timestamptz'
            && $incidentColumns['escalated_to_sat_at']['nullable'] === true
    );

    $fkStmt = $pgPdo->prepare(
        "SELECT confdeltype, confupdtype, confrelid::regclass::text AS parent
           FROM pg_constraint
          WHERE conname = 'fk_incidents_escalated_from_restocker'
            AND conrelid = 'incidents'::regclass"
    );
    $fkStmt->execute();
    $fk = $fkStmt->fetch();
    $assert(
        'fk_incidents_escalated_from_restocker apunta a users con RESTRICT/CASCADE',
        is_array($fk) && $fk['parent'] === 'users'
            && $fk['confdeltype'] === 'r' && $fk['confupdtype'] === 'c',
        is_array($fk) ? 'parent=' . $fk['parent'] . ' · delete=' . $fk['confdeltype'] : 'ausente'
    );

    $dispatchIndexStmt = $pgPdo->prepare(
        "SELECT indexname FROM pg_indexes
          WHERE schemaname = current_schema() AND tablename = 'incidents'
            AND indexname = 'idx_incidents_dispatch_level_status'"
    );
    $dispatchIndexStmt->execute();
    $assert(
        'idx_incidents_dispatch_level_status sirve el filtro por nivel y custodio (D1)',
        (string)$dispatchIndexStmt->fetchColumn() === 'idx_incidents_dispatch_level_status'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Guarda constitucional Art. II / QA-LIM-03 (QA-CONST-01)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Guarda chk_incidents_l1_dispatch_coherence y mapeo determinista L1 ---\n";

    $guardStmt = $pgPdo->prepare(
        "SELECT constraint_name FROM information_schema.table_constraints
          WHERE table_schema = current_schema() AND table_name = 'incidents'
            AND constraint_name = 'chk_incidents_l1_dispatch_coherence'"
    );
    $guardStmt->execute();
    $assert(
        'La restricción chk_incidents_l1_dispatch_coherence está declarada en incidents',
        (string)$guardStmt->fetchColumn() === 'chk_incidents_l1_dispatch_coherence'
    );

    $locationId = $createLocation($pgPdo, '01');
    $machineId = $createMachine($pgPdo, $locationId, 'VEND-RSS-001');
    $restockerId = $createUser($pgPdo, 'rep01', 'RESTOCKER');
    // El índice uq_machine_active_ticket impide dos expedientes vivos por máquina:
    // el rastro de escalado se certifica sobre una segunda máquina limpia.
    $escalationMachineId = $createMachine($pgPdo, $locationId, 'VEND-RSS-002');
    $historicalTraceMachineId = $createMachine($pgPdo, $locationId, 'VEND-RSS-003');

    $attemptIncident = static function (array $overrides, ?int $machineOverride = null) use ($pgPdo, $locationId, $machineId, $restockerId): array {
        return PostgresFixtureBuilder::insertIncident(
            $pgPdo,
            'INC-RSS-' . strtoupper(substr(hash('sha256', json_encode($overrides) . microtime()), 0, 10)),
            $locationId,
            $machineOverride ?? $machineId,
            $overrides + ['escalated_from_restocker_id' => null]
        );
    };

    $thermalL1 = PostgresFixtureBuilder::expectRejection(static function () use ($attemptIncident): void {
        $attemptIncident([
            'category' => 'TEMPERATURE_COLD',
            'dispatch_level' => 'L1_RESTOCKER',
        ]);
    });
    $assert(
        'Un ticket TEMPERATURE_COLD no puede quedar en L1_RESTOCKER (23514, Art. II)',
        $thermalL1 !== null && $thermalL1['state'] === '23514'
            && str_contains($thermalL1['message'], 'chk_incidents_l1_dispatch_coherence'),
        $thermalL1 === null ? 'la fila térmica se persistió' : 'estado=' . $thermalL1['state']
    );

    $electricalL1 = PostgresFixtureBuilder::expectRejection(static function () use ($attemptIncident): void {
        $attemptIncident([
            'category' => 'ELECTRICAL_OFF',
            'dispatch_level' => 'L1_RESTOCKER',
        ]);
    });
    $assert(
        'Un ticket ELECTRICAL_OFF no puede quedar en L1_RESTOCKER (23514, Art. II)',
        $electricalL1 !== null && $electricalL1['state'] === '23514',
        $electricalL1 === null ? 'la fila eléctrica se persistió' : 'estado=' . $electricalL1['state']
    );

    $pendingPartsL1 = PostgresFixtureBuilder::expectRejection(static function () use ($attemptIncident): void {
        $attemptIncident([
            'category' => 'PRODUCT_JAM',
            'status' => 'PENDING_PARTS',
            'dispatch_level' => 'L1_RESTOCKER',
        ]);
    });
    $assert(
        'Un L1_RESTOCKER no puede estar en PENDING_PARTS (23514, QA-LIM-03)',
        $pendingPartsL1 !== null && $pendingPartsL1['state'] === '23514',
        $pendingPartsL1 === null ? 'el estado PENDING_PARTS pasó' : 'estado=' . $pendingPartsL1['state']
    );

    $pendingReasonL1 = PostgresFixtureBuilder::expectRejection(static function () use ($attemptIncident): void {
        $attemptIncident([
            'category' => 'PRODUCT_JAM',
            'dispatch_level' => 'L1_RESTOCKER',
            'pending_parts_reason' => 'Pieza pedida al almacén central y pendiente de recepción.',
        ]);
    });
    $assert(
        'Un L1_RESTOCKER no puede declarar pending_parts_reason (23514, QA-LIM-03)',
        $pendingReasonL1 !== null && $pendingReasonL1['state'] === '23514',
        $pendingReasonL1 === null ? 'la razón de repuestos pasó' : 'estado=' . $pendingReasonL1['state']
    );

    $validL1 = null;
    try {
        $validL1 = $attemptIncident([
            'category' => 'PRODUCT_JAM',
            'dispatch_level' => 'L1_RESTOCKER',
            'level1_issue_type' => 'MINOR_SPIRAL_JAM',
        ]);
    } catch (Throwable $exception) {
        $validL1 = null;
    }
    $assert(
        'Un aviso leve PRODUCT_JAM en L1_RESTOCKER con su subtipo se persiste',
        is_array($validL1)
            && $validL1['dispatch_level'] === 'L1_RESTOCKER'
            && $validL1['level1_issue_type'] === 'MINOR_SPIRAL_JAM'
    );

    $incompatibleL1Pair = PostgresFixtureBuilder::expectRejection(static function () use ($attemptIncident, $escalationMachineId): void {
        $attemptIncident([
            'category' => 'PAYMENT_SYSTEM',
            'dispatch_level' => 'L1_RESTOCKER',
            'level1_issue_type' => 'STOCKOUT_EMPTY_SLOT',
        ], $escalationMachineId);
    });
    $assert(
        'Un L1_RESTOCKER rechaza una pareja motivo/categoría incoherente (23514, QA-AMB-01)',
        $incompatibleL1Pair !== null && $incompatibleL1Pair['state'] === '23514'
            && str_contains($incompatibleL1Pair['message'], 'chk_incidents_l1_dispatch_coherence'),
        $incompatibleL1Pair === null ? 'la pareja incoherente se persistió' : 'estado=' . $incompatibleL1Pair['state']
    );

    $halfEscalation = PostgresFixtureBuilder::expectRejection(static function () use ($attemptIncident, $restockerId, $escalationMachineId): void {
        $attemptIncident([
            'category' => 'PAYMENT_SYSTEM',
            'escalated_from_restocker_id' => $restockerId,
        ], $escalationMachineId);
    });
    $assert(
        'El rastro de escalado exige custodio y marca a la vez (23514)',
        $halfEscalation !== null && $halfEscalation['state'] === '23514',
        $halfEscalation === null ? 'medio rastro pasó' : 'estado=' . $halfEscalation['state']
    );

    $fullEscalation = null;
    try {
        $fullEscalation = $attemptIncident([
            'category' => 'PRODUCT_JAM',
            'dispatch_level' => 'L2_SAT',
            'escalated_from_restocker_id' => $restockerId,
            'escalated_to_sat_at' => '2026-11-18 10:30:00+00',
        ], $escalationMachineId);
    } catch (Throwable $exception) {
        $fullEscalation = null;
    }
    $assert(
        'El escalado completo se persiste con su custodio de origen y su marca',
        is_array($fullEscalation)
            && (int)$fullEscalation['escalated_from_restocker_id'] === $restockerId
            && $fullEscalation['dispatch_level'] === 'L2_SAT'
    );

    $historicalL2 = null;
    try {
        $historicalL2 = $attemptIncident([
            'category' => 'TEMPERATURE_COLD',
            'dispatch_level' => 'L2_SAT',
            'level1_issue_type' => 'STOCKOUT_EMPTY_SLOT',
            'escalated_from_restocker_id' => $restockerId,
            'escalated_to_sat_at' => '2026-11-18 11:00:00+00',
        ], $historicalTraceMachineId);
    } catch (Throwable $exception) {
        $historicalL2 = null;
    }
    $assert(
        'Un L2_SAT conserva el motivo L1 histórico aunque la categoría pase a térmica',
        is_array($historicalL2)
            && $historicalL2['dispatch_level'] === 'L2_SAT'
            && $historicalL2['category'] === 'TEMPERATURE_COLD'
            && $historicalL2['level1_issue_type'] === 'STOCKOUT_EMPTY_SLOT'
            && (int)$historicalL2['escalated_from_restocker_id'] === $restockerId
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Cola Offline del reponedor (RF-05.1, C2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Cola offline de las mutaciones de Nivel 1 ---\n";

    $insertMutation = static function (string $mutationId, string $stopType, string $operation) use ($pgPdo, $restockerId): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO offline_sync_mutations
                 (technician_id, client_mutation_id, stop_type, stop_id, operation_type,
                  client_occurred_at, effective_occurred_at)
             VALUES (:technician_id, :mutation, :stop_type, 1, :operation,
                  \'2026-11-18 10:00:00+00\', \'2026-11-18 10:00:00+00\')'
        );
        $stmt->execute([
            ':technician_id' => $restockerId,
            ':mutation' => $mutationId,
            ':stop_type' => $stopType,
            ':operation' => $operation,
        ]);
    };

    $acceptedMutations = null;
    try {
        $insertMutation('rss02.start.l1.0001', 'INCIDENT', 'START_L1_INCIDENT');
        $insertMutation('rss02.resolve.l1.0002', 'INCIDENT', 'RESOLVE_L1_INCIDENT');
        $insertMutation('rss02.escalate.0003', 'MACHINE', 'ESCALATE_INCIDENT_TO_SAT');
        $acceptedMutations = 3;
    } catch (Throwable $exception) {
        $acceptedMutations = null;
    }
    $assert(
        'La cola acepta START_L1_INCIDENT, RESOLVE_L1_INCIDENT y ESCALATE_INCIDENT_TO_SAT sobre MACHINE',
        $acceptedMutations === 3
    );

    $unknownStopType = PostgresFixtureBuilder::expectRejection(static function () use ($insertMutation): void {
        $insertMutation('rss02.badstop.0004', 'ROUTE', 'ESCALATE_INCIDENT_TO_SAT');
    });
    $assert(
        'La parada sigue acotada y un stop_type desconocido se rechaza (23514)',
        $unknownStopType !== null && $unknownStopType['state'] === '23514',
        $unknownStopType === null ? 'ROUTE pasó' : 'estado=' . $unknownStopType['state']
    );

    $unknownOperation = PostgresFixtureBuilder::expectRejection(static function () use ($insertMutation): void {
        $insertMutation('rss02.badop.0005', 'MACHINE', 'ESCALATE_TO_SAT');
    });
    $assert(
        'El catálogo cerrado de operaciones rechaza un nombre no declarado (23514)',
        $unknownOperation !== null && $unknownOperation['state'] === '23514',
        $unknownOperation === null ? 'la operación inventada pasó' : 'estado=' . $unknownOperation['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Semillas de taxonomía de Nivel 1 (RF-01.3, QA-AMB-01)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Semillas RC-L1-* / SS-L1-* ---\n";

    $expectedTaxonomy = [
        'RC-L1-STOCKOUT' => ['PRODUCT_JAM', 'Agotamiento de producto en carril de selección', 'SS-L1-RESTOCK-SLOT'],
        'RC-L1-SPIRAL-RELEASE' => ['PRODUCT_JAM', 'Envase mal posicionado o trabado leve en boca de espiral', 'SS-L1-CLEAR-SPIRAL'],
        'RC-L1-EMPTY-TUBES' => ['PAYMENT_SYSTEM', 'Tubos de cambio del monedero sin suficiente moneda fraccionaria', 'SS-L1-REFILL-TUBES'],
        'RC-L1-CONSUMABLES' => ['OTHER', 'Falta de vasos, azúcar o paletinas en dispensador', 'SS-L1-REFILL-CUPS-SUGAR'],
    ];

    $taxonomyStmt = $pgPdo->prepare(
        'SELECT rc.code, rc.category, rc.name, ss.code AS solution_code, ss.is_default, ss.is_active
           FROM root_causes rc
           JOIN standard_solutions ss ON ss.root_cause_id = rc.id
          WHERE rc.code = :code
          ORDER BY ss.is_default DESC, ss.code'
    );

    $taxonomyOk = true;
    $taxonomyDetail = [];
    foreach ($expectedTaxonomy as $causeCode => [$category, $name, $solutionCode]) {
        $taxonomyStmt->execute([':code' => $causeCode]);
        $rows = $taxonomyStmt->fetchAll();
        $taxonomyOk = $taxonomyOk
            && count($rows) === 1
            && $rows[0]['category'] === $category
            && $rows[0]['name'] === $name
            && $rows[0]['solution_code'] === $solutionCode
            && $rows[0]['is_default'] === true
            && $rows[0]['is_active'] === true;
        $taxonomyDetail[] = $causeCode . '→' . ($rows[0]['solution_code'] ?? 'ausente');
    }

    $assert(
        'Las cuatro causas RC-L1-* existen con su categoría, nombre y solución predeterminada activa',
        $taxonomyOk,
        implode(' · ', $taxonomyDetail)
    );

    $orphanDefaults = (int)$pgPdo->query(
        "SELECT count(*) FROM standard_solutions ss
           JOIN root_causes rc ON rc.id = ss.root_cause_id
          WHERE rc.code LIKE 'RC-L1-%' AND ss.is_default = TRUE AND ss.is_active = TRUE"
    )->fetchColumn();
    $assert(
        'Cada una de las cuatro causas declara exactamente una solución predeterminada activa',
        $orphanDefaults === 4,
        'predeterminadas: ' . $orphanDefaults
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Sin deriva estructural (Art. IV, Art. III.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Integridad estructural ---\n";

    $tables = PostgresFixtureBuilder::tables($pgPdo);
    $assert(
        'El Módulo 36 no añade tablas: el esquema sigue en 49 tablas de dominio (Art. IV)',
        count($tables) === 49,
        'tablas: ' . count($tables)
    );

    $triggerStmt = $pgPdo->prepare(
        "SELECT tgname FROM pg_trigger
          WHERE tgrelid = 'incidents'::regclass AND NOT tgisinternal
            AND tgname = 'trg_no_delete_incidents'"
    );
    $triggerStmt->execute();
    $assert(
        'incidents conserva su disparador constitucional de borrado (Art. III.1)',
        (string)$triggerStmt->fetchColumn() === 'trg_no_delete_incidents'
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, SYNERGY_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    $residueProbe = $pgPdo->query("SELECT to_regclass('" . SYNERGY_TEST_SCHEMA . ".incidents')")->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El escenario se retira entero: el esquema aislado y su incidents ya no existen en el motor',
    $residueProbe === null,
    'la sonda de residuo encontró la tabla en ' . json_encode($residueProbe)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. ESQUEMA DE LA SINERGIA REPONEDOR ↔ SAT CERTIFICADO (T-RSS-02).\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
