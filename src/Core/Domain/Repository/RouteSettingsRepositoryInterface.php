<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\RouteSettings;

/**
 * Persistence contract for the singleton central base and operational-area settings.
 */
interface RouteSettingsRepositoryInterface
{
    /**
     * Retrieves the singleton route settings record.
     */
    public function find(): ?RouteSettings;

    /**
     * Persists the central base configuration and operational-area boundaries.
     */
    public function update(RouteSettings $settings): bool;
}
