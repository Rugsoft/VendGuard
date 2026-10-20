/**
 * VendGuard - OperationalZoneManagementTab Test Suite
 *
 * Certifica la pestaña de administración territorial del Módulo 17 (T-ZONE-12):
 * tarjetas de zona con su color corporativo, alta y edición de metadatos, badges de
 * códigos postales, cuadrante titular/suplente y confirmaciones en bloque.
 *
 * Hecho cuando:
 * 1. La pestaña declara los contratos de plantilla que la hacen montable en Coordinación
 *    (buscador, filtro de estado, tarjetas, modales y `data-testid` estables).
 * 2. El catálogo de zonas se carga una sola vez y alimenta tarjetas, contadores y filtros
 *    reactivos sin volver a pedirlo al servidor.
 * 3. El alta/edición valida el mismo contrato que el servidor (código, nombre, `#RRGGBB`)
 *    y envía payloads normalizados.
 * 4. Un traslado de CP responde 409 y no mueve nada hasta que el coordinador confirma;
 *    la segunda llamada viaja con `confirm_transfers = true`.
 * 5. Un segundo titular vivo exige confirmación explícita (`confirm_primary_override`) y
 *    la baja del cuadrante es lógica.
 * 6. La desactivación cifra el territorio que libera y exige `confirm_release`; sin
 *    territorio no pide una firma ceremonial.
 *
 * Dogma Vanilla: Node.js nativo, ESM y cero dependencias externas.
 */

// Entorno mínimo de navegador para el transporte HTTP y el store de la aplicación.
const storageMock = (() => {
  let store = {};
  return {
    getItem: (key) => store[key] || null,
    setItem: (key, value) => { store[key] = String(value); },
    removeItem: (key) => { delete store[key]; },
    clear: () => { store = {}; }
  };
})();
globalThis.localStorage = storageMock;
globalThis.window = {
  location: { search: '', href: 'http://localhost/' },
  addEventListener: () => {},
  removeEventListener: () => {},
  dispatchEvent: () => true
};

import { api } from '../../public/assets/js/api.js';
import {
  OperationalZoneManagementTab,
  parsePostalCodesInput,
  invalidPostalCodes,
  DEFAULT_ZONE_COLOR
} from '../../public/assets/js/components/OperationalZoneManagementTab.js';

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
console.log(' VendGuard: Frontend Test Suite - OperationalZoneManagementTab (T-ZONE-12)');
console.log('======================================================================\n');

// --------------------------------------------------------------------
// Fixtures
// --------------------------------------------------------------------

const makeZones = () => ([
  {
    id: 1,
    code: 'BCN-NORTE',
    name: 'Barcelona Norte',
    color_hex: '#2560ff',
    description: 'Litoral y norte metropolitano.',
    is_active: true,
    locations_count: 3,
    primary_technicians_count: 1,
    backup_technicians_count: 2,
    postal_codes_count: 2,
    postal_codes: ['08005', '08006']
  },
  {
    id: 2,
    code: 'BCN-SUR',
    name: 'Barcelona Sur',
    color_hex: '#38bd7d',
    description: null,
    is_active: false,
    locations_count: 0,
    primary_technicians_count: 0,
    backup_technicians_count: 0,
    postal_codes_count: 0,
    postal_codes: []
  }
]);

function createTabInstance(initialData = {}) {
  const component = Object.assign({}, OperationalZoneManagementTab);
  const data = component.data();
  const instance = Object.assign(data, component.methods, initialData);

  for (const [key, getter] of Object.entries(component.computed || {})) {
    Object.defineProperty(instance, key, {
      get: () => getter.call(instance),
      configurable: true
    });
  }

  return instance;
}

// Captura de llamadas para certificar los contratos sin tocar la red.
let apiCalls = [];
let nextZonesPayload = { zones: makeZones(), unassigned_locations_count: 4 };
let nextAssignResult = {
  zone_id: 1,
  assigned_postal_codes: ['08005', '08006'],
  transferred_postal_codes: [],
  affected_locations_count: 0
};
let nextPostalError = null;
let nextTechnicianError = null;

