# ESPECIFICACIÓN TÉCNICA · CONTRATOS DE API PARA ADMINISTRACIÓN INTEGRAL (CRUD)
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `04-admin-crud`  
**Documento:** `specs/technical/admin_crud_contracts.md`  
**Referencia Funcional:** [`specs/functional/admin_crud_spec.md`](../functional/admin_crud_spec.md) (RF-01 a RF-05, RNF-01 a RNF-05)  
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`)  
**Metodología:** SDD (Specification-Driven Development) · Contratos API-First  
**Conformidad Constitucional:** Artículos I, II, III (3.1, 3.2, 3.3), IV, V (5.1, 5.2, 5.4, 5.6) y VI  

---

## 1. Visión General de Endpoints del Módulo

Todos los endpoints administrativos requieren autenticación de Coordinador (`InternalAuthMiddleware(COORDINATOR)`), a excepción de la consulta pública de escaneo QR (`GET /api/qr/scan/{code}`).

| Método | Endpoint | Rol / Acceso | Propósito |
| :--- | :--- | :--- | :--- |
| **Sedes (Locations)** | | | |
| `GET` | `/api/coordinator/locations` | Coordinador | Listado con filtros (`status`, `search`) y conteo de máquinas activas |
| `POST` | `/api/coordinator/locations` | Coordinador | Alta de nueva sede (`site_code` inmutable) |
| `PATCH` | `/api/coordinator/locations/{id}` | Coordinador | Edición de datos maestros de sede |
| `PATCH` | `/api/coordinator/locations/{id}/deactivate` | Coordinador | Baja lógica de sede (bloqueo si tiene máquinas activas) |
| `PATCH` | `/api/coordinator/locations/{id}/reactivate` | Coordinador | Reactivación de sede inactiva |
| **Máquinas (Machines)** | | | |
| `GET` | `/api/coordinator/machines` | Coordinador | Listado integral con filtros (`status`, `location_id`, `machine_type`, `search`) |
| `POST` | `/api/coordinator/machines` | Coordinador | Alta de máquina en sede activa (`code` inmutable, tipología sanitaria) |
| `PATCH` | `/api/coordinator/machines/{id}` | Coordinador | Edición de modelo/ubicación y tipología (bloqueada si hay tickets activos o en garantía) |
| `PATCH` | `/api/coordinator/machines/{id}/transfer` | Coordinador | Traslado entre sedes activas (bloqueado si hay tickets activos o en garantía) |
| `PATCH` | `/api/coordinator/machines/{id}/deactivate` | Coordinador | Baja lógica de máquina (bloqueada si hay tickets activos o en garantía) |
| `PATCH` | `/api/coordinator/machines/{id}/reactivate` | Coordinador | Reactivación de máquina (con reubicación obligatoria si su sede original está inactiva) |
| **Personal Interno (Users)** | | | |
| `GET` | `/api/coordinator/users` | Coordinador | Listado de técnicos y coordinadores (`role`, `status`, `search`) con carga de averías activas |
| `POST` | `/api/coordinator/users` | Coordinador | Alta de usuario con credenciales seguras |
| `PATCH` | `/api/coordinator/users/{id}` | Coordinador | Edición de nombre y teléfono corporativo |
| `PATCH` | `/api/coordinator/users/{id}/reset-password` | Coordinador | Reseteo seguro de contraseña (mín. 8 caracteres) |
| `PATCH` | `/api/coordinator/users/{id}/deactivate` | Coordinador | Baja lógica (bloqueo si tiene averías activas, auto-baja o guardia mínima) |
| `PATCH` | `/api/coordinator/users/{id}/reactivate` | Coordinador | Reactivación de usuario inactivo |
| **Escaneo QR Ciudadano** | | | |
| `GET` | `/api/qr/scan/{code}` | Público | Retorna estado de máquina inactiva (`INACTIVE`) con pantalla explicativa |

---

## 2. Definición del Esquema y Migraciones de Base de Datos

### 2.1 Migración `004_admin_crud_audit_and_snapshot.sql`

1. **Extensión del Enum de Auditoría (`audit_log`):**  
   Se incluye la entidad `'USER'` para registrar cambios sobre personal técnico y coordinadores:
   ```sql
   ALTER TABLE `audit_log` 
   MODIFY COLUMN `entity_type` ENUM('TICKET', 'MACHINE', 'LOCATION', 'USER') NOT NULL;
   ```

2. **Blindaje Histórico de Tipología de Máquina en Incidencias (Art. II):**  
   Se añade columna para preservar la tipología sanitaria en el momento de creación del ticket:
   ```sql
   ALTER TABLE `incidents`
   ADD COLUMN `machine_type_snapshot` ENUM('HOT_DRINKS', 'COLD_DRINKS', 'SNACKS', 'PERISHABLE_FOOD', 'COMBO') NULL AFTER `machine_id`;

   -- Backfill para incidencias preexistentes
   UPDATE `incidents` i
   INNER JOIN `machines` m ON i.`machine_id` = m.`id`
   SET i.`machine_type_snapshot` = m.`machine_type`
   WHERE i.`machine_type_snapshot` IS NULL;
   ```

---

## 3. Especificación Detallada de Contratos de API

### 3.1 Gestión de Sedes (Locations)

#### 3.1.1 `GET /api/coordinator/locations`
Lista las sedes registradas permitiendo filtrar por estado operativo y búsqueda de texto.

* **Parámetros Query:**
  * `status` *(opcional, default: `all`)*: `active`, `inactive`, `all`.
  * `search` *(opcional)*: Búsqueda parcial case-insensitive en `site_code`, `name`, `address` o `contact_name`.

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "site_code": "SEDE-BCN-01",
      "name": "Hospital del Mar - Edificio Central",
      "address": "Passeig Marítim 25, 08003 Barcelona",
      "contact_name": "Marta Puig",
      "contact_phone": "600112233",
      "is_active": true,
      "created_at": "2026-09-01 08:00:00",
      "updated_at": "2026-09-15 11:20:00",
      "active_machines_count": 4,
      "total_machines_count": 5
    }
  ]
}
```

---

#### 3.1.2 `POST /api/coordinator/locations`
Crea una nueva sede cliente. `site_code` debe respetar el formato `^[A-Z0-9-]{3,32}$`. Si el código ya existe inactivo, retorna `409 Conflict` con sugerencia de reactivación.

##### Petición (`POST /api/coordinator/locations`)
```json
{
  "site_code": "SEDE-VAL-01",
  "name": "Politécnico de Valencia - Rectorado",
  "address": "Camino de Vera s/n, 46022 Valencia",
  "contact_name": "Laura Navarro",
  "contact_phone": "633445566"
}
```

##### Respuesta Exitosa (`201 Created`)
```json
{
  "success": true,
  "message": "Sede creada exitosamente.",
  "data": {
    "id": 8,
    "site_code": "SEDE-VAL-01",
    "name": "Politécnico de Valencia - Rectorado",
    "address": "Camino de Vera s/n, 46022 Valencia",
    "contact_name": "Laura Navarro",
    "contact_phone": "633445566",
    "is_active": true,
    "created_at": "2026-09-23 20:00:00"
  }
}
```

##### Respuesta de Conflicto (`409 Conflict` - Existe inactiva)
```json
{
  "success": false,
  "error": {
    "code": "LOCATION_ALREADY_EXISTS_INACTIVE",
    "message": "El código de sede 'SEDE-VAL-01' ya existe pero se encuentra dado de baja.",
    "location_id": 5,
    "can_reactivate": true
  }
}
```

---

#### 3.1.3 `PATCH /api/coordinator/locations/{id}`
Modifica los datos descriptivos de la sede. El campo `site_code` es inmutable y no se puede alterar.

##### Petición
```json
{
  "name": "Hospital del Mar - Urgencias y Consultas",
  "address": "Passeig Marítim 27, 08003 Barcelona",
  "contact_name": "Carla Vidal",
  "contact_phone": "611998877"
}
```

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "Sede actualizada exitosamente.",
  "data": {
    "id": 1,
    "site_code": "SEDE-BCN-01",
    "name": "Hospital del Mar - Urgencias y Consultas",
    "address": "Passeig Marítim 27, 08003 Barcelona",
    "contact_name": "Carla Vidal",
    "contact_phone": "611998877",
    "is_active": true
  }
}
```

---

#### 3.1.4 `PATCH /api/coordinator/locations/{id}/deactivate`
Da de baja lógica a la sede (`is_active = FALSE`, `deleted_at = NOW()`). Bloquea si existen máquinas activas vinculadas.

##### Petición
```json
{}
```

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "Sede dada de baja lógica exitosamente.",
  "data": {
    "id": 1,
    "is_active": false
  }
}
```

