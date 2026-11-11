<?php

declare(strict_types=1);

/**
 * PostgresTieredSlaSchemaTest · T-SLA-03
 *
 * Certifica el suelo del Módulo 30 sobre el motor único PostgreSQL: el tipo
 * `sla_tier`, las columnas de perfil y objetivo, la restricción de la matriz
 * RF-01, los índices y la inmutabilidad del perfil sellado.
 *
 *   1. **Forma declarada.** La migración `026_tiered_sla_contracts.sql` aplica;
 *      el enumerado declara sus tres perfiles; `locations.sla_tier` nace con
 *      `STANDARD`; `incidents.sla_tier_snapshot` nace con `STANDARD` y
 *      `sla_target_minutes` con `1440`.
 *
 *   2. **Matriz RF-01 (Art. II).** El motor rechaza objetivos fuera de
 *      `(240, 360, 480, 720, 1440)` con `23514`, y acepta el recálculo hacia un
 *      valor más estricto.
 *
 *   3. **Inmutabilidad del perfil (Art. III.3, D2, C1).** El disparador
 *      `trg_incidents_protect_sla_tier_snapshot` rechaza cambiar el perfil
 *      sellado con `23514` y el mensaje `IMMUTABLE_SLA_TIER_SNAPSHOT`; el
 *      objetivo y su vencimiento siguen siendo actualizables (C4).
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible a través de `PG_TEST_DSN`, sin valor por
 *   defecto. Sin motor, la suite se declara no ejecutable con código 2 y no se
 *   omite en silencio.
 *
 * LIMPIEZA: el escenario vive en un esquema aislado que se borra con `CASCADE`
 * en el `finally`, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresTieredSlaSchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado propio: no comparte escenario con las demás suites del canal. */
const TIERED_SLA_SCHEMA = 't_sla30_schema';

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
echo " VENDGUARD · T-SLA-03 · Perfiles de SLA y sellado contractual\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

$crash = null;
$residueCheck = null;

