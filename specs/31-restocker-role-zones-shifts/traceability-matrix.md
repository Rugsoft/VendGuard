# MATRIZ DE TRAZABILIDAD · MÓDULO 31

**Especificación:** [`spec.md`](spec.md) (v2.0) · **Espejo funcional:** [`../functional/restocker_role_zones_shifts_spec.md`](../functional/restocker_role_zones_shifts_spec.md) · **Contratos:** [`../technical/restocker_role_zones_shifts_contracts.md`](../technical/restocker_role_zones_shifts_contracts.md) · **Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md)

**Esquema de referencia canónico:** `RF-01` rol, CRUD y RBAC · `RF-02` zonificación y cuadrante dual · `RF-03` cobertura y promoción del `BACKUP` · `RF-04` jornada móvil · `RF-05` ausencias, turnos y registro horario.

**Corrida de certificación:** 2026-11-11 (PostgreSQL local bajo TLS, canal `new_engine` y batería global).

---

## 1. Requisitos Funcionales y criterios EARS

### RF-01 · Rol `RESTOCKER`, CRUD de Personal Interno y segregación RBAC

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **01.1** Rol en el enum nativo y alta con `operator_code`, `is_on_call = FALSE` y auditoría (D1, D3) | `database/supabase_migrations/027_restocker_role_and_zone_assignments.sql`, `UserRole`, `AdminStaffService`, `database/supabase_seeds.sql` (`REP-01`, `REP-02`) | `DomainEnumsTest` · `AdminStaffRestockerServiceTest` 1.x–2.x · `RestockerRoleZonesShiftsApiTest` 4.x, 5.x · `PostgresRestockerZoneSchemaTest` | 3 roles ✅ · 26/26 ✅ · 21/21 ✅ · 27/27 ✅ |
| **01.2** Inmutabilidad estricta del rol tras el alta (`C1`, Art. III.3) | `AdminStaffService::updateUser` (`RoleChangeForbiddenException`) | `AdminStaffRestockerServiceTest` 3.x · `RestockerRbacConstitutionalTest` | 26/26 ✅ · 27/27 ✅ |
| **01.3** Desactivación con cierre automático de turno y `FORCE_CLOSE_SHIFT` (`C3`) | `AdminStaffService::deactivateUser`, `ShiftLifecycleService` | `AdminStaffRestockerServiceTest` 4.x · `RestockerShiftAndWorkHoursTest` | 26/26 ✅ · 38/38 ✅ |
| **01.4** Separación SAT ↔ reposición y exclusión del motor de triaje (Art. V.3/V.4) | `InternalAuthMiddleware`, `AppRouter`, `TechnicianAssignmentSuggester::isEligibleTechnicianRole` | `RestockerRbacConstitutionalTest` (seis superficies SAT, `401/403`) · `TechnicianAssignmentSuggesterTest` 8.x · `RestockerRoleZonesShiftsApiTest` 1.x–3.x | 27/27 ✅ · 41/41 ✅ · 21/21 ✅ |

### RF-02 · Zonificación de carga y cuadrante dual en Zonas Operativas

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **02.1** Tabla 41 con `role_in_zone` + `is_primary`, disparador anti-borrado e índices únicos parciales (D3, QA 1, Art. III.1) | migración `027`, `RestockerZoneAssignment`, `database/supabase_init.sql` | `PostgresRestockerZoneSchemaTest` · `SchemaConvergenceParityTest` · `RestockerRbacConstitutionalTest` | 27/27 ✅ · 29/29 ✅ · 27/27 ✅ |
| **02.2** Validación de rol/cuenta y traslado confirmado de titularidad (`409 PRIMARY_ZONE_ALREADY_ASSIGNED`) | `PdoOperationalZoneRepository::assignRestocker`, `OperationalZoneService` | `PdoRestockerZoneRepositoryTest` · `RestockerRoleZonesShiftsApiTest` 6.x · `OperationalZoneManagementTabTest` | 26/26 ✅ · 21/21 ✅ · 53/53 ✅ |
| **02.3** Baja lógica idempotente y liberación al desactivar la zona (D3, C3, QA 4) | `PdoOperationalZoneRepository::removeRestocker`, `OperationalZoneService::deactivateZone` | `PdoRestockerZoneRepositoryTest` · `RestockerRoleZonesShiftsApiTest` 9.x · `RestockerRbacConstitutionalTest` | 26/26 ✅ · 21/21 ✅ · 27/27 ✅ |
| **02.4** Contadores, cuadrante de carga, insignia «Baja» y consulta inversa (D2, C3, QA 2) | `ZoneRestockerAssignmentDto`, `OperationalZoneDto`, `OperationalZoneManagementTab.js` | `RestockerZoneCoverageServiceTest` · `coordinator_restocker_tabs.test.mjs` · `OperationalZoneManagementTabTest` | 23/23 ✅ · 43/43 ✅ · 53/53 ✅ |

### RF-03 · Motor determinista de cobertura y promoción automática del `BACKUP`

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **03.1** Escalafón de 6 niveles activos y `UNAVAILABLE` (D3, C2, QA 3) | `RestockerZoneCoverageRanker`, `RestockerZoneCoverageService` | `RestockerZoneCoverageRankerTest` · `RestockerZoneCoverageServiceTest` · `RestockerRoleZonesShiftsApiTest` 7.x | 42/42 ✅ · 23/23 ✅ · 21/21 ✅ |
| **03.2** `#1 RECOMENDADO ⭐` con etiqueta de promoción y desempate `assigned_at` → `operator_code` → `id` | `RestockerZoneCoverageRanker` | `RestockerZoneCoverageRankerTest` 2.x–5.x · `RestockerZoneCoverageServiceTest` | 42/42 ✅ · 23/23 ✅ |
| **03.3** Precedencia de presencia física y `SHIFT_STARTED_DURING_ABSENCE` (C2) | `RestockerZoneCoverageRanker` (`has_overridden_absence`), `ShiftLifecycleService` | `RestockerZoneCoverageRankerTest` · `RestockerShiftAndWorkHoursTest` | 42/42 ✅ · 38/38 ✅ |

### RF-04 · Control de jornada y vista móvil del reponedor

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **04.1** Enrutamiento exclusivo a `RestockerRouteView.js` con zonas e insignias (D1) | `public/assets/js/components/RestockerRouteView.js`, `app.js`, `store.js`, `RestockerRouteController::getMyRoute` | `restocker_route_view.test.mjs` 1.x–2.x · `RestockerRoleZonesShiftsApiTest` 5.x | 54/54 ✅ · 21/21 ✅ |
| **04.2** Ciclo `ON_SHIFT`/`MEAL_BREAK`/`OFF_SHIFT` con cierre directo sin relevo SAT | `ShiftLifecycleService::closeShift`, `POST /api/technician/shift/close`, `RestockerRouteView.js` | `restocker_route_view.test.mjs` 3.x–4.x · `RestockerShiftAndWorkHoursTest` · `RestockerRoleZonesShiftsApiTest` 8.x | 54/54 ✅ · 38/38 ✅ · 21/21 ✅ |
| **04.3** Contador diario, «⏱️ Mis Horas» y aislamiento horizontal (Art. V.4) | `RestockerRouteController`, `RestockerRouteView.js` | `restocker_route_view.test.mjs` 5.x · `RestockerRbacConstitutionalTest` (dos reponedores) | 54/54 ✅ · 27/27 ✅ |

### RF-05 · Ausencias, turnos y registro horario con filtro por rol

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **05.1** Ausencias de ambos roles de campo, promoción de zonas y cierres forzados con motivo | `TechnicianAbsenceService`, `PdoTechnicianAbsenceRepository`, `CoordinatorAbsencesTab.js` | `CoordinatorAbsencesTabTest` · `coordinator_restocker_tabs.test.mjs` · `PdoWorkHoursRoleFilterTest` | 33/33 ✅ · 43/43 ✅ · 18/18 ✅ |
| **05.2** Informe y CSV con filtro `ALL|TECHNICIAN|RESTOCKER`, columna `Rol` y métricas SAT a cero (D2, QA 4) | `TechnicianWorkHoursService`, `CoordinatorWorkHoursTab.js` | `TechnicianWorkHoursServiceTest` 54/54 · `RestockerShiftAndWorkHoursTest` · `coordinator_restocker_tabs.test.mjs` | 54/54 ✅ · 38/38 ✅ · 43/43 ✅ |

---

## 2. Requisitos No Funcionales

| RNF | Artefacto | Test | Resultado medido |
|---|---|---|---|
| **RNF-01** prohibición de borrado físico en `users`, cuadrante, turnos, bitácora y ausencias (Art. III.1/III.3) | Disparadores `prevent_hard_delete()`, `removed_at`, `is_active`, `cancelled_at` | `PostgresRestockerZoneSchemaTest` · `RestockerRbacConstitutionalTest` · `PostgresConstitutionalTest` | ✅ 27/27 · 27/27 · 78/78 — `DELETE` rechazado con `45000`; baja lógica conserva la fila y el reingreso estrena vínculo |
| **RNF-02** determinismo y < 100 ms por zona | `RestockerZoneCoverageService` (tres lecturas acotadas, sin N+1) | `RestockerZoneCoverageRankerTest` (inmutabilidad de la foto y orden total) · `RestockerZoneCoverageServiceTest` | ✅ 42/42 · 23/23 — el ranking es función pura y el servicio compone cuadrante, turnos y ausencias con lecturas por zona |
| **RNF-03** Sistema de Diseño y dogma vanilla (Art. IV) | `RestockerRouteView.js`, `OperationalZoneManagementTab.js`, `AdminUsersTab.js`, `CoordinatorAbsencesTab.js`, `CoordinatorWorkHoursTab.js` | `DesignTokenDebtRatchetTest` · `FrontendTemplateCompileTest` · `MobileShellLayoutGuardTest` · `OfflinePwaAssetsAndUiGuardTest` | ✅ 3/3 · 15/15 (77 plantillas) · 7/7 · 45/45 — paleta, radios y *mobile-first* sin librerías externas |

---

## 3. Casos Límite, Decisiones y Resoluciones QA

| Elemento | Escenario | Test | Resultado |
|---|---|---|---|
| **C1** Cambio de rol en edición | `TECHNICIAN` → `RESTOCKER` rechazado con `409 ROLE_CHANGE_FORBIDDEN` | `AdminStaffRestockerServiceTest` · `RestockerRbacConstitutionalTest` | ✅ |
| **C2** Ausencia vigente e inicio de turno | Presencia física prevalece y audita `SHIFT_STARTED_DURING_ABSENCE` | `RestockerZoneCoverageRankerTest` · `RestockerShiftAndWorkHoursTest` | ✅ |
| **C3** Baja con turno abierto y zona viva | Cierre automático, insignia «Baja», `NO_DISPONIBLE` y promoción del `BACKUP` | `AdminStaffRestockerServiceTest` · `RestockerZoneCoverageServiceTest` | ✅ |
| **D1** Vista móvil y cierre directo | `RESTOCKER` cierra desde `ON_SHIFT` y desde `MEAL_BREAK` sin relevo SAT | `restocker_route_view.test.mjs` · `RestockerRoleZonesShiftsApiTest` | ✅ |
| **D2** Consolas unificadas | Cuadrante SAT y de carga separados; filtro de rol en ausencias y horas/CSV | `coordinator_restocker_tabs.test.mjs` · `OperationalZoneManagementTabTest` · `CoordinatorWorkHoursTabTest` | ✅ 43/43 · 53/53 · 58/58 |
| **D3** Titularidad única y guardia | Máximo un `PRIMARY` vivo; `is_on_call = TRUE` rechazado con `23514` | `PostgresRestockerZoneSchemaTest` · `RestockerRbacConstitutionalTest` | ✅ |
| **QA 1** Paridad `role_in_zone` + `is_primary` y entidad `RESTOCKER_ZONE` | Coherencia forzada por `CHECK`; auditoría con identidad propia | `PostgresRestockerZoneSchemaTest` · `AuditEntityTypesTest` | ✅ 27/27 · 41/41 |
| **QA 2** Contadores por zona | `primary_restockers_count` y `backup_restockers_count` en listado y detalle | `RestockerZoneCoverageServiceTest` · `coordinator_restocker_tabs.test.mjs` | ✅ |
| **QA 3** Prelación de `MEAL_BREAK` sobre `OFF_SHIFT` | Niveles 3–4 por delante de 5–6 | `RestockerZoneCoverageRankerTest` | ✅ 42/42 |
| **QA 4** Idempotencia y visibilidad `C5` | Baja repetida sin evento nuevo; activos con 0 sesiones + inactivos con actividad | `RestockerRoleZonesShiftsApiTest` · `PdoWorkHoursRoleFilterTest` | ✅ 21/21 · 18/18 |
| **§6.1** Coexistencia SAT/carga en la misma zona | `TEC-01` y `REP-01` titulares simultáneos sin colisión | `RestockerRbacConstitutionalTest` · `RestockerRoleZonesShiftsApiTest` | ✅ |
| **§6.4** Traslado de titularidad | `409` sin confirmación; con `confirm_primary_transfer` extingue la previa en una transacción | `PdoRestockerZoneRepositoryTest` · `coordinator_restocker_tabs.test.mjs` | ✅ |

---

## 4. Definición de Hecho (§8)

| DoD | Evidencia |
|---|---|
| **1.** Rol en enum, CRUD con `is_on_call = FALSE`, rol inmutable y cierre en baja | `DomainEnumsTest` · `AdminStaffRestockerServiceTest` 26/26 · `PostgresRestockerZoneSchemaTest` 27/27 |
| **2.** RBAC `403` y exclusión del triaje SAT | `RestockerRbacConstitutionalTest` 27/27 · `TechnicianAssignmentSuggesterTest` 41/41 |
| **3.** Tabla 41, guardas, auditoría, contadores y CRUD idempotente | `PostgresRestockerZoneSchemaTest` 27/27 · `PdoRestockerZoneRepositoryTest` 26/26 · `RestockerRoleZonesShiftsApiTest` 21/21 |
| **4.** Cobertura determinista de 6 niveles y promoción del `BACKUP` | `RestockerZoneCoverageRankerTest` 42/42 · `RestockerZoneCoverageServiceTest` 23/23 |
| **5.** Vista móvil con ciclo de turno, cierre directo, contador y «Mis Horas» | `restocker_route_view.test.mjs` 54/54 · `RestockerRoleZonesShiftsApiTest` 21/21 |
| **6.** Coordinación con filtro por rol, `C5` e insignia/columna de rol | `coordinator_restocker_tabs.test.mjs` 43/43 · `CoordinatorAbsencesTabTest` 33/33 · `CoordinatorWorkHoursTabTest` 58/58 |
| **7.** Batería global al 100 % en verde | `php tests/run_all.php` (ver [`README.md`](../../README.md#resumen-de-ejecución-global-noviembre-2026)) |

---

## 5. Mantenimiento

Esta matriz es un artefacto vivo: cualquier cambio en un `RF`, criterio EARS, `RNF`, decisión `D`, caso límite `C` o resolución `QA` debe actualizar **la fila correspondiente y el resultado medido** en la misma entrega. La cifra viva de suites y aserciones se declara **solo** en el README; el resto de documentos la referencia sin duplicarla.
