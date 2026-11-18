# CONTRATOS TÉCNICOS: SINERGIA OPERATIVA REPONEDOR ↔ TÉCNICO SAT (`LEVEL-1 RESTOCKER DISPATCH & IN-SITU SAT ESCALATION`)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)\
**Módulo:** `36-restocker-sat-synergy` (Feature 26 de `docs/features_pendientes.md`)\
**Documento Canónico:** `specs/technical/restocker_sat_synergy_contracts.md`\
**Referencia Funcional:** [`specs/36-restocker-sat-synergy/spec.md`](../36-restocker-sat-synergy/spec.md) y [`specs/functional/restocker_sat_synergy_spec.md`](../functional/restocker_sat_synergy_spec.md) (v1.0 · `RF-01` a `RF-05`, `D1`–`D4`, `C1`–`C2`, 9 salvaguardas de auditoría QA `QA-*`)\
**Motor de Persistencia:** PostgreSQL 16+ (Supabase / Motor Local de Certificación) · Motor Único\
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`) y `multipart/form-data` para subida de fotos de evidencia ($\le 5\text{ MB}$)\
**Conformidad Constitucional:** Artículos I, II (2.1, 2.2), III (3.1, 3.2, 3.3), IV, V (5.1, 5.2, 5.3, 5.4, 5.5, 5.6) y VI

---

## 1. Visión General de Endpoints y Superficies del Módulo

| Método | Ruta | Rol / Middleware | Propósito Operativo |
| :--- | :--- | :--- | :--- |
| **Superficie Móvil del Reponedor (`RF-03`, `RF-04`, `RF-05.1`, `RF-05.2`, Art. II, Art. V.1, Art. V.4, Art. V.5)** | | | |
| `GET` | `/api/restocker/my-route` | `InternalAuthMiddleware(RESTOCKER)` | Extiende el bootstrap móvil del reponedor añadiendo: (1) `assigned_l1_incidents` (tickets activos en `dispatch_level = 'L1_RESTOCKER'` asignados al reponedor), (2) `l1_taxonomy_catalog` (las causas raíz y soluciones activas elegibles para Nivel 1), (3) en cada máquina de `machines` su `active_l1_incident` (si existe) y el indicador `has_active_sat_incident` (booleano para saber si ya tiene un `L2_SAT` abierto), y (4) por cada sede de sus zonas cubiertas el bloque `site_coincidence` con los Técnicos SAT en turno activo (`ON_SHIFT` / `MEAL_BREAK`) que tienen paradas en esa sede (`D4`, `Art. V.4`). |
| `POST` | `/api/restocker/incidents/{id}/start` | `InternalAuthMiddleware(RESTOCKER)` | Inicia de forma atómica e idempotente (`client_mutation_id`) la intervención in situ sobre un aviso `L1_RESTOCKER` asignado al reponedor (`ASSIGNED` / `REOPENED` $\rightarrow$ `IN_PROGRESS`, `RF-03.1.1`). Rechaza con `403 INCIDENT_NOT_ASSIGNED_TO_RESTOCKER` si pertenece a otro operario o está en `L2_SAT`. |
| `POST` | `/api/restocker/incidents/{id}/resolve` | `InternalAuthMiddleware(RESTOCKER)` | Resuelve de forma atómica e idempotente (`client_mutation_id`) un aviso `L1_RESTOCKER` (`IN_PROGRESS` $\rightarrow$ `RESOLVED`, `RF-03.1.2`), validando que la máquina no esté en `sanitary_status = 'QUARANTINE'` (`409 SANITARY_L1_RESOLUTION_BLOCKED`, `QA-CONST-01`, `Art. II`), sellando `root_cause_id`, `standard_solution_id` y sus 5 snapshots (Módulo 25), exigiendo `resolution_diagnosis` $\ge 20$ caracteres reales y `resolution_action` $\ge 20$ caracteres reales (`422 RESOLUTION_TEXT_TOO_SHORT`, `Art. V.1`) y cerrando el tramo en `incident_assignments` (`RESOLVED`), sin tocar `technician_van_stocks` ni `incident_service_sheets`. |
| `POST` | `/api/restocker/incidents/{id}/escalate-to-sat` | `InternalAuthMiddleware(RESTOCKER)` | Escala a `L2_SAT` un aviso `L1_RESTOCKER` activo (`Rama A` de `RF-03.2.2`, idempotente por `client_mutation_id`), exigiendo `category`, `workshop_internal_note` ($\ge 20$ caracteres reales con $\ge 3$ bloques alfanuméricos, `is_internal = TRUE`, `Art. V.1` y `Art. V.4`) y `photo_path` ($\le 5\text{ MB}$, `Art. V.5`), cerrando la custodia del reponedor (`ESCALATED_TO_SAT_BY_RESTOCKER`) y asignando automáticamente al mejor Técnico SAT disponible vía `TechnicianAssignmentSuggester` (`RESTOCKER_ESCALATION_TO_SAT`) o dejándolo en `REGISTERED`, con alerta en `notification_outbox`. |
| `POST` | `/api/restocker/machines/{machineId}/escalate-to-sat` | `InternalAuthMiddleware(RESTOCKER)` | Ejecuta **«⚡ Escalar Avería al SAT»** directamente sobre una máquina activa de las zonas cubiertas hoy por el reponedor (`Ramas A, B y C` de `RF-03.2.2`, idempotente por `client_mutation_id`): si hay ticket `L1_RESTOCKER` lo escala (`Rama A`); si la máquina está limpia crea el ticket directamente en `L2_SAT` y lo asigna vía `TechnicianAssignmentSuggester` (`Rama B`); si ya había un ticket `L2_SAT` abierto absorbe la foto y la Nota Interna (`is_internal = TRUE`) sin crear duplicado ni romper la custodia del técnico (`Rama C`, `QA-CONST-02`, `Art. V.2` y `Art. V.3`). |
| `POST` | `/api/restocker/incidents/upload-photo` | `InternalAuthMiddleware(RESTOCKER)` | Sube y valida por magic bytes reales (JPG, PNG, WebP $\le 5\text{ MB}$, `Art. V.5`) la fotografía de evidencia para adjuntar al escalado SAT o a la resolución de Nivel 1. |
| `POST` | `/api/restocker/incidents/conflict-dump` | `InternalAuthMiddleware(RESTOCKER)` | Archiva en `audit_log` (`OFFLINE_RESTOCKER_INCIDENT_CONFLICT_DUMP`, `Art. III.3`) una mutación Offline de Nivel 1 o escalado que recibió rechazo irreversible (`409` / `422`) al sincronizar (`RF-05.1.2`). |
| **Superficie Móvil del Técnico SAT (`RF-04.1`, `RF-05.2.2`, Art. V.4)** | | | |
| `GET` | `/api/technician/my-route` (y listado de paradas SAT) | `InternalAuthMiddleware(TECHNICIAN)` | Extiende cada parada/sede con: (1) metadatos de escalado (`escalated_from_restocker_id`, `escalated_from_restocker_name`, `escalated_from_restocker_code`, `escalated_to_sat_at`) cuando el ticket provino de un reponedor, y (2) el bloque `site_coincidence` listando los Reponedores en turno activo (`ON_SHIFT` / `MEAL_BREAK`) que operan en esa misma sede (`D4`, `Art. V.4`). |
| **Triaje, Despacho Dual y Supervisión en Coordinación (`RF-01.2`, `RF-02`, `RF-05.3`, Art. II, Art. V.3)** | | | |
| `GET` | `/api/coordinator/incidents` | `InternalAuthMiddleware(COORDINATOR)` | Soporta el filtro opcional `?dispatch_level=` (`ALL` \| `L1_RESTOCKER` \| `L2_SAT`) y expone en cada fila `dispatch_level`, `level1_issue_type`, `escalated_from_restocker_id` y `escalated_to_sat_at`. |
| `GET` | `/api/coordinator/incidents/{id}/suggested-restockers` | `InternalAuthMiddleware(COORDINATOR)` | Devuelve el ranking determinista de reponedores de la zona de la sede (`RestockerZoneCoverageService` del Módulo 31, Niveles 1 a 6 con `#1 RECOMENDADO ⭐`), enriquecido con `active_l1_incidents_count` por reponedor (`RF-02.1.1`). |
| `PUT` | `/api/coordinator/incidents/{id}/assign` | `InternalAuthMiddleware(COORDINATOR)` | Extiende la asignación/reasignación aceptando `dispatch_level` opcional (`'L1_RESTOCKER'` \| `'L2_SAT'`): valida que el rol del operario coincida con el nivel (`422 INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL`), que `'TEMPERATURE_COLD'` y `'ELECTRICAL_OFF'` jamás vayan a `'L1_RESTOCKER'` (`422 L1_DISPATCH_FORBIDDEN_FOR_CATEGORY`, `Art. II`) y registra el tramo en `incident_assignments` (`RF-02.1.2`). |
| **Entrada de Avisos QR y Portal de Sede (`RF-01.2`, `RF-02.2`, `RF-02.3`, `QA-LIM-01/02`, `QA-CONST-03`, Art. V.4, Art. V.6)** | | | |
| `POST` | `/api/public/qr/{code}/report` y `POST /api/portal/incidents` | Público / `PortalAuthMiddleware` | Aceptan el parámetro opcional `level1_issue_type`: pre-clasifican el ticket nuevo en `dispatch_level = 'L1_RESTOCKER'` cuando es elegible (`RF-01.2`), ejecutan el auto-escalado a `L2_SAT` en absorción QR grave (`RF-02.2`, `QA-LIM-01`), y mantienen opacidad total en la respuesta (`QA-CONST-03`, `Art. V.4`). |

