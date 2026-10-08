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
 * T-IDM-09 extends this suite with the reactive rendering of:
 * 6. Header ticket code, status/urgency badges and the conditional reopening chip (RF-02.1, RF-02.2).
 * 7. Reopened banner, location/machine cards with the perishables indicator, description,
 *    report channel and the expandable photo evidence (RF-02.2 to RF-02.4, Art. II).
 * 8. Life-cycle timeline and SLA monitor with active countdown or formal historical balance (RF-03).
 *
 * T-IDM-10 extends this suite with:
 * 9. Technical intervention rendering: pause with catalog and justified out-of-catalog parts,
 *    resolution with diagnosis/action and replaced parts carrying frozen unit costs, plus the
 *    justified discard block (RF-04).
 * 10. Conditional refund case with server-masked payment/contact data and the link to the
 *     Reintegros board, never exposing unmasked fields (RF-06, RNF-05).
 *
 * T-IDM-11 extends this suite with:
 * 11. Comment log rendered in its own bounded scroll container, visually separating public
 *     comments from internal workshop notes (RF-05.1, RF-05.2, case limit 8).
 * 12. Inline form publishing a note through POST .../comments, refreshing the log without
 *     closing the modal and retaining the draft on failure (RF-05.3, RF-08.4).
 *
 * T-IDM-12 extends this suite with:
 * 13. Inline assignment and discard panels toggled from the fixed footer, with the
 *     reactive 20-character counter disabling the discard confirmation (RF-07.3, RF-07.4).
 * 14. Panel actions against the real endpoints: active technicians loaded from the users
 *     list, assignment and soft-delete discard refreshing the file without closing the
 *     modal, drafts retained on failure (RNF-06, Art. III.2 y V.1).
 *
 * T-IDM-21 extends this suite with:
 * 16. Reassignment panel: the fixed footer reopens the "Reasignar Técnico" action, the
 *     current responsible is excluded from the selector (Art. V.3) and the justified motive
 *     (>= 10 real characters) is mandatory before the request reaches the endpoint,
 *     keeping the draft intact when the server rejects it (RF-07.3).
 *
 * T-IDM-13 extends this suite with:
 * 17. Dirty state guard on the modal lifecycle: comment, discard and reassignment drafts
 *     force an explicit confirmation before closing, clean forms close immediately and
 *     every close signal (Escape, shaded backdrop, header and footer buttons) shares that
 *     single guard (RF-08.1 to RF-08.3, plan §3.3).
 *
 * T-IDM-16 certifies the module Done-when checklist with:
 * 18. Terminal-state sealing: RESOLVED, CLOSED and CANCELLED files switch the modal to
 *     consultation mode with zero operational actions and a sealed log (RF-07.2), closing
 *     the certification of blocks rendering, 20-character discard validation, dirty guard
 *     on ESC and clean closing achieved across groups 1-17.
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
    photoZoomOpen: false,
    photoFailed: false,
    isAssignPanelOpen: false,
    isCancelPanelOpen: false,
    ...overrides,
    $emit: (event, payload) => { emitted.push({ event, payload }); },
    getEmitted: () => emitted
  };

  // Cross-method calls (Escape -> requestClose, backdrop -> requestClose) need the
  // component methods reachable through `this`, exactly as Vue wires them.
  for (const [methodName, method] of Object.entries(CoordinatorIncidentDetailModal.methods)) {
    instance[methodName] = method.bind(instance);
  }

  // Vue exposes computed properties as instance getters; the harness mirrors that so a
  // computed can depend on its siblings (e.g. hasReopening reads this.incident).
  for (const [name, handler] of Object.entries(CoordinatorIncidentDetailModal.computed)) {
    Object.defineProperty(instance, name, {
      get: () => handler.call(instance),
      configurable: true
    });
  }

  return instance;
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - CoordinatorIncidentDetailModal (T-IDM-08..T-IDM-13, T-IDM-16, T-IDM-21)');
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

// ---------------------------------------------------------------------
// T-IDM-09 fixtures and helpers
// ---------------------------------------------------------------------

/** Enriched detail payload mirroring the module 09 contract (plan.md §2.1). */
const detailFixture = {
  incident: {
    id: 142,
    ticket_code: 'INC-DEMO-0922',
    status: 'PENDING_PARTS',
    status_label: 'Pendiente de repuestos',
    urgency: 'CRITICAL',
    urgency_label: 'Crítica',
    is_reopened: true,
    reopened_at: '2026-10-02 11:30:00',
    reopened_reason: 'La máquina volvió a fallar 2 horas después de la reparación del técnico.',
    description: 'El compresor no arranca y los sándwiches superan los 9°C.',
    report_channel: 'QR_CODE',
    photo_url: '/uploads/evidence/evidence_142.jpg',
    created_at: '2026-10-01 08:15:00',
    updated_at: '2026-10-02 12:00:00'
  },
  location: {
    id: 1,
    name: 'Hospital del Mar',
    code: 'SEDE-BCN-01',
    address: 'Passeig Marítim 25-29, Barcelona',
    floor_zone: 'Planta 1 - Urgencias',
    has_physical_reception: true
  },
  machine: {
    id: 10,
    code: 'VEND-0101',
    model: 'FAS Perla Fast Cold',
    manufacturer: null,
    type: 'PERISHABLE_FOOD',
    type_label: 'Alimentos perecederos (Sándwiches y lácteos frescos)',
    has_perishables: true
  },
  technician: {
    assigned: true,
    technician_id: 4,
    name: 'Jordi Cruz',
    operator_code: 'OP-BCN-04',
    assigned_at: '2026-10-01 08:30:00',
    assigned_by_name: 'Coordinación Central',
    reassignment_reason: null
  },
  timeline: {
    created_at: '2026-10-01 08:15:00',
    assigned_at: '2026-10-01 08:30:00',
    started_at: '2026-10-01 09:10:00',
    paused_at: '2026-10-01 09:45:00',
    resolved_at: null,
    closed_at: null,
    time_to_assign_minutes: 15,
    time_to_first_response_minutes: 55,
    total_elapsed_minutes: 1665
  },
  sla: {
    has_sla_limit: true,
    sla_limit_hours: 4,
    is_active_countdown: true,
    is_breached: true,
    minutes_remaining: -65,
    historical_balance: 'SLA superado hace 1 h 5 min',
    sla_target_at: '2026-10-01 12:15:00'
  },
  technical_intervention: {
    pause: { is_paused: true, reason: 'Fallo del compresor.', requested_parts: [] },
    resolution: { is_resolved: false, diagnosis: null, corrective_action: null, replaced_parts_declared: false, replaced_parts: [], total_parts_cost: 0 },
    cancellation: { is_cancelled: false, cancelled_at: null, cancelled_by_name: null, reason: null }
  },
  comments: [],
  refund: { has_refund: false },
  permissions: { can_assign: true, can_reassign: false, can_cancel: true, can_add_comment: true }
};

/** Fresh assignment case: the ticket has no responsible technician yet (RF-07.1, RF-07.3). */
const assignFixture = {
  ...detailFixture,
  incident: { ...detailFixture.incident, status: 'REGISTERED', status_label: 'Registrada', is_reopened: false },
  technician: {
    assigned: false,
    technician_id: null,
    name: null,
    operator_code: null,
    assigned_at: null,
    assigned_by_name: null,
    reassignment_reason: null
  },
  permissions: { ...detailFixture.permissions, can_assign: true, can_reassign: false }
};

