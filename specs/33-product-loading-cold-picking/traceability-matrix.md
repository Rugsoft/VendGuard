# MATRIZ DE TRAZABILIDAD · MÓDULO 33

**Especificación:** [`spec.md`](spec.md) (v2.0) · **Contratos:** [`../technical/product_loading_cold_picking_contracts.md`](../technical/product_loading_cold_picking_contracts.md) · **Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md)

**Esquema de referencia canónico:** `RF-01` inventario de furgoneta por lote · `RF-02` propuesta y emisión de órdenes · `RF-03` edición, Hoja de Picking A4 y despacho con frío · `RF-04` recepción móvil y liquidación · `RF-05` consolas de Coordinación y Reponedor.

**Corrida de certificación:** 2026-11-13 (PostgreSQL 16+ local, batería global y canal `new_engine`).

**Actualización B-01/B-02/B-03/B-04/B-05/B-06/B-07:** 2026-11-13 · cierre de la brecha funcional de `C2` (propuesta y emisión individual para un reponedor ausente, alerta en consola y exclusión mantenida en el lote), de las omisiones del lote (`SKIP_ACTIVE_ORDER`, `SKIP_NO_DEMAND`), de la tolerancia a un producto desactivado en tránsito, de la cancelación justificada desde `DISPATCHED`, de la ruta HTTP de liquidación de Coordinación, de la colisión de caducidad al confirmar la recepción y del doble clic concurrente de recepción y liquidación. Filas `02.3`, `C2`, `C3`, `03.4`, `04.1` y `04.2` actualizadas con las pruebas nuevas.

**Estado:** ✅ Módulo 33 cerrado · 13/13 tareas · batería global al 100 % · `DocMetricsGuard` coherente.

---

## 1. Requisitos Funcionales y criterios EARS

### RF-01 · Inventario de producto en furgoneta por lote, semáforo y ajuste auditado

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **01.1** Tabla 44, unicidad `(restocker_id, product_id, batch_code)`, cotas, anti-borrado, lote normalizado y entidad auditable (`D2`, `C1`, `Art. III.1`) | `database/supabase_migrations/029_product_loading_orders_and_cold_picking.sql`, `database/supabase_init.sql`, `RestockerVanProductStock`, `PdoRestockerVanStockRepository`, `RestockerVanStockService` | `PostgresProductLoadingSchemaTest` · `ProductLoadingDomainModelsTest` · `PdoRestockerVanStockRepositoryTest` · `SchemaConvergenceParityTest` | 39/39 ✅ · 97/97 ✅ · 28/28 ✅ · 31/31 ✅ |
| **01.2** Semáforo determinista `EXPIRED` / `EXPIRING_SOON` / `OK`, exclusión del stock útil y alerta de retirada (`D1`, `D4`, `Art. II`) | `BatchExpiryStatus`, `RestockerVanProductStock`, `PdoRestockerVanStockRepository::findByRestocker/sumUsableQuantityByProduct`, `RestockerVanStockService`, `RestockerRouteView.js` | `ProductLoadingDomainModelsTest` · `PdoRestockerVanStockRepositoryTest` · `RestockerVanStockServiceTest` · `restocker_route_view.test.mjs` | 97/97 ✅ · 28/28 ✅ · 31/31 ✅ · 108/108 ✅ |
| **01.3** Ajuste manual con motivo ≥ 20 caracteres reales, lote/caducidad, colisión de fecha y auditoría de saldo (`D4`, `QA 2`, `QA 3`, `Art. III.3`, `Art. V.1`) | `ProductLoadingValidator`, `RestockerVanStockService::adjustStock`, `CoordinatorProductLoadingController`, `CoordinatorProductLoadingTab.js` | `ProductLoadingValidatorTest` · `RestockerVanStockServiceTest` · `ProductLoadingColdPickingApiTest` · `ProductLoadingConstitutionalTest` · `coordinator_product_loading_tab.test.mjs` | 57/57 ✅ · 31/31 ✅ · 34/34 ✅ · 30/30 ✅ · 50/50 ✅ |

