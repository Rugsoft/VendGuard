# MATRIZ DE TRAZABILIDAD · MÓDULO 34

**Especificación:** [`spec.md`](spec.md) (v2.0) · **Contratos:** [`../technical/in_situ_restock_visit_contracts.md`](../technical/in_situ_restock_visit_contracts.md) · **Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md)

**Esquema de referencia canónico:** `RF-01` evolución del carril y tablas históricas inmutables · `RF-02` Triple Cerrojo Sanitario · `RF-03` higiene, evidencia y actualización transaccional · `RF-04` cola Offline y conflictos · `RF-05` superficies de Reponedor y Coordinación.

**Corrida de certificación:** 2026-11-14 (PostgreSQL 16+ local, batería global y canal `new_engine`).

**Actualización T-RVF-13:** 2026-11-14 · cierre de la certificación global. Se corrigieron dos deudas reales descubiertas por la batería: la geometría inline de dos botones del asistente móvil (`mt-1`/`ms-1` en la escalera del sistema) y las nueve acciones del Módulo 33 que el visor de auditoría no catalogaba (`PRODUCT_LOADING_ORDER_*`, `RESTOCKER_VAN_STOCK_*`), resolviendo además las constantes `self::AUDIT_*` en la guarda. El plan de purga pasa de 35 a 37 tablas transitorias por las dos tablas del Módulo 34. Las cifras documentales quedan sincronizadas con la corrida.

**Estado:** ✅ Módulo 34 cerrado · 13/13 tareas · batería global al 100 % · `DocMetricsGuard` coherente.

---

## 1. Requisitos Funcionales y criterios EARS

### RF-01 · Evolución de `machine_planogram_slots` y tablas históricas inmutables

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **01.1** Tabla 43 con `current_batch_code`/`current_expiration_date`, formato cerrado del lote y semáforo `EXPIRED`/`EXPIRING_SOON`/`OK` (`D1`, `QA 1`, `Art. III.1`) | migración `030_in_situ_restock_visit_and_fefo.sql`, `database/supabase_init.sql`, `MachinePlanogramSlot`, `PdoMachinePlanogramRepository`, `MachinePlanogramService` | `PostgresInSituRestockVisitSchemaTest` · `InSituRestockVisitDomainModelsTest` · `PdoMachinePlanogramRepositoryTest` · `MachinePlanogramServiceTest` | 34/34 ✅ · 75/75 ✅ · 46/46 ✅ · 38/38 ✅ |
| **01.2** Tablas 47/48, tipo `restock_visit_status`, `MACHINE_RESTOCK_VISIT`, inmutabilidad, idempotencia `(restocker_id, client_mutation_id)` y auditoría (`D4`, `C1`, `Art. III.1`, `Art. III.3`) | migración `030`, `database/supabase_init.sql`, `MachineRestockVisit`, `MachineRestockVisitItem`, `PdoMachineRestockVisitRepository`, `MachineRestockVisitService` | `PostgresInSituRestockVisitSchemaTest` · `PostgresConstitutionalTest` · `PdoMachineRestockVisitRepositoryTest` · `MachineRestockVisitServiceTest` · `InSituRestockVisitConstitutionalTest` | 34/34 ✅ · 85/85 ✅ · 29/29 ✅ · 33/33 ✅ · 38/38 ✅ |

