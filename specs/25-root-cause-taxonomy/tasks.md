# PLAN DE TAREAS ATÓMICAS: TAXONOMÍA DE CAUSAS RAÍZ Y SOLUCIONES ESTANDARIZADAS

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 25 · Taxonomía de Causas Raíz y Soluciones Estandarizadas (*Root Cause Taxonomy & Standardized Solutions*)  
**Documento Canónico:** `specs/25-root-cause-taxonomy/tasks.md`  
**Especificación Funcional:** [`spec.md`](spec.md) (v2.1, Octubre 2026) · [`../functional/root_cause_taxonomy_spec.md`](../functional/root_cause_taxonomy_spec.md)  
**Contratos Técnicos:** [`../technical/root_cause_taxonomy_contracts.md`](../technical/root_cause_taxonomy_contracts.md)  
**Plan Arquitectónico:** [`plan.md`](plan.md)  
**Fecha:** Octubre 2026  

---

## Resumen de Fases y Tareas

| Fase | Tareas | Tiempo Estimado | Propósito |
| :--- | :--- | :--- | :--- |
| **Fase 1: Esquema de Base de Datos PostgreSQL / Supabase, Semillas y Guardas de Esquema** | `T-TAXONOMY-01`, `T-TAXONOMY-02` | 45–50 min | Migración incremental `021_root_cause_taxonomy.sql`, convergencia en `supabase_init.sql` (Tablas 35 `root_causes` y 36 `standard_solutions` + ampliación de `incidents` Tabla 4 a 50 columnas), semillas de las 16 causas raíz y 34 soluciones, contrato de correspondencia, arnés de limpieza y suite de esquema PostgreSQL. |
| **Fase 2: Modelos de Dominio, Validadores Puros y Normalizador de Fabricante** | `T-TAXONOMY-03`, `T-TAXONOMY-04` | 45–50 min | Entidades `RootCause` y `StandardSolution`, ampliación de `Incident`, validador puro `ResolutionTaxonomyValidator` (independencia $\ge 20$ caracteres, tolerancia offline `D2`), normalizador determinista `ManufacturerNormalizer` (`QA 4`), interfaz `RootCauseRepositoryInterface` y suites unitarias `ResolutionTaxonomyValidatorTest` y `ManufacturerNormalizerTest`. |
| **Fase 3: Capa de Infraestructura y Repositorios PDO (`pdo_pgsql`)** | `T-TAXONOMY-05`, `T-TAXONOMY-06` | 50–55 min | `PdoRootCauseRepository` (lectura sin N+1, bloqueos `FOR UPDATE`, alta y conmutación atómica de `is_default`), `PdoRootCauseParetoAnalyticsRepository` (agregación por `resolved_at` vigente, `QA 2`) y ampliación de `PdoIncidentRepository` (hidratación/persistencia de snapshots y preservación en `incident_history` ante reaperturas `< 48h`, `C1`). |
| **Fase 4: Servicios de Aplicación (`RootCauseTaxonomyService`, `RootCauseParetoService` e Integración)** | `T-TAXONOMY-07`, `T-TAXONOMY-08`, `T-TAXONOMY-09` | 75–80 min | `RootCauseTaxonomyService` (invariantes `QA 1`, `C2`, `C4`, auditoría `ROOT_CAUSE_TAXONOMY`), `RootCauseParetoService` (motor 80/20, redondeo a 2 decimales con cierre en `100.00%`, desempate determinista `C6`, CSV `UTF-8 BOM` en 2 secciones `QA 2`), integración en `SparePartTraceabilityService`, `OfflineBootstrapSnapshotDto`, `OfflineSyncService` (`C5`) y `CoordinatorIncidentDetailDto` + suites unitarias `RootCauseTaxonomyServiceTest` y `RootCauseParetoServiceTest`. |
| **Fase 5: Controladores REST, Router, Blindaje de Roles Art. V.4 y Suites de Integración HTTP** | `T-TAXONOMY-10`, `T-TAXONOMY-11` | 50–55 min | `CoordinatorRootCauseController`, ampliación de `CoordinatorMetricsController`, `TechnicianController`, `CoordinatorController` (`QA 3`), `TechnicianOfflineSyncController` y blindaje en `LocationPortalController` (Art. V.4, `QA 5`), rutas en `AppRouter` y suite `RootCauseTaxonomyIntegrationTest`. |
| **Fase 6: Integración de Chart.js Local, Precaché PWA y Componentes Frontend (Vue 3 ESM)** | `T-TAXONOMY-12`, `T-TAXONOMY-13`, `T-TAXONOMY-14`, `T-TAXONOMY-15` | 95–110 min | Chart.js 4.x local en `/public/assets/js/vendor/chart.umd.min.js` (`D6`), `sw.js` (`vendguard-app-shell-v3`); soporte en `OfflineStorage.js`, `OfflineSyncManager.js` y selector 1-Tap en `TechnicianRouteView.js` (`D1`–`D3`); componentes `CoordinatorRootCauseParetoTab.js` y `CoordinatorRootCauseCatalogModal.js`, e integración en `CoordinatorIncidentDetailModal.js`, `CoordinatorDashboardView.js` y `CoordinatorMetricsView.js` + suite ESM `RootCauseTaxonomyFrontendAndPwaTest.mjs`. |
| **Fase 7: Certificación Integral, Matriz de Trazabilidad, Regresión Global y Cierre Documental** | `T-TAXONOMY-16` | 25–30 min | `specs/25-root-cause-taxonomy/traceability-matrix.md`, batería completa `php tests/run_all.php`, guardas de diseño (`DesignTokenDebtRatchetTest`), correspondencia de esquema (`SchemaCorrespondenceTest`), dialecto (`SingleEngineDialectContractTest`) y actualización de `docs/features_pendientes.md`. |

---

## Fase 1: Esquema de Base de Datos PostgreSQL / Supabase, Semillas y Guardas de Esquema

- [x] **T-TAXONOMY-01 · Migración `021_root_cause_taxonomy.sql`, convergencia en `supabase_init.sql` y semillas del catálogo inicial**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** Ninguna
  * **Ficheros a crear o modificar:**
    * `database/supabase_migrations/021_root_cause_taxonomy.sql` **(crear)**
    * `database/supabase_init.sql` **(modificar)**
    * `database/supabase_seeds.sql` **(modificar)**
    * `src/Infrastructure/Database/SeedRunner.php` **(modificar)**
    * `specs/technical/database_schema.md` **(modificar)**
    * `specs/technical/schema_correspondence_contract.md` **(modificar)**
  * **Requisitos que cubre:** `RF-01` (`EARS 01.1` a `EARS 01.7`), `RF-02` (`EARS 02.5`), `RNF-01`, `RNF-02`, Decisiones `D1` y `D2`, Casos Límite `C2` y `C4`, Resoluciones `QA 1` y `QA 3`, Art. III.1 y Art. IV.2 de `constitution.md`.
  * **Detalle de implementación:**
    1. Declarar en `database/supabase_migrations/021_root_cause_taxonomy.sql` y en `database/supabase_init.sql` la **Tabla 35 `root_causes`** (`id`, `code` único `^[A-Z0-9-]+$`, `category` `incident_category`, `name`, `description`, `is_active`, `created_at`, `updated_at`), sus índices `idx_root_causes_category_active` e `idx_root_causes_active_code` y el disparador `trg_root_causes_updated_at`.
    2. Declarar la **Tabla 36 `standard_solutions`** (`id`, `root_cause_id` FK hacia `root_causes(id) ON DELETE RESTRICT`, `code` único `^[A-Z0-9-]+$`, `name`, `is_default`, `is_active`, `created_at`, `updated_at`), el índice `idx_standard_solutions_cause_active`, el **índice único parcial `idx_standard_solutions_one_default_per_cause ON standard_solutions (root_cause_id) WHERE is_default = TRUE AND is_active = TRUE`** (`D1`, `QA 1`) y el disparador `trg_standard_solutions_updated_at`.
    3. Ampliar la **Tabla 4 `incidents`** de 43 a **50 columnas** añadiendo `root_cause_id` (`ON DELETE RESTRICT`), `standard_solution_id` (`ON DELETE RESTRICT`), `root_cause_code_snapshot`, `root_cause_category_snapshot`, `root_cause_name_snapshot`, `standard_solution_code_snapshot` y `standard_solution_name_snapshot`, la restricción todo-o-nada `chk_incidents_taxonomy_pair_consistency` y los índices analíticos `idx_incidents_root_cause_pareto` e `idx_incidents_standard_solution`.
    4. Sembrar de forma idempotente (`ON CONFLICT (code) DO NOTHING`) las **16 Causas Raíz** (`RC-MEC-001..003`, `RC-ELE-001..002`, `RC-PAY-001..003`, `RC-REF-001..003`, `RC-DSP-001..002`, `RC-COM-001`, `RC-EXT-001..002`) y las **34 Soluciones Estandarizadas** (`SOL-*`, exactamente 1 con `is_default = TRUE` por causa) más averías históricas resueltas clasificadas en `database/supabase_seeds.sql` y `SeedRunner.php`.
    5. Actualizar `specs/technical/database_schema.md` y `specs/technical/schema_correspondence_contract.md` (36 tablas de dominio y 50 columnas en `incidents`).
  * **Hecho cuando:** `php bin/init_cloud_db.php` aplica el esquema y las semillas sin errores sobre PostgreSQL.

- [x] **T-TAXONOMY-02 · Actualización de guardas de esquema y suite `PostgresRootCauseTaxonomySchemaTest`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TAXONOMY-01`
  * **Ficheros a crear o modificar:**
    * `tests/Support/TestDataCleaner.php` **(modificar)**
    * `tests/Support/PostgresFixtureBuilder.php` **(modificar)**
    * `tests/integration/SchemaConvergenceParityTest.php` **(modificar)**
    * `tests/integration/PostgresSchemaTest.php` **(modificar)**
    * `tests/integration/PostgresRootCauseTaxonomySchemaTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-01`, `RF-02`, `RNF-01`, `RNF-02`, Decisiones `D1` y `D2`, Resoluciones `QA 1` y `QA 3`, Art. III.1 y Art. IV.2 de `constitution.md`.
  * **Detalle de implementación:**
    1. Actualizar `TestDataCleaner.php` y `PostgresFixtureBuilder.php` para contemplar `root_causes`, `standard_solutions` y las 7 nuevas columnas de `incidents` sin borrar el catálogo maestro semilla durante limpiezas transaccionales de test o restaurándolo limpiamente.
    2. Actualizar `CONTRACT_TABLES` de 34 a **36 tablas** (`root_causes`, `standard_solutions`) y el inventario de columnas de `incidents` (50 columnas) en `PostgresSchemaTest.php` y `SchemaConvergenceParityTest.php`.
    3. Crear `tests/integration/PostgresRootCauseTaxonomySchemaTest.php` verificando en PostgreSQL: (a) unicidad de `idx_standard_solutions_one_default_per_cause` (rechazo al insertar una segunda solución activa con `is_default = TRUE` para la misma causa, pero aceptación de múltiples soluciones inactivas con `is_default = FALSE`), (b) rechazo por `chk_incidents_taxonomy_pair_consistency` si se intenta poblar parcialmente `root_cause_id` sin `standard_solution_id` o sin los 5 snapshots, y (c) bloqueo `ON DELETE RESTRICT` al intentar borrar físicamente una causa o solución referenciada por una incidencia.
  * **Hecho cuando:** `php tests/integration/PostgresRootCauseTaxonomySchemaTest.php && php tests/integration/PostgresSchemaTest.php && php tests/integration/SchemaConvergenceParityTest.php` pasan al 100 % en verde con código de salida `0`.

---

## Fase 2: Modelos de Dominio, Validadores Puros y Normalizador de Fabricante

- [x] **T-TAXONOMY-03 · Entidades `RootCause` y `StandardSolution`, ampliación de `Incident`, validador `ResolutionTaxonomyValidator`, normalizador `ManufacturerNormalizer` e interfaz `RootCauseRepositoryInterface`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TAXONOMY-02`
  * **Ficheros a crear o modificar:**
    * `src/Core/Domain/Model/RootCause.php` **(crear)**
    * `src/Core/Domain/Model/StandardSolution.php` **(crear)**
    * `src/Core/Domain/Model/Incident.php` **(modificar)**
    * `src/Core/Domain/Repository/RootCauseRepositoryInterface.php` **(crear)**
    * `src/Core/Service/ResolutionTaxonomyValidator.php` **(crear)**
    * `src/Core/Service/ManufacturerNormalizer.php` **(crear)**
  * **Requisitos que cubre:** `RF-01`, `RF-02`, `RF-03` (`EARS 03.3`), `RF-05` (`EARS 05.3`), Decisiones `D1`, `D2` y `D5`, Casos Límite `C1` y `C4`, Resoluciones `QA 1`, `QA 3` y `QA 4`, Art. V.1 de `constitution.md`.
  * **Detalle de implementación:**
    1. Implementar las entidades inmutables `RootCause` y `StandardSolution` con validación de formato de código (`^[A-Z0-9-]+$`) y longitud de nombres (`120` y `140` caracteres).
    2. Ampliar `Incident.php` con los 7 campos anulables (`rootCauseId`, `standardSolutionId`, `rootCauseCodeSnapshot`, `rootCauseCategorySnapshot`, `rootCauseNameSnapshot`, `standardSolutionCodeSnapshot`, `standardSolutionNameSnapshot`) y el método `applyResolutionTaxonomySnapshot(RootCause $cause, StandardSolution $solution)`.
    3. Implementar `ResolutionTaxonomyValidator`: valida presencia de ambos IDs (`MISSING_ROOT_CAUSE_OR_SOLUTION`), existencia (`ROOT_CAUSE_OR_SOLUTION_NOT_FOUND`), pertenencia jerárquica `solution.rootCauseId === rootCause.id` (`SOLUTION_DOES_NOT_BELONG_TO_ROOT_CAUSE`), exigencia de `is_active = true` en cierres online (`INACTIVE_ROOT_CAUSE_OR_SOLUTION`) con **exención explícita cuando `isOfflineDeferredSync = true`** (`D2`, `EARS 03.3`), y garantiza que jamás altera ni exime la validación de $\ge 20$ caracteres reales de `ResolutionValidator` (Art. V.1).
    4. Implementar `ManufacturerNormalizer` (`QA 4`): `extractManufacturer(?string $machineModel): string` que aplica `trim()`, extrae el primer token antes del primer espacio (o el modelo completo si es una sola palabra), lo convierte de forma *Case-Insensitive* a **Formato Título** (`Necta`, `Jofemar`, `Fasel`, `Saeco`, `Bianchi`, `Azkoyen`, `Sanden`), o devuelve `'Sin Marca Especificada'` si es nulo/vacío, además de `matchesFilter(?string $machineModel, ?string $manufacturerFilter): bool`.
    5. Declarar `RootCauseRepositoryInterface`.
  * **Hecho cuando:** `php -l src/Core/Domain/Model/RootCause.php && php -l src/Core/Domain/Model/StandardSolution.php && php -l src/Core/Domain/Model/Incident.php && php -l src/Core/Service/ResolutionTaxonomyValidator.php && php -l src/Core/Service/ManufacturerNormalizer.php` no reporta errores de sintaxis.

- [x] **T-TAXONOMY-04 · Suites unitarias `ResolutionTaxonomyValidatorTest` y `ManufacturerNormalizerTest`**
  * **Tiempo estimado:** 20 min
  * **Dependencias previas:** `T-TAXONOMY-03`
  * **Ficheros a crear o modificar:**
    * `tests/unit/ResolutionTaxonomyValidatorTest.php` **(crear)**
    * `tests/unit/ManufacturerNormalizerTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-02` (`EARS 02.3`, `EARS 02.4`), `RF-03` (`EARS 03.3`), `RF-05` (`EARS 05.3`), Decisiones `D1` y `D2`, Resoluciones `QA 3` y `QA 4`, Art. V.1 de `constitution.md`.
  * **Detalle de implementación:**
    1. En `ResolutionTaxonomyValidatorTest.php`, certificar: (a) rechazo `422` cuando falta `root_cause_id` o `standard_solution_id`, (b) rechazo `422` cuando la solución pertenece a otra causa raíz, (c) rechazo `422` cuando la causa o la solución están inactivas en resolución online (`isOfflineDeferredSync = false`), (d) aceptación y generación de snapshot cuando la causa o la solución fueron desactivadas pero se sincronizan desde la cola offline (`isOfflineDeferredSync = true`, `EARS 03.3`), y (e) independencia estricta respecto a `ResolutionValidator` ($\ge 20$ caracteres reales en diagnóstico y acción, Art. V.1).
    2. En `ManufacturerNormalizerTest.php`, certificar: (a) `"Necta Kikko Max"`, `"NECTA SAMBA"` y `"  necta   krea "` se normalizan siempre a `"Necta"` (`QA 4`), (b) `"Jofemar Vision ES Plus"` $\rightarrow$ `"Jofemar"`, (c) `"Fasel"` (una sola palabra) $\rightarrow$ `"Fasel"`, y (d) `null` o `"   "` $\rightarrow$ `"Sin Marca Especificada"`.
  * **Hecho cuando:** `php tests/unit/ResolutionTaxonomyValidatorTest.php && php tests/unit/ManufacturerNormalizerTest.php` pasan al 100 % en verde con código de salida `0`.

---

## Fase 3: Capa de Infraestructura y Repositorios PDO (`pdo_pgsql`)

- [x] **T-TAXONOMY-05 · Implementación de `PdoRootCauseRepository` y ampliación de `PdoIncidentRepository`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TAXONOMY-04`
  * **Ficheros a crear o modificar:**
    * `src/Infrastructure/Repository/PdoRootCauseRepository.php` **(crear)**
    * `src/Infrastructure/Repository/PdoIncidentRepository.php` **(modificar)**
  * **Requisitos que cubre:** `RF-01` (`EARS 01.1` a `EARS 01.7`), `RF-02` (`EARS 02.5`), Decisiones `D1` y `D3`, Casos Límite `C1`, `C2` y `C4`, Resoluciones `QA 1` y `QA 3`, Art. III.3 de `constitution.md`.
  * **Detalle de implementación:**
    1. Implementar en `PdoRootCauseRepository`:
       * `findActiveCatalogWithSolutions()`: consulta optimizada sin N+1 que devuelve todas las causas raíz con `is_active = TRUE` y sus soluciones con `is_active = TRUE` ordenadas por `is_default DESC, code ASC`.
       * `findFullCatalogForAdmin(?string $category, ?bool $isActive)`: listado administrativo con contador `incidents_count` de usos históricos por causa y por solución.
       * `findRootCauseById()`, `findSolutionById()`, `findRootCauseForUpdate()` y `findSolutionForUpdate()` (`SELECT ... FOR UPDATE`).
       * Operaciones transaccionales de inserción, actualización y `clearActiveDefaultForCause()` para conmutar `is_default = TRUE` respetando el índice único parcial de PostgreSQL.
    2. Ampliar `PdoIncidentRepository` para hidratar y persistir las 7 columnas de taxonomía en `incidents` y, cuando una avería reabierta en garantía (`< 48h`, `C1`) recibe una nueva clasificación en su segundo cierre, registrar la clasificación del episodio anterior en el historial/comentario de trazabilidad antes de actualizar los campos vigentes.
  * **Hecho cuando:** `php -l src/Infrastructure/Repository/PdoRootCauseRepository.php && php -l src/Infrastructure/Repository/PdoIncidentRepository.php` no reporta errores de sintaxis.

- [x] **T-TAXONOMY-06 · Implementación de `PdoRootCauseParetoAnalyticsRepository`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TAXONOMY-05`
  * **Ficheros a crear o modificar:**
    * `src/Infrastructure/Repository/PdoRootCauseParetoAnalyticsRepository.php` **(crear)**
  * **Requisitos que cubre:** `RF-05` (`EARS 05.1` a `EARS 05.6`), `RNF-01`, Decisión `D5`, Casos Límite `C1`, `C3` y `C6`, Resoluciones `QA 2` y `QA 4`.
  * **Detalle de implementación:**
    1. Implementar `fetchResolvedIncidentsForPareto(ParetoFilterDto $filter)` consultando las averías correctivas con estado actual `RESOLVED` o `CLOSED` cuya fecha `resolved_at` vigente se encuentre dentro de `[start_date, end_date]` (`QA 2`), aplicando filtros opcionales de `category`, `machine_model`, `location_id` y `technician_id`.
    2. Incluir mediante `LEFT JOIN` y subconsultas agregadas sin N+1: datos actuales del catálogo (`root_causes` y `standard_solutions`), los 5 campos `*_snapshot` congelados en `incidents`, `machines.model` y `machines.internal_code`, `locations.name`, nombre del técnico que resolvió, tiempo neto de resolución (MTTR neto descontando pausas autorizadas por repuesto/sede según Spec 20/22) y el total de unidades y coste en céntimos (`SUM(quantity_used * unit_cost_cents_snapshot)`) de `incident_spare_parts`.
    3. Implementar `fetchAvailableFilterOptions()` para poblar los selectores de modelos, sedes y técnicos en la pestaña analítica de Coordinación.
  * **Hecho cuando:** `php -l src/Infrastructure/Repository/PdoRootCauseParetoAnalyticsRepository.php` no reporta errores de sintaxis.

---

## Fase 4: Servicios de Aplicación (`RootCauseTaxonomyService`, `RootCauseParetoService` e Integración)

- [x] **T-TAXONOMY-07 · Implementación de `RootCauseTaxonomyService`, DTOs de catálogo y suite `RootCauseTaxonomyServiceTest`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-TAXONOMY-06`
  * **Ficheros a crear o modificar:**
    * `src/Application/DTO/RootCauseCatalogItemDto.php` **(crear)**
    * `src/Application/Service/RootCauseTaxonomyService.php` **(crear)**
    * `tests/unit/RootCauseTaxonomyServiceTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-01` (`EARS 01.1` a `EARS 01.7`), Decisiones `D1` y `D3`, Casos Límite `C2` y `C4`, Resolución `QA 1`, Art. III.3 de `constitution.md`.
  * **Detalle de implementación:**
    1. Implementar `RootCauseTaxonomyService`:
       * `createRootCauseWithInitialSolution()`: alta transaccional atómica de la Causa Raíz junto con su primera Solución Estandarizada con `is_default = TRUE` y `is_active = TRUE` (`QA 1`), rechazando payloads sin `initial_solution` (`422`) o códigos duplicados (`409`).
       * `updateRootCause()`: bloquea cualquier modificación de `code` o `category` (`422 TAXONOMY_IMMUTABLE_FIELD_MODIFICATION`, `C4`, `QA 1`) y bloquea la reactivación (`is_active = true`) de una causa inactiva que no tenga ninguna solución activa con `is_default = true` (`409 CANNOT_REACTIVATE_ORPHAN_ROOT_CAUSE`, `QA 1`).
       * `createStandardSolution()` y `updateStandardSolution()`: bloquean cambios en `code` o `root_cause_id` (`422`), conmutan atómicamente `is_default = true` desmarcando la anterior, impiden desactivar la última solución activa de una causa activa (`409 CANNOT_DEACTIVATE_LAST_ACTIVE_SOLUTION`, `C2`), impiden desactivar o desmarcar la solución con `is_default = true` sin relevarla antes (`409 CANNOT_DEACTIVATE_DEFAULT_SOLUTION` / `CANNOT_UNSET_DEFAULT_WITHOUT_REPLACEMENT`, `C2`, `QA 1`), y garantizan que una solución inactiva reactivada reingrese con `is_default = false` salvo designación explícita (`QA 1`).
       * Registro obligatorio en `audit_log` bajo `entity_type = 'ROOT_CAUSE_TAXONOMY'` con `previous_state` y `new_state` (Art. III.3).
    2. Crear `tests/unit/RootCauseTaxonomyServiceTest.php` certificando todos los invariantes anteriores.
  * **Hecho cuando:** `php tests/unit/RootCauseTaxonomyServiceTest.php` pasa al 100 % en verde con código de salida `0`.

- [ ] **T-TAXONOMY-08 · Implementación de `RootCauseParetoService`, DTOs de Pareto y suite `RootCauseParetoServiceTest`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TAXONOMY-07`
  * **Ficheros a crear o modificar:**
    * `src/Application/DTO/ParetoFilterDto.php` **(crear)**
    * `src/Application/DTO/RootCauseParetoReportDto.php` **(crear)**
    * `src/Application/Service/RootCauseParetoService.php` **(crear)**
    * `tests/unit/RootCauseParetoServiceTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-05` (`EARS 05.1` a `EARS 05.6`), Decisión `D5`, Casos Límite `C1`, `C3` y `C6`, Resoluciones `QA 2` y `QA 4`.
  * **Detalle de implementación:**
    1. Implementar `RootCauseParetoService::buildParetoReport(ParetoFilterDto $filter)`:
       * Filtra por fabricante normalizado mediante `ManufacturerNormalizer` (`QA 4`).
       * Separa `classified_incidents_count` (base `100.00%` de Pareto) de `unclassified_historical_count` (`C3`).
       * Si `classified_incidents_count === 0`, devuelve estado vacío seguro con `pareto_items = []` y contadores a `0` sin división por cero (`C6`).
       * Agrupa por `root_cause_id` (1 avería = 1 voto en su causa definitiva actual tras eventual reapertura, atribuyéndole el 100 % de su MTTR neto y coste de repuestos, `C1`, `QA 2`), ordena por `incidents_count DESC, root_cause_code ASC` (`C6`) y determina la solución más frecuente con desempate `count DESC, is_default DESC, code ASC` (`C6`).
       * Redondea `percentage` y `cumulative_percentage` a 2 decimales forzando el último elemento a `100.00%` exacto (`QA 2`), y marca `is_vital_few_80_20 = true` desde el rango 1 hasta e incluyendo la primera causa que alcanza o supera el `80.00%` acumulado (`C3`).
    2. Implementar `RootCauseParetoService::exportCsv(ParetoFilterDto $filter)`: genera el archivo `UTF-8 BOM` (`\xEF\xBB\xBF`) delimitado por `;` en **dos secciones** (`QA 2`: Sección 1 con nombres vigentes del catálogo; Sección 2 con doble columna de nombre vigente y nombre congelado original `*_snapshot`).
    3. Crear `tests/unit/RootCauseParetoServiceTest.php` verificando la matemática 80/20, el cierre en `100.00%`, los desempates, el estado vacío y las dos secciones del CSV.
  * **Hecho cuando:** `php tests/unit/RootCauseParetoServiceTest.php` pasa al 100 % en verde con código de salida `0`.

- [ ] **T-TAXONOMY-09 · Integración en `SparePartTraceabilityService`, `OfflineBootstrapSnapshotDto`, `OfflineSyncService` y `CoordinatorIncidentDetailDto`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TAXONOMY-08`
  * **Ficheros a crear o modificar:**
    * `src/Application/Service/SparePartTraceabilityService.php` **(modificar)**
    * `src/Application/DTO/OfflineBootstrapSnapshotDto.php` **(modificar)**
    * `src/Application/Service/OfflineSyncService.php` **(modificar)**
    * `src/Infrastructure/Repository/PdoOfflineSyncMutationRepository.php` **(modificar)**
    * `src/Application/DTO/CoordinatorIncidentDetailDto.php` **(modificar)**
  * **Requisitos que cubre:** `RF-02`, `RF-03` (`EARS 03.1` a `EARS 03.4`), `RF-04` (`EARS 04.1` a `EARS 04.3`), Decisiones `D1`, `D2` y `D4`, Casos Límite `C1`, `C4` y `C5`, Resoluciones `QA 3` y `QA 5`, Art. III.3 y Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. En `SparePartTraceabilityService::resolveIncidentWithConsumption()`, invocar `ResolutionTaxonomyValidator`, congelar los 5 campos `*_snapshot` en la entidad `Incident` e incluir `root_cause_classification` en la respuesta y en `audit_log`. Mantener compatibilidad con llamadas internas de test que no envíen claves de taxonomía salvo cuando `requireTaxonomy = true` o cuando se envíe cualquiera de las claves (`specs/technical/root_cause_taxonomy_contracts.md` §4.3).
    2. En `PdoOfflineSyncMutationRepository::fetchBootstrapSnapshotForTechnician()` y `OfflineBootstrapSnapshotDto`, añadir la colección `root_cause_taxonomy` con las causas y soluciones activas (`RF-03`).
    3. En `OfflineSyncService`, pasar `isOfflineDeferredSync = true` al resolver paradas desde la cola offline (`EARS 03.3`) y, cuando una resolución offline sufra colisión `409 CONFLICT` o se vuelque mediante `dumpConflict()`, preservar en el JSON de conflicto y en la Nota Interna de Taller (`[OFFLINE SYNC CONFLICT DUMP]`) el bloque `offline_root_cause_classification` con los códigos y nombres de la causa y solución seleccionadas in situ (`C5`, `EARS 03.4`).
    4. En `CoordinatorIncidentDetailDto` (y en el historial devuelto a `COORDINATOR` y `TECHNICIAN`), exponer `root_cause_classification` a partir de las columnas `*_snapshot` congeladas (`RF-04`).
  * **Hecho cuando:** `php -l src/Application/Service/SparePartTraceabilityService.php && php -l src/Application/DTO/OfflineBootstrapSnapshotDto.php && php -l src/Application/Service/OfflineSyncService.php && php -l src/Application/DTO/CoordinatorIncidentDetailDto.php` no reporta errores de sintaxis y `php tests/unit/OfflineSyncServiceTest.php` sigue pasando en verde.

---

## Fase 5: Controladores REST, Router, Blindaje de Roles Art. V.4 y Suites de Integración HTTP

- [ ] **T-TAXONOMY-10 · Controlador `CoordinatorRootCauseController`, ampliación de controladores de métricas/resolución, blindaje de Sede y rutas en `AppRouter`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-TAXONOMY-09`
  * **Ficheros a crear o modificar:**
    * `src/Presentation/Controller/CoordinatorRootCauseController.php` **(crear)**
    * `src/Presentation/Controller/CoordinatorMetricsController.php` **(modificar)**
    * `src/Presentation/Controller/TechnicianController.php` **(modificar)**
    * `src/Presentation/Controller/CoordinatorController.php` **(modificar)**
    * `src/Presentation/Controller/LocationPortalController.php` **(modificar)**
    * `src/Presentation/Routing/AppRouter.php` **(modificar)**
  * **Requisitos que cubre:** `RF-01` a `RF-05`, Decisiones `D1` a `D5`, Casos Límite `C1` a `C6`, Resoluciones `QA 1` a `QA 5`, Art. III.3 y Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. Crear `CoordinatorRootCauseController` verificando rol exclusivo `COORDINATOR` (`403 FORBIDDEN` para otros roles, `QA 5`) con las acciones `listCatalog()`, `createRootCause()`, `updateRootCause()`, `createSolution()` y `updateSolution()`.
    2. Ampliar `CoordinatorMetricsController` con `getRootCausesPareto()` (`GET /api/coordinator/metrics/root-causes-pareto`) y `exportRootCausesParetoCsv()` (`GET /api/coordinator/metrics/root-causes-pareto/export`), restringidos a `COORDINATOR` (`QA 5`).
    3. Ampliar `TechnicianController` con `listActiveRootCauses()` (`GET /api/technician/root-causes`, accesible a `TECHNICIAN` y `COORDINATOR`) y conectar `resolveIncident()` con la validación y persistencia de `root_cause_id` y `standard_solution_id`.
    4. Ampliar `CoordinatorController::resolveIncident()` (`POST /api/coordinator/incidents/{id}/resolve`, `QA 3`) exigiendo obligatoriamente `root_cause_id` y `standard_solution_id` (`requireTaxonomy = true`), manteniendo exento el cierre automático directo a `CLOSED` por reemplazo de máquina en 3.ª reincidencia crónica en `TechnicianOpportunisticController`.
    5. Blindar `LocationPortalController` (Art. V.4, `D4`, `QA 5`) añadiendo `root_cause_id`, `standard_solution_id`, `root_cause_code_snapshot`, `root_cause_category_snapshot`, `root_cause_name_snapshot`, `standard_solution_code_snapshot`, `standard_solution_name_snapshot` y `root_cause_classification` a la lista de claves internas excluidas para `LOCATION_RESPONSIBLE`, y registrar todas las nuevas rutas en `AppRouter.php`.
  * **Hecho cuando:** `php -l src/Presentation/Controller/CoordinatorRootCauseController.php && php -l src/Presentation/Controller/CoordinatorMetricsController.php && php -l src/Presentation/Controller/TechnicianController.php && php -l src/Presentation/Controller/CoordinatorController.php && php -l src/Presentation/Routing/AppRouter.php` no reporta errores de sintaxis.

- [ ] **T-TAXONOMY-11 · Suite de integración `RootCauseTaxonomyIntegrationTest`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TAXONOMY-10`
  * **Ficheros a crear o modificar:**
    * `tests/integration/RootCauseTaxonomyIntegrationTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-01` a `RF-05`, `RNF-01`, `RNF-02`, Decisiones `D1` a `D5`, Casos Límite `C1` a `C6`, Resoluciones `QA 1` a `QA 5`, Art. III.3, Art. V.1 y Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. Certificar el ciclo CRUD del catálogo maestro por `COORDINATOR`: alta atómica de causa + solución predeterminada inicial (`201`), conmutación de `is_default`, rechazo `422` al intentar alterar `code` o `category`, rechazo `409` al desactivar la última solución activa o la predeterminada vigente (`C2`, `QA 1`), bloqueo de reactivación de causa huérfana y registro en `audit_log`.
    2. Certificar el flujo de resolución de averías (técnico y coordinador, `QA 3`): rechazo `422` si falta taxonomía o si la solución no pertenece a la causa o si `diagnosis`/`action` tienen $< 20$ caracteres (Art. V.1); congelación de los 5 campos `*_snapshot`; y verificación de que un renombrado posterior en el catálogo no altera el snapshot del ticket cerrado (`C4`) pero sí actualiza el nombre en el ranking de Pareto (`QA 2`).
    3. Certificar la reapertura en garantía `< 48h` (`C1`): el segundo cierre actualiza la causa activa para Pareto mientras el primer episodio queda preservado en el historial.
    4. Certificar el soporte Offline PWA: presencia de `root_cause_taxonomy` en `GET /api/technician/offline/bootstrap`, aceptación de resolución offline cuya causa fue desactivada mientras el técnico estaba sin red (`EARS 03.3`), y preservación en `conflict_dump` ante colisión (`C5`).
    5. Certificar el motor de Pareto (`GET /api/coordinator/metrics/root-causes-pareto`) y la exportación CSV (`GET /api/coordinator/metrics/root-causes-pareto/export`) con filtro de fabricante (`QA 4`), Zona Vital 80/20 y las dos secciones con `UTF-8 BOM`.
    6. Certificar el blindaje de privacidad Art. V.4 y `QA 5`: `403 FORBIDDEN` para `TECHNICIAN` y `LOCATION_RESPONSIBLE` en Pareto/CSV/CRUD, y ausencia total de datos de taxonomía en el Portal de Sede, seguimiento QR y Albarán A4.
  * **Hecho cuando:** `php tests/integration/RootCauseTaxonomyIntegrationTest.php` pasa al 100 % en verde con código de salida `0`.

---

## Fase 6: Integración de Chart.js Local, Precaché PWA y Componentes Frontend (Vue 3 ESM)

- [ ] **T-TAXONOMY-12 · Incorporación de Chart.js 4.x local (`/public/assets/js/vendor/chart.umd.min.js`), enlace en `index.html`, soporte en `OfflineStorage.js` y `api.js`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TAXONOMY-11`
  * **Ficheros a crear o modificar:**
    * `public/assets/js/vendor/chart.umd.min.js` **(crear)**
    * `public/index.html` **(modificar)**
    * `public/assets/js/api.js` **(modificar)**
    * `public/assets/js/utils/OfflineStorage.js` **(modificar)**
    * `public/assets/js/utils/OfflineSyncManager.js` **(modificar)**
    * `public/assets/js/utils/AuditActionLabels.js` **(modificar)**
  * **Requisitos que cubre:** `RF-03` (`EARS 03.1` a `EARS 03.4`), `RF-05` (`EARS 05.2`), Decisión `D6`, Caso Límite `C5`, Art. IV.3 de `constitution.md`.
  * **Detalle de implementación:**
    1. Incorporar el bundle UMD oficial autocontenido de **Chart.js 4.x** en `/public/assets/js/vendor/chart.umd.min.js` (autorizado bajo Art. IV.3 en `D6`, sin `npm`, `node_modules` ni CDNs externos) y cargarlo mediante `<script src="/assets/js/vendor/chart.umd.min.js"></script>` en `public/index.html`.
    2. Añadir en `public/assets/js/api.js` los métodos `getTechnicianRootCauses()`, `getCoordinatorRootCauses()`, `createRootCause()`, `updateRootCause()`, `createStandardSolution()`, `updateStandardSolution()`, `getRootCausesPareto()` y `exportRootCausesParetoCsv()`.
    3. Extender `OfflineStorage.js` y `OfflineSyncManager.js` para persistir y exponer `root_cause_taxonomy` dentro del snapshot de ruta en `IndexedDB`, validar y transportar `root_cause_id` y `standard_solution_id` en las mutaciones `RESOLVE_INCIDENT` y preservar los metadatos de clasificación en el volcado de conflictos (`C5`).
    4. Añadir en `AuditActionLabels.js` las etiquetas en castellano para `CREATE_ROOT_CAUSE`, `UPDATE_ROOT_CAUSE`, `CREATE_STANDARD_SOLUTION` y `UPDATE_STANDARD_SOLUTION`.
  * **Hecho cuando:** `public/assets/js/vendor/chart.umd.min.js` existe, `public/index.html` lo referencia localmente y los módulos ES importan limpiamente en Node.

- [ ] **T-TAXONOMY-13 · Selector guiado con preselección 1-Tap en `TechnicianRouteView.js` y `CoordinatorIncidentDetailModal.js`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TAXONOMY-12`
  * **Ficheros a crear o modificar:**
    * `public/assets/js/views/TechnicianRouteView.js` **(modificar)**
    * `public/assets/js/components/CoordinatorIncidentDetailModal.js` **(modificar)**
  * **Requisitos que cubre:** `RF-02` (`EARS 02.1` a `EARS 02.4`), `RF-03` (`EARS 03.2`), `RF-04` (`EARS 04.1`, `EARS 04.2`), `RNF-03`, Decisiones `D1`, `D2`, `D3` y `D4`, Resolución `QA 3`, Art. V.1 y Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. En `TechnicianRouteView.js` (modal de resolución correctiva, tanto online como offline leyendo de `OfflineStorage`):
       * Renderizar el selector de **Causa Raíz** priorizando las causas activas cuya `category` coincide con la categoría de la avería más las de categoría `OTHER`, e incluir el conmutador táctil ($\ge 44\text{px}$) **«Mostrar todas las categorías»** (`D3`, `EARS 02.1`).
       * Al elegir una Causa Raíz, preseleccionar automáticamente en **1 toque** (`EARS 02.2`) la Solución Estandarizada activa marcada con `is_default === true`, permitiendo cambiarla si existen alternativas activas.
       * Garantizar que la selección **nunca rellene ni modifique** los campos de texto libre `diagnosis` y `action` ($\ge 20$ caracteres obligatorios, `D2`, Art. V.1) y deshabilitar el botón de confirmar resolución mientras falten `root_cause_id` o `standard_solution_id`.
    2. En `CoordinatorIncidentDetailModal.js`:
       * Mostrar la insignia estructurada `[RC-CODE] Nombre Causa → [SOL-CODE] Nombre Solución` en el expediente de averías resueltas/cerradas y en el historial de la máquina (`RF-04`).
       * Incorporar el mismo selector obligatorio de Causa Raíz y Solución Estandarizada cuando el Coordinador resuelva manualmente una avería desde el panel (`QA 3`).
  * **Hecho cuando:** `TechnicianRouteView.js` y `CoordinatorIncidentDetailModal.js` cargan sin errores y cumplen `DesignTokenDebtRatchetTest`.

- [ ] **T-TAXONOMY-14 · Componentes `CoordinatorRootCauseParetoTab.js` y `CoordinatorRootCauseCatalogModal.js` e integración en Coordinación y `sw.js`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-TAXONOMY-13`
  * **Ficheros a crear o modificar:**
    * `public/assets/js/components/CoordinatorRootCauseParetoTab.js` **(crear)**
    * `public/assets/js/components/CoordinatorRootCauseCatalogModal.js` **(crear)**
    * `public/assets/js/views/CoordinatorDashboardView.js` **(modificar)**
    * `public/assets/js/views/CoordinatorMetricsView.js` **(modificar)**
    * `public/assets/css/styles.css` **(modificar)**
    * `public/sw.js` **(modificar)**
  * **Requisitos que cubre:** `RF-01` (`EARS 01.1` a `EARS 01.6`), `RF-05` (`EARS 05.1` a `EARS 05.6`), `RNF-03`, Decisiones `D1`, `D3`, `D5` y `D6`, Casos Límite `C2`, `C3` y `C6`, Resoluciones `QA 1`, `QA 2` y `QA 4`.
  * **Detalle de implementación:**
    1. Crear `CoordinatorRootCauseParetoTab.js`:
       * Tarjetas KPI superiores (Total clasificadas, Causas en Zona Vital 80/20, % de averías concentradas en Zona Vital y Avisos históricos sin clasificar).
       * Barra de filtros multicriterio: rango de fechas (`start_date`, `end_date`), categoría, **Fabricante / Marca** (`available_filters.manufacturers`, `QA 4`), modelo de máquina, sede, técnico y conmutador «Solo Zona Vital 80/20».
       * Gráfico combinado de doble eje Y en **Chart.js** (`window.Chart`): barras verticales de frecuencia (`incidents_count`) coloreadas mediante tokens CSS computados (`getComputedStyle` sobre `--color-primary` para Zona Vital 80/20 y `--color-border` para el 20% trivial, cero literales hexadecimales), curva de porcentaje acumulado (`0–100%`) en el eje derecho y línea horizontal discontinua de corte en el `80%`.
       * Tabla de desglose con rango, código/nombre de causa, categoría, nº de averías, `%` individual, `%` acumulado, chip `ZONA VITAL 80/20`, solución más aplicada, marca/modelo más afectado, MTTR medio y unidades/coste (`€`) de repuestos consumidos, más botón de descarga CSV y botón para abrir el catálogo maestro.
    2. Crear `CoordinatorRootCauseCatalogModal.js`: administración completa del catálogo maestro (alta atómica de causa + solución predeterminada inicial, edición de nombres/descripción, alta de soluciones adicionales, conmutación en 1 clic de `is_default` y activación/desactivación con avisos claros ante las guardas `409`).
    3. Integrar la pestaña/acceso en `CoordinatorDashboardView.js` y `CoordinatorMetricsView.js`, añadir estilos con tokens CSS (`var(--color-*)`, bordes `4px`/`8px`) en `public/assets/css/styles.css` y actualizar `public/sw.js` (`CACHE_NAME = 'vendguard-app-shell-v3'` e inclusión de `/assets/js/vendor/chart.umd.min.js`, `/assets/js/components/CoordinatorRootCauseParetoTab.js` y `/assets/js/components/CoordinatorRootCauseCatalogModal.js` en `APP_SHELL_ASSETS`).
  * **Hecho cuando:** `php tests/unit/DesignTokenDebtRatchetTest.php` pasa en verde y `public/sw.js` enumera todos los nuevos activos del App Shell.

