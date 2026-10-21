# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO 17: ZONIFICACIÓN OPERATIVA Y ASIGNACIÓN ASISTIDA (TASKS.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `17-operational-zones`  
**Documento:** `specs/17-operational-zones/tasks.md`  
**Referencia Funcional:** [`specs/17-operational-zones/spec.md`](spec.md) y [`specs/functional/operational_zones_spec.md`](../functional/operational_zones_spec.md) (`RF-01` a `RF-07`, `RNF-01` a `RNF-04`, Resoluciones QA 1 a 12)  
**Plan Técnico:** [`specs/17-operational-zones/plan.md`](plan.md)  
**Contratos Técnicos:** [`specs/technical/operational_zones_contracts.md`](../technical/operational_zones_contracts.md)  
**Normativa Suprema:** [`constitution.md`](../../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [`AGENTS.md`](../../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)  
**Sistema de Diseño Visual:** [`docs/design.md`](../../docs/design.md) (Tokens Docker: Azul `#2560ff`, ámbar `#fef9c3`/`#854d0e`, rojo crítico `#dc2626`, canvas `#f9fafb`, radio `4px`/`8px`)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Esquema de Base de Datos PostgreSQL / Supabase

- [x] **T-ZONE-01 · Crear migración incremental `007_operational_zones.sql` y converger `supabase_init.sql` y `supabase_seeds.sql`**
  * **Tiempo estimado:** 25 min
  * **Dependencias:** Ninguna
  * **Ficheros a crear o modificar:**
    * `database/supabase_migrations/007_operational_zones.sql` *(crear)*
    * `database/supabase_init.sql` *(modificar)*
    * `database/supabase_seeds.sql` *(modificar)*
    * `src/Core/Domain/Model/AuditEvent.php` *(modificar: `ENTITY_TYPES` con `OPERATIONAL_ZONE` y `TECHNICIAN_ZONE`)*
    * `specs/technical/schema_correspondence_contract.md` *(modificar)*
  * **Requisitos que cubre:** `RF-01.1`, `RF-01.2`, `RF-02.1`, `RF-03.1`, `RF-03.2`, Resoluciones QA 4 y QA 6, `constitution.md` Art. III.1, Art. IV y Art. V.7
  * **Hecho cuando:** Existen en `007_operational_zones.sql` y en `database/supabase_init.sql`: (1) tabla `operational_zones` con restricciones `CHECK` de nombre, código y color `#RRGGBB`; (2) tabla `operational_zone_postal_codes` (`VARCHAR(5)` con `CHECK (postal_code ~ '^[0-9]{5}$')`) garantizando la unicidad **global del CP vivo** (QA 7);
  * **Enmienda 2026-10-20 (QA 13):** la unicidad del CP pasa del `PRIMARY KEY (postal_code)` al índice único parcial `uq_zone_postal_codes_live (postal_code) WHERE released_at IS NULL`, con PK sustituta `id` y liberación lógica (`released_at`); la evolución vive en `008_zone_postal_code_release.sql` y `PostgresZoneSchemaTest` certifica que liberar un CP no requiere `DELETE`. (3) columnas `zone_id`, `is_manual_override` y `postal_code` en `locations`, con `CHECK` de formato del CP e índices `idx_locations_zone_id` e `idx_locations_postal_code`; (4) tabla `technician_zone_assignments` con `removed_at`, índice parcial `uq_technician_zone_live` (`WHERE removed_at IS NULL`) y `uq_technician_primary_zone` (`WHERE is_primary = TRUE AND removed_at IS NULL`); (5) valores `OPERATIONAL_ZONE` y `TECHNICIAN_ZONE` añadidos a `audit_entity_type` con `ALTER TYPE ... ADD VALUE IF NOT EXISTS` y replicados en la declaración de `supabase_init.sql` (QA 11); (6) semillas de zonas BCN Norte/Sur/Centro con sus CPs, cuadrantes demo y CP asignado a cada sede sembrada; y `PostgresMigrationRunner` aplica la migración `007` sin errores dejando en verde `php tests/integration/PostgresSchemaTest.php` y `php tests/integration/SchemaConvergenceParityTest.php` con las listas `CONTRACT_TABLES` actualizadas a **25 tablas**.

- [x] **T-ZONE-02 · Implementar suite de integración de esquema `PostgresZoneSchemaTest.php`**
  * **Tiempo estimado:** 20 min
  * **Dependencias:** `T-ZONE-01`
  * **Ficheros a crear o modificar:**
    * `tests/integration/PostgresZoneSchemaTest.php` *(crear)*
    * `tests/integration/PostgresSchemaTest.php` *(modificar: `CONTRACT_TABLES` a 25)*
    * `tests/integration/SchemaConvergenceParityTest.php` *(modificar: `CONTRACT_TABLES` a 25)*
    * `tests/integration/PostgresConstitutionalTest.php` *(modificar: `CONTRACT_TABLES` a 25)*
  * **Requisitos que cubre:** `RF-01.2`, `RF-02.1`, `RF-03.2`, `RNF-04`, `constitution.md` Art. III.1 y Art. V.7
  * **Hecho cuando:** `php tests/integration/PostgresZoneSchemaTest.php` pasa al 100% en verde certificando contra PostgreSQL: (1) el rechazo de inserción de un código postal duplicado entre dos zonas por el índice único parcial de filas vivas, y que un CP liberado (`released_at`) sí puede asignarse a otra zona; (2) el rechazo de asignar a un mismo técnico como titular (`is_primary = TRUE`) en dos zonas distintas vía `uq_technician_primary_zone`; (3) la admisión de múltiples suplencias (`is_primary = FALSE`) para un mismo técnico; (4) que las zonas solo admiten **desactivación lógica** (el borrado físico choca con las FK `RESTRICT` y con los disparadores constitucionales) y que al desactivarlas `locations.zone_id` queda en `NULL`; (5) la validación regex de `color_hex` y `postal_code`; (6) el `CHECK` de `locations.postal_code` (admite `NULL`, rechaza cuatro dígitos o letras); (7) que tras una baja lógica (`removed_at`) el mismo técnico puede reingresar en la misma zona con una fila nueva; y (8) que el ENUM `audit_entity_type` incluye las dos entidades nuevas.

---

## Fase 2: Modelos de Dominio, Value Objects, Excepciones y Políticas Puras

- [x] **T-ZONE-03 · Implementar entidad `OperationalZone`, Value Objects (`ZoneAffinityType`, `PostalCode`), Excepciones de Dominio y test unitario**
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
  * **Hecho cuando:** `PostalCode` normaliza con ceros a la izquierda y rechaza cadenas no conformes con `^\d{5}$` (mismo Value Object que valida el CP de sede en el alta y la edición), `ZoneAffinityType` define `PRIMARY`, `BACKUP`, `NONE` con sus etiquetas en español, las 5 excepciones tipadas exponen sus códigos y estados HTTP (`400`, `404`, `409`, `422`), la entidad inmutable `OperationalZone` serializa e hidrata sus datos vía `fromDatabaseRow()` y `toArray()`, y `php tests/unit/PostalCodeValidationTest.php` pasa al 100% en verde con 0 fallos.

- [x] **T-ZONE-04 · Implementar política pura de dominio `TechnicianWorkloadScorer` y su suite unitaria**
  * **Tiempo estimado:** 25 min
  * **Dependencias:** `T-ZONE-03`
  * **Ficheros a crear o modificar:**
    * `src/Core/Service/TechnicianWorkloadScorer.php` *(crear)*
    * `tests/unit/TechnicianWorkloadScorerTest.php` *(crear)*
  * **Requisitos que cubre:** `RF-04.1`, `RF-05.1`, `RNF-01`, Resoluciones QA 1, QA 2 y QA 3
  * **Hecho cuando:** `TechnicianWorkloadScorer::computeScore()` es una función pura sin efectos secundarios que calcula exactamente: titularidad (+50), suplencia (+25), presencia in situ en la misma sede (+40), paradas en la misma zona (+20), penalización por parada en curso en otra sede (-15), carga viva activa (-5 por avería o preventivo hoy), pausa por falta de acceso `PENDING_INFO` (-2) y escalado sanitario de guardia (+100 en frío con paradas previas del titular); y `php tests/unit/TechnicianWorkloadScorerTest.php` pasa al 100% en verde evaluando todos los casos y combinaciones de la matriz.

---

## Fase 3: Capa de Infraestructura y Repositorio PDO

- [x] **T-ZONE-05 · Crear `OperationalZoneRepositoryInterface`, `PdoOperationalZoneRepository` y extender `PdoLocationRepository`**
  * **Tiempo estimado:** 30 min
  * **Dependencias:** `T-ZONE-03`
  * **Ficheros a crear o modificar:**
    * `src/Core/Domain/Repository/OperationalZoneRepositoryInterface.php` *(crear)*
    * `src/Infrastructure/Repository/PdoOperationalZoneRepository.php` *(crear)*
    * `src/Infrastructure/Repository/PdoLocationRepository.php` *(modificar: `zone_id`, `is_manual_override` y `postal_code`)*
    * `src/Application/Service/AdminLocationService.php` *(modificar: CP obligatorio en alta/edición)*
    * `tests/integration/OperationalZoneRepositoryTest.php` *(crear: certificación contra PostgreSQL)*
  * **Requisitos que cubre:** `RF-01.1`, `RF-02.1`, `RF-02.2`, `RF-03.1`, `RF-03.2`, `RNF-01`, `constitution.md` Art. IV
  * **Hecho cuando:** `PdoOperationalZoneRepository` implementa las consultas de listado con conteos agregados de sedes, técnicos y códigos postales en un solo round-trip mediante CTE/subqueries; permite vincular técnicos como titular o suplente; `PdoLocationRepository::setManualOverride()` conmuta `is_manual_override` y `zone_id` de forma atómica; `PdoLocationRepository::setPostalCode()` persiste el CP validado y recalcula la zona heredada cuando `is_manual_override = FALSE`; `AdminLocationService` exige el CP en el alta y la edición (QA 7); y `PdoLocationRepository::findInheritedZoneByPostalCode()` resuelve la zona correspondiente en $< 5\text{ ms}$.

- [x] **T-ZONE-06 · Implementar migración transaccional de códigos postales con registro de auditoría dual matriz-sede (Art. III / QA 6)**
  * **Tiempo estimado:** 25 min
  * **Dependencias:** `T-ZONE-05`
  * **Ficheros a crear o modificar:**
    * `src/Infrastructure/Repository/PdoOperationalZoneRepository.php` *(modificar)*
    * `src/Core/Domain/Repository/OperationalZoneRepositoryInterface.php` *(modificar: `transferPostalCodes` y `releasePostalCodes`)*
    * `tests/integration/ZonePostalCodeTransferTest.php` *(crear: certificación transaccional contra PostgreSQL)*
  * **Requisitos que cubre:** `RF-01.2`, `RF-03.3`, `RNF-03`, `RNF-04`, Resolución QA 6 y QA 13, `constitution.md` Art. III.1, Art. III.2 y Art. V.7
  * **Hecho cuando:** El método `transferPostalCodes(int $newZoneId, array $postalCodes, int $userId)` **mueve** las filas del CP (`UPDATE operational_zone_postal_codes SET zone_id = :new`), nunca las duplica, y ejecuta dentro de una transacción PDO: (1) la detección y bloqueo `FOR UPDATE` de las sedes que heredan dichos CPs con `is_manual_override = FALSE`; (2) la actualización atómica de `operational_zone_postal_codes`; (3) la actualización en bloque de `locations.zone_id`; (4) la inserción del evento matriz `ZONE_POSTAL_CODE_TRANSFERRED` (entidad `OPERATIONAL_ZONE`) en `audit_log`; y (5) la inserción de los eventos individuales `LOCATION_ZONE_REASSIGNED` por cada sede afectada, haciendo rollback completo si ocurre cualquier fallo.

---

## Fase 4: Servicios de Aplicación y Casos de Uso

- [x] **T-ZONE-07 · Implementar servicio de aplicación `OperationalZoneService` y DTOs**
  * **Tiempo estimado:** 25 min
  * **Dependencias:** `T-ZONE-05`, `T-ZONE-06`
  * **Ficheros a crear o modificar:**
    * `src/Application/DTO/OperationalZoneDto.php` *(crear)*
    * `src/Application/DTO/PostalCodeTransferResultDto.php` *(crear)*
    * `src/Application/Service/OperationalZoneService.php` *(crear)*
    * `src/Core/Domain/Repository/LocationZoneRepositoryInterface.php` *(crear: puerto segregado de la capacidad territorial de la sede, sin ampliar `LocationRepositoryInterface` ni romper sus veinte dobles de prueba)*
    * `src/Core/Domain/Repository/OperationalZoneRepositoryInterface.php` *(modificar: `findWithCounts`, `findPostalCodeConflicts`, `assignPostalCodes`, `inspectReleaseImpact`, `deactivateZone`; `userId` opcional en alta, edición, vinculación y baja del cuadrante, con degradación de la titularidad previa)*
    * `src/Infrastructure/Repository/PdoOperationalZoneRepository.php` *(modificar: implementación transaccional y auditoría `ZONE_CREATED`, `ZONE_UPDATED`, `ZONE_DEACTIVATED`, `TECHNICIAN_ZONE_ASSIGNED`, `TECHNICIAN_ZONE_REMOVED`, `TECHNICIAN_ZONE_PRIMARY_DEMOTED`)*
    * `src/Infrastructure/Repository/PdoLocationRepository.php` *(modificar: `LOCATION_ZONE_MANUAL_OVERRIDE` al forzar o restablecer la herencia)*
    * `tests/integration/OperationalZoneServiceTest.php` *(crear: certificación del caso de uso contra PostgreSQL)*
  * **Requisitos que cubre:** `RF-01.1`, `RF-01.3`, `RF-02.1`, `RF-02.2`, `RF-03.2`, Resoluciones QA 4 y QA 6
  * **Hecho cuando:** `OperationalZoneService` gestiona el ciclo de vida de zonas (alta, edición, desactivación), detecta si hay códigos postales en conflicto para requerir confirmación previa (`PostalCodeConflictException`), coordina el traslado atómico cuando `confirm_transfers = true`, valida que un técnico no tenga dos titularidades vivas (`PrimaryTechnicianConflictException`), degrada la titularidad anterior con `TECHNICIAN_ZONE_PRIMARY_DEMOTED` al confirmar `confirm_primary_override`, exige `confirm_release = true` para desactivar una zona con CPs o cuadrante y, en esa misma transacción, deja las sedes en `zone_id = NULL`, libera los CPs marcándolos con `released_at` (QA 13) y marca `removed_at` en las vinculaciones (QA 7 / QA 9 / QA 10), y gestiona las excepciones manuales de sede.
  * **Verificación:** `source deploy/local/postgres/.env.local && php tests/integration/OperationalZoneServiceTest.php` → **40/40**, exit 0.

- [x] **T-ZONE-08 · Implementar caso de uso `TechnicianAssignmentSuggester` con desempates deterministas y fallback Haversine sin GPS**
  * **Tiempo estimado:** 30 min
  * **Dependencias:** `T-ZONE-04`, `T-ZONE-05`
  * **Ficheros a crear o modificar:**
    * `src/Application/DTO/SuggestedTechnicianDto.php` *(crear: forma exacta del contrato §3.6, con identidad contable recomendación ↔ primer puesto)*
    * `src/Application/Service/TechnicianAssignmentSuggester.php` *(crear)*
    * `tests/unit/TechnicianAssignmentSuggesterTest.php` *(crear)*
  * **Requisitos que cubre:** `RF-04.1`, `RF-04.2`, `RF-05.1`, `RF-06.1`, `RF-06.2`, `RNF-01`, Resoluciones QA 1, QA 2, QA 3 y QA 5, `constitution.md` Art. II y Art. V.4
  * **Hecho cuando:** `TechnicianAssignmentSuggester::getSuggestionsForIncident()`: (1) pondera a los técnicos con `TechnicianWorkloadScorer`; (2) ante averías críticas/frío con paradas previas del titular, antepone obligatoriamente al técnico de guardia 24/7; (3) en zonas desatendidas aplica ordenación secundaria por distancia Haversine (`GeoDistanceService`) respecto a la parada actual, última resuelta o Base Central; (4) desempata deterministamente por mayor tiempo desde la última asignación formal (`assigned_at ASC`); y `php tests/unit/TechnicianAssignmentSuggesterTest.php` pasa al 100% en verde certificando todos los caminos lógicos.
  * **Verificación:** `php tests/unit/TechnicianAssignmentSuggesterTest.php` → **28/28**, exit 0. La batería global queda en 305 suites · 11.827 aserciones (frase canónica sincronizada en README y en los cuatro informes vivos).

---

## Fase 5: Controladores REST, Router y Suites de Integración HTTP

- [x] **T-ZONE-09 · Implementar controlador `CoordinatorZoneController`, registrar rutas en `AppRouter` y crear suite `CoordinatorZoneApiTest.php`**
  * **Tiempo estimado:** 25 min
  * **Dependencias:** `T-ZONE-07`
  * **Ficheros a crear o modificar:**
    * `src/Presentation/Controller/CoordinatorZoneController.php` *(crear)*
    * `src/Presentation/Routing/AppRouter.php` *(modificar)*
    * `tests/integration/CoordinatorZoneApiTest.php` *(crear)*
  * **Requisitos que cubre:** `RF-01.1`, `RF-01.2`, `RF-01.3`, `RF-02.1`, `RF-03.2`, `RNF-04`
  * **Hecho cuando:** Están registradas y protegidas con `InternalAuthMiddleware(COORDINATOR)` las rutas `GET /api/coordinator/zones`, `POST /api/coordinator/zones`, `GET /api/coordinator/zones/{id}`, `PUT /api/coordinator/zones/{id}`, `POST /api/coordinator/zones/{id}/postal-codes`, `POST /api/coordinator/zones/{id}/technicians`, `DELETE /api/coordinator/zones/{id}/technicians/{tech_id}` y `POST /api/coordinator/locations/{id}/override-zone`; y `php tests/integration/CoordinatorZoneApiTest.php` pasa al 100% en verde verificando todos los códigos HTTP (`200`, `201`, `400`, `404`, `409`).
  * **Verificación:** `source deploy/local/postgres/.env.local && php tests/integration/CoordinatorZoneApiTest.php` → **37/37**, exit 0 (incluye `401`, `403`, `201`, `409` de código/nombre duplicado y `422` de liberación). La batería global sigue en 305 suites · 11.827 aserciones (la suite declara categoría `new_engine` y no altera las cifras herméticas).

- [x] **T-ZONE-10 · Implementar endpoint de sugerencias, validación constitucional $\ge 20$ chars en `/assign` y suites de integración**
  * **Tiempo estimado:** 30 min
  * **Dependencias:** `T-ZONE-08`, `T-ZONE-09`
  * **Ficheros a crear o modificar:**
    * `src/Presentation/Controller/CoordinatorController.php` *(modificar: `getSuggestedTechnicians`, `assignTechnician` en `PATCH` con `override_justification`)*
    * `src/Presentation/Routing/AppRouter.php` *(modificar: ruta `GET /api/coordinator/incidents/{id}/suggested-technicians` con `InternalAuthMiddleware(COORDINATOR)`)*
    * `src/Presentation/Controller/LocationPortalController.php` *(modificar: ocultación de zona, excepción manual y afinidad en `sanitizeIncidentForSite`)*
    * `src/Core/Domain/Repository/TechnicianSuggestionRepositoryInterface.php` *(crear: puerto de lectura de la foto operativa; no amplía los puertos de usuario ni de incidencia, cuyos dobles de prueba se mantienen intactos)*
    * `src/Infrastructure/Repository/PdoTechnicianSuggestionRepository.php` *(crear: cinco consultas fijas, independientes del número de técnicos)*
    * `src/Application/Service/SuggestedTechniciansService.php` *(crear: composición de foto + motor; única fuente del ranking para el panel y para la salvaguarda)*
    * `src/Core/Service/ColdChainOverridePolicy.php` *(crear: regla pura del Art. V.1 y umbral de 20 caracteres reales)*
    * `specs/technical/api_contracts.md` *(modificar: apartado 4.2.1, campo `override_justification` y códigos de error del módulo)*
    * `tests/integration/SuggestedTechniciansApiTest.php` *(crear)*
    * `tests/integration/ColdChainOverrideJustificationTest.php` *(crear)*
    * `tests/integration/SiteZonePrivacyTest.php` *(crear: certifica el Art. V.4 en la superficie de sede)*
  * **Requisitos que cubre:** `RF-04.1`, `RF-05.1`, `RF-05.2`, `RF-07.1`, `RNF-01`, Resolución QA 1, `constitution.md` Art. II, Art. V.1 y Art. V.4
  * **Hecho cuando:** (1) `GET /api/coordinator/incidents/{id}/suggested-technicians` devuelve el ranking desglosado con mediana $< 100\text{ ms}$ sobre 20 repeticiones; (2) `PATCH /api/coordinator/incidents/{id}/assign` rechaza con `422 COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED` si se descarta al técnico de guardia sugerido ante riesgo de frío con `override_justification` de menos de 20 caracteres, y acepta con $\ge 20$ caracteres registrando `INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN` en `audit_log`, sin romper el `reassignment_reason` que ya envía el cliente; (3) `LocationPortalController` sanitiza y oculta cualquier campo de zona ante la sede (Art. V.4); y `php tests/integration/SuggestedTechniciansApiTest.php` y `php tests/integration/ColdChainOverrideJustificationTest.php` pasan al 100% en verde.
  * **Verificación ejecutada (2026-10-20):** `php tests/integration/SuggestedTechniciansApiTest.php` → **23/23**, exit 0, con la mediana de 20 repeticiones reales **11,24 ms** (mín. 9,06 · máx. 14,45) y orden idéntico en las veinte; `php tests/integration/ColdChainOverrideJustificationTest.php` → **15/15**, exit 0; `php tests/integration/SiteZonePrivacyTest.php` → **8/8**, exit 0. Las tres suites declaran categoría `new_engine` y no alteran las cifras herméticas de la batería.

---

## Fase 6: Interfaz Frontend Vanilla ES Modules y Pruebas ESM

- [x] **T-ZONE-11 · Implementar cliente `api.js` y componente de administración territorial `OperationalZoneManagementTab.js`**
  * **Tiempo estimado:** 30 min
  * **Dependencias:** `T-ZONE-09`
  * **Ficheros a crear o modificar:**
    * `public/assets/js/api.js` *(modificar)*
    * `public/assets/js/components/OperationalZoneManagementTab.js` *(crear)*
    * `public/assets/js/components/AdminLocationsTab.js` *(modificar: campo obligatorio de CP de sede, QA 7)*
    * `public/assets/js/utils/AuditActionLabels.js` *(modificar: 11 acciones y 2 entidades nuevas, QA 11)*
    * `public/assets/js/views/CoordinatorDashboardView.js` *(modificar)*
  * **Requisitos que cubre:** `RF-01.1`, `RF-01.3`, `RF-02.1`, `RF-03.2`, `DoD-01`, `DoD-02`, `DoD-04`, `docs/design.md`
  * **Hecho cuando:** `OperationalZoneManagementTab.js` se monta en la vista de Coordinación permitiendo listar zonas en tarjetas con sus tokens de color Docker (`#2560ff`, etc.), añadir/editar zonas, gestionar badges de códigos postales, asignar técnicos titulares/suplentes y presentar el modal de confirmación en bloque ante traslados de códigos postales que afectan a sedes; `AdminLocationsTab.js` exige el CP en el alta y la edición de sede; y `AuditActionLabels.js` cataloga todas las acciones y entidades nuevas sin dejar ninguna sin etiqueta ni filtro.
  * **Verificación ejecutada (2026-10-20):** `OperationalZoneManagementTab` se registra como cuarta subpestaña de Administración (`subtab-zones`) y `dashboard` + `zone tab` compilan sin errores con el compilador de plantillas de Vue 3; `node tests/unit/AdminLocationsTabTest.mjs` → **86/86** (incluye CP obligatorio en alta, edición y plantilla); `node tests/unit/AdminTabsTest.mjs` → **25/25**; `node tests/unit/DesignTokenDebtRatchetTest.mjs` → **3/3** con el techo rebajado a **2.269** literales y el acta §6 sincronizada; `php tests/unit/AuditActionCatalogTest.php` → **26/26**, con la guarda ampliada para enumerar las escrituras directas `new AuditEvent(...)` del repositorio territorial; y las **70 suites ESM** de `tests/unit/*.mjs` pasan con 0 fallos.

- [x] **T-ZONE-12 · Ampliar el panel de asignación existente, filtro territorial en Módulo M4 y suite `OperationalZoneManagementTabTest.mjs`**
  * **Tiempo estimado:** 25 min
  * **Dependencias:** `T-ZONE-10`, `T-ZONE-11`
  * **Ficheros a crear o modificar:**
    * `public/assets/js/views/CoordinatorDashboardView.js` *(modificar: panel de asignación existente con sugerido, badges y `override_justification`)*
    * `public/assets/js/components/CoordinatorTerritorialMapTab.js` *(modificar)*
    * `tests/unit/OperationalZoneManagementTabTest.mjs` *(crear)*
    * `tests/unit/CoordinatorDashboardAssignPanelTest.mjs` *(crear)*
  * **Requisitos que cubre:** `RF-04.1`, `RF-04.2`, `RF-05.1`, `RF-05.2`, `RF-06.1`, `RNF-02`, Resoluciones QA 1 y QA 5, `docs/design.md`
  * **Hecho cuando:** el panel de asignación de `CoordinatorDashboardView` permite despachar en $\le 2$ toques al técnico recomendado mediante el botón `[⚡ Asignar Sugerido]`; despliega el banner de emergencia sanitaria ante riesgo de frío; muestra dinámicamente el campo obligatorio `override_justification` ($\ge 20$ caracteres) si el usuario selecciona a otro técnico no sugerido, manteniendo el `reassignment_reason` existente; `CoordinatorTerritorialMapTab.js` añade el filtro por zona operativa coloreando los centros en el mapa; y `node tests/unit/OperationalZoneManagementTabTest.mjs` y `node tests/unit/CoordinatorDashboardAssignPanelTest.mjs` pasan al 100% en verde con 0 fallos.
  * **Verificación ejecutada (2026-10-20):** `node tests/unit/OperationalZoneManagementTabTest.mjs` → **43/43**, exit 0; `node tests/unit/CoordinatorDashboardAssignPanelTest.mjs` → **32/32**, exit 0 (incluye 19/20 caracteres reales, emojis, 422 constitucional y despacho en dos interacciones); `node tests/unit/CoordinatorTerritorialMapTabTest.mjs` → **101/101** con el bloque nuevo de filtro por zona; las **72 suites ESM** de `tests/unit/*.mjs` pasan con 0 fallos; el trinquete de tokens sigue en **2.269** (los centros se tiñen con el `color_hex` del dato, no con literales) y las plantillas del dashboard y del mapa compilan con el compilador de Vue 3. El filtro territorial es local porque `route_map_contracts.md` §4.2.1 prohíbe añadir parámetros o campos al endpoint del mapa.

---

## Fase 7: Certificación Integral, Regresión Global y Cierre Documental

- [x] **T-ZONE-13 · Ejecutar batería global de regresión (`php tests/run_all.php`), sincronizar métricas documentales (`DocMetricsGuard`) y cerrar actas del Módulo 17**
  * **Tiempo estimado:** 20 min
  * **Dependencias:** `T-ZONE-01` a `T-ZONE-12`
  * **Ficheros a crear o modificar:**
    * `README.md` *(modificar)*
    * `CHANGELOG.md` *(modificar)*
    * `docs/features_pendientes.md` *(modificar)*
    * `specs/17-operational-zones/spec.md` *(modificar estado a Cerrada)*
    * `specs/functional/operational_zones_spec.md` *(modificar estado a Cerrada)*
    * `specs/17-operational-zones/tasks.md` *(marcar acta final)*
  * **Requisitos que cubre:** Todos los requisitos `RF-01` a `RF-07`, `RNF-01` a `RNF-04`, Resoluciones QA 1 a 12 y Artículos I al VII de `constitution.md`
  * **Hecho cuando:** `php tests/run_all.php` finaliza con **0 fallos (100% PASS)** en todas las suites PHP y ESM (incluyendo `PostgresZoneSchemaTest`, `CoordinatorZoneApiTest`, `SuggestedTechniciansApiTest`, `ColdChainOverrideJustificationTest`, `AuditActionCatalogTest`, `AuditEntityTypesTest` y `DocMetricsGuard`), `ApiContractDriftGuardTest` sigue en verde con la sección del módulo en `api_contracts.md`, las tres listas `CONTRACT_TABLES` declaran 25 tablas, la base de datos queda restablecida a semillas y la documentación viva refleja las cifras exactas del proyecto.
  * **Verificación ejecutada (2026-10-20):** `php tests/run_all.php` → **307 suites · 11.985 aserciones · 0 fallos · exit 0** (133 unitarias PHP, 72 unitarias ESM, 101 de integración y 1 guion E2E) en 299,21 s de la corrida final tras la enmienda de la Fase 8, con la base del arnés restablecida a semillas y las **cifras documentadas COHERENTES**. Las catorce suites del canal `new_engine` se ejecutaron una a una por su canal contra PostgreSQL: trece en verde (entre ellas `PostgresZoneSchemaTest`, `OperationalZoneRepositoryTest`, `OperationalZoneServiceTest`, `ZonePostalCodeTransferTest`, `CoordinatorZoneApiTest`, `SuggestedTechniciansApiTest`, `ColdChainOverrideJustificationTest`, `SiteZonePrivacyTest`, `PostgresSchemaTest` y `SchemaConvergenceParityTest`), y `PoolerLatencyMeasurementTest` sale con código 2 sin ejecutar por falta de `DB_SSL_ROOT_CERT` —la identidad TLS del motor remoto no se puede verificar en local—, condición de entorno preexistente que no altera la corrida hermética. La frase canónica `batería global: 307 suites · 11.985 aserciones` se sincronizó en el README (insignia, línea canónica, resumen de ejecución y árbol de directorios) y en los cuatro informes vivos; `CHANGELOG.md` registra el cierre del módulo, `docs/features_pendientes.md` retira la zonificación del backlog y ambas especificaciones (canónica y espejo) declaran la implementación certificada.

---

## Fase 8: Enmienda de auditoría (huecos G1–G4)

La auditoría requisito por requisito posterior al cierre detectó tres requisitos aprobados sin implementar en la interfaz y una cláusula sin magnitud. Esta fase los enmienda **sin reabrir lo certificado**: no toca esquema, repositorios ni API salvo la semántica de `unattended_zone`.

- [x] **T-ZONE-14 · Cerrar los huecos G1 (insignia «Sin Zona Asignada»), G2 (anulación manual desde el panel de sedes), G3 (cupo de paradas vivas, QA 14) y G4 (preservación de averías sin aserción)**
  * **Tiempo estimado:** 30 min
  * **Dependencias:** `T-ZONE-13`
  * **Ficheros a crear o modificar:**
    * `specs/17-operational-zones/spec.md` *(modificar: RF-06.1 y Resolución QA 14)*
    * `specs/functional/operational_zones_spec.md` *(modificar: espejo)*
    * `specs/technical/operational_zones_contracts.md` *(modificar: semántica de `unattended_zone`)*
    * `specs/17-operational-zones/plan.md` *(modificar: criterio de ordenación de zona desatendida)*
    * `src/Application/Service/TechnicianAssignmentSuggester.php` *(modificar: `MAX_LIVE_STOPS_PER_TECHNICIAN`)*
    * `public/assets/js/components/AdminLocationsTab.js` *(modificar: columna de zona, insignia e idempotencia del override)*
    * `tests/unit/TechnicianAssignmentSuggesterTest.php` *(modificar: 7 aserciones del cupo)*
    * `tests/unit/AdminLocationsTabTest.mjs` *(modificar: 14 aserciones territoriales)*
    * `tests/integration/ZonePostalCodeTransferTest.php` *(modificar: la avería no se reasigna)*
    * `tests/integration/OperationalZoneServiceTest.php` *(modificar: casos límite 3 y 4)*
  * **Requisitos que cubre:** `RF-03.2`, `RF-03.3`, `RF-03.4`, `RF-06.1`, `RF-06.3`, `DoD-03`, `DoD-07`, `DoD-10`, Resolución QA 14, casos límite 3 y 4, `constitution.md` Art. I, Art. II y Art. III
  * **Hecho cuando:** la tabla de sedes muestra la zona operativa con su color y la insignia «Sin Zona Asignada» cuando el CP no resuelve ninguna zona activa; la edición permite forzar una zona, dejarla sin zona o restablecer la herencia, y la excepción solo viaja al servidor cuando el coordinador la cambia; una zona con todo su cuadrante en diez paradas vivas se declara desatendida sin excluir a sus técnicos del ranking; y una avería asignada sobrevive intacta al traslado de su CP, a la desactivación de su zona y a la baja del técnico del cuadrante.
  * **Verificación ejecutada (2026-10-20):** `php tests/unit/TechnicianAssignmentSuggesterTest.php` → **35/35**, exit 0; `node tests/unit/AdminLocationsTabTest.mjs` → **100/100**, exit 0; `php tests/integration/ZonePostalCodeTransferTest.php` → **18/18**, exit 0; `php tests/integration/OperationalZoneServiceTest.php` → **42/42**, exit 0. Batería global sin cambios de suites (307) y con las cifras documentales sincronizadas.
---

## Fase 9: Arreglo de despliegue (Render)

El primer despliegue del módulo sobre la base que ya servía abortó en los tres reintentos del arranque. Esta fase corrige el DDL base y añade la certificación que lo vigila; no cambia modelo de datos, API ni interfaz.

- [x] **T-ZONE-15 · Converger el DDL base sobre una base anterior al Módulo 17 y certificar el arranque**
  * **Tiempo estimado:** 25 min
  * **Dependencias:** `T-ZONE-14`
  * **Ficheros a crear o modificar:**
    * `database/supabase_init.sql` *(modificar: declaración idempotente de `zone_id`, `is_manual_override` y `postal_code` junto a `locations`)*
    * `tests/integration/CloudDeploySchemaParityTest.php` *(modificar: caso 6, base anterior al Módulo 17)*
  * **Requisitos que cubre:** `RF-03.1`, `RNF-04`, `EARS-02.4`, `EARS-02.5`, `constitution.md` Art. III y Art. VII
  * **Hecho cuando:** el paso 3/6 del arranque (`php bin/init_cloud_db.php`) recorre el DDL base completo sobre una base creada antes del módulo, con `locations` sin las columnas territoriales, sin abortar por `SQLSTATE 42703`; las tres columnas, sus dos índices y `fk_locations_zone` quedan materializados en ese mismo paso, antes de que la capa incremental corra; y una sonda que reproduzca esa base antigua falla en rojo si la guarda desaparece del fichero.
  * **Verificación ejecutada (2026-10-20):** con la declaración idempotente retirada del DDL, `php tests/integration/CloudDeploySchemaParityTest.php` reproduce el fallo del despliegue y muere con `SQLSTATE[42703]` sobre la misma sentencia que abortó Render (exit 255); restaurada, la suite pasa **52/52** (aserciones 49 a 52 del caso 6: tabla antigua reproducida, DDL base sin abortar, columnas+índice+clave foránea repuestas antes de migrar y convergencia declarada con `007_operational_zones.sql` aplicada). En el canal `new_engine` siguen en verde `PostgresZoneSchemaTest` **31/31**, `PostgresSchemaTest` **80/80** y `SchemaConvergenceParityTest` **18/18**. `php tests/run_all.php` → **307 suites · 11.985 aserciones · 0 fallos · exit 0**, cifras documentales COHERENTES.

---

## Fase 10: Arreglo de presentación (modales territoriales)

Los cinco diálogos de la consola territorial se dibujaban sobre la cáscara `vg-modal` sin anatomía interna: la clase no declara relleno, así que el título, los controles y las acciones tocaban el borde de la tarjeta y el pie quedaba dentro del área desplazable. Esta fase los alinea con el diálogo compartido del panel; no cambia modelo de datos, API ni lógica de negocio.

- [x] **T-ZONE-16 · Servir los cinco diálogos territoriales sobre `ModalDialog` y congelar la anatomía**
  * **Tiempo estimado:** 20 min
  * **Dependencias:** `T-ZONE-15`
  * **Ficheros a crear o modificar:**
    * `public/assets/js/components/OperationalZoneManagementTab.js` *(modificar: alta, edición, liberación, códigos postales y cuadrante sobre `ModalDialog`)*
    * `tests/unit/OperationalZoneManagementTabTest.mjs` *(modificar: aserciones 1.10 y 1.11)*
  * **Requisitos que cubre:** `RNF-06`, `DoD-01`, `DoD-02`, `constitution.md` Art. VII
  * **Hecho cuando:** los cinco diálogos declaran cabecera con hairline, cuerpo de 20 px y pie separado con las acciones; ninguna plantilla de la pestaña reimplementa `vg-modal-backdrop`; el botón de envío del pie sigue enviando el formulario del cuerpo (atributo `form`, Enter incluido); y una suite que mida la geometría real certifica que el contenido no toca el borde ni el pie se desplaza con el cuerpo.
  * **Verificación ejecutada (2026-10-20):** `node tests/unit/OperationalZoneManagementTabTest.mjs` → **45/45**, exit 0 (1.10 y 1.11 nuevas: los cinco diálogos entran por `ModalDialog`, ninguno declara `vg-modal-backdrop`, los cinco tienen pie y los tres formularios se envían desde el pie). Medición en navegador real sobre la pestaña montada: `header` 16 px 20 px con borde inferior de 1 px, cuerpo 20 px, pie 12 px 20 px con borde superior de 1 px y dentro del diálogo (1 px de borde), contenido a 21 px de ambos bordes en alta (560 px), edición (560 px), liberación (420 px), códigos postales (720 px) y cuadrante (720 px). Comportamiento: el submit del pie dispara el formulario del cuerpo —el alta vacía publica sus dos errores de campo y el cuadrante sin técnico publica «Seleccione un técnico del directorio.»— y `Escape` cierra el diálogo restaurando el desplazamiento del documento. `php tests/run_all.php` → **307 suites · 11.985 aserciones · 0 fallos · exit 0**, cifras documentales COHERENTES.

---

## Fase 11: Visibilidad del cuadrante y consulta inversa (RF-02.3 / RF-02.4 / QA 15)

El cuadrante se contaba pero no se nombraba: el modal mostraba «Titulares: 1 / Suplentes: 1» y retiraba desde un selector con **todo** el directorio. Esta fase publica las vinculaciones vivas con identidad y añade la lectura inversa por técnico. No toca esquema, reglas de titularidad ni auditoría: es una ampliación de lectura.

- [x] **T-ZONE-17 · Publicar el cuadrante por zona y la consulta inversa por técnico**
  * **Tiempo estimado:** 45 min
  * **Dependencias:** `T-ZONE-16`
  * **Ficheros a crear o modificar:**
    * `src/Core/Domain/Repository/OperationalZoneRepositoryInterface.php` *(modificar: `operator_code` en el contrato de `findTechnicianAssignments` y nuevo `findQuadrantsByTechnician`)*
    * `src/Infrastructure/Repository/PdoOperationalZoneRepository.php` *(modificar: proyección con `operator_code` y agregación por técnico en una sentencia)*
    * `src/Application/DTO/ZoneTechnicianAssignmentDto.php` *(crear: fila del cuadrante)*
    * `src/Application/DTO/TechnicianQuadrantDto.php` *(crear: técnico con sus zonas vivas)*
    * `src/Application/Service/OperationalZoneService.php` *(modificar: `listZoneQuadrant` y `listTechnicianQuadrants`)*
    * `src/Presentation/Controller/CoordinatorZoneController.php` *(modificar: dos acciones de lectura)*
    * `src/Presentation/Routing/AppRouter.php` *(modificar: dos rutas GET, la estática antes de cualquier `{id}`)*
    * `tests/integration/OperationalZoneRepositoryTest.php` *(modificar: proyección, orden, técnico de baja y una sola sentencia)*
    * `tests/integration/CoordinatorZoneApiTest.php` *(modificar: forma de las dos respuestas, 404, RBAC y universo de filas)*
  * **Requisitos que cubre:** `RF-02.3`, `RF-02.4`, `RNF-01`, `RNF-04`, `constitution.md` Art. III y Art. V.4
  * **Hecho cuando:** `GET /api/coordinator/zones/{id}/technicians` devuelve las vinculaciones vivas con `assignment_id`, `technician_id`, `name`, `operator_code`, `is_primary`, `is_on_call`, `is_active` y `assigned_at`, ordenadas titular→suplente→nombre, incluyendo al técnico desactivado con vinculación viva y sin publicar `email`; una zona ya liberada responde `technicians: []` y una inexistente `404 ZONE_NOT_FOUND`; `GET /api/coordinator/technicians/quadrants` agrupa las zonas vivas por técnico, incluye a los activos sin zona con `zones: []` y no es interceptada por ninguna ruta `{id}`; cada superficie cuesta **una** sentencia, certificado con el contador del arnés.
  * **Criterio de reversión del test:** retirar `operator_code` de la proyección o el `LEFT JOIN` de la agregación pone la suite en rojo.
  * **Verificación ejecutada (2026-10-20):** en el canal `new_engine`, `OperationalZoneRepositoryTest` → **45/45**, exit 0 (proyección con `operator_code` y sin `email`, baja con vinculación viva publicada, titular antes que suplencia, técnico activo sin zona con `zones: []` y **una sola sentencia** medida con el contador del arnés) y `CoordinatorZoneApiTest` → **43/43**, exit 0 (forma de las dos respuestas, orden titular→suplente, `404 ZONE_NOT_FOUND`, `401`/`403` de Coordinación y la ruta estática sin ser interpretada como `{id}`). El escenario del cuadrante se siembra con una vinculación directa para probar la lectura de un técnico de baja, porque el alta la rechaza por diseño; la aserción de reactivación de zona se reescribió para comprobar la presencia en el filtro en lugar de un total fijo que el nuevo escenario rompía.

- [x] **T-ZONE-18 · Nombrar el cuadrante en la pestaña territorial y ofrecer la vista por técnico**
  * **Tiempo estimado:** 60 min
  * **Dependencias:** `T-ZONE-17`
  * **Ficheros a crear o modificar:**
    * `public/assets/js/api.js` *(modificar: `getZoneTechnicians` y `getTechnicianQuadrants`)*
    * `public/assets/js/components/OperationalZoneManagementTab.js` *(modificar: lista del cuadrante con retirada por fila, estado vacío y segmentado «Por zona / Por técnico»)*
    * `tests/unit/OperationalZoneManagementTabTest.mjs` *(modificar: aserciones del cuadrante legible y de la vista inversa)*
  * **Requisitos que cubre:** `RF-02.3`, `RF-02.4`, `DoD-01`, `DoD-02`, `constitution.md` Art. VII
  * **Hecho cuando:** abrir el cuadrante muestra a cada técnico con su nombre, código de operador, insignia Titular/Suplente, marca de guardia, insignia «Baja» cuando procede y fecha de alta; cada fila ofrece «Retirar» con el estado de envío propio, sin selector ciego; una zona sin cuadrante muestra un estado vacío explicativo; el segmentado «Por técnico» lista a todos los técnicos con sus zonas coloreadas y señala a los que no tienen ninguna; y el modal vuelve a pedir el cuadrante tras cada alta o baja para no mostrar contadores congelados.
  * **Verificación ejecutada (2026-10-20):** `node tests/unit/OperationalZoneManagementTabTest.mjs` → **51/51**, exit 0 (1.12 y 1.13 nuevas: el cuadrante declara lista, filas, insignia «Baja» y código de operador sin selector ciego; la vista por técnico declara sus chips y el estado «Sin zona asignada»; 6.5–6.7: la baja recarga la lista, abrir el modal trae sus filas y los contadores se derivan de ellas, y la fecha tolera marcas ilegibles sin inventarla; 8.3–8.4: la lectura inversa es perezosa y no se repite al volver). Comprobación en navegador real con la pestaña montada: el modal lista «Titular · Marta Guardia · OP-07 · En guardia · Alta: 20/10/2026 · Retirar», el suplente y la baja («ID #9» cuando no hay código de operador, «Alta: —»), contadores derivados (1 titular, 2 suplentes), sin `zone-quadrant-remove-select`; la vista «Por técnico» pinta las zonas con su color (`rgb(37, 96, 255)`), marca «Sin zona asignada» al técnico sin demarcación y oculta la rejilla de zonas.

- [x] **T-ZONE-19 · Cierre documental y cifras**
  * **Tiempo estimado:** 20 min
  * **Dependencias:** `T-ZONE-18`
  * **Ficheros a crear o modificar:**
    * `CHANGELOG.md`, `README.md`, `docs/handoff_rama_incident-comments.md`, `docs/manual_e2e_verification.md`, `docs/verificacion_criterios_finalizacion_mvp.md`, `docs/verificacion_criterios_finalizacion_preventivo.md`
    * `specs/17-operational-zones/tasks.md` *(marcar la fase)*
  * **Hecho cuando:** `php tests/run_all.php` cierra en verde con las cifras documentales COHERENTES y la frase canónica sincronizada en el README y los cuatro informes vivos.
  * **Verificación ejecutada (2026-10-20):** `php tests/run_all.php` → **307 suites · 11.985 aserciones · 0 fallos · exit 0**, cifras documentadas COHERENTES y base restaurada a semillas; la frase canónica se sincronizó en el README (insignia, línea canónica, resumen de ejecución y tabla de endpoints), `CHANGELOG.md`, los cuatro informes vivos y esta fase.

- [x] **T-ZONE-20 · Corrección de los ocho hallazgos de la verificación funcional**
  * **Tiempo estimado:** 240 min
  * **Dependencias:** `T-ZONE-19`
  * **Origen:** verificación funcional, de flujo y de humo del 2026-10-21 (ocho hallazgos, H-1 a H-8).
  * **Ficheros a crear o modificar:**
    * `src/Infrastructure/Database/SeedRunner.php` *(sembrar zonas, CP, cuadrante y el CP de cada sede; herencia territorial)*
    * `tests/Support/TestDataCleaner.php` *(raíz de purga `operational_zones` y salvaguarda de sus hijas)*
    * `src/Infrastructure/Repository/PdoOperationalZoneRepository.php`, `src/Application/Service/OperationalZoneService.php`, `src/Application/DTO/PostalCodeTransferResultDto.php` *(sustitución con liberación)*
    * `public/assets/js/components/OperationalZoneManagementTab.js`, `public/assets/js/views/CoordinatorDashboardView.js` *(chips de liberación y espejo de la política de frío)*
    * `tests/run_new_engine.php`, `deploy/local/postgres/provision-tls-engine.sh` *(canal del motor y `PG_TEST_DSN`)*
    * `README.md`, `CHANGELOG.md` y los informes vivos *(cifras)*
  * **Requisitos que cubre:** `RF-01.2`, `RF-01.4`, `RF-05.2`, `RNF-01`, `RNF-04`, Art. II, Art. III.1 y Art. V
  * **Hecho cuando:** H-1 el alta de CP sobre una zona con territorio funciona y es idempotente; H-2 tras `run_all` el catálogo territorial queda sembrado (3 zonas, 10 CP, cuadrante y sedes heredadas); H-3 la batería documentada cierra con exit 0 y cifras coherentes; H-4 el canal `new_engine` se ejecuta con un comando propio y `PG_TEST_DSN` documentado; H-5 la UI exige la justificación de frío con la misma condición que el servidor; H-6/H-7 el alta de avería de sede acepta JSON y devuelve el contexto de la máquina; H-8 el lote del mapa rotula el alcance de su sugerencia.
  * **Verificación ejecutada (2026-10-21):**
    * **H-1 · Sustitución territorial:** `OperationalZoneServiceTest` **47/47** y `CoordinatorZoneApiTest` **46/46** en verde. Reenviar la colección actual es un no-op; lo ausente se libera con `released_at` (la fila no se borra), sus sedes heredadas recalculan la zona y las forzadas a mano la conservan. `PostalCodeTransferResultDto` publica `released_postal_codes` y la consola anticipa los códigos que se liberarán (`OperationalZoneManagementTabTest.mjs` **53/53**, aserciones 5.6 y 5.7).
    * **H-2 · Catálogo territorial sembrado:** `SeedRunnerSingleEngineTest` **26/26** en verde con las aserciones 5.1 a 5.6 (3 zonas, 10 CP vivos y únicos, cuadrante de 7 vinculaciones, herencia de las diez sedes e idempotencia). `TestDataCleaner` declara `operational_zones` como raíz de purga y sus dos hijas; la suite de aislamiento certifica las **18** tablas transitorias del plan.
    * **H-3 · Batería y cifras:** `php tests/run_all.php` → **307 suites · 11.985 aserciones · 0 fallos · exit 0**, **101/101** suites de integración, base restaurada a semillas y `Cifras documentadas: COHERENTES`.
    * **H-4 · Canal del motor nuevo:** `source deploy/local/postgres/.env.local && php tests/run_new_engine.php` → **13 suites en verde · 458 aserciones · 0 fallos · exit 0**; `PoolerLatencyMeasurementTest` se declara **no ejecutable** con su motivo (falta `DB_SSL_ROOT_CERT` del motor remoto) y no se omite en silencio. Sin `PG_TEST_DSN` el comando sale con código **2**; con un filtro sin coincidencias, también. El comando quedó documentado en el README y en el contrato de `deploy/local/postgres/`, y los dos aprovisionadores lo imprimen al generar su `.env`.
    * **H-5 · Espejo de la política de frío:** `CoordinatorDashboardViewTest.mjs` **141/141**; la justificación solo se exige cuando el primer puesto del ranking es un técnico de guardia (`is_on_call === true`), igual que `ColdChainOverridePolicy`, tanto en el despacho unitario como en el lote.
    * **H-6/H-7 · Alta de avería de sede:** `CreateIncidentEndpointTest` **55/55** con el caso 7.6/7.7 nuevo contra HTTP real: `application/json` responde `201` y el cuerpo publica `machine_code`, `machine_model`, `machine_type`, `location_site_code` y `location_name` (aserción 1.10). El contrato §3.2 declara ambas formas de envío y el contexto del equipo.
    * **H-8 · Alcance de la sugerencia del lote:** `CoordinatorDashboardViewTest.mjs` aserciones 7.12/7.13: la referencia del ranking se elige por urgencia real (crítica primero) y el rótulo declara sobre qué ticket se calculó y a cuántos avisos se aplicará el técnico elegido.
