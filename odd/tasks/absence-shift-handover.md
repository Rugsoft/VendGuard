# Feature: Gestión de Ausencias Temporales y Relevo de Fin de Turno (Módulo 21)

**Rama:** `absence-shift` · **Entrega:** 7 WU + 1 PR final · **Batería:** `php tests/run_all.php` (el motor `new_engine` se ejecuta aparte con `deploy/local/postgres/.env.local`).
**Fuente de verdad:** [`specs/21-absence-shift-handover/spec.md`](../../specs/21-absence-shift-handover/spec.md) v2.0 (R1–R10), [`plan.md`](../../specs/21-absence-shift-handover/plan.md), [`tasks.md`](../../specs/21-absence-shift-handover/tasks.md), [`contratos`](../../specs/technical/absence_shift_handover_contracts.md).

## Specs

S1 — **Esquema de persistencia e inmutabilidad (RF-01, RF-03, Art. III.1).** Tablas 29 (`technician_absences`) y 30 (`technician_shifts`) con índices, FKs hacia `users`, restricciones CHECK y disparador constitucional `prevent_hard_delete()`. Unicidad de turno abierto con índice parcial `UNIQUE (technician_id) WHERE closed_at IS NULL` (R3).

S2 — **Entidades inmutables y Enums de dominio (RF-01, RF-03).** `AbsenceReason` (`VACATION`, `SICK_LEAVE`, `TRAINING`, `PERSONAL_LEAVE`, `COMPENSATORY`, `OTHER`), `ShiftStatus` (`OFF_SHIFT`, `ON_SHIFT`, `MEAL_BREAK`), `TechnicianAbsence`, `TechnicianShift` y excepciones de dominio tipificadas.

S3 — **Repositorios PDO y consultas indexadas (RF-01, RF-03, RNF-01).** `PdoTechnicianAbsenceRepository` y `PdoTechnicianShiftRepository` con transacciones seguras, sin N+1 y respeto de claves foráneas.

S4 — **Gestión de ausencias desde Coordinación (RF-01).** Alta `POST /api/coordinator/technicians/{id}/absences` con validación de fechas (`INVALID_DATE_RANGE`, `ends_at >= starts_at`), solapamiento (`ABSENCE_OVERLAP`), días completos por defecto (R5). Cancelación lógica `DELETE /api/coordinator/absences/{id}` con motivo obligatorio $\ge 5$ caracteres (`CANCELLED_REASON_TOO_SHORT`). Listado con filtros `GET /api/coordinator/technicians/absences`.

S5 — **Asistente de reasignación ante ausencia (RF-02).** Alta de ausencia devuelve incidencias afectadas activas y propuesta del recomendador para reasignación asistida.

S6 — **Ciclo de turno en ruta móvil (RF-03).** `POST /api/technician/shift/start` inicia turno (`ON_SHIFT`). `POST /api/technician/shift/pause-meal` pasa a `MEAL_BREAK`. `POST /api/technician/shift/resume` reanuda a `ON_SHIFT`. Precedencia de presencia física sobre ausencia planificada registrando `ABSENCE_OVERRIDE_BY_SHIFT_START` (R4). Cierre directo desde `MEAL_BREAK` permitido (R10).

S7 — **Guardia estricta contra averías en curso (RF-04, D4).** Bloqueo fail-fast si hay tickets en `IN_PROGRESS` ante cualquier cierre de turno: `422 UNPROCESSABLE_ENTITY` con `HAS_IN_PROGRESS_INCIDENTS`.

S8 — **Relevo de fin de turno y concurrencia (RF-05, R2, R8, R9).** Traspaso atómico de averías en `ASSIGNED`, `PENDING_PARTS` y `PENDING_INFO` con cerrojo `SELECT ... FOR UPDATE`, nota interna obligatoria $\ge 10$ caracteres (`is_internal = TRUE`, Art. V.4), alerta a Telegram del receptor y detección de colisión `409 CONFLICT` con `CONCURRENT_ROUTE_MODIFICATION`. Órdenes preventivas excluidas del relevo (R7).

S9 — **Protocolo sanitario fail-safe Art. II en cierre de turno (RF-06, R1).** Al cerrar turno sin relevo con averías `FOOD_PERISHABLE`: Nivel 1 traspaso automático a técnico de guardia 24/7 (`is_on_call = TRUE`) con alerta Telegram; Nivel 2 desasignación a `REGISTERED` y alarma crítica a Coordinación. Las averías ordinarias se retienen en el técnico saliente (`OFF_SHIFT`).

S10 — **Guardia pasiva 24/7 (RF-06, R6).** El técnico con `is_on_call = TRUE` pasa a `OFF_SHIFT` en su jornada ordinaria pero permanece disponible para emergencias sanitarias de frío.

S11 — **Cierre forzado por Coordinación (RF-07, D9).** `POST /api/coordinator/technicians/{id}/shifts/force-close` con motivo $\ge 10$ caracteres, verificación de guardia `IN_PROGRESS`, protocolo sanitario y evento `FORCE_CLOSE_SHIFT` en `audit_log`.

