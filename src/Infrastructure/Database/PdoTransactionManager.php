<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Database;

use PDO;
use Throwable;

/**
 * PdoTransactionManager
 *
 * Implementación nativa del puerto de unidad de trabajo sobre la misma
 * conexión PDO que comparten los repositorios (Dogma Vanilla, sin dependencias).
 *
 * La conexión se resuelve de forma perezosa desde `ConnectionFactory` para que
 * el objeto sea construible sin base de datos y para no fijar la conexión antes
 * de que el proceso haya configurado el entorno.
 */
class PdoTransactionManager implements \VendGuard\Core\Domain\Repository\TransactionManagerInterface
{
    private ?PDO $pdo;

    /** Si la conexión ya venía abierta en transacción, la confirmación es de su dueño. */
    private bool $ownsTransaction = false;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * {@inheritDoc}
     */
    public function runInTransaction(callable $operation): mixed
    {
        $pdo = $this->connection();

        // Anidamiento tolerante: quien ya abrió la transacción es su dueño y el
        // único que confirma o deshace. Igual que hacen los repositorios del
        // proyecto con `inTransaction()`.
        $this->ownsTransaction = !$pdo->inTransaction();

        if ($this->ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $result = $operation();

            if ($this->ownsTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($this->ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Conexión PDO compartida con el resto de la infraestructura.
     */
    private function connection(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = ConnectionFactory::getConnection();
        }

        return $this->pdo;
    }
}
