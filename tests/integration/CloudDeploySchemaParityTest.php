<?php

declare(strict_types=1);

/**
 * CloudDeploySchemaParityTest (T-REF-27 · ruta de despliegue portada al motor único en T-13-13)
 *
 * Guards the schema the DEPLOYED server ends up with.
 *
 * ## The failure this exists for
 * 2026-10-03: the refunds inbox answered 500 on the deploy while the whole local
 * environment and every other suite were green. The container boots with
 * `php bin/init_cloud_db.php`, which back then executed only the base DDL
 * (`database/cloud_init.sql`). That file creates every table with `CREATE TABLE
 * IF NOT EXISTS`, so it can define the schema of a database that does not exist
 * yet and nothing else: `refund_requests` had been created before `paid_amount`,
 * `pickup_attempts` and `pickup_locked_until` were declared, the incremental
 * layer never ran on the server, and the listing -- which selects all three --
 * died. The three columns had been in the migrations all along; the deployment
 * simply never applied them.
 *
 * A green battery could not see it by construction: every suite runs against the
 * local schema, which is migrated. The deployment runs against a schema that is
 * created, not migrated. That gap is what this suite closes.
 *
 * ## What changed in the single engine (T-13-13)
 * The route of record is now the one of the engine the system runs on:
 * `database/supabase_init.sql` + `database/supabase_migrations/` through
 * `PostgresMigrationRunner`, plus `database/supabase_seeds.sql` for the master
 * catalogue. The difference is not cosmetic: the retired route had **no**
 * convergence book, so nothing could tell a created schema from a converged one.
 * The single engine's route has a ledger (`schema_migrations`), a baseline that
 * refuses to run over a schema whose base DDL is missing, and a gate
 * (`assertConverged()`) that the boot script calls before serving. This suite
 * therefore certifies the route of record -- not a replica of it -- and its
 * failure modes are the ones of that route:
 *
 * 1. The boot script applies the base DDL, then the incremental layer **with the
 *    repository's own runner and splitter**, then the seeds, and it declares
 *    convergence as a gate (EARS-02.4/EARS-13.3).
 * 2. The shared splitter survives the single engine's escaping form -- the
 *    dollar-quoted bodies of `DO $$ ... $$` and `$$ ... $$ LANGUAGE plpgsql`,
 *    which carry semicolons of their own. A splitter that loses that awareness
 *    cuts every enum declaration and both trigger functions in half, and the
 *    deploy aborts halfway through.
 * 3. A schema built by the deploy route inside a scratch schema declares every
 *    table, every column and every type the tested schema has -- including the
 *    `NUMERIC(10,2)` amounts, so no figure can be silently saturated -- and both
 *    declare the same `ENUM` sets.
 * 4. The guard bites, in the three directions that matter: a schema built from
 *    the base DDL alone is **not** convergent (no ledger, baseline pending) and
 *    its gate fails in the open; the incremental layer applied with no base DDL
 *    **raises** naming the missing tables instead of declaring convergence over
 *    a schema that does not exist; and the comparators signal a column, a type or
 *    an enum label that goes missing.
 * 5. The boot is idempotent over an **older** schema, which is the shape every
 *    redeploy actually finds: the base DDL runs again over an `incidents` table
 *    that already exists, so `CREATE TABLE IF NOT EXISTS` adds none of the
 *    columns an incremental migration introduced. A base statement that reads
 *    such a column -- the opportunistic index did -- aborted the boot with
 *    SQLSTATE 42703 before the runner was reached. The probe reproduces that
 *    table, reapplies the base DDL and certifies that migrations complete it.
 *
 * ## Why a scratch schema and not a scratch database
 * The deployment target is a database whose owner is the application role, and
 * that role has no `CREATEDB` (`provision-tls-engine.sh` grants it
 * `NOSUPERUSER NOCREATEDB NOCREATEROLE`): the faithful probe of "a fresh deploy"
 * is a fresh **schema**, with `public` in its search path because the extensions
 * (`uuid-ossp`, `pgcrypto`) live there and the DDL installs them idempotently.
 * The battery's own bank is the yardstick and is never written to: probes are
 * created, measured and dropped.
 *
 * Requirements: RF-REF-01, RF-REF-10 (Art. III.1), RNF-REF-01, EARS-02.4,
 * EARS-02.5, EARS-13.3.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PostgresMigrationRunner;
use VendGuard\Infrastructure\Database\SqlScriptSplitter;

$baseDir = dirname(__DIR__, 2);

// Los tres esquemas de sonda. Viven en la base del arnés, que es el banco contra el
// que corre toda la batería, y se crean y se borran en cada ejecución.
$probeSchema = 'vendguard_deploy_probe';
$baseOnlySchema = 'vendguard_deploy_probe_base';
$emptySchema = 'vendguard_deploy_probe_empty';
$legacySchema = 'vendguard_deploy_probe_legacy';

// El esquema probado: el que la batería usa de verdad. Es el rasero, no el sujeto.
$yardstickSchema = 'public';

$deployDdlFile = $baseDir . '/database/supabase_init.sql';
$migrationsDirectory = $baseDir . '/database/supabase_migrations';
$seedsFile = $baseDir . '/database/supabase_seeds.sql';
$baselineFilename = '001_initial_baseline.sql';

echo "======================================================================\n";
echo " VendGuard: Test de Integración - CloudDeploySchemaParityTest (T-REF-27)\n";
echo " Ruta de despliegue del motor único (T-13-13)\n";
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
 * Forma canónica de un tipo, tal como la publica el catálogo del motor.
 *
 * `format_type()` cualifica con su esquema el tipo de usuario que no está en la
 * ruta de búsqueda -- es el caso del enumerado de una sonda frente al de
 * `public` --, y el esquema no es parte del contrato: la forma del tipo sí. Los
 * dígitos de `numeric(10,2)` y de `character varying(100)` NO se tocan: esas
 * anchuras son exactamente lo que un defecto de saturación silenciosa esconde.
 */
