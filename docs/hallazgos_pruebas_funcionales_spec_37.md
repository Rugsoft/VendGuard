# Hallazgos · Pruebas funcionales, de humo y E2E de la Spec 37

- **Módulo:** 37 · Planificador de Rutas Periódicas de Carga y Priorización Dinámica por Demanda (`37-restock-route-planner`)
- **Especificación:** [`specs/37-restock-route-planner/spec.md`](../specs/37-restock-route-planner/spec.md)
- **Contratos:** [`specs/technical/restock_route_planner_contracts.md`](../specs/technical/restock_route_planner_contracts.md)
- **Sistema de diseño:** [`docs/design.md`](design.md)
- **Fecha de la corrida:** 2026-11-23
- **Rama:** `feat/restock-route-planner`
- **HEAD:** `13637b7` · `test(restock-route): certify 360x800 layout of M37 surfaces (AUD-37-04)`
- **Árbol:** la corrida inicial no modificó código. Las correcciones posteriores de 2026-11-23 (`H-37-01` a `H-37-07`) tocan la cola offline, los servicios y controladores del planificador y de M33, las suites y este acta; todos los hallazgos de este documento quedan cerrados.
- **Entorno:** app local `http://127.0.0.1:8000` levantada con `VENDGUARD_DEV_SITE_KEYS=1 php -S 127.0.0.1:8000 -t public public/index.php`, contra el banco local `vendguard_battery` (PostgreSQL `127.0.0.1:54331`).
- **Naturaleza:** acta de verificación funcional sobre la aplicación ensamblada. **No sustituye** a [`auditoria_spec_37_brechas.md`](auditoria_spec_37_brechas.md), que certifica las brechas `AUD-37-01`–`AUD-37-09` con las suites automatizadas. Los siete hallazgos de este documento son nuevos y aparecieron al recorrer la app real, no en las suites.

---

## 1. Resumen ejecutivo

La sonda API de caja negra cerró con **63 PASS · 1 FAIL**, y el único fallo correspondía a un supuesto que el contrato no exige; la validación de fechas pasadas quedó resuelta en `H-37-06` con `422` para el pasado estricto. El recorrido E2E de Coordinación y Reponedor confirmó que los flujos principales funcionan: agendas, ruta del día, desvío y revocación, omisión, mapa, visita y salvaguarda FEFO en servidor.

Los siete hallazgos se ordenan así:

| Orden | ID | Prioridad | Hallazgo | Requisito afectado | Estado |
|---:|---|---|---|---|---|
| 1 | **H-37-01** | **P1 · Alta** | El snapshot offline de la ruta nunca se guarda en IndexedDB (`DataCloneError`) | `RF-05.1`, `C3` | **Cerrado (2026-11-23)** |
| 2 | **H-37-02** | **P2 · Media** | El tablero de Coordinación muestra `0.0 km` en todas las rutas | `RF-03.2.4`, `RF-05.3` | **Cerrado (2026-11-23)** |
| 3 | **H-37-03** | **P2 · Media** | El modal de omisión móvil no advierte de alerta FEFO ni perecedero caducado | `RF-05.2` (EARS 05.2.1) | **Cerrado (2026-11-23)** |
| 4 | **H-37-04** | **P3 · Baja** | Los formularios piden IDs internos numéricos en vez de selectores | `RF-01.3`, `RF-02.3`, `RF-05.3` | **Cerrado (2026-11-23)** |
| 5 | **H-37-05** | **P3 · Baja** | El modal de omisión no declara semántica de diálogo | Hoja móvil (`RF-05.2`) | **Cerrado (2026-11-23)** |
| 6 | **H-37-06** | **P3 · Observación** | `service_date` pasada se acepta en propuesta y emisión `TODAY_ROUTE` | Contrato §4 y `TODAY_ROUTE` | **Cerrado (2026-11-23)** |
| 7 | **H-37-07** | **P3 · Observación** | Cambiar la fecha del tablero no recarga hasta pulsar «Actualizar» | `RF-05.3` | **Cerrado (2026-11-23)** |