##### Error por Bloqueo de Máquinas Activas (`409 Conflict`)
```json
{
  "success": false,
  "error": {
    "code": "LOCATION_HAS_ACTIVE_MACHINES",
    "message": "No se puede dar de baja la sede porque tiene 4 máquinas activas asociadas. Reubique o dé de baja las máquinas antes de desactivar la sede.",
    "active_machines_count": 4
  }
}
```

---

#### 3.1.5 `PATCH /api/coordinator/locations/{id}/reactivate`
Reactiva una sede inactiva (`is_active = TRUE`, `deleted_at = NULL`). No reactiva en masa las máquinas que hubieran estado asociadas.

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "Sede reactivada exitosamente.",
  "data": {
    "id": 1,
    "is_active": true
  }
}
```

---

### 3.2 Gestión de Máquinas (Machines)

#### 3.2.1 `GET /api/coordinator/machines`
Listado exhaustivo de máquinas con filtrado por estado, sede, tipología y búsqueda.

* **Parámetros Query:**
  * `status` *(opcional, default: `all`)*: `active`, `inactive`, `all`.
  * `location_id` *(opcional)*: ID de sede específica.
  * `machine_type` *(opcional)*: `HOT_DRINKS`, `COLD_DRINKS`, `SNACKS`, `PERISHABLE_FOOD`, `COMBO`.
  * `search` *(opcional)*: Búsqueda en `code`, `model`, `floor_wing`, `notes` o nombre de sede.

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "code": "VEND-BCN-101",
      "model": "Necta Canto Touch",
      "machine_type": "HOT_DRINKS",
      "machine_type_label": "Bebidas calientes (Café e infusiones)",
      "is_perishable": false,
      "location_id": 1,
      "location_name": "Hospital del Mar",
      "location_site_code": "SEDE-BCN-01",
      "floor_wing": "Planta Baja - Hall Principal",
      "notes": "Acceso por puerta giratoria",
      "is_active": true,
      "has_active_ticket": false,
      "active_ticket_code": null,
      "active_ticket_status": null,
      "is_in_warranty": false,
      "created_at": "2026-09-01 08:30:00",
      "updated_at": "2026-09-20 14:10:00"
    }
  ]
}
```

---

#### 3.2.2 `POST /api/coordinator/machines`
Registra una máquina dispensadora en una sede activa. Si el código existe inactivo, retorna `409 Conflict`.

##### Petición
```json
{
  "code": "VEND-VAL-301",
  "model": "FAS Fast 1050",
  "machine_type": "PERISHABLE_FOOD",
  "location_id": 8,
  "floor_wing": "Planta 1 - Comedor",
  "notes": "Enchufar a línea protegida con termostato"
}
```

##### Respuesta Exitosa (`201 Created`)
```json
{
  "success": true,
  "message": "Máquina dada de alta exitosamente.",
  "data": {
    "id": 15,
    "code": "VEND-VAL-301",
    "model": "FAS Fast 1050",
    "machine_type": "PERISHABLE_FOOD",
    "location_id": 8,
    "floor_wing": "Planta 1 - Comedor",
    "notes": "Enchufar a línea protegida con termostato",
    "is_active": true,
    "created_at": "2026-09-23 20:05:00"
  }
}
```

##### Respuesta de Conflicto (`409 Conflict` - Existe inactiva)
```json
{
  "success": false,
  "error": {
    "code": "MACHINE_ALREADY_EXISTS_INACTIVE",
    "message": "El código de máquina 'VEND-VAL-301' ya existe pero se encuentra dado de baja.",
    "machine_id": 12,
    "can_reactivate": true
  }
}
```

---

#### 3.2.3 `PATCH /api/coordinator/machines/{id}`
Edita modelo, ubicación física interna y notas. La modificación de `machine_type` está terminantemente bloqueada si la máquina tiene tickets activos o en garantía (`RESOLVED` < 48h). El código es inmutable.

