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

    echo "[2/3] Ejecutando script DDL: database/schema.sql ...\n";
    $runner->runSqlFile($schemaFile);
    echo "      Script DDL ejecutado con éxito.\n";

    // Conectar a vendguard_db para verificar
    $pdo->exec("USE `vendguard_db`");

    $expectedTables = [
        'locations',
        'machines',
        'users',
        'incidents',
        'incident_history',
        'incident_comments'
    ];

    echo "[3/3] Verificando tablas creadas en vendguard_db:\n";
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

    echo "      - Columna virtual `is_active_ticket`: [" . ($hasVirtualCol ? "OK" : "FALTA") . "]\n";
    echo "      - Índice único `uq_machine_active_ticket`: [" . ($hasUniqueIndex ? "OK" : "FALTA") . "]\n\n";

    if ($allTablesExist && $hasVirtualCol && $hasUniqueIndex) {
        echo "========================================================\n";
        echo " Migración completada exitosamente. Condición T-02 CUMPLIDA.\n";
        echo "========================================================\n";
        exit(0);
    } else {
        echo "ERROR: Algunas tablas o índices no se crearon correctamente.\n";
        exit(1);
    }

} catch (Throwable $e) {
    echo "\n[ERROR FATAL]: " . $e->getMessage() . "\n";
    exit(1);
}
