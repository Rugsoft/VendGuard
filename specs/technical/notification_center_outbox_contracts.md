# ESPECIFICACIÓN TÉCNICA · CONTRATOS DE DATOS Y API DEL CENTRO DE NOTIFICACIONES MULTICANAL Y PATRÓN OUTBOX
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `14-notification-center-outbox`  
**Documento:** `specs/technical/notification_center_outbox_contracts.md`  
**Referencia Funcional:** [`specs/functional/notification_center_outbox_spec.md`](../functional/notification_center_outbox_spec.md) (RF-01 a RF-07, RNF-01 a RNF-06)  
**Motor de Persistencia:** PostgreSQL 16+ (Supabase) · Motor Único (Módulo 13)  
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`) + Worker CLI (`bin/send_notifications.php`)  
**Conformidad Constitucional:** Artículos I, II, III (3.1, 3.2, 3.3), IV, V (5.1, 5.2, 5.4, 5.6) y VI  

---

## 1. Visión General de Endpoints y Superficies del Módulo

| Método | Ruta / Comando | Rol / Seguridad | Propósito Operativo |
| :--- | :--- | :--- | :--- |
| **Supervisión y Bandeja Outbox (Coordinación)** | | | |
| `GET` | `/api/coordinator/notifications/outbox` | `InternalAuthMiddleware(COORDINATOR)` | Contadores KPI y listado paginado/filtrable por los 6 estados canónicos, canal, prioridad y código de ticket (RF-06.3) |
| `POST` | `/api/coordinator/notifications/outbox/{id}/retry` | `InternalAuthMiddleware(COORDINATOR)` | Reintento manual individual de un envío en `FAILED_PERMANENT` refrescando el destinatario vigente (RF-06.4) |
| `POST` | `/api/coordinator/notifications/outbox/retry-all-failed` | `InternalAuthMiddleware(COORDINATOR)` | Reintento manual masivo de todos los envíos en `FAILED_PERMANENT` refrescando destinatarios vigentes (RF-06.4) |
| `POST` | `/api/coordinator/notifications/outbox/{id}/cancel` | `InternalAuthMiddleware(COORDINATOR)` | Cancelación manual a `CANCELLED_OBSOLETE` de un envío en `PENDING`, `PENDING_RETRY` o `FAILED_PERMANENT` (RF-06.4) |
| **Configuración de Canal de Emergencias y Guardia (Coordinación)** | | | |
| `GET` | `/api/coordinator/notifications/settings` | `InternalAuthMiddleware(COORDINATOR)` | Obtiene la configuración del Webhook corporativo de emergencias, último resultado de prueba y técnicos de guardia activos (RF-06.5) |
| `PUT` | `/api/coordinator/notifications/settings` | `InternalAuthMiddleware(COORDINATOR)` | Actualiza nombre, URL (`https://` público validado anti-SSRF) y estado activo del Webhook corporativo (RF-06.5) |
| `POST` | `/api/coordinator/notifications/settings/test-webhook` | `InternalAuthMiddleware(COORDINATOR)` | Prueba interactiva directa (máx. 5 s) del Webhook corporativo con registro de resultado y auditoría (RF-01.2, RF-06.5) |
| `PATCH` | `/api/coordinator/users/{id}/on-call` | `InternalAuthMiddleware(COORDINATOR)` | Activa o desactiva la condición `is_on_call` sobre un Técnico de Campo en activo (RF-02.5) |
| **Extensiones en Endpoints Existentes (Sedes, Usuarios, Reportes)** | | | |
| `GET/POST/PATCH` | `/api/coordinator/locations[/{id}]` | `InternalAuthMiddleware(COORDINATOR)` | Incorpora `contact_email` obligatorio y editable en la ficha maestra de Sede (RF-02.1) |
| `GET` | `/api/coordinator/users` | `InternalAuthMiddleware(COORDINATOR)` | Expone el booleano `is_on_call` en cada usuario y limpia `is_on_call = false` al desactivar un técnico (RF-02.5, RF-02.6) |
| `POST` | `/api/incidents` | `SiteAuthMiddleware` | Acepta `reporter_email` opcional validado; aplica doble destinatario si difiere de `locations.contact_email` (RF-02.2, RF-02.3) |
| `POST` | `/api/qr/report` | Público (Escaneo QR) | Acepta `reporter_email` opcional validado; en concurrencia/ticket abierto envía solo acuse puntual sin suplantar (RF-02.2, RF-02.4, RF-05.9) |
| **Procesamiento Batch en Segundo Plano (Cron HTTP y Worker CLI)** | | | |
| `POST` | `/api/cron/notifications-dispatch` | `CronAuthMiddleware` | Rescata envíos atrapados (`> 5 min`), evalúa umbrales SLA 2h/3h y despacha la cola por prioridad sanitaria estricta (RF-01.3, RF-01.4, RF-04) |
| `CLI` | `php bin/send_notifications.php` | Ejecución local / contenedor | Equivalente CLI idempotente del despachador y vigilante de inminencia de SLA 2h/3h |

