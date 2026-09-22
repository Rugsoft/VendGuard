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

### 4.2 `PATCH /api/coordinator/incidents/{id}/assign` (Asignar Técnico)
Asocia un técnico de campo único a la incidencia (RF-05).

* **Request Body:**
```json
{
  "technician_id": 2,
  "urgency_override": "MEDIUM",
  "urgency_override_reason": "Comprobado que la máquina no contiene comida perecedera en esta temporada."
}
```
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

---

### 4.3 `PATCH /api/coordinator/incidents/{id}/cancel` (Descartar Aviso)
Anulación lógica obligatoriamente justificada (RF-06).

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
