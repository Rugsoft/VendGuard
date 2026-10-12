-- =============================================================================
-- VendGuard Module 11: operational visibility of the sanitary sweep
-- Migration: 018_sanitary_sweep_visibility.sql
--
-- Turns the row contention of the background sweep (T-PAUSE-39/T-PAUSE-40) into
-- something the Service Coordination can SEE, and raises the escalation alert
-- when the deferral persists (T-PAUSE-41, RF-07, Constitution Art. II and
-- Art. III).
--
-- Why two tables and not a column on `machines`
-- ---------------------------------------------
-- The sweep already tells the truth about a deferral in its own response
-- (`contended`/`contended_count`), but that response goes to the SCHEDULER, not
-- to the coordination panel: nobody with authority to act on a stuck writer
-- reads it. Worse, a deferral leaves no trace anywhere by design -- the loser
-- writes neither machine state nor audit evidence -- so "the same ticket has
-- been deferred in three consecutive passes" was unanswerable: an in-memory
-- counter is per process, and the very next pass runs in another one.
--
-- `sanitary_sweep_passes` is the HEARTBEAT of the sweep: one row per pass with
-- its counts and measured duration. It is what lets the panel distinguish the
-- three situations that used to look identical, "the sweep runs and finds
-- nothing", "the sweep runs and defers candidates" and "the sweep is not
-- running at all". No foreign keys: it is an append-only log, not a relation
-- with the fleet.
--
-- `sanitary_sweep_contentions` is the FOLLOW-UP per ticket: how many
-- consecutive passes it has been deferred, since when, whether it already
-- escalated and whether it is still open. The unique key is per incident and is
-- REUSED: a new episode resets the counters and clears `escalated_at` and
-- `resolved_at`, so the table cannot grow per machine and the whole history is
-- still preserved (Art. III).
--
-- Why the sweep writes this AFTER consuming the clock
-- ---------------------------------------------------
-- The bookkeeping runs at the end of the pass, never before it. The sanitary
-- safeguard (Art. II) must not depend on an observability table: if this write
-- fails, the quarantines already committed stay firm and idempotent and the
-- failure travels loudly (the pass answers an error and the scheduler alerts),
-- but no machine with a real thermal risk goes un-quarantined because nobody
-- could write a line in a log.
--
-- Both tables are declared in `database/cloud_init.sql` as well: that file
-- rebuilds the schema of a freshly deployed boot and the migrations are the
-- convergence path for a database that already exists. Everything here is
-- additive and idempotent (`CREATE TABLE IF NOT EXISTS`), so an interrupted or
-- repeated run is a clean no-op.
-- =============================================================================

-- ── 1. The pass log: one row per sweep, the heartbeat of the safeguard ───────
CREATE TABLE IF NOT EXISTS `sanitary_sweep_passes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME NULL DEFAULT NULL,
  `candidates` INT UNSIGNED NOT NULL DEFAULT 0,
  `evaluated` INT UNSIGNED NOT NULL DEFAULT 0,
  `quarantined_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `contended_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failures_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_ms` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  INDEX `idx_sanitary_sweep_passes_started` (`started_at`),
  INDEX `idx_sanitary_sweep_passes_contended` (`contended_count`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Why `machine_id` carries NO foreign key
-- ---------------------------------------
-- Found by the integration suite of this same module, not by reasoning:
-- inserting a child row takes a SHARED lock on every parent row it references,
-- so a follow-up that pointed at `machines(id)` became unwritable exactly while
-- another consumer was retaining that machine row -- that is, in the ONE
-- situation the table exists to record. Measured: the deferral was classified
-- correctly (1205, `contended`), and then recording it died on the same lock,
-- aborting the pass and turning a plain contention into a red scheduler alert.
-- The observation must not contend with what it observes, so `machine_id` stays
-- as a denormalised reference (the surface joins `machines` anyway, and an
-- orphan would simply not be listed) and the FK to `incidents` stays: the
-- contender holds the machine row, not the ticket. `purgeIncident()` still
-- drains the follow-up, because that traversal is derived from the `incidents`
-- FK, not from this one.
--
-- ── 2. The follow-up per ticket: consecutive deferrals and escalation ────────
-- The resolution is by IDENTITY, not by timestamp: a pass closes exactly the
-- open follow-ups whose ticket is NOT in the list of tickets it deferred, which
-- is by definition the set THAT pass no longer deferred. Asking "who did I
-- touch?" is exact; asking "who is older than me?" depends on second-resolution
-- marks that two millisecond passes can share. `last_detected_at` is kept as the
-- display of the last deferral, not as the cut.
CREATE TABLE IF NOT EXISTS `sanitary_sweep_contentions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `incident_id` INT UNSIGNED NOT NULL,
  `machine_id` INT UNSIGNED NOT NULL,
  `first_detected_at` DATETIME NOT NULL,
  `last_detected_at` DATETIME NOT NULL,
  `consecutive_passes` INT UNSIGNED NOT NULL DEFAULT 1,
  `total_passes` INT UNSIGNED NOT NULL DEFAULT 1,
  `escalated_at` DATETIME NULL DEFAULT NULL,
  `resolved_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sanitary_sweep_contention_incident` (`incident_id`),
  INDEX `idx_sanitary_sweep_contentions_open` (`resolved_at`, `escalated_at`),
  INDEX `idx_sanitary_sweep_contentions_machine` (`machine_id`),
  CONSTRAINT `fk_sanitary_sweep_contention_incident` FOREIGN KEY (`incident_id`)
    REFERENCES `incidents` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
