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
| `422 Unprocessable`| Validación fallida | **EARS 8.2:** Diagnóstico < 20 caracteres; **EARS 9.2:** Reapertura tras 48h; **RF-03.5.2:** Declaraciones sanitarias obligatorias del cierre (§5.4.1) |
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
      "sanitary_status": "QUARANTINE",
      "active_incident": null
    },
    {
      "id": 2,
      "code": "VEND-0102",
      "model": "Bianchi Gaia Espresso",
      "machine_type": "HOT_DRINKS",
      "floor_wing": "Planta 1 - Sala Médica",
      "notes": "Café en grano",
      "sanitary_status": "OK",
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

* **`sanitary_status` (T-PAUSE-29, RF-03.5.1, Art. II):** cada máquina del parque —incluidas las
  bloqueadas por falta de acceso, que viajan por la misma lista— publica su estado higiénico-sanitario
  del catálogo `OK` · `ATTENTION_REQUIRED` · `EXPIRED` · `QUARANTINE` · `SEASONAL_PAUSE`. Una columna
  vacía se normaliza a `OK`: el silencio de la base de datos jamás se interpreta como riesgo térmico.
  Con `QUARANTINE` la tarjeta de sede pinta el distintivo literal
  «Riesgo térmico: máquina en cuarentena preventiva», el mismo rótulo que el escaneo QR público sirve
  al ciudadano (ver §7.2), de modo que ambos canales leen una sola cuarentena y no dos relatos.
  El campo es estado de la máquina, no del expediente: no abre ninguna rendija a los datos internos
  de una pausa por falta de acceso (Art. V.4).

---

### 3.2 `POST /api/incidents` (Crear Nueva Incidencia)
Registra un nuevo aviso de avería con cálculo automático de urgencia y control de duplicados (RF-02, RF-03).

* **Formato:** `application/json` (sin fotografía) o `multipart/form-data` (con fotografía opcional). El portal de sede envía JSON puro cuando no hay imagen; ambas formas comparten los mismos campos y el mismo contrato de respuesta (hallazgo H-6).
* **Parámetros / Campos:**
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
    "machine_id": 3,
    "machine_code": "VEND-0101",
    "machine_model": "Sanden Vendo G-Drink",
    "machine_type": "PERISHABLE_FOOD",
    "location_id": 1,
    "location_site_code": "SEDE-BCN-01",
    "location_name": "Hospital del Mar - Edificio Central",
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
  * `409 Conflict` (`TICKET_CODE_COLLISION`): **EARS 2.4.** Los tres intentos de asignación de código de ticket chocaron con el índice único `uq_incidents_ticket_code`. Es la única respuesta posible cuando el reintento acotado se agota; el alta nunca responde `500` por esta causa. La misma regla cubre el portal de sede, el puente de no conformidad preventiva y las dos superficies oportunistas del Módulo 16 (alta in situ y sustitución por Avería Crónica, §4.3 y §4.4 de [sus contratos](opportunistic_incident_contracts.md)), que responden con su propio envolvente de error.
    ```json
    {
      "success": false,
      "error": {
        "code": "TICKET_CODE_COLLISION",
        "message": "No se pudo reservar un código de ticket único tras varios intentos. Vuelva a enviar el aviso.",
        "details": { "attempts": 3 }
      }
    }
    ```

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
  * `403 Forbidden` (`CONVERSATION_SEALED`): expediente `CLOSED`/`CANCELLED`, o `RESOLVED` fuera de la ventana de garantía de 48 h. La lectura sigue disponible. Si el expediente se canceló por inactividad de sede y la máquina sigue bloqueada por falta de acceso (RF-04.4), el mensaje del rechazo incluye además la guía de RF-05.4 —confirmar el acceso a la máquina al registrar un nuevo aviso—; el resto de sellados conserva el mensaje genérico.
  * `404 Not Found` (`INCIDENT_NOT_FOUND`): no existe ningún expediente con ese código o identificador.
  * `422 Unprocessable` (`INVALID_COMMENT_LENGTH`): texto con menos de 5 o más de 1.000 caracteres.
  * `422 Unprocessable` (`FILE_TOO_LARGE`): archivo adjunto de más de 5 MB.
  * `422 Unprocessable` (`INVALID_FILE_TYPE`): *magic bytes* que no corresponden a una imagen segura. En ambos errores de archivo se devuelve `details.form_data` con `ticket_code` y `comment_text` para permitir el reintento sin volver a redactar.
* **Auditoría (Art. III.3):** cada publicación emite un evento inmutable `INCIDENT_COMMENT_ADDED` en `audit_log` (`entity_type = TICKET`, `entity_id` del expediente, autor y visibilidad `PUBLIC`).

> **Sin bloque de reactivación (enmienda del Product Owner, 2026-10-11).** El mensaje público de la sede ya no transiciona el expediente (RF-02.1 enmendado): la respuesta es el hilo canónico, sin los campos `auto_resumed` y `pause` que la versión anterior añadía al publicar sobre un expediente en `PENDING_INFO`. El servidor solo deriva la marca `site_response_since_pause` que consume la ruta técnica (§5.1); la pausa se cierra con la reanudación manual del técnico o del coordinador, o con la cancelación justificada por inactividad.

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
      "is_sla_paused": false,
      "total_pending_info_minutes": 0,
      "is_prolonged_inactivity": false,
      "created_at": "2026-09-22T14:55:00Z"
    }
  ]
}
```

* **Extensión del Módulo 11 (T-PAUSE-19, RF-03.2, RF-04.2):** cada fila publica el estado del reloj contractual congelado para que la bandeja pinte la insignia `⏸️ SLA Pausado (+X min desc.)`, el filtro rápido de la espera de sede y el aviso urgente de la inactividad prolongada sin abrir una segunda petición por fila. `is_sla_paused` (la avería espera a la sede: el SLA no corre), `total_pending_info_minutes` (minutos ya descontados del cómputo contractual, RNF-01) y `is_prolonged_inactivity` (más de 72 horas hábiles sin respuesta ni acceso, medida en horario comercial de la sede) se suman a los campos de pausa que ya viajan desde `Incident::toArray()` (`paused_at`, `pending_info_reason_category`, `pending_info_reason_category_label`, `pending_info_reason_text`, `total_pending_info_seconds`).

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
  * `override_justification` (string, obligatorio **solo** al descartar la recomendación sanitaria): justificación de **20 caracteres reales** mínimo tras recortar espacios (Art. II y V.1, RF-05.2, Resolución QA 8). Se exige cuando la avería presenta riesgo de cadena de frío, el escalado sanitario está activo y el técnico asignado es distinto del primer puesto del ranking —el técnico de guardia sugerido—. Convive con `reassignment_reason` sin sustituirlo: uno razona el descarte sanitario y el otro el cambio de titular.
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
  * `422 Unprocessable` (`COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED`): la avería presenta riesgo de frío, el escalado sanitario está activo y el técnico elegido no es el sugerido, sin `override_justification` de al menos 20 caracteres reales (Art. II y Art. V.1, Resolución QA 8). La comprobación es Fail-Fast: ocurre antes de abrir la transacción, de modo que el rechazo no escribe ni el cambio de responsable ni el rastro de salvaguarda.
  * `500 Server Error` (`ASSIGNMENT_FAILED`): fallo inesperado durante la asignación transaccional.
* **Trazabilidad:** la acción deja un registro inmutable en `incident_history` con el actor, los estados de origen y destino y el motivo. La nota de reasignación reserva su texto final tras el marcador `Motivo: `, del que el servicio de detalle lee `technician.reassignment_reason` (apartado 4.4).
* **Auditoría (RNF-04, Art. III.3):** la asignación inicial emite `INCIDENT_ASSIGNED` y la reasignación `INCIDENT_REASSIGNED` en `audit_log` con `entity_type = TICKET`, el coordinador autenticado como causante y el detalle en `new_state` (`status`, `assigned_technician_id` y, en la reasignación, `reassignment_reason`); `metadata` registra `previous_technician_id` y `new_technician_id` en la reasignación. Cuando la asignación descarta la recomendación sanitaria, se añade un segundo evento **`INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN`** (T-ZONE-10, contrato del módulo §5.1) con el técnico sugerido en `previous_state.suggested_technician_id`, el enviado en `new_state.assigned_technician_id` y la `override_justification` íntegra; es un hecho distinto del cambio de responsable a propósito, para que el visor de auditoría pueda filtrar la salvaguarda del Art. V.1.
* **Privacidad (Art. V.4):** la respuesta es exclusiva de Coordinación; el portal de sede nunca recibe zonas, cuadrantes ni marcas de excepción manual.

---

### 4.2.1 `GET /api/coordinator/incidents/{id}/suggested-technicians` (Recomendador de Idoneidad)
Publica el ranking explicado del despacho asistido (RF-04.1, RF-05.1, RNF-01) tal y como lo calcula `TechnicianAssignmentSuggester`: la avería con su sede y su zona, la recomendación con su explicación y el desglose exacto de la puntuación de cada candidato. El panel de triaje no recalcula nada y el orden publicado es el mismo que evalúa la puerta constitucional de `/assign`.

* **Autenticación:** Token interno con rol `COORDINATOR` (`401 Unauthorized` sin token o con token inválido; `403 Forbidden` con otro rol).
* **Identificador:** admite el ID primario (entero positivo) o el código de ticket (`INC-2026-0001`, con `#` opcional), igual que la ficha integral (apartado 4.4).
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
        "zone_name": "Barcelona Litoral y Norte",
        "latitude": 41.385,
        "longitude": 2.196
      }
    },
    "recommendation": {
      "top_technician_id": 7,
      "is_cold_chain_escalation": true,
      "unattended_zone": false,
      "explanation": "🚨 Prioridad Sanitaria Art. II: ningún titular activo de la zona está libre de paradas previas; se antepone al técnico de guardia 24/7 Marta Guardia para asegurar el SLA < 4 h."
    },
    "ranked_technicians": [
      {
        "technician_id": 7,
        "name": "Marta Guardia",
        "operator_code": "OP-774",
        "is_on_call": true,
        "affinity_type": "NONE",
        "zone_affinity_label": "Sin zona asignada",
        "total_score": 80,
        "score_breakdown": {
          "territorial_affinity": 0,
          "onsite_presence": 0,
          "same_zone_stops": 0,
          "active_workload_penalty": -5,
          "inter_location_penalty": -15,
          "cold_chain_on_call_bonus": 100
        },
        "active_workload": { "active_tickets_count": 1, "pending_info_count": 0, "preventive_orders_count": 0 },
        "current_status": { "has_in_progress_stop": true, "in_progress_location_name": "Sede Sur", "distance_km": 2.78 },
        "is_top_suggestion": true,
        "badges": ["RECOMENDADO", "GUARDIA_24_7", "EN_CURSO_OTRA_SEDE"]
      }
    ]
  }
}
```
* **Reglas de contenido:**
  * `ranked_technicians` sólo incluye usuarios con rol `TECHNICIAN`, activos y no borrados (RF-04.1.5, RF-02.2); un técnico de baja no se puede enviar.
  * `recommendation.top_technician_id` coincide siempre con `ranked_technicians[0].technician_id` (identidad contable).
  * El orden de decisión es el del contrato del módulo §4: escalado sanitario del Art. II → zona desatendida (Haversine) → puntuación → mayor tiempo sin asignación formal → menor ID. `distance_km` se mide desde la parada en curso, la última resuelta del día o la Base Central (QA 5); una coordenada inválida computa `999.0` km.
  * `score_breakdown` publica exactamente las seis claves del contrato; `total_score` es su suma, sin segunda fórmula.
  * `is_cold_chain_risk` se deriva de `priority = CRITICAL` o de máquina `PERISHABLE_FOOD`/`COMBO`.
* **Errores:** `400 INVALID_INCIDENT_IDENTIFIER` si el identificador no es válido; `401 UNAUTHORIZED` / `403 FORBIDDEN`; `404 INCIDENT_NOT_FOUND` si el expediente no existe o está borrado lógicamente.
* **Privacidad (Art. V.4):** el bloque de sede publica `zone_id` y `zone_name` **sólo** a Coordinación; el portal de sede no consume este endpoint.
* **Rendimiento (RNF-01):** la foto se compone en cinco consultas fijas, independientes del número de técnicos, y el ranking es determinista: veinte repeticiones sobre el mismo estado devuelven el mismo orden con mediana por debajo de 100 ms (medida en `SuggestedTechniciansApiTest`).


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
  * `sla`: objetivo de cadena de frío (`has_sla_limit`, `sla_limit_hours`, `is_active_countdown`, `is_frozen` — el reloj contractual está detenido en la espera de sede (RF-03.2) —, `frozen_at` — instante congelado —, `is_breached`, `minutes_remaining`, `historical_balance`, `sla_target_at`).
  * `technical_intervention`: `pause`, `resolution` (diagnóstico, acción correctiva y piezas sustituidas con coste congelado) y `cancellation`.
    * `pause` publica la espera de repuestos (RF-04.2) y la espera de sede (RF-03.2, RF-04.2) en un único bloque estable: `is_paused` (cualquiera de las dos paradas), `is_sla_paused` (la contractual, con el reloj congelado), `paused_at`, `paused_minutes` (intervalo vivo), `accumulated_pause_minutes` (minutos ya descontados), `is_prolonged_inactivity`, `inactivity_threshold_business_hours` (72), `sla_target_frozen_at`, `sla_target_recalculated_at`, `can_resume_pause` (habilitada con la pausa viva) y `can_cancel_inactivity` (las dos acciones supervisadas de la ficha, RF-04.3 y RF-06.2), `reason_category`, `reason_category_label`, `reason_text`, `reason` (motivo de la pausa por repuestos) y `requested_parts`.
    * **Qué mide `sla_target_recalculated_at` (2026-10-10, cierre de F-3).** Es el vencimiento congelado desplazado por el **tiempo hábil realmente perdido en el intervalo vivo**, calculado con el mismo punto de medida que grabará la reanudación (`IncidentPauseService::pauseBusinessSecondsSince()`): la ficha no puede ofrecer una fecha que el cierre real no vaya a escribir. El acumulador de pausas cerradas (`accumulated_pause_minutes`, en segundos de pared) no entra en esta proyección, porque ya está incorporado al vencimiento vigente —cada reanudación desplazó el suyo—; reintegrarlo duplicaba la espera. Una pausa que transcurre entera fuera de la jornada (noche o fin de semana) deja la fecha exactamente donde estaba, que es lo que fija la nota de contrato de RF-03.3.
    * **Una sola regla para la acción supervisada de cierre (2026-10-10, cierre de F-2).** `can_cancel_inactivity` exige pausa viva **y** haber superado el umbral de 72 h hábiles: es exactamente la misma medición que enciende `is_prolonged_inactivity` y la que admite la escritura en `POST /api/coordinator/incidents/{id}/cancel-inactivity` (RF-04.3). La fila de triaje ya gateaba su atajo con `is_prolonged_inactivity`; publicar aquí `true` desde el primer segundo de pausa ofrecía en la ficha un cierre que el servidor rechaza con `422 INACTIVITY_THRESHOLD_NOT_REACHED`. Bajo el umbral la ficha no ofrece la acción y explica la frontera con la etiqueta `pause-inactivity-threshold-pending`.
    * **El destino de la reanudación viaja con la responsabilidad (2026-10-11, cierre de F-5).** `can_resume_pause` sigue dependiendo sólo de la pausa viva —una avería sin técnico también debe poder salir de la pausa—, pero la ficha elige el destino con `technician.assigned`: con responsable reanuda a `ASSIGNED` (RF-06.2); sin él envía `REGISTERED`, que devuelve la avería a la cola de asignación en lugar de declarar «Asignada» sin nadie. El agregado `Incident::resumePendingInfo()` impone la coherencia en todas las puertas: rechaza `ASSIGNED`/`IN_PROGRESS` sin técnico y `REGISTERED` con él, de modo que ninguna reanudación manual —la del técnico o la del coordinador— puede dejar el expediente afirmando un responsable inexistente (la reactivación automática de sede quedó derogada en la enmienda del 2026-10-11).
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
  * **Doble reloj de la pausa de sede (T-PAUSE-19, RF-03.2, RF-03.3).** Con la avería esperando a la sede (`PENDING_INFO` con marca de pausa viva) el objetivo de cadena de frío **no sigue consumiéndose**: el bloque `sla` publica el instante congelado (`is_frozen`, `frozen_at`) y la cuenta atrás tal y como estaba al pausar, mientras el bloque `pause` publica la fecha contractual vigente en ese momento (`sla_target_frozen_at`) y la **recalculada** (`sla_target_recalculated_at`), desplazada en horario comercial 08:00-18:00 de lunes a viernes por los segundos de pausa ya acumulados más el intervalo vivo (Algoritmo 3 del plan del módulo 11). Ambas fechas viajan a `null` cuando la máquina no tiene objetivo de frío o cuando no hay pausa contractual viva. El reloj biológico sanitario (cuarentena de perecederos, Art. II) es una lectura independiente y no se detiene.

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
      "pending_info_reason_text": null,
      "site_response_since_pause": false
    }
  ]
}
```

