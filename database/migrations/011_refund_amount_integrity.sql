-- =============================================================================
-- VendGuard Module 08: Refunds and Unclaimed Cash Management
-- Migration: 011_refund_amount_integrity.sql
--
-- Two monetary defects found by an adversarial audit of the refund flows.
--
-- 1. The settled amount was never stored
-- --------------------------------------
-- `registerDigitalPayment()` validated `paid_amount`, the DTO required it, and
-- then wrote only `payment_reference` and `paid_at`. No `paid_amount` column
-- existed. A case in PAID_DIGITAL therefore said it had been paid and nothing
-- about how much: the amount the operator declared lived only in the request
-- that no system retained. For a treasury audit trail the amount paid has to be
-- a stored fact, not something reconstructed from a banking screenshot.
--
-- 2. Out-of-range amounts were silently saturated, not rejected
-- --------------------------------------------------------------
-- Every amount column was DECIMAL(6,2), which tops out at 9999.99, and this
-- server runs without STRICT_TRANS_TABLES in `sql_mode`. MySQL therefore
-- clamped instead of raising: a technician declaring 999999.00 of recovered
-- cash had it stored as 9999.99 with no error and no warning. Measured on this
-- instance:
--
--     999999.00 -> 9999.99     12345.67 -> 9999.99     10000.00 -> 9999.99
--
-- The audit trail silently recorded a different number from the one the
-- operator filed, which is the worst failure mode a financial ledger can have.
--
-- Why the columns widen instead of `sql_mode` tightening
-- -------------------------------------------------------
-- Two options would stop the truncation: activate STRICT_TRANS_TABLES, or make
-- the columns big enough that nothing legitimate can overflow. This migration
-- takes the second, for two reasons. SET GLOBAL sql_mode needs server
-- privileges and silently changes the behaviour of every other database on the
-- same instance, which is not a decision one module may take. And the columns
-- are widened anyway, because DECIMAL(6,2) was simply too narrow for a cash
-- reconciliation: several claims can share one incident, so the recovered
-- total is legitimately larger than any single 50,00 EUR ceiling.
--
-- The domain now bounds these amounts too (see `RefundManagementService` and
-- `TechnicianRefundService`), so the value is refused before it ever reaches the
-- database. Widening is the second line of defence, not the first: it guarantees
-- the column can hold every value the domain considers legitimate.
--
-- Existing rows are untouched: `paid_amount` is NULL where the amount is
-- unknown, which is exactly what those historical cases can prove.
-- =============================================================================

SET @vg_col = '';

-- ── 1. The settled amount becomes a stored fact ──────────────────────────────
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'refund_requests'
       AND column_name = 'paid_amount') = 0,
    'ALTER TABLE `refund_requests` ADD COLUMN `paid_amount` DECIMAL(10,2) NULL
     AFTER `payment_reference`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- ── 2. Amount columns widen so no legitimate figure can be saturated ─────────
-- Each ALTER is guarded on the CURRENT type, so re-running the migration is a
-- no-op instead of an error, and a database already widened is left alone.
SET @vg_col = IF(
    (SELECT column_type FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'refund_requests'
       AND column_name = 'claimed_amount') = 'decimal(6,2)',
    'ALTER TABLE `refund_requests` MODIFY COLUMN `claimed_amount` DECIMAL(10,2) NOT NULL',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

SET @vg_col = IF(
    (SELECT column_type FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'refund_requests'
       AND column_name = 'recovered_amount') = 'decimal(6,2)',
    'ALTER TABLE `refund_requests` MODIFY COLUMN `recovered_amount` DECIMAL(10,2) NULL',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

SET @vg_col = IF(
    (SELECT column_type FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'refund_requests'
       AND column_name = 'approved_amount') = 'decimal(6,2)',
    'ALTER TABLE `refund_requests` MODIFY COLUMN `approved_amount` DECIMAL(10,2) NULL',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- Unclaimed cash is money the technician found with no consumer claim behind it,
-- so it never passes through the 50,00 EUR consumer ceiling. It was still
-- saturating at the very same 9999.99 ceiling.
SET @vg_col = IF(
    (SELECT column_type FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'unclaimed_cash_findings'
       AND column_name = 'amount') = 'decimal(6,2)',
    'ALTER TABLE `unclaimed_cash_findings` MODIFY COLUMN `amount` DECIMAL(10,2) NOT NULL',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;