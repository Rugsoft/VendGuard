-- =============================================================================
-- VendGuard · Migraciones incrementales del motor nuevo · 001 · BASELINE
-- Fichero: database/supabase_migrations/001_initial_baseline.sql
-- Módulo: 12-hybrid-supabase-migration · T-HYBRID-23
-- Ejecutor: src/Infrastructure/Database/PostgresMigrationRunner.php
-- =============================================================================
--
-- QUÉ ES ESTE FICHERO
--   El arranque del libro de migraciones del motor nuevo, no una copia del DDL.
--   Las 20 tablas, sus restricciones y sus disparadores los materializa
--   `database/supabase_init.sql`, que el arranque del contenedor aplica **antes**
--   que este conjunto. Repetir aquí ese DDL crearía una segunda definición de las
--   mismas reglas, que es exactamente lo que el proyecto prohíbe (una sola
--   definición de cada regla, dos traducciones de su sintaxis).
--
-- QUÉ DECLARA ENTONCES
--   La guarda del baseline: comprueba que las 20 tablas del contrato de
--   correspondencia existen de verdad antes de que el libro registre la primera
--   migración como aplicada. Sin esta guarda, un arranque con el DDL base omitido
--   marcaría el esquema como «convergido» sobre una base vacía: el libro diría que
--   sí y el servicio arrancaría contra un esquema que no existe — el modo de fallo
--   que EARS-02.4/EARS-02.5 prohíben expresamente.
--
-- IDEMPOTENCIA
--   Sólo consulta catálogos: reejecutarla no cambia nada ni duplica nada. Las
--   migraciones posteriores (002…) son las que traen cambios incrementales y las
--   que hacen converger una base ya creada sin reescribir este fichero nunca.
--
-- FUERA DE ALCANCE
--   Datos y semillas: el catálogo maestro de un arranque en limpio vive en
--   `database/supabase_seeds.sql` (EARS-02.1) y no se mezcla con el esquema.

DO $$
DECLARE
    missing_tables text;
BEGIN
    SELECT string_agg(expected.name, ', ' ORDER BY expected.name)
      INTO missing_tables
      FROM (VALUES
        ('locations'),
        ('machines'),
        ('users'),
        ('incidents'),
        ('incident_history'),
        ('incident_comments'),
        ('incident_replaced_parts'),
        ('refund_requests'),
        ('unclaimed_cash_findings'),
        ('spare_parts'),
        ('spare_part_compatibilities'),
        ('spare_part_requests'),
        ('preventive_settings'),
        ('preventive_orders'),
        ('preventive_order_items'),
        ('sanitary_certificates'),
        ('route_settings'),
        ('sanitary_sweep_passes'),
        ('sanitary_sweep_contentions'),
        ('audit_log')
      ) AS expected(name)
     WHERE NOT EXISTS (
        SELECT 1
          FROM information_schema.tables
         WHERE table_schema = current_schema()
           AND table_name = expected.name
     );

    IF missing_tables IS NOT NULL THEN
        RAISE EXCEPTION
            'Baseline del motor nuevo incompleto: falta(n) la(s) tabla(s) [%]. Aplique database/supabase_init.sql antes de las migraciones (EARS-02.4).',
            missing_tables;
    END IF;
END
$$;
