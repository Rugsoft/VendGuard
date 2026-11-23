# Hallazgos · Pruebas visuales y de UI de la Spec 37

- **Módulo:** 37 · Planificador de Rutas Periódicas de Carga y Priorización Dinámica por Demanda (`37-restock-route-planner`)
- **Especificación:** [`specs/37-restock-route-planner/spec.md`](../specs/37-restock-route-planner/spec.md)
- **Contratos:** [`specs/technical/restock_route_planner_contracts.md`](../specs/technical/restock_route_planner_contracts.md)
- **Sistema de diseño:** [`docs/design.md`](design.md)
- **Fecha de la corrida:** 2026-11-23
- **Rama:** `feat/restock-route-planner`
- **HEAD de la corrida:** `cefed9e`
- **Árbol:** la corrida original no modificó código. Las correcciones posteriores de H-37-08, H-37-09, H-37-10, H-37-11, H-37-12, H-37-13, H-37-14 y H-37-15 tocan la vista móvil, el servicio de agendas, los modales del mapa y compartido, el read model del tablero, el resumen responsive, el estado vacío de rutas, la concordancia del alta masiva, sus suites y este acta; se registran en commits separados. H-37-16 se cierra como externo documentado, sin cambios de código.
- **Entorno:** app local `http://127.0.0.1:8000` contra el banco `vendguard_battery` (PostgreSQL `127.0.0.1:54331`), con las credenciales semilla del entorno de batería.
- **Alcance:** recorrido manual en Chromium como Coordinación y Reponedor, con clics y teclado reales, a `1280 × 932 px` y `360 × 800 px`. Se contrastaron red, DOM, respuestas API, PostgreSQL e IndexedDB.
- **Naturaleza:** acta de verificación visual y de UI sobre la aplicación ensamblada. **No sustituye** a [`hallazgos_pruebas_funcionales_spec_37.md`](hallazgos_pruebas_funcionales_spec_37.md), que recoge la corrida funcional y los cierres `H-37-01`–`H-37-07`, ni a [`auditoria_spec_37_brechas.md`](auditoria_spec_37_brechas.md), que certifica las brechas `AUD-37-01`–`AUD-37-09` con suites automatizadas.

> Los identificadores `H-37-08`–`H-37-16` son nuevos para no reutilizar los `H-37-01`–`H-37-07` del acta funcional. No queda ningún hallazgo abierto: H-37-08, H-37-09, H-37-10, H-37-11, H-37-12, H-37-13, H-37-14 y H-37-15 quedaron corregidos el 2026-11-23 con regresiones automatizadas, y H-37-16 se cerró como externo documentado.

---

## 1. Resumen ejecutivo

No se detectó ningún fallo **P1** (bloqueo sin workaround ni pérdida de datos). Los dos fallos P2 afectaban al flujo principal del reponedor y a la consulta de agendas de Coordinación; **ambos están corregidos**. El resto son deuda de accesibilidad, presentación o copy, salvo H-37-16: un solapamiento del widget externo de n8n, cerrado como deuda ajena documentada.

| Orden | ID | Prioridad | Hallazgo | Requisito afectado | Estado |
|---:|---|---|---|---|---|
| 1 | **H-37-08** | **P2 · Media** | La ruta móvil no se recarga al iniciar o cerrar turno | `RF-05.2`, `RF-03` | **Cerrado (2026-11-23)** |
| 2 | **H-37-09** | **P2 · Media** | El filtro de agendas ignora la búsqueda en máquinas sin agenda | `RF-01.3`, `RF-05.3` | **Cerrado (2026-11-23)** |
| 3 | **H-37-10** | **P3 · Baja-media** | El cierre del mapa tenía contraste 1,72:1 y el modal no recibía foco ni cerraba con Escape | `RNF-04`, `RF-05.2` | **Cerrado (2026-11-23)** |
| 4 | **H-37-11** | **P3 · Baja-media** | El modal de «Nueva agenda» no atrapaba el foco | `RNF-04` | **Cerrado (2026-11-23)** |
| 5 | **H-37-12** | **P3 · Baja** | La tabla de excepciones mostraba el ID interno (`#10`) en vez del código de máquina | `RF-05.3`, Art. V.4 | **Cerrado (2026-11-23)** |
| 6 | **H-37-13** | **P3 · Baja** | A 360 px las etiquetas del resumen de ruta se salían de su celda | `RNF-04`, `RF-05.3` | **Cerrado (2026-11-23)** |
| 7 | **H-37-14** | **P3 · Baja** | El estado vacío de rutas decía «No hay paradas pendientes» aunque hubiera paradas sin cobertura | `RF-03.3`, `RF-05.3` | **Cerrado (2026-11-23)** |
| 8 | **H-37-15** | **P4 · Cosmético** | El contador de Alta masiva decía «1 seleccionadas» | `RNF-04` | **Cerrado (2026-11-23)** |
| 9 | **H-37-16** | **P4 · Externo** | El widget flotante de n8n tapa acciones en la esquina inferior derecha | Transversal; no es código del M37 | **Cerrado como externo (2026-11-23)** |

