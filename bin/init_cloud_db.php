<?php

declare(strict_types=1);

/**
 * VendGuard - Inicializador Automático de Base de Datos Cloud
 * 
 * Ejecuta el esquema DDL y los datos semilla en cualquier base de datos MySQL/MariaDB
 * (TiDB Cloud, Aiven, Alwaysdata, Docker local, etc.) dividiendo y ejecutando
 * sentencia por sentencia de forma segura y tolerante.
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
 * que hacer converger una base de datos ya existente.
 */

require_once __DIR__ . '/../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../src/Infrastructure/Database/SqlScriptSplitter.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SqlScriptSplitter;

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

try {
    echo "[1/6] Estableciendo conexión con el servidor MySQL/TiDB...\n";
    $pdo = ConnectionFactory::getConnection($config, true);
    echo "      ✓ Conexión establecida con éxito.\n\n";

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
            try {
                executeStatement($pdo, $migrationStatement);
            } catch (PDOException $e) {
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

    echo "[5/6] Verificando integridad de datos base en el servidor...\n";
    $locCount = (int)$pdo->query("SELECT COUNT(*) FROM locations WHERE deleted_at IS NULL")->fetchColumn();
    $machCount = (int)$pdo->query("SELECT COUNT(*) FROM machines WHERE deleted_at IS NULL")->fetchColumn();
    $usrCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();

    echo "      ✓ Sedes registradas: {$locCount}\n";
    echo "      ✓ Máquinas operativas: {$machCount}\n";
    echo "      ✓ Usuarios internos: {$usrCount}\n";

    // Las columnas que el listado de reintegros selecciona tienen que existir
    // aunque la tabla ya estuviera creada antes de que se declararan: son las
    // que provocaban el 500 del servidor desplegado (esperado: 3/3).
    $refundColumns = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE table_schema = DATABASE()
            AND table_name = 'refund_requests'
            AND column_name IN ('paid_amount', 'pickup_attempts', 'pickup_locked_until')"
    )->fetchColumn();
    echo "      ✓ Columnas de reintegros verificadas: {$refundColumns}/3\n\n";

    echo "[6/6] Sembrando histórico de averías, reparaciones y log de auditoría...\n";
    require_once __DIR__ . '/../database/DemoMetricsSeeder.php';
    $demoSeeder = new \VendGuard\Database\DemoMetricsSeeder($pdo);
    $demoSummary = $demoSeeder->seed();
    echo "      ✓ Averías y reparaciones sembradas: {$demoSummary['incidents']}\n";
    echo "      ✓ Registros de auditoría sembrados: {$demoSummary['audit_events']}\n\n";

    echo "======================================================================\n";
    echo " ¡ÉXITO! La base de datos ha sido inicializada y está 100% lista.\n";
    echo "======================================================================\n";
    exit(0);

} catch (Throwable $e) {
    echo "\n[ERROR FATAL]: " . $e->getMessage() . "\n";
    exit(1);
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


