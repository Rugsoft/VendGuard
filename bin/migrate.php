<?php

declare(strict_types=1);

/**
 * VendGuard - Script CLI de Migración DDL del motor único (PostgreSQL / Supabase)
 * (T-13-14)
 * 
 * Uso: php bin/migrate.php
 */

require_once __DIR__ . '/../src/Infrastructure/Config/SecretProvider.php';
require_once __DIR__ . '/../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PostgresMigrationRunner;

echo "========================================================\n";
echo " VendGuard: Migración incremental DDL en PostgreSQL\n";
echo "========================================================\n\n";

try {
    $pdo = ConnectionFactory::getConnection();

    echo "[1/3] Conexión establecida con el motor PostgreSQL (Supabase)\n";

    $runner = new PostgresMigrationRunner($pdo);

    echo "[2/3] Aplicando migraciones incrementales pendientes...\n";
    $applied = $runner->runAll();

    if ($applied === []) {
        echo "      ✓ El esquema ya convergía: 0 migraciones pendientes.\n";
    } else {
        foreach ($applied as $migrationFile) {
            echo "      ✓ Migración aplicada: {$migrationFile}\n";
        }
    }

    echo "[3/3] Verificando convergencia completa del esquema...\n";
    $runner->assertConverged();
    echo "      ✓ Esquema 100% convergido según libro schema_migrations (EARS-02.4).\n\n";

    echo "========================================================\n";
    echo " Migración completada exitosamente.\n";
    echo "========================================================\n";
    exit(0);
} catch (Throwable $e) {
    echo "\n[ERROR FATAL]: " . $e->getMessage() . "\n";
    exit(1);
}
