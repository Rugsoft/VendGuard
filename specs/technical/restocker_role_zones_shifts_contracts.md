# CONTRATOS TÉCNICOS: ROL DE CARGADOR/REPONEDOR DE RUTA (`RESTOCKER`), ZONIFICACIÓN DE CARGA Y CONTROL DE JORNADA

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)\
**Módulo:** `31-restocker-role-zones-shifts` (Feature 21 de `docs/features_pendientes.md`)\
**Documento Canónico:** `specs/technical/restocker_role_zones_shifts_contracts.md`\
**Referencia Funcional:** [`specs/31-restocker-role-zones-shifts/spec.md`](../31-restocker-role-zones-shifts/spec.md) y [`specs/functional/restocker_role_zones_shifts_spec.md`](../functional/restocker_role_zones_shifts_spec.md) (v2.0 · `RF-01` a `RF-05`, `D1`–`D3`, `C1`–`C3`, `QA 1`–`QA 4`)\
**Motor de Persistencia:** PostgreSQL 16+ (Supabase / Motor Local de Certificación) · Motor Único\
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`)\
**Conformidad Constitucional:** Artículos I, II, III (3.1, 3.2, 3.3), IV, V (5.1, 5.3, 5.4) y VI

---

## 1. Visión General de Endpoints y Superficies del Módulo

| Método | Ruta | Rol / Middleware | Propósito Operativo |
| :--- | :--- | :--- | :--- |
| **CRUD de Personal Interno (`RF-01`)** | | | |
| `GET` | `/api/coordinator/staff` | `InternalAuthMiddleware(COORDINATOR)` | Lista el personal interno (`COORDINATOR`, `TECHNICIAN`, `RESTOCKER`) con filtro opcional `?role=ALL\|COORDINATOR\|TECHNICIAN\|RESTOCKER`. |
| `POST` | `/api/coordinator/staff` | `InternalAuthMiddleware(COORDINATOR)` | Crea un usuario interno; si `role = 'RESTOCKER'`, exige `operator_code` único, fuerza `is_on_call = FALSE` (rechaza `true` con `422 RESTOCKER_CANNOT_BE_ON_CALL`) y audita `STAFF_USER_CREATED` (`RF-01.1`). |
| `PUT` | `/api/coordinator/staff/{id}` | `InternalAuthMiddleware(COORDINATOR)` | Actualiza datos del usuario; bloquea cambios de `role` con `409 ROLE_CHANGE_FORBIDDEN` (`C1`). Si desactiva (`is_active = false`) a un operario con turno abierto, lo cierra automáticamente en la misma transacción con `FORCE_CLOSE_SHIFT` (`C3`). |
| **Zonificación de Carga y Cobertura (`RF-02`, `RF-03`)** | | | |
| `GET` | `/api/coordinator/zones` | `InternalAuthMiddleware(COORDINATOR)` | Extiende el catálogo de zonas con `primary_restockers_count` y `backup_restockers_count` (`QA 2`). |
| `GET` | `/api/coordinator/zones/{id}` | `InternalAuthMiddleware(COORDINATOR)` | Extiende el detalle de zona con `primary_restockers_count` y `backup_restockers_count` (`QA 2`). |
| `GET` | `/api/coordinator/zones/{id}/restockers` | `InternalAuthMiddleware(COORDINATOR)` | Devuelve las vinculaciones vivas (`removed_at IS NULL`) del Cuadrante de Carga de la zona (`PRIMARY` primero, luego `BACKUP`, orden alfabético), incluyendo cuentas de baja/ausentes con sus insignias (`RF-02.4`, `C3`). |
| `POST` | `/api/coordinator/zones/{id}/restockers` | `InternalAuthMiddleware(COORDINATOR)` | Vincula un usuario `RESTOCKER` activo a la zona (`role_in_zone`: `'PRIMARY'` \| `'BACKUP'` o `is_primary`: `true` \| `false`). Si ya es `PRIMARY` de otra zona, exige `confirm_primary_transfer = true` (`409 PRIMARY_ZONE_ALREADY_ASSIGNED`, `RF-02.2`). |
| `DELETE` | `/api/coordinator/zones/{id}/restockers/{restocker_id}` | `InternalAuthMiddleware(COORDINATOR)` | Baja lógica idempotente (`removed_at = NOW()`, `200 OK`) del reponedor en la zona (`RF-02.3`, `QA 4`). |
| `GET` | `/api/coordinator/restockers/quadrants` | `InternalAuthMiddleware(COORDINATOR)` | Consulta inversa: agrupa por reponedor todas sus zonas vivas (`PRIMARY` y `BACKUP`), incluyendo a los reponedores activos sin zona (`RF-02.4`). |
| `GET` | `/api/coordinator/zones/{id}/restocker-coverage` | `InternalAuthMiddleware(COORDINATOR)` | Calcula en tiempo real la cobertura de carga de la zona según el escalafón determinista de 6 niveles activos y designa al `#1 RECOMENDADO ⭐` (`RF-03.1`, `RF-03.2`, `C2`, `QA 3`). |
| **Vista Móvil y Control de Jornada del Reponedor (`RF-04`)** | | | |
| `GET` | `/api/restocker/my-route` | `InternalAuthMiddleware(RESTOCKER)` | Devuelve el perfil operativo del reponedor autenticado, sus zonas vivas (`PRIMARY` y `BACKUP`), su estado de turno activo y el resumen base de su ruta de carga (`D1`, `RF-04.1`). |
| `GET` | `/api/technician/shifts/current` | `InternalAuthMiddleware(TECHNICIAN, RESTOCKER)` | Devuelve el turno actual (`ON_SHIFT`, `MEAL_BREAK`, `OFF_SHIFT`) y segundos efectivos acumulados hoy del operario autenticado (`RF-04.2`, `RF-04.3`). |
| `POST` | `/api/technician/shifts/start` | `InternalAuthMiddleware(TECHNICIAN, RESTOCKER)` | Inicia turno (`ON_SHIFT`). Si tenía ausencia activa, aplica precedencia de presencia física y audita `SHIFT_STARTED_DURING_ABSENCE` (`C2`, `RF-03.3`). |
| `POST` | `/api/technician/shifts/meal-start` | `InternalAuthMiddleware(TECHNICIAN, RESTOCKER)` | Inicia pausa de comida (`MEAL_BREAK`). |
| `POST` | `/api/technician/shifts/meal-end` | `InternalAuthMiddleware(TECHNICIAN, RESTOCKER)` | Reanuda turno (`ON_SHIFT`) acumulando segundos en `meal_break_seconds`. |
| `POST` | `/api/technician/shifts/close` | `InternalAuthMiddleware(TECHNICIAN, RESTOCKER)` | Cierra el turno (`OFF_SHIFT`). Si el rol es `RESTOCKER`, ejecuta cierre directo sin validación ni relevo de tickets SAT (`D1`, `RF-04.2`). |
| `GET` | `/api/technician/shifts/my-hours` | `InternalAuthMiddleware(TECHNICIAN, RESTOCKER)` | Autoconsulta mensual «⏱️ Mis Horas» aislada estrictamente por el `user_id` del token (`RF-04.3`, Art. V.4). |
| **Ausencias, Turnos y Registro Horario en Coordinación (`RF-05`)** | | | |
| `GET` | `/api/coordinator/absences` | `InternalAuthMiddleware(COORDINATOR)` | Lista ausencias soportando `?role=ALL\|TECHNICIAN\|RESTOCKER` e incluye `user_role` y zonas afectadas (`RF-05.1`). |
| `POST` | `/api/coordinator/absences` | `InternalAuthMiddleware(COORDINATOR)` | Registra ausencia para `TECHNICIAN` o `RESTOCKER`. Si es `RESTOCKER`, devuelve las zonas de carga donde se promueve automáticamente al `BACKUP` (`RF-05.1`). |
| `GET` | `/api/coordinator/shifts/status` | `InternalAuthMiddleware(COORDINATOR)` | Supervisión de estado de turno de la flota con filtro `?role=ALL\|TECHNICIAN\|RESTOCKER` (`RF-05.1`). |
| `POST` | `/api/coordinator/shifts/{technician_id}/force-close` | `InternalAuthMiddleware(COORDINATOR)` | Cierre forzado de turno abierto de `TECHNICIAN` o `RESTOCKER` (tiempo real $\ge 10$ chars o `effective_closed_at` $\ge 20$ chars reales, `RF-05.1`). |
| `GET` | `/api/coordinator/metrics/work-hours` | `InternalAuthMiddleware(COORDINATOR)` | Informe de jornada y horas con filtro `?role=ALL\|TECHNICIAN\|RESTOCKER`, aplicando regla `C5` e incluyendo `role` y `role_label` (`RF-05.2`, `QA 4`). |
| `GET` | `/api/coordinator/metrics/work-hours/export` | `InternalAuthMiddleware(COORDINATOR)` | Exportación CSV UTF-8 BOM (`;`) respetando `?role=ALL\|TECHNICIAN\|RESTOCKER` y añadiendo la columna `Rol` en ambas secciones (`RF-05.2`, `QA 4`). |

