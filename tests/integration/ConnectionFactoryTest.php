<?php

declare(strict_types=1);

/**
 * ConnectionFactoryTest
 * 
 * Verificación técnica de la factoría PDO de VendGuard.
 * Valida la condición 'Hecho cuando:' de la tarea T-03.
 */

require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;

echo "========================================================\n";
echo " VendGuard: Ejecutando verificación de ConnectionFactory (T-03)\n";
echo "========================================================\n\n";

try {
    // 1. Obtener conexión
    $pdo = ConnectionFactory::getConnection();

    // 2. Comprobar que es instancia de PDO
    $isPdo = ($pdo instanceof PDO);
    echo "1. Instancia devuelta es PDO: " . ($isPdo ? "[OK]" : "[FALLO]") . "\n";
    if (!$isPdo) {
        throw new RuntimeException("El objeto devuelto no es una instancia de PDO.");
    }

    // 3. Comprobar modo de error (ERRMODE_EXCEPTION)
    $errmode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
    $isExceptionMode = ($errmode === PDO::ERRMODE_EXCEPTION);
    echo "2. Modo de error es ERRMODE_EXCEPTION: " . ($isExceptionMode ? "[OK]" : "[FALLO]") . "\n";
    if (!$isExceptionMode) {
        throw new RuntimeException("El modo de error no está configurado como ERRMODE_EXCEPTION.");
    }

    // 4. Comprobar que ATTR_EMULATE_PREPARES está desactivado (false)
    $emulatePrepares = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
    $isEmulateDisabled = ($emulatePrepares === false || $emulatePrepares === 0);
    echo "3. ATTR_EMULATE_PREPARES desactivado (false): " . ($isEmulateDisabled ? "[OK]" : "[FALLO]") . "\n";
    if (!$isEmulateDisabled) {
        throw new RuntimeException("La emulación de prepares no está desactivada.");
    }

    // 5. Comprobar codificación UTF-8 (utf8mb4)
    $stmt = $pdo->query("SHOW VARIABLES LIKE 'character_set_connection'");
    $var = $stmt->fetch();
    $charset = $var['Value'] ?? '';
    $isUtf8mb4 = (str_starts_with($charset, 'utf8mb4'));
    echo "4. Codificación activa es utf8mb4 ({$charset}): " . ($isUtf8mb4 ? "[OK]" : "[FALLO]") . "\n";
    if (!$isUtf8mb4) {
        throw new RuntimeException("El charset de conexión no es utf8mb4.");
    }

    // 6. Comprobar base de datos seleccionada
    $dbStmt = $pdo->query("SELECT DATABASE()");
    $currentDb = $dbStmt->fetchColumn();
    $isCorrectDb = ($currentDb === 'vendguard_db');
    echo "5. Base de datos activa es 'vendguard_db': " . ($isCorrectDb ? "[OK]" : "[FALLO]") . "\n\n";
    if (!$isCorrectDb) {
        throw new RuntimeException("La base de datos seleccionada no es vendguard_db.");
    }

    echo "========================================================\n";
    echo " TODAS LAS CONDICIONES DE T-03 CUMPLIDAS CON ÉXITO.\n";
    echo "========================================================\n";
    exit(0);

} catch (Throwable $e) {
    echo "\n[ERROR]: " . $e->getMessage() . "\n";
    exit(1);
}
