# Plan de Tareas de Implementación (`tasks.md`): Órdenes de Carga de Furgoneta de Reposición y Picking con Trazabilidad de Frío (`33-product-loading-cold-picking`)

**Módulo:** `33-product-loading-cold-picking`\
**Versión:** 1.0 (Alineado con `spec.md` v2.0, `product_loading_cold_picking_contracts.md` v1.0 y `plan.md` v1.0)\
**Estado:** Aprobado por el Product Owner el 2026-11-13 · Puerta SDD Fase 2 cerrada · Listo para iniciar en `T-PLD-01`\
**Metodología:** SDD (Specification-Driven Development) · Clean Architecture · TDD / Guardas de Esquema y Diseño\
**Ley Suprema:** [`constitution.md`](../../constitution.md), [`AGENTS.md`](../../AGENTS.md) y [`docs/design.md`](../../docs/design.md)

---

## Resumen de Fases y Grafo de Dependencias

```mermaid
flowchart LR
    P1["Fase 1: Esquema PostgreSQL\n(T-PLD-01 y 02)"] --> P2["Fase 2: Dominio y Validador\n(T-PLD-03 y 04)"]
    P2 --> P3["Fase 3: Repositorios PDO\n(T-PLD-05 y 06)"]
    P3 --> P4["Fase 4: Servicios de Aplicación\n(T-PLD-07 y 08)"]
    P4 --> P5["Fase 5: API REST y Guardas\n(T-PLD-09 y 10)"]
    P5 --> P6["Fase 6: Frontend Vue 3\n(T-PLD-11 y 12)"]
    P6 --> P7["Fase 7: Batería Global y Cierre\n(T-PLD-13)"]
```

| Fase | Rango de Tareas | Descripción | Tiempo Estimado |
| :--- | :--- | :--- | :--- |
| **Fase 1** | `T-PLD-01` .. `T-PLD-02` | Migración `029_product_loading_orders_and_cold_picking.sql`, convergencia en `supabase_init.sql`, semillas del parque, actualización del contrato a 46 tablas / 40 enums y suite de esquema PostgreSQL | ~45 min |
| **Fase 2** | `T-PLD-03` .. `T-PLD-04` | Enums (`ProductLoadingOrderStatus`, `ProductLoadingDemandMode`, `ProductLoadingItemReason`, `BatchExpiryStatus`), entidades (`RestockerVanProductStock`, `ProductLoadingOrder`, `ProductLoadingOrderItem`) y `ProductLoadingValidator` (`Art. II`, `D2`, `D3`, `C1`, `QA 2`, `QA 3`, `Art. V.1`) + tests unitarios | ~45 min |
| **Fase 3** | `T-PLD-05` .. `T-PLD-06` | Repositorios PDO (`PdoRestockerVanStockRepository` y `PdoProductLoadingOrderRepository`) con ordenación FEFO, exclusión de máquinas en cuarentena/fuera de servicio (`QA 1`), baja lógica de líneas (`C1`) y cerrojo `SELECT ... FOR UPDATE` | ~50 min |
| **Fase 4** | `T-PLD-07` .. `T-PLD-08` | Servicios `RestockerVanStockService` (`D4`, `QA 2`, `QA 3`, `Art. V.1`, `Art. V.4`) y `ProductLoadingOrderService` (propuesta `D1`/`QA 1`, lote `C2`, despacho frío `D2`, recepción 1-Tap, liquidación `D3`/`QA 4` y Hoja A4 `D4`) + tests unitarios | ~60 min |
| **Fase 5** | `T-PLD-09` .. `T-PLD-10` | Controlador `CoordinatorProductLoadingController`, extensión de `RestockerRouteController`, rutas + RBAC en `AppRouter` y suites de integración HTTP y blindaje constitucional | ~55 min |
| **Fase 6** | `T-PLD-11` .. `T-PLD-12` | Frontend Vue 3: `CoordinatorProductLoadingTab.js` (con Hoja de Picking A4 en 2 secciones) y extensión de `RestockerRouteView.js` (recepción 1-Tap, liquidación, stock FEFO y última orden `C4` sin `unit_cost_eur`) + tests `.mjs` | ~55 min |
| **Fase 7** | `T-PLD-13` | Ejecución de batería global (`php tests/run_all.php`), sincronización `DocMetricsGuard`, matriz de trazabilidad `traceability-matrix.md` y actualización de `docs/features_pendientes.md` | ~25 min |

---

## Detalle de Tareas

- [x] **T-PLD-01 · Crear migración `029_product_loading_orders_and_cold_picking.sql`, converger `supabase_init.sql` y sembrar lotes y órdenes en `supabase_seeds.sql` y `SeedRunner`**
  - **Requisitos:** `RF-01.1`, `RF-03.1`, `RF-03.3`, `D2`, `D3`, `C1`, `QA 4`, `Art. II`, `Art. III.1`.
  - **Verificación:** `php tests/integration/SchemaConvergenceParityTest.php`

- [x] **T-PLD-02 · Actualizar contratos de esquema (46 tablas, 40 enums), `PostgresFixtureBuilder`, `TestDataCleaner` y crear `PostgresProductLoadingSchemaTest.php`**
  - **Requisitos:** `RF-01.1`, `RF-03.1`, `RNF-01`, `RNF-02`, `QA 4`, `Art. II`, `Art. III.1`.
  - **Verificación:** `php tests/integration/PostgresSchemaTest.php && php tests/integration/PostgresConstitutionalTest.php && php tests/integration/PostgresProductLoadingSchemaTest.php`

