# ESPECIFICACIÓN FUNCIONAL: ENCUESTA DE SATISFACCIÓN POST-RESOLUCIÓN (CSAT DE SEDE)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 20 · Encuesta de Satisfacción Post-Resolución (CSAT de Sede)  
**Documento Canónico:** `specs/20-csat-satisfaction-survey/spec.md`  
**Versión:** 2 · incorpora las 11 resoluciones de la revisión QA (2026-10-23)  
**Estado:** Resoluciones R1–R11 **aprobadas por el Product Owner el 2026-10-23** · puerta SDD superada el mismo día · **Fase 3 implementada y cerrada** (WU-1 a WU-7) · **R6 revisada por el incremento 20.1** (superficie CSAT de solo lectura para el técnico) · batería global 325 suites / 12.656 aserciones en verde  
**Fecha:** Octubre 2026  
**Origen:** Feature 6 del catálogo [`docs/features_pendientes.md`](../../docs/features_pendientes.md) (Bloque 2)  
**Alineación Constitucional:** Art. I (supremacía de la especificación), Art. III.1 y III.3 (inmutabilidad y auditoría), Art. IV.1–IV.3 (PHP puro, Vue 3 estándar, cero dependencias), Art. V.4 (privacidad y segregación de la sede), Art. VI.2 (canalización del backlog). **Art. II: no aplica** (el módulo no altera cadena de frío, SLA ni cuarentenas).

---

## 1. Autorización Formal del Alcance (Art. VI.1)

La Fase 1 (MVP) quedó formalmente cerrada por el Product Owner el 2026-10-21 (ver [`specs/18-van-stock/spec.md`](../18-van-stock/spec.md) §1 y el acta [`docs/verificacion_criterios_finalizacion_mvp.md`](../../docs/verificacion_criterios_finalizacion_mvp.md)). La feature pertenece al backlog declarado del Bloque 2 y no toca telemetría, pasarelas de pago ni inventario de furgonetas: ningún término prohibido del Art. VI.1.

Instrucciones del Product Owner, verbatim:

> **2026-10-23:** "Hola, vamos a iniciar el proceso de implementacion de 6. ⭐ Encuesta de Satisfacción Post-Resolución (CSAT de Sede), lo puedes leer en docs/features_pendientes.md"

> **2026-10-23:** "hazlo todo con tus respuestas recomendadas, ya que me parecen correctas"

Consecuencias normativas:

1. El módulo 20 queda autorizado **exclusivamente** en el alcance de §2.1.
2. Nace del catálogo ya aprobado (feature 6, Bloque 2), no de una idea nueva (Art. VI.2).
3. La guarda `tests/unit/ConstitutionalAuditTest.php` §7.1 **no requiere enmienda**.
4. Las once resoluciones de la revisión QA (§13) son **normativas**. Cualquier cambio posterior exige reabrir esta puerta (Art. I y `AGENTS.md` §5.5).

### 1.1. Requisito del catálogo (verbatim, `docs/features_pendientes.md` §6)

> * **Propósito:** Medir objetivamente la satisfacción del cliente una vez resuelto o cerrado formalmente el ticket.
> * **Componentes Técnicos:**
>   * Modal reactivo de valoración (1 a 5 estrellas + comentario cualitativo breve) visible en el Portal de Sede tras el cierre de la avería.
>   * Tabla `incident_satisfaction_surveys`.
>   * Métrica agregada en el Panel de Coordinación: Índice CSAT promedio por Sede, por Técnico y tendencia mensual.
> * **Valor para el Cliente:** Demuestra compromiso formal de calidad y permite detectar fricciones antes de renovaciones de contrato.
> * **Complejidad:** Baja (1–2 días).

> **Nota de alcance:** la estimación del catálogo (1–2 días) quedó desactualizada al añadirse auditoría, esquema con disparadores, segregación constitucional y dos superficies. Esta spec fija el alcance real; la estimación no es un criterio de aceptación.

---

## 2. Alcance

### 2.1. Dentro del alcance

