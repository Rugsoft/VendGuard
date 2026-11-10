# Inspección visual y de clics · Hallazgos UI

| Campo | Valor |
| :--- | :--- |
| **Proyecto** | Gestor de Incidencias de Vending (*VendGuard*) |
| **Rama** | `tiered-sla-contracts` |
| **Fecha de inspección** | 2026-11-10 |
| **Entorno** | Local · PHP 8.2.12 · PostgreSQL 16.15 con TLS · base `vendguard_pr16` |
| **URL** | `http://127.0.0.1:8000` |
| **Alcance** | Portal de Sede, panel de Coordinación y vista móvil del Técnico |
| **Método** | Navegación real con datos de demostración, capturas, consola, red y CSSOM |
| **Resultado** | **6 hallazgos**: 3 de severidad alta, 1 media y 2 bajas · **H-01 y H-02 corregidos** |

> Inspección de solo lectura. No se modificó código funcional durante la revisión.

---

## 1. Resumen

| ID | Severidad | Hallazgo | Estado |
| :--- | :--- | :--- | :--- |
| **H-01** | **Alta** | El Portal de Sede no carga `styles.css`; la fila del contrato SLA pierde su layout. | **Corregido** |
| **H-02** | **Alta** | `GET /api/technician/my-route` no publica los campos SLA; el técnico ve «Contrato sin asignar». | **Corregido** |
| **H-03** | **Alta** | Desbordamiento horizontal de 205 px en la vista móvil del técnico a 390 px. | **Corregido** |
| **H-04** | **Media** | La tabla de triaje desborda a 1280 × 720 y el chat tapa acciones. | **Corregido** |
| **H-05** | **Baja** | El panel de Coordinación renderiza pestañas, filtros y tabla antes del login. | Abierto |
| **H-06** | **Baja** | El modal de satisfacción se abre automáticamente al entrar en el Portal de Sede. | Abierto |

### Flujos comprobados

- **Portal de Sede:** login con código de sede, pestañas **Máquinas**, **Control Higiénico** y **Reintegros**, tarjetas de máquina y modal de conversación.
- **Coordinación:** login, bandeja de triaje, filtros, tabla y ficha de detalle de una incidencia.
- **Técnico:** login y vista **Mi Ruta** con viewport de **390 × 844**.
- **Consola y red:** sin errores de consola ni peticiones fallidas durante el recorrido.

---

## 2. Hallazgos

### H-01 · Alta · El Portal de Sede no carga `styles.css`

**Síntoma visible**

En las tarjetas de máquina del portal, el contrato SLA aparece pegado:

```text
EstándarPlazo objetivo: 24 h
```

No hay separación entre el badge del contrato y el plazo objetivo.

**Evidencia**

- `public/index.html` enlaza únicamente cuatro hojas:
  - `design-tokens.css`
  - `qr-print.css`
  - `metrics-print.css`
  - `service-sheet.css`
- En el Portal de Sede, `document.styleSheets` no contiene `styles.css`.
- La fila `.vg-sla-contract-row` se computa como `display: block` y `gap: normal`, aunque `styles.css` declara `display: flex` y `gap: 4px 8px`.
- `public/sw.js` precachea `styles.css`, pero precachear no equivale a enlazarlo en el documento. El propio comentario del Service Worker reconoce que la hoja entra «aunque no la enlace `index.html`».
- En la vista del técnico `styles.css` sí aparece cargada bajo demanda, lo que confirma que el problema es la carga global y no la regla CSS.

**Causa probable**

`styles.css` se incorpora solo desde algunas pestañas de Coordinación; el Portal de Sede nunca lo solicita y `index.html` no lo enlaza.

**Corrección propuesta**

1. Enlazar `styles.css` de forma global en `public/index.html`, después de `design-tokens.css`.
2. Evitar cargas duplicadas en las pestañas que ya lo inyectan.
3. Subir la versión de caché del Service Worker para que los clientes existentes reciban la hoja.

**Resolución (2026-11-10)**

H-01 corregido en la rama `tiered-sla-contracts`:

- `public/index.html` enlaza `styles.css` globalmente, después de `design-tokens.css`.
- Se retiraron las cinco inyecciones bajo demanda de `CoordinatorSanitarySpoilageTab`, `CoordinatorRootCauseParetoTab`, `CoordinatorVanStockTab`, `CoordinatorTroubleshootingGuidesTab` y `TroubleshootingGuideAccordion`.
- `public/sw.js` sube la caché del shell a `vendguard-app-shell-v12` y documenta la hoja como parte del shell global.
- Se añadió la guarda `2.4b` en `OfflinePwaAssetsAndUiGuardTest.mjs` y se actualizó `2.4` de `TroubleshootingGuideCoverageUiTest.mjs` para exigir el enlace global y la ausencia de inyecciones locales.
- Verificación: `OfflinePwaAssetsAndUiGuardTest.mjs` 45/45, `TroubleshootingGuideCoverageUiTest.mjs` 18/18 y `TieredSlaFrontendTest.mjs` 47/47. En navegador, una sola hoja `styles.css`, `display:flex`, `gap:4px 8px`, sin errores de consola.

**Archivos relacionados**

- `public/index.html`
- `public/assets/css/styles.css`
- `public/sw.js`
- `public/assets/js/views/LocationPortalView.js` (fila del contrato, ~826)

**Evidencia visual**

> Captura local: `screenshots/ui_03_site_machines.png` (excluida del repositorio).

**Evidencia tras la corrección**

> Captura local: `screenshots/ui_13_site_portal_h01_fixed.png` (excluida del repositorio).

---

### H-02 · Alta · `my-route` no publica los campos SLA

**Síntoma visible**

Las dos paradas de la ruta del técnico muestran:

```text
Contrato sin asignar
```

Sin embargo, Coordinación muestra para esas mismas incidencias «Estándar · 24 h» e «Infraestructura Crítica 24x7 · 4 h».

**Evidencia**

La respuesta real de `GET /api/technician/my-route` devuelve estas claves por parada:

```text
id, ticket_code, urgency, status, opportunistic_mode, opportunistic_mode_label,
opportunistic_origin_reason, opportunistic_origin_label, opportunistic_informant_name,
chronic_predecessor_incident_id, machine, location, category, description, started_at,
pending_parts_reason, created_at, paused_at, total_pending_info_seconds,
pending_info_reason_category, pending_info_reason_category_label,
pending_info_reason_text, site_response_since_pause, comments_count,
sanitary_resolution_required, van_stock_readiness
```

No aparece ninguna de las claves que consume el frontend:

- `sla_tier_snapshot`
- `sla_tier_label`
- `sla_target_minutes`
- `sla_target_at`
- `sla_due_at`
- `critical_reason`
- `is_cold_chain_critical`
- `is_contractual_critical`

`TechnicianController::stopPayload()` construye la tarjeta de la parada y no incluye campos SLA. El frontend, en cambio, ya está preparado: `TechnicianRouteView.js` mapea `sla_tier_label`, `sla_tier_snapshot` y `sla_tier`, y la plantilla recurre a `'Contrato sin asignar'` cuando no hay datos.

**Impacto**

El Módulo 30 no se visualiza en la superficie móvil del técnico. El profesional de campo no ve el perfil contractual ni el plazo objetivo de la parada que va a atender, aunque la API de Coordinación sí los publique.

**Causa probable**

Omisión en la serialización de `stopPayload()`, no en el frontend. Conviene revisar también que `findRouteBoard()` y `findAssignedToTechnician()` hidraten el expediente con sus campos SLA antes de serializarlo.

**Corrección propuesta**

1. Añadir a `stopPayload()` los campos `sla_tier_snapshot`, `sla_tier_label`, `sla_target_minutes`, `sla_target_at`, `sla_due_at`, `critical_reason`, `is_cold_chain_critical` e `is_contractual_critical`.
2. Verificar que ambos caminos de lectura (`routeFromBoard()` y `routeFromReads()`) publican exactamente el mismo contrato.
3. Añadir una aserción en `TechnicianRouteEndpointTest` que compruebe los campos SLA en la respuesta real de `my-route`.

