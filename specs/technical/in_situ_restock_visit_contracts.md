# CONTRATOS TÉCNICOS: VISITA DE REPOSICIÓN IN SITU, CONTROL FEFO DE CADUCIDADES Y LIMPIEZA DIARIA (`IN-SITU RESTOCK VISIT, FEFO EXPIRY CONTROL & DAILY HYGIENE`)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)\
**Módulo:** `34-in-situ-restock-visit` (Feature 24 de `docs/features_pendientes.md`)\
**Documento Canónico:** `specs/technical/in_situ_restock_visit_contracts.md`\
**Referencia Funcional:** [`specs/34-in-situ-restock-visit/spec.md`](../34-in-situ-restock-visit/spec.md) y [`specs/functional/in_situ_restock_visit_spec.md`](../functional/in_situ_restock_visit_spec.md) (v2.0 · `RF-01` a `RF-05`, `D1`–`D4`, `C1`–`C4`, `QA 1`–`QA 4`)\
**Motor de Persistencia:** PostgreSQL 16+ (Supabase / Motor Local de Certificación) · Motor Único\
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`) y `multipart/form-data` para subida de fotografía\
**Conformidad Constitucional:** Artículos I, II, III (3.1, 3.2, 3.3), IV, V (5.1, 5.2, 5.4, 5.5) y VI

---

## 1. Visión General de Endpoints y Superficies del Módulo

| Método | Ruta | Rol / Middleware | Propósito Operativo |
| :--- | :--- | :--- | :--- |
| **Superficie Móvil del Reponedor (`RF-02`, `RF-03`, `RF-04`, `RF-05.1`, Art. II, Art. V.4, Art. V.5)** | | | |
| `GET` | `/api/restocker/my-route` | `InternalAuthMiddleware(RESTOCKER)` | Extiende el bootstrap móvil del reponedor incluyendo en `machines` los contadores FEFO de carril (`expired_slots_count`, `expiring_soon_slots_count`, `can_restock`), en `active_loading_order` las cantidades sugeridas `suggested_restocked_in_machines_qty` y `suggested_withdrawn_spoilage_qty` (`C4`), y `recent_visits` sin `unit_cost_snapshot_eur` ni `commercial_spoilage_loss_eur` (`Art. V.4`). |
| `GET` | `/api/restocker/machines/{machineId}/planogram` | `InternalAuthMiddleware(RESTOCKER)` | Devuelve el planograma de la máquina (dentro de sus zonas cubiertas hoy, `403 MACHINE_OUTSIDE_RESTOCKER_ZONES`) enriquecido con `current_batch_code`, `current_expiration_date`, `slot_expiry_status` (`EXPIRED`, `EXPIRING_SOON`, `OK`), flag `fefo_withdrawal_required`, estado sanitario de la máquina y lotes compatibles disponibles en la furgoneta del reponedor ordenados por FEFO, sin costes (`Art. V.4`). |
| `POST` | `/api/restocker/visits/photo` | `InternalAuthMiddleware(RESTOCKER)` | Sube una fotografía de evidencia de visita in situ (`multipart/form-data` o base64 seguro) con tope de $5\text{ MB}$ y verificación de cabecera mágica real (`.jpg`, `.jpeg`, `.png`, `.webp` según `Art. V.5`), devolviendo `photo_evidence_url`. |
| `POST` | `/api/restocker/machines/{machineId}/visits` | `InternalAuthMiddleware(RESTOCKER)` | Registra de forma atómica e idempotente (`client_mutation_id`) una visita de reposición in situ (`COMPLETED` o `ABORTED_THERMAL_BREACH`), aplicando el Triple Cerrojo Sanitario (`Art. II`, `D2`, `QA 1`, `QA 2`), validando el checklist diario de higiene (`D3`), descontando atómicamente de `restocker_van_product_stocks`, actualizando `machine_planogram_slots` (`D1`) y congelando internamente el snapshot de coste (`QA 3`, oculto en la respuesta del reponedor por `Art. V.4`). |
| `GET` | `/api/restocker/visits` | `InternalAuthMiddleware(RESTOCKER)` | Lista las visitas de reposición realizadas por el propio reponedor autenticado (con filtro opcional `?machine_id=` y `?status=`), omitiendo cualquier campo de coste económico (`Art. V.4`). |
| `POST` | `/api/restocker/visits/conflict-dump` | `InternalAuthMiddleware(RESTOCKER)` | Registra en `audit_log` (`OFFLINE_RESTOCK_VISIT_CONFLICT_DUMP`, `Art. III.3`) el volcado de una visita encolada offline que fue rechazada con `409` al sincronizar (`C3`). |
| **Supervisión de Visitas, Control FEFO y Mermas Comerciales en Coordinación (`RF-03.4`, `RF-05.2`, `D4`, `C4`, `QA 3`, Art. V.4)** | | | |
| `GET` | `/api/coordinator/restock-visits` | `InternalAuthMiddleware(COORDINATOR)` | Lista el libro histórico de visitas de reposición in situ con 5 KPIs ejecutivos (`completed_visits_count`, `aborted_thermal_visits_count`, `total_units_added`, `total_units_withdrawn`, `total_commercial_spoilage_loss_eur`), filtros `?restocker_id=`, `?machine_id=`, `?status=COMPLETED\|ABORTED_THERMAL_BREACH\|ALL`, `?date_from=` y `?date_to=`. |
| `GET` | `/api/coordinator/restock-visits/{id}` | `InternalAuthMiddleware(COORDINATOR)` | Devuelve el expediente íntegro de una visita de reposición (cabecera, checklist diario de higiene, lectura térmica, foto, ticket SAT vinculado y líneas carril a carril incluyendo `unit_cost_snapshot_eur` y `commercial_spoilage_loss_eur`, `QA 3`). |
| `GET` | `/api/coordinator/product-loading-orders/{id}` | `InternalAuthMiddleware(COORDINATOR)` | Extiende el detalle de la orden de carga del Módulo 33 exponiendo en cada línea `suggested_restocked_in_machines_qty` y `suggested_withdrawn_spoilage_qty` calculados desde las visitas reales del turno (`C4`). |

---

## 2. Contrato de Esquema de Base de Datos (PostgreSQL 16+ / Supabase)

La persistencia del Módulo 34 se materializa en la migración incremental `database/supabase_migrations/030_in_situ_restock_visit_and_fefo.sql` y converge 1:1 en `database/supabase_init.sql`, ampliando la Tabla 43 (`machine_planogram_slots`), elevando el esquema de **46 a 48 tablas** (`machine_restock_visits` Tabla 47 y `machine_restock_visit_items` Tabla 48) y declarando **41 tipos `ENUM` nativos** en total (nuevo tipo #31 `restock_visit_status`).

### 2.1. Nuevo Tipo Enumerado y Ampliación de `audit_entity_type` y `machine_planogram_slots`

```sql
-- Estado final inmutable de la visita de reposición in situ (RF-01.2, D2, D4)
DO $do$
BEGIN
    CREATE TYPE restock_visit_status AS ENUM (
        'COMPLETED',
        'ABORTED_THERMAL_BREACH'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Entidad de auditoría para visitas de reposición in situ (Art. III.3)
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'MACHINE_RESTOCK_VISIT';

-- Evolución de Tabla 43 · machine_planogram_slots con lote y caducidad vigentes (RF-01.1, D1)
ALTER TABLE machine_planogram_slots
    ADD COLUMN IF NOT EXISTS current_batch_code VARCHAR(40),
    ADD COLUMN IF NOT EXISTS current_expiration_date DATE;

ALTER TABLE machine_planogram_slots
    DROP CONSTRAINT IF EXISTS chk_machine_planogram_current_batch_format;

ALTER TABLE machine_planogram_slots
    ADD CONSTRAINT chk_machine_planogram_current_batch_format
    CHECK (current_batch_code IS NULL OR current_batch_code ~ '^[A-Z0-9_-]{2,40}$');
```

### 2.2. Definición DDL de Tabla 47 · `machine_restock_visits` (`RF-01.2`, `D2`, `D3`, `D4`, `C1`, Art. II, Art. III.1, Art. III.3)

```sql
CREATE TABLE IF NOT EXISTS machine_restock_visits (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY,
    visit_code                  VARCHAR(32) NOT NULL,
    machine_id                  BIGINT NOT NULL,
    restocker_id                BIGINT NOT NULL,
    loading_order_id            BIGINT,
    route_schedule_id           BIGINT,
    status                      restock_visit_status NOT NULL DEFAULT 'COMPLETED',
    started_at                  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at                TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cabin_temp_celsius          NUMERIC(4, 1),
    hygiene_checklist_json      JSONB NOT NULL DEFAULT '{}'::jsonb,
    precautionary_seal_applied  BOOLEAN NOT NULL DEFAULT FALSE,
    photo_evidence_url          VARCHAR(255),
    internal_notes              VARCHAR(500),
    triggered_incident_id       BIGINT,
    client_mutation_id          VARCHAR(64) NOT NULL,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_machine_restock_visits PRIMARY KEY (id),
    CONSTRAINT uq_machine_restock_visits_code UNIQUE (visit_code),
    CONSTRAINT uq_machine_restock_visits_mutation UNIQUE (restocker_id, client_mutation_id),
    CONSTRAINT fk_machine_restock_visits_machine
        FOREIGN KEY (machine_id) REFERENCES machines (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_restock_visits_restocker
        FOREIGN KEY (restocker_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_restock_visits_loading_order
        FOREIGN KEY (loading_order_id) REFERENCES product_loading_orders (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_restock_visits_triggered_incident
        FOREIGN KEY (triggered_incident_id) REFERENCES incidents (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_machine_restock_visits_code_format
        CHECK (visit_code ~ '^MRV-[0-9]{4}-[0-9]{4,}$'),
    CONSTRAINT chk_machine_restock_visits_mutation_format
        CHECK (char_length(btrim(client_mutation_id)) >= 6),
    CONSTRAINT chk_machine_restock_visits_time_order
        CHECK (completed_at >= started_at),
    CONSTRAINT chk_machine_restock_visits_temp_range
        CHECK (cabin_temp_celsius IS NULL OR (cabin_temp_celsius >= -5.0 AND cabin_temp_celsius <= 35.0)),
    CONSTRAINT chk_machine_restock_visits_thermal_abort_coherence
        CHECK (
            status <> 'ABORTED_THERMAL_BREACH'
            OR (
                precautionary_seal_applied = TRUE
                AND cabin_temp_celsius IS NOT NULL
                AND photo_evidence_url IS NOT NULL
                AND char_length(btrim(photo_evidence_url)) > 0
                AND internal_notes IS NOT NULL
                AND char_length(btrim(internal_notes)) >= 20
            )
        )
);

CREATE INDEX IF NOT EXISTS idx_machine_restock_visits_machine_created
    ON machine_restock_visits (machine_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_machine_restock_visits_restocker_created
    ON machine_restock_visits (restocker_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_machine_restock_visits_loading_order
    ON machine_restock_visits (loading_order_id)
    WHERE loading_order_id IS NOT NULL;
```

### 2.3. Definición DDL de Tabla 48 · `machine_restock_visit_items` (`RF-01.2`, `D1`, `C1`, `QA 3`, Art. III.1, Art. III.3)

```sql
CREATE TABLE IF NOT EXISTS machine_restock_visit_items (
    id                              BIGINT GENERATED ALWAYS AS IDENTITY,
    visit_id                        BIGINT NOT NULL,
    slot_id                         BIGINT NOT NULL,
    slot_code                       VARCHAR(24) NOT NULL,
    product_id                      BIGINT NOT NULL,
    qty_before                      INTEGER NOT NULL DEFAULT 0,
    qty_withdrawn_expired           INTEGER NOT NULL DEFAULT 0,
    qty_withdrawn_damaged           INTEGER NOT NULL DEFAULT 0,
    qty_added                       INTEGER NOT NULL DEFAULT 0,
    qty_after                       INTEGER NOT NULL DEFAULT 0,
    withdrawn_batch_code            VARCHAR(40),
    withdrawn_expiration_date       DATE,
    loaded_batch_code               VARCHAR(40),
    loaded_expiration_date          DATE,
    unit_cost_snapshot_eur          NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    commercial_spoilage_loss_eur    NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    created_at                      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_machine_restock_visit_items PRIMARY KEY (id),
    CONSTRAINT uq_machine_restock_visit_item_slot UNIQUE (visit_id, slot_code),
    CONSTRAINT fk_machine_restock_visit_items_visit
        FOREIGN KEY (visit_id) REFERENCES machine_restock_visits (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_restock_visit_items_slot
        FOREIGN KEY (slot_id) REFERENCES machine_planogram_slots (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_machine_restock_visit_items_product
        FOREIGN KEY (product_id) REFERENCES vending_products (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_machine_restock_visit_items_qty_bounds
        CHECK (
            qty_before >= 0 AND qty_before <= 10000
            AND qty_withdrawn_expired >= 0 AND qty_withdrawn_expired <= 10000
            AND qty_withdrawn_damaged >= 0 AND qty_withdrawn_damaged <= 10000
            AND qty_added >= 0 AND qty_added <= 10000
            AND qty_withdrawn_expired + qty_withdrawn_damaged <= qty_before
            AND qty_after = qty_before - qty_withdrawn_expired - qty_withdrawn_damaged + qty_added
            AND qty_after >= 0 AND qty_after <= 10000
        ),
    CONSTRAINT chk_machine_restock_visit_items_batch_formats
        CHECK (
            (withdrawn_batch_code IS NULL OR withdrawn_batch_code ~ '^[A-Z0-9_-]{2,40}$')
            AND (loaded_batch_code IS NULL OR loaded_batch_code ~ '^[A-Z0-9_-]{2,40}$')
            AND (qty_added = 0 OR loaded_batch_code IS NOT NULL)
        ),
    CONSTRAINT chk_machine_restock_visit_items_cost_coherence
        CHECK (
            unit_cost_snapshot_eur >= 0.00 AND unit_cost_snapshot_eur <= 9999.99
            AND commercial_spoilage_loss_eur = ROUND((qty_withdrawn_expired + qty_withdrawn_damaged) * unit_cost_snapshot_eur, 2)
        )
);

CREATE INDEX IF NOT EXISTS idx_machine_restock_visit_items_visit
    ON machine_restock_visit_items (visit_id);

CREATE INDEX IF NOT EXISTS idx_machine_restock_visit_items_product_batch
    ON machine_restock_visit_items (product_id, loaded_batch_code);
```

### 2.4. Disparadores Constitucionales (`Art. II`, `Art. III.1` y `Art. III.3`)

1. **Prohibición de borrado físico (`Art. III.1`):**
   * `trg_no_delete_machine_restock_visits` (`BEFORE DELETE ON machine_restock_visits EXECUTE FUNCTION prevent_hard_delete()`)
   * `trg_no_delete_machine_restock_visit_items` (`BEFORE DELETE ON machine_restock_visit_items EXECUTE FUNCTION prevent_hard_delete()`)
2. **Inmutabilidad histórica absoluta frente a `UPDATE` (`Art. III.3`, `C1`):**
   * `prevent_restock_visit_mutation()` y disparadores `trg_immutable_machine_restock_visits` (`BEFORE UPDATE ON machine_restock_visits`) y `trg_immutable_machine_restock_visit_items` (`BEFORE UPDATE ON machine_restock_visit_items`): rechazan cualquier `UPDATE` con `RAISE EXCEPTION 'ART_III_3_IMMUTABLE_VISIT_VIOLATION: ...' USING ERRCODE = '45000'`.
3. **Defensa en profundidad del Cerrojo Térmico y de Estado de Máquina en PostgreSQL (`Art. II`, `RNF-02`, `D2`, `QA 4`):**
   * `enforce_restock_visit_sanitary_guard()` (`BEFORE INSERT ON machine_restock_visits`):
     * Consulta `machines.machine_type` de `NEW.machine_id`.
     * Si `machine_type IN ('PERISHABLE_FOOD', 'COMBO', 'COLD_DRINKS')` y `NEW.cabin_temp_celsius IS NULL`, lanza `RAISE EXCEPTION 'ART_II_CABIN_TEMP_REQUIRED: ...' USING ERRCODE = '45000'`.
     * Si `NEW.status = 'COMPLETED'` y ((`machine_type IN ('PERISHABLE_FOOD', 'COMBO')` y `NEW.cabin_temp_celsius > 4.0`) o (`machine_type = 'COLD_DRINKS'` y `NEW.cabin_temp_celsius > 8.0`)), lanza `RAISE EXCEPTION 'ART_II_CABIN_TEMP_EXCEEDED: ...' USING ERRCODE = '45000'`.
   * `enforce_restock_visit_item_guard()` (`BEFORE INSERT ON machine_restock_visit_items`):
     * Si la visita padre tiene `status = 'ABORTED_THERMAL_BREACH'` y `NEW.qty_added > 0`, lanza `RAISE EXCEPTION 'ART_II_THERMAL_ABORT_ADD_FORBIDDEN: ...' USING ERRCODE = '45000'`.

---

## 3. Catálogo de Códigos de Error de Dominio y HTTP

| Código de Error | HTTP | Condición de Disparo |
| :--- | :---: | :--- |
| `MACHINE_OUTSIDE_RESTOCKER_ZONES` | `403` | El reponedor intenta consultar o registrar una visita en una máquina fuera de sus Zonas Operativas cubiertas hoy (`C2`, `Art. V.4`). |
| `MACHINE_NOT_FOUND` | `404` | La máquina indicada no existe o está inactiva (`machines.is_active = FALSE`). |
| `RESTOCK_VISIT_NOT_FOUND` | `404` | No existe la visita de reposición solicitada. |
| `SANITARY_RESTOCK_BLOCKED` | `409` | La máquina está en `SANITARY_QUARANTINE`, `OUT_OF_SERVICE` o tiene una avería abierta de tipo `THERMAL_BREAKDOWN` (`RF-02.1`, `D2`, `Art. II`). |
| `OFFLINE_VISIT_CONFLICT` | `409` | Al sincronizar una visita offline diferida, la máquina o el saldo real en furgoneta cambiaron impidiendo su aplicación (`C3`, `RF-04.2`). |
| `INVALID_CABIN_TEMPERATURE` | `422` | Falta `cabin_temp_celsius` en una máquina refrigerada (`PERISHABLE_FOOD`, `COMBO`, `COLD_DRINKS`) o el valor está fuera de `[-5.0, 35.0] °C` (`QA 4`, `Art. II`). |
| `CABIN_TEMP_EXCEEDS_SANITARY_LIMIT` | `422` | En una visita enviada como `COMPLETED` (o con `qty_added > 0`), `cabin_temp_celsius > 4.0 °C` en `PERISHABLE_FOOD`/`COMBO` o `> 8.0 °C` en `COLD_DRINKS` (`RF-02.2`, `D2`, `Art. II`). |
| `PRECAUTIONARY_SEAL_REQUIRED` | `422` | En una visita `ABORTED_THERMAL_BREACH`, `precautionary_seal_applied` no es `true` (`RF-02.2`, `D3`, `Art. II`). |
| `FEFO_WITHDRAWAL_REQUIRED` | `422` | Un carril tiene lote caducado (`<= CURRENT_DATE`) sin retirar en su totalidad, o tiene lote próximo a vencer (`<= CURRENT_DATE + 2`) y se intenta añadir producto (`qty_added > 0`) sin retirar antes todo el remanente (`RF-02.3`, `QA 1`, `Art. II`). |
| `EXPIRED_VAN_BATCH_FORBIDDEN` | `422` | Se intenta cargar desde la furgoneta un lote con `expiration_date <= CURRENT_DATE` (`RF-02.3`, `QA 1`, `Art. II`). |
| `FEFO_OLDER_VAN_BATCH_AVAILABLE` | `422` | Se intenta cargar un lote de furgoneta teniendo otro lote sano del mismo producto con `expiration_date` anterior aún con saldo disponible (`RF-02.3`, `QA 1`, `Art. II`). |
| `INSUFFICIENT_VAN_BATCH_STOCK` | `422` | La suma de `qty_added` para `(product_id, loaded_batch_code)` supera `quantity_available` en `restocker_van_product_stocks` (`RF-02.3`). |
| `SLOT_CAPACITY_OR_WITHDRAWAL_INVALID` | `422` | En algún carril `qty_withdrawn_expired + qty_withdrawn_damaged > qty_before`, o `qty_after > max_capacity`, o el carril no pertenece al planograma activo de la máquina (`RF-03.3`, `C1`). |
| `INCOMPLETE_DAILY_HYGIENE_CHECKLIST` | `422` | En una visita `COMPLETED`, falta marcar en `true` alguno de los ítems obligatorios del checklist de higiene según `MachineType` (`RF-03.1`, `D3`). |
| `PHOTO_EVIDENCE_REQUIRED` | `422` | Falta `photo_evidence_url` válido cuando `status = 'ABORTED_THERMAL_BREACH'` o cuando existen unidades retiradas por caducidad/daño (`RF-03.2`, `D3`, `Art. V.5`). |
| `INVALID_JUSTIFICATION_LENGTH` | `422` | `internal_notes` tiene menos de 20 caracteres reales (`>= 3` palabras/bloques) cuando hay aborto térmico o retirada de mermas (`RF-03.2`, `QA 4`, `Art. V.1`). |