---

## 2. Contrato de Esquema de Base de Datos (PostgreSQL 16+ / Supabase)

La persistencia del Módulo 31 se materializa en la migración incremental `database/supabase_migrations/027_restocker_role_and_zone_assignments.sql` y converge 1:1 en `database/supabase_init.sql`, elevando el catálogo del dominio de **40 a 41 tablas** (`restocker_zone_assignments`).

### 2.1. Extensión de Tipos Enumerados (`user_role` y `audit_entity_type`)

```sql
-- 1. Ampliación del rol operativo con RESTOCKER (RF-01.1)
ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'RESTOCKER';

-- 2. Nueva entidad auditable para el cuadrante de carga (QA 1)
ALTER TYPE audit_entity_type ADD VALUE IF NOT EXISTS 'RESTOCKER_ZONE';
```

### 2.2. Regla Constitucional en Tabla 3 · `users` (`is_on_call = FALSE` para `RESTOCKER`)

```sql
-- Un usuario RESTOCKER jamás puede ostentar la guardia técnica 24/7 del Art. II (D3, EARS 01.1)
ALTER TABLE users
    DROP CONSTRAINT IF EXISTS chk_users_restocker_not_on_call;

ALTER TABLE users
    ADD CONSTRAINT chk_users_restocker_not_on_call
    CHECK (role <> 'RESTOCKER' OR is_on_call = FALSE);
```

