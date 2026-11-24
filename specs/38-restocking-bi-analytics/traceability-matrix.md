# MATRIZ DE TRAZABILIDAD · MÓDULO 38

**Especificación:** [`spec.md`](spec.md) (v1.1 · `D1`–`D5`, `C1`–`C6` y `QA-1`–`QA-5`) · **Contratos:** [`../technical/restocking_bi_analytics_contracts.md`](../technical/restocking_bi_analytics_contracts.md) · **Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md) · **ODD:** [`../../odd/tasks/restocking-bi-analytics.md`](../../odd/tasks/restocking-bi-analytics.md)

**Esquema de referencia canónico:** `RF-01` filtrado, ventana `Europe/Madrid` y cero DDL · `RF-02` disponibilidad dual y segregación térmica · `RF-03` comparativa de mermas comercial vs. APPCC · `RF-04` planograma sobredimensionado · `RF-05` rendimiento por reponedor, L1 y arqueos · `RF-06` sub-pestaña Vue 3, Chart.js y CSV autenticado.

**Corrida de certificación:** 2026-11-24 · batería global **453 suites · 17.822 aserciones · 0 fallos** (`DocMetricsGuard` coherente) · canal `new_engine`: **78 evaluadas · 78 verdes · 2.718 aserciones · 0 fallos** · PostgreSQL 16.15 local con TLS (`vendguard_battery` y `vendguard_pg_test`).

**Estado:** ✅ Módulo 38 cerrado al 100 % · **8/8 tareas** (`T-RBI-01` a `T-RBI-08`) · **7 suites propias con 500 aserciones verdes** + regresiones de esquema, API, PWA y diseño · **5/5 DoD** · esquema intacto en **51 tablas / 50 tipos `ENUM` / 56 columnas `ENUM` / 34 migraciones** · `ExecutiveReportModal.js`, `CoordinatorDashboardView.js`, `CoordinatorPlanogramTab.js` y consolas M33/M37 sin cambios.

---

## 1. Trazabilidad de Requisitos Funcionales

| Requisito | Criterios EARS | Implementación | Evidencia verificada |
| :--- | :--- | :--- | :--- |
| **RF-01** · Filtrado, fechas de corte, validación e históricos | `01.1` ventana de 30 días `Europe/Madrid` y fechas de corte por hecho; `01.2` par obligatorio, formato/fecha estricta, `from <= to`, techo de 366 días, `to > today` admitido e IDs `>= 1`; `01.3` bajas lógicas, `available_filters`, nulabilidad y snapshots; `01.4` periodo vacío con `200 OK` y tasas en `null` | `RestockingBiFilterValidator`, `RestockingBiFilterDto`, `PdoRestockingBiAnalyticsRepository`, `CoordinatorMetricsController`, `api.js` | `RestockingBiDomainTest` **250/250**; `PdoRestockingBiAnalyticsRepositoryTest` **72/72**; `RestockingBiServiceTest` **32/32**; `RestockingBiApiTest` **23/23**; `exit=0` |
| **RF-02** · Disponibilidad dual y segregación sanitaria | `02.1` visitas `COMPLETED`, cierre exacto al `100,00 %` y visitas sin líneas como *In-Stock*; `02.2` carriles auditados, roturas y bajo umbral con guarda de producto; `02.3` `ABORTED_THERMAL_BREACH` fuera del denominador | `RestockingBiCalculator`, `PdoRestockingBiAnalyticsRepository`, `RestockingBiAnalyticsService`, `CoordinatorRestockingBiTab.js` | `RestockingBiDomainTest` **250/250**; `PdoRestockingBiAnalyticsRepositoryTest` **72/72**; `RestockingBiServiceTest` **32/32**; `RestockingBiConstitutionalTest` **38/38**; `exit=0` |
| **RF-03** · Mermas comercial FEFO/dañado vs. térmica APPCC | `03.1` desglose exacto con `unit_cost_snapshot_eur`; `03.2` bloqueo `NOT_SEGMENTABLE_BY_RESTOCKER`, aviso canónico y prioridad sanitaria en `by_machine` | `RestockingBiCalculator`, `PdoRestockingBiAnalyticsRepository`, `RestockingBiExportService`, `CoordinatorRestockingBiTab.js` | `RestockingBiDomainTest` **250/250**; `RestockingBiServiceTest` **32/32**; `PdoRestockingBiAnalyticsRepositoryTest` **72/72**; `RestockingBiConstitutionalTest` **38/38**; `restocking_bi_tab.test.mjs` **51/51**; `exit=0` |
| **RF-04** · Merchandising y planograma sobredimensionado | `04.1` tasa FEFO excluyendo dañados y `null` sin denominador; `04.2` insignia `>= 15,00 %`, `>= 2` visitas y `>= 3` caducadas, con recuento exacto en `summary` | `RestockingBiCalculator`, `RestockingBiReportDto`, `CoordinatorRestockingBiTab.js` | `RestockingBiDomainTest` **250/250**; `RestockingBiServiceTest` **32/32**; `RestockingBiLayoutBrowserTest` **34/34**; `exit=0` |
| **RF-05** · Rendimiento por reponedor, L1 y arqueos | `05.1` inclusión territorial y `machines_per_shift` con sus dos motivos nulos; `05.2` turnos válidos sin anulados; `05.3` duración real de parada, L1 efectivas, escalados y exactitud de arqueos | `RestockingBiCalculator`, `PdoRestockingBiAnalyticsRepository`, `RestockingBiAnalyticsService` | `RestockingBiDomainTest` **250/250**; `PdoRestockingBiAnalyticsRepositoryTest` **72/72**; `RestockingBiServiceTest` **32/32**; `RestockingBiApiTest` **23/23**; `exit=0` |
| **RF-06** · Sub-pestaña Vue 3, Chart.js y CSV autenticado | `06.1` pestaña propia, barra única (`QA-3`), filtros en cascada y `360 × 800`; `06.2` dos gráficos con degradación sin `window.Chart` y tres tablas; `06.3` CSV de cinco secciones, BOM, anti-fórmulas y auditoría | `CoordinatorRestockingBiTab.js`, `CoordinatorMetricsView.js`, `AuditActionLabels.js`, `api.js`, `public/sw.js`, `RestockingBiExportService` | `restocking_bi_tab.test.mjs` **51/51**; `RestockingBiLayoutBrowserTest` **34/34** en Chromium real; `RestockingBiApiTest` **23/23**; `RestockingBiConstitutionalTest` **38/38**; `exit=0` |

### 1.1. Mapa de suites propias y regresiones directas

| Suite | Ruta | Recuento |
| :--- | :--- | ---: |
| `RestockingBiDomainTest` | [`tests/unit/RestockingBiDomainTest.php`](../../tests/unit/RestockingBiDomainTest.php) | 250/250 |
| `PdoRestockingBiAnalyticsRepositoryTest` | [`tests/integration/PdoRestockingBiAnalyticsRepositoryTest.php`](../../tests/integration/PdoRestockingBiAnalyticsRepositoryTest.php) | 72/72 |
| `RestockingBiServiceTest` | [`tests/unit/RestockingBiServiceTest.php`](../../tests/unit/RestockingBiServiceTest.php) | 32/32 |
| `RestockingBiApiTest` | [`tests/integration/RestockingBiApiTest.php`](../../tests/integration/RestockingBiApiTest.php) | 23/23 |
| `RestockingBiConstitutionalTest` | [`tests/integration/RestockingBiConstitutionalTest.php`](../../tests/integration/RestockingBiConstitutionalTest.php) | 38/38 |
| `restocking_bi_tab.test.mjs` | [`tests/frontend/restocking_bi_tab.test.mjs`](../../tests/frontend/restocking_bi_tab.test.mjs) | 51/51 |
| `RestockingBiLayoutBrowserTest` | [`tests/integration/RestockingBiLayoutBrowserTest.php`](../../tests/integration/RestockingBiLayoutBrowserTest.php) | 34/34 · Chromium real `360 × 800` y `1280 × 900` |
| `PostgresSchemaTest` | [`tests/integration/PostgresSchemaTest.php`](../../tests/integration/PostgresSchemaTest.php) | 159/159 |
| `PostgresConstitutionalTest` | [`tests/integration/PostgresConstitutionalTest.php`](../../tests/integration/PostgresConstitutionalTest.php) | 88/88 |
| `SchemaConvergenceParityTest` | [`tests/integration/SchemaConvergenceParityTest.php`](../../tests/integration/SchemaConvergenceParityTest.php) | 42/42 |
| `AuditActionCatalogTest` | [`tests/unit/AuditActionCatalogTest.php`](../../tests/unit/AuditActionCatalogTest.php) | 27/27 |
| `ConstitutionalAuditTest` | [`tests/unit/ConstitutionalAuditTest.php`](../../tests/unit/ConstitutionalAuditTest.php) | 44/44 |
| `OfflinePwaAssetsAndUiGuardTest` | [`tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs`](../../tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs) | 45/45 |
| `CoordinatorLoginShellGuardTest` | [`tests/unit/CoordinatorLoginShellGuardTest.mjs`](../../tests/unit/CoordinatorLoginShellGuardTest.mjs) | 6/6 |
| `CoordinatorTriageLayoutGuardTest` | [`tests/unit/CoordinatorTriageLayoutGuardTest.mjs`](../../tests/unit/CoordinatorTriageLayoutGuardTest.mjs) | 6/6 |
| `MobileShellLayoutGuardTest` | [`tests/unit/MobileShellLayoutGuardTest.mjs`](../../tests/unit/MobileShellLayoutGuardTest.mjs) | 7/7 |
| `FrontendTemplateCompileTest` | [`tests/unit/FrontendTemplateCompileTest.mjs`](../../tests/unit/FrontendTemplateCompileTest.mjs) | 15/15 |
| `DesignTokenDebtRatchetTest` | [`tests/unit/DesignTokenDebtRatchetTest.mjs`](../../tests/unit/DesignTokenDebtRatchetTest.mjs) | 3/3 |
| `NewEngineChannelDocumentationTest` | [`tests/unit/NewEngineChannelDocumentationTest.php`](../../tests/unit/NewEngineChannelDocumentationTest.php) | 9/9 |

