<?php

declare(strict_types=1);

/**
 * PostgresRestockerZoneSchemaTest · T-RES-02
 *
 * Certifica en el motor real el esquema del Módulo 31 (`31-restocker-role-zones-shifts`): el
 * rol `RESTOCKER`, la guarda de guardia técnica y la Tabla 41 `restocker_zone_assignments`. No
 * comprueba los servicios ni la API —eso llega con las tareas `T-RES-05` a `T-RES-11`— sino las
 * garantías que la base de datos debe imponer por sí sola, porque son las que ningún camino de
 * código puede saltarse:
 *
 *   1. **Forma de la Tabla 41 (RF-02.1, QA 1).** Siete columnas, con `role_in_zone VARCHAR(10)`
 *      e `is_primary BOOLEAN` sincronizados, referencias `ON DELETE RESTRICT ON UPDATE CASCADE`
 *      a `users` y `operational_zones`, y la baja lógica `removed_at`.
 *   2. **Coherencia del vínculo (QA 1).** `chk_restocker_zone_role_coherence` rechaza con
 *      `SQLSTATE '23514'` la combinación contradictoria (`PRIMARY` con `is_primary = FALSE` y
 *      `BACKUP` con `is_primary = TRUE`) y admite las dos formas legítimas, que es lo que
 *      permite aceptar cualquiera de los dos campos en el payload.
 *   3. **Titularidad única viva (RF-02.1, D3).** `uq_restocker_primary_zone` rechaza con `23505`
 *      el segundo titularato vivo del mismo reponedor, mientras que las suplencias no tienen
 *      límite: el índice es parcial (`WHERE is_primary = TRUE AND removed_at IS NULL`).
 *   4. **Una vinculación viva por pareja (Art. III.1).** `uq_restocker_zone_live` rechaza el
 *      duplicado vivo del mismo reponedor en la misma zona; la baja es lógica y el reingreso
 *      estrena fila, de modo que la unicidad parcial no borra el histórico.
 *   5. **Orden de la baja (Art. III.3).** `chk_restocker_zone_removed_order` impide una baja
 *      anterior al alta, que haría ilegible la línea de tiempo del cuadrante.
 *   6. **Borrado físico prohibido (Art. III.1).** `DELETE` sobre la tabla lanza
 *      `SQLSTATE '45000'` con la marca constitucional; abierta la ventana de purga del arnés, la
 *      fila se puede retirar.
 *   7. **El rol y su entidad auditable existen de verdad (RF-01.1, QA 1).** Los valores
 *      `RESTOCKER` de `user_role` y `RESTOCKER_ZONE` de `audit_entity_type` se pueden castear en
 *      el motor: un miembro declarado que el motor no aceptara no serviría de nada.
 *   8. **Guarda de guardia técnica (D3, Art. II).** `chk_users_restocker_not_on_call` muerde por
 *      sí sola: la sonda retira la guarda genérica `chk_users_on_call_active_tech` dentro de una
 *      transacción que se revierte, para no medir el rechazo de la restricción vecina.
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
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresRestockerZoneSchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Infrastructure\Database\PostgresMigrationRunner;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado de esta suite: no comparte escenario con las suites del módulo 17. */
const RESTOCKER_TEST_SCHEMA = 't_res02_cuadrante_carga';

/** Marca que el disparador constitucional antepone a sus rechazos (Art. III.1). */
const PURGE_VIOLATION_MARKER = 'ART_III_1_VIOLATION';

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
echo " VENDGUARD · T-RES-02 · Esquema del rol RESTOCKER y la Tabla 41 (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures: solo lo que las reglas necesitan para poder dispararse
// ─────────────────────────────────────────────────────────────────────────────

/** Alta de zona operativa y devolución de su identificador. */
$createZone = static function (PDO $pdo, string $code, string $name, string $color = '#2560ff'): int {
    $stmt = $pdo->prepare(
        'INSERT INTO operational_zones (code, name, color_hex) VALUES (:code, :name, :color) RETURNING id'
    );
    $stmt->execute([':code' => $code, ':name' => $name, ':color' => $color]);

    return (int)$stmt->fetchColumn();
};