**Resolución (2026-11-10)**

H-02 corregido en la rama `tiered-sla-contracts`:

- `TechnicianController::stopPayload()` incorpora el contrato SLA mediante `stopSlaPayload()`, con la misma precedencia que la ruta y el mapa: `COLD_CHAIN` prevalece sobre `CONTRACTUAL_SLA`.
- `sla_target_at` conserva el valor persistido; `sla_due_at` publica el vencimiento efectivo en ISO 8601, con cálculo determinista de respaldo para avisos antiguos.
- La respuesta real de `my-route` devuelve la etiqueta `Estándar`, 1440 minutos, vencimiento y `COLD_CHAIN` para la parada perecedera; la segunda parada queda sin motivo crítico.
- `TechnicianRouteBatchReadsTest` pasa 30/30 y `TechnicianRouteEndpointTest` completa su batería sin fallos, incluidas las aserciones 2.7 y 2.8.
- Verificación en navegador: las dos tarjetas muestran «Estándar» y «Vence: 11/11, 11:37»; la perecedera añade «🌡️ Riesgo sanitario de cadena de frío», sin errores de consola.

**Archivos relacionados**

- `src/Presentation/Controller/TechnicianController.php` (`stopPayload()`, ~761)
- `src/Infrastructure/Repository/PdoIncidentRepository.php` (`findRouteBoard()`, ~2405)
- `public/assets/js/views/TechnicianRouteView.js` (~1358 y ~4286)
- `public/assets/js/utils/OfflineStorage.js` (~122)

**Evidencia visual**

> Captura local: `screenshots/ui_11_technician_route_mobile.png` (excluida del repositorio).

---

### H-03 · Alta · Desbordamiento horizontal en la vista móvil del técnico

**Síntoma visible**

A 390 px de ancho, la cabecera y la barra de perfiles no caben. Se ven cortados «Técnico de Ruta», «Salir», «Mis Métricas» y parte del bloque de acciones. Aparece una barra de desplazamiento horizontal.

**Evidencia medida**

```text
innerWidth:          390 px
document.clientWidth: 373 px
document.scrollWidth: 578 px
Desbordamiento:      205 px
```

El bloque derecho de la cabecera se extiende hasta `x = 578`, muy por fuera del viewport. Los principales infractores son el contenedor de identidad del técnico y los botones **Ayuda** y **Salir**.

**Impacto**

La vista está pensada para uso móvil de campo, pero a un ancho estándar de teléfono obliga a desplazarse horizontalmente para alcanzar acciones básicas.

**Causa probable**

La cabecera y la barra de perfiles usan disposición en línea sin `flex-wrap`, sin `min-width: 0` y con anchos mínimos que no se reducen. El bloque de identidad del técnico y el grupo de acciones compiten por el mismo espacio.

**Corrección propuesta**

1. Permitir `flex-wrap` en la cabecera y en la barra de perfiles.
2. Aplicar `min-width: 0` a los bloques de texto y truncar con elipsis cuando sea necesario.
3. En móvil, ocultar el selector de perfiles de demostración o convertirlo en un control compacto.
4. Verificar a 360, 390 y 430 px que `scrollWidth <= clientWidth`.

**Resolución (2026-11-10)**

H-03 corregido en la rama `tiered-sla-contracts`:

- La cabecera global usa `.vg-navbar__inner` y `.vg-navbar__actions` con `flex-wrap`, `min-width: 0` y altura mínima en lugar de una fila fija de 56 px. En móvil, las acciones ocupan una línea completa y la etiqueta de sesión puede truncarse con elipsis.
- La barra de perfiles usa `.vg-profile-tabs` con envoltura y ancho máximo; el bloque de credenciales puede romper direcciones largas sin ensanchar el documento.
- La cabecera de la ruta del técnico usa `.vg-tech-route-header` y `.vg-tech-route-actions` con envoltura y límites flexibles; la identidad conserva una base de 120 px para que las acciones pasen a una segunda línea solo cuando no caben.
- `public/sw.js` sube la caché del shell a `vendguard-app-shell-v13` para que los clientes instalados reciban los estilos y módulos corregidos.
- Se añadió `tests/unit/MobileShellLayoutGuardTest.mjs` (7/7) para congelar la envoltura, los límites de ancho y la versión de caché.
- Verificación en navegador con el desplegable de credenciales abierto: a 360 px, `clientWidth=343` y `scrollWidth=343`; a 390 px, `373/373`; a 430 px, `413/413`. En los tres casos el desbordamiento es 0 px. A 1280 px no hay desbordamiento y la cabecera global conserva 56 px de alto.
- `TechnicianRouteViewTest.mjs`, `OfflinePwaAssetsAndUiGuardTest.mjs` y `TroubleshootingGuideCoverageUiTest.mjs` pasan sin fallos.

**Archivos relacionados**

- `public/assets/css/styles.css` (reglas de cabecera y barra de perfiles)
- `public/assets/js/components/AppNavbar.js` (cabecera y bloque de sesión)
- `public/assets/js/app.js` (selector de perfiles y guía de credenciales)
- `public/assets/js/views/TechnicianRouteView.js` (cabecera de la ruta)
- `public/sw.js` (versión de caché del shell)
- `tests/unit/MobileShellLayoutGuardTest.mjs` (guarda de regresión)

**Evidencia visual**

> Captura local: `screenshots/ui_11_technician_route_mobile.png` (excluida del repositorio).

---

### H-04 · Media · La tabla de triaje desborda y el chat tapa acciones

**Síntoma visible**

En una ventana de 1280 × 720, la tabla de Coordinación es más ancha que el viewport. La columna **ACCIONES** queda parcialmente fuera de la vista. El widget de chat flotante se superpone a la esquina inferior derecha de la tabla y a parte de los botones.

**Evidencia**

- La tabla y sus filtros ocupan más ancho que el área visible.
- La cabecera **ACCIONES** aparece cortada en el borde derecho.
- La burbuja de chat queda por encima del contenido de la tabla.

**Impacto**

El coordinador puede no ver el botón de acción de una fila sin desplazarse horizontalmente, y el chat puede interceptar clics en la zona inferior derecha.

**Corrección propuesta**

1. Envolver la tabla en un contenedor con `overflow-x: auto` y un ancho máximo del 100 %.
2. Fijar la columna de acciones a la derecha o reducir el ancho de columnas secundarias.
3. Desplazar la burbuja de chat cuando la tabla esté visible, o permitir ocultarla.
4. Probar a 1280 × 720 y 1366 × 768.

**Resolución (2026-11-10)**

H-04 corregido en la rama `tiered-sla-contracts`:

- La tabla de triaje queda encapsulada en `.vg-triage-table-card`, `.vg-triage-table-scroll` y `.vg-triage-table`; el contenedor acota el ancho al 100 % y asume el `overflow-x`.
- La columna de acciones usa `.vg-triage-actions` con `position: sticky`, ancho de 240 px y fondo heredado de la fila, de modo que permanece visible y conserva el resaltado SLA.
- En anchos de 900 px o más, la tarjeta reserva 88 px a la derecha para que la burbuja fija del chat no invada los botones. A 1024 px la tabla usa desplazamiento interno y la columna fija sigue a la vista.
- `public/sw.js` sube la caché del shell a `vendguard-app-shell-v14`.
- Se añadió `tests/unit/CoordinatorTriageLayoutGuardTest.mjs` (6/6).
- Verificación en navegador: a 1280 × 720, contenedor 1141/1141, borde derecho de acciones 1158 y chat desde 1183 (25 px de separación); a 1366 × 768, acciones hasta 1220 y chat desde 1269 (≈49 px); a 1024 × 768, la tabla desborda solo dentro de su contenedor y la columna fija termina en 902 frente al chat en 927 (25 px). Sin desbordamiento horizontal del documento en ninguno de los tres casos.
- `CoordinatorDashboardViewTest.mjs` 143/143, `CoordinatorActionsWrapGuardTest.mjs` 7/7 y `OfflinePwaAssetsAndUiGuardTest.mjs` 45/45 pasan sin fallos.

