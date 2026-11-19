# Auditoría de la Spec 36 · Brechas que impiden el 100 %

- **Módulo:** 36 · Sinergia Operativa Reponedor ↔ Técnico SAT (`36-restocker-sat-synergy`)
- **Especificación auditada:** [`specs/36-restocker-sat-synergy/spec.md`](../specs/36-restocker-sat-synergy/spec.md) (v1.0)
- **Espejo funcional:** [`specs/functional/restocker_sat_synergy_spec.md`](../specs/functional/restocker_sat_synergy_spec.md)
- **Contratos técnicos:** [`specs/technical/restocker_sat_synergy_contracts.md`](../specs/technical/restocker_sat_synergy_contracts.md)
- **Sistema de Diseño:** [`docs/design.md`](design.md)
- **Tareas:** [`specs/36-restocker-sat-synergy/tasks.md`](../specs/36-restocker-sat-synergy/tasks.md) · [`odd/tasks/restocker-sat-synergy.md`](../odd/tasks/restocker-sat-synergy.md)
- **Matriz de trazabilidad:** [`specs/36-restocker-sat-synergy/traceability-matrix.md`](../specs/36-restocker-sat-synergy/traceability-matrix.md)
- **Fecha de auditoría:** 2026-11-19
- **Última actualización:** 2026-11-19 · FIX-36-01, FIX-36-02, FIX-36-03 y FIX-36-04 cerrados con composición real
- **Rama:** `feat/restocker-sat-synergy`
- **Base auditada:** `95f9af1f1f481b14eeaf4b3acfcd623e791486e8` · árbol limpio antes de crear este acta
- **Alcance:** auditoría de los `RF-01`–`RF-05`, sus criterios EARS, `RNF-01`–`RNF-03`, las decisiones `D1`–`D4`, los casos `C1`–`C2`, las 9 salvaguardas `QA-*`, el §6, los 4 criterios de Definition of Done y las 12 tareas `T-RSS-01`–`T-RSS-12`, con ejecución real de las suites. FIX-36-01, FIX-36-02, FIX-36-03 y FIX-36-04 se corrigieron con prueba de composición; el resto se mantiene como hallazgos de solo lectura.

> Este documento es un acta viva de hallazgos: registra la evidencia previa de las brechas y los cierres de FIX-36-01, FIX-36-02, FIX-36-03 y FIX-36-04. El resto de brechas permanece abierto. No modifica la Spec, la matriz ni las tareas.

---

## 1. Veredicto ejecutivo

**La Spec 36 no está cumplida al 100 %.**

La implementación de esquema, dominio, gateway, servicios, API y frontend es amplia, y sus suites están en verde:

```text
php tests/run_all.php
441 suites · 16.987 aserciones · 0 fallos · DocMetricsGuard coherente · exit=0
```

Las 8 suites `new_engine` ejecutadas (4 del módulo y 4 de convergencia/paridad global) también pasan:

```text
PostgresRestockerSatSynergySchemaTest   28/28
PostgresSchemaTest                     153/153
PostgresConstitutionalTest              86/86
SchemaConvergenceParityTest             42/42
PdoRestockerSatSynergyGatewayTest       24/24
RestockerSatSynergyApiTest              55/55
RestockerSatSynergyConstitutionalTest   17/17
CloudDeploySchemaParityTest             55/55
```

Y las 12 tareas están marcadas `[x]` en `tasks.md` y en el ODD.

**Sin embargo, aún quedan una brecha funcional de frontend y una brecha de defensa en profundidad.** Los tests de componentes pasan porque ejercitan servicios y gateways directamente con dobles o con colaboradores inyectados; las composiciones de QR, C1, escalado y alta de Coordinación ya están cubiertas, pero el alta de Portal sigue sin prueba de composición.

En concreto:

1. **`QA-LIM-01` / Art. II — ✅ corregido el 2026-11-19:** el QR real ya inyecta la sinergia, propaga el método de reembolso y relee el expediente antes de ajustar urgencia/SLA. La composición se certifica en `RestockerSatSynergyApiTest` bloques 6 a 8 (55/55).
2. **`C1` — ✅ corregido el 2026-11-19:** el cierre de turno, la ausencia y la baja inyectan la sinergia, comprueban el resultado de `handoverLevel1()` y ejecutan el relevo antes de cerrar o desactivar. La composición se certifica en `RestockerSatSynergyApiTest` bloque 7.
3. **`D3` / `RF-03.2` — ✅ corregido el 2026-11-19:** el escalado del reponedor ya inyecta el resolvedor de técnico y el notificador; la composición se certifica en `RestockerSatSynergyApiTest` bloque 8.
4. **`D2.2` / `RF-01.2` — ✅ corregido el 2026-11-19:** el alta de Coordinación lee `level1_issue_type`, aplica `classifyCreation()` con las exclusiones de cuarentena y reclamación y persiste `dispatch_level`/`level1_issue_type`; la composición se certifica en `RestockerSatSynergyApiTest` bloque 9.

Además, falta el botón de escalado dentro del asistente de Visita de Reposición (`RF-05.2.1`) y el mapeo determinista de `level1_issue_type` no es un invariante duro de dominio/SQL (`EARS 01.1.2`).

**Conclusión corta:** FIX-36-01, FIX-36-02, FIX-36-03 y FIX-36-04 están cerrados y su composición real queda protegida por prueba. La batería ya cubre las composiciones de QR, C1, escalado y alta de Coordinación; el alta de Portal sigue pendiente de composición y el verde de `run_all` no equivale al cumplimiento funcional completo de la Spec 36.

---

## 2. Matriz de brechas

| Orden | ID | Prioridad | Brecha | Requisito afectado | Estado |
|---:|---|---|---|---|---|
| **1** | **FIX-36-01** | **P0 · Bloqueante sanitario** | El QR real no auto-escalaba una absorción térmica/eléctrica o una reclamación digital sobre un L1; además no asignaba técnico, no encolaba aviso y un `update()` posterior podía revertir la promoción | `RF-02.2`, `QA-LIM-01`, Art. II | **Cerrada (2026-11-19)** |
| **2** | **FIX-36-02** | **P0 · Bloqueante C1** | El cierre de turno, la ausencia y la baja no invocaban `handoverLevel1` en producción; el cierre además ignoraba el resultado `ok=false` | `RF-04.2`, `C1`, Art. V.3 | **Cerrada (2026-11-19)** |
| **3** | **FIX-36-03** | **P0 · Bloqueante D3** | El escalado del reponedor se construye solo con el gateway: sin `technicianResolver` ni `notifier`, queda en `REGISTERED` y sin aviso en el outbox | `RF-03.2`, `D3`, Art. V.3 | **Cerrada (2026-11-19)** |
| **4** | **FIX-36-04** | **P1 · Alto** | El alta de Coordinación no leía `level1_issue_type` ni fijaba `dispatch_level`; siempre creaba `L2_SAT` | `RF-01.2`, `D2.2` | **Cerrada (2026-11-19)** |
| **5** | **FIX-36-05** | **P1 · Alto** | El botón «⚡ Escalar Avería al SAT» no existe dentro del asistente de Visita de Reposición del Módulo 34 | `RF-05.2.1` | **Abierta** |
| **6** | **FIX-36-06** | **P2 · Medio** | El mapeo determinista `level1_issue_type` ↔ `category` no se impone en el constructor de `Incident` ni en el CHECK SQL; solo en `classifyCreation()` | `EARS 01.1.2`, `RNF-02` | **Abierta** |
| **7** | **FIX-36-07** | **P2 · Medio** | Falta prueba de composición del alta de Portal con L1; la absorción QR, el relevo C1, el escalado y el alta de Coordinación ya están cubiertos | DoD 2, DoD 4, cobertura | **Abierta** |
| **8** | **FIX-36-08** | **P3 · Documental** | La matriz y los cierres de tareas declaran cubiertas C1 y `QA-LIM-01` con tests que no ejercitan la composición real; las composiciones QR/C1/Coordinación aún no se citan y hay una cifra desactualizada | Trazabilidad, DoD, tareas | **Abierta** |

