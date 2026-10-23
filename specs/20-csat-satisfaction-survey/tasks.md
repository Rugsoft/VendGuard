# TAREAS DE IMPLEMENTACIÓN: ENCUESTA DE SATISFACCIÓN POST-RESOLUCIÓN (CSAT DE SEDE)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 20 · Encuesta de Satisfacción Post-Resolución (CSAT de Sede)  
**Documento:** `specs/20-csat-satisfaction-survey/tasks.md`  
**Spec:** [`spec.md`](spec.md) · **Plan:** [`plan.md`](plan.md) · **Contratos:** [`../../specs/technical/satisfaction_survey_contracts.md`](../technical/satisfaction_survey_contracts.md)  
**Política de pruebas:** *test-first*. Cada tarea declara su prueba en ROJO antes de implementar. Cierra con `php tests/run_all.php` y las suites `tests/unit/*.mjs`.

**Rama de trabajo:** `20-csat-satisfaction-survey`. **Estrategia de entrega:** `ask-on-risk` (ver plan §5).  
**Estado:** WU-1 cerrado (commit `7524ee2`). Fase 1 y puerta SDD cerradas el 2026-10-23.

---

## Work Units

| WU | Tareas | Contenido |
| :--- | :--- | :--- |
| **WU-1 · Esquema** | T-CSAT-01 | Tabla 28, disparadores, contrato de correspondencia, guardas y purga |
| **WU-2 · Dominio y aplicación** | T-CSAT-02, T-CSAT-03, T-CSAT-04 | Entidad, puerto, repositorio PDO, servicio y DTOs |
| **WU-3 · API de sede** | T-CSAT-05 | Controlador, rutas y suites HTTP/constitucional |
| **WU-4 · Métrica de coordinación** | T-CSAT-06 | `getCsat()` y consultas agregadas |
| **WU-5 · Frontend de sede** | T-CSAT-07 | Modal, aviso, cliente API y pruebas JS |
| **WU-6 · Frontend de coordinación** | T-CSAT-08 | Bloque CSAT y pruebas JS |
| **WU-7 · Guardas y cierre** | T-CSAT-09, T-CSAT-10 | Catálogo de auditoría, cifras, cierre y batería completa |

---

## Tareas

### ✅ T-CSAT-01 · Esquema de la tabla y su contrato
* **Refs:** RF-01.1, RF-02.6, RF-02.7, RF-03.1 · RD-01, RD-02, RD-06 · Contratos §2
* **Ruta:** inline
* **Prueba en rojo:** `tests/integration/PostgresSatisfactionSurveySchemaTest.php` + ajuste de las guardas de esquema.
* **Alcance:** `database/supabase_migrations/011_incident_satisfaction_surveys.sql`; `database/supabase_init.sql` (sección 7, tabla 28, corrección de la cabecera «25 tablas»); `specs/technical/schema_correspondence_contract.md`; `CONTRACT_TABLES` de `SchemaConvergenceParityTest` y `PostgresSchemaTest`; `tests/Support/TestDataCleaner.php`; suite de esquema.
* **Criterio de cierre:** tabla con `CHECK` de nota y comentario, unicidad por episodio, índices, disparadores de `UPDATE`/`DELETE` (con purga del arnés) y paridad migración/`init` en verde.
* **Riesgo:** `item 1` (esquema persistente nuevo).
* **Cierre observado (2026-10-23):** 17/17 aserciones del esquema en verde; guardas `PostgresSchemaTest` (89), `PostgresConstitutionalTest` (65), `SchemaConvergenceParityTest` (18) y `PostgresServiceSheetSchemaTest` (19) en verde; batería completa 317 suites / 12.327 aserciones en verde. Commit `7524ee2`.

### ⬜ T-CSAT-02 · Entidad de dominio
* **Refs:** RF-02.3, RF-02.4 · RD-04, RD-05, RD-06 · Contratos §4.3, §4.4
* **Ruta:** inline
* **Prueba en rojo:** `tests/unit/SatisfactionSurveyDomainTest.php` (rango, entero/cadena, float/bool, normalización y longitud del comentario).
* **Alcance:** `src/Core/Domain/Model/IncidentSatisfactionSurvey.php`, `src/Core/Domain/Exception/SatisfactionSurveyValidationException.php`.
* **Criterio de cierre:** la entidad rechaza toda nota fuera de 1–5 y todo comentario no textual o de más de 500 caracteres; la cadena vacía se normaliza a `null`.

### ⬜ T-CSAT-03 · Puerto y repositorio PDO
* **Refs:** RF-01.1, RF-01.3, RF-02.6, RF-02.7, RF-04.3, RF-04.4, RF-04.5 · Contratos §3.2, §4.5, §6.3
* **Ruta:** inline
* **Prueba en rojo:** cobertura vía `SatisfactionSurveyServiceTest` con doble del puerto + `LocationSatisfactionApiTest`.
* **Alcance:** `src/Core/Domain/Repository/SatisfactionSurveyRepositoryInterface.php`, `src/Infrastructure/Repository/PdoSatisfactionSurveyRepository.php` (pendientes, total, `create` con `ON CONFLICT`, agregados).
* **Criterio de cierre:** los pendientes excluyen los episodios valorados por pareja; el alta devuelve conflicto sin excepción cruda; los agregados devuelven las cinco claves de distribución y solo grupos con respuestas.