> Los identificadores `H-37-01`–`H-37-07` son nuevos para no reutilizar los `AUD-37-01`–`AUD-37-09` ya cerrados. `H-37-06` y `H-37-07` quedaron cerrados al fijar la regla de contrato y al corregir la recarga del tablero, respectivamente.

---

## 2. Cómo se probó

| Elemento | Detalle |
|---|---|
| Sonda API | `php scratch/m37_probe.php` sobre el servidor local, con el banco de la batería |
| Navegador | Chromium del panel, recorrido manual con clics y tecleo reales |
| Perfiles | Coordinación y Reponedor; Lucía (`REP-01`) con turno abierto y Óscar (`REP-02`) como receptor de un desvío |
| Datos | Máquinas `VEND-0101`, `VEND-0102`, `VEND-0302`, `VEND-0401`, `VEND-0801` y `VEND-0901`; agenda activa de la máquina 2 (`route_schedule_id = 8`) |
| Comprobaciones | API, UI real y consultas directas a PostgreSQL e IndexedDB |

Recorrido ejecutado:

1. Login de Coordinación → «🚚 Órdenes de Carga y Picking de Frío» → «🗺️ Ruta del día y Agendas».
2. Lectura de KPIs, bandeja de paradas sin cobertura, rutas por reponedor, excepciones y tabla de agendas.
3. Alta real de una agenda para `VEND-0901` desde «＋ Nueva agenda», verificación de la fila y baja lógica posterior.
4. Apertura del modal «⚡ Alta masiva» y revisión de todos sus campos, sin enviar el lote.
5. Desvío de una parada a Óscar y revocación posterior desde la UI.
6. Login de Lucía → ruta móvil, mapa, omisión de `VEND-0101` y visita de `VEND-0102`.
7. Contraste de los efectos en la API, en PostgreSQL y en el tablero de Coordinación.

---

## 3. Hallazgos detallados

### H-37-01 · P1 · El snapshot offline de la ruta nunca se guarda

**Qué ocurre.** Tras cargar la ruta móvil, la propia vista muestra el error:

```text
Failed to execute 'put' on 'IDBObjectStore': #<Object> could not be cloned.
```

La base `vendguard-restock-route-overrides` existe y su almacén `route_snapshots` está creado, pero contiene **0 registros** después de varias cargas de ruta y de haber completado una omisión y una visita. El snapshot que debía servir para reabrir la ruta sin red nunca llega a persistirse.

**Causa.** [`RestockerRouteView.js:799`](../public/assets/js/components/RestockerRouteView.js) llama a `saveSnapshot()` con `this.routePlan`, que es un proxy reactivo de Vue. [`RestockRouteOverrideOfflineQueue.js:229-252`](../public/assets/js/utils/RestockRouteOverrideOfflineQueue.js) construye el snapshot con `route_plan: routePlan` y ejecuta `put(snapshot)` sin clonar a un objeto plano. Structured Clone no puede clonar el proxy y la transacción aborta; la vista captura el error y lo pinta como `routeOverrideSyncError`.

**Impacto.** `RF-05.1` y `C3` quedan rotos en la práctica: la ruta no se cachea, por lo que al recargar sin cobertura no hay snapshot que restaurar. La cola de omisiones y el flujo online sí funcionan, lo que hace el fallo más silencioso: solo se manifiesta al intentar trabajar offline.

**Corrección aplicada (2026-11-23).** `RestockRouteOverrideOfflineQueue.saveSnapshot()` convierte el plan con `toPlainRoutePlan()` (`JSON.parse(JSON.stringify(...))`) antes de construir el snapshot, de modo que IndexedDB recibe un objeto plano y no el proxy de Vue. Se añadió la regresión `1.3b` en `restocker_route_planner_view.test.mjs`, que envuelve el fixture en un `Proxy` y exige que `structuredClone(snapshot.route_plan)` funcione y que no sea la misma referencia. La suite pasa de 42 a **43/43 aserciones**.

**Evidencia real (2026-11-23).** En Chromium, tras desregistrar el Service Worker y limpiar cachés, Lucía abrió su ruta y `route_snapshots` quedó con **1 fila** (`restocker_id = 7`, `service_date = 2026-11-23`, 2 paradas, 3 máquinas, `saved_at` de la carga); no aparece el `DataCloneError`. Tras recargar la página la fila sigue existiendo, con `saved_at` actualizado por la nueva carga. El App Shell sube a `vendguard-app-shell-v28` para que una PWA instalada reciba el módulo corregido; con `H-37-03` pasa a `v29`.

