<?php

declare(strict_types=1);

/**
 * PostgresRestockRoutePlannerSchemaTest · T-RRP-02
 *
 * Certifica en el motor real el esquema del Módulo 37 (`37-restock-route-planner`): los seis
 * tipos nativos #45 a #50, las Tablas 50 y 51, el enlace histórico de la visita con su agenda y
 * la ampliación del catálogo Offline. No comprueba servicios, gateway ni API —eso llega con las
 * tareas `T-RRP-03` a `T-RRP-12`— sino las garantías que la base impone por sí sola, porque son
 * las que ningún camino de código puede saltarse:
 *
 *   1. **Tipos nativos y ampliaciones (RF-01.1, RF-02.1, D5, D6, QA-4, QA-5).** Las cinco
 *      cadencias, las cuatro franjas, los dos tipos de excepción, sus tres estados, las cinco
 *      causas de omisión y las cuatro de desvío; `product_loading_demand_mode` gana
 *      `TODAY_ROUTE`, la auditoría sus dos entidades y la plantilla de aviso interno su valor.
 *   2. **Forma de la agenda (D1, QA-2).** Quince columnas con `cadence_type` obligatorio y los
 *      defectos del contrato (`ANY`, sin fines de semana, activa), más la unicidad parcial de
 *      una agenda activa por máquina.
 *   3. **Forma de la excepción diaria (C1).** Diecinueve columnas con estado `ACTIVE` por
 *      defecto, el sello FEFO en falso y la unicidad parcial por máquina y día civil.
 *   4. **Guardas de coherencia en el motor (Art. III.3, Art. V.1).** `WEEKLY` exige día ISO y
 *      `BIWEEKLY` fecha ancla; los fines de semana solo existen en `DAILY`; una agenda inactiva
 *      siempre deja rastro de baja; `SKIPPED` exige causa y motivo de 20 a 500 caracteres
 *      reales, y `REASSIGNED` exige destino y causa; `REVOKED` siempre se resuelve.
 *   5. **Versionado inmutable (Art. III.3, QA-2).** Una segunda agenda activa se rechaza con
 *      `23505`; cerrar la vigente y sembrar la nueva conserva las dos versiones.
 *   6. **Enlace histórico (RF-01.1.4, RF-04.2).** `machine_restock_visits.route_schedule_id`
 *      referencia la agenda con `RESTRICT`/`CASCADE`, de modo que una versión cerrada sigue
 *      siendo referenciable por una visita capturada offline.
 *   7. **Cola Offline (RF-05.1.1, C3).** `SKIP_RESTOCK_STOP` se admite y lo ajeno al catálogo
 *      se rechaza.
 *   8. **Sin deriva estructural (Art. IV).** El esquema declara exactamente 51 tablas y
 *      `locations` conserva sus 23 columnas: el DDL de la sede no se toca.
 *   9. **Art. III.1.** El borrado físico de ambas tablas se rechaza con `45000`.
 *  10. **Semillas 1:1 e idempotencia (RNF-01, DoD 1).** Las secciones 23 y 24 de
 *      `database/supabase_seeds.sql` y `SeedRunner::seedMachineRestockSchedules()` /
 *      `seedRestockRouteDailyOverrides()` insertan exactamente las 5 agendas (las
 *      5 cadencias) y 1 excepción `SKIPPED` con filas equivalentes 1:1 e
 *      idempotencia cruzada (0 filas al reejecutar SQL o PHP).
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible (el motor de `deploy/local/postgres/`). NO pertenece a la
 *   batería hermética (RNF-04): se declara con su marca de categoría, se ejecuta en su canal
 *   propio y su informe va aparte. Sin `PG_TEST_DSN` la suite **no se omite en silencio**: lo
 *   dice y sale con código 2.
 *
 * LIMPIEZA: el escenario vive en su propio esquema y se borra con `CASCADE` al terminar, incluso
 * si una aserción falla. No deja una sola tabla en la base de certificación.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresRestockRoutePlannerSchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Infrastructure\Database\PostgresMigrationRunner;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Database\SqlScriptSplitter;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado de esta suite: no comparte escenario con las suites de otros módulos. */
const PLANNER_TEST_SCHEMA = 't_rrp02_planificador';

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
echo " VENDGUARD · T-RRP-02 · Esquema del planificador de rutas (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

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

$createLocation = static function (PDO $pdo, string $suffix): int {
    $stmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, has_physical_reception)
         VALUES (:site_code, :site_code_key, :name, :address, TRUE)
         RETURNING id'
    );
    $stmt->execute([
        ':site_code' => 'SEDE-RRP-' . strtoupper($suffix),
        ':site_code_key' => 'sede-rrp-' . strtolower($suffix),
        ':name' => 'Sede de certificación del planificador ' . $suffix,
        ':address' => 'Carrer de la Ruta 37, Barcelona',
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
        ':email' => 'rrp02.' . strtolower($suffix) . '@gestion-vending.test',
        ':email_key' => 'rrp02.' . strtolower($suffix) . '@gestion-vending.test',
        ':password_hash' => password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 4]),
        ':role' => $role,
    ]);

    return (int)$stmt->fetchColumn();
};

/**
 * Alta de una agenda con los campos que la fila mínima necesita. Los booleanos viajan como
 * literal —la forma que el motor acepta sin traducción— y la baja se declara en pareja.
 */
