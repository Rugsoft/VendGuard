<?php

declare(strict_types=1);

/**
 * VendGuard - Script CLI de Migración DDL
 * 
 * Uso: php bin/migrate.php
 */

require_once __DIR__ . '/../src/Infrastructure/Database/MigrationRunner.php';

use VendGuard\Infrastructure\Database\MigrationRunner;

echo "========================================================\n";
echo " VendGuard: Iniciando migración DDL en MariaDB/MySQL\n";
echo "========================================================\n\n";

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';

try {
    // Conexión inicial al servidor sin base de datos específica para permitir crearla si no existe
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    echo "[1/3] Conexión establecida con MariaDB en {$host}:{$port}\n";

    $runner = new MigrationRunner($pdo);
    $schemaFile = __DIR__ . '/../database/schema.sql';

    echo "[2/4] Ejecutando script DDL base: database/schema.sql ...\n";
    $runner->runSqlFile($schemaFile);
    echo "      Script DDL ejecutado con éxito.\n";

    // Conectar a vendguard_db
    $pdo->exec("USE `vendguard_db`");

    // Ejecutar migraciones incrementales en database/migrations/
    echo "[3/4] Ejecutando migraciones incrementales (database/migrations/) ...\n";
    $migrationsDir = __DIR__ . '/../database/migrations';
    if (is_dir($migrationsDir)) {
        $migrationFiles = glob($migrationsDir . '/*.sql');
        sort($migrationFiles);
        foreach ($migrationFiles as $mFile) {
            $baseName = basename($mFile);
            echo "      - Aplicando migración: {$baseName} ...\n";
            $runner->runSqlFile($mFile);
        }
    }

    $expectedTables = [
        'locations',
        'machines',
        'users',
        'incidents',
        'incident_history',
        'incident_comments',
        'audit_log'
    ];

    echo "[4/4] Verificando esquema e integridad en vendguard_db:\n";
    $allTablesExist = true;

    foreach ($expectedTables as $table) {
        $exists = $runner->tableExists($table);
        $status = $exists ? "OK" : "FALTA";
        echo "      - Tabla `{$table}`: [{$status}]\n";
        if (!$exists) {
            $allTablesExist = false;
        }
    }

    // Verificar la columna virtual y el índice único condicional
    $stmt = $pdo->query("SHOW COLUMNS FROM `incidents` LIKE 'is_active_ticket'");
    $col = $stmt->fetch();
    $hasVirtualCol = ($col !== false);

    $stmt = $pdo->query("SHOW INDEX FROM `incidents` WHERE Key_name = 'uq_machine_active_ticket'");
    $idx = $stmt->fetch();
    $hasUniqueIndex = ($idx !== false);

    // Verificar columna machine_type_snapshot (Módulo 04 / Art. II)
    $stmt = $pdo->query("SHOW COLUMNS FROM `incidents` LIKE 'machine_type_snapshot'");
    $colSnapshot = $stmt->fetch();
    $hasSnapshotCol = ($colSnapshot !== false);

    // Verificar enum USER en audit_log (Módulo 04 / Art. III y V)
    $stmt = $pdo->query("SHOW COLUMNS FROM `audit_log` LIKE 'entity_type'");
    $colAuditEnum = $stmt->fetch();
    $hasUserEnum = ($colAuditEnum !== false && str_contains((string)($colAuditEnum['Type'] ?? ''), "'USER'"));

    echo "      - Columna virtual `is_active_ticket`: [" . ($hasVirtualCol ? "OK" : "FALTA") . "]\n";
    echo "      - Índice único `uq_machine_active_ticket`: [" . ($hasUniqueIndex ? "OK" : "FALTA") . "]\n";
    echo "      - Columna `machine_type_snapshot` en incidents: [" . ($hasSnapshotCol ? "OK" : "FALTA") . "]\n";
    echo "      - Soporte entidad `USER` en audit_log: [" . ($hasUserEnum ? "OK" : "FALTA") . "]\n\n";

    if ($allTablesExist && $hasVirtualCol && $hasUniqueIndex && $hasSnapshotCol && $hasUserEnum) {
        echo "========================================================\n";
        echo " Migración completada exitosamente. Condición T-ADM-01 CUMPLIDA.\n";
        echo "========================================================\n";
        exit(0);
    } else {
        echo "ERROR: Algunas tablas, columnas o índices no se crearon correctamente.\n";
        exit(1);
    }

} catch (Throwable $e) {
    echo "\n[ERROR FATAL]: " . $e->getMessage() . "\n";
    exit(1);
}
