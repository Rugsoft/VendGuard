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

### 2.1 `POST /api/auth/site-login` (Acceso por Código de Sede y Clave de Centro)
Permite al responsable de ubicación identificarse en su centro sin contraseñas personales (RF-01): código de sede más la clave de centro entregada en mano.

* **Cabeceras:** `Content-Type: application/json`
* **Request Body:**
```json
{
  "site_code": "SEDE-BCN-01",
  "access_code": "K7M4P-2QX9R"
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
  * `401 Unauthorized` (`INVALID_SITE_CREDENTIALS`): mensaje único «Código o clave no reconocidos. Contacte con el servicio técnico.»; no distingue sede inexistente, inactiva, sin clave emitida o clave incorrecta.
* **Nota de custodia:** la clave de centro solo se devuelve **una vez**, en la respuesta del alta o de la reemisión en coordinación; el login nunca la devuelve ni queda registrada en claro.

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
  * `400 Bad Request` (`MISSING_CREDENTIALS`): Correo o contraseña obligatorios ausentes.
  * `401 Unauthorized` (`INVALID_CREDENTIALS`): Correo o contraseña incorrectos.
  * `423 Locked` (`ACCOUNT_LOCKED`): Cuenta temporalmente bloqueada por acumular 5 intentos fallidos consecutivos (freno de fuerza bruta S-3). Expira automáticamente a los 15 minutos.

---

## 3. Módulo de Portal de Ubicación (Informador)

### 3.1 `GET /api/locations/{site_code}/machines`
Obtiene el parque de máquinas instaladas en la sede para el formulario de reporte (RF-01, RF-02).

* **Autenticación:** exclusivamente `Authorization: Bearer <site_token>` emitido por `POST /api/auth/site-login`
  con código de sede y clave de centro vigente. La cabecera `X-Site-Code` queda **retirada** como vía de
  autenticación (hallazgo S-4, cerrado el 2026-10-08; ver
  [`docs/propuesta_decision_s4_acceso_sede.md`](../../docs/propuesta_decision_s4_acceso_sede.md)).
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

### 3.3 `GET|POST /api/incidents/{ticket_code}/comments` (Hilo de Conversación de la Sede)

* **Rutas:**
  * `GET` / `POST /api/incidents/{ticket_code}/comments` — alias retrocompatible del informador (EARS 2.2).
  * `GET` / `POST /api/location/incidents/{id}/comments` — rutas canónicas del módulo 10; el `{id}` admite tanto el identificador primario como el código de ticket.
* **Autenticación:** middleware `SiteAuthMiddleware` (token de sesión de sede; la cabecera `X-Site-Code` está retirada).
* **Contrato del módulo propietario:** [Módulo 10 · Hilo de comentarios](../10-incident-comments/plan.md) (§2.1.A consulta, §2.2.A publicación).

#### 3.3.1 Consulta del hilo (`GET`)

* **Parámetros de consulta:**
  * `limit` *(int, opcional, por defecto `50`)*: tamaño del bloque. Valores por encima de **100** se saturan en ese tope; `0` o un valor no numérico devuelven `400 INVALID_LIMIT`.
  * `before_id` *(int, opcional)*: paginación retrospectiva por cursor. Devuelve el bloque inmediatamente anterior al identificador indicado; un valor no positivo o no numérico devuelve `400 INVALID_BEFORE_ID`.
* **Segregación obligatoria (Art. V.4):** el servidor filtra `is_internal = 0` **antes de serializar**. El canal de sede no devuelve notas internas, ni el campo `is_internal`, ni los datos de contacto del personal técnico.
  * `author_type = TECHNICIAN` → el nombre real se sustituye en servidor por `Servicio Técnico Oficial (Operador #NN)` (`sprintf('%02d', userId)`).
  * `author_type = COORDINATOR` → `Coordinación Central de Operaciones`.
  * `author_type = REPORTER` → `Responsable de Sede · <nombre de la ubicación>`.
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "incident": {
      "id": 142,
      "ticket_code": "INC-2026-0142",
      "machine_code": "VEND-0101",
      "machine_model": "Sanden Vendo G-Drink",
      "location_name": "Hospital del Mar - Edificio Central",
      "status": "IN_PROGRESS",
      "status_label": "En curso",
      "is_sealed": false,
      "can_comment": true,
      "read_only_reason": null
    },
    "pagination": {
      "total_comments": 2,
      "loaded_count": 2,
      "has_more_before": false,
      "oldest_id": 205401,
      "latest_id": 205403
    },
    "comments": [
      {
        "id": 205401,
        "author_type": "REPORTER",
        "author_name": "Responsable de Sede · Hospital del Mar - Edificio Central",
        "comment_text": "Adjunto fotografía del panel con el código de error E04 en la pantalla.",
        "photo_url": "/uploads/f0aa1d017d1da890b2e0e2f8025c08ec.jpg",
        "created_at": "2026-10-08 08:12:54",
        "is_own_message": true
      },
      {
        "id": 205403,
        "author_type": "TECHNICIAN",
        "author_name": "Servicio Técnico Oficial (Operador #02)",
        "comment_text": "Repongo el servicio tras sustituir el fusible del muelle.",
        "photo_url": null,
        "created_at": "2026-10-08 08:12:54",
        "is_own_message": false
      }
    ]
  }
}
```
  * **`incident`**: cabecera contextual del expediente. `can_comment = false` indica modo de solo lectura; `read_only_reason` transporta el motivo legible cuando el servidor lo determina y puede llegar `null` aunque el expediente esté sellado (`is_sealed = true`).
  * **`pagination`**: `total_comments` contabiliza **solo los mensajes públicos** en este canal; `loaded_count` es el tamaño del bloque devuelto; `has_more_before` indica si quedan bloques anteriores; `oldest_id` y `latest_id` son los cursores (`null` con el hilo vacío).
  * **`comments`**: mensajes en orden cronológico ascendente. El canal de sede omite deliberadamente el campo `is_internal` y cierra cada mensaje con `is_own_message` (autoría del informador autenticado). El expediente sellado sigue siendo **legible** (`200 OK`).

#### 3.3.2 Publicación de un mensaje (`POST`)

* **Formato:** `multipart/form-data` (con fotografía) o `application/json` (sin archivo).
* **Campos:**
  * `comment_text` *(string, obligatorio)*: entre **5 y 1.000 caracteres** UTF-8 descriptivos tras recortar espacios. Se aceptan los alias `text`, `description` y `comment`.
  * `photo` *(file, opcional)*: imagen JPG, PNG o WebP de **≤ 5 MB** validada por *magic bytes* reales. Alias: `image`, `file`.
  * `author_name` *(string, opcional)*: **se acepta por compatibilidad y se ignora**; la autoría se deriva en servidor de la sede autenticada.
  * `is_internal`: **ignorado por diseño**; el canal de sede publica siempre `is_internal = 0` (Art. V.4), también en `multipart/form-data`.
* **Respuesta Exitosa (`201 Created`):** envelope con `data`, `message` y **el hilo completo actualizado** (mismo DTO que el `GET`, con el mensaje recién publicado como último elemento de `comments` y `total_comments` recalculado):
```json
{
  "success": true,
  "data": {
    "incident": { "…": "misma cabecera contextual que en el apartado 3.3.1" },
    "pagination": { "total_comments": 3, "loaded_count": 3, "has_more_before": false, "oldest_id": 205401, "latest_id": 205404 },
    "comments": [ { "…": "hilo público íntegro en orden cronológico; el mensaje recién publicado es el último elemento" } ]
  },
  "message": "Comentario publicado en el hilo de conversación"
}
```
* **Errores de Validación / Negocio:**
  * `400 Bad Request` (`MISSING_COMMENT_TEXT`): falta el texto o llega vacío tras recortar.
  * `400 Bad Request` (`INVALID_LIMIT` / `INVALID_BEFORE_ID`): parámetros de consulta mal formados (`GET`).
  * `401 Unauthorized` (`UNAUTHORIZED`): no se ha podido verificar la sede autenticada.
  * `403 Forbidden` (`SITE_MISMATCH`): el expediente pertenece a otra sede.
  * `403 Forbidden` (`CONVERSATION_SEALED`): expediente `CLOSED`/`CANCELLED`, o `RESOLVED` fuera de la ventana de garantía de 48 h. La lectura sigue disponible.
  * `404 Not Found` (`INCIDENT_NOT_FOUND`): no existe ningún expediente con ese código o identificador.
  * `422 Unprocessable` (`INVALID_COMMENT_LENGTH`): texto con menos de 5 o más de 1.000 caracteres.
  * `422 Unprocessable` (`FILE_TOO_LARGE`): archivo adjunto de más de 5 MB.
  * `422 Unprocessable` (`INVALID_FILE_TYPE`): *magic bytes* que no corresponden a una imagen segura. En ambos errores de archivo se devuelve `details.form_data` con `ticket_code` y `comment_text` para permitir el reintento sin volver a redactar.
* **Auditoría (Art. III.3):** cada publicación emite un evento inmutable `INCIDENT_COMMENT_ADDED` en `audit_log` (`entity_type = TICKET`, `entity_id` del expediente, autor y visibilidad `PUBLIC`).

> **Contrato del hilo (decisión registrada el 08/10/2026).** Los endpoints de comentarios de la sede hablan el **DTO de hilo del módulo 10**
> (`data.incident` + `data.pagination` + `data.comments[]`), tal y como implementan `IncidentCommentThreadDto` e
> `IncidentCommentItemDto`. Este contrato **sustituye** al payload plano (`data.id`, `data.incident_id`, `data.author_name`,
> `data.comment_text`, `data.created_at`) que documentaba la versión inicial de esta especificación y que el servicio dejó de
> emitir al integrarse el hilo bidireccional (T-COM-01 … T-COM-05). **No se mantiene ningún alias de compatibilidad** porque el
> único consumidor de frontend ignora el cuerpo de la respuesta, la segregación de notas internas exige que el payload sea
> precisamente el hilo ya filtrado, y ningún cliente dependía del objeto plano. Los códigos `COMMENT_TOO_SHORT` e
> `INCIDENT_NOT_ACTIVE` tampoco se emiten ya: su semántica la cubren `INVALID_COMMENT_LENGTH` (422) y `CONVERSATION_SEALED` (403).
> Un cliente que hoy lea `data.id` debe migrar a `data.comments[].id`. La decisión va acompañada de la verificación de caja negra
> del canal de sede documentada en [verificacion_modulo_10_reejecucion_humo_funcionalidad_flujo.md](../../docs/verificacion_modulo_10_reejecucion_humo_funcionalidad_flujo.md).

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
    "status": "REOPENED",
    "status_label": "Reabierta",
    "status_canonical": "REOPENED",
    "assigned_technician_id": null,
    "reopened_at": "2026-09-22T15:30:00Z",
    "reopen_reason": "El técnico se marchó hace 2 horas pero al introducir monedas de 1 euro las sigue expulsando."
  },
  "message": "Incidencia reabierta con éxito y enviada a triaje de coordinación."
}
```
> **Contrato de estado (normalizado):** el campo `status` de **todos** los endpoints de la API transporta el enumerado canónico
> (`REGISTERED`, `ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS`, `RESOLVED`, `REOPENED`, `CLOSED`, `CANCELLED`) y la etiqueta legible
> en castellano viaja siempre en `status_label`. `status_canonical` se conserva como **alias deprecado** de `status` durante una
> versión para no romper a los clientes que ya lo consumían; su uso en código nuevo está desaconsejado.
> **Efectos obligatorios de la reapertura (EARS 9.1):** `assigned_technician_id = NULL`, `assigned_at = NULL`, `resolved_at = NULL`,
> `reopened_at = ahora` y reinicio del reloj de garantía de 48 h. La transición queda registrada en `incident_history`
> (`RESOLVED → REOPENED`) y el evento inmutable **`REOPEN_TICKET`** se anota en `audit_log`
> (`user_role = SITE_MANAGER`, `new_state.status = REOPENED`), dando cumplimiento a EARS 5.1.2 de
> [metrics_audit_spec.md](../functional/metrics_audit_spec.md).
* **Errores de Validación / Negocio:**
  * `400 Unprocessable` (`MISSING_REOPEN_REASON`): falta el motivo descriptivo de la reapertura.
  * `401 Unauthorized` (`UNAUTHORIZED`): no se ha podido verificar la sede autenticada.
  * `403 Forbidden` (`SITE_MISMATCH`): el expediente pertenece a otra sede.
  * `422 Unprocessable` (`REOPEN_WINDOW_EXPIRED`): **EARS 9.2.** Han transcurrido más de 48 horas desde la resolución.
  * `422 Unprocessable` (`REOPEN_REASON_TOO_SHORT`): el motivo no alcanza los 5 caracteres descriptivos.
  * `422 Unprocessable` (`CHRONIC_INCIDENT_LIMIT`): **EARS 9.3.** Tercera reincidencia consecutiva: expediente marcado como *Avería Crónica*.

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