### RF-02 · Propuesta automática, ordenación y emisión individual o en lote

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **02.1** Demanda por zonas cubiertas, exclusión de cuarentena/fuera de servicio, modos `ALERTS_ONLY`/`ALL_GAPS`, colchón `0..50 %` y descuento de stock útil (`D1`, `QA 1`) | `PdoProductLoadingOrderRepository::fetchProposalInputs`, `ProductLoadingOrderService::buildProposal`, `CoordinatorProductLoadingController` | `PdoProductLoadingOrderRepositoryTest` · `ProductLoadingOrderServiceTest` · `ProductLoadingColdPickingApiTest` · `ProductLoadingConstitutionalTest` | 30/30 ✅ · 51/51 ✅ · 34/34 ✅ · 30/30 ✅ |
| **02.2** Ordenación frío → prioridad de motivo → `sku` en propuesta, orden y Hoja A4 (`D4`) | `PdoProductLoadingOrderRepository` (orden de líneas), `ProductLoadingOrderService::buildDetail/buildPickingSheet`, `CoordinatorProductLoadingTab.js` | `PdoProductLoadingOrderRepositoryTest` · `ProductLoadingOrderServiceTest` · `coordinator_product_loading_tab.test.mjs` | 30/30 ✅ · 51/51 ✅ · 50/50 ✅ |
| **02.3** Orden activa única, destinatario `RESTOCKER` activo, propuesta y emisión individual de un ausente con alerta C2 y emisión masiva con omitidos justificados por ausencia, orden activa o demanda cero (`D3`, `C2`) | `PdoProductLoadingOrderRepository::create/fetchProposalInputs`, `ProductLoadingOrderService::createOrder/createBatchOrders`, `CoordinatorProductLoadingController`, `CoordinatorProductLoadingTab.js` | `PdoProductLoadingOrderRepositoryTest` · `ProductLoadingOrderServiceTest` · `ProductLoadingColdPickingApiTest` · `ProductLoadingConstitutionalTest` · `coordinator_product_loading_tab.test.mjs` | 30/30 ✅ · 51/51 ✅ · 34/34 ✅ · 30/30 ✅ · 50/50 ✅ |

### RF-03 · Edición, Hoja de Picking A4 y despacho con cerrojo sanitario

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **03.1** Tablas 45/46, `UPSERT`, baja lógica `is_active = FALSE`, cotas y anti-borrado (`C1`, `Art. III.1`, `Art. III.3`) | migración `029`, `database/supabase_init.sql`, `ProductLoadingOrder`, `ProductLoadingOrderItem`, `PdoProductLoadingOrderRepository`, `ProductLoadingOrderService::updateOrder` | `PostgresProductLoadingSchemaTest` · `PdoProductLoadingOrderRepositoryTest` · `ProductLoadingOrderServiceTest` · `ProductLoadingConstitutionalTest` | 39/39 ✅ · 30/30 ✅ · 51/51 ✅ · 30/30 ✅ |
| **03.2** Hoja A4 en dos secciones, casillas y firmas, sin `unit_cost_eur` ni `sale_price_eur` (`D4`, `Art. V.4`) | `ProductLoadingOrderService::buildPickingSheet`, `CoordinatorProductLoadingController::pickingSheet`, `CoordinatorProductLoadingTab.js` | `ProductLoadingOrderServiceTest` · `ProductLoadingColdPickingApiTest` · `coordinator_product_loading_tab.test.mjs` · `FrontendTemplateCompileTest.mjs` | 51/51 ✅ · 34/34 ✅ · 50/50 ✅ · 15/15 ✅ |
| **03.3** Despacho con temperatura `[-5,0, 4,0] °C`, lote real, caducidad dentro de `max_shelf_life_days` y colisión `QA 3` (`D2`, `QA 2`, `Art. II`) | `ProductLoadingValidator` (cerrojo térmico/lote), `ProductLoadingOrderService::dispatchOrder`, `trg_enforce_loading_order_cold_chain_dispatch`, `CoordinatorProductLoadingTab.js` | `ProductLoadingValidatorTest` · `ProductLoadingOrderServiceTest` · `PostgresProductLoadingSchemaTest` · `ProductLoadingColdPickingApiTest` · `ProductLoadingConstitutionalTest` · `coordinator_product_loading_tab.test.mjs` | 57/57 ✅ · 51/51 ✅ · 39/39 ✅ · 34/34 ✅ · 30/30 ✅ · 50/50 ✅ |
| **03.4** Cancelación justificada desde `PENDING_PICKING` o `DISPATCHED` con `cancel_reason` ≥ 20 caracteres reales, sin tocar stock y bloqueada tras recepción (`Art. V.1`) | `ProductLoadingValidator`, `ProductLoadingOrderService::cancelOrder`, `CoordinatorProductLoadingController`, `CoordinatorProductLoadingTab.js` | `ProductLoadingValidatorTest` · `ProductLoadingOrderServiceTest` · `ProductLoadingColdPickingApiTest` · `ProductLoadingConstitutionalTest` · `coordinator_product_loading_tab.test.mjs` | 57/57 ✅ · 51/51 ✅ · 34/34 ✅ · 30/30 ✅ · 50/50 ✅ |

