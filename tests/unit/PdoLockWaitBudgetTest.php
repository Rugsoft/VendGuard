<?php

declare(strict_types=1);

/**
 * PdoLockWaitBudgetTest — certificación del presupuesto de espera de bloqueo
 * para el motor único PostgreSQL (T-HYBRID-19, adaptado a motor único en T-13-14).
 *
 * POR QUÉ ESTA SUITE EXISTE
 *
 * El presupuesto describe la cota de espera de bloqueo bajo el contrato del motor
 * único PostgreSQL (EARS-09.3, T-PAUSE-40, Art. II):
 *
 *   1. La cota vale **sólo para la operación** y viaja como cota ambiente del proceso
 *      fuera de una transacción, o se aplica directamente con `SET LOCAL lock_timeout`
 *      dentro de una transacción activa.
 *   2. **Nunca eleva** una cota ya más estricta: si la transacción ya espera menos,
 *      la elección del operador se respeta (no se emite `SET LOCAL` redundante).
 *   3. Sólo se toca la **transacción local** (`SET LOCAL`), jamás el valor global del
 *      servidor ni la sesión compartida del pooler.
 *   4. Una cota imposible se rechaza **antes** de tocar la sesión (Fail-Fast).
 *   5. Fuera de una transacción, `applyToCurrentTransaction` falla en voz alta
 *      porque PostgreSQL ignora `SET LOCAL` en silencio fuera de un bloque transaccional.
 *
 * CÓMO SE CERTIFICA SIN MOTOR
 *
 * Se inyecta una conexión simulada que **registra la traza exacta** de lo que el adaptador pide
 * —consultas, sentencias y el momento de la operación—.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas ni bundlers.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Repository\LockWaitBudgetInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoLockWaitBudget;

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - PdoLockWaitBudgetTest (T-13-14)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
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

final class FakeBudgetStatement extends PDOStatement
{
    public function __construct(private mixed $value = null)
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->value;
    }
}

final class FakeBudgetConnection extends PDO
{
    /** @var list<string> */
    public array $trace = [];

    private mixed $currentBudget;

    public function __construct(
        private string $driver = ConnectionFactory::DRIVER_PGSQL,
        private int|string $sessionBudget = '0',
        private bool $transactionOpen = false,
        public ?string $execFailureNeedle = null,
    ) {
        $this->currentBudget = (string)$sessionBudget;
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? $this->driver : null;
    }

    public function inTransaction(): bool
    {
        return $this->transactionOpen;
    }

    public function openTransaction(): void
    {
        $this->transactionOpen = true;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->trace[] = 'QUERY: ' . $query;

        return new FakeBudgetStatement($this->currentBudget);
    }

    public function exec(string $statement): int|false
    {
        $this->trace[] = 'EXEC: ' . $statement;

        if ($this->execFailureNeedle !== null && str_contains($statement, $this->execFailureNeedle)) {
            throw new PDOException('la sesión ya no admite sentencias');
        }

        return 1;
    }
}

$operationMarking = function (FakeBudgetConnection $connection, mixed $result = 'resultado'): callable {
    return function () use ($connection, $result): mixed {
        $connection->trace[] = 'OPERATION';

        return $result;
    };
};

$ambientBefore = ConnectionFactory::ambientLockWaitSeconds();

// =====================================================================
// GRUPO 1 — El puerto y la puerta de entrada (Fail-Fast antes de tocar la sesión)
// =====================================================================
echo "--- Grupo 1: contrato del puerto y cota imposible rechazada antes de tocar la sesión ---\n";

$pgConnection = new FakeBudgetConnection(ConnectionFactory::DRIVER_PGSQL);
$budget = new PdoLockWaitBudget($pgConnection);

$assert(
    '1.1 El adaptador implementa el puerto declarado del dominio',
    $budget instanceof LockWaitBudgetInterface
);