### 4.5 `GET|POST /api/coordinator/incidents/{id}/comments` (Hilo de Conversación de la Coordinación)

Consulta y publica mensajes en la bitácora del expediente desde el modal de detalle, con notas internas de taller
incluidas, y deja constancia inmutable del evento en `audit_log` (Módulo 09 y Módulo 10, RF-05.3, RNF-04).

* **Autenticación:** token interno con rol `COORDINATOR`. Un rol interno distinto (p. ej. técnico) recibe `403 FORBIDDEN`; sin token, `401 UNAUTHORIZED`.
* **Identificador de ruta (`{id}`):** ID primario positivo, código de ticket o `#` + código de ticket (los tres admitidos en `GET` y en `POST`). Un identificador vacío, no positivo o con caracteres no admitidos devuelve `400 INVALID_INCIDENT_IDENTIFIER`.
* **Contrato del módulo propietario:** [Módulo 10 · Hilo de comentarios](../10-incident-comments/plan.md) (§2.1.C consulta, §2.2.C publicación).

#### 4.5.1 Consulta del hilo (`GET`)

* **Parámetros de consulta:**
  * `limit` *(int, opcional, por defecto `50`)*: tamaño del bloque; los valores por encima de **100** se saturan en el tope y `0` o un valor no numérico devuelven `400 INVALID_LIMIT`.
  * `before_id` *(int, opcional)*: paginación retrospectiva por cursor; un valor no positivo o no numérico devuelve `400 INVALID_BEFORE_ID`.