1. **Descubrimiento de servicios por valorar:** el Portal de Sede conoce qué averías de su sede han quedado resueltas y todavía no tienen encuesta, tanto en la ventana de 48 h (`RESOLVED`) como después del cierre automático (`CLOSED`).
2. **Valoración única e inmutable:** una encuesta por **episodio de resolución**, con nota entera de 1 a 5 estrellas y comentario opcional de hasta 500 caracteres.
3. **Persistencia:** tabla `incident_satisfaction_surveys` (tabla 28 del DDL canónico) con el ancla `resolution_at`, la sede, el técnico (id y nombre congelados) y la marca de la respuesta.
4. **Métrica agregada de Coordinación:** resumen global (media, respuestas y distribución por estrellas), media por sede, media por técnico y tendencia mensual, sobre `MetricFilter`.
5. **Auditoría:** cada respuesta deja un evento inmutable `SUBMIT_SATISFACTION_SURVEY` sobre la entidad `TICKET` (Art. III.3).
6. **Interfaz:** aviso persistente y modal de valoración en el Portal de Sede; bloque CSAT en el Panel de Métricas de Coordinación.

### 2.2. Fuera del alcance (excluido explícitamente)

* **Encuestas de inspecciones preventivas** (`preventive_orders`): el catálogo habla de «la avería».
* **Edición, rectificación o borrado de una respuesta enviada** (R4). Sin ventana de corrección.
* **Anonimización o caducidad de la respuesta** (R4/D8): sin borrado, sin seudonimización, sin job de retención.
* **Superficie para el técnico** (su nota, sus comentarios o un ranking): la feature solo pide el panel de Coordinación (R6).
* **NPS, escala 0–10, subcategorías de valoración, etiquetas de motivo o análisis de sentimiento.**
* **Recordatorios automáticos** por correo, Telegram o webhook: el catálogo de plantillas está cerrado.
* **Tasa de respuesta, CSAT por máquina o por categoría, exportación CSV o informe A4.**
* **Moderación, censura o filtrado de comentarios.**
* **Encuestas al ciudadano** (reporte QR) o a informadores sin sesión de sede.
* **Modo offline / PWA** (feature 7, independiente).
* **Selector territorial en el panel de métricas** (R11): no existe hoy y no se añade.

---

## 3. Usuarios y Perfiles Implicados

| Perfil | Papel en el módulo | Permisos y restricciones |
| :--- | :--- | :--- |
| **🏢 Responsable de Sede** | Cliente que valora el servicio. | Responde **solo** por su sede y **solo** por episodios de sus averías. Ve sus pendientes; **nunca** métricas agregadas, identidad del técnico ni valoraciones de otras sedes (Art. V.4). Una respuesta por episodio, inmutable. |
| **📊 Coordinador de Operaciones** | Consumidor de la métrica. | Ve todas las encuestas (nota + comentario) y los agregados. No puede crear, editar ni borrar encuestas en nombre de la sede. |
| **📱 Técnico de Campo** | Objeto de la métrica por técnico. | **Sin superficie en v1.** No ve comentarios ni su nota media. |

> **Identidad del respondiente (R2):** la encuesta se firma con la **sede**, no con una persona. No se pide nombre, teléfono ni correo al valorar: el Art. V.4 manda minimizar, y la métrica no necesita identificar al conserje de turno. Límite declarado: si en una sede responden varias personas, el sistema no distingue cuál.

---

## 4. Historias de Usuario

### HU-01: Valorar el servicio recibido
> **Como** Responsable de Sede, **quiero** puntuar de 1 a 5 estrellas la última reparación y dejar un comentario breve, **para** que mi valoración quede registrada sin llamar ni escribir un correo.

### HU-02: Saber qué me falta por valorar
> **Como** Responsable de Sede, **quiero** ver al entrar cuántos servicios tengo pendientes, **para** no perder la valoración por no recordar qué averías se resolvieron.

### HU-03: Medir la calidad antes de renovar
> **Como** Coordinador de Operaciones, **quiero** ver el CSAT medio por sede, por técnico y su evolución mensual, **para** detectar fricciones antes de la renovación del contrato.

---

## 5. Requisitos Funcionales con Criterios de Aceptación (EARS)

### RF-01 · Descubrimiento de episodios pendientes de valorar

