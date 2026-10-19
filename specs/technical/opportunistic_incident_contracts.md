# ESPECIFICACIÓN TÉCNICA · CONTRATOS DE DATOS Y API DE AVERÍA OPORTUNISTA IN SITU (ALTA Y ASUNCIÓN EN RUTA)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `16-opportunistic-incident`  
**Documento:** `specs/technical/opportunistic_incident_contracts.md`  
**Referencia Funcional:** [`specs/16-opportunistic-incident/spec.md`](../16-opportunistic-incident/spec.md) y [`specs/functional/opportunistic_incident_spec.md`](../functional/opportunistic_incident_spec.md) (`RF-01` a `RF-06`, `RNF-01` a `RNF-04`, Resoluciones QA 1 a 5)  
**Motor de Persistencia:** PostgreSQL 16+ (Supabase / Motor Local de Certificación) · Motor Único  
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`) y `multipart/form-data` (alta con fotografía opcional)  
**Conformidad Constitucional:** Artículos I, II, III (3.1, 3.2, 3.3), IV, V (5.1, 5.2, 5.3, 5.4, 5.5, 5.6) y VI  

---

## 1. Visión General de Endpoints y Superficies del Módulo

| Método | Ruta | Rol / Middleware | Propósito Operativo |
| :--- | :--- | :--- | :--- |
| **Operativa Oportunista del Técnico en Ruta** | | | |
| `GET` | `/api/technician/my-route` | `InternalAuthMiddleware(TECHNICIAN)` | *(Extensión retrocompatible)* Devuelve las paradas activas (agrupando y ordenando múltiples intervenciones `IN_PROGRESS` de la misma sede en la Parada #1, `RF-02.1`), las sedes en **ventana de gracia de 30 min** (`recently_visited_locations`, `RF-01.1.2` y `RF-01.2`) y el resumen de emergencias críticas/frío pendientes en otras sedes (`RF-03.1`). |
| `GET` | `/api/technician/locations/{location_id}/opportunistic-machines` | `InternalAuthMiddleware(TECHNICIAN)` | Devuelve el catálogo de máquinas operativas (`is_active = TRUE` o bloqueadas por acceso `is_blocked_no_access = TRUE`, excluyendo bajas definitivas, `RF-01.4`, `RF-01.5`) de una sede habilitada para el técnico, junto con el estado de su expediente activo o en garantía (< 48h) y la evaluación de advertencia sanitaria en ruta (`RF-03.1`). |
| `POST` | `/api/technician/opportunistic/incidents` | `InternalAuthMiddleware(TECHNICIAN)` | **Flujo A — Alta Oportunista In Situ (`RF-04`):** Crea una nueva incidencia sobre una máquina sin expediente activo ni en garantía, etiquetada como `NEW` (*"Oportunista In Situ · Nueva"*), asignada al técnico autenticado y nacida directamente en estado `IN_PROGRESS`. Soporta `multipart/form-data` (con foto opcional validada según Art. V.5) y `application/json`. |
| `POST` | `/api/technician/opportunistic/incidents/{id}/assume` | `InternalAuthMiddleware(TECHNICIAN)` | **Flujo B — Asunción / Reapertura Oportunista In Situ (`RF-05`):** Asume un expediente preexistente (`REGISTERED`, `REOPENED`, `ASSIGNED`, `PENDING_PARTS`, `PENDING_INFO`) o reabre in situ un expediente en garantía (`RESOLVED` < 48h), etiquetándolo como `ASSUMED` (*"Oportunista In Situ · Asumida"*) y pasándolo a `IN_PROGRESS` bajo titularidad del técnico actuante. Si alcanza la 3.ª reincidencia (*Avería Crónica*, `RF-05.4.2`), cierra el ticket crónico, alerta a Coordinación y crea atómicamente el expediente sucesor limpio en `IN_PROGRESS`. |
| **Extensiones en Endpoints Existentes (Resolución, Coordinación, Métricas y Sede)** | | | |
| `POST` | `/api/technician/incidents/{id}/resolve` | `InternalAuthMiddleware(TECHNICIAN)` | *(Extensión `RF-06.1`)* Al resolver satisfactoriamente una incidencia en una máquina con `is_blocked_no_access = TRUE`, levanta automáticamente el bloqueo de acceso y audita `MACHINE_UNBLOCKED_BY_ONSITE_RESOLUTION`. Mantiene intacta la puerta de Cuarentena Sanitaria del Módulo 11 (`RF-06.1.2`). |
| `GET` | `/api/coordinator/incidents` y `/api/coordinator/incidents/{id}/detail` | `InternalAuthMiddleware(COORDINATOR)` | *(Extensión `RF-06.4`)* Expone los campos `opportunistic_mode`, `opportunistic_mode_label`, `opportunistic_origin_reason`, `opportunistic_origin_label`, `opportunistic_informant_name` y `chronic_predecessor_incident_id`. |
| `GET` | `/api/coordinator/metrics/summary` | `InternalAuthMiddleware(COORDINATOR)` | *(Extensión `RF-06.4`)* Añade el bloque `opportunistic_interventions` con `total_avoided_dispatches`, `new_count` (*"Oportunista In Situ · Nueva"*) y `assumed_count` (*"Oportunista In Situ · Asumida"*). |
| `GET` | `/api/locations/{site_code}/machines` | `SiteAuthMiddleware` | *(Extensión `RF-04.1.2` y Art. V.4)* Expone en el ticket activo de la sede `opportunistic_mode_label` y `opportunistic_informant_name` (si aplica), garantizando que el técnico figure únicamente como `"Servicio Técnico Oficial · Operador #[Código]"` y jamás se filtren notas internas de traspaso. |

---

## 2. Contrato de Esquema de Base de Datos (PostgreSQL / Supabase)

De acuerdo con el contrato de correspondencia de esquema (`specs/technical/schema_correspondence_contract.md`), las adiciones se declaran de forma idempotente tanto en la migración incremental `database/supabase_migrations/005_opportunistic_incident.sql` como en el DDL canónico `database/supabase_init.sql`; la coherencia modalidad ↔ submotivo se refuerza además con la reparación `006_opportunistic_coherence_repair.sql`.

### 2.1. Nuevos Tipos Enumerados Nativos (`ENUM`) y Extensión de Plantillas Outbox

```sql
-- 1. Modalidad de intervención oportunista in situ (RF-04.2, RF-05.2, RF-06.4 / Resolución QA 4)
DO $do$
BEGIN
    CREATE TYPE opportunistic_mode AS ENUM (
        'NEW',      -- "Oportunista In Situ · Nueva" (creada desde cero por el técnico en sede)
        'ASSUMED'   -- "Oportunista In Situ · Asumida" (ticket preexistente absorbido o reabierto in situ)
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 2. Submotivo de origen de la intervención oportunista (RF-04.1.2, RF-05.2, RF-05.4)
DO $do$
BEGIN
    CREATE TYPE opportunistic_origin_reason AS ENUM (
        'SITE_VERBAL_NOTICE',       -- Aviso verbal en sede (pasillo / recepción)
        'TECHNICIAN_DETECTION',     -- Detección propia del técnico in situ
        'EXISTING_TICKET_CLAIM',    -- Asunción de ticket abierto preexistente
        'WARRANTY_REOPEN_IN_SITU'   -- Reapertura en garantía (< 48h) o sucesor de Avería Crónica in situ
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 3. Nuevas plantillas en notification_template (RF-03.2.2 y RF-06.3.1 / Resoluciones QA 1 y QA 2)
ALTER TYPE notification_template ADD VALUE IF NOT EXISTS 'OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT';
ALTER TYPE notification_template ADD VALUE IF NOT EXISTS 'INCIDENT_REOPENED_IN_SITU_SITE';
```

### 2.2. Alteraciones sobre la Tabla `incidents`

```sql
ALTER TABLE incidents
    ADD COLUMN IF NOT EXISTS opportunistic_mode              opportunistic_mode,
    ADD COLUMN IF NOT EXISTS opportunistic_origin_reason     opportunistic_origin_reason,
    ADD COLUMN IF NOT EXISTS opportunistic_informant_name    VARCHAR(100),
    ADD COLUMN IF NOT EXISTS chronic_predecessor_incident_id BIGINT;

-- Restricción de integridad: coherencia entre modalidad oportunista y submotivo de origen
--
-- Los dos `IS NOT NULL` no son decorativos: sin ellos, `opportunistic_mode = 'NEW'`
-- sobre una modalidad nula evalúa a desconocido y el `CHECK` admitiría «submotivo
-- sin modalidad» y «modalidad sin submotivo». RF-04.1 exige el motivo de origen y
-- la 006 repara la definición declarada originalmente por la 005.
DO $do$
BEGIN
    ALTER TABLE incidents
        ADD CONSTRAINT chk_incidents_opportunistic_coherence CHECK (
            (opportunistic_mode IS NULL AND opportunistic_origin_reason IS NULL)
            OR (
                opportunistic_mode IS NOT NULL
                AND opportunistic_origin_reason IS NOT NULL
                AND (
                    (
                        opportunistic_mode = 'NEW'
                        AND opportunistic_origin_reason IN ('SITE_VERBAL_NOTICE', 'TECHNICIAN_DETECTION', 'WARRANTY_REOPEN_IN_SITU')
                    )
                    OR (
                        opportunistic_mode = 'ASSUMED'
                        AND opportunistic_origin_reason IN ('EXISTING_TICKET_CLAIM', 'WARRANTY_REOPEN_IN_SITU')
                    )
                )
            )
        );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Clave foránea autorreferencial opcional hacia el expediente crónico predecesor (RF-05.4.2)
DO $do$
BEGIN
    ALTER TABLE incidents
        ADD CONSTRAINT fk_incidents_chronic_predecessor
        FOREIGN KEY (chronic_predecessor_incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE;
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- Índice parcial para métricas y filtrado rápido de intervenciones oportunistas (RF-06.4)
CREATE INDEX IF NOT EXISTS idx_incidents_opportunistic_mode
    ON incidents (opportunistic_mode, created_at)
    WHERE opportunistic_mode IS NOT NULL AND deleted_at IS NULL;
```

---

## 3. Reglas de Dominio y Contratos de Estado

### 3.1. Regla de Habilitación de Sede y Ventana de Gracia (`RF-01.1`, `RF-01.2`, `RF-01.3`)

Una sede `location_id` se considera **habilitada para intervención oportunista** por el técnico `technician_id` en el instante `now` si y solo si:

1. **Parada Activa Vigente (`active_stop`):**
   * Existe al menos una fila en `incidents` con `location_id = :loc_id`, `assigned_technician_id = :tech_id`, `deleted_at IS NULL` y `status IN ('ASSIGNED', 'IN_PROGRESS')`; **O BIEN**
   * Existe al menos una fila en `preventive_orders` con `location_id = :loc_id`, `assigned_technician_id = :tech_id`, `deleted_at IS NULL` y `status IN ('SCHEDULED', 'IN_INSPECTION')`.
   * *(Nota: Los estados `PENDING_INFO` y `PENDING_PARTS` NO mantienen habilitada la sede de forma permanente; únicamente activan la ventana de gracia del punto 2 a partir del instante en que se pausaron).*

2. **Ventana de Gracia de 30 Minutos (`grace_window`, más +5 min con formulario abierto):**
   * El técnico `technician_id` realizó sobre una máquina de `location_id` una transición a `RESOLVED`, `PENDING_PARTS` o `PENDING_INFO` (registrada en `incident_history.created_at` con `user_id = :tech_id`) o completó una orden preventiva (`preventive_orders.completed_at` con `assigned_technician_id = :tech_id` y `status = 'COMPLETED'`) hace **menos de 30 minutos naturales** (`1800` segundos) para mostrar la tarjeta en ruta y abrir el catálogo/formulario, o hace **menos de 35 minutos naturales** (`2100` segundos) cuando la petición de creación/asunción incluye `form_opened_within_grace = true` (`RF-01.3`).

### 3.2. Regla de Coexistencia `IN_PROGRESS` (`RF-02.1`, `RF-02.2`)

* **Bloqueo entre sedes distintas (`RF-02.2`):** Si el técnico `technician_id` tiene actualmente cualquier incidencia en `incidents` con `status = 'IN_PROGRESS'` en `location_id <> :target_location_id`, o cualquier orden en `preventive_orders` con `status = 'IN_INSPECTION'` en `location_id <> :target_location_id`, el backend rechaza la petición con **HTTP 409 Conflict** y código `ACTIVE_INTERVENTION_AT_DIFFERENT_LOCATION`, devolviendo en `details` los datos del expediente abierto en la otra sede.
* **Coexistencia y ordenación en la misma sede (`RF-02.1`):** Se permiten múltiples incidencias en `status = 'IN_PROGRESS'` (y órdenes preventivas en `IN_INSPECTION`) para el mismo técnico siempre que compartan el mismo `location_id`. En `GET /api/technician/my-route`, todas las paradas `IN_PROGRESS` de esa sede se sitúan al principio de la ruta ordenadas por:
  1. `urgency` descendente (`CRITICAL` > `HIGH` > `MEDIUM` > `LOW`).
  2. `sla_target_at` ascendente (`NULLS LAST`).
  3. `started_at` ascendente, `id` ascendente.

### 3.3. Regla de Salvaguarda Sanitaria de Cadena de Frío en Ruta (`RF-03.1`, `RF-03.2`, Art. II)

Antes de ejecutar un Alta Oportunista (`POST /api/technician/opportunistic/incidents`) o una Asunción Oportunista (`POST /api/technician/opportunistic/incidents/{id}/assume`) en `location_id`:
1. El servicio comprueba si el técnico tiene asignada alguna parada pendiente (`status IN ('ASSIGNED', 'PENDING_INFO')`) en **otra sede distinta** (`other.location_id <> :location_id`) que cumpla:
   * `other.urgency = 'CRITICAL'`, **O BIEN**
   * La máquina de `other` es de tipo `PERISHABLE_FOOD` o `COMBO` y la categoría del fallo es `TEMPERATURE_COLD` o `ELECTRICAL_OFF` (o la máquina está en `sanitary_status = 'QUARANTINE'`).
2. Si existe al menos una parada crítica/sanitaria esperando en otra sede y la petición **no** envía `confirm_cold_chain_override = true`:
   * El servidor responde **HTTP 409 Conflict** con código `COLD_CHAIN_ROUTE_WARNING_CONFIRMATION_REQUIRED` y la lista `pending_critical_stops` en `details` para que la interfaz muestre el modal de advertencia destacada del Artículo II.
3. Si el técnico reenvía la petición con `confirm_cold_chain_override = true`:
   * Se ejecuta la operación oportunista dentro de la transacción.
   * Se registra en `audit_log` la confirmación expresa de la advertencia sanitaria (`cold_chain_override_confirmed = true`).
   * Se encola atómicamente en `notification_outbox` la alerta prioritaria `OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT` con prioridad `CRITICAL_SANITARY` dirigida a todos los Coordinadores activos (vía `EMAIL`) y al Canal Corporativo de Emergencias (`WEBHOOK`, si está activo y alguna de las paradas postergadas está bajo vigilancia sanitaria del Art. II).

---

## 4. Contratos Detallados de Endpoints REST

### 4.1. `GET /api/technician/my-route` (Extensión Retrocompatible)

Mantiene como raíz o estructura compatible la lista de paradas activas del técnico y añade/expone en cada parada los metadatos oportunistas, además de exponer las cabeceras/metadatos o colección de sedes en ventana de gracia mediante el parámetro opcional `?include_opportunistic_context=1` (o campos enriquecidos en cada parada activa).

* **Nota de retrocompatibilidad estricta con suites existentes:**
  * Cuando se invoca `GET /api/technician/my-route` sin `?include_opportunistic_context=1`, la respuesta sigue siendo el **array JSON indexado de paradas** (`[ { ...stopPayload... } ]`) para no romper ningún test de contrato previo (Módulos 01 a 15). Cada elemento de parada incluye ahora los nuevos campos opcionales:
    * `opportunistic_mode`: `"NEW"` | `"ASSUMED"` | `null`
    * `opportunistic_mode_label`: `"Oportunista In Situ · Nueva"` | `"Oportunista In Situ · Asumida"` | `null`
    * `opportunistic_origin_reason`: `"SITE_VERBAL_NOTICE"` | `"TECHNICIAN_DETECTION"` | `"EXISTING_TICKET_CLAIM"` | `"WARRANTY_REOPEN_IN_SITU"` | `null`
    * `opportunistic_informant_name`: `string|null`
    * `chronic_predecessor_incident_id`: `int|null`
  * Cuando el frontend de ruta invoca `GET /api/technician/my-route?include_opportunistic_context=1`, devuelve:

```json
{
  "stops": [
    {
      "id": 104,
      "ticket_code": "INC-2026-8821",
      "urgency": "HIGH",
      "status": "IN_PROGRESS",
      "opportunistic_mode": "NEW",
      "opportunistic_mode_label": "Oportunista In Situ · Nueva",
      "opportunistic_origin_reason": "SITE_VERBAL_NOTICE",
      "opportunistic_origin_label": "Aviso verbal en sede",
      "opportunistic_informant_name": "Marta Gómez (Conserjería)",
      "chronic_predecessor_incident_id": null,
      "machine": {
        "id": 12,
        "code": "MAQ-BCN-012",
        "model": "Necta Canto Touch",
        "floor_wing": "Planta 2 · Cafetería"
      },
      "location": {
        "id": 3,
        "name": "Hospital Clínic · Pabellón Norte",
        "address": "C/ Villarroel 170, Barcelona",
        "contact_phone": "+34 932 275 400",
        "latitude": 41.3894,
        "longitude": 2.1524
      },
      "category": "PRODUCT_JAM",
      "description": "Atasco en espiral B4 reportado en persona por conserjería.",
      "started_at": "2026-10-19T08:40:00+02:00",
      "created_at": "2026-10-19T08:40:00+02:00",
      "comments_count": 0,
      "sanitary_resolution_required": false
    }
  ],
  "enabled_locations": [
    {
      "location_id": 3,
      "location_name": "Hospital Clínic · Pabellón Norte",
      "location_address": "C/ Villarroel 170, Barcelona",
      "enablement_type": "ACTIVE_STOP",
      "grace_expires_at": null,
      "grace_remaining_seconds": null
    },
    {
      "location_id": 5,
      "location_name": "Facultad de Ciencias · Edificio Central",
      "location_address": "Av. Diagonal 645, Barcelona",
      "enablement_type": "GRACE_WINDOW_30M",
      "last_intervention_reference": "INC-2026-7710",
      "last_intervention_Transition_at": "2026-10-19T08:35:00+02:00",
      "grace_expires_at": "2026-10-19T09:05:00+02:00",
      "grace_remaining_seconds": 1200
    }
  ],
  "pending_critical_stops_other_locations": [
    {
      "incident_id": 99,
      "ticket_code": "INC-2026-9901",
      "urgency": "CRITICAL",
      "category": "TEMPERATURE_COLD",
      "machine_code": "MAQ-BCN-050",
      "machine_type": "PERISHABLE_FOOD",
      "location_id": 8,
      "location_name": "Centro Logístico Zona Franca",
      "sla_target_at": "2026-10-19T10:30:00+02:00"
    }
  ]
}
```

---

### 4.2. `GET /api/technician/locations/{location_id}/opportunistic-machines`

Consulta el parque de máquinas operativas de una sede habilitada (`RF-01.1`, `RF-01.4`, `RF-01.5`) y determina para cada máquina qué acción oportunista está disponible (`CREATE_NEW`, `ASSUME_DIRECT`, `ASSUME_WITH_CONFIRMATION`, `REOPEN_IN_SITU`, `CURRENT_TECHNICIAN_IN_PROGRESS` o `BLOCKED_OTHER_TECH_IN_PROGRESS`).

* **Parámetros de ruta:**
  * `location_id` (entero positivo obligatorio).
* **Respuesta Exitosa (`200 OK`):**

```json
{
  "location": {
    "id": 3,
    "site_code": "HOSP-CLINIC-N",
    "name": "Hospital Clínic · Pabellón Norte",
    "address": "C/ Villarroel 170, Barcelona",
    "enablement_type": "ACTIVE_STOP",
    "grace_expires_at": null,
    "grace_remaining_seconds": null
  },
  "active_intervention_conflict": null,
  "cold_chain_route_warning": {
    "requires_confirmation": true,
    "pending_critical_stops": [
      {
        "incident_id": 99,
        "ticket_code": "INC-2026-9901",
        "urgency": "CRITICAL",
        "category": "TEMPERATURE_COLD",
        "machine_code": "MAQ-BCN-050",
        "machine_type": "PERISHABLE_FOOD",
        "location_id": 8,
        "location_name": "Centro Logístico Zona Franca",
        "sla_target_at": "2026-10-19T10:30:00+02:00"
      }
    ]
  },
  "machines": [
    {
      "id": 11,
      "code": "MAQ-BCN-011",
      "model": "Fas Winning",
      "machine_type": "PERISHABLE_FOOD",
      "floor_wing": "Planta Baja · Urgencias",
      "is_blocked_no_access": false,
      "sanitary_status": "OK",
      "opportunistic_action": "CURRENT_TECHNICIAN_IN_PROGRESS",
      "active_incident": {
        "id": 101,
        "ticket_code": "INC-2026-8100",
        "status": "IN_PROGRESS",
        "urgency": "CRITICAL",
        "category": "TEMPERATURE_COLD",
        "description": "Compresor detenido.",
        "assigned_technician_id": 4,
        "assigned_technician_name": "Carlos Ruiz",
        "is_in_warranty": false,
        "reopen_count": 0
      }
    },
    {
      "id": 12,
      "code": "MAQ-BCN-012",
      "model": "Necta Canto Touch",
      "machine_type": "HOT_DRINKS",
      "floor_wing": "Planta 2 · Cafetería",
      "is_blocked_no_access": false,
      "sanitary_status": "OK",
      "opportunistic_action": "CREATE_NEW",
      "active_incident": null
    },
    {
      "id": 13,
      "code": "MAQ-BCN-013",
      "model": "Necta Samba",
      "machine_type": "SNACKS",
      "floor_wing": "Planta 3 · Sala Espera",
      "is_blocked_no_access": false,
      "sanitary_status": "OK",
      "opportunistic_action": "ASSUME_WITH_CONFIRMATION",
      "active_incident": {
        "id": 102,
        "ticket_code": "INC-2026-8105",
        "status": "ASSIGNED",
        "urgency": "MEDIUM",
        "category": "PAYMENT_SYSTEM",
        "description": "Monedero no devuelve cambio.",
        "assigned_technician_id": 5,
        "assigned_technician_name": "Laura Gómez",
        "is_in_warranty": false,
        "reopen_count": 0
      }
    },
    {
      "id": 14,
      "code": "MAQ-BCN-014",
      "model": "Jofemar Vision",
      "machine_type": "COLD_DRINKS",
      "floor_wing": "Planta 1 · Vestíbulo",
      "is_blocked_no_access": false,
      "sanitary_status": "OK",
      "opportunistic_action": "REOPEN_IN_SITU",
      "active_incident": {
        "id": 95,
        "ticket_code": "INC-2026-7990",
        "status": "RESOLVED",
        "urgency": "MEDIUM",
        "category": "PRODUCT_JAM",
        "description": "Lata atascada en canal 3.",
        "assigned_technician_id": 5,
        "assigned_technician_name": "Laura Gómez",
        "resolved_at": "2026-10-18T16:00:00+02:00",
        "is_in_warranty": true,
        "reopen_count": 2,
        "will_trigger_chronic_replacement": true
      }
    }
  ]
}
```

* **Códigos de Error de `GET /api/technician/locations/{location_id}/opportunistic-machines`:**
  * `400 INVALID_LOCATION_ID`: El parámetro `location_id` no es un entero positivo válido.
  * `401 UNAUTHORIZED`: Sin sesión de técnico válida.
  * `403 LOCATION_NOT_ENABLED_FOR_OPPORTUNISTIC`: El técnico no tiene ninguna parada activa (`ASSIGNED` / `IN_PROGRESS` / preventivo activo) ni una intervención finalizada/pausada en los últimos 30 minutos en esa sede (`RF-01.1`).
  * `404 LOCATION_NOT_FOUND`: La sede no existe o está inactiva.

---

### 4.3. `POST /api/technician/opportunistic/incidents` (Flujo A — Alta Oportunista In Situ)

Crea una nueva incidencia con `opportunistic_mode = 'NEW'`, asignada al técnico autenticado y directamente en estado `IN_PROGRESS` (`RF-04`).

* **Formatos admitidos:** `multipart/form-data` (cuando adjunta archivo `photo`) o `application/json`.
* **Campos de Entrada:**

| Campo | Tipo | Obligatorio | Reglas de Validación |
| :--- | :--- | :---: | :--- |
| `machine_id` | `int` | Sí | Debe pertenecer a una sede habilitada (`RF-01.1`, o margen de 35 min si `form_opened_within_grace = true`). La máquina no debe estar dada de baja definitiva (`is_active = false AND is_blocked_no_access = false` $\rightarrow$ error `422 MACHINE_INACTIVE`). |
| `category` | `string` | Sí | Miembro válido de `incident_category`: `'TEMPERATURE_COLD'`, `'PAYMENT_SYSTEM'`, `'PRODUCT_JAM'`, `'ELECTRICAL_OFF'`, `'OTHER'`. |
| `origin_reason` | `string` | Sí | `'SITE_VERBAL_NOTICE'` (*"Aviso verbal en sede"*) o `'TECHNICIAN_DETECTION'` (*"Detección propia del técnico"*). |
| `informant_name` | `string\|null` | No | Opcional (máx. 100 caracteres). Solo se conserva si `origin_reason = 'SITE_VERBAL_NOTICE'`. |
| `description` | `string` | Sí | Mínimo **10 caracteres** significativos tras `trim()` (`RF-04.1.3`). |
| `photo` | `file\|null` | No | Opcional. Validación estricta del **Art. V.5**: $\le 5\text{ MB}$ (`5242880` bytes), extensión en `.jpg`, `.jpeg`, `.png`, `.webp` y verificación obligatoria de magic bytes / MIME real en servidor (`LocalFileUploader`). |
| `confirm_cold_chain_override` | `bool` | Condicional | Obligatorio (`true` / `'1'`) si el técnico tiene paradas críticas/sanitarias esperando en otra sede (`RF-03.1`). |
| `form_opened_within_grace` | `bool` | No | Si es `true`, aplica el margen de tolerancia de **35 minutos** desde el cierre/pausa previo en la sede (`RF-01.3`). |
| `iban` / `bizum_phone` / `refund_requested` | — | **PROHIBIDO** | Si la petición intenta enviar campos financieros/bancarios de reembolso (`iban`, `bizum_phone`, `refund_requested`), el servidor rechaza con `422 CASH_REFUND_PRIVACY_VIOLATION` recordando que los reembolsos se tramitan exclusivamente vía código QR exterior (`RF-04.3`, Art. V.4). |

* **Efectos Transaccionales Atómicos (`RF-04.2`, `RF-06.3`):**
  1. Calcula `urgency` con `UrgencyCalculator::calculate($machine->getMachineType(), $category)` (escalando a `CRITICAL` si es frío/corte eléctrico en `PERISHABLE_FOOD` o `COMBO`, Art. II) y calcula `sla_target_at`.
  2. Inserta en `incidents` con:
     * `status = 'IN_PROGRESS'`
     * `assigned_technician_id = :tech_id`
     * `assigned_at = CURRENT_TIMESTAMP`, `started_at = CURRENT_TIMESTAMP`, `cycle_started_at = CURRENT_TIMESTAMP`
     * `opportunistic_mode = 'NEW'`
     * `opportunistic_origin_reason = :origin_reason`
     * `opportunistic_informant_name = :informant_name`
     * `reporter_name = :informant_name ?? :technician_name`
  3. Inserta en `incident_history`:
     * `from_status = NULL`, `to_status = 'IN_PROGRESS'`, `user_id = :tech_id`
     * `action_note = "Alta Oportunista In Situ (Oportunista In Situ · Nueva · {origin_label}). Intervención iniciada en el acto."`
  4. Inserta en `audit_log` (`entity_type = 'TICKET'`, `action = 'INCIDENT_CREATED_OPPORTUNISTIC'`).
  5. Encola en `notification_outbox` dentro de la misma transacción:
     * `INCIDENT_OPENED_SITE` (canal `EMAIL`) dirigido a `locations.contact_email` indicando que la avería ya está siendo atendida in situ por `"Servicio Técnico Oficial · Operador #[Código]"` (`RF-06.3.1`, Art. V.4).
     * Si `urgency = 'CRITICAL'` en máquina bajo vigilancia sanitaria (`PERISHABLE_FOOD` / `COMBO` o fallo térmico/eléctrico): `COLD_CHAIN_IMMEDIATE_ALERT` (`CRITICAL_SANITARY`) para Coordinadores activos y `WEBHOOK` (`RF-06.3.4`).
     * Si `confirm_cold_chain_override = true` y había emergencia crítica/frío en otra sede: `OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT` (`CRITICAL_SANITARY`) para Coordinadores activos y `WEBHOOK` (`RF-03.2.2`).

* **Respuesta Exitosa (`201 Created`):**

```json
{
  "status": "success",
  "message": "Avería oportunista registrada e iniciada en curso.",
  "data": {
    "id": 104,
    "ticket_code": "INC-2026-8821",
    "status": "IN_PROGRESS",
    "urgency": "HIGH",
    "category": "PRODUCT_JAM",
    "description": "Atasco en espiral B4 reportado en persona por conserjería.",
    "machine_id": 12,
    "machine_code": "MAQ-BCN-012",
    "location_id": 3,
    "assigned_technician_id": 4,
    "opportunistic_mode": "NEW",
    "opportunistic_mode_label": "Oportunista In Situ · Nueva",
    "opportunistic_origin_reason": "SITE_VERBAL_NOTICE",
    "opportunistic_origin_label": "Aviso verbal en sede",
    "opportunistic_informant_name": "Marta Gómez (Conserjería)",
    "assigned_at": "2026-10-19T08:40:00+02:00",
    "started_at": "2026-10-19T08:40:00+02:00",
    "sla_target_at": "2026-10-19T16:40:00+02:00",
    "cold_chain_override_alerted": false
  }
}
```

* **Códigos de Error de `POST /api/technician/opportunistic/incidents`:**
  * `403 LOCATION_NOT_ENABLED_FOR_OPPORTUNISTIC`: La sede no está habilitada para el técnico ni dentro de la ventana de gracia/tolerancia.
  * `404 MACHINE_NOT_FOUND`: La máquina indicada no existe.
  * `409 ACTIVE_INTERVENTION_AT_DIFFERENT_LOCATION`: El técnico dejó una intervención `IN_PROGRESS` (o preventivo `IN_INSPECTION`) abierta en otra sede distinta (`RF-02.2`). Incluye `details.active_conflict`.
  * `409 ACTIVE_INCIDENT_ALREADY_EXISTS_ASSUME_INSTEAD`: La máquina ya tiene un expediente activo o en garantía de 48h (`uq_machine_active_ticket`, Art. V.2 y `RF-05.1`). Devuelve en `details.existing_incident` el objeto completo del ticket existente y `opportunistic_action` para que el cliente ofrezca asumirlo inmediatamente sin recargar.
  * `409 COLD_CHAIN_ROUTE_WARNING_CONFIRMATION_REQUIRED`: El técnico tiene una parada crítica/sanitaria esperando en otra sede y no envió `confirm_cold_chain_override = true` (`RF-03.1`).
  * `422 MACHINE_INACTIVE`: La máquina está dada de baja definitiva (`RF-01.5`).
  * `422 INVALID_CATEGORY` / `INVALID_ORIGIN_REASON` / `DESCRIPTION_TOO_SHORT` (< 10 caracteres).
  * `422 CASH_REFUND_PRIVACY_VIOLATION`: Intento de enviar datos bancarios o de reembolso desde el parte del técnico (`RF-04.3`, Art. V.4).
  * `413 FILE_TOO_LARGE` / `422 INVALID_FILE_TYPE`: Violación del Artículo V.5 en la fotografía adjunta (preservando `details.form_data`).

---

### 4.4. `POST /api/technician/opportunistic/incidents/{id}/assume` (Flujo B — Asunción y Reapertura Oportunista In Situ)

Asume un expediente activo preexistente o reabre in situ un expediente en garantía (< 48h), transicionándolo atómicamente a `IN_PROGRESS` bajo la titularidad del técnico presente en la sede (`RF-05`).

* **Parámetros de ruta:**
  * `id` (ID numérico del expediente existente sobre la máquina de la sede).
* **Cuerpo de Entrada (`application/json`):**

| Campo | Tipo | Obligatorio | Reglas de Validación |
| :--- | :--- | :---: | :--- |
| `confirm_takeover_from_other_tech` | `bool` | Condicional | Obligatorio (`true`) cuando el expediente estaba asignado a **otro técnico distinto** en estado `ASSIGNED`, `PENDING_PARTS` o `PENDING_INFO` (`RF-05.3`). |
| `takeover_internal_note` | `string\|null` | No | Nota opcional del traspaso entre técnicos (máx. 500 caracteres). Se persiste como **nota interna (`is_internal = TRUE`)** en `incident_comments`, jamás visible para la Sede ni para el Ciudadano QR (`RF-05.3.3`, Art. V.4). |
| `reopen_reason` | `string\|null` | Condicional | Obligatorio (mínimo **10 caracteres** tras `trim()`) cuando el expediente está en estado `RESOLVED` dentro de las 48h de garantía (`RF-05.4`). |
| `confirm_cold_chain_override` | `bool` | Condicional | Obligatorio (`true`) si el técnico tiene paradas críticas/sanitarias esperando en otra sede (`RF-03.1`). |
| `form_opened_within_grace` | `bool` | No | Si es `true`, aplica el margen de tolerancia de **35 minutos** (`RF-01.3`). |

* **Matriz de Comportamiento por Estado Previo del Expediente (`RF-05.2` a `RF-05.5`):**

| Caso | Estado Previo y Titular | Acción Transaccional del Servidor |
| :--- | :--- | :--- |
| **Caso 1 (`RF-05.2`)** | `REGISTERED` o `REOPENED` (sin técnico) | Asigna a `:tech_id`, fija `assigned_at = COALESCE(assigned_at, CURRENT_TIMESTAMP)`, `started_at = COALESCE(started_at, CURRENT_TIMESTAMP)`, `status = 'IN_PROGRESS'`, `opportunistic_mode = 'ASSUMED'`, `opportunistic_origin_reason = 'EXISTING_TICKET_CLAIM'`. No envía correo redundante a la Sede (`RF-06.3.1`). |
| **Caso 2 (`RF-05.2`)** | `ASSIGNED`, `PENDING_PARTS` o `PENDING_INFO` del **mismo técnico** | Reanuda/inicia pasando a `status = 'IN_PROGRESS'` (acumulando `total_pending_info_seconds` y desplazando `sla_target_at` si venía de `PENDING_INFO`). Conserva `opportunistic_mode` previo (no lo marca como `ASSUMED` si ya era una parada ordinaria asignada a él, `RF-05.2`). |
| **Caso 3 (`RF-05.3`)** | `ASSIGNED`, `PENDING_PARTS` o `PENDING_INFO` de **otro técnico distinto** | Exige `confirm_takeover_from_other_tech = true` (si falta, responde `409 TAKEOVER_CONFIRMATION_REQUIRED`). Desasigna al técnico anterior (`previous_tech_id`), asigna a `:tech_id`, pasa a `status = 'IN_PROGRESS'`, fija `opportunistic_mode = 'ASSUMED'`, `opportunistic_origin_reason = 'EXISTING_TICKET_CLAIM'`. Si viene `takeover_internal_note`, crea fila en `incident_comments` con `is_internal = TRUE`. **Cancela por obsolescencia (`CANCELLED_OBSOLETE`)** los avisos `TECHNICIAN_ASSIGNED` (`EMAIL` y `TELEGRAM`) pendientes en `notification_outbox` para `previous_tech_id` y encola `TECHNICIAN_UNASSIGNED` **exclusivamente por canal `EMAIL`** (sin `TELEGRAM`, `RF-06.3.3` y Módulo 15 `RF-TG-06`). |
| **Caso 4A (`RF-05.4.1`)** | `RESOLVED` en garantía (< 48h) con `reopen_count < 2` (1.ª o 2.ª reapertura) | Exige `reopen_reason` ($\ge 10$ caracteres). Actualiza el expediente a `status = 'IN_PROGRESS'`, `assigned_technician_id = :tech_id`, `assigned_at = CURRENT_TIMESTAMP`, `started_at = CURRENT_TIMESTAMP`, `reopened_at = CURRENT_TIMESTAMP`, `reopen_reason = :reopen_reason`, `resolved_at = NULL`, `cycle_started_at = CURRENT_TIMESTAMP`, `opportunistic_mode = 'ASSUMED'`, `opportunistic_origin_reason = 'WARRANTY_REOPEN_IN_SITU'`. Inserta en `incident_history` la transición `RESOLVED -> REOPENED` (para el cómputo de reincidencias) seguida de `REOPENED -> IN_PROGRESS`. Encola correo `INCIDENT_REOPENED_IN_SITU_SITE` a la Sede y `INCIDENT_REOPENED_COORDINATOR` (+ `COLD_CHAIN_IMMEDIATE_ALERT` si aplica vigilancia sanitaria). |
| **Caso 4B (`RF-05.4.2`)** | `RESOLVED` en garantía (< 48h) con `reopen_count >= 2` (**3.ª reincidencia · Avería Crónica**) | Exige `reopen_reason` ($\ge 10$ caracteres). **En la misma transacción atómica:** (1) Marca el expediente antiguo como `[AVERÍA CRÓNICA]` y lo transiciona a `status = 'CLOSED'` (`closed_at = CURRENT_TIMESTAMP`) para liberar limpiamente el índice único `uq_machine_active_ticket`; (2) Encola `CHRONIC_BREAKDOWN_ALERT` para Coordinación y Webhook; (3) Crea automáticamente un **nuevo expediente limpio sucesor** con `chronic_predecessor_incident_id = :old_id`, `opportunistic_mode = 'NEW'`, `opportunistic_origin_reason = 'WARRANTY_REOPEN_IN_SITU'`, `status = 'IN_PROGRESS'`, `assigned_technician_id = :tech_id`, `description = :reopen_reason`; (4) Encola `INCIDENT_OPENED_SITE` a la Sede. Devuelve el nuevo expediente creado con `"chronic_replacement_created": true`. |
| **Caso 5 (`RF-05.5`)** | `IN_PROGRESS` de **otro técnico distinto** | **Bloqueo absoluto (Art. V.3):** Responde **HTTP 409 Conflict** con código `INCIDENT_ALREADY_IN_PROGRESS_BY_OTHER_TECHNICIAN`. |

* **Respuesta Exitosa (`200 OK`, o `201 Created` si hubo sustitución automática por Avería Crónica en 3.ª reincidencia):**

```json
{
  "status": "success",
  "message": "Incidencia asumida e iniciada en curso.",
  "data": {
    "id": 102,
    "ticket_code": "INC-2026-8105",
    "status": "IN_PROGRESS",
    "urgency": "MEDIUM",
    "category": "PAYMENT_SYSTEM",
    "machine_id": 13,
    "machine_code": "MAQ-BCN-013",
    "location_id": 3,
    "assigned_technician_id": 4,
    "previous_technician_id": 5,
    "opportunistic_mode": "ASSUMED",
    "opportunistic_mode_label": "Oportunista In Situ · Asumida",
    "opportunistic_origin_reason": "EXISTING_TICKET_CLAIM",
    "opportunistic_origin_label": "Asunción de ticket abierto in situ",
    "chronic_replacement_created": false,
    "chronic_predecessor_incident_id": null,
    "chronic_predecessor_ticket_code": null,
    "started_at": "2026-10-19T08:45:00+02:00",
    "cold_chain_override_alerted": false
  }
}
```

* **Códigos de Error de `POST /api/technician/opportunistic/incidents/{id}/assume`:**
  * `403 LOCATION_NOT_ENABLED_FOR_OPPORTUNISTIC`: Sede no habilitada ni en ventana de gracia.
  * `404 INCIDENT_NOT_FOUND`: El expediente no existe.
  * `409 INCIDENT_ALREADY_IN_PROGRESS_BY_OTHER_TECHNICIAN`: Otro técnico ya tiene esta máquina `IN_PROGRESS` (`RF-05.5`, Art. V.3).
  * `409 ACTIVE_INTERVENTION_AT_DIFFERENT_LOCATION`: El técnico mantiene una intervención `IN_PROGRESS` en otra sede distinta (`RF-02.2`).
  * `409 TAKEOVER_CONFIRMATION_REQUIRED`: El expediente pertenece a otro técnico (`ASSIGNED` / `PENDING_PARTS` / `PENDING_INFO`) y no se envió `confirm_takeover_from_other_tech = true` (`RF-05.3`).
  * `409 COLD_CHAIN_ROUTE_WARNING_CONFIRMATION_REQUIRED`: Hay una parada crítica/sanitaria en otra sede y falta `confirm_cold_chain_override = true` (`RF-03.1`).
  * `422 REOPEN_REASON_TOO_SHORT`: El expediente está en `RESOLVED` (< 48h) y `reopen_reason` tiene menos de 10 caracteres (`RF-05.4`).
  * `422 REOPEN_WINDOW_EXPIRED`: El expediente está en `RESOLVED` pero ya superó las 48h de garantía (`Art. V.6`).
  * `422 INVALID_STATUS_FOR_ASSUME`: El expediente está en `CLOSED` o `CANCELLED`.

---

## 5. Contratos de Notificaciones, Privacidad (Art. V.4) y Métricas

### 5.1. Matriz de Encolado en `notification_outbox` (`RF-06.3`, Resoluciones QA 1 y QA 2)

| Evento Oportunista | Plantilla (`notification_template`) | Canal | Prioridad | Destinatario | Regla de Privacidad / Obsolescencia |
| :--- | :--- | :---: | :---: | :--- | :--- |
| **Alta Oportunista In Situ (`NEW`)** | `INCIDENT_OPENED_SITE` | `EMAIL` | `STANDARD` | `locations.contact_email` | Técnico anonimizado como `"Servicio Técnico Oficial · Operador #[Código]"`. Incluye `opportunistic_informant_name` si se indicó. |
| **Asunción de ticket ya abierto (`ASSUMED` desde `REGISTERED`/`ASSIGNED`/`PENDING_*`)** | *(Ninguna para la Sede)* | — | — | — | **Cero correo redundante a la Sede (`RF-06.3.1`).** |
| **Asunción con traspaso de otro técnico (`RF-05.3`)** | `TECHNICIAN_UNASSIGNED` | **`EMAIL` únicamente** | `STANDARD` | `previous_technician.email` | **Sin canal `TELEGRAM` y sin botón (`RF-TG-06` Módulo 15).** Cancela previamente a `CANCELLED_OBSOLETE` cualquier fila `TECHNICIAN_ASSIGNED` (`EMAIL` y `TELEGRAM`) en `PENDING` / `PENDING_RETRY` del técnico reemplazado. |
| **Reapertura In Situ en Garantía (`RF-05.4.1`)** | `INCIDENT_REOPENED_IN_SITU_SITE` + `INCIDENT_REOPENED_COORDINATOR` | `EMAIL` | `STANDARD` | Sede + Coordinadores activos | Informa a la Sede y a Coordinación de que la incidencia fue reabierta en garantía y ya se encuentra **En Curso** in situ. |
| **3.ª Reincidencia In Situ (`RF-05.4.2` · Avería Crónica)** | `CHRONIC_BREAKDOWN_ALERT` + `INCIDENT_OPENED_SITE` | `EMAIL` + `WEBHOOK` | `CRITICAL_SANITARY` / `STANDARD` | Coordinadores + Webhook (alerta crónica) y Sede (nuevo ticket sucesor) | El ticket antiguo queda `CLOSED` con marca `[AVERÍA CRÓNICA]` y el nuevo ticket sucesor nace `IN_PROGRESS`. |
| **Confirmación de Postergación de Parada Crítica / Frío en Otra Sede (`RF-03.2`)** | `OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT` | `EMAIL` (+ `WEBHOOK` si es vigilancia sanitaria Art. II) | `CRITICAL_SANITARY` | Coordinadores activos (+ Webhook corporativo) | Detalla qué técnico asumió trabajo oportunista en la Sede A y qué máquina crítica/frío le espera en la Sede B para permitir reasignación inmediata. |
| **Resolución Final de Ticket Oportunista o Reabierto In Situ (`RF-06.2`, `RF-06.3.2`)** | `INCIDENT_RESOLVED_SITE` (+ `INCIDENT_RESOLVED_CITIZEN_QR` si el ticket asumido/reabierto tenía `reporter_email` de QR) | `EMAIL` | `STANDARD` | Sede (+ Ciudadano QR original si aplica) | Misma sanitización estricta del Módulo 14 (`Art. V.4`): sin diagnóstico técnico, sin acciones de taller, sin costes y sin `opportunistic_informant_name` en el canal Ciudadano QR. |

### 5.2. Contrato de Métricas en `GET /api/coordinator/metrics/summary` (`RF-06.4`)

El objeto JSON devuelto por `GET /api/coordinator/metrics/summary` incorpora la clave `opportunistic_interventions` manteniendo intactas todas las claves existentes:

```json
{
  "opportunistic_interventions": {
    "total_avoided_dispatches": 14,
    "new_count": 9,
    "assumed_count": 5,
    "by_origin_reason": {
      "SITE_VERBAL_NOTICE": 6,
      "TECHNICIAN_DETECTION": 3,
      "EXISTING_TICKET_CLAIM": 4,
      "WARRANTY_REOPEN_IN_SITU": 1
    }
  }
}
```

---

## 6. Matriz de Trazabilidad Técnica (Spec Funcional $\leftrightarrow$ Contratos)

| Requisito Funcional | Contrato de Datos / Endpoint que lo Satisface |
| :--- | :--- |
| **`RF-01.1`, `RF-01.2`, `RF-01.3`** (Habilitación por parada activa o ventana de 30 min + tarjeta temporal + 5 min tolerancia) | Regla §3.1, `GET /api/technician/my-route?include_opportunistic_context=1` (`enabled_locations`), `GET /api/technician/locations/{location_id}/opportunistic-machines` y flag `form_opened_within_grace`. |
| **`RF-01.4`, `RF-01.5`** (Catálogo de máquinas de la sede y prohibición en máquinas inactivas) | `GET /api/technician/locations/{location_id}/opportunistic-machines` y rechazo `422 MACHINE_INACTIVE`. |
| **`RF-02.1`, `RF-02.2`** (Coexistencia y orden en la misma sede vs. bloqueo por otra sede `IN_PROGRESS`) | Regla §3.2 y código de rechazo `409 ACTIVE_INTERVENTION_AT_DIFFERENT_LOCATION`. |
| **`RF-03.1`, `RF-03.2`** (Advertencia no bloqueante de cadena de frío Art. II y alerta inmediata a Coordinación) | Regla §3.3, código `409 COLD_CHAIN_ROUTE_WARNING_CONFIRMATION_REQUIRED`, flag `confirm_cold_chain_override` y plantilla `OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT` (`CRITICAL_SANITARY`). |
| **`RF-04.1`, `RF-04.2`, `RF-04.3`** (Flujo A: Alta Oportunista `NEW`, foto Art. V.5, prohibición de datos bancarios Art. V.4) | `POST /api/technician/opportunistic/incidents` (§4.3), columnas `opportunistic_mode = 'NEW'`, `opportunistic_origin_reason`, `opportunistic_informant_name` y rechazo `422 CASH_REFUND_PRIVACY_VIOLATION`. |
| **`RF-05.1` a `RF-05.5`** (Flujo B: Asunción `ASSUMED`, traspaso con nota interna, reapertura < 48h y 3.ª reincidencia Crónica) | `POST /api/technician/opportunistic/incidents/{id}/assume` (§4.4), cierre atómico del ticket crónico predecesor + creación del sucesor con `chronic_predecessor_incident_id` y bloqueo `409 INCIDENT_ALREADY_IN_PROGRESS_BY_OTHER_TECHNICIAN`. |
| **`RF-06.1`, `RF-06.2`, `RF-06.3`, `RF-06.4`** (Desbloqueo de acceso 72h, protocolo sanitario Módulo 11, Outbox/Telegram Módulo 15 y KPI) | Extensión de `POST /api/technician/incidents/{id}/resolve`, matriz de notificaciones §5.1 y extensión de `GET /api/coordinator/metrics/summary` (§5.2). |
