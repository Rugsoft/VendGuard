# CONTRATOS TÉCNICOS: ENCUESTA DE SATISFACCIÓN POST-RESOLUCIÓN (CSAT DE SEDE)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 20 · Encuesta de Satisfacción Post-Resolución (CSAT de Sede)  
**Documento:** `specs/technical/satisfaction_survey_contracts.md`  
**Referencia Funcional:** [`specs/20-csat-satisfaction-survey/spec.md`](../20-csat-satisfaction-survey/spec.md) (v2, aprobada 2026-10-23)  
**Plan:** [`specs/20-csat-satisfaction-survey/plan.md`](../20-csat-satisfaction-survey/plan.md) · **Tareas:** [`tasks.md`](../20-csat-satisfaction-survey/tasks.md)  
**Estado:** Contrato congelado para la fase 3 (tests en rojo).

---

## 1. Convenciones Generales

* Todas las respuestas son `application/json; charset=utf-8`.
* Los errores usan el sobre ya vigente en el proyecto: `{ "error": "CODE", "message": "texto legible" }`.
* Las fechas viajan como cadena tal como las entrega PostgreSQL (`Y-m-d H:i:sP`), igual que el resto de DTOs del proyecto. El frontend las interpreta con `new Date()`.
* Toda escritura ocurre dentro de una transacción de `PDO`; ningún controlador ejecuta SQL.
* El aislamiento de sede lo impone `SiteAuthMiddleware`; ningún endpoint de sede acepta `location_id` por query ni por cuerpo.

---

## 2. Contrato de Esquema

### 2.1. Tabla `incident_satisfaction_surveys` (tabla 28)

| Columna | Tipo | Nulo | Origen / Regla |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT GENERATED ALWAYS AS IDENTITY` | No | PK. |
| `incident_id` | `BIGINT` | No | FK `incidents(id)` `ON DELETE RESTRICT`. |
| `location_id` | `BIGINT` | No | FK `locations(id)`. Copiado de `incidents.location_id` al insertar (RD-11). |
| `technician_id` | `BIGINT` | Sí | FK `users(id)`. Copiado de `incidents.assigned_technician_id`; nulo si no hubo asignación (RD-02). |
| `technician_name_snapshot` | `VARCHAR(100)` | Sí | Nombre vigente del técnico al resolver (R8). Nulo si `technician_id` es nulo. |
| `resolution_at` | `TIMESTAMPTZ` | No | **Copia exacta** de `incidents.resolved_at`. Jamás `now()` (RF-02.6). |
| `rating` | `SMALLINT` | No | `CHECK (rating BETWEEN 1 AND 5)`. |
| `comment` | `VARCHAR(500)` | Sí | `CHECK (comment IS NULL OR char_length(btrim(comment)) BETWEEN 1 AND 500)`. |
| `created_at` | `TIMESTAMPTZ` | No | `DEFAULT CURRENT_TIMESTAMP`. |

### 2.2. Restricciones e Índices

```sql
CONSTRAINT uq_survey_episode UNIQUE (incident_id, resolution_at)
CONSTRAINT chk_survey_rating CHECK (rating BETWEEN 1 AND 5)
CONSTRAINT chk_survey_comment CHECK (comment IS NULL OR (char_length(btrim(comment)) BETWEEN 1 AND 500))

