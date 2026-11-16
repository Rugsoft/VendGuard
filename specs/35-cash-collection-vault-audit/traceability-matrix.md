# MATRIZ DE TRAZABILIDAD · MÓDULO 35

**Especificación:** [`spec.md`](spec.md) (v2.0) · **Contratos:** [`../technical/cash_collection_vault_audit_contracts.md`](../technical/cash_collection_vault_audit_contracts.md) · **Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md)

**Esquema de referencia canónico:** `RF-01` Tabla 49 `machine_cash_collections`, tipo #42 y precinto único · `RF-02` captura en ruta y Arqueo Ciego · `RF-03` entrega en conserjería y transición atómica de reintegros · `RF-04` recepción en cámara y arqueo terminal · `RF-05` superficies de Reponedor y Coordinación.

**Corrida de certificación:** 2026-11-16 (PostgreSQL 16+ local, batería global y canal `new_engine`).

**Actualización T-CCV-12:** 2026-11-16 · cierre de la certificación global. Se corrigieron las deudas reales descubiertas por la batería: el catálogo del visor de auditoría no cubría la entidad `MACHINE_CASH_COLLECTION` ni la acción `OFFLINE_CASH_COLLECTION_CONFLICT_DUMP`; cuatro suites del Módulo 35 no publicaban el formato canónico de aserciones; la vista móvil conservaba geometría en línea en cuatro botones; el plan de purga seguía declarando 37 tablas transitorias cuando la Tabla 49 eleva el total a 38; y la semilla de recaudaciones podía abortar la siembra completa si una máquina tenía una avería térmica viva. La semilla ahora omite el corte demo bloqueado sin vulnerar el Art. II y lo recupera cuando la máquina vuelve a estar disponible.

**Estado:** ✅ Módulo 35 cerrado · 12/12 tareas (`T-CCV-01` a `T-CCV-12`) · batería global en verde y `DocMetricsGuard` coherente.

**FIX-35-02 (2026-11-16):** `RNF-03` queda certificado en navegador real a `360 × 800` por `CashCollectionLayoutBrowserTest` (32/32): historial, modal rápido, bloque de visita, recibo de conserjería y sub-vista/detalle de Coordinación sin desbordamiento horizontal del documento; la tabla ancha de Coordinación conserva su scroll interno.

**FIX-35-03 (2026-11-16):** `RNF-02.3` queda enlazado a `RestockerCashCollectionViewDTO`, la clase real que construye la respuesta móvil con lista blanca de 18 campos; `CashCollectionDomainModelsTest` 74/74 verifica la ausencia de las cinco claves privadas y el recibo conserva sus nueve campos.

**FIX-35-04 (2026-11-16):** `QA 1` queda certificado también para `vault_reconciled_by_name_snapshot` en `CashCollectionVaultAuditConstitutionalTest` (`5.2`–`5.4`, 25/25): tras la baja lógica de máquina, reponedor y revisor —y el renombrado posterior del revisor en `users`—, tanto la fila de la Tabla 49 como `GET /api/coordinator/cash-collections/{id}` conservan los cuatro snapshots congelados.

**FIX-35-05 (2026-11-16):** `RF-04.1` queda certificado también en `audit_log` por `CashCollectionVaultAuditConstitutionalTest` (`6.1` y `6.3`, 25/25): `CASH_COLLECTION_DELIVERED_TO_VAULT` queda enlazado a `entity_type = 'MACHINE_CASH_COLLECTION'` y al `entity_id` de la bolsa con el actor y el rol `COORDINATOR`.

---

## 1. Requisitos Funcionales y criterios EARS

### RF-01 · Tabla histórica inmutable y unicidad de precinto

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **01.1** Tabla 49 `machine_cash_collections`, tipo nativo #42 `cash_collection_status`, `MACHINE_CASH_COLLECTION` en `audit_entity_type`, disparadores de inmutabilidad y guarda sanitaria (`D1`–`D4`, `C1`, `QA 1`, `Art. II.2`, `Art. III.1`, `Art. III.3`) | `031_cash_collection_vault_audit.sql`, `database/supabase_init.sql`, `SeedRunner::seedMachineCashCollections()` | `PostgresCashCollectionVaultAuditSchemaTest` · `PostgresSchemaTest` · `PostgresConstitutionalTest` · `SchemaConvergenceParityTest` | 46/46 ✅ · 153/153 ✅ · 86/86 ✅ · 39/39 ✅ |
| **01.2** Precinto normalizado y único global, idempotencia `(restocker_id, client_mutation_id)` y cotas de importes (`C1`, `D4`) | `MachineCashCollection`, `MachineCashCollectionValidator`, `PdoMachineCashCollectionRepository`, `MachineCashCollectionService` | `CashCollectionDomainModelsTest` · `MachineCashCollectionValidatorTest` · `PdoMachineCashCollectionRepositoryTest` · `CashCollectionVaultAuditApiTest` | 74/74 ✅ · 114/114 ✅ · 45/45 ✅ · 30/30 ✅ |
| **01.3** Prohibición de `DELETE`, congelación de la captura en ruta y bloqueo terminal tras el arqueo (`Art. III.1`, `Art. III.3`) | `prevent_hard_delete()`, `enforce_machine_cash_collections_immutability()`, `trg_no_delete_machine_cash_collections`, `trg_guard_machine_cash_collections` | `PostgresCashCollectionVaultAuditSchemaTest` · `PdoMachineCashCollectionRepositoryTest` · `CashCollectionVaultAuditConstitutionalTest` · `TestDataCleanerIsolationTest` | 46/46 ✅ · 45/45 ✅ · 25/25 ✅ · 50/50 ✅ |

### RF-02 · Captura en ruta, monotonía del contador y cálculo privado del Arqueo Ciego

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **02.1** Rol `RESTOCKER`, perímetro de zonas, bloqueo `403 MACHINE_OUTSIDE_RESTOCKER_ZONES` y primacía sanitaria en aplicación y motor (`C2`, `C4`, `Art. II.2`) | `MachineCashCollectionValidator::assertMachineAvailable`, `MachineCashCollectionService`, `PdoMachineCashCollectionGateway`, `RestockerRouteController`, `enforce_cash_collection_sanitary_guard()` | `MachineCashCollectionValidatorTest` · `MachineCashCollectionServiceTest` · `CashCollectionVaultAuditApiTest` · `CashCollectionVaultAuditConstitutionalTest` · `PostgresCashCollectionVaultAuditSchemaTest` · `RestockerRbacConstitutionalTest` | 114/114 ✅ · 45/45 ✅ · 30/30 ✅ · 25/25 ✅ · 46/46 ✅ · 27/27 ✅ |
| **02.2** Monotonía de `sales_counter_reading`, reseteo con nota real, base inicial y `expected_cashbox_eur` privado (`D2`, `C3`, `Art. V.1`, `Art. V.4`) | `MachineCashCollectionValidator::calculateExpectedCashbox`, `MachineCashCollectionService`, `PdoMachineCashCollectionRepository::findPreviousReading` | `MachineCashCollectionValidatorTest` · `MachineCashCollectionServiceTest` · `PdoMachineCashCollectionRepositoryTest` · `CashCollectionVaultAuditApiTest` · `CashCollectionVaultAuditConstitutionalTest` | 114/114 ✅ · 45/45 ✅ · 45/45 ✅ · 30/30 ✅ · 25/25 ✅ |

