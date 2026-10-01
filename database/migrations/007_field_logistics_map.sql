-- =============================================================================
-- VendGuard Module 07: Field Logistics Map
-- Migration: 007_field_logistics_map.sql
-- Adds validated site coordinates and the central route settings record.
-- =============================================================================

-- Add location coordinates only when absent (portable across MySQL and MariaDB).
SET @vg_map_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'locations' AND column_name = 'latitude') = 0,
    'ALTER TABLE `locations` ADD COLUMN `latitude` DECIMAL(10, 7) NOT NULL DEFAULT 41.3850640 AFTER `address`',
    'SELECT 1'
);
PREPARE vg_map_statement FROM @vg_map_ddl;
EXECUTE vg_map_statement;
DEALLOCATE PREPARE vg_map_statement;

SET @vg_map_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'locations' AND column_name = 'longitude') = 0,
    'ALTER TABLE `locations` ADD COLUMN `longitude` DECIMAL(11, 7) NOT NULL DEFAULT 2.1734035 AFTER `latitude`',
    'SELECT 1'
);
PREPARE vg_map_statement FROM @vg_map_ddl;
EXECUTE vg_map_statement;
DEALLOCATE PREPARE vg_map_statement;

-- Create the coordinate index only once.
SET @vg_map_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'locations' AND index_name = 'idx_locations_lat_lng') = 0,
    'CREATE INDEX `idx_locations_lat_lng` ON `locations` (`latitude`, `longitude`, `is_active`)',
    'SELECT 1'
);
PREPARE vg_map_statement FROM @vg_map_ddl;
EXECUTE vg_map_statement;
DEALLOCATE PREPARE vg_map_statement;

CREATE TABLE IF NOT EXISTS `route_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `base_name` VARCHAR(100) NOT NULL DEFAULT 'Base Central VendGuard Barcelona',
    `base_address` VARCHAR(255) NOT NULL DEFAULT 'Carrer de la Marina 100, 08018 Barcelona',
    `base_latitude` DECIMAL(10, 7) NOT NULL DEFAULT 41.3935000,
    `base_longitude` DECIMAL(11, 7) NOT NULL DEFAULT 2.1890000,
    `operational_radius_km` INT UNSIGNED NOT NULL DEFAULT 100,
    `min_latitude` DECIMAL(10, 7) NOT NULL DEFAULT 27.0000000,
    `max_latitude` DECIMAL(10, 7) NOT NULL DEFAULT 44.5000000,
    `min_longitude` DECIMAL(11, 7) NOT NULL DEFAULT -18.5000000,
    `max_longitude` DECIMAL(11, 7) NOT NULL DEFAULT 5.0000000,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keep the singleton row idempotent without overwriting coordinator settings.
INSERT INTO `route_settings` (`id`, `base_name`, `base_address`, `base_latitude`, `base_longitude`)
VALUES (1, 'Base Central VendGuard Barcelona', 'Carrer de la Marina 100, 08018 Barcelona', 41.3935000, 2.1890000)
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

-- Backfill canonical seed locations that still have the migration placeholder coordinates.
UPDATE `locations`
SET `latitude` = 41.3853120, `longitude` = 2.1932450
WHERE `site_code` = 'SEDE-BCN-01'
  AND `latitude` = 41.3850640 AND `longitude` = 2.1734035;

UPDATE `locations`
SET `latitude` = 41.4036290, `longitude` = 2.1895120
WHERE `site_code` = 'SEDE-BCN-02'
  AND `latitude` = 41.3850640 AND `longitude` = 2.1734035;
