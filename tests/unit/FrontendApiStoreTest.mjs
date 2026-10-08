/**
 * VendGuard - Frontend API Client & Reactive Store Test Suite (T-32)
 * 
 * Verifies:
 * 1. api.js handles fetch() calls correctly.
 * 2. api.js automatically attaches Bearer tokens and X-Site-Code.
 * 3. api.js handles HTTP errors transforming them into structured ApiError instances.
 * 4. api.js correctly unpacks VendGuard standard envelope { success: true, data: ... }.
 * 5. store.js exposes reactive user and site location state.
 * 6. store.js synchronizes session state with localStorage and api.js.
 * 7. store.js auto-clears session on HTTP 401 Unauthorized.
 */

// Mock localStorage for headless test execution
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

// Import modules
import { ApiClient, ApiError, api } from '../../public/assets/js/api.js';
import { store, state, setInternalSession, setSiteSession, clearSession, restoreSession, addAlert, removeAlert } from '../../public/assets/js/store.js';

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
console.log(' VendGuard: Frontend Test Suite - API Client & Reactive Store (T-32)');
console.log('======================================================================\n');

// Mock fetch harness
let lastFetchCall = null;
let mockFetchResponse = null;

globalThis.fetch = async (url, options) => {
  lastFetchCall = { url, options };
  return mockFetchResponse;
};

// ---------------------------------------------------------------------
// TEST GROUP 1: ApiClient Core & Token Attachment
// ---------------------------------------------------------------------
console.log('--- Group 1: ApiClient Request Dispatch & Automatic Token Injection ---');

const client = new ApiClient('/api');

// 1.1: Request without token
mockFetchResponse = {
  ok: true,
  status: 200,
  headers: new Map([['content-type', 'application/json']]),
  json: async () => ({ success: true, data: { test: 'value' } })
};

const res1 = await client.get('/test-endpoint');
assert('1.1 client.get dispatches GET request to base URL', lastFetchCall.url === '/api/test-endpoint');
assert('1.2 Default GET has no Authorization header if token not set', lastFetchCall.options.headers['Authorization'] === undefined);
assert('1.3 Successfully unpacks envelope data', res1?.test === 'value');

// 1.4: Request with Bearer token
client.setToken('auth_token_mock_12345');
client.setSiteCode('SEDE-BCN-01');

await client.post('/test-post', { key: 'hello' });
assert('1.4 Automatic injection of Authorization Bearer header', lastFetchCall.options.headers['Authorization'] === 'Bearer auth_token_mock_12345');
assert('1.5 Retired X-Site-Code header is no longer injected (S-4: site session is a Bearer token)', lastFetchCall.options.headers['X-Site-Code'] === undefined);
assert('1.6 Auto-sets Content-Type to application/json for object bodies', lastFetchCall.options.headers['Content-Type'].includes('application/json'));
assert('1.7 Request body is stringified JSON', lastFetchCall.options.body === JSON.stringify({ key: 'hello' }));

// 1.8: Request with FormData (no manual Content-Type)
class MockFormData {}
globalThis.FormData = MockFormData;
const mockForm = new MockFormData();

await client.upload('/test-upload', mockForm);
assert('1.8 Uploading FormData does NOT set manual Content-Type header (browser boundary handling)', lastFetchCall.options.headers['Content-Type'] === undefined);
assert('1.9 Upload uses POST method and retains Bearer token', lastFetchCall.options.method === 'POST' && lastFetchCall.options.headers['Authorization'] === 'Bearer auth_token_mock_12345');

// ---------------------------------------------------------------------
// TEST GROUP 2: ApiClient Error Handling
// ---------------------------------------------------------------------
console.log('\n--- Group 2: ApiClient Structured Error Handling (ApiError) ---');

// 2.1: 409 Conflict (Duplicate incident)
mockFetchResponse = {
  ok: false,
  status: 409,
  statusText: 'Conflict',
  headers: new Map([['content-type', 'application/json']]),
  json: async () => ({
    success: false,
    error: {
      code: 'MACHINE_HAS_ACTIVE_INCIDENT',
      message: 'La máquina ya cuenta con un aviso activo.',
      details: { ticket_code: 'INC-2026-0001', status: 'IN_PROGRESS' }
    }
  })
};

let caughtError = null;
try {
  await client.post('/incidents', { machine_id: 1 });
} catch (e) {
  caughtError = e;
}

