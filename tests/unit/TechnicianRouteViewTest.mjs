/**
 * VendGuard - Frontend Unit Test Suite (TechnicianRouteViewTest.mjs)
 * 
 * Validates TechnicianRouteView (T-38, RF-07, RF-08, RNF-01):
 * - Mobile vertical smartphone UI layout.
 * - Route task list and operational status counters.
 * - "Iniciar intervención" action (transitions to IN_PROGRESS).
 * - "Pausar por repuesto" modal requiring spare part description (EARS 7.2).
 * - "Resolver avería" modal strictly requiring >= 20 chars per field (EARS 8.1, 8.2).
 * 
 * Dogma Vanilla: Pure ES Module test runner without external dependencies.
 */

// 1. Mock LocalStorage and SessionStorage in Node environment
const storageMock = new Map();
globalThis.localStorage = {
  getItem: (k) => storageMock.get(k) ?? null,
  setItem: (k, v) => storageMock.set(k, String(v)),
  removeItem: (k) => storageMock.delete(k),
  clear: () => storageMock.clear()
};
globalThis.sessionStorage = { ...globalThis.localStorage };

// 2. Import components and store
import { TechnicianRouteView } from '../../public/assets/js/views/TechnicianRouteView.js';
import { api } from '../../public/assets/js/api.js';
import { store, setInternalSession, clearSession } from '../../public/assets/js/store.js';

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - TechnicianRouteView (T-38)');
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

// Mock seed route data
const mockRouteIncidents = [
  {
    id: 101,
    ticket_code: 'INC-2026-0101',
    urgency: 'HIGH',
    status: 'ASSIGNED',
    category: 'PAYMENT_SYSTEM',
    description: 'El monedero traga monedas de 1 euro y no da cambio.',
    machine: { id: 1, code: 'VEND-BCN-001', model: 'Vendo ColdDrink 800', floor_wing: 'Planta 1 · Cafetería' },
    location: { id: 1, name: 'Sede Central Barcelona', address: 'Av. Diagonal 123', contact_phone: '933001122' },
    started_at: null,
    pending_parts_reason: null
  },
  {
    id: 102,
    ticket_code: 'INC-2026-0102',
    urgency: 'CRITICAL',
    status: 'IN_PROGRESS',
    category: 'TEMPERATURE_COLD',
    description: 'Máquina de sándwiches a 14ºC con alerta de frío alimentario.',
    machine: { id: 2, code: 'VEND-BCN-002', model: 'Vendo FreshMeal 400', floor_wing: 'Planta Baja · Vestíbulo' },
    location: { id: 1, name: 'Sede Central Barcelona', address: 'Av. Diagonal 123', contact_phone: '933001122' },
    started_at: '2026-09-22T08:30:00Z',
    pending_parts_reason: null
  },
  {
    id: 103,
    ticket_code: 'INC-2026-0103',
    urgency: 'MEDIUM',
    status: 'PENDING_PARTS',
    category: 'MECHANICAL',
    description: 'Espiral 4 atascada sin dispensar bolsas de patatas.',
    machine: { id: 3, code: 'VEND-BCN-003', model: 'Vendo SnackMaster 600', floor_wing: 'Planta 3 · Sala de Descanso' },
    location: { id: 1, name: 'Sede Central Barcelona', address: 'Av. Diagonal 123', contact_phone: '933001122' },
    started_at: '2026-09-22T07:45:00Z',
    pending_parts_reason: 'Motor reductor de espiral 24V roto. Ref. MOT-ESP-24'
  }
];