### Lectura de prioridades

- **P0:** incumple una regla constitucional o impide certificar el módulo. Debe corregirse antes de cualquier cierre.
- **P1:** no rompe necesariamente el runtime, pero deja un requisito funcional sin cumplir.
- **P2:** deuda de defensa en profundidad o de cobertura; mantiene la certificación incompleta.
- **P3:** deuda documental y de trazabilidad; debe corregirse después de los anteriores para que la matriz no vuelva a certificar de más.

---

## 3. Detalle de las brechas

### FIX-36-01 · El QR real no ejecutaba `QA-LIM-01` · P0 · ✅ Cerrada (2026-11-19)

#### Requisito

`RF-02.2` y `QA-LIM-01` exigen que, cuando `QrReportService::mergeIntoExistingIncident()` absorba un reporte grave sobre un ticket `L1_RESTOCKER` (`TEMPERATURE_COLD`, `ELECTRICAL_OFF`, reclamación > 10,00 € o método digital), el sistema, **en la misma transacción**:

- conmute `dispatch_level = 'L2_SAT'` y actualice la categoría;
- cierre la custodia del reponedor con `QR_AUTO_ESCALATED_TO_SAT`;
- asigne al mejor Técnico SAT mediante `TechnicianAssignmentSuggester` (`QR_AUTO_ESCALATION_TO_SAT`) o lo deje en `REGISTERED` si no hay candidato;
- encole la alerta en `notification_outbox`.

El Art. II exige además que una avería térmica o eléctrica jamás quede en `L1_RESTOCKER`.

#### Evidencia previa (antes de la corrección)

`src/Presentation/Controller/QrScanController.php:122-128` construye `QrReportService` sin el séptimo argumento (`RestockerSatSynergyService`):

```php
$this->qrReportService = $qrReportService
    ?? new QrReportService(
        null,
        null,
        null,
        NotificationOutboxEnqueuerFactory::createOrNull()
    );
```

`QrReportService::mergeIntoExistingIncident()` solo ejecuta el bloque de auto-escalado si `$this->synergyService !== null`; en producción es `null`. Por tanto, un reporte QR de `TEMPERATURE_COLD` sobre un ticket L1:

- no se promociona a `L2_SAT`;
- no cierra la custodia del reponedor;
- no asigna técnico SAT;
- no encola alerta;
- y la categoría entrante no se persiste: el ticket permanece en `L1_RESTOCKER` con la categoría antigua (por ejemplo, `OTHER`), dejando el aviso térmico solo como comentario.

Además, aunque se inyectara el servicio sin corregir el flujo:

1. `QrReportService` pasa `assignTechnicianId = null`, por lo que `promoteToSatOnQrMerge()` deja el ticket en `REGISTERED` y no invoca ningún sugeridor.
2. `QrReportService` también pasa `refundMethod = null`, por lo que una reclamación digital (`BIZUM` / `TRANSFERENCIA_BANCARIA`) no dispara la condición de auto-escalado aunque el servicio esté inyectado.
3. `promoteToSatOnQrMerge()` no encola nada en `notification_outbox`; `autoEscalateQrMerge()` no recibe ningún notificador.
4. Si la urgencia entrante es mayor, `QrReportService` llama después a `PdoIncidentRepository::update($escalated)` con el objeto `Incident` **antiguo** (L1, categoría antigua, reponedor asignado). Ese `update()` escribe `assigned_technician_id`, `category`, `status` y `started_at` desde el objeto obsoleto (`PdoIncidentRepository.php:2792-2825`), revirtiendo la asignación, la categoría y el estado que acababa de fijar el gateway.

#### Cobertura previa

`RestockerSatSynergyIntegrationsTest` 27/27 prueba `shouldAutoEscalateQrMerge()` y que el servicio delega en el gateway, pero con un **gateway falso**. `RestockerSatSynergyApiTest` cubría entonces 18/18 el alta QR L1 y la opacidad del nivel, no la absorción de un reporte grave sobre un L1. La composición real se añadió en el bloque 6 de esa misma suite (ver «Cierre»).

#### Impacto (antes de la corrección)

Es una violación directa del Art. II y del caso límite §6.1: una máquina con un aviso L1 por consumibles puede recibir un reporte de frío y quedarse en la cola del reponedor, sin SAT y sin cuarentena. El test verde no lo detecta porque no ejecuta esa composición.

#### Corrección aplicada

1. `QrScanController` compone `QrReportService` con `RestockerSatSynergyService` sobre `PdoRestockerSatSynergyGateway`, `SuggestedTechniciansService` y el outbox real. La inyección ya no depende de un doble de test.
2. El `refund_method` del payload QR se propaga a `QrReportService::report()`, a `classifyCreation()` y a `mergeIntoExistingIncident()` hasta `autoEscalateQrMerge()`.
3. Tras `promoted=true`, `QrReportService` relee el expediente con `findById()` antes de ajustar urgencia y SLA. El `update()` posterior ya no reescribe categoría, custodio, estado ni `started_at` desde la foto L1 obsoleta.
4. El técnico SAT se resuelve con `SuggestedTechniciansService` y se pasa como `assignTechnicianId`; `NotificationOutboxEnqueuerService::enqueueAssignment()` encola la asignación y, cuando la promoción entra en riesgo de cadena de frío, la alerta `COLD_CHAIN_IMMEDIATE_ALERT` a Coordinación.
5. `PdoRestockerSatSynergyGateway::closeOpenCustody()` normaliza el actor de sistema `0` a `NULL` en `unassigned_by`; sin ello, la custodia del QR violaba la FK de usuarios.
6. La composición se prueba en el bloque 6 de `RestockerSatSynergyApiTest`, con el router y PostgreSQL reales: 42/42 en total.

#### Criterios de aceptación

