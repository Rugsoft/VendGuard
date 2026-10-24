# PLAN TÉCNICO: GESTIÓN DE AUSENCIAS TEMPORALES Y RELEVO DE FIN DE TURNO

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 21 · Gestión de Ausencias Temporales y Relevo de Fin de Turno  
**Documento:** `specs/21-absence-shift-handover/plan.md`  
**Referencia Funcional:** [`spec.md`](spec.md) (v2, 2026-10-23) · **Contratos:** [`../../specs/technical/absence_shift_handover_contracts.md`](../technical/absence_shift_handover_contracts.md)  
**Tareas:** [`tasks.md`](tasks.md)  
**Batería de referencia:** `php tests/run_all.php` + `node --test tests/unit/*.mjs`

---

## 1. Resumen de la Solución

El Módulo 21 unifica la gestión administrativa de ausencias del personal técnico con el ciclo de vida de la jornada diaria en ruta y el relevo ordenado de tickets al finalizar el turno:

1. **Gestión Administrativa de Ausencias:** Coordinación da de alta y cancela ausencias (`technician_absences`), con soporte para días completos o franjas horarias (`TIMESTAMPTZ`), impidiendo solapamientos y ofreciendo reasignación asistida de tickets activos mediante `TechnicianAssignmentSuggester`.
2. **Ciclo de Turno del Técnico:** La jornada diaria se traza en `technician_shifts` con tres estados (`ON_SHIFT`, `MEAL_BREAK`, `OFF_SHIFT`), soportando turno partido mediante un índice único parcial `WHERE closed_at IS NULL`.
3. **Relevo de Fin de Turno Transaccional:** El técnico traspasa averías en estados `ASSIGNED`, `PENDING_PARTS` y `PENDING_INFO` a su compañero de relevo mediante una transacción atómica protegida por `SELECT ... FOR UPDATE`, inyectando una nota interna confidencial (`is_internal = TRUE`, Art. V.4) y notificando a su Telegram con deep linking (Módulo 15).
4. **Blindaje Sanitario Art. II:** Si al cerrar turno existen averías en máquinas con alimentos perecederos (`FOOD_PERISHABLE`) y no hay relevo manual, se activan dos niveles fail-safe: Nivel 1 (traspaso automático a la guardia 24/7 `is_on_call = TRUE`) o Nivel 2 (desasignación a `REGISTERED` y alarma roja a Coordinación). El técnico nunca queda secuestrado por la app.
5. **Recomendador Territorial Asistido:** Los técnicos no disponibles se agrupan al final del ranking con la insignia `NO_DISPONIBLE` y su motivo explícito; el suplente de zona (`BACKUP`) se promueve automáticamente a `#1`.

---

## 2. Arquitectura por Capas

### 2.1. Core / Domain

| Artefacto | Responsabilidad |
| :--- | :--- |
| `src/Core/Domain/Model/TechnicianAbsence.php` **(nuevo)** | Entidad de ausencia: valida fechas, tipos de motivo y cancelación lógica inmutable. |
| `src/Core/Domain/Model/TechnicianShift.php` **(nuevo)** | Entidad de turno: modela las transiciones legales (`start`, `pauseMeal`, `resume`, `close`). |
| `src/Core/Domain/ValueObject/AbsenceReason.php` **(nuevo)** | Enum tipificado de causas de ausencia (`VACATION`, `SICK_LEAVE`, `TRAINING`, etc.). |
| `src/Core/Domain/ValueObject/ShiftStatus.php` **(nuevo)** | Enum de estados de jornada (`ON_SHIFT`, `MEAL_BREAK`, `OFF_SHIFT`). |
| `src/Core/Domain/Repository/TechnicianAbsenceRepositoryInterface.php` **(nuevo)** | Puerto de persistencia de ausencias (creación, búsqueda, solapamiento, cancelación). |
| `src/Core/Domain/Repository/TechnicianShiftRepositoryInterface.php` **(nuevo)** | Puerto de persistencia de turnos (sesión activa, transiciones, mapa de estados). |
| `src/Core/Domain/Exception/TechnicianAbsenceValidationException.php` **(nuevo)** | Excepciones de validación de ausencias (fechas inválidas, solapamientos). |
| `src/Core/Domain/Exception/TechnicianShiftValidationException.php` **(nuevo)** | Excepciones de ciclo de turno y precondición de intervenciones en curso. |

### 2.2. Application

| Artefacto | Responsabilidad |
| :--- | :--- |
| `src/Application/Service/TechnicianAbsenceService.php` **(nuevo)** | Caso de uso de gestión de ausencias en Coordinación, cálculo de tickets afectados y auditoría. |
| `src/Application/Service/ShiftLifecycleService.php` **(nuevo)** | Caso de uso de inicio, pausa de comida y reanudación de jornada técnica. |
| `src/Application/Service/ShiftHandoverService.php` **(nuevo)** | Caso de uso de relevo y cierre: traspaso atómico de tickets con nota interna, protocolo sanitario Art. II y cierre de turno. |
| `src/Application/Service/TechnicianAssignmentSuggester.php` **(modificado)** | Integración de disponibilidad en el motor de ranking: badges `NO_DISPONIBLE` y promoción del `BACKUP` de zona. |
| `src/Application/DTO/TechnicianAbsenceDto.php` **(nuevo)** | Proyección inmutable de ausencia para la API de Coordinación. |
| `src/Application/DTO/TechnicianShiftDto.php` **(nuevo)** | Proyección del estado actual de la jornada técnica. |
| `src/Application/DTO/ShiftHandoverResultDto.php` **(nuevo)** | Resultado del cierre de turno (tickets traspasados, retenidos y derivados). |

