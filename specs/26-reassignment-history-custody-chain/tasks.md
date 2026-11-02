# PLAN DE TAREAS ATÓMICAS: HISTORIAL COMPLETO DE REASIGNACIONES Y CADENA DE CUSTODIA

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 26 · Historial Completo de Reasignaciones y Cadena de Custodia (*Reassignment History & Custody Chain*)  
**Documento Canónico:** [`specs/26-reassignment-history-custody-chain/tasks.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/specs/26-reassignment-history-custody-chain/tasks.md)  
**Especificación Funcional:** [`spec.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/specs/26-reassignment-history-custody-chain/spec.md) (v2.1, Noviembre 2026) · [`../functional/reassignment_history_custody_chain_spec.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/specs/functional/reassignment_history_custody_chain_spec.md)  
**Contratos Técnicos:** [`../technical/reassignment_history_custody_chain_contracts.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/specs/technical/reassignment_history_custody_chain_contracts.md)  
**Plan Arquitectónico:** [`plan.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/specs/26-reassignment-history-custody-chain/plan.md)  
**Estado:** Aprobado (Gate SDD Superado · Pendiente de Ejecución `T-CUSTODY-01` a `T-CUSTODY-14`)  
**Fecha:** 2026-11-02  

---

## Resumen de Fases y Tareas

| Fase | Tareas | Tiempo Estimado | Propósito |
| :--- | :--- | :--- | :--- |
| **Fase 1: Esquema de Base de Datos PostgreSQL / Supabase y Guardas de Esquema** | `T-CUSTODY-01`, `T-CUSTODY-02` | 45–50 min | Migración incremental `022_incident_assignments_custody_chain.sql`, convergencia en `supabase_init.sql` (nuevos tipos `ENUM` `incident_assignment_source` e `incident_unassignment_reason_type`, Tabla 37 `incident_assignments` con *snapshots* congelados, restricciones `CHECK`, índice único parcial `uq_incident_assignments_active_custody` `Art. V.3`, disparadores `trg_no_delete_incident_assignments` `Art. III.1` y `trg_immutable_incident_assignments` `Art. III.3`, y *Backfill* SQL idempotente `QA 5`), semillas, contratos y suites de esquema PostgreSQL. |
| **Fase 2: Modelos de Dominio, Enumerados y Validador de Motivo de Reasignación** | `T-CUSTODY-03`, `T-CUSTODY-04` | 45–50 min | Creación de `IncidentAssignmentSource`, `IncidentUnassignmentReasonType` (con `isUnresolvedTransferOrRelease(): bool` `R2`, `QA 2`), entidad inmutable `IncidentAssignment` y validador `ReassignmentReasonValidator` ($\ge 10$ caracteres reales tras colapsar espacios y al menos un carácter alfanumérico `/[\p{L}\p{N}]/u`, `R1`, `QA 1`) + suites unitarias `ReassignmentReasonValidatorTest` y `IncidentAssignmentTest`. |
| **Fase 3: Capa de Infraestructura y Repositorio Transaccional (`PdoIncidentRepository`)** | `T-CUSTODY-05`, `T-CUSTODY-06` | 50–55 min | Métodos transaccionales `closeActiveCustodySegmentInTransaction()` (con `FOR UPDATE` y `GREATEST(assigned_at, closed_at)` `QA 4`) y `openCustodySegmentInTransaction()` (con *snapshots* congelados `D2`); integración en las 4 vías operativas (Coordinación, Oportunista In Situ, Relevo de Fin de Turno y Protocolo Sanitario `Art. II` Niveles 1 y 2), resolución (`QA 3`, `QA 4`), cancelación ordinaria o $> 72\text{ h}$ (`QA 3`), sellado defensivo en 3.ª reincidencia crónica y 48 h (`QA 4`), carga sin N+1 en `findEnrichedDetailById()` (`RNF-01`) y trazabilidad en `audit_log` (`Art. III.3`, `QA 1`). |
| **Fase 4: Servicios de Aplicación, Proyección de Cadena de Custodia y Motor de Fallback** | `T-CUSTODY-07`, `T-CUSTODY-08`, `T-CUSTODY-09` | 65–75 min | Ampliación de `CoordinatorIncidentDetailDto` y `CoordinatorIncidentDetailService` con la proyección de `custody_chain` (`summary`, `segments`, `unassigned_gaps`, `timeline_items`), retención continua (`D4`), huecos `UNASSIGNED_GAP` ($> 60\text{ s}$) excluyendo intervalos en `RESOLVED` previos a reapertura (`D4`, `QA 2`), motor de *Fallback* determinista (`"SIN-CODIGO"`, `"Coordinación / Sistema"`, `"Registro histórico previo"`, `QA 5`), autoría humana real en `ShiftHandoverService` (`QA 3`), `OpportunisticIncidentService`, `SparePartTraceabilityService`, `IncidentPauseService` y `OfflineSyncService` (`QA 4`) + ampliación de `CoordinatorIncidentDetailServiceTest`. |
| **Fase 5: Controlador REST, Blindaje Constitucional de Privacidad y Suites de Integración HTTP** | `T-CUSTODY-10`, `T-CUSTODY-11` | 50–55 min | Integración de `ReassignmentReasonValidator` en `CoordinatorController::assignTechnician()` preservando independiente la salvaguarda sanitaria de 20 caracteres (`COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED`, `Art. II`, `Art. V.1`, `QA 1`) y `TECHNICIAN_ALREADY_ASSIGNED` (`422`, `R4`); opacidad estricta en endpoints móviles de Técnico, Portal de Sede, QR público y Parte A4 (`Art. V.4`, `D3`, `QA 5`) + suites `CustodyChainApiTest` y `CustodyChainConstitutionalTest`. |
| **Fase 6: Interfaz Frontend en Modal de Coordinación (Vue 3 ESM)** | `T-CUSTODY-12`, `T-CUSTODY-13` | 45–50 min | Modificación de `CoordinatorIncidentDetailModal.js` y `AuditActionLabels.js`: renderizado permanente de **«🔄 Cadena de Custodia y Reasignaciones»** (`R6`), KPIs de resumen, línea de tiempo intercalada (`CUSTODY_SEGMENT` / `UNASSIGNED_GAP`), validación reactiva en cliente del motivo de reasignación, refresco inmediato tras asignar/reasignar/cancelar y preservación de borrador con recarga automática ante colisión concurrente (`R4`) bajo tokens de `docs/design.md`. |
| **Fase 7: Certificación Integral, Matriz de Trazabilidad, Regresión Global y Cierre Documental** | `T-CUSTODY-14` | 25–30 min | Creación de `specs/26-reassignment-history-custody-chain/traceability-matrix.md`, ejecución de `php tests/run_all.php`, verificación de `DesignTokenDebtRatchetTest`, `SchemaConvergenceParityTest` y `SingleEngineDialectContractTest`, actualización de `docs/features_pendientes.md` y cierre del módulo. |

---

## Fase 1: Esquema de Base de Datos PostgreSQL / Supabase y Guardas de Esquema

- [x] **T-CUSTODY-01 · Migración `022_incident_assignments_custody_chain.sql`, convergencia en `supabase_init.sql` y semillas**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** Ninguna
  * **Ficheros a crear o modificar:**
    * [`database/supabase_migrations/022_incident_assignments_custody_chain.sql`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/database/supabase_migrations/022_incident_assignments_custody_chain.sql) **(crear)**
    * [`database/supabase_init.sql`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/database/supabase_init.sql) **(modificar)**
    * [`database/supabase_seeds.sql`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/database/supabase_seeds.sql) **(modificar)**
    * [`src/Infrastructure/Persistence/SeedRunner.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Infrastructure/Persistence/SeedRunner.php) **(modificar)**
    * [`specs/technical/database_schema.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/specs/technical/database_schema.md) **(modificar)**
    * [`specs/technical/schema_correspondence_contract.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/specs/technical/schema_correspondence_contract.md) **(modificar)**
  * **Requisitos que cubre:** `RF-01` (`EARS 01.1` a `EARS 01.9`), `RF-02` (`EARS 02.1`), `RF-03` (`EARS 03.7`), `RNF-01`, `RNF-02`, Decisiones `D1`, `D2` y `D4`, Resoluciones `R1`, `QA 1`, `QA 4` y `QA 5`, Art. III.1, Art. III.3 y Art. V.3 de [`constitution.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/constitution.md).
  * **Detalle de implementación:**
    1. Declarar de forma idempotente en `database/supabase_migrations/022_incident_assignments_custody_chain.sql` y en `database/supabase_init.sql` los 2 nuevos tipos `ENUM` (**elevando el total a 24 tipos `ENUM`**): `incident_assignment_source` (`INITIAL_ASSIGNMENT`, `COORDINATOR_REASSIGN`, `ABSENCE_COVERAGE`, `OPPORTUNISTIC_NEW`, `OPPORTUNISTIC_ASSUMED`, `OPPORTUNISTIC_TAKEOVER`, `SHIFT_HANDOVER_RELEASE`, `SANITARY_FAILSAFE_GUARD`) e `incident_unassignment_reason_type` (`REASSIGNED_BY_COORDINATOR`, `ABSENCE_COVERAGE_REASSIGN`, `TAKEN_OVER_IN_SITU`, `SHIFT_HANDOVER`, `SANITARY_FAILSAFE_GUARD`, `SANITARY_FAILSAFE_UNASSIGNED`, `RESOLVED`, `CANCELLED`, `CHRONIC_REPLACEMENT_CLOSED`, `WARRANTY_REOPENED`).
    2. Crear la **Tabla 37 `incident_assignments`** con las 14 columnas especificadas en `reassignment_history_custody_chain_contracts.md` (`id`, `incident_id`, `technician_id`, `technician_name_snapshot`, `technician_code_snapshot`, `assignment_source`, `assigned_by`, `assigned_by_name_snapshot`, `assigned_at`, `unassigned_at`, `unassigned_by`, `unassigned_by_name_snapshot`, `unassignment_reason_type`, `reassignment_reason`), claves foráneas con `ON DELETE RESTRICT` y las restricciones `chk_incident_assignments_dates` (`unassigned_at IS NULL OR unassigned_at >= assigned_at`), `chk_incident_assignments_closure_consistency` y `chk_incident_assignments_reason_length` ($\ge 10$ caracteres reales tras colapsar espacios y al menos un carácter alfanumérico `[[:alnum:]]` en reasignaciones de coordinación/ausencia, `QA 1`).
    3. Crear los índices `idx_incident_assignments_incident_timeline`, `idx_incident_assignments_technician` y el **índice único parcial `uq_incident_assignments_active_custody ON incident_assignments (incident_id) WHERE unassigned_at IS NULL`** (`Art. V.3`), junto con los disparadores constitucionales `trg_no_delete_incident_assignments` (`Art. III.1`) y `trg_immutable_incident_assignments` (`Art. III.3`).
    4. Incluir en la migración el bloque de *Backfill* SQL idempotente para poblar tramos base en incidencias preexistentes con `assigned_technician_id IS NOT NULL` empleando los valores de respaldo `"SIN-CODIGO"`, `"Coordinación / Sistema"` y `"Registro histórico previo"` (`D4`, `QA 5`), y añadir semillas representativas de cadena de custodia en `database/supabase_seeds.sql` y `SeedRunner.php`.
    5. Actualizar `specs/technical/database_schema.md` y `specs/technical/schema_correspondence_contract.md` (37 tablas relacionales y 24 tipos `ENUM`).
  * **Hecho cuando:** `php bin/init_cloud_db.php` aplica el esquema, disparadores y semillas sin errores sobre PostgreSQL.

- [x] **T-CUSTODY-02 · Actualización de arneses de prueba y suites de paridad de esquema (`SchemaConvergenceParityTest` y `PostgresSchemaTest`)**
  * **Tiempo estimado:** 20 min
  * **Dependencias previas:** `T-CUSTODY-01`
  * **Ficheros a crear o modificar:**
    * [`tests/Support/TestDataCleaner.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/tests/Support/TestDataCleaner.php) **(modificar)**
    * [`tests/Support/PostgresFixtureBuilder.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/tests/Support/PostgresFixtureBuilder.php) **(modificar)**
    * [`tests/integration/SchemaConvergenceParityTest.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/tests/integration/SchemaConvergenceParityTest.php) **(modificar)**
    * [`tests/integration/PostgresSchemaTest.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/tests/integration/PostgresSchemaTest.php) **(modificar)**
  * **Requisitos que cubre:** `RF-01`, `RNF-01`, `RNF-02`, Decisión `D2`, Resoluciones `QA 1` y `QA 5`, Art. III.1, Art. III.3 y Art. V.3 de [`constitution.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/constitution.md).
  * **Detalle de implementación:**
    1. Añadir `incident_assignments` en el orden correcto de truncado/limpieza de `TestDataCleaner.php` y añadir ayudantes de creación de tramos en `PostgresFixtureBuilder.php`.
    2. Actualizar el contrato de tablas canónicas a **37 tablas** (`incident_assignments`) y **24 tipos `ENUM`** (`incident_assignment_source`, `incident_unassignment_reason_type`) en `SchemaConvergenceParityTest.php` y `PostgresSchemaTest.php`.
  * **Hecho cuando:** `php tests/integration/SchemaConvergenceParityTest.php && php tests/integration/PostgresSchemaTest.php` pasan al 100 % en verde con código de salida `0`.

---

## Fase 2: Modelos de Dominio, Enumerados y Validador de Motivo de Reasignación

- [ ] **T-CUSTODY-03 · Enumerados `IncidentAssignmentSource` e `IncidentUnassignmentReasonType`, entidad `IncidentAssignment` y validador `ReassignmentReasonValidator`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-CUSTODY-02`
  * **Ficheros a crear o modificar:**
    * [`src/Core/Domain/Enum/IncidentAssignmentSource.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Core/Domain/Enum/IncidentAssignmentSource.php) **(crear)**
    * [`src/Core/Domain/Enum/IncidentUnassignmentReasonType.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Core/Domain/Enum/IncidentUnassignmentReasonType.php) **(crear)**
    * [`src/Core/Domain/Model/IncidentAssignment.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Core/Domain/Model/IncidentAssignment.php) **(crear)**
    * [`src/Core/Domain/Service/ReassignmentReasonValidator.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Core/Domain/Service/ReassignmentReasonValidator.php) **(crear)**
  * **Requisitos que cubre:** `RF-01`, `RF-02` (`EARS 02.1`, `EARS 02.2`), `RF-03` (`EARS 03.2`, `EARS 03.6`), Decisiones `D1`, `D2` y `D4`, Resoluciones `R1`, `R2`, `QA 1`, `QA 2` y `QA 4`.
  * **Detalle de implementación:**
    1. Crear `IncidentAssignmentSource` con los 8 canales de entrada y el método `label(): string` con las etiquetas en español neutro definidas en el contrato técnico.
    2. Crear `IncidentUnassignmentReasonType` con las 10 causas de cierre de tramo, su método `label(): string` y el método `isUnresolvedTransferOrRelease(): bool` que devuelve `true` exclusivamente para `REASSIGNED_BY_COORDINATOR`, `ABSENCE_COVERAGE_REASSIGN`, `TAKEN_OVER_IN_SITU`, `SHIFT_HANDOVER`, `SANITARY_FAILSAFE_GUARD` y `SANITARY_FAILSAFE_UNASSIGNED`, y `false` para `RESOLVED`, `CANCELLED`, `CHRONIC_REPLACEMENT_CLOSED` y `WARRANTY_REOPENED` (`R2`, `QA 2`).
    3. Crear la entidad inmutable `IncidentAssignment` con validación de invariante temporal (`unassigned_at >= assigned_at`), indicador `isActive(): bool` y método `retentionSecondsAt(DateTimeImmutable $now): int` ($\max(0, \text{end} - \text{assigned\_at})$).
    4. Crear `ReassignmentReasonValidator::normalizeAndValidate(?string $rawReason): string` que recorta extremos, colapsa secuencias internas de espacios en blanco (`preg_replace('/\s+/u', ' ', trim(...))`), exige `mb_strlen($normalized, 'UTF-8') >= 10` y verifica `preg_match('/[\p{L}\p{N}]/u', $normalized) === 1`, lanzando `InvalidArgumentException` si no cumple (`R1`, `QA 1`).
  * **Hecho cuando:** `php -l src/Core/Domain/Enum/IncidentAssignmentSource.php && php -l src/Core/Domain/Enum/IncidentUnassignmentReasonType.php && php -l src/Core/Domain/Model/IncidentAssignment.php && php -l src/Core/Domain/Service/ReassignmentReasonValidator.php` no reporta errores de sintaxis.

- [ ] **T-CUSTODY-04 · Suites unitarias `ReassignmentReasonValidatorTest` e `IncidentAssignmentTest`**
  * **Tiempo estimado:** 20 min
  * **Dependencias previas:** `T-CUSTODY-03`
  * **Ficheros a crear o modificar:**
    * [`tests/unit/ReassignmentReasonValidatorTest.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/tests/unit/ReassignmentReasonValidatorTest.php) **(crear)**
    * [`tests/unit/IncidentAssignmentTest.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/tests/unit/IncidentAssignmentTest.php) **(crear)**
  * **Requisitos que cubre:** `RF-01`, `RF-02`, `RF-03`, Decisiones `D1`, `D2` y `D4`, Resoluciones `R1`, `R2`, `QA 1`, `QA 2` y `QA 4`.
  * **Detalle de implementación:**
    1. En `ReassignmentReasonValidatorTest.php`, certificar el rechazo de motivos `null`, vacíos, solo espacios (`"            "`), textos cortos con espacios intercalados (`"a        b"`) y cadenas de $\ge 10$ guiones o símbolos sin caracteres alfanuméricos (`"----------"`, `"..........."`), así como la normalización exitosa de motivos válidos con espacios múltiples intermedios (`QA 1`).
    2. En `IncidentAssignmentTest.php`, certificar la creación de tramos activos y cerrados, el rechazo de `unassigned_at < assigned_at`, el cálculo exacto de `retentionSecondsAt($now)` (incluyendo `0 s` cuando `unassigned_at === assigned_at` por suelo temporal `QA 4`), y la tabla de verdad de `IncidentUnassignmentReasonType::isUnresolvedTransferOrRelease()` para las 10 causas de cierre (`R2`, `QA 2`).
  * **Hecho cuando:** `php tests/unit/ReassignmentReasonValidatorTest.php && php tests/unit/IncidentAssignmentTest.php` pasan al 100 % en verde con código de salida `0`.

---

## Fase 3: Capa de Infraestructura y Repositorio Transaccional (`PdoIncidentRepository`)

- [ ] **T-CUSTODY-05 · Primitivas transaccionales de apertura/cierre de custodia e integración en Coordinación, Oportunista In Situ y Relevo de Turno en `PdoIncidentRepository`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-CUSTODY-04`
  * **Ficheros a crear o modificar:**
    * [`src/Infrastructure/Repository/PdoIncidentRepository.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Infrastructure/Repository/PdoIncidentRepository.php) **(modificar)**
  * **Requisitos que cubre:** `RF-01` (`EARS 01.1` a `EARS 01.4`), `RF-02` (`EARS 02.3`, `EARS 02.4`), Decisiones `D1` y `D2`, Resoluciones `R1`, `QA 1`, `QA 3` y `QA 4`, Art. III.3 y Art. V.3 de [`constitution.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/constitution.md).
  * **Detalle de implementación:**
    1. Implementar en `PdoIncidentRepository` los métodos privados `closeActiveCustodySegmentInTransaction()` (con `SELECT ... FOR UPDATE` sobre la fila con `unassigned_at IS NULL` y actualización con `GREATEST(assigned_at, COALESCE(:closed_at::timestamptz, CURRENT_TIMESTAMP))` según `QA 4`) y `openCustodySegmentInTransaction()` (resolviendo y congelando `technician_name_snapshot`, `technician_code_snapshot` con fallback `'SIN-CODIGO'`, y `assigned_by_name_snapshot` según `D2` y `QA 5`).
    2. Integrar ambas primitivas dentro de la misma transacción PDO en:
       * `assignTechnician()` (y flujos de cobertura por ausencia): cierra tramo previo con `REASSIGNED_BY_COORDINATOR` (o `ABSENCE_COVERAGE_REASSIGN`) si existía custodio activo, abre el nuevo tramo con `INITIAL_ASSIGNMENT`, `COORDINATOR_REASSIGN` o `ABSENCE_COVERAGE`, y registra en `audit_log` la acción `'INCIDENT_CUSTODY_ASSIGNED'` / `'INCIDENT_CUSTODY_REASSIGNED'` con `previous_state` y `new_state` completos (`Art. III.3`, `QA 1`).
       * Operaciones de intervención oportunista in situ (`NEW` $\rightarrow$ `OPPORTUNISTIC_NEW`, `ASSUMED` $\rightarrow$ `OPPORTUNISTIC_ASSUMED`, `TAKEOVER` $\rightarrow$ cierre previo `TAKEN_OVER_IN_SITU` + apertura `OPPORTUNISTIC_TAKEOVER`, y reapertura en garantía in situ $\rightarrow$ sellado defensivo `WARRANTY_REOPENED` si hubiera tramo abierto + apertura `OPPORTUNISTIC_ASSUMED`).
       * Relevo de fin de turno: cierra el tramo del técnico saliente con `SHIFT_HANDOVER` registrando la autoría humana real (`technicianUserId` en cierre manual vs. `coordinatorUserId` en cierre administrativo forzado, `QA 3`) y, cuando el modo es `REASSIGN_TO_COLLEAGUE`, abre el tramo del compañero receptor con `SHIFT_HANDOVER_RELEASE`.
  * **Hecho cuando:** `php -l src/Infrastructure/Repository/PdoIncidentRepository.php` no reporta errores de sintaxis.

- [ ] **T-CUSTODY-06 · Integración en Protocolo Sanitario Art. II, Resolución, Cancelación, Sellado Defensivo y lectura sin N+1 en `findEnrichedDetailById()`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-CUSTODY-05`
  * **Ficheros a crear o modificar:**
    * [`src/Infrastructure/Repository/PdoIncidentRepository.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Infrastructure/Repository/PdoIncidentRepository.php) **(modificar)**
  * **Requisitos que cubre:** `RF-01` (`EARS 01.5` a `EARS 01.8`), `RF-03` (`EARS 03.1`), `RNF-01`, Decisiones `D1` y `D4`, Resoluciones `QA 3` y `QA 4`, Art. II y Art. III.3 de [`constitution.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/constitution.md).
  * **Detalle de implementación:**
    1. Integrar el cierre/apertura de custodia en `enforceColdChainFailSafe()` (`Art. II`):
       * **Nivel 1 (Guardia 24/7 disponible):** cierra el tramo del técnico previo (si existía) con `SANITARY_FAILSAFE_GUARD` (`unassigned_by = NULL`, `unassigned_by_name_snapshot = 'Protocolo Sanitario Art. II'`) y abre el tramo del técnico de guardia con `SANITARY_FAILSAFE_GUARD`.
       * **Nivel 2 (Sin guardia 24/7 — liberación a bandeja + cuarentena):** cierra el tramo del técnico previo con `SANITARY_FAILSAFE_UNASSIGNED` y motivo automático de liberación por alerta sanitaria sin guardia.
    2. Integrar el cierre del tramo activo en:
       * Resolución de incidencia (`RESOLVED` online o sincronizada): cierra el tramo activo con `unassignment_reason_type = 'RESOLVED'`, `unassigned_at = GREATEST(assigned_at, resolved_at)` y `reassignment_reason = $resolutionDiagnosis` (`QA 3`, `QA 4`).
       * Cancelación de incidencia (ordinaria o por $> 72\text{ h}$ hábiles de inactividad en `PENDING_INFO`): cierra el tramo activo (si existía) con `unassignment_reason_type = 'CANCELLED'` y el motivo de cancelación del coordinador (`QA 3`).
       * Sellado defensivo en retirada por 3.ª reincidencia crónica (`CHRONIC_REPLACEMENT_CLOSED`), cierre automático a las 48 h (`RESOLVED`) y reapertura por responsable de sede (`WARRANTY_REOPENED`) si existiera algún tramo heredado con `unassigned_at IS NULL` (`QA 4`).
    3. Ampliar `findEnrichedDetailById(string $incidentId)` para ejecutar dentro de la misma transacción de lectura la consulta indexada `SELECT ... FROM incident_assignments WHERE incident_id = :incident_id ORDER BY assigned_at ASC, id ASC` y devolverla bajo la clave `'assignments'` sin consultas N+1 (`RNF-01`).
  * **Hecho cuando:** `php -l src/Infrastructure/Repository/PdoIncidentRepository.php` no reporta errores de sintaxis y las pruebas existentes de `CoordinatorIncidentDetailServiceTest` siguen pasando.

---

## Fase 4: Servicios de Aplicación, Proyección de Cadena de Custodia y Motor de Fallback

- [ ] **T-CUSTODY-07 · Ampliación de `CoordinatorIncidentDetailDto` y motor de proyección `custody_chain` + Fallback en `CoordinatorIncidentDetailService`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-CUSTODY-06`
  * **Ficheros a crear o modificar:**
    * [`src/Application/DTO/CoordinatorIncidentDetailDto.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Application/DTO/CoordinatorIncidentDetailDto.php) **(modificar)**
    * [`src/Application/Service/CoordinatorIncidentDetailService.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Application/Service/CoordinatorIncidentDetailService.php) **(modificar)**
  * **Requisitos que cubre:** `RF-03` (`EARS 03.1` a `EARS 03.7`), `RNF-01`, Decisiones `D3` y `D4`, Resoluciones `R2`, `QA 2`, `QA 4` y `QA 5`.
  * **Detalle de implementación:**
    1. Añadir en `CoordinatorIncidentDetailDto` la propiedad `public array $custodyChain = []` (con valor por defecto para no romper constructores en tests legados) y exponer `'custody_chain' => $this->custodyChain` en `toArray()`.
    2. Implementar en `CoordinatorIncidentDetailService` la construcción del objeto `custody_chain` (`summary`, `segments`, `unassigned_gaps`, `timeline_items`):
       * Cálculo de `retention_seconds` en tiempo natural continuo (`unassigned_at - assigned_at` en tramos cerrados y `$now - assigned_at` en el tramo activo, incluyendo periodos en `WAITING_PARTS`, `PENDING_INFO` o `PAUSED`, `D4`).
       * Formateo legible `formatCustodyDuration(int $seconds)` (`"< 1 s"` para `0 s`, `"45 s"`, `"15 min"`, `"2 h 10 min"`, `"1 d 4 h"`, `QA 4`).
       * Conteo exacto `reassignments_count` sumando únicamente tramos cerrados donde `unassignment_reason_type->isUnresolvedTransferOrRelease() === true` (`R2`, `QA 2`).
       * Detección de huecos `UNASSIGNED_GAP` ($> 60\text{ s}$) acotados hasta la siguiente asignación, hasta `cancelled_at` si fue descartada sin técnico o hasta `$now` si sigue activa en bandeja, **excluyendo siempre el intervalo en que la avería estuvo en estado `RESOLVED` antes de una reapertura en garantía** (`D4`, `QA 2`).
       * Motor de *Fallback* determinista en lectura (`synthesizeFallbackAssignments`) cuando `assignments === []` pero la incidencia tiene o tuvo técnico asignado, aplicando `"SIN-CODIGO"`, `"Coordinación / Sistema"` y `"Registro histórico previo"` (`D4`, `QA 5`).
  * **Hecho cuando:** `php -l src/Application/DTO/CoordinatorIncidentDetailDto.php && php -l src/Application/Service/CoordinatorIncidentDetailService.php` no reporta errores de sintaxis.

- [ ] **T-CUSTODY-08 · Propagación de autoría y contexto de custodia en `ShiftHandoverService`, `OpportunisticIncidentService`, `SparePartTraceabilityService`, `IncidentPauseService` y `OfflineSyncService`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-CUSTODY-07`
  * **Ficheros a crear o modificar:**
    * [`src/Application/Service/ShiftHandoverService.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Application/Service/ShiftHandoverService.php) **(modificar)**
    * [`src/Application/Service/OpportunisticIncidentService.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Application/Service/OpportunisticIncidentService.php) **(modificar)**
    * [`src/Application/Service/SparePartTraceabilityService.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Application/Service/SparePartTraceabilityService.php) **(modificar)**
    * [`src/Application/Service/IncidentPauseService.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Application/Service/IncidentPauseService.php) **(modificar)**
    * [`src/Application/Service/OfflineSyncService.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Application/Service/OfflineSyncService.php) **(modificar)**
  * **Requisitos que cubre:** `RF-01` (`EARS 01.3`, `EARS 01.4`, `EARS 01.7`), Decisiones `D1` y `D4`, Resoluciones `QA 3` y `QA 4`.
  * **Detalle de implementación:**
    1. En `ShiftHandoverService`, asegurar que tanto el cierre de turno manual por el técnico como el cierre forzado por el coordinador pasen al repositorio el `actorUserId` humano real (`technicianUserId` vs. `coordinatorUserId`) y concatenen el motivo administrativo en cierres forzados (`QA 3`).
    2. En `OpportunisticIncidentService`, propagar el canal exacto (`OPPORTUNISTIC_NEW`, `OPPORTUNISTIC_ASSUMED`, `OPPORTUNISTIC_TAKEOVER`) y la justificación de absorción in situ al repositorio.
    3. En `SparePartTraceabilityService` y `OfflineSyncService`, asegurar que la resolución online o sincronizada desde la cola PWA propague `resolved_at` / `client_occurred_at` y `resolution_diagnosis` para que el cierre del tramo aplique `GREATEST(assigned_at, resolved_at)` (`QA 3`, `QA 4`).
    4. En `IncidentPauseService`, verificar que las transiciones a `WAITING_PARTS`, `PENDING_INFO` y `PAUSED` conserven intacto el tramo de custodia activo (`D4`).
  * **Hecho cuando:** `php -l src/Application/Service/ShiftHandoverService.php && php -l src/Application/Service/OpportunisticIncidentService.php && php -l src/Application/Service/SparePartTraceabilityService.php && php -l src/Application/Service/IncidentPauseService.php && php -l src/Application/Service/OfflineSyncService.php` no reporta errores de sintaxis.

