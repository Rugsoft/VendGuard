# Plan de Tareas de Implementación (`tasks.md`): Cuadro de Mando de Reposición, Roturas de Stock y Mermas Comerciales (`38-restocking-bi-analytics`)

**Módulo:** `38-restocking-bi-analytics` (Feature 28 de `docs/features_pendientes.md`)  
**Versión:** 1.1 (Alineado con `spec.md` v1.1, `restocking_bi_analytics_contracts.md` v1.1 y `plan.md` v1.1; `D1`–`D5`, `C1`–`C6`, `QA-1`–`QA-5`)  
**Estado:** Aprobado el 2026-11-24 · Puerta SDD Fase 2 superada · Pendiente de Ejecución (`0/8` tareas)  
**Metodología:** SDD (Specification-Driven Development) · Clean Architecture · TDD / Guardas de Esquema y Diseño  
**Ley Suprema:** [`constitution.md`](../../constitution.md), [`AGENTS.md`](../../AGENTS.md) y [`docs/design.md`](../../docs/design.md)

---

## Resumen de Fases y Grafo de Dependencias

```mermaid
flowchart LR
    P1["Fase 1: Dominio Puro y DTOs\n(T-RBI-01)"] --> P2["Fase 2: Repositorio PDO PostgreSQL\n(T-RBI-02)"]
    P2 --> P3["Fase 3: Servicios BI y Exportación CSV\n(T-RBI-03)"]
    P3 --> P4["Fase 4: API REST, RBAC y Blindaje\n(T-RBI-04 y T-RBI-05)"]
    P4 --> P5["Fase 5: Frontend Vue 3, Chart.js y SW\n(T-RBI-06)"]
    P5 --> P6["Fase 6: Layout Chromium 360px\n(T-RBI-07)"]
    P6 --> P7["Fase 7: Batería Global y Cierre\n(T-RBI-08)"]
```

| Fase | Rango de Tareas | Descripción | Tiempo Estimado |
| :--- | :--- | :--- | :--- |
| **Fase 1** | `T-RBI-01` | Validación `RestockingBiFilterValidator`, motor puro `RestockingBiCalculator`, DTOs `RestockingBiFilterDto` / `RestockingBiReportDto` y suite unitaria `RestockingBiDomainTest.php` | ~45 min |
| **Fase 2** | `T-RBI-02` | Contrato `RestockingBiAnalyticsRepositoryInterface` e implementación PostgreSQL `PdoRestockingBiAnalyticsRepository` (cero DDL, consultas agregadas sin N+1 ni producto cartesiano) + `PdoRestockingBiAnalyticsRepositoryTest.php` | ~55 min |
| **Fase 3** | `T-RBI-03` | Servicios `RestockingBiAnalyticsService` y `RestockingBiExportService` (CSV 5 secciones `UTF-8 BOM` `;`, confidencialidad Art. V.4, anti-fórmulas + auditoría `EXPORT_RESTOCKING_BI_CSV`) + `RestockingBiServiceTest.php` | ~45 min |
| **Fase 4** | `T-RBI-04` .. `T-RBI-05` | Controlador `CoordinatorMetricsController`, rutas RBAC en `AppRouter`, métodos `api.js` (`fetch`+`Blob`), suite HTTP `RestockingBiApiTest.php` y blindaje constitucional `RestockingBiConstitutionalTest.php` | ~60 min |
| **Fase 5** | `T-RBI-06` | Componente `CoordinatorRestockingBiTab.js`, integración en `CoordinatorMetricsView.js` (`QA-3`), etiqueta en `AuditActionLabels.js`, precache en `public/sw.js` (`v33`), suite Node ESM `restocking_bi_tab.test.mjs` y guardas SW | ~70 min |
| **Fase 6** | `T-RBI-07` | Verificación visual y responsiva en Chromium (`360 × 800 px` y escritorio) con `RestockingBiLayoutBrowserTest.php` | ~35 min |
| **Fase 7** | `T-RBI-08` | Batería global (`php tests/run_all.php`), canal `new_engine`, `specs/38-restocking-bi-analytics/traceability-matrix.md`, cierre en `docs/features_pendientes.md` y `DocMetricsGuard` | ~30 min |

---

## Detalle de Tareas

