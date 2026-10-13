<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Database;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use VendGuard\Core\Domain\Repository\LockWaitBudgetInterface;

/**
 * PdoLockWaitBudget — adaptador nativo de la cota de espera de bloqueo de la
 * sesión PDO compartida por la infraestructura (T-PAUSE-40, T-HYBRID-07, Art. II).
 *
 * La sesión no se abre aquí: se resuelve de forma perezosa desde
 * `ConnectionFactory`, exactamente la misma conexión que usan los repositorios
 * del proceso —acotar otra sesión no serviría de nada— y de modo que el objeto
 * sea construible sin base de datos, como el resto de la infraestructura.
 *
 * **Dos motores, dos formas técnicas, una sola regla** (EARS-09.3). El puerto
 * describe la regla —acotar la operación, no elevar una cota más estricta y no
 * tocar nunca el valor del servidor— y cada motor la escribe como puede:
 *
 * 1. **MariaDB/MySQL:** la cota es un valor de sesión (`innodb_lock_wait_timeout`).
 *    Se lee, se baja con `SET SESSION` si procede y se restituye en un `finally`.
 * 2. **PostgreSQL:** la cota es `lock_timeout` y su única forma fiable bajo el
 *    agrupador transaccional es `SET LOCAL`, que vale **dentro de la transacción**.
 *    Fuera de una transacción el motor ignora `SET LOCAL` **en silencio** (medido)
 *    y la forma de sesión no sirve: un `SET` de sesión puede caer en un backend
 *    distinto del que después toma el bloqueo, y deja el valor pegado a un backend
 *    compartido por otros clientes del agrupador. Por eso, cuando la operación no
 *    está dentro de una transacción, la cota se declara **ambiente** en la factoría
 *    y la consume [`PdoTransactionManager`](PdoTransactionManager.php) al abrir cada
 *    unidad de trabajo, que es donde la transacción existe. El valor local se
 *    revierte solo, con la transacción: no hay nada que restituir.
 *
 * **Alcance declarado:** en PostgreSQL la cota es de alcance transaccional, no de
 * proceso. Una operación que no abra ninguna transacción no recibe cota —no hay
 * dónde aplicarla sin ensuciar la sesión del agrupador— y así queda dicho aquí en
 * lugar de fingirse aplicada.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO, sin dependencias externas. Código en
 * inglés, documentación en castellano.
 */
final class PdoLockWaitBudget implements LockWaitBudgetInterface
{
    /** Milisegundos de un segundo: la unidad con la que PostgreSQL aplica la cota. */
    private const MILLISECONDS_PER_SECOND = 1000;

    /**
     * Equivalencia en milisegundos de las unidades con las que PostgreSQL devuelve una
     * duración en `current_setting` (por ejemplo `2s`, `1500ms` o `1min`).
     */
    private const POSTGRES_MILLISECONDS_PER_UNIT = [
        'us' => 0.001,
        'µs' => 0.001,
        'ms' => 1.0,
        's' => 1000.0,
        'min' => 60000.0,
        'h' => 3600000.0,
        'd' => 86400000.0,
    ];