### 2.3. Infrastructure

| Artefacto | Responsabilidad |
| :--- | :--- |
| `src/Infrastructure/Repository/PdoTechnicianAbsenceRepository.php` **(nuevo)** | Implementación PDO para `technician_absences` con filtrado eficiente e índice de solapamiento. |
| `src/Infrastructure/Repository/PdoTechnicianShiftRepository.php` **(nuevo)** | Implementación PDO para `technician_shifts` con control de sesión única activa. |
| `src/Infrastructure/Repository/PdoTechnicianSuggestionRepository.php` **(modificado)** | Inyección de estado de turno y ausencias en la foto operativa en una única consulta indexada. |

### 2.4. Presentation

| Artefacto | Responsabilidad |
| :--- | :--- |
| `src/Presentation/Controller/CoordinatorAbsenceController.php` **(nuevo)** | Endpoints de ausencias de Coordinación (`GET`, `POST`, `DELETE`). |
| `src/Presentation/Controller/CoordinatorShiftController.php` **(nuevo)** | Supervisión de flota y endpoint de cierre forzado de turno (`POST force-close`). |
| `src/Presentation/Controller/TechnicianShiftController.php` **(nuevo)** | Endpoints móviles para el técnico (`status`, `start`, `pause-meal`, `resume`, `close-handover`). |
| `src/Presentation/Routing/AppRouter.php` **(modificado)** | Registro de las nuevas rutas bajo middlewares `InternalAuthMiddleware(COORDINATOR)` y `InternalAuthMiddleware(TECHNICIAN)`. |

### 2.5. Frontend (Vue 3 ESM, sin bundler, Options API)

| Artefacto | Responsabilidad |
| :--- | :--- |
| `public/assets/js/views/TechnicianRouteView.js` **(modificado)** | Barra de estado de turno superior y modal táctil de relevo/cierre de jornada. |
| `public/assets/js/views/CoordinatorDashboardView.js` **(modificado)** | Indicador en vivo de estado de técnicos, botón de cierre forzado y pestaña de ausencias. |
| `public/assets/js/components/CoordinatorAbsenceModal.js` **(nuevo)** | Modal de alta/cancelación de ausencia con selector de fechas/horas y reasignación asistida. |
| `public/assets/js/components/SuggestedTechniciansModal.js` **(modificado)** | Renderizado de badges `NO_DISPONIBLE` con motivos y atenuación visual de técnicos no disponibles. |
| `public/assets/js/api.js` **(modificado)** | Métodos cliente `api.technicianShift.*` y `api.coordinatorAbsences.*`. |
| `public/assets/js/utils/AuditActionLabels.js` **(modificado)** | Catálogo de etiquetas para las nuevas acciones de auditoría. |

---

## 3. Decisiones Técnicas y Justificación

1. **Índice Parcial para Turno Partido (`WHERE closed_at IS NULL`):** Evita la rigidez de un turno por día natural; permite a un técnico cerrar turno a mediodía y abrir otro de tarde o atender una llamada de emergencia sin violaciones de clave única.
2. **Transaccionalidad Atómica en Traspaso con `SELECT ... FOR UPDATE`:** Garantiza la inviolabilidad del Art. V.3 (técnico único responsable); si Coordinación reasigna una avería mientras el técnico redactaba el traspaso, el sistema detecta la carrera y responde `409 CONFLICT` para mantener los datos sincronizados.
3. **Preservación del Rol de Guardia Pasiva:** El flag `is_on_call = TRUE` no se apaga al cerrar el turno regular; permite que el operario descanse de averías comerciales mientras su terminal conserva la recepción de alarmas sanitarias de frío.
4. **Dogma Vanilla Puro:** Cero dependencias añadidas. Todo se construye sobre PHP 8.2+ estándar y Vue 3 Options API sin herramientas de empaquetado en runtime.

---

## 4. Estrategia de Migración y Paridad de Base de Datos

* **Migración:** `database/supabase_migrations/012_technician_absences_and_shifts.sql`.
* **Sincronización:** Reflejo exacto en `database/supabase_init.sql` (Tablas 29 y 30).
* **Contratos:** Actualización de `specs/technical/schema_correspondence_contract.md`, `SchemaConvergenceParityTest` y `PostgresSchemaTest`.
* **Arnés de Pruebas:** Registro de las tablas en `tests/Support/TestDataCleaner.php` para aislamiento estricto en la batería de pruebas.

---

## 5. Estrategia de Entrega (Work Units)

La implementación se dividirá en **7 unidades de trabajo (WU-1 a WU-7)** con política *test-first*, cerrando cada una con aserciones en verde y commits atómicos:

* **WU-1 · Esquema:** Tablas 29 y 30, índices parciales, paridad `init`/migración y guardas de esquema.
* **WU-2 · Dominio de Ausencias y Turnos:** Entidades inmutables, enums, repositorios PDO y tests de dominio.
* **WU-3 · Servicios de Aplicación y Relevo:** `ShiftHandoverService`, protocolo sanitario Art. II y cierre con traspaso atómico.
* **WU-4 · Integración en el Recomendador:** `TechnicianAssignmentSuggester` con disponibilidad, badges y promoción del suplente.
* **WU-5 · API de Coordinación y Técnico:** Controladores REST, rutas y suites de integración HTTP.
* **WU-6 · Frontend Móvil y Coordinación:** Componentes Vue 3, barra de turno móvil, modal de relevo y gestión de ausencias.
* **WU-7 · Guardas, Auditoría y Certificación:** Catálogo de `audit_log`, ejecución de `php tests/run_all.php` y cierre del módulo.
