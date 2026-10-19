<?php

declare(strict_types=1);

/**
 * UniqueViolationDetectorTest
 *
 * Certifica la **única autoridad** sobre la pregunta «¿esta escritura chocó contra
 * un índice único?», con la que todos los repositorios y servicios traducen la
 * colisión a un error de negocio:
 *
 *   1. Reconoce `SQLSTATE 23505` de PostgreSQL —el motor único—, la familia `23000`
 *      y el errno `1062` del motor retirado.
 *   2. **No** confunde una violación de clave ajena (`23503`) ni un error cualquiera
 *      con una violación de unicidad, aunque el mensaje nombre un índice único.
 *   3. `matches()` exige que el mensaje nombre la restricción declarada: dos índices
 *      únicos del mismo cuadro no comparten traducción por accidente.
 *   4. Un fallo que no viene de PDO nunca se clasifica como colisión.
 *
 * La suite es puramente de dominio: construye la `PDOException` con su `errorInfo`
 * tal y como la publica cada motor, sin abrir conexión.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en español.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Infrastructure\Database\UniqueViolationDetector;

echo "======================================================================\n";
echo " VendGuard · Detector único de violaciones de unicidad\n";
echo "======================================================================\n\n";

$failures = 0;
$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }

    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
    $failures++;
};

/**
 * Construye la PDOException como la publica cada motor, con su `errorInfo` real.
 */
$pdoFailure = static function (string $sqlState, ?int $driverCode, string $message): PDOException {
    $exception = new PDOException($message);
    $exception->errorInfo = [$sqlState, $driverCode, $message];

    return $exception;
};

// ---------------------------------------------------------------------
// CASO 1 · PostgreSQL, el motor único
// ---------------------------------------------------------------------
echo "--- Caso 1: SQLSTATE 23505 de PostgreSQL ---\n";

$postgres = $pdoFailure(
    UniqueViolationDetector::SQLSTATE_UNIQUE_VIOLATION,
    null,
    'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "uq_machine_active_ticket"'
);

$assert(
    '1.1 Un 23505 se reconoce como violación de unicidad',
    UniqueViolationDetector::isUniqueViolation($postgres) === true
);
$assert(
    '1.2 El SQLSTATE se extrae de errorInfo aunque getCode() no lo traiga',
    UniqueViolationDetector::sqlStateOf($postgres) === '23505'
);
$assert(
    '1.3 El detector no inventa un errno de controlador que PostgreSQL no publica',
    UniqueViolationDetector::driverErrorCodeOf($postgres) === null
);
$assert(
    '1.4 matches() confirma la restricción nombrada en el mensaje del motor',
    UniqueViolationDetector::matches($postgres, 'uq_machine_active_ticket') === true
);
$assert(
    '1.5 matches() rechaza una restricción distinta aunque también sea única',
    UniqueViolationDetector::matches($postgres, 'uq_users_email') === false
);
$assert(
    '1.6 Sin identidades declaradas matches() equivale a isUniqueViolation()',
    UniqueViolationDetector::matches($postgres) === true
);

// ---------------------------------------------------------------------
// CASO 2 · Tolerancia del motor retirado
// ---------------------------------------------------------------------
echo "\n--- Caso 2: familias heredadas de MySQL/MariaDB ---\n";

$mysql = $pdoFailure(
    UniqueViolationDetector::SQLSTATE_INTEGRITY_CONSTRAINT_VIOLATION,
    UniqueViolationDetector::DRIVER_ERROR_DUPLICATE_ENTRY,
    "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'INC-2026-1' for key 'uq_incidents_ticket_code'"
);

$assert(
    '2.1 La familia 23000 con errno 1062 se reconoce como duplicado',
    UniqueViolationDetector::isUniqueViolation($mysql) === true
        && UniqueViolationDetector::driverErrorCodeOf($mysql) === 1062
);
$assert(
    '2.2 El errno 1062 se reconoce incluso con SQLSTATE opaco',
    UniqueViolationDetector::isUniqueViolation(
        $pdoFailure('HY000', 1062, "Duplicate entry 'x' for key 'uq_x'")
    ) === true
);
$assert(
    '2.3 mentions() compara en minúsculas y acepta varias identidades',
    UniqueViolationDetector::mentions($mysql, 'NO_EXISTE', 'UQ_INCIDENTS_TICKET_CODE') === true
);

// ---------------------------------------------------------------------
// CASO 3 · Lo que NO es una violación de unicidad
// ---------------------------------------------------------------------
echo "\n--- Caso 3: falsos positivos que deben quedar fuera ---\n";

$foreignKey = $pdoFailure(
    '23503',
    null,
    'SQLSTATE[23503]: Foreign key violation: 7 ERROR:  insert or update on table "machines" violates foreign key constraint "uq_machines_code"'
);
$assert(
    '3.1 Una violación de clave ajena no se clasifica como duplicado, aunque nombre un índice',
    UniqueViolationDetector::isUniqueViolation($foreignKey) === false
        && UniqueViolationDetector::matches($foreignKey, 'uq_machines_code') === false
);
$assert(
    '3.2 Un error de columna inexistente no se clasifica como duplicado',
    UniqueViolationDetector::isUniqueViolation($pdoFailure('42703', null, 'column "x" does not exist')) === false
);
$assert(
    '3.3 Un fallo ajeno a PDO nunca se clasifica como colisión de motor',
    UniqueViolationDetector::isUniqueViolation(new InvalidArgumentException('duplicate key value violates unique constraint')) === false
        && UniqueViolationDetector::mentions(new InvalidArgumentException('duplicate'), 'duplicate') === true
);
$assert(
    '3.4 matches() exige la identidad declarada cuando se indica alguna',
    UniqueViolationDetector::matches($postgres, 'uq_users_email_key') === false
);
$assert(
    '3.5 mentions() no encuentra nada en un mensaje vacío',
    UniqueViolationDetector::mentions(new PDOException(''), 'uq_x') === false
);

echo "\n======================================================================\n";
echo " Total Aserciones fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. DETECTOR CERTIFICADO.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
