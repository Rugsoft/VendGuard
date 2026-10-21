<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use InvalidArgumentException;
use PDO;
use Throwable;
use VendGuard\Application\DTO\VanStockEntryDto;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\VanStockItem;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\VanStockRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoVanStockRepository
 *
 * Persistencia del stock de repuestos de la furgoneta sobre PostgreSQL (Módulo 18).
 *
 * Decisiones que el código hace explícitas:
 *
 *  - **El consumo es una sola sentencia.** `consume()` es `INSERT ... ON CONFLICT ... DO UPDATE`
 *    sin lectura previa y sin transacción propia: dos técnicos declarando piezas a la vez no
 *    pierden ninguna unidad, y el descuento no puede, por sí mismo, impedir el cierre de una
 *    avería (RD-02, RD-04). Tampoco escribe auditoría: el consumo ya queda trazado por la línea
 *    de pieza sustituida, que es el libro inmutable (RF-05.2).
 *  - **El ajuste es una unidad de escritura.** `adjust()` escribe la fila y su evento de
 *    auditoría en la misma transacción. Si el coordinador ajusta dos veces seguidas, el rastro
 *    conserva la cantidad previa y el delta de cada paso (RF-05.1).
 *  - **El orden de la alerta es de la consulta.** `listBelowThreshold()` ordena por severidad en
 *    SQL —primero los descuadres negativos y después por cantidad ascendente—, porque el panel no
 *    puede permitirse reordenar en cliente lo que la base ya sabe hacer en una pasada (RF-03.2).
 *  - **No hay `DELETE`.** La baja de una pieza es cantidad `0`; el disparador
 *    `trg_no_delete_technician_van_stocks` lo impone además en el motor (Art. III.1).
 *
 * El repositorio no valida reglas de negocio: eso es del servicio. Aquí solo se traduce entre el
 * dominio y el motor, y se respeta lo que el motor ya garantiza.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoVanStockRepository implements VanStockRepositoryInterface
{
    /** Columnas del saldo que proyecta la entidad. */
    private const STOCK_COLUMNS = 'id, technician_id, spare_part_id, quantity_available, min_threshold';

    /**
     * Proyección de una fila de inventario con su técnico y su pieza.
     *
     * La unión es interna a propósito: la clave foránea `RESTRICT` impide que exista una fila de
     * stock huérfana, así que un `INNER JOIN` no puede perder filas reales.
     */
    private const ENTRY_COLUMNS = 'vs.spare_part_id, sp.part_code, sp.name AS part_name, sp.category,
        vs.quantity_available, vs.min_threshold, vs.technician_id,
        u.name AS technician_name, u.operator_code';

    private const ENTRY_JOINS = 'FROM technician_van_stocks vs
        INNER JOIN users u ON u.id = vs.technician_id
        INNER JOIN spare_parts sp ON sp.id = vs.spare_part_id';

    private PDO $pdo;

    /**
     * Registro de auditoría del ajuste del coordinador.
     *
     * Debe compartir la conexión del repositorio: el evento se escribe dentro de la misma
     * transacción que el saldo y se deshace con él (RF-05.1).
     */
    private AuditLogRepositoryInterface $auditRepository;

    public function __construct(?PDO $pdo = null, ?AuditLogRepositoryInterface $auditRepository = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
        $this->auditRepository = $auditRepository ?? new PdoAuditLogRepository($this->pdo);
    }

    /**
     * {@inheritDoc}
     */
    public function findByPair(int $technicianId, int $sparePartId): ?VanStockItem
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::STOCK_COLUMNS . '
               FROM technician_van_stocks
              WHERE technician_id = :technician_id
                AND spare_part_id = :spare_part_id
              LIMIT 1'
        );
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->bindValue(':spare_part_id', $sparePartId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrateStock($row);
    }

    /**
     * {@inheritDoc}
     */
    public function adjust(VanStockItem $item, ?int $previousQuantity, ?int $actorUserId): VanStockItem
    {
        $ownTransaction = !$this->pdo->inTransaction();

        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
                 VALUES (:technician_id, :spare_part_id, :quantity_available, :min_threshold)
                 ON CONFLICT (technician_id, spare_part_id)
                 DO UPDATE SET
                     quantity_available = EXCLUDED.quantity_available,
                     min_threshold      = EXCLUDED.min_threshold,
                     updated_at         = CURRENT_TIMESTAMP
                 RETURNING ' . self::STOCK_COLUMNS
            );
            $stmt->bindValue(':technician_id', $item->technicianId, PDO::PARAM_INT);
            $stmt->bindValue(':spare_part_id', $item->sparePartId, PDO::PARAM_INT);
            $stmt->bindValue(':quantity_available', $item->quantityAvailable, PDO::PARAM_INT);
            $stmt->bindValue(':min_threshold', $item->minThreshold, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();

            if ($row === false) {
                throw new InvalidArgumentException(
                    'El motor no devolvió el saldo recién escrito: el ajuste de stock no puede darse por bueno.'
                );
            }

            $saved = $this->hydrateStock($row);
            $this->auditAdjustment($saved, $previousQuantity, $actorUserId);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $saved;
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function consume(int $technicianId, int $sparePartId, int $quantity): void
    {
        // Una sola sentencia: crea la fila desde cero si la furgoneta no la tenía y resta en el
        // mismo acto. Sin lectura previa no hay carrera, y sin transacción propia el descuento no
        // toca la unidad de escritura del cierre de la avería (RD-02).
        $stmt = $this->pdo->prepare(
            'INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
             VALUES (:technician_id, :spare_part_id, 0 - :quantity, 0)
             ON CONFLICT (technician_id, spare_part_id)
             DO UPDATE SET
                 quantity_available = technician_van_stocks.quantity_available - :quantity,
                 updated_at         = CURRENT_TIMESTAMP'
        );
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->bindValue(':spare_part_id', $sparePartId, PDO::PARAM_INT);
        $stmt->bindValue(':quantity', $quantity, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function listByTechnician(int $technicianId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ENTRY_COLUMNS . '
               ' . self::ENTRY_JOINS . '
              WHERE vs.technician_id = :technician_id
              ORDER BY sp.name ASC, sp.part_code ASC'
        );
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrateEntries($stmt->fetchAll());
    }

    /**
     * {@inheritDoc}
     */
    public function listInventory(?int $technicianId = null): array
    {
        $sql = 'SELECT ' . self::ENTRY_COLUMNS . '
                  ' . self::ENTRY_JOINS;
        $params = [];

        if ($technicianId !== null) {
            $sql .= ' WHERE vs.technician_id = :technician_id';
            $params[':technician_id'] = $technicianId;
        }

        $sql .= ' ORDER BY u.name ASC, sp.name ASC, sp.part_code ASC';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $this->hydrateEntries($stmt->fetchAll());
    }

    /**
     * {@inheritDoc}
     */
    public function listBelowThreshold(?int $technicianId = null): array
    {
        $sql = 'SELECT ' . self::ENTRY_COLUMNS . '
                  ' . self::ENTRY_JOINS . '
                 WHERE vs.quantity_available <= vs.min_threshold';
        $params = [];

        if ($technicianId !== null) {
            $sql .= ' AND vs.technician_id = :technician_id';
            $params[':technician_id'] = $technicianId;
        }

        // Severidad: primero los descuadres (cantidad negativa) y después la cantidad más baja.
        $sql .= ' ORDER BY (vs.quantity_available < 0) DESC, vs.quantity_available ASC, u.name ASC, sp.name ASC';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $this->hydrateEntries($stmt->fetchAll());
    }

    /**
     * {@inheritDoc}
     */
    public function findTechnician(int $technicianId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, role, operator_code
               FROM users
              WHERE id = :technician_id
                AND deleted_at IS NULL
              LIMIT 1'
        );
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'role' => strtoupper((string)$row['role']),
            'operator_code' => $row['operator_code'] === null ? null : (string)$row['operator_code'],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function sparePartExists(int $sparePartId): bool
    {
        // Sin filtro de baja lógica: una furgoneta puede conservar una pieza descatalogada y el
        // coordinador debe poder ajustarla mientras siga montada (contratos §4.3).
        $stmt = $this->pdo->prepare('SELECT 1 FROM spare_parts WHERE id = :spare_part_id');
        $stmt->bindValue(':spare_part_id', $sparePartId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Deja el evento del ajuste con la cantidad previa y el delta aplicado (RF-05.1).
     *
     * El identificador de la entidad auditada es el de la **fila de stock**, que es el objeto que
     * cambió; el técnico y la pieza viajan en el estado nuevo porque son su clave de negocio.
     */
    private function auditAdjustment(VanStockItem $saved, ?int $previousQuantity, ?int $actorUserId): void
    {
        if ($actorUserId === null) {
            return;
        }

        if ($saved->id === null) {
            throw new InvalidArgumentException('No se puede auditar un saldo de stock sin identificador.');
        }

        $actor = $this->resolveActor($actorUserId);

        $this->auditRepository->log(new AuditEvent(
            null,
            AuditEvent::ENTITY_TECHNICIAN_VAN_STOCK,
            $saved->id,
            'VAN_STOCK_ADJUSTED',
            $actorUserId,
            $actor['role'],
            $actor['name'],
            $previousQuantity === null ? null : ['quantity_available' => $previousQuantity],
            [
                'technician_id' => $saved->technicianId,
                'spare_part_id' => $saved->sparePartId,
                'quantity_available' => $saved->quantityAvailable,
                'min_threshold' => $saved->minThreshold,
            ],
            ['delta' => $saved->quantityAvailable - ($previousQuantity ?? 0)]
        ));
    }

    /**
     * Rol y nombre del usuario que ejecuta el ajuste.
     *
     * @return array{role: string, name: string}
     */
    private function resolveActor(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT role, name FROM users WHERE id = :user_id LIMIT 1');
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            throw new InvalidArgumentException(
                "No existe el usuario {$userId} que pretende ajustar el stock de la furgoneta."
            );
        }

        return [
            'role' => strtoupper((string)$row['role']),
            'name' => (string)$row['name'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateStock(array $row): VanStockItem
    {
        return new VanStockItem(
            (int)$row['id'],
            (int)$row['technician_id'],
            (int)$row['spare_part_id'],
            (int)$row['quantity_available'],
            (int)$row['min_threshold']
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<VanStockEntryDto>
     */
    private function hydrateEntries(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            $entries[] = new VanStockEntryDto(
                (int)$row['spare_part_id'],
                (string)$row['part_code'],
                (string)$row['part_name'],
                (string)$row['category'],
                (int)$row['quantity_available'],
                (int)$row['min_threshold'],
                (int)$row['technician_id'],
                (string)$row['technician_name'],
                $row['operator_code'] === null ? null : (string)$row['operator_code']
            );
        }

        return $entries;
    }
}
