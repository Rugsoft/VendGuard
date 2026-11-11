-- ============================================================================
-- Módulo 31 · Rol de Cargador/Reponedor de Ruta, Zonificación de Carga y
--             Control de Jornada
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   El parque de máquinas se abastecía sin un actor formal en el dominio: el
--   técnico SAT hacía las veces de reponedor y el cuadrante territorial solo
--   conocía técnicos. La Feature 21 del catálogo incorpora al cuarto actor
--   operativo —el Cargador/Reponedor de Ruta (`RESTOCKER`)— con permisos
--   segregados del técnico, demarcaciones territoriales propias y control de
--   jornada (RF-01.1, RF-02.1, D3, QA 1).
--
-- QUÉ DECLARA
--   1. El valor `RESTOCKER` del enumerado `user_role`: el rol operativo nuevo
--      (RF-01.1). No se toca la ortografía de los dos existentes.
--   2. El valor `RESTOCKER_ZONE` del enumerado `audit_entity_type`: el cuadrante
--      de carga se audita con entidad propia para no confundirlo con la zona ni
--      con el técnico (QA 1).
--   3. La guarda constitucional `chk_users_restocker_not_on_call`: un reponedor
--      jamás ostenta la guardia técnica 24/7 del Art. II ni puede quedar marcado
--      como técnico de guardia (D3, EARS 01.1).
--   4. La Tabla 41 `restocker_zone_assignments` con `role_in_zone` e `is_primary`
--      sincronizados (QA 1), titularidad única sobre vinculaciones vivas, baja
--      lógica (`removed_at`) y disparador constitucional anti-borrado físico
--      (Art. III.1).
--
-- POR QUÉ LA GUARDA SE ESCRIBE CON `role::text`
--   PostgreSQL 12+ admite `ALTER TYPE ... ADD VALUE` dentro de la transacción
--   del ejecutor (`PostgresMigrationRunner`), pero el valor nuevo NO puede
--   usarse hasta que esa transacción confirme: el motor responde SQLSTATE 55P04
--   («unsafe use of new value»). Una restricción que nombre el literal
--   `'RESTOCKER'` lo resuelve al analizarse, así que sobre una base anterior a
--   este módulo el fichero abortaría. La comparación sobre `role::text` declara
--   exactamente la misma regla sin resolver el miembro del enumerado, y es la
--   forma que comparten esta migración y `database/supabase_init.sql` para que
--   una base creada y una base migrada declaren la misma guarda.
--   Verificado contra el motor local: la forma literal responde 55P04 en la
--   misma transacción y la forma `::text` se crea y muerde.
--
-- POR QUÉ ES IDEMPOTENTE
--   El ejecutor registra la huella de cada migración y no la reaplica, pero la
--   misma declaración vive también en `database/supabase_init.sql` (contrato de
--   correspondencia, §2). Por eso los valores del enumerado usan
--   `IF NOT EXISTS`, la restricción se recrea tras su `DROP ... IF EXISTS` y la
--   tabla, sus índices y su disparador se declaran de forma idempotente.
--
-- QUÉ NO HACE
--   No siembra los reponedores (`REP-01`/`REP-02`): eso es T-HYBRID-03 y
--   `SeedRunner`. No expone rutas, servicios ni vistas (Fases 3 a 6 del módulo)
--   y no modifica la regla de técnico único responsable del Art. V.3, que sigue
--   evaluándose solo sobre `technician_zone_assignments`.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Ampliación de los enumerados del dominio (RF-01.1, QA 1)
-- ----------------------------------------------------------------------------
-- El valor nuevo no se usa en esta transacción a propósito. Las filas de
-- auditoría del cuadrante de carga las escriben los servicios de aplicación,
-- cuando el libro ya ha confirmado la migración.
ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'RESTOCKER';

ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'RESTOCKER_ZONE';


-- ----------------------------------------------------------------------------
-- 2. Guarda constitucional del rol en `users` (D3, EARS 01.1)
-- ----------------------------------------------------------------------------
-- La guardia de guardia técnica del Art. II es exclusiva del `TECHNICIAN`: un
-- reponedor no atiende emergencias térmicas electromecánicas ni entra en el
-- turno de guardia 24/7.
ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_restocker_not_on_call;

ALTER TABLE users
    ADD CONSTRAINT chk_users_restocker_not_on_call
    CHECK (role::text <> 'RESTOCKER' OR is_on_call = FALSE);


-- ----------------------------------------------------------------------------
-- 3. Tabla 41 · Cuadrante de carga por zona operativa (RF-02.1, QA 1, Art. III.1)
-- ----------------------------------------------------------------------------
-- `role_in_zone` es la forma legible del vínculo y `is_primary` la que consultan
-- los índices parciales: ambos se declaran y `chk_restocker_zone_role_coherence`
-- impide que se contradigan (QA 1). La baja es lógica (`removed_at`), de modo que
-- el histórico del cuadrante no se borra y el reingreso estrena vinculación
-- nueva y auditada (Art. III.1).
CREATE TABLE IF NOT EXISTS restocker_zone_assignments (
    id            BIGINT GENERATED ALWAYS AS IDENTITY,
    restocker_id  BIGINT NOT NULL,
    zone_id       BIGINT NOT NULL,
    role_in_zone  VARCHAR(10) NOT NULL DEFAULT 'BACKUP',
    is_primary    BOOLEAN NOT NULL DEFAULT FALSE,
    assigned_at   TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    removed_at    TIMESTAMPTZ,
    CONSTRAINT pk_restocker_zone_assignments PRIMARY KEY (id),
    CONSTRAINT fk_restocker_zone_restocker FOREIGN KEY (restocker_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_restocker_zone_zone FOREIGN KEY (zone_id)
        REFERENCES operational_zones (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_restocker_zone_role_values CHECK (role_in_zone IN ('PRIMARY', 'BACKUP')),
    CONSTRAINT chk_restocker_zone_role_coherence CHECK (
        (role_in_zone = 'PRIMARY' AND is_primary = TRUE)
        OR (role_in_zone = 'BACKUP' AND is_primary = FALSE)
    ),
    CONSTRAINT chk_restocker_zone_removed_order CHECK (
        removed_at IS NULL OR removed_at >= assigned_at
    )
);

-- Unicidad solo sobre vinculaciones vivas: permite reingresos con histórico.
CREATE UNIQUE INDEX IF NOT EXISTS uq_restocker_zone_live
    ON restocker_zone_assignments (restocker_id, zone_id)
    WHERE removed_at IS NULL;

-- Titularidad única: un reponedor es Titular (`PRIMARY`) de una sola zona viva y
-- suplente (`BACKUP`) de cuantas haga falta (RF-02.1, D3).
CREATE UNIQUE INDEX IF NOT EXISTS uq_restocker_primary_zone
    ON restocker_zone_assignments (restocker_id)
    WHERE is_primary = TRUE AND removed_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_restocker_zone_assignments_zone_id
    ON restocker_zone_assignments (zone_id);

CREATE INDEX IF NOT EXISTS idx_restocker_zone_assignments_restocker_id
    ON restocker_zone_assignments (restocker_id);

DROP TRIGGER IF EXISTS trg_no_delete_restocker_zone_assignments ON restocker_zone_assignments;
CREATE TRIGGER trg_no_delete_restocker_zone_assignments
    BEFORE DELETE ON restocker_zone_assignments
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();
