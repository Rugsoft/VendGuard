/**
 * VendGuard - AdminLocationsTab Test Suite (AdminLocationsTabTest.mjs)
 * 
 * Valida la funcionalidad reactiva y contratos del componente AdminLocationsTab (RF-01, RNF-05).
 * 
 * Hecho cuando:
 * 1. Renderiza y gestiona la tabla de sedes con filtros de estado ('active', 'inactive', 'all').
 * 2. Aplica filtro de búsqueda en tiempo real (código, nombre, dirección y contacto).
 * 3. Valida en cliente el código de sede (3-32 chars, uppercase) y teléfono español (9 dígitos).
 * 4. Gestiona el modal de alta invocando a la API y reseteando errores.
 * 5. Gestiona el modal de edición preservando el código inmutable en modo solo lectura.
 * 6. Diálogo de confirmación de baja alerta interactivamente y bloquea si la sede tiene máquinas activas (EARS 1.4).
 * 7. Permite confirmar baja lógica si no tiene máquinas y soporta reactivación fluida.
 * 
 * Dogma Vanilla: Node.js nativo con módulos ESM y cero dependencias externas.
 */

// Mock de entorno navegador para Node.js ESM
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
import { AdminLocationsTab } from '../../public/assets/js/components/AdminLocationsTab.js';

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
console.log(' VendGuard: Frontend Test Suite - AdminLocationsTab (T-ADM-13)');
console.log('======================================================================\n');

// Fixtures de prueba
const mockLocations = [
  {
    id: 1,
    site_code: 'SEDE-BCN-01',
    name: 'Hospital del Mar - Edificio Central',
    address: 'Passeig Marítim 25, Barcelona',
    contact_name: 'Laura Sanitaria',
    contact_phone: '600111222',
    is_active: true,
    active_machines_count: 2,
    total_machines_count: 2
  },
  {
    id: 2,
    site_code: 'SEDE-BCN-02',
    name: 'Torre Glòries - Planta 4 Oficinas',
    address: 'Avinguda Diagonal 211, Barcelona',
    contact_name: 'Marc Recepción',
    contact_phone: '600333444',
    is_active: true,
    active_machines_count: 0,
    total_machines_count: 1
  },
  {
    id: 3,
    site_code: 'SEDE-OLD-01',
    name: 'Antigua Central Retirada',
    address: 'Calle Pasada 99',
    contact_name: 'Antiguo Contacto',
    contact_phone: '677000111',
    is_active: false,
    active_machines_count: 0,
    total_machines_count: 0
  }
];

// Instanciar componente como un objeto reactivo vanilla
function createComponentInstance() {
  const comp = Object.assign({}, AdminLocationsTab);
  const data = comp.data();
  const instance = Object.assign(data, comp.methods);

  // Enlazar computed properties como getters dinámicos
  for (const [key, getter] of Object.entries(comp.computed || {})) {
    Object.defineProperty(instance, key, {
      get: () => getter.call(instance),
      configurable: true
    });
  }

  return instance;
}

// =========================================================================
// BLOQUE 1: Estado Inicial y Validaciones
// =========================================================================
console.log('--- BLOQUE 1: Estado Inicial y Validaciones de Entrada ---');

const tab = createComponentInstance();

assert('1.1 Inicializa con lista de sedes vacía', Array.isArray(tab.locations) && tab.locations.length === 0);
assert('1.2 Filtro inicial de estado es "all"', tab.filterStatus === 'all');
assert('1.3 Modales cerrados por defecto', !tab.showCreateModal && !tab.showEditModal && !tab.showDeactivateModal);

// Validaciones de código de sede
assert('1.4 validateSiteCode rechaza código vacío', tab.validateSiteCode('') !== null);
assert('1.5 validateSiteCode rechaza código demasiado corto (< 3 chars)', tab.validateSiteCode('AB') !== null);
assert('1.6 validateSiteCode rechaza caracteres especiales inválidos', tab.validateSiteCode('SEDE BCN*01') !== null);
assert('1.7 validateSiteCode acepta código alfanumérico válido con guiones', tab.validateSiteCode('SEDE-MAD-01') === null);

