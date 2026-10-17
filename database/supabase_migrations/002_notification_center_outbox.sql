-- =============================================================================
-- VendGuard · Migraciones incrementales del motor nuevo · 002 · NOTIFICATIONS OUTBOX
-- Fichero: database/supabase_migrations/002_notification_center_outbox.sql
-- Módulo: 14-notification-center-outbox · T-NOTIF-01
-- Referencia: specs/technical/notification_center_outbox_contracts.md §2
-- Ejecutor: src/Infrastructure/Database/PostgresMigrationRunner.php
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Nuevos tipos enumerados nativos (ENUM)
-- -----------------------------------------------------------------------------

-- 1.1. Canal de salida de la notificación
DO $do$
BEGIN
    CREATE TYPE notification_channel AS ENUM ('EMAIL', 'WEBHOOK');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 1.2. Prioridad de despacho en cola (Art. II: prevalencia sanitaria estricta)
DO $do$
BEGIN
    CREATE TYPE notification_priority AS ENUM ('CRITICAL_SANITARY', 'STANDARD');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 1.3. Los 6 estados canónicos de la bandeja de salida (RF-01.1)
DO $do$
BEGIN
    CREATE TYPE notification_status AS ENUM (
        'PENDING',
        'PROCESSING',
        'PENDING_RETRY',
        'SENT',
        'FAILED_PERMANENT',
        'CANCELLED_OBSOLETE'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 1.4. Catálogo cerrado de plantillas tipificadas (RF-03, RF-04, RF-05)
DO $do$
BEGIN
    CREATE TYPE notification_template AS ENUM (
        'COLD_CHAIN_IMMEDIATE_ALERT',
        'SANITARY_QUARANTINE_TRIGGERED',
        'SITE_SANITARY_QUARANTINE_NOTICE',
        'SANITARY_SWEEP_ESCALATED',
        'SLA_IMMINENCE_2H_UNASSIGNED',
        'SLA_IMMINENCE_3H_UNSTARTED',
        'INCIDENT_OPENED_SITE',
        'INCIDENT_OPENED_CITIZEN_QR',
        'TECHNICIAN_ASSIGNED',
        'TECHNICIAN_UNASSIGNED',
        'PENDING_INFO_SITE_REQUEST',
        'PENDING_INFO_SITE_REPLY',
        'INCIDENT_RESOLVED_SITE',
        'INCIDENT_RESOLVED_CITIZEN_QR',
        'INCIDENT_REOPENED_COORDINATOR',
        'CHRONIC_BREAKDOWN_ALERT',
        'INCIDENT_CANCELLED_SITE',
        'INCIDENT_CANCELLED_CITIZEN_QR',
        'CITIZEN_QR_OBSERVATION_ACK'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 1.5. Extensión de audit_entity_type para trazabilidad de notificaciones y canales (RF-07.3)
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'NOTIFICATION_OUTBOX';
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'NOTIFICATION_CHANNEL_SETTING';

-- -----------------------------------------------------------------------------
-- 2. Alteraciones sobre tablas existentes (locations, users, incidents)
-- -----------------------------------------------------------------------------

-- 2.1. Correo oficial de contacto en locations (RF-02.1)
ALTER TABLE locations
    ADD COLUMN IF NOT EXISTS contact_email VARCHAR(150) NOT NULL DEFAULT 'recepcion@sede.vendguard.es';

-- Backfill determinista para sedes preexistentes que conserven el valor por defecto genérico
UPDATE locations
   SET contact_email = 'contacto.' || lower(regexp_replace(site_code_key, '[^a-z0-9]+', '-', 'g')) || '@sede.vendguard.es'
 WHERE contact_email = 'recepcion@sede.vendguard.es';

-- 2.2. Indicador de Técnico de Guardia en users (RF-02.5, RF-02.6)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_on_call BOOLEAN NOT NULL DEFAULT FALSE;

DO $do$
BEGIN
    ALTER TABLE users
        ADD CONSTRAINT chk_users_on_call_active_tech CHECK (
            is_on_call = FALSE OR (role = 'TECHNICIAN' AND is_active = TRUE)
        );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

CREATE INDEX IF NOT EXISTS idx_users_on_call ON users (role, is_active, is_on_call)
    WHERE is_on_call = TRUE;

-- 2.3. Correo opcional del informador y marcas de control de ciclo sanitario en incidents (RF-02.2, RF-03.1, RF-04.4, RF-04.5)
ALTER TABLE incidents
    ADD COLUMN IF NOT EXISTS reporter_email VARCHAR(150),
    ADD COLUMN IF NOT EXISTS cycle_started_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS cold_alert_sent_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS sla_2h_alert_sent_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS sla_3h_alert_sent_at TIMESTAMPTZ;

UPDATE incidents
   SET cycle_started_at = COALESCE(reopened_at, created_at)
 WHERE cycle_started_at IS NULL;

ALTER TABLE incidents
    ALTER COLUMN cycle_started_at SET NOT NULL,
    ALTER COLUMN cycle_started_at SET DEFAULT CURRENT_TIMESTAMP;

-- -----------------------------------------------------------------------------
-- 3. Tabla 21 · notification_outbox (Bandeja de Salida Transaccional)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_outbox (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY,
    incident_id             BIGINT,
    channel                 notification_channel NOT NULL,
    priority                notification_priority NOT NULL DEFAULT 'STANDARD',
    template                notification_template NOT NULL,
    recipient               VARCHAR(255) NOT NULL,
    recipient_role          VARCHAR(32) NOT NULL,
    recipient_user_id       BIGINT,
    recipient_location_id   BIGINT,
    subject                 VARCHAR(255) NOT NULL,
    payload_json            JSONB NOT NULL,
    dedup_key               VARCHAR(160),
    status                  notification_status NOT NULL DEFAULT 'PENDING',
    attempts                SMALLINT NOT NULL DEFAULT 0,
    max_attempts            SMALLINT NOT NULL DEFAULT 5,
    next_attempt_at         TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processing_started_at   TIMESTAMPTZ,
    processed_at            TIMESTAMPTZ,
    last_error              TEXT,
    obsolete_reason         TEXT,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              TIMESTAMPTZ,
    CONSTRAINT pk_notification_outbox PRIMARY KEY (id),
    CONSTRAINT uq_notification_outbox_dedup_key UNIQUE (dedup_key),
    CONSTRAINT fk_outbox_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_outbox_recipient_user FOREIGN KEY (recipient_user_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_outbox_recipient_location FOREIGN KEY (recipient_location_id)
        REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_outbox_dispatch_queue
    ON notification_outbox (status, priority, next_attempt_at, id)
    WHERE status IN ('PENDING', 'PENDING_RETRY', 'PROCESSING');

CREATE INDEX IF NOT EXISTS idx_outbox_incident ON notification_outbox (incident_id, status);
CREATE INDEX IF NOT EXISTS idx_outbox_status_created ON notification_outbox (status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_outbox_channel_status ON notification_outbox (channel, status);

CREATE OR REPLACE TRIGGER trg_no_delete_notification_outbox
    BEFORE DELETE ON notification_outbox
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_notification_outbox
    BEFORE UPDATE ON notification_outbox
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- -----------------------------------------------------------------------------
-- 4. Tabla 22 · notification_channel_settings (Canal Corporativo de Emergencias)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_channel_settings (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY,
    webhook_name        VARCHAR(100) NOT NULL DEFAULT 'Canal Central de Emergencias',
    webhook_url         VARCHAR(500),
    is_webhook_enabled  BOOLEAN NOT NULL DEFAULT FALSE,
    last_tested_at      TIMESTAMPTZ,
    last_test_status    VARCHAR(32),
    last_test_error     TEXT,
    updated_by          BIGINT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at          TIMESTAMPTZ,
    CONSTRAINT pk_notification_channel_settings PRIMARY KEY (id),
    CONSTRAINT chk_webhook_url_https CHECK (
        webhook_url IS NULL OR webhook_url ~* '^https://[a-z0-9.-]+\\.[a-z]{2,}(:[0-9]+)?(/.*)?$'
    ),
    CONSTRAINT fk_notif_settings_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE OR REPLACE TRIGGER trg_no_delete_notification_channel_settings
    BEFORE DELETE ON notification_channel_settings
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_notification_channel_settings
    BEFORE UPDATE ON notification_channel_settings
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