- [x] **T-PLD-03 · Crear enums (`ProductLoadingOrderStatus`, `ProductLoadingDemandMode`, `ProductLoadingItemReason`, `BatchExpiryStatus`) y entidades (`RestockerVanProductStock`, `ProductLoadingOrder`, `ProductLoadingOrderItem`)**
  - **Requisitos:** `RF-01.1`, `RF-01.2`, `RF-03.1`, `D2`, `D3`, `D4`, `C1`.
  - **Verificación:** `php tests/unit/ProductLoadingDomainModelsTest.php`

- [x] **T-PLD-04 · Crear `ProductLoadingValidator` y `ProductLoadingValidationException` (cerrojo cámara $\le 4,0\,^\circ\text{C}$, `max_shelf_life_days` `QA 2`, lote `QA 3`, cuadre de liquidación y justificación $\ge 20$ chars)**
  - **Requisitos:** `RF-01.3`, `RF-03.3`, `RF-03.4`, `RF-04.2`, `D2`, `D3`, `C1`, `QA 2`, `QA 3`, `QA 4`, `Art. II`, `Art. V.1`.
  - **Verificación:** `php tests/unit/ProductLoadingValidatorTest.php`

- [x] **T-PLD-05 · Implementar `PdoRestockerVanStockRepository` (`UPSERT` por lote, ordenación FEFO, stock útil no caducado y filtros de Coordinación)**
  - **Requisitos:** `RF-01.1`–`RF-01.3`, `D1`, `D2`, `D4`, `QA 3`, `Art. III.1`, `Art. V.4`.
  - **Verificación:** `php tests/integration/PdoRestockerVanStockRepositoryTest.php`

- [x] **T-PLD-06 · Implementar `PdoProductLoadingOrderRepository` (órdenes, líneas con baja lógica `C1`, demanda de carriles excluyendo máquinas en cuarentena/fuera de servicio `QA 1` y `SELECT ... FOR UPDATE`)**
  - **Requisitos:** `RF-02.1`–`RF-02.3`, `RF-03.1`, `RF-04.1`, `RF-04.2`, `D1`–`D4`, `C1`, `QA 1`, `Art. III.1`.
  - **Verificación:** `php tests/integration/PdoProductLoadingOrderRepositoryTest.php`

- [ ] **T-PLD-07 · Implementar `RestockerVanStockService` (consulta segregada sin `unit_cost_eur` para reponedor y ajuste manual auditado con `QA 2`, `QA 3` y motivo $\ge 20$ chars)**
  - **Requisitos:** `RF-01.2`, `RF-01.3`, `RF-05.2`, `D4`, `QA 2`, `QA 3`, `Art. III.3`, `Art. V.1`, `Art. V.4`.
  - **Verificación:** `php tests/unit/RestockerVanStockServiceTest.php`

- [ ] **T-PLD-08 · Implementar `ProductLoadingOrderService` (propuesta `D1`/`QA 1`, emisión individual y lote `C2`, edición `C1`, despacho frío `D2`/`QA 2`/`QA 3`, recepción 1-Tap, liquidación `D3`/`QA 4`, cancelación y Hoja A4 `D4`)**
  - **Requisitos:** `RF-02` a `RF-04`, `D1`–`D4`, `C1`–`C4`, `QA 1`–`QA 4`, `Art. II`, `Art. III.3`, `Art. V.1`, `Art. V.4`.
  - **Verificación:** `php tests/unit/ProductLoadingOrderServiceTest.php`

- [ ] **T-PLD-09 · Crear `CoordinatorProductLoadingController`, extender `RestockerRouteController` y registrar rutas + guardas RBAC en `AppRouter`**
  - **Requisitos:** `RF-01` a `RF-05`, `Art. V.4`.
  - **Verificación:** `php tests/integration/ProductLoadingColdPickingApiTest.php`

- [ ] **T-PLD-10 · Certificar suites de integración HTTP y blindaje constitucional (`ProductLoadingColdPickingApiTest.php` y `ProductLoadingConstitutionalTest.php`)**
  - **Requisitos:** `RF-01` a `RF-05`, `D1`–`D4`, `C1`–`C4`, `QA 1`–`QA 4`, `Art. II`, `Art. III.1`, `Art. V.1`, `Art. V.4`.
  - **Verificación:** `php tests/integration/ProductLoadingColdPickingApiTest.php && php tests/integration/ProductLoadingConstitutionalTest.php`

- [ ] **T-PLD-11 · Construir `CoordinatorProductLoadingTab.js` (con Hoja de Picking A4 en 2 secciones y gestión de lotes) e integrarlo en `CoordinatorDashboardView.js` y `public/sw.js`**
  - **Requisitos:** `RF-03.2`, `RF-05.1`, `RNF-03`, `D1`–`D4`, `QA 1`, `Art. V.4`.
  - **Verificación:** `node tests/frontend/coordinator_product_loading_tab.test.mjs`

- [ ] **T-PLD-12 · Extender `RestockerRouteView.js` con banner de recepción 1-Tap, modal de liquidación al cierre, stock FEFO con semáforo y comprobante de última orden liquidada (`C4`) sin `unit_cost_eur`**
  - **Requisitos:** `RF-04.1`, `RF-04.2`, `RF-05.2`, `RNF-03`, `D3`, `D4`, `C4`, `Art. V.4`.
  - **Verificación:** `node tests/frontend/restocker_route_view.test.mjs`

- [ ] **T-PLD-13 · Ejecutar batería global (`php tests/run_all.php`), sincronizar `DocMetricsGuard`, generar `traceability-matrix.md` y actualizar `docs/features_pendientes.md`**
  - **Requisitos:** `DoD 1`–`DoD 5`.
  - **Verificación:** `php tests/run_all.php`