assert('2.1 Non-2xx response throws ApiError', caughtError instanceof ApiError);
assert('2.2 ApiError contains correct HTTP status 409', caughtError?.status === 409);
assert('2.3 ApiError contains application error code', caughtError?.code === 'MACHINE_HAS_ACTIVE_INCIDENT');
assert('2.4 ApiError contains human-readable message', caughtError?.message === 'La máquina ya cuenta con un aviso activo.');
assert('2.5 ApiError contains details object', caughtError?.details?.ticket_code === 'INC-2026-0001');

// 2.6: 401 Unauthorized trigger callback
let unauthorizedTriggered = false;
client.setOnUnauthorized((code, msg) => {
  unauthorizedTriggered = true;
});

mockFetchResponse = {
  ok: false,
  status: 401,
  statusText: 'Unauthorized',
  headers: new Map([['content-type', 'application/json']]),
  json: async () => ({
    success: false,
    error: { code: 'UNAUTHORIZED', message: 'Token caducado' }
  })
};

try {
  await client.get('/protected-resource');
} catch (e) {
  // Expected error
}
assert('2.6 HTTP 401 triggers onUnauthorized listener callback', unauthorizedTriggered === true);

// ---------------------------------------------------------------------
// TEST GROUP 3: Reactive Store State & Session Management
// ---------------------------------------------------------------------
console.log('\n--- Group 3: Reactive Store State (User & Location) ---');

// Reset store
clearSession();
assert('3.1 Initial store state has null session and empty alerts', state.token === null && state.user === null && state.location === null && state.alerts.length === 0);
assert('3.2 isAuthenticated is initially false', store.isAuthenticated === false);

// 3.3: Set internal user session (Coordinator)
const mockCoordinator = {
  id: 1,
  name: 'Carles Coordinador',
  email: 'carles.coord@vendguard.internal',
  role: 'COORDINATOR'
};

setInternalSession(mockCoordinator, 'auth_token_coord_999');
assert('3.3 setInternalSession updates reactive state.user', state.user?.id === 1 && state.user?.role === 'COORDINATOR');
assert('3.4 setInternalSession updates reactive state.token', state.token === 'auth_token_coord_999');
assert('3.5 setInternalSession sets authType to internal', state.authType === 'internal');
assert('3.6 store.isAuthenticated is true', store.isAuthenticated === true);
assert('3.7 store.isCoordinator is true', store.isCoordinator === true);
assert('3.8 store.isTechnician is false', store.isTechnician === false);
assert('3.9 store.isSiteSession is false', store.isSiteSession === false);
assert('3.10 api.getToken() is synchronized with store session', api.getToken() === 'auth_token_coord_999');
assert('3.11 Session persisted to localStorage', JSON.parse(localStorage.getItem('vendguard_session'))?.token === 'auth_token_coord_999');

// 3.12: Set site session (Location Responsible)
const mockLocation = {
  id: 4,
  site_code: 'SEDE-MAD-02',
  name: 'Campus Tecnológico',
  address: 'Calle Alcalá 50, Madrid'
};

setSiteSession(mockLocation, 'site_token_madrid_777');
assert('3.12 setSiteSession updates reactive state.location', state.location?.site_code === 'SEDE-MAD-02');
assert('3.13 setSiteSession clears state.user', state.user === null);
assert('3.14 state.authType is site', state.authType === 'site');
assert('3.15 store.isSiteSession is true', store.isSiteSession === true);
assert('3.16 store.isCoordinator is false', store.isCoordinator === false);
assert('3.17 api.getSiteCode() is synchronized with location site_code', api.getSiteCode() === 'SEDE-MAD-02');
assert('3.18 api.getToken() is synchronized with site token', api.getToken() === 'site_token_madrid_777');

// 3.19: Clear session
clearSession();
assert('3.19 clearSession resets state.token to null', state.token === null);
assert('3.20 clearSession resets state.location to null', state.location === null);
assert('3.21 clearSession wipes api auth tokens', api.getToken() === null && api.getSiteCode() === null);
assert('3.22 clearSession removes session from localStorage', localStorage.getItem('vendguard_session') === null);

// 3.23: Restore session from localStorage
localStorage.setItem('vendguard_session', JSON.stringify({
  authType: 'internal',
  token: 'restored_token_111',
  user: { id: 2, name: 'Jordi Técnico', role: 'TECHNICIAN' }
}));

const restored = restoreSession();
assert('3.23 restoreSession successfully recovers session from storage', restored === true);
assert('3.24 Restored state.user matches storage', state.user?.role === 'TECHNICIAN');
assert('3.25 store.isTechnician is true', store.isTechnician === true);
assert('3.26 api.getToken() was restored', api.getToken() === 'restored_token_111');

