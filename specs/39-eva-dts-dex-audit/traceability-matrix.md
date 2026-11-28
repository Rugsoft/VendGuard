# MATRIZ DE TRAZABILIDAD · MÓDULO 39

**Especificación:** [`spec.md`](spec.md) (v1.1 · `D1`–`D6`, `C1`–`C6` y `QA-1`–`QA-5`) · **Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md) · **ODD:** [`../../odd/tasks/eva-dts-dex-audit.md`](../../odd/tasks/eva-dts-dex-audit.md) · **Espejo funcional:** [`../functional/eva_dts_dex_audit_spec.md`](../functional/eva_dts_dex_audit_spec.md)

**Esquema de referencia canónico:** `RF-01` esquema inmutable y tipos #51–#55 · `RF-02` parser determinista e identidad · `RF-03` RBAC, perímetro y primacía sanitaria · `RF-04` deltas y cruces no destructivos M34/M35 · `RF-05` catálogo de eventos, asistencia y alerta térmica · `RF-06` cola Offline y aislamiento de conflictos · `RF-07` consola de Coordinación, anulación y CSV.

**Corrida de certificación:** 2026-11-28 · batería global **461 suites · 18.181 aserciones · 0 fallos** (`DocMetricsGuard` coherente) · canal `new_engine`: **85 evaluadas · 85 verdes · 2.950 aserciones · 0 fallos** · PostgreSQL 16.15 local con TLS (`vendguard_battery` y `vendguard_pg_test`).

**Estado:** ✅ Módulo 39 cerrado · **20/20 tareas** (`T-DEX-01` a `T-DEX-20`, incluidas las correctivas `AUD-39-01`–`AUD-39-05`, el cierre de cableado `T-DEX-18`, la latencia `T-DEX-19` y la trazabilidad `T-DEX-20`) · **14 suites propias con 488 aserciones verdes** + regresiones de esquema, servicios, rutas y superficies · **DoD 1, 2, 3 y 4 cumplidos** · DDL previo intacto. `AUD-39-01` a `AUD-39-05` cerradas el 2026-11-26; la auditoría UI `H-39-01` a `H-39-07` quedó cerrada el 2026-11-27 (`../../docs/ui-audit-eva-dts-dex.md`) con 14 aserciones nuevas. La matriz separa primitiva y flujo de producción y enlaza los 14 criterios EARS con su aserción de integración.

---

## 1. Trazabilidad de Requisitos Funcionales: primitiva vs flujo de producción

**Leyenda.** *Primitiva* es el contrato ejercitado en aislamiento (unidad, repositorio o esquema). *Flujo de producción* es el camino real cableado por router/controlador/servicio/repositorio o por la vista que consume la API. Una suite primitiva nunca se contabiliza como flujo. `No aplica` solo se usa cuando el criterio es DDL y no existe una ruta HTTP que lo ejecute.