**Estado:** Cerrado (2026-11-23).

---

### H-37-02 · P2 · El tablero de Coordinación muestra `0.0 km` en todas las rutas

**Qué ocurre.** La ruta móvil de Lucía publica `8.77 km` en `/api/restocker/my-route`, pero `GET /api/coordinator/restock-route/day` devuelve `total_distance_km = 0` para Lucía y para Óscar, y la consola pinta `0.0 km` en todas las tarjetas. El resto del resumen (paradas, máquinas, FEFO, completadas y omitidas) sí es correcto.

**Causa.** [`RestockRoutePlannerService.php:156`](../src/Application/Service/RestockRoutePlannerService.php) pasa el origen al optimizador en la ruta móvil, mientras que [`RestockRoutePlannerService.php:340`](../src/Application/Service/RestockRoutePlannerService.php), dentro de `buildCoordinatorDay()`, llama `$this->optimizer->optimize($pendingStops)` sin origen. Sin origen no hay distancia Haversine que sumar y el resumen queda a `0`. La UI solo refleja ese valor en [`CoordinatorRestockRoutePlannerSubTab.js:676`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js).

**Impacto.** El indicador de planificación es engañoso y no permite comparar carga entre reponedores. El dato existe y es correcto en la ruta móvil; el defecto está solo en la composición de Coordinación.

**Corrección aplicada (2026-11-23).** `buildCoordinatorDay()` acepta un `$origin` opcional y lo reenvía al optimizador. [`CoordinatorRestockRoutePlannerController.php`](../src/Presentation/Controller/CoordinatorRestockRoutePlannerController.php) resuelve la Base Central con `RouteSettingsRepositoryInterface` mediante el mismo `routeOrigin()` que usa la hoja móvil y lo pasa como cuarto argumento. Se añadió la aserción API `3.4` en [`RestockRoutePlannerApiTest.php`](../tests/integration/RestockRoutePlannerApiTest.php): con `route_settings` y sedes con coordenadas, la ruta del titular debe publicar `total_distance_km > 0`.

**Evidencia (2026-11-23).** Antes de la corrección, `3.4` fallaba y la suite cerraba **40 aserciones · 1 fallo**. Después, la misma suite cierra **40/40 · 0 fallos · `exit=0`**. En la app local, `GET /api/coordinator/restock-route/day` devuelve para Lucía `total_distance_km = 8.49` (antes `0`), coherente con los `8.5 km` de la hoja móvil; las demás rutas y contadores no cambian.

**Estado:** Cerrado (2026-11-23).

---

### H-37-03 · P2 · El modal de omisión móvil no advierte de FEFO

**Qué ocurre.** Abrir «⏭️ Omitir parada hoy» sobre `VEND-0101`, una parada con alerta FEFO activa, muestra solo el selector de causa, el motivo y el contador de bloques. No hay ningún aviso sanitario destacado. La tarjeta de la parada sí lleva el badge «FEFO 24/11/2026».

**Requisito.** [`spec.md:230`](../specs/37-restock-route-planner/spec.md) exige que el modal advierta «de forma destacada si la parada tiene alerta FEFO o perecedero caducado».

**Causa.** [`RestockerRouteView.js:852`](../public/assets/js/components/RestockerRouteView.js) define `routeStopHasFefo()`, pero el modal de [`RestockerRouteView.js:5405`](../public/assets/js/components/RestockerRouteView.js) no lo consulta. La comprobación solo se usa para el badge de la tarjeta.

**Evidencia complementaria.** El backend sí cumple la salvaguarda: la omisión creó la excepción `1534` con `had_active_fefo_alert = true` y la notificación `6610` `RESTOCK_FEFO_STOP_SKIPPED_ALERT` / `CRITICAL_SANITARY` / `PENDING` para Coordinación. El fallo es exclusivamente de la interfaz que debe informar al reponedor antes de decidir.