* **Extensión del Módulo 11 (T-PAUSE-18, RF-05.5):** la ruta incluye también las averías en `PENDING_INFO` (pausa por bloqueo imputable a la sede). Una parada pausada no desaparece de "Mi Ruta": viaja con `paused_at` (marca del intervalo vivo), `total_pending_info_seconds` (acumulado exacto en segundos, RNF-01), `pending_info_reason_category`, `pending_info_reason_category_label` y `pending_info_reason_text`, de modo que la tarjeta móvil pinte la insignia `⏸️ En espera de sede` con su contador , ofrezca la reanudación in situ (`POST /api/technician/incidents/{id}/resume-pending-info`) y publique la marca `site_response_since_pause` (RF-05.6, enmienda del 2026-10-11): `true` cuando el responsable de sede publicó un mensaje con posterioridad al inicio de la pausa vigente —para que la tarjeta muestre el aviso *"✉️ La sede ha respondido · pendiente de tu reanudación"*—; el mensaje de la sede informa, nunca transiciona el estado. **Autoría declarada:** el servidor identifica a la sede por el rol `SITE_MANAGER` de su evento inmutable `INCIDENT_COMMENT_ADDED` en `audit_log`, y no por `incident_comments.author_type`: esa columna etiqueta igual al responsable de sede y al informador ciudadano que avisa por QR (`REPORTER` con `user_id` nulo en ambos casos), de modo que un aviso ciudadano llegado en carrera (EARS 4.5) encendería el aviso de sede. El límite temporal es `>=` sobre `incidents.paused_at`, que sólo tiene resolución de segundo. El resto de campos de pausa viajan a `null` cuando el expediente no está pausado.
* **Serialización de las reanudaciones (T-PAUSE-30, caso límite §6.2):** las dos vías que cierran una pausa —la reanudación manual del técnico in situ y la del coordinador desde central; la reactivación automática por comentario de sede quedó derogada en la enmienda del 2026-10-11— leen el expediente bajo bloqueo exclusivo por fila (`SELECT ... FOR UPDATE`) dentro de su transacción. Si las dos llegan a la vez sobre el mismo ticket, la segunda espera el bloqueo y, al entrar, decide sobre el estado ya confirmado por la primera: **una sola transición y un solo rastro inmutable sobreviven** a la carrera, sin doble descuento del reloj contractual ni estados inconsistentes. El bloqueo es de una fila: el resto del parque sigue operando con normalidad.

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

