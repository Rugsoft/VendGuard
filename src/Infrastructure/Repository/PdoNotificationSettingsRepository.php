<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use RuntimeException;
use Throwable;
use VendGuard\Core\Domain\Model\NotificationChannelSettings;
use VendGuard\Core\Domain\Repository\NotificationOutboxRepositoryInterface;
use VendGuard\Core\Domain\Repository\NotificationSettingsRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoNotificationSettingsRepository
 *
 * Persistencia de la configuración del Canal Corporativo de Emergencias
 * (Tabla 22 · RF-06.5).
 *
 * La tabla es un único registro lógico. `findCurrent()` lo crea con los valores
 * institucionales si aún no existiera, de modo que ninguna lectura del panel de
 * Coordinación depende de que el sembrado haya corrido.
 *
 * **Cierre del canal (RF-07.1.f).** Guardar con `is_webhook_enabled = false`
 * cancela en la MISMA transacción los envíos Webhook que siguieran pendientes:
 * apagar el canal no puede dejar mensajes en cola que salgan minutos después por
 * una configuración que Coordinación ya considera desactivada.
 */
class PdoNotificationSettingsRepository implements NotificationSettingsRepositoryInterface
{
    private const DISABLED_REASON = 'Canal corporativo de emergencias desactivado por Coordinación.';

    private PDO $pdo;

    private NotificationOutboxRepositoryInterface $outbox;

    public function __construct(?PDO $pdo = null, ?NotificationOutboxRepositoryInterface $outbox = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
        $this->outbox = $outbox ?? new PdoNotificationOutboxRepository($this->pdo);
    }

    /**
     * {@inheritDoc}
     */
    public function findCurrent(): NotificationChannelSettings
    {
        $row = $this->currentRow();

        if ($row === null) {
            $insert = $this->pdo->prepare("
                INSERT INTO notification_channel_settings (webhook_name, webhook_url, is_webhook_enabled)
                VALUES ('Canal Central de Emergencias', NULL, FALSE)
                RETURNING *
            ");
            $insert->execute();
            $row = $insert->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                throw new RuntimeException('No se pudo crear la configuración inicial del canal de emergencias.');
            }
        }

        return NotificationChannelSettings::fromDatabaseRow($row);
    }

    /**
     * {@inheritDoc}
     */
    public function save(NotificationChannelSettings $settings): NotificationChannelSettings
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $current = $this->findCurrent();
            $id = $settings->getId() ?? $current->getId();

            if ($id === null) {
                throw new RuntimeException('La configuración del canal de emergencias no tiene identificador.');
            }

            $sql = "
                UPDATE notification_channel_settings SET
                    webhook_name = :webhook_name,
                    webhook_url = :webhook_url,
                    is_webhook_enabled = :is_webhook_enabled,
                    last_tested_at = :last_tested_at,
                    last_test_status = :last_test_status,
                    last_test_error = :last_test_error,
                    updated_by = :updated_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
                  AND deleted_at IS NULL
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':webhook_name', $settings->getWebhookName(), PDO::PARAM_STR);
            $stmt->bindValue(
                ':webhook_url',
                $settings->getWebhookUrl(),
                $settings->getWebhookUrl() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
            );
            $stmt->bindValue(':is_webhook_enabled', $settings->isWebhookEnabled(), PDO::PARAM_BOOL);
            $stmt->bindValue(
                ':last_tested_at',
                $settings->getLastTestedAt()?->format('Y-m-d H:i:sP'),
                $settings->getLastTestedAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
            );
            $stmt->bindValue(
                ':last_test_status',
                $settings->getLastTestStatus(),
                $settings->getLastTestStatus() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
            );
            $stmt->bindValue(
                ':last_test_error',
                $settings->getLastTestError(),
                $settings->getLastTestError() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
            );
            $stmt->bindValue(
                ':updated_by',
                $settings->getUpdatedBy(),
                $settings->getUpdatedBy() !== null ? PDO::PARAM_INT : PDO::PARAM_NULL
            );

            if (!$stmt->execute()) {
                throw new RuntimeException('No se pudo persistir la configuración del canal de emergencias.');
            }

            if (!$settings->isWebhookEnabled()) {
                $this->outbox->cancelPendingWebhookItems(self::DISABLED_REASON);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $this->findCurrent();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritDoc}
     *
     * Actualiza únicamente el resultado de la prueba interactiva. No reutiliza
     * `save()` a propósito: `save()` cancela los envíos Webhook pendientes al
     * desactivar el canal, y probar una conexión desde el panel no puede vaciar
     * la cola como efecto colateral (RF-06.5.2, RF-07.1.f).
     */
    public function recordTestResult(NotificationChannelSettings $settings): NotificationChannelSettings
    {
        $current = $this->findCurrent();
        $id = $settings->getId() ?? $current->getId();

        if ($id === null) {
            throw new RuntimeException('La configuración del canal de emergencias no tiene identificador.');
        }

        $stmt = $this->pdo->prepare("
            UPDATE notification_channel_settings SET
                last_tested_at = :last_tested_at,
                last_test_status = :last_test_status,
                last_test_error = :last_test_error,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND deleted_at IS NULL
        ");

        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(
            ':last_tested_at',
            $settings->getLastTestedAt()?->format('Y-m-d H:i:sP'),
            $settings->getLastTestedAt() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $stmt->bindValue(
            ':last_test_status',
            $settings->getLastTestStatus(),
            $settings->getLastTestStatus() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $stmt->bindValue(
            ':last_test_error',
            $settings->getLastTestError(),
            $settings->getLastTestError() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
        );

        $stmt->execute();

        return $this->findCurrent();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentRow(): ?array
    {
        $stmt = $this->pdo->query("
            SELECT *
            FROM notification_channel_settings
            WHERE deleted_at IS NULL
            ORDER BY id ASC
            LIMIT 1
        ");

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
