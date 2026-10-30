# MATRIZ DE TRAZABILIDAD · MÓDULO 25

**Módulo:** 25 · Taxonomía de Causas Raíz y Soluciones Estandarizadas (*Root Cause Taxonomy & Standardized Solutions*)  
**Especificación:** [`spec.md`](spec.md) (v2.1) · [`../functional/root_cause_taxonomy_spec.md`](../functional/root_cause_taxonomy_spec.md)  
**Contratos técnicos:** [`../technical/root_cause_taxonomy_contracts.md`](../technical/root_cause_taxonomy_contracts.md)  
**Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md)  
**Fecha de certificación:** 30 de octubre de 2026

**Esquema de referencia:** `RF-01` catálogo maestro · `RF-02` captura en resolución e independencia del Art. V.1 · `RF-03` reaperturas y modo sin conexión · `RF-04` expediente y privacidad por rol · `RF-05` Pareto 80/20 y exportación de garantías.

**Corrida de certificación:** batería hermética con el motor PostgreSQL 16 del arnés (`vendguard_battery`): **376 suites · 14.336 aserciones · 0 fallos · exit 0**. Canal `new_engine`: **32 suites en verde · 963 aserciones · 0 fallos · exit 0**. Las suites de esquema, integración HTTP y dialecto se ejecutan en el canal del motor real conforme a su contrato de entorno.

---

## 1. Historias de Usuario

