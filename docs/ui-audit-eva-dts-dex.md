# Hallazgos · Auditoría visual y de UI EVA-DTS (Módulo 39)

- **Módulo:** 39 · Auditoría Local mediante Importación de Ficheros Estándar EVA-DTS (`.dex`) en Ruta.
- **Rama:** `feat/eva-dts-dex-audit`.
- **HEAD de la corrida:** `158c578c`.
- **Árbol:** la corrida no modificó código ni confirmó anulaciones ni importaciones.
- **Entorno:** app local `http://127.0.0.1:8000`; servidor PHP preexistente (PID 26148) sin detener; consola Chrome con los roles Semilla de Batería.
- **Fecha de la corrida:** 2026-11-26.
- **Alcance:** recorrido manual como cuatro roles (Sede, Coordinación, Técnico, Reponedor) en desktop `1280 × 900 px` y móvil `360 × 800 px`, con clics, teclado real, inspección de red, DOM, consola y capturas.
- **Naturaleza:** acta de verificación visual y de UI sobre la aplicación ensamblada en rama de racha. **No sustituye** a la trazabilidad funcional ni a las suites de automatización ya existentes; cierra el hueco documental residuo de `AUD-39-05` sobre la experiencia en navegador en la rama actual.

> Los identificadores que siguen (H-39-01 a H-39-07) son nuevos para esta cadena; no coinciden con IDs ajenos a esta azul. No se hizo commit ni push en esta corrida; el árbol quedó limpio.

---

## 1. Resumen ejecutivo

No se detectó ningún fallo de bloqueo (`P1`) en la UI del Módulo 39. El flujo principal respondió bien: carga de vigentes, filtro de alertas, modal de detalle, apertura/cierre de modales, anulación sin confirmar, importación manual con zona vacía, CSV y descarga `.dex` devolvieron `200`; las APIs de producto fueron limpias y la consola del producto no mostró errores. Se detectaron **7 defectos de UI**, sin Pérdida de datos ni cambio de estado presentado:

- **Dos de ellos importaban** (prioridad Alta): un delta desconocido se pintaba como `0` en el historial móvil (H-39-01, **cerrado el 2026-11-27**) y la tarjeta «Máquina» del detalle de Coordinación quedaba vacía (H-39-02, **cerrado el 2026-11-27**).
- **Un defecto de accesibilidad** dejaba al panel DEX del Reponedor sin comportamiento modal real en teclado (`P3`); **cerrado el 2026-11-27**.
- **El timestamp técnico del historial móvil** (H-39-04) **se cerró el 2026-11-27** con el formateo local compartido.
- **Los plurales con una unidad** (H-39-05) **se cerraron el 2026-11-27** con un helper de concordancia compartido en la consola.
- **El placeholder duplicado de Delta** (H-39-06) **se cerró el 2026-11-27** dejando un solo `—` cuando no hay unidades.
- **El último, cosmético** (H-39-07), **se cerró el 2026-11-27** sustituyendo el control nativo por un disparador legible con el estado del archivo.

**Los siete hallazgos quedan cerrados a 2026-11-27.**

El único fallo de red visto fue el widget de chat de n8n (`net::ERR_FAILED`); se documenta aparte como externo/ambiental.

| Orden | ID | Prioridad | Hallazgo | Estado |
|---:|---|---|---|---|
| 1 | **H-39-01** | **Alta** | Un delta sin base se muestra como `0` en el historial móvil | **Cerrado (2026-11-27)** |
| 2 | **H-39-02** | **Alta** | La tarjeta «Máquina» del detalle de Coordinación está vacía | **Cerrado (2026-11-27)** |
| 3 | **H-39-03** | **P3 · Media** | El panel DEX del Reponedor no es un modal plenamente operable por teclado | **Cerrado (2026-11-27)** |
| 4 | **H-39-04** | **P3 · Media-baja** | Fecha técnica sin localizar en el historial móvil | **Cerrado (2026-11-27)** |
| 5 | **H-39-05** | **P4 · Baja** | Plurales incorrectos con una unidad (`1 anulados`, `1 bloques`) | **Cerrado (2026-11-27)** |
| 6 | **H-39-06** | **P4 · Baja** | Placeholder duplicado `— — uds.` en la columna Delta de la tabla | **Cerrado (2026-11-27)** |
| 7 | **H-39-07** | **P4 · Baja** | Texto del selector de archivo recortado en móvil | **Cerrado (2026-11-27)** |

