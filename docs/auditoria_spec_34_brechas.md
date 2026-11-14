# Auditoría de la Spec 34 · Brechas y Plan de Corrección Priorizado

- **Módulo:** 34 · Visita de Reposición In Situ, Control FEFO de Caducidades y Limpieza Diaria
- **Especificación auditada:** [`specs/34-in-situ-restock-visit/spec.md`](../specs/34-in-situ-restock-visit/spec.md) (v2.0) y su espejo [`specs/functional/in_situ_restock_visit_spec.md`](../specs/functional/in_situ_restock_visit_spec.md)
- **Contratos:** [`specs/technical/in_situ_restock_visit_contracts.md`](../specs/technical/in_situ_restock_visit_contracts.md)
- **Sistema de Diseño:** [`docs/design.md`](design.md)
- **Fecha de auditoría:** 2026-11-14
- **Rama:** `feat/in-situ-restock-visit` · HEAD `89c03de`
- **Alcance:** auditoría estática y ejecución de suites. **No se ha modificado código, esquema ni especificación.**

---

## Resultado general

La implementación está muy avanzada: las 13 tareas originales del módulo están cerradas, la batería global pasa al 100 % y el canal `new_engine` también está en verde.

- Batería global: **432/432 suites · 16.381 aserciones · 0 fallos**.
- Canal `new_engine`: **60/60 suites · 1.968 aserciones · 0 fallos · 0 no ejecutables**.
- Tareas: **13/13 originales** en [`tasks.md`](../specs/34-in-situ-restock-visit/tasks.md) y [`odd/tasks/in-situ-restock-visit.md`](../odd/tasks/in-situ-restock-visit.md), más `T-RVF-14` de reconciliación documental (**14/14** tras el cierre de `FIX-34-04`).

Sin embargo, **la Spec 34 no está cumplida al 100 %**. La brecha está concentrada en dos cláusulas de `RF-02.2` que no se ejecutan en la ruta real y que ninguna suite comprueba:

1. **No se asigna automáticamente el ticket térmico al mejor Técnico SAT** ni se deja constancia de que se haya intentado la asignación.
2. **No se encola la alerta en `notification_outbox`**, porque el gateway se construye sin el servicio de encolado.

El resto de `RF-01`, `RF-02.1`, `RF-02.3`, `RF-03`, `RF-04`, `RF-05`, los `RNF-01`–`RNF-03`, los casos §6, `D1`–`D4`, `C1`–`C4` y `QA 1`–`QA 4` está cubierto y en verde.

> **Conclusión corta:** el módulo está al 100 % en la batería existente, pero no al 100 % respecto a la especificación literal. Las dos cláusulas de `RF-02.2` deben implementarse y probarse antes de mantener la certificación.

### Cierre de la entrega (2026-11-14)

`FIX-34-01`, `FIX-34-02` y `FIX-34-03` quedan implementados y cubiertos por regresión. El ticket térmico nuevo se asigna al primer candidato utilizable del recomendador —o permanece `REGISTERED` sin candidato— mediante `PdoIncidentRepository::assign()`, con un único tramo de custodia y la fuente existente `COORDINATOR_ASSIGN`; la alerta de frío se encola en `notification_outbox` tanto en el alta como en la escalada, después de rehidratar el expediente asignado. La suite constitucional pasa a **42/42** y las suites focales quedan en verde. `FIX-34-04` se cierra con `T-RVF-14`: la matriz publica filas explícitas de asignación y outbox, `tasks.md` y ODD registran la corrección y la Feature 24 ya no declara el 100 % sin esas cláusulas. La certificación global completa sigue pendiente de reejecución (`FIX-34-07`).

---

## Lista ordenada por importancia

