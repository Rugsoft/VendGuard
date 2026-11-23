-- ============================================================================
-- Módulo 37 · Corrección de la guarda de cadencia de la agenda de reposición
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   `chk_mrs_cadence_coherence` se declaró en la migración 033 con la rama
--   `cadence_type = 'WEEKLY' AND day_of_week BETWEEN 1 AND 7`. En PostgreSQL un
--   `CHECK` solo rechaza la fila cuando la expresión evalúa a FALSE: con
--   `day_of_week IS NULL` la comparación devuelve NULL, el disyunto entero
--   también y la restricción se satisface. Una agenda semanal sin día ISO se
--   persistía, que es justo lo que la guarda debía impedir (RF-01.1, D6,
--   Art. III.3).
--
-- QUÉ HACE
--   Reemplaza la restricción por la misma regla con la prueba de nulidad
--   explícita en la rama `WEEKLY`. Las otras dos ramas ya eran NULL-safe
--   (`anchor_date IS NOT NULL` y `day_of_week IS NULL` son predicados que nunca
--   devuelven NULL).
--
-- POR QUÉ UNA MIGRACIÓN NUEVA Y NO UNA RECTIFICACIÓN DE LA 033
--   El ejecutor registra la huella SHA-256 de cada migración aplicada y rechaza
--   su reescritura: una migración aplicada no se corrige editándola, se corrige
--   con una migración posterior. `database/supabase_init.sql` ya declara la
--   forma corregida, de modo que una base nueva y una base migrada convergen al
--   mismo contrato.
--
-- POR QUÉ ES IDEMPOTENTE
--   El `DROP CONSTRAINT IF EXISTS` deja el camino libre y el `ADD CONSTRAINT`
--   vuelve a declarar la regla; reaplicarla no acumula restricciones.
-- ============================================================================

ALTER TABLE machine_restock_schedules
    DROP CONSTRAINT IF EXISTS chk_mrs_cadence_coherence;

ALTER TABLE machine_restock_schedules
    ADD CONSTRAINT chk_mrs_cadence_coherence CHECK (
        (
            (
                cadence_type = 'WEEKLY'
                AND day_of_week IS NOT NULL
                AND day_of_week BETWEEN 1 AND 7
                AND anchor_date IS NULL
            )
            OR (cadence_type = 'BIWEEKLY' AND anchor_date IS NOT NULL AND day_of_week IS NULL)
            OR (cadence_type IN ('DAILY', 'MON_WED_FRI', 'TUE_THU') AND day_of_week IS NULL AND anchor_date IS NULL)
        )
        AND (include_weekends = FALSE OR cadence_type = 'DAILY')
    );