### ⬜ T-CSAT-04 · Servicio de aplicación y DTOs
* **Refs:** RF-02.2, RF-02.5, RF-02.6, RF-03.2, RF-03.3 · RD-01, RD-08, RD-10 · Contratos §4.5, §7
* **Ruta:** inline
* **Prueba en rojo:** `tests/unit/SatisfactionSurveyServiceTest.php` (precedencia de errores, reapertura, duplicado, `resolution_at` copiado, auditoría en la misma transacción).
* **Alcance:** `src/Application/Service/SatisfactionSurveyService.php`, `src/Application/DTO/SatisfactionSurveyDto.php`, `src/Application/DTO/CsatReportDto.php`.
* **Criterio de cierre:** toda alta pasa por relectura bloqueada; la auditoría usa `SUBMIT_SATISFACTION_SURVEY` sobre `TICKET`; un episodio valorado sobrevive a la reapertura.

### ⬜ T-CSAT-05 · API de sede
* **Refs:** RF-01.2, RF-01.4–01.7, RF-02.1, RF-02.5 · R2, R3 · Contratos §3, §4, §5
* **Ruta:** inline
* **Prueba en rojo:** `tests/integration/LocationSatisfactionApiTest.php`, `tests/unit/CsatPrivacyConstitutionalTest.php`.
* **Alcance:** `src/Presentation/Controller/LocationSatisfactionController.php`, `src/Presentation/Routing/AppRouter.php`.
* **Criterio de cierre:** 401/403/404/409/422/201 con la precedencia declarada; el listado nunca expone técnico; `pending_total` supera el tope de 20 sin truncarlo.

### ⬜ T-CSAT-06 · Métrica CSAT de coordinación
* **Refs:** RF-04.1–04.6 · R7, R8, R10, R11 · Contratos §6
* **Ruta:** inline
* **Prueba en rojo:** `tests/integration/CoordinatorCsatMetricsApiTest.php`.
* **Alcance:** `src/Presentation/Controller/CoordinatorMetricsController.php` (método `getCsat()`), `src/Infrastructure/Repository/PdoSatisfactionSurveyRepository.php` (agregados), ruta en `AppRouter`.
* **Criterio de cierre:** media redondeada, `average: null` sin respuestas, distribución con cinco claves, agrupaciones por nombre congelado y serie mensual en Europe/Madrid.

### ⬜ T-CSAT-07 · Frontend del Portal de Sede
* **Refs:** RF-05.1–05.7 · R1, R3 · Contratos §10.1
* **Ruta:** inline
* **Prueba en rojo:** `tests/unit/SatisfactionSurveyModalTest.mjs`, ampliación de `LocationPortalViewTest.mjs`.
* **Alcance:** `public/assets/js/components/SatisfactionSurveyModal.js`, `public/assets/js/views/LocationPortalView.js`, `public/assets/js/api.js`.
* **Criterio de cierre:** modal accesible, aviso con total real, refresco tras cada envío, `409` retira el episodio y el
  error conserva nota y comentario.

### ⬜ T-CSAT-08 · Frontend de coordinación
* **Refs:** RF-06.1–06.4 · R7, R11 · Contratos §10.2
* **Ruta:** inline
* **Prueba en rojo:** ampliación de `CoordinatorMetricsViewTest.mjs`.
* **Alcance:** `public/assets/js/views/CoordinatorMetricsView.js`.
* **Criterio de cierre:** bloque CSAT con resumen, distribución, tablas y tendencia; estado vacío explícito; sin filtro territorial ni exportación.

### ⬜ T-CSAT-09 · Catálogo de auditoría y documentación
* **Refs:** RF-03.4 · Contratos §7
* **Ruta:** inline
* **Prueba en rojo:** `tests/unit/AuditActionCatalogTest.php`.
* **Alcance:** `public/assets/js/utils/AuditActionLabels.js`; documentación de cifras (`README.md`, `CHANGELOG.md` si procede).
* **Criterio de cierre:** la acción aparece en el grupo `ticket` con tono `primary` y el test queda verde.

### ⬜ T-CSAT-10 · Cierre del módulo
* **Refs:** §14 de la spec
* **Ruta:** inline
* **Prueba en rojo:** no aplica (verificación final).
* **Alcance:** `php tests/run_all.php`, suites `.mjs`, `docs/features_pendientes.md` (marcar la feature 6 como implementada).
* **Criterio de cierre:** batería completa en verde, cifras documentadas y catálogo actualizado.
