-- ============================================================================
-- Módulo 17 · Zonificación operativa y asignación asistida por territorio
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
-- El parque de sedes necesita una demarcación territorial estable: una zona
-- operativa con sus códigos postales, un cuadrante técnico con titular y
-- suplencias, y una sede que hereda su zona del código postal con la posibilidad
-- de anularla manualmente (RF-01 a RF-03 del módulo 17).
--
-- QUÉ DECLARA
--   1. `operational_zones` — catálogo maestro de zonas (código, nombre, color,
--      descripción y estado), con las restricciones de formato que exige RF-01.1.
--   2. `operational_zone_postal_codes` — mapeo unívoco de códigos postales. La
--      clave primaria está **en el código postal**: un CP pertenece a una sola
--      zona durante todo su ciclo de vida (RF-01.2, Resolución QA 7).
--   3. `locations.zone_id`, `locations.is_manual_override` y
--      `locations.postal_code` — la herencia territorial de la sede (RF-03.1 y
--      RF-03.2). El CP admite NULL para no inventar datos sobre sedes históricas:
--      la obligatoriedad se exige en el alta y la edición (validación de
--      aplicación, Resolución QA 7).
--   4. `technician_zone_assignments` — cuadrante técnico con titularidad única
--      (índice parcial `uq_technician_primary_zone`) y baja **lógica**
--      (`removed_at`), porque el borrado físico está prohibido por el Art. III.1
--      (Resolución QA 10).
--   5. Los valores `OPERATIONAL_ZONE` y `TECHNICIAN_ZONE` del enumerado
--      `audit_entity_type` (Resolución QA 11).
--
-- NOTA SOBRE EL EJECUTOR (PostgresMigrationRunner):
-- Aplica cada migración dentro de una transacción. PostgreSQL 12+ admite
-- `ALTER TYPE ... ADD VALUE` en ese contexto, pero el valor nuevo NO puede
-- usarse hasta que la transacción confirme. Por eso este fichero se limita a
-- declarar los valores: ninguna fila de auditoría de estas dos entidades se
-- escribe aquí. El catálogo inicial lo siembra `database/supabase_seeds.sql`,
-- que corre después de que el libro haya confirmado la migración.
--
-- NOTA SOBRE LA IDEMPOTENCIA:
-- El ejecutor registra la huella de cada migración y no la reaplica, pero la
-- misma declaración vive también en `database/supabase_init.sql` (contrato de
-- correspondencia, §2). Por eso todo se declara con `IF NOT EXISTS` o tolerando
-- `duplicate_object`: una base creada desde el DDL base y una base migrada
-- tienen que quedar con la misma forma.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Catálogo maestro de zonas operativas (RF-01.1)
-- ----------------------------------------------------------------------------
-- `color_hex` se valida contra `#RRGGBB`: es el token que pinta el mapa
-- territorial del coordinador, y un color inválido dejaría la sede sin
-- representación cartográfica. El código y el nombre son únicos porque son la
-- identidad legible de la zona para todo el equipo.
CREATE TABLE IF NOT EXISTS operational_zones (
    id          BIGINT GENERATED ALWAYS AS IDENTITY,
    code        VARCHAR(50) NOT NULL,
    name        VARCHAR(100) NOT NULL,
    color_hex   VARCHAR(7) NOT NULL DEFAULT '#2560ff',
    description TEXT,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_operational_zones PRIMARY KEY (id),
    CONSTRAINT uq_operational_zones_code UNIQUE (code),
    CONSTRAINT uq_operational_zones_name UNIQUE (name),
    CONSTRAINT chk_operational_zones_code_len CHECK (length(btrim(code)) >= 3),
    CONSTRAINT chk_operational_zones_name_len CHECK (length(btrim(name)) >= 3),
    CONSTRAINT chk_operational_zones_color_format CHECK (color_hex ~* '^#[0-9A-Fa-f]{6}$')
);

