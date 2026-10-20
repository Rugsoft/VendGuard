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
  * **Requisitos que cubre:** Todos los requisitos `RF-01` a `RF-07`, `RNF-01` a `RNF-04`, Resoluciones QA 1 a 12 y Artículos I al VII de `constitution.md`
  * **Hecho cuando:** `php tests/run_all.php` finaliza con **0 fallos (100% PASS)** en todas las suites PHP y ESM (incluyendo `PostgresZoneSchemaTest`, `CoordinatorZoneApiTest`, `SuggestedTechniciansApiTest`, `ColdChainOverrideJustificationTest`, `AuditActionCatalogTest`, `AuditEntityTypesTest` y `DocMetricsGuard`), `ApiContractDriftGuardTest` sigue en verde con la sección del módulo en `api_contracts.md`, las tres listas `CONTRACT_TABLES` declaran 25 tablas, la base de datos queda restablecida a semillas y la documentación viva refleja las cifras exactas del proyecto.
