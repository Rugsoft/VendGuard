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
 *
 * Es también la **frontera donde nace la transacción**, y por eso el único punto
 * desde el que se puede acotar la espera de bloqueo en motores cuya cota es local a
 * la transacción (PostgreSQL, `lock_timeout`). Al abrir cada unidad de trabajo
 * consume la cota ambiente declarada por [`PdoLockWaitBudget`](PdoLockWaitBudget.php)
 * —la que el barrido sanitario pide por operación— y la aplica dentro de la
 * transacción, que es donde el motor la respeta. En MariaDB la cota viaja por sesión
 * y esta frontera no interviene.
 */
class PdoTransactionManager implements \VendGuard\Core\Domain\Repository\TransactionManagerInterface
{
    private ?PDO $pdo;

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
        //
        // La propiedad de ESTA unidad de trabajo vive en una variable local y no
        // en un atributo del objeto: el servicio de encolado anida varias
        // llamadas en la misma instancia y un atributo compartido hacía que la
        // llamada interna sobrescribiera la propiedad del marco externo, de modo
        // que un fallo posterior dejaba la transacción abierta sin deshacer
        // (bug de anidamiento detectado por `NotificationOutboxEnqueueIntegrationTest`).
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
            PdoLockWaitBudget::applyToCurrentTransaction($pdo, ConnectionFactory::ambientLockWaitSeconds());
        }

        try {
            $result = $operation();

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
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
