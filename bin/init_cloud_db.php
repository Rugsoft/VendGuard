<?php

declare(strict_types=1);

/**
 * VendGuard - Inicializador Automático de Base de Datos Cloud
 *
 * Ejecuta el esquema DDL, las migraciones del motor y los datos semilla en la base de
 * datos configurada, dividiendo y ejecutando sentencia por sentencia de forma segura y
 * tolerante. Autodetermina el motor por la conexión: MySQL/MariaDB (TiDB Cloud, Aiven,
 * Alwaysdata, Docker local, ...) o PostgreSQL (Supabase, el motor nuevo).
 *
 * Uso:
 *   1. Desde CLI con variables de entorno:
 *      DB_HOST=xxx DB_USER=yyy php bin/init_cloud_db.php
 *   2. Desde CLI con argumentos:
 *      php bin/init_cloud_db.php --host=gateway... --user=xxx --password="yyy" --port=4000 --name=test --ssl=1
 *   3. En arranque de contenedor Docker (Render):
 *      php bin/init_cloud_db.php
 *
 * ## Por qué además del DDL base se aplican las migraciones
 * `database/cloud_init.sql` crea las tablas con `CREATE TABLE IF NOT EXISTS`,
 * así que solo puede definir el esquema de una base de datos que todavía no
 * existe. Una columna añadida por una migración posterior no llegaba nunca al
 * servidor desplegado: la tabla ya estaba creada y el `CREATE TABLE` no la
 * tocaba. Con el listado de reintegros pasó exactamente eso y la API respondía
 * 500 (`Unknown column`) contra el esquema antiguo, mientras en local -- donde
 * sí se ejecutan las migraciones -- todo funcionaba. Este script es el único
 * que corre en el arranque del contenedor, de modo que también es el que tiene
 * que hacer converger una base de datos ya existente. El motor nuevo tiene su
 * propio conjunto (`database/supabase_migrations/`, vía
 * `PostgresMigrationRunner`) por el mismo motivo y con la misma consecuencia:
 * un esquema que solo se crea se queda congelado en su creación.
 *
 * ## Por qué reintenta y por qué no arranca sin verificar
 * EARS-02.5: si el motor no está disponible en el arranque (proyecto suspendido
 * o red caída) el inicializador reintenta con espera progresiva un mínimo de
 * tres veces y **no deja el servicio sirviendo con un esquema no verificado**.
 * El código de salida es la puerta: `0` significa «esquema aplicado y
 * comprobado», y cualquier otra cosa significa que el servidor web no debe
 * arrancar contra esa base.
 */

// Este arranque no carga el autoloader: es lo primero que corre en el contenedor, así
// que cada clase que su cadena toca tiene que declararse aquí de forma explícita.
// `ConnectionFactory` resuelve el fallo en cerrado de producción con `SecretProvider`
// (hallazgo S-5) y `SeedRunner` hashea las claves de centro con `SiteAccessCodeGenerator`
// (hallazgo S-4): omitir una sola cambia un despliegue sano por un
// «Class ... not found» en el paso 1/6, antes incluso de tocar el esquema. `SeedRunner`
// resuelve su dialecto con `SqlDialectAdapter` (T-HYBRID-25) y el motor nuevo converge
// con `PostgresMigrationRunner` (T-HYBRID-23), que trocea con `SqlScriptSplitter`: la
// cadena entera tiene que estar declarada aquí.
require_once __DIR__ . '/../src/Infrastructure/Config/SecretProvider.php';
require_once __DIR__ . '/../src/Core/Domain/Service/SiteAccessCodeGenerator.php';
require_once __DIR__ . '/../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../src/Infrastructure/Dialect/SqlDialectAdapter.php';
require_once __DIR__ . '/../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../src/Infrastructure/Database/SeedRunner.php';
require_once __DIR__ . '/../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PostgresMigrationRunner;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Database\SqlScriptSplitter;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

echo "======================================================================\n";
echo " VendGuard: Inicializador Automático de Base de Datos Cloud\n";
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

// 2. Arranque con reintento y espera progresiva (EARS-02.5). La salida del proceso
//    es la puerta: nada queda sirviendo contra un esquema sin verificar.
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
 * Inicializa la base de datos del motor activo: DDL base, migraciones del motor,
 * semillas y verificación del esquema.
 *
 * @param array<string, mixed> $config Configuración explícita de conexión.
 * @return array{driver: string, verified: array<string, mixed>}
 */