// Validaciones de teléfono
assert('1.8 validatePhone acepta null o vacío (campo opcional)', tab.validatePhone('') === null);
assert('1.9 validatePhone rechaza teléfono con menos de 9 dígitos', tab.validatePhone('6001122') !== null);
assert('1.10 validatePhone rechaza teléfono que no comience por 6, 7, 8 o 9', tab.validatePhone('500112233') !== null);
assert('1.11 validatePhone acepta teléfono español válido de 9 dígitos', tab.validatePhone('600112233') === null);

// =========================================================================
// BLOQUE 2: Carga de Datos y Filtros Reactivos
// =========================================================================
console.log('\n--- BLOQUE 2: Carga de Datos y Filtros Reactivos ---');

// Mockear llamada a API
api.admin = api.admin || {};
api.admin.getLocations = async (params = {}) => {
  return { success: true, data: mockLocations };
};

await tab.loadLocations();
assert('2.1 loadLocations puebla la lista de sedes desde la API', tab.locations.length === 3);

// Filtro por estado 'active'
tab.filterStatus = 'active';
const activeList = tab.filteredLocations;
assert('2.2 filteredLocations filtra solo sedes activas', activeList.length === 2 && activeList.every(l => l.is_active));

// Filtro por estado 'inactive'
tab.filterStatus = 'inactive';
const inactiveList = tab.filteredLocations;
assert('2.3 filteredLocations filtra solo sedes inactivas', inactiveList.length === 1 && !inactiveList[0].is_active);

// Filtro por búsqueda de texto
tab.filterStatus = 'all';
tab.searchQuery = 'Glòries';
const searchList = tab.filteredLocations;
assert('2.4 filteredLocations filtra por texto de búsqueda', searchList.length === 1 && searchList[0].site_code === 'SEDE-BCN-02');
tab.searchQuery = '';

// =========================================================================
// BLOQUE 3: Modal de Alta de Nueva Sede
// =========================================================================
console.log('\n--- BLOQUE 3: Modal de Alta de Nueva Sede ---');

tab.openCreateModal();
assert('3.1 openCreateModal abre el modal de alta', tab.showCreateModal === true);
assert('3.2 createForm inicializado en blanco', tab.createForm.site_code === '' && tab.createForm.name === '');

// Intento de envío con campos vacíos
await tab.submitCreate();
assert('3.3 submitCreate bloquea envío si faltan campos obligatorios', tab.createErrors.site_code !== undefined && tab.createErrors.name !== undefined);

// Envío válido mockeado
let apiCreateCalled = false;
let createdPayload = null;
api.admin.createLocation = async (payload) => {
  apiCreateCalled = true;
  createdPayload = payload;
  return { success: true, data: { id: 4, ...payload, is_active: true } };
};

tab.createForm.site_code = 'SEDE-VAL-01';
tab.createForm.name = 'Hospital La Fe';
tab.createForm.address = 'Avinguda de Fernando Abril Martorell 106, Valencia';
tab.createForm.contact_name = 'Vicente Sanitario';
tab.createForm.contact_phone = '600778899';

await tab.submitCreate();
assert('3.4 submitCreate invoca a api.admin.createLocation', apiCreateCalled === true);
assert('3.5 Payload enviado en mayúsculas y limpio', createdPayload.site_code === 'SEDE-VAL-01' && createdPayload.name === 'Hospital La Fe');
assert('3.6 Modal de alta se cierra tras éxito', tab.showCreateModal === false);

// =========================================================================
// BLOQUE 4: Modal de Edición de Sede
// =========================================================================
console.log('\n--- BLOQUE 4: Modal de Edición de Sede ---');

const locToEdit = mockLocations[0];
tab.openEditModal(locToEdit);
assert('4.1 openEditModal abre el modal de edición', tab.showEditModal === true);
assert('4.2 editForm contiene los datos de la sede seleccionada', tab.editForm.site_code === 'SEDE-BCN-01' && tab.editForm.name === locToEdit.name);

let apiUpdateCalled = false;
let updatedPayload = null;
api.admin.updateLocation = async (id, payload) => {
  apiUpdateCalled = true;
  updatedPayload = payload;
  return { success: true, data: { id, ...payload } };
};

tab.editForm.name = 'Hospital del Mar - Sede Renovada';
tab.editForm.contact_phone = '699112233';
await tab.submitEdit();

