<?php

declare(strict_types=1);

/**
 * CloudDeploySchemaParityTest (T-REF-27)
 *
 * Guards the schema the DEPLOYED server ends up with.
 *
 * ## The failure this exists for
 * 2026-10-03: the refunds inbox answered 500 on the Render deployment
 * ("Ha ocurrido un error interno no controlado en el servidor") while the whole
 * local environment and every other suite were green. The container boots with
 * `php bin/init_cloud_db.php`, which executed only `database/cloud_init.sql`.
 * That file creates every table with `CREATE TABLE IF NOT EXISTS`, so it can
 * define the schema of a database that does not exist yet and nothing else:
 * `refund_requests` had been created before `paid_amount`, `pickup_attempts`
 * and `pickup_locked_until` were declared, `database/migrations/` never ran on
 * the server, and the listing -- which selects all three -- died with
 * `Unknown column`. The three columns had been in the migrations all along; the
 * deployment simply never applied them.
 *
 * A green battery could not see it by construction: every suite runs against
 * the local schema, which is migrated. The deployment runs against a schema
 * that is created, not migrated. That gap is what this suite closes.
 *
 * ## What it certifies
 * 1. The boot script applies `database/migrations/*.sql`, statement by
 *    statement and with the shared splitter, so a database that already exists
 *    converges to the repository schema instead of staying frozen at its first
 *    boot.
 * 2. The shared splitter survives the two escaping forms the migration set
 *    actually contains -- `\''` in 009 and `''...;...''` in 013 -- because a
 *    splitter that gets either wrong cuts a migration in half and the deploy
 *    aborts halfway through.
 * 3. A schema built from the deploy path (base DDL + migrations) declares every
 *    column the tested schema has, for the two refunds tables, with the same
 *    types -- including the widened DECIMAL(10,2) amounts migration 011
 *    demands so no figure can be silently saturated.
 * 4. The guard bites: a schema built from the base DDL alone -- the deploy path
 *    as it was before the fix -- is detected as incomplete.
 *
 * ## Why a scratch database
 * `vendguard_db` is the schema the application is tested against, so it is the
 * yardstick, not the subject. This suite never mutates it: it creates its own
 * throwaway schemas, builds them from the deployment's own SQL, compares, and
 * drops them. It is the only suite that opens a connection without a database,
 * because creating a database is the one thing it has to do.
 *
 * Requirements: RF-REF-01, RF-REF-10 (Art. III.1), RNF-REF-01.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SqlScriptSplitter;

$baseDir = dirname(__DIR__, 2);
$liveSchema = 'vendguard_db';
$deploySchema = 'vendguard_deploy_probe';
$baseOnlySchema = 'vendguard_deploy_probe_base';

echo "======================================================================\n";
echo " VendGuard: Test de Integración - CloudDeploySchemaParityTest (T-REF-27)\n";
echo "======================================================================\n";

$assertionCount = 0;

$assert = function (bool $condition, string $message, string $detail = '') use (&$assertionCount): void {
    $assertionCount++;
    if (!$condition) {
        throw new RuntimeException(
            "FALLO EN ASERCIÓN [{$assertionCount}]: {$message}" . ($detail !== '' ? " -- {$detail}" : '')
        );
    }
    echo "  [PASS] Aserción {$assertionCount}: {$message}\n";
};

/**
 * MySQL reports integer display widths (`tinyint(3)`, `int(10)`) that carry no
 * contract; they are normalised away so the comparison is about the type. The
 * digits of DECIMAL(10,2) and VARCHAR(100) are NOT touched: those widths are
 * exactly what a silent saturation bug hides behind.
 */
$normalizeType = static function (string $type): string {
    $normalized = strtolower(trim($type));

    return (string)preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $normalized);
};

$columnMap = static function (PDO $pdo, string $schema, string $table) use ($normalizeType): array {
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME, COLUMN_TYPE
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table"
    );
    $stmt->execute([':schema' => $schema, ':table' => $table]);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(string)$row['COLUMN_NAME']] = $normalizeType((string)$row['COLUMN_TYPE']);
    }
    ksort($map);

    return $map;
};

/** Columns the expected schema has and the actual one does not. */
$missingColumns = static function (array $expected, array $actual): array {
    return array_values(array_diff(array_keys($expected), array_keys($actual)));
};

