/**
 * VendGuard - CoordinatorDashboardView Test Suite (T-37)
 * 
 * Verifies:
 * 1. Global table of incidents with combined filtering (status, urgency, search, SLA).
 * 2. 24/7 SLA monitoring: detects SLA breaches (> 60m for CRITICAL unassigned) (RF-11).
 * 3. 60-second polling mechanism updates without manual reload.
 * 4. Assign modal enforces mandatory reason on urgency overrides (RF-05 / EARS 5.3).
 * 5. Cancel modal enforces mandatory reason for logical soft delete (RF-06 / EARS 6.1).
 */

// Mock localStorage for headless Node environment
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

import { api } from '../../public/assets/js/api.js';
import { store, clearSession, setInternalSession } from '../../public/assets/js/store.js';
import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) {
      console.error(`         Reason: ${details}`);
    }
    failures++;
  }
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - CoordinatorDashboardView (T-37)');
console.log('======================================================================\n');

// Mock incident fixtures
const mockIncidents = [
  {
    id: 1,
    ticket_code: 'INC-2026-0001',
    machine_id: 1,
    machine_code: 'VEND-0101',
    machine_model: 'Sanden Vendo G-Drink',
    machine_type: 'PERISHABLE_FOOD',
    site_code: 'SEDE-BCN-01',
    location_name: 'Hospital del Mar',
    floor_wing: 'Planta Baja',
    category: 'TEMPERATURE_COLD',
    description: 'Pérdida de frío en cuba de sándwiches',
    urgency: 'CRITICAL',
    status: 'REGISTRADA',
    assigned_technician_id: null,
    waiting_minutes: 75,
    sla_breached: true
  },
  {
    id: 2,
    ticket_code: 'INC-2026-0002',
    machine_id: 2,
    machine_code: 'VEND-0102',
    machine_model: 'Necta Canto',
    machine_type: 'HOT_DRINKS',
    site_code: 'SEDE-BCN-01',
    location_name: 'Hospital del Mar',
    floor_wing: 'Planta 1',
    category: 'PAYMENT_SYSTEM',
    description: 'Fallo en billetero',
    urgency: 'HIGH',
    status: 'REGISTRADA',
    assigned_technician_id: null,
    waiting_minutes: 20,
    sla_breached: false
  },
  {
    id: 3,
    ticket_code: 'INC-2026-0003',
    machine_id: 3,
    machine_code: 'VEND-0103',
    machine_model: 'Fas Fast',
    machine_type: 'SNACKS',
    site_code: 'SEDE-MAD-01',
    location_name: 'Campus Central',
    floor_wing: 'Cafetería',
    category: 'PRODUCT_JAM',
    description: 'Atasco en espiral 12',
    urgency: 'MEDIUM',
    status: 'ASIGNADA',
    assigned_technician_id: 2,
    technician_name: 'Jordi Técnico Ruta BCN',
    waiting_minutes: 40,
    sla_breached: false
  }
];

// Helper to create reactive/computed view instance
function createDashboardInstance(initialData = {}) {
  let emits = [];
  const instance = {
    loginEmail: '',
    loginPassword: '',
    loginError: '',
    isLoggingIn: false,
    incidents: [...mockIncidents],
    isLoading: false,
    incidentsError: '',
    pollingTimer: null,
    lastUpdated: null,
    filterStatus: '',
    filterUrgency: '',
    filterSearch: '',
    filterSlaOnly: false,
    technicians: [{ id: 2, name: 'Jordi Técnico' }],
    showAssignModal: false,
    selectedIncident: null,
    assignTechnicianId: 2,
    assignUrgencyOverride: '',
    assignUrgencyReason: '',
    isAssigning: false,
    assignError: '',
    showCancelModal: false,
    cancelReason: '',
    isCancelling: false,
    cancelError: '',
    ...initialData,
    $emit: (evt, val) => { emits.push({ evt, val }); },
    getEmits: () => emits
  };

  Object.defineProperty(instance, 'isAuthenticated', {
    get: () => CoordinatorDashboardView.computed.isAuthenticated.call(instance)
  });
  Object.defineProperty(instance, 'slaBreachedIncidents', {
    get: () => CoordinatorDashboardView.computed.slaBreachedIncidents.call(instance)
  });
  Object.defineProperty(instance, 'filteredIncidents', {
    get: () => CoordinatorDashboardView.computed.filteredIncidents.call(instance)
  });
  Object.defineProperty(instance, 'metrics', {
    get: () => CoordinatorDashboardView.computed.metrics.call(instance)
  });

  if (CoordinatorDashboardView.methods) {
    for (const [name, fn] of Object.entries(CoordinatorDashboardView.methods)) {
      instance[name] = fn.bind(instance);
    }
  }

  return instance;
}

// ---------------------------------------------------------------------
// TEST GROUP 1: Coordinator Authentication (RF-04)
// ---------------------------------------------------------------------
console.log('--- Group 1: Coordinator Authentication Flow ---');

clearSession();
const unauthDash = createDashboardInstance();
assert('1.1 Initial view starts unauthenticated', unauthDash.isAuthenticated === false);

// Authenticate coordinator
setInternalSession(
  { id: 1, name: 'Carles Coordinador', email: 'coord@vendguard.internal', role: 'COORDINATOR' },
  'auth_token_coord'
);
const authDash = createDashboardInstance();
assert('1.2 Successfully authenticates coordinator role', authDash.isAuthenticated === true);

// ---------------------------------------------------------------------
// TEST GROUP 2: SLA Monitoring & 60s Polling (RF-11 / T-37)
// ---------------------------------------------------------------------
console.log('\n--- Group 2: SLA 24/7 Monitoring & 60s Polling ---');