CREATE INDEX idx_surveys_resolution_at ON incident_satisfaction_surveys (resolution_at);
CREATE INDEX idx_surveys_location     ON incident_satisfaction_surveys (location_id, resolution_at);
CREATE INDEX idx_surveys_technician   ON incident_satisfaction_surveys (technician_id, resolution_at);
```

### 2.3. Disparadores

| Disparador | Función | Efecto |
| :--- | :--- | :--- |
| `trg_no_update_incident_satisfaction_surveys` | `prevent_satisfaction_survey_rewrite()` **(nueva)** | `RAISE EXCEPTION … USING ERRCODE = '45000'`. La fila no se reescribe (RD-06). |
| `trg_no_delete_incident_satisfaction_surveys` | `prevent_hard_delete()` (compartida) | Bloquea el borrado salvo ventana de purga `vendguard.purge` del arnés. |

La función de `UPDATE` se declara en la migración con `CREATE OR REPLACE FUNCTION` y su disparador con `DROP TRIGGER IF EXISTS` antes de crearlo, para que una base migrada y una creada desde `supabase_init.sql` queden idénticas (contrato de correspondencia).

### 2.4. Correspondencia y Purga

* Migración: `database/supabase_migrations/011_incident_satisfaction_surveys.sql`.
* Réplica canónica: `database/supabase_init.sql`, sección 7, tabla 28. Al añadirla se corrige la cabecera, que declara «25 tablas» cuando hoy existen 27.
* `specs/technical/schema_correspondence_contract.md` §2 declara la tabla y sus disparadores.
* `tests/Support/TestDataCleaner.php`: la tabla entra en el plan de purga **antes** de `incidents` y `users`.
* Sin semillas (D9).

---

## 3. `GET /api/location/satisfaction-surveys/pending`

**Auth:** `SiteAuthMiddleware` (`Authorization: Bearer <token>`).

### 3.1. Respuesta 200

```json
{
  "items": [
    {
      "incident_id": 1841,
      "ticket_code": "INC-20261022-0007",
      "machine_code": "M-014",
      "machine_model": "Necta Korinto",
      "resolved_at": "2026-10-22 17:04:11+02"
    }
  ],
  "pending_total": 23
}
```

* `items`: como máximo 20, orden `resolved_at DESC, incident_id DESC`.
* `pending_total`: total real de episodios pendientes de la sede, sin tope.
* `machine_model` puede ser `null` si la máquina no lo declara.

### 3.2. Consulta de referencia (semántica, no texto obligatorio)

```sql
SELECT i.id, i.ticket_code, m.code, m.model, i.resolved_at
FROM incidents i
JOIN machines m ON m.id = i.machine_id
LEFT JOIN incident_satisfaction_surveys s
       ON s.incident_id = i.id
      AND s.resolution_at = i.resolved_at
WHERE i.location_id = :location_id
  AND i.status IN ('RESOLVED', 'CLOSED')
  AND i.resolved_at IS NOT NULL
  AND s.id IS NULL
ORDER BY i.resolved_at DESC, i.id DESC
LIMIT 20;
```

### 3.3. Errores

| HTTP | `error` | Causa |
| :--- | :--- | :--- |
| 401 | `UNAUTHORIZED` | Token ausente, inválido o sede dada de baja. |
| 500 | `INTERNAL_SERVER_ERROR` | Fallo de persistencia; sin detalle interno. |

---

## 4. `POST /api/location/incidents/{id}/satisfaction-survey`

**Auth:** `SiteAuthMiddleware`.  
**Cuerpo:**

```json
{ "rating": 5, "comment": "Vinieron el mismo día y dejaron la máquina limpia." }
```

`comment` es opcional y admite `null` o ausencia de clave.

### 4.1. Respuesta 201

```json
{
  "id": 92,
  "incident_id": 1841,
  "ticket_code": "INC-20261022-0007",
  "rating": 5,
  "comment": "Vinieron el mismo día y dejaron la máquina limpia.",
  "created_at": "2026-10-23 09:15:02+02"
}
```

Sin `technician_id` ni nombre de técnico: la respuesta la lee la sede.

### 4.2. Orden de validación (normativo)

| # | Condición | HTTP | `error` |
| :--- | :--- | :--- | :--- |
| 1 | Sin token o token inválido (middleware) | 401 | `UNAUTHORIZED` |
| 2 | `{id}` no numérico o incidencia inexistente | 404 | `INCIDENT_NOT_FOUND` |
| 3 | `incidents.location_id` ≠ sede autenticada | 403 | `SITE_MISMATCH` |
| 4 | `resolved_at` nulo o estado ∉ {`RESOLVED`,`CLOSED`} | 422 | `SURVEY_NOT_AVAILABLE` |
| 5 | `rating` ausente | 400 | `MISSING_RATING` |
| 6 | `rating` no entero 1–5 | 422 | `INVALID_RATING` |
| 7 | `comment` > 500 caracteres tras normalizar, o tipo no admitido | 422 | `COMMENT_TOO_LONG` |
| 8 | Ya existe fila para `(incident_id, resolution_at)` vigente | 409 | `SURVEY_ALREADY_SUBMITTED` |

**Precedencia declarada (R9):** 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8. Las comprobaciones 1–4 no miran el cuerpo. Las 5–7 no consultan duplicados. El duplicado (8) solo se evalúa con carga útil válida.

### 4.3. Reglas de Tipado de `rating` (R9)

| Entrada | Resultado |
| :--- | :--- |
| `5` (int) | Válido. |
| `"5"` (string) | Válido (`/^[1-5]$/` tras `trim`). |
| `" 5 "` | Válido tras `trim`. |
| `4.0` (float) | `422 INVALID_RATING`. |
| `"4.0"` | `422 INVALID_RATING`. |
| `true` / `null` / `[4]` / `{"v":4}` | `422 INVALID_RATING`. |
| `0`, `6`, `-1` | `422 INVALID_RATING`. |

### 4.4. Reglas del Comentario

* Tipos admitidos: `string`, `null`, ausente.
* Normalización: `trim()` de espacios Unicode (`\s` + `\u{00A0}`), saltos de línea y tabulaciones internas permitidos.
* Cadena vacía o solo espacios → `NULL` en base de datos (nunca `''`).
* Longitud: `mb_strlen($comment, 'UTF-8')` ≤ 500.
* Sin saneado HTML; la vista escapa al renderizar.
* Un tipo no admitido (array, objeto, número, booleano) responde `422 COMMENT_TOO_LONG` con `message: "El comentario debe ser texto."` — se mantiene el código de error existente para no ampliar el catálogo.

### 4.5. Secuencia Transaccional (B1)

```
BEGIN
  SELECT id, location_id, status, resolved_at, assigned_technician_id
    FROM incidents WHERE id = :id FOR UPDATE
  ── disponibilidad (4): resolved_at y estado
  ── novelería de errores de cuerpo (5,6,7)
  ── duplicado (8): SELECT 1 FROM surveys WHERE incident_id = :id AND resolution_at = <resolved_at>
  INSERT INTO incident_satisfaction_surveys (…, resolution_at, …) VALUES (…, <resolved_at>, …)
  INSERT INTO audit_log (…, action = 'SUBMIT_SATISFACTION_SURVEY', …)
