# ESPECIFICACIÓN TÉCNICA · CONTRATOS DE API PARA CÓDIGOS QR
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Documento:** `specs/technical/qr_codes_contracts.md`  
**Referencia Funcional:** `specs/functional/qr_codes_spec.md` (RF-01 a RF-05)  
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`) y SVG vectorial (`image/svg+xml`)  
**Metodología:** SDD (Specification-Driven Development) · Contratos API-First  

---

## 1. Estructura del Enlace Codificado en el Código QR

### 1.1 Formato de la URL de Escaneo (Deep Link)
Cada código QR físico contiene una dirección web única normalizada:

```text
{APP_BASE_URL}/?qr={machine_code}&site={site_code}
```

* **Ejemplo Real:** `https://vendguard.onrender.com/?qr=VEND-0101&site=SEDE-BCN-01`
* **Parámetros:**
  * `qr` (obligatorio): Código único alfanumérico de la máquina (`machines.code`).
  * `site` (opcional/contextual): Código de la sede (`locations.site_code`). Si la máquina fue reubicada, la base de datos central prevalece sobre este parámetro (`RF-05, EARS 5.1`).

---

## 2. Catálogo de Endpoints del Módulo QR

| Método | Endpoint | Rol / Acceso | Propósito |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/qr/scan/{code}` | Público | Resuelve el estado de la máquina tras el escaneo móvil y determina el modo de visualización |
| `POST`| `/api/qr/report` | Público (Efímero) | Registra el reporte de avería desde el flujo QR gestionando concurrencia atómica |
| `GET` | `/api/coordinator/machines/{id}/qr-label` | Coordinador | Obtiene los datos y el SVG vectorial de la etiqueta adhesiva individual |
| `GET` | `/api/coordinator/locations/{id}/qr-batch`| Coordinador | Obtiene el conjunto de etiquetas de una sede para la hoja de impresión A4 |

---

## 3. Especificación Detallada de Contratos

### 3.1 `GET /api/qr/scan/{code}` (Resolución de Escaneo de Máquina)
Invocado automáticamente por la aplicación web en cuanto el usuario aterriza con un parámetro `?qr={code}`.

* **Parámetro de ruta:**
  * `code`: Código alfanumérico de la máquina (ej: `VEND-0101`).
* **Cabeceras:**
  * `Accept: application/json`

#### Caso A: Máquina sin avería activa (`200 OK` - Formulario Limpio)
Permite abrir de inmediato el formulario de nueva avería (`RF-03, EARS 3.1`). Si `is_perishable` es `true`, activa el banner sanitario en cabecera (`EARS 3.3`).

```json
{
  "success": true,
  "data": {
    "status_mode": "CAN_REPORT",
    "machine": {
      "id": 1,
      "code": "VEND-0101",
      "model": "Sanden Vendo G-Drink",
      "machine_type": "PERISHABLE_FOOD",
      "floor_wing": "Planta Baja - Urgencias",
      "is_perishable": true,
      "notes": "Máquina de sándwiches y lácteos frescos"
    },
    "location": {
      "id": 1,
      "site_code": "SEDE-BCN-01",
      "name": "Hospital del Mar - Edificio Central",
      "contact_phone": "600111222"
    },
    "active_incident": null
  }
}
```

#### Caso B: Máquina con Avería Activa Abierta (`200 OK` - Privacidad Blindada Art. V.4)
Muestra el panel informativo público del aviso existente e impide duplicados (`RF-04, EARS 4.1`).  
> [!IMPORTANT]
> **Blindaje Constitucional Art. V.4:** Los campos `assigned_technician`, notas internas de taller y costes de repuestos **no existen en este contrato**.

```json
{
  "success": true,
  "data": {
    "status_mode": "ACTIVE_INCIDENT",
    "machine": {
      "id": 1,
      "code": "VEND-0101",
      "model": "Sanden Vendo G-Drink",
      "machine_type": "PERISHABLE_FOOD",
      "floor_wing": "Planta Baja - Urgencias",
      "is_perishable": true,
      "notes": "Máquina de sándwiches y lácteos frescos"
    },
    "location": {
      "id": 1,
      "site_code": "SEDE-BCN-01",
      "name": "Hospital del Mar - Edificio Central",
      "contact_phone": "600111222"
    },
    "active_incident": {
      "ticket_code": "TICK-2026-0001",
      "category": "TEMPERATURE_COLD",
      "public_status": "IN_PROGRESS",
      "status_label": "Técnico interviniendo en la máquina",
      "reported_at": "2026-09-23T07:15:00Z"
    }
  }
}
```

#### Caso C: Máquina con Avería en Garantía de 48h (`200 OK` - Opción de Reapertura)
Aplica cuando la última avería está en `RESOLVED` y aún no han transcurrido 48 horas (`RF-04, EARS 4.3`).

```json
{
  "success": true,
  "data": {
    "status_mode": "UNDER_WARRANTY",
    "machine": {
      "id": 1,
      "code": "VEND-0101",
      "model": "Sanden Vendo G-Drink",
      "machine_type": "PERISHABLE_FOOD",
      "floor_wing": "Planta Baja - Urgencias",
      "is_perishable": true
    },
    "location": {
      "id": 1,
      "site_code": "SEDE-BCN-01",
      "name": "Hospital del Mar - Edificio Central",
      "contact_phone": "600111222"
    },
    "resolved_incident": {
      "ticket_code": "TICK-2026-0001",
      "category": "TEMPERATURE_COLD",
      "resolved_at": "2026-09-22T18:00:00Z",
      "warranty_expires_at": "2026-09-24T18:00:00Z"
    }
  }
}
```

#### Caso D: Máquina No Encontrada o Inactiva (`404 Not Found`)
Cumple con `RF-05, EARS 5.2`:

```json
{
  "success": false,
  "error": {
    "code": "MACHINE_NOT_FOUND_OR_INACTIVE",
    "message": "Máquina no identificada o temporalmente fuera de servicio. Si necesitas asistencia, contacta con el servicio técnico."
  }
}
```

---

### 3.2 `POST /api/qr/report` (Envío de Reporte por QR con Gestión de Concurrencia)
Permite enviar el formulario público de incidencia sin requerir login previo de sede.

* **Cabeceras:**
  * `Content-Type: application/json`
* **Cuerpo de la Petición (`Request Body`):**

```json
{
  "machine_code": "VEND-0101",
  "category": "TEMPERATURE_COLD",
  "description": "La nevera no enfría y los sándwiches están a temperatura ambiente.",
  "reporter_name": "Dra. Laura Sánchez",
  "reporter_phone": "655444333",
  "retained_money_amount": null
}
```

#### Respuesta Exitosa (`201 Created` - Nuevo Ticket Creado):
Cumple con `RF-03, EARS 3.4`:

```json
{
  "success": true,
  "data": {
    "ticket_code": "TICK-2026-0015",
    "status": "REGISTERED",
    "urgency": "CRITICAL",
    "reported_at": "2026-09-23T07:45:00Z",
    "merged": false
  },
  "message": "Incidencia registrada correctamente. Nuestro equipo técnico ha recibido tu aviso."
}
```

#### Respuesta Ante Concurrencia Simultánea (`200 OK` - Anexado Amigable):
Cumple con `RF-04, EARS 4.5`. Si dos usuarios envían casi a la vez, el segundo aviso se anexa automáticamente como comentario de refuerzo sin lanzar un error técnico:

```json
{
  "success": true,
  "data": {
    "ticket_code": "TICK-2026-0014",
    "status": "REGISTERED",
    "urgency": "CRITICAL",
    "reported_at": "2026-09-23T07:44:50Z",
    "merged": true
  },
  "message": "Otro usuario acaba de reportar una avería en esta máquina hace un momento (Ticket #TICK-2026-0014). Hemos registrado tus observaciones en dicho ticket."
}
```

---

### 3.3 `GET /api/coordinator/machines/{id}/qr-label` (Etiqueta Individual)
Invocado desde el panel de coordinación para previsualizar, imprimir o descargar la etiqueta adhesiva de una máquina.

* **Seguridad:** Requiere cabecera `Authorization: Bearer <token_coordinador>`.
* **Parámetro de ruta:** `id` (identificador entero de la máquina).
* **Parámetros de consulta opcionales (`Query Params`):**
  * `phone`: teléfono personalizado para sobreescribir el número de la pegatina (`RF-01, EARS 1.2`).
  * `update_location_phone`: `true` | `false` (si `true`, actualiza el registro maestro de la sede en BD; `RF-01, EARS 1.3`).
  * `format`: `json` (por defecto) o `svg` (descarga directa como archivo `image/svg+xml`).

#### Respuesta en JSON (`200 OK`):
Devuelve los metadatos y el código SVG vectorial completo generado de forma nativa (`RNF-04`):

```json
{
  "success": true,
  "data": {
    "machine": {
      "id": 1,
      "code": "VEND-0101",
      "model": "Sanden Vendo G-Drink",
      "machine_type": "PERISHABLE_FOOD",
      "floor_wing": "Planta Baja - Urgencias"
    },
    "location": {
      "id": 1,
      "site_code": "SEDE-BCN-01",
      "name": "Hospital del Mar - Edificio Central",
      "phone": "600111222"
    },
    "support_phone": "600111222",
    "qr_target_url": "https://vendguard.onrender.com/?qr=VEND-0101&site=SEDE-BCN-01",
    "svg_content": "<svg viewBox=\"0 0 400 600\" xmlns=\"http://www.w3.org/2000/svg\">...</svg>"
  }
}
```

#### Respuesta en Descarga Directa (`format=svg`):
* `Content-Type: image/svg+xml; charset=utf-8`
* `Content-Disposition: attachment; filename="etiqueta-VEND-0101.svg"`

---

### 3.4 `GET /api/coordinator/locations/{id}/qr-batch` (Lote de Etiquetas para Sede)
Genera la lista de etiquetas con código QR para todas las máquinas activas de una sede concreta.

* **Seguridad:** Requiere cabecera `Authorization: Bearer <token_coordinador>`.
* **Parámetro de ruta:** `id` (identificador entero de la sede).

#### Respuesta (`200 OK`):
```json
{
  "success": true,
  "data": {
    "location": {
      "id": 1,
      "site_code": "SEDE-BCN-01",
      "name": "Hospital del Mar - Edificio Central",
      "contact_phone": "600111222"
    },
    "total_machines": 2,
    "items": [
      {
        "machine_id": 1,
        "code": "VEND-0101",
        "model": "Sanden Vendo G-Drink",
        "machine_type": "PERISHABLE_FOOD",
        "floor_wing": "Planta Baja - Urgencias",
        "qr_target_url": "https://vendguard.onrender.com/?qr=VEND-0101&site=SEDE-BCN-01",
        "svg_content": "<svg viewBox=\"0 0 400 600\" ...>...</svg>"
      },
      {
        "machine_id": 2,
        "code": "VEND-0102",
        "model": "Bianchi Gaia Espresso",
        "machine_type": "HOT_DRINKS",
        "floor_wing": "Planta 1 - Sala Médica",
        "qr_target_url": "https://vendguard.onrender.com/?qr=VEND-0102&site=SEDE-BCN-01",
        "svg_content": "<svg viewBox=\"0 0 400 600\" ...>...</svg>"
      }
    ]
  }
}
```

---

## 4. Estándar de Renderizado SVG Nativo (Dogma Vanilla)

Para dar estricto cumplimiento al **Artículo IV (Aversión a dependencias y minimalismo)**, la generación gráfica de los códigos QR y las etiquetas no utilizará librerías pesadas ni servicios de terceros:
1. El backend incorporará un encoder nativo minimalista en PHP puro que genera la matriz binaria del código QR (corrección de errores nivel M) y la convierte en rectángulos `<rect>` vectoriales SVG limpios.
2. La maqueta de la etiqueta adhesiva (400x600 px proporción 2:3) compone de forma vectorial:
   - Cabecera con logo VendGuard y texto *"Soporte Técnico Oficial"*.
   - Código identificador de la máquina en fuente destacada (`VEND-0101`).
   - Módulo QR centralizado de al menos 200x200 px (físicamente >= 40x40 mm).
   - Franja inferior con llamada a la acción y teléfono de asistencia técnica editable.
3. El frontend de coordinación maquetará la hoja A4 de impresión mediante CSS estándar `@media print` con una cuadrícula de 2x2 etiquetas por página y regla `break-inside: avoid; page-break-inside: avoid;`.
