-- ============================================================================
-- Módulo 25 · Taxonomía de Causas Raíz y Soluciones Estandarizadas (Pareto)
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   El diagnóstico y la acción de una avería se escriben en texto libre, y dos
--   técnicos describen el mismo fallo con palabras distintas. Sin un catálogo
--   común no hay forma de agrupar las paradas del parque, calcular un Pareto
--   80/20 ni sostener una reclamación de garantía ante un fabricante. Este
--   fichero introduce las dos tablas maestras que normalizan esa clasificación
--   y las siete columnas del expediente que congelan la elección del cierre.
--
-- QUÉ DECLARA
--   1. El valor `ROOT_CAUSE_TAXONOMY` en `audit_entity_type`, para que el
--      gobierno del catálogo tenga un rastro propio y no se confunda con la
--      edición de una avería (Art. III.3).
--   2. La Tabla 35 `root_causes`: código único, categoría técnica inmutable,
--      nombre editable, descripción opcional y baja lógica (`is_active`).
--   3. La Tabla 36 `standard_solutions`: código único, causa padre inmutable,
--      nombre editable, marca de predeterminada y baja lógica. El índice único
--      parcial `uq_standard_solutions_default_per_cause` garantiza a lo sumo una
--      solución predeterminada activa por causa, y el CHECK
--      `chk_standard_solutions_default_active` impide que una solución inactiva
--      conserve esa marca (D1, C2, QA 1).
--   4. La ampliación de la Tabla 4 `incidents` con las referencias al catálogo
--      y los cinco snapshots congelados del cierre. El CHECK
--      `chk_incidents_taxonomy_coherence` impone la coherencia todo-o-nada: o
--      los siete campos están vacíos (avería histórica, preventiva o retirada
--      de máquina) o los siete están completos (avería clasificada).
--   5. El índice analítico `idx_incidents_pareto_resolved`, que sirve la
--      consulta del Pareto por fecha de resolución vigente sin recorrer el
--      histórico completo.
--   6. Los disparadores constitucionales de siempre: `trg_no_delete_*`
--      (`prevent_hard_delete()`, Art. III.1) y `trg_touch_*`
--      (`touch_updated_at()`), más los disparadores de inmutabilidad
--      `trg_immutable_*` que rechazan cualquier cambio de `code`, `category` o
--      `root_cause_id` tras el alta (C4, QA 1).
--
-- POR QUÉ EL CÓDIGO Y LA CATEGORÍA SON INMUTABLES EN EL MOTOR
--   El código es la identidad estable que sobrevive a un renombrado: el Pareto
--   agrupa por código y el CSV de garantías cruza ambas secciones por él. La
--   categoría se congela en el cierre, de modo que un cambio retroactivo de
--   categoría falsearía la lectura histórica por tipo de fallo. La aplicación
--   también lo valida (`22000`), pero la regla queda escrita aquí para que no
--   dependa de que un camino futuro se salte el servicio.
--
-- POR QUÉ LA MIGRACIÓN ES IDEMPOTENTE
--   El ejecutor registra la huella de cada migración y no la reaplica, pero la
--   misma declaración vive en `database/supabase_init.sql` para que una base
--   creada desde cero y una base migrada converjan. Todo se declara con
--   `IF NOT EXISTS`, las funciones con `CREATE OR REPLACE`, los disparadores se
--   recrean y las restricciones se añaden dentro de un bloque que tolera el
--   objeto duplicado.
--
-- QUÉ NO HACE
--   No siembra el catálogo: las 16 causas y las 34 soluciones iniciales viven
--   en `database/supabase_seeds.sql` (arranque en limpio) y en `SeedRunner`
--   (banco del arnés), para que el esquema y los datos tengan una sola
--   responsabilidad cada uno.
-- ============================================================================

ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'ROOT_CAUSE_TAXONOMY';