- [ ] **T-CUSTODY-09 · Ampliación de la suite unitaria `CoordinatorIncidentDetailServiceTest`**
  * **Tiempo estimado:** 20 min
  * **Dependencias previas:** `T-CUSTODY-08`
  * **Ficheros a crear o modificar:**
    * [`tests/unit/CoordinatorIncidentDetailServiceTest.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/tests/unit/CoordinatorIncidentDetailServiceTest.php) **(modificar)**
  * **Requisitos que cubre:** `RF-03` (`EARS 03.1` a `EARS 03.7`), Decisión `D4`, Resoluciones `R2`, `R6`, `QA 2`, `QA 4` y `QA 5`.
  * **Detalle de implementación:**
    1. Añadir casos de prueba unitarios en `CoordinatorIncidentDetailServiceTest.php` que verifiquen:
       * Proyección completa de `custody_chain` con múltiples tramos (`INITIAL_ASSIGNMENT` $\rightarrow$ `REASSIGNED_BY_COORDINATOR` $\rightarrow$ `SHIFT_HANDOVER` $\rightarrow$ `RESOLVED`) y comprobación de que `reassignments_count` cuenta únicamente transferencias/liberaciones sin resolver (`QA 2`).
       * Intercalación cronológica de `UNASSIGNED_GAP` ($> 60\text{ s}$) y omisión de micro-huecos $\le 60\text{ s}$.
       * Exclusión del tiempo en estado `RESOLVED` cuando una incidencia resuelta es posteriormente reabierta en garantía (`D4`, `QA 2`).
       * Acotación exacta hasta `cancelled_at` cuando una incidencia sin técnico (`0` asignaciones) es cancelada desde la bandeja (`R6`, `QA 2`).
       * Motor de *Fallback* determinista en lectura aplicando `"SIN-CODIGO"`, `"Coordinación / Sistema"`, `"Registro histórico previo"` e `is_synthesized_fallback = true` (`QA 5`).
  * **Hecho cuando:** `php tests/unit/CoordinatorIncidentDetailServiceTest.php` pasa al 100 % en verde con código de salida `0`.

---

## Fase 5: Controlador REST, Blindaje Constitucional de Privacidad y Suites de Integración HTTP

- [ ] **T-CUSTODY-10 · Integración de `ReassignmentReasonValidator` en `CoordinatorController` y blindaje de opacidad (`Art. V.4`, `QA 5`)**
  * **Tiempo estimado:** 20 min
  * **Dependencias previas:** `T-CUSTODY-09`
  * **Ficheros a crear o modificar:**
    * [`src/Presentation/Controller/CoordinatorController.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Presentation/Controller/CoordinatorController.php) **(modificar)**
  * **Requisitos que cubre:** `RF-02` (`EARS 02.1` a `EARS 02.4`), `RF-05` (`EARS 05.1`, `EARS 05.2`), Decisión `D3`, Resoluciones `R1`, `R4`, `QA 1` y `QA 5`, Art. II, Art. V.1 y Art. V.4 de [`constitution.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/constitution.md).
  * **Detalle de implementación:**
    1. En `CoordinatorController::assignTechnician()`, validar `reassignment_reason` mediante `ReassignmentReasonValidator::normalizeAndValidate()` cuando la incidencia ya tiene un técnico asignado distinto del nuevo, devolviendo `422 REASSIGNMENT_REASON_REQUIRED` si falla (`R1`, `QA 1`).
    2. Mantener intactas e independientes la validación de `TECHNICIAN_ALREADY_ASSIGNED` (`422`, `R4`) cuando `previousTechnicianId === newTechnicianId` y la validación de `ColdChainOverridePolicy::validateOverrideJustification()` (`422 COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED` de $\ge 20$ caracteres, `Art. II`, `Art. V.1`, `QA 1`).
    3. Verificar que `custody_chain` se expone única y exclusivamente en `GET /api/coordinator/incidents/{id}/detail` y jamás se adjunta en endpoints de Técnico (`/api/technician/*`), Portal de Sede (`/api/location/*`), QR público ni Parte A4 (`Art. V.4`, `D3`, `QA 5`).
  * **Hecho cuando:** `php -l src/Presentation/Controller/CoordinatorController.php` no reporta errores de sintaxis.

- [ ] **T-CUSTODY-11 · Creación de las suites de integración `CustodyChainApiTest` y `CustodyChainConstitutionalTest`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-CUSTODY-10`
  * **Ficheros a crear o modificar:**
    * [`tests/integration/CustodyChainApiTest.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/tests/integration/CustodyChainApiTest.php) **(crear)**
    * [`tests/integration/CustodyChainConstitutionalTest.php`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/tests/integration/CustodyChainConstitutionalTest.php) **(crear)**
  * **Requisitos que cubre:** `RF-01` a `RF-05`, `RNF-01`, `RNF-02`, Decisiones `D1` a `D4`, Resoluciones `R1` a `R6`, Resoluciones `QA 1` a `QA 5`, Art. II, Art. III.1, Art. III.3, Art. V.1, Art. V.3 y Art. V.4 de [`constitution.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/constitution.md).
  * **Detalle de implementación:**
    1. En `CustodyChainApiTest.php`, probar contra PostgreSQL real:
       * Apertura de avería sin técnico (`0` asignaciones $\rightarrow$ `UNASSIGNED_GAP`), asignación inicial (`INITIAL_ASSIGNMENT`), reasignación por coordinación (`REASSIGNED_BY_COORDINATOR` + `COORDINATOR_REASSIGN`) y verificación de `audit_log` con `previous_state` y `new_state` (`QA 1`).
       * Rechazo `422 REASSIGNMENT_REASON_REQUIRED` con motivos de espacios o guiones (`"----------"`), rechazo `422 TECHNICIAN_ALREADY_ASSIGNED` al reasignar al mismo técnico, e independencia de `422 COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED` ($< 20$ caracteres, `QA 1`).
       * Trazabilidad en relevo de turno manual y forzado con autoría humana real (`QA 3`), absorción oportunista in situ (`TAKEN_OVER_IN_SITU`), protocolo sanitario Art. II Niveles 1 y 2 (`SANITARY_FAILSAFE_GUARD` / `SANITARY_FAILSAFE_UNASSIGNED`), resolución con suelo temporal `GREATEST(assigned_at, resolved_at)` (`QA 4`), cancelación por $> 72\text{ h}$ en `PENDING_INFO` (`QA 3`) y sellado defensivo en retirada por 3.ª reincidencia crónica (`QA 4`).
    2. En `CustodyChainConstitutionalTest.php`, certificar:
       * Bloqueo de `DELETE FROM incident_assignments` por `trg_no_delete_incident_assignments` (`Art. III.1`).
       * Bloqueo de `UPDATE` sobre tramos cerrados (`unassigned_at IS NOT NULL`) o campos de apertura por `trg_immutable_incident_assignments` (`Art. III.3`).
       * Bloqueo de doble custodia activa (`unassigned_at IS NULL`) para una misma incidencia por `uq_incident_assignments_active_custody` (`Art. V.3`).
       * Ausencia total de `custody_chain` y de motivos internos de reasignación en las respuestas de `/api/technician/route`, `/api/technician/incidents/{id}`, `/api/technician/history`, `/api/technician/offline/bootstrap`, `/api/location/incidents`, `/api/location/incidents/{id}`, `/api/public/incidents/track/{token}` y `/api/incidents/{id}/service-report` (`Art. V.4`, `QA 5`).
  * **Hecho cuando:** `php tests/integration/CustodyChainApiTest.php && php tests/integration/CustodyChainConstitutionalTest.php` pasan al 100 % en verde con código de salida `0`.

---

## Fase 6: Interfaz Frontend en Modal de Coordinación (Vue 3 ESM)

- [ ] **T-CUSTODY-12 · Renderizado de la sección «🔄 Cadena de Custodia y Reasignaciones» en `CoordinatorIncidentDetailModal.js` y etiquetas de auditoría**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-CUSTODY-11`
  * **Ficheros a crear o modificar:**
    * [`public/assets/js/components/CoordinatorIncidentDetailModal.js`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/public/assets/js/components/CoordinatorIncidentDetailModal.js) **(modificar)**
    * [`public/assets/js/utils/AuditActionLabels.js`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/public/assets/js/utils/AuditActionLabels.js) **(modificar)**
  * **Requisitos que cubre:** `RF-04` (`EARS 04.1` a `EARS 04.4`), `RNF-03`, `RNF-04`, Decisión `D3`, Resoluciones `R3` y `R6`.
  * **Detalle de implementación:**
    1. Añadir en `AuditActionLabels.js` las traducciones legibles en español para `'INCIDENT_CUSTODY_ASSIGNED'` y `'INCIDENT_CUSTODY_REASSIGNED'`.
    2. En `CoordinatorIncidentDetailModal.js`, renderizar de forma permanente la sección **«🔄 Cadena de Custodia y Reasignaciones»** (`data-testid="incident-custody-chain-section"`) en la columna izquierda debajo de «Línea de Tiempo de Estados»:
       * Cuando `custody_chain.summary.total_assignments === 0` (`R6`): mostrar el estado informativo *«Sin asignaciones técnicas registradas — La incidencia permanece o finalizó sin custodio técnico asignado»* junto con el tiempo acumulado en bandeja (`total_unassigned_formatted`).
       * Cuando `total_assignments >= 1`: mostrar las 4 tarjetas KPI de resumen (*Custodio Actual / Estado*, *Reasignaciones*, *Tiempo Total en Custodia* y *Tiempo en Bandeja Sin Asignar*) y la lista vertical intercalada `timeline_items` diferenciando visualmente `CUSTODY_SEGMENT` (activo en azul eléctrico `--color-accent-primary` `#2560ff` vs. cerrado en gris neutro con bloque de cierre, autor, causa y `reassignment_reason`) y `UNASSIGNED_GAP` ($> 60\text{ s}$ en ámbar `--color-warning` `#f8b60f`), empleando bordes `4px`/`8px` y cero literales hexadecimales fuera de tokens (`docs/design.md`).
  * **Hecho cuando:** `php tests/integration/DesignTokenDebtRatchetTest.php` pasa en verde sin detectar deuda de tokens ni literales hexadecimales prohibidos en `CoordinatorIncidentDetailModal.js`.

- [ ] **T-CUSTODY-13 · Validación reactiva en cliente del motivo de reasignación, refresco inmediato y preservación de borrador ante colisiones (`R1`, `R4`, `QA 1`)**
  * **Tiempo estimado:** 20 min
  * **Dependencias previas:** `T-CUSTODY-12`
  * **Ficheros a crear o modificar:**
    * [`public/assets/js/components/CoordinatorIncidentDetailModal.js`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/public/assets/js/components/CoordinatorIncidentDetailModal.js) **(modificar)**
  * **Requisitos que cubre:** `RF-02` (`EARS 02.1`, `EARS 02.2`), `RF-04` (`EARS 04.5`, `EARS 04.6`), Resoluciones `R1`, `R4` y `QA 1`.
  * **Detalle de implementación:**
    1. Actualizar el computed `canSubmitAssign` en `CoordinatorIncidentDetailModal.js` para normalizar espacios (`reassignmentReasonDraft.trim().replace(/\s+/g, ' ')`), exigir longitud $\ge 10$ caracteres reales y al menos un carácter alfanumérico (`/[a-zA-Z0-9áéíóúÁÉÍÓÚñÑüÜ]/`), mostrando un indicador reactivo de caracteres válidos (`X / 10 mín.`) cuando la incidencia ya tiene técnico asignado (`R1`, `QA 1`).
    2. Garantizar que tras un `PATCH /api/coordinator/incidents/{id}/assign` o `PATCH /cancel` exitoso (`200 OK`), el modal invoque `await fetchIncidentDetail()` y actualice reactivamente la sección **«🔄 Cadena de Custodia y Reasignaciones»** sin cerrar el modal.
    3. Si `PATCH /api/coordinator/incidents/{id}/assign` devuelve un error de colisión o estado (`409` o `422`), preservar intacto `reassignmentReasonDraft` y `urgencyOverrideReasonDraft` mientras se recarga automáticamente en segundo plano `fetchIncidentDetail({ preserveAssignDrafts: true })` para mostrar al coordinador el nuevo estado y custodio de la incidencia sin perder el texto escrito (`R4`).
  * **Hecho cuando:** `php tests/integration/DesignTokenDebtRatchetTest.php` pasa en verde con código de salida `0`.

---

## Fase 7: Certificación Integral, Matriz de Trazabilidad, Regresión Global y Cierre Documental

- [ ] **T-CUSTODY-14 · Matriz de trazabilidad, regresión completa (`run_all.php`) y cierre documental en `docs/features_pendientes.md`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-CUSTODY-13`
  * **Ficheros a crear o modificar:**
    * [`specs/26-reassignment-history-custody-chain/traceability-matrix.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/specs/26-reassignment-history-custody-chain/traceability-matrix.md) **(crear)**
    * [`docs/features_pendientes.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/docs/features_pendientes.md) **(modificar)**
    * [`specs/26-reassignment-history-custody-chain/tasks.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/specs/26-reassignment-history-custody-chain/tasks.md) **(modificar — marcar checkboxes completados)**
  * **Requisitos que cubre:** Todos (`RF-01` a `RF-05`, `RNF-01` a `RNF-04`, Decisiones `D1` a `D4`, Resoluciones `R1` a `R6`, Resoluciones `QA 1` a `QA 5` y Artículos II, III.1, III.3, IV, V.1, V.3 y V.4 de [`constitution.md`](file:///c:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/constitution.md)).
  * **Detalle de implementación:**
    1. Redactar `specs/26-reassignment-history-custody-chain/traceability-matrix.md` mapeando 1:1 cada requisito EARS (`RF-01`–`RF-05`, `RNF-01`–`RNF-04`), decisiones (`D1`–`D4`), resoluciones (`R1`–`R6`, `QA 1`–`QA 5`) y artículos constitucionales con su archivo de código y test verificador.
    2. Ejecutar las suites de gobernanza y la batería completa de pruebas del repositorio (`php tests/run_all.php`) asegurando 0 fallos y 0 regresiones.
    3. Actualizar el estado de la **Feature 12 (`🔄 Historial Completo de Reasignaciones y Cadena de Custodia`)** en `docs/features_pendientes.md` como implementada y certificada en el Módulo 26.
  * **Hecho cuando:** `php tests/integration/SchemaConvergenceParityTest.php && php tests/integration/SingleEngineDialectContractTest.php && php tests/integration/DesignTokenDebtRatchetTest.php && php tests/run_all.php` finalizan al 100 % en verde con código de salida `0`.