- [x] Un reporte QR térmico sobre un L1 termina en `L2_SAT` con `category = 'TEMPERATURE_COLD'`.
- [x] La custodia del reponedor se cierra con `QR_AUTO_ESCALATED_TO_SAT`.
- [x] Se asigna un Técnico SAT si hay candidato; si no, el gateway lo deja en `REGISTERED`. La alerta a Coordinación se emite en el mismo outbox cuando la promoción entra en riesgo de cadena de frío (`COLD_CHAIN_IMMEDIATE_ALERT`, aserción 6.7); el fallback sin candidato está cubierto por `PdoRestockerSatSynergyGatewayTest` 24/24.
- [x] La alerta llega a `notification_outbox`.
- [x] Una reclamación digital (`BIZUM` / `TRANSFERENCIA_BANCARIA`) sobre un L1 también auto-escala.
- [x] La prueba falla si se elimina la inyección del servicio o si el `update()` vuelve a escribir la categoría/asignación antigua.

#### Cierre

Cerrada el 2026-11-19 con evidencia reproducible:

- **Composición real:** `RestockerSatSynergyApiTest` bloque 6, **42/42** en total, exit 0. El escenario térmico verifica `dispatch_level=L2_SAT`, `category=TEMPERATURE_COLD`, `status=ASSIGNED`, `assigned_technician_id=7`, `urgency=CRITICAL`, `sla_target_minutes=240`, custodia `QR_AUTO_ESCALATED_TO_SAT` cerrada y `QR_AUTO_ESCALATION_TO_SAT` abierta, aviso de asignación y alerta de cadena de frío a Coordinación. El escenario digital con `BIZUM` y 9,50 € promociona a L2 con `category=OTHER`.
- **No reversión:** la aserción 6.3 comprueba que el `update()` posterior conserva la asignación al técnico SAT y `status=ASSIGNED`.
- **Regresiones:** `PdoRestockerSatSynergyGatewayTest` 24/24, `RestockerSatSynergyConstitutionalTest` 17/17, `QrRefundCaptureTest` 105 aserciones, `QrSanitaryQuarantineModeTest` 35 aserciones (con `.env.battery`), `QrEndpointsIntegrationTest` 0 fallos y `PublicRefundTrackingApiTest` 58/58 en batería limpia.
- **Batería global:** `441/441 suites · 16.987 aserciones · 0 fallos · exit 0`.

---

### FIX-36-02 · `C1` no estaba cableado en cierre, ausencia ni baja · P0 · ✅ Cerrada (2026-11-19)

#### Requisito

`RF-04.2` y `C1` exigen:

- **Cierre de turno:** si el reponedor tiene un L1 en `IN_PROGRESS`, el cierre se rechaza con `409 SHIFT_CLOSE_BLOCKED_IN_PROGRESS_L1`; los `ASSIGNED`/`PENDING_INFO` se relevan al siguiente reponedor en turno o vuelven a `REGISTERED`.
- **Ausencia vigente:** los L1 vivos se relevan con `ABSENCE_COVERAGE_REASSIGN`.
- **Baja de cuenta (`is_active = FALSE`):** los L1 vivos se relevan o vuelven a `REGISTERED`.

#### Evidencia previa (antes de la corrección)

- `src/Presentation/Controller/TechnicianShiftController.php:69-77` construye `ShiftLifecycleService` con 7 argumentos; el octavo (`RestockerSatSynergyService`) queda `null`.
- `src/Presentation/Routing/Router.php:339` instancia los controladores con `new $controller()` sin inyección, por lo que se usa el constructor por defecto anterior.
- `src/Application/Service/ShiftLifecycleService.php:271` llama a `$this->synergyService?->handoverLevel1(...)` pero **ignora el resultado**. El gateway devuelve `['ok' => false, 'error_code' => 'SHIFT_CLOSE_BLOCKED_IN_PROGRESS_L1']`; no lanza excepción, así que el turno se cierra igualmente.
- `src/Presentation/Controller/CoordinatorAbsenceController.php:39-48` construye `TechnicianAbsenceService` con 8 argumentos; el noveno (`RestockerSatSynergyService`) queda `null`, por lo que `createAbsence()` no ejecuta el relevo.
- `src/Application/Service/AdminUserService.php` (`deactivateUser()`) hace `softDelete()` sin relevo.
- `src/Application/Service/AdminStaffService.php` (`deactivateUser()`) cierra el turno directamente con `shiftRepo->closeShift()`, sin pasar por `ShiftLifecycleService` ni por la sinergia.

Resultado: **no se entrega el 409, no se reasignan los L1 y la baja puede dejar avisos asignados a una cuenta inactiva**. El gateway 24/24 demuestra que el gateway sabe bloquear y relevar; no demuestra que la ruta real lo llame.

#### Cobertura previa

`PdoRestockerSatSynergyGatewayTest` 24/24 prueba `handoverLevel1()` directamente. `RestockerSatSynergyIntegrationsTest` 27/27 prueba la delegación del servicio. Ninguna suite ejecutaba `POST /api/technician/shifts/close` con un L1 en `IN_PROGRESS`, ni `PATCH /api/coordinator/users/{id}/deactivate` con un L1 `ASSIGNED`, ni el alta de ausencia real. La composición se añadió en el bloque 7 de `RestockerSatSynergyApiTest` (ver «Cierre»).

#### Impacto (antes de la corrección)

El reponedor podía cerrar su jornada dejando un L1 en curso; los avisos `ASSIGNED` quedaban huérfanos; una baja dejaba tickets L1 asignados a un usuario inactivo. Es un incumplimiento directo de `C1` y del Art. V.3 (custodio activo único y coherente).

#### Corrección aplicada

1. `TechnicianShiftController` compone `ShiftLifecycleService` con `RestockerSatSynergyService` sobre `PdoRestockerSatSynergyGateway`; `closeShift()` comprueba `ok` y lanza `InvalidShiftTransitionException` con `SHIFT_CLOSE_BLOCKED_IN_PROGRESS_L1` y HTTP 409.
2. `CoordinatorAbsenceController` compone `TechnicianAbsenceService` con la sinergia; el relevo se ejecuta dentro de la transacción del alta, después de comprobar solapamientos, y un `ok=false` lanza `InvalidAbsenceException` 409.
3. `AdminUserService::deactivateUser()` recibe la sinergia opcional y, para un `RESTOCKER`, ejecuta `handoverLevel1()` antes del `softDelete`; un bloqueo se traduce en `PendingIncidentsBlockedException` 409.
4. `AdminStaffService::deactivateUser()` recibe la sinergia opcional y ejecuta el relevo antes de cerrar el turno; después delega en `AdminUserService` para la baja lógica.
5. `PdoRestockerSatSynergyGateway` hace que `begin()`/`commit()`/`rollback()` respeten la transacción exterior: el relevo participa en ella sin confirmarla ni deshacerla por su cuenta.
6. La composición se prueba en el bloque 7 de `RestockerSatSynergyApiTest`, con router y PostgreSQL reales.

#### Criterios de aceptación

