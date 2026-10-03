-- =============================================================================
-- VendGuard Module 08: Refunds and Unclaimed Cash Management
-- Migration: 008_refund_management.sql
-- Adds the physical reception flag to sites and creates the refund case
-- tables described in specs/technical/refunds_contracts.md (RF-REF-01,
-- RF-REF-04, RF-REF-10, Constitution Art. III soft delete and auditability).
-- =============================================================================

-- Add the physical reception flag only when absent (idempotent, portable
-- across MySQL and MariaDB). Sites default to having a reception desk, so the
-- column is added with DEFAULT 1 and existing rows are never touched.
SET @vg_refund_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'locations' AND column_name = 'has_physical_reception') = 0,
    'ALTER TABLE `locations` ADD COLUMN `has_physical_reception` TINYINT(1) NOT NULL DEFAULT 1 AFTER `longitude`',
    'SELECT 1'
);
PREPARE vg_refund_statement FROM @vg_refund_ddl;
EXECUTE vg_refund_statement;
DEALLOCATE PREPARE vg_refund_statement;

-- Refund cases: kept decoupled from the incident lifecycle so a machine can
-- return to service without waiting for the consumer to be paid.
-- Art. III: rows are never hard deleted, cancellations flip is_active/deleted_at.
CREATE TABLE IF NOT EXISTS `refund_requests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `incident_id` INT UNSIGNED NOT NULL,
    `machine_id` INT UNSIGNED NOT NULL,
    `location_id` INT UNSIGNED NOT NULL,
    `claimant_name` VARCHAR(100) NOT NULL,
    `claimant_contact` VARCHAR(100) NOT NULL,
    `claimed_amount` DECIMAL(6, 2) NOT NULL,
    `product_attempted` VARCHAR(100) NULL,
    `compensation_method` ENUM('EN_MANO_SEDE', 'BIZUM', 'TRANSFERENCIA_BANCARIA') NOT NULL,
    `bizum_phone` VARCHAR(15) NULL,
    `iban` VARCHAR(34) NULL,
    `pickup_pin` VARCHAR(4) NULL,
    `tracking_token` VARCHAR(64) NOT NULL UNIQUE,
    `status` ENUM(
        'PENDING_INSPECTION',
        'DEPOSITED_AT_RECEPTION',
        'VERIFIED_PENDING_PAYMENT',
        'REQUIRES_COORDINATOR_APPROVAL',
        'PENDING_CONTACT',
        'PAID_DIGITAL',
        'REFUNDED_IN_HAND',
        'REJECTED'
    ) NOT NULL DEFAULT 'PENDING_INSPECTION',
    `technician_finding` ENUM('FOUND_PHYSICAL', 'CONFIRMED_NO_CASH', 'UNVERIFIED_NO_CASH') NULL,
    `recovered_amount` DECIMAL(6, 2) NULL,
    `cash_custody_action` ENUM('LEFT_AT_RECEPTION', 'HELD_FOR_CENTRAL') NULL,
    `receptionist_name` VARCHAR(100) NULL,
    `technician_justification` TEXT NULL,
    `technician_inspected_at` DATETIME NULL,
    `technician_id` INT UNSIGNED NULL,
    `coordinator_decision` ENUM('APPROVED', 'REJECTED') NULL,
    `approved_amount` DECIMAL(6, 2) NULL,
    `coordinator_justification` TEXT NULL,
    `coordinator_id` INT UNSIGNED NULL,
    `payment_reference` VARCHAR(100) NULL,
    `paid_at` DATETIME NULL,
    `hand_delivered_at` DATETIME NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,

    CONSTRAINT `fk_refund_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_refund_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_refund_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_refund_technician` FOREIGN KEY (`technician_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_refund_coordinator` FOREIGN KEY (`coordinator_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,

    INDEX `idx_refund_status_location` (`status`, `location_id`),
    INDEX `idx_refund_incident` (`incident_id`),
    INDEX `idx_refund_tracking_token` (`tracking_token`),
    INDEX `idx_refund_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cash recovered on site with no prior consumer claim.
CREATE TABLE IF NOT EXISTS `unclaimed_cash_findings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `incident_id` INT UNSIGNED NOT NULL,
    `machine_id` INT UNSIGNED NOT NULL,
    `technician_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(6, 2) NOT NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT `fk_unclaimed_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_unclaimed_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_unclaimed_technician` FOREIGN KEY (`technician_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,

    INDEX `idx_unclaimed_incident` (`incident_id`),
    INDEX `idx_unclaimed_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;