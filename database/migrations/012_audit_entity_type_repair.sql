-- =============================================================================
-- VendGuard Module 08: Refunds and Unclaimed Cash Management
-- Migration: 012_audit_entity_type_repair.sql
--
-- Repairs the audit rows whose `entity_type` was silently emptied by the
-- database itself, and then makes that residue impossible to ignore again.
--
-- What went wrong
-- ---------------
-- `audit_log.entity_type` is an ENUM. This server runs without
-- `STRICT_TRANS_TABLES`, so when the refund module started writing events
-- labelled 'REFUND_REQUEST' BEFORE migration 009 widened the enum, MariaDB did
-- not raise an error: it stored the empty string and moved on. 224 events of
-- real refunds were therefore recorded with no entity type at all, which took
-- two things down:
--
--   1. `AuditEntityTypesTest` 4.5, which certifies that the data only uses
--      declared types; and
--   2. `GET /api/coordinator/audit-log/export`, whose 10.000-row window
--      reaches those rows and whose hydration then refuses the empty type,
--      turning a 200 into a 500.
--
-- The lesson is worth stating: on this server a schema value the database does
-- not know is not an error, it is a silent lie. The audit trail is exactly the
-- artefact that must not lie (Art. III.3), so the damage had to be repaired.
--
-- Why UPDATE and not DELETE
-- -------------------------
-- The obvious shortcut is to drop the broken rows. Art. III.3 requires the
-- immutable history of what happened, and these rows DO record something real:
-- cases created, inspected, cash recorded and delivered. Deleting them would
-- erase genuine refund history to tidy a label. The lost information is the
-- label alone, and the label is recoverable with certainty because every
-- surviving row states its own action:
--
--   * `REFUND_*`                 -> the entity is a `refund_requests` row
--   * `UNCLAIMED_CASH_RECORDED`  -> the entity is an `unclaimed_cash_findings`
--
-- So the migration restores the label, keeps every row, and leaves the history
-- complete. Nothing here softens Art. III: no row is removed, and no row is
-- invented.
--
-- Idempotent: on a healthy database both updates match zero rows.
-- =============================================================================

-- 1. Refund case events written while the enum only knew TICKET/MACHINE/...
UPDATE `audit_log`
SET `entity_type` = 'REFUND_REQUEST'
WHERE `entity_type` = ''
  AND LEFT(`action`, 7) = 'REFUND_';

-- 2. Unclaimed cash findings carry their own entity type (Art. III.3: a pile of
--    recovered coins and a reimbursement case are different facts, and must not
--    share a trail).
UPDATE `audit_log`
SET `entity_type` = 'UNCLAIMED_CASH_FINDING'
WHERE `entity_type` = ''
  AND `action` = 'UNCLAIMED_CASH_RECORDED';

-- 3. Anything still unlabelled is NOT guessable from its action, so the
--    migration refuses to pretend it knows: it aborts and demands a human
--    decision. Better a failed deploy than a repaired-looking database hiding
--    an unattributable event.
SET @vg_audit_repair_ddl = IF(
    (SELECT COUNT(*) FROM `audit_log` WHERE `entity_type` = '') = 0,
    'SELECT 1',
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''audit_log: quedan eventos sin entity_type que la migracion no puede atribuir; revisarlos a mano antes de continuar'''
);
PREPARE vg_audit_repair_statement FROM @vg_audit_repair_ddl;
EXECUTE vg_audit_repair_statement;
DEALLOCATE PREPARE vg_audit_repair_statement;