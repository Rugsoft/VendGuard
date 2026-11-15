-- ============================================================================
-- Módulo 34 · Visita de Reposición In Situ, Control FEFO de Caducidades y
--             Limpieza Diaria (In-Situ Restock Visit & FEFO Expiry Control)
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   El planograma (Tabla 43) conoce cuántas unidades caben y cuántas quedan
--   estimadas en cada carril, pero no QUÉ LOTE ni con QUÉ CADUCIDAD quedó dentro
--   tras la última visita. Sin ese dato no hay control FEFO posible: no se puede
--   avisar de un carril caducado, ni exigir su retirada antes de abastecer, ni
--   ordenar los lotes de la furgoneta por la fecha más próxima a vencer. Tampoco
--   existía un registro histórico de lo que el reponedor hizo a pie de máquina
--   —temperatura de cabina al abrir la puerta, retiradas por caducidad o daño,
--   checklist diario de higiene y evidencia fotográfica—, que es la prueba
--   documental que el Art. II exige para proteger al consumidor final.
--   Este fichero amplía el carril con su lote vigente y declara las dos tablas
--   históricas e inmutables de la visita de reposición (Tablas 47 y 48).
--
-- QUÉ DECLARA
--   1. El tipo nativo #31 `restock_visit_status` (`'COMPLETED'`,
--      `'ABORTED_THERMAL_BREACH'`) y el valor `MACHINE_RESTOCK_VISIT` del
--      enumerado `audit_entity_type` (Art. III.3).
--   2. La evolución de la Tabla 43 `machine_planogram_slots` con
--      `current_batch_code VARCHAR(40) NULL` y `current_expiration_date DATE
--      NULL`, más el formato del lote (`D1`, `RF-01.1`).
--   3. La Tabla 47 `machine_restock_visits`: cabecera de la visita con su
--      lectura térmica, checklist de higiene, precinto, foto, nota interna,
--      ticket SAT vinculado e idempotencia `(restocker_id, client_mutation_id)`.
--   4. La Tabla 48 `machine_restock_visit_items`: desglose carril a carril con
--      las cotas aritméticas del `C1` y el snapshot contable de la merma
--      comercial (`unit_cost_snapshot_eur`, `commercial_spoilage_loss_eur`).
--   5. El blindaje constitucional completo: prohibición de borrado físico
--      (`prevent_hard_delete()`, Art. III.1), inmutabilidad absoluta frente a
--      `UPDATE` (`prevent_restock_visit_mutation()`, Art. III.3) y el cerrojo
--      sanitario en el motor (`enforce_restock_visit_sanitary_guard()` y
--      `enforce_restock_visit_item_guard()`, Art. II, RNF-02).
--
-- POR QUÉ EL CERROJO VIVE TAMBIÉN EN EL MOTOR (RNF-02)
--   `MachineRestockVisitValidator` y `MachineRestockVisitService` validan antes
--   de persistir, pero una consola SQL, un importador o una migración manual no
--   pasan por ellos. El Art. II no admite una salvaguarda que dependa de que el
--   cliente se porte bien: una visita `'COMPLETED'` en una máquina refrigerada
--   sin lectura térmica, o por encima del techo de 4,0 °C (8,0 °C en bebidas
--   frías), se rechaza con `SQLSTATE 45000`; y una visita abortada por rotura de
--   frío no puede colar una sola unidad nueva en ningún carril.
--
-- POR QUÉ LA VISITA ES INMUTABLE (Art. III.1 y Art. III.3)
--   La visita es un hecho histórico: la prueba de lo que se retiró, de lo que se
--   cargó y de la temperatura que había. Rectificarla después sería reescribir la
--   historia sanitaria. Por eso ninguna de las dos tablas admite `DELETE` ni
--   `UPDATE`: la corrección se hace con otra visita, que queda también auditada.
--
-- POR QUÉ ES IDEMPOTENTE
--   El ejecutor registra la huella de cada migración y no la reaplica, pero
--   estas mismas declaraciones viven en `database/supabase_init.sql` (contrato
--   de correspondencia). Por eso los tipos se crean en bloques que toleran
--   `duplicate_object`, las columnas usan `ADD COLUMN IF NOT EXISTS`, las tablas
--   e índices `IF NOT EXISTS` y los disparadores se recrean tras su
--   `DROP ... IF EXISTS`.
--
-- QUÉ NO HACE
--   No siembra lotes en carriles ni visitas de ejemplo (eso es
--   `supabase_seeds.sql` y `SeedRunner`, T-RVF-01). No expone endpoints, servicios
--   ni vistas (Fases 2 a 6 del módulo) y no toca el catálogo comercial, la orden
--   de carga del Módulo 33 ni la máquina.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Tipo nativo #31 y entidad de auditoría (RF-01.2, Art. III.3)
-- ----------------------------------------------------------------------------
DO $do$
BEGIN
    CREATE TYPE restock_visit_status AS ENUM (
        'COMPLETED',
        'ABORTED_THERMAL_BREACH'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- El valor nuevo no se usa en esta transacción a propósito: PostgreSQL no deja
-- referenciar un miembro añadido hasta que la transacción que lo añadió
-- confirme (SQLSTATE 55P04). Las filas de auditoría las escriben los servicios
-- de aplicación, cuando el libro ya ha confirmado la migración.
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'MACHINE_RESTOCK_VISIT';


-- ----------------------------------------------------------------------------
-- 2. Evolución de la Tabla 43 · lote y caducidad vigentes en el carril
--    (RF-01.1, D1, QA 1)
-- ----------------------------------------------------------------------------
-- `current_batch_code` y `current_expiration_date` describen el lote que hoy
-- ocupa el carril y su vencimiento. El par se recalcula en cada visita
-- `'COMPLETED'` (T-RVF-07) y puede ser nulo: un carril vacío no tiene lote, y un
-- producto sin control de caducidad tampoco. El formato del lote es el mismo que
-- el del stock de furgoneta y el de las líneas de la visita, para que el cruce
-- FEFO entre carril y furgoneta no dependa de normalizaciones distintas.
ALTER TABLE machine_planogram_slots
    ADD COLUMN IF NOT EXISTS current_batch_code VARCHAR(40),
    ADD COLUMN IF NOT EXISTS current_expiration_date DATE;

ALTER TABLE machine_planogram_slots
    DROP CONSTRAINT IF EXISTS chk_machine_planogram_current_batch_format;

ALTER TABLE machine_planogram_slots
    ADD CONSTRAINT chk_machine_planogram_current_batch_format
    CHECK (current_batch_code IS NULL OR current_batch_code ~ '^[A-Z0-9_-]{2,40}$');


-- ----------------------------------------------------------------------------
-- 3. Tabla 47 · machine_restock_visits — cabecera histórica de la visita
--    (RF-01.2, D2, D3, D4, C1, Art. II, Art. III.1, Art. III.3)
-- ----------------------------------------------------------------------------
-- `visit_code` es la identidad legible e inmutable (`MRV-AAAA-NNNN`). La
-- idempotencia del móvil vive en `uq_machine_restock_visits_mutation`: si el
-- reponedor reenvía la misma mutación tras un microcorte, el servidor devuelve
-- la visita ya grabada en lugar de descontar dos veces de la furgoneta (D4).
--   * `loading_order_id` vincula la visita con la orden de carga del Módulo 33
--     que la abasteció (C4) y `route_schedule_id` queda reservado para la
--     Feature 27 sin acoplar una tabla que aún no existe (Art. VI, D4).
--   * `triggered_incident_id` apunta al ticket SAT creado o escalado en un
--     aborto térmico (QA 2); es nulo en una visita completada.
--   * `chk_machine_restock_visits_thermal_abort_coherence` sella el protocolo
--     `'ABORTED_THERMAL_BREACH'` completo: sin precinto, sin lectura térmica,
--     sin foto o con una nota de menos de 20 caracteres la visita no es un
--     aborto válido (Art. II, Art. V.1, Art. V.5).
CREATE TABLE IF NOT EXISTS machine_restock_visits (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY,
    visit_code                  VARCHAR(32) NOT NULL,
    machine_id                  BIGINT NOT NULL,
    restocker_id                BIGINT NOT NULL,
    loading_order_id            BIGINT,
    route_schedule_id           BIGINT,
    status                      restock_visit_status NOT NULL DEFAULT 'COMPLETED',
    started_at                  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at                TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cabin_temp_celsius          NUMERIC(4, 1),
    hygiene_checklist_json      JSONB NOT NULL DEFAULT '{}'::jsonb,
    precautionary_seal_applied  BOOLEAN NOT NULL DEFAULT FALSE,
    photo_evidence_url          VARCHAR(255),
    internal_notes              VARCHAR(500),
    triggered_incident_id       BIGINT,
    client_mutation_id          VARCHAR(64) NOT NULL,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_machine_restock_visits PRIMARY KEY (id),
    CONSTRAINT uq_machine_restock_visits_code UNIQUE (visit_code),
    CONSTRAINT uq_machine_restock_visits_mutation UNIQUE (restocker_id, client_mutation_id),
    CONSTRAINT fk_machine_restock_visits_machine
        FOREIGN KEY (machine_id) REFERENCES machines (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_restock_visits_restocker
        FOREIGN KEY (restocker_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_restock_visits_loading_order
        FOREIGN KEY (loading_order_id) REFERENCES product_loading_orders (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_restock_visits_triggered_incident
        FOREIGN KEY (triggered_incident_id) REFERENCES incidents (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_machine_restock_visits_code_format
        CHECK (visit_code ~ '^MRV-[0-9]{4}-[0-9]{4,}$'),
    CONSTRAINT chk_machine_restock_visits_mutation_format
        CHECK (char_length(btrim(client_mutation_id)) >= 6),
    CONSTRAINT chk_machine_restock_visits_time_order
        CHECK (completed_at >= started_at),
    CONSTRAINT chk_machine_restock_visits_temp_range
        CHECK (cabin_temp_celsius IS NULL OR (cabin_temp_celsius >= -5.0 AND cabin_temp_celsius <= 35.0)),
    CONSTRAINT chk_machine_restock_visits_thermal_abort_coherence
        CHECK (
            status <> 'ABORTED_THERMAL_BREACH'
            OR (
                precautionary_seal_applied = TRUE
                AND cabin_temp_celsius IS NOT NULL
                AND photo_evidence_url IS NOT NULL
                AND char_length(btrim(photo_evidence_url)) > 0
                AND internal_notes IS NOT NULL
                AND char_length(btrim(internal_notes)) >= 20
            )
        )
);

CREATE INDEX IF NOT EXISTS idx_machine_restock_visits_machine_created
    ON machine_restock_visits (machine_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_machine_restock_visits_restocker_created
    ON machine_restock_visits (restocker_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_machine_restock_visits_loading_order
    ON machine_restock_visits (loading_order_id)
    WHERE loading_order_id IS NOT NULL;


-- ----------------------------------------------------------------------------
-- 4. Tabla 48 · machine_restock_visit_items — desglose carril a carril
--    (RF-01.2, D1, C1, QA 3, Art. III.1, Art. III.3)
-- ----------------------------------------------------------------------------
-- El par `(visit_id, slot_code)` identifica la línea dentro de la visita. Las
-- cotas son una identidad aritmética, no una convención (C1):
-- `0 <= retiradas <= qty_before`, `qty_after = qty_before - retiradas + añadidas`
-- y `qty_after` dentro de la capacidad declarada del carril. El snapshot
-- contable se congela aquí (`QA 3`): el coste del producto en el momento de la
-- visita, no el que tenga el catálogo el mes que viene, y la merma comercial
-- como producto exacto de las unidades retiradas por ese coste. Ambos campos se
-- exponen solo en Coordinación (Art. V.4); la base no impide leerlos, la capa de
-- aplicación los omite en las respuestas del reponedor.
CREATE TABLE IF NOT EXISTS machine_restock_visit_items (
    id                              BIGINT GENERATED ALWAYS AS IDENTITY,
    visit_id                        BIGINT NOT NULL,
    slot_id                         BIGINT NOT NULL,
    slot_code                       VARCHAR(24) NOT NULL,
    product_id                      BIGINT NOT NULL,
    qty_before                      INTEGER NOT NULL DEFAULT 0,
    qty_withdrawn_expired           INTEGER NOT NULL DEFAULT 0,
    qty_withdrawn_damaged           INTEGER NOT NULL DEFAULT 0,
    qty_added                       INTEGER NOT NULL DEFAULT 0,
    qty_after                       INTEGER NOT NULL DEFAULT 0,
    withdrawn_batch_code            VARCHAR(40),
    withdrawn_expiration_date       DATE,
    loaded_batch_code               VARCHAR(40),
    loaded_expiration_date          DATE,
    unit_cost_snapshot_eur          NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    commercial_spoilage_loss_eur    NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    created_at                      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_machine_restock_visit_items PRIMARY KEY (id),
    CONSTRAINT uq_machine_restock_visit_item_slot UNIQUE (visit_id, slot_code),
    CONSTRAINT fk_machine_restock_visit_items_visit
        FOREIGN KEY (visit_id) REFERENCES machine_restock_visits (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_restock_visit_items_slot
        FOREIGN KEY (slot_id) REFERENCES machine_planogram_slots (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_restock_visit_items_product
        FOREIGN KEY (product_id) REFERENCES vending_products (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_machine_restock_visit_items_qty_bounds
        CHECK (
            qty_before >= 0 AND qty_before <= 10000
            AND qty_withdrawn_expired >= 0 AND qty_withdrawn_expired <= 10000
            AND qty_withdrawn_damaged >= 0 AND qty_withdrawn_damaged <= 10000
            AND qty_added >= 0 AND qty_added <= 10000
            AND qty_withdrawn_expired + qty_withdrawn_damaged <= qty_before
            AND qty_after = qty_before - qty_withdrawn_expired - qty_withdrawn_damaged + qty_added
            AND qty_after >= 0 AND qty_after <= 10000
        ),
    CONSTRAINT chk_machine_restock_visit_items_batch_formats
        CHECK (
            (withdrawn_batch_code IS NULL OR withdrawn_batch_code ~ '^[A-Z0-9_-]{2,40}$')
            AND (loaded_batch_code IS NULL OR loaded_batch_code ~ '^[A-Z0-9_-]{2,40}$')
            AND (qty_added = 0 OR loaded_batch_code IS NOT NULL)
        ),
    CONSTRAINT chk_machine_restock_visit_items_cost_coherence
        CHECK (
            unit_cost_snapshot_eur >= 0.00 AND unit_cost_snapshot_eur <= 9999.99
            AND commercial_spoilage_loss_eur = ROUND((qty_withdrawn_expired + qty_withdrawn_damaged) * unit_cost_snapshot_eur, 2)
        )
);

CREATE INDEX IF NOT EXISTS idx_machine_restock_visit_items_visit
    ON machine_restock_visit_items (visit_id);

CREATE INDEX IF NOT EXISTS idx_machine_restock_visit_items_product_batch
    ON machine_restock_visit_items (product_id, loaded_batch_code);


-- ----------------------------------------------------------------------------
-- 5. Blindaje constitucional (Art. III.1, Art. III.3, Art. II)
-- ----------------------------------------------------------------------------

-- 5.1 · Prohibición de borrado físico (Art. III.1)
DROP TRIGGER IF EXISTS trg_no_delete_machine_restock_visits ON machine_restock_visits;
CREATE TRIGGER trg_no_delete_machine_restock_visits
    BEFORE DELETE ON machine_restock_visits
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

DROP TRIGGER IF EXISTS trg_no_delete_machine_restock_visit_items ON machine_restock_visit_items;
CREATE TRIGGER trg_no_delete_machine_restock_visit_items
    BEFORE DELETE ON machine_restock_visit_items
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

-- 5.2 · Inmutabilidad absoluta frente a `UPDATE` (Art. III.3, C1)
-- La visita y su desglose son prueba documental sanitaria: se corrigen con otra
-- visita, nunca reescribiendo la anterior.
CREATE OR REPLACE FUNCTION prevent_restock_visit_mutation()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'ART_III_3_IMMUTABLE_VISIT_VIOLATION: La visita de reposición in situ y su desglose son históricos e inmutables; una corrección se registra como una visita nueva.'
        USING ERRCODE = '45000';
END;
$$;

DROP TRIGGER IF EXISTS trg_immutable_machine_restock_visits ON machine_restock_visits;
CREATE TRIGGER trg_immutable_machine_restock_visits
    BEFORE UPDATE ON machine_restock_visits
    FOR EACH ROW EXECUTE FUNCTION prevent_restock_visit_mutation();

DROP TRIGGER IF EXISTS trg_immutable_machine_restock_visit_items ON machine_restock_visit_items;
CREATE TRIGGER trg_immutable_machine_restock_visit_items
    BEFORE UPDATE ON machine_restock_visit_items
    FOR EACH ROW EXECUTE FUNCTION prevent_restock_visit_mutation();

-- 5.3 · Cerrojo térmico y de estado en el motor (Art. II, RNF-02, D2, QA 4)
-- La tipología manda: una máquina refrigerada no admite una visita sin lectura
-- de cabina, y una visita completada no puede declarar una cabina por encima del
-- techo sanitario. El protocolo de aborto lo tramita el servicio, que además
-- marca la cuarentena y abre el ticket al SAT.
CREATE OR REPLACE FUNCTION enforce_restock_visit_sanitary_guard()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_machine_type TEXT;
    v_machine_code VARCHAR(32);
    v_thermal_ceiling NUMERIC(4, 1);
BEGIN
    SELECT m.machine_type::text, m.code
      INTO v_machine_type, v_machine_code
      FROM machines m
     WHERE m.id = NEW.machine_id;

    IF v_machine_type IN ('PERISHABLE_FOOD', 'COMBO', 'COLD_DRINKS')
       AND NEW.cabin_temp_celsius IS NULL THEN
        RAISE EXCEPTION 'ART_II_CABIN_TEMP_REQUIRED: La máquina % (%) exige lectura de temperatura de cabina al abrir la puerta.', v_machine_code, v_machine_type
            USING ERRCODE = '45000';
    END IF;

    IF NEW.status = 'COMPLETED' AND NEW.cabin_temp_celsius IS NOT NULL THEN
        v_thermal_ceiling := CASE
            WHEN v_machine_type IN ('PERISHABLE_FOOD', 'COMBO') THEN 4.0
            WHEN v_machine_type = 'COLD_DRINKS' THEN 8.0
            ELSE NULL
        END;

        IF v_thermal_ceiling IS NOT NULL AND NEW.cabin_temp_celsius > v_thermal_ceiling THEN
            RAISE EXCEPTION 'ART_II_CABIN_TEMP_EXCEEDED: La máquina % registra % °C y supera el techo sanitario de % °C; la visita debe tramitarse como ABORTED_THERMAL_BREACH.', v_machine_code, NEW.cabin_temp_celsius, v_thermal_ceiling
                USING ERRCODE = '45000';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_enforce_restock_visit_sanitary_guard ON machine_restock_visits;
CREATE TRIGGER trg_enforce_restock_visit_sanitary_guard
    BEFORE INSERT ON machine_restock_visits
    FOR EACH ROW EXECUTE FUNCTION enforce_restock_visit_sanitary_guard();

-- Una visita abortada por rotura de frío no introduce una sola unidad: `qty_added`
-- queda en 0 en todas sus líneas, se declare en el cliente o no.
CREATE OR REPLACE FUNCTION enforce_restock_visit_item_guard()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_visit_status restock_visit_status;
BEGIN
    SELECT v.status
      INTO v_visit_status
      FROM machine_restock_visits v
     WHERE v.id = NEW.visit_id;

    IF v_visit_status = 'ABORTED_THERMAL_BREACH' AND NEW.qty_added > 0 THEN
        RAISE EXCEPTION 'ART_II_THERMAL_ABORT_ADD_FORBIDDEN: La visita % está abortada por rotura de cadena de frío y no admite unidades cargadas en el carril %.', NEW.visit_id, NEW.slot_code
            USING ERRCODE = '45000';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_enforce_restock_visit_item_guard ON machine_restock_visit_items;
CREATE TRIGGER trg_enforce_restock_visit_item_guard
    BEFORE INSERT ON machine_restock_visit_items
    FOR EACH ROW EXECUTE FUNCTION enforce_restock_visit_item_guard();