- [ ] **T-RBI-01 · Dominio puro de filtrado, cálculo BI y DTOs (`RestockingBiFilterValidator`, `RestockingBiCalculator`, `RestockingBiFilterDto`, `RestockingBiReportDto`) + suite `RestockingBiDomainTest.php`**
  - **Requisitos:** `RF-01.1`, `RF-01.2`, `RF-01.4`, `RF-02`–`RF-05`, `D1`–`D3`, `C1`–`C5`, `QA-2`, `QA-4`, `QA-5`.
  - **Alcance:** Reglas puras sin E/S: ventana 30 días `Europe/Madrid`, validación `400 INVALID_FILTER_PARAMS` (par incompleto, formato/fecha imposible, `from > to`, `> 366` días, IDs `<= 0`, admisión de `to > today`), disponibilidad dual con cierre exacto `100.00 - visit_in_stock_rate_pct` (0 líneas = *In-Stock*, exclusión de `ABORTED_THERMAL_BREACH`), desglose FEFO/dañado, bloqueo térmico por `restocker_id`, badge determinista de planograma sobredimensionado (`>= 15.00 %`, `>= 2` visitas, `>= 3` caducadas), inclusión contable exacta (`QA-4`), prioridad sanitaria Art. II.2 en `by_machine` (`QA-2`), `machines_per_shift` (`null` en filtro territorial o 0 turnos), tiempo medio real de parada, L1 efectivas vs. escalados SAT y exactitud de arqueos M35 (`cash_reconciled_ok_count` y `cash_discrepancy_flagged_count` en `summary`).
  - **Verificación prevista:** `php tests/unit/RestockingBiDomainTest.php`

- [ ] **T-RBI-02 · Contrato e implementación PDO de lectura agregada (`RestockingBiAnalyticsRepositoryInterface` y `PdoRestockingBiAnalyticsRepository`) + suite `PdoRestockingBiAnalyticsRepositoryTest.php`**
  - **Requisitos:** `RF-01.1`, `RF-01.3`, `RF-02`–`RF-05`, `RNF-01`, `RNF-02`, `C1`–`C4`, `QA-2`, `QA-4`, `QA-5`, `Art. III`, `Art. IV`.
  - **Alcance:** Consultas agregadas independientes por fuente sobre las 51 tablas intactas: cabeceras M34 con `EXISTS`, ítems M34/M32 con guarda `mps.product_id = mvi.product_id` en `low_threshold_slots_count`, todas las actas térmicas M29 (incluidas `empty_machine_confirmed = TRUE` y los 3 orígenes, omitidas cuando `restocker_id != null`), turnos M22/M31 excluyendo anulados `closed_at = started_at`, arqueos M35 incluyendo `restock_visit_id IS NULL` y tickets M36 vinculados a `role = 'RESTOCKER'`; entidades con baja lógica (`is_active = FALSE` o `deleted_at IS NOT NULL`) con actividad en `(from, to)` y estabilidad de `available_filters` (`QA-4`).
  - **Verificación prevista:** `php tests/integration/PdoRestockingBiAnalyticsRepositoryTest.php`

- [ ] **T-RBI-03 · Servicios de aplicación BI y exportación CSV multi-sección (`RestockingBiAnalyticsService`, `RestockingBiExportService`) + suite `RestockingBiServiceTest.php`**
  - **Requisitos:** `RF-01.4`, `RF-03.2`, `RF-06.3`, `D2`, `D5`, `C2`, `C5`, `QA-1`, `Art. III.3`, `Art. V.4`.
  - **Alcance:** Ensamblado completo del informe BI y generación del CSV en 5 secciones con `UTF-8 BOM`, delimitador `;`, aviso de confidencialidad Art. V.4, `Arqueos_OK`/`Arqueos_Descuadre`, fallbacks `Sin zona`/`—`, regla única de neutralización anti-fórmulas (`=`, `+`, `-`, `@`, `\t`, `\r` prefijados con `'`), descarga válida incluso en periodos vacíos y persistencia del evento `EXPORT_RESTOCKING_BI_CSV` bajo `AuditEvent::ENTITY_MACHINE_RESTOCK_VISIT` (`entity_id = 0`, `QA-1`).
  - **Verificación prevista:** `php tests/unit/RestockingBiServiceTest.php`

- [ ] **T-RBI-04 · Capa HTTP REST y cliente API (`AppRouter.php`, `CoordinatorMetricsController.php`, `public/assets/js/api.js`) + suite `RestockingBiApiTest.php`**
  - **Requisitos:** `RF-01.2`, `RF-01.4`, `RF-06.3`, `RNF-03`, `C5`, `QA-5`, `Art. V.4`.
  - **Alcance:** Registrar `GET /api/coordinator/metrics/restocking/export` antes que `GET /api/coordinator/metrics/restocking` bajo `InternalAuthMiddleware(COORDINATOR)`, implementar `getRestockingBi` y `exportRestockingBiCsv` en `CoordinatorMetricsController`, añadir únicamente `getRestockingBi` y `downloadRestockingBiCsv` (`fetch` + `Blob`) en `api.js` y verificar códigos `200`/`400`/`401`/`403` más descarga CSV.
  - **Verificación prevista:** `php tests/integration/RestockingBiApiTest.php`