### RF-03 · Entrega en conserjería y justificante digital opaco

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **03.1** Entrega `≤ 10,00 €`, `has_physical_reception`, conserje obligatorio y transición atómica de `refund_requests` a `DEPOSITED_AT_RECEPTION` (`D3`) | `MachineCashCollectionValidator::validateReception`, `MachineCashCollectionService::registerCollectionInTransaction`, `PdoMachineCashCollectionGateway` | `MachineCashCollectionValidatorTest` · `MachineCashCollectionServiceTest` · `CashCollectionVaultAuditApiTest` · `CashCollectionVaultAuditConstitutionalTest` | 114/114 ✅ · 45/45 ✅ · 30/30 ✅ · 25/25 ✅ |
| **03.2** Recibo de conserjería de nueve campos sin bolsa, importes privados, PII ni `pickup_pin` (`QA 2`, `Art. V.4`) | `MachineCashCollection::toReceptionReceiptArray`, `MachineCashCollectionService::receptionReceipt`, `RestockerRouteController`, `RestockerRouteView.js` | `CashCollectionDomainModelsTest` · `MachineCashCollectionServiceTest` · `CashCollectionVaultAuditApiTest` · `CashCollectionVaultAuditConstitutionalTest` · `restocker_route_view.test.mjs` | 74/74 ✅ · 45/45 ✅ · 30/30 ✅ · 25/25 ✅ · 209/209 ✅ |

### RF-04 · Recepción en cámara y arqueo terminal

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **04.1** Transición `SEALED_IN_ROUTE → DELIVERED_TO_VAULT` con sellos de recepción y auditoría `CASH_COLLECTION_DELIVERED_TO_VAULT` (`D4`) | `PdoMachineCashCollectionRepository::deliverToVault`, `MachineCashCollectionService::deliverToVault`, `CoordinatorProductLoadingController` | `PdoMachineCashCollectionRepositoryTest` · `MachineCashCollectionServiceTest` · `CashCollectionVaultAuditApiTest` · `CashCollectionVaultAuditConstitutionalTest` (`6.1`, `6.3`) | 45/45 ✅ · 45/45 ✅ · 30/30 ✅ · 25/25 ✅ |
| **04.2** Arqueo ciego, tolerancia `±1,00 €`, `DISCREPANCY_FLAGGED` con justificación real y bloqueo de re-arqueo (`D2`, `D4`, `Art. V.1`) | `MachineCashCollectionValidator::resolveReconciliation`, `MachineCashCollectionService::reconcile`, `PdoMachineCashCollectionRepository::reconcile`, `CoordinatorProductLoadingTab.js` | `MachineCashCollectionValidatorTest` · `MachineCashCollectionServiceTest` · `PdoMachineCashCollectionRepositoryTest` · `CashCollectionVaultAuditApiTest` · `CashCollectionVaultAuditConstitutionalTest` · `coordinator_product_loading_tab.test.mjs` | 114/114 ✅ · 45/45 ✅ · 45/45 ✅ · 30/30 ✅ · 25/25 ✅ · 80/80 ✅ |

### RF-05 · Superficies de Reponedor y Coordinación

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **05.1** Captura integrada e independiente, conserjería, recibo, historial opaco y cola Offline `IndexedDB` (`D1`, `D4`, `C2`, `C4`, `Art. V.4`) | `RestockerRouteView.js`, `CashCollectionOfflineQueue.js`, `api.js`, `public/sw.js` | `restocker_route_view.test.mjs` · `OfflinePwaAssetsAndUiGuardTest.mjs` · `FrontendTemplateCompileTest.mjs` · `CoordinatorButtonVariantsGuardTest.mjs` · `DesignTokenDebtRatchetTest.mjs` | 209/209 ✅ · 45/45 ✅ · 15/15 ✅ · 25/25 ✅ · 3/3 ✅ |
| **05.2** Historial del reponedor sin las cinco claves privadas del arqueo y recibo imprimible (`C2`, `QA 2`) | `RestockerCashCollectionViewDTO`, `MachineCashCollection::toRestockerArray` (proyección interna), `RestockerRouteView.js`, `RestockerRouteController` | `CashCollectionDomainModelsTest` · `restocker_route_view.test.mjs` · `CashCollectionVaultAuditApiTest` · `CashCollectionVaultAuditConstitutionalTest` | 74/74 ✅ · 209/209 ✅ · 30/30 ✅ · 25/25 ✅ |
| **05.3** Sub-vista de Coordinación con 6 KPIs reactivos, filtros, recepción, arqueo y CSV UTF-8 BOM neutralizado (`QA 3`, `QA 4`) | `CoordinatorProductLoadingTab.js`, `api.js`, `CoordinatorProductLoadingController` | `coordinator_product_loading_tab.test.mjs` · `CashCollectionVaultAuditApiTest` · `FrontendTemplateCompileTest.mjs` · `CoordinatorButtonVariantsGuardTest.mjs` | 80/80 ✅ · 30/30 ✅ · 15/15 ✅ · 25/25 ✅ |

---

## 2. Requisitos No Funcionales

| RNF | Artefacto | Test | Resultado medido |
|---|---|---|---|
| **RNF-01** prohibición de borrado físico e inmutabilidad por etapas de la Tabla 49 (`Art. III.1`, `Art. III.3`) | `prevent_hard_delete()`, `enforce_machine_cash_collections_immutability()`, `TestDataCleaner` | `PostgresCashCollectionVaultAuditSchemaTest` · `CashCollectionVaultAuditConstitutionalTest` · `PdoMachineCashCollectionRepositoryTest` · `TestDataCleanerIsolationTest` | ✅ 46/46 · 25/25 · 45/45 · 50/50 — `DELETE` y `UPDATE` rechazados con `SQLSTATE 45000`; el plan de purga cubre las 38 tablas transitorias |
| **RNF-02** defensa en profundidad sanitaria, justificación real y opacidad financiera (`Art. II.2`, `Art. V.1`, `Art. V.4`) | `MachineCashCollectionValidator`, `enforce_cash_collection_sanitary_guard()`, `RestockerCashCollectionViewDTO`, `MachineCashCollection::toRestockerArray` | `CashCollectionDomainModelsTest` · `MachineCashCollectionValidatorTest` · `MachineCashCollectionServiceTest` · `PostgresCashCollectionVaultAuditSchemaTest` · `CashCollectionVaultAuditConstitutionalTest` · `CashCollectionVaultAuditApiTest` | ✅ 74/74 · 114/114 · 45/45 · 46/46 · 25/25 · 30/30 — el DTO publica solo su lista blanca; dominio y PostgreSQL rechazan la misma combinación sanitaria y el móvil nunca recibe las cinco claves privadas |
| **RNF-03** Sistema de Diseño, Dogma Vanilla y PWA (`Art. IV`) | `RestockerRouteView.js`, `CoordinatorProductLoadingTab.js`, `public/sw.js`, tokens `vg-*` | `restocker_route_view.test.mjs` · `coordinator_product_loading_tab.test.mjs` · `CashCollectionLayoutBrowserTest` · `CoordinatorButtonVariantsGuardTest.mjs` · `FrontendTemplateCompileTest.mjs` · `DesignTokenDebtRatchetTest.mjs` · `OfflinePwaAssetsAndUiGuardTest.mjs` | ✅ 209/209 · 80/80 · **32/32 a `360 × 800` en Chromium real** · 25/25 · 15/15 · 3/3 · 45/45 — sin geometría inline, documento sin scroll horizontal y tabla de Coordinación con scroll interno; Service Worker v22 con la cola de recaudación |

---

## 3. Decisiones, casos límite y resoluciones QA

| Elemento | Escenario | Test | Resultado |
|---|---|---|---|
| **D1** Doble vía de captura | Visita `COMPLETED` con bloque de recaudación y operación independiente con `restock_visit_id = NULL`; una sola recaudación por visita | `MachineCashCollectionServiceTest` · `MachineRestockVisitServiceTest` · `CashCollectionVaultAuditApiTest` · `restocker_route_view.test.mjs` | ✅ 45/45 · 50/50 · 30/30 · 209/209 |
| **D2** Arqueo Ciego y tolerancia | Cálculo privado del esperado, dictamen `RECONCILED_OK` dentro de `±1,00 €` y `DISCREPANCY_FLAGGED` fuera con justificación | `MachineCashCollectionValidatorTest` · `MachineCashCollectionServiceTest` · `PdoMachineCashCollectionRepositoryTest` · `CashCollectionVaultAuditApiTest` · `coordinator_product_loading_tab.test.mjs` | ✅ 114/114 · 45/45 · 45/45 · 30/30 · 80/80 |
| **D3** Conserjería y reintegros | Entrega de `2,50 €` con conserje y transición atómica del expediente; sede sin recepción física rechazada | `MachineCashCollectionValidatorTest` · `MachineCashCollectionServiceTest` · `CashCollectionVaultAuditApiTest` · `CashCollectionVaultAuditConstitutionalTest` | ✅ 114/114 · 45/45 · 30/30 · 25/25 |
| **D4** Ciclo de custodia y Offline | `SEALED_IN_ROUTE → DELIVERED_TO_VAULT → RECONCILED_OK/DISCREPANCY_FLAGGED`, idempotencia y conflicto offline archivable | `PdoMachineCashCollectionRepositoryTest` · `MachineCashCollectionServiceTest` · `PostgresCashCollectionVaultAuditSchemaTest` · `restocker_route_view.test.mjs` | ✅ 45/45 · 45/45 · 46/46 · 209/209 |
| **C1** Precinto y cotas | Normalización, bloque numérico obligatorio, unicidad global y límites económicos | `MachineCashCollectionValidatorTest` · `PostgresCashCollectionVaultAuditSchemaTest` · `PdoMachineCashCollectionRepositoryTest` · `CashCollectionVaultAuditApiTest` | ✅ 114/114 · 46/46 · 45/45 · 30/30 |
| **C2** Perímetro y opacidad | `403 MACHINE_OUTSIDE_RESTOCKER_ZONES` y ausencia de `previous_sales_counter_reading`, `expected_cashbox_eur`, `vault_counted_eur`, `discrepancy_eur` y `discrepancy_justification` en el móvil | `MachineCashCollectionServiceTest` · `CashCollectionVaultAuditApiTest` · `CashCollectionVaultAuditConstitutionalTest` · `restocker_route_view.test.mjs` | ✅ 45/45 · 30/30 · 25/25 · 209/209 |
| **C3** Contador y reseteo | Regresión `422 SALES_COUNTER_REGRESSION`, reseteo con nota real y base inicial de la primera recaudación | `MachineCashCollectionValidatorTest` · `MachineCashCollectionServiceTest` · `CashCollectionVaultAuditApiTest` · `PostgresCashCollectionVaultAuditSchemaTest` | ✅ 114/114 · 45/45 · 30/30 · 46/46 |
| **C4** Primacía sanitaria y conflicto Offline | Cuarentena, fuera de servicio, ticket `TEMPERATURE_COLD`, visita abortada y volcado del `409` en auditoría | `MachineCashCollectionValidatorTest` · `MachineCashCollectionServiceTest` · `CashCollectionVaultAuditConstitutionalTest` · `restocker_route_view.test.mjs` | ✅ 114/114 · 45/45 · 25/25 · 209/209 |
| **QA 1** Snapshots históricos | Arqueo de bolsa en tránsito tras la baja lógica de máquina o reponedor y conservación de `machine_code_snapshot`, `restocker_name_snapshot`, `restocker_code_snapshot` y `vault_reconciled_by_name_snapshot` tras baja lógica y renombrado del revisor | `PdoMachineCashCollectionRepositoryTest` · `MachineCashCollectionServiceTest` · `CashCollectionVaultAuditConstitutionalTest` (`5.2`–`5.4`) | ✅ 45/45 · 45/45 · 25/25 |
| **QA 2** Recibo opaco | Exactamente nueve campos, sin bolsa, contador, cambio, arqueo, PII ni `pickup_pin` | `CashCollectionDomainModelsTest` · `MachineCashCollectionServiceTest` · `CashCollectionVaultAuditApiTest` · `CashCollectionVaultAuditConstitutionalTest` · `restocker_route_view.test.mjs` | ✅ 65/65 · 45/45 · 30/30 · 25/25 · 209/209 |
| **QA 3** Detalle de visita y 6 KPIs | `cash_collection_summary` en la visita del Módulo 34 y KPIs recalculados sobre el filtro activo | `MachineCashCollectionServiceTest` · `CashCollectionVaultAuditApiTest` · `coordinator_product_loading_tab.test.mjs` · `restocker_route_view.test.mjs` | ✅ 45/45 · 30/30 · 80/80 · 209/209 |
| **QA 4** Exportación CSV | BOM UTF-8, delimitador `;`, neutralización de fórmulas y acceso exclusivo de Coordinación | `MachineCashCollectionServiceTest` · `CashCollectionVaultAuditApiTest` · `coordinator_product_loading_tab.test.mjs` | ✅ 45/45 · 30/30 · 80/80 |
| **T-CCV-12** Cierre global | Semilla compatible con averías térmicas vivas, purga de 38 tablas, catálogo de auditoría completo, salida canónica de aserciones y geometría móvil sin `style` | `SeedDataTest` · `SeedRunnerSingleEngineTest` · `TestDataCleanerIsolationTest` · `AuditActionCatalogTest` · `CoordinatorButtonVariantsGuardTest.mjs` · `php tests/run_all.php` | ✅ 10/10 · 26/26 · 50/50 · 27/27 · 25/25 · exit=0 |

