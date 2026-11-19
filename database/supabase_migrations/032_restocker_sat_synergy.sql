-- ============================================================================
-- Módulo 36 · Sinergia Operativa Reponedor ↔ Técnico SAT
--             (Level-1 Restocker Dispatch & In-Situ SAT Escalation)
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   Hasta ahora toda avería nacía con la misma forma —un ticket que solo podía
--   atender un Técnico SAT— y los avisos operativos leves (carril vacío, falta
--   de vasos o de cambio, atasco leve) saturaban la agenda especialista. El
--   Módulo 36 introduce un nivel operativo explícito en el propio expediente y
--   un subtipo de Nivel 1 que permite al reponedor de la zona resolverlo in
--   situ o escalarlo al SAT con una Nota Interna de Taller.
--   Este fichero declara la evolución de esquema que sostiene ese flujo, sin
--   añadir una sola tabla: el expediente sigue siendo único (Art. IV).
--
-- QUÉ DECLARA
--   1. El tipo nativo #43 `incident_dispatch_level` (`'L1_RESTOCKER'`,
--      `'L2_SAT'`) y el tipo nativo #44 `level1_issue_type`
--      (`'STOCKOUT_EMPTY_SLOT'`, `'MISSING_CONSUMABLES'`,
--      `'EMPTY_CHANGE_TUBES'`, `'MINOR_SPIRAL_JAM'`) (D1, D2, QA-AMB-01).
--   2. La ampliación de `comment_author_type` (`'RESTOCKER'`), de
--      `incident_assignment_source` (4 canales nuevos) y de
--      `incident_unassignment_reason_type` (3 causas nuevas) (QA-AMB-02).
--   3. Las columnas `dispatch_level`, `level1_issue_type`,
--      `escalated_from_restocker_id` y `escalated_to_sat_at` en `incidents`,
--      su clave foránea y la restricción `chk_incidents_l1_dispatch_coherence`
--      (Art. II, QA-LIM-03, QA-AMB-01, QA-CONST-01).
--   4. La ampliación de `offline_sync_mutations` para las tres mutaciones
--      móviles de Nivel 1 y escalado (RF-05.1, C2).
--   5. La siembra idempotente de las 4 parejas de taxonomía de Nivel 1
--      `RC-L1-*` / `SS-L1-*` (RF-01.3, QA-AMB-01).
--
-- POR QUÉ EL CERROJO VIVE TAMBIÉN EN EL MOTOR (RNF-02, Art. II)
--   El validador de dominio y el servicio comprueban la categoría antes de
--   persistir, pero una consola SQL o un importador no pasan por ellos. La
--   restricción `chk_incidents_l1_dispatch_coherence` impide que un ticket
--   térmico o eléctrico quede en `L1_RESTOCKER`, que un Nivel 1 declare
--   `PENDING_PARTS` (QA-LIM-03), que la pareja motivo/categoría del alta L1
--   sea la determinista (QA-AMB-01) y que el rastro de escalado quede a medias.
--   La emergencia alimentaria no admite una salvaguarda que dependa del cliente.
--
-- POR QUÉ ES IDEMPOTENTE
--   El ejecutor registra la huella de cada migración y no la reaplica, pero
--   estas mismas declaraciones viven en `database/supabase_init.sql` (contrato
--   de correspondencia). Por eso los tipos se crean en un bloque que tolera
--   `duplicate_object`, las columnas usan `ADD COLUMN IF NOT EXISTS`, las
--   restricciones se añaden solo si faltan, la clave foránea se comprueba en
--   `pg_constraint` y las semillas usan `ON CONFLICT (code) DO NOTHING`.
--
-- POR QUÉ LOS VALORES NUEVOS DE `ENUM` NO SE USAN AQUÍ
--   PostgreSQL no deja referenciar un miembro añadido hasta que la transacción
--   que lo añadió confirme (SQLSTATE 55P04). Las notas internas de escalado y
--   los tramos de custodia los escriben los servicios de aplicación, cuando el
--   libro ya ha confirmado la migración.
--
-- QUÉ NO HACE
--   No crea tablas nuevas (el esquema permanece en 49 tablas, Art. IV), no
--   expone endpoints ni vistas (Fases 2 a 6 del módulo) y no siembra
--   expedientes de ejemplo (eso es `supabase_seeds.sql` y `SeedRunner`).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Tipos nativos #43 y #44 (D1, D2, RF-01.1, QA-AMB-01)
-- ----------------------------------------------------------------------------