* **RF-01.1** `GET /api/location/satisfaction-surveys/pending` devuelve los servicios de la sede autenticada que cumplen **las tres** condiciones: `status IN ('RESOLVED','CLOSED')`, `resolved_at IS NOT NULL` y ausencia de fila en `incident_satisfaction_surveys` con la misma pareja `(incident_id, resolution_at)`.
* **RF-01.2** La sede se resuelve **siempre** desde la sesión (`authenticated_location`). El endpoint no acepta código de sede por parámetro ni por cuerpo.
* **RF-01.3** Orden `resolved_at DESC`. Se devuelven como máximo los **20** episodios más recientes y `pending_total` con el total real (R3).
* **RF-01.4** Cada elemento expone solo `incident_id`, `ticket_code`, `machine_code`, `machine_model` y `resolved_at`. **Nunca** el técnico, su nombre ni datos económicos.
* **RF-01.5** Sin pendientes: `HTTP 200` con `{ "items": [], "pending_total": 0 }`.
* **RF-01.6** Sin sesión válida o con la sede dada de baja, el middleware responde `401 UNAUTHORIZED` antes de llegar al controlador. Un fallo de persistencia responde `500 INTERNAL_SERVER_ERROR` sin filtrar detalles.
* **RF-01.7** Una avería `CLOSED` sin `resolved_at` (dato heredado o de demostración) **no** genera pendiente: sin ancla de resolución no hay episodio valorable.

### RF-02 · Envío de la valoración

* **RF-02.1** `POST /api/location/incidents/{id}/satisfaction-survey` con JSON `{ "rating": 1..5, "comment": "texto opcional" }`. `{id}` es numérico; no hay alias por `ticket_code`.
* **RF-02.2 Validaciones, en este orden exacto (fail-fast):**
  | # | Condición | HTTP | `error` |
  | :--- | :--- | :--- | :--- |
  | 1 | Sin token o token inválido (middleware) | 401 | `UNAUTHORIZED` |
  | 2 | `{id}` no numérico o inexistente | 404 | `INCIDENT_NOT_FOUND` |
  | 3 | La avería pertenece a otra sede | 403 | `SITE_MISMATCH` |
  | 4 | `resolved_at` nulo o estado fuera de `RESOLVED`/`CLOSED` | 422 | `SURVEY_NOT_AVAILABLE` |
  | 5 | `rating` ausente | 400 | `MISSING_RATING` |
  | 6 | `rating` no entero entre 1 y 5 | 422 | `INVALID_RATING` |
  | 7 | `comment` supera 500 caracteres tras normalizar | 422 | `COMMENT_TOO_LONG` |
  | 8 | Ya existe encuesta para el `resolution_at` vigente | 409 | `SURVEY_ALREADY_SUBMITTED` |

  **Precedencia declarada (R9):** disponibilidad antes que contenido; duplicado al final. Un envío repetido **con nota inválida** responde `422 INVALID_RATING`, no `409`. El duplicado solo se comprueba con una carga útil válida.
* **RF-02.3 Tipos aceptados (R9):** `rating` entero JSON o cadena que cumpla `/^[1-5]$/` tras `trim`. Se rechazan `float` (incluido `4.0`), `bool`, `null`, arrays y objetos. `comment` admite cadena o `null`; cualquier otro tipo responde `422 COMMENT_TOO_LONG` con el mensaje de tipo no válido.
* **RF-02.4 Normalización del comentario:** `trim` con espacios Unicode; una cadena vacía se persiste como `NULL`, nunca como `''`; se permiten saltos de línea y tabulaciones internas; la longitud se mide con `mb_strlen` (caracteres, no bytes); no se sanea HTML (la interfaz escapa al renderizar).
* **RF-02.5** Respuesta `HTTP 201` con `{ id, incident_id, ticket_code, rating, comment, created_at }`. Sin identificador ni nombre de técnico.
* **RF-02.6 Concurrencia (B1):** la escritura se ejecuta en una transacción que **relee la incidencia con bloqueo** (`SELECT … FOR UPDATE`) y recalcula disponibilidad y duplicado antes de insertar. `resolution_at` se **copia exactamente** de `incidents.resolved_at`; jamás se sustituye por `now()` ni por la hora del servidor. Una reapertura concurrente hace que la petición responda `422 SURVEY_NOT_AVAILABLE`.
* **RF-02.7** La unicidad `(incident_id, resolution_at)` es la última defensa: dos envíos concurrentes producen una sola fila y el segundo recibe `409`, sin `500`.

### RF-03 · Inmutabilidad y trazabilidad

