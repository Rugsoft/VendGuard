-- =============================================================================
-- VendGuard MVP: Inicialización Completa para Bases de Datos en la Nube
-- (TiDB Cloud, Aiven, Alwaysdata, Clever Cloud, MySQL 8.0+ / MariaDB 10.4+)
-- =============================================================================
-- Este fichero contiene el esquema completo DDL y las semillas iniciales.
-- No fuerza la creación de base de datos para funcionar en bases de datos asignadas.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `incident_comments`;
DROP TABLE IF EXISTS `incident_history`;
DROP TABLE IF EXISTS `incidents`;
DROP TABLE IF EXISTS `machines`;
DROP TABLE IF EXISTS `locations`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- 1. TABLA: locations (Sedes de Clientes)
-- -----------------------------------------------------------------------------
CREATE TABLE `locations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_code` VARCHAR(32) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `address` VARCHAR(255) NOT NULL,
  `contact_name` VARCHAR(100) NULL,
  `contact_phone` VARCHAR(30) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_locations_site_code` (`site_code`),
  INDEX `idx_locations_active` (`is_active`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. TABLA: machines (Parque de Máquinas)
-- -----------------------------------------------------------------------------
CREATE TABLE `machines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `location_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `model` VARCHAR(100) NOT NULL,
  `machine_type` ENUM('HOT_DRINKS', 'COLD_DRINKS', 'SNACKS', 'PERISHABLE_FOOD', 'COMBO') NOT NULL,
  `floor_wing` VARCHAR(100) NOT NULL,
  `notes` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_machines_code` (`code`),
  INDEX `idx_machines_location` (`location_id`),
  INDEX `idx_machines_type` (`machine_type`),
  CONSTRAINT `fk_machines_location` FOREIGN KEY (`location_id`)
    REFERENCES `locations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. TABLA: users (Personal Interno: Coordinadores y Técnicos)
-- -----------------------------------------------------------------------------
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('COORDINATOR', 'TECHNICIAN') NOT NULL,
  `phone` VARCHAR(30) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  INDEX `idx_users_role` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. TABLA: incidents (Núcleo Operativo del Gestor de Incidencias)
-- -----------------------------------------------------------------------------
CREATE TABLE `incidents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_code` VARCHAR(32) NOT NULL,
  `machine_id` INT UNSIGNED NOT NULL,
  `machine_type_snapshot` ENUM('HOT_DRINKS', 'COLD_DRINKS', 'SNACKS', 'PERISHABLE_FOOD', 'COMBO') NULL,
  `location_id` INT UNSIGNED NOT NULL,
  `assigned_technician_id` INT UNSIGNED NULL DEFAULT NULL,
  `reporter_name` VARCHAR(100) NULL,
  `reporter_phone` VARCHAR(30) NULL,
  `category` ENUM('TEMPERATURE_COLD', 'PAYMENT_SYSTEM', 'PRODUCT_JAM', 'ELECTRICAL_OFF', 'OTHER') NOT NULL,
  `description` TEXT NOT NULL,
  `retained_money_amount` DECIMAL(10,2) NULL DEFAULT NULL,
  `photo_path` VARCHAR(255) NULL DEFAULT NULL,
  `urgency` ENUM('LOW', 'MEDIUM', 'HIGH', 'CRITICAL') NOT NULL,
  `status` ENUM('REGISTERED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 'RESOLVED', 'REOPENED', 'CLOSED', 'CANCELLED') NOT NULL DEFAULT 'REGISTERED',
  `assigned_at` TIMESTAMP NULL DEFAULT NULL,
  `started_at` TIMESTAMP NULL DEFAULT NULL,
  `pending_parts_reason` TEXT NULL DEFAULT NULL,
  `resolution_diagnosis` TEXT NULL DEFAULT NULL,
  `resolution_action` TEXT NULL DEFAULT NULL,
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  `reopen_reason` TEXT NULL DEFAULT NULL,
  `reopened_at` TIMESTAMP NULL DEFAULT NULL,
  `closed_at` TIMESTAMP NULL DEFAULT NULL,
  `cancellation_reason` TEXT NULL DEFAULT NULL,
  `cancelled_at` TIMESTAMP NULL DEFAULT NULL,
  `is_active_ticket` TINYINT GENERATED ALWAYS AS (
    IF(`status` IN ('CLOSED', 'CANCELLED'), NULL, 1)
  ) VIRTUAL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_incidents_ticket_code` (`ticket_code`),
  UNIQUE KEY `uq_machine_active_ticket` (`machine_id`, `is_active_ticket`),
  INDEX `idx_incidents_location` (`location_id`),
  INDEX `idx_incidents_technician` (`assigned_technician_id`),
  INDEX `idx_incidents_status_urgency` (`status`, `urgency`),
  INDEX `idx_incidents_created_at` (`created_at`),
  CONSTRAINT `fk_incidents_machine` FOREIGN KEY (`machine_id`)
    REFERENCES `machines` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_incidents_location` FOREIGN KEY (`location_id`)
    REFERENCES `locations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_incidents_technician` FOREIGN KEY (`assigned_technician_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. TABLA: incident_history (Auditoría Inmutable de Estados)