---

## 2. Contrato de Esquema de Base de Datos (PostgreSQL 16+ / Supabase)

La persistencia del Módulo 36 se materializa en la migración incremental `database/supabase_migrations/032_restocker_sat_synergy.sql` y converge 1:1 en `database/supabase_init.sql` y `database/supabase_seeds.sql`, manteniendo el esquema en **49 tablas** (**Art. IV**) y elevando el catálogo de **42 a 44 tipos `ENUM` nativos** (`incident_dispatch_level` #43 y `level1_issue_type` #44, pasando de 48 a 50 columnas `ENUM`).

### 2.1. Nuevos Tipos Enumerados #43 y #44 y Ampliación de Tipos Existentes (`RF-01.1`, `QA-AMB-02`)

```sql
-- Tipo nativo #43: Nivel operativo de despacho del expediente (D1, RF-01.1)
DO $do$
BEGIN
    CREATE TYPE incident_dispatch_level AS ENUM (
        'L1_RESTOCKER',
        'L2_SAT'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Tipo nativo #44: Subtipo operativo de incidencia leve de Nivel 1 (D2, QA-AMB-01)
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

-- Ampliación de comment_author_type (QA-AMB-02): autoría propia del Reponedor en Notas Internas de Taller
ALTER TYPE comment_author_type ADD VALUE IF NOT EXISTS 'RESTOCKER';

-- Ampliación de fuentes y motivos de custodia en incident_assignments (QA-AMB-02, Módulo 26)
ALTER TYPE incident_assignment_source ADD VALUE IF NOT EXISTS 'RESTOCKER_ESCALATION_TO_SAT';
ALTER TYPE incident_assignment_source ADD VALUE IF NOT EXISTS 'RESTOCKER_SHIFT_HANDOVER';
ALTER TYPE incident_assignment_source ADD VALUE IF NOT EXISTS 'QR_AUTO_ESCALATION_TO_SAT';
ALTER TYPE incident_assignment_source ADD VALUE IF NOT EXISTS 'WARRANTY_REOPEN_ESCALATION_TO_SAT';

ALTER TYPE incident_unassignment_reason_type ADD VALUE IF NOT EXISTS 'ESCALATED_TO_SAT_BY_RESTOCKER';
ALTER TYPE incident_unassignment_reason_type ADD VALUE IF NOT EXISTS 'QR_AUTO_ESCALATED_TO_SAT';
ALTER TYPE incident_unassignment_reason_type ADD VALUE IF NOT EXISTS 'RESTOCKER_SHIFT_HANDOVER';
```

### 2.2. Evolución de Tabla 4 · `incidents` y Tabla 34 · `offline_sync_mutations` (`RF-01.1`, `RF-05.1`, `QA-LIM-03`, `QA-CONST-01`, Art. II)

```sql
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

    -- Guarda constitucional Art. II y QA-LIM-03:
    -- 1. Jamás L1_RESTOCKER en TEMPERATURE_COLD ni ELECTRICAL_OFF.
    -- 2. Jamás PENDING_PARTS ni pending_parts_reason en L1_RESTOCKER.
    -- 3. Coherencia entre escalated_from_restocker_id y escalated_to_sat_at.
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
                    )
                )
                AND (
                    (escalated_from_restocker_id IS NULL AND escalated_to_sat_at IS NULL)
                    OR (escalated_from_restocker_id IS NOT NULL AND escalated_to_sat_at IS NOT NULL)
                )
            );
    END IF;
END $do$;

CREATE INDEX IF NOT EXISTS idx_incidents_dispatch_level_status
    ON incidents (dispatch_level, status, assigned_technician_id)
    WHERE deleted_at IS NULL;

-- Ampliación de Tabla 34 offline_sync_mutations para soportar las 3 mutaciones de Nivel 1 / Escalado del Reponedor (RF-05.1)
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
```

### 2.3. Semillas Maestras de Taxonomía de Nivel 1 (`RF-01.3`, `QA-AMB-01`)

| `level1_issue_type` | `category` (`incident_category`) | `root_causes.code` | `root_causes.name` | `standard_solutions.code` | `standard_solutions.name` |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `STOCKOUT_EMPTY_SLOT` | `PRODUCT_JAM` | `RC-L1-STOCKOUT` | Agotamiento de producto en carril de selección | `SS-L1-RESTOCK-SLOT` | Reposición de producto en carril y verificación de entrega |
| `MINOR_SPIRAL_JAM` | `PRODUCT_JAM` | `RC-L1-SPIRAL-RELEASE` | Envase mal posicionado o trabado leve en boca de espiral | `SS-L1-CLEAR-SPIRAL` | Liberación manual de envase en espiral y reacomodo de carril |
| `EMPTY_CHANGE_TUBES` | `PAYMENT_SYSTEM` | `RC-L1-EMPTY-TUBES` | Tubos de cambio del monedero sin suficiente moneda fraccionaria | `SS-L1-REFILL-TUBES` | Recarga de tubos de cambio y comprobación de devolución |
| `MISSING_CONSUMABLES` | `OTHER` | `RC-L1-CONSUMABLES` | Falta de vasos, azúcar o paletinas en dispensador | `SS-L1-REFILL-CUPS-SUGAR` | Reposición de consumibles (vasos/azúcar/paletinas) y limpieza de tolva |

---

## 3. Contratos JSON de Petición y Respuesta

### 3.1. Escalado Técnico In Situ (`POST /api/restocker/machines/{machineId}/escalate-to-sat` y `POST /api/restocker/incidents/{id}/escalate-to-sat`)

**Request Body:**
```json
{
  "category": "PRODUCT_JAM",
  "workshop_internal_note": "Al abrir la máquina para reponer el carril 24 se comprueba que el motorreductor de la espiral está quemado y huele a bobina recalentada.",
  "photo_path": "/uploads/incidents/evidence_20261118_motor24.jpg",
  "client_mutation_id": "mut-l1-esc-20261118-0001",
  "client_occurred_at": "2026-11-18T11:30:00+01:00"
}
```

**Response `200 OK` / `201 Created`:**
```json
{
  "success": true,
  "data": {
    "incident_id": 42,
    "ticket_code": "INC-2026-0042",
    "machine_id": 5,
    "machine_code": "VEND-MAD-005",
    "dispatch_level": "L2_SAT",
    "status": "ASSIGNED",
    "urgency": "MEDIUM",
    "escalation_outcome": "ESCALATED_FROM_L1",
    "merged_into_existing_sat_ticket": false,
    "assigned_sat_technician": {
      "id": 2,
      "name": "Carlos Mendoza",
      "operator_code": "TEC-01"
    },
    "escalated_from_restocker_id": 7,
    "escalated_to_sat_at": "2026-11-18T11:30:00+01:00",
    "idempotent_replay": false
  },
  "message": "Avería escalada al equipo de Técnicos SAT con éxito."
}
```
*(Valores posibles de `escalation_outcome`: `'ESCALATED_FROM_L1'`, `'CREATED_NEW_SAT_TICKET'`, `'MERGED_INTO_EXISTING_SAT_TICKET'`).*

### 3.2. Resolución de Nivel 1 In Situ (`POST /api/restocker/incidents/{id}/resolve`)

**Request Body:**
```json
{
  "root_cause_id": 17,
  "standard_solution_id": 35,
  "resolution_diagnosis": "Se verifica que la bolsa del carril 14 quedó girada apoyada contra el cristal frontal impidiendo su caída al cajón.",
  "resolution_action": "Se libera manualmente el envase en la espiral 14, se reacomoda la fila completa y se realiza prueba de giro correcta.",
  "photo_path": "/uploads/incidents/l1_res_20261118.jpg",
  "client_mutation_id": "mut-l1-res-20261118-0002",
  "client_occurred_at": "2026-11-18T11:45:00+01:00"
}
```

### 3.3. Bloque `site_coincidence` en `/api/restocker/my-route` y `/api/technician/my-route` (`RF-04.1`, `D4`)

```json
{
  "location_id": 1,
  "location_name": "Hospital Universitario Central",
  "coinciding_operators": [
    {
      "user_id": 2,
      "name": "Carlos Mendoza",
      "operator_code": "TEC-01",
      "role": "TECHNICIAN",
      "shift_status": "ON_SHIFT",
      "phone": "+34600111222",
      "activity_summary": "1 avería SAT activa en esta sede (VEND-MAD-001)"
    }
  ]
}
```

---

## 4. Catálogo Determinista de Códigos de Error HTTP

| Código HTTP | `error_code` | Condición de Disparo |
| :---: | :--- | :--- |
| `403` | `MACHINE_OUTSIDE_RESTOCKER_ZONES` | El reponedor intenta operar o escalar sobre una máquina cuya sede no pertenece a sus zonas operativas cubiertas hoy. |
| `403` | `INCIDENT_NOT_ASSIGNED_TO_RESTOCKER` | El reponedor intenta iniciar o resolver un ticket que no está en `dispatch_level = 'L1_RESTOCKER'` o está asignado a otro operario. |
| `409` | `SANITARY_L1_RESOLUTION_BLOCKED` | El reponedor intenta resolver un aviso `L1_RESTOCKER` sobre una máquina en `sanitary_status = 'QUARANTINE'` (`QA-CONST-01`, `Art. II`). |
| `409` | `L1_PENDING_PARTS_FORBIDDEN` | Intento de pasar a `PENDING_PARTS` o declarar repuestos sobre un ticket en `dispatch_level = 'L1_RESTOCKER'` (`QA-LIM-03`). |
| `409` | `SHIFT_CLOSE_BLOCKED_IN_PROGRESS_L1` | El reponedor intenta cerrar su turno mientras mantiene algún aviso `L1_RESTOCKER` en estado `'IN_PROGRESS'` (`C1`). |
| `409` | `INVALID_INCIDENT_STATE_TRANSITION` | El ticket ya estaba cerrado/cancelado o cambió a `L2_SAT` antes de procesar la mutación de Nivel 1. |
| `422` | `L1_DISPATCH_FORBIDDEN_FOR_CATEGORY` | Intento de clasificar o asignar en `L1_RESTOCKER` un ticket con `category IN ('TEMPERATURE_COLD', 'ELECTRICAL_OFF')` (`Art. II`). |
| `422` | `INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL` | Intento de asignar un `TECHNICIAN` a un ticket `L1_RESTOCKER` o un `RESTOCKER` a un ticket `L2_SAT` (`QA-CON-01`). |
| `422` | `ESCALATION_INTERNAL_NOTE_REQUIRED` | La `workshop_internal_note` del escalado tiene menos de 20 caracteres reales o menos de 3 bloques alfanuméricos (`Art. V.1`). |
| `422` | `ESCALATION_PHOTO_REQUIRED` | Falta la fotografía de evidencia obligatoria al escalar al SAT o el archivo no supera la validación de magic bytes / $5\text{ MB}$ (`Art. V.5`). |
| `422` | `RESOLUTION_TEXT_TOO_SHORT` | `resolution_diagnosis` o `resolution_action` tiene menos de 20 caracteres reales (`Art. V.1`). |
