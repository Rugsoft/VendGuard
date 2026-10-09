# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO 11: ESTADO OPERATIVO "PENDIENTE DE INFORMACIÓN" (PENDING_INFO) CON PAUSA DE SLA (TASKS.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `11-pending-info-sla-pause`  
**Documento:** `specs/11-pending-info-sla-pause/tasks.md`  
**Referencia Funcional:** [`specs/11-pending-info-sla-pause/spec.md`](spec.md) (y [`specs/functional/pending_info_sla_pause_spec.md`](../functional/pending_info_sla_pause_spec.md))  
**Plan Técnico:** [`specs/11-pending-info-sla-pause/plan.md`](plan.md)  
**Normativa Suprema:** [`constitution.md`](../../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [`AGENTS.md`](../../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)  
**Sistema de Diseño Visual:** [`docs/design.md`](../../docs/design.md) (Tokens Docker: Ámbar técnico `#fef9c3` con texto `#854d0e`, azul `#2560ff`, radio 4px/8px, canvas `#f9fafb`)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Dominio, Value Objects, DTOs y Extensión de Repositorios

- [x] **T-PAUSE-01: Crear migración de base de datos `016_pending_info_sla_pause.sql` y registrar campos de pausa**
  * **Requisitos:** RF-01.1, RF-01.4, RF-04.1, RF-04.4, Constitución Art. III, Art. V.1
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existe `database/migrations/016_pending_info_sla_pause.sql` con la sentencia `ALTER TABLE incidents` que amplía la columna `status` con el valor `'PENDING_INFO'` (preservando la columna virtual `is_active_ticket`), añade las columnas `pending_info_reason_category VARCHAR(64) NULL`, `pending_info_reason_text TEXT NULL`, `paused_at TIMESTAMP NULL`, `total_pending_info_seconds INT UNSIGNED NOT NULL DEFAULT 0`, `sla_target_at TIMESTAMP NULL`, y `MigrationRunner` la ejecuta sin errores en MariaDB.
  * **Nota de enmienda (2026-10-09):** La spec nombraba `014_pending_info_sla_pause.sql`, número ya ocupado por `014_location_access_code.sql` (hallazgo S-4) y seguido de `015_user_login_lockout.sql` (hallazgo S-3). Con aprobación del Product Owner, la migración se registra como `016_pending_info_sla_pause.sql` para preservar la numeración secuencial del repositorio.
  * **Verificación ejecutada:** Sonda sobre base desechable (27/27 aserciones: enum, tipos, nulabilidad, `is_active_ticket` virtual, `uq_machine_active_ticket` y rechazo de segundo ticket activo), aplicación real vía `MigrationRunner` sobre `vendguard_db` (13 filas preservadas) y `CloudDeploySchemaParityTest` (27/27).

- [x] **T-PAUSE-02: Extender `IncidentStatus.php` e implementar el enum `IncidentPauseReasonCategory.php`**
  * **Requisitos:** RF-01.1, RF-01.2, RF-06.2, RNF-01
  * **Dependencias:** T-PAUSE-01
  * **Hecho cuando:** `IncidentStatus.php` incluye el caso `PENDING_INFO = 'PENDING_INFO'`, etiqueta descriptiva `'Pendiente de información'`, confirma `isActive() === true`, valida transiciones legales desde `ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS` y `REOPENED` hacia `PENDING_INFO`, y desde `PENDING_INFO` hacia `ASSIGNED`, `IN_PROGRESS` y `CANCELLED` (prohibiendo expresamente la transición directa a `RESOLVED`); existe `src/Core/Domain/ValueObject/IncidentPauseReasonCategory.php` con los 4 motivos tipificados (`BUILDING_CLOSED_NO_ACCESS`, `MACHINE_LOCATION_NOT_FOUND`, `EXTERNAL_POWER_CUT`, `PENDING_SITE_AUTHORIZATION`); y el test unitario `IncidentStatusPendingInfoTest.php` pasa al 100% en verde.
  * **Alineaciones del mismo commit (exigidas por el ciclo de vida):** guardián bilingüe `IncidentStateMachine` (sinónimos + grafo), `match` exhaustivo de `IncidentCommentService` (sin él, una parada en `PENDING_INFO` lanzaría `UnhandledMatchError`), `CoordinatorIncidentDetailService::ACTIVE_STATUSES` (6 estados: la cancelación por inactividad y la respuesta de sede siguen disponibles) y el espejo frontend `IncidentStatusPermissions.js` con su contrato técnico, conforme al §7 de `specs/technical/incident_status_permissions_contracts.md`.
  * **Verificación ejecutada (2026-10-09):** `php tests/unit/IncidentStatusPendingInfoTest.php` (32 aserciones), `IncidentStatusPermissionsUtilTest.mjs` (49), `DomainEnumsTest.php` y `IncidentStateMachineTest.php` (36) en verde; `php tests/run_all.php` → **218 suites · 8.247 aserciones · 0 fallos**.

- [x] **T-PAUSE-03: Implementar DTOs inmutables `IncidentPauseRequestDto.php` e `IncidentPauseResponseDto.php`**
  * **Requisitos:** RF-01.2, RF-01.3, RF-03.2, RNF-01, Constitución Art. V.1
  * **Dependencias:** T-PAUSE-02
  * **Hecho cuando:** Existen en `src/Application/DTO/IncidentPauseRequestDto.php` e `IncidentPauseResponseDto.php` las clases `final readonly` con tipado estricto PHP 8.2+ que: (1) validan obligatoriamente la presencia de una categoría válida de `IncidentPauseReasonCategory`; (2) exigen un texto explicativo `reasonText` con al menos 20 caracteres no vacíos (`mb_strlen(trim($text)) >= 20`), lanzando `InvalidArgumentException` en caso contrario; y (3) serializan inmutablemente la respuesta JSON con campos `is_sla_paused`, `accumulated_pause_minutes`, `sla_target_at_original` y `sla_target_at_shifted`.
  * **Contrato HTTP para T-PAUSE-12/13:** el DTO lanza `InvalidArgumentException` ante valores inválidos (causa fuera del catálogo o justificación corta), que el controlador mapea a `422` capturando la excepción —el patrón del resto de controladores del proyecto—; la **ausencia** de campos (`reason_category`/`reason_text`) es responsabilidad del controlador (`400`). `sla_target_at_shifted` es el nombre que fija esta tarea y equivale al `new_sla_target_at` ilustrado en `plan.md` §2.2.
  * **Verificación ejecutada (2026-10-09):** `php tests/unit/IncidentPauseDtoTest.php` (27 aserciones: catálogo tipificado, umbral de 20 caracteres reales con `mb_strlen`, espacios superfluos, serialización de los 4 campos del contrato, `null` explícito sin SLA, acumulado negativo y `final readonly`); `php tests/run_all.php` → **219 suites · 8.274 aserciones · 0 fallos**.

- [x] **T-PAUSE-04: Extender Aggregate Root `Incident.php` y entidad `Machine.php` con estado de bloqueo**
  * **Requisitos:** RF-01.1, RF-01.4, RF-04.1, RF-04.4, Constitución Art. V.1
  * **Dependencias:** T-PAUSE-02, T-PAUSE-03
  * **Hecho cuando:** La clase `src/Core/Domain/Model/Incident.php` incorpora los atributos privados de pausa, getters tipados, métodos de mutación inmutable `pausePendingInfo(...)`, `resumePendingInfo(...)` y `shiftSlaTarget(...)`, calculando acumulados de tiempo pausado; y `src/Core/Domain/Model/Machine.php` soporta la indicación de máquina fuera de servicio por acceso bloqueado (`BLOCKED_NO_ACCESS`).
  * **Verificación ejecutada (2026-10-09):** `php tests/unit/IncidentPauseDomainModelTest.php` (49 aserciones: hidratación de las 5 columnas de la migración 016, pausa legal desde los 4 orígenes, doble pausa y origen `REGISTERED` bloqueados, umbral de 20 caracteres reales con `mb_strlen` multibyte, 90 min = 5.400 s exactos, suma acumulativa 90 min + 60 min = 9.000 s, `PENDING_INFO -> RESOLVED` prohibido, composición pausa -> reanudación -> desplazamiento de SLA, y bloqueo de máquina por falta de acceso con precedencia, idempotencia y anotación de auditoría); `php tests/run_all.php` -> **220 suites · 8.323 aserciones · 0 fallos**.
  * **Decisiones de diseño anotadas para las tareas siguientes:**
    * **Inmutabilidad real:** `pausePendingInfo()`, `resumePendingInfo()` y `shiftSlaTarget()` devuelven instancias nuevas (`clone`); la receptora nunca muta, de modo que el estado persistido sólo cambia cuando el repositorio graba el resultado (T-PAUSE-05).
    * **El umbral legal muerde también en el dominio:** `Incident::MIN_PAUSE_REASON_LENGTH = 20` es espejo de `IncidentPauseRequestDto::MIN_REASON_TEXT_LENGTH` (T-PAUSE-03). El DTO protege el cuerpo HTTP (422) y la entidad protege el cambio de estado, de modo que un servicio no puede abrir la puerta del Art. V.1 olvidando su validación. Mientras esa duplicación exista, ambos números deben moverse en el mismo commit.
    * **La hidratación no valida longitud:** el constructor sólo exige el acumulador no negativo. Un ticket histórico o reanudado con justificación corta debe poder leerse sin reventar en consulta; la regla muerde al declarar la pausa.
    * **`sla_target_at` lo graba el servicio:** `shiftSlaTarget()` es un mero grabador del resultado; el desplazamiento en ventana comercial (08:00-18:00, lunes a viernes) lo calcula el Algoritmo 3 de `IncidentPauseService` (T-PAUSE-06), que es quien conoce el calendario de la sede. `currentPauseDurationSeconds()` entrega los segundos exactos del intervalo vivo para el reloj congelado (RF-03.2), la reactivación en caliente de 60 min (RF-02.1) y ese mismo desplazamiento.
    * **La causa y el texto se conservan al reanudar:** el intervalo vivo lo marca `paused_at = NULL`. La auditoría inmutable de cada intervalo pertenece a `incident_history` (T-PAUSE-05) y el banner ámbar de sede sigue condicionado al estado `PENDING_INFO` (RF-05.1), así que no hay fuga de información interna.
    * **Pendiente para T-PAUSE-09 (persistencia del bloqueo de máquina):** la migración 016 no añade ninguna columna a `machines` y el esquema no define `operational_status`. El modelo ya soporta la indicación (`blockForNoAccess()`, `isBlockedNoAccess()`, `getOperationalStatus()`) y `fromDatabaseRow()` la hidrata si la persistencia expone una clave `is_blocked_no_access` o `operational_status`. Dónde vive la marca (columna nueva, `is_active = 0` con anotación en `notes`, o derivación) corresponde a T-PAUSE-09; si exige migración, requiere enmienda de especificación aprobada por el Product Owner.
    * **`getOperationalStatus()` replica la lectura vigente del parque** (`OPERATIONAL` / `ACTIVE_INCIDENT` / `IN_WARRANTY`, hoy calculada en `CoordinatorController::getLocationMachines()`), añadiendo el bloqueo con precedencia absoluta. Sustituir el cálculo del controlador por el del dominio corresponde a la tarea que toque esa superficie, no a esta.

- [ ] **T-PAUSE-05: Extender `IncidentRepositoryInterface` y `PdoIncidentRepository` con métodos de pausa y auditoría inmutable**
  * **Requisitos:** RF-01.4, RF-02.1, RF-02.2, RF-04.1, RF-04.2, Constitución Art. III
  * **Dependencias:** T-PAUSE-04
  * **Hecho cuando:** `IncidentRepositoryInterface` y `PdoIncidentRepository` implementan: (1) `recordPauseEvent(int $incidentId, int $userId, IncidentStatus $fromStatus, IncidentPauseReasonCategory $category, string $reasonText, DateTimeImmutable $pausedAt): void`; (2) `recordResumeEvent(int $incidentId, ?int $userId, IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, DateTimeImmutable $resumedAt): void`; (3) inserción inmutable en `incident_history` sin sobreescrituras ni borrados; y (4) `getPendingInfoIncidentsOlderThanHours(int $hours): array` para auditoría de tickets inactivos.

---

## Fase 2: Servicios de Aplicación y Algoritmos de Dominio

- [ ] **T-PAUSE-06: Implementar Algoritmo 3 de Desplazamiento Comercial de SLA en `IncidentPauseService.php`**
  * **Requisitos:** RF-03.1, RF-03.3, RNF-01
  * **Dependencias:** T-PAUSE-05
  * **Hecho cuando:** El método `IncidentPauseService::shiftSlaTargetInBusinessHours(DateTimeImmutable $currentDeadline, int $pauseDurationSeconds, int $locationId): DateTimeImmutable` calcula el nuevo vencimiento contractual sumando exclusivamente segundos hábiles comerciales (08:00 a 18:00, lunes a viernes), saltando fines de semana e intervalos nocturnos; y un test unitario dedicado verifica el cálculo exacto de saltos nocturnos y de fin de semana.

- [ ] **T-PAUSE-07: Implementar Algoritmo 4 de Doble Reloj y Salvaguarda Sanitaria (Art. II) en `IncidentPauseService.php`**
  * **Requisitos:** RF-03.4, RF-03.5, Constitución Art. II
  * **Dependencias:** T-PAUSE-06
  * **Hecho cuando:** El método `IncidentPauseService::evaluateSanitaryBiologicalClock(Incident $incident): bool` comprueba si la máquina es de alimentos perecederos (`isPerishable = true`) y computa el tiempo natural continuo 24/7 sin frío confirmado; si el contador natural alcanza o supera 4 horas (14.400 segundos), invoca automáticamente `PdoPreventiveSettingsRepository::updateSanitaryStatus($machineId, 'QUARANTINE')`, registra el evento inmutable `SANITARY_QUARANTINE_AUTO_TRIGGERED` y exige checklist sanitario previo al cierre en resolución técnica.

- [ ] **T-PAUSE-08: Implementar Algoritmo 2 de Reactivación Condicional Inteligente en `IncidentPauseService.php`**
  * **Requisitos:** RF-02.1, RF-05.3, RNF-02, Constitución Art. V.1
  * **Dependencias:** T-PAUSE-06, T-PAUSE-07
  * **Hecho cuando:** El método `IncidentPauseService::handleSiteCommentReactivation(int $incidentId, string $commentText, int $siteUserId): IncidentPauseResponseDto` procesa comentarios válidos de sede ($\ge 5$ caracteres), y si la incidencia está en `PENDING_INFO`: (1) si transcurrieron $< 60$ minutos y el técnico asignado no tiene otra intervención activa, transiciona a `IN_PROGRESS`; (2) si transcurrieron $\ge 60$ minutos, el técnico inició otra avería o fue reasignado, transiciona forzosamente a `ASSIGNED`; (3) recalcula la fecha límite de SLA; y (4) procesa con idempotencia llamadas duplicadas o ráfagas concurrentes.

- [ ] **T-PAUSE-09: Implementar Algoritmo 5 de Inactividad de 72h Hábiles, Cancelación y Protección de Reintegros**
  * **Requisitos:** RF-04.2, RF-04.3, RF-04.4, RF-04.5, RF-04.6, Constitución Art. V.1, Art. V.2, Módulo 08
  * **Dependencias:** T-PAUSE-08
  * **Hecho cuando:** El método `IncidentPauseService::cancelByInactivity(int $incidentId, int $coordinatorUserId, string $cancellationReason): void`: (1) valida que `cancellationReason` contenga al menos 20 caracteres reales; (2) transiciona el ticket a `CANCELLED`; (3) transiciona la máquina a `'BLOCKED_NO_ACCESS'` en `PdoMachineRepository` (prohibido volver a 'Operativa'); (4) desvincula cualquier solicitud de reintegro en `refund_requests` (`incident_id = NULL`), preservándola en `PENDING_COORDINATOR` para liquidación central; y (5) sella el expediente en solo lectura.

- [ ] **T-PAUSE-10: Integrar deducción de pausas en MTTR en `MetricsCalculationService.php` y `PdoMetricsRepository.php`**
  * **Requisitos:** RF-03.1, RNF-01
  * **Dependencias:** T-PAUSE-06
  * **Hecho cuando:** `MetricsCalculationService` y `PdoMetricsRepository` descuentan `total_pending_info_seconds` del cómputo del tiempo total de resolución en tickets cerrados/resueltos; y la prueba unitaria `MetricsCalculationServiceTest.php` demuestra que una avería con 5 horas de duración bruta y 2 horas en `PENDING_INFO` registra exactamente 3 horas netas de MTTR contractual.

- [ ] **T-PAUSE-11: Implementar pruebas unitarias completas `IncidentPauseServiceTest.php`**
  * **Requisitos:** RF-01, RF-02, RF-03, RF-04, Constitución Art. II, Art. V.1
  * **Dependencias:** T-PAUSE-06, T-PAUSE-07, T-PAUSE-08, T-PAUSE-09, T-PAUSE-10
  * **Hecho cuando:** La ejecución de `php tests/unit/IncidentPauseServiceTest.php` pasa al 100% en verde evaluando: declaración legal de pausa, rechazo de textos con $< 20$ caracteres, reactivación en caliente (< 60 min a `IN_PROGRESS`), reactivación desfasada ($\ge 60$ min a `ASSIGNED`), cuarentena sanitaria automática a las 4h naturales continuas, cancelación tras 72h con máquina en fuera de servicio y preservación de reintegros.

---

## Fase 3: Controladores REST y Rutas en AppRouter

- [ ] **T-PAUSE-12: Implementar endpoints de técnico en `TechnicianController.php` y registrar en `AppRouter.php`**
  * **Requisitos:** RF-01.1, RF-01.2, RF-01.3, RF-02.2, RF-02.3, RNF-02
  * **Dependencias:** T-PAUSE-11
  * **Hecho cuando:** `TechnicianController` incorpora `pausePendingInfo(Request $request): Response` y `resumePendingInfo(Request $request): Response`; `AppRouter.php` registra `POST /api/technician/incidents/{id}/pause-pending-info` y `POST /api/technician/incidents/{id}/resume-pending-info` con `InternalAuthMiddleware(UserRole::TECHNICIAN)`; se valida que el técnico esté asignado a la incidencia; y las respuestas devuelven códigos HTTP 200, 400, 403 y 422 según el contrato formal.

- [ ] **T-PAUSE-13: Implementar endpoints de coordinador en `CoordinatorController.php` y registrar en `AppRouter.php`**
  * **Requisitos:** RF-01.1, RF-02.2, RF-04.3, RF-04.4, RF-04.5, RF-06.3, Constitución Art. V.1
  * **Dependencias:** T-PAUSE-11
  * **Hecho cuando:** `CoordinatorController` incorpora `pausePendingInfo(Request $request): Response`, `resumePendingInfo(Request $request): Response` y `cancelInactivity(Request $request): Response`; `AppRouter.php` registra `POST /api/coordinator/incidents/{id}/pause-pending-info`, `POST /api/coordinator/incidents/{id}/resume-pending-info` y `POST /api/coordinator/incidents/{id}/cancel-inactivity` con `InternalAuthMiddleware(UserRole::COORDINATOR)`; y la cancelación ejecuta el protocolo completo de inactividad de 72h hábiles.

- [ ] **T-PAUSE-14: Conectar gancho reactivador en `LocationPortalController.php` y blindar estado en `QrScanController.php`**
  * **Requisitos:** RF-02.1, RF-05.3, RF-05.4, RF-06.1, Constitución Art. III, Art. V.4
  * **Dependencias:** T-PAUSE-12, T-PAUSE-13
  * **Hecho cuando:** En `LocationPortalController::addComment()`, al registrar un comentario de sede válido en una incidencia con estado `PENDING_INFO`, se invoca automáticamente `IncidentPauseService::handleSiteCommentReactivation(...)` retornando el flag `auto_resumed: true`; si el expediente está `CANCELLED`, se bloquea el comentario con código 403; y en `QrScanController.php`, las incidencias en `PENDING_INFO` se proyectan al ciudadano como `"En proceso de atención técnica"` sin referencias internas de pausa ni acceso bloqueado.

---

## Fase 4: Componentes Frontend Vanilla Vue.js 3 ESM

- [ ] **T-PAUSE-15: Extender cliente API frontend en `public/assets/js/api.js` con métodos de pausa y reanudación**
  * **Requisitos:** RF-01, RF-02, RF-04, RNF-02
  * **Dependencias:** T-PAUSE-12, T-PAUSE-13
  * **Hecho cuando:** El archivo `public/assets/js/api.js` exporta `pauseIncidentPendingInfo(incidentId, reasonCategory, reasonText)`, `resumeIncidentPendingInfo(incidentId, targetStatus, resumeNote)` y `cancelIncidentInactivity(incidentId, cancellationReason)`, gestionando tokens de autenticación Bearer y devolviendo los payloads deserializados o excepciones con el mensaje de error de la API.

- [ ] **T-PAUSE-16: Implementar componente modal reutilizable `PendingInfoPauseModal.js`**
  * **Requisitos:** RF-01.2, RF-01.3, RNF-03, RNF-04, RNF-06, Constitución Art. V.1
  * **Dependencias:** T-PAUSE-15
  * **Hecho cuando:** Existe `public/assets/js/components/PendingInfoPauseModal.js` como módulo ES nativo de Vue 3 que renderiza un modal accesible con: (1) selector de 4 causas tipificadas; (2) textarea con contador dinámico de caracteres restantes bloqueando el botón de envío si hay $< 20$ caracteres; (3) botones táctiles $\ge 44\text{ px}$; y (4) guardián de borrador sucio solicitando confirmación si el usuario presiona `Escape` o hace clic exterior habiendo texto escrito.

- [ ] **T-PAUSE-17: Integrar banner informativo ámbar y llamada a la acción en `MachineCard.js` y `LocationPortalView.js`**
  * **Requisitos:** RF-05.1, RF-05.2, RNF-04
  * **Dependencias:** T-PAUSE-15
  * **Hecho cuando:** Los componentes `MachineCard.js` y `LocationPortalView.js` muestran un banner ámbar destacado (`#fef9c3` con borde `#fde047` y texto `#854d0e`) cuando la máquina tiene una incidencia activa en `PENDING_INFO`, indicando la causa tipificada y ofreciendo un botón prominente de 1 clic *"Aportar información / Responder al técnico"* que abre directamente el modal de comentarios.

- [ ] **T-PAUSE-18: Integrar modal de pausa y despriorización de paradas en `TechnicianRouteView.js`**
  * **Requisitos:** RF-01.1, RF-02.2, RF-02.3, RF-05.5, RNF-03
  * **Dependencias:** T-PAUSE-16
  * **Hecho cuando:** `TechnicianRouteView.js` incorpora en la parada activa el botón *"Pausar por falta de acceso"* (que abre `PendingInfoPauseModal.js`), y al confirmarse la pausa: (1) la parada se desprioriza mostrando la insignia `⏸️ En espera de sede`; (2) permite avanzar a la siguiente parada sin bloqueo de ruta; y (3) muestra el botón *"Reanudar intervención in situ"* para retomar a `IN_PROGRESS` con 1 clic.

- [ ] **T-PAUSE-19: Integrar insignias de SLA pausado, alertas de 72h y cancelación en `CoordinatorDashboardView.js`**
  * **Requisitos:** RF-03.2, RF-04.2, RF-04.3, RF-06.3, RNF-04
  * **Dependencias:** T-PAUSE-15, T-PAUSE-16
  * **Hecho cuando:** `CoordinatorDashboardView.js` y `CoordinatorIncidentDetailModal.js`: (1) muestran la insignia `⏸️ SLA Pausado` en la tabla y cabecera del expediente; (2) renderizan el cronómetro contractual congelado y la fecha SLA recalculada; (3) muestran aviso urgente si la pausa supera las 72 horas hábiles; y (4) permiten al coordinador reanudar la avería o ejecutar la cancelación formal supervisada.

---

## Fase 5: Estrategia de Pruebas Integrales, Blindaje Constitucional y Verificación Global

- [ ] **T-PAUSE-20: Implementar pruebas unitarias reactivas frontend ESM en `PendingInfoPauseModalTest.mjs`**
  * **Requisitos:** RF-01.2, RF-01.3, RNF-03, RNF-06, Constitución Art. V.1
  * **Dependencias:** T-PAUSE-16
  * **Hecho cuando:** El test `tests/unit/PendingInfoPauseModalTest.mjs` ejecutado con `node` en local sin dependencias npm valida: (1) bloqueo del botón de envío con 0 a 19 caracteres; (2) habilitación del botón con 20 caracteres y causa seleccionada; (3) restablecimiento del formulario tras éxito; y (4) activación del guardián de confirmación al presionar `Escape` con cambios sucios.

- [ ] **T-PAUSE-21: Implementar suite de pruebas de integración HTTP `PendingInfoApiTest.php` contra MariaDB real**
  * **Requisitos:** RF-01, RF-02, RF-03, RF-04, RNF-01, RNF-02
  * **Dependencias:** T-PAUSE-12, T-PAUSE-13, T-PAUSE-14
  * **Hecho cuando:** La ejecución de `php tests/integration/PendingInfoApiTest.php` pasa al 100% en verde evaluando contra MariaDB: flujo completo de pausa por técnico y coordinador, reanudación manual, reactivación condicional automática tras comentario de sede (< 60 min vs $\ge 60$ min), desplazamiento exacto de SLA en horario hábil y cancelación formal por inactividad de 72h.

- [ ] **T-PAUSE-22: Implementar pruebas de blindaje constitucional `PendingInfoConstitutionalTest.php` y suite global verde**
  * **Requisitos:** Constitución Art. II, Art. III, Art. V.1, Art. V.2, Art. V.4, Art. VII
  * **Dependencias:** T-PAUSE-20, T-PAUSE-21
  * **Hecho cuando:** `php tests/integration/PendingInfoConstitutionalTest.php` certifica: (1) activación automática de `QUARANTINE` y bloqueo de resolución a las 4 horas continuas en perecederos (Art. II); (2) inmutabilidad de `incident_history` sin sobreescrituras ni `DELETE` (Art. III); (3) justificación obligatoria $\ge 20$ chars y máquina fuera de servicio tras cancelación (Art. V.1); (4) confirmación de acceso requerida en nuevos reportes sobre máquinas bloqueadas (Art. V.2); (5) anonimización pública del estado en QR ciudadano (Art. V.4); y la suite global `php tests/run_all.php` finaliza 100% verde sin regresiones en ningún módulo previo.
