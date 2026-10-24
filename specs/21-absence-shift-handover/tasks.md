# TAREAS DE IMPLEMENTACIÓN: GESTIÓN DE AUSENCIAS TEMPORALES Y RELEVO DE FIN DE TURNO

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 21 · Gestión de Ausencias Temporales y Relevo de Fin de Turno  
**Documento:** `specs/21-absence-shift-handover/tasks.md`  
**Spec:** [`spec.md`](spec.md) (v2) · **Plan:** [`plan.md`](plan.md) · **Contratos:** [`../../specs/technical/absence_shift_handover_contracts.md`](../technical/absence_shift_handover_contracts.md)  
**Política de pruebas:** *test-first*. Cada tarea declara su prueba en ROJO antes de implementar. Cierra con `php tests/run_all.php` y las suites `tests/unit/*.mjs`.  
**Rama de trabajo:** `21-absence-shift-handover`. **Estrategia de entrega:** `ask-on-risk`.

---

## Resumen de Work Units

| WU | Tareas | Contenido |
| :--- | :--- | :--- |
| **WU-1 · Esquema** | T-ASH-01 | Tablas 29 y 30, índices parciales, contrato de correspondencia, guardas y purga |
| **WU-2 · Dominio y repositorios** | T-ASH-02, T-ASH-03 | Entidades inmutables, enums, repositorios PDO de ausencias y turnos |
| **WU-3 · Servicios de aplicación** | T-ASH-04, T-ASH-05 | Ciclo de turno, relevo atómico, protocolo sanitario Art. II y reasignación asistida |
| **WU-4 · Recomendador territorial** | T-ASH-06 | Integración de disponibilidad en `TechnicianAssignmentSuggester` y promoción de suplente |
| **WU-5 · API REST y controladores** | T-ASH-07, T-ASH-08 | Controladores de Coordinación y Técnico, rutas y suites de integración HTTP |
| **WU-6 · Interfaces frontend** | T-ASH-09, T-ASH-10 | Barra de turno móvil, modal de relevo y pestaña de ausencias en Coordinación |
| **WU-7 · Guardas y certificación** | T-ASH-11 | Catálogo de `audit_log`, sincronización de cifras y batería global al 100% |

---

## Detalle de Tareas

### T-ASH-01 · Esquema de Base de Datos y Contratos de Correspondencia
* **Refs:** RF-01.1, RF-03.2, R3, R5 · Contratos §1.1, §1.2
* **Ruta:** direct inline
* **Prueba en rojo:** `tests/integration/PostgresAbsenceShiftSchemaTest.php` + ajuste de guardas de esquema existentes.
* **Alcance:**
  * `database/supabase_migrations/012_technician_absences_and_shifts.sql` (tablas 29 y 30, índices, triggers).
  * `database/supabase_init.sql` (incorporación canónica de tablas 29 y 30).
  * `specs/technical/schema_correspondence_contract.md`.
  * `CONTRACT_TABLES` en `tests/integration/SchemaConvergenceParityTest.php` y `tests/integration/PostgresSchemaTest.php`.
  * `tests/Support/TestDataCleaner.php` (registro de tablas para aislamiento de tests).
* **Criterio de cierre:** Tablas creadas con paridad exacta migración/init, índices parciales `WHERE closed_at IS NULL` verificados y suite de esquema en verde.
* **Riesgo:** `item 1` (esquema persistente nuevo).

---

### T-ASH-02 · Entidades de Dominio y Enums
* **Refs:** RF-01.1, RF-03.2–03.4 · R5, R10 · Contratos §2
* **Ruta:** direct inline
* **Prueba en rojo:** `tests/unit/TechnicianAbsenceDomainTest.php` y `tests/unit/TechnicianShiftDomainTest.php`.
* **Alcance:**
  * `src/Core/Domain/ValueObject/AbsenceReason.php`.
  * `src/Core/Domain/ValueObject/ShiftStatus.php`.
  * `src/Core/Domain/Model/TechnicianAbsence.php`.
  * `src/Core/Domain/Model/TechnicianShift.php`.
  * `src/Core/Domain/Exception/TechnicianAbsenceValidationException.php`.
  * `src/Core/Domain/Exception/TechnicianShiftValidationException.php`.
* **Criterio de cierre:** Validación de rangos de fechas en ausencias, transiciones legales de turno (`OFF_SHIFT` -> `ON_SHIFT` <-> `MEAL_BREAK` -> `OFF_SHIFT`), cierre directo desde comida y rechazo de estados ilegales en verde.
* **Riesgo:** `none`.

