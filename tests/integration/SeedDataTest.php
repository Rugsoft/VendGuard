<?php

declare(strict_types=1);

/**
 * SeedDataTest
 * 
 * Test de Integración para validar la carga de datos semilla (Tarea T-04).
 * Valida la existencia de sedes, máquinas, tipos críticos de alimentos y contraseñas Bcrypt.
 */

require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;

echo "========================================================\n";
echo " VendGuard: Verificación de Datos Semilla (T-04)\n";
echo "========================================================\n\n";

$pdo = ConnectionFactory::getConnection();
$runner = new SeedRunner($pdo);

// Ejecutar o asegurar carga de semillas
$runner->seedAll('Password123!');

$failures = 0;

// 1. Verificar Sedes (al menos SEDE-BCN-01 y SEDE-BCN-02)
$stmt = $pdo->prepare("SELECT * FROM `locations` WHERE `site_code` IN ('SEDE-BCN-01', 'SEDE-BCN-02') AND `deleted_at` IS NULL");
$stmt->execute();
$locations = $stmt->fetchAll();

if (count($locations) === 2) {
    echo "1. Sedes SEDE-BCN-01 y SEDE-BCN-02 presentes: [OK]\n";
} else {
    echo "1. Sedes esperadas no encontradas: [FALLO] (Encontradas: " . count($locations) . ")\n";
    $failures++;
}

// 2. Verificar Máquinas (al menos 3 máquinas, incluyendo PERISHABLE_FOOD)
$stmt = $pdo->prepare("SELECT * FROM `machines` WHERE `code` IN ('VEND-0101', 'VEND-0102', 'VEND-0201') AND `deleted_at` IS NULL");
$stmt->execute();
$machines = $stmt->fetchAll();

$hasPerishable = false;
foreach ($machines as $m) {
    if ($m['code'] === 'VEND-0101' && $m['machine_type'] === 'PERISHABLE_FOOD') {
        $hasPerishable = true;
    }
}

if (count($machines) >= 3) {
    echo "2. Total de máquinas de prueba requeridas (>= 3): [OK]\n";
} else {
    echo "2. Máquinas insuficientes: [FALLO] (Encontradas: " . count($machines) . ")\n";
    $failures++;
}

if ($hasPerishable) {
    echo "3. Máquina VEND-0101 configurada con PERISHABLE_FOOD: [OK]\n";
} else {
    echo "3. Máquina PERISHABLE_FOOD no encontrada: [FALLO]\n";
    $failures++;
}

// 3. Verificar Usuarios de Prueba y Contraseñas Bcrypt
$testUsers = [
    'coordinacion@vendguard.internal' => 'COORDINATOR',
    'jordi.ruta@vendguard.internal' => 'TECHNICIAN'
];

foreach ($testUsers as $email => $expectedRole) {
    $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `email` = :email AND `deleted_at` IS NULL");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        echo "4. Usuario {$email}: [FALLO] (No existe)\n";
        $failures++;
        continue;
    }

    if ($user['role'] !== $expectedRole) {
        echo "4. Rol de {$email}: [FALLO] (Esperado: {$expectedRole}, Actual: {$user['role']})\n";
        $failures++;
    } else {
        echo "4. Rol de {$email} ({$expectedRole}): [OK]\n";
    }

    $isPasswordValid = password_verify('Password123!', $user['password_hash']);
    if ($isPasswordValid) {
        echo "5. Verificación de hash Bcrypt para {$email}: [OK]\n";
    } else {
        echo "5. Verificación de hash Bcrypt para {$email}: [FALLO]\n";
        $failures++;
    }
}

// 4. Verificar Idempotencia (re-ejecutar sin errores)
try {
    $runner->seedAll('Password123!');
    echo "6. Idempotencia de semillas (segunda ejecución sin errores): [OK]\n";
} catch (Throwable $e) {
    echo "6. Idempotencia de semillas fallida: [FALLO] " . $e->getMessage() . "\n";
    $failures++;
}

echo "\n========================================================\n";
if ($failures === 0) {
    echo " TODAS LAS CONDICIONES DE T-04 CUMPLIDAS CON ÉXITO.\n";
    echo "========================================================\n";
    exit(0);
} else {
    echo " ERROR: Se detectaron {$failures} fallos en la verificación de T-04.\n";
    echo "========================================================\n";
    exit(1);
}
