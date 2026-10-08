# Auditoría de los Flujos de Asignación y Reasignación Técnica

**Fecha:** 2026-10-08 · **Ámbito:** MVP Fase 1 (ramas activas) · **Branches:** `incident-comments`
**Pregunta auditada:** ¿todo camino del sistema que cambia al técnico responsable exige el motivo justificado que las reglas de negocio exigen y deja rastro de auditoría inmutable?

**Conclusión corta:** existe **un solo** camino de reasignación de incidencias (un único endpoint y un único escritor de base de datos), estaba correctamente protegido en backend y su **único desvío era un consumidor del frontend** que omitía el motivo (corregido en este trabajo). Hay **dos caminos exentos** con justificación normativa explícita: la asignación/reasignación de órdenes preventivas y la autoasignación oportunista del técnico, ninguna de las cuales tiene exigencia de motivo en la especificación aprobada.

---

## 1. Inventario de caminos que escriben el técnico responsable

| # | Camino | Superficie HTTP | ¿Exige motivo? | Rastro de auditoría | Cobertura de pruebas |
| :--- | :--- | :--- | :--- | :--- | :--- |
| 1 | Asignación **y reasignación de incidencias** | `PATCH /api/coordinator/incidents/{id}/assign` (`AppRouter.php:107` → `CoordinatorController::assignTechnician:306`) | **Sí**, ≥ 10 caracteres reales cuando el estado es `ASSIGNED`, `IN_PROGRESS` o `PENDING_PARTS` (`MIN_REASSIGNMENT_REASON_LENGTH:50`, bloque `355-391`) | `incident_history` con la nota `Reasignación técnica: … Motivo: <texto>` + `audit_log` `INCIDENT_REASSIGNED` / `INCIDENT_ASSIGNED` con actor, motivo y técnicos sustituido/nuevo | `AssignTechnicianEndpointTest.php` casos 4.1–4.7, 6.1–6.6 y **7.1–7.4 (nuevos)**; consumidores: `CoordinatorDashboardViewTest.mjs` grupos 7 y **14 (nuevo)** |
| 2 | **Asignación / reasignación de órdenes preventivas** | `PATCH /api/coordinator/preventive/orders/{id}/assign` (`AppRouter.php:161` → `CoordinatorPreventiveController::assignOrder:345`) | **No** (exento, ver §3) | `audit_log` `ASSIGN_PREVENTIVE_ORDER` con estado y técnico previo y nuevo | `CoordinatorPreventiveApiTest.php` |
| 3 | **Autoasignación oportunista del técnico** | `POST /api/technician/preventive/orders/{id}/claim` (`AppRouter.php:207` → `TechnicianPreventiveController::claimOrder:185`) | **No** (exento, ver §3) | `audit_log` `CLAIM_PREVENTIVE_ORDER` con actor y resultado | `TechnicianPreventiveApiTest.php` |
| 4 | **Desasignación por reapertura de garantía** | `POST /api/incidents/{ticket}/reopen` (`LocationPortalController::reopenIncident:913`) | **Sí**, `reopen_reason` obligatorio (EARS 9.1/9.2) | `incident_history` con el evento de reapertura + `audit_log` `REOPEN_TICKET` con técnico saliente en el estado previo | `ReopenIncidentEndpointTest.php`, `LocationPortalReopenAuditTest.php`, `ReopenTicketModalTest.mjs` |
| 5 | Escrituras indirectas (puente preventivo→avería, reinspección) | N/A (servicios internos) | N/A | Eventos propios del servicio | Suites de coexistencia preventiva |

### 1.1 Unicidad del escritor (incidencias)

* `PdoIncidentRepository::assign()` es el **único** método que cambia `assigned_technician_id` con lógica de negocio, y tiene **un solo llamador** en todo `src/`: `CoordinatorController:396`.
* `PdoIncidentRepository::update()` (el genérico que también escribe la columna) sólo lo consume `PreventiveCoexistenceBridgeService:276`, que **preserva** el técnico y exclusivamente escala la urgencia (`assignedTechnicianId: $activeIncident->getAssignedTechnicianId()`).
* `reopen()` es el único otro escritor de la columna y lo hace para **desasignar** (`assigned_technician_id = NULL`).
* La baja de un técnico con trabajo vivo queda **bloqueada** por `PdoUserRepository::countActiveAssignedIncidents()` (Decisión QA 2 de `specs/04-admin-crud/plan.md` §3.2), de modo que no existe una reasignación masiva implícita al desactivar usuarios.
* Otras tres tablas guardan un `technician_id`, pero como **traza histórica de quién ejecutó una acción**, no como responsable reasignable: `replaced_machine_parts.technician_id` (quien instaló el repuesto, migración 006), `refund_requests.technician_id` (quien inspeccionó el reintegro, escrito en la transición de inspección, migración 008) y `unclaimed_cash_findings.technician_id` (quien halló el efectivo). Ninguna es el responsable del aviso ni entra en el ámbito de RF-07.3/Art. V.3: no hay endpoint que cambie el inspector de un reintegro ya inspeccionado ni reasigne repuestos instalados.

## 2. Hallazgo corregido en esta auditoría