### RF-02 · Triple Cerrojo Sanitario Innegociable

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **02.1** Cerrojo 1: rol `RESTOCKER`, máquina activa, perímetro de zonas y bloqueo `409 SANITARY_RESTOCK_BLOCKED` por cuarentena, fuera de servicio o avería térmica activa (`D2`, `C2`, `Art. II`) | `MachineRestockVisitValidator::assertMachineAvailable`, `MachineRestockVisitService`, `PdoInSituRestockVisitGateway`, `RestockerRouteController`, `AppRouter` | `MachineRestockVisitValidatorTest` · `MachineRestockVisitServiceTest` · `InSituRestockVisitApiTest` · `InSituRestockVisitConstitutionalTest` · `RestockerRbacConstitutionalTest` | 71/71 ✅ · 33/33 ✅ · 22/22 ✅ · 38/38 ✅ · 27/27 ✅ |
| **02.2** Cerrojo 2: lectura obligatoria en refrigeradas, rango `[-5,0, 35,0] °C`, techo 4,0 °C / 8,0 °C, protocolo `ABORTED_THERMAL_BREACH`, precinto, foto, nota ≥ 20, cuarentena y ticket `CRITICAL` idempotente (`D2`, `QA 2`, `QA 4`, `Art. II`, `Art. V.1`, `Art. V.2`, `Art. V.5`) | `MachineRestockVisitValidator::assertCabinTemperature/assertEvidence`, `MachineRestockVisitService`, `PdoInSituRestockVisitGateway`, `enforce_restock_visit_sanitary_guard()`, `RestockerRouteView.js` | `MachineRestockVisitValidatorTest` · `MachineRestockVisitServiceTest` · `PostgresInSituRestockVisitSchemaTest` · `InSituRestockVisitConstitutionalTest` · `restocker_route_view.test.mjs` | 71/71 ✅ · 33/33 ✅ · 34/34 ✅ · 38/38 ✅ · 171/171 ✅ |
| **02.3** Cerrojo 3: retirada total de lote caducado o ≤ 48 h antes de cargar (`422 FEFO_WITHDRAWAL_REQUIRED`), lote de furgoneta existente y con saldo, no caducado (`422 EXPIRED_VAN_BATCH_FORBIDDEN`) y FEFO contra otro lote sano más antiguo (`422 FEFO_OLDER_VAN_BATCH_AVAILABLE`) (`D1`, `QA 1`, `Art. II`) | `MachineRestockVisitValidator::assertSlotMovement/assertVanBatchSelection`, `MachineRestockVisitService`, `PdoMachinePlanogramRepository::applyVisitSlotMovement`, `PdoRestockerVanStockRepository`, `RestockerRouteView.js` | `MachineRestockVisitValidatorTest` · `MachineRestockVisitServiceTest` · `PdoMachinePlanogramRepositoryTest` · `InSituRestockVisitApiTest` · `InSituRestockVisitConstitutionalTest` · `restocker_route_view.test.mjs` | 71/71 ✅ · 33/33 ✅ · 46/46 ✅ · 22/22 ✅ · 38/38 ✅ · 171/171 ✅ |

### RF-03 · Higiene diaria, evidencia y actualización transaccional

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **03.1** Checklist adaptativo por `MachineType`: cuatro puntos en `HOT_DRINKS`/`COMBO`, dos en `COLD_DRINKS`/`SNACKS`/`PERISHABLE_FOOD`, con normalización a `null` de los indicadores de café (`D3`) | `DailyHygieneChecklist`, `MachineRestockVisitValidator::assertHygieneChecklist`, `MachineRestockVisitService`, `RestockerRouteView.js` | `InSituRestockVisitDomainModelsTest` · `MachineRestockVisitValidatorTest` · `MachineRestockVisitServiceTest` · `restocker_route_view.test.mjs` | 75/75 ✅ · 71/71 ✅ · 33/33 ✅ · 171/171 ✅ |
| **03.2** Foto obligatoria con aborto o retiradas, límite 5 MB y magic bytes reales JPG/PNG/WebP; nota interna ≥ 20 caracteres reales (`D3`, `QA 4`, `Art. V.1`, `Art. V.5`) | `MachineRestockVisitValidator::assertEvidence/detectImageMime`, `MachineRestockVisitService::uploadPhoto`, `RestockerRouteController`, `RestockerRouteView.js` | `MachineRestockVisitValidatorTest` · `MachineRestockVisitServiceTest` · `InSituRestockVisitApiTest` · `InSituRestockVisitConstitutionalTest` · `restocker_route_view.test.mjs` | 71/71 ✅ · 33/33 ✅ · 22/22 ✅ · 38/38 ✅ · 171/171 ✅ |
| **03.3** Actualización atómica con `FOR UPDATE`: cotas del carril, descuento por lote de furgoneta, regla `D1` del lote vigente, vínculo a la orden y snapshot `unit_cost_snapshot_eur`/`commercial_spoilage_loss_eur` (`D1`, `C1`, `QA 3`, `Art. V.4`) | `MachineRestockVisitService::registerVisit`, `PdoMachinePlanogramRepository::applyVisitSlotMovement`, `PdoRestockerVanStockRepository`, `PdoMachineRestockVisitRepository` | `MachineRestockVisitServiceTest` · `PdoMachinePlanogramRepositoryTest` · `PdoRestockerVanStockRepositoryTest` · `PdoMachineRestockVisitRepositoryTest` · `InSituRestockVisitConstitutionalTest` | 33/33 ✅ · 46/46 ✅ · 28/28 ✅ · 29/29 ✅ · 38/38 ✅ |
| **03.4** Pre-cálculo `C4` de `suggested_restocked_in_machines_qty` y `suggested_withdrawn_spoilage_qty` desde las visitas del reponedor desde `confirmed_at` (`C4`) | `PdoMachineRestockVisitRepository::suggestLiquidationQuantities`, `ProductLoadingOrderService::buildDetail`, `RestockerRouteView.js` | `PdoMachineRestockVisitRepositoryTest` · `ProductLoadingOrderServiceTest` · `InSituRestockVisitApiTest` · `restocker_route_view.test.mjs` | 29/29 ✅ · 53/53 ✅ · 22/22 ✅ · 171/171 ✅ |

