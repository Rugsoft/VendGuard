-- =============================================================================
-- VendGuard Module 08: Refunds and Unclaimed Cash Management
-- Migration: 009_refund_audit_entity.sql
-- Extends audit_log.entity_type so refund cases and unclaimed cash findings
-- get their own immutable audit trail (RNF-REF-01, Constitution Art. III.3).
--
-- Dedicated entity types are mandatory here, not cosmetic: refund_requests.id
-- and incidents.id are separate ID spaces, so recording a refund event under
-- 'TICKET' would silently point at an unrelated incident and destroy the
-- traceability the Constitution requires for money movements.
--
-- MODIFY COLUMN is naturally idempotent: re-running redefines the same enum.
-- =============================================================================

ALTER TABLE `audit_log`
MODIFY COLUMN `entity_type` ENUM(
    'TICKET',
    'MACHINE',
    'LOCATION',
    'USER',
    'PREVENTIVE_ORDER',
    'SANITARY_CERTIFICATE',
    'REFUND_REQUEST',
    'UNCLAIMED_CASH_FINDING'
) NOT NULL;

-- The enum extension only widens the accepted set, so existing rows keep their
-- values. Assert that invariant explicitly instead of trusting the DDL.
SET @vg_refund_audit_ddl = IF(
    (SELECT COUNT(*) FROM `audit_log` WHERE `entity_type` IS NULL) = 0,
    'SELECT 1',
    'SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'audit_log.entity_type no admite nulos tras la migración\''
);
PREPARE vg_refund_audit_statement FROM @vg_refund_audit_ddl;
EXECUTE vg_refund_audit_statement;
DEALLOCATE PREPARE vg_refund_audit_statement;