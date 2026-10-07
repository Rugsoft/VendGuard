# Cierre de los hallazgos F-1, F-2 y F-3 · Módulo 10 (hilo de comentarios)

**Módulo:** 10 · Hilo de comentarios bidireccional (T-COM-01 … T-COM-20)
**Hallazgos de origen:** [verificacion_modulo_10_humo_funcionalidad_flujo.md](verificacion_modulo_10_humo_funcionalidad_flujo.md)
**Fecha:** 07/10/2026 · **Rama:** `incident-comments`
**Naturaleza del cambio:** 2 enmiendas de especificación aprobadas (F-1 y F-3) + 1 corrección de incumplimiento (F-2)

---

## 1 · Resumen

| Hallazgo | Naturaleza | Dirección aprobada | Estado |
|---|---|---|---|
| **F-1** | Silencio de la especificación: solo cubría el «cambio de técnico», no el «sin técnico» tras la reapertura | Lectura histórica para el técnico con antecedentes de intervención; publicación bloqueada hasta la reasignación | **Cerrado** (RF-05.4 + caso límite 7) |
| **F-2** | Incumplimiento de **EARS 5.1.2** (`specs/functional/metrics_audit_spec.md`): la reapertura no dejaba rastro en `audit_log` | Emitir el evento inmutable `REOPEN_TICKET` desde el controlador de sede | **Cerrado** |
| **F-3** | Contrato de API inconsistente: `status` devolvía la etiqueta `"REABIERTA"` mientras el resto de endpoints devuelven el enum canónico | Normalizar el payload y enmendar el contrato aprobado (Art. V.5) | **Cerrado** |

**Resultado global de la verificación:**

```
php tests/run_all.php → 208/208 suites · 7.932 aserciones · 0 fallos · EXIT 0
Batería negra sobre la API real → 21/21 comprobaciones (humo + flujo completo)
```

---

## 2 · F-1 · Lectura histórica del técnico con antecedentes

### 2.1 Enmienda de especificación

* `specs/10-incident-comments/spec.md` y `specs/functional/incident_comments_spec.md` → **RF-05.4** (nuevo):

  > MIENTRAS la incidencia se encuentre reabierta en garantía (`REOPENED`) y **sin técnico asignado**, el sistema DEBE conceder al técnico que intervino previamente acceso de **solo lectura** al hilo, con las mismas reglas de proyección de su canal, bloqueando la publicación hasta que coordinación le reasigne el expediente. El acceso de lectura no se concede a ningún otro técnico: leer el hilo no constituye responsabilidad activa sobre la avería.

* **Caso límite 7** («Reapertura en garantía sin técnico asignado») en ambos documentos.
* `specs/10-incident-comments/plan.md`: contrato del bloque `incident` del DTO con `can_comment` y `read_only_reason`, y la tercera rama del pie del modal.

**Por qué no colisiona con las reglas vigentes:** la desasignación al reabrir es obligatoria (EARS 9.1); la lectura no crea un segundo responsable activo y el hilo es una bitácora de solo adición (Art. III), así que abrirla en consulta no altera ningún dato.

### 2.2 Regla de antecedentes acreditados

Un técnico tiene antecedentes si el historial inmutable del expediente registra una transición firmada con su `user_id` hacia `IN_PROGRESS` (inicio o reanudación de la intervención) o `RESOLVED` (resolución). Se evalúa con `IncidentRepositoryInterface::getHistory()` + `IncidentHistory::getUserId()/getToStatus()`: **no se añadió ningún método al repositorio**, de modo que los ~25 dobles de test que implementan la interfaz no se tocaron. El historial es append-only, así que nadie puede fabricarse antecedentes ni perderlos retroactivamente.

### 2.3 Cambios de código (3 capas)

| Capa | Archivo | Cambio |
|---|---|---|
| Aplicación | `src/Application/Service/IncidentCommentService.php` | En el bloque `incident` del DTO del hilo: `can_comment` (bool) y `read_only_reason` (`REOPENED_AWAITING_REASSIGNMENT` o `null`). Regla única y centralizada: `can_comment = aceptaComentarios && (canal ≠ técnico || expediente asignado a este técnico)`. El sellado sigue comunicándose con `is_sealed` y no arrastra motivo de reapertura |
| Presentación | `src/Presentation/Controller/TechnicianController.php` | Nuevo resolutor de **lectura** (`resolveReadableIncident`): asignado → acceso completo; `REOPENED` + sin asignar + antecedentes → acceso de consulta; resto → 403 `NOT_ASSIGNED_TO_TECHNICIAN`. El POST mantiene el guard estricto. Se extrajo `locateRequestedIncident()` para compartir la resolución de identificador (400/404) entre ambos caminos |
| Interfaz | `public/assets/js/components/IncidentCommentThreadModal.js` | Nueva rama de pie `v-else-if="isReadOnlyReopened"` con el aviso *«Expediente reabierto pendiente de reasignación: el historial se mantiene consultable»*, y guarda de cortesía en `submitComment()` que ni siquiera llama al API |

### 2.4 Comportamiento resultante

| Actor sobre un expediente `REOPENED` sin asignar | Antes | Ahora |
|---|---|---|
| Técnico que intervino | 403 en lectura **y** escritura | **200 en lectura** (públicos + notas internas, `can_comment = false`) · 403 al publicar |
| Técnico ajeno | 403 | 403 (sin cambios) |
| Sede / coordinación | 201 | 201 (sin cambios) |
| Técnico tras la reasignación | — | 200 con `can_comment = true` y publicación 201 |

### 2.5 Evidencia

| Suite | Cobertura del hallazgo | Resultado |
|---|---|---|
| `tests/unit/IncidentCommentServiceTest.php` | **Caso 8** (8.1–8.8): permiso efectivo, proyección íntegra en solo lectura, sede/coordinación, sellado sin motivo de reapertura y contrato de diez claves | **42/42**, exit 0 |
| `tests/unit/TechnicianCommentEndpointTest.php` | **Caso 9** (9.1–9.8): lectura con antecedentes, 403 sin ellos, 403 si está reasignado a otro, 201 tras la reasignación | **59/59**, exit 0 |
| `tests/integration/TechnicianCommentsApiTest.php` | **Caso 9** con la API y MariaDB reales: resolver → reabrir → leer 200 con `can_comment = false` → POST 403 sin persistir → reasignar → 201 | **39/39**, exit 0 |
| `tests/unit/IncidentCommentThreadModalSealedGuardTest.mjs` | **Grupo 5** (5.1–5.9): aviso, precedencia del sellado, y que el envío bloqueado no llama al API ni pierde el borrador | **32/32**, exit 0 |
| `tests/unit/IncidentCommentThreadDtoTest.php` | Contrato del bloque `incident` ampliado a diez claves | exit 0 |

---

## 3 · F-2 · Evento de auditoría `REOPEN_TICKET`

### 3.1 Fundamento

`specs/functional/metrics_audit_spec.md` **EARS 5.1.2** obliga a registrar un evento ante «cambio de estado del ticket (… o reaperturas dentro de 48h)». La reapertura escribía `incident_history` pero no `audit_log`. No es una decisión de diseño: es un hueco de trazabilidad, y `AuditLogger` ya documentaba `'REOPEN_TICKET'` como acción válida sin que nadie la emitiera.

### 3.2 Elección del nombre del evento

Se usa **`REOPEN_TICKET`** porque es el valor que el visor de auditoría ya esperaba: `public/assets/js/components/AuditLogViewer.js` tiene su insignia («Reapertura de Ticket») y su opción de filtro, que hasta ahora devolvían cero filas. La corrección **reactiva una funcionalidad de interfaz** sin escribir una línea de frontend.

### 3.3 Cambio de código

`src/Presentation/Controller/LocationPortalController.php`:
* `AuditLogger` inyectable con **inicialización perezosa** (`audit()`), por el mismo motivo que el servicio de comentarios: el controlador se instancia en contextos unitarios sin base de datos.
* Tras la reapertura correcta se emite el evento con:

| Campo | Valor |
|---|---|
| `entity_type` / `entity_id` | `TICKET` / expediente reabierto |
| `action` | `REOPEN_TICKET` |
| Actor | `user_id = null`, `user_role = SITE_MANAGER`, `user_name = 'Responsable de Sede · <sede>'` (misma identidad que el hilo) |
| `previous_state` | `{status: RESOLVED, assigned_technician_id, resolved_at}` |
| `new_state` | `{status: REOPENED, assigned_technician_id: null, reopen_reason, reopened_at}` |
| `metadata` | `{ticket_code, reopen_count}` |

