# PLAN DE TAREAS ATÓMICAS: REGISTRO DE JORNADA Y CÓMPUTO DE HORAS EFECTIVAS

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 22 · Registro de Jornada y Cómputo de Horas Efectivas (*Technician Time Tracking & Work Hours*)  
**Documento Canónico:** `specs/22-technician-time-tracking/tasks.md`  
**Especificación Funcional:** [`spec.md`](spec.md) (v2.0, 2026-10-25) y espejo [`../functional/technician_time_tracking_spec.md`](../functional/technician_time_tracking_spec.md)  
**Contratos Técnicos:** [`../technical/technician_time_tracking_contracts.md`](../technical/technician_time_tracking_contracts.md)  
**Plan Arquitectónico:** [`plan.md`](plan.md)  
**Fecha:** Octubre 2026  

---

## Resumen de Fases y Tareas

| Fase | Tareas | Tiempo Estimado | Propósito |
| :--- | :--- | :--- | :--- |
| **Fase 1: Esquema de Base de Datos PostgreSQL / Supabase** | `T-TIME-01`, `T-TIME-02` | 45–55 min | Migración `015`, convergencia en `supabase_init.sql`, semillas, contrato de correspondencia y test de esquema. |
| **Fase 2: Modelos de Dominio, Value Objects y Excepciones** | `T-TIME-03`, `T-TIME-04` | 45–55 min | `ShiftEventType`, `TechnicianShiftLog`, `ShiftAdjustmentValidationException`, extensión de `TechnicianShift` y tests unitarios de dominio. |
| **Fase 3: Capa de Infraestructura y Repositorios PDO** | `T-TIME-05`, `T-TIME-06` | 45–55 min | `PdoTechnicianShiftLogRepository` y extensión de `PdoTechnicianShiftRepository` (solapamientos, rangos, huérfanos e intervenciones cerradas). |
| **Fase 4: Servicios de Aplicación y Casos de Uso** | `T-TIME-07`, `T-TIME-08` | 50–60 min | Extensión de `ShiftLifecycleService` y `ShiftHandoverService`, nuevo `TechnicianWorkHoursService`, exportación CSV y tests unitarios. |
| **Fase 5: Controladores REST, Router y Suites de Integración HTTP** | `T-TIME-09`, `T-TIME-10` | 50–60 min | Endpoints en `CoordinatorShiftController` y `TechnicianShiftController`, rutas en `AppRouter` y suite de integración `TechnicianTimeTrackingApiTest`. |
| **Fase 6: Interfaz Frontend Vanilla ES Modules y Pruebas ESM** | `T-TIME-11`, `T-TIME-12` | 50–60 min | Cliente `api.js`, `CoordinatorWorkHoursTab.js` en `CoordinatorMetricsView.js`, contador y modal «⏱️ Mis Horas» en `TechnicianRouteView.js` + suites `.mjs`. |
| **Fase 7: Certificación Integral, Regresión Global y Cierre Documental** | `T-TIME-13` | 20–30 min | Batería completa `php tests/run_all.php`, `DocMetricsGuard` y actualización de catálogo y actas. |

---

## Fase 1: Esquema de Base de Datos PostgreSQL / Supabase y Guardas de Esquema