---

### T-ASH-03 · Puertos y Repositorios PDO de Ausencias y Turnos
* **Refs:** RF-01.2–01.4, RF-03.1, RF-03.5 · R3, R5 · Contratos §3
* **Ruta:** direct inline
* **Prueba en rojo:** Tests de integración contra base de datos en `tests/integration/PdoTechnicianAbsenceRepositoryTest.php` y `tests/integration/PdoTechnicianShiftRepositoryTest.php`.
* **Alcance:**
  * `src/Core/Domain/Repository/TechnicianAbsenceRepositoryInterface.php`.
  * `src/Core/Domain/Repository/TechnicianShiftRepositoryInterface.php`.
  * `src/Infrastructure/Repository/PdoTechnicianAbsenceRepository.php`.
  * `src/Infrastructure/Repository/PdoTechnicianShiftRepository.php`.
* **Criterio de cierre:** Creación, detección de solapamiento de fechas, cancelación lógica con motivo, inicio/pausa/cierre de turnos y mapa de turnos activos funcionando contra PostgreSQL.
* **Riesgo:** `none`.

---

### T-ASH-04 · Servicios de Aplicación: Ausencias y Ciclo de Turno
* **Refs:** RF-01.1–01.3, RF-02.1, RF-03.2–03.4, RF-07 · R4 · Contratos §4
* **Ruta:** direct inline
* **Prueba en rojo:** `tests/unit/TechnicianAbsenceServiceTest.php` y `tests/unit/ShiftLifecycleServiceTest.php`.
* **Alcance:**
  * `src/Application/Service/TechnicianAbsenceService.php`.
  * `src/Application/Service/ShiftLifecycleService.php`.
  * `src/Application/DTO/TechnicianAbsenceDto.php`.
  * `src/Application/DTO/TechnicianShiftDto.php`.
* **Criterio de cierre:** Alta de ausencia con propuesta de tickets afectados, override de ausencia al iniciar turno con aviso en `audit_log`, pausas de comida y cierre forzado administrativo por coordinador.
* **Riesgo:** `none`.

---

### T-ASH-05 · Servicio de Relevo de Fin de Turno y Protocolo Sanitario Art. II
* **Refs:** RF-04.1–04.3, RF-05.1–05.4, RF-06.1–06.2 · R1, R2, R7, R8, R9 · Contratos §4.1
* **Ruta:** direct inline
* **Prueba en rojo:** `tests/unit/ShiftHandoverServiceTest.php` y `tests/integration/ShiftHandoverSanitaryTest.php`.
* **Alcance:**
  * `src/Application/Service/ShiftHandoverService.php`.
  * `src/Application/DTO/ShiftHandoverResultDto.php`.
* **Criterio de cierre:** Bloqueo fail-fast si hay tickets en `IN_PROGRESS`; traspaso atómico de `ASSIGNED`, `PENDING_PARTS` y `PENDING_INFO` con nota interna confidencial (`is_internal = TRUE`); detección de colisiones concurrentes (`409 CONFLICT`); protocolo sanitario fail-safe de frío en 2 niveles (traspaso a guardia 24/7 o desasignación con alerta crítica a Coordinación); notificación encolada para Telegram.
* **Riesgo:** `item 2` (salvaguarda sanitaria y guardia de frío Art. II).

---

### T-ASH-06 · Integración en el Recomendador Territorial
* **Refs:** RF-08.1–08.5 · R4, R6 · Contratos §4
* **Ruta:** direct inline
* **Prueba en rojo:** `tests/unit/TechnicianAssignmentSuggesterAbsenceTest.php`.
* **Alcance:**
  * `src/Infrastructure/Repository/PdoTechnicianSuggestionRepository.php` (inyección indexada de turno y ausencia en la foto).
  * `src/Application/Service/TechnicianAssignmentSuggester.php` (ordenación con no disponibles al final, badges explicativos y promoción automática del `BACKUP` de zona a recomendado).
* **Criterio de cierre:** Un titular ausente o fuera de turno recibe `NO_DISPONIBLE` con motivo; el suplente de zona pasa a `#1`; el técnico de guardia conserva disponibilidad para frío aunque esté en `OFF_SHIFT`.
* **Riesgo:** `none`.

---