**Lectura de prioridades:** P2 deja un requisito funcional sin cumplir o produce un dato operativo incorrecto, con workaround; P3 es deuda de accesibilidad, presentación o semántica sin bloqueo; P4 es cosmético o ajeno al módulo.

---

## 2. Metodología

1. Se comprobó `/api/health` → **200** y se inició sesión con Coordinación y con Lucía (`REP-01`).
2. Se recorrió «🚚 Órdenes de Carga y Picking de Frío» → «🗺️ Ruta del día y Agendas»: KPIs, sin cobertura, rutas por reponedor, excepciones, agendas, fecha, alta/versionado/baja, alta masiva y desvío/revocación.
3. Se recorrió la superficie móvil de Lucía: inicio de turno, ruta por bloques, mapa, modal de omisión, validación de motivo, aviso FEFO y snapshot de IndexedDB.
4. Cada paso se contrastó con la petición de red, el DOM renderizado, la respuesta API, PostgreSQL y el log del servidor.
5. Se probaron los tamaños `1280 × 932` y `360 × 800`; se midieron `scrollWidth`/`clientWidth` de etiquetas y contenedores para distinguir scroll interno intencional de solapamiento real.
6. La corrida original no corrigió código ni hizo commit. Las correcciones posteriores de H-37-08, H-37-09, H-37-10, H-37-11, H-37-12, H-37-13, H-37-14 y H-37-15 se validaron con sus suites; H-37-08, H-37-10, H-37-11, H-37-12, H-37-13, H-37-14 y H-37-15 también se verificaron en Chromium. H-37-16 se cerró como externo documentado tras verificar que el lanzador fijo del asistente mantiene el puntero y que su mitigación exige una decisión de producto sobre el widget. Se revocó la excepción de prueba y se cerró el turno de Lucía al terminar.

**Datos utilizados:** `VEND-0101`, `VEND-0102`, `VEND-0401`, `VEND-0501`, `VEND-0502`, `VEND-0701`, `VEND-0801` y `VEND-0901`; agenda de prueba de `VEND-0901` (dada de baja); desvío FEFO de `VEND-0701` a Lucía (revocado).

---

## 3. Hallazgos detallados

### H-37-08 · P2 · CORREGIDO · La ruta móvil se recarga al iniciar y cerrar turno

**Qué ocurría.** Con Lucía en `OFF_SHIFT`, la superficie móvil muestra `0.0 km` y «Sin paradas asignadas». Al pulsar «Iniciar turno», la cabecera cambia a «En turno», pero la ruta sigue vacía y no se vuelve a pedir `/api/restocker/my-route`. Al recargar la página aparece la ruta correcta: **3 paradas, 5 máquinas y 8,77 km**. El endpoint ya devolvía esos datos al iniciar el turno.

**Causa.** [`RestockerRouteView.js:1244`](../public/assets/js/components/RestockerRouteView.js) define `startShift()` sobre `runShiftAction()`; este aplica la respuesta del turno con `applyShiftResponse()` ([`:1214`](../public/assets/js/components/RestockerRouteView.js)), pero no llamaba a `loadMyRoute()`. `closeShift()` tampoco recargaba la ruta.

**Impacto.** El reponedor podía creer que no tenía trabajo hasta que recargaba manualmente. Rompía la continuidad del flujo principal de inicio de jornada, aunque el dato del servidor era correcto.

**Reproducción original.**

1. Entrar como Lucía con el turno cerrado.
2. Abrir la ruta móvil y comprobar «0.0 km».
3. Pulsar «Iniciar turno».
4. Verificar que el estado pasa a «En turno» pero no hay petición a `/api/restocker/my-route` y la ruta sigue vacía.
5. Recargar: aparecen las 3 paradas y los 8,77 km.

**Corrección aplicada (2026-11-23).** `startShift()` pasa `refreshRoute = true` a `runShiftAction()`, que ejecuta `loadMyRoute()` solo después de un `POST` correcto. `closeShift()` recarga la hoja móvil después de aplicar `OFF_SHIFT` y antes de liberar `isShiftLoading`. Así la ruta y el estado del turno se publican con la misma transición; un fallo del `POST` no provoca una recarga.