* **RF-03.1** Sin endpoint de modificación ni de borrado. La tabla bloquea `UPDATE` y `DELETE` en el motor mediante disparadores canónicos. El `DELETE` usa la función compartida `prevent_hard_delete()`, que **solo** cede ante la ventana de purga del arnés (`vendguard.purge`, `tests/Support/TestDataCleaner::withPurgeWindow`); ninguna ruta de aplicación la activa.
* **RF-03.2** Cada respuesta registra un evento de auditoría `SUBMIT_SATISFACTION_SURVEY` sobre la entidad `TICKET`: `user = { id: null, role: 'SITE_RESPONSIBLE', name: <nombre de la sede> }`, `previous_state = null`, `new_state = { survey_id, rating, resolution_at, technician_id }`, `metadata = { incident_id, location_id, ticket_code }`. No se añade valor al enum `audit_entity_type`.
* **RF-03.3** La auditoría se escribe **en la misma transacción** que la encuesta.
* **RF-03.4** El catálogo de auditoría del frontend (`public/assets/js/utils/AuditActionLabels.js`) incorpora la acción en el grupo `ticket` con tono `primary`; `AuditActionCatalogTest` debe quedar en verde.

### RF-04 · Métrica CSAT en el Panel de Coordinación

* **RF-04.1** `GET /api/coordinator/metrics/csat` reutiliza `MetricFilter::fromQueryParams()` con los parámetros **`period`** (`last_7_days`, `current_month`, `last_month`, `last_30_days`, `custom`), `from`, `to`, `location_id` y `technician_id` (R10). Sin `period` aplica `last_30_days`, igual que el resto del panel. Filtro inválido → `400 INVALID_FILTER_PARAMS`.
* **RF-04.2** Respuesta exacta:
  ```json
  {
    "summary": { "average": 4.21, "responses": 19, "distribution": { "1": 0, "2": 1, "3": 2, "4": 6, "5": 10 } },
    "by_site": [ { "location_id": 3, "site_code": "SEDE-BCN-01", "location_name": "Hospital La Paz", "average": 4.5, "responses": 8 } ],
    "by_technician": [ { "technician_id": 7, "technician_name": "Ana Ruiz", "average": 4.0, "responses": 5 } ],
    "monthly_trend": [ { "month": "2026-09", "average": 4.0, "responses": 6 } ]
  }
  ```
  `distribution` incluye **siempre** las cinco claves como cadenas, con `0` cuando no hay respuestas.
* **RF-04.3 Cálculo:** media aritmética de `rating` redondeada a 2 decimales (`ROUND(AVG(rating)::numeric, 2)`); `average = null` cuando `responses = 0`. Las averías sin valorar **no** puntúan como cero (RD-07).
* **RF-04.4 Agrupaciones y nombres:**
  * `by_site`: por `location_id`, nombre **actual** de la sede, con `site_code`. Orden: `responses DESC`, `location_name ASC`.
  * `by_technician`: por `technician_id`, nombre **congelado** en la encuesta más reciente del grupo (`technician_name_snapshot` con `created_at DESC, id DESC`); si el id es nulo, `technician_name = "Sin técnico"`. Orden: `responses DESC`, nombre ASC.
  * `monthly_trend`: por mes de **resolución** (`resolution_at`), formato `YYYY-MM`, en la zona **Europe/Madrid**, orden ascendente.
* **RF-04.5** Solo se listan grupos con al menos una respuesta (R7). El filtro temporal se aplica a `resolution_at`; los filtros de sede y técnico, a las columnas homónimas de la encuesta.
* **RF-04.6** El nombre del técnico no filtra por `is_active`: un técnico dado de baja conserva sus encuestas históricas.

### RF-05 · Interfaz del Portal de Sede