##### Petición
```json
{
  "model": "FAS Fast 1050 V2",
  "machine_type": "PERISHABLE_FOOD",
  "floor_wing": "Planta 1 - Cafetería de Profesores",
  "notes": "Revisar precinto de seguridad térmico"
}
```

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "Máquina actualizada exitosamente.",
  "data": {
    "id": 15,
    "code": "VEND-VAL-301",
    "model": "FAS Fast 1050 V2",
    "machine_type": "PERISHABLE_FOOD",
    "floor_wing": "Planta 1 - Cafetería de Profesores",
    "notes": "Revisar precinto de seguridad térmico",
    "is_active": true
  }
}
```

##### Error por Intento de Cambio de Tipología con Avería Activa (`409 Conflict`)
```json
{
  "success": false,
  "error": {
    "code": "MACHINE_TYPE_CHANGE_BLOCKED",
    "message": "No se puede modificar la tipología sanitaria de la máquina mientras tenga una avería activa o en periodo de garantía de 48 horas.",
    "ticket_code": "INC-2026-0042",
    "status": "IN_PROGRESS"
  }
}
```

---

#### 3.2.4 `PATCH /api/coordinator/machines/{id}/transfer`
Traslada una máquina a otra sede cliente activa con nueva planta y ala. Bloqueado si tiene averías activas o en garantía (`RESOLVED` < 48h).

##### Petición
```json
{
  "target_location_id": 2,
  "floor_wing": "Planta 0 - Sala de Espera",
  "notes": "Traslado autorizado por gerencia técnica"
}
```

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "Máquina trasladada exitosamente.",
  "data": {
    "id": 15,
    "code": "VEND-VAL-301",
    "location_id": 2,
    "location_name": "Sede Corporativa Madrid",
    "floor_wing": "Planta 0 - Sala de Espera",
    "notes": "Traslado autorizado por gerencia técnica"
  }
}
```

##### Error por Traslado Bloqueado por Avería o Garantía (`409 Conflict`)
```json
{
  "success": false,
  "error": {
    "code": "MACHINE_TRANSFER_BLOCKED",
    "message": "No se puede trasladar la máquina mientras tenga incidencias activas o en garantía de 48h (ticket INC-2026-0045, estado RESOLVED).",
    "ticket_code": "INC-2026-0045",
    "status": "RESOLVED"
  }
}
```

---

#### 3.2.5 `PATCH /api/coordinator/machines/{id}/deactivate`
Da de baja lógica a la máquina (`is_active = FALSE`). Bloqueada si tiene tickets activos o en garantía (`RESOLVED` < 48h).

##### Petición
```json
{}
```

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "Máquina dada de baja lógica exitosamente.",
  "data": {
    "id": 15,
    "code": "VEND-VAL-301",
    "is_active": false
  }
}
```

##### Error por Baja Bloqueada (`409 Conflict`)
```json
{
  "success": false,
  "error": {
    "code": "MACHINE_DEACTIVATION_BLOCKED",
    "message": "No se puede dar de baja la máquina porque tiene la incidencia activa INC-2026-0048 en estado PENDING_PARTS.",
    "ticket_code": "INC-2026-0048",
    "status": "PENDING_PARTS"
  }
}
```

---

#### 3.2.6 `PATCH /api/coordinator/machines/{id}/reactivate`
Reactiva una máquina previamente dada de baja. Si su sede original se encuentra inactiva, la petición debe proveer obligatoriamente `target_location_id` (sede activa) y `floor_wing`.

##### Petición (Sede original activa)
```json
{}
```

##### Petición (Si la sede original está dada de baja)
```json
{
  "target_location_id": 1,
  "floor_wing": "Planta 2 - Pasillo Laboratorios"
}
```

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "Máquina reactivada exitosamente.",
  "data": {
    "id": 15,
    "code": "VEND-VAL-301",
    "location_id": 1,
    "is_active": true
  }
}
```

##### Error por Sede Original Inactiva sin Destino (`422 Unprocessable Entity`)
```json
{
  "success": false,
  "error": {
    "code": "MACHINE_REACTIVATION_REQUIRES_NEW_LOCATION",
    "message": "La sede original de la máquina está dada de baja. Debe especificar una sede activa de destino y la nueva planta/ala."
  }
}
```

---

### 3.3 Gestión de Personal Interno (Users: Técnicos y Coordinadores)

#### 3.3.1 `GET /api/coordinator/users`
Lista el personal del sistema, permitiendo filtrar por rol y estado operativo, e informando de la carga de averías activas.