**Evidencia (2026-11-23).** `node tests/frontend/restocker_route_view.test.mjs`: **247/247 · 0 fallos**, con las aserciones `H-37-08: Iniciar Turno recarga /api/restocker/my-route tras el éxito` y `H-37-08: Cerrar Turno recarga /api/restocker/my-route y deja OFF_SHIFT`. En Chromium, con el service worker desregistrado y la caché limpia: al pulsar «Iniciar Turno», el log mostró `POST /api/technician/shift/start → 200` seguido de `GET /api/restocker/my-route → 200`, y la ruta pasó a **3 paradas, 5 máquinas y 8,8 km** sin recargar la página; al pulsar «Cerrar Turno», `POST /api/technician/shift/close → 200` seguido de `GET /api/restocker/my-route → 200`, y la ruta quedó vacía con `0.0 km`. El cierre dejó el turno en `OFF_SHIFT`.

**Estado:** Cerrado (2026-11-23).

---

### H-37-09 · P2 · CORREGIDO · El filtro de agendas ignora la búsqueda en máquinas sin agenda

**Qué ocurría.** En la tabla de «Agendas periódicas», buscar `VEND-0901` y pulsar «Filtrar» devuelve **4 filas**: `VEND-0302`, `VEND-0801`, `VEND-0901` y `VEND-1001`. Solo una coincide con el término; las otras tres son máquinas sin agenda que se cuelan en el resultado. Por API, `GET /api/coordinator/restock-schedules?limit=100&search=VEND-0901` confirma el mismo resultado. Buscar `VEND-0101` devuelve 5 filas: la máquina correcta más las 4 sin agenda.

**Causa.** En [`RestockRouteScheduleService.php`](../src/Application/Service/RestockRouteScheduleService.php), las máquinas sin agenda se añadían a `$items` y hacían `continue` antes de llegar al bloque `if ($search !== null)`. La búsqueda solo se aplicaba a las máquinas que sí tienen agenda.

**Impacto.** El coordinador veía máquinas que no había buscado y podía editar o crear una agenda sobre la fila equivocada. Era un fallo de datos de la consulta, no un detalle visual.

**Reproducción original.**

1. Abrir «Agendas periódicas» con Coordinación.
2. Escribir `VEND-0901` en «Buscar» y pulsar «Filtrar».
3. Observar las 4 filas y comparar con `VEND-0901` en la tabla.
4. Repetir con `VEND-0101`: aparece la fila correcta más las 4 sin agenda.

**Corrección aplicada (2026-11-23).** La comprobación de `$search` se movió antes de la bifurcación entre máquinas con agenda y sin agenda, después de los filtros de máquina, zona y tipo. Así el término se aplica siempre al `machine_code`, `location_name` y `site_code`; la rama sin agenda ya no puede añadir filas que no coinciden. El filtro `has_schedule` y la cadencia conservan su comportamiento.

**Evidencia (2026-11-23).** `source deploy/local/postgres/.env.battery && php tests/integration/RestockRoutePlannerApiTest.php`: **44/44 · 0 fallos**, incluida la nueva aserción `2.1b`, que antes devolvía `total=3` y ahora devuelve `total=1` con `machine_code=RRP9-SNACK-C` y `has_schedule=false`. `php tests/unit/RestockRoutePlannerServicesTest.php`: **120/120 · 0 fallos**. `php -l` del servicio y de la suite sin errores.

**Estado:** Cerrado (2026-11-23).

---

### H-37-10 · P3 · CORREGIDO · El cierre del mapa tenía mal contraste y el modal no era plenamente accesible

**Qué ocurría.** En el modal «🗺️ Mapa de mi ruta», el botón «Cerrar» se pintaba con `vg-btn-ghost`, cuyo color es `--color-ink-secondary` (`#434c5f`), sobre la cabecera azul `--color-primary` (`#2560ff`). El contraste calculado era **1,72:1**, muy por debajo del mínimo WCAG AA de 4,5:1 para texto normal. El botón se veía gris casi invisible en la captura a 360 px.

Además, el modal no movía el foco al abrirse y no se cerraba con `Escape`; solo respondía al botón o al clic en el fondo.

**Causa.** [`RestockerRouteMapModal.js:303`](../public/assets/js/components/RestockerRouteMapModal.js) usaba `vg-btn-ghost` sin el tono inverso que existe para superficies oscuras ([`design-tokens.css:422`](../public/assets/css/design-tokens.css)). `openRouteMap()` en [`RestockerRouteView.js:923`](../public/assets/js/components/RestockerRouteView.js) solo cambiaba `showRouteMapModal`; el modal no tenía gestión de foco ni listener de `Escape`.

**Impacto.** Para baja visión el cierre era difícil de localizar. Para teclado, el modal no recibía el foco y no se podía cerrar con `Escape`, lo que obligaba a tabular por una superficie cuyo orden no estaba controlado.

**Reproducción original.**

1. Entrar como Lucía con ruta visible.
2. Abrir «🗺️ Mapa».
3. Inspeccionar el color de «Cerrar» o calcular el contraste con los tokens indicados.
4. Pulsar `Escape`: el modal permanecía abierto.

**Corrección aplicada (2026-11-23).** El cierre conserva `vg-btn-ghost` y añade `vg-btn-ghost-inverse`, que pinta el texto en blanco (`#ffffff`) sobre la cabecera azul: **5,0:1**. El modal guarda el elemento que lo abrió, enfoca «Cerrar» tras el render, mantiene `Tab` y `Shift+Tab` dentro del diálogo, cierra con `Escape` y devuelve el foco al botón «Mapa» ([`RestockerRouteMapModal.js:114`](../public/assets/js/components/RestockerRouteMapModal.js), [`:170`](../public/assets/js/components/RestockerRouteMapModal.js), [`:297`](../public/assets/js/components/RestockerRouteMapModal.js)). El listener se retira al cerrar y al desmontar; el diálogo declara `tabindex="-1"`.

**Evidencia (2026-11-23).** `node tests/frontend/restocker_route_planner_view.test.mjs`: **52/52 · 0 fallos**, con las aserciones `5.13`–`5.16`, que cubren la clase inversa y los refs, el foco en «Cerrar», el cierre con `Escape`, el retorno al disparador y el ciclo `Tab`/`Shift+Tab`. En Chromium, con la caché y el service worker limpios, el modal abrió con `document.activeElement` en `btn-close-route-map`, color `rgb(255, 255, 255)` sobre `rgb(37, 96, 255)`; `Shift+Tab` desde el primer control llevó al último (`btn-route-map-full-navigation`), `Tab` desde el último volvió al cierre, y `Escape` cerró el diálogo devolviendo el foco a `btn-open-restocker-route-map` («🗺️ Mapa»).

**Estado:** Cerrado (2026-11-23).

---

### H-37-11 · P3 · CORREGIDO · El modal de «Nueva agenda» no atrapaba el foco

**Qué ocurría.** Al abrir «＋ Nueva agenda», el foco se quedaba en el botón que abrió el modal, detrás del overlay. Pulsando `Tab`, el foco pasaba a «⚡ Alta masiva», también detrás del diálogo, en lugar de entrar en el formulario. `Escape` sí cerraba el modal.

**Causa.** El modal se construye con [`ModalDialog.js`](../public/assets/js/components/ModalDialog.js), que declaraba `role="dialog"` y `aria-modal="true"`, pero no movía el foco al abrir ni implementaba un focus trap. El problema afectaba también a los modales de alta masiva y de excepción que usan el mismo componente.

**Impacto.** Un usuario de teclado o lector de pantalla podía interactuar con la página de fondo sin darse cuenta. Era una deuda de accesibilidad del componente compartido, no del formulario concreto.

**Reproducción original.**

1. Abrir «＋ Nueva agenda» con Coordinación.
2. Comprobar que `document.activeElement` sigue siendo el botón «＋ Nueva agenda».
3. Pulsar `Tab`: el foco pasa a «⚡ Alta masiva», fuera del diálogo.
4. Pulsar `Escape`: el modal se cierra.

**Corrección aplicada (2026-11-23).** `ModalDialog` guarda el elemento que abrió el diálogo, enfoca el primer control tras el render, contiene `Tab` y `Shift+Tab` dentro del `.vg-modal`, cierra con `Escape` y devuelve el foco al disparador. La contención se aplica también cuando el foco está fuera del diálogo y deja el foco en el propio diálogo si no hay controles. El listener se retira al cerrar y al desmontar; el diálogo conserva `role="dialog"`, `aria-modal="true"`, `aria-labelledby` y `tabindex="-1"` ([`ModalDialog.js:66`](../public/assets/js/components/ModalDialog.js), [`:114`](../public/assets/js/components/ModalDialog.js), [`:149`](../public/assets/js/components/ModalDialog.js)).

**Evidencia (2026-11-23).** `node tests/unit/UIComponentsTest.mjs`: **45/45 · 0 fallos**, con las aserciones `2.13`–`2.16`, que cubren el foco inicial, el retorno al disparador y los dos sentidos del focus trap. `node tests/frontend/coordinator_restock_route_planner.test.mjs`: **38/38 · 0 fallos**. En Chromium, con la caché y el service worker limpios, «＋ Nueva agenda» abrió con `document.activeElement` en «Cerrar ventana modal»; `Shift+Tab` desde el primer control llevó a `route-planner-schedule-form-save` («Guardar agenda»), `Tab` desde el último volvió al cierre, y `Escape` cerró el diálogo, devolvió el foco a `route-planner-new-schedule` («＋ Nueva agenda») y dejó `document.body.style.overflow` vacío.

**Estado:** Cerrado (2026-11-23).

---

### H-37-12 · P3 · CORREGIDO · La tabla de excepciones mostraba el ID interno de la máquina

**Qué ocurría.** Se creó un desvío FEFO de `VEND-0701` a Lucía desde Coordinación. La sección «Excepciones vivas del día» mostraba una fila con la celda **`#10`**, no `VEND-0701`. La API devolvía `machine_id: 10` y no publicaba `machine_code` en `active_overrides`.

**Causa.** [`CoordinatorRestockRoutePlannerSubTab.js:831`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js) pintaba `<td>#{{ override.machine_id }}</td>` directamente. El read model del tablero construía las excepciones desde `RestockRouteDailyOverride::toArray()`, que no conoce el código de máquina, y el saneador del servicio no lo permitía.

**Impacto.** El coordinador no podía identificar la máquina afectada sin conocer la correspondencia interna. El dato era correcto, pero la presentación incumplía el criterio de opacidad de identificadores internos del Art. V.4.

**Reproducción original.**

1. Crear un desvío de `VEND-0701` a una reponedora desde «Rutas por reponedor» o desde «Paradas sin cobertura».
2. Revisar «Excepciones vivas del día».
3. Observar `#10` en la columna «Máquina».
4. Revocar la excepción: la fila desaparece y la ruta vuelve a su estado anterior.

**Corrección aplicada (2026-11-23).** [`PdoRestockRouteReadModel.php:390`](../src/Infrastructure/Repository/PdoRestockRouteReadModel.php) une `restock_route_daily_overrides` con `machines` y publica `machine_code` en cada excepción; [`RestockRoutePlannerService.php:791`](../src/Application/Service/RestockRoutePlannerService.php) lo conserva en el saneador. La tabla pinta `override.machine_code || '—'`, sin volver a exponer el identificador interno ([`CoordinatorRestockRoutePlannerSubTab.js:831`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js)).

**Evidencia (2026-11-23).** `node tests/frontend/coordinator_restock_route_planner.test.mjs`: **39/39 · 0 fallos**, con la aserción `6.7`. `php tests/integration/RestockRoutePlannerApiTest.php`: **45/45 · 0 fallos**, con la aserción `4.1b`, que exige `machine_code = RRP9-SNACK-A` en `active_overrides`. `php tests/integration/PdoRestockRoutePlannerGatewayTest.php`: **36/36 · 0 fallos**. `php tests/unit/RestockRoutePlannerServicesTest.php`: **120/120 · 0 fallos**. En Chromium, con la caché y el service worker limpios, se creó un desvío de `VEND-0701` a Lucía: la primera celda de «Excepciones vivas del día» fue **`VEND-0701`**, no `#10`. La excepción de prueba se revocó y la sección volvió al estado vacío.

**Estado:** Cerrado (2026-11-23). La excepción de prueba se revocó; no queda ninguna activa.

---

### H-37-13 · P3 · CORREGIDO · A 360 px las etiquetas del resumen de ruta se salían de su celda

**Qué ocurría.** En la tarjeta de ruta de «Rutas por reponedor», a `360 × 800 px`, las etiquetas del resumen se salían de su columna. «Completadas» ocupaba `77 px` en una celda de `64 px` y «Bloqueadas» `68 px` en `64 px`; visualmente se leía **«CompletadasOmitidas»**. La página no generaba scroll horizontal; el desbordamiento era del texto dentro de la cuadrícula.

**Causa.** Las etiquetas se pintaban en [`CoordinatorRestockRoutePlannerSubTab.js:802-805`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js) dentro de una cuadrícula `repeat(auto-fit, minmax(0, 1fr))`: cuatro columnas iguales que a 360 px dejaban unos 64 px por celda, sin `overflow`, `min-width` ni tamaño de fuente adaptativo.

**Impacto.** El resumen seguía siendo legible con esfuerzo, pero perdía separación entre métricas y aparentaba un texto corrupto. Incumplía la expectativa de legibilidad y acabado a 360 px de `RNF-04`.

**Reproducción original.**

1. Abrir el planificador a `360 × 800 px` con una ruta de reponedor visible.
2. Localizar el resumen «Completadas / Omitidas / Bloqueadas / FEFO».
3. Medir `scrollWidth` y `clientWidth` de las etiquetas: `77 > 64` y `68 > 64`.
4. Observar la unión visual de «Completadas» y «Omitidas».

**Corrección aplicada (2026-11-23).** La cuadrícula reserva `minmax(120px, 1fr)` por columna ([`CoordinatorRestockRoutePlannerSubTab.js:801`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js)). Con los 281 px útiles de la tarjeta a 360 px caben dos columnas de 136,5 px en dos filas; en escritorio siguen cabiendo las cuatro métricas en una sola fila.

**Evidencia (2026-11-23).** `node tests/frontend/coordinator_restock_route_planner.test.mjs`: **40/40 · 0 fallos**, con la aserción `6.8`. En Chromium, a `360 × 800 px` y con la ruta real de Lucía, la cuadrícula calculó dos pistas de `136,5 px`; las etiquetas quedaron dentro de su celda (`Completadas` y `Bloqueadas` sin invadir la siguiente), y la página mantuvo `scrollWidth = clientWidth = 343`. A `1280 × 932 px` se mantuvieron cuatro pistas de `294,25 px`. Lucía volvió a `OFF_SHIFT` al terminar.

**Estado:** Cerrado (2026-11-23).

---

### H-37-14 · P3 · CORREGIDO · El estado vacío de rutas era engañoso cuando había paradas sin cobertura

**Qué ocurría.** Con los dos reponedores fuera de turno, la sección «Rutas por reponedor» mostraba «No hay paradas pendientes para la fecha seleccionada», mientras el KPI «Sin cobertura» y la bandeja prioritaria listaban **9 paradas**. El mensaje sugería que no quedaba trabajo, cuando en realidad no había ningún reponedor en turno para cubrirlo.

**Causa.** El texto de [`CoordinatorRestockRoutePlannerSubTab.js`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js) se mostraba siempre que no había rutas de reponedor, sin distinguir entre «ningún reponedor en turno», «todos los reponedores sin paradas» y «paradas sin cobertura».

**Impacto.** El coordinador podía interpretar que el día estaba cubierto y dejar sin atender las paradas de la bandeja. No hay pérdida de datos ni error de cálculo; era un problema de semántica del estado vacío.

**Reproducción original.**

1. Asegurar que Lucía y Óscar están fuera de turno.
2. Abrir el planificador de hoy.
3. Comparar «Rutas por reponedor» con el KPI y la tabla de «Paradas sin cobertura».
4. Observar el mensaje «No hay paradas pendientes» junto a 9 paradas sin cobertura.

**Corrección aplicada (2026-11-23).** El estado vacío pasa por el computed `emptyRoutesMessage`: si no hay rutas pero la bandeja contiene paradas sin cobertura, publica «Hay 9 paradas sin cobertura y ninguna ruta de reponedor para la fecha seleccionada. Revisa los turnos o desvía las paradas desde la bandeja superior.» Solo conserva «No hay paradas pendientes» cuando tampoco hay paradas sin cobertura. La tarjeta incorpora `data-testid="route-planner-routes-empty"` para la comprobación dirigida.

**Evidencia (2026-11-23).** `node tests/frontend/coordinator_restock_route_planner.test.mjs`: **42/42 · 0 fallos**, con las aserciones `1.10` y `1.11`, que cubren el caso con 9 paradas sin cobertura y el estado neutro sin paradas. En Chromium, con el tablero real sin rutas y **9** paradas en la bandeja, la sección mostró el mensaje correcto y ningún «No hay paradas pendientes»; a `1280 × 932 px` la tarjeta midió 622 px y a `360 × 800 px` 311 px, con `scrollWidth = clientWidth = 343` en ambos casos.

**Estado:** Cerrado (2026-11-23).

---

### H-37-15 · P4 · CORREGIDO · El contador de Alta masiva decía «1 seleccionadas»

**Qué ocurría.** En el modal «⚡ Alta masiva», al marcar una sola máquina el texto quedaba «Máquinas (opcional · **1 seleccionadas**)». La concordancia debía ser «1 seleccionada».

**Causa.** [`CoordinatorRestockRoutePlannerSubTab.js`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js) usaba siempre el plural `seleccionadas`, sin alternar según `bulkForm.machine_ids.length`.

**Impacto.** Cosmético; no altera la selección ni el payload.

**Reproducción original.**

1. Abrir «⚡ Alta masiva».
2. Marcar una máquina.
3. Leer el contador sobre la lista.

**Corrección aplicada (2026-11-23).** El computed `bulkSelectionLabel` devuelve «1 seleccionada» para una máquina y «N seleccionadas» para el resto de cantidades; la etiqueta del modal consume ese valor en lugar de concatenar siempre el plural.