| Orden | ID | Prioridad | Brecha | Requisito afectado | Impacto | Estado |
|---:|---|---|---|---|---|---|
| **1** | **FIX-34-01** | **P0 · Bloqueante** | El ticket térmico se crea siempre sin `assigned_technician_id`; no se invoca `TechnicianAssignmentSuggester` | `RF-02.2`, `QA 2`, §6.2 | Un ticket sanitario crítico queda sin dueño aunque exista un SAT elegible; se degrada el SLA y la respuesta ante pérdida de frío | **Cerrada (2026-11-14)** |
| **2** | **FIX-34-02** | **P0 · Bloqueante** | El gateway se construye sin `NotificationOutboxEnqueuerService`; `enqueueIncidentOpened()` y `enqueueColdChainAlert()` son no-ops | `RF-02.2` | No se encola la alerta de frío ni la apertura/escalado para SAT/Coordinación; el ticket existe pero nadie recibe el aviso | **Cerrada (2026-11-14)** |
| **3** | **FIX-34-03** | **P0 · Certificación** | No hay ninguna aserción sobre asignación ni sobre `notification_outbox`; el test constitucional solo comprueba `REGISTERED` | `RF-02.2`, `QA 2`, DoD 5 | La regresión es invisible: la batería puede seguir en verde con las dos cláusulas rotas | **Cerrada (2026-11-14)** |
| **4** | **FIX-34-04** | **P1 · Documental** | `traceability-matrix.md`, `tasks.md`, ODD y `features_pendientes.md` certifican el 100 % sin estas dos cláusulas | §8, DoD, trazabilidad | La certificación formal no refleja el estado real del requisito | **Cerrada (2026-11-14, `T-RVF-14`)** |
| **5** | **FIX-34-05** | **P2 · Contractual** | La spec usa `THERMAL_BREAKDOWN`; el motor usa `incident_category = 'TEMPERATURE_COLD'` | `RF-02.2` | Ambigüedad de nomenclatura entre especificación, esquema y tests; hoy se resuelve por convención oral/documental | **Abierta** |
| **6** | **FIX-34-06** | **P2 · Contractual** | La spec usa `machines.status = 'OUT_OF_SERVICE'`; el esquema no tiene ese estado y el código usa `is_blocked_no_access = TRUE` para bebidas frías | `RF-02.2`, §6.2 | Mapeo correcto en la práctica, pero no explícito en el contrato; dificulta la verificación literal | **Abierta** |
| **7** | **FIX-34-07** | **P3 · Verificación** | La certificación global no se repetirá hasta cerrar los fixes anteriores | DoD 5 | Necesario para actualizar métricas y `DocMetricsGuard` sin arrastrar cifras obsoletas | **Pendiente de FIX-34-05…06** |

### Lectura de prioridades

- **P0:** rompe una obligación funcional o impide certificar. Debe resolverse antes de volver a declarar la Spec 34 como cerrada.
- **P1:** no rompe el runtime, pero mantiene una certificación engañosa y debe corregirse en el mismo ciclo.
- **P2:** deuda de contrato/semántica; no bloquea el flujo actual, pero impide una auditoría literal limpia.
- **P3:** verificación final dependiente de los anteriores.

---

## FIX-34-01 · Asignación automática del ticket térmico al SAT · P0

### Requisito

`RF-02.2` exige que, al crear el ticket térmico:

> «…asignarla automáticamente al mejor Técnico SAT disponible de la zona mediante `TechnicianAssignmentSuggester` (o dejarla en `'REGISTERED'` si no hay técnico disponible)…»

También lo recogen `QA 2` y el caso límite §6.2.

### Evidencia actual

- `src/Infrastructure/Repository/PdoInSituRestockVisitGateway.php:294-306` construye la incidencia con `IncidentStatus::REGISTERED` y `null` en el argumento `assignedTechnicianId`.
- No existe ninguna llamada a `TechnicianAssignmentSuggester` en el gateway, en `MachineRestockVisitService` ni en `RestockerRouteController`.
- El test `tests/integration/InSituRestockVisitConstitutionalTest.php` crea un usuario `TECHNICIAN`, pero la aserción `3.5` solo comprueba `status = 'REGISTERED'`; no comprueba `assigned_technician_id`, `assigned_at` ni que se haya intentado asignar.
- La matriz de trazabilidad reduce `RF-02.2` a ticket/cuarentena y no traza esta cláusula.

### Impacto

Un ticket `CRITICAL` de pérdida de frío puede quedar en la cola sin responsable aunque haya un técnico de zona o de guardia disponible. El daño no es solo de datos: retrasa la primera respuesta y puede agravar una incidencia sanitaria del Art. II.

### Corrección propuesta

1. Reutilizar la composición existente de sugerencia:
   - `SuggestedTechniciansService::getSuggestions($incidentId)` reúne la foto operativa y aplica `TechnicianAssignmentSuggester`.
   - El bloque `recommendation['top_technician_id']` identifica al candidato que encabeza el ranking; si no hay candidato elegible, debe quedar `null`/ausente y el ticket permanece `REGISTERED`.
2. Asignar dentro de la misma unidad transaccional del alta o de la escalada, no en un proceso posterior no transaccional.
3. Usar `PdoIncidentRepository::assign()` (o la vía de asignación que corresponda) para que la asignación deje su tramo de custodia y su auditoría; no escribir `assigned_technician_id` a mano ni crear una asignación fuera de la cadena de custodia.
4. Decidir y documentar la fuente de custodia de esta asignación automática (`IncidentAssignmentSource`). No inventar un valor fuera del enum del motor; si se necesita un valor nuevo, primero debe aprobarse el cambio de esquema.
5. Si la asignación falla después de crear el ticket, la transacción debe revertir el alta completa o dejar un estado explícito y auditable; no debe quedar un ticket creado con una asignación a medias.

### Criterios de aceptación

- [x] Con un técnico elegible en la zona/guardia, el ticket térmico nace con `assigned_technician_id` del recomendado y `assigned_at` sellado.
- [x] Sin candidato elegible, el ticket queda `REGISTERED` con `assigned_technician_id = NULL`, sin error 500.
- [x] La asignación deja el tramo de custodia y el evento de auditoría coherentes con el Módulo 26.
- [x] El reenvío idempotente no crea un segundo ticket ni una segunda asignación.
- [x] El alta y la asignación viajan en la misma transacción o en una saga compensada documentada.

### Archivos previsibles

- `src/Infrastructure/Repository/PdoInSituRestockVisitGateway.php`
- `src/Application/Service/MachineRestockVisitService.php` (si la coordinación transaccional se eleva aquí)
- `src/Presentation/Controller/RestockerRouteController.php` (composición)
- `tests/integration/InSituRestockVisitConstitutionalTest.php`
- `tests/integration/InSituRestockVisitApiTest.php`
- `specs/34-in-situ-restock-visit/traceability-matrix.md`

---

## FIX-34-02 · Encolado real en `notification_outbox` · P0

### Requisito

`RF-02.2` exige, para el ticket nuevo o escalado:

> «…adjuntar la lectura térmica y la foto en el expediente y **encolar la alerta en `notification_outbox`**…»

### Evidencia actual

- `PdoInSituRestockVisitGateway` acepta un `?NotificationOutboxEnqueuerService` opcional y llama:
  - `$this->notifications?->enqueueIncidentOpened($created)` al crear (`:329`);
  - `$this->notifications?->enqueueColdChainAlert($escalated)` al escalar (`:372`).
- La ruta real construye el gateway con **solo PDO**:

```php
// src/Presentation/Controller/RestockerRouteController.php:145
new PdoInSituRestockVisitGateway($pdo),
```

- `Router::invokeHandler()` instancia el controlador con `new $controller()` (`src/Presentation/Routing/Router.php:339`), sin inyección adicional.
- Por tanto, `$notifications` es `null` en producción y las dos llamadas son no-ops.
- No hay ninguna aserción de `notification_outbox` en las suites del Módulo 34.

### Impacto

El ticket puede existir y estar visible en la cola, pero **no se encola el aviso sanitario**. Esto contradice la promesa de activar al SAT/Coordinación «sin perder un minuto» y deja la alerta dependiente de que alguien mire el panel manualmente.

### Corrección propuesta

1. Usar el patrón ya existente en otros controladores: `NotificationOutboxEnqueuerFactory::createOrNull()` (o `create($pdo)` cuando la conexión es imprescindible) para construir el enqueuer real.
2. Inyectarlo en `PdoInSituRestockVisitGateway` al construir el `MachineRestockVisitService` del reponedor.
3. Mantener el encolado dentro de la transacción de negocio; `NotificationOutboxEnqueuerService` ya es reentrante y comparte la transacción del llamador.
4. Verificar que la creación usa `enqueueIncidentOpened()` y la escalada usa `enqueueColdChainAlert()`, sin duplicar avisos.
5. No usar `createOrNull()` como excusa para silenciar un fallo de composición en producción: si la conexión es imprescindible, el fallo debe ser visible; la variante tolerante queda para dobles de test.

