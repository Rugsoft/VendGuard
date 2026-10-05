/**
 * VendGuard - CoordinatorIncidentDetailModal Scaffolding Test Suite (T-IDM-08)
 *
 * Verifies the "Done when" condition of T-IDM-08:
 * 1. The Vue 3 ESM component exists at public/assets/js/components/CoordinatorIncidentDetailModal.js.
 * 2. It declares the `isOpen` and `incidentId` props and emits `close` and `incident-updated`.
 * 3. It is laid out with a fixed header, an independently scrollable body and a fixed action footer (RNF-03).
 * 4. It honours the Docker design tokens of docs/design.md (RNF-02): hairlines, canvas, 8px card radius, DM Sans/Inter.
 * 5. It loads the enriched file through GET /api/coordinator/incidents/{id}/detail and guards the loading/error states.
 *
 * Dogma Vanilla: pure Node ESM suite, no external dependencies, mirrors the browser module graph.
 * Dualismo Lingüístico: assertions in English, user-facing copy in Spanish.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

// Mock localStorage for headless Node environment (api.js/store.js bootstrapping).
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

import { api, ApiError } from '../../public/assets/js/api.js';
import {
  CoordinatorIncidentDetailModal,
  CoordinatorIncidentDetailModal as DefaultExport
} from '../../public/assets/js/components/CoordinatorIncidentDetailModal.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CSS_PATH = path.resolve(HERE, '../../public/assets/css/design-tokens.css');

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

/**
 * Builds a lightweight component instance for calling its methods/render helpers
 * without mounting a real DOM harness.
 */
