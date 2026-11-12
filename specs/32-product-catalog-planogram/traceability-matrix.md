# MATRIZ DE TRAZABILIDAD · MÓDULO 32

**Especificación:** [`spec.md`](spec.md) (v2.0) · **Contratos:** [`../technical/product_catalog_planogram_contracts.md`](../technical/product_catalog_planogram_contracts.md) · **Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md)

**Esquema de referencia canónico:** `RF-01` catálogo maestro · `RF-02` matriz de planograma y semáforo · `RF-03` cerrojo sanitario bidireccional · `RF-04` plantillas y clonación · `RF-05` Coordinación y consulta móvil del reponedor.

**Corrida de certificación:** 2026-11-12 (PostgreSQL local bajo TLS, canal `new_engine` para las suites de esquema/API y batería global).

---

## 1. Requisitos Funcionales y criterios EARS

### RF-01 · Catálogo maestro de productos y consumibles (`vending_products`)

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **01.1** Tabla 42, tipos nativos, `CHECK` numéricos, baja lógica y auditoría `VENDING_PRODUCT` (D3, C1, C2, QA 1, Art. III.1) | `database/supabase_migrations/028_product_catalog_and_planogram_slots.sql`, `database/supabase_init.sql`, `VendingProduct`, `ProductCategory`, `ProductUnitType`, `PlanogramCompatibilityValidator` | `PostgresProductPlanogramSchemaTest` · `PdoVendingProductRepositoryTest` · `VendingProductServiceTest` · `ProductCatalogPlanogramApiTest` · `PlanogramConstitutionalTest` | 35/35 ✅ · 30/30 ✅ · 32/32 ✅ · 30/30 ✅ · 31/31 ✅ |
| **01.2** `sku` único normalizado e inmutable en edición (`409 DUPLICATE_PRODUCT_SKU` / `409 SKU_CHANGE_FORBIDDEN`, C1) | `VendingProduct`, `PdoVendingProductRepository::create/update`, `VendingProductService::create/update`, `CoordinatorPlanogramController` | `PdoVendingProductRepositoryTest` · `VendingProductServiceTest` · `ProductCatalogPlanogramApiTest` · `PlanogramConstitutionalTest` | 30/30 ✅ · 32/32 ✅ · 30/30 ✅ · 31/31 ✅ |
| **01.3** Baja/reactivación lógica con `affected_active_slots_count` e insignia de producto inactivo (C1, Art. III.2) | `PdoVendingProductRepository::updateStatus`, `VendingProductService::changeStatus`, `CoordinatorPlanogramTab.js` | `PdoVendingProductRepositoryTest` · `VendingProductServiceTest` · `ProductCatalogPlanogramApiTest` · `coordinator_planogram_tab.test.mjs` | 30/30 ✅ · 32/32 ✅ · 30/30 ✅ · 38/38 ✅ |

### RF-02 · Matriz de planograma por máquina (`machine_planogram_slots`) y semáforo de stock

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **02.1** Tabla 43, unicidad `(machine_id, slot_code)`, `CHECK` numéricos y ordenación física de bandejas (C1, C2, QA 3, Art. III.1) | migración `028`, `MachinePlanogramSlot`, `PdoMachinePlanogramRepository::findSlotsByMachine`, `PlanogramCompatibilityValidator` | `PostgresProductPlanogramSchemaTest` · `PdoMachinePlanogramRepositoryTest` · `PlanogramConstitutionalTest` | 35/35 ✅ · 32/32 ✅ · 31/31 ✅ |
| **02.2** Guardado individual/completo por `UPSERT`, baja lógica sin `DELETE` y rechazo de producto inactivo (`422 PRODUCT_INACTIVE`, C1) | `PdoMachinePlanogramRepository::upsertSlot/replaceMachinePlanogram/deactivateSlot`, `MachinePlanogramService::savePlanogram/saveSlot`, `CoordinatorPlanogramController` | `PdoMachinePlanogramRepositoryTest` · `MachinePlanogramServiceTest` · `ProductCatalogPlanogramApiTest` · `PlanogramConstitutionalTest` · `coordinator_planogram_tab.test.mjs` | 32/32 ✅ · 35/35 ✅ · 30/30 ✅ · 31/31 ✅ · 38/38 ✅ |
| **02.3** Semáforo `STOCKOUT` / `LOW_STOCK` / `OK`, déficit y resumen de ocho indicadores (C2) | `SlotStockStatus`, `MachinePlanogramSlot`, `PdoMachinePlanogramRepository::summarize`, `MachinePlanogramService::getPlanogram` | `PlanogramCompatibilityValidatorTest` · `PdoMachinePlanogramRepositoryTest` · `MachinePlanogramServiceTest` · `ProductCatalogPlanogramApiTest` · `coordinator_planogram_tab.test.mjs` · `restocker_route_view.test.mjs` | 47/47 ✅ · 32/32 ✅ · 35/35 ✅ · 30/30 ✅ · 38/38 ✅ · 82/82 ✅ |