* **RF-05.1** Al cargar el portal autenticado se consulta el listado de pendientes. Si hay al menos uno, se abre el modal del episodio más reciente.
* **RF-05.2** Un aviso persistente en la pestaña «Máquinas e Incidencias» muestra «Tiene N servicios por valorar» y reabre el modal. Cerrar el modal sin valorar no bloquea el portal y no lo reabre en cada refresco de la lista **dentro de la misma carga de página**; una recarga o un nuevo inicio de sesión sí vuelve a ofrecerlo (R3).
* **RF-05.3** El modal contiene: ticket, máquina y fecha de resolución; selector de 1 a 5 estrellas; textarea con contador `0/500`; botón «Enviar valoración» deshabilitado mientras el envío está en curso.
* **RF-05.4** Un error de red o de servidor muestra el mensaje y **conserva** nota y comentario. Un `409` informa de que ese servicio ya fue valorado y lo retira de la lista.
* **RF-05.5** Tras un envío correcto se **vuelve a consultar el listado de pendientes** (R3): si queda otro, el modal pasa al siguiente; si no, se cierra con confirmación. El bucle continúa hasta que `pending_total` sea `0` o el usuario cierre el modal; nunca queda un pendiente inalcanzable por el tope de 20.
* **RF-05.6** El selector de estrellas es un grupo de radios accesible por teclado y lector de pantalla (etiquetas «1 estrella» … «5 estrellas»), y sigue [`docs/design.md`](../../docs/design.md): radios de 4 px en botones e inputs, 8 px en la tarjeta del modal.
* **RF-05.7** El componente se escribe en **Options API**, como el resto del repositorio (R1). Desviación constitucional Art. IV.2 declarada y heredada del Módulo 19: el repositorio entero usa Options API; no se introduce una excepción nueva.

### RF-06 · Interfaz de Coordinación

* **RF-06.1** El Panel de Métricas incorpora un bloque «⭐ Satisfacción del Cliente (CSAT)» con media global, respuestas, distribución por estrellas, tabla por sede, tabla por técnico y serie mensual.
* **RF-06.2** Respeta el **filtro temporal ya presente** (`period`, `from`, `to`). **No** hay filtro territorial en la vista y no se añade (R11).
* **RF-06.3** Sin respuestas en el periodo muestra «Sin valoraciones en el periodo», no `0`.
* **RF-06.4** Sin exportación CSV ni impresión.

---

## 6. Reglas de Dominio (RD)

| # | Regla | Fundamento |
| :--- | :--- | :--- |
| **RD-01** | Una encuesta por **episodio de resolución** `(incident_id, resolution_at)`. Una reapertura pone `resolved_at = NULL`: el episodio anterior deja de ser valorable y la siguiente resolución abre uno nuevo. Un episodio **ya valorado** conserva su encuesta aunque el ticket se reabra después (R5). | Art. V.6. |
| **RD-02** | El técnico se congela en la fila: `technician_id` **y** `technician_name_snapshot` con los valores vigentes al resolverse (R8). | Art. III.3. |
| **RD-03** | Sin encuesta para canceladas, sin `resolved_at`, ni para preventivas. | §2.2. |
| **RD-04** | Nota entera de 1 a 5; sin 0, sin medias estrellas, sin escala 0–10. | Catálogo §1.1. |
| **RD-05** | Comentario opcional, hasta 500 caracteres tras normalizar; sin datos personales solicitados. | Catálogo §1.1 + Art. V.4. |
| **RD-06** | Respuesta inmutable en aplicación y motor; sin ventana de rectificación (R4). | Art. III.1. |
| **RD-07** | Las averías sin valorar no entran en la media ni se imputan por defecto. | Veracidad de la métrica. |
| **RD-08** | Toda respuesta firma auditoría en la misma transacción. | Art. III.3. |
| **RD-09** | Sin plantillas nuevas en `notification_outbox` ni canales nuevos. | Catálogo cerrado M14. |
| **RD-10** | El predecesor de una **Avería Crónica** (M16) es valorable: se cerró conservando `resolved_at` y hubo servicio real (R5). | Veracidad de la métrica. |
| **RD-11** | La encuesta se atribuye a `incidents.location_id`, la sede que reportó la avería, aunque la máquina se transfiera después. | Art. III.3. |

---

## 7. Modelo de Datos (tabla 28 del DDL canónico)

