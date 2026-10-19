-- ============================================================================
-- Módulo 16 · Reparación de la coherencia oportunista (Art. III.2, Art. V.2)
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
-- La restricción que declaró la migración `005_opportunistic_incident.sql`
-- comparaba `opportunistic_mode = 'NEW'` sin garantizar antes que la modalidad no
-- fuera nula. En SQL, `NULL = 'NEW'` no es falso: es desconocido, y un `CHECK`
-- sólo rechaza lo que evalúa a FALSE. El resultado era que dos combinaciones
-- incoherentes se colaban:
--
--   · modalidad nula con submotivo    (NULL, 'SITE_VERBAL_NOTICE')
--   · modalidad sin submotivo         ('NEW', NULL)
--
-- La especificación funcional (RF-04.1) exige el motivo de origen in situ como
-- dato obligatorio del alta, y su ausencia dejaría el ticket sin la etiqueta de
-- origen que la Sede y Coordinación leen. Se corrige con una migración posterior
-- —nunca reescribiendo la ya aplicada, que es lo que el libro de huellas impide—
-- y no se reescriben datos: si existiera alguna fila incoherente (no debería,
-- porque las columnas nacen con la 005), el alta de la restricción falla en voz
-- alta y la decisión queda en manos del operador (Art. III.1).
-- ============================================================================

ALTER TABLE incidents DROP CONSTRAINT IF EXISTS chk_incidents_opportunistic_coherence;

DO $do$
BEGIN
    ALTER TABLE incidents
        ADD CONSTRAINT chk_incidents_opportunistic_coherence CHECK (
            -- Sin modalidad no hay submotivo, y con modalidad el submotivo es obligatorio:
            -- los dos `IS NOT NULL` convierten el desconocido de SQL en un falso explícito.
            (opportunistic_mode IS NULL AND opportunistic_origin_reason IS NULL)
            OR (
                opportunistic_mode IS NOT NULL
                AND opportunistic_origin_reason IS NOT NULL
                AND (
                    (
                        opportunistic_mode = 'NEW'
                        AND opportunistic_origin_reason IN ('SITE_VERBAL_NOTICE', 'TECHNICIAN_DETECTION', 'WARRANTY_REOPEN_IN_SITU')
                    )
                    OR (
                        opportunistic_mode = 'ASSUMED'
                        AND opportunistic_origin_reason IN ('EXISTING_TICKET_CLAIM', 'WARRANTY_REOPEN_IN_SITU')
                    )
                )
            )
        );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;
