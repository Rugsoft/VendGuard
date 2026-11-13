-- ============================================================================
-- Módulo 32 · Catálogo Maestro de Productos, Consumibles y Planograma por
--             Máquina (Machine Planogram & Slot Matrix)
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   El catálogo del Módulo 06 (`spare_parts`) modela componentes
--   electromecánicos, no producto comercial: mezclar allí alimentos y
--   consumibles de ruta rompería las reglas de caducidad, cadena de frío
--   (Art. II), unidades de medida (`UNIT`, `GRAMS`, `SLEEVE`) y precios de
--   venta. Coordinación necesita, además, conocer qué referencia ocupa cada
--   carril de cada máquina y con qué capacidad y umbral de rotura de stock.
--   Este fichero declara las dos únicas tablas nucleares del módulo —sin
--   tablas extra de plantillas (D1)— y el blindaje sanitario bidireccional
--   que impide planificar producto refrigerado en equipos sin frío (Art. II).
--
-- QUÉ DECLARA
--   1. Los tipos nativos #26 `vending_product_category` y #27
--      `vending_product_unit_type` (RF-01.1, QA 1).
--   2. Los valores `VENDING_PRODUCT` y `MACHINE_PLANOGRAM` del enumerado
--      `audit_entity_type`: el catálogo y el planograma se auditan con entidad
--      propia (C3, Art. III.3).
--   3. La Tabla 42 `vending_products`: SKU único e inmutable (C1), cotas
--      numéricas por categoría (C2, QA 1), baja lógica (`is_active`, Art. III.1)
--      y disparador anti-borrado físico.
--   4. La Tabla 43 `machine_planogram_slots`: par único `(machine_id, slot_code)`,
--      cotas de capacidad y umbral (C2), baja lógica de carriles (C1) y
--      disparador anti-borrado físico.
--   5. Los tres disparadores del cerrojo sanitario bidireccional (Art. II, D3):
--      alta/edición de carril, edición de producto y cambio de `machine_type`.
--
-- POR QUÉ EL CERROJO VIVE TAMBIÉN EN EL MOTOR (RNF-02)
--   La capa de aplicación valida antes de persistir, pero una consola SQL, una
--   migración manual o un futuro importador no pasan por ella. El Art. II no
--   admite una salvaguarda que dependa de que el cliente se porte bien: la
--   matriz `MachineType × ProductCategory` se declara como función inmutable y
--   los tres disparadores la aplican con `SQLSTATE 45000`, el mismo código que
--   el arnés reconoce como rechazo constitucional.
--
-- POR QUÉ ES IDEMPOTENTE
--   El ejecutor registra la huella de cada migración y no la reaplica, pero
--   estas mismas declaraciones viven en `database/supabase_init.sql` (contrato
--   de correspondencia). Por eso los tipos se crean en bloques que toleran
--   `duplicate_object`, las tablas usan `IF NOT EXISTS`, los índices también y
--   los disparadores se recrean tras su `DROP ... IF EXISTS`.
--
-- QUÉ NO HACE
--   No siembra catálogo ni planogramas (`C3`): eso es `supabase_seeds.sql` y
--   `SeedRunner`. No expone endpoints, servicios ni vistas (Fases 3 a 6 del
--   módulo) y no toca `AdminMachineService::updateMachine` (`QA 4`, T-PLN-09).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Tipos nativos #26 y #27 (RF-01.1, QA 1)
-- ----------------------------------------------------------------------------
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