### 2.3. Definición DDL de Tabla 41 · `restocker_zone_assignments` (`RF-02.1`, `QA 1`, Art. III.1)

```sql
CREATE TABLE IF NOT EXISTS restocker_zone_assignments (
    id            BIGINT GENERATED ALWAYS AS IDENTITY,
    restocker_id  BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    zone_id       BIGINT NOT NULL REFERENCES operational_zones(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    role_in_zone  VARCHAR(10) NOT NULL DEFAULT 'BACKUP',
    is_primary    BOOLEAN NOT NULL DEFAULT FALSE,
    assigned_at   TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    removed_at    TIMESTAMPTZ,
    CONSTRAINT pk_restocker_zone_assignments PRIMARY KEY (id),
    CONSTRAINT chk_restocker_zone_role_values CHECK (role_in_zone IN ('PRIMARY', 'BACKUP')),
    CONSTRAINT chk_restocker_zone_role_coherence CHECK (
        (role_in_zone = 'PRIMARY' AND is_primary = TRUE)
        OR (role_in_zone = 'BACKUP' AND is_primary = FALSE)
    ),
    CONSTRAINT chk_restocker_zone_removed_order CHECK (
        removed_at IS NULL OR removed_at >= assigned_at
    )
);

-- Unicidad sobre vinculaciones vivas: permite reingresos conservando el histórico (Art. III.1)
CREATE UNIQUE INDEX IF NOT EXISTS uq_restocker_zone_live
    ON restocker_zone_assignments (restocker_id, zone_id)
    WHERE removed_at IS NULL;

-- Regla de Titularidad Única: un reponedor solo puede ser Titular Principal (PRIMARY / is_primary = TRUE)
-- de como máximo una zona operativa viva simultáneamente (RF-02.1)
CREATE UNIQUE INDEX IF NOT EXISTS uq_restocker_primary_zone
    ON restocker_zone_assignments (restocker_id)
    WHERE is_primary = TRUE AND removed_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_restocker_zone_assignments_zone_id
    ON restocker_zone_assignments (zone_id);

CREATE INDEX IF NOT EXISTS idx_restocker_zone_assignments_restocker_id
    ON restocker_zone_assignments (restocker_id);

-- Blindaje Constitucional Art. III.1: Prohibición absoluta de DELETE físico
DROP TRIGGER IF EXISTS trg_no_delete_restocker_zone_assignments ON restocker_zone_assignments;
CREATE TRIGGER trg_no_delete_restocker_zone_assignments
    BEFORE DELETE ON restocker_zone_assignments
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();
```

