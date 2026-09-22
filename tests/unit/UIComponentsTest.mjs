/**
 * VendGuard - UI Components Test Suite (T-33)
 * 
 * Verifies:
 * 1. IncidentBadge renders semantic colors for urgencies and lifecycle statuses.
 * 2. IncidentBadge applies 4px interactive border-radius.
 * 3. ModalDialog conforms to WAI-ARIA (role="dialog", aria-modal="true", aria-labelledby).
 * 4. ModalDialog applies 8px card border-radius and respects sizes (sm, md, lg).
 * 5. AppNavbar renders brand identity and contextual session badges.
 * 6. AppNavbar handles logout action, wipes store session and emits 'logout'.
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

// Import component modules and store
import { IncidentBadge } from '../../public/assets/js/components/IncidentBadge.js';
import { ModalDialog } from '../../public/assets/js/components/ModalDialog.js';
import { AppNavbar } from '../../public/assets/js/components/AppNavbar.js';
import { store, state, setInternalSession, setSiteSession, clearSession } from '../../public/assets/js/store.js';

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
console.log(' VendGuard: Frontend Test Suite - UI Components (T-33)');
console.log('======================================================================\n');

// ---------------------------------------------------------------------
// TEST GROUP 1: IncidentBadge Semantic Colors & Rounding
// ---------------------------------------------------------------------
console.log('--- Group 1: IncidentBadge Semantic Colors and 4px Radius ---');

// Helper to evaluate badge computed properties
function evalBadge(props) {
  const instance = {
    value: props.value,
    type: props.type || 'auto',
    size: props.size || 'md',
    customLabel: props.customLabel || null
  };
  const normalizedKey = IncidentBadge.computed.normalizedKey.call(instance);
  const resolvedConfig = IncidentBadge.computed.resolvedConfig.call({ ...instance, normalizedKey });
  const displayLabel = IncidentBadge.computed.displayLabel.call({ ...instance, resolvedConfig });
  const badgeStyle = IncidentBadge.computed.badgeStyle.call({ ...instance, resolvedConfig, normalizedKey });
  const dotColor = IncidentBadge.computed.dotColor.call({ ...instance, resolvedConfig });

  return { normalizedKey, resolvedConfig, displayLabel, badgeStyle, dotColor };
}

// 1.1: CRITICAL Urgency
const criticalBadge = evalBadge({ value: 'CRITICAL', type: 'urgency' });
assert('1.1 CRITICAL urgency resolves to label "Crítica"', criticalBadge.displayLabel === 'Crítica');
assert('1.2 CRITICAL urgency uses red semantic background (#fee2e2)', criticalBadge.resolvedConfig.bg === '#fee2e2');
assert('1.3 CRITICAL urgency uses red semantic text color (#dc2626)', criticalBadge.resolvedConfig.color === '#dc2626');
assert('1.4 Badge enforces 4px interactive border-radius', criticalBadge.badgeStyle.borderRadius.includes('4px'));

// 1.5: Bilingual Spanish input: 'CRÍTICA'
const criticaBadge = evalBadge({ value: 'CRÍTICA' });
assert('1.5 Spanish value "CRÍTICA" auto-resolves with same red color', criticaBadge.resolvedConfig.color === '#dc2626');

// 1.6: HIGH Urgency
const highBadge = evalBadge({ value: 'HIGH', type: 'urgency' });
assert('1.6 HIGH urgency resolves to label "Alta"', highBadge.displayLabel === 'Alta');
assert('1.7 HIGH urgency uses orange semantic background (#ffedd5)', highBadge.resolvedConfig.bg === '#ffedd5');

// 1.8: MEDIUM Urgency
const mediumBadge = evalBadge({ value: 'MEDIUM', type: 'urgency' });
assert('1.8 MEDIUM urgency resolves to label "Media"', mediumBadge.displayLabel === 'Media');
assert('1.9 MEDIUM urgency uses amber semantic background (#fef9c3)', mediumBadge.resolvedConfig.bg === '#fef9c3');

// 1.10: LOW Urgency
const lowBadge = evalBadge({ value: 'LOW', type: 'urgency' });
assert('1.10 LOW urgency resolves to label "Baja"', lowBadge.displayLabel === 'Baja');
assert('1.11 LOW urgency uses blue semantic background (#dbeafe)', lowBadge.resolvedConfig.bg === '#dbeafe');

// 1.12: Lifecycle Statuses
const inProgressBadge = evalBadge({ value: 'EN_CURSO', type: 'status' });
assert('1.12 EN_CURSO status resolves to "En curso"', inProgressBadge.displayLabel === 'En curso');

const resolvedBadge = evalBadge({ value: 'RESOLVED', type: 'status' });
assert('1.13 RESOLVED status resolves to "Resuelta (Garantía)"', resolvedBadge.displayLabel === 'Resuelta (Garantía)');
assert('1.14 RESOLVED status uses green semantic background (#eaf8f1)', resolvedBadge.resolvedConfig.bg === '#eaf8f1');

const pendingPartsBadge = evalBadge({ value: 'PENDING_PARTS' });
assert('1.15 PENDING_PARTS status auto-detected and resolves to "Pendiente repuesto"', pendingPartsBadge.displayLabel === 'Pendiente repuesto');

const cancelledBadge = evalBadge({ value: 'CANCELLED' });
assert('1.16 CANCELLED badge applies line-through decoration', cancelledBadge.badgeStyle.textDecoration === 'line-through');

// 1.17: Size variant
const smallBadge = evalBadge({ value: 'CRITICAL', size: 'sm' });
assert('1.17 Size sm reduces font size to 11px', smallBadge.badgeStyle.fontSize === '11px');

// ---------------------------------------------------------------------
// TEST GROUP 2: ModalDialog Accessibility & 8px Radius
// ---------------------------------------------------------------------
console.log('\n--- Group 2: ModalDialog WAI-ARIA Accessibility and 8px Radius ---');

assert('2.1 ModalDialog defines name "ModalDialog"', ModalDialog.name === 'ModalDialog');
assert('2.2 ModalDialog template contains role="dialog"', ModalDialog.template.includes('role="dialog"'));
assert('2.3 ModalDialog template contains aria-modal="true"', ModalDialog.template.includes('aria-modal="true"'));
assert('2.4 ModalDialog template binds :aria-labelledby', ModalDialog.template.includes(':aria-labelledby="titleId"'));
assert('2.5 ModalDialog template applies 8px card border-radius', ModalDialog.template.includes('border-radius: var(--radius-card, 8px)'));

// Helper to evaluate ModalDialog computed properties
function evalModal(size) {
  return ModalDialog.computed.maxWidthPx.call({ size });
}

assert('2.6 Size "sm" sets max-width to 420px', evalModal('sm') === '420px');
assert('2.7 Size "md" sets max-width to 560px', evalModal('md') === '560px');
assert('2.8 Size "lg" sets max-width to 720px', evalModal('lg') === '720px');

// Close and Keyboard handling
let emittedEvents = [];
const mockModalInstance = {
  modelValue: true,
  closeOnBackdrop: true,
  $emit: (evt, val) => { emittedEvents.push({ evt, val }); },
  close() { ModalDialog.methods.close.call(this); }
};

ModalDialog.methods.close.call(mockModalInstance);
assert('2.9 close() emits update:modelValue with false', emittedEvents.some(e => e.evt === 'update:modelValue' && e.val === false));
assert('2.10 close() emits close event', emittedEvents.some(e => e.evt === 'close'));

emittedEvents = [];
ModalDialog.methods.handleKeyDown.call(mockModalInstance, { key: 'Escape' });
assert('2.11 Pressing Escape key triggers close()', emittedEvents.some(e => e.evt === 'close'));

emittedEvents = [];
ModalDialog.methods.handleKeyDown.call(mockModalInstance, { key: 'Enter' });
assert('2.12 Pressing non-Escape key does not trigger close()', emittedEvents.length === 0);

// ---------------------------------------------------------------------
// TEST GROUP 3: AppNavbar Brand, Context Badges & Logout
// ---------------------------------------------------------------------
console.log('\n--- Group 3: AppNavbar Brand, Session Badges & Logout ---');

assert('3.1 AppNavbar defines name "AppNavbar"', AppNavbar.name === 'AppNavbar');
assert('3.2 AppNavbar template uses DM Sans display typography for brand logo', AppNavbar.template.includes("'DM Sans'"));
assert('3.3 AppNavbar template incorporates primary electric blue #2560ff', AppNavbar.template.includes('#2560ff'));
assert('3.4 AppNavbar template contains loading progress indicator', AppNavbar.template.includes('vg-progress-animation'));

// 3.5: Navbar without authentication
clearSession();
const unauthNavbar = {
  isAuthenticated: AppNavbar.computed.isAuthenticated(),
  isSiteSession: AppNavbar.computed.isSiteSession(),
  user: AppNavbar.computed.user(),
  location: AppNavbar.computed.location(),
  isLoading: AppNavbar.computed.isLoading()
};
assert('3.5 AppNavbar reflects unauthenticated state', unauthNavbar.isAuthenticated === false);

// 3.6: Navbar with Location Session
setSiteSession({ site_code: 'SEDE-BCN-01', name: 'Hospital del Mar' }, 'site_token_test');
const siteNavbar = {
  isAuthenticated: AppNavbar.computed.isAuthenticated(),
  isSiteSession: AppNavbar.computed.isSiteSession(),
  location: AppNavbar.computed.location()
};
assert('3.6 AppNavbar reflects site session', siteNavbar.isAuthenticated === true && siteNavbar.isSiteSession === true);
assert('3.7 Location data exposes site_code "SEDE-BCN-01"', siteNavbar.location?.site_code === 'SEDE-BCN-01');

// 3.8: Navbar with Internal User Session (Coordinator)
setInternalSession({ id: 1, name: 'Laura Coordinación', email: 'laura@vendguard.internal', role: 'COORDINATOR' }, 'auth_token_coord');
const coordNavbar = {
  isAuthenticated: AppNavbar.computed.isAuthenticated(),
  isSiteSession: AppNavbar.computed.isSiteSession(),
  user: AppNavbar.computed.user(),
  roleLabel: AppNavbar.computed.roleLabel.call({ user: store.state.user })
};
assert('3.8 AppNavbar reflects coordinator session', coordNavbar.isAuthenticated === true && coordNavbar.isSiteSession === false);
assert('3.9 Role label computes to "Coordinación"', coordNavbar.roleLabel === 'Coordinación');

// 3.10: Technician role label
setInternalSession({ id: 2, name: 'Jordi Ruta', email: 'jordi@vendguard.internal', role: 'TECHNICIAN' }, 'auth_token_tech');
const techRoleLabel = AppNavbar.computed.roleLabel.call({ user: store.state.user });
assert('3.10 Technician role label computes to "Técnico de Ruta"', techRoleLabel === 'Técnico de Ruta');

// 3.11: Logout execution
let navbarEmits = [];
const mockNavbarInstance = {
  $emit: (evt) => { navbarEmits.push(evt); }
};

AppNavbar.methods.handleLogout.call(mockNavbarInstance);
assert('3.11 handleLogout() purges store session', store.isAuthenticated === false && store.state.token === null);
assert('3.12 handleLogout() emits "logout" event', navbarEmits.includes('logout'));

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-33 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
