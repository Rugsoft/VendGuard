-- ============================================================================
-- Módulo 27 · Bandeja de Taller Central para Piezas Recuperadas
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   Una pieza desmontada con destino `TALLER` solo dejaba rastro dentro del
--   expediente de la avería: el técnico declaraba que la guardaba para taller y
--   el sistema perdía su rastro físico en el acto. Nadie podía responder qué
--   unidad concreta esperaba entrega en la furgoneta, cuál había entrado en el
--   banco de pruebas, con qué diagnóstico se reacondicionó ni cuánto ahorró
--   frente a comprar una nueva. Esta migración introduce el expediente unitario
--   del ciclo de taller con sus snapshots congelados y su sellado de estados.
--
-- QUÉ DECLARA
--   1. Los tipos `workshop_repair_status` (5 estados del ciclo, `D1`) y
--      `workshop_restock_destination` (2 destinos de reintegro, `D3`).
--   2. La ampliación del catálogo de auditoría con `WORKSHOP_REPAIR_ITEM`, para
--      que cada transición del ciclo quede registrada por el módulo de auditoría
--      sin abrir un tipo nuevo por operación.
--   3. La Tabla 38 `workshop_repair_items`, con el desglose unitario `1..N`
--      (`D1`, `C4`, `C6`, `QA 4`): una fila por unidad física extraída, sellada
--      por `uq_workshop_repair_replaced_part_unit`, nunca una fila «cantidad: 3».
--   4. Diez claves foráneas con `ON DELETE RESTRICT` (Art. III.3), los seis
--      índices de lectura de la bandeja y los disparadores constitucionales
--      `trg_no_delete_workshop_repair_items` (Art. III.1) y
--      `trg_touch_workshop_repair_items` (Art. III.3).
--   5. El backfill idempotente de las sustituciones anteriores al módulo, con
--      `generate_series(1, quantity)` para que una línea de tres unidades genere
--      tres expedientes y no uno.
--
-- POR QUÉ LOS SNAPSHOTS VIVEN EN LA TABLA Y NO SE LEEN DEL CATÁLOGO
--   Renombrar una pieza, recalibrar su coste de referencia o dar de baja una
--   máquina reescribiría el pasado si la fila guardase solo la clave foránea.
--   El expediente congela lo que estaba vigente en el instante de la extracción
--   (`part_code_snapshot`, `part_name_snapshot`, `part_category_snapshot`,
--   `machine_code_snapshot`, `machine_model_snapshot`, `location_name_snapshot`,
--   `technician_name_snapshot`, `unit_cost_snapshot`), y la clave foránea queda
--   únicamente para integridad referencial y para las consultas por categoría o
--   por técnico. `is_out_of_catalog` distingue la pieza que nunca estuvo en el
--   catálogo de la que sí estuvo y hoy ya no está: el `spare_part_id` del alta
--   libre puede ser nulo y el nombre viaja igualmente congelado.
--
-- POR QUÉ EL DICTAMEN ES UN SELLO DE UN SOLO USO (`C5`)
--   `REFURBISHED_RESTORED` y `SCRAPPED_IRREPARABLE` son terminales. La
--   coherencia se declara en el motor y no solo en el servicio: un dictamen sin
--   diagnóstico de banco, un reintegro a furgoneta de una pieza fuera de
--   catálogo o una baja con destino de stock quedan rechazados por
--   `chk_workshop_repair_terminal_coherence` aunque un camino futuro olvide
--   validarlo. El service responde `409 WORKSHOP_ITEM_ALREADY_RESOLVED`; la
--   tabla se niega a escribir el estado imposible.
--
-- POR QUÉ LA BAJA PUEDE NO HABER PASADO NUNCA POR EL TALLER (`C1`)
--   La pieza extraviada antes de llegar a recepción no puede exigir
--   `received_at`: la rama de `SCRAPPED_IRREPARABLE` admite las dos formas
--   coherentes —sin recepción ni banco, o con ambas marcas— y exige en cambio
--   el diagnóstico, el actor que dictamina y el importe de ahorro a cero. Un
--   extravío no ahorra dinero y no debe inflar `recovery_rate_pct` (`QA 1`).
--
-- POR QUÉ LA MIGRACIÓN ES IDEMPOTENTE
--   El ejecutor registra la huella de cada migración y no la reaplica, pero la
--   misma declaración vive en `database/supabase_init.sql` para que una base
--   creada desde cero y una base migrada converjan. Los tipos se crean
--   tolerando el objeto duplicado, las tablas e índices usan `IF NOT EXISTS`,
--   los disparadores se recrean y el backfill solo inserta las unidades que aún
--   no existen (`ON CONFLICT ... DO NOTHING`).
--
-- QUÉ NO HACE
--   No escribe el ciclo desde los flujos operativos: la apertura unitaria en el
--   cierre técnico y las transiciones de recepción, banco y dictamen ocurren en
--   la misma transacción PDO que las sirve (Módulo 27, Fases 3 y 4). No guarda
--   números de serie individuales, no abre RMA externo, no mide horas de banco
--   ni lleva stock cuantitativo del almacén central (Art. VI.1).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Estados del ciclo de taller (`D1`) y destinos de reintegro (`D3`)
-- ----------------------------------------------------------------------------
DO $do$
BEGIN
    CREATE TYPE workshop_repair_status AS ENUM (
        'PENDING_DELIVERY',
        'RECEIVED_IN_WORKSHOP',
        'IN_BENCH_TEST',
        'REFURBISHED_RESTORED',
        'SCRAPPED_IRREPARABLE'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE workshop_restock_destination AS ENUM (
        'CENTRAL_WAREHOUSE',
        'TECHNICIAN_VAN'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- La entidad entra en el catálogo de auditoría sin inventar un tipo por
-- operación: `WORKSHOP_REPAIR_ITEM` cubre recepción, banco y dictamen.
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'WORKSHOP_REPAIR_ITEM';

-- ----------------------------------------------------------------------------
-- 2. Tabla 38 `workshop_repair_items` · una fila por unidad física
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS workshop_repair_items (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY,
    incident_replaced_part_id   BIGINT NOT NULL,
    unit_index                  SMALLINT NOT NULL DEFAULT 1,
    total_units_in_line         SMALLINT NOT NULL DEFAULT 1,
    intervention_type           intervention_type NOT NULL,
    incident_id                 BIGINT,
    preventive_order_id         BIGINT,
    machine_id                  BIGINT NOT NULL,
    location_id                 BIGINT NOT NULL,
    extracted_by_technician_id  BIGINT NOT NULL,
    spare_part_id               BIGINT,
    is_out_of_catalog           BOOLEAN NOT NULL DEFAULT FALSE,
    part_code_snapshot          VARCHAR(64),
    part_name_snapshot          VARCHAR(255) NOT NULL,
    part_category_snapshot      VARCHAR(32) NOT NULL DEFAULT 'OTHER',
    machine_code_snapshot       VARCHAR(50) NOT NULL,
    machine_model_snapshot      VARCHAR(100) NOT NULL,
    location_name_snapshot      VARCHAR(150) NOT NULL,
    technician_name_snapshot    VARCHAR(100) NOT NULL,
    unit_cost_snapshot          NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    status                      workshop_repair_status NOT NULL DEFAULT 'PENDING_DELIVERY',
    received_at                 TIMESTAMPTZ,
    received_by_user_id         BIGINT,
    reception_notes             VARCHAR(500),
    bench_started_at            TIMESTAMPTZ,
    bench_diagnosis             TEXT,
    refurbishment_cost_eur      NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    net_savings_eur             NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    restock_destination         workshop_restock_destination,
    restocked_to_technician_id  BIGINT,
    resolved_at                 TIMESTAMPTZ,
    resolved_by_user_id         BIGINT,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_workshop_repair_items PRIMARY KEY (id),
    CONSTRAINT uq_workshop_repair_replaced_part_unit UNIQUE (incident_replaced_part_id, unit_index),
    CONSTRAINT fk_workshop_repair_replaced_part FOREIGN KEY (incident_replaced_part_id)
        REFERENCES incident_replaced_parts (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_workshop_repair_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_workshop_repair_preventive FOREIGN KEY (preventive_order_id)
        REFERENCES preventive_orders (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_workshop_repair_machine FOREIGN KEY (machine_id)
        REFERENCES machines (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_workshop_repair_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_workshop_repair_extracted_tech FOREIGN KEY (extracted_by_technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_workshop_repair_spare_part FOREIGN KEY (spare_part_id)
        REFERENCES spare_parts (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_workshop_repair_received_by FOREIGN KEY (received_by_user_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_workshop_repair_restocked_tech FOREIGN KEY (restocked_to_technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_workshop_repair_resolved_by FOREIGN KEY (resolved_by_user_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,

    -- El desglose unitario nunca desborda la línea de la que nace (`D1`).
    CONSTRAINT chk_workshop_repair_unit_bounds CHECK (
        unit_index >= 1
        AND total_units_in_line >= 1
        AND unit_index <= total_units_in_line
        AND total_units_in_line <= 50
    ),
    -- Un coste negativo o un ahorro negativo no son datos, son un error (`C2`).
    CONSTRAINT chk_workshop_repair_costs_non_negative CHECK (
        unit_cost_snapshot >= 0.00
        AND refurbishment_cost_eur >= 0.00
        AND refurbishment_cost_eur <= 9999.99
        AND net_savings_eur >= 0.00
    ),
    CONSTRAINT chk_workshop_repair_reception_notes_len CHECK (
        reception_notes IS NULL OR char_length(btrim(reception_notes)) BETWEEN 1 AND 500
    ),
    -- El suelo de 20 caracteres de Art. V.1 también vive en el motor (`QA 1`).
    CONSTRAINT chk_workshop_repair_bench_diagnosis_len CHECK (
        bench_diagnosis IS NULL OR char_length(btrim(bench_diagnosis)) BETWEEN 20 AND 1000
    ),
    -- Coherencia estricta de los cinco estados (`C1`, `C5`, `D3`).
    CONSTRAINT chk_workshop_repair_terminal_coherence CHECK (
        (
            status IN ('PENDING_DELIVERY', 'RECEIVED_IN_WORKSHOP', 'IN_BENCH_TEST')
            AND resolved_at IS NULL
            AND resolved_by_user_id IS NULL
            AND restock_destination IS NULL
            AND restocked_to_technician_id IS NULL
        )
        OR (
            status = 'REFURBISHED_RESTORED'
            AND received_at IS NOT NULL
            AND bench_started_at IS NOT NULL
            AND bench_diagnosis IS NOT NULL
            AND char_length(btrim(bench_diagnosis)) BETWEEN 20 AND 1000
            AND resolved_at IS NOT NULL
            AND resolved_by_user_id IS NOT NULL
            AND restock_destination IS NOT NULL
            AND (
                (restock_destination = 'CENTRAL_WAREHOUSE' AND restocked_to_technician_id IS NULL)
                OR (
                    restock_destination = 'TECHNICIAN_VAN'
                    AND restocked_to_technician_id IS NOT NULL
                    AND is_out_of_catalog = FALSE
                    AND spare_part_id IS NOT NULL
                )
            )
        )
        OR (
            status = 'SCRAPPED_IRREPARABLE'
            AND (
                (received_at IS NULL AND bench_started_at IS NULL)
                OR (received_at IS NOT NULL AND bench_started_at IS NOT NULL)
            )
            AND bench_diagnosis IS NOT NULL
            AND char_length(btrim(bench_diagnosis)) BETWEEN 20 AND 1000
            AND resolved_at IS NOT NULL
            AND resolved_by_user_id IS NOT NULL
            AND restock_destination IS NULL
            AND restocked_to_technician_id IS NULL
            AND refurbishment_cost_eur = 0.00
            AND net_savings_eur = 0.00
        )
    )
);

-- ----------------------------------------------------------------------------
-- 3. Índices de lectura de la bandeja y de la cartera del técnico
-- ----------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_workshop_repair_status_created
    ON workshop_repair_items (status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_workshop_repair_extracted_tech
    ON workshop_repair_items (extracted_by_technician_id, status);
CREATE INDEX IF NOT EXISTS idx_workshop_repair_spare_part
    ON workshop_repair_items (spare_part_id, status);
CREATE INDEX IF NOT EXISTS idx_workshop_repair_incident
    ON workshop_repair_items (incident_id, unit_index);
CREATE INDEX IF NOT EXISTS idx_workshop_repair_preventive_order
    ON workshop_repair_items (preventive_order_id, unit_index);
CREATE INDEX IF NOT EXISTS idx_workshop_repair_resolved_at
    ON workshop_repair_items (resolved_at DESC);

-- ----------------------------------------------------------------------------
-- 4. Disparadores constitucionales (Art. III.1 y Art. III.3)
-- ----------------------------------------------------------------------------
CREATE OR REPLACE TRIGGER trg_no_delete_workshop_repair_items
    BEFORE DELETE ON workshop_repair_items
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_workshop_repair_items
    BEFORE UPDATE ON workshop_repair_items
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ----------------------------------------------------------------------------
-- 5. Backfill unitario de las sustituciones anteriores al módulo (`D1`, `QA 4`)
-- ----------------------------------------------------------------------------
-- Una línea con `quantity = 3` genera tres expedientes `PENDING_DELIVERY`, uno
-- por unidad, no una fila con la cantidad agregada. El técnico extractor es el
-- custodio declarado en el expediente: el asignado a la incidencia, el asignado
-- a la orden preventiva y, en su defecto, el técnico que registró la sustitución.
-- `created_at` conserva el instante de la extracción para que la antigüedad del
-- recordatorio móvil cuente desde el hecho real y no desde la migración.
INSERT INTO workshop_repair_items (
    incident_replaced_part_id,
    unit_index,
    total_units_in_line,
    intervention_type,
    incident_id,
    preventive_order_id,
    machine_id,
    location_id,
    extracted_by_technician_id,
    spare_part_id,
    is_out_of_catalog,
    part_code_snapshot,
    part_name_snapshot,
    part_category_snapshot,
    machine_code_snapshot,
    machine_model_snapshot,
    location_name_snapshot,
    technician_name_snapshot,
    unit_cost_snapshot,
    created_at
)
SELECT
    irp.id,
    gs.unit_idx,
    GREATEST(irp.quantity, 1),
    irp.intervention_type,
    irp.incident_id,
    irp.preventive_order_id,
    irp.machine_id,
    irp.location_id,
    COALESCE(i.assigned_technician_id, po.assigned_technician_id, irp.technician_id),
    irp.spare_part_id,
    irp.is_out_of_catalog,
    sp.part_code,
    COALESCE(
        NULLIF(btrim(sp.name), ''),
        NULLIF(btrim(irp.custom_part_name), ''),
        'Pieza extraída sin catálogo'
    ),
    COALESCE(sp.category::text, 'OTHER'),
    m.code,
    m.model,
    l.name,
    u.name,
    irp.unit_cost_snapshot,
    irp.created_at
FROM incident_replaced_parts irp
JOIN machines m ON m.id = irp.machine_id
JOIN locations l ON l.id = irp.location_id
LEFT JOIN incidents i ON i.id = irp.incident_id
LEFT JOIN preventive_orders po ON po.id = irp.preventive_order_id
LEFT JOIN spare_parts sp ON sp.id = irp.spare_part_id
JOIN users u ON u.id = COALESCE(i.assigned_technician_id, po.assigned_technician_id, irp.technician_id)
CROSS JOIN LATERAL generate_series(1, GREATEST(irp.quantity, 1)) AS gs(unit_idx)
WHERE irp.old_part_destination = 'TALLER'
ON CONFLICT (incident_replaced_part_id, unit_index) DO NOTHING;