**Lectura de prioridades:** Alta = dato operativo incorrecto o campo de trazabilidad clave sin valor, con impacto en el juicio de auditoría; `P3` es una deuda de accesibilidad, presentación o semántica sin bloqueo; `P4` es cosmético o de refinamiento.

---

## 2. Metodología

1. Se comprobó `/api/health` y `/` → **200**, y se inició sesión con los cuatro roles semilla (Sede `SEDE-BCN-01` / `DEV-SEDE-BCN-01`; Coordinación `coordinacion@vendguard.internal` / `Password123!`; Técnico `jordi.ruta@vendguard.internal` / `Password123!`; Reponedor `lucia.repostaje@vendguard.internal` / `Password123!`).
2. Se recorrió la superficie principal de cada rol y la consola DEX de Coordinación: KPIs, filtro «Solo con alertas», carga manual en escritorio, detalle, descarga `.dex`, exportación CSV y modal de anulación (sin confirmar).
3. Se recorrió la UI móvil del Reponedor y del Técnico: historial DEX, selector de archivo, sin confirmar importación ni anulación.
4. Se recorrió el detalle móvil de Coordinación a `360 px` (apertura y lectura del modal de detalle).
5. Cada paso se contrastó con la petición de red, el DOM renderizado, la respuesta API, la consola del servidor y las capturas.
6. Se probaron `1280 × 900` y `360 × 800`; se midieron `scrollWidth`/`clientWidth` de contenedores, cajas de diálogo y controles para distinguir scroll interno intencional de solapamiento real y de desbordamiento de página.
7. Se inspeccionó el foco activo (`document.activeElement`) y la presencia de `role`/`aria-modal` sobre los diálogos móviles.
8. La corrida no corrigió código ni confirmó importaciones o anulaciones. No se debió ningún cambio al árbol de trabajo activo.

**Datos/identificadores utilizados:** `VEND-0101`, `VEND-0102`, `VEND-0401`, `VEND-0501`, `VEND-0502`; vigente `DEX-2026-0001`; equivalencia raíz `COORDINATOR` ↔ `findForRole($auditId, 'COORDINATOR')`.

---

## 3. Hallazgos detallados

### H-39-01 · Alta · Un delta sin base se muestra como `0` en el historial móvil

**Qué ocurría.** En el historial `.dex` del Reponedor y del Técnico, el primer volcado sin predecesor (`INITIAL_BASELINE`) se pintaba como `Δ 0 u`. El mismo volcado, visto en el detalle de Coordinación, muestra `Delta unidades: —` y `Fuente: INITIAL_BASELINE`. Es un caso de dato incorrecto presentado en pantalla, no solo estético: un operador podría interpretar que no hay diferencia cuando en realidad no hay base de comparación.

**Causa.** Los dos helpers móviles que pintan el delta, `dexAuditDeltaUnits()` (Reponedor y Técnico), hacen `Number(audit?.delta_vend_units)` y, si no es finito, devuelven `0`. Para el caso de base inicial la respuesta lleva `delta_vend_units` sin valor/NULL y `delta_source = 'INITIAL_BASELINE'`; el detalle de Coordinación si conserva el `NULL` y lo muestra como `—`. El Historial no conserva esa distinción.

**Impacto.** A data falsa leve en un KPI de auditoría (el operador lee cero variación en lugar de «sin base»). No hay pérdida de datos ni persistencia incorrecta; el cálculo del backend es correcto (`delta_source = 'INITIAL_BASELINE'`), solo la proyección móvil no diferencia el `NULL` del valor `0`.

**Reproducción.**

1. Entrar como Reponedor o Técnico con una máquina que tenga al menos un volcado `ACTIVE` y ver el historial (móvil).
2. Localizar el primer volcado (`INITIAL_BASELINE`).
3. Leer la línea: aparece `Δ 0 u`.
4. Abrir el detalle de Coordinación del mismo volcado: `Delta unidades: —`, `Fuente: INITIAL_BASELINE`.