* Contrato documentado en `specs/technical/api_contracts.md` §3.4 y en el pseudo-código de `specs/technical/plan.md` §3.3 (con la nota de que el evento se emite tras el commit, igual que `INCIDENT_ASSIGNED` en el triaje).

### 3.4 Evidencia

| Suite | Cobertura | Resultado |
|---|---|---|
| `tests/unit/LocationPortalReopenAuditTest.php` *(nueva)* | 18 aserciones: payload contractual del evento, actor de sede, estados previo/nuevo, metadata, y **ausencia de evento** en los tres rechazos (ventana vencida, Avería Crónica, motivo corto) | **18/18**, exit 0 |
| `tests/integration/ReopenIncidentEndpointTest.php` | 1.10–1.13 (evento real en `audit_log`) y 3.8 (el intento bloqueado no añade eventos) | **53/53**, exit 0 |
| Batería negra (API real) | W5.3/W5.4/W8.1/W8.2 | 4/4 |

Fase roja previa: la suite unitaria fallaba con `Error: Unknown named parameter $auditLogger` (el controlador no tenía sumidero de auditoría) antes de implementar.

**Limitación declarada:** el evento se emite después del commit de la reapertura, no dentro de su transacción. Es la misma convención que sigue `INCIDENT_ASSIGNED` en el triaje; cerrar esa ventana exigiría compartir la transacción con el repositorio y queda fuera del alcance de este cierre.

---

## 4 · F-3 · Normalización del contrato de reapertura

### 4.1 Enmienda de contrato

* `specs/technical/api_contracts.md` §3.4: `status: "REOPENED"`, `status_label: "Reabierta"`, `status_canonical` como **alias deprecado una versión**, efectos obligatorios de la reapertura y catálogo completo de errores (400/401/403/422).
* `specs/technical/plan.md` §3.3 y `specs/technical/tasks.md` T-24: la etiqueta legible vive en `status_label`; el estado persistido es el canónico.
* `docs/manual_e2e_verification.md`: prosa y diagrama del hito de reapertura actualizados, incluido el evento de auditoría.

### 4.2 Cambio de código

`LocationPortalController::reopenIncident()` responde `status` con el valor canónico del expediente reabierto, añade `status_label` y conserva `status_canonical`, `assigned_technician_id`, `reopened_at`, `reopen_reason` e `incident`.

### 4.3 Compatibilidad verificada antes del cambio

El único consumidor del frontend (`LocationPortalView.onIncidentReopened()`) ignora el payload y recarga el parque de máquinas; `ReopenTicketModal.js` reemite la respuesta tal cual. Riesgo de rotura en cliente: nulo.

### 4.4 Suites actualizadas

| Archivo | Aserción |
|---|---|
| `tests/integration/ReopenIncidentEndpointTest.php` | 1.2, 1.2b (`status_label`), 1.2c (alias), 2.2 y 8.3 (HTTP real) |
| `tests/integration/SiteManagerPartsDataSegregationTest.php` | 3.7 |
| `tests/unit/TechnicianPreventiveSparePartsAndSegregationTest.php` | 3.4 |
| `tests/unit/ReopenTicketModalTest.mjs` | 3.3: el modal reemite la respuesta **verbatim** en lugar de un literal |
| `tests/Manual/E2EVerificationRunner.php` | 4.4 |

**Defecto colateral descubierto y corregido en el mismo paso:** el doble `reopen()` de `TechnicianPreventiveSparePartsAndSegregationTest` devolvía un expediente en `REGISTERED` (simplificación del stub). Al dejar de estar el estado hardcodeado en el controlador, la aserción lo destapó: se corrigió el doble para que replique el contrato real (`REOPENED` desasignado). Anteriormente esa parte del test era vacua.

---

## 5 · Verificación de cierre

### 5.1 Suite completa del repositorio

```
======================================================================
 RESUMEN DE EJECUCIÓN GLOBAL (T-39)
======================================================================
 Tiempo de ejecución total : 81.82 segundos
 Suites de pruebas PHP Unit : 92 / 92 pasadas
 Suites de pruebas JS Unit  : 53 / 53 pasadas
 Suites de Integración PHP  : 63 / 63 pasadas
 ──────────────────────────────────────────────────────────────────
 Total Suites Ejecutadas    : 208
 Total Aserciones Evaluadas : 7.932
 Fallos Detectados          : 0
 Base de datos restablecida : SÍ (Semillas intactas)
======================================================================
 RESULTADO: 100% EN VERDE. (0 errors, 0 failures)
```

