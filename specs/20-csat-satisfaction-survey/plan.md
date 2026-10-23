# PLAN TÉCNICO: ENCUESTA DE SATISFACCIÓN POST-RESOLUCIÓN (CSAT DE SEDE)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 20 · Encuesta de Satisfacción Post-Resolución (CSAT de Sede)  
**Documento:** `specs/20-csat-satisfaction-survey/plan.md`  
**Referencia Funcional:** [`spec.md`](spec.md) (v2, aprobada 2026-10-23) · **Contratos:** [`../../specs/technical/satisfaction_survey_contracts.md`](../technical/satisfaction_survey_contracts.md)  
**Tareas:** [`tasks.md`](tasks.md)  
**Batería de referencia:** `php tests/run_all.php` + `node --test tests/unit/*.mjs`

---

## 1. Resumen de la Solución

El módulo añade una **valoración de una sola vez por episodio de resolución**. El ancla no es la avería, sino la pareja `(incident_id, resolution_at)`: una reapertura anula el pendiente anterior y una nueva resolución abre un episodio nuevo; una valoración ya enviada no se pierde ni se reescribe.

Tres decisiones estructurales gobiernan el diseño:

1. **El episodio es la unidad.** `resolution_at` se copia de `incidents.resolved_at` bajo `SELECT … FOR UPDATE`; el índice único `(incident_id, resolution_at)` es la última defensa contra carreras (RF-02.6).
2. **La métrica no imputa.** Solo suma filas existentes; `average = null` cuando no hay respuestas y los grupos sin valoraciones no aparecen (RD-07, R7).
3. **Nada se reescribe.** Motor y aplicación bloquean `UPDATE`/`DELETE`; la purga del arnés conserva su ventana (RD-06).

No hay dependencias nuevas, ni servicios externos, ni cambios en el flujo de resolución existente.

---

## 2. Arquitectura por Capas

### 2.1. Core / Domain

| Artefacto | Responsabilidad |
| :--- | :--- |
| `src/Core/Domain/Model/IncidentSatisfactionSurvey.php` **(nuevo)** | Entidad inmutable: normaliza nota y comentario, aplica RD-04/05/06. |
| `src/Core/Domain/Repository/SatisfactionSurveyRepositoryInterface.php` **(nuevo)** | `create()`, `findByEpisode()`, `findPendingByLocation()`, `countPendingByLocation()`, `aggregate()`. |
| `src/Core/Domain/Exception/SatisfactionSurveyValidationException.php` **(nuevo)** | Códigos de error de los contratos §5. |

### 2.2. Application

| Artefacto | Responsabilidad |
| :--- | :--- |
| `src/Application/Service/SatisfactionSurveyService.php` **(nuevo)** | Alta transaccional con relectura bloqueada, disponibilidad, duplicado y auditoría. |
| `src/Application/DTO/SatisfactionSurveyDto.php` **(nuevo)** | Proyección de lectura de la encuesta creada. |
| `src/Application/DTO/CsatReportDto.php` **(nuevo)** | Resumen, distribución, agrupaciones y serie mensual. |

### 2.3. Infrastructure

| Artefacto | Responsabilidad |
| :--- | :--- |
| `src/Infrastructure/Repository/PdoSatisfactionSurveyRepository.php` **(nuevo)** | PDO puro: pendientes, inserción con `ON CONFLICT`, agregados con `GROUP BY` y `TO_CHAR`. |

### 2.4. Presentation

| Artefacto | Responsabilidad |
| :--- | :--- |
| `src/Presentation/Controller/LocationSatisfactionController.php` **(nuevo)** | `pending()` y `store()`, con el aislamiento de `LocationRefundController`. |
| `src/Presentation/Controller/CoordinatorMetricsController.php` **(modificado)** | Método `getCsat()`; reutiliza `MetricFilter`. No altera los métodos existentes. |
| `src/Presentation/Routing/AppRouter.php` **(modificado)** | Dos rutas de sede tras `SiteAuthMiddleware` y una de coordinación tras `InternalAuthMiddleware(COORDINATOR)`. |

### 2.5. Frontend (Vue 3 ESM, sin bundler, Options API)

| Artefacto | Responsabilidad |
| :--- | :--- |
| `public/assets/js/components/SatisfactionSurveyModal.js` **(nuevo)** | Modal accesible, estados y emisión de eventos. |
| `public/assets/js/views/LocationPortalView.js` **(modificado)** | Aviso persistente, apertura del modal y refresco tras cada envío. |
| `public/assets/js/views/CoordinatorMetricsView.js` **(modificado)** | Bloque CSAT con distribución, tablas y serie mensual. |
| `public/assets/js/api.js` **(modificado)** | `satisfaction.getPending()` y `satisfaction.submit()`. |
| `public/assets/js/utils/AuditActionLabels.js` **(modificado)** | Etiqueta y tono de `SUBMIT_SATISFACTION_SURVEY`. |

---

## 3. Decisiones Técnicas y su Justificación