$insertSchedule = static function (
    PDO $pdo,
    int $machineId,
    int $actorId,
    string $cadence,
    ?int $dayOfWeek = null,
    ?string $anchorDate = null,
    bool $includeWeekends = false,
    bool $isActive = true,
    ?string $deactivatedAt = null,
    ?string $notes = 'Agenda de certificación del planificador de rutas (T-RRP-02).'
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO machine_restock_schedules
            (machine_id, cadence_type, day_of_week, anchor_date, include_weekends, notes,
             is_active, created_by, updated_by, deactivated_at, deactivated_by)
         VALUES
            (:machine_id, :cadence_type, :day_of_week, :anchor_date, ' . ($includeWeekends ? 'TRUE' : 'FALSE') . ', :notes,
             ' . ($isActive ? 'TRUE' : 'FALSE') . ', :actor_id, :actor_id, :deactivated_at, :deactivated_by)
         RETURNING id'
    );
    $stmt->execute([
        ':machine_id' => $machineId,
        ':cadence_type' => $cadence,
        ':day_of_week' => $dayOfWeek,
        ':anchor_date' => $anchorDate,
        ':notes' => $notes,
        ':actor_id' => $actorId,
        ':deactivated_at' => $deactivatedAt,
        ':deactivated_by' => $deactivatedAt === null ? null : $actorId,
    ]);

    return (int)$stmt->fetchColumn();
};

/** Alta de una excepción diaria: las columnas que se declaran son las que la rama exige. */
$insertOverride = static function (PDO $pdo, array $values): int {
    $columns = array_keys($values);
    $placeholders = [];
    $params = [];
    foreach ($columns as $index => $column) {
        $placeholders[] = ':v' . $index;
        $params[':v' . $index] = $values[$column];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO restock_route_daily_overrides (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $placeholders) . ')
         RETURNING id'
    );
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
};

