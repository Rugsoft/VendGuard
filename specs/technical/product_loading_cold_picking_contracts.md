# CONTRATOS TÉCNICOS: ÓRDENES DE CARGA DE FURGONETA DE REPOSICIÓN Y PICKING CON TRAZABILIDAD DE FRÍO (`ROUTE PRODUCT LOADING & COLD CHAMBER PICKING`)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)\
**Módulo:** `33-product-loading-cold-picking` (Feature 23 de `docs/features_pendientes.md`)\
**Documento Canónico:** `specs/technical/product_loading_cold_picking_contracts.md`\
**Referencia Funcional:** [`specs/33-product-loading-cold-picking/spec.md`](../33-product-loading-cold-picking/spec.md) y [`specs/functional/product_loading_cold_picking_spec.md`](../functional/product_loading_cold_picking_spec.md) (v2.0 · `RF-01` a `RF-05`, `D1`–`D4`, `C1`–`C4`, `QA 1`–`QA 4`)\
**Motor de Persistencia:** PostgreSQL 16+ (Supabase / Motor Local de Certificación) · Motor Único\
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`)\
**Conformidad Constitucional:** Artículos I, II, III (3.1, 3.2, 3.3), IV, V (5.1, 5.4) y VI

---

## 1. Visión General de Endpoints y Superficies del Módulo

| Método | Ruta | Rol / Middleware | Propósito Operativo |
| :--- | :--- | :--- | :--- |
| **Inventario de Producto en Furgoneta por Lote (`RF-01`, `D2`, `D4`, `QA 2`, `QA 3`)** | | | |
| `GET` | `/api/coordinator/restocker-van-stocks` | `InternalAuthMiddleware(COORDINATOR)` | Lista el inventario por lote de las furgonetas de reposición con filtros `?restocker_id=`, `?category=`, `?expiry_status=EXPIRED\|EXPIRING_SOON\|OK\|ALL` y `?search=`, incluyendo KPIs y semáforo de caducidad FEFO. |
| `PUT` | `/api/coordinator/restocker-van-stocks/{restockerId}` | `InternalAuthMiddleware(COORDINATOR)` | Crea o ajusta (`UPSERT`) un lote `(restocker_id, product_id, batch_code)` en la furgoneta de un reponedor activo, exigiendo `reason` $\ge 20$ caracteres reales (`Art. V.1`), validando cadena de frío y vida útil (`QA 2`), inmutabilidad de fecha del lote (`409 BATCH_EXPIRATION_MISMATCH`, `QA 3`) y auditando `RESTOCKER_VAN_STOCK_ADJUSTED`. |
| **Propuesta Automática, Órdenes de Carga, Despacho con Frío y Liquidación en Coordinación (`RF-02`, `RF-03`, `RF-04`)** | | | |
| `GET` | `/api/coordinator/product-loading-orders/proposal` | `InternalAuthMiddleware(COORDINATOR)` | Calcula la propuesta matutina para `?restocker_id=` con `?demand_mode=ALERTS_ONLY\|ALL_GAPS` y `?safety_buffer_pct=0..50`, excluyendo máquinas en `SANITARY_QUARANTINE`/`OUT_OF_SERVICE` (`QA 1`) y descontando solo lotes no caducados en furgoneta (`D1`, `D4`). |
| `GET` | `/api/coordinator/product-loading-orders` | `InternalAuthMiddleware(COORDINATOR)` | Lista las órdenes de carga (abiertas primero por fecha descendente) con KPIs (`pending_picking_count`, `dispatched_count`, `confirmed_in_route_count`, `reconciled_today_count`), filtros `?status=` y `?restocker_id=`, y alerta si el destinatario está ausente/inactivo (`C2`). |
| `GET` | `/api/coordinator/product-loading-orders/{id}` | `InternalAuthMiddleware(COORDINATOR)` | Devuelve el detalle completo de una orden de carga, sus líneas ordenadas (`requires_cold_chain DESC`, prioridad de motivo y `sku ASC`), y la estructura lista para la Hoja de Picking A4 en 2 secciones sin importes (`D4`, `Art. V.4`). |
| `POST` | `/api/coordinator/product-loading-orders` | `InternalAuthMiddleware(COORDINATOR)` | Crea una orden individual en `PENDING_PICKING` para un `RESTOCKER` activo sin otra orden abierta (`409 ACTIVE_LOADING_ORDER_EXISTS`, `D3`), auditando `PRODUCT_LOADING_ORDER_CREATED`. |
| `POST` | `/api/coordinator/product-loading-orders/batch` | `InternalAuthMiddleware(COORDINATOR)` | Emisión masiva en lote de órdenes matutinas para toda la plantilla `RESTOCKER` activa disponible, omitiendo ausentes, con orden abierta o sin demanda sugerida (`C2`, `RF-02.3`). |
| `PUT` | `/api/coordinator/product-loading-orders/{id}` | `InternalAuthMiddleware(COORDINATOR)` | Edita una orden en `PENDING_PICKING` mediante `UPSERT` sobre `(order_id, product_id)` y baja lógica (`is_active = false`) en líneas retiradas sin `DELETE` físico (`C1`, `Art. III.1`). |
| `POST` | `/api/coordinator/product-loading-orders/{id}/dispatch` | `InternalAuthMiddleware(COORDINATOR)` | Transiciona de `PENDING_PICKING` a `DISPATCHED`, exigiendo para productos con `requires_cold_chain = TRUE` y `dispatched_qty > 0` la temperatura `cold_chamber_exit_temp_celsius` en `[-5.0, 4.0]` (`422 COLD_CHAMBER_TEMP_EXCEEDED`, `Art. II`), `batch_code` y `expiration_date` en `(CURRENT_DATE, CURRENT_DATE + max_shelf_life_days]` (`D2`, `QA 2`, `QA 3`). |
| `POST` | `/api/coordinator/product-loading-orders/{id}/reconcile` | `InternalAuthMiddleware(COORDINATOR)` | Liquida una orden en `CONFIRMED_BY_RESTOCKER` a `RECONCILED_AT_CLOSE`, sellando en `0` las líneas con `dispatched_qty = 0` (`QA 4`), calculando `expected_return_qty` y `discrepancy_qty`, exigiendo `reconciliation_notes` $\ge 20$ caracteres si hay descuadre (`Art. V.1`) y fijando `quantity_available = counted_return_qty` (`D3`). |
| `POST` | `/api/coordinator/product-loading-orders/{id}/cancel` | `InternalAuthMiddleware(COORDINATOR)` | Cancela una orden en `PENDING_PICKING` o `DISPATCHED` exigiendo `cancel_reason` $\ge 20$ caracteres reales (`Art. V.1`, `RF-03.4`). |
| **Superficie Móvil del Reponedor con Opacidad de Costes (`RF-04`, `RF-05.2`, `D3`, `C4`, Art. V.4)** | | | |
| `GET` | `/api/restocker/my-route` | `InternalAuthMiddleware(RESTOCKER)` | Extiende el bootstrap móvil del reponedor incluyendo `active_loading_order` (`DISPATCHED` o `CONFIRMED_BY_RESTOCKER`), `last_reconciled_order` (`C4`) y `van_product_stocks` ordenados por FEFO sin `unit_cost_eur` (`Art. V.4`). |
| `GET` | `/api/restocker/van-stocks` | `InternalAuthMiddleware(RESTOCKER)` | Devuelve el stock por lote de la furgoneta del propio reponedor autenticado ordenado por FEFO con semáforo `expiry_status` (`EXPIRED`, `EXPIRING_SOON`, `OK`) y sin `unit_cost_eur` (`Art. V.4`). |
| `POST` | `/api/restocker/product-loading-orders/{id}/confirm` | `InternalAuthMiddleware(RESTOCKER)` | Confirma en 1 toque la recepción de su propia orden `DISPATCHED` (`403` si es de otro reponedor), incrementando de forma atómica `restocker_van_product_stocks` por lote y pasando a `CONFIRMED_BY_RESTOCKER` (`D3`, `RF-04.1`). |
| `POST` | `/api/restocker/product-loading-orders/{id}/reconcile` | `InternalAuthMiddleware(RESTOCKER)` | Permite al propio reponedor liquidar al cierre su orden en `CONFIRMED_BY_RESTOCKER` a `RECONCILED_AT_CLOSE` bajo las mismas reglas de cuadre y justificación $\ge 20$ caracteres (`D3`, `RF-04.2`). |

---

## 2. Contrato de Esquema de Base de Datos (PostgreSQL 16+ / Supabase)

La persistencia del Módulo 33 se materializa en la migración incremental `database/supabase_migrations/029_product_loading_orders_and_cold_picking.sql` y converge 1:1 en `database/supabase_init.sql`, elevando el esquema de **43 a 46 tablas** (`restocker_van_product_stocks` Tabla 44, `product_loading_orders` Tabla 45 y `product_loading_order_items` Tabla 46) y declarando **40 tipos `ENUM` nativos** en total (`QA 4`).

### 2.1. Nuevos Tipos Enumerados y Ampliación de `audit_entity_type`

```sql
-- Estado del ciclo de vida de la orden de carga de producto (RF-03.1, D3, QA 4)
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