-- Nivel operativo de despacho del expediente: la ruta de reposición o el SAT.
DO $do$
BEGIN
    CREATE TYPE incident_dispatch_level AS ENUM (
        'L1_RESTOCKER',
        'L2_SAT'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Subtipo operativo del aviso leve que puede resolver el reponedor in situ.
DO $do$
BEGIN
    CREATE TYPE level1_issue_type AS ENUM (
        'STOCKOUT_EMPTY_SLOT',
        'MISSING_CONSUMABLES',
        'EMPTY_CHANGE_TUBES',
        'MINOR_SPIRAL_JAM'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;


-- ----------------------------------------------------------------------------
-- 2. Ampliación de los tipos de comentario y custodia (QA-AMB-02, Art. III.3)
-- ----------------------------------------------------------------------------

-- La Nota Interna de Taller del escalado se firma como `RESTOCKER`, no como
-- `TECHNICIAN`: el rastro debe decir quién abrió la máquina y lo detectó.
ALTER TYPE comment_author_type ADD VALUE IF NOT EXISTS 'RESTOCKER';

-- Canales de apertura del tramo de custodia (Módulo 26) que introduce la
-- sinergia: escalado manual, relevo de turno, auto-escalado QR y reapertura
-- en garantía promovida a SAT.
ALTER TYPE incident_assignment_source ADD VALUE IF NOT EXISTS 'RESTOCKER_ESCALATION_TO_SAT';
ALTER TYPE incident_assignment_source ADD VALUE IF NOT EXISTS 'RESTOCKER_SHIFT_HANDOVER';
ALTER TYPE incident_assignment_source ADD VALUE IF NOT EXISTS 'QR_AUTO_ESCALATION_TO_SAT';
ALTER TYPE incident_assignment_source ADD VALUE IF NOT EXISTS 'WARRANTY_REOPEN_ESCALATION_TO_SAT';

-- Causas de cierre del tramo cuando el ticket sale de la custodia del
-- reponedor sin resolución suya.
ALTER TYPE incident_unassignment_reason_type ADD VALUE IF NOT EXISTS 'ESCALATED_TO_SAT_BY_RESTOCKER';
ALTER TYPE incident_unassignment_reason_type ADD VALUE IF NOT EXISTS 'QR_AUTO_ESCALATED_TO_SAT';
ALTER TYPE incident_unassignment_reason_type ADD VALUE IF NOT EXISTS 'RESTOCKER_SHIFT_HANDOVER';


-- ----------------------------------------------------------------------------
-- 3. Evolución de Tabla 4 · incidents (D1, D2, Art. II, QA-LIM-03)
-- ----------------------------------------------------------------------------
-- `dispatch_level` nace en `'L2_SAT'`: ningún expediente histórico se reclasifica
-- a la ruta de reposición por el simple hecho de migrar. `level1_issue_type`
-- queda nulo porque solo lo sella la pre-clasificación de Nivel 1.
-- `escalated_from_restocker_id` y `escalated_to_sat_at` son el rastro del
-- escalado in situ y viajan siempre juntos.
ALTER TABLE incidents
    ADD COLUMN IF NOT EXISTS dispatch_level incident_dispatch_level NOT NULL DEFAULT 'L2_SAT',
    ADD COLUMN IF NOT EXISTS level1_issue_type level1_issue_type,
    ADD COLUMN IF NOT EXISTS escalated_from_restocker_id BIGINT,
    ADD COLUMN IF NOT EXISTS escalated_to_sat_at TIMESTAMPTZ;

DO $do$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'fk_incidents_escalated_from_restocker'
           AND conrelid = 'incidents'::regclass
    ) THEN
        ALTER TABLE incidents
            ADD CONSTRAINT fk_incidents_escalated_from_restocker
            FOREIGN KEY (escalated_from_restocker_id)
            REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE;
    END IF;

    -- Guarda constitucional Art. II, QA-LIM-03 y QA-AMB-01:
    -- 1. Jamás L1_RESTOCKER en TEMPERATURE_COLD ni ELECTRICAL_OFF.
    -- 2. Jamás PENDING_PARTS ni pending_parts_reason en L1_RESTOCKER.
    -- 3. En L1, el motivo de Nivel 1 exige su categoría determinista. En L2
    --    puede conservarse como rastro histórico aunque la categoría cambie.
    -- 4. Coherencia todo-o-nada entre escalated_from_restocker_id y escalated_to_sat_at.
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'chk_incidents_l1_dispatch_coherence'
           AND conrelid = 'incidents'::regclass
    ) THEN
        ALTER TABLE incidents
            ADD CONSTRAINT chk_incidents_l1_dispatch_coherence
            CHECK (
                (
                    dispatch_level = 'L2_SAT'
                    OR (
                        dispatch_level = 'L1_RESTOCKER'
                        AND category NOT IN ('TEMPERATURE_COLD', 'ELECTRICAL_OFF')
                        AND status <> 'PENDING_PARTS'
                        AND pending_parts_reason IS NULL
                        AND (
                            level1_issue_type IS NULL
                            OR (level1_issue_type = 'STOCKOUT_EMPTY_SLOT' AND category = 'PRODUCT_JAM')
                            OR (level1_issue_type = 'MINOR_SPIRAL_JAM' AND category = 'PRODUCT_JAM')
                            OR (level1_issue_type = 'EMPTY_CHANGE_TUBES' AND category = 'PAYMENT_SYSTEM')
                            OR (level1_issue_type = 'MISSING_CONSUMABLES' AND category = 'OTHER')
                        )
                    )
                )
                AND (
                    (escalated_from_restocker_id IS NULL AND escalated_to_sat_at IS NULL)
                    OR (escalated_from_restocker_id IS NOT NULL AND escalated_to_sat_at IS NOT NULL)
                )
            );
    END IF;
