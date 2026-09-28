# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO M2: CATÁLOGO DE REPUESTOS Y TRAZABILIDAD DE PIEZAS EN INTERVENCIÓN (TASKS.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `06-spare-parts` (Opción B: Control Operativo y Taller)  
**Documento:** `specs/06-spare-parts/tasks.md`  
**Referencia Funcional:** [`specs/functional/spare_parts_spec.md`](../functional/spare_parts_spec.md) (RF-REP-01 a RF-REP-10, RNF-REP-01 a RNF-REP-05)  
**Contratos Técnicos y DDL:** [`specs/technical/spare_parts_contracts.md`](../technical/spare_parts_contracts.md)  
**Plan Técnico:** [`specs/06-spare-parts/plan.md`](plan.md)  
**Sistema de Diseño Visual:** [`docs/design.md`](../../docs/design.md) (Inspiración Docker: Azul eléctrico `#2560ff`, radio binario 4px/8px, fondo canvas `#f9fafb`, alertas `#f8b60f`)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Esquema de Base de Datos, Modelos y Excepciones de Dominio

- [x] **T-SPARE-01: Migración DDL idempotente y semillas normativas (`006_spare_parts_catalog_and_traceability.sql`, `cloud_init.sql`, `SeedRunner.php`)**
  * **Requisitos:** RF-REP-01, RF-REP-02, RF-REP-06, RF-REP-07, Constitución Art. III (Inviolabilidad de datos y soft delete)
  * **Dependencias:** Ninguna
  * **Hecho cuando:** La ejecución de `php bin/migrate.php` aplica con éxito `006_spare_parts_catalog_and_traceability.sql`, creando las tablas `spare_parts`, `spare_part_compatibilities`, `spare_part_requests` e `incident_replaced_parts`; `database/cloud_init.sql` y `SeedRunner.php` incorporan semillas maestras iniciales de repuestos y compatibilidades por modelo de máquina.

- [x] **T-SPARE-02: Implementar Enums y Modelos de Dominio (`SparePartCategory`, `OldPartDestination`, `SparePartRequestStatus`, `SparePart`, `SparePartRequest`, `IncidentReplacedPart`)**
  * **Requisitos:** RF-REP-01, RF-REP-03, RF-REP-05, RF-REP-06, Constitución Art. VI (Clasificación cerrada sin feature creep)
  * **Dependencias:** T-SPARE-01
  * **Hecho cuando:** Existen en `src/Core/Domain/Model/` los enums tipados (`SparePartCategory`, `OldPartDestination`, `SparePartRequestStatus`) y las entidades inmutables con tipado estricto PHP 8.2+ (`SparePart`, `SparePartRequest`, `IncidentReplacedPart`) con validaciones de invariantes de dominio.

- [x] **T-SPARE-03: Implementar Excepciones de Dominio y suite unitaria (`SparePartExceptionsTest.php`)**
  * **Requisitos:** RF-REP-01, RF-REP-03, RF-REP-04, RF-REP-06, RF-REP-10, Constitución Art. V.4
  * **Dependencias:** T-SPARE-02
  * **Hecho cuando:** Existen en `src/Core/Domain/Exception/` las clases tipadas (`SparePartCodeExistsException`, `IncompatibleSparePartException`, `InvalidOutOfCatalogJustificationException`, `InvalidPartQuantityException`, `SitePartsDataForbiddenException`), y `php tests/unit/SparePartExceptionsTest.php` pasa al 100% en verde evaluando códigos HTTP (`403`, `409`, `422`), códigos de error técnicos y mensajes en castellano.

---

## Fase 2: Repositorios PDO y Capa de Persistencia

- [x] **T-SPARE-04: Implementar `SparePartRepositoryInterface` y `PdoSparePartRepository.php`**
  * **Requisitos:** RF-REP-01, RF-REP-02, RNF-REP-01, Constitución Art. III
  * **Dependencias:** T-SPARE-01, T-SPARE-02, T-SPARE-03
  * **Hecho cuando:** `PdoSparePartRepository` implementa creación de repuesto con modelos compatibles (`create`), actualización (`update`), baja lógica (`softDelete` fijando `is_active = 0`), búsqueda por ID y código (`findById`, `findByCode`), listado filtrado con compatibilidades (`findAll`), listado de repuestos compatibles con un modelo de máquina (`findCompatibleWithModel`) y consulta de modelos únicos (`findDistinctMachineModels`).

- [x] **T-SPARE-05: Implementar `SparePartRequestRepositoryInterface` y `PdoSparePartRequestRepository.php`**
  * **Requisitos:** RF-REP-03, RF-REP-04
  * **Dependencias:** T-SPARE-01, T-SPARE-02
  * **Hecho cuando:** `PdoSparePartRequestRepository` implementa registro de solicitudes en pausa técnica (`createRequest`), consulta de solicitudes por incidencia (`findByIncidentId`), transición atómica de solicitudes a atendidas (`markAttendedByIncident`) y listado de solicitudes fuera de catálogo pendientes de revisión para el coordinador.

- [ ] **T-SPARE-06: Implementar `IncidentReplacedPartRepositoryInterface` y `PdoIncidentReplacedPartRepository.php`**
  * **Requisitos:** RF-REP-06, RF-REP-07, RF-REP-08, RNF-REP-04, Constitución Art. III
  * **Dependencias:** T-SPARE-01, T-SPARE-02
  * **Hecho cuando:** `PdoIncidentReplacedPartRepository` implementa inserción transaccional de piezas sustituidas capturando el snapshot inmutable de coste (`insertReplacedPart` con `unit_cost_snapshot`), consulta de piezas por incidencia/preventivo, agregaciones de costes acumulados por modelo/sede y consulta de piezas con $> 3$ sustituciones en 90 días naturales para alertas de fallo crónico.

---

## Fase 3: Servicios de Aplicación y Pruebas Unitarias de Lógica

- [ ] **T-SPARE-07: Implementar `SparePartCatalogService.php` y suite unitaria `SparePartCatalogServiceTest.php`**
  * **Requisitos:** RF-REP-01, RF-REP-02, RNF-REP-01, Constitución Art. III
  * **Dependencias:** T-SPARE-04
  * **Hecho cuando:** `SparePartCatalogService` valida unicidad de código, consistencia de precios ($\ge 0.00$ con 2 decimales) y modelos asociados; `php tests/unit/SparePartCatalogServiceTest.php` pasa al 100% en verde comprobando creación, edición, rechazo de duplicados y baja lógica sin borrado físico.

- [ ] **T-SPARE-08: Implementar `SparePartTraceabilityService.php` y suite unitaria `SparePartTraceabilityServiceTest.php`**
  * **Requisitos:** RF-REP-03, RF-REP-04, RF-REP-05, RF-REP-06, RF-REP-07, RNF-REP-04, Constitución Art. III, Art. V.1
  * **Dependencias:** T-SPARE-05, T-SPARE-06
  * **Hecho cuando:** `SparePartTraceabilityService` procesa la pausa estructurada (rechazando solicitudes vacías o justificantes $< 20$ chars) y la resolución de averías/preventivos (exigiendo declaración Sí/No y congelando snapshots de coste unitario); `php tests/unit/SparePartTraceabilityServiceTest.php` pasa al 100% en verde validando que cambios posteriores en el catálogo maestro no alteran el coste de las intervenciones registradas.

- [ ] **T-SPARE-09: Implementar `SparePartAnalyticsService.php` y suite unitaria `SparePartAnalyticsServiceTest.php`**
  * **Requisitos:** RF-REP-08, RF-REP-09
  * **Dependencias:** T-SPARE-06
  * **Hecho cuando:** `SparePartAnalyticsService` consolida el ranking de piezas más sustituidas, costes por modelo/sede, detecta alertas de avería recurrente ($> 3$ sustituciones en la misma máquina en 90 días) y genera el contenido plano CSV con codificación UTF-8; `php tests/unit/SparePartAnalyticsServiceTest.php` pasa al 100% en verde.

---

## Fase 4: Controladores HTTP, Middlewares y Enrutamiento

- [ ] **T-SPARE-10: Implementar `CoordinatorSparePartsController.php`**
  * **Requisitos:** RF-REP-01, RF-REP-02, RF-REP-04, RF-REP-08, RF-REP-09
  * **Dependencias:** T-SPARE-07, T-SPARE-09
  * **Hecho cuando:** `CoordinatorSparePartsController` expone los endpoints de catálogo (`getCatalog`, `createPart`, `updatePart`, `toggleStatus`, `getModels`), el panel analítico (`getAnalytics`), la exportación de consumos (`exportCsv`) y la bandeja de revisión de repuestos no catalogados (`getPendingReviewRequests`), con validaciones y respuestas JSON conforme a contrato.

- [ ] **T-SPARE-11: Implementar `TechnicianSparePartsController.php` y actualizar `TechnicianController.php`**
  * **Requisitos:** RF-REP-03, RF-REP-04, RF-REP-05, RF-REP-06, RNF-REP-02
  * **Dependencias:** T-SPARE-08
  * **Hecho cuando:** `TechnicianSparePartsController` sirve el catálogo compatible con la máquina en $< 250\text{ ms}$; `TechnicianController::pauseIntervention` procesa solicitudes estructuradas en `spare_part_requests` y `TechnicianController::resolveIncident` ejecuta la resolución obligatoriamente justificada con registro de piezas sustituidas, destino `DESGUACE`/`TALLER` y congelación de coste.

- [ ] **T-SPARE-12: Actualizar `TechnicianPreventiveController.php` y blindar `LocationPortalController.php`**
  * **Requisitos:** RF-REP-07, RF-REP-10, Constitución Art. II, Art. V.4
  * **Dependencias:** T-SPARE-08
  * **Hecho cuando:** `TechnicianPreventiveController::completeInspection` admite la declaración opcional de piezas sustituidas en preventivos; `LocationPortalController` garantiza la exclusión estricta de piezas, códigos y costes en todas las respuestas dirigidas a usuarios de sede.

- [ ] **T-SPARE-13: Registrar rutas en `AppRouter.php` y verificar enrutamiento (`SparePartsRoutesTest.php`)**
  * **Requisitos:** RF-REP-01 a RF-REP-10
  * **Dependencias:** T-SPARE-10, T-SPARE-11, T-SPARE-12
  * **Hecho cuando:** Todas las rutas `/api/coordinator/spare-parts/*` y `/api/technician/spare-parts/*` están registradas con sus respectivos middlewares de rol (`COORDINATOR` o `TECHNICIAN`); la suite unitaria `php tests/unit/SparePartsRoutesTest.php` pasa al 100% en verde verificando métodos, URIs y rechazo ante accesos no autorizados.

---

## Fase 5: Componentes Frontend Reactivos (ES Modules) y Tests Unitarios Reactivos

- [ ] **T-SPARE-14: Implementar componente `CoordinatorSparePartsTab.js` y test reactivo `CoordinatorSparePartsTabTest.mjs`**
  * **Requisitos:** RF-REP-01, RF-REP-02, RNF-REP-03, docs/design.md (Azul eléctrico `#2560ff`, radio 4px en botones/badges y 8px en tarjetas)
  * **Dependencias:** T-SPARE-10
  * **Hecho cuando:** `CoordinatorSparePartsTab.js` renderiza la tabla de repuestos con buscador en tiempo real, filtros por categoría y modelo, botón de conmutación de estado activo/inactivo y modal de alta/edición con selector dinámico de modelos compatibles; respeta las directrices visuales de `docs/design.md`; `node tests/unit/CoordinatorSparePartsTabTest.mjs` pasa al 100% en verde.

- [ ] **T-SPARE-15: Implementar componente `CoordinatorSparePartsAnalyticsTab.js`**
  * **Requisitos:** RF-REP-04, RF-REP-08, RF-REP-09, docs/design.md (Alertas de fallo crónico en `#f8b60f`, tarjetas de métricas)
  * **Dependencias:** T-SPARE-10
  * **Hecho cuando:** `CoordinatorSparePartsAnalyticsTab.js` renderiza las tarjetas KPI de gasto y consumo, banners de alertas por averías crónicas (> 3 sustituciones en 90 días con estilo de advertencia Docker), tabla ranking de piezas sustituidas con desglose de destino, botón de descarga directa de CSV y bandeja de piezas fuera de catálogo pendientes de revisión.

