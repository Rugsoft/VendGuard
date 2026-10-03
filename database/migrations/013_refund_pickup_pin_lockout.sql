-- =============================================================================
-- VendGuard Module 08: Refunds and Unclaimed Cash Management
-- Migration: 013_refund_pickup_pin_lockout.sql
--
-- Gives the four-digit pickup PIN a brute-force brake.
--
-- Why the PIN needs one
-- ---------------------
-- The PIN that releases cash at the reception desk is four digits: 10.000
-- combinations, compared in constant time, with nothing else in between. An
-- adversarial run of the real endpoint managed 3000 wrong PINs in 1.02 seconds
-- (0.34 ms per attempt), which is roughly 2.900 guesses a minute and leaves the
-- whole keyspace reachable in under four minutes. `hash_equals()` protects the
-- secret from timing leaks; it does nothing about volume, because a volume
-- attack does not need to be fast, only relentless.
--
-- The secret itself is NOT weakened. Widening the PIN or hashing it into a
-- separate credential table would be a bigger change than the threat deserves
-- and would break the contract the consumer relies on: a number he reads off
-- his phone and hands over at the desk. What was missing was a rate limit, so
-- that is what this migration adds.
--
-- Why these two columns and not a log table
-- -----------------------------------------
-- The counter belongs to the case, next to the PIN it guards: the desk is bound
-- to one location and one case, and a wrong guess is an attempt against THAT
-- envelope. A separate attempts table would have to answer the same question
-- with a join, and would need its own purge policy to stay useful.
--
-- Privacy
-- -------
-- Both columns stay OUT of the restricted projection and out of every DTO the
-- consumer or the receptionist can read. How many wrong guesses have been made
-- tells an attacker exactly how much of the keyspace is left, so the counter is
-- as sensitive as the PIN itself.
--
-- Existing rows are untouched: everyone starts at zero attempts, which is the
-- truth for every case that exists today.
-- =============================================================================

-- ── 1. Failed-attempt counter ────────────────────────────────────────────────
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'refund_requests'
       AND column_name = 'pickup_attempts') = 0,
    'ALTER TABLE `refund_requests`
     ADD COLUMN `pickup_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0
     AFTER `pickup_pin`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- ── 2. Lock expiry, so the brake is temporary and self-healing ───────────────
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'refund_requests'
       AND column_name = 'pickup_locked_until') = 0,
    'ALTER TABLE `refund_requests`
     ADD COLUMN `pickup_locked_until` DATETIME NULL
     AFTER `pickup_attempts`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- A lock nobody can wait out is a denial of service on the consumer, so the
-- column must exist before the code can rely on it. Assert it rather than
-- trusting the guarded ALTER.
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'refund_requests'
       AND column_name IN ('pickup_attempts', 'pickup_locked_until')) = 2,
    'SELECT 1',
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''refund_requests: falta el bloqueo de intentos del PIN de recogida; revise la migracion 013'''
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;