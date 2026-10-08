<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Core\Domain\Repository\SiteAccessCodeRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoSiteAccessCodeRepository
 *
 * Adaptador PDO del puerto `SiteAccessCodeRepositoryInterface` (hallazgo S-4).
 * Persiste el hash bcrypt de la clave de centro en `locations`, junto al sello de
 * emisión y el freno de intentos, reutilizando la sede como agregado propietario:
 * no se crea tabla nueva porque la clave es un atributo de acceso de la sede.
 *
 * Notas de integridad:
 * - El bloqueo por intentos es temporal (por defecto 15 minutos): un bloqueo ya
 *   vencido se limpia antes de contabilizar un nuevo fallo, de modo que un usuario
 *   legítimo no queda excluido de por vida por un error de transcripción.
 * - La emisión y la reemisión reinician el freno en la misma sentencia: una clave
 *   nueva siempre nace con contador a cero.
 */
final class PdoSiteAccessCodeRepository implements SiteAccessCodeRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritDoc}
     */
    public function findAccessState(int $locationId): ?array
    {
        $sql = "
            SELECT
                `access_code_hash` AS hash,
                `access_code_issued_at` AS issued_at,
                `login_attempts` AS attempts,
                (`login_locked_until` IS NOT NULL AND `login_locked_until` > NOW()) AS is_locked
            FROM `locations`
            WHERE `id` = :id
              AND `deleted_at` IS NULL
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $locationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'hash' => $row['hash'] !== null ? (string)$row['hash'] : null,
            'issued_at' => $row['issued_at'] !== null ? (string)$row['issued_at'] : null,
            'attempts' => (int)$row['attempts'],
            'is_locked' => (bool)$row['is_locked'],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function storeAccessCodeHash(int $locationId, string $hash): void
    {
        $sql = "
            UPDATE `locations`
            SET
                `access_code_hash` = :hash,
                `access_code_issued_at` = CURRENT_TIMESTAMP,
                `login_attempts` = 0,
                `login_locked_until` = NULL
            WHERE `id` = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':hash' => $hash, ':id' => $locationId]);
    }

    /**
     * {@inheritDoc}
     */
    public function registerFailedLogin(int $locationId, int $maxAttempts, int $lockSeconds): void
    {
        // Un bloqueo ya vencido no debe seguir penalizando: se limpia antes de contar.
        $clearExpiredSql = "
            UPDATE `locations`
            SET `login_attempts` = 0, `login_locked_until` = NULL
            WHERE `id` = :id
              AND `login_locked_until` IS NOT NULL
              AND `login_locked_until` <= NOW()
        ";
        $this->pdo->prepare($clearExpiredSql)->execute([':id' => $locationId]);

        // El cálculo del bloqueo va primero y usa el contador anterior: MySQL evalúa las
        // asignaciones en orden, así que mover el incremento delante activaría el bloqueo
        // un intento antes de lo previsto.
        $sql = "
            UPDATE `locations`
            SET
                `login_locked_until` = IF(
                    `login_attempts` + 1 >= :max_attempts,
                    TIMESTAMPADD(SECOND, :lock_seconds, NOW()),
                    NULL
                ),
                `login_attempts` = `login_attempts` + 1
            WHERE `id` = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':max_attempts', $maxAttempts, PDO::PARAM_INT);
        $stmt->bindValue(':lock_seconds', $lockSeconds, PDO::PARAM_INT);
        $stmt->bindValue(':id', $locationId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function resetLoginAttempts(int $locationId): void
    {
        $sql = "
            UPDATE `locations`
            SET `login_attempts` = 0, `login_locked_until` = NULL
            WHERE `id` = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $locationId]);
    }
}
