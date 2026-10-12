<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Database;

use InvalidArgumentException;
use PDO;
use PDOException;
use VendGuard\Core\Domain\Repository\LockWaitBudgetInterface;

/**
 * PdoLockWaitBudget — adaptador nativo de la cota de espera de bloqueo de la
 * sesión PDO compartida por la infraestructura (T-PAUSE-40, Art. II).
 *
 * La sesión no se abre aquí: se resuelve de forma perezosa desde
 * `ConnectionFactory`, exactamente la misma conexión que usan los repositorios
 * del proceso —acotar otra sesión no serviría de nada— y de modo que el objeto
 * sea construible sin base de datos, como el resto de la infraestructura.
 *
 * Decisión de implementación: la cota se aplica con `SET SESSION` y se restituye
 * en un `finally`. El valor del **servidor** no se toca nunca: el barrido es un
 * proceso de fondo y no tiene autoridad para cambiar cómo esperan los demás.
 * Si la sesión ya esperaba menos que la cota pedida, no se eleva.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO, sin dependencias externas. Código en
 * inglés, documentación en castellano.
 */
final class PdoLockWaitBudget implements LockWaitBudgetInterface
{
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
        $previous = (int)$pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
        $lowered = $previous > $seconds;

        if ($lowered) {
            $pdo->exec(sprintf('SET SESSION innodb_lock_wait_timeout = %d', $seconds));
        }

        try {
            return $operation();
        } finally {
            if ($lowered) {
                $this->restore($pdo, $previous);
            }
        }
    }

    /**
     * Restituye la cota anterior de la sesión.
     *
     * Degradación declarada: si la sesión ya no admite sentencias —el caso de la
     * conexión perdida, que es justo uno de los motivos por los que la operación
     * puede haber lanzado— no hay nada que restituir y la excepción original es la
     * que debe llegar al llamante, no una segunda que la enmascare.
     */
    private function restore(PDO $pdo, int $previous): void
    {
        try {
            $pdo->exec(sprintf('SET SESSION innodb_lock_wait_timeout = %d', $previous));
        } catch (PDOException) {
            // Declarado arriba: la sesión no admite sentencias; el fallo original viaja.
        }
    }

    /**
     * Conexión PDO compartida con el resto de la infraestructura.
     */
    private function connection(): PDO
    {
        return $this->pdo ??= ConnectionFactory::getConnection();
    }
}
