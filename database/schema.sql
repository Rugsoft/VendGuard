-- =============================================================================
-- VENDGUARD MVP: DDL DE BASE DE DATOS (MariaDB 10.4+ / MySQL 8.0+)
-- Documento de referencia: specs/technical/database_schema.md
-- Normativa: constitution.md (Art. II, III, IV, V) y mvp_functional_spec.md
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `vendguard_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `vendguard_db`;

-- Desactivar temporalmente comprobación de claves foráneas para recreación limpia
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
  -- Columna virtual para blindar a nivel de motor de BD la regla constitucional de duplicados:
  `is_active_ticket` TINYINT GENERATED ALWAYS AS (
    IF(`status` IN ('CLOSED', 'CANCELLED'), NULL, 1)
  ) VIRTUAL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_incidents_ticket_code` (`ticket_code`),
  -- Índice único condicional: garantiza como máximo 1 solo ticket activo por máquina a la vez:
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
-- 7. TABLA: audit_log (Auditoría Inmutable de Sistema, Máquinas, Sedes y Personal)
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