function createRouteInstance(initialData = {}) {
  const emits = [];
  const instance = {
    ...TechnicianRouteView.data(),
    incidents: JSON.parse(JSON.stringify(mockRouteIncidents)),
    ...initialData,
    $emit: (evt, val) => { emits.push({ evt, val }); },
    getEmits: () => emits
  };

  // Bind computed properties
  Object.defineProperty(instance, 'isAuthenticated', {
    get: () => TechnicianRouteView.computed.isAuthenticated.call(instance)
  });
  Object.defineProperty(instance, 'currentUser', {
    get: () => TechnicianRouteView.computed.currentUser.call(instance)
  });
  Object.defineProperty(instance, 'routeMetrics', {
    get: () => TechnicianRouteView.computed.routeMetrics.call(instance)
  });
  Object.defineProperty(instance, 'filteredIncidents', {
    get: () => TechnicianRouteView.computed.filteredIncidents.call(instance)
  });
  Object.defineProperty(instance, 'diagnosisLength', {
    get: () => TechnicianRouteView.computed.diagnosisLength.call(instance)
  });
  Object.defineProperty(instance, 'actionLength', {
    get: () => TechnicianRouteView.computed.actionLength.call(instance)
  });
  Object.defineProperty(instance, 'isDiagnosisValid', {
    get: () => TechnicianRouteView.computed.isDiagnosisValid.call(instance)
  });
  Object.defineProperty(instance, 'isActionValid', {
    get: () => TechnicianRouteView.computed.isActionValid.call(instance)
  });
  Object.defineProperty(instance, 'canResolve', {
    get: () => TechnicianRouteView.computed.canResolve.call(instance)
  });

  // Bind methods
  if (TechnicianRouteView.methods) {
    for (const [name, fn] of Object.entries(TechnicianRouteView.methods)) {
      instance[name] = fn.bind(instance);
    }
  }

  return instance;
}

// ---------------------------------------------------------------------
// TEST GROUP 1: Field Technician Authentication & RBAC (RF-04)
// ---------------------------------------------------------------------
console.log('--- Group 1: Field Technician Authentication Flow ---');

clearSession();
const unauthView = createRouteInstance();
assert('1.1 Unauthenticated technician starts in login state', unauthView.isAuthenticated === false);

// Authenticate with TECHNICIAN role
setInternalSession(
  { id: 2, name: 'Jordi Técnico Ruta', email: 'jordi.ruta@vendguard.internal', role: 'TECHNICIAN' },
  'fake_tech_token_123'
);
const authView = createRouteInstance();
assert('1.2 Authenticates user with TECHNICIAN role', authView.isAuthenticated === true);
assert('1.3 CurrentUser returns technician details', authView.currentUser?.name === 'Jordi Técnico Ruta');

// Non-technician role (COORDINATOR) is rejected for route view
setInternalSession(
  { id: 1, name: 'Carles Coordinador', email: 'coord@vendguard.internal', role: 'COORDINATOR' },
  'fake_coord_token'
);
const coordView = createRouteInstance();
assert('1.4 Blocks access if user is COORDINATOR rather than TECHNICIAN', coordView.isAuthenticated === false);

// Restore technician session
setInternalSession(
  { id: 2, name: 'Jordi Técnico Ruta', email: 'jordi.ruta@vendguard.internal', role: 'TECHNICIAN' },
  'fake_tech_token_123'
);

// ---------------------------------------------------------------------
// TEST GROUP 2: Smartphone Vertical Layout & Task List (RNF-01, RF-07)
// ---------------------------------------------------------------------
console.log('\n--- Group 2: Smartphone Vertical Layout & Task List ---');

assert('2.1 Template container has mobile constraint max-width: 500px',
  TechnicianRouteView.template.includes('max-width: 500px') &&
  TechnicianRouteView.template.includes('vg-technician-route')
);

assert('2.2 RouteMetrics accurately summarizes tasks',
  authView.routeMetrics.total === 3 &&
  authView.routeMetrics.assigned === 1 &&
  authView.routeMetrics.inProgress === 1 &&
  authView.routeMetrics.pendingParts === 1 &&
  authView.routeMetrics.critical === 1
);

// Sorted order: CRITICAL (102) first
assert('2.3 Sorts CRITICAL urgency first in route list',
  authView.filteredIncidents[0].ticket_code === 'INC-2026-0102' &&
  authView.filteredIncidents[0].urgency === 'CRITICAL'
);

// Status filtering
authView.filterStatus = 'IN_PROGRESS';
assert('2.4 Filter IN_PROGRESS returns 1 incident', authView.filteredIncidents.length === 1 && authView.filteredIncidents[0].id === 102);

authView.filterStatus = 'PENDING_PARTS';
assert('2.5 Filter PENDING_PARTS returns 1 incident', authView.filteredIncidents.length === 1 && authView.filteredIncidents[0].id === 103);

authView.filterStatus = 'ALL';
assert('2.6 Reset filter ALL returns all 3 incidents', authView.filteredIncidents.length === 3);

// Direct phone link in template (RNF-01)
assert('2.7 Template contains click-to-call link for concierge (tel:)',
  TechnicianRouteView.template.includes("'tel:' + incident.location")
);

// ---------------------------------------------------------------------
// TEST GROUP 3: "Iniciar intervención" Action (RF-07 / EARS 7.1, 7.3)
// ---------------------------------------------------------------------
console.log('\n--- Group 3: Start / Resume Intervention Action ---');

let startCalledId = null;
api.technician.startIncident = async (id) => {
  startCalledId = id;
  return {
    success: true,
    data: { id, status: 'IN_PROGRESS', started_at: '2026-09-22T09:00:00Z' }
  };
};

const assignedInc = authView.incidents.find(i => i.status === 'ASSIGNED');
assert('3.1 Assigned incident exists in test route', assignedInc !== undefined);

await authView.startIntervention(assignedInc);

assert('3.2 startIntervention calls api.technician.startIncident with correct ID', startCalledId === assignedInc.id);
assert('3.3 Incident status transitions to IN_PROGRESS', assignedInc.status === 'IN_PROGRESS');
assert('3.4 Incident started_at is recorded', assignedInc.started_at === '2026-09-22T09:00:00Z');

const startedEmits = authView.getEmits().filter(e => e.evt === 'started');
assert('3.5 Emits "started" event with incident ID', startedEmits.length === 1 && startedEmits[0].val.incidentId === assignedInc.id);

// Resume from PENDING_PARTS
const pausedInc = authView.incidents.find(i => i.status === 'PENDING_PARTS');
await authView.startIntervention(pausedInc);
assert('3.6 Resuming from PENDING_PARTS transitions back to IN_PROGRESS', pausedInc.status === 'IN_PROGRESS');

// ---------------------------------------------------------------------
// TEST GROUP 4: "Pausar por repuesto" Modal (RF-07 / EARS 7.2)
// ---------------------------------------------------------------------
console.log('\n--- Group 4: Pause by Replacement Part Modal ---');

let pauseCalledId = null;
let pauseCalledReason = null;
api.technician.pauseIncident = async (id, reason) => {
  pauseCalledId = id;
  pauseCalledReason = reason;
  return {
    success: true,
    data: { id, status: 'PENDING_PARTS' }
  };
};

authView.openPauseModal(assignedInc);
assert('4.1 openPauseModal opens modal and selects incident',
  authView.showPauseModal === true &&
  authView.selectedIncident?.id === assignedInc.id
);

// Empty reason is blocked
authView.pauseReason = '   ';
await authView.submitPause();
assert('4.2 Blank pause reason is rejected with error',
  authView.pauseError.length > 0 &&
  pauseCalledId === null
);

// Valid reason
const partNote = 'Resistencia térmica blindada 800W para caldera de café. Ref. RES-800W';
authView.pauseReason = partNote;
await authView.submitPause();

assert('4.3 Valid pause calls api.technician.pauseIncident', pauseCalledId === assignedInc.id && pauseCalledReason === partNote);
assert('4.4 Incident status updated to PENDING_PARTS', assignedInc.status === 'PENDING_PARTS');
assert('4.5 Incident stores pending_parts_reason', assignedInc.pending_parts_reason === partNote);
assert('4.6 showPauseModal closed after submission', authView.showPauseModal === false);

const pausedEmits = authView.getEmits().filter(e => e.evt === 'paused');
assert('4.7 Emits "paused" event with reason', pausedEmits.length === 1 && pausedEmits[0].val.reason === partNote);

