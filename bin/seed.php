<?php

declare(strict_types=1);

/**
 * VendGuard - Script CLI de Carga de Datos Semilla (Seed Data)
 * 
 * Uso: php bin/seed.php
 */

require_once __DIR__ . '/../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;

echo "========================================================\n";
echo " VendGuard: Iniciando carga de Seed Data en MariaDB\n";
echo "========================================================\n\n";

try {
    $pdo = ConnectionFactory::getConnection();
    echo "[1/3] Conexión PDO obtenida con éxito.\n";

    $runner = new SeedRunner($pdo);
    echo "[2/3] Ejecutando inserción de datos semilla...\n";
    $summary = $runner->seedAll('Password123!');

    echo "      - Sedes cargadas/actualizadas: {$summary['locations']}\n";
    echo "      - Máquinas cargadas/actualizadas: {$summary['machines']}\n";
    echo "      - Usuarios cargados/actualizados: {$summary['users']}\n";

    echo "[3/3] Verificando datos en la base de datos:\n";

    // 1. Sedes
    $stmt = $pdo->query("SELECT site_code, name FROM locations WHERE deleted_at IS NULL ORDER BY id ASC");
    $locations = $stmt->fetchAll();
    echo "      * Sedes encontradas (" . count($locations) . "):\n";
    foreach ($locations as $loc) {
        echo "        - [{$loc['site_code']}] {$loc['name']}\n";
    }

    // 2. Máquinas
    $stmt = $pdo->query("SELECT code, machine_type, model FROM machines WHERE deleted_at IS NULL ORDER BY id ASC");
    $machines = $stmt->fetchAll();
    echo "      * Máquinas encontradas (" . count($machines) . "):\n";
    $hasPerishable = false;
    foreach ($machines as $mach) {
        echo "        - [{$mach['code']}] Tipo: {$mach['machine_type']} ({$mach['model']})\n";
        if ($mach['machine_type'] === 'PERISHABLE_FOOD') {
            $hasPerishable = true;
        }
    }

    // 3. Usuarios
    $stmt = $pdo->query("SELECT email, role, password_hash FROM users WHERE deleted_at IS NULL ORDER BY id ASC");
    $users = $stmt->fetchAll();
    echo "      * Usuarios encontrados (" . count($users) . "):\n";
    $allPasswordsValid = true;
    foreach ($users as $user) {
        $valid = password_verify('Password123!', $user['password_hash']);
        $status = $valid ? 'OK (Bcrypt válido)' : 'ERROR (Hash inválido)';
        echo "        - {$user['email']} [{$user['role']}] -> Password 'Password123!': {$status}\n";
        if (!$valid) {
            $allPasswordsValid = false;
        }
    }

    if (count($locations) >= 2 && count($machines) >= 3 && $hasPerishable && count($users) >= 2 && $allPasswordsValid) {
        echo "\n========================================================\n";
        echo " Carga de Seed Data completada. Condición T-04 CUMPLIDA.\n";
        echo "========================================================\n";
        exit(0);
    } else {
        echo "\nERROR: No se cumplieron todas las condiciones de verificación de T-04.\n";
        exit(1);
    }

} catch (Throwable $e) {
    echo "\n[ERROR FATAL]: " . $e->getMessage() . "\n";
    exit(1);
}