---

## 2. Contrato de Esquema de Base de Datos (PostgreSQL / Supabase)

De acuerdo con el contrato de motor único (`specs/technical/schema_correspondence_contract.md` y EARS-02.4), los cambios de esquema se materializan tanto en la migración incremental `database/supabase_migrations/002_notification_center_outbox.sql` como en el DDL base `database/supabase_init.sql` y las semillas `database/supabase_seeds.sql`.

### 2.1. Nuevos Tipos Enumerados Nativos (`ENUM`) y Extensión de Auditoría

```sql
-- 1. Canal de salida de la notificación
DO $do$
BEGIN
    CREATE TYPE notification_channel AS ENUM ('EMAIL', 'WEBHOOK');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 2. Prioridad de despacho en cola (Art. II: prevalencia sanitaria estricta)
DO $do$
BEGIN
    CREATE TYPE notification_priority AS ENUM ('CRITICAL_SANITARY', 'STANDARD');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 3. Los 6 estados canónicos de la bandeja de salida (RF-01.1 / Resolución QA 6)
DO $do$
BEGIN
    CREATE TYPE notification_status AS ENUM (
        'PENDING',
        'PROCESSING',
        'PENDING_RETRY',
        'SENT',
        'FAILED_PERMANENT',
        'CANCELLED_OBSOLETE'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 4. Catálogo cerrado de plantillas tipificadas (RF-03, RF-04, RF-05)
DO $do$
BEGIN
    CREATE TYPE notification_template AS ENUM (
        'COLD_CHAIN_IMMEDIATE_ALERT',
        'SANITARY_QUARANTINE_TRIGGERED',
        'SITE_SANITARY_QUARANTINE_NOTICE',
        'SANITARY_SWEEP_ESCALATED',
        'SLA_IMMINENCE_2H_UNASSIGNED',
        'SLA_IMMINENCE_3H_UNSTARTED',
        'INCIDENT_OPENED_SITE',
        'INCIDENT_OPENED_CITIZEN_QR',
        'TECHNICIAN_ASSIGNED',
        'TECHNICIAN_UNASSIGNED',
        'PENDING_INFO_SITE_REQUEST',
        'PENDING_INFO_SITE_REPLY',
        'INCIDENT_RESOLVED_SITE',
        'INCIDENT_RESOLVED_CITIZEN_QR',
        'INCIDENT_REOPENED_COORDINATOR',
        'CHRONIC_BREAKDOWN_ALERT',
        'INCIDENT_CANCELLED_SITE',
        'INCIDENT_CANCELLED_CITIZEN_QR',
        'CITIZEN_QR_OBSERVATION_ACK'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 5. Extensión de audit_entity_type para trazabilidad de notificaciones y canales (RF-07.3)
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'NOTIFICATION_OUTBOX';
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'NOTIFICATION_CHANNEL_SETTING';
```

### 2.2. Alteraciones sobre Tablas Existentes (`locations`, `users`, `incidents`)

```sql
-- 1. Correo oficial de contacto en locations (RF-02.1, Resolución QA 2)
ALTER TABLE locations
    ADD COLUMN IF NOT EXISTS contact_email VARCHAR(150) NOT NULL DEFAULT 'recepcion@sede.vendguard.es';

-- Backfill determinista para sedes preexistentes que conserven el valor por defecto genérico
UPDATE locations
   SET contact_email = 'contacto.' || lower(regexp_replace(site_code_key, '[^a-z0-9]+', '-', 'g')) || '@sede.vendguard.es'
 WHERE contact_email = 'recepcion@sede.vendguard.es';

-- 2. Indicador de Técnico de Guardia en users (RF-02.5, RF-02.6)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_on_call BOOLEAN NOT NULL DEFAULT FALSE;

-- Restricción de integridad: solo un TECHNICIAN activo puede tener is_on_call = TRUE
DO $do$
BEGIN
    ALTER TABLE users
        ADD CONSTRAINT chk_users_on_call_active_tech CHECK (
            is_on_call = FALSE OR (role = 'TECHNICIAN' AND is_active = TRUE)
        );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

CREATE INDEX IF NOT EXISTS idx_users_on_call ON users (role, is_active, is_on_call)
    WHERE is_on_call = TRUE;

-- 3. Correo opcional del informador y marcas de control de ciclo sanitario en incidents (RF-02.2, RF-03.1, RF-04.4, RF-04.5)
ALTER TABLE incidents
    ADD COLUMN IF NOT EXISTS reporter_email VARCHAR(150),
    ADD COLUMN IF NOT EXISTS cycle_started_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS cold_alert_sent_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS sla_2h_alert_sent_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS sla_3h_alert_sent_at TIMESTAMPTZ;

UPDATE incidents
   SET cycle_started_at = COALESCE(reopened_at, created_at)
 WHERE cycle_started_at IS NULL;

ALTER TABLE incidents
    ALTER COLUMN cycle_started_at SET NOT NULL,
    ALTER COLUMN cycle_started_at SET DEFAULT CURRENT_TIMESTAMP;
```

### 2.3. Tabla 21 · `notification_outbox` (Bandeja de Salida Transaccional)

```sql
CREATE TABLE IF NOT EXISTS notification_outbox (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY,
    incident_id             BIGINT,
    channel                 notification_channel NOT NULL,
    priority                notification_priority NOT NULL DEFAULT 'STANDARD',
    template                notification_template NOT NULL,
    recipient               VARCHAR(255) NOT NULL,
    recipient_role          VARCHAR(32) NOT NULL,
    recipient_user_id       BIGINT,
    recipient_location_id   BIGINT,
    subject                 VARCHAR(255) NOT NULL,
    payload_json            JSONB NOT NULL,
    dedup_key               VARCHAR(160),
    status                  notification_status NOT NULL DEFAULT 'PENDING',
    attempts                SMALLINT NOT NULL DEFAULT 0,
    max_attempts            SMALLINT NOT NULL DEFAULT 5,
    next_attempt_at         TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processing_started_at   TIMESTAMPTZ,
    processed_at            TIMESTAMPTZ,
    last_error              TEXT,
    obsolete_reason         TEXT,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              TIMESTAMPTZ,
    CONSTRAINT pk_notification_outbox PRIMARY KEY (id),
    CONSTRAINT uq_notification_outbox_dedup_key UNIQUE (dedup_key),
    CONSTRAINT fk_outbox_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_outbox_recipient_user FOREIGN KEY (recipient_user_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_outbox_recipient_location FOREIGN KEY (recipient_location_id)
        REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

-- Índice de despacho por prioridad estricta (Art. II: CRITICAL_SANITARY siempre antes que STANDARD)
CREATE INDEX IF NOT EXISTS idx_outbox_dispatch_queue
    ON notification_outbox (status, priority, next_attempt_at, id)
    WHERE status IN ('PENDING', 'PENDING_RETRY', 'PROCESSING');

CREATE INDEX IF NOT EXISTS idx_outbox_incident ON notification_outbox (incident_id, status);
CREATE INDEX IF NOT EXISTS idx_outbox_status_created ON notification_outbox (status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_outbox_channel_status ON notification_outbox (channel, status);

-- Blindaje Constitucional Art. III.1: Prohibición absoluta de borrado físico
CREATE OR REPLACE TRIGGER trg_no_delete_notification_outbox
    BEFORE DELETE ON notification_outbox
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_notification_outbox
    BEFORE UPDATE ON notification_outbox
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
```

### 2.4. Tabla 22 · `notification_channel_settings` (Configuración del Canal Corporativo de Emergencias)

```sql
CREATE TABLE IF NOT EXISTS notification_channel_settings (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY,
    webhook_name        VARCHAR(100) NOT NULL DEFAULT 'Canal Central de Emergencias',
    webhook_url         VARCHAR(500),
    is_webhook_enabled  BOOLEAN NOT NULL DEFAULT FALSE,
    last_tested_at      TIMESTAMPTZ,
    last_test_status    VARCHAR(32),
    last_test_error     TEXT,
    updated_by          BIGINT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at          TIMESTAMPTZ,
    CONSTRAINT pk_notification_channel_settings PRIMARY KEY (id),
    CONSTRAINT chk_webhook_url_https CHECK (
        webhook_url IS NULL OR webhook_url ~* '^https://[a-z0-9.-]+\.[a-z]{2,}(:[0-9]+)?(/.*)?$'
    ),
    CONSTRAINT fk_notif_settings_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE OR REPLACE TRIGGER trg_no_delete_notification_channel_settings
    BEFORE DELETE ON notification_channel_settings
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_notification_channel_settings
    BEFORE UPDATE ON notification_channel_settings
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
```

---

## 3. Reglas de Encolado Transaccional, Prioridades y Deduplicación (`dedup_key`)

Toda notificación se inserta **dentro de la misma transacción de base de datos** (`TransactionManagerInterface::transactional()`) que persiste el cambio de negocio, garantizando atomicidad absoluta (RF-01.1, RNF-01).

### 3.1. Tabla de Correspondencia de Eventos, Plantillas, Prioridades y Claves de Deduplicación

| Evento Disparador | `template` | `priority` | `channel` | Destinatarios | `dedup_key` (Idempotencia por ciclo) |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **1. Alerta Inmediata de Frío** (apertura, reapertura o reclasificación a `CRITICAL` en máquina `PERISHABLE_FOOD` / `COMBO`, RF-03.1) | `COLD_CHAIN_IMMEDIATE_ALERT` | `CRITICAL_SANITARY` | `EMAIL` + `WEBHOOK` | Todos los `COORDINATOR` activos + Técnicos `is_on_call = true` (o técnico asignado si ya tiene) + Webhook (si activo) | `inc:{id}:cyc:{cycle_ts}:cold_alert:{channel}:{dest_id}` |
| **2. Inminencia SLA > 2h sin asignar** (máquina bajo vigilancia sanitaria en `REGISTERED`/`REOPENED`, RF-04.1) | `SLA_IMMINENCE_2H_UNASSIGNED` | `CRITICAL_SANITARY` | `EMAIL` + `WEBHOOK` | Todos los `COORDINATOR` activos + Webhook (si activo) | `inc:{id}:cyc:{cycle_ts}:sla_2h:{channel}:{dest_id}` |
| **3. Inminencia SLA > 3h sin iniciar** (máquina bajo vigilancia sanitaria con `started_at IS NULL`, RF-04.2) | `SLA_IMMINENCE_3H_UNSTARTED` | `CRITICAL_SANITARY` | `EMAIL` + `WEBHOOK` | Todos los `COORDINATOR` activos + Técnico asignado (si existe) + Webhook (si activo) | `inc:{id}:cyc:{cycle_ts}:sla_3h:{channel}:{dest_id}` |
| **4. Activación de Cuarentena Sanitaria 4h** (reloj biológico continuo 4h, RF-03.3) | `SANITARY_QUARANTINE_TRIGGERED` | `CRITICAL_SANITARY` | `EMAIL` + `WEBHOOK` | Todos los `COORDINATOR` activos + Técnico asignado (o de guardia) + Webhook (si activo) | `inc:{id}:quarantine_internal:{channel}:{dest_id}` |
| **5. Aviso a Sede de Cuarentena 4h** (RF-03.3.2) | `SITE_SANITARY_QUARANTINE_NOTICE` | `CRITICAL_SANITARY` | `EMAIL` | Buzón oficial de la Sede (`locations.contact_email`) | `inc:{id}:quarantine_site:{location_id}` |
| **6. Escalado de Contención del Barrido Sanitario** (3 pasadas consecutivas o > 5 en parque, RF-03.4) | `SANITARY_SWEEP_ESCALATED` | `CRITICAL_SANITARY` | `EMAIL` + `WEBHOOK` | Todos los `COORDINATOR` activos + Webhook (si activo) | `sweep:esc:{incident_or_pass_key}:{channel}:{dest_id}` |
| **7. Confirmación de Apertura a Sede** (RF-05.1.1, RF-02.3) | `INCIDENT_OPENED_SITE` | `STANDARD` | `EMAIL` | `locations.contact_email` (+ `reporter_email` si el reporte vino del Portal de Sede y difiere del oficial) | `NULL` (única por acto de creación) |
| **8. Confirmación de Apertura a Ciudadano QR** (RF-05.1.2) | `INCIDENT_OPENED_CITIZEN_QR` | `STANDARD` | `EMAIL` | `incidents.reporter_email` (solo si el reporte vino por QR y no está vacío) | `NULL` |
| **9. Asignación / Reasignación a Técnico Entrante** (RF-05.2) | `TECHNICIAN_ASSIGNED` | `STANDARD` | `EMAIL` | `users.email` del técnico asignado | `NULL` |
| **10. Desasignación a Técnico Saliente** (en reasignación, RF-05.2) | `TECHNICIAN_UNASSIGNED` | `STANDARD` | `EMAIL` | `users.email` del técnico reemplazado | `NULL` |
| **11. Solicitud de Acceso / Información a Sede** (pausa a `PENDING_INFO`, RF-05.3) | `PENDING_INFO_SITE_REQUEST` | `STANDARD` | `EMAIL` | Exclusivamente `locations.contact_email` (y `reporter_email` si el ticket lo abrió la Sede; **jamás** Ciudadano QR) | `NULL` |
| **12. Respuesta de Sede en Pausa** (comentario público de Sede en `PENDING_INFO`, RF-05.4) | `PENDING_INFO_SITE_REPLY` | `STANDARD` | `EMAIL` | Técnico asignado (o Coordinadores si no hay técnico). **Agrupación de ráfagas:** si existe fila `PENDING` para el mismo `(incident_id, template, recipient_user_id)`, se hace `UPDATE` del `payload_json` en vez de `INSERT` | `NULL` (controlado por consulta de fila `PENDING` activa) |
| **13. Confirmación de Resolución a Sede** (RF-05.5) | `INCIDENT_RESOLVED_SITE` | `STANDARD` | `EMAIL` | `locations.contact_email` (+ `reporter_email` de sede si difiere) | `NULL` |
| **14. Confirmación de Resolución a Ciudadano QR** (RF-05.5) | `INCIDENT_RESOLVED_CITIZEN_QR` | `STANDARD` | `EMAIL` | `incidents.reporter_email` (si el ticket nació por QR con email) | `NULL` |
| **15. Reapertura en Garantía a Coordinación** (RF-05.7) | `INCIDENT_REOPENED_COORDINATOR` | `STANDARD` | `EMAIL` | Todos los `COORDINATOR` activos | `NULL` |
| **16. Alerta de Avería Crónica (3.ª reincidencia)** (RF-05.7) | `CHRONIC_BREAKDOWN_ALERT` | `CRITICAL_SANITARY` | `EMAIL` + `WEBHOOK` | Todos los `COORDINATOR` activos + Webhook (si activo) | `inc:{id}:chronic:{channel}:{dest_id}` |
| **17. Cancelación de Expediente a Sede** (incl. bloqueo tras 72h hábiles, RF-05.8.1) | `INCIDENT_CANCELLED_SITE` | `STANDARD` | `EMAIL` | `locations.contact_email` (+ `reporter_email` de sede si difiere) | `NULL` |
| **18. Cancelación de Expediente a Ciudadano QR** (RF-05.8.2) | `INCIDENT_CANCELLED_CITIZEN_QR` | `STANDARD` | `EMAIL` | `incidents.reporter_email` (si el ticket nació por QR con email) | `NULL` |
| **19. Acuse a Segundo Ciudadano Concurrente por QR** (RF-05.9) | `CITIZEN_QR_OBSERVATION_ACK` | `STANDARD` | `EMAIL` | Correo facilitado por el segundo ciudadano (sin alterar `incidents.reporter_email`) | `NULL` |

### 3.2. Blindaje de Privacidad en Origen (`payload_json`) — Artículo V.4

El servicio de construcción de payloads (`NotificationPayloadSanitizer`) aplica las siguientes reglas innegociables **antes** de serializar `payload_json` en `notification_outbox`:
1. **Para destinatarios con `recipient_role IN ('LOCATION', 'CITIZEN_QR')`:**
   * Prohibido incluir `resolution_diagnosis`, `resolution_action`, `is_internal`, notas internas, importes o costes de repuestos (`cost_snapshot`), ni el nombre, email o teléfono personal del técnico.
   * Si se referencia la intervención técnica, el único identificador permitido es `"Servicio Técnico Oficial · Operador #" . ($operatorCode ?: sprintf('%02d', $technicianId))`.
2. **Adicionalmente, para destinatarios con `recipient_role = 'CITIZEN_QR'`:**
   * Prohibido generar notificaciones de tipo `PENDING_INFO_SITE_REQUEST` o `SITE_SANITARY_QUARANTINE_NOTICE`.
   * Prohibido incluir `pending_info_reason_category`, `pending_info_reason_text`, `cancellation_reason`, `description` libre escrita por terceros o `reporter_name`/`reporter_phone`.
   * El enlace de seguimiento apunta exclusivamente a la ruta pública acotada de QR: `/?qr={machine_code}`.
3. **Para destinatarios con `recipient_role = 'LOCATION'`:**
   * El enlace de seguimiento apunta al Portal de Sede: `/?site={site_code}&ticket={ticket_code}`.
   * En `PENDING_INFO_SITE_REQUEST` sí se incluyen `reason_category_label` y `reason_text` ($\ge 20$ caracteres) para que el responsable del edificio pueda desbloquear el acceso (Módulo 11 RF-05.1).

---

## 4. Contratos Detallados de Endpoints REST

### 4.1. `GET /api/coordinator/notifications/outbox`
Consulta los contadores de estado y el historial de la bandeja de salida para el panel de supervisión del Coordinador (RF-06.3).

* **Autenticación:** `InternalAuthMiddleware(UserRole::COORDINATOR)`
* **Parámetros Query (opcionales):**
  * `status`: `ALL` (default) | `PENDING` | `PROCESSING` | `PENDING_RETRY` | `SENT` | `FAILED_PERMANENT` | `CANCELLED_OBSOLETE`
  * `channel`: `ALL` (default) | `EMAIL` | `WEBHOOK`
  * `priority`: `ALL` (default) | `CRITICAL_SANITARY` | `STANDARD`
  * `ticket_code`: búsqueda parcial o exacta por código de ticket (ej. `TICK-2026`)
  * `limit`: entero (`1..200`, default `50`)
  * `offset`: entero (`>= 0`, default `0`)

#### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "data": {
    "summary": {
      "in_queue_count": 3,
      "pending_count": 2,
      "processing_count": 0,
      "pending_retry_count": 1,
      "sent_last_24h_count": 48,
      "failed_permanent_count": 1,
      "cancelled_obsolete_count": 4,
      "has_permanent_failures_alert": true,
      "active_on_call_technicians_count": 2
    },
    "items": [
      {
        "id": 901,
        "incident_id": 142,
        "ticket_code": "TICK-2026-00142",
        "channel": "EMAIL",
        "priority": "CRITICAL_SANITARY",
        "priority_label": "Crítica Sanitaria (Art. II)",
        "template": "COLD_CHAIN_IMMEDIATE_ALERT",
        "template_label": "Alerta Inmediata de Frío",
        "recipient": "contacto.sede-bcn-01@sede.vendguard.es",
        "recipient_role": "LOCATION",
        "subject": "[URGENTE FRÍO] Avería registrada TICK-2026-00142 · Máquina VEN-BCN-001",
        "status": "FAILED_PERMANENT",
        "status_label": "Fallo definitivo",
        "attempts": 5,
        "max_attempts": 5,
        "next_attempt_at": null,
        "processing_started_at": null,
        "processed_at": "2026-10-16T19:40:00+02:00",
        "last_error": "SMTP 550 5.1.1 <malformed@invalid>: Recipient address rejected",
        "obsolete_reason": null,
        "can_retry": true,
        "can_cancel": true,
        "created_at": "2026-10-16T18:10:00+02:00",
        "updated_at": "2026-10-16T19:40:00+02:00"
      }
    ],
    "pagination": {
      "total": 56,
      "limit": 50,
      "offset": 0
    }
  }
}
```

---

### 4.2. `POST /api/coordinator/notifications/outbox/{id}/retry`
Relanza manualmente una notificación que se encuentra en estado `FAILED_PERMANENT` (o `PENDING_RETRY`), actualizando dinámicamente su destinatario si pertenece a una Sede (`locations.contact_email`) o al Canal Corporativo de Emergencias (`notification_channel_settings.webhook_url`) (RF-06.4, Resolución QA 7).

* **Autenticación:** `InternalAuthMiddleware(UserRole::COORDINATOR)`
* **Reglas de Validación:**
  * Si la notificación no existe: `404 NOT_FOUND`.
  * Si su estado no es `FAILED_PERMANENT` ni `PENDING_RETRY`: `409 INVALID_OUTBOX_STATUS_FOR_RETRY`.
  * Si `recipient_location_id IS NOT NULL`, actualiza `recipient` con el `contact_email` actual de esa sede en `locations`.
  * Si `channel = 'WEBHOOK'`, verifica que exista `webhook_url` válida en `notification_channel_settings` y actualiza `recipient` con dicha URL; si el Webhook no tiene URL configurada, devuelve `422 WEBHOOK_URL_NOT_CONFIGURED`.
  * Restablece `status = 'PENDING'`, `attempts = 0`, `next_attempt_at = CURRENT_TIMESTAMP`, `last_error = NULL` y registra evento `NOTIFICATION_MANUAL_RETRY` en `audit_log`.

#### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "data": {
    "id": 901,
    "status": "PENDING",
    "status_label": "Pendiente",
    "recipient": "nuevo.correo@hospitaldelmar.cat",
    "attempts": 0,
    "next_attempt_at": "2026-10-16T20:35:00+02:00"
  },
  "message": "Notificación reencolada correctamente con el destinatario actualizado."
}
```

---

### 4.3. `POST /api/coordinator/notifications/outbox/retry-all-failed`
Relanza en bloque todas las notificaciones actualmente en estado `FAILED_PERMANENT`, refrescando sus destinatarios vigentes (RF-06.4).

#### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "data": {
    "retried_count": 3,
    "retried_ids": [901, 904, 912]
  },
  "message": "Se han reencolado 3 notificaciones fallidas para nuevo intento de envío."
}
```

---

### 4.4. `POST /api/coordinator/notifications/outbox/{id}/cancel`
Cancela manualmente una notificación en estado `PENDING`, `PENDING_RETRY` o `FAILED_PERMANENT`, pasándola a `CANCELLED_OBSOLETE` con auditoría (RF-06.4).

* **Payload de Entrada (opcional):**
  ```json
  {
    "reason": "Cancelada manualmente por Coordinación tras contacto telefónico directo."
  }
  ```
* **Reglas de Validación:**
  * Si el estado es `SENT`, `CANCELLED_OBSOLETE` o `PROCESSING`: `409 INVALID_OUTBOX_STATUS_FOR_CANCEL`.

#### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "data": {
    "id": 901,
    "status": "CANCELLED_OBSOLETE",
    "status_label": "Cancelada por obsolescencia",
    "obsolete_reason": "Cancelada manualmente por Coordinación tras contacto telefónico directo."
  },
  "message": "Envío cancelado correctamente."
}
```

---

### 4.5. `GET /api/coordinator/notifications/settings` y `PUT /api/coordinator/notifications/settings`
Consulta y actualiza la configuración del Canal Corporativo de Emergencias (Webhook) (RF-06.5).

