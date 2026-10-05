# Contratos técnicos · Módulo compartido de permisos por estado (`utils/IncidentStatusPermissions.js`)

**Módulo:** bandeja de triaje + ficha de detalle · **Tipo:** unificación de lógica · **Estado:** implementado

## 1. Problema

La bandeja de triaje (`CoordinatorDashboardView.js`) mantenía **seis listas de estados independientes** (predicados de acciones rápidas EARS 5.5/6.4, métricas del panel, filtro SLA, agrupación de asignación masiva del mapa territorial y mapa canónico bilingüe propio). Cada lista era una copia literal de las mismas reglas de negocio que el backend aplica en `CoordinatorIncidentDetailService` (`ACTIVE_STATUSES`, `computePermissions`) y en los controladores (`REGISTERED`/`REOPENED` asignables; activos cancelables). Cualquier cambio futuro del ciclo de vida exigiría tocar PHP y, si se olvidaba uno, algún clasificador de la bandeja quedaba desalineado en silencio.

La ficha de detalle **no** duplica listas: consume `permissions` calculados por el backend (API-First, autoridad única del servidor). El nuevo módulo no compite con ese contrato: es el **espejo frontend** de las reglas PHP para las superficies que solo tienen el listado de bandeja.

## 2. Módulo compartido

`public/assets/js/utils/IncidentStatusPermissions.js` — ESM puro, sin dependencias (Dogma Vanilla), mismo patrón que `utils/Mercator.js` y `utils/MapZoomPan.js`.

### 2.1 Fuente única de listas (espejo de `IncidentStatus` PHP)

```js
export const INCIDENT_STATUSES = Object.freeze({ REGISTERED: 'REGISTERED', ASSIGNED: 'ASSIGNED', IN_PROGRESS: 'IN_PROGRESS', PENDING_PARTS: 'PENDING_PARTS', RESOLVED: 'RESOLVED', REOPENED: 'REOPENED', CLOSED: 'CLOSED', CANCELLED: 'CANCELLED' });
export const ASSIGNABLE_STATUSES = Object.freeze(['REGISTERED', 'REOPENED']);               // EARS 5.5 / guardia PHP assign
export const REASSIGNABLE_STATUSES = Object.freeze(['ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS']); // RF-07.3 / guardia PHP reassign
export const ACTIVE_STATUSES = Object.freeze(['REGISTERED', 'REOPENED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS']); // espejo CoordinatorIncidentDetailService::ACTIVE_STATUSES
export const TERMINAL_STATUSES = Object.freeze(['RESOLVED', 'CLOSED', 'CANCELLED']);
export const CANONICAL_STATUS_MAP = Object.freeze({ REGISTERED: 'REGISTERED', REGISTRADA: 'REGISTERED', ASSIGNED: 'ASSIGNED', ASIGNADA: 'ASSIGNED', IN_PROGRESS: 'IN_PROGRESS', EN_CURSO: 'IN_PROGRESS', PENDING_PARTS: 'PENDING_PARTS', PENDIENTE_REPUESTO: 'PENDING_PARTS', PENDIENTE_REPUESTOS: 'PENDING_PARTS', RESOLVED: 'RESOLVED', RESUELTA: 'RESOLVED', REOPENED: 'REOPENED', REABIERTA: 'REOPENED', CLOSED: 'CLOSED', CERRADA: 'CLOSED', CANCELLED: 'CANCELLED', CANCELADA: 'CANCELLED' });
```

### 2.2 Funciones puras

| Firma | Regla de negocio |
|---|---|
| `normalizeIncidentStatus(value): string` | Acepta español o inglés, recorta, pasa a mayúsculas y devuelve el canónico en inglés; desconocidos quedan en mayúsculas tal cual (misma política que el mapa previo de la bandeja). |
| `canQuickAssign(incident): boolean` | EARS 5.5: `true` solo si el estado canónico ∈ `ASSIGNABLE_STATUSES`. |
| `canQuickCancel(incident): boolean` | EARS 6.4: `true` si el estado canónico ∈ `ACTIVE_STATUSES`; estado vacío o desconocido → `false` (fail-safe con acción destructiva). |
| `isTerminalStatus(value): boolean` | Estado canónico ∈ `TERMINAL_STATUSES`. |
| `isResolvedStatus(value): boolean` | Estado canónico === `RESOLVED` (identificación de tickets resueltos para ventana de garantía y reaperturas). |
| `isActiveStatus(value): boolean` | Estado canónico ∈ `ACTIVE_STATUSES`. |
| `normalizeUrgency(value): string` | Normaliza valores de urgencia bilingües con/sin tildes a la clave canónica en inglés (`CRITICAL`, `HIGH`, `MEDIUM`, `LOW`). |
| `isCriticalUrgency(value): boolean` | Nivel de urgencia canónico === `CRITICAL` (usado para semáforo SLA de 60 min y prioridad sanitaria). |
| `isPendingAssignment(value): boolean` | Estado ∈ `ASSIGNABLE_STATUSES` **o** `assigned_technician_id` vacío; replica el semáforo SLA previo de la bandeja (`slaBreachedIncidents`). |