/** Reassignment case: the ticket already carries a responsible technician (RF-07.3). */
const reassignFixture = {
  ...detailFixture,
  permissions: { ...detailFixture.permissions, can_assign: false, can_reassign: true }
};

function computed(instance, name) {
  return CoordinatorIncidentDetailModal.computed[name].call(instance);
}

// ---------------------------------------------------------------------
// GROUP 7: Header badges, reopened banner and machine/location cards (T-IDM-09)
// ---------------------------------------------------------------------
console.log('\n--- Group 7: Header, reopened banner and metadata rendering ---');

const fixtureInstance = createInstance({ detail: detailFixture });

assert('7.1 Computed accessors surface the incident, location, machine, timeline and SLA blocks',
  computed(fixtureInstance, 'incident') === detailFixture.incident &&
  computed(fixtureInstance, 'location') === detailFixture.location &&
  computed(fixtureInstance, 'machine') === detailFixture.machine &&
  computed(fixtureInstance, 'timeline') === detailFixture.timeline &&
  computed(fixtureInstance, 'sla') === detailFixture.sla);

assert('7.2 Reopening flag follows the incident contract',
  computed(fixtureInstance, 'hasReopening') === true &&
  computed(createInstance({ detail: { incident: { is_reopened: false } } }), 'hasReopening') === false);

assert('7.3 Report channel translates QR_CODE to the citizen QR reading',
  computed(fixtureInstance, 'reportChannelLabel') === 'Lectura QR Ciudadana');
assert('7.4 Report channel translates LOCATION_PORTAL to the site portal',
  computed(createInstance({ detail: { incident: { report_channel: 'LOCATION_PORTAL' } } }), 'reportChannelLabel') === 'Portal de Sede');
assert('7.5 Unknown report channels fall back to the raw value',
  computed(createInstance({ detail: { incident: { report_channel: 'UNKNOWN_CHANNEL' } } }), 'reportChannelLabel') === 'UNKNOWN_CHANNEL');

assert('7.6 IncidentBadge is registered and reused for status and urgency',
  CoordinatorIncidentDetailModal.components.IncidentBadge?.name === 'IncidentBadge' &&
  template.includes('type="status"') && template.includes('type="urgency"') &&
  template.includes(':custom-label="incident.status_label"') &&
  template.includes(':custom-label="incident.urgency_label"'));

assert('7.7 Header renders the status/urgency badges and the reopening chip',
  template.includes('data-testid="incident-detail-status-badge"') &&
  template.includes('data-testid="incident-detail-urgency-badge"') &&
  template.includes('data-testid="incident-detail-reopened-chip"'));

assert('7.8 Reopened banner shows the mandatory client reason and the reopening date',
  template.includes('data-testid="reopened-banner"') &&
  template.includes('Reabierta en Garantía') &&
  template.includes('Motivo aportado por la sede:') &&
  template.includes('formatDateTime(incident.reopened_at)'));

assert('7.9 Location card exposes site, physical zone, address and reception',
  template.includes('data-testid="location-name"') &&
  template.includes('data-testid="location-zone"') &&
  template.includes('location.floor_zone') && template.includes('location.has_physical_reception'));

assert('7.10 Machine card shows code/model/type and the perishables sanitary indicator (Art. II)',
  template.includes('data-testid="machine-code"') &&
  template.includes('data-testid="machine-type"') &&
  template.includes('data-testid="machine-perishable"') &&
  template.includes('Alimentos Perecederos'));

assert('7.11 Description block shows the original report, channel and expandable evidence',
  template.includes('data-testid="incident-description"') &&
  template.includes('Canal de reporte:') &&
  template.includes('data-testid="incident-photo-thumb"') &&
  template.includes('data-testid="photo-zoom"') &&
  template.includes('@click="openPhotoZoom"'));

assert('7.12 Broken evidence swaps to the styled substitution box',
  template.includes('data-testid="incident-photo-fallback"') &&
  template.includes('Evidencia gráfica no disponible') &&
  template.includes('@error="handlePhotoError"'));

assert('7.13 Header still binds the status label from the server and not a client duplicate',
  !template.includes('vg-badge-critical') &&
  !template.includes("label: 'Crítica'"));

// ---------------------------------------------------------------------
// GROUP 8: Life-cycle timeline and derived times (T-IDM-09)
// ---------------------------------------------------------------------
console.log('\n--- Group 8: Timeline milestones and elapsed times ---');

const fixtureMilestones = computed(fixtureInstance, 'timelineMilestones');
assert('8.1 Milestones follow the life-cycle order with the reopening between resolution and closure',
  fixtureMilestones.map((m) => m.key).join(',') === 'created,assigned,started,paused,resolved,reopened,closed');

assert('8.2 Paused milestone disappears when the incident never paused',
  computed(createInstance({
    detail: { ...detailFixture, incident: { ...detailFixture.incident, is_reopened: false }, timeline: { ...detailFixture.timeline, paused_at: null } }
  }), 'timelineMilestones').map((m) => m.key).join(',') === 'created,assigned,started,resolved,closed');

assert('8.3 Reopening milestone disappears when the incident was not reopened',
  computed(createInstance({
    detail: { ...detailFixture, incident: { ...detailFixture.incident, is_reopened: false } }
  }), 'timelineMilestones').map((m) => m.key).includes('reopened') === false);

const reopenedMilestone = fixtureMilestones.find((m) => m.key === 'reopened');
assert('8.4 Reopening milestone carries the date and the client motive',
  reopenedMilestone.at === '2026-10-02 11:30:00' &&
  reopenedMilestone.detail.includes('volvió a fallar') && reopenedMilestone.done === true);

const pendingMilestones = computed(createInstance({
  detail: { ...detailFixture, incident: { ...detailFixture.incident, is_reopened: false }, timeline: { ...detailFixture.timeline, resolved_at: null, closed_at: null } }
}), 'timelineMilestones');
assert('8.5 Unreached milestones render as pending instead of disappearing',
  pendingMilestones.filter((m) => ['resolved', 'closed'].includes(m.key)).every((m) => m.at === null && m.done === false));

const fixtureMetrics = computed(fixtureInstance, 'timelineMetrics');
assert('8.6 Derived times render assignment, first response and total elapsed',
  fixtureMetrics.map((m) => `${m.key}:${m.value}`).join('|') === 'assign:15 min|response:55 min|total:27 h 45 min');

assert('8.7 Timeline and metric blocks are present in the template',
  template.includes('data-testid="incident-timeline"') &&
  template.includes('data-testid="timeline-metrics"') &&
  template.includes("formatDateTime(milestone.at)"));

assert('8.8 formatMinutes renders minutes and hours/minutes',
  CoordinatorIncidentDetailModal.methods.formatMinutes.call(fixtureInstance, 0) === '0 min' &&
  CoordinatorIncidentDetailModal.methods.formatMinutes.call(fixtureInstance, 59) === '59 min' &&
  CoordinatorIncidentDetailModal.methods.formatMinutes.call(fixtureInstance, 120) === '2 h 0 min');

assert('8.9 formatDateTime renders DD/MM/YYYY HH:MM without timezone drift',
  CoordinatorIncidentDetailModal.methods.formatDateTime.call(fixtureInstance, '2026-10-01 08:15:00') === '01/10/2026 08:15' &&
  CoordinatorIncidentDetailModal.methods.formatDateTime.call(fixtureInstance, null) === '');

// ---------------------------------------------------------------------
// GROUP 9: SLA monitor and integrated photo viewer (T-IDM-09)
// ---------------------------------------------------------------------
console.log('\n--- Group 9: SLA monitor and photo viewer lifecycle ---');