---

## 2. Trazabilidad de Requisitos No Funcionales

| Requisito | Regla verificada | Evidencia |
| :--- | :--- | :--- |
| **RNF-01** · Cero evolución DDL e integridad relacional | 51 tablas, 50 tipos `ENUM`, 56 columnas `ENUM`, 34 migraciones, cero DDL en fuentes, baja lógica preservada y auditoría append-only | `PostgresSchemaTest` **159/159**; `PostgresConstitutionalTest` **88/88**; `SchemaConvergenceParityTest` **42/42**; `RestockingBiConstitutionalTest` **38/38** |
| **RNF-02** · Eficiencia SQL y cero producto cartesiano | Consultas agregadas independientes por fuente, guarda `mps.product_id = mvi.product_id`, cruce en memoria PHP sin N+1 | `PdoRestockingBiAnalyticsRepositoryTest` **72/72**; `RestockingBiDomainTest` **250/250** |
| **RNF-03** · RBAC, opacidad económica y error seguro | `401`/`403`, rutas ausentes en Sede/QR/público, `500` sin trazas, descarga solo por `fetch` + `Blob` y opacidad de costes | `RestockingBiApiTest` **23/23**; `RestockingBiConstitutionalTest` **38/38**; `RestockingBiLayoutBrowserTest` **34/34** |
| **RNF-04** · Dogma Vanilla y conformidad visual | PHP 8.2+ estricto, PDO puro, Vue 3 ESM, Chart.js reutilizado, cero hex en JS, tokens por `getComputedStyle`, `360 × 800` sin scroll horizontal | `restocking_bi_tab.test.mjs` **51/51**; `RestockingBiLayoutBrowserTest` **34/34**; `DesignTokenDebtRatchetTest` **3/3**; `FrontendTemplateCompileTest` **15/15**; `OfflinePwaAssetsAndUiGuardTest` **45/45** |
| **RNF-05** · Trazabilidad y cobertura de certificación | 7 suites propias, 500 aserciones, batería global y canal `new_engine` en verde, matriz viva y guardas documentales | `php tests/run_all.php` y `php tests/run_new_engine.php` · **453/453** y **78/78** suites · `exit=0` |

---

## 3. Decisiones, Casos Límite, Resoluciones QA y Artículos Constitucionales

