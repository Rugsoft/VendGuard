<?php

declare(strict_types=1);

/**
 * VendGuard - Inicializador Automático de Base de Datos Cloud (Motor único PostgreSQL / Supabase)
 * (T-13-15)
 *
 * Ejecuta el esquema DDL base (`database/supabase_init.sql`), las migraciones incrementales
 * (`database/supabase_migrations/`), el catálogo maestro (`database/supabase_seeds.sql`) y
 * el histórico de demostración (`database/DemoHistorySeeder.php`), dividiendo y ejecutando
 * sentencia por sentencia de forma segura y tolerante sobre el motor único del sistema: PostgreSQL.
 *
 * Uso:
 *   1. Desde CLI con variables de entorno:
 *      DATABASE_URL=postgres://... php bin/init_cloud_db.php
 *   2. Desde CLI con argumentos:
 *      php bin/init_cloud_db.php --host=... --user=... --password="..." --port=6543 --name=postgres --ssl=1
 *   3. En arranque de contenedor Docker (Render):
 *      php bin/init_cloud_db.php
 *
 * ## Por qué además del DDL base se aplican las migraciones
 * `database/supabase_init.sql` crea las tablas con `CREATE TABLE IF NOT EXISTS`,
 * así que solo puede definir el esquema de una base de datos que todavía no existe.
 * Una columna añadida por una migración posterior no llegaría nunca al servidor desplegado
 * sin la capa incremental. Este script hace converger la base con `PostgresMigrationRunner`.
 *
 * ## Por qué reintenta y por qué no arranca sin verificar
 * EARS-02.5: si el motor no está disponible en el arranque el inicializador reintenta con espera
 * progresiva un mínimo de tres veces y no deja el servicio sirviendo con un esquema no verificado.
 * El código de salida es la puerta: `0` significa «esquema aplicado y comprobado», y cualquier
 * otra cosa significa que el servidor web no debe arrancar contra esa base.
 */

require_once __DIR__ . '/../src/Infrastructure/Config/SecretProvider.php';
require_once __DIR__ . '/../src/Core/Domain/Service/SiteAccessCodeGenerator.php';
require_once __DIR__ . '/../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../src/Infrastructure/Dialect/SqlDialectAdapter.php';
require_once __DIR__ . '/../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../src/Infrastructure/Database/SeedRunner.php';
require_once __DIR__ . '/../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PostgresMigrationRunner;
use VendGuard\Infrastructure\Database\SqlScriptSplitter;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

echo "======================================================================\n";
echo " VendGuard: Inicializador Automático de Base de Datos Cloud (PostgreSQL)\n";
echo "======================================================================\n\n";

// 1. Parsear opciones CLI (--host, --user, --password, --name, --port, --ssl)
$shortopts = "";
$longopts  = [
    "host::",
    "port::",
    "name::",
    "user::",
    "password::",
    "ssl::",
];
$options = getopt($shortopts, $longopts);

$config = [];
if (isset($options['host'])) {
    $config['host'] = (string)$options['host'];
}
if (isset($options['port'])) {
    $config['port'] = (string)$options['port'];
}
if (isset($options['name'])) {
    $config['dbname'] = (string)$options['name'];
}
if (isset($options['user'])) {
    $config['user'] = (string)$options['user'];
}
if (isset($options['password'])) {
    $config['password'] = (string)$options['password'];
}
if (isset($options['ssl'])) {
    $config['ssl'] = ($options['ssl'] === '1' || $options['ssl'] === 'true');
}

// 2. Arranque con reintento y espera progresiva (EARS-02.5).
$maxAttempts = 3;
$progressiveWaits = [3, 8];

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    try {
        $summary = initializeDatabase($config);

        echo "======================================================================\n";
        echo " ¡ÉXITO! La base de datos ha sido inicializada y está 100% lista.\n";
        echo " Motor: {$summary['driver']} · intentos consumidos: {$attempt}\n";
        echo "======================================================================\n";
        exit(0);
    } catch (Throwable $e) {
        echo "\n[INTENTO {$attempt}/{$maxAttempts}] La inicialización falló: " . $e->getMessage() . "\n";

        if ($attempt < $maxAttempts) {
            $wait = $progressiveWaits[$attempt - 1] ?? 8;
            echo "      · El motor todavía no responde o el esquema no está listo: nuevo intento en {$wait} segundos...\n\n";
            sleep($wait);
            continue;
        }
    }
}

echo "\n[ERROR FATAL]: El esquema no quedó aplicado y verificado tras {$maxAttempts} intentos.\n";
echo "El servicio no debe arrancar contra una base de datos sin esquema comprobado (EARS-02.5).\n";
exit(1);

/**
 * Inicializa la base de datos PostgreSQL: DDL base, migraciones incrementales,
 * catálogo maestro, histórico de demostración y verificación.
 *
 * @param array<string, mixed> $config Configuración explícita de conexión.
 * @return array{driver: string, verified: array<string, mixed>}
 */