---

## 4. Definición de Hecho (§8)

| DoD | Evidencia |
|---|---|
| **1.** Tabla 49, tipo #42, `MACHINE_CASH_COLLECTION` en `audit_entity_type`, `CHECK`, anti-borrado, inmutabilidad y guarda sanitaria | `PostgresCashCollectionVaultAuditSchemaTest` 46/46 · `PostgresSchemaTest` 153/153 · `PostgresConstitutionalTest` 86/86 · `SchemaConvergenceParityTest` 39/39 |
| **2.** Arqueo Ciego, primacía sanitaria, entrega en conserjería `≤ 10,00 €`, tolerancia `±1,00 €` y opacidad financiera | `MachineCashCollectionValidatorTest` 114/114 · `MachineCashCollectionServiceTest` 45/45 · `CashCollectionVaultAuditApiTest` 30/30 · `CashCollectionVaultAuditConstitutionalTest` 25/25 |
| **3.** Captura integrada e independiente, conserjería, recibo opaco, cola Offline e historial sin claves privadas | `restocker_route_view.test.mjs` 209/209 · `CashCollectionLayoutBrowserTest` 32/32 · `OfflinePwaAssetsAndUiGuardTest.mjs` 45/45 · `FrontendTemplateCompileTest.mjs` 15/15 · `CoordinatorButtonVariantsGuardTest.mjs` 25/25 |
| **4.** Sub-vista de Coordinación con 6 KPIs, filtros, recepción en cámara, modal de arqueo y CSV | `coordinator_product_loading_tab.test.mjs` 80/80 · `CashCollectionVaultAuditApiTest` 30/30 · `FrontendTemplateCompileTest.mjs` 15/15 |
| **5.** Batería global y canal `new_engine` | `php tests/run_all.php` → **exit=0**, 0 fallos y `DocMetricsGuard` coherente; cifra viva solo en `README.md` y `docs/features_pendientes.md`. `php tests/run_new_engine.php` → 65 suites, 64 en verde, 1 no ejecutable por el contrato TLS local, 2.123 aserciones y 0 fallos, `exit=0`. Sin esquemas ni datos de prueba residuales. |

---

## 5. Mantenimiento

Esta matriz es un artefacto vivo: cualquier cambio en un `RF`, criterio EARS, `RNF`, decisión `D`, caso límite `C` o resolución `QA` debe actualizar la fila correspondiente y su resultado medido en la misma entrega. La cifra viva de suites y aserciones de la batería global se declara solo en el `README.md` y en `docs/features_pendientes.md`; el resto de documentos la referencia sin duplicarla.