$normalizeType = static function (string $type): string {
    $normalized = strtolower(trim($type));

    return (string)preg_replace('/^[a-z_][a-z0-9_]*\./', '', $normalized);
};

/** Columnas de una tabla: nombre => forma canónica del tipo, ordenadas por nombre. */
$columnMap = static function (PDO $pdo, string $schema, string $table) use ($normalizeType): array {
    $stmt = $pdo->prepare(
        'SELECT a.attname AS column_name, format_type(a.atttypid, a.atttypmod) AS column_type
           FROM pg_attribute a
           JOIN pg_class c ON c.oid = a.attrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = :schema
            AND c.relname = :table
            AND a.attnum > 0
            AND NOT a.attisdropped
          ORDER BY a.attname'
    );
    $stmt->execute([':schema' => $schema, ':table' => $table]);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(string)$row['column_name']] = $normalizeType((string)$row['column_type']);
    }
    ksort($map);
    $stmt->closeCursor();

    return $map;
};

/** Tablas de un esquema: nombre => columnas. */
$tableMap = static function (PDO $pdo, string $schema) use ($columnMap): array {
    $stmt = $pdo->prepare(
        "SELECT c.relname AS table_name
           FROM pg_class c
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = :schema
            AND c.relkind = 'r'
          ORDER BY c.relname"
    );
    $stmt->execute([':schema' => $schema]);

    $tables = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tableName = (string)$row['table_name'];
        $tables[$tableName] = $columnMap($pdo, $schema, $tableName);
    }
    $stmt->closeCursor();
    ksort($tables);

    return $tables;
};

/** Conjuntos `ENUM` de un esquema: tipo => etiquetas en su ordinal declarado. */
$enumMap = static function (PDO $pdo, string $schema): array {
    $stmt = $pdo->prepare(
        'SELECT t.typname AS type_name, e.enumlabel AS label
           FROM pg_type t
           JOIN pg_namespace n ON n.oid = t.typnamespace
           JOIN pg_enum e ON e.enumtypid = t.oid
          WHERE n.nspname = :schema
          ORDER BY t.typname, e.enumsortorder'
    );
    $stmt->execute([':schema' => $schema]);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(string)$row['type_name']][] = (string)$row['label'];
    }
    $stmt->closeCursor();
    ksort($map);

    return $map;
};

/** Tablas que el rasero declara y el sujeto no. */
$tablesAbsentFrom = static function (array $yardstick, array $subject): array {
    return array_values(array_diff(array_keys($yardstick), array_keys($subject)));
};

/** Columnas que el rasero tiene y el sujeto no. */
$columnsAbsentFrom = static function (array $expected, array $actual): array {
    return array_values(array_diff(array_keys($expected), array_keys($actual)));
};

/** Columnas presentes en los dos pero declaradas con otra forma. */
$typeMismatches = static function (array $expected, array $actual): array {
    $mismatches = [];
    foreach ($expected as $column => $type) {
        if (isset($actual[$column]) && $actual[$column] !== $type) {
            $mismatches[$column] = $type . ' != ' . $actual[$column];
        }
    }

    return $mismatches;
};

/** Etiquetas del dominio que el conjunto declarado no tiene. */
$labelsAbsentFrom = static function (array $declared, array $expected): array {
    return array_values(array_filter(
        $expected,
        static fn (string $label): bool => !in_array($label, $declared, true)
    ));
};

/**
 * Ejecuta una sentencia como lo hace el arranque del contenedor: `PDO::query()`
 * y cierre del cursor.
 *
 * No es un detalle: las migraciones cierran sus comprobaciones de kernel con
 * `SELECT 1`, y un resultado sin leer deja la conexión inutilizable para la
 * sentencia siguiente. Replicarlo es lo que hace de la sonda un fiel del
 * despliegue.
 */
$runStatement = static function (PDO $pdo, string $statement): void {
    $result = $pdo->query($statement);
    if ($result instanceof PDOStatement) {
        $result->closeCursor();
    }
};

/** Aplica un script del repositorio sentencia a sentencia, con el troceador compartido. */
$applySqlFile = static function (PDO $pdo, string $path) use ($runStatement): int {
    $sql = (string)file_get_contents($path);
    $executed = 0;

    foreach (SqlScriptSplitter::split($sql) as $statement) {
        $runStatement($pdo, $statement);
        $executed++;
    }

    return $executed;
};

$migrationFiles = glob($migrationsDirectory . '/*.sql') ?: [];
sort($migrationFiles);
$migrationSql = '';
foreach ($migrationFiles as $migrationFile) {
    $migrationSql .= (string)file_get_contents($migrationFile);
}
$deploySql = (string)file_get_contents($deployDdlFile) . $migrationSql;
$baselinePath = $migrationsDirectory . '/' . $baselineFilename;

// Las tres columnas que la API consultaba contra un esquema que no las tenía.
$columnsThatBrokeTheDeployment = ['paid_amount', 'pickup_attempts', 'pickup_locked_until'];

// ─────────────────────────────────────────────────────────────────────────────
// Caso 1: el arranque desplegado aplica la ruta del motor único y la declara convergida
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 1: el arranque desplegado hace converger el esquema ---\n";

$bootScript = (string)file_get_contents($baseDir . '/bin/init_cloud_db.php');

