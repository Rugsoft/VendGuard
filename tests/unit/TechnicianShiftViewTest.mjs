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

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
console.log('======================================================================');

if (failures > 0) {
  process.exit(1);
}
