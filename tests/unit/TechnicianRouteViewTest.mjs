/**
 * VendGuard - Frontend Unit Test Suite (TechnicianRouteViewTest.mjs)
 * 
 * Validates TechnicianRouteView (T-38, RF-07, RF-08, RNF-01):
 * - Mobile vertical smartphone UI layout.
 * - Route task list and operational status counters.
 * - "Iniciar intervención" action (transitions to IN_PROGRESS).
 * - "Pausar por repuesto" modal requiring spare part description (EARS 7.2).
 * - "Resolver avería" modal strictly requiring >= 20 chars per field (EARS 8.1, 8.2).
 * - Pausa por falta de acceso, despriorización de la parada y reanudación in situ
 *   (Módulo 11: T-PAUSE-18, RF-01.1, RF-02.2, RF-02.3, RF-05.5, RNF-03, RNF-04).
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
import { TechnicianResolutionRefundBlock } from '../../public/assets/js/components/TechnicianResolutionRefundBlock.js';
import { store, setInternalSession, clearSession } from '../../public/assets/js/store.js';
import { PendingInfoPauseModal } from '../../public/assets/js/components/PendingInfoPauseModal.js';
import { AMBER_TECHNICAL_TOKENS } from '../../public/assets/js/utils/IncidentStatusPermissions.js';

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

// Printable A4 spare parts quick guide native link (RF-REP-03, RF-REP-04)
assert('2.8 Header quick guide link present with aria-label and testid',
  TechnicianRouteView.template.includes('data-testid="btn-quick-guide"') &&
  TechnicianRouteView.template.includes('aria-label="Abrir guía rápida de repuestos en formato A4"')
);
assert('2.9 Quick guide is a native anchor to the printable static asset (Dogma Vanilla, no API route)',
  TechnicianRouteView.template.includes('href="/docs/guia_rapida_taller_repuestos_a4.html"') &&
  TechnicianRouteView.template.includes('target="_blank"') &&
  TechnicianRouteView.template.includes('rel="noopener"')
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
let resolveCalledPayload = null;
api.technician.getRefundInspection = async () => ({
  has_refund_requests: false,
  has_pending_verdict: false,
  requests: []
});
api.technician.resolveIncident = async (id, diag, act) => {
  resolveCalledId = id;
  resolveCalledDiag = diag;
  resolveCalledAct = act;
  resolveCalledPayload = typeof diag === 'object' ? diag : null;
  return {
    success: true,
    data: { id, status: 'RESOLVED', resolved_at: '2026-09-22T10:00:00Z' }
  };
};

const inProgressInc = authView.incidents.find(i => i.id === 102);
await authView.openResolveModal(inProgressInc);

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
assert('5.13a Legacy resolution without refund or parts keeps the positional API contract', resolveCalledPayload === null);
assert('5.14 Modal closed upon resolution', authView.showResolveModal === false);
assert('5.15 Resolved incident is removed from active route', authView.incidents.find(i => i.id === 102) === undefined);

const resolvedEmits = authView.getEmits().filter(e => e.evt === 'resolved');
assert('5.16 Emits "resolved" event with diagnosis and action',
  resolvedEmits.length === 1 &&
  resolvedEmits[0].val.incidentId === 102 &&
  resolvedEmits[0].val.diagnosis === validDiagnosis
);

// ---------------------------------------------------------------------
// TEST GROUP 6: Refund verdict integration (T-REF-16)
// ---------------------------------------------------------------------
console.log('\n--- Group 6: Mobile refund verdict integration ---');

assert('6.1 View registers the tactile refund verdict block',
  TechnicianRouteView.components?.TechnicianResolutionRefundBlock === TechnicianResolutionRefundBlock);
assert('6.2 Resolve modal loads technician-safe refund inspection before showing the block',
  TechnicianRouteView.methods.loadRefundInspection.toString().includes('getRefundInspection')
    && TechnicianRouteView.template.includes('ref="resolutionRefundBlockRef"')
    && TechnicianRouteView.template.includes(':refund-requests="refundRequests"')
    && TechnicianRouteView.template.includes('PAYMENT_SYSTEM'));
assert('6.3 Resolve action is disabled during loading, failed lookup, or invalid verdict',
  TechnicianRouteView.template.includes('isLoadingRefundInspection || refundInspectionFailed || !canResolve || !resolveRefundData.isValid'));
assert('6.4 A failed refund inspection request fails closed',
  TechnicianRouteView.methods.loadRefundInspection.toString().includes('this.hasPendingRefundVerdict = true')
    && TechnicianRouteView.methods.loadRefundInspection.toString().includes('this.refundInspectionFailed = true')
    && TechnicianRouteView.methods.loadRefundInspection.toString().includes('this.resolveRefundData = { isValid: false }'));
assert('6.5 Refund API method uses the technician-scoped incident endpoint',
  typeof api.technician.getRefundInspection === 'function');

const refundRouteView = createRouteInstance();
const paymentIncident = { ...mockRouteIncidents[1], category: 'PAYMENT_SYSTEM' };
let inspectionRequestedFor = null;
api.technician.getRefundInspection = async (id) => {
  inspectionRequestedFor = id;
  return {
    has_refund_requests: true,
    has_pending_verdict: true,
    requests: [{
      id: 44,
      claimed_amount: 3,
      product_attempted: 'Café',
      compensation_method: 'EN_MANO_SEDE',
      status: 'PENDING_INSPECTION',
      custody_instruction: 'Depositar en recepción.'
    }]
  };
};
await refundRouteView.openResolveModal(paymentIncident);
assert('6.6 Opening the resolve modal queries refund information for that incident',
  inspectionRequestedFor === paymentIncident.id && refundRouteView.isLoadingRefundInspection === false);
assert('6.7 Pending claim data is loaded and blocks resolution pending a verdict',
  refundRouteView.refundRequests.length === 1
    && refundRouteView.hasPendingRefundVerdict === true
    && refundRouteView.resolveRefundData.isValid === false);
assert('6.7a The block receives only backend-projected request fields',
  refundRouteView.refundRequests.every((request) => !('iban' in request) && !('bizum_phone' in request))
    && TechnicianRouteView.template.includes(':refund-requests="refundRequests"'));
assert('6.8 A pending claim does not submit until the refund block provides a valid verdict',
  refundRouteView.resolveRefundData.isValid === false && refundRouteView.hasPendingRefundVerdict === true);

const receivedPayloads = [];
api.technician.resolveIncident = async (id, payloadOrDiagnosis, actionTaken) => {
  receivedPayloads.push({ id, payloadOrDiagnosis, actionTaken });
  return { data: { id, status: 'RESOLVED', resolved_at: '2026-10-02T10:00:00Z' } };
};
refundRouteView.resolveDiagnosis = 'Fallo de selector con moneda atascada en canal interno.';
refundRouteView.resolveAction = 'Desatascado el selector y probado el pago con monedas.';
refundRouteView.$refs = {
  resolutionRefundBlockRef: {
    validate: () => ({
      isValid: true,
      payload: {
        refund_inspection: {
          finding: 'FOUND_PHYSICAL',
          recovered_amount: 3,
          cash_custody_action: 'LEFT_AT_RECEPTION',
          receptionist_name: 'Ana'
        }
      }
    })
  },
  resolutionPartsBlockRef: { validate: () => ({ isValid: true, payload: {} }) }
};
await refundRouteView.submitResolve();
assert('6.9 The resolved request combines technical and refund data in one object payload',
  receivedPayloads.length === 1
    && typeof receivedPayloads[0].payloadOrDiagnosis === 'object'
    && receivedPayloads[0].payloadOrDiagnosis.refund_inspection?.finding === 'FOUND_PHYSICAL'
    && receivedPayloads[0].payloadOrDiagnosis.diagnosis === refundRouteView.getEmits().find((event) => event.evt === 'resolved')?.val.diagnosis);

const unclaimedRouteView = createRouteInstance();
api.technician.getRefundInspection = async () => ({
  has_refund_requests: false,
  has_pending_verdict: false,
  requests: []
});
await unclaimedRouteView.openResolveModal(paymentIncident);
unclaimedRouteView.resolveDiagnosis = 'No hay fallo de cobro y selector se mueve con normalidad.';
unclaimedRouteView.resolveAction = 'Limpieza interna y prueba de venta con moneda válida.';
unclaimedRouteView.$refs = {
  resolutionRefundBlockRef: {
    validate: () => ({
      isValid: true,
      payload: { unclaimed_cash_found: { amount: 1.5, notes: 'Canal del monedero' } }
    })
  }
};
await unclaimedRouteView.submitResolve();
assert('6.10 An optional unclaimed-cash finding is merged into the resolution object payload',
  receivedPayloads.length === 2
    && receivedPayloads[1].payloadOrDiagnosis.unclaimed_cash_found?.amount === 1.5);
assert('6.10a Payment incidents with no claims pass an explicit opt-in for the unclaimed-cash control',
  TechnicianRouteView.template.includes(':allow-unclaimed-cash="selectedIncident?.category === \'PAYMENT_SYSTEM\'"'));

const failedLookupView = createRouteInstance();
api.technician.getRefundInspection = async () => { throw new Error('No hay conexión'); };
await failedLookupView.openResolveModal(mockRouteIncidents[1]);
assert('6.11 A failed lookup leaves resolution blocked and shows the lookup error',
  failedLookupView.resolveRefundData.isValid === false
    && failedLookupView.hasPendingRefundVerdict === true
    && failedLookupView.refundInspectionFailed === true
    && failedLookupView.resolveError === 'No hay conexión');
failedLookupView.resolveDiagnosis = 'Monedero sin evidencia de atasco en canal interno.';
failedLookupView.resolveAction = 'Verificado el ciclo de pago con moneda de prueba válida.';
const payloadCountBeforeFailedLookupSubmit = receivedPayloads.length;
await failedLookupView.submitResolve();
assert('6.12 Resolution is refused after an unsuccessful refund lookup, regardless of local block state',
  failedLookupView.resolveError.includes('No se pudo verificar')
    && receivedPayloads.length === payloadCountBeforeFailedLookupSubmit
    && failedLookupView.isResolving === false);

// ---------------------------------------------------------------------
// TEST GROUP 7: Route Map Integration (RF-MAP-07, RF-MAP-08 / T-MAP-14)
// ---------------------------------------------------------------------
console.log('\n--- Group 7: Route Map Integration ---');

// 7.1 The view registers the map modal component (Dogma Vanilla ESM import)
assert('7.1 View registers TechnicianRouteMapModal component',
  TechnicianRouteView.components?.TechnicianRouteMapModal !== undefined);

// 7.2 Prominent "Ver Mapa de Ruta" button opens the modal (RF-MAP-07)
assert('7.2 Template contains the prominent "Ver Mapa de Ruta" button',
  TechnicianRouteView.template.includes('data-testid="btn-open-route-map"') &&
  TechnicianRouteView.template.includes('Ver Mapa de Ruta') &&
  TechnicianRouteView.template.includes('showRouteMapModal = true'));

// 7.3 The modal is bound to the view state
assert('7.3 Template mounts TechnicianRouteMapModal bound to showRouteMapModal',
  TechnicianRouteView.template.includes('<TechnicianRouteMapModal v-model="showRouteMapModal"'));

// 7.4 Full-day Google Maps link with waypoints (RF-MAP-08)
const mapInstance = createRouteInstance();
mapInstance.incidents = JSON.parse(JSON.stringify(mockRouteIncidents));
mapInstance.incidents[0].location.latitude = 41.385312;
mapInstance.incidents[0].location.longitude = 2.193245;
mapInstance.incidents[1].location.latitude = 41.403629;
mapInstance.incidents[1].location.longitude = 2.189512;
mapInstance.incidents[2].location.latitude = 41.392;
mapInstance.incidents[2].location.longitude = 2.164;
Object.defineProperty(mapInstance, 'fullRouteNavigationUrl', {
  get: () => TechnicianRouteView.computed.fullRouteNavigationUrl.call(mapInstance)
});
const fullUrl = mapInstance.fullRouteNavigationUrl;
assert('7.4 Computes the full-day navigation link with origin stops as waypoints',
  fullUrl.startsWith('https://www.google.com/maps/dir/?') &&
  fullUrl.includes('destination=41.392%2C2.164') &&
  fullUrl.includes('waypoints=41.385312%2C2.193245%7C41.403629%2C2.189512'));
assert('7.5 Uses driving travelmode in the universal link', fullUrl.includes('travelmode=driving'));

// 7.6 Incidents without coordinates are excluded without breaking the link
const partialInstance = createRouteInstance();
partialInstance.incidents = [
  { id: 201, urgency: 'LOW', status: 'ASSIGNED', location: { name: 'Sede sin coordenadas', address: 'Calle 1' } },
  { id: 202, urgency: 'LOW', status: 'ASSIGNED', location: { name: 'Sede geográfica', address: 'Calle 2', latitude: 41.4, longitude: 2.2 } }
];
Object.defineProperty(partialInstance, 'fullRouteNavigationUrl', {
  get: () => TechnicianRouteView.computed.fullRouteNavigationUrl.call(partialInstance)
});
assert('7.6 Excludes incidents without coordinates and keeps the valid stop',
  partialInstance.fullRouteNavigationUrl === 'https://www.google.com/maps/dir/?api=1&destination=41.4%2C2.2&travelmode=driving');

// 7.7 No coordinates at all -> no link rendered
const emptyCoordInstance = createRouteInstance();
emptyCoordInstance.incidents = [
  { id: 203, urgency: 'LOW', status: 'ASSIGNED', location: { name: 'Sede sin GPS', address: 'Calle 3' } }
];
Object.defineProperty(emptyCoordInstance, 'fullRouteNavigationUrl', {
  get: () => TechnicianRouteView.computed.fullRouteNavigationUrl.call(emptyCoordInstance)
});
assert('7.7 Returns an empty link when no incident has coordinates',
  emptyCoordInstance.fullRouteNavigationUrl === '');

// 7.8 Link rendered conditionally in template
assert('7.8 Template renders the full-day navigation link conditionally',
  TechnicianRouteView.template.includes('v-if="fullRouteNavigationUrl"') &&
  TechnicianRouteView.template.includes('data-testid="btn-full-route-navigation"'));

// 7.9 One-tap GPS navigation per card (RF-MAP-08)
assert('7.9 Template contains the per-card "Navegar con GPS" button',
  TechnicianRouteView.template.includes('data-testid="btn-navigate-stop"') &&
  TechnicianRouteView.template.includes('Navegar con GPS') &&
  TechnicianRouteView.template.includes('@click="navigateToStopLocation(incident)"'));

let openedUrls = [];
const originalWindowOpen = globalThis.window?.open;
globalThis.window = globalThis.window || {};
globalThis.window.open = (url) => {
  openedUrls.push(url);
  return null;
};

openedUrls = [];
mapInstance.navigateToStopLocation(mapInstance.incidents[0]);
assert('7.10 navigateToStopLocation opens Google Maps with the exact stop coordinates',
  openedUrls.length === 1 &&
  openedUrls[0] === 'https://www.google.com/maps/dir/?api=1&destination=41.385312,2.193245&travelmode=driving');

openedUrls = [];
mapInstance.navigateToStopLocation({ location: { name: 'Sede sin coordenadas' } });
assert('7.11 navigateToStopLocation is a no-op without coordinates', openedUrls.length === 0);

if (originalWindowOpen !== undefined) {
  globalThis.window.open = originalWindowOpen;
}

// 7.12 Coordinates available on the route payload (backend contract extension)
assert('7.12 Template guards the GPS button when latitude is missing',
  TechnicianRouteView.template.includes("incident.location?.latitude !== null && incident.location?.latitude !== undefined"));

// ---------------------------------------------------------------------
// 8. MACHINE INCIDENT HISTORY ACCESS (RF-07 / EARS H.1-H.6,
//    specs/technical/technician_machine_history_contracts.md)
// ---------------------------------------------------------------------
console.log('\n--- Grupo 8: Acceso al historial de la máquina (EARS H.1, H.6) ---');

assert('8.1 Cada avería pendiente ofrece el botón "Historial de la máquina"',
  TechnicianRouteView.template.includes('btn-machine-history')
    && TechnicianRouteView.template.includes('Historial de la máquina'));

assert('8.2 El botón se muestra en cada tarjeta de avería y se guarda si la máquina tiene id',
  TechnicianRouteView.template.includes('v-if="incident.machine?.id"'));

const historyViewInstance = { historyMachine: null, showMachineHistoryModal: false };
TechnicianRouteView.methods.openMachineHistoryModal.call(historyViewInstance, { machine: { id: 12, code: 'VM-012' } });
assert('8.3 openMachineHistoryModal abre el modal con la máquina de la avería',
  historyViewInstance.showMachineHistoryModal === true && historyViewInstance.historyMachine?.id === 12);

const historyMachineBefore = historyViewInstance.historyMachine;
TechnicianRouteView.methods.openMachineHistoryModal.call(historyViewInstance, { machine: null });
assert('8.4 Ignora averías sin máquina y no rompe el estado previo (EARS H.6)',
  historyViewInstance.showMachineHistoryModal === true && historyViewInstance.historyMachine === historyMachineBefore);

TechnicianRouteView.methods.closeMachineHistoryModal.call(historyViewInstance);
assert('8.5 closeMachineHistoryModal cierra y resetea el estado',
  historyViewInstance.showMachineHistoryModal === false && historyViewInstance.historyMachine === null);

assert('8.6 El modal de historial está registrado como componente de la vista',
  typeof TechnicianRouteView.components?.TechnicianMachineHistoryModal === 'object');

assert('8.7 El modal se renderiza una única vez, fuera del bucle de tarjetas (EARS H.6)',
  (TechnicianRouteView.template.match(/<TechnicianMachineHistoryModal/g) || []).length === 1);

const viewData = TechnicianRouteView.data();
assert('8.8 Estado del modal declarado en data() con valores iniciales cerrados',
  viewData.showMachineHistoryModal === false && viewData.historyMachine === null);

// ---------------------------------------------------------------------
// GRUPO 9: PAUSA POR FALTA DE ACCESO Y DESPRIORIZACIÓN DE LA PARADA
// (Módulo 11: T-PAUSE-18, RF-01.1, RF-02.2, RF-02.3, RF-05.5, RNF-03, RNF-04)
// ---------------------------------------------------------------------
console.log('\n--- Grupo 9: Pausa por falta de acceso y despriorización de la parada ---');

assert('9.1 La vista registra el modal de pausa por falta de acceso',
  TechnicianRouteView.components?.PendingInfoPauseModal === PendingInfoPauseModal);

assert('9.2 El modal se monta con el estado, la parada y el canal del técnico',
  TechnicianRouteView.template.includes('<PendingInfoPauseModal')
    && TechnicianRouteView.template.includes(':is-open="showPendingInfoPauseModal"')
    && TechnicianRouteView.template.includes(':incident="pendingInfoIncident"')
    && TechnicianRouteView.template.includes('role="TECHNICIAN"')
    && TechnicianRouteView.template.includes('@paused="handlePendingInfoPaused"'));

assert('9.3 El modal se renderiza una única vez, fuera del bucle de tarjetas',
  (TechnicianRouteView.template.match(/<PendingInfoPauseModal/g) || []).length === 1);

const pendingInfoViewData = TechnicianRouteView.data();
assert('9.4 data() declara el modal y los contadores de la pausa cerrados',
  pendingInfoViewData.showPendingInfoPauseModal === false
    && pendingInfoViewData.pendingInfoIncident === null
    && pendingInfoViewData.isResumingPendingInfo === false);

assert('9.5 La parada activa ofrece el botón "Pausar por falta de acceso"',
  TechnicianRouteView.template.includes('data-testid="btn-pause-no-access"')
    && TechnicianRouteView.template.includes('⏸️ Pausar por falta de acceso')
    && TechnicianRouteView.template.includes('@click="openPendingInfoPauseModal(incident)"'));

const pauseEligibilityView = createRouteInstance();
assert('9.6 La pausa se ofrece desde ASSIGNED, IN_PROGRESS y PENDING_PARTS (RF-01.1)',
  pauseEligibilityView.canPausePendingInfo({ status: 'ASSIGNED' }) === true
    && pauseEligibilityView.canPausePendingInfo({ status: 'IN_PROGRESS' }) === true
    && pauseEligibilityView.canPausePendingInfo({ status: 'PENDING_PARTS' }) === true);

assert('9.7 La pausa se bloquea en estados terminales y en una pausa ya viva',
  pauseEligibilityView.canPausePendingInfo({ status: 'PENDING_INFO' }) === false
    && pauseEligibilityView.canPausePendingInfo({ status: 'RESOLVED' }) === false
    && pauseEligibilityView.canPausePendingInfo({ status: 'CANCELLED' }) === false
    && pauseEligibilityView.canPausePendingInfo(null) === false);

const pausedRouteView = createRouteInstance();
api.technician.getMyRoute = async () => JSON.parse(JSON.stringify(pausedRouteView.incidents));

const stopToPause = pausedRouteView.incidents.find(i => i.id === 102);
pausedRouteView.openPendingInfoPauseModal(stopToPause);
assert('9.8 openPendingInfoPauseModal abre el modal con la parada seleccionada',
  pausedRouteView.showPendingInfoPauseModal === true
    && pausedRouteView.pendingInfoIncident?.id === 102);

let pendingInfoPauseEmit = null;
pausedRouteView.$emit = (evt, val) => { if (evt === 'pending-info-paused') pendingInfoPauseEmit = val; };

pausedRouteView.handlePendingInfoPaused({
  incidentId: 102,
  ticketCode: stopToPause.ticket_code,
  pauseData: {
    incident_id: 102,
    status: 'PENDING_INFO',
    paused_at: '2026-10-10T09:15:00+00:00',
    accumulated_pause_minutes: 0,
    reason_category: 'BUILDING_CLOSED_NO_ACCESS',
    reason_category_label: 'Edificio cerrado / Sin acceso a instalaciones',
    reason_text: 'El edificio de consultas externas está cerrado por festivo local sin conserje.'
  }
});

assert('9.9 Al confirmar la pausa, la parada pasa a PENDING_INFO',
  stopToPause.status === 'PENDING_INFO' && stopToPause.paused_at === '2026-10-10T09:15:00+00:00');

assert('9.10 La parada pausada conserva la causa tipificada y la justificación del técnico',
  stopToPause.pending_info_reason_category === 'BUILDING_CLOSED_NO_ACCESS'
    && stopToPause.pending_info_reason_category_label === 'Edificio cerrado / Sin acceso a instalaciones'
    && stopToPause.pending_info_reason_text.startsWith('El edificio de consultas externas'));

assert('9.11 La pausa se anuncia al contenedor y cierra el modal',
  pendingInfoPauseEmit?.incidentId === 102
    && pausedRouteView.showPendingInfoPauseModal === false
    && pausedRouteView.pendingInfoIncident === null);

assert('9.12 La pausa deja constancia al técnico de que la ruta queda desbloqueada',
  pausedRouteView.feedbackMessage.includes('desbloqueada'));

// Refresco en segundo plano del contador exacto (RNF-01)
await new Promise((resolve) => setTimeout(resolve, 0));

assert('9.13 El contador de paradas en espera separa la pausa de las intervenciones activas',
  pausedRouteView.routeMetrics.pendingInfo === 1
    && pausedRouteView.routeMetrics.total === 3
    && pausedRouteView.routeMetrics.assigned === 1
    && pausedRouteView.routeMetrics.pendingParts === 1);

assert('9.14 La parada pausada se ordena la última aunque su urgencia sea CRÍTICA (RF-05.5)',
  pausedRouteView.filteredIncidents[pausedRouteView.filteredIncidents.length - 1].status === 'PENDING_INFO'
    && pausedRouteView.filteredIncidents[0].status !== 'PENDING_INFO');

pausedRouteView.filterStatus = 'PENDING_INFO';
assert('9.15 El filtro "En espera" aísla las paradas pausadas',
  pausedRouteView.filteredIncidents.length === 1
    && pausedRouteView.filteredIncidents[0].id === 102
    && TechnicianRouteView.template.includes('data-testid="filter-pending-info"'));
pausedRouteView.filterStatus = 'ALL';

// RF-05.5: la siguiente visita programada sigue siendo operable sin bloqueo
const nextStop = pausedRouteView.incidents.find(i => i.status === 'ASSIGNED');
assert('9.16 La siguiente parada programada sigue disponible y se puede iniciar',
  nextStop !== undefined
    && pausedRouteView.filteredIncidents[0].id === nextStop.id);

await pausedRouteView.startIntervention(nextStop);
assert('9.17 Iniciar la siguiente visita funciona con una parada pausada en la ruta',
  nextStop.status === 'IN_PROGRESS'
    && api.technician.startIncident !== undefined);

assert('9.18 La parada pausada no ofrece Iniciar ni Resolver: solo reanudar',
  TechnicianRouteView.template.indexOf('v-if="isPendingInfo(incident)"')
    < TechnicianRouteView.template.indexOf('v-else-if="isAssigned(incident)"')
    && TechnicianRouteView.template.includes('data-testid="btn-resume-pending-info"')
    && TechnicianRouteView.template.includes('▶ Reanudar intervención in situ'));

assert('9.19 La tarjeta muestra la insignia "⏸️ En espera de sede" con la causa y el contador',
  TechnicianRouteView.template.includes('data-testid="tech-stop-pending-info-badge"')
    && TechnicianRouteView.template.includes('⏸️ En espera de sede')
    && TechnicianRouteView.template.includes('data-testid="tech-stop-pending-info-timer"')
    && TechnicianRouteView.template.includes('{{ pendingInfoElapsedLabel(incident) }}')
    && TechnicianRouteView.template.includes('{{ pendingInfoReasonLabel(incident) }}'));

const timerView = createRouteInstance();
const tenMinutesAgo = new Date(Date.now() - 10 * 60000).toISOString();
const ninetyMinutesAgo = new Date(Date.now() - 90 * 60000).toISOString();
const timerStop = { paused_at: tenMinutesAgo, total_pending_info_seconds: 0 };
assert('9.20 El contador de pausa se redondea al minuto y salta a horas (RNF-01)',
  timerView.pendingInfoElapsedMinutes(timerStop) === 10
    && timerView.pendingInfoElapsedLabel(timerStop) === 'Pausada hace 10 min'
    && timerView.pendingInfoElapsedLabel({ paused_at: ninetyMinutesAgo }) === 'Pausada hace 1 h 30 min'
    && timerView.pendingInfoElapsedLabel({ paused_at: null, total_pending_info_seconds: 7200 }) === 'Pausada hace 2 h');

assert('9.21 La causa de la pausa usa la etiqueta del servidor con respaldo al catálogo',
  timerView.pendingInfoReasonLabel({ pending_info_reason_category: 'MACHINE_LOCATION_NOT_FOUND' })
    === 'Máquina no localizada en la planta indicada'
    && timerView.pendingInfoReasonLabel({ pending_info_reason_category_label: 'Corte eléctrico ajeno' })
    === 'Corte eléctrico ajeno'
    && timerView.pendingInfoReasonLabel({}) === 'Información pendiente de la sede');

assert('9.22 La insignia ámbar reutiliza los tokens compartidos, sin color nuevo (RNF-04)',
  TechnicianRouteView.computed.pendingInfoTokens.call(timerView) === AMBER_TECHNICAL_TOKENS
    && TechnicianRouteView.template.includes('pendingInfoTokens.bg')
    && TechnicianRouteView.template.includes('pendingInfoTokens.border'));

let resumeCalledId = null;
let resumeCalledTarget = null;
api.technician.resumeIncidentPendingInfo = async (id, targetStatus) => {
  resumeCalledId = id;
  resumeCalledTarget = targetStatus;
  return {
    incident_id: id,
    status: 'IN_PROGRESS',
    paused_at: null,
    is_sla_paused: false,
    accumulated_pause_minutes: 0
  };
};

const resumedEmit = [];
pausedRouteView.$emit = (evt, val) => { resumedEmit.push({ evt, val }); };

await pausedRouteView.resumePendingInfoIntervention(stopToPause);

assert('9.23 Reanudar in situ llama a la API con destino IN_PROGRESS (RF-02.3)',
  resumeCalledId === 102 && resumeCalledTarget === 'IN_PROGRESS');

assert('9.24 La parada vuelve a "En curso" y la pausa viva se cierra',
  stopToPause.status === 'IN_PROGRESS' && stopToPause.paused_at === null);

assert('9.25 La reanudación se anuncia y reactiva el reloj contractual',
  resumedEmit.some(e => e.evt === 'pending-info-resumed' && e.val.incidentId === 102)
    && pausedRouteView.feedbackMessage.includes('SLA reactivado')
    && pausedRouteView.isResumingPendingInfo === false);

const failedResumeView = createRouteInstance();
api.technician.getMyRoute = async () => JSON.parse(JSON.stringify(failedResumeView.incidents));
failedResumeView.$emit = () => {};
failedResumeView.handlePendingInfoPaused({
  incidentId: 101,
  pauseData: { paused_at: '2026-10-10T08:00:00+00:00', reason_category: 'EXTERNAL_POWER_CUT' }
});
api.technician.resumeIncidentPendingInfo = async () => { throw new Error('La sede aún no ha respondido.'); };
const failedStop = failedResumeView.incidents.find(i => i.id === 101);
await failedResumeView.resumePendingInfoIntervention(failedStop);
assert('9.26 Un fallo de reanudación se muestra y mantiene la parada en espera',
  failedStop.status === 'PENDING_INFO'
    && failedResumeView.pendingInfoResumeError === 'La sede aún no ha respondido.'
    && failedResumeView.isResumingPendingInfo === false);

// ---------------------------------------------------------------------
// TEST GROUP 10: Sanitary closure gate on the route (T-PAUSE-25, RF-03.5.2, Art. II)
// ---------------------------------------------------------------------
console.log('\n--- Group 10: Puerta sanitaria del cierre en la vista (RF-03.5.2, Art. II) ---');

assert('10.1 La vista registra el bloque de declaraciones sanitarias como componente',
  Boolean(TechnicianRouteView.components?.TechnicianSanitaryResolutionBlock));

assert('10.2 La plantilla monta el bloque sanitario con su referencia dentro del modal de resolución',
  typeof TechnicianRouteView.template === 'string'
    && TechnicianRouteView.template.includes('TechnicianSanitaryResolutionBlock')
    && TechnicianRouteView.template.includes('resolutionSanitaryBlockRef'));

const sanitaryView = createRouteInstance();
let sanitaryCalledId = null;
let sanitaryCalledPayload = null;
api.technician.getRefundInspection = async () => ({ has_refund_requests: false, has_pending_verdict: false, requests: [] });
api.technician.resolveIncident = async (id, payloadOrDiagnosis, actionTaken) => {
  sanitaryCalledId = id;
  sanitaryCalledPayload = typeof payloadOrDiagnosis === 'object' ? payloadOrDiagnosis : null;
  return { success: true, data: { id, status: 'RESOLVED', resolved_at: '2026-10-10T10:00:00Z' } };
};

const quarantineIncident = {
  ...JSON.parse(JSON.stringify(mockRouteIncidents.find(i => i.id === 102)),),
  sanitary_resolution_required: true
};
await sanitaryView.openResolveModal(quarantineIncident);
assert('10.3 La ruta que publica sanitary_resolution_required abre la puerta sanitaria',
  sanitaryView.sanitaryResolutionRequired === true && sanitaryView.selectedIncident?.id === 102);

sanitaryView.resolveDiagnosis = 'Sonda NTC descalibrada registrando 14 grados en cámara de frescos.';
sanitaryView.resolveAction = 'Sustitución de sonda NTC y verificación del ciclo a 3,5 grados.';
assert('10.4 Con los textos válidos y sin declaraciones sanitarias el cierre sigue bloqueado',
  sanitaryView.isDiagnosisValid === true
    && sanitaryView.isActionValid === true
    && sanitaryView.canResolve === false);

await sanitaryView.submitResolve();
assert('10.5 Sin bloque sanitario válido el cierre se intercepta y no llega a la API',
  sanitaryView.resolveError.length > 0 && sanitaryCalledId === null);

sanitaryView.$refs = {
  resolutionSanitaryBlockRef: {
    validate: () => ({
      isValid: true,
      error: '',
      payload: {
        sanitary_declarations: { temperature_c: 3.5, stock_destroyed: true, hygiene_checklist: true }
      }
    })
  }
};
sanitaryView.handleSanitaryChange({
  isValid: true,
  temperature_c: 3.5,
  stock_destroyed: true,
  hygiene_checklist: true
});
assert('10.6 El bloque válido habilita el cierre', sanitaryView.canResolve === true);

await sanitaryView.submitResolve();
assert('10.7 El cierre viaja con las tres declaraciones sanitarias en el payload',
  sanitaryCalledId === 102
    && sanitaryCalledPayload?.sanitary_declarations?.temperature_c === 3.5
    && sanitaryCalledPayload?.sanitary_declarations?.stock_destroyed === true
    && sanitaryCalledPayload?.sanitary_declarations?.hygiene_checklist === true,
  JSON.stringify(sanitaryCalledPayload));

// La vigilancia no es universal: sin la bandera del servidor el cierre sigue siendo el de siempre.
const plainView = createRouteInstance();
sanitaryCalledId = null;
sanitaryCalledPayload = 'sin-llamar';
await plainView.openResolveModal(JSON.parse(JSON.stringify(mockRouteIncidents.find(i => i.id === 101))));
plainView.resolveDiagnosis = 'Monedero con el selector de monedas desgastado y sin retorno de cambio.';
plainView.resolveAction = 'Sustitución del selector de monedas y prueba de ciclo completo de cobro.';
assert('10.8 Sin bandera sanitaria la vista no exige las declaraciones (la vigilancia no es universal)',
  plainView.sanitaryResolutionRequired === false && plainView.canResolve === true);
await plainView.submitResolve();
assert('10.9 El cierre ordinario conserva el contrato posicional, sin bloque sanitario añadido',
  sanitaryCalledId === 101 && sanitaryCalledPayload === null);

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