**Evidencia.** `VEND-0101` en ruta de Lucía; historial móvil mostró `VA1 118 u · Δ 0 u · —`. Detalle de Coordinación de `DEX-2026-0001` mostró `Delta unidades: —` y `Fuente del delta: INITIAL_BASELINE`. Respuesta API (`GET /api/coordinator/dex-audits/6376 → 200`) acorde con la proyección de coordinador. `public/assets/js/components/RestockerRouteView.js` y `TechnicianRouteView.js` (`dexAuditDeltaUnits()`) devuelven `0` para el `NULL`. La misma función `dexAuditUnits()` del volcado sí pinta `118`.

**Estado:** **Cerrado el 2026-11-27.** `dexAuditDeltaUnits()` (Reponedor y Técnico) ya no convierte el hueco en cero: devuelve `null` cuando `delta_vend_units` es `null`, `undefined` o cadena vacía (antes `Number(null) === 0` se colaba como valor finito), y la nueva etiqueta `dexAuditDeltaLabel()` publica `—` en el historial móvil. Un delta real sigue pintándose como `4 u`. **Evidencia:** `node tests/frontend/technician_route_view.test.mjs` → **21/21**, `exit=0`; `node tests/frontend/restocker_route_view.test.mjs` → **263/263**, `exit=0`; `node tests/unit/FrontendTemplateCompileTest.mjs` → **83 plantillas / 5.729 expresiones / 0 fallos**, `exit=0`; `node --check` de ambas vistas → sintaxis válida. Regresión añadida en ambas suites: un volcado con `delta_vend_units: null` y `delta_source: 'INITIAL_BASELINE'` se etiqueta `—` y no `0`. Prioridad Alta.

**Nota de prioridad.** No es un fallo de cálculo; el riesgo es de interpretación en el operador móvil, pero la columna «Delta» de un volcado base es un dato que tiene sentido opaco (debe ser `—` o «sin base»), no cero. Por eso se carga en Alta en esta cadena.

---

### H-39-02 · Alta · La tarjeta «Máquina» del detalle de Coordinación está vacía

**Qué ocurría.** Al abrir el modal «Detalle del volcado EVA-DTS» de Coordinación, la tarjeta de la sección «Máquina» aparece sin valor, aunque la propia tabla de la consola identifica la máquina (`VEND-0101`), el detalle pinta «ID máquina DEX» con el código y el resto de campos del mismo card (`Código`, `Estado`, `Capturado`, etc.) sí se rellenan.

**Causa.** El endpoint de detalle de Coordinación devuelve la fila con `machine_code_snapshot`, no con `machine_code`. La plantilla que pinta el card espera `detail.machine_code`. En la lista/colapsada la fila usa `machine_code_snapshot`, por lo que el dato existe en el JSON pero no en la clave que consume esa tarjeta. Causa localizada en la proyección del controlador de Coordinación y en cómo la plantilla lee el campo del detalle.

**Impacto.** El coordinador que abre el detalle desde una fila ya sabe qué máquina analizó, pero la tarjeta de identificación de máquina del modal queda sin valor, lo que rompe la legibilidad de una pantalla de auditoría y contradice la misma fila que dio origen al detalle. No hay pérdida de datos; el algoritmo de auditoría es correcto, solo la proyección/plantilla no publica el código en el campo esperado.

**Reproducción.**

1. Entrar como Coordinación.
2. Abrir la consola «Auditorías EVA-DTS (.dex)».
3. Pulsar sobre una fila (por ejemplo `DEX-2026-0001`).
4. Verificar el card: la fila y el detalle muestran `VEND-0101`; la tarjeta «Máquina» del card queda vacía, tanto a `1280 px` como a `360 px`.

**Evidencia.** `GET /api/coordinator/dex-audits/6376 → 200` con `machine_code_snapshot` presente y `machine_code` sin campo equivalente en esa proyección. `CoordinatorDexAuditsSubTab.js` pinta en listado con `machine_code_snapshot`; la plantilla de detalle lee `detail.machine_code`. `src/Application/Service/MachineDexAuditService.php` (proyección `COORDINATOR`) y `CoordinadorDexAuditController.php` (`show()`) acarrean esa diferencia. Capturas: `ui-audit-04-dex-detail-modal.png` (desktop) y `ui-audit-13-dex-detail-360.png` (móvil).

