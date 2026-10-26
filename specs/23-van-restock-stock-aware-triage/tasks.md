# PLAN DE TAREAS ATÓMICAS: ÓRDENES DE CARGA DE FURGONETA Y CRUCE DE STOCK EN TRIAJE

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 23 · Órdenes de Carga de Furgoneta y Cruce de Stock en Triaje (*Van Restock Orders & Stock-Aware Triage*)  
**Documento Canónico:** `specs/23-van-restock-stock-aware-triage/tasks.md`  
**Especificación Funcional:** [`spec.md`](spec.md) (v2.0, 2026-10-26) y espejo [`../functional/van_restock_stock_aware_triage_spec.md`](../functional/van_restock_stock_aware_triage_spec.md)  
**Contratos Técnicos:** [`../technical/van_restock_stock_aware_triage_contracts.md`](../technical/van_restock_stock_aware_triage_contracts.md)  
**Plan Arquitectónico:** [`plan.md`](plan.md)  
**Fecha:** Octubre 2026  

---

## Resumen de Fases y Tareas

| Fase | Tareas | Tiempo Estimado | Propósito |
| :--- | :--- | :--- | :--- |
| **Fase 1: Esquema de Base de Datos PostgreSQL / Supabase y Guardas de Esquema** | `T-RESTOCK-01`, `T-RESTOCK-02` | 45–55 min | Migración `016`, convergencia en `supabase_init.sql`, semillas, contrato de correspondencia, arnés de limpieza y suite de esquema PostgreSQL. |
| **Fase 2: Modelos de Dominio, DTOs, Excepciones y Calculador de Disponibilidad** | `T-RESTOCK-03`, `T-RESTOCK-04` | 45–55 min | Entidades `VanRestockOrder` y `VanRestockOrderItem`, `VanRestockProposalDto`, `VanRestockOrderValidationException`, `VanRestockOrderRepositoryInterface` y `VanStockReadinessCalculator` + test unitario. |
| **Fase 3: Cruce Informativo en 2 Niveles en Triaje (Módulo 17 + Módulo 18)** | `T-RESTOCK-05`, `T-RESTOCK-06` | 45–55 min | Extensión de `PdoTechnicianSuggestionRepository`, `TechnicianAssignmentSuggester` y `SuggestedTechnicianDto` con `van_stock_match` en 2 niveles sin alterar `TechnicianWorkloadScorer` ni el Art. II + test unitario. |
| **Fase 4: Capa de Infraestructura y Repositorio PDO de Órdenes de Carga** | `T-RESTOCK-07` | 30 min | `PdoVanRestockOrderRepository` (insumos de propuesta sin N+1, CRUD con baja lógica `removed_at`, confirmación móvil bajo `SELECT ... FOR UPDATE` y auditoría en `audit_log`). |
| **Fase 5: Servicio de Aplicación de Órdenes de Carga y Pruebas Unitarias** | `T-RESTOCK-08`, `T-RESTOCK-09` | 50–60 min | `VanRestockOrderService` (fórmula de pre-cálculo, tope doble `9999`, ordenación determinista, lote sin ausentes, confirmación móvil independiente de turno) + suites unitarias `VanRestockOrderProposalTest` y `VanRestockOrderServiceTest`. |
| **Fase 6: Controladores REST, Router, Privacidad y Suites de Integración HTTP** | `T-RESTOCK-10`, `T-RESTOCK-11` | 50–60 min | `CoordinatorVanRestockOrderController`, extensión de `TechnicianVanStockController`, `CoordinatorController`, `TechnicianController`, `LocationPortalController`, `AppRouter` y suite `VanRestockOrderApiTest`. |
| **Fase 7: Interfaz Frontend Vanilla ES Modules, Picking A4 y Certificación Integral** | `T-RESTOCK-12`, `T-RESTOCK-13` | 50–60 min | `api.js`, `AuditActionLabels.js`, `CoordinatorVanStockTab.js` + `@media print` Picking A4, `TechnicianVanStockPanel.js`, insignias en `CoordinatorDashboardView.js` y `TechnicianRouteView.js`, suite `VanRestockUiComponentsTest.mjs`, `run_all.php`, `DocMetricsGuard` y cierre documental. |

---

## Fase 1: Esquema de Base de Datos PostgreSQL / Supabase y Guardas de Esquema

- [x] **T-RESTOCK-01 · Migración `016_van_restock_orders.sql`, convergencia en `supabase_init.sql` y semillas**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** Ninguna
  * **Ficheros a crear o modificar:**
    * `database/supabase_migrations/016_van_restock_orders.sql` **(crear)**
    * `database/supabase_init.sql` **(modificar)**
    * `database/supabase_seeds.sql` **(modificar)**
    * `src/Infrastructure/Database/SeedRunner.php` **(modificar)**
    * `specs/technical/schema_correspondence_contract.md` **(modificar)**
  * **Requisitos que cubre:** `RF-02.1` a `RF-02.4`, `RF-04.1` a `RF-04.3`, `RF-05.2`, `RF-05.3`, `RNF-02`, Decisiones `D1` y `D3`, Resolución `QA 1`, Art. III.1 y Art. V.1 de `constitution.md`.
  * **Detalle de implementación:**
    1. Declarar en `016_van_restock_orders.sql` y en `database/supabase_init.sql` el valor `'VAN_RESTOCK_ORDER'` en el tipo `audit_entity_type`.
    2. Declarar la Tabla 32 `van_restock_orders` con sus claves foráneas (`technician_id`, `created_by`, `dispatched_by`, `confirmed_by`, `cancelled_by` hacia `users`), las restricciones `chk_van_restock_orders_status`, `chk_van_restock_orders_dispatch_consistency`, `chk_van_restock_orders_confirm_consistency` y `chk_van_restock_orders_cancel_consistency` ($\ge 20$ caracteres reales tras `btrim()`), el índice único parcial `uq_van_restock_open_per_tech (technician_id) WHERE status IN ('PENDING_PICKING', 'DISPATCHED')`, y los disparadores `trg_no_delete_van_restock_orders` (`prevent_hard_delete()`) y `trg_touch_van_restock_orders` (`touch_updated_at()`).
    3. Declarar la Tabla 33 `van_restock_order_items` con `uq_van_restock_order_item_part (order_id, spare_part_id)`, snapshots (`current_quantity_snapshot`, `min_threshold_snapshot`, `pending_request_qty_snapshot`), doble cantidad (`requested_qty` en `[1, 9999]`, `dispatched_qty` en `[0, 9999]` o `NULL`), motivo `reason` (`LOW_STOCK`, `PENDING_REQUEST`, `BOTH`, `MANUAL`), columnas de baja lógica `removed_at` y `removed_by` con `chk_van_restock_items_removed_consistency` (`QA 1`), y disparadores `trg_no_delete_van_restock_order_items` y `trg_touch_van_restock_order_items`.
    4. Añadir semillas de órdenes de carga en `database/supabase_seeds.sql` y `SeedRunner.php`, y actualizar `specs/technical/schema_correspondence_contract.md` y el bloque final de recuentos de `database/supabase_init.sql` (33 tablas).
  * **Hecho cuando:** `php bin/init_cloud_db.php` aplica el esquema y las semillas sin errores sobre PostgreSQL.

- [x] **T-RESTOCK-02 · Actualización de guardas de esquema y suite `PostgresVanRestockSchemaTest`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-RESTOCK-01`
  * **Ficheros a crear o modificar:**
    * `tests/Support/TestDataCleaner.php` **(modificar)**
    * `tests/Support/PostgresFixtureBuilder.php` **(modificar)**
    * `tests/integration/SchemaConvergenceParityTest.php` **(modificar)**
    * `tests/integration/PostgresSchemaTest.php` **(modificar)**
    * `tests/integration/PostgresVanRestockSchemaTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-02.3`, `RF-02.4`, `RF-04.2`, `RNF-02`, Decisión `D3`, Resolución `QA 1`, Art. III.1 y Art. V.1 de `constitution.md`.
  * **Detalle de implementación:**
    1. Añadir `van_restock_order_items` y `van_restock_orders` en `TestDataCleaner.php` antes de `spare_parts` y `users`.
    2. Registrar las columnas de estado/rama de `van_restock_orders` y `van_restock_order_items` en `PostgresFixtureBuilder::CRUD_EXCLUDED_COLUMNS` y ampliar `CONTRACT_TABLES` de 31 a 33 tablas en `PostgresSchemaTest.php` y `SchemaConvergenceParityTest.php`.
    3. Crear `tests/integration/PostgresVanRestockSchemaTest.php` verificando en el motor PostgreSQL: (a) unicidad de `uq_van_restock_open_per_tech` ante dos órdenes abiertas del mismo técnico y liberación tras pasar a `CONFIRMED_BY_TECH` o `CANCELLED`, (b) rechazo de cancelación con `< 20` caracteres reales en `chk_van_restock_orders_cancel_consistency`, (c) baja lógica consistente en `chk_van_restock_items_removed_consistency`, y (d) rechazo de `DELETE FROM` en ambas tablas (`trg_no_delete_*`).
  * **Hecho cuando:** `php tests/integration/PostgresVanRestockSchemaTest.php`, `php tests/integration/PostgresSchemaTest.php` y `php tests/integration/SchemaConvergenceParityTest.php` pasan al 100 % en verde con código de salida `0`.

---

## Fase 2: Modelos de Dominio, DTOs, Excepciones y Calculador de Disponibilidad

- [x] **T-RESTOCK-03 · Entidades `VanRestockOrder` y `VanRestockOrderItem`, DTO `VanRestockProposalDto`, excepción `VanRestockOrderValidationException` e interfaz `VanRestockOrderRepositoryInterface`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-RESTOCK-02`
  * **Ficheros a crear o modificar:**
    * `src/Core/Domain/Model/VanRestockOrder.php` **(crear)**
    * `src/Core/Domain/Model/VanRestockOrderItem.php` **(crear)**
    * `src/Application/DTO/VanRestockProposalDto.php` **(crear)**
    * `src/Core/Domain/Exception/VanRestockOrderValidationException.php` **(crear)**
    * `src/Core/Domain/Repository/VanRestockOrderRepositoryInterface.php` **(crear)**
  * **Requisitos que cubre:** `RF-01.1` a `RF-01.5`, `RF-02.1` a `RF-02.4`, `RF-04.1` a `RF-04.4`, `RF-05.1` a `RF-05.3`, Decisiones `D1`, `D3`, `D4`, Resoluciones `QA 1`, `QA 4`, `QA 5`, Art. III.1 y Art. V.1 de `constitution.md`.
  * **Detalle de implementación:**
    1. Implementar las entidades inmutables `VanRestockOrder` (con formato `orderCode()` `RO-XXXXXX` y serialización `toCoordinatorArray()`, `toTechnicianActiveArray()`, `toTechnicianConfirmedSummaryArray()` sin ningún campo de coste) y `VanRestockOrderItem`.
    2. Implementar `VanRestockProposalDto` para proyectar propuestas de carga por técnico con líneas ordenadas y banderas `technician_is_active` y `recipient_currently_absent`.
    3. Implementar `VanRestockOrderValidationException` con los 7 códigos de error canónicos (`INVALID_RESTOCK_ORDER_PAYLOAD`, `TECHNICIAN_NOT_ELIGIBLE`, `SPARE_PART_NOT_ELIGIBLE`, `RESTOCK_ORDER_NOT_FOUND`, `OPEN_RESTOCK_ORDER_ALREADY_EXISTS`, `INVALID_RESTOCK_ORDER_TRANSITION`, `RESTOCK_ORDER_OWNERSHIP_MISMATCH`) y estados HTTP (`422`, `409`, `404`, `403`).
    4. Declarar `VanRestockOrderRepositoryInterface`.
  * **Hecho cuando:** `php -l src/Core/Domain/Model/VanRestockOrder.php && php -l src/Core/Domain/Model/VanRestockOrderItem.php && php -l src/Application/DTO/VanRestockProposalDto.php && php -l src/Core/Domain/Exception/VanRestockOrderValidationException.php && php -l src/Core/Domain/Repository/VanRestockOrderRepositoryInterface.php` no reporta errores de sintaxis.

- [x] **T-RESTOCK-04 · Calculador `VanStockReadinessCalculator` y suite unitaria `VanStockReadinessCalculatorTest`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-RESTOCK-03`
  * **Ficheros a crear o modificar:**
    * `src/Application/Service/VanStockReadinessCalculator.php` **(crear)**
    * `tests/unit/VanStockReadinessCalculatorTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-05.4`, Caso Límite `C4`, Resoluciones `QA 2` y `QA 3`, Art. III.3 de `constitution.md`.
  * **Detalle de implementación:**
    1. Implementar `VanStockReadinessCalculator` que recibe el conjunto de averías activas, el mapa de solicitudes `PENDING` por avería (distinguiendo piezas catalogadas de fuera de catálogo) y el mapa de saldos de `technician_van_stocks` por técnico/pieza.
    2. Clasificar cada avería activa en:
       * `READY_IN_VAN` (`📦 Repuesto ya en furgoneta`) cuando el saldo del técnico asignado cubre las piezas de la avería y toda la demanda acumulada de sus averías activas.
       * `SHARED_IN_VAN` (`📦 Repuesto en furgoneta (stock compartido)`) cuando cubre la avería individual pero no alcanza para cubrir simultáneamente todas las averías activas del mismo técnico que piden la misma pieza (`QA 3`).
       * `NOT_READY` cuando el saldo es insuficiente para la avería.
       * `HAS_OUT_OF_CATALOG` cuando existe al menos una solicitud `PENDING` fuera de catálogo (`spare_part_id IS NULL`, `QA 2`).
       * `NOT_APPLICABLE` cuando la avería no está activa, no tiene técnico asignado o no tiene solicitudes en `PENDING`.
    3. Crear `tests/unit/VanStockReadinessCalculatorTest.php` certificando los 5 estados y el escenario de stock compartido entre dos averías de un mismo técnico.
  * **Hecho cuando:** `php tests/unit/VanStockReadinessCalculatorTest.php` pasa al 100 % en verde con código de salida `0`.

---

## Fase 3: Cruce Informativo en 2 Niveles en Triaje (Módulo 17 + Módulo 18)

- [x] **T-RESTOCK-05 · Extensión de `PdoTechnicianSuggestionRepository`, `TechnicianAssignmentSuggester` y `SuggestedTechnicianDto` con cruce informativo en 2 niveles**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-RESTOCK-04`
  * **Ficheros a crear o modificar:**
    * `src/Infrastructure/Repository/PdoTechnicianSuggestionRepository.php` **(modificar)**
    * `src/Application/Service/TechnicianAssignmentSuggester.php` **(modificar)**
    * `src/Application/DTO/SuggestedTechnicianDto.php` **(modificar)**
  * **Requisitos que cubre:** `RF-06.1` a `RF-06.5`, `RNF-01`, Decisión `D2`, Casos Límite `C1` y `C5`, Resolución `QA 2`, Art. II de `constitution.md`.
  * **Detalle de implementación:**
    1. En `PdoTechnicianSuggestionRepository::findSuggestionPhoto()`, recuperar en un número fijo de consultas SQL (cero N+1): la categoría de la avería y el modelo de la máquina; las solicitudes `PENDING` de la avería (`spare_part_requests` agrupadas por `spare_part_id` más indicador `has_pending_out_of_catalog_requests`); el conjunto de repuestos activos compatibles con `(TRIM(LOWER(machine.model)), mappedCategory)` (`TEMPERATURE_COLD` $\rightarrow$ `THERMAL`, `PAYMENT_SYSTEM` $\rightarrow$ `PAYMENT_SYSTEM`, `PRODUCT_JAM` $\rightarrow$ `MECHANICAL`, `ELECTRICAL_OFF` $\rightarrow$ `ELECTRONIC`, `OTHER` $\rightarrow$ cualquier categoría); y los saldos de `technician_van_stocks` de los técnicos candidatos.
    2. En `TechnicianAssignmentSuggester`, añadir el evaluador puro `evaluateVanStockMatch()` en 2 niveles (`EXPLICIT_REQUESTS` con degradación a `PARTIAL_MATCH` ante pieza fuera de catálogo `QA 2`, y `CATEGORY_COMPATIBILITY` con clasificación `NOT_APPLICABLE`, `FULL_MATCH`, `PARTIAL_MATCH`, `NO_STOCK`, `C1`).
    3. Incluir en cada candidato sugerido el bloque `van_stock_match` y la insignia informativa (`STOCK_COMPATIBLE`, `STOCK_PARCIAL`, `SIN_STOCK_COMPATIBLE`) en `badges`, **manteniendo 100 % intactos `TechnicianWorkloadScorer`, `total_score`, `score_breakdown` y el protocolo de escalado sanitario del Art. II (`D2`)**.
  * **Hecho cuando:** `php tests/unit/TechnicianWorkloadScorerTest.php && php tests/unit/TechnicianAssignmentSuggesterTest.php` siguen pasando al 100 % en verde sin regresiones.

- [x] **T-RESTOCK-06 · Suite unitaria `TechnicianAssignmentSuggesterVanStockTest`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-RESTOCK-05`
  * **Ficheros a crear o modificar:**
    * `tests/unit/TechnicianAssignmentSuggesterVanStockTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-06.1` a `RF-06.5`, Decisión `D2`, Casos Límite `C1` y `C5`, Resolución `QA 2`, Art. II de `constitution.md`.
  * **Detalle de implementación:**
    1. Probar el Nivel 1 (`EXPLICIT_REQUESTS`): cobertura completa (`FULL_MATCH`, `STOCK_COMPATIBLE`), cobertura parcial (`PARTIAL_MATCH`, `STOCK_PARCIAL`), sin stock (`NO_STOCK`, `SIN_STOCK_COMPATIBLE`), solicitud mixta con pieza fuera de catálogo degradada a `PARTIAL_MATCH` (`QA 2`), y solicitud únicamente fuera de catálogo (`NOT_APPLICABLE`, `QA 2`).
    2. Probar el Nivel 2 (`CATEGORY_COMPATIBILITY`): mapeo de las 5 categorías de avería (incluyendo `OTHER`), `NOT_APPLICABLE` cuando el catálogo compatible tiene 0 piezas (`C1`), y tratamiento de stock negativo como `0` unidades disponibles.
    3. Certificar que `total_score`, `score_breakdown`, el orden de candidatos y `sanitary_escalation_required` (Art. II) permanecen inalterados con independencia del stock de la furgoneta (`D2`).
  * **Hecho cuando:** `php tests/unit/TechnicianAssignmentSuggesterVanStockTest.php` pasa al 100 % en verde con código de salida `0`.

---

## Fase 4: Capa de Infraestructura y Repositorio PDO de Órdenes de Carga

- [x] **T-RESTOCK-07 · Implementación de `PdoVanRestockOrderRepository`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-RESTOCK-03`
  * **Ficheros a crear o modificar:**
    * `src/Infrastructure/Repository/PdoVanRestockOrderRepository.php` **(crear)**
  * **Requisitos que cubre:** `RF-01.1` a `RF-01.5`, `RF-02.1` a `RF-02.4`, `RF-04.1` a `RF-04.4`, `RF-05.1` a `RF-05.3`, `RNF-01`, `RNF-02`, Decisiones `D1`, `D3`, Resoluciones `QA 1`, `QA 4`, `QA 5`, Art. III.1 y Art. V.1 de `constitution.md`.
  * **Detalle de implementación:**
    1. Implementar `fetchProposalInputs(?int $technicianId)` en un número constante de consultas SQL: técnicos elegibles con flag de ausencia activa en `technician_absences` (`starts_at <= CURRENT_TIMESTAMP AND ends_at >= CURRENT_TIMESTAMP AND cancelled_at IS NULL`) y orden abierta actual; catálogo de repuestos activos; saldos y umbrales de `technician_van_stocks`; y suma de `spare_part_requests` en `PENDING` de averías activas (`ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS`, `PENDING_INFO`, `REOPENED`).
    2. Implementar `createOrder()`, `updatePendingOrderWithSoftDelete()` (aplicando `UPDATE van_restock_order_items SET removed_at = CURRENT_TIMESTAMP, removed_by = :actor_id` a las líneas retiradas y reactivando con `removed_at = NULL, removed_by = NULL` si se vuelve a añadir la misma pieza en la misma orden, `QA 1` / Art. III.1), `markDispatched()` y `markCancelled()`.
    3. Implementar `confirmOrderAndIncrementVanStock()` bajo una única transacción PDO con `SELECT ... FOR UPDATE` en `van_restock_orders` y en cada fila de `technician_van_stocks`, verificando el tope `quantity_available + dispatched_qty <= 9999` (`QA 1`) y registrando el evento correspondiente en `audit_log` (`entity_type = 'VAN_RESTOCK_ORDER'`).
    4. Implementar las lecturas `findById()`, `findAllForCoordinator()` (ordenadas con abiertas `PENDING_PICKING`/`DISPATCHED` primero y cerradas después por `created_at DESC, id DESC`, `RF-04.4`), `findActiveDispatchedForTechnician()` y `findLastConfirmedForTechnician()` (`QA 5`).
  * **Hecho cuando:** `php -l src/Infrastructure/Repository/PdoVanRestockOrderRepository.php` no reporta errores de sintaxis.

---

## Fase 5: Servicio de Aplicación de Órdenes de Carga y Pruebas Unitarias

- [ ] **T-RESTOCK-08 · Servicio `VanRestockOrderService` y suite `VanRestockOrderProposalTest`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-RESTOCK-07`
  * **Ficheros a crear o modificar:**
    * `src/Application/Service/VanRestockOrderService.php` **(crear)**
    * `tests/unit/VanRestockOrderProposalTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-01.1` a `RF-01.5`, `RF-02.1` a `RF-02.4`, `RF-04.1` a `RF-04.3`, `RF-05.1` a `RF-05.3`, Decisiones `D1`, `D3`, `D4`, Casos Límite `C2` y `C3`, Resoluciones `QA 1`, `QA 4`, `QA 5`, Art. III.1 y Art. V.1 de `constitution.md`.
  * **Detalle de implementación:**
    1. Implementar en `VanRestockOrderService` el motor de pre-cálculo (`getProposals()`) con las tres fórmulas exactas (`LOW_STOCK`, `PENDING_REQUEST`, `BOTH`), aplicación del tope doble `min(9999, 9999 - current_quantity, raw_qty)` (`QA 1`) y ordenación determinista (`BOTH` $\rightarrow$ `PENDING_REQUEST` $\rightarrow$ `LOW_STOCK` $\rightarrow$ `MANUAL`, luego `current_quantity ASC`, luego `part_code ASC`, `QA 5`).
    2. Implementar `createOrder()`, `generateBatch()` (excluyendo técnicos con ausencia activa `SKIPPED_ACTIVE_ABSENCE`, técnicos con orden abierta `SKIPPED_OPEN_ORDER_EXISTS` y técnicos sin déficit `SKIPPED_NO_DEFICIT`, `QA 4`), `updateOrder()`, `dispatchOrder()`, `cancelOrder()` ($\ge 20$ caracteres reales tras `trim()`, Art. V.1) y `confirmOrder()` (independiente del estado de turno en `technician_shifts`, `QA 4`).
    3. Crear `tests/unit/VanRestockOrderProposalTest.php` certificando todos los casos de la fórmula de pre-cálculo (incluyendo saldos negativos `C2`, umbral `0`, demanda combinada `BOTH`, tope doble `9999` `QA 1` y ordenación determinista `QA 5`).
  * **Hecho cuando:** `php tests/unit/VanRestockOrderProposalTest.php` pasa al 100 % en verde con código de salida `0`.

- [ ] **T-RESTOCK-09 · Suite unitaria `VanRestockOrderServiceTest`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-RESTOCK-08`
  * **Ficheros a crear o modificar:**
    * `tests/unit/VanRestockOrderServiceTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-02.1` a `RF-02.4`, `RF-04.1` a `RF-04.3`, `RF-05.1` a `RF-05.3`, `RNF-02`, Decisiones `D1` y `D3`, Caso Límite `C3`, Resoluciones `QA 1` y `QA 4`, Art. III.1 y Art. V.1 de `constitution.md`.
  * **Detalle de implementación:**
    1. Certificar creación individual, rechazo a técnicos inactivos (`422 TECHNICIAN_NOT_ELIGIBLE`), permiso con bandera `recipient_currently_absent = true` en creación individual para técnico ausente (`QA 4`), y rechazo `409 OPEN_RESTOCK_ORDER_ALREADY_EXISTS` cuando ya existe orden abierta (`D3`).
    2. Certificar generación por lotes verificando el desglose de creadas y omitidas (`SKIPPED_ACTIVE_ABSENCE`, `SKIPPED_OPEN_ORDER_EXISTS`, `SKIPPED_NO_DEFICIT`).
    3. Certificar edición en `PENDING_PICKING` con baja lógica (`removed_at`) y reactivación al re-añadir la misma pieza (`QA 1`), expedición parcial (`dispatched_qty = 0` en una línea y `> 0` en otra, `C3`), rechazo si $\sum \text{dispatched\_qty} == 0$, rechazo de cancelación con `< 20` caracteres reales, y confirmación móvil en 1 toque tanto en `ON_SHIFT` como en `OFF_SHIFT` (`QA 4`).
  * **Hecho cuando:** `php tests/unit/VanRestockOrderServiceTest.php` pasa al 100 % en verde con código de salida `0`.

---

## Fase 6: Controladores REST, Router, Privacidad y Suites de Integración HTTP

- [ ] **T-RESTOCK-10 · Controladores `CoordinatorVanRestockOrderController`, extensión de `TechnicianVanStockController`, enriquecimiento de tarjetas de avería y rutas en `AppRouter`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-RESTOCK-04`, `T-RESTOCK-08`
  * **Ficheros a crear o modificar:**
    * `src/Presentation/Controller/CoordinatorVanRestockOrderController.php` **(crear)**
    * `src/Presentation/Controller/TechnicianVanStockController.php` **(modificar)**
    * `src/Presentation/Controller/CoordinatorController.php` **(modificar)**
    * `src/Presentation/Controller/TechnicianController.php` **(modificar)**
    * `src/Presentation/Controller/LocationPortalController.php` **(modificar)**
    * `src/Presentation/Routing/AppRouter.php` **(modificar)**
  * **Requisitos que cubre:** `RF-01` a `RF-06`, `RNF-03`, Resoluciones `QA 1` a `QA 5`, Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. Implementar `CoordinatorVanRestockOrderController` con las 8 acciones REST (`getProposals`, `listOrders`, `getOrderDetail`, `createOrder`, `generateBatch`, `updateOrder`, `dispatchOrder`, `cancelOrder`).
    2. Extender `TechnicianVanStockController::getInventory()` para incluir `active_dispatched_order` y `last_confirmed_order` (`QA 5`), y añadir `confirmRestockOrder()` (`POST /api/technician/van-stock/restock-orders/{id}/confirm`).
    3. Enriquecer los listados de averías en `CoordinatorController::getIncidents()` y `TechnicianController::getMyRoute()` adjuntando `van_stock_readiness` mediante `VanStockReadinessCalculator` (`RF-05.4`, `QA 3`).
    4. Ampliar la lista de claves vedadas en `LocationPortalController` (`van_stock_match`, `van_stock_readiness`, `active_dispatched_order`, `last_confirmed_order`, `restock_order`, Art. V.4) y registrar todas las rutas nuevas en `AppRouter.php`.
  * **Hecho cuando:** `php -l src/Presentation/Controller/CoordinatorVanRestockOrderController.php && php -l src/Presentation/Controller/TechnicianVanStockController.php && php -l src/Presentation/Routing/AppRouter.php` no reporta errores y `php tests/unit/VanStockPrivacyTest.php` pasa en verde.

- [ ] **T-RESTOCK-11 · Suite de integración `VanRestockOrderApiTest`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-RESTOCK-10`
  * **Ficheros a crear o modificar:**
    * `tests/integration/VanRestockOrderApiTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-01` a `RF-06`, `RNF-01` a `RNF-03`, Decisiones `D1` a `D4`, Casos Límite `C1` a `C5`, Resoluciones `QA 1` a `QA 5`, Art. II, Art. III.1, Art. III.3, Art. V.1 y Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. Certificar el flujo completo end-to-end contra PostgreSQL: consulta de propuesta con saldo negativo y solicitud `PENDING` $\rightarrow$ generación por lotes (verificando exclusión de técnico con ausencia activa) $\rightarrow$ edición en `PENDING_PICKING` verificando que la línea retirada persiste con `removed_at IS NOT NULL` en `van_restock_order_items` (`Art. III.1`) $\rightarrow$ expedición con `dispatched_qty` parcial $\rightarrow$ visualización de `active_dispatched_order` en `GET /api/technician/van-stock` $\rightarrow$ confirmación móvil en 1 toque $\rightarrow$ verificación del saldo actualizado en `technician_van_stocks`, del comprobante `last_confirmed_order`, del registro en `audit_log` y de la insignia `van_stock_readiness` (`READY_IN_VAN` y `SHARED_IN_VAN`) en las averías activas.
    2. Certificar el endpoint enriquecido `GET /api/coordinator/incidents/{id}/suggested-technicians` en los 2 niveles (`EXPLICIT_REQUESTS` y `CATEGORY_COMPATIBILITY`) verificando que `total_score` no varía (`D2`).
    3. Certificar el bloqueo de concurrencia (`409` al crear segunda orden abierta o al confirmar dos veces la misma orden) y la segregación de roles (`403` para `LOCATION_RESPONSIBLE` y `403` para otro `TECHNICIAN`, Art. V.4).
  * **Hecho cuando:** `php tests/integration/VanRestockOrderApiTest.php` pasa al 100 % en verde con código de salida `0`.

---

## Fase 7: Interfaz Frontend Vanilla ES Modules, Picking A4 y Certificación Integral

