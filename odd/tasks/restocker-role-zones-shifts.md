# Feature: Rol de Cargador/Reponedor de Ruta (`RESTOCKER`), Zonificación de Carga y Control de Jornada (Módulo 31)

**Rama:** `feat/restocker-role-zones-shifts` · **Entrega:** 7 WU (14 tareas `T-RES-01` a `T-RES-14`) + 1 PR final · **Batería:** `php tests/run_all.php`.
**Fuente de verdad:** [`specs/31-restocker-role-zones-shifts/spec.md`](../../specs/31-restocker-role-zones-shifts/spec.md) v2.0 (`D1`–`D3`, `C1`–`C3`, `QA 1`–`QA 4`), [`plan.md`](../../specs/31-restocker-role-zones-shifts/plan.md), [`tasks.md`](../../specs/31-restocker-role-zones-shifts/tasks.md), [`contratos`](../../specs/technical/restocker_role_zones_shifts_contracts.md).

## Specs

S1 — **Esquema PostgreSQL e Inmutabilidad de Tabla 41 `restocker_zone_assignments` (RF-01.1, RF-02.1, D3, QA 1, Art. III.1).** Ampliación de `user_role` con `'RESTOCKER'` y `audit_entity_type` con `'RESTOCKER_ZONE'`. Constraint `chk_users_restocker_not_on_call` (`role <> 'RESTOCKER' OR is_on_call = FALSE`). Tabla 41 `restocker_zone_assignments` con `role_in_zone` (`'PRIMARY'` | `'BACKUP'`) e `is_primary` sincronizados por `chk_restocker_zone_role_coherence`, índices únicos parciales `uq_restocker_zone_live` (`WHERE removed_at IS NULL`) y `uq_restocker_primary_zone` (`WHERE is_primary = TRUE AND removed_at IS NULL`), y disparador constitucional `trg_no_delete_restocker_zone_assignments` (`prevent_hard_delete()`).

S2 — **Rol `RESTOCKER`, CRUD de Personal Interno e Inmutabilidad de Rol (RF-01.1, RF-01.2, RF-01.3, D3, C1, C3).** En `POST /api/coordinator/staff`, creación de usuarios `RESTOCKER` exigiendo `operator_code` único y forzando `is_on_call = FALSE` (`422 RESTOCKER_CANNOT_BE_ON_CALL` si se envía `true`). En `PUT /api/coordinator/staff/{id}`, el `role` es estrictamente inmutable (`409 ROLE_CHANGE_FORBIDDEN`, `C1`). Al desactivar (`is_active = FALSE`) un usuario con turno abierto (`closed_at IS NULL`), el sistema cierra automáticamente el turno en la misma transacción registrando `FORCE_CLOSE_SHIFT` con motivo `"Cierre automático por desactivación de cuenta de usuario"` (`C3`).

S3 — **Segregación RBAC entre `RESTOCKER` y `TECHNICIAN` (RF-01.4, Art. V.3, Art. V.4).** Bloqueo con `403 Forbidden` (`FORBIDDEN_ROLE`) si un usuario `RESTOCKER` invoca endpoints exclusivos de reparación SAT (incidencias, alta oportunista, stock de repuestos en furgoneta SAT, órdenes de repuestos, preventivos o taller). Exclusión absoluta de usuarios `RESTOCKER` en los selectores de asignación de averías y en `TechnicianAssignmentSuggester`.