-- ----------------------------------------------------------------------------
-- 1. Tabla 35 · root_causes — catálogo maestro de causas raíz (RF-01)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS root_causes (
    id          BIGINT GENERATED ALWAYS AS IDENTITY,
    code        VARCHAR(32) NOT NULL,
    category    incident_category NOT NULL,
    name        VARCHAR(150) NOT NULL,
    description TEXT,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_root_causes PRIMARY KEY (id),
    CONSTRAINT uq_root_causes_code UNIQUE (code),
    CONSTRAINT chk_root_causes_code_format CHECK (
        btrim(code) ~ '^[A-Z0-9_-]{3,32}$' AND code = btrim(code)
    ),
    CONSTRAINT chk_root_causes_name_len CHECK (
        char_length(btrim(name)) BETWEEN 5 AND 150
    ),
    CONSTRAINT chk_root_causes_description_len CHECK (
        description IS NULL OR char_length(btrim(description)) BETWEEN 1 AND 500
    )
);

CREATE INDEX IF NOT EXISTS idx_root_causes_category_active
    ON root_causes (category, is_active, code);

DROP TRIGGER IF EXISTS trg_no_delete_root_causes ON root_causes;
CREATE TRIGGER trg_no_delete_root_causes
    BEFORE DELETE ON root_causes
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

DROP TRIGGER IF EXISTS trg_touch_root_causes ON root_causes;
CREATE TRIGGER trg_touch_root_causes
    BEFORE UPDATE ON root_causes
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Inmutabilidad de `code` y `category` tras la creación (C4, QA 1).
CREATE OR REPLACE FUNCTION enforce_root_causes_immutability()
RETURNS TRIGGER AS $fn$
BEGIN
    IF NEW.code IS DISTINCT FROM OLD.code THEN
        RAISE EXCEPTION 'ROOT_CAUSE_CODE_IMMUTABLE: El código de una causa raíz no puede modificarse una vez creado (C4).'
            USING ERRCODE = '45000';
    END IF;

    IF NEW.category IS DISTINCT FROM OLD.category THEN
        RAISE EXCEPTION 'ROOT_CAUSE_CATEGORY_IMMUTABLE: La categoría técnica de una causa raíz no puede modificarse una vez creada (QA 1).'
            USING ERRCODE = '45000';
    END IF;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_immutable_root_causes ON root_causes;
CREATE TRIGGER trg_immutable_root_causes
    BEFORE UPDATE ON root_causes
    FOR EACH ROW EXECUTE FUNCTION enforce_root_causes_immutability();

-- ----------------------------------------------------------------------------
-- 2. Tabla 36 · standard_solutions — soluciones estandarizadas vinculadas
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS standard_solutions (
    id              BIGINT GENERATED ALWAYS AS IDENTITY,
    root_cause_id   BIGINT NOT NULL,
    code            VARCHAR(32) NOT NULL,
    name            VARCHAR(150) NOT NULL,
    is_default      BOOLEAN NOT NULL DEFAULT FALSE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_standard_solutions PRIMARY KEY (id),
    CONSTRAINT uq_standard_solutions_code UNIQUE (code),
    CONSTRAINT fk_standard_solutions_root_cause FOREIGN KEY (root_cause_id)
        REFERENCES root_causes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_standard_solutions_code_format CHECK (
        btrim(code) ~ '^[A-Z0-9_-]{3,32}$' AND code = btrim(code)
    ),
    CONSTRAINT chk_standard_solutions_name_len CHECK (
        char_length(btrim(name)) BETWEEN 5 AND 150
    ),
    -- Una solución inactiva jamás puede conservar la marca de predeterminada:
    -- al desactivarla, la aplicación debe relevar antes a su sucesora (C2).
    CONSTRAINT chk_standard_solutions_default_active CHECK (
        is_default = FALSE OR is_active = TRUE
    )
);

-- A lo sumo una solución predeterminada activa por causa raíz (D1, EARS 01.1).
CREATE UNIQUE INDEX IF NOT EXISTS uq_standard_solutions_default_per_cause
    ON standard_solutions (root_cause_id)
    WHERE is_default = TRUE AND is_active = TRUE;

CREATE INDEX IF NOT EXISTS idx_standard_solutions_cause_active
    ON standard_solutions (root_cause_id, is_active, is_default DESC, code ASC);

DROP TRIGGER IF EXISTS trg_no_delete_standard_solutions ON standard_solutions;
CREATE TRIGGER trg_no_delete_standard_solutions
    BEFORE DELETE ON standard_solutions
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

