-- ============================================================================
-- Módulo 39 · Auditoría Local mediante Importación de Ficheros Estándar
--             EVA-DTS (.dex) en Ruta (Feature 33)
-- ----------------------------------------------------------------------------
-- QUÉ CREA
--   Los cinco tipos nativos #51–#55 del módulo (`dex_audit_status`,
--   `dex_import_source`, `dex_selection_match_status`, `dex_event_category` y
--   `dex_event_severity`), dos valores nuevos en enumerados existentes
--   (`audit_entity_type = 'MACHINE_DEX_AUDIT'` y
--   `notification_template = 'DEX_THERMAL_EVENT_ALERT'`) y las Tablas 52, 53 y
--   54: la cabecera inmutable del volcado, su desglose de selecciones `PA1`/`PA2`
--   y sus eventos `EA1`/`EA2`.
--
-- QUÉ NO TOCA
--   El DDL de `machines`, `machine_planogram_slots`, `machine_restock_visits`,
--   `machine_cash_collections`, `incidents` y `offline_sync_mutations` queda
--   intacto (RF-01.1). Los vínculos operativos viven como claves foráneas
--   NULL-ables en la cabecera, de modo que los Módulos 16, 34 y 35 no ganan una
--   sola columna ni cambian de forma.
--
-- BLINDAJE CONSTITUCIONAL
--   · Art. III.1: `prevent_hard_delete()` en las tres tablas.
--   · Art. III.3: las líneas son inmutables desde el `INSERT` y la cabecera
--     congela toda su captura, admitiendo solo (a) enlazar una vez `NULL` a
--     identificador mientras el volcado está `ACTIVE` y (b) la transición única
--     `ACTIVE` → `VOIDED` con motivo real (Art. V.1).
--   · Art. II.2 / QA-1: `enforce_dex_audit_sanitary_guard()` bloquea en SQL al
--     `RESTOCKER` sobre una máquina en `QUARANTINE` o con avería abierta
--     `TEMPERATURE_COLD`; Técnico y Coordinación conservan el acceso forense.
--
-- POR QUÉ ES IDEMPOTENTE
--   Los tipos se envuelven en bloques `DO` que toleran `duplicate_object`,
--   `ALTER TYPE ... ADD VALUE IF NOT EXISTS` no acumula etiquetas y todas las
--   tablas, índices, funciones y disparadores se declaran con `IF NOT EXISTS` o
--   `OR REPLACE`. `database/supabase_init.sql` ya contiene la misma forma, de
--   modo que una base nueva y una base migrada convergen al mismo contrato.
-- ============================================================================


