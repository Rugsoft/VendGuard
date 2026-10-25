/**
 * VendGuard - Frontend Unit Test Suite (TechnicianShiftViewTest.mjs)
 *
 * Validates TechnicianRouteView shift controls and handover modal (T-ASH-09, RF-03 to RF-06):
 * - Mobile shift control bar (Iniciar Turno, Pausa Comida, Reanudar, Cerrar Turno).
 * - Direct close from MEAL_BREAK (Resolución R10).
 * - Modal de fin de jornada: abre en CLOSE_ONLY, sin averías preseleccionadas.
 * - Modo TRANSFER explícito: carga receptores solo al entrar, exige selección,
 *   receptor disponible y nota; deshabilita los no disponibles.
 * - Bloqueo visual ante incidencias en IN_PROGRESS (D4 / RF-04).
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

const buildContext = (overrides = {}) => {
  const context = {
    ...TechnicianRouteView.data(),
    ...TechnicianRouteView.methods,
    currentUser: { id: 1 },
    eligibleHandoverIncidents: [],
    hasInProgressIncidents: false,
    loadRoute: async () => {},
    ...overrides,
  };

  return context;
};

// ─────────────────────────────────────────────────────────────────────────────
// 1. Estado de jornada y reactividad de datos
// ─────────────────────────────────────────────────────────────────────────────
const vm = buildContext();

assert('data() incluye shiftStatus inicial', typeof vm.shiftStatus === 'string');
assert('data() incluye showShiftHandoverModal en false', vm.showShiftHandoverModal === false);
assert('data() incluye handoverMode CLOSE_ONLY por defecto', vm.handoverMode === 'CLOSE_ONLY');
assert('data() incluye selectedHandoverTicketIds como array vacío', Array.isArray(vm.selectedHandoverTicketIds) && vm.selectedHandoverTicketIds.length === 0);
assert('data() incluye targetHandoverTechId inicializado', vm.targetHandoverTechId === '' || vm.targetHandoverTechId === null);
assert('data() incluye handoverNotes', typeof vm.handoverNotes === 'string');

// ─────────────────────────────────────────────────────────────────────────────
// 2. Computed properties de bloqueo preventivo
// ─────────────────────────────────────────────────────────────────────────────
const computed = TechnicianRouteView.computed;
assert('computed incluye hasInProgressIncidents', typeof computed.hasInProgressIncidents === 'function');
assert('computed incluye eligibleHandoverIncidents', typeof computed.eligibleHandoverIncidents === 'function');

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
assert('methods incluye setHandoverMode', typeof TechnicianRouteView.methods.setHandoverMode === 'function');
assert('methods incluye selectAllHandoverIncidents', typeof TechnicianRouteView.methods.selectAllHandoverIncidents === 'function');

// ─────────────────────────────────────────────────────────────────────────────
// 4. Modal: CLOSE_ONLY por defecto, sin tráfico ni selección
// ─────────────────────────────────────────────────────────────────────────────
let getCalls = 0;
let requestedUrls = [];
const originalGet = api.get;
api.get = async (url) => {
  getCalls++;
  requestedUrls.push(url);
  return [
    { id: 1, name: 'Técnico Autenticado', is_active: true, availability: 'AVAILABLE', is_available_for_handover: true },
    { id: 2, name: 'Jordi Ruta', is_active: true, availability: 'AVAILABLE', is_available_for_handover: true },
    { id: 9, name: 'Otro Técnico', is_active: true, availability: 'UNAVAILABLE', is_available_for_handover: false },
  ];
};

const handoverCtx = buildContext({
  eligibleHandoverIncidents: [
    { id: 10, ticket_code: 'INC-10', status: 'ASSIGNED' },
    { id: 11, ticket_code: 'INC-11', status: 'PENDING_PARTS' },
  ],
});

await handoverCtx.openShiftHandoverModal();
assert('openShiftHandoverModal() abre en CLOSE_ONLY', handoverCtx.handoverMode === 'CLOSE_ONLY');
assert('openShiftHandoverModal() no preselecciona ninguna avería', handoverCtx.selectedHandoverTicketIds.length === 0);
assert('El modo cierre no llama a handover-targets', getCalls === 0);

await handoverCtx.setHandoverMode('TRANSFER');
assert('setHandoverMode(TRANSFER) carga el endpoint propio del técnico', getCalls === 1 && requestedUrls[0] === '/technician/handover-targets');
assert('El modo traspaso tampoco selecciona averías por defecto', handoverCtx.selectedHandoverTicketIds.length === 0);
assert(
  'El listado excluye al técnico autenticado',
  handoverCtx.handoverTargets.length === 2
    && handoverCtx.handoverTargets.every((technician) => technician.id !== 1)
);
assert(
  'Los receptores no disponibles quedan deshabilitados',
  handoverCtx.handoverTargets.find((t) => t.id === 9)?.is_available_for_handover === false
);
assert(
  'Solo los receptores disponibles se ofrecen en availableReceptors',
  handoverCtx.availableReceptors.length === 1 && handoverCtx.availableReceptors[0].id === 2
);

handoverCtx.selectAllHandoverIncidents();
assert(
  '«Seleccionar todas» es una acción explícita y selecciona todas las elegibles',
  handoverCtx.selectedHandoverTicketIds.length === 2
    && handoverCtx.selectedHandoverTicketIds.includes(10)
    && handoverCtx.selectedHandoverTicketIds.includes(11)
);

handoverCtx.clearHandoverSelection();
assert('«Quitar selección» deja la lista vacía', handoverCtx.selectedHandoverTicketIds.length === 0);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Envío en modo CLOSE_ONLY
// ─────────────────────────────────────────────────────────────────────────────
let postedUrl = null;
let postedPayload = null;
const originalPost = api.post;
api.post = async (url, payload) => {
  postedUrl = url;
  postedPayload = payload;
  return { success: true };
};

const closeCtx = buildContext({
  showShiftHandoverModal: true,
  handoverMode: 'CLOSE_ONLY',
  eligibleHandoverIncidents: [{ id: 10, ticket_code: 'INC-10', status: 'ASSIGNED' }],
  selectedHandoverTicketIds: [],
  targetHandoverTechId: '',
  handoverNotes: '',
});

await closeCtx.submitShiftHandover();
assert('El cierre sin relevo envía target null', postedPayload?.target_technician_id === null);
assert('El cierre sin relevo envía incident_ids vacío', Array.isArray(postedPayload?.incident_ids) && postedPayload.incident_ids.length === 0);
assert('El cierre sin relevo no exige nota', postedPayload?.handover_notes === '');
assert('El cierre sin relevo cierra el modal y el turno', closeCtx.showShiftHandoverModal === false && closeCtx.shiftStatus === 'OFF_SHIFT');

// ─────────────────────────────────────────────────────────────────────────────
// 6. Envío en modo TRANSFER: validaciones y payload
// ─────────────────────────────────────────────────────────────────────────────
postedPayload = null;
const transferCtx = buildContext({
  showShiftHandoverModal: true,
  handoverMode: 'TRANSFER',
  eligibleHandoverIncidents: [{ id: 10, ticket_code: 'INC-10', status: 'ASSIGNED' }],
  selectedHandoverTicketIds: [],
  targetHandoverTechId: '2',
  handoverNotes: 'Nota de traspaso suficientemente larga',
  handoverTargets: [{ id: 2, name: 'Jordi Ruta', is_available_for_handover: true }],
});

await transferCtx.submitShiftHandover();
assert('Sin averías seleccionadas no se envía el traspaso', postedPayload === null);
assert('Sin averías seleccionadas se explica el error', transferCtx.handoverError.includes('al menos una avería'));

transferCtx.selectedHandoverTicketIds = [10];
transferCtx.handoverNotes = 'corta';
await transferCtx.submitShiftHandover();
assert('Con nota corta no se envía el traspaso', postedPayload === null);
assert('Con nota corta se explica el error', transferCtx.handoverError.includes('10 caracteres'));

transferCtx.handoverNotes = 'Nota de traspaso suficientemente larga';
transferCtx.targetHandoverTechId = '9';
transferCtx.handoverTargets = [{ id: 9, name: 'Otro Técnico', is_available_for_handover: false }];
await transferCtx.submitShiftHandover();
assert('Con receptor no disponible no se envía el traspaso', postedPayload === null);
assert('Con receptor no disponible se explica el error', transferCtx.handoverError.includes('no está disponible'));

transferCtx.targetHandoverTechId = '2';
transferCtx.handoverTargets = [{ id: 2, name: 'Jordi Ruta', is_available_for_handover: true }];
await transferCtx.submitShiftHandover();
assert('El traspaso válido envía el receptor elegido', postedPayload?.target_technician_id === 2);
assert('El traspaso válido envía la avería seleccionada', postedPayload?.incident_ids?.length === 1 && postedPayload.incident_ids[0] === 10);
assert('El traspaso válido incluye la nota interna', postedPayload?.handover_notes === 'Nota de traspaso suficientemente larga');
assert('El traspaso válido cierra el modal', transferCtx.showShiftHandoverModal === false);

// ─────────────────────────────────────────────────────────────────────────────
// 7. Etiqueta de máquina (A6)
// ─────────────────────────────────────────────────────────────────────────────
assert(
  'handoverMachineLabel() prioriza machine.code de la ruta',
  handoverCtx.handoverMachineLabel({ machine: { id: 5, code: 'VG-001' } }) === 'VG-001'
);
assert(
  'handoverMachineLabel() cae al identificador cuando no hay código',
  handoverCtx.handoverMachineLabel({ machine: { id: 5 } }) === 'Máquina #5'
);

api.get = originalGet;
api.post = originalPost;

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
console.log('======================================================================');

if (failures > 0) {
  process.exit(1);
}