$assert(
    str_contains($bootScript, 'database/supabase_init.sql'),
    'El arranque del contenedor (bin/init_cloud_db.php) aplica el DDL base del motor único'
);
$assert(
    str_contains($bootScript, 'database/supabase_migrations'),
    'El arranque aplica la capa incremental del motor único (database/supabase_migrations/)'
);
$assert(
    str_contains($bootScript, 'PostgresMigrationRunner'),
    'El arranque aplica las migraciones con el ejecutor del motor único'
);
$assert(
    str_contains($bootScript, 'SqlScriptSplitter::split('),
    'El arranque aplica las migraciones con el troceador compartido, sentencia a sentencia'
);
$assert(
    str_contains($bootScript, '$runner->assertConverged()'),
    'El arranque declara la convergencia como puerta y no como deseo (EARS-02.4)'
);
$assert(
    str_contains($bootScript, 'database/supabase_seeds.sql'),
    'El arranque carga el catálogo maestro del arranque en limpio (EARS-02.1)'
);
$assert(
    $migrationFiles !== [],
    'El repositorio declara la capa incremental del motor único',
    'ficheros: ' . count($migrationFiles)
);
$assert(
    is_file($baselinePath)
    && str_contains((string)file_get_contents($baselinePath), 'Baseline del motor nuevo incompleto'),
    'La línea base del libro existe y es la guarda de completitud de un arranque sin DDL base (EARS-02.4)'
);
$assert(
    str_contains($deploySql, 'paid_amount')
    && str_contains($deploySql, 'pickup_attempts')
    && str_contains($deploySql, 'pickup_locked_until'),
    'Las tres columnas que rompieron el despliegue viven en la ruta de despliegue del motor único'
);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 1d: el histórico de demostración entra por defecto en el arranque desplegado
// ─────────────────────────────────────────────────────────────────────────────
// 2026-10-15 (EARS-02.6): el paso [6/6] del arranque dejó de ser *opt-in*. El despliegue
// cargaba las diecisiete averías solo si alguien declaraba `VENDGUARD_DEMO_HISTORY=1`, de modo
// que una instalación recién hecha podía nacer con el cuadro de mandos vacío; y la pasada sobre
// un histórico ya sembrado reafirmaba las fechas del expediente dejando su traza anclada en la
// fecha anterior. Se certifica aquí, sin motor, que el arranque lo pide **por defecto** y que
// declara lo que hizo, de modo que la política no pueda volver a invertirse en silencio.
echo "\n--- Caso 1d: el histórico de demostración entra por defecto en el arranque ---\n";

$assert(
    str_contains($bootScript, '\\VendGuard\\Database\\DemoHistorySeeder::enabledByEnvironment()')
    && str_contains($bootScript, 'if (!\\VendGuard\\Database\\DemoHistorySeeder::enabledByEnvironment())'),
    'El arranque desplegado carga el histórico de demostración por defecto (opt-out, EARS-02.6)'
);
$assert(
    str_contains($bootScript, "history_realigned")
    && str_contains($bootScript, "audit_realigned")
    && str_contains($bootScript, "preserved")
    && str_contains($bootScript, "skipped"),
    'El arranque declara lo que hizo: marcas desplazadas, expedientes preservados y averías omitidas'
);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 1c: la cadena de arranque se resuelve sin autoloader
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 1c: el arranque resuelve su cadena de clases sin autoloader ---\n";

// 2026-10-08: el contenedor abortó en el paso 1/6 con «Class
// VendGuard\Infrastructure\Config\SecretProvider not found» mientras la batería
// local seguía verde. `bin/init_cloud_db.php` no usa el autoloader: declara a
// mano las clases que necesita, y `ConnectionFactory` (fallo en cerrado de S-5)
// y `SeedRunner` (claves de centro de S-4) ganaron dependencias que nadie añadió
// a esa lista. El mismo tipo de fallo que este suite ya cubría para el esquema,
// ahora para el arranque: la cadena se resuelve en un proceso limpio con los
// requires exactos del script, sin que intervenga el autoloader del bootstrap.
$bootRequirePaths = [];
if (preg_match_all("/require(?:_once)?\s+__DIR__\s*\.\s*'([^']+)'/", $bootScript, $bootRequireMatches)) {
    foreach ($bootRequireMatches[1] as $relativePath) {
        $resolvedPath = realpath($baseDir . '/bin/' . $relativePath);
        if ($resolvedPath !== false) {
            $bootRequirePaths[] = $resolvedPath;
        }
    }
}

// Clases VendGuard que la cadena usa, leídas de sus propios `use`: si una
// dependencia nueva entra en ConnectionFactory o SeedRunner, entra aquí sola.
$bootReferencedClasses = [];
$bootScanQueue = $bootRequirePaths;
$bootScannedFiles = [];
while ($bootScanQueue !== []) {
    $scannedFile = array_pop($bootScanQueue);
    if (isset($bootScannedFiles[$scannedFile]) || !is_file($scannedFile)) {
        continue;
    }
    $bootScannedFiles[$scannedFile] = true;

    preg_match_all('/^use\s+(VendGuard\\\\[A-Za-z0-9_\\\\]+);/m', (string)file_get_contents($scannedFile), $useMatches);
    foreach ($useMatches[1] as $usedClass) {
        $classFile = $baseDir . '/src/' . str_replace('\\', '/', substr($usedClass, strlen('VendGuard\\'))) . '.php';
        if (is_file($classFile)) {
            $bootReferencedClasses[$usedClass] = (string)realpath($classFile);
            $bootScanQueue[] = (string)realpath($classFile);
        }
    }
}

/**
 * Lanza un proceso PHP limpio con los requires indicados y comprueba que cada
 * clase de la lista resuelve y que las funciones puras devuelven lo pactado.
 *
 * @param array<int, string> $requirePaths Ficheros a requerir, en orden.
 * @param array<string, string> $classes Clases VendGuard que deben resolverse.
 * @return array{exit: int, output: string, error: string}
 */
