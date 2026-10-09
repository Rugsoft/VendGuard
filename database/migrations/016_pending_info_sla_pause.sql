-- =============================================================================
-- VendGuard Module 11: "Pending Info" (PENDING_INFO) and the SLA pause
-- Migration: 016_pending_info_sla_pause.sql
--
-- Turns a blocked visit into an honest, auditable state.
--
-- Why a state and not a flag
-- --------------------------
-- A technician who reaches a closed building today can only leave the ticket
-- `ASSIGNED` (which claims he is on his way) or start `IN_PROGRESS` (which
-- claims he is working on the machine) while the contractual SLA clock keeps
-- running against the operator for a door the client never opened. The new
-- state names what is really happening: work is blocked by the site, the
-- contractual clock is frozen, and the state is visible to the site responsible
-- so he can unlock it. Module 11 spec: RF-01.1, RF-01.4, RF-04.1, RF-04.4,
-- Constitution Art. III and Art. V.1.
--
-- Why the enum must keep the virtual column
-- -----------------------------------------
-- `incidents.is_active_ticket` and its conditional unique key
-- `uq_machine_active_ticket` are computed from `status` (the engine-level guard
-- of the duplicate-detection rule, Art. V.2). PENDING_INFO is an ACTIVE,
-- non-terminal ticket, so the generated expression must keep classifying it as
-- active: the virtual column and its unique key survive this migration
-- untouched, and the closing assertion proves it.
--
-- What is stored here, and what is not
-- ------------------------------------
-- `paused_at` is the start of the CURRENT pause (NULL while not paused; cleared
-- on resume). `total_pending_info_seconds` is the accumulator across every pause
-- of the ticket (RF-04.1) and the exact figure MTTR and the SLA discount later
-- subtract. `sla_target_at` is the contractual deadline, shifted forward by the
-- paused minutes inside the site's business hours on resume (RF-03.3).
-- `pending_info_reason_category` and `pending_info_reason_text` hold the typified
-- cause and the >= 20-character justification the pause demands (Art. V.1).
--
-- Every interval itself stays immutable in `incident_history` (Art. III): these
-- columns describe the live pause, they are not its audit trail.
--
-- Existing rows are untouched: no ticket is paused, so both timestamps are NULL,
-- the accumulator starts at zero and the enum rarely changes value, which is the
-- truth for every ticket that exists today.
-- =============================================================================

-- ── 1. `status`: PENDING_INFO joins the enum as an active, non-terminal state ─
-- Placed after PENDING_PARTS (the other waiting state) and before RESOLVED, so
-- the enum reads in operational order. Guarded on `column_type` and not on the
-- column existing: an interrupted or repeated run must be a clean no-op.
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'incidents'
       AND column_name = 'status'
       AND column_type LIKE '%''PENDING_INFO''%') = 0,
    'ALTER TABLE `incidents`
     MODIFY COLUMN `status`
       ENUM(''REGISTERED'', ''ASSIGNED'', ''IN_PROGRESS'', ''PENDING_PARTS'', ''PENDING_INFO'', ''RESOLVED'', ''REOPENED'', ''CLOSED'', ''CANCELLED'')
       NOT NULL DEFAULT ''REGISTERED''',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- ── 2. Current pause start (NULL while the ticket is not paused) ─────────────
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'incidents'
       AND column_name = 'paused_at') = 0,
    'ALTER TABLE `incidents`
     ADD COLUMN `paused_at` TIMESTAMP NULL DEFAULT NULL
     AFTER `cancelled_at`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- ── 3. Typified blocking cause (RF-01.2) ─────────────────────────────────────
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'incidents'
       AND column_name = 'pending_info_reason_category') = 0,
    'ALTER TABLE `incidents`
     ADD COLUMN `pending_info_reason_category` VARCHAR(64) NULL DEFAULT NULL
     AFTER `paused_at`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- ── 4. Mandatory justification, >= 20 real characters (RF-01.3, Art. V.1) ────
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'incidents'
       AND column_name = 'pending_info_reason_text') = 0,
    'ALTER TABLE `incidents`
     ADD COLUMN `pending_info_reason_text` TEXT NULL DEFAULT NULL
     AFTER `pending_info_reason_category`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- ── 5. Accumulated paused seconds across every interval (RF-03.1, RF-04.1) ───
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'incidents'
       AND column_name = 'total_pending_info_seconds') = 0,
    'ALTER TABLE `incidents`
     ADD COLUMN `total_pending_info_seconds` INT UNSIGNED NOT NULL DEFAULT 0
     AFTER `pending_info_reason_text`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- ── 6. Contractual deadline, shifted in business hours on resume (RF-03.3) ───
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'incidents'
       AND column_name = 'sla_target_at') = 0,
    'ALTER TABLE `incidents`
     ADD COLUMN `sla_target_at` TIMESTAMP NULL DEFAULT NULL
     AFTER `total_pending_info_seconds`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- The state and its fields are useless half-provisioned: a missing column turns
-- a route pause into a 500 mid-shift, and losing the generated column would
-- quietly drop the engine-level duplicate guard. Assert the shape instead of
-- trusting the guarded ALTERs (same closing kernel check as 013, 014 and 015).
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'incidents'
       AND column_name IN ('pending_info_reason_category', 'pending_info_reason_text',
                           'paused_at', 'total_pending_info_seconds', 'sla_target_at')) = 5
    AND (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'incidents'
           AND column_name = 'status'
           AND column_type LIKE '%''PENDING_INFO''%') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'incidents'
           AND column_name = 'is_active_ticket'
           AND extra LIKE '%GENERATED%') = 1,
    'SELECT 1',
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''incidents: falta el estado PENDING_INFO, sus columnas de pausa o la columna virtual de ticket activo; revise la migracion 016'''
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;