#### 5.4.1 Bloque sanitario obligatorio del cierre (RF-03.5.2 · Art. II)

Sobre una máquina **bajo vigilancia sanitaria** —alimentos perecederos (`PERISHABLE_FOOD`) y mixtas (`COMBO`), que es lo que devuelve `MachineType::requiresSanitaryWatch()`— el cierre técnico exige, además del diagnóstico y la acción correctiva, declarar qué se ha hecho con el producto que perdió la cadena de frío. La puerta se impone en `TechnicianController::resolveIncident()` **antes de cualquier escritura** del cierre (el dictamen de saldo incluido) y la evalúa `ResolutionValidator::getSanitaryDeclarationErrors()`.

**Cuándo es exigible.** El bloque es obligatorio cuando se cumple cualquiera de estas dos condiciones:

1. La máquina está en cuarentena sanitaria (`machines.sanitary_status = 'QUARANTINE'`), venga del Reloj Sanitario Biológico o de una decisión del módulo 05.
2. La máquina es de vigilancia sanitaria, el expediente no está cerrado y han transcurrido **≥ 4 horas naturales** (14.400 s) desde su apertura. El reloj se consume aquí de forma idempotente (`IncidentPauseService::evaluateSanitaryBiologicalClock()`): si vence en este intento, la máquina pasa a `QUARANTINE` y queda el evento inmutable `SANITARY_QUARANTINE_AUTO_TRIGGERED` con su umbral y el actor `SYSTEM`.