### Criterios de aceptación

- [x] Un aborto térmico nuevo deja al menos una fila en `notification_outbox` vinculada a la incidencia, con la plantilla/canal de alerta de frío y un `dedup_key` estable.
- [x] La escalada de un ticket existente deja la fila de alerta correspondiente sin duplicar la del alta.
- [x] El reenvío idempotente de la visita no duplica filas de outbox.
- [x] Las pruebas cubren creación y escalada, y fallan si se quita la inyección del enqueuer.

### Archivos previsibles

- `src/Presentation/Controller/RestockerRouteController.php`
- `src/Infrastructure/Repository/PdoInSituRestockVisitGateway.php`
- `src/Infrastructure/Notification/NotificationOutboxEnqueuerFactory.php` (reutilización, no cambio obligatorio)
- `tests/integration/InSituRestockVisitConstitutionalTest.php`
- `tests/integration/InSituRestockVisitApiTest.php`

---

## FIX-34-03 · Cobertura de regresión para asignación y outbox · P0

### Problema

Las suites actuales certifican ticket, cuarentena, foto y nota, pero no las dos cláusulas que fallan. El `38/38` constitucional es correcto para lo que mide, pero **no mide `RF-02.2` completo**.

### Pruebas que deben añadirse

1. **Asignación con candidato elegible:** crear zona, técnico activo elegible y visita abortada; comprobar `assigned_technician_id`, `assigned_at` y el tramo de custodia. No basta con que exista un usuario `TECHNICIAN` en el fixture.
2. **Asignación sin candidato:** desactivar/ausentar al técnico o dejarlo fuera de zona; comprobar `REGISTERED` y `assigned_technician_id = NULL` sin error.
3. **Outbox en ticket nuevo:** comprobar fila(s) de `notification_outbox` con `incident_id`, plantilla/canal y estado inicial; comprobar el `dedup_key`.
4. **Outbox en escalada:** crear un ticket activo previo, enviar el aborto y comprobar que se eleva la urgencia, se añade la nota interna y se encola la alerta sin duplicar el ticket.
5. **Idempotencia:** repetir el mismo `client_mutation_id` y verificar que no se duplican ticket, asignación ni outbox.

### Criterio de aceptación

- [x] La suite falla si se elimina la llamada al recomendador.
- [x] La suite falla si se elimina la inyección del enqueuer.
- [x] La suite falla si se duplica el outbox en un reenvío.
- [x] Los casos positivos y negativos de asignación quedan separados y explícitos.

### Archivos previsibles

- `tests/integration/InSituRestockVisitConstitutionalTest.php`
- `tests/integration/InSituRestockVisitApiTest.php`
- `tests/integration/PostgresInSituRestockVisitSchemaTest.php` (si se añade alguna restricción o índice de soporte)

---

## FIX-34-04 · Reconciliación documental y de certificación · P1

### Problema

La documentación de cierre afirma que el módulo está al 100 % y que `RF-02.2` está cubierto. La matriz de trazabilidad enumera ticket/cuarentena, pero omite asignación y outbox. `tasks.md` y ODD marcan `T-RVF-07`/`T-RVF-13` como hechas sin esas cláusulas.

### Corrección propuesta

1. Actualizar `specs/34-in-situ-restock-visit/traceability-matrix.md`:
   - añadir filas explícitas para «asignación SAT» y «outbox»;
   - enlazar las pruebas nuevas;
   - no marcar `RF-02.2` como completo hasta que FIX-34-01…03 estén cerrados.
2. Actualizar `specs/34-in-situ-restock-visit/tasks.md` y `odd/tasks/in-situ-restock-visit.md` con una tarea de corrección (`T-RVF-14` o equivalente) mientras la brecha esté abierta.
3. Revisar `docs/features_pendientes.md` y el estado del proyecto: hoy dice «Módulo 34 cerrado»; debe reflejar la corrección pendiente o esperar al cierre real.
4. Si se actualizan cifras de suites, sincronizar `DocMetricsGuard` y las métricas del README.

### Criterio de aceptación

