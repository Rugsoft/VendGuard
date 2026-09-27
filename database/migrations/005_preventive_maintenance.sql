-- =============================================================================
-- VendGuard Módulo 05: Mantenimiento Preventivo y Checklists Sanitarios (M1)
-- Migración DDL: 005_preventive_maintenance.sql
-- =============================================================================
-- Cumplimiento Constitucional:
-- - Artículo II: Seguridad Alimentaria y Control de Cadena de Frío
-- - Artículo III: Prohibición de Borrado Físico (soft delete con deleted_at)
-- - Artículo V.4: Código Operador Técnico (operator_code) protegiendo privacidad
-- =============================================================================

-- 1. Ampliación de tabla USERS: Código de Operador Técnico Oficial (Art. V.4)
ALTER TABLE `users`
ADD COLUMN IF NOT EXISTS `operator_code` VARCHAR(20) NULL AFTER `role`;

-- Asignar códigos de operador a técnicos preexistentes si no lo tienen
UPDATE `users`
SET `operator_code` = CONCAT('OP-', LPAD(`id`, 2, '0'))
WHERE `role` = 'TECHNICIAN' AND (`operator_code` IS NULL OR `operator_code` = '');

-- 2. Ampliación de tabla MACHINES: Semáforo higiénico, frecuencias y pausa estacional
ALTER TABLE `machines`
ADD COLUMN IF NOT EXISTS `sanitary_status` ENUM('OK', 'ATTENTION_REQUIRED', 'EXPIRED', 'QUARANTINE', 'SEASONAL_PAUSE') NOT NULL DEFAULT 'OK' AFTER `is_active`,
ADD COLUMN IF NOT EXISTS `sanitary_frequency_days` INT UNSIGNED NULL AFTER `sanitary_status`,
ADD COLUMN IF NOT EXISTS `last_sanitary_inspection_at` DATETIME NULL AFTER `sanitary_frequency_days`,
ADD COLUMN IF NOT EXISTS `next_sanitary_inspection_due` DATE NULL AFTER `last_sanitary_inspection_at`,
ADD COLUMN IF NOT EXISTS `is_seasonal_pause` TINYINT(1) NOT NULL DEFAULT 0 AFTER `next_sanitary_inspection_due`,
ADD COLUMN IF NOT EXISTS `seasonal_pause_reason` TEXT NULL AFTER `is_seasonal_pause`,
ADD COLUMN IF NOT EXISTS `seasonal_pause_until` DATE NULL AFTER `seasonal_pause_reason`;

-- Backfill inicial de next_sanitary_inspection_due para máquinas existentes según tipología
UPDATE `machines`
SET `next_sanitary_inspection_due` = CASE
  WHEN `machine_type` = 'PERISHABLE_FOOD' THEN DATE_ADD(COALESCE(`created_at`, NOW()), INTERVAL 15 DAY)
  WHEN `machine_type` = 'HOT_DRINKS' THEN DATE_ADD(COALESCE(`created_at`, NOW()), INTERVAL 30 DAY)
  WHEN `machine_type` = 'COLD_DRINKS' THEN DATE_ADD(COALESCE(`created_at`, NOW()), INTERVAL 45 DAY)
  WHEN `machine_type` = 'SNACKS' THEN DATE_ADD(COALESCE(`created_at`, NOW()), INTERVAL 60 DAY)
  WHEN `machine_type` = 'COMBO' THEN DATE_ADD(COALESCE(`created_at`, NOW()), INTERVAL 15 DAY)
  ELSE DATE_ADD(COALESCE(`created_at`, NOW()), INTERVAL 30 DAY)
END
WHERE `next_sanitary_inspection_due` IS NULL;