* **Visibilidad total (Art. III):** el canal interno no filtra nada. Devuelve los mensajes públicos **y** las notas internas, expone el campo booleano `is_internal` y muestra los nombres nominales reales de todos los autores, sin enmascaramiento. `total_comments` cuenta la totalidad de mensajes del expediente (comprobado con un hilo de 127 mensajes: 121 públicos + 6 internos). `is_own_message` es verdadero cuando el `user_id` del mensaje coincide con el del coordinador autenticado.
* **Respuesta Exitosa (`200 OK`):** misma estructura de hilo que el apartado 3.3.1 (`incident` + `pagination` + `comments`), con el canal interno en los mensajes:
```json
{
  "success": true,
  "data": {
    "incident": {
      "id": 142,
      "ticket_code": "INC-2026-0142",
      "machine_code": "VEND-0101",
      "machine_model": "Sanden Vendo G-Drink",
      "location_name": "Hospital del Mar - Edificio Central",
      "status": "ASSIGNED",
      "status_label": "Asignada",
      "is_sealed": false,
      "can_comment": true,
      "read_only_reason": null
    },
    "pagination": {
      "total_comments": 4,
      "loaded_count": 4,
      "has_more_before": false,
      "oldest_id": 205716,
      "latest_id": 205719
    },
    "comments": [
      {
        "id": 205716,
        "author_type": "REPORTER",
        "author_name": "Responsable de Sede · Hospital del Mar - Edificio Central",
        "comment_text": "Mensaje público de la sede para el hilo.",
        "photo_url": null,
        "created_at": "2026-10-08 08:21:10",
        "is_own_message": false,
        "is_internal": false
      },
      {
        "id": 205717,
        "author_type": "TECHNICIAN",
        "author_name": "Jordi Técnico Ruta BCN",
        "comment_text": "Nota interna del técnico de ruta.",
        "photo_url": null,
        "created_at": "2026-10-08 08:21:10",
        "is_own_message": false,
        "is_internal": true
      },
      {
        "id": 205719,
        "author_type": "COORDINATOR",
        "author_name": "Sara Coordinadora",
        "comment_text": "Nota interna de coordinación.",
        "photo_url": null,
        "created_at": "2026-10-08 08:21:10",
        "is_own_message": true,
        "is_internal": true
      }
    ]
  }
}
```
* **Errores de Validación / Negocio:** `400` (`INVALID_INCIDENT_IDENTIFIER` / `INVALID_LIMIT` / `INVALID_BEFORE_ID`), `401` (`UNAUTHORIZED`), `403` (`FORBIDDEN`) y `404` (`INCIDENT_NOT_FOUND`).

