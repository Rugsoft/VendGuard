/**
 * VendGuard - TechnicianRouteMapModal Test Suite (TechnicianRouteMapModalTest.mjs)
 *
 * Validates the reactive behavior and contracts of the technician route map modal
 * (RF-MAP-05 to RF-MAP-08, RNF-MAP-02, RNF-MAP-03, RNF-MAP-06).
 *
 * Hecho cuando:
 * 1. Loads the ordered route with the device GPS origin and renders the interactive map.
 * 2. Renders sequential numbered circular markers with the semantic institutional colors.
 * 3. Syncs marker taps and textual list selection with the stop summary sheet.
 * 4. Opens universal Google Maps navigation per stop and the full route with waypoints,
 *    warning when the waypoint limit truncates the sequence.
 * 5. Falls back transparently to Base Central with an informational notice when the GPS
 *    is denied, unavailable, hangs (own watchdog for embedded webviews) or fails, and
 *    shows the friendly empty-route message.
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
  location: { search: '', href: 'http://localhost/' },
  open: null
};

import { api } from '../../public/assets/js/api.js';
import { TechnicianRouteMapModal, STOP_COLORS, Mercator } from '../../public/assets/js/components/TechnicianRouteMapModal.js';

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
console.log(' VendGuard: Frontend Test Suite - TechnicianRouteMapModal (T-MAP-13)');
console.log('======================================================================\n');

// Fixtures de prueba (contrato specs/technical/route_map_contracts.md 4.1.1)
const GPS_ORIGIN = {
  source: 'GPS',
  latitude: 41.3887901,
  longitude: 2.158992,
  address: 'Ubicación actual del técnico (GPS móvil)'
};

const BASE_CENTRAL_ORIGIN = {
  source: 'BASE_CENTRAL',
  latitude: 41.3935,
  longitude: 2.189,
  address: 'Base Central VendGuard Barcelona'
};

const makeStops = () => ([
  {
    order: 1,
    location: { id: 1, site_code: 'SEDE-BCN-01', name: 'Hospital del Mar - Edificio Central', address: 'Passeig Marítim 25, Barcelona', latitude: 41.385312, longitude: 2.193245 },
    status: 'IN_PROGRESS',
    priority: 'ORDINARY',
    is_critical: false,
    is_preventive_only: false,
    total_tasks: 2,
    completed_tasks: 1,
    distance_from_previous_km: 2.85,
    navigation_url: 'https://www.google.com/maps/dir/?api=1&destination=41.385312,2.193245&travelmode=driving',
    tasks: [
      { type: 'CORRECTIVE', id: 101, machine_code: 'VEND-0101', status: 'IN_PROGRESS', urgency: 'HIGH', is_critical: false, sla_due_at: null },
      { type: 'PREVENTIVE', id: 501, machine_code: 'VEND-0102', status: 'ASSIGNED', urgency: 'MEDIUM', is_critical: false, sla_due_at: null }
    ]
  },
  {
    order: 2,
    location: { id: 2, site_code: 'SEDE-BCN-02', name: 'Torre Glòries - Planta 4 Oficinas', address: 'Avinguda Diagonal 211, Barcelona', latitude: 41.403629, longitude: 2.189512 },
    status: 'PENDING',
    priority: 'CRITICAL',
    is_critical: true,
    is_preventive_only: false,
    total_tasks: 1,
    completed_tasks: 0,
    distance_from_previous_km: 2.05,
    navigation_url: 'https://www.google.com/maps/dir/?api=1&destination=41.403629,2.189512&travelmode=driving',
    tasks: [
      { type: 'CORRECTIVE', id: 105, machine_code: 'VEND-0201', machine_type: 'PERISHABLE_FOOD', status: 'ASSIGNED', urgency: 'CRITICAL', is_critical: true, sla_due_at: '2026-09-30T14:30:00+02:00' }
    ]
  },
  {
    order: 3,
    location: { id: 3, site_code: 'SEDE-BCN-03', name: 'Centro Comercial Diagonal', address: 'Avinguda Diagonal 3, Barcelona', latitude: 41.392, longitude: 2.164 },
    status: 'PENDING',
    priority: 'ORDINARY',
    is_critical: false,
    is_preventive_only: false,
    total_tasks: 1,
    completed_tasks: 0,
    distance_from_previous_km: 1.9,
    navigation_url: 'https://www.google.com/maps/dir/?api=1&destination=41.392,2.164&travelmode=driving',
    tasks: [
      { type: 'CORRECTIVE', id: 108, machine_code: 'VEND-0301', machine_type: 'SNACKS', status: 'ASSIGNED', urgency: 'MEDIUM', is_critical: false, sla_due_at: null }
    ]
  },
  {
    order: 4,
    location: { id: 4, site_code: 'SEDE-BCN-04', name: 'Oficinas Meridiana', address: 'Carrer de la Meridiana 20, Barcelona', latitude: 41.412, longitude: 2.176 },
    status: 'PENDING',
    priority: 'ORDINARY',
    is_critical: false,
    is_preventive_only: true,
    total_tasks: 1,
    completed_tasks: 0,
    distance_from_previous_km: 2.4,
    navigation_url: 'https://www.google.com/maps/dir/?api=1&destination=41.412,2.176&travelmode=driving',
    tasks: [
      { type: 'PREVENTIVE', id: 601, machine_code: 'VEND-0401', machine_type: 'HOT_DRINKS', status: 'ASSIGNED', urgency: 'LOW', is_critical: false, sla_due_at: null }
    ]
  },
  {
    order: 5,
    location: { id: 5, site_code: 'SEDE-BCN-05', name: 'Polideportivo Besòs', address: 'Carrer d\'Alfons el Savi 12, Barcelona', latitude: 41.418, longitude: 2.208 },
    status: 'COMPLETED',
    priority: 'ORDINARY',
    is_critical: false,
    is_preventive_only: false,
    total_tasks: 1,
    completed_tasks: 1,
    distance_from_previous_km: null,
    navigation_url: 'https://www.google.com/maps/dir/?api=1&destination=41.418,2.208&travelmode=driving',
    tasks: [
      { type: 'CORRECTIVE', id: 110, machine_code: 'VEND-0501', status: 'RESOLVED', urgency: 'LOW', is_critical: false, sla_due_at: null }
    ]
  }
]);

const makeRoutePayload = (overrides = {}) => ({
  origin: GPS_ORIGIN,
  stops: makeStops(),
  full_route_navigation_url: 'https://www.google.com/maps/dir/?api=1&origin=41.3887901,2.158992&destination=41.418,2.208&waypoints=41.385312,2.193245%7C41.403629,2.189512%7C41.392,2.164%7C41.412,2.176&travelmode=driving',
  waypoints_truncated: false,
  summary: { total_stops: 5, total_tasks: 6, total_critical: 1, estimated_total_distance_km: 9.2 },
  ...overrides
});

// Mock de entorno navegador para Node.js ESM
function setGeolocation(behavior) {
  const geo = behavior === 'none'
    ? undefined
    : {
        getCurrentPosition: (success, failure) => {
          if (behavior === 'granted') {
            success({ coords: { latitude: GPS_ORIGIN.latitude, longitude: GPS_ORIGIN.longitude } });
          } else if (behavior === 'hang') {
            // Embedded webviews: no callback is ever invoked
          } else if (behavior === 'late') {
            // The browser answers only after the app watchdog has already given up
            setTimeout(() => success({ coords: { latitude: GPS_ORIGIN.latitude, longitude: GPS_ORIGIN.longitude } }), 200);
          } else {
            failure({ code: 1, message: 'User denied Geolocation' });
          }
        }
      };
  const descriptor = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
  if (descriptor && descriptor.configurable === false) {
    throw new Error('navigator is not configurable in this runtime');
  }
  Object.defineProperty(globalThis, 'navigator', {
    value: geo === undefined ? {} : { geolocation: geo },
    configurable: true,
    writable: true
  });
}

function createModalInstance() {
  const comp = Object.assign({}, TechnicianRouteMapModal);
  const data = comp.data();
  const instance = Object.assign(data, comp.methods);
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

// Captura de navegación externa
let openedUrls = [];
globalThis.window.open = (url) => {
  openedUrls.push(url);
  return null;
};

// Captura de llamadas a la API
let getRouteMapCalls = [];
let routeMapQueue = [];
api.technician.getRouteMap = async (origin = {}) => {
  getRouteMapCalls.push(origin);
  const payload = routeMapQueue.length > 0 ? routeMapQueue.shift() : makeRoutePayload();
  return { success: true, data: payload };
};

// =========================================================================
// BLOQUE 1: Carga de la ruta con GPS del dispositivo (RF-MAP-06)
// =========================================================================
console.log('--- BLOQUE 1: Carga de la ruta con GPS del dispositivo ---');

setGeolocation('granted');
const modal1 = createModalInstance();
modal1.modelValue = true;

await modal1.openMap();

assert('1.1 Solicita los permisos de geolocalización al abrir', modal1.gpsPermission === 'granted');
assert('1.2 Usa las coordenadas GPS del dispositivo como origen de la ruta',
  getRouteMapCalls[0] !== undefined
    && getRouteMapCalls[0].origin_lat === GPS_ORIGIN.latitude
    && getRouteMapCalls[0].origin_lng === GPS_ORIGIN.longitude);
assert('1.3 La ruta cargada incluye origen, paradas y resumen', modal1.origin.source === 'GPS' && modal1.stops.length === 5 && modal1.summary.total_stops === 5);
assert('1.4 Con GPS concedido no se muestra el aviso de Base Central', modal1.showBaseCentralNotice === false && modal1.gpsNotice === '');
assert('1.5 La etiqueta de origen describe la posición GPS', modal1.originLabel.includes('GPS móvil'));
assert('1.6 Sin parada seleccionada la ficha permanece cerrada', modal1.selectedStop === null && modal1.selectedStopOrder === null);

// =========================================================================
// BLOQUE 2: Marcadores numerados y colores semánticos (RF-MAP-07, RNF-MAP-06)
// =========================================================================
console.log('\n--- BLOQUE 2: Marcadores numerados y colores semánticos ---');

const stops = modal1.stops;
assert('2.1 Parada en curso usa el ámbar institucional', modal1.stopColor(stops[0]) === STOP_COLORS.inProgress && STOP_COLORS.inProgress === '#f8b60f');
assert('2.2 Parada crítica de perecederos usa el rojo de advertencia', modal1.stopColor(stops[1]) === STOP_COLORS.critical && STOP_COLORS.critical === '#e02424');
assert('2.3 Parada ordinaria usa el azul primario', modal1.stopColor(stops[2]) === STOP_COLORS.ordinary && STOP_COLORS.ordinary === '#2560ff');
assert('2.4 Parada exclusiva de preventivo usa el verde', modal1.stopColor(stops[3]) === STOP_COLORS.preventive && STOP_COLORS.preventive === '#38bd7d');
assert('2.5 Parada completada usa el gris atenuado', modal1.stopColor(stops[4]) === STOP_COLORS.completed && STOP_COLORS.completed === '#c8cfda');

const projections = stops.map(stop => modal1.markerFor(stop));
assert('2.6 Cada parada proyecta un marcador finito dentro del lienzo de teselas',
  projections.every(point => Number.isFinite(point.x) && Number.isFinite(point.y) && point.x >= 0 && point.x <= 100 && point.y >= 0 && point.y <= 100));
assert('2.7 El origen proyecta el indicador de inicio dentro del lienzo',
  Number.isFinite(modal1.originPoint().x) && Number.isFinite(modal1.originPoint().y));

// Proyección Web Mercator verificada contra valores canónicos
assert('2.10 Mercator: Greenwich cae en el centro del mundo y los polos en 0/1',
  Mercator.lngToFraction(0) === 0.5 && Mercator.lngToFraction(-180) === 0 && Mercator.lngToFraction(180) === 1);
assert('2.11 Mercator: el ecuador cae en 0.5 y los límites de latitud OSM en 0/1',
  Mercator.latToFraction(0) === 0.5
    && Math.abs(Mercator.latToFraction(85.0511)) < 1e-5
    && Math.abs(Mercator.latToFraction(-85.0511) - 1) < 1e-5);
assert('2.12 Mercator: Barcelona se proyecta en el cuadrante noreste del mundo',
  Mercator.lngToFraction(2.16) > 0.5 && Mercator.latToFraction(41.4) < 0.5);

// Ventana de teselas estándar (OpenStreetMap) que enmarca la ruta
const tiles = modal1.mapTiles;
assert('2.13 Se solicitan teselas estándar de OpenStreetMap sin clave de API',
  tiles.length >= 1 && tiles.every(tile => tile.url.startsWith('https://tile.openstreetmap.org/')));
assert('2.14 La tesela que contiene el origen existe en la ventana calculada',
  (() => {
    const zoom = modal1.mapWindow.zoom;
    const originTileX = Math.floor(Mercator.lngToWorldX(GPS_ORIGIN.longitude, zoom));
    const originTileY = Math.floor(Mercator.latToWorldY(GPS_ORIGIN.latitude, zoom));
    return tiles.some(tile => tile.key === zoom + '/' + originTileX + '/' + originTileY);
  })());
assert('2.15 Cada tesela ocupa una posición y tamaño porcentuales finitos y acotados',
  tiles.every(tile => [tile.leftPct, tile.topPct, tile.widthPct, tile.heightPct].every(value => Number.isFinite(value) && value > -200 && value < 200)));
assert('2.16 Una tesela rota se oculta sin romper los marcadores (RNF-MAP-04)',
  (() => { const ev = { target: { style: {} } }; modal1.hideTile(ev); return ev.target.style.display === 'none'; })());
assert('2.8 El progreso parcial se muestra como contador visible', modal1.progressLabel(stops[0]) === '1 de 2 completadas' && modal1.progressLabel(stops[1]) === '0 de 1 completadas');
assert('2.9 La parada completada se rotula como Completada', modal1.stopStatusLabel(stops[4]) === 'Completada' && modal1.stopStatusLabel(stops[0]) === 'En Curso');

// =========================================================================
// BLOQUE 3: Selección de parada y ficha resumen sincronizada (RF-MAP-07)
// =========================================================================
console.log('\n--- BLOQUE 3: Selección de parada y ficha resumen ---');

modal1.selectStop(stops[1]);
assert('3.1 Tocar un marcador abre la ficha de la parada correspondiente', modal1.selectedStopOrder === 2 && modal1.selectedStop.location.site_code === 'SEDE-BCN-02');
assert('3.2 La ficha resume nombre, dirección, progreso y urgencia',
  modal1.selectedStop.location.name.includes('Torre Glòries')
    && modal1.selectedStop.location.address.includes('Diagonal')
    && modal1.progressLabel(modal1.selectedStop) === '0 de 1 completadas'
    && modal1.selectedStop.is_critical === true);

modal1.selectStop(stops[0]);
assert('3.3 Seleccionar una parada de la lista resincroniza el marcador activo', modal1.selectedStopOrder === 1 && modal1.selectedStop.machineTasks !== undefined ? true : modal1.selectedStop.order === 1);
assert('3.4 Las tareas agrupadas de la sede están disponibles en la ficha', modal1.selectedStop.tasks.length === 2 && modal1.selectedStop.tasks.some(task => task.type === 'PREVENTIVE'));

modal1.clearSelection();
assert('3.5 Cerrar la ficha limpia la selección', modal1.selectedStop === null && modal1.selectedStopOrder === null);

// =========================================================================
// BLOQUE 4: Navegación GPS universal con Google Maps (RF-MAP-08)
// =========================================================================
console.log('\n--- BLOQUE 4: Navegación GPS universal (Google Maps) ---');

openedUrls = [];
modal1.selectStop(stops[1]);
modal1.navigateToStop(stops[1]);
assert('4.1 "Navegar con GPS" abre el enlace universal de la parada', openedUrls.length === 1 && openedUrls[0] === stops[1].navigation_url);
assert('4.2 El enlace fija el destino exacto de la sede con modo conducción',
  openedUrls[0].startsWith('https://www.google.com/maps/dir/?api=1&destination=41.403629,2.189512') && openedUrls[0].endsWith('travelmode=driving'));

const stopWithoutUrl = { ...stops[2], navigation_url: null };
modal1.navigateToStop(stopWithoutUrl);
assert('4.3 Sin URL del backend se construye el enlace desde las coordenadas de la sede',
  openedUrls[1] === 'https://www.google.com/maps/dir/?api=1&destination=41.392,2.164&travelmode=driving');

openedUrls = [];
modal1.navigateFullRoute();
assert('4.4 "Abrir ruta completa" concatena origen, waypoints y destino en el orden del sistema',
  openedUrls.length === 1
    && openedUrls[0].includes('origin=41.3887901,2.158992')
    && openedUrls[0].includes('waypoints=41.385312,2.193245%7C41.403629,2.189512%7C41.392,2.164%7C41.412,2.176')
    && openedUrls[0].endsWith('travelmode=driving'));
assert('4.5 Con ruta dentro del límite no se marca el truncado de waypoints', modal1.waypointsTruncated === false);

const truncatedModal = createModalInstance();
routeMapQueue = [makeRoutePayload({ waypoints_truncated: true })];
await truncatedModal.openMap();
assert('4.6 Superar el límite de waypoints activa la advertencia explicativa', truncatedModal.waypointsTruncated === true);

// =========================================================================
// BLOQUE 5: Fallback a Base Central y ruta vacía (RF-MAP-06, RNF-MAP-04)
// =========================================================================
console.log('\n--- BLOQUE 5: Fallback a Base Central y ruta vacía ---');

setGeolocation('denied');
getRouteMapCalls = [];
routeMapQueue = [makeRoutePayload({ origin: BASE_CENTRAL_ORIGIN })];
const modalDenied = createModalInstance();
await modalDenied.openMap();
assert('5.1 Denegar permisos no interrumpe la aplicación', modalDenied.errorMessage === '' && modalDenied.isLoading === false);
assert('5.2 Sin GPS se consulta la ruta sin coordenadas de dispositivo', getRouteMapCalls[0] !== undefined && getRouteMapCalls[0].origin_lat === undefined && modalDenied.gpsPermission === 'denied');
assert('5.3 La ruta se calcula desde la Base Central', modalDenied.isBaseCentralFallback === true && modalDenied.origin.source === 'BASE_CENTRAL');
assert('5.4 Se muestra el aviso informativo de fallback a Base Central', modalDenied.showBaseCentralNotice === true && modalDenied.gpsNotice.includes('Base Central'));

setGeolocation('none');
getRouteMapCalls = [];
routeMapQueue = [makeRoutePayload({ origin: BASE_CENTRAL_ORIGIN })];
const modalUnavailable = createModalInstance();
await modalUnavailable.openMap();
assert('5.5 Sin sensor GPS disponible se usa la Base Central sin error', modalUnavailable.gpsPermission === 'unavailable' && modalUnavailable.isBaseCentralFallback === true && modalUnavailable.errorMessage === '');

getRouteMapCalls = [];
routeMapQueue = [makeRoutePayload({ stops: [], full_route_navigation_url: '', summary: { total_stops: 0, total_tasks: 0, total_critical: 0, estimated_total_distance_km: 0 } })];
const emptyModal = createModalInstance();
setGeolocation('granted');
await emptyModal.openMap();
assert('5.6 Técnico sin paradas: la ruta se muestra vacía sin fallos', emptyModal.hasStops === false && emptyModal.errorMessage === '');

// Watchdog propio: algunos webviews embebidos jamás invocan los callbacks de geolocalización (RNF-MAP-04)
setGeolocation('hang');
getRouteMapCalls = [];
routeMapQueue = [makeRoutePayload({ origin: BASE_CENTRAL_ORIGIN })];
const hangingModal = createModalInstance();
hangingModal.gpsWatchdogMs = 60;
const hangStart = Date.now();
await hangingModal.openMap();
const hangElapsed = Date.now() - hangStart;
assert('5.7 Un GPS colgado no bloquea el modal: el watchdog propio rinde y carga la ruta',
  hangingModal.gpsPermission === 'unavailable' && hangingModal.isLoading === false && hangingModal.hasStops === true && hangingModal.errorMessage === '');
assert('5.8 El watchdog vence por su propio tiempo y no depende del timeout del navegador', hangElapsed >= 60 && hangElapsed < 2000);
assert('5.9 Un GPS colgado cae a Base Central con aviso informativo',
  hangingModal.isBaseCentralFallback === true && hangingModal.showBaseCentralNotice === true && hangingModal.gpsNotice.includes('Base Central'));

setGeolocation('late');
getRouteMapCalls = [];
routeMapQueue = [makeRoutePayload({ origin: BASE_CENTRAL_ORIGIN })];
const lateModal = createModalInstance();
lateModal.gpsWatchdogMs = 50;
await lateModal.openMap();
await new Promise(resolve => setTimeout(resolve, 300));
assert('5.10 Una respuesta GPS tardía tras el watchdog no corrompe el fallback ya calculado',
  lateModal.gpsPermission === 'unavailable' && lateModal.deviceCoordinates === null && lateModal.isBaseCentralFallback === true);

// =========================================================================
// BLOQUE 6: Errores, cierre y contratos de plantilla (RNF-MAP-02, RNF-MAP-06)
// =========================================================================
console.log('\n--- BLOQUE 6: Errores, cierre y contratos de plantilla ---');

// ApiClient propaga siempre errores con mensaje en castellano; se simula ese contrato real.
api.technician.getRouteMap = async () => {
  throw new Error('No se ha podido conectar con el servidor. Inténtalo de nuevo.');
};
const errorModal = createModalInstance();
await errorModal.openMap();
assert('6.1 Un fallo de red muestra un mensaje en castellano sin romper el modal ni pedir teselas', errorModal.errorMessage.includes('No se ha podido conectar') && errorModal.stops.length === 0 && errorModal.mapTiles.length === 0);
api.technician.getRouteMap = async (origin = {}) => {
  getRouteMapCalls.push(origin);
  const payload = routeMapQueue.length > 0 ? routeMapQueue.shift() : makeRoutePayload();
  return { success: true, data: payload };
};

const closableModal = createModalInstance();
closableModal.close();
assert('6.2 Cerrar el mapa emite update:modelValue a false',
  closableModal.emittedEvents.length === 1
    && closableModal.emittedEvents[0].event === 'update:modelValue'
    && closableModal.emittedEvents[0].value === false);

const tmpl = TechnicianRouteMapModal.template;
assert('6.3 Plantilla: capa de teselas estándar bajo el overlay SVG con polilínea de ruta',
  tmpl.includes('route-tile-layer') && tmpl.includes(':src="tile.url"') && tmpl.includes('route-map-overlay')
    && tmpl.includes('<polyline') && tmpl.includes('route-map-canvas')
    && TechnicianRouteMapModal.methods.buildMapTiles.toString().includes('tile.openstreetmap.org'));
assert('6.4 Plantilla: marcadores circulares accesibles por parada', tmpl.includes('route-map-marker') && tmpl.includes('aria-label') && tmpl.includes('@click="selectStop(stop)"'));
assert('6.5 Plantilla: check verde para paradas completadas', tmpl.includes('route-marker-check') && tmpl.includes('✔'));
assert('6.6 Plantilla: leyenda con los colores semánticos institucionales',
  tmpl.includes('background:#f8b60f') && tmpl.includes('background:#e02424') && tmpl.includes('background:#2560ff') && tmpl.includes('background:#38bd7d') && tmpl.includes('background:#c8cfda'));
assert('6.7 Plantilla: botones de navegación GPS y ruta completa presentes',
  tmpl.includes('Navegar con GPS') && tmpl.includes('Abrir ruta completa en Google Maps'));
assert('6.8 Plantilla: advertencia de truncado de waypoints condicionada', tmpl.includes('waypointsTruncated') && tmpl.includes('Mostrando las primeras'));
assert('6.9 Plantilla: mensaje amistoso para jornada sin paradas', tmpl.includes('No tienes paradas asignadas para la ruta de hoy.'));
assert('6.10 Plantilla: aviso de fallback a Base Central condicionado', tmpl.includes('showBaseCentralNotice') && tmpl.includes('gpsNotice'));
assert('6.11 Plantilla: distancias entre paradas visibles en la lista', tmpl.includes('distance_from_previous_km'));
assert('6.12 Plantilla: diálogo accesible con roles ARIA', tmpl.includes('role="dialog"') && tmpl.includes('aria-modal="true"') && tmpl.includes('aria-label="Cerrar mapa"'));
assert('6.13 Plantilla: atribución obligatoria de OpenStreetMap en el mapa', tmpl.includes('OpenStreetMap') && tmpl.includes('route-map-attribution'));
assert('6.14 Plantilla: el indicador de origen usa un triángulo vectorial en vez de un glifo de texto', tmpl.includes('route-origin-triangle') && !tmpl.includes('route-origin-icon'));

// =========================================================================
// RESUMEN FINAL
// =========================================================================
console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: ¡TODAS LAS PRUEBAS FRONTEND PASARON EXITOSAMENTE (${assertions} aserciones)!`);
  console.log(' CONDICIÓN T-MAP-13 CUMPLIDA.');
  process.exit(0);
} else {
  console.error(` RESULTADO: ${failures} fallos detectados de ${assertions} aserciones.`);
  process.exit(1);
}