| EARS | Regla verificada | Primitiva (aislamiento) | Flujo de producción (cableado) | Aserción de integración |
| :--- | :--- | :--- | :--- | :--- |
| **01.1** | Tipos #51–#55, Tablas 52–54, índices únicos parciales, `prevent_hard_delete()` e inmutabilidad; convergencia 1:1 sin `ALTER` de tablas previas | `PostgresDexAuditSchemaTest` **38/38** (convergencia, tablas, ENUM, índices y triggers); `PostgresSchemaTest` **168/168**; `PostgresConstitutionalTest` **91/91** | **No aplica al DDL**: el esquema no se ejecuta por HTTP. La inmutabilidad y la anulación se recorren en `DexAuditApiTest` 5.1–5.3 y `DexAuditConstitutionalTest` 5.1–5.3 | `PostgresDexAuditSchemaTest` (convergencia, 3 tablas, 5 ENUM, 11 índices, 7 triggers); `DexAuditConstitutionalTest` **5.1–5.3**; `DexAuditApiTest` **5.1–5.3** |
| **02.1** | Límite 1 MB, UTF-8/ISO-8859-1, envoltorio `DXS*`..`DXE*`, `VA1` obligatorio, duplicados y hash canónico | `EvaDtsParserAndCatalogTest` **1.1–1.18** | `DexAuditApiTest` 2.1 importa un `.dex` real; `DexAuditApiTest` 2.5 y `DexAuditConcurrencyTest` 1.1–1.3 recorren la huella duplicada bajo carrera | `DexAuditApiTest` **2.1, 2.5**; `DexAuditConcurrencyTest` **1.1–1.3** |
| **02.2** | `ID4` 0..4, cotas, `ID1`, `VA1`, `CA3`, `PA1`/`PA2`, `EA1`/`EA2` y segmentos desconocidos | `EvaDtsParserAndCatalogTest` **2.1–2.10, 3.1–3.9** | `DexAuditApiTest` 3.2 persiste VA1/CA3/PA1; 7.2–7.3 recorren los deltas derivados | `DexAuditApiTest` **2.1, 3.2, 7.2–7.3** |
| **03.1** | Usuario/máquina activos, tres puertas RBAC, perímetro del Reponedor/Técnico y bloqueo sanitario solo al Reponedor | `DexSelectionMatcherAndValidatorTest` **2.1–2.13**; `PdoMachineDexAuditRepositoryTest` **1.1–1.6** | `DexAuditApiTest` 1.1–1.4 (RBAC); `DexAuditConstitutionalTest` 3.1–3.3 (primacía sanitaria) | `DexAuditApiTest` **1.1–1.4**; `DexAuditConstitutionalTest` **3.1–3.3** |
| **03.2** | Proyecciones por rol sin euros ni `CA3` en móvil, Arqueo Ciego y opacidad Sede/QR/A4 | `DexAuditDomainModelsTest` **59/59**; `MachineDexAuditServiceTest` **1.3, 2.4**; `PdoMachineDexAuditRepositoryTest` **3.4** | `DexAuditApiTest` 2.2–2.4, 3.5–3.6; `DexAuditConstitutionalTest` 1.1–1.2, 2.1–2.4; `coordinator_dex_audits_tab.test.mjs` 2.5 | `DexAuditApiTest` **2.2–2.4, 3.5–3.6**; `DexAuditConstitutionalTest` **1.1–1.2, 2.1–2.4**; `coordinator_dex_audits_tab.test.mjs` **2.5** |
| **04.1** | Predecesor `ACTIVE` por `captured_at`, `RESET_FALLBACK`/`UNAVAILABLE`, `out_of_order_sync` y delta efectivo tras `VOIDED` sin reescribir filas | `DexSelectionMatcherAndValidatorTest` **3.1–3.12**; `PdoMachineDexAuditRepositoryTest` **3.9–3.10**; `MachineDexAuditServiceTest` **4.1–4.3** | `DexAuditApiTest` 7.1–7.9 (cadena A0→A→B, anulación intermedia y caso sin predecesor) | `DexAuditApiTest` **7.1–7.9**; `DexAuditConstitutionalTest` **5.1, 5.3** |
| **04.2** | Emparejamiento `PA1`/`PA2` exacto, ceros a la izquierda, `AMBIGUOUS`, `UNMAPPED` y modo `HOT_DRINKS` | `DexSelectionMatcherAndValidatorTest` **1.1–1.13**; `PdoMachineDexAuditRepositoryTest` **3.5** | `DexAuditApiTest` 2.1 importa `PA1`; 3.8–3.9 recorren consumo y delta por carril | `DexAuditApiTest` **2.1, 3.8–3.9** |
| **04.3** | Prefill M35, ausencia de mutación de existencias, enlace `dex_audit_id` y fórmulas cerradas M34/M35 | `DexAuditServiceIntegrationsTest` **1.0–4.3**; `PdoMachineDexAuditRepositoryTest` **2.1–2.5**; `MachineDexAuditServiceTest` **1.2** | `DexAuditApiTest` 2.2 prefill, 3.7–3.9 cruces, 9.1–9.3 enlaces M34/M35/M36; `restocker_route_view.test.mjs` prefill y enlace | `DexAuditApiTest` **2.2, 3.7–3.9, 9.1–9.3**; `restocker_route_view.test.mjs` (prefill y enlace) |
| **05.1** | Clasificación determinista de `EA1`/`EA2` por prefijos y fallback `OTHER` | `EvaDtsParserAndCatalogTest` **3.8, 4.1–4.8** | `DexAuditApiTest` 2.1 importa un evento `EG01`; 3.1 expone el KPI térmico; `DexAuditConstitutionalTest` 3.3 importa en `COMBO` | `DexAuditApiTest` **2.1, 3.1**; `DexAuditConstitutionalTest` **3.3** |
| **05.2** | Sugerencia M36/M16, banner térmico y Outbox anti-spam solo ante novedad | `MachineDexAuditServiceTest` **1.4–1.6, 3.1–3.3**; `EvaDtsParserAndCatalogTest` **3.8** | `DexAuditApiTest` 2.1–2.2 (importación y prefill); `restocker_route_view.test.mjs` «La alerta térmica activa el banner y la sugerencia M36». El encolado Outbox **no** tiene aserción HTTP y se declara como primitiva | `DexAuditApiTest` **2.1–2.2**; `restocker_route_view.test.mjs` (banner y sugerencia) |
| **06.1** | Validación local del sobre, micro-prelectura sin `CA3` y cola FIFO por operador con `client_mutation_id` | `eva_dts_offline_queue.test.mjs` **1.1–1.11, 2.1–2.5** | `restocker_route_view.test.mjs` (`handleDexFile`/`submitDexAudit`); `coordinator_dex_audits_tab.test.mjs` 3.1–3.3 valida el archivo en UI | `eva_dts_offline_queue.test.mjs` **1.1–1.11, 2.1–2.5**; `restocker_route_view.test.mjs` (pre-filtro y cola) |
| **06.2** | Replay FIFO idempotente, `DEX_DUPLICATE_HASH`, conflictos aislados y continuación de M34/M35/M36 | `eva_dts_offline_queue.test.mjs` **3.1–3.8** | `DexAuditApiTest` 6.1–6.2 (conflict-dump real); `restocker_route_view.test.mjs` (replay y desvinculación M35) | `DexAuditApiTest` **6.1–6.2**; `eva_dts_offline_queue.test.mjs` **3.1–3.8** |
| **07.1** | KPIs, filtros, carga manual, detalle completo, `/raw` seguro y descarga del original | `PdoMachineDexAuditRepositoryTest` **3.11–3.13**; `coordinator_dex_audits_tab.test.mjs` **1.1–3.5**; `DexAuditLayoutBrowserTest` **26/26** | `DexAuditApiTest` 3.1–3.4, 4.1–4.3; `DexAuditLayoutBrowserTest` 26/26 en Chromium real | `DexAuditApiTest` **3.1–3.4, 4.1–4.3**; `DexAuditLayoutBrowserTest` **26/26** |
| **07.2** | Anulación `ACTIVE → VOIDED` con motivo real, auditoría y CSV UTF-8 BOM neutralizado | `DexSelectionMatcherAndValidatorTest` **4.1–4.11**; `PdoMachineDexAuditRepositoryTest` **4.1–4.7** | `DexAuditApiTest` 5.1–5.4, 8.3–8.4; `coordinator_dex_audits_tab.test.mjs` 4.1–4.5, 5.2; `DexAuditConstitutionalTest` 5.1 | `DexAuditApiTest` **5.1–5.4, 8.3–8.4**; `coordinator_dex_audits_tab.test.mjs` **4.1–4.5, 5.2**; `DexAuditConstitutionalTest` **5.1** |