#### 4.5.2 Publicación de un mensaje (`POST`)

* **Formato:** `application/json` (sin archivo) o `multipart/form-data` (con fotografía).
* **Campos:**
  * `comment_text` *(string, obligatorio)*: entre **5 y 1.000 caracteres** tras recortar espacios. Alias admitidos: `text`, `comment`.
  * `is_internal` *(boolean, opcional)*: **por defecto `true`** — la *Nota Interna de Taller* viene preseleccionada (RF-03.3) y solo un valor explícito la desmarca. Se admiten `true`/`false` y `1`/`0` (también como cadena); cualquier otro valor devuelve `422 INVALID_IS_INTERNAL`.
  * `photo` *(file, opcional)*: imagen JPG, PNG o WebP de ≤ 5 MB validada por *magic bytes*. Alias: `image`, `file`.
* **Respuesta Exitosa (`201 Created`):** envelope con `data` (el hilo completo actualizado, igual que en 4.5.1) y un `message` que distingue la visibilidad: `"Nota interna registrada en el hilo de conversación"` o `"Comentario publicado en el hilo de conversación"`.
* **Errores de Validación / Negocio:**
  * `400 Bad Request` (`MISSING_COMMENT_TEXT`): falta `comment_text` o llega vacío tras recortar.
  * `401 Unauthorized` (`UNAUTHORIZED`) / `403 Forbidden` (`FORBIDDEN`).
  * `403 Forbidden` (`CONVERSATION_SEALED`): expediente `CLOSED`/`CANCELLED` o `RESOLVED` fuera de la ventana de garantía de 48 h. La lectura sigue disponible (`200`, con `is_sealed = true` y `can_comment = false`).
  * `404 Not Found` (`INCIDENT_NOT_FOUND`): ni el ID ni el código de ticket corresponden a un expediente existente.
  * `422 Unprocessable` (`INVALID_COMMENT_LENGTH`): texto con menos de 5 o más de 1.000 caracteres.
  * `422 Unprocessable` (`INVALID_IS_INTERNAL`): indicador no reconocible como booleano.
  * `422 Unprocessable` (`FILE_TOO_LARGE` / `INVALID_FILE_TYPE`): adjunto de más de 5 MB o *magic bytes* no seguros.
  * **Eco para el reintento (RF-07.1):** los errores recuperables devuelven `details.form_data` con el texto íntegro —`comment_text`, y `is_internal` cuando el rechazo es por sellado o por archivo— para que la interfaz no obligue a reescribir la nota.
  * **Orden de guardas:** la validación de la bandera de privacidad se resuelve **antes** del sellado; un indicador mal formado sobre un expediente cerrado devuelve `422 INVALID_IS_INTERNAL`, no `403`.