**Evidencia (2026-11-23).** `node tests/frontend/coordinator_restock_route_planner.test.mjs`: **43/43 · 0 fallos**, con la aserción `3.7b`, que cubre singular, plural y el uso del computed en la plantilla. En Chromium, con una máquina marcada el contador mostró «Máquinas (opcional · 1 seleccionada)»; al marcar la segunda, «Máquinas (opcional · 2 seleccionadas)».

**Estado:** Cerrado (2026-11-23).

---

### H-37-16 · P4 · CERRADO COMO EXTERNO DOCUMENTADO · El widget flotante de n8n tapa acciones en la esquina inferior derecha

**Qué ocurre.** A `1280 × 932 px`, el botón «Desviar» de la fila visible inferior quedó debajo del SVG del chat flotante de n8n. El clic real no abría el modal y `document.elementFromPoint()` devolvía el `path` del widget. Al hacer scroll y dejar el botón libre, el modal abrió correctamente.

**Causa.** El widget externo del chat monta un lanzador fijo de 64 px en la esquina inferior derecha con mayor `z-index` que la tabla. No es código del M37: el repositorio carga el bundle de n8n y ya aplica la mitigación H-1 del Módulo 35, que hace transparente al puntero el contenedor `.chat-window-wrapper`, pero mantiene clicables la burbuja y el panel (`.chat-window-toggle` y `.chat-window`). La burbuja debe capturar el puntero para poder abrir el asistente; por eso el solapamiento con la acción inferior es inevitable cuando ambos coinciden.

**Mitigación existente y sus límites.** H-1 añadió `pointer-events: none` al contenedor y reactivó el puntero solo en el lanzador y el panel, además de un pasillo de 88 px para la tabla de recaudación por debajo de 1024 px. Ese pasillo no cubre el planificador de rutas ni el viewport de 1280 px, y no existe una opción de posición en `CHAT_ASSISTANT_DEFAULTS`: la configuración del repositorio solo permite activar/desactivar el asistente, cambiar sus URLs y ajustar tiempos de carga.

**Por qué no se corrige desde el repositorio.** Mover la burbuja solo traslada el solape a otra zona; reservar un pasillo fijo en todas las superficies reduciría el ancho útil de la consola y seguiría sin proteger cualquier otro control que caiga bajo un elemento fijo; hacerla atravesable al puntero dejaría el asistente inaccesible; y retirar el asistente con `enabled: false` es una decisión de producto sobre una funcionalidad transversal, no una corrección del M37. La acción afectada sigue siendo alcanzable con scroll y no hay pérdida de datos.

**Decisión (2026-11-23).** Se cierra como **externo documentado**. No se modifica `CoordinatorRestockRoutePlannerSubTab.js`, `styles.css` ni `n8nChat.js`: la eliminación del solape requiere que el propietario del widget decida moverlo, ocultarlo en determinadas superficies o retirar el asistente. Queda registrado como riesgo residual de la consola, con el scroll como workaround.

**Evidencia (2026-11-23).** `node tests/unit/N8nChatScopeTest.mjs`: **33/33 · 0 fallos**; las aserciones `5.1` y `5.2` confirman que el contenedor no captura el puntero y que el lanzador sí, y `5.3`–`5.5` que el pasillo existente pertenece solo a la tabla de recaudación. La revisión de `public/assets/css/styles.css` y `public/assets/js/config/chatAssistant.js` confirma que no hay posición configurable del widget ni un pasillo global.

**Estado:** Cerrado (2026-11-23) como externo documentado; sin cambios de código.

---

## 4. Comprobaciones que no son hallazgo

Estas comprobaciones pasaron en la misma corrida y delimitan el alcance:

| Comprobación | Resultado |
|---|---|
| Cambio de fecha del tablero y aviso de proyección futura | Correcto |
| Alta, versionado y baja lógica de una agenda semanal | Correcto |
| Modales compartidos: foco inicial, focus trap y retorno al disparador | Correcto; H-37-11 corregido en `ModalDialog` |
| Tabla de excepciones: código de máquina en lugar del ID interno | Correcto; H-37-12 corregido |
| Resumen de ruta a 360 px: etiquetas dentro de su celda y 2×2 sin solapamiento | Correcto; H-37-13 corregido |
| Estado vacío de rutas con 9 paradas sin cobertura: copy correcto, sin scroll horizontal a 360 px | Correcto; H-37-14 corregido |
| Modal de alta masiva, selector de zonas/máquinas y contador | Correcto; H-37-15 corregido |
| Validación de omisión sin causa o con motivo corto | Correcto |
| Modal de omisión: `role="dialog"`, `aria-modal`, foco inicial, `Escape` y retorno de foco | Correcto |
| Aviso FEFO real: `role="alert"`, caducidad y máquinas afectadas | Correcto |
| Desvío y revocación desde Coordinación | Correcto |
| Mapa: paradas, máquinas, km, leyenda y enlace a Google Maps | Correcto |
| Mapa a 360 px: cabe con scroll interno y sin desbordamiento de página | Correcto; contraste y teclado corregidos en H-37-10 |
| Snapshot de ruta en IndexedDB | 1 fila, 4 paradas, `saved_at` actualizado |
| Log del servidor | Sin errores PHP del M37 |

