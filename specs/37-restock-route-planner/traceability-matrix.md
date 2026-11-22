# MATRIZ DE TRAZABILIDAD · MÓDULO 37

**Especificación:** [`spec.md`](spec.md) (v1.2 · `D1`–`D7`, `C1`–`C3` y `QA-1`–`QA-6`) · **Contratos:** [`../technical/restock_route_planner_contracts.md`](../technical/restock_route_planner_contracts.md) · **Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md) · **ODD:** [`../../odd/tasks/restock-route-planner.md`](../../odd/tasks/restock-route-planner.md)

**Esquema de referencia canónico:** `RF-01` agendas versionadas y herencia territorial · `RF-02` excepciones diarias, perímetro unificado y salvaguarda Art. II · `RF-03` motor de ruta, tres bloques y degradación sin GPS · `RF-04` integración M33 (`TODAY_ROUTE`) y M34 (`route_schedule_id`) · `RF-05` cola Offline, superficies móvil/Coordinación y opacidad.

**Corrida de certificación:** 2026-11-22 · batería global **448 suites · 17.373 aserciones · 0 fallos** (`DocMetricsGuard` coherente) · canal `new_engine`: **75 evaluadas · 75 verdes · 2.552 aserciones · 0 fallos** · PostgreSQL 16.15 local con TLS (`vendguard_battery` y `vendguard_pg_test`).

**Estado:** ✅ Módulo 37 cerrado al 100 % · **13/13 tareas** (`T-RRP-01` a `T-RRP-13`) · **11 suites propias con 502 aserciones verdes** + regresiones de esquema, servicios y superficies · **5/5 DoD** · `CoordinatorTerritorialMapTab.js` y el DDL de `locations` intactos.

---

## 1. Trazabilidad de Requisitos Funcionales

| Requisito | Criterios EARS | Implementación | Evidencia verificada |
| :--- | :--- | :--- | :--- |
| **RF-01** · Agendas periódicas versionadas y herencia territorial | `01.1` tipos #45/#46, Tabla 50, índice activo único, FK histórica y CHECK de cadencia; `01.2` calendario civil DST-safe y pausa estacional; `01.3` CRUD individual, alta masiva atómica y baja lógica | `033_restock_route_planner.sql`, `034_restock_schedule_cadence_guard.sql`, `supabase_init.sql`, `supabase_seeds.sql`, `MachineRestockSchedule`, `RestockCadenceEvaluator`, `RestockRouteScheduleService`, `PdoRestockRoutePlannerGateway` | `PostgresRestockRoutePlannerSchemaTest` **41/41**; `RestockRoutePlannerDomainModelsTest` **67/67**; `RestockCadenceAndValidatorTest` **60/60**; `RestockRoutePlannerServicesTest` **103/103**; `RestockRoutePlannerApiTest` **29/29**; `PostgresSchemaTest` **159/159**; `PostgresConstitutionalTest` **88/88**; `SchemaConvergenceParityTest` **42/42**; `exit=0` |
| **RF-02** · Excepciones diarias, perímetro unificado y salvaguarda Art. II | `02.1` tipos #47–#50, Tabla 51, ciclo `ACTIVE`/`SUPERSEDED`/`REVOKED` y prevalencia física; `02.2` `SKIPPED` con motivo real, alerta FEFO y sello `EXPIRED`; `02.3` `REASSIGNED`, perímetro M32–M36 y revocación automática | `RestockRouteDailyOverride`, `RestockRoutePlannerValidator`, `RestockRouteOverrideService`, `RestockRouteReassignmentRevoker`, `PdoRestockRoutePlannerGateway`, gateways M32/M34/M35/M36 | `RestockRoutePlannerDomainModelsTest` **67/67**; `RestockCadenceAndValidatorTest` **60/60**; `RestockRoutePlannerServicesTest` **103/103**; `RestockRoutePlannerIntegrationsTest` **21/21**; `PdoRestockRoutePlannerGatewayTest` **34/34**; `RestockRoutePlannerApiTest` **29/29**; `RestockRoutePlannerConstitutionalTest` **45/45**; `exit=0` |
| **RF-03** · Motor de ruta, tres bloques y degradación sin GPS | `03.1` composición, exclusiones, `blocked_stops`, pausa estacional y precedencia `REASSIGNED > L1 > zona`; `03.2` agrupación por sede, 3 bloques, desempates y Haversine; `03.3` bandeja `uncovered_stops` | `RestockRouteOptimizer`, `RestockRoutePlannerService`, `PdoRestockRouteReadModel`, `GeoDistanceService`, `CoordinatorRestockRoutePlannerSubTab.js`, `RestockerRouteView.js` | `RestockRouteOptimizerTest` **30/30**; `RestockRoutePlannerServicesTest` **103/103**; `RestockRoutePlannerIntegrationsTest` **21/21**; `RestockRoutePlannerApiTest` **29/29**; `RestockRoutePlannerConstitutionalTest` **45/45**; `restocker_route_planner_view.test.mjs` **40/40**; `restocker_route_view.test.mjs` **245/245**; `exit=0` |
| **RF-04** · Integración M33 (`TODAY_ROUTE`) y M34 (`route_schedule_id`) | `04.1` modo `TODAY_ROUTE` retrocompatible y acotado a la ruta pendiente; `04.2` enlace automático/validado de la agenda y aceptación de versiones cerradas | `ProductLoadingDemandMode::TODAY_ROUTE`, `ProductLoadingOrderService`, `MachineRestockVisitService`, `RestockRoutePlannerService` | `RestockRoutePlannerIntegrationsTest` **21/21**; `ProductLoadingOrderServiceTest` **53/53**; `MachineRestockVisitServiceTest` **50/50**; `PdoRestockRoutePlannerGatewayTest` **34/34**; `RestockRoutePlannerApiTest` **29/29**; `exit=0` |
| **RF-05** · Cola Offline, superficies y opacidad | `05.1` `SKIP_RESTOCK_STOP`, idempotencia, drenaje ordenado y volcado de conflictos; `05.2` hoja móvil con mapa Web Mercator y `360 × 800`; `05.3` consola de Coordinación con contadores de un solo `service_date` | `RestockRouteOverrideOfflineQueue.js`, `RestockerRouteMapModal.js`, `RestockerRouteView.js`, `CoordinatorRestockRoutePlannerSubTab.js`, `CoordinatorProductLoadingTab.js`, `public/sw.js` | `restocker_route_planner_view.test.mjs` **40/40**; `restocker_route_view.test.mjs` **245/245**; `coordinator_restock_route_planner.test.mjs` **32/32**; `coordinator_product_loading_tab.test.mjs` **80/80**; `OfflinePwaAssetsAndUiGuardTest.mjs` **45/45**; `RestockerSatLayoutBrowserTest` **22/22** a `360 × 800`; `RestockRoutePlannerConstitutionalTest` **45/45**; `exit=0` |

### 1.1. Mapa de suites propias y regresiones directas

| Suite | Ruta | Recuento |
| :--- | :--- | ---: |
| `RestockRoutePlannerDomainModelsTest` | [`tests/unit/RestockRoutePlannerDomainModelsTest.php`](../../tests/unit/RestockRoutePlannerDomainModelsTest.php) | 67/67 |
| `RestockCadenceAndValidatorTest` | [`tests/unit/RestockCadenceAndValidatorTest.php`](../../tests/unit/RestockCadenceAndValidatorTest.php) | 60/60 |
| `RestockRouteOptimizerTest` | [`tests/unit/RestockRouteOptimizerTest.php`](../../tests/unit/RestockRouteOptimizerTest.php) | 30/30 |
| `RestockRoutePlannerServicesTest` | [`tests/unit/RestockRoutePlannerServicesTest.php`](../../tests/unit/RestockRoutePlannerServicesTest.php) | 103/103 |
| `RestockRoutePlannerIntegrationsTest` | [`tests/unit/RestockRoutePlannerIntegrationsTest.php`](../../tests/unit/RestockRoutePlannerIntegrationsTest.php) | 21/21 |
| `PostgresRestockRoutePlannerSchemaTest` | [`tests/integration/PostgresRestockRoutePlannerSchemaTest.php`](../../tests/integration/PostgresRestockRoutePlannerSchemaTest.php) | 41/41 |
| `PdoRestockRoutePlannerGatewayTest` | [`tests/integration/PdoRestockRoutePlannerGatewayTest.php`](../../tests/integration/PdoRestockRoutePlannerGatewayTest.php) | 34/34 |
| `RestockRoutePlannerApiTest` | [`tests/integration/RestockRoutePlannerApiTest.php`](../../tests/integration/RestockRoutePlannerApiTest.php) | 29/29 |
| `RestockRoutePlannerConstitutionalTest` | [`tests/integration/RestockRoutePlannerConstitutionalTest.php`](../../tests/integration/RestockRoutePlannerConstitutionalTest.php) | 45/45 |
| `coordinator_restock_route_planner.test.mjs` | [`tests/frontend/coordinator_restock_route_planner.test.mjs`](../../tests/frontend/coordinator_restock_route_planner.test.mjs) | 32/32 |
| `restocker_route_planner_view.test.mjs` | [`tests/frontend/restocker_route_planner_view.test.mjs`](../../tests/frontend/restocker_route_planner_view.test.mjs) | 40/40 |
| `PostgresSchemaTest` | [`tests/integration/PostgresSchemaTest.php`](../../tests/integration/PostgresSchemaTest.php) | 159/159 |
| `PostgresConstitutionalTest` | [`tests/integration/PostgresConstitutionalTest.php`](../../tests/integration/PostgresConstitutionalTest.php) | 88/88 |
| `SchemaConvergenceParityTest` | [`tests/integration/SchemaConvergenceParityTest.php`](../../tests/integration/SchemaConvergenceParityTest.php) | 42/42 |
| `ProductLoadingOrderServiceTest` | [`tests/unit/ProductLoadingOrderServiceTest.php`](../../tests/unit/ProductLoadingOrderServiceTest.php) | 53/53 |
| `MachineRestockVisitServiceTest` | [`tests/unit/MachineRestockVisitServiceTest.php`](../../tests/unit/MachineRestockVisitServiceTest.php) | 50/50 |
| `restocker_route_view.test.mjs` | [`tests/frontend/restocker_route_view.test.mjs`](../../tests/frontend/restocker_route_view.test.mjs) | 245/245 |
| `coordinator_product_loading_tab.test.mjs` | [`tests/frontend/coordinator_product_loading_tab.test.mjs`](../../tests/frontend/coordinator_product_loading_tab.test.mjs) | 80/80 |
| `RestockerSatLayoutBrowserTest` | [`tests/integration/RestockerSatLayoutBrowserTest.php`](../../tests/integration/RestockerSatLayoutBrowserTest.php) | 22/22 · `360 × 800` |
| `OfflinePwaAssetsAndUiGuardTest` | [`tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs`](../../tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs) | 45/45 |
| `CoordinatorButtonVariantsGuardTest` | [`tests/unit/CoordinatorButtonVariantsGuardTest.mjs`](../../tests/unit/CoordinatorButtonVariantsGuardTest.mjs) | 23/23 |
| `FrontendTemplateCompileTest` | [`tests/unit/FrontendTemplateCompileTest.mjs`](../../tests/unit/FrontendTemplateCompileTest.mjs) | 15/15 |
| `DesignTokenDebtRatchetTest` | [`tests/unit/DesignTokenDebtRatchetTest.mjs`](../../tests/unit/DesignTokenDebtRatchetTest.mjs) | 3/3 |
| `TestDataCleanerIsolationTest` | [`tests/integration/TestDataCleanerIsolationTest.php`](../../tests/integration/TestDataCleanerIsolationTest.php) | 50/50 |

---

## 2. Trazabilidad de Requisitos No Funcionales

| Requisito | Regla verificada | Evidencia |
| :--- | :--- | :--- |
| **RNF-01** · Evolución controlada, integridad relacional y prohibición de borrado físico | 51 tablas, 50 tipos `ENUM`, 56 columnas `ENUM`, `prevent_hard_delete()` en ambas tablas, versionado inmutable y plan de purga de 39 tablas transitorias | `PostgresRestockRoutePlannerSchemaTest` **41/41**; `PostgresSchemaTest` **159/159**; `PostgresConstitutionalTest` **88/88**; `SchemaConvergenceParityTest` **42/42**; `TestDataCleanerIsolationTest` **50/50**; `CloudDeploySchemaParityTest` **55/55** |
| **RNF-02** · Determinismo puro y eficiencia de consulta | Evaluación de cadencias sin E/S, optimizador puro y composición diaria sin N+1 | `RestockCadenceAndValidatorTest` **60/60**; `RestockRouteOptimizerTest` **30/30**; `RestockRoutePlannerServicesTest` **103/103**; `RestockRoutePlannerApiTest` **29/29** |
| **RNF-03** · Defensa en profundidad sanitaria y privacidad | FEFO solo `requires_cold_chain = TRUE`, `CHECK` de excepciones, alerta FEFO solo interna, opacidad móvil y de Sede/QR/A4 | `RestockRoutePlannerConstitutionalTest` **45/45**; `RestockCadenceAndValidatorTest` **60/60**; `RestockRoutePlannerDomainModelsTest` **67/67**; `RestockRoutePlannerApiTest` **29/29**; `restocker_route_planner_view.test.mjs` **40/40** |
| **RNF-04** · Sistema de diseño y Dogma Vanilla | PHP 8.2+ tipado estricto, Vue 3 ESM sin bundlers, tokens `vg-*`, cero geometría inline y `360 × 800` sin scroll horizontal | `CoordinatorButtonVariantsGuardTest.mjs` **23/23**; `FrontendTemplateCompileTest.mjs` **15/15**; `DesignTokenDebtRatchetTest.mjs` **3/3**; `restocker_route_planner_view.test.mjs` **40/40**; `coordinator_restock_route_planner.test.mjs` **32/32**; `RestockerSatLayoutBrowserTest` **22/22** |

---

## 3. Decisiones, Casos Límite, Resoluciones QA y Artículos Constitucionales

| Referencia | Decisión o caso | Cobertura |
| :--- | :--- | :--- |
| **D1** | Modelo híbrido agendas + excepciones diarias y enlace histórico M34 | `PostgresRestockRoutePlannerSchemaTest` **41/41**; `RestockRoutePlannerDomainModelsTest` **67/67**; `RestockRoutePlannerServicesTest` **103/103** |
| **D2** | Herencia territorial pura desde `locations.zone_id` y cobertura M31 | `RestockRoutePlannerDomainModelsTest` **67/67**; `RestockRoutePlannerServicesTest` **103/103**; `PdoRestockRoutePlannerGatewayTest` **34/34** |
| **D3** | Disparadores FEFO/L1/`qty=0`, exclusión de bloqueadas y precedencia | `RestockRouteOptimizerTest` **30/30**; `RestockRoutePlannerServicesTest` **103/103**; `RestockRoutePlannerIntegrationsTest` **21/21** |
| **D4** | Permisos de excepción, `SKIPPED` y salvaguarda Art. II | `RestockCadenceAndValidatorTest` **60/60**; `RestockRoutePlannerServicesTest` **103/103**; `RestockRoutePlannerApiTest` **29/29**; `RestockRoutePlannerConstitutionalTest` **45/45** |
| **D5** | `TODAY_ROUTE` acotado a la ruta pendiente y retrocompatible | `RestockRoutePlannerIntegrationsTest` **21/21**; `ProductLoadingOrderServiceTest` **53/53**; `RestockRoutePlannerApiTest` **29/29** |
| **D6** | Cadencias DST-safe, tres bloques y desempates deterministas | `RestockCadenceAndValidatorTest` **60/60**; `RestockRouteOptimizerTest` **30/30**; `RestockRoutePlannerServicesTest` **103/103** |
| **D7** | Superficie móvil con mapa y consola de Coordinación sin tocar el mapa territorial | `coordinator_restock_route_planner.test.mjs` **32/32**; `restocker_route_planner_view.test.mjs` **40/40**; `RestockerSatLayoutBrowserTest` **22/22** |
| **C1** | Prevalencia física de la visita, `SUPERSEDED` y bloqueo de nuevas excepciones | `RestockRoutePlannerServicesTest` **103/103**; `RestockRoutePlannerIntegrationsTest` **21/21**; `PdoRestockRoutePlannerGatewayTest` **34/34**; `RestockRoutePlannerConstitutionalTest` **45/45** |
| **C2** | Degradación sin GPS y bandeja `uncovered_stops` | `RestockRouteOptimizerTest` **30/30**; `RestockRoutePlannerServicesTest` **103/103**; `restocker_route_planner_view.test.mjs` **40/40**; `coordinator_restock_route_planner.test.mjs` **32/32** |
| **C3** | Idempotencia Offline, drenaje ordenado y volcado de conflictos | `RestockRoutePlannerServicesTest` **103/103**; `RestockRoutePlannerApiTest` **29/29**; `restocker_route_planner_view.test.mjs` **40/40**; `OfflinePwaAssetsAndUiGuardTest.mjs` **45/45** |
| **QA-1** | FEFO exclusivo de frío, alerta interna y sello `EXPIRED` sin bloquear M34 | `RestockCadenceAndValidatorTest` **60/60**; `RestockRoutePlannerServicesTest` **103/103**; `RestockRoutePlannerIntegrationsTest` **21/21**; `RestockRoutePlannerConstitutionalTest` **45/45** |
| **QA-2** | Versionado inmutable de agendas y aceptación de versiones cerradas | `PostgresRestockRoutePlannerSchemaTest` **41/41**; `RestockRoutePlannerDomainModelsTest` **67/67**; `PdoRestockRoutePlannerGatewayTest` **34/34**; `RestockRoutePlannerConstitutionalTest` **45/45** |
| **QA-3** | Perímetro unificado M32–M36 y revocación automática del receptor | `PdoRestockRoutePlannerGatewayTest` **34/34**; `RestockRoutePlannerIntegrationsTest` **21/21**; `RestockRoutePlannerServicesTest` **103/103**; `RestockRoutePlannerApiTest` **29/29**; `RestockRoutePlannerConstitutionalTest` **45/45** |
| **QA-4** | Precedencia `REASSIGNED > L1 > zona`, prohibición de auto-desvío y validación de motivos | `RestockCadenceAndValidatorTest` **60/60**; `RestockRoutePlannerServicesTest` **103/103**; `RestockRoutePlannerApiTest` **29/29**; `RestockRoutePlannerConstitutionalTest` **45/45** |
| **QA-5** | `TODAY_ROUTE` reclama todos los huecos llenables de la ruta | `RestockRoutePlannerIntegrationsTest` **21/21**; `ProductLoadingOrderServiceTest` **53/53**; `RestockRoutePlannerApiTest` **29/29** |
| **QA-6** | Pausa estacional, proyección futura, bordes Offline y desempates | `RestockCadenceAndValidatorTest` **60/60**; `RestockRouteOptimizerTest` **30/30**; `PostgresRestockRoutePlannerSchemaTest` **41/41**; `RestockRoutePlannerConstitutionalTest` **45/45** |
| **Art. II** | Primacía sanitaria y FEFO refrigerado | `PostgresRestockRoutePlannerSchemaTest` **41/41**; `RestockCadenceAndValidatorTest` **60/60**; `RestockRoutePlannerServicesTest` **103/103**; `RestockRoutePlannerConstitutionalTest` **45/45** |
| **Art. III.1 / III.3** | Cero borrado físico, histórico inmutable y auditoría de ambas entidades | `PostgresRestockRoutePlannerSchemaTest` **41/41**; `PdoRestockRoutePlannerGatewayTest` **34/34**; `RestockRoutePlannerConstitutionalTest` **45/45**; `TestDataCleanerIsolationTest` **50/50** |
| **Art. V.1** | Motivo `SKIPPED` de 20–500 caracteres reales y ≥ 3 bloques | `RestockCadenceAndValidatorTest` **60/60**; `RestockRoutePlannerServicesTest` **103/103**; `RestockRoutePlannerApiTest` **29/29**; `RestockRoutePlannerConstitutionalTest` **45/45** |
| **Art. V.4** | Opacidad de Sede/QR/A4 y del móvil del reponedor | `RestockRoutePlannerConstitutionalTest` **45/45**; `RestockRoutePlannerApiTest` **29/29**; `restocker_route_planner_view.test.mjs` **40/40**; `RestockRoutePlannerServicesTest` **103/103** |
| **Art. VI** | Contadores de un único `service_date`, `locations` intacto y mapa territorial intacto | `RestockRoutePlannerConstitutionalTest` **45/45**; `coordinator_restock_route_planner.test.mjs` **32/32**; `RestockRoutePlannerServicesTest` **103/103** |

---

## 4. Definición de Hecho (§8)

| DoD | Evidencia |
| :--- | :--- |
| **1.** Migración `033`, tipos #45–#50, Tablas 50/51, índices, FK, auditoría y guardas | `PostgresRestockRoutePlannerSchemaTest` **41/41**; `PostgresSchemaTest` **159/159**; `PostgresConstitutionalTest` **88/88**; `SchemaConvergenceParityTest` **42/42** |
| **2.** Dominio, cadencias, optimizador, servicios, excepciones y perímetro M32–M36 | `RestockRoutePlannerDomainModelsTest` **67/67**; `RestockCadenceAndValidatorTest` **60/60**; `RestockRouteOptimizerTest` **30/30**; `RestockRoutePlannerServicesTest` **103/103**; `RestockRoutePlannerIntegrationsTest` **21/21**; `PdoRestockRoutePlannerGatewayTest` **34/34** |
| **3.** API REST, RBAC, idempotencia y opacidad | `RestockRoutePlannerApiTest` **29/29**; `RestockRoutePlannerConstitutionalTest` **45/45** |
| **4.** Superficies móvil y de Coordinación, mapa, Offline y `TODAY_ROUTE` | `restocker_route_planner_view.test.mjs` **40/40**; `restocker_route_view.test.mjs` **245/245**; `coordinator_restock_route_planner.test.mjs` **32/32**; `coordinator_product_loading_tab.test.mjs` **80/80**; `RestockerSatLayoutBrowserTest` **22/22**; `OfflinePwaAssetsAndUiGuardTest.mjs` **45/45** |
| **5.** Batería global y canal `new_engine` | `php tests/run_all.php` → **448 suites · 17.373 aserciones · 0 fallos · `DocMetricsGuard` coherente · `exit=0`**. `php tests/run_new_engine.php` (`.env.docker-tls`) → **75/75 suites en verde · 0 no ejecutables · 2.552 aserciones · 0 fallos · `exit=0`**. Residuo 0 de esquemas `t_*` y `contract_convergence_check*`. |

---

## 5. Evidencia de Certificación

| Puerta | Comando | Resultado |
| :--- | :--- | :--- |
| Batería global | `source deploy/local/postgres/.env.battery && php tests/run_all.php` | **448 suites · 17.373 aserciones · 0 fallos** · `DocMetricsGuard` coherente · base restablecida · `exit=0` |
| Canal `new_engine` | `source deploy/local/postgres/.env.docker-tls && php tests/run_new_engine.php` | **75 evaluadas · 75 verdes · 2.552 aserciones · 0 fallos · `exit=0`** |
| Esquema y despliegue | `PostgresSchemaTest` + `PostgresConstitutionalTest` + `SchemaConvergenceParityTest` + `CloudDeploySchemaParityTest` | **159/159 · 88/88 · 42/42 · 55/55** · `exit=0` |
| Suites propias | Las 11 suites del mapa 1.1 | **502 aserciones · 0 fallos · `exit=0`** |
| Guardas de interfaz | Botones + plantillas + tokens + PWA + layout móvil | **23/23 · 15/15 · 3/3 · 45/45 · 22/22** |
| Residuo de esquemas temporales | Consulta a `pg_namespace` tras ambas corridas | **0** esquemas `t_*` ni `contract_convergence_check*` |

**Nota de cierre:** la batería global anterior declaraba 441 suites / 17.018 aserciones; la incorporación de las siete suites globales del Módulo 37 (cinco unitarias PHP y dos frontend) eleva la cifra viva a 448 suites / 17.358 aserciones; la regresión de pausa estacional de AUD-37-02 añade 15 aserciones y deja la cifra viva en 448 suites / 17.373 aserciones. El canal `new_engine` incorpora las cuatro suites de integración del módulo (esquema, gateway PDO, API y blindaje constitucional) y pasa de 71 a 75 suites. Las correcciones de certificación de T-RRP-13 no cambian el alcance funcional: completan el catálogo de auditoría con las entidades y la acción del Módulo 37, retiran dos menciones textuales que confundían a la guarda de borrado, resuelven el `LogicException` global del servicio M33, eliminan una geometría inline del botón de archivo Offline, cierran el App Shell con la sub-vista de Coordinación, añaden la guarda de duplicado al bloque `DO` de la FK histórica y actualizan el contrato de purga a 39 tablas transitorias.

---

## 6. Mantenimiento

Esta matriz es un artefacto vivo: cualquier cambio en un `RF`, criterio EARS, `RNF`, decisión `D`, caso límite `C`, resolución `QA` o artículo constitucional debe actualizar la fila correspondiente y su resultado medido en la misma entrega. La cifra viva de suites y aserciones de la batería global se declara solo en `README.md` y `docs/features_pendientes.md`; el canal `new_engine` se declara en `deploy/local/postgres/README.md`.