### T-ASH-07 · API de Coordinación (Ausencias y Supervisión)
* **Refs:** RF-01.1–01.4, RF-02.1–02.3, RF-07.1–07.3 · Contratos §5.1
* **Ruta:** direct inline
* **Prueba en rojo:** `tests/integration/CoordinatorAbsenceApiTest.php` y `tests/integration/CoordinatorShiftApiTest.php`.
* **Alcance:**
  * `src/Presentation/Controller/CoordinatorAbsenceController.php`.
  * `src/Presentation/Controller/CoordinatorShiftController.php`.
  * `src/Presentation/Routing/AppRouter.php` (registro de rutas).
* **Criterio de cierre:** 401/403/404/409/422/201/200 con precedencias fail-fast; listado filtrado, alta con sugerencias de reasignación, cancelación con motivo y cierre forzado auditado en verde.
* **Riesgo:** `none`.

---

### T-ASH-08 · API Móvil del Técnico (Jornada y Relevo)
* **Refs:** RF-03.2–03.5, RF-04.1–04.3, RF-05.1–05.4, RF-06.1–06.2 · Contratos §5.1
* **Ruta:** direct inline
* **Prueba en rojo:** `tests/integration/TechnicianShiftApiTest.php` y `tests/integration/TechnicianShiftConstitutionalTest.php`.
* **Alcance:**
  * `src/Presentation/Controller/TechnicianShiftController.php`.
  * `src/Presentation/Routing/AppRouter.php` (registro de rutas bajo `InternalAuthMiddleware(TECHNICIAN)`).
* **Criterio de cierre:** Flujo completo móvil: `status`, `start`, `pause-meal`, `resume` y `close-handover` con bloqueo ante `IN_PROGRESS`, traspaso atómico y blindaje constitucional (Art. V.4: notas internas ocultas al portal de sede).
* **Riesgo:** `none`.

---

### T-ASH-09 · Frontend Móvil del Técnico (`TechnicianRouteView.js`)
* **Refs:** RF-03.2–03.5, RF-04.1–04.3, RF-05.1–05.4, RF-06.1 · EARS §5
* **Ruta:** direct inline
* **Prueba en rojo:** `tests/unit/TechnicianShiftViewTest.mjs`.
* **Alcance:**
  * `public/assets/js/views/TechnicianRouteView.js`.
  * `public/assets/js/components/ShiftHandoverModal.js` **(nuevo)**.
  * `public/assets/js/api.js` (añadir métodos `technicianShift`).
* **Criterio de cierre:** Widget superior reactivo con estados (En Turno, Pausa Comida, Fuera de Turno); modal de cierre con bloqueo ante `IN_PROGRESS`, selección de relevo, casilla de retención y banner informativo de protocolo de frío.
* **Riesgo:** `none`.

---

### T-ASH-10 · Frontend de Coordinación (Ausencias y Supervisión)
* **Refs:** RF-01.1–01.4, RF-07.1, RF-08.3 · EARS §5
* **Ruta:** direct inline
* **Prueba en rojo:** `tests/unit/CoordinatorAbsenceViewTest.mjs`.
* **Alcance:**
  * `public/assets/js/views/CoordinatorDashboardView.js`.
  * `public/assets/js/components/CoordinatorAbsenceModal.js` **(nuevo)**.
  * `public/assets/js/components/SuggestedTechniciansModal.js`.
  * `public/assets/js/api.js` (añadir métodos `coordinatorAbsence`).
* **Criterio de cierre:** Tabla de técnicos con chip de estado en vivo y botón de forzar cierre; sub-pestaña de gestión de ausencias con modal de alta/cancelación y reasignación asistida; badges de indisponibilidad en modal de asignación.
* **Riesgo:** `none`.

---

### T-ASH-11 · Guardas de Auditoría, Cifras y Certificación Final
* **Refs:** Art. I, Art. III.3, Art. VII · Contratos §6
* **Ruta:** direct inline
* **Prueba en rojo:** `tests/unit/ConstitutionalAuditTest.php`, `tests/unit/DocMetricsGuardTest.php`.
* **Alcance:**
  * `public/assets/js/utils/AuditActionLabels.js` (incorporación de las nuevas etiquetas de auditoría).
  * `tests/unit/ConstitutionalAuditTest.php` (registro de los 10 nuevos eventos).
  * `docs/features_pendientes.md` (marcar Feature 10 como 100% implementada).
  * Ejecución completa de la batería global: `php tests/run_all.php` y suites `.mjs`.
* **Criterio de cierre:** 100% de aserciones en verde, paridad certificada, cero acciones fantasma en auditoría y cierre formal del módulo.
* **Riesgo:** `none`.