---

## 5. No verificado

- Corte de red real con la PWA instalada y recarga en frío; se comprobó que el snapshot existe en IndexedDB, pero no se simuló la pérdida de conectividad.
- Dispositivo móvil físico; el recorrido se hizo con el navegador del panel y viewports emulados.
- Envío real del lote masivo desde la UI; el modal y la atomicidad por API ya están cubiertos por el acta funcional.
- El `401` aislado visto en consola no se pudo atribuir al M37; los errores del chat externo de n8n (`Failed to fetch` desde `cdn.jsdelivr.net/npm/@n8n/chat`) son ajenos al módulo.

---

## 6. Orden de corrección propuesto

1. **H-37-08 (P2) — corregido el 2026-11-23.** Recargar `my-route` tras iniciar y cerrar turno, con una regresión que comprueba que la petición se dispara.
2. **H-37-09 (P2) — corregido el 2026-11-23.** Aplicar `search` antes de la rama de máquinas sin agenda y cubrirlo con una aserción API que exige una única fila.
3. **H-37-10 (P3) — corregido el 2026-11-23.** Contraste inverso, foco inicial, focus trap, `Escape` y retorno al disparador en el mapa, con regresión frontend.
4. **H-37-11 (P3) — corregido el 2026-11-23.** `ModalDialog` incorpora foco inicial, focus trap y retorno al disparador; el mapa ya no comparte esta deuda.
5. **H-37-12 (P3) — corregido el 2026-11-23.** Publicar `machine_code` en las excepciones y pintarlo en la tabla, con regresión API y UI.
6. **H-37-13 (P3) — corregido el 2026-11-23.** Resumen de ruta responsive con columnas mínimas de 120 px y regresión frontend.
7. **H-37-14 (P3) — corregido el 2026-11-23.** El estado vacío distingue las paradas sin cobertura y conserva el copy neutro cuando no hay trabajo pendiente, con regresión frontend.
8. **H-37-15 (P4) — corregido el 2026-11-23.** El contador alterna singular y plural y la plantilla usa el valor calculado, con regresión frontend.
9. **H-37-16 (P4) — cerrado como externo documentado el 2026-11-23.** La superposición pertenece al lanzador fijo del asistente; no se toca código del M37 ni del widget. El workaround es hacer scroll; eliminarla exige una decisión de producto sobre el asistente.

---

## 7. Nota de mantenimiento

Este acta se actualizará cuando cada hallazgo se corrija: el estado pasará de **Abierto** a **Cerrado (fecha)** y se añadirá la evidencia focalizada que lo demuestre, incluida la suite o la comprobación manual reproducible. No se declarará cerrado un hallazgo P2 mientras no exista una prueba de regresión que reproduzca el caso original y pase en verde.

**Estado del árbol:** rama `feat/restock-route-planner`; la corrida original se hizo sobre `cefed9e` sin cambios de código. H-37-08 se corrigió en `f59d13c` (vista móvil y suite frontend). H-37-09 añade cambios en `RestockRouteScheduleService.php`, `RestockRoutePlannerApiTest.php` y este acta, en su propio commit. H-37-10 añade cambios en `RestockerRouteMapModal.js`, `restocker_route_planner_view.test.mjs` y este acta, en su propio commit. H-37-11 añade cambios en `ModalDialog.js`, `UIComponentsTest.mjs` y este acta, en su propio commit. H-37-12 añade cambios en `PdoRestockRouteReadModel.php`, `RestockRoutePlannerService.php`, `CoordinatorRestockRoutePlannerSubTab.js`, sus suites API/frontend/gateway y este acta, en su propio commit. H-37-13 añade cambios en `CoordinatorRestockRoutePlannerSubTab.js`, `coordinator_restock_route_planner.test.mjs` y este acta, en su propio commit. H-37-14 añade cambios en `CoordinatorRestockRoutePlannerSubTab.js`, `coordinator_restock_route_planner.test.mjs` y este acta, en su propio commit. H-37-15 añade cambios en `CoordinatorRestockRoutePlannerSubTab.js`, `coordinator_restock_route_planner.test.mjs` y este acta, en su propio commit. H-37-16 no modifica código: se cierra como externo documentado en este acta; sin push.
