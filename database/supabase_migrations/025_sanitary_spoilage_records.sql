-- ============================================================================
-- Módulo 29 · Acta de Merma y Destrucción de Lotes por Cuarentena Sanitaria
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   Levantar una cuarentena sanitaria no es cerrar una avería: es certificar
--   ante Salud Pública qué producto expuesto se retiró, cuántas unidades se
--   destruyeron, con qué lotes, a qué temperatura y con qué evidencia gráfica.
--   Hasta ahora esa decisión se confirmaba con una casilla y no dejaba un
--   documento verificable. Esta migración introduce el acta APPCC numerada e
--   inmutable que respalda cada levantamiento y que separa el dato económico
--   interno (solo Coordinación) del documento sanitario oficial.
--
-- QUÉ DECLARA
--   1. El valor `SANITARY_SPOILAGE_RECORD` en `audit_entity_type`, para que el
--      alta, el ajuste financiero y la lectura del acta tengan rastro propio y
--      no se confundan con la avería ni con el certificado que expiden.
--   2. La Tabla 40 `sanitary_spoilage_records` (22 columnas): código oficial
--      correlativo, máquina, avería y orden preventiva de origen, técnico
--      certificador in situ y usuario registrador, origen de la cuarentena,
--      unidades y lotes destruidos (o confirmación de máquina vacía), lecturas
--      térmicas de retirada y reapertura, método de destrucción, fotografía,
--      observaciones, coste estimado con su ajuste auditado y certificado
--      higiénico emitido.
--   3. La clave única `uq_sanitary_spoilage_records_code`: la numeración del
--      acta es oficial y no admite duplicados (`QA 2`).
--   4. Las seis claves foráneas `ON DELETE RESTRICT ON UPDATE CASCADE` y las
--      ocho restricciones `CHECK` que hacen imposible un acta incoherente
--      (máquina vacía con coste, cero unidades con lotes, temperaturas fuera
--      del rango físico plausible o ajuste sin justificación, `C5` y `D3`).
--   5. Los cuatro índices de consulta del libro de mermas.
--   6. La función `enforce_sanitary_spoilage_records_immutability()` y los tres
--      disparadores constitucionales `trg_no_delete_sanitary_spoilage_records`
--      (`prevent_hard_delete()`, Art. III.1),
--      `trg_touch_sanitary_spoilage_records` (`touch_updated_at()`) y
--      `trg_immutable_sanitary_spoilage_records` (Art. III.3).
--
-- POR QUÉ LOS HECHOS SANITARIOS SON INMUTABLES (Art. III.3, RNF-01)
--   El acta es la prueba documental de una destrucción de género perecedero.
--   Si sus hechos físicos pudieran corregirse después, dejaría de acreditar lo
--   que ocurrió in situ y el histórico `1..N` ante reaperturas en garantía
--   perdería sentido (`QA 2`, Art. V.6). El disparador rechaza con SQLSTATE
--   45000 cualquier cambio en el código, los vínculos, el técnico certificador,
--   el origen, las unidades, los lotes, las temperaturas, el método, la
--   fotografía, las observaciones, el certificado o el alta; solo deja pasar
--   `estimated_merchandise_loss_eur`, `loss_adjusted_by`,
--   `loss_adjustment_reason` y `updated_at`, que son el ajuste contable
--   auditado de Coordinación (`C5`, `QA 4`).
--
-- POR QUÉ LA MIGRACIÓN ES IDEMPOTENTE
--   El ejecutor registra la huella de cada migración y no la reaplica, pero la
--   misma declaración vive en `database/supabase_init.sql` para que una base
--   creada desde cero y una base migrada converjan. El valor del enumerado se
--   añade con `IF NOT EXISTS`, la tabla y los índices usan `IF NOT EXISTS`, la
--   función se recrea y los disparadores se recrean tras su `DROP`.
--
-- QUÉ NO HACE
--   No siembra actas históricas (T-SPOILAGE-02), no implementa el validador ni
--   el servicio (Fases 2 a 4) y no guarda importes en el documento A4: la
--   segregación financiera es una regla de serialización, no una columna
--   ausente.
-- ============================================================================

ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'SANITARY_SPOILAGE_RECORD';