---

## 3. Contratos de Dominio y Reglas de Negocio

### 3.1. Escalafón Determinista de Cobertura en `RestockerZoneCoverageService` (`RF-03`, `C2`, `QA 3`)

Dado un `zone_id` y un instante de evaluación `$now` (`DateTimeImmutable`, zona horaria `Europe/Madrid`), el servicio evalúa todas las vinculaciones vivas (`rza.removed_at IS NULL`) de la zona y clasifica a cada reponedor en uno de los **7 escalones** siguientes:

| Orden | Código de Escalón (`coverage_tier`) | Condición Exacta |Elegible como `#1 RECOMENDADO ⭐` | Etiqueta Promoción Automática (`is_promoted_backup`) |
| :---: | :--- | :--- | :---: | :---: |
| **1** | `PRIMARY_ON_SHIFT` | `u.is_active = TRUE` AND `rza.is_primary = TRUE` AND `shift.status = 'ON_SHIFT'` | Sí | `false` |
| **2** | `BACKUP_PROMOTED_ON_SHIFT` | `u.is_active = TRUE` AND `rza.is_primary = FALSE` AND `shift.status = 'ON_SHIFT'` | Sí | `true` |
| **3** | `PRIMARY_MEAL_BREAK` | `u.is_active = TRUE` AND `rza.is_primary = TRUE` AND `shift.status = 'MEAL_BREAK'` | Sí | `false` |
| **4** | `BACKUP_PROMOTED_MEAL_BREAK` | `u.is_active = TRUE` AND `rza.is_primary = FALSE` AND `shift.status = 'MEAL_BREAK'` | Sí | `true` |
| **5** | `PRIMARY_PLANNED_OFF_SHIFT` | `u.is_active = TRUE` AND `rza.is_primary = TRUE` AND `shift = OFF_SHIFT` AND `active_absence IS NULL` | Sí | `false` |
| **6** | `BACKUP_PROMOTED_OFF_SHIFT` | `u.is_active = TRUE` AND `rza.is_primary = FALSE` AND `shift = OFF_SHIFT` AND `active_absence IS NULL` | Sí | `true` |
| **7** | `UNAVAILABLE` | `u.is_active = FALSE` OR (`shift = OFF_SHIFT` AND `active_absence IS NOT NULL`) | No (`NO_DISPONIBLE`) | `false` |

* **Criterio de desempate dentro del mismo escalón (`RF-03.2`):**
  1. `rza.assigned_at ASC` (mayor antigüedad en la zona).
  2. `u.operator_code ASC`.
  3. `u.id ASC`.
* **Precedencia de presencia física (`RF-03.3`):** Si `shift.status IN ('ON_SHIFT', 'MEAL_BREAK')`, el reponedor entra en los escalones 1–4 aunque tenga una fila vigente en `technician_absences`, exponiendo `has_overridden_absence = true`.

