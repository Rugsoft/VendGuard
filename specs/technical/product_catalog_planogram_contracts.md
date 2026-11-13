# CONTRATOS TÉCNICOS: CATÁLOGO MAESTRO DE PRODUCTOS, CONSUMIBLES Y PLANOGRAMA POR MÁQUINA (`MACHINE PLANOGRAM & SLOT MATRIX`)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)\
**Módulo:** `32-product-catalog-planogram` (Feature 22 de `docs/features_pendientes.md`)\
**Documento Canónico:** `specs/technical/product_catalog_planogram_contracts.md`\
**Referencia Funcional:** [`specs/32-product-catalog-planogram/spec.md`](../32-product-catalog-planogram/spec.md) y [`specs/functional/product_catalog_planogram_spec.md`](../functional/product_catalog_planogram_spec.md) (v2.0 · `RF-01` a `RF-05`, `D1`–`D3`, `C1`–`C3`, `QA 1`–`QA 4`)\
**Motor de Persistencia:** PostgreSQL 16+ (Supabase / Motor Local de Certificación) · Motor Único\
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`)\
**Conformidad Constitucional:** Artículos I, II, III (3.1, 3.2, 3.3), IV, V (5.4) y VI

---

## 1. Visión General de Endpoints y Superficies del Módulo

| Método | Ruta | Rol / Middleware | Propósito Operativo |
| :--- | :--- | :--- | :--- |
| **Catálogo Maestro de Productos y Consumibles (`RF-01`)** | | | |
| `GET` | `/api/coordinator/products` | `InternalAuthMiddleware(COORDINATOR)` | Lista el catálogo maestro `vending_products` con filtros `?category=`, `?requires_cold_chain=`, `?status=ACTIVE\|INACTIVE\|ALL` y `?search=`, incluyendo KPIs y `active_slots_count` por producto. |
| `POST` | `/api/coordinator/products` | `InternalAuthMiddleware(COORDINATOR)` | Crea un producto o consumible en `vending_products` validando SKU único (`409 DUPLICATE_PRODUCT_SKU`), cadena de frío (`D3`, `QA 1`), PVP y unidad por categoría (`C2`) y auditando `VENDING_PRODUCT_CREATED`. |
| `PUT` | `/api/coordinator/products/{id}` | `InternalAuthMiddleware(COORDINATOR)` | Actualiza un producto existente; bloquea cambio de `sku` (`409 SKU_CHANGE_FORBIDDEN`, `C1`) y bloquea cambios de `category` o `requires_cold_chain` que entren en conflicto con máquinas asignadas (`409 COLD_CHAIN_MACHINE_INCOMPATIBLE` / `PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE`, `D3`). |
| `PATCH` | `/api/coordinator/products/{id}/status` | `InternalAuthMiddleware(COORDINATOR)` | Baja lógica (`is_active = false`) o reactivación (`is_active = true`) en `vending_products`; devuelve `affected_active_slots_count` (`C1`, `RF-01.3`). |
| **Matriz de Planograma por Máquina y Clonación (`RF-02`, `RF-03`, `RF-04`)** | | | |
| `GET` | `/api/coordinator/machines/{id}/planogram` | `InternalAuthMiddleware(COORDINATOR)` | Devuelve la ficha de la máquina, el resumen de métricas del planograma (`stockout_slots_count`, `low_stock_slots_count`, `fill_rate_percent`, etc.), los carriles ordenados físicamente (`QA 3`) incluyendo `unit_cost_eur`, y las máquinas compatibles para clonación (`D1`). |
| `PUT` | `/api/coordinator/machines/{id}/planogram` | `InternalAuthMiddleware(COORDINATOR)` | Reemplaza/actualiza la matriz completa de carriles mediante `UPSERT` sobre `(machine_id, slot_code)` y marca `is_active = false` en los carriles ausentes sin `DELETE` físico (`C1`), validando el cerrojo sanitario `Art. II` y la matriz `MachineType × ProductCategory` (`D3`). |
| `PUT` | `/api/coordinator/machines/{id}/planogram/slots/{slot_code}` | `InternalAuthMiddleware(COORDINATOR)` | Crea o actualiza (`UPSERT`) un carril individual de la máquina (`RF-02.2`, `D3`, `C1`, `C2`). |
| `DELETE` | `/api/coordinator/machines/{id}/planogram/slots/{slot_code}` | `InternalAuthMiddleware(COORDINATOR)` | Baja lógica idempotente (`is_active = FALSE`, `200 OK`) de un carril del planograma sin `DELETE` físico (`C1`, Art. III.1). |
| `POST` | `/api/coordinator/machines/{id}/planogram/apply-preset` | `InternalAuthMiddleware(COORDINATOR)` | Aplica en 1 clic la plantilla estándar de dominio del `machine.model` (o `machine.machine_type`) con `initial_stock_mode` (`'ZERO'` \| `'FULL'`), preservando `current_estimated_qty` en carriles cuyo `product_id` no cambie (`D1`, `C3`, `RF-04.1`). |
| `POST` | `/api/coordinator/machines/{id}/planogram/clone` | `InternalAuthMiddleware(COORDINATOR)` | Clona el planograma activo desde `source_machine_id` con `initial_stock_mode` (`'ZERO'` \| `'FULL'`), omitiendo productos dados de baja (`skipped_inactive_slots`, `QA 3`) y validando compatibilidad sanitaria/física con la máquina destino (`D1`, `D3`, `C3`, `RF-04.2`). |
| **Integración con CRUD de Máquinas (`RF-03.2`, `QA 4`)** | | | |
| `PUT` | `/api/coordinator/admin/machines/{id}` | `InternalAuthMiddleware(COORDINATOR)` | Extiende `AdminMachineService::updateMachine`: si cambia `machine_type` y la máquina tiene carriles activos incompatibles con el nuevo tipo, rechaza con `409 PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE` (`D3`, `QA 4`). |
| **Consulta Móvil del Reponedor con Opacidad de Costes (`RF-05.2`, `D2`, `QA 2`, Art. V.4)** | | | |
| `GET` | `/api/restocker/my-route` | `InternalAuthMiddleware(RESTOCKER)` | Extiende el bootstrap móvil del reponedor incluyendo `machines` (resumen de máquinas activas ubicadas en sus zonas vivas `PRIMARY` y `BACKUP` con contadores `stockout_slots_count`, `low_stock_slots_count` y `fill_rate_percent`, `QA 2`). |
| `GET` | `/api/restocker/machines` | `InternalAuthMiddleware(RESTOCKER)` | Lista las máquinas activas de las zonas operativas vivas del reponedor autenticado con resumen de estado de planograma y filtro opcional `?zone_id=` o `?stock_alert_only=true` (`D2`, `QA 2`). |
| `GET` | `/api/restocker/machines/{id}/planogram` | `InternalAuthMiddleware(RESTOCKER)` | Devuelve el planograma en **solo lectura** de una máquina de las zonas vivas del reponedor (`403 MACHINE_OUTSIDE_RESTOCKER_ZONES` si pertenece a otra zona, `QA 2`), **omitiendo estrictamente `unit_cost_eur` y márgenes** (`D2`, Art. V.4). |

---

## 2. Contrato de Esquema de Base de Datos (PostgreSQL 16+ / Supabase)

La persistencia del Módulo 32 se materializa en la migración incremental `database/supabase_migrations/028_product_catalog_and_planogram_slots.sql` y converge 1:1 en `database/supabase_init.sql`, elevando el esquema de **41 a 43 tablas** (`vending_products` Tabla 42 y `machine_planogram_slots` Tabla 43) y declarando **37 tipos `ENUM` nativos** en total (`QA 1`). Esta migración incorpora `vending_product_category` y `vending_product_unit_type`.

### 2.1. Nuevos Tipos Enumerados y Ampliación de `audit_entity_type`

```sql
-- Categoría del producto comercial o consumible (RF-01.1, QA 1)
DO $do$
BEGIN
    CREATE TYPE vending_product_category AS ENUM (
        'COLD_DRINK',
        'SNACK',
        'PERISHABLE_FOOD',
        'HOT_BEVERAGE_INGREDIENT',
        'CUP_STIRRER_CONSUMABLE'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Unidad de medida de carga y control de stock (RF-01.1, QA 1)
DO $do$
BEGIN
    CREATE TYPE vending_product_unit_type AS ENUM (
        'UNIT',
        'GRAMS',
        'SLEEVE'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Entidades de auditoría para catálogo y planogramas (C3)
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'VENDING_PRODUCT';
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'MACHINE_PLANOGRAM';
```

### 2.2. Definición DDL de Tabla 42 · `vending_products` (`RF-01.1`, `D3`, `C1`, `C2`, `QA 1`, Art. III.1)

```sql
CREATE TABLE IF NOT EXISTS vending_products (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY,
    sku                   VARCHAR(32) NOT NULL,
    name                  VARCHAR(120) NOT NULL,
    category              vending_product_category NOT NULL,
    unit_type             vending_product_unit_type NOT NULL DEFAULT 'UNIT',
    requires_cold_chain   BOOLEAN NOT NULL DEFAULT FALSE,
    max_shelf_life_days   INTEGER,
    unit_cost_eur         NUMERIC(10, 2) NOT NULL,
    sale_price_eur        NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    is_active             BOOLEAN NOT NULL DEFAULT TRUE,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_vending_products PRIMARY KEY (id),
    CONSTRAINT uq_vending_products_sku UNIQUE (sku),
    CONSTRAINT chk_vending_products_sku_format CHECK (sku ~ '^[A-Z0-9_-]{3,32}$'),
    CONSTRAINT chk_vending_products_name_not_blank CHECK (char_length(btrim(name)) >= 2),
    CONSTRAINT chk_vending_products_unit_cost CHECK (unit_cost_eur > 0.00 AND unit_cost_eur <= 9999.99),
    CONSTRAINT chk_vending_products_sale_price CHECK (
        sale_price_eur >= 0.00 AND sale_price_eur <= 9999.99
        AND (
            category IN ('HOT_BEVERAGE_INGREDIENT', 'CUP_STIRRER_CONSUMABLE')
            OR sale_price_eur > 0.00
        )
    ),
    CONSTRAINT chk_vending_products_unit_type_coherence CHECK (
        (category IN ('COLD_DRINK', 'SNACK', 'PERISHABLE_FOOD') AND unit_type = 'UNIT')
        OR (category = 'HOT_BEVERAGE_INGREDIENT' AND unit_type IN ('GRAMS', 'UNIT'))
        OR (category = 'CUP_STIRRER_CONSUMABLE' AND unit_type IN ('SLEEVE', 'UNIT'))
    ),
    CONSTRAINT chk_vending_products_perishable_cold_chain CHECK (
        category <> 'PERISHABLE_FOOD' OR requires_cold_chain = TRUE
    ),
    CONSTRAINT chk_vending_products_cold_chain_allowed_categories CHECK (
        requires_cold_chain = FALSE
        OR category IN ('PERISHABLE_FOOD', 'COLD_DRINK', 'SNACK')
    ),
    CONSTRAINT chk_vending_products_shelf_life CHECK (
        (requires_cold_chain = TRUE AND max_shelf_life_days IS NOT NULL AND max_shelf_life_days BETWEEN 1 AND 3650)
        OR (requires_cold_chain = FALSE AND (max_shelf_life_days IS NULL OR max_shelf_life_days BETWEEN 1 AND 3650))
    )
);

CREATE INDEX IF NOT EXISTS idx_vending_products_category_active
    ON vending_products (category, is_active);

-- Blindaje Constitucional Art. III.1: Prohibición absoluta de DELETE físico
DROP TRIGGER IF EXISTS trg_no_delete_vending_products ON vending_products;
CREATE TRIGGER trg_no_delete_vending_products
    BEFORE DELETE ON vending_products
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();
```

### 2.3. Definición DDL de Tabla 43 · `machine_planogram_slots` (`RF-02.1`, `C1`, `C2`, Art. III.1)

```sql
CREATE TABLE IF NOT EXISTS machine_planogram_slots (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY,
    machine_id            BIGINT NOT NULL,
    slot_code             VARCHAR(24) NOT NULL,
    product_id            BIGINT NOT NULL,
    max_capacity          INTEGER NOT NULL,
    current_estimated_qty INTEGER NOT NULL DEFAULT 0,
    min_threshold         INTEGER NOT NULL DEFAULT 2,
    is_active             BOOLEAN NOT NULL DEFAULT TRUE,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_machine_planogram_slots PRIMARY KEY (id),
    CONSTRAINT uq_machine_planogram_slot UNIQUE (machine_id, slot_code),
    CONSTRAINT fk_machine_planogram_slots_machine FOREIGN KEY (machine_id)
        REFERENCES machines (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_planogram_slots_product FOREIGN KEY (product_id)
        REFERENCES vending_products (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_machine_planogram_slot_code_format CHECK (slot_code ~ '^[A-Z0-9_]{1,24}$'),
    CONSTRAINT chk_machine_planogram_max_capacity CHECK (max_capacity BETWEEN 1 AND 10000),
    CONSTRAINT chk_machine_planogram_min_threshold CHECK (min_threshold >= 0 AND min_threshold < max_capacity),
    CONSTRAINT chk_machine_planogram_estimated_qty CHECK (
        current_estimated_qty >= 0 AND current_estimated_qty <= max_capacity
    )
);

CREATE INDEX IF NOT EXISTS idx_machine_planogram_slots_machine_active
    ON machine_planogram_slots (machine_id, is_active);

CREATE INDEX IF NOT EXISTS idx_machine_planogram_slots_product_active
    ON machine_planogram_slots (product_id, is_active);

-- Blindaje Constitucional Art. III.1: Prohibición absoluta de DELETE físico
DROP TRIGGER IF EXISTS trg_no_delete_machine_planogram_slots ON machine_planogram_slots;
CREATE TRIGGER trg_no_delete_machine_planogram_slots
    BEFORE DELETE ON machine_planogram_slots
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();
```

### 2.4. Funciones y Disparadores del Cerrojo Sanitario Bidireccional (Art. II y `D3`)

```sql
-- 1. Función helper de compatibilidad MachineType × (ProductCategory, requires_cold_chain)
CREATE OR REPLACE FUNCTION is_planogram_product_compatible_with_machine(
    p_machine_type TEXT,
    p_product_category TEXT,
    p_requires_cold_chain BOOLEAN
) RETURNS BOOLEAN
LANGUAGE plpgsql
IMMUTABLE
AS $$
BEGIN
    -- Art. II: Producto de cadena de frío exige máquina PERISHABLE_FOOD o COMBO
    IF p_requires_cold_chain IS TRUE AND p_machine_type NOT IN ('PERISHABLE_FOOD', 'COMBO') THEN
        RETURN FALSE;
    END IF;

    -- Matriz MachineType × ProductCategory (D3)
    RETURN CASE p_machine_type
        WHEN 'HOT_DRINKS' THEN p_product_category IN ('HOT_BEVERAGE_INGREDIENT', 'CUP_STIRRER_CONSUMABLE')
        WHEN 'COLD_DRINKS' THEN p_product_category IN ('COLD_DRINK')
        WHEN 'SNACKS' THEN p_product_category IN ('SNACK', 'COLD_DRINK')
        WHEN 'PERISHABLE_FOOD' THEN p_product_category IN ('PERISHABLE_FOOD', 'COLD_DRINK', 'SNACK')
        WHEN 'COMBO' THEN p_product_category IN (
            'PERISHABLE_FOOD', 'COLD_DRINK', 'SNACK',
            'HOT_BEVERAGE_INGREDIENT', 'CUP_STIRRER_CONSUMABLE'
        )
        ELSE FALSE
    END;
END;
$$;

-- 2. Disparador sobre `machine_planogram_slots` (INSERT / UPDATE de carril activo)
CREATE OR REPLACE FUNCTION enforce_planogram_slot_compatibility()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_machine_type TEXT;
    v_category TEXT;
    v_requires_cold_chain BOOLEAN;
BEGIN
    -- Si el carril se está desactivando lógicamente (is_active = FALSE), se permite siempre (C1)
    IF NEW.is_active IS FALSE THEN
        RETURN NEW;
    END IF;

    SELECT m.machine_type::text INTO v_machine_type
    FROM machines m
    WHERE m.id = NEW.machine_id;

    SELECT vp.category::text, vp.requires_cold_chain
    INTO v_category, v_requires_cold_chain
    FROM vending_products vp
    WHERE vp.id = NEW.product_id;

    IF v_requires_cold_chain IS TRUE AND v_machine_type NOT IN ('PERISHABLE_FOOD', 'COMBO') THEN
        RAISE EXCEPTION 'Violación Constitucional Art. II (COLD_CHAIN_MACHINE_INCOMPATIBLE): El producto (ID %) requiere cadena de frío y no puede asignarse a una máquina de tipo %.',
            NEW.product_id, v_machine_type
            USING ERRCODE = '45000';
    END IF;

    IF NOT is_planogram_product_compatible_with_machine(v_machine_type, v_category, v_requires_cold_chain) THEN
        RAISE EXCEPTION 'Incompatibilidad de planograma (PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE): La categoría % no es apta para una máquina de tipo %.',
            v_category, v_machine_type
            USING ERRCODE = '45000';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_enforce_planogram_cold_chain_and_category ON machine_planogram_slots;
CREATE TRIGGER trg_enforce_planogram_cold_chain_and_category
    BEFORE INSERT OR UPDATE ON machine_planogram_slots
    FOR EACH ROW EXECUTE FUNCTION enforce_planogram_slot_compatibility();

-- 3. Disparador inverso sobre `vending_products` (UPDATE de requires_cold_chain o category)
CREATE OR REPLACE FUNCTION validate_product_update_against_planograms()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_conflict_machine_code TEXT;
    v_conflict_machine_type TEXT;
BEGIN
    IF NEW.requires_cold_chain IS DISTINCT FROM OLD.requires_cold_chain
       OR NEW.category IS DISTINCT FROM OLD.category THEN

        SELECT m.code, m.machine_type::text
        INTO v_conflict_machine_code, v_conflict_machine_type
        FROM machine_planogram_slots s
        INNER JOIN machines m ON m.id = s.machine_id
        WHERE s.product_id = NEW.id
          AND s.is_active = TRUE
          AND NOT is_planogram_product_compatible_with_machine(
              m.machine_type::text,
              NEW.category::text,
              NEW.requires_cold_chain
          )
        LIMIT 1;

        IF v_conflict_machine_code IS NOT NULL THEN
            RAISE EXCEPTION 'Violación Constitucional Art. II (COLD_CHAIN_MACHINE_INCOMPATIBLE): El producto % ya está asignado en carriles activos de la máquina % (%) incompatible con el cambio.',
                NEW.sku, v_conflict_machine_code, v_conflict_machine_type
                USING ERRCODE = '45000';
        END IF;
    END IF;

    -- Inmutabilidad del SKU a nivel de motor (C1)
    IF NEW.sku IS DISTINCT FROM OLD.sku THEN
        RAISE EXCEPTION 'SKU_CHANGE_FORBIDDEN: El SKU de un producto comercial es inmutable tras su creación.'
            USING ERRCODE = '45000';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_validate_product_update_against_planograms ON vending_products;
CREATE TRIGGER trg_validate_product_update_against_planograms
    BEFORE UPDATE ON vending_products
    FOR EACH ROW EXECUTE FUNCTION validate_product_update_against_planograms();

-- 4. Disparador inverso sobre `machines` (UPDATE de machine_type)
CREATE OR REPLACE FUNCTION validate_machine_type_against_planogram()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_conflict_slot VARCHAR(24);
    v_conflict_sku VARCHAR(32);
BEGIN
    IF NEW.machine_type IS DISTINCT FROM OLD.machine_type THEN
        SELECT s.slot_code, vp.sku
        INTO v_conflict_slot, v_conflict_sku
        FROM machine_planogram_slots s
        INNER JOIN vending_products vp ON vp.id = s.product_id
        WHERE s.machine_id = NEW.id
          AND s.is_active = TRUE
          AND NOT is_planogram_product_compatible_with_machine(
              NEW.machine_type::text,
              vp.category::text,
              vp.requires_cold_chain
          )
        LIMIT 1;

        IF v_conflict_slot IS NOT NULL THEN
            RAISE EXCEPTION 'Violación Constitucional Art. II (PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE): El carril activo % (producto %) es incompatible con la nueva tipología %.',
                v_conflict_slot, v_conflict_sku, NEW.machine_type::text
                USING ERRCODE = '45000';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_validate_machine_type_against_planogram ON machines;
CREATE TRIGGER trg_validate_machine_type_against_planogram
    BEFORE UPDATE OF machine_type ON machines
    FOR EACH ROW EXECUTE FUNCTION validate_machine_type_against_planogram();
```

---

## 3. Contratos JSON de Payloads y Respuestas REST

### 3.1. Catálogo Maestro en Coordinación (`/api/coordinator/products`)

#### `GET /api/coordinator/products` — Respuesta `200 OK`
```json
{
  "kpis": {
    "total_products": 14,
    "active_products": 14,
    "inactive_products": 0,
    "cold_chain_products": 4,
    "consumable_and_ingredient_products": 6
  },
  "products": [
    {
      "id": 1,
      "sku": "SAND-JAMON-QUESO",
      "name": "Sándwich Mixto Jamón y Queso Fresco",
      "category": "PERISHABLE_FOOD",
      "category_label": "Alimento Perecedero Fresco",
      "unit_type": "UNIT",
      "unit_type_label": "Unidad",
      "requires_cold_chain": true,
      "max_shelf_life_days": 5,
      "unit_cost_eur": 1.15,
      "sale_price_eur": 2.40,
      "unit_margin_eur": 1.25,
      "is_active": true,
      "active_slots_count": 4,
      "created_at": "2026-11-12T08:00:00+00:00",
      "updated_at": "2026-11-12T08:00:00+00:00"
    }
  ]
}
```

### 3.2. Planograma por Máquina en Coordinación vs. Reponedor (Segregación Art. V.4)

#### `GET /api/coordinator/machines/{id}/planogram` (`COORDINATOR` — Incluye `unit_cost_eur`)
```json
{
  "machine": {
    "id": 1,
    "code": "MAQ-HOSP-01",
    "model": "Jofemar Vision",
    "machine_type": "PERISHABLE_FOOD",
    "machine_type_label": "Alimentos Perecederos (Frío)",
    "sanitary_status": "OK",
    "location_id": 1,
    "location_name": "Hospital Universitario Central",
    "zone_id": 1,
    "zone_name": "Zona Norte"
  },
  "summary": {
    "active_slots_count": 6,
    "stockout_slots_count": 1,
    "low_stock_slots_count": 1,
    "ok_slots_count": 4,
    "inactive_product_slots_count": 0,
    "total_capacity": 60,
    "total_estimated_qty": 38,
    "fill_rate_percent": 63.33
  },
  "slots": [
    {
      "id": 101,
      "machine_id": 1,
      "slot_code": "11",
      "product_id": 1,
      "product_sku": "SAND-JAMON-QUESO",
      "product_name": "Sándwich Mixto Jamón y Queso Fresco",
      "category": "PERISHABLE_FOOD",
      "category_label": "Alimento Perecedero Fresco",
      "unit_type": "UNIT",
      "unit_type_label": "Unidad",
      "requires_cold_chain": true,
      "max_shelf_life_days": 5,
      "unit_cost_eur": 1.15,
      "sale_price_eur": 2.40,
      "product_is_active": true,
      "max_capacity": 10,
      "current_estimated_qty": 0,
      "min_threshold": 3,
      "restock_needed_qty": 10,
      "stock_status": "STOCKOUT",
      "stock_status_label": "Agotado",
      "is_active": true,
      "updated_at": "2026-11-12T08:00:00+00:00"
    }
  ],
  "compatible_source_machines": [
    {
      "id": 4,
      "code": "MAQ-UNI-02",
      "model": "Jofemar Vision",
      "machine_type": "PERISHABLE_FOOD",
      "active_slots_count": 6
    }
  ]
}
```

#### `GET /api/restocker/machines/{id}/planogram` (`RESTOCKER` — **SIN `unit_cost_eur`**, Art. V.4 y `QA 2`)
* Si la máquina pertenece a una sede fuera de las zonas operativas vivas (`PRIMARY`/`BACKUP`) del reponedor autenticado: **`403 Forbidden` (`MACHINE_OUTSIDE_RESTOCKER_ZONES`)**.
* En caso válido (`200 OK`), devuelve `machine`, `summary` y `slots` (solo carriles activos `is_active = TRUE`), donde cada elemento de `slots` incluye `sale_price_eur` pero **jamás** contiene `unit_cost_eur` ni `unit_margin_eur`.

---

## 4. Catálogo de Códigos de Error de Dominio

| Código de Error | HTTP Status | Condición de Disparo |
| :--- | :---: | :--- |
| `DUPLICATE_PRODUCT_SKU` | `409 Conflict` | Intento de crear un producto con un `sku` ya existente (`RF-01.2`). |
| `SKU_CHANGE_FORBIDDEN` | `409 Conflict` | Intento de modificar el `sku` de un producto existente en edición (`C1`, `RF-01.2`). |
| `PERISHABLE_REQUIRES_COLD_CHAIN_AND_SHELF_LIFE` | `422 Unprocessable` | Producto `PERISHABLE_FOOD` con `requires_cold_chain = false`, o producto con `requires_cold_chain = true` sin `max_shelf_life_days` válido (`1..3650`, `D3`, `QA 1`). |
| `INVALID_SALE_PRICE` / `INVALID_UNIT_COST` / `INVALID_UNIT_TYPE` | `422 Unprocessable` | Violación de cotas de coste, PVP o unidad de medida según la categoría (`C2`, `RF-01.1`). |
| `COLD_CHAIN_MACHINE_INCOMPATIBLE` | `422` (en carril/clon) / `409` (al editar producto) | Producto con `requires_cold_chain = TRUE` sobre una máquina cuyo `machine_type` no es `PERISHABLE_FOOD` ni `COMBO` (**Art. II**, `D3`, `RF-03`). |
| `PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE` | `422` (en carril/clon) / `409` (al editar producto) | Categoría del producto incompatible con el `machine_type` según la matriz `D3` (`RF-03`). |
| `PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE` | `409 Conflict` | Intento de cambiar el `machine_type` de una máquina cuyos carriles activos son incompatibles con la nueva tipología (`D3`, `QA 4`, `RF-03.2`). |
| `PRODUCT_INACTIVE` | `422 Unprocessable` | Intento de asignar a un carril nuevo (o cambiar de producto hacia) una referencia con `vending_products.is_active = FALSE` (`C1`, `RF-02.2`). |
| `INVALID_PLANOGRAM_SLOT_BOUNDS` | `422 Unprocessable` | `slot_code` mal formado, `max_capacity` fuera de `1..10000`, `min_threshold >= max_capacity` o `current_estimated_qty > max_capacity` (`C2`, `RF-02.1`). |
| `SOURCE_PLANOGRAM_EMPTY` | `422 Unprocessable` | La máquina origen en `clone` no tiene ningún carril activo con producto activo clonable (`QA 3`, `RF-04.2`). |
| `MACHINE_OUTSIDE_RESTOCKER_ZONES` | `403 Forbidden` | Un `RESTOCKER` intenta consultar el planograma de una máquina situada fuera de sus zonas operativas vivas (`QA 2`, `RF-05.2`). |
