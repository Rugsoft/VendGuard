# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO 17: ZONIFICACIÓN OPERATIVA Y ASIGNACIÓN ASISTIDA (TASKS.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `17-operational-zones`  
**Documento:** `specs/17-operational-zones/tasks.md`  
**Referencia Funcional:** [`specs/17-operational-zones/spec.md`](spec.md) y [`specs/functional/operational_zones_spec.md`](../functional/operational_zones_spec.md) (`RF-01` a `RF-07`, `RNF-01` a `RNF-04`, Resoluciones QA 1 a 6)  
**Plan Técnico:** [`specs/17-operational-zones/plan.md`](plan.md)  
**Contratos Técnicos:** [`specs/technical/operational_zones_contracts.md`](../technical/operational_zones_contracts.md)  
**Normativa Suprema:** [`constitution.md`](../../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [`AGENTS.md`](../../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)  
**Sistema de Diseño Visual:** [`docs/design.md`](../../docs/design.md) (Tokens Docker: Azul `#2560ff`, ámbar `#fef9c3`/`#854d0e`, rojo crítico `#dc2626`, canvas `#f9fafb`, radio `4px`/`8px`)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Esquema de Base de Datos PostgreSQL / Supabase

- [ ] **T-ZONE-01 · Crear migración incremental `007_operational_zones.sql` y converger `supabase_init.sql` y `supabase_seeds.sql`**
  * **Tiempo estimado:** 25 min
  * **Dependencias:** Ninguna
  * **Ficheros a crear o modificar:**
    * `database/supabase_migrations/007_operational_zones.sql` *(crear)*
    * `database/supabase_init.sql` *(modificar)*
    * `database/supabase_seeds.sql` *(modificar)*
    * `specs/technical/schema_correspondence_contract.md` *(modificar)*
  * **Requisitos que cubre:** `RF-01.1`, `RF-01.2`, `RF-02.1`, `RF-03.1`, `RF-03.2`, Resoluciones QA 4 y QA 6, `constitution.md` Art. III.1, Art. IV y Art. V.7
  * **Hecho cuando:** Existen en `007_operational_zones.sql` y en `database/supabase_init.sql`: (1) tabla `operational_zones` con restricciones `CHECK` de nombre, código y color `#RRGGBB`; (2) tabla `operational_zone_postal_codes` con clave primaria en `postal_code` (`VARCHAR(5)`, `CHECK (postal_code ~ '^[0-9]{5}$')`) garantizando la unicidad territorial estricta a nivel físico; (3) columnas `zone_id` e `is_manual_override` en `locations` con índice `idx_locations_zone_id`; (4) tabla `technician_zone_assignments` con restricción `UNIQUE(technician_id, zone_id)` y el índice único parcial `uq_technician_primary_zone` (`WHERE is_primary = TRUE`); y `PostgresMigrationRunner` aplica la migración `007` sin errores dejando en verde `php tests/integration/PostgresSchemaTest.php` y `php tests/integration/SchemaConvergenceParityTest.php`.

- [ ] **T-ZONE-02 · Implementar suite de integración de esquema `PostgresZoneSchemaTest.php`**
  * **Tiempo estimado:** 20 min
  * **Dependencias:** `T-ZONE-01`
  * **Ficheros a crear o modificar:**
    * `tests/integration/PostgresZoneSchemaTest.php` *(crear)*
  * **Requisitos que cubre:** `RF-01.2`, `RF-02.1`, `RF-03.2`, `RNF-04`, `constitution.md` Art. III.1 y Art. V.7
  * **Hecho cuando:** `php tests/integration/PostgresZoneSchemaTest.php` pasa al 100% en verde certificando contra PostgreSQL: (1) el rechazo de inserción de un código postal duplicado entre dos zonas por clave primaria; (2) el rechazo de asignar a un mismo técnico como titular (`is_primary = TRUE`) en dos zonas distintas vía `uq_technician_primary_zone`; (3) la admisión de múltiples suplencias (`is_primary = FALSE`) para un mismo técnico; (4) la desvinculación segura `ON DELETE SET NULL` de `locations.zone_id` al eliminar una zona; y (5) la correcta validación regex de `color_hex` y `postal_code`.

---

## Fase 2: Modelos de Dominio, Value Objects, Excepciones y Políticas Puras

- [ ] **T-ZONE-03 · Implementar entidad `OperationalZone`, Value Objects (`ZoneAffinityType`, `PostalCode`), Excepciones de Dominio y test unitario**
  * **Tiempo estimado:** 20 min
  * **Dependencias:** `T-ZONE-01`
  * **Ficheros a crear o modificar:**
    * `src/Core/Domain/Model/OperationalZone.php` *(crear)*
    * `src/Core/Domain/ValueObject/ZoneAffinityType.php` *(crear)*
    * `src/Core/Domain/ValueObject/PostalCode.php` *(crear)*
    * `src/Core/Domain/Exception/ZoneNotFoundException.php` *(crear)*
    * `src/Core/Domain/Exception/ZoneValidationException.php` *(crear)*
    * `src/Core/Domain/Exception/PostalCodeConflictException.php` *(crear)*
    * `src/Core/Domain/Exception/PrimaryTechnicianConflictException.php` *(crear)*
    * `src/Core/Domain/Exception/ColdChainOverrideJustificationRequiredException.php` *(crear)*
    * `tests/unit/PostalCodeValidationTest.php` *(crear)*
  * **Requisitos que cubre:** `RF-01.1`, `RF-01.2`, `RF-02.1`, `RF-05.2`, Resoluciones QA 1 y QA 4
  * **Hecho cuando:** `PostalCode` normaliza con ceros a la izquierda y rechaza cadenas no conformes con `^\d{5}$`, `ZoneAffinityType` define `PRIMARY`, `BACKUP`, `NONE` con sus etiquetas en español, las 5 excepciones tipadas exponen sus códigos y estados HTTP (`400`, `404`, `409`, `422`), la entidad inmutable `OperationalZone` serializa e hidrata sus datos vía `fromDatabaseRow()` y `toArray()`, y `php tests/unit/PostalCodeValidationTest.php` pasa al 100% en verde con 0 fallos.

- [ ] **T-ZONE-04 · Implementar política pura de dominio `TechnicianWorkloadScorer` y su suite unitaria**
  * **Tiempo estimado:** 25 min
  * **Dependencias:** `T-ZONE-03`
  * **Ficheros a crear o modificar:**
    * `src/Core/Domain/Service/TechnicianWorkloadScorer.php` *(crear)*
    * `tests/unit/TechnicianWorkloadScorerTest.php` *(crear)*
  * **Requisitos que cubre:** `RF-04.1`, `RF-05.1`, `RNF-01`, Resoluciones QA 1, QA 2 y QA 3
  * **Hecho cuando:** `TechnicianWorkloadScorer::computeScore()` es una función pura sin efectos secundarios que calcula exactamente: titularidad (+50), suplencia (+25), presencia in situ en la misma sede (+40), paradas en la misma zona (+20), penalización por parada en curso en otra sede (-15), carga viva activa (-5 por avería o preventivo hoy), pausa por falta de acceso `PENDING_INFO` (-2) y escalado sanitario de guardia (+100 en frío con paradas previas del titular); y `php tests/unit/TechnicianWorkloadScorerTest.php` pasa al 100% en verde evaluando todos los casos y combinaciones de la matriz.

---

## Fase 3: Capa de Infraestructura y Repositorio PDO

- [ ] **T-ZONE-05 · Crear `OperationalZoneRepositoryInterface`, `PdoOperationalZoneRepository` y extender `PdoLocationRepository`**
  * **Tiempo estimado:** 30 min
  * **Dependencias:** `T-ZONE-03`
  * **Ficheros a crear o modificar:**
    * `src/Core/Domain/Repository/OperationalZoneRepositoryInterface.php` *(crear)*
    * `src/Infrastructure/Repository/PdoOperationalZoneRepository.php` *(crear)*
    * `src/Infrastructure/Repository/PdoLocationRepository.php` *(modificar)*
  * **Requisitos que cubre:** `RF-01.1`, `RF-02.1`, `RF-02.2`, `RF-03.1`, `RF-03.2`, `RNF-01`, `constitution.md` Art. IV
  * **Hecho cuando:** `PdoOperationalZoneRepository` implementa las consultas de listado con conteos agregados de sedes, técnicos y códigos postales en un solo round-trip mediante CTE/subqueries; permite vincular técnicos como titular o suplente; `PdoLocationRepository::setManualOverride()` conmuta `is_manual_override` y `zone_id` de forma atómica; y `PdoLocationRepository::findInheritedZoneByPostalCode()` resuelve la zona correspondiente en $< 5\text{ ms}$.

- [ ] **T-ZONE-06 · Implementar migración transaccional de códigos postales con registro de auditoría dual matriz-sede (Art. III / QA 6)**
  * **Tiempo estimado:** 25 min
  * **Dependencias:** `T-ZONE-05`
  * **Ficheros a crear o modificar:**
    * `src/Infrastructure/Repository/PdoOperationalZoneRepository.php` *(modificar)*
  * **Requisitos que cubre:** `RF-01.2`, `RF-03.3`, `RNF-03`, `RNF-04`, Resolución QA 6, `constitution.md` Art. III.1, Art. III.2 y Art. V.7
  * **Hecho cuando:** El método `transferPostalCodes(int $newZoneId, array $postalCodes, int $userId)` ejecuta dentro de una transacción PDO: (1) la detección y bloqueo `FOR UPDATE` de las sedes que heredan dichos CPs con `is_manual_override = FALSE`; (2) la actualización atómica de `operational_zone_postal_codes`; (3) la actualización en bloque de `locations.zone_id`; (4) la inserción del evento matriz `OPERATIONAL_ZONE_POSTAL_CODE_TRANSFERRED` en `audit_log`; y (5) la inserción de los eventos individuales `LOCATION_ZONE_REASSIGNED` por cada sede afectada, haciendo rollback completo si ocurre cualquier fallo.

---

## Fase 4: Servicios de Aplicación y Casos de Uso

- [ ] **T-ZONE-07 · Implementar servicio de aplicación `OperationalZoneService` y DTOs**
  * **Tiempo estimado:** 25 min
  * **Dependencias:** `T-ZONE-05`, `T-ZONE-06`
  * **Ficheros a crear o modificar:**
    * `src/Application/DTO/OperationalZoneDto.php` *(crear)*
    * `src/Application/DTO/PostalCodeTransferResultDto.php` *(crear)*
    * `src/Application/Service/OperationalZoneService.php` *(crear)*
  * **Requisitos que cubre:** `RF-01.1`, `RF-01.3`, `RF-02.1`, `RF-02.2`, `RF-03.2`, Resoluciones QA 4 y QA 6
  * **Hecho cuando:** `OperationalZoneService` gestiona el ciclo de vida de zonas (alta, edición, desactivación), detecta si hay códigos postales en conflicto para requerir confirmación previa (`PostalCodeConflictException`), coordina el traslado atómico cuando `confirm_transfers = true`, valida que un técnico no tenga dos titularidades activas (`PrimaryTechnicianConflictException`) y gestiona las excepciones manuales de sede.

- [ ] **T-ZONE-08 · Implementar caso de uso `TechnicianAssignmentSuggester` con desempates deterministas y fallback Haversine sin GPS**
  * **Tiempo estimado:** 30 min
  * **Dependencias:** `T-ZONE-04`, `T-ZONE-05`
  * **Ficheros a crear o modificar:**
    * `src/Application/DTO/SuggestedTechnicianDto.php` *(crear)*
    * `src/Application/Service/TechnicianAssignmentSuggester.php` *(crear)*
    * `tests/unit/TechnicianAssignmentSuggesterTest.php` *(crear)*
  * **Requisitos que cubre:** `RF-04.1`, `RF-04.2`, `RF-05.1`, `RF-06.1`, `RF-06.2`, `RNF-01`, Resoluciones QA 1, QA 2, QA 3 y QA 5, `constitution.md` Art. II y Art. V.4
  * **Hecho cuando:** `TechnicianAssignmentSuggester::getSuggestionsForIncident()`: (1) pondera a los técnicos con `TechnicianWorkloadScorer`; (2) ante averías críticas/frío con paradas previas del titular, antepone obligatoriamente al técnico de guardia 24/7; (3) en zonas desatendidas aplica ordenación secundaria por distancia Haversine (`GeoDistanceService`) respecto a la parada actual, última resuelta o Base Central; (4) desempata deterministamente por mayor tiempo desde la última asignación formal (`assigned_at ASC`); y `php tests/unit/TechnicianAssignmentSuggesterTest.php` pasa al 100% en verde certificando todos los caminos lógicos.

---

## Fase 5: Controladores REST, Router y Suites de Integración HTTP

- [ ] **T-ZONE-09 · Implementar controlador `CoordinatorZoneController`, registrar rutas en `AppRouter` y crear suite `CoordinatorZoneApiTest.php`**
  * **Tiempo estimado:** 25 min
  * **Dependencias:** `T-ZONE-07`
  * **Ficheros a crear o modificar:**
    * `src/Presentation/Controller/CoordinatorZoneController.php` *(crear)*
    * `src/Presentation/Routing/AppRouter.php` *(modificar)*
    * `tests/integration/CoordinatorZoneApiTest.php` *(crear)*
  * **Requisitos que cubre:** `RF-01.1`, `RF-01.2`, `RF-01.3`, `RF-02.1`, `RF-03.2`, `RNF-04`
  * **Hecho cuando:** Están registradas y protegidas con `InternalAuthMiddleware(COORDINATOR)` las rutas `GET /api/coordinator/zones`, `POST /api/coordinator/zones`, `GET /api/coordinator/zones/{id}`, `PUT /api/coordinator/zones/{id}`, `POST /api/coordinator/zones/{id}/postal-codes`, `POST /api/coordinator/zones/{id}/technicians`, `DELETE /api/coordinator/zones/{id}/technicians/{tech_id}` y `POST /api/coordinator/locations/{id}/override-zone`; y `php tests/integration/CoordinatorZoneApiTest.php` pasa al 100% en verde verificando todos los códigos HTTP (`200`, `201`, `400`, `404`, `409`).

- [ ] **T-ZONE-10 · Implementar endpoint de sugerencias, validación constitucional $\ge 20$ chars en `/assign` y suites de integración**
  * **Tiempo estimado:** 30 min
  * **Dependencias:** `T-ZONE-08`, `T-ZONE-09`
  * **Ficheros a crear o modificar:**
    * `src/Presentation/Controller/CoordinatorController.php` *(modificar)*
    * `src/Presentation/Controller/LocationPortalController.php` *(modificar)*
    * `tests/integration/SuggestedTechniciansApiTest.php` *(crear)*
    * `tests/integration/ColdChainOverrideJustificationTest.php` *(crear)*
  * **Requisitos que cubre:** `RF-04.1`, `RF-05.1`, `RF-05.2`, `RF-07.1`, `RNF-01`, Resolución QA 1, `constitution.md` Art. II, Art. V.1 y Art. V.4
  * **Hecho cuando:** (1) `GET /api/coordinator/incidents/{id}/suggested-technicians` devuelve el ranking desglosado en $< 100\text{ ms}$; (2) `POST /api/coordinator/incidents/{id}/assign` rechaza con `422 COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED` si se descarta al técnico de guardia sugerido ante riesgo de frío con justificación $< 20$ caracteres, y acepta con $\ge 20$ caracteres registrando `INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN` en `audit_log`; (3) `LocationPortalController` sanitiza y oculta cualquier campo de zona ante la sede (Art. V.4); y `php tests/integration/SuggestedTechniciansApiTest.php` y `php tests/integration/ColdChainOverrideJustificationTest.php` pasan al 100% en verde.

---

## Fase 6: Interfaz Frontend Vanilla ES Modules y Pruebas ESM

- [ ] **T-ZONE-11 · Implementar cliente `api.js` y componente de administración territorial `OperationalZoneManagementTab.js`**
  * **Tiempo estimado:** 30 min
  * **Dependencias:** `T-ZONE-09`
  * **Ficheros a crear o modificar:**
    * `public/assets/js/api.js` *(modificar)*
    * `public/assets/js/components/OperationalZoneManagementTab.js` *(crear)*
    * `public/assets/js/views/CoordinatorDashboardView.js` *(modificar)*
  * **Requisitos que cubre:** `RF-01.1`, `RF-01.3`, `RF-02.1`, `RF-03.2`, `DoD-01`, `DoD-02`, `DoD-04`, `docs/design.md`
  * **Hecho cuando:** `OperationalZoneManagementTab.js` se monta en la vista de Coordinación permitiendo listar zonas en tarjetas con sus tokens de color Docker (`#2560ff`, etc.), añadir/editar zonas, gestionar badges de códigos postales, asignar técnicos titulares/suplentes y presentar el modal de confirmación en bloque ante traslados de códigos postales que afectan a sedes.

- [ ] **T-ZONE-12 · Implementar modal de asignación asistida `AssignTechnicianModal.js`, filtro territorial en Módulo M4 y suite `OperationalZoneManagementTabTest.mjs`**
  * **Tiempo estimado:** 25 min
  * **Dependencias:** `T-ZONE-10`, `T-ZONE-11`
  * **Ficheros a crear o modificar:**
    * `public/assets/js/components/AssignTechnicianModal.js` *(crear)*
    * `public/assets/js/components/TerritorialMapTab.js` *(modificar)*
    * `public/assets/js/views/CoordinatorDashboardView.js` *(modificar)*
    * `tests/unit/OperationalZoneManagementTabTest.mjs` *(crear)*
  * **Requisitos que cubre:** `RF-04.1`, `RF-04.2`, `RF-05.1`, `RF-05.2`, `RF-06.1`, `RNF-02`, Resoluciones QA 1 y QA 5, `docs/design.md`
  * **Hecho cuando:** `AssignTechnicianModal.js` permite despachar en $\le 2$ toques al técnico recomendado mediante el botón `[⚡ Asignar Sugerido]`; despliega el banner de emergencia sanitaria ante riesgo de frío; muestra dinámicamente el campo obligatorio de justificación $\ge 20$ caracteres si el usuario selecciona a otro técnico no sugerido; `TerritorialMapTab.js` añade el filtro por zona operativa coloreando los centros en el mapa; y `node tests/unit/OperationalZoneManagementTabTest.mjs` pasa al 100% en verde con 0 fallos.

---

## Fase 7: Certificación Integral, Regresión Global y Cierre Documental

- [ ] **T-ZONE-13 · Ejecutar batería global de regresión (`php tests/run_all.php`), sincronizar métricas documentales (`DocMetricsGuard`) y cerrar actas del Módulo 17**
  * **Tiempo estimado:** 20 min
  * **Dependencias:** `T-ZONE-01` a `T-ZONE-12`
  * **Ficheros a crear o modificar:**
    * `README.md` *(modificar)*
    * `CHANGELOG.md` *(modificar)*
    * `docs/features_pendientes.md` *(modificar)*
    * `specs/17-operational-zones/spec.md` *(modificar estado a Cerrada)*
    * `specs/functional/operational_zones_spec.md` *(modificar estado a Cerrada)*
    * `specs/17-operational-zones/tasks.md` *(marcar acta final)*
  * **Requisitos que cubre:** Todos los requisitos `RF-01` a `RF-07`, `RNF-01` a `RNF-04`, Resoluciones QA 1 a 6 y Artículos I al VII de `constitution.md`
  * **Hecho cuando:** `php tests/run_all.php` finaliza con **0 fallos (100% PASS)** en todas las suites PHP y ESM (incluyendo `PostgresZoneSchemaTest`, `CoordinatorZoneApiTest`, `SuggestedTechniciansApiTest`, `ColdChainOverrideJustificationTest` y `DocMetricsGuardTest`), la base de datos queda restablecida a semillas y la documentación viva refleja las cifras exactas del proyecto.
