-- ============================================================================
-- Módulo 26 · Historial Completo de Reasignaciones y Cadena de Custodia
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   El expediente solo conservaba al técnico actual, la marca de la primera
--   asignación y el texto del último cambio. Con eso, Coordinación no puede
--   responder quién tuvo la avería, en qué orden, cuánto tiempo la retuvo cada
--   operario ni por qué salió de su cartera: la secuencia de tenencias
--   intermedias se perdía en las notas. Esta migración introduce el registro
--   estructurado, inmutable y cronológico de cada tramo de custodia técnica.
--
-- QUÉ DECLARA
--   1. Los tipos `incident_assignment_source` (canal de entrada al tramo) e
--      `incident_unassignment_reason_type` (causa tipificada de salida).
--   2. La Tabla 37 `incident_assignments`, con los snapshots congelados de los
--      tres actores (técnico, quien asigna y quien cierra), la coherencia
--      todo-o-nada entre tramo abierto y tramo cerrado y el suelo temporal
--      `unassigned_at >= assigned_at` (EARS 01.2, QA 4).
--   3. El índice único parcial `uq_incident_assignments_active_custody`, que es
--      la garantía en el motor —y no solo en el servicio— de que una avería
--      nunca tiene dos custodios activos simultáneos (Art. V.3, EARS 01.1).
--   4. Los índices de lectura de la línea de tiempo y de la cartera del técnico.
--   5. Los disparadores constitucionales: `trg_no_delete_incident_assignments`
--      (`prevent_hard_delete()`, Art. III.1) y `trg_immutable_incident_assignments`,
--      que rechaza cualquier `UPDATE` de un tramo ya cerrado y cualquier cambio
--      de los datos de apertura o de los snapshots (Art. III.3, EARS 01.3).
--   6. El backfill idempotente de los expedientes anteriores al módulo, con los
--      valores de respaldo deterministas de QA 5: código `SIN-CODIGO` cuando el
--      técnico no tiene código de operario, autor `Coordinación / Sistema` y
--      explicación `Registro histórico previo` cuando el expediente antiguo no
--      dejó diagnóstico ni motivo de cancelación.
--
-- POR QUÉ LOS SNAPSHOTS VIVEN EN LA TABLA Y NO SE LEEN DEL DIRECTORIO
--   Un renombrado, una baja o un cambio de código de operario reescribirían el
--   pasado si el tramo guardase solo la clave foránea. El tramo congela lo que
--   estaba vigente en el instante del evento (D2, Art. III.3): la fila conserva
--   el nombre y el código tal como figuraban entonces, y la clave foránea queda
--   únicamente para integridad referencial y para las consultas por técnico.
--
-- POR QUÉ EL CIERRE ES UN SELLO DE UN SOLO USO
--   Un tramo nace abierto (sin fecha ni causa de salida) y muere cerrado. La
--   función disparadora permite exactamente esa transición y ninguna otra: una
--   vez sellado `unassigned_at`, la fila completa queda bloqueada. Es la forma
--   de que el histórico de responsabilidades no dependa de que todos los caminos
--   futuros se acuerden de no tocarlo.
--
-- POR QUÉ LA MIGRACIÓN ES IDEMPOTENTE
--   El ejecutor registra la huella de cada migración y no la reaplica, pero la
--   misma declaración vive en `database/supabase_init.sql` para que una base
--   creada desde cero y una base migrada converjan. Todo se declara con
--   `IF NOT EXISTS`, los tipos se crean tolerando el objeto duplicado, las
--   funciones con `CREATE OR REPLACE` y los disparadores se recrean. El backfill
--   sólo inserta en incidencias que aún no tienen ningún tramo.
--
-- QUÉ NO HACE
--   No escribe la cadena desde los flujos operativos: eso ocurre en la misma
--   transacción PDO que asigna, reasigna, releva, resuelve o cancela (Módulo 26,
--   Fase 3). Aquí solo se declara el suelo y se reconstruye el pasado.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Canal de entrada al tramo de custodia (D2, EARS 01.4)
-- ----------------------------------------------------------------------------
DO $do$
BEGIN
    CREATE TYPE incident_assignment_source AS ENUM (
        'COORDINATOR_ASSIGN',
        'COORDINATOR_REASSIGN',
        'ABSENCE_COVERAGE_REASSIGN',
        'OPPORTUNISTIC_NEW',
        'OPPORTUNISTIC_ASSUMED',
        'OPPORTUNISTIC_TAKEOVER',
        'OPPORTUNISTIC_WARRANTY_REOPEN',
        'SHIFT_HANDOVER',
        'SANITARY_FAILSAFE_GUARD'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- ----------------------------------------------------------------------------
-- 2. Causa tipificada de salida del tramo (D2, R2, QA 2)
--    `RESOLVED`, `CHRONIC_REPLACEMENT_CLOSED`, `WARRANTY_REOPENED` y `CANCELLED`
--    no son traspasos: no engrosan el contador de reasignaciones.
-- ----------------------------------------------------------------------------
DO $do$
BEGIN
    CREATE TYPE incident_unassignment_reason_type AS ENUM (
        'REASSIGNED_BY_COORDINATOR',
        'ABSENCE_COVERAGE_REASSIGN',
        'TAKEN_OVER_IN_SITU',
        'SHIFT_HANDOVER',
        'SANITARY_FAILSAFE_GUARD',
        'SANITARY_FAILSAFE_UNASSIGNED',
        'RESOLVED',
        'CHRONIC_REPLACEMENT_CLOSED',
        'WARRANTY_REOPENED',
        'CANCELLED'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- ----------------------------------------------------------------------------
-- 3. Tabla 37 · incident_assignments — tramos de custodia técnica (RF-01)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS incident_assignments (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY,
    incident_id                 BIGINT NOT NULL,
    technician_id               BIGINT NOT NULL,
    technician_name_snapshot    VARCHAR(150) NOT NULL,
    technician_code_snapshot    VARCHAR(32) NOT NULL DEFAULT 'SIN-CODIGO',
    assigned_by                 BIGINT,
    assigned_by_name_snapshot   VARCHAR(150) NOT NULL DEFAULT 'Coordinación / Sistema',
    assignment_source           incident_assignment_source NOT NULL,
    assigned_at                 TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unassigned_by               BIGINT,
    unassigned_by_name_snapshot VARCHAR(150),
    unassignment_reason_type    incident_unassignment_reason_type,
    reassignment_reason         TEXT,
    unassigned_at               TIMESTAMPTZ,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_incident_assignments PRIMARY KEY (id),
    CONSTRAINT fk_incident_assignments_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_incident_assignments_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_incident_assignments_assigned_by FOREIGN KEY (assigned_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_incident_assignments_unassigned_by FOREIGN KEY (unassigned_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    -- Ningún snapshot puede quedar vacío: un tramo sin nombre legible no es
    -- auditable y, si se permitiera, el expediente mostraría un hueco mudo (R5).
    CONSTRAINT chk_incident_assignments_snapshots_not_empty CHECK (
        char_length(btrim(technician_name_snapshot)) BETWEEN 1 AND 150
        AND char_length(btrim(technician_code_snapshot)) BETWEEN 1 AND 32
        AND char_length(btrim(assigned_by_name_snapshot)) BETWEEN 1 AND 150
        AND (
            unassigned_by_name_snapshot IS NULL
            OR char_length(btrim(unassigned_by_name_snapshot)) BETWEEN 1 AND 150
        )
    ),
    -- Coherencia entre tramo abierto y tramo cerrado (EARS 01.2, R5, QA 4):
    -- o están todos los datos de salida, o no está ninguno; a medias no hay
    -- estado representable.
    CONSTRAINT chk_incident_assignments_closure_coherence CHECK (
        (
            unassigned_at IS NULL
            AND unassignment_reason_type IS NULL
            AND unassigned_by IS NULL
            AND unassigned_by_name_snapshot IS NULL
            AND reassignment_reason IS NULL
        )
        OR (
            unassigned_at IS NOT NULL
            AND unassignment_reason_type IS NOT NULL
            AND unassigned_by_name_snapshot IS NOT NULL
            AND reassignment_reason IS NOT NULL
            AND char_length(btrim(reassignment_reason)) BETWEEN 1 AND 1000
            AND unassigned_at >= assigned_at
        )
    )
);

-- ----------------------------------------------------------------------------
-- 4. Índices: unicidad del custodio activo y lecturas de la línea de tiempo
-- ----------------------------------------------------------------------------
-- Art. V.3 y EARS 01.1: a lo sumo un único tramo abierto por incidencia.
CREATE UNIQUE INDEX IF NOT EXISTS uq_incident_assignments_active_custody
    ON incident_assignments (incident_id)
    WHERE unassigned_at IS NULL;

-- RNF-01: la línea de tiempo se lee ordenada sin ordenar en memoria.
CREATE INDEX IF NOT EXISTS idx_incident_assignments_incident_timeline
    ON incident_assignments (incident_id, assigned_at ASC, id ASC);

-- Cartera histórica del técnico: quién tuvo qué y en qué orden.
CREATE INDEX IF NOT EXISTS idx_incident_assignments_technician
    ON incident_assignments (technician_id, assigned_at DESC);

-- ----------------------------------------------------------------------------
-- 5. Blindaje constitucional: ni borrado físico ni reescritura del pasado
-- ----------------------------------------------------------------------------
-- Art. III.1: prohibición absoluta de borrado físico.
CREATE OR REPLACE TRIGGER trg_no_delete_incident_assignments
    BEFORE DELETE ON incident_assignments
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

-- Art. III.3 y EARS 01.3: los datos de apertura y los snapshots son inmutables
-- tras el alta, y un tramo cerrado no admite ningún cambio posterior.
CREATE OR REPLACE FUNCTION enforce_incident_assignments_immutability()
RETURNS TRIGGER AS $fn$
BEGIN
    IF OLD.unassigned_at IS NOT NULL THEN
        RAISE EXCEPTION 'ART_III_3_VIOLATION: Un tramo de custodia ya cerrado en incident_assignments es inmutable y no admite modificaciones (EARS 01.3).'
            USING ERRCODE = '45000';
    END IF;

    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.incident_id IS DISTINCT FROM OLD.incident_id
        OR NEW.technician_id IS DISTINCT FROM OLD.technician_id
        OR NEW.technician_name_snapshot IS DISTINCT FROM OLD.technician_name_snapshot
        OR NEW.technician_code_snapshot IS DISTINCT FROM OLD.technician_code_snapshot
        OR NEW.assigned_by IS DISTINCT FROM OLD.assigned_by
        OR NEW.assigned_by_name_snapshot IS DISTINCT FROM OLD.assigned_by_name_snapshot
        OR NEW.assignment_source IS DISTINCT FROM OLD.assignment_source
        OR NEW.assigned_at IS DISTINCT FROM OLD.assigned_at
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'ART_III_3_VIOLATION: Los datos de apertura y snapshots de un tramo en incident_assignments son inmutables tras su creación (EARS 01.3).'
            USING ERRCODE = '45000';
    END IF;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

CREATE OR REPLACE TRIGGER trg_immutable_incident_assignments
    BEFORE UPDATE ON incident_assignments
    FOR EACH ROW EXECUTE FUNCTION enforce_incident_assignments_immutability();

-- ----------------------------------------------------------------------------
-- 6. Backfill idempotente de expedientes anteriores al módulo (D4, QA 5)
--    Sólo toca incidencias que tengan técnico asignado y ningún tramo todavía.
--    El tramo se abre en la primera asignación conocida y, si el expediente ya
--    terminó, se sella con la fecha efectiva de su desenlace y una explicación
--    legible: nunca queda un tramo abierto colgando de una avería cerrada.
-- ----------------------------------------------------------------------------
INSERT INTO incident_assignments (
    incident_id,
    technician_id,
    technician_name_snapshot,
    technician_code_snapshot,
    assigned_by,
    assigned_by_name_snapshot,
    assignment_source,
    assigned_at,
    unassigned_by,
    unassigned_by_name_snapshot,
    unassignment_reason_type,
    reassignment_reason,
    unassigned_at,
    created_at
)
SELECT
    i.id,
    u.id,
    u.name,
    COALESCE(NULLIF(btrim(u.operator_code), ''), 'SIN-CODIGO'),
    NULL,
    'Coordinación / Sistema',
    'COORDINATOR_ASSIGN'::incident_assignment_source,
    COALESCE(i.assigned_at, i.created_at),
    NULL,
    CASE
        WHEN i.status IN ('RESOLVED', 'CLOSED', 'CANCELLED') THEN 'Coordinación / Sistema'
    END,
    CASE
        WHEN i.status = 'CANCELLED' THEN 'CANCELLED'::incident_unassignment_reason_type
        WHEN i.status IN ('RESOLVED', 'CLOSED') THEN 'RESOLVED'::incident_unassignment_reason_type
    END,
    CASE
        WHEN i.status = 'CANCELLED' THEN left(
            COALESCE(NULLIF(btrim(i.cancellation_reason), ''), 'Registro histórico previo'),
            1000
        )
        WHEN i.status IN ('RESOLVED', 'CLOSED') THEN left(
            COALESCE(NULLIF(btrim(i.resolution_diagnosis), ''), 'Registro histórico previo'),
            1000
        )
    END,
    CASE
        WHEN i.status IN ('RESOLVED', 'CLOSED', 'CANCELLED') THEN GREATEST(
            COALESCE(i.assigned_at, i.created_at),
            COALESCE(i.resolved_at, i.cancelled_at, i.closed_at, i.created_at)
        )
    END,
    COALESCE(i.assigned_at, i.created_at)
FROM incidents i
JOIN users u ON u.id = i.assigned_technician_id
WHERE i.assigned_technician_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
        FROM incident_assignments a
       WHERE a.incident_id = i.id
  );