// ---------------------------------------------------------------------
// TEST GROUP 4: Alerts and UI Reactive State
// ---------------------------------------------------------------------
console.log('\n--- Group 4: Alerts & UI Reactive Utilities ---');

const alertId = addAlert('Operación completada con éxito', 'success', 0);
assert('4.1 addAlert appends notification to state.alerts', state.alerts.length === 1);
assert('4.2 Alert contains correct message and type', state.alerts[0].message === 'Operación completada con éxito' && state.alerts[0].type === 'success');

removeAlert(alertId);
assert('4.3 removeAlert removes alert by ID', state.alerts.length === 0);

// ---------------------------------------------------------------------
// TEST GROUP 5: Technician refund inspection API (T-REF-16)
// ---------------------------------------------------------------------
console.log('\n--- Group 5: Technician refund inspection API ---');

mockFetchResponse = {
  ok: true,
  status: 200,
  headers: new Map([['content-type', 'application/json']]),
  json: async () => ({ success: true, data: { has_pending_verdict: true, requests: [] } })
};
const refundInspection = await api.technician.getRefundInspection(42);
assert('5.1 Technician refund inspection uses its incident-scoped GET endpoint',
  lastFetchCall.url === '/api/technician/incidents/42/refund' && lastFetchCall.options.method === 'GET');
assert('5.2 Technician refund inspection unpacks the JSON data envelope',
  refundInspection?.has_pending_verdict === true && Array.isArray(refundInspection.requests));

// ---------------------------------------------------------------------
// TEST GROUP 6: Site refund desk API (T-REF-17)
// ---------------------------------------------------------------------
console.log('\n--- Group 6: Site refund desk API ---');

mockFetchResponse = {
  ok: true,
  status: 200,
  headers: new Map([['content-type', 'application/json']]),
  json: async () => ({ success: true, data: { total: 1, ready_for_pickup_total: 1, refunds: [] } })
};
const siteRefunds = await api.site.getRefunds();
assert('6.1 Site refunds list uses the location refunds GET endpoint',
  lastFetchCall.url === '/api/location/refunds' && lastFetchCall.options.method === 'GET');
assert('6.2 Site refunds list unpacks the envelope totals',
  siteRefunds?.total === 1 && siteRefunds?.ready_for_pickup_total === 1 && Array.isArray(siteRefunds.refunds));

mockFetchResponse = {
  ok: true,
  status: 200,
  headers: new Map([['content-type', 'application/json']]),
  json: async () => ({ success: true, data: { id: 21, status: 'REFUNDED_IN_HAND', claimant_name_anon: 'Laura S.' } })
};
const delivered = await api.site.deliverRefund(21, '4821');
assert('6.3 Handover posts the 4-digit PIN to the deliver endpoint',
  lastFetchCall.url === '/api/location/refunds/21/deliver'
    && lastFetchCall.options.method === 'POST'
    && lastFetchCall.options.body === JSON.stringify({ pickup_pin: '4821' }));
assert('6.4 Handover returns the updated anonymized case',
  delivered?.status === 'REFUNDED_IN_HAND' && delivered?.claimant_name_anon === 'Laura S.');

// ---------------------------------------------------------------------
// TEST GROUP 7: Coordinator refunds inbox API (T-REF-18)
// ---------------------------------------------------------------------
console.log('\n--- Group 7: Coordinator refunds inbox API ---');

mockFetchResponse = {
  ok: true,
  status: 200,
  headers: new Map([['content-type', 'application/json']]),
  json: async () => ({ success: true, data: { total: 2, requires_approval_total: 1, items: [] } })
};
const inbox = await api.coordinator.getRefunds({ status: 'REQUIRES_COORDINATOR_APPROVAL', requires_approval_only: 1 });
assert('7.1 Coordinator inbox targets the global refunds endpoint with its filters',
  lastFetchCall.url === '/api/coordinator/refunds?status=REQUIRES_COORDINATOR_APPROVAL&requires_approval_only=1'
    && lastFetchCall.options.method === 'GET');
assert('7.2 Coordinator inbox unpacks the pending-approval counter',
  inbox?.requires_approval_total === 1 && Array.isArray(inbox.items));