---

## 4. Contratos Detallados de API REST y Códigos de Error HTTP

### 4.1. `POST /api/coordinator/zones/{id}/restockers`
Vincula un reponedor al cuadrante de carga de la zona.

* **Payload de Entrada:**
```json
{
  "restocker_id": 12,
  "role_in_zone": "PRIMARY",
  "is_primary": true,
  "confirm_primary_transfer": false
}
```
*(Si se envía solo `role_in_zone` o solo `is_primary`, el controlador deriva el otro de forma coherente; si se envían ambos contradictorios, devuelve `422 ROLE_IN_ZONE_MISMATCH`).*
* **Respuesta `200 OK` / `201 Created`:**
```json
{
  "success": true,
  "data": {
    "assignment": {
      "id": 5,
      "zone_id": 1,
      "restocker_id": 12,
      "name": "Lucía Gómez",
      "operator_code": "REP-01",
      "role_in_zone": "PRIMARY",
      "is_primary": true,
      "assigned_at": "2026-11-11T09:00:00+01:00"
    },
    "previous_primary_zone_released": null
  }
}
```
* **Precedencia de Errores:**
  * `404 ZONE_NOT_FOUND`: La zona no existe o está inactiva (`409 ZONE_INACTIVE`).
  * `422 INVALID_RESTOCKER_USER`: El usuario no existe, no tiene `role === 'RESTOCKER'` o tiene `is_active = false`.
  * `409 PRIMARY_ZONE_ALREADY_ASSIGNED`: El reponedor ya es `PRIMARY` vivo en otra zona y `confirm_primary_transfer` es `false`. Incluye `current_primary_zone: { id, code, name }`.

### 4.2. `GET /api/coordinator/zones/{id}/restocker-coverage`
Devuelve el estado de cobertura en vivo de la zona operativa y el reponedor recomendado.

* **Respuesta `200 OK`:**
```json
{
  "success": true,
  "data": {
    "zone_id": 1,
    "zone_code": "ZONA-BCN-NORTE",
    "zone_name": "Barcelona Litoral y Norte",
    "coverage_status": "COVERED_BY_PROMOTED_BACKUP",
    "recommended_restocker": {
      "restocker_id": 14,
      "name": "Carlos Ruiz",
      "operator_code": "REP-02",
      "role_in_zone": "BACKUP",
      "is_primary": false,
      "shift_status": "ON_SHIFT",
      "coverage_tier": "BACKUP_PROMOTED_ON_SHIFT",
      "tier_rank": 2,
      "is_recommended": true,
      "is_promoted_backup": true,
      "recommendation_label": "#1 RECOMENDADO ⭐ · Suplente en Cobertura Activa (Promoción automática)"
    },
    "candidates": [
      {
        "restocker_id": 14,
        "name": "Carlos Ruiz",
        "operator_code": "REP-02",
        "role_in_zone": "BACKUP",
        "is_primary": false,
        "is_active": true,
        "shift_status": "ON_SHIFT",
        "coverage_tier": "BACKUP_PROMOTED_ON_SHIFT",
        "tier_rank": 2,
        "is_available": true,
        "is_recommended": true,
        "is_promoted_backup": true,
        "unavailable_reason": null,
        "assigned_at": "2026-11-01T08:00:00+01:00"
      },
      {
        "restocker_id": 12,
        "name": "Lucía Gómez",
        "operator_code": "REP-01",
        "role_in_zone": "PRIMARY",
        "is_primary": true,
        "is_active": true,
        "shift_status": "OFF_SHIFT",
        "coverage_tier": "UNAVAILABLE",
        "tier_rank": 7,
        "is_available": false,
        "is_recommended": false,
        "is_promoted_backup": false,
        "unavailable_reason": "Ausencia activa: VACATION",
        "assigned_at": "2026-10-15T08:00:00+01:00"
      }
    ]
  }
}
```

### 4.3. `GET /api/restocker/my-route`
Bootstrap operativo para la vista móvil `RestockerRouteView.js`.