function initializeDatabase(array $config): array
{
    echo "[1/6] Estableciendo conexión con el servidor de base de datos PostgreSQL...\n";
    $pdo = ConnectionFactory::getConnection($config, true);
    $dialect = SqlDialectAdapter::forConnection($pdo);
    echo "      ✓ Conexión establecida con éxito. Motor: {$dialect->driver()}\n\n";

    return [
        'driver' => $dialect->driver(),
        'verified' => initializePostgresDatabase($pdo, $dialect),
    ];
}

/**
 * Ruta única de despliegue del motor PostgreSQL: DDL base + `database/supabase_migrations/` +
 * semillas maestras del arranque en limpio + histórico de demostración (EARS-02.6).
 *
 * @return array<string, mixed> Resumen de la verificación.
 */
function initializePostgresDatabase(PDO $pdo, SqlDialectAdapter $dialect): array
{
    $sqlPath = __DIR__ . '/../database/supabase_init.sql';
    if (!file_exists($sqlPath)) {
        throw new RuntimeException("No se encontró el fichero {$sqlPath}");
    }

    echo "[2/6] Leyendo y parseando 'database/supabase_init.sql'...\n";
    $sqlContent = file_get_contents($sqlPath);
    if ($sqlContent === false) {
        throw new RuntimeException("Error al leer 'database/supabase_init.sql'");
    }

    $statements = SqlScriptSplitter::split($sqlContent);
    echo "      ✓ Total de sentencias detectadas: " . count($statements) . "\n\n";

    echo "[3/6] Ejecutando creación de tablas, blindajes y disparadores en PostgreSQL...\n";
    $count = 0;
    foreach ($statements as $stmt) {
        executeStatement($pdo, $stmt);
        $count++;

        $firstWord = strtoupper(strtok($stmt, " \t\n\r"));
        if ($firstWord === 'CREATE' && preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?("?)([a-zA-Z0-9_]+)\1/i', $stmt, $m)) {
            echo "      ✓ Tabla creada: {$m[2]}\n";
        }
    }
    echo "      ✓ {$count} sentencias ejecutadas correctamente.\n\n";

    echo "[4/6] Aplicando migraciones incrementales (database/supabase_migrations/)...\n";
    $runner = new PostgresMigrationRunner($pdo);
    $applied = $runner->runAll();

    if ($applied === []) {
        echo "      ✓ El esquema ya convergía: 0 migraciones pendientes.\n";
    }
    foreach ($applied as $filename) {
        echo "      ✓ Migración aplicada: {$filename}\n";
    }

    $runner->assertConverged();
    echo "      ✓ Esquema convergido: libros y migraciones del repositorio al día.\n\n";

    echo "[4b/6] Cargando el catálogo maestro del arranque en limpio (database/supabase_seeds.sql)...\n";
    $seedsPath = __DIR__ . '/../database/supabase_seeds.sql';
    if (!file_exists($seedsPath)) {
        throw new RuntimeException("No se encontró el fichero {$seedsPath}");
    }
    $seedsSql = file_get_contents($seedsPath);
    if ($seedsSql === false) {
        throw new RuntimeException("Error al leer 'database/supabase_seeds.sql'");
    }

    $seedCount = 0;
    foreach (SqlScriptSplitter::split($seedsSql) as $seedStatement) {
        try {
            executeStatement($pdo, $seedStatement);
            $seedCount++;
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Error en las semillas maestras del motor único: {$e->getMessage()}\nSentencia:\n"
                . substr($seedStatement, 0, 200) . '...',
                0,
                $e
            );
        }
    }
    echo "      ✓ {$seedCount} sentencias de semillas ejecutadas (catálogo maestro, sin incidentes ni historial: Fresh Start).\n\n";

    echo "[5/6] Verificando el esquema y el catálogo en el servidor...\n";
    $verified = verifyCatalog($pdo, $dialect);
    echo "      ✓ Sedes registradas: {$verified['locations']} (esperado >= 10)\n";
    echo "      ✓ Máquinas operativas: {$verified['machines']} (esperado >= 13)\n";
    echo "      ✓ Usuarios internos: {$verified['users']} (esperado >= 6)\n";
    echo "      ✓ Repuestos activos: {$verified['spare_parts']} (esperado >= 13)\n";
    echo "      ✓ Ítems de checklist preventivo: {$verified['preventive_order_items']} (esperado >= 0)\n";
    echo "      ✓ Columnas de reintegros verificadas: {$verified['refund_columns']}/3\n\n";

    assertCatalogVerified($verified);

    echo "[6/6] Histórico de averías de demostración...\n";

    require_once __DIR__ . '/../database/DemoHistorySeeder.php';

    if (!\VendGuard\Database\DemoHistorySeeder::enabledByEnvironment()) {
        echo '      · Histórico de demostración desactivado por '
            . \VendGuard\Database\DemoHistorySeeder::ENVIRONMENT_FLAG . ": el arranque se queda en limpio.\n";
        echo "      · Para cargarlo igualmente: php bin/seed_demo_history.php\n\n";

        return $verified;
    }

    $demoSummary = (new \VendGuard\Database\DemoHistorySeeder($pdo))->seed();
    echo "      ✓ Averías reafirmadas: {$demoSummary['incidents']}\n";
    echo '      ✓ Traza de estados: ' . $demoSummary['history_events'] . ' eventos nuevos · '
        . $demoSummary['history_realigned'] . " marcas desplazadas al esquema temporal vigente\n";
    echo '      ✓ Auditoría: ' . $demoSummary['audit_events'] . ' registros nuevos · '
        . $demoSummary['audit_realigned'] . " registros desplazados\n";

    if ($demoSummary['preserved'] !== []) {
        echo '      ⚠ ' . count($demoSummary['preserved']) . ' expediente(s) con traza ajena al fixture: no se han tocado ('
            . implode(', ', $demoSummary['preserved']) . ").\n";
    }

    if ($demoSummary['skipped'] !== []) {
        echo '      ⚠ ' . count($demoSummary['skipped']) . ' avería(s) en garantía sin sembrar: su máquina está ocupada por otro aviso vivo ('
            . implode(', ', $demoSummary['skipped']) . ").\n";
    }

    echo "\n";

    return $verified;
}