```sql
CREATE TABLE IF NOT EXISTS incident_satisfaction_surveys (
    id                       BIGINT GENERATED ALWAYS AS IDENTITY,
    incident_id              BIGINT NOT NULL,
    location_id              BIGINT NOT NULL,
    technician_id            BIGINT,
    technician_name_snapshot VARCHAR(100),
    resolution_at            TIMESTAMPTZ NOT NULL,
    rating                   SMALLINT NOT NULL,
    comment                  VARCHAR(500),
    created_at               TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_incident_satisfaction_surveys PRIMARY KEY (id),
    CONSTRAINT fk_survey_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_survey_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_survey_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT uq_survey_episode UNIQUE (incident_id, resolution_at),
    CONSTRAINT chk_survey_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT chk_survey_comment CHECK (
        comment IS NULL OR (char_length(btrim(comment)) BETWEEN 1 AND 500)
    )
);
```

* **Índices:** `idx_surveys_resolution_at (resolution_at)`, `idx_surveys_location (location_id, resolution_at)`, `idx_surveys_technician (technician_id, resolution_at)`.
* **Disparadores:** `trg_no_update_incident_satisfaction_surveys` (función propia, `SQLSTATE 45000`) y `trg_no_delete_incident_satisfaction_surveys` (`prevent_hard_delete()`, compatible con la ventana de purga del arnés).
* **Sin `updated_at`:** no hay edición que fechar.
* **Migración:** `database/supabase_migrations/011_incident_satisfaction_surveys.sql`, replicada en `database/supabase_init.sql` (sección 7, tabla 28) y declarada en `specs/technical/schema_correspondence_contract.md` §2. Al añadirla se corrige la cabecera del DDL, que hoy dice «25 tablas» cuando ya declara 27 (desfase preexistente).
* **Semillas:** ninguna (D9).

---

## 8. Contratos de API (resumen)