- [x] `POST /api/technician/shifts/close` con un L1 `IN_PROGRESS` responde `409 SHIFT_CLOSE_BLOCKED_IN_PROGRESS_L1` y no cierra el turno.
- [x] Con un L1 `ASSIGNED`, el cierre reasigna al reponedor suplente o lo devuelve a `REGISTERED`.
- [x] El alta de una ausencia vigente ejecuta el relevo `ABSENCE_COVERAGE_REASSIGN`.
- [x] La baja de cuenta ejecuta el relevo y no deja L1 asignados a un usuario inactivo.
- [x] Las pruebas fallan si se vuelve a construir el servicio sin la sinergia o si se ignora el resultado.

#### Cierre

Cerrada el 2026-11-19 con evidencia reproducible:

- **Composición real:** `RestockerSatSynergyApiTest` bloque 7, **42/42** en total, exit 0. 7.1/7.2 verifican el 409 y que el turno sigue abierto; 7.3-7.6 el cierre con un L1 `ASSIGNED` reasignado al suplente y la custodia `RESTOCKER_SHIFT_HANDOVER`; 7.7-7.9 el alta de ausencia vigente y la custodia `ABSENCE_COVERAGE_REASSIGN`; 7.10-7.12 la baja directa sin dejar el L1 en la cuenta inactiva; 7.13-7.15 la baja por staff con el turno cerrado y el motivo contractual.
- **Regresiones:** `ShiftLifecycleServiceTest` 12/12, `TechnicianAbsenceServiceTest` 17/17, `RestockerSatSynergyIntegrationsTest` 27/27, `AdminUserServiceTest` 74 aserciones, `AdminStaffRestockerServiceTest` 26/26, `RestockerShiftAndWorkHoursTest` 38/38, `PdoRestockerSatSynergyGatewayTest` 24/24, `QrEndpointsIntegrationTest` 0 fallos, `CoordinatorAbsenceApiTest` 23/23 y `RestockerStaffHttpTest` 18/18.
- **Batería global:** **441/441 suites · 16.987 aserciones · 0 fallos · exit 0**, con `RestockerSatSynergyApiTest` 42/42 en el canal `new_engine`.

---

### FIX-36-03 · El escalado del reponedor no asigna técnico ni encola aviso · P0 · ✅ Cerrada (2026-11-19)

#### Requisito

`RF-03.2` y `D3` exigen que «⚡ Escalar Avería al SAT»:

- valide nota, foto y categoría;
- ejecute las tres ramas bajo `FOR UPDATE`;
- invoque `TechnicianAssignmentSuggester` para asignar al mejor Técnico SAT (`RESTOCKER_ESCALATION_TO_SAT`) o deje `REGISTERED` si no hay candidato;
- encole la alerta en `notification_outbox`.

#### Evidencia previa (antes de la corrección)

`src/Presentation/Controller/RestockerRouteController.php:166-168` construye el servicio solo con el gateway:

```php
$this->synergyService = $synergyService ?? new RestockerSatSynergyService(
    new \VendGuard\Infrastructure\Repository\PdoRestockerSatSynergyGateway($pdo)
);
```

`RestockerSatSynergyService::__construct()` acepta `technicianResolver` y `notifier` opcionales; `escalateToSat()` solo resuelve técnico si el resolver no es `null` y solo notifica si el notifier no es `null`. En producción ambos son `null`.

El gateway, por su parte, solo lee `assign_technician_id` del payload (`PdoRestockerSatSynergyGateway.php:518`); no tiene un sugeridor propio. Si el cliente no envía ese campo, el ticket queda en `REGISTERED` con `assigned_technician_id = NULL` y no se encola `RESTOCKER_ESCALATION_TO_SAT`.

#### Cobertura previa

`PdoRestockerSatSynergyGatewayTest` 24/24 prueba las tres ramas, pero el escenario proporciona un `assign_technician_id` explícito. `RestockerSatSynergyConstitutionalTest` 17/17 prueba la absorción sobre un L2 preexistente, no la asignación automática desde una máquina limpia. No había una prueba HTTP real del escalado sin `assign_technician_id`.

#### Impacto (antes de la corrección)

El reponedor escalaba una avería y el ticket quedaba en la cola de Coordinación en vez de llegar al SAT; además no se generaba el aviso interno. El flujo funcionaba en los tests porque el test inyectaba el técnico, pero no en la composición real.

#### Corrección aplicada

1. `RestockerRouteController::buildSynergyService()` compone el gateway real, `SuggestedTechniciansService` con `PdoTechnicianSuggestionRepository`, `PdoIncidentRepository` y `NotificationOutboxEnqueuerFactory::create($pdo)`.
2. El resolvedor consulta el ticket activo de la máquina y reutiliza `SuggestedTechniciansService::getSuggestions()`; si la máquina está limpia, usa la nueva `getSuggestionsForMachine()` con categoría, urgencia y SLA del escalado. Solo devuelve el `top_technician_id` del ranking, sin duplicar la regla de decisión.
3. Se añadió `findSuggestionPhotoForMachine()` al puerto `TechnicianSuggestionRepositoryInterface` y a `PdoTechnicianSuggestionRepository`. La foto usa sede, zona, tipo y modelo de la máquina y alimenta el mismo motor de ranking; `findSuggestionPhoto()` se refactorizó sobre un `buildSuggestionPhoto()` compartido.
4. El notificador descarta replays idempotentes y la Rama C (`MERGED_INTO_EXISTING_SAT_TICKET`), relee el expediente y encola `enqueueAssignment()` si queda `ASSIGNED` o `enqueueSla2hUnassignedAlert()` —alerta a Coordinación— si queda `REGISTERED`.
5. El bloque 8 de `RestockerSatSynergyApiTest` ejecuta el router y PostgreSQL reales con dos máquinas limpias: una con técnico elegible y otra sin candidato tras desactivar temporalmente al único técnico.

#### Criterios de aceptación

- [x] El escalado sin `assign_technician_id` asigna al mejor Técnico SAT disponible.
- [x] Sin candidato, el ticket queda `REGISTERED` y se encola alerta a Coordinación.
- [x] La Rama C conserva la custodia del técnico existente; el notificador no la toca y el gateway la certifica en 24/24.
- [x] La prueba falla si el controlador vuelve a construir el servicio sin resolver/notifier: 8.2 quedaría en `REGISTERED` y 8.3/8.6 sin aviso.

#### Cierre

Cerrada el 2026-11-19 con evidencia reproducible:

- **Composición real:** `RestockerSatSynergyApiTest` bloque 8, **55/55** en total, exit 0. 8.1-8.3 verifican `L2_SAT`, `ASSIGNED`, `assigned_technician_id=7` y la fila `TECHNICIAN_ASSIGNED` del outbox; 8.4-8.6 verifican `REGISTERED`, `assigned_technician_id=NULL` y la alerta `SLA_IMMINENCE_2H_UNASSIGNED` con `recipient_role=COORDINATOR`.
- **Regresiones:** `RestockerSatSynergyServiceTest` 34/34, `PdoRestockerSatSynergyGatewayTest` 24/24, `SuggestedTechniciansApiTest` 24/24 y `php -l` sin errores en los cinco archivos modificados.
- **Alcance:** no se modificaron FIX-36-01 ni FIX-36-02; sus bloques 6 y 7 siguen verdes dentro de la misma suite.

---

### FIX-36-04 · El alta de Coordinación no pre-clasificaba L1 · P1 · ✅ Cerrada (2026-11-19)

#### Requisito

`RF-01.2` y `D2.2` exigen que, cuando se cree una incidencia desde el panel de Coordinación (`POST /api/coordinator/incidents`) informando un `level1_issue_type` válido, el expediente nazca en `dispatch_level = 'L1_RESTOCKER'` con la categoría del mapeo `QA-AMB-01`, salvo exclusión térmica/eléctrica, cuarentena o reclamación grave.

#### Evidencia previa (antes de la corrección)

En el bloque de alta (`src/Presentation/Controller/CoordinatorController.php:406-620`) no se leía `level1_issue_type`. El bloque de construcción (líneas 525-540) creaba el `Incident` sin `dispatchLevel` ni `level1IssueType`:

```php
$incident = new Incident(
    id: null,
    ticketCode: $ticketCode,
    machineId: $machine->getId(),
    locationId: $location->getId(),
    category: $category,
    description: $description,
    urgency: $urgency,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    reporterName: $reporterName,
    cycleStartedAt: $now->format(DATE_ATOM),
    slaTargetAt: $slaTargetAt->format(DATE_ATOM),
    slaTierSnapshot: $slaTier,
    slaTargetMinutes: $slaTargetMinutes
);
```

El constructor de `Incident` aplicaba el valor por defecto `L2_SAT`. Por tanto, toda alta desde Coordinación nacía en `L2_SAT`, aunque el operador hubiera seleccionado un motivo L1.

#### Corrección aplicada

1. `CoordinatorController::createIncident()` lee `level1_issue_type`, lo normaliza con `Level1IssueType::tryFrom()` —vacío o inválido queda en `null`— y ejecuta `RestockerSatSynergyValidator::classifyCreation()` antes de calcular urgencia y SLA, igual que Portal y QR. Le pasa el estado sanitario hidratado en `Machine`, el importe retenido y el método de devolución, de modo que las cuatro exclusiones de `D2` se evalúan también en Coordinación. La categoría solicitada solo se conserva cuando la clasificación no impone el mapeo `QA-AMB-01`.
2. El `Incident` se construye con `dispatchLevel` y `level1IssueType`. `PdoIncidentRepository::create()` los persiste en las dos ramas de inserción: la genérica y la CTE de PostgreSQL. La rama CTE era la que los omitía y hacía que la base aplicara `L2_SAT`/`NULL` por defecto.
3. `RestockerSatSynergyApiTest` bloque 9 cubre el alta real desde Coordinación en HTTP/PostgreSQL: 9.1-9.2 crean con `MISSING_CONSUMABLES` y verifican `L1_RESTOCKER`, `OTHER`, `MISSING_CONSUMABLES` y `REGISTERED`; 9.3-9.4 fuerzan `TEMPERATURE_COLD`; 9.5 cubre una máquina en `QUARANTINE`; 9.6 una reclamación de 10,01 €; y 9.7 el método digital `BIZUM`. Los cuatro escenarios excluyentes persisten `L2_SAT` con `level1_issue_type` como rastro.

#### Criterios de aceptación

- [x] Un alta de Coordinación con `level1_issue_type` elegible nace en `L1_RESTOCKER`.
- [x] Una exclusión térmica/eléctrica, cuarentena o reclamación grave fuerza `L2_SAT`.
- [x] La prueba falla si el controlador vuelve a ignorar `level1_issue_type`.

#### Cierre verificado

- **Composición real:** `RestockerSatSynergyApiTest` bloque 9, **55/55** en total, exit 0. La fila 9.2 queda en `dispatch_level=L1_RESTOCKER`, `category=OTHER`, `level1_issue_type=MISSING_CONSUMABLES`, `status=REGISTERED`; las filas 9.4-9.7 fuerzan `L2_SAT` por térmica, cuarentena, importe de 10,01 € y método digital, conservando `level1_issue_type` como rastro.
- **Regresiones:** `php -l` sin errores en `Machine`, `PdoMachineRepository`, el controlador, el repositorio de incidencias y la suite; los bloques 6 a 8 de QR/C1/escalado siguen verdes dentro de la misma suite.
- **Alcance:** se añadió el estado sanitario a la hidratación de `Machine` y se persistió el importe retenido declarado en el alta; no se modificaron FIX-36-01, FIX-36-02 ni FIX-36-03.

---

### FIX-36-05 · Falta el escalado dentro del asistente de visita · P1 · Abierta

#### Requisito

`RF-05.2.1` exige el botón «⚡ Escalar Avería al SAT» en cada tarjeta de máquina **y dentro del asistente de Visita de Reposición del Módulo 34**, para reportar averías descubiertas a puerta abierta.

#### Evidencia

`public/assets/js/components/RestockerRouteView.js` solo invoca `openEscalate()` en:

- línea 3249 — tarjeta de máquina;
- línea 3333 — bandeja de avisos L1.

El modal de visita comienza en la línea 3960 (`data-testid="modal-restock-visit"`) y no contiene ninguna llamada a `openEscalate()` ni el literal «⚡ Escalar Avería al SAT». El test `restocker_route_view.test.mjs` 234/234 certifica el botón en la tarjeta y en la bandeja, pero no en el asistente de visita.

#### Impacto

El reponedor puede escalar desde la tarjeta de la máquina, pero no desde el flujo guiado de visita, que es el contexto natural donde descubre la avería con la máquina abierta. Es una desviación de alcance funcional, no un fallo de backend.

#### Corrección propuesta

1. Añadir el botón en el modal de visita, junto a las acciones de la visita, reutilizando `openEscalate(machine)` y conservando el contexto de la máquina.
2. Añadir una aserción en `restocker_route_view.test.mjs` que compruebe el botón dentro de `modal-restock-visit` y que abre el modal de escalado con la máquina de la visita.

#### Criterios de aceptación

- [ ] El botón «⚡ Escalar Avería al SAT» aparece dentro del asistente de visita.
- [ ] Abre el modal de escalado con la máquina y la incidencia correctas.
- [ ] La prueba falla si se retira del modal de visita.

---

### FIX-36-06 · El mapeo determinista no es un invariante duro · P2 · Abierta

#### Requisito

`EARS 01.1.2` exige imponer «tanto en el dominio como mediante la restricción SQL `chk_incidents_l1_dispatch_coherence`» que, si `level1_issue_type IS NOT NULL`, su categoría compatible en la creación inicial sea determinista (`STOCKOUT_EMPTY_SLOT`/`MINOR_SPIRAL_JAM` → `PRODUCT_JAM`, `EMPTY_CHANGE_TUBES` → `PAYMENT_SYSTEM`, `MISSING_CONSUMABLES` → `OTHER`).