### RF-04 · Recepción móvil en 1 toque y liquidación de cierre

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **04.1** Confirmación atómica con `FOR UPDATE`, incremento por lote, pertenencia al reponedor, estado `DISPATCHED`, tolerancia a producto desactivado tras el despacho, colisión de caducidad y doble clic concurrente (`D3`, `C3`, `QA 3`, `Art. III.3`) | `ProductLoadingOrderService::confirmOrder`, `PdoRestockerVanStockRepository::upsertLot`, `RestockerRouteController`, `RestockerRouteView.js` | `ProductLoadingOrderServiceTest` · `PdoProductLoadingOrderRepositoryTest` · `ProductLoadingColdPickingApiTest` · `ProductLoadingConstitutionalTest` · `ProductLoadingConcurrencyTest` · `restocker_route_view.test.mjs` | 51/51 ✅ · 30/30 ✅ · 34/34 ✅ · 30/30 ✅ · 12/12 ✅ · 108/108 ✅ |
| **04.2** Liquidación `RECONCILED_AT_CLOSE`, sellado en 0 (`QA 4`), cuadre por lote, nota obligatoria con descuadre, stock al retorno contado, tolerancia a producto desactivado en tránsito, doble clic concurrente y ruta de liquidación de Coordinación (`D3`, `C3`, `Art. V.1`, `Art. III.3`) | `ProductLoadingOrderService::reconcileOrder`, `ProductLoadingValidator`, `PdoProductLoadingOrderRepository`, `RestockerRouteController`, `CoordinatorProductLoadingController`, ambas vistas Vue | `ProductLoadingOrderServiceTest` · `ProductLoadingValidatorTest` · `ProductLoadingColdPickingApiTest` · `ProductLoadingConstitutionalTest` · `ProductLoadingConcurrencyTest` · `coordinator_product_loading_tab.test.mjs` · `restocker_route_view.test.mjs` | 51/51 ✅ · 57/57 ✅ · 34/34 ✅ · 30/30 ✅ · 12/12 ✅ · 50/50 ✅ · 108/108 ✅ |

### RF-05 · Superficies de Coordinación y Reponedor

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **05.1** Consola de Coordinación con KPIs, propuesta, órdenes, despacho, liquidación, cancelación, A4 y stock por lote; integración en dashboard y Service Worker (`D1`–`D4`, `RNF-03`, `Art. V.4`) | `CoordinatorProductLoadingTab.js`, `CoordinatorDashboardView.js`, `api.coordinator`, `public/sw.js` | `coordinator_product_loading_tab.test.mjs` · `FrontendTemplateCompileTest.mjs` · `DesignTokenDebtRatchetTest.mjs` · `OfflinePwaAssetsAndUiGuardTest.mjs` | 50/50 ✅ · 15/15 ✅ · 3/3 ✅ · 45/45 ✅ |
| **05.2** Vista móvil con banner 1-Tap, tarjeta de liquidación, stock FEFO, semáforo y comprobante `C4`; opacidad de `unit_cost_eur` (`D3`, `D4`, `C4`, `Art. V.4`) | `RestockerRouteView.js`, `api.restocker`, `RestockerRouteController`, `ProductLoadingOrderService::getRestockerLoadingContext` | `restocker_route_view.test.mjs` · `ProductLoadingColdPickingApiTest` · `ProductLoadingConstitutionalTest` | 108/108 ✅ · 34/34 ✅ · 30/30 ✅ |

---

## 2. Requisitos No Funcionales