| # | Decisión | Alternativa descartada | Motivo |
| :--- | :--- | :--- | :--- |
| P1 | Ancla `(incident_id, resolution_at)` | Solo `incident_id` | `reopen()` pone `resolved_at = NULL`; la siguiente resolución debe poder valorarse. |
| P2 | Relectura `FOR UPDATE` + copia de `resolved_at` | Leer en el controlador y pasar la fecha | Una reapertura concurrente dejaría una encuesta huérfana. |
| P3 | `ON CONFLICT` sobre la unicidad | `SELECT` previo sin transacción | La carrera se resuelve en el motor; el segundo recibe `409`. |
| P4 | Agregados en SQL, no en PHP | Cargar filas y promediar | Volumen y precisión decimal; el repositorio ya agrega así. |
| P5 | `technician_name_snapshot` | Join a `users.name` en la métrica | Un renombrado no debe reescribir la historia (R8). |
| P6 | Solo grupos con respuestas | `LEFT JOIN` con ceros como en MTTR | CSAT sin respuestas no es cero; un cero falsearía la lectura (R7). |
| P7 | `period` en la métrica | Solo `from`/`to` | Consistencia con el resto del panel (R10). |
| P8 | Modal y aviso en `LocationPortalView` | Vista nueva | El portal ya es la única superficie de sede; no hay historial. |
| P9 | Bloque CSAT dentro de `CoordinatorMetricsView` | Vista propia | La métrica pertenece al panel existente (RF-06.1). |

---

## 4. Work Units (rebanadas de commit)

| WU | Tareas | Contenido | Riesgo |
| :--- | :--- | :--- | :--- |
| **WU-1 · Esquema** | T-CSAT-01 | Migración 011, réplica en `supabase_init.sql`, contrato de correspondencia, guardas de esquema, `TestDataCleaner` y suite de esquema. | `Risk: item 1` (esquema persistente nuevo). Rebanada aislada. |
| **WU-2 · Dominio y aplicación** | T-CSAT-02, T-CSAT-03, T-CSAT-04 | Entidad, puerto, repositorio PDO, servicio, DTOs y pruebas unitarias. | `Risk: none`. |
| **WU-3 · API de sede** | T-CSAT-05 | Controlador, rutas, pruebas HTTP (precedencia, 409, 20 + total) y constitucional de privacidad. | `Risk: none` (endpoints nuevos, sin tocar salidas existentes). |
| **WU-4 · Métrica de coordinación** | T-CSAT-06 | `getCsat()`, consultas agregadas y suite HTTP. | `Risk: none` (método nuevo; no altera los existentes). |
| **WU-5 · Frontend de sede** | T-CSAT-07 | `SatisfactionSurveyModal.js`, aviso en el portal, `api.js` y pruebas JS. | `Risk: item 3` solo si se modifica salida existente; se declara al commitear. |
| **WU-6 · Frontend de coordinación** | T-CSAT-08 | Bloque CSAT y pruebas JS. | `Risk: none`. |
| **WU-7 · Guardas y cierre** | T-CSAT-09, T-CSAT-10 | Catálogo de auditoría, cifras documentadas, cierre documental y batería completa. | `Risk: none`. |

**Orden de ejecución:** WU-1 → WU-2 → WU-3 → WU-4 → WU-5 → WU-6 → WU-7. WU-5 y WU-6 comparten `CoordinatorMetricsView.js` solo en WU-6 y `api.js` solo en WU-5: no son paralelizables sin solaparse.

---

## 5. Pronóstico de Cambio y Estrategia de Entrega

| Bloque | Líneas estimadas |
| :--- | ---: |
| Esquema (migración + init + guardas) | ~180 |
| Dominio y aplicación | ~340 |
| Infraestructura PDO | ~230 |
| API y rutas | ~200 |
| Frontend (modal, portal, panel, api) | ~330 |
| Pruebas (PHP + JS) | ~700 |
| **Total autorizado** | **~1.980** |

* **Rama:** `20-csat-satisfaction-survey`, una rama de módulo (precedente del repositorio).
* **Estrategia de entrega:** `ask-on-risk`. El pronóstico supera las 400 líneas, así que antes del primer commit se pregunta al Product Owner por una de las dos estrategias de cadena (`stacked-to-main` o `feature-branch-chain`). Sin respuesta, se mantiene un único PR por módulo con siete commits revisables.
* **Commits:** un WU por commit, Conventional Commits, con pruebas y documentación en el mismo commit que el comportamiento.
* **Rebanada de alto riesgo:** WU-1 va sola para que la revisión nativa pueda tomarla aislada.

---

## 6. Riesgos y Mitigaciones

| Riesgo | Impacto | Mitigación |
| :--- | :--- | :--- |
| Reapertura concurrente durante el envío | Encuesta sobre un episodio ya anulado | `FOR UPDATE` + relectura de `resolved_at` (P2). |
| Doble envío simultáneo | `500` por violación de unicidad | `ON CONFLICT` capturado como `409` (P3). |
| `resolution_at` sustituido por `now()` | Métrica atribuida al mes equivocado | Copia explícita de la columna; prueba de esquema que compara ambos valores. |
| Zona horaria del motor distinta | Meses desplazados | `resolution_at AT TIME ZONE 'Europe/Madrid'` explícito, sin depender del `TimeZone` de sesión. |
| Renombrado de técnico | Historia reescrita | `technician_name_snapshot` (P5). |
| Guarda de esquema desactualizada | Batería roja en CI | T-CSAT-01 incluye guardas y contrato de correspondencia. |
| Tope de 20 con más pendientes | Encuestas inalcanzables | `pending_total` + refresco tras cada envío (R3). |
| Componente nuevo en Composition API | Inconsistencia con el repositorio | Options API declarada en RF-05.7. |

---

## 7. Verificación

1. **Por tarea:** prueba en rojo → verde → refactor, con la suite enfocada.
2. **Por WU:** suite del área + `php tests/run_all.php` cuando el WU toque esquema o infraestructura compartida.
3. **Cierre:** batería completa PHP + suites `.mjs`, `AuditActionCatalogTest` en verde y cifras documentadas.

---

## 8. Fuera de Este Plan

Todo lo excluido en `spec.md` §2.2: encuestas de preventivas, edición, anonimización, superficie del técnico, NPS, recordatorios, exportación, moderación y selector territorial.
