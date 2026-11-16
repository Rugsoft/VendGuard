-- ============================================================================
-- Módulo 35 · Recaudación de Efectivo, Carga de Tubos de Cambio y Arqueo de
--             Caja (Cash Collection, Coin Changer Refill & Vault Audit)
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
--   La visita de reposición in situ (Módulo 34) demuestra qué producto entró y
--   salió de cada carril, pero no custodia el efectivo: cuando el reponedor
--   extrae la hucha y el apilador de billetes no queda rastro del precinto de la
--   bolsa, de la lectura acumulada del monedero ni del cambio cargado en los
--   tubos. Sin esos tres datos Caja Central no puede contrastar a ciegas lo
--   contado contra lo facturado desde el vaciado anterior, y un descuadre real
--   se confunde con una avería. Tampoco existía forma de que el reponedor
--   entregase en conserjería el efectivo de una reclamación de hasta 10,00 €
--   (Módulo 08) sin esperar a un Técnico SAT.
--   Este fichero declara la Tabla 49 `machine_cash_collections`: el corte
--   formal de bolsa precintada, la carga de tubos de cambio, la entrega en
--   recepción y el arqueo terminal en Caja Central.
--
-- QUÉ DECLARA
--   1. El tipo nativo #42 `cash_collection_status` (`'SEALED_IN_ROUTE'`,
--      `'DELIVERED_TO_VAULT'`, `'RECONCILED_OK'`, `'DISCREPANCY_FLAGGED'`) y el
--      valor `'MACHINE_CASH_COLLECTION'` del enumerado `audit_entity_type`
--      (Art. III.3).
--   2. La Tabla 49 `machine_cash_collections` con la identidad legible
--      `collection_code` (`MCC-AAAA-NNNNNN`), el precinto único global
--      `bag_seal_code` (C1), la idempotencia del móvil
--      `(restocker_id, client_mutation_id)` (D4), el vínculo opcional a la
--      visita del Módulo 34 (D1) y al reintegro del Módulo 08 (D3), la captura
--      en ruta congelada al insertar (D2) y el ciclo de custodia en Caja Central
--      (D4, QA 1).
--   3. Los índices únicos parciales `uq_machine_cash_collections_visit` (a lo
--      sumo una recaudación por visita) y `uq_machine_cash_collections_refund`
--      (un expediente de conserjería solo se liquida una vez), más los tres
--      índices de consulta del libro contable.
--   4. Las ocho restricciones `CHECK` que sostienen la aritmética del arqueo
--      ciego: coherencia del contador con y sin reseteo, cotas económicas,
--      coherencia de la entrega en conserjería, snapshots no vacíos y
--      ciclo de vida por estados con la tolerancia de ±1,00 € (D2, C3,
--      Art. V.1).
--   5. El blindaje constitucional completo: prohibición de borrado físico
--      (`prevent_hard_delete()`, Art. III.1), inmutabilidad de los datos de ruta
--      y bloqueo terminal tras el arqueo
--      (`enforce_machine_cash_collections_immutability()`, Art. III.3) y la
--      guarda sanitaria y de conserjería en `BEFORE INSERT`
--      (`enforce_cash_collection_sanitary_guard()`, Art. II.2).
--
-- POR QUÉ EL CERROJO VIVE TAMBIÉN EN EL MOTOR (RNF-02)
--   El validador y el servicio de aplicación comprueban la cuarentena, el
--   bloqueo de acceso, la avería térmica abierta y el aborto térmico de la
--   visita antes de persistir, pero una consola SQL o un importador no pasan por
--   ellos. El Art. II no admite una salvaguarda que dependa del cliente: una
--   recaudación sobre una máquina en `QUARANTINE`, fuera de servicio, con un
--   ticket `TEMPERATURE_COLD` vivo o colgada de una visita
--   `ABORTED_THERMAL_BREACH` se rechaza con `SQLSTATE 45000`. La entrega en
--   conserjería exige además que la sede tenga `has_physical_reception = TRUE`
--   (D3): el motor no deja que un cliente antiguo deposite efectivo en una sede
--   sin recepción física.
--
-- POR QUÉ LA RUTA ES INMUTABLE Y EL ARQUEO TERMINAL (Art. III.1 y Art. III.3)
--   Lo declarado en ruta —precinto, lecturas, cambio cargado, entrega y notas—
--   es la prueba de custodia del efectivo. Rectificarlo después sería reescribir
--   la historia contable, así que ninguna de esas columnas admite `UPDATE`. El
--   arqueo, una vez sellado en `'RECONCILED_OK'` o `'DISCREPANCY_FLAGGED'`, es
--   terminal: la corrección se registra como una recaudación nueva, no
--   reescribiendo la anterior. Los `CHECK` de ciclo de vida impiden además que
--   una fila salte de estado sin sus marcas de recepción y arqueo.
--
-- POR QUÉ ES IDEMPOTENTE
--   El ejecutor registra la huella de cada migración y no la reaplica, pero
--   estas mismas declaraciones viven en `database/supabase_init.sql` (contrato
--   de correspondencia). Por eso el tipo se crea en un bloque que tolera
--   `duplicate_object`, la tabla y los índices usan `IF NOT EXISTS` y los
--   disparadores se recrean tras su `DROP ... IF EXISTS`.
--
-- QUÉ NO HACE
--   No siembra recaudaciones de ejemplo (eso es `supabase_seeds.sql` y
--   `SeedRunner`). No expone endpoints, servicios ni vistas (Fases 2 a 6 del
--   módulo) y no toca el contador de la máquina, la visita in situ, el
--   expediente de reintegro ni el catálogo comercial.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Tipo nativo #42 y entidad de auditoría (RF-01.1, Art. III.3)
-- ----------------------------------------------------------------------------
DO $do$
BEGIN
    CREATE TYPE cash_collection_status AS ENUM (
        'SEALED_IN_ROUTE',
        'DELIVERED_TO_VAULT',
        'RECONCILED_OK',
        'DISCREPANCY_FLAGGED'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- El valor nuevo no se usa en esta transacción a propósito: PostgreSQL no deja
-- referenciar un miembro añadido hasta que la transacción que lo añadió
-- confirme (SQLSTATE 55P04). Los eventos de auditoría los escriben los servicios
-- de aplicación, cuando el libro ya ha confirmado la migración.
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'MACHINE_CASH_COLLECTION';


-- ----------------------------------------------------------------------------
-- 2. Tabla 49 · machine_cash_collections — corte de bolsa, cambio y arqueo
--    (RF-01.1, D1–D4, C1–C3, QA 1, Art. II.2, Art. III.1, Art. III.3, Art. V.1)
-- ----------------------------------------------------------------------------
-- `collection_code` es la identidad legible e inmutable (`MCC-AAAA-NNNNNN`).
--   * `bag_seal_code` se normaliza con `UPPER(BTRIM(...))` y es único global:
--     un precinto no se reutiliza jamás en la historia del sistema (C1).
--   * `uq_machine_cash_collections_mutation` resuelve la idempotencia del móvil:
--     el reenvío de la misma mutación devuelve la fila ya grabada en lugar de
--     duplicar el corte (D4).
--   * `restock_visit_id` es nulo en la vía rápida independiente y apunta a la
--     visita del Módulo 34 en la vía integrada (D1); el índice único parcial
--     impide dos recaudaciones sobre la misma visita.
--   * `previous_sales_counter_reading` y `expected_cashbox_eur` son datos
--     privados de Coordinación: la base los guarda, la capa de aplicación los
--     omite en toda respuesta al reponedor (C2, Art. V.4).
--   * `initial_baseline_reading_eur` solo puede ser distinto de 0,00 en la
--     primera recaudación histórica sin reseteo; con reseteo o con lectura
--     previa se fuerza a 0,00 (D2, C3).
CREATE TABLE IF NOT EXISTS machine_cash_collections (
    id                                  BIGINT GENERATED ALWAYS AS IDENTITY,
    collection_code                     VARCHAR(32) NOT NULL,
    restock_visit_id                    BIGINT,
    machine_id                          BIGINT NOT NULL,
    machine_code_snapshot               VARCHAR(32) NOT NULL,
    restocker_id                        BIGINT NOT NULL,
    restocker_name_snapshot             VARCHAR(150) NOT NULL,
    restocker_code_snapshot             VARCHAR(32) NOT NULL,
    bag_seal_code                       VARCHAR(40) NOT NULL,
    change_tubes_loaded_eur             NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    sales_counter_reading               NUMERIC(12, 2) NOT NULL,
    previous_sales_counter_reading      NUMERIC(12, 2),
    initial_baseline_reading_eur        NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
    is_counter_reset                    BOOLEAN NOT NULL DEFAULT FALSE,
    counter_reset_notes                 VARCHAR(500),
    expected_cashbox_eur                NUMERIC(10, 2) NOT NULL,
    reception_cash_delivered_eur        NUMERIC(6, 2) NOT NULL DEFAULT 0.00,
    receptionist_name                   VARCHAR(120),
    linked_refund_request_id            BIGINT,
    route_notes                         VARCHAR(500),
    status                              cash_collection_status NOT NULL DEFAULT 'SEALED_IN_ROUTE',
    collected_at                        TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_to_vault_at               TIMESTAMPTZ,
    delivered_to_vault_by               BIGINT,
    vault_counted_eur                   NUMERIC(10, 2),
    discrepancy_eur                     NUMERIC(10, 2),
    vault_reconciled_by                 BIGINT,
    vault_reconciled_by_name_snapshot   VARCHAR(150),
    vault_reconciled_at                 TIMESTAMPTZ,
    discrepancy_justification           VARCHAR(1000),
    client_mutation_id                  VARCHAR(64) NOT NULL,
    created_at                          TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                          TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_machine_cash_collections PRIMARY KEY (id),
    CONSTRAINT uq_machine_cash_collections_code UNIQUE (collection_code),
    CONSTRAINT uq_machine_cash_collections_bag_seal UNIQUE (bag_seal_code),
    CONSTRAINT uq_machine_cash_collections_mutation UNIQUE (restocker_id, client_mutation_id),
    CONSTRAINT fk_machine_cash_collections_visit
        FOREIGN KEY (restock_visit_id) REFERENCES machine_restock_visits (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_cash_collections_machine
        FOREIGN KEY (machine_id) REFERENCES machines (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_cash_collections_restocker
        FOREIGN KEY (restocker_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_cash_collections_refund
        FOREIGN KEY (linked_refund_request_id) REFERENCES refund_requests (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_cash_collections_delivered_by
        FOREIGN KEY (delivered_to_vault_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_cash_collections_reconciled_by
        FOREIGN KEY (vault_reconciled_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_machine_cash_collections_code_format
        CHECK (collection_code ~ '^MCC-[0-9]{4}-[0-9]{4,}$'),
    -- C1: sin espacios, en mayúsculas, entre 6 y 40 caracteres y con al menos un
    -- bloque numérico de tres dígitos. El formato se comprueba también aquí
    -- porque una consola SQL no pasa por el validador de aplicación.
    CONSTRAINT chk_machine_cash_collections_bag_seal_format
        CHECK (
            bag_seal_code ~ '^[A-Z0-9_-]{6,40}$'
            AND bag_seal_code ~ '[0-9]{3,}'
        ),
    CONSTRAINT chk_machine_cash_collections_mutation_format
        CHECK (char_length(btrim(client_mutation_id)) >= 6),
    CONSTRAINT chk_machine_cash_collections_snapshots_not_empty
        CHECK (
            char_length(btrim(machine_code_snapshot)) BETWEEN 1 AND 32
            AND char_length(btrim(restocker_name_snapshot)) BETWEEN 1 AND 150
            AND char_length(btrim(restocker_code_snapshot)) BETWEEN 1 AND 32
            AND (
                vault_reconciled_by_name_snapshot IS NULL
                OR char_length(btrim(vault_reconciled_by_name_snapshot)) BETWEEN 1 AND 150
            )
        ),
    CONSTRAINT chk_machine_cash_collections_amounts_bounds
        CHECK (
            change_tubes_loaded_eur >= 0.00 AND change_tubes_loaded_eur <= 500.00
            AND sales_counter_reading >= 0.00 AND sales_counter_reading <= 9999999.99
            AND (previous_sales_counter_reading IS NULL OR (previous_sales_counter_reading >= 0.00 AND previous_sales_counter_reading <= 9999999.99))
            AND initial_baseline_reading_eur >= 0.00 AND initial_baseline_reading_eur <= 9999999.99
            AND expected_cashbox_eur >= 0.00 AND expected_cashbox_eur <= 10000.00
            AND reception_cash_delivered_eur >= 0.00 AND reception_cash_delivered_eur <= 10.00
            AND (vault_counted_eur IS NULL OR (vault_counted_eur >= 0.00 AND vault_counted_eur <= 10000.00))
        ),
    -- D2 y C3: la aritmética del arqueo ciego no es una convención del servicio,
    -- es una identidad del registro. Con reseteo se parte de 0,00; con lectura
    -- previa se resta; en la primera histórica se resta la base declarada.
    CONSTRAINT chk_machine_cash_collections_counter_coherence
        CHECK (
            (
                is_counter_reset = TRUE
                AND initial_baseline_reading_eur = 0.00
                AND counter_reset_notes IS NOT NULL
                AND char_length(btrim(counter_reset_notes)) BETWEEN 20 AND 500
                AND btrim(counter_reset_notes) ~ '([[:alnum:]]+[^[:alnum:]]+){2,}[[:alnum:]]+'
                AND expected_cashbox_eur = ROUND(sales_counter_reading, 2)
            )
            OR (
                is_counter_reset = FALSE
                AND counter_reset_notes IS NULL
                AND (
                    (
                        previous_sales_counter_reading IS NOT NULL
                        AND initial_baseline_reading_eur = 0.00
                        AND sales_counter_reading >= previous_sales_counter_reading
                        AND expected_cashbox_eur = ROUND(sales_counter_reading - previous_sales_counter_reading, 2)
                    )
                    OR (
                        previous_sales_counter_reading IS NULL
                        AND sales_counter_reading >= initial_baseline_reading_eur
                        AND expected_cashbox_eur = ROUND(sales_counter_reading - initial_baseline_reading_eur, 2)
                    )
                )
            )
        ),
    -- D3: el efectivo de conserjería viaja con nombre de conserje; sin entrega,
    -- ni conserje ni expediente vinculado.
    CONSTRAINT chk_machine_cash_collections_reception_coherence
        CHECK (
            (
                reception_cash_delivered_eur = 0.00
                AND receptionist_name IS NULL
                AND linked_refund_request_id IS NULL
            )
            OR (
                reception_cash_delivered_eur > 0.00
                AND receptionist_name IS NOT NULL
                AND char_length(btrim(receptionist_name)) BETWEEN 3 AND 120
            )
        ),
    -- D4: cada estado declara exactamente sus marcas. El arqueo terminal exige
    -- la tolerancia de ±1,00 € y, por encima de ella, la justificación real del
    -- Art. V.1 (20 caracteres y tres bloques alfanuméricos).
    CONSTRAINT chk_machine_cash_collections_vault_lifecycle
        CHECK (
            (
                status = 'SEALED_IN_ROUTE'
                AND delivered_to_vault_at IS NULL
                AND delivered_to_vault_by IS NULL
                AND vault_counted_eur IS NULL
                AND discrepancy_eur IS NULL
                AND vault_reconciled_by IS NULL
                AND vault_reconciled_by_name_snapshot IS NULL
                AND vault_reconciled_at IS NULL
                AND discrepancy_justification IS NULL
            )
            OR (
                status = 'DELIVERED_TO_VAULT'
                AND delivered_to_vault_at IS NOT NULL
                AND delivered_to_vault_at >= collected_at
                AND delivered_to_vault_by IS NOT NULL
                AND vault_counted_eur IS NULL
                AND discrepancy_eur IS NULL
                AND vault_reconciled_by IS NULL
                AND vault_reconciled_by_name_snapshot IS NULL
                AND vault_reconciled_at IS NULL
                AND discrepancy_justification IS NULL
            )
            OR (
                status = 'RECONCILED_OK'
                AND delivered_to_vault_at IS NOT NULL
                AND delivered_to_vault_at >= collected_at
                AND delivered_to_vault_by IS NOT NULL
                AND vault_counted_eur IS NOT NULL
                AND discrepancy_eur = ROUND(vault_counted_eur - expected_cashbox_eur, 2)
                AND ABS(discrepancy_eur) <= 1.00
                AND vault_reconciled_by IS NOT NULL
                AND vault_reconciled_by_name_snapshot IS NOT NULL
                AND vault_reconciled_at IS NOT NULL
                AND vault_reconciled_at >= delivered_to_vault_at
                AND (
                    discrepancy_justification IS NULL
                    OR char_length(btrim(discrepancy_justification)) BETWEEN 3 AND 1000
                )
            )
            OR (
                status = 'DISCREPANCY_FLAGGED'
                AND delivered_to_vault_at IS NOT NULL
                AND delivered_to_vault_at >= collected_at
                AND delivered_to_vault_by IS NOT NULL
                AND vault_counted_eur IS NOT NULL
                AND discrepancy_eur = ROUND(vault_counted_eur - expected_cashbox_eur, 2)
                AND ABS(discrepancy_eur) > 1.00
                AND vault_reconciled_by IS NOT NULL
                AND vault_reconciled_by_name_snapshot IS NOT NULL
                AND vault_reconciled_at IS NOT NULL
                AND vault_reconciled_at >= delivered_to_vault_at
                AND discrepancy_justification IS NOT NULL
                AND char_length(btrim(discrepancy_justification)) BETWEEN 20 AND 1000
                AND btrim(discrepancy_justification) ~ '([[:alnum:]]+[^[:alnum:]]+){2,}[[:alnum:]]+'
            )
        )
);

-- D1: a lo sumo una operación de recaudación vinculada por visita de reposición.
CREATE UNIQUE INDEX IF NOT EXISTS uq_machine_cash_collections_visit
    ON machine_cash_collections (restock_visit_id)
    WHERE restock_visit_id IS NOT NULL;

-- D3: un expediente de reintegro de conserjería solo se liquida una vez.
CREATE UNIQUE INDEX IF NOT EXISTS uq_machine_cash_collections_refund
    ON machine_cash_collections (linked_refund_request_id)
    WHERE linked_refund_request_id IS NOT NULL;

-- D2: la última lectura del contador de una máquina se resuelve por este índice
-- con `ORDER BY collected_at DESC, id DESC`; el libro de Coordinación filtra por
-- reponedor y estado.
CREATE INDEX IF NOT EXISTS idx_machine_cash_collections_machine_collected
    ON machine_cash_collections (machine_id, collected_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_machine_cash_collections_restocker_collected
    ON machine_cash_collections (restocker_id, collected_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_machine_cash_collections_status_collected
    ON machine_cash_collections (status, collected_at DESC);


-- ----------------------------------------------------------------------------
-- 3. Blindaje constitucional (Art. II.2, Art. III.1, Art. III.3)
-- ----------------------------------------------------------------------------

-- 3.1 · Prohibición de borrado físico (Art. III.1)
DROP TRIGGER IF EXISTS trg_no_delete_machine_cash_collections ON machine_cash_collections;
CREATE TRIGGER trg_no_delete_machine_cash_collections
    BEFORE DELETE ON machine_cash_collections
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

-- 3.2 · Marca temporal de actualización
DROP TRIGGER IF EXISTS trg_touch_machine_cash_collections ON machine_cash_collections;
CREATE TRIGGER trg_touch_machine_cash_collections
    BEFORE UPDATE ON machine_cash_collections
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- 3.3 · Inmutabilidad de la captura en ruta y bloqueo terminal (Art. III.3, D4)
-- El arqueo terminal no se reescribe: una corrección es otra recaudación. Y lo
-- declarado en ruta —precinto, lecturas, cambio, entrega, notas e idempotencia—
-- queda congelado desde el `INSERT`, incluso antes del arqueo.
CREATE OR REPLACE FUNCTION enforce_machine_cash_collections_immutability()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.status IN ('RECONCILED_OK', 'DISCREPANCY_FLAGGED') THEN
        RAISE EXCEPTION 'ART_III_3_CASH_COLLECTION_IMMUTABLE: La recaudación % ya fue arqueada en Caja Central (%) y es inmutable.', OLD.collection_code, OLD.status
            USING ERRCODE = '45000';
    END IF;

    IF NEW.id IS DISTINCT FROM OLD.id
       OR NEW.collection_code IS DISTINCT FROM OLD.collection_code
       OR NEW.restock_visit_id IS DISTINCT FROM OLD.restock_visit_id
       OR NEW.machine_id IS DISTINCT FROM OLD.machine_id
       OR NEW.machine_code_snapshot IS DISTINCT FROM OLD.machine_code_snapshot
       OR NEW.restocker_id IS DISTINCT FROM OLD.restocker_id
       OR NEW.restocker_name_snapshot IS DISTINCT FROM OLD.restocker_name_snapshot
       OR NEW.restocker_code_snapshot IS DISTINCT FROM OLD.restocker_code_snapshot
       OR NEW.bag_seal_code IS DISTINCT FROM OLD.bag_seal_code
       OR NEW.change_tubes_loaded_eur IS DISTINCT FROM OLD.change_tubes_loaded_eur
       OR NEW.sales_counter_reading IS DISTINCT FROM OLD.sales_counter_reading
       OR NEW.previous_sales_counter_reading IS DISTINCT FROM OLD.previous_sales_counter_reading
       OR NEW.initial_baseline_reading_eur IS DISTINCT FROM OLD.initial_baseline_reading_eur
       OR NEW.is_counter_reset IS DISTINCT FROM OLD.is_counter_reset
       OR NEW.counter_reset_notes IS DISTINCT FROM OLD.counter_reset_notes
       OR NEW.expected_cashbox_eur IS DISTINCT FROM OLD.expected_cashbox_eur
       OR NEW.reception_cash_delivered_eur IS DISTINCT FROM OLD.reception_cash_delivered_eur
       OR NEW.receptionist_name IS DISTINCT FROM OLD.receptionist_name
       OR NEW.linked_refund_request_id IS DISTINCT FROM OLD.linked_refund_request_id
       OR NEW.route_notes IS DISTINCT FROM OLD.route_notes
       OR NEW.collected_at IS DISTINCT FROM OLD.collected_at
       OR NEW.client_mutation_id IS DISTINCT FROM OLD.client_mutation_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'ART_III_3_ROUTE_CAPTURE_IMMUTABLE: Los datos declarados en ruta de la bolsa % son inmutables tras su sellado.', OLD.bag_seal_code
            USING ERRCODE = '45000';
    END IF;

    IF OLD.status = 'DELIVERED_TO_VAULT' AND NEW.status = 'SEALED_IN_ROUTE' THEN
        RAISE EXCEPTION 'ART_III_3_INVALID_CASH_STATE_REGRESSION: Una bolsa entregada en Caja Central no puede volver a estado SEALED_IN_ROUTE.'
            USING ERRCODE = '45000';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_guard_machine_cash_collections ON machine_cash_collections;
CREATE TRIGGER trg_guard_machine_cash_collections
    BEFORE UPDATE ON machine_cash_collections
    FOR EACH ROW EXECUTE FUNCTION enforce_machine_cash_collections_immutability();

-- 3.4 · Guarda de primacía sanitaria y de conserjería (Art. II.2, C4, D3, RNF-02)
-- La emergencia alimentaria prevalece sobre la recaudación: ni cuarentena, ni
-- máquina fuera de servicio, ni ticket de frío vivo, ni visita abortada por
-- rotura térmica admiten un corte de bolsa. Y una sede sin recepción física no
-- puede recibir efectivo de conserjería.
CREATE OR REPLACE FUNCTION enforce_cash_collection_sanitary_guard()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_machine_code          VARCHAR(32);
    v_sanitary_status       sanitary_status;
    v_blocked_no_access     BOOLEAN;
    v_has_physical_reception BOOLEAN;
    v_thermal_incident      BOOLEAN;
    v_visit_status          restock_visit_status;
    v_visit_machine_id      BIGINT;
BEGIN
    SELECT m.code, m.sanitary_status, m.is_blocked_no_access, l.has_physical_reception
      INTO v_machine_code, v_sanitary_status, v_blocked_no_access, v_has_physical_reception
      FROM machines m
      INNER JOIN locations l ON l.id = m.location_id
     WHERE m.id = NEW.machine_id;

    IF v_sanitary_status = 'QUARANTINE' THEN
        RAISE EXCEPTION 'ART_II_SANITARY_CASH_COLLECTION_BLOCKED: La máquina % está en cuarentena sanitaria y no admite recaudación.', v_machine_code
            USING ERRCODE = '45000';
    END IF;

    IF COALESCE(v_blocked_no_access, FALSE) THEN
        RAISE EXCEPTION 'ART_II_SANITARY_CASH_COLLECTION_BLOCKED: La máquina % está fuera de servicio y no admite recaudación.', v_machine_code
            USING ERRCODE = '45000';
    END IF;

    SELECT EXISTS (
        SELECT 1
          FROM incidents i
         WHERE i.machine_id = NEW.machine_id
           AND i.category = 'TEMPERATURE_COLD'
           AND i.status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')
    ) INTO v_thermal_incident;

    IF v_thermal_incident THEN
        RAISE EXCEPTION 'ART_II_SANITARY_CASH_COLLECTION_BLOCKED: La máquina % tiene una avería térmica abierta y no admite recaudación.', v_machine_code
            USING ERRCODE = '45000';
    END IF;

    IF NEW.restock_visit_id IS NOT NULL THEN
        SELECT v.status, v.machine_id
          INTO v_visit_status, v_visit_machine_id
          FROM machine_restock_visits v
         WHERE v.id = NEW.restock_visit_id;

        IF v_visit_status = 'ABORTED_THERMAL_BREACH' THEN
            RAISE EXCEPTION 'ART_II_THERMAL_ABORT_CASH_FORBIDDEN: La visita % está abortada por rotura de cadena de frío y no admite recaudación.', NEW.restock_visit_id
                USING ERRCODE = '45000';
        END IF;

        IF v_visit_machine_id IS NOT NULL AND v_visit_machine_id <> NEW.machine_id THEN
            RAISE EXCEPTION 'ART_II_CASH_COLLECTION_VISIT_MACHINE_MISMATCH: La visita % no pertenece a la máquina %.', NEW.restock_visit_id, v_machine_code
                USING ERRCODE = '45000';
        END IF;
    END IF;

    IF NEW.reception_cash_delivered_eur > 0.00 AND COALESCE(v_has_physical_reception, FALSE) = FALSE THEN
        RAISE EXCEPTION 'LOCATION_HAS_NO_PHYSICAL_RECEPTION: La sede de la máquina % no tiene conserjería física y no admite entrega de efectivo.', v_machine_code
            USING ERRCODE = '45000';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_enforce_cash_collection_sanitary_guard ON machine_cash_collections;
CREATE TRIGGER trg_enforce_cash_collection_sanitary_guard
    BEFORE INSERT ON machine_cash_collections
    FOR EACH ROW EXECUTE FUNCTION enforce_cash_collection_sanitary_guard();
