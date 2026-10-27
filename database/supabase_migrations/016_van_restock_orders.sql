-- ============================================================================
-- Módulo 23 · Órdenes de Carga de Furgoneta y Cruce de Stock en Triaje
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
-- El Módulo 18 modeló el saldo declarado de cada furgoneta, pero la reposición
-- matutina seguía siendo un acto manual sin rastro: Coordinación cruzaba de
-- memoria las alertas de umbral con las piezas pedidas en averías activas y el
-- almacén entregaba una caja que nadie podía auditar. Sin una orden formal no
-- había forma de distinguir lo solicitado de lo realmente servido, de conservar
-- una línea retirada antes del despacho ni de demostrar quién recibió qué.
--
-- QUÉ DECLARA
--   1. El valor `VAN_RESTOCK_ORDER` del enumerado `audit_entity_type`: la orden
--      se audita con tipo propio, no como edición del repuesto ni del técnico.
--   2. La Tabla 32 `van_restock_orders`, cabecera del albarán de carga, con su
--      ciclo de estados, la coherencia de cada rama (despacho, confirmación y
--      cancelación) y el índice único parcial que impide que un técnico tenga
--      dos órdenes abiertas a la vez (D3).
--   3. La Tabla 33 `van_restock_order_items`, líneas con los snapshots que
--      justifican la cantidad sugerida, la doble cantidad (solicitada frente a
--      despachada) y la baja lógica (`removed_at` / `removed_by`) que exige el
--      Art. III.1 al retirar una pieza durante la edición.
--   4. Los disparadores constitucionales `trg_no_delete_*`
--      (`prevent_hard_delete()`) y `trg_touch_*` (`touch_updated_at()`) en
--      ambas tablas.
--
-- NOTA SOBRE LA IDEMPOTENCIA
-- El ejecutor registra la huella de cada migración y no la reaplica, pero la
-- misma declaración vive también en `database/supabase_init.sql` para que una
-- base creada desde cero y una base migrada converjan. Todo se declara con
-- `IF NOT EXISTS`, los índices con `IF NOT EXISTS` y los disparadores se
-- recrean con `DROP TRIGGER IF EXISTS` / `CREATE TRIGGER`.
--
-- NOTA SOBRE EL ENUMERADO Y LA TRANSACCIÓN
-- `ALTER TYPE ... ADD VALUE IF NOT EXISTS` se ejecuta dentro de la transacción
-- que abre el ejecutor. Desde PostgreSQL 12 está permitido, y este fichero no
-- usa el valor nuevo en la misma transacción: las tablas declaran sus estados
-- como `VARCHAR` con `CHECK`, igual que el resto de ciclos del esquema.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Enumerado de auditoría (Art. III.3)
-- ----------------------------------------------------------------------------
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'VAN_RESTOCK_ORDER';