**Estado:** **Cerrado el 2026-11-27.** La proyección de Coordinación devolvía la fila cruda del repositorio, que solo trae `machine_code_snapshot`; la tarjeta lee `detail.machine_code` y quedaba vacía. `MachineDexAuditService::projectByRole()` publica ahora `machine_code` (y `machine_type`) desde esas instantáneas para la proyección `COORDINATOR`, igual que `toCoordinatorArray()`; la plantilla no cambia. **Evidencia:** `php tests/unit/MachineDexAuditServiceTest.php` → **30/30**, `exit=0` (nueva aserción 3.4: `findForRole()` publica `VEND-DEX07`/`COMBO`); `php tests/integration/DexAuditApiTest.php` → **46/46**, `exit=0` (la aserción 3.2 exige `machine_code` no vacío e igual a la instantánea). Comprobación real en Chromium a 1280×900: `DEX-2026-0001` abre el detalle y la tarjeta «Máquina» muestra `VEND-0101` (captura `screenshots/ui-audit-17-dex-detail-machine-1280.png`), sin errores de producto en consola. Prioridad Alta.

**Nota de prioridad.** Es una proyección/plantilla, no un problema de la regla de negocio ni del cálculo. Se carga en Alta porque la pantalla de auditoría clara falla en una celda de título de máquina.

---

### H-39-03 · P3 · El panel DEX del Reponedor no es un modal plenamente operable por teclado

**Qué ocurría.** Al pulsar «📄 Importar .dex» en una máquina del Reponedor, se abre el panel inferior. Con el teclado:

- el foco se queda en el botón de la máquina de fondo (`btn-open-dex-audit`);
- el panel no tiene `role="dialog"` ni `aria-modal`;
- `Escape` no lo cierra (el diálogo permanece abierto y el foco sigue en el botón de la fila);
- el foco no queda atrapado dentro del panel.

En el mismo producto, el modal de Coordinación sí mueve el foco al abrir, tienes `role="dialog"`/`aria-modal` y sí cierra con `Escape`.

**Causa.** Este panel móvil no está construido sobre el componente modal que ya maneja foco, atrapa `Tab`/`Shift+Tab`, cierra con `Escape` y retorna el foco al disparador; es una superposición ad-hoc que representa un flujo de archivo, no un diálogo semántico.

**Impacto.** Un usuario de teclado o lector de pantalla puede interactuar con la página de fondo sin advertencia, y no puede cerrar el panel con la tecla habitual. No hay pérdida de datos ni error de cálculo; es una deuda de accesibilidad de este flujo concreto.

**Reproducción.**

1. Entrar como Reponedor en móvil `360 px`.
2. Pulsar «📄 Importar .dex» de `VEND-0101`.
3. Verificar `document.activeElement` sigue en `btn-open-dex-audit`; inspeccionar que el panel no tiene `role="dialog"` ni `aria-modal`; pulsar `Escape` y verificar que el panel sigue abierto.

**Evidencia.** Evaluación sobre el DOM con `document.querySelector('[data-testid="restocker-dex-modal"]')`: `role` nulo, `aria-modal` nulo, `modalContainsFocus === false`; `Escape` no cerró el panel; el botón de fondo conservó el foco.

**Estado:** **Cerrado el 2026-11-27.** El panel inferior se declara ahora como diálogo semántico (`role="dialog"`, `aria-modal="true"`, `aria-labelledby="restocker-dex-title"`, `tabindex="-1"`, `ref="dexDialog"`) sin cambiar su presentación móvil. Al abrirse guarda el disparador y mueve el foco al botón «Cerrar»; un `keydown` de documento atrapa `Tab`/`Shift+Tab` dentro del panel, cierra con `Escape` y devuelve el foco al botón `btn-open-dex-audit` de origen. **Evidencia:** `node tests/frontend/restocker_route_view.test.mjs` → **267/267**, `exit=0` (4 aserciones nuevas: semántica del tag, foco inicial, `Escape` con retorno de foco y trampa de `Tab`); `node tests/unit/FrontendTemplateCompileTest.mjs` → **83 plantillas / 5.729 expresiones / 0 fallos**, `exit=0`; `node --check` de la vista → sintaxis válida. Comprobación real en Chromium a 360×800: apertura con `Enter` desde el botón (`document.activeElement` = «Cerrar», dentro del diálogo), `Tab` desde el último control vuelve al primero, `Shift+Tab` desde el primero vuelve al último, `Escape` cierra y deja el foco en `btn-open-dex-audit`; consola sin errores de producto. Captura: `screenshots/ui-audit-18-dex-dialog-keyboard-360.png`. Prioridad `P3` (accesibilidad, sin bloqueo).

---

### H-39-04 · P3 · Fecha técnica sin localizar en el historial móvil

**Qué ocurría.** En el historial de auditorías del Reponedor a `360 px`, el timestamp del volcado se muestra en forma técnica: `2026-11-25 13:06:05+00`, con zona horaria. El resto de la aplicación usa valores locales como `25/11/26, 14:06`.

**Causa.** La plantilla del historial móvil renderiza el campo de fecha sin formatear a presentación local, a diferencia del resto de la aplicación y del detalle de Coordinación.

**Impacto.** Inconsistencia con el resto de la app y lectura con zona horaria que obliga al operador a convertir mentalmente la hora. No hay pérdida de datos ni error de cálculo; es de presentación/usabilidad.

**Reproducción.**

1. Abrir el panel DEX del Reponedor en móvil.
2. Localizar la línea de historial del volcado.
3. Leer la fecha: `2026-11-25 13:06:05+00`.

**Evidencia.** Snapshot del historial móvil: `2026-11-25 13:06:05+00`. Detalle de Coordinación de `DEX-2026-0001`: `Capturado: 25/11/26, 14:06`.

**Estado:** **Cerrado el 2026-11-27.** Las plantillas del historial móvil del Reponedor y del Técnico pintan `formatDateTime(audit.captured_at)` con el util compartido `utils/DateTimeFormat.js`, el mismo que usa la consola y el detalle de Coordinación; ya no se publica el `captured_at` crudo con offset. **Evidencia:** `node tests/frontend/restocker_route_view.test.mjs` → **268/268**, `exit=0`; `node tests/frontend/technician_route_view.test.mjs` → **22/22**, `exit=0` (ambas con aserción nueva: la fecha se localiza, no contiene `+00` y un nulo se pinta `—`); `node tests/unit/FrontendTemplateCompileTest.mjs` → **83 plantillas / 5.729 expresiones / 0 fallos**, `exit=0`; `node --check` de ambas vistas → sintaxis válida. Comprobación real en Chromium a 360×640: el historial de `VEND-0101` muestra `25/11/26, 14:06`, igual que el detalle de Coordinación, y la consola queda limpia. Captura: `screenshots/ui-audit-19-dex-history-local-date-360.png`. Prioridad `P3` (consistencia y usabilidad).

---

### H-39-05 · P4 · Plurales incorrectos con una unidad

**Qué ocurría.** En la consola de Coordinación aparece «`1 anulados en el perímetro`», y en el modal de anulación de Coordinación el contador de bloques dice «`1 bloques`» cuando hay un bloque. Debería decir «1 anulado» y «1 bloque».

**Causa.** Copy/pluración literal aplicada sin concordancia numérica, en la KPI de Coordinación y en el contador del modal de anulación. Se observó con 1 unidad en cada caso; no se necesitó confirmar anulación.

**Impacto.** Copy incorrecto; no altera la funcionalidad ni el cálculo. Calcificado en la UI vista en la corrida.

**Reproducción.**

1. Entrar como Coordinación y abrir la consola DEX (mínimo 1 anulado en perímetro): leer el KPI «1 anulados en el perímetro».
2. Abrir el modal de anulación y comprobar el contador de bloques con 1 bloque seleccionado: «1 bloques».

**Evidencia.** Tabla de la consola de Coordinación: `1 anulados en el perímetro`. Modal de anulación con motivo de 20 caracteres y 1 bloque: `1 bloques`. Se comparan con la expectativa de concordancia (`1 anulado`, `1 bloque`).

**Estado:** **Cerrado el 2026-11-27.** `CoordinatorDexAuditsSubTab` gana el helper `pluralUnit(count, singular, plural)` y lo aplica al KPI del perímetro («1 anulado en el perímetro») y al contador del modal de anulación («1 bloque» / «2 bloques»), sin tocar los textos fijos que ya iban en plural («mínimo 3 bloques alfanuméricos»). **Evidencia:** `node tests/frontend/coordinator_dex_audits_tab.test.mjs` → **32/32**, `exit=0` (aserción nueva: singular con `1` —también si llega como cadena—, plural con `2` y ambas llamadas presentes en la plantilla); `node tests/unit/FrontendTemplateCompileTest.mjs` → **83 plantillas / 5.731 expresiones / 0 fallos**, `exit=0`; `node --check` del componente → sintaxis válida. Comprobación real en Chromium a 1280×900: el KPI muestra `1 anulado en el perímetro` y el contador del modal `9 / 500 caracteres · 1 bloque`, pasando a `19 / 500 caracteres · 2 bloques` al escribir dos bloques; consola sin errores de producto. Captura: `screenshots/ui-audit-20-dex-void-one-block.png`. Prioridad `P4` (copy).