### RF-04 · Cola Offline PWA y resolución de conflictos

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **04.1** Encolado en `IndexedDB` aislado por `restocker_id`, orden FIFO, foto en `Blob`, proyección optimista y sincronización idempotente (`D4`, `C3`) | `RestockVisitOfflineQueue.js`, `RestockerRouteView.js`, `api.js`, `public/sw.js` | `restocker_route_view.test.mjs` · `OfflinePwaAssetsAndUiGuardTest.mjs` · `FrontendTemplateCompileTest.mjs` | 171/171 ✅ · 45/45 ✅ · 15/15 ✅ |
| **04.2** `409 OFFLINE_VISIT_CONFLICT`/`SANITARY_RESTOCK_BLOCKED` con causa, archivo en `audit_log` y continuación de la cola (`C3`, `Art. III.3`) | `RestockerRouteView.js`, `api.dumpVisitConflict`, `MachineRestockVisitService::dumpOfflineConflict`, `RestockerRouteController` | `restocker_route_view.test.mjs` · `MachineRestockVisitServiceTest` · `InSituRestockVisitApiTest` · `InSituRestockVisitConstitutionalTest` | 171/171 ✅ · 33/33 ✅ · 22/22 ✅ · 38/38 ✅ |

### RF-05 · Superficies de Reponedor y Coordinación

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **05.1** Asistente móvil con insignias FEFO, control térmico, retirada/carga, checklist, foto, historial y cola Offline (`D1`–`D4`, `C4`, `RNF-03`, `Art. V.4`) | `RestockerRouteView.js`, `RestockVisitOfflineQueue.js`, `api.restocker`, `public/sw.js` | `restocker_route_view.test.mjs` · `FrontendTemplateCompileTest.mjs` · `CoordinatorButtonVariantsGuardTest.mjs` · `OfflinePwaAssetsAndUiGuardTest.mjs` · `DesignTokenDebtRatchetTest.mjs` | 171/171 ✅ · 15/15 ✅ · 23/23 ✅ · 45/45 ✅ · 3/3 ✅ |
| **05.2** Sub-vista de Coordinación con cinco KPIs, filtros y modal carril a carril con snapshot contable exclusivo (`D4`, `QA 3`, `Art. V.4`) | `CoordinatorProductLoadingTab.js`, `api.coordinator`, `CoordinatorProductLoadingController` | `coordinator_product_loading_tab.test.mjs` · `InSituRestockVisitApiTest` · `FrontendTemplateCompileTest.mjs` · `CoordinatorButtonVariantsGuardTest.mjs` | 63/63 ✅ · 22/22 ✅ · 15/15 ✅ · 23/23 ✅ |

---

## 2. Requisitos No Funcionales

| RNF | Artefacto | Test | Resultado medido |
|---|---|---|---|
| **RNF-01** prohibición de borrado físico e inmutabilidad de Tablas 47/48 (`Art. III.1`, `Art. III.3`) | `prevent_hard_delete()`, `prevent_restock_visit_mutation()`, `trg_no_delete_machine_restock_visits`, `trg_no_delete_machine_restock_visit_items`, `trg_immutable_*` | `PostgresInSituRestockVisitSchemaTest` · `PostgresConstitutionalTest` · `PdoMachineRestockVisitRepositoryTest` · `TestDataCleanerIsolationTest` | ✅ 34/34 · 85/85 · 29/29 · 50/50 — `DELETE` y `UPDATE` rechazados con `SQLSTATE 45000`; el plan de purga cubre las 37 tablas transitorias |
| **RNF-02** defensa en profundidad del Triple Cerrojo Sanitario (`Art. II`) | `MachineRestockVisitValidator` + guardas `enforce_restock_visit_sanitary_guard()` y `enforce_restock_visit_item_guard()` | `MachineRestockVisitValidatorTest` · `PostgresInSituRestockVisitSchemaTest` · `InSituRestockVisitConstitutionalTest` · `MachineRestockVisitServiceTest` | ✅ 71/71 · 34/34 · 38/38 · 33/33 — dominio y PostgreSQL rechazan la misma combinación térmica y FEFO |
| **RNF-03** Sistema de Diseño, Dogma Vanilla y PWA (`Art. IV`) | `RestockerRouteView.js`, `CoordinatorProductLoadingTab.js`, `public/sw.js`, tokens `vg-*` | `restocker_route_view.test.mjs` · `coordinator_product_loading_tab.test.mjs` · `CoordinatorButtonVariantsGuardTest.mjs` · `FrontendTemplateCompileTest.mjs` · `DesignTokenDebtRatchetTest.mjs` · `OfflinePwaAssetsAndUiGuardTest.mjs` | ✅ 171/171 · 63/63 · 23/23 · 15/15 · 3/3 · 45/45 — 25 plantillas y 375 botones sin geometría inline, 79 plantillas / 4.911 expresiones compiladas, sin costes en la vista móvil |

---

## 3. Decisiones, casos límite y resoluciones QA

| Elemento | Escenario | Test | Resultado |
|---|---|---|---|
| **D1** Lote vigente del carril | Carril vacío sin traza; lote cargado si se retira todo el remanente; `LEAST` de caducidades si conviven remanente sano y carga nueva | `InSituRestockVisitDomainModelsTest` · `PdoMachinePlanogramRepositoryTest` · `MachineRestockVisitServiceTest` · `PostgresInSituRestockVisitSchemaTest` | ✅ 75/75 · 46/46 · 33/33 · 34/34 |
| **D2** Excursión térmica vs merma comercial | Aborto con cuarentena y ticket `CRITICAL`; retirada con frío correcto sin cuarentena y con snapshot de merma | `MachineRestockVisitValidatorTest` · `MachineRestockVisitServiceTest` · `InSituRestockVisitConstitutionalTest` · `PostgresInSituRestockVisitSchemaTest` | ✅ 71/71 · 33/33 · 38/38 · 34/34 |
| **D3** Checklist adaptativo y foto condicional | Cuatro puntos en caliente/mixta, dos en frío/snacks/perecedero; foto obligatoria con aborto o retiradas | `InSituRestockVisitDomainModelsTest` · `MachineRestockVisitValidatorTest` · `MachineRestockVisitServiceTest` · `restocker_route_view.test.mjs` | ✅ 75/75 · 71/71 · 33/33 · 171/171 |
| **D4** Idempotencia y Offline | Reenvío por `client_mutation_id` sin duplicar stock; cola FIFO por reponedor y conflicto archivable | `MachineRestockVisitServiceTest` · `PdoMachineRestockVisitRepositoryTest` · `InSituRestockVisitApiTest` · `restocker_route_view.test.mjs` | ✅ 33/33 · 29/29 · 22/22 · 171/171 |
| **C1** Inmutabilidad y cotas | `DELETE`/`UPDATE` rechazados; `qty_after` y retiradas dentro de las cotas del carril | `PostgresInSituRestockVisitSchemaTest` · `PdoMachineRestockVisitRepositoryTest` · `InSituRestockVisitConstitutionalTest` | ✅ 34/34 · 29/29 · 38/38 |
| **C2** Perímetro territorial y rol | Solo `RESTOCKER` activo sobre máquinas activas de sus zonas; fuera de perímetro `403 MACHINE_OUTSIDE_RESTOCKER_ZONES` | `InSituRestockVisitApiTest` · `InSituRestockVisitConstitutionalTest` · `MachineRestockVisitServiceTest` · `RestockerRbacConstitutionalTest` | ✅ 22/22 · 38/38 · 33/33 · 27/27 |
| **C3** Conflicto Offline | Cuarentena o saldo insuficiente al sincronizar: `409` con causa y volcado en auditoría sin tocar inventario | `MachineRestockVisitServiceTest` · `InSituRestockVisitApiTest` · `InSituRestockVisitConstitutionalTest` · `restocker_route_view.test.mjs` | ✅ 33/33 · 22/22 · 38/38 · 171/171 |
| **C4** Pre-cálculo de liquidación | Suma por producto/lote de cargas y retiradas desde `confirmed_at`, con `0` fuera de `CONFIRMED_BY_RESTOCKER` | `PdoMachineRestockVisitRepositoryTest` · `ProductLoadingOrderServiceTest` · `InSituRestockVisitApiTest` · `restocker_route_view.test.mjs` | ✅ 29/29 · 53/53 · 22/22 · 171/171 |
| **QA 1** Doble regla FEFO | Retirada total obligatoria en carril caducado/≤ 48 h; lote de furgoneta no caducado y más antiguo primero | `MachineRestockVisitValidatorTest` · `MachineRestockVisitServiceTest` · `InSituRestockVisitConstitutionalTest` · `restocker_route_view.test.mjs` | ✅ 71/71 · 33/33 · 38/38 · 171/171 |
| **QA 2** Ticket térmico idempotente | Alta `CRITICAL`/`THERMAL_BREAKDOWN` sin ticket previo; elevación y nota interna si ya había uno activo | `MachineRestockVisitServiceTest` · `InSituRestockVisitConstitutionalTest` · `PdoMachineRestockVisitRepositoryTest` | ✅ 33/33 · 38/38 · 29/29 |
| **QA 3** Snapshot y opacidad de costes | `unit_cost_snapshot_eur` y `commercial_spoilage_loss_eur` solo en Coordinación; móvil, técnico y QR sin costes | `InSituRestockVisitDomainModelsTest` · `PdoMachineRestockVisitRepositoryTest` · `InSituRestockVisitApiTest` · `InSituRestockVisitConstitutionalTest` · `coordinator_product_loading_tab.test.mjs` | ✅ 75/75 · 29/29 · 22/22 · 38/38 · 63/63 |
| **QA 4** Rango térmico y nota mínima | Lectura obligatoria en refrigeradas, rango físico `[-5,0, 35,0] °C`, nota ≥ 20 caracteres reales con aborto o mermas | `MachineRestockVisitValidatorTest` · `PostgresInSituRestockVisitSchemaTest` · `InSituRestockVisitConstitutionalTest` · `restocker_route_view.test.mjs` | ✅ 71/71 · 34/34 · 38/38 · 171/171 |

---

## 4. Definición de Hecho (§8)

| DoD | Evidencia |
|---|---|
| **1.** Tabla 43 evolucionada, Tablas 47/48, tipo #31, `audit_entity_type`, `CHECK`, anti-borrado, inmutabilidad y guarda térmica | `PostgresInSituRestockVisitSchemaTest` 34/34 · `PostgresSchemaTest` 150/150 · `PostgresConstitutionalTest` 85/85 · `SchemaConvergenceParityTest` 36/36 · `CloudDeploySchemaParityTest` 55/55 |
| **2.** Triple Cerrojo Sanitario: cuarentena/fuera de servicio/avería térmica, protocolo de aborto con ticket idempotente y doble FEFO de carril y furgoneta | `MachineRestockVisitValidatorTest` 71/71 · `MachineRestockVisitServiceTest` 33/33 · `InSituRestockVisitApiTest` 22/22 · `InSituRestockVisitConstitutionalTest` 38/38 · `PostgresInSituRestockVisitSchemaTest` 34/34 |
| **3.** Cierre `COMPLETED` con checklist, descuento atómico, lote del carril, snapshot financiero y pre-cálculo `C4` | `MachineRestockVisitServiceTest` 33/33 · `PdoMachinePlanogramRepositoryTest` 46/46 · `PdoMachineRestockVisitRepositoryTest` 29/29 · `ProductLoadingOrderServiceTest` 53/53 · `restocker_route_view.test.mjs` 171/171 |
| **4.** Asistente móvil, foto ≤ 5 MB, cola Offline y consola de Coordinación con KPIs y costes | `restocker_route_view.test.mjs` 171/171 · `coordinator_product_loading_tab.test.mjs` 63/63 · `FrontendTemplateCompileTest.mjs` 15/15 (79 plantillas / 4.911 expresiones) · `CoordinatorButtonVariantsGuardTest.mjs` 23/23 · `OfflinePwaAssetsAndUiGuardTest.mjs` 45/45 |
| **5.** Batería global y canal `new_engine` | `php tests/run_all.php` — 432 suites / 16.381 aserciones / 0 fallos, `DocMetricsGuard` coherente · `php tests/run_new_engine.php` — 60 suites / 1.968 aserciones / 60/60 en verde, sin fallos |

---

## 5. Mantenimiento

Esta matriz es un artefacto vivo: cualquier cambio en un `RF`, criterio EARS, `RNF`, decisión `D`, caso límite `C` o resolución `QA` debe actualizar la fila correspondiente y su resultado medido en la misma entrega. La cifra viva de suites y aserciones se declara solo en el README y en `docs/features_pendientes.md`; el resto de documentos la referencia sin duplicarla.
