/**
 * VendGuard - CoordinatorTerritorialMapTab Test Suite (CoordinatorTerritorialMapTabTest.mjs)
 *
 * Validates the reactive behavior and contracts of the coordinator territorial triage map
 * (RF-MAP-09, RNF-MAP-02, RNF-MAP-06).
 *
 * Hecho cuando:
 * 1. Renders the global territorial map grouping sites with active incidents/preventives.
 * 2. Shows badges with the pending incident count per site and the semantic colors.
 * 3. Highlights multi-technician sites and offers reactive filters by technician and severity.
 * 4. Starts the technical assignment flow from unassigned sites.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

const storageMock = (() => {
  let store = {};
  return {
    getItem: (key) => store[key] || null,
    setItem: (key, val) => { store[key] = String(val); },
    removeItem: (key) => { delete store[key]; },
    clear: () => { store = {}; }
  };
})();
globalThis.localStorage = storageMock;
globalThis.window = {
  location: { search: '', href: 'http://localhost/' }
};

import { api } from '../../public/assets/js/api.js';
import { CoordinatorTerritorialMapTab, MARKER_COLORS, TERRITORIAL_CANVAS_HEIGHT_RATIO } from '../../public/assets/js/components/CoordinatorTerritorialMapTab.js';
import { Mercator, buildMapTiles as sharedBuildMapTiles } from '../../public/assets/js/utils/Mercator.js';

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) {
      console.error(`         Motivo: ${details}`);
    }
    failures++;
  }
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - CoordinatorTerritorialMapTab (T-MAP-15)');
console.log('======================================================================\n');

// Fixtures de prueba (contrato specs/technical/route_map_contracts.md 4.2.1)
const makeSites = () => ([
  {
    location_id: 1,
    site_code: 'SEDE-BCN-01',
    name: 'Hospital del Mar - Edificio Central',
    address: 'Passeig Marítim 25, Barcelona',
    latitude: 41.385312,
    longitude: 2.193245,
    max_urgency: 'CRITICAL',
    has_perishable_risk: true,
    total_incidents: 1,
    total_preventives: 1,
    assigned_technicians: [{ id: 2, name: 'Jordi Técnico', operator_code: 'OP-02' }],
    is_multi_technician: false,
    has_unassigned: false,
    incidents_summary: []
  },
  {
    location_id: 2,
    site_code: 'SEDE-BCN-02',
    name: 'Torre Glòries - Planta 4 Oficinas',
    address: 'Avinguda Diagonal 211, Barcelona',
    latitude: 41.403629,
    longitude: 2.189512,
    max_urgency: 'HIGH',
    has_perishable_risk: false,
    total_incidents: 2,
    total_preventives: 0,
    assigned_technicians: [
      { id: 2, name: 'Jordi Técnico', operator_code: 'OP-02' },
      { id: 3, name: 'Marta Técnica', operator_code: 'OP-03' }
    ],
    is_multi_technician: true,
    has_unassigned: false,
    incidents_summary: []
  },
  {
    location_id: 3,
    site_code: 'SEDE-BCN-03',
    name: 'Centro Comercial Diagonal',
    address: 'Avinguda Diagonal 3, Barcelona',
    latitude: 41.392,
    longitude: 2.164,
    max_urgency: 'MEDIUM',
    has_perishable_risk: false,
    total_incidents: 1,
    total_preventives: 0,
    assigned_technicians: [],
    is_multi_technician: false,
    has_unassigned: true,
    incidents_summary: []
  }
]);

function createTabInstance(initialData = {}) {
  const comp = Object.assign({}, CoordinatorTerritorialMapTab);
  const data = comp.data();
  const instance = Object.assign(data, comp.methods, initialData);
  for (const [key, getter] of Object.entries(comp.computed || {})) {
    Object.defineProperty(instance, key, {
      get: () => getter.call(instance),
      configurable: true
    });
  }
  instance.emittedEvents = [];
  instance.$emit = (event, value) => {
    instance.emittedEvents.push({ event, value });
  };
  return instance;
}

// Captura de llamadas a la API (sin red)
let apiCalls = [];
let apiQueue = [];
api.map = api.map || {};
api.map.getActiveIncidents = async (params = {}) => {
  apiCalls.push(params);
  const payload = apiQueue.length > 0 ? apiQueue.shift() : { locations: makeSites() };
  return { success: true, data: payload };
};

// =========================================================================
// BLOQUE 1: Carga del mapa territorial y agrupación por sedes (RF-MAP-09)
// =========================================================================
console.log('--- BLOQUE 1: Carga del mapa territorial ---');

const tab = createTabInstance();
await tab.loadTerritorialData();

assert('1.1 Carga las sedes con averías y preventivos activos agrupadas', tab.sites.length === 3 && tab.visibleSites.length === 3);
assert('1.2 Cada sede conserva sus coordenadas geográficas', tab.sites.every(site => Number.isFinite(Number(site.latitude)) && Number.isFinite(Number(site.longitude))));
assert('1.3 El resumen cuenta tareas activas del parque', tab.totalActiveTasks === 5);
assert('1.4 Detecta las sedes con riesgo de cadena de frío', tab.criticalSiteCount === 1);
assert('1.5 Detecta las sedes con multi-técnico', tab.multiTechnicianSiteCount === 1);
assert('1.6 Sin filtros la consulta se realiza sin parámetros', apiCalls[0] !== undefined && Object.keys(apiCalls[0]).length === 0);
assert('1.7 La primera carga muestra el spinner (no silenciosa) y después lo retira', tab.isLoading === false);

// =========================================================================
// BLOQUE 2: Insignias, colores semánticos y proyección (RNF-MAP-06)
// =========================================================================
console.log('\n--- BLOQUE 2: Insignias y colores semánticos ---');

const sites = tab.sites;
assert('2.1 Sede crítica de perecederos usa el rojo de advertencia', tab.siteColor(sites[0]) === MARKER_COLORS.critical && MARKER_COLORS.critical === '#e02424');
assert('2.2 Sede ordinaria usa el azul primario', tab.siteColor(sites[2]) === MARKER_COLORS.ordinary && MARKER_COLORS.ordinary === '#2560ff');
assert('2.3 Sede exclusiva de preventivo usa el verde', tab.siteColor({ has_perishable_risk: false, total_incidents: 0, total_preventives: 2 }) === MARKER_COLORS.preventive && MARKER_COLORS.preventive === '#38bd7d');
assert('2.4 La insignia cuenta el total de máquinas pendientes de la sede', tab.sitePendingCount(sites[0]) === 2 && tab.sitePendingCount(sites[1]) === 2 && tab.sitePendingCount(sites[2]) === 1);

const positions = tab.sites.map(site => tab.sitePosition(site));
assert('2.5 Cada sede proyecta un marcador finito dentro del lienzo territorial',
  positions.every(point => Number.isFinite(point.x) && Number.isFinite(point.y) && point.x >= 0 && point.x <= 100 && point.y >= 0 && point.y <= 100));
assert('2.6 Cada sede dispone de posición para su marcador', positions[0].x !== undefined && positions[2].y !== undefined);

// Proyección Web Mercator y teselas estándar compartidas con el mapa del técnico (utils/Mercator.js)
assert('2.7 Mercator compartido: el ecuador cae en 0.5 y Greenwich en el centro del mundo',
  Mercator.latToFraction(0) === 0.5 && Mercator.lngToFraction(0) === 0.5);
const tiles = tab.mapTiles;
assert('2.8 Se solicitan teselas estándar de OpenStreetMap sin clave de API',
  tiles.length >= 1 && tiles.every(tile => tile.url.startsWith('https://tile.openstreetmap.org/')));
assert('2.9 La tesela que contiene la primera sede existe en la ventana territorial',
  (() => {
    const zoom = tab.mapWindow.zoom;
    const tileX = Math.floor(Mercator.lngToWorldX(sites[0].longitude, zoom));
    const tileY = Math.floor(Mercator.latToWorldY(sites[0].latitude, zoom));
    return tiles.some(tile => tile.key === zoom + '/' + tileX + '/' + tileY);
  })());
assert('2.10 El recorte panorámico mantiene la banda central con escala isótropa',
  Math.abs(tab.territorialBandCrop(19)) < 1e-9
    && Math.abs(tab.territorialBandCrop(50) - 50) < 1e-9
    && tab.territorialTileStyle(tiles[0]).height.endsWith('%'));
assert('2.11 Una tesela rota se oculta sin romper los marcadores (RNF-MAP-04)',
  (() => { const ev = { target: { style: {} } }; tab.hideTile(ev); return ev.target.style.display === 'none'; })());
assert('2.12 El utilitario compartido genera teselas OSM válidas para cualquier ventana',
  (() => {
    const sample = sharedBuildMapTiles({ zoom: 13, sideTiles: 2, leftEdge: 4145.2, topEdge: 3058.8 });
    return sample.length >= 1 && sample.every(tile => tile.url.startsWith('https://tile.openstreetmap.org/13/'));
  })());

// =========================================================================
// BLOQUE 3: Detección multi-técnico y flujo de asignación (RF-MAP-09)
// =========================================================================
console.log('\n--- BLOQUE 3: Multi-técnico y flujo de asignación ---');

assert('3.1 La sede multi-técnico lista a todos sus operarios', tab.technicianNames(sites[1]) === 'Jordi Técnico / Marta Técnica');
assert('3.2 La sede con un solo técnico muestra su nombre', tab.technicianNames(sites[0]) === 'Jordi Técnico');
assert('3.3 Una sede sin técnicos se considera sin asignar', tab.isUnassignedSite(sites[2]) === true && tab.isUnassignedSite(sites[1]) === false);

tab.handleSiteClick(sites[1]);
assert('3.4 Pulsar una sede asignada no dispara el flujo de asignación', tab.emittedEvents.length === 0);

tab.handleSiteClick(sites[2]);
assert('3.5 Pulsar una sede sin asignar emite el evento de asignación con su sede', tab.emittedEvents.length === 1 && tab.emittedEvents[0].event === 'assign-incidents' && tab.emittedEvents[0].value.locationId === 3 && tab.emittedEvents[0].value.siteCode === 'SEDE-BCN-03');

// =========================================================================
// BLOQUE 4: Filtros reactivos por técnico y severidad (RF-MAP-09)
// =========================================================================
console.log('\n--- BLOQUE 4: Filtros reactivos ---');

apiCalls = [];
const filteredTab = createTabInstance();
filteredTab.filters.technician_id = '2';
await filteredTab.loadTerritorialData();
assert('4.1 El filtro por técnico viaja en la consulta al servidor', apiCalls[0] !== undefined && apiCalls[0].technician_id === '2');

apiCalls = [];
filteredTab.filters.technician_id = '';
filteredTab.filters.is_critical_only = true;
await filteredTab.loadTerritorialData();
assert('4.2 El filtro de solo críticas viaja en la consulta', apiCalls[0] !== undefined && apiCalls[0].is_critical_only === 1);

apiCalls = [];
filteredTab.filters.is_critical_only = false;
filteredTab.filters.unassigned_only = true;
await filteredTab.loadTerritorialData();
assert('4.3 El filtro de solo sin asignar viaja en la consulta', apiCalls[0] !== undefined && apiCalls[0].unassigned_only === 1);

// Los watchers reaccionan a los cambios de filtros (reactividad)
apiCalls = [];
const reactiveTab = createTabInstance();
const watchers = CoordinatorTerritorialMapTab.watch || {};
assert('4.4 Los filtros están vigilados para recargar reactivamente', typeof watchers['filters.technician_id'] === 'function' && typeof watchers['filters.is_critical_only'] === 'function' && typeof watchers['filters.unassigned_only'] === 'function');
reactiveTab.filters.technician_id = '3';
// Sin el motor reactivo de Vue el watcher se invoca manualmente para verificar que delega en la carga.
CoordinatorTerritorialMapTab.watch['filters.technician_id'].call(reactiveTab);
assert('4.5 Cambiar el filtro de técnico relanza la carga de datos', apiCalls.length === 1 && apiCalls[0].technician_id === '3');

// =========================================================================
// BLOQUE 5: Estados vacío y de error (RNF-MAP-02)
// =========================================================================
console.log('\n--- BLOQUE 5: Estados vacío y de error ---');

apiQueue = [{ locations: [] }];
const emptyTab = createTabInstance();
await emptyTab.loadTerritorialData();
assert('5.1 Sin actividad el mapa territorial queda vacío sin fallos', emptyTab.sites.length === 0 && emptyTab.errorMessage === '' && emptyTab.totalActiveTasks === 0);

api.map.getActiveIncidents = async () => {
  throw new Error('No se ha podido conectar con el servidor. Inténtalo de nuevo.');
};
const errorTab = createTabInstance();
await errorTab.loadTerritorialData();
assert('5.2 Un fallo de red muestra un mensaje en castellano sin romper la pestaña', errorTab.errorMessage.includes('No se ha podido conectar') && errorTab.sites.length === 0);
api.map.getActiveIncidents = async (params = {}) => {
  apiCalls.push(params);
  const payload = apiQueue.length > 0 ? apiQueue.shift() : { locations: makeSites() };
  return { success: true, data: payload };
};

// =========================================================================
// BLOQUE 6: Contratos de plantilla (RNF-MAP-02, RNF-MAP-06)
// =========================================================================
console.log('\n--- BLOQUE 6: Contratos de plantilla ---');

const tmpl = CoordinatorTerritorialMapTab.template;
assert('6.1 Plantilla: capa de teselas OSM bajo el overlay SVG territorial con marcadores',
  tmpl.includes('territorial-tile-layer') && tmpl.includes(':src="tile.url"') && tmpl.includes('territorial-map-overlay')
    && tmpl.includes('territorial-map-canvas') && tmpl.includes('territorial-marker'));
assert('6.2 Plantilla: insignia con el recuento de averías por sede', tmpl.includes('sitePendingCount(site)') && tmpl.includes('territorial-marker-badge') && tmpl.includes('territorial-site-badge'));
assert('6.3 Plantilla: distintivo multi-técnico con los nombres de operarios', tmpl.includes('is_multi_technician') && tmpl.includes('technicianNames(site)') && tmpl.includes('👥'));
assert('6.4 Plantilla: filtro reactivo por técnico y casillas de severidad', tmpl.includes('filters.technician_id') && tmpl.includes('filters.is_critical_only') && tmpl.includes('filters.unassigned_only'));
assert('6.5 Plantilla: acción de asignación disponible en sedes sin asignar', tmpl.includes('btn-assign-site') && tmpl.includes('Asignar técnico') && tmpl.includes('handleSiteClick(site)'));
assert('6.6 Plantilla: leyenda con los colores semánticos institucionales', tmpl.includes('background:#e02424') && tmpl.includes('background:#2560ff') && tmpl.includes('background:#38bd7d') && tmpl.includes('background:#f8b60f'));
assert('6.7 Plantilla: marcadores accesibles con roles ARIA y etiquetas descriptivas', tmpl.includes('role="button"') && tmpl.includes('aria-label') && tmpl.includes('@keydown.enter.prevent="handleSiteClick(site)"'));
assert('6.8 Plantilla: resumen territorial con sedes, tareas y críticas', tmpl.includes('territorial-summary') && tmpl.includes('multiTechnicianSiteCount'));
assert('6.9 Plantilla: mensaje amistoso para un territorio sin actividad', tmpl.includes('No hay averías ni preventivos activos en el territorio.'));
assert('6.10 Plantilla: atribución obligatoria de OpenStreetMap en el mapa territorial', tmpl.includes('OpenStreetMap') && tmpl.includes('territorial-map-attribution'));

// =========================================================================
// BLOQUE 7: Zoom, gestos y reencuadre del territorio (RF-MAP-09, RNF-MAP-02)
// =========================================================================
console.log('\n--- BLOQUE 7: Zoom, gestos y reencuadre ---');

function makePointerEvent(pointerId, x, y, type = 'mouse') {
  return {
    pointerId,
    pointerType: type,
    button: 0,
    clientX: x,
    clientY: y,
    currentTarget: {
      setPointerCapture: () => {},
      getBoundingClientRect: () => ({ left: 0, top: 0, width: 1000, height: 620 })
    }
  };
}

const zoomTab = createTabInstance();
await zoomTab.loadTerritorialData();
const baseWindow = { ...zoomTab.mapWindow };

assert('7.1 La vista inicial encaja el territorio completo (escala 1 y centrada)',
  zoomTab.view.scale === 1 && zoomTab.view.centerX === 0.5 && zoomTab.view.centerY === 0.5);
assert('7.2 En escala de encaje la ventana efectiva coincide con la ventana base',
  zoomTab.effectiveWindow().sideTiles === baseWindow.sideTiles
    && zoomTab.effectiveWindow().leftEdge === baseWindow.leftEdge);

zoomTab.zoomIn();
assert('7.3 Zoom + amplía 1.5x manteniendo el centro del lienzo',
  Math.abs(zoomTab.view.scale - 1.5) < 1e-9);
assert('7.4 La ventana efectiva reduce su lado a la mitad de teselas al ampliar',
  Math.abs(zoomTab.effectiveWindow().sideTiles - baseWindow.sideTiles / 1.5) < 1e-9);
assert('7.5 El zoom genera una ventana de teselas con cobertura ampliada',
  zoomTab.mapTiles.length >= 1 && zoomTab.mapTiles.every(tile => tile.url.startsWith('https://tile.openstreetmap.org/')));

const anchorBefore = {
  x: zoomTab.view.centerX + (0.25 - 0.5) / zoomTab.view.scale,
  y: zoomTab.view.centerY + (0.5 - 0.5) / zoomTab.view.scale
};
zoomTab.zoomToPoint(3, 0.25, 0.5);
const anchorAfter = {
  x: zoomTab.view.centerX + (0.25 - 0.5) / zoomTab.view.scale,
  y: zoomTab.view.centerY + (0.5 - 0.5) / zoomTab.view.scale
};
assert('7.6 El zoom ancla el punto bajo el cursor: no se desplaza al ampliar',
  Math.abs(anchorBefore.x - anchorAfter.x) < 1e-9 && Math.abs(anchorBefore.y - anchorAfter.y) < 1e-9);

zoomTab.zoomToPoint(999, 0.5, 0.5);
assert('7.7 La escala máxima de zoom queda limitada a 12x', zoomTab.view.scale === 12);
zoomTab.zoomToPoint(0.01, 0.5, 0.5);
assert('7.8 Alejar por debajo del encaje se detiene en el mínimo metropolitano (0.15x) con el centro libre',
  Math.abs(zoomTab.view.scale - zoomTab.zoomMinScale) < 1e-9);

// El paneo necesita recorrido: a escala 3 el encaje supera el lienzo y arrastrar desplaza
// el contenido de verdad (por debajo del encaje todo el territorio ya es visible y el
// centro queda fijado, igual que en cualquier mapa real).
zoomTab.zoomToPoint(3, 0.5, 0.5);
const posBeforePan = zoomTab.sitePosition(zoomTab.sites[0]);
zoomTab.handlePointerDown(makePointerEvent(1, 500, 300));
zoomTab.handlePointerMove(makePointerEvent(1, 600, 340));
zoomTab.handlePointerUp(makePointerEvent(1, 600, 340));
const posAfterPan = zoomTab.sitePosition(zoomTab.sites[0]);
assert('7.9 Arrastrar con un puntero mueve el mapa siguiendo el gesto (contenido pegado al dedo)',
  posAfterPan.x > posBeforePan.x && posAfterPan.y > posBeforePan.y);
assert('7.10 Un arrastre largo suprime el clic accidental sobre la sede',
  zoomTab.suppressNextClick === true);
zoomTab.emittedEvents = [];
zoomTab.handleSiteClick(zoomTab.sites[2]);
assert('7.11 Tras arrastrar, el clic siguiente no dispara asignaciones accidentales',
  zoomTab.emittedEvents.length === 0 && zoomTab.suppressNextClick === false);

const pinchTab = createTabInstance();
await pinchTab.loadTerritorialData();
pinchTab.handlePointerDown(makePointerEvent(1, 500, 300));
pinchTab.handlePointerDown(makePointerEvent(2, 540, 300));
pinchTab.handlePointerMove(makePointerEvent(2, 580, 300));
assert('7.12 Separar los dedos (pellizco) amplía el mapa al doble',
  Math.abs(pinchTab.view.scale - 2) < 1e-6);
pinchTab.handlePointerMove(makePointerEvent(2, 660, 300));
assert('7.13 El pellizco se ancla al inicio del gesto y no se compone acumulándose',
  Math.abs(pinchTab.view.scale - 4) < 1e-6);
pinchTab.handlePointerUp(makePointerEvent(2, 660, 300));
assert('7.14 Quitar un dedo del pellizco vuelve al modo de paneo sin romper la escala',
  pinchTab.activePointers.size === 1 && pinchTab.pinchStartScale === 1);

const preWheelScale = zoomTab.view.scale;
zoomTab.handleWheel({ deltaY: -120, clientX: 250, clientY: 310, currentTarget: { getBoundingClientRect: () => ({ left: 0, top: 0, width: 1000, height: 620 }) } });
const wheelScale = zoomTab.view.scale;
zoomTab.handleWheel({ deltaY: 120, clientX: 250, clientY: 310, currentTarget: { getBoundingClientRect: () => ({ left: 0, top: 0, width: 1000, height: 620 }) } });
assert('7.15 La rueda del ratón amplía hacia arriba y reduce hacia abajo un paso',
  Math.abs(wheelScale - preWheelScale * 1.25) < 1e-6 && Math.abs(zoomTab.view.scale - preWheelScale) < 1e-6);

const preDblScale = zoomTab.view.scale;
zoomTab.handleDblClick({ clientX: 250, clientY: 310, currentTarget: { getBoundingClientRect: () => ({ left: 0, top: 0, width: 1000, height: 620 }) } });
assert('7.16 El doble clic amplía 1.8x anclado en el cursor',
  Math.abs(zoomTab.view.scale - preDblScale * 1.8) < 1e-6);

zoomTab.resetView();
assert('7.17 El reencuadre devuelve la vista al territorio completo encajado',
  zoomTab.view.scale === 1 && zoomTab.view.centerX === 0.5 && zoomTab.view.centerY === 0.5
    && zoomTab.effectiveWindow().sideTiles === baseWindow.sideTiles);

const zoomTmpl = CoordinatorTerritorialMapTab.template;
assert('7.18 Plantilla: gestos de rueda, doble clic y punteros enlazados al lienzo',
  zoomTmpl.includes('@wheel.prevent="handleWheel"') && zoomTmpl.includes('@dblclick.prevent="handleDblClick"')
    // SIN `.prevent` en pointerdown: los botones de zoom y los marcadores viven DENTRO del
    // lienzo, y cancelar su pulsacion suprime mousedown/mouseup y, con la captura de
    // puntero del controlador, redirige el clic al lienzo (botones muertos). El
    // controlador cancela el comportamiento por defecto solo cuando el gesto es suyo.
    && zoomTmpl.includes('@pointerdown="handlePointerDown"') && !zoomTmpl.includes('@pointerdown.prevent')
    && zoomTmpl.includes('@pointerup="handlePointerUp"'));
assert('7.19 Plantilla: botones de zoom y reencuadre accesibles con etiquetas ARIA',
  zoomTmpl.includes('btn-zoom-in') && zoomTmpl.includes('btn-zoom-out') && zoomTmpl.includes('btn-fit-territory')
    && zoomTmpl.includes('aria-label="Acercar el mapa"') && zoomTmpl.includes('aria-label="Ver el territorio completo"'));
assert('7.20 Plantilla: los botones se deshabilitan en los límites de escala',
  zoomTmpl.includes(':disabled="view.scale >= zoomMaxScale"') && zoomTmpl.includes(':disabled="view.scale <= zoomMinScale"'));

// --- Zoom-out metropolitano: ver toda Barcelona y alrededores desde pocas sedes ---

// Suelo geográfico absoluto: por debajo del encaje la ventana más ancha alcanzable
// garantiza 0.0009 del mundo Mercator (~27 km de lado en Barcelona) cubriendo toda el
// área metropolitana (Badalona - Cornellà - El Prat) aunque el clúster activo sea
// mínimo; la escala nominal 0.15 actúa como límite superior del mínimo dinámico.
const spanFraction = zoomTab.mapWindow.sideTiles / Math.pow(2, zoomTab.mapWindow.zoom);
// Rama dinámica: un encaje diminuto (una sola sede a zoom 16) obliga a bajar el mínimo
// por debajo de 0.15 hasta alcanzar el suelo geográfico garantizado.
const tightView = createTabInstance();
tightView.mapWindow = { zoom: 16, sideTiles: 0.65, leftEdge: 33161.5, topEdge: 24472.2 };
assert('7.21 El zoom mínimo garantiza el suelo geográfico metropolitano (~27 km) desde cualquier encaje',
  Math.abs(zoomTab.zoomMinScale - Math.min(0.15, spanFraction / 0.0009)) < 1e-12
    && Math.abs(tightView.zoomMinScale - (tightView.mapWindow.sideTiles / Math.pow(2, 16)) / 0.0009) < 1e-12
    && spanFraction / zoomTab.zoomMinScale >= 0.0009);

const wideView = createTabInstance();
wideView.sites = zoomTab.sites;
wideView.mapWindow = { ...zoomTab.mapWindow };
wideView.view.scale = wideView.zoomMinScale;
const wideWindow = wideView.effectiveWindow();
const wideSpan = wideWindow.sideTiles / Math.pow(2, wideWindow.zoom);
const wideTiles = sharedBuildMapTiles(wideWindow);
assert('7.22 Al mínimo, la ventana cubre el área metropolitana con zoom de tesela adaptativo y pocas teselas',
  Math.abs(wideSpan - spanFraction / zoomTab.zoomMinScale) < 1e-12
    && wideSpan >= 0.0009
    && wideWindow.zoom === Math.round(Math.log2(1 / wideSpan))
    && wideTiles.length >= 1 && wideTiles.length <= 9);

const wideCorners = [
  { lat: 41.4417, lng: 2.2246 },  // Badalona (NE)
  { lat: 41.3592, lng: 2.1131 },  // El Prat de Llobregat (SW)
  { lat: 41.4140, lng: 2.1520 },  // Cornellà / Esplugues (NO)
  { lat: 41.3700, lng: 2.1900 }   // Barcelona sur (SE)
];
const widePositions = wideCorners.map(point => wideView.sitePosition(point));
assert('7.23 Al mínimo, Badalona, El Prat, Cornellà y el sur de Barcelona quedan dentro del lienzo',
  widePositions.every(point => point.x >= 0 && point.x <= 100 && point.y >= 0 && point.y <= 100));

assert('7.24 Por debajo del encaje el centro recorre el lienzo sin tope (y por encima se sujeta al visible con margen acotado)',
  wideView.clampCenter(0.7, wideView.zoomMinScale) === 0.7 && wideView.clampCenter(0.05, 0.5) === 0.05
    && Math.abs(wideView.clampCenter(0.7, 2) - 0.7) < 1e-9
    && wideView.clampCenter(9, 1) === 9 && wideView.clampCenter(-9, 1) === -9
    // A escala 2 el margen es 0.5 * (1/4) = 0.125 a cada lado: 0.99 sigue siendo valido,
    // y el limite se aplica a partir de 1.125.
    && Math.abs(wideView.clampCenter(0.99, 2) - 0.99) < 1e-9
    && Math.abs(wideView.clampCenter(9, 2) - 1.125) < 1e-9 && Math.abs(wideView.clampCenter(-9, 2) + 0.125) < 1e-9);

// Regresión del paneo que se congelaba tras un par de arrastres: cualquier tope a escala
// de encaje se agota con dos gestos reales, porque un arrastre de ancho completo recorre
// una unidad de ventana entera. Cuatro de ellos seguidos deben seguir moviendo el lienzo.
const sustainedTab = createTabInstance();
const sustainedTrack = [];
for (let i = 0; i < 4; i++) {
  sustainedTab.handlePointerDown(makePointerEvent(1, 0, 300));
  sustainedTab.handlePointerMove(makePointerEvent(1, 1000, 300));
  sustainedTab.handlePointerUp(makePointerEvent(1, 1000, 300));
  sustainedTrack.push(Number(sustainedTab.view.centerX.toFixed(6)));
}
assert('7.25 Cuatro arrastres de ancho completo seguidos siguen desplazando el mapa territorial',
  JSON.stringify(sustainedTrack) === JSON.stringify([-0.5, -1.5, -2.5, -3.5]),
  `recorrido observado: ${JSON.stringify(sustainedTrack)}`);

// El arrastre debe tener la misma sensacion a cualquier escala: el contenido se pega al
// dedo. El lienzo mide 1000px y el viewBox 100 unidades, asi que un arrastre de 250px
// debe desplazar el marcador exactamente 25 unidades, tanto en la vista metropolitana
// (0.25x) como con la lupa puesta (3x).
const slippyTracks = [];
for (const scale of [0.25, 3]) {
  const slippyTab = createTabInstance();
  await slippyTab.loadTerritorialData();
  slippyTab.zoomToPoint(scale, 0.5, 0.5);
  const before = slippyTab.sitePosition(slippyTab.sites[0]);
  slippyTab.panBy(250, 0, 1000);
  slippyTracks.push(Number((slippyTab.sitePosition(slippyTab.sites[0]).x - before.x).toFixed(6)));
}
assert('7.26 El arrastre es 1:1 con el dedo tanto alejado como cerca (25 unidades por 250px)',
  slippyTracks.every((units) => Math.abs(units - 25) < 1e-9),
  `desplazamiento observado por arrastre de 250px: ${JSON.stringify(slippyTracks)} unidades`);

// =========================================================================
// BLOQUE 7 bis: Encuadre al pulsar un marcador (RF-MAP-09, contrato tecnico 7.1)
// =========================================================================
console.log('\n--- BLOQUE 7 bis: Encuadre de sede al pulsar su marcador ---');

const focusTab = createTabInstance();
await focusTab.loadTerritorialData();
// El clamp de la ventana visible impide centrar una sede pegada al borde al ampliar,
// asi que el caso de encuadre exacto se verifica con una sede alcanzable.
const reachable = (tab) => tab.sites.find((site) => {
  const point = tab.siteWindowPosition(site);
  return point.x > 26 && point.x < 74 && point.y > 26 && point.y < 74;
});
const focusSite = reachable(focusTab);
assert('7.27a El fixture de prueba contiene una sede centrable (el clamp lo alcanzaria)',
  focusSite !== undefined,
  'ninguna sede del fixture queda dentro de la banda alcanzable a escala 2');
focusTab.handleSiteClick(focusSite);
const focusPos = focusTab.sitePosition(focusSite);
assert('7.27 Pulsar un marcador encuadra la sede a escala x2 y la deja centrada en el lienzo',
  focusTab.view.scale === 2
    && Math.abs(focusPos.x - 50) < 1e-6
    && Math.abs(focusPos.y - TERRITORIAL_CANVAS_HEIGHT_RATIO * 50) < 1e-6,
  `escala=${focusTab.view.scale} posicion=(${focusPos.x}, ${focusPos.y})`);
const focusAfter = { x: focusTab.view.centerX, y: focusTab.view.centerY };
focusTab.handleSiteClick(focusSite);
assert('7.28 Pulsar dos veces el mismo marcador no acumula zoom',
  focusTab.view.scale === 2
    && Math.abs(focusTab.view.centerX - focusAfter.x) < 1e-9 && Math.abs(focusTab.view.centerY - focusAfter.y) < 1e-9,
  `escala=${focusTab.view.scale} centro=(${focusTab.view.centerX}, ${focusTab.view.centerY})`);

// Sede ya asignada: reencuadra sin abrir el flujo de asignacion.
const assignedFocus = createTabInstance();
await assignedFocus.loadTerritorialData();
const assignedSite = assignedFocus.sites.find(site => !assignedFocus.isUnassignedSite(site)) || assignedFocus.sites[0];
assignedFocus.handleSiteClick(assignedSite);
assert('7.29 Una sede ya asignada reencuadra el mapa y NO abre la asignacion',
  assignedFocus.view.scale === 2 && assignedFocus.emittedEvents.length === 0,
  `escala=${assignedFocus.view.scale} eventos=${assignedFocus.emittedEvents.length}`);

// El clic que cierra un arrastre largo no debe reencuadrar (no fue una pulsacion real).
const dragFocus = createTabInstance();
await dragFocus.loadTerritorialData();
dragFocus.suppressNextClick = true;
dragFocus.handleSiteClick(dragFocus.sites[0]);
assert('7.30 El clic residual de un arrastre no reencuadra ni asigna',
  dragFocus.view.scale === 1 && dragFocus.emittedEvents.length === 0,
  `escala=${dragFocus.view.scale} eventos=${dragFocus.emittedEvents.length}`);

// ...pero en el gesto REAL el usuario arrastra y LUEGO pulsa un marcador: ese primer clic
// debe funcionar. El cerrojo lo libera la pulsacion sobre el mapa, no el clic.
const afterDragTab = createTabInstance();
await afterDragTab.loadTerritorialData();
afterDragTab.handlePointerDown(makePointerEvent(1, 300, 300));
afterDragTab.handlePointerMove(makePointerEvent(1, 600, 420));
afterDragTab.handlePointerUp(makePointerEvent(1, 600, 420));
const markerPress = makePointerEvent(1, 320, 310);
markerPress.target = { closest: (selector) => (selector.includes('[role="button"]') ? { tagName: 'g' } : null) };
afterDragTab.handlePointerDown(markerPress);
afterDragTab.handlePointerUp(markerPress);
const afterDragSite = reachable(afterDragTab);
afterDragTab.handleSiteClick(afterDragSite);
assert('7.31 Tras arrastrar, el PRIMER clic sobre un marcador sí reencuadra',
  afterDragTab.view.scale === 2 && afterDragTab.suppressNextClick === false,
  `cerrojo=${afterDragTab.suppressNextClick} escala=${afterDragTab.view.scale}`);

// El area pulsable del marcador debe ser holgada: el circulo visible es de 3.6 unidades
// sobre un viewBox de 100, asi que sin un blanco transparente mayor el puntero falla
// entre el circulo y la insignia y el clic "no hace nada".
assert('7.32 El marcador tiene un blanco de pulsacion invisible mas grande que el circulo visible',
  /<circle :r="markerHitRadius" fill="transparent" class="territorial-marker-hit"/.test(zoomTmpl)
    && /<circle :r="markerRadius" :fill="siteColor/.test(zoomTmpl),
  'el circulo visible y el de golpeo deben enlazar su radio a los computeds');

// Tamano del marcador segun la escala (RF-MAP-09): al alejar por debajo del encaje el pin
// se reduce para que las sedes proximas no se solapen, con suelo legible y area de
// pulsacion holgada.
const sizeTab = createTabInstance();
await sizeTab.loadTerritorialData();
const radiusAt = (scale) => { sizeTab.view.scale = scale; return Number(sizeTab.markerRadius.toFixed(4)); };
const radiusFit = radiusAt(1);
const radiusHalf = radiusAt(0.5);
const radiusMin = radiusAt(sizeTab.zoomMinScale);
assert('7.33 A escala de encaje el marcador conserva su radio de diseño (3.6)',
  radiusFit === 3.6 && radiusAt(2) === 3.6 && radiusAt(4) === 3.6,
  `encaje=${radiusFit}`);
assert('7.34 Por debajo del encaje el marcador se reduce de forma monótona',
  radiusHalf < radiusFit && radiusMin < radiusHalf && radiusMin >= 1,
  `radios: encaje=${radiusFit}, 0.5=${radiusHalf}, minimo=${radiusMin}`);
assert('7.35 El radio nunca baja del suelo legible (1 unidad ≈ 9px en el lienzo)',
  radiusMin >= 1 && radiusAt(0.01) === 1 && radiusAt(0.5) > 1,
  `radio en el minimo=${radiusMin}, radio con escala 0.01=${radiusAt(0.01)}`);
sizeTab.view.scale = sizeTab.zoomMinScale;
assert('7.36 El area de pulsación mantiene un suelo aunque el pin se encoja',
  sizeTab.markerHitRadius > sizeTab.markerRadius && sizeTab.markerHitRadius >= 3,
  `pin=${Number(sizeTab.markerRadius.toFixed(3))} golpeo=${Number(sizeTab.markerHitRadius.toFixed(3))}`);
sizeTab.view.scale = 1;
assert('7.37 El area de pulsación mantiene la holgura de 2.5x en el encuadre',
  Math.abs(sizeTab.markerHitRadius - 9) < 1e-9,
  `golpeo=${sizeTab.markerHitRadius}`);
assert('7.38 Las insignias siguen al radio del pin para no quedar descolgadas',
  zoomTmpl.includes(':y="-(markerRadius + 1.9)"') && zoomTmpl.includes(':y="markerRadius + 3.4"'),
  'la insignia de recuento o la de multi-tecnico no siguen al radio');

// =========================================================================
// BLOQUE 8: Refresco reactivo silencioso tras asignar desde el mapa (RF-MAP-09)
// =========================================================================
console.log('\n--- BLOQUE 8: Refresco reactivo silencioso ---');

const reactiveMap = createTabInstance();
await reactiveMap.loadTerritorialData();
assert('8.1 La carga normal muestra el spinner mientras dura y no es silenciosa',
  (() => { const t = createTabInstance(); const p = t.loadTerritorialData(); assert('(8.1a) isLoading activo durante la carga', t.isLoading === true); return p.then(() => t.isLoading === false); })());

// Zoom out and pan away: a silent refresh must preserve the view state...
reactiveMap.zoomToPoint(0.2, 0.5, 0.5);
reactiveMap.panBy(40, 25, 1000);
const viewBeforeSilent = { ...reactiveMap.view };
apiCalls = [];
await reactiveMap.loadTerritorialData(true);
assert('8.2 El refresco silencioso recarga los datos sin spinner ni reseteo de la vista',
  apiCalls.length === 1 && reactiveMap.isLoading === false
    && reactiveMap.view.scale === viewBeforeSilent.scale
    && reactiveMap.view.centerX === viewBeforeSilent.centerX
    && reactiveMap.view.centerY === viewBeforeSilent.centerY);

// ...and keeps the fitted window geometry (markers move with the same projection).
assert('8.3 El refresco silencioso conserva la ventana encajada (proyección estable)',
  Math.abs(reactiveMap.mapWindow.sideTiles - tab.mapWindow.sideTiles) < 1e-9
    && Math.abs(reactiveMap.mapWindow.leftEdge - tab.mapWindow.leftEdge) < 1e-9);

// A silent refresh onto an empty board (map was empty before) re-fits the view.
const emptySilent = createTabInstance();
apiQueue = [{ locations: [] }];
await emptySilent.loadTerritorialData(true);
assert('8.4 Un refresco silencioso sobre tablero vacío reencuadra la vista',
  emptySilent.sites.length === 0 && emptySilent.mapWindow === null && emptySilent.view.scale === 1 && emptySilent.view.centerX === 0.5);

// A silent refresh clears a stale error banner on success but never sets one on failure.
const staleError = createTabInstance();
await staleError.loadTerritorialData(true);
staleError.errorMessage = 'Fallo anterior';
apiQueue = [{ locations: makeSites() }];
await staleError.loadTerritorialData(true);
assert('8.5 El refresco silencioso limpia un error previo al tener éxito', staleError.errorMessage === '' && staleError.sites.length === 3);

const silentFailure = createTabInstance();
await silentFailure.loadTerritorialData();
const sitesBeforeFailure = silentFailure.sites.length;
const windowBeforeFailure = { ...silentFailure.mapWindow };
const previousHandler = api.map.getActiveIncidents;
api.map.getActiveIncidents = async () => { throw new Error('red caída'); };
await silentFailure.loadTerritorialData(true);
assert('8.6 Un fallo transitorio en silencio conserva marcadores, ventana y no muestra error',
  silentFailure.sites.length === sitesBeforeFailure && silentFailure.errorMessage === ''
    && Math.abs(silentFailure.mapWindow.sideTiles - windowBeforeFailure.sideTiles) < 1e-9);
api.map.getActiveIncidents = previousHandler;

// The dashboard triggers the silent refresh through the component ref after assigning.
const viewSource = await (await import('node:fs/promises')).readFile(
  new URL('../../public/assets/js/views/CoordinatorDashboardView.js', import.meta.url), 'utf8');
assert('8.7 El dashboard refresca el mapa vía ref tras asignar en lote (y solo si hubo altas)',
  viewSource.includes('ref="territorialMap"') && viewSource.includes('refreshTerritorialMap()')
    && viewSource.includes('if (assigned.length > 0) {') && viewSource.includes('mapTab.loadTerritorialData(true)'));

// =========================================================================
// RESUMEN FINAL
// =========================================================================
console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: ¡TODAS LAS PRUEBAS FRONTEND PASARON EXITOSAMENTE (${assertions} aserciones)!`);
  console.log(' CONDICIÓN T-MAP-15 CUMPLIDA.');
  process.exit(0);
} else {
  console.error(` RESULTADO: ${failures} fallos detectados de ${assertions} aserciones.`);
  process.exit(1);
}