foreach ([0, -1, -30] as $impossibleSeconds) {
    $untouched = new FakeBudgetConnection(ConnectionFactory::DRIVER_PGSQL);
    $rejected = null;

    try {
        (new PdoLockWaitBudget($untouched))->runWithLockWaitBudget($impossibleSeconds, $operationMarking($untouched));
    } catch (InvalidArgumentException $exception) {
        $rejected = $exception;
    }

    $assert(
        sprintf('1.2 Una cota de %d s se rechaza con InvalidArgumentException', $impossibleSeconds),
        $rejected instanceof InvalidArgumentException
    );

    $assert(
        sprintf('1.3 Una cota de %d s se rechaza ANTES de tocar la sesión (traza vacía)', $impossibleSeconds),
        $untouched->trace === [],
        json_encode($untouched->trace)
    );
}

$identityResult = new ArrayObject(['v' => 1]);
$identityConnection = new FakeBudgetConnection(ConnectionFactory::DRIVER_PGSQL, sessionBudget: '0', transactionOpen: true);
$returned = (new PdoLockWaitBudget($identityConnection))->runWithLockWaitBudget(
    9,
    $operationMarking($identityConnection, $identityResult)
);

$assert(
    '1.4 El resultado de la operación se devuelve tal cual, sin reenvolverlo',
    $returned === $identityResult
);

// =====================================================================
// GRUPO 2 — PostgreSQL: cota local en la transacción, cota ambiente sin ella
// =====================================================================
echo "\n--- Grupo 2: PostgreSQL — `SET LOCAL` en la transacción y cota ambiente fuera de ella ---\n";

$postgresOpen = new FakeBudgetConnection(ConnectionFactory::DRIVER_PGSQL, sessionBudget: '0', transactionOpen: true);
(new PdoLockWaitBudget($postgresOpen))->runWithLockWaitBudget(2, $operationMarking($postgresOpen));

$assert(
    '2.1 En transacción y sin cota vigente, se aplica `SET LOCAL` en milisegundos',
    $postgresOpen->trace === [
        "QUERY: SELECT current_setting('lock_timeout')",
        "EXEC: SET LOCAL lock_timeout = '2000ms'",
        'OPERATION',
    ],
    json_encode($postgresOpen->trace)
);

$assert(
    '2.2 La cota local no se restituye a mano: se revierte con la transacción que la contiene',
    count(array_filter($postgresOpen->trace, fn (string $entry): bool => str_contains($entry, 'lock_timeout'))) === 2
);

$postgresAtEqual = new FakeBudgetConnection(ConnectionFactory::DRIVER_PGSQL, sessionBudget: '2s', transactionOpen: true);
(new PdoLockWaitBudget($postgresAtEqual))->runWithLockWaitBudget(2, $operationMarking($postgresAtEqual));

$assert(
    '2.3 Una cota vigente igual a la pedida no se reescribe (`2s` = 2000 ms)',
    $postgresAtEqual->trace === ["QUERY: SELECT current_setting('lock_timeout')", 'OPERATION'],
    json_encode($postgresAtEqual->trace)
);

$postgresStricter = new FakeBudgetConnection(ConnectionFactory::DRIVER_PGSQL, sessionBudget: '1500ms', transactionOpen: true);
(new PdoLockWaitBudget($postgresStricter))->runWithLockWaitBudget(2, $operationMarking($postgresStricter));

$assert(
    '2.4 Una cota vigente más estricta (1500 ms) no se eleva a 2000 ms',
    $postgresStricter->trace === ["QUERY: SELECT current_setting('lock_timeout')", 'OPERATION'],
    json_encode($postgresStricter->trace)
);

$postgresTight = new FakeBudgetConnection(ConnectionFactory::DRIVER_PGSQL, sessionBudget: '0', transactionOpen: true);
(new PdoLockWaitBudget($postgresTight))->runWithLockWaitBudget(1, $operationMarking($postgresTight));

$assert(
    '2.5 El borde de 1 s se escribe como 1000 ms, la unidad del motor',
    str_contains(json_encode($postgresTight->trace), "SET LOCAL lock_timeout = '1000ms'"),
    json_encode($postgresTight->trace)
);