- [x] **T-TIME-01 · Migración `015_technician_time_tracking.sql`, convergencia en `supabase_init.sql` y semillas**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** Ninguna
  * **Ficheros a crear o modificar:**
    * `database/supabase_migrations/015_technician_time_tracking.sql` **(crear)**
    * `database/supabase_init.sql` **(modificar)**
    * `database/supabase_seeds.sql` **(modificar)**
    * `specs/technical/schema_correspondence_contract.md` **(modificar)**
  * **Requisitos que cubre:** `RF-01.1` a `RF-01.4`, `RF-02.1`, `RF-03.3`, `RF-03.4`, `RNF-01`, `RNF-02`, Decisiones `D1` y `D2`, Resoluciones `QA 2` y `QA 3`, Art. III.1 y Art. III.3 de `constitution.md`.
  * **Detalle de implementación:**
    1. Declarar en `015_technician_time_tracking.sql` y en la Tabla 30 de `supabase_init.sql` las columnas aditivas de `technician_shifts`: `meal_break_seconds INT NOT NULL DEFAULT 0`, `original_started_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP`, `original_closed_at TIMESTAMPTZ`, `original_meal_break_seconds INT`, `is_force_closed BOOLEAN NOT NULL DEFAULT FALSE`, `is_adjusted BOOLEAN NOT NULL DEFAULT FALSE`, `adjusted_at TIMESTAMPTZ`, `adjusted_by BIGINT`, `adjustment_reason TEXT`, la FK `fk_tech_shifts_adjuster` y las restricciones `chk_tech_shifts_meal_seconds` y `chk_tech_shifts_adjustment_consistency`.
    2. Declarar la Tabla 31 `technician_shift_logs` con sus claves foráneas (`fk_shift_logs_shift`, `fk_shift_logs_technician`, `fk_shift_logs_actor`), restricciones `chk_shift_logs_event_type` y `chk_shift_logs_adjust_reason`, índices `idx_tech_shift_logs_shift` e `idx_tech_shift_logs_tech`, y los disparadores de inmutabilidad `trg_no_delete_technician_shift_logs` (`prevent_hard_delete()`) y `trg_no_update_technician_shift_logs` (`prevent_service_sheet_update()`).
    3. Añadir semillas de turnos cerrados, pausas acumuladas y bitácora en `supabase_seeds.sql` y documentar la Tabla 31 en `schema_correspondence_contract.md`.
  * **Hecho cuando:** `php bin/init_cloud_db.php` aplica el esquema sin errores y las tablas `technician_shifts` y `technician_shift_logs` contienen todas las columnas, restricciones y disparadores definidos en el contrato técnico §1.