**Regla de lectura.** Las celdas de *flujo* solo citan suites que atraviesan el router/controlador/servicio/repositorio o la vista real. `PdoMachineDexAuditRepositoryTest`, `MachineDexAuditServiceTest`, `DexAuditServiceIntegrationsTest` y las suites de parser/matcher son primitivas y no se usan para declarar un flujo. En `02.1`/`02.2` las ramas de rechazo se certifican en la primitiva; el flujo HTTP cubre la importación real, no cada rama de error. En `05.2` el encolado Outbox no tiene aserción HTTP y se declara explícitamente como primitiva; el flujo de producción cubre la importación y el banner/sugerencia de la UI. La reestructuración no añadió suites: el recuento vigente es **14 suites / 488 aserciones**.

### 1.1. Implementación por requisito

| Requisito | Implementación |
| :--- | :--- |
| **RF-01** | `035_eva_dts_dex_audit.sql`, `supabase_init.sql`, `supabase_seeds.sql`, `SeedRunner`, `PostgresFixtureBuilder`, `TestDataCleaner` |
| **RF-02** | `EvaDtsParser`, `EvaDtsEventCatalog`, `DexAuditValidationException`, `DexAuditConflictException`, `DexAuditValidator` |
| **RF-03** | `MachineDexAuditService`, `DexAuditValidator`, `DexAuditForbiddenException`, `CoordinatorDexAuditController`, `RestockerRouteController`, `TechnicianController`, `AppRouter`, `MachineDexAudit` |
| **RF-04** | `DexSelectionMatcher`, `DexAuditValidator`, `MachineDexAuditService`, `MachineDexAuditGatewayInterface`, `PdoMachineDexAuditGateway`, `PdoMachineDexAuditRepository`, `DexAuditLinker` |
| **RF-05** | `EvaDtsEventCatalog`, `MachineDexAuditService`, `NotificationTemplate`, `NotificationMessageRenderer`, `EvaDtsClientPreview.js` |
| **RF-06** | `EvaDtsClientPreview.js`, `DexAuditOfflineQueue.js`, `public/sw.js` (`vendguard-app-shell-v34`), `RestockerRouteView.js`, `TechnicianRouteView.js` |
| **RF-07** | `CoordinatorDexAuditsSubTab.js`, `CoordinatorProductLoadingTab.js`, `api.js`, `CoordinatorDexAuditController` |

**Clasificación de suites:** son primitivas `PostgresDexAuditSchemaTest`, `DexAuditDomainModelsTest`, `EvaDtsParserAndCatalogTest`, `DexSelectionMatcherAndValidatorTest`, `MachineDexAuditServiceTest`, `DexAuditServiceIntegrationsTest` y `PdoMachineDexAuditRepositoryTest`. Son flujo o superficie `DexAuditApiTest`, `DexAuditConstitutionalTest`, `DexAuditConcurrencyTest` (servicio concurrente, no HTTP), `DexAuditPerformanceTest`, `DexAuditLayoutBrowserTest`, `eva_dts_offline_queue.test.mjs`, `coordinator_dex_audits_tab.test.mjs` y `restocker_route_view.test.mjs`. Las demás filas de regresión no acreditan EARS por sí solas.

### 1.2. Mapa de suites propias y regresiones directas

| Suite | Ruta | Recuento |
| :--- | :--- | ---: |
| `PostgresDexAuditSchemaTest` | [`tests/integration/PostgresDexAuditSchemaTest.php`](../../tests/integration/PostgresDexAuditSchemaTest.php) | 38/38 |
| `DexAuditDomainModelsTest` | [`tests/unit/DexAuditDomainModelsTest.php`](../../tests/unit/DexAuditDomainModelsTest.php) | 59/59 |
| `EvaDtsParserAndCatalogTest` | [`tests/unit/EvaDtsParserAndCatalogTest.php`](../../tests/unit/EvaDtsParserAndCatalogTest.php) | 48/48 |
| `DexSelectionMatcherAndValidatorTest` | [`tests/unit/DexSelectionMatcherAndValidatorTest.php`](../../tests/unit/DexSelectionMatcherAndValidatorTest.php) | 49/49 |
| `MachineDexAuditServiceTest` | [`tests/unit/MachineDexAuditServiceTest.php`](../../tests/unit/MachineDexAuditServiceTest.php) | 30/30 |
| `DexAuditServiceIntegrationsTest` | [`tests/unit/DexAuditServiceIntegrationsTest.php`](../../tests/unit/DexAuditServiceIntegrationsTest.php) | 19/19 |
| `PdoMachineDexAuditRepositoryTest` | [`tests/integration/PdoMachineDexAuditRepositoryTest.php`](../../tests/integration/PdoMachineDexAuditRepositoryTest.php) | 44/44 |
| `DexAuditApiTest` | [`tests/integration/DexAuditApiTest.php`](../../tests/integration/DexAuditApiTest.php) | 83/83 · incluye M34/M35/M36 por HTTP |
| `DexAuditConstitutionalTest` | [`tests/integration/DexAuditConstitutionalTest.php`](../../tests/integration/DexAuditConstitutionalTest.php) | 14/14 |
| `DexAuditConcurrencyTest` | [`tests/integration/DexAuditConcurrencyTest.php`](../../tests/integration/DexAuditConcurrencyTest.php) | 6/6 |
| `DexAuditPerformanceTest` | [`tests/integration/DexAuditPerformanceTest.php`](../../tests/integration/DexAuditPerformanceTest.php) | 3/3 · p95 **202,7 ms** con 20 muestras y `.dex` de ~1 MB |
| `eva_dts_offline_queue.test.mjs` | [`tests/unit/eva_dts_offline_queue.test.mjs`](../../tests/unit/eva_dts_offline_queue.test.mjs) | 26/26 |
| `coordinator_dex_audits_tab.test.mjs` | [`tests/frontend/coordinator_dex_audits_tab.test.mjs`](../../tests/frontend/coordinator_dex_audits_tab.test.mjs) | 43/43 |
| `DexAuditLayoutBrowserTest` | [`tests/integration/DexAuditLayoutBrowserTest.php`](../../tests/integration/DexAuditLayoutBrowserTest.php) | 26/26 · Chromium real `360 × 800` y `1440 × 900` |
| **Total propio** | **14 suites** | **488/488** |
| `PostgresSchemaTest` | [`tests/integration/PostgresSchemaTest.php`](../../tests/integration/PostgresSchemaTest.php) | 168/168 |
| `PostgresConstitutionalTest` | [`tests/integration/PostgresConstitutionalTest.php`](../../tests/integration/PostgresConstitutionalTest.php) | 91/91 |
| `TestDataCleanerIsolationTest` | [`tests/integration/TestDataCleanerIsolationTest.php`](../../tests/integration/TestDataCleanerIsolationTest.php) | 50/50 |
| `OfflinePwaAssetsAndUiGuardTest` | [`tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs`](../../tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs) | 45/45 |
| `AuditActionCatalogTest` | [`tests/unit/AuditActionCatalogTest.php`](../../tests/unit/AuditActionCatalogTest.php) | 27/27 |
| `AuditEntityTypesTest` | [`tests/unit/AuditEntityTypesTest.php`](../../tests/unit/AuditEntityTypesTest.php) | 41/41 |
| `restocker_route_view.test.mjs` | [`tests/frontend/restocker_route_view.test.mjs`](../../tests/frontend/restocker_route_view.test.mjs) | 269/269 |
| `coordinator_product_loading_tab.test.mjs` | [`tests/frontend/coordinator_product_loading_tab.test.mjs`](../../tests/frontend/coordinator_product_loading_tab.test.mjs) | 80/80 |
| `coordinator_restock_route_planner.test.mjs` | [`tests/frontend/coordinator_restock_route_planner.test.mjs`](../../tests/frontend/coordinator_restock_route_planner.test.mjs) | 43/43 |

