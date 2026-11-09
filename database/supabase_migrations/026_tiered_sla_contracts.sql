-- ============================================================================
-- Módulo 30 · Perfiles de SLA Diferenciados por Tipo de Sede
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   Un único umbral de 24 h para toda máquina sin riesgo alimentario no
--   representa los contratos que la operadora comercializa: un hospital o un
--   centro logístico 24x7 recibían el mismo plazo que una oficina estándar, y
--   ni la ruta del técnico ni el recomendador de asignación reflejaban esa
--   urgencia contractual. A la vez, recalcular el pasado con el contrato nuevo
--   falsearía los informes de cumplimiento ya cerrados (Art. III.3).
--
-- QUÉ DECLARA
--   1. El tipo nativo `sla_tier` (25.º del esquema) con los tres perfiles
--      oficiales: STANDARD, PRIORITY_CORPORATE y CRITICAL_INFRASTRUCTURE_24_7.
--   2. `locations.sla_tier` (NOT NULL DEFAULT 'STANDARD') y su índice parcial
--      para el catálogo de sedes activas.
--   3. El sellado contractual inmutable de `incidents`: `sla_tier_snapshot`
--      (Art. III.3, D2, C1) y `sla_target_minutes`, con la restricción de los
--      cinco objetivos de la matriz RF-01.
--   4. El backfill determinista de C5: los expedientes cerrados conservan el
--      umbral con el que se evaluaron (240 min en riesgo de cadena de frío,
--      1440 min en el resto) y los abiertos reciben la matriz vigente bajo el
--      perfil de su sede, con `sla_target_at` desplazado por las pausas ya
--      acumuladas.
--   5. La función y el disparador que impiden modificar `sla_tier_snapshot`
--      tras el alta (SQLSTATE 23514, IMMUTABLE_SLA_TIER_SNAPSHOT).
--
-- POR QUÉ ES IDEMPOTENTE
--   El tipo se crea solo si no existe, las columnas usan IF NOT EXISTS, la
--   restricción se recrea tras su DROP y el disparador se recrea tras su DROP.
--   La misma declaración vive en `database/supabase_init.sql` para que una base
--   creada desde cero y una base migrada converjan.
--
-- QUÉ NO HACE
--   No siembra perfiles diferenciados (T-SLA-02), no crea el dominio ni los
--   servicios (Fases 2 a 4) y no altera `sla_target_at` de los expedientes
--   cerrados más allá de poblarlo cuando faltaba.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Tipo enumerado nativo del perfil contractual
-- ----------------------------------------------------------------------------
DO $do$
BEGIN
    CREATE TYPE sla_tier AS ENUM (
        'STANDARD',
        'PRIORITY_CORPORATE',
        'CRITICAL_INFRASTRUCTURE_24_7'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- ----------------------------------------------------------------------------
-- 2. Perfil vigente de la sede (RF-01, C5)
-- ----------------------------------------------------------------------------
ALTER TABLE locations
    ADD COLUMN IF NOT EXISTS sla_tier sla_tier NOT NULL DEFAULT 'STANDARD';

CREATE INDEX IF NOT EXISTS idx_locations_sla_tier
    ON locations (sla_tier)
    WHERE deleted_at IS NULL;

-- ----------------------------------------------------------------------------
-- 3. Sellado contractual del expediente (D2, C1, Art. III.3)
-- ----------------------------------------------------------------------------
ALTER TABLE incidents
    ADD COLUMN IF NOT EXISTS sla_tier_snapshot sla_tier NOT NULL DEFAULT 'STANDARD',
    ADD COLUMN IF NOT EXISTS sla_target_minutes INTEGER NOT NULL DEFAULT 1440;

ALTER TABLE incidents
    DROP CONSTRAINT IF EXISTS chk_incidents_sla_target_minutes;

ALTER TABLE incidents
    ADD CONSTRAINT chk_incidents_sla_target_minutes
    CHECK (sla_target_minutes IN (240, 360, 480, 720, 1440));

CREATE INDEX IF NOT EXISTS idx_incidents_sla_target_lookup
    ON incidents (status, sla_target_at, sla_target_minutes)
    WHERE deleted_at IS NULL;

-- ----------------------------------------------------------------------------
-- 4. Backfill determinista de expedientes preexistentes (C5, EARS 03.2)
-- ----------------------------------------------------------------------------
-- 4.a Cerrados, resueltos o cancelados: sellan el umbral con el que fueron
--     evaluados en su día (240 min en riesgo alimentario, 1440 min en el
--     resto) para que ningún informe histórico de MTTR o brechas cambie.
UPDATE incidents i
SET
    sla_tier_snapshot = 'STANDARD',
    sla_target_minutes = CASE
        WHEN m.machine_type = 'PERISHABLE_FOOD' THEN 240
        WHEN m.machine_type = 'COMBO'
             AND (i.category = 'TEMPERATURE_COLD' OR i.urgency = 'CRITICAL') THEN 240
        ELSE 1440
    END,
    sla_target_at = COALESCE(
        i.sla_target_at,
        i.created_at
            + make_interval(mins => CASE
                WHEN m.machine_type = 'PERISHABLE_FOOD' THEN 240
                WHEN m.machine_type = 'COMBO'
                     AND (i.category = 'TEMPERATURE_COLD' OR i.urgency = 'CRITICAL') THEN 240
                ELSE 1440
              END)
            + make_interval(secs => COALESCE(i.total_pending_info_seconds, 0))
    )
FROM machines m
WHERE i.machine_id = m.id
  AND i.status IN ('RESOLVED', 'CLOSED', 'CANCELLED');

-- 4.b Abiertos o activos: sellan la matriz RF-01 bajo el perfil vigente de su
--     sede ('STANDARD' por defecto tras esta migración) y conservan el
--     descuento de las pausas ya acumuladas (C2).
UPDATE incidents i
SET
    sla_tier_snapshot = COALESCE(l.sla_tier, 'STANDARD'),
    sla_target_minutes = CASE
        WHEN m.machine_type = 'PERISHABLE_FOOD' THEN 240
        WHEN m.machine_type = 'COMBO'
             AND (i.category = 'TEMPERATURE_COLD' OR i.urgency = 'CRITICAL') THEN 240
        WHEN COALESCE(l.sla_tier, 'STANDARD') = 'CRITICAL_INFRASTRUCTURE_24_7' THEN
            CASE WHEN i.urgency IN ('CRITICAL', 'HIGH') THEN 240 ELSE 480 END
        WHEN COALESCE(l.sla_tier, 'STANDARD') = 'PRIORITY_CORPORATE' THEN
            CASE
                WHEN i.urgency = 'CRITICAL' THEN 360
                WHEN i.urgency = 'HIGH' THEN 480
                ELSE 720
            END
        ELSE
            CASE
                WHEN i.urgency = 'CRITICAL' THEN 480
                WHEN i.urgency = 'HIGH' THEN 720
                ELSE 1440
            END
    END,
    sla_target_at = i.created_at
        + make_interval(mins => CASE
            WHEN m.machine_type = 'PERISHABLE_FOOD' THEN 240
            WHEN m.machine_type = 'COMBO'
                 AND (i.category = 'TEMPERATURE_COLD' OR i.urgency = 'CRITICAL') THEN 240
            WHEN COALESCE(l.sla_tier, 'STANDARD') = 'CRITICAL_INFRASTRUCTURE_24_7' THEN
                CASE WHEN i.urgency IN ('CRITICAL', 'HIGH') THEN 240 ELSE 480 END
            WHEN COALESCE(l.sla_tier, 'STANDARD') = 'PRIORITY_CORPORATE' THEN
                CASE
                    WHEN i.urgency = 'CRITICAL' THEN 360
                    WHEN i.urgency = 'HIGH' THEN 480
                    ELSE 720
                END
            ELSE
                CASE
                    WHEN i.urgency = 'CRITICAL' THEN 480
                    WHEN i.urgency = 'HIGH' THEN 720
                    ELSE 1440
                END
          END)
        + make_interval(secs => COALESCE(i.total_pending_info_seconds, 0))
FROM machines m, locations l
WHERE i.machine_id = m.id
  AND i.location_id = l.id
  AND i.status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED');

-- ----------------------------------------------------------------------------
-- 5. Inmutabilidad del perfil sellado (Art. III.3, D2, C1)
-- ----------------------------------------------------------------------------
-- El objetivo (`sla_target_minutes`) y su vencimiento sí pueden recalcularse
-- hacia un valor más estricto en el triaje o por absorción QR (C4, QA 1); lo
-- que nunca cambia es el perfil bajo el que nació el expediente.
CREATE OR REPLACE FUNCTION trg_fn_incidents_protect_sla_tier_snapshot()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.sla_tier_snapshot IS DISTINCT FROM NEW.sla_tier_snapshot THEN
        RAISE EXCEPTION 'IMMUTABLE_SLA_TIER_SNAPSHOT: incidents.sla_tier_snapshot cannot be modified after incident creation (Art. III.3).'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_incidents_protect_sla_tier_snapshot ON incidents;
CREATE TRIGGER trg_incidents_protect_sla_tier_snapshot
    BEFORE UPDATE ON incidents
    FOR EACH ROW
    EXECUTE FUNCTION trg_fn_incidents_protect_sla_tier_snapshot();
