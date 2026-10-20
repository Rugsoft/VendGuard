# ESPECIFICACIÓN TÉCNICA · CONTRATOS DE DATOS Y API DE ZONIFICACIÓN OPERATIVA Y ASIGNACIÓN ASISTIDA

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `17-operational-zones`  
**Documento:** `specs/technical/operational_zones_contracts.md`  
**Referencia Funcional:** [`specs/17-operational-zones/spec.md`](../17-operational-zones/spec.md) y [`specs/functional/operational_zones_spec.md`](../functional/operational_zones_spec.md) (`RF-01` a `RF-07`, `RNF-01` a `RNF-04`, Resoluciones QA 1 a 12)  
**Motor de Persistencia:** PostgreSQL 16+ (Supabase / Motor Local de Certificación) · Motor Único  
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`)  
**Conformidad Constitucional:** Artículos I, II, III (3.1, 3.2, 3.3), IV, V (5.1, 5.3, 5.4, 5.7) y VI  

---

## 1. Visión General de Endpoints y Superficies del Módulo

| Método | Ruta | Rol / Middleware | Propósito Operativo |
| :--- | :--- | :--- | :--- |
| **Administración Territorial de Zonas** | | | |
| `GET` | `/api/coordinator/zones` | `InternalAuthMiddleware(COORDINATOR)` | Devuelve el listado completo de zonas operativas (activas e inactivas) con contadores agregados de sedes vinculadas, técnicos titulares/suplentes y códigos postales (`RF-01.1`). |
| `POST` | `/api/coordinator/zones` | `InternalAuthMiddleware(COORDINATOR)` | Crea una nueva zona operativa con código único alfanumérico, nombre, color hexadecimal y descripción opcional (`RF-01.1`, `DoD-01`). |
| `GET` | `/api/coordinator/zones/{id}` | `InternalAuthMiddleware(COORDINATOR)` | Ficha de detalle de una zona operativa, incluyendo sus códigos postales asociados, sedes vinculadas y cuadrante técnico asignado (`RF-01.1`). |
| `PUT` | `/api/coordinator/zones/{id}` | `InternalAuthMiddleware(COORDINATOR)` | Actualiza metadatos de la zona (nombre, color corporativo, descripción o conmutación de estado activo/inactivo). Desactivar exige `confirm_release = true` cuando la zona conserva códigos postales o cuadrante, y libera ambos en la misma transacción (QA 7). |
| `POST` | `/api/coordinator/zones/{id}/postal-codes` | `InternalAuthMiddleware(COORDINATOR)` | **Gestión de Códigos Postales (`RF-01.2`, `RF-03.3` / QA 4 / QA 7):** Asignación o sustitución en bloque de códigos postales. Detecta colisiones previas, **mueve** la fila del CP (`UPDATE zone_id`, nunca duplica por la `PRIMARY KEY`) y traslada las sedes heredadas generando eventos de auditoría dual matriz-sede (Art. III / QA 6). |
| `POST` | `/api/coordinator/zones/{id}/technicians` | `InternalAuthMiddleware(COORDINATOR)` | **Cuadrante Técnico (`RF-02.1`, `RF-02.2`):** Vincula técnicos a la zona definiendo su condición de Titular Principal (`is_primary = TRUE`, máx. 1 zona activa por técnico) o Suplente (`is_primary = FALSE`). |
| `DELETE` | `/api/coordinator/zones/{id}/technicians/{technician_id}` | `InternalAuthMiddleware(COORDINATOR)` | Da de baja **lógica** (`removed_at`) al técnico del cuadrante, preservando el histórico (Art. III.1 / QA 10). Es idempotente: si la vinculación ya estaba extinguida responde `200` sin escribir. |
| **Asignación Territorial en Sedes** | | | |
| `POST` | `/api/coordinator/locations/{id}/override-zone` | `InternalAuthMiddleware(COORDINATOR)` | **Asignación Forzada Manual (`RF-03.2`):** Asigna manualmente una sede a una zona específica (`is_manual_override = TRUE`) o restablece la herencia automática por código postal (`is_manual_override = FALSE`). |
| **Motor de Recomendación y Triaje Asistido** | | | |
| `GET` | `/api/coordinator/incidents/{id}/suggested-technicians` | `InternalAuthMiddleware(COORDINATOR)` | **Recomendador de Idoneidad (`RF-04`, `RF-05`, `RF-06`):** Devuelve la lista ordenada de técnicos activos con su puntuación desglosada, insignias explicativas, detección de guardia 24/7 de frío (Art. II) y distancias esféricas Haversine. |
| `PATCH` | `/api/coordinator/incidents/{id}/assign` | `InternalAuthMiddleware(COORDINATOR)` | *(Extensión `RF-05.2` / QA 1 / QA 8)* Asigna o reasigna un técnico al expediente. Si la recomendación sanitaria está activa y se descarta al técnico sugerido, exige `override_justification` de al menos 20 caracteres (Art. V.1); el campo convive con `reassignment_reason` sin sustituirlo. |
| **Consultas Informativas de Técnicos y Privacidad** | | | |
| `GET` | `/api/technician/profile` o `/api/technician/my-route` | `InternalAuthMiddleware(TECHNICIAN)` | *(Extensión informativa)* Expone en la sesión del técnico su zona titular y suplencias asignadas sin permitir mutación. |
| `GET` | `/api/locations/{site_code}/...` | `SiteAuthMiddleware` | *(Blindaje Art. V.4)* Ocultación estricta: ningún endpoint del portal de sede ni escaneo QR expone zonas ni cuadrantes. |

---

## 2. Contrato de Esquema de Base de Datos (PostgreSQL 16+ / Supabase)

La persistencia del Módulo 17 se consolida en la migración incremental `database/supabase_migrations/007_operational_zones.sql` y converge en `database/supabase_init.sql`.

### 2.1. Definición DDL de Nuevas Tablas e Índices

```sql
-- ============================================================================
-- 1. TABLA: operational_zones (Catálogo Maestro de Zonas Operativas)
-- ============================================================================
CREATE TABLE IF NOT EXISTS operational_zones (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(50) NOT NULL,
    name        VARCHAR(100) NOT NULL,
    color_hex   VARCHAR(7) NOT NULL DEFAULT '#2560ff',
    description TEXT,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_operational_zones_code UNIQUE (code),
    CONSTRAINT uq_operational_zones_name UNIQUE (name),
    CONSTRAINT chk_operational_zones_code_len CHECK (length(trim(code)) >= 3),
    CONSTRAINT chk_operational_zones_name_len CHECK (length(trim(name)) >= 3),
    CONSTRAINT chk_operational_zones_color_format CHECK (color_hex ~* '^#[0-9A-Fa-f]{6}$')
);

