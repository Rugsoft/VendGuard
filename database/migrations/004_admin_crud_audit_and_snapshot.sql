-- =============================================================================
-- VendGuard Módulo 04: Panel CRUD de Administración Integral
-- Migración DDL: 004_admin_crud_audit_and_snapshot.sql
-- =============================================================================
-- 1. Ampliación del enum en audit_log para incluir 'USER' (Art. III.3 y Art. V.1)
-- 2. Creación de machine_type_snapshot en incidents para blindaje histórico (Art. II)
-- 3. Backfill de datos históricos para incidencias preexistentes
-- =============================================================================

-- 1. Asegurar que audit_log soporte entidad 'USER'
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

ALTER TABLE `audit_log` 
MODIFY COLUMN `entity_type` ENUM('TICKET', 'MACHINE', 'LOCATION', 'USER') NOT NULL;

-- 2. Preservar tipología histórica en incidencias (Artículo II)
ALTER TABLE `incidents`
ADD COLUMN IF NOT EXISTS `machine_type_snapshot` ENUM('HOT_DRINKS', 'COLD_DRINKS', 'SNACKS', 'PERISHABLE_FOOD', 'COMBO') NULL AFTER `machine_id`;

-- 3. Backfill retroactivo para tickets creados con anterioridad
UPDATE `incidents` i
INNER JOIN `machines` m ON i.`machine_id` = m.`id`
SET i.`machine_type_snapshot` = m.`machine_type`
WHERE i.`machine_type_snapshot` IS NULL;