**Impacto.** El reponedor puede omitir una parada con género refrigerado en riesgo sin ver la advertencia que el criterio EARS le exige. La protección de datos y la alerta interna no se pierden, pero se degrada la decisión informada y la trazabilidad visual del Art. II.

**Corrección aplicada (2026-11-23).** La vista calcula `skipRouteFefoWarning` a partir de `routeStopHasFefo(stop)`: reúne las máquinas afectadas, la caducidad más próxima (`earliest_fefo_expiration_date` de máquina o parada) y el indicador `has_expired_cold_batch`. El modal pinta un banner `role="alert"` con `data-testid="route-skip-fefo-warning"` antes de la causa, con fondo y borde de error. Si hay lote caducado el título es «Perecedero caducado» y anuncia la marca `EXPIRED`; si solo hay alerta FEFO el título es «Alerta FEFO activa». Incluye la fecha y los códigos de las máquinas afectadas. Se mantiene el bloqueo de validación de 20 caracteres y 3 bloques.

**Evidencia (2026-11-23).** Antes de la corrección, las nuevas aserciones `3.1b`, `3.1c` y `5.8` fallaban (45 aserciones · 3 fallos). Después, [`restocker_route_planner_view.test.mjs`](../tests/frontend/restocker_route_planner_view.test.mjs) cierra **45/45**, y las guardas de shell (`restocker_route_view`, `coordinator_product_loading_tab`, `N8nChatScope` y `OfflinePwaAssetsAndUiGuard`) siguen en verde con el App Shell en `vendguard-app-shell-v29`. En Chromium, tras revocar la excepción de prueba `1534`, `VEND-0101` volvió a la ruta de Lucía; al abrir su omisión el modal mostró `role="alert"`, «Alerta FEFO activa», «Caducidad: 24/11/2026» y «Máquinas afectadas: VEND-0101», con fondo y borde de error. El caso de perecedero caducado se cubre con el fixture (`has_expired_cold_batch: true`), que exige el título «Perecedero caducado».

**Estado:** Cerrado (2026-11-23).

---

### H-37-04 · P3 · Los formularios piden IDs internos en vez de selectores

**Qué ocurre.** Tres formularios del módulo piden identificadores numéricos que el operador no puede descubrir desde la propia pantalla:

- «Nueva agenda»: `Máquina (ID)` ([`CoordinatorRestockRoutePlannerSubTab.js`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js)).
- «Alta masiva»: `Zona (ID)` y `Máquinas (IDs separados por coma)` ([`CoordinatorRestockRoutePlannerSubTab.js`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js)).
- «Desviar parada»: `Reponedor receptor (ID)` ([`CoordinatorRestockRoutePlannerSubTab.js`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js)).

Las tablas muestran códigos `VEND-xxxx` y nombres de reponedor, pero no sus IDs internos.

**Impacto.** El alta de agenda y el desvío se vuelven propensos a error: hay que conocer el ID de memoria o consultarlo fuera de la consola. El backend valida y rechaza IDs inexistentes, pero la fricción es de la UI.

**Corrección aplicada (2026-11-23).** [`loadPlannerOptions()`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js) carga en paralelo `getMachines({ status: 'active' })`, `getZones()` y `getUsers({ role: 'RESTOCKER', status: 'active' })`; descarta las zonas y reponedores inactivos y, si un catálogo falla, muestra un aviso con reintento sin romper los demás. «Nueva agenda» usa un `select` con `código · sede · tipo` que conserva la máquina actual al versionar una agenda fuera del catálogo activo. «Alta masiva» usa un `select` de zona con `código · nombre` y una lista de checkboxes de máquinas con contador, sin perder la selección múltiple. «Desviar parada» usa un `select` de reponedor con `nombre · operator_code` y el modal muestra el código de la máquina en lugar de `#id`. Los payload conservan exactamente los mismos IDs numéricos; las máquinas se deduplican en cliente.

**Evidencia (2026-11-23).** La línea base de [`coordinator_restock_route_planner.test.mjs`](../tests/frontend/coordinator_restock_route_planner.test.mjs) era **32/32**; con las regresiones `3.0`, `3.0b`, la ampliación de `3.5` y `3.7`, y la guarda `6.6`, cierra **35/35**. Las guardas de shell (`coordinator_product_loading_tab`, `restocker_route_view`, `N8nChatScope` y `OfflinePwaAssetsAndUiGuard`) siguen en verde con el App Shell en `vendguard-app-shell-v30`. En Chromium, con Coordinación y el planificador reales, los selectores publicaron **13 máquinas**, **3 zonas** y **2 reponedores** activos: `VEND-0101 · Hospital del Mar - Edificio Central · Alimentos perecederos…`, `ZONA-BCN-CENTRO · Barcelona Centro y Litoral` y `Lucía Reponedora BCN · REP-01`. La lista de Alta masiva mostró los 13 checkboxes y el contador pasó de 0 a 1 al marcar una máquina; el desvío de `VEND-0101` mostró su código y los receptores Lucía (`REP-01`) y Óscar (`REP-02`). No se guardó ninguna agenda ni excepción durante la comprobación.

**Estado:** Cerrado (2026-11-23).

---

### H-37-05 · P3 · El modal de omisión no tiene semántica de diálogo

**Qué ocurre.** El contenedor de [`RestockerRouteView.js`](../public/assets/js/components/RestockerRouteView.js) no declara `role="dialog"`, `aria-modal="true"` ni `aria-labelledby`; tampoco hay gestión de foco ni cierre con `Escape`.

**Impacto.** Un lector de pantalla no anuncia el modal como diálogo y el foco puede quedar detrás del contenido. Es una deuda de accesibilidad de la hoja móvil, no un fallo de negocio.

**Corrección aplicada (2026-11-23).** El contenedor declara `role="dialog"`, `aria-modal="true"`, `aria-labelledby="route-skip-modal-title"` y `tabindex="-1"`; el título lleva el `id` referenciado. Al abrir se guarda el botón de origen (`event.currentTarget`) y, tras el render, se enfoca el botón «Cerrar». Al cerrar —botón, fondo, Escape o cierre tras guardar— se devuelve el foco al origen si sigue conectado. Un listener global de `keydown` cierra con `Escape` solo cuando el diálogo está visible y no hay una omisión en vuelo; se retira en `beforeUnmount`.

**Evidencia (2026-11-23).** La línea base de [`restocker_route_planner_view.test.mjs`](../tests/frontend/restocker_route_planner_view.test.mjs) era **45/45**; con las regresiones `3.1d` (foco inicial y origen), `3.1e` (Escape cierra y restaura foco), `3.1f` (Escape no cierra mientras guarda) y la ampliación de `5.8` (ARIA y listener), cierra **48/48**. Las guardas de shell (`coordinator_product_loading_tab`, `restocker_route_view`, `N8nChatScope`, `OfflinePwaAssetsAndUiGuard` y el planificador de Coordinación) siguen en verde con el App Shell en `vendguard-app-shell-v31`. En Chromium, el modal real publicó `role="dialog"`, `aria-modal="true"` y `aria-labelledby` resuelto al título «⏭️ Omitir parada hoy»; el foco inicial quedó en `btn-close-route-skip` («Cerrar»), Escape cerró el diálogo y el foco volvió al botón `btn-route-skip-stop` («Omitir parada hoy»). No se registró ninguna omisión durante la comprobación.

**Estado:** Cerrado (2026-11-23).

---

### H-37-06 · P3 · `service_date` pasada se acepta en `TODAY_ROUTE`

**Qué ocurre.** Una propuesta `GET /api/coordinator/product-loading-orders/proposal?demand_mode=TODAY_ROUTE&service_date=2026-01-05` responde `200`, y un `POST /api/coordinator/product-loading-orders` con esa misma fecha responde `201`. La orden creada (`4290`) se canceló después con su motivo para dejar el banco limpio.

**Matiz contractual.** El catálogo de errores de [`restock_route_planner_contracts.md`](../specs/technical/restock_route_planner_contracts.md) declara `422 PAST_SERVICE_DATE_FORBIDDEN` en el contexto de las excepciones diarias, no de las órdenes de carga. La orden tampoco persiste `service_date` (la tabla no tiene esa columna; solo timestamps de ciclo de vida): el parámetro se usa para calcular el alcance de ruta durante la emisión y luego se descarta. Por eso se registra como observación y no como incumplimiento cerrado.

