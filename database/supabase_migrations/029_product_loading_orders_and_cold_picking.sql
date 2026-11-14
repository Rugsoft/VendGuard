-- ============================================================================
-- Módulo 33 · Órdenes de Carga de Furgoneta de Reposición y Picking con
--             Trazabilidad de Frío (Route Product Loading & Cold Chamber Picking)
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   El reponedor salía a ruta con lo que cabía en la furgoneta, sin una orden
--   formal que dijera QUÉ cargar, con qué LOTE y a qué TEMPERATURA salió de la
--   cámara frigorífica. Sin esa cadena no hay trazabilidad posible: no se puede
--   demostrar qué producto caducado se sirvió, ni cuadrar la merma que volvió al
--   cierre, ni separar el stock de la furgoneta por lote FEFO. El Módulo 33
--   declara el inventario por lote del vehículo, la orden de carga con su ciclo
--   de vida y sus líneas, y el cerrojo sanitario que impide despachar producto
--   refrigerado sin lote ni fecha (Art. II, D2, D3, QA 2, QA 3).
--
-- QUÉ DECLARA
--   1. Los tipos nativos #28 `product_loading_order_status`, #29
--      `product_loading_demand_mode` y #30 `product_loading_item_reason`
--      (RF-03.1, D3, QA 4).
--   2. Los valores `RESTOCKER_VAN_STOCK` y `PRODUCT_LOADING_ORDER` del enumerado
--      `audit_entity_type`: el stock por lote del vehículo y la orden de carga se
--      auditan con entidad propia (Art. III.3).
--   3. La Tabla 44 `restocker_van_product_stocks`: existencias por
--      `(restocker_id, product_id, batch_code)`, con lote normalizado
--      (`'SIN-LOTE'` en no perecederos sin lote) y cotas numéricas.
--   4. La Tabla 45 `product_loading_orders`: ciclo de vida de la orden, modo de
--      demanda, buffer de seguridad, temperatura de salida de cámara y el índice
--      único parcial que impide más de una orden activa por reponedor (D3).
--   5. La Tabla 46 `product_loading_order_items`: líneas con baja lógica
--      (`is_active = FALSE`, C1), lote y caducidad del despacho, y el cuadre
--      matemático de la liquidación al cierre (D3, QA 4).
--   6. Los disparadores de blindaje constitucional: anti-borrado físico en las
--      tres tablas (`Art. III.1`), `updated_at` (D-2) y el cerrojo sanitario de
--      frío y lote en el stock de furgoneta y en el despacho de la orden
--      (`Art. II`, D2, QA 2).
--
-- POR QUÉ EL CERROJO VIVE TAMBIÉN EN EL MOTOR (RNF-02)
--   `ProductLoadingValidator` valida antes de persistir, pero una consola SQL, un
--   importador o una migración manual no pasan por la capa de aplicación. El
--   Art. II no admite una salvaguarda que dependa de que el cliente se porte
--   bien: el producto refrigerado exige lote real y fecha de caducidad en el
--   propio motor, y la salida de cámara se rechaza con `SQLSTATE 45000`, el mismo
--   código que el arnés reconoce como rechazo constitucional.
--
-- POR QUÉ LA BAJA ES LÓGICA (Art. III.1, C1)
--   Una línea retirada de una orden no se borra: se marca `is_active = FALSE`,
--   de modo que la Hoja de Picking A4 del día siguiente conserva la evidencia de
--   lo que se planificó y se retiró. Ninguna de las tres tablas admite `DELETE`.
--
-- POR QUÉ ES IDEMPOTENTE
--   El ejecutor registra la huella de cada migración y no la reaplica, pero estas
--   mismas declaraciones viven en `database/supabase_init.sql` (contrato de
--   correspondencia). Por eso los tipos se crean en bloques que toleran
--   `duplicate_object`, las tablas e índices usan `IF NOT EXISTS` y los
--   disparadores se recrean tras su `DROP ... IF EXISTS`.
--
-- QUÉ NO HACE
--   No siembra lotes ni órdenes demo (eso es `supabase_seeds.sql` y
--   `SeedRunner`, T-PLD-01). No expone endpoints, servicios ni vistas (Fases 3 a
--   6 del módulo) y no toca el catálogo comercial ni el planograma del Módulo 32.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Tipos nativos #28, #29 y #30 (RF-03.1, D3, QA 4)
-- ----------------------------------------------------------------------------
DO $do$
BEGIN
    CREATE TYPE product_loading_order_status AS ENUM (
        'PENDING_PICKING',
        'DISPATCHED',
        'CONFIRMED_BY_RESTOCKER',
        'RECONCILED_AT_CLOSE',
        'CANCELLED'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE product_loading_demand_mode AS ENUM (
        'ALERTS_ONLY',
        'ALL_GAPS'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE product_loading_item_reason AS ENUM (
        'STOCKOUT_ALERT',
        'LOW_STOCK_ALERT',
        'ROUTE_GAP_FILL',
        'MANUAL_ADDITION'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Los valores nuevos no se usan en esta transacción a propósito: PostgreSQL no
-- deja referenciar un miembro añadido hasta que la transacción que lo añadió
-- confirme (SQLSTATE 55P04). Las filas de auditoría las escriben los servicios de
-- aplicación, cuando el libro ya ha confirmado la migración.
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'RESTOCKER_VAN_STOCK';
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'PRODUCT_LOADING_ORDER';


-- ----------------------------------------------------------------------------
-- 2. Tabla 44 · Stock de producto en furgoneta por lote (RF-01.1, D2, D4, C1)
-- ----------------------------------------------------------------------------
-- La identidad del saldo es `(restocker_id, product_id, batch_code)`: dos lotes
-- del mismo producto conviven en la furgoneta porque caducan distinto y el FEFO
-- los ordena por fecha. `'SIN-LOTE'` es el lote normalizado de los no
-- perecederos; el cerrojo de la Sección 4 impide usarlo con producto refrigerado.
CREATE TABLE IF NOT EXISTS restocker_van_product_stocks (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY,
    restocker_id          BIGINT NOT NULL,
    product_id            BIGINT NOT NULL,
    batch_code            VARCHAR(40) NOT NULL DEFAULT 'SIN-LOTE',
    expiration_date       DATE,
    quantity_available    INTEGER NOT NULL DEFAULT 0,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_restocker_van_product_stocks PRIMARY KEY (id),
    CONSTRAINT uq_restocker_van_product_batch UNIQUE (restocker_id, product_id, batch_code),
    CONSTRAINT fk_restocker_van_product_stocks_restocker
        FOREIGN KEY (restocker_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_restocker_van_product_stocks_product
        FOREIGN KEY (product_id) REFERENCES vending_products (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_restocker_van_product_stocks_batch_format
        CHECK (batch_code ~ '^[A-Z0-9_-]{2,40}$'),
    CONSTRAINT chk_restocker_van_product_stocks_qty_range
        CHECK (quantity_available >= 0 AND quantity_available <= 99999)
);

CREATE INDEX IF NOT EXISTS idx_restocker_van_product_stocks_restocker_fefo
    ON restocker_van_product_stocks (restocker_id, expiration_date ASC NULLS LAST, product_id ASC);

DROP TRIGGER IF EXISTS trg_no_delete_restocker_van_product_stocks ON restocker_van_product_stocks;
CREATE TRIGGER trg_no_delete_restocker_van_product_stocks
    BEFORE DELETE ON restocker_van_product_stocks
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

DROP TRIGGER IF EXISTS trg_touch_restocker_van_product_stocks ON restocker_van_product_stocks;
CREATE TRIGGER trg_touch_restocker_van_product_stocks
    BEFORE UPDATE ON restocker_van_product_stocks
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- ----------------------------------------------------------------------------
-- 3. Tabla 45 · Órdenes de carga de furgoneta (RF-03.1, D2, D3, Art. II)
-- ----------------------------------------------------------------------------
-- `order_code` es la identidad legible e inmutable de la orden
-- (`PLO-AAAA-NNNN`). La coherencia de los estados terminales se defiende con
-- `CHECK`: una orden cancelada sin fecha ni motivo, o liquidada sin responsable y
-- fecha, no es un estado admisible. La temperatura de salida de cámara solo
-- admite el rango físico del Art. II: nula mientras no se despacha y, cuando
-- existe, entre -5,0 °C y 4,0 °C.
CREATE TABLE IF NOT EXISTS product_loading_orders (
    id                              BIGINT GENERATED ALWAYS AS IDENTITY,
    order_code                      VARCHAR(32) NOT NULL,
    restocker_id                    BIGINT NOT NULL,
    status                          product_loading_order_status NOT NULL DEFAULT 'PENDING_PICKING',
    demand_mode                     product_loading_demand_mode NOT NULL DEFAULT 'ALERTS_ONLY',
    safety_buffer_pct               INTEGER NOT NULL DEFAULT 10,
    cold_chamber_exit_temp_celsius  NUMERIC(4, 1),
    notes                           VARCHAR(500),
    cancel_reason                   VARCHAR(500),
    reconciliation_notes            VARCHAR(500),
    created_by                      BIGINT NOT NULL,
    dispatched_by                   BIGINT,
    reconciled_by                   BIGINT,
    created_at                      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dispatched_at                   TIMESTAMPTZ,
    confirmed_at                    TIMESTAMPTZ,
    reconciled_at                   TIMESTAMPTZ,
    cancelled_at                    TIMESTAMPTZ,
    updated_at                      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_product_loading_orders PRIMARY KEY (id),
    CONSTRAINT uq_product_loading_orders_code UNIQUE (order_code),
    CONSTRAINT fk_product_loading_orders_restocker
        FOREIGN KEY (restocker_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_product_loading_orders_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_product_loading_orders_dispatched_by
        FOREIGN KEY (dispatched_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_product_loading_orders_reconciled_by
        FOREIGN KEY (reconciled_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_product_loading_orders_code_format
        CHECK (order_code ~ '^PLO-[0-9]{4}-[0-9]{4,}$'),
    CONSTRAINT chk_product_loading_orders_buffer_range
        CHECK (safety_buffer_pct >= 0 AND safety_buffer_pct <= 50),
    CONSTRAINT chk_product_loading_orders_cold_temp_range
        CHECK (cold_chamber_exit_temp_celsius IS NULL
            OR (cold_chamber_exit_temp_celsius >= -5.0 AND cold_chamber_exit_temp_celsius <= 4.0)),
    CONSTRAINT chk_product_loading_orders_cancel_coherence
        CHECK (
            (status = 'CANCELLED' AND cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL
                AND char_length(btrim(cancel_reason)) >= 20)
            OR (status <> 'CANCELLED' AND cancelled_at IS NULL AND cancel_reason IS NULL)
        ),
    CONSTRAINT chk_product_loading_orders_reconcile_coherence
        CHECK (
            (status = 'RECONCILED_AT_CLOSE' AND reconciled_at IS NOT NULL AND reconciled_by IS NOT NULL)
            OR (status <> 'RECONCILED_AT_CLOSE' AND reconciled_at IS NULL AND reconciled_by IS NULL)
        )
);

-- Unicidad de orden activa por reponedor (D3, RF-02.3): el índice es parcial
-- porque las órdenes cerradas o canceladas son histórico y no ocupan la plaza.
CREATE UNIQUE INDEX IF NOT EXISTS uq_product_loading_orders_active_restocker
    ON product_loading_orders (restocker_id)
    WHERE status IN ('PENDING_PICKING', 'DISPATCHED', 'CONFIRMED_BY_RESTOCKER');

CREATE INDEX IF NOT EXISTS idx_product_loading_orders_status_created
    ON product_loading_orders (status, created_at DESC);

DROP TRIGGER IF EXISTS trg_no_delete_product_loading_orders ON product_loading_orders;
CREATE TRIGGER trg_no_delete_product_loading_orders
    BEFORE DELETE ON product_loading_orders
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

DROP TRIGGER IF EXISTS trg_touch_product_loading_orders ON product_loading_orders;
CREATE TRIGGER trg_touch_product_loading_orders
    BEFORE UPDATE ON product_loading_orders
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- ----------------------------------------------------------------------------
-- 4. Tabla 46 · Líneas de la orden de carga (RF-03.1, RF-04.2, C1, D2, D3)
-- ----------------------------------------------------------------------------
-- El par `(order_id, product_id)` es la identidad de la línea: editar una orden
-- es `UPSERT` y retirar una línea es `is_active = FALSE`, nunca `DELETE` (C1).
-- El cuadre de la liquidación es una identidad aritmética, no una convención: o
-- los seis campos están vacíos (orden no liquidada) o
-- `expected_return_qty = van_stock_before_reconcile - restocked_in_machines_qty
-- - withdrawn_spoilage_qty` y `discrepancy_qty = counted_return_qty -
-- expected_return_qty` con no negatividad y `restocked + withdrawn <= before`.
CREATE TABLE IF NOT EXISTS product_loading_order_items (
    id                            BIGINT GENERATED ALWAYS AS IDENTITY,
    order_id                      BIGINT NOT NULL,
    product_id                    BIGINT NOT NULL,
    reason                        product_loading_item_reason NOT NULL DEFAULT 'STOCKOUT_ALERT',
    requested_qty                 INTEGER NOT NULL,
    dispatched_qty                INTEGER,
    batch_code                    VARCHAR(40),
    expiration_date               DATE,
    van_stock_before_reconcile    INTEGER,
    restocked_in_machines_qty     INTEGER,
    withdrawn_spoilage_qty        INTEGER,
    expected_return_qty           INTEGER,
    counted_return_qty            INTEGER,
    discrepancy_qty               INTEGER,
    is_active                     BOOLEAN NOT NULL DEFAULT TRUE,
    created_at                    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_product_loading_order_items PRIMARY KEY (id),
    CONSTRAINT uq_product_loading_order_item_product UNIQUE (order_id, product_id),
    CONSTRAINT fk_product_loading_order_items_order
        FOREIGN KEY (order_id) REFERENCES product_loading_orders (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_product_loading_order_items_product
        FOREIGN KEY (product_id) REFERENCES vending_products (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_product_loading_order_items_requested_qty
        CHECK (requested_qty >= 1 AND requested_qty <= 99999),
    CONSTRAINT chk_product_loading_order_items_dispatched_qty
        CHECK (dispatched_qty IS NULL OR (dispatched_qty >= 0 AND dispatched_qty <= 99999)),
    CONSTRAINT chk_product_loading_order_items_batch_format
        CHECK (batch_code IS NULL OR batch_code ~ '^[A-Z0-9_-]{2,40}$'),
    CONSTRAINT chk_product_loading_order_items_reconcile_bounds
        CHECK (
            (van_stock_before_reconcile IS NULL AND restocked_in_machines_qty IS NULL
                AND withdrawn_spoilage_qty IS NULL AND expected_return_qty IS NULL
                AND counted_return_qty IS NULL AND discrepancy_qty IS NULL)
            OR (
                van_stock_before_reconcile >= 0
                AND restocked_in_machines_qty >= 0
                AND withdrawn_spoilage_qty >= 0
                AND restocked_in_machines_qty + withdrawn_spoilage_qty <= van_stock_before_reconcile
                AND expected_return_qty = van_stock_before_reconcile - restocked_in_machines_qty - withdrawn_spoilage_qty
                AND counted_return_qty >= 0 AND counted_return_qty <= 99999
                AND discrepancy_qty = counted_return_qty - expected_return_qty
            )
        )
);

CREATE INDEX IF NOT EXISTS idx_product_loading_order_items_order_active
    ON product_loading_order_items (order_id, is_active);

DROP TRIGGER IF EXISTS trg_no_delete_product_loading_order_items ON product_loading_order_items;
CREATE TRIGGER trg_no_delete_product_loading_order_items
    BEFORE DELETE ON product_loading_order_items
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

DROP TRIGGER IF EXISTS trg_touch_product_loading_order_items ON product_loading_order_items;
CREATE TRIGGER trg_touch_product_loading_order_items
    BEFORE UPDATE ON product_loading_order_items
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- ----------------------------------------------------------------------------
-- 5. Cerrojo sanitario de frío y lote (Art. II, D2, QA 2)
-- ----------------------------------------------------------------------------
-- 5.1 · Stock de furgoneta: el producto refrigerado exige lote real y caducidad.
-- El lote `'SIN-LOTE'` es legítimo para un refresco sin frío y jamás para un
-- sándwich: sin fecha no hay FEFO y sin lote no hay trazabilidad de una merma.
CREATE OR REPLACE FUNCTION enforce_van_stock_cold_chain_batch()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_requires_cold_chain BOOLEAN;
    v_sku VARCHAR(32);
BEGIN
    SELECT vp.requires_cold_chain, vp.sku
      INTO v_requires_cold_chain, v_sku
      FROM vending_products vp
     WHERE vp.id = NEW.product_id;

    IF COALESCE(v_requires_cold_chain, FALSE) IS TRUE
       AND (NEW.batch_code IS NULL OR NEW.batch_code = 'SIN-LOTE' OR NEW.expiration_date IS NULL) THEN
        RAISE EXCEPTION 'ART_II_COLD_CHAIN_BATCH_REQUIRED: El producto refrigerado % exige lote real (distinto de SIN-LOTE) y fecha de caducidad en el stock de furgoneta.', v_sku
            USING ERRCODE = '45000';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_enforce_van_stock_cold_chain_batch ON restocker_van_product_stocks;
CREATE TRIGGER trg_enforce_van_stock_cold_chain_batch
    BEFORE INSERT OR UPDATE ON restocker_van_product_stocks
    FOR EACH ROW EXECUTE FUNCTION enforce_van_stock_cold_chain_batch();

-- 5.2 · Despacho: la orden no sale de cámara sin temperatura registrada y sin
-- lote real en cada línea refrigerada servida. El disparador se evalúa también en
-- la confirmación y en la liquidación: si una orden llegó a `DISPATCHED` con
-- datos válidos, siguen siendo válidos al cerrarse; si alguien intenta fabricar
-- ese estado por SQL, el motor lo rechaza.
CREATE OR REPLACE FUNCTION enforce_loading_order_cold_chain_dispatch()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_cold_lines INTEGER;
    v_invalid_line_sku VARCHAR(32);
BEGIN
    IF NEW.status NOT IN ('DISPATCHED', 'CONFIRMED_BY_RESTOCKER', 'RECONCILED_AT_CLOSE') THEN
        RETURN NEW;
    END IF;

    SELECT COUNT(*)
      INTO v_cold_lines
      FROM product_loading_order_items i
      INNER JOIN vending_products vp ON vp.id = i.product_id
     WHERE i.order_id = NEW.id
       AND i.is_active = TRUE
       AND COALESCE(i.dispatched_qty, 0) > 0
       AND vp.requires_cold_chain = TRUE;

    IF v_cold_lines = 0 THEN
        RETURN NEW;
    END IF;

    IF NEW.cold_chamber_exit_temp_celsius IS NULL
       OR NEW.cold_chamber_exit_temp_celsius < -5.0
       OR NEW.cold_chamber_exit_temp_celsius > 4.0 THEN
        RAISE EXCEPTION 'ART_II_COLD_CHAMBER_DISPATCH_VIOLATION: La orden % transporta producto refrigerado y exige temperatura de salida de cámara entre -5,0 °C y 4,0 °C.', NEW.order_code
            USING ERRCODE = '45000';
    END IF;

    SELECT vp.sku
      INTO v_invalid_line_sku
      FROM product_loading_order_items i
      INNER JOIN vending_products vp ON vp.id = i.product_id
     WHERE i.order_id = NEW.id
       AND i.is_active = TRUE
       AND COALESCE(i.dispatched_qty, 0) > 0
       AND vp.requires_cold_chain = TRUE
       AND (i.batch_code IS NULL OR i.batch_code = 'SIN-LOTE' OR i.expiration_date IS NULL)
     LIMIT 1;

    IF v_invalid_line_sku IS NOT NULL THEN
        RAISE EXCEPTION 'ART_II_COLD_CHAIN_BATCH_REQUIRED: La línea del producto refrigerado % no declara lote real y fecha de caducidad.', v_invalid_line_sku
            USING ERRCODE = '45000';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_enforce_loading_order_cold_chain_dispatch ON product_loading_orders;
CREATE TRIGGER trg_enforce_loading_order_cold_chain_dispatch
    BEFORE UPDATE ON product_loading_orders
    FOR EACH ROW EXECUTE FUNCTION enforce_loading_order_cold_chain_dispatch();
