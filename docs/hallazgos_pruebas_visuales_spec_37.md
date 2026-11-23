# Hallazgos · Pruebas visuales y de UI de la Spec 37

- **Módulo:** 37 · Planificador de Rutas Periódicas de Carga y Priorización Dinámica por Demanda (`37-restock-route-planner`)
- **Especificación:** [`specs/37-restock-route-planner/spec.md`](../specs/37-restock-route-planner/spec.md)
- **Contratos:** [`specs/technical/restock_route_planner_contracts.md`](../specs/technical/restock_route_planner_contracts.md)
- **Sistema de diseño:** [`docs/design.md`](design.md)
- **Fecha de la corrida:** 2026-11-23
- **Rama:** `feat/restock-route-planner`
- **HEAD de la corrida:** `cefed9e`
- **Árbol:** la corrida original no modificó código. Las correcciones posteriores de H-37-08 y H-37-09 tocan la vista móvil, el servicio de agendas, sus suites y este acta; se registran en commits separados.
- **Entorno:** app local `http://127.0.0.1:8000` contra el banco `vendguard_battery` (PostgreSQL `127.0.0.1:54331`), con las credenciales semilla del entorno de batería.
- **Alcance:** recorrido manual en Chromium como Coordinación y Reponedor, con clics y teclado reales, a `1280 × 932 px` y `360 × 800 px`. Se contrastaron red, DOM, respuestas API, PostgreSQL e IndexedDB.
- **Naturaleza:** acta de verificación visual y de UI sobre la aplicación ensamblada. **No sustituye** a [`hallazgos_pruebas_funcionales_spec_37.md`](hallazgos_pruebas_funcionales_spec_37.md), que recoge la corrida funcional y los cierres `H-37-01`–`H-37-07`, ni a [`auditoria_spec_37_brechas.md`](auditoria_spec_37_brechas.md), que certifica las brechas `AUD-37-01`–`AUD-37-09` con suites automatizadas.

> Los identificadores `H-37-08`–`H-37-16` son nuevos para no reutilizar los `H-37-01`–`H-37-07` del acta funcional. Siete hallazgos siguen **abiertos**; H-37-08 y H-37-09 quedaron corregidos el 2026-11-23 con regresiones automatizadas.

---

## 1. Resumen ejecutivo

No se detectó ningún fallo **P1** (bloqueo sin workaround ni pérdida de datos). Los dos fallos P2 afectaban al flujo principal del reponedor y a la consulta de agendas de Coordinación; **ambos están corregidos**. El resto son deuda de accesibilidad, presentación o copy.

| Orden | ID | Prioridad | Hallazgo | Requisito afectado | Estado |
|---:|---|---|---|---|---|
| 1 | **H-37-08** | **P2 · Media** | La ruta móvil no se recarga al iniciar o cerrar turno | `RF-05.2`, `RF-03` | **Cerrado (2026-11-23)** |
| 2 | **H-37-09** | **P2 · Media** | El filtro de agendas ignora la búsqueda en máquinas sin agenda | `RF-01.3`, `RF-05.3` | **Cerrado (2026-11-23)** |
| 3 | **H-37-10** | **P3 · Baja-media** | El cierre del mapa tiene contraste 1,72:1 y el modal no recibe foco ni cierra con Escape | `RNF-04`, `RF-05.2` | **Abierto** |
| 4 | **H-37-11** | **P3 · Baja-media** | El modal de «Nueva agenda» no atrapa el foco | `RNF-04` | **Abierto** |
| 5 | **H-37-12** | **P3 · Baja** | La tabla de excepciones muestra el ID interno (`#10`) en vez del código de máquina | `RF-05.3`, Art. V.4 | **Abierto** |
| 6 | **H-37-13** | **P3 · Baja** | A 360 px las etiquetas del resumen de ruta se salen de su celda | `RNF-04`, `RF-05.3` | **Abierto** |
| 7 | **H-37-14** | **P3 · Baja** | El estado vacío de rutas dice «No hay paradas pendientes» aunque haya paradas sin cobertura | `RF-03.3`, `RF-05.3` | **Abierto** |
| 8 | **H-37-15** | **P4 · Cosmético** | El contador de Alta masiva dice «1 seleccionadas» | `RNF-04` | **Abierto** |
| 9 | **H-37-16** | **P4 · Externo** | El widget flotante de n8n tapa acciones en la esquina inferior derecha | Transversal; no es código del M37 | **Abierto** |