| RNF | Artefacto | Test | Resultado medido |
|---|---|---|---|
| **RNF-01** prohibición de borrado físico en Tablas 44, 45 y 46 (`Art. III.1`) | `prevent_hard_delete()`, `trg_no_delete_restocker_van_product_stocks`, `trg_no_delete_product_loading_orders`, `trg_no_delete_product_loading_order_items`, baja lógica de líneas | `PostgresProductLoadingSchemaTest` · `PostgresConstitutionalTest` · `ProductLoadingConstitutionalTest` · `TestDataCleanerIsolationTest` | ✅ 39/39 · 83/83 · 30/30 · 50/50 — `DELETE` rechazado con `SQLSTATE 45000` y `ART_III_1_VIOLATION`; las 35 raíces transitorias quedan cubiertas |
| **RNF-02** defensa en profundidad del cerrojo de frío y caducidad (`Art. II`) | `ProductLoadingValidator` + `trg_enforce_van_stock_cold_chain_batch` + `trg_enforce_loading_order_cold_chain_dispatch` | `ProductLoadingValidatorTest` · `PostgresProductLoadingSchemaTest` · `ProductLoadingConstitutionalTest` | ✅ 57/57 · 39/39 · 30/30 — dominio y PostgreSQL rechazan la misma combinación |
| **RNF-03** Sistema de Diseño, A4 limpia y Dogma Vanilla (`Art. IV`) | `CoordinatorProductLoadingTab.js`, `RestockerRouteView.js`, `public/sw.js`, tokens `vg-*` | `coordinator_product_loading_tab.test.mjs` · `restocker_route_view.test.mjs` · `FrontendTemplateCompileTest.mjs` · `DesignTokenDebtRatchetTest.mjs` · `OfflinePwaAssetsAndUiGuardTest.mjs` | ✅ 50/50 · 108/108 · 15/15 · 3/3 · 45/45 — plantillas compiladas, bordes 4/8 px y sin `unit_cost_eur` |

---

## 3. Decisiones, casos límite y resoluciones QA

| Elemento | Escenario | Test | Resultado |
|---|---|---|---|
| **D1** Propuesta matutina | Modos `ALERTS_ONLY`/`ALL_GAPS`, colchón, descuento del stock útil no caducado y propuesta individual del ausente con alerta C2 | `ProductLoadingOrderServiceTest` · `PdoProductLoadingOrderRepositoryTest` · `ProductLoadingColdPickingApiTest` · `coordinator_product_loading_tab.test.mjs` | ✅ 51/51 · 30/30 · 34/34 · 50/50 |
| **D2** Trazabilidad de frío | Temperatura de cámara, lote y caducidad en despacho y stock | `ProductLoadingValidatorTest` · `PostgresProductLoadingSchemaTest` · `ProductLoadingConstitutionalTest` | ✅ 57/57 · 39/39 · 30/30 |
| **D3** Ciclo de orden | Orden activa única, 1-Tap y liquidación desde móvil o Coordinación | `ProductLoadingOrderServiceTest` · `ProductLoadingColdPickingApiTest` · `restocker_route_view.test.mjs` · `coordinator_product_loading_tab.test.mjs` | ✅ 51/51 · 34/34 · 108/108 · 50/50 |
| **D4** A4 y FEFO | Dos secciones A4, semáforo de caducidad y orden por vencimiento | `ProductLoadingOrderServiceTest` · `PdoRestockerVanStockRepositoryTest` · `restocker_route_view.test.mjs` | ✅ 51/51 · 28/28 · 108/108 |
| **C1** Baja lógica y topes | Línea retirada conservada como `is_active = FALSE`; cantidades `0..99999` | `PdoProductLoadingOrderRepositoryTest` · `ProductLoadingOrderServiceTest` · `PostgresProductLoadingSchemaTest` | ✅ 30/30 · 51/51 · 39/39 |
| **C2** Destinatario no elegible | Reponedor inactivo/no `RESTOCKER` rechazado; ausente con propuesta y orden individual permitidas, alerta en consola y omisión en lote; causas `ACTIVE_ORDER_EXISTS` y `NO_DEMAND` certificadas | `ProductLoadingOrderServiceTest` · `PdoProductLoadingOrderRepositoryTest` · `ProductLoadingColdPickingApiTest` · `coordinator_product_loading_tab.test.mjs` · `ProductLoadingConstitutionalTest` | ✅ 51/51 · 30/30 · 34/34 · 50/50 · 30/30 |
| **C3** Producto desactivado en tránsito | Alta en preparación bloqueada; recepción 1-Tap y liquidación permitidas tras desactivar el producto en el catálogo | `ProductLoadingOrderServiceTest` · `ProductLoadingValidatorTest` · `ProductLoadingConstitutionalTest` | ✅ 51/51 · 57/57 · 30/30 |
| **C4** Comprobante móvil | Última orden `RECONCILED_AT_CLOSE` visible en solo lectura | `restocker_route_view.test.mjs` | ✅ 108/108 |
| **QA 1** Máquinas no operativas | `SANITARY_QUARANTINE` y `OUT_OF_SERVICE` excluidas con contador | `PdoProductLoadingOrderRepositoryTest` · `ProductLoadingOrderServiceTest` · `ProductLoadingConstitutionalTest` | ✅ 30/30 · 51/51 · 30/30 |
| **QA 2** Vida útil máxima | Fecha superior a `CURRENT_DATE + max_shelf_life_days` rechazada | `ProductLoadingValidatorTest` · `ProductLoadingOrderServiceTest` · `ProductLoadingConstitutionalTest` | ✅ 57/57 · 51/51 · 30/30 |
| **QA 3** Colisión de fecha | Mismo lote con otra fecha rechazado con `409 BATCH_EXPIRATION_MISMATCH` en ajuste, despacho y confirmación de recepción | `RestockerVanStockServiceTest` · `ProductLoadingOrderServiceTest` · `ProductLoadingConstitutionalTest` | ✅ 31/31 · 51/51 · 30/30 |
| **QA 4** Sellado en cero y tipos | Línea con `dispatched_qty = 0` sellada; enums #28–#30 | `PostgresProductLoadingSchemaTest` · `ProductLoadingOrderServiceTest` · `ProductLoadingConstitutionalTest` | ✅ 39/39 · 51/51 · 30/30 |