S4 — **Cuadrante de Carga por Zona Operativa, Contadores y Baja Lógica Idempotente (RF-02.1–RF-02.4, D2, D3, C3, QA 2, QA 4).** Exposición de `primary_restockers_count` y `backup_restockers_count` en `GET /api/coordinator/zones` y `/zones/{id}` (`QA 2`). Alta en `POST /api/coordinator/zones/{id}/restockers` validando usuario `RESTOCKER` activo (`422 INVALID_RESTOCKER_USER`) y exigiendo `confirm_primary_transfer = true` si ya es `PRIMARY` vivo en otra zona (`409 PRIMARY_ZONE_ALREADY_ASSIGNED`). Baja lógica idempotente en `DELETE /api/coordinator/zones/{id}/restockers/{restocker_id}` (`200 OK` sin duplicar auditoría si ya estaba extinguida, `QA 4`). Liberación conjunta en desactivación de zona con `confirm_release = true`. Consulta de cuadrante `GET /api/coordinator/zones/{id}/restockers` (mostrando cuentas de baja con insignia «Baja», `C3`) y consulta inversa `GET /api/coordinator/restockers/quadrants`.

S5 — **Motor Determinista de Cobertura de Carga y Promoción Automática del `BACKUP` (RF-03.1–RF-03.3, D3, C2, QA 3).** `RestockerZoneCoverageRanker` y `RestockerZoneCoverageService` (`GET /api/coordinator/zones/{id}/restocker-coverage`) evalúan los 6 niveles activos + `UNAVAILABLE`: (1) `PRIMARY_ON_SHIFT`, (2) `BACKUP_PROMOTED_ON_SHIFT`, (3) `PRIMARY_MEAL_BREAK`, (4) `BACKUP_PROMOTED_MEAL_BREAK`, (5) `PRIMARY_PLANNED_OFF_SHIFT`, (6) `BACKUP_PROMOTED_OFF_SHIFT`, y (7) `UNAVAILABLE` (`OFF_SHIFT` con ausencia vigente o `is_active = FALSE`). Desempate por `assigned_at ASC, operator_code ASC, id ASC`. Designación del `#1 RECOMENDADO ⭐` con etiqueta de promoción automática cuando encabeza un `BACKUP`. Precedencia de presencia física al iniciar turno durante ausencia (`SHIFT_STARTED_DURING_ABSENCE`).

S6 — **Control de Jornada y Vista Móvil `RestockerRouteView.js` con Cierre Directo (RF-04.1–RF-04.3, D1, Art. V.4).** Endpoint `GET /api/restocker/my-route` y componente móvil `RestockerRouteView.js` exclusivo para `RESTOCKER` con insignias de zonas (`PRIMARY` y `BACKUP`), barra de turno (`ON_SHIFT`, `MEAL_BREAK`, `OFF_SHIFT`), contador diario en vivo, modal «⏱️ Mis Horas» aislado por el `user_id` del token, y cierre directo de turno (incluso desde `MEAL_BREAK`) sin modal de relevo de averías SAT (`D1`).

S7 — **Gestión Unificada de Ausencias, Turnos y Registro de Jornada + CSV con Filtro por Rol en Coordinación (RF-05.1, RF-05.2, D2, QA 4).** Filtro `role=ALL|TECHNICIAN|RESTOCKER` en Ausencias, Supervisión de Turnos y sub-pestaña «⏱️ Registro de Jornada y Horas». Al crear ausencia de `RESTOCKER`, se omite el asistente de averías SAT y se informan las zonas con promoción automática de `BACKUP`. En Registro de Horas se aplica la regla `C5` por rol (`QA 4`), reportando `0` intervenciones y `0s` en máquina para `RESTOCKER`, y añadiendo la columna `Rol` en las dos secciones del archivo CSV UTF-8 BOM (`;`).

## Tasks

