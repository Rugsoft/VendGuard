/**
 * VendGuard - MapZoomPan Utility Test Suite (MapZoomPanUtilTest.mjs)
 *
 * Validates the shared zoom/pan controller contract consumed by every interactive
 * Mercator canvas of the application (coordinator territorial tab, technician route
 * modal): anchored zooming, clamped panning, click suppression after drags, scale
 * bounds and the below-fit adaptive tile-zoom window derivation.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

import { mapZoomPanState, MapZoomPanMethods, MAP_ZOOM_PAN_DEFAULTS } from '../../public/assets/js/utils/MapZoomPan.js';

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
console.log(' VendGuard: Frontend Test Suite - MapZoomPan shared controller');
console.log('======================================================================\n');

/**
 * Minimal host harness exposing the controller methods over a fixed base window,
 * exactly like the real components bind them.
 */
function createHost(baseWindow, hooks = {}) {
  const host = {
    ...mapZoomPanState(),
    ...hooks,
    mapWindow: baseWindow,
    getBaseWindow() {
      return this.mapWindow;
    },
    getMinScale() {
      return typeof this.hookMin === 'function' ? this.hookMin() : MAP_ZOOM_PAN_DEFAULTS.MIN_SCALE;
    },
    getMaxScale() {
      return typeof this.hookMax === 'function' ? this.hookMax() : MAP_ZOOM_PAN_DEFAULTS.MAX_SCALE;
    }
  };
  for (const [name, fn] of Object.entries(MapZoomPanMethods)) {
    if (name !== 'getBaseWindow' && name !== 'getMinScale' && name !== 'getMaxScale') {
      host[name] = fn.bind(host);
    }
  }
  return host;
}

// Ventana encajada canónica (zoom 13, ~1.2 teselas de lado) y función de eventos puntero
const BASE = { zoom: 13, sideTiles: 1.1978752, leftEdge: 4144.9768277, topEdge: 3058.8653644 };
function makePointerEvent(pointerId, x, y, type = 'mouse') {
  return {
    pointerId,
    pointerType: type,
    button: 0,
    clientX: x,
    clientY: y,
    currentTarget: {
      setPointerCapture: () => {},
      getBoundingClientRect: () => ({ left: 0, top: 0, width: 800, height: 600 })
    }
  };
}

// -------------------------------------------------------------------------
console.log('--- Estado inicial y fábrica ---');

const factory = createHost(BASE);
assert('1.1 La fábrica expone la vista encajada inicial (escala 1, centrada)',
  factory.view.scale === 1 && factory.view.centerX === 0.5 && factory.view.centerY === 0.5);
assert('1.2 El estado de gestos arranca limpio',
  factory.isPanning === false && factory.dragDistance === 0 && factory.suppressNextClick === false
    && factory.activePointers.size === 0 && factory.pinchStartDistance === 0);
assert('1.3 Los límites por defecto son 1x..12x con factores 1.25/1.5/1.8',
  MAP_ZOOM_PAN_DEFAULTS.MIN_SCALE === 1 && MAP_ZOOM_PAN_DEFAULTS.MAX_SCALE === 12
    && MAP_ZOOM_PAN_DEFAULTS.WHEEL_FACTOR === 1.25 && MAP_ZOOM_PAN_DEFAULTS.BUTTON_FACTOR === 1.5
    && MAP_ZOOM_PAN_DEFAULTS.DBLCLICK_FACTOR === 1.8);

// -------------------------------------------------------------------------
console.log('\n--- Ventana efectiva y zoom de tesela adaptativo ---');

assert('2.1 A escala 1 la ventana efectiva es la ventana base intacta',
  (() => { const w = factory.effectiveWindow(); return w.zoom === BASE.zoom && w.sideTiles === BASE.sideTiles && w.leftEdge === BASE.leftEdge && w.topEdge === BASE.topEdge; })());

const zoomedIn = createHost(BASE);
zoomedIn.zoomToPoint(2, 0.5, 0.5);
const wIn = zoomedIn.effectiveWindow();
assert('2.2 Ampliar 2x reduce el lado de la ventana a la mitad en el mismo zoom',
  wIn.zoom === BASE.zoom && Math.abs(wIn.sideTiles - BASE.sideTiles / 2) < 1e-12);

// El zoom de tesela adaptativo solo entra por debajo del encaje: el host necesita un
// mínimo dinámico (< 1), igual que el mapa territorial con su suelo metropolitano.
const zoomedOut = createHost(BASE, { hookMin: () => 0.15 });
zoomedOut.zoomToPoint(0.25, 0.5, 0.5);
const wOut = zoomedOut.effectiveWindow();
const spanOut = wOut.sideTiles / Math.pow(2, wOut.zoom);
const baseSpan = BASE.sideTiles / Math.pow(2, BASE.zoom);
assert('2.3 Alejar 0.25x baja el zoom de tesela adaptativamente (estilo slippy-map)',
  wOut.zoom < BASE.zoom
    && Math.abs(wOut.zoom - Math.round(Math.log2(1 / (baseSpan / 0.25)))) < 1e-9
    && Math.abs(spanOut - baseSpan / 0.25) < 1e-15);
assert('2.4 La ventana más ancha mantiene un recuento acotado de teselas (<= 2x2) y zoom >= 2',
  wOut.sideTiles <= 2 && wOut.zoom >= 2);

// -------------------------------------------------------------------------
console.log('\n--- Anclaje, límites y clamp de centro ---');