CREATE OR REPLACE TRIGGER trg_no_delete_operational_zones
    BEFORE DELETE ON operational_zones
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_operational_zones
    BEFORE UPDATE ON operational_zones
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- ----------------------------------------------------------------------------
-- 2. Mapeo unívoco de códigos postales (RF-01.2)
-- ----------------------------------------------------------------------------
-- La unicidad territorial es global y vive en la clave primaria, no en un
-- índice aparte: si el CP fuera único por zona, el mismo barrio podría estar en
-- dos demarcaciones y el recomendador tendría dos titulares para una sola sede.
-- La desactivación de una zona libera sus filas en la misma transacción
-- (Resolución QA 7), de modo que el CP queda disponible para otra zona.
CREATE TABLE IF NOT EXISTS operational_zone_postal_codes (
    postal_code VARCHAR(5) NOT NULL,
    zone_id     BIGINT NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_operational_zone_postal_codes PRIMARY KEY (postal_code),
    CONSTRAINT chk_postal_code_format CHECK (postal_code ~ '^[0-9]{5}$'),
    CONSTRAINT fk_postal_codes_zone FOREIGN KEY (zone_id)
        REFERENCES operational_zones (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_zone_postal_codes_zone_id
    ON operational_zone_postal_codes (zone_id);

CREATE OR REPLACE TRIGGER trg_no_delete_operational_zone_postal_codes
    BEFORE DELETE ON operational_zone_postal_codes
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();


-- ----------------------------------------------------------------------------
-- 3. Herencia territorial en sedes (RF-03.1, RF-03.2, Resolución QA 7)
-- ----------------------------------------------------------------------------
-- `zone_id` se declara sin clave foránea en la columna y la restricción se añade
-- aparte: el DDL base crea `locations` mucho antes que el catálogo de zonas, y
-- una referencia inline obligaría a reordenar el fichero entero. La regla es la
-- misma (`ON DELETE SET NULL`: desactivar o retirar una zona deja la sede en
-- «Sin Zona Asignada» conservando su historial).
ALTER TABLE locations
    ADD COLUMN IF NOT EXISTS zone_id BIGINT,
    ADD COLUMN IF NOT EXISTS is_manual_override BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS postal_code VARCHAR(5);

DO $do$
BEGIN
    ALTER TABLE locations
        ADD CONSTRAINT fk_locations_zone FOREIGN KEY (zone_id)
        REFERENCES operational_zones (id) ON DELETE SET NULL ON UPDATE CASCADE;
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- El CP admite NULL (sedes históricas que la migración nunca inventa) pero, si
-- existe, tiene que ser de cinco dígitos: es la llave de la herencia territorial.
ALTER TABLE locations DROP CONSTRAINT IF EXISTS chk_locations_postal_code_format;
ALTER TABLE locations
    ADD CONSTRAINT chk_locations_postal_code_format
    CHECK (postal_code IS NULL OR postal_code ~ '^[0-9]{5}$');

CREATE INDEX IF NOT EXISTS idx_locations_postal_code ON locations (postal_code);
CREATE INDEX IF NOT EXISTS idx_locations_zone_id ON locations (zone_id);


-- ----------------------------------------------------------------------------
-- 4. Cuadrante técnico: titularidad y suplencias (RF-02.1, Resolución QA 10)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS technician_zone_assignments (
    id            BIGINT GENERATED ALWAYS AS IDENTITY,
    technician_id BIGINT NOT NULL,
    zone_id       BIGINT NOT NULL,
    is_primary    BOOLEAN NOT NULL DEFAULT FALSE,
    assigned_at   TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- La baja es lógica: el histórico del cuadrante no se borra (Art. III.1) y
    -- el reingreso del técnico estrena una vinculación nueva y auditada.
    removed_at    TIMESTAMPTZ,
    CONSTRAINT pk_technician_zone_assignments PRIMARY KEY (id),
    CONSTRAINT fk_technician_zone_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_technician_zone_zone FOREIGN KEY (zone_id)
        REFERENCES operational_zones (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

-- Unicidad solo sobre vinculaciones vivas: permite reingresos con histórico.
CREATE UNIQUE INDEX IF NOT EXISTS uq_technician_zone_live
    ON technician_zone_assignments (technician_id, zone_id)
    WHERE removed_at IS NULL;

-- Un técnico solo puede ser Titular Principal de una zona viva a la vez (RF-02.1).
CREATE UNIQUE INDEX IF NOT EXISTS uq_technician_primary_zone
    ON technician_zone_assignments (technician_id)
    WHERE is_primary = TRUE AND removed_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_tech_zone_assignments_zone_id
    ON technician_zone_assignments (zone_id);

CREATE INDEX IF NOT EXISTS idx_tech_zone_assignments_tech_id
    ON technician_zone_assignments (technician_id);

CREATE OR REPLACE TRIGGER trg_no_delete_technician_zone_assignments
    BEFORE DELETE ON technician_zone_assignments
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();


-- ----------------------------------------------------------------------------
-- 5. Ampliación del enumerado de auditoría (Resolución QA 11)
-- ----------------------------------------------------------------------------
-- `OPERATIONAL_ZONE` cubre el ciclo de vida de la zona y el traslado masivo de
-- códigos postales (evento matriz `ZONE_POSTAL_CODE_TRANSFERRED`, QA 6).
-- `TECHNICIAN_ZONE` cubre el alta, la baja lógica y la degradación de titularidad
-- del cuadrante. El valor nuevo no se usa en esta transacción a propósito.
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'OPERATIONAL_ZONE';
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'TECHNICIAN_ZONE';