assert('9.1 SLA monitor only exists for perishable machines with a limit',
  computed(fixtureInstance, 'hasSla') === true &&
  computed(createInstance({ detail: { sla: { has_sla_limit: false } } }), 'hasSla') === false);

const breachedStyle = computed(fixtureInstance, 'slaMonitorStyle');
assert('9.2 Breached SLA paints the error tokens',
  breachedStyle.backgroundColor.includes('--color-error-bg') &&
  breachedStyle.border.includes('--color-error'));

const fulfilledStyle = computed(createInstance({
  detail: { sla: { has_sla_limit: true, is_breached: false } }
}), 'slaMonitorStyle');
assert('9.3 Fulfilled SLA paints the success tokens',
  fulfilledStyle.backgroundColor.includes('--color-success-bg') &&
  fulfilledStyle.border.includes('--color-success'));

assert('9.4 Monitor distinguishes active countdown from formal historical balance',
  template.includes('data-testid="sla-monitor"') &&
  template.includes("'Cuenta atrás activa'") && template.includes("'Balance histórico formal'") &&
  template.includes('sla.historical_balance'));

const viewerInstance = createInstance({ detail: detailFixture });
CoordinatorIncidentDetailModal.methods.openPhotoZoom.call(viewerInstance);
assert('9.5 Miniatura click opens the integrated viewer', viewerInstance.photoZoomOpen === true);

CoordinatorIncidentDetailModal.methods.closePhotoZoom.call(viewerInstance);
assert('9.6 closePhotoZoom hides the viewer', viewerInstance.photoZoomOpen === false);

const brokenViewerInstance = createInstance({ detail: detailFixture, photoFailed: true });
CoordinatorIncidentDetailModal.methods.openPhotoZoom.call(brokenViewerInstance);
assert('9.7 Viewer never opens over a broken image', brokenViewerInstance.photoZoomOpen === false);

const errorViewerInstance = createInstance({ detail: detailFixture, photoZoomOpen: true });
CoordinatorIncidentDetailModal.methods.handlePhotoError.call(errorViewerInstance);
assert('9.8 Broken image marks the fallback and closes the viewer',
  errorViewerInstance.photoFailed === true && errorViewerInstance.photoZoomOpen === false);

const noPhotoInstance = createInstance({ detail: { incident: { photo_url: null } } });
CoordinatorIncidentDetailModal.methods.openPhotoZoom.call(noPhotoInstance);
assert('9.9 Viewer stays closed when the incident has no evidence', noPhotoInstance.photoZoomOpen === false);

const escapeViewerInstance = createInstance({ detail: detailFixture, photoZoomOpen: true });
CoordinatorIncidentDetailModal.methods.handleKeyDown.call(escapeViewerInstance, { key: 'Escape' });
assert('9.10 Escape closes the viewer first and does not close the modal',
  escapeViewerInstance.photoZoomOpen === false && escapeViewerInstance.getEmitted().length === 0);

api.coordinator.getIncidentDetail = async () => ({ ...detailFixture });
const refreshInstance = createInstance({ incidentId: 142, photoZoomOpen: true, photoFailed: true });
await CoordinatorIncidentDetailModal.methods.fetchDetail.call(refreshInstance);
assert('9.11 Reloading the file resets the transient viewer state',
  refreshInstance.photoZoomOpen === false && refreshInstance.photoFailed === false);

// ---------------------------------------------------------------------
// T-IDM-10 fixtures: intervention with parts and masked refund
// ---------------------------------------------------------------------

const interventionFixture = {
  ...detailFixture,
  technical_intervention: {
    pause: {
      is_paused: true,
      reason: 'Fallo en condensador de arranque y relé térmico del compresor.',
      requested_parts: [
        { spare_part_id: 12, part_code: 'SP-FAS-RELAY-01', description: 'Relé Térmico Compresor 230V', quantity: 1, is_out_of_catalog: false, justification: null },
        { spare_part_id: null, part_code: 'OUT_OF_CATALOG', description: 'Abrazadera reforzada antivibración para circuito de cobre', quantity: 1, is_out_of_catalog: true, justification: 'Tubería de cobre suelta genera resonancia y fatiga de material en soporte.' }
      ]
    },
    resolution: {
      is_resolved: true,
      diagnosis: 'Condensador de arranque agotado y relé térmico disparado.',
      corrective_action: 'Sustitución del relé y rearme del circuito de frío.',
      replaced_parts_declared: true,
      replaced_parts: [
        { spare_part_id: 12, part_code: 'SP-FAS-RELAY-01', description: 'Relé Térmico Compresor 230V', quantity: 1, is_out_of_catalog: false, unit_cost_snapshot: 18.5, total_cost_snapshot: 18.5, old_part_destination: 'DESGUACE', destination_label: 'Desguace', notes: null },
        { spare_part_id: null, part_code: 'OUT_OF_CATALOG', description: 'Abrazadera reforzada antivibración', quantity: 2, is_out_of_catalog: true, unit_cost_snapshot: 2.25, total_cost_snapshot: 4.5, old_part_destination: 'TALLER', destination_label: 'Taller', notes: 'Recuperada del circuito antiguo.' }
      ],
      total_parts_cost: 23.0
    },
    cancellation: { is_cancelled: false, cancelled_at: null, cancelled_by_name: null, reason: null }
  },
  refund: {
    has_refund: true,
    refund_id: 5,
    claim_code: 'REF-2026-00005',
    amount: 2.5,
    compensation_method: 'BIZUM',
    compensation_method_label: 'Bizum',
    status: 'REQUIRES_COORDINATOR_APPROVAL',
    status_label: 'Pendiente de visto bueno',
    contact_phone_masked: '6** *** 789',
    iban_masked: 'ES** **** **** **** **12 3456',
    technician_finding: 'FOUND_PHYSICAL',
    cash_custody_action: 'HELD_FOR_CENTRAL',
    technician_notes: 'Moneda de 2 € y 0,50 € retenidas en el selector mecánico.',
    refund_tab_url: '#refunds?id=5'
  }
};

const interventionInstance = createInstance({ detail: interventionFixture });

// ---------------------------------------------------------------------
// GROUP 10: Technical intervention, pause, parts and discard (T-IDM-10)
// ---------------------------------------------------------------------
console.log('\n--- Group 10: Technical intervention and parts breakdown ---');

assert('10.1 Computed accessors surface technician, intervention, pause, resolution and cancellation',
  computed(interventionInstance, 'technician') === detailFixture.technician &&
  computed(interventionInstance, 'technicalIntervention') === interventionFixture.technical_intervention &&
  computed(interventionInstance, 'pause') === interventionFixture.technical_intervention.pause &&
  computed(interventionInstance, 'resolution') === interventionFixture.technical_intervention.resolution &&
  computed(interventionInstance, 'cancellation') === interventionFixture.technical_intervention.cancellation);

assert('10.2 Pause block renders reason, catalog parts and justified out-of-catalog parts',
  template.includes('data-testid="pause-block"') &&
  template.includes('data-testid="requested-part"') &&
  template.includes('data-testid="out-of-catalog-chip"') &&
  template.includes('{{ pause.reason }}') &&
  template.includes('part.justification'));

assert('10.3 Pause without registered parts falls back to an explicit notice',
  template.includes('Sin piezas registradas durante la pausa.'));

