-- ============================================================================
-- Módulo 21 · Integridad de ausencias: una sola ausencia viva por instante
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
-- La comprobación `hasOverlappingAbsence()` y el INSERT estaban separados por
-- una ventana de carrera: dos altas simultáneas podían pasar la comprobación e
-- insertar rangos solapados. La aplicación serializa por técnico con un cerrojo
-- `FOR UPDATE`; esta constraint es la última autoridad y protege también las
-- escrituras que no pasen por el servicio.
--
-- QUÉ DECLARA
--   1. La extensión `btree_gist`, necesaria para combinar la igualdad de
--      `technician_id` con el rango temporal en un índice GiST.
--   2. Una constraint de exclusión sobre `technician_absences` con semántica
--      semiabierta `[starts_at, ends_at)`: dos ausencias que solo comparten el
--      instante límite no se solapan.
--   3. El predicado parcial `cancelled_at IS NULL`: cancelar una ausencia libera
--      su rango sin borrar la fila (Art. III.1).
--
-- NOTA SOBRE LA IDEMPOTENCIA
-- El bloque `DO` comprueba el catálogo antes de añadir la constraint. La misma
-- declaración vive en `database/supabase_init.sql` para que una base creada
-- desde cero y una base migrada converjan.
-- ============================================================================

CREATE EXTENSION IF NOT EXISTS btree_gist;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM pg_constraint
         WHERE conname = 'ex_technician_absences_no_overlap'
           AND conrelid = 'technician_absences'::regclass
    ) THEN
        ALTER TABLE technician_absences
            ADD CONSTRAINT ex_technician_absences_no_overlap
            EXCLUDE USING gist (
                technician_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            ) WHERE (cancelled_at IS NULL);
    END IF;
END
$$;