DO $do$
BEGIN
    CREATE TYPE vending_product_unit_type AS ENUM (
        'UNIT',
        'GRAMS',
        'SLEEVE'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- El valor nuevo no se usa en esta transacción a propósito: PostgreSQL no deja
-- referenciar un miembro añadido hasta que la transacción que lo añadió
-- confirme (SQLSTATE 55P04). Las filas de auditoría las escriben los servicios
-- de aplicación, cuando el libro ya ha confirmado la migración.
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'VENDING_PRODUCT';
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'MACHINE_PLANOGRAM';


-- ----------------------------------------------------------------------------
-- 2. Tabla 42 · Catálogo maestro de productos y consumibles (RF-01.1, C1, C2, QA 1)
-- ----------------------------------------------------------------------------
-- `sku` es único y su inmutabilidad se defiende en el disparador de la
-- Sección 4, no solo en el servicio (C1). `max_shelf_life_days` es obligatorio
-- cuando hay cadena de frío —y todo `PERISHABLE_FOOD` la exige— y opcional en
-- consumibles no perecederos (QA 1). `sale_price_eur` admite 0,00 € únicamente
-- en ingredientes y consumibles (C2): un refresco a 0,00 € sería un error de
-- explotación, no una promoción.
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

-- Blindaje Constitucional Art. III.1: la baja es lógica (`is_active = FALSE`).
DROP TRIGGER IF EXISTS trg_no_delete_vending_products ON vending_products;
CREATE TRIGGER trg_no_delete_vending_products
    BEFORE DELETE ON vending_products
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

-- D-2: `updated_at` avanza en cada `UPDATE` real, igual que en las maestras.
DROP TRIGGER IF EXISTS trg_touch_vending_products ON vending_products;
CREATE TRIGGER trg_touch_vending_products
    BEFORE UPDATE ON vending_products
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- ----------------------------------------------------------------------------
-- 3. Tabla 43 · Matriz de planograma por máquina (RF-02.1, C1, C2, Art. III.1)
-- ----------------------------------------------------------------------------
-- El par `(machine_id, slot_code)` es la identidad del carril: guardar es
-- `UPSERT` y retirar es `is_active = FALSE`, nunca un `DELETE` (C1). Las cotas
-- se declaran en el motor además de en dominio (RNF-02): capacidad entre 1 y
-- 10 000, umbral estrictamente menor que la capacidad y existencia estimada
-- dentro de la capacidad.
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

DROP TRIGGER IF EXISTS trg_no_delete_machine_planogram_slots ON machine_planogram_slots;
CREATE TRIGGER trg_no_delete_machine_planogram_slots
    BEFORE DELETE ON machine_planogram_slots
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

DROP TRIGGER IF EXISTS trg_touch_machine_planogram_slots ON machine_planogram_slots;
CREATE TRIGGER trg_touch_machine_planogram_slots
    BEFORE UPDATE ON machine_planogram_slots
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- ----------------------------------------------------------------------------
-- 4. Cerrojo sanitario bidireccional (Art. II, D3, RF-03.1 y RF-03.2)
-- ----------------------------------------------------------------------------
-- La matriz se declara UNA vez, como función inmutable, y los tres disparadores
-- la consultan: asignar un carril, editar el producto y cambiar la tipología de
-- la máquina. Una regla con tres copias sería una regla con tres verdades.
CREATE OR REPLACE FUNCTION is_planogram_product_compatible_with_machine(
    p_machine_type TEXT,
    p_product_category TEXT,
    p_requires_cold_chain BOOLEAN
) RETURNS BOOLEAN
LANGUAGE plpgsql
IMMUTABLE
AS $$
BEGIN
    -- Art. II: producto de cadena de frío exige máquina PERISHABLE_FOOD o COMBO.
    IF p_requires_cold_chain IS TRUE AND p_machine_type NOT IN ('PERISHABLE_FOOD', 'COMBO') THEN
        RETURN FALSE;
    END IF;

    -- Matriz `MachineType × ProductCategory` (D3).
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

-- 4.1 · Alta o edición de un carril activo (RF-03.1)
CREATE OR REPLACE FUNCTION enforce_planogram_slot_compatibility()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_machine_type TEXT;
    v_category TEXT;
    v_requires_cold_chain BOOLEAN;
BEGIN
    -- Desactivar un carril es siempre legítimo: la baja lógica no planifica
    -- producto, lo retira (C1).
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

-- 4.2 · Edición inversa del producto (RF-03.2, cerrojo inverso)
-- Activar `requires_cold_chain` o cambiar la categoría sobre una referencia que
-- ya vive en carriles activos incompatibles se rechaza aquí. También se
-- defiende la inmutabilidad del SKU (C1) en el motor y no solo en el servicio.
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

-- 4.3 · Edición inversa de la máquina (RF-03.2, QA 4)
-- Cambiar la tipología de una máquina con carriles activos incompatibles se
-- rechaza con el detalle del primer carril en conflicto. `AdminMachineService`
-- devuelve el listado completo; el motor garantiza que el cambio no pase.
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
