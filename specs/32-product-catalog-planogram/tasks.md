# Plan de Tareas de Implementación (`tasks.md`): Catálogo Maestro de Productos, Consumibles y Planograma por Máquina (`32-product-catalog-planogram`)

**Módulo:** `32-product-catalog-planogram`\
**Versión:** 1.0 (Alineado con `spec.md` v2.0, `product_catalog_planogram_contracts.md` v1.0 y `plan.md` v1.0)\
**Estado:** Aprobado por el Product Owner el 2026-11-12 · Puerta SDD cerrada · Listo para Ejecución de las Tareas `T-PLN-01` a `T-PLN-14` (Fase 3: tests y código)\
**Metodología:** SDD (Specification-Driven Development) · Clean Architecture · TDD / Guardas de Esquema y Diseño\
**Ley Suprema:** [`constitution.md`](../../constitution.md), [`AGENTS.md`](../../AGENTS.md) y [`docs/design.md`](../../docs/design.md)

---

## Resumen de Fases y Grafo de Dependencias

```mermaid
flowchart LR
    P1["Fase 1: Esquema PostgreSQL\n(T-PLN-01 y 02)"] --> P2["Fase 2: Dominio y Presets\n(T-PLN-03 y 04)"]
    P2 --> P3["Fase 3: Repositorios PDO\n(T-PLN-05 y 06)"]
    P3 --> P4["Fase 4: Servicios de Aplicación\n(T-PLN-07 a 09)"]
    P4 --> P5["Fase 5: API REST y Guardas\n(T-PLN-10 y 11)"]
    P5 --> P6["Fase 6: Frontend Vue 3\n(T-PLN-12 y 13)"]
    P6 --> P7["Fase 7: Batería Global y Cierre\n(T-PLN-14)"]
```

| Fase | Rango de Tareas | Descripción | Tiempo Estimado |
| :--- | :--- | :--- | :--- |
| **Fase 1** | `T-PLN-01` .. `T-PLN-02` | Migración `028_product_catalog_and_planogram_slots.sql`, convergencia en `supabase_init.sql`, semillas del parque, actualización del contrato a 43 tablas / 27 enums y suite de esquema PostgreSQL | ~45 min |
| **Fase 2** | `T-PLN-03` .. `T-PLN-04` | Enums y entidades (`VendingProduct`, `MachinePlanogramSlot`), `PlanogramCompatibilityValidator` (`Art. II`, `D3`, `C2`, `QA 1`), `ModelPlanogramPresetCatalog` (`D1`) y tests unitarios | ~45 min |
| **Fase 3** | `T-PLN-05` .. `T-PLN-06` | Repositorios PDO (`PdoVendingProductRepository` y `PdoMachinePlanogramRepository`) con `UPSERT` sin `DELETE` (`C1`), ordenación física de bandejas (`QA 3`) y filtrado territorial por zonas del reponedor (`QA 2`) | ~45 min |
| **Fase 4** | `T-PLN-07` .. `T-PLN-09` | Servicios `VendingProductService` (`C1`–`C3`, `D3`), `MachinePlanogramService` (`D1`–`D3`, `C1`–`C3`, `QA 2`, `QA 3`, `Art. V.4`) e integración en `AdminMachineService::updateMachine` (`QA 4`) + tests unitarios | ~60 min |
| **Fase 5** | `T-PLN-10` .. `T-PLN-11` | Controladores REST (`CoordinatorPlanogramController`, extensión de `RestockerRouteController`), rutas y RBAC en `public/index.php`, y suites de integración HTTP y blindaje constitucional | ~55 min |
| **Fase 6** | `T-PLN-12` .. `T-PLN-13` | Frontend Vue 3: `CoordinatorPlanogramTab.js` en Coordinación y consulta de máquinas/planogramas en `RestockerRouteView.js` sin `unit_cost_eur` + tests `.mjs` | ~55 min |
| **Fase 7** | `T-PLN-14` | Ejecución de batería global (`php tests/run_all.php`), sincronización `DocMetricsGuard`, matriz de trazabilidad `traceability-matrix.md` y actualización de `docs/features_pendientes.md` | ~25 min |

---

## Detalle de Tareas

- [x] **T-PLN-01 · Crear migración `028_product_catalog_and_planogram_slots.sql`, converger `supabase_init.sql` y sembrar catálogo y planogramas en `supabase_seeds.sql` y `SeedRunner`**
  - **Requisitos:** `RF-01.1`, `RF-02.1`, `RF-03.1`, `RF-03.2`, `D1`, `D3`, `C1`–`C3`, `QA 1`, `Art. II`, `Art. III.1`.
  - **Verificación:** `php tests/integration/SchemaConvergenceParityTest.php`

- [x] **T-PLN-02 · Actualizar contratos de esquema (43 tablas, 27 enums), `TestDataCleaner` y crear `PostgresProductPlanogramSchemaTest.php`**
  - **Requisitos:** `RF-01.1`, `RF-02.1`, `RF-03.1`, `RF-03.2`, `RNF-01`, `RNF-02`, `QA 1`, `Art. II`, `Art. III.1`.
  - **Verificación:** `php tests/integration/PostgresSchemaTest.php && php tests/integration/PostgresConstitutionalTest.php && php tests/integration/PostgresProductPlanogramSchemaTest.php`

