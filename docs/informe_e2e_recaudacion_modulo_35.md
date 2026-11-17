# Informe · Pruebas E2E de navegador del Módulo 35 (Recaudación, Cambio y Arqueo)

- **Módulo:** 35 · Recaudación de Efectivo, Carga de Tubos de Cambio y Arqueo de Caja en Cámara
- **Especificación de referencia:** [`specs/35-cash-collection-vault-audit/spec.md`](../specs/35-cash-collection-vault-audit/spec.md) (RF-04, RF-05, `D2`–`D4`, `C2`–`C4`, `QA 1`–`QA 4`)
- **Contratos:** [`specs/technical/cash_collection_vault_audit_contracts.md`](../specs/technical/cash_collection_vault_audit_contracts.md)
- **Fecha de la corrida:** 2026-11-17 · **Rama:** `feat/cash-collection-vault-audit` · **HEAD:** `ba4c097`
- **Alcance:** recorrido manual en navegador real (Chromium del panel) sobre el servidor de desarrollo con el contrato del banco de la batería, más sondas HTTP directas para las invariantes que la interfaz no expone.
- **Estado:** H-1, H-2, H-3 y H-6 corregidos el 2026-11-17 con sus guardas de regresión (ver la nota de cada hallazgo). Quedan abiertos H-4 y H-5, decisiones de producto no bloqueantes para la rama.
- **Adenda (2026-11-17):** los arreglos vuelven a medir el mismo caso que reprodujo el defecto y suben la caché del App Shell a `vendguard-app-shell-v23` para que una PWA instalada reciba la hoja corregida.

---

## Resultado general

El ciclo completo del módulo funciona de punta a punta por la interfaz: el reponedor sella la bolsa en ruta, Coordinación la recibe en cámara, ejecuta el arqueo ciego con su tolerancia y su justificación, y el resultado queda terminal e inmutable. Las cifras de los 6 KPIs cuadran con las semillas, los filtros recalculan, el CSV conserva la envoltura contable (UTF-8 BOM, `;`, `Europe/Madrid`) y el blindaje de roles responde en todas las orillas probadas.

Los defectos encontrados no están en la lógica de custodia, sino en los alrededores de la consola: el asistente de chat de n8n, que se monta sobre las vistas de recaudación, resta clics a la tabla en anchos estrechos y se instala en un rol que su contrato no contempla.

---

## 1. Cómo se probó

| Elemento | Detalle |
| :--- | :--- |
| Servidor | `php -S 127.0.0.1:8000 -t public public/index.php` con `deploy/local/postgres/.env.battery` y `VENDGUARD_DEV_SITE_KEYS=1` |
| Motor | PostgreSQL local del banco (`127.0.0.1:55434`, base `vendguard_battery`) |
| Navegador | Chromium del panel, viewport 1440×900 (escritorio) y 360×780 (móvil) |
| Perfiles | Coordinación (`coordinacion@vendguard.internal`), Reponedor (`lucia.repostaje@vendguard.internal`), Técnico (`jordi.ruta@vendguard.internal`) y Responsable de Sede (`SEDE-BCN-01` + `DEV-SEDE-BCN-01`) |
| Interacción | Clics, tecleo y envíos reales sobre la UI; selección de desplegables nativos mediante su `change`; sondas `fetch` con el token de sesión para estado e invariantes |

Recorrido ejecutado: acceso de los cuatro perfiles → sub-vista «🪙 Recaudación y Arqueo» → filtros y KPIs → detalle → recepción en cámara → arqueo ciego (sin justificación, con justificación) → recibo → exportación CSV → turno y captura del reponedor → precinto duplicado, tope de conserjería y transiciones imposibles → portal de sede.

---

## 2. Hallazgos por orden de importancia

### H-1 · P2 · El widget de chat de n8n intercepta los clics sobre «Acciones» en anchos estrechos

**Qué ocurre.** Con el viewport a 522×715, el botón «🔍 Arquear» de la fila `MCC-2026-0004` ocupa `y 679–715` y queda debajo del lanzador del chat, que es un contenedor `position: fixed`, `z-index: 9999` y `pointer-events: auto` de 64×64 px situado en `x 442–506, y 635–699`. La comprobación con `document.elementFromPoint()` sobre el centro del botón devuelve `.chat-window-wrapper`, y el clic real **no llega al botón**: hubo que abrir el modal por código para continuar. A 1440×900 el solape no se produce.

