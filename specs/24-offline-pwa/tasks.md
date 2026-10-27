# PLAN DE TAREAS ATÓMICAS: MODO SIN CONEXIÓN E INSTALABILIDAD MÓVIL PARA TÉCNICOS DE CAMPO

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 24 · Modo Sin Conexión para Técnicos de Campo (*Offline PWA Mode for Field Technicians*)  
**Documento Canónico:** `specs/24-offline-pwa/tasks.md`  
**Especificación Funcional:** [`spec.md`](spec.md) (v2.0, 2026-10-27)  
**Contratos Técnicos:** [`../technical/offline_pwa_contracts.md`](../technical/offline_pwa_contracts.md)  
**Plan Arquitectónico:** [`plan.md`](plan.md)  
**Fecha:** Octubre 2026  

---

## Resumen de Fases y Tareas

| Fase | Tareas | Tiempo Estimado | Propósito |
| :--- | :--- | :--- | :--- |
| **Fase 1: Esquema de Base de Datos PostgreSQL / Supabase y Guardas de Esquema** | `T-OFFLINE-01`, `T-OFFLINE-02` | 45–50 min | Migración `020_offline_pwa_sync.sql`, convergencia en `supabase_init.sql` (Tabla 34 `offline_sync_mutations`), semillas, contrato de correspondencia, arnés de limpieza y suite de esquema PostgreSQL. |
| **Fase 2: Modelos de Dominio, Value Objects y Excepciones** | `T-OFFLINE-03`, `T-OFFLINE-04` | 45–50 min | Entidad `OfflineSyncMutation`, Value Object `OfflineSyncMetadata` (algoritmo de reloj efectivo y preservación de $\Delta t$ ante desajuste de reloj, `QA 4`), `OfflineSyncValidationException`, interfaz de repositorio y suite unitaria `OfflineSyncClockAndQuotaTest`. |
| **Fase 3: Capa de Infraestructura y Repositorio PDO** | `T-OFFLINE-05` | 30 min | `PdoOfflineSyncMutationRepository` (idempotencia por `(technician_id, client_mutation_id)`, paquete `fetchBootstrapSnapshotForTechnician()` sin N+1, excepción de auditoría `QA 2` en `incident_comments` y `preventive_orders`, y registro en `audit_log`). |
| **Fase 4: Servicio de Aplicación y Casos de Uso (`OfflineSyncService`)** | `T-OFFLINE-06`, `T-OFFLINE-07` | 50–55 min | `OfflineBootstrapSnapshotDto` y `OfflineSyncService` (ejecución idempotente, precinto sanitario obligatorio Art. II / `QA 1`, colisión parcial de reintegro `QA 2`, retención de `SANITARY_QUARANTINE` Art. II, volcado de conflictos en 1 toque `QA 1`/`QA 2` y volcado por baja de cuenta `QA 5`) + suite `OfflineSyncServiceTest`. |
| **Fase 5: Controladores REST, Router y Suites de Integración HTTP** | `T-OFFLINE-08`, `T-OFFLINE-09` | 50–55 min | `TechnicianOfflineSyncController`, enriquecimiento de `TechnicianController` y `TechnicianPreventiveController` con `OfflineSyncMetadata`, blindaje en `LocationPortalController` (Art. V.4), rutas en `AppRouter` y suite `OfflinePwaSyncApiTest`. |
| **Fase 6: PWA Nativa, Almacenamiento `IndexedDB`, Gestor de Cola e Interfaz Frontend (Vue 3 ESM)** | `T-OFFLINE-10`, `T-OFFLINE-11`, `T-OFFLINE-12`, `T-OFFLINE-13` | 90–110 min | `manifest.webmanifest`, iconos vectoriales y `sw.js`; `OfflineStorage.js` (`IndexedDB` aislado por técnico, vigencia 4h/12h `D6`, cuota 5 fotos / 25 MB sin contar firma PNG `D3`/`QA 3`); `OfflineSyncManager.js` (FIFO, aislamiento por parada vs. comentario intermedio `QA 3`, reversión de stock provisional); `OfflineSyncTrayModal.js`, integración en `TechnicianRouteView.js` y `AppNavbar.js` + suites ESM `OfflineStorageAndSyncManagerTest.mjs` y `OfflinePwaAssetsAndUiGuardTest.mjs`. |
| **Fase 7: Certificación Integral, Matriz de Trazabilidad, Regresión Global y Cierre Documental** | `T-OFFLINE-14` | 25–30 min | `specs/24-offline-pwa/traceability-matrix.md`, batería completa `php tests/run_all.php`, guardas de diseño (`DesignTokenDebtRatchetTest`) y actualización de `docs/features_pendientes.md`. |