-- Modo de cálculo de demanda matutina (RF-02.1, D1, QA 4)
DO $do$
BEGIN
    CREATE TYPE product_loading_demand_mode AS ENUM (
        'ALERTS_ONLY',
        'ALL_GAPS'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Motivo de inclusión de la línea en la orden de carga (RF-02.1, D4, QA 4)
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

-- Entidades de auditoría para stock de furgoneta y órdenes de carga de reposición
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'RESTOCKER_VAN_STOCK';
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'PRODUCT_LOADING_ORDER';
```

### 2.2. Definición DDL de Tabla 44 · `restocker_van_product_stocks` (`RF-01.1`, `D2`, `D4`, `C1`, Art. III.1)

```sql
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
```

### 2.3. Definición DDL de Tabla 45 · `product_loading_orders` (`RF-03.1`, `D2`, `D3`, Art. II, Art. III.1, Art. V.1)

```sql
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
        CHECK (cold_chamber_exit_temp_celsius IS NULL OR (cold_chamber_exit_temp_celsius >= -5.0 AND cold_chamber_exit_temp_celsius <= 4.0)),
    CONSTRAINT chk_product_loading_orders_cancel_coherence
        CHECK (
            (status = 'CANCELLED' AND cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL AND char_length(btrim(cancel_reason)) >= 20)
            OR (status <> 'CANCELLED' AND cancelled_at IS NULL AND cancel_reason IS NULL)
        ),
    CONSTRAINT chk_product_loading_orders_reconcile_coherence
        CHECK (
            (status = 'RECONCILED_AT_CLOSE' AND reconciled_at IS NOT NULL AND reconciled_by IS NOT NULL)
            OR (status <> 'RECONCILED_AT_CLOSE' AND reconciled_at IS NULL AND reconciled_by IS NULL)
        )
);