/** Alta de reponedor: el rol nuevo, sin guardia técnica (Art. II). */
$createRestocker = static function (PDO $pdo, string $suffix, string $name): int {
    $stmt = $pdo->prepare(
        "INSERT INTO users (name, email, email_key, password_hash, role, operator_code)
         VALUES (:name, :email, :email_key, :hash, 'RESTOCKER', :operator)
         RETURNING id"
    );
    $stmt->execute([
        ':name' => $name,
        ':email' => 'repostaje.cert.' . $suffix . '@vendguard.internal',
        ':email_key' => 'repostaje.cert.' . $suffix . '@vendguard.internal',
        ':hash' => password_hash('carga-' . $suffix, PASSWORD_BCRYPT),
        ':operator' => 'REC-' . strtoupper($suffix),
    ]);

    return (int)$stmt->fetchColumn();
};

/** Vinculación de carga: `role_in_zone` e `is_primary` viajan siempre sincronizados. */
$assignRestocker = static function (PDO $pdo, int $restockerId, int $zoneId, string $roleInZone): void {
    $stmt = $pdo->prepare(
        'INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES (:restocker, :zone, :role_in_zone, :is_primary)'
    );
    $stmt->execute([
        ':restocker' => $restockerId,
        ':zone' => $zoneId,
        ':role_in_zone' => $roleInZone,
        ':is_primary' => $roleInZone === 'PRIMARY' ? 'TRUE' : 'FALSE',
    ]);
};

