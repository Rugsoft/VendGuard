# CONTRATOS TÉCNICOS: RECAUDACIÓN DE EFECTIVO, CARGA DE TUBOS DE CAMBIO Y ARQUEO DE CAJA (`CASH COLLECTION, COIN CHANGER REFILL & VAULT AUDIT`)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)\
**Módulo:** `35-cash-collection-vault-audit` (Feature 25 de `docs/features_pendientes.md`)\
**Documento Canónico:** `specs/technical/cash_collection_vault_audit_contracts.md`\
**Referencia Funcional:** [`specs/35-cash-collection-vault-audit/spec.md`](../35-cash-collection-vault-audit/spec.md) y [`specs/functional/cash_collection_vault_audit_spec.md`](../functional/cash_collection_vault_audit_spec.md) (v2.1 · `RF-01` a `RF-05`, `D1`–`D4`, `C1`–`C4`, 4 resoluciones QA agregadas `QA 1`–`QA 4`)\
**Motor de Persistencia:** PostgreSQL 16+ (Supabase / Motor Local de Certificación) · Motor Único\
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`) y `text/csv; charset=utf-8` (con UTF-8 BOM para exportación de Coordinación)\
**Conformidad Constitucional:** Artículos I, II (2.1, 2.2), III (3.1, 3.2, 3.3), IV, V (5.1, 5.4) y VI

---

## 1. Visión General de Endpoints y Superficies del Módulo

| Método | Ruta | Rol / Middleware | Propósito Operativo |
| :--- | :--- | :--- | :--- |
| **Superficie Móvil del Reponedor (`RF-01`, `RF-02`, `RF-03`, `RF-05.1`, `RF-05.2`, Art. II, Art. V.4)** | | | |
| `GET` | `/api/restocker/my-route` | `InternalAuthMiddleware(RESTOCKER)` | Extiende el bootstrap móvil del reponedor añadiendo en cada máquina de `machines` el flag `has_physical_reception` de su sede, el flag `has_prior_cash_collection` (booleano sin exponer jamás `previous_sales_counter_reading`, `C2`), los reintegros elegibles para entrega en conserjería (`pending_reception_refunds`, con `compensation_method = 'EN_MANO_SEDE'` y sin exponer `pickup_pin` ni PII de contacto, `D3`, `Art. V.4`), y en la raíz `recent_cash_collections` y `cash_route_summary` omitiendo `previous_sales_counter_reading`, `expected_cashbox_eur`, `vault_counted_eur`, `discrepancy_eur` y `discrepancy_justification` (`C2`, `Art. V.4`). |
| `POST` | `/api/restocker/machines/{machineId}/visits` | `InternalAuthMiddleware(RESTOCKER)` | Extiende el alta de Visita de Reposición del Módulo 34 aceptando un objeto opcional `cash_collection` cuando `status = 'COMPLETED'` (`D1`). Si se incluye en una visita `ABORTED_THERMAL_BREACH`, rechaza con `422 THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN` (`C4`, `Art. II.2`). La recaudación integrada exige que la visita pertenezca al mismo reponedor (`403 RESTOCK_VISIT_NOT_OWNED`) y que esté en `status = 'COMPLETED'` (`422 RESTOCK_VISIT_NOT_COMPLETED`). |
| `POST` | `/api/restocker/machines/{machineId}/cash-collections` | `InternalAuthMiddleware(RESTOCKER)` | Registra de forma atómica e idempotente (`client_mutation_id`) una operación independiente de recaudación en bolsa precintada (`bag_seal_code` obligatorio), carga de tubos de cambio y/o entrega en conserjería (`restock_visit_id = NULL`, `D1`), bloqueando primero `machines` con `SELECT ... FOR UPDATE`, calculando en privado `expected_cashbox_eur` (`D2`) y transicionando el expediente `linked_refund_request_id` a `DEPOSITED_AT_RECEPTION` (`technician_finding = 'FOUND_PHYSICAL'`) si aplica (`D3`). Rechaza con `422 RESTOCK_VISIT_NOT_ALLOWED` cualquier `restock_visit_id` en el payload: el vínculo con la visita solo se declara al crear la visita de reposición (`D1`). Si la comprobación previa y el `INSERT` compiten, una mutación duplicada devuelve la fila ganadora con `idempotent_replay = true` y un precinto duplicado responde `409 BAG_SEAL_CODE_ALREADY_USED`, sin escapar el `23505` como 500. |
| `GET` | `/api/restocker/cash-collections` | `InternalAuthMiddleware(RESTOCKER)` | Lista el historial de bolsas precintadas y operaciones de cambio registradas por el propio reponedor autenticado (filtros opcionales `?machine_id=` y `?status=`), proyectado con `RestockerCashCollectionViewDTO` (Arqueo Ciego: sin `previous_sales_counter_reading`, `expected_cashbox_eur`, `vault_counted_eur`, `discrepancy_eur` ni `discrepancy_justification`, `C2`, `Art. V.4`). |
| `GET` | `/api/restocker/cash-collections/{id}/reception-receipt` | `InternalAuthMiddleware(RESTOCKER)` | Devuelve el comprobante limpio de entrega de efectivo en conserjería ($\le 10,00\,\text{€}$, `QA 2`), sin incluir jamás datos de la bolsa precintada, de la hucha, datos personales del reclamante ni `pickup_pin` (`Art. V.4`). |
| `POST` | `/api/restocker/cash-collections/conflict-dump` | `InternalAuthMiddleware(RESTOCKER)` | Registra en `audit_log` (`OFFLINE_CASH_COLLECTION_CONFLICT_DUMP`, `Art. III.3`) el volcado de una recaudación offline rechazada con `409` al sincronizar (`C4`). |
| **Supervisión de Tesorería y Arqueo en Caja Central en Coordinación (`RF-04`, `RF-05.3`, `D2`, `D4`, `QA 3`, `QA 4`, Art. V.1, Art. V.4)** | | | |
| `GET` | `/api/coordinator/cash-collections` | `InternalAuthMiddleware(COORDINATOR)` | Lista el libro completo de recaudaciones y bolsas precintadas con **6 KPIs financieros reactivos al filtro activo** (`pending_vault_bags_count`, `reconciled_ok_count`, `discrepancy_flagged_count`, `total_vault_counted_eur`, `net_discrepancy_eur`, `total_route_cash_deployed_eur`) y filtros `?restocker_id=`, `?machine_id=`, `?status=`, `?bag_seal_code=`, `?date_from=` y `?date_to=`. |
| `GET` | `/api/coordinator/cash-collections/export-csv` | `InternalAuthMiddleware(COORDINATOR)` | Exporta el listado filtrado en formato CSV con UTF-8 BOM, separador `;`, zona horaria `Europe/Madrid` y neutralización contra inyección de fórmulas (`QA 4`). La columna `Estado` lleva la etiqueta legible del ciclo de custodia (la misma que la consola, `CashCollectionStatus::label()`) y la columna final `Código de estado` conserva el valor técnico del `ENUM`. Sin tope de página: entrega todas las filas del filtro activo, no solo la primera página de la consola. |
| `GET` | `/api/coordinator/cash-collections/{id}` | `InternalAuthMiddleware(COORDINATOR)` | Devuelve el expediente completo de una recaudación (`CoordinatorCashCollectionViewDTO`), incluyendo `previous_sales_counter_reading`, `expected_cashbox_eur`, `vault_counted_eur`, `discrepancy_eur`, `discrepancy_justification`, vínculo a la visita del Módulo 34 y al reintegro del Módulo 08. |
| `POST` | `/api/coordinator/cash-collections/{id}/deliver-to-vault` | `InternalAuthMiddleware(COORDINATOR)` | Registra la recepción física de la bolsa precintada intacta en Caja Central (`SEALED_IN_ROUTE` $\rightarrow$ `DELIVERED_TO_VAULT`, `RF-04.1`, `D4`), permitido incluso si la máquina o el reponedor pasaron a `is_active = FALSE` después del sellado en ruta. |
| `POST` | `/api/coordinator/cash-collections/{id}/reconcile` | `InternalAuthMiddleware(COORDINATOR)` | Ejecuta el Arqueo Ciego en Caja Central introduciendo `vault_counted_eur`, calculando `discrepancy_eur` y sellando el estado terminal inmutable `RECONCILED_OK` ($\le \pm 1,00\,\text{€}$) o `DISCREPANCY_FLAGGED` ($> \pm 1,00\,\text{€}$, exigiendo `discrepancy_justification` $\ge 20$ caracteres reales con $\ge 3$ bloques alfanuméricos, `RF-04.2`, `D2`, `Art. V.1`). Si parte de `SEALED_IN_ROUTE`, sella también `delivered_to_vault_at` y `delivered_to_vault_by`. |
| `GET` | `/api/coordinator/cash-collections/{id}/reception-receipt` | `InternalAuthMiddleware(COORDINATOR)` | Devuelve el comprobante limpio de entrega en conserjería ($\le 10,00\,\text{€}$, `QA 2`, `Art. V.4`). |

### 1.0. Correlativo del `collection_code` (`H-5`)

`collection_code` se compone como `MCC-AAAA-NNNN`: prefijo de año y número correlativo con relleno de cuatro dígitos (crece a más dígitos al superar 9999). La reserva la hace `PdoMachineCashCollectionRepository::reserveCollectionCode()` dentro de la transacción de captura: toma `pg_advisory_xact_lock(hashtext('cash_collection_code_<año>'))`, calcula `MAX(número) + 1` entre los códigos del año y devuelve el siguiente; la cerradura se libera al hacer commit, de modo que dos capturas simultáneas se serializan sin repetir número y un rollback no consume hueco. El índice único `uq_machine_cash_collections_code` sigue siendo la última red. Las semillas conservan `MCC-2026-0001`…`0004`.

### 1.1. Contrato de idempotencia de la captura independiente (`RF-01.2.3`)

La respuesta de `POST /api/restocker/machines/{machineId}/cash-collections` usa la envolvente canónica `{ success, data, message }`. El alta nueva y el reenvío comparten la proyección del reponedor; solo cambian el código HTTP y `data.idempotent_replay`.

| Caso | Condición | HTTP | `data.idempotent_replay` | Efectos |
| :--- | :--- | :---: | :---: | :--- |
| **Alta nueva** | No existe una fila para `(restocker_id, client_mutation_id)` | `201` | `false` | Inserta una fila, transiciona el reintegro si aplica y audita `CASH_COLLECTION_SEALED` |
| **Replay idempotente** | Ya existe una fila para `(restocker_id, client_mutation_id)` | `200` | `true` | Devuelve la misma fila; no inserta, no transiciona el reintegro y no vuelve a auditar |

La clave canónica es `data.idempotent_replay`. No se publica la clave heredada `data.idempotent`. Un precinto ya usado por otra mutación sigue respondiendo `409 BAG_SEAL_CODE_ALREADY_USED`.

---

## 2. Contrato de Esquema de Base de Datos (PostgreSQL 16+ / Supabase)

La persistencia del Módulo 35 se materializa en la migración incremental `database/supabase_migrations/031_cash_collection_vault_audit.sql` y converge 1:1 en `database/supabase_init.sql`, elevando el esquema de **48 a 49 tablas** (`machine_cash_collections` Tabla 49) y de **41 a 42 tipos `ENUM` nativos** (`cash_collection_status`, y de 47 a 48 columnas `ENUM`).

### 2.1. Nuevo Tipo Enumerado #42 y Ampliación de `audit_entity_type`

```sql
-- Estado del ciclo de custodia y arqueo de la bolsa precintada (RF-01.1, D4)
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