---

## 2. Trazabilidad de Requisitos No Funcionales

| Requisito | Regla verificada | Evidencia |
| :--- | :--- | :--- |
| **RNF-01** · Rendimiento y presupuesto de memoria/latencia | 54 tablas y 55 tipos; `raw_payload` excluido de listados, KPIs y proyecciones móviles; tope de 1 MB; descarga solo en `/raw` de Coordinación; importación local con **p95 < 250 ms** | `PostgresDexAuditSchemaTest` **38/38**; `PdoMachineDexAuditRepositoryTest` **44/44**; `DexAuditApiTest` **83/83**; `DexAuditConstitutionalTest` **14/14**; `DexAuditPerformanceTest` **3/3** · p95 **202,7 ms** |
| **RNF-02** · Integridad SQL y defensa en profundidad | Triggers de no borrado e inmutabilidad por etapas; guarda sanitaria SQL; `CHECK` de motivo real y contadores; proyecciones por rol; `nosniff` + `attachment` | `PostgresDexAuditSchemaTest` **38/38**; `PostgresConstitutionalTest` **91/91**; `DexAuditConstitutionalTest` **14/14**; `DexAuditDomainModelsTest` **59/59**; `DexAuditApiTest` **83/83** |
| **RNF-03** · Dogma Vanilla y diseño visual | PHP 8.2+ y Vue 3 ESM sin dependencias; tokens de diseño; `360 × 800` y `1440 × 900` sin scroll horizontal del documento | `DexAuditLayoutBrowserTest` **26/26**; `coordinator_dex_audits_tab.test.mjs` **43/43**; `FrontendTemplateCompileTest.mjs` **15/15**; `OfflinePwaAssetsAndUiGuardTest.mjs` **45/45** |
| **RNF-04** · Zona horaria, codificación y canal `new_engine` | Fechas `Europe/Madrid`, persistencia `TIMESTAMPTZ`, JSON UTF-8; suite de esquema ejecutable en PostgreSQL y registrada en el canal `new_engine` | `PostgresDexAuditSchemaTest` **38/38**; `DexAuditConcurrencyTest` **6/6**; canal `new_engine` **85/85 suites · 2.950 aserciones · 0 fallos**; `exit=0` |

