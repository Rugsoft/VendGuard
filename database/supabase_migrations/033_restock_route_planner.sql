-- ============================================================================
-- Módulo 37 · Planificador de Rutas Periódicas de Carga y Priorización
--             Dinámica por Demanda (Recurring Restock Schedules & Dynamic
--             Route Cadence)
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   Los Módulos 31 a 36 ya permiten demarcar el territorio del reponedor,
--   mantener el catálogo y el planograma, cargar la furgoneta, ejecutar la
--   visita in situ, recaudar y atender avisos de Nivel 1. Lo que faltaba era la
--   regla que decide QUÉ máquinas deben abastecerse cada jornada y en qué orden
--   debe recorrerlas el reponedor. Este fichero declara el modelo híbrido
--   aprobado (D1): una agenda periódica versionada por máquina y una excepción
--   diaria trazable, sin almacenar zona ni titular en la agenda (D2).
--
-- QUÉ DECLARA
--   1. Los seis tipos nativos #45 a #50: `restock_cadence_type`,
--      `restock_time_window`, `restock_override_type`, `restock_override_status`,
--      `restock_skip_reason_code` y `restock_reassignment_reason_code`
--      (RF-01.1, RF-02.1, D6, QA-4).
--   2. La ampliación de `product_loading_demand_mode` con `'TODAY_ROUTE'`
--      (RF-04.1, D5, QA-5), de `audit_entity_type` con las dos entidades nuevas
--      y de `notification_template` con la alerta interna de omisión FEFO
--      (RF-02.2, QA-1, Art. II, Art. V.4).
--   3. La Tabla 50 `machine_restock_schedules`, versionada por fila con el
--      índice único parcial `uq_machine_restock_schedule_active` sobre
--      `(machine_id) WHERE is_active = TRUE` (QA-2, Art. III.3).
--   4. La Tabla 51 `restock_route_daily_overrides`, con el ciclo
--      `ACTIVE`/`SUPERSEDED`/`REVOKED` y el índice único parcial
--      `uq_restock_override_active_machine_day` sobre
--      `(machine_id, service_date) WHERE status = 'ACTIVE'` (C1, Art. III.1).
--   5. La clave foránea `fk_machine_restock_visits_route_schedule` sobre la
--      columna ya reservada `machine_restock_visits.route_schedule_id`
--      (RF-01.1.4, RF-04.2) y su índice parcial.
--   6. La ampliación de `chk_offline_sync_operation_type` con
--      `'SKIP_RESTOCK_STOP'` (RF-05.1.1, C3).
--
-- POR QUÉ LA AGENDA NO GUARDA ZONA NI REPONEDOR (D2)
--   La zona se hereda de `locations.zone_id` y el titular efectivo del día lo
--   resuelve `RestockerZoneCoverageService` (Módulo 31). Guardar aquí una copia
--   crearía una segunda verdad que se degradaría en silencio en cuanto una
--   máquina cambie de sede o un reponedor cause baja.
--
-- POR QUÉ EL VERSIONADO ES POR FILA Y NO UN `UPDATE` (QA-2, Art. III.3)
--   `machine_restock_visits.route_schedule_id` es un enlace histórico inmutable.
--   Si la agenda se reescribiera, la visita pasada pasaría a declarar una regla
--   que nunca se aplicó. Toda edición cierra lógicamente la versión vigente
--   (`is_active = FALSE`, `deactivated_at`, `deactivated_by`) e inserta una
--   nueva fila activa en la misma transacción; el índice único parcial garantiza
--   que solo hay una activa por máquina y las cerradas siguen siendo válidas
--   para enlazar visitas capturadas offline antes del cambio.
--
-- POR QUÉ EL CICLO DE LA EXCEPCIÓN ES EXPLÍCITO (C1, Art. III.1)
--   Una omisión no se borra: se supera cuando la visita física real demuestra
--   que la parada se atendió (`SUPERSEDED` + `superseded_by_visit_id`) o se
--   revoca cuando Coordinación la deja sin efecto (`REVOKED`). El índice único
--   parcial impide dos excepciones vivas para la misma máquina y día, y el
--   histórico completo queda auditable.
--
-- POR QUÉ ES IDEMPOTENTE
--   El ejecutor registra la huella de cada migración y no la reaplica, pero
--   estas mismas declaraciones viven en `database/supabase_init.sql` (contrato
--   de correspondencia). Por eso los tipos se crean en un bloque que tolera
--   `duplicate_object`, las tablas e índices usan `IF NOT EXISTS`, los
--   disparadores se recrean con `DROP TRIGGER IF EXISTS` y la clave foránea se
--   comprueba en `pg_constraint` antes de añadirse.
--
-- POR QUÉ LOS VALORES NUEVOS DE `ENUM` NO SE USAN AQUÍ
--   PostgreSQL no deja referenciar un miembro añadido hasta que la transacción
--   que lo añadió confirme (SQLSTATE 55P04). Las agendas, excepciones y
--   auditorías las escriben los servicios de aplicación, cuando el libro ya ha
--   confirmado la migración.
--
-- QUÉ NO HACE
--   No altera el DDL de `locations` (Art. IV, QA-6), no siembra agendas ni
--   excepciones de demostración (eso es `database/supabase_seeds.sql` y
--   `SeedRunner`) y no expone endpoints ni vistas (Fases 3 a 6 del módulo).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Tipos nativos #45 a #50 (RF-01.1, RF-02.1, D6, QA-4)
-- ----------------------------------------------------------------------------

