<?php

declare(strict_types=1);

/**
 * UniqueRaceHolder (G6 · detección unificada de unicidad)
 *
 * Proceso titiritero genérico de las carreras de unicidad: abre su propia conexión
 * PDO, ejecuta la sentencia `INSERT` que le entrega un fichero y **retiene la fila
 * sin confirmar** durante el tiempo indicado. El proceso padre lanza en ese instante
 * la operación de negocio por otra conexión, que queda bloqueada por el índice único
 * hasta que este proceso confirma. Es la única forma de que la colisión ocurra de
 * verdad: un solo proceso PHP no puede hacer esperar una escritura mientras confirma
 * otra.
 *
 * Uso (invocado por `UniqueViolationRaceTranslationTest`):
 *   php UniqueRaceHolder.php <sqlFile> <readyFile> <holdMs>
 *
 * Contrato de salida:
 *   - Escribe `READY` en `<readyFile>` en cuanto la fila está insertada y sin confirmar.
 *   - Duerme `<holdMs>` milisegundos y confirma.
 *   - Imprime `COMMITTED` y termina con código 0; cualquier fallo va a stderr.
 *
 * La sentencia la construye la suite con literales que ella misma controla: este
 * script no recibe entrada de usuario ni de red.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;

$sqlFile = (string)($argv[1] ?? '');
$readyFile = (string)($argv[2] ?? '');
$holdMs = (int)($argv[3] ?? 0);

if ($sqlFile === '' || !is_file($sqlFile) || $readyFile === '' || $holdMs < 1) {
    fwrite(STDERR, "Uso: php UniqueRaceHolder.php <sqlFile> <readyFile> <holdMs>\n");
    exit(2);
}

$sql = trim((string)file_get_contents($sqlFile));
if ($sql === '') {
    fwrite(STDERR, "El fichero de sentencia está vacío.\n");
    exit(2);
}

$pdo = ConnectionFactory::getConnection([], true);
$pdo->beginTransaction();

try {
    $pdo->exec($sql);

    file_put_contents($readyFile, 'READY');

    usleep($holdMs * 1000);

    $pdo->commit();
    echo "COMMITTED\n";
    exit(0);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
