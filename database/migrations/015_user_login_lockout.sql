-- =============================================================================
-- VendGuard Core: Internal User Login Lockout (finding S-3)
-- Migration: 015_user_login_lockout.sql
--
-- Adds a brute-force brake to internal user accounts (Coordinators and Technicians).
--
-- Why it is needed
-- ----------------
-- Finding S-3 noted that `POST /api/auth/login` had no rate limiting, allowing
-- unlimited brute-force attacks against user passwords. Following the pattern
-- of refund pickup PINs (migration 013) and location access codes (migration 014),
-- we add `login_attempts` and `login_locked_until` directly to `users`.
-- Five consecutive failures lock the account for 15 minutes.
-- =============================================================================

-- ── 1. Failed-attempt counter (per user account) ─────────────────────────────
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users'
       AND column_name = 'login_attempts') = 0,
    'ALTER TABLE `users`
     ADD COLUMN `login_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0
     AFTER `is_active`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- ── 2. Lock expiry timestamp ─────────────────────────────────────────────────
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users'
       AND column_name = 'login_locked_until') = 0,
    'ALTER TABLE `users`
     ADD COLUMN `login_locked_until` DATETIME NULL DEFAULT NULL
     AFTER `login_attempts`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- ── 3. Assertion: ensure columns exist ───────────────────────────────────────
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users'
       AND column_name IN ('login_attempts', 'login_locked_until')) = 2,
    'SELECT 1',
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''users: faltan columnas de freno de fuerza bruta; revise la migracion 015'''
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;