**Desvío (defecto real):** el flujo masivo del mapa territorial invocaba la API con **cuatro** argumentos, por lo que nunca enviaba `reassignment_reason`. En un lote con incidencias ya asignadas, cada una respondía `422 MISSING_REASSIGNMENT_REASON` y el coordinador veía una «asignación parcial» en lugar de la consolidación que RF-MAP-09 exige.

* **Alcance:** sólo el lote mixto; las asignaciones iniciales no requieren motivo y seguían funcionando.
* **Por qué pasó desapercibido:** las fixtures del test del dashboard no contenían ningún lote mixto y el doble de la API declaraba cuatro parámetros, de modo que la aserción 7.5 no podía ver el motivo ausente.
* **Corrección (sin enmienda de especificación, porque RF-07.3 ya lo exigía):** `submitBulkAssignment` valida el lote completo antes de cualquier petición (fail-fast, sin fallos silenciosos fila a fila) y envía el motivo como quinto argumento únicamente en las filas que son reasignación; el modal incorpora el campo obligatorio cuando el lote contiene responsables activos.
* **Cobertura nueva:** grupo 14 de `CoordinatorDashboardViewTest.mjs` (10 aserciones, incluida una emulación fiel de las reglas del backend) y caso 7 de `AssignTechnicianEndpointTest.php` (5 aserciones de defensa en profundidad).

## 3. Exenciones justificadas (no son desvíos)

1. **Órdenes preventivas (camino 2).** RF-PREV-02 EARS 2.2 exige técnico responsable y fecha programada, pero **no** exige motivo justificado, y el pliego preventivo **no contiene ningún EARS de reasignación**: la capacidad de cambiar de técnico una orden `SCHEDULED` existe en el repositorio, no en la especificación. Añadir un motivo obligatorio ahora sería introducir un requisito nuevo sin consenso (AGENTS §5, Líneas Rojas 1 y 5). El cambio de responsable **sí queda auditado** en `audit_log`.
   * **Recomendación (requiere enmienda aparte):** si se quiere simetría con las averías, proponer un EARS de reasignación preventiva con motivo obligatorio antes de tocar la interfaz.
2. **Autoasignación oportunista (camino 3).** EARS 2.3 es una **asignación inicial** restringida por SQL a `PENDING_ASSIGNMENT`: es imposible usarla para arrebatar una orden ya asignada, así que no hay reasignación que justificar. Queda auditada.
3. **Desasignación por reapertura (camino 4).** No cambia un responsable por otro: lo retira, y el motivo de reapertura ya es obligatorio por RF-09.

## 4. Garantías verificadas (invariantes)

* **Un único responsable activo simultáneo** (Art. V.3): el repositorio sustituye la columna en una sola sentencia dentro de transacción; la reasignación no retrocede el estado ni reescribe `assigned_at` (hito audited preservado, Art. III.1), y no se registran eventos ficticios al reasignar sobre el mismo técnico (Art. III.3).
* **Motivo obligatorio en reasignación de incidencias** (RF-07.3/EARS 5.5): validado en **tres capas** — cliente (modal de detalle y lote), controlador (422 `MISSING_REASSIGNMENT_REASON` / `REASSIGNMENT_REASON_TOO_SHORT`) y repositorio (defensa en profundidad, ahora con prueba directa).
* **Estados terminales intocables** (RF-07.2): `RESOLVED`, `CLOSED`, `CANCELLED` rechazan cualquier cambio de técnico, también invocando el repositorio directamente.
* **Trazabilidad** (Art. III/RNF-04): cada cambio de responsable produce, en la misma operación, una fila inmutable en `incident_history` y un evento en `audit_log` con actor autenticado y diff de estado.

## 5. Cobertura de pruebas resultante

| Suite | Qué garantiza |
| :--- | :--- |
| `tests/integration/AssignTechnicianEndpointTest.php` | HTTP real: RBAC, validaciones, umbral multibyte del motivo, auditoría con actor, hito preservado, estados terminales y **defensa en profundidad del repositorio (nuevo caso 7)** |
| `tests/unit/CoordinatorDashboardViewTest.mjs` | Consumidores del cliente: motivo obligatorio en lote mixto, quinto argumento sólo en reasignaciones, reintento tras fallo parcial y regresión del flujo de asignación simple (**grupo 14 nuevo**) |
| `php tests/run_all.php` | Batería global 100 % verde con la base restablecida a semillas |

## 6. Riesgos residuales y pendientes

1. **Técnico preseleccionado en el lote:** el modal propone `technicians[0]`; si ese técnico ya es el responsable de alguna fila, el backend responde `TECHNICIAN_ALREADY_ASSIGNED` y esa fila queda como error parcial. Es un rechazo correcto (evita eventos ficticios), pero la experiencia puede mejorarse.
2. **Reasignación preventiva sin motivo:** puede cambiar el responsable de una orden programada sin justificación, con auditoría pero sin motivo. Requiere enmienda de especificación si se quiere cerrar.
3. **Copy del flujo de consolidación:** el rótulo «Asignar/pendiente(s)» del modal masivo sigue pendiente de aprobación en `docs/propuesta_enmienda_copy_consolidacion_territorial.md`.
