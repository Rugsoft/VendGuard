<?php

declare(strict_types=1);

/**
 * PostgresOfflineSyncSchemaTest · T-OFFLINE-02
 *
 * Certifica sobre el motor PostgreSQL el blindaje de la Tabla 34
 * `offline_sync_mutations`, el libro de idempotencia de la sincronización
 * diferida del Módulo 24. No certifica todavía el servicio ni los endpoints (eso
 * es T-OFFLINE-06 a T-OFFLINE-09): aquí se mide lo que sólo la base de datos
 * puede garantizar aunque un caso de uso futuro olvidara validarlo.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Unicidad por técnico y operación (RF-04.2).** La pareja
 *      `(technician_id, client_mutation_id)` es la clave de idempotencia: el
 *      mismo reenvío no puede aplicarse dos veces. Se prueba también el límite
 *      contrario —el mismo identificador en otro técnico sí entra—, porque una
 *      unicidad global bloquearía a un compañero cuyo generador local coincidió.
 *
 *   2. **Formato del identificador del cliente.** Se rechazan por debajo y por
 *      encima de la ventana 8–64 y un valor con caracteres fuera del alfabeto
 *      permitido. El nombre de la restricción se lee en el mensaje del motor, no
 *      se confía en que «algo» falló.
 *
 *   3. **Catálogos cerrados.** `stop_type`, `operation_type` y `outcome_status`
 *      rechazan valores fuera de catálogo; las tres ramas de resultado
 *      (`APPLIED`, `CONFLICT_DUMPED` y `DEACTIVATED_ACCOUNT_DUMP`) se aceptan,
 *      de modo que la guarda no prohíbe lo que la especificación autoriza.
 *
 *   4. **Prohibición de borrado físico (Art. III.1).** `DELETE FROM` se rechaza
 *      con `SQLSTATE '45000'` y la marca `ART_III_1_VIOLATION`, con filas dentro:
 *      un disparador `FOR EACH ROW` no ve una tabla vacía. Después del rechazo la
 *      fila sigue ahí, que es la mitad útil de la garantía.
 *
 * CATEGORÍA: `new_engine`
 *   Exige el PostgreSQL de certificación. Sin `PG_TEST_DSN` no se omite en
 *   silencio: lo declara y sale con código 2. El escenario vive en su propio
 *   esquema y se borra con `CASCADE` al terminar, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresOfflineSyncSchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

/** Marca de categoría: `run_all.php` la lee para no confundirla con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Marca que el disparador constitucional escribe en el mensaje de la excepción. */
const PURGE_VIOLATION_MARKER = 'ART_III_1_VIOLATION';

/** Identidad de la fixture: nombres reservados para que una limpieza fallida sea visible. */
const FIXTURE_TECH_EMAIL = 'offline.tech.m24@vendguard.test';
const FIXTURE_SECOND_TECH_EMAIL = 'offline.tech2.m24@vendguard.test';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;
$crash = null;
$pgPdo = null;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-OFFLINE-02 · Esquema del libro de sincronización (Módulo 24)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