* **Parámetros Query:**
  * `role` *(opcional, default: `all`)*: `TECHNICIAN`, `COORDINATOR`, `all`.
  * `status` *(opcional, default: `all`)*: `active`, `inactive`, `all`.
  * `search` *(opcional)*: Búsqueda en `name`, `email` o `phone`.

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "data": [
    {
      "id": 2,
      "name": "Carlos Rodríguez",
      "email": "carlos.tecnico@vendguard.internal",
      "role": "TECHNICIAN",
      "role_label": "Técnico de Ruta",
      "phone": "677998811",
      "is_active": true,
      "active_assigned_incidents_count": 2,
      "created_at": "2026-09-01 08:00:00",
      "updated_at": "2026-09-22 17:00:00"
    },
    {
      "id": 1,
      "name": "Elena Coordinadora",
      "email": "elena.coord@vendguard.internal",
      "role": "COORDINATOR",
      "role_label": "Coordinador de Servicios",
      "phone": "688001122",
      "is_active": true,
      "active_assigned_incidents_count": 0,
      "created_at": "2026-09-01 08:00:00",
      "updated_at": "2026-09-20 10:00:00"
    }
  ]
}
```

---

#### 3.3.2 `POST /api/coordinator/users`
Da de alta a un nuevo técnico o coordinador con credenciales criptográficas seguras (Bcrypt). El email es inmutable.

##### Petición
```json
{
  "name": "Santiago Gómez",
  "email": "santiago.tec@vendguard.internal",
  "role": "TECHNICIAN",
  "phone": "655223344",
  "password": "PasswordSegura2026!"
}
```

##### Respuesta Exitosa (`201 Created`)
```json
{
  "success": true,
  "message": "Usuario dado de alta exitosamente.",
  "data": {
    "id": 6,
    "name": "Santiago Gómez",
    "email": "santiago.tec@vendguard.internal",
    "role": "TECHNICIAN",
    "phone": "655223344",
    "is_active": true,
    "created_at": "2026-09-23 20:10:00"
  }
}
```

##### Respuesta de Conflicto (`409 Conflict` - Email existe inactivo)
```json
{
  "success": false,
  "error": {
    "code": "USER_ALREADY_EXISTS_INACTIVE",
    "message": "El correo 'santiago.tec@vendguard.internal' pertenece a un usuario inactivo.",
    "user_id": 4,
    "can_reactivate": true
  }
}
```

---

#### 3.3.3 `PATCH /api/coordinator/users/{id}`
Actualiza datos de contacto del usuario (nombre y teléfono). El rol y el email son inmutables mediante este endpoint.

##### Petición
```json
{
  "name": "Santiago Gómez Morales",
  "phone": "655998877"
}
```

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "Datos de usuario actualizados exitosamente.",
  "data": {
    "id": 6,
    "name": "Santiago Gómez Morales",
    "email": "santiago.tec@vendguard.internal",
    "role": "TECHNICIAN",
    "phone": "655998877",
    "is_active": true
  }
}
```

---

#### 3.3.4 `PATCH /api/coordinator/users/{id}/reset-password`
Establece una nueva contraseña para el usuario especificado. No altera incidencias asignadas ni sesiones de otros usuarios. Mínimo 8 caracteres.

##### Petición
```json
{
  "new_password": "NuevaClaveTecnica2026!"
}
```

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "Contraseña restablecida exitosamente."
}
```

---

#### 3.3.5 `PATCH /api/coordinator/users/{id}/deactivate`
Da de baja lógica al usuario (`is_active = FALSE`, `deleted_at = NOW()`).

* **Reglas de Bloqueo Infranqueables:**
  1. **Auto-desactivación (Caso Límite 8):** El usuario en sesión no puede desactivar su propia cuenta (`403 Forbidden`).
  2. **Guardia Mínima Operativa (Caso Límite 9):** No se puede desactivar al último técnico activo o al último coordinador activo del sistema (`409 Conflict`).
  3. **Averías Asignadas Pendientes (RF-03, Decisión QA 2):** Si el técnico tiene averías en `ASSIGNED`, `IN_PROGRESS` o `PENDING_PARTS`, se bloquea la baja (`409 Conflict`), requiriendo que el coordinador las reasigne previamente.

##### Petición
```json
{}
```

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "Usuario dado de baja lógica exitosamente.",
  "data": {
    "id": 6,
    "is_active": false
  }
}
```

##### Error por Auto-desactivación (`403 Forbidden`)
```json
{
  "success": false,
  "error": {
    "code": "CANNOT_DEACTIVATE_SELF",
    "message": "Acción denegada: no puede desactivar su propio usuario en sesión activa."
  }
}
```