S12 — **Recomendador territorial asistido (RF-08, D8, R4, R6).** `TechnicianAssignmentSuggester` excluye o relega a ausentes / fuera de turno al final con badge `NO_DISPONIBLE`, promueve automáticamente al suplente (`BACKUP`) a `RECOMENDADO ⭐` si el titular no está disponible.

S13 — **Interfaces de usuario: móvil y coordinación (RF-01, RF-03, RF-05).** Barra de turno y modal de relevo en `TechnicianRouteView.js` con diseño Docker (DM Sans, Inter, `#2560ff`, radio 4px); pestaña de ausencias en panel de Coordinación con formulario y modal asistido.

S14 — **Auditoría inmutable y métricas (RF-01, RF-07, Art. III.3).** Catálogo de eventos en `audit_log`, sincronización de métricas documentales y cero borrado físico.

## Tasks

| ID | Specs | Ruta | Commit | Estado |
| :--- | :--- | :--- | :--- | :--- |
| **WU-1 / T-ASH-01** | S1 | inline | `feat(db): add technician absences and shifts schema migration 012` (`064fd1e`) | ✅ hecho |
| **WU-2 / T-ASH-02** | S2 | inline | `feat(domain): define absence and shift entities, enums and domain exceptions` (`67be86c`) | ✅ hecho |
| **WU-2 / T-ASH-03** | S3 | inline | `feat(repository): implement PDO repositories for technician absences and shifts` (`3669367`) | ✅ hecho |
| **WU-3 / T-ASH-04** | S4, S5, S6, S11 | inline | `feat(service): implement absence management and shift lifecycle services` (`6af6c87`) | ✅ hecho |
| **WU-3 / T-ASH-05** | S7, S8, S9, S10 | inline | `feat(service): implement shift handover service and sanitary fail-safe protocol` (`83b8be4`) | ✅ hecho |
| **WU-4 / T-ASH-06** | S12 | inline | `feat(suggester): integrate shift status and absence availability into territorial suggester` (`67e5fe0`) | ✅ hecho |
| **WU-5 / T-ASH-07** | S4, S5, S11 | inline | pendiente | ⏳ en curso |
| **WU-5 / T-ASH-08** | S6, S7, S8, S9 | inline | pendiente | ⏳ en curso |
| **WU-6 / T-ASH-09** | S13 | inline | pendiente | ⏸️ pendiente |
| **WU-6 / T-ASH-10** | S13 | inline | pendiente | ⏸️ pendiente |
| **WU-7 / T-ASH-11** | S14 | inline | pendiente | ⏸️ pendiente |

## Log

* **L1 (2026-10-23):** Inicio de especificación e implementación del Módulo 21 (Feature 10: Shift Handover & Absence Coverage). Aprobación de spec v2.0 con resoluciones R1–R10 y superación de la Puerta SDD Fase 1.
* **L2 (2026-10-23):** Finalizado WU-1 (T-ASH-01): migración `012_technician_absences_and_shifts.sql`, actualización de `supabase_init.sql`, contratos de esquema a 30 tablas y suites en verde (`PostgresAbsenceShiftSchemaTest`, `PostgresSchemaTest`, `PostgresConstitutionalTest`, `SchemaConvergenceParityTest`, `CloudDeploySchemaParityTest`, `TestDataCleanerIsolationTest` y batería `tests/run_all.php` 325/325 100% PASS). Commit `064fd1e`.
* **L3 (2026-10-23):** Finalizado WU-2 (T-ASH-02 y T-ASH-03): entidades inmutables `TechnicianAbsence` y `TechnicianShift`, enums `AbsenceReason` y `ShiftStatus`, excepciones de dominio (`ActiveIncidentInProgressException`, `InvalidAbsenceException`, `InvalidShiftTransitionException`), interfaces y repositorios PDO (`PdoTechnicianAbsenceRepository`, `PdoTechnicianShiftRepository`). Suites de test unitarias y de integración pasando 100% (21/21, 32/32, 17/17, 18/18). Commits `67be86c` y `3669367`.
* **L4 (2026-10-23):** Finalizado WU-3 (T-ASH-04 y T-ASH-05): servicios de aplicación `TechnicianAbsenceService`, `ShiftLifecycleService` y `ShiftHandoverService` con DTOs (`TechnicianAbsenceDto`, `TechnicianShiftDto`, `ShiftHandoverResultDto`). Protocolo sanitario de frío Art. II en 2 niveles certificado en integración, bloqueo fail-fast ante averías en curso y notas internas obligatorias (`is_internal = TRUE`). Suites unitarias e integración al 100% (8/8, 12/12, 10/10, 8/8). Commits `6af6c87` y `83b8be4`.
* **L5 (2026-10-23):** Finalizado WU-4 (T-ASH-06): integración de turnos y ausencias en `TechnicianAssignmentSuggester` y `PdoTechnicianSuggestionRepository`. Relegación de no disponibles al final con insignia `NO_DISPONIBLE`, promoción del suplente (`BACKUP`) a `RECOMENDADO ⭐` ante titular ausente y persistencia de guardia en emergencias de frío (R6). Suites unitarias e integración al 100% (35/35, 11/11, 23/23). Commit `67e5fe0`.