---

## 3. Decisiones, Casos Límite, Resoluciones QA y Artículos Constitucionales

> Esta sección inventaría cobertura de decisión y regresión; **no acredita por sí sola un flujo de producción**. La acreditación EARS → primitiva → flujo está en §1.

| Referencia | Decisión o caso | Cobertura |
| :--- | :--- | :--- |
| **D1** | Entidad propia inmutable y tres puertas de entrada | `PostgresDexAuditSchemaTest` **38/38**; `DexAuditDomainModelsTest` **59/59**; `DexAuditServiceIntegrationsTest` **19/19**; `DexAuditApiTest` **83/83** |
| **D2** | Blindaje de Arqueo Ciego y proyecciones Art. V.4 | `DexAuditDomainModelsTest` **59/59**; `DexAuditConstitutionalTest` **14/14**; `DexAuditApiTest` **83/83**; `restocker_route_view.test.mjs` **269/269** |
| **D3** | Parser determinista, tolerancia de firmware e identidad `ID1` | `EvaDtsParserAndCatalogTest` **48/48**; `DexSelectionMatcherAndValidatorTest` **49/49**; `PostgresDexAuditSchemaTest` **38/38** |
| **D4** | Asistencia en 1 toque y alerta térmica Art. II | `EvaDtsParserAndCatalogTest` **48/48**; `MachineDexAuditServiceTest` **30/30**; `DexAuditConstitutionalTest` **14/14**; `eva_dts_offline_queue.test.mjs` **26/26** |
| **D5** | Pre-relleno no destructivo M35 y lectura M34/M35 | `DexSelectionMatcherAndValidatorTest` **49/49**; `MachineDexAuditServiceTest` **30/30**; `DexAuditServiceIntegrationsTest` **19/19**; `PdoMachineDexAuditRepositoryTest` **44/44**; `DexAuditApiTest` **83/83** con `CA3_VS_VAULT`/`VA1_VS_EXPECTED` y consumo observado por carril |
| **D6** | Soporte Offline PWA con micro-prelectura JS | `eva_dts_offline_queue.test.mjs` **26/26**; `OfflinePwaAssetsAndUiGuardTest.mjs` **45/45**; `restocker_route_view.test.mjs` **269/269**; `DexAuditApiTest` **83/83** |
| **C1** | Retroceso o reseteo de contadores acumulados | `DexSelectionMatcherAndValidatorTest` **49/49**; `MachineDexAuditServiceTest` **30/30**; `DexAuditConstitutionalTest` **14/14** |
| **C2** | Permisos diferenciados en cuarentena, frío y fuera de servicio | `DexAuditServiceIntegrationsTest` **19/19**; `PostgresDexAuditSchemaTest` **38/38**; `DexAuditApiTest` **83/83**; `DexAuditConstitutionalTest` **14/14** |
| **C3** | Persistencia inmutable del `.dex` crudo | `PostgresDexAuditSchemaTest` **38/38**; `DexAuditDomainModelsTest` **59/59**; `DexAuditApiTest` **83/83**; `DexAuditConstitutionalTest` **14/14** |
| **C4** | Emparejamiento `PA1` en dos pasos y `HOT_DRINKS` | `DexSelectionMatcherAndValidatorTest` **49/49**; `DexAuditDomainModelsTest` **59/59**; `MachineDexAuditServiceTest` **30/30**; `PdoMachineDexAuditRepositoryTest` **44/44** |
| **C5** | Aislamiento de conflictos en replay Offline M24 | `MachineDexAuditServiceTest` **30/30**; `DexAuditConcurrencyTest` **6/6**; `eva_dts_offline_queue.test.mjs` **26/26**; `DexAuditApiTest` **83/83** |
| **C6** | Consola de Coordinación, anulación lógica y CSV | `coordinator_dex_audits_tab.test.mjs` **43/43**; `DexAuditLayoutBrowserTest` **26/26**; `DexAuditApiTest` **83/83**; `coordinator_product_loading_tab.test.mjs` **80/80** |
| **QA-1** | Prefill solo en el `POST` del Reponedor y bloqueo sanitario | `DexAuditDomainModelsTest` **59/59**; `DexAuditConstitutionalTest` **14/14**; `DexAuditApiTest` **83/83** |
| **QA-2** | Reseteos, orden Offline y deltas efectivos tras `VOIDED` | `DexSelectionMatcherAndValidatorTest` **49/49**; `MachineDexAuditServiceTest` **30/30**; `PdoMachineDexAuditRepositoryTest` **44/44**; `DexAuditApiTest` **83/83** con lectura A0→A→B, anulación intermedia y caso sin predecesor activo; `DexAuditConstitutionalTest` **14/14** |
| **QA-3** | Ventana/base del cruce M35 y consumo M34 | `MachineDexAuditServiceTest` **30/30**; `PdoMachineDexAuditRepositoryTest` **44/44**; `DexAuditApiTest` **83/83**; `coordinator_dex_audits_tab.test.mjs` **43/43** |
| **QA-4** | `EA202`/`EA203`/`EA205`, prefijos, `PA101` y anti-spam | `EvaDtsParserAndCatalogTest` **48/48**; `MachineDexAuditServiceTest` **30/30**; `PostgresDexAuditSchemaTest` **38/38**; `DexAuditConstitutionalTest` **14/14** |
| **QA-5** | Codificaciones, cabeceras, `DXE*`, `/raw` y enlaces `404`/`409`/`422` | `EvaDtsParserAndCatalogTest` **48/48**; `DexAuditServiceIntegrationsTest` **19/19**; `DexAuditApiTest` **83/83**; `eva_dts_offline_queue.test.mjs` **26/26** |
| **Art. II** | Primacía sanitaria transversal | `PostgresDexAuditSchemaTest` **38/38**; `DexAuditConstitutionalTest` **14/14**; `DexAuditApiTest` **83/83**; `DexSelectionMatcherAndValidatorTest` **49/49** |
| **Art. III.1 / III.2 / III.3** | Cero borrado físico, anulación lógica auditada e historial inmutable | `PostgresDexAuditSchemaTest` **38/38**; `DexAuditConstitutionalTest` **14/14**; `DexAuditApiTest` **83/83** con alta, enlace, anulación y CSV bajo `MACHINE_DEX_AUDIT`; `AuditActionCatalogTest` **27/27**; `TestDataCleanerIsolationTest` **50/50** |
| **Art. IV** | Dogma Vanilla y cero dependencias | `DexAuditLayoutBrowserTest` **26/26**; `coordinator_dex_audits_tab.test.mjs` **43/43**; `eva_dts_offline_queue.test.mjs` **26/26** |
| **Art. V.1** | Motivo de anulación de 20–500 caracteres reales y ≥ 3 bloques | `DexAuditDomainModelsTest` **59/59**; `DexAuditApiTest` **83/83**; `PostgresDexAuditSchemaTest` **38/38**; `DexAuditConstitutionalTest` **14/14** |
| **Art. V.4** | Opacidad de Sede/QR/A4, Reponedor y Técnico | `DexAuditDomainModelsTest` **59/59**; `DexAuditConstitutionalTest` **14/14**; `DexAuditApiTest` **83/83**; `restocker_route_view.test.mjs` **269/269** |
| **Art. VI** | Fuera de alcance: hardware, IoT remoto y preventivo por desgaste | `EvaDtsParserAndCatalogTest` **48/48**; `DexAuditDomainModelsTest` **59/59**; `DexAuditApiTest` **83/83**; `coordinator_dex_audits_tab.test.mjs` **43/43** |