Todas aceptan el incidente completo (`{ status, assigned_technician_id }`) o el estado suelto; toleran `null`/`undefined`.

## 3. Consumidores refactorizados

- **`CoordinatorDashboardView.js`** (bandeja): elimina `STATUS_CANONICAL_MAP`, `normalizeStatus` y las 6 listas; importa el módulo y delega `canQuickAssign`/`canQuickCancel` (plantilla intacta: mismos `v-if`), `filteredIncidents`, `metrics`, `slaBreachedIncidents` (delegando en `isCriticalUrgency`), el bloqueo del bulk-assign del mapa y el filtro de incidencias pendientes. El fichero queda **cero literales de estado**.
- **`CoordinatorIncidentDetailModal.js`** (ficha): sin cambios funcionales; sigue consumiendo `permissions` del backend. Queda documentado en su cabecera que las reglas espejo viven en el módulo utilitario.
- **`TechnicianRouteView.js`** (ruta móvil del técnico): consume `INCIDENT_STATUSES`, `URGENCY_LEVELS`, `URGENCY_RANKS`, `normalizeUrgency` e `isCriticalUrgency` para métricas de ruta (`routeMetrics`), filtrado y ordenación operacional (`filteredIncidents`), estilos de borde y predicados en plantilla.
- **`IncidentReportModal.js`**: utiliza `INCIDENT_CATEGORIES` (5 categorías canónicas coincidentes con `IncidentCategory.php`) y `URGENCY_LEVELS.MEDIUM` como fallback tipado ante colisiones 409.
- **`QrReportView.js`**: reexporta `INCIDENT_CATEGORIES` del módulo compartido, garantizando cero divergencias entre sede y escaneo público.
- **`MachineCard.js`** y **`ReopenTicketModal.js`** (sede / portal de ubicación): delegan la comprobación de estado de garantía y elegibilidad de reapertura en `isResolvedStatus` e `INCIDENT_STATUSES.RESOLVED`, eliminando literales duplicados `'RESUELTA' || 'RESOLVED'`.

## 4. Verificación

- Suite unitaria nueva `tests/unit/IncidentStatusPermissionsUtilTest.mjs` (patrón `*UtilTest.mjs`): listas congeladas espejo del enum PHP, normalización bilingüe completa, predicados por estado, fail-safe y contrato EARS 5.5/6.4 cruzado con las listas PHP.
- `CoordinatorDashboardViewTest.mjs` conserva sus 79 aserciones: prueba que el gating y los filtros siguen operativos tras la delegación.
- Batería global `tests/run_all.php` recoge la suite nueva por descubrimiento automático `glob(tests/unit/*.mjs)`.

## 6. Extensión: vocabulario localizado y paletas de insignias

El módulo concentra también los mapas de **presentación** que antes estaban duplicados en `IncidentBadge.js` (`URGENCY_CONFIG`, `STATUS_CONFIG` bilingües) y `MachineCard.js` (`MACHINE_TYPE_MAP`):

- `STATUS_LABELS` / `URGENCY_LABELS`: etiquetas en español. `URGENCY_LABELS.CRITICAL` conserva la variante corta de insignia ('Crítica'); la etiqueta completa del servidor ('Crítica (Riesgo Alimentario)') sigue llegando vía API donde hay espacio.
- `BADGE_URGENCY_PALETTE` / `BADGE_STATUS_PALETTE`: paletas semánticas del sistema Docker, byte a byte idénticas a las pre-extracción.
- `MACHINE_TYPE_LABELS` / `MACHINE_TYPE_ICONS` / `isPerishableMachineType`: vocabulario de tipos de máquina, espejo de `MachineType.php`, con la regla sanitaria de perecederos.
- `INCIDENT_CATEGORIES`: lista congelada de las 5 categorías canónicas de avería (espejo de `IncidentCategory.php`), consumida por el modal de reporte guiado y por la vista QR pública.
- `normalizeBadgeKey` / `resolveBadgeConfig(value, type)`: normalización tolerante del badge (NFD sin diacríticos, espacios → guion bajo) y resolución única de paleta + etiqueta en modos `urgency`/`status`/`auto` (precedencia histórica: urgencia primero). Valores desconocidos → `null`: cada consumidor conserva su fallback.

Los componentes `IncidentBadge` y `MachineCard` quedan como puros mapeadores a markup (cero literales de etiquetas; verificado por los grupos 7–9 de la suite). El wrapper `UIComponentsTest.php` apunta ahora sus contratos de diccionario al módulo compartido.

## 7. Restricción de futuro

Si el ciclo de vida cambia, la edición canónica es PHP (`IncidentStatus` + `CoordinatorIncidentDetailService`); este módulo y su suite espejo se actualizan en el mismo commit. Ninguna otra superficie del frontend debe volver a escribir listas de estado literales.