-- ============================================================================
-- 2. TABLA: operational_zone_postal_codes (Mapeo de Códigos Postales)
-- Regla de Unicidad Territorial: 1 CP vivo = 1 sola zona (RF-01.2)
-- Resolución QA 13: la liberación es lógica (released_at). La clave primaria
-- pasa a un identificador sustituto y la unicidad territorial se declara en un
-- índice único **parcial** sobre las filas vivas: así un CP liberado queda
-- disponible para otra zona sin borrar su historia y sin tocar el disparador
-- anti-borrado (Art. III.1).
-- ============================================================================
CREATE TABLE IF NOT EXISTS operational_zone_postal_codes (
    id          BIGINT GENERATED ALWAYS AS IDENTITY,
    postal_code VARCHAR(5) NOT NULL,
    zone_id     BIGINT NOT NULL REFERENCES operational_zones(id) ON DELETE RESTRICT,
    released_at TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_operational_zone_postal_codes PRIMARY KEY (id),
    CONSTRAINT chk_postal_code_format CHECK (postal_code ~ '^[0-9]{5}$')
);

-- Un CP pertenece a una única zona **viva**; las filas liberadas se conservan.
CREATE UNIQUE INDEX IF NOT EXISTS uq_zone_postal_codes_live
    ON operational_zone_postal_codes (postal_code)
    WHERE released_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_zone_postal_codes_zone_id 
    ON operational_zone_postal_codes (zone_id);