mockFetchResponse = {
  ok: true,
  status: 200,
  headers: new Map([['content-type', 'application/json']]),
  json: async () => ({ success: true, data: { id: 31, status: 'VERIFIED_PENDING_PAYMENT' } })
};
const approvedRefund = await api.coordinator.approveRefund(31, 12.5, 'Autorizado tras revisar el histórico de ventas.');
assert('7.3 Double approval posts the final amount and the notes',
  lastFetchCall.url === '/api/coordinator/refunds/31/approve'
    && lastFetchCall.options.method === 'POST'
    && lastFetchCall.options.body === JSON.stringify({ approved_amount: 12.5, notes: 'Autorizado tras revisar el histórico de ventas.' })
    && approvedRefund?.status === 'VERIFIED_PENDING_PAYMENT');

mockFetchResponse = {
  ok: true,
  status: 200,
  headers: new Map([['content-type', 'application/json']]),
  json: async () => ({ success: true, data: { id: 32, status: 'PAID_DIGITAL', payment_reference: 'BIZUM-20261002-998822' } })
};
const paidRefund = await api.coordinator.payRefund(32, 'BIZUM-20261002-998822', 8);
assert('7.4 Digital settlement posts the bank reference and the settled amount',
  lastFetchCall.url === '/api/coordinator/refunds/32/pay'
    && lastFetchCall.options.body === JSON.stringify({ payment_reference: 'BIZUM-20261002-998822', paid_amount: 8 })
    && paidRefund?.payment_reference === 'BIZUM-20261002-998822');

await api.coordinator.payRefund(33, 'TRF-20261002-0001');
assert('7.5 Settlement without an explicit amount lets the backend apply the approved figure',
  lastFetchCall.options.body === JSON.stringify({ payment_reference: 'TRF-20261002-0001' }));

mockFetchResponse = {
  ok: true,
  status: 200,
  headers: new Map([['content-type', 'application/json']]),
  json: async () => ({ success: true, data: { id: 35, status: 'REJECTED', coordinator_decision: 'REJECTED' } })
};
const rejectedRefund = await api.coordinator.rejectRefund(35, 'Inspección sin monedas atascadas y máquina operando con normalidad.');
assert('7.6 Motivated rejection posts the mandatory written reason',
  lastFetchCall.url === '/api/coordinator/refunds/35/reject'
    && lastFetchCall.options.body === JSON.stringify({ rejection_reason: 'Inspección sin monedas atascadas y máquina operando con normalidad.' })
    && rejectedRefund?.status === 'REJECTED');

// ---------------------------------------------------------------------
// TEST GROUP 8: Technician Machine History Endpoint Client (EARS H.1-H.5,
// specs/technical/technician_machine_history_contracts.md)
// ---------------------------------------------------------------------
console.log('\n--- Group 8: Technician Machine History Client ---');

// 8.1: Successful retrieval unwraps the envelope
mockFetchResponse = {
  ok: true,
  status: 200,
  headers: new Map([['content-type', 'application/json']]),
  json: async () => ({ success: true, data: {
    machine: { id: 12, code: 'VM-012', model: 'Necta Koro' },
    history: [ { id: 3401, ticket_code: 'INC-2026-0341', status: 'CLOSED', reopen_count: 1 } ]
  } })
};

const machineHistory = await api.technician.getMachineHistory(12);
assert('8.1 getMachineHistory dispatches GET to /technician/machines/{id}/history',
  lastFetchCall.url === '/api/technician/machines/12/history' && lastFetchCall.options.method === 'GET');
assert('8.2 getMachineHistory unwraps envelope with machine block and history list',
  machineHistory?.machine?.id === 12 && Array.isArray(machineHistory?.history)
    && machineHistory.history[0]?.reopen_count === 1);

// 8.3: 403 NOT_ASSIGNED_TO_TECHNICIAN becomes a structured ApiError (EARS H.3)
mockFetchResponse = {
  ok: false,
  status: 403,
  headers: new Map([['content-type', 'application/json']]),
  json: async () => ({ success: false, error: { code: 'NOT_ASSIGNED_TO_TECHNICIAN', message: 'Solo puedes consultar el historial de máquinas con una avería activa asignada a tu ruta.' } })
};

let historyForbiddenError = null;
try {
  await api.technician.getMachineHistory(99);
} catch (err) {
  historyForbiddenError = err;
}
assert('8.3 403 NOT_ASSIGNED_TO_TECHNICIAN surfaces as structured ApiError',
  historyForbiddenError instanceof ApiError
    && historyForbiddenError.status === 403
    && historyForbiddenError.code === 'NOT_ASSIGNED_TO_TECHNICIAN');

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-32 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