**Por qué importa.** La tabla de recaudación tiene scroll horizontal interno (así lo fija `CashCollectionLayoutBrowserTest`); en móvil y PWA, al desplazarla hasta la columna de acciones, los botones caen bajo la burbuja y el toque se pierde. El usuario percibe un botón muerto.

**Origen.** [`public/assets/js/utils/n8nChat.js`](../public/assets/js/utils/n8nChat.js) monta el bundle de n8n sin aislar el puntero del contenedor.

**Arreglo sugerido.** Envolver el widget en un contenedor con `pointer-events: none` y reactivarlo solo en la burbuja y en la ventana de chat, o reservar un pasillo libre de ~80 px en las vistas con tabla de acciones.

**Estado: corregido.** Se aplicaron las dos mitades del arreglo. [`styles.css`](../public/assets/css/styles.css) declara `.chat-window-wrapper { pointer-events: none !important; }` y reactiva el puntero solo en `.chat-window` y `.chat-window-toggle`; el contenedor de la tabla pasa a `.vg-cash-table-scroll` (ya no lleva `overflow-x` en línea) y reserva `padding-right: 88px` por debajo de 1024 px, el ancho del lanzador más su margen. Verificado a 522×715 con la tabla desplazada a tope (`scrollLeft 381/381`): «🔍 Arquear» queda en `x 297–399`, a 110 px del centro de la burbuja, `document.elementFromPoint` devuelve el propio botón y un clic real del navegador abre el modal de Arqueo. Las esquinas que antes robaban el toque (`432,700`, `436,697`, `505,700`) ya devuelven el contenido de la página. Guarda de regresión en `tests/unit/N8nChatScopeTest.mjs` (aserciones 3.1–3.6).

### H-2 · P3 · El asistente de chat se monta en sesiones de Reponedor

**Qué ocurre.** [`specs/technical/chat_assistant_contracts.md`](../specs/technical/chat_assistant_contracts.md) limita el asistente a «Coordinadores y Técnicos de Ruta», con `role` en minúsculas `coordinador` o `tecnico`. Tras el acceso de Lucía (`RESTOCKER`) se cargaron `cdn.jsdelivr.net/npm/@n8n/chat` y la hoja de estilos, y se registró un `POST` al webhook del asistente.

**Causa.** `app.js` monta el widget cuando existen `chatToken` y `user`, sin filtrar el rol.

**Por qué importa.** Expone una superficie interna a un rol no previsto y es la causa directa de H-1 en la vista del reponedor, que es precisamente donde más se usa la tabla en móvil.

**Estado: corregido.** La causa se cortó en el origen: `AuthService::generateChatToken()` devuelve `null` para cualquier rol que no sea `COORDINATOR` o `TECHNICIAN`, de modo que el login de un reponedor ya no emite `chat_token` (`data.chat_token: null`) y el claim `role` deja de certificar una identidad que el contrato no contempla. Como segunda orilla, [`n8nChat.js`](../public/assets/js/utils/n8nChat.js) exporta `CHAT_ALLOWED_ROLES` e `isChatRoleAllowed()` y `mountChatWidget()` retira el widget si la sesión no es de Coordinación ni de Técnico, lo que cubre un token antiguo guardado en el navegador. Verificado en vivo con la sesión de Lucía: sin `.chat-window-wrapper` en el DOM, sin peticiones al CSS ni al bundle de `cdn.jsdelivr.net` y sin `POST` al webhook. Guarda de regresión en `ChatTokenServiceTest.php` (§5) y en `tests/unit/N8nChatScopeTest.mjs` (§1–§2).

### H-3 · P3 · El widget depende de dos CDN y de un webhook externo fijado en el código

**Qué ocurre.** `n8nChat.js` declara la hoja de estilos y el bundle por `cdn.jsdelivr.net` y el webhook `https://rugsoft.app.n8n.cloud/webhook/…/chat` como constantes del código fuente.