##### Error por Guardia Mínima (`409 Conflict`)
```json
{
  "success": false,
  "error": {
    "code": "MINIMUM_ACTIVE_STAFF_BREACH",
    "message": "No se puede dar de baja al usuario porque debe existir al menos un coordinador y un técnico activo en la plataforma."
  }
}
```

##### Error por Averías Asignadas Pendientes (`409 Conflict`)
```json
{
  "success": false,
  "error": {
    "code": "TECHNICIAN_HAS_PENDING_INCIDENTS",
    "message": "No se puede dar de baja al técnico porque tiene 2 averías asignadas pendientes. Reasigne sus averías desde el panel de triaje antes de darlo de baja.",
    "pending_incidents_count": 2
  }
}
```

---

#### 3.3.6 `PATCH /api/coordinator/users/{id}/reactivate`
Reactiva un usuario inactivo (`is_active = TRUE`, `deleted_at = NULL`), restaurando su capacidad de inicio de sesión.

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "message": "Usuario reactivado exitosamente.",
  "data": {
    "id": 6,
    "is_active": true
  }
}
```

---

### 3.4 Respuesta Ciudadana y Validación QR de Máquinas Inactivas

#### 3.4.1 `GET /api/qr/scan/{code}` (Comportamiento para máquinas inactivas)
Cuando una máquina ha sido dada de baja lógica (`is_active = FALSE`), el escaneo de su código QR físico no debe devolver error 404 ni permitir reportar averías (RF-04).

##### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "data": {
    "code": "VEND-BCN-101",
    "status": "INACTIVE",
    "is_active": false,
    "model": "Necta Canto Touch",
    "machine_type_label": "Bebidas calientes (Café e infusiones)",
    "location_name": "Hospital del Mar",
    "message": "Esta máquina de vending se encuentra temporalmente retirada o fuera de servicio. No es posible registrar nuevas incidencias sobre este dispositivo.",
    "allow_reporting": false
  }
}
```

---

## 4. Matriz de Eventos de Auditoría Inmutable (`audit_log`)

De acuerdo con el **Artículo III.3**, **Artículo V.1** y el requisito **RF-05**, todas las mutaciones administrativas deben generar un evento sincrónico append-only en la tabla `audit_log`:

| Entidad (`entity_type`) | Acción (`action`) | Datos en `previous_state` | Datos en `new_state` | `metadata` |
| :--- | :--- | :--- | :--- | :--- |
| `LOCATION` | `LOCATION_CREATED` | `null` | `{site_code, name, address, contact_name, contact_phone}` | `{"ip": ...}` |
| `LOCATION` | `LOCATION_UPDATED` | `{name, address, contact_name, contact_phone}` | `{name, address, contact_name, contact_phone}` | `{"changed_fields": [...]}` |
| `LOCATION` | `LOCATION_DEACTIVATED` | `{"is_active": true}` | `{"is_active": false}` | `{"reason": "Baja administrativa"}` |
| `LOCATION` | `LOCATION_REACTIVATED` | `{"is_active": false}` | `{"is_active": true}` | `{"reason": "Reactivación administrativa"}` |
| `MACHINE` | `MACHINE_CREATED` | `null` | `{code, model, machine_type, location_id, floor_wing}` | `{"ip": ...}` |
| `MACHINE` | `MACHINE_UPDATED` | `{model, machine_type, floor_wing, notes}` | `{model, machine_type, floor_wing, notes}` | `{"changed_fields": [...]}` |
| `MACHINE` | `MACHINE_TRANSFERRED` | `{location_id, floor_wing, notes}` | `{location_id, floor_wing, notes}` | `{"source_location_id": ..., "target_location_id": ...}` |
| `MACHINE` | `MACHINE_DEACTIVATED` | `{"is_active": true}` | `{"is_active": false}` | `{"reason": "Baja administrativa"}` |
| `MACHINE` | `MACHINE_REACTIVATED` | `{"is_active": false, "location_id": ...}` | `{"is_active": true, "location_id": ...}` | `{"transferred_on_reactivation": bool}` |
| `USER` | `USER_CREATED` | `null` | `{name, email, role, phone}` *(sin hash de clave)* | `{"ip": ...}` |
| `USER` | `USER_UPDATED` | `{name, phone}` | `{name, phone}` | `{"changed_fields": [...]}` |
| `USER` | `USER_PASSWORD_RESET` | `null` | `{"password_reset": true}` *(sin texto plano ni hash)* | `{"reset_by_coordinator_id": ...}` |
| `USER` | `USER_DEACTIVATED` | `{"is_active": true}` | `{"is_active": false}` | `{"role": ...}` |
| `USER` | `USER_REACTIVATED` | `{"is_active": false}` | `{"is_active": true}` | `{"role": ...}` |