- [x] **T-TIME-02 · Actualización de guardas de esquema y suite `PostgresTimeTrackingSchemaTest`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TIME-01`
  * **Ficheros a crear o modificar:**
    * `tests/Support/TestDataCleaner.php` **(modificar)**
    * `tests/integration/SchemaConvergenceParityTest.php` **(modificar)**
    * `tests/integration/PostgresSchemaTest.php` **(modificar)**
    * `tests/integration/PostgresTimeTrackingSchemaTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-01.1`, `RF-03.3`, `RF-03.4`, `RNF-02`, Resolución `QA 3`, Art. III.1 y Art. III.3 de `constitution.md`.
  * **Detalle de implementación:**
    1. Registrar `technician_shift_logs` en `TestDataCleaner.php` (antes de `technician_shifts`), `SchemaConvergenceParityTest.php` y `PostgresSchemaTest.php` (31 tablas).
    2. Crear `tests/integration/PostgresTimeTrackingSchemaTest.php` verificando: existencia de columnas nuevas en `technician_shifts`, rechazo por `chk_tech_shifts_meal_seconds` cuando `meal_break_seconds < 0` o `> gross_seconds`, rechazo por `chk_tech_shifts_adjustment_consistency` cuando `is_adjusted = true` y el motivo tiene `< 20` caracteres reales, y bloqueo de `UPDATE` y `DELETE` sobre `technician_shift_logs`.
  * **Hecho cuando:** `php tests/integration/PostgresTimeTrackingSchemaTest.php` y `php tests/integration/PostgresSchemaTest.php` pasan al 100 % en verde con código de salida `0`.

---

## Fase 2: Modelos de Dominio, Value Objects y Excepciones

- [x] **T-TIME-03 · Enum `ShiftEventType`, entidad `TechnicianShiftLog` y excepción `ShiftAdjustmentValidationException`**
  * **Tiempo estimado:** 20 min
  * **Dependencias previas:** `T-TIME-02`
  * **Ficheros a crear o modificar:**
    * `src/Core/Domain/ValueObject/ShiftEventType.php` **(crear)**
    * `src/Core/Domain/Model/TechnicianShiftLog.php` **(crear)**
    * `src/Core/Domain/Exception/ShiftAdjustmentValidationException.php` **(crear)**
  * **Requisitos que cubre:** `RF-01.1`, `RF-02.2`, `RF-03.1`, `RF-03.2`, `RF-03.3`, Resoluciones `QA 2` y `QA 3`, Art. III.3 y Art. V.1 de `constitution.md`.
  * **Detalle de implementación:**
    1. Implementar `ShiftEventType` con los 6 casos canónicos (`START_SHIFT`, `PAUSE_MEAL`, `RESUME_SHIFT`, `CLOSE_SHIFT`, `FORCE_CLOSE_SHIFT`, `ADJUST_SHIFT`) y etiquetas en castellano (`label()`).
    2. Implementar la entidad inmutable `TechnicianShiftLog` con hidratación `fromDatabaseRow()` y serialización `toArray()`.
    3. Implementar `ShiftAdjustmentValidationException` con constructores estáticos para cada regla de negocio (`shiftStillOpen`, `reasonTooShort`, `futureTimestampNotAllowed`, `invalidCloseOrder`, `effectiveCloseBeforeLastActivity`, `invalidMealBreakDuration`, `exceedsMaxShiftDuration`, `overlappingShift`).
  * **Hecho cuando:** `php -l src/Core/Domain/ValueObject/ShiftEventType.php && php -l src/Core/Domain/Model/TechnicianShiftLog.php && php -l src/Core/Domain/Exception/ShiftAdjustmentValidationException.php` no reporta errores de sintaxis.

- [x] **T-TIME-04 · Extensión de `TechnicianShift` y suite unitaria `TechnicianShiftTimeTrackingTest`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-TIME-03`
  * **Ficheros a crear o modificar:**
    * `src/Core/Domain/Model/TechnicianShift.php` **(modificar)**
    * `src/Application/DTO/TechnicianShiftDto.php` **(modificar)**
    * `tests/unit/TechnicianShiftTimeTrackingTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-01.2`, `RF-01.3`, `RF-01.4`, `RF-02.1`, `RF-02.2`, `RF-02.4`, `RF-03.1` a `RF-03.4`, `RF-04.1`, `RNF-01`, Decisiones `D1` y `D2`, Casos Límite `C1` y `C4`, Resoluciones `QA 2`, `QA 3` y `QA 4`.
  * **Detalle de implementación:**
    1. Extender `TechnicianShift` para acumular `mealBreakSeconds` en `resume()`, descontar automáticamente el tramo abierto en `close()` cuando el turno está en `MEAL_BREAK`, distinguir `isForceClosed` e `isAdjusted`, conservar `originalStartedAt`, `originalClosedAt` y `originalMealBreakSeconds`, implementar `adjust()` con validación cuádruple (permitiendo `0s` efectivos cuando `mealBreakSeconds == grossSeconds`) y recálculo de `shiftDate` en `Europe/Madrid`, y exponer `grossDurationSeconds()`, `totalMealBreakSeconds()`, `effectiveWorkSeconds()` e `isAnomalousDuration()`.
    2. Actualizar `TechnicianShiftDto` para proyectar todos los campos nuevos y los textos formateados (`HHh MMm`).
    3. Crear `tests/unit/TechnicianShiftTimeTrackingTest.php` probando exhaustivamente múltiples pausas, cierre directo desde comida, turno que cruza medianoche, alerta `> 12h` brutas (`43.200 s`), rectificaciones múltiples conservando `original_*`, ajuste a `0s` efectivos y rechazos de la validación cuádruple.
  * **Hecho cuando:** `php tests/unit/TechnicianShiftTimeTrackingTest.php` y `php tests/unit/TechnicianShiftLifecycleTest.php` pasan al 100 % en verde.

---

## Fase 3: Capa de Infraestructura y Repositorios PDO

- [x] **T-TIME-05 · Repositorio `PdoTechnicianShiftLogRepository` y su interfaz**
  * **Tiempo estimado:** 20 min
  * **Dependencias previas:** `T-TIME-04`
  * **Ficheros a crear o modificar:**
    * `src/Core/Domain/Repository/TechnicianShiftLogRepositoryInterface.php` **(crear)**
    * `src/Infrastructure/Repository/PdoTechnicianShiftLogRepository.php` **(crear)**
  * **Requisitos que cubre:** `RF-01.1`, `RF-03.4`, `RF-05.3`, `RNF-02`, Art. III.3 de `constitution.md`.
  * **Detalle de implementación:**
    1. Definir `TechnicianShiftLogRepositoryInterface` con `record(...)` y `findByShiftId(int $shiftId)`.
    2. Implementar `PdoTechnicianShiftLogRepository` insertando estados en `JSONB` y consultando el historial ordenado por `created_at ASC, id ASC` junto con el nombre y rol del actor.
  * **Hecho cuando:** `php -l src/Infrastructure/Repository/PdoTechnicianShiftLogRepository.php` valida sin errores y el repositorio persiste y recupera entradas en PostgreSQL.

