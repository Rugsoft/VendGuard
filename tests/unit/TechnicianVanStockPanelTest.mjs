/**
 * VendGuard - Frontend Unit Test Suite (TechnicianVanStockPanelTest.mjs)
 *
 * Certifica el panel de stock de la furgoneta del técnico (Módulo 18, T-VAN-08, RF-04.1 y
 * RF-04.3) sin navegador, sin red y sin dependencias npm:
 *
 *  - **Contrato del módulo:** módulo ES nativo, nombre canónico y las marcas `data-testid` que
 *    anclan la suite de integración visual.
 *  - **Lectura (RF-04.1):** la carga pinta las parejas que devuelve el endpoint, la cabecera
 *    identifica la furgoneta y las tres cifras derivadas cuentan bien —incluido el negativo, que
 *    resta en el total de unidades en vez de desaparecer—.
 *  - **Insignia de bajo umbral:** solo aparece donde `is_below_threshold` es cierto; la vista no
 *    la recalcula por su cuenta.
 *  - **Estado vacío (RF-04.3):** una furgoneta sin stock declarado no es un error: se explica al
 *    técnico quién carga las cantidades.
 *  - **Error recuperable:** la carga fallida deja un aviso y permite reintentar sin recargar la
 *    vista.
 *  - **Solo lectura y mínimo privilegio (Art. V.4):** la plantilla no tiene ningún control que
 *    mute el saldo, no menciona el umbral mínimo —que RF-04.1 excluye de esta vista— ni ningún
 *    importe, y no reconstruye la etiqueta de descuadre que el servidor retiró de esta
 *    proyección.
 *  - **Deuda cromática congelada:** ni un literal hexadecimal en el componente; todo entra por
 *    token del sistema de diseño, que es lo que mantiene el techo de `DesignTokenDebtRatchetTest`.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

globalThis.localStorage = {
  _store: {},
  getItem(key) { return this._store[key] ?? null; },
  setItem(key, value) { this._store[key] = String(value); },
  removeItem(key) { delete this._store[key]; },
  clear() { this._store = {}; }
};
globalThis.window = {
  location: { search: '', href: 'http://localhost/' }
};

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { api } from '../../public/assets/js/api.js';
import { TechnicianVanStockPanel } from '../../public/assets/js/components/TechnicianVanStockPanel.js';

const SOURCE_PATH = fileURLToPath(new URL('../../public/assets/js/components/TechnicianVanStockPanel.js', import.meta.url));
const SOURCE = readFileSync(SOURCE_PATH, 'utf8');
const HEX_PATTERN = /#[0-9a-fA-F]{3,8}\b/g;

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

/** Instancia vanilla: datos + métodos propios + `computed` como getters. */
function createComponentInstance() {
  const component = Object.assign({}, TechnicianVanStockPanel);
  const instance = Object.assign(component.data(), component.methods);

  for (const [key, getter] of Object.entries(component.computed || {})) {
    Object.defineProperty(instance, key, {
      get: () => getter.call(instance),
      configurable: true
    });
  }

  return instance;
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - TechnicianVanStockPanel (T-VAN-08)');
console.log('======================================================================\n');

// =========================================================================
// BLOQUE 1: Contrato del módulo
// =========================================================================
console.log('--- BLOQUE 1: Contrato del módulo ---');

assert('1.1 Exporta el componente con su nombre canónico', TechnicianVanStockPanel.name === 'TechnicianVanStockPanel');
assert(
  '1.2 La plantilla trae las marcas de anclaje del panel, la lista y el estado vacío',
  SOURCE.includes('data-testid="tech-van-stock-panel"')
    && SOURCE.includes('data-testid="tech-van-stock-list"')
    && SOURCE.includes('data-testid="tech-van-stock-empty"')
);
assert('1.3 El componente entra sin dependencias externas', !/from\s+'[^']*node_modules/.test(SOURCE));

const panel = createComponentInstance();
assert(
  '1.4 Estado inicial limpio: sin filas, sin técnico y sin error',
  panel.items.length === 0 && panel.technicianId === null && panel.errorMessage === '' && panel.isLoading === false
);