$postgresFailing = new FakeBudgetConnection(ConnectionFactory::DRIVER_PGSQL, sessionBudget: '0', transactionOpen: true);
$postgresFailure = new RuntimeException('la operación falló dentro de la transacción');
$postgresPropagated = null;

try {
    (new PdoLockWaitBudget($postgresFailing))->runWithLockWaitBudget(2, function () use ($postgresFailing, $postgresFailure): void {
        $postgresFailing->trace[] = 'OPERATION';
        throw $postgresFailure;
    });
} catch (Throwable $exception) {
    $postgresPropagated = $exception;
}

$assert(
    '2.6 Un fallo dentro de la transacción no busca restituir nada (el valor local se va con ella)',
    $postgresPropagated === $postgresFailure
        && $postgresFailing->trace === [
            "QUERY: SELECT current_setting('lock_timeout')",
            "EXEC: SET LOCAL lock_timeout = '2000ms'",
            'OPERATION',
        ],
    json_encode($postgresFailing->trace)
);

$postgresAmbient = new FakeBudgetConnection(ConnectionFactory::DRIVER_PGSQL, sessionBudget: '0');
$ambientDeclaredDuringOperation = null;
$ambientAfterOperation = null;

(new PdoLockWaitBudget($postgresAmbient))->runWithLockWaitBudget(3, function () use (&$ambientDeclaredDuringOperation): string {
    $ambientDeclaredDuringOperation = ConnectionFactory::ambientLockWaitSeconds();

    return 'resultado';
});
$ambientAfterOperation = ConnectionFactory::ambientLockWaitSeconds();

$assert(
    '2.7 Sin transacción no se emite ninguna sentencia: la cota se declara ambiente',
    $postgresAmbient->trace === [],
    json_encode($postgresAmbient->trace)
);

$assert(
    '2.8 La cota ambiente está vigente DURANTE la operación y retirada después',
    $ambientDeclaredDuringOperation === 3 && $ambientAfterOperation === $ambientBefore,
    sprintf('durante: %s · después: %s', var_export($ambientDeclaredDuringOperation, true), var_export($ambientAfterOperation, true))
);

ConnectionFactory::declareAmbientLockWaitSeconds(7);
$postgresAmbientPrevious = new FakeBudgetConnection(ConnectionFactory::DRIVER_PGSQL, sessionBudget: '0');
$ambientRestored = null;

(new PdoLockWaitBudget($postgresAmbientPrevious))->runWithLockWaitBudget(2, function () use (&$ambientRestored): void {
    $ambientRestored = ConnectionFactory::ambientLockWaitSeconds();
});

$assert(
    '2.9 La cota ambiente anterior se restituye, no se pierde',
    $ambientRestored === 2 && ConnectionFactory::ambientLockWaitSeconds() === 7,
    sprintf('durante: %s · después: %s', var_export($ambientRestored, true), var_export(ConnectionFactory::ambientLockWaitSeconds(), true))
);

$ambientErrorDuring = null;
$ambientErrorAfter = null;
try {
    (new PdoLockWaitBudget($postgresAmbientPrevious))->runWithLockWaitBudget(4, function () use (&$ambientErrorDuring): void {
        $ambientErrorDuring = ConnectionFactory::ambientLockWaitSeconds();
        throw new RuntimeException('fallo bajo cota ambiente');
    });
} catch (RuntimeException) {
    $ambientErrorAfter = ConnectionFactory::ambientLockWaitSeconds();
}

$assert(
    '2.10 Un fallo con cota ambiente declarada también la restituye',
    $ambientErrorDuring === 4 && $ambientErrorAfter === 7
);

ConnectionFactory::declareAmbientLockWaitSeconds($ambientBefore);

// =====================================================================
// GRUPO 3 — La frontera transaccional (`applyToCurrentTransaction`)
// =====================================================================
echo "\n--- Grupo 3: la frontera transaccional aplica la cota ambiente ---\n";