---

### H-39-06 · P4 · Placeholder duplicado en la columna Delta de la tabla

**Qué ocurría.** En las tres filas vigentes de la tabla de auditorías de Coordinación, la celda Delta muestra `— — uds.`: dos placeholders superpuestos (el valor vacío y la unidad).

**Causa.** La celda no diferencia un Delta sin valor del caso controlado y pinta dos marcas simultáneas. Visualmente se lee como dos guiones/placeholder pegados con la unidad.

**Impacto.** Cosmético; no altera los datos ni el cálculo del Delta (que ya se maneja bien en el detalle y en la proyección). Afecta solo a la lectura de la tabla.

**Reproducción.**

1. Entrar como Coordinación y abrir la consola DEX.
2. Localizar las tres filas vigentes; observar la columna Delta: `— — uds.` en cada una.

**Evidencia.** Snapshot de la tabla de Coordinación: celdas Delta de las tres filas con `— — uds.` (ej. `DEX-2026-0001`, `DEX-2026-0003`, `DEX-2026-0002`).

**Estado:** **Cerrado el 2026-11-27.** La celda Delta apilaba dos placeholders cuando el volcado no traía deltas: el importe vacío (`—`) y la línea de unidades (`— uds.`), de ahí `— — uds.`. El componente gana `deltaUnits(audit)`, que resuelve el delta efectivo con su respaldo y devuelve `null` si no hay valor finito; la línea de unidades solo se renderiza con `v-if="deltaUnits(audit) !== null"`, quedando un único `—` en la celda. Los volcados con unidades conservan `X uds.`. **Evidencia:** `node tests/frontend/coordinator_dex_audits_tab.test.mjs` → **33/33**, `exit=0` (aserción nueva: `null` sin valor —también con cadena vacía—, respaldo al delta persistido y plantilla sin la expresión antigua); `node tests/unit/FrontendTemplateCompileTest.mjs` → **83 plantillas / 5.731 expresiones / 0 fallos**, `exit=0`; `node --check` del componente → sintaxis válida. Comprobación real en Chromium a 1280×900: las cuatro filas del libro muestran `—` en Delta, sin ninguna celda con `— — uds.`; consola sin errores de producto. Captura: `screenshots/ui-audit-21-dex-delta-single-placeholder-table.png`. Prioridad `P4` (presentación).

---

### H-39-07 · P4 · Texto del selector de archivo recortado en móvil

**Qué ocurría.** A `360 px`, el control nativo del selector muestra un texto truncado de la forma `Ningún ar…ccionado` en lugar del mensaje completo «Ningún archivo seleccionado». El control sigue siendo usable; no genera scroll horizontal de página ni overflow del documento.

**Causa.** El control nativo tiene un ancho prefijado que no cabe el texto completo en el ancho útil del panel a `360 px`.

**Impacto.** Refino de presentación en móvil; funcionalidad intacta. No genera problemas de desbordamiento de documento ni impedimento para seleccionar archivo.

**Reproducción.**

1. Abrir el panel DEX del Reponedor en móvil sin archivo seleccionado.
2. Verificar el label del selector: texto truncado `Ningún ar…ccionado`.

**Evidencia.** Snapshot móvil del panel: `document.documentElement.scrollWidth = 343`, viewport `360`; el control muestra `Ningún ar…ccionado`. No se midió overflow (el documento no desborda).