END $do$;

-- La cola de Coordinación filtra por nivel operativo y la hoja de ruta del
-- reponedor busca sus avisos vivos por custodio.
CREATE INDEX IF NOT EXISTS idx_incidents_dispatch_level_status
    ON incidents (dispatch_level, status, assigned_technician_id)
    WHERE deleted_at IS NULL;


-- ----------------------------------------------------------------------------
-- 4. Ampliación de Tabla 34 · offline_sync_mutations (RF-05.1, C2)
-- ----------------------------------------------------------------------------
-- El reponedor sincroniza en diferido Inicio L1, Resolución L1 y Escalado al
-- SAT. El escalado puede dispararse desde una máquina sin ticket previo, de ahí
-- el `stop_type = 'MACHINE'`.
ALTER TABLE offline_sync_mutations
    DROP CONSTRAINT IF EXISTS chk_offline_sync_stop_type,
    ADD CONSTRAINT chk_offline_sync_stop_type CHECK (
        stop_type IN ('INCIDENT', 'PREVENTIVE_ORDER', 'MACHINE')
    ),
    DROP CONSTRAINT IF EXISTS chk_offline_sync_operation_type,
    ADD CONSTRAINT chk_offline_sync_operation_type CHECK (
        operation_type IN (
            'START_INCIDENT',
            'RESUME_INCIDENT_PENDING_INFO',
            'PAUSE_INCIDENT_PARTS',
            'PAUSE_INCIDENT_PENDING_INFO',
            'RESOLVE_INCIDENT',
            'START_PREVENTIVE',
            'COMPLETE_PREVENTIVE',
            'REINSPECT_PREVENTIVE',
            'ADD_INCIDENT_COMMENT',
            'START_L1_INCIDENT',
            'RESOLVE_L1_INCIDENT',
            'ESCALATE_INCIDENT_TO_SAT'
        )
    );


-- ----------------------------------------------------------------------------
-- 5. Semillas maestras de taxonomía de Nivel 1 (RF-01.3, QA-AMB-01)
-- ----------------------------------------------------------------------------
-- Solo aseguran que la fila exista: la taxonomía la gobierna Coordinación y un
-- renombrado, una baja lógica o un cambio de predeterminada posterior no puede
-- deshacerse al reaplicar el arranque.
INSERT INTO root_causes (code, category, name, description, is_active)
VALUES
    ('RC-L1-STOCKOUT', 'PRODUCT_JAM', 'Agotamiento de producto en carril de selección', NULL, TRUE),
    ('RC-L1-SPIRAL-RELEASE', 'PRODUCT_JAM', 'Envase mal posicionado o trabado leve en boca de espiral', NULL, TRUE),
    ('RC-L1-EMPTY-TUBES', 'PAYMENT_SYSTEM', 'Tubos de cambio del monedero sin suficiente moneda fraccionaria', NULL, TRUE),
    ('RC-L1-CONSUMABLES', 'OTHER', 'Falta de vasos, azúcar o paletinas en dispensador', NULL, TRUE)
ON CONFLICT (code) DO NOTHING;

INSERT INTO standard_solutions (root_cause_id, code, name, is_default, is_active)
SELECT rc.id, seed.code, seed.name, seed.is_default, TRUE
  FROM (
      VALUES
          ('RC-L1-STOCKOUT', 'SS-L1-RESTOCK-SLOT', 'Reposición de producto en carril y verificación de entrega', TRUE),
          ('RC-L1-SPIRAL-RELEASE', 'SS-L1-CLEAR-SPIRAL', 'Liberación manual de envase en espiral y reacomodo de carril', TRUE),
          ('RC-L1-EMPTY-TUBES', 'SS-L1-REFILL-TUBES', 'Recarga de tubos de cambio y comprobación de devolución', TRUE),
          ('RC-L1-CONSUMABLES', 'SS-L1-REFILL-CUPS-SUGAR', 'Reposición de consumibles (vasos/azúcar/paletinas) y limpieza de tolva', TRUE)
  ) AS seed(cause_code, code, name, is_default)
  JOIN root_causes rc ON rc.code = seed.cause_code
ON CONFLICT (code) DO NOTHING;