/**
 * Cuenta el catálogo base y comprueba las columnas en PostgreSQL.
 *
 * @return array{locations: int, machines: int, users: int, spare_parts: int, preventive_order_items: int, refund_columns: int}
 */
function verifyCatalog(PDO $pdo, SqlDialectAdapter $dialect): array
{
    $activeBoolean = $dialect->booleanLiteral(true);

    $locations = (int)$pdo->query("SELECT COUNT(*) FROM locations WHERE deleted_at IS NULL")->fetchColumn();
    $machines = (int)$pdo->query("SELECT COUNT(*) FROM machines WHERE deleted_at IS NULL")->fetchColumn();
    $users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();
    $spareParts = (int)$pdo->query("SELECT COUNT(*) FROM spare_parts WHERE is_active = {$activeBoolean}")->fetchColumn();
    $checklist = (int)$pdo->query('SELECT COUNT(*) FROM preventive_order_items')->fetchColumn();

    $refundColumns = (int)$pdo->query(
        "SELECT COUNT(*) FROM pg_attribute a
           JOIN pg_class c ON c.oid = a.attrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema()
            AND c.relname = 'refund_requests'
            AND a.attname IN ('paid_amount', 'pickup_attempts', 'pickup_locked_until')
            AND a.attnum > 0
            AND NOT a.attisdropped"
    )->fetchColumn();

    return [
        'locations' => $locations,
        'machines' => $machines,
        'users' => $users,
        'spare_parts' => $spareParts,
        'preventive_order_items' => $checklist,
        'refund_columns' => $refundColumns,
    ];
}

/**
 * Puerta de verificación: un esquema sin catálogo o sin las columnas del incidente
 * no se declara listo, y el arranque sale con error en vez de servir tráfico.
 *
 * @param array<string, mixed> $verified
 */
function assertCatalogVerified(array $verified): void
{
    $failures = [];

    if ((int)$verified['locations'] < 10) {
        $failures[] = "sedes insuficientes ({$verified['locations']} < 10)";
    }
    if ((int)$verified['machines'] < 13) {
        $failures[] = "máquinas insuficientes ({$verified['machines']} < 13)";
    }
    if ((int)$verified['users'] < 6) {
        $failures[] = "usuarios insuficientes ({$verified['users']} < 6)";
    }
    if ((int)$verified['spare_parts'] < 13) {
        $failures[] = "repuestos insuficientes ({$verified['spare_parts']} < 13)";
    }
    if ((int)$verified['refund_columns'] !== 3) {
        $failures[] = "columnas de reintegros verificadas {$verified['refund_columns']}/3";
    }

    if ($failures !== []) {
        throw new RuntimeException(
            'Esquema no verificado: ' . implode(' · ', $failures)
            . '. El arranque no deja el servicio sirviendo contra una base sin esquema comprobado (EARS-02.5).'
        );
    }
}

/**
 * Ejecuta una sentencia y descarta su posible conjunto de resultados.
 *
 * @param PDO $pdo
 * @param string $statement Sentencia SQL sin el `;` final.
 */
function executeStatement(PDO $pdo, string $statement): void
{
    // Por `exec()` y no por `query()`: el DDL base declara el operador JSONB `?`
    // (`signed_document ? 'version'`) y `query()` analiza el texto como sentencia
    // parametrizada; con prepares nativos lo reescribe como `$1` (42601).
    $pdo->exec($statement);
}