**Estado:** **Cerrado el 2026-11-27.** El control nativo se sustituye por el patrón de disparador del sistema de diseño: el `input[type=file]` queda `visually-hidden` (sigue siendo el control real y accesible, con su `label for`) y un `<label>` con clase `vg-btn` («Seleccionar archivo .dex») abre el selector; debajo se anuncia el estado completo («Ningún archivo seleccionado» o «📄 nombre.dex»). La regla `.vg-file-field:focus-within .vg-file-trigger` dibuja el anillo de foco sobre el disparador. Se aplicó el mismo patrón a los selectores .dex del Reponedor, del Técnico y de la consola de Coordinación. **Evidencia:** `node tests/frontend/coordinator_dex_audits_tab.test.mjs` → **34/34**, `node tests/frontend/restocker_route_view.test.mjs` → **269/269** y `node tests/frontend/technician_route_view.test.mjs` → **23/23**, los tres `exit=0` y con aserción nueva sobre disparador, `for`, estado vacío y control oculto; `node tests/unit/FrontendTemplateCompileTest.mjs` → **83 plantillas / 5.730 expresiones / 0 fallos**, `exit=0`; `node --check` de las tres vistas → sintaxis válida. Comprobación real en Chromium a 360×800: el disparador mide 190,4 px con `scrollWidth - clientWidth = 0`, el estado «Ningún archivo seleccionado» mide 311 px sin recorte, el `input` oculto mide 1×1, su `label` apunta a su `id`, el foco pinta un anillo sólido de 2 px en primario y el documento sigue sin desbordar (`scrollWidth = 343`). Al simular la selección de `volcado.dex`, el estado pasa a «📄 volcado.dex» y se calcula la vista previa sin error ni importación. Consola sin errores de producto. Captura: `screenshots/ui-audit-22-dex-file-picker-360.png`. Prioridad `P4` (cosmético).

---

## 4. Externo / ambiental (no imputable al Módulo 39)

- **Widget de chat de n8n:** fallo de red repetido en consola (`net::ERR_FAILED`, `TypeError: Failed to fetch` desde `cdn.jsdelivr.net` y `POST https://rugsoft.app.n8n.cloud/.../chat` → `net::ERR_FAILED`).
- **Solapamiento:** a `360 px` la burbuja flotante tapa el botón de cierre/acción del detalle en la esquina inferior derecha. No es código del M39; el repositorio ya aplica la mitigación de puntero del contenedor, pero la burbuja de n8n mantiene clicables su lanzador y panel. El action impactado sigue siendo alcanzable con scroll; no hay pérdida de datos.
- **Decisión en esta cadena:** documentar como externo, sin modificar ni el M39 ni la integración de n8n; se recomienda resolver desde la petición de integración/ambiental correspondiente cuando el dueño del widget decida mover, ocultar o retirarlo.

---

## 5. Comprobaciones que no son hallazgo

| Comprobación | Resultado |
|---|---|
| `/api/health` y `/` | `200` |
| Accesos por rol (Sede, Coordinación, Técnico, Reponedor) | Login y render sin errores de producto |
| Consola DEX: carga, KPIs, filtro «Solo con alertas» | Funciona; petición `status=ACTIVE&alerts_only=1` → `200`; KPIs y tabla se reducen a la fila esperada |
| Detalle de Coordinación (desktop y móvil) | Abre, pinta datos, cruces M34, líneas y eventos; cierra con `Esc` y botón |
| Modal de anulación (validación sin confirmar) | Motivo < 20 chars y 1 bloque → botón deshabilitado; motivo ≥ 20 chars y ≥ 3 bloques → habilitado; sin confirmar, sin mutación |
| Carga manual con máquina vacía | Aparece la advertencia de selección de máquina |
| Exportación CSV y descarga `.dex` | `200` (CSV `/api/coordinator/dex-audits/export-csv?status=ACTIVE`; raw `/api/coordinator/dex-audits/6376/raw`) |
| API de producto DEX | `200` en los endpoints usados; consola del producto sin errores |
| Sin confirmar importación ni anulación | No se provocó ningún cambio de estado real en esta corrida |
| Responsive sin desbordamiento de página | `document.documentElement.scrollWidth ≤ innerWidth` en móvil (360 px); tabla DEX contenida en su wrapper con scroll interno |

---

## 6. No verificado

- Confirmación real de una importación o anulación desde la UI (no se ejecutó para no mutar datos).
- Recorrido exhaustivo de cada control del app (se probó el flujo principal de la superficie DEX y las acciones relevantes).
- Lectores de pantalla reales (solo inspección de DOM/foco/semántica).
- Dispositivo móvil físico (solo viewports emulados en el navegador del panel).
- Otros navegadores (solo Chromium del panel de vista previa).
- Persistencia Offline PWA con pérdida real de conectividad (la cola existe; no se simulará).

---

## 7. Orden de corrección propuesto