- [x] Ninguna matriz, tarea u ODD declara `RF-02.2` completo sin las pruebas de asignación y outbox.
- [x] La certificación final cita las suites concretas y sus recuentos reales.
- [x] Las cifras documentales coinciden con la última corrida focal; la corrida global queda pendiente (`FIX-34-07`).

**Cierre (2026-11-14):** `T-RVF-14` añade las filas `02.2a` (asignación/custodia) y `02.2b` (`notification_outbox`) a la matriz, registra la tarea de corrección en `tasks.md` y ODD, actualiza la ficha de la Feature 24 y deja constancia de que las cifras globales no se refrescan hasta `FIX-34-07`.

---

## FIX-34-05 · Alineación `THERMAL_BREAKDOWN` / `TEMPERATURE_COLD` · P2

### Problema

La spec habla de `breakdown_type = 'THERMAL_BREAKDOWN'`, pero el esquema real usa `incident_category` con el valor `TEMPERATURE_COLD`. El código y el test constitucional usan `TEMPERATURE_COLD`; ODD L9 lo describe como equivalente operativo.

### Impacto

No rompe la lógica actual, pero una auditoría literal no encuentra el valor que la spec nombra. Puede confundir a futuras integraciones, informes o pruebas.

### Corrección propuesta

Elegir una de estas dos vías, de forma explícita:

- **A. Documental:** mantener `TEMPERATURE_COLD` como valor canónico del motor y actualizar spec/contratos para nombrarlo así, dejando `THERMAL_BREAKDOWN` como etiqueta funcional.
- **B. De esquema:** añadir/renombrar el valor del enum y migrar los datos; solo si el Product Owner lo aprueba y se actualizan todos los contratos, seeds y guardas.

La opción A es la de menor riesgo y no requiere migración.

### Criterio de aceptación

- [ ] Spec, contratos, esquema, código y tests usan el mismo nombre o declaran el mapeo de forma inequívoca.
- [ ] La matriz de trazabilidad cita el valor real del enum.

---

## FIX-34-06 · Alineación `OUT_OF_SERVICE` / `is_blocked_no_access` · P2

### Problema

La spec dice que una máquina `COLD_DRINKS` pasa a `machines.status = 'OUT_OF_SERVICE'`. El esquema no tiene un estado `OUT_OF_SERVICE`; el código usa `is_blocked_no_access = TRUE` para las bebidas frías y `sanitary_status = 'QUARANTINE'` para perecederas/mixtas.

### Impacto

El comportamiento de bloqueo es el correcto para el modelo real, pero la denominación de la spec no es verificable literalmente. Es una deuda de contrato, no un fallo funcional observado.

### Corrección propuesta

- Documentar el mapeo en `specs/technical/in_situ_restock_visit_contracts.md`: `OUT_OF_SERVICE` funcional → `is_blocked_no_access = TRUE` en `COLD_DRINKS`.
- O, si el Product Owner quiere el estado literal, aprobar el cambio de esquema y migración correspondiente.
- Añadir una aserción explícita en la suite constitucional para el caso `COLD_DRINKS` si aún no está singularizada.

### Criterio de aceptación

- [ ] El contrato explica el mapeo y la suite lo verifica en el campo real.
- [ ] No se introducen dos representaciones distintas del mismo estado.

---

## FIX-34-07 · Verificación final y actualización de métricas · P3

### Secuencia

1. Cerrar FIX-34-01, FIX-34-02 y FIX-34-03.
2. Ejecutar las suites focales:
   - `php tests/integration/InSituRestockVisitConstitutionalTest.php`
   - `php tests/integration/InSituRestockVisitApiTest.php`
   - `php tests/integration/PostgresInSituRestockVisitSchemaTest.php`
3. Ejecutar la batería global con `.env.battery`:
   - `php tests/run_all.php`
4. Ejecutar el canal `new_engine` con `.env.docker-tls`:
   - `php tests/run_new_engine.php`
5. Verificar `DocMetricsGuard`, semillas y ausencia de residuos.
6. Actualizar la matriz, ODD y `features_pendientes.md` con los recuentos finales.

### Criterio de aceptación

- [ ] Todas las suites focales en verde.
- [ ] Batería global y `new_engine` en verde, con recuentos coherentes con la documentación.
- [ ] La matriz ya no reduce `RF-02.2`.
- [ ] La Spec 34 puede declararse 100 % solo con las dos cláusulas implementadas y probadas.