try {
    PostgresFixtureBuilder::createIsolationSchema($pgPdo);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    $assert(
        'El esquema del repositorio compila y la migración 020 se aplica sobre la base aislada',
        in_array('020_offline_pwa_sync.sql', $schemaReport['migrations'], true),
        'migraciones: ' . implode(', ', $schemaReport['migrations'])
    );

    $tables = PostgresFixtureBuilder::tables($pgPdo);
    $assert(
        'La Tabla 34 del contrato existe en el esquema certificado',
        in_array('offline_sync_mutations', $tables, true),
        'tablas: ' . count($tables)
    );

    // ─────────────────────────────────────────────────────────────────────
    // Fixture mínima: dos técnicos reales
    // ─────────────────────────────────────────────────────────────────────
    $insertUser = $pgPdo->prepare(
        "INSERT INTO users (name, email, email_key, password_hash, role, is_active)
         VALUES (:name, :email, :email_key, 'fixture-hash-m24', 'TECHNICIAN', TRUE)
         RETURNING *"
    );

    $insertUser->execute([
        ':name' => 'Técnico Fixture M24',
        ':email' => FIXTURE_TECH_EMAIL,
        ':email_key' => FIXTURE_TECH_EMAIL,
    ]);
    $technician = $insertUser->fetch();

    $insertUser->execute([
        ':name' => 'Técnico Fixture M24 Suplente',
        ':email' => FIXTURE_SECOND_TECH_EMAIL,
        ':email_key' => FIXTURE_SECOND_TECH_EMAIL,
    ]);
    $secondTechnician = $insertUser->fetch();

    $technicianId = (int)$technician['id'];
    $secondTechnicianId = (int)$secondTechnician['id'];

    /**
     * Alta de una mutación con los valores del caso, todos sustituibles.
     *
     * Los booleanos y el JSONB viajan como texto y se castean en la sentencia: es
     * la forma que el adaptador del proyecto usa para no depender de cómo PDO
     * traduzca `false` en cada driver.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    $insertMutation = static function (PDO $pdo, array $overrides): array {
        $values = array_merge([
            'technician_id' => 0,
            'client_mutation_id' => 'fixture.m24.0001',
            'stop_type' => 'INCIDENT',
            'stop_id' => 101,
            'operation_type' => 'START_INCIDENT',
            'outcome_status' => 'APPLIED',
            'client_occurred_at' => '2026-10-27 09:00:00+00',
            'chain_started_at' => null,
            'effective_occurred_at' => '2026-10-27 09:00:00+00',
            'synced_at' => '2026-10-27 09:05:00+00',
            'clock_skew_adjusted' => false,
            'sanitary_seal_confirmed' => false,
            'response_http_status' => 200,
            'response_payload' => '{"status":"APPLIED"}',
        ], $overrides);

        $stmt = $pdo->prepare(
            "INSERT INTO offline_sync_mutations (
                technician_id, client_mutation_id, stop_type, stop_id, operation_type,
                outcome_status, client_occurred_at, chain_started_at, effective_occurred_at,
                synced_at, clock_skew_adjusted, sanitary_seal_confirmed,
                response_http_status, response_payload
             ) VALUES (
                :technician_id, :client_mutation_id, :stop_type, :stop_id, :operation_type,
                :outcome_status, :client_occurred_at, :chain_started_at, :effective_occurred_at,
                :synced_at, CAST(:clock_skew_adjusted AS BOOLEAN),
                CAST(:sanitary_seal_confirmed AS BOOLEAN),
                :response_http_status, CAST(:response_payload AS JSONB)
             ) RETURNING *"
        );
        $stmt->execute([
            ':technician_id' => $values['technician_id'],
            ':client_mutation_id' => $values['client_mutation_id'],
            ':stop_type' => $values['stop_type'],
            ':stop_id' => $values['stop_id'],
            ':operation_type' => $values['operation_type'],
            ':outcome_status' => $values['outcome_status'],
            ':client_occurred_at' => $values['client_occurred_at'],
            ':chain_started_at' => $values['chain_started_at'],
            ':effective_occurred_at' => $values['effective_occurred_at'],
            ':synced_at' => $values['synced_at'],
            ':clock_skew_adjusted' => $values['clock_skew_adjusted'] ? 'true' : 'false',
            ':sanitary_seal_confirmed' => $values['sanitary_seal_confirmed'] ? 'true' : 'false',
            ':response_http_status' => $values['response_http_status'],
            ':response_payload' => $values['response_payload'],
        ]);

        $created = $stmt->fetch();

        return is_array($created) ? $created : [];
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. Registro válido, valores por defecto y doble sello temporal
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Alta válida, defectos del motor y tres marcas temporales ---\n";

    $minimal = $pgPdo->prepare(
        "INSERT INTO offline_sync_mutations (
            technician_id, client_mutation_id, stop_type, stop_id, operation_type,
            client_occurred_at, effective_occurred_at
         ) VALUES (
            :technician_id, 'fixture.m24.minimal', 'PREVENTIVE_ORDER', 202,
            'COMPLETE_PREVENTIVE', '2026-10-27 10:00:00+00', '2026-10-27 10:02:00+00'
         ) RETURNING *"
    );
    $minimal->execute([':technician_id' => $technicianId]);
    $minimalRow = $minimal->fetch();

    $assert(
        'Una operación mínima entra con resultado APPLIED, 200 y cadena nula por defecto',
        is_array($minimalRow)
            && $minimalRow['outcome_status'] === 'APPLIED'
            && (int)$minimalRow['response_http_status'] === 200
            && $minimalRow['chain_started_at'] === null
            && $minimalRow['response_payload'] === '{}'
    );

    $assert(
        'El servidor sella synced_at y created_at aunque el cliente no los envíe',
        is_array($minimalRow) && $minimalRow['synced_at'] !== null && $minimalRow['created_at'] !== null
    );

    $assert(
        'Las dos marcas declaradas se conservan separadas de la de recepción',
        is_array($minimalRow)
            && $minimalRow['client_occurred_at'] !== $minimalRow['effective_occurred_at']
            && $minimalRow['client_occurred_at'] !== $minimalRow['synced_at']
    );

    $skewed = $insertMutation($pgPdo, [
        'technician_id' => $technicianId,
        'client_mutation_id' => 'fixture.m24.skewed',
        'operation_type' => 'RESOLVE_INCIDENT',
        'outcome_status' => 'CONFLICT_DUMPED',
        'client_occurred_at' => '2026-10-26 09:00:00+00',
        'chain_started_at' => '2026-10-26 08:35:00+00',
        'effective_occurred_at' => '2026-10-27 11:05:00+00',
        'synced_at' => '2026-10-27 11:05:00+00',
        'clock_skew_adjusted' => true,
        'response_http_status' => 409,
        'response_payload' => '{"error":"OFFLINE_MUTATION_CONFLICT"}',
    ]);

    $assert(
        'El reloj desajustado queda registrado con su testigo y el conflicto con su estado HTTP',
        ($skewed['clock_skew_adjusted'] ?? null) === true
            && (int)($skewed['response_http_status'] ?? 0) === 409
            && ($skewed['chain_started_at'] ?? null) !== null,
        'clock_skew_adjusted=' . var_export($skewed['clock_skew_adjusted'] ?? null, true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Unicidad de la pareja técnico + operación (RF-04.2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Idempotencia por técnico y operación (RF-04.2) ---\n";

    $duplicate = PostgresFixtureBuilder::expectRejection(static function () use ($insertMutation, $pgPdo, $technicianId): void {
        $insertMutation($pgPdo, [
            'technician_id' => $technicianId,
            'client_mutation_id' => 'fixture.m24.minimal',
            'stop_type' => 'PREVENTIVE_ORDER',
            'stop_id' => 202,
            'operation_type' => 'COMPLETE_PREVENTIVE',
        ]);
    });
    $assert(
        'El reenvío de la misma operación del mismo técnico se rechaza con 23505 y nombra la clave',
        $duplicate !== null
            && $duplicate['state'] === '23505'
            && str_contains($duplicate['message'], 'uq_offline_sync_tech_mutation'),
        $duplicate === null ? 'el duplicado se aceptó' : 'estado=' . $duplicate['state']
    );

    $otherTechnician = $insertMutation($pgPdo, [
        'technician_id' => $secondTechnicianId,
        'client_mutation_id' => 'fixture.m24.minimal',
        'stop_type' => 'PREVENTIVE_ORDER',
        'stop_id' => 202,
        'operation_type' => 'COMPLETE_PREVENTIVE',
    ]);
    $assert(
        'El mismo identificador en otro técnico sí se acepta: la unicidad es por técnico',
        ($otherTechnician['client_mutation_id'] ?? null) === 'fixture.m24.minimal'
            && (int)($otherTechnician['technician_id'] ?? 0) === $secondTechnicianId
    );

    $secondDuplicate = PostgresFixtureBuilder::expectRejection(static function () use ($insertMutation, $pgPdo, $secondTechnicianId): void {
        $insertMutation($pgPdo, [
            'technician_id' => $secondTechnicianId,
            'client_mutation_id' => 'fixture.m24.minimal',
            'stop_type' => 'PREVENTIVE_ORDER',
            'stop_id' => 202,
            'operation_type' => 'COMPLETE_PREVENTIVE',
        ]);
    });
    $assert(
        'El suplente tampoco puede repetir su propia operación (23505)',
        $secondDuplicate !== null && $secondDuplicate['state'] === '23505',
        $secondDuplicate === null ? 'el duplicado se aceptó' : 'estado=' . $secondDuplicate['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Formato del identificador del cliente
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Formato del identificador generado por el dispositivo ---\n";

    $tooShort = PostgresFixtureBuilder::expectRejection(static function () use ($insertMutation, $pgPdo, $technicianId): void {
        $insertMutation($pgPdo, ['technician_id' => $technicianId, 'client_mutation_id' => 'm24.001']);
    });
    $assert(
        'Un identificador de 7 caracteres se rechaza con 23514 y nombra su restricción',
        $tooShort !== null
            && $tooShort['state'] === '23514'
            && str_contains($tooShort['message'], 'chk_offline_sync_mutation_id_format'),
        $tooShort === null ? 'el identificador corto se aceptó' : 'estado=' . $tooShort['state']
    );

    $tooLong = PostgresFixtureBuilder::expectRejection(static function () use ($insertMutation, $pgPdo, $technicianId): void {
        $insertMutation($pgPdo, [
            'technician_id' => $technicianId,
            'client_mutation_id' => str_repeat('a', 65),
        ]);
    });
    $assert(
        'Un identificador de 65 caracteres se rechaza por la anchura declarada (22001)',
        $tooLong !== null && $tooLong['state'] === '22001',
        $tooLong === null ? 'el identificador largo se aceptó' : 'estado=' . $tooLong['state']
    );

    $badCharacter = PostgresFixtureBuilder::expectRejection(static function () use ($insertMutation, $pgPdo, $technicianId): void {
        $insertMutation($pgPdo, [
            'technician_id' => $technicianId,
            'client_mutation_id' => 'fixture m24#0001',
        ]);
    });
    $assert(
        'Un identificador con caracteres fuera del alfabeto permitido se rechaza con 23514',
        $badCharacter !== null && $badCharacter['state'] === '23514',
        $badCharacter === null ? 'el identificador inválido se aceptó' : 'estado=' . $badCharacter['state']
    );

    $boundary = $insertMutation($pgPdo, [
        'technician_id' => $technicianId,
        'client_mutation_id' => 'm24.0001',
    ]);
    $assert(
        'La frontera inferior de 8 caracteres se acepta',
        ($boundary['client_mutation_id'] ?? null) === 'm24.0001'
    );

    $upperBoundary = $insertMutation($pgPdo, [
        'technician_id' => $technicianId,
        'client_mutation_id' => str_repeat('b', 64),
    ]);
    $assert(
        'La frontera superior de 64 caracteres se acepta sin recortar',
        ($upperBoundary['client_mutation_id'] ?? null) === str_repeat('b', 64)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Catálogos cerrados: parada, operación y resultado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Catálogos cerrados de parada, operación y resultado ---\n";

    $badStop = PostgresFixtureBuilder::expectRejection(static function () use ($insertMutation, $pgPdo, $technicianId): void {
        $insertMutation($pgPdo, [
            'technician_id' => $technicianId,
            'client_mutation_id' => 'fixture.m24.badstop',
            'stop_type' => 'MACHINE',
        ]);
    });
    $assert(
        'Un tipo de parada fuera de catálogo se rechaza y nombra chk_offline_sync_stop_type',
        $badStop !== null
            && $badStop['state'] === '23514'
            && str_contains($badStop['message'], 'chk_offline_sync_stop_type'),
        $badStop === null ? 'el tipo inválido se aceptó' : 'estado=' . $badStop['state']
    );

    $badOperation = PostgresFixtureBuilder::expectRejection(static function () use ($insertMutation, $pgPdo, $technicianId): void {
        $insertMutation($pgPdo, [
            'technician_id' => $technicianId,
            'client_mutation_id' => 'fixture.m24.badop',
            'operation_type' => 'DELETE_EVERYTHING',
        ]);
    });
    $assert(
        'Una operación no autorizada por D1 se rechaza y nombra chk_offline_sync_operation_type',
        $badOperation !== null
            && $badOperation['state'] === '23514'
            && str_contains($badOperation['message'], 'chk_offline_sync_operation_type'),
        $badOperation === null ? 'la operación inválida se aceptó' : 'estado=' . $badOperation['state']
    );

    $badOutcome = PostgresFixtureBuilder::expectRejection(static function () use ($insertMutation, $pgPdo, $technicianId): void {
        $insertMutation($pgPdo, [
            'technician_id' => $technicianId,
            'client_mutation_id' => 'fixture.m24.badoutcome',
            'outcome_status' => 'SILENT_DROP',
        ]);
    });
    $assert(
        'Un resultado fuera de catálogo se rechaza y nombra chk_offline_sync_outcome_status',
        $badOutcome !== null
            && $badOutcome['state'] === '23514'
            && str_contains($badOutcome['message'], 'chk_offline_sync_outcome_status'),
        $badOutcome === null ? 'el resultado inválido se aceptó' : 'estado=' . $badOutcome['state']
    );

    $deactivated = $insertMutation($pgPdo, [
        'technician_id' => $technicianId,
        'client_mutation_id' => 'fixture.m24.deactivated',
        'outcome_status' => 'DEACTIVATED_ACCOUNT_DUMP',
        'response_http_status' => 403,
        'response_payload' => '{"error":"ACCOUNT_DEACTIVATED"}',
    ]);
    $assert(
        'La tercera rama del catálogo, el volcado por baja de cuenta, se acepta (QA 5)',
        ($deactivated['outcome_status'] ?? null) === 'DEACTIVATED_ACCOUNT_DUMP'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Borrado físico prohibido (Art. III.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Prohibición de borrado físico (Art. III.1) ---\n";

    $rowsBefore = (int)$pgPdo->query('SELECT count(*) FROM offline_sync_mutations')->fetchColumn();

    $hardDelete = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->exec('DELETE FROM offline_sync_mutations');
    });
    $assert(
        'DELETE FROM offline_sync_mutations se rechaza con 45000 y la marca constitucional',
        $hardDelete !== null
            && $hardDelete['state'] === '45000'
            && str_contains($hardDelete['message'], PURGE_VIOLATION_MARKER),
        $hardDelete === null ? 'el borrado pasó' : 'estado=' . $hardDelete['state']
    );

    $rowsAfter = (int)$pgPdo->query('SELECT count(*) FROM offline_sync_mutations')->fetchColumn();
    $assert(
        'Tras el rechazo las filas siguen ahí: el disparador protege el rastro, no solo la sentencia',
        $rowsAfter === $rowsBefore && $rowsAfter > 0,
        "antes={$rowsBefore} · después={$rowsAfter}"
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Piezas declaradas por la migración
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Índices y disparador declarados por la migración ---\n";

    $indexStmt = $pgPdo->prepare(
        "SELECT indexname FROM pg_indexes
          WHERE schemaname = current_schema() AND tablename = 'offline_sync_mutations'
          ORDER BY indexname"
    );
    $indexStmt->execute();
    $indexNames = array_map(static fn (array $row): string => (string)$row['indexname'], $indexStmt->fetchAll());

    $assert(
        'Los dos índices de lectura del libro existen',
        in_array('idx_offline_sync_mutations_stop', $indexNames, true)
            && in_array('idx_offline_sync_mutations_tech_synced', $indexNames, true),
        'índices: ' . implode(', ', $indexNames)
    );

    $triggerStmt = $pgPdo->prepare(
        "SELECT count(*) FROM information_schema.triggers
          WHERE trigger_schema = current_schema()
            AND event_object_table = 'offline_sync_mutations'
            AND event_manipulation = 'DELETE' AND action_timing = 'BEFORE'"
    );
    $triggerStmt->execute();
    $assert(
        'El disparador BEFORE DELETE está declarado sobre la tabla',
        (int)$triggerStmt->fetchColumn() === 1
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }
}

if ($crash !== null) {
    echo "\n[ERROR FATAL] " . $crash->getMessage() . "\n";
    $failures++;
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0 && $crash === null) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-OFFLINE-02 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIÓN(ES).\n";
echo "======================================================================\n";
exit(1);