#### Evidencia

- `src/Core/Domain/Model/Incident.php` valida L1 térmico/eléctrico, `PENDING_PARTS`, `pending_parts_reason` y el rastro de escalado, pero **no llama a `Level1IssueType::isCompatibleWith()`**.
- `isCompatibleWith()` solo tiene su definición en `src/Core/Domain/ValueObject/Level1IssueType.php`; no hay ninguna llamada en `src/`.
- El CHECK `chk_incidents_l1_dispatch_coherence` (`database/supabase_migrations/032_restocker_sat_synergy.sql`) no impone la correspondencia `level1_issue_type` ↔ `category`.
- El comportamiento externo es correcto porque `RestockerSatSynergyValidator::classifyCreation()` sobrescribe la categoría con `mappedCategory()` en QR y Portal; pero la defensa en profundidad que pide el EARS no está completa.

#### Impacto

Una escritura directa desde el repositorio o una futura ruta que construya `Incident` sin pasar por `classifyCreation()` podría persistir una pareja incoherente. No es un fallo observado en los canales actuales, pero sí una brecha de defensa en profundidad y de fidelidad literal al EARS.

#### Corrección propuesta

1. Validar en el constructor de `Incident` (o en una fábrica de creación) que `level1IssueType` es compatible con `category` cuando el ticket nace en L1.
2. Si se quiere defensa SQL, añadir la comprobación al CHECK solo para la creación inicial; como el SQL no distingue creación de actualización, valorar una función/trigger o aceptar una enmienda documental aprobada.
3. Añadir una prueba que intente construir/persistir una pareja incompatible y falle.

#### Criterios de aceptación

- [ ] Una pareja `level1_issue_type`/`category` incompatible no puede persistirse en L1.
- [ ] La regla se aplica en el dominio, no solo en el validador de creación.
- [ ] La prueba falla si se elimina la validación.

---

### FIX-36-07 · Faltan pruebas de composición · P2 · Abierta

#### Requisito

El DoD 2 exige que los servicios de aplicación impongan las reglas, y el DoD 4 que toda la batería pase al 100 %. La trazabilidad debe demostrar que la composición real, no solo los servicios aislados, cumple la Spec.

#### Evidencia

A fecha de cierre de FIX-36-01, FIX-36-02, FIX-36-03 y FIX-36-04, sigue sin prueba de composición:

- alta de Portal con `level1_issue_type`.

La absorción QR, el relevo C1, el escalado real y el alta L1 de Coordinación ya tienen composición en `RestockerSatSynergyApiTest` bloques 6 a 9 (55/55). El alta de Portal sigue probándose con dobles o colaboradores inyectados.

#### Impacto

La batería no protege las rutas que realmente usa el usuario. Una regresión en la composición puede pasar desapercibida, como ya ocurre con los P0.

#### Corrección propuesta

Añadir pruebas de integración HTTP/PostgreSQL para cada flujo anterior, con aserciones sobre el estado persistido y los efectos colaterales (custodia, asignación, outbox, auditoría). No sustituir estas pruebas por dobles de servicio.

#### Criterios de aceptación

- [ ] Cada flujo de composición tiene una prueba que falla si se elimina la inyección correspondiente.
- [ ] Las pruebas verifican el estado en PostgreSQL, no solo el retorno HTTP.
- [ ] La matriz enlaza cada prueba con su requisito.

---

### FIX-36-08 · La trazabilidad y los cierres de tareas sobrecertifican · P3 · Abierta

#### Evidencia

- `specs/36-restocker-sat-synergy/traceability-matrix.md` declara `C1` cubierto por `RestockerSatSynergyIntegrationsTest` 27/27 y `PdoRestockerSatSynergyGatewayTest` 24/24; ambas pruebas ejercitan el servicio/gateway, no la ruta real que debería inyectarlo. La composición real ya existe en `RestockerSatSynergyApiTest` bloques 6 a 9 (55/55), pero la matriz aún no la cita.
- La misma matriz declara `QA-LIM-01` cubierto por `RestockerSatSynergyIntegrationsTest` 27/27 y `RestockerSatSynergyConstitutionalTest` 17/17; la primera usa un gateway falso y la segunda prueba la absorción sobre un L2 preexistente, no la promoción L1→L2 desde QR. La composición real ya existe en `RestockerSatSynergyApiTest` bloques 6 a 9 (55/55), pero la matriz aún no la cita.
- `docs/auditoria_spec_36_brechas.md` (este documento) es la corrección de esa sobrecertificación.
- La matriz cita `LocationPortalReopenAuditTest` con 9/9; la ejecución real produce 18 líneas `[PASS]`. Es una imprecisión menor, ajena al núcleo del módulo.
- `tasks.md` y el ODD marcan 12/12 tareas `[x]`, pero los cierres de `T-RSS-06`, `T-RSS-07`, `T-RSS-08`, `T-RSS-11` y `T-RSS-12` contienen afirmaciones que la composición real no respalda o que la matriz aún no enlaza:
  - `T-RSS-06`: aviso al outbox y sugerencia de técnico en el escalado; el escalado del reponedor ya los inyecta (bloque 8) y el alta L1 de Coordinación está cubierta (bloque 9). Queda el alta de Portal como flujo pendiente de composición.
  - `T-RSS-07`: `ShiftLifecycleService`/`TechnicianAbsenceService` ya relevan L1 en producción; la matriz aún no cita la composición del bloque 7.
  - `T-RSS-08`: rutas RBAC y controladores; el alta de Coordinación ya pre-clasifica L1 (bloque 9); queda la cobertura del alta de Portal.
  - `T-RSS-11`: botón de escalado en ticket/máquina/visita; falta en la visita.
  - `T-RSS-12`: batería global en verde; QR, C1, escalado y alta de Coordinación ya tienen composición, pero el alta de Portal y el botón de visita no.

#### Impacto

La matriz y las tareas son la base de la certificación. Si declaran cubierto lo que no se ejecuta, una auditoría futura puede volver a dar por cerrado el módulo sin corregir los P0.

#### Corrección propuesta

1. No cerrar la Spec 36 hasta que FIX-36-05 esté corregido y cubierto por prueba de composición (FIX-36-01 a FIX-36-04 ya están cerrados).
2. Actualizar la matriz para que cada fila enlace una prueba que ejecute la ruta real, no solo el servicio.
3. Corregir el recuento de `LocationPortalReopenAuditTest` o retirar la cita si no es una suite del módulo.
4. Reabrir o anotar los cierres de `T-RSS-06/07/08/11/12` hasta que la evidencia los respalde.

#### Criterios de aceptación

- [ ] La matriz no declara `C1` ni `QA-LIM-01` cubiertos por pruebas que no ejecutan la composición real; debe citar los bloques 6 a 9.
- [ ] Las 12 tareas tienen evidencia reproducible y coherente con el código de producción.
- [ ] El estado del módulo refleja la realidad: no se declara 100 % hasta cerrar los P0/P1.

