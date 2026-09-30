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
import { CoordinatorTerritorialMapTab, MARKER_COLORS } from '../../public/assets/js/components/CoordinatorTerritorialMapTab.js';
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
