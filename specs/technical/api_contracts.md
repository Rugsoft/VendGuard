# ESPECIFICACIÓN TÉCNICA · CONTRATOS DE LA API REST
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Documento:** `specs/technical/api_contracts.md`  
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`)  
**Metodología:** SDD (Specification-Driven Development) · Contratos API-First  

---

## 1. Convenciones Globales de la API

### 1.1 Base URL y Versionado
* Todas las rutas de la API estarán prefijadas con: `/api/v1` (o `/api`).
* La comunicación es estrictamente **JSON** tanto en peticiones (`Content-Type: application/json`) como en respuestas, salvo en la subida de fotos que utiliza `multipart/form-data`.

### 1.2 Formato Estándar de Respuesta (Envelope)

#### Respuesta Exitosa (`200 OK`, `201 Created`):
```json
{
  "success": true,
  "data": { ... },
  "message": "Operación completada con éxito"
}
```

#### Respuesta de Error (`4xx`, `5xx`):
```json
{
  "success": false,
  "error": {
    "code": "MACHINE_HAS_ACTIVE_INCIDENT",
    "message": "La máquina ya cuenta con una incidencia activa en curso.",
    "details": {
      "ticket_code": "INC-2026-0012",
      "status": "IN_PROGRESS"
    }
  }
}
```

### 1.3 Códigos de Estado HTTP Utilizados

| Código | Significado | Caso de uso en VendGuard |
| :--- | :--- | :--- |
| `200 OK` | Petición correcta | Lectura de datos, actualizaciones correctas |
| `201 Created` | Recurso creado | Nueva incidencia registrada, nuevo comentario |
| `400 Bad Request` | Petición malformada | JSON inválido o parámetros de cabecera erróneos |
| `401 Unauthorized` | No autenticado | Token de sesión ausente o código de sede inválido |
| `403 Forbidden` | No autorizado | Técnico intentando cancelar una incidencia (solo Coordinador) |
| `404 Not Found` | No encontrado | Máquina, sede o incidencia inexistente |
| `409 Conflict` | Conflicto de estado | **EARS 2.1:** Intento de crear ticket en máquina con incidencia activa |
| `422 Unprocessable`| Validación fallida | **EARS 8.2:** Diagnóstico < 20 caracteres; **EARS 9.2:** Reapertura tras 48h |
| `500 Server Error` | Error de servidor | Fallo de conexión o excepción no controlada |

---

## 2. Módulo de Autenticación y Acceso

### 2.1 `POST /api/auth/site-login` (Acceso por Código de Sede)
Permite al responsable de ubicación identificarse en su centro sin contraseñas (RF-01).

* **Cabeceras:** `Content-Type: application/json`
* **Request Body:**
```json
{
  "site_code": "SEDE-BCN-01"
}
```
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "token": "site_token_eyJhbGciOi...",
    "location": {
      "id": 1,
      "site_code": "SEDE-BCN-01",
      "name": "Hospital del Mar - Edificio Central",
      "address": "Passeig Marítim 25, Barcelona",
      "contact_name": "Laura Sanitaria"
    }
  }
}
```
* **Errores posibles:**
  * `401 Unauthorized` (`INVALID_SITE_CODE`): Código no encontrado o sede inactiva.

---

### 2.2 `POST /api/auth/login` (Acceso Personal Interno)
Inicio de sesión para Coordinadores y Técnicos de Campo (RF-04).

* **Request Body:**
```json
{
  "email": "jordi.ruta@vendguard.internal",
  "password": "Password123!"
}
```
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "token": "auth_token_eyJhbGciOi...",
    "user": {
      "id": 2,
      "name": "Jordi Técnico Ruta BCN",
      "email": "jordi.ruta@vendguard.internal",
      "role": "TECHNICIAN",
      "phone": "677222333"
    }
  }
}
```
* **Errores posibles:**
  * `401 Unauthorized` (`INVALID_CREDENTIALS`): Correo o contraseña incorrectos.

---

## 3. Módulo de Portal de Ubicación (Informador)

### 3.1 `GET /api/locations/{site_code}/machines`
Obtiene el parque de máquinas instaladas en la sede para el formulario de reporte (RF-01, RF-02).

* **Autenticación:** Token de sede o cabecera `X-Site-Code: SEDE-BCN-01`
  **Aviso de seguridad:** el `site_code` es la credencial completa de este rol; `POST /api/auth/site-login`
  emite el token firmado a cambio del código y sin contraseña. Ambos caminos son la misma puerta y el
  acceso alcanza también endpoints de escritura. Hallazgo abierto y escalado a 🔴 en
  `specs/08-refunds/analisis_sexta_tanda.md` §2 (H-1) y §5; cerrarlo exige una decisión de producto.
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "code": "VEND-0101",
      "model": "Sanden Vendo G-Drink",
      "machine_type": "PERISHABLE_FOOD",
      "floor_wing": "Planta Baja - Urgencias",
      "notes": "Máquina de sándwiches y lácteos frescos",
      "active_incident": null
    },
    {
      "id": 2,
      "code": "VEND-0102",
      "model": "Bianchi Gaia Espresso",
      "machine_type": "HOT_DRINKS",
      "floor_wing": "Planta 1 - Sala Médica",
      "notes": "Café en grano",
      "active_incident": {
        "ticket_code": "INC-2026-0004",
        "status": "ASSIGNED",
        "category": "PAYMENT_SYSTEM",
        "created_at": "2026-09-22T10:15:00Z"
      }
    }
  ]
}
```

---

### 3.2 `POST /api/incidents` (Crear Nueva Incidencia)
Registra un nuevo aviso de avería con cálculo automático de urgencia y control de duplicados (RF-02, RF-03).

* **Formato:** `multipart/form-data` (permite adjuntar imagen opcional)
* **Parámetros / Campos de Formulario:**
  * `machine_id` *(int, requerido)*: ID de la máquina averiada.
  * `reporter_name` *(string, opcional)*: Nombre del informador.
  * `reporter_phone` *(string, opcional)*: Teléfono de contacto.
  * `category` *(enum, requerido)*: `TEMPERATURE_COLD` | `PAYMENT_SYSTEM` | `PRODUCT_JAM` | `ELECTRICAL_OFF` | `OTHER`
  * `description` *(string, requerido)*: Descripción de los síntomas.
  * `retained_money_amount` *(decimal, opcional)*: Ej: `2.50`.
  * `photo` *(file, opcional)*: Imagen `.jpg`, `.jpeg`, `.png`, `.webp` (máximo 5 MB).
* **Respuesta Exitosa (`201 Created`):**
```json
{
  "success": true,
  "data": {
    "id": 14,
    "ticket_code": "INC-2026-0014",
    "machine_code": "VEND-0101",
    "category": "TEMPERATURE_COLD",
    "urgency": "CRITICAL",
    "status": "REGISTERED",
    "created_at": "2026-09-22T14:55:00Z"
  },
  "message": "Incidencia registrada correctamente. Prioridad CRÍTICA asignada por seguridad alimentaria."
}
```
* **Errores de Validación / Negocio:**
  * `409 Conflict` (`MACHINE_HAS_ACTIVE_INCIDENT`): **EARS 2.1.** La máquina ya tiene un ticket activo.
    ```json
    {
      "success": false,
      "error": {
        "code": "MACHINE_HAS_ACTIVE_INCIDENT",
        "message": "Esta máquina ya tiene la incidencia INC-2026-0004 activa.",
        "details": { "ticket_code": "INC-2026-0004", "status": "ASSIGNED" }
      }
    }
    ```
  * `422 Unprocessable` (`FILE_TOO_LARGE` / `INVALID_FILE_TYPE`): Archivo > 5 MB o formato no seguro.

---

### 3.3 `POST /api/incidents/{ticket_code}/comments` (Añadir Comentario/Evidencia)
Permite al informador aportar más datos a un ticket activo preexistente sin duplicarlo (EARS 2.2).

* **Request Body:**
```json
{
  "author_name": "Marta Conserjería",
  "comment_text": "La máquina ha empezado a pitar con un código de error E04 en la pantalla."
}
```
* **Respuesta Exitosa (`201 Created`):**
```json
{
  "success": true,
  "data": {
    "id": 5,
    "incident_id": 14,
    "author_name": "Marta Conserjería",
    "comment_text": "La máquina ha empezado a pitar...",
    "created_at": "2026-09-22T15:10:00Z"
  }
}
```

---

### 3.4 `POST /api/incidents/{ticket_code}/reopen` (Reapertura < 48h)
Solicita una segunda intervención si la máquina vuelve a fallar tras la reparación (RF-09).

* **Request Body:**
```json
{
  "reopen_reason": "El técnico se marchó hace 2 horas pero al introducir monedas de 1 euro las sigue expulsando."
}
```
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "ticket_code": "INC-2026-0004",
    "status": "REABIERTA",
    "reopened_at": "2026-09-22T15:30:00Z"
  },
  "message": "Incidencia reabierta con éxito y enviada a triaje de coordinación."
}
```
* **Errores de Validación / Negocio:**
  * `422 Unprocessable` (`REOPEN_WINDOW_EXPIRED`): **EARS 9.2.** Han transcurrido más de 48 horas desde la resolución.

---

## 4. Módulo de Coordinación y Triaje

### 4.1 `GET /api/coordinator/incidents` (Bandeja Global de Averías)
Consulta el listado completo para supervisión y asignación (RF-05, RF-11).

* **Parámetros de Query (Filtros Opcionales):**
  * `status`: `REGISTERED,ASSIGNED,IN_PROGRESS,PENDING_PARTS,RESOLVED,REOPENED,CLOSED,CANCELLED`
  * `urgency`: `LOW,MEDIUM,HIGH,CRITICAL`
  * `location_id`: ID de sede
  * `technician_id`: ID del técnico asignado
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": [
    {
      "id": 14,
      "ticket_code": "INC-2026-0014",
      "machine_code": "VEND-0101",
      "location_name": "Hospital del Mar",
      "category": "TEMPERATURE_COLD",
      "urgency": "CRITICAL",
      "status": "REGISTERED",
      "assigned_technician": null,
      "sla_minutes_elapsed": 42,
      "sla_breached": false,
      "created_at": "2026-09-22T14:55:00Z"
    }
  ]
}
```

---

### 4.2 `PATCH /api/coordinator/incidents/{id}/assign` (Asignar o Reasignar Técnico)
Asocia un técnico de campo único a la incidencia y, desde el modal de detalle del triaje, sustituye al responsable vigente por otro profesional con motivo justificado (RF-05, RF-07.3, Art. V.3).

* **Autenticación:** Token interno con rol `COORDINATOR` (`401 Unauthorized` sin token o con token inválido; `403 Forbidden` con otro rol).
* **Request Body (asignación inicial):**
```json
{
  "technician_id": 2,
  "urgency_override": "MEDIUM",
  "urgency_override_reason": "Comprobado que la máquina no contiene comida perecedera en esta temporada."
}
```
* **Request Body (reasignación de un aviso con responsable vigente):**
```json
{
  "technician_id": 7,
  "reassignment_reason": "Reasignación por proximidad geográfica al centro con riesgo de rotura de la cadena de frío."
}
```
* **Parámetros:**
  * `technician_id` (int, obligatorio): ID de un técnico de ruta activo con rol `TECHNICIAN`.
  * `urgency_override` / `urgency_override_reason` (string, opcionales): reclasificación de urgencia con motivo obligatorio cuando se envía la nueva clasificación.
  * `reassignment_reason` (string, obligatorio en la reasignación): motivo justificado de **10 caracteres reales** mínimo tras recortar espacios. Se admite también el alias `reason`.
* **Estados admitidos:** asignación inicial desde `REGISTERED` y `REOPENED`; reasignación desde `ASSIGNED`, `IN_PROGRESS` y `PENDING_PARTS`. La reasignación conserva el estado operativo y el hito `assigned_at` original (el instante del cambio queda fechado en el historial inmutable) y mantiene un único técnico responsable activo por incidencia.
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "id": 14,
    "status": "ASSIGNED",
    "assigned_technician_id": 2,
    "assigned_at": "2026-09-22T15:40:00Z"
  }
}
```
* **Errores de Validación / Negocio:**
  * `400 Bad Request` (`INVALID_INCIDENT_ID`): el ID de ruta no es numérico.
  * `401 Unauthorized` (`UNAUTHORIZED`) / `403 Forbidden` (`FORBIDDEN`).
  * `404 Not Found` (`INCIDENT_NOT_FOUND`): la incidencia no existe o está borrada lógicamente.
  * `422 Unprocessable` (`MISSING_TECHNICIAN_ID`): falta `technician_id` o no es un entero positivo.
  * `422 Unprocessable` (`TECHNICIAN_NOT_FOUND`): no existe un usuario activo con rol `TECHNICIAN` para ese ID.
  * `422 Unprocessable` (`INVALID_URGENCY`): la urgencia enviada no pertenece a `LOW, MEDIUM, HIGH, CRITICAL`.
  * `422 Unprocessable` (`URGENCY_REASON_REQUIRED`): reclasificación de urgencia sin motivo justificado.
  * `422 Unprocessable` (`INVALID_STATUS_FOR_ASSIGNMENT`): el estado no admite asignación ni reasignación (`RESOLVED`, `CLOSED`, `CANCELLED`).
  * `422 Unprocessable` (`MISSING_REASSIGNMENT_REASON`): reasignación sin `reassignment_reason`.
  * `422 Unprocessable` (`REASSIGNMENT_REASON_TOO_SHORT`): el motivo de la reasignación no alcanza los 10 caracteres reales.
  * `422 Unprocessable` (`TECHNICIAN_ALREADY_ASSIGNED`): el destino es el responsable actual del aviso.
  * `500 Server Error` (`ASSIGNMENT_FAILED`): fallo inesperado durante la asignación transaccional.
* **Trazabilidad:** la acción deja un registro inmutable en `incident_history` con el actor, los estados de origen y destino y el motivo. La nota de reasignación reserva su texto final tras el marcador `Motivo: `, del que el servicio de detalle lee `technician.reassignment_reason` (apartado 4.4).
* **Auditoría (RNF-04, Art. III.3):** la asignación inicial emite `INCIDENT_ASSIGNED` y la reasignación `INCIDENT_REASSIGNED` en `audit_log` con `entity_type = TICKET`, el coordinador autenticado como causante y el detalle en `new_state` (`status`, `assigned_technician_id` y, en la reasignación, `reassignment_reason`); `metadata` registra `previous_technician_id` y `new_technician_id` en la reasignación.

---

### 4.3 `PATCH /api/coordinator/incidents/{id}/cancel` (Descartar Aviso)
Anulación lógica obligatoriamente justificada con un motivo de **20 caracteres reales** mínimo (RF-06, RF-07.4, Art. III.2 y V.1).

* **Autenticación:** Token interno con rol `COORDINATOR` (`401 Unauthorized` sin token o con token inválido; `403 Forbidden` con otro rol).
* **Request Body:**
```json
{
  "cancellation_reason": "Comprobado por llamada: el conserje apagó por error la regleta del enchufe general."
}
```
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "id": 14,
    "status": "CANCELLED",
    "cancelled_at": "2026-09-22T15:45:00Z"
  }
}
```
* **Parámetros:**
  * `cancellation_reason` (string, obligatorio): motivo del descarte, mínimo **20 caracteres reales** medidos con `mb_strlen()` sobre el texto ya recortado; los acentos y símbolos cuentan como un único carácter y el relleno con espacios no supera el umbral.
* **Errores de Validación / Negocio:**
  * `400 Bad Request` (`INVALID_INCIDENT_ID`): el ID de ruta no es numérico.
  * `401 Unauthorized` (`UNAUTHORIZED`) / `403 Forbidden` (`FORBIDDEN`).
  * `404 Not Found` (`INCIDENT_NOT_FOUND`): la incidencia no existe o está borrada lógicamente.
  * `422 Unprocessable` (`MISSING_CANCELLATION_REASON`): falta el motivo o llega vacío tras recortar.
  * `422 Unprocessable` (`CANCELLATION_REASON_TOO_SHORT`): el motivo no alcanza los 20 caracteres reales.
  * `422 Unprocessable` (`INVALID_STATUS_FOR_CANCELLATION`): el estado actual no admite el descarte.
  * `500 Server Error` (`CANCELLATION_FAILED`): fallo inesperado durante el descarte transaccional.
* **Trazabilidad:** el descarte es un borrado lógico (`status = CANCELLED`) que preserva la fila íntegra y registra el evento inmutable en `incident_history` con el actor, la transición y el motivo; `incidents.cancellation_reason` y `incidents.cancelled_at` conservan el motivo y la fecha (Art. III).
* **Auditoría en `audit_log` (RNF-04):** cada descarte ejecutado emite el evento inmutable `INCIDENT_CANCELLED` con el coordinador que lo autorizó. `previous_state` conserva el estado y el técnico responsable previos, `new_state` registra el nuevo estado, el motivo del descarte y su marca temporal, y `metadata` incluye el `ticket_code`. El rastro permanece además en `incident_history` y en las columnas del soft delete (Art. III).

---

### 4.4 `GET /api/coordinator/incidents/{id}/detail` (Ficha Integral del Expediente)
Devuelve la ficha completa enriquecida que consume el modal de detalle del triaje (Módulo 09, RF-01 a RF-06). Consolida en una única transacción de lectura los metadatos de sede y máquina, el cronograma del ciclo de vida, el SLA de frío, la intervención técnica, la bitácora, el reintegro enmascarado y la matriz de permisos (RNF-01).

* **Autenticación:** Token interno con rol `COORDINATOR` (`401 Unauthorized` sin token o con token inválido; `403 Forbidden` con otro rol).
* **Parámetros de Ruta:** `id` — ID primario (entero positivo) o código de ticket con prefijo `#` opcional (ej. `INC-2026-0142` o `%23INC-2026-0142` si viaja codificado en la URL).
* **Respuesta Exitosa (`200 OK`):** la envolvente canónica con los diez bloques del contrato, en su orden publicado:
  * `incident`: cabecera del ticket (`ticket_code`, `status`, `status_label`, `urgency`, `urgency_label`, `is_reopened`, `reopened_at`, `reopened_reason`, `description`, `report_channel`, `photo_url`, `created_at`, `updated_at`).
  * `location`: sede cliente (`id`, `name`, `code`, `address`, `floor_zone`, `has_physical_reception`).
  * `machine`: máquina (`id`, `code`, `model`, `manufacturer`, `type`, `type_label`, `has_perishables`).
  * `technician`: profesional asignado (`assigned`, `technician_id`, `name`, `operator_code`, `assigned_at`, `assigned_by_name`, `reassignment_reason` — motivo justificado de la última reasignación leído del historial inmutable; `null` mientras el aviso conserve a su primer responsable).
  * `timeline`: hitos del ciclo de vida (`created_at`, `assigned_at`, `started_at`, `paused_at`, `resolved_at`, `closed_at` y tiempos derivados en minutos).
  * `sla`: objetivo de cadena de frío (`has_sla_limit`, `sla_limit_hours`, `is_active_countdown`, `is_breached`, `minutes_remaining`, `historical_balance`, `sla_target_at`).
  * `technical_intervention`: `pause` (motivo y piezas solicitadas), `resolution` (diagnóstico, acción correctiva y piezas sustituidas con coste congelado) y `cancellation`.
  * `comments`: bitácora cronológica; `is_internal` distingue los comentarios públicos de las notas internas de taller.
  * `refund`: expediente de reintegro vinculado (`has_refund`, importe, estado y datos de contacto y pago enmascarados en servidor; Art. V.4).
  * `permissions`: matriz operativa del estado (`can_assign`, `can_reassign`, `can_cancel`, `can_add_comment`).
* **Ejemplo de respuesta (bloques abreviados):**
```json
{
  "success": true,
  "data": {
    "incident": {
      "id": 142,
      "ticket_code": "INC-2026-0142",
      "status": "PENDING_PARTS",
      "status_label": "Pendiente de repuestos",
      "urgency": "CRITICAL",
      "urgency_label": "Crítica",
      "is_reopened": false,
      "description": "El compresor no arranca y los sándwiches superan los 9°C.",
      "report_channel": "QR_CODE",
      "created_at": "2026-10-01 08:15:00",
      "updated_at": "2026-10-02 12:00:00"
    },
    "location": { "id": 1, "name": "Hospital del Mar", "code": "SEDE-BCN-01", "floor_zone": "Planta Baja - Urgencias", "has_physical_reception": true },
    "machine": { "id": 10, "code": "VEND-0101", "model": "FAS Perla Fast Cold", "manufacturer": null, "type": "PERISHABLE_FOOD", "type_label": "Alimentos perecederos (Sándwiches y lácteos frescos)", "has_perishables": true },
    "technician": { "assigned": true, "technician_id": 4, "name": "Jordi Cruz", "operator_code": "OP-BCN-04", "assigned_at": "2026-10-01 08:30:00", "assigned_by_name": "Coordinación Central", "reassignment_reason": null },
    "timeline": { "created_at": "2026-10-01 08:15:00", "assigned_at": "2026-10-01 08:30:00", "started_at": "2026-10-01 09:10:00", "paused_at": "2026-10-01 09:45:00", "resolved_at": null, "closed_at": null, "time_to_assign_minutes": 15, "time_to_first_response_minutes": 55, "total_elapsed_minutes": 180 },
    "sla": { "has_sla_limit": true, "sla_limit_hours": 4.0, "is_active_countdown": true, "is_breached": false, "minutes_remaining": 60, "historical_balance": "Tiempo restante: 1 h 0 min", "sla_target_at": "2026-10-01 12:15:00" },
    "technical_intervention": {
      "pause": { "is_paused": true, "reason": "Fallo en condensador de arranque y relé térmico del compresor.", "requested_parts": [ { "spare_part_id": 12, "part_code": "SP-FAS-RELAY-01", "description": "Relé Térmico Compresor 230V", "quantity": 1, "is_out_of_catalog": false, "justification": null } ] },
      "resolution": { "is_resolved": false, "diagnosis": null, "corrective_action": null, "replaced_parts_declared": false, "replaced_parts": [], "total_parts_cost": 0.0 },
      "cancellation": { "is_cancelled": false, "cancelled_at": null, "cancelled_by_name": null, "reason": null }
    },
    "comments": [ { "id": 86, "author_type": "TECHNICIAN", "author_name": "Jordi Cruz", "comment_text": "Comprobada fuga en bandeja de desescarche.", "is_internal": true, "created_at": "2026-10-01 09:46:00" } ],
    "refund": { "has_refund": true, "refund_id": 5, "claim_code": "REF-2026-00005", "amount": 2.5, "compensation_method": "BIZUM", "status": "REQUIRES_COORDINATOR_APPROVAL", "contact_phone_masked": "6** *** 789", "iban_masked": null, "technician_finding": "FOUND_PHYSICAL", "cash_custody_action": "HELD_FOR_CENTRAL", "refund_tab_url": "#refunds?id=5" },
    "permissions": { "can_assign": false, "can_reassign": true, "can_cancel": true, "can_add_comment": true }
  }
}
```
* **Errores de Validación / Negocio:**
  * `400 Bad Request` (`INVALID_INCIDENT_IDENTIFIER`): identificador de ruta vacío, no positivo o con caracteres no admitidos.
  * `401 Unauthorized` (`UNAUTHORIZED`) / `403 Forbidden` (`FORBIDDEN`).
  * `404 Not Found` (`INCIDENT_NOT_FOUND`): el ticket no existe o está borrado lógicamente.
* **Consideraciones Técnicas:**
  * Endpoint estrictamente de lectura: abre una única transacción de lectura y no escribe ni muta nada (Art. III, RNF-04).
  * Los datos completos de teléfono e IBAN del consumidor nunca salen del servidor; el enmascaramiento se resuelve en el DTO (Art. V.4, RNF-05).

---

### 4.5 `POST /api/coordinator/incidents/{id}/comments` (Añadir Comentario o Nota Interna de Taller)
Registra una anotación en la bitácora de la incidencia desde el modal de detalle y deja constancia inmutable del evento en `audit_log` (Módulo 09, RF-05.3, RNF-04).

* **Autenticación:** Token interno con rol `COORDINATOR`.
* **Parámetros de Ruta:** `id` — ID primario o código de ticket con `#` opcional (mismo contrato que el apartado 4.4).
* **Request Body:**
```json
{
  "comment_text": "Revisado compresor en taller; pieza en camino desde almacén central.",
  "is_internal": true
}
```
  * `comment_text` (string, obligatorio): mínimo **5 caracteres reales** tras recortar espacios en blanco.
  * `is_internal` (boolean, opcional, por defecto `false`): `false` publica el comentario en la bitácora visible del portal de sede; `true` lo marca como nota interna confidencial de taller. Se admiten igualmente `1` y `0`.
* **Respuesta Exitosa (`201 Created`):**
```json
{
  "success": true,
  "data": {
    "id": 341,
    "incident_id": 142,
    "author_type": "COORDINATOR",
    "user_id": 2,
    "author_name": "Coordinación",
    "comment_text": "Revisado compresor en taller; pieza en camino desde almacén central.",
    "photo_path": null,
    "is_internal": true,
    "created_at": "2026-10-05 12:00:00",
    "ticket_code": "INC-2026-0142"
  },
  "message": "Comentario añadido correctamente a la bitácora de la incidencia."
}
```
* **Errores de Validación / Negocio:**
  * `400 Bad Request` (`INVALID_INCIDENT_IDENTIFIER`).
  * `401 Unauthorized` (`UNAUTHORIZED`) / `403 Forbidden` (`FORBIDDEN`).
  * `404 Not Found` (`INCIDENT_NOT_FOUND`).
  * `422 Unprocessable` (`MISSING_COMMENT_TEXT`): falta `comment_text` o llega vacío tras recortar.
  * `422 Unprocessable` (`COMMENT_TOO_SHORT`): el texto no alcanza los 5 caracteres reales.
  * `422 Unprocessable` (`INVALID_IS_INTERNAL`): el indicador no es un booleano reconocible.
  * `422 Unprocessable` (`COMMENT_WINDOW_CLOSED`): bitácora sellada en tickets `CLOSED`/`CANCELLED` (Art. III) o ventana de garantía de 48 horas vencida en tickets `RESOLVED` (Art. V.6).
* **Auditoría (Art. III.3):** cada escritura emite un evento `INCIDENT_COMMENT_ADDED` en `audit_log` con `entity_type = TICKET`, el `entity_id` de la incidencia, el coordinador autenticado como causante (`user_id`, `user_role`, `user_name`) y el detalle del comentario en `new_state` (`comment_id`, `author_type`, `is_internal`, `comment_text`); `metadata.visibility` registra `PUBLIC` o `INTERNAL`.

---

## 5. Módulo de Técnico de Campo (Vista Móvil "Mi Ruta")

### 5.1 `GET /api/technician/my-route`
Devuelve la lista de incidencias asignadas al técnico autenticado (RF-07).

* **Autenticación:** Token de usuario con rol `TECHNICIAN`
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": [
    {
      "id": 14,
      "ticket_code": "INC-2026-0014",
      "urgency": "CRITICAL",
      "status": "ASSIGNED",
      "machine": {
        "code": "VEND-0101",
        "model": "Sanden Vendo G-Drink",
        "floor_wing": "Planta Baja - Urgencias"
      },
      "location": {
        "name": "Hospital del Mar",
        "address": "Passeig Marítim 25, Barcelona",
        "contact_phone": "600111222"
      },
      "category": "TEMPERATURE_COLD",
      "description": "El display marca 14 grados y hay comida fresca dentro."
    }
  ]
}
```

---

### 5.2 `PATCH /api/technician/incidents/{id}/start` (Iniciar Intervención)
Cambia el estado a `IN_PROGRESS` al personarse físicamente frente a la máquina (RF-07).

* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "id": 14,
    "status": "IN_PROGRESS",
    "started_at": "2026-09-22T16:00:00Z"
  }
}
```

---

### 5.3 `PATCH /api/technician/incidents/{id}/pause` (Pausar por Falta de Repuesto)
Registra la necesidad de una pieza adicional no disponible en la furgoneta (RF-07).

* **Request Body:**
```json
{
  "pending_parts_reason": "Se requiere sonda térmica NTC modelo Vendo-04. Solicitada a almacén central."
}
```
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "id": 14,
    "status": "PENDING_PARTS"
  }
}
```

---

### 5.4 `POST /api/technician/incidents/{id}/resolve` (Resolución Obligatoria)
Cierre técnico con validación estricta de informe diagnóstico (RF-08).

* **Request Body:**
```json
{
  "resolution_diagnosis": "Sonda térmica NTC desconectada por vibración del compresor.",
  "resolution_action": "Reconectado el cableado de la sonda, sellado con brida y comprobada bajada de temperatura a 3.5 ºC en ciclo de prueba."
}
```
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "id": 14,
    "status": "RESUELTA",
    "resolved_at": "2026-09-22T16:45:00Z",
    "warranty_expiration": "2026-09-24T16:45:00Z"
  },
  "message": "Incidencia marcada como resuelta. Ventana de garantía de 48h activada."
}
```
* **Errores de Validación / Negocio:**
  * `422 Unprocessable` (`VALIDATION_ERROR`): **EARS 8.2.** Si el diagnóstico o la acción tienen menos de 20 caracteres descriptivos cada uno:
    ```json
    {
      "success": false,
      "error": {
        "code": "VALIDATION_ERROR",
        "message": "Validación de cierre fallida.",
        "details": {
          "resolution_diagnosis": "Debe contener al menos 20 caracteres descriptivos (actual: 14).",
          "resolution_action": "Debe contener al menos 20 caracteres descriptivos (actual: 8)."
        }
      }
    }
    ```

---

## 6. Tareas en Segundo Plano y Cron (`/api/cron`)

### 6.1 `POST /api/cron/auto-close` (Cierre Definitivo de Expedientes)
Ejecutado periódicamente por el sistema para archivar incidencias resueltas tras 48h (RF-10).

* **Cabecera de Seguridad:** `X-Cron-Secret: ...`
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "closed_count": 3,
    "closed_tickets": ["INC-2026-0001", "INC-2026-0002", "INC-2026-0003"]
  }
}
```