| Referencia | Decisión o caso | Cobertura |
| :--- | :--- | :--- |
| **D1** | Disponibilidad dual por visita `COMPLETED` y desglose por carril | `RestockingBiDomainTest` **250/250**; `PdoRestockingBiAnalyticsRepositoryTest` **72/72**; `RestockingBiServiceTest` **32/32** |
| **D2** | Merma comercial vs. térmica APPCC y bloqueo por reponedor | `RestockingBiDomainTest` **250/250**; `PdoRestockingBiAnalyticsRepositoryTest` **72/72**; `RestockingBiServiceTest` **32/32**; `RestockingBiApiTest` **23/23** |
| **D3** | Insignia determinista de planograma sobredimensionado | `RestockingBiDomainTest` **250/250**; `RestockingBiServiceTest` **32/32**; `RestockingBiLayoutBrowserTest` **34/34** |
| **D4** | Sub-pestaña propia en Coordinación y barra global oculta | `restocking_bi_tab.test.mjs` **51/51**; `RestockingBiLayoutBrowserTest` **34/34**; `RestockingBiConstitutionalTest` **38/38** |
| **D5** | Dos gráficos `window.Chart`, tres tablas y CSV multi-sección | `restocking_bi_tab.test.mjs` **51/51**; `RestockingBiApiTest` **23/23**; `RestockingBiLayoutBrowserTest` **34/34** |
| **C1** | Ventana semiabierta DST-safe `Europe/Madrid` | `RestockingBiDomainTest` **250/250**; `PdoRestockingBiAnalyticsRepositoryTest` **72/72** |
| **C2** | Bajas lógicas visibles, snapshots monetarios y cero DDL | `RestockingBiDomainTest` **250/250**; `PdoRestockingBiAnalyticsRepositoryTest` **72/72**; `RestockingBiConstitutionalTest` **38/38** |
| **C3** | Turnos válidos, máquinas por turno y duración real | `RestockingBiDomainTest` **250/250**; `PdoRestockingBiAnalyticsRepositoryTest` **72/72**; `RestockingBiServiceTest` **32/32** |
| **C4** | L1 efectivas vs. escalados al SAT sobre incidencias vigentes | `RestockingBiDomainTest` **250/250**; `PdoRestockingBiAnalyticsRepositoryTest` **72/72**; `RestockingBiServiceTest` **32/32** |
| **C5** | `400 INVALID_FILTER_PARAMS`, `401`/`403`, `500` sin trazas y periodo vacío coherente | `RestockingBiDomainTest` **250/250**; `RestockingBiApiTest` **23/23**; `RestockingBiServiceTest` **32/32** |
| **C6** | *Non-Goals*: sin comparativas de periodo ni acoplamiento a planogramas/A4/M33/M37 | `RestockingBiConstitutionalTest` **38/38**; `restocking_bi_tab.test.mjs` **51/51** |
| **QA-1** | Evento global `EXPORT_RESTOCKING_BI_CSV` con `entity_id = 0` y etiqueta propia | `RestockingBiServiceTest` **32/32**; `RestockingBiApiTest` **23/23**; `RestockingBiConstitutionalTest` **38/38** |
| **QA-2** | Prioridad sanitaria en `by_machine` y todas las actas M29 | `RestockingBiDomainTest` **250/250**; `RestockingBiConstitutionalTest` **38/38** |
| **QA-3** | Barra global de SAT y CSV no duplicados en la pestaña | `restocking_bi_tab.test.mjs` **51/51**; `RestockingBiLayoutBrowserTest` **34/34** |
| **QA-4** | Inclusión contable exacta y `available_filters` estable | `RestockingBiDomainTest` **250/250**; `PdoRestockingBiAnalyticsRepositoryTest` **72/72** |
| **QA-5** | `to > today`, coherencia L1 y guarda de producto en el umbral | `RestockingBiDomainTest` **250/250**; `PdoRestockingBiAnalyticsRepositoryTest` **72/72**; `RestockingBiServiceTest` **32/32** |
| **Art. II** | Primacía sanitaria: abortos térmicos separados y mermas APPCC independientes | `RestockingBiDomainTest` **250/250**; `RestockingBiServiceTest` **32/32**; `RestockingBiConstitutionalTest` **38/38** |
| **Art. III** | Históricos inmutables, bajas lógicas y auditoría de exportaciones | `PdoRestockingBiAnalyticsRepositoryTest` **72/72**; `RestockingBiServiceTest` **32/32**; `RestockingBiConstitutionalTest` **38/38** |
| **Art. IV** | Cero DDL, cero dependencias y PHP/Vue nativos | `RestockingBiConstitutionalTest` **38/38**; `PostgresSchemaTest` **159/159** |
| **Art. V.4** | RBAC `COORDINATOR`, opacidad económica y descarga autenticada | `RestockingBiApiTest` **23/23**; `RestockingBiConstitutionalTest` **38/38**; `RestockingBiLayoutBrowserTest` **34/34** |
| **Art. VI** | Ficheros protegidos y consolas M33/M37 intactos | `RestockingBiConstitutionalTest` **38/38** (huellas SHA-256) |

---

## 4. Definición de Hecho (§8)

| DoD | Evidencia |
| :--- | :--- |
| **1.** Dominio, repositorio PDO y servicios con cobertura `D1`–`D5`, `C1`–`C5` y `QA-1`–`QA-5` | `RestockingBiDomainTest` **250/250**; `PdoRestockingBiAnalyticsRepositoryTest` **72/72**; `RestockingBiServiceTest` **32/32** |
| **2.** Consultas agregadas limpias sin DDL adicional | `PdoRestockingBiAnalyticsRepositoryTest` **72/72**; `PostgresSchemaTest` **159/159**; `RestockingBiConstitutionalTest` **38/38** |
| **3.** Rutas REST con RBAC y auditoría `EXPORT_RESTOCKING_BI_CSV` | `RestockingBiApiTest` **23/23**; `RestockingBiConstitutionalTest` **38/38** |
| **4.** Sub-pestaña integrada, precacheada, con Chart.js, degradación y `360 × 800` | `restocking_bi_tab.test.mjs` **51/51**; `RestockingBiLayoutBrowserTest` **34/34**; `OfflinePwaAssetsAndUiGuardTest` **45/45** |
| **5.** Batería global, canal `new_engine`, guardas, matriz y cierre documental al 100 % | `php tests/run_all.php` → **453 suites · 17.822 aserciones · 0 fallos · `DocMetricsGuard` coherente · `exit=0`**. `php tests/run_new_engine.php` (`.env.docker-tls`) → **78/78 suites · 2.718 aserciones · 0 fallos · `exit=0`**. Residuo 0 de esquemas `t_*` y `contract_convergence_check*`. |

---

## 5. Evidencia de Certificación

| Puerta | Comando | Resultado |
| :--- | :--- | :--- |
| Batería global | `source deploy/local/postgres/.env.battery && php tests/run_all.php` | **453 suites · 17.822 aserciones · 0 fallos** · `DocMetricsGuard` coherente · base restablecida · `exit=0` |
| Canal `new_engine` | `source deploy/local/postgres/.env.docker-tls && php tests/run_new_engine.php` | **78 evaluadas · 78 verdes · 2.718 aserciones · 0 fallos · `exit=0`** |
| Esquema y despliegue | `PostgresSchemaTest` + `PostgresConstitutionalTest` + `SchemaConvergenceParityTest` | **159/159 · 88/88 · 42/42** · `exit=0` |
| Suites propias | Las 7 suites del mapa 1.1 | **500 aserciones · 0 fallos · `exit=0`** |
| Guardas de interfaz y PWA | Login + triaje + shell móvil + PWA + plantillas + tokens | **6/6 · 6/6 · 7/7 · 45/45 · 15/15 · 3/3** |
| Residuo de esquemas temporales | Consulta a `pg_namespace` tras el canal | **0** esquemas `t_*` ni `contract_convergence_check*` |

**Nota de cierre:** la cifra viva de la última corrida es **453 suites / 17.822 aserciones**; las 7 suites propias suman **500** y el canal `new_engine` mide **78/78 suites / 2.718 aserciones**. La guarda constitucional reconoce como superficie frontend aprobada el cliente API, la sub-pestaña, su integración en la vista de métricas y la etiqueta de auditoría, manteniendo que solo `api.js` compone las rutas HTTP. No cambia el alcance funcional del módulo.

---

## 6. Mantenimiento

Esta matriz es un artefacto vivo: cualquier cambio en un `RF`, criterio EARS, `RNF`, decisión `D`, caso límite `C`, resolución `QA` o artículo constitucional debe actualizar la fila correspondiente y su resultado medido en la misma entrega. La cifra viva de suites y aserciones de la batería global se declara solo en `README.md` y `docs/features_pendientes.md`; el canal `new_engine` se declara en `deploy/local/postgres/README.md`.
