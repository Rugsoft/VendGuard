<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * MigrationRunner
 * 
 * Ejecuta scripts SQL de migración directamente en el motor MariaDB/MySQL mediante PDO.
 * Respeta el Dogma Vanilla sin depender de librerías externas.
 */
class MigrationRunner
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Ejecuta un fichero SQL de migración.
     *
     * @param string $sqlFilePath Ruta absoluta al fichero SQL.
     * @return bool True si la ejecución fue exitosa.
     * @throws RuntimeException Si el fichero no existe o falla la ejecución.
     */
    public function runSqlFile(string $sqlFilePath): bool
    {
        if (!file_exists($sqlFilePath)) {
            throw new RuntimeException("El fichero de migración no existe: {$sqlFilePath}");
        }

        $sqlContent = file_get_contents($sqlFilePath);
        if ($sqlContent === false) {
            throw new RuntimeException("Error al leer el fichero de migración: {$sqlFilePath}");
        }

        try {
            // Ejecutar el script SQL completo
            $this->pdo->exec($sqlContent);
            return true;
        } catch (PDOException $e) {
            throw new RuntimeException("Error ejecutando migración SQL: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Comprueba si una tabla específica existe en la base de datos actual.
     *
     * @param string $tableName Nombre de la tabla a verificar.
     * @return bool
     */
    public function tableExists(string $tableName): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
              AND table_name = :tableName
        ");
        $stmt->execute([':tableName' => $tableName]);
        return ((int)$stmt->fetchColumn()) > 0;
    }
}