- [ ] **T-TAXONOMY-15 · Suite unitaria ESM `RootCauseTaxonomyFrontendAndPwaTest.mjs`**
  * **Tiempo estimado:** 20 min
  * **Dependencias previas:** `T-TAXONOMY-14`
  * **Ficheros a crear o modificar:**
    * `tests/unit/RootCauseTaxonomyFrontendAndPwaTest.mjs` **(crear)**
  * **Requisitos que cubre:** `RF-01` a `RF-05`, `RNF-03`, Decisiones `D1` a `D6`, Resoluciones `QA 1` a `QA 5`.
  * **Detalle de implementación:**
    1. Certificar que `public/sw.js` precachea `/assets/js/vendor/chart.umd.min.js`, `CoordinatorRootCauseParetoTab.js` y `CoordinatorRootCauseCatalogModal.js` y conserva la regla `Network-Only` para `/api/`.
    2. Certificar en `OfflineStorage.js` que el paquete `route_snapshots` conserva `root_cause_taxonomy` y que el encolado offline de `RESOLVE_INCIDENT` transporta `root_cause_id` y `standard_solution_id`.
    3. Certificar mediante inspección de contratos de UI que: (a) `TechnicianRouteView.js` implementa el filtrado por categoría + `OTHER`, el conmutador «Mostrar todas las categorías» y la preselección en 1 toque sin autorrellenar `diagnosis` ni `action`, (b) `CoordinatorRootCauseParetoTab.js` instancia el gráfico combinado de doble eje Y en Chart.js y expone el filtro por fabricante y la exportación CSV, y (c) `LocationPortalView.js`, `QrReportView.js` y el albarán A4 no exponen ninguna etiqueta ni campo de taxonomía interna (Art. V.4).
  * **Hecho cuando:** `node tests/unit/RootCauseTaxonomyFrontendAndPwaTest.mjs && node tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs && php tests/unit/DesignTokenDebtRatchetTest.php` pasan al 100 % en verde con código de salida `0`.

---

## Fase 7: Certificación Integral, Matriz de Trazabilidad, Regresión Global y Cierre Documental

- [ ] **T-TAXONOMY-16 · Matriz de trazabilidad (`traceability-matrix.md`), batería global `run_all.php` y actualización de `docs/features_pendientes.md`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TAXONOMY-15`
  * **Ficheros a crear o modificar:**
    * `specs/25-root-cause-taxonomy/traceability-matrix.md` **(crear)**
    * `docs/features_pendientes.md` **(modificar)**
  * **Requisitos que cubre:** Todos los requisitos `RF-01` a `RF-05`, `RNF-01` a `RNF-03`, Decisiones `D1`–`D6`, Casos Límite `C1`–`C6` y Resoluciones `QA 1`–`QA 5`.
  * **Detalle de implementación:**
    1. Redactar `specs/25-root-cause-taxonomy/traceability-matrix.md` vinculando cada historia de usuario (`HU-01` a `HU-04`), requisito funcional y criterio EARS (`RF-01` a `RF-05`), requisito no funcional (`RNF-01` a `RNF-03`), decisión (`D1`–`D6`), caso límite (`C1`–`C6`) y resolución QA (`QA 1`–`QA 5`) con sus ficheros de código y pruebas automatizadas.
    2. Actualizar `docs/features_pendientes.md` marcando la **Feature 11 (🔍 Taxonomía de Causas Raíz y Soluciones Estandarizadas)** como **100 % IMPLEMENTADA (Módulo 25: `25-root-cause-taxonomy`)**.
    3. Ejecutar la batería completa del proyecto (`php tests/run_all.php`), incluyendo `SchemaCorrespondenceTest`, `SingleEngineDialectContractTest` y `DesignTokenDebtRatchetTest`, verificando 0 fallos.
  * **Hecho cuando:** `php tests/run_all.php` finaliza con el 100 % de las suites y aserciones en verde (`exit code 0`).
