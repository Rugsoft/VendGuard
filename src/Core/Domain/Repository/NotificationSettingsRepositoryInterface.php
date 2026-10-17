<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\NotificationChannelSettings;

/**
 * NotificationSettingsRepositoryInterface
 *
 * Persistence port of the corporate emergency channel configuration (Table 22).
 *
 * The table is a single logical row. The port therefore exposes a current
 * settings accessor and a save operation, never a raw row identifier, so the
 * coordination panel cannot accidentally address an older configuration.
 */
interface NotificationSettingsRepositoryInterface
{
    /**
     * Returns the current configuration, creating the institutional default row
     * when the table is still empty.
     */
    public function findCurrent(): NotificationChannelSettings;

    /**
     * Persists the configuration.
     *
     * When the Webhook is disabled, the implementation must also cancel by
     * obsolescence every pending Webhook notification in the same transaction
     * (RF-07.1.f), so no message leaves through a channel the coordinator has
     * just shut down.
     */
    public function save(NotificationChannelSettings $settings): NotificationChannelSettings;

    /**
     * Persists the outcome of a direct connectivity test of the emergency channel
     * (RF-06.5.2) without side effects on the configuration state.
     *
     * This operation must NOT toggle `is_webhook_enabled` and must NOT cancel
     * pending Webhook notifications: probing a disabled channel is a legitimate
     * diagnostic action, and it cannot silently purge the queue. Only
     * `last_tested_at`, `last_test_status` and `last_test_error` change.
     */
    public function recordTestResult(NotificationChannelSettings $settings): NotificationChannelSettings;
}
