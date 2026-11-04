-- ============================================================================
-- Módulo 28 · Guías Rápidas de Diagnóstico por Modelo
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   El conocimiento de diagnóstico de una máquina vive hoy en la cabeza del
--   técnico y en hojas sueltas: qué significa cada código de error del display,
--   en qué orden comprobar los componentes y qué repuesto suele resolver el
--   fallo. Sin un catálogo por modelo, dos técnicos diagnostican la misma
--   avería de formas distintas y el repuesto recomendado depende de quién
--   llegue primero. Esta migración introduce la ficha de diagnóstico por modelo
--   y categoría de avería que el técnico consulta in situ y que Coordinación
--   gobierna.
--
-- QUÉ DECLARA
--   1. El valor `MACHINE_TROUBLESHOOTING_GUIDE` en `audit_entity_type`, para
--      que el ciclo de vida de la guía tenga un rastro propio y no se confunda
--      con la avería que se diagnostica con ella (Art. III.3).
--   2. La Tabla 39 `machine_troubleshooting_guides` (14 columnas): modelo
--      canónico y su clave normalizada, categoría de avería, título, códigos de
--      error y pasos en `JSONB`, repuestos recomendados y causa raíz sugerida,
--      más la baja lógica (`is_active`) y la autoría de la última edición.
--   3. La clave única `uq_troubleshooting_guides_model_category`: una sola guía
--      por modelo y categoría, activa o inactiva (C1).
--   4. Los tres índices de lectura: por modelo y estado, por categoría y estado,
--      y por causa raíz sugerida.
--   5. La función `enforce_troubleshooting_guides_immutability()` y los tres
--      disparadores constitucionales `trg_no_delete_machine_troubleshooting_guides`
--      (`prevent_hard_delete()`, Art. III.1),
--      `trg_touch_machine_troubleshooting_guides` (`touch_updated_at()`) y
--      `trg_immutable_machine_troubleshooting_guides`.
--
-- POR QUÉ EL MODELO Y LA CATEGORÍA SON INMUTABLES (C1)
--   El par `(machine_model_key, breakdown_type)` es la identidad de la guía: el
--   conmutador del técnico la busca por ese par y el indicador de cobertura del
--   parque cuenta modelos cubiertos por él. Un cambio retroactivo de modelo o de
--   categoría no sería una edición: sería otra guía ocupando el hueco de la
--   anterior, con su historial de auditoría apuntando a un hecho que nunca
--   ocurrió. La aplicación también lo valida (`422 IMMUTABLE_GUIDE_IDENTITY`),
--   pero la regla queda escrita aquí para que no dependa de que un camino futuro
--   se salte el servicio.
--
-- POR QUÉ LOS REPUESTOS SON UN ARRAY DE IDENTIFICADORES
--   La guía recomienda entre 0 y 10 repuestos del catálogo, en el orden en que
--   Coordinación quiere que se prueben (`QA 3`). El `CHECK`
--   `chk_troubleshooting_guides_spare_parts_bounds` impide que el array deje de
--   ser un array o desborde esa cota; la integridad de cada identificador contra
--   `spare_parts` la valida el servicio (C2), porque un catálogo puede dar de
--   baja una pieza después y la guía debe seguir siendo legible.
--
-- POR QUÉ LA MIGRACIÓN ES IDEMPOTENTE
--   El ejecutor registra la huella de cada migración y no la reaplica, pero la
--   misma declaración vive en `database/supabase_init.sql` para que una base
--   creada desde cero y una base migrada converjan. El valor del enumerado se
--   añade con `IF NOT EXISTS`, la tabla y los índices usan `IF NOT EXISTS`, la
--   función se recrea y los disparadores se recrean tras su `DROP`.
--
-- QUÉ NO HACE
--   No siembra el catálogo de guías (T-GUIDE-02), no implementa el CRUD ni la
--   API (Fases 3 a 5) y no guarda contenido en Markdown ni HTML: los pasos son
--   bloques estructurados que el frontend interpola como texto.
-- ============================================================================

ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'MACHINE_TROUBLESHOOTING_GUIDE';