| Método | Ruta | Auth | Respuesta |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/location/satisfaction-surveys/pending` | `SiteAuthMiddleware` | `200 { items, pending_total }` · `401` |
| `POST` | `/api/location/incidents/{id}/satisfaction-survey` | `SiteAuthMiddleware` | `201` · `400/401/403/404/409/422` (RF-02.2) |
| `GET` | `/api/coordinator/metrics/csat` | `InternalAuthMiddleware(COORDINATOR)` | `200` bloque CSAT · `400` |

Contrato completo, ejemplos y catálogo de errores en [`specs/technical/satisfaction_survey_contracts.md`](../technical/satisfaction_survey_contracts.md).

---

## 9. Arquitectura por Capas

| Capa | Artefacto | Responsabilidad |
| :--- | :--- | :--- |
| Core / Domain | `IncidentSatisfactionSurvey.php` | Entidad inmutable, validación de nota y comentario, reglas RD-04/05/07. |
| Core / Domain | `SatisfactionSurveyRepositoryInterface.php` | `create()`, `findByEpisode()`, `findPendingByLocation()`, `aggregate()`. |
| Application | `SatisfactionSurveyService.php` | Alta transaccional con relectura bloqueada + auditoría; cálculo de agregados. |
| Application | `SatisfactionSurveyDto.php`, `CsatReportDto.php` | Proyecciones inmutables. |
| Infrastructure | `PdoSatisfactionSurveyRepository.php` | PDO puro; `ON CONFLICT` sobre `uq_survey_episode`. |
| Presentation | `LocationSatisfactionController.php` | Rutas de sede con el aislamiento de `LocationRefundController`. |
| Presentation | `CoordinatorMetricsController::getCsat()` | Reutiliza `MetricFilter`; sin controlador nuevo. |
| Frontend | `SatisfactionSurveyModal.js` | Modal accesible (Options API). |
| Frontend | `LocationPortalView.js`, `CoordinatorMetricsView.js`, `api.js`, `AuditActionLabels.js` | Aviso, bloque CSAT, cliente API y etiqueta de auditoría. |

---

## 10. Privacidad y Segregación (Art. V.4 / RNF-01)

1. El portal solo lee episodios de su sede, resueltos desde su sesión. Sin parámetro de sede ni de técnico.
2. Ninguna respuesta al portal incluye `technician_id`, nombre, teléfono ni métricas agregadas.
3. Coordinación ve nota y comentario; es información interna y no se publica en documentos entregables a la sede.
4. El comentario no se filtra ni se enmascara: es texto del cliente sobre el servicio.
5. `CsatPrivacyConstitutionalTest` demuestra los puntos 1 y 2.

---

## 11. Verificación (fase 3)

* **Esquema:** `tests/integration/PostgresSatisfactionSurveySchemaTest.php` (tabla, `CHECK`, unicidad por episodio, disparadores, correspondencia de migraciones, purga del arnés).
* **Dominio:** `tests/unit/SatisfactionSurveyDomainTest.php` (rango, tipos aceptados, comentario, media nula, distribución).
* **Servicio:** `tests/unit/SatisfactionSurveyServiceTest.php` (episodio vigente, reapertura, `409`, relectura bloqueada, auditoría en la misma transacción).
* **HTTP sede:** `tests/integration/LocationSatisfactionApiTest.php` (401/403/404/409/422/201, precedencia de errores, 20 + `pending_total`).
* **HTTP coordinación:** `tests/integration/CoordinatorCsatMetricsApiTest.php` (media, agrupaciones, `period`, zona horaria, `null` sin respuestas, distribución con ceros).
* **Constitucional:** `tests/unit/CsatPrivacyConstitutionalTest.php` (aislamiento de sede y ausencia de identidad técnica).
* **Auditoría:** `AuditActionCatalogTest` en verde con la acción nueva y su etiqueta.
* **Frontend:** `tests/unit/SatisfactionSurveyModalTest.mjs`; ampliación de `LocationPortalViewTest.mjs` y `CoordinatorMetricsViewTest.mjs`.
* **Cierre:** `php tests/run_all.php` + suites `.mjs` en verde.

---

## 12. Decisiones de Producto (aprobadas)

| # | Decisión |
| :--- | :--- |
| **D1** | La encuesta se habilita al fijarse `resolved_at` y sigue disponible tras el cierre automático a las 48 h. Sin caducidad técnica; la superficie solo ofrece los 20 más recientes (D4/R3). |
| **D2** | Una encuesta por episodio `(incident_id, resolution_at)`. |
| **D3** | Atribución técnica congelada: id **y** nombre. |
| **D4** | Tope de 20 con `pending_total` y refresco tras cada envío. |
| **D5** | Inmutabilidad absoluta, sin rectificación. |
| **D6** | Comentario opcional (0–500); nota obligatoria. |
| **D7** | Coordinación ve todo; el técnico no tiene superficie. |
| **D8** | Retención indefinida, sin anonimización. |
| **D9** | Sin semillas de demostración. |

---

## 13. Resoluciones de la Revisión QA (normativas, 2026-10-23)

| # | Pregunta | Resolución |
| :--- | :--- | :--- |
| **R1** | Options API vs Composition API | **Options API** + desviación Art. IV.2 declarada (precedente M19). |
| **R2** | Identidad del respondiente | **Solo la sede**; sin nombre de persona. |
| **R3** | Tope de 20 | **Tope 20 + `pending_total` + refresco tras cada envío**. Sin paginación. |
| **R4** | Rectificación de la nota | **Inmutable**, sin ventana de corrección. |
| **R5** | Reapertura y avería crónica | Reabrir **anula el pendiente** del episodio anterior; un episodio ya valorado conserva su encuesta. El predecesor crónico **sí** es valorable. |
| **R6** | Superficie del técnico | **Sin superficie**; el técnico no ve nota ni comentarios. **Revisada por el incremento 20.1** (`spec-20.1-technician-surface.md`): el técnico ve su media, su conteo, su distribución y su tendencia mensual, nunca comentarios ni desglose por sede. |
| **R7** | Grupos vacíos | **Solo grupos con respuestas** + estado vacío explícito. |
| **R8** | Nombre del técnico | **Congelado** en `technician_name_snapshot`. |
| **R9** | Tipo de `rating` | Acepta entero y cadena entera `1`–`5`; rechaza float y bool. Precedencia de errores de RF-02.2. |
| **R10** | Periodo de la métrica | **`period` + `from`/`to`**, con `last_30_days` por defecto. |
| **R11** | Filtro territorial | **No existe y no se añade**; se retira la mención de RF-06.2. |

---

## 14. Criterios de Cierre del Módulo

1. Las diez suites de §11 en verde, incluida `php tests/run_all.php`.
2. La tabla 28 existe en migración e `init` con paridad certificada.
3. El catálogo de auditoría no tiene acciones fantasma.
4. La documentación de cifras (`README.md`/`CHANGELOG.md`/specs versionadas) refleja el estado real.
5. `docs/features_pendientes.md` marca la feature 6 como implementada (documento no versionado).