**Lectura de prioridades:** P2 deja un requisito funcional sin cumplir o produce un dato operativo incorrecto, con workaround; P3 es deuda de accesibilidad, presentación o semántica sin bloqueo; P4 es cosmético o ajeno al módulo.

---

## 2. Metodología

1. Se comprobó `/api/health` → **200** y se inició sesión con Coordinación y con Lucía (`REP-01`).
2. Se recorrió «🚚 Órdenes de Carga y Picking de Frío» → «🗺️ Ruta del día y Agendas»: KPIs, sin cobertura, rutas por reponedor, excepciones, agendas, fecha, alta/versionado/baja, alta masiva y desvío/revocación.
3. Se recorrió la superficie móvil de Lucía: inicio de turno, ruta por bloques, mapa, modal de omisión, validación de motivo, aviso FEFO y snapshot de IndexedDB.
4. Cada paso se contrastó con la petición de red, el DOM renderizado, la respuesta API, PostgreSQL y el log del servidor.
5. Se probaron los tamaños `1280 × 932` y `360 × 800`; se midieron `scrollWidth`/`clientWidth` de etiquetas y contenedores para distinguir scroll interno intencional de solapamiento real.
6. La corrida original no corrigió código ni hizo commit. Las correcciones posteriores de H-37-08 y H-37-09 se validaron con sus suites; H-37-08 también se verificó en Chromium. Se revocó la excepción de prueba y se cerró el turno de Lucía al terminar.

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

### H-37-10 · P3 · El cierre del mapa tiene mal contraste y el modal no es plenamente accesible

**Qué ocurre.** En el modal «🗺️ Mapa de mi ruta», el botón «Cerrar» se pinta con `vg-btn-ghost`, cuyo color es `--color-ink-secondary` (`#434c5f`), sobre la cabecera azul `--color-primary` (`#2560ff`). El contraste calculado es **1,72:1**, muy por debajo del mínimo WCAG AA de 4,5:1 para texto normal. El botón se ve gris casi invisible en la captura a 360 px.

Además, el modal no mueve el foco al abrirse y no se cierra con `Escape`; solo responde al botón o al clic en el fondo.

**Causa.** [`RestockerRouteMapModal.js:212`](../public/assets/js/components/RestockerRouteMapModal.js) usa `vg-btn-ghost` en vez de `vg-btn-ghost-inverse`, que existe precisamente para superficies oscuras ([`design-tokens.css:422`](../public/assets/css/design-tokens.css)). `openRouteMap()` en [`RestockerRouteView.js:923`](../public/assets/js/components/RestockerRouteView.js) solo cambia `showRouteMapModal`; no hay gestión de foco ni listener de `Escape` para este modal.

**Impacto.** Para baja visión el cierre es difícil de localizar. Para teclado, el modal no recibe el foco y no se puede cerrar con `Escape`, lo que obliga a tabular por una superficie cuyo orden no está controlado.

**Reproducción.**

1. Entrar como Lucía con ruta visible.
2. Abrir «🗺️ Mapa».
3. Inspeccionar el color de «Cerrar» o calcular el contraste con los tokens indicados.
4. Pulsar `Escape`: el modal permanece abierto.

**Estado:** Abierto (2026-11-23).

---

### H-37-11 · P3 · El modal de «Nueva agenda» no atrapa el foco

**Qué ocurre.** Al abrir «＋ Nueva agenda», el foco se queda en el botón que abrió el modal, detrás del overlay. Pulsando `Tab`, el foco pasa a «⚡ Alta masiva», también detrás del diálogo, en lugar de entrar en el formulario. `Escape` sí cierra el modal.

**Causa.** El modal se construye con [`ModalDialog.js`](../public/assets/js/components/ModalDialog.js), que declara `role="dialog"` y `aria-modal="true"`, pero no mueve el foco al abrir ni implementa un focus trap. El problema afecta también a los modales de alta masiva y de excepción que usan el mismo componente.

**Impacto.** Un usuario de teclado o lector de pantalla puede interactuar con la página de fondo sin darse cuenta. Es una deuda de accesibilidad del componente compartido, no del formulario concreto.

**Reproducción.**