assert('10.4 Resolution block shows diagnosis and corrective action (RF-04.3)',
  template.includes('data-testid="resolution-block"') &&
  template.includes('Diagnóstico:') && template.includes('Acción correctiva:') &&
  template.includes('{{ resolution.diagnosis') && template.includes('{{ resolution.corrective_action'));

assert('10.5 Replaced parts carry frozen unit and total costs plus the destination label',
  template.includes('data-testid="replaced-part"') &&
  template.includes('{{ formatCurrency(part.unit_cost_snapshot) }}') &&
  template.includes('{{ formatCurrency(part.total_cost_snapshot) }}') &&
  template.includes('part.destination_label') &&
  template.includes('data-testid="total-parts-cost"'));

assert('10.6 hasReplacedParts distinguishes material substitution from a zero-cost fix',
  computed(interventionInstance, 'hasReplacedParts') === true &&
  computed(createInstance({
    detail: { ...interventionFixture, technical_intervention: { ...interventionFixture.technical_intervention, resolution: { ...interventionFixture.technical_intervention.resolution, replaced_parts: [], total_parts_cost: 0 } } }
  }), 'hasReplacedParts') === false &&
  template.includes('data-testid="no-replaced-parts"') &&
  template.includes('Sin sustitución de repuestos (intervención sin coste de material).'));

assert('10.7 Justified discard block (RF-04.4) renders date, authorizer and reason',
  template.includes('data-testid="cancellation-block"') &&
  template.includes('formatDateTime(cancellation.cancelled_at)') &&
  template.includes('cancellation.cancelled_by_name') && template.includes('cancellation.reason') &&
  template.includes('data-testid="intervention-empty"'));

assert('10.8 formatCurrency renders frozen amounts with two decimals',
  CoordinatorIncidentDetailModal.methods.formatCurrency.call(interventionInstance, 2.5) === '2.50 €' &&
  CoordinatorIncidentDetailModal.methods.formatCurrency.call(interventionInstance, 0) === '0.00 €' &&
  CoordinatorIncidentDetailModal.methods.formatCurrency.call(interventionInstance, null) === '—' &&
  CoordinatorIncidentDetailModal.methods.formatCurrency.call(interventionInstance, undefined) === '—');

assert('10.9 Technician summary and pending-assignment warning are both wired (RF-04.1)',
  template.includes('data-testid="technician-summary"') &&
  template.includes('data-testid="technician-pending"') &&
  template.includes('technician.reassignment_reason'));

// ---------------------------------------------------------------------
// GROUP 11: Masked refund case and privacy guard (T-IDM-10, RNF-05)
// ---------------------------------------------------------------------
console.log('\n--- Group 11: Masked refund case ---');

assert('11.1 hasRefund follows the refund block contract (RF-06.2)',
  computed(interventionInstance, 'hasRefund') === true &&
  computed(createInstance({ detail: { ...detailFixture, refund: { has_refund: false } } }), 'hasRefund') === false);

assert('11.2 Refund card shows amount, claim code, status and masked contact/payment data',
  template.includes('data-testid="refund-amount"') &&
  template.includes('{{ formatCurrency(refund.amount) }}') &&
  template.includes('refund.claim_code') && template.includes('refund.status_label') &&
  template.includes('data-testid="refund-phone"') && template.includes('refund.contact_phone_masked') &&
  template.includes('data-testid="refund-iban"') && template.includes('refund.iban_masked'));

assert('11.3 Refund card links to the coordinator Reintegros board',
  template.includes('data-testid="refund-link"') &&
  template.includes(':href="refund.refund_tab_url"') &&
  template.includes('Abrir expediente en la bandeja de Reintegros'));

assert('11.4 Privacy guard: the template never binds an unmasked IBAN or phone field',
  !/refund\.iban(?!_masked)/.test(template) &&
  !/refund\.contact_phone(?!_masked)/.test(template) &&
  !template.includes('bizum'));

assert('11.5 Inspector verdict and custody labels are translated for the coordinator',
  CoordinatorIncidentDetailModal.computed.refundFindingLabel.call(interventionInstance) === 'Efectivo encontrado físicamente' &&
  CoordinatorIncidentDetailModal.computed.refundCustodyLabel.call(interventionInstance) === 'Custodiado para caja central' &&
  CoordinatorIncidentDetailModal.computed.refundFindingLabel.call(createInstance({ detail: { refund: { technician_finding: 'CONFIRMED_NO_CASH' } } })) === 'Confirmado sin efectivo');

assert('11.6 Refund section is conditional on the refund case existing',
  template.includes('v-if="hasRefund"') &&
  template.includes('refund.technician_notes'));

// ---------------------------------------------------------------------
// T-IDM-11 fixtures: chronological log with a public and an internal note
// ---------------------------------------------------------------------

const commentsFixture = {
  ...detailFixture,
  comments: [
    { id: 85, author_type: 'REPORTER', author_name: 'Conserjería Hospital', comment_text: 'El agua gotea por debajo de la máquina.', is_internal: false, created_at: '2026-10-01 08:20:00' },
    { id: 86, author_type: 'TECHNICIAN', author_name: 'Jordi Cruz', comment_text: 'Comprobada fuga en bandeja de desescarche. Pauso aviso esperando recambio.', is_internal: true, created_at: '2026-10-01 09:46:00' }
  ]
};

const commentsInstance = createInstance({ detail: commentsFixture });

// ---------------------------------------------------------------------
// GROUP 12: Comment log rendering (T-IDM-11)
// ---------------------------------------------------------------------
console.log('\n--- Group 12: Comment log with public and internal notes ---');

assert('12.1 Comments accessor returns the log array or an empty list',
  computed(commentsInstance, 'comments') === commentsFixture.comments &&
  computed(createInstance({ detail: {} }), 'comments').length === 0);

assert('12.2 The inline form only appears when the state machine allows commenting (RF-07.2)',
  computed(commentsInstance, 'canAddComment') === true &&
  computed(createInstance({ detail: { ...detailFixture, permissions: { ...detailFixture.permissions, can_add_comment: false } } }), 'canAddComment') === false &&
  template.includes('v-if="canAddComment"'));

const commentsView = computed(commentsInstance, 'commentsView');
assert('12.3 Log entries translate the author type and format the timestamp',
  commentsView[0].author_label === 'Responsable de Sede' &&
  commentsView[1].author_label === 'Técnico de Campo' &&
  commentsView[0].created_at_label === '01/10/2026 08:20' &&
  computed(createInstance({ detail: { comments: [{ author_type: 'UNKNOWN', comment_text: 'x', created_at: 'n/a' }] } }), 'commentsView')[0].author_label === 'UNKNOWN');

assert('12.4 Log lives in its own bounded scroll container (case limit 8)',
  template.includes('data-testid="comments-log"') &&
  template.includes('max-height: 280px; overflow-y: auto') &&
  template.includes('ref="commentsLog"'));

assert('12.5 Internal notes are visually separated from public comments (RF-05.2)',
  template.includes('data-testid="comment-internal-chip"') &&
  template.includes('🔒 Nota interna de taller') &&
  template.includes("comment.is_internal ? 'var(--color-warning-bg, #fef8e7)'") &&
  template.includes('{{ comment.author_label }}'));

assert('12.6 Empty log falls back to an explicit notice',
  template.includes('data-testid="comments-empty"') &&
  template.includes('Sin comentarios registrados todavía.'));