* **Respuesta `200 OK`:**
```json
{
  "success": true,
  "data": {
    "restocker": {
      "id": 12,
      "name": "Lucía Gómez",
      "email": "lucia.restocker@vendguard.local",
      "operator_code": "REP-01",
      "role": "RESTOCKER"
    },
    "zones": {
      "primary_zone": {
        "id": 1,
        "code": "ZONA-BCN-NORTE",
        "name": "Barcelona Litoral y Norte",
        "color_hex": "#2560ff",
        "role_in_zone": "PRIMARY"
      },
      "backup_zones": [
        {
          "id": 2,
          "code": "ZONA-BCN-CENTRO",
          "name": "Barcelona Distrito Centro",
          "color_hex": "#10b981",
          "role_in_zone": "BACKUP"
        }
      ]
    },
    "shift": {
      "status": "ON_SHIFT",
      "active_shift_id": 45,
      "started_at": "2026-11-11T08:00:00+01:00",
      "today_effective_seconds": 7200
    },
    "route_stops": []
  }
}
```

### 4.4. Tabla de Códigos de Error del Módulo

| HTTP Status | `error_code` | Condición de Disparo |
| :---: | :--- | :--- |
| `403` | `FORBIDDEN_ROLE` | Un `RESTOCKER` invoca un endpoint exclusivo de `TECHNICIAN` o `COORDINATOR` (`RF-01.4`). |
| `409` | `ROLE_CHANGE_FORBIDDEN` | Intento de modificar el `role` de un usuario existente en `PUT /api/coordinator/staff/{id}` (`C1`). |
| `409` | `PRIMARY_ZONE_ALREADY_ASSIGNED` | El reponedor ya es `PRIMARY` vivo en otra zona y no se envió `confirm_primary_transfer = true` (`RF-02.2`). |
| `409` | `ZONE_RELEASE_CONFIRMATION_REQUIRED` | Se intenta desactivar una zona que tiene reponedores vivos sin `confirm_release = true` (`RF-02.3`). |
| `422` | `RESTOCKER_CANNOT_BE_ON_CALL` | Se intenta crear o editar un usuario `RESTOCKER` con `is_on_call = true` (`D3`, `RF-01.1`). |
| `422` | `INVALID_RESTOCKER_USER` | Se intenta vincular al cuadrante de carga un usuario que no es `RESTOCKER` o está inactivo (`RF-02.2`). |
| `422` | `INVALID_ROLE_FILTER` | Parámetro `?role=` fuera de `ALL`, `TECHNICIAN`, `RESTOCKER` (y `COORDINATOR` en staff). |

---

## 5. Eventos de Auditoría (`audit_log` · Art. III.3)

| `entity_type` | `action` | Disparador | Metadatos (`old_values` / `new_values`) |
| :--- | :--- | :--- | :--- |
| `USER` | `STAFF_USER_CREATED` | Alta de `RESTOCKER` en Personal Interno | `role: 'RESTOCKER'`, `operator_code`, `is_on_call: false` |
| `USER` | `FORCE_CLOSE_SHIFT` | Cierre automático de turno al desactivar cuenta (`C3`) | `shift_id`, `reason: "Cierre automático por desactivación de cuenta de usuario"` |
| `RESTOCKER_ZONE` | `RESTOCKER_ZONE_ASSIGNED` | Alta o cambio de rol (`PRIMARY`/`BACKUP`) en zona | `zone_id`, `restocker_id`, `role_in_zone`, `is_primary`, `previous_primary_zone_id` |
| `RESTOCKER_ZONE` | `RESTOCKER_ZONE_REMOVED` | Retirada lógica (`removed_at`) del cuadrante | `zone_id`, `restocker_id`, `role_in_zone`, `removed_at` |
| `TECHNICIAN_SHIFT` | `SHIFT_STARTED_DURING_ABSENCE` | Inicio de turno de un `RESTOCKER` con ausencia activa (`C2`) | `user_id`, `role: 'RESTOCKER'`, `absence_id`, `shift_id` |