DROP TRIGGER IF EXISTS trg_touch_standard_solutions ON standard_solutions;
CREATE TRIGGER trg_touch_standard_solutions
    BEFORE UPDATE ON standard_solutions
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Inmutabilidad de `code` y de la causa padre tras la creación (C4, QA 1).
CREATE OR REPLACE FUNCTION enforce_standard_solutions_immutability()
RETURNS TRIGGER AS $fn$
BEGIN
    IF NEW.code IS DISTINCT FROM OLD.code THEN
        RAISE EXCEPTION 'STANDARD_SOLUTION_CODE_IMMUTABLE: El código de una solución estandarizada no puede modificarse una vez creado (C4).'
            USING ERRCODE = '45000';
    END IF;

    IF NEW.root_cause_id IS DISTINCT FROM OLD.root_cause_id THEN
        RAISE EXCEPTION 'STANDARD_SOLUTION_CAUSE_IMMUTABLE: La causa raíz padre de una solución estandarizada no puede modificarse una vez creada (QA 1).'
            USING ERRCODE = '45000';
    END IF;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_immutable_standard_solutions ON standard_solutions;
CREATE TRIGGER trg_immutable_standard_solutions
    BEFORE UPDATE ON standard_solutions
    FOR EACH ROW EXECUTE FUNCTION enforce_standard_solutions_immutability();

-- ----------------------------------------------------------------------------
-- 3. Tabla 4 · incidents — referencias y snapshots congelados (RF-02.5)
-- ----------------------------------------------------------------------------
ALTER TABLE incidents
    ADD COLUMN IF NOT EXISTS root_cause_id BIGINT,
    ADD COLUMN IF NOT EXISTS standard_solution_id BIGINT,
    ADD COLUMN IF NOT EXISTS root_cause_code_snapshot VARCHAR(32),
    ADD COLUMN IF NOT EXISTS root_cause_category_snapshot incident_category,
    ADD COLUMN IF NOT EXISTS root_cause_name_snapshot VARCHAR(150),
    ADD COLUMN IF NOT EXISTS standard_solution_code_snapshot VARCHAR(32),
    ADD COLUMN IF NOT EXISTS standard_solution_name_snapshot VARCHAR(150);

DO $do$
BEGIN
    ALTER TABLE incidents
        ADD CONSTRAINT fk_incidents_root_cause FOREIGN KEY (root_cause_id)
            REFERENCES root_causes (id) ON DELETE RESTRICT ON UPDATE CASCADE;
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    ALTER TABLE incidents
        ADD CONSTRAINT fk_incidents_standard_solution FOREIGN KEY (standard_solution_id)
            REFERENCES standard_solutions (id) ON DELETE RESTRICT ON UPDATE CASCADE;
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Coherencia todo-o-nada de los siete campos de taxonomía (EARS 02.5):
-- una avería o no está clasificada (los siete NULL) o lo está por completo.
DO $do$
BEGIN
    ALTER TABLE incidents
        ADD CONSTRAINT chk_incidents_taxonomy_coherence CHECK (
            (
                root_cause_id IS NULL
                AND standard_solution_id IS NULL
                AND root_cause_code_snapshot IS NULL
                AND root_cause_category_snapshot IS NULL
                AND root_cause_name_snapshot IS NULL
                AND standard_solution_code_snapshot IS NULL
                AND standard_solution_name_snapshot IS NULL
            )
            OR (
                root_cause_id IS NOT NULL
                AND standard_solution_id IS NOT NULL
                AND root_cause_code_snapshot IS NOT NULL
                AND root_cause_category_snapshot IS NOT NULL
                AND root_cause_name_snapshot IS NOT NULL
                AND standard_solution_code_snapshot IS NOT NULL
                AND standard_solution_name_snapshot IS NOT NULL
            )
        );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Índice del universo del Pareto: expedientes vivos resueltos o cerrados, por
-- fecha de resolución vigente y causa (QA 2, C3).
CREATE INDEX IF NOT EXISTS idx_incidents_pareto_resolved
    ON incidents (resolved_at, root_cause_id)
    WHERE status IN ('RESOLVED', 'CLOSED') AND deleted_at IS NULL;
