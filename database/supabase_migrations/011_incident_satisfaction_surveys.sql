-- ============================================================================
-- Módulo 20 · Encuesta de satisfacción post-resolución (CSAT de sede)
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
-- La sede no tenía forma de decir si el servicio recibido había estado a la
-- altura. La única señal era la ausencia de reclamaciones, que mide el silencio
-- y no la satisfacción. Este módulo guarda una valoración breve —una nota de 1 a
-- 5 estrellas y un comentario opcional— sobre el servicio ya resuelto, para que
-- coordinación detecte fricciones antes de la renovación del contrato.
--
-- QUÉ DECLARA
--   1. `incident_satisfaction_surveys` — una fila por **episodio de resolución**.
--      El ancla es `(incident_id, resolution_at)`, no la avería: una reapertura
--      pone `resolved_at = NULL` y la siguiente resolución abre un episodio
--      nuevo (RD-01). `resolution_at` es copia exacta de `incidents.resolved_at`,
--      nunca `now()`: atribuye la valoración al servicio que realmente se prestó
--      (RF-02.6).
--   2. El nombre del técnico viaja congelado en `technician_name_snapshot`: un
--      renombrado posterior no reescribe la historia de la métrica (R8, Art. III.3).
--   3. Sus tres índices de consulta, el disparador canónico de borrado y el de
--      bloqueo de `UPDATE`: la respuesta es inmutable (Art. III.1, RD-06).
--
-- QUÉ NO DECLARA, A PROPÓSITO
--   · Ningún valor nuevo del enumerado de auditoría: el evento
--     `SUBMIT_SATISFACTION_SURVEY` reutiliza la entidad `TICKET` y `action` es
--     `VARCHAR(64)`. Por eso esta migración no necesita `ALTER TYPE`.
--   · Ninguna columna de contacto del respondiente: la encuesta se firma con la
--     sede, no con una persona (R2, Art. V.4).
--   · Ninguna ruta de `UPDATE` ni de `DELETE` de aplicación: la valoración no se
--     rectifica (RD-05). Los dos disparadores lo impiden en el motor, no solo en
--     el código.
--
-- NOTA SOBRE LA IDEMPOTENCIA:
-- El ejecutor registra la huella de cada migración y no la reaplica, pero la
-- misma declaración vive también en `database/supabase_init.sql` (contrato de
-- correspondencia, §2). Todo se declara con `IF NOT EXISTS` y los disparadores
-- con `DROP ... IF EXISTS` para que una base creada desde el DDL base y una base
-- migrada queden con la misma forma.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Encuesta de satisfacción (RF-01.1, RF-02.1, RF-04.1)
-- ----------------------------------------------------------------------------
-- `technician_id` es nulo cuando la avería se resolvió sin asignación: la
-- valoración sigue siendo válida y la métrica la agrupa como «Sin técnico».
-- `technician_name_snapshot` solo tiene sentido si hay técnico, pero no se
-- fuerza con un CHECK: un técnico sin nombre registrado no debe impedir que la
-- sede valore el servicio.
CREATE TABLE IF NOT EXISTS incident_satisfaction_surveys (
    id                       BIGINT GENERATED ALWAYS AS IDENTITY,
    incident_id              BIGINT NOT NULL,
    location_id              BIGINT NOT NULL,
    technician_id            BIGINT,
    technician_name_snapshot VARCHAR(100),
    resolution_at            TIMESTAMPTZ NOT NULL,
    rating                   SMALLINT NOT NULL,
    comment                  VARCHAR(500),
    created_at               TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_incident_satisfaction_surveys PRIMARY KEY (id),
    CONSTRAINT fk_survey_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_survey_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_survey_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT uq_survey_episode UNIQUE (incident_id, resolution_at),
    CONSTRAINT chk_survey_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT chk_survey_comment CHECK (
        comment IS NULL OR (char_length(btrim(comment)) BETWEEN 1 AND 500)
    )
);

-- El listado del portal ordena por resolución; la métrica filtra por sede y por
-- técnico dentro del periodo elegido. Los tres índices cubren esas tres rutas.
CREATE INDEX IF NOT EXISTS idx_surveys_resolution_at
    ON incident_satisfaction_surveys (resolution_at);

CREATE INDEX IF NOT EXISTS idx_surveys_location
    ON incident_satisfaction_surveys (location_id, resolution_at);

CREATE INDEX IF NOT EXISTS idx_surveys_technician
    ON incident_satisfaction_surveys (technician_id, resolution_at);

-- ----------------------------------------------------------------------------
-- 2. Inmutabilidad (Art. III.1)
-- ----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_no_delete_incident_satisfaction_surveys ON incident_satisfaction_surveys;
CREATE TRIGGER trg_no_delete_incident_satisfaction_surveys
    BEFORE DELETE ON incident_satisfaction_surveys
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

-- El `BEFORE DELETE` canónico impide borrar la respuesta; este otro impide
-- reescribirla. Sin él, cambiar una nota de 1 a 5 sería un `UPDATE` y la
-- inmutabilidad sería una convención del código (RD-06).
CREATE OR REPLACE FUNCTION prevent_satisfaction_survey_rewrite()
RETURNS TRIGGER AS $fn$
BEGIN
    RAISE EXCEPTION 'ART_III_1_VIOLATION: la encuesta de satisfacción no admite UPDATE (Art. III.1).'
        USING ERRCODE = '45000';
END;
$fn$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_no_update_incident_satisfaction_surveys ON incident_satisfaction_surveys;
CREATE TRIGGER trg_no_update_incident_satisfaction_surveys
    BEFORE UPDATE ON incident_satisfaction_surveys
    FOR EACH ROW EXECUTE FUNCTION prevent_satisfaction_survey_rewrite();