$runBootProbe = static function (array $requirePaths, array $classes): array {
    $probeLines = ['<?php', 'declare(strict_types=1);'];
    foreach ($requirePaths as $requirePath) {
        $probeLines[] = 'require_once ' . var_export($requirePath, true) . ';';
    }
    $probeLines[] = '$classes = ' . var_export(array_keys($classes), true) . ';';
    $probeLines[] = 'foreach ($classes as $fqcn) {';
    $probeLines[] = '    if (!class_exists($fqcn) && !interface_exists($fqcn) && !enum_exists($fqcn)) {';
    $probeLines[] = '        fwrite(STDERR, "BOOT_CHAIN_MISSING:" . $fqcn);';
    $probeLines[] = '        exit(3);';
    $probeLines[] = '    }';
    $probeLines[] = '}';
    // Las comprobaciones de clave solo tienen sentido para quien carga esas clases:
    // el arranque desplegado y las herramientas de semillas las referencian; un
    // script que solo migra no tiene por qué poder llamarlas.
    if (isset($classes['VendGuard\\Core\\Domain\\Service\\SiteAccessCodeGenerator'])) {
        $probeLines[] = '$code = \VendGuard\Core\Domain\Service\SiteAccessCodeGenerator::normalize("K7M4p-2qx9r");';
        $probeLines[] = 'if ($code !== "K7M4P2QX9R") { fwrite(STDERR, "NORMALIZE_MISMATCH:" . $code); exit(4); }';
    }
    if (isset($classes['VendGuard\\Infrastructure\\Database\\SeedRunner'])) {
        $probeLines[] = 'if (\VendGuard\Infrastructure\Database\SeedRunner::devAccessCode("sede-bcn-01") !== "DEV-SEDE-BCN-01") { fwrite(STDERR, "DEV_KEY_MISMATCH"); exit(5); }';
    }
    $probeLines[] = 'echo "BOOT_CHAIN_OK";';

    $probeFile = tempnam(sys_get_temp_dir(), 'vgboot');
    if ($probeFile === false) {
        return ['exit' => -1, 'output' => '', 'error' => 'no se pudo crear el fichero sonda'];
    }
    file_put_contents($probeFile, implode("\n", $probeLines));

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(PHP_BINARY . ' ' . escapeshellarg($probeFile), $descriptors, $pipes);
    if (!is_resource($process)) {
        @unlink($probeFile);

        return ['exit' => -1, 'output' => '', 'error' => 'no se pudo lanzar el proceso sonda'];
    }

    $output = (string)stream_get_contents($pipes[1]);
    $error = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($probeFile);

    return ['exit' => $exitCode, 'output' => $output, 'error' => $error];
};

$assert(
    count($bootRequirePaths) >= 4
    && isset($bootReferencedClasses['VendGuard\\Infrastructure\\Config\\SecretProvider'])
    && isset($bootReferencedClasses['VendGuard\\Core\\Domain\\Service\\SiteAccessCodeGenerator']),
    'La sonda lee los requires del arranque y las clases de su cadena (S-5 y S-4 incluidas)',
    'requires: ' . count($bootRequirePaths) . ' -- clases: ' . implode(', ', array_keys($bootReferencedClasses))
);

$missingBootRequires = [];
foreach ($bootReferencedClasses as $usedClass => $classFile) {
    if (!in_array($classFile, $bootRequirePaths, true)) {
        $missingBootRequires[] = $usedClass;
    }
}
$assert(
    $missingBootRequires === [],
    'Cada clase que la cadena de arranque usa esta declarada en su lista de requires',
    'sin require: ' . implode(', ', $missingBootRequires)
);

$bootProbe = $runBootProbe($bootRequirePaths, $bootReferencedClasses);
$assert(
    $bootProbe['exit'] === 0 && str_contains($bootProbe['output'], 'BOOT_CHAIN_OK'),
    'Un proceso limpio con los requires del arranque resuelve toda la cadena y sus claves',
    trim($bootProbe['error'] . ' ' . $bootProbe['output'])
);

// Prueba de mordida: sin el require de la clase que rompió el despliegue, la
// misma sonda tiene que fallar. Si no fallara, la guardia no serviría de nada.
$bittenRequires = array_values(array_filter(
    $bootRequirePaths,
    static fn (string $path): bool => !in_array($path, array_values($bootReferencedClasses), true)
));
$bittenProbe = $runBootProbe($bittenRequires, $bootReferencedClasses);
$assert(
    $bittenProbe['exit'] !== 0 && !str_contains($bittenProbe['output'], 'BOOT_CHAIN_OK'),
    'Sin los requires de la cadena, la sonda falla: la guardia muerde',
    trim($bittenProbe['error'] . ' ' . $bittenProbe['output'])
);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 1c-bis: el mismo contrato para el resto de la caja de herramientas
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 1c-bis: los demas scripts de bin/ resuelven su cadena ---\n";

// `bin/seed.php`, `bin/seed_demo_metrics.php` y `bin/migrate.php` declaran a mano las clases
// que usan, y heredaron las mismas dependencias que rompieron el contenedor
// (`SecretProvider` por S-5, `SiteAccessCodeGenerator` por S-4). El script del
// despliegue ya tiene su propia certificación arriba; esta recorre el resto para
// que ninguna herramienta del repositorio vuelva a morir con «Class not found»
// mientras la batería sigue verde.
$chainSpecOf = static function (string $entryScript) use ($baseDir): array {
    $entrySource = (string)file_get_contents($entryScript);

    $requiredFiles = [];
    if (preg_match_all("/require(?:_once)?\s+__DIR__\s*\.\s*'([^']+)'/", $entrySource, $requireMatches)) {
        foreach ($requireMatches[1] as $relativePath) {
            $resolvedPath = realpath(dirname($entryScript) . '/' . $relativePath);
            if ($resolvedPath !== false) {
                $requiredFiles[] = $resolvedPath;
            }
        }
    }

    // Clases VendGuard que referencia la cadena: los `use` de cada fichero
    // requerido más las menciones cualificadas del propio script.
    $referencedClasses = [];
    $scanQueue = $requiredFiles;
    $scannedFiles = [];
    while ($scanQueue !== []) {
        $scannedFile = array_pop($scanQueue);
        if (isset($scannedFiles[$scannedFile]) || !is_file($scannedFile)) {
            continue;
        }
        $scannedFiles[$scannedFile] = true;

        preg_match_all('/^use\s+(VendGuard\\\\[A-Za-z0-9_\\\\]+);/m', (string)file_get_contents($scannedFile), $useMatches);
        foreach ($useMatches[1] as $usedClass) {
            $classFile = $baseDir . '/src/' . str_replace('\\', '/', substr($usedClass, strlen('VendGuard\\'))) . '.php';
            if (is_file($classFile)) {
                $referencedClasses[$usedClass] = (string)realpath($classFile);
                $scanQueue[] = (string)realpath($classFile);
            }
        }
    }

    preg_match_all('/\\\\VendGuard\\\\[A-Za-z0-9_\\\\]+/', $entrySource, $qualifiedMatches);
    foreach ($qualifiedMatches[0] as $qualifiedClass) {
        $usedClass = ltrim($qualifiedClass, '\\');
        $classFile = $baseDir . '/src/' . str_replace('\\', '/', substr($usedClass, strlen('VendGuard\\'))) . '.php';
        if (is_file($classFile)) {
            $referencedClasses[$usedClass] = (string)realpath($classFile);
        }
    }

    return ['requires' => $requiredFiles, 'classes' => $referencedClasses];
};

$toolchainFailures = [];
foreach (glob($baseDir . '/bin/*.php') ?: [] as $entryScript) {
    if ((string)realpath($entryScript) === (string)realpath($baseDir . '/bin/init_cloud_db.php')) {
        continue; // el arranque desplegado ya está certificado en el Caso 1c
    }

    $chainSpec = $chainSpecOf($entryScript);
    $chainProbe = $runBootProbe($chainSpec['requires'], $chainSpec['classes']);
    if ($chainProbe['exit'] !== 0 || !str_contains($chainProbe['output'], 'BOOT_CHAIN_OK')) {
        $toolchainFailures[] = basename($entryScript) . ' -> ' . trim($chainProbe['error'] . ' ' . $chainProbe['output']);
    }
}
$assert(
    $toolchainFailures === [],
    'Los scripts de bin/ resuelven en proceso limpio la cadena VendGuard que declaran',
    implode(' | ', $toolchainFailures)
);

// ─────────────────────────────────────────────────────────────────────────────
// Caso 1b: el troceador respeta el acotado por signos de dólar
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Caso 1b: el troceador respeta los cuerpos acotados por dolar ---\n";

// El DDL base declara sus 24 conjuntos `ENUM` y sus dos funciones de disparador
// dentro de cuerpos acotados por dólar (`DO $do$ ... $do$`, `$$ ... $$ LANGUAGE
// plpgsql`), y esos cuerpos llevan puntos y coma propios. Si el troceador no
// reconociera el acotado, cada uno de esos bloques se partiría por la mitad y el
// arranque abortaría en la primera sentencia cortada.
$deployDdlSql = (string)file_get_contents($deployDdlFile);
$deployDdlStatements = SqlScriptSplitter::split($deployDdlSql);

$dollarBlocks = array_values(array_filter(
    $deployDdlStatements,
    static fn (string $statement): bool => str_starts_with(strtoupper(ltrim($statement)), 'DO ')
));
$dollarBlocksWithoutGuard = array_values(array_filter(
    $dollarBlocks,
    static fn (string $statement): bool => !str_contains($statement, 'EXCEPTION WHEN duplicate_object')
));
$dollarBlocksUnclosed = array_values(array_filter(
    $dollarBlocks,
    static fn (string $statement): bool => !str_ends_with(rtrim($statement), 'END $do$')
));

$assert(
    count($dollarBlocks) >= 20,
    'Los bloques DO del DDL base llegan sentencia a sentencia, sin cortarse por sus puntos y coma internos',
    'bloques: ' . count($dollarBlocks)
);
$assert(
    $dollarBlocksWithoutGuard === [],
    'Cada bloque DO conserva su cuerpo completo: la declaracion del enumerado y su tolerancia al objeto duplicado',
    'bloques truncados: ' . count($dollarBlocksWithoutGuard)
);
$assert(
    $dollarBlocksUnclosed === [],
    'Cada bloque DO cierra con su propio delimitador, no con el de la sentencia siguiente',
    'bloques sin cierre: ' . count($dollarBlocksUnclosed)
);
$assert(
    count($deployDdlStatements) < substr_count($deployDdlSql, ';'),
    'La cuenta de sentencias es menor que la de puntos y coma: los de dentro de los cuerpos no trocean',
    'sentencias: ' . count($deployDdlStatements) . ' · puntos y coma: ' . substr_count($deployDdlSql, ';')
);

// Prueba de mordida: sin conciencia del acotado por dólar, ningún bloque llega
// entero. El medidor tiene que discriminar de verdad.
$naiveFragments = array_values(array_filter(
    array_map('trim', explode(';', $deployDdlSql)),
    static fn (string $fragment): bool => $fragment !== ''
));
$naiveCompleteBlocks = count(array_filter(
    $naiveFragments,
    static fn (string $fragment): bool => str_starts_with(strtoupper($fragment), 'DO ')
        && str_ends_with($fragment, 'END $do$')
));
$assert(
    $naiveCompleteBlocks === 0 && count($dollarBlocks) >= 20,
    'El medidor discrimina: un troceado ingenuo por punto y coma no deja ni un bloque entero y el troceador compartido los deja todos',
    'bloques enteros con troceado ingenuo: ' . $naiveCompleteBlocks
);