-- ============================================================================
-- 3. ALTERACIÓN: locations (Vinculación Territorial y Herencia Manual)
-- ============================================================================
ALTER TABLE locations
    ADD COLUMN IF NOT EXISTS zone_id BIGINT REFERENCES operational_zones(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS is_manual_override BOOLEAN NOT NULL DEFAULT FALSE,
    -- Resolución QA 7: la herencia territorial necesita el CP en la propia sede.
    -- Se admite NULL para no inventar datos sobre sedes históricas; la
    -- obligatoriedad se exige en el alta y la edición (validación de aplicación).
    ADD COLUMN IF NOT EXISTS postal_code VARCHAR(5);

ALTER TABLE locations
    DROP CONSTRAINT IF EXISTS chk_locations_postal_code_format;
ALTER TABLE locations
    ADD CONSTRAINT chk_locations_postal_code_format
    CHECK (postal_code IS NULL OR postal_code ~ '^[0-9]{5}$');

CREATE INDEX IF NOT EXISTS idx_locations_postal_code
    ON locations (postal_code);

CREATE INDEX IF NOT EXISTS idx_locations_zone_id 
    ON locations (zone_id);

-- ============================================================================
-- 4. TABLA: technician_zone_assignments (Cuadrante Técnico Titular y Suplente)
-- ============================================================================
CREATE TABLE IF NOT EXISTS technician_zone_assignments (
    id            BIGSERIAL PRIMARY KEY,
    technician_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    zone_id       BIGINT NOT NULL REFERENCES operational_zones(id) ON DELETE RESTRICT,
    is_primary    BOOLEAN NOT NULL DEFAULT FALSE,
    assigned_at   TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Resolución QA 10: la baja es lógica; el histórico no se borra (Art. III.1).
    removed_at    TIMESTAMPTZ
);

-- Unicidad solo sobre vinculaciones vivas: permite reingresos con histórico.
CREATE UNIQUE INDEX IF NOT EXISTS uq_technician_zone_live
    ON technician_zone_assignments (technician_id, zone_id)
    WHERE removed_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_tech_zone_assignments_zone_id 
    ON technician_zone_assignments (zone_id);

CREATE INDEX IF NOT EXISTS idx_tech_zone_assignments_tech_id 
    ON technician_zone_assignments (technician_id);

-- Regla de Titularidad Única: un técnico solo puede ser Titular Principal (is_primary = TRUE)
-- de una zona viva simultáneamente (RF-02.1 / QA 4 / QA 10)
CREATE UNIQUE INDEX IF NOT EXISTS uq_technician_primary_zone 
    ON technician_zone_assignments (technician_id) 
    WHERE is_primary = TRUE AND removed_at IS NULL;
```

### 2.2. Disparador de Actualización de Marcas Temporales

```sql
CREATE OR REPLACE FUNCTION trg_operational_zones_touch_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_operational_zones_updated_at ON operational_zones;
CREATE TRIGGER trg_operational_zones_updated_at
    BEFORE UPDATE ON operational_zones
    FOR EACH ROW
    EXECUTE FUNCTION trg_operational_zones_touch_updated_at();
```

### 2.3. Extensión del ENUM `audit_entity_type` (Resolución QA 11)

```sql
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'OPERATIONAL_ZONE';
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'TECHNICIAN_ZONE';
```

* El runner envuelve cada migración en una transacción (`PostgresMigrationRunner`): PostgreSQL 12+ admite `ADD VALUE` dentro de transacción, pero el valor nuevo **no puede usarse hasta el commit**, así que la `007` solo ejecuta DDL y ningún `INSERT` de auditoría de estas dos entidades.
* Se replica en la declaración de `database/supabase_init.sql` (build limpio), en `AuditEvent::ENTITY_TYPES`, en `public/assets/js/utils/AuditActionLabels.js` (entidades y acciones) y en las tres listas `CONTRACT_TABLES` (`PostgresSchemaTest`, `SchemaConvergenceParityTest`, `PostgresConstitutionalTest`), que pasan de 22 a **25 tablas**.

---

## 3. Contratos de API REST

### 3.1. `GET /api/coordinator/zones`

Devuelve el catálogo de zonas operativas configuradas para el Coordinador.

* **Headers:** `Authorization: Bearer <token_coordinator>`
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "zones": [
      {
        "id": 1,
        "code": "ZONA-BCN-NORTE",
        "name": "Barcelona Litoral y Norte",
        "color_hex": "#2560ff",
        "description": "Distritos de Sant Martí, Poblenou y área costera",
        "is_active": true,
        "locations_count": 14,
        "primary_technicians_count": 2,
        "backup_technicians_count": 1,
        "postal_codes_count": 5,
        "postal_codes": ["08005", "08018", "08019", "08020", "08030"],
        "created_at": "2026-10-20T08:00:00Z",
        "updated_at": "2026-10-20T08:00:00Z"
      }
    ],
    "unassigned_locations_count": 3
  }
}
```

---

### 3.2. `POST /api/coordinator/zones`

Crea una nueva zona operativa.

* **Headers:** `Content-Type: application/json`, `Authorization: Bearer <token_coordinator>`
* **Payload de Entrada:**
```json
{
  "code": "ZONA-BCN-SUR",
  "name": "Barcelona Sur y Sants",
  "color_hex": "#10b981",
  "description": "Distrito de Sants-Montjuïc y Les Corts"
}
```
* **Respuesta Exitosa (`201 Created`):**
```json
{
  "success": true,
  "data": {
    "zone": {
      "id": 2,
      "code": "ZONA-BCN-SUR",
      "name": "Barcelona Sur y Sants",
      "color_hex": "#10b981",
      "description": "Distrito de Sants-Montjuïc y Les Corts",
      "is_active": true,
      "locations_count": 0,
      "postal_codes_count": 0,
      "created_at": "2026-10-20T08:30:00Z"
    }
  }
}
```
* **Errores Estándar:**
  * `400 BAD_REQUEST`: Formato de color o campos inválidos.
  * `409 ZONE_CODE_ALREADY_EXISTS`: El código alfanumérico ya existe.
  * `409 ZONE_NAME_ALREADY_EXISTS`: El nombre ya está en uso.

---

### 3.3. `POST /api/coordinator/zones/{id}/postal-codes`

Asigna o sustituye la colección de códigos postales asociados a una zona operativa.

* **Headers:** `Content-Type: application/json`, `Authorization: Bearer <token_coordinator>`
* **Payload de Entrada:**
```json
{
  "postal_codes": ["08001", "08002", "08003"],
  "confirm_transfers": true
}
```
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "zone_id": 1,
    "assigned_postal_codes": ["08001", "08002", "08003"],
    "transferred_postal_codes": [
      {
        "postal_code": "08001",
        "previous_zone_id": 2,
        "previous_zone_name": "Barcelona Centro"
      }
    ],
    "affected_locations_count": 8
  }
}
```
* **Detección Previa de Conflictos (`409 POSTAL_CODES_REQUIRE_CONFIRMATION`):**  
  Si se envían códigos postales que ya pertenecían a otra zona activa y `confirm_transfers` es `false` (o no se envía):
```json
{
  "success": false,
  "error": {
    "code": "POSTAL_CODES_REQUIRE_CONFIRMATION",
    "message": "Uno o varios códigos postales pertenecen a otra zona activa y requieren confirmación de traslado.",
    "conflicts": [
      {
        "postal_code": "08001",
        "current_zone_id": 2,
        "current_zone_name": "Barcelona Centro",
        "affected_locations_count": 3
      }
    ]
  }
}
```

---

### 3.4. `POST /api/coordinator/zones/{id}/technicians`

Vincula o actualiza el rol de un técnico dentro de la zona (titular principal o suplente).

* **Headers:** `Content-Type: application/json`, `Authorization: Bearer <token_coordinator>`
* **Payload de Entrada:**
```json
{
  "technician_id": 5,
  "is_primary": true,
  "confirm_primary_override": true
}
```
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "assignment": {
      "id": 12,
      "technician_id": 5,
      "technician_name": "Jordi Ruta",
      "zone_id": 1,
      "is_primary": true,
      "assigned_at": "2026-10-20T08:45:00Z"
    }
  }
}
```
* **Errores Estándar:**
  * `409 TECHNICIAN_ALREADY_PRIMARY_IN_OTHER_ZONE`: Si el técnico ya es titular de otra zona y `confirm_primary_override` no se envió en `true`.