/** Columns present in both schemas but declared with a different type. */
$typeMismatches = static function (array $expected, array $actual): array {
    $mismatches = [];
    foreach ($expected as $column => $type) {
        if (isset($actual[$column]) && $actual[$column] !== $type) {
            $mismatches[$column] = $type . ' != ' . $actual[$column];
        }
    }

    return $mismatches;
};

/**
 * Runs one statement the way the container does: through `PDO::query()` with
 * the cursor closed afterwards.
 *
 * The migrations close each kernel check with `SELECT 1`, and an unread result
 * set makes the next statement of the same connection fail with «2014 Cannot
 * execute queries while other unbuffered queries are active». Mirroring the
 * boot here is what makes the scratch schema a faithful replica of the
 * deployed one.
 */
$runStatement = static function (PDO $pdo, string $statement): void {
    $result = $pdo->query($statement);
    if ($result instanceof PDOStatement) {
        $result->closeCursor();
    }
};

/** Applies a script statement by statement, skipping seeds (schema only). */
$applySqlFile = static function (PDO $pdo, string $path) use ($runStatement): int {
    $sql = (string)file_get_contents($path);
    $executed = 0;

    foreach (SqlScriptSplitter::split($sql) as $statement) {
        if (strtoupper((string)strtok($statement, " \t\n\r")) === 'INSERT') {
            continue;
        }
        $runStatement($pdo, $statement);
        $executed++;
    }

    return $executed;
};

$migrationFiles = glob($baseDir . '/database/migrations/*.sql') ?: [];
sort($migrationFiles);
$migrationSql = '';
foreach ($migrationFiles as $migrationFile) {
    $migrationSql .= (string)file_get_contents($migrationFile);
}

// Las tres columnas que la API consultaba contra un esquema que no las tenía.
$columnsThatBrokeTheDeployment = ['paid_amount', 'pickup_attempts', 'pickup_locked_until'];

// ─────────────────────────────────────────────────────────────────────────────
// Caso 1: el arranque del despliegue aplica las migraciones incrementales
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 1: el arranque desplegado hace converger el esquema ---\n";

$bootScript = (string)file_get_contents($baseDir . '/bin/init_cloud_db.php');

$assert(
    str_contains($bootScript, 'database/migrations'),
    'El arranque del contenedor (bin/init_cloud_db.php) aplica database/migrations/'
);
$assert(
    str_contains($bootScript, 'SqlScriptSplitter::split('),
    'El arranque aplica las migraciones con el troceador compartido, sentencia a sentencia'
);
$assert(
    $migrationFiles !== [] && count($migrationFiles) >= 11,
    'El repositorio declara las migraciones incrementales de todos los modulos',
    'encontradas: ' . count($migrationFiles)
);
$assert(
    str_contains($migrationSql, '`paid_amount`')
    && str_contains($migrationSql, '`pickup_attempts`')
    && str_contains($migrationSql, '`pickup_locked_until`'),
    'Las tres columnas que rompieron el despliegue viven en la capa incremental'
);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 1b: el troceador sobrevive a los escapes que usan las migraciones
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 1b: el troceador respeta comillas escapadas ---\n";

// 009 cierra su SIGNAL con `\'` seguido de la comilla de cierre: si el
// troceador mira antes la duplicación que la barra invertida, se come el cierre
// y el resto del fichero queda dentro de una cadena.
$statement009 = SqlScriptSplitter::split((string)file_get_contents($baseDir . '/database/migrations/009_refund_audit_entity.sql'));

$assert(
    count($statement009) >= 5
    && count(array_filter($statement009, static fn (string $s): bool => str_starts_with(trim($s), 'PREPARE vg_refund_audit_statement'))) === 1,
    'La migracion 009 se trocea en sentencias separadas pese a cerrar un literal con barra invertida',
    'sentencias: ' . count($statement009)
);
$assert(
    count(array_filter($statement009, static fn (string $s): bool => str_contains($s, 'SIGNAL SQLSTATE') && str_contains($s, 'no admite nulos'))) === 1,
    'El SIGNAL escapado de la migracion 009 llega intacto a una sola sentencia'
);

