-- =============================================================================
-- VendGuard MVP: Inicialización Completa para Bases de Datos en la Nube
-- (TiDB Cloud, Aiven, Alwaysdata, Clever Cloud, MySQL 8.0+ / MariaDB 10.4+)
-- =============================================================================
-- Este fichero contiene el esquema completo DDL y las semillas iniciales.
-- No fuerza la creación de base de datos para funcionar en bases de datos asignadas.
-- Incluye: Módulo 01-04 (Correctivo y Admin) y Módulo 05 (Preventivo y Sanitario M1).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `sanitary_certificates`;
DROP TABLE IF EXISTS `preventive_order_items`;
DROP TABLE IF EXISTS `preventive_orders`;
DROP TABLE IF EXISTS `preventive_settings`;
DROP TABLE IF EXISTS `incident_comments`;
DROP TABLE IF EXISTS `incident_history`;
DROP TABLE IF EXISTS `incidents`;
DROP TABLE IF EXISTS `machines`;
DROP TABLE IF EXISTS `locations`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `audit_log`;

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
  `sanitary_status` ENUM('OK', 'ATTENTION_REQUIRED', 'EXPIRED', 'QUARANTINE', 'SEASONAL_PAUSE') NOT NULL DEFAULT 'OK',
  `sanitary_frequency_days` INT UNSIGNED NULL DEFAULT NULL,
  `last_sanitary_inspection_at` DATETIME NULL DEFAULT NULL,
  `next_sanitary_inspection_due` DATE NULL DEFAULT NULL,
  `is_seasonal_pause` TINYINT(1) NOT NULL DEFAULT 0,
  `seasonal_pause_reason` TEXT NULL DEFAULT NULL,
  `seasonal_pause_until` DATE NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_machines_code` (`code`),
  INDEX `idx_machines_location` (`location_id`),
  INDEX `idx_machines_type` (`machine_type`),
  INDEX `idx_machines_sanitary_status` (`sanitary_status`),
  INDEX `idx_machines_sanitary_due` (`next_sanitary_inspection_due`),
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
  `operator_code` VARCHAR(20) NULL DEFAULT NULL,
  `phone` VARCHAR(30) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_operator_code` (`operator_code`),
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
  `preventive_order_id` INT UNSIGNED NULL DEFAULT NULL,
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
  `entity_type` ENUM('TICKET', 'MACHINE', 'LOCATION', 'USER', 'PREVENTIVE_ORDER', 'SANITARY_CERTIFICATE') NOT NULL,
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

-- -----------------------------------------------------------------------------
-- 8. TABLA: preventive_settings (Frecuencias Sanitarias - M1 / Art. II)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- 9. TABLA: preventive_orders (Órdenes de Inspección Preventiva - M1)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- 10. TABLA: preventive_order_items (Ítems y Checklists Normativos - M1)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- 11. TABLA: sanitary_certificates (Certificados Sanitarios Oficiales - M1)
-- -----------------------------------------------------------------------------
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

