# Auditoría de la Spec 36 · Brechas que impiden el 100 %

- **Módulo:** 36 · Sinergia Operativa Reponedor ↔ Técnico SAT (`36-restocker-sat-synergy`)
- **Especificación auditada:** [`specs/36-restocker-sat-synergy/spec.md`](../specs/36-restocker-sat-synergy/spec.md) (v1.0)
- **Espejo funcional:** [`specs/functional/restocker_sat_synergy_spec.md`](../specs/functional/restocker_sat_synergy_spec.md)
- **Contratos técnicos:** [`specs/technical/restocker_sat_synergy_contracts.md`](../specs/technical/restocker_sat_synergy_contracts.md)
- **Sistema de Diseño:** [`docs/design.md`](design.md)
- **Tareas:** [`specs/36-restocker-sat-synergy/tasks.md`](../specs/36-restocker-sat-synergy/tasks.md) · [`odd/tasks/restocker-sat-synergy.md`](../odd/tasks/restocker-sat-synergy.md)
- **Matriz de trazabilidad:** [`specs/36-restocker-sat-synergy/traceability-matrix.md`](../specs/36-restocker-sat-synergy/traceability-matrix.md)
- **Fecha de auditoría:** 2026-11-19
- **Última actualización:** 2026-11-19 · FIX-36-01..08 conservados como historial; reauditoría de aceptación en §10
- **Rama:** `feat/restocker-sat-synergy`
- **Base auditada:** `95f9af1f1f481b14eeaf4b3acfcd623e791486e8` · árbol limpio antes de crear este acta
- **Base de la reauditoría:** `d25cbad` · árbol limpio
- **Alcance:** auditoría de los `RF-01`–`RF-05`, sus criterios EARS, `RNF-01`–`RNF-03`, las decisiones `D1`–`D4`, los casos `C1`–`C2`, las 9 salvaguardas `QA-*`, el §6, los 4 criterios de Definition of Done y las 12 tareas `T-RSS-01`–`T-RSS-12`, con ejecución real de las suites. FIX-36-01 a FIX-36-07 se corrigieron con prueba de composición o de invariante; FIX-36-08 cerró la trazabilidad con la evidencia focalizada del 2026-11-19.

> Este documento es un acta viva de hallazgos: registra la evidencia previa de las brechas y los cierres de FIX-36-01 a FIX-36-08. La matriz, las tareas y el ODD quedaron sincronizados con la evidencia focalizada; la certificación global vigente es **441/16.993**, y el canal `new_engine` queda en **69/70** por el esquema del pooler sin la migración 032.

> [!WARNING]
> **Reauditoría de aceptación del 2026-11-19 (ver §10).** El veredicto de cierre de este acta queda **superado**: la Spec 36 no está al 100 %. Los FIX-36-01..08 siguen siendo correcciones válidas y **H-36-01 (`RF-04.1`/`D4`) quedó cerrada el 2026-11-19**; la reauditoría mantiene brechas en `RF-05.2.2`, `RF-01.2`, `RNF-03` y la certificación `new_engine`. El estado vigente es **2/4 DoD**, **12/12 tareas marcadas con 2 reservas** y **69/70 en `new_engine`**.

---

## 1. Veredicto ejecutivo (histórico · superado por §10)

**Veredicto histórico (superado por la reauditoría de §10):** la Spec 36 se declaró cerrada documentalmente con la evidencia focalizada de los FIX-36; la certificación global del 2026-11-19 está en verde y el canal `new_engine` queda en 69/70 por una deriva de esquema del pooler. **La reauditoría posterior concluye que no está al 100 %.**

La implementación de esquema, dominio, gateway, servicios, API y frontend es amplia, y sus suites focalizadas están en verde. La corrida global vigente deja este resultado:

```text
php tests/run_all.php   # certificación global del 2026-11-19
441 suites · 16.993 aserciones · 0 fallos · DocMetricsGuard coherente · exit=0
```

Las 8 suites focalizadas de esquema/API ejecutadas (4 del módulo y 4 de convergencia/paridad global) también pasan:

```text
PostgresRestockerSatSynergySchemaTest   30/30
PostgresSchemaTest                     153/153
PostgresConstitutionalTest              86/86
SchemaConvergenceParityTest             42/42
PdoRestockerSatSynergyGatewayTest       24/24
RestockerSatSynergyApiTest              55/55
RestockerSatSynergyConstitutionalTest   17/17
CloudDeploySchemaParityTest             55/55
```

Y las 12 tareas están marcadas `[x]` en `tasks.md` y en el ODD.

**Las brechas funcionales y la deuda documental están cerradas.** Los tests de componentes que usan dobles se complementan ahora con composiciones HTTP/PostgreSQL reales para QR, C1, escalado, alta de Coordinación y alta de Portal; el escalado está disponible también dentro del asistente de visita y la matriz enlaza cada composición con su requisito.

En concreto:

1. **`QA-LIM-01` / Art. II — ✅ corregido el 2026-11-19:** el QR real ya inyecta la sinergia, propaga el método de reembolso y relee el expediente antes de ajustar urgencia/SLA. La composición se certifica en `RestockerSatSynergyApiTest` bloques 6 a 8 (55/55).
2. **`C1` — ✅ corregido el 2026-11-19:** el cierre de turno, la ausencia y la baja inyectan la sinergia, comprueban el resultado de `handoverLevel1()` y ejecutan el relevo antes de cerrar o desactivar. La composición se certifica en `RestockerSatSynergyApiTest` bloque 7.
3. **`D3` / `RF-03.2` — ✅ corregido el 2026-11-19:** el escalado del reponedor ya inyecta el resolvedor de técnico y el notificador; la composición se certifica en `RestockerSatSynergyApiTest` bloque 8.
4. **`D2.2` / `RF-01.2` — ✅ corregido el 2026-11-19:** el alta de Coordinación lee `level1_issue_type`, aplica `classifyCreation()` con las exclusiones de cuarentena y reclamación y persiste `dispatch_level`/`level1_issue_type`; la composición se certifica en `RestockerSatSynergyApiTest` bloque 9.

El botón de escalado dentro del asistente de Visita de Reposición (`RF-05.2.1`) ya está cubierto y el mapeo determinista de `level1_issue_type` ya es un invariante duro de dominio/SQL (`EARS 01.1.2`).

**Conclusión corta histórica (superada por §10):** FIX-36-01 a FIX-36-08 están cerrados. La evidencia focalizada cubre las composiciones de QR, C1, escalado, alta de Coordinación, alta de Portal, el botón de escalado dentro de la visita y el mapeo determinista L1 en dominio y motor: **11 suites propias, 561 aserciones, 0 fallos**. La certificación global del 2026-11-19 queda en **441/16.993, 0 fallos**; el canal `new_engine` queda en **69/70** por el esquema del pooler sin la migración 032, no por una regresión del módulo. **La reauditoría de §10 mantiene la evidencia verde, pero no acepta el cierre al 100 %.**

---

## 2. Matriz de brechas

| Orden | ID | Prioridad | Brecha | Requisito afectado | Estado |
|---:|---|---|---|---|---|
| **1** | **FIX-36-01** | **P0 · Bloqueante sanitario** | El QR real no auto-escalaba una absorción térmica/eléctrica o una reclamación digital sobre un L1; además no asignaba técnico, no encolaba aviso y un `update()` posterior podía revertir la promoción | `RF-02.2`, `QA-LIM-01`, Art. II | **Cerrada (2026-11-19)** |
| **2** | **FIX-36-02** | **P0 · Bloqueante C1** | El cierre de turno, la ausencia y la baja no invocaban `handoverLevel1` en producción; el cierre además ignoraba el resultado `ok=false` | `RF-04.2`, `C1`, Art. V.3 | **Cerrada (2026-11-19)** |
| **3** | **FIX-36-03** | **P0 · Bloqueante D3** | El escalado del reponedor se construye solo con el gateway: sin `technicianResolver` ni `notifier`, queda en `REGISTERED` y sin aviso en el outbox | `RF-03.2`, `D3`, Art. V.3 | **Cerrada (2026-11-19)** |
| **4** | **FIX-36-04** | **P1 · Alto** | El alta de Coordinación no leía `level1_issue_type` ni fijaba `dispatch_level`; siempre creaba `L2_SAT` | `RF-01.2`, `D2.2` | **Cerrada (2026-11-19)** |
| **5** | **FIX-36-05** | **P1 · Alto** | El botón «⚡ Escalar Avería al SAT» no existía dentro del asistente de Visita de Reposición del Módulo 34 | `RF-05.2.1` | **Cerrada (2026-11-19)** |
| **6** | **FIX-36-06** | **P2 · Medio** | El mapeo determinista `level1_issue_type` ↔ `category` no se impone en el constructor de `Incident` ni en el CHECK SQL; solo en `classifyCreation()` | `EARS 01.1.2`, `RNF-02` | **Cerrada (2026-11-19)** |
| **7** | **FIX-36-07** | **P2 · Medio** | El alta de Portal con L1 ya tiene composición HTTP/PostgreSQL; QR, relevo C1, escalado y Coordinación también están cubiertos | DoD 2, DoD 4, cobertura | **Cerrada (2026-11-19)** |
| **8** | **FIX-36-08** | **P3 · Documental** | La matriz y los cierres de tareas ya citan las composiciones reales (bloques 6–9), corrigen la ruta y recuento de `LocationPortalReopenAuditTest` y distinguen la certificación global vigente y el 69/70 de `new_engine` de la evidencia focalizada | Trazabilidad, DoD, tareas | **Cerrada (2026-11-19)** |

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
6. La composición se prueba en el bloque 6 de `RestockerSatSynergyApiTest`, con el router y PostgreSQL reales: 55/55 en total.

#### Criterios de aceptación

- [x] Un reporte QR térmico sobre un L1 termina en `L2_SAT` con `category = 'TEMPERATURE_COLD'`.
- [x] La custodia del reponedor se cierra con `QR_AUTO_ESCALATED_TO_SAT`.
- [x] Se asigna un Técnico SAT si hay candidato; si no, el gateway lo deja en `REGISTERED`. La alerta a Coordinación se emite en el mismo outbox cuando la promoción entra en riesgo de cadena de frío (`COLD_CHAIN_IMMEDIATE_ALERT`, aserción 6.7); el fallback sin candidato está cubierto por `PdoRestockerSatSynergyGatewayTest` 24/24.
- [x] La alerta llega a `notification_outbox`.
- [x] Una reclamación digital (`BIZUM` / `TRANSFERENCIA_BANCARIA`) sobre un L1 también auto-escala.
- [x] La prueba falla si se elimina la inyección del servicio o si el `update()` vuelve a escribir la categoría/asignación antigua.

#### Cierre

Cerrada el 2026-11-19 con evidencia reproducible:

- **Composición real:** `RestockerSatSynergyApiTest` bloque 6, **55/55** en total, exit 0. El escenario térmico verifica `dispatch_level=L2_SAT`, `category=TEMPERATURE_COLD`, `status=ASSIGNED`, `assigned_technician_id=7`, `urgency=CRITICAL`, `sla_target_minutes=240`, custodia `QR_AUTO_ESCALATED_TO_SAT` cerrada y `QR_AUTO_ESCALATION_TO_SAT` abierta, aviso de asignación y alerta de cadena de frío a Coordinación. El escenario digital con `BIZUM` y 9,50 € promociona a L2 con `category=OTHER`.
- **No reversión:** la aserción 6.3 comprueba que el `update()` posterior conserva la asignación al técnico SAT y `status=ASSIGNED`.
- **Regresiones:** `PdoRestockerSatSynergyGatewayTest` 24/24, `RestockerSatSynergyConstitutionalTest` 17/17, `QrRefundCaptureTest` 105 aserciones, `QrSanitaryQuarantineModeTest` 35 aserciones (con `.env.battery`), `QrEndpointsIntegrationTest` 0 fallos y `PublicRefundTrackingApiTest` 58/58 en batería limpia.
- **Batería global (2026-11-19):** `441/441 suites · 16.993 aserciones · 0 fallos · exit 0`; `DocMetricsGuard` corregido en la repetición. La cifra 441/16.987 queda solo como referencia histórica L14.

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

- **Composición real:** `RestockerSatSynergyApiTest` bloque 7, **55/55** en total, exit 0. 7.1/7.2 verifican el 409 y que el turno sigue abierto; 7.3-7.6 el cierre con un L1 `ASSIGNED` reasignado al suplente y la custodia `RESTOCKER_SHIFT_HANDOVER`; 7.7-7.9 el alta de ausencia vigente y la custodia `ABSENCE_COVERAGE_REASSIGN`; 7.10-7.12 la baja directa sin dejar el L1 en la cuenta inactiva; 7.13-7.15 la baja por staff con el turno cerrado y el motivo contractual.
- **Regresiones:** `ShiftLifecycleServiceTest` 12/12, `TechnicianAbsenceServiceTest` 17/17, `RestockerSatSynergyIntegrationsTest` 27/27, `AdminUserServiceTest` 74 aserciones, `AdminStaffRestockerServiceTest` 26/26, `RestockerShiftAndWorkHoursTest` 38/38, `PdoRestockerSatSynergyGatewayTest` 24/24, `QrEndpointsIntegrationTest` 0 fallos, `CoordinatorAbsenceApiTest` 23/23 y `RestockerStaffHttpTest` 18/18.
- **Batería global (2026-11-19):** **441/441 suites · 16.993 aserciones · 0 fallos · exit 0**. La evidencia focalizada deja `RestockerSatSynergyApiTest` en **55/55**.

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

### FIX-36-05 · Escalado dentro del asistente de visita · P1 · ✅ Cerrada (2026-11-19)

#### Requisito

`RF-05.2.1` exige el botón «⚡ Escalar Avería al SAT» en cada tarjeta de máquina **y dentro del asistente de Visita de Reposición del Módulo 34**, para reportar averías descubiertas a puerta abierta.

#### Evidencia

La brecha consistía en que `public/assets/js/components/RestockerRouteView.js` solo invocaba `openEscalate()` desde la tarjeta de máquina y la bandeja de avisos L1. El modal `data-testid="modal-restock-visit"` no ofrecía ninguna acción de escalado, de modo que una avería descubierta a puerta abierta obligaba a cerrar el asistente y buscar la tarjeta.

#### Impacto

El reponedor podía escalar desde la tarjeta, pero no desde el flujo guiado de visita, que es el contexto natural donde descubre la avería con la máquina abierta. Era una desviación de alcance funcional, no un fallo de backend.

#### Corrección aplicada

1. El asistente de visita incorpora un botón «⚡ Escalar Avería al SAT» (`data-testid="btn-escalate-from-visit"`) junto a las acciones de cierre. Invoca `openEscalate(visitMachine, visitMachine?.active_l1_incident || null)`, conserva la máquina abierta y propaga la incidencia L1 cuando existe.
2. `RestockerRouteView` mantiene el asistente de visita debajo del modal de escalado (`z-index` 75 frente a 86), por lo que el reponedor no pierde el contexto al reportar la avería.
3. `restocker_route_view.test.mjs` extrae el bloque completo de `modal-restock-visit`, comprueba que el botón y su expresión de apertura están dentro, y ejecuta `openEscalate()` con la máquina y su aviso L1.

#### Criterios de aceptación

- [x] El botón «⚡ Escalar Avería al SAT» aparece dentro del asistente de visita.
- [x] Abre el modal de escalado con la máquina y la incidencia correctas.
- [x] La prueba falla si se retira del modal de visita.

#### Cierre verificado

- **Composición real:** `node tests/frontend/restocker_route_view.test.mjs` → **236/236 aserciones, 0 fallos, exit 0**. Las dos aserciones nuevas verifican el botón dentro de `visitModalSource` y que `openEscalate()` conserva `visitMachine` y su `active_l1_incident`.
- **Regresiones:** la suite completa de `RestockerRouteView` sigue verde, incluidos los flujos de visita, FEFO, evidencia, cola offline y L1.
- **Alcance:** el botón queda disponible en tarjeta, bandeja L1 y asistente de visita; no se modificó el contrato de escalado ni el backend.

---

### FIX-36-06 · El mapeo determinista ya es un invariante duro · P2 · Cerrada (2026-11-19)

#### Requisito

`EARS 01.1.2` exige imponer «tanto en el dominio como mediante la restricción SQL `chk_incidents_l1_dispatch_coherence`» que, si `level1_issue_type IS NOT NULL`, su categoría compatible en la creación inicial sea determinista (`STOCKOUT_EMPTY_SLOT`/`MINOR_SPIRAL_JAM` → `PRODUCT_JAM`, `EMPTY_CHANGE_TUBES` → `PAYMENT_SYSTEM`, `MISSING_CONSUMABLES` → `OTHER`).

#### Evidencia

- `src/Core/Domain/Model/Incident.php` validaba L1 térmico/eléctrico, `PENDING_PARTS`, `pending_parts_reason` y el rastro de escalado, pero **no llamaba a `Level1IssueType::isCompatibleWith()`**.
- `isCompatibleWith()` solo tenía su definición en `src/Core/Domain/ValueObject/Level1IssueType.php`; no había ninguna llamada en `src/`.
- El CHECK `chk_incidents_l1_dispatch_coherence` (`database/supabase_migrations/032_restocker_sat_synergy.sql`) no imponía la correspondencia `level1_issue_type` ↔ `category`.
- El comportamiento externo era correcto porque `RestockerSatSynergyValidator::classifyCreation()` sobrescribía la categoría con `mappedCategory()` en QR y Portal; pero la defensa en profundidad que pide el EARS no estaba completa.

#### Impacto

Una escritura directa desde el repositorio o una futura ruta que construya `Incident` sin pasar por `classifyCreation()` podría persistir una pareja incoherente. No es un fallo observado en los canales actuales, pero sí una brecha de defensa en profundidad y de fidelidad literal al EARS.

#### Corrección aplicada

1. `Incident::__construct()` rechaza una pareja incoherente cuando `dispatchLevel->isLevel1()` y `level1IssueType !== null`, reutilizando `Level1IssueType::isCompatibleWith()`. La guarda no se aplica en `L2_SAT`: allí el motivo puede conservarse como rastro histórico de origen aunque la categoría pase a `TEMPERATURE_COLD` o `ELECTRICAL_OFF` (`EARS 01.1.2`).
2. `chk_incidents_l1_dispatch_coherence` exige el mapeo determinista dentro de la rama `L1_RESTOCKER`, tanto en `database/supabase_migrations/032_restocker_sat_synergy.sql` como en `database/supabase_init.sql`. Una consola SQL o un importador ya no pueden persistir una pareja incoherente en Nivel 1; el motor responde `SQLSTATE 23514`.
3. La regresión queda cubierta en dominio y en PostgreSQL, incluido el control positivo de que un `L2_SAT` con rastro histórico incoherente sigue siendo válido.

#### Criterios de aceptación

- [x] Una pareja `level1_issue_type`/`category` incompatible no puede persistirse en L1.
- [x] La regla se aplica en el dominio, no solo en el validador de creación.
- [x] La prueba falla si se elimina la validación.

#### Cierre verificado

- **Dominio:** `php tests/unit/RestockerSatSynergyDomainModelsTest.php` → **36/36 aserciones, exit 0**. Rechaza `STOCKOUT_EMPTY_SLOT` con `PAYMENT_SYSTEM` en L1 y acepta un `L2_SAT` térmico que conserva `STOCKOUT_EMPTY_SLOT` como rastro histórico.
- **Motor:** `source deploy/local/postgres/.env.docker-tls && php tests/integration/PostgresRestockerSatSynergySchemaTest.php` → **30/30 aserciones, exit 0**. La pareja incoherente en L1 se rechaza con `23514` y la fila `L2_SAT` con rastro histórico se persiste.
- **Convergencia:** `php tests/integration/SchemaConvergenceParityTest.php` → **42/42 aserciones, exit 0**; el DDL base y las 32 migraciones convergen y la restricción conserva su nombre.
- **Alcance:** la batería global se reejecutó el 2026-11-19 y quedó en 441/16.993 · 0 fallos; las suites afectadas y la convergencia también quedaron verificadas. El canal `new_engine` queda en 69/70 por el esquema del pooler sin la migración 032.

---

### FIX-36-07 · Pruebas de composición de los flujos · P2 · Cerrada

#### Requisito

El DoD 2 exige que los servicios de aplicación impongan las reglas, y el DoD 4 que toda la batería pase al 100 %. La trazabilidad debe demostrar que la composición real, no solo los servicios aislados, cumple la Spec.

#### Evidencia

El alta de Portal con `level1_issue_type` era el último flujo sin composición real. Se cubre ahora en `CreateIncidentEndpointTest` caso 9: la máquina se marca `QUARANTINE` en PostgreSQL, el Portal despacha `POST /api/incidents` con `MISSING_CONSUMABLES` y la fila persistida queda en `L2_SAT`, `OTHER`, `MISSING_CONSUMABLES` y `REGISTERED`.

La absorción QR, el relevo C1, el escalado real, el alta L1 de Coordinación y el alta de Portal ya tienen composición en `RestockerSatSynergyApiTest` bloques 6 a 9 y `CreateIncidentEndpointTest` caso 9.

#### Impacto

La batería no protege las rutas que realmente usa el usuario. Una regresión en la composición puede pasar desapercibida, como ya ocurre con los P0.

#### Corrección propuesta

Añadir pruebas de integración HTTP/PostgreSQL para cada flujo anterior, con aserciones sobre el estado persistido y los efectos colaterales (custodia, asignación, outbox, auditoría). No sustituir estas pruebas por dobles de servicio.

#### Criterios de aceptación

- [x] Cada flujo de composición tiene una prueba que falla si se elimina la inyección correspondiente.
- [x] Las pruebas verifican el estado en PostgreSQL, no solo el retorno HTTP.
- [x] La matriz enlaza cada prueba con su requisito; el enlace quedó cerrado en FIX-36-08.

#### Cierre verificado

- **Composición real:** `CreateIncidentEndpointTest` caso 9 (9.1-9.2), exit 0. La cuarentena se hidrata desde `Machine`, el alta responde 201 y PostgreSQL conserva `dispatch_level=L2_SAT`, `category=OTHER`, `level1_issue_type=MISSING_CONSUMABLES` y `status=REGISTERED`.
- **Regresiones:** la suite completa de creación de incidencias sigue en verde; `php -l` sin errores en el controlador y la prueba.
- **Alcance:** el alta de Portal ahora comparte el estado sanitario hidratado con Coordinación y QR. El enlace de la matriz quedó cerrado en FIX-36-08.

---

### FIX-36-08 · La trazabilidad y los cierres de tareas sobrecertifican · P3 · ✅ Cerrada (2026-11-19)

#### Evidencia

- `specs/36-restocker-sat-synergy/traceability-matrix.md` cita ahora `C1` con `RestockerSatSynergyApiTest` bloque 7 (55/55), además de `RestockerSatSynergyIntegrationsTest` 27/27, `PdoRestockerSatSynergyGatewayTest` 24/24 y `RestockerShiftAndWorkHoursTest` 38/38. La composición real es la fuente principal; las suites de servicio/gateway quedan como apoyo.
- La misma matriz cita `QA-LIM-01` con `RestockerSatSynergyApiTest` bloque 6 (55/55), que ejecuta la absorción QR real con asignación y outbox; `RestockerSatSynergyIntegrationsTest` 27/27 y `RestockerSatSynergyConstitutionalTest` 17/17 quedan como cobertura complementaria, no como certificación única.
- `docs/auditoria_spec_36_brechas.md` (este documento) registra la corrección y enlaza la evidencia.
- `LocationPortalReopenAuditTest` se cita con su ruta real, [`tests/unit/LocationPortalReopenAuditTest.php`](../tests/unit/LocationPortalReopenAuditTest.php), 18/18, y se identifica como auditoría auxiliar que no certifica la promoción `dispatch_level`.
- `tasks.md` y el ODD mantienen las 12 tareas `[x]`, pero sus cierres ya se contrastaron con la composición real: `T-RSS-06` cita el bloque 8 y el alta de Coordinación del bloque 9; `T-RSS-07` cita el bloque 7; `T-RSS-08` cita el bloque 9 y `CreateIncidentEndpointTest` caso 9; `T-RSS-11` cita el botón de visita; y `T-RSS-12` distingue la evidencia focalizada (11 suites, 561 aserciones) de la certificación global vigente (441/16.993); el canal `new_engine` queda en 69/70 por el esquema del pooler sin la migración 032. La matriz ya enlaza todas esas composiciones.

#### Impacto

La matriz y las tareas son la base de la certificación. Si declaran cubierto lo que no se ejecuta, una auditoría futura puede volver a dar por cerrado el módulo sin corregir los P0. La corrección elimina esa sobrecertificación y deja explícito qué evidencia es focalizada y cuál es la última certificación global completa.

#### Corrección aplicada

1. La matriz cita las composiciones HTTP/PostgreSQL reales de los bloques 6–9 para `QA-LIM-01`, `C1`, `D2`, `D3` y `RF-02`–`RF-05`.
2. `LocationPortalReopenAuditTest` queda en `tests/unit/` con 18/18 y marcada como auditoría auxiliar; `ReopenIncidentEndpointTest` se cita con la salvedad de que no certifica la promoción `dispatch_level`.
3. `tasks.md` y el ODD corrigen las cifras (`28/28` → `30/30`, dominio `34/34` → `36/36`, API `13/16/18` → `55/55`) y enlazan los fixes con sus commits reales.
4. La cifra global vigente es 441/16.993; la evidencia focalizada es 11 suites propias / 561 aserciones. El canal `new_engine` se publica en 69/70 por el fallo de esquema de `PoolerLatencyMeasurementTest`, sin ocultarlo tras la cifra global.

#### Criterios de aceptación

- [x] La matriz no declara `C1` ni `QA-LIM-01` cubiertos por pruebas que no ejecutan la composición real; cita los bloques 6 a 9.
- [x] Las 12 tareas tienen evidencia reproducible y coherente con el código de producción.
- [x] El estado del módulo refleja la realidad: cerrado documentalmente, con la certificación global vigente 441/16.993 y el canal `new_engine` en 69/70 por el esquema del pooler.

#### Cierre verificado

- **Composición:** `RestockerSatSynergyApiTest` 55/55 (bloques 6–9), `CreateIncidentEndpointTest` caso 9 `exit=0` y `RestockerSatSynergyConstitutionalTest` 17/17.
- **Trazabilidad:** las 11 suites propias suman 561 aserciones verdes; `LocationPortalReopenAuditTest` 18/18; `CoordinatorButtonVariantsGuardTest` 23/23; `restocker_route_view.test.mjs` 236/236; `FrontendTemplateCompileTest` 15/15.
- **Alcance:** no se reabrió ningún fix funcional. La única regresión de guarda (geometría en línea del botón `btn-escalate-from-visit`) se corrigió con `w-100 mt-3`. La batería global se reejecutó: 441/16.993, 0 fallos; el canal `new_engine` queda en 69/70 por el esquema del pooler sin la migración 032.

---

## 4. Definition of Done (§8)

| DoD | Comprobación | Estado |
| :--- | :--- | :--- |
| **1. Migración `032` y convergencia 1:1 con tipos #43/#44, ampliaciones, columnas, CHECK y 4 semillas** | `032_restocker_sat_synergy.sql` con tipos, columnas, FK, CHECK —incluido el mapeo determinista L1—, índice, operaciones Offline y las 4 parejas `RC-L1-*`/`SS-L1-*`; `supabase_seeds.sql` con 11 referencias; `SchemaConvergenceParityTest` 42/42; `PostgresRestockerSatSynergySchemaTest` 30/30; `CloudDeploySchemaParityTest` 55/55 | ✅ |
| **2. Los servicios de aplicación imponen pre-clasificación, resolución L1, escalado en 3 ramas, auto-escalado QR, reapertura, `site_coincidence`, relevo C1, horas y opacidad** | Servicios y gateway verdes; QR, C1, el escalado, el alta L1 de Coordinación y el alta de Portal inyectan sinergia y tienen composición real (`RestockerSatSynergyApiTest` bloques 6–9 y `CreateIncidentEndpointTest` caso 9); la matriz enlaza cada composición | ✅ |
| **3. `RestockerRouteView`, `TechnicianRouteView` y Coordinación implementan la operativa completa, el botón de escalado, el banner, la cola Offline y el filtro/triaje dual** | Frontend 236/236 + 18/18 + guards verdes; el botón «⚡ Escalar Avería al SAT» está en tarjeta, bandeja L1 y asistente de visita | ✅ |
| **4. Toda la batería unitaria, de integración PostgreSQL/HTTP, frontend y blindaje constitucional al 100 %** | Evidencia focalizada: **11 suites propias · 561 aserciones · 0 fallos**; `RestockerSatSynergyApiTest` 55/55 (bloques 6–9), `CreateIncidentEndpointTest` caso 9 `exit=0`, guardas 23/23 y frontend 236/236 + 18/18. Global: **441/16.993 · 0 fallos**. `new_engine`: **69/70 · 2.320 aserciones · 1 fallo** de esquema del pooler | ✅ global; ⚠️ `new_engine` no certificable al 100 % hasta aplicar la migración 032 al pooler |

> **Reauditoría 2026-11-19 (§10):** los DoD 1 y 2 se mantienen; **DoD 3 no se cumple** por la falta de cableado de la nota/foto y de los metadatos de escalado en la ruta del técnico (H-36-02). **DoD 4 no se cumple** en la certificación completa mientras `new_engine` siga en 69/70 (16/30 aserciones del pooler). H-36-01, la brecha de `operator_code`/`tel:`, quedó cerrada.

---

## 5. Estado de las 12 tareas

| Tarea | Marca | Evidencia | Observación |
| :--- | :---: | :--- | :--- |
| `T-RSS-01` | `[x]` | Migración `032`, convergencia, semillas | ✅ coherente |
| `T-RSS-02` | `[x]` | Contratos y `PostgresRestockerSatSynergySchemaTest` 30/30 | ✅ coherente |
| `T-RSS-03` | `[x]` | Domain models 36/36 | ✅ coherente |
| `T-RSS-04` | `[x]` | Validador 46/46 | ✅ coherente |
| `T-RSS-05` | `[x]` | Gateway 24/24 | ✅ coherente |
| `T-RSS-06` | `[x]` | Servicio 34/34; API 55/55 | ✅ el escalado del reponedor resuelve técnico y encola aviso (bloque 8); QR, C1 y el alta L1 de Coordinación (bloque 9) también están cableados |
| `T-RSS-07` | `[x]` | Integraciones 27/27 | ✅ `C1` cableado en cierre, ausencia y baja (bloque 7) |
| `T-RSS-08` | `[x]` | API 55/55; constitucional 17/17 | ✅ el alta de Coordinación ya pre-clasifica L1 (bloque 9); el alta de Portal ya tiene composición (`CreateIncidentEndpointTest` caso 9) |
| `T-RSS-09` | `[x]` | API/constitucional, `site_coincidence`, filtro y promoción | ✅ incluye la composición QR/C1 real (bloques 6 y 7) |
| `T-RSS-10` | `[x]` | Frontend 18/18 | ✅ coherente |
| `T-RSS-11` | `[x]` | Frontend 236/236 | ✅ botón de escalado en tarjeta, bandeja L1 y asistente de visita |
| `T-RSS-12` | `[x]` | 11 suites propias · 561 aserciones; global 441/441 · 16.993; `new_engine` 69/70 | ✅ evidencia focalizada real; la matriz enlaza QR, C1, escalado, alta de Coordinación, alta de Portal y botón de visita. El fallo de `PoolerLatencyMeasurementTest` se declara como deriva de esquema del pooler |

**Las 12 tareas están cerradas y respaldadas por la composición real o por la evidencia focalizada.**

> **Reauditoría 2026-11-19 (§10):** la marca `[x]` se conserva en las 12 tareas, pero **`T-RSS-10` y el cierre de `T-RSS-12` tienen reservas de aceptación**. `T-RSS-10` no puede activar la insignia técnica con el payload real; `T-RSS-12` certifica la documentación, no un `new_engine` al 100 %. La reserva de `T-RSS-11` quedó cerrada con H-36-01: ambas vistas muestran `operator_code` y `tel:`.

---

## 6. Evidencia ejecutada

### 6.1. Batería global (2026-11-19)

```text
php tests/run_all.php   # certificación global del 2026-11-19
Total Suites Ejecutadas    : 441
Total Aserciones Evaluadas : 16993
Fallos Detectados          : 0
Cifras documentadas        : COHERENTES
RESULTADO: 100% EN VERDE. (0 errors, 0 failures)
RUN_ALL_CLEAN_EXIT:0
```

Nota: la primera pasada de esta corrida terminó con 0 fallos de suites pero con 4 desviaciones de `DocMetricsGuard` porque README y `features_pendientes.md` aún declaraban 16.987. Se corrigieron las cifras a 16.993 y la repetición quedó en 441/441 · 16.993 · 0, con `DocMetricsGuard` coherente. La evidencia focalizada de cierre sigue siendo 11 suites y 561 aserciones.

### 6.2. Suites del módulo y guardas

| Suite | Aserciones | Exit |
| :--- | ---: | ---: |
| `tests/unit/RestockerSatSynergyDomainModelsTest.php` | 36/36 | 0 |
| `tests/unit/RestockerSatSynergyValidatorTest.php` | 46/46 | 0 |
| `tests/unit/RestockerSatSynergyServiceTest.php` | 34/34 | 0 |
| `tests/unit/RestockerSatSynergyIntegrationsTest.php` | 27/27 | 0 |
| `tests/unit/RestockerShiftAndWorkHoursTest.php` | 38/38 | 0 |
| `tests/integration/PostgresRestockerSatSynergySchemaTest.php` | 30/30 | 0 |
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
| `tests/frontend/restocker_route_view.test.mjs` | 236/236 | 0 |
| `tests/unit/CoordinatorButtonVariantsGuardTest.mjs` | 23/23 | 0 |
| `tests/unit/FrontendTemplateCompileTest.mjs` | 15/15 | 0 |
| `tests/unit/DesignTokenDebtRatchetTest.mjs` | 3/3 | 0 |
| `tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs` | 45/45 | 0 |
| `tests/unit/MobileShellLayoutGuardTest.mjs` | 7/7 | 0 |

### 6.3. Canal `new_engine` (2026-11-19)

```text
source deploy/local/postgres/.env.docker-tls
php tests/run_new_engine.php
Suites evaluadas    : 70
Suites en verde     : 69
No ejecutables      : 0
Aserciones          : 2320
Fallos              : 1
RESULTADO: FALLO. Revisa: PoolerLatencyMeasurementTest.php
```

`PoolerLatencyMeasurementTest` falla con `SQLSTATE[42703]`: el esquema del pooler de certificación (`deploy/local/supabase/.env.local`, Supavisor 6543) no tiene `incidents.dispatch_level`, porque la migración `032_restocker_sat_synergy.sql` no está aplicada en ese destino. Sin la columna, el alta L1 del camino real no persiste y la suite no recoge las 20 muestras por operación; 16 de sus 30 aserciones quedan rojas. Las otras 69 suites están verdes, incluidas `RestockerSatSynergyApiTest` 55/55 y `RestockerSatSynergyConstitutionalTest` 17/17. No se modificó el esquema del pooler ni se sustituyó la medición remota por el motor local.

---

## 7. Orden de corrección recomendado

```text
1. ✅ FIX-36-01  Cablear y corregir la absorción QR grave (QA-LIM-01 / Art. II) · cerrado 2026-11-19
2. ✅ FIX-36-02  Cablear C1: cierre de turno, ausencia y baja · cerrado 2026-11-19
3. ✅ FIX-36-03  Inyectar resolvedor de técnico y notificador en el escalado · cerrado 2026-11-19
4. ✅ FIX-36-04  Pre-clasificación L1 en el alta de Coordinación · cerrado 2026-11-19
5. ✅ FIX-36-05  Botón de escalado dentro del asistente de visita · cerrado 2026-11-19
6. ✅ FIX-36-06  Invariante de mapeo en dominio/SQL · cerrado 2026-11-19
7. ✅ FIX-36-07  Pruebas de composición de todos los flujos anteriores · cerrado 2026-11-19
8. ✅ FIX-36-08  Matriz, tareas, ODD y acta sincronizados · cerrado 2026-11-19
```

FIX-36-01 a FIX-36-08 están cerrados. La Spec 36 queda cerrada documentalmente con la certificación global vigente 441/16.993 y el canal `new_engine` en 69/70 por el esquema del pooler sin la migración 032.

---

## 8. Criterio de cierre de este backlog

La Spec 36 queda cerrada con estos criterios:

- [x] El QR real auto-escale una absorción térmica/eléctrica sobre un L1, cierre la custodia, asigne SAT o deje `REGISTERED` con alerta, y encole en `notification_outbox`. Cerrado el 2026-11-19; `RestockerSatSynergyApiTest` 55/55.
- [x] El cierre de turno bloquee un L1 `IN_PROGRESS` con `409` y releve `ASSIGNED`/`PENDING_INFO`. Cerrado el 2026-11-19; aserciones 7.1-7.6 sobre un `ASSIGNED`; la consulta de relevo incluye ambos estados.
- [x] La ausencia y la baja de cuenta releven los L1 vivos. Cerrado el 2026-11-19; aserciones 7.7-7.15.
- [x] El escalado del reponedor asigne técnico y encole aviso sin `assign_technician_id`. Cerrado el 2026-11-19; aserciones 8.1-8.6 de `RestockerSatSynergyApiTest` 55/55.
- [x] El alta de Coordinación pre-clasifique L1 y respete las exclusiones de cuarentena y reclamación. Cerrado el 2026-11-19; aserciones 9.1-9.7 de `RestockerSatSynergyApiTest` 55/55.
- [x] El alta de Portal pre-clasifique L1 y respete la cuarentena. Cerrado el 2026-11-19; aserciones 9.1-9.2 de `CreateIncidentEndpointTest`.
- [x] El botón de escalado exista dentro del asistente de visita. Cerrado el 2026-11-19; `restocker_route_view.test.mjs` 236/236, aserciones del botón y de `openEscalate()` con la máquina y su aviso L1.
- [x] El mapeo `level1_issue_type` ↔ `category` sea un invariante de dominio y SQL. Cerrado el 2026-11-19; dominio 36/36 y esquema 30/30.
- [x] Existan pruebas de composición que fallen si se elimina cualquiera de los cableados anteriores. Cerrado el 2026-11-19; bloques 6–9 de `RestockerSatSynergyApiTest` y caso 9 de `CreateIncidentEndpointTest`.
- [x] La matriz, las tareas y el ODD reflejen la evidencia real, sin declarar cubierto lo que no se ejecuta. Cerrado el 2026-11-19; matriz con 11 suites propias/561 aserciones y enlaces a las composiciones reales.
- [x] La evidencia focalizada (`11 suites · 561 aserciones`) quede en verde tras las correcciones. Verificado el 2026-11-19: `RestockerSatSynergyApiTest` 55/55, guardas 23/23 y frontend 236/236. La batería global se reejecutó y quedó en `441/441 · 16.993 · 0`; el canal `new_engine` queda en 69/70 por el esquema del pooler sin la migración 032.

> **Reauditoría 2026-11-19 (§10):** este criterio de cierre se conserva como historial, pero no acredita la aceptación final. La reauditoría encontró requisitos de interfaz sin cablear y el canal `new_engine` sigue sin estar al 100 %. El veredicto vigente está en §10.7.

---

## 9. Referencias

- [`specs/36-restocker-sat-synergy/spec.md`](../specs/36-restocker-sat-synergy/spec.md) · `RF-01.2`, `RF-02.2`, `RF-03.2`, `RF-04.2`, `RF-05.2`, `EARS 01.1.2`, `D2`, `D3`, `C1`, `QA-LIM-01`, Art. II y Art. V.
- [`specs/functional/restocker_sat_synergy_spec.md`](../specs/functional/restocker_sat_synergy_spec.md) · espejo funcional.
- [`specs/technical/restocker_sat_synergy_contracts.md`](../specs/technical/restocker_sat_synergy_contracts.md) · contratos de endpoints y esquema.
- [`specs/36-restocker-sat-synergy/tasks.md`](../specs/36-restocker-sat-synergy/tasks.md) · 12/12 tareas marcadas.
- [`odd/tasks/restocker-sat-synergy.md`](../odd/tasks/restocker-sat-synergy.md) · ODD y logs.
- [`specs/36-restocker-sat-synergy/traceability-matrix.md`](../specs/36-restocker-sat-synergy/traceability-matrix.md) · matriz corregida con la evidencia focalizada de FIX-36-08.
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
- `public/assets/js/components/RestockerRouteView.js:3249, 3333, 4250` · escalado disponible en tarjeta, bandeja y asistente de visita; el botón de visita usa `w-100 mt-3` en lugar de geometría en línea.
- `src/Core/Domain/Model/Incident.php` · constructor con `isCompatibleWith()` para L1 y rastro histórico admitido en L2.
- `database/supabase_migrations/032_restocker_sat_synergy.sql` · CHECK con el mapeo `level1_issue_type` ↔ `category` dentro de la rama L1.

---

## 10. Reauditoría de aceptación · 2026-11-19

- **Base:** `d25cbad` · árbol limpio · sin cambios de código durante la auditoría.
- **Alcance:** lectura requisito por requisito de `spec.md` y su espejo funcional, `restocker_sat_synergy_contracts.md` y `docs/design.md`; ejecución de las 25 suites solicitadas, dos guardas adicionales de frontend y el test del pooler; contraste con `tasks.md` y el ODD.
- **Veredicto vigente:** **la Spec 36 no está 100 % cumplida**. Los FIX-36-01..08 no se reabren: las brechas de §10.6 son hallazgos nuevos de aceptación, no regresiones de esos fixes.

### 10.1. Resultado ejecutivo

La evidencia focalizada sigue siendo sólida: **11 suites propias, 561 aserciones, 0 fallos**. La batería hermética global está en **441 suites / 16.993 aserciones / 0 fallos**. El canal `new_engine` sigue en **69/70**, con **2.320 aserciones y 1 fallo**; el fallo es `PoolerLatencyMeasurementTest`, que deja **16 de sus 30 aserciones** en rojo porque el esquema del pooler de certificación no tiene `incidents.dispatch_level` (migración 032 no aplicada allí).

La diferencia con el cierre anterior no está en las suites del núcleo, sino en la **aceptación de la interfaz y de la certificación completa**:

1. `RF-04.1` exigía nombre, `operator_code` y enlace `tel:` del compañero; **H-36-01 quedó cerrada el 2026-11-19** y ambas vistas ya los muestran.
2. `RF-05.2.2` exige que la tarjeta del técnico active la insignia de escalado y destaque la Nota Interna y la foto; el payload real de `/api/technician/my-route` no publica esos campos.
3. `RF-01.2` describe rutas que no coinciden literalmente con el router real y no tiene una composición de Portal elegible L1.
4. `RNF-03` no tiene medición de scroll horizontal a 360 px.
5. `new_engine` no está al 100 %, por lo que el DoD 4 no se cumple si la certificación incluye el canal.

### 10.2. Evidencia ejecutada

**Suites focalizadas del módulo (suma 561):**

| Suite | Resultado |
| :--- | ---: |
| `RestockerSatSynergyDomainModelsTest.php` | 36/36 · `exit=0` |
| `RestockerSatSynergyValidatorTest.php` | 46/46 · `exit=0` |
| `RestockerSatSynergyServiceTest.php` | 34/34 · `exit=0` |
| `RestockerSatSynergyIntegrationsTest.php` | 27/27 · `exit=0` |
| `RestockerShiftAndWorkHoursTest.php` | 38/38 · `exit=0` |
| `PostgresRestockerSatSynergySchemaTest.php` | 30/30 · `exit=0` |
| `PdoRestockerSatSynergyGatewayTest.php` | 24/24 · `exit=0` |
| `RestockerSatSynergyApiTest.php` | 55/55 · `exit=0` |
| `RestockerSatSynergyConstitutionalTest.php` | 17/17 · `exit=0` |
| `restocker_route_view.test.mjs` | 236/236 · `exit=0` |
| `technician_and_coordinator_synergy.test.mjs` | 18/18 · `exit=0` |

**Esquema, composición y guardas:**

| Suite | Resultado |
| :--- | ---: |
| `SchemaConvergenceParityTest.php` | 42/42 · `exit=0` |
| `PostgresSchemaTest.php` | 153/153 · `exit=0` |
| `PostgresConstitutionalTest.php` | 86/86 · `exit=0` |
| `CloudDeploySchemaParityTest.php` | 55/55 · `exit=0` |
| `RestockerRbacConstitutionalTest.php` | 27/27 · `exit=0` |
| `PdoWorkHoursRoleFilterTest.php` | 18/18 · `exit=0` |
| `LocationPortalReopenAuditTest.php` | 18/18 · auditoría auxiliar |
| `CreateIncidentEndpointTest.php` | caso 9.1–9.2 · `exit=0` |
| `ReopenIncidentEndpointTest.php` | caso 9.1–9.5 · `exit=0`; no certifica la promoción `dispatch_level` |
| `CoordinatorButtonVariantsGuardTest.mjs` | 23/23 · 413 botones |
| `FrontendTemplateCompileTest.mjs` | 15/15 · 5.157 expresiones |
| `DesignTokenDebtRatchetTest.mjs` | 3/3 |
| `OfflinePwaAssetsAndUiGuardTest.mjs` | 45/45 |
| `MobileShellLayoutGuardTest.mjs` | 7/7 |
| `TechnicianRouteViewTest.mjs` adicional | 181/181 |
| `CoordinatorDashboardViewTest.mjs` adicional | 143/143 |
| `PoolerLatencyMeasurementTest.php` con el pooler real | **16/30 fallidas**, `exit=1` |

**Certificación completa:**

```text
Batería global: 441 suites · 16.993 aserciones · 0 fallos · exit=0
new_engine:    70 evaluadas · 69 verdes · 2.320 aserciones · 1 fallo
Pooler:        16/30 aserciones fallidas · SQLSTATE[42703] · exit=1
```

### 10.3. Cobertura por RF y EARS

| Criterio | Estado | Evidencia y observación |
| :--- | :---: | :--- |
| **RF-01.1** · Tipos #43/#44, columnas, FK, CHECK, 49 tablas y 44 enums | ✅ | Schema 30/30, Domain 36/36, Convergence 42/42, PostgresSchema 153/153, CloudDeploy 55/55. El CHECK incluye el mapeo determinista y el rastro de escalado. |
| **RF-01.2** · QR, Portal y Coordinación; exclusiones de categoría, cuarentena y reclamación | ⚠️ | Validator 46/46, Integrations 27/27, API 55/55 (QR y Coordinación), CreateIncident caso 9 (solo exclusión por cuarentena). Faltan paths literales y una composición de Portal elegible L1. |
| **RF-01.3** · 4 parejas `RC-L1-*` / `SS-L1-*` | ✅ | Schema 30/30: 4 causas, categorías, nombres, solución predeterminada activa y 49 tablas. |
| **RF-02.1** · Sugerencia de reponedor, carga L1 y despacho/conmutación L1↔L2 | ✅ | Service 34/34, Gateway 24/24, API 55/55. |
| **RF-02.2** · Auto-escalado en absorción QR grave | ✅ | Integrations 27/27, API 55/55 bloque 6, Constitutional 17/17. |
| **RF-02.3** · Reapertura < 48 h y promoción a L2 | ✅ | Integrations 27/27 y Constitutional 17/17; la composición HTTP de reapertura no añade la aserción de promoción. |
| **RF-03.1** · Inicio/resolución L1, cuarentena, textos, taxonomía y sin A4/repuestos | ✅ | Gateway 24/24, Service 34/34, API 55/55, Constitutional 17/17; Gateway comprueba ausencia de `incident_service_sheets`. |
| **RF-03.2** · Escalado por máquina, nota/foto, tres ramas, custodia y outbox | ✅ | Gateway 24/24, Service 34/34, API 55/55 bloque 8, Restocker 236/236. La ruta por máquina satisface el “o” de la spec. |
| **RF-04.1** · Coincidencia en Sede y banner con nombre, código y `tel:` | ✅ | Backend y tests de servicio/API verdes; H-36-01 cerrada: ambas vistas muestran `operator_code` y enlace `tel:`; `restocker_route_view` 236/236 y `technician_and_coordinator_synergy` 18/18. |
| **RF-04.2** · Bloqueo y relevo de turno/ausencia/baja | ✅ | API 55/55 bloque 7, Gateway 24/24, Integrations 27/27, Shift 38/38. |
| **RF-05.1** · Cola Offline, idempotencia y volcado de conflicto | ✅ | Schema 30/30, API 55/55, Restocker 236/236, Service 34/34. |
| **RF-05.2** · Superficies Restocker y Técnico | ❌ | Restocker 236/236; Técnico 181/181 y 18/18, pero el payload real no publica la insignia ni la nota/foto. Ver §10.6, H-36-02. |
| **RF-05.3** · Horas, filtro y triaje dual | ✅ | Shift 38/38, WorkHours 18/18, Frontend 18/18, API 55/55. |

### 10.4. RNF, decisiones, casos límite y QA

| Referencia | Estado | Evidencia |
| :--- | :---: | :--- |
| **RNF-01** · 49 tablas, 44 enums, borrado físico e inmutabilidad | ✅ local / ⚠️ remoto | PostgresSchema 153/153, PostgresConstitutional 86/86, Schema 30/30, Convergence 42/42. El pooler remoto no tiene la 032. |
| **RNF-02** · CHECK sanitario, notas internas, foto ≤ 5 MB y DTOs opacos | ✅ / ⚠️ | Validator 46/46, Domain 36/36, Constitutional 17/17, API 55/55 (QR). Portal/A4 no tienen una aserción focalizada propia. |
| **RNF-03** · PHP/Vue vanilla, tokens y 360 px sin scroll | ⚠️ | Botones 23/23, plantillas 15/15, tokens 3/3, PWA 45/45, móvil 7/7. No hay medición real a 360 px. |
| **D1** · Expediente único y `dispatch_level` | ✅ | Schema 30/30 y Domain 36/36. |
| **D2** · Pre-clasificación y despacho dual | ✅ | Validator 46/46, Integrations 27/27, API 55/55. |
| **D3** · Resolución L1 y escalado en tres ramas | ✅ | Gateway 24/24, Service 34/34, API 55/55, Restocker 236/236. |
| **D4** · Coincidencia en Sede | ✅ | Backend 24/24 y API 55/55; H-36-01 cerrada: las dos vistas muestran `operator_code` y `tel:`. |
| **C1** · Cierre, ausencia y baja con relevo | ✅ | API 55/55 bloque 7, Gateway 24/24, Shift 38/38. |
| **C2** · Offline, horas y métricas | ✅ | API 55/55, Restocker 236/236, Shift 38/38. |
| **QA-AMB-01** · Mapeo y semillas | ✅ | Validator 46/46, Schema 30/30, Domain 36/36. |
| **QA-AMB-02** · Ampliación de enums | ✅ | Schema 30/30. |
| **QA-CON-01** · RBAC y coherencia rol/nivel | ✅ | Validator 46/46, API 55/55, RBAC 27/27. |
| **QA-CON-02** · Atribución horaria | ✅ | Shift 38/38, WorkHours 18/18, Integrations 27/27. |
| **QA-LIM-01** · Auto-escalado QR grave | ✅ | Integrations 27/27, API 55/55 bloque 6, Constitutional 17/17. |
| **QA-LIM-02** · Promoción en reapertura | ✅ | Integrations 27/27 y Constitutional 17/17. |
| **QA-LIM-03** · Prohibición de repuestos en L1 | ✅ | Schema 30/30, Validator 46/46, Domain 36/36. |
| **QA-CONST-01** · Guarda sanitaria | ✅ | Schema 30/30, Domain 36/36, Constitutional 17/17. |
| **QA-CONST-02** · Absorción idempotente sobre L2 | ✅ | Gateway 24/24 y Constitutional 17/17. |
| **QA-CONST-03** · Opacidad Sede/QR/A4 | ✅ / ⚠️ | Domain 36/36 y QR 55/55; Portal/A4 sin aserción específica. |

Los cuatro casos límite del §6 pasan: QR térmico (API bloque 6 e Integrations), absorción sobre L2 preexistente (Gateway y Constitutional), reapertura < 48 h (Integrations y Constitutional) y cierre de turno `IN_PROGRESS` vs `ASSIGNED` (API bloque 7, Gateway y Shift).

### 10.5. Definition of Done y tareas

| DoD | Estado | Motivo |
| :--- | :---: | :--- |
| **1** · Migración, convergencia, tipos, columnas, CHECK y semillas | ✅ | Convergence 42/42, CloudDeploy 55/55, Schema 30/30. |
| **2** · Servicios de aplicación completos | ✅ | Service 34/34, Integrations 27/27, Gateway 24/24, API 55/55. |
| **3** · Frontend completo | ❌ | Falta cablear la insignia/nota/foto del técnico (H-36-02); el banner de coincidencia ya muestra `operator_code` y `tel:` (H-36-01 cerrada). |
| **4** · Toda la batería al 100 % | ❌ | Global 441/16.993 verde; `new_engine` 69/70 y pooler 16/30. |

**Resultado: 2/4 DoD.** Las 12 tareas conservan `[x]` en `tasks.md` y ✅ en el ODD, pero la aceptación de dos de ellas queda condicionada:

| Tarea | Marca | Reserva |
| :--- | :---: | :--- |
| `T-RSS-01` a `T-RSS-09` y `T-RSS-11` | `[x]` | Sin reservas; H-36-01 cerrada y evidencia focalizada verde. |
| `T-RSS-10` | `[x]` | La insignia técnica no se activa con el payload real; ver H-36-02. |
| `T-RSS-12` | `[x]` | Documentación y cifras correctas, pero `new_engine` sigue 69/70; ver H-36-05. |

### 10.6. Brechas de aceptación y cierre de H-36-01

#### H-36-01 · `RF-04.1` / `D4`: el banner ya muestra código y teléfono del compañero · P1 · ✅ Cerrada (2026-11-19)

El gateway devuelve `operator_code`, `phone`, `shift_status` y `activity_summary` (`src/Infrastructure/Repository/PdoRestockerSatSynergyGateway.php:749-758,841-847`). Antes del fix, las dos vistas reducían la coincidencia a nombre y actividad y no existía enlace `tel:`; el test de restocker validaba un resumen “sin identificadores”, inferior a la spec.

**Corrección aplicada:** `RestockerRouteView.js` y `TechnicianRouteView.js` incorporan `siteCoincidenceLabel(entry)` y renderizan una fila por compañero con `Nombre (operator_code)`, actividad y enlace `tel:` cuando `phone` existe. Los `user_id` siguen fuera de la UI. Se ampliaron las aserciones existentes para que fallen si desaparecen `operator_code` o `tel:`.

**Cierre verificado:** `restocker_route_view.test.mjs` **236/236** y `technician_and_coordinator_synergy.test.mjs` **18/18**, ambos `exit=0`; `FrontendTemplateCompileTest.mjs` 15/15, 79 plantillas y 5.157 expresiones. La reserva de `T-RSS-11` queda cerrada; la de `T-RSS-10` sigue abierta por H-36-02.

#### H-36-02 · `RF-05.2.2`: la insignia y la nota/foto del técnico no están cableadas · P1

`TechnicianRouteView.js:1348-1350` decide la insignia con `escalated_from_restocker_id`/`escalated_to_sat_at`, pero `TechnicianController::stopPayload()` (`src/Presentation/Controller/TechnicianController.php:802-870`) no publica ninguno de esos campos, ni `workshop_internal_note` ni `photo_path`. La insignia no puede activarse en una ruta real y la vista rápida no tiene la Nota Interna ni la foto. `tests/frontend/technician_and_coordinator_synergy.test.mjs:197-213` usa un objeto sintético y `tests/integration/RestockerSatSynergyApiTest.php:354-358` solo comprueba `site_coincidence`; por eso las suites verdes no detectan el fallo.

**Cierre esperado:** ampliar `stopPayload()` con el rastro de escalado y la última nota interna/foto, renderizarlos en la tarjeta y añadir una composición HTTP real sobre `/api/technician/my-route`.

#### H-36-03 · `RF-01.2`: desviación literal de endpoints y Portal elegible sin composición · P2

La spec y el contrato nombran `/api/public/qr/{code}/report` y `/api/portal/incidents` (`spec.md:131`); el router real expone `/api/qr/report` y `/api/incidents` (`src/Presentation/Routing/AppRouter.php:639,133`). La conducta está probada, pero los paths canónicos no. Además, `CreateIncidentEndpointTest` caso 9 solo prueba la exclusión por cuarentena; no hay composición de un alta de Portal elegible L1.

**Cierre esperado:** alinear spec/contrato y router (o declarar alias) y añadir el caso positivo de Portal.

#### H-36-04 · `RNF-03`: faltan 360 px medidos · P2

Las guardas de tokens, botones, plantillas, PWA y shell móvil pasan, pero el test de restocker solo valida `max-width: 480px` y ausencia de `min-width: 1024px` (`tests/frontend/restocker_route_view.test.mjs:355-358`). No hay una medición de `scrollWidth` a 360 px como la que existe en otros módulos.

**Cierre esperado:** añadir una prueba de navegador a 360 × 800 sobre las superficies L1/SAT, o corregir la spec si el viewport mínimo no es 360 px.

#### H-36-05 · DoD 4: el pooler de certificación no tiene la migración 032 · P0 de certificación

`PoolerLatencyMeasurementTest` falla con `SQLSTATE[42703]`: el esquema del pooler no tiene `incidents.dispatch_level`. La creación se aborta y caen 16 de 30 aserciones; el canal queda en 69/70. No es una regresión del Módulo 36 ni de los FIX-36, pero impide declarar el 100 %.

**Cierre esperado:** aplicar `032_restocker_sat_synergy.sql` al pooler de certificación con autorización explícita, o declarar formalmente que el DoD 4 se limita a la batería hermética y excluye `new_engine`. No se modificó el esquema remoto en esta auditoría.

### 10.7. Veredicto final

**No, la Spec 36 no está 100 % cumplida.** El núcleo de dominio, esquema, gateway, servicios, API y la mayor parte de la interfaz están implementados y verdes; la evidencia focalizada es **11 suites / 561 aserciones**. H-36-01 (`RF-04.1`/`D4`) quedó cerrada. Pero `RF-05.2.2` sigue sin cablear, `RF-01.2` tiene una desviación contractual y una composición de Portal incompleta, `RNF-03` no está medido a 360 px, el DoD 3 falla por H-36-02 y el DoD 4 falla si la certificación incluye `new_engine` (69/70; pooler 16/30).

Las 12 tareas están marcadas `[x]`, pero **`T-RSS-10` y el cierre de `T-RSS-12` no son aceptables como 100 %** con la evidencia actual; `T-RSS-11` queda cerrada por H-36-01. La reauditoría inicial no modificó código; H-36-01 se corrigió después en las dos vistas y sus suites, y esta acta registra el cambio sobre la base `d25cbad`.
