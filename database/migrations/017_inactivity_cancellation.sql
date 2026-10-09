-- =============================================================================
-- VendGuard Module 11: safe cancellation after 72 business hours of inactivity
-- Migration: 017_inactivity_cancellation.sql
--
-- Two shapes were missing for RF-04.4, RF-04.5 and RF-04.6 to be truthful.
--
-- 1. `machines.is_blocked_no_access`
-- ----------------------------------
-- When the coordinator cancels a ticket because the site never answered, the
-- machine must NOT go back to "Operativa": putting a machine with an
-- unrepaired fault back in green would lie to every user of the building
-- (Constitution Art. V.1, plan.md §4 decision table). `Machine` already models
-- the state and its precedence over the incident reading (T-PAUSE-04), and it
-- declares the persisted column as this task's responsibility. Without the
-- column the block could only live in memory, so the flag is materialised here.
--
-- 2. `refund_requests.incident_id` becomes NULLable
-- ------------------------------------------------
-- RF-04.5: cancelling the ticket must never cancel the claim. The money stays
-- with the consumer, so the case is DETACHED from the incident
-- (`incident_id = NULL`) and left in the coordination inbox for central
-- settlement. The column was NOT NULL, which made the detach impossible; the
-- foreign key survives untouched because a NULL parent is simply not enforced,
-- and the row keeps every other column for audit (Art. III).
--
-- Both ALTERs are guarded on `information_schema` and not on the column
-- existing, so an interrupted or repeated run is a clean no-op.
-- =============================================================================

-- ── 1. Machine blocked for lack of access (RF-04.4, Art. V.1) ────────────────
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'machines'
       AND column_name = 'is_blocked_no_access') = 0,
    'ALTER TABLE `machines`
     ADD COLUMN `is_blocked_no_access` TINYINT(1) NOT NULL DEFAULT 0
     AFTER `is_active`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- ── 2. Refund cases can outlive their incident (RF-04.5, Module 08) ──────────
-- Guarded on `IS_NULLABLE` and not on the column existing: the goal is the
-- nullability, so a database where the column is already NULLable is a no-op
-- and a database where it is still NOT NULL converges.
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'refund_requests'
       AND column_name = 'incident_id'
       AND is_nullable = 'NO') = 1,
    'ALTER TABLE `refund_requests`
     MODIFY COLUMN `incident_id` INT UNSIGNED NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- The two shapes are useless half-provisioned: a missing flag turns the
-- cancellation into a 500 after the ticket is already cancelled, and a still
-- NOT NULL column aborts the detach mid-transaction. Assert the end state
-- instead of trusting the guarded ALTERs (same closing kernel check as 013-016).
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'machines'
       AND column_name = 'is_blocked_no_access'
       AND column_type LIKE 'tinyint%') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'refund_requests'
           AND column_name = 'incident_id'
           AND is_nullable = 'YES') = 1,
    'SELECT 1',
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''machines o refund_requests: falta la marca de bloqueo por falta de acceso o el incident_id sigue siendo NOT NULL; revise la migracion 017'''
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;