// La línea base del libro es el otro cuerpo acotado de la ruta: consulta catálogos
// y decide si el arranque puede declararse convergido.
$baselineStatements = SqlScriptSplitter::split((string)file_get_contents($baselinePath));
$baselineGuards = array_values(array_filter(
    $baselineStatements,
    static fn (string $statement): bool => str_contains($statement, 'Baseline del motor nuevo incompleto')
));
$assert(
    count($baselineGuards) === 1
    && str_starts_with($baselineGuards[0], 'DO $$')
    && str_contains($baselineGuards[0], 'RAISE EXCEPTION')
    && substr_count($baselineGuards[0], ';') >= 3,
    'La guarda de completitud de la linea base llega en una sola sentencia, con sus puntos y coma internos',
    'sentencias: ' . count($baselineStatements)
);

// ─────────────────────────────────────────────────────────────────────────────
// Casos 2 a 4: la ruta de despliegue se construye de verdad, en esquemas de sonda
// ─────────────────────────────────────────────────────────────────────────────
$definitionsPdo = null;
$yardstickPdo = null;
$probePdo = null;
$baseOnlyPdo = null;
$emptyPdo = null;
$legacyPdo = null;

try {
    // El rasero: el esquema contra el que corre toda la batería. No se toca.
    $yardstickPdo = ConnectionFactory::getConnection();
    $yardstickTables = $tableMap($yardstickPdo, $yardstickSchema);
    $yardstickColumns = array_sum(array_map('count', $yardstickTables));
    $yardstickEnums = $enumMap($yardstickPdo, $yardstickSchema);

    $definitionsPdo = ConnectionFactory::getConnection([], true);

    // ─────────────────────────────────────────────────────────────────────────
    // Caso 2: el esquema que construye la ruta de despliegue cubre el esquema probado
    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- Caso 2: la ruta de despliegue cubre el esquema probado ---\n";

    $assert(
        count($yardstickTables) >= 20 && $yardstickColumns >= 150,
        'El esquema probado declara el parque de tablas y columnas del contrato',
        'tablas: ' . count($yardstickTables) . ' (rasero; incluye el libro del ejecutor) · columnas: ' . $yardstickColumns
    );

    foreach ([$probeSchema, $baseOnlySchema, $emptySchema, $legacySchema] as $scratchSchema) {
        $definitionsPdo->exec("DROP SCHEMA IF EXISTS {$scratchSchema} CASCADE");
        $definitionsPdo->exec("CREATE SCHEMA {$scratchSchema}");
    }
    $assert(
        true,
        'Los esquemas de sonda se crean desde cero y no tocan el esquema probado'
    );

    // El despliegue real: DDL base, capa incremental por el ejecutor del repositorio,
    // todo dentro del esquema de sonda. `public` va en la ruta de búsqueda porque las
    // extensiones viven ahí y el DDL las instala de forma idempotente.
    $probePdo = ConnectionFactory::getConnection([], true);
    $probePdo->exec("SET search_path TO {$probeSchema}, public");

    $deployStatements = $applySqlFile($probePdo, $deployDdlFile);
    $probeRunner = new PostgresMigrationRunner($probePdo);
    $appliedNow = $probeRunner->runAll();

    $assert(
        $deployStatements >= 100 && $appliedNow === array_map('basename', $migrationFiles),
        'La ruta de despliegue aplica el DDL base y la capa incremental completa del repositorio',
        'sentencias: ' . $deployStatements . ' · migraciones: ' . implode(', ', $appliedNow)
    );

    $convergenceFailure = '';
    try {
        $probeRunner->assertConverged();
    } catch (Throwable $exception) {
        $convergenceFailure = $exception->getMessage();
    }
    $assert(
        $convergenceFailure === '',
        'Tras aplicar el libro, la ruta de despliegue declara el esquema convergido (EARS-02.4)',
        $convergenceFailure
    );

    $probeTables = $tableMap($probePdo, $probeSchema);
    $tablesAbsent = $tablesAbsentFrom($yardstickTables, $probeTables);

    $assert(
        $tablesAbsent === [],
        'La ruta de despliegue declara todas las tablas que usa la aplicacion',
        'faltan: ' . implode(', ', $tablesAbsent)
    );

    $columnDeviations = [];
    foreach ($yardstickTables as $tableName => $expectedColumns) {
        if (!isset($probeTables[$tableName])) {
            continue;
        }

        foreach ($columnsAbsentFrom($expectedColumns, $probeTables[$tableName]) as $column) {
            $columnDeviations[] = "{$tableName}.{$column} ausente";
        }
        foreach ($typeMismatches($expectedColumns, $probeTables[$tableName]) as $column => $mismatch) {
            $columnDeviations[] = "{$tableName}.{$column} {$mismatch}";
        }
    }
    $assert(
        $columnDeviations === [],
        'Cada tabla declara las mismas columnas y la misma forma de tipo en el despliegue que en el esquema probado',
        implode(' | ', array_slice($columnDeviations, 0, 8))
    );

    $probeRefund = $probeTables['refund_requests'] ?? [];
    $probeUnclaimed = $probeTables['unclaimed_cash_findings'] ?? [];

    foreach ($columnsThatBrokeTheDeployment as $column) {
        $assert(
            isset($probeRefund[$column]),
            "La columna `{$column}` existe en el esquema que construye la ruta de despliegue"
        );
    }

    // NUMERIC(10,2) no es un detalle de estilo: un importe declarado con menos
    // dígitos deja que una cifra alta se guarde truncada sin avisar. Las tres
    // columnas del incidente son de esta tabla.
    $assert(
        ($probeRefund['claimed_amount'] ?? '') === 'numeric(10,2)'
        && ($probeRefund['recovered_amount'] ?? '') === 'numeric(10,2)'
        && ($probeRefund['approved_amount'] ?? '') === 'numeric(10,2)'
        && ($probeRefund['paid_amount'] ?? '') === 'numeric(10,2)'
        && ($probeUnclaimed['amount'] ?? '') === 'numeric(10,2)',
        'Los importes del despliegue son NUMERIC(10,2) y no pueden saturar en silencio',
        json_encode([
            'claimed_amount' => $probeRefund['claimed_amount'] ?? null,
            'paid_amount' => $probeRefund['paid_amount'] ?? null,
            'unclaimed.amount' => $probeUnclaimed['amount'] ?? null,
        ]) ?: ''
    );

    // Prueba de mordida en memoria: el comparador no puede pasar por vacío.
    $syntheticActual = $probeTables['refund_requests'];
    unset($syntheticActual['paid_amount']);
    $assert(
        $columnsAbsentFrom($yardstickTables['refund_requests'], $syntheticActual) === ['paid_amount'],
        'El comparador de columnas senala una columna ausente aunque todo lo demas coincida'
    );
    $assert(
        $typeMismatches(['paid_amount' => 'numeric(10,2)'], ['paid_amount' => 'numeric(6,2)'])
            === ['paid_amount' => 'numeric(10,2) != numeric(6,2)'],
        'El comparador de tipos senala el ensanchamiento de un importe, no solo su ausencia'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // Caso 3: la guardia muerde
    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- Caso 3: la guardia detecta el despliegue sin convergencia ---\n";

    // Dirección A: solo el DDL base. Es la ruta anterior al arreglo: crea el esquema
    // entero y **no** deja libro, de modo que nada puede declararla convergida.
    $baseOnlyPdo = ConnectionFactory::getConnection([], true);
    $baseOnlyPdo->exec("SET search_path TO {$baseOnlySchema}, public");
    $applySqlFile($baseOnlyPdo, $deployDdlFile);
    $baseOnlyRunner = new PostgresMigrationRunner($baseOnlyPdo);

    $baseOnlyReport = $baseOnlyRunner->convergenceReport();
    $assert(
        $baseOnlyReport['converged'] === false
        && in_array($baselineFilename, $baseOnlyReport['pending'], true),
        'Un esquema construido solo con el DDL base se declara NO convergido: el despliegue anterior al arreglo se detecta',
        'pendientes: ' . implode(', ', $baseOnlyReport['pending'])
    );
    $assert(
        $baseOnlyRunner->ledgerExists() === false,
        'Sin la capa incremental no hay libro del ejecutor: la convergencia no se puede declarar por accidente'
    );

    $baseOnlyGateFailed = '';
    try {
        $baseOnlyRunner->assertConverged();
    } catch (Throwable $exception) {
        $baseOnlyGateFailed = $exception->getMessage();
    }
    $assert(
        $baseOnlyGateFailed !== '' && str_contains($baseOnlyGateFailed, 'EARS-02.4'),
        'La puerta de convergencia del arranque falla en voz alta sobre el despliegue sin migraciones',
        $baseOnlyGateFailed
    );

    $baseOnlyTables = $tableMap($baseOnlyPdo, $baseOnlySchema);
    $baseOnlyMissing = $tablesAbsentFrom($yardstickTables, $baseOnlyTables);
    $assert(
        $baseOnlyMissing === ['schema_migrations'],
        'El unico objeto que el DDL base no declara es el libro del ejecutor, y esa ausencia es la que delata la falta de convergencia',
        'faltan: ' . implode(', ', $baseOnlyMissing)
    );

    // Dirección B: la capa incremental sin el DDL base. La ruta tiene que **fallar en
    // voz alta** en lugar de registrar la convergencia sobre un esquema que no existe:
    // es exactamente el modo de fallo que EARS-02.4/EARS-02.5 prohiben.
    $emptyPdo = ConnectionFactory::getConnection([], true);
    $emptyPdo->exec("SET search_path TO {$emptySchema}, public");
    $emptyRunner = new PostgresMigrationRunner($emptyPdo);

    $bitMessage = '';
    try {
        $emptyRunner->runAll();
    } catch (Throwable $exception) {
        $bitMessage = $exception->getMessage();
    }
    $assert(
        str_contains($bitMessage, 'Baseline del motor nuevo incompleto'),
        'Sin el DDL base, la capa incremental falla en voz alta en lugar de declarar convergido un esquema que no existe',
        $bitMessage
    );
    $assert(
        str_contains($bitMessage, $baselineFilename),
        'El fallo nombra la migracion que lo declaro'
    );

    $emptyTables = $tableMap($emptyPdo, $emptySchema);
    $emptyReport = $emptyRunner->convergenceReport();
    $assert(
        array_diff(array_keys($emptyTables), [PostgresMigrationRunner::LEDGER_TABLE]) === []
        && $emptyReport['converged'] === false
        && $emptyReport['pending'] !== [],
        'Tras el rechazo, el esquema sigue declarandose no convergido: la transaccion de la migracion no dejo rastro',
        'tablas: ' . implode(', ', array_keys($emptyTables))
    );

    // ─────────────────────────────────────────────────────────────────────────
    // Caso 4: los conjuntos ENUM de la ruta de despliegue son los del dominio
    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- Caso 4: los ENUM del despliegue declaran los valores del dominio ---\n";

    // El DDL base **crea** los 24 conjuntos, de modo que un valor que solo viviera en
    // una migracion posterior no estaria en un arranque en limpio. La guarda compara
    // conjuntos, no ordinales: el contrato declara el mismo conjunto de miembros en
    // los dos motores y no fija el orden como parte de la regla.
    $assert(
        count($yardstickEnums) >= 24,
        'El esquema probado declara los conjuntos ENUM del contrato',
        'conjuntos: ' . count($yardstickEnums)
    );

    $probeEnums = $enumMap($probePdo, $probeSchema);
    $assert(
        $probeEnums === $yardstickEnums,
        'La ruta de despliegue declara los mismos conjuntos ENUM, con las mismas etiquetas, que el esquema probado',
        'desviaciones: ' . json_encode(array_keys(array_diff_key($yardstickEnums, $probeEnums))) ?: ''
    );

    $applicationStatuses = IncidentStatus::values();
    $statusDeviations = [];
    foreach ([$yardstickSchema => $yardstickEnums, $probeSchema => $probeEnums] as $schemaName => $enums) {
        $declared = $enums['incident_status'] ?? [];
        if ($declared === []) {
            $statusDeviations[] = "{$schemaName} no declara el enumerado incident_status";
            continue;
        }
        $missing = $labelsAbsentFrom($declared, $applicationStatuses);
        if ($missing !== []) {
            $statusDeviations[] = "{$schemaName} sin " . implode(', ', $missing);
        }
    }
    $assert(
        $statusDeviations === [],
        'Los dos esquemas declaran todos los estados de IncidentStatus que la aplicacion escribe',
        implode(' | ', $statusDeviations)
    );

    // Prueba de mordida: el comparador tiene que discriminar de verdad.
    $syntheticMissing = $labelsAbsentFrom(['REGISTERED', 'CLOSED'], $applicationStatuses);
    $assert(
        $syntheticMissing !== [] && in_array('PENDING_INFO', $syntheticMissing, true),
        'El comparador de estados senala los que faltan en un enumerado incompleto',
        'detectados: ' . implode(', ', $syntheticMissing)
    );

    // ─────────────────────────────────────────────────────────────────────────
    // Caso 5: el despliegue sobre una base anterior al Módulo 16
    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- Caso 5: el DDL base se reaplica sobre un esquema preexistente ---\n";

    // El DDL base se ejecuta en cada arranque, no solo en el primero. Una base
    // creada antes del Módulo 16 ya tiene `incidents`, así que `CREATE TABLE IF NOT
    // EXISTS` no añade las columnas oportunistas: las añade la migración 005. Si el
    // DDL base declara un índice sobre esa columna sin guarda, el paso 3/6 aborta con
    // SQLSTATE 42703 y el ejecutor nunca llega al paso 4/6. Este caso reproduce esa
    // base y certifica que la ruta converge igual.
    $legacyPdo = ConnectionFactory::getConnection([], true);
    $legacyPdo->exec("SET search_path TO {$legacySchema}, public");
    $applySqlFile($legacyPdo, $deployDdlFile);

    // Se retiran las cuatro columnas de la migración 005 (y, en cascada, su
    // restricción, su clave foránea y su índice) para dejar el parque anterior.
    $legacyPdo->exec(
        'ALTER TABLE incidents '
        . 'DROP COLUMN IF EXISTS opportunistic_mode, '
        . 'DROP COLUMN IF EXISTS opportunistic_origin_reason, '
        . 'DROP COLUMN IF EXISTS opportunistic_informant_name, '
        . 'DROP COLUMN IF EXISTS chronic_predecessor_incident_id CASCADE'
    );

    $columnExists = static function (PDO $pdo, string $schema, string $table, string $column): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns '
            . 'WHERE table_schema = :schema AND table_name = :table AND column_name = :column'
        );
        $stmt->execute([':schema' => $schema, ':table' => $table, ':column' => $column]);

        return (int)$stmt->fetchColumn() > 0;
    };

    $assert(
        !$columnExists($legacyPdo, $legacySchema, 'incidents', 'opportunistic_mode'),
        'La sonda reproduce una base anterior al Módulo 16, sin la columna oportunista'
    );

    $legacyReapplyFailure = '';
    try {
        $applySqlFile($legacyPdo, $deployDdlFile);
    } catch (Throwable $exception) {
        $legacyReapplyFailure = $exception->getMessage();
    }
    $assert(
        $legacyReapplyFailure === '',
        'El DDL base se reaplica sobre una base preexistente sin abortar por una columna que aún no existe (EARS-02.5)',
        $legacyReapplyFailure
    );

    $legacyRunner = new PostgresMigrationRunner($legacyPdo);
    $legacyApplied = $legacyRunner->runAll();
    $legacyConvergenceFailure = '';
    try {
        $legacyRunner->assertConverged();
    } catch (Throwable $exception) {
        $legacyConvergenceFailure = $exception->getMessage();
    }

    $legacyIndexDefinition = (string)$legacyPdo->query(
        "SELECT indexdef FROM pg_indexes WHERE schemaname = '{$legacySchema}' AND indexname = 'idx_incidents_opportunistic_mode'"
    )->fetchColumn();
    $assert(
        $legacyConvergenceFailure === ''
        && in_array('005_opportunistic_incident.sql', array_map('basename', $legacyApplied), true)
        && $columnExists($legacyPdo, $legacySchema, 'incidents', 'opportunistic_mode')
        && str_contains($legacyIndexDefinition, 'opportunistic_mode')
        && str_contains($legacyIndexDefinition, 'deleted_at IS NULL'),
        'La capa incremental completa la base anterior: columna e índice parcial presentes y convergencia declarada',
        'convergencia: ' . $legacyConvergenceFailure . ' · índice: ' . $legacyIndexDefinition
    );
} finally {
    // Las conexiones a las sondas se sueltan antes de borrarlas. El borrado va por una
    // conexion propia para no depender de la que quedó apuntando al esquema de sonda.
    $yardstickPdo = null;
    $probePdo = null;
    $baseOnlyPdo = null;
    $emptyPdo = null;
    $legacyPdo = null;

    if ($definitionsPdo instanceof PDO) {
        foreach ([$probeSchema, $baseOnlySchema, $emptySchema, $legacySchema] as $scratchSchema) {
            $definitionsPdo->exec("DROP SCHEMA IF EXISTS {$scratchSchema} CASCADE");
        }
    }
}

echo "\n======================================================================\n";
echo " RESUMEN: {$assertionCount} aserciones superadas exitosamente (100% PASS).\n";
echo " CONDICIÓN T-REF-27 VERIFICADA SATISFACTORIAMENTE (motor único).\n";
echo "======================================================================\n";