-- Frecuencia periódica de reposición de la agenda.
DO $do$
BEGIN
    CREATE TYPE restock_cadence_type AS ENUM (
        'DAILY',
        'MON_WED_FRI',
        'TUE_THU',
        'WEEKLY',
        'BIWEEKLY'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Franja horaria tipificada de acceso y carga.
DO $do$
BEGIN
    CREATE TYPE restock_time_window AS ENUM (
        'MORNING',
        'MIDDAY',
        'AFTERNOON',
        'ANY'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Naturaleza de la excepción diaria de ruta.
DO $do$
BEGIN
    CREATE TYPE restock_override_type AS ENUM (
        'SKIPPED',
        'REASSIGNED'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Ciclo de vida trazable de la excepción diaria.
DO $do$
BEGIN
    CREATE TYPE restock_override_status AS ENUM (
        'ACTIVE',
        'SUPERSEDED',
        'REVOKED'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Causas tipificadas de omisión de parada (Art. V.1).
DO $do$
BEGIN
    CREATE TYPE restock_skip_reason_code AS ENUM (
        'LOCATION_CLOSED',
        'NO_KEY_OR_ACCESS',
        'LOCAL_HOLIDAY',
        'FLEET_CAPACITY_SHORTAGE',
        'OTHER_OPERATIONAL'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Causas tipificadas de desvío puntual de parada (QA-4).
DO $do$
BEGIN
    CREATE TYPE restock_reassignment_reason_code AS ENUM (
        'ZONE_UNCOVERED',
        'LOAD_BALANCING',
        'FEFO_RESCUE',
        'OTHER_REASSIGNMENT'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;


-- ----------------------------------------------------------------------------
-- 2. Ampliación de los tipos existentes (D5, QA-5, Art. II, Art. III.3)
-- ----------------------------------------------------------------------------

-- Modo de demanda de la orden de carga M33: ruta efectiva del día.
ALTER TYPE product_loading_demand_mode ADD VALUE IF NOT EXISTS 'TODAY_ROUTE';

-- Entidades de auditoría propias del módulo: la agenda y la excepción diaria se
-- auditan contra sí mismas, no contra la máquina ni contra el usuario.
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'MACHINE_RESTOCK_SCHEDULE';
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'RESTOCK_ROUTE_DAILY_OVERRIDE';

-- Alerta interna de Coordinación cuando se omite una parada con FEFO viva.
ALTER TYPE notification_template ADD VALUE IF NOT EXISTS 'RESTOCK_FEFO_STOP_SKIPPED_ALERT';


-- ----------------------------------------------------------------------------
-- 3. Tabla 50 · machine_restock_schedules (agendas versionadas por fila)
-- ----------------------------------------------------------------------------
-- `day_of_week` usa la numeración ISO-8601 (1 = lunes, 7 = domingo) y solo se
-- admite en `WEEKLY`; `anchor_date` solo en `BIWEEKLY`; `include_weekends` solo
-- en `DAILY`. La coherencia se sella en el motor porque una consola SQL no pasa
-- por el validador de dominio (Art. I.3, Art. III.3).
CREATE TABLE IF NOT EXISTS machine_restock_schedules (
    id                     BIGINT GENERATED ALWAYS AS IDENTITY,
    machine_id             BIGINT NOT NULL,
    cadence_type           restock_cadence_type NOT NULL,
    day_of_week            SMALLINT,
    anchor_date            DATE,
    preferred_time_window  restock_time_window NOT NULL DEFAULT 'ANY',
    include_weekends       BOOLEAN NOT NULL DEFAULT FALSE,
    notes                  VARCHAR(500),
    is_active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_by             BIGINT NOT NULL,
    updated_by             BIGINT NOT NULL,
    deactivated_by         BIGINT,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at         TIMESTAMPTZ,
    CONSTRAINT pk_machine_restock_schedules PRIMARY KEY (id),
    CONSTRAINT fk_mrs_machine FOREIGN KEY (machine_id)
        REFERENCES machines (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_mrs_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_mrs_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_mrs_deactivated_by FOREIGN KEY (deactivated_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_mrs_cadence_coherence CHECK (
        (
            (cadence_type = 'WEEKLY' AND day_of_week BETWEEN 1 AND 7 AND anchor_date IS NULL)
            OR (cadence_type = 'BIWEEKLY' AND anchor_date IS NOT NULL AND day_of_week IS NULL)
            OR (cadence_type IN ('DAILY', 'MON_WED_FRI', 'TUE_THU') AND day_of_week IS NULL AND anchor_date IS NULL)
        )
        AND (include_weekends = FALSE OR cadence_type = 'DAILY')
    ),
    CONSTRAINT chk_mrs_deactivation_coherence CHECK (
        (is_active = TRUE AND deactivated_at IS NULL AND deactivated_by IS NULL)
        OR (is_active = FALSE AND deactivated_at IS NOT NULL)
    ),
    CONSTRAINT chk_mrs_notes_length CHECK (
        notes IS NULL OR char_length(btrim(notes)) BETWEEN 1 AND 500
    )
);

-- Como máximo una agenda activa por máquina; las versiones cerradas permanecen
-- inmutables y siguen siendo válidas para el enlace histórico de visitas.
CREATE UNIQUE INDEX IF NOT EXISTS uq_machine_restock_schedule_active
    ON machine_restock_schedules (machine_id)
    WHERE is_active = TRUE;

CREATE INDEX IF NOT EXISTS idx_mrs_machine_history
    ON machine_restock_schedules (machine_id, is_active, created_at DESC);

-- Prohibición de borrado físico (Art. III.1).
DROP TRIGGER IF EXISTS trg_no_delete_machine_restock_schedules ON machine_restock_schedules;
CREATE TRIGGER trg_no_delete_machine_restock_schedules
    BEFORE DELETE ON machine_restock_schedules
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

-- Marca temporal de actualización.
DROP TRIGGER IF EXISTS trg_touch_machine_restock_schedules ON machine_restock_schedules;
CREATE TRIGGER trg_touch_machine_restock_schedules
    BEFORE UPDATE ON machine_restock_schedules
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- ----------------------------------------------------------------------------
-- 4. Tabla 51 · restock_route_daily_overrides (excepciones diarias trazables)
-- ----------------------------------------------------------------------------
-- `SKIPPED` exige causa tipificada y motivo de 20 a 500 caracteres reales
-- (Art. V.1); `REASSIGNED` exige destino y causa tipificada, con nota opcional.
-- El estado terminal se sella con su coherencia: `SUPERSEDED` solo nace de una
-- visita física y `REVOKED` siempre deja rastro de quién y cuándo (C1).
CREATE TABLE IF NOT EXISTS restock_route_daily_overrides (
    id                        BIGINT GENERATED ALWAYS AS IDENTITY,
    machine_id                BIGINT NOT NULL,
    service_date              DATE NOT NULL,
    override_type             restock_override_type NOT NULL,
    status                    restock_override_status NOT NULL DEFAULT 'ACTIVE',
    original_restocker_id     BIGINT,
    target_restocker_id       BIGINT,
    skip_reason_code          restock_skip_reason_code,
    reassignment_reason_code  restock_reassignment_reason_code,
    reason_text               VARCHAR(500),
    had_active_fefo_alert     BOOLEAN NOT NULL DEFAULT FALSE,
    triggered_notification_id BIGINT,
    client_mutation_id        VARCHAR(64),
    superseded_by_visit_id    BIGINT,
    created_by                BIGINT NOT NULL,
    resolved_by               BIGINT,
    created_at                TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at               TIMESTAMPTZ,
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_restock_route_daily_overrides PRIMARY KEY (id),
    CONSTRAINT fk_rrdo_machine FOREIGN KEY (machine_id)
        REFERENCES machines (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rrdo_original_restocker FOREIGN KEY (original_restocker_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rrdo_target_restocker FOREIGN KEY (target_restocker_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rrdo_notification FOREIGN KEY (triggered_notification_id)
        REFERENCES notification_outbox (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rrdo_superseded_visit FOREIGN KEY (superseded_by_visit_id)
        REFERENCES machine_restock_visits (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rrdo_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rrdo_resolved_by FOREIGN KEY (resolved_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_rrdo_type_fields_coherence CHECK (
        (
            override_type = 'SKIPPED'
            AND skip_reason_code IS NOT NULL
            AND reassignment_reason_code IS NULL
            AND target_restocker_id IS NULL
            AND reason_text IS NOT NULL
            AND char_length(btrim(reason_text)) BETWEEN 20 AND 500
        )
        OR (
            override_type = 'REASSIGNED'
            AND target_restocker_id IS NOT NULL
            AND reassignment_reason_code IS NOT NULL
            AND skip_reason_code IS NULL
            AND (reason_text IS NULL OR char_length(btrim(reason_text)) <= 500)
        )
    ),
    CONSTRAINT chk_rrdo_status_resolution_coherence CHECK (
        (status = 'ACTIVE' AND resolved_at IS NULL AND superseded_by_visit_id IS NULL)
        OR (status = 'SUPERSEDED' AND resolved_at IS NOT NULL AND superseded_by_visit_id IS NOT NULL AND override_type = 'SKIPPED')
        OR (status = 'REVOKED' AND resolved_at IS NOT NULL)
    ),
    CONSTRAINT chk_rrdo_client_mutation_format CHECK (
        client_mutation_id IS NULL
        OR (char_length(btrim(client_mutation_id)) BETWEEN 8 AND 64 AND client_mutation_id ~ '^[A-Za-z0-9_.:-]+$')
    )
);

-- Máximo una excepción viva por máquina y día civil; el histórico no se borra.
CREATE UNIQUE INDEX IF NOT EXISTS uq_restock_override_active_machine_day
    ON restock_route_daily_overrides (machine_id, service_date)
    WHERE status = 'ACTIVE';

CREATE INDEX IF NOT EXISTS idx_rrdo_service_date_target
    ON restock_route_daily_overrides (service_date, target_restocker_id)
    WHERE status = 'ACTIVE' AND override_type = 'REASSIGNED';

-- Prohibición de borrado físico (Art. III.1).
DROP TRIGGER IF EXISTS trg_no_delete_restock_route_daily_overrides ON restock_route_daily_overrides;
CREATE TRIGGER trg_no_delete_restock_route_daily_overrides
    BEFORE DELETE ON restock_route_daily_overrides
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

-- Marca temporal de actualización.
DROP TRIGGER IF EXISTS trg_touch_restock_route_daily_overrides ON restock_route_daily_overrides;
CREATE TRIGGER trg_touch_restock_route_daily_overrides
    BEFORE UPDATE ON restock_route_daily_overrides
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- ----------------------------------------------------------------------------
-- 5. Evolución de Tabla 47 · machine_restock_visits (RF-01.1.4, RF-04.2)
-- ----------------------------------------------------------------------------
-- La columna se reservó en el Módulo 34 precisamente para este enlace. La
-- versión cerrada de una agenda sigue siendo referenciable: una visita capturada
-- offline antes de un cambio de cadencia no puede quedar huérfana (QA-2).
DO $do$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'fk_machine_restock_visits_route_schedule'
           AND conrelid = 'machine_restock_visits'::regclass
    ) THEN
        ALTER TABLE machine_restock_visits
            ADD CONSTRAINT fk_machine_restock_visits_route_schedule
            FOREIGN KEY (route_schedule_id)
            REFERENCES machine_restock_schedules (id)
            ON DELETE RESTRICT ON UPDATE CASCADE;
    END IF;
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

CREATE INDEX IF NOT EXISTS idx_machine_restock_visits_schedule
    ON machine_restock_visits (route_schedule_id)
    WHERE route_schedule_id IS NOT NULL;


-- ----------------------------------------------------------------------------
-- 6. Evolución de Tabla 34 · offline_sync_mutations (RF-05.1.1, C3)
-- ----------------------------------------------------------------------------
-- La omisión de parada viaja desde el móvil con `stop_type = 'MACHINE'` y su
-- `client_mutation_id`; el libro de idempotencia ya la admite por esa forma.
ALTER TABLE offline_sync_mutations
    DROP CONSTRAINT IF EXISTS chk_offline_sync_operation_type,
    ADD CONSTRAINT chk_offline_sync_operation_type CHECK (
        operation_type IN (
            'START_INCIDENT',
            'RESUME_INCIDENT_PENDING_INFO',
            'PAUSE_INCIDENT_PARTS',
            'PAUSE_INCIDENT_PENDING_INFO',
            'RESOLVE_INCIDENT',
            'START_PREVENTIVE',
            'COMPLETE_PREVENTIVE',
            'REINSPECT_PREVENTIVE',
            'ADD_INCIDENT_COMMENT',
            'START_L1_INCIDENT',
            'RESOLVE_L1_INCIDENT',
            'ESCALATE_INCIDENT_TO_SAT',
            'SKIP_RESTOCK_STOP'
        )
    );
