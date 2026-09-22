<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * ConnectionFactory
 * 
 * Factoría para la creación y gestión de conexiones seguras PDO a MariaDB/MySQL.
 * Garantiza modo de excepciones estricto, desactivación de emulación de prepares
 * y codificación completa UTF-8 (utf8mb4).
 * 
 * Cumple con Dogma Vanilla (sin dependencias externas) y Clean Architecture.
 */
class ConnectionFactory
{
    private static ?PDO $instance = null;

    /**
     * Obtiene una conexión PDO configurada.
     *
     * @param array<string, mixed> $customConfig Configuración opcional para sobrescribir variables de entorno.
     * @param bool $fresh Si es true, fuerza la creación de una nueva instancia en lugar de reutilizar la existente.
     * @return PDO
     * @throws RuntimeException Si la conexión a la base de datos falla.
     */
    public static function getConnection(array $customConfig = [], bool $fresh = false): PDO
    {
        if (self::$instance !== null && !$fresh && empty($customConfig)) {
            return self::$instance;
        }

        $host = $customConfig['host'] ?? (getenv('DB_HOST') ?: '127.0.0.1');
        $port = (string)($customConfig['port'] ?? (getenv('DB_PORT') ?: '3306'));
        $dbname = $customConfig['dbname'] ?? (getenv('DB_NAME') ?: 'vendguard_db');
        $charset = $customConfig['charset'] ?? 'utf8mb4';
        $user = $customConfig['user'] ?? (getenv('DB_USER') ?: 'root');
        $password = $customConfig['password'] ?? (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
        ];

        try {
            $pdo = new PDO($dsn, $user, $password, $options);

            if (!$fresh && empty($customConfig)) {
                self::$instance = $pdo;
            }

            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException("Fallo de conexión a la base de datos: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Restablece la instancia en caché (útil para suites de test).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
