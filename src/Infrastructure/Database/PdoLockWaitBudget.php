<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Database;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use VendGuard\Core\Domain\Repository\LockWaitBudgetInterface;

/**
 * PdoLockWaitBudget — adaptador nativo de la cota de espera de bloqueo de la
 * sesión PDO compartida por la infraestructura para el motor único PostgreSQL (T-13-14, T-PAUSE-40, Art. II).
 *
 * La sesión no se abre aquí: se resuelve de forma perezosa desde
 * `ConnectionFactory`, exactamente la misma conexión que usan los repositorios
 * del proceso —acotar otra sesión no serviría de nada— y de modo que el objeto
 * sea construible sin base de datos, como el resto de la infraestructura.
 *
 * **Forma técnica en PostgreSQL** (EARS-09.3):
 * La cota es `lock_timeout` y su única forma fiable bajo el agrupador transaccional
 * es `SET LOCAL`, que vale **dentro de la transacción**. Fuera de una transacción el motor
 * ignora `SET LOCAL` **en silencio** (medido) y la forma de sesión no sirve: un `SET` de
 * sesión puede caer en un backend distinto del que después toma el bloqueo, y deja el
 * valor pegado a un backend compartido por otros clientes del agrupador. Por eso, cuando
 * la operación no está dentro de una transacción, la cota se declara **ambiente** en la factoría
 * y la consume [`PdoTransactionManager`](PdoTransactionManager.php) al abrir cada unidad de
 * trabajo, que es donde la transacción existe. El valor local se revierte solo con la transacción.
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

        return $this->runWithPostgresBudget($pdo, $seconds, $operation);
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
     * Aplica la cota a la transacción activa mediante `SET LOCAL lock_timeout`.
     *
     * No toca la sesión fuera de la transacción y no eleva una cota más restrictiva
     * que la sesión ya tuviera declarada. Si falla al aplicar la cota (por ejemplo,
     * porque el motor no admite la instrucción), la operación aborta en lugar de
     * correr desprotegida: la garantía de T-PAUSE-40/Art. II es estricta.
     *
     * @throws RuntimeException Si `SET LOCAL lock_timeout` falla dentro de la transacción.
     */
    public static function applyToCurrentTransaction(PDO $pdo, ?int $seconds): void
    {
        if ($seconds === null || $seconds < 1) {
            return;
        }

        if (!$pdo->inTransaction()) {
            throw new RuntimeException(
                'No se puede aplicar lock_timeout fuera de una transacción: PostgreSQL ignora SET LOCAL en silencio fuera de una transacción (T-HYBRID-07).'
            );
        }

        $timeoutMs = $seconds * self::MILLISECONDS_PER_SECOND;

        try {
            $effectiveMs = self::resolveEffectiveTimeoutMs($pdo, $timeoutMs);
            if ($effectiveMs !== null) {
                $pdo->exec(sprintf("SET LOCAL lock_timeout = '%dms'", $effectiveMs));
            }
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'No se pudo aplicar la cota de espera de bloqueo a la transacción activa (T-HYBRID-07): ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * Resuelve la cota efectiva en milisegundos sin superar una cota previa más restrictiva.
     * Devuelve null si la cota previa ya es igual o más estricta que la pedida (evita emitir SET LOCAL redundante).
     */
    private static function resolveEffectiveTimeoutMs(PDO $pdo, int $requestedMs): ?int
    {
        $currentSetting = (string)$pdo->query("SELECT current_setting('lock_timeout')")->fetchColumn();

        if (trim($currentSetting) === '') {
            return $requestedMs;
        }

        $currentMs = self::parsePostgresDuration($currentSetting);

        if ($currentMs === 0) {
            return $requestedMs;
        }

        if ($currentMs <= $requestedMs) {
            return null; // Ya es igual o más estricta: no se modifica
        }

        return $requestedMs;
    }

    /**
     * Parsea una duración de PostgreSQL (por ejemplo `2s`, `1500ms`, `0`) a milisegundos.
     *
     * @throws RuntimeException Si la duración no tiene la forma que el motor documenta.
     */
    public static function parsePostgresDuration(string $duration): int
    {
        $normalized = strtolower(trim($duration));

        if ($normalized === '') {
            throw new RuntimeException('PostgreSQL devolvió una duración vacía para la cota de espera de bloqueo (T-HYBRID-07).');
        }

        if (!preg_match('/^([0-9]+(?:\\.[0-9]+)?)\\s*([a-zµ]*)$/', $normalized, $matches)) {
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
     * Conexión PDO compartida con el resto de la infraestructura.
     */
    private function connection(): PDO
    {
        return $this->pdo ??= ConnectionFactory::getConnection();
    }
}