---

## 4. Definición de Hecho (§8)

| DoD | Evidencia |
|---|---|
| **1.** Tablas 44/45/46, tipos #28–#30, `CHECK`, anti-borrado, cerrojos y entidades auditables | `PostgresProductLoadingSchemaTest` 39/39 · `SchemaConvergenceParityTest` 31/31 · `CloudDeploySchemaParityTest` 55/55 · `PostgresSchemaTest` 144/144 |
| **2.** Propuesta, emisión individual/lote, edición con baja lógica, despacho térmico y cancelación | `ProductLoadingOrderServiceTest` 51/51 · `ProductLoadingValidatorTest` 57/57 · `PdoProductLoadingOrderRepositoryTest` 30/30 · `ProductLoadingDomainModelsTest` 97/97 |
| **3.** Recepción 1-Tap, liquidación al cierre con descuadre, doble clic concurrente y stock al retorno contado | `ProductLoadingOrderServiceTest` 51/51 · `ProductLoadingColdPickingApiTest` 34/34 · `ProductLoadingConcurrencyTest` 12/12 · `ProductLoadingConstitutionalTest` 30/30 · `RestockerVanStockServiceTest` 31/31 |
| **4.** Consola de Coordinación, Hoja A4 y móvil del reponedor sin costes | `coordinator_product_loading_tab.test.mjs` 50/50 · `restocker_route_view.test.mjs` 108/108 · `PdoRestockerVanStockRepositoryTest` 28/28 |
| **5.** Batería global y canal `new_engine` | `php tests/run_all.php` — 427 suites / 16.053 aserciones / 0 fallos, `DocMetricsGuard` coherente · `php tests/run_new_engine.php` — 57 suites / 1.861 aserciones / 57/57 en verde, sin fallos |

---

## 5. Mantenimiento

Esta matriz es un artefacto vivo: cualquier cambio en un `RF`, criterio EARS, `RNF`, decisión `D`, caso límite `C` o resolución `QA` debe actualizar la fila correspondiente y su resultado medido en la misma entrega. La cifra viva de suites y aserciones se declara solo en el README y en `docs/features_pendientes.md`; el resto de documentos la referencia sin duplicarla.
