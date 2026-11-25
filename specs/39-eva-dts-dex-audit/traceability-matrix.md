# MATRIZ DE TRAZABILIDAD · MÓDULO 39

**Especificación:** [`spec.md`](spec.md) (v1.1 · `D1`–`D6`, `C1`–`C6` y `QA-1`–`QA-5`) · **Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md) · **ODD:** [`../../odd/tasks/eva-dts-dex-audit.md`](../../odd/tasks/eva-dts-dex-audit.md) · **Espejo funcional:** [`../functional/eva_dts_dex_audit_spec.md`](../functional/eva_dts_dex_audit_spec.md)

**Esquema de referencia canónico:** `RF-01` esquema inmutable y tipos #51–#55 · `RF-02` parser determinista e identidad · `RF-03` RBAC, perímetro y primacía sanitaria · `RF-04` deltas y cruces no destructivos M34/M35 · `RF-05` catálogo de eventos, asistencia y alerta térmica · `RF-06` cola Offline y aislamiento de conflictos · `RF-07` consola de Coordinación, anulación y CSV.

**Corrida de certificación:** 2026-11-25 · batería global **461 suites · 18.126 aserciones · 0 fallos** (`DocMetricsGuard` coherente) · canal `new_engine`: **84 evaluadas · 84 verdes · 2.881 aserciones · 0 fallos** · PostgreSQL 16.15 local con TLS (`vendguard_battery` y `vendguard_pg_test`).

**Estado:** ✅ Módulo 39 cerrado al 100 % · **14/14 tareas** (`T-DEX-01` a `T-DEX-14`) · **13 suites propias con 405 aserciones verdes** + regresiones de esquema, servicios, rutas y superficies · **4/4 DoD** · DDL previo intacto.

---

## 1. Trazabilidad de Requisitos Funcionales

| Requisito | Criterios EARS | Implementación | Evidencia verificada |
| :--- | :--- | :--- | :--- |
| **RF-01** · Esquema relacional inmutable (Tablas 52–54, tipos #51–#55) | `01.1` migración incremental y convergencia 1:1 de `supabase_init.sql`; `01.2` inmutabilidad y `prevent_hard_delete()`; `01.3` unicidad parcial de `sha256_hash` y liberación tras `VOIDED` | `035_eva_dts_dex_audit.sql`, `supabase_init.sql`, `supabase_seeds.sql`, `SeedRunner`, `PostgresFixtureBuilder`, `TestDataCleaner` | `PostgresDexAuditSchemaTest` **38/38**; `PostgresSchemaTest` **168/168**; `PostgresConstitutionalTest` **91/91**; `TestDataCleanerIsolationTest` **50/50**, incluida la purga de las 3 tablas como raíz propia; `exit=0` |
| **RF-02** · Parser determinista EVA-DTS en PHP 8.2+ e identidad `ID1` | `02.1` cotas, codificaciones y delimitadores; `02.2` bloques obligatorios/opcionales y duplicados; `02.3` SHA-256, escala `ID4` e identidad no bloqueante; `02.4` deduplicación `(machine_id, sha256_hash)` | `EvaDtsParser`, `EvaDtsEventCatalog`, `DexAuditValidationException`, `DexAuditConflictException`, `DexAuditValidator` | `EvaDtsParserAndCatalogTest` **45/45**; `DexSelectionMatcherAndValidatorTest` **49/49**; `DexAuditDomainModelsTest` **59/59**; `PostgresDexAuditSchemaTest` **38/38**; `exit=0` |
| **RF-03** · Guardas de rol, perímetro territorial, primacía sanitaria y proyecciones Art. V.4 | `03.1` tres puertas y RBAC; `03.2` perímetro del Reponedor/Técnico; `03.3` bloqueo sanitario solo al Reponedor; `03.4` proyecciones por rol sin fugas | `MachineDexAuditService`, `DexAuditValidator`, `DexAuditForbiddenException`, `CoordinatorDexAuditController`, `RestockerRouteController`, `TechnicianController`, `AppRouter`, `MachineDexAudit` | `DexAuditServiceIntegrationsTest` **19/19**; `DexAuditApiTest` **25/25**; `DexAuditConstitutionalTest` **14/14**; `AuditActionCatalogTest` **27/27**; `AuditEntityTypesTest` **41/41**; `restocker_route_view.test.mjs` **262/262**; `exit=0` |
| **RF-04** · Cadena de deltas, cruce no destructivo M34/M35 y emparejamiento `PA1` | `04.1` predecesor `ACTIVE` por `captured_at`; `04.2` `RESET_FALLBACK`/`UNAVAILABLE` y `out_of_order_sync`; `04.3` deltas efectivos tras `VOIDED`; `04.4` cruces M34/M35 y `PA1` exacto/ceros/ambiguo | `DexSelectionMatcher`, `DexAuditValidator`, `MachineDexAuditService`, `PdoMachineDexAuditGateway`, `PdoMachineDexAuditRepository`, `DexAuditLinker` | `DexSelectionMatcherAndValidatorTest` **49/49**; `MachineDexAuditServiceTest` **29/29**; `PdoMachineDexAuditRepositoryTest` **40/40**; `DexAuditServiceIntegrationsTest` **19/19**; `DexAuditConstitutionalTest` **14/14**; `exit=0` |
| **RF-05** · Catálogo `EA1`/`EA2`, asistencia en 1 toque y alerta térmica anti-spam | `05.1` clasificación determinista por prefijos; `05.2` sugerencia M36/M16 con Nota Interna; `05.3` aviso sanitario y Outbox solo ante novedad/incremento | `EvaDtsEventCatalog`, `MachineDexAuditService`, `NotificationTemplate`, `NotificationMessageRenderer`, `EvaDtsClientPreview.js` | `EvaDtsParserAndCatalogTest` **45/45**; `MachineDexAuditServiceTest` **29/29**; `DexAuditConstitutionalTest` **14/14**; `eva_dts_offline_queue.test.mjs` **25/25**; `exit=0` |
| **RF-06** · Modo Offline PWA con micro-prelectura segura y aislamiento de conflictos | `06.1` validación local `DXS*`..`DXE*`; `06.2` cola FIFO por operador e idempotencia; `06.3` replays, conflictos y desvinculación M35; `06.4` precache del shell | `EvaDtsClientPreview.js`, `DexAuditOfflineQueue.js`, `public/sw.js` (`vendguard-app-shell-v34`), `RestockerRouteView.js`, `TechnicianRouteView.js` | `eva_dts_offline_queue.test.mjs` **25/25**; `OfflinePwaAssetsAndUiGuardTest.mjs` **45/45**; `restocker_route_view.test.mjs` **262/262**; `DexAuditApiTest` **25/25**; `exit=0` |
| **RF-07** · Consola de auditoría DEX en Coordinación, anulación lógica y CSV | `07.1` KPIs, filtros y carga manual; `07.2` detalle, `/raw` seguro y anulación `ACTIVE → VOIDED`; `07.3` CSV UTF-8 BOM neutralizado | `CoordinatorDexAuditsSubTab.js`, `CoordinatorProductLoadingTab.js`, `api.js`, `CoordinatorDexAuditController` | `coordinator_dex_audits_tab.test.mjs` **31/31**; `DexAuditLayoutBrowserTest` **25/25** en Chromium real a `360 × 800` y `1440 × 900`; `DexAuditApiTest` **25/25**; `coordinator_product_loading_tab.test.mjs` **80/80**; `exit=0` |

### 1.1. Mapa de suites propias y regresiones directas

| Suite | Ruta | Recuento |
| :--- | :--- | ---: |
| `PostgresDexAuditSchemaTest` | [`tests/integration/PostgresDexAuditSchemaTest.php`](../../tests/integration/PostgresDexAuditSchemaTest.php) | 38/38 |
| `DexAuditDomainModelsTest` | [`tests/unit/DexAuditDomainModelsTest.php`](../../tests/unit/DexAuditDomainModelsTest.php) | 59/59 |
| `EvaDtsParserAndCatalogTest` | [`tests/unit/EvaDtsParserAndCatalogTest.php`](../../tests/unit/EvaDtsParserAndCatalogTest.php) | 45/45 |
| `DexSelectionMatcherAndValidatorTest` | [`tests/unit/DexSelectionMatcherAndValidatorTest.php`](../../tests/unit/DexSelectionMatcherAndValidatorTest.php) | 49/49 |
| `MachineDexAuditServiceTest` | [`tests/unit/MachineDexAuditServiceTest.php`](../../tests/unit/MachineDexAuditServiceTest.php) | 29/29 |
| `DexAuditServiceIntegrationsTest` | [`tests/unit/DexAuditServiceIntegrationsTest.php`](../../tests/unit/DexAuditServiceIntegrationsTest.php) | 19/19 |
| `PdoMachineDexAuditRepositoryTest` | [`tests/integration/PdoMachineDexAuditRepositoryTest.php`](../../tests/integration/PdoMachineDexAuditRepositoryTest.php) | 40/40 |
| `DexAuditApiTest` | [`tests/integration/DexAuditApiTest.php`](../../tests/integration/DexAuditApiTest.php) | 25/25 |
| `DexAuditConstitutionalTest` | [`tests/integration/DexAuditConstitutionalTest.php`](../../tests/integration/DexAuditConstitutionalTest.php) | 14/14 |
| `DexAuditConcurrencyTest` | [`tests/integration/DexAuditConcurrencyTest.php`](../../tests/integration/DexAuditConcurrencyTest.php) | 6/6 |
| `eva_dts_offline_queue.test.mjs` | [`tests/unit/eva_dts_offline_queue.test.mjs`](../../tests/unit/eva_dts_offline_queue.test.mjs) | 25/25 |
| `coordinator_dex_audits_tab.test.mjs` | [`tests/frontend/coordinator_dex_audits_tab.test.mjs`](../../tests/frontend/coordinator_dex_audits_tab.test.mjs) | 31/31 |
| `DexAuditLayoutBrowserTest` | [`tests/integration/DexAuditLayoutBrowserTest.php`](../../tests/integration/DexAuditLayoutBrowserTest.php) | 25/25 · Chromium real `360 × 800` y `1440 × 900` |
| **Total propio** | **13 suites** | **405/405** |
| `PostgresSchemaTest` | [`tests/integration/PostgresSchemaTest.php`](../../tests/integration/PostgresSchemaTest.php) | 168/168 |
| `PostgresConstitutionalTest` | [`tests/integration/PostgresConstitutionalTest.php`](../../tests/integration/PostgresConstitutionalTest.php) | 91/91 |
| `TestDataCleanerIsolationTest` | [`tests/integration/TestDataCleanerIsolationTest.php`](../../tests/integration/TestDataCleanerIsolationTest.php) | 50/50 |
| `OfflinePwaAssetsAndUiGuardTest` | [`tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs`](../../tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs) | 45/45 |
| `AuditActionCatalogTest` | [`tests/unit/AuditActionCatalogTest.php`](../../tests/unit/AuditActionCatalogTest.php) | 27/27 |
| `AuditEntityTypesTest` | [`tests/unit/AuditEntityTypesTest.php`](../../tests/unit/AuditEntityTypesTest.php) | 41/41 |
| `restocker_route_view.test.mjs` | [`tests/frontend/restocker_route_view.test.mjs`](../../tests/frontend/restocker_route_view.test.mjs) | 262/262 |
| `coordinator_product_loading_tab.test.mjs` | [`tests/frontend/coordinator_product_loading_tab.test.mjs`](../../tests/frontend/coordinator_product_loading_tab.test.mjs) | 80/80 |
| `coordinator_restock_route_planner.test.mjs` | [`tests/frontend/coordinator_restock_route_planner.test.mjs`](../../tests/frontend/coordinator_restock_route_planner.test.mjs) | 43/43 |

---

## 2. Trazabilidad de Requisitos No Funcionales

| Requisito | Regla verificada | Evidencia |
| :--- | :--- | :--- |
| **RNF-01** · Rendimiento y presupuesto de memoria/latencia | 54 tablas y 55 tipos; `raw_payload` excluido de listados, KPIs y proyecciones móviles; tope de 1 MB; descarga solo en `/raw` de Coordinación | `PostgresDexAuditSchemaTest` **38/38**; `PdoMachineDexAuditRepositoryTest` **40/40**; `DexAuditApiTest` **25/25**; `DexAuditConstitutionalTest` **14/14** |
| **RNF-02** · Integridad SQL y defensa en profundidad | Triggers de no borrado e inmutabilidad por etapas; guarda sanitaria SQL; `CHECK` de motivo real y contadores; proyecciones por rol; `nosniff` + `attachment` | `PostgresDexAuditSchemaTest` **38/38**; `PostgresConstitutionalTest` **91/91**; `DexAuditConstitutionalTest` **14/14**; `DexAuditDomainModelsTest` **59/59**; `DexAuditApiTest` **25/25** |
| **RNF-03** · Dogma Vanilla y diseño visual | PHP 8.2+ y Vue 3 ESM sin dependencias; tokens de diseño; `360 × 800` y `1440 × 900` sin scroll horizontal del documento | `DexAuditLayoutBrowserTest` **25/25**; `coordinator_dex_audits_tab.test.mjs` **31/31**; `FrontendTemplateCompileTest.mjs` **15/15**; `OfflinePwaAssetsAndUiGuardTest.mjs` **45/45** |
| **RNF-04** · Zona horaria, codificación y canal `new_engine` | Fechas `Europe/Madrid`, persistencia `TIMESTAMPTZ`, JSON UTF-8; suite de esquema ejecutable en PostgreSQL y registrada en el canal `new_engine` | `PostgresDexAuditSchemaTest` **38/38**; `DexAuditConcurrencyTest` **6/6**; canal `new_engine` **84/84 suites · 2.881 aserciones · 0 fallos**; `exit=0` |

---

## 3. Decisiones, Casos Límite, Resoluciones QA y Artículos Constitucionales

| Referencia | Decisión o caso | Cobertura |
| :--- | :--- | :--- |
| **D1** | Entidad propia inmutable y tres puertas de entrada | `PostgresDexAuditSchemaTest` **38/38**; `DexAuditDomainModelsTest` **59/59**; `DexAuditServiceIntegrationsTest` **19/19**; `DexAuditApiTest` **25/25** |
| **D2** | Blindaje de Arqueo Ciego y proyecciones Art. V.4 | `DexAuditDomainModelsTest` **59/59**; `DexAuditConstitutionalTest` **14/14**; `DexAuditApiTest` **25/25**; `restocker_route_view.test.mjs` **262/262** |
| **D3** | Parser determinista, tolerancia de firmware e identidad `ID1` | `EvaDtsParserAndCatalogTest` **45/45**; `DexSelectionMatcherAndValidatorTest` **49/49**; `PostgresDexAuditSchemaTest` **38/38** |
| **D4** | Asistencia en 1 toque y alerta térmica Art. II | `EvaDtsParserAndCatalogTest` **45/45**; `MachineDexAuditServiceTest` **29/29**; `DexAuditConstitutionalTest` **14/14**; `eva_dts_offline_queue.test.mjs` **25/25** |
| **D5** | Pre-relleno no destructivo M35 y lectura M34/M35 | `DexSelectionMatcherAndValidatorTest` **49/49**; `MachineDexAuditServiceTest` **29/29**; `DexAuditServiceIntegrationsTest` **19/19**; `PdoMachineDexAuditRepositoryTest` **40/40** |
| **D6** | Soporte Offline PWA con micro-prelectura JS | `eva_dts_offline_queue.test.mjs` **25/25**; `OfflinePwaAssetsAndUiGuardTest.mjs` **45/45**; `restocker_route_view.test.mjs` **262/262**; `DexAuditApiTest` **25/25** |
| **C1** | Retroceso o reseteo de contadores acumulados | `DexSelectionMatcherAndValidatorTest` **49/49**; `MachineDexAuditServiceTest` **29/29**; `DexAuditConstitutionalTest` **14/14** |
| **C2** | Permisos diferenciados en cuarentena, frío y fuera de servicio | `DexAuditServiceIntegrationsTest` **19/19**; `PostgresDexAuditSchemaTest` **38/38**; `DexAuditApiTest` **25/25**; `DexAuditConstitutionalTest` **14/14** |
| **C3** | Persistencia inmutable del `.dex` crudo | `PostgresDexAuditSchemaTest` **38/38**; `DexAuditDomainModelsTest` **59/59**; `DexAuditApiTest` **25/25**; `DexAuditConstitutionalTest` **14/14** |
| **C4** | Emparejamiento `PA1` en dos pasos y `HOT_DRINKS` | `DexSelectionMatcherAndValidatorTest` **49/49**; `DexAuditDomainModelsTest` **59/59**; `MachineDexAuditServiceTest` **29/29**; `PdoMachineDexAuditRepositoryTest` **40/40** |
| **C5** | Aislamiento de conflictos en replay Offline M24 | `MachineDexAuditServiceTest` **29/29**; `DexAuditConcurrencyTest` **6/6**; `eva_dts_offline_queue.test.mjs` **25/25**; `DexAuditApiTest` **25/25** |
| **C6** | Consola de Coordinación, anulación lógica y CSV | `coordinator_dex_audits_tab.test.mjs` **31/31**; `DexAuditLayoutBrowserTest` **25/25**; `DexAuditApiTest` **25/25**; `coordinator_product_loading_tab.test.mjs` **80/80** |
| **QA-1** | Prefill solo en el `POST` del Reponedor y bloqueo sanitario | `DexAuditDomainModelsTest` **59/59**; `DexAuditConstitutionalTest` **14/14**; `DexAuditApiTest` **25/25** |
| **QA-2** | Reseteos, orden Offline y deltas efectivos tras `VOIDED` | `DexSelectionMatcherAndValidatorTest` **49/49**; `MachineDexAuditServiceTest` **29/29**; `PdoMachineDexAuditRepositoryTest` **40/40**; `DexAuditConstitutionalTest` **14/14** |
| **QA-3** | Ventana/base del cruce M35 y consumo M34 | `MachineDexAuditServiceTest` **29/29**; `PdoMachineDexAuditRepositoryTest` **40/40**; `DexAuditApiTest` **25/25**; `coordinator_dex_audits_tab.test.mjs` **31/31** |
| **QA-4** | `EA202`/`EA203`/`EA205`, prefijos, `PA101` y anti-spam | `EvaDtsParserAndCatalogTest` **45/45**; `MachineDexAuditServiceTest` **29/29**; `PostgresDexAuditSchemaTest` **38/38**; `DexAuditConstitutionalTest` **14/14** |
| **QA-5** | Codificaciones, cabeceras, `DXE*`, `/raw` y enlaces `404`/`409`/`422` | `EvaDtsParserAndCatalogTest` **45/45**; `DexAuditServiceIntegrationsTest` **19/19**; `DexAuditApiTest` **25/25**; `eva_dts_offline_queue.test.mjs` **25/25** |
| **Art. II** | Primacía sanitaria transversal | `PostgresDexAuditSchemaTest` **38/38**; `DexAuditConstitutionalTest` **14/14**; `DexAuditApiTest` **25/25**; `DexSelectionMatcherAndValidatorTest` **49/49** |
| **Art. III.1 / III.2 / III.3** | Cero borrado físico, anulación lógica auditada e historial inmutable | `PostgresDexAuditSchemaTest` **38/38**; `DexAuditConstitutionalTest` **14/14**; `AuditActionCatalogTest` **27/27**; `TestDataCleanerIsolationTest` **50/50** |
| **Art. IV** | Dogma Vanilla y cero dependencias | `DexAuditLayoutBrowserTest` **25/25**; `coordinator_dex_audits_tab.test.mjs` **31/31**; `eva_dts_offline_queue.test.mjs` **25/25** |
| **Art. V.1** | Motivo de anulación de 20–500 caracteres reales y ≥ 3 bloques | `DexAuditDomainModelsTest` **59/59**; `DexAuditApiTest` **25/25**; `PostgresDexAuditSchemaTest` **38/38**; `DexAuditConstitutionalTest` **14/14** |
| **Art. V.4** | Opacidad de Sede/QR/A4, Reponedor y Técnico | `DexAuditDomainModelsTest` **59/59**; `DexAuditConstitutionalTest` **14/14**; `DexAuditApiTest` **25/25**; `restocker_route_view.test.mjs` **262/262** |
| **Art. VI** | Fuera de alcance: hardware, IoT remoto y preventivo por desgaste | `EvaDtsParserAndCatalogTest` **45/45**; `DexAuditDomainModelsTest` **59/59**; `DexAuditApiTest` **25/25**; `coordinator_dex_audits_tab.test.mjs` **31/31** |

---

## 4. Criterios de Finalización (Definition of Done)

| DoD | Criterio | Evidencia |
| :--- | :--- | :--- |
| **DoD 1** | Migración `035`, convergencia de `supabase_init.sql`, tipos #51–#55, Tablas 52–54, índices y triggers sin alterar DDL previo | `PostgresDexAuditSchemaTest` **38/38**; `PostgresSchemaTest` **168/168**; `PostgresConstitutionalTest` **91/91**; `TestDataCleanerIsolationTest` **50/50** |
| **DoD 2** | Parser, validador, servicio, deltas, anti-spam, cruces, anulación, CSV y proyecciones por rol al 100 % | `DexAuditDomainModelsTest` **59/59**; `EvaDtsParserAndCatalogTest` **45/45**; `DexSelectionMatcherAndValidatorTest` **49/49**; `MachineDexAuditServiceTest` **29/29**; `DexAuditServiceIntegrationsTest` **19/19**; `PdoMachineDexAuditRepositoryTest` **40/40** |
| **DoD 3** | Integración móvil, consola de Coordinación y cola Offline PWA con conflictos | `DexAuditApiTest` **25/25**; `eva_dts_offline_queue.test.mjs` **25/25**; `coordinator_dex_audits_tab.test.mjs` **31/31**; `restocker_route_view.test.mjs` **262/262**; `OfflinePwaAssetsAndUiGuardTest.mjs` **45/45** |
| **DoD 4** | Batería global, canal `new_engine` y navegador real al 100 % en verde, con matriz de trazabilidad | Global **461/461 suites · 18.126 aserciones · 0 fallos**; `new_engine` **84/84 suites · 2.881 aserciones · 0 fallos**; `DexAuditLayoutBrowserTest` **25/25**; `DocMetricsGuard` coherente; matriz publicada |

---

## 5. Comandos y evidencia de la corrida de cierre

```bash
php tests/run_all.php
# 461 suites · 18.126 aserciones · 0 fallos · DocMetricsGuard COHERENTE · exit=0

source deploy/local/postgres/.env.docker-tls
php tests/run_new_engine.php
# 84 suites · 2.881 aserciones · 0 fallos · exit=0
```

**Regresiones directas de la corrida global:** `restocker_route_view.test.mjs` **262/262**, `coordinator_product_loading_tab.test.mjs` **80/80**, `coordinator_restock_route_planner.test.mjs` **43/43**, `AuditActionCatalogTest` **27/27**, `AuditEntityTypesTest` **41/41**, `OfflinePwaAssetsAndUiGuardTest` **45/45** y `TestDataCleanerIsolationTest` **50/50**.

---

## 6. Mantenimiento de esta matriz

* Toda tarea nueva del Módulo 39 debe añadir su fila de evidencia y actualizar el recuento de suites propias.
* Las cifras de la corrida de cierre se sincronizan con `README.md` y `docs/features_pendientes.md`; `DocMetricsGuard` las audita en cada ejecución de `php tests/run_all.php`.
* El recuento del canal `new_engine` se declara en `deploy/local/postgres/README.md`; `NewEngineChannelDocumentationTest` lo compara con las suites descubiertas.
* Una suite propia que cambie de recuento obliga a actualizar esta matriz, el ODD y la tabla de tareas en el mismo commit.