try {
    echo "\n--- Escenario (DDL base + migraciones del motor nuevo) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, TIERED_SLA_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    $assert(
        'El esquema del repositorio se aplica entero, incluida la migración 026',
        $schemaReport['statements'] > 100
        && in_array('026_tiered_sla_contracts.sql', $schemaReport['migrations'], true),
        sprintf('%d sentencias · migraciones: %s', $schemaReport['statements'], implode(', ', $schemaReport['migrations']))
    );

    echo "\n--- Tipo enumerado y columnas del contrato ---\n";

    $labels = $pgPdo->query(
        "SELECT enumlabel FROM pg_enum WHERE enumtypid = 'sla_tier'::regtype ORDER BY enumsortorder"
    )->fetchAll(PDO::FETCH_COLUMN);

    $assert(
        'El tipo sla_tier declara los tres perfiles oficiales',
        $labels === ['STANDARD', 'PRIORITY_CORPORATE', 'CRITICAL_INFRASTRUCTURE_24_7'],
        'etiquetas: ' . json_encode($labels, JSON_UNESCAPED_UNICODE)
    );

    $locationColumns = PostgresFixtureBuilder::columns($pgPdo, 'locations');
    $incidentColumns = PostgresFixtureBuilder::columns($pgPdo, 'incidents');

    $assert(
        'locations declara sus 23 columnas, con sla_tier NOT NULL y defecto STANDARD',
        count($locationColumns) === 23
        && isset($locationColumns['sla_tier'])
        && $locationColumns['sla_tier']['udt_name'] === 'sla_tier'
        && $locationColumns['sla_tier']['nullable'] === false
        && str_contains((string)$locationColumns['sla_tier']['default'], "'STANDARD'"),
        'columnas: ' . count($locationColumns) . ' · defecto: ' . (string)($locationColumns['sla_tier']['default'] ?? 'ausente')
    );

    $assert(
        'incidents declara sus 52 columnas y las tres piezas del sellado',
        count($incidentColumns) === 52
        && array_key_exists('sla_tier_snapshot', $incidentColumns)
        && array_key_exists('sla_target_minutes', $incidentColumns)
        && array_key_exists('sla_target_at', $incidentColumns),
        'columnas: ' . count($incidentColumns)
    );

    $assert(
        'sla_tier_snapshot nace NOT NULL con defecto STANDARD',
        $incidentColumns['sla_tier_snapshot']['udt_name'] === 'sla_tier'
        && $incidentColumns['sla_tier_snapshot']['nullable'] === false
        && str_contains((string)$incidentColumns['sla_tier_snapshot']['default'], "'STANDARD'")
    );

    $assert(
        'sla_target_minutes nace NOT NULL con defecto 1440',
        $incidentColumns['sla_target_minutes']['udt_name'] === 'int4'
        && $incidentColumns['sla_target_minutes']['nullable'] === false
        && (string)$incidentColumns['sla_target_minutes']['default'] === '1440'
    );

    $assert(
        'sla_target_at es el vencimiento TIMESTAMPTZ nullable del expediente',
        $incidentColumns['sla_target_at']['udt_name'] === 'timestamptz'
        && $incidentColumns['sla_target_at']['nullable'] === true
    );

    echo "\n--- Índices, restricción y disparador ---\n";

    $indexStmt = $pgPdo->prepare(
        'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = :table'
    );

    $indexStmt->execute([':table' => 'locations']);
    $locationIndexes = array_column($indexStmt->fetchAll(), 'indexname');
    $assert(
        'Existe el índice parcial idx_locations_sla_tier',
        in_array('idx_locations_sla_tier', $locationIndexes, true)
    );

    $indexStmt->execute([':table' => 'incidents']);
    $incidentIndexes = array_column($indexStmt->fetchAll(), 'indexname');
    $assert(
        'Existe el índice de consulta idx_incidents_sla_target_lookup',
        in_array('idx_incidents_sla_target_lookup', $incidentIndexes, true)
    );

    $constraintDef = $pgPdo->query(
        "SELECT pg_get_constraintdef(oid)
           FROM pg_constraint
          WHERE conrelid = 'incidents'::regclass
            AND conname = 'chk_incidents_sla_target_minutes'"
    )->fetchColumn();

    $assert(
        'La restricción chk_incidents_sla_target_minutes acota los cinco objetivos RF-01',
        is_string($constraintDef)
        && str_contains($constraintDef, 'sla_target_minutes')
        && preg_match('/240.*360.*480.*720.*1440/', $constraintDef) === 1,
        is_string($constraintDef) ? $constraintDef : 'restricción ausente'
    );

    $triggerName = $pgPdo->query(
        "SELECT tgname
           FROM pg_trigger
          WHERE tgrelid = 'incidents'::regclass
            AND tgname = 'trg_incidents_protect_sla_tier_snapshot'
            AND NOT tgisinternal"
    )->fetchColumn();

    $functionName = $pgPdo->query(
        "SELECT proname FROM pg_proc WHERE proname = 'trg_fn_incidents_protect_sla_tier_snapshot'"
    )->fetchColumn();

    $assert(
        'El disparador y su función de inmutabilidad están vinculados a incidents',
        $triggerName === 'trg_incidents_protect_sla_tier_snapshot'
        && $functionName === 'trg_fn_incidents_protect_sla_tier_snapshot'
    );

    echo "\n--- Alta mínima: los defectos del contrato se heredan ---\n";

    $parents = [];
    $parents['users'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'users', $parents, '01');
    $parents['locations'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'locations', $parents, '01');
    $parents['machines'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'machines', $parents, '01');
    $incident = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'incidents', $parents, '01');

    $assert(
        'Una sede nueva hereda STANDARD y una avería nueva hereda STANDARD/1440',
        $parents['locations']['sla_tier'] === 'STANDARD'
        && $incident['sla_tier_snapshot'] === 'STANDARD'
        && (int)$incident['sla_target_minutes'] === 1440,
        json_encode([
            'location' => $parents['locations']['sla_tier'],
            'snapshot' => $incident['sla_tier_snapshot'],
            'target' => $incident['sla_target_minutes'],
        ], JSON_UNESCAPED_UNICODE) ?: ''
    );

    echo "\n--- Matriz RF-01 y recálculo del objetivo (C4) ---\n";

    $incidentId = (int)$incident['id'];

    $allowed = $pgPdo->exec(
        "UPDATE incidents
            SET sla_target_minutes = 240,
                sla_target_at = created_at + interval '240 minutes'
          WHERE id = {$incidentId}"
    );

    $coherent = $pgPdo->query(
        "SELECT COUNT(*) FROM incidents
          WHERE id = {$incidentId}
            AND sla_target_minutes = 240
            AND sla_target_at = created_at + interval '240 minutes'"
    )->fetchColumn();

    $assert(
        'El objetivo y su vencimiento se recalculan dentro de la matriz',
        $allowed === 1 && (string)$coherent === '1',
        'filas: ' . (string)$allowed . ' · coherente: ' . (string)$coherent
    );

    $invalidRejections = [];
    foreach ([0, 300, 2000] as $invalidTarget) {
        $invalidRejections[$invalidTarget] = PostgresFixtureBuilder::expectRejection(
            static fn (): mixed => $pgPdo->exec(
                "UPDATE incidents SET sla_target_minutes = {$invalidTarget} WHERE id = {$incidentId}"
            )
        );
    }

    $allInvalidRejected = true;
    foreach ($invalidRejections as $rejection) {
        $allInvalidRejected = $allInvalidRejected
            && $rejection !== null
            && $rejection['state'] === '23514'
            && str_contains($rejection['message'], 'chk_incidents_sla_target_minutes');
    }

    $assert(
        'El motor rechaza objetivos fuera de (240, 360, 480, 720, 1440)',
        $allInvalidRejected,
        json_encode($invalidRejections, JSON_UNESCAPED_UNICODE) ?: ''
    );

    echo "\n--- Inmutabilidad del perfil sellado (Art. III.3, D2, C1) ---\n";

    $blocked = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $pgPdo->exec(
            "UPDATE incidents
                SET sla_tier_snapshot = 'CRITICAL_INFRASTRUCTURE_24_7'
              WHERE id = {$incidentId}"
        )
    );

    $assert(
        'El disparador rechaza cambiar el perfil sellado con IMMUTABLE_SLA_TIER_SNAPSHOT',
        $blocked !== null
        && $blocked['state'] === '23514'
        && str_contains($blocked['message'], 'IMMUTABLE_SLA_TIER_SNAPSHOT'),
        $blocked === null ? 'el perfil se actualizó' : $blocked['message']
    );

    $snapshotAfter = $pgPdo->query(
        "SELECT sla_tier_snapshot FROM incidents WHERE id = {$incidentId}"
    )->fetchColumn();

    $assert(
        'El perfil sellado permanece intacto tras el intento rechazado',
        $snapshotAfter === 'STANDARD',
        'perfil leído: ' . (string)$snapshotAfter
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, TIERED_SLA_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    $residueCheck = $pgPdo->query(
        "SELECT to_regclass('" . TIERED_SLA_SCHEMA . ".incidents')"
    )->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El esquema aislado se retira entero y no deja tablas en el motor',
    $residueCheck === null,
    'residuo: ' . json_encode($residueCheck)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% EN VERDE. T-SLA-03 (perfiles de SLA) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLOS DETECTADOS EN EL SELLADO DE SLA.\n";
echo "======================================================================\n";
exit(1);