---

## Fase 1: Esquema de Base de Datos PostgreSQL / Supabase y Guardas de Esquema

- [x] **T-OFFLINE-01 · Migración `020_offline_pwa_sync.sql`, convergencia en `supabase_init.sql` y semillas**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** Ninguna
  * **Ficheros a crear o modificar:**
    * `database/supabase_migrations/020_offline_pwa_sync.sql` **(crear)**
    * `database/supabase_init.sql` **(modificar)**
    * `database/supabase_seeds.sql` **(modificar)**
    * `src/Infrastructure/Database/SeedRunner.php` **(modificar)**
    * `specs/technical/schema_correspondence_contract.md` **(modificar)**
  * **Requisitos que cubre:** `RF-04.2`, `RF-04.3`, `RF-05.3`, `RF-06.4`, Decisiones `D1` y `D2`, Resoluciones `QA 1`, `QA 2`, `QA 4` y `QA 5`, Art. III.1 y Art. III.3 de `constitution.md`.
  * **Detalle de implementación:**
    1. Declarar en `020_offline_pwa_sync.sql` y en `database/supabase_init.sql` la Tabla 34 `offline_sync_mutations` con clave foránea `fk_offline_sync_mutations_technician` hacia `users(id) ON DELETE RESTRICT ON UPDATE CASCADE`, la restricción única `uq_offline_sync_tech_mutation (technician_id, client_mutation_id)`, y las restricciones `chk_offline_sync_mutation_id_format`, `chk_offline_sync_stop_type` (`INCIDENT`, `PREVENTIVE_ORDER`), `chk_offline_sync_operation_type` (las 9 operaciones permitidas en `D1`) y `chk_offline_sync_outcome_status` (`APPLIED`, `CONFLICT_DUMPED`, `DEACTIVATED_ACCOUNT_DUMP`).
    2. Declarar los índices `idx_offline_sync_mutations_stop (stop_type, stop_id, synced_at DESC)` e `idx_offline_sync_mutations_tech_synced (technician_id, synced_at DESC)` y el disparador constitucional `trg_no_delete_offline_sync_mutations` (`prevent_hard_delete()`, Art. III.1).
    3. Añadir semillas representativas de mutaciones sincronizadas (`APPLIED` y `CONFLICT_DUMPED`) en `database/supabase_seeds.sql` y `SeedRunner.php`, y actualizar `specs/technical/schema_correspondence_contract.md` y el bloque final de recuentos de `database/supabase_init.sql` (34 tablas).
  * **Hecho cuando:** `php bin/init_cloud_db.php` aplica el esquema y las semillas sin errores sobre PostgreSQL.

- [x] **T-OFFLINE-02 · Actualización de guardas de esquema y suite `PostgresOfflineSyncSchemaTest`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-OFFLINE-01`
  * **Ficheros a crear o modificar:**
    * `tests/Support/TestDataCleaner.php` **(modificar)**
    * `tests/Support/PostgresFixtureBuilder.php` **(modificar)**
    * `tests/integration/SchemaConvergenceParityTest.php` **(modificar)**
    * `tests/integration/PostgresSchemaTest.php` **(modificar)**
    * `tests/integration/PostgresOfflineSyncSchemaTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-04.2`, `RF-05.3`, `RF-06.4`, Art. III.1 y Art. III.3 de `constitution.md`.
  * **Detalle de implementación:**
    1. Añadir `offline_sync_mutations` en `TestDataCleaner.php` antes de `users` y registrarla en `PostgresFixtureBuilder::CRUD_EXCLUDED_COLUMNS`.
    2. Actualizar `CONTRACT_TABLES` de 33 a 34 tablas en `PostgresSchemaTest.php` y `SchemaConvergenceParityTest.php`.
    3. Crear `tests/integration/PostgresOfflineSyncSchemaTest.php` verificando en PostgreSQL: (a) unicidad de `uq_offline_sync_tech_mutation (technician_id, client_mutation_id)`, (b) rechazo de formatos inválidos de `client_mutation_id`, `stop_type`, `operation_type` y `outcome_status`, y (c) rechazo de `DELETE FROM offline_sync_mutations` por el disparador `trg_no_delete_offline_sync_mutations` (Art. III.1).
  * **Hecho cuando:** `php tests/integration/PostgresOfflineSyncSchemaTest.php && php tests/integration/PostgresSchemaTest.php && php tests/integration/SchemaConvergenceParityTest.php` pasan al 100 % en verde con código de salida `0`.

---

## Fase 2: Modelos de Dominio, Value Objects y Excepciones

- [x] **T-OFFLINE-03 · Entidad `OfflineSyncMutation`, Value Object `OfflineSyncMetadata`, excepción `OfflineSyncValidationException` e interfaz `OfflineSyncMutationRepositoryInterface`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-OFFLINE-02`
  * **Ficheros a crear o modificar:**
    * `src/Core/Domain/Model/OfflineSyncMutation.php` **(crear)**
    * `src/Core/Domain/ValueObject/OfflineSyncMetadata.php` **(crear)**
    * `src/Core/Domain/Exception/OfflineSyncValidationException.php` **(crear)**
    * `src/Core/Domain/Repository/OfflineSyncMutationRepositoryInterface.php` **(crear)**
  * **Requisitos que cubre:** `RF-03.4`, `RF-04.2`, `RF-04.3`, `RF-05.3`, `RF-06.4`, Decisiones `D1` y `D2`, Resoluciones `QA 1`, `QA 2`, `QA 4` y `QA 5`, Art. II y Art. III.3 de `constitution.md`.
  * **Detalle de implementación:**
    1. Implementar la entidad inmutable `OfflineSyncMutation` con el catálogo cerrado de las 9 operaciones permitidas (`START_INCIDENT`, `RESUME_INCIDENT_PENDING_INFO`, `PAUSE_INCIDENT_PARTS`, `PAUSE_INCIDENT_PENDING_INFO`, `RESOLVE_INCIDENT`, `START_PREVENTIVE`, `COMPLETE_PREVENTIVE`, `REINSPECT_PREVENTIVE`, `ADD_INCIDENT_COMMENT`) y los 3 estados de resultado (`APPLIED`, `CONFLICT_DUMPED`, `DEACTIVATED_ACCOUNT_DUMP`).
    2. Implementar `OfflineSyncMetadata` con extracción desde array/cabeceras HTTP, validación del identificador `clientMutationId` (`8–64` caracteres `^[A-Za-z0-9_.:-]+$`), y el método puro `resolveEffectiveTimestamp(DateTimeImmutable $previousMilestoneAt, DateTimeImmutable $syncedAt)` que implementa la tolerancia futura de 5 minutos (`300 s`) y la preservación del intervalo relativo $\Delta t = t_2 - t_1$ (hasta `12 h`) en pasos encadenados cuando el reloj del dispositivo está fuera de rango (`QA 4`).
    3. Implementar `OfflineSyncValidationException` con constructores estáticos para `OFFLINE_MUTATION_CONFLICT` (`409`), `SANITARY_SEAL_CONFIRMATION_REQUIRED` (`422`), `INVALID_OFFLINE_DUMP_PAYLOAD` (`422`), `ACCOUNT_STILL_ACTIVE_USE_STANDARD_SYNC` (`409`) y `ACCOUNT_DEACTIVATED` (`403`).
    4. Declarar `OfflineSyncMutationRepositoryInterface`.
  * **Hecho cuando:** `php -l src/Core/Domain/Model/OfflineSyncMutation.php && php -l src/Core/Domain/ValueObject/OfflineSyncMetadata.php && php -l src/Core/Domain/Exception/OfflineSyncValidationException.php && php -l src/Core/Domain/Repository/OfflineSyncMutationRepositoryInterface.php` no reporta errores de sintaxis.

- [x] **T-OFFLINE-04 · Suite unitaria `OfflineSyncClockAndQuotaTest`**
  * **Tiempo estimado:** 20 min
  * **Dependencias previas:** `T-OFFLINE-03`
  * **Ficheros a crear o modificar:**
    * `tests/unit/OfflineSyncClockAndQuotaTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-03.4`, `RF-04.2`, `RF-04.3`, Decisión `D2`, Resoluciones `QA 1` y `QA 4`, Art. II y Art. III.3 de `constitution.md`.
  * **Detalle de implementación:**
    1. Certificar que `OfflineSyncMetadata::resolveEffectiveTimestamp()` acepta `client_occurred_at` dentro de $[T_{\text{prev}}, T_{\text{sync}} + 5\text{ min}]$ con `clock_skew_adjusted = false`.
    2. Certificar que cuando el reloj del teléfono está atrasado 24 horas o adelantado 2 horas en una cadena *Iniciar* ($t_1$) $\rightarrow$ *Resolver* ($t_2$) con $\Delta t = 25\text{ min}$, el paso final se fija en $T_{\text{sync}}$ y el paso inicial en $\max(T_{\text{prev}}, T_{\text{sync}} - 25\text{ min})$ con `clock_skew_adjusted = true`, preservando exactamente los 25 minutos de intervención in situ (`QA 4`).
    3. Certificar las validaciones de `OfflineSyncValidationException` ante ausencia de `sanitary_seal_confirmed` en riesgo sanitario de máquinas `FOOD_PERISHABLE` (`QA 1`).
  * **Hecho cuando:** `php tests/unit/OfflineSyncClockAndQuotaTest.php` pasa al 100 % en verde con código de salida `0`.

---

## Fase 3: Capa de Infraestructura y Repositorio PDO

- [x] **T-OFFLINE-05 · Implementación de `PdoOfflineSyncMutationRepository`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-OFFLINE-04`
  * **Ficheros a crear o modificar:**
    * `src/Infrastructure/Repository/PdoOfflineSyncMutationRepository.php` **(crear)**
  * **Requisitos que cubre:** `RF-02.1`, `RF-04.2`, `RF-04.3`, `RF-05.3`, `RF-06.4`, Decisiones `D2` y `D6`, Resoluciones `QA 1`, `QA 2`, `QA 4` y `QA 5`, Art. III.1, Art. III.3 y Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. Implementar `findByTechnicianAndMutationId(int $technicianId, string $clientMutationId)` para la guarda de idempotencia (`RF-04.2`).
    2. Implementar `fetchBootstrapSnapshotForTechnician(int $technicianId)` consolidando sin N+1 los datos de ruta correctiva, órdenes preventivas asignadas o en curso con sus checklists, catálogo de repuestos activos compatibles con las máquinas presentes en la ruta y stock de furgoneta (`RF-02.1`, `QA 4`).
    3. Implementar `recordAppliedMutation()` insertando en `offline_sync_mutations` (`outcome_status = 'APPLIED'`) y enriqueciendo el evento en `audit_log` con el bloque `offline_sync`.
    4. Implementar `recordConflictDump()` con la **excepción de auditoría (`QA 2`, Art. III.3)**: en averías (`stop_type = 'INCIDENT'`), inserta directamente la Nota Interna de Taller (`is_internal = TRUE`) en `incident_comments` (con foto adjunta si se aporta) **aunque el técnico ya no sea el titular o la avería esté en `CANCELLED` o `CLOSED` $> 48\text{ h}$**; en órdenes preventivas (`stop_type = 'PREVENTIVE_ORDER'`), anexa el resumen en `preventive_orders.notes`; y en ambos casos inserta en `offline_sync_mutations` (`CONFLICT_DUMPED`) y en `audit_log` (`OFFLINE_CONFLICT_DUMPED`).
    5. Implementar `recordDeactivatedAccountDumpBatch()` para volcar de forma atómica e idempotente la cola pendiente de un técnico cuya cuenta fue desactivada (`DEACTIVATED_ACCOUNT_DUMP`, `QA 5`).
  * **Hecho cuando:** `php -l src/Infrastructure/Repository/PdoOfflineSyncMutationRepository.php` no reporta errores de sintaxis.

---

## Fase 4: Servicio de Aplicación y Casos de Uso (`OfflineSyncService`)

- [x] **T-OFFLINE-06 · Implementación de `OfflineBootstrapSnapshotDto` y `OfflineSyncService`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-OFFLINE-05`
  * **Ficheros a crear o modificar:**
    * `src/Application/DTO/OfflineBootstrapSnapshotDto.php` **(crear)**
    * `src/Application/Service/OfflineSyncService.php` **(crear)**
  * **Requisitos que cubre:** `RF-02.1`, `RF-03.4`, `RF-04.2`, `RF-04.3`, `RF-05.1` a `RF-05.4`, `RF-06.4`, Decisiones `D1`, `D2` y `D6`, Resoluciones `QA 1`, `QA 2`, `QA 4` y `QA 5`, Art. II, Art. III.3 y Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. Implementar `OfflineBootstrapSnapshotDto` con `synced_at`, `warn_after_seconds = 14400` (4 h) y `readonly_after_seconds = 43200` (12 h).
    2. Implementar `OfflineSyncService::executeIdempotentMutation()` verificando idempotencia previa, exigiendo `sanitary_seal_confirmed = true` cuando una mutación offline afecta a una máquina `FOOD_PERISHABLE` con pausa térmica/crítica o checklist desfavorable (`EARS 03.4`, `QA 1`), calculando el sello temporal efectivo (`RF-04.3`, `QA 4`), interceptando colisiones parciales en el sub-bloque de reintegro durante `RESOLVE_INCIDENT` para completar el cierre de la avería y anexar automáticamente la Nota Interna con el dictamen de efectivo (`EARS 05.2`, `QA 2`), y garantizando que si una máquina `FOOD_PERISHABLE` ya estaba en `SANITARY_QUARANTINE` en el servidor, la resolución técnica no levante la cuarentena (`EARS 05.4`, Art. II).
    3. Implementar `OfflineSyncService::dumpConflict()` (`EARS 05.3`, `QA 1`, `QA 2`) y `OfflineSyncService::dumpDeactivatedAccountQueue()` (`EARS 06.4`, `QA 5`).
  * **Hecho cuando:** `php -l src/Application/DTO/OfflineBootstrapSnapshotDto.php && php -l src/Application/Service/OfflineSyncService.php` no reporta errores de sintaxis.