const anchored = createHost(BASE);
anchored.zoomToPoint(1.5, 0.5, 0.5);
const anchorBefore = { x: anchored.view.centerX + (0.2 - 0.5) / anchored.view.scale, y: anchored.view.centerY + (0.8 - 0.5) / anchored.view.scale };
anchored.zoomToPoint(3, 0.2, 0.8);
const anchorAfter = { x: anchored.view.centerX + (0.2 - 0.5) / anchored.view.scale, y: anchored.view.centerY + (0.8 - 0.5) / anchored.view.scale };
assert('3.1 El zoom mantiene fijo el punto del lienzo bajo el ancla',
  Math.abs(anchorBefore.x - anchorAfter.x) < 1e-9 && Math.abs(anchorBefore.y - anchorAfter.y) < 1e-9);

const bounded = createHost(BASE);
bounded.zoomToPoint(999, 0.5, 0.5);
assert('3.2 La escala superior se respeta (12x)', bounded.view.scale === 12);
bounded.zoomToPoint(0.001, 0.5, 0.5);
assert('3.3 La escala inferior se respeta y reencuadra al encaje',
  bounded.view.scale === 1 && bounded.view.centerX === 0.5 && bounded.view.centerY === 0.5);

const dynamicHost = createHost(BASE, { hookMin: () => 0.2 });
dynamicHost.zoomToPoint(0.05, 0.5, 0.5);
assert('3.4 El límite inferior dinámico del componente se respeta', Math.abs(dynamicHost.view.scale - 0.2) < 1e-12);

const clamped = createHost(BASE);
clamped.zoomToPoint(2, 0.5, 0.5);
clamped.panBy(-5000, 5000, 800);
assert('3.5 El paneo queda sujeto a la ventana encajada: el centro se detiene en el borde (0.75, 0.25)',
  clamped.view.centerX === 0.75 && clamped.view.centerY === 0.25);

const belowFit = createHost(BASE);
belowFit.zoomToPoint(0.5, 0.5, 0.5);
belowFit.panBy(100, 100, 800);
assert('3.6 Por debajo del encaje el centro queda fijado en 0.5 (sin clamp invertido)',
  belowFit.view.centerX === 0.5 && belowFit.view.centerY === 0.5);

// -------------------------------------------------------------------------
console.log('\n--- Gestos: rueda, doble clic, arrastre y pellizco ---');

const gestured = createHost(BASE);
gestured.handleWheel({ deltaY: -120, clientX: 400, clientY: 300, currentTarget: { getBoundingClientRect: () => ({ left: 0, top: 0, width: 800, height: 600 }) } });
assert('4.1 La rueda hacia arriba amplía 1.25x', Math.abs(gestured.view.scale - 1.25) < 1e-12);
gestured.handleWheel({ deltaY: 120, clientX: 400, clientY: 300, currentTarget: { getBoundingClientRect: () => ({ left: 0, top: 0, width: 800, height: 600 }) } });
assert('4.2 La rueda hacia abajo reduce de vuelta', Math.abs(gestured.view.scale - 1) < 1e-12);

gestured.handleDblClick({ clientX: 400, clientY: 300, currentTarget: { getBoundingClientRect: () => ({ left: 0, top: 0, width: 800, height: 600 }) } });
assert('4.3 El doble clic amplía 1.8x', Math.abs(gestured.view.scale - 1.8) < 1e-12);
gestured.resetView();

gestured.handlePointerDown(makePointerEvent(1, 400, 300));
gestured.handlePointerMove(makePointerEvent(1, 480, 330));
gestured.handlePointerUp(makePointerEvent(1, 480, 330));
assert('4.4 Un arrastre largo activa la supresión del clic posterior', gestured.suppressNextClick === true);
gestured.handlePointerDown(makePointerEvent(1, 400, 300));
gestured.handlePointerMove(makePointerEvent(1, 402, 301));
gestured.handlePointerUp(makePointerEvent(1, 402, 301));
assert('4.5 Un movimiento corto no suprime el clic (umbral de 6px)', gestured.suppressNextClick === false);

const pincher = createHost(BASE);
pincher.handlePointerDown(makePointerEvent(1, 400, 300));
pincher.handlePointerDown(makePointerEvent(2, 440, 300));
pincher.handlePointerMove(makePointerEvent(2, 480, 300));
assert('4.6 Separar los dedos amplía al doble con anclaje al inicio del gesto', Math.abs(pincher.view.scale - 2) < 1e-9);
pincher.handlePointerMove(makePointerEvent(2, 560, 300));
assert('4.7 El pellizco no se compone: reancora contra la escala inicial', Math.abs(pincher.view.scale - 4) < 1e-9);
pincher.handlePointerUp(makePointerEvent(2, 560, 300));
assert('4.8 Quitar un dedo devuelve al paneo limpio', pincher.activePointers.size === 1 && pincher.pinchStartScale === 1);

const emptyHost = createHost(null);
emptyHost.zoomToPoint(3, 0.5, 0.5);
assert('4.9 Sin ventana base el controlador no falla ni muta la vista',
  emptyHost.view.scale === 1 && emptyHost.effectiveWindow() === null);

// -------------------------------------------------------------------------
console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: ¡TODAS LAS PRUEBAS DEL CONTROLADOR PASARON (${assertions} aserciones)!`);
  process.exit(0);
} else {
  console.error(` RESULTADO: ${failures} fallos detectados de ${assertions} aserciones.`);
  process.exit(1);
}