function initializeDatabase(array $config): array
{
    echo "[1/6] Estableciendo conexión con el servidor de base de datos...\n";
    $pdo = ConnectionFactory::getConnection($config, true);
    $dialect = SqlDialectAdapter::forConnection($pdo);
    echo "      ✓ Conexión establecida con éxito. Motor detectado: {$dialect->driver()}\n\n";

    if ($dialect->isPostgres()) {
        return [
            'driver' => $dialect->driver(),
            'verified' => initializePostgresDatabase($pdo, $dialect),
        ];
    }

    return [
        'driver' => $dialect->driver(),
        'verified' => initializeMysqlDatabase($pdo, $dialect),
    ];
}

/**
 * Ruta de despliegue del motor local: DDL base + `database/migrations/` + semillas
 * del catalogo y del arnes.
 *
 * @return array<string, mixed> Resumen de la verificacion.
 */
function initializeMysqlDatabase(PDO $pdo, SqlDialectAdapter $dialect): array
{
    $sqlPath = __DIR__ . '/../database/cloud_init.sql';
    if (!file_exists($sqlPath)) {
        throw new RuntimeException("No se encontró el fichero {$sqlPath}");
    }

    echo "[2/6] Leyendo y parseando 'database/cloud_init.sql'...\n";
    $sqlContent = file_get_contents($sqlPath);
    if ($sqlContent === false) {
        throw new RuntimeException("Error al leer 'database/cloud_init.sql'");
    }

    $statements = SqlScriptSplitter::split($sqlContent);
    echo "      ✓ Total de sentencias detectadas: " . count($statements) . "\n\n";

    echo "[3/6] Ejecutando creación de tablas y configuración de índices...\n";
    $count = 0;
    foreach ($statements as $stmt) {
        $firstWord = strtoupper(strtok($stmt, " \t\n\r"));
        try {
            executeStatement($pdo, $stmt);
            $count++;

            // Log amigable de eventos clave
            if ($firstWord === 'CREATE') {
                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $stmt, $m)) {
                    echo "      ✓ Tabla creada: {$m[1]}\n";
                }
            } elseif ($firstWord === 'INSERT') {
                if (preg_match('/INSERT\s+INTO\s+`?([a-zA-Z0-9_]+)`?/i', $stmt, $m)) {
                    echo "      ✓ Semillas insertadas en: {$m[1]}\n";
                }
            }
        } catch (PDOException $e) {
            // Tolerar sentencias DROP de tablas que no existen
            if ($firstWord === 'DROP' && str_contains($e->getMessage(), "Unknown table")) {
                continue;
            }
            throw new RuntimeException("Error en sentencia SQL: {$e->getMessage()}\nSentencia:\n" . substr($stmt, 0, 200) . "...");
        }
    }
    echo "\n      ✓ {$count} sentencias ejecutadas correctamente.\n\n";

    echo "[4/6] Aplicando migraciones incrementales (database/migrations/)...\n";
    $migrationFiles = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
    sort($migrationFiles);
    if ($migrationFiles === []) {
        throw new RuntimeException("No se encontraron migraciones en database/migrations/");
    }

    foreach ($migrationFiles as $migrationFile) {
        $migrationName = basename($migrationFile);
        $migrationSql = file_get_contents($migrationFile);
        if ($migrationSql === false) {
            throw new RuntimeException("Error al leer el fichero de migración {$migrationName}");
        }

        // Sentencia a sentencia, igual que el DDL base. Enviar el fichero entero
        // a PDO::exec() depende del protocolo de sentencias múltiples, que no es
        // universal (`1295 This command is not supported in the prepared
        // statement protocol yet`), y además permite nombrar en el error la
        // migración que falló.
        foreach (SqlScriptSplitter::split($migrationSql) as $migrationStatement) {
            $stmtUpper = strtoupper(ltrim($migrationStatement));
            $isTriggerStmt = str_starts_with($stmtUpper, 'CREATE TRIGGER') || str_starts_with($stmtUpper, 'DROP TRIGGER');
            try {
                executeStatement($pdo, $migrationStatement);
            } catch (PDOException $e) {
                // TiDB Serverless y ciertos motores cloud no implementan triggers
                // ("check the manual that corresponds to your TiDB version ... near TRIGGER").
                // En MySQL y MariaDB se ejecutan de forma obligatoria.
                if ($isTriggerStmt && (str_contains(strtolower($e->getMessage()), 'tidb') || str_contains($e->getMessage(), 'not supported'))) {
                    echo "      ⚠ Triggers no soportados por el motor de base de datos cloud (TiDB): ignorando trigger.\n";
                    continue;
                }
                throw new RuntimeException(
                    "Error en la migración {$migrationName}: {$e->getMessage()}\nSentencia:\n"
                    . substr($migrationStatement, 0, 200) . '...',
                    0,
                    $e
                );
            }
        }

        echo "      ✓ Migración aplicada: {$migrationName}\n";
    }
    echo "\n";

    echo "[4b/6] Asegurando catálogo ampliado de sedes, máquinas, técnicos y repuestos...\n";
    $seedRunner = new SeedRunner($pdo);
    $seedSummary = $seedRunner->seedAll('Password123!');
    echo "      ✓ Sedes procesadas: {$seedSummary['locations']}\n";
    echo "      ✓ Máquinas procesadas: {$seedSummary['machines']}\n";
    echo "      ✓ Usuarios procesados: {$seedSummary['users']}\n";
    echo "      ✓ Checklists preventivos procesados: {$seedSummary['preventive_checklists']} órdenes\n";
    echo "      ✓ Eventos de auditoría preventiva procesados: {$seedSummary['preventive_audit_events']}\n";
    echo "      ✓ Repuestos procesados: {$seedSummary['spare_parts']} (compatibilidades: {$seedSummary['spare_part_compatibilities']})\n\n";

    echo "[5/6] Verificando integridad de datos base en el servidor...\n";
    $verified = verifyCatalog($pdo, $dialect);
    echo "      ✓ Sedes registradas: {$verified['locations']} (esperado >= 10)\n";
    echo "      ✓ Máquinas operativas: {$verified['machines']} (esperado >= 13)\n";
    echo "      ✓ Usuarios internos: {$verified['users']} (esperado >= 6)\n";
    echo "      ✓ Repuestos activos: {$verified['spare_parts']} (esperado >= 13)\n";
    echo "      ✓ Ítems de checklist preventivo: {$verified['preventive_order_items']} (esperado >= 14)\n";

    // Las columnas que el listado de reintegros selecciona tienen que existir
    // aunque la tabla ya estuviera creada antes de que se declararan: son las
    // que provocaban el 500 del servidor desplegado (esperado: 3/3).
    echo "      ✓ Columnas de reintegros verificadas: {$verified['refund_columns']}/3\n\n";

    assertCatalogVerified($verified);

    echo "[6/6] Sembrando histórico de averías, reparaciones y log de auditoría...\n";
    require_once __DIR__ . '/../database/DemoMetricsSeeder.php';
    $demoSeeder = new \VendGuard\Database\DemoMetricsSeeder($pdo);
    $demoSummary = $demoSeeder->seed();
    echo "      ✓ Averías y reparaciones sembradas: {$demoSummary['incidents']}\n";
    echo "      ✓ Registros de auditoría sembrados: {$demoSummary['audit_events']}\n\n";

    return $verified;
}