-- 3. Tabla de Configuración de Frecuencias Sanitarias por Tipología (Art. II)
CREATE TABLE IF NOT EXISTS `preventive_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `machine_type` ENUM('PERISHABLE_FOOD', 'HOT_DRINKS', 'COLD_DRINKS', 'SNACKS', 'COMBO') NOT NULL,
    `default_frequency_days` INT UNSIGNED NOT NULL,
    `max_allowed_days` INT UNSIGNED NOT NULL,
    `advance_warning_days` INT UNSIGNED NOT NULL DEFAULT 5,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_prev_settings_type` (`machine_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Semillas normativas por defecto (Blindaje Art. II: perecederos máximo 15 días)
INSERT INTO `preventive_settings` (`machine_type`, `default_frequency_days`, `max_allowed_days`, `advance_warning_days`)
VALUES
  ('PERISHABLE_FOOD', 15, 15, 5),
  ('HOT_DRINKS',       30, 60, 5),
  ('COLD_DRINKS',      45, 90, 5),
  ('SNACKS',           60, 90, 5),
  ('COMBO',            15, 45, 5)
ON DUPLICATE KEY UPDATE
  `default_frequency_days` = VALUES(`default_frequency_days`),
  `max_allowed_days` = VALUES(`max_allowed_days`),
  `advance_warning_days` = VALUES(`advance_warning_days`);

-- 4. Tabla Principal de Órdenes de Mantenimiento Preventivo (Art. III)
CREATE TABLE IF NOT EXISTS `preventive_orders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_code` VARCHAR(30) NOT NULL,
    `machine_id` INT UNSIGNED NOT NULL,
    `location_id` INT UNSIGNED NOT NULL,
    `assigned_technician_id` INT UNSIGNED NULL DEFAULT NULL,
    `status` ENUM('PENDING_ASSIGNMENT', 'SCHEDULED', 'IN_INSPECTION', 'COMPLETED', 'EXPIRED', 'CANCELLED') NOT NULL DEFAULT 'PENDING_ASSIGNMENT',
    `order_type` ENUM('ROUTINE', 'REINSPECTION', 'MANUAL_EXTRA') NOT NULL DEFAULT 'ROUTINE',
    `scheduled_date` DATE NOT NULL,
    `due_date` DATE NOT NULL,
    `started_at` DATETIME NULL DEFAULT NULL,
    `completed_at` DATETIME NULL DEFAULT NULL,
    `temperature_measured` DECIMAL(3,1) NULL DEFAULT NULL,
    `result` ENUM('CONFORME', 'CONFORME_CON_OBSERVACIONES', 'NO_CONFORME', 'NO_EVALUABLE_POR_CAUSA_EXTERNA') NULL DEFAULT NULL,
    `linked_incident_id` INT UNSIGNED NULL DEFAULT NULL,
    `is_quarantine_triggered` TINYINT(1) NOT NULL DEFAULT 0,
    `notes` TEXT NULL,
    `cancellation_reason` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    UNIQUE KEY `uq_prev_order_code` (`order_code`),
    INDEX `idx_prev_orders_status_due` (`status`, `due_date`),
    INDEX `idx_prev_orders_machine` (`machine_id`),
    INDEX `idx_prev_orders_technician` (`assigned_technician_id`),
    CONSTRAINT `fk_prev_orders_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_prev_orders_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_prev_orders_technician` FOREIGN KEY (`assigned_technician_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabla de Respuestas a Ítems del Checklist Normativo
CREATE TABLE IF NOT EXISTS `preventive_order_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `preventive_order_id` INT UNSIGNED NOT NULL,
    `item_code` VARCHAR(50) NOT NULL,
    `item_description` VARCHAR(255) NOT NULL,
    `is_critical` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('PASS', 'WARN', 'FAIL', 'NOT_APPLICABLE') NOT NULL,
    `observations` TEXT NULL,
    `photo_path` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_order_items_order_critical` (`preventive_order_id`, `is_critical`),
    CONSTRAINT `fk_order_items_order` FOREIGN KEY (`preventive_order_id`) REFERENCES `preventive_orders` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabla de Certificados Oficiales de Inspección Sanitaria (Art. V.4)
CREATE TABLE IF NOT EXISTS `sanitary_certificates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `certificate_code` VARCHAR(40) NOT NULL,
    `preventive_order_id` INT UNSIGNED NOT NULL,
    `machine_id` INT UNSIGNED NOT NULL,
    `location_id` INT UNSIGNED NOT NULL,
    `technician_id` INT UNSIGNED NOT NULL,
    `technician_name` VARCHAR(150) NOT NULL,
    `technician_operator_code` VARCHAR(30) NOT NULL,
    `inspection_date` DATETIME NOT NULL,
    `valid_until` DATE NOT NULL,
    `temperature_measured` DECIMAL(3,1) NULL DEFAULT NULL,
    `result` ENUM('CONFORME', 'CONFORME_CON_OBSERVACIONES') NOT NULL,
    `status` ENUM('VALID', 'SUSPENDED', 'REVOKED') NOT NULL DEFAULT 'VALID',
    `suspended_reason` TEXT NULL,
    `suspended_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    UNIQUE KEY `uq_certificates_code` (`certificate_code`),
    INDEX `idx_certificates_machine_status` (`machine_id`, `status`),
    INDEX `idx_certificates_location` (`location_id`),
    CONSTRAINT `fk_certificates_prev_order` FOREIGN KEY (`preventive_order_id`) REFERENCES `preventive_orders` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_certificates_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_certificates_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_certificates_technician` FOREIGN KEY (`technician_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Ampliación de INCIDENTS: Vínculo con Orden Preventiva
ALTER TABLE `incidents`
ADD COLUMN IF NOT EXISTS `preventive_order_id` INT UNSIGNED NULL AFTER `machine_type_snapshot`;

-- 8. Ampliación de AUDIT_LOG: Tipos de Entidad Preventivos
ALTER TABLE `audit_log`
MODIFY COLUMN `entity_type` ENUM('TICKET', 'MACHINE', 'LOCATION', 'USER', 'PREVENTIVE_ORDER', 'SANITARY_CERTIFICATE') NOT NULL;