-- Unicidad de orden activa por reponedor (D3, EARS 02.3)
CREATE UNIQUE INDEX IF NOT EXISTS uq_product_loading_orders_active_restocker
    ON product_loading_orders (restocker_id)
    WHERE status IN ('PENDING_PICKING', 'DISPATCHED', 'CONFIRMED_BY_RESTOCKER');

CREATE INDEX IF NOT EXISTS idx_product_loading_orders_status_created
    ON product_loading_orders (status, created_at DESC);
```

### 2.4. Definición DDL de Tabla 46 · `product_loading_order_items` (`RF-03.1`, `RF-04.2`, `C1`, `D2`, `D3`, Art. III.1)

```sql
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
            (van_stock_before_reconcile IS NULL AND restocked_in_machines_qty IS NULL AND withdrawn_spoilage_qty IS NULL AND expected_return_qty IS NULL AND counted_return_qty IS NULL AND discrepancy_qty IS NULL)
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
```

### 2.5. Disparadores Constitucionales (`Art. II` y `Art. III.1`)

1. **Prohibición de borrado físico (`Art. III.1`):**
   * `trg_no_delete_restocker_van_product_stocks` (`BEFORE DELETE ON restocker_van_product_stocks EXECUTE FUNCTION prevent_hard_delete()`)
   * `trg_no_delete_product_loading_orders` (`BEFORE DELETE ON product_loading_orders EXECUTE FUNCTION prevent_hard_delete()`)
   * `trg_no_delete_product_loading_order_items` (`BEFORE DELETE ON product_loading_order_items EXECUTE FUNCTION prevent_hard_delete()`)
2. **Actualización de `updated_at`:**
   * `trg_touch_restocker_van_product_stocks`, `trg_touch_product_loading_orders`, `trg_touch_product_loading_order_items` (`touch_updated_at()`).
3. **Cerrojo Sanitario de Cadena de Frío en Stock de Furgoneta y en Despacho (`Art. II`, `D2`, `QA 2`):**
   * `trg_enforce_van_stock_cold_chain_batch` (`BEFORE INSERT OR UPDATE ON restocker_van_product_stocks`): si el producto tiene `requires_cold_chain = TRUE`, exige `batch_code <> 'SIN-LOTE'` y `expiration_date IS NOT NULL`; si falla, lanza `RAISE EXCEPTION 'ART_II_COLD_CHAIN_BATCH_REQUIRED: ...' USING ERRCODE = '45000'`.
   * `trg_enforce_loading_order_cold_chain_dispatch` (`BEFORE UPDATE ON product_loading_orders`): cuando `NEW.status IN ('DISPATCHED', 'CONFIRMED_BY_RESTOCKER', 'RECONCILED_AT_CLOSE')` y existe al menos una línea activa con `dispatched_qty > 0` cuyo producto tiene `requires_cold_chain = TRUE`, exige que `NEW.cold_chamber_exit_temp_celsius IS NOT NULL AND NEW.cold_chamber_exit_temp_celsius BETWEEN -5.0 AND 4.0` y que todas esas líneas tengan `batch_code IS NOT NULL AND batch_code <> 'SIN-LOTE'` y `expiration_date IS NOT NULL`; si falla, lanza `RAISE EXCEPTION 'ART_II_COLD_CHAMBER_DISPATCH_VIOLATION: ...' USING ERRCODE = '45000'`.

---

## 3. Catálogo de Códigos de Error de Dominio y HTTP

| Código de Error | HTTP | Condición de Disparo |
| :--- | :---: | :--- |
| `INVALID_RESTOCKER_RECIPIENT` | `422` | El usuario destinatario no existe, está inactivo o su rol no es `RESTOCKER` (`C2`). |
| `ACTIVE_LOADING_ORDER_EXISTS` | `409` | El reponedor ya tiene una orden activa en `PENDING_PICKING`, `DISPATCHED` o `CONFIRMED_BY_RESTOCKER` (`D3`). |
| `LOADING_ORDER_NOT_FOUND` | `404` | No existe la orden de carga solicitada. |
| `EMPTY_LOADING_ORDER_ITEMS` | `422` | Se intenta crear o guardar una orden sin ninguna línea activa válida, o el total `dispatched_qty` al despachar es `0` (`C1`, `RF-03.3`). |
| `INVALID_LOADING_QUANTITY` | `422` | `requested_qty` fuera de `1..99999`, `dispatched_qty` fuera de `0..99999` o saldo proyectado en furgoneta `> 99999` (`C1`). |
| `PRODUCT_INACTIVE` | `422` | Se intenta añadir en `PENDING_PICKING` un producto con `vending_products.is_active = FALSE` (`C3`). |
| `COLD_CHAMBER_TEMP_EXCEEDED` | `422` | Al despachar una orden con productos `requires_cold_chain = TRUE` y `dispatched_qty > 0`, falta `cold_chamber_exit_temp_celsius` o está fuera de `[-5.0, 4.0] °C` (`D2`, `Art. II`). |
| `EXPIRED_OR_INVALID_BATCH` | `422` | Falta `batch_code` (o es `'SIN-LOTE'` en cadena de frío), formato inválido o `expiration_date <= CURRENT_DATE` (`D2`, `Art. II`). |
| `EXPIRATION_EXCEEDS_MAX_SHELF_LIFE` | `422` | `expiration_date > CURRENT_DATE + max_shelf_life_days` del producto (`QA 2`, `Art. II`). |
| `BATCH_EXPIRATION_MISMATCH` | `409` | El lote `(restocker_id, product_id, batch_code)` ya existe en la furgoneta con una `expiration_date` distinta (`QA 3`). |
| `INVALID_ORDER_STATE_TRANSITION` | `409` | Transición no permitida desde el estado actual de la orden (`RF-03`, `RF-04`). |
| `ORDER_CANCELLATION_NOT_ALLOWED` | `409` | Intento de cancelar una orden que ya está en `CONFIRMED_BY_RESTOCKER`, `RECONCILED_AT_CLOSE` o `CANCELLED` (`RF-03.4`). |
| `INVALID_JUSTIFICATION_LENGTH` | `422` | El motivo de cancelación o de ajuste manual de furgoneta tiene menos de 20 caracteres reales (`Art. V.1`). |
| `RECONCILIATION_EXCEEDS_AVAILABLE_STOCK` | `422` | En liquidación, `restocked_in_machines_qty + withdrawn_spoilage_qty > van_stock_before_reconcile` (`D3`, `RF-04.2`). |
| `RECONCILIATION_DISCREPANCY_REASON_REQUIRED` | `422` | En liquidación existe al menos una línea con `discrepancy_qty != 0` y `reconciliation_notes` tiene menos de 20 caracteres reales (`D3`, `Art. V.1`). |
| `RESTOCKER_ORDER_FORBIDDEN` | `403` | Un `RESTOCKER` intenta confirmar, liquidar o consultar una orden perteneciente a otro reponedor (`Art. V.4`). |