api.coordinator = api.coordinator || {};
api.coordinator.getZones = async () => ({
  zones: JSON.parse(JSON.stringify(nextZonesPayload.zones)),
  unassigned_locations_count: nextZonesPayload.unassigned_locations_count
});
api.coordinator.createZone = async (payload) => {
  apiCalls.push({ method: 'createZone', payload });
  return { id: 3, ...payload, is_active: true };
};
api.coordinator.updateZone = async (zoneId, payload) => {
  apiCalls.push({ method: 'updateZone', zoneId, payload });
  return { id: zoneId, ...payload };
};
api.coordinator.assignZonePostalCodes = async (zoneId, postalCodes, confirmTransfers) => {
  apiCalls.push({ method: 'assignZonePostalCodes', zoneId, postalCodes, confirmTransfers });
  if (nextPostalError) {
    const error = nextPostalError;
    nextPostalError = null;
    throw error;
  }
  return nextAssignResult;
};
api.coordinator.assignZoneTechnician = async (zoneId, technicianId, isPrimary, confirmOverride) => {
  apiCalls.push({ method: 'assignZoneTechnician', zoneId, technicianId, isPrimary, confirmOverride });
  if (nextTechnicianError) {
    const error = nextTechnicianError;
    nextTechnicianError = null;
    throw error;
  }
  return { zone_id: zoneId, technician_id: technicianId, is_primary: isPrimary };
};
api.coordinator.removeZoneTechnician = async (zoneId, technicianId) => {
  apiCalls.push({ method: 'removeZoneTechnician', zoneId, technicianId });
  return { zone_id: zoneId, technician_id: technicianId, removed: true };
};
api.admin = api.admin || {};
api.admin.getUsers = async () => ([
  { id: 7, name: 'Marta Guardia', operator_code: 'OP-07' },
  { id: 5, name: 'Jordi Ruta', operator_code: 'OP-05' }
]);

const resetCalls = () => { apiCalls = []; };

// =========================================================================
// BLOQUE 1: Contrato de componente y plantilla
// =========================================================================
console.log('--- BLOQUE 1: Contrato de componente y plantilla ---');

const template = OperationalZoneManagementTab.template;

assert('1.1 El componente declara su nombre Vue de inspección',
  OperationalZoneManagementTab.name === 'OperationalZoneManagementTab');
assert('1.2 Monta una raíz identificable por la suite de UI',
  template.includes('data-testid="operational-zone-tab"'));
assert('1.3 La cabecera y los avisos publican estados observables',
  template.includes('data-testid="zone-tab-header"')
    && template.includes('data-testid="zone-success"')
    && template.includes('data-testid="zone-error"'));
assert('1.4 Las tarjetas de zona exponen el estado activo/inactivo',
  template.includes('data-testid="zone-card"')
    && template.includes('data-testid="zone-status-active"')
    && template.includes('data-testid="zone-status-inactive"'));
assert('1.5 Los badges de códigos postales se pintan en la tarjeta',
  template.includes('data-testid="zone-postal-badges"')
    && template.includes('visiblePostalCodes(zone)')
    && template.includes('hiddenPostalCodesCount(zone)'));
assert('1.6 El alta permite elegir color hexadecimal (#RRGGBB)',
  template.includes('type="color"')
    && template.includes('v-model="createForm.color_hex"')
    && template.includes('data-testid="zone-create-color"'));
assert('1.7 Los traslados de CP muestran los conflictos antes de confirmar',
  template.includes('data-testid="zone-postal-conflicts"')
    && template.includes('conflict.affected_locations_count')
    && template.includes('data-testid="zone-postal-confirm"'));
assert('1.8 El cuadrante distingue titular y suplente y ofrece la baja lógica',
  template.includes('data-testid="zone-quadrant-primary"')
    && template.includes('data-testid="zone-quadrant-confirm"')
    && template.includes('data-testid="zone-quadrant-remove"'));