### RF-03 · Cerrojo sanitario bidireccional y matriz `MachineType × ProductCategory`

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **03.1** Bloqueo directo de frío y categoría en dominio y PostgreSQL (`422`, `SQLSTATE 45000`) (D3, Art. II) | `PlanogramCompatibilityValidator`, `trg_enforce_planogram_cold_chain_and_category`, `PdoMachinePlanogramRepository`, `MachinePlanogramService` | `PlanogramCompatibilityValidatorTest` · `PdoMachinePlanogramRepositoryTest` · `PostgresProductPlanogramSchemaTest` · `ProductCatalogPlanogramApiTest` · `PlanogramConstitutionalTest` | 47/47 ✅ · 32/32 ✅ · 35/35 ✅ · 30/30 ✅ · 31/31 ✅ |
| **03.2** Cerrojo inverso al editar producto o `machine_type` (`409`, `QA 4`, Art. II) | `PlanogramCompatibilityValidator::assertProductChange/assertMachineTypeChange`, `VendingProductService`, `AdminMachineService::updateMachine`, `PdoMachineTypePlanogramGuard`, triggers inversos | `PlanogramCompatibilityValidatorTest` · `AdminMachinePlanogramGuardTest` · `VendingProductServiceTest` · `MachinePlanogramServiceTest` · `ProductCatalogPlanogramApiTest` · `PlanogramConstitutionalTest` | 47/47 ✅ · 9/9 ✅ · 32/32 ✅ · 35/35 ✅ · 30/30 ✅ · 31/31 ✅ |

### RF-04 · Plantillas por modelo y clonación en 1 clic

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **04.1** Preset por modelo/tipología, `initial_stock_mode` `ZERO`/`FULL` y auditoría `PLANOGRAM_PRESET_APPLIED` (D1, C3) | `ModelPlanogramPresetCatalog`, `MachinePlanogramService::applyPreset`, `CoordinatorPlanogramController::applyPreset` | `ModelPlanogramPresetCatalogTest` · `MachinePlanogramServiceTest` · `ProductCatalogPlanogramApiTest` · `PlanogramConstitutionalTest` · `coordinator_planogram_tab.test.mjs` | 15/15 ✅ · 35/35 ✅ · 30/30 ✅ · 31/31 ✅ · 38/38 ✅ |
| **04.2** Clonación compatible, omisión de bajas, `422 SOURCE_PLANOGRAM_EMPTY` y preservación de existencias sin copiar las del origen (D1, D3, C3, QA 3) | `MachinePlanogramService::clonePlanogram`, `CoordinatorPlanogramController::clonePlanogram` | `MachinePlanogramServiceTest` · `ProductCatalogPlanogramApiTest` · `PlanogramConstitutionalTest` · `coordinator_planogram_tab.test.mjs` | 35/35 ✅ · 30/30 ✅ · 31/31 ✅ · 38/38 ✅ |

### RF-05 · Consola de Coordinación y consulta móvil del reponedor

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **05.1** Catálogo y planograma en Coordinación con KPIs, filtros, modales, acciones y Sistema de Diseño (D1, D2, RNF-03) | `CoordinatorPlanogramTab.js`, `CoordinatorDashboardView.js`, `api.coordinator`, `public/sw.js` | `coordinator_planogram_tab.test.mjs` · `FrontendTemplateCompileTest.mjs` · `DesignTokenDebtRatchetTest.mjs` | 38/38 ✅ · 15/15 ✅ (78 plantillas) · 3/3 ✅ |
| **05.2** Parque restringido a zonas vivas, planograma solo lectura, `403 MACHINE_OUTSIDE_RESTOCKER_ZONES` y opacidad de `unit_cost_eur` (D2, QA 2, Art. V.4) | `PdoMachinePlanogramRepository::findMachinesForRestocker/findMachineForRestocker`, `MachinePlanogramService::getRestockerPlanogram`, `RestockerRouteController`, `RestockerRouteView.js` | `PdoMachinePlanogramRepositoryTest` · `MachinePlanogramServiceTest` · `ProductCatalogPlanogramApiTest` · `PlanogramConstitutionalTest` · `restocker_route_view.test.mjs` | 32/32 ✅ · 35/35 ✅ · 30/30 ✅ · 31/31 ✅ · 82/82 ✅ |

---

## 2. Requisitos No Funcionales

| RNF | Artefacto | Test | Resultado medido |
|---|---|---|---|
| **RNF-01** prohibición de `DELETE` físico en Tablas 42 y 43 (Art. III.1/III.3) | `prevent_hard_delete()`, `trg_no_delete_vending_products`, `trg_no_delete_machine_planogram_slots`, baja lógica `is_active = FALSE` | `PostgresProductPlanogramSchemaTest` · `PdoVendingProductRepositoryTest` · `PdoMachinePlanogramRepositoryTest` · `PlanogramConstitutionalTest` | ✅ 35/35 · 30/30 · 32/32 · 31/31 — `DELETE` rechazado con `SQLSTATE 45000` y `ART_III_1_VIOLATION`; la fila se conserva |
| **RNF-02** defensa en profundidad del cerrojo sanitario (Art. II) | `PlanogramCompatibilityValidator` + tres disparadores bidireccionales | `PlanogramCompatibilityValidatorTest` · `PostgresProductPlanogramSchemaTest` · `PlanogramConstitutionalTest` | ✅ 47/47 · 35/35 · 31/31 — el dominio devuelve `422`/`409` y PostgreSQL rechaza la misma combinación |
| **RNF-03** Sistema de Diseño y dogma vanilla (Art. IV) | `CoordinatorPlanogramTab.js`, `RestockerRouteView.js`, `public/sw.js` | `coordinator_planogram_tab.test.mjs` · `restocker_route_view.test.mjs` · `FrontendTemplateCompileTest.mjs` · `DesignTokenDebtRatchetTest.mjs` · `MobileShellLayoutGuardTest.mjs` · `OfflinePwaAssetsAndUiGuardTest.mjs` | ✅ 38/38 · 82/82 · 15/15 (78 plantillas) · 3/3 (2.267 literales, techo 2.268) · 7/7 · 45/45 — cero hexadecimales, radios 4/8 px y tokens CSS |

---

## 3. Casos límite, decisiones y resoluciones QA