- [ ] **T-RESTOCK-12 · Componentes Frontend (`CoordinatorVanStockTab.js`, `TechnicianVanStockPanel.js`, `CoordinatorDashboardView.js`, `TechnicianRouteView.js`), Picking A4 y suite `VanRestockUiComponentsTest.mjs`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-RESTOCK-11`
  * **Ficheros a crear o modificar:**
    * `public/assets/js/api.js` **(modificar)**
    * `public/assets/js/utils/AuditActionLabels.js` **(modificar)**
    * `public/assets/js/components/CoordinatorVanStockTab.js` **(modificar)**
    * `public/assets/js/components/TechnicianVanStockPanel.js` **(modificar)**
    * `public/assets/js/views/CoordinatorDashboardView.js` **(modificar)**
    * `public/assets/js/views/TechnicianRouteView.js` **(modificar)**
    * `public/assets/css/styles.css` **(modificar)**
    * `tests/unit/VanRestockUiComponentsTest.mjs` **(crear)**
  * **Requisitos que cubre:** `RF-02` a `RF-06`, `RNF-03`, `RNF-04`, Resoluciones `QA 2`, `QA 3`, `QA 4`, `QA 5`, Art. IV y Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. Añadir los métodos cliente en `public/assets/js/api.js` y las traducciones de acciones en `AuditActionLabels.js`.
    2. En `CoordinatorVanStockTab.js`, incorporar el modo **«📦 Órdenes de Carga»** respetando estrictamente `docs/design.md` y `CoordinatorButtonVariantsGuardTest.mjs` (escalera `vg-btn` / `vg-btn-sm` / `vg-btn-tab` sin geometría inline en `<button>`, agrupadores `vg-action-group` y `vg-row-actions`, tonos ámbar reservados a lecturas y nunca pintados a mano en botones, `var(--font-display)` para titulares/KPIs y `var(--font-body)` para tablas/controles, bordes `var(--radius-interactive)` de 4px y `var(--radius-card)` de 8px sin sombra en reposo) con propuestas pre-calculadas, botón de generación por lotes, creación/edición en `PENDING_PICKING`, modal de expedición con `dispatched_qty`, modal de cancelación ($\ge 20$ caracteres), alerta `⚠️ Destinatario actualmente ausente / inactivo` (`QA 4`) y vista imprimible de **Albarán de Picking A4** (`window.print()`, `RF-03`) sin ningún coste ni precio (`Art. V.4`). Añadir estilos `@media print` en `public/assets/css/styles.css` usando solo variables CSS `var(--color-*)`.
    3. En `TechnicianVanStockPanel.js`, renderizar la tarjeta destacada de recepción en 1 toque (`active_dispatched_order`) con botón `vg-btn vg-btn-primary` (`min-height: 44px`) y el bloque de comprobante de última recepción (`last_confirmed_order`, `QA 5`).
    4. En `CoordinatorDashboardView.js` y `TechnicianRouteView.js`, mostrar las insignias de `van_stock_match` en el modal de sugerencia de triaje (`RF-06`) y el chip `van_stock_readiness.badge_label` (`📦 Repuesto ya en furgoneta` / `stock compartido`) en las tarjetas de averías activas (`RF-05.4`, `QA 3`).
    5. Crear `tests/unit/VanRestockUiComponentsTest.mjs` verificando `data-testid`, ausencia de `reference_cost` en el Picking A4 y cumplimiento del trinquete de tokens CSS.
  * **Hecho cuando:** `node tests/unit/VanRestockUiComponentsTest.mjs`, `node tests/unit/CoordinatorButtonVariantsGuardTest.mjs` y `php tests/unit/DesignTokenDebtRatchetTest.php` pasan al 100 % en verde.

- [ ] **T-RESTOCK-13 · Certificación Integral, Regresión Global y Cierre Documental**
  * **Tiempo estimado:** 20 min
  * **Dependencias previas:** `T-RESTOCK-12`
  * **Ficheros a crear o modificar:**
    * `tests/Support/DocMetricsGuard.php` **(modificar si aplica)**
    * `README.md` y `CHANGELOG.md` **(modificar)**
    * `docs/features_pendientes.md` **(modificar)**
  * **Requisitos que cubre:** `RF-01` a `RF-06`, `RNF-01` a `RNF-04`, Art. I a Art. VI de `constitution.md`.
  * **Detalle de implementación:**
    1. Ejecutar la batería completa de pruebas (`php tests/run_all.php`) y las suites del canal `new_engine` (`PostgresSchemaTest`, `SchemaConvergenceParityTest`, `PostgresVanRestockSchemaTest`).
    2. Sincronizar los contadores de suites, aserciones y tablas en `DocMetricsGuard.php`, `README.md`, `CHANGELOG.md` y marcar la **Feature 9 (`📦 Órdenes de Carga de Furgoneta y Cruce de Stock en Triaje`)** como completada en `docs/features_pendientes.md`.
  * **Hecho cuando:** `php tests/run_all.php` finaliza con **0 fallos** en todas las suites unitarias, de integración, constitucionales y documentales.