1. Abrir «＋ Nueva agenda» con Coordinación.
2. Comprobar que `document.activeElement` sigue siendo el botón «＋ Nueva agenda».
3. Pulsar `Tab`: el foco pasa a «⚡ Alta masiva», fuera del diálogo.
4. Pulsar `Escape`: el modal se cierra.

**Estado:** Abierto (2026-11-23).

---

### H-37-12 · P3 · La tabla de excepciones muestra el ID interno de la máquina

**Qué ocurre.** Se creó un desvío FEFO de `VEND-0701` a Lucía desde Coordinación. La sección «Excepciones vivas del día» mostró una fila con la celda **`#10`**, no `VEND-0701`. La API devolvió `machine_id: 10` y el código de máquina solo estaba disponible por otra vía.

**Causa.** [`CoordinatorRestockRoutePlannerSubTab.js:831`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js) pinta `<td>#{{ override.machine_id }}</td>` directamente. Es una deuda residual de `H-37-04`, que sustituyó los IDs internos por selectores en los formularios pero no en esta tabla.

**Impacto.** El coordinador no puede identificar la máquina afectada sin conocer la correspondencia interna. El dato es correcto, pero la presentación incumple el criterio de opacidad de identificadores internos del Art. V.4.

**Reproducción.**

1. Crear un desvío de `VEND-0701` a una reponedora desde «Rutas por reponedor» o desde «Paradas sin cobertura».
2. Revisar «Excepciones vivas del día».
3. Observar `#10` en la columna «Máquina».
4. Revocar la excepción: la fila desaparece y la ruta vuelve a su estado anterior.

**Estado:** Abierto (2026-11-23). La excepción de prueba se revocó; no queda ninguna activa.

---

### H-37-13 · P3 · A 360 px las etiquetas del resumen de ruta se salen de su celda

**Qué ocurre.** En la tarjeta de ruta de «Rutas por reponedor», a `360 × 800 px`, las etiquetas del resumen se salen de su columna. «Completadas» ocupa `77 px` en una celda de `64 px` y «Bloqueadas» `68 px` en `64 px`; visualmente se lee **«CompletadasOmitidas»**. La página no genera scroll horizontal; el desbordamiento es del texto dentro de la cuadrícula.

**Causa.** Las etiquetas se pintan en [`CoordinatorRestockRoutePlannerSubTab.js:802-805`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js) dentro de una cuadrícula de cuatro columnas iguales, sin `overflow`, `min-width` o tamaño de fuente adaptativo para el ancho móvil.

**Impacto.** El resumen sigue siendo legible con esfuerzo, pero pierde separación entre métricas y aparenta un texto corrupto. Incumple la expectativa de legibilidad y acabado a 360 px de `RNF-04`.

**Reproducción.**

1. Abrir el planificador a `360 × 800 px` con una ruta de reponedor visible.
2. Localizar el resumen «Completadas / Omitidas / Bloqueadas / FEFO».
3. Medir `scrollWidth` y `clientWidth` de las etiquetas: `77 > 64` y `68 > 64`.
4. Observar la unión visual de «Completadas» y «Omitidas».

**Estado:** Abierto (2026-11-23).

---

### H-37-14 · P3 · El estado vacío de rutas es engañoso cuando hay paradas sin cobertura

**Qué ocurre.** Con los dos reponedores fuera de turno, la sección «Rutas por reponedor» mostraba «No hay paradas pendientes para la fecha seleccionada», mientras el KPI «Sin cobertura» y la bandeja prioritaria listaban **9 paradas**. El mensaje sugiere que no queda trabajo, cuando en realidad no hay ningún reponedor en turno para cubrirlo.

**Causa.** El texto de [`CoordinatorRestockRoutePlannerSubTab.js:760`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js) se muestra siempre que no hay rutas de reponedor, sin distinguir entre «ningún reponedor en turno», «todos los reponedores sin paradas» y «paradas sin cobertura».

**Impacto.** El coordinador puede interpretar que el día está cubierto y dejar sin atender las paradas de la bandeja. No hay pérdida de datos ni error de cálculo; es un problema de semántica del estado vacío.

**Reproducción.**

1. Asegurar que Lucía y Óscar están fuera de turno.
2. Abrir el planificador de hoy.
3. Comparar «Rutas por reponedor» con el KPI y la tabla de «Paradas sin cobertura».
4. Observar el mensaje «No hay paradas pendientes» junto a 9 paradas sin cobertura.

**Estado:** Abierto (2026-11-23).