| Elemento | Escenario | Test | Resultado |
|---|---|---|---|
| **C1** Inmutabilidad del `sku` y ausencia de borrado físico | Edición con otro SKU rechazada; carriles retirados pasan a `is_active = FALSE` | `VendingProductServiceTest` · `PdoVendingProductRepositoryTest` · `PdoMachinePlanogramRepositoryTest` · `PlanogramConstitutionalTest` | ✅ 32/32 · 30/30 · 32/32 · 31/31 |
| **C2** Cotas numéricas y semáforo | Capacidad, umbral, existencias y precios fuera de rango rechazados; `STOCKOUT`/`LOW_STOCK`/`OK` deterministas | `PlanogramCompatibilityValidatorTest` · `MachinePlanogramServiceTest` · `ProductCatalogPlanogramApiTest` | ✅ 47/47 · 35/35 · 30/30 |
| **C3** Clonación y stock inicial | Se preserva la existencia del mismo producto, acotada a la nueva capacidad; nunca se copia la del origen | `MachinePlanogramServiceTest` · `PlanogramConstitutionalTest` | ✅ 35/35 · 31/31 |
| **D1** Presets y clonación | Preset por modelo o tipología y clonación directa en un solo clic | `ModelPlanogramPresetCatalogTest` · `MachinePlanogramServiceTest` · `coordinator_planogram_tab.test.mjs` | ✅ 15/15 · 35/35 · 38/38 |
| **D2** Superficies separadas | Coordinación con costes y márgenes; reponedor sin `unit_cost_eur` ni margen | `coordinator_planogram_tab.test.mjs` · `restocker_route_view.test.mjs` · `ProductCatalogPlanogramApiTest` | ✅ 38/38 · 82/82 · 30/30 |
| **D3** Matriz `MachineType × ProductCategory` | Los 25 pares rechazados o admitidos de forma unívoca en dominio y SQL | `PlanogramCompatibilityValidatorTest` · `PostgresProductPlanogramSchemaTest` | ✅ 47/47 · 35/35 |
| **QA 1** Vida útil y frío | `PERISHABLE_FOOD` exige frío y `1..3650` días; sin frío se admite `NULL` | `PlanogramCompatibilityValidatorTest` · `VendingProductServiceTest` · `ProductCatalogPlanogramApiTest` | ✅ 47/47 · 32/32 · 30/30 |
| **QA 2** Perímetro del reponedor y opacidad | Solo zonas vivas; `403` fuera de ellas; sin coste ni margen en DTO/API | `PdoMachinePlanogramRepositoryTest` · `MachinePlanogramServiceTest` · `PlanogramConstitutionalTest` · `restocker_route_view.test.mjs` | ✅ 32/32 · 35/35 · 31/31 · 82/82 |
| **QA 3** Orden físico y bajas en clonación | Numéricos por entero y luego alfanuméricos; `skipped_inactive_slots` y `422 SOURCE_PLANOGRAM_EMPTY` | `PdoMachinePlanogramRepositoryTest` · `MachinePlanogramServiceTest` · `PlanogramConstitutionalTest` | ✅ 32/32 · 35/35 · 31/31 |
| **QA 4** Cambio de `machine_type` | Rechazo con `409 PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE` y conflictos `slot_code`/`sku` | `AdminMachinePlanogramGuardTest` · `ProductCatalogPlanogramApiTest` · `PlanogramConstitutionalTest` | ✅ 9/9 · 30/30 · 31/31 |

---

## 4. Definición de Hecho (§8)

| DoD | Evidencia |
|---|---|
| **1.** Tablas 42/43, tipos nativos, `CHECK`, anti-borrado, cerrojos y entidades auditables | `PostgresProductPlanogramSchemaTest` 35/35 · `SchemaConvergenceParityTest` 29/29 · `CloudDeploySchemaParityTest` 55/55 · `AuditEntityTypesTest` 41/41 |
| **2.** CRUD con `sku` inmutable, cotas, cerrojo inverso y `affected_active_slots_count` | `VendingProductServiceTest` 32/32 · `PdoVendingProductRepositoryTest` 30/30 · `ProductCatalogPlanogramApiTest` 30/30 · `PlanogramConstitutionalTest` 31/31 |
| **3.** Planograma por `UPSERT`, orden físico, semáforo, presets, clonación y cambio de `machine_type` | `PdoMachinePlanogramRepositoryTest` 32/32 · `MachinePlanogramServiceTest` 35/35 · `PlanogramCompatibilityValidatorTest` 47/47 · `AdminMachinePlanogramGuardTest` 9/9 |
| **4.** Consola de Coordinación y consulta móvil sin costes | `coordinator_planogram_tab.test.mjs` 38/38 · `restocker_route_view.test.mjs` 82/82 |
| **5.** Batería global al 100 % en verde | `php tests/run_all.php` — 420 suites / 15.669 aserciones / 0 fallos, con `DocMetricsGuard` coherente |

---

## 5. Mantenimiento

Esta matriz es un artefacto vivo: cualquier cambio en un `RF`, criterio EARS, `RNF`, decisión `D`, caso límite `C` o resolución `QA` debe actualizar **la fila correspondiente y el resultado medido** en la misma entrega. La cifra viva de suites y aserciones se declara **solo** en el README y en `docs/features_pendientes.md`; el resto de documentos la referencia sin duplicarla.
