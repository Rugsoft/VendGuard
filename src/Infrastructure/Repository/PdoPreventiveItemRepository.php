<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use Throwable;
use VendGuard\Core\Domain\Model\PreventiveOrderItem;
use VendGuard\Core\Domain\Repository\PreventiveItemRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * Repositorio PDO de Ítems del Checklist Preventivo
 *
 * Implementa la persistencia de respuestas del checklist de inspección sanitaria,
 * vinculación de evidencias fotográficas y consultas de severidad sin incurrir en
 * eliminaciones físicas destructivas (Artículo III).
 */
class PdoPreventiveItemRepository implements PreventiveItemRepositoryInterface
{
    private PDO $pdo;

    public function __construct(
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function saveOrderItems(int $orderId, array $items): bool
    {
        if (empty($items)) {
            return true;
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $checkStmt = $this->pdo->prepare("
                SELECT `id` 
                FROM `preventive_order_items` 
                WHERE `preventive_order_id` = :order_id 
                  AND `item_code` = :item_code 
                LIMIT 1
            ");

            $updateStmt = $this->pdo->prepare("
                UPDATE `preventive_order_items`
                SET 
                    `item_description` = :item_description,
                    `is_critical` = :is_critical,
                    `status` = :status,
                    `observations` = :observations,
                    `photo_path` = COALESCE(:photo_path, `photo_path`)
                WHERE `id` = :id
            ");

            $insertStmt = $this->pdo->prepare("
                INSERT INTO `preventive_order_items` (
                    `preventive_order_id`,
                    `item_code`,
                    `item_description`,
                    `is_critical`,
                    `status`,
                    `observations`,
                    `photo_path`
                ) VALUES (
                    :preventive_order_id,
                    :item_code,
                    :item_description,
                    :is_critical,
                    :status,
                    :observations,
                    :photo_path
                )
            ");

            foreach ($items as $item) {
                $code = $item instanceof PreventiveOrderItem ? $item->getItemCode() : trim((string)$item['item_code']);
                $description = $item instanceof PreventiveOrderItem ? $item->getItemDescription() : trim((string)($item['item_description'] ?? $code));
                $isCritical = $item instanceof PreventiveOrderItem ? ($item->isCritical() ? 1 : 0) : (!empty($item['is_critical']) ? 1 : 0);
                $status = $item instanceof PreventiveOrderItem ? $item->getStatus() : strtoupper(trim((string)$item['status']));
                $observations = $item instanceof PreventiveOrderItem ? $item->getObservations() : (isset($item['observations']) && $item['observations'] !== '' ? trim((string)$item['observations']) : null);
                $photoPath = $item instanceof PreventiveOrderItem ? $item->getPhotoPath() : (isset($item['photo_path']) && $item['photo_path'] !== '' ? trim((string)$item['photo_path']) : null);

                // Comprobar si ya existe respuesta registrada para este ítem en la orden
                $checkStmt->execute([
                    ':order_id' => $orderId,
                    ':item_code' => $code,
                ]);
                $existingId = $checkStmt->fetchColumn();

                if ($existingId !== false) {
                    $updateStmt->execute([
                        ':item_description' => $description,
                        ':is_critical' => $isCritical,
                        ':status' => $status,
                        ':observations' => $observations,
                        ':photo_path' => $photoPath,
                        ':id' => (int)$existingId,
                    ]);
                } else {
                    $insertStmt->execute([
                        ':preventive_order_id' => $orderId,
                        ':item_code' => $code,
                        ':item_description' => $description,
                        ':is_critical' => $isCritical,
                        ':status' => $status,
                        ':observations' => $observations,
                        ':photo_path' => $photoPath,
                    ]);
                }
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findByOrderId(int $orderId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM `preventive_order_items`
            WHERE `preventive_order_id` = :order_id
            ORDER BY `is_critical` DESC, `id` ASC
        ");

        $stmt->execute([':order_id' => $orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->hydrateItem($row);
        }

        return $items;
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $itemId): ?PreventiveOrderItem
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM `preventive_order_items`
            WHERE `id` = :id
            LIMIT 1
        ");

        $stmt->execute([':id' => $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrateItem($row);
    }

    /**
     * {@inheritdoc}
     */
    public function attachPhoto(int $itemId, string $photoPath): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE `preventive_order_items`
            SET `photo_path` = :photo_path
            WHERE `id` = :id
        ");

        return $stmt->execute([
            ':photo_path' => trim($photoPath),
            ':id' => $itemId,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function attachPhotoByItemCode(int $orderId, string $itemCode, string $photoPath): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE `preventive_order_items`
            SET `photo_path` = :photo_path
            WHERE `preventive_order_id` = :order_id
              AND `item_code` = :item_code
        ");

        return $stmt->execute([
            ':photo_path' => trim($photoPath),
            ':order_id' => $orderId,
            ':item_code' => trim($itemCode),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function findCriticalFailures(int $orderId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM `preventive_order_items`
            WHERE `preventive_order_id` = :order_id
              AND `is_critical` = 1
              AND `status` = 'FAIL'
            ORDER BY `id` ASC
        ");

        $stmt->execute([':order_id' => $orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->hydrateItem($row);
        }

        return $items;
    }

    /**
     * {@inheritdoc}
     */
    public function hasCriticalFailures(int $orderId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM `preventive_order_items`
            WHERE `preventive_order_id` = :order_id
              AND `is_critical` = 1
              AND `status` = 'FAIL'
        ");

        $stmt->execute([':order_id' => $orderId]);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    /**
     * Hidrata una fila asociativa de base de datos en una entidad PreventiveOrderItem.
     *
     * @param array<string, mixed> $row
     * @return PreventiveOrderItem
     */
    private function hydrateItem(array $row): PreventiveOrderItem
    {
        return new PreventiveOrderItem(
            (int)$row['preventive_order_id'],
            (string)$row['item_code'],
            (string)$row['item_description'],
            (bool)$row['is_critical'],
            (string)$row['status'],
            isset($row['observations']) ? (string)$row['observations'] : null,
            isset($row['photo_path']) ? (string)$row['photo_path'] : null,
            isset($row['id']) ? (int)$row['id'] : null,
            isset($row['created_at']) ? (string)$row['created_at'] : null
        );
    }
}