-- ----------------------------------------------------------------------------
-- 2. Tabla 32 · van_restock_orders — cabecera del albarán de carga
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS van_restock_orders (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY,
    technician_id       BIGINT NOT NULL,
    status              VARCHAR(30) NOT NULL DEFAULT 'PENDING_PICKING',
    notes               TEXT,
    created_by          BIGINT NOT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dispatched_by       BIGINT,
    dispatched_at       TIMESTAMPTZ,
    confirmed_by        BIGINT,
    confirmed_at        TIMESTAMPTZ,
    cancelled_by        BIGINT,
    cancelled_at        TIMESTAMPTZ,
    cancellation_reason TEXT,
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_van_restock_orders PRIMARY KEY (id),
    CONSTRAINT fk_van_restock_orders_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_van_restock_orders_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_van_restock_orders_dispatcher FOREIGN KEY (dispatched_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_van_restock_orders_confirmer FOREIGN KEY (confirmed_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_van_restock_orders_canceller FOREIGN KEY (cancelled_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_van_restock_orders_status CHECK (
        status IN ('PENDING_PICKING', 'DISPATCHED', 'CONFIRMED_BY_TECH', 'CANCELLED')
    ),
    CONSTRAINT chk_van_restock_orders_dispatch_consistency CHECK (
        (status = 'PENDING_PICKING' AND dispatched_at IS NULL AND dispatched_by IS NULL)
        OR (status IN ('DISPATCHED', 'CONFIRMED_BY_TECH') AND dispatched_at IS NOT NULL AND dispatched_by IS NOT NULL)
        OR (status = 'CANCELLED')
    ),
    CONSTRAINT chk_van_restock_orders_confirm_consistency CHECK (
        (status = 'CONFIRMED_BY_TECH' AND confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL)
        OR (status <> 'CONFIRMED_BY_TECH' AND confirmed_at IS NULL AND confirmed_by IS NULL)
    ),
    CONSTRAINT chk_van_restock_orders_cancel_consistency CHECK (
        (status <> 'CANCELLED' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
        OR (
            status = 'CANCELLED'
            AND cancelled_at IS NOT NULL
            AND cancelled_by IS NOT NULL
            AND cancellation_reason IS NOT NULL
            AND char_length(btrim(cancellation_reason)) >= 20
        )
    )
);

-- Máximo una orden abierta simultánea por técnico (D3, RF-01.1, RNF-02).
CREATE UNIQUE INDEX IF NOT EXISTS uq_van_restock_open_per_tech
    ON van_restock_orders (technician_id)
    WHERE status IN ('PENDING_PICKING', 'DISPATCHED');

CREATE INDEX IF NOT EXISTS idx_van_restock_orders_tech_status
    ON van_restock_orders (technician_id, status, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_van_restock_orders_status_created
    ON van_restock_orders (status, created_at DESC);

DROP TRIGGER IF EXISTS trg_no_delete_van_restock_orders ON van_restock_orders;
CREATE TRIGGER trg_no_delete_van_restock_orders
    BEFORE DELETE ON van_restock_orders
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

DROP TRIGGER IF EXISTS trg_touch_van_restock_orders ON van_restock_orders;
CREATE TRIGGER trg_touch_van_restock_orders
    BEFORE UPDATE ON van_restock_orders
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ----------------------------------------------------------------------------
-- 3. Tabla 33 · van_restock_order_items — líneas del albarán de carga
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS van_restock_order_items (
    id                           BIGINT GENERATED ALWAYS AS IDENTITY,
    order_id                     BIGINT NOT NULL,
    spare_part_id                BIGINT NOT NULL,
    current_quantity_snapshot    INTEGER NOT NULL DEFAULT 0,
    min_threshold_snapshot       INTEGER NOT NULL DEFAULT 0,
    pending_request_qty_snapshot INTEGER NOT NULL DEFAULT 0,
    requested_qty                INTEGER NOT NULL DEFAULT 1,
    dispatched_qty               INTEGER,
    reason                       VARCHAR(30) NOT NULL DEFAULT 'MANUAL',
    removed_at                   TIMESTAMPTZ,
    removed_by                   BIGINT,
    created_at                   TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                   TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_van_restock_order_items PRIMARY KEY (id),
    CONSTRAINT uq_van_restock_order_item_part UNIQUE (order_id, spare_part_id),
    CONSTRAINT fk_van_restock_items_order FOREIGN KEY (order_id)
        REFERENCES van_restock_orders (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_van_restock_items_part FOREIGN KEY (spare_part_id)
        REFERENCES spare_parts (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_van_restock_items_remover FOREIGN KEY (removed_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_van_restock_items_current_snapshot CHECK (
        current_quantity_snapshot BETWEEN -999 AND 9999
    ),
    CONSTRAINT chk_van_restock_items_threshold_snapshot CHECK (
        min_threshold_snapshot BETWEEN 0 AND 9999
    ),
    CONSTRAINT chk_van_restock_items_pending_snapshot CHECK (
        pending_request_qty_snapshot BETWEEN 0 AND 9999
    ),
    CONSTRAINT chk_van_restock_items_requested_qty CHECK (
        requested_qty BETWEEN 1 AND 9999
    ),
    CONSTRAINT chk_van_restock_items_dispatched_qty CHECK (
        dispatched_qty IS NULL OR (dispatched_qty BETWEEN 0 AND 9999)
    ),
    CONSTRAINT chk_van_restock_items_reason CHECK (
        reason IN ('LOW_STOCK', 'PENDING_REQUEST', 'BOTH', 'MANUAL')
    ),
    -- Baja lógica coherente (QA 1): una línea retirada conserva siempre su
    -- instante y su actor; una línea viva no arrastra ninguno de los dos.
    CONSTRAINT chk_van_restock_items_removed_consistency CHECK (
        (removed_at IS NULL AND removed_by IS NULL)
        OR (removed_at IS NOT NULL AND removed_by IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_van_restock_items_order
    ON van_restock_order_items (order_id)
    WHERE removed_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_van_restock_items_part
    ON van_restock_order_items (spare_part_id);

DROP TRIGGER IF EXISTS trg_no_delete_van_restock_order_items ON van_restock_order_items;
CREATE TRIGGER trg_no_delete_van_restock_order_items
    BEFORE DELETE ON van_restock_order_items
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

DROP TRIGGER IF EXISTS trg_touch_van_restock_order_items ON van_restock_order_items;
CREATE TRIGGER trg_touch_van_restock_order_items
    BEFORE UPDATE ON van_restock_order_items
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