    /** Conexión compartida; se resuelve en el primer uso si no se inyecta. */
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * {@inheritDoc}
     */
    public function runWithLockWaitBudget(int $seconds, callable $operation): mixed
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException(
                'La cota de espera de bloqueo debe ser de al menos un segundo (T-PAUSE-40).'
            );
        }

        $pdo = $this->connection();

        return self::driverOf($pdo) === ConnectionFactory::DRIVER_PGSQL
            ? $this->runWithPostgresBudget($pdo, $seconds, $operation)
            : $this->runWithMysqlBudget($pdo, $seconds, $operation);
    }

    /**
     * Rama MariaDB/MySQL: la cota es un valor de sesión que se baja y se restituye.
     *
     * El valor del **servidor** no se toca nunca: el barrido es un proceso de fondo y no
     * tiene autoridad para cambiar cómo esperan los demás. Si la sesión ya esperaba menos
     * que la cota pedida, no se eleva.
     */
    private function runWithMysqlBudget(PDO $pdo, int $seconds, callable $operation): mixed
    {
        $previous = (int)$pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
        $lowered = $previous > $seconds;

        if ($lowered) {
            $pdo->exec(sprintf('SET SESSION innodb_lock_wait_timeout = %d', $seconds));
        }

        try {
            return $operation();
        } finally {
            if ($lowered) {
                $this->restoreMysqlBudget($pdo, $previous);
            }
        }
    }

    /**
     * Rama PostgreSQL: `SET LOCAL lock_timeout` dentro de la transacción, o cota ambiente
     * declarada para las transacciones que la operación abra (ver el docblock de la clase).
     */
    private function runWithPostgresBudget(PDO $pdo, int $seconds, callable $operation): mixed
    {
        if ($pdo->inTransaction()) {
            // La transacción ya existe: la cota se aplica aquí y se revierte con ella.
            self::applyToCurrentTransaction($pdo, $seconds);

            return $operation();
        }

        // No hay transacción activa: la cota viaja como estado ambiente del proceso hasta la
        // frontera transaccional. Se restituye el valor anterior pase lo que pase.
        $previous = ConnectionFactory::declareAmbientLockWaitSeconds($seconds);
        try {
            return $operation();
        } finally {
            ConnectionFactory::declareAmbientLockWaitSeconds($previous);
        }
    }

    /**
     * Aplica la cota a la **transacción activa** con la forma del motor.
     *
     * Único punto donde vive el `SET LOCAL` de PostgreSQL: lo usan el propio adaptador
     * cuando la operación ya corre en una transacción y [`PdoTransactionManager`](PdoTransactionManager.php)
     * al abrir cada unidad de trabajo del lote, de modo que la cota cae dentro de la
     * transacción que realmente toma el bloqueo y no hay dos definiciones de la forma.
     *
     * Se respeta la regla del puerto de **no elevar** una cota más estricta: si la
     * transacción ya corre con una cota igual o menor —incluida la que fije el propio motor
     * por rol o base de datos—, se deja como está. Un valor `0` significa «sin límite», es
     * decir, la cota más laxa posible, y por eso sí se baja.
     *
     * En MariaDB no hace nada: allí la cota se aplica por sesión desde el adaptador.
     *
     * @param int|null $seconds Cota en segundos, o `null` si el proceso no ha declarado
     *                          ninguna: entonces no hay nada que acotar y la transacción
     *                          corre con la cota del motor.
     *
     * @throws RuntimeException Si se invoca sin transacción activa en el motor nuevo, donde
     *                          la forma local se ignoraría en silencio (Fail-Fast).
     */
    public static function applyToCurrentTransaction(PDO $pdo, ?int $seconds): void
    {
        if ($seconds === null || self::driverOf($pdo) !== ConnectionFactory::DRIVER_PGSQL) {
            return;
        }

        if (!$pdo->inTransaction()) {
            throw new RuntimeException(
                'La cota de espera de bloqueo de PostgreSQL sólo puede aplicarse dentro de una transacción: fuera de ella el motor ignora `SET LOCAL` en silencio (T-HYBRID-07).'
            );
        }

        $milliseconds = $seconds * self::MILLISECONDS_PER_SECOND;
        $current = self::readPostgresLockWaitMilliseconds($pdo);

        if ($current !== 0 && $current <= $milliseconds) {
            return;
        }

        $pdo->exec(sprintf("SET LOCAL lock_timeout = '%dms'", $milliseconds));
    }

    /**
     * Cota de espera de bloqueo vigente en la sesión o en la transacción activa, en
     * milisegundos (`0` significa «sin límite»).
     */
    private static function readPostgresLockWaitMilliseconds(PDO $pdo): int
    {
        $value = (string)$pdo->query("SELECT current_setting('lock_timeout')")->fetchColumn();

        return self::parsePostgresDuration($value);
    }

    /**
     * Traduce una duración de PostgreSQL a milisegundos.
     *
     * Es la forma en la que el motor devuelve `current_setting('lock_timeout')`:
     * `0`, `1500ms`, `2s`, `1min`... Un valor que no se entienda **no se interpreta
     * a la ligera**: se rechaza en voz alta, porque suponerlo «sin límite» acabaría
     * pisando una cota que el operador sí había fijado.
     *
     * @throws RuntimeException Si la duración no tiene la forma que el motor documenta.
     */
    public static function parsePostgresDuration(string $duration): int
    {
        $normalized = strtolower(trim($duration));

        if ($normalized === '') {
            throw new RuntimeException('PostgreSQL devolvió una duración vacía para la cota de espera de bloqueo (T-HYBRID-07).');
        }

        if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([a-zµ]*)$/', $normalized, $matches)) {
            throw new RuntimeException(sprintf(
                'Duración de PostgreSQL no reconocida («%s») para la cota de espera de bloqueo: se rechaza en lugar de suponer un valor (T-HYBRID-07).',
                $duration
            ));
        }

        $amount = (float)$matches[1];
        $unit = $matches[2];

        // Un número desnudo son milisegundos, que es la unidad por defecto del motor.
        if ($unit === '') {
            return (int)round($amount);
        }

        if (!array_key_exists($unit, self::POSTGRES_MILLISECONDS_PER_UNIT)) {
            throw new RuntimeException(sprintf(
                'Unidad de duración no reconocida («%s») en la cota de espera de bloqueo (T-HYBRID-07).',
                $unit
            ));
        }

        return (int)round($amount * self::POSTGRES_MILLISECONDS_PER_UNIT[$unit]);
    }

    /**
     * Restituye la cota anterior de la sesión en MariaDB.
     *
     * Degradación declarada: si la sesión ya no admite sentencias —el caso de la
     * conexión perdida, que es justo uno de los motivos por los que la operación
     * puede haber lanzado— no hay nada que restituir y la excepción original es la
     * que debe llegar al llamante, no una segunda que la enmascare.
     */
    private function restoreMysqlBudget(PDO $pdo, int $previous): void
    {
        try {
            $pdo->exec(sprintf('SET SESSION innodb_lock_wait_timeout = %d', $previous));
        } catch (PDOException) {
            // Declarado arriba: la sesión no admite sentencias; el fallo original viaja.
        }
    }

    /**
     * Driver activo de la conexión compartida.
     */
    private static function driverOf(PDO $pdo): string
    {
        return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Conexión PDO compartida con el resto de la infraestructura.
     */
    private function connection(): PDO
    {
        return $this->pdo ??= ConnectionFactory::getConnection();
    }
}