**Archivos relacionados**

- `public/assets/js/views/CoordinatorDashboardView.js`
- `public/assets/css/styles.css`
- `public/sw.js`
- `tests/unit/CoordinatorTriageLayoutGuardTest.mjs`
- Widget de chat de n8n (`@n8n/chat`)

**Evidencia visual**

> Captura local: `screenshots/ui_08_coordinator_triage.png` (excluida del repositorio).

---

### H-05 · Baja · El panel de Coordinación se renderiza antes del login

**Síntoma visible**

Al abrir la pestaña **Coordinador** sin sesión, se ven las pestañas, los filtros, los KPIs y la tabla vacía detrás del formulario de acceso. El login aparece superpuesto, pero la estructura del panel ya está montada.

**Evidencia**

- La captura del login de Coordinación muestra el shell completo detrás del formulario.
- No se comprobó que las pestañas respondan sin sesión; la API sí devuelve 401, por lo que no hay exposición de datos.

**Impacto**

Es una inconsistencia de experiencia, no un fallo de seguridad: la interfaz sugiere que el panel está operativo antes de autenticarse.

**Corrección propuesta**

Renderizar el shell de Coordinación solo cuando la sesión esté resuelta y el rol sea el adecuado. Mientras tanto, mostrar únicamente el formulario de login.

**Archivos relacionados**

- `public/assets/js/app.js`
- `public/assets/js/views/CoordinatorDashboardView.js`

**Evidencia visual**

> Captura local: `screenshots/ui_07_coordinator_login.png` (excluida del repositorio).

---

### H-06 · Baja · El modal de satisfacción se abre automáticamente

**Síntoma visible**

Al entrar en el Portal de Sede, si hay servicios pendientes de valorar, se abre solo el modal **«¿Cómo fue el servicio?»** y bloquea la primera visión del panel. Se puede cerrar con **«Ahora no»**.

**Evidencia**

- El modal aparece inmediatamente después del login.
- El contenido y las acciones funcionan correctamente; el problema es la interrupción automática.

**Impacto**

Puede tapar el estado de las máquinas y retrasar la tarea principal del responsable de sede, especialmente si entra para reportar o consultar una avería urgente.

**Corrección propuesta**

Sustituir la apertura automática por un aviso no modal en el panel, o abrir la encuesta solo cuando el usuario pulse explícitamente el aviso. Si se mantiene el modal, recordar la decisión de «Ahora no» durante la sesión.

**Archivos relacionados**

- `public/assets/js/views/LocationPortalView.js`
- Componente de encuesta de satisfacción

**Evidencia visual**

> Captura local: `screenshots/ui_02_site_portal.png` (excluida del repositorio).

---

## 3. Prioridad recomendada

1. **H-01:** restaurar la hoja global; afecta a la presentación del contrato en el portal.
2. **H-02:** completar el payload de `my-route`; afecta a la información contractual que recibe el técnico.
3. **H-03:** corregir el responsive móvil; afecta al uso real en campo.
4. **H-04:** contener la tabla y recolocar el chat; afecta a la operativa de Coordinación.
5. **H-05 y H-06:** ajustes de experiencia, sin impacto funcional inmediato.

---

## 4. Notas de la revisión

- Los seis hallazgos se detectaron con datos de demostración (`seed_demo_history.php` y `seed_demo_map.php`), por lo que conviene reproducirlos también con datos reales antes de cerrar las correcciones.
- El distintivo azul **Freebuff** de la esquina superior izquierda es una superposición del navegador, no un elemento de VendGuard; no se contabiliza como hallazgo.
- La inspección no cubrió accesibilidad completa, lectores de pantalla ni navegación por teclado; se limita a presentación visual, clics, consola y red.