- [ ] **T-OFFLINE-07 · Suite unitaria `OfflineSyncServiceTest`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-OFFLINE-06`
  * **Ficheros a crear o modificar:**
    * `tests/unit/OfflineSyncServiceTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-03.4`, `RF-04.2`, `RF-04.3`, `RF-05.2`, `RF-05.3`, `RF-05.4`, `RF-06.4`, Resoluciones `QA 1`, `QA 2`, `QA 4` y `QA 5`, Art. II y Art. III.3 de `constitution.md`.
  * **Detalle de implementación:**
    1. Certificar que una segunda llamada con el mismo `(technician_id, client_mutation_id)` devuelve el resultado almacenado con `idempotent_replay = true` sin reejecutar el callback de dominio.
    2. Certificar el cierre exitoso de avería cuando existe colisión parcial en el reintegro (`partial_refund_collision_dumped = true` y creación de Nota Interna de Taller, `QA 2`).
    3. Certificar la retención de `SANITARY_QUARANTINE` cuando se resuelve offline una máquina `FOOD_PERISHABLE` que ya había superado las 4,0 horas sin frío (`sanitary_quarantine_retained = true`, Art. II).
    4. Certificar `dumpConflict()` sobre averías canceladas/cerradas y órdenes preventivas (`QA 1`, `QA 2`) y `dumpDeactivatedAccountQueue()` (`QA 5`).
  * **Hecho cuando:** `php tests/unit/OfflineSyncServiceTest.php` pasa al 100 % en verde con código de salida `0`.

---

## Fase 5: Controladores REST, Router y Suites de Integración HTTP

- [ ] **T-OFFLINE-08 · Controlador `TechnicianOfflineSyncController`, integración en controladores de parada, blindaje de Sede y rutas en `AppRouter`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-OFFLINE-07`
  * **Ficheros a crear o modificar:**
    * `src/Presentation/Controller/TechnicianOfflineSyncController.php` **(crear)**
    * `src/Presentation/Controller/TechnicianController.php` **(modificar)**
    * `src/Presentation/Controller/TechnicianPreventiveController.php` **(modificar)**
    * `src/Presentation/Controller/LocationPortalController.php` **(modificar)**
    * `src/Presentation/Routing/AppRouter.php` **(modificar)**
  * **Requisitos que cubre:** `RF-02.1`, `RF-03.1`, `RF-03.4`, `RF-04.2`, `RF-04.3`, `RF-05.1` a `RF-05.4`, `RF-06.4`, Resoluciones `QA 1` a `QA 5`, Art. II, Art. III.3 y Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. Crear `TechnicianOfflineSyncController` con las acciones `getBootstrap()` (`GET /api/technician/offline/bootstrap`), `dumpConflict()` (`POST /api/technician/offline/conflicts/dump`, soportando JSON y `multipart/form-data` con validación de foto por *magic bytes* Art. V.5) y `dumpDeactivatedAccount()` (`POST /api/technician/offline/deactivated-account-dump`, verificando el token de un técnico cuya cuenta tiene `is_active = false`, `QA 5`).
    2. Enriquecer las 9 acciones de parada en `TechnicianController` (`start`, `resume-pending-info`, `pause`, `pause-pending-info`, `resolve`, `comments`) y `TechnicianPreventiveController` (`start`, `complete`, `reinspect`) para leer `OfflineSyncMetadata` y ejecutar a través de `OfflineSyncService::executeIdempotentMutation()`.
    3. Añadir las claves internas de sincronización al saneador de `LocationPortalController` (Art. V.4) y registrar las rutas en `AppRouter.php`.
  * **Hecho cuando:** `php -l src/Presentation/Controller/TechnicianOfflineSyncController.php && php -l src/Presentation/Controller/TechnicianController.php && php -l src/Presentation/Controller/TechnicianPreventiveController.php && php -l src/Presentation/Routing/AppRouter.php` no reporta errores de sintaxis.

- [ ] **T-OFFLINE-09 · Suite de integración `OfflinePwaSyncApiTest`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-OFFLINE-08`
  * **Ficheros a crear o modificar:**
    * `tests/integration/OfflinePwaSyncApiTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-02.1`, `RF-03.4`, `RF-04.2`, `RF-04.3`, `RF-05.1` a `RF-05.4`, `RF-06.4`, Decisiones `D1` a `D6`, Resoluciones `QA 1` a `QA 5`, Art. II, Art. III.3, Art. V.3 y Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. Certificar `GET /api/technician/offline/bootstrap` para un técnico autenticado y rechazo `403` para `LOCATION_RESPONSIBLE`.
    2. Certificar el flujo encadenado (*Iniciar* $\rightarrow$ *Comentario* $\rightarrow$ *Resolver con repuestos y firma*) con `client_mutation_id`, verificando idempotencia ante reenvío duplicado (sin descontar dos veces el repuesto en `technician_van_stocks`), preservación de $\Delta t$ con reloj atrasado (`QA 4`), colisión parcial de reintegro (`QA 2`) y retención de `SANITARY_QUARANTINE` en máquina perecedera (`Art. II`).
    3. Certificar `POST /api/technician/offline/conflicts/dump` sobre una avería reasignada a otro compañero, sobre una avería en `CANCELLED` (`QA 2`) y sobre una orden preventiva, verificando que la Nota Interna (`is_internal = TRUE`) es invisible para el responsable de sede (`Art. V.4`).
    4. Certificar `POST /api/technician/offline/deactivated-account-dump` para un técnico desactivado (`is_active = false`, `QA 5`).
  * **Hecho cuando:** `php tests/integration/OfflinePwaSyncApiTest.php` pasa al 100 % en verde con código de salida `0`.

---

## Fase 6: PWA Nativa, Almacenamiento `IndexedDB`, Gestor de Cola e Interfaz Frontend (Vue 3 ESM)

- [ ] **T-OFFLINE-10 · Manifiesto Web (`manifest.webmanifest`), iconos vectoriales y Service Worker (`sw.js`)**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-OFFLINE-09`
  * **Ficheros a crear o modificar:**
    * `public/manifest.webmanifest` **(crear)**
    * `public/assets/icons/vendguard-icon-192.svg` **(crear)**
    * `public/assets/icons/vendguard-icon-512.svg` **(crear)**
    * `public/sw.js` **(crear)**
    * `public/index.html` **(modificar)**
  * **Requisitos que cubre:** `RF-01.1`, `RF-01.2`, `RF-01.3`, `RNF-01`, `RNF-02`, Decisión Técnica `DT-01`, Art. IV.3 y Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. Crear `public/manifest.webmanifest` y los iconos SVG escalables con la paleta corporativa (`#2560ff` / `#f9fafb`).
    2. Crear `public/sw.js` con precaché del App Shell (`index.html`, hojas CSS y módulos ES de Vue 3), estrategia *Stale-While-Revalidate* para activos estáticos del mismo origen y estrategia **`Network-Only` estricta** para `/api/*` (nunca cachear respuestas de API en `CacheStorage`, Art. V.4).
    3. Enlazar el manifiesto y registrar `/sw.js` en `public/index.html`.
  * **Hecho cuando:** `public/manifest.webmanifest` es JSON válido y `public/sw.js` contiene la lista completa del App Shell y la regla `Network-Only` para `/api/`.

- [ ] **T-OFFLINE-11 · Almacenamiento local `OfflineStorage.js` e implementación del motor `OfflineSyncManager.js`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-OFFLINE-10`
  * **Ficheros a crear o modificar:**
    * `public/assets/js/utils/OfflineStorage.js` **(crear)**
    * `public/assets/js/utils/OfflineSyncManager.js` **(crear)**
    * `public/assets/js/api.js` **(modificar)**
    * `public/assets/js/utils/AuditActionLabels.js` **(modificar)**
  * **Requisitos que cubre:** `RF-02.1` a `RF-02.4`, `RF-03.1` a `RF-03.5`, `RF-04.1` a `RF-04.3`, `RF-05.1` a `RF-05.3`, `RF-06.1` a `RF-06.5`, Decisiones `D1` a `D6`, Resoluciones `QA 1` a `QA 5`, `RNF-01` y `RNF-02`.
  * **Detalle de implementación:**
    1. Implementar `OfflineStorage.js` sobre `IndexedDB` (`vendguard_offline_v1`, almacenes `route_snapshots` y `mutation_outbox`) con soporte de respaldo en memoria para entornos de prueba Node: evaluación de vigencia (`FRESH` $\le 4\text{h}$, `STALE_WARNING` $4\text{h}\text{–}12\text{h}$, `EXPIRED_READONLY` $> 12\text{h}`, `D6`), mutaciones optimistas encadenadas sobre paradas locales y descuentos provisionales de stock de furgoneta (`D5`), y validador de cuota de **máximo 5 fotografías o 25 MB** eximiendo el trazo PNG de firma digital (`D3`, `QA 3`).
    2. Implementar `OfflineSyncManager.js`: encolado con generación de `client_mutation_id` y enlace `chain_started_at`/`chain_ended_at` (`QA 4`), procesamiento FIFO al reconectar, aislamiento de conflictos de transición de estado (`is_state_transition = true`) frente a comentarios intermedios (`is_state_transition = false`, `QA 3`), reversión de descuentos provisionales de stock ante conflicto, volcado obligatorio en 1 toque (`dumpConflict`) y volcado automático por cuenta desactivada (`QA 5`).
    3. Añadir en `api.js` los métodos `getOfflineBootstrap()`, `dumpOfflineConflict()` y `dumpDeactivatedAccountOfflineQueue()`, y en `AuditActionLabels.js` las etiquetas de `OFFLINE_CONFLICT_DUMPED` y `OFFLINE_DEACTIVATED_ACCOUNT_DUMP`.
  * **Hecho cuando:** Los módulos ES importan limpiamente en Node sin errores de sintaxis.