- [x] **T-TIME-06 · Extensión de `PdoTechnicianShiftRepository` para rangos, huérfanos, solapamientos e intervenciones cerradas**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-TIME-05`
  * **Ficheros a crear o modificar:**
    * `src/Core/Domain/Repository/TechnicianShiftRepositoryInterface.php` **(modificar)**
    * `src/Infrastructure/Repository/PdoTechnicianShiftRepository.php` **(modificar)**
  * **Requisitos que cubre:** `RF-01.2` a `RF-01.4`, `RF-02.1`, `RF-02.2`, `RF-03.3`, `RF-03.4`, `RF-04.2`, `RF-04.3`, `RNF-03`, Casos Límite `C1`, `C2` y `C4`, Resoluciones `QA 1`, `QA 3` y `QA 4`.
  * **Detalle de implementación:**
    1. Actualizar `startShift`, `pauseMeal`, `resumeShift` y `closeShift` en `PdoTechnicianShiftRepository` para persistir y leer `meal_break_seconds`, `original_*`, `is_force_closed`, `is_adjusted`, `adjusted_*` y calcular `shift_date` en `Europe/Madrid`.
    2. Implementar `findById()`, `saveAdjustment()`, `findOverlappingShiftId()` (comparación semiabierta `[started_at, closed_at)` contra turnos del mismo técnico), `findShiftsByDateRange()`, `findOrphanOpenShiftsBefore()` y `getClosedInterventionMetricsByTechnician()` (sumando averías resueltas en el periodo descontando pausas + órdenes preventivas completadas en el periodo con `linked_incident_id IS NULL`).
  * **Hecho cuando:** `php -l src/Infrastructure/Repository/PdoTechnicianShiftRepository.php` pasa sin errores y las suites existentes del Módulo 21 siguen al 100 % en verde.

---

## Fase 4: Servicios de Aplicación y Casos de Uso

- [x] **T-TIME-07 · Extensión de `ShiftLifecycleService` y `ShiftHandoverService` (bitácora, `today_summary`, `effective_closed_at` y blindaje Art. II)**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TIME-06`
  * **Ficheros a crear o modificar:**
    * `src/Application/Service/ShiftLifecycleService.php` **(modificar)**
    * `src/Application/Service/ShiftHandoverService.php` **(modificar)**
    * `src/Infrastructure/Notification/ShiftHandoverServiceFactory.php` **(modificar)**
  * **Requisitos que cubre:** `RF-01.1` a `RF-01.3`, `RF-02.1` a `RF-02.3`, `RF-07.1`, Resolución `QA 2`, Art. II, Art. III.3 y Art. V.1 de `constitution.md`.
  * **Detalle de implementación:**
    1. En `ShiftLifecycleService`: registrar en `TechnicianShiftLogRepositoryInterface` los eventos `START_SHIFT`, `PAUSE_MEAL` y `RESUME_SHIFT`, e inyectar en `getCurrentShift()` el bloque `today_summary` con las sesiones y segundos efectivos acumulados por el técnico en la fecha actual (`Europe/Madrid`).
    2. En `ShiftHandoverService`: registrar `CLOSE_SHIFT` en el cierre del técnico y extender `forceCloseShift()` con el parámetro opcional `$effectiveClosedAtRaw`, exigiendo $\ge 20$ caracteres reales cuando se informe, validando contra marcas y pausas previas, comprobando no solapamiento, y ejecutando el protocolo sanitario de frío del Artículo II siempre con `NOW()` real.
  * **Hecho cuando:** Las suites unitarias y de integración del Módulo 21 (`ShiftHandoverServiceTest.php`, `AbsenceShiftHandoverApiTest.php`) pasan al 100 % en verde.

