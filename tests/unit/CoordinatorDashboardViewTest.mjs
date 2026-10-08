/**
 * VendGuard - CoordinatorDashboardView Test Suite (T-37)
 * 
 * Verifies:
 * 1. Global table of incidents with combined filtering (status, urgency, search, SLA).
 * 2. 24/7 SLA monitoring: detects SLA breaches (> 60m for CRITICAL unassigned) (RF-11).
 * 3. 60-second polling mechanism updates without manual reload.
 * 4. Assign modal enforces mandatory reason on urgency overrides (RF-05 / EARS 5.3).
 * 5. Cancel modal enforces mandatory reason for logical soft delete (RF-06 / EARS 6.1).
 * 6. Per-row "Ver detalle" trigger with inspection icon, kept independent from the
 *    preexisting assign/cancel quick actions (RF-01 / T-IDM-14).
 * 7. Reactive mount of the integral incident detail modal over that trigger, refreshing
 *    the corresponding triage row in place on incident-updated (RF-01/RF-07/RF-08, T-IDM-15).
 * 8. Per-row quick action gating by incident status (EARS 5.5 / EARS 6.4) plus the
 *    stale-state defense that re-syncs the affected row after a backend rejection
 *    (EARS 5.6 / EARS 6.5).
 * 9. State-aware copy of the consolidated site batch: a mixed batch is titled, counted,
 *    chipped and confirmed as a reassignment/consolidation while a batch without owners
 *    keeps the plain assignment wording (RF-MAP-09, T-MAP-23).
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
console.log(' VendGuard: Frontend Test Suite - CoordinatorDashboardView (T-37, T-IDM-14, T-IDM-15)');
console.log('======================================================================\n');

// Mock incident fixtures
const mockIncidents = [
  {
    id: 1,
    ticket_code: 'INC-2026-0001',
    machine_id: 1,
    location_id: 1,
    machine_code: 'VEND-0101',
    machine_model: 'Sanden Vendo G-Drink',
    machine_type: 'PERISHABLE_FOOD',
    location_site_code: 'SEDE-BCN-01',
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
    location_id: 1,
    machine_code: 'VEND-0102',
    machine_model: 'Necta Canto',
    machine_type: 'HOT_DRINKS',
    location_site_code: 'SEDE-BCN-01',
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
    location_id: 3,
    machine_code: 'VEND-0103',
    machine_model: 'Fas Fast',
    machine_type: 'SNACKS',
    location_site_code: 'SEDE-MAD-01',
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
    showBulkAssignModal: false,
    bulkAssignSite: null,
    bulkAssignIncidents: [],
    bulkAssignTechnicianId: 2,
    bulkAssignUrgencyOverride: '',
    bulkAssignUrgencyReason: '',
    bulkAssignReassignmentReason: '',
    bulkAssignError: '',
    isBulkAssigning: false,
    showCancelModal: false,
    cancelReason: '',
    isCancelling: false,
    cancelError: '',
    showDetailModal: false,
    selectedDetailIncident: null,
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
  Object.defineProperty(instance, 'detailIncidentId', {
    get: () => CoordinatorDashboardView.computed.detailIncidentId.call(instance)
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

// Filter by status 'ASIGNADA' (legacy Spanish key)
authDash.filterStatus = 'ASIGNADA';
assert('3.4 Filter by status ASIGNADA returns 1 incident', authDash.filteredIncidents.length === 1 && authDash.filteredIncidents[0].ticket_code === 'INC-2026-0003');

// Filter by status 'ASSIGNED' (canonical English key from database)
authDash.filterStatus = 'ASSIGNED';
assert('3.4a Filter by status ASSIGNED returns 1 incident', authDash.filteredIncidents.length === 1 && authDash.filteredIncidents[0].ticket_code === 'INC-2026-0003');

// Filter by status 'REGISTERED' (canonical English key)
authDash.filterStatus = 'REGISTERED';
assert('3.4b Filter by status REGISTERED returns 2 incidents', authDash.filteredIncidents.length === 2);

// Filter by status on incident list containing English DB statuses
authDash.incidents = [
  { id: 10, ticket_code: 'INC-EN-01', status: 'IN_PROGRESS' },
  { id: 11, ticket_code: 'INC-EN-02', status: 'RESOLVED' }
];
authDash.filterStatus = 'IN_PROGRESS';
assert('3.4c Filter by status IN_PROGRESS on DB incidents returns 1 match', authDash.filteredIncidents.length === 1 && authDash.filteredIncidents[0].ticket_code === 'INC-EN-01');
authDash.filterStatus = 'EN_CURSO';
assert('3.4d Filter by status EN_CURSO on DB incidents matches IN_PROGRESS', authDash.filteredIncidents.length === 1 && authDash.filteredIncidents[0].ticket_code === 'INC-EN-01');
authDash.filterStatus = 'RESOLVED';
assert('3.4e Filter by status RESOLVED on DB incidents returns 1 match', authDash.filteredIncidents.length === 1 && authDash.filteredIncidents[0].ticket_code === 'INC-EN-02');
authDash.filterStatus = 'RESUELTA';
assert('3.4f Filter by status RESUELTA on DB incidents matches RESOLVED', authDash.filteredIncidents.length === 1 && authDash.filteredIncidents[0].ticket_code === 'INC-EN-02');

// Restore original mock incidents
authDash.incidents = [...mockIncidents];

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

// Site-code search: the incidents API serializes it as location_site_code (Incident.php).
// Regression: the filter used to read inc.site_code, a field the API never sends, so
// searching or arriving from a territorial-map card returned zero rows.
authDash.filterSearch = 'sede-bcn-01';
assert('3.8 Search by site code (case-insensitive) returns every incident of the site', authDash.filteredIncidents.length === 2 && authDash.filteredIncidents.every(i => i.location_site_code === 'SEDE-BCN-01'));
authDash.filterSearch = 'SEDE-MAD';
assert('3.9 Search by site code prefix returns only that site', authDash.filteredIncidents.length === 1 && authDash.filteredIncidents[0].location_site_code === 'SEDE-MAD-01');
authDash.filterSearch = 'Hospital del Mar';
assert('3.10 Search by location name keeps working', authDash.filteredIncidents.length === 2);
authDash.filterSearch = '';

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

// ---------------------------------------------------------------------
// TEST GROUP 6: Territorial Map Tab Integration (RF-MAP-09 / T-MAP-16)
// ---------------------------------------------------------------------
console.log('\n--- Group 6: Territorial Map Tab Integration ---');

assert('6.1 View registers CoordinatorTerritorialMapTab component',
  CoordinatorDashboardView.components?.CoordinatorTerritorialMapTab !== undefined);

assert('6.2 Tab bar contains the "Mapa Territorial" button',
  CoordinatorDashboardView.template.includes('data-testid="tab-mapa-territorial"') &&
  CoordinatorDashboardView.template.includes('🗺️ Mapa Territorial') &&
  CoordinatorDashboardView.template.includes("activeTab = 'mapa-territorial'"));

assert('6.3 Territorial map content mounts conditionally on the active tab',
  CoordinatorDashboardView.template.includes("v-else-if=\"activeTab === 'mapa-territorial'\"") &&
  CoordinatorDashboardView.template.includes('<CoordinatorTerritorialMapTab'));

assert('6.4 Tab is accessible from the triage dashboard with seamless switching',
  CoordinatorDashboardView.template.includes("@click=\"activeTab = 'incidents'\"") &&
  CoordinatorDashboardView.template.includes("@click=\"activeTab = 'mapa-territorial'\""));

// Alternative tab switching keeps the map mounted only while active
const mapTabView = createDashboardInstance();
mapTabView.activeTab = 'mapa-territorial';
assert('6.5 Map tab becomes the active tab on click simulation', mapTabView.activeTab === 'mapa-territorial');
mapTabView.activeTab = 'incidents';
assert('6.6 Switching back to triage restores the incidents tab without residue', mapTabView.activeTab === 'incidents');

// Assignment flow started from the territorial map (RF-MAP-09): the bulk assignment
// modal opens directly over the site pending incidents (no triage-list redirect).
const assignFlowView = createDashboardInstance();
assignFlowView.activeTab = 'mapa-territorial';
CoordinatorDashboardView.methods.handleTerritorialAssign.call(assignFlowView, { locationId: 9, siteCode: 'SEDE-BCN-03' });
assert('6.7 Unassigned site click opens the bulk assignment modal for that site', assignFlowView.showBulkAssignModal === true && assignFlowView.bulkAssignSite.siteCode === 'SEDE-BCN-03');
assert('6.8 A site without pending incidents opens an empty bulk modal (submit disabled by contract)', assignFlowView.bulkAssignIncidents.length === 0 && assignFlowView.bulkAssignSite.name === 'SEDE-BCN-03');

// End-to-end: arriving from a map card must open the bulk modal over that site's
// pending incidents (user-reported regression).
const mapSearchView = createDashboardInstance();
mapSearchView.activeTab = 'mapa-territorial';
CoordinatorDashboardView.methods.handleTerritorialAssign.call(mapSearchView, { locationId: 1, siteCode: 'SEDE-BCN-01' });
assert('6.10 A map-card assignment opens the bulk modal over that site pending incidents',
  mapSearchView.showBulkAssignModal === true && mapSearchView.bulkAssignIncidents.length === 2
    && mapSearchView.bulkAssignIncidents.every(i => (i.location_site_code || i.site_code) === 'SEDE-BCN-01'));

// ---------------------------------------------------------------------
// TEST GROUP 7: Bulk Site Assignment Modal (RF-MAP-09)
// ---------------------------------------------------------------------
console.log('\n--- Group 7: Bulk Site Assignment Modal (RF-MAP-09) ---');

const bulkView = createDashboardInstance();
CoordinatorDashboardView.methods.handleTerritorialAssign.call(bulkView, { locationId: 1, siteCode: 'SEDE-BCN-01' });
assert('7.1 Map card opens the bulk modal over the site pending incidents',
  bulkView.showBulkAssignModal === true && bulkView.bulkAssignSite.siteCode === 'SEDE-BCN-01'
    && bulkView.bulkAssignIncidents.length === 2
    && bulkView.bulkAssignIncidents.every(i => i.location_site_code === 'SEDE-BCN-01'));
assert('7.2 Site name is derived from the incidents and the first technician comes preselected',
  bulkView.bulkAssignSite.name === 'Hospital del Mar' && bulkView.bulkAssignTechnicianId === 2);
assert('7.3 Closed or resolved incidents of the site never enter the bulk list',
  bulkView.bulkAssignIncidents.every(i => !['CERRADA', 'CLOSED', 'CANCELADA', 'CANCELLED', 'RESUELTA', 'RESOLVED'].includes(String(i.status || '').toUpperCase())));

// EARS 5.3: reclassifying urgency without a justified reason is blocked
bulkView.bulkAssignUrgencyOverride = 'HIGH';
await CoordinatorDashboardView.methods.submitBulkAssignment.call(bulkView);
assert('7.4 Bulk urgency reclassification without reason is blocked (EARS 5.3)',
  bulkView.bulkAssignError.includes('obligatorio') && bulkView.isBulkAssigning === false);

// Successful bulk assignment: one API call per pending incident
const bulkCalls = [];
api.coordinator.assignTechnician = async (id, techId, urgency, reason) => {
  bulkCalls.push({ id, techId, urgency, reason });
  return { id, technician_id: techId };
};
bulkView.bulkAssignUrgencyReason = 'Corte de refrigeración general de la sede';
await CoordinatorDashboardView.methods.submitBulkAssignment.call(bulkView);
assert('7.5 Confirming dispatches one assignment per pending incident and closes the modal',
  bulkCalls.length === 2 && bulkCalls.every(c => c.techId === 2) && bulkCalls.every(c => c.reason.includes('refrigeración'))
    && bulkView.showBulkAssignModal === false
    && bulkView.getEmits().some(e => e.evt === 'bulk-assigned'));

// Partial failure: modal stays open listing the failed tickets for retry
const partialView = createDashboardInstance();
CoordinatorDashboardView.methods.handleTerritorialAssign.call(partialView, { locationId: 1, siteCode: 'SEDE-BCN-01' });
let partialCalls = 0;
api.coordinator.assignTechnician = async (id) => {
  partialCalls++;
  if (id === mockIncidents[1].id) {
    throw new Error('La incidencia ya fue cerrada por otro operario.');
  }
  return { id };
};
await CoordinatorDashboardView.methods.submitBulkAssignment.call(partialView);
assert('7.6 A per-incident failure keeps the modal open with the failed tickets for retry',
  partialView.showBulkAssignModal === true && partialCalls === 2
    && partialView.bulkAssignError.includes('ya fue cerrada')
    && partialView.bulkAssignIncidents.length === 1
    && partialView.bulkAssignIncidents[0].id === mockIncidents[1].id);

assert('7.7 Submit stays disabled without pending incidents or technician (template contract)',
  CoordinatorDashboardView.template.includes('isBulkAssigning || !bulkAssignTechnicianId || bulkAssignIncidents.length === 0')
    && CoordinatorDashboardView.template.includes('data-testid="bulk-assign-form"'));

// Template passes the coordinator context to the map tab
assert('6.9 Map tab receives the coordinator user context',
  CoordinatorDashboardView.template.includes(':current-user="currentUser"') &&
  CoordinatorDashboardView.template.includes('@assign-incidents="handleTerritorialAssign"'));

// ---------------------------------------------------------------------
// TEST GROUP 8: Refunds Inbox Tab Integration (RF-REF-03, RF-REF-07, RF-REF-08 / T-REF-18)
// ---------------------------------------------------------------------
console.log('\n--- Group 8: Refunds Inbox Tab Integration ---');

assert('8.1 View registers CoordinatorRefundsTab component',
  CoordinatorDashboardView.components?.CoordinatorRefundsTab !== undefined);

assert('8.2 Tab bar contains the "Reintegros" button',
  CoordinatorDashboardView.template.includes('data-testid="tab-refunds"') &&
  CoordinatorDashboardView.template.includes('💶 Reintegros') &&
  CoordinatorDashboardView.template.includes("activeTab = 'refunds'"));

assert('8.3 Refunds inbox mounts conditionally on the active tab',
  CoordinatorDashboardView.template.includes("v-else-if=\"activeTab === 'refunds'\"") &&
  CoordinatorDashboardView.template.includes('<CoordinatorRefundsTab'));

assert('8.4 The triage tab stays the default landing section',
  CoordinatorDashboardView.data().activeTab === 'incidents');

// ---------------------------------------------------------------------
// TEST GROUP 9: Incident Detail Trigger Button (RF-01 / T-IDM-14)
// ---------------------------------------------------------------------
console.log('\n--- Group 9: Incident Detail Trigger Button (RF-01 / T-IDM-14) ---');

// The trigger is rendered inside the v-for row, so the slice between the row opening
// tag and its first closing </tr> proves every triage row carries it in the actions cell.
const triageTemplate = CoordinatorDashboardView.template;
const triageRowStart = triageTemplate.indexOf('v-for="inc in filteredIncidents"');
const triageRowEnd = triageTemplate.indexOf('</tr>', triageRowStart);
const triageRowBlock = triageTemplate.slice(triageRowStart, triageRowEnd);

assert('9.1 Every triage row renders the "Ver detalle" trigger with the inspection icon (RF-01.1)',
  triageRowStart !== -1 && triageRowEnd > triageRowStart &&
  triageRowBlock.includes('data-testid="btn-view-detail"') &&
  triageRowBlock.includes('🔍 Ver detalle'));

assert('9.2 The trigger sits in the actions column beside the intact quick actions (RF-01.1, RF-01.2)',
  triageRowBlock.includes('<!-- 7. Acciones -->') &&
  triageRowBlock.indexOf('<!-- 7. Acciones -->') < triageRowBlock.indexOf('btn-view-detail') &&
  triageRowBlock.includes('@click.stop="openDetailModal(inc)"') &&
  triageRowBlock.includes('@click="openQrLabelModal(inc)"') &&
  triageRowBlock.includes('@click="openAssignModal(inc)"') &&
  triageRowBlock.includes('@click="openCancelModal(inc)"'));

const detailTriggerView = createDashboardInstance();
CoordinatorDashboardView.methods.openDetailModal.call(detailTriggerView, mockIncidents[0]);
assert('9.3 The trigger records the chosen incident and flips only the detail state (RF-01.1)',
  detailTriggerView.selectedDetailIncident === mockIncidents[0] &&
  detailTriggerView.showDetailModal === true &&
  detailTriggerView.showAssignModal === false &&
  detailTriggerView.showCancelModal === false);

const independentActionsView = createDashboardInstance();
CoordinatorDashboardView.methods.openDetailModal.call(independentActionsView, mockIncidents[2]);
CoordinatorDashboardView.methods.openAssignModal.call(independentActionsView, mockIncidents[0]);
CoordinatorDashboardView.methods.openCancelModal.call(independentActionsView, mockIncidents[1]);
assert('9.4 Quick assign/discard keep their own lifecycle without disturbing the detail selection (RF-01.2)',
  independentActionsView.selectedIncident === mockIncidents[1] &&
  independentActionsView.showAssignModal === true && independentActionsView.showCancelModal === true &&
  independentActionsView.selectedDetailIncident === mockIncidents[2] &&
  independentActionsView.showDetailModal === true);

// ---------------------------------------------------------------------
// TEST GROUP 10: Reactive Detail Modal Mount & Row Refresh (RF-01/RF-07/RF-08 / T-IDM-15)
// ---------------------------------------------------------------------
console.log('\n--- Group 10: Reactive Detail Modal Mount & Row Refresh (T-IDM-15) ---');

assert('10.1 View registers CoordinatorIncidentDetailModal and mounts it with reactive bindings',
  CoordinatorDashboardView.components?.CoordinatorIncidentDetailModal !== undefined &&
  CoordinatorDashboardView.template.includes('<CoordinatorIncidentDetailModal') &&
  CoordinatorDashboardView.template.includes(':is-open="showDetailModal"') &&
  CoordinatorDashboardView.template.includes(':incident-id="detailIncidentId"') &&
  CoordinatorDashboardView.template.includes('@close="closeDetailModal"') &&
  CoordinatorDashboardView.template.includes('@incident-updated="handleIncidentUpdated"'));

const mountView = createDashboardInstance();
CoordinatorDashboardView.methods.openDetailModal.call(mountView, mockIncidents[0]);
assert('10.2 Pressing "Ver detalle" reactively opens the modal over the chosen incident (RF-01.1)',
  mountView.showDetailModal === true &&
  mountView.detailIncidentId === mockIncidents[0].id);

CoordinatorDashboardView.methods.closeDetailModal.call(mountView);
assert('10.3 Closing the modal clears the selection without residue',
  mountView.showDetailModal === false && mountView.detailIncidentId === null &&
  mountView.selectedDetailIncident === null);

// incident-updated refreshes ONLY the corresponding row, in place, without a page reload.
// The modal payload carries just the reason, so the view locates the row through the
// incident currently open in the modal (selectedDetailIncident).
const refreshedRow = { ...mockIncidents[0], status: 'CANCELADA', urgency: 'LOW' };
const freshServerList = [refreshedRow, mockIncidents[1], mockIncidents[2]];
let incidentListCalls = 0;
api.coordinator.getIncidents = async () => { incidentListCalls++; return freshServerList; };

const rowRefreshView = createDashboardInstance();
CoordinatorDashboardView.methods.openDetailModal.call(rowRefreshView, mockIncidents[0]);
const untouchedSecondRow = rowRefreshView.incidents[1];
const untouchedThirdRow = rowRefreshView.incidents[2];
await CoordinatorDashboardView.methods.handleIncidentUpdated.call(rowRefreshView, { reason: 'cancel' });
assert('10.4 incident-updated replaces only the corresponding row in place without a reload (RF-07.4)',
  incidentListCalls === 1 &&
  rowRefreshView.incidents.length === 3 &&
  rowRefreshView.incidents[0] === refreshedRow &&
  rowRefreshView.incidents[0].status === 'CANCELADA' &&
  rowRefreshView.incidents[1] === untouchedSecondRow &&
  rowRefreshView.incidents[2] === untouchedThirdRow &&
  rowRefreshView.isLoading === false);

// A ticket that left the coordinator list degrades to a silent full sync
api.coordinator.getIncidents = async () => [mockIncidents[1], mockIncidents[2]];
const vanishedView = createDashboardInstance();
CoordinatorDashboardView.methods.openDetailModal.call(vanishedView, mockIncidents[0]);
await CoordinatorDashboardView.methods.handleIncidentUpdated.call(vanishedView, { reason: 'cancel' });
assert('10.5 A vanished ticket falls back to a silent full sync without a page reload',
  vanishedView.incidents.length === 2 && vanishedView.isLoading === false);

// A failing background refresh keeps the table intact and never breaks the flow
api.coordinator.getIncidents = async () => { throw new Error('Red caída'); };
const failingRefreshView = createDashboardInstance();
CoordinatorDashboardView.methods.openDetailModal.call(failingRefreshView, mockIncidents[1]);
let refreshThrew = false;
try {
  await CoordinatorDashboardView.methods.handleIncidentUpdated.call(failingRefreshView, { reason: 'assign' });
} catch (refreshFailure) {
  refreshThrew = true;
}
assert('10.6 A failed row refresh keeps the table intact and degrades gracefully',
  refreshThrew === false &&
  failingRefreshView.incidents.length === 3 &&
  failingRefreshView.incidents[1].id === mockIncidents[1].id);

// ---------------------------------------------------------------------
// TEST GROUP 11: Per-Row Quick Action Gating by Status (EARS 5.5 / EARS 6.4)
// ---------------------------------------------------------------------
console.log('\n--- Group 11: Quick Action Gating by Status (EARS 5.5 / EARS 6.4) ---');

const gatingMethods = CoordinatorDashboardView.methods;
const mkStatusIncident = (status) => ({ id: 99, ticket_code: 'INC-2026-GATE', status });

assert('11.1 "Asignar" visible in REGISTRADA (EARS 5.5)',
  gatingMethods.canQuickAssign(mkStatusIncident('REGISTRADA')) === true);
assert('11.2 "Asignar" visible in canonical REGISTERED',
  gatingMethods.canQuickAssign(mkStatusIncident('REGISTERED')) === true);
assert('11.3 "Asignar" visible in REABIERTA/REOPENED (re-entry after warranty)',
  gatingMethods.canQuickAssign(mkStatusIncident('REABIERTA')) === true &&
  gatingMethods.canQuickAssign(mkStatusIncident('REOPENED')) === true);
assert('11.4 "Asignar" hidden in states with an active owner (reassignment stays in the detail modal, RF-07.3)',
  ['ASIGNADA', 'ASSIGNED', 'EN_CURSO', 'IN_PROGRESS', 'PENDIENTE_REPUESTO', 'PENDING_PARTS']
    .every(s => gatingMethods.canQuickAssign(mkStatusIncident(s)) === false));
assert('11.5 "Asignar" hidden in terminal states',
  ['RESUELTA', 'RESOLVED', 'CERRADA', 'CLOSED', 'CANCELADA', 'CANCELLED']
    .every(s => gatingMethods.canQuickAssign(mkStatusIncident(s)) === false));
assert('11.6 "Asignar" hidden without status (fail-safe)',
  gatingMethods.canQuickAssign(mkStatusIncident('')) === false);

assert('11.7 "Descartar" visible in every active status (EARS 6.4)',
  ['REGISTRADA', 'REGISTERED', 'REABIERTA', 'REOPENED', 'ASIGNADA', 'ASSIGNED', 'EN_CURSO', 'IN_PROGRESS', 'PENDIENTE_REPUESTO', 'PENDING_PARTS']
    .every(s => gatingMethods.canQuickCancel(mkStatusIncident(s)) === true));
assert('11.8 "Descartar" hidden in RESUELTA/CERRADA/CANCELADA (both languages)',
  ['RESUELTA', 'RESOLVED', 'CERRADA', 'CLOSED', 'CANCELADA', 'CANCELLED']
    .every(s => gatingMethods.canQuickCancel(mkStatusIncident(s)) === false));
assert('11.9 "Descartar" hidden without status (fail-safe)',
  gatingMethods.canQuickCancel(mkStatusIncident('')) === false);

assert('11.10 Row template gates "Asignar" with v-if (EARS 5.5)',
  triageRowBlock.includes('v-if="canQuickAssign(inc)"') &&
  triageRowBlock.includes('@click="openAssignModal(inc)"'));
assert('11.11 Row template gates "Descartar" with v-if (EARS 6.4)',
  triageRowBlock.includes('v-if="canQuickCancel(inc)"') &&
  triageRowBlock.includes('@click="openCancelModal(inc)"'));

// ---------------------------------------------------------------------
// TEST GROUP 12: Stale-State Defense on Quick Actions (EARS 5.6 / EARS 6.5)
// ---------------------------------------------------------------------
console.log('\n--- Group 12: Stale-State Defense on Quick Actions (EARS 5.6 / EARS 6.5) ---');

// The tray says REGISTRADA but the server already cancelled the ticket (another
// operator won the race). The 422 rejection must surface in the panel AND the
// affected row must be re-synced in place with the server truth.
api.coordinator.assignTechnician = async () => {
  throw new Error('Solo se pueden asignar incidencias en estado REGISTERED o REOPENED, o reasignar las que están en ASSIGNED, IN_PROGRESS o PENDING_PARTS. Estado actual: CANCELLED.');
};
api.coordinator.getIncidents = async () => [{ ...mockIncidents[0], status: 'CANCELLED' }];

const staleAssignView = createDashboardInstance({ selectedIncident: mockIncidents[0] });
await CoordinatorDashboardView.methods.submitAssignment.call(staleAssignView);
assert('12.1 A stale assignment surfaces the backend rejection in the panel (EARS 5.6)',
  staleAssignView.assignError.includes('REGISTERED'));
assert('12.2 The rejected row is re-synced in place with the server state (EARS 5.6)',
  staleAssignView.incidents[0].status === 'CANCELLED' &&
  staleAssignView.incidents.length === 3);

api.coordinator.cancelIncident = async () => {
  throw new Error('No se puede cancelar una incidencia en estado CLOSED.');
};
api.coordinator.getIncidents = async () => [{ ...mockIncidents[0], status: 'CLOSED' }];

const staleCancelView = createDashboardInstance({
  selectedIncident: mockIncidents[0],
  cancelReason: 'Motivo de descarte de prueba'
});
await CoordinatorDashboardView.methods.submitCancellation.call(staleCancelView);
assert('12.3 A stale discard surfaces the backend rejection in the panel (EARS 6.5)',
  staleCancelView.cancelError.includes('CLOSED'));
assert('12.4 The rejected row is re-synced in place with the server state (EARS 6.5)',
  staleCancelView.incidents[0].status === 'CLOSED' &&
  staleCancelView.incidents.length === 3);

// ---------------------------------------------------------------------
// TEST GROUP 13: Real Technician Roster from the Users Endpoint (EARS 3.9)
// ---------------------------------------------------------------------
console.log('\n--- Group 13: Real Technician Roster from the Users Endpoint (EARS 3.9) ---');

// 13.1 The view no longer ships a hardcoded seed list: the module must not define
// DEFAULT_TECHNICIANS nor any parallel technician vocabulary (regression for the
// defect where the tray only offered Jordi/Marta while the detail modal offered all).
const source = (await import('node:fs')).readFileSync(
  new URL('../../public/assets/js/views/CoordinatorDashboardView.js', import.meta.url), 'utf8'
);
assert('13.1 The view ships no hardcoded technician roster',
  !/DEFAULT_TECHNICIANS/.test(source) && !/marta\.ruta@vendguard\.internal/.test(source));

let rosterQuery = null;
let rosterCallCount = 0;
api.coordinator.getUsers = async (params) => {
  rosterCallCount++;
  rosterQuery = params;
  return [
    { id: 2, name: 'Jordi Técnico Ruta BCN', email: 'jordi.ruta@vendguard.internal' },
    { id: 3, name: 'Marta Técnica Ruta BCN', email: 'marta.ruta@vendguard.internal' },
    { id: 4, name: 'Carlos Técnico Ruta Sud', email: 'carlos.ruta@vendguard.internal' }
  ];
};

const rosterView = createDashboardInstance({ technicians: [], techniciansLoaded: false, techniciansErrorMessage: '', isLoadingTechnicians: false });
await rosterView.loadTechnicians();
assert('13.2 Active route technicians come from the users endpoint with active filter',
  rosterCallCount === 1 && rosterQuery && rosterQuery.status === 'active' && rosterQuery.role === 'TECHNICIAN');
assert('13.3 The full real roster (beyond the old Jordi/Marta pair) populates the tray',
  rosterView.technicians.length === 3 && rosterView.techniciansLoaded === true && rosterView.isLoadingTechnicians === false);

const realRosterView = createDashboardInstance({
  technicians: [
    { id: 2, name: 'Jordi Técnico Ruta BCN', email: 'jordi.ruta@vendguard.internal' },
    { id: 3, name: 'Marta Técnica Ruta BCN', email: 'marta.ruta@vendguard.internal' },
    { id: 4, name: 'Carlos Técnico Ruta Sud', email: 'carlos.ruta@vendguard.internal' }
  ],
  techniciansLoaded: true,
  techniciansErrorMessage: '',
  isLoadingTechnicians: false
});
realRosterView.openAssignModal(mockIncidents[0]);
assert('13.4 Opening the assign modal exposes the full real roster (3 technicians)',
  realRosterView.technicians.length === 3 && realRosterView.showAssignModal === true);
assert('13.5 Without a prior assignee the modal preselects the first real technician, not a hardcoded id',
  realRosterView.assignTechnicianId === 2
  && realRosterView.technicians[0].id === 2
  && realRosterView.technicians.some((t) => t.id === 4));

// 13.6 Submit guard: without a chosen technician nothing is sent to the API
const guardView = createDashboardInstance({
  selectedIncident: mockIncidents[0],
  assignTechnicianId: null,
  technicians: []
});
let guardAssignCalled = false;
api.coordinator.assignTechnician = async () => { guardAssignCalled = true; return {}; };
await guardView.submitAssignment();
assert('13.6 Submit without technician is blocked with a local error and no API call',
  guardAssignCalled === false && guardView.assignError.includes('técnico'));

// 13.7 A failed roster load is retried when the modal reopens (no permanent dead list)
let rosterFailures = 0;
api.coordinator.getUsers = async () => {
  rosterFailures++;
  if (rosterFailures === 1) throw new Error('Acceso denegado');
  return [{ id: 4, name: 'Carlos Técnico Ruta Sud', email: 'carlos.ruta@vendguard.internal' }];
};
const retryView = createDashboardInstance({ technicians: [], techniciansLoaded: false, techniciansErrorMessage: '', isLoadingTechnicians: false });
await retryView.loadTechnicians();
assert('13.7a First failed load records the error without marking the roster as loaded',
  retryView.techniciansErrorMessage.includes('Acceso denegado') && retryView.techniciansLoaded === false && retryView.isLoadingTechnicians === false);
retryView.techniciansErrorMessage = '';
retryView.ensureTechniciansLoaded();
await retryView.loadTechnicians();
assert('13.7b The modal reopens re-request the roster and recover to a populated list',
  retryView.technicians.length === 1 && retryView.techniciansLoaded === true && retryView.techniciansErrorMessage === '');

// ---------------------------------------------------------------------
// TEST GROUP 14: Consolidated Reassignment Over a Mixed Batch (RF-MAP-09 + RF-07.3)
// ---------------------------------------------------------------------
console.log('\n--- Group 14: Consolidated Reassignment Over a Mixed Batch ---');

// A site that still keeps one unassigned ticket while another is already owned reaches the
// bulk modal with a mixed batch: the orphan ticket is an initial assignment while the owned
// one can only change hands with a justified reason of >= 10 real characters (RF-07.3).
const mixedAssignedIncident = {
  ...mockIncidents[0],
  id: 90,
  ticket_code: 'INC-2026-0090',
  machine_code: 'VEND-0190',
  status: 'ASIGNADA',
  assigned_technician_id: 2,
  assigned_technician: { id: 2, name: 'Jordi Técnico' }
};
const mixedOrphanIncident = {
  ...mockIncidents[1],
  id: 91,
  ticket_code: 'INC-2026-0091',
  assigned_technician_id: null,
  assigned_technician: null
};
const mixedRoster = [{ id: 2, name: 'Jordi Técnico' }, { id: 4, name: 'Marta Técnica' }];

// The batch flow reloads the triage list after confirming: declare the server picture so the
// partial-failure retry list is deterministic (the rejected ticket stays untouched).
api.coordinator.getIncidents = async () => [
  { ...mixedAssignedIncident, status: 'ASIGNADA' },
  { ...mixedOrphanIncident, status: 'ASIGNADA' }
];

const mixedView = createDashboardInstance({
  incidents: [...mockIncidents, mixedAssignedIncident, mixedOrphanIncident],
  technicians: mixedRoster
});
CoordinatorDashboardView.methods.handleTerritorialAssign.call(mixedView, { locationId: 1, siteCode: 'SEDE-BCN-01' });
mixedView.bulkAssignTechnicianId = 4; // consolidation onto a different owner

assert('14.1 A mixed batch is detected as a consolidated reassignment',
  mixedView.bulkAssignIncidents.length === 4 && mixedView.bulkAssignReassignmentTargets().length === 1
    && mixedView.hasAssignedIncidents() === true
    && mixedView.hasAssignedIncidents([mixedOrphanIncident]) === false);

const pureBatchView = createDashboardInstance();
CoordinatorDashboardView.methods.handleTerritorialAssign.call(pureBatchView, { locationId: 1, siteCode: 'SEDE-BCN-01' });
assert('14.2 A batch without owners stays a plain assignment and needs no reason',
  pureBatchView.hasAssignedIncidents() === false && pureBatchView.bulkAssignReassignmentTargets().length === 0);

// Fail fast: not a single incident of the batch is dispatched until the batch-level reason is valid.
let mixedCalls = [];
api.coordinator.assignTechnician = async (id, techId, urgency, urgencyReason, reassignmentReason) => {
  mixedCalls.push({ id, techId, urgency, urgencyReason, reassignmentReason });
  return { id, technician_id: techId };
};
await CoordinatorDashboardView.methods.submitBulkAssignment.call(mixedView);
assert('14.3 Confirming a mixed batch without a reason is blocked before any API call',
  mixedView.bulkAssignError.includes('ya asignada(s)') && mixedView.isBulkAssigning === false
    && mixedCalls.length === 0 && mixedView.showBulkAssignModal === true);

mixedView.bulkAssignReassignmentReason = 'Corto';
await CoordinatorDashboardView.methods.submitBulkAssignment.call(mixedView);
assert('14.4 A reason under 10 real characters is rejected with the RF-07.3 contract',
  mixedView.bulkAssignError.includes('al menos 10 caracteres') && mixedCalls.length === 0);

// Boundary: exactly 10 real characters satisfies the rule and dispatches the batch.
mixedView.bulkAssignReassignmentReason = 'Consolidar';
await CoordinatorDashboardView.methods.submitBulkAssignment.call(mixedView);
assert('14.5 A valid reason dispatches one assignment per incident onto the chosen technician',
  mixedCalls.length === 4 && mixedCalls.every(c => c.techId === 4));

const assignedCall = mixedCalls.find(c => c.id === mixedAssignedIncident.id);
const orphanCalls = mixedCalls.filter(c => c.id !== mixedAssignedIncident.id);
assert('14.6 Only the incident with an active owner carries the reassignment reason (RF-07.3)',
  assignedCall.reassignmentReason === 'Consolidar'
    && orphanCalls.length === 3 && orphanCalls.every(c => !c.reassignmentReason));

assert('14.7 A fully successful consolidation closes the modal and clears the typed reason',
  mixedView.showBulkAssignModal === false && mixedView.bulkAssignReassignmentReason === ''
    && mixedView.getEmits().some(e => e.evt === 'bulk-assigned'));

// Partial failure on the reassigned row: the modal stays open and keeps the typed reason so
// the coordinator can retry the consolidation without retyping the justification.
const mixedRetryView = createDashboardInstance({
  incidents: [...mockIncidents, mixedAssignedIncident, mixedOrphanIncident],
  technicians: mixedRoster
});
CoordinatorDashboardView.methods.handleTerritorialAssign.call(mixedRetryView, { locationId: 1, siteCode: 'SEDE-BCN-01' });
mixedRetryView.bulkAssignTechnicianId = 4;
mixedRetryView.bulkAssignReassignmentReason = 'Consolidación de la sede';
let retryCalls = 0;
api.coordinator.assignTechnician = async (id) => {
  retryCalls++;
  if (id === mixedAssignedIncident.id) {
    throw new Error('El técnico indicado ya es el responsable activo de esta incidencia.');
  }
  return { id };
};
await CoordinatorDashboardView.methods.submitBulkAssignment.call(mixedRetryView);
assert('14.8 A reassignment rejected by the backend keeps the modal open with the reason for retry',
  mixedRetryView.showBulkAssignModal === true && retryCalls === 4
    && mixedRetryView.bulkAssignReassignmentReason === 'Consolidación de la sede'
    && mixedRetryView.bulkAssignError.includes('ya es el responsable activo')
    && mixedRetryView.bulkAssignIncidents.length === 1
    && mixedRetryView.bulkAssignIncidents[0].id === mixedAssignedIncident.id);

assert('14.9 Template: the reassignment reason is only offered for mixed batches (RF-07.3)',
  CoordinatorDashboardView.template.includes('v-if="hasAssignedIncidents()"')
    && CoordinatorDashboardView.template.includes('v-model="bulkAssignReassignmentReason"')
    && CoordinatorDashboardView.template.includes('data-testid="bulk-assign-reassignment-reason"'));

// Contract-faithful backend emulation (CoordinatorController::assignTechnician): rejects any
// reassignment without a justified reason of >= 10 real characters and any consolidation onto
// the current owner. This is the regression that used to fail while the 5th argument was missing.
const contractView = createDashboardInstance({
  incidents: [...mockIncidents, mixedAssignedIncident, mixedOrphanIncident],
  technicians: mixedRoster
});
CoordinatorDashboardView.methods.handleTerritorialAssign.call(contractView, { locationId: 1, siteCode: 'SEDE-BCN-01' });
contractView.bulkAssignTechnicianId = 4;
contractView.bulkAssignReassignmentReason = 'Consolidación de la sede';
const ownerById = new Map(contractView.bulkAssignIncidents.map(inc => [inc.id, inc.assigned_technician_id]));
const contractRejections = [];
api.coordinator.assignTechnician = async (id, techId, urgency, urgencyReason, reassignmentReason) => {
  const currentOwner = ownerById.get(id);
  if (currentOwner !== null && currentOwner !== undefined) {
    if (currentOwner === techId) {
      contractRejections.push(`${id}:TECHNICIAN_ALREADY_ASSIGNED`);
    } else if (!reassignmentReason || Array.from(String(reassignmentReason).trim()).length < 10) {
      contractRejections.push(`${id}:MISSING_REASSIGNMENT_REASON`);
    }
  }
  return { id, technician_id: techId };
};
await CoordinatorDashboardView.methods.submitBulkAssignment.call(contractView);
assert('14.10 A contract-faithful backend accepts the whole consolidation with no 422 rejections',
  contractRejections.length === 0 && contractView.showBulkAssignModal === false
    && contractView.bulkAssignError === '' && contractView.getEmits().some(e => e.evt === 'bulk-assigned'));

// ---------------------------------------------------------------------
// TEST GROUP 15: State-Aware Copy of the Consolidated Site Batch (RF-MAP-09, T-MAP-23)
// ---------------------------------------------------------------------
console.log('\n--- Group 15: State-Aware Copy of the Consolidated Site Batch ---');

const copyView = createDashboardInstance({
  incidents: [...mockIncidents, mixedAssignedIncident, mixedOrphanIncident],
  technicians: mixedRoster
});
CoordinatorDashboardView.methods.handleTerritorialAssign.call(copyView, { locationId: 1, siteCode: 'SEDE-BCN-01' });
copyView.bulkAssignTechnicianId = 4;

assert('15.1 A mixed batch titles the dialogue as a consolidation, never as a first assignment',
  copyView.bulkAssignModalTitle() === 'Reasignar / Consolidar Sede en un Único Técnico'
    && copyView.bulkAssignSubmitLabel() === `Reasignar ${copyView.bulkAssignIncidents.length} incidencia(s)`);

assert('15.2 The entradilla counts the already assigned incidents of the batch',
  copyView.bulkAssignSubtitle().includes(`${copyView.bulkAssignIncidents.length} incidencia(s)`)
    && copyView.bulkAssignSubtitle().includes('1 ya asignada(s)'),
  copyView.bulkAssignSubtitle());

assert('15.3 Every already owned row carries its responsible as a per-row chip',
  copyView.incidentOwnerLabel(mixedAssignedIncident) === 'Ya asignada: Jordi Técnico'
    && copyView.incidentOwnerLabel(mixedOrphanIncident) === ''
    && copyView.incidentOwnerLabel({ assigned_technician_id: 9, assigned_technician: null }) === 'Ya asignada: otro técnico');

const cleanCopyView = createDashboardInstance();
CoordinatorDashboardView.methods.handleTerritorialAssign.call(cleanCopyView, { locationId: 1, siteCode: 'SEDE-BCN-01' });
assert('15.4 A batch without owners keeps the assignment copy and its pending wording',
  cleanCopyView.bulkAssignModalTitle() === 'Asignar Técnico a la Sede'
    && cleanCopyView.bulkAssignSubmitLabel() === `Asignar ${cleanCopyView.bulkAssignIncidents.length} incidencia(s)`
    && cleanCopyView.bulkAssignSubtitle().includes('pendiente(s)'),
  cleanCopyView.bulkAssignSubtitle());

assert('15.5 Template: title, subtitle and button bind the state-aware copy and the row chip',
  CoordinatorDashboardView.template.includes(':title="bulkAssignModalTitle()"')
    && CoordinatorDashboardView.template.includes(':subtitle="bulkAssignSubtitle()"')
    && CoordinatorDashboardView.template.includes('{{ bulkAssignSubmitLabel() }}')
    && CoordinatorDashboardView.template.includes('data-testid="bulk-assign-row-owner"')
    && CoordinatorDashboardView.template.includes('data-testid="bulk-assign-batch-note"'));

// The alert wording follows the nature of the batch: a consolidation is never reported as
// a plain assignment (RF-MAP-09). The roster is pinned so the chosen owner has a stable name.
api.coordinator.getUsers = async () => mixedRoster;
const copyAlerts = [];
const originalAddAlert = store.addAlert;
store.addAlert = (message) => { copyAlerts.push(String(message)); };

api.coordinator.assignTechnician = async (id) => ({ id });
const alertView = createDashboardInstance({
  incidents: [...mockIncidents, mixedAssignedIncident, mixedOrphanIncident],
  technicians: mixedRoster
});
CoordinatorDashboardView.methods.handleTerritorialAssign.call(alertView, { locationId: 1, siteCode: 'SEDE-BCN-01' });
alertView.bulkAssignTechnicianId = 4;
alertView.bulkAssignReassignmentReason = 'Consolidación de la sede';
await CoordinatorDashboardView.methods.submitBulkAssignment.call(alertView);
assert('15.6 A fully successful consolidation reports the site as consolidated in the chosen technician',
  copyAlerts.length === 1 && copyAlerts[0].includes('consolidada en Marta Técnica')
    && copyAlerts[0].includes('reasignada(s) correctamente'),
  copyAlerts.join(' | '));

copyAlerts.length = 0;
const partialCopyView = createDashboardInstance({
  incidents: [...mockIncidents, mixedAssignedIncident, mixedOrphanIncident],
  technicians: mixedRoster
});
CoordinatorDashboardView.methods.handleTerritorialAssign.call(partialCopyView, { locationId: 1, siteCode: 'SEDE-BCN-01' });
partialCopyView.bulkAssignTechnicianId = 4;
partialCopyView.bulkAssignReassignmentReason = 'Consolidación de la sede';
api.coordinator.assignTechnician = async (id) => {
  if (id === mixedAssignedIncident.id) {
    throw new Error('El técnico indicado ya es el responsable activo de esta incidencia.');
  }
  return { id };
};
await CoordinatorDashboardView.methods.submitBulkAssignment.call(partialCopyView);
assert('15.7 A rejected row reports the result as a partial consolidation, not as an assignment',
  copyAlerts.length === 1 && copyAlerts[0].includes('Consolidación parcial en SEDE-BCN-01'),
  copyAlerts.join(' | '));

copyAlerts.length = 0;
api.coordinator.assignTechnician = async (id) => ({ id });
const cleanAlertView = createDashboardInstance();
CoordinatorDashboardView.methods.handleTerritorialAssign.call(cleanAlertView, { locationId: 1, siteCode: 'SEDE-BCN-01' });
cleanAlertView.bulkAssignTechnicianId = 2;
await CoordinatorDashboardView.methods.submitBulkAssignment.call(cleanAlertView);
assert('15.8 A clean batch keeps the assignment wording in its success alert',
  copyAlerts.length === 1 && copyAlerts[0].includes('asignada(s) correctamente')
    && !copyAlerts[0].includes('consolidada'),
  copyAlerts.join(' | '));

store.addAlert = originalAddAlert;

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITIONS T-37, T-IDM-14, T-IDM-15 AND TRAY GATING (EARS 5.5/6.4) FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