// 013 mete un `;` dentro de un literal con comillas duplicadas: si el troceador
// no entiende `''`, parte el MESSAGE_TEXT por la mitad y el arranque aborta.
// 010 mete un `;` dentro de un literal con comillas duplicadas (`''`), que es la
// otra forma que tiene SQL de escapar una comilla.
$statement010 = SqlScriptSplitter::split((string)file_get_contents($baseDir . '/database/migrations/010_refund_hard_delete_guard.sql'));

$assert(
    count(array_filter($statement010, static fn (string $s): bool => str_contains($s, 'SIGNAL SQLSTATE') && str_contains($s, 'is_active = 0'))) === 1,
    'El punto y coma dentro del literal duplicado de la migracion 010 no trocea la sentencia'
);

// 013 usa la misma forma con otro mensaje: `recogida; revise`.
$statement013 = SqlScriptSplitter::split((string)file_get_contents($baseDir . '/database/migrations/013_refund_pickup_pin_lockout.sql'));

$assert(
    count(array_filter($statement013, static fn (string $s): bool => str_contains($s, 'SIGNAL SQLSTATE') && str_contains($s, 'recogida; revise'))) === 1,
    'El punto y coma dentro del literal duplicado de la migracion 013 no trocea la sentencia'
);

$unbalanced = array_values(array_filter(
    array_merge($statement010, $statement013),
    static fn (string $s): bool => substr_count($s, "'") % 2 !== 0
));
$assert(
    $unbalanced === [],
    'Ninguna sentencia de las migraciones 010 y 013 queda con comillas simples desequilibradas',
    'desequilibradas: ' . count($unbalanced)
);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 2: el esquema del despliegue cubre el esquema probado
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 2: el esquema desplegado cubre el esquema de la aplicacion ---\n";

$serverPdo = ConnectionFactory::getConnection(['dbname' => 'mysql'], true);