- [ ] **T-OFFLINE-12 · Componente `OfflineSyncTrayModal.js` e integración en `TechnicianRouteView.js` y `AppNavbar.js`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-OFFLINE-11`
  * **Ficheros a crear o modificar:**
    * `public/assets/js/components/OfflineSyncTrayModal.js` **(crear)**
    * `public/assets/js/views/TechnicianRouteView.js` **(modificar)**
    * `public/assets/js/components/AppNavbar.js` **(modificar)**
  * **Requisitos que cubre:** `RF-02.2` a `RF-02.4`, `RF-03.1` a `RF-03.5`, `RF-05.3`, `RF-06.1` a `RF-06.5`, Decisiones `D1` a `D6`, Resoluciones `QA 1` a `QA 5`, `RNF-03` y `RNF-04`.
  * **Detalle de implementación:**
    1. Crear `OfflineSyncTrayModal.js` respetando estrictamente `docs/design.md` (exclusivamente tokens `var(--color-*)`, bordes `4px`/`8px`, botones táctiles `min-height: 44px`, cero literales hexadecimales): barra de estado con reloj de antigüedad (*«Última sincronización: hace X min»*), aviso ámbar a las 4h, bloqueo de solo lectura a las 12h, contador de cola pendiente y modal de conflictos con el botón único **«Volcar trabajo al expediente y cerrar conflicto»** (sin botón de descarte silencioso, `QA 1`).
    2. Integrar en `TechnicianRouteView.js`: hidratación y lectura offline desde `OfflineStorage`, encolado en las 9 operaciones permitidas, bloqueo explícito en operaciones online-only (reclamo de preventivas libres, órdenes de carga, altas oportunistas fuera de ruta, `QA 4`), aviso *«📄 Albarán oficial A4 disponible al sincronizar con el servidor»* mientras la resolución está en cola (`QA 4`), casilla obligatoria de confirmación de precinto físico in situ en pausas térmicas o preventivas desfavorables de máquinas `FOOD_PERISHABLE` (`EARS 03.4`, `QA 1`, Art. II), sincronización previa obligatoria antes de pausar/cerrar turno (`EARS 06.2`, `QA 5`) y modal de re-autenticación del mismo técnico ante `401` (`EARS 06.3`).
    3. Integrar en `AppNavbar.js` el bloqueo de «Cerrar sesión» cuando existen elementos pendientes o en conflicto en la cola del técnico (`EARS 06.1`) y la limpieza de la copia local al cerrar sesión con la bandeja vacía (`EARS 06.5`).
  * **Hecho cuando:** `OfflineSyncTrayModal.js`, `TechnicianRouteView.js` y `AppNavbar.js` cargan sin errores y respetan el trinquete de tokens de diseño.

- [ ] **T-OFFLINE-13 · Suites unitarias ESM `OfflineStorageAndSyncManagerTest.mjs` y `OfflinePwaAssetsAndUiGuardTest.mjs`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-OFFLINE-12`
  * **Ficheros a crear o modificar:**
    * `tests/unit/OfflineStorageAndSyncManagerTest.mjs` **(crear)**
    * `tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs` **(crear)**
  * **Requisitos que cubre:** `RF-01.1` a `RF-06.5`, `RNF-01` a `RNF-04`, Decisiones `D1` a `D6`, Resoluciones `QA 1` a `QA 5`.
  * **Detalle de implementación:**
    1. En `OfflineStorageAndSyncManagerTest.mjs`, certificar: vigencia del snapshot (`FRESH` $\le 4\text{h}$, `STALE_WARNING` $4\text{h}\text{–}12\text{h}$, `EXPIRED_READONLY` $> 12\text{h}`), cuota de 5 fotos / 25 MB con exención de firma PNG (`QA 3`), encadenamiento optimista en la misma parada con descuento y reversión provisional de stock de furgoneta (`D5`), aislamiento de conflictos por parada frente a independencia de comentarios intermedios (`QA 3`), volcado obligatorio en 1 toque (`QA 1`) y bloqueo de logout con cola pendiente (`D4`).
    2. En `OfflinePwaAssetsAndUiGuardTest.mjs`, certificar: estructura de `manifest.webmanifest`, regla `Network-Only` para `/api/` en `sw.js`, presencia de la confirmación de precinto físico (`QA 1`), ausencia de descarte silencioso y cumplimiento de `DesignTokenDebtRatchetTest`.
  * **Hecho cuando:** `node tests/unit/OfflineStorageAndSyncManagerTest.mjs && node tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs && php tests/unit/DesignTokenDebtRatchetTest.php` pasan al 100 % en verde con código de salida `0`.

---

## Fase 7: Certificación Integral, Matriz de Trazabilidad, Regresión Global y Cierre Documental

- [ ] **T-OFFLINE-14 · Matriz de trazabilidad (`traceability-matrix.md`), batería global `run_all.php` y actualización de `docs/features_pendientes.md`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-OFFLINE-13`
  * **Ficheros a crear o modificar:**
    * `specs/24-offline-pwa/traceability-matrix.md` **(crear)**
    * `docs/features_pendientes.md` **(modificar)**
  * **Requisitos que cubre:** Todos los requisitos `RF-01` a `RF-06`, `RNF-01` a `RNF-04`, Decisiones `D1`–`D6` y Resoluciones `QA 1`–`QA 5`.
  * **Detalle de implementación:**
    1. Redactar `specs/24-offline-pwa/traceability-matrix.md` vinculando cada criterio EARS, decisión y resolución QA con sus ficheros de código y pruebas automatizadas.
    2. Actualizar `docs/features_pendientes.md` marcando la **Feature 7 (📶 Modo Offline PWA para Técnicos de Campo)** como **100 % IMPLEMENTADA (Módulo 24: `24-offline-pwa`)**, cerrando así al 100 % el **Bloque 2 (Calidad de Servicio, Validación y Logística Móvil)**.
    3. Ejecutar la batería completa del proyecto (`php tests/run_all.php`) verificando 0 fallos.
  * **Hecho cuando:** `php tests/run_all.php` finaliza con el 100 % de las suites y aserciones en verde (`exit code 0`).