En snacks, bebidas frías o calientes y en cualquier otra tipología el bloque no es exigible, y aun así se valida si viaja: un contrato no acepta declaraciones mal formadas.

**Campo de petición `sanitary_declarations` (objeto; obligatorio cuando la puerta está activa):**

| Campo | Tipo | Obligatorio | Regla |
| :--- | :--- | :--- | :--- |
| `temperature_c` | número | Sí | Lectura real del recinto térmico, dentro del rango físicamente plausible **[-40,0, 80,0] °C** (`ResolutionValidator::MIN_PLAUSIBLE_TEMPERATURE_C` y `MAX_PLAUSIBLE_TEMPERATURE_C`). Ausente, no numérica o increíble (p. ej. `120`) ⇒ rechazo. |
| `stock_destroyed` | booleano | Sí | Confirmación de la retirada y destrucción del stock perecedero deteriorado por la rotura de frío. Debe llegar **marcado**: un `false` explícito se rechaza con el mismo rigor que una omisión. |
| `hygiene_checklist` | booleano | Sí | Confirmación de la ejecución del checklist de higienización sanitaria. Mismas reglas que el anterior. |

Petición completa de un cierre sobre máquina en cuarentena:

```json
{
  "resolution_diagnosis": "Rotura de frío por compresor agarrotado en la cámara de perecederos.",
  "resolution_action": "Sustituido el relé de arranque, verificado el ciclo de frío y anotada la temperatura de la sonda.",
  "replaced_parts_declared": true,
  "replaced_parts": [
    { "spare_part_id": 7, "quantity": 1, "old_part_destination": "DESGUACE" }
  ],
  "sanitary_declarations": {
    "temperature_c": 3.5,
    "stock_destroyed": true,
    "hygiene_checklist": true
  }
}
```