// ---------------------------------------------------------------------
// TEST GROUP 5: Strict Resolution Modal & 20 Chars / Field (RF-08 / EARS 8.1, 8.2)
// ---------------------------------------------------------------------
console.log('\n--- Group 5: Strict Resolution Modal & 20 Chars per Field ---');

let resolveCalledId = null;
let resolveCalledDiag = null;
let resolveCalledAct = null;
api.technician.resolveIncident = async (id, diag, act) => {
  resolveCalledId = id;
  resolveCalledDiag = diag;
  resolveCalledAct = act;
  return {
    success: true,
    data: { id, status: 'RESOLVED', resolved_at: '2026-09-22T10:00:00Z' }
  };
};

const inProgressInc = authView.incidents.find(i => i.id === 102);
authView.openResolveModal(inProgressInc);

assert('5.1 openResolveModal opens modal and sets incident',
  authView.showResolveModal === true &&
  authView.selectedIncident?.id === 102
);

// Both fields empty => invalid
assert('5.2 Empty fields calculate isDiagnosisValid = false', authView.isDiagnosisValid === false);
assert('5.3 Empty fields calculate isActionValid = false', authView.isActionValid === false);
assert('5.4 canResolve is false', authView.canResolve === false);

// Diagnosis 19 chars (1 under threshold)
authView.resolveDiagnosis = '1234567890123456789'; // 19 chars
authView.resolveAction = 'Sustituido sensor de temperatura NTC calibrado a 4 grados.'; // >20 chars
assert('5.5 Diagnosis with 19 chars fails validation (EARS 8.2)',
  authView.diagnosisLength === 19 &&
  authView.isDiagnosisValid === false &&
  authView.canResolve === false
);

// Action 19 chars (1 under threshold)
authView.resolveDiagnosis = 'Termostato digital descalibrado marcando 14 grados.'; // >20 chars
authView.resolveAction = '1234567890123456789'; // 19 chars
assert('5.6 Action with 19 chars fails validation (EARS 8.2)',
  authView.actionLength === 19 &&
  authView.isActionValid === false &&
  authView.canResolve === false
);

// Submitting when invalid is blocked
await authView.submitResolve();
assert('5.7 submitResolve blocked when fields < 20 chars',
  authView.resolveError.length > 0 &&
  resolveCalledId === null
);

// Valid inputs (both >= 20 characters)
const validDiagnosis = 'Sonda NTC de temperatura descalibrada registrando 14 grados.'; // 60 chars
const validAction = 'Sustitución de sonda NTC y verificación de ciclo a 3.5 grados.'; // 62 chars

authView.resolveDiagnosis = validDiagnosis;
authView.resolveAction = validAction;

assert('5.8 With both fields >= 20 chars, isDiagnosisValid = true', authView.isDiagnosisValid === true);
assert('5.9 With both fields >= 20 chars, isActionValid = true', authView.isActionValid === true);
assert('5.10 With both fields >= 20 chars, canResolve = true', authView.canResolve === true);

await authView.submitResolve();

assert('5.11 Dispatches api.technician.resolveIncident with ID 102', resolveCalledId === 102);
assert('5.12 Passed valid diagnosis text', resolveCalledDiag === validDiagnosis);
assert('5.13 Passed valid corrective action text', resolveCalledAct === validAction);
assert('5.14 Modal closed upon resolution', authView.showResolveModal === false);
assert('5.15 Resolved incident is removed from active route', authView.incidents.find(i => i.id === 102) === undefined);

const resolvedEmits = authView.getEmits().filter(e => e.evt === 'resolved');
assert('5.16 Emits "resolved" event with diagnosis and action',
  resolvedEmits.length === 1 &&
  resolvedEmits[0].val.incidentId === 102 &&
  resolvedEmits[0].val.diagnosis === validDiagnosis
);

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-38 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.log(' RESULT: FAILURES DETECTED IN TEST SUITE.');
  console.log('======================================================================\n');
  process.exit(1);
}