assert('4.3 submitEdit invoca a api.admin.updateLocation', apiUpdateCalled === true);
assert('4.4 Datos descriptivos actualizados enviados a la API', updatedPayload.name === 'Hospital del Mar - Sede Renovada' && updatedPayload.contact_phone === '699112233');
assert('4.5 Modal de edición se cierra tras éxito', tab.showEditModal === false);

// =========================================================================
// BLOQUE 5: Diálogo de Confirmación de Baja y Alerta Interactiva (EARS 1.4)
// =========================================================================
console.log('\n--- BLOQUE 5: Diálogo de Confirmación de Baja (EARS 1.4) ---');

// Caso A: Sede con máquinas activas asociadas (SEDE-BCN-01 tiene 2 máquinas)
const locWithMachines = mockLocations[0];
tab.openDeactivateModal(locWithMachines);

assert('5.1 openDeactivateModal abre el diálogo de confirmación', tab.showDeactivateModal === true);
assert('5.2 Sede con máquinas activas genera advertencia interactiva', tab.deactivateWarning.includes('máquina(s) operativa(s) activa(s)'));
assert('5.3 canConfirmDeactivate es false cuando tiene máquinas activas', tab.canConfirmDeactivate === false);

// Intentar confirmar baja cuando canConfirmDeactivate es false no debe llamar a la API
let apiDeactivateCalled = false;
api.admin.deactivateLocation = async (id) => {
  apiDeactivateCalled = true;
  return { success: true, data: { id, is_active: false } };
};

await tab.confirmDeactivate();
assert('5.4 confirmDeactivate rechaza la llamada si canConfirmDeactivate es false', apiDeactivateCalled === false);
tab.closeDeactivateModal();

// Caso B: Sede sin máquinas activas (SEDE-BCN-02 tiene 0 máquinas activas)
const locEmpty = mockLocations[1];
tab.openDeactivateModal(locEmpty);

assert('5.5 Sede sin máquinas activas no genera advertencia bloqueante', tab.deactivateWarning === '');
assert('5.6 canConfirmDeactivate es true para sede sin máquinas activas', tab.canConfirmDeactivate === true);

await tab.confirmDeactivate();
assert('5.7 confirmDeactivate invoca a api.admin.deactivateLocation para sede limpia', apiDeactivateCalled === true);
assert('5.8 Modal de baja se cierra tras éxito', tab.showDeactivateModal === false);

// =========================================================================
// BLOQUE 6: Reactivación y Plantilla Template
// =========================================================================
console.log('\n--- BLOQUE 6: Reactivación y Plantilla Template ---');

let apiReactivateCalled = false;
api.admin.reactivateLocation = async (id) => {
  apiReactivateCalled = true;
  return { success: true, data: { id, is_active: true } };
};

const inactiveLoc = mockLocations[2]; // SEDE-OLD-01 (is_active = false)
await tab.reactivateLocation(inactiveLoc);
assert('6.1 reactivateLocation invoca a api.admin.reactivateLocation', apiReactivateCalled === true);

// Verificación de template HTML
const tmpl = AdminLocationsTab.template;
assert('6.2 Template contiene tabla semántica de sedes', tmpl.includes('<table') && tmpl.includes('Código') && tmpl.includes('Máquinas'));
assert('6.3 Template contiene selector de estado active/inactive/all', tmpl.includes('filterStatus') && tmpl.includes('Todas las Sedes'));
assert('6.4 Template contiene input de búsqueda', tmpl.includes('searchQuery'));
assert('6.5 Template contiene modal de alta con código inmutable', tmpl.includes('showCreateModal') && tmpl.includes('site_code'));
assert('6.6 Template contiene modal de edición', tmpl.includes('showEditModal'));
assert('6.7 Template contiene modal de confirmación de baja con advertencia interactiva', tmpl.includes('showDeactivateModal') && tmpl.includes('canConfirmDeactivate'));

// =========================================================================
// RESUMEN FINAL
// =========================================================================
console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: ¡TODAS LAS PRUEBAS FRONTEND PASARON EXITOSAMENTE (${assertions} aserciones)!`);
  console.log(' CONDICIÓN T-ADM-13 CUMPLIDA.');
  process.exit(0);
} else {
  console.error(` RESULTADO: ${failures} fallos detectados de ${assertions} aserciones.`);
  process.exit(1);
}