$noBudgetPostgres = new FakeBudgetConnection(ConnectionFactory::DRIVER_PGSQL, transactionOpen: true);
PdoLockWaitBudget::applyToCurrentTransaction($noBudgetPostgres, null);

$assert(
    '3.1 Sin cota declarada no hay nada que aplicar: ni una sola sentencia',
    $noBudgetPostgres->trace === [],
    json_encode($noBudgetPostgres->trace)
);

$outsideTransaction = new FakeBudgetConnection(ConnectionFactory::DRIVER_PGSQL, sessionBudget: '0');
$outsideRejection = null;

try {
    PdoLockWaitBudget::applyToCurrentTransaction($outsideTransaction, 2);
} catch (RuntimeException $exception) {
    $outsideRejection = $exception;
}

$assert(
    '3.2 PostgreSQL sin transacción se rechaza en voz alta (fuera, el motor ignora SET LOCAL)',
    $outsideRejection instanceof RuntimeException
        && str_contains($outsideRejection->getMessage(), 'transacción')
        && str_contains($outsideRejection->getMessage(), 'T-HYBRID-07'),
    $outsideRejection instanceof RuntimeException ? $outsideRejection->getMessage() : 'no se lanzó'
);

$assert(
    '3.3 El rechazo es Fail-Fast: no se llegó a consultar la cota vigente',
    $outsideTransaction->trace === [],
    json_encode($outsideTransaction->trace)
);

$postgresBoundary = new FakeBudgetConnection(ConnectionFactory::DRIVER_PGSQL, sessionBudget: '0', transactionOpen: true);
PdoLockWaitBudget::applyToCurrentTransaction($postgresBoundary, 4);

$assert(
    '3.4 En PostgreSQL dentro de la transacción sí se aplica, con la misma forma local',
    $postgresBoundary->trace === [
        "QUERY: SELECT current_setting('lock_timeout')",
        "EXEC: SET LOCAL lock_timeout = '4000ms'",
    ],
    json_encode($postgresBoundary->trace)
);

$transactionManagerSource = (string)file_get_contents(__DIR__ . '/../../src/Infrastructure/Database/PdoTransactionManager.php');

$assert(
    '3.5 La cota ambiente se declara y se consume de verdad en la frontera transaccional',
    str_contains($transactionManagerSource, 'applyToCurrentTransaction')
        && str_contains($transactionManagerSource, 'ConnectionFactory::ambientLockWaitSeconds()')
);

// =====================================================================
// GRUPO 4 — Bordes del parseo de duraciones de PostgreSQL
// =====================================================================
echo "\n--- Grupo 4: parseo estricto de duraciones de PostgreSQL ---\n";

$validDurations = [
    '0' => 0,
    '0s' => 0,
    '1500ms' => 1500,
    '2s' => 2000,
    '2.5s' => 2500,
    '1min' => 60000,
    '1h' => 3600000,
    '1d' => 86400000,
    '500µs' => 1,
    '500us' => 1,
    ' 2s ' => 2000,
];

foreach ($validDurations as $raw => $expectedMs) {
    $parsed = PdoLockWaitBudget::parsePostgresDuration((string)$raw);
    $assert(
        sprintf('4.1 Duración «%s» parsea a %d ms', (string)$raw, $expectedMs),
        $parsed === $expectedMs,
        sprintf('valor devuelto: %d', $parsed)
    );
}

$invalidDurations = [
    '' => 'vacía',
    'desconocido' => 'no reconocida',
    '2x' => 'Unidad de duración no reconocida',
];

foreach ($invalidDurations as $raw => $expectedFragment) {
    $rejected = null;
    try {
        PdoLockWaitBudget::parsePostgresDuration($raw);
    } catch (RuntimeException $exception) {
        $rejected = $exception;
    }

    $assert(
        sprintf('4.2 Duración inválida «%s» se rechaza en voz alta', $raw),
        $rejected instanceof RuntimeException && str_contains($rejected->getMessage(), $expectedFragment),
        $rejected instanceof RuntimeException ? $rejected->getMessage() : 'no lanzó'
    );
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-HYBRID-07 CUMPLIDA.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