let scrollCalls = 0;
const scrollInstance = createInstance({ detail: commentsFixture });
scrollInstance.scrollCommentsToLatest = () => { scrollCalls++; };
api.coordinator.getIncidentDetail = async () => commentsFixture;
await CoordinatorIncidentDetailModal.methods.fetchDetail.call(scrollInstance);
assert('12.7 Refreshing the file keeps the latest comments visible',
  scrollCalls === 1 &&
  CoordinatorIncidentDetailModal.methods.scrollCommentsToLatest.call(createInstance()) === undefined);

// ---------------------------------------------------------------------
// GROUP 13: Inline comment form (T-IDM-11)
// ---------------------------------------------------------------------
console.log('\n--- Group 13: Inline comment form and POST wiring ---');

assert('13.1 Form scaffold binds input, internal toggle and submit button',
  template.includes('data-testid="comment-form"') &&
  template.includes('v-model="newCommentText"') &&
  template.includes('data-testid="comment-internal-toggle"') &&
  template.includes('v-model="isCommentInternal"') &&
  template.includes('Nota interna de taller (confidencial)') &&
  template.includes('data-testid="comment-submit"') &&
  template.includes('newCommentText.trim().length < 5'));

const submittedCalls = [];
let refreshCalls = 0;
api.coordinator.addComment = async (incidentId, text, isInternal) => {
  submittedCalls.push({ incidentId, text, isInternal });
  return { id: 87, comment_text: text, is_internal: isInternal };
};

const submitInstance = createInstance({
  incidentId: 3661,
  detail: commentsFixture,
  newCommentText: '  Revisado el compresor en taller; recambio en camino.  ',
  isCommentInternal: true
});
submitInstance.fetchDetail = async () => { refreshCalls++; };

await CoordinatorIncidentDetailModal.methods.submitComment.call(submitInstance);

assert('13.2 Form submits trimmed text and the visibility flag to POST .../comments',
  submittedCalls.length === 1 &&
  submittedCalls[0].incidentId === 3661 &&
  submittedCalls[0].text === 'Revisado el compresor en taller; recambio en camino.' &&
  submittedCalls[0].isInternal === true);

assert('13.3 Successful send clears the draft, refreshes the log and notifies the parent',
  submitInstance.newCommentText === '' && submitInstance.isCommentInternal === false &&
  refreshCalls === 1 &&
  submitInstance.getEmitted().some((e) => e.event === 'incident-updated' && e.payload?.reason === 'comment') &&
  submitInstance.isSubmittingComment === false && submitInstance.commentErrorMessage === '');

const shortInstance = createInstance({ incidentId: 3661, newCommentText: '  ab  ' });
const callsBeforeShort = submittedCalls.length;
await CoordinatorIncidentDetailModal.methods.submitComment.call(shortInstance);
assert('13.4 Drafts under 5 real characters never reach the server',
  submittedCalls.length === callsBeforeShort &&
  shortInstance.commentErrorMessage.includes('5 caracteres') &&
  shortInstance.newCommentText === '  ab  ');

api.coordinator.addComment = async () => {
  throw new ApiError(
    403,
    'CONVERSATION_SEALED',
    'El expediente está archivado: la conversación quedó sellada por auditoría y no admite nuevos mensajes.'
  );
};
const failedInstance = createInstance({
  incidentId: 3661,
  detail: commentsFixture,
  newCommentText: 'Nota que debe sobrevivir al fallo de red.'
});
failedInstance.fetchDetail = async () => { throw new Error('no debe refrescarse'); };
await CoordinatorIncidentDetailModal.methods.submitComment.call(failedInstance);

assert('13.5 A rejected comment keeps the draft intact for an immediate retry (RF-08.4)',
  failedInstance.newCommentText === 'Nota que debe sobrevivir al fallo de red.' &&
  failedInstance.commentErrorMessage.includes('quedó sellada') &&
  failedInstance.isSubmittingComment === false &&
  failedInstance.getEmitted().some((e) => e.event === 'close') === false);

assert('13.6 Sealed states replace the form with a consultation notice',
  template.includes('data-testid="comments-sealed"') &&
  template.includes('La bitácora está sellada para este estado'));

// ---------------------------------------------------------------------
// GROUP 14: Inline panels scaffolding and lifecycle (T-IDM-12)
// ---------------------------------------------------------------------
console.log('\n--- Group 14: Inline assignment and discard panels ---');

const panelInstance = createInstance({ detail: detailFixture, incidentId: 142 });

assert('14.1 Panels ship closed and live inside the scrollable body, not as nested modals',
  panelInstance.isAssignPanelOpen === false && panelInstance.isCancelPanelOpen === false &&
  template.includes('data-testid="assign-panel"') && template.includes('data-testid="cancel-panel"') &&
  template.indexOf('data-testid="incident-detail-body"') < template.indexOf('data-testid="assign-panel"') &&
  template.indexOf('data-testid="assign-panel"') < template.indexOf('data-testid="incident-detail-footer"') &&
  (template.match(/role="dialog"/g) || []).length === 1);

assert('14.2 Fixed footer carries the two operational triggers, gated by permissions (RF-07.1)',
  template.includes('data-testid="incident-detail-assign-trigger"') &&
  template.includes('v-if="canAssign && !isAssignPanelOpen"') &&
  template.includes('data-testid="incident-detail-cancel-trigger"') &&
  template.includes('v-if="canCancel && !isCancelPanelOpen"') &&
  computed(panelInstance, 'canAssign') === true && computed(panelInstance, 'canCancel') === true);

assert('14.3 Operational actions always target the numeric incident id',
  computed(panelInstance, 'actionableIncidentId') === 142 &&
  computed(createInstance({ detail: detailFixture, incidentId: 'INC-DEMO-0922' }), 'actionableIncidentId') === 142 &&
  computed(createInstance({ detail: null, incidentId: 99 }), 'actionableIncidentId') === 99);

let loadCalls = 0;
const toggleInstance = createInstance({ detail: detailFixture });
toggleInstance.loadTechnicians = () => { loadCalls++; };
CoordinatorIncidentDetailModal.methods.openAssignPanel.call(toggleInstance);
assert('14.4 Opening the assignment panel loads technicians once and closes the discard panel',
  toggleInstance.isAssignPanelOpen === true && toggleInstance.isCancelPanelOpen === false && loadCalls === 1);

toggleInstance.techniciansLoaded = true;
CoordinatorIncidentDetailModal.methods.closeAssignPanel.call(toggleInstance);
CoordinatorIncidentDetailModal.methods.openAssignPanel.call(toggleInstance);
assert('14.5 Cached technicians are not re-fetched on every panel open',
  toggleInstance.isAssignPanelOpen === true && loadCalls === 1);

CoordinatorIncidentDetailModal.methods.openCancelPanel.call(toggleInstance);
assert('14.6 Opening the discard panel closes the assignment panel (single inline panel)',
  toggleInstance.isCancelPanelOpen === true && toggleInstance.isAssignPanelOpen === false);

assert('14.7 Reactive counter counts real trimmed characters (Art. V.1)',
  computed(createInstance({ cancelReason: '   ' }), 'cancelReasonLength') === 0 &&
  computed(createInstance({ cancelReason: 'a'.repeat(19) }), 'isCancelReasonValid') === false &&
  computed(createInstance({ cancelReason: 'a'.repeat(20) }), 'isCancelReasonValid') === true &&
  computed(createInstance({ cancelReason: '  motivo suficientemente largo  ' }), 'isCancelReasonValid') === true &&
  computed(createInstance({ cancelReason: ' ' + 'á'.repeat(20) + ' ' }), 'cancelReasonLength') === 20);

