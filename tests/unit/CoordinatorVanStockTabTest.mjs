/**
 * VendGuard - Frontend Unit Test Suite (CoordinatorVanStockTabTest.mjs)
 *
 * Certifica la pestaña de stock de las furgonetas de Coordinación (Módulo 18, T-VAN-09, RF-03.2 y
 * RF-04.2) sin navegador, sin red y sin dependencias npm:
 *
 *  - **Contrato del módulo:** módulo ES nativo, nombre canónico y las marcas `data-testid` que
 *    anclan la tabla, el formulario y los dos modos de lectura.
 *  - **Dos lecturas (contrato §4.2):** el modo alerta pide `below_threshold=1`; el modo inventario
 *    no lo pide. El filtro por furgoneta viaja como `technician_id` en los dos modos, y sin filtro
 *    no viaja ningún parámetro —el contrato declara opcionales los dos—.
 *  - **Severidad (RF-03.2):** el descuadre manda sobre el simple bajo umbral y lo cubierto no
 *    lleva insignia de alarma.
 *  - **Ajuste absoluto (contrato §4.3):** abrir el formulario desde una fila precarga el saldo
 *    actual; guardar envía la cantidad declarada tal cual —no un delta— y el aviso repite la
 *    cantidad previa que devuelve el servidor. Un saldo negativo es declarable (RD-04).
 *  - **Catálogo de técnicos:** las opciones salen de los usuarios con rol técnico activo, no de las
 *    filas cargadas, para poder cargar la furgoneta de un técnico que aún no tiene ninguna pareja.
 *  - **Deuda cromática congelada:** ni un literal hexadecimal en el componente.
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
import { CoordinatorVanStockTab } from '../../public/assets/js/components/CoordinatorVanStockTab.js';

const SOURCE_PATH = fileURLToPath(new URL('../../public/assets/js/components/CoordinatorVanStockTab.js', import.meta.url));
const SOURCE = readFileSync(SOURCE_PATH, 'utf8');
const TEMPLATE = SOURCE.slice(SOURCE.indexOf('template:'));
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
  const component = Object.assign({}, CoordinatorVanStockTab);
  const instance = Object.assign(component.data(), component.methods);

  for (const [key, getter] of Object.entries(component.computed || {})) {
    Object.defineProperty(instance, key, {
      get: () => getter.call(instance),
      configurable: true
    });
  }

  return instance;
}

const alertRow = {
  technician_id: 7,
  technician_name: 'Jordi Técnico Ruta BCN',
  operator_code: 'OP-01',
  spare_part_id: 9,
  part_code: 'JUN-TEFL-09',
  name: 'Junta de teflón del grupo de infusión',
  quantity_available: -1,
  min_threshold: 0,
  is_below_threshold: true,
  is_negative: true
};

const healthyRow = {
  technician_id: 11,
  technician_name: 'Técnico Alfa',
  operator_code: 'OP-07',
  spare_part_id: 1,
  part_code: 'VALV-ULKA-01',
  name: 'Electroválvula 24V Ulka',
  quantity_available: 5,
  min_threshold: 2,
  is_below_threshold: false,
  is_negative: false
};

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - CoordinatorVanStockTab (T-VAN-09)');
console.log('======================================================================\n');

// =========================================================================
// BLOQUE 1: Contrato del módulo y estado inicial
// =========================================================================
console.log('--- BLOQUE 1: Contrato del módulo y estado inicial ---');

assert('1.1 Exporta el componente con su nombre canónico', CoordinatorVanStockTab.name === 'CoordinatorVanStockTab');
assert(
  '1.2 La plantilla trae la tabla, el formulario y los dos modos',
  TEMPLATE.includes('data-testid="coord-van-stock-table"')
    && TEMPLATE.includes('data-testid="coord-van-stock-form"')
    && TEMPLATE.includes('data-testid="coord-van-stock-mode-alert"')
    && TEMPLATE.includes('data-testid="coord-van-stock-mode-inventory"')
);

const tab = createComponentInstance();
assert(
  '1.3 Arranca en modo alerta con el formulario cerrado y el filtro sin fijar',
  tab.mode === 'alert' && tab.isAlertMode === true && tab.showForm === false && tab.selectedTechnicianId === ''
);
assert(
  '1.4 El formulario arranca vacío y por tanto no es guardable',
  tab.canSave === false && tab.form.technician_id === '' && tab.form.spare_part_id === ''
);

// =========================================================================
// BLOQUE 2: Las dos lecturas y el filtro (contrato §4.2)
// =========================================================================
console.log('\n--- BLOQUE 2: Las dos lecturas y el filtro (§4.2) ---');

const requestedParams = [];
api.coordinator.getVanStocks = async (params = {}) => {
  requestedParams.push({ ...params });
  return { success: true, data: { below_threshold: 'below_threshold' in params, items: [alertRow] } };
};

await tab.loadStock();
assert(
  '2.1 El modo alerta pide below_threshold=1',
  requestedParams.length === 1 && requestedParams[0].below_threshold === 1
);
assert('2.2 La carga pinta las filas devueltas', tab.items.length === 1 && tab.items[0].spare_part_id === 9);

await tab.setMode('inventory');
assert(
  '2.3 El modo inventario no envía below_threshold',
  tab.isAlertMode === false
    && requestedParams.length === 2
    && !('below_threshold' in requestedParams[1])
    && Object.keys(requestedParams[1]).length === 0,
  JSON.stringify(requestedParams[1])
);

tab.selectedTechnicianId = '7';
await tab.applyTechnicianFilter();
assert(
  '2.4 El filtro por furgoneta viaja como technician_id',
  requestedParams.length === 3 && requestedParams[2].technician_id === '7' && !('below_threshold' in requestedParams[2])
);

tab.selectedTechnicianId = '';
tab.mode = 'alert';
await tab.applyTechnicianFilter();
assert(
  '2.5 Sin filtro no viaja ningún parámetro de técnico',
  requestedParams.length === 4 && !('technician_id' in requestedParams[3]) && requestedParams[3].below_threshold === 1
);

// =========================================================================
// BLOQUE 3: Severidad y estados vacíos (RF-03.2)
// =========================================================================
console.log('\n--- BLOQUE 3: Severidad y estados vacíos (RF-03.2) ---');

assert(
  '3.1 El descuadre negativo manda sobre el simple bajo umbral',
  tab.severityOf(alertRow).label === 'Descuadre' && tab.severityOf(alertRow).testid === 'descuadre'
);
assert(
  '3.2 Un saldo bajo pero no negativo se marca como reposición',
  tab.severityOf({ ...healthyRow, is_below_threshold: true }).label === 'Reponer'
);
assert(
  '3.3 Una pareja cubierta no lleva insignia de alarma',
  tab.severityOf(healthyRow).label === 'Cubierto' && tab.severityOf(healthyRow).testid === 'cubierto'
);

tab.items = [];
tab.isLoading = false;
tab.mode = 'alert';
assert(
  '3.4 El vacío de la alerta dice que el parque está cubierto',
  tab.isEmpty === true && tab.emptyMessage.includes('por debajo de su umbral')
);
tab.mode = 'inventory';
assert(
  '3.5 El vacío del inventario dice que nadie ha declarado stock',
  tab.emptyMessage.includes('no hay stock declarado')
);

// =========================================================================
// BLOQUE 4: Ajuste absoluto (contrato §4.3)
// =========================================================================
console.log('\n--- BLOQUE 4: Ajuste absoluto (§4.3) ---');

const formTab = createComponentInstance();
formTab.openForm(alertRow);
assert(
  '4.1 Abrir el ajuste desde una fila precarga la pareja y el saldo actual',
  formTab.showForm === true
    && formTab.form.technician_id === 7
    && formTab.form.spare_part_id === 9
    && formTab.form.quantity_available === -1
    && formTab.form.min_threshold === 0
    && formTab.formTitle === 'Ajustar el stock declarado'
);
assert('4.2 El saldo negativo precargado es guardable (RD-04)', formTab.canSave === true);

formTab.closeForm();
assert(
  '4.3 Cancelar cierra el formulario y lo deja vacío',
  formTab.showForm === false && formTab.form.technician_id === '' && formTab.canSave === false
);

formTab.openForm();
assert(
  '4.4 El alta desde cero no se puede guardar hasta elegir técnico y pieza',
  formTab.showForm === true && formTab.formTitle === 'Declarar stock de una furgoneta' && formTab.canSave === false
);
formTab.form.technician_id = 11;
formTab.form.spare_part_id = 1;
formTab.form.quantity_available = '3';
formTab.form.min_threshold = '1';
assert('4.5 Con las cuatro cotas completas el ajuste es guardable', formTab.canSave === true);
formTab.form.quantity_available = '3.5';
assert('4.6 Una cantidad fraccionaria no es declarable', formTab.canSave === false);

const sentPayloads = [];
api.coordinator.setVanStock = async (payload) => {
  sentPayloads.push(payload);
  return { success: true, data: { ...payload, previous_quantity_available: 4, is_below_threshold: true } };
};

formTab.form.quantity_available = '1';
await formTab.saveStock();
assert(
  '4.7 El ajuste envía el valor absoluto declarado, no un delta',
  sentPayloads.length === 1
    && sentPayloads[0].technician_id === 11
    && sentPayloads[0].spare_part_id === 1
    && sentPayloads[0].quantity_available === 1
    && sentPayloads[0].min_threshold === 1,
  JSON.stringify(sentPayloads[0])
);
assert(
  '4.8 El aviso de éxito repite la cantidad previa que devolvió el servidor',
  formTab.successMessage.includes('antes 4') && formTab.successMessage.includes('1 unidades')
);
assert('4.9 Guardar cierra el formulario y lo deja listo para el siguiente ajuste', formTab.showForm === false);

const failingTab = createComponentInstance();
failingTab.form = { technician_id: 11, spare_part_id: 1, quantity_available: '2', min_threshold: '1' };
failingTab.showForm = true;
api.coordinator.setVanStock = async () => {
  throw new Error('La cantidad está fuera de rango.');
};
await failingTab.saveStock();
assert(
  '4.10 Un rechazo del servidor deja el aviso en el formulario sin cerrarlo',
  failingTab.formError === 'La cantidad está fuera de rango.'
    && failingTab.showForm === true
    && failingTab.isSaving === false
);

// =========================================================================
// BLOQUE 5: Catálogo de técnicos y deuda cromática
// =========================================================================
console.log('\n--- BLOQUE 5: Catálogo de técnicos y deuda cromática ---');

api.coordinator.getUsers = async () => ({
  success: true,
  data: [
    { id: 7, name: 'Jordi Técnico Ruta BCN', operator_code: 'OP-01', role: 'TECHNICIAN' },
    { id: 11, name: 'Técnico Alfa', operator_code: null, role: 'TECHNICIAN' }
  ]
});

const techTab = createComponentInstance();
await techTab.loadTechnicians();
assert(
  '5.1 Las furgonetas seleccionables salen del directorio de técnicos, no del inventario',
  techTab.technicians.length === 2 && techTab.technicians[0].id === 7 && techTab.technicians[0].operatorCode === 'OP-01'
);
assert(
  '5.2 Un técnico sin código de operador no rompe la opción',
  techTab.technicians[1].operatorCode === ''
);

api.coordinator.getUsers = async () => {
  throw new Error('sin permisos');
};
const resilientTab = createComponentInstance();
await resilientTab.loadTechnicians();
assert(
  '5.3 Si el directorio falla la tabla sigue siendo legible',
  resilientTab.technicians.length === 0 && resilientTab.items.length === 0
);

assert(
  '5.4 Ni un literal hexadecimal: todo el color entra por token',
  (SOURCE.match(HEX_PATTERN) || []).length === 0,
  (SOURCE.match(HEX_PATTERN) || []).join(',')
);
assert('5.5 La plantilla no inyecta HTML crudo', !/v-html/.test(TEMPLATE));
assert(
  '5.6 Los botones se visten con la escalera del sistema y no declaran geometría en línea',
  !/<button[^>]*style="[^"]*min-height/i.test(TEMPLATE)
);

// =========================================================================
// Resumen de Ejecución
// =========================================================================
console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. CONDICIÓN T-VAN-09 (PESTAÑA DE COORDINACIÓN) CUMPLIDA.');
  console.log('======================================================================');
  process.exit(0);
}
console.log(' RESULTADO: HAY FALLOS. REVISAR EL DETALLE ANTERIOR.');
console.log('======================================================================');
process.exit(1);
