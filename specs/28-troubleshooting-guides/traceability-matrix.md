# MATRIZ DE TRAZABILIDAD · MÓDULO 28

**Módulo:** 28 · Guías Rápidas de Diagnóstico por Modelo (*In-Situ Troubleshooting Playbooks*)  
**Especificación:** [`spec.md`](spec.md) (v2.0) · [`../functional/troubleshooting_guides_spec.md`](../functional/troubleshooting_guides_spec.md)  
**Contratos técnicos:** [`../technical/troubleshooting_guides_contracts.md`](../technical/troubleshooting_guides_contracts.md)  
**Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md)  
**Fecha de certificación:** 4 de noviembre de 2026

**Esquema de referencia:** `RF-01` emparejamiento por modelo y salvaguarda sanitaria · `RF-02` bloques, stock de furgoneta y causa raíz · `RF-03` paquete sin conexión y opacidad · `RF-04` gobierno y auditoría del catálogo · `RF-05` cobertura del parque y semillas reales.

**Corrida de certificación:** `php tests/run_all.php` certificó **397 suites · 14.812 aserciones · 0 fallos · 0 errores** el 4 de noviembre de 2026. La rama incorpora en la revisión del 5 de noviembre `TroubleshootingGuideEditorPartsTest.mjs`, trece aserciones ESM de regresión y tres de integración para `findByIds()`, por lo que la cifra vigente es **398 suites · 14.828 aserciones**, pendiente de la próxima corrida global. Las tres suites del módulo en el canal PostgreSQL `new_engine` certifican **3 suites · 106 aserciones · 0 fallos**. El repositorio no declara un script `composer test` ni contiene `composer.json`; el ejecutor nativo es la fuente de verdad del proyecto.

> **Nota de integración:** la tarea `T-GUIDE-13` nombraba `CoordinatorSparePartsView.js`, un fichero que no existe en este árbol. La sub-pestaña «📖 Guías de Diagnóstico» se integró en el contenedor real de sub-pestañas, `public/assets/js/views/CoordinatorDashboardView.js`, sin crear una vista paralela muerta.

---

## 1. Historias de Usuario

| Historia | Necesidad verificable | Artefactos que la realizan | Pruebas y evidencia |
| :--- | :--- | :--- | :--- |
| **HU-01** Consulta in situ con conmutador y sin cobertura | Guía exacta por modelo y categoría, conmutador de las guías del modelo, alerta sanitaria, códigos de display, pasos locales y lectura offline | `TroubleshootingGuideAccordion.js`; `TroubleshootingGuideService::getGuidesForModel`; `PdoMachineTroubleshootingGuideRepository::findActiveByModelKey`; `OfflineStorage.js::getOfflineTroubleshootingGuidesByModel`; `TechnicianRouteView.js`; `CoordinatorIncidentDetailModal.js` | `TroubleshootingGuideServiceIntegrationTest.php` (emparejamiento, conmutador y orden); `TroubleshootingGuideApiIntegrationTest.php` (`by-model` y paquete offline); `OfflinePwaAssetsAndUiGuardTest.mjs` (precaché y `Network-Only`); `node --check` de los módulos ES |
| **HU-02** Repuestos disponibles y causa raíz en un toque | Semáforo de stock de la furgoneta sin importes, orden de repuestos preservado y causa raíz sugerida aplicable sin autorrellenar diagnóstico ni acción | `TroubleshootingGuideService` (cruce de stock y `suggested_root_cause`); `TroubleshootingGuideAccordion.js`; `api.js`; `CoordinatorIncidentDetailModal.js` (conmutador de categorías y preselección); `TechnicianRouteView.js` | `TroubleshootingGuideServiceIntegrationTest.php` (saldos positivo/cero/negativo, sin costes y causa desactivada); `TroubleshootingGuideApiIntegrationTest.php` (respuesta HTTP sin importes); `TroubleshootingGuideValidatorTest.php` (`QA 2`); `node --check` de las vistas |
| **HU-03** Gobierno del catálogo y modelos huérfanos | CRUD auditado, baja/reactivación lógica, tres KPIs de cobertura inmunes al filtro, listado de huérfanos y semillas reales | `CoordinatorTroubleshootingGuidesTab.js`; `CoordinatorDashboardView.js`; `TroubleshootingGuideService`; `TroubleshootingGuideController`; `PdoMachineTroubleshootingGuideRepository::fetchFleetCoverageSummary`; `SeedRunner::seedTroubleshootingGuides`; migración `024` | `TroubleshootingGuideServiceIntegrationTest.php` (CRUD, auditoría y cobertura); `TroubleshootingGuideApiIntegrationTest.php` (RBAC, CRUD y KPIs); `PostgresTroubleshootingGuideSchemaTest.php`; `AuditActionCatalogTest.php`; guardas de diseño del panel |

---

## 2. Requisitos Funcionales y Criterios EARS

### RF-01 · Emparejamiento normalizado, conmutador y salvaguarda sanitaria

| Criterio | Artefacto de producción | Prueba automatizada | Resultado |
| :--- | :--- | :--- | :---: |
| **01.1** Emparejamiento normalizado y selección inicial por categoría (`D1`, `C5`) | `MachineTroubleshootingGuide::normalizeModelName` / `normalizeModelKey`; `TroubleshootingGuideService::getGuidesForModel`; `PdoMachineTroubleshootingGuideRepository::findActiveByModelKey`; `TroubleshootingGuideController::getByModel`; `TroubleshootingGuideAccordion.js` | `TroubleshootingGuideValidatorTest.php` (normalización); `TroubleshootingGuideServiceIntegrationTest.php` (§1); `TroubleshootingGuideApiIntegrationTest.php` (§2) | ✅ |
| **01.2** Conmutador «Ver todas las guías de este modelo», orden canónico y respaldo (`D1`, `QA 3`) | `TroubleshootingGuideService` (prioridad exacta y jerarquía `TEMPERATURE_COLD` → `PAYMENT_SYSTEM` → `PRODUCT_JAM` → `ELECTRICAL_OFF` → `OTHER`); `TroubleshootingGuideAccordion.js` (`vg-action-group`) | `TroubleshootingGuideServiceIntegrationTest.php` (§1); `TroubleshootingGuideApiIntegrationTest.php` (§2, modelo huérfano) | ✅ |
| **01.3** Alerta prioritaria de Seguridad Alimentaria (`Art. II`, `D4`) | `TroubleshootingGuideService` (`sanitary_cold_warning`, `sanitary_cold_warning_for_machine`); `TroubleshootingGuideAccordion.js` | `TroubleshootingGuideServiceIntegrationTest.php` (§2, frío y perecederos); `TroubleshootingGuideApiIntegrationTest.php` (§2) | ✅ |

### RF-02 · Bloques, stock de furgoneta y puente a Causa Raíz

| Criterio | Artefacto de producción | Prueba automatizada | Resultado |
| :--- | :--- | :--- | :---: |
| **02.1** Buscador de códigos y casillas locales de pasos (`D2`, `C6`) | `TroubleshootingGuideAccordion.js` (filtro de `error_codes`, pasos `1..N`, marcas locales por incidencia); `TroubleshootingGuideService` (pasos y códigos ordenados) | `TroubleshootingGuideApiIntegrationTest.php` (§2 devuelve códigos y pasos); `TroubleshootingGuideServiceIntegrationTest.php`; `OfflinePwaAssetsAndUiGuardTest.mjs`; `node --check` | ✅ |
| **02.2** Cruce de repuestos con la furgoneta sin importes (`D2`, `C2`, `QA 3`, `Art. V.4`) | `TroubleshootingGuideService` (orden preservado, `van_stock_quantity`, `has_van_stock`, `is_active`, sin costes); `PdoOfflineSyncMutationRepository`; `TroubleshootingGuideAccordion.js` | `TroubleshootingGuideServiceIntegrationTest.php` (§3); `TroubleshootingGuideApiIntegrationTest.php` (§2, `forbiddenKeys`); `TroubleshootingGuideValidatorTest.php` (repuestos activos y orden) | ✅ |
| **02.3** Causa Raíz en un toque, cruce de categoría e independencia del `Art. V.1` (`D2`, `C2`, `C4`, `QA 4`) | `TroubleshootingGuideService` (causa activa y solución predeterminada); `TroubleshootingGuideAccordion.js` (`apply-suggested-root-cause`); `TechnicianRouteView.js`; `CoordinatorIncidentDetailModal.js` (`showAllRootCauseCategories`, sin tocar `diagnosis`/`action_taken`) | `TroubleshootingGuideServiceIntegrationTest.php` (§5); `TroubleshootingGuideApiIntegrationTest.php`; `RootCauseTaxonomyFrontendAndPwaTest.mjs` (independencia y opacidad); `node --check` de las vistas | ✅ |

### RF-03 · Sin conexión y exclusión de superficies

| Criterio | Artefacto de producción | Prueba automatizada | Resultado |
| :--- | :--- | :--- | :---: |
| **03.1** Catálogo activo en el paquete local sin conexión (`D3`) | `PdoOfflineSyncMutationRepository::fetchBootstrapSnapshotForTechnician`; `OfflineBootstrapSnapshotDto`; `OfflineSyncService`; `OfflineStorage.js::getOfflineTroubleshootingGuidesByModel`; `public/sw.js` | `TroubleshootingGuideApiIntegrationTest.php` (§3, bootstrap con 8 guías y stock); `OfflinePwaAssetsAndUiGuardTest.mjs` (App Shell y `Network-Only`) | ✅ |
| **03.2** Solo correctivas, opacidad Sede/QR/Albarán (`D3`, `C4`, `Art. V.4`) | `TroubleshootingGuideController`; `AppRouter` (roles); ausencia del acordeón en `LocationPortalView`, `QrReportView`, `QrBatchPrintView` y `ServiceSheetModal` | `TroubleshootingGuideApiIntegrationTest.php` (§1, `401`/`403`); `RootCauseTaxonomyFrontendAndPwaTest.mjs` (§5); auditoría estática de superficies | ✅ |

### RF-04 · Gobierno y validaciones del catálogo

| Criterio | Artefacto de producción | Prueba automatizada | Resultado |
| :--- | :--- | :--- | :---: |
| **04.1** Unicidad e inmutabilidad de `Modelo + Categoría` (`C1`) | `TroubleshootingGuideValidator::validateCreate` / `validateUpdate`; `TroubleshootingGuideService::createGuide` / `updateGuide`; índice `uq_troubleshooting_guides_model_category`; `trg_immutable_machine_troubleshooting_guides` | `TroubleshootingGuideValidatorTest.php` (§7); `PostgresTroubleshootingGuideSchemaTest.php`; `TroubleshootingGuideServiceIntegrationTest.php` (§4); `TroubleshootingGuideApiIntegrationTest.php` (§4: `409` y `422 IMMUTABLE_GUIDE_IDENTITY`) | ✅ |
| **04.2** Cotas, afinidad de causa y rigor del contenido (`C2`, `C3`, `QA 2`, `Art. V.1`) | `TroubleshootingGuideValidator` (título, pasos, bloques alfanuméricos, códigos, repuestos y categoría de causa); `TroubleshootingGuideService::assertActiveSpareParts` / `resolveRootCause` | `TroubleshootingGuideValidatorTest.php` (§2–§6); `TroubleshootingGuideServiceIntegrationTest.php` (§4); `TroubleshootingGuideApiIntegrationTest.php` (§4: `422`) | ✅ |
| **04.3** Baja lógica, reactivación y auditoría antes/después (`Art. III.1`, `Art. III.3`, `D4`) | `TroubleshootingGuideService::setGuideActiveStatus`; `AuditLogger` bajo `MACHINE_TROUBLESHOOTING_GUIDE`; `trg_no_delete_machine_troubleshooting_guides`; `AuditActionLabels.js` | `PostgresTroubleshootingGuideSchemaTest.php` (DELETE bloqueado); `TroubleshootingGuideServiceIntegrationTest.php` (§4); `TroubleshootingGuideApiIntegrationTest.php` (§4); `AuditActionCatalogTest.php` | ✅ |

### RF-05 · Cobertura del parque y semillas reales

| Criterio | Artefacto de producción | Prueba automatizada | Resultado |
| :--- | :--- | :--- | :---: |
| **05.1** Tres KPIs inmunes al filtro y listado de huérfanos (`D4`, `QA 1`) | `PdoMachineTroubleshootingGuideRepository::fetchFleetCoverageSummary`; `TroubleshootingGuideService::listForCoordinator`; `CoordinatorTroubleshootingGuidesTab.js` (KPIs, filtros y botón por huérfano) | `TroubleshootingGuideServiceIntegrationTest.php` (§6, variantes, `Fas Perla` y filtros); `TroubleshootingGuideApiIntegrationTest.php` (§4, KPIs); `PostgresTroubleshootingGuideSchemaTest.php` (agrupación por clave) | ✅ |
| **05.2** Semillas reales del parque (`Art. I.3`, `D4`) | `database/supabase_seeds.sql` §20; `SeedRunner::seedTroubleshootingGuides`; migración `024` | `TroubleshootingGuideApiIntegrationTest.php` (8 guías sembradas); `TroubleshootingGuideServiceIntegrationTest.php` (modelos y huérfano real); `SeedDataTest.php` / `SeedRunnerSingleEngineTest.php` | ✅ |

---

## 3. Requisitos No Funcionales

| RNF | Compromiso | Artefacto | Evidencia |
| :--- | :--- | :--- | :--- |
| **RNF-01** Renderizado seguro sin inyección | Instrucciones y códigos como datos estructurados e interpolación de texto; sin `v-html`, sin Markdown externo | `TroubleshootingGuideAccordion.js`; `CoordinatorTroubleshootingGuidesTab.js`; `TroubleshootingGuideValidator` | `TroubleshootingGuideValidatorTest.php`; auditoría estática de plantillas; `node --check` |
| **RNF-02** Inmutabilidad histórica y auditoría | Sin borrado físico; alta, edición y cambio de estado con `previous_state` y `new_state`; disparadores y `ON DELETE RESTRICT` | Migración `024`; `supabase_init.sql`; `TroubleshootingGuideService::audit`; `AuditLogger`; `AuditActionLabels.js` | `PostgresTroubleshootingGuideSchemaTest.php`; `TroubleshootingGuideServiceIntegrationTest.php`; `TroubleshootingGuideApiIntegrationTest.php`; `AuditActionCatalogTest.php` |
| **RNF-03** Privacidad por rol y sistema de diseño | `401`/`403` en escritura y cobertura; `vg-btn`, cero `.btn*`, radios 4/8 px, cero sombras en reposo, tokens y cero importes al técnico | `TroubleshootingGuideController`; `AppRouter`; `TroubleshootingGuideAccordion.js`; `CoordinatorTroubleshootingGuidesTab.js`; `styles.css`; `sw.js` | `TroubleshootingGuideApiIntegrationTest.php` (§1); `DesignTokenDebtRatchetTest.mjs`; `LegacyButtonPaletteRetirementTest.mjs`; `CoordinatorButtonVariantsGuardTest.mjs`; `OfflinePwaAssetsAndUiGuardTest.mjs` |

---

## 4. Decisiones de Diseño (`D1`–`D4`)

| Decisión | Implementación | Pruebas |
| :--- | :--- | :--- |
| **D1** Pareja `Modelo + Categoría` con conmutador | Clave normalizada y única; `findActiveByModelKey`; selector `vg-action-group` del acordeón; preselección de la categoría reportada | `TroubleshootingGuideServiceIntegrationTest.php`; `TroubleshootingGuideApiIntegrationTest.php`; `TroubleshootingGuideValidatorTest.php` |
| **D2** Bloques estructurados, stock y causa raíz | `error_codes`, `steps`, repuestos ordenados, cruce con `technician_van_stocks`, causa y solución predeterminada | `TroubleshootingGuideServiceIntegrationTest.php` (§2–§3); `TroubleshootingGuideApiIntegrationTest.php`; `TroubleshootingGuideValidatorTest.php` |
| **D3** Ruta, detalle y paquete offline | Acordeón en `TechnicianRouteView.js` y `CoordinatorIncidentDetailModal.js`; `troubleshooting_guides` en el bootstrap; helper local `OfflineStorage.js`; precaché en `sw.js` | `TroubleshootingGuideApiIntegrationTest.php` (§3); `OfflinePwaAssetsAndUiGuardTest.mjs`; `node --check` |
| **D4** Gobierno, cobertura, alerta sanitaria y semillas | CRUD auditado en `CoordinatorTroubleshootingGuidesTab.js`; KPIs del repositorio; banner sanitario; 8 semillas reales y `Fas Perla` huérfano | `TroubleshootingGuideServiceIntegrationTest.php`; `TroubleshootingGuideApiIntegrationTest.php`; `PostgresTroubleshootingGuideSchemaTest.php`; `AuditActionCatalogTest.php` |

---

## 5. Casos Límite (`C1`–`C6`)

| Caso | Regla preservada | Implementación | Pruebas |
| :--- | :--- | :--- | :--- |
| **C1** Unicidad e identidad inmutable | Una guía por pareja, incluso inactiva; `409` al duplicar; modelo y categoría no editables | Índice único; `TroubleshootingGuideValidator::validateUpdate`; `TroubleshootingGuideService::createGuide`/`updateGuide`; disparador de inmutabilidad | `TroubleshootingGuideValidatorTest.php`; `TroubleshootingGuideServiceIntegrationTest.php`; `TroubleshootingGuideApiIntegrationTest.php`; `PostgresTroubleshootingGuideSchemaTest.php` |
| **C2** Pieza o causa desactivada después | La guía sigue leyéndose; la pieza se marca inactiva; la causa no se ofrece; la edición sólo exige actividad a lo que se vuelve a declarar | `TroubleshootingGuideService::getGuidesForModel` (marcas `is_active`); `updateGuide` (sólo valida campos enviados); `TroubleshootingGuideValidator::validateRootCause` | `TroubleshootingGuideServiceIntegrationTest.php` (§5); `TroubleshootingGuideApiIntegrationTest.php`; `TroubleshootingGuideValidatorTest.php` |
| **C3** Cotas y rigor del contenido | Título `5..150`; `2..10` pasos; instrucción `20..500` y ≥ 3 bloques; `0..20` códigos; `0..10` repuestos | `TroubleshootingGuideValidator`; restricciones `CHECK` de la Tabla 39; editor dinámico del panel | `TroubleshootingGuideValidatorTest.php`; `TroubleshootingGuideApiIntegrationTest.php`; `PostgresTroubleshootingGuideSchemaTest.php` |
| **C4** Carácter asistencial e independencia del `Art. V.1` | Marcar pasos o aplicar la causa no bloquea el cierre ni escribe en diagnóstico/acción | `TroubleshootingGuideAccordion.js` (estado local); `TechnicianRouteView.js` / `CoordinatorIncidentDetailModal.js` (sólo preselección de taxonomía) | `RootCauseTaxonomyFrontendAndPwaTest.mjs` (independencia Art. V.1); `node --check` de las vistas |
| **C5** Normalización insensible a mayúsculas y espacios | `TRIM` + colapso de espacios + `LOWER`; misma clave en PHP y PostgreSQL | `MachineTroubleshootingGuide::normalizeModelName`/`normalizeModelKey`; `fetchFleetCoverageSummary`; `SeedRunner` | `TroubleshootingGuideValidatorTest.php` (§1); `TroubleshootingGuideServiceIntegrationTest.php` (§1); `PostgresTroubleshootingGuideSchemaTest.php` |
| **C6** Casillas de pasos 100 % locales | Estado por incidencia en el dispositivo, sin petición ni mutación offline | `TroubleshootingGuideAccordion.js` (almacén local por `incident_id`) | Auditoría estática del componente; `OfflinePwaAssetsAndUiGuardTest.mjs` (sin tráfico de API en el shell); `node --check` |

---

## 6. Resoluciones de Auditoría QA (`QA 1`–`QA 4`) y Blindajes Constitucionales

| Resolución | Hallazgo que cierra | Blindaje verificado | Evidencia |
| :--- | :--- | :--- | :--- |
| **QA 1** Cobertura determinista e inmune al filtro | Universo de máquinas activas no eliminadas, agrupado por clave normalizada; tres KPIs globales y listado de huérfanos con acción rápida | `fetchFleetCoverageSummary`; `TroubleshootingGuideService::listForCoordinator`; `CoordinatorTroubleshootingGuidesTab.js`; `Fas Perla` sembrado sin guía | `TroubleshootingGuideServiceIntegrationTest.php` (§6); `TroubleshootingGuideApiIntegrationTest.php` (§4); `PostgresTroubleshootingGuideSchemaTest.php` |
| **QA 2** Afinidad de la Causa Raíz | Causa existente, activa y de la misma categoría o `OTHER`; rechazo `422 INVALID_GUIDE_ROOT_CAUSE` | `TroubleshootingGuideValidator::validateRootCause`; selector filtrado del panel; servicio resuelve la entidad antes de validar | `TroubleshootingGuideValidatorTest.php` (§6); `TroubleshootingGuideServiceIntegrationTest.php` (§4); `TroubleshootingGuideApiIntegrationTest.php` |
| **QA 3** Orden determinista del conmutador | Coincidencia exacta primero; después `TEMPERATURE_COLD` → `PAYMENT_SYSTEM` → `PRODUCT_JAM` → `ELECTRICAL_OFF` → `OTHER`; repuestos conservan el orden de Coordinación | `PdoMachineTroubleshootingGuideRepository::findActiveByModelKey`; `TroubleshootingGuideService::getGuidesForModel`; `validateSparePartIds` | `TroubleshootingGuideServiceIntegrationTest.php` (§1); `TroubleshootingGuideValidatorTest.php` (§5); `TroubleshootingGuideApiIntegrationTest.php` |
| **QA 4** Causa sugerida desde otra categoría | El modal activa `showAllRootCauseCategories` si la causa no pertenece a la categoría del aviso y preselecciona causa/solución sin autorrellenar | `CoordinatorIncidentDetailModal.js`; `TechnicianRouteView.js`; `TroubleshootingGuideAccordion.js` | `RootCauseTaxonomyFrontendAndPwaTest.mjs` (conmutador y no autorrelleno); `TroubleshootingGuideServiceIntegrationTest.php` (§5); `node --check` |
| **Art. II** Prioridad sanitaria | Banner de cadena de frío en guías `TEMPERATURE_COLD` y en máquinas `PERISHABLE_FOOD` | `TroubleshootingGuideService`; `TroubleshootingGuideAccordion.js`; semillas de refrigeración | `TroubleshootingGuideServiceIntegrationTest.php` (§2); `TroubleshootingGuideApiIntegrationTest.php` (§2) |
| **Art. III.1 / III.3** Sin borrado y auditoría completa | `trg_no_delete_machine_troubleshooting_guides`; auditoría `CREATE`/`UPDATE`/`ACTIVATE`/`DEACTIVATE` con antes/después | Migración `024`; `TroubleshootingGuideService`; `AuditLogger`; `AuditActionLabels.js` | `PostgresTroubleshootingGuideSchemaTest.php`; `TroubleshootingGuideServiceIntegrationTest.php`; `TroubleshootingGuideApiIntegrationTest.php`; `AuditActionCatalogTest.php` |
| **Art. V.1** Texto libre intacto y contenido real | La guía no escribe `diagnosis`/`action_taken`; instrucciones con ≥ 20 caracteres reales y ≥ 3 bloques | `TroubleshootingGuideValidator`; `TroubleshootingGuideAccordion.js`; vistas de resolución | `TroubleshootingGuideValidatorTest.php` (§3); `RootCauseTaxonomyFrontendAndPwaTest.mjs`; `RootCauseTaxonomyIntegrationTest.php` |
| **Art. V.4** Opacidad y separación de roles | Técnico ve stock sin costes; Sede/QR/A4 no reciben guías; escritura y cobertura sólo Coordinador | `TroubleshootingGuideService`; `TroubleshootingGuideController`; `AppRouter`; `TroubleshootingGuideAccordion.js` | `TroubleshootingGuideApiIntegrationTest.php` (§1–§3); `RootCauseTaxonomyFrontendAndPwaTest.mjs` (§5); `SiteManagerRouteDataSegregationTest.php` |

---

## 7. Inventario de Suites y Guardas del Módulo

| Suite / guarda | Tipo | Cobertura principal |
| :--- | :--- | :--- |
| `tests/unit/TroubleshootingGuideValidatorTest.php` | Unitaria PHP | Normalización `C5`, cotas `C3`, códigos/repuestos, afinidad `QA 2` e inmutabilidad `C1` |
| `tests/integration/PostgresTroubleshootingGuideSchemaTest.php` | Integración PostgreSQL (`new_engine`) | Tabla 39, 14 columnas, índices, claves foráneas, `CHECK`, unicidad, inmutabilidad y bloqueo de `DELETE` |
| `tests/integration/TroubleshootingGuideServiceIntegrationTest.php` | Integración PostgreSQL (`new_engine`) | Emparejamiento, orden `QA 3`, alerta sanitaria, stock sin costes, `C2`, CRUD auditado y cobertura `QA 1` |
| `tests/integration/TroubleshootingGuideApiIntegrationTest.php` | Integración HTTP (`new_engine`) | RBAC, `by-model`, bootstrap offline, CRUD, errores `404`/`409`/`422` y KPIs |
| `tests/integration/SchemaConvergenceParityTest.php` | Integración PostgreSQL (`new_engine`) | Paridad migración ↔ `supabase_init.sql`, Tabla 39 y migración `024` |
| `tests/integration/PostgresSchemaTest.php` | Integración PostgreSQL (`new_engine`) | Inventario canónico de 39 tablas |
| `tests/integration/PostgresConstitutionalTest.php` | Integración PostgreSQL (`new_engine`) | Barrido del Art. III.1 sobre las 39 tablas, incluida la Tabla 39, con una fila por tabla |
| `tests/unit/AuditActionCatalogTest.php` | Unitaria PHP | Acciones `*_TROUBLESHOOTING_GUIDE` y etiquetas del visor |
| `tests/unit/AuditEntityTypesTest.php` | Unitaria PHP | `MACHINE_TROUBLESHOOTING_GUIDE` en dominio y enum de auditoría |
| `tests/unit/DesignTokenDebtRatchetTest.mjs` | Guarda de diseño | Cero deuda de tokens; sin hexadecimales nuevos |
| `tests/unit/LegacyButtonPaletteRetirementTest.mjs` | Guarda de diseño | Cero clases `.btn*` |
| `tests/unit/CoordinatorButtonVariantsGuardTest.mjs` | Guarda de diseño | Escalera `vg-btn`, sin geometría/color inline en botones del panel |
| `tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs` | Guarda PWA | App Shell con los dos componentes, `Network-Only` y tokens del modo offline |
| `tests/unit/RootCauseTaxonomyFrontendAndPwaTest.mjs` | Unitaria ESM | Independencia del `Art. V.1`, conmutador de categorías y opacidad Sede/QR/A4 |
| `tests/unit/TroubleshootingGuideAccordionTest.mjs` | Unitaria ESM | Buscador `02.1.1`, casillas locales `C6`, stock `02.2`, causa `02.3`, orden `QA 3`, aviso `01.2.2`, banner sanitario `01.3` y rótulo de resultados `H-28-04` |
| `tests/unit/TroubleshootingGuideCoverageUiTest.mjs` | Unitaria ESM | Inmunidad de los 3 KPIs al filtrado (`QA 1`, `EARS 05.1`) y filtros delegados al servidor (`H-28-06`) |
| `tests/unit/TroubleshootingGuideUiContractTest.mjs` | Unitaria ESM | Estado informativo de `01.2.3` en la parada de ruta, montaje con aviso sanitario y su clase tokenizada |
| `tests/unit/TroubleshootingGuideEditorPartsTest.mjs` | Unitaria ESM | Edición con repuestos inactivos (`C2`, `H-28-03`): aviso, retirada explícita y omisión del campo sin cambios |
| `tests/unit/TroubleshootingGuideSurfacesGuardTest.mjs` | Guarda ESM | Handler `QA 4`, opacidad Sede/QR/A4 `03.2` y renderizado seguro `RNF-01` |
| `tests/unit/FunctionalSpecMirrorParityTest.php` | Guarda PHP | Paridad de versión, identificadores e historias entre spec canónico y espejo |
| `node --check` de `TroubleshootingGuideAccordion.js`, `CoordinatorTroubleshootingGuidesTab.js`, `TechnicianRouteView.js`, `CoordinatorIncidentDetailModal.js`, `api.js`, `OfflineStorage.js` y `sw.js` | Verificación estática | Sintaxis ESM y plantillas de las superficies del módulo |

---

## 8. Verificación Ejecutada

| Comprobación | Comando | Resultado |
| :--- | :--- | :--- |
| Batería hermética global | `php tests/run_all.php` | ✅ **397 suites · 14.812 aserciones · 0 fallos · exit 0** (corrida del 04-11-2026) |
| Canal PostgreSQL `new_engine` (suites del módulo) | `source deploy/local/postgres/.env.local && php tests/run_new_engine.php TroubleshootingGuide` | ✅ **3 suites · 106 aserciones · 0 fallos** |
| Blindaje constitucional de las 39 tablas | `php tests/integration/PostgresConstitutionalTest.php` | ✅ **76 aserciones · 0 fallos** |
| Esquema y paridad | suites `PostgresTroubleshootingGuideSchemaTest`, `SchemaConvergenceParityTest`, `PostgresSchemaTest` | ✅ |
| Contratos HTTP y RBAC | `TroubleshootingGuideApiIntegrationTest.php` | ✅ |
| Servicio, cobertura y auditoría | `TroubleshootingGuideServiceIntegrationTest.php` | ✅ |
| Validador y casos límite | `TroubleshootingGuideValidatorTest.php` | ✅ |
| Guardas de diseño y PWA | `DesignTokenDebtRatchetTest.mjs`, `LegacyButtonPaletteRetirementTest.mjs`, `CoordinatorButtonVariantsGuardTest.mjs`, `OfflinePwaAssetsAndUiGuardTest.mjs` | ✅ |
| Sintaxis de los módulos ES | `node --check` de las siete superficies listadas | ✅ |
| Hardening de los 7 huecos de auditoría | `TroubleshootingGuideAccordionTest.mjs`, `TroubleshootingGuideUiContractTest.mjs`, `TroubleshootingGuideSurfacesGuardTest.mjs`, `FunctionalSpecMirrorParityTest.php`, `TroubleshootingGuideCoverageUiTest.mjs` | ✅ **5 suites · 103 aserciones · 0 fallos** |
| Regresión de la auditoría M28 (`H-28-01` a `H-28-06`) | `node` sobre las cinco suites ESM del módulo | ✅ **5 suites · 109 aserciones · 0 fallos** (05-11-2026) |
| Lectura en lote de repuestos (`H-28-05`) | `php tests/integration/PdoSparePartRepositoryTest.php` (caso 10) | ⏳ **3 aserciones añadidas; no ejecutadas en este entorno** (sin motor de base de datos) |

## 9. Correspondencia de nombres (solicitados → reales)

| Nombre solicitado en la auditoría | Artefacto real en este repositorio |
| :--- | :--- |
| `SchemaCorrespondenceTest.php` | `SchemaConvergenceParityTest.php` + `PostgresSchemaTest.php` + `OpportunisticSchemaCorrespondenceTest.php` |
| `OfflinePwaAppShellContractTest.php` | `tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs` |
| `OfflinePwaSyncIntegrationTest.php` | `tests/integration/OfflinePwaSyncApiTest.php` |
| `LegacyButtonPaletteRetirementTest.php` | `tests/unit/LegacyButtonPaletteRetirementTest.mjs` |
| `DesignTokenDebtRatchetTest.php` | `tests/unit/DesignTokenDebtRatchetTest.mjs` |
| `composer test` | `php tests/run_all.php` (no existe `composer.json`) |
| `IncidentDetailModal.js` | `public/assets/js/components/CoordinatorIncidentDetailModal.js` |
| `CoordinatorSparePartsView.js` | `public/assets/js/views/CoordinatorDashboardView.js` (contenedor real de sub-pestañas) |
| `OfflineStorageService.js` | `public/assets/js/utils/OfflineStorage.js` |

## 10. Cierre de los huecos de la auditoría

| Hueco | Cierre verificado |
| :--- | :--- |
| `01.2.3` en ruta | `guide-empty-inline` tras resolución, en paradas asignadas o en curso, sin acciones |
| `01.2.2` sin categoría exacta | `guide-no-exact-category` en el acordeón, sólo si hay guías del modelo y ninguna de la categoría reportada |
| `01.3` / `05.1` verificación UI | Aserciones del banner sanitario y de los 3 KPIs de cobertura inmunes al filtro |
| `02.1.1` / `02.1.2` / `C6` | `TroubleshootingGuideAccordionTest.mjs` (buscador, persistencia local y ausencia de red) |
| `02.3` / `QA 4` | `TroubleshootingGuideSurfacesGuardTest.mjs` (conmutador y preselección sin autorrelleno) |
| `03.2` opacidad | Guarda sobre Sede, QR, impresión QR y Albarán A4 |
| `RNF-01` | Guarda de ausencia de `v-html`, `innerHTML` y Markdown |
| Deriva nominal | Spec y documentación usan «Fas Fast 900»; Módulo 25 intacto |
| Espejo funcional | Espejo completo restaurado y `FunctionalSpecMirrorParityTest.php` en verde |

**Limitación declarada:** el repositorio no contiene `composer.json`; `composer test` no está definido. La certificación se ejecutó con el runner nativo versionado (`tests/run_all.php`) y el canal PostgreSQL (`tests/run_new_engine.php`), que son los comandos documentados en `README.md`. No se sustituyó ninguna suite por una comprobación manual.

En la pasada completa del canal `new_engine` (37 suites) los 34 verdes iniciales incluían el fallo de inventario de `PostgresConstitutionalTest` (36 → 39 tablas), ya corregido y verificado en verde. Permanecen dos fallos **ajenos al Módulo 28 y al cambio**: `OfflineSyncLockBrowserTest` no consigue publicar DevTools con el Edge del puesto y `PoolerLatencyMeasurementTest` supera el presupuesto remoto de 250 ms. Se declaran como limitaciones de entorno, no como certificación del módulo.