- [ ] **T-SPARE-16: Implementar componentes móviles `TechnicianSparePartsPauseModal.js` y `TechnicianResolutionPartsBlock.js` con test `TechnicianSparePartsModalsTest.mjs`**
  * **Requisitos:** RF-REP-03, RF-REP-04, RF-REP-05, RF-REP-06, RNF-REP-03, docs/design.md (Touch targets $\ge 44\text{px}$, conmutadores táctiles)
  * **Dependencias:** T-SPARE-11
  * **Hecho cuando:** `TechnicianSparePartsPauseModal.js` permite seleccionar piezas compatibles o justificar piezas fuera de catálogo ($\ge 20$ chars); `TechnicianResolutionPartsBlock.js` exige la respuesta obligatoria Sí/No y despliega selectores táctiles de repuestos, cantidades (1–50) y destino `DESGUACE`/`TALLER` con objetivos táctiles móviles $\ge 44\text{px}$; `node tests/unit/TechnicianSparePartsModalsTest.mjs` pasa al 100% en verde.

- [ ] **T-SPARE-17: Integrar sustitución de piezas en `TechnicianChecklistModal.js` y navegación en `app.js`**
  * **Requisitos:** RF-REP-07, Dogma Vanilla, docs/design.md
  * **Dependencias:** T-SPARE-12, T-SPARE-14, T-SPARE-15, T-SPARE-16
  * **Hecho cuando:** Al completar una orden preventiva en `TechnicianChecklistModal.js` se permite declarar piezas sustituidas; `app.js` registra las pestañas `repuestos` y `analitica-repuestos` en la barra del coordinador respetando el estilo de navegación activa de `docs/design.md` y enlaza los modales en la vista de técnico.

---

## Fase 6: Pruebas de Integración HTTP, Blindaje Constitucional y Cierre

- [ ] **T-SPARE-18: Pruebas de integración HTTP de Coordinación (`CoordinatorSparePartsApiTest.php`)**
  * **Requisitos:** RF-REP-01, RF-REP-02, RF-REP-08, RF-REP-09
  * **Dependencias:** T-SPARE-10, T-SPARE-13
  * **Hecho cuando:** `php tests/run_all.php` ejecuta `CoordinatorSparePartsApiTest.php` contra MariaDB real pasando al 100% en verde en flujos de creación, validación de códigos duplicados, baja lógica, cálculo analítico y descarga de CSV.

- [ ] **T-SPARE-19: Pruebas de integración HTTP de Técnico (`TechnicianSparePartsApiTest.php`)**
  * **Requisitos:** RF-REP-03, RF-REP-04, RF-REP-05, RF-REP-06, RF-REP-07, RNF-REP-02, RNF-REP-04
  * **Dependencias:** T-SPARE-11, T-SPARE-12, T-SPARE-13
  * **Hecho cuando:** `php tests/run_all.php` ejecuta `TechnicianSparePartsApiTest.php` contra MariaDB real pasando al 100% en verde en flujos de consulta de catálogo compatible, pausa técnica estructurada y resolución con congelación inmutable de costes.

- [ ] **T-SPARE-20: Pruebas de blindaje constitucional y segregación de sede (`SiteManagerPartsDataSegregationTest.php`)**
  * **Requisitos:** RF-REP-10, Constitución Art. V.4
  * **Dependencias:** T-SPARE-12, T-SPARE-13
  * **Hecho cuando:** `php tests/run_all.php` ejecuta `SiteManagerPartsDataSegregationTest.php` pasando al 100% en verde, certificando que ningún endpoint accesible por el rol `LOCATION_MANAGER` expone piezas solicitadas, sustituidas, destinos ni costes económicos.

- [ ] **T-SPARE-21: Verificación global de regresión, Dogma Vanilla y cierre de módulo**
  * **Requisitos:** Todos (RF-REP-01 a RF-REP-10, RNF-REP-01 a RNF-REP-05, Constitución Art. I a VII)
  * **Dependencias:** T-SPARE-01 a T-SPARE-20
  * **Hecho cuando:** La ejecución de `php tests/run_all.php` completa todas las suites unitarias PHP, unitarias reactivas frontend e integración con 0 fallos y 0 errores; se verifica la ausencia de dependencias externas npm/composer y el cumplimiento estricto del Dualismo Lingüístico.