// =========================================================================
// BLOQUE 2: Carga e indicadores derivados
// =========================================================================
console.log('\n--- BLOQUE 2: Carga e indicadores derivados (RF-04.1) ---');

const inventoryRows = [
  {
    spare_part_id: 1,
    part_code: 'VALV-ULKA-01',
    name: 'Electroválvula 24V Ulka',
    category: 'HYDRAULIC',
    quantity_available: 2,
    min_threshold: 3,
    is_below_threshold: true
  },
  {
    spare_part_id: 9,
    part_code: 'JUN-TEFL-09',
    name: 'Junta de teflón del grupo de infusión',
    category: 'CONSUMABLE',
    quantity_available: -1,
    min_threshold: 0,
    is_below_threshold: true
  },
  {
    spare_part_id: 12,
    part_code: 'SOND-NTC-02',
    name: 'Sonda Térmica NTC Frío 10k',
    category: 'THERMAL',
    quantity_available: 8,
    min_threshold: 2,
    is_below_threshold: false
  }
];

api.technician.getVanStock = async () => ({
  success: true,
  data: { technician_id: 7, items: inventoryRows }
});

await panel.loadInventory();

assert(
  '2.1 La carga identifica la furgoneta del sujeto autenticado',
  panel.technicianId === 7 && panel.items.length === 3 && panel.errorMessage === ''
);
assert('2.2 Cuenta las referencias declaradas', panel.referenceCount === 3);
assert(
  '2.3 Suma las unidades dejando restar el negativo (2 - 1 + 8)',
  panel.totalUnits === 9,
  `total=${panel.totalUnits}`
);
assert('2.4 Cuenta solo las parejas que el servidor marca bajo umbral', panel.alertCount === 2);
assert('2.5 Con inventario cargado no se pinta el estado vacío', panel.isEmpty === false);
assert(
  '2.6 Las cifras de cabecera se publican ya resueltas',
  panel.kpis.length === 3
    && panel.kpis[0].value === 3
    && panel.kpis[1].value === 9
    && panel.kpis[2].value === 2
);

assert(
  '2.7 Las categorías del catálogo se traducen a texto legible',
  panel.categoryLabel('HYDRAULIC') === 'Hidráulica'
    && panel.categoryLabel('CONSUMABLE') === 'Consumible'
    && panel.categoryLabel('SIN_CATEGORIA') === 'SIN_CATEGORIA'
);

assert(
  '2.8 El comprobante de recepción concuerda en singular con una sola unidad',
  panel.receivedUnitsLabel({ total_received_units: 1 }) === 'unidad recibida'
    && panel.receivedUnitsLabel({ total_received_units: '1' }) === 'unidad recibida'
);
assert(
  '2.9 El comprobante usa el plural con cero, con varias unidades y sin dato',
  panel.receivedUnitsLabel({ total_received_units: 0 }) === 'unidades recibidas'
    && panel.receivedUnitsLabel({ total_received_units: 3 }) === 'unidades recibidas'
    && panel.receivedUnitsLabel({}) === 'unidades recibidas'
    && panel.receivedUnitsLabel(null) === 'unidades recibidas'
);
assert(
  '2.10 La plantilla delega la concordancia en el método y no la fija en el texto',
  SOURCE.includes('{{ receivedUnitsLabel(lastConfirmedOrder) }}')
    && !/>\s*unidades recibidas/.test(SOURCE)
);

// =========================================================================
// BLOQUE 3: Estado vacío y error recuperable
// =========================================================================
console.log('\n--- BLOQUE 3: Estado vacío y error recuperable (RF-04.3) ---');

const emptyPanel = createComponentInstance();
api.technician.getVanStock = async () => ({ success: true, data: { technician_id: 11, items: [] } });
await emptyPanel.loadInventory();