- [ ] **T-RBI-05 · Blindaje constitucional integral (`RestockingBiConstitutionalTest.php`)**
  - **Requisitos:** `Art. II`, `Art. III.1/III.2/III.3`, `Art. IV`, `Art. V.4`, `Art. VI`, `RNF-01`, `RNF-03`, `C6`, `QA-2`.
  - **Alcance:** Certificar segregación de abortos térmicos y prioridad sanitaria Art. II.2 en `by_machine`, preservación histórica de bajas lógicas sin borrados ni migraciones DDL (51 tablas / 50 ENUMs intactos), cero dependencias externas, bloqueo `403` para `RESTOCKER`/`TECHNICIAN`/`LOCATION_RESPONSIBLE`, ausencia de URLs CSV inseguras, opacidad frente a QR/Sede/Albarán A4 e integridad intacta de `ExecutiveReportModal.js`, `CoordinatorDashboardView.js` y `CoordinatorPlanogramTab.js`.
  - **Verificación prevista:** `php tests/integration/RestockingBiConstitutionalTest.php`

- [ ] **T-RBI-06 · Interfaz Vue 3 ESM (`CoordinatorRestockingBiTab.js`, `CoordinatorMetricsView.js`, `AuditActionLabels.js`, `public/sw.js`) + suite `tests/frontend/restocking_bi_tab.test.mjs` y guardas SW**
  - **Requisitos:** `RF-06.1`, `RF-06.2`, `D4`, `D5`, `RNF-04`, `QA-1`, `QA-3`, `docs/design.md`.
  - **Alcance:** Sub-pestaña **«📦 Métricas de Carga y Reposición»**, ocultación de la barra superior global de SAT (`v-if="activeSubTab !== 'restocking-bi'"`, `QA-3`), barra de filtros independiente con cascada zona/sede que limpia `location_id` al quedar fuera de zona, tarjetas KPI, 2 gráficos `window.Chart` (Mermas `€`/uds y Top 10 críticos Rotura/Caducidad) resolviendo colores por `getComputedStyle` con degradación limpia sin `window.Chart`, banner térmico no segmentable, 3 tablas con badges *«Baja»* y *«Planograma sobredimensionado»*, etiqueta `EXPORT_RESTOCKING_BI_CSV` y precache en `sw.js` (`v33`).
  - **Verificación prevista:** `node --test tests/frontend/restocking_bi_tab.test.mjs tests/unit/CoordinatorLoginShellGuardTest.mjs tests/unit/CoordinatorTriageLayoutGuardTest.mjs tests/unit/MobileShellLayoutGuardTest.mjs tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs`

- [ ] **T-RBI-07 · Prueba visual y responsiva en navegador Chromium (`360 × 800 px` y escritorio) (`RestockingBiLayoutBrowserTest.php`)**
  - **Requisitos:** `RF-06.1`, `RF-06.2`, `RNF-04`, `RNF-05`, `QA-3`.
  - **Alcance:** Renderizado real en Chromium contra servidor local: interacción de filtros, ausencia de barra global SAT duplicada, visualización de ambos `<canvas>` Chart.js, ausencia de errores de consola, disparo de descarga CSV autenticada y verificación estricta de `scrollWidth <= clientWidth` en `360 × 800 px`.
  - **Verificación prevista:** `php tests/integration/RestockingBiLayoutBrowserTest.php`

- [ ] **T-RBI-08 · Batería global, canal `new_engine`, matriz de trazabilidad y cierre documental**
  - **Requisitos:** `RNF-01`–`RNF-05`, `Art. I`, `Art. VI`.
  - **Alcance:** Ejecutar la batería global completa (`php tests/run_all.php`), validar el canal `new_engine` (`.env.docker-tls`), crear `specs/38-restocking-bi-analytics/traceability-matrix.md`, marcar la Feature 28 como implementada en `docs/features_pendientes.md` y verificar `DocMetricsGuard` y `PostgresSchemaTest` (51 tablas / 50 tipos `ENUM` intactos).
  - **Verificación prevista:** `php tests/run_all.php`
