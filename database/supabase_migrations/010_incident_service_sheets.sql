-- ============================================================================
-- Módulo 19 · Firma manuscrita digitalizada y albarán de servicio
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
-- El técnico cerraba la avería sin ninguna constancia de que la sede hubiera
-- recibido el servicio. Cuando la sede reclamaba, la empresa no podía demostrar
-- qué se había sustituido ni quién lo había dado por bueno. Este módulo guarda
-- la conformidad firmada en el móvil del técnico —o la atestación de que no
-- había nadie en la sede— y congela el albarán que se entrega en papel.
--
-- QUÉ DECLARA
--   1. `incident_service_sheets` — una fila por resolución, con el trazo en
--      `BYTEA`, el firmante, el ancla de vigencia (`resolution_at`) y el
--      documento congelado (`signed_document`) que imprime el albarán.
--   2. Su índice de historial, el disparador canónico de borrado y el de bloqueo
--      de `UPDATE`: la hoja firmada no se reescribe ni se borra.
--
-- QUÉ NO DECLARA, A PROPÓSITO
--   · Ningún valor del enumerado de auditoría: el evento `RESOLVE_INCIDENT` ya
--     existe y la lectura del albarán reutiliza la entidad `TICKET` con la
--     acción `VIEW_SERVICE_SHEET` (RD-09). Por eso esta migración no necesita
--     `ALTER TYPE` y puede revertirse desplegando código.
--   · Ninguna columna de coste, nota interna ni SLA: el papel se entrega a la
--     sede y el Art. V.4 prohíbe que transporte vida interna de la empresa
--     (Art. V.4, RD-04).
--   · Ninguna ruta de `UPDATE` ni de `DELETE`: la hoja es prueba documental y
--     una corrección exige una resolución nueva (Art. III.1, RD-01). Los dos
--     disparadores lo impiden en el motor, no solo en el código.
--
-- NOTA SOBRE LA IDEMPOTENCIA:
-- El ejecutor registra la huella de cada migración y no la reaplica, pero la
-- misma declaración vive también en `database/supabase_init.sql` (contrato de
-- correspondencia, §2). Todo se declara con `IF NOT EXISTS` y el disparador con
-- `DROP ... IF EXISTS` para que una base creada desde el DDL base y una base
-- migrada queden con la misma forma.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Hoja de servicio firmada (RF-03.1, RF-03.5, RF-03.6)
-- ----------------------------------------------------------------------------
-- `resolution_at` no es un adorno: es la copia del `resolved_at` vigente en el
-- momento de la inserción y lo que permite saber si esta hoja documenta la
-- resolución actual o una visita anterior tras una reapertura (RD-11).
-- `signed_document` se compone en la misma sentencia que inserta la fila, desde
-- las filas que la transacción del cierre acaba de escribir (RD-10).
CREATE TABLE IF NOT EXISTS incident_service_sheets (
    id              BIGINT GENERATED ALWAYS AS IDENTITY,
    incident_id     BIGINT NOT NULL,
    technician_id   BIGINT NOT NULL,
    signer_name     VARCHAR(120),
    signer_document VARCHAR(20),
    signature_png   BYTEA,
    absence_reason  TEXT,
    resolution_at   TIMESTAMPTZ NOT NULL,
    signed_document JSONB NOT NULL,
    recorded_at     TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_incident_service_sheets PRIMARY KEY (id),
    CONSTRAINT fk_service_sheet_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_service_sheet_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_service_sheet_branch CHECK (
        (
            signature_png IS NOT NULL
            AND signer_name IS NOT NULL
            AND char_length(btrim(signer_name)) >= 3
            AND signer_document IS NOT NULL
            AND char_length(btrim(signer_document)) >= 5
            AND absence_reason IS NULL
        )
        OR
        (
            signature_png IS NULL
            AND signer_name IS NULL
            AND signer_document IS NULL
            AND absence_reason IS NOT NULL
            AND char_length(btrim(absence_reason)) >= 10
        )
    ),
    CONSTRAINT chk_service_sheet_png_size CHECK (
        signature_png IS NULL
        OR (
            octet_length(signature_png) BETWEEN 64 AND 307200
            AND substring(signature_png FROM 1 FOR 8) = decode('89504e470d0a1a0a', 'hex')
        )
    ),
    CONSTRAINT chk_service_sheet_document CHECK (
        jsonb_typeof(signed_document) = 'object'
        AND signed_document ? 'version'
    )
);

CREATE INDEX IF NOT EXISTS idx_service_sheet_incident
    ON incident_service_sheets (incident_id, recorded_at DESC, id DESC);

-- ----------------------------------------------------------------------------
-- 2. Inmutabilidad (Art. III.1)
-- ----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_no_delete_incident_service_sheets ON incident_service_sheets;
CREATE TRIGGER trg_no_delete_incident_service_sheets
    BEFORE DELETE ON incident_service_sheets
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

-- El `BEFORE DELETE` impide borrar la hoja; este otro impide reescribirla. Sin él,
-- la inmutabilidad sería una convención del código y cualquier ruta con permiso de
-- escritura podría cambiar el firmante de un papel ya entregado en la sede.
CREATE OR REPLACE FUNCTION prevent_service_sheet_rewrite()
RETURNS TRIGGER AS $fn$
BEGIN
    RAISE EXCEPTION 'ART_III_1_VIOLATION: la hoja de servicio firmada no admite UPDATE (Art. III.1).'
        USING ERRCODE = '45000';
END;
$fn$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_no_update_incident_service_sheets ON incident_service_sheets;
CREATE TRIGGER trg_no_update_incident_service_sheets
    BEFORE UPDATE ON incident_service_sheets
    FOR EACH ROW EXECUTE FUNCTION prevent_service_sheet_rewrite();