---

### 3.5. `POST /api/coordinator/locations/{id}/override-zone`

Aplica una asignación forzada manual a una sede o restablece su herencia por código postal.

* **Payload de Entrada (Forzar Zona):**
```json
{
  "zone_id": 2,
  "is_manual_override": true
}
```
* **Payload de Entrada (Restablecer Herencia Automática):**
```json
{
  "is_manual_override": false
}
```
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "location_id": 4,
    "site_code": "SEDE-BCN-01",
    "postal_code": "08005",
    "zone_id": 1,
    "zone_name": "Barcelona Litoral y Norte",
    "is_manual_override": false,
    "resolution_mode": "INHERITED_BY_POSTAL_CODE"
  }
}
```

---

### 3.6. `GET /api/coordinator/incidents/{id}/suggested-technicians`

Punto central del recomendador. Evalúa a todos los técnicos disponibles frente a la incidencia concreta, desglosando el scoring algorítmico y determinando la presencia de prioridades sanitarias.

* **Headers:** `Authorization: Bearer <token_coordinator>`
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "incident": {
      "id": 1042,
      "ticket_code": "INC-20261020-0012",
      "priority": "CRITICAL",
      "is_cold_chain_risk": true,
      "location": {
        "id": 4,
        "site_code": "SEDE-BCN-01",
        "name": "Hospital del Mar",
        "postal_code": "08005",
        "zone_id": 1,
        "zone_name": "Barcelona Litoral y Norte"
      }
    },
    "recommendation": {
      "top_technician_id": 7,
      "is_cold_chain_escalation": true,
      "unattended_zone": false,
      "explanation": "🚨 Prioridad Sanitaria Art. II: Titular saturado; técnico de guardia 24/7 antepuesto para asegurar SLA < 4h."
    },
    "ranked_technicians": [
      {
        "technician_id": 7,
        "name": "Marta Guardia",
        "operator_code": "OP-774",
        "is_on_call": true,
        "affinity_type": "PRIMARY",
        "zone_affinity_label": "Titular Zona Norte",
        "total_score": 140,
        "score_breakdown": {
          "territorial_affinity": 50,
          "onsite_presence": 0,
          "same_zone_stops": 0,
          "active_workload_penalty": -10,
          "inter_location_penalty": 0,
          "cold_chain_on_call_bonus": 100
        },
        "active_workload": {
          "active_tickets_count": 2,
          "pending_info_count": 0,
          "preventive_orders_count": 0
        },
        "current_status": {
          "has_in_progress_stop": false,
          "in_progress_location_name": null,
          "distance_km": 1.4
        },
        "is_top_suggestion": true,
        "badges": ["RECOMENDADO", "GUARDIA_24_7", "TITULAR_ZONA"]
      },
      {
        "technician_id": 5,
        "name": "Jordi Ruta",
        "operator_code": "OP-512",
        "is_on_call": false,
        "affinity_type": "PRIMARY",
        "zone_affinity_label": "Titular Zona Norte",
        "total_score": 25,
        "score_breakdown": {
          "territorial_affinity": 50,
          "onsite_presence": 0,
          "same_zone_stops": 20,
          "active_workload_penalty": -30,
          "inter_location_penalty": -15,
          "cold_chain_on_call_bonus": 0
        },
        "active_workload": {
          "active_tickets_count": 5,
          "pending_info_count": 1,
          "preventive_orders_count": 1
        },
        "current_status": {
          "has_in_progress_stop": true,
          "in_progress_location_name": "Torre Glòries (Sede BCN-02)",
          "distance_km": 2.8
        },
        "is_top_suggestion": false,
        "badges": ["TITULAR_ZONA", "EN_CURSO_OTRA_SEDE", "CARGA_ALTA"]
      }
    ]
  }
}
```