| Historia | Necesidad verificable | Artefactos que la realizan | Pruebas de aceptación |
| :--- | :--- | :--- | :--- |
| **HU-01** Clasificación rápida en ruta y en oportunista | Selector de Causa Raíz priorizado por categoría + `OTHER`, conmutador «Mostrar todas las categorías», preselección 1-Tap de la solución predeterminada y texto libre intacto | `public/assets/js/views/TechnicianRouteView.js`; `api.getTechnicianRootCauses()`; `GET /api/technician/root-causes`; `TechnicianController`; `ResolutionTaxonomyValidator`; `RootCauseTaxonomyService` | `RootCauseTaxonomyFrontendAndPwaTest.mjs` (contratos 1-Tap, filtrado D3 y no autorrelleno); `TechnicianRouteViewTest.mjs`; `ResolutionTaxonomyValidatorTest.php`; `RootCauseTaxonomyIntegrationTest.php` (resolución técnica) |
| **HU-02** Pareto 80/20 por fabricante y modelo | Gráfico combinado de barras + curva acumulada + corte 80 %, filtros de fecha/categoría/fabricante/modelo/sede/técnico, MTTR y coste de repuestos, exportación CSV en dos secciones | `CoordinatorRootCauseParetoTab.js`; `RootCauseParetoService`; `PdoRootCauseParetoAnalyticsRepository`; `ManufacturerNormalizer`; `CoordinatorMetricsController`; `GET /api/coordinator/metrics/root-causes-pareto[/export]`; `chart.umd.min.js` local | `RootCauseParetoServiceTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` (Chart.js, filtro de fabricante, CSV y doble eje); `RootCauseTaxonomyIntegrationTest.php` (Pareto, filtro de fabricante, Zona Vital y BOM) |
| **HU-03** Gobernanza atómica del catálogo | Alta atómica causa + solución inicial predeterminada, inmutabilidad de `code`/`category`/`root_cause_id`, conmutación de predeterminada, guardas `409` al desactivar y auditoría antes/después | `RootCauseTaxonomyService`; `CoordinatorRootCauseController`; `CoordinatorRootCauseCatalogModal.js`; `PdoRootCauseRepository`; tablas 35 y 36 | `RootCauseTaxonomyServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php` (CRUD y guardas); `PostgresRootCauseTaxonomySchemaTest.php`; `AuditActionCatalogTest.php` |
| **HU-04** Trazabilidad ante reaperturas y conflictos offline | Copia congelada de los 5 `*_snapshot`, preservación del episodio previo en reaperturas `< 48 h`, catálogo en el paquete offline y bloque `offline_root_cause_classification` en `conflict_dump` | `Incident::applyResolutionTaxonomySnapshot`; `PdoIncidentRepository`; `OfflineBootstrapSnapshotDto`; `PdoOfflineSyncMutationRepository`; `OfflineSyncService`; `CoordinatorIncidentDetailDto`; `CoordinatorIncidentDetailModal.js` | `RootCauseTaxonomyIntegrationTest.php` (reapertura y snapshots); `OfflineSyncServiceTest.php`; `CoordinatorIncidentDetailDtoTest.php`; `OfflineStorageAndSyncManagerTest.mjs`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` |

---

## 2. Requisitos Funcionales y Criterios EARS

### RF-01 · Catálogo Maestro de Causas Raíz y Soluciones Estandarizadas

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **01.1** Alta atómica con solución predeterminada inicial | `RootCauseTaxonomyService::createRootCauseWithInitialSolution`; `PdoRootCauseRepository`; `021_root_cause_taxonomy.sql`; índice `idx_standard_solutions_one_default_per_cause` | `RootCauseTaxonomyServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php` (alta `201` y causa activa con predeterminada); `PostgresRootCauseTaxonomySchemaTest.php` | ✅ |
| **01.2** Conmutación atómica de la predeterminada | `RootCauseTaxonomyService::createStandardSolution` / `updateStandardSolution` / `designateDefaultSolution`; `clearActiveDefaultForCause()` | `RootCauseTaxonomyServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php`; `PostgresRootCauseTaxonomySchemaTest.php` | ✅ |
| **01.3** Guardas al desactivar y reactivar | `RootCauseTaxonomyService` (`CANNOT_DEACTIVATE_LAST_ACTIVE_SOLUTION`, `CANNOT_DEACTIVATE_DEFAULT_SOLUTION`, `CANNOT_UNSET_DEFAULT_WITHOUT_REPLACEMENT`, `CANNOT_REACTIVATE_ORPHAN_ROOT_CAUSE`); `CoordinatorRootCauseCatalogModal.js` | `RootCauseTaxonomyServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php` (`409`); `PostgresRootCauseTaxonomySchemaTest.php` (segunda predeterminada rechazada) | ✅ |
| **01.4** Inmutabilidad, baja lógica y auditoría antes/después | `RootCause` / `StandardSolution` inmutables; `assertCauseImmutability` / `assertSolutionImmutability`; `ON DELETE RESTRICT`; `AuditLogger` bajo `ROOT_CAUSE_TAXONOMY`; `AuditActionLabels.js` | `RootCauseTaxonomyServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php`; `PostgresRootCauseTaxonomySchemaTest.php` (`ON DELETE RESTRICT`); `AuditActionCatalogTest.php` | ✅ |

### RF-02 · Captura en la Resolución Técnica e Independencia del Artículo V.1

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **02.1** Obligatoriedad en `RESOLVED` y exención del cierre por 3.ª reincidencia | `ResolutionTaxonomyValidator`; `SparePartTraceabilityService::resolveIncidentWithConsumption`; `TechnicianController::resolveIncident`; `CoordinatorController::resolveIncident`; `TechnicianOpportunisticController` (exención del reemplazo crónico) | `ResolutionTaxonomyValidatorTest.php`; `RootCauseTaxonomyIntegrationTest.php` (técnico y coordinador `422`, exención crónica); `OpportunisticIncidentApiIntegrationTest.php` (4.11: predecesor crónico sin taxonomía inventada); `TechnicianRouteViewTest.mjs`; `SparePartsIntegrationComponentsTest.mjs` | ✅ |
| **02.2** Independencia estricta de los 20 caracteres | `ResolutionTaxonomyValidator` (no toca `diagnosis`/`action`); `ResolutionValidator`; `TechnicianRouteView.js` (selectores sin escritura en texto libre) | `ResolutionTaxonomyValidatorTest.php` (independencia Art. V.1); `RootCauseTaxonomyFrontendAndPwaTest.mjs`; `TechnicianRouteViewTest.mjs`; `RootCauseTaxonomyIntegrationTest.php` | ✅ |
| **02.3** Priorización por categoría + «Mostrar todas» | `TechnicianRouteView.js` (`resolutionCauseOptions`, conmutador `showAllCategories`); `CoordinatorIncidentDetailModal.js` | `RootCauseTaxonomyFrontendAndPwaTest.mjs`; `TechnicianRouteViewTest.mjs` | ✅ |
| **02.4** Preselección automática de la solución predeterminada | `TechnicianRouteView.js`; `CoordinatorIncidentDetailModal.js`; `api.getTechnicianRootCauses()` | `RootCauseTaxonomyFrontendAndPwaTest.mjs`; `TechnicianRouteViewTest.mjs` | ✅ |
| **02.5** Congelación de snapshots e inmutabilidad post-cierre | `Incident::applyResolutionTaxonomySnapshot`; `PdoIncidentRepository`; `SparePartTraceabilityService`; `CoordinatorIncidentDetailModal.js` | `RootCauseTaxonomyIntegrationTest.php`; `CoordinatorIncidentDetailDtoTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` | ✅ |

### RF-03 · Reaperturas en Garantía y Modo Sin Conexión

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **03.1** Actualización tras reapertura `< 48 h` con rastro | `PdoIncidentRepository` (historial del episodio previo); `Incident`; `SparePartTraceabilityService` | `RootCauseTaxonomyIntegrationTest.php`; `CoordinatorIncidentDetailDtoTest.php` | ✅ |
| **03.2** Catálogo en la copia local offline | `PdoOfflineSyncMutationRepository::fetchBootstrapSnapshotForTechnician`; `OfflineBootstrapSnapshotDto`; `OfflineStorage.js`; `TechnicianOfflineSyncController` | `OfflinePwaSyncApiTest.php`; `OfflineStorageAndSyncManagerTest.mjs`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` | ✅ |
| **03.3** Tolerancia en sincronización diferida | `ResolutionTaxonomyValidator` (`isOfflineDeferredSync`); `OfflineSyncService`; `PdoOfflineSyncMutationRepository` | `ResolutionTaxonomyValidatorTest.php`; `OfflineSyncServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php` | ✅ |
| **03.4** Preservación en `conflict_dump` | `OfflineSyncService::dumpConflict` (`offline_root_cause_classification`); `PdoOfflineSyncMutationRepository::recordConflictDump`; `OfflineSyncManager.js` | `OfflineSyncServiceTest.php`; `OfflinePwaSyncApiTest.php`; `OfflineStorageAndSyncManagerTest.mjs` | ✅ |

