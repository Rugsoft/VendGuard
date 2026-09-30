<?php
declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Core\Domain\Model\RouteSettings;
use VendGuard\Core\Domain\Repository\RouteSettingsRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PDO implementation for the singleton central base route settings.
 */
final class PdoRouteSettingsRepository implements RouteSettingsRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    public function find(): ?RouteSettings
    {
        $stmt = $this->pdo->query("
            SELECT `base_name`, `base_address`, `base_latitude`, `base_longitude`,
                   `operational_radius_km`, `min_latitude`, `max_latitude`,
                   `min_longitude`, `max_longitude`
            FROM `route_settings`
            WHERE `id` = 1
            LIMIT 1
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new RouteSettings(
            (string)$row['base_name'],
            (string)$row['base_address'],
            (float)$row['base_latitude'],
            (float)$row['base_longitude'],
            (int)$row['operational_radius_km'],
            (float)$row['min_latitude'],
            (float)$row['max_latitude'],
            (float)$row['min_longitude'],
            (float)$row['max_longitude']
        );
    }

    public function update(RouteSettings $settings): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE `route_settings`
            SET `base_name` = :base_name,
                `base_address` = :base_address,
                `base_latitude` = :base_latitude,
                `base_longitude` = :base_longitude,
                `operational_radius_km` = :operational_radius_km,
                `min_latitude` = :min_latitude,
                `max_latitude` = :max_latitude,
                `min_longitude` = :min_longitude,
                `max_longitude` = :max_longitude,
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = 1
        ");

        return $stmt->execute([
            ':base_name' => $settings->getBaseName(),
            ':base_address' => $settings->getBaseAddress(),
            ':base_latitude' => $settings->getBaseLatitude(),
            ':base_longitude' => $settings->getBaseLongitude(),
            ':operational_radius_km' => $settings->getOperationalRadiusKm(),
            ':min_latitude' => $settings->getMinLatitude(),
            ':max_latitude' => $settings->getMaxLatitude(),
            ':min_longitude' => $settings->getMinLongitude(),
            ':max_longitude' => $settings->getMaxLongitude(),
        ]);
    }
}