**Por qué importa.** Cada carga hace dos peticiones a Internet y una al tenant ajeno en una PWA que se presenta como offline-first; sin cobertura, el widget falla y ensucia la consola. La URL debería viajar por configuración y quedar fuera del precacheo obligatorio.

**Estado: corregido.** Los tres destinos viven ahora en [`public/assets/js/config/chatAssistant.js`](../public/assets/js/config/chatAssistant.js) (`CHAT_ASSISTANT_DEFAULTS`, con `loadTimeoutMs` y `mountDelayMs`), validados y sobrescribibles por despliegue con `window.VENDGUARD_CHAT_CONFIG`; `n8nChat.js` ya no contiene ningún literal de CDN ni de tenant y se precachea como parte del shell cerrado (`vendguard-app-shell-v24`). El montaje se programa en el primer hueco de inactividad y la importación del bundle tiene techo de espera de 8 s, así que el CDN queda fuera de la ruta crítica: un bundle que nunca responde se rinde en el techo, se avisa por `console.warn` y la consola sigue. Cerrar sesión cancela un montaje pendiente y retira la hoja inyectada. Guarda de regresión en `tests/unit/N8nChatScopeTest.mjs` §3–§4 (validación de la configuración, ausencia de literales, techo de espera con un importador colgado y cancelación del montaje pendiente).

### H-4 · P3 · El CSV de arqueo entrega los estados como código

**Qué ocurre.** La columna «Estado» del libro exportado contiene `DISCREPANCY_FLAGGED`, mientras la consola muestra «Arqueada con descuadre». El resto del contrato se cumple: BOM `EF BB BF`, separador `;`, fechas en `Europe/Madrid`, nombre `recaudaciones.csv` y neutralización de fórmulas.

**Matiz.** El contrato del endpoint **no fija etiquetas**, así que no hay incumplimiento; es una pérdida de legibilidad del documento que va a tesorería. La neutralización de importes negativos (`'-5.50`) sí está exigida por `QA 4` y es un efecto conocido, no un defecto.

### H-5 · P3 · Códigos de recaudación no correlativos

**Qué ocurre.** Las altas nuevas generan seis dígitos aleatorios (`MCC-2026-897619`) mientras las semillas son correlativas (`MCC-2026-0001` … `MCC-2026-0004`). La unicidad no se resiente (el precinto es único global y su duplicado responde `409 BAG_SEAL_CODE_ALREADY_USED`), pero ordenar por código deja de ser cronológico y la búsqueda manual se complica.

### H-6 · Cobertura · Falta la aserción del camino «Recepcionar y Arquear»

**Qué ocurre.** [`tests/integration/CashCollectionVaultAuditApiTest.php`](../tests/integration/CashCollectionVaultAuditApiTest.php) certifica sellar → recibir → arquear y el re-arqueo terminal, pero no el arqueo directo desde `SEALED_IN_ROUTE`, que `EARS 04.2` autoriza y que hoy se verificó a mano: `200` y sellado simultáneo de `delivered_to_vault_at` y `delivered_to_vault_by`.

**Por qué importa.** Es el camino que fabrica la traza de custodia en una sola escritura; una aserción explícita lo blindaría frente a futuras refactorizaciones del repositorio.

**Estado: corregido.** `CashCollectionVaultAuditApiTest` incorpora el bloque 5.11–5.14 sobre una bolsa nueva: nace `SEALED_IN_ROUTE` sin sello de cámara, el arqueo directo responde `200` y sella `RECONCILED_OK` con descuadre cero, `delivered_to_vault_at` y `delivered_to_vault_by` quedan firmados en la misma escritura (con `vault_reconciled_at` no anterior al sello) y la lectura posterior conserva ambos. La suite pasa de 33 a 37 aserciones en verde.

---

## 3. Falso positivo descartado

Durante la corrida, el arqueo directo de una bolsa `SEALED_IN_ROUTE` pareció una brecha de custodia (una bolsa auditada sin recepción física, con `delivered_to_vault_by` firmado por la coordinadora). La revisión de la especificación lo descartó: `EARS 04.2` y la nota de la Etapa 2 describen ese camino como **«Recepcionar y Arquear»** en un solo paso, y la interfaz lo expone (`cashDetailCanReconcile` admite `SEALED_IN_ROUTE` y `DELIVERED_TO_VAULT`). El comportamiento es el especificado, no un defecto.