* **Auditoría (Art. III.3):** cada publicación emite un evento inmutable `INCIDENT_COMMENT_ADDED` en `audit_log` con `entity_type = TICKET`, el `entity_id` del expediente, el coordinador autenticado como causante (`user_id`, `user_role = COORDINATOR`, `user_name`) y `new_state = { comment_id, ticket_code, is_internal, has_photo }`. El evento **no copia el texto del mensaje** (`metadata` queda a `null`), de modo que el contenido confidencial no se duplica fuera del hilo.

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
      "description": "El display marca 14 grados y hay comida fresca dentro.",
      "paused_at": null,
      "total_pending_info_seconds": 0,
      "pending_info_reason_category": null,
      "pending_info_reason_category_label": null,
      "pending_info_reason_text": null
    }
  ]
}
```

* **Extensión del Módulo 11 (T-PAUSE-18, RF-05.5):** la ruta incluye también las averías en `PENDING_INFO` (pausa por bloqueo imputable a la sede). Una parada pausada no desaparece de "Mi Ruta": viaja con `paused_at` (marca del intervalo vivo), `total_pending_info_seconds` (acumulado exacto en segundos, RNF-01), `pending_info_reason_category`, `pending_info_reason_category_label` y `pending_info_reason_text`, de modo que la tarjeta móvil pinte la insignia `⏸️ En espera de sede` con su contador y ofrezca la reanudación in situ (`POST /api/technician/incidents/{id}/resume-pending-info`). El resto de campos de pausa viajan a `null` cuando el expediente no está pausado.

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

### 5.5 `GET|POST /api/technician/incidents/{id}/comments` (Hilo de Conversación de la Ruta)

Hilo de conversación del expediente en la vista móvil *Mi Ruta*: el técnico consulta la conversación completa
(notas internas incluidas) y publica mensajes clasificados como nota interna de taller o como mensaje público
para la sede (Módulo 10, RF-01.2, RF-02.3, RF-03.3, RF-04.1, RF-05.4).

* **Autenticación:** token interno con rol `TECHNICIAN`. Otro rol interno recibe `403 FORBIDDEN`; sin token, `401 UNAUTHORIZED`.
* **Identificador de ruta (`{id}`):** admite el ID primario del expediente y también el código de ticket.
* **Contrato del módulo propietario:** [Módulo 10 · Hilo de comentarios](../10-incident-comments/plan.md) (§2.1.B consulta, §2.2.B publicación).

#### 5.5.1 Consulta del hilo (`GET`)

* **Parámetros de consulta:** `limit` *(opcional, por defecto `50`, tope `100`)* y `before_id` *(cursor retrospectivo)*, con los mismos códigos de rechazo que el resto del hilo (`400 INVALID_LIMIT` / `INVALID_BEFORE_ID`).
* **Alcance de lectura (RF-05.4):**
  * **Asignado:** el técnico responsable activo consulta el hilo completo del expediente.
  * **Antecedentes acreditados:** un técnico que figura en el historial inmutable del expediente (`IN_PROGRESS`/`RESOLVED` firmado con su `user_id`) consulta un expediente **`REOPENED` y sin reasignar** en **modo de solo lectura**, con `can_comment = false` y `read_only_reason = "REOPENED_AWAITING_REASSIGNMENT"`; la interfaz muestra el aviso de reasignación pendiente en lugar de un formulario condenado a un `403`.
  * **Técnico ajeno:** cualquier otro técnico recibe `403 NOT_ASSIGNED_TO_TECHNICIAN` tanto en lectura como en escritura.
* **Visibilidad total (Art. III):** se devuelven los mensajes públicos y las notas internas, con el campo `is_internal` y los nombres nominales reales (compañeros, coordinación y la sede como `Responsable de Sede · <ubicación>`). `total_comments` cuenta todos los mensajes y `is_own_message` marca los propios.
* **Respuesta Exitosa (`200 OK`):** estructura de hilo del apartado 4.5.1, con la cabecera en modo lectura cuando procede:
```json
{
  "success": true,
  "data": {
    "incident": {
      "id": 142,
      "ticket_code": "INC-2026-0142",
      "machine_code": "VEND-0102",
      "machine_model": "Bianchi Gaia Espresso",
      "location_name": "Hospital del Mar - Edificio Central",
      "status": "REOPENED",
      "status_label": "Reabierta",
      "is_sealed": false,
      "can_comment": false,
      "read_only_reason": "REOPENED_AWAITING_REASSIGNMENT"
    },
    "pagination": {
      "total_comments": 4,
      "loaded_count": 4,
      "has_more_before": false,
      "oldest_id": 205713,
      "latest_id": 205719
    },
    "comments": [
      {
        "id": 205713,
        "author_type": "TECHNICIAN",
        "author_name": "Jordi Técnico Ruta BCN",
        "comment_text": "Nota interna del técnico de ruta.",
        "photo_url": null,
        "created_at": "2026-10-08 08:21:10",
        "is_own_message": true,
        "is_internal": true
      }
    ]
  }
}
```
* **Errores de Validación / Negocio:** `400` (`INVALID_LIMIT` / `INVALID_BEFORE_ID`), `401` (`UNAUTHORIZED`), `403` (`FORBIDDEN` / `NOT_ASSIGNED_TO_TECHNICIAN`) y `404` (`INCIDENT_NOT_FOUND`).

#### 5.5.2 Publicación de un mensaje (`POST`)

* **Formato:** `multipart/form-data` (con fotografía in situ) o `application/json` (sin archivo).
* **Campos:**
  * `comment_text` *(string, obligatorio)*: entre **5 y 1.000 caracteres** tras recortar espacios. Alias admitidos: `text`, `comment`.
  * `is_internal` *(boolean, opcional)*: **por defecto `true`** por seguridad — sin el campo, el mensaje se guarda como nota interna de taller (fail-safe). Se admiten `true`/`false` y `1`/`0`; cualquier otro valor devuelve `422 INVALID_IS_INTERNAL`.
  * `photo` *(file, opcional)*: JPG, PNG o WebP de ≤ 5 MB validada por *magic bytes*. Alias: `image`, `file`.
* **Regla de escritura:** publica **solo el técnico que tiene el expediente asignado**. Un técnico con antecedentes pero sin reasignación —aunque pueda leer— recibe `403 NOT_ASSIGNED_TO_TECHNICIAN`, igual que un técnico ajeno.
* **Respuesta Exitosa (`201 Created`):** envelope con `data` (hilo completo actualizado) y un `message` según la clasificación: `"Nota interna registrada en el hilo de conversación"` o `"Comentario publicado en el hilo de conversación"`.
* **Errores de Validación / Negocio:**
  * `400 Bad Request` (`MISSING_COMMENT_TEXT`): falta `comment_text` o llega vacío tras recortar.
  * `401 Unauthorized` (`UNAUTHORIZED`) / `403 Forbidden` (`FORBIDDEN`).
  * `403 Forbidden` (`NOT_ASSIGNED_TO_TECHNICIAN`): expediente no asignado al técnico autenticado.
  * `403 Forbidden` (`CONVERSATION_SEALED`): expediente `CLOSED`/`CANCELLED` o `RESOLVED` fuera de la ventana de garantía de 48 h. La lectura sigue disponible (`200`, con `is_sealed = true` y `can_comment = false`).
  * `404 Not Found` (`INCIDENT_NOT_FOUND`).
  * `422 Unprocessable` (`INVALID_COMMENT_LENGTH`): texto con menos de 5 o más de 1.000 caracteres.
  * `422 Unprocessable` (`INVALID_IS_INTERNAL`): indicador no reconocible como booleano.
  * `422 Unprocessable` (`FILE_TOO_LARGE` / `INVALID_FILE_TYPE`): adjunto de más de 5 MB o *magic bytes* no seguros.
  * **Eco para el reintento (RF-07.1):** los errores recuperables devuelven `details.form_data` con `ticket_code` y `comment_text` (y `is_internal` cuando el rechazo es por sellado o por archivo), de modo que la ruta móvil pueda reintentar sin volver a redactar.
* **Auditoría (Art. III.3):** cada publicación emite un evento inmutable `INCIDENT_COMMENT_ADDED` en `audit_log` con el técnico autenticado como causante (`user_id`, `user_role = TECHNICIAN`, `user_name`) y `new_state = { comment_id, ticket_code, is_internal, has_photo }`; el texto del mensaje no se copia al registro de auditoría.

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
