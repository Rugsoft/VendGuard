-- =============================================================================
-- VendGuard Core: Site Access Credential (finding S-4)
-- Migration: 014_location_access_code.sql
--
-- Gives the site portal a real credential and a brake.
--
-- Why the site code is not a credential
-- -------------------------------------
-- `POST /api/auth/site-login` used to issue a session token in exchange for the
-- site code alone, and `SiteAuthMiddleware` accepted the same code as an
-- `X-Site-Code` header without a token. Both were the same door. The code is
-- public by construction: it travels inside the URL encoded in every machine's
-- QR label (`/?qr=VEND-0101&site=SEDE-BCN-01`) and the seeded codes are
-- sequential. With it, an anonymous client read a site's refund records and
-- created incidents (measured: `200` / `201`) without ever visiting the
-- building. The finding escalated from 🟡 to 🔴 for exactly that write reach.
--
-- What closes it
-- --------------
-- A per-site access code issued by coordination and handed over in person. Only
-- its bcrypt hash is stored: the plaintext is shown once and can never be read
-- back, so a database dump does not hand over the credential. A site without an
-- issued code fails closed. `X-Site-Code` is retired as an authentication path.
--
-- Why the counter lives here
-- --------------------------
-- The site code is public, so the only secret left is the access code; a
-- ten-character code without a rate limit is a promise half kept. The counter
-- belongs to the site it guards, exactly like the pickup PIN counter belongs to
-- the envelope (migration 013). Five consecutive failures lock the site login
-- for fifteen minutes; a successful login resets the counter. Both columns stay
-- OUT of every projection: how much of the keyspace is left is as sensitive as
-- the credential itself.
--
-- Existing rows keep `access_code_hash = NULL`, which is the truth for every
-- site that has not received its code yet: they cannot log in until
-- coordination issues and delivers one (guided reissue, fail closed).
-- =============================================================================

-- ── 1. Access code hash (bcrypt; NULL = pending delivery) ────────────────────
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'locations'
       AND column_name = 'access_code_hash') = 0,
    'ALTER TABLE `locations`
     ADD COLUMN `access_code_hash` VARCHAR(255) NULL
     AFTER `contact_phone`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- ── 2. Issuance timestamp (audit of the last emission/reissue) ───────────────
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'locations'
       AND column_name = 'access_code_issued_at') = 0,
    'ALTER TABLE `locations`
     ADD COLUMN `access_code_issued_at` DATETIME NULL
     AFTER `access_code_hash`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- ── 3. Failed-attempt counter (brute-force brake, per site) ──────────────────
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'locations'
       AND column_name = 'login_attempts') = 0,
    'ALTER TABLE `locations`
     ADD COLUMN `login_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0
     AFTER `access_code_issued_at`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- ── 4. Lock expiry, so the brake is temporary and self-healing ───────────────
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'locations'
       AND column_name = 'login_locked_until') = 0,
    'ALTER TABLE `locations`
     ADD COLUMN `login_locked_until` DATETIME NULL
     AFTER `login_attempts`',
    'SELECT 1'
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;

-- A lock nobody can wait out, or a credential column missing, breaks the site
-- portal silently; assert the shape instead of trusting the guarded ALTERs.
SET @vg_col = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'locations'
       AND column_name IN ('access_code_hash', 'access_code_issued_at', 'login_attempts', 'login_locked_until')) = 4,
    'SELECT 1',
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''locations: falta la credencial de sede o su freno de intentos; revise la migracion 014'''
);
PREPARE vg_stmt FROM @vg_col;
EXECUTE vg_stmt;
DEALLOCATE PREPARE vg_stmt;