* **Respuesta Exitosa (`200 OK`):** además de los campos ya documentados en 5.4, el cierre devuelve el bloque congelado, con la temperatura redondeada a dos decimales:

```json
{
  "success": true,
  "data": {
    "id": 14,
    "ticket_code": "INC-2026-0014",
    "status": "RESOLVED",
    "resolved_at": "2026-10-12 11:20:00",
    "replaced_parts_count": 1,
    "total_parts_cost": 18.4,
    "sanitary_declarations": {
      "temperature_c": 3.5,
      "stock_destroyed": true,
      "hygiene_checklist": true
    }
  },
  "message": "Incidencia resuelta con registro de repuestos."
}
```

* **Errores de Validación / Negocio:**
  * `422 Unprocessable` (`SANITARY_QUARANTINE_OVERTAKEN`): **cuarentena biológica sobrevenida** (RF-10, EARS-10.1, Art. II y Art. III). La máquina ya estaba en `QUARANTINE` cuando llegó esta resolución —la declaró la central mientras el técnico trabajaba— y la petición no trae el protocolo sanitario: el formulario de campo se abrió cuando la unidad aún estaba en `OK`, de modo que el técnico no está omitiendo nada. El cierre se **suspende**, el parte redactado se guarda como evento inmutable sobre el expediente y se le pide el protocolo de higienización sin borrar una sola letra:

```json
{
  "success": false,
  "error": {
    "code": "SANITARY_QUARANTINE_OVERTAKEN",
    "message": "La máquina entró en CUARENTENA sanitaria automática a las 4 h naturales (Art. II) mientras esta intervención estaba en curso: la avería NO se ha cerrado y el informe ya redactado —diagnóstico, acción y repuestos— queda salvaguardado. Complete el protocolo obligatorio de higienización —temperatura real del recinto, retirada y destrucción del stock perecedero y checklist de higienización— para consolidar el cierre sin volver a redactar los datos técnicos.",
    "details": {
      "draft_safeguarded": true,
      "safeguarded_fields": ["diagnosis", "action_taken", "replaced_parts_declared", "replaced_parts"],
      "errors": [
        "Debe registrar las tres declaraciones sanitarias que exige el Art. II antes de cerrar una avería sobre una máquina en cuarentena: temperatura real del recinto térmico, retirada y destrucción del stock perecedero deteriorado, y ejecución del checklist de higienización."
      ]
    }
  }
}
```

    * **Qué queda salvaguardado:** exactamente los campos de la lista blanca del resguardo —`diagnosis`, `action_taken`, `replaced_parts_declared`, `replaced_parts`, `refund_inspection` y `unclaimed_cash_found`— que la petición declare; `safeguarded_fields` enumera los que se guardaron de verdad. El listado se guarda en el estado del evento inmutable de auditoría (`action = SANITARY_QUARANTINE_REPORT_SAFEGUARDED`, `entity_type = TICKET`), **sin transicionar el expediente**: sigue en `IN_PROGRESS`.
    * **Idempotencia:** reintentar el mismo formulario responde igual y **no** añade un segundo evento. El parte se guarda una vez por contenido, de modo que quince reintentos sin cobertura no ensucian la auditoría.
    * **Recuperación (EARS-10.2):** cuando el técnico vuelve con el protocolo sanitario firmado —aunque mande **sólo** el bloque `sanitary_declarations`— el cierre se completa con el parte a resguardo: recupera los textos y las piezas que ya había escrito, y lo que la petición **sí** traiga gana siempre sobre el resguardo (la palabra más reciente de quien está delante de la máquina).
    * **Distinción declarada:** con el protocolo presente —aunque venga incompleto— el rechazo es `SANITARY_CHECKLIST_REQUIRED` y no hay resguardo: si el técnico manda el bloque, sabe qué le piden y lo que necesita es el detalle campo a campo.
  * `422 Unprocessable` (`SANITARY_CHECKLIST_REQUIRED`): el cierre llega sin algún campo del bloque o con una declaración inválida **cuando la puerta estaba activa**. El `message` enumera los motivos y `details.errors` los repite uno a uno, en el mismo orden en que los produce el validador; el expediente y la máquina quedan **intactos** (ni una columna escrita, ni rastro de dinero):