- [x] **T-PLN-03 · Crear enums (`ProductCategory`, `ProductUnitType`, `SlotStockStatus`), entidades (`VendingProduct`, `MachinePlanogramSlot`) y `PlanogramCompatibilityValidator`**
  - **Requisitos:** `RF-01.1`, `RF-02.1`, `RF-02.3`, `RF-03.1`, `D3`, `C2`, `QA 1`, `Art. II`.
  - **Verificación:** `php tests/unit/PlanogramCompatibilityValidatorTest.php`

- [ ] **T-PLN-04 · Crear `ModelPlanogramPresetCatalog` con plantillas de dominio por modelo y tipología (`D1`, `RF-04.1`)**
  - **Requisitos:** `RF-04.1`, `D1`, `D3`.
  - **Verificación:** `php tests/unit/ModelPlanogramPresetCatalogTest.php`

- [ ] **T-PLN-05 · Implementar `PdoVendingProductRepository` (`vending_products`, conteo de carriles activos y consulta de máquinas vinculadas)**
  - **Requisitos:** `RF-01.1`–`RF-01.3`, `RF-03.2`, `C1`, `D3`.
  - **Verificación:** `php tests/integration/PdoVendingProductRepositoryTest.php`

- [ ] **T-PLN-06 · Implementar `PdoMachinePlanogramRepository` (`UPSERT` sin `DELETE`, ordenación física `QA 3`, resúmenes y filtrado por zonas de reponedor `QA 2`)**
  - **Requisitos:** `RF-02.1`–`RF-02.3`, `RF-05.2`, `C1`, `C2`, `QA 2`, `QA 3`, `Art. III.1`.
  - **Verificación:** `php tests/integration/PdoMachinePlanogramRepositoryTest.php`

- [ ] **T-PLN-07 · Implementar `VendingProductService` (CRUD catálogo, `sku` inmutable `C1`, bloqueo inverso `D3` y auditoría `VENDING_PRODUCT`)**
  - **Requisitos:** `RF-01.1`–`RF-01.3`, `RF-03.2`, `C1`–`C3`, `D3`, `QA 1`, `Art. II`, `Art. III.3`.
  - **Verificación:** `php tests/unit/VendingProductServiceTest.php`

- [ ] **T-PLN-08 · Implementar `MachinePlanogramService` (guardado `UPSERT`, presets `D1`, clonación `C3`/`QA 3` y lectura segregada de reponedor `D2`/`QA 2`/`Art. V.4`)**
  - **Requisitos:** `RF-02.2`, `RF-02.3`, `RF-03.1`, `RF-04.1`, `RF-04.2`, `RF-05.2`, `D1`–`D3`, `C1`–`C3`, `QA 2`, `QA 3`, `Art. II`, `Art. V.4`.
  - **Verificación:** `php tests/unit/MachinePlanogramServiceTest.php`

- [ ] **T-PLN-09 · Integrar validación de carriles activos en `AdminMachineService::updateMachine` al cambiar `machine_type` (`QA 4`, `RF-03.2`)**
  - **Requisitos:** `RF-03.2`, `D3`, `QA 4`, `Art. II`.
  - **Verificación:** `php tests/unit/AdminMachinePlanogramGuardTest.php`

- [ ] **T-PLN-10 · Crear `CoordinatorPlanogramController`, extender `RestockerRouteController` y registrar rutas + guardas RBAC en `public/index.php`**
  - **Requisitos:** `RF-01` a `RF-05`, `D2`, `QA 2`, `Art. V.4`.
  - **Verificación:** `php tests/integration/ProductCatalogPlanogramApiTest.php`

- [ ] **T-PLN-11 · Certificar suites de integración HTTP y blindaje constitucional (`ProductCatalogPlanogramApiTest.php` y `PlanogramConstitutionalTest.php`)**
  - **Requisitos:** `RF-01` a `RF-05`, `D1`–`D3`, `C1`–`C3`, `QA 1`–`QA 4`, `Art. II`, `Art. III.1`, `Art. V.4`.
  - **Verificación:** `php tests/integration/ProductCatalogPlanogramApiTest.php && php tests/integration/PlanogramConstitutionalTest.php`

- [ ] **T-PLN-12 · Construir `CoordinatorPlanogramTab.js` e integrarlo en `CoordinatorDashboard.js` (`RF-05.1`, `D1`, `D2`)**
  - **Requisitos:** `RF-05.1`, `RNF-03`, `D1`, `D2`, `C1`, `C2`, `C3`.
  - **Verificación:** `node tests/frontend/coordinator_planogram_tab.test.mjs`

- [ ] **T-PLN-13 · Extender `RestockerRouteView.js` con listado de máquinas de zona y modal de planograma en solo lectura sin `unit_cost_eur` (`RF-05.2`, `D2`, `QA 2`, `Art. V.4`)**
  - **Requisitos:** `RF-05.2`, `RNF-03`, `D2`, `QA 2`, `Art. V.4`.
  - **Verificación:** `node tests/frontend/restocker_route_view.test.mjs`

- [ ] **T-PLN-14 · Ejecutar batería global (`php tests/run_all.php`), sincronizar `DocMetricsGuard`, generar `traceability-matrix.md` y actualizar `docs/features_pendientes.md`**
  - **Requisitos:** `DoD 1`–`DoD 5`.
  - **Verificación:** `php tests/run_all.php`