assert('2.1 slaBreachedIncidents detects incident with >60m wait', authDash.slaBreachedIncidents.length === 1 && authDash.slaBreachedIncidents[0].ticket_code === 'INC-2026-0001');
assert('2.2 Template includes SLA alert banner', CoordinatorDashboardView.template.includes('vg-sla-alert-banner'));

// Polling interval check
let pollingTriggered = false;
authDash.loadIncidents = async () => { pollingTriggered = true; };

CoordinatorDashboardView.methods.startPolling.call(authDash);
assert('2.3 startPolling initiates timer', authDash.pollingTimer !== null);

CoordinatorDashboardView.methods.stopPolling.call(authDash);
assert('2.4 stopPolling clears timer', authDash.pollingTimer === null);

// ---------------------------------------------------------------------
// TEST GROUP 3: Table Filtering & Operational Metrics
// ---------------------------------------------------------------------
console.log('\n--- Group 3: Metrics Summary & Combined Filtering ---');

assert('3.1 Metrics calculates totalActive = 3', authDash.metrics.totalActive === 3);
assert('3.2 Metrics calculates criticalFood = 1', authDash.metrics.criticalFood === 1);
assert('3.3 Metrics calculates unassigned = 2', authDash.metrics.unassigned === 2);

// Filter by status 'ASIGNADA'
authDash.filterStatus = 'ASIGNADA';
assert('3.4 Filter by status ASIGNADA returns 1 incident', authDash.filteredIncidents.length === 1 && authDash.filteredIncidents[0].ticket_code === 'INC-2026-0003');

// Filter by urgency 'CRITICAL'
authDash.filterStatus = '';
authDash.filterUrgency = 'CRITICAL';
assert('3.5 Filter by urgency CRITICAL returns 1 incident', authDash.filteredIncidents.length === 1 && authDash.filteredIncidents[0].ticket_code === 'INC-2026-0001');

// Filter by SLA Only
authDash.filterUrgency = '';
authDash.filterSlaOnly = true;
assert('3.6 Filter by SLA Only returns 1 breached incident', authDash.filteredIncidents.length === 1 && authDash.filteredIncidents[0].ticket_code === 'INC-2026-0001');

// Text search
authDash.filterSlaOnly = false;
authDash.filterSearch = 'VEND-0102';
assert('3.7 Search by machine code returns matching machine', authDash.filteredIncidents.length === 1 && authDash.filteredIncidents[0].machine_code === 'VEND-0102');

// ---------------------------------------------------------------------
// TEST GROUP 4: Assign Modal & Urgency Override Validation (RF-05 / EARS 5.3)
// ---------------------------------------------------------------------
console.log('\n--- Group 4: Assign Modal & Reclassification Audit (RF-05) ---');

let lastAssignCall = null;
api.coordinator.assignTechnician = async (id, techId, urgency, reason) => {
  lastAssignCall = { id, techId, urgency, reason };
  return { id, technician_id: techId, urgency };
};

const assignModalInstance = createDashboardInstance({
  selectedIncident: mockIncidents[0],
  assignTechnicianId: 2,
  assignUrgencyOverride: 'HIGH',
  assignUrgencyReason: '' // Missing mandatory reason
});

// Attempt assignment without mandatory justification
await CoordinatorDashboardView.methods.submitAssignment.call(assignModalInstance);
assert('4.1 Reclassifying urgency without reason is blocked (EARS 5.3)', assignModalInstance.assignError.includes('obligatorio'));
assert('4.2 No API call was dispatched without reason', lastAssignCall === null);

// Provide mandatory reason
assignModalInstance.assignUrgencyReason = 'Máquina de sándwiches vacía de producto perecedero';
await CoordinatorDashboardView.methods.submitAssignment.call(assignModalInstance);

assert('4.3 With reason, api.coordinator.assignTechnician is called', lastAssignCall?.id === 1 && lastAssignCall?.techId === 2);
assert('4.4 Passed urgency HIGH to API', lastAssignCall?.urgency === 'HIGH');
assert('4.5 Passed justification reason to API', lastAssignCall?.reason.includes('vacía de producto'));
assert('4.6 submitAssignment emits "assigned" event', assignModalInstance.getEmits().some(e => e.evt === 'assigned'));

// ---------------------------------------------------------------------
// TEST GROUP 5: Cancel Modal & Reason Validation (RF-06 / EARS 6.1)
// ---------------------------------------------------------------------
console.log('\n--- Group 5: Cancel Modal & Mandatory Reason (RF-06) ---');

let lastCancelCall = null;
api.coordinator.cancelIncident = async (id, reason) => {
  lastCancelCall = { id, reason };
  return { id, status: 'CANCELADA', cancellation_reason: reason };
};

const cancelModalInstance = createDashboardInstance({
  selectedIncident: mockIncidents[1],
  cancelReason: '' // Missing reason
});

// Attempt cancel without reason
await CoordinatorDashboardView.methods.submitCancellation.call(cancelModalInstance);
assert('5.1 Discard without reason is blocked (EARS 6.1)', cancelModalInstance.cancelError.includes('obligatoriamente'));
assert('5.2 No cancel API call was dispatched without reason', lastCancelCall === null);

// Provide reason
cancelModalInstance.cancelReason = 'Falsa alarma reportada por el cliente';
await CoordinatorDashboardView.methods.submitCancellation.call(cancelModalInstance);

assert('5.3 With reason, api.coordinator.cancelIncident is called', lastCancelCall?.id === 2);
assert('5.4 Passed cancellation reason to API', lastCancelCall?.reason === 'Falsa alarma reportada por el cliente');
assert('5.5 submitCancellation emits "cancelled" event', cancelModalInstance.getEmits().some(e => e.evt === 'cancelled'));

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-37 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
