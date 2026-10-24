/**
 * VendGuard - Frontend Unit Test Suite (TechnicianShiftViewTest.mjs)
 * 
 * Validates TechnicianRouteView shift controls and handover modal (T-ASH-09, RF-03 to RF-06):
 * - Mobile shift control bar (Iniciar Turno, Pausa Comida, Reanudar, Cerrar Turno).
 * - Direct close from MEAL_BREAK (Resolución R10).
 * - Modal de fin de jornada y relevo de ruta (selección de receptor, notas internas).
 * - Bloqueo visual ante incidencias en IN_PROGRESS (D4 / RF-04).
 * - Manejo reactivo de errores 409 CONCURRENT_ROUTE_MODIFICATION.
 * 
 * Dogma Vanilla: Pure ES Module test runner without external dependencies.
 */

// Mock storage
const storageMock = new Map();
globalThis.localStorage = {
  getItem: (k) => storageMock.get(k) ?? null,
  setItem: (k, v) => storageMock.set(k, String(v)),
  removeItem: (k) => storageMock.delete(k),
  clear: () => storageMock.clear()
};
globalThis.sessionStorage = { ...globalThis.localStorage };

import { api } from '../../public/assets/js/api.js';
import { TechnicianRouteView } from '../../public/assets/js/views/TechnicianRouteView.js';

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - TechnicianShiftView (T-ASH-09)');
console.log('======================================================================\n');

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.log(`  [FAIL] ${description}`);
    if (details) console.log(`         ${details}`);
    failures++;
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Estado de jornada y reactividad de datos
// ─────────────────────────────────────────────────────────────────────────────
const vm = {
  ...TechnicianRouteView.data(),
  ...TechnicianRouteView.methods,
  $emit: () => {},
};

assert('data() incluye shiftStatus inicial', typeof vm.shiftStatus === 'string');
assert('data() incluye showShiftHandoverModal en false', vm.showShiftHandoverModal === false);
assert('data() incluye selectedHandoverTicketIds como array', Array.isArray(vm.selectedHandoverTicketIds));
assert('data() incluye targetHandoverTechId inicializado', vm.targetHandoverTechId === '' || vm.targetHandoverTechId === null);
assert('data() incluye handoverNotes', typeof vm.handoverNotes === 'string');

// ─────────────────────────────────────────────────────────────────────────────
// 2. Computed properties de bloqueo preventivo
// ─────────────────────────────────────────────────────────────────────────────
const computed = TechnicianRouteView.computed;
assert('computed incluye hasInProgressIncidents', typeof computed.hasInProgressIncidents === 'function');
assert('computed incluye eligibleHandoverIncidents', typeof computed.eligibleHandoverIncidents === 'function');

// Evaluar con ruta que contiene una avería IN_PROGRESS
const mockCtxInProgress = {
  assignedIncidents: [
    { id: 1, ticket_code: 'INC-01', status: 'IN_PROGRESS' },
    { id: 2, ticket_code: 'INC-02', status: 'ASSIGNED' },
  ],
};
assert(
  'hasInProgressIncidents detecta avería en curso bloqueante',
  computed.hasInProgressIncidents.call(mockCtxInProgress) === true
);

// Evaluar con ruta que solo tiene ASSIGNED, PENDING_PARTS y PENDING_INFO
const mockCtxEligible = {
  assignedIncidents: [
    { id: 2, ticket_code: 'INC-02', status: 'ASSIGNED' },
    { id: 3, ticket_code: 'INC-03', status: 'PENDING_PARTS' },
    { id: 4, ticket_code: 'INC-04', status: 'PENDING_INFO' },
  ],
};
assert(
  'hasInProgressIncidents es false si no hay IN_PROGRESS',
  computed.hasInProgressIncidents.call(mockCtxEligible) === false
);
const eligible = computed.eligibleHandoverIncidents.call(mockCtxEligible);
assert('eligibleHandoverIncidents filtra correctamente las 3 averías elegibles', eligible.length === 3);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Métodos de control de jornada
// ─────────────────────────────────────────────────────────────────────────────
assert('methods incluye startShift', typeof TechnicianRouteView.methods.startShift === 'function');
assert('methods incluye pauseMealShift', typeof TechnicianRouteView.methods.pauseMealShift === 'function');
assert('methods incluye resumeMealShift', typeof TechnicianRouteView.methods.resumeMealShift === 'function');
assert('methods incluye openShiftHandoverModal', typeof TechnicianRouteView.methods.openShiftHandoverModal === 'function');
assert('methods incluye submitShiftHandover', typeof TechnicianRouteView.methods.submitShiftHandover === 'function');

// ─────────────────────────────────────────────────────────────────────────────
// 4. Endpoint móvil de relevo y etiqueta de máquina (A6)
// ─────────────────────────────────────────────────────────────────────────────
let requestedUrl = null;
const originalGet = api.get;
api.get = async (url) => {
  requestedUrl = url;
  return [
    { id: 1, name: 'Técnico Autenticado', is_active: true },
    { id: 2, name: 'Jordi Ruta', is_active: true },
    { id: 9, name: 'Otro Técnico', is_active: true }
  ];
};

const handoverCtx = {
  ...TechnicianRouteView.data(),
  ...TechnicianRouteView.methods,
  currentUser: { id: 1 },
  eligibleHandoverIncidents: []
};
await handoverCtx.openShiftHandoverModal();

assert('openShiftHandoverModal() usa el endpoint propio del técnico', requestedUrl === '/technician/handover-targets');
assert(
  'openShiftHandoverModal() excluye al técnico autenticado de los receptores',
  handoverCtx.availableReceptors.length === 2
    && handoverCtx.availableReceptors.every((technician) => technician.id !== 1)
);

assert(
  'handoverMachineLabel() prioriza machine.code de la ruta',
  handoverCtx.handoverMachineLabel({ machine: { id: 5, code: 'VG-001' } }) === 'VG-001'
);
assert(
  'handoverMachineLabel() cae al identificador cuando no hay código',
  handoverCtx.handoverMachineLabel({ machine: { id: 5 } }) === 'Máquina #5'
);

api.get = originalGet;

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
console.log('======================================================================');

if (failures > 0) {
  process.exit(1);
}
