-- ============================================================================
-- Módulo 16 · Avería oportunista in situ (alta y asunción en ruta)
-- ----------------------------------------------------------------------------
-- 1. Tipos nativos de la modalidad oportunista y de su submotivo de origen.
-- 2. Ampliación del catálogo cerrado de plantillas de la bandeja de salida.
-- 3. Metadatos oportunistas en `incidents`, con su restricción de coherencia,
--    el vínculo autorreferencial de la avería crónica y el índice parcial con el
--    que se miden los desplazamientos evitados.
--
-- NOTA SOBRE EL EJECUTOR (PostgresMigrationRunner):
-- Aplica cada migración dentro de una transacción. PostgreSQL 12+ admite
-- `ALTER TYPE ... ADD VALUE` en ese contexto, pero el valor nuevo NO puede
-- usarse hasta que la transacción confirme. Por eso este fichero se limita a
-- declarar los valores: ninguna fila con las nuevas plantillas se escribe aquí.
--
-- NOTA SOBRE LA IDEMPOTENCIA:
-- El ejecutor registra la huella de cada migración y no la reaplica, pero la
-- misma declaración vive también en `database/supabase_init.sql` (contrato de
-- correspondencia, §2). Por eso todo se declara con `IF NOT EXISTS` o tolerando
-- `duplicate_object`: una base creada desde el DDL base y una base migrada
-- tienen que quedar con la misma forma.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Tipos nativos del módulo 16
-- ----------------------------------------------------------------------------

-- 1.1. Modalidad de la intervención oportunista (RF-04.2, RF-05.2, RF-06.4).
DO $do$
BEGIN
    CREATE TYPE opportunistic_mode AS ENUM (
        'NEW',      -- «Oportunista In Situ · Nueva»: expediente abierto en sede
        'ASSUMED'   -- «Oportunista In Situ · Asumida»: expediente preexistente absorbido o reabierto
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 1.2. Submotivo del hallazgo, obligatorio cuando hay modalidad (RF-04.1.2, RF-05.2).
DO $do$
BEGIN
    CREATE TYPE opportunistic_origin_reason AS ENUM (
        'SITE_VERBAL_NOTICE',       -- Aviso verbal en sede (pasillo / recepción)
        'TECHNICIAN_DETECTION',     -- Detección propia del técnico in situ
        'EXISTING_TICKET_CLAIM',    -- Asunción de un ticket abierto preexistente
        'WARRANTY_REOPEN_IN_SITU'   -- Reapertura en garantía (< 48 h) o sucesor de avería crónica
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- ----------------------------------------------------------------------------
-- 2. Plantillas de la bandeja de salida (módulo 14) que estrena el módulo 16
-- ----------------------------------------------------------------------------
-- OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT: el técnico pospone una parada
-- crítica o de cadena de frío en otra sede (RF-03.2.2, Art. II).
-- INCIDENT_REOPENED_IN_SITU_SITE: la sede recibe el aviso de la reapertura
-- practicada in situ por el técnico (RF-06.3.2).
ALTER TYPE notification_template ADD VALUE IF NOT EXISTS 'OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT';
ALTER TYPE notification_template ADD VALUE IF NOT EXISTS 'INCIDENT_REOPENED_IN_SITU_SITE';

-- ----------------------------------------------------------------------------
-- 3. Metadatos oportunistas en `incidents`
-- ----------------------------------------------------------------------------
-- Las cuatro columnas son opcionales a propósito: todo expediente anterior al
-- módulo 16 —y todo ticket abierto por los canales ordinarios— no tiene marca
-- oportunista y no debe inventarla al migrar (Art. III.2).
ALTER TABLE incidents
    ADD COLUMN IF NOT EXISTS opportunistic_mode              opportunistic_mode,
    ADD COLUMN IF NOT EXISTS opportunistic_origin_reason     opportunistic_origin_reason,
    ADD COLUMN IF NOT EXISTS opportunistic_informant_name    VARCHAR(100),
    ADD COLUMN IF NOT EXISTS chronic_predecessor_incident_id BIGINT;

-- 3.1. Coherencia modalidad ↔ submotivo (RF-04.1.2, RF-05.4.1).
--      Sin modalidad no hay submotivo; cada modalidad admite sólo los motivos
--      que la especificación le reconoce. La reapertura en garantía es el único
--      motivo compartido: reabre tanto un expediente nuevo como uno asumido.
DO $do$
BEGIN
    ALTER TABLE incidents
        ADD CONSTRAINT chk_incidents_opportunistic_coherence CHECK (
            (opportunistic_mode IS NULL AND opportunistic_origin_reason IS NULL)
            OR (
                opportunistic_mode = 'NEW'
                AND opportunistic_origin_reason IN ('SITE_VERBAL_NOTICE', 'TECHNICIAN_DETECTION', 'WARRANTY_REOPEN_IN_SITU')
            )
            OR (
                opportunistic_mode = 'ASSUMED'
                AND opportunistic_origin_reason IN ('EXISTING_TICKET_CLAIM', 'WARRANTY_REOPEN_IN_SITU')
            )
        );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 3.2. Vínculo con el expediente crónico predecesor (RF-05.4.2).
--      `RESTRICT` impide que un borrado se lleve la cadena de reincidencias, y
--      `CASCADE` en la actualización mantiene el vínculo si cambia la clave.
DO $do$
BEGIN
    ALTER TABLE incidents
        ADD CONSTRAINT fk_incidents_chronic_predecessor
        FOREIGN KEY (chronic_predecessor_incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE;
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 3.3. Índice parcial de las intervenciones oportunistas vigentes (RF-06.4).
--      Parcial por dos motivos: el indicador de desplazamientos evitados sólo
--      cuenta lo no borrado lógicamente, y la inmensa mayoría de las filas de
--      `incidents` no son oportunistas, así que indexarlas engordaría el índice
--      sin servir a ninguna consulta del módulo.
CREATE INDEX IF NOT EXISTS idx_incidents_opportunistic_mode
    ON incidents (opportunistic_mode, created_at)
    WHERE opportunistic_mode IS NOT NULL AND deleted_at IS NULL;
