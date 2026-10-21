-- ============================================================================
-- Módulo 18 · Stock de repuestos en la furgoneta del técnico
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
-- El coordinador no sabía qué repuestos llevaba cada técnico en la furgoneta, de
-- modo que la declaración de piezas sustituidas en una avería no dejaba rastro
-- del consumo del vehículo y la reposición se descubría tarde, sobre el terreno.
-- Este módulo guarda un saldo declarado por pareja técnico/pieza, lo descuenta
-- cuando el técnico declara piezas instaladas y avisa cuando toca el umbral
-- (RF-01 a RF-03 del módulo 18).
--
-- QUÉ DECLARA
--   1. `technician_van_stocks` — saldo declarado por técnico y pieza, con su
--      umbral mínimo de reposición.
--   2. El valor `TECHNICIAN_VAN_STOCK` del enumerado `audit_entity_type`
--      (RF-05.1), para que la auditoría del ajuste no se confunda con la de la
--      pieza del catálogo ni con la del usuario.
--
-- QUÉ NO DECLARA, A PROPÓSITO
--   Ninguna columna de coste ni de valoración monetaria: el coste del repuesto
--   ya se congela de forma inmutable al declararlo en la avería
--   (`incident_replaced_parts.unit_cost_snapshot`, M2). Tampoco hay libro de
--   movimientos: el consumo real vive en `incident_replaced_parts`, que es el
--   histórico inmutable (Art. III.3), y esta tabla es solo el saldo vigente
--   (RD-05).
--
-- NOTA SOBRE LA CANTIDAD NEGATIVA
--   `quantity_available` admite valores negativos a propósito: significan que se
--   declararon más unidades instaladas de las que constaban cargadas. Esa señal
--   operativa —un descuadre— no se trunca a cero ni bloquea el cierre de la
--   avería, porque el Art. V.1 y el Art. II prohíben subordinar la resolución a
--   la contabilidad del inventario (RD-04).
--
-- NOTA SOBRE EL EJECUTOR (PostgresMigrationRunner):
-- Aplica cada migración dentro de una transacción. PostgreSQL 12+ admite
-- `ALTER TYPE ... ADD VALUE` en ese contexto, pero el valor nuevo NO puede
-- usarse hasta que la transacción confirme. Por eso este fichero se limita a
-- declarar el valor: ninguna fila de auditoría de esta entidad se escribe aquí.
--
-- NOTA SOBRE LA IDEMPOTENCIA:
-- El ejecutor registra la huella de cada migración y no la reaplica, pero la
-- misma declaración vive también en `database/supabase_init.sql` (contrato de
-- correspondencia, §2). Por eso todo se declara con `IF NOT EXISTS`: una base
-- creada desde el DDL base y una base migrada tienen que quedar con la misma
-- forma.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Saldo declarado de la furgoneta (RF-01.1, RF-01.2, RD-04)
-- ----------------------------------------------------------------------------
-- La unicidad por pareja es la razón de ser de la tabla: un técnico tiene un
-- saldo por pieza, no un histórico de apuntes. El ajuste del coordinador es un
-- valor absoluto y el descuento automático resta sobre esa misma fila.
CREATE TABLE IF NOT EXISTS technician_van_stocks (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY,
    technician_id      BIGINT NOT NULL,
    spare_part_id      BIGINT NOT NULL,
    quantity_available INTEGER NOT NULL DEFAULT 0,
    min_threshold      INTEGER NOT NULL DEFAULT 0,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_technician_van_stocks PRIMARY KEY (id),
    CONSTRAINT uq_van_stock_technician_part UNIQUE (technician_id, spare_part_id),
    CONSTRAINT chk_van_stock_quantity_range CHECK (quantity_available BETWEEN -999 AND 9999),
    CONSTRAINT chk_van_stock_threshold_range CHECK (min_threshold BETWEEN 0 AND 9999),
    CONSTRAINT fk_van_stock_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_van_stock_spare_part FOREIGN KEY (spare_part_id)
        REFERENCES spare_parts (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_technician_van_stocks_technician_id
    ON technician_van_stocks (technician_id);

CREATE INDEX IF NOT EXISTS idx_technician_van_stocks_spare_part_id
    ON technician_van_stocks (spare_part_id);

CREATE OR REPLACE TRIGGER trg_no_delete_technician_van_stocks
    BEFORE DELETE ON technician_van_stocks
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_technician_van_stocks
    BEFORE UPDATE ON technician_van_stocks
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ----------------------------------------------------------------------------
-- 2. Auditoría del saldo de furgoneta (RF-05.1)
-- ----------------------------------------------------------------------------
-- El ajuste del coordinador deja evento con tipo propio: el rastro dice que se
-- tocó el stock de una furgoneta, no el catálogo de repuestos ni el usuario.
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'TECHNICIAN_VAN_STOCK';