---

## 4. Criterios de Finalización (Definition of Done)

| DoD | Criterio | Evidencia |
| :--- | :--- | :--- |
| **DoD 1** | Migración `035`, convergencia de `supabase_init.sql`, tipos #51–#55, Tablas 52–54, índices y triggers sin alterar DDL previo | `PostgresDexAuditSchemaTest` **38/38**; `PostgresSchemaTest` **168/168**; `PostgresConstitutionalTest` **91/91**; `TestDataCleanerIsolationTest` **50/50** |
| **DoD 2** | Parser, validador, servicio, deltas, anti-spam, cruces, anulación, CSV y proyecciones por rol al 100 % | ✅ Cumplido, incluida la latencia de `RNF-01` · `DexAuditDomainModelsTest` **59/59**; `EvaDtsParserAndCatalogTest` **48/48**; `DexSelectionMatcherAndValidatorTest` **49/49**; `MachineDexAuditServiceTest` **30/30**; `DexAuditApiTest` **83/83**; `DexAuditServiceIntegrationsTest` **19/19**; `PdoMachineDexAuditRepositoryTest` **44/44**; `DexAuditPerformanceTest` **3/3** |
| **DoD 3** | Integración móvil, consola de Coordinación y cola Offline PWA con conflictos | `DexAuditApiTest` **83/83**; `eva_dts_offline_queue.test.mjs` **26/26**; `coordinator_dex_audits_tab.test.mjs` **43/43**; `restocker_route_view.test.mjs` **269/269**; `OfflinePwaAssetsAndUiGuardTest.mjs` **45/45** |
| **DoD 4** | Batería global, canal `new_engine` y navegador real al 100 % en verde, con matriz de trazabilidad | ✅ Cumplido · Global **461/461 suites · 18.181 aserciones · 0 fallos**; `new_engine` **85/85 suites · 2.950 aserciones · 0 fallos**; `DexAuditLayoutBrowserTest` **26/26**; `DocMetricsGuard` coherente; matriz EARS **14/14** con primitiva, flujo y aserción de integración |