assert('14.8 Discard panel wires the counter and the guarded confirmation button',
  template.includes('data-testid="cancel-reason"') &&
  template.includes('data-testid="cancel-counter"') && template.includes('/ 20 caracteres') &&
  template.includes(':disabled="!isCancelReasonValid || isSubmittingCancel"'));

assert('14.9 Reassignment trigger and its mandatory motive field are rendered (RF-07.3, T-IDM-21)',
  template.includes('data-testid="incident-detail-reassign-trigger"') &&
  template.includes('v-if="canReassign && !isAssignPanelOpen"') &&
  template.includes('data-testid="assign-reason"') &&
  typeof CoordinatorIncidentDetailModal.methods.confirmReassign !== 'function');

// ---------------------------------------------------------------------
// GROUP 15: Panel actions against the real endpoints (T-IDM-12)
// ---------------------------------------------------------------------
console.log('\n--- Group 15: Assignment and discard requests ---');

const techniciansPayload = [
  { id: 2, name: 'Jordi Técnico Ruta BCN', role: 'TECHNICIAN', is_active: true, active_assigned_incidents_count: 3 },
  { id: 3, name: 'Marta Técnica Ruta BCN', role: 'TECHNICIAN', is_active: true, active_assigned_incidents_count: 0 }
];
let usersQuery = null;
api.coordinator.getUsers = async (params) => { usersQuery = params; return techniciansPayload; };

const loadInstance = createInstance({ detail: detailFixture });
await CoordinatorIncidentDetailModal.methods.loadTechnicians.call(loadInstance);
assert('15.1 Active technicians come from the users endpoint, never a hardcoded list',
  usersQuery?.role === 'TECHNICIAN' && usersQuery?.status === 'active' &&
  loadInstance.technicians.length === 2 && loadInstance.selectedTechnicianId === 2 &&
  loadInstance.techniciansLoaded === true && loadInstance.isLoadingTechnicians === false);

api.coordinator.getUsers = async () => { throw new ApiError(403, 'FORBIDDEN', 'Acceso denegado a la lista de usuarios.'); };
const failedLoad = createInstance({ detail: detailFixture });
await CoordinatorIncidentDetailModal.methods.loadTechnicians.call(failedLoad);
assert('15.2 A failed technician load surfaces the reason and clears the spinner',
  failedLoad.techniciansErrorMessage.includes('Acceso denegado') && failedLoad.isLoadingTechnicians === false);

let assignArgs = null;
let assignRefreshes = 0;
api.coordinator.assignTechnician = async (incidentId, technicianId, urgency, urgencyReason, reassignmentReason) => {
  assignArgs = { incidentId, technicianId, urgency, urgencyReason, reassignmentReason };
  return { id: incidentId, status: 'ASSIGNED' };
};
const assignInstance = createInstance({
  detail: assignFixture,
  incidentId: 142,
  isAssignPanelOpen: true,
  selectedTechnicianId: 3
});
assignInstance.fetchDetail = async () => { assignRefreshes++; };
await CoordinatorIncidentDetailModal.methods.confirmAssign.call(assignInstance);

assert('15.3 Confirmed assignment refreshes the file and notifies the parent row',
  assignArgs?.incidentId === 142 && assignArgs?.technicianId === 3 &&
  assignArgs?.reassignmentReason === null &&
  assignInstance.isAssignPanelOpen === false && assignInstance.selectedTechnicianId === null &&
  assignRefreshes === 1 &&
  assignInstance.getEmitted().some((e) => e.event === 'incident-updated' && e.payload?.reason === 'assign') &&
  assignInstance.isSubmittingAssign === false && assignInstance.assignErrorMessage === '');

const assignByCode = createInstance({ detail: assignFixture, incidentId: 'INC-DEMO-0922', selectedTechnicianId: 2 });
assignByCode.fetchDetail = async () => {};
await CoordinatorIncidentDetailModal.methods.confirmAssign.call(assignByCode);
assert('15.4 Assignment opened by ticket code still targets the numeric endpoint id',
  assignArgs?.incidentId === 142 && assignArgs?.technicianId === 2);

api.coordinator.assignTechnician = async () => {
  throw new ApiError(422, 'INVALID_STATUS_FOR_ASSIGNMENT', 'Solo se pueden asignar incidencias en estado REGISTERED o REOPENED.');
};
const failedAssign = createInstance({ detail: assignFixture, incidentId: 142, isAssignPanelOpen: true, selectedTechnicianId: 2 });
await CoordinatorIncidentDetailModal.methods.confirmAssign.call(failedAssign);
assert('15.5 Failed assignment keeps the panel open with the API message',
  failedAssign.isAssignPanelOpen === true &&
  failedAssign.assignErrorMessage.includes('REGISTERED o REOPENED') &&
  failedAssign.isSubmittingAssign === false);

let cancelArgs = null;
let cancelRefreshes = 0;
api.coordinator.cancelIncident = async (incidentId, reason) => {
  cancelArgs = { incidentId, reason };
  return { id: incidentId, status: 'CANCELLED' };
};

const blockedCancel = createInstance({ detail: detailFixture, incidentId: 142, isCancelPanelOpen: true, cancelReason: 'motivo corto' });
await CoordinatorIncidentDetailModal.methods.confirmCancel.call(blockedCancel);
assert('15.6 A short reason never reaches the server and keeps the draft',
  cancelArgs === null && blockedCancel.cancelErrorMessage.includes('20 caracteres') &&
  blockedCancel.cancelReason === 'motivo corto' && blockedCancel.isCancelPanelOpen === true);

const cancelInstance = createInstance({
  detail: detailFixture,
  incidentId: 142,
  isCancelPanelOpen: true,
  cancelReason: '  Avería duplicada confirmada telefónicamente con la sede; ya atendida en otro ticket.  '
});
cancelInstance.fetchDetail = async () => { cancelRefreshes++; };
await CoordinatorIncidentDetailModal.methods.confirmCancel.call(cancelInstance);

assert('15.7 Confirmed discard sends the trimmed reason, refreshes and notifies',
  cancelArgs?.incidentId === 142 &&
  cancelArgs?.reason === 'Avería duplicada confirmada telefónicamente con la sede; ya atendida en otro ticket.' &&
  cancelInstance.cancelReason === '' && cancelInstance.isCancelPanelOpen === false &&
  cancelRefreshes === 1 &&
  cancelInstance.getEmitted().some((e) => e.event === 'incident-updated' && e.payload?.reason === 'cancel') &&
  cancelInstance.isSubmittingCancel === false);

api.coordinator.cancelIncident = async () => {
  throw new ApiError(500, 'CANCELLATION_FAILED', 'No se pudo ejecutar el descarte lógico.');
};
const failedCancel = createInstance({
  detail: detailFixture,
  incidentId: 142,
  isCancelPanelOpen: true,
  cancelReason: 'Motivo perfectamente válido para reintentar el descarte.'
});
await CoordinatorIncidentDetailModal.methods.confirmCancel.call(failedCancel);
assert('15.8 Failed discard keeps the panel open and the reason intact for a retry',
  failedCancel.isCancelPanelOpen === true &&
  failedCancel.cancelReason === 'Motivo perfectamente válido para reintentar el descarte.' &&
  failedCancel.cancelErrorMessage.includes('descarte lógico') &&
  failedCancel.isSubmittingCancel === false);

// ---------------------------------------------------------------------
// GROUP 16: Reassignment with mandatory motive (T-IDM-21 / RF-07.3)
// ---------------------------------------------------------------------
console.log('\n--- Group 16: Reassignment with mandatory motive ---');