-- -----------------------------------------------------------------------------
-- Tipos nativos #51–#55 (RF-01.1, QA-4)
-- -----------------------------------------------------------------------------
DO $do$
BEGIN
    CREATE TYPE dex_audit_status AS ENUM (
        'ACTIVE',
        'VOIDED'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE dex_import_source AS ENUM (
        'RESTOCKER_ROUTE',
        'TECHNICIAN_ROUTE',
        'COORDINATOR_CONSOLE'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE dex_selection_match_status AS ENUM (
        'EXACT_MATCH',
        'LEADING_ZERO_MATCH',
        'AMBIGUOUS',
        'UNMAPPED',
        'HOT_DRINKS_SELECTION'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE dex_event_category AS ENUM (
        'THERMAL_REFRIGERATION',
        'PAYMENT_MDB',
        'MECHANICAL_VEND',
        'POWER_CONTROLLER',
        'OTHER'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE dex_event_severity AS ENUM (
        'CRITICAL',
        'WARNING',
        'INFO'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- `ALTER TYPE ... ADD VALUE IF NOT EXISTS` dentro de la transacción del
-- ejecutor: los valores nuevos no se usan en esta misma migración, que es la
-- única restricción que PostgreSQL impone desde la versión 12.
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'MACHINE_DEX_AUDIT';
ALTER TYPE notification_template ADD VALUE IF NOT EXISTS 'DEX_THERMAL_EVENT_ALERT';


-- -----------------------------------------------------------------------------
-- Tabla 52 · machine_dex_audits — cabecera inmutable del volcado `.dex`
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS machine_dex_audits (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY,
    audit_code                  VARCHAR(24) NOT NULL,
    machine_id                  BIGINT NOT NULL,
    machine_code_snapshot       VARCHAR(32) NOT NULL,
    machine_type_snapshot       machine_type NOT NULL,
    imported_by                 BIGINT NOT NULL,
    importer_role_snapshot      user_role NOT NULL,
    importer_name_snapshot      VARCHAR(150) NOT NULL,
    import_source               dex_import_source NOT NULL,
    status                      dex_audit_status NOT NULL DEFAULT 'ACTIVE',
    -- Vínculos operativos opcionales: viven aquí para no alterar el DDL de M16,
    -- M34 ni M35 (D1, RF-01.1).
    restock_visit_id            BIGINT,
    cash_collection_id          BIGINT,
    incident_id                 BIGINT,
    original_filename           VARCHAR(255) NOT NULL,
    file_size_bytes             INT NOT NULL,
    sha256_hash                 CHAR(64) NOT NULL,
    raw_payload                 TEXT NOT NULL,
    dxs_comm_id                 VARCHAR(64),
    dxs_version                 VARCHAR(32),
    dex_machine_identifier      VARCHAR(64),
    identity_mismatch_warning   BOOLEAN NOT NULL DEFAULT FALSE,
    id4_decimal_places          SMALLINT NOT NULL DEFAULT 2,
    ignored_unknown_lines_count INT NOT NULL DEFAULT 0,
    va1_total_sales_eur         NUMERIC(12, 2) NOT NULL,
    va1_total_vend_units        BIGINT NOT NULL,
    va1_reset_sales_eur         NUMERIC(12, 2),
    va1_reset_vend_units        BIGINT,
    ca3_cashbox_eur             NUMERIC(12, 2),
    ca3_coin_tubes_eur          NUMERIC(12, 2),
    ca3_bills_in_eur            NUMERIC(12, 2),
    previous_audit_id           BIGINT,
    counter_reset_detected      BOOLEAN NOT NULL DEFAULT FALSE,
    -- QA-2: el volcado llegó con retraso y ya existía uno posterior en el tiempo.
    out_of_order_sync           BOOLEAN NOT NULL DEFAULT FALSE,
    delta_source                VARCHAR(24) NOT NULL,
    delta_sales_eur             NUMERIC(12, 2),
    delta_vend_units            BIGINT,
    client_mutation_id          VARCHAR(64),
    captured_at                 TIMESTAMPTZ NOT NULL,
    void_reason                 TEXT,
    voided_by                   BIGINT,
    voided_by_name_snapshot     VARCHAR(150),
    voided_at                   TIMESTAMPTZ,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_machine_dex_audits PRIMARY KEY (id),
    CONSTRAINT uq_machine_dex_audits_code UNIQUE (audit_code),
    CONSTRAINT fk_machine_dex_audits_machine
        FOREIGN KEY (machine_id) REFERENCES machines (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_dex_audits_imported_by
        FOREIGN KEY (imported_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_dex_audits_restock_visit
        FOREIGN KEY (restock_visit_id) REFERENCES machine_restock_visits (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_dex_audits_cash_collection
        FOREIGN KEY (cash_collection_id) REFERENCES machine_cash_collections (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_dex_audits_incident
        FOREIGN KEY (incident_id) REFERENCES incidents (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_dex_audits_previous
        FOREIGN KEY (previous_audit_id) REFERENCES machine_dex_audits (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_dex_audits_voided_by
        FOREIGN KEY (voided_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_machine_dex_audits_code_format
        CHECK (audit_code ~ '^DEX-[0-9]{4}-[0-9]{4,}$'),
    CONSTRAINT chk_machine_dex_audits_hash_format
        CHECK (sha256_hash ~ '^[0-9a-f]{64}$'),
    CONSTRAINT chk_machine_dex_audits_file_size
        CHECK (file_size_bytes BETWEEN 1 AND 1048576),
    CONSTRAINT chk_machine_dex_audits_payload_not_empty
        CHECK (char_length(raw_payload) BETWEEN 1 AND 1048576),
    CONSTRAINT chk_machine_dex_audits_id4_scale
        CHECK (id4_decimal_places BETWEEN 0 AND 4),
    CONSTRAINT chk_machine_dex_audits_snapshots_not_empty
        CHECK (
            char_length(btrim(machine_code_snapshot)) BETWEEN 1 AND 32
            AND char_length(btrim(importer_name_snapshot)) BETWEEN 1 AND 150
            AND char_length(btrim(original_filename)) BETWEEN 1 AND 255
        ),
    CONSTRAINT chk_machine_dex_audits_counters_not_negative
        CHECK (
            va1_total_sales_eur >= 0.00 AND va1_total_sales_eur <= 9999999999.99
            AND va1_total_vend_units >= 0 AND va1_total_vend_units <= 999999999
            AND (va1_reset_sales_eur IS NULL OR (va1_reset_sales_eur >= 0.00 AND va1_reset_sales_eur <= 9999999999.99))
            AND (va1_reset_vend_units IS NULL OR (va1_reset_vend_units >= 0 AND va1_reset_vend_units <= 999999999))
            AND (ca3_cashbox_eur IS NULL OR (ca3_cashbox_eur >= 0.00 AND ca3_cashbox_eur <= 9999999999.99))
            AND (ca3_coin_tubes_eur IS NULL OR (ca3_coin_tubes_eur >= 0.00 AND ca3_coin_tubes_eur <= 9999999999.99))
            AND (ca3_bills_in_eur IS NULL OR (ca3_bills_in_eur >= 0.00 AND ca3_bills_in_eur <= 9999999999.99))
            AND ignored_unknown_lines_count >= 0
        ),
    CONSTRAINT chk_machine_dex_audits_mutation_format
        CHECK (
            client_mutation_id IS NULL
            OR (
                char_length(btrim(client_mutation_id)) BETWEEN 8 AND 64
                AND client_mutation_id ~ '^[A-Za-z0-9_.:-]+$'
            )
        ),
    -- RF-04.1 / C1: cada fuente de delta tiene una forma cerrada. La rama
    -- `RESET_FALLBACK` exige al menos un parcial real del `.dex`; si no hay
    -- ninguno, la fuente honesta es `UNAVAILABLE` y los deltas quedan en NULL.
    CONSTRAINT chk_machine_dex_audits_delta_coherence
        CHECK (
            (
                delta_source = 'INITIAL_BASELINE'
                AND previous_audit_id IS NULL
                AND counter_reset_detected = FALSE
                AND delta_sales_eur IS NULL
                AND delta_vend_units IS NULL
            )
            OR (
                delta_source = 'PREVIOUS_AUDIT'
                AND previous_audit_id IS NOT NULL
                AND counter_reset_detected = FALSE
                AND delta_sales_eur IS NOT NULL
                AND delta_vend_units IS NOT NULL
            )
            OR (
                delta_source = 'RESET_FALLBACK'
                AND previous_audit_id IS NOT NULL
                AND counter_reset_detected = TRUE
                AND (delta_sales_eur IS NOT NULL OR delta_vend_units IS NOT NULL)
            )
            OR (
                delta_source = 'UNAVAILABLE'
                AND previous_audit_id IS NOT NULL
                AND counter_reset_detected = TRUE
                AND delta_sales_eur IS NULL
                AND delta_vend_units IS NULL
            )
        ),
    -- Art. V.1: la anulación lógica exige motivo real de 20 a 500 caracteres con
    -- al menos tres bloques alfanuméricos y sella autor y fecha. El estado
    -- `ACTIVE` no admite ni un residuo de anulación.
    CONSTRAINT chk_machine_dex_audits_void_coherence
        CHECK (
            (
                status = 'ACTIVE'
                AND void_reason IS NULL
                AND voided_by IS NULL
                AND voided_by_name_snapshot IS NULL
                AND voided_at IS NULL
            )
            OR (
                status = 'VOIDED'
                AND void_reason IS NOT NULL
                AND char_length(btrim(void_reason)) BETWEEN 20 AND 500
                AND btrim(void_reason) ~ '([[:alnum:]]+[^[:alnum:]]+){2,}[[:alnum:]]+'
                AND voided_by IS NOT NULL
                AND voided_by_name_snapshot IS NOT NULL
                AND char_length(btrim(voided_by_name_snapshot)) BETWEEN 1 AND 150
                AND voided_at IS NOT NULL
                AND voided_at >= created_at
            )
        )
);

-- D3 / QA-4: un mismo fichero no se importa dos veces mientras siga vigente. La
-- anulación lógica libera la huella para permitir la reimportación correcta.
CREATE UNIQUE INDEX IF NOT EXISTS uq_machine_dex_audits_machine_hash
    ON machine_dex_audits (machine_id, sha256_hash)
    WHERE status = 'ACTIVE';

-- C5: idempotencia del móvil por actor y mutación de cliente.
CREATE UNIQUE INDEX IF NOT EXISTS uq_machine_dex_audits_actor_mutation
    ON machine_dex_audits (imported_by, client_mutation_id)
    WHERE client_mutation_id IS NOT NULL;

-- D1: a lo sumo un volcado vigente vinculado a una visita o a una recaudación.
CREATE UNIQUE INDEX IF NOT EXISTS uq_machine_dex_audits_visit
    ON machine_dex_audits (restock_visit_id)
    WHERE status = 'ACTIVE' AND restock_visit_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_machine_dex_audits_cash_collection
    ON machine_dex_audits (cash_collection_id)
    WHERE status = 'ACTIVE' AND cash_collection_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_machine_dex_audits_machine_captured
    ON machine_dex_audits (machine_id, captured_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_machine_dex_audits_status_captured
    ON machine_dex_audits (status, captured_at DESC);

CREATE INDEX IF NOT EXISTS idx_machine_dex_audits_imported_by_captured
    ON machine_dex_audits (imported_by, captured_at DESC, id DESC);


-- -----------------------------------------------------------------------------
-- Tabla 53 · machine_dex_selection_lines — desglose `PA1`/`PA2` inmutable
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS machine_dex_selection_lines (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY,
    dex_audit_id                BIGINT NOT NULL,
    line_sequence               INT NOT NULL,
    raw_selection_code          VARCHAR(32) NOT NULL,
    normalized_selection_code   VARCHAR(24) NOT NULL,
    matched_slot_id             BIGINT,
    matched_slot_code_snapshot  VARCHAR(24),
    match_status                dex_selection_match_status NOT NULL,
    unit_price_eur              NUMERIC(10, 2),
    total_vend_units            BIGINT NOT NULL,
    total_sales_eur             NUMERIC(12, 2) NOT NULL,
    reset_vend_units            BIGINT,
    reset_sales_eur             NUMERIC(12, 2),
    delta_vend_units            BIGINT,
    delta_sales_eur             NUMERIC(12, 2),
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_machine_dex_selection_lines PRIMARY KEY (id),
    CONSTRAINT uq_machine_dex_selection_lines_sequence UNIQUE (dex_audit_id, line_sequence),
    -- QA-4: un `PA101` repetido dentro del mismo `.dex` se rechaza también en SQL.
    CONSTRAINT uq_machine_dex_selection_lines_code UNIQUE (dex_audit_id, normalized_selection_code),
    CONSTRAINT fk_machine_dex_selection_lines_audit
        FOREIGN KEY (dex_audit_id) REFERENCES machine_dex_audits (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_dex_selection_lines_slot
        FOREIGN KEY (matched_slot_id) REFERENCES machine_planogram_slots (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_machine_dex_selection_lines_sequence_positive
        CHECK (line_sequence >= 1),
    CONSTRAINT chk_machine_dex_selection_lines_codes_not_empty
        CHECK (
            char_length(btrim(raw_selection_code)) BETWEEN 1 AND 32
            AND char_length(btrim(normalized_selection_code)) BETWEEN 1 AND 24
        ),
    CONSTRAINT chk_machine_dex_selection_lines_units_not_negative
        CHECK (
            total_vend_units >= 0 AND total_vend_units <= 999999999
            AND total_sales_eur >= 0.00 AND total_sales_eur <= 9999999999.99
            AND (reset_vend_units IS NULL OR (reset_vend_units >= 0 AND reset_vend_units <= 999999999))
            AND (reset_sales_eur IS NULL OR (reset_sales_eur >= 0.00 AND reset_sales_eur <= 9999999999.99))
            AND (unit_price_eur IS NULL OR (unit_price_eur >= 0.00 AND unit_price_eur <= 999999.99))
        ),
    -- C4: solo los dos emparejamientos reales llevan carril; los estados de
    -- ambigüedad, ausencia y café informativo no inventan una correspondencia.
    CONSTRAINT chk_machine_dex_selection_lines_match_coherence
        CHECK (
            (
                match_status IN ('EXACT_MATCH', 'LEADING_ZERO_MATCH')
                AND matched_slot_id IS NOT NULL
                AND matched_slot_code_snapshot IS NOT NULL
                AND char_length(btrim(matched_slot_code_snapshot)) BETWEEN 1 AND 24
            )
            OR (
                match_status IN ('AMBIGUOUS', 'UNMAPPED', 'HOT_DRINKS_SELECTION')
                AND matched_slot_id IS NULL
                AND matched_slot_code_snapshot IS NULL
            )
        )
);

CREATE INDEX IF NOT EXISTS idx_machine_dex_selection_lines_audit
    ON machine_dex_selection_lines (dex_audit_id, line_sequence);

CREATE INDEX IF NOT EXISTS idx_machine_dex_selection_lines_slot
    ON machine_dex_selection_lines (matched_slot_id)
    WHERE matched_slot_id IS NOT NULL;


-- -----------------------------------------------------------------------------
-- Tabla 54 · machine_dex_event_lines — eventos `EA1`/`EA2` inmutables (QA-4)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS machine_dex_event_lines (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY,
    dex_audit_id            BIGINT NOT NULL,
    line_sequence           INT NOT NULL,
    source_block            VARCHAR(3) NOT NULL,
    event_code              VARCHAR(32) NOT NULL,
    event_category          dex_event_category NOT NULL,
    severity                dex_event_severity NOT NULL,
    occurrence_count        INT NOT NULL DEFAULT 1,
    count_since_reset       INT,
    count_since_init        INT,
    is_active_flag          BOOLEAN NOT NULL DEFAULT FALSE,
    event_occurred_at_raw   VARCHAR(32),
    description_es          VARCHAR(255) NOT NULL,
    is_thermal_alert        BOOLEAN NOT NULL DEFAULT FALSE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_machine_dex_event_lines PRIMARY KEY (id),
    CONSTRAINT uq_machine_dex_event_lines_sequence UNIQUE (dex_audit_id, line_sequence),
    CONSTRAINT fk_machine_dex_event_lines_audit
        FOREIGN KEY (dex_audit_id) REFERENCES machine_dex_audits (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_machine_dex_event_lines_sequence_positive
        CHECK (line_sequence >= 1),
    CONSTRAINT chk_machine_dex_event_lines_source_block
        CHECK (source_block IN ('EA1', 'EA2')),
    CONSTRAINT chk_machine_dex_event_lines_code_not_empty
        CHECK (char_length(btrim(event_code)) BETWEEN 1 AND 32),
    CONSTRAINT chk_machine_dex_event_lines_description_not_empty
        CHECK (char_length(btrim(description_es)) BETWEEN 1 AND 255),
    CONSTRAINT chk_machine_dex_event_lines_occurrence_positive
        CHECK (
            occurrence_count >= 1
            AND (count_since_reset IS NULL OR (count_since_reset >= 0 AND count_since_reset <= 999999999))
            AND (count_since_init IS NULL OR (count_since_init >= 0 AND count_since_init <= 999999999))
        ),
    -- Art. II / D4: el aviso sanitario solo puede colgar de un evento térmico.
    CONSTRAINT chk_machine_dex_event_lines_thermal_coherence
        CHECK (is_thermal_alert = FALSE OR event_category = 'THERMAL_REFRIGERATION')
);

CREATE INDEX IF NOT EXISTS idx_machine_dex_event_lines_audit
    ON machine_dex_event_lines (dex_audit_id, line_sequence);

CREATE INDEX IF NOT EXISTS idx_machine_dex_event_lines_category
    ON machine_dex_event_lines (dex_audit_id, event_category, severity);


-- -----------------------------------------------------------------------------
-- Art. III.1 · prohibición de borrado físico en las tres tablas
-- -----------------------------------------------------------------------------
CREATE OR REPLACE TRIGGER trg_no_delete_machine_dex_audits
    BEFORE DELETE ON machine_dex_audits
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_no_delete_machine_dex_selection_lines
    BEFORE DELETE ON machine_dex_selection_lines
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_no_delete_machine_dex_event_lines
    BEFORE DELETE ON machine_dex_event_lines
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_machine_dex_audits
    BEFORE UPDATE ON machine_dex_audits
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- -----------------------------------------------------------------------------
-- Art. III.3 · inmutabilidad de las líneas parseadas
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION prevent_dex_line_rewrite()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'ART_III_3_DEX_LINE_IMMUTABLE: Las líneas parseadas de un volcado .dex son inmutables desde su alta.'
        USING ERRCODE = '45000';
END;
$$;

CREATE OR REPLACE TRIGGER trg_immutable_machine_dex_selection_lines
    BEFORE UPDATE ON machine_dex_selection_lines
    FOR EACH ROW EXECUTE FUNCTION prevent_dex_line_rewrite();

CREATE OR REPLACE TRIGGER trg_immutable_machine_dex_event_lines
    BEFORE UPDATE ON machine_dex_event_lines
    FOR EACH ROW EXECUTE FUNCTION prevent_dex_line_rewrite();


-- -----------------------------------------------------------------------------
-- Art. III.3 / Art. V.1 · ciclo de vida por etapas de la cabecera
-- -----------------------------------------------------------------------------
-- Lo único mutable tras el `INSERT` es (a) el enlace único de una visita, una
-- recaudación o una incidencia mientras el volcado siga `ACTIVE`, y (b) la
-- anulación lógica `ACTIVE` → `VOIDED`. Cualquier otra escritura —reescribir la
-- captura, desanular, volver a enlazar o enlazar ya anulado— levanta `45000`.
CREATE OR REPLACE FUNCTION enforce_machine_dex_audits_immutability()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.status = 'VOIDED' THEN
        RAISE EXCEPTION 'ART_III_3_DEX_AUDIT_TERMINAL: El volcado % ya está anulado y no admite más escrituras.', OLD.audit_code
            USING ERRCODE = '45000';
    END IF;

    IF NEW.id IS DISTINCT FROM OLD.id
       OR NEW.audit_code IS DISTINCT FROM OLD.audit_code
       OR NEW.machine_id IS DISTINCT FROM OLD.machine_id
       OR NEW.machine_code_snapshot IS DISTINCT FROM OLD.machine_code_snapshot
       OR NEW.machine_type_snapshot IS DISTINCT FROM OLD.machine_type_snapshot
       OR NEW.imported_by IS DISTINCT FROM OLD.imported_by
       OR NEW.importer_role_snapshot IS DISTINCT FROM OLD.importer_role_snapshot
       OR NEW.importer_name_snapshot IS DISTINCT FROM OLD.importer_name_snapshot
       OR NEW.import_source IS DISTINCT FROM OLD.import_source
       OR NEW.original_filename IS DISTINCT FROM OLD.original_filename
       OR NEW.file_size_bytes IS DISTINCT FROM OLD.file_size_bytes
       OR NEW.sha256_hash IS DISTINCT FROM OLD.sha256_hash
       OR NEW.raw_payload IS DISTINCT FROM OLD.raw_payload
       OR NEW.dxs_comm_id IS DISTINCT FROM OLD.dxs_comm_id
       OR NEW.dxs_version IS DISTINCT FROM OLD.dxs_version
       OR NEW.dex_machine_identifier IS DISTINCT FROM OLD.dex_machine_identifier
       OR NEW.identity_mismatch_warning IS DISTINCT FROM OLD.identity_mismatch_warning
       OR NEW.id4_decimal_places IS DISTINCT FROM OLD.id4_decimal_places
       OR NEW.ignored_unknown_lines_count IS DISTINCT FROM OLD.ignored_unknown_lines_count
       OR NEW.va1_total_sales_eur IS DISTINCT FROM OLD.va1_total_sales_eur
       OR NEW.va1_total_vend_units IS DISTINCT FROM OLD.va1_total_vend_units
       OR NEW.va1_reset_sales_eur IS DISTINCT FROM OLD.va1_reset_sales_eur
       OR NEW.va1_reset_vend_units IS DISTINCT FROM OLD.va1_reset_vend_units
       OR NEW.ca3_cashbox_eur IS DISTINCT FROM OLD.ca3_cashbox_eur
       OR NEW.ca3_coin_tubes_eur IS DISTINCT FROM OLD.ca3_coin_tubes_eur
       OR NEW.ca3_bills_in_eur IS DISTINCT FROM OLD.ca3_bills_in_eur
       OR NEW.previous_audit_id IS DISTINCT FROM OLD.previous_audit_id
       OR NEW.counter_reset_detected IS DISTINCT FROM OLD.counter_reset_detected
       OR NEW.out_of_order_sync IS DISTINCT FROM OLD.out_of_order_sync
       OR NEW.delta_source IS DISTINCT FROM OLD.delta_source
       OR NEW.delta_sales_eur IS DISTINCT FROM OLD.delta_sales_eur
       OR NEW.delta_vend_units IS DISTINCT FROM OLD.delta_vend_units
       OR NEW.client_mutation_id IS DISTINCT FROM OLD.client_mutation_id
       OR NEW.captured_at IS DISTINCT FROM OLD.captured_at
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'ART_III_3_DEX_AUDIT_IMMUTABLE: Los datos de captura del volcado % son inmutables tras el alta.', OLD.audit_code
            USING ERRCODE = '45000';
    END IF;

    IF NEW.status IS DISTINCT FROM OLD.status THEN
        IF OLD.status <> 'ACTIVE' OR NEW.status <> 'VOIDED' THEN
            RAISE EXCEPTION 'ART_III_3_DEX_AUDIT_INVALID_TRANSITION: El volcado % solo admite la transición ACTIVE -> VOIDED.', OLD.audit_code
                USING ERRCODE = '45000';
        END IF;

        IF NEW.restock_visit_id IS DISTINCT FROM OLD.restock_visit_id
           OR NEW.cash_collection_id IS DISTINCT FROM OLD.cash_collection_id
           OR NEW.incident_id IS DISTINCT FROM OLD.incident_id THEN
            RAISE EXCEPTION 'ART_III_3_DEX_AUDIT_VOID_LINK_FORBIDDEN: La anulación del volcado % no puede enlazar ni desenlazar operaciones.', OLD.audit_code
                USING ERRCODE = '45000';
        END IF;
    ELSE
        -- Mismo estado: los vínculos solo pueden pasar una vez de NULL a valor.
        IF (OLD.restock_visit_id IS NOT NULL AND NEW.restock_visit_id IS DISTINCT FROM OLD.restock_visit_id)
           OR (OLD.cash_collection_id IS NOT NULL AND NEW.cash_collection_id IS DISTINCT FROM OLD.cash_collection_id)
           OR (OLD.incident_id IS NOT NULL AND NEW.incident_id IS DISTINCT FROM OLD.incident_id) THEN
            RAISE EXCEPTION 'ART_III_3_DEX_AUDIT_LINK_IMMUTABLE: El vínculo del volcado % ya estaba fijado y no se puede reescribir.', OLD.audit_code
                USING ERRCODE = '45000';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE OR REPLACE TRIGGER trg_guard_machine_dex_audits
    BEFORE UPDATE ON machine_dex_audits
    FOR EACH ROW EXECUTE FUNCTION enforce_machine_dex_audits_immutability();


-- -----------------------------------------------------------------------------
-- Art. II.2 / QA-1 · primacía sanitaria en el alta del Reponedor
-- -----------------------------------------------------------------------------
-- El Técnico SAT y Coordinación conservan el acceso forense incluso en
-- `QUARANTINE`, con avería `TEMPERATURE_COLD` abierta o `OUT_OF_SERVICE` (C2).
-- El Reponedor queda bloqueado en los dos supuestos sanitarios, y se le permite
-- documentar un escalado en `EXPIRED` o con `is_blocked_no_access` no sanitario.
CREATE OR REPLACE FUNCTION enforce_dex_audit_sanitary_guard()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_machine_code      VARCHAR(32);
    v_sanitary_status   sanitary_status;
    v_thermal_incident  BOOLEAN;
BEGIN
    IF NEW.importer_role_snapshot IS DISTINCT FROM 'RESTOCKER' THEN
        RETURN NEW;
    END IF;

    SELECT m.code, m.sanitary_status
      INTO v_machine_code, v_sanitary_status
      FROM machines m
     WHERE m.id = NEW.machine_id;

    IF v_sanitary_status = 'QUARANTINE' THEN
        RAISE EXCEPTION 'ART_II_SANITARY_DEX_BLOCKED: La máquina % está en cuarentena sanitaria y no admite auditoría .dex del Reponedor.', v_machine_code
            USING ERRCODE = '45000';
    END IF;

    SELECT EXISTS (
        SELECT 1
          FROM incidents i
         WHERE i.machine_id = NEW.machine_id
           AND i.category = 'TEMPERATURE_COLD'
           AND i.status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')
    ) INTO v_thermal_incident;

    IF v_thermal_incident THEN
        RAISE EXCEPTION 'ART_II_SANITARY_DEX_BLOCKED: La máquina % tiene una avería térmica abierta y no admite auditoría .dex del Reponedor.', v_machine_code
            USING ERRCODE = '45000';
    END IF;

    RETURN NEW;
END;
$$;

CREATE OR REPLACE TRIGGER trg_enforce_dex_audit_sanitary_guard
    BEFORE INSERT ON machine_dex_audits
    FOR EACH ROW EXECUTE FUNCTION enforce_dex_audit_sanitary_guard();
