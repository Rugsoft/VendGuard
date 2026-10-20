-- ============================================================================
-- Módulo 17 · Liberación lógica de códigos postales (Resolución QA 13)
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
-- La Resolución QA 7 exige que desactivar una zona libere sus códigos postales
-- en la misma transacción, de modo que otra zona pueda reasignarlos. La `007`
-- los declaró con `PRIMARY KEY (postal_code)`, lo que solo deja dos salidas para
-- liberar: borrar la fila o no liberarla nunca.
--
-- Borrarla es imposible sin romper la Constitución: `prevent_hard_delete()`
-- rechaza cualquier DELETE sobre las 25 tablas con `SQLSTATE '45000'`
-- (Art. III.1), y esa guarda no se toca. Por eso la liberación es **lógica**:
--
--   1. La tabla estrena una clave primaria sustituta (`id`), porque un CP
--      liberado debe poder convivir con su nueva asignación viva.
--   2. `released_at` marca el instante de liberación; la fila no se borra y el
--      historial de qué zona lo tuvo sigue siendo consultable.
--   3. La unicidad territorial —la regla de negocio de RF-01.2— se declara donde
--      corresponde: un índice único **parcial** que solo mira las filas vivas
--      (`WHERE released_at IS NULL`). Un CP sigue perteneciendo a una sola zona
--      viva a la vez; lo que deja de ser cierto es que el CP sea inmutable.
--
-- Reactivar la zona no recupera sus CPs: la liberación es un hecho y el
-- `released_at` la data. Volver a asignarlos es una operación explícita y
-- auditada.
--
-- NOTA SOBRE LA IDEMPOTENCIA:
-- El DDL base (`database/supabase_init.sql`) ya declara la forma final, y una
-- base creada desde él aplica esta migración sobre una tabla que ya tiene `id`,
-- `released_at` y el índice. Todo se declara tolerando ese escenario: la clave
-- primaria se re-crea dentro de un bloque que absorbe `duplicate_object` y las
-- columnas usan `IF NOT EXISTS`. El ejecutor registra la huella y no reaplica.
-- ============================================================================

-- 1. La clave primaria deja de ser el CP: pasa a ser un sustituto estable.
ALTER TABLE operational_zone_postal_codes
    DROP CONSTRAINT IF EXISTS pk_operational_zone_postal_codes;

ALTER TABLE operational_zone_postal_codes
    ADD COLUMN IF NOT EXISTS id BIGINT GENERATED ALWAYS AS IDENTITY;

-- 2. Instante de liberación. `NULL` significa «asignación viva».
ALTER TABLE operational_zone_postal_codes
    ADD COLUMN IF NOT EXISTS released_at TIMESTAMPTZ;

DO $do$
BEGIN
    ALTER TABLE operational_zone_postal_codes
        ADD CONSTRAINT pk_operational_zone_postal_codes PRIMARY KEY (id);
EXCEPTION
    WHEN duplicate_object THEN NULL;
    WHEN invalid_table_definition THEN NULL;
END $do$;

-- 3. Unicidad territorial sobre las filas vivas. Es el índice que usa la
--    herencia (`findInheritedZoneByPostalCode`) y el que rechaza un CP duplicado
--    entre dos zonas con `23505`.
CREATE UNIQUE INDEX IF NOT EXISTS uq_zone_postal_codes_live
    ON operational_zone_postal_codes (postal_code)
    WHERE released_at IS NULL;