INSERT INTO `machines` (`location_id`, `code`, `model`, `machine_type`, `floor_wing`, `notes`, `sanitary_status`, `next_sanitary_inspection_due`)
VALUES
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01'), 'VEND-0101', 'Sanden Vendo G-Drink', 'PERISHABLE_FOOD', 'Planta Baja - Urgencias', 'Máquina de sándwiches y lácteos frescos', 'OK', DATE_ADD(CURRENT_DATE(), INTERVAL 15 DAY)),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01'), 'VEND-0102', 'Bianchi Gaia Espresso', 'HOT_DRINKS', 'Planta 1 - Sala Médica', 'Café en grano y bebidas calientes', 'OK', DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)),
  ((SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-02'), 'VEND-0201', 'Necta Samba Combo', 'COMBO', 'Planta 4 - Office Este', 'Snacks y refrescos variados', 'OK', DATE_ADD(CURRENT_DATE(), INTERVAL 15 DAY))
ON DUPLICATE KEY UPDATE
  `location_id` = VALUES(`location_id`),
  `model` = VALUES(`model`),
  `machine_type` = VALUES(`machine_type`),
  `floor_wing` = VALUES(`floor_wing`),
  `notes` = VALUES(`notes`),
  `sanitary_status` = VALUES(`sanitary_status`),
  `next_sanitary_inspection_due` = VALUES(`next_sanitary_inspection_due`),
  `deleted_at` = NULL;

INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `operator_code`, `phone`)
VALUES
  ('Sara Coordinadora', 'coordinacion@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'COORDINATOR', NULL, '677000111'),
  ('Jordi Técnico Ruta BCN', 'jordi.ruta@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'TECHNICIAN', 'OP-01', '677222333'),
  ('Marta Técnica Ruta BCN', 'marta.ruta@vendguard.internal', '$2y$10$2kYc4PEIpFz0Y.BtbOT05uY2XruBBpA9VvyUMP8DCKjnvB2bHdMwm', 'TECHNICIAN', 'OP-02', '677444555')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `password_hash` = VALUES(`password_hash`),
  `role` = VALUES(`role`),
  `operator_code` = VALUES(`operator_code`),
  `phone` = VALUES(`phone`),
  `deleted_at` = NULL;

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

-- -----------------------------------------------------------------------------
-- Órdenes Preventivas y Certificados Sanitarios Iniciales (Módulo 05)
-- -----------------------------------------------------------------------------
INSERT INTO `preventive_orders` (
  `order_code`, `machine_id`, `location_id`, `assigned_technician_id`,
  `status`, `order_type`, `scheduled_date`, `due_date`, `started_at`, `completed_at`,
  `temperature_measured`, `result`, `is_quarantine_triggered`, `notes`
) VALUES
  (
    'ORD-PREV-2026-0001',
    (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0101' LIMIT 1),
    (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01' LIMIT 1),
    (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1),
    'COMPLETED',
    'ROUTINE',
    DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY),
    CURRENT_DATE(),
    DATE_SUB(NOW(), INTERVAL 1 DAY),
    DATE_SUB(NOW(), INTERVAL 1 DAY),
    3.2,
    'CONFORME',
    0,
    'Inspección higiénico-sanitaria inicial conforme. Temperatura en rango seguro (Art. II).'
  ),
  (
    'ORD-PREV-2026-0002',
    (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0201' LIMIT 1),
    (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-02' LIMIT 1),
    (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1),
    'COMPLETED',
    'ROUTINE',
    DATE_SUB(CURRENT_DATE(), INTERVAL 2 DAY),
    CURRENT_DATE(),
    DATE_SUB(NOW(), INTERVAL 2 DAY),
    DATE_SUB(NOW(), INTERVAL 2 DAY),
    3.8,
    'CONFORME',
    0,
    'Inspección periódica inicial conforme.'
  ),
  (
    'ORD-PREV-2026-0003',
    (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0102' LIMIT 1),
    (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01' LIMIT 1),
    NULL,
    'PENDING_ASSIGNMENT',
    'ROUTINE',
    DATE_ADD(CURRENT_DATE(), INTERVAL 2 DAY),
    DATE_ADD(CURRENT_DATE(), INTERVAL 2 DAY),
    NULL,
    NULL,
    NULL,
    NULL,
    0,
    'Revisión preventiva inminente pendiente de asignación técnica.'
  )
ON DUPLICATE KEY UPDATE
  `status` = VALUES(`status`),
  `result` = VALUES(`result`);

INSERT INTO `sanitary_certificates` (
  `certificate_code`, `preventive_order_id`, `machine_id`, `location_id`,
  `technician_id`, `technician_name`, `technician_operator_code`,
  `inspection_date`, `valid_until`, `temperature_measured`,
  `result`, `status`
) VALUES
  (
    'CERT-2026-0001',
    (SELECT `id` FROM `preventive_orders` WHERE `order_code` = 'ORD-PREV-2026-0001' LIMIT 1),
    (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0101' LIMIT 1),
    (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01' LIMIT 1),
    (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1),
    'Jordi Técnico Ruta BCN',
    'OP-01',
    DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY),
    DATE_ADD(CURRENT_DATE(), INTERVAL 14 DAY),
    3.2,
    'CONFORME',
    'VALID'
  ),
  (
    'CERT-2026-0002',
    (SELECT `id` FROM `preventive_orders` WHERE `order_code` = 'ORD-PREV-2026-0002' LIMIT 1),
    (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0201' LIMIT 1),
    (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-02' LIMIT 1),
    (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1),
    'Jordi Técnico Ruta BCN',
    'OP-01',
    DATE_SUB(CURRENT_DATE(), INTERVAL 2 DAY),
    DATE_ADD(CURRENT_DATE(), INTERVAL 13 DAY),
    3.8,
    'CONFORME',
    'VALID'
  )
ON DUPLICATE KEY UPDATE
  `valid_until` = VALUES(`valid_until`),
  `status` = VALUES(`status`);

UPDATE `machines` SET
  `last_sanitary_inspection_at` = DATE_SUB(NOW(), INTERVAL 1 DAY),
  `next_sanitary_inspection_due` = DATE_ADD(CURRENT_DATE(), INTERVAL 14 DAY),
  `sanitary_status` = 'OK'
WHERE `code` = 'VEND-0101';

UPDATE `machines` SET
  `last_sanitary_inspection_at` = DATE_SUB(NOW(), INTERVAL 2 DAY),
  `next_sanitary_inspection_due` = DATE_ADD(CURRENT_DATE(), INTERVAL 13 DAY),
  `sanitary_status` = 'OK'
WHERE `code` = 'VEND-0201';

UPDATE `machines` SET
  `last_sanitary_inspection_at` = DATE_SUB(NOW(), INTERVAL 28 DAY),
  `next_sanitary_inspection_due` = DATE_ADD(CURRENT_DATE(), INTERVAL 2 DAY),
  `sanitary_status` = 'OK'
WHERE `code` = 'VEND-0102';

-- -----------------------------------------------------------------------------
-- Averías y Reparaciones Históricas de Demostración (Módulo 03 / Métricas)
-- -----------------------------------------------------------------------------
INSERT INTO `incidents` (
  `ticket_code`, `machine_id`, `location_id`, `assigned_technician_id`,
  `reporter_name`, `reporter_phone`, `category`, `description`, `urgency`,
  `status`, `assigned_at`, `started_at`, `resolved_at`, `closed_at`,
  `resolution_diagnosis`, `resolution_action`, `created_at`, `updated_at`
) VALUES
  (
    'INC-DEMO-0810',
    (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0101' LIMIT 1),
    (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01' LIMIT 1),
    (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1),
    'Dra. Carmen Morales', '611223344', 'TEMPERATURE_COLD',
    'Temperatura en cuba de sándwiches a 9.2°C con aviso acústico intermitente en display.',
    'CRITICAL', 'CLOSED',
    DATE_SUB(NOW(), INTERVAL 35 DAY) + INTERVAL 15 MINUTE,
    DATE_SUB(NOW(), INTERVAL 35 DAY) + INTERVAL 40 MINUTE,
    DATE_SUB(NOW(), INTERVAL 35 DAY) + INTERVAL 170 MINUTE,
    DATE_SUB(NOW(), INTERVAL 33 DAY),
    'Sonda de temperatura NTC averiada por pico de tensión registrando falsos 12°C en cuba.',
    'Sustitución de sonda NTC y ajuste de parámetros de histéresis a 3.5°C estables.',
    DATE_SUB(NOW(), INTERVAL 35 DAY),
    DATE_SUB(NOW(), INTERVAL 33 DAY)
  ),
  (
    'INC-DEMO-0822',
    (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0102' LIMIT 1),
    (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01' LIMIT 1),
    (SELECT `id` FROM `users` WHERE `email` = 'marta.ruta@vendguard.internal' LIMIT 1),
    'Enrique Vigilancia', '622334455', 'PRODUCT_JAM',
    'El café no cae en vaso y sale vapor denso por la ranura de erogación.',
    'MEDIUM', 'CLOSED',
    DATE_SUB(NOW(), INTERVAL 32 DAY) + INTERVAL 30 MINUTE,
    DATE_SUB(NOW(), INTERVAL 32 DAY) + INTERVAL 75 MINUTE,
    DATE_SUB(NOW(), INTERVAL 32 DAY) + INTERVAL 255 MINUTE,
    DATE_SUB(NOW(), INTERVAL 30 DAY),
    'Atasco del grupo de infusión de café por apelmazamiento de molienda excesivamente fina.',
    'Desmontaje del grupo erogador, limpieza por inmersión y reajuste del micrométrico del molino.',
    DATE_SUB(NOW(), INTERVAL 32 DAY),
    DATE_SUB(NOW(), INTERVAL 30 DAY)
  ),
  (
    'INC-DEMO-0828',
    (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0201' LIMIT 1),
    (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-02' LIMIT 1),
    (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1),
    'Sonia Administrativa', '633445566', 'PAYMENT_SYSTEM',
    'El datáfono contactless indica error de comunicación y rechaza pagos con tarjeta.',
    'HIGH', 'CLOSED',
    DATE_SUB(NOW(), INTERVAL 26 DAY) + INTERVAL 15 MINUTE,
    DATE_SUB(NOW(), INTERVAL 26 DAY) + INTERVAL 45 MINUTE,
    DATE_SUB(NOW(), INTERVAL 26 DAY) + INTERVAL 225 MINUTE,
    DATE_SUB(NOW(), INTERVAL 24 DAY),
    'Fallo de comunicación del lector contactless por cableado MDB pinzado en puerta.',
    'Reparación y enfundado del mazo de cables MDB y actualización del firmware del lector Nayax.',
    DATE_SUB(NOW(), INTERVAL 26 DAY),
    DATE_SUB(NOW(), INTERVAL 24 DAY)
  ),
  (
    'INC-DEMO-0904',
    (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0101' LIMIT 1),
    (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01' LIMIT 1),
    (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1),
    'Laura Sanitaria', '600111222', 'TEMPERATURE_COLD',
    'Aviso preventivo: compresor encendido de forma continua con escarcha en evaporador.',
    'CRITICAL', 'CLOSED',
    DATE_SUB(NOW(), INTERVAL 18 DAY) + INTERVAL 12 MINUTE,
    DATE_SUB(NOW(), INTERVAL 18 DAY) + INTERVAL 35 MINUTE,
    DATE_SUB(NOW(), INTERVAL 18 DAY) + INTERVAL 137 MINUTE,
    DATE_SUB(NOW(), INTERVAL 16 DAY),
    'Acumulación de suciedad en la rejilla del ventilador evaporador reduciendo el flujo térmico.',
    'Limpieza profunda con desengrasante alimentario y comprobación del ciclo de desescarche.',
    DATE_SUB(NOW(), INTERVAL 18 DAY),
    DATE_SUB(NOW(), INTERVAL 16 DAY)
  ),
  (
    'INC-DEMO-0910',
    (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0201' LIMIT 1),
    (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-02' LIMIT 1),
    (SELECT `id` FROM `users` WHERE `email` = 'marta.ruta@vendguard.internal' LIMIT 1),
    'Marc Recepción', '600333444', 'PRODUCT_JAM',
    'Bolsa de patatas enganchada en la espiral 23 sin caer al cajón de entrega.',
    'MEDIUM', 'CLOSED',
    DATE_SUB(NOW(), INTERVAL 12 DAY) + INTERVAL 25 MINUTE,
    DATE_SUB(NOW(), INTERVAL 12 DAY) + INTERVAL 70 MINUTE,
    DATE_SUB(NOW(), INTERVAL 12 DAY) + INTERVAL 195 MINUTE,
    DATE_SUB(NOW(), INTERVAL 10 DAY),
    'Bolsa de frutos secos trabada en la trampilla basculante de recogida de producto.',
    'Alineación del muelle de retorno de la trampilla y verificación de diez ciclos de dispensación.',
    DATE_SUB(NOW(), INTERVAL 12 DAY),
    DATE_SUB(NOW(), INTERVAL 10 DAY)
  ),
  (
    'INC-DEMO-0916',
    (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0102' LIMIT 1),
    (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01' LIMIT 1),
    (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1),
    'Guillermo Mantenimiento', '644556677', 'ELECTRICAL_OFF',
    'Máquina totalmente apagada. El diferencial del cuadro saltó a primera hora.',
    'HIGH', 'CLOSED',
    DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 15 MINUTE,
    DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 45 MINUTE,
    DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 195 MINUTE,
    DATE_SUB(NOW(), INTERVAL 3 DAY),
    'Fuga de agua en racor de entrada de electroválvula provocando salto diferencial.',
    'Sustitución de racor rápido y tramo de teflón de 6mm con secado completo de la electrónica.',
    DATE_SUB(NOW(), INTERVAL 5 DAY),
    DATE_SUB(NOW(), INTERVAL 3 DAY)
  ),
  (
    'INC-DEMO-0919',
    (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0201' LIMIT 1),
    (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-02' LIMIT 1),
    (SELECT `id` FROM `users` WHERE `email` = 'marta.ruta@vendguard.internal' LIMIT 1),
    'Patricia RRHH', '655667788', 'PAYMENT_SYSTEM',
    'Traga monedas de 1 euro sin acreditar saldo ni devolver el importe.',
    'LOW', 'CLOSED',
    DATE_SUB(NOW(), INTERVAL 3 DAY) + INTERVAL 20 MINUTE,
    DATE_SUB(NOW(), INTERVAL 3 DAY) + INTERVAL 65 MINUTE,
    DATE_SUB(NOW(), INTERVAL 3 DAY) + INTERVAL 185 MINUTE,
    DATE_SUB(NOW(), INTERVAL 1 DAY),
    'Lector de monedas rechazaba por acumulación de grasa en las fotocélulas de entrada.',
    'Limpieza de fotocélulas ópticas y recalibración del canal de validación de 1€ y 2€.',
    DATE_SUB(NOW(), INTERVAL 3 DAY),
    DATE_SUB(NOW(), INTERVAL 1 DAY)
  ),
  (
    'INC-DEMO-0922',
    (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0101' LIMIT 1),
    (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01' LIMIT 1),
    (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1),
    'Laura Sanitaria', '600111222', 'OTHER',
    'Display frontal con caracteres ilegibles dificultando la selección de productos.',
    'LOW', 'CLOSED',
    DATE_SUB(NOW(), INTERVAL 1 DAY) + INTERVAL 15 MINUTE,
    DATE_SUB(NOW(), INTERVAL 1 DAY) + INTERVAL 35 MINUTE,
    DATE_SUB(NOW(), INTERVAL 1 DAY) + INTERVAL 120 MINUTE,
    DATE_SUB(NOW(), INTERVAL 1 DAY) + INTERVAL 10 HOUR,
    'Falso contacto en conector cinta ribbon del display LCD frontal de la máquina.',
    'Sustitución de cable plano ribbon y ajuste de los tornillos de fijación del frontal.',
    DATE_SUB(NOW(), INTERVAL 1 DAY),
    DATE_SUB(NOW(), INTERVAL 1 DAY) + INTERVAL 10 HOUR
  )
ON DUPLICATE KEY UPDATE
  `status` = VALUES(`status`),
  `resolution_diagnosis` = VALUES(`resolution_diagnosis`),
  `resolution_action` = VALUES(`resolution_action`),
  `resolved_at` = VALUES(`resolved_at`),
  `closed_at` = VALUES(`closed_at`),
  `created_at` = VALUES(`created_at`),
  `updated_at` = VALUES(`updated_at`);