```json
{
  "success": false,
  "error": {
    "code": "SANITARY_CHECKLIST_REQUIRED",
    "message": "La máquina está fuera de servicio por control higiénico-sanitario (Art. II): Debe confirmar la retirada y destrucción del stock perecedero deteriorado por la rotura de frío (campo stock_destroyed).",
    "details": {
      "errors": [
        "Debe declarar la temperatura real del recinto térmico en grados Celsius (campo temperature_c).",
        "Debe confirmar la retirada y destrucción del stock perecedero deteriorado por la rotura de frío (campo stock_destroyed).",
        "Debe confirmar la ejecución del checklist de higienización sanitaria (campo hygiene_checklist)."
      ]
    }
  }
}
```

  * El catálogo de mensajes es literalmente el del validador de dominio: `Debe declarar la temperatura real del recinto térmico en grados Celsius (campo temperature_c).`, `La temperatura declarada (120.0 °C) queda fuera del rango físicamente plausible [-40.0, 80.0] °C; registre la lectura real del termómetro.`, `Debe confirmar la retirada y destrucción del stock perecedero deteriorado por la rotura de frío (campo stock_destroyed).` y `Debe confirmar la ejecución del checklist de higienización sanitaria (campo hygiene_checklist).` Si el bloque falta por completo, la lista trae **un único** mensaje que pide las tres declaraciones juntas.
  * `422 Unprocessable` (`INVALID_RESOLUTION`): el bloque viaja en un cierre donde no era exigible y su contenido no es válido, o `sanitary_declarations` no es un objeto. `details.errors` lleva la lista del validador igual que en el caso anterior.

* **Efectos en el expediente y en la máquina:**
  * Declarar **no desbloquea** la máquina: sigue en `QUARANTINE` y sólo la reinspección reglamentaria del módulo 05 (temperatura ≤ 4,0 °C más checklist de higienización) la devuelve a `OK`.
  * El cierre queda congelado en la auditoría inmutable (Art. III): el evento `RESOLVE_INCIDENT` guarda las declaraciones en su estado resultante y marca `sanitary_declarations_recorded: true` en sus metadatos, con el actor técnico.

* **CERRADO (2026-10-12, T-PAUSE-38): la activación automática ya no espera al intento de cierre.** El reloj biológico estrena un **segundo consumidor de fondo** —el barrido periódico `POST /api/cron/sanitary-clock-sweep` (§6.2), con su equivalente `bin/sanitary_clock_sweep.php`— que evalúa las máquinas bajo vigilancia cada **15 minutos** sin que nadie intente cerrar la avería: la máquina se publica bloqueada en el portal de sede y en el escaneo ciudadano en cuanto el umbral de 4 h naturales se supera. La medición de antes del arreglo —18.000 s naturales sin interacción ⇒ `sanitary_status = OK` y el escaneo ciudadano en `ACTIVE_INCIDENT`— queda sustituida por una **latencia acotada de hasta 15 minutos** (criterio de contrato de RF-03.5 en `specs/functional/pending_info_sla_pause_spec.md`). Este cierre técnico (§5.4.1) sigue consumiendo el mismo reloj como puerta del formulario, y la transición sanitaria es idempotente: dos consumidores simultáneos sobre la misma máquina dejan una sola cuarentena y **un solo** evento `SANITARY_QUARANTINE_AUTO_TRIGGERED` (Art. III).
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
### 6.2 `POST /api/cron/sanitary-clock-sweep` (Barrido del Reloj Sanitario Biológico · T-PAUSE-38)

Ejecutado periódicamente por el sistema —cadencia ratificada: **15 minutos**— para consumir el Reloj
Sanitario Biológico **sin que nadie intente cerrar la avería** y activar la cuarentena de las máquinas bajo
vigilancia sanitaria (`PERISHABLE_FOOD` y `COMBO`) con más de 4 h naturales sin frío confirmado
(RF-03.5.1, RF-03.4, Art. II). Es el consumidor que materializa la activación automática: entre el umbral
y la pasada siguiente la latencia queda acotada en «4 h naturales + hasta 15 minutos».

