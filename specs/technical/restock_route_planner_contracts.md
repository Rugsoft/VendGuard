# CONTRATOS TÉCNICOS: PLANIFICADOR DE RUTAS PERIÓDICAS DE CARGA Y PRIORIZACIÓN DINÁMICA POR DEMANDA (`RECURRING RESTOCK SCHEDULES & DYNAMIC ROUTE CADENCE`)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `37-restock-route-planner` (Feature 27 de `docs/features_pendientes.md`)  
**Documento Canónico:** `specs/technical/restock_route_planner_contracts.md`  
**Referencia Funcional:** [`specs/37-restock-route-planner/spec.md`](../37-restock-route-planner/spec.md) y [`specs/functional/restock_route_planner_spec.md`](../functional/restock_route_planner_spec.md) (v1.1 · `RF-01` a `RF-05`, `D1`–`D7`, `C1`–`C3`, `QA-1`–`QA-6`)  
**Motor de Persistencia:** PostgreSQL 16+ (Supabase / Motor Local de Certificación) · Motor Único  
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`)  
**Conformidad Constitucional:** Artículos I, II, III (3.1, 3.2, 3.3), IV, V (5.1, 5.4) y VI

---

## 1. Visión General de Endpoints y Superficies del Módulo

| Método | Ruta | Rol / Middleware | Propósito Operativo |
| :--- | :--- | :--- | :--- |
| **Superficie Móvil del Reponedor (`RF-02`, `RF-03`, `RF-05.1`, `RF-05.2`, `D4`, `D7`, `C1`–`C3`, Art. II, Art. V.1, Art. V.4)** | | | |
| `GET` | `/api/restocker/my-route` | `InternalAuthMiddleware(RESTOCKER)` | Extiende el bootstrap móvil del reponedor añadiendo `service_date` (`YYYY-MM-DD` en `Europe/Madrid`) y el objeto `route_plan`: `summary`, `stops` agrupadas por sede y ordenadas en 3 bloques (`FEFO_SANITARY` $\rightarrow$ `DEMAND_L1_STOCKOUT` $\rightarrow$ `SCHEDULED_CADENCE`) con Haversine y degradación sin GPS, `completed_stops`, `skipped_stops`, `blocked_stops`, `unmapped_stops_count`, `total_distance_km`, `full_route_navigation_url`, `waypoints_truncated`, `origin`, y `site_coincidence` (incluyendo sedes habilitadas por `REASSIGNED` vigente). Jamás serializa `unit_cost_eur`, `commercial_spoilage_loss_eur` ni `reason_text` internos de Coordinación de excepciones revocadas o ajenas (`Art. V.4`). |
| `POST` | `/api/restocker/route/stops/{machineId}/skip` | `InternalAuthMiddleware(RESTOCKER)` | Registra de forma atómica e idempotente (`client_mutation_id`, longitud `8..64`, respaldado por `offline_sync_mutations`) una omisión `SKIPPED` exclusivamente para `service_date = today` (`Europe/Madrid`). Exige `skip_reason_code` y `reason_text` ($\ge 20$ caracteres reales y $\ge 3$ bloques alfanuméricos, `Art. V.1`). Si existe `FEFO_ALERT` (`requires_cold_chain = TRUE`), sella `had_active_fefo_alert = TRUE` y encola `'RESTOCK_FEFO_STOP_SKIPPED_ALERT'` (`CRITICAL_SANITARY`) en canales internos de Coordinación; si además hay lote ya `EXPIRED`, sella `machines.sanitary_status = 'EXPIRED'` (`QA-1`, `Art. II`). |
| `POST` | `/api/restocker/route/override-conflict-dump` | `InternalAuthMiddleware(RESTOCKER)` | Archiva en `audit_log` (`OFFLINE_RESTOCK_OVERRIDE_CONFLICT_DUMP` bajo `RESTOCK_ROUTE_DAILY_OVERRIDE`, `Art. III.3`) una mutación Offline `SKIP_RESTOCK_STOP` rechazada con `409`/`422` al sincronizar (`RF-05.1.3`, `C3`), conservando el justificante sin alterar la verdad del servidor. |
| **Superficie de Planificación y Ruta del Día en Coordinación (`RF-01`–`RF-03`, `RF-05.3`, `D1`–`D4`, `D6`, `D7`, `QA-1`–`QA-6`)** | | | |
| `GET` | `/api/coordinator/restock-schedules` | `InternalAuthMiddleware(COORDINATOR)` | Lista paginada y filtrable (`?zone_id=&machine_type=&cadence_type=&has_schedule=&search=&limit=&offset=`) de máquinas activas del parque con su versión vigente de agenda (`is_active = TRUE`) o indicación `has_schedule = false`. |
| `POST` / `PUT` | `/api/coordinator/restock-schedules` · `/api/coordinator/restock-schedules/machine/{machineId}` | `InternalAuthMiddleware(COORDINATOR)` | Crea o actualiza de forma versionada la agenda periódica de una máquina activa (`QA-2`, `Art. III.3`): en una transacción cierra lógicamente la fila activa previa (`is_active = FALSE`, `deactivated_at`, `deactivated_by`) e inserta una nueva fila activa en `machine_restock_schedules`, auditando antes/después bajo `MACHINE_RESTOCK_SCHEDULE`. |
| `POST` | `/api/coordinator/restock-schedules/bulk` | `InternalAuthMiddleware(COORDINATOR)` | Configura en una única transacción atómica todo-o-nada agendas por filtro (`zone_id`, `machine_type`) o lista explícita `machine_ids[]`, respetando la bandera `overwrite_existing` (`RF-01.3.2`, `QA-6`). |
| `PATCH` | `/api/coordinator/restock-schedules/{id}/deactivate` | `InternalAuthMiddleware(COORDINATOR)` | Da de baja lógica una agenda activa (`is_active = FALSE`, `deactivated_at`, `deactivated_by`, `Art. III.1/III.2`) conservando inmutable la fila para histórico de `machine_restock_visits.route_schedule_id`. |
| `GET` | `/api/coordinator/restock-route/day` | `InternalAuthMiddleware(COORDINATOR)` | Devuelve la fotografía operativa instantánea del día (`?service_date=YYYY-MM-DD&restocker_id=&zone_id=`): **contadores instantáneos exclusivos de ese `service_date`** (`Art. VI`), bandeja prioritaria `uncovered_stops`, rutas ordenadas por reponedor (con proyección futura de ausencias y titularidad cuando `service_date > today`, `QA-6`), `blocked_stops`, `completed_stops`, `skipped_stops` y `active_overrides`. |
| `POST` | `/api/coordinator/restock-route/overrides` | `InternalAuthMiddleware(COORDINATOR)` | Registra para `(machine_id, service_date >= today)` un desvío puntual `REASSIGNED` (`target_restocker_id` + `reassignment_reason_code` + `reason_text` opcional; rechaza auto-desvío `422`) o una omisión `SKIPPED` (`skip_reason_code` + `reason_text` $\ge 20$ chars reales). Si existía una excepción previa `ACTIVE` para esa máquina y día, la pasa a `'REVOKED'` dentro de la misma transacción (`RF-02.3`). Rechaza con `409` si existe visita `COMPLETED` o `ABORTED_THERMAL_BREACH` en `service_date`. |
| `POST` | `/api/coordinator/restock-route/overrides/{id}/revoke` | `InternalAuthMiddleware(COORDINATOR)` | Revoca manualmente una excepción `ACTIVE` (`ACTIVE` $\rightarrow$ `REVOKED`) siempre que no exista visita `COMPLETED` o `ABORTED_THERMAL_BREACH` para esa máquina en `service_date` (`RF-02.3`). |
| **Integración con Órdenes de Carga M33 (`RF-04.1`, `D5`, `QA-5`)** | | | |
| `GET` | `/api/coordinator/product-loading-orders/proposal` | `InternalAuthMiddleware(COORDINATOR)` | Extiende el cálculo de propuesta aceptando `demand_mode=TODAY_ROUTE` y parámetro opcional `service_date` (`YYYY-MM-DD`, por defecto hoy en `Europe/Madrid`), reclamando todos los huecos llenables (`ALL_GAPS` sobre ruta) exclusivamente de las máquinas `PENDING` en la ruta efectiva del `restocker_id`. |
| `POST` | `/api/coordinator/product-loading-orders` · `/api/coordinator/product-loading-orders/batch` | `InternalAuthMiddleware(COORDINATOR)` | Acepta `demand_mode = "TODAY_ROUTE"` (y `service_date` opcional); en emisión en lote omite con causa `'NO_DEMAND'` a los reponedores sin paradas pendientes o sin huecos llenables hoy. |
| **Opacidad Constitucional (`Art. V.4`)** | | | |
| `ALL` | `/api/incidents/*`, `/api/qr/*`, Albaranes A4 | `SiteAuthMiddleware` / Público | Opacidad total: ningún endpoint ciudadano, de Portal de Sede ni de impresión externa expone cadencias, órdenes de parada, excepciones `SKIPPED`/`REASSIGNED`, alertas FEFO ni cobertura territorial. |

---

## 2. Contrato de Esquema de Base de Datos (PostgreSQL 16+ / Supabase)

La persistencia del Módulo 37 se materializa en la migración incremental `database/supabase_migrations/033_restock_route_planner.sql` y converge 1:1 en `database/supabase_init.sql` y `database/supabase_seeds.sql`, elevando el esquema de **49 a 51 tablas de dominio** (`machine_restock_schedules` Tabla 50 y `restock_route_daily_overrides` Tabla 51), de **44 a 50 tipos `ENUM` nativos** (`#45` a `#50`) y de **50 a 56 columnas `ENUM`**, manteniendo intacto el DDL de `locations`.

### 2.1. Nuevos Tipos Enumerados `#45` a `#50` y Ampliación de Tipos Existentes

```sql
-- Tipo nativo #45: Frecuencia periódica de reposición (D6, RF-01.1)
DO $do$
BEGIN
    CREATE TYPE restock_cadence_type AS ENUM (
        'DAILY',
        'MON_WED_FRI',
        'TUE_THU',
        'WEEKLY',
        'BIWEEKLY'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Tipo nativo #46: Franja horaria tipificada de acceso/carga (D6, RF-01.1)
DO $do$
BEGIN
    CREATE TYPE restock_time_window AS ENUM (
        'MORNING',
        'MIDDAY',
        'AFTERNOON',
        'ANY'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Tipo nativo #47: Naturaleza de la excepción diaria de ruta (D1, RF-02.1)
DO $do$
BEGIN
    CREATE TYPE restock_override_type AS ENUM (
        'SKIPPED',
        'REASSIGNED'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Tipo nativo #48: Ciclo de vida trazable de la excepción diaria (C1, Art. III.1/III.3)
DO $do$
BEGIN
    CREATE TYPE restock_override_status AS ENUM (
        'ACTIVE',
        'SUPERSEDED',
        'REVOKED'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Tipo nativo #49: Causas tipificadas de omisión de parada (D4, RF-02.1, Art. V.1)
DO $do$
BEGIN
    CREATE TYPE restock_skip_reason_code AS ENUM (
        'LOCATION_CLOSED',
        'NO_KEY_OR_ACCESS',
        'LOCAL_HOLIDAY',
        'FLEET_CAPACITY_SHORTAGE',
        'OTHER_OPERATIONAL'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Tipo nativo #50: Causas tipificadas de desvío puntual de parada (QA-4, RF-02.1)
DO $do$
BEGIN
    CREATE TYPE restock_reassignment_reason_code AS ENUM (
        'ZONE_UNCOVERED',
        'LOAD_BALANCING',
        'FEFO_RESCUE',
        'OTHER_REASSIGNMENT'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Ampliación del modo de demanda de carga M33 (D5, QA-5, RF-04.1)
ALTER TYPE product_loading_demand_mode ADD VALUE IF NOT EXISTS 'TODAY_ROUTE';

-- Ampliación del catálogo de entidades de auditoría (Art. III.3, RF-01.3, RF-02.1)
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'MACHINE_RESTOCK_SCHEDULE';
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'RESTOCK_ROUTE_DAILY_OVERRIDE';

-- Plantilla interna de alerta sanitaria FEFO ante omisión (D4, QA-1, Art. II, Art. V.4)
ALTER TYPE notification_template ADD VALUE IF NOT EXISTS 'RESTOCK_FEFO_STOP_SKIPPED_ALERT';
```

### 2.2. Tabla 50 (`machine_restock_schedules`), Tabla 51 (`restock_route_daily_overrides`) y Evolución de Tablas 34 y 47

```sql
-- ============================================================================
-- Tabla 50 · machine_restock_schedules (Agendas periódicas versionadas por fila)
-- ============================================================================
CREATE TABLE IF NOT EXISTS machine_restock_schedules (
    id                     BIGINT GENERATED ALWAYS AS IDENTITY,
    machine_id             BIGINT NOT NULL,
    cadence_type           restock_cadence_type NOT NULL,
    day_of_week            SMALLINT,
    anchor_date            DATE,
    preferred_time_window  restock_time_window NOT NULL DEFAULT 'ANY',
    include_weekends       BOOLEAN NOT NULL DEFAULT FALSE,
    notes                  VARCHAR(500),
    is_active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_by             BIGINT NOT NULL,
    updated_by             BIGINT NOT NULL,
    deactivated_by         BIGINT,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at         TIMESTAMPTZ,
    CONSTRAINT pk_machine_restock_schedules PRIMARY KEY (id),
    CONSTRAINT fk_mrs_machine FOREIGN KEY (machine_id)
        REFERENCES machines (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_mrs_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_mrs_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_mrs_deactivated_by FOREIGN KEY (deactivated_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_mrs_cadence_coherence CHECK (
        (
            (cadence_type = 'WEEKLY' AND day_of_week BETWEEN 1 AND 7 AND anchor_date IS NULL)
            OR (cadence_type = 'BIWEEKLY' AND anchor_date IS NOT NULL AND day_of_week IS NULL)
            OR (cadence_type IN ('DAILY', 'MON_WED_FRI', 'TUE_THU') AND day_of_week IS NULL AND anchor_date IS NULL)
        )
        AND (include_weekends = FALSE OR cadence_type = 'DAILY')
    ),
    CONSTRAINT chk_mrs_deactivation_coherence CHECK (
        (is_active = TRUE AND deactivated_at IS NULL AND deactivated_by IS NULL)
        OR (is_active = FALSE AND deactivated_at IS NOT NULL)
    ),
    CONSTRAINT chk_mrs_notes_length CHECK (
        notes IS NULL OR char_length(btrim(notes)) BETWEEN 1 AND 500
    )
);

-- Como máximo una agenda activa por máquina; las versiones históricas permanecen inmutables (QA-2, Art. III.3)
CREATE UNIQUE INDEX IF NOT EXISTS uq_machine_restock_schedule_active
    ON machine_restock_schedules (machine_id)
    WHERE is_active = TRUE;

CREATE INDEX IF NOT EXISTS idx_mrs_machine_history
    ON machine_restock_schedules (machine_id, is_active, created_at DESC);

DROP TRIGGER IF EXISTS trg_no_delete_machine_restock_schedules ON machine_restock_schedules;
CREATE TRIGGER trg_no_delete_machine_restock_schedules
    BEFORE DELETE ON machine_restock_schedules
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

DROP TRIGGER IF EXISTS trg_touch_machine_restock_schedules ON machine_restock_schedules;
CREATE TRIGGER trg_touch_machine_restock_schedules
    BEFORE UPDATE ON machine_restock_schedules
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ============================================================================
-- Tabla 51 · restock_route_daily_overrides (Excepciones diarias trazables)
-- ============================================================================
CREATE TABLE IF NOT EXISTS restock_route_daily_overrides (
    id                        BIGINT GENERATED ALWAYS AS IDENTITY,
    machine_id                BIGINT NOT NULL,
    service_date              DATE NOT NULL,
    override_type             restock_override_type NOT NULL,
    status                    restock_override_status NOT NULL DEFAULT 'ACTIVE',
    original_restocker_id     BIGINT,
    target_restocker_id       BIGINT,
    skip_reason_code          restock_skip_reason_code,
    reassignment_reason_code  restock_reassignment_reason_code,
    reason_text               VARCHAR(500),
    had_active_fefo_alert     BOOLEAN NOT NULL DEFAULT FALSE,
    triggered_notification_id BIGINT,
    client_mutation_id        VARCHAR(64),
    superseded_by_visit_id    BIGINT,
    created_by                BIGINT NOT NULL,
    resolved_by               BIGINT,
    created_at                TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at               TIMESTAMPTZ,
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_restock_route_daily_overrides PRIMARY KEY (id),
    CONSTRAINT fk_rrdo_machine FOREIGN KEY (machine_id)
        REFERENCES machines (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rrdo_original_restocker FOREIGN KEY (original_restocker_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rrdo_target_restocker FOREIGN KEY (target_restocker_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rrdo_notification FOREIGN KEY (triggered_notification_id)
        REFERENCES notification_outbox (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rrdo_superseded_visit FOREIGN KEY (superseded_by_visit_id)
        REFERENCES machine_restock_visits (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rrdo_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rrdo_resolved_by FOREIGN KEY (resolved_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_rrdo_type_fields_coherence CHECK (
        (
            override_type = 'SKIPPED'
            AND skip_reason_code IS NOT NULL
            AND reassignment_reason_code IS NULL
            AND target_restocker_id IS NULL
            AND reason_text IS NOT NULL
            AND char_length(btrim(reason_text)) BETWEEN 20 AND 500
        )
        OR (
            override_type = 'REASSIGNED'
            AND target_restocker_id IS NOT NULL
            AND reassignment_reason_code IS NOT NULL
            AND skip_reason_code IS NULL
            AND (reason_text IS NULL OR char_length(btrim(reason_text)) <= 500)
        )
    ),
    CONSTRAINT chk_rrdo_status_resolution_coherence CHECK (
        (status = 'ACTIVE' AND resolved_at IS NULL AND superseded_by_visit_id IS NULL)
        OR (status = 'SUPERSEDED' AND resolved_at IS NOT NULL AND superseded_by_visit_id IS NOT NULL AND override_type = 'SKIPPED')
        OR (status = 'REVOKED' AND resolved_at IS NOT NULL)
    ),
    CONSTRAINT chk_rrdo_client_mutation_format CHECK (
        client_mutation_id IS NULL
        OR (char_length(btrim(client_mutation_id)) BETWEEN 8 AND 64 AND client_mutation_id ~ '^[A-Za-z0-9_.:-]+$')
    )
);

-- Máximo una excepción viva por máquina y día civil; el histórico permanece inmutable (C1, Art. III.1/III.3)
CREATE UNIQUE INDEX IF NOT EXISTS uq_restock_override_active_machine_day
    ON restock_route_daily_overrides (machine_id, service_date)
    WHERE status = 'ACTIVE';

CREATE INDEX IF NOT EXISTS idx_rrdo_service_date_target
    ON restock_route_daily_overrides (service_date, target_restocker_id)
    WHERE status = 'ACTIVE' AND override_type = 'REASSIGNED';

DROP TRIGGER IF EXISTS trg_no_delete_restock_route_daily_overrides ON restock_route_daily_overrides;
CREATE TRIGGER trg_no_delete_restock_route_daily_overrides
    BEFORE DELETE ON restock_route_daily_overrides
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

DROP TRIGGER IF EXISTS trg_touch_restock_route_daily_overrides ON restock_route_daily_overrides;
CREATE TRIGGER trg_touch_restock_route_daily_overrides
    BEFORE UPDATE ON restock_route_daily_overrides
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ============================================================================
-- Evolución de Tabla 47 (machine_restock_visits) y Tabla 34 (offline_sync_mutations)
-- ============================================================================
DO $do$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'fk_machine_restock_visits_route_schedule'
           AND conrelid = 'machine_restock_visits'::regclass
    ) THEN
        ALTER TABLE machine_restock_visits
            ADD CONSTRAINT fk_machine_restock_visits_route_schedule
            FOREIGN KEY (route_schedule_id)
            REFERENCES machine_restock_schedules (id)
            ON DELETE RESTRICT ON UPDATE CASCADE;
    END IF;
END $do$;

CREATE INDEX IF NOT EXISTS idx_machine_restock_visits_schedule
    ON machine_restock_visits (route_schedule_id)
    WHERE route_schedule_id IS NOT NULL;

ALTER TABLE offline_sync_mutations
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
            'ESCALATE_INCIDENT_TO_SAT',
            'SKIP_RESTOCK_STOP'
        )
    );
```

---

## 3. Contratos JSON de Petición y Respuesta

### 3.1. Configuración Individual y Masiva de Agendas (`PUT /api/coordinator/restock-schedules/machine/{machineId}` y `POST /api/coordinator/restock-schedules/bulk`)

**Request (`PUT /api/coordinator/restock-schedules/machine/{machineId}`):**
```json
{
  "cadence_type": "WEEKLY",
  "day_of_week": 3,
  "anchor_date": null,
  "preferred_time_window": "MORNING",
  "include_weekends": false,
  "notes": "Acceso por muelle de carga antes de las 11:30."
}
```

**Response `200 OK`:**
```json
{
  "success": true,
  "data": {
    "schedule": {
      "id": 14,
      "machine_id": 8,
      "cadence_type": "WEEKLY",
      "cadence_label": "Semanal",
      "day_of_week": 3,
      "anchor_date": null,
      "preferred_time_window": "MORNING",
      "preferred_time_window_label": "Mañana",
      "include_weekends": false,
      "notes": "Acceso por muelle de carga antes de las 11:30.",
      "is_active": true,
      "previous_version_id": 5,
      "next_scheduled_date": "2026-11-25",
      "updated_at": "2026-11-21T09:15:00+01:00"
    }
  },
  "message": "Agenda periódica guardada y versionada correctamente."
}
```

**Request (`POST /api/coordinator/restock-schedules/bulk`):**
```json
{
  "zone_id": 2,
  "machine_type": "PERISHABLE_FOOD",
  "machine_ids": null,
  "cadence_type": "MON_WED_FRI",
  "day_of_week": null,
  "anchor_date": null,
  "preferred_time_window": "MORNING",
  "include_weekends": false,
  "overwrite_existing": true,
  "notes": "Cadencia refrigerada estándar Zona Norte."
}
```

**Response `200 OK`:**
```json
{
  "success": true,
  "data": {
    "matched_count": 6,
    "created_count": 4,
    "updated_count": 2,
    "skipped_count": 0
  },
  "message": "Configuración masiva aplicada de forma atómica."
}
```

### 3.2. Tablero «Ruta del Día» en Coordinación (`GET /api/coordinator/restock-route/day?service_date=2026-11-21`)

**Response `200 OK`:**
```json
{
  "success": true,
  "data": {
    "service_date": "2026-11-21",
    "is_future_projection": false,
    "kpis": {
      "scheduled_machines_count": 18,
      "injected_fefo_count": 3,
      "injected_demand_count": 4,
      "completed_count": 5,
      "skipped_count": 1,
      "blocked_count": 2,
      "uncovered_count": 2
    },
    "uncovered_stops": [
      {
        "machine_id": 19,
        "machine_code": "VEND-NOR-019",
        "machine_type": "PERISHABLE_FOOD",
        "location_id": 6,
        "location_name": "Centro Logístico Norte",
        "sla_tier": "PRIORITY_CORPORATE",
        "zone_id": 1,
        "zone_name": "Zona Norte",
        "coverage_status": "UNCOVERED",
        "priority_block": "FEFO_SANITARY",
        "triggers": ["FEFO_ALERT", "SCHEDULED"],
        "earliest_fefo_expiration_date": "2026-11-21"
      }
    ],
    "restocker_routes": [
      {
        "restocker_id": 7,
        "restocker_name": "Lucía Navarro",
        "operator_code": "REP-01",
        "shift_status": "ON_SHIFT",
        "summary": {
          "pending_stops_count": 4,
          "pending_machines_count": 6,
          "fefo_alerts_count": 2,
          "completed_machines_count": 3,
          "skipped_machines_count": 0,
          "blocked_machines_count": 1,
          "total_distance_km": 14.8,
          "unmapped_stops_count": 0
        },
        "stops": [],
        "completed_stops": [],
        "skipped_stops": [],
        "blocked_stops": []
      }
    ],
    "active_overrides": []
  }
}
```

### 3.3. Registro y Revocación de Excepciones en Coordinación (`POST /api/coordinator/restock-route/overrides` y `.../revoke`)

**Request (`REASSIGNED`):**
```json
{
  "machine_id": 19,
  "service_date": "2026-11-21",
  "override_type": "REASSIGNED",
  "target_restocker_id": 9,
  "reassignment_reason_code": "ZONE_UNCOVERED",
  "reason_text": "Zona Norte sin cobertura por baja simultánea de titular y suplente."
}
```

**Request (`SKIPPED`):**
```json
{
  "machine_id": 22,
  "service_date": "2026-11-24",
  "override_type": "SKIPPED",
  "skip_reason_code": "LOCAL_HOLIDAY",
  "reason_text": "Edificio municipal cerrado todo el día por festivo patronal confirmado por conserjería."
}
```

**Response `201 Created` (`REASSIGNED`):**
```json
{
  "success": true,
  "data": {
    "override": {
      "id": 31,
      "machine_id": 19,
      "service_date": "2026-11-21",
      "override_type": "REASSIGNED",
      "status": "ACTIVE",
      "original_restocker_id": null,
      "target_restocker_id": 9,
      "reassignment_reason_code": "ZONE_UNCOVERED",
      "had_active_fefo_alert": true,
      "triggered_notification_id": null,
      "revoked_previous_override_id": null
    }
  },
  "message": "Excepción diaria de ruta registrada correctamente."
}
```

### 3.4. Hoja de Ruta del Reponedor (`GET /api/restocker/my-route`)

**Fragmento `route_plan` dentro de `data` (`200 OK`, excluyendo costes y notas internas ajenas):**
```json
{
  "service_date": "2026-11-21",
  "route_plan": {
    "origin": {
      "source": "BASE_CENTRAL",
      "latitude": 41.385064,
      "longitude": 2.1734035
    },
    "summary": {
      "pending_stops_count": 2,
      "pending_machines_count": 3,
      "completed_machines_count": 1,
      "skipped_machines_count": 0,
      "blocked_machines_count": 1,
      "fefo_alerts_count": 1,
      "total_distance_km": 6.4,
      "unmapped_stops_count": 1
    },
    "full_route_navigation_url": "https://www.google.com/maps/dir/?api=1&origin=41.385064,2.1734035&destination=41.4036299,2.1743558&travelmode=driving",
    "waypoints_truncated": false,
    "stops": [
      {
        "stop_order": 1,
        "location_id": 1,
        "location_name": "Hospital Universitario Central",
        "address": "Av. Diagonal 450, Barcelona",
        "sla_tier": "CRITICAL_INFRASTRUCTURE_24_7",
        "priority_block": "FEFO_SANITARY",
        "has_valid_coordinates": true,
        "latitude": 41.4036299,
        "longitude": 2.1743558,
        "distance_km": 6.4,
        "navigation_url": "https://www.google.com/maps/dir/?api=1&destination=41.4036299,2.1743558&travelmode=driving",
        "earliest_fefo_expiration_date": "2026-11-21",
        "preferred_time_window": "MORNING",
        "site_coincidence": null,
        "machines": [
          {
            "machine_id": 5,
            "machine_code": "VEND-MAD-005",
            "machine_type": "PERISHABLE_FOOD",
            "schedule_id": 12,
            "triggers": ["FEFO_ALERT", "SCHEDULED"],
            "has_fefo_alert": true,
            "has_expired_cold_batch": true,
            "empty_slots_count": 2,
            "active_l1_incident": null,
            "is_temporary_reassignment": false
          }
        ]
      }
    ],
    "completed_stops": [],
    "skipped_stops": [],
    "blocked_stops": [
      {
        "machine_id": 11,
        "machine_code": "VEND-MAD-011",
        "location_id": 3,
        "location_name": "Oficinas Centro",
        "block_reason": "SANITARY_QUARANTINE",
        "block_reason_label": "Bloqueada por cuarentena sanitaria activa"
      }
    ]
  }
}
```

### 3.5. Omisión In Situ (`POST /api/restocker/route/stops/{machineId}/skip`) y Volcado Offline (`POST /api/restocker/route/override-conflict-dump`)

**Request (`.../skip`):**
```json
{
  "service_date": "2026-11-21",
  "skip_reason_code": "LOCATION_CLOSED",
  "reason_text": "La conserjería permanece cerrada con persiana metálica bajada y sin personal de acceso.",
  "client_mutation_id": "mut-skip-20261121-0001",
  "client_occurred_at": "2026-11-21T08:45:00+01:00"
}
```

**Response `201 Created` (o `200 OK` en reenvío idempotente):**
```json
{
  "success": true,
  "data": {
    "override": {
      "id": 32,
      "machine_id": 5,
      "service_date": "2026-11-21",
      "override_type": "SKIPPED",
      "status": "ACTIVE",
      "skip_reason_code": "LOCATION_CLOSED",
      "had_active_fefo_alert": true,
      "triggered_notification_id": 118
    },
    "sanitary_status_updated_to_expired": true,
    "idempotent_replay": false
  },
  "message": "Omisión registrada. Se ha notificado a Coordinación la alerta sanitaria FEFO."
}
```

**Request (`.../override-conflict-dump`):**
```json
{
  "machine_id": 5,
  "service_date": "2026-11-21",
  "skip_reason_code": "LOCATION_CLOSED",
  "reason_text": "La conserjería permanece cerrada con persiana metálica bajada y sin personal de acceso.",
  "client_mutation_id": "mut-skip-0901",
  "client_occurred_at": "2026-11-21T10:15:00+01:00",
  "http_status": 409,
  "error_code": "RESTOCK_STOP_ALREADY_COMPLETED_TODAY",
  "error_message": "La parada ya figura completada hoy."
}
```

### 3.6. Propuesta M33 con Modo `TODAY_ROUTE` (`GET /api/coordinator/product-loading-orders/proposal?restocker_id=7&demand_mode=TODAY_ROUTE&safety_buffer_pct=10&service_date=2026-11-21`)

**Response `200 OK` (estructura compatible con M33 filtrada a ruta efectiva):**
```json
{
  "success": true,
  "data": {
    "restocker": { "id": 7, "name": "Lucía Navarro", "operator_code": "REP-01" },
    "demand_mode": "TODAY_ROUTE",
    "service_date": "2026-11-21",
    "safety_buffer_pct": 10,
    "route_machine_ids": [5, 8, 19],
    "excluded_blocked_machine_ids": [11],
    "items": [
      {
        "product_id": 3,
        "sku": "SAND-JAMON-01",
        "name": "Sándwich Mixto Fresco",
        "requires_cold_chain": true,
        "total_gap": 12,
        "usable_van_stock": 2,
        "suggested_qty": 12,
        "reason": "ROUTE_GAP_FILL"
      }
    ]
  }
}
```

---

## 4. Catálogo Determinista de Códigos de Error HTTP

| Código HTTP | `error_code` | Condición de Disparo | Referencia |
| :---: | :--- | :--- | :--- |
| `400` | `VALIDATION_ERROR` | Payload JSON malformado, fecha `service_date` con formato distinto a `YYYY-MM-DD`, o parámetro de ruta/filtro numérico inválido. | `RF-01`–`RF-05` |
| `401` | `UNAUTHORIZED` | Petición sin sesión o token JWT válido. | `RNF-03` |
| `403` | `FORBIDDEN` / `RESTOCK_STOP_NOT_IN_RESTOCKER_ROUTE` | Usuario sin rol permitido (`COORDINATOR` vs. `RESTOCKER`), intento de `RESTOCKER` de registrar `REASSIGNED` o de omitir una parada que no pertenece a su ruta efectiva de hoy. | `RF-02.2`, `RF-02.3` |
| `404` | `MACHINE_NOT_FOUND` / `SCHEDULE_NOT_FOUND` / `OVERRIDE_NOT_FOUND` | La máquina, versión de agenda o excepción diaria solicitada no existe. | `RF-01.3`, `RF-02.3` |
| `409` | `RESTOCK_STOP_ALREADY_COMPLETED_TODAY` | Se intenta registrar `SKIPPED`, `REASSIGNED` o `revoke` sobre `(machine_id, service_date)` cuando ya existe una visita `'COMPLETED'` o `'ABORTED_THERMAL_BREACH'` ese día. | `RF-02.1`, `QA-6` |
| `409` | `RESTOCK_STOP_BLOCKED_BY_SANITARY_OR_SAT` | Un `RESTOCKER` intenta omitir (`SKIPPED`) una máquina segregada en `blocked_stops` (`QUARANTINE`, `is_blocked_no_access = TRUE`, `TEMPERATURE_COLD` abierta o pausa estacional sin inclusión activa). | `RF-02.2`, `C1` |
| `409` | `OVERRIDE_NOT_ACTIVE` | Intento de revocar manualmente una excepción que ya está en estado `'REVOKED'` o `'SUPERSEDED'`. | `RF-02.3` |
| `422` | `SCHEDULE_CADENCE_FIELDS_INVALID` | Combinación incoherente entre `cadence_type`, `day_of_week`, `anchor_date`, `include_weekends` o `preferred_time_window`. | `RF-01.1` |
| `422` | `MACHINE_INACTIVE_OR_DELETED` | Intento de configurar agenda, lote masivo explícito o excepción diaria sobre una máquina inactiva/eliminada o en sede inactiva. | `RF-01.3`, `QA-6` |
| `422` | `SKIP_REASON_INVALID` | `skip_reason_code` inválido o `reason_text` fuera de `20..500` caracteres reales o con menos de `3` bloques alfanuméricos. | `RF-02.2`, `Art. V.1` |
| `422` | `REASSIGNMENT_REASON_INVALID` | `reassignment_reason_code` ausente o ajeno al enum `#50 restock_reassignment_reason_code`, o `reason_text` superior a `500` caracteres. | `RF-02.3`, `QA-4` |
| `422` | `INVALID_TARGET_RESTOCKER` | `target_restocker_id` no corresponde a un usuario activo con rol `'RESTOCKER'`. | `RF-02.3` |
| `422` | `SELF_REASSIGNMENT_FORBIDDEN` | Coordinación intenta registrar `REASSIGNED` hacia el mismo reponedor que ya posee la parada en `service_date`. | `RF-02.3`, `QA-4` |
| `422` | `PAST_SERVICE_DATE_FORBIDDEN` / `SKIP_FUTURE_DATE_FORBIDDEN_FOR_RESTOCKER` | `service_date` anterior a hoy (`COORDINATOR` y `RESTOCKER`), o `RESTOCKER` intentando `SKIPPED` en una fecha distinta de `today` (`Europe/Madrid`). | `RF-02.2`, `QA-4` |
| `422` | `INVALID_CLIENT_MUTATION_ID` | `client_mutation_id` ausente en mutación móvil o fuera de `8..64` caracteres `^[A-Za-z0-9_.:-]+$`. | `RF-02.1`, `RF-05.1` |