-- ----------------------------------------------------------------------------
-- 1. Tabla 39 · machine_troubleshooting_guides — ficha por modelo y categoría
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS machine_troubleshooting_guides (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY,
    machine_model               VARCHAR(100) NOT NULL,
    machine_model_key           VARCHAR(100) NOT NULL,
    breakdown_type              incident_category NOT NULL,
    title                       VARCHAR(150) NOT NULL,
    error_codes_json            JSONB NOT NULL DEFAULT '[]'::jsonb,
    steps_json                  JSONB NOT NULL,
    recommended_spare_part_ids  JSONB NOT NULL DEFAULT '[]'::jsonb,
    suggested_root_cause_id     BIGINT,
    is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
    created_by                  BIGINT,
    updated_by                  BIGINT,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_machine_troubleshooting_guides PRIMARY KEY (id),
    CONSTRAINT uq_troubleshooting_guides_model_category UNIQUE (machine_model_key, breakdown_type),
    CONSTRAINT fk_troubleshooting_guides_root_cause FOREIGN KEY (suggested_root_cause_id)
        REFERENCES root_causes (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_troubleshooting_guides_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_troubleshooting_guides_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,

    -- El modelo se guarda dos veces: la etiqueta visible y la clave de
    -- emparejamiento, que es minúscula y sin espacios sobrantes (C5).
    CONSTRAINT chk_troubleshooting_guides_model_not_empty CHECK (
        char_length(btrim(machine_model)) BETWEEN 2 AND 100
        AND char_length(btrim(machine_model_key)) BETWEEN 2 AND 100
        AND machine_model_key = lower(btrim(machine_model_key))
    ),
    CONSTRAINT chk_troubleshooting_guides_title_len CHECK (
        char_length(btrim(title)) BETWEEN 5 AND 150
    ),
    CONSTRAINT chk_troubleshooting_guides_error_codes_bounds CHECK (
        jsonb_typeof(error_codes_json) = 'array'
        AND jsonb_array_length(error_codes_json) BETWEEN 0 AND 20
    ),
    CONSTRAINT chk_troubleshooting_guides_steps_bounds CHECK (
        jsonb_typeof(steps_json) = 'array'
        AND jsonb_array_length(steps_json) BETWEEN 2 AND 10
    ),
    CONSTRAINT chk_troubleshooting_guides_spare_parts_bounds CHECK (
        jsonb_typeof(recommended_spare_part_ids) = 'array'
        AND jsonb_array_length(recommended_spare_part_ids) BETWEEN 0 AND 10
    )
);

-- ----------------------------------------------------------------------------
-- 2. Índices de lectura del conmutador del técnico y del catálogo
-- ----------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_troubleshooting_guides_model_active
    ON machine_troubleshooting_guides (machine_model_key, is_active, breakdown_type);
CREATE INDEX IF NOT EXISTS idx_troubleshooting_guides_category_active
    ON machine_troubleshooting_guides (breakdown_type, is_active);
CREATE INDEX IF NOT EXISTS idx_troubleshooting_guides_root_cause
    ON machine_troubleshooting_guides (suggested_root_cause_id)
    WHERE suggested_root_cause_id IS NOT NULL;

-- ----------------------------------------------------------------------------
-- 3. Disparadores constitucionales (Art. III.1 y Art. III.3, C1)
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION enforce_troubleshooting_guides_immutability()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF OLD.machine_model_key IS DISTINCT FROM NEW.machine_model_key
       OR OLD.machine_model IS DISTINCT FROM NEW.machine_model
       OR OLD.breakdown_type IS DISTINCT FROM NEW.breakdown_type THEN
        RAISE EXCEPTION 'INMUTABILIDAD_VIOLADA (C1): El modelo de máquina y la categoría de avería de una guía de diagnóstico son inmutables tras su creación.'
            USING ERRCODE = '45000';
    END IF;
    RETURN NEW;
END;
$fn$;

DROP TRIGGER IF EXISTS trg_no_delete_machine_troubleshooting_guides ON machine_troubleshooting_guides;
CREATE TRIGGER trg_no_delete_machine_troubleshooting_guides
    BEFORE DELETE ON machine_troubleshooting_guides
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

DROP TRIGGER IF EXISTS trg_touch_machine_troubleshooting_guides ON machine_troubleshooting_guides;
CREATE TRIGGER trg_touch_machine_troubleshooting_guides
    BEFORE UPDATE ON machine_troubleshooting_guides
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

DROP TRIGGER IF EXISTS trg_immutable_machine_troubleshooting_guides ON machine_troubleshooting_guides;
CREATE TRIGGER trg_immutable_machine_troubleshooting_guides
    BEFORE UPDATE ON machine_troubleshooting_guides
    FOR EACH ROW EXECUTE FUNCTION enforce_troubleshooting_guides_immutability();
