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
 */

require_once __DIR__ . '/../src/Infrastructure/Database/ConnectionFactory.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;

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
    echo "[1/4] Estableciendo conexión con el servidor MySQL/TiDB...\n";
    $pdo = ConnectionFactory::getConnection($config, true);
    echo "      ✓ Conexión establecida con éxito.\n\n";

    $sqlPath = __DIR__ . '/../database/cloud_init.sql';
    if (!file_exists($sqlPath)) {
        throw new RuntimeException("No se encontró el fichero {$sqlPath}");
    }

    echo "[2/4] Leyendo y parseando 'database/cloud_init.sql'...\n";
    $sqlContent = file_get_contents($sqlPath);
    if ($sqlContent === false) {
        throw new RuntimeException("Error al leer 'database/cloud_init.sql'");
    }

    $statements = splitSqlStatements($sqlContent);
    echo "      ✓ Total de sentencias detectadas: " . count($statements) . "\n\n";

    echo "[3/4] Ejecutando creación de tablas y configuración de índices...\n";
    $count = 0;
    foreach ($statements as $stmt) {
        $firstWord = strtoupper(strtok($stmt, " \t\n\r"));
        try {
            $pdo->exec($stmt);
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

    echo "[4/4] Verificando integridad de datos en el servidor...\n";
    $locCount = (int)$pdo->query("SELECT COUNT(*) FROM locations WHERE deleted_at IS NULL")->fetchColumn();
    $machCount = (int)$pdo->query("SELECT COUNT(*) FROM machines WHERE deleted_at IS NULL")->fetchColumn();
    $usrCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();

    echo "      ✓ Sedes registradas: {$locCount}\n";
    echo "      ✓ Máquinas operativas: {$machCount}\n";
    echo "      ✓ Usuarios internos: {$usrCount}\n\n";

    echo "======================================================================\n";
    echo " ¡ÉXITO! La base de datos ha sido inicializada y está 100% lista.\n";
    echo "======================================================================\n";
    exit(0);

} catch (Throwable $e) {
    echo "\n[ERROR FATAL]: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Divide un script SQL en sentencias individuales respetando cadenas entre comillas.
 *
 * @param string $sql
 * @return string[]
 */
function splitSqlStatements(string $sql): array
{
    // Eliminar comentarios de bloque /* ... */
    $sql = (string)preg_replace('!/\*.*?\*/!s', '', $sql);

    $statements = [];
    $cleanedLines = [];

    $lines = explode("\n", $sql);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        // Filtrar comentarios de línea completa si empiezan con -- o #
        if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $cleanedLines[] = $line;
    }

    $raw = implode("\n", $cleanedLines);
    $len = strlen($raw);
    $current = '';
    $inString = false;
    $quoteChar = '';

    for ($i = 0; $i < $len; $i++) {
        $char = $raw[$i];

        // Detección de cadenas entre comillas simples, dobles o backticks
        if (($char === "'" || $char === '"' || $char === '`') && ($i === 0 || $raw[$i - 1] !== '\\')) {
            if ($inString && $char === $quoteChar) {
                $inString = false;
                $quoteChar = '';
            } elseif (!$inString) {
                $inString = true;
                $quoteChar = $char;
            }
        }

        // Si encontramos un punto y coma fuera de una cadena, termina la sentencia
        if ($char === ';' && !$inString) {
            $stmt = trim($current);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $remaining = trim($current);
    if ($remaining !== '') {
        $statements[] = $remaining;
    }

    return $statements;
}
