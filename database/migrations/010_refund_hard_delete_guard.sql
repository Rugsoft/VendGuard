-- =============================================================================
-- VendGuard Module 08: Refunds and Unclaimed Cash Management
-- Migration: 010_refund_hard_delete_guard.sql
--
-- Makes the Art. III.1 prohibition of physical deletion enforceable instead of
-- merely documented. Until now "never hard delete" was a rule the application
-- obeyed by convention: a stray `DELETE FROM refund_requests` in src/ would
-- have destroyed a monetary record and nothing would have objected. The
-- specification demands more than convention (RF-REF-10, plan §6.4): the table
-- itself has to refuse.
--
-- Why a trigger and not a repository rule
-- ---------------------------------------
-- Any guard living in PHP can be bypassed by a second code path, a migration,
-- or a console session. The rule being certified is about DATA, not about code
-- paths, so it belongs where the data lives. A BEFORE DELETE trigger is also
-- the only mechanism that survives the application being replaced.
--
-- Why the purge escape hatch
-- --------------------------
-- The test harness genuinely has to empty these tables between runs: refund
-- suites are order-independent only because each one rolls back, but
-- `TestDataCleaner::purge()` still resets operational state and
-- `run_all.php` relies on it. A guard with no way out would make the database
-- impossible to reset.
--
-- So the trigger refuses by default and yields to ONE explicit, auditable
-- signal: the session variable `@vendguard_purge`. Only the test harness sets
-- it, and it is unset again in the same breath. Production code never sets it
-- — grep the repository and you will find exactly one writer, in
-- tests/Support/TestDataCleaner.php. That keeps the constitutional rule
-- absolute for the application while leaving the tests able to clean up.
--
-- SQLSTATE 45000 is the standard "unhandled user-defined exception" code, so
-- the refusal surfaces as a normal PDOException rather than a silent no-op.
-- =============================================================================

-- Drop and recreate the trigger idempotently.
-- Note: MySQL/MariaDB cannot PREPARE a CREATE TRIGGER statement (Error 1295),
-- so dynamic PREPARE cannot be used here. Direct execution is portable across
-- MySQL and MariaDB, while maintaining idempotency against information_schema.triggers.
DROP TRIGGER IF EXISTS `trg_refund_requests_no_hard_delete`;

CREATE TRIGGER `trg_refund_requests_no_hard_delete`
BEFORE DELETE ON `refund_requests`
FOR EACH ROW
BEGIN
    IF COALESCE(@vendguard_purge, 0) <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Art. III.1: los expedientes de reintegro no se borran fisicamente; se anulan por estado (is_active = 0).';
    END IF;
END;