---

### 3.7. `PATCH /api/coordinator/incidents/{id}/assign` *(Extensión Art. V.1 / QA 8)*

Extensión del endpoint de asignación existente para incorporar la justificación obligatoria cuando se descarta la sugerencia sanitaria de frío del Art. II.

* **Payload de Entrada:**
```json
{
  "technician_id": 5,
  "override_justification": "Técnico Jordi finaliza en 10 min en Torre Glòries y se desplaza de inmediato al Hospital del Mar (distancia 5 min)."
}
```
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "incident_id": 1042,
    "assigned_technician_id": 5,
    "assigned_technician_name": "Jordi Ruta",
    "status": "ASSIGNED"
  }
}
```
* **Error de Validación Constitucional (`422 COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED`):**  
  Si se intenta asignar a un técnico que no es el técnico de guardia sugerido en una avería crítica sin aportar justificación $\ge 20$ caracteres:
```json
{
  "success": false,
  "error": {
    "code": "COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED",
    "message": "La avería presenta riesgo crítico de frío (Art. II). Si descarta al técnico de guardia sugerido, debe aportar una justificación obligatoria de al menos 20 caracteres."
  }
}
```

---

## 4. Contrato Algorítmico del Motor de Ponderación (`TechnicianWorkloadScorer`)

El índice numérico final de idoneidad ($Score_{total}$) para cada técnico se rige por la siguiente especificación determinista:

$$Score_{total} = S_{territorial} + S_{in\_situ} + S_{misma\_zona} + S_{inter\_sede} + S_{carga} + S_{frio}$$

### 4.1. Reglas de Puntuación Componente a Componente

| Componente | Condición Evaluada | Puntos |
| :--- | :--- | :--- |
| **Afinidad Territorial ($S_{territorial}$)** | Es Titular Principal (`is_primary = TRUE`) de la zona de la sede | **+50** |
| | Es Suplente (`is_primary = FALSE`) de la zona de la sede | **+25** |
| | No está asignado a la zona de la sede | **0** |
| **Presencia In Situ ($S_{in\_situ}$)** | Tiene hoy una parada viva (`ASSIGNED`/`IN_PROGRESS`) en la **misma sede** averiada | **+40** |
| **Proximidad de Zona ($S_{misma\_zona}$)** | Tiene paradas vivas hoy en **otras sedes de la misma zona** (excluyente con `+40`) | **+20** |
| **Incompatibilidad entre Sedes ($S_{inter\_sede}$)** | Tiene actualmente parada `IN_PROGRESS` abierta en una **sede diferente** (Módulo 16 / Art. V.3) | **-15** |
| **Carga de Paradas Vivas ($S_{carga}$)** | Por cada avería en `ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS` | **-5** (por ticket) |
| | Por cada preventivo en `IN_INSPECTION` o `SCHEDULED` para hoy | **-5** (por orden) |
| | Por cada avería pausada por acceso `PENDING_INFO` (Módulo 11) | **-2** (atenuado) |
| **Escalado Sanitario de Frío ($S_{frio}$)** | Avería `CRITICAL` o perecedera: la recomendación sanitaria se activa cuando **ningún titular activo de la zona está libre** de paradas previas; el bono se aplica al técnico de guardia (`users.is_on_call = TRUE`). Sin guardia, la recomendación recae en el activo con menor carga | **+100** |

### 4.2. Algoritmo de Desempate Determinista (QA 5 / QA 12)
1. Si dos o más técnicos empatan en $Score_{total}$, se desempata por **mayor tiempo transcurrido desde la última asignación formal**, tomando `MAX(incidents.assigned_at)` de los expedientes que el técnico **tiene asignados hoy**. `incident_history` no registra `assigned_at` y el `audit_log` se reserva para trazabilidad, no para el cálculo.
2. Un técnico **sin ninguna asignación registrada** se trata como asignado en `epoch`, de modo que pasa por delante de cualquier compañero con trabajo reciente (reparto de fatiga).
3. Si persiste el empate (ej. dos técnicos sin asignaciones previas), se desempata por **menor ID de usuario** (`users.id ASC`), garantizando 100% de reproducibilidad en pruebas unitarias.

### 4.3. Proximidad Espacial Haversine en Zonas Desatendidas (RF-06 / QA 5)
Cuando una zona no tiene técnicos vinculados o todos están saturados/inactivos, la ordenación secundaria por distancia esférica ($d$) utiliza el servicio canónico `GeoDistanceService`:

$$d = 2 R \arcsin \left( \sqrt{\sin^2\left(\frac{\Delta\phi}{2}\right) + \cos(\phi_1)\cos(\phi_2)\sin^2\left(\frac{\Delta\lambda}{2}\right)} \right)$$

* $R = 6371.0\text{ km}$.
* Coordenadas de referencia del técnico tomadas en orden de disponibilidad:
  1. Coordenadas de su parada `IN_PROGRESS` actual.
  2. Coordenadas de su última parada `RESOLVED` en el día.
  3. Coordenadas fijas de la Base Central (`route_settings.base_latitude`, `route_settings.base_longitude`).
* Ni `locations.latitude`/`longitude` ni la Base Central admiten `NULL` (son `NOT NULL` con valores por defecto): el caso degradado es una **coordenada inválida** (`0,0`, no finita o fuera del área operativa que valida `GeoDistanceService`), para la que se fija $d = 999.0\text{ km}$.

---

## 5. Contrato de Trazabilidad y Auditoría (Art. III / QA 6)

Todos los eventos de mutación territorial se persisten de forma transaccional e inmutable en la tabla `audit_log`.

### 5.1. Catálogo de Acciones de Auditoría del Módulo

| `entity_type` | `action` | Disparador Operativo |
| :--- | :--- | :--- |
| `OPERATIONAL_ZONE` | `ZONE_CREATED` | Alta de nueva zona operativa. |
| `OPERATIONAL_ZONE` | `ZONE_UPDATED` | Modificación de nombre, color o descripción. |
| `OPERATIONAL_ZONE` | `ZONE_DEACTIVATED` | Desactivación lógica de una zona. |
| `OPERATIONAL_ZONE` | `ZONE_POSTAL_CODE_TRANSFERRED` | Traslado masivo de códigos postales entre zonas (evento matriz). |
| `LOCATION` | `LOCATION_ZONE_REASSIGNED` | Reasignación de zona en una sede individual (evento hijo). |
| `LOCATION` | `LOCATION_ZONE_MANUAL_OVERRIDE` | Asignación forzada manual o liberación de herencia. |
| `TECHNICIAN_ZONE` | `TECHNICIAN_ZONE_ASSIGNED` | Asignación de técnico como titular o suplente. |
| `TECHNICIAN_ZONE` | `TECHNICIAN_ZONE_REMOVED` | Baja lógica de técnico del cuadrante de zona (`removed_at`). |
| `TECHNICIAN_ZONE` | `TECHNICIAN_ZONE_PRIMARY_DEMOTED` | Degradación automática de la titularidad previa al confirmar `confirm_primary_override` (QA 9). |
| `INCIDENT` | `INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN` | Asignación donde el coordinador descartó la sugerencia sanitaria de frío. |

### 5.2. Estructura de Payload en Traslado Masivo (Auditoría Dual Atómica)

```json
{
  "entity_type": "OPERATIONAL_ZONE",
  "entity_id": 1,
  "action": "ZONE_POSTAL_CODE_TRANSFERRED",
  "user_id": 2,
  "payload": {
    "source_zone_id": 2,
    "source_zone_name": "Barcelona Centro",
    "destination_zone_id": 1,
    "destination_zone_name": "Barcelona Litoral y Norte",
    "postal_codes_transferred": ["08001"],
    "affected_locations_count": 3,
    "affected_location_ids": [10, 14, 22]
  }
}
```

Simultáneamente, para cada sede en `affected_location_ids` se inserta:

```json
{
  "entity_type": "LOCATION",
  "entity_id": 10,
  "action": "LOCATION_ZONE_REASSIGNED",
  "user_id": 2,
  "payload": {
    "previous_zone_id": 2,
    "new_zone_id": 1,
    "postal_code": "08001",
    "reason": "BATCH_POSTAL_CODE_TRANSFER"
  }
}
```

---

## 6. Blindaje de Privacidad y Segregación de Datos (Art. V.4)

1. **Aislamiento en Portal de Sede:**  
   Los controladores `LocationPortalController` y los endpoints de reporte QR (`/api/public/qr/report`, `/api/locations/{site_code}/machines`) **jamás** incluirán los campos `zone_id`, `zone_name`, `technician_zone_assignments` ni scoring interno en sus payloads JSON.
2. **Anonimización del Técnico:**  
   Aunque el recomendador sugiera al técnico por su afinidad territorial de zona, el responsable de sede solo visualizará:  
   `"Servicio Técnico Oficial · Operador #[Código]"`.
3. **Prohibición de Rastreo en Tiempo Real:**  
   La proximidad espacial se calcula exclusivamente a partir de las coordenadas de sedes fijas de paradas registradas en el sistema. Queda terminantemente prohibido almacenar o consumir coordenadas GPS en tiempo real o en segundo plano de los dispositivos móviles de los técnicos.
4. **Código Postal de la Sede:** El CP de una sede (`locations.postal_code`) es un dato propio del cliente y puede mostrarse en su portal; lo que nunca se expone es su interpretación territorial (`zone_id`, `zone_name`, cuadrante o scoring).

---

## 7. Matriz de Trazabilidad de Requisitos

| Requisito | Cláusula EARS / Criterio | Componente / Endpoint que lo Satisface |
| :--- | :--- | :--- |
| **RF-01.1** | Alta, edición y desactivación de zonas | `POST/PUT /api/coordinator/zones`, tabla `operational_zones` |
| **RF-01.2** | Formato 5 dígitos y unicidad de CP | `operational_zone_postal_codes.postal_code (PK)`, `CHECK (^\d{5}$)` |
| **RF-01.3** | Confirmación en traslados y desactivación | `POST /api/coordinator/zones/{id}/postal-codes`, `PUT /api/coordinator/zones/{id}` |
| **RF-02.1** | Máx. 1 titular principal por técnico | Índice parcial `uq_technician_primary_zone WHERE is_primary = TRUE` |
| **RF-02.2** | Exclusión de técnicos inactivos | `TechnicianWorkloadScorer` filtra `users.is_active = TRUE` |
| **RF-03.1** | Herencia de zona por CP | `locations.postal_code` (QA 7) resuelto contra `operational_zone_postal_codes` en el alta y la edición de sede |
| **RF-03.2** | Asignación forzada manual | Columna `locations.is_manual_override`, endpoint `/override-zone` |
| **RF-03.3** | Preservación de rutas en traslados | El traslado actualiza `locations.zone_id` pero **no** modifica `incidents.assigned_technician_id` |
| **RF-04.1** | Scoring de idoneidad (+50/+25, +40/+20 excluyentes, -15, -5/-2) | `TechnicianWorkloadScorer::computeScore()` |
| **RF-04.2** | Desempate por última asignación | Ordenación determinista por `assigned_at ASC` (mayor tiempo sin asignar) |
| **RF-05.1** | Salvaguarda sanitaria de frío (Art. II) | Escalamiento $+100$ pts al técnico de guardia ante riesgo de 4h |
| **RF-05.2** | Justificación obligatoria $\ge 20$ chars en `override_justification` (Art. V.1) | Validación en `PATCH /api/coordinator/incidents/{id}/assign` (QA 8) |
| **RF-06.1** | Manejo de zonas desatendidas | Detección de `unattended_zone = true` y fallback por proximidad Haversine |
| **RF-06.2** | Proximidad Haversine sin GPS | `GeoDistanceService` midiendo respecto a parada actual o Base Central |
| **RF-07.1** | Aislamiento y privacidad (Art. V.4) | `LocationPortalController` sanitiza todo dato de zonificación |
| **RNF-01** | Rendimiento $< 100\text{ ms}$ (mediana de 20 repeticiones, 6 técnicos sembrados) | Consultas combinadas CTE con índices en `zone_id`, `technician_id` y `postal_code` |
| **RNF-03** | Auditoría atómica dual (Art. III) | Transacción PDO con eventos `OPERATIONAL_ZONE_*` y `LOCATION_ZONE_*` |
| **RNF-04** | Consistencia e idempotencia | Transacciones acotadas con bloqueo `FOR UPDATE` |