---

## Evidencia de la auditoría

### Suites focales ejecutadas

| Suite | Resultado |
|---|---|
| `tests/unit/InSituRestockVisitDomainModelsTest.php` | **75/75** |
| `tests/unit/MachineRestockVisitValidatorTest.php` | **71/71** |
| `tests/unit/MachineRestockVisitServiceTest.php` | **33/33** |
| `tests/integration/PdoMachinePlanogramRepositoryTest.php` | **46/46** |
| `tests/integration/PdoMachineRestockVisitRepositoryTest.php` | **29/29** |
| `tests/integration/PdoRestockerVanStockRepositoryTest.php` | **28/28** |
| `tests/unit/ProductLoadingOrderServiceTest.php` | **53/53** |
| `tests/unit/MachinePlanogramServiceTest.php` | **38/38** |
| `tests/integration/InSituRestockVisitApiTest.php` | **22/22** |
| `tests/integration/InSituRestockVisitConstitutionalTest.php` | **42/42** |
| `tests/integration/PostgresInSituRestockVisitSchemaTest.php` | **34/34** |

### Guardas de esquema, paridad y diseño

| Suite | Resultado |
|---|---|
| `tests/integration/PostgresSchemaTest.php` | **150/150** |
| `tests/integration/PostgresConstitutionalTest.php` | **85/85** |
| `tests/integration/SchemaConvergenceParityTest.php` | **36/36** |
| `tests/integration/CloudDeploySchemaParityTest.php` | **55/55** |
| `tests/integration/TestDataCleanerIsolationTest.php` | **50/50** |
| `tests/integration/RestockerRbacConstitutionalTest.php` | **27/27** |
| `tests/unit/AuditActionCatalogTest.php` | **27/27** |
| `tests/unit/NewEngineChannelDocumentationTest.php` | **9/9** |

### Frontend, PWA y Sistema de Diseño

| Suite | Resultado |
|---|---|
| `tests/frontend/restocker_route_view.test.mjs` | **171/171** |
| `tests/frontend/coordinator_product_loading_tab.test.mjs` | **63/63** |
| `tests/unit/CoordinatorButtonVariantsGuardTest.mjs` | **23/23** · 25 plantillas / 375 botones |
| `tests/unit/FrontendTemplateCompileTest.mjs` | **15/15** · 79 plantillas / 4.911 expresiones |
| `tests/unit/DesignTokenDebtRatchetTest.mjs` | **3/3** |
| `tests/unit/MobileShellLayoutGuardTest.mjs` | **7/7** |
| `tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs` | **45/45** |
| `tests/unit/TieredSlaFrontendTest.mjs` | **47/47** |

### Batería global (corrida de auditoría, antes del fix)

```text
php tests/run_all.php
432 suites · 16.381 aserciones · 0 fallos · exit=0

php tests/run_new_engine.php
60 suites · 1.968 aserciones · 0 fallos · 0 no ejecutables · exit=0
```

Tras el cierre de `FIX-34-01/02/03` se reejecutaron las suites focales; la corrida global completa queda pendiente (`FIX-34-07`) para refrescar las cifras y `DocMetricsGuard`.

---

## Criterios de Finalización (DoD §8) y estado real

| DoD | Estado | Nota |
|---|---|---|
| **DoD 1** — esquema, migración, tablas y disparadores | ✅ | `PostgresInSituRestockVisitSchemaTest` 34/34, `PostgresSchemaTest` 150/150, `PostgresConstitutionalTest` 85/85, paridad 36/36 y 55/55. |
| **DoD 2** — Triple Cerrojo, cuarentena, ticket `CRITICAL` idempotente, asignación SAT y outbox | ✅ | Alta/escalada idempotentes, asignación al candidato disponible o `REGISTERED`, custodia y alerta encolada; `InSituRestockVisitConstitutionalTest` 42/42. |
| **DoD 3** — checklist, descuento atómico, `D1`, snapshot y `C4` | ✅ | 33/33, 46/46, 29/29, 53/53, 38/38 y 171/171. |
| **DoD 4** — asistente, foto, cola IndexedDB, conflictos y consola | ✅ | 171/171, 63/63, 45/45, 15/15, 7/7 y 23/23. |
| **DoD 5** — batería al 100 % | ⏳ pendiente de reejecución | La corrida de auditoría sigue en 432/432 y 60/60; el fix se validó con las suites focales (`FIX-34-07`). |

> El `DoD 5` certifica que las suites existentes pasan, no que la especificación esté completa. Tras el cierre de `RF-02.2`, la regresión constitucional ya hace fallar asignación y outbox; falta reejecutar la batería global.

---

## Estado de las tareas

- `specs/34-in-situ-restock-visit/tasks.md`: **14/14** tareas `[x]` (13 originales + `T-RVF-14`).
- `odd/tasks/in-situ-restock-visit.md`: **14/14** filas `✅ hecho` (13 originales + `T-RVF-14`).
- `T-RVF-13` con hash `cacd436`; `89c03de` registra la evidencia ODD.
- Matriz de trazabilidad publicada.

**Matiz:** las 13 tareas originales están cerradas con un alcance que no incluía las dos cláusulas de `RF-02.2`. `T-RVF-07` habla de «ticket SAT idempotente» y `T-RVF-10` de las suites HTTP/constitucionales; ninguna exigía asignación automática ni `notification_outbox`. Por eso **13/13 tareas originales no equivalían a 13/13 requisitos**; `T-RVF-14` cierra la reconciliación documental tras `FIX-34-01/02/03`.

---

## Orden de ejecución recomendado

```text
1. FIX-34-01  Asignación SAT automática
2. FIX-34-02  Encolado en notification_outbox
3. FIX-34-03  Pruebas de regresión de ambas cláusulas
4. FIX-34-04  Reconciliación de matriz/tasks/ODD/features
5. FIX-34-05  Alineación THERMAL_BREAKDOWN / TEMPERATURE_COLD
6. FIX-34-06  Alineación OUT_OF_SERVICE / is_blocked_no_access
7. FIX-34-07  Batería global, new_engine y métricas finales
```

**La Spec 34 puede declararse cerrada respecto a `RF-02.2` una vez completados FIX-34-01, FIX-34-02 y FIX-34-03; la certificación global sigue pendiente de reejecución (FIX-34-07).**

---

## Referencias

- [`specs/34-in-situ-restock-visit/spec.md`](../specs/34-in-situ-restock-visit/spec.md) · `RF-02.2`, §6, §8.
- [`specs/functional/in_situ_restock_visit_spec.md`](../specs/functional/in_situ_restock_visit_spec.md) · espejo; solo difiere en la numeración de la sublista de `RF-02.2`.
- [`specs/technical/in_situ_restock_visit_contracts.md`](../specs/technical/in_situ_restock_visit_contracts.md) · contratos de endpoints y esquema.
- [`specs/34-in-situ-restock-visit/traceability-matrix.md`](../specs/34-in-situ-restock-visit/traceability-matrix.md) · matriz actual.
- [`specs/34-in-situ-restock-visit/tasks.md`](../specs/34-in-situ-restock-visit/tasks.md) · 14 tareas (13 originales + `T-RVF-14`).
- [`odd/tasks/in-situ-restock-visit.md`](../odd/tasks/in-situ-restock-visit.md) · ODD y log.
- `src/Presentation/Controller/RestockerRouteController.php` · composición real del gateway (línea 145).
- `src/Infrastructure/Repository/PdoInSituRestockVisitGateway.php` · alta/escalada del ticket y llamadas opcionales al outbox.
- `src/Application/Service/NotificationOutboxEnqueuerService.php` · contrato de encolado.
- `src/Infrastructure/Notification/NotificationOutboxEnqueuerFactory.php` · factoría reutilizable.
- `src/Application/Service/SuggestedTechniciansService.php` · foto operativa + `TechnicianAssignmentSuggester`.
- `tests/integration/InSituRestockVisitConstitutionalTest.php` · 42 aserciones; cubre asignación, custodia, outbox de alta y escalada e idempotencia.
- `tests/integration/InSituRestockVisitApiTest.php` · 22 aserciones HTTP.
- `tests/integration/PostgresInSituRestockVisitSchemaTest.php` · 34 aserciones de esquema.