- [x] **T-TIME-08 · Servicio `TechnicianWorkHoursService`, DTO `WorkHoursReportDto` y suite `TechnicianWorkHoursServiceTest`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-TIME-07`
  * **Ficheros a crear o modificar:**
    * `src/Application/DTO/WorkHoursReportDto.php` **(crear)**
    * `src/Application/Service/TechnicianWorkHoursService.php` **(crear)**
    * `tests/unit/TechnicianWorkHoursServiceTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-03.1` a `RF-03.4`, `RF-04.1` a `RF-04.4`, `RF-05.1` a `RF-05.3`, `RF-06.1`, `RF-06.2`, `RF-07.2`, `RF-07.3`, `RF-08.1`, `RNF-01`, Decisiones `D3`, `D4` y `D5`, Casos Límite `C2` a `C5`, Resoluciones `QA 1`, `QA 3`, `QA 4` y `QA 5`.
  * **Detalle de implementación:**
    1. Implementar `TechnicianWorkHoursService` con:
       * `getWorkHoursReport(MetricFilter $filter, ?int $technicianId = null): WorkHoursReportDto` (presencia, turnos `En curso`, turnos abiertos huérfanos previos al filtro, cruce con Tiempo en Intervención In Situ y Tiempo en Ruta con suelo `0`, flag `has_out_of_shift_interventions`, e inclusión de todos los técnicos activos más los inactivos con actividad en el rango).
       * `adjustShift(int $shiftId, string $startedAtRaw, string $closedAtRaw, int $mealBreakSeconds, int $coordinatorId, string $reason): TechnicianShiftDto` bajo transacción PDO y cerrojo `FOR UPDATE`.
       * `getShiftLogs(int $shiftId): array`.
       * `getTechnicianOwnMonthlyHours(int $technicianId): array` (mes actual en `Europe/Madrid`).
       * `exportWorkHoursCsv(MetricFilter $filter, ?int $technicianId = null): string` con UTF-8 BOM (`\xEF\xBB\xBF`), separador `;`, fechas en `Europe/Madrid` y dos secciones separadas por fila en blanco.
    2. Crear `tests/unit/TechnicianWorkHoursServiceTest.php` verificando todos los cálculos, casos límite y estructura del CSV.
  * **Hecho cuando:** `php tests/unit/TechnicianWorkHoursServiceTest.php` pasa al 100 % en verde con código de salida `0`.

---

## Fase 5: Controladores REST, Router y Suites de Integración HTTP

- [ ] **T-TIME-09 · Endpoints en `CoordinatorShiftController`, `TechnicianShiftController` y rutas en `AppRouter`**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TIME-08`
  * **Ficheros a crear o modificar:**
    * `src/Presentation/Controller/CoordinatorShiftController.php` **(modificar)**
    * `src/Presentation/Controller/TechnicianShiftController.php` **(modificar)**
    * `src/Presentation/Routing/AppRouter.php` **(modificar)**
  * **Requisitos que cubre:** `RF-02.1` a `RF-02.3`, `RF-03.1` a `RF-03.4`, `RF-05.1` a `RF-05.3`, `RF-06.1`, `RF-06.2`, `RF-07.2`, `RF-08.1`, `RF-08.2`, Art. V.1 y Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. En `CoordinatorShiftController`: actualizar `forceClose()` para pasar `effective_closed_at` y mapear errores `ShiftAdjustmentValidationException`; añadir `workHoursReport()`, `exportWorkHoursCsv()`, `adjustShift()` y `shiftLogs()`.
    2. En `TechnicianShiftController`: añadir `myHours()` extrayendo el técnico exclusivamente del atributo `user_id` del token autenticado (Art. V.4).
    3. Registrar en `AppRouter.php`:
       * `GET /api/coordinator/metrics/work-hours`
       * `GET /api/coordinator/metrics/work-hours/export`
       * `PATCH /api/coordinator/shifts/{id}/adjust`
       * `GET /api/coordinator/shifts/{id}/logs`
       * `GET /api/technician/shift/my-hours`
  * **Hecho cuando:** `php -l src/Presentation/Controller/CoordinatorShiftController.php && php -l src/Presentation/Controller/TechnicianShiftController.php && php -l src/Presentation/Routing/AppRouter.php` valida sin errores.