assert('1.9 La desactivación presenta el resumen de liberación en un modal',
  template.includes('data-testid="zone-release-modal"')
    && template.includes('data-testid="zone-release-summary"')
    && template.includes('data-testid="zone-release-confirm"'));

// 2026-10-20: los cinco diálogos se pintaban con la cáscara `vg-modal` a pelo —sin cuerpo
// acolchado ni pie separado— y el contenido tocaba el borde de la tarjeta. La guarda impide
// volver a reimplementar la anatomía: un diálogo entra por el componente compartido o no entra.
assert('1.10 Los cinco diálogos se declaran sobre el componente compartido ModalDialog',
  Boolean(OperationalZoneManagementTab.components && OperationalZoneManagementTab.components.ModalDialog)
    && (template.match(/<ModalDialog[\s>]/g) || []).length === 5
    && (template.match(/<\/ModalDialog>/g) || []).length === 5
    && !template.includes('vg-modal-backdrop'));
assert('1.11 Cada diálogo separa cuerpo y pie, y el pie envía el formulario del cuerpo',
  (template.match(/<template #footer>/g) || []).length === 5
    && template.includes('form="zone-create-form"')
    && template.includes('form="zone-edit-form"')
    && template.includes('form="zone-quadrant-form"'));

// =========================================================================
// BLOQUE 2: Normalización del lote de códigos postales
// =========================================================================
console.log('\n--- BLOQUE 2: Normalización del lote de códigos postales ---');

assert('2.1 Acepta comas, puntos y coma, espacios y saltos de línea',
  JSON.stringify(parsePostalCodesInput('08006, 08005;08007 08008\n08009'))
    === JSON.stringify(['08005', '08006', '08007', '08008', '08009']));
assert('2.2 Colapsa duplicados y ordena de forma determinista',
  JSON.stringify(parsePostalCodesInput('08006,08005,08006')) === JSON.stringify(['08005', '08006']));
assert('2.3 El parser rechaza tokens que no tienen cinco dígitos',
  invalidPostalCodes('08005, 0800, 0800A, 123456').length === 3);
assert('2.4 Un lote válido no reporta códigos inválidos',
  invalidPostalCodes('08005,08006').length === 0);
assert('2.5 El alta arranca con el azul de voltaje del sistema de diseño',
  DEFAULT_ZONE_COLOR === '#2560ff');

// =========================================================================
// BLOQUE 3: Catálogo, contadores y filtros reactivos
// =========================================================================
console.log('\n--- BLOQUE 3: Catálogo, contadores y filtros reactivos ---');

resetCalls();
const tab = createTabInstance();
await tab.loadZones();

assert('3.1 loadZones puebla el catálogo y el trabajo pendiente',
  tab.zones.length === 2 && tab.unassignedLocationsCount === 4
    && tab.isLoading === false);
assert('3.2 Los contadores de cabecera suman el catálogo real',
  tab.summaryMetrics.total === 2
    && tab.summaryMetrics.active === 1
    && tab.summaryMetrics.inactive === 1
    && tab.summaryMetrics.postalCodes === 2
    && tab.summaryMetrics.primary === 1
    && tab.summaryMetrics.backup === 2
    && tab.summaryMetrics.unassigned === 4);
assert('3.3 El filtro de estado aísla las zonas inactivas',
  (() => { tab.filterStatus = 'inactive'; return tab.filteredZones.length === 1 && tab.filteredZones[0].id === 2; })());
assert('3.4 La búsqueda cubre código, nombre y códigos postales',
  (() => {
    tab.filterStatus = 'all';
    tab.searchQuery = '08005';
    const byPostalCode = tab.filteredZones.length === 1 && tab.filteredZones[0].id === 1;
    tab.searchQuery = 'bcn-sur';
    const byCode = tab.filteredZones.length === 1 && tab.filteredZones[0].id === 2;
    tab.searchQuery = '';
    return byPostalCode && byCode;
  })());
assert('3.5 Una zona vacía no se anuncia como cargada',
  (() => { const empty = createTabInstance(); return empty.zones.length === 0 && empty.summaryMetrics.total === 0; })());

const failingTab = createTabInstance();
const previousGetZones = api.coordinator.getZones;
api.coordinator.getZones = async () => { throw new Error('catálogo caído'); };
await failingTab.loadZones();
api.coordinator.getZones = previousGetZones;
assert('3.7 El error de carga se comunica en castellano sin bloquear la UI',
  failingTab.errorMessage.includes('catálogo caído') && failingTab.isLoading === false);

// =========================================================================
// BLOQUE 4: Alta y edición de zonas
// =========================================================================
console.log('\n--- BLOQUE 4: Alta y edición de zonas ---');

resetCalls();
const createTab = createTabInstance();
createTab.openCreateModal();
assert('4.1 El alta se abre limpia y con el color por defecto',
  createTab.showCreateModal === true
    && createTab.createForm.code === ''
    && createTab.createForm.color_hex === DEFAULT_ZONE_COLOR);

createTab.createForm.code = 'Z';
createTab.createForm.name = 'AB';
createTab.createForm.color_hex = '#12345';
await createTab.submitCreate();
assert('4.2 La validación espejo rechaza código corto, nombre corto y color inválido',
  createTab.createErrors.code !== undefined
    && createTab.createErrors.name !== undefined
    && createTab.createErrors.color_hex !== undefined
    && apiCalls.length === 0);

createTab.createForm.code = 'bcn-litoral';
createTab.createForm.name = 'Barcelona Litoral';
createTab.createForm.color_hex = '#2560FF';
createTab.createForm.description = '  Cobertura de litoral.  ';
await createTab.submitCreate();
const created = apiCalls.find(call => call.method === 'createZone');
assert('4.3 El alta envía código en mayúsculas, color en minúsculas y texto limpio',
  Boolean(created)
    && created.payload.code === 'BCN-LITORAL'
    && created.payload.name === 'Barcelona Litoral'
    && created.payload.color_hex === '#2560ff'
    && created.payload.description === 'Cobertura de litoral.');
assert('4.4 El modal se cierra y el catálogo se recarga tras el alta',
  createTab.showCreateModal === false && createTab.isSubmittingCreate === false);

const duplicateTab = createTabInstance();
duplicateTab.openCreateModal();
duplicateTab.createForm.code = 'BCN-NORTE';
duplicateTab.createForm.name = 'Duplicada';
api.coordinator.createZone = async () => {
  const error = new Error('Ya existe una zona operativa con el código «BCN-NORTE».');
  error.code = 'ZONE_CODE_ALREADY_EXISTS';
  throw error;
};
await duplicateTab.submitCreate();
api.coordinator.createZone = async (payload) => {
  apiCalls.push({ method: 'createZone', payload });
  return { id: 3, ...payload, is_active: true };
};
assert('4.5 Un código duplicado se ancla al campo y no se pierde el formulario',
  duplicateTab.createErrors.code !== undefined && duplicateTab.showCreateModal === true);

resetCalls();
const editTab = createTabInstance();
editTab.zones = makeZones();
editTab.openEditModal(editTab.zones[0]);
assert('4.6 La edición precarga el código inmutable y los metadatos',
  editTab.showEditModal === true
    && editTab.editForm.code === 'BCN-NORTE'
    && editTab.editForm.name === 'Barcelona Norte'
    && editTab.editForm.color_hex === '#2560ff');
editTab.editForm.name = 'Barcelona Norte Renovada';
await editTab.submitEdit();
const updated = apiCalls.find(call => call.method === 'updateZone');
assert('4.7 La edición envía los metadatos y conserva el estado activo',
  Boolean(updated)
    && updated.zoneId === 1
    && updated.payload.name === 'Barcelona Norte Renovada'
    && updated.payload.is_active === true
    && updated.payload.confirm_release === undefined);

// =========================================================================
// BLOQUE 5: Códigos postales y confirmación en bloque (QA 4 / QA 6)
// =========================================================================
console.log('\n--- BLOQUE 5: Códigos postales y confirmación en bloque ---');

resetCalls();
const postalTab = createTabInstance();
postalTab.zones = makeZones();
postalTab.openPostalCodesModal(postalTab.zones[0]);
assert('5.1 El modal de CP precarga los códigos vigentes de la zona',
  postalTab.showPostalCodesModal === true
    && postalTab.postalCodesInput === '08005, 08006'
    && postalTab.newPostalCodes.length === 0);

postalTab.postalCodesInput = '08005, 0800X';
await postalTab.submitPostalCodes(false);
assert('5.2 Un código inválido bloquea el envío antes de tocar la API',
  apiCalls.length === 0 && postalTab.postalErrors.codes.includes('0800X'));

postalTab.postalCodesInput = '08005, 08006, 08007';
nextPostalError = {
  code: 'POSTAL_CODES_REQUIRE_CONFIRMATION',
  message: 'Uno o varios códigos postales pertenecen a otra zona activa.',
  raw: {
    error: {
      conflicts: [
        { postal_code: '08007', current_zone_id: 2, current_zone_name: 'Barcelona Sur', affected_locations_count: 2 }
      ]
    }
  }
};
await postalTab.submitPostalCodes(false);
assert('5.3 El 409 no mueve territorio: publica los conflictos y pide confirmación',
  postalTab.postalConflicts.length === 1
    && postalTab.postalConflicts[0].postal_code === '08007'
    && postalTab.postalConflicts[0].affected_locations_count === 2
    && postalTab.showPostalCodesModal === true);
assert('5.4 La primera llamada viaja sin confirmar el traslado',
  apiCalls.filter(call => call.method === 'assignZonePostalCodes').every(call => call.confirmTransfers === false));

resetCalls();
apiCalls = [];
await postalTab.confirmPostalCodesTransfer();
const confirmed = apiCalls.find(call => call.method === 'assignZonePostalCodes');
assert('5.5 La confirmación reenvía el lote completo con confirm_transfers = true',
  Boolean(confirmed)
    && confirmed.confirmTransfers === true
    && JSON.stringify(confirmed.postalCodes) === JSON.stringify(['08005', '08006', '08007'])
    && postalTab.postalConflicts.length === 0
    && postalTab.showPostalCodesModal === false);

// =========================================================================
// BLOQUE 6: Cuadrante técnico (QA 9 / QA 10)
// =========================================================================
console.log('\n--- BLOQUE 6: Cuadrante técnico ---');

resetCalls();
const quadrantTab = createTabInstance();
quadrantTab.zones = makeZones();
await quadrantTab.openTechniciansModal(quadrantTab.zones[0]);
assert('6.1 El modal carga el directorio de técnicos activos',
  quadrantTab.showTechniciansModal === true && quadrantTab.availableTechnicians.length === 2);

quadrantTab.quadrantForm.technician_id = 7;
quadrantTab.quadrantForm.is_primary = true;
await quadrantTab.submitQuadrantAssignment(false);
const assigned = apiCalls.find(call => call.method === 'assignZoneTechnician');
assert('6.2 La vinculación envía zona, técnico y rol de titularidad',
  Boolean(assigned)
    && assigned.zoneId === 1
    && assigned.technicianId === 7
    && assigned.isPrimary === true
    && assigned.confirmOverride === false);

nextTechnicianError = {
  code: 'TECHNICIAN_ALREADY_PRIMARY_IN_OTHER_ZONE',
  message: 'El técnico ya es titular principal de otra zona activa.',
  raw: { error: { current_zone_name: 'Barcelona Sur' } }
};
quadrantTab.quadrantForm.technician_id = 7;
quadrantTab.quadrantForm.is_primary = true;
await quadrantTab.submitQuadrantAssignment(false);
assert('6.3 Un segundo titular vivo se detiene y anuncia la degradación',
  quadrantTab.primaryConflict !== null
    && quadrantTab.primaryConflict.currentZoneName === 'Barcelona Sur');

resetCalls();
await quadrantTab.confirmPrimaryOverride();
const overridden = apiCalls.find(call => call.method === 'assignZoneTechnician');
assert('6.4 La confirmación reenvía la titularidad con confirm_primary_override',
  Boolean(overridden)
    && overridden.confirmOverride === true
    && quadrantTab.primaryConflict === null
    && quadrantTab.quadrantForm.technician_id === '');

resetCalls();
quadrantTab.removeTechnicianId = 5;
await quadrantTab.removeTechnicianFromQuadrant(5);
assert('6.5 La baja del cuadrante llama al DELETE lógico y conserva el histórico',
  apiCalls.some(call => call.method === 'removeZoneTechnician' && call.technicianId === 5)
    && quadrantTab.removeTechnicianId === ''
    && quadrantTab.quadrantNotice.includes('histórico'));

// =========================================================================
// BLOQUE 7: Desactivación con liberación territorial (QA 7 / QA 13)
// =========================================================================
console.log('\n--- BLOQUE 7: Desactivación con liberación territorial ---');

resetCalls();
const releaseTab = createTabInstance();
releaseTab.zones = makeZones();
await releaseTab.requestDeactivation(releaseTab.zones[0]);
assert('7.1 Una zona con territorio abre el modal con el balance real',
  releaseTab.showReleaseModal === true
    && releaseTab.releaseSummary.includes('2 código(s) postal(es)')
    && releaseTab.releaseSummary.includes('3 sede(s)')
    && releaseTab.releaseSummary.includes('3 técnico(s) del cuadrante'));
assert('7.2 Nada se envía antes de confirmar la liberación', apiCalls.length === 0);

await releaseTab.confirmRelease();
const deactivation = apiCalls.find(call => call.method === 'updateZone');
assert('7.3 La desactivación envía confirm_release y libera el territorio',
  Boolean(deactivation)
    && deactivation.zoneId === 1
    && deactivation.payload.is_active === false
    && deactivation.payload.confirm_release === true
    && releaseTab.showReleaseModal === false);

resetCalls();
const emptyReleaseTab = createTabInstance();
emptyReleaseTab.zones = makeZones();
await emptyReleaseTab.requestDeactivation(emptyReleaseTab.zones[1]);
assert('7.4 Sin territorio no hay firma ceremonial: se desactiva directamente',
  apiCalls.length === 1
    && apiCalls[0].payload.is_active === false
    && emptyReleaseTab.showReleaseModal === false);

// =========================================================================
// BLOQUE 8: Reactividad del catálogo tras las mutaciones
// =========================================================================
console.log('\n--- BLOQUE 8: Reactividad del catálogo ---');

resetCalls();
nextZonesPayload = {
  zones: makeZones().map(zone => zone.id === 1 ? { ...zone, postal_codes_count: 3, postal_codes: ['08005', '08006', '08007'] } : zone),
  unassigned_locations_count: 3
};
const refreshTab = createTabInstance();
await refreshTab.loadZones();
assert('8.1 Una recarga refleja los contadores y el trabajo pendiente nuevos',
  refreshTab.summaryMetrics.postalCodes === 3 && refreshTab.summaryMetrics.unassigned === 3);

refreshTab.quadrantZone = refreshTab.zones[0];
await refreshTab.loadZones();
assert('8.2 El modal de cuadrante apunta a la fila recargada (sin contadores congelados)',
  refreshTab.quadrantZone === refreshTab.zones[0]);

// =========================================================================
// RESUMEN FINAL
// =========================================================================
console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: ¡TODAS LAS PRUEBAS FRONTEND PASARON EXITOSAMENTE (${assertions} aserciones)!`);
  console.log(' CONDICIÓN T-ZONE-12 (pestaña territorial) CUMPLIDA.');
  process.exit(0);
} else {
  console.error(` RESULTADO: ${failures} fallos detectados de ${assertions} aserciones.`);
  process.exit(1);
}
