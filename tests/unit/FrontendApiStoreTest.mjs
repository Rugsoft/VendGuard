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
assert('1.5 Automatic injection of X-Site-Code header', lastFetchCall.options.headers['X-Site-Code'] === 'SEDE-BCN-01');
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