---

## 4. Definition of Done (§8)

| DoD | Comprobación | Estado |
| :--- | :--- | :--- |
| **1. Migración `032` y convergencia 1:1 con tipos #43/#44, ampliaciones, columnas, CHECK y 4 semillas** | `032_restocker_sat_synergy.sql` (11.724 bytes) con tipos, columnas, FK, CHECK, índice, operaciones Offline y las 4 parejas `RC-L1-*`/`SS-L1-*`; `supabase_seeds.sql` con 11 referencias; `SchemaConvergenceParityTest` 42/42; `PostgresRestockerSatSynergySchemaTest` 28/28; `CloudDeploySchemaParityTest` 55/55 | ✅ |
| **2. Los servicios de aplicación imponen pre-clasificación, resolución L1, escalado en 3 ramas, auto-escalado QR, reapertura, `site_coincidence`, relevo C1, horas y opacidad** | Servicios y gateway verdes; QR, C1, el escalado y el alta L1 de Coordinación ya inyectan sinergia y tienen composición real; queda la cobertura del alta de Portal (FIX-36-07) | ✅ |
| **3. `RestockerRouteView`, `TechnicianRouteView` y Coordinación implementan la operativa completa, el botón de escalado, el banner, la cola Offline y el filtro/triaje dual** | Frontend 234/234 + 18/18 + guards verdes; falta el botón de escalado dentro del asistente de Visita de Reposición | ⚠️ |
| **4. Toda la batería unitaria, de integración PostgreSQL/HTTP, frontend y blindaje constitucional al 100 %** | Batería limpia 441/441 · 16.987 aserciones · 0 fallos · `DocMetricsGuard` coherente · exit 0; 8 suites `new_engine` de la auditoría 8/8 verdes; la suite de composición actual queda en 55/55. Es verde y cubre la absorción QR, el relevo C1, el escalado real y el alta L1 de Coordinación; queda el alta de Portal (FIX-36-07) | ✅ en lo literal; insuficiente como certificación funcional |

---

## 5. Estado de las 12 tareas

| Tarea | Marca | Evidencia | Observación |
| :--- | :---: | :--- | :--- |
| `T-RSS-01` | `[x]` | Migración `032`, convergencia, semillas | ✅ coherente |
| `T-RSS-02` | `[x]` | Contratos y `PostgresRestockerSatSynergySchemaTest` 28/28 | ✅ coherente |
| `T-RSS-03` | `[x]` | Domain models 34/34 | ✅ coherente |
| `T-RSS-04` | `[x]` | Validador 46/46 | ✅ coherente |
| `T-RSS-05` | `[x]` | Gateway 24/24 | ✅ coherente |
| `T-RSS-06` | `[x]` | Servicio 34/34; API 55/55 | ✅ el escalado del reponedor resuelve técnico y encola aviso (bloque 8); QR, C1 y el alta L1 de Coordinación (bloque 9) también están cableados |
| `T-RSS-07` | `[x]` | Integraciones 27/27 | ✅ `C1` cableado en cierre, ausencia y baja (bloque 7) |
| `T-RSS-08` | `[x]` | API 55/55; constitucional 17/17 | ✅ el alta de Coordinación ya pre-clasifica L1 (bloque 9); el alta de Portal aún no tiene prueba de composición (FIX-36-07) |
| `T-RSS-09` | `[x]` | API/constitucional, `site_coincidence`, filtro y promoción | ✅ incluye la composición QR/C1 real (bloques 6 y 7) |
| `T-RSS-10` | `[x]` | Frontend 18/18 | ✅ coherente |
| `T-RSS-11` | `[x]` | Frontend 234/234 | ⚠️ falta el botón de escalado en el asistente de visita |
| `T-RSS-12` | `[x]` | Batería 441/441 · 16.987 | ⚠️ verde real; QR, C1, escalado y alta de Coordinación ya están cubiertos, pero faltan el alta de Portal y el botón de visita |

**Las 12 tareas están cerradas administrativamente; no todas están respaldadas funcionalmente por la composición real.**

---

## 6. Evidencia ejecutada

### 6.1. Batería global

```text
php tests/run_all.php
Total Suites Ejecutadas    : 441
Total Aserciones Evaluadas : 16987
Fallos Detectados          : 0
Cifras documentadas        : COHERENTES
RESULTADO: 100% EN VERDE. (0 errors, 0 failures)
RUN_ALL_CLEAN_EXIT:0
```

Nota: una primera corrida concurrente con otras suites sobre la misma base produjo 2 deadlocks transitorios en `CustodyChainPerformanceTest` y `CustodyClosureTest`; ambas pasaron aisladas con 0 fallos y la corrida limpia posterior quedó en 441/441 · 16.987 · 0. No es una regresión del Módulo 36.

### 6.2. Suites del módulo y guardas

| Suite | Aserciones | Exit |
| :--- | ---: | ---: |
| `tests/unit/RestockerSatSynergyDomainModelsTest.php` | 34/34 | 0 |
| `tests/unit/RestockerSatSynergyValidatorTest.php` | 46/46 | 0 |
| `tests/unit/RestockerSatSynergyServiceTest.php` | 34/34 | 0 |
| `tests/unit/RestockerSatSynergyIntegrationsTest.php` | 27/27 | 0 |
| `tests/unit/RestockerShiftAndWorkHoursTest.php` | 38/38 | 0 |
| `tests/integration/PostgresRestockerSatSynergySchemaTest.php` | 28/28 | 0 |
| `tests/integration/PdoRestockerSatSynergyGatewayTest.php` | 24/24 | 0 |
| `tests/integration/RestockerSatSynergyApiTest.php` | 55/55 | 0 |
| `tests/integration/RestockerSatSynergyConstitutionalTest.php` | 17/17 | 0 |
| `tests/integration/SchemaConvergenceParityTest.php` | 42/42 | 0 |
| `tests/integration/PostgresSchemaTest.php` | 153/153 | 0 |
| `tests/integration/PostgresConstitutionalTest.php` | 86/86 | 0 |
| `tests/integration/CloudDeploySchemaParityTest.php` | 55/55 | 0 |
| `tests/integration/RestockerRbacConstitutionalTest.php` | 27/27 | 0 |
| `tests/integration/PdoWorkHoursRoleFilterTest.php` | 18/18 | 0 |
| `tests/frontend/technician_and_coordinator_synergy.test.mjs` | 18/18 | 0 |
| `tests/frontend/restocker_route_view.test.mjs` | 234/234 | 0 |
| `tests/unit/CoordinatorButtonVariantsGuardTest.mjs` | 23/23 | 0 |
| `tests/unit/FrontendTemplateCompileTest.mjs` | 15/15 | 0 |
| `tests/unit/DesignTokenDebtRatchetTest.mjs` | 3/3 | 0 |
| `tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs` | 45/45 | 0 |
| `tests/unit/MobileShellLayoutGuardTest.mjs` | 7/7 | 0 |

---

## 7. Orden de corrección recomendado