#### Petición `PUT /api/coordinator/notifications/settings`
```json
{
  "webhook_name": "Slack #emergencias-frio",
  "webhook_url": "https://hooks.slack.com/services/T000/B000/X000",
  "is_webhook_enabled": true
}
```
* **Validación Anti-SSRF y Seguridad de Destino (RF-06.5.1, Resolución QA 7):**
  1. Si `is_webhook_enabled = true`, `webhook_url` no puede estar vacío (`422 WEBHOOK_URL_REQUIRED`).
  2. Si `webhook_url` no es vacío, debe comenzar estrictamente por `https://` (`422 INVALID_WEBHOOK_URL_SCHEME`).
  3. El host extraído de `webhook_url` NO puede ser `localhost`, `127.*`, `0.0.0.0`, `::1`, dominios `.local` / `.internal`, ni resolver o coincidir con rangos de IP privados/reservados (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`) (`422 FORBIDDEN_WEBHOOK_INTERNAL_HOST`).
  4. Si `is_webhook_enabled` pasa a `false`, todas las notificaciones de `channel = 'WEBHOOK'` que estén en `PENDING` o `PENDING_RETRY` pasan atómicamente a `CANCELLED_OBSOLETE` con motivo `"Canal Webhook desactivado por Coordinación"` (Caso Límite 5).
  5. Registra el cambio en `audit_log` con `entity_type = 'NOTIFICATION_CHANNEL_SETTING'`.

#### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "data": {
    "webhook_name": "Slack #emergencias-frio",
    "webhook_url": "https://hooks.slack.com/services/T000/B000/X000",
    "is_webhook_enabled": true,
    "last_tested_at": "2026-10-16T20:15:00+02:00",
    "last_test_status": "SUCCESS",
    "last_test_error": null,
    "on_call_technicians": [
      {
        "id": 2,
        "name": "Carlos Ruiz",
        "operator_code": "OP-02",
        "email": "carlos.ruiz@vendguard.es"
      }
    ]
  }
}
```

---

### 4.6. `POST /api/coordinator/notifications/settings/test-webhook`
Ejecuta una verificación directa e interactiva hacia el Webhook corporativo con un límite máximo de espera (*timeout*) de **5 segundos** (RF-01.2, RF-06.5.2).

* **Payload de Entrada (opcional):** Permite probar la URL en edición antes o después de guardar:
  ```json
  {
    "webhook_name": "Slack #emergencias-frio",
    "webhook_url": "https://hooks.slack.com/services/T000/B000/X000"
  }
  ```
* **Comportamiento:**
  * Valida las reglas anti-SSRF y `https://` de §4.5 (si no las cumple, devuelve `422`).
  * Envía un mensaje JSON de prueba identificado como `[PRUEBA DE CONEXIÓN · VENDGUARD]` con timeout estricto de 5 segundos.
  * Actualiza `last_tested_at`, `last_test_status` (`SUCCESS` o `FAILED`) y `last_test_error` en `notification_channel_settings`, y registra una fila en `notification_outbox` (`SENT` o `FAILED_PERMANENT`) y en `audit_log` para trazabilidad completa (Art. III.3).

#### Respuesta Exitosa (`200 OK` tanto si el servidor remoto aceptó como si rechazó, informando del diagnóstico)
```json
{
  "success": true,
  "data": {
    "test_passed": true,
    "status": "SUCCESS",
    "http_code": 200,
    "duration_ms": 142,
    "tested_at": "2026-10-16T20:36:00+02:00",
    "error": null
  },
  "message": "Conexión verificada con éxito con el canal Slack #emergencias-frio."
}
```

---

### 4.7. `PATCH /api/coordinator/users/{id}/on-call`
Activa o desactiva la condición de *"Técnico de guardia"* (`is_on_call`) sobre un técnico de campo (RF-02.5, RF-02.6).

* **Payload de Entrada:**
  ```json
  {
    "is_on_call": true
  }
  ```
* **Reglas de Validación:**
  * Si el usuario no existe: `404 USER_NOT_FOUND`.
  * Si el usuario tiene rol `COORDINATOR`: `422 ON_CALL_ONLY_FOR_TECHNICIANS`.
  * Si el usuario está inactivo (`is_active = false`) y se intenta poner `is_on_call = true`: `409 CANNOT_SET_ON_CALL_INACTIVE_USER`.
  * Registra `USER_ON_CALL_UPDATED` en `audit_log`.
* **Regla Automática en Baja de Personal (`PATCH /api/coordinator/users/{id}/deactivate`):**
  * Al desactivar cualquier técnico, el repositorio fuerza atómicamente `is_on_call = FALSE` (RF-02.6) y cancela por obsolescencia cualquier notificación pendiente dirigida a ese usuario (Caso Límite 6).

---

### 4.8. Contrato del Despachador y Vigilante en Segundo Plano (`POST /api/cron/notifications-dispatch` y `bin/send_notifications.php`)

* **Seguridad HTTP:** Protegido por `CronAuthMiddleware` (cabecera `X-Cron-Secret` o `Authorization: Bearer <secret>`), idéntico a `/api/cron/auto-close` y `/api/cron/sanitary-clock-sweep`.
* **Secuencia de Ejecución Atómica e Idempotente en 4 Fases:**
  1. **Fase 1 · Rescate de Envíos Huérfanos en Tránsito (RF-01.4):**
     Busca filas con `status = 'PROCESSING'` y `processing_started_at < CURRENT_TIMESTAMP - INTERVAL '5 minutes'`. Incrementa `attempts`; si `attempts >= max_attempts`, las pasa a `FAILED_PERMANENT`; en caso contrario, las pasa a `PENDING_RETRY` con la espera progresiva correspondiente y `last_error = 'Rescatada tras superar 5 minutos en estado PROCESSING sin respuesta'`.
  2. **Fase 2 · Vigilancia de Inminencia de SLA 2h y 3h en Máquinas Sanitarias (RF-04):**
     * Evalúa incidencias activas (`status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')`) sobre máquinas con `MachineType::requiresSanitaryWatch() = true` (`PERISHABLE_FOOD` y `COMBO`).
     * **Umbral 2h sin asignar (RF-04.1):** Si `status IN ('REGISTERED', 'REOPENED')`, `assigned_technician_id IS NULL`, `sla_2h_alert_sent_at IS NULL` y `CURRENT_TIMESTAMP - cycle_started_at >= INTERVAL '2 hours'`, marca `sla_2h_alert_sent_at = CURRENT_TIMESTAMP` y encola `SLA_IMMINENCE_2H_UNASSIGNED` (`CRITICAL_SANITARY`) para todos los Coordinadores activos y el Webhook.
     * **Umbral 3h sin iniciar (RF-04.2):** Si `started_at IS NULL`, `sla_3h_alert_sent_at IS NULL` y `CURRENT_TIMESTAMP - cycle_started_at >= INTERVAL '3 hours'`, marca `sla_3h_alert_sent_at = CURRENT_TIMESTAMP` y encola `SLA_IMMINENCE_3H_UNSTARTED` (`CRITICAL_SANITARY`) para todos los Coordinadores activos, el Técnico asignado (si existe) y el Webhook.
  3. **Fase 3 · Selección con Prioridad Estricta y Bloqueo Concurrente (RF-01.3, RNF-06):**
     Selecciona hasta `batch_size` (default `50`) filas donde `status IN ('PENDING', 'PENDING_RETRY') AND next_attempt_at <= CURRENT_TIMESTAMP` ordenadas por:
     ```sql
     ORDER BY
         CASE WHEN priority = 'CRITICAL_SANITARY' THEN 0 ELSE 1 END ASC,
         next_attempt_at ASC,
         id ASC
     FOR UPDATE SKIP LOCKED
     ```
     y las marca como `status = 'PROCESSING'`, `processing_started_at = CURRENT_TIMESTAMP`.
  4. **Fase 4 · Verificación de Obsolescencia, Entrega y Backoff Progresivo (RF-06.1, RF-06.2, RF-07.1):**
     Para cada fila reclamada:
     * **Comprobación de Obsolescencia (RF-07.1):** Consulta el estado vigente del ticket y del destinatario. Si el mensaje quedó obsoleto (ticket ya asignado para alerta de 2h, ya iniciado para alerta de 3h, técnico reemplazado para aviso de asignación, pausa ya reanudada para solicitud de acceso, ticket `CANCELLED`/`CLOSED` para avisos previos, usuario desactivado o Webhook desactivado), transiciona a `CANCELLED_OBSOLETE` con `obsolete_reason` y continúa con la siguiente sin enviar.
     * **Intento de Entrega (timeout máx. 5 s, RNF-03):**
       * Si tiene éxito: `status = 'SENT'`, `processed_at = CURRENT_TIMESTAMP`, `processing_started_at = NULL`.
       * Si falla con **rechazo permanente** (formato de correo o URL inválido, error SMTP 5xx permanente `550/551/553`, HTTP `400/401/403/404/410` en Webhook o host bloqueado por SSRF): pasa directamente a `status = 'FAILED_PERMANENT'`, `attempts = attempts + 1`, `processed_at = CURRENT_TIMESTAMP`, `last_error = <detalle>`, y escribe en `audit_log` (RF-06.2).
       * Si falla por **causa transitoria** (timeout, caída de red, SMTP 4xx, HTTP 429/5xx en Webhook): incrementa `attempts = attempts + 1`. Si `attempts >= max_attempts` (5), pasa a `FAILED_PERMANENT` y audita; si `attempts < 5`, pasa a `PENDING_RETRY` con `next_attempt_at` calculado según la tabla de backoff:
         * Intento 1 fallido $\rightarrow +1\text{ minuto}$ (`60 s`)
         * Intento 2 fallido $\rightarrow +5\text{ minutos}$ (`300 s`)
         * Intento 3 fallido $\rightarrow +15\text{ minutos}$ (`900 s`)
         * Intento 4 fallido $\rightarrow +60\text{ minutos}$ (`3600 s`)
         * Intento 5 fallido $\rightarrow$ `FAILED_PERMANENT`

#### Respuesta Exitosa de `POST /api/cron/notifications-dispatch` (`200 OK`)
```json
{
  "success": true,
  "data": {
    "rescued_stuck_count": 0,
    "sla_2h_alerts_enqueued": 1,
    "sla_3h_alerts_enqueued": 0,
    "claimed_count": 4,
    "sent_count": 3,
    "retry_scheduled_count": 1,
    "failed_permanent_count": 0,
    "cancelled_obsolete_count": 0,
    "duration_ms": 84.5
  }
}
```