Línea base de la rama antes de este trabajo: 207 suites / 7.874 aserciones. La diferencia son la suite nueva de auditoría y las ampliaciones de las suites existentes.

### 5.2 Batería negra sobre la API real (21/21)

Servicio en `http://127.0.0.1:8000`, semillas canónicas y ciclo completo multi-actor:

| Bloque | Comprobaciones |
|---|---|
| Humo | servicio vivo, estado canónico (13 expedientes / 0 comentarios), cinco inicios de sesión, catálogo de máquinas |
| Flujo | reporte `REGISTERED` → asignación → inicio → conversación en ambos canales → resolución → **reapertura con contrato canónico y evento de auditoría** → **lectura histórica del técnico (200, `can_comment = false`)** → **403 al publicar** → **403 de un técnico ajeno** → **reasignación → 201** → ventana vencida 422 sin evento |

```
 Comprobaciones: 21 · correctas: 21 · fallos: 0
 BD restaurada: incidents=13 comments=0
```

---

## 6 · Limitaciones declaradas

1. **Sin capturas de pantalla.** El *webview* de previsualización dejó de componer fotogramas durante la sesión (`document.visibilityState === 'hidden'`); la evidencia de la rama de solo lectura del modal es estructural y reactiva (sondas de plantilla y de estado sobre la instancia del componente), no gráfica.
2. **La lectura histórica no reabre la ruta.** `GET /api/technician/my-route` sigue sin listar el expediente reabierto y sin asignar: leer el hilo no convierte al técnico en responsable de la avería. El acceso histórico aplica a quien conserva el hilo abierto o el enlace directo; hacer que la parada reaparezca en la ruta como solo lectura sería otra decisión de producto.
3. **`GET /api/technician/machines/{id}/history` conserva su propio 403** para el técnico desasignado. Su contrato (`specs/technical/technician_machine_history_contracts.md` §2.1-2.2) documenta esa regla de forma explícita, así que extenderlo exige otra enmienda y no se ha tocado.
4. **La auditoría de la reapertura no es atómica** con su commit (apartado 3.4).
5. **El bloqueo por Avería Crónica no emite evento propio.** Se decidió no ampliar el alcance; queda como candidato para una iteración de auditoría.
6. **F-4 CERRADO (07/10/2026).** La deriva de vocabulario del visor de auditoría - ocho filtros que nunca podían devolver una fila y 44 acciones del backend sin etiqueta ni filtro - se corrigió con un catálogo único derivado de los escritores reales y una guarda anti-deriva en la suite: [verificacion_cierre_hallazgo_f4.md](verificacion_cierre_hallazgo_f4.md).

---

## 7 · Trazabilidad de artefactos

**Especificación:** `specs/10-incident-comments/spec.md`, `specs/functional/incident_comments_spec.md`, `specs/10-incident-comments/plan.md`, `specs/technical/api_contracts.md`, `specs/technical/plan.md`, `specs/technical/tasks.md`.
**Código:** `src/Application/Service/IncidentCommentService.php`, `src/Presentation/Controller/LocationPortalController.php`, `src/Presentation/Controller/TechnicianController.php`, `public/assets/js/components/IncidentCommentThreadModal.js`.
**Pruebas nuevas:** `tests/unit/LocationPortalReopenAuditTest.php`.
**Pruebas ampliadas:** `tests/unit/IncidentCommentServiceTest.php`, `tests/unit/TechnicianCommentEndpointTest.php`, `tests/unit/IncidentCommentThreadDtoTest.php`, `tests/unit/TechnicianPreventiveSparePartsAndSegregationTest.php`, `tests/unit/IncidentCommentThreadModalSealedGuardTest.mjs`, `tests/unit/ReopenTicketModalTest.mjs`, `tests/integration/ReopenIncidentEndpointTest.php`, `tests/integration/TechnicianCommentsApiTest.php`, `tests/integration/SiteManagerPartsDataSegregationTest.php`, `tests/Manual/E2EVerificationRunner.php`.
**Documentación:** este informe y la nota de cierre en [verificacion_modulo_10_humo_funcionalidad_flujo.md](verificacion_modulo_10_humo_funcionalidad_flujo.md), `docs/manual_e2e_verification.md`.