- [ ] **T-TIME-10 · Suite de integración HTTP y constitucional `TechnicianTimeTrackingApiTest`**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-TIME-09`
  * **Ficheros a crear o modificar:**
    * `tests/integration/TechnicianTimeTrackingApiTest.php` **(crear)**
  * **Requisitos que cubre:** `RF-01` a `RF-08`, `RNF-01` a `RNF-03`, Resoluciones `QA 1` a `QA 5`, Art. II, Art. III.1, Art. III.3, Art. V.1 y Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. Verificar ciclo de múltiples pausas y cierre directo desde `MEAL_BREAK` con reflejo en `today_summary` y `technician_shift_logs`.
    2. Verificar cierre forzado con `effective_closed_at`: rechazo con `< 20` caracteres (`422`), rechazo si es anterior a última actividad/pausa (`422`), preservación del instante actual `NOW()` en traspaso sanitario de avería perecedera (`Art. II`) y activación simultánea de `is_force_closed` e `is_adjusted`.
    3. Verificar `PATCH /api/coordinator/shifts/{id}/adjust`: rechazo en turno abierto (`422`), rechazo por motivo `< 20` chars (`422`), rechazo por solapamiento (`409 SHIFT_OVERLAP_CONFLICT`), permisión de `0s` efectivos, actualización de `shift_date` y preservación inmutable de `original_*` tras dos rectificaciones seguidas.
    4. Verificar `GET /api/coordinator/metrics/work-hours` y `/export`: presencia de técnicos activos con `0` sesiones, técnicos inactivos con actividad, exclusión de preventivos con `linked_incident_id`, aviso de turnos abiertos huérfanos y formato CSV con UTF-8 BOM y `;`.
    5. Verificar aislamiento Art. V.4 en `GET /api/technician/shift/my-hours` (bloqueo de acceso a otros técnicos y prohibición para rol `LOCATION_MANAGER`).
  * **Hecho cuando:** `php tests/integration/TechnicianTimeTrackingApiTest.php` pasa al 100 % en verde con `0` fallos.

---

## Fase 6: Interfaz Frontend Vanilla ES Modules y Pruebas ESM

- [ ] **T-TIME-11 · Cliente `api.js`, componente `CoordinatorWorkHoursTab.js`, integración en `CoordinatorMetricsView.js` y suite ESM**
  * **Tiempo estimado:** 30 min
  * **Dependencias previas:** `T-TIME-10`
  * **Ficheros a crear o modificar:**
    * `public/assets/js/api.js` **(modificar)**
    * `public/assets/js/utils/AuditActionLabels.js` **(modificar)**
    * `public/assets/js/components/CoordinatorWorkHoursTab.js` **(crear)**
    * `public/assets/js/views/CoordinatorMetricsView.js` **(modificar)**
    * `tests/unit/CoordinatorWorkHoursTabTest.mjs` **(crear)**
  * **Requisitos que cubre:** `RF-02.1`, `RF-02.4`, `RF-03.2` a `RF-03.4`, `RF-04.2` a `RF-04.4`, `RF-05.1` a `RF-05.3`, `RF-06.1`, `RNF-05` (`docs/design.md`), Decisiones `D2`, `D3` y `D5`, Resoluciones `QA 3`, `QA 4` y `QA 5`.
  * **Detalle de implementación:**
    1. Añadir en `api.js` los métodos `getWorkHoursReport`, `getWorkHoursCsvUrl`, `adjustShift`, `getShiftLogs` (y parámetro `effectiveClosedAt` en `forceClose`) y `getMyHours` en `api.technicianShift`; añadir la etiqueta `ADJUST_TECHNICIAN_SHIFT` en `AuditActionLabels.js`.
    2. Crear `CoordinatorWorkHoursTab.js` siguiendo estrictamente [`docs/design.md`](../../docs/design.md) y [`public/assets/css/design-tokens.css`](../../public/assets/css/design-tokens.css) (voltaje interactivo `var(--color-primary)` `#2560ff`, tipografía `var(--font-display)` DM Sans en cifras KPI e `var(--font-body)` Inter en tablas/controles, bordes conservadores `4px` `var(--radius-interactive)` en botones/chips/inputs y `8px` `var(--radius-card)` en tarjetas/paneles con borde `var(--color-hairline)`, cero colores hexadecimales literales): tarjetas de resumen, banner de turnos abiertos huérfanos, selector de técnico, botón de descarga CSV, tabla resumen por técnico, tabla de sesiones con insignias (`⚠️ > 12h`, `Cierre forzado`, `Ajustado por Coordinación`), visualización de marcas originales `original_*` y modales de `✏️ Ajustar Turno`, `🛑 Forzar Cierre` (con `effective_closed_at` opcional) y `📜 Bitácora`.
    3. Montar la sub-pestaña `work-hours` («⏱️ Registro de Jornada y Horas») en `CoordinatorMetricsView.js`.
    4. Crear `tests/unit/CoordinatorWorkHoursTabTest.mjs` validando el componente, sus interacciones y la ausencia de colores hex hardcodeados.
  * **Hecho cuando:** `node --test tests/unit/CoordinatorWorkHoursTabTest.mjs` pasa al 100 % en verde.

