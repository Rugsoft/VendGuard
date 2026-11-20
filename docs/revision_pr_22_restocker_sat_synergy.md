# Revisión de la PR #22 · Sinergia Reponedor ↔ SAT (Módulo 36)

| Campo | Valor |
| :--- | :--- |
| **Proyecto** | Gestor de Incidencias de Vending (*VendGuard*) |
| **PR** | [#22 · feat(restocker): sinergia operativa reponedor ↔ SAT (Módulo 36)](https://github.com/Rugsoft/VendGuard/pull/22) |
| **Rama origen** | `feat/restocker-sat-synergy` |
| **Rama destino** | `main` |
| **HEAD revisado (original)** | `f4b72a6` |
| **HEAD publicado por PR** | `edd4fef` (en `origin/feat/restocker-sat-synergy`) |
| **Head remoto antes de la publicación** | `ab7a85c` (datos históricos: 52 commits, 89 archivos, +16.659/−191) |
| **HEAD local consolidado (P0+P1)** | `19ff79d` (más cambios P2+P3 locales pendientes de commit) |
| **Base** | `7187116` (`main`) |
| **Fecha de la revisión** | 2026-11-20 |
| **Estado de la PR** | abierta, `MERGEABLE`; sin checks de CI; head remoto `edd4fef` (local por delante tras P0+P1+P2) |
| **Alcance** | Revisión original estática sobre `f4b72a6`. Actualizada tras la segunda auditoría integral (`P0`–`P3`) y las correcciones aplicadas en local. |
| **Resultado** | **7 hallazgos iniciales (`PR22-01`–`PR22-07`) + 12 hallazgos de auditoría (`P0`–`P2` + higiene `P3`)**: código y regresiones **solucionados en local** |

> **Actualización de corrección (2026-11-20):** además de `PR22-01`–`PR22-07` (head publicado `edd4fef`), quedan **solucionados en local** todos los hallazgos de la auditoría de mergeabilidad (`P0.1`–`P0.5`, `P1.6`–`P1.8` en `19ff79d`, y `P2.9`–`P2.12` + higiene `P3` en el árbol de trabajo actual).

---

## Veredicto

La rama `feat/restocker-sat-synergy` queda **saneada en local** tras resolver tanto los 7 hallazgos iniciales (`PR22-01`–`PR22-07`) como los 12 puntos de auditoría profunda (`P0`–`P2` + higiene `P3`). Los 5 bloqueantes `P0`, los 3 `P1` y los 4 `P2` cuentan con regresiones automatizadas en verde sobre PostgreSQL local y sobre la batería global (`441/441` suites · `17.018` aserciones · `0` fallos · `DocMetricsGuard COHERENTES`). Quedan únicamente las acciones remotas en GitHub (subir los commits locales, actualizar el cuerpo de la PR #22 y decidir entre *Squash & Merge* o reescritura de historial para limpiar los `Co-Authored-By:` previos).

---

## Resumen ordenado por importancia

| # | ID | Prioridad | Hallazgo | Impacto | Estado |
|---:|:---:|:---:|---|---|---|
| 1 | `PR22-01` | **P1 · Crítico** | El canal QR descarta `level1_issue_type` y nunca crea un aviso L1 | El alta QR incumple RF-01.2/D2 en todos los casos nuevos | ✅ Solucionado |
| 2 | `PR22-02` | **P1 · Crítico** | El cambio L1→L2 de Coordinación no actualiza `dispatch_level` | La incidencia queda en L1 con un `TECHNICIAN`, o el cambio falla con 422 | ✅ Solucionado |
| 3 | `PR22-03` | **P1 · Crítico** | El escalado manual desde L1 no recalcula urgencia/SLA y acepta valores del cliente | Una avería térmica/eléctrica puede quedar con prioridad y SLA antiguos o rebajados | ✅ Solucionado |
| 4 | `PR22-04` | **P2 · Alto** | `POST /dispatch-level` no valida el estado actual | Puede resucitar tickets `RESOLVED`/`CLOSED` y resetear `IN_PROGRESS`/`PENDING_*` | ✅ Solucionado |
| 5 | `PR22-05` | **P2 · Alto** | La foto del escalado solo se valida por extensión cuando llega `photo_path` | Se puede adjuntar una ruta sin archivo, tamaño ni magic bytes reales | ✅ Solucionado |
| 6 | `PR22-06` | **P3 · Medio** | `start` rechaza `REOPENED` pese al contrato y a la UI | Un L1 reabierto asignado no puede iniciarse | ✅ Solucionado |
| 7 | `PR22-07` | **P3 · Bajo** | La ruta contractual `upload-photo` no existe | Contrato y router no coinciden; la evidencia usa `visits/photo` | ✅ Solucionado y publicado (commit `7470bf9`) |

---

## 1. `PR22-01` · El canal QR descarta `level1_issue_type`

**Prioridad:** P1 · **Requisito afectado:** RF-01.2, D2, QA-AMB-01.

**Estado:** ✅ **Solucionado** · `QrScanController::report()` propaga `level1_issue_type`; regresión sobre `RSS8-SNACK-20` limpia en `RestockerSatSynergyApiTest`.

### Evidencia

- `src/Presentation/Controller/QrScanController.php:355-365`: `report()` construye `$payload` con `machine_code`, `category`, `description`, `reporter_*`, `retained_money_amount`, `refund_method` y `photo_path`, pero **no incluye `level1_issue_type`**.
- `src/Application/Service/QrReportService.php:183-198`: el servicio solo lee `$payload['level1_issue_type']`; si no llega, `$level1IssueType` queda `null` y `RestockerSatSynergyValidator::classifyCreation(null, ...)` devuelve `L2_SAT`.
- `specs/technical/restocker_sat_synergy_contracts.md:32`: `/api/qr/report` debe aceptar el parámetro opcional `level1_issue_type` y pre-clasificar el ticket en `L1_RESTOCKER` cuando sea elegible.

### Por qué la suite no lo detecta

El test `tests/integration/RestockerSatSynergyApiTest.php:574-634` envía `level1_issue_type`, pero la máquina `RSS8-SNACK-01` ya tiene un ticket L1 sembrado en la línea 266. Ese ticket se resuelve en la línea 372; sin embargo, `IncidentStatus::isActive()` considera `RESOLVED` activo y `isTerminal()` solo marca `CLOSED`/`CANCELLED`. El servicio fusiona la petición QR con el ticket antiguo, responde 200 y la aserción de la línea 634 lee ese ticket L1 preexistente. **No se está probando un alta QR nueva.**

### Impacto

Un aviso QR nuevo con un motivo L1 válido nace siempre en `L2_SAT`. El canal público incumple la pre-clasificación asistida de D2 y la suite no lo protege.

### Corrección propuesta

Añadir al payload:

```php
'level1_issue_type' => $request->getBodyParam('level1_issue_type'),
```

y crear un caso de regresión sobre una máquina sin incidencia activa que espere `201`, `dispatch_level = 'L1_RESTOCKER'`, `level1_issue_type` persistido y opacidad en la respuesta pública.

---

## 2. `PR22-02` · El cambio L1→L2 de Coordinación no actualiza el nivel

**Prioridad:** P1 · **Requisito afectado:** D1, D2.3, QA-CON-01; contrato de `PUT /api/coordinator/incidents/{id}/assign`.

**Estado:** ✅ **Solucionado** · el modal usa `changeDispatchLevel()` para L1→L2, evita preseleccionar al reponedor como técnico SAT y propaga la urgencia elegida con su motivo para recalcular el SLA y auditarlo.

### Evidencia

- `public/assets/js/views/CoordinatorDashboardView.js:1698-1703`: `submitAssignment()` solo llama a `performLevel1Dispatch()` cuando el destino es L1; para L2 llama a `performAssignment()`.
- `public/assets/js/views/CoordinatorDashboardView.js:1785-1792`: `performAssignment()` usa `api.coordinator.assignTechnician()` sin enviar `dispatch_level` y con `reassignment_reason = null`.
- `src/Presentation/Controller/CoordinatorController.php:1098-1140`: `assignTechnician()` no lee `dispatch_level`; solo acepta `technician_id`, urgencia y motivo de reasignación.
- `src/Infrastructure/Repository/PdoIncidentRepository.php:3698`: `assign()` actualiza el técnico, la urgencia y la custodia, pero no `dispatch_level`.
- `specs/technical/restocker_sat_synergy_contracts.md:30`: el contrato declara que `PUT /assign` acepta `dispatch_level` opcional (`L1_RESTOCKER` | `L2_SAT`) y valida la coherencia de rol.

### Casos afectados

- **L1 en `REGISTERED`**: `assignTechnician()` asigna un usuario `TECHNICIAN` y deja el expediente en `L1_RESTOCKER`. Se viola el invariante D1/QA-CON-01: `dispatch_level = L1_RESTOCKER` debe tener un `RESTOCKER`.
- **L1 en `ASSIGNED` o `IN_PROGRESS`**: el controlador trata la operación como reasignación, pero `performAssignment()` envía `reassignment_reason = null`; responde 422 y el cambio de nivel no se completa.
- **L1 con reponedor asignado**: `openAssignModal()` puede conservar el ID del reponedor en `assignTechnicianId` (línea 1194), lo que añade un 422 `TECHNICIAN_NOT_FOUND`.

El endpoint nuevo `POST /api/coordinator/incidents/{id}/dispatch-level` funciona para el despacho L1, pero la rama L2 no lo usa y tampoco aparece en el contrato técnico.

### Impacto

El flujo de triaje L1↔L2 de Coordinación no funciona y puede dejar una incidencia L1 asignada a un técnico SAT. Es una rotura directa de D1 y de la coherencia rol/nivel.

### Corrección propuesta

Usar `changeDispatchLevel()` también para el destino L2, o implementar `dispatch_level` en `PUT /assign` con la validación de rol y categoría del servicio de sinergia. Añadir regresiones para L1→L2 y L2→L1, tanto en frontend como en API.

---

## 3. `PR22-03` · El escalado manual desde L1 no recalcula urgencia/SLA y acepta valores del cliente

**Prioridad:** P1 · **Requisito afectado:** D3, RF-03.2, Art. II.

**Estado:** ✅ **Solucionado** · las ramas A y C derivan urgencia/SLA de la máquina, categoría y tier, actualizan `sla_target_at` e ignoran el payload; regresión de gateway.

### Evidencia

- `src/Infrastructure/Repository/PdoRestockerSatSynergyGateway.php:1062-1086`: la rama A del escalado actualiza la incidencia con:

```sql
urgency = COALESCE(:urgency::urgency_level, urgency),
sla_target_minutes = COALESCE(:sla_minutes::integer, sla_target_minutes)
```

- `:urgency` y `:sla_minutes` se toman directamente de `$payload` (líneas 1084-1085). El servicio no calcula esos valores; solo valida la nota interna y la foto.
- `public/assets/js/components/RestockerRouteView.js:1612-1616`: el payload real de la UI solo lleva `category`, `workshop_internal_note` y `client_mutation_id`; no envía urgencia ni SLA.
- La rama B sí deriva urgencia y SLA en servidor (`PdoRestockerSatSynergyGateway.php:1216-1228`), y la rama C vuelve a confiar en `$payload` (líneas 1150-1163).
- El test de la rama A (`tests/integration/PdoRestockerSatSynergyGatewayTest.php:348-363`) envía `HIGH` y `480`, pero no comprueba que el servidor los recalcule.

### Impacto

Al escalar un L1 `PRODUCT_JAM` en `MEDIUM` a `TEMPERATURE_COLD` o `ELECTRICAL_OFF`, la incidencia conserva la urgencia y el SLA antiguos. Un llamador autenticado puede enviar `urgency = LOW` o un SLA más laxo y rebajarlos. La rama C permite la misma manipulación cuando la categoría entrante es más grave. `sla_target_at` tampoco se actualiza.

Esto incumple la prioridad sanitaria del Art. II y el recálculo/elevación que exige D3.

### Corrección propuesta

Descartar `urgency` y `sla_target_minutes` del payload. Derivar urgencia, tier y objetivo con `UrgencyCalculator`/`SlaTargetCalculator` a partir de la máquina, la sede y la categoría, y actualizar `sla_target_at` en la misma transacción. Añadir una regresión que escale un L1 a térmica y compruebe `CRITICAL`/240 minutos, y otra que intente rebajar la urgencia por payload.

---

## 4. `PR22-04` · `POST /dispatch-level` no valida el estado actual

**Prioridad:** P2 · **Requisito afectado:** máquina de estados, Art. III.3, ventana de reapertura de 48 horas.

**Estado:** ✅ **Solucionado** · `assignDispatchLevel()` valida la fila bloqueada y solo permite el cambio desde `REGISTERED`, `ASSIGNED` o `REOPENED`; el resto responde `409 INVALID_INCIDENT_STATE_TRANSITION` sin mutar el expediente. La regresión cubre `RESOLVED`, `CLOSED`, `IN_PROGRESS` y `PENDING_INFO`.

### Evidencia

- `src/Presentation/Controller/CoordinatorController.php:224-260`: `dispatchLevel()` solo comprueba que la incidencia exista y que el asignado exista.
- `src/Infrastructure/Repository/PdoRestockerSatSynergyGateway.php:237-269`: `assignDispatchLevel()` bloquea la fila pero no valida `$current['status']`; calcula `status = ASSIGNED` o `REGISTERED` según haya asignado.
- La escritura de las líneas 273-294 fija `status = :status`, `started_at = NULL` y abre una nueva custodia, sin tocar `reopened_at`, `resolved_at` ni `closed_at`.

### Impacto

El endpoint puede convertir un ticket `RESOLVED`, `CLOSED` o `CANCELLED` en `ASSIGNED` sin pasar por la reapertura de 48 horas ni por su auditoría específica. También puede resetear un `IN_PROGRESS` o un `PENDING_INFO`/`PENDING_PARTS` a `ASSIGNED`, perdiendo el estado, el tiempo de intervención o la pausa.

### Corrección propuesta

Limitar el endpoint a los estados de triaje/asignación permitidos, o derivar los estados terminales a la máquina de estados de reapertura. Añadir pruebas con `RESOLVED`, `CLOSED`, `IN_PROGRESS` y `PENDING_INFO`.

---

## 5. `PR22-05` · La foto del escalado solo se valida por extensión si llega `photo_path`

**Prioridad:** P2 · **Requisito afectado:** Art. V.5; contrato de escalado.

**Estado:** ✅ **Solucionado** · `escalateToSat()` resuelve el `photo_path` con `LocalFileUploader::validateStoredImage()`: exige una ruta interna de `/uploads/`, un archivo existente, tamaño entre 1 byte y 5 MB, magic bytes JPG/PNG/WebP y extensión coherente con el MIME. Cualquier fallo devuelve `422 INVALID_EVIDENCE_IMAGE`.

### Evidencia

- `src/Core/Service/RestockerSatSynergyValidator.php:388-468`: `assertEscalationPhoto()` comprueba la extensión cuando recibe `photo_path` (líneas 402-416), pero el tamaño y los magic bytes solo se validan cuando llegan `photo_size_bytes` o `photo_bytes` (líneas 418-455).
- `RestockerSatSynergyService::escalateToSat()` llama al validador con el path y, como máximo, tamaño/mime del payload; el controlador no envía `photo_bytes`.
- El endpoint `POST /api/restocker/machines/{machineId}/escalate-to-sat` acepta el `photo_path` del cuerpo y el gateway lo inserta en la nota interna y en `photo_path`.

### Impacto

Un reponedor autenticado puede enviar `photo_path: "evidencia.jpg"` sin que exista el archivo, sin tamaño válido y sin firma real. El uploader de `/restocker/visits/photo` sí valida, pero el endpoint de escalado acepta el path sin demostrar que pasó por ese uploader. La evidencia del escalado no queda garantizada por el servidor.

### Corrección propuesta

Resolver los bytes desde el almacenamiento y validar tamaño y magic bytes, o exigir un identificador de subida verificado y restringir el path al directorio de evidencias. Añadir una prueba con un path inexistente y otra con un archivo renombrado.

---

## 6. `PR22-06` · `start` rechaza `REOPENED` pese al contrato y a la UI

**Prioridad:** P3 · **Requisito afectado:** RF-03.1.1, contrato de `POST /api/restocker/incidents/{id}/start`.

**Estado:** ✅ **Solucionado** · `startLevel1()` acepta `ASSIGNED` o `REOPENED`, conserva el estado de origen real en `incident_history` y `audit_log`, y reinicia `started_at` al comenzar una reapertura. La regresión API cubre un L1 `REOPENED` asignado al reponedor y comprueba la transición, el historial, la auditoría y el nuevo `started_at`.

### Evidencia

- `specs/technical/restocker_sat_synergy_contracts.md:19`: el inicio debe aceptar `ASSIGNED` / `REOPENED` → `IN_PROGRESS`.
- `public/assets/js/components/RestockerRouteView.js:3409`: la vista muestra «▶ Iniciar Nivel 1» para `ASSIGNED` y `REOPENED`.
- `src/Infrastructure/Repository/PdoRestockerSatSynergyGateway.php:109-114`: el gateway solo acepta `ASSIGNED` y devuelve `409 INVALID_INCIDENT_STATE_TRANSITION` para `REOPENED`.

### Impacto

Un L1 reabierto que conserve un reponedor asignado no puede iniciarse desde la UI. En el flujo normal, `reopen()` desasigna al técnico y el ticket no aparece en `assigned_l1_incidents` hasta que se le asigne otro, por lo que el caso es latente; aun así, contrato, UI y servidor no coinciden.

### Corrección propuesta

Permitir `REOPENED` en el guard, usar el estado de origen real en `incident_history` y refrescar `started_at` al iniciar una reapertura. Añadir una prueba con un ticket `REOPENED` asignado al reponedor.

---

## 7. `PR22-07` · Falta la ruta contractual `upload-photo`

**Prioridad:** P3 · **Requisito afectado:** contrato de subida de evidencia del reponedor.

**Estado:** ✅ **Solucionado** · `AppRouter` registra `POST /api/restocker/incidents/upload-photo` como alias autenticado de `uploadVisitPhoto`, reutilizando la validación de 5 MB y magic bytes. `uploadIncidentPhoto()` consume la ruta contractual y `visits/photo` se conserva para las visitas in situ. Publicado en la PR (commit `7470bf9`, head `403ef49`). El head remoto anterior era `ab7a85c`.

### Evidencia

- `specs/technical/restocker_sat_synergy_contracts.md:23`: declara `POST /api/restocker/incidents/upload-photo` para validar y subir la fotografía de evidencia.
- `src/Presentation/Routing/AppRouter.php:603`: solo registra `POST /api/restocker/visits/photo`.
- `public/assets/js/api.js:2270-2273`: `uploadIncidentPhoto()` usa `/restocker/visits/photo`, no la ruta contractual.

### Impacto

La funcionalidad de subida existe a través de la ruta de visitas, pero el contrato y el router no coinciden. Esto dificulta el consumo por otros clientes y agrava el hallazgo 5: el escalado acepta un `photo_path` sin verificar que venga de una subida validada.

### Corrección propuesta

Registrar la ruta contractual como alias o endpoint dedicado, reutilizando el uploader con validación de tamaño y magic bytes, y hacer que el escalado exija una referencia de subida verificada. Actualizar el contrato si se decide mantener `visits/photo` como única ruta.

---

## Corrección aplicada

| ID | Cambio | Regresión |
| :--- | :--- | :--- |
| `PR22-01` | `QrScanController` incluye `level1_issue_type` en el payload del alta QR. | `RestockerSatSynergyApiTest`: alta nueva sobre `RSS8-SNACK-20`, `201`, `L1_RESTOCKER` y motivo persistido (**88/88**). |
| `PR22-02` | `CoordinatorDashboardView` conmuta L1→L2 mediante `changeDispatchLevel()` y no reutiliza el id del reponedor como técnico SAT; la urgencia elegida viaja con su motivo, se recalcula el SLA y queda en `audit_log`. | Frontend de sinergia: L1→L2 llama a `dispatch-level` con urgencia y exige motivo (**22/22**); API: urgencia aplicada, SLA 480 y motivo auditado (**88/88**). |
| `PR22-03` | `PdoRestockerSatSynergyGateway` calcula urgencia, SLA y `sla_target_at` en servidor para las ramas A y C; descarta `urgency`/`sla_target_minutes` del cliente. | Gateway: L1 térmico con payload `LOW`/60 exige `CRITICAL`/240; rama C recalcula al alza (**26/26**). |
| `PR22-04` | `PdoRestockerSatSynergyGateway::assignDispatchLevel()` limita el cambio a `REGISTERED`/`ASSIGNED`/`REOPENED` y el controlador traduce el fallo a `409`; los estados terminales y operativos no se resucitan ni se resetean. | API: `RESOLVED`, `CLOSED`, `IN_PROGRESS` y `PENDING_INFO` responden `409`; estado, nivel, asignado, `started_at`/`paused_at`, custodia y auditoría permanecen intactos (**88/88**). |
| `PR22-05` | `RestockerSatSynergyService::escalateToSat()` valida el `photo_path` contra el almacenamiento local con `LocalFileUploader::validateStoredImage()` antes de delegar en el gateway. | Servicio: path inexistente, archivo renombrado y archivo > 5 MB devuelven `422 INVALID_EVIDENCE_IMAGE` (**40/40**); API: los tres rechazos no crean expedientes y el PNG real se persiste (**88/88**). |
| `PR22-06` | `PdoRestockerSatSynergyGateway::startLevel1()` admite `ASSIGNED` y `REOPENED`; usa el estado de origen real en historial/auditoría y refresca `started_at` al iniciar una reapertura. | API: un L1 `REOPENED` asignado responde `200 IN_PROGRESS`, el historial y la auditoría conservan `REOPENED` y `started_at` se actualiza (**88/88**). |
| `PR22-07` | `AppRouter` registra `/api/restocker/incidents/upload-photo` como alias autenticado de `uploadVisitPhoto`; `api.js` usa esa ruta para el escalado y mantiene `visits/photo` para las visitas in situ. | API: la subida base64 válida responde `201` con `/uploads/` y los magic bytes inválidos `422 INVALID_FILE_TYPE` (**88/88**); frontend: el cliente usa la ruta contractual (**244/244**). Publicado en la PR (commit `7470bf9`). |

---

## Segunda auditoría integral de mergeabilidad (`P0`–`P3`) · Corrección aplicada

| Prioridad | ID | Cambio aplicado | Regresión / Evidencia |
| :---: | :---: | :--- | :--- |
| **P0** | `P0.1` | `RestockerRouteController` usa `gatewayErrorResponse()` al iniciar, resolver, escalar o volcar conflictos para respetar el estado HTTP real del gateway (`403`/`404`/`409`/`422`). | `RestockerSatSynergyApiTest` (**98/98**) |
| **P0** | `P0.2` | `lockActiveIncident()` alinea el filtro con `uq_machine_active_ticket` (`NOT IN ('CLOSED','CANCELLED')`), reabre en garantía `<= 48 h` sin colisionar y autocierra expirados `> 48 h`. | `RestockerSatSynergyApiTest` (**98/98**) |
| **P0** | `P0.3` | `QrReportService` evalúa la cuarentena sanitaria real de la máquina al crear QR y las ramas de escalado recalculan ranking SAT post-escalado y encolan alerta de frío (`Outbox`). | `RestockerSatSynergyApiTest` (**98/98**) |
| **P0** | `P0.4` | Rama B (`escalateBranchB`) deja de volcar `workshop_internal_note` en `incidents.description` (Art. V.4) y persiste `sla_target_at`, `machine_type_snapshot` y `cycle_started_at`. | `RestockerSatSynergyApiTest` (**98/98**) |
| **P0** | `P0.5` | Catálogo `AuditActionLabels` / `ACTION_*` completo (`AuditActionCatalogTest` **27/27**), 0 literales hex nuevos en JS (`DesignTokenDebtRatchetTest`) y métricas `DocMetricsGuard` sincronizadas. | `php tests/run_all.php` (**441/441**) |
| **P1** | `P1.6` | `assignTechnician()` exige `L2_SAT` (`422`), `dispatchLevel()` encola aviso de asignación, `assignSuggestedTechnician()` enruta L1 vía `performLevel2Dispatch()`, y los flujos oportunistas promueven `L1_RESTOCKER` → `L2_SAT`. | `RestockerSatSynergyApiTest` (**98/98**) · `RestockerSatSynergyConstitutionalTest` (**20/20**) |
| **P1** | `P1.7` | Escalado SAT monotónico en Ramas A/C sin degradación de urgencia/SLA y autoasignación de ticket SAT `REGISTERED` en Rama C. | `PdoRestockerSatSynergyGatewayTest` (**31/31**) · `RestockerSatSynergyApiTest` (**98/98**) |
| **P1** | `P1.8` | Ruta contractual `POST /api/restocker/incidents/{id}/escalate-to-sat` registrada, compatibilidad con `resolution_diagnosis`/`resolution_action` y `conflict-dump` con `stop_id`/`operation_type` reales. | `RestockerSatSynergyApiTest` (**98/98**) · `restocker_route_view.test.mjs` (**245/245**) |
| **P2** | `P2.9` | `suggestRestockers()` y `findNextRestocker()` usan la fecha operativa `Europe/Madrid`; `suggestRestockers()` reutiliza el ranking de 6 escalones de `RestockerZoneCoverageRanker` (Módulo 31, con `OFF_SHIFT` disponibles y filtro de ausentes) y `findNextRestocker()` prioriza `ON_SHIFT` > `MEAL_BREAK`. | `PdoRestockerSatSynergyGatewayTest` (**31/31**) |
| **P2** | `P2.10` | `TechnicianAbsenceService` exige ausencia viva (`starts <= now <= ends`) solo para `RESTOCKER` antes de ejecutar `handoverLevel1()`; el relevo L1 incluye `REOPENED`, preserva estado y pausa en reasignación, y cierra `paused_at` + acumula `total_pending_info_seconds` al liberar a `REGISTERED`. | `TechnicianAbsenceServiceTest` (**19/19**) · `PdoRestockerSatSynergyGatewayTest` (**31/31**) |
| **P2** | `P2.11` | `findReplay()` se ejecuta dentro de transacción con bloqueo `FOR UPDATE` y verifica `outcome_status = 'APPLIED'`, `operation_type` y ámbito de parada (`409 MUTATION_ID_CONFLICT`). | `PdoRestockerSatSynergyGatewayTest` (**31/31**) |
| **P2** | `P2.12` | `technicianCoincidences()` y `restockerCoincidences()` reemplazan bucles N+1 por una consulta SQL por lotes con `IN (...)` y mapa completo de sedes. | `PdoRestockerSatSynergyGatewayTest` (**31/31**) |
| **P3** | `P3` | Informe de revisión depurado (fila duplicada eliminada y tablas actualizadas). Acciones remotas pendientes de confirmación: subir commits locales, actualizar descripción remota de PR #22 y decidir *Squash & Merge* vs limpieza de `Co-Authored-By` en commits ya publicados. | Documentación + `DocMetricsGuard` (**COHERENTES**) |

---

## Verificación ejecutada

| Comprobación | Resultado |
| :--- | :--- |
| `php -l` sobre PHP modificados (`P0`–`P2`) | Sin errores |
| `node --check` sobre JS/MJS modificados (`P0`–`P1`) | Sin errores |
| `php tests/run_all.php` (batería hermética global) | **441/441 suites · 17.018 aserciones · 0 fallos · `Cifras documentadas: COHERENTES` · `exit 0`** |
| `PostgresRestockerSatSynergySchemaTest` (`new_engine`) | **30/30**, `exit 0` |
| `PdoRestockerSatSynergyGatewayTest` (`new_engine`) | **31/31**, `exit 0` (con regresiones `P2.9`–`P2.12`) |
| `RestockerSatSynergyApiTest` (`new_engine`) | **98/98**, `exit 0` |
| `RestockerSatSynergyConstitutionalTest` (`new_engine`) | **20/20**, `exit 0` |
| `TechnicianAbsenceServiceTest` (Unit) | **19/19**, `exit 0` (con regresión `P2.10` retroactiva vs viva) |
| `gh pr view 22` remotos | head remoto publicado `edd4fef` (antes `ab7a85c`); commits locales `19ff79d` + cambios `P2`/`P3` pendientes de push |
| `gh pr checks 22` | Sin checks reportados en la rama |

Las suites de esquema, gateway, API y constitucional requieren `PG_TEST_DSN` apuntando al PostgreSQL local del arnés.

---

## Recomendación

1. ✅ **Corregido en local:** `PR22-01`–`PR22-07` y los hallazgos `P0.1`–`P2.12` + higiene documental `P3` están aplicados y certificados con la batería global (`441/441`, `17.018` aserciones) y las suites de integración PostgreSQL del Módulo 36.
2. **Publicación y fusión (`P3` remoto):** confirmar commit local del lote `P2`/`P3`, hacer `git push` a `feat/restocker-sat-synergy`, actualizar el cuerpo de la PR #22 en GitHub y fusionar preferiblemente con **Squash & Merge** sobre `main` (para dejar un historial limpio en `main` sin `Co-Authored-By:` heredados, salvo que se prefiera reescribir el historial de la rama con `force-push`).
