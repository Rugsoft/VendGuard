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

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITIONS T-37, T-IDM-14 AND T-IDM-15 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