assert('3.1 Sin stock declarado el panel queda vacío', emptyPanel.isEmpty === true && emptyPanel.items.length === 0);
assert(
  '3.2 El estado vacío explica quién carga el stock en vez de dejarlo mudo',
  emptyPanel.emptyMessage.includes('Coordinación') && emptyPanel.emptyMessage.includes('Todavía no hay stock')
);

const failingPanel = createComponentInstance();
api.technician.getVanStock = async () => {
  throw new Error('La red no responde.');
};
await failingPanel.loadInventory();

assert(
  '3.3 Una carga fallida deja aviso, no filas y suelta el indicador de carga',
  failingPanel.errorMessage === 'La red no responde.'
    && failingPanel.items.length === 0
    && failingPanel.isLoading === false
);

// =========================================================================
// BLOQUE 3-bis: Recepción de carga sin conexión (Módulo 24, EARS 03.2, D1)
// =========================================================================
console.log('\n--- BLOQUE 3-bis: Recepción de carga sin conexión (EARS 03.2) ---');

const offlinePanel = createComponentInstance();
offlinePanel.isOffline = true;
offlinePanel.activeDispatchedOrder = {
  id: 77,
  order_code: 'RST-2026-0077',
  total_dispatched_units: 3,
  items: []
};

let restockCalls = 0;
api.technician.confirmVanRestockOrder = async () => {
  restockCalls++;
  return { success: true, data: { message: 'Carga confirmada.' } };
};

await offlinePanel.confirmActiveOrder();

assert(
  '3.4 Sin conexión la recepción se bloquea antes de llamar a la API',
  restockCalls === 0 && offlinePanel.isConfirming === false,
  `llamadas: ${restockCalls}`
);
assert(
  '3.5 El bloqueo informa de que la operación exige conexión en vivo',
  offlinePanel.confirmError.includes('conexión en vivo')
    && offlinePanel.confirmMessage === ''
);
assert(
  '3.6 La plantilla conserva el aviso de error para mostrar el bloqueo',
  SOURCE.includes('data-testid="tech-van-stock-confirm-error"')
    && SOURCE.includes('{{ confirmError }}')
);

// =========================================================================
// BLOQUE 4: Solo lectura, mínimo privilegio y deuda cromática
// =========================================================================
console.log('\n--- BLOQUE 4: Solo lectura, mínimo privilegio y deuda cromática ---');

assert(
  '4.1 La vista no tiene ningún control que mute el saldo directamente (RF-04.1; la nota de recepción en textarea de confirmación es M23)',
  !/<input\b/i.test(SOURCE) && !/<select\b/i.test(SOURCE)
);
assert(
  '4.2 La vista no menciona el umbral mínimo, que RF-04.1 deja fuera del panel del técnico',
  !/min_threshold/.test(SOURCE)
);
assert(
  '4.3 La vista no transporta ni coste ni importe (Art. V.4, RD-05)',
  !/unit_cost|reference_cost|price|cost_snapshot/.test(SOURCE)
);
const TEMPLATE = SOURCE.slice(SOURCE.indexOf('template:'));

assert(
  '4.4 No reconstruye la etiqueta de descuadre que el servidor retira de esta proyección',
  !/Descuadre|is_negative/.test(TEMPLATE),
  TEMPLATE.match(/Descuadre|is_negative/g)?.join(',') || ''
);
assert(
  '4.5 Ni un literal hexadecimal: todo el color entra por token',
  (SOURCE.match(HEX_PATTERN) || []).length === 0,
  (SOURCE.match(HEX_PATTERN) || []).join(',')
);
assert(
  '4.6 El único botón declara un objetivo táctil de 44 px',
  /min-height:\s*44px/.test(SOURCE) && (SOURCE.match(/<button\b/g) || []).length === 2
);

// =========================================================================
// Resumen de Ejecución
// =========================================================================
console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. CONDICIÓN T-VAN-08 (PANEL DEL TÉCNICO) CUMPLIDA.');
  console.log('======================================================================');
  process.exit(0);
}
console.log(' RESULTADO: HAY FALLOS. REVISAR EL DETALLE ANTERIOR.');
console.log('======================================================================');
process.exit(1);