**Impacto.** Un modo llamado «Ruta efectiva de hoy» acepta una fecha histórica y puede emitir una orden sobre una ruta que ya no existe, sin que el dato quede guardado para auditar con qué fecha se calculó. La propuesta es de solo lectura; la emisión sí es una mutación.

**Decisión aprobada (2026-11-23).** Prevalece (a): rechazar con `422 PAST_SERVICE_DATE_FORBIDDEN` las fechas pasadas en propuesta, emisión individual y lote de `TODAY_ROUTE`, manteniendo hoy y el futuro y sin cambiar otros modos. Se descarta (b) porque una foto histórica de M33 usaría stock, FEFO y estado de ruta actuales, exigiría una migración ajena al alcance del hallazgo y permitiría órdenes sobre jornadas cerradas.

**Corrección aplicada.** `ProductLoadingOrderService::assertTodayRouteServiceDate()` normaliza la fecha en `Europe/Madrid` y rechaza `service_date < today` en `buildProposal()`, en `createOrder()` antes del camino de `items` explícitos y en `createBatchOrders()` antes de iterar. No se modificó el esquema ni se añadió persistencia de `service_date`; hoy, futuro y los demás modos conservan su comportamiento.

**Evidencia (2026-11-23).** `php tests/unit/ProductLoadingOrderServiceTest.php`: **57/57**; `php tests/integration/RestockRoutePlannerApiTest.php`: **43/43**, incluidas `6.3b` (propuesta), `6.3c` (emisión individual con `items` explícitos) y `6.3d` (lote), todas con `422` y sin incrementar el recuento de `product_loading_orders`; `php -l` sin errores y `git diff --check` limpio. Las regresiones de hoy/futuro (`6.1`, `6.2`, `6.4`) siguen verdes. No se ejecutaron suites frontend porque no hubo cambios de UI.

**Estado:** Cerrado (2026-11-23).

---

### H-37-07 · P3 · Cambiar la fecha no recarga el tablero hasta pulsar «Actualizar»

**Qué ocurre.** Al cambiar el selector de fecha de `2026-11-23` a `2026-11-24`, el campo muestra la fecha nueva pero los KPIs y las rutas siguen siendo los del día anterior. Solo después de pulsar «↻ Actualizar» llegan los datos correctos: `6` programadas, `4` FEFO, `4` de demanda, `0` completadas, `0` omitidas, `1` sin cobertura, y Lucía pasa a `OFF_SHIFT` con `5` máquinas.

**Impacto.** El operador puede creer que la fecha ya está aplicada y tomar decisiones con los contadores del día anterior. No hay pérdida de datos ni error de cálculo: el backend responde bien a la segunda petición.

**Corrección aplicada.** El selector usa `onServiceDateChange()`, que lee el valor directamente del evento, actualiza `serviceDate` y recarga el tablero. `loadDay()` incorpora `dayRequestSeq` para descartar respuestas de peticiones anteriores, de modo que una carga antigua no pisa la fecha vigente. `↻ Actualizar` se mantiene como recarga explícita y el App Shell sube a `vendguard-app-shell-v32` para invalidar la caché del componente.

**Evidencia (2026-11-23).** `node tests/frontend/coordinator_restock_route_planner.test.mjs`: **38/38**, incluidas `1.7` (cambio de fecha), `1.8` (respuesta obsoleta) y `1.9` (manejador del selector). Guardas verdes: `coordinator_product_loading_tab` **80 aserciones**, `restocker_route_view` **245/245**, `restocker_route_planner_view` **48/48**, `N8nChatScopeTest` **33/33** y `OfflinePwaAssetsAndUiGuardTest` **45/45**. No se modificó el backend ni el contrato.

**Estado:** Cerrado (2026-11-23).

---

## 4. Evidencia verde que no es hallazgo

Estas comprobaciones pasaron durante la misma corrida y se conservan para delimitar el alcance:

| Comprobación | Resultado |
|---|---|
| Sonda API completa | **63 PASS · 1 FAIL** (el fallo era un supuesto de la sonda, no del contrato) |
| RBAC de Coordinación y Reponedor | `403`/`401` correctos en los casos probados |
| Agendas | Alta, versionado, baja lógica y alta masiva atómica con rollback |
| Ruta del día | KPIs, bandeja sin cobertura, rutas por reponedor y excepciones |
| Ruta móvil | 3 paradas, 5 máquinas, 8.8 km, bloques FEFO → Demanda → Cadencia |
| Privacidad | Sin `unit_cost_eur` ni `commercial_spoilage_loss_eur`; sin notas internas ajenas |
| Omisión | `201`, motivo de 20+ caracteres, idempotencia y `CONFLICT` offline |
| Desvío | `201`, visible en la ruta del receptor, revocación `200` y no fingida |
| Salvaguarda FEFO | Override `1534` con alerta y notificación `6610` `CRITICAL_SANITARY` |
| Visita M34 | Visita `4290` `COMPLETED` enlazada a `route_schedule_id = 8` |
| UI de agenda | `VEND-0901` creada con «Diaria · Mañana», visible y dada de baja con toast |
| UI de mapa | 3 pines, polilínea, leyenda, `8.8 km` y enlace a Google Maps |

---

## 5. No verificado

- Desconexión de red real con la PWA instalada y recarga en frío; el snapshot de `H-37-01` ya persiste y se recupera con la base abierta, pero no se ejecutó el corte físico de red.
- Dispositivo móvil físico; el recorrido se hizo con el navegador del panel.
- Envío real del lote masivo desde la UI; el modal se revisó y la atomicidad está cubierta por la sonda API.
- Validación cliente del motivo de omisión por debajo de 20 caracteres; el `422` del servidor sí está cubierto por la sonda.
- Ciclo completo con el service worker y la app cerrada.

---

## 6. Orden de corrección propuesto

1. **H-37-01 (P1) — corregido el 2026-11-23 (commit `088f66e`).** Snapshot offline: clonar el `route_plan` antes de `put()` y cubrir el caso con un proxy reactivo.
2. **H-37-03 (P2) — corregido el 2026-11-23.** Aviso FEFO y perecedero caducado en el modal de omisión; era la deuda más sensible por Art. II.
3. **H-37-02 (P2) — corregido el 2026-11-23.** Origen del optimizador en `buildCoordinatorDay()` y aserción de distancia.
4. **H-37-04 (P3) — corregido el 2026-11-23.** Selectores con nombre/código en Nueva agenda, Alta masiva y Desviar parada.
5. **H-37-05 (P3) — corregido el 2026-11-23.** Semántica ARIA, foco y cierre con `Escape` del modal de omisión.
6. **H-37-06.** Decidir contrato antes de implementar; si se rechaza la fecha pasada, añadir `422 PAST_SERVICE_DATE_FORBIDDEN` y su aserción. Si se permite, persistir `service_date`.
7. **H-37-07 (P3).** Recarga al cambiar la fecha o estado visual de «desactualizado».

Los puntos 2, 4 y 5 son correcciones contra requisitos ya existentes; no requieren cambiar la especificación. El punto 3 quedó corregido sin cambio de contrato. El punto 6 requiere decisión de producto y, si se opta por permitir fechas pasadas, una enmienda del contrato. El punto 7 es una mejora de usabilidad sin cambio de contrato.

---

## 7. Nota de mantenimiento

Este acta se actualizará cuando cada hallazgo se corrija, con la misma disciplina que las auditorías de los módulos 33–36: el estado pasará de **Abierto** a **Cerrado (fecha)** y se añadirá la evidencia focalizada que lo demuestre, incluida la suite o la comprobación manual reproducible. No se declarará cerrado un hallazgo P1 mientras no exista una prueba de regresión que reproduzca el caso original y pase en verde.

**Estado del árbol:** árbol limpio tras los commits de corrección de `H-37-01` (`088f66e`), `H-37-02` (`bf7b018`), `H-37-03`, `H-37-04` y `H-37-05`; sin push. Los scripts de la sonda viven en `scratch/` y no se versionan. El acta recoge la corrida original y las correcciones posteriores.