```text
1. ✅ FIX-36-01  Cablear y corregir la absorción QR grave (QA-LIM-01 / Art. II) · cerrado 2026-11-19
2. ✅ FIX-36-02  Cablear C1: cierre de turno, ausencia y baja · cerrado 2026-11-19
3. ✅ FIX-36-03  Inyectar resolvedor de técnico y notificador en el escalado · cerrado 2026-11-19
4. ✅ FIX-36-04  Pre-clasificación L1 en el alta de Coordinación · cerrado 2026-11-19
5. FIX-36-05  Botón de escalado dentro del asistente de visita
6. FIX-36-06  Invariante de mapeo en dominio/SQL
7. FIX-36-07  Pruebas de composición de todos los flujos anteriores
8. FIX-36-08  Actualizar matriz, tareas y ODD tras cerrar los anteriores
```

FIX-36-01 a FIX-36-04 ya están cerrados. FIX-36-05 completa un requisito funcional. Los FIX-36-06 a FIX-36-08 son defensa en profundidad, cobertura y trazabilidad; no deben cerrarse antes que el P1 restante.

---

## 8. Criterio de cierre de este backlog

La Spec 36 podrá declararse al 100 % cuando:

- [x] El QR real auto-escale una absorción térmica/eléctrica sobre un L1, cierre la custodia, asigne SAT o deje `REGISTERED` con alerta, y encole en `notification_outbox`. Cerrado el 2026-11-19; `RestockerSatSynergyApiTest` 42/42.
- [x] El cierre de turno bloquee un L1 `IN_PROGRESS` con `409` y releve `ASSIGNED`/`PENDING_INFO`. Cerrado el 2026-11-19; aserciones 7.1-7.6 sobre un `ASSIGNED`; la consulta de relevo incluye ambos estados.
- [x] La ausencia y la baja de cuenta releven los L1 vivos. Cerrado el 2026-11-19; aserciones 7.7-7.15.
- [x] El escalado del reponedor asigne técnico y encole aviso sin `assign_technician_id`. Cerrado el 2026-11-19; aserciones 8.1-8.6 de `RestockerSatSynergyApiTest` 55/55.
- [x] El alta de Coordinación pre-clasifique L1 y respete las exclusiones de cuarentena y reclamación. Cerrado el 2026-11-19; aserciones 9.1-9.7 de `RestockerSatSynergyApiTest` 55/55.
- [ ] El botón de escalado exista dentro del asistente de visita.
- [ ] El mapeo `level1_issue_type` ↔ `category` sea un invariante de dominio o exista una enmienda aprobada.
- [ ] Existan pruebas de composición que fallen si se elimina cualquiera de los cableados anteriores.
- [ ] La matriz, las tareas y el ODD reflejen la evidencia real, sin declarar cubierto lo que no se ejecuta.
- [x] La batería global (`441/441 · 16.987`) y el canal `new_engine` sigan en verde tras las correcciones. Verificado el 2026-11-19: `RestockerSatSynergyApiTest` 55/55 y batería global 0 fallos.

---

## 9. Referencias

- [`specs/36-restocker-sat-synergy/spec.md`](../specs/36-restocker-sat-synergy/spec.md) · `RF-01.2`, `RF-02.2`, `RF-03.2`, `RF-04.2`, `RF-05.2`, `EARS 01.1.2`, `D2`, `D3`, `C1`, `QA-LIM-01`, Art. II y Art. V.
- [`specs/functional/restocker_sat_synergy_spec.md`](../specs/functional/restocker_sat_synergy_spec.md) · espejo funcional.
- [`specs/technical/restocker_sat_synergy_contracts.md`](../specs/technical/restocker_sat_synergy_contracts.md) · contratos de endpoints y esquema.
- [`specs/36-restocker-sat-synergy/tasks.md`](../specs/36-restocker-sat-synergy/tasks.md) · 12/12 tareas marcadas.
- [`odd/tasks/restocker-sat-synergy.md`](../odd/tasks/restocker-sat-synergy.md) · ODD y logs.
- [`specs/36-restocker-sat-synergy/traceability-matrix.md`](../specs/36-restocker-sat-synergy/traceability-matrix.md) · matriz a corregir tras los FIX.
- `src/Presentation/Controller/QrScanController.php` · composición real de `QrReportService` con sinergia, sugeridor de técnicos y outbox.
- `src/Application/Service/QrReportService.php` · propagación de `refund_method`, relectura tras la promoción y encolado de la asignación.
- `tests/integration/RestockerSatSynergyApiTest.php` · bloques 6 a 9 de composición QR/C1/escalado/alta de Coordinación (55/55).
- `src/Infrastructure/Repository/PdoIncidentRepository.php:2792-2825` · `update()` que reescribe la categoría/asignación desde el objeto obsoleto.
- `src/Presentation/Controller/TechnicianShiftController.php` · composición de `ShiftLifecycleService` con la sinergia real.
- `src/Application/Service/ShiftLifecycleService.php` · comprobación del resultado de `handoverLevel1()` y traducción a 409.
- `src/Presentation/Controller/CoordinatorAbsenceController.php` · composición de `TechnicianAbsenceService` con la sinergia real.
- `src/Application/Service/TechnicianAbsenceService.php` · relevo dentro de la transacción del alta de ausencia.
- `src/Application/Service/AdminUserService.php` · relevo del restocker antes del `softDelete`.
- `src/Application/Service/AdminStaffService.php` · relevo antes del cierre de turno por baja.
- `src/Infrastructure/Repository/PdoRestockerSatSynergyGateway.php` · transacciones anidadas y relevo `handoverLevel1()`.
- `src/Presentation/Controller/RestockerRouteController.php` · `buildSynergyService()` compone gateway, resolvedor de técnico y notificador reales.
- `src/Application/Service/SuggestedTechniciansService.php` · ranking por avería y por máquina sin ticket activo.
- `src/Core/Domain/Repository/TechnicianSuggestionRepositoryInterface.php` · contrato de foto del recomendador por máquina.
- `src/Infrastructure/Repository/PdoTechnicianSuggestionRepository.php` · `findSuggestionPhotoForMachine()` y `buildSuggestionPhoto()` compartido.
- `src/Application/Service/RestockerSatSynergyService.php:45-55, 150-185` · resolver/notifier opcionales.
- `src/Infrastructure/Repository/PdoRestockerSatSynergyGateway.php:518` · `assign_technician_id` explícito; el resolvedor lo rellena cuando el cliente no lo envía.
- `src/Presentation/Controller/CoordinatorController.php:408-505` · alta de Coordinación con lectura de `level1_issue_type` y `classifyCreation()`.
- `public/assets/js/components/RestockerRouteView.js:3249, 3333, 3960` · escalado solo en tarjeta/bandeja, no en visita.
- `src/Core/Domain/Model/Incident.php` · constructor sin `isCompatibleWith()`.
- `database/supabase_migrations/032_restocker_sat_synergy.sql` · CHECK sin mapeo `level1_issue_type` ↔ `category`.
