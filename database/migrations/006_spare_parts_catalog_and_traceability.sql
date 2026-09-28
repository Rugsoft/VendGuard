-- =============================================================================
-- VendGuard Módulo 06 (M2): Catálogo de Repuestos y Trazabilidad de Piezas
-- Migración DDL: 006_spare_parts_catalog_and_traceability.sql
-- =============================================================================
-- Cumplimiento Constitucional:
-- - Artículo II: Principio de Precaución y Seguridad Alimentaria
-- - Artículo III: Prohibición de Borrado Físico (soft delete y snapshots inmutables)
-- - Artículo IV: SQL nativo estricto para MariaDB/MySQL
-- - Artículo V.4: Segregación total de datos frente a usuarios de sede
-- - Artículo VI: Clasificación cerrada (DESGUACE / TALLER) sin feature creep
-- =============================================================================

-- 1. Tabla de Catálogo Maestro de Repuestos (Art. III: soft delete con deleted_at)
CREATE TABLE IF NOT EXISTS `spare_parts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `part_code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `category` ENUM(
        'HYDRAULIC',
        'THERMAL',
        'ELECTRONIC',
        'MECHANICAL',
        'PAYMENT_SYSTEM',
        'CONSUMABLE',
        'OTHER'
    ) NOT NULL DEFAULT 'OTHER',
    `manufacturer` VARCHAR(100) NOT NULL,
    `reference_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `notes` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    UNIQUE KEY `uq_spare_parts_part_code` (`part_code`),
    INDEX `idx_spare_parts_active_cat` (`is_active`, `category`),
    INDEX `idx_spare_parts_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla de Compatibilidad Repuesto - Modelo de Máquina (RF-REP-01)