| ID | Specs | Ruta | Commit | Estado |
| :--- | :--- | :--- | :--- | :--- |
| **WU-1 / T-RES-01** | S1 | inline | `ffed623` | ✅ hecho |
| **WU-1 / T-RES-02** | S1 | inline | pendiente | ⏳ pendiente |
| **WU-2 / T-RES-03** | S1, S5 | inline | pendiente | ⏳ pendiente |
| **WU-2 / T-RES-04** | S3 | inline | pendiente | ⏳ pendiente |
| **WU-3 / T-RES-05** | S4 | inline | pendiente | ⏳ pendiente |
| **WU-3 / T-RES-06** | S2, S7 | inline | pendiente | ⏳ pendiente |
| **WU-4 / T-RES-07** | S2 | inline | pendiente | ⏳ pendiente |
| **WU-4 / T-RES-08** | S4, S5 | inline | pendiente | ⏳ pendiente |
| **WU-4 / T-RES-09** | S6, S7 | inline | pendiente | ⏳ pendiente |
| **WU-5 / T-RES-10** | S2, S3, S4, S5, S6, S7 | inline | pendiente | ⏳ pendiente |
| **WU-5 / T-RES-11** | S1, S2, S3, S4, S5, S6, S7 | inline | pendiente | ⏳ pendiente |
| **WU-6 / T-RES-12** | S6 | inline | pendiente | ⏳ pendiente |
| **WU-6 / T-RES-13** | S2, S4, S5, S7 | inline | pendiente | ⏳ pendiente |
| **WU-7 / T-RES-14** | S1–S7 | inline | pendiente | ⏳ pendiente |

## Log

* **L1 (2026-11-11):** Petición original del usuario: `"antes de empezar con el mecanismo sdd y escribir la spec, vamos a definir un poco esta feature de docs/features_pendientes-md: 21. 👤 Rol de Cargador/Reponedor de Ruta (RESTOCKER), Zonificación de Carga y Control de Jornada"`.
* **L2 (2026-11-11):** Consensuadas con el Product Owner las decisiones de diseño `D1`–`D3`, los casos límite `C1`–`C3` y las resoluciones de auditoría QA `QA 1`–`QA 4`. Superadas las Puertas SDD de Fase 1 y Fase 2 (`spec.md` v2.0, `restocker_role_zones_shifts_contracts.md` v1.0, `plan.md` v1.0 y `tasks.md` v1.0). Creación de `odd/tasks/restocker-role-zones-shifts.md` y apertura de la rama `feat/restocker-role-zones-shifts`.
* **L3 (2026-11-11):** **T-RES-01 completada y certificada** (commit `ffed623`). Migración `027_restocker_role_and_zone_assignments.sql` con los valores `RESTOCKER` y `RESTOCKER_ZONE`, la guarda `chk_users_restocker_not_on_call` y la Tabla 41 `restocker_zone_assignments`; convergencia 1:1 en `supabase_init.sql` (41 tablas), semillas `REP-01`/`REP-02` en `supabase_seeds.sql` y `SeedRunner::seedRestockerZoneAssignments()`. La guarda compara `role::text` porque el literal del enumerado devuelve `55P04` en la transacción que lo añade (verificado en el motor y documentado en la migración).
  **Evidencia:** `SchemaConvergenceParityTest` 29/29 (41 tablas + libro de 27 migraciones), `SeedRunnerSingleEngineTest` 26/26 y `HarnessResidueGuardTest` 27/27 sobre una base temporal convergida; sonda de esquema con las cuatro guardas mordiendo (`chk_users_restocker_not_on_call`, `chk_restocker_zone_role_coherence`, `uq_restocker_zone_live` y `ART_III_1_VIOLATION` en el borrado físico) y ruta de actualización real sobre la base anterior al módulo (27 migraciones aplicadas, `user_role` con `RESTOCKER`, Tabla 41 y disparador presentes, esquema convergido).
  **Desviación declarada:** la restricción `chk_users_restocker_not_on_call` se escribe `role::text <> 'RESTOCKER'` en lugar del literal `role <> 'RESTOCKER'` del contrato técnico (§2.2); la regla es idéntica y la forma literal es imposible en la transacción de la migración. Riesgo asumido: el banco compartido de pruebas (`vendguard_pg_test`) arrastra una deriva previa en la huella de la migración 025 que impide ejecutar el ejecutor completo sobre él; no se corrigió en esta tarea. Siguiente tarea pendiente: **T-RES-02**.