-- Entidad de auditoría para recaudaciones y arqueos de caja (Art. III.3)
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'MACHINE_CASH_COLLECTION';
```

### 2.2. Definición DDL de Tabla 49 · `machine_cash_collections` (`RF-01.1`, `D1`–`D4`, `C1`–`C3`, `QA 1`, Art. III.1, Art. III.3, Art. V.1)

```sql
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

-- D1: A lo sumo una operación de recaudación vinculada por visita de reposición.
CREATE UNIQUE INDEX IF NOT EXISTS uq_machine_cash_collections_visit
    ON machine_cash_collections (restock_visit_id)
    WHERE restock_visit_id IS NOT NULL;

-- D3: Un expediente de reintegro de conserjería solo puede ser liquidado por una recaudación.
CREATE UNIQUE INDEX IF NOT EXISTS uq_machine_cash_collections_refund
    ON machine_cash_collections (linked_refund_request_id)
    WHERE linked_refund_request_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_machine_cash_collections_machine_collected
    ON machine_cash_collections (machine_id, collected_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_machine_cash_collections_restocker_collected
    ON machine_cash_collections (restocker_id, collected_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_machine_cash_collections_status_collected
    ON machine_cash_collections (status, collected_at DESC);
```

### 2.3. Disparadores Constitucionales (`Art. II`, `Art. III.1` y `Art. III.3`)

1. **Prohibición de borrado físico (`Art. III.1`):**
   * `trg_no_delete_machine_cash_collections` (`BEFORE DELETE ON machine_cash_collections EXECUTE FUNCTION prevent_hard_delete()`)
2. **Actualización de marca temporal `updated_at`:**
   * `trg_touch_machine_cash_collections` (`BEFORE UPDATE ON machine_cash_collections EXECUTE FUNCTION touch_updated_at()`)
3. **Inmutabilidad de los campos de ruta y bloqueo terminal tras el arqueo (`Art. III.3`, `D4`, `RNF-01`):**
   * Función `enforce_machine_cash_collections_immutability()` y disparador `trg_guard_machine_cash_collections` (`BEFORE UPDATE ON machine_cash_collections`):
     * Si `OLD.status IN ('RECONCILED_OK', 'DISCREPANCY_FLAGGED')`, lanza `RAISE EXCEPTION 'ART_III_3_CASH_COLLECTION_IMMUTABLE: La recaudación % ya fue arqueada en Caja Central (%) y es inmutable.', OLD.collection_code, OLD.status USING ERRCODE = '45000'`.
     * Si cambia cualquiera de los campos de captura en ruta (`id`, `collection_code`, `restock_visit_id`, `machine_id`, `machine_code_snapshot`, `restocker_id`, `restocker_name_snapshot`, `restocker_code_snapshot`, `bag_seal_code`, `change_tubes_loaded_eur`, `sales_counter_reading`, `previous_sales_counter_reading`, `initial_baseline_reading_eur`, `is_counter_reset`, `counter_reset_notes`, `expected_cashbox_eur`, `reception_cash_delivered_eur`, `receptionist_name`, `linked_refund_request_id`, `route_notes`, `collected_at`, `client_mutation_id`, `created_at`), lanza `RAISE EXCEPTION 'ART_III_3_ROUTE_CAPTURE_IMMUTABLE: Los datos declarados en ruta de la bolsa % son inmutables tras su sellado.', OLD.bag_seal_code USING ERRCODE = '45000'`.
     * Si `OLD.status = 'DELIVERED_TO_VAULT'` y `NEW.status = 'SEALED_IN_ROUTE'`, lanza `RAISE EXCEPTION 'ART_III_3_INVALID_CASH_STATE_REGRESSION: Una bolsa entregada en Caja Central no puede volver a estado SEALED_IN_ROUTE.' USING ERRCODE = '45000'`.
4. **Guarda de primacía sanitaria y de conserjería al insertar (`Art. II.1`, `Art. II.2`, `C4`, `D3`, `RNF-02`):**
   * Función `enforce_cash_collection_sanitary_guard()` y disparador `trg_enforce_cash_collection_sanitary_guard` (`BEFORE INSERT ON machine_cash_collections`):
     * Verifica sobre `machines` (y `locations`) de `NEW.machine_id` que `sanitary_status <> 'QUARANTINE'`, `is_blocked_no_access = FALSE` y que no exista un ticket activo en `incidents` con `category = 'TEMPERATURE_COLD'` y `status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')`; de lo contrario lanza `RAISE EXCEPTION 'ART_II_SANITARY_CASH_COLLECTION_BLOCKED: ...' USING ERRCODE = '45000'`.
     * Si `NEW.restock_visit_id IS NOT NULL` y la visita vinculada tiene `status = 'ABORTED_THERMAL_BREACH'` (o pertenece a otra `machine_id`), lanza `RAISE EXCEPTION 'ART_II_THERMAL_ABORT_CASH_FORBIDDEN: ...' USING ERRCODE = '45000'`.
     * Si `NEW.reception_cash_delivered_eur > 0.00` y la sede de la máquina tiene `locations.has_physical_reception = FALSE`, lanza `RAISE EXCEPTION 'LOCATION_HAS_NO_PHYSICAL_RECEPTION: ...' USING ERRCODE = '45000'`.

---

## 3. Catálogo de Códigos de Error de Dominio y HTTP

| Código de Error | HTTP | Condición de Disparo |
| :--- | :---: | :--- |
| `MACHINE_OUTSIDE_RESTOCKER_ZONES` | `403` | El reponedor intenta consultar o registrar una recaudación en una máquina fuera de sus Zonas Operativas cubiertas hoy (`C2`, `Art. V.4`). |
| `RESTOCK_VISIT_NOT_OWNED` | `403` | La recaudación integrada referencia una visita de reposición cuyo `restocker_id` no coincide con el actor (`D1`). |
| `CASH_COLLECTION_FORBIDDEN_FOR_ROLE` | `403` | Un rol no autorizado (`TECHNICIAN`, `LOCATION_RESPONSIBLE`) intenta acceder a endpoints de recaudación o un reponedor intenta leer una recaudación de otro operario (`Art. V.4`). |
| `MACHINE_NOT_FOUND` | `404` | La máquina indicada no existe o está inactiva (`machines.is_active = FALSE`). |
| `CASH_COLLECTION_NOT_FOUND` | `404` | No existe el registro de recaudación solicitado o no tiene entrega en conserjería cuando se pide su recibo. |
| `SANITARY_CASH_COLLECTION_BLOCKED` | `409` | La máquina está en cuarentena sanitaria (`machines.sanitary_status = 'QUARANTINE'`), fuera de servicio (`machines.is_blocked_no_access = TRUE`) o tiene una avería térmica abierta (`TEMPERATURE_COLD`) (`RF-02.1`, `C4`, `Art. II.2`). |
| `BAG_SEAL_CODE_ALREADY_USED` | `409` | El código de precinto `bag_seal_code` ya fue utilizado en otra recaudación histórica, incluida la carrera en la que dos capturas superan la comprobación previa y compiten en el índice único (`RF-01.2`, `C1`). |
| `LOCATION_HAS_NO_PHYSICAL_RECEPTION` | `409` | Se intenta registrar `reception_cash_delivered_eur > 0.00` en una máquina cuya sede tiene `locations.has_physical_reception = FALSE` (`RF-03.1`, `D3`). |
| `REFUND_REQUEST_NOT_ELIGIBLE_FOR_RECEPTION` | `409` | El expediente `linked_refund_request_id` no existe, pertenece a otra máquina, no está en `'PENDING_INSPECTION'`, su método no es `'EN_MANO_SEDE'` o ya fue liquidado (`RF-03.1`, `D3`). |
| `INVALID_CASH_COLLECTION_STATE_TRANSITION` | `409` | Se intenta marcar `deliver-to-vault` sobre una bolsa que ya no está en `'SEALED_IN_ROUTE'` (`RF-04.1`, `D4`). |
| `CASH_COLLECTION_ALREADY_RECONCILED` | `409` | Se intenta modificar o volver a arquear una bolsa que ya alcanzó `'RECONCILED_OK'` o `'DISCREPANCY_FLAGGED'` (`RF-04.2`, `D4`, `Art. III.3`). |
| `OFFLINE_CASH_COLLECTION_CONFLICT` | `409` | Al sincronizar una recaudación offline diferida, colisiona el precinto, la lectura de contador, el estado sanitario de la máquina o el expediente de reintegro (`RF-05.1`, `C4`). |
| `THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN` | `422` | Se intenta incluir datos de recaudación/cambio dentro de una visita in situ enviada con `status = 'ABORTED_THERMAL_BREACH'` (`RF-02.1`, `C4`, `Art. II.2`). |
| `RESTOCK_VISIT_NOT_ALLOWED` | `422` | La vía independiente de recaudación recibe un `restock_visit_id`; el vínculo con la visita solo se declara al crearla (`D1`). |
| `RESTOCK_VISIT_NOT_COMPLETED` | `422` | La visita vinculada a la recaudación no está en `status = 'COMPLETED'` (`D1`). |
| `VALIDATION_ERROR` | `422` | `collected_at` no es una marca temporal interpretable, supera la hora del servidor en más de 5 minutos o es anterior a la última captura de la máquina (`D2`, `C3`). El detalle incluye `field = collected_at` y `reason` (`INVALID_FORMAT`, `FUTURE_OUT_OF_WINDOW` o `BEFORE_LAST_CAPTURE`). |
| `INVALID_BAG_SEAL_CODE` | `422` | `bag_seal_code` no cumple el patrón `^[A-Z0-9_-]{6,40}$` o carece de al menos 3 dígitos numéricos consecutivos (`RF-01.2`, `C1`). |
| `SALES_COUNTER_REGRESSION` | `422` | `sales_counter_reading < previous_sales_counter_reading` con `is_counter_reset = false` (`RF-02.2`, `C3`). |
| `COUNTER_RESET_NOTES_REQUIRED` | `422` | Se envía `is_counter_reset = true` sin `counter_reset_notes` de entre 20 y 500 caracteres reales con al menos 3 bloques alfanuméricos (`RF-02.2`, `C3`, `Art. V.1`). |
| `INVALID_CASH_AMOUNT_BOUNDS` | `422` | `change_tubes_loaded_eur` fuera de `[0.00, 500.00]`, `sales_counter_reading` fuera de `[0.00, 9999999.99]`, `expected_cashbox_eur` fuera de `[0.00, 10000.00]` o `vault_counted_eur` fuera de `[0.00, 10000.00]` (`RF-02.2`, `RF-04.2`, `C3`). |
| `RECEPTION_CASH_LIMIT_EXCEEDED` | `422` | `reception_cash_delivered_eur` es negativo o supera el tope de $10,00\,\text{€}$ (`RF-03.1`, `D3`). |
| `RECEPTIONIST_NAME_REQUIRED` | `422` | `reception_cash_delivered_eur > 0.00` pero falta `receptionist_name` válido (`3..120` caracteres, `RF-03.1`, `D3`). |
| `REFUND_AMOUNT_MISMATCH` | `422` | `reception_cash_delivered_eur` no coincide exactamente con el `claimed_amount` del expediente `linked_refund_request_id` (`RF-03.1`, `D3`). |
| `DISCREPANCY_JUSTIFICATION_REQUIRED` | `422` | En el arqueo de Caja Central, `ABS(vault_counted_eur - expected_cashbox_eur) > 1.00` y falta `discrepancy_justification` de entre 20 y 1000 caracteres reales con al menos 3 bloques alfanuméricos (`RF-04.2`, `D2`, `Art. V.1`). |