CREATE TABLE IF NOT EXISTS `spare_part_compatibilities` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `spare_part_id` INT UNSIGNED NOT NULL,
    `machine_model` VARCHAR(100) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    UNIQUE KEY `uq_part_model` (`spare_part_id`, `machine_model`),
    INDEX `idx_part_compat_model` (`machine_model`),
    INDEX `idx_part_compat_active` (`spare_part_id`, `is_active`),
    CONSTRAINT `fk_compat_spare_part` FOREIGN KEY (`spare_part_id`)
        REFERENCES `spare_parts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabla de Solicitudes de Repuesto en Pausa Técnica (RF-REP-03, RF-REP-04)
CREATE TABLE IF NOT EXISTS `spare_part_requests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `incident_id` INT UNSIGNED NOT NULL,
    `spare_part_id` INT UNSIGNED NULL DEFAULT NULL,
    `is_out_of_catalog` TINYINT(1) NOT NULL DEFAULT 0,
    `custom_part_description` TEXT NULL DEFAULT NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
    `status` ENUM('PENDING', 'ATTENDED', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
    `requested_by_user_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_requests_incident` (`incident_id`),
    INDEX `idx_requests_status` (`status`),
    INDEX `idx_requests_part` (`spare_part_id`),
    CONSTRAINT `fk_requests_incident` FOREIGN KEY (`incident_id`)
        REFERENCES `incidents` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_requests_spare_part` FOREIGN KEY (`spare_part_id`)
        REFERENCES `spare_parts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_requests_user` FOREIGN KEY (`requested_by_user_id`)
        REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabla de Consumo y Trazabilidad de Piezas Sustituidas (RF-REP-06, RF-REP-07)
CREATE TABLE IF NOT EXISTS `incident_replaced_parts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `intervention_type` ENUM('INCIDENT', 'PREVENTIVE') NOT NULL,
    `incident_id` INT UNSIGNED NULL DEFAULT NULL,
    `preventive_order_id` INT UNSIGNED NULL DEFAULT NULL,
    `machine_id` INT UNSIGNED NOT NULL,
    `location_id` INT UNSIGNED NOT NULL,
    `technician_id` INT UNSIGNED NOT NULL,
    `spare_part_id` INT UNSIGNED NULL DEFAULT NULL,
    `is_out_of_catalog` TINYINT(1) NOT NULL DEFAULT 0,
    `custom_part_name` VARCHAR(255) NULL DEFAULT NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
    `unit_cost_snapshot` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total_cost_snapshot` DECIMAL(10,2) GENERATED ALWAYS AS (`quantity` * `unit_cost_snapshot`) STORED,
    `old_part_destination` ENUM('DESGUACE', 'TALLER') NOT NULL,
    `notes` TEXT NULL DEFAULT NULL,
    `installed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_replaced_machine_part_date` (`machine_id`, `spare_part_id`, `installed_at`),
    INDEX `idx_replaced_incident` (`incident_id`),
    INDEX `idx_replaced_preventive` (`preventive_order_id`),
    INDEX `idx_replaced_location_date` (`location_id`, `installed_at`),
    INDEX `idx_replaced_technician` (`technician_id`),
    CONSTRAINT `fk_replaced_incident` FOREIGN KEY (`incident_id`)
        REFERENCES `incidents` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_replaced_preventive` FOREIGN KEY (`preventive_order_id`)
        REFERENCES `preventive_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_replaced_machine` FOREIGN KEY (`machine_id`)
        REFERENCES `machines` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_replaced_location` FOREIGN KEY (`location_id`)
        REFERENCES `locations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_replaced_technician` FOREIGN KEY (`technician_id`)
        REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_replaced_spare_part` FOREIGN KEY (`spare_part_id`)
        REFERENCES `spare_parts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Semillas Normativas Iniciales de Repuestos del Catálogo Maestro
INSERT INTO `spare_parts` (`part_code`, `name`, `category`, `manufacturer`, `reference_cost`, `is_active`, `notes`)
VALUES
    ('VALV-ULKA-01', 'Electroválvula 24V 2 Vías Ulka', 'HYDRAULIC', 'Ulka', 28.50, 1, 'Válvula de entrada de agua para calderas espresso'),
    ('BOMB-VIB-02',  'Bomba Vibratoria 230V EX5', 'HYDRAULIC', 'Ulka / CEME', 36.00, 1, 'Bomba de presión estándar para café'),
    ('SOND-NTC-01',  'Sonda Térmica NTC 10K Roscada', 'THERMAL', 'Carel', 15.20, 1, 'Sonda de temperatura para cámaras refrigeradas'),
    ('TERM-SEG-01',  'Termostato de Seguridad 135°C', 'THERMAL', 'Campini', 12.80, 1, 'Rearme manual de seguridad para grupo térmico'),
    ('MOT-ESP-01',   'Motor Extractor de Espiral 24V DC', 'MECHANICAL', 'Sande / Merkle', 24.50, 1, 'Motor con microinterruptor de posición'),
    ('MON-CASH-01',  'Monedero Selector de Monedas NRI G13', 'PAYMENT_SYSTEM', 'Crane / CPI', 185.00, 1, 'Validador estándar MDB multimoneda'),
    ('DISP-LCD-01',  'Display LCD Gráfico 128x64 Azul', 'ELECTRONIC', 'Winstar', 42.00, 1, 'Pantalla frontal de selección de usuario'),
    ('JUNT-TOR-01',  'Kit 10 Juntas Tóricas Silicona Alimentaria', 'CONSUMABLE', 'Parker', 8.50, 1, 'Juntas de estanqueidad para grupo de café y pistón')
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `category` = VALUES(`category`),
    `manufacturer` = VALUES(`manufacturer`),
    `reference_cost` = VALUES(`reference_cost`),
    `is_active` = VALUES(`is_active`);

-- 6. Semillas de Compatibilidad por Modelo de Máquina
INSERT INTO `spare_part_compatibilities` (`spare_part_id`, `machine_model`)
SELECT sp.id, m.model
FROM `spare_parts` sp
CROSS JOIN (
    SELECT 'Sanden Vendo G-Drink' AS model
    UNION SELECT 'Bianchi Gaia Espresso'
    UNION SELECT 'Necta Samba Combo'
    UNION SELECT 'Azkoyen Palma+'
    UNION SELECT 'Fas Fast 900'
    UNION SELECT 'Fas Perla'
) m
WHERE 
    (sp.part_code IN ('SOND-NTC-01', 'MOT-ESP-01', 'MON-CASH-01') AND m.model IN ('Sanden Vendo G-Drink', 'Necta Samba Combo', 'Azkoyen Palma+', 'Fas Fast 900'))
    OR
    (sp.part_code IN ('VALV-ULKA-01', 'BOMB-VIB-02', 'TERM-SEG-01', 'JUNT-TOR-01', 'MON-CASH-01') AND m.model IN ('Bianchi Gaia Espresso', 'Fas Perla', 'Azkoyen Palma+'))
    OR
    (sp.part_code = 'DISP-LCD-01')
ON DUPLICATE KEY UPDATE `machine_model` = VALUES(`machine_model`);