const reassignPanel = createInstance({ detail: reassignFixture, incidentId: 142 });
assert('16.1 Reassignment is offered only while the ticket has a responsible technician',
  computed(reassignPanel, 'canReassign') === true && computed(reassignPanel, 'isReassign') === true &&
  computed(createInstance({ detail: assignFixture, incidentId: 142 }), 'canReassign') === false &&
  computed(createInstance({ detail: assignFixture, incidentId: 142 }), 'isReassign') === false);

const techniciansList = [
  { id: 4, name: 'Jordi Técnico Ruta BCN', active_assigned_incidents_count: 3 },
  { id: 7, name: 'Marta Técnica Ruta BCN', active_assigned_incidents_count: 1 }
];
const optionsInstance = createInstance({ detail: reassignFixture, technicians: techniciansList });
const openOptions = createInstance({
  detail: { ...reassignFixture, technician: { ...reassignFixture.technician, technician_id: null } },
  technicians: techniciansList
});
assert('16.2 The current responsible never appears as reassignment target (Art. V.3)',
  computed(optionsInstance, 'reassignableTechnicians').length === 1 &&
  computed(optionsInstance, 'reassignableTechnicians')[0].id === 7 &&
  computed(openOptions, 'reassignableTechnicians').length === 2);

assert('16.3 Reactive counter measures real characters with a 10-character threshold (RF-07.3)',
  computed(createInstance({ detail: reassignFixture, reassignReason: '   ' }), 'reassignReasonLength') === 0 &&
  computed(createInstance({ detail: reassignFixture, reassignReason: 'a'.repeat(9) }), 'isReassignReasonValid') === false &&
  computed(createInstance({ detail: reassignFixture, reassignReason: 'a'.repeat(10) }), 'isReassignReasonValid') === true &&
  computed(createInstance({ detail: reassignFixture, reassignReason: ' ' + 'á'.repeat(10) + ' ' }), 'reassignReasonLength') === 10 &&
  computed(createInstance({ detail: assignFixture, reassignReason: '' }), 'isReassignReasonValid') === true);

assert('16.4 Panel copy, filtered selector, counter and guarded confirmation are wired',
  template.includes("isReassign ? '🔁 Reasignar Técnico' : '👷 Asignar Técnico'") &&
  template.includes("isReassign ? 'Nuevo técnico responsable' : 'Técnico de ruta activo'") &&
  template.includes('v-for="technician in reassignableTechnicians"') &&
  template.includes('data-testid="assign-reason"') &&
  template.includes('data-testid="assign-reason-counter"') &&
  template.includes('/ 10 caracteres') &&
  template.includes(':disabled="isSubmittingAssign || !selectedTechnicianId || !isReassignReasonValid"'));

let reassignArgs = null;
let reassignRefreshes = 0;
api.coordinator.assignTechnician = async (incidentId, technicianId, urgency, urgencyReason, reassignmentReason) => {
  reassignArgs = { incidentId, technicianId, reassignmentReason };
  return { id: incidentId, status: 'PENDING_PARTS' };
};

const shortReassign = createInstance({
  detail: reassignFixture,
  incidentId: 142,
  isAssignPanelOpen: true,
  selectedTechnicianId: 7,
  reassignReason: 'Cobertura',
  isSubmittingAssign: false
});
await CoordinatorIncidentDetailModal.methods.confirmAssign.call(shortReassign);
assert('16.5 A short motive never reaches the server and keeps the draft with a clear message',
  reassignArgs === null &&
  shortReassign.assignErrorMessage.includes('10 caracteres') &&
  shortReassign.reassignReason === 'Cobertura' &&
  shortReassign.isAssignPanelOpen === true &&
  shortReassign.isSubmittingAssign === false);

const reassignInstance = createInstance({
  detail: reassignFixture,
  incidentId: 142,
  isAssignPanelOpen: true,
  selectedTechnicianId: 7,
  reassignReason: '  Cobertura inmediata por rotura de la cadena de frío en urgencias.  '
});
reassignInstance.fetchDetail = async () => { reassignRefreshes++; };
await CoordinatorIncidentDetailModal.methods.confirmAssign.call(reassignInstance);
assert('16.6 Confirmed reassignment sends the trimmed motive, refreshes and clears the draft',
  reassignArgs?.incidentId === 142 && reassignArgs?.technicianId === 7 &&
  reassignArgs?.reassignmentReason === 'Cobertura inmediata por rotura de la cadena de frío en urgencias.' &&
  reassignInstance.reassignReason === '' && reassignInstance.isAssignPanelOpen === false &&
  reassignInstance.selectedTechnicianId === null && reassignRefreshes === 1 &&
  reassignInstance.getEmitted().some((e) => e.event === 'incident-updated' && e.payload?.reason === 'assign') &&
  reassignInstance.isSubmittingAssign === false);

api.coordinator.assignTechnician = async () => {
  throw new ApiError(422, 'TECHNICIAN_ALREADY_ASSIGNED', 'El técnico indicado ya es el responsable activo de esta incidencia.');
};
const failedReassign = createInstance({
  detail: reassignFixture,
  incidentId: 142,
  isAssignPanelOpen: true,
  selectedTechnicianId: 7,
  reassignReason: 'Motivo válido para reasignar y reintentar el envío.'
});
await CoordinatorIncidentDetailModal.methods.confirmAssign.call(failedReassign);
assert('16.7 A rejected reassignment keeps the panel open with the motive intact for a retry',
  failedReassign.isAssignPanelOpen === true &&
  failedReassign.reassignReason === 'Motivo válido para reasignar y reintentar el envío.' &&
  failedReassign.assignErrorMessage.includes('responsable activo') &&
  failedReassign.isSubmittingAssign === false);

api.coordinator.getUsers = async () => techniciansList;
const preselectInstance = createInstance({ detail: reassignFixture });
await CoordinatorIncidentDetailModal.methods.loadTechnicians.call(preselectInstance);
assert('16.8 Loading technicians for a reassignment preselects a different professional',
  preselectInstance.technicians.length === 2 && preselectInstance.selectedTechnicianId === 7 &&
  preselectInstance.techniciansLoaded === true);

const stalePanel = createInstance({ detail: reassignFixture, techniciansLoaded: true, selectedTechnicianId: 4 });
CoordinatorIncidentDetailModal.methods.openAssignPanel.call(stalePanel);
assert('16.9 Opening the panel drops a stale selection of the current responsible',
  stalePanel.isAssignPanelOpen === true && stalePanel.selectedTechnicianId === null &&
  stalePanel.reassignReason === '' && stalePanel.isCancelPanelOpen === false);

CoordinatorIncidentDetailModal.methods.closeAssignPanel.call(stalePanel);
assert('16.10 Closing the panel discards the motive draft',
  stalePanel.isAssignPanelOpen === false && stalePanel.reassignReason === '' && stalePanel.assignErrorMessage === '');

// ---------------------------------------------------------------------
// GROUP 17: Dirty state guard on the close lifecycle (T-IDM-13 / RF-08)
// ---------------------------------------------------------------------
console.log('\n--- Group 17: Dirty state guard and close lifecycle ---');

assert('17.1 A clean modal reports no drafts, even when a closed panel still holds text',
  computed(createInstance({}), 'isFormDirty') === false &&
  computed(createInstance({}), 'hasUnsavedComment') === false &&
  computed(createInstance({ cancelReason: 'borrador huérfano' }), 'hasUnsavedCancelReason') === false &&
  computed(createInstance({ reassignReason: 'borrador huérfano' }), 'hasUnsavedAssignReason') === false);