COMMIT
```

* `resolution_at` es la columna leída bajo bloqueo, nunca una variable previa ni `now()`.
* Si la incidencia se reabre entre la lectura del controlador y el `FOR UPDATE`, la relectura ve `resolved_at = NULL` y responde `422 SURVEY_NOT_AVAILABLE`.
* Si dos peticiones concurrentes superan el duplicado, el índice único decide: la segunda captura la violación y responde `409`, no `500`.

---

## 5. Catálogo de Errores Completo

| `error` | HTTP | Mensaje (`message`) | Notas |
| :--- | :--- | :--- | :--- |
| `UNAUTHORIZED` | 401 | `Sesión de sede no válida.` | Emitido por el middleware. |
| `INCIDENT_NOT_FOUND` | 404 | `La avería solicitada no existe.` | También para `{id}` no numérico. |
| `SITE_MISMATCH` | 403 | `La avería no pertenece a su sede.` | Precedente de `LocationPortalController`. |
| `SURVEY_NOT_AVAILABLE` | 422 | `La avería no está resuelta o ya no admite valoración.` | `resolved_at` nulo o estado no resuelto. |
| `MISSING_RATING` | 400 | `Debe indicar una valoración de 1 a 5 estrellas.` | `rating` ausente. |
| `INVALID_RATING` | 422 | `La valoración debe ser un entero entre 1 y 5.` | Tipo o rango inválido. |
| `COMMENT_TOO_LONG` | 422 | `El comentario no puede superar los 500 caracteres.` / `El comentario debe ser texto.` | Longitud o tipo. |
| `SURVEY_ALREADY_SUBMITTED` | 409 | `Este servicio ya fue valorado.` | Unicidad por episodio. |
| `INVALID_FILTER_PARAMS` | 400 | Mensaje de `MetricFilter`. | Solo en métricas. |
| `INTERNAL_SERVER_ERROR` | 500 | `Se produjo un error interno.` | Sin detalle. |

---

## 6. `GET /api/coordinator/metrics/csat`

**Auth:** `InternalAuthMiddleware(COORDINATOR)`.

### 6.1. Parámetros

| Parámetro | Tipo | Obligatorio | Regla |
| :--- | :--- | :--- | :--- |
| `period` | string | No | `last_7_days`, `current_month`, `last_month`, `last_30_days`, `custom`. Por defecto `last_30_days`. |
| `from` | `YYYY-MM-DD` | Solo con `custom` | Inicio inclusivo, 00:00:00 Europe/Madrid. |
| `to` | `YYYY-MM-DD` | Solo con `custom` | Fin inclusivo, 23:59:59 Europe/Madrid. |
| `location_id` | int | No | Filtra `incident_satisfaction_surveys.location_id`. |
| `technician_id` | int | No | Filtra `technician_id`. |

`MetricFilter::fromQueryParams()` es la única fuente de verdad del rango; el controlador no recalcula fechas.

### 6.2. Respuesta 200

```json
{
  "summary": {
    "average": 4.21,
    "responses": 19,
    "distribution": { "1": 0, "2": 1, "3": 2, "4": 6, "5": 10 }
  },
  "by_site": [
    { "location_id": 3, "site_code": "SEDE-BCN-01", "location_name": "Hospital La Paz", "average": 4.5, "responses": 8 }
  ],
  "by_technician": [
    { "technician_id": 7, "technician_name": "Ana Ruiz", "average": 4.0, "responses": 5 }
  ],
  "monthly_trend": [
    { "month": "2026-09", "average": 4.0, "responses": 6 }
  ]
}
```

### 6.3. Reglas de Cálculo

| Regla | Implementación |
| :--- | :--- |
| Media | `ROUND(AVG(rating)::numeric, 2)`, convertida a `float` en PHP. |
| Sin respuestas | `average: null`, `responses: 0`; el bloque se renderiza vacío. |
| Distribución | Siempre las cinco claves `"1"`…`"5"` como cadenas; `0` cuando no hay casos. |
| Grupos | `by_site` y `by_technician` listan **solo** grupos con ≥ 1 respuesta (R7). |
| Orden `by_site` | `responses DESC`, `location_name ASC`. |
| Orden `by_technician` | `responses DESC`, nombre ASC. |
| Nombre de sede | `locations.name` actual. |
| Nombre de técnico | `technician_name_snapshot` de la encuesta más reciente del grupo (`created_at DESC, id DESC`); `"Sin técnico"` si `technician_id` es nulo (R8). |
| Serie mensual | `TO_CHAR(resolution_at AT TIME ZONE 'Europe/Madrid', 'YYYY-MM')`, ascendente. |
| Imputación | Ninguna: sin valoración no hay fila (RD-07). |
| Técnico inactivo | Se incluye; no se filtra por `users.is_active` (RF-04.6). |

### 6.4. Filtro Temporal

`WHERE resolution_at >= :from AND resolution_at <= :to`, con `:from`/`:to` generados por `MetricFilter`. El periodo se evalúa sobre `resolution_at`, no sobre `created_at`: la encuesta cuenta en el mes en que se resolvió el servicio.

### 6.5. Errores

| HTTP | `error` |
| :--- | :--- |
| 400 | `INVALID_FILTER_PARAMS` |
| 401 | `UNAUTHORIZED` |

---

## 7. Contrato de Auditoría

| Campo | Valor |
| :--- | :--- |
| `action` | `SUBMIT_SATISFACTION_SURVEY` (`VARCHAR(64)`; sin `ALTER TYPE`). |
| `entity_type` | `TICKET` (enum existente). |
| `entity_id` | `incident_id`. |
| `user_id` | `NULL`. |
| `user_role` | `SITE_RESPONSIBLE`. |
| `user_name` | `locations.name` de la sede autenticada. |
| `previous_state` | `NULL`. |
| `new_state` | `{"survey_id": 92, "rating": 5, "resolution_at": "2026-10-22 17:04:11+02", "technician_id": 7}` |
| `metadata` | `{"incident_id": 1841, "location_id": 3, "ticket_code": "INC-20261022-0007"}` |

* Escrito con `AuditLogger::logTicketEvent()` en la misma transacción que la encuesta (RF-03.3).
* `public/assets/js/utils/AuditActionLabels.js` incorpora `SUBMIT_SATISFACTION_SURVEY` en el grupo `ticket` con tono `primary`, o `AuditActionCatalogTest` falla.
* El detalle expuesto en el panel de auditoría no incluye nombre de persona que respondió: solo la sede (R2).

---

## 8. Contrato de Privacidad (Art. V.4)

| Superficie | Puede ver | No puede ver |
| :--- | :--- | :--- |
| Sesión de sede | Sus pendientes (ticket, máquina, fecha). Su propia respuesta recién creada. | Identidad del técnico, encuestas de otras sedes, agregados. |
| Coordinación | Nota, comentario, sede, técnico, agregados, tendencia. | Nombre del respondiente (no existe). |
| Técnico | Nada del módulo. | Nota, comentarios, agregados. |
| API pública / QR | Nada. | Todo. |

* Los endpoints de sede no aceptan `location_id` externo: la sede sale de la sesión.
* `CsatPrivacyConstitutionalTest` verifica que las respuestas de sede no contienen `technician_id` ni `technician_name`, y que una sede no puede leer ni valorar episodios de otra (403).

---

## 9. Invariantes Verificables

1. `resolution_at = incidents.resolved_at` en toda fila (comparación exacta en la suite de esquema).
2. `(incident_id, resolution_at)` único.
3. `rating` entre 1 y 5, entero.
4. `comment` nulo o con 1–500 caracteres tras `btrim`.
5. `UPDATE` y `DELETE` rechazados por el motor, salvo purga del arnés.
6. Toda fila tiene exactamente un evento de auditoría asociado con la misma transacción.
7. Sin `resolved_at`, no hay pendiente ni encuesta.
8. Tras una reapertura, el episodio anterior no aparece como pendiente; una encuesta ya enviada permanece y sigue contando.
9. Los agregados nunca imputan cero por falta de respuesta.
10. La distribución siempre tiene cinco claves.

---

## 10. Contrato de Interfaz (Frontend)

### 10.1. Portal de Sede

* `api.js` expone `satisfaction.getPending()` y `satisfaction.submit(incidentId, payload)`.
* `SatisfactionSurveyModal.js` (Options API) recibe `episodes` y emite `submitted` / `closed`.
* Estados: `loading`, `empty`, `error`, `submitting`, `success`.
* Tras `submitted`, el padre vuelve a llamar a `getPending()` y actualiza el modal con el siguiente episodio (R3).
* El aviso `Tiene N servicios por valorar` usa `pending_total`; si N > 20, el texto sigue mostrando el total real.
* 409 retira el episodio de la lista local sin mostrar error rojo.
* Accesibilidad: `role="dialog"`, `aria-modal="true"`, foco inicial en el primer radio, `Escape` cierra, etiquetas `1 estrella`…`5 estrellas`, contador `N/500` con `aria-live="polite"`.

### 10.2. Panel de Coordinación

* El bloque CSAT se carga junto al resto de métricas y respeta el filtro temporal vigente; no añade filtros propios.
* Estados: `loading`, `empty` («Sin valoraciones en el periodo»), `error`, `ready`.
* La tabla por técnico muestra el nombre congelado tal como llega de la API.

---

## 11. Matriz de Pruebas (fase 3)

| Suite | Nivel | Qué congela |
| :--- | :--- | :--- |
| `PostgresSatisfactionSurveySchemaTest.php` | Integración | Tabla, `CHECK`, unicidad, disparadores, paridad migración/init, purga. |
| `SatisfactionSurveyDomainTest.php` | Unidad | Rango, tipos de `rating`, normalización de comentario, media nula, distribución. |
| `SatisfactionSurveyServiceTest.php` | Unidad | Episodio vigente, reapertura, duplicado, `FOR UPDATE`, auditoría transaccional. |
| `LocationSatisfactionApiTest.php` | Integración | 401/403/404/409/422/201, precedencia, 20 + `pending_total`, `resolution_at` copiado. |
| `CoordinatorCsatMetricsApiTest.php` | Integración | Media, grupos con respuestas, `period`, Europe/Madrid, `null` sin datos, distribución con ceros. |
| `CsatPrivacyConstitutionalTest.php` | Unidad | Aislamiento de sede y ausencia de identidad técnica. |
| `AuditActionCatalogTest.php` | Unidad | Etiqueta y tono de `SUBMIT_SATISFACTION_SURVEY`. |
| `SatisfactionSurveyModalTest.mjs` | Unidad JS | Estados, refetch, 409, accesibilidad. |
| `LocationPortalViewTest.mjs` | Unidad JS | Aviso y apertura del modal. |
| `CoordinatorMetricsViewTest.mjs` | Unidad JS | Bloque CSAT y estado vacío. |