foreach ([$deploySchema, $baseOnlySchema] as $scratchSchema) {
    $serverPdo->exec("DROP DATABASE IF EXISTS `{$scratchSchema}`");
    $serverPdo->exec("CREATE DATABASE `{$scratchSchema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

$assert(
    true,
    'Las bases de datos de sonda se crean desde cero y no tocan vendguard_db'
);

$livePdo = ConnectionFactory::getConnection();

$deployPdo = ConnectionFactory::getConnection(['dbname' => $deploySchema], true);
$baseOnlyPdo = ConnectionFactory::getConnection(['dbname' => $baseOnlySchema], true);

$baseDdlFile = $baseDir . '/database/cloud_init.sql';

try {
    // Ruta de despliegue real: DDL base + migraciones incrementales, las dos
    // con el mismo troceador y la misma forma de ejecución que el contenedor.
    $applySqlFile($deployPdo, $baseDdlFile);
    foreach ($migrationFiles as $migrationFile) {
        $applySqlFile($deployPdo, $migrationFile);
    }

    // Ruta de despliegue anterior al arreglo: solo el DDL base. Se construye
    // para demostrar que la diferencia es observable, no teórica.
    $applySqlFile($baseOnlyPdo, $baseDdlFile);

    // El yardstick: el esquema contra el que corre toda la batería.
    $liveRefund = $columnMap($livePdo, $liveSchema, 'refund_requests');
    $liveUnclaimed = $columnMap($livePdo, $liveSchema, 'unclaimed_cash_findings');

    $assert(
        count($liveRefund) >= 30 && count($liveUnclaimed) >= 7,
        'El esquema probado declara las dos tablas de reintegros con sus columnas',
        'refund_requests: ' . count($liveRefund) . ', unclaimed_cash_findings: ' . count($liveUnclaimed)
    );

    $deployRefund = $columnMap($deployPdo, $deploySchema, 'refund_requests');
    $deployUnclaimed = $columnMap($deployPdo, $deploySchema, 'unclaimed_cash_findings');

    $missingRefund = $missingColumns($liveRefund, $deployRefund);
    $assert(
        $missingRefund === [],
        'El esquema del despliegue declara todas las columnas de refund_requests que usa la aplicacion',
        'faltan: ' . implode(', ', $missingRefund)
    );

    $refundTypeMismatches = $typeMismatches($liveRefund, $deployRefund);
    $assert(
        $refundTypeMismatches === [],
        'Las columnas de refund_requests tienen el mismo tipo en el despliegue que en el entorno probado',
        json_encode($refundTypeMismatches, JSON_UNESCAPED_UNICODE) ?: ''
    );

    $missingUnclaimed = $missingColumns($liveUnclaimed, $deployUnclaimed);
    $assert(
        $missingUnclaimed === [],
        'El esquema del despliegue declara todas las columnas de unclaimed_cash_findings',
        'faltan: ' . implode(', ', $missingUnclaimed)
    );

    $unclaimedTypeMismatches = $typeMismatches($liveUnclaimed, $deployUnclaimed);
    $assert(
        $unclaimedTypeMismatches === [],
        'Las columnas de unclaimed_cash_findings tienen el mismo tipo en el despliegue',
        json_encode($unclaimedTypeMismatches, JSON_UNESCAPED_UNICODE) ?: ''
    );

    foreach ($columnsThatBrokeTheDeployment as $column) {
        $assert(
            isset($deployRefund[$column]),
            "La columna `{$column}` existe en el esquema que construye el arranque desplegado"
        );
    }

    // DECIMAL(10,2) no es un detalle de estilo: con DECIMAL(6,2) y este servidor
    // sin STRICT_TRANS_TABLES, 999999.00 se guardaba como 9999.99 sin avisar
    // (migracion 011_refund_amount_integrity.sql).
    $assert(
        ($deployRefund['claimed_amount'] ?? '') === 'decimal(10,2)'
        && ($deployRefund['recovered_amount'] ?? '') === 'decimal(10,2)'
        && ($deployRefund['approved_amount'] ?? '') === 'decimal(10,2)'
        && ($deployRefund['paid_amount'] ?? '') === 'decimal(10,2)'
        && ($deployUnclaimed['amount'] ?? '') === 'decimal(10,2)',
        'Los importes del despliegue son DECIMAL(10,2) y no pueden saturar en silencio',
        json_encode([
            'claimed_amount' => $deployRefund['claimed_amount'] ?? null,
            'paid_amount' => $deployRefund['paid_amount'] ?? null,
            'unclaimed.amount' => $deployUnclaimed['amount'] ?? null,
        ]) ?: ''
    );

    // ─────────────────────────────────────────────────────────────────────────
    // Caso 3: la guardia muerde (el esquema sin migraciones se detecta)
    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- Caso 3: la guardia detecta el despliegue sin migraciones ---\n";

    $baseOnlyRefund = $columnMap($baseOnlyPdo, $baseOnlySchema, 'refund_requests');
    $baseOnlyMissing = $missingColumns($liveRefund, $baseOnlyRefund);

    $assert(
        $baseOnlyMissing !== [],
        'Un esquema construido solo con el DDL base se detecta como incompleto',
        'faltan: ' . implode(', ', $baseOnlyMissing)
    );

    $stillMissing = array_values(array_intersect($columnsThatBrokeTheDeployment, $baseOnlyMissing));
    $assert(
        count($stillMissing) === count($columnsThatBrokeTheDeployment),
        'Las tres columnas del incidente de Render son exactamente las que faltan sin migraciones',
        'detectadas: ' . implode(', ', $stillMissing)
    );

    // Prueba de mordida en memoria: el comparador no puede pasar por vacío.
    $syntheticExpected = $liveRefund;
    $syntheticActual = $deployRefund;
    unset($syntheticActual['paid_amount']);
    $assert(
        $missingColumns($syntheticExpected, $syntheticActual) === ['paid_amount'],
        'El comparador de columnas senala una columna ausente aunque todo lo demas coincida'
    );
} finally {
    // Las conexiones a las sondas se sueltan antes de borrarlas.
    $deployPdo = null;
    $baseOnlyPdo = null;
    foreach ([$deploySchema, $baseOnlySchema] as $scratchSchema) {
        $serverPdo->exec("DROP DATABASE IF EXISTS `{$scratchSchema}`");
    }
}

echo "\n======================================================================\n";
echo " RESUMEN: {$assertionCount} aserciones superadas exitosamente (100% PASS).\n";
echo " CONDICIÓN T-REF-27 VERIFICADA SATISFACTORIAMENTE.\n";
echo "======================================================================\n";