$crash = null;
$residueProbe = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio entero en un esquema aislado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones, incluida la 033) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, PLANNER_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base y la capa incremental aplican, incluidas la 033 y su corrección 034',
        in_array('033_restock_route_planner.sql', $schemaReport['migrations'], true)
            && in_array('034_restock_schedule_cadence_guard.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf(
            '%d sentencias · migraciones: %d · pendientes: %s',
            $schemaReport['statements'],
            count($schemaReport['migrations']),
            json_encode($convergence['pending'])
        )
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1. Tipos nativos #45 a #50 y ampliaciones (D5, D6, QA-4, QA-5)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Tipos nativos del planificador y ampliaciones de catálogo ---\n";

    $assert(
        'restock_cadence_type declara las cinco cadencias del contrato (RF-01.1, D6)',
        $enumLabels($pgPdo, 'restock_cadence_type') === [
            'DAILY', 'MON_WED_FRI', 'TUE_THU', 'WEEKLY', 'BIWEEKLY',
        ],
        implode(' · ', $enumLabels($pgPdo, 'restock_cadence_type'))
    );

    $assert(
        'restock_time_window declara las cuatro franjas de acceso (RF-01.1)',
        $enumLabels($pgPdo, 'restock_time_window') === ['MORNING', 'MIDDAY', 'AFTERNOON', 'ANY'],
        implode(' · ', $enumLabels($pgPdo, 'restock_time_window'))
    );

    $assert(
        'restock_override_type declara la omisión y el desvío (RF-02.1)',
        $enumLabels($pgPdo, 'restock_override_type') === ['SKIPPED', 'REASSIGNED'],
        implode(' · ', $enumLabels($pgPdo, 'restock_override_type'))
    );

    $assert(
        'restock_override_status declara el ciclo ACTIVE/SUPERSEDED/REVOKED (C1)',
        $enumLabels($pgPdo, 'restock_override_status') === ['ACTIVE', 'SUPERSEDED', 'REVOKED'],
        implode(' · ', $enumLabels($pgPdo, 'restock_override_status'))
    );

    $assert(
        'restock_skip_reason_code declara las cinco causas tipificadas de omisión (QA-4, Art. V.1)',
        $enumLabels($pgPdo, 'restock_skip_reason_code') === [
            'LOCATION_CLOSED', 'NO_KEY_OR_ACCESS', 'LOCAL_HOLIDAY',
            'FLEET_CAPACITY_SHORTAGE', 'OTHER_OPERATIONAL',
        ],
        implode(' · ', $enumLabels($pgPdo, 'restock_skip_reason_code'))
    );

    $assert(
        'restock_reassignment_reason_code declara las cuatro causas de desvío (QA-4)',
        $enumLabels($pgPdo, 'restock_reassignment_reason_code') === [
            'ZONE_UNCOVERED', 'LOAD_BALANCING', 'FEFO_RESCUE', 'OTHER_REASSIGNMENT',
        ],
        implode(' · ', $enumLabels($pgPdo, 'restock_reassignment_reason_code'))
    );

    $assert(
        'product_loading_demand_mode incorpora TODAY_ROUTE sin perder los dos modos previos (D5, QA-5)',
        $enumLabels($pgPdo, 'product_loading_demand_mode') === ['ALERTS_ONLY', 'ALL_GAPS', 'TODAY_ROUTE'],
        implode(' · ', $enumLabels($pgPdo, 'product_loading_demand_mode'))
    );

    $auditEntities = $enumLabels($pgPdo, 'audit_entity_type');
    $assert(
        'audit_entity_type declara las dos entidades de auditoría del Módulo 37 (Art. III.3)',
        in_array('MACHINE_RESTOCK_SCHEDULE', $auditEntities, true)
            && in_array('RESTOCK_ROUTE_DAILY_OVERRIDE', $auditEntities, true),
        'entidades: ' . count($auditEntities)
    );

    $assert(
        'notification_template declara la alerta interna de omisión con FEFO viva (RF-02.2, QA-1)',
        in_array('RESTOCK_FEFO_STOP_SKIPPED_ALERT', $enumLabels($pgPdo, 'notification_template'), true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Forma de la agenda (D1, QA-2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Tabla 50 · machine_restock_schedules ---\n";

    $scheduleColumns = PostgresFixtureBuilder::columns($pgPdo, 'machine_restock_schedules');

    $assert(
        'machine_restock_schedules declara sus 15 columnas con cadence_type obligatorio y notas opcionales',
        count($scheduleColumns) === 15
            && $scheduleColumns['cadence_type']['udt_name'] === 'restock_cadence_type'
            && $scheduleColumns['cadence_type']['nullable'] === false
            && $scheduleColumns['notes']['nullable'] === true
            && $scheduleColumns['deactivated_by']['nullable'] === true,
        'columnas: ' . count($scheduleColumns)
    );

    $assert(
        'Los defectos del contrato son ANY, sin fines de semana y agenda activa',
        str_contains((string)$scheduleColumns['preferred_time_window']['default'], "'ANY'")
            && str_contains(strtolower((string)$scheduleColumns['include_weekends']['default']), 'false')
            && str_contains(strtolower((string)$scheduleColumns['is_active']['default']), 'true'),
        'franja=' . (string)$scheduleColumns['preferred_time_window']['default']
            . ' · fines de semana=' . (string)$scheduleColumns['include_weekends']['default']
    );

    $indexDefinition = static function (PDO $pdo, string $name): string {
        $stmt = $pdo->prepare(
            "SELECT pg_get_indexdef(c.oid)
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = current_schema() AND c.relname = :name"
        );
        $stmt->execute([':name' => $name]);

        return (string)$stmt->fetchColumn();
    };

    $activeScheduleIndex = $indexDefinition($pgPdo, 'uq_machine_restock_schedule_active');
    $assert(
        'uq_machine_restock_schedule_active impone una sola agenda activa por máquina (QA-2)',
        $activeScheduleIndex !== ''
            && str_contains($activeScheduleIndex, 'UNIQUE')
            && str_contains($activeScheduleIndex, 'is_active'),
        'definición: ' . mb_substr($activeScheduleIndex, 0, 120)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Forma de la excepción diaria (C1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Tabla 51 · restock_route_daily_overrides ---\n";

    $overrideColumns = PostgresFixtureBuilder::columns($pgPdo, 'restock_route_daily_overrides');

    $assert(
        'restock_route_daily_overrides declara sus 19 columnas con estado ACTIVE por defecto',
        count($overrideColumns) === 19
            && $overrideColumns['override_type']['udt_name'] === 'restock_override_type'
            && $overrideColumns['status']['udt_name'] === 'restock_override_status'
            && str_contains((string)$overrideColumns['status']['default'], "'ACTIVE'")
            && str_contains(strtolower((string)$overrideColumns['had_active_fefo_alert']['default']), 'false'),
        'columnas: ' . count($overrideColumns)
    );

    $activeOverrideIndex = $indexDefinition($pgPdo, 'uq_restock_override_active_machine_day');
    $assert(
        'uq_restock_override_active_machine_day impide dos excepciones vivas por máquina y día (C1)',
        $activeOverrideIndex !== ''
            && str_contains($activeOverrideIndex, 'UNIQUE')
            && str_contains($activeOverrideIndex, 'service_date')
            && str_contains($activeOverrideIndex, "status = 'ACTIVE'"),
        'definición: ' . mb_substr($activeOverrideIndex, 0, 140)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Enlace histórico y disparadores constitucionales
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Enlace con la visita y disparadores ---\n";

    $foreignKey = static function (PDO $pdo, string $name, string $table): ?array {
        $stmt = $pdo->prepare(
            "SELECT confdeltype, confupdtype, confrelid::regclass::text AS parent
               FROM pg_constraint
              WHERE conname = :name AND conrelid = :table::regclass"
        );
        $stmt->execute([':name' => $name, ':table' => $table]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    };

    $machineFk = $foreignKey($pgPdo, 'fk_mrs_machine', 'machine_restock_schedules');
    $assert(
        'fk_mrs_machine apunta a machines con RESTRICT/CASCADE',
        is_array($machineFk) && $machineFk['parent'] === 'machines'
            && $machineFk['confdeltype'] === 'r' && $machineFk['confupdtype'] === 'c',
        is_array($machineFk) ? 'parent=' . $machineFk['parent'] . ' · delete=' . $machineFk['confdeltype'] : 'ausente'
    );

    $visitFk = $foreignKey($pgPdo, 'fk_machine_restock_visits_route_schedule', 'machine_restock_visits');
    $assert(
        'La visita enlaza su agenda con fk_machine_restock_visits_route_schedule (RESTRICT/CASCADE)',
        is_array($visitFk) && $visitFk['parent'] === 'machine_restock_schedules'
            && $visitFk['confdeltype'] === 'r' && $visitFk['confupdtype'] === 'c'
            && array_key_exists('route_schedule_id', PostgresFixtureBuilder::columns($pgPdo, 'machine_restock_visits')),
        is_array($visitFk) ? 'parent=' . $visitFk['parent'] : 'ausente'
    );

    $triggerFunction = static function (PDO $pdo, string $table, string $trigger): ?string {
        $stmt = $pdo->prepare(
            "SELECT p.proname
               FROM pg_trigger t
               JOIN pg_class c ON c.oid = t.tgrelid
               JOIN pg_namespace n ON n.oid = c.relnamespace
               JOIN pg_proc p ON p.oid = t.tgfoid
              WHERE n.nspname = current_schema() AND c.relname = :table
                AND t.tgname = :trigger AND NOT t.tgisinternal"
        );
        $stmt->execute([':table' => $table, ':trigger' => $trigger]);
        $name = $stmt->fetchColumn();

        return is_string($name) ? $name : null;
    };

    $triggerProblems = [];
    foreach (['machine_restock_schedules', 'restock_route_daily_overrides'] as $table) {
        if ($triggerFunction($pgPdo, $table, 'trg_no_delete_' . $table) !== 'prevent_hard_delete') {
            $triggerProblems[] = "{$table}: falta trg_no_delete con prevent_hard_delete()";
        }
        if ($triggerFunction($pgPdo, $table, 'trg_touch_' . $table) !== 'touch_updated_at') {
            $triggerProblems[] = "{$table}: falta trg_touch con touch_updated_at()";
        }
    }

    $assert(
        'Ambas tablas declaran el borrado constitucional y la marca temporal de actualización',
        $triggerProblems === [],
        $triggerProblems === [] ? 'cuatro disparadores verificados' : implode(' · ', $triggerProblems)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Guardas de coherencia de la agenda (Art. III.3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Guardas chk_mrs_* de la agenda ---\n";

    $locationId = $createLocation($pgPdo, '01');
    $actorId = $createUser($pgPdo, 'coord01', 'COORDINATOR');
    $restockerId = $createUser($pgPdo, 'rep01', 'RESTOCKER');

    $machineSequence = 0;
    $nextMachine = static function () use ($pgPdo, $locationId, $createMachine, &$machineSequence): int {
        $machineSequence++;

        return $createMachine($pgPdo, $locationId, sprintf('VEND-RRP-%03d', $machineSequence));
    };

    $weeklyWithoutDay = PostgresFixtureBuilder::expectRejection(static function () use ($insertSchedule, $pgPdo, $nextMachine, $actorId): void {
        $insertSchedule($pgPdo, $nextMachine(), $actorId, 'WEEKLY');
    });
    $assert(
        'Una cadencia WEEKLY sin día ISO se rechaza con 23514 (chk_mrs_cadence_coherence)',
        $weeklyWithoutDay !== null && $weeklyWithoutDay['state'] === '23514'
            && str_contains($weeklyWithoutDay['message'], 'chk_mrs_cadence_coherence'),
        $weeklyWithoutDay === null ? 'la agenda semanal sin día se persistió' : 'estado=' . $weeklyWithoutDay['state']
    );

    $weeklyMachineId = $nextMachine();
    $weeklyAccepted = PostgresFixtureBuilder::expectRejection(static function () use ($insertSchedule, $pgPdo, $weeklyMachineId, $actorId): void {
        $insertSchedule($pgPdo, $weeklyMachineId, $actorId, 'WEEKLY', 3);
    });
    $assert(
        'Una cadencia WEEKLY con día ISO se admite',
        $weeklyAccepted === null,
        $weeklyAccepted === null ? 'admitida' : 'rechazada con ' . $weeklyAccepted['state']
    );

    $weekendMachineId = $nextMachine();
    $dailyWeekendAccepted = PostgresFixtureBuilder::expectRejection(static function () use ($insertSchedule, $pgPdo, $weekendMachineId, $actorId): void {
        $insertSchedule($pgPdo, $weekendMachineId, $actorId, 'DAILY', null, null, true);
    });
    $assert(
        'include_weekends solo se admite en la cadencia DAILY',
        $dailyWeekendAccepted === null,
        $dailyWeekendAccepted === null ? 'admitida' : 'rechazada con ' . $dailyWeekendAccepted['state']
    );

    $anchoredMachineId = $nextMachine();
    $anchoredFixedCadence = PostgresFixtureBuilder::expectRejection(static function () use ($insertSchedule, $pgPdo, $anchoredMachineId, $actorId): void {
        $insertSchedule($pgPdo, $anchoredMachineId, $actorId, 'MON_WED_FRI', null, '2026-11-02');
    });
    $assert(
        'Una cadencia fija con fecha ancla se rechaza con 23514',
        $anchoredFixedCadence !== null && $anchoredFixedCadence['state'] === '23514',
        $anchoredFixedCadence === null ? 'la fecha ancla pasó' : 'estado=' . $anchoredFixedCadence['state']
    );

    $weekendWeeklyMachineId = $nextMachine();
    $weekendWeekly = PostgresFixtureBuilder::expectRejection(static function () use ($insertSchedule, $pgPdo, $weekendWeeklyMachineId, $actorId): void {
        $insertSchedule($pgPdo, $weekendWeeklyMachineId, $actorId, 'WEEKLY', 3, null, true);
    });
    $assert(
        'Una cadencia WEEKLY con fines de semana se rechaza con 23514',
        $weekendWeekly !== null && $weekendWeekly['state'] === '23514',
        $weekendWeekly === null ? 'los fines de semana semanales pasaron' : 'estado=' . $weekendWeekly['state']
    );

    $inactiveMachineId = $nextMachine();
    $inactiveWithoutStamp = PostgresFixtureBuilder::expectRejection(static function () use ($insertSchedule, $pgPdo, $inactiveMachineId, $actorId): void {
        $insertSchedule($pgPdo, $inactiveMachineId, $actorId, 'DAILY', null, null, false, false);
    });
    $assert(
        'Una agenda inactiva sin marca de baja se rechaza con 23514 (chk_mrs_deactivation_coherence)',
        $inactiveWithoutStamp !== null && $inactiveWithoutStamp['state'] === '23514'
            && str_contains($inactiveWithoutStamp['message'], 'chk_mrs_deactivation_coherence'),
        $inactiveWithoutStamp === null ? 'la baja sin rastro pasó' : 'estado=' . $inactiveWithoutStamp['state']
    );

    $emptyNotesMachineId = $nextMachine();
    $emptyNotes = PostgresFixtureBuilder::expectRejection(static function () use ($insertSchedule, $pgPdo, $emptyNotesMachineId, $actorId): void {
        $insertSchedule($pgPdo, $emptyNotesMachineId, $actorId, 'DAILY', null, null, false, true, null, '   ');
    });
    $assert(
        'Una nota de agenda vacía se rechaza con 23514 (chk_mrs_notes_length)',
        $emptyNotes !== null && $emptyNotes['state'] === '23514'
            && str_contains($emptyNotes['message'], 'chk_mrs_notes_length'),
        $emptyNotes === null ? 'la nota vacía pasó' : 'estado=' . $emptyNotes['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Versionado inmutable por fila (QA-2, Art. III.3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Versionado de la agenda: una activa por máquina ---\n";

    $versionedMachineId = $nextMachine();
    $firstScheduleId = $insertSchedule($pgPdo, $versionedMachineId, $actorId, 'DAILY');

    $duplicateActive = PostgresFixtureBuilder::expectRejection(static function () use ($insertSchedule, $pgPdo, $versionedMachineId, $actorId): void {
        $insertSchedule($pgPdo, $versionedMachineId, $actorId, 'TUE_THU');
    });
    $assert(
        'Una segunda agenda activa de la misma máquina se rechaza con 23505 nombrando el índice',
        $duplicateActive !== null && $duplicateActive['state'] === '23505'
            && str_contains($duplicateActive['message'], 'uq_machine_restock_schedule_active'),
        $duplicateActive === null ? 'la segunda agenda activa se persistió' : 'estado=' . $duplicateActive['state']
    );

    $deactivate = $pgPdo->prepare(
        'UPDATE machine_restock_schedules
            SET is_active = FALSE, deactivated_at = CURRENT_TIMESTAMP, deactivated_by = :actor
          WHERE id = :id'
    );
    $deactivate->execute([':actor' => $actorId, ':id' => $firstScheduleId]);

    $secondScheduleId = $insertSchedule($pgPdo, $versionedMachineId, $actorId, 'MON_WED_FRI');
    $versionCount = $pgPdo->prepare('SELECT count(*) FROM machine_restock_schedules WHERE machine_id = :machine');
    $versionCount->execute([':machine' => $versionedMachineId]);
    $versionCountValue = (int)$versionCount->fetchColumn();

    $assert(
        'Cerrar la versión vigente y sembrar la nueva conserva las dos filas del histórico',
        $secondScheduleId !== $firstScheduleId && $versionCountValue === 2,
        sprintf('versiones=%d · activa nueva=%d · cerrada=%d', $versionCountValue, $secondScheduleId, $firstScheduleId)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Guardas de coherencia de la excepción diaria (C1, Art. V.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Guardas chk_rrdo_* de la excepción diaria ---\n";

    $skipMachineId = $nextMachine();
    $skipDate = '2026-11-14';

    $shortReason = PostgresFixtureBuilder::expectRejection(static function () use ($insertOverride, $pgPdo, $skipMachineId, $skipDate, $actorId, $restockerId): void {
        $insertOverride($pgPdo, [
            'machine_id' => $skipMachineId,
            'service_date' => $skipDate,
            'override_type' => 'SKIPPED',
            'original_restocker_id' => $restockerId,
            'skip_reason_code' => 'LOCATION_CLOSED',
            'reason_text' => str_repeat('x', 19),
            'created_by' => $actorId,
        ]);
    });
    $assert(
        'Una omisión con motivo de 19 caracteres se rechaza con 23514 (Art. V.1)',
        $shortReason !== null && $shortReason['state'] === '23514'
            && str_contains($shortReason['message'], 'chk_rrdo_type_fields_coherence'),
        $shortReason === null ? 'el motivo corto pasó' : 'estado=' . $shortReason['state']
    );

    $missingReasonCode = PostgresFixtureBuilder::expectRejection(static function () use ($insertOverride, $pgPdo, $skipMachineId, $skipDate, $actorId, $restockerId): void {
        $insertOverride($pgPdo, [
            'machine_id' => $skipMachineId,
            'service_date' => $skipDate,
            'override_type' => 'SKIPPED',
            'original_restocker_id' => $restockerId,
            'reason_text' => 'Sede cerrada por inventario anual.',
            'created_by' => $actorId,
        ]);
    });
    $assert(
        'Una omisión sin causa tipificada se rechaza con 23514',
        $missingReasonCode !== null && $missingReasonCode['state'] === '23514',
        $missingReasonCode === null ? 'la omisión sin causa pasó' : 'estado=' . $missingReasonCode['state']
    );

    $skippedId = $insertOverride($pgPdo, [
        'machine_id' => $skipMachineId,
        'service_date' => $skipDate,
        'override_type' => 'SKIPPED',
        'original_restocker_id' => $restockerId,
        'skip_reason_code' => 'LOCATION_CLOSED',
        'reason_text' => 'Sede cerrada por inventario anual.',
        'had_active_fefo_alert' => true,
        'created_by' => $actorId,
    ]);
    $assert(
        'Una omisión con causa y motivo de 20 caracteres reales se admite con su sello FEFO',
        $skippedId > 0,
        'id=' . $skippedId
    );

    $duplicateOverride = PostgresFixtureBuilder::expectRejection(static function () use ($insertOverride, $pgPdo, $skipMachineId, $skipDate, $actorId): void {
        $insertOverride($pgPdo, [
            'machine_id' => $skipMachineId,
            'service_date' => $skipDate,
            'override_type' => 'SKIPPED',
            'skip_reason_code' => 'NO_KEY_OR_ACCESS',
            'reason_text' => 'Sin llave de acceso durante la jornada.',
            'created_by' => $actorId,
        ]);
    });
    $assert(
        'Una segunda excepción activa para la misma máquina y día se rechaza con 23505',
        $duplicateOverride !== null && $duplicateOverride['state'] === '23505'
            && str_contains($duplicateOverride['message'], 'uq_restock_override_active_machine_day'),
        $duplicateOverride === null ? 'la segunda excepción activa se persistió' : 'estado=' . $duplicateOverride['state']
    );

    $reassignMachineId = $nextMachine();
    $reassignWithoutTarget = PostgresFixtureBuilder::expectRejection(static function () use ($insertOverride, $pgPdo, $reassignMachineId, $actorId): void {
        $insertOverride($pgPdo, [
            'machine_id' => $reassignMachineId,
            'service_date' => '2026-11-14',
            'override_type' => 'REASSIGNED',
            'reassignment_reason_code' => 'ZONE_UNCOVERED',
            'created_by' => $actorId,
        ]);
    });
    $assert(
        'Un desvío sin reponedor destino se rechaza con 23514',
        $reassignWithoutTarget !== null && $reassignWithoutTarget['state'] === '23514',
        $reassignWithoutTarget === null ? 'el desvío sin destino pasó' : 'estado=' . $reassignWithoutTarget['state']
    );

    $reassignedId = $insertOverride($pgPdo, [
        'machine_id' => $reassignMachineId,
        'service_date' => '2026-11-14',
        'override_type' => 'REASSIGNED',
        'target_restocker_id' => $restockerId,
        'reassignment_reason_code' => 'ZONE_UNCOVERED',
        'reason_text' => 'Cobertura puntual de la zona este.',
        'created_by' => $actorId,
    ]);
    $assert(
        'Un desvío con destino y causa tipificada se admite',
        $reassignedId > 0,
        'id=' . $reassignedId
    );

    $revokeMachineId = $nextMachine();
    $revokedWithoutStamp = PostgresFixtureBuilder::expectRejection(static function () use ($insertOverride, $pgPdo, $revokeMachineId, $actorId): void {
        $insertOverride($pgPdo, [
            'machine_id' => $revokeMachineId,
            'service_date' => '2026-11-14',
            'override_type' => 'SKIPPED',
            'status' => 'REVOKED',
            'skip_reason_code' => 'LOCAL_HOLIDAY',
            'reason_text' => 'Festivo local declarado por la sede.',
            'created_by' => $actorId,
        ]);
    });
    $assert(
        'Una excepción REVOKED sin instante de resolución se rechaza con 23514',
        $revokedWithoutStamp !== null && $revokedWithoutStamp['state'] === '23514'
            && str_contains($revokedWithoutStamp['message'], 'chk_rrdo_status_resolution_coherence'),
        $revokedWithoutStamp === null ? 'la revocación sin sello pasó' : 'estado=' . $revokedWithoutStamp['state']
    );

    $revokedId = $insertOverride($pgPdo, [
        'machine_id' => $revokeMachineId,
        'service_date' => '2026-11-14',
        'override_type' => 'SKIPPED',
        'status' => 'REVOKED',
        'skip_reason_code' => 'LOCAL_HOLIDAY',
        'reason_text' => 'Festivo local declarado por la sede.',
        'resolved_by' => $actorId,
        'resolved_at' => '2026-11-14 09:00:00+00',
        'created_by' => $actorId,
    ]);
    $assert(
        'Una excepción REVOKED con instante y actor de resolución se admite',
        $revokedId > 0,
        'id=' . $revokedId
    );

    // ─────────────────────────────────────────────────────────────────────
    // 8. Cola Offline (RF-05.1.1, C3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Catálogo Offline: SKIP_RESTOCK_STOP ---\n";

    $offlineInsert = static function (PDO $pdo, int $technicianId, string $mutationId, string $operation): void {
        $stmt = $pdo->prepare(
            "INSERT INTO offline_sync_mutations
                (technician_id, client_mutation_id, stop_type, stop_id, operation_type, outcome_status,
                 client_occurred_at, effective_occurred_at)
             VALUES
                (:technician_id, :client_mutation_id, 'MACHINE', 1, :operation_type, 'APPLIED',
                 '2026-11-14 09:00:00+00', '2026-11-14 09:00:00+00')"
        );
        $stmt->execute([
            ':technician_id' => $technicianId,
            ':client_mutation_id' => $mutationId,
            ':operation_type' => $operation,
        ]);
    };

    $skipOperationAccepted = PostgresFixtureBuilder::expectRejection(static function () use ($offlineInsert, $pgPdo, $restockerId): void {
        $offlineInsert($pgPdo, $restockerId, 'rrp02.skip.0001', 'SKIP_RESTOCK_STOP');
    });
    $assert(
        'offline_sync_mutations admite SKIP_RESTOCK_STOP desde el móvil',
        $skipOperationAccepted === null,
        $skipOperationAccepted === null ? 'admitida' : 'rechazada con ' . $skipOperationAccepted['state']
    );

    $unknownOperation = PostgresFixtureBuilder::expectRejection(static function () use ($offlineInsert, $pgPdo, $restockerId): void {
        $offlineInsert($pgPdo, $restockerId, 'rrp02.skip.0002', 'SKIP_UNKNOWN_STOP');
    });
    $assert(
        'offline_sync_mutations sigue rechazando una operación fuera de catálogo (23514)',
        $unknownOperation !== null && $unknownOperation['state'] === '23514'
            && str_contains($unknownOperation['message'], 'chk_offline_sync_operation_type'),
        $unknownOperation === null ? 'la operación desconocida pasó' : 'estado=' . $unknownOperation['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 9. Art. III.1: el borrado físico se rechaza en las dos tablas
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Art. III.1: rechazo ruidoso de «DELETE FROM» ---\n";

    $scheduleDelete = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $firstScheduleId): void {
        $stmt = $pgPdo->prepare('DELETE FROM machine_restock_schedules WHERE id = :id');
        $stmt->execute([':id' => $firstScheduleId]);
    });
    $assert(
        'machine_restock_schedules rechaza el borrado físico con 45000 (Art. III.1)',
        $scheduleDelete !== null && $scheduleDelete['state'] === '45000'
            && str_contains($scheduleDelete['message'], 'ART_III_1_VIOLATION'),
        $scheduleDelete === null ? 'la agenda se dejó borrar' : 'estado=' . $scheduleDelete['state']
    );

    $overrideDelete = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $skippedId): void {
        $stmt = $pgPdo->prepare('DELETE FROM restock_route_daily_overrides WHERE id = :id');
        $stmt->execute([':id' => $skippedId]);
    });
    $assert(
        'restock_route_daily_overrides rechaza el borrado físico con 45000 (Art. III.1)',
        $overrideDelete !== null && $overrideDelete['state'] === '45000'
            && str_contains($overrideDelete['message'], 'ART_III_1_VIOLATION'),
        $overrideDelete === null ? 'la excepción se dejó borrar' : 'estado=' . $overrideDelete['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 10. Art. IV: inventario exacto y DDL de la sede intacto
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Art. IV: inventario de tablas y DDL de locations ---\n";

    $tables = PostgresFixtureBuilder::tables($pgPdo);
    $locationColumns = PostgresFixtureBuilder::columns($pgPdo, 'locations');

    $assert(
        'El esquema declara exactamente 51 tablas de dominio y locations conserva sus 23 columnas',
        count($tables) === 51
            && in_array('machine_restock_schedules', $tables, true)
            && in_array('restock_route_daily_overrides', $tables, true)
            && count($locationColumns) === 23,
        sprintf('tablas=%d · columnas de locations=%d', count($tables), count($locationColumns))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 11. Semillas 1:1 e idempotencia: supabase_seeds.sql (§23–§24) vs. SeedRunner
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Semillas 1:1 e idempotencia (RNF-01, DoD 1) ---\n";

    $seedsContent = (string)file_get_contents($projectRoot . '/database/supabase_seeds.sql');
    $sqlSchedulesStmt = null;
    $sqlOverridesStmt = null;
    foreach (SqlScriptSplitter::split($seedsContent) as $seedSqlStmt) {
        $trimmed = ltrim($seedSqlStmt);
        if (str_starts_with($trimmed, 'INSERT INTO machine_restock_schedules')) {
            $sqlSchedulesStmt = $seedSqlStmt;
        } elseif (str_starts_with($trimmed, 'INSERT INTO restock_route_daily_overrides')) {
            $sqlOverridesStmt = $seedSqlStmt;
        }
    }

    $assert(
        'database/supabase_seeds.sql declara las sentencias de agenda (§23) y excepción diaria (§24)',
        $sqlSchedulesStmt !== null && $sqlOverridesStmt !== null
    );

    $insertDemoUser = $pgPdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role, is_active)
         VALUES (:name, :email, :email_key, :password_hash, :role, TRUE)
         RETURNING id'
    );
    $insertDemoUser->execute([
        ':name' => 'Coordinación Demo M37',
        ':email' => 'coordinacion@vendguard.internal',
        ':email_key' => 'coordinacion@vendguard.internal',
        ':password_hash' => password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 4]),
        ':role' => 'COORDINATOR',
    ]);
    $insertDemoUser->execute([
        ':name' => 'Lucía Repostaje Demo M37',
        ':email' => 'lucia.repostaje@vendguard.internal',
        ':email_key' => 'lucia.repostaje@vendguard.internal',
        ':password_hash' => password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 4]),
        ':role' => 'RESTOCKER',
    ]);

    $seedLocationId = $createLocation($pgPdo, 'SEEDS-37');
    foreach (['VEND-0101', 'VEND-0201', 'VEND-0301', 'VEND-0502', 'VEND-0601', 'VEND-0701'] as $demoCode) {
        $createMachine($pgPdo, $seedLocationId, $demoCode);
    }

    $fetchSeededSchedules = static function (PDO $pdo): array {
        $stmt = $pdo->query(
            "SELECT m.code AS machine_code,
                    s.cadence_type::text AS cadence_type,
                    s.day_of_week,
                    s.anchor_date::text AS anchor_date,
                    s.preferred_time_window::text AS preferred_time_window,
                    s.include_weekends,
                    s.notes
               FROM machine_restock_schedules s
               INNER JOIN machines m ON m.id = s.machine_id
              WHERE m.code IN ('VEND-0101', 'VEND-0201', 'VEND-0301', 'VEND-0601', 'VEND-0701')
                AND s.is_active = TRUE
              ORDER BY m.code ASC"
        );
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['day_of_week'] = $row['day_of_week'] !== null ? (int)$row['day_of_week'] : null;
            $row['include_weekends'] = filter_var($row['include_weekends'], FILTER_VALIDATE_BOOL);
            $rows[] = $row;
        }

        return $rows;
    };

    $fetchSeededOverrides = static function (PDO $pdo): array {
        $stmt = $pdo->query(
            "SELECT m.code AS machine_code,
                    o.service_date::text AS service_date,
                    o.override_type::text AS override_type,
                    o.status::text AS status,
                    u.email_key AS original_restocker_email,
                    o.skip_reason_code::text AS skip_reason_code,
                    o.reason_text,
                    o.had_active_fefo_alert,
                    c.email_key AS created_by_email
               FROM restock_route_daily_overrides o
               INNER JOIN machines m ON m.id = o.machine_id
               INNER JOIN users u ON u.id = o.original_restocker_id
               INNER JOIN users c ON c.id = o.created_by
              WHERE m.code = 'VEND-0502'
              ORDER BY o.service_date ASC, o.id ASC"
        );
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['had_active_fefo_alert'] = filter_var($row['had_active_fefo_alert'], FILTER_VALIDATE_BOOL);
            $rows[] = $row;
        }

        return $rows;
    };

    $seedRunner = new SeedRunner($pgPdo);

    // Rama SQL dentro de transacción revertible para comparar 1:1 sin borrar filas Art. III.1.
    $pgPdo->beginTransaction();
    $sqlSchedulesPass1 = $pgPdo->exec((string)$sqlSchedulesStmt);
    $sqlOverridesPass1 = $pgPdo->exec((string)$sqlOverridesStmt);
    $sqlScheduleRows = $fetchSeededSchedules($pgPdo);
    $sqlOverrideRows = $fetchSeededOverrides($pgPdo);
    $sqlSchedulesPass2 = $pgPdo->exec((string)$sqlSchedulesStmt);
    $sqlOverridesPass2 = $pgPdo->exec((string)$sqlOverridesStmt);
    $crossPhpSchedulesAfterSql = $seedRunner->seedMachineRestockSchedules();
    $crossPhpOverridesAfterSql = $seedRunner->seedRestockRouteDailyOverrides();
    $pgPdo->rollBack();

    $assert(
        'supabase_seeds.sql inserta exactamente 5 agendas y 1 excepción SKIPPED y es idempotente (2ª pasada = 0)',
        $sqlSchedulesPass1 === 5
            && $sqlOverridesPass1 === 1
            && $sqlSchedulesPass2 === 0
            && $sqlOverridesPass2 === 0
            && $crossPhpSchedulesAfterSql === 0
            && $crossPhpOverridesAfterSql === 0,
        sprintf(
            'sql1=%d/%d · sql2=%d/%d · crossPhp=%d/%d',
            (int)$sqlSchedulesPass1,
            (int)$sqlOverridesPass1,
            (int)$sqlSchedulesPass2,
            (int)$sqlOverridesPass2,
            $crossPhpSchedulesAfterSql,
            $crossPhpOverridesAfterSql
        )
    );

    // Rama PHP (SeedRunner) sobre el mismo estado limpio tras el rollback anterior.
    $phpSchedulesPass1 = $seedRunner->seedMachineRestockSchedules();
    $phpOverridesPass1 = $seedRunner->seedRestockRouteDailyOverrides();
    $phpScheduleRows = $fetchSeededSchedules($pgPdo);
    $phpOverrideRows = $fetchSeededOverrides($pgPdo);
    $phpSchedulesPass2 = $seedRunner->seedMachineRestockSchedules();
    $phpOverridesPass2 = $seedRunner->seedRestockRouteDailyOverrides();
    $crossSqlSchedulesAfterPhp = $pgPdo->exec((string)$sqlSchedulesStmt);
    $crossSqlOverridesAfterPhp = $pgPdo->exec((string)$sqlOverridesStmt);

    $assert(
        'SeedRunner inserta 5 agendas y 1 excepción SKIPPED con idempotencia propia y cruzada ante supabase_seeds.sql',
        $phpSchedulesPass1 === 5
            && $phpOverridesPass1 === 1
            && $phpSchedulesPass2 === 0
            && $phpOverridesPass2 === 0
            && $crossSqlSchedulesAfterPhp === 0
            && $crossSqlOverridesAfterPhp === 0,
        sprintf(
            'php1=%d/%d · php2=%d/%d · crossSql=%d/%d',
            $phpSchedulesPass1,
            $phpOverridesPass1,
            $phpSchedulesPass2,
            $phpOverridesPass2,
            (int)$crossSqlSchedulesAfterPhp,
            (int)$crossSqlOverridesAfterPhp
        )
    );

    $seededCadences = array_column($phpScheduleRows, 'cadence_type');
    sort($seededCadences);
    $expectedCadences = ['DAILY', 'MON_WED_FRI', 'TUE_THU', 'WEEKLY', 'BIWEEKLY'];
    sort($expectedCadences);

    $assert(
        'supabase_seeds.sql y SeedRunner convergen 1:1 en las 5 agendas (5 cadencias) y la excepción SKIPPED',
        count($phpScheduleRows) === 5
            && $seededCadences === $expectedCadences
            && $sqlScheduleRows === $phpScheduleRows
            && count($phpOverrideRows) === 1
            && $phpOverrideRows[0]['machine_code'] === 'VEND-0502'
            && $phpOverrideRows[0]['service_date'] === '2026-11-14'
            && $phpOverrideRows[0]['override_type'] === 'SKIPPED'
            && $phpOverrideRows[0]['status'] === 'ACTIVE'
            && $phpOverrideRows[0]['skip_reason_code'] === 'NO_KEY_OR_ACCESS'
            && $sqlOverrideRows === $phpOverrideRows,
        sprintf('cadencias=%s · filas=%d/%d', implode(',', $seededCadences), count($phpScheduleRows), count($phpOverrideRows))
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        if ($pgPdo->inTransaction()) {
            $pgPdo->rollBack();
        }
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, PLANNER_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    // `to_regclass` devuelve `NULL` cuando la relación no existe: es la sonda de residuo que no
    // depende del texto exacto del mensaje del motor.
    $residueProbe = $pgPdo->query(
        "SELECT to_regclass('" . PLANNER_TEST_SCHEMA . ".locations')"
    )->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El escenario se retira entero: el esquema aislado y sus tablas ya no existen en el motor',
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
    echo " RESULT: 100% EN VERDE. T-RRP-02 (ESQUEMA DEL PLANIFICADOR) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLOS DETECTADOS EN LA CERTIFICACIÓN DEL ESQUEMA DEL PLANIFICADOR.\n";
echo "======================================================================\n";
exit(1);