-- ----------------------------------------------------------------------------
-- 1. Tabla 40 · sanitary_spoilage_records — acta oficial inmutable por episodio
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sanitary_spoilage_records (
    id                              BIGINT GENERATED ALWAYS AS IDENTITY,
    record_code                     VARCHAR(32) NOT NULL,
    machine_id                      BIGINT NOT NULL,
    incident_id                     BIGINT,
    maintenance_order_id            BIGINT,
    technician_id                   BIGINT NOT NULL,
    registered_by                   BIGINT NOT NULL,
    quarantine_origin               VARCHAR(32) NOT NULL,
    discarded_units_count           INTEGER NOT NULL,
    empty_machine_confirmed         BOOLEAN NOT NULL DEFAULT FALSE,
    affected_batch_codes            TEXT NOT NULL,
    cabin_temp_at_removal           NUMERIC(4,1) NOT NULL,
    restored_stabilized_temp_c      NUMERIC(4,1) NOT NULL,
    disposal_method                 VARCHAR(40) NOT NULL,
    evidence_photo_url              VARCHAR(255) NOT NULL,
    sanitary_notes                  TEXT NOT NULL,
    estimated_merchandise_loss_eur  NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    loss_adjusted_by                BIGINT,
    loss_adjustment_reason          TEXT,
    certificate_code                VARCHAR(32) NOT NULL,
    created_at                      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_sanitary_spoilage_records PRIMARY KEY (id),
    CONSTRAINT uq_sanitary_spoilage_records_code UNIQUE (record_code),
    CONSTRAINT fk_spoilage_records_machine FOREIGN KEY (machine_id)
        REFERENCES machines (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_spoilage_records_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_spoilage_records_preventive_order FOREIGN KEY (maintenance_order_id)
        REFERENCES preventive_orders (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_spoilage_records_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_spoilage_records_registered_by FOREIGN KEY (registered_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_spoilage_records_loss_adjusted_by FOREIGN KEY (loss_adjusted_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,

    -- El origen se acota aquí para que un camino futuro no pueda inventar una
    -- cuarentena sin historia que la explique (`QA 2`).
    CONSTRAINT chk_spoilage_records_origin CHECK (
        quarantine_origin IN ('BIOLOGICAL_CLOCK_4H', 'PREVENTIVE_NON_CONFORMITY', 'MANUAL_SANITARY_LOCK')
    ),
    CONSTRAINT chk_spoilage_records_units_bounds CHECK (
        discarded_units_count BETWEEN 0 AND 500
    ),
    -- Máquina vacía y máquina con producto son dos actas distintas: la primera
    -- exige confirmación expresa y método no aplicable (`D3`); la segunda exige
    -- un método de destrucción real y prohíbe la confirmación de vacía (`C5`).
    CONSTRAINT chk_spoilage_records_empty_coherence CHECK (
        (discarded_units_count = 0 AND empty_machine_confirmed = TRUE AND disposal_method = 'NOT_APPLICABLE_EMPTY')
        OR (discarded_units_count > 0 AND empty_machine_confirmed = FALSE AND disposal_method IN (
            'ON_SITE_ORGANIC_SEALED',
            'RETURN_TO_CENTRAL_BIOHAZARD',
            'AUTHORIZED_WASTE_MANAGER'
        ))
    ),
    CONSTRAINT chk_spoilage_records_batch_codes_len CHECK (
        char_length(btrim(affected_batch_codes)) BETWEEN 3 AND 500
    ),
    CONSTRAINT chk_spoilage_records_temps_bounds CHECK (
        cabin_temp_at_removal BETWEEN -5.0 AND 35.0
        AND restored_stabilized_temp_c BETWEEN -5.0 AND 35.0
    ),
    CONSTRAINT chk_spoilage_records_photo_not_empty CHECK (
        char_length(btrim(evidence_photo_url)) BETWEEN 5 AND 255
    ),
    CONSTRAINT chk_spoilage_records_notes_len CHECK (
        char_length(btrim(sanitary_notes)) BETWEEN 20 AND 1000
    ),
    -- Coherencia contable estricta (`C5`): un acta de cero unidades no puede
    -- llevar coste ni ajuste; una con unidades exige coste positivo y acotado.
    CONSTRAINT chk_spoilage_records_loss_non_negative CHECK (
        (discarded_units_count = 0 AND estimated_merchandise_loss_eur = 0.00 AND loss_adjusted_by IS NULL AND loss_adjustment_reason IS NULL)
        OR (discarded_units_count > 0 AND estimated_merchandise_loss_eur > 0.00 AND estimated_merchandise_loss_eur <= 99999.99)
    ),
    CONSTRAINT chk_spoilage_records_adjustment_coherence CHECK (
        (loss_adjusted_by IS NULL AND loss_adjustment_reason IS NULL)
        OR (discarded_units_count > 0 AND loss_adjusted_by IS NOT NULL AND loss_adjustment_reason IS NOT NULL AND char_length(btrim(loss_adjustment_reason)) BETWEEN 20 AND 500)
    )
);

-- ----------------------------------------------------------------------------
-- 2. Índices de lectura del libro de mermas y de la ficha por máquina
-- ----------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_spoilage_records_machine_created
    ON sanitary_spoilage_records (machine_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_spoilage_records_incident
    ON sanitary_spoilage_records (incident_id)
    WHERE incident_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_spoilage_records_preventive_order
    ON sanitary_spoilage_records (maintenance_order_id)
    WHERE maintenance_order_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_spoilage_records_technician_created
    ON sanitary_spoilage_records (technician_id, created_at DESC);

-- ----------------------------------------------------------------------------
-- 3. Disparadores constitucionales (Art. III.1 y Art. III.3, RNF-01)
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION enforce_sanitary_spoilage_records_immutability()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF OLD.record_code IS DISTINCT FROM NEW.record_code
       OR OLD.machine_id IS DISTINCT FROM NEW.machine_id
       OR OLD.incident_id IS DISTINCT FROM NEW.incident_id
       OR OLD.maintenance_order_id IS DISTINCT FROM NEW.maintenance_order_id
       OR OLD.technician_id IS DISTINCT FROM NEW.technician_id
       OR OLD.registered_by IS DISTINCT FROM NEW.registered_by
       OR OLD.quarantine_origin IS DISTINCT FROM NEW.quarantine_origin
       OR OLD.discarded_units_count IS DISTINCT FROM NEW.discarded_units_count
       OR OLD.empty_machine_confirmed IS DISTINCT FROM NEW.empty_machine_confirmed
       OR OLD.affected_batch_codes IS DISTINCT FROM NEW.affected_batch_codes
       OR OLD.cabin_temp_at_removal IS DISTINCT FROM NEW.cabin_temp_at_removal
       OR OLD.restored_stabilized_temp_c IS DISTINCT FROM NEW.restored_stabilized_temp_c
       OR OLD.disposal_method IS DISTINCT FROM NEW.disposal_method
       OR OLD.evidence_photo_url IS DISTINCT FROM NEW.evidence_photo_url
       OR OLD.sanitary_notes IS DISTINCT FROM NEW.sanitary_notes
       OR OLD.certificate_code IS DISTINCT FROM NEW.certificate_code
       OR OLD.created_at IS DISTINCT FROM NEW.created_at THEN
        RAISE EXCEPTION 'INMUTABILIDAD_VIOLADA (Art. III.3): Los hechos sanitarios y físicos de un Acta de Merma APPCC son inmutables tras su emisión; solo se admite el ajuste financiero auditado por Coordinación.'
            USING ERRCODE = '45000';
    END IF;
    RETURN NEW;
END;
$fn$;

DROP TRIGGER IF EXISTS trg_no_delete_sanitary_spoilage_records ON sanitary_spoilage_records;
CREATE TRIGGER trg_no_delete_sanitary_spoilage_records
    BEFORE DELETE ON sanitary_spoilage_records
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

DROP TRIGGER IF EXISTS trg_touch_sanitary_spoilage_records ON sanitary_spoilage_records;
CREATE TRIGGER trg_touch_sanitary_spoilage_records
    BEFORE UPDATE ON sanitary_spoilage_records
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

DROP TRIGGER IF EXISTS trg_immutable_sanitary_spoilage_records ON sanitary_spoilage_records;
CREATE TRIGGER trg_immutable_sanitary_spoilage_records
    BEFORE UPDATE ON sanitary_spoilage_records
    FOR EACH ROW EXECUTE FUNCTION enforce_sanitary_spoilage_records_immutability();