/**
 * Ruta de despliegue del motor nuevo: DDL base + `database/supabase_migrations/` +
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

    echo "[3/6] Ejecutando creación de tablas, blindajes y disparadores del motor nuevo...\n";
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

    // La convergencia es una puerta, no un deseo: sin todas las migraciones del
    // repositorio aplicadas, el esquema no está verificado (EARS-02.4).
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
                "Error en las semillas maestras del motor nuevo: {$e->getMessage()}\nSentencia:\n"
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

    // El histórico de demostración entra en **cada arranque** (EARS-02.6): es lo que hace que
    // un redespliegue deje las diecisiete averías en su sitio sin depender de que nadie recuerde
    // una variable. La puerta es *opt-out* —`VENDGUARD_DEMO_HISTORY=0` devuelve el arranque en
    // limpio estricto de la especificación— y la regla vive en el propio sembrador, para que su
    // suite la ejerza. La reafirmación de las marcas y la guardia de forma que la acompaña son
    // suyas también: aquí sólo se informa de lo que devolvió.
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
 * Cuenta el catálogo base y comprueba las columnas que el despliegue rompió, en la
 * forma de cada motor: la sonda no puede ser privativa de uno de los dos.
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

    $schema = $dialect->isPostgres() ? 'current_schema()' : 'DATABASE()';
    $refundColumns = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE table_schema = {$schema}
            AND table_name = 'refund_requests'
            AND column_name IN ('paid_amount', 'pickup_attempts', 'pickup_locked_until')"
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
 * Puerta de verificación: un esquema sin catálogo o sin las columnas del incidente del
 * 2026-10-03 no se declara listo, y el arranque sale con error en vez de servir tráfico.
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
 * `EXECUTE` de una sentencia preparada que resuelve `SELECT 1` -- el patrón con
 * el que las migraciones comprueban si hay algo que cambiar -- deja un resultado
 * sin leer, y la siguiente consulta de la misma conexión falla con «2014 Cannot
 * execute queries while other unbuffered queries are active». Cerrar el cursor
 * es obligatorio para poder encadenar sentencias, no una precaución.
 *
 * @param PDO $pdo
 * @param string $statement Sentencia SQL sin el `;` final.
 */
function executeStatement(PDO $pdo, string $statement): void
{
    $result = $pdo->query($statement);
    if ($result instanceof PDOStatement) {
        $result->closeCursor();
    }
}