---

### H-37-15 · P4 · El contador de Alta masiva dice «1 seleccionadas»

**Qué ocurre.** En el modal «⚡ Alta masiva», al marcar una sola máquina el texto queda «Máquinas (opcional · **1 seleccionadas**)». La concordancia debe ser «1 seleccionada».

**Causa.** [`CoordinatorRestockRoutePlannerSubTab.js:986`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js) usa siempre el plural `seleccionadas`, sin alternar según `bulkForm.machine_ids.length`.

**Impacto.** Cosmético; no altera la selección ni el payload.

**Reproducción.**

1. Abrir «⚡ Alta masiva».
2. Marcar una máquina.
3. Leer el contador sobre la lista.

**Estado:** Abierto (2026-11-23).

---

### H-37-16 · P4 · El widget flotante de n8n tapa acciones en la esquina inferior derecha

**Qué ocurre.** A `1280 × 932 px`, el botón «Desviar» de la fila visible inferior quedó debajo del SVG del chat flotante de n8n. El clic real no abría el modal y `document.elementFromPoint()` devolvía el `path` del widget. Al hacer scroll y dejar el botón libre, el modal abrió correctamente.

**Causa.** El widget externo del chat se superpone a la esquina inferior derecha con mayor `z-index` que la tabla. No es código del M37 ni del repositorio; se registra porque afecta a la operativa de la pantalla.

**Impacto.** Una acción puede quedar inaccesible si coincide con la esquina del chat. El workaround es hacer scroll; no se pierde ningún dato.

**Reproducción.**

1. A `1280 × 932 px`, abrir el planificador y desplazarse hasta que un botón «Desviar» quede en la esquina inferior derecha.
2. Comprobar con `elementFromPoint` que el punto devuelve el SVG del chat.
3. Hacer scroll para liberar el botón y comprobar que el clic abre el modal.

**Estado:** Abierto (2026-11-23). Fallo transversal, fuera del alcance de corrección del M37.

---

## 4. Comprobaciones que no son hallazgo

Estas comprobaciones pasaron en la misma corrida y delimitan el alcance:

| Comprobación | Resultado |
|---|---|
| Cambio de fecha del tablero y aviso de proyección futura | Correcto |
| Alta, versionado y baja lógica de una agenda semanal | Correcto |
| Modal de alta masiva, selector de zonas/máquinas y contador | Correcto salvo el copy de H-37-15 |
| Validación de omisión sin causa o con motivo corto | Correcto |
| Modal de omisión: `role="dialog"`, `aria-modal`, foco inicial, `Escape` y retorno de foco | Correcto |
| Aviso FEFO real: `role="alert"`, caducidad y máquinas afectadas | Correcto |
| Desvío y revocación desde Coordinación | Correcto |
| Mapa: paradas, máquinas, km, leyenda y enlace a Google Maps | Correcto |
| Mapa a 360 px: cabe con scroll interno y sin desbordamiento de página | Correcto; el defecto es el contraste de H-37-10 |
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
3. **H-37-10 y H-37-11 (P3):** usar `vg-btn-ghost-inverse` y dotar a los modales de foco inicial, focus trap y `Escape`; el mapa y `ModalDialog` comparten la deuda.
4. **H-37-12, H-37-13 y H-37-14 (P3):** publicar `machine_code` en la excepción, adaptar el resumen a 360 px y distinguir los estados vacíos de cobertura.
5. **H-37-15 (P4):** alternar singular/plural en el contador.
6. **H-37-16 (P4):** no requiere cambio en el M37; valorar reducir el `z-index` o el área de impacto del widget de n8n.

---

## 7. Nota de mantenimiento

Este acta se actualizará cuando cada hallazgo se corrija: el estado pasará de **Abierto** a **Cerrado (fecha)** y se añadirá la evidencia focalizada que lo demuestre, incluida la suite o la comprobación manual reproducible. No se declarará cerrado un hallazgo P2 mientras no exista una prueba de regresión que reproduzca el caso original y pase en verde.

**Estado del árbol:** rama `feat/restock-route-planner`; la corrida original se hizo sobre `cefed9e` sin cambios de código. H-37-08 se corrigió en `f59d13c` (vista móvil y suite frontend). H-37-09 añade cambios en `RestockRouteScheduleService.php`, `RestockRoutePlannerApiTest.php` y este acta, en su propio commit; sin push.