### RF-04 · Visibilidad en Expediente y Segregación de Privacidad por Rol

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **04.1** Visibilidad en expediente e historial | `CoordinatorIncidentDetailDto` (`root_cause_classification` y `timeline.previous_classifications`); `CoordinatorIncidentDetailService`; `ResolutionTaxonomyHistoryParser`; `CoordinatorIncidentDetailModal.js`; `TechnicianController::getMachineHistory` / `getIncidentDetail`; `TechnicianMachineHistoryModal.js` | `CoordinatorIncidentDetailDtoTest.php`; `CoordinatorIncidentDetailServiceTest.php` (cierres previos); `CoordinatorIncidentDetailModalTest.mjs`; `RootCauseTaxonomyIntegrationTest.php` (3.11.b y 3.11.c); `TechnicianMachineHistoryServiceTest.php`; `TechnicianMachineHistoryApiTest.php`; `TechnicianMachineHistoryModalTest.mjs`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` | ✅ |
| **04.2** Pareto, CSV y CRUD exclusivos de Coordinación | `CoordinatorRootCauseController`; `CoordinatorMetricsController`; `AppRouter`; `CoordinatorRootCauseParetoTab.js`; `CoordinatorRootCauseCatalogModal.js` | `RootCauseTaxonomyIntegrationTest.php` (`403` para técnico y sede); `RootCauseTaxonomyFrontendAndPwaTest.mjs` | ✅ |
| **04.3** Opacidad en Sede, QR y Albarán A4 | `LocationPortalController` (lista de claves internas excluidas); `LocationPortalView.js`; `QrReportView.js`; `ServiceSheetModal.js`; `QrBatchPrintView.js` | `RootCauseTaxonomyIntegrationTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs`; `SiteManagerPartsDataSegregationTest.php`/`SiteManagerRefundDataSegregationTest.php` (segregación Art. V.4 de la superficie de sede) | ✅ |

### RF-05 · Diagrama de Pareto 80/20 y Exportación para Garantías

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **05.1** Universo por `resolved_at`, voto único y atribución íntegra | `PdoRootCauseParetoAnalyticsRepository::fetchResolvedIncidentsForPareto`; `ParetoFilterDto`; `RootCauseParetoService` | `RootCauseParetoServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php` | ✅ |
| **05.2** Ordenación, 2 decimales, cierre en `100.00%` y Zona Vital incluyente | `RootCauseParetoService::buildParetoReport`; `RootCauseParetoReportDto` | `RootCauseParetoServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` | ✅ |
| **05.3** MTTR, repuestos y solución más frecuente con desempate | `RootCauseParetoService`; `PdoRootCauseParetoAnalyticsRepository`; `CoordinatorRootCauseParetoTab.js` | `RootCauseParetoServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php` | ✅ |
| **05.4** Filtros de periodo, fabricante normalizado, modelo y categoría | `ManufacturerNormalizer`; `ParetoFilterDto`; `CoordinatorRootCauseParetoTab.js` | `ManufacturerNormalizerTest.php`; `RootCauseParetoServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` | ✅ |
| **05.5** Gráfico combinado y estado vacío seguro | `CoordinatorRootCauseParetoTab.js` (Chart.js local, `getComputedStyle`); `chart.umd.min.js`; `public/sw.js` | `RootCauseTaxonomyFrontendAndPwaTest.mjs`; `RootCauseParetoServiceTest.php` (estado vacío) | ✅ |
| **05.6** CSV `UTF-8 BOM` en dos secciones auditado | `RootCauseParetoService::exportCsv`; `CoordinatorMetricsController::exportRootCausesParetoCsv`; `api.exportRootCausesParetoCsv` | `RootCauseParetoServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` | ✅ |

---

## 3. Requisitos No Funcionales

| RNF | Compromiso | Artefacto | Evidencia |
|---|---|---|---|
| **RNF-01** Autonomía de red y offline | Chart.js y catálogo servidos localmente; shell precacheado | `public/assets/js/vendor/chart.umd.min.js`; `public/sw.js` (`vendguard-app-shell-v3`); `OfflineStorage.js` | `RootCauseTaxonomyFrontendAndPwaTest.mjs` (precache y `Network-Only` de `/api/`); `OfflinePwaAssetsAndUiGuardTest.mjs` |
| **RNF-02** Fidelidad al sistema de diseño | Tokens CSS, tipografía dual, radios 4/8 px y objetivos táctiles ≥ 44 px; cero hexadecimales en JS | `public/assets/css/design-tokens.css`; `styles.css`; `CoordinatorRootCauseParetoTab.js`; `CoordinatorRootCauseCatalogModal.js`; `TechnicianRouteView.js` | `DesignTokenDebtRatchetTest.mjs`; `RootCauseTaxonomyFrontendAndPwaTest.mjs`; `DesignTokensTest.php` |
| **RNF-03** Rendimiento analítico | Agregación en SQL sin N+1; índice real `idx_incidents_pareto_resolved` sobre `(resolved_at, root_cause_id)` con predicado parcial | `PdoRootCauseParetoAnalyticsRepository`; `RootCauseParetoService`; `021_root_cause_taxonomy.sql`; `supabase_init.sql` | `RootCauseParetoPerformanceTest.php` (10.000 averías y 20.000 repuestos; mediana **170,9 ms** en tres muestras y **4 sentencias SQL** por informe); `PostgresRootCauseTaxonomySchemaTest.php` (definición completa del índice en el catálogo vivo); `RootCauseTaxonomyIntegrationTest.php` (consulta completa por la API) |

---

## 4. Decisiones de Diseño (`D1`–`D6`)

| Decisión | Implementación | Pruebas |
|---|---|---|
| **D1** Catálogo 1:N con predeterminada y preselección | Tablas 35/36; índice parcial único; `RootCauseTaxonomyService`; selectores encadenados en `TechnicianRouteView.js` y `CoordinatorIncidentDetailModal.js` | `PostgresRootCauseTaxonomySchemaTest.php`; `RootCauseTaxonomyServiceTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs`; `TechnicianRouteViewTest.mjs` |
| **D2** Obligatoriedad online, independencia de 20 caracteres y tolerancia offline | `ResolutionTaxonomyValidator`; `ResolutionValidator`; `OfflineSyncService`; `SparePartTraceabilityService` | `ResolutionTaxonomyValidatorTest.php`; `OfflineSyncServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php` |
| **D3** Priorización por categoría + «Mostrar todas» + administración | `TechnicianRouteView.js`; `CoordinatorRootCauseCatalogModal.js`; `CoordinatorRootCauseController` | `RootCauseTaxonomyFrontendAndPwaTest.mjs`; `RootCauseTaxonomyIntegrationTest.php` |
| **D4** Segregación total frente a Sede/QR/A4 | `LocationPortalController`; `LocationPortalView.js`; `QrReportView.js`; `ServiceSheetModal.js`; `QrBatchPrintView.js` | `RootCauseTaxonomyIntegrationTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` |
| **D5** Pareto con fabricante/modelo, MTTR, repuestos y CSV | `RootCauseParetoService`; `PdoRootCauseParetoAnalyticsRepository`; `CoordinatorRootCauseParetoTab.js`; CSV en dos secciones | `RootCauseParetoServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` |
| **D6** Gráficos autónomos sin red | Chart.js 4.x en `/public/assets/js/vendor/chart.umd.min.js`; enlace en `index.html`; precache en `sw.js` | `RootCauseTaxonomyFrontendAndPwaTest.mjs`; `OfflinePwaAssetsAndUiGuardTest.mjs` |

---

## 5. Casos Límite (`C1`–`C6`)

| Caso | Regla preservada | Implementación | Pruebas |
|---|---|---|---|
| **C1** Reapertura `< 48 h` y voto único | El cierre vigente aporta un único voto al Pareto; el episodio previo queda en historial y los snapshots se actualizan sin perder el rastro | `PdoIncidentRepository`; `SparePartTraceabilityService`; `RootCauseParetoService` | `RootCauseTaxonomyIntegrationTest.php`; `RootCauseTaxonomyServiceTest.php`; `CoordinatorIncidentDetailDtoTest.php` |
| **C2** Guardas de desactivación | `409` al desactivar la última activa o la predeterminada sin relevo; `422` si falta la pareja | `RootCauseTaxonomyService`; `CoordinatorRootCauseCatalogModal.js` | `RootCauseTaxonomyServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php`; `PostgresRootCauseTaxonomySchemaTest.php` |
| **C3** Histórico sin clasificar y Zona Vital inclusiva | Porcentajes sobre clasificadas; contador separado de huérfanas; corte en el primer acumulado ≥ `80.00%` inclusive | `RootCauseParetoService`; `RootCauseParetoReportDto` | `RootCauseParetoServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php` |
| **C4** Código/categoría inmutables y renombrados | `422 TAXONOMY_IMMUTABLE_FIELD_MODIFICATION`; snapshots congelados; Pareto con nombre vigente | `RootCauseTaxonomyService`; `Incident`; `PdoIncidentRepository`; `RootCauseParetoService` | `RootCauseTaxonomyServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php`; `RootCauseParetoServiceTest.php` |
| **C5** Correctivas y oportunistas, `conflict_dump` y preventivas fuera | Selector obligatorio en resoluciones correctivas; volcado con clasificación; preventivas sin selector | `TechnicianController`; `CoordinatorController`; `OfflineSyncService`; `TechnicianRouteView.js` | `RootCauseTaxonomyIntegrationTest.php`; `OfflineSyncServiceTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` |
| **C6** Filtro dual, desempate determinista y estado vacío | Fabricante normalizado + modelo exacto; `count DESC, is_default DESC, code ASC`; totales a cero sin división por cero | `ManufacturerNormalizer`; `RootCauseParetoService`; `PdoRootCauseParetoAnalyticsRepository` | `ManufacturerNormalizerTest.php`; `RootCauseParetoServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php` |

---

## 6. Resoluciones de Auditoría QA (`QA 1`–`QA 5`) y Blindajes Constitucionales

| Resolución | Hallazgos que cierra | Blindaje verificado | Evidencia |
|---|---|---|---|
| **QA 1** | 2, 6, 7, 8, 13 | Alta atómica con predeterminada, inmutabilidad de código/categoría, guardas de reactivación y auditoría antes/después bajo `ROOT_CAUSE_TAXONOMY` | `RootCauseTaxonomyServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php`; `AuditActionCatalogTest.php`; `PostgresRootCauseTaxonomySchemaTest.php` |
| **QA 2** | 3, 4, 5, 11 | Filtro por `resolved_at` vigente, atribución íntegra del expediente, cierre exacto en `100.00%` y CSV con nombre vigente + congelado | `RootCauseParetoServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` |
| **QA 3** | 9, 10 | Obligatoriedad en todo paso a `RESOLVED` de técnico y coordinador, exención del cierre por reemplazo en 3.ª reincidencia sin taxonomía inventada e inmutabilidad post-cierre | `ResolutionTaxonomyValidatorTest.php`; `RootCauseTaxonomyIntegrationTest.php`; `OpportunisticIncidentApiIntegrationTest.php` (4.11); `TechnicianRouteViewTest.mjs`; `SparePartsIntegrationComponentsTest.mjs` |
| **QA 4** | 1 | Fabricante determinista en Formato Título y filtro por modelo acotado | `ManufacturerNormalizerTest.php`; `RootCauseParetoServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` |
| **QA 5** | 12 | CRUD, Pareto, costes y CSV exclusivos de `COORDINATOR`; `403` a técnico y sede; opacidad total en Sede/QR/A4 | `RootCauseTaxonomyIntegrationTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs`; `SiteManagerRouteDataSegregationTest.php` |
| **Art. III.1 / III.3** | Borrado físico y auditabilidad | `ON DELETE RESTRICT` en ambas tablas; soft-delete; snapshots congelados; auditoría con estado anterior y nuevo | `PostgresRootCauseTaxonomySchemaTest.php`; `RootCauseTaxonomyServiceTest.php`; `RootCauseTaxonomyIntegrationTest.php` |
| **Art. V.1** | Justificación real | Los selectores no escriben `resolution_diagnosis` ni `resolution_action`; cada campo conserva su mínimo independiente de 20 caracteres reales | `ResolutionTaxonomyValidatorTest.php`; `RootCauseTaxonomyIntegrationTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` |
| **Art. V.4** | Fuga de datos internos | Lista de claves internas excluidas en el portal de sede; 0 campos y 0 etiquetas en QR/A4 | `RootCauseTaxonomyIntegrationTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` |
| **Art. IV.3 / D6** | Dependencia de red | Chart.js 4.x local; precacheado en `sw.js`; `/api/` sigue `Network-Only` | `RootCauseTaxonomyFrontendAndPwaTest.mjs`; `OfflinePwaAssetsAndUiGuardTest.mjs` |

---

## 7. Inventario de Suites del Módulo

| Suite | Tipo | Cobertura principal |
|---|---|---|
| `tests/integration/PostgresRootCauseTaxonomySchemaTest.php` | Integración PostgreSQL | Tablas 35/36, índice de predeterminada única, coherencia todo-o-nada de snapshots, `ON DELETE RESTRICT` e índice real `idx_incidents_pareto_resolved` |
| `tests/integration/RootCauseParetoPerformanceTest.php` | Integración PostgreSQL | RNF-03: 10.000 averías con 20.000 repuestos, mediana < 500 ms y ≤ 4 sentencias SQL por informe |
| `tests/unit/ResolutionTaxonomyValidatorTest.php` | Unitaria PHP | Presencia, pertenencia, inactividad online, tolerancia offline e independencia Art. V.1 |
| `tests/unit/ManufacturerNormalizerTest.php` | Unitaria PHP | Extracción determinista y Formato Título (QA 4) |
| `tests/unit/RootCauseTaxonomyServiceTest.php` | Unitaria PHP | Alta atómica, guardas `409`/`422`, conmutación y auditoría (QA 1, C2, C4) |
| `tests/unit/RootCauseParetoServiceTest.php` | Unitaria PHP | 80/20, redondeo y cierre `100.00%`, desempates, estado vacío y CSV BOM (QA 2, C3, C6) |
| `tests/integration/RootCauseTaxonomyIntegrationTest.php` | Integración HTTP | CRUD, resolución técnica/coordinador, snapshots, reapertura, offline, Pareto, CSV y `403` (QA 1–QA 5) |
| `tests/unit/RootCauseTaxonomyFrontendAndPwaTest.mjs` | Unitaria ESM | Selector 1-Tap, filtros D3, Chart.js local, precache, CSV y opacidad de Sede/QR/A4 |
| `tests/unit/OfflineSyncServiceTest.php` | Unitaria PHP | Tolerancia `EARS 03.3` y `conflict_dump` (C5) |
| `tests/integration/OfflinePwaSyncApiTest.php` | Integración HTTP | Bootstrap con `root_cause_taxonomy` y resolución offline |
| `tests/unit/OfflineStorageAndSyncManagerTest.mjs` | Unitaria ESM | Persistencia de taxonomía en `IndexedDB` y transporte en `RESOLVE_INCIDENT` |
| `tests/unit/CoordinatorIncidentDetailDtoTest.php` | Unitaria PHP | `root_cause_classification` y snapshots en expediente |
| `tests/unit/CoordinatorIncidentDetailServiceTest.php` | Unitaria PHP | Reconstrucción de cierres previos preservados en la línea de tiempo (`C1`, EARS 04.1) |
| `tests/unit/CoordinatorIncidentDetailModalTest.mjs` | Unitaria ESM | Hitos de cierre anterior y etiqueta congelada de causa/solución |
| `tests/unit/TechnicianMachineHistoryServiceTest.php` | Unitaria PHP | Historial de máquina con snapshots congelados y sin datos privados |
| `tests/integration/TechnicianMachineHistoryApiTest.php` | Integración HTTP | Historial y detalle read-only del técnico, autorización (vigente e histórica) y privacidad Art. V.4 |
| `tests/unit/TechnicianMachineHistoryModalTest.mjs` | Unitaria ESM | Insignia de clasificación congelada y detalle ampliable del expediente |
| `tests/unit/AuditActionCatalogTest.php` | Unitaria PHP | Catálogo de acciones y etiquetas de auditoría del visor |
| `tests/unit/DesignTokenDebtRatchetTest.mjs` | Guarda de diseño | Cero deuda de tokens y radios/objetivos táctiles |
| `tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs` | Guarda PWA | App Shell, Chart.js precacheado y `Network-Only` |
| `tests/integration/SchemaConvergenceParityTest.php` | Integración PostgreSQL | Paridad migración ↔ `supabase_init.sql` |
| `tests/unit/SqlDialectPortabilityTest.php` | Guarda de dialecto | Conformidad del adaptador con las reglas de upsert de las semillas |

---

## 8. Verificación Ejecutada

| Comprobación | Comando | Resultado |
|---|---|---|
| Esquema PostgreSQL | `php tests/integration/PostgresRootCauseTaxonomySchemaTest.php` | ✅ 100 % en verde |
| Validador puro | `php tests/unit/ResolutionTaxonomyValidatorTest.php` | ✅ 100 % en verde |
| Normalizador | `php tests/unit/ManufacturerNormalizerTest.php` | ✅ 100 % en verde |
| Servicio de catálogo | `php tests/unit/RootCauseTaxonomyServiceTest.php` | ✅ 100 % en verde |
| Motor Pareto | `php tests/unit/RootCauseParetoServiceTest.php` | ✅ 100 % en verde |
| Integración HTTP | `php tests/integration/RootCauseTaxonomyIntegrationTest.php` | ✅ 100 % en verde (66 aserciones) |
| Rendimiento del Pareto | `php tests/integration/RootCauseParetoPerformanceTest.php` | ✅ **11 aserciones · mediana 170,9 ms · 3×4 sentencias SQL** |
| Detalle read-only del técnico | `php tests/integration/TechnicianMachineHistoryApiTest.php` | ✅ 100 % en verde (23 aserciones) |
| Canal del motor nuevo | `php tests/run_new_engine.php` | ✅ **32/32 suites · 963 aserciones · 0 no ejecutables · exit 0** |
| Frontend y PWA | `node tests/unit/RootCauseTaxonomyFrontendAndPwaTest.mjs` | ✅ 100 % en verde |
| Guarda de diseño | `php tests/unit/DesignTokenDebtRatchetTest.php` | ✅ 100 % en verde |
| Guarda de dialecto | `php tests/unit/SqlDialectPortabilityTest.php` | ✅ 100 % en verde |
| Regresión completa | `php tests/run_all.php` | ✅ **376 suites · 14.336 aserciones · 0 fallos · exit 0** |

---

**Cierre del módulo:** todos los requisitos `RF-01`–`RF-05`, `RNF-01`–`RNF-03`, decisiones `D1`–`D6`, casos límite `C1`–`C6` y resoluciones `QA 1`–`QA 5` quedan vinculados a código y a pruebas automatizadas. La Feature 11 se declara **100 % implementada** y el módulo queda cerrado en su Fase 7.