-- -----------------------------------------------------------------------------
CREATE TABLE `incident_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `incident_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NULL DEFAULT NULL,
  `from_status` VARCHAR(32) NULL,
  `to_status` VARCHAR(32) NOT NULL,
  `action_note` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_history_incident` (`incident_id`, `created_at`),
  CONSTRAINT `fk_history_incident` FOREIGN KEY (`incident_id`)
    REFERENCES `incidents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_history_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. TABLA: incident_comments (Bitácora de Evidencias y Mensajes)
-- -----------------------------------------------------------------------------
CREATE TABLE `incident_comments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `incident_id` INT UNSIGNED NOT NULL,
  `author_type` ENUM('REPORTER', 'TECHNICIAN', 'COORDINATOR', 'SYSTEM') NOT NULL,
  `user_id` INT UNSIGNED NULL DEFAULT NULL,
  `author_name` VARCHAR(100) NOT NULL,
  `comment_text` TEXT NOT NULL,
  `photo_path` VARCHAR(255) NULL DEFAULT NULL,
  `is_internal` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_comments_incident` (`incident_id`, `created_at`),
  CONSTRAINT `fk_comments_incident` FOREIGN KEY (`incident_id`)
    REFERENCES `incidents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. TABLA: audit_log (Auditoría Inmutable de Sistema, Máquinas y Sedes - RF-05)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` ENUM('TICKET', 'MACHINE', 'LOCATION', 'USER') NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(64) NOT NULL,
  `user_id` INT UNSIGNED NULL DEFAULT NULL,
  `user_role` VARCHAR(32) NOT NULL,
  `user_name` VARCHAR(100) NOT NULL,
  `previous_state` JSON NULL DEFAULT NULL,
  `new_state` JSON NOT NULL,
  `metadata` JSON NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
  INDEX `idx_audit_action` (`action`),
  INDEX `idx_audit_user` (`user_id`),
  INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- CARGA DE DATOS SEMILLA (SEEDS)
-- =============================================================================

INSERT INTO `locations` (`site_code`, `name`, `address`, `contact_name`, `contact_phone`)
VALUES
  ('SEDE-BCN-01', 'Hospital del Mar - Edificio Central', 'Passeig Marítim 25, Barcelona', 'Laura Sanitaria', '600111222'),
  ('SEDE-BCN-02', 'Torre Glòries - Planta 4 Oficinas', 'Avinguda Diagonal 211, Barcelona', 'Marc Recepción', '600333444')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `address` = VALUES(`address`),
  `contact_name` = VALUES(`contact_name`),
  `contact_phone` = VALUES(`contact_phone`),
  `deleted_at` = NULL;

INSERT INTO `machines` (`location_id`, `code`, `model`, `machine_type`, `floor_wing`, `notes`)
VALUES
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01'), 'VEND-0101', 'Sanden Vendo G-Drink', 'PERISHABLE_FOOD', 'Planta Baja - Urgencias', 'Máquina de sándwiches y lácteos frescos'),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01'), 'VEND-0102', 'Bianchi Gaia Espresso', 'HOT_DRINKS', 'Planta 1 - Sala Médica', 'Café en grano y bebidas calientes'),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-02'), 'VEND-0201', 'Necta Samba Combo', 'COMBO', 'Planta 4 - Office Este', 'Snacks y refrescos variados')
ON DUPLICATE KEY UPDATE
  `location_id` = VALUES(`location_id`),
  `model` = VALUES(`model`),
  `machine_type` = VALUES(`machine_type`),
  `floor_wing` = VALUES(`floor_wing`),
  `notes` = VALUES(`notes`),
  `deleted_at` = NULL;

INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `phone`)
VALUES
  ('Sara Coordinadora', 'coordinacion@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'COORDINATOR', '677000111'),
  ('Jordi Técnico Ruta BCN', 'jordi.ruta@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'TECHNICIAN', '677222333')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `password_hash` = VALUES(`password_hash`),
  `role` = VALUES(`role`),
  `phone` = VALUES(`phone`),
  `deleted_at` = NULL;
