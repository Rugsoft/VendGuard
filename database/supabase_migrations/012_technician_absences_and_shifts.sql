-- ============================================================================
-- Módulo 21 · Gestión de Ausencias Temporales y Relevo de Fin de Turno
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
-- Coordinación no disponía de un mecanismo para registrar vacaciones, bajas
-- médicas o permisos del personal técnico sin recurrir a la desactivación
-- de la cuenta (is_active = false), lo cual provocaba desalineación en el
-- recomendador territorial (M17) y falta de visibilidad en el despacho.
-- Asimismo, los técnicos en ruta no contaban con un ciclo de jornada explícito
-- (inicio, pausa de comida y cierre) ni un protocolo transaccional atómico
-- para traspasar averías pendientes a su relevo al concluir el turno comercial,
-- salvaguardando las averías perecederas (Art. II).
--
-- QUÉ DECLARA
--   1. `technician_absences` (Tabla 29) — registro administrativo de ausencias
--      temporales con granularidad TIMESTAMPTZ, soporte de cancelación lógica
--      auditada con motivo (Art. III.1) y comprobación de solapamiento.
--   2. `technician_shifts` (Tabla 30) — ciclo de jornada del técnico (ON_SHIFT,
--      MEAL_BREAK, OFF_SHIFT) con soporte canónico de turno partido y múltiples
--      sesiones diarias mediante el índice único parcial
--      `uq_tech_shifts_single_active (technician_id) WHERE closed_at IS NULL` (R3).
--   3. Disparadores constitucionales de inmutabilidad `BEFORE DELETE`
--      (`prevent_hard_delete()`, Art. III.1) en ambas tablas y disparador
--      `touch_updated_at()` en `technician_shifts`.
--
-- NOTA SOBRE LA IDEMPOTENCIA:
-- El ejecutor registra la huella de cada migración y no la reaplica, pero la
-- misma declaración vive también en `database/supabase_init.sql` (contrato de
-- correspondencia, §2). Todo se declara con `IF NOT EXISTS` y los disparadores
-- con `DROP TRIGGER IF EXISTS` / `CREATE OR REPLACE TRIGGER`.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Tabla 29 · technician_absences — ausencias de técnicos (RF-01, R5)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS technician_absences (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY,
    technician_id         BIGINT NOT NULL,
    reason                VARCHAR(30) NOT NULL,
    starts_at             TIMESTAMPTZ NOT NULL,
    ends_at               TIMESTAMPTZ NOT NULL,
    notes                 TEXT,
    created_by            BIGINT NOT NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancelled_at          TIMESTAMPTZ,
    cancelled_by          BIGINT,
    cancellation_reason   TEXT,

    CONSTRAINT pk_technician_absences PRIMARY KEY (id),
    CONSTRAINT fk_tech_absences_user FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_tech_absences_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_tech_absences_canceller FOREIGN KEY (cancelled_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_tech_absences_dates CHECK (ends_at >= starts_at),
    CONSTRAINT chk_tech_absences_reason CHECK (
        reason IN ('VACATION', 'SICK_LEAVE', 'TRAINING', 'PERSONAL_LEAVE', 'COMPENSATORY', 'OTHER')
    ),
    CONSTRAINT chk_tech_absences_cancellation CHECK (
        (cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
        OR (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND char_length(btrim(cancellation_reason)) >= 5)
    )
);

CREATE INDEX IF NOT EXISTS idx_tech_absences_lookup 
    ON technician_absences (technician_id, starts_at, ends_at)
    WHERE cancelled_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_tech_absences_status 
    ON technician_absences (starts_at, ends_at)
    WHERE cancelled_at IS NULL;

DROP TRIGGER IF EXISTS trg_no_delete_technician_absences ON technician_absences;
CREATE TRIGGER trg_no_delete_technician_absences
    BEFORE DELETE ON technician_absences
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();


-- ----------------------------------------------------------------------------
-- 2. Tabla 30 · technician_shifts — ciclo de turno y jornada (RF-03, R3, R10)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS technician_shifts (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY,
    technician_id         BIGINT NOT NULL,
    shift_date            DATE NOT NULL DEFAULT CURRENT_DATE,
    status                VARCHAR(20) NOT NULL DEFAULT 'ON_SHIFT',
    started_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    meal_break_started_at TIMESTAMPTZ,
    meal_break_ended_at   TIMESTAMPTZ,
    closed_at             TIMESTAMPTZ,
    closed_by             BIGINT,
    close_reason          TEXT,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_technician_shifts PRIMARY KEY (id),
    CONSTRAINT fk_tech_shifts_user FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_tech_shifts_closer FOREIGN KEY (closed_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_tech_shifts_status CHECK (
        status IN ('ON_SHIFT', 'MEAL_BREAK', 'OFF_SHIFT')
    ),
    CONSTRAINT chk_tech_shifts_meal_order CHECK (
        meal_break_ended_at IS NULL OR (meal_break_started_at IS NOT NULL AND meal_break_ended_at >= meal_break_started_at)
    ),
    CONSTRAINT chk_tech_shifts_close_order CHECK (
        closed_at IS NULL OR closed_at >= started_at
    )
);

-- Restricción de concurrencia: Máximo un turno abierto simultáneo por técnico (Resolución R3)
-- Soporta turno partido y emergencias porque un técnico puede tener varios turnos cerrados al día.
CREATE UNIQUE INDEX IF NOT EXISTS uq_tech_shifts_single_active 
    ON technician_shifts (technician_id)
    WHERE closed_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_tech_shifts_lookup 
    ON technician_shifts (technician_id, shift_date);

DROP TRIGGER IF EXISTS trg_touch_technician_shifts ON technician_shifts;
CREATE TRIGGER trg_touch_technician_shifts
    BEFORE UPDATE ON technician_shifts
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

DROP TRIGGER IF EXISTS trg_no_delete_technician_shifts ON technician_shifts;
CREATE TRIGGER trg_no_delete_technician_shifts
    BEFORE DELETE ON technician_shifts
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();