---

## 5. Matriz de Códigos de Error HTTP del Módulo

| Código HTTP | Error Code | Causa | Mensaje en Castellano |
| :--- | :--- | :--- | :--- |
| `400 Bad Request` | `VALIDATION_ERROR` | Datos malformados, formato de código inválido, teléfono no numérico | "Los datos introducidos no son válidos." |
| `403 Forbidden` | `FORBIDDEN` | Petición no realizada por Coordinador | "Acceso denegado. Se requiere rol de Coordinador." |
| `403 Forbidden` | `CANNOT_DEACTIVATE_SELF` | El coordinador intenta auto-desactivar su sesión | "Acción denegada: no puede desactivar su propio usuario en sesión activa." |
| `404 Not Found` | `LOCATION_NOT_FOUND` | La sede especificada por ID no existe | "La sede indicada no existe." |
| `404 Not Found` | `MACHINE_NOT_FOUND` | La máquina especificada por ID o código no existe | "La máquina indicada no existe." |
| `404 Not Found` | `USER_NOT_FOUND` | El usuario especificado por ID no existe | "El usuario indicado no existe." |
| `409 Conflict` | `LOCATION_ALREADY_EXISTS_ACTIVE` | El `site_code` ya está en uso por una sede activa | "El código de sede ya se encuentra registrado." |
| `409 Conflict` | `LOCATION_ALREADY_EXISTS_INACTIVE`| El `site_code` existe pero está dado de baja | "El código de sede ya existe pero se encuentra dado de baja." |
| `409 Conflict` | `LOCATION_HAS_ACTIVE_MACHINES` | Intento de dar de baja sede con máquinas activas | "No se puede dar de baja la sede porque tiene máquinas activas asociadas." |
| `409 Conflict` | `MACHINE_ALREADY_EXISTS_ACTIVE` | El código de máquina ya está en uso activo | "El código de máquina ya se encuentra registrado." |
| `409 Conflict` | `MACHINE_ALREADY_EXISTS_INACTIVE`| El código de máquina existe pero está dado de baja | "El código de máquina ya existe pero se encuentra dado de baja." |
| `409 Conflict` | `MACHINE_DEACTIVATION_BLOCKED` | Máquina con ticket activo o en garantía | "No se puede dar de baja la máquina mientras tenga incidencias activas o en garantía." |
| `409 Conflict` | `MACHINE_TRANSFER_BLOCKED` | Máquina con ticket activo o en garantía | "No se puede trasladar la máquina mientras tenga incidencias activas o en garantía." |
| `409 Conflict` | `MACHINE_TYPE_CHANGE_BLOCKED` | Cambio de tipología con ticket activo o en garantía | "No se puede modificar la tipología de la máquina mientras tenga incidencias activas o en garantía." |
| `409 Conflict` | `USER_ALREADY_EXISTS_ACTIVE` | El email ya está registrado y activo | "El correo electrónico ya se encuentra registrado." |
| `409 Conflict` | `USER_ALREADY_EXISTS_INACTIVE` | El email existe en usuario inactivo | "El correo electrónico pertenece a un usuario inactivo." |
| `409 Conflict` | `MINIMUM_ACTIVE_STAFF_BREACH` | Intento de baja que dejaría 0 técnicos o 0 coordinadores | "Debe existir al menos un coordinador y un técnico activo en la plataforma." |
| `409 Conflict` | `TECHNICIAN_HAS_PENDING_INCIDENTS` | Técnico con averías en `ASSIGNED`/`IN_PROGRESS`/`PENDING_PARTS` | "No se puede dar de baja al técnico porque tiene averías asignadas pendientes." |
| `422 Unproc. Entity` | `MACHINE_REACTIVATION_REQUIRES_NEW_LOCATION` | Máquina a reactivar cuya sede original está inactiva | "La sede original está dada de baja. Indique una nueva sede activa." |