---

## 4. Verificado en verde

| Superficie | Comprobación | Resultado |
| :--- | :--- | :--- |
| Acceso y roles | Coordinación, Reponedor y Sede entran y aterrizan en su vista | ✔ |
| Art. V.4 | El portal de sede no menciona efectivo en ningún punto | ✔ |
| KPIs (6) | Cifras coherentes con las semillas: pendientes 2 · OK 1 · descuadre 1 · 364,00 € · −6,00 € · 147,50 € | ✔ |
| Filtros | Estado «Sellada en ruta» filtra filas y recalcula KPIs | ✔ |
| Recepción en cámara | `SEALED_IN_ROUTE → DELIVERED_TO_VAULT` con aviso al operador | ✔ |
| Arqueo ciego | Descuadre en vivo −5,50 €; justificación obligatoria > ±1,00 € (cliente y 422 servidor) | ✔ |
| Sello | `RECONCILED_OK`/`DISCREPANCY_FLAGGED`, KPIs actualizados (424,00 €, −11,50 €) | ✔ |
| Inmutabilidad | Re-arqueo `409 CASH_COLLECTION_ALREADY_RECONCILED`; entrega tras arqueo `409 INVALID_CASH_COLLECTION_STATE_TRANSITION` | ✔ |
| Recibo conserjería | Conserje, importe (2,50 €) y vínculo a reintegro; `404` si no hubo entrega | ✔ |
| CSV | `text/csv; charset=utf-8`, BOM, `;`, `Europe/Madrid`, fila sellada incluida | ✔ |
| Reponedor | Turno, modal de captura, alta `201`, precinto propio, historial y contadores | ✔ |
| Validaciones | Precinto duplicado `409`, tope > 10,00 € `422`, conserje sin nombre `422`, reseteo sin notas `422` | ✔ |
| RBAC | Token de reponedor: rutas de Coordinación y bolsa ajena `403`; token de sede `401` | ✔ |

---

## 5. Límites de la corrida

- **Cola Offline con corte real de red**: el navegador del panel no ofrece emulación de red; la cola `IndexedDB` se comprobó solo por lo que anuncia la propia vista. Su cobertura canónica sigue en las suites del módulo y en `CashCollectionOfflineQueue`.
- **Comprobación visual a 360 px**: la pestaña reporta `document.visibilityState: "hidden"` y las capturas salen en blanco, así que la disposición se validó por geometría (sin overflow horizontal; panel de 343 px con campos de 310 px) y no de vista. La referencia visual sigue siendo `CashCollectionLayoutBrowserTest`.
- **PWA instalada y dispositivos reales**: fuera del alcance de este entorno.

---

## 6. Restitución del entorno

Las pruebas mutaron el banco (una bolsa sellada, un arqueo, un alta del reponedor y un turno). Al terminar se ejecutó `TestDataCleaner::purge()` seguido de `SeedRunner::seedAll()` y `ANALYZE`, y se verificó el estado: **4 recaudaciones, 10 sedes, 0 turnos abiertos**. El servidor de desarrollo se detuvo y la pestaña de prueba se cerró.

---

## 7. Orden de corrección propuesto

1. ✅ **H-1** (P2): aislar el puntero del widget o reservar el pasillo inferior derecho. Es el único defecto que un usuario nota bloqueando una acción. *Corregido: puntero aislado y pasillo de 88 px.*
2. ✅ **H-2** (P3): filtrar el montaje del asistente por rol, tal como exige su contrato; corrige también la causa de H-1 en la vista del reponedor. *Corregido: emisión condicionada en el servidor y guarda en el cliente.*
3. ✅ **H-3** (P3): sacar CDN y webhook del código y de la ruta crítica de arranque. *Corregido: configuración validada, montaje diferido y techo de espera.*
4. ✅ **H-6** (cobertura): aserción del arqueo directo desde `SEALED_IN_ROUTE`. *Corregido: bloque 5.11–5.14 de `CashCollectionVaultAuditApiTest`.*
5. **H-4** y **H-5** (P3): etiquetas en el CSV y criterio de numeración, cuando el Product Owner lo decida.