assert('17.2 A comment draft marks the form dirty regardless of the panels (RF-08.1)',
  computed(createInstance({ newCommentText: '   ' }), 'hasUnsavedComment') === false &&
  computed(createInstance({ newCommentText: 'Nota a medio escribir' }), 'isFormDirty') === true);

assert('17.3 Discard and reassignment drafts count only inside their open panel (plan §3.3)',
  computed(createInstance({ isCancelPanelOpen: true, cancelReason: 'Motivo a medias' }), 'isFormDirty') === true &&
  computed(createInstance({ isAssignPanelOpen: true, detail: reassignFixture, reassignReason: 'Motivo a medias' }), 'isFormDirty') === true &&
  computed(createInstance({ isAssignPanelOpen: true, detail: assignFixture, reassignReason: 'Motivo a medias' }), 'isFormDirty') === false);

const originalConfirm = globalThis.confirm;
let confirmMessages = [];
globalThis.confirm = (message) => { confirmMessages.push(String(message)); return true; };

const cleanClose = createInstance({ isOpen: true, newCommentText: '', cancelReason: '', reassignReason: '' });
CoordinatorIncidentDetailModal.methods.requestClose.call(cleanClose);
assert('17.4 Clean forms close immediately without asking (RF-08.3, plan §3.3)',
  confirmMessages.length === 0 &&
  cleanClose.getEmitted().some((e) => e.event === 'close'));

confirmMessages = [];
const dirtyClose = createInstance({
  isOpen: true,
  newCommentText: 'Comentario a medio redactar',
  isCommentInternal: true,
  isCancelPanelOpen: true,
  cancelReason: 'Motivo de descarte a medio redactar',
  cancelErrorMessage: 'error previo'
});
CoordinatorIncidentDetailModal.methods.requestClose.call(dirtyClose);
assert('17.5 A confirmed discard asks exactly once, closes and resets every draft (RF-08.2)',
  confirmMessages.length === 1 && confirmMessages[0].includes('¿Descartar cambios sin guardar?') &&
  dirtyClose.getEmitted().some((e) => e.event === 'close') &&
  dirtyClose.newCommentText === '' && dirtyClose.isCommentInternal === false &&
  dirtyClose.cancelReason === '' && dirtyClose.isCancelPanelOpen === false &&
  dirtyClose.cancelErrorMessage === '' && computed(dirtyClose, 'isFormDirty') === false);

confirmMessages = [];
globalThis.confirm = (message) => { confirmMessages.push(String(message)); return false; };
const keptOpen = createInstance({
  isOpen: true,
  newCommentText: '  Nota intacta  ',
  isAssignPanelOpen: true,
  detail: reassignFixture,
  reassignReason: 'Motivo de reasignación intacto'
});
CoordinatorIncidentDetailModal.methods.requestClose.call(keptOpen);
assert('17.6 Cancelling the confirmation keeps the modal open with the texts intact (RF-08.2)',
  confirmMessages.length === 1 &&
  keptOpen.getEmitted().some((e) => e.event === 'close') === false &&
  keptOpen.newCommentText === '  Nota intacta  ' &&
  keptOpen.reassignReason === 'Motivo de reasignación intacto' &&
  keptOpen.isAssignPanelOpen === true && computed(keptOpen, 'isFormDirty') === true);

confirmMessages = [];
const escapeInstance = createInstance({ isOpen: true, isCancelPanelOpen: true, cancelReason: 'Motivo en curso' });
CoordinatorIncidentDetailModal.methods.handleKeyDown.call(escapeInstance, { key: 'Escape' });
CoordinatorIncidentDetailModal.methods.handleBackdropClick.call(escapeInstance, { target: 'backdrop', currentTarget: 'backdrop' });
assert('17.7 Escape and the shaded backdrop share the very same guarded signal (RF-08.1)',
  confirmMessages.length === 2 &&
  escapeInstance.getEmitted().some((e) => e.event === 'close') === false &&
  escapeInstance.cancelReason === 'Motivo en curso' &&
  escapeInstance.isCancelPanelOpen === true);

const zoomInstance = createInstance({ isOpen: true, photoZoomOpen: true, newCommentText: 'borrador en curso' });
confirmMessages = [];
CoordinatorIncidentDetailModal.methods.handleKeyDown.call(zoomInstance, { key: 'Escape' });
assert('17.8 Escape closes the evidence viewer first without touching the drafts (RF-02.4)',
  zoomInstance.photoZoomOpen === false && confirmMessages.length === 0 &&
  zoomInstance.newCommentText === 'borrador en curso' &&
  zoomInstance.getEmitted().some((e) => e.event === 'close') === false);

globalThis.confirm = originalConfirm;

assert('17.9 Header, footer and backdrop close signals all route through the guarded method',
  (template.match(/@click="requestClose"/g) || []).length >= 2 &&
  template.includes('@click.self="handleBackdropClick"') &&
  typeof CoordinatorIncidentDetailModal.methods.confirmDiscardChanges === 'function' &&
  typeof CoordinatorIncidentDetailModal.methods.resetDrafts === 'function');

// ---------------------------------------------------------------------
// GROUP 18: Terminal states seal the operational actions (RF-07.2 / T-IDM-16)
// ---------------------------------------------------------------------
console.log('\n--- Group 18: Terminal states seal the operational actions (T-IDM-16) ---');

// RF-07.2: RESOLVED, CLOSED and CANCELLED files switch the modal to consultation and
// immutable-audit mode: the assign, reassign and discard actions vanish from the footer
// and the log is sealed. The backend remains the fail-fast last line (group 15.5 and
// the T-IDM-21 integration suite cover the 422 rejections).
const TERMINAL_CASES = [
  { number: '18.1', status: 'RESOLVED', label: 'Resuelta' },
  { number: '18.2', status: 'CLOSED', label: 'Cerrada' },
  { number: '18.3', status: 'CANCELLED', label: 'Cancelada' }
];
for (const terminal of TERMINAL_CASES) {
  const sealedDetail = {
    ...detailFixture,
    incident: { ...detailFixture.incident, status: terminal.status, status_label: terminal.label },
    permissions: { can_assign: false, can_reassign: false, can_cancel: false, can_add_comment: false }
  };
  const sealedInstance = createInstance({ detail: sealedDetail });
  assert(`${terminal.number} A ${terminal.status} file switches to consultation mode: no assign, reassign, discard or comment form (RF-07.2)`,
    computed(sealedInstance, 'canAssign') === false &&
    computed(sealedInstance, 'canReassign') === false &&
    computed(sealedInstance, 'canCancel') === false &&
    computed(sealedInstance, 'canAddComment') === false);
}

assert('18.4 The footer triggers are permission-gated, so terminal files render zero operational actions (RF-07.2)',
  template.includes('v-if="canAssign && !isAssignPanelOpen"') &&
  template.includes('v-if="canCancel && !isCancelPanelOpen"') &&
  template.includes('v-if="canReassign && !isAssignPanelOpen"'));

assert('18.5 The comment form is gated by canAddComment and falls back to the sealed consultation notice (RF-07.2)',
  template.includes('v-if="canAddComment"') &&
  template.includes('v-else data-testid="comments-sealed"'));

assert('18.6 No operational entry point escapes the permission gates (consultation mode is total)',
  (template.match(/@click="openAssignPanel"/g) || []).length === 2 &&
  (template.match(/@click="openCancelPanel"/g) || []).length === 1);

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITIONS T-IDM-08..T-IDM-13, T-IDM-16 AND T-IDM-21 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