$crash = null;
$residueProbe = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio entero en un esquema aislado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones, incluida la 027) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, RESTOCKER_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base y la capa incremental aplican, incluida la migración 027 del Módulo 31',
        in_array('027_restocker_role_and_zone_assignments.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf('%d sentencias · migraciones: %d · pendientes: %s', $schemaReport['statements'], count($schemaReport['migrations']), json_encode($convergence['pending']))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1. Forma de la Tabla 41 (RF-02.1, QA 1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Forma de la Tabla 41 restocker_zone_assignments ---\n";

    $columns = PostgresFixtureBuilder::columns($pgPdo, 'restocker_zone_assignments');
    $assert(
        'La tabla declara sus siete columnas canónicas en el orden del contrato',
        array_keys($columns) === ['id', 'restocker_id', 'zone_id', 'role_in_zone', 'is_primary', 'assigned_at', 'removed_at'],
        implode(', ', array_keys($columns))
    );

    $assert(
        'role_in_zone es VARCHAR(10) e is_primary BOOLEAN, ambos obligatorios',
        ($columns['role_in_zone']['udt_name'] ?? '') === 'varchar'
            && ($columns['role_in_zone']['max_length'] ?? 0) === 10
            && $columns['role_in_zone']['nullable'] === false
            && ($columns['is_primary']['udt_name'] ?? '') === 'bool'
            && $columns['is_primary']['nullable'] === false,
        sprintf('role_in_zone=%s(%d) · is_primary=%s', $columns['role_in_zone']['udt_name'] ?? '?', $columns['role_in_zone']['max_length'] ?? 0, $columns['is_primary']['udt_name'] ?? '?')
    );

    $assert(
        'La baja es lógica: removed_at es TIMESTAMPTZ nullable y no existe delete físico de columna',
        ($columns['removed_at']['udt_name'] ?? '') === 'timestamptz' && $columns['removed_at']['nullable'] === true
    );

    $foreignKeys = $pgPdo->query(
        "SELECT con.conname AS name, pg_get_constraintdef(con.oid) AS definition
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'restocker_zone_assignments' AND con.contype = 'f'"
    )->fetchAll();

    $foreignKeyDefinitions = [];
    foreach ($foreignKeys as $foreignKey) {
        $foreignKeyDefinitions[(string)$foreignKey['name']] = (string)$foreignKey['definition'];
    }

    $assert(
        'Las dos claves foráneas referencian users y operational_zones con RESTRICT y CASCADE',
        isset($foreignKeyDefinitions['fk_restocker_zone_restocker'], $foreignKeyDefinitions['fk_restocker_zone_zone'])
            && str_contains($foreignKeyDefinitions['fk_restocker_zone_restocker'], 'users(id)')
            && str_contains($foreignKeyDefinitions['fk_restocker_zone_restocker'], 'ON DELETE RESTRICT')
            && str_contains($foreignKeyDefinitions['fk_restocker_zone_restocker'], 'ON UPDATE CASCADE')
            && str_contains($foreignKeyDefinitions['fk_restocker_zone_zone'], 'operational_zones(id)')
            && str_contains($foreignKeyDefinitions['fk_restocker_zone_zone'], 'ON DELETE RESTRICT'),
        implode(' | ', array_keys($foreignKeyDefinitions))
    );

    $checks = $pgPdo->query(
        "SELECT con.conname AS name
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'restocker_zone_assignments' AND con.contype = 'c'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $assert(
        'Las tres restricciones CHECK del contrato están declaradas en la tabla',
        array_values(array_intersect(
            ['chk_restocker_zone_role_values', 'chk_restocker_zone_role_coherence', 'chk_restocker_zone_removed_order'],
            $checks
        )) === ['chk_restocker_zone_role_values', 'chk_restocker_zone_role_coherence', 'chk_restocker_zone_removed_order'],
        'declaradas: ' . implode(', ', $checks)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Índices únicos parciales (RF-02.1, D3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Índices únicos parciales del cuadrante de carga ---\n";

    $indexDefinition = static function (PDO $pdo, string $name): string {
        $stmt = $pdo->prepare('SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = :name');
        $stmt->execute([':name' => $name]);

        return (string)($stmt->fetchColumn() ?: '');
    };

    $liveDefinition = $indexDefinition($pgPdo, 'uq_restocker_zone_live');
    $primaryDefinition = $indexDefinition($pgPdo, 'uq_restocker_primary_zone');

    $assert(
        'uq_restocker_zone_live es único sobre (restocker_id, zone_id) y solo vigila las filas vivas',
        str_contains($liveDefinition, 'UNIQUE')
            && str_contains($liveDefinition, 'restocker_id')
            && str_contains($liveDefinition, 'zone_id')
            && str_contains($liveDefinition, 'removed_at IS NULL'),
        $liveDefinition
    );

    $assert(
        'uq_restocker_primary_zone es único sobre restocker_id y solo vigila la titularidad viva',
        str_contains($primaryDefinition, 'UNIQUE')
            && str_contains($primaryDefinition, 'is_primary = true')
            && str_contains($primaryDefinition, 'removed_at IS NULL'),
        $primaryDefinition
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. El rol nuevo y su entidad auditable existen de verdad (RF-01.1, QA 1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Rol RESTOCKER y entidad auditable RESTOCKER_ZONE ---\n";

    $roleCast = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->query("SELECT 'RESTOCKER'::user_role, 'RESTOCKER_ZONE'::audit_entity_type")->fetch();
    });

    $assert(
        'Los valores RESTOCKER y RESTOCKER_ZONE se pueden castear en el motor',
        $roleCast === null,
        $roleCast === null ? 'admitidos' : 'rechazado con ' . $roleCast['state']
    );

    $assert(
        'El miembro RESTOCKER cierra el conjunto de user_role sin alterar el ordinal histórico',
        $pgPdo->query(
            "SELECT string_agg(e.enumlabel, ',' ORDER BY e.enumsortorder)
               FROM pg_type t JOIN pg_enum e ON e.enumtypid = t.oid
              WHERE t.typname = 'user_role'
                AND t.typnamespace = current_schema()::regnamespace"
        )->fetchColumn() === 'COORDINATOR,TECHNICIAN,RESTOCKER'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Coherencia del vínculo (QA 1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Coherencia de role_in_zone con is_primary ---\n";

    $zoneA = $createZone($pgPdo, 'ZONA-RES-A', 'Zona de Carga Alfa');
    $zoneB = $createZone($pgPdo, 'ZONA-RES-B', 'Zona de Carga Beta', '#10b981');
    $zoneC = $createZone($pgPdo, 'ZONA-RES-C', 'Zona de Carga Gamma', '#f59e0b');

    $restockerOne = $createRestocker($pgPdo, 'uno', 'Lucía Reponedora de Certificación');
    $restockerTwo = $createRestocker($pgPdo, 'dos', 'Óscar Reponedor de Certificación');

    $assert(
        'El escenario siembra las tres zonas y los dos reponedores con rol RESTOCKER',
        $zoneA > 0 && $zoneB > 0 && $zoneC > 0 && $restockerOne > 0 && $restockerTwo > 0
    );

    $mismatchPrimary = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $restockerOne, $zoneA): void {
        $stmt = $pgPdo->prepare(
            "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
             VALUES (:restocker, :zone, 'PRIMARY', FALSE)"
        );
        $stmt->execute([':restocker' => $restockerOne, ':zone' => $zoneA]);
    });

    $assert(
        'PRIMARY con is_primary = FALSE se rechaza con 23514 nombrando chk_restocker_zone_role_coherence',
        $mismatchPrimary !== null
            && $mismatchPrimary['state'] === '23514'
            && str_contains($mismatchPrimary['message'], 'chk_restocker_zone_role_coherence'),
        $mismatchPrimary === null ? 'la combinación contradictoria se admitió' : 'estado=' . $mismatchPrimary['state']
    );

    $mismatchBackup = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $restockerOne, $zoneA): void {
        $stmt = $pgPdo->prepare(
            "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
             VALUES (:restocker, :zone, 'BACKUP', TRUE)"
        );
        $stmt->execute([':restocker' => $restockerOne, ':zone' => $zoneA]);
    });

    $assert(
        'BACKUP con is_primary = TRUE se rechaza con 23514 por la misma restricción',
        $mismatchBackup !== null
            && $mismatchBackup['state'] === '23514'
            && str_contains($mismatchBackup['message'], 'chk_restocker_zone_role_coherence'),
        $mismatchBackup === null ? 'la combinación contradictoria se admitió' : 'estado=' . $mismatchBackup['state']
    );

    // La coherencia y el catálogo conviven en la misma tabla y el motor informa solo la primera
    // restricción que evalúa, así que se retira la coherencia **dentro de una transacción que se
    // revierte** para medir el catálogo por sí solo. El escenario queda intacto tras el rollback.
    $invalidRole = '';
    $pgPdo->beginTransaction();
    try {
        $pgPdo->exec('ALTER TABLE restocker_zone_assignments DROP CONSTRAINT chk_restocker_zone_role_coherence');
        $stmt = $pgPdo->prepare(
            "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
             VALUES (:restocker, :zone, 'OWNER', FALSE)"
        );
        $stmt->execute([':restocker' => $restockerOne, ':zone' => $zoneA]);
    } catch (Throwable $exception) {
        $invalidRole = $exception->getMessage();
    } finally {
        $pgPdo->rollBack();
    }

    $assert(
        'Aislado de la coherencia, chk_restocker_zone_role_values rechaza un role_in_zone fuera del catálogo',
        $invalidRole !== '' && str_contains($invalidRole, 'chk_restocker_zone_role_values'),
        $invalidRole === '' ? 'el valor inválido se admitió' : mb_substr(str_replace("\n", ' ', $invalidRole), 0, 110)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Titularidad única y suplencias sin límite (RF-02.1, D3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Titularidad única y suplencias del reponedor ---\n";

    $assignRestocker($pgPdo, $restockerOne, $zoneA, 'PRIMARY');

    $secondPrimary = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $restockerOne, $zoneB): void {
        $stmt = $pgPdo->prepare(
            "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
             VALUES (:restocker, :zone, 'PRIMARY', TRUE)"
        );
        $stmt->execute([':restocker' => $restockerOne, ':zone' => $zoneB]);
    });

    $assert(
        'Un reponedor no puede ser titular de dos zonas vivas: uq_restocker_primary_zone responde 23505',
        $secondPrimary !== null
            && $secondPrimary['state'] === '23505'
            && str_contains($secondPrimary['message'], 'uq_restocker_primary_zone'),
        $secondPrimary === null ? 'la segunda titularidad se admitió' : 'estado=' . $secondPrimary['state']
    );

    $assignRestocker($pgPdo, $restockerOne, $zoneB, 'BACKUP');
    $assignRestocker($pgPdo, $restockerOne, $zoneC, 'BACKUP');
    $assignRestocker($pgPdo, $restockerTwo, $zoneA, 'BACKUP');

    $backups = (int)$pgPdo->query(
        'SELECT COUNT(*) FROM restocker_zone_assignments
          WHERE restocker_id = ' . $restockerOne . ' AND is_primary = FALSE AND removed_at IS NULL'
    )->fetchColumn();

    $assert(
        'El mismo reponedor acumula varias suplencias vivas en zonas distintas',
        $backups === 2,
        'suplencias vivas: ' . $backups
    );

    $assert(
        'Dos reponedores pueden coexistir en la misma zona con roles distintos (D2)',
        (int)$pgPdo->query(
            'SELECT COUNT(*) FROM restocker_zone_assignments WHERE zone_id = ' . $zoneA . ' AND removed_at IS NULL'
        )->fetchColumn() === 2
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Una vinculación viva por pareja y reingreso con histórico (Art. III.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Unicidad de la pareja y reingreso con histórico ---\n";

    $duplicateLive = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $restockerOne, $zoneA): void {
        $stmt = $pgPdo->prepare(
            "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
             VALUES (:restocker, :zone, 'PRIMARY', TRUE)"
        );
        $stmt->execute([':restocker' => $restockerOne, ':zone' => $zoneA]);
    });

    $assert(
        'La misma pareja reponedor-zona no admite dos vinculaciones vivas (23505)',
        $duplicateLive !== null
            && $duplicateLive['state'] === '23505'
            && str_contains($duplicateLive['message'], 'uq_restocker_zone_live'),
        $duplicateLive === null ? 'el duplicado vivo se admitió' : 'estado=' . $duplicateLive['state']
    );

    $pgPdo->prepare(
        'UPDATE restocker_zone_assignments SET removed_at = CURRENT_TIMESTAMP
          WHERE restocker_id = :restocker AND zone_id = :zone AND removed_at IS NULL'
    )->execute([':restocker' => $restockerOne, ':zone' => $zoneA]);

    $historicalRows = (int)$pgPdo->query(
        'SELECT COUNT(*) FROM restocker_zone_assignments WHERE restocker_id = ' . $restockerOne . ' AND zone_id = ' . $zoneA
    )->fetchColumn();

    $assert(
        'La baja es lógica: la fila histórica se conserva marcada con removed_at',
        $historicalRows === 1
            && $pgPdo->query(
                'SELECT removed_at FROM restocker_zone_assignments WHERE restocker_id = ' . $restockerOne . ' AND zone_id = ' . $zoneA
            )->fetchColumn() !== null,
        'filas de la pareja: ' . $historicalRows
    );

    $assignRestocker($pgPdo, $restockerOne, $zoneA, 'BACKUP');

    $assert(
        'Tras la baja, el reponedor puede reingresar en la misma zona estrenando vinculación',
        (int)$pgPdo->query(
            'SELECT COUNT(*) FROM restocker_zone_assignments WHERE restocker_id = ' . $restockerOne . ' AND zone_id = ' . $zoneA
        )->fetchColumn() === 2
    );

    $backdatedRemoval = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $restockerTwo, $zoneB): void {
        $stmt = $pgPdo->prepare(
            "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary, assigned_at, removed_at)
             VALUES (:restocker, :zone, 'BACKUP', FALSE, '2026-11-11 10:00:00+00', '2026-11-11 09:00:00+00')"
        );
        $stmt->execute([':restocker' => $restockerTwo, ':zone' => $zoneB]);
    });

    $assert(
        'Una baja anterior al alta se rechaza con 23514 (chk_restocker_zone_removed_order)',
        $backdatedRemoval !== null
            && $backdatedRemoval['state'] === '23514'
            && str_contains($backdatedRemoval['message'], 'chk_restocker_zone_removed_order'),
        $backdatedRemoval === null ? 'la baja invertida se admitió' : 'estado=' . $backdatedRemoval['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Art. III.1: borrado físico prohibido, ventana de purga del arnés aparte
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Art. III.1 sobre la Tabla 41 ---\n";

    $deleteAttempt = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $restockerOne): void {
        $pgPdo->exec('DELETE FROM restocker_zone_assignments WHERE restocker_id = ' . $restockerOne);
    });

    $assert(
        'El DELETE físico se rechaza con SQLSTATE 45000 y la marca constitucional',
        $deleteAttempt !== null
            && $deleteAttempt['state'] === '45000'
            && str_contains($deleteAttempt['message'], PURGE_VIOLATION_MARKER),
        $deleteAttempt === null ? 'el DELETE se admitió' : 'estado=' . $deleteAttempt['state']
    );

    TestDataCleaner::withPurgeWindow($pgPdo, static function () use ($pgPdo, $restockerTwo, $zoneC): void {
        $stmt = $pgPdo->prepare(
            "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary, removed_at)
             VALUES (:restocker, :zone, 'BACKUP', FALSE, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([':restocker' => $restockerTwo, ':zone' => $zoneC]);

        $delete = $pgPdo->prepare(
            'DELETE FROM restocker_zone_assignments WHERE restocker_id = :restocker AND zone_id = :zone'
        );
        $delete->execute([':restocker' => $restockerTwo, ':zone' => $zoneC]);
    });

    $assert(
        'Dentro de la ventana de purga del arnés la fila se puede retirar (la vía existe y funciona)',
        (int)$pgPdo->query(
            'SELECT COUNT(*) FROM restocker_zone_assignments WHERE restocker_id = ' . $restockerTwo . ' AND zone_id = ' . $zoneC
        )->fetchColumn() === 0
    );

    // ─────────────────────────────────────────────────────────────────────
    // 8. Guarda de guardia técnica del rol (D3, Art. II)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Guarda de guardia técnica del reponedor ---\n";

    $guardDeclaration = $pgPdo->query(
        "SELECT pg_get_constraintdef(con.oid)
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND con.conname = 'chk_users_restocker_not_on_call'"
    )->fetchColumn();

    $assert(
        'La restricción chk_users_restocker_not_on_call está declarada sobre users con la regla del contrato',
        is_string($guardDeclaration)
            && str_contains($guardDeclaration, 'RESTOCKER')
            && str_contains($guardDeclaration, 'is_on_call'),
        (string)$guardDeclaration
    );

    // La guarda genérica de guardia técnica ya rechaza el caso; se retira **dentro de una
    // transacción que se revierte** para certificar que la del Módulo 31 muerde por sí sola y no
    // vive de prestado. El esquema del escenario queda intacto tras el rollback.
    $isolatedGuard = '';
    $pgPdo->beginTransaction();
    try {
        $pgPdo->exec('ALTER TABLE users DROP CONSTRAINT chk_users_on_call_active_tech');
        $stmt = $pgPdo->prepare(
            "INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_on_call)
             VALUES ('Reponedor de guardia', 'repostaje.cert.guardia@vendguard.internal',
                     'repostaje.cert.guardia@vendguard.internal', 'x', 'RESTOCKER', 'REC-GUARDIA', TRUE)"
        );
        $stmt->execute();
    } catch (Throwable $exception) {
        $isolatedGuard = $exception->getMessage();
    } finally {
        $pgPdo->rollBack();
    }

    $assert(
        'Aislada de la guarda genérica, chk_users_restocker_not_on_call rechaza la guardia técnica',
        $isolatedGuard !== ''
            && str_contains($isolatedGuard, 'chk_users_restocker_not_on_call'),
        $isolatedGuard === '' ? 'el reponedor quedó de guardia' : mb_substr(str_replace("\n", ' ', $isolatedGuard), 0, 110)
    );

    $restockerOnCall = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->exec(
            "UPDATE users SET is_on_call = TRUE
              WHERE email_key = 'repostaje.cert.uno@vendguard.internal'"
        );
    });

    $assert(
        'Con el esquema completo, marcar de guardia a un reponedor se sigue rechazando con 23514',
        $restockerOnCall !== null && $restockerOnCall['state'] === '23514',
        $restockerOnCall === null ? 'la guardia se admitió' : 'estado=' . $restockerOnCall['state']
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, RESTOCKER_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    $residueProbe = $pgPdo->query("SELECT to_regclass('" . RESTOCKER_TEST_SCHEMA . ".restocker_zone_assignments')")->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El escenario se retira entero: el esquema aislado y su Tabla 41 ya no existen',
    $residueProbe === null || $residueProbe === false,
    'la sonda de residuo encontró ' . json_encode($residueProbe)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. ESQUEMA DEL ROL RESTOCKER Y LA TABLA 41 CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN EL ESQUEMA DEL MÓDULO 31.\n";
echo "======================================================================\n";
exit(1);