1. ~~**H-39-01 (Alta):** Que `dexAuditDeltaUnits()` (Reponedor y Técnico) preserve el caso de base inicial (`delta_source = 'INITIAL_BASELINE'` y/o `delta_vend_units` nulo) y pinte `—` en lugar de `0`.~~ **Hecho el 2026-11-27** (ver §3).
2. ~~**H-39-02 (Alta):** Que la proyección del detalle de Coordinación publique `machine_code` (o que la plantilla lea `machine_code_snapshot`) de modo que la tarjeta «Máquina» se rellene. Regresión de UI que compruebe el card con `VEND-0101`.~~ **Hecho el 2026-11-27** (ver §3).
3. ~~**H-39-03 (P3):** Convertir el panel DEX del Reponedor en un diálogo semántico con foco inicial, atrapa de `Tab`/`Shift+Tab`, cierre con `Escape` y retorno de foco al disparador, o consolidarlo con el componente modal que ya lo hace en Coordinación. Regresión de accesibilidad.~~ **Hecho el 2026-11-27** (ver §3).
4. ~~**H-39-04 (P3):** Formatear la fecha del historial móvil con la misma presentación local usada en el resto de la app (`25/11/26, 14:06`), sin zona horaria expuesta.~~ **Hecho el 2026-11-27** (ver §3).
5. ~~**H-39-05 (P4):** Concordancia numérica del copy en el KPI de Coordinación y en el contador de bloques del modal de anulación (`1 anulado`, `1 bloque`).~~ **Hecho el 2026-11-27** (ver §3).
6. ~~**H-39-06 (P4):** Unificar el render de la celda Delta para valores nulos, evitando el placeholder duplicado `— — uds.`.~~ **Hecho el 2026-11-27** (ver §3).
7. ~~**H-39-07 (P4):** Ajuste de presentación del selector de archivo nativo a `360 px` para que el texto «Ningún archivo seleccionado» se lea completo sin truncar.~~ **Hecho el 2026-11-27** (ver §3).

Externo: resolver por canal de integración/ambiental la inconsistencia del widget de n8n (solapamiento y errores de red), sin modificar el M39.

---

## 8. Entorno y alcance de la corrida

| Atributo | Valor |
|---|---|
| Proyecto | VendGuard (Gestor de Incidencias de Vending) |
| Módulo | 39 · Auditoría Local mediante Importación de Ficheros Estándar EVA-DTS (`.dex`) |
| Rama | `feat/eva-dts-dex-audit` |
| HEAD | `158c578c` |
| Corrida | 2026-11-26, sin commit ni push |
| Entorno | app local `http://127.0.0.1:8000`; servidor PHP preexistente sin detener |
| Viewports | `1280 × 900` (desktop) y `360 × 800` (móvil) |
| Roles | Sede, Coordinación, Técnico, Reponedor |
| Instrumentos | Chromium del panel, logs de red/DOM/consola, medidas `scrollWidth/clientWidth`, inspección de foco y `role`/`aria-modal` |
| Documentos de referencia | `specs/39-eva-dts-dex-audit/spec.md`, `docs/design.md` |

---

## 9. Relación con la documentación existente

- Cierra el hueco residuo de `AUD-39-05` sobre la experiencia en navegador en la rama de racha.
- Complementa la acta funcional/especificación del M39 (`specs/39-eva-dts-dex-audit/`) y los hallazgos visuales previos del proyecto en `docs/`, pero no los reemplaza.
- No se reutilizan IDs de otras especificaciones ni otras ramas; los `H-39-01`..`H-39-07` son propios de esta cadena.

---

## 10. Nota de mantenimiento

Este acta se actualizará cuando cada hallazgo se corrija: el estado pasará de **Pendiente** a **Cerrado (fecha)** y se añadirá la evidencia focalizada que lo demuestre (suite de regresión o comprobación manual reproducible). No se declarará cerrado un hallazgo de prioridad Alta mientras no exista una prueba de regresión que reproduzca el caso original y pase en verde.

**Estado del árbol:** `feat/eva-dts-dex-audit` al `158c578c`; la corrida no modificó código ni confirmó cambios de estado, y no quedó ningún archivo de código alterado por este documento. Este `docs/ui-audit-eva-dts-dex.md` queda como acta de la auditoría en navegador y se incorpora al historial de la rama.

**Cierres registrados:** H-39-01 a H-39-07 cerrados el 2026-11-27 (ver §3 para la corrección y su evidencia). No quedan hallazgos pendientes en esta acta.