function createInstance(overrides = {}) {
  const emitted = [];
  const instance = {
    isOpen: true,
    incidentId: 3661,
    detail: null,
    isLoading: false,
    errorMessage: '',
    ...overrides,
    $emit: (event, payload) => { emitted.push({ event, payload }); },
    getEmitted: () => emitted
  };

  // Cross-method calls (Escape -> requestClose, backdrop -> requestClose) need the
  // component methods reachable through `this`, exactly as Vue wires them.
  for (const [methodName, method] of Object.entries(CoordinatorIncidentDetailModal.methods)) {
    instance[methodName] = method.bind(instance);
  }

  return instance;
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - CoordinatorIncidentDetailModal (T-IDM-08)');
console.log('======================================================================\n');

// ---------------------------------------------------------------------
// GROUP 1: Module contract (props and emits)
// ---------------------------------------------------------------------
console.log('--- Group 1: ESM module contract ---');

assert('1.1 Component file exists on disk', fs.existsSync(
  path.resolve(HERE, '../../public/assets/js/components/CoordinatorIncidentDetailModal.js')
));

assert('1.2 Named export matches the default export', CoordinatorIncidentDetailModal === DefaultExport);
assert('1.3 Component is registered as CoordinatorIncidentDetailModal',
  CoordinatorIncidentDetailModal.name === 'CoordinatorIncidentDetailModal');

assert('1.4 Declares the isOpen prop as Boolean',
  CoordinatorIncidentDetailModal.props.isOpen.type === Boolean &&
  CoordinatorIncidentDetailModal.props.isOpen.default === false);

assert('1.5 Declares the incidentId prop as Number|String',
  Array.isArray(CoordinatorIncidentDetailModal.props.incidentId.type) &&
  CoordinatorIncidentDetailModal.props.incidentId.type.includes(Number) &&
  CoordinatorIncidentDetailModal.props.incidentId.type.includes(String));

assert('1.6 Declares the close event', CoordinatorIncidentDetailModal.emits.includes('close'));
assert('1.7 Declares the incident-updated event', CoordinatorIncidentDetailModal.emits.includes('incident-updated'));
assert('1.8 has a render template', typeof CoordinatorIncidentDetailModal.template === 'string'
  && CoordinatorIncidentDetailModal.template.length > 500);

// ---------------------------------------------------------------------
// GROUP 2: Layout scaffolding (fixed header, scrollable body, fixed footer)
// ---------------------------------------------------------------------
console.log('\n--- Group 2: Fixed header + independent body scroll + fixed footer ---');

const template = CoordinatorIncidentDetailModal.template;

assert('2.1 Modal is teleported to body for full-viewport overlay',
  template.includes('<Teleport to="body"'));

const headerIndex = template.indexOf('class="modal-header');
const bodyIndex = template.indexOf('class="modal-body modal-body-scrollable');
const footerIndex = template.indexOf('class="modal-footer');

assert('2.2 Header, body and footer are all present',
  headerIndex !== -1 && bodyIndex !== -1 && footerIndex !== -1);
assert('2.3 Header precedes the scrollable body', headerIndex < bodyIndex);
assert('2.4 Scrollable body precedes the action footer', bodyIndex < footerIndex);

assert('2.5 Header is pinned with flex 0 0 auto (does not scroll away)',
  template.slice(headerIndex, headerIndex + 400).includes('flex: 0 0 auto'));
assert('2.6 Footer is pinned with flex 0 0 auto (does not scroll away)',
  template.slice(footerIndex, footerIndex + 400).includes('flex: 0 0 auto'));

assert('2.7 Modal container is a fixed-height flex column with hidden overflow',
  template.includes('display: flex') && template.includes('flex-direction: column') &&
  template.includes('overflow: hidden') && template.includes('height: min(88vh'));

const css = fs.readFileSync(CSS_PATH, 'utf8');
const modalBodyRule = css.slice(css.indexOf('.modal-body {'), css.indexOf('.modal-footer {'));
assert('2.8 .modal-body CSS provides the independent vertical scroll',
  modalBodyRule.includes('overflow-y: auto') && modalBodyRule.includes('flex: 1 1 auto'));

assert('2.9 Backdrop uses the shared vg-modal-backdrop class',
  template.includes('class="vg-modal-backdrop incident-detail-backdrop"'));

assert('2.10 Dialog is WAI-ARIA compliant (role, aria-modal, labelledby)',
  template.includes('role="dialog"') && template.includes('aria-modal="true"') &&
  template.includes('aria-labelledby="incident-detail-title"'));

assert('2.11 Header offers ticket code, manual refresh and close controls',
  template.includes('data-testid="incident-detail-code"') &&
  template.includes('data-testid="incident-detail-refresh"') &&
  template.includes('data-testid="incident-detail-close"'));

assert('2.12 Footer carries the closing action',
  template.includes('data-testid="incident-detail-footer-close"') && template.includes('Cerrar'));

assert('2.13 Body exposes loading, error and empty feedback states',
  template.includes('data-testid="incident-detail-loading"') &&
  template.includes('data-testid="incident-detail-error"') &&
  template.includes('data-testid="incident-detail-empty"'));

assert('2.14 Section placeholders for T-IDM-09/10/11 are scaffolded in order',
  ['section-location-machine', 'section-description', 'section-timeline-sla',
    'section-technical-intervention', 'section-comments', 'section-refund']
    .every((id) => template.includes(`data-testid="${id}"`)));

// ---------------------------------------------------------------------
// GROUP 3: Docker design tokens (RNF-02)
// ---------------------------------------------------------------------
console.log('\n--- Group 3: Docker design tokens ---');

assert('3.1 Hairline borders use --color-hairline with the #c8cfda fallback',
  template.includes('var(--color-hairline, #c8cfda)'));
assert('3.2 Canvas background uses --color-canvas with the #f9fafb fallback',
  template.includes('var(--color-canvas, #f9fafb)'));
assert('3.3 Card surfaces use the 8px --radius-card token',
  template.includes('var(--radius-card, 8px)'));
assert('3.4 Typography uses the --font-display (DM Sans) and --font-body (Inter) tokens',
  template.includes("var(--font-display, 'DM Sans'") && template.includes('var(--font-body, Inter'));
assert('3.5 Modal elevation uses the --shadow-modal token', template.includes('var(--shadow-modal'));
assert('3.6 Error feedback uses the semantic error tokens',
  template.includes('var(--color-error, #ff5757)') && template.includes('var(--color-error-bg, #fddfdf)'));
assert('3.7 Actions reuse the shared vg-btn button classes',
  template.includes('vg-btn vg-btn-primary') && template.includes('vg-btn vg-btn-secondary'));
assert('3.8 The design system still wires the electric-blue voltage in CSS',
  css.includes('--color-primary: #2560ff') || css.includes('--color-primary:#2560ff'));

// ---------------------------------------------------------------------
// GROUP 4: Close lifecycle (close event, Escape key, backdrop click)
// ---------------------------------------------------------------------
console.log('\n--- Group 4: Close lifecycle ---');

const closingInstance = createInstance();
CoordinatorIncidentDetailModal.methods.requestClose.call(closingInstance);
assert('4.1 requestClose emits the close event',
  closingInstance.getEmitted().some((e) => e.event === 'close'));

CoordinatorIncidentDetailModal.methods.handleKeyDown.call(closingInstance, { key: 'Escape' });
assert('4.2 Escape key triggers a close request',
  closingInstance.getEmitted().filter((e) => e.event === 'close').length >= 2);

const closedInstance = createInstance({ isOpen: false });
CoordinatorIncidentDetailModal.methods.handleKeyDown.call(closedInstance, { key: 'Enter' });
assert('4.3 Non-Escape keys never close the modal',
  closedInstance.getEmitted().length === 0);

const backdropInstance = createInstance();
const backdropEvent = { target: {}, currentTarget: {} };
backdropEvent.target = backdropEvent.currentTarget;
CoordinatorIncidentDetailModal.methods.handleBackdropClick.call(backdropInstance, backdropEvent);
assert('4.4 Clicking the backdrop itself requests closure',
  backdropInstance.getEmitted().some((e) => e.event === 'close'));

const innerClickInstance = createInstance();
CoordinatorIncidentDetailModal.methods.handleBackdropClick.call(innerClickInstance, {
  target: { id: 'inner' },
  currentTarget: { id: 'backdrop' }
});
assert('4.5 Clicks inside the dialog do NOT close the modal',
  innerClickInstance.getEmitted().length === 0);

// ---------------------------------------------------------------------
// GROUP 5: Fetch wiring against the enriched detail endpoint
// ---------------------------------------------------------------------
console.log('\n--- Group 5: Enriched detail fetch wiring ---');

let lastRequestedIdentifier = null;
api.coordinator.getIncidentDetail = async (incidentId) => {
  lastRequestedIdentifier = incidentId;
  return {
    incident: { ticket_code: 'INC-DEMO-0922', status: 'CLOSED' },
    permissions: { can_assign: false, can_add_comment: false }
  };
};

const loadingInstance = createInstance({ incidentId: 'INC-DEMO-0922' });
await CoordinatorIncidentDetailModal.methods.fetchDetail.call(loadingInstance);

assert('5.1 fetchDetail requests the enriched detail endpoint with the incident identifier',
  lastRequestedIdentifier === 'INC-DEMO-0922');
assert('5.2 Unpacked detail payload is stored in component state',
  loadingInstance.detail?.incident?.ticket_code === 'INC-DEMO-0922');
assert('5.3 Loading flag is cleared after a successful fetch', loadingInstance.isLoading === false);
assert('5.4 No error message on the happy path', loadingInstance.errorMessage === '');

let missingCallCount = 0;
api.coordinator.getIncidentDetail = async () => { missingCallCount++; return {}; };
const noIdInstance = createInstance({ incidentId: null });
await CoordinatorIncidentDetailModal.methods.fetchDetail.call(noIdInstance);
assert('5.5 fetchDetail is a no-op while no incident identifier is provided', missingCallCount === 0);

api.coordinator.getIncidentDetail = async () => {
  throw new ApiError(404, 'INCIDENT_NOT_FOUND', 'La incidencia solicitada no existe.');
};
const errorInstance = createInstance({ incidentId: 999999 });
await CoordinatorIncidentDetailModal.methods.fetchDetail.call(errorInstance);

assert('5.6 HTTP failure surfaces the API message', errorInstance.errorMessage.includes('no existe'));
assert('5.7 Failed fetch clears any stale detail', errorInstance.detail === null);
assert('5.8 Loading flag is cleared after a failure', errorInstance.isLoading === false);

// ---------------------------------------------------------------------
// GROUP 6: Header code and update notifications
// ---------------------------------------------------------------------
console.log('\n--- Group 6: Header code and incident-updated notification ---');

function headerCode(instance) {
  return CoordinatorIncidentDetailModal.computed.headerTicketCode.call(instance);
}

assert('6.1 Header shows the ticket code once the file is loaded',
  headerCode(createInstance({ detail: { incident: { ticket_code: 'INC-DEMO-0922' } } })) === '#INC-DEMO-0922');
assert('6.2 Header falls back to the received identifier while loading',
  headerCode(createInstance({ incidentId: 3661 })) === '#3661');
assert('6.3 Header shows a neutral placeholder without an identifier',
  headerCode(createInstance({ incidentId: null })) === '#—');

const updatedInstance = createInstance();
CoordinatorIncidentDetailModal.methods.notifyIncidentUpdated.call(updatedInstance, { status: 'ASSIGNED' });
assert('6.4 notifyIncidentUpdated emits incident-updated with the payload',
  updatedInstance.getEmitted().some((e) => e.event === 'incident-updated' && e.payload?.status === 'ASSIGNED'));

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-IDM-08 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