- [ ] **T-TIME-12 · Contador diario y modal «⏱️ Mis Horas» en `TechnicianRouteView.js` + suite ESM**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TIME-11`
  * **Ficheros a crear o modificar:**
    * `public/assets/js/views/TechnicianRouteView.js` **(modificar)**
    * `tests/unit/TechnicianMyHoursModalTest.mjs` **(crear)**
  * **Requisitos que cubre:** `RF-07.1` a `RF-07.3`, `RF-08.1`, `RNF-04`, `RNF-05` (`docs/design.md`), Decisión `D4`, Resoluciones `QA 3` y `QA 4`, Art. V.4 de `constitution.md`.
  * **Detalle de implementación:**
    1. Mostrar en el widget superior de jornada de `TechnicianRouteView.js` el resumen acumulado del día (`today_summary.effective_formatted` y `today_summary.meal_break_formatted`) empleando los tokens de [`docs/design.md`](../../docs/design.md) (`var(--font-body)`, `var(--radius-interactive)` `4px`, `var(--color-slate)`, `var(--color-ink-muted)`).
    2. Añadir el botón `⏱️ Mis Horas` (`data-testid="btn-open-my-hours"`) que abre un modal de solo lectura con el resumen del mes actual (`var(--font-display)` para los totales y tarjetas con `var(--radius-card)` `8px` y `1px solid var(--color-hairline)`) y la lista de sesiones propias del técnico, mostrando de forma diferenciada `Cierre forzado` y `Ajustado por Coordinación`, las marcas originales del fichaje del trabajador y el motivo registrado por Coordinación.
    3. Crear `tests/unit/TechnicianMyHoursModalTest.mjs` verificando el contador diario, el modal de solo lectura y el uso de tokens de diseño.
  * **Hecho cuando:** `node --test tests/unit/TechnicianMyHoursModalTest.mjs` pasa al 100 % en verde.

---

## Fase 7: Certificación Integral, Regresión Global y Cierre Documental

- [ ] **T-TIME-13 · Batería global de regresión, sincronización de cifras (`DocMetricsGuard`) y cierre documental**
  * **Tiempo estimado:** 25 min
  * **Dependencias previas:** `T-TIME-12`
  * **Ficheros a crear o modificar:**
    * `README.md` **(modificar si aplica anclaje de cifras)**
    * `docs/features_pendientes.md` **(modificar para marcar Feature 20 como 100 % implementada en el Módulo 22)**
    * `specs/22-technician-time-tracking/tasks.md` **(marcar casillas `[x]`)**
  * **Requisitos que cubre:** Todos los requisitos `RF-01` a `RF-08`, `RNF-01` a `RNF-05` y conformidad constitucional íntegra (`Art. I` a `Art. VII`).
  * **Detalle de implementación:**
    1. Ejecutar todas las suites ESM (`node --test tests/unit/*.mjs`) y la batería global PHP (`php tests/run_all.php`).
    2. Actualizar los anclajes de conteo de suites y aserciones verificados por `DocMetricsGuardTest` y marcar la Feature 20 como implementada en `docs/features_pendientes.md`.
  * **Hecho cuando:** `php tests/run_all.php` termina al **100 % en verde** con `0` suites fallidas.
