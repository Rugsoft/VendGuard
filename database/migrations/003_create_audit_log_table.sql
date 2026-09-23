-- =============================================================================
-- VendGuard Módulo 03: Métricas y Auditoría (003_create_audit_log_table.sql)
-- =============================================================================
-- Crea la tabla inmutable `audit_log` para cumplimiento de los Artículos III y V
-- de la Constitución de VendGuard y los requisitos RF-05 y RNF-03.
-- Estricto principio append-only: sin operaciones de borrado ni modificación.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` ENUM('TICKET', 'MACHINE', 'LOCATION') NOT NULL,
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