* **Autenticación:** `CronAuthMiddleware`, con las mismas credenciales que §6.1 —cabecera `X-Cron-Secret`, `Authorization: Bearer <CRON_SECRET>` o sesión de `COORDINATOR`— y el controlador es **fail-closed**: sin la marca `cron_authenticated` responde `401` aunque el secreto sea válido.
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "candidates": 3,
    "evaluated": 1,
    "quarantined": [{"incident_id": 41, "ticket_code": "INC-2026-000041", "machine_id": 7}],
    "skipped": 0,
    "failures": [{"incident_id": 44, "ticket_code": "INC-2026-000044", "reason": "..."}],
    "failures_count": 1,
    "contended": [{"incident_id": 46, "ticket_code": "INC-2026-000046", "reason": "SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout"}],
    "contended_count": 1,
    "threshold_seconds": 14400,
    "duration_ms": 12.4
  }
}
```
* **Semántica de las claves:** `candidates` son los expedientes que publicó el prefiltro del repositorio (capacidad y economía de consulta, **no** decisión); `evaluated` son los que el reloj evaluó de verdad; `quarantined` son las máquinas que quedan en cuarentena tras la pasada —el disparo concreto queda sellado como evento `SANITARY_QUARANTINE_AUTO_TRIGGERED` en la bitácora inmutable, que es la fuente de verdad de qué máquina entró y cuándo—; `skipped` son los evaluados que no requieren cuarentena (umbral no alcanzado en la relectura del reloj o expediente liquidado en carrera); `failures`/`failures_count` son los candidatos cuya evaluación no pudo decidirse (tipología o apertura ilegibles) o cuya escritura no se pudo persistir; y `contended`/`contended_count` son los candidatos **aplazados por contención de fila**, es decir, cuya transición murió esperando el bloqueo exclusivo que otro consumidor sostiene sobre la misma máquina (`55P03 lock_not_available`, `40P01 deadlock_detected` o `40001 serialization_failure`, que es como el motor único declara esa espera).
* **Contención de fila ≠ fallo (distinción de contrato):** la contención es la prueba de que **otro escritor está actuando sobre esa máquina ahora mismo** —el cierre técnico con su compare-and-set y su evento de auditoría (certificado con dos conexiones PDO reales por `SanitaryClockConsumerRaceTest`)—, no un error del proceso. Por eso viaja en su propia clave y **no alerta**: el resumen la publica para que el planificador la vea y el reintento de la pasada siguiente la resuelva, mientras que `failures_count > 0` sigue siendo la única señal de alerta. La clasificación es **estricta y fail-safe**: sólo se marca contención si el **código estructurado** del error es de bloqueo —el `SQLSTATE` del motor único ∈ {`40001`, `40P01`, `55P03`}, que es la forma en que el motor declara la serialización rota, el interbloqueo resuelto y la espera agotada—; cualquier otro error de persistencia —incluido uno con texto similar— se clasifica como **fallo real**, y la regla se lee del código, nunca del mensaje. La implementación conserva además los códigos de driver del motor retirado (`1205`, `1213`) como tolerancia declarada, con dueño **T-13-14**: esa rama muere con la factoría de una sola rama, no con este contrato.
* **Identidad contable del resumen (Fail-Fast):** `candidates = evaluated + failures_count + contended_count`. El DTO rechaza (`InvalidArgumentException`) cualquier resumen que no cuadre, de modo que un recuento incoherente no puede llegar al planificador como si fuera una pasada válida.
* **Cota de espera de bloqueo de la pasada (T-PAUSE-40):** durante la pasada, la sesión del barrido usa una **cota de espera de bloqueo de 2 s** (`SanitaryClockSweepService::LOCK_WAIT_BUDGET_SECONDS`), muy por debajo del valor de servidor (50 s), y la **restituye** al terminar —sin elevar nunca una cota ya más estricta—. La justificación es medida, no elegida a ojo: la transacción ganadora del cierre (la que sostiene la fila) tarda **p50 1,68 ms · p95 2,54 ms · máx 3,97 ms** sobre **120 ejecuciones reales** del camino de producción, así que la cota de 2 s es ~787× el p95 y un tenedor legítimo sigue resolviéndose **esperando**; lo que cambia es que un tenedor que se alarga deja de bloquear la pasada (hasta 50 s por fila) y se convierte en un **aplazamiento visible** (`contended`). Peor caso declarado de la pasada: `candidates × cota` (500 × 2 s ≈ 16,7 min teóricos, frente a ~7 h con el valor de servidor), con la regla de escalado de la contención avisando mucho antes.
* **Respuesta de Error (`401 Unauthorized`):** credencial ausente o inválida, o controlador invocado sin pasar por el middleware (código `UNAUTHORIZED`).
* **Efectos y garantías:** la transición del estado sanitario es **idempotente (compare-and-set)** y cada candidato se evalúa dentro de su propia unidad de trabajo, de modo que el estado y su evidencia inmutable caen juntos (Art. III) y dos consumidores simultáneos —este barrido y la puerta sanitaria del cierre (§5.4.1)— dejan una sola cuarentena y un solo evento (exclusión mutua certificada con **dos conexiones PDO reales** por `SanitaryClockConsumerRaceTest`: carrera en los dos sentidos, contención por fila con `55P03` y un solo rastro por máquina). Un fallo de una máquina no aborta el lote: viaja en `failures` y el equivalente de línea de comandos (`bin/sanitary_clock_sweep.php`) termina con código distinto de cero para que el planificador pueda alertar.
* **Regla de alerta (una sola en el código):** `SanitaryClockSweepResultDto::requiresOperatorAttention()` es la única autoridad sobre qué enciende el planificador —`true` sólo con fallos reales—, y el CLI deriva de ella su código de salida (`0` sin fallos reales, `1` con ellos). La contención **no** enciende esa alerta, persista o no: su escalado vive en la superficie de §6.3, que es donde alguien puede actuar sobre él (decisión D4 de `docs/propuesta_politica_reintento_barrido_sanitario.md`, ratificada en su recomendación).
* **Libro de la pasada (T-PAUSE-41):** cada pasada queda registrada con su apertura, su cierre, sus recuentos y su duración, y cada candidato aplazado abre un seguimiento por expediente con sus **pasadas consecutivas**. El registro se escribe **después** de consumir el reloj: la observabilidad nunca puede impedir una cuarentena (Art. II). Si el libro no se puede escribir, la pasada falla en voz alta (`failures`/`500`/`exit 1` según el canal) y las cuarentenas ya confirmadas quedan firmes e idempotentes.
* **Equivalente de línea de comandos:** `php bin/sanitary_clock_sweep.php` (mismos datos y mismos códigos de salida) para programadores que no disponen de HTTP.

### 6.3 `GET /api/coordinator/sanitary-sweep/contention` (Superficie Operativa del Barrido · T-PAUSE-41)

Superficie de **sólo lectura** de la contención del barrido de §6.2 (RF-07): responde qué pasadas se han registrado, cuánta contención hubo en las últimas 24 h y qué expedientes siguen **aplazados**, con la alerta de escalado encendida cuando el aplazamiento persiste.

* **Seguridad:** `InternalAuthMiddleware(UserRole::COORDINATOR)`. Es instrumentación interna —identifica máquinas, sedes y expedientes frente a un escritor que retiene filas— y **no** se publica en el portal de sede ni en el escaneo ciudadano (Art. V.4).
* **Respuesta Exitosa (`200 OK`):**
```json
{
  "success": true,
  "data": {
    "generated_at": "2026-10-12 18:20:00",
    "cadence_minutes": 15,
    "escalation": {
      "level": "ESCALATED",
      "requires_attention": true,
      "consecutive_passes_threshold": 3,
      "per_pass_threshold": 5,
      "open_contentions": 2,
      "escalated_contentions": 1,
      "level_label": "Contención persistente: la detección sanitaria se está retrasando"
    },
    "sweep": {
      "last_pass": {
        "pass_id": 412,
        "started_at": "2026-10-12 18:15:00",
        "finished_at": "2026-10-12 18:15:01",
        "candidates": 4,
        "evaluated": 2,
        "quarantined_count": 1,
        "contended_count": 2,
        "failures_count": 0,
        "duration_ms": 812.34
      },
      "minutes_since_last_pass": 5,
      "is_stale": false,
      "window_hours": 24,
      "passes_in_window": 96,
      "passes_with_contention": 3,
      "deferrals_in_window": 7,
      "failures_in_window": 0,
      "quarantines_in_window": 31,
      "contention_rate_percent": 3.13
    },
    "contentions": [
      {
        "id": 9,
        "incident_id": 142,
        "ticket_code": "INC-2026-00142",
        "machine_id": 14,
        "machine_code": "VEND-0101",
        "location_name": "Hospital del Mar",
        "floor_wing": "Planta Baja - Urgencias",
        "incident_status": "IN_PROGRESS",
        "machine_sanitary_status": "OK",
        "first_detected_at": "2026-10-12 17:15:00",
        "last_detected_at": "2026-10-12 18:15:00",
        "consecutive_passes": 5,
        "total_passes": 5,
        "escalated": true,
        "escalated_at": "2026-10-12 17:45:00",
        "age_minutes": 65,
        "minutes_since_last_detection": 5
      }
    ]
  }
}
```
* **Semántica de las claves:** `escalation.level` vale **`CLEAR`** (sin seguimientos abiertos), **`MONITORED`** (hay aplazamientos vivos, ninguno persistente) o **`ESCALATED`** (algún expediente alcanzó el umbral de pasadas consecutivas, o la última pasada superó el umbral de parque de 5 aplazamientos). `requires_attention` es la **única autoridad** de la alerta. `sweep.last_pass` es la última pasada del libro; `minutes_since_last_pass` es `null` cuando nunca se ha registrado una pasada y `is_stale` se enciende cuando han transcurrido más de **dos cadencias** sin pasada, porque *el barrido no está corriendo* es una situación distinta de *no encuentra nada*. `sweep.window` agrega las últimas 24 h del libro (pasadas, pasadas con aplazamiento, aplazamientos, fallos reales, cuarentenas y la tasa de contención) para poder ver **tendencia** antes de que escale. `contentions` lista los seguimientos **abiertos** —los que una pasada posterior ya no volvió a aplazar quedan cerrados y no viajan—, ordenados por escalado y antigüedad, con el expediente, la máquina, la sede, la planta, el estado del expediente, el estado sanitario de la máquina y las pasadas consecutivas.
* **Auto-resolución:** el escalado **no** exige acuse de recibo ni se apaga a mano: la primera pasada que ya no aplaza al expediente cierra su seguimiento y la alerta desaparece sola, conservando el rastro en el libro (Art. III). El acuse explícito del coordinador queda fuera de alcance de este incremento.
* **Respuesta de Error:** `401 Unauthorized` (`UNAUTHORIZED`) sin credencial válida, `403 Forbidden` (`FORBIDDEN`) con un token de rol distinto de `COORDINATOR` y `500 Internal Server Error` (`SWEEP_VISIBILITY_UNAVAILABLE`) si el libro de pasadas no se puede leer. Este último describe **sólo** un fallo de lectura de la superficie: la salvaguarda sanitaria no está afectada —el barrido sigue evaluando candidatos y activando cuarentenas con el mismo reloj—, y así viaja en su propio mensaje para que nadie confunda un panel vacío con un parque sin vigilancia.
