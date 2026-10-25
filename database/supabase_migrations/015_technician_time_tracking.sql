-- ============================================================================
-- Módulo 22 · Registro de Jornada y Cómputo de Horas Efectivas
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
-- El ciclo de turno del Módulo 21 conocía el estado presente (ON_SHIFT,
-- MEAL_BREAK, OFF_SHIFT), pero no conservaba el acumulado de pausas, las marcas
-- originales del primer fichaje ni la secuencia inmutable de eventos que exige
-- el Art. III.3. Sin esa memoria, un cierre forzado con hora efectiva no podía
-- demostrar qué había fichado el técnico antes de la intervención de
-- Coordinación, y una rectificación podía perder el rastro del valor previo.
--
-- QUÉ DECLARA
--   1. Columnas aditivas en la Tabla 30 `technician_shifts` para el acumulado
--      de pausa, las marcas originales, las insignias de cierre forzado y la
--      rectificación auditada.
--   2. La Tabla 31 `technician_shift_logs`, bitácora append-only de los seis
--      eventos canónicos de jornada.
--   3. Restricciones de coherencia temporal y de justificación mínima de 20
--      caracteres reales (Art. V.1).
--   4. Disparadores de inmutabilidad: `DELETE` y `UPDATE` rechazados sobre la
--      bitácora (Art. III.1 y Art. III.3).
--
-- NOTA SOBRE LA IDEMPOTENCIA
-- El ejecutor registra la huella de cada migración y no la reaplica, pero la
-- misma declaración vive también en `database/supabase_init.sql` para que una
-- base creada desde cero y una base migrada converjan. Todo se declara con
-- `IF NOT EXISTS`, las constraints se comprueban en el catálogo y los
-- disparadores se recrean con `DROP TRIGGER IF EXISTS` / `CREATE TRIGGER`.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Tabla 30 · columnas de cómputo y trazabilidad del turno
-- ----------------------------------------------------------------------------
ALTER TABLE technician_shifts
    ADD COLUMN IF NOT EXISTS meal_break_seconds INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS original_started_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS original_closed_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS original_meal_break_seconds INT,
    ADD COLUMN IF NOT EXISTS is_force_closed BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS is_adjusted BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS adjusted_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS adjusted_by BIGINT,
    ADD COLUMN IF NOT EXISTS adjustment_reason TEXT;

-- Inicialización de las filas preexistentes del Módulo 21: lo que el técnico
-- fichó antes de que existiera la bitácora es, por definición, su marca
-- original. No se inventa una salida para un turno que sigue abierto.
UPDATE technician_shifts
   SET original_started_at = started_at
 WHERE original_started_at IS NULL;

UPDATE technician_shifts
   SET original_closed_at = closed_at,
       original_meal_break_seconds = COALESCE(original_meal_break_seconds, meal_break_seconds)
 WHERE closed_at IS NOT NULL
   AND original_closed_at IS NULL;

ALTER TABLE technician_shifts
    ALTER COLUMN original_started_at SET NOT NULL,
    ALTER COLUMN original_started_at SET DEFAULT CURRENT_TIMESTAMP;

DO $do$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM pg_constraint
         WHERE conname = 'fk_tech_shifts_adjuster'
           AND conrelid = 'technician_shifts'::regclass
    ) THEN
        ALTER TABLE technician_shifts
            ADD CONSTRAINT fk_tech_shifts_adjuster FOREIGN KEY (adjusted_by)
                REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE;
    END IF;
END $do$;

DO $do$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM pg_constraint
         WHERE conname = 'chk_tech_shifts_meal_seconds'
           AND conrelid = 'technician_shifts'::regclass
    ) THEN
        ALTER TABLE technician_shifts
            ADD CONSTRAINT chk_tech_shifts_meal_seconds CHECK (
                meal_break_seconds >= 0
                AND (
                    closed_at IS NULL
                    OR meal_break_seconds <= EXTRACT(EPOCH FROM (closed_at - started_at))::INT
                )
            );
    END IF;
END $do$;

DO $do$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM pg_constraint
         WHERE conname = 'chk_tech_shifts_adjustment_consistency'
           AND conrelid = 'technician_shifts'::regclass
    ) THEN
        ALTER TABLE technician_shifts
            ADD CONSTRAINT chk_tech_shifts_adjustment_consistency CHECK (
                (is_adjusted = FALSE AND adjusted_at IS NULL AND adjusted_by IS NULL AND adjustment_reason IS NULL)
                OR (
                    is_adjusted = TRUE
                    AND adjusted_at IS NOT NULL
                    AND adjusted_by IS NOT NULL
                    AND char_length(btrim(adjustment_reason)) >= 20
                )
            );
    END IF;
END $do$;

-- ----------------------------------------------------------------------------
-- 2. Guardia de inmutabilidad para superficies append-only
-- ----------------------------------------------------------------------------
-- El contrato del Módulo 22 nombra esta función `prevent_service_sheet_update`;
-- su forma es la de cualquier superficie de solo inserción: rechaza el UPDATE
-- con el código constitucional que reconoce el arnés.
CREATE OR REPLACE FUNCTION prevent_service_sheet_update()
RETURNS TRIGGER AS $fn$
BEGIN
    RAISE EXCEPTION 'ART_III_1_VIOLATION: la bitácora de jornada no admite UPDATE (Art. III.3).'
        USING ERRCODE = '45000';
END;
$fn$ LANGUAGE plpgsql;

-- ----------------------------------------------------------------------------
-- 3. Tabla 31 · technician_shift_logs — bitácora inmutable de jornada
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS technician_shift_logs (
    id              BIGINT GENERATED ALWAYS AS IDENTITY,
    shift_id        BIGINT NOT NULL,
    technician_id   BIGINT NOT NULL,
    event_type      VARCHAR(30) NOT NULL,
    actor_user_id   BIGINT NOT NULL,
    effective_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    previous_state  JSONB,
    new_state       JSONB NOT NULL,
    reason          TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_technician_shift_logs PRIMARY KEY (id),
    CONSTRAINT fk_shift_logs_shift FOREIGN KEY (shift_id)
        REFERENCES technician_shifts (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_shift_logs_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_shift_logs_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_shift_logs_event_type CHECK (
        event_type IN (
            'START_SHIFT',
            'PAUSE_MEAL',
            'RESUME_SHIFT',
            'CLOSE_SHIFT',
            'FORCE_CLOSE_SHIFT',
            'ADJUST_SHIFT'
        )
    ),
    CONSTRAINT chk_shift_logs_adjust_reason CHECK (
        event_type <> 'ADJUST_SHIFT'
        OR (reason IS NOT NULL AND char_length(btrim(reason)) >= 20)
    )
);

CREATE INDEX IF NOT EXISTS idx_tech_shift_logs_shift
    ON technician_shift_logs (shift_id, created_at);

CREATE INDEX IF NOT EXISTS idx_tech_shift_logs_tech
    ON technician_shift_logs (technician_id, created_at);

DROP TRIGGER IF EXISTS trg_no_delete_technician_shift_logs ON technician_shift_logs;
CREATE TRIGGER trg_no_delete_technician_shift_logs
    BEFORE DELETE ON technician_shift_logs
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

DROP TRIGGER IF EXISTS trg_no_update_technician_shift_logs ON technician_shift_logs;
CREATE TRIGGER trg_no_update_technician_shift_logs
    BEFORE UPDATE ON technician_shift_logs
    FOR EACH ROW EXECUTE FUNCTION prevent_service_sheet_update();