---

## 5. Comandos y evidencia de la corrida de cierre

```bash
php tests/run_all.php
# 461 suites · 18.181 aserciones · 0 fallos · DocMetricsGuard COHERENTE · exit=0

source deploy/local/postgres/.env.docker-tls
php tests/run_new_engine.php
# 85 suites · 2.950 aserciones · 0 fallos · exit=0
```

**Regresiones directas de la corrida global:** `restocker_route_view.test.mjs` **269/269**, `coordinator_product_loading_tab.test.mjs` **80/80**, `coordinator_restock_route_planner.test.mjs` **43/43**, `AuditActionCatalogTest` **27/27**, `AuditEntityTypesTest` **41/41**, `OfflinePwaAssetsAndUiGuardTest` **45/45** y `TestDataCleanerIsolationTest` **50/50**.

---

## 6. Mantenimiento de esta matriz

* Toda tarea nueva del Módulo 39 debe añadir su fila de evidencia y actualizar el recuento de suites propias.
* Las cifras de la corrida de cierre se sincronizan con `README.md` y `docs/features_pendientes.md`; `DocMetricsGuard` las audita en cada ejecución de `php tests/run_all.php`.
* El recuento del canal `new_engine` se declara en `deploy/local/postgres/README.md`; `NewEngineChannelDocumentationTest` lo compara con las suites descubiertas.
* Una suite propia que cambie de recuento obliga a actualizar esta matriz, el ODD y la tabla de tareas en el mismo commit.
* Cada criterio EARS debe conservar una fila con primitiva, flujo de producción y aserción de integración; una suite primitiva no puede rellenar sola la columna de flujo.
* Las ramas sin aserción HTTP se declaran como primitivas en la propia fila; no se promocionan a «flujo» por aparecer en un test de unidad.
