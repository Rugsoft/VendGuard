/**
 * VendGuard - CoordinatorPreventiveOrderDetailModal Test Suite
 * (CoordinatorPreventiveOrderDetailModalTest.mjs)
 *
 * Task: T-PREV-29 / T-PREV-30 / T-PREV-31
 * Requirements: RF-PD-01 to RF-PD-10, RNF-PD-01 to RNF-PD-07.
 *
 * Verifies:
 * 1. Component contract: name, props, emits and template testids of the nine blocks.
 * 2. Exactly ONE close glyph in the header (shared `.btn-close` ::before convention).
 * 3. Strictly read-only: no assignment, cancellation or inspection controls inside.
 * 4. Computed projections: header code, badges, validity countdown and checklist items.
 * 5. Lifecycle: close signals, integrated evidence viewer and Escape precedence.
 * 6. Data loading: one aggregated request, error retention and retry.
 * 7. Linked incident jump: closes the ficha and emits the corrective incident id.
 * 8. Shared label util, orders tray trigger and dashboard view wiring.
 *
 * Dogma Vanilla: Pure JavaScript ESM (Node.js).
 * Dualismo Lingüístico: Test in English; UI assertions in Spanish.
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

import { api } from '../../public/assets/js/api.js';
import {
  CoordinatorPreventiveOrderDetailModal,
  CoordinatorPreventiveOrderDetailModal as DefaultExport
} from '../../public/assets/js/components/CoordinatorPreventiveOrderDetailModal.js';
import { CoordinatorPreventiveOrdersTab } from '../../public/assets/js/components/CoordinatorPreventiveOrdersTab.js';
import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';
import {
  getChecklistItemBadge,
  getOrderStatusBadge,
  getOrderTypeLabel,
  getValidityBadge
} from '../../public/assets/js/utils/PreventiveLabels.js';

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
    orderId: 1,
    detail: null,
    isLoading: false,
    errorMessage: '',
    photoZoomItem: null,
    failedPhotoCodes: [],
    ...overrides,
    $emit: (event, payload) => { emitted.push({ event, payload }); },
    getEmitted: () => emitted
  };

  for (const [methodName, method] of Object.entries(CoordinatorPreventiveOrderDetailModal.methods)) {
    instance[methodName] = method.bind(instance);
  }

  for (const [name, handler] of Object.entries(CoordinatorPreventiveOrderDetailModal.computed)) {
    Object.defineProperty(instance, name, { get: handler.bind(instance), configurable: true });
  }

  for (const [name, initial] of Object.entries(CoordinatorPreventiveOrderDetailModal.data ?? {})) {
    if (!(name in instance)) {
      instance[name] = initial;
    }
  }

  return instance;
}

const TEMPLATE = CoordinatorPreventiveOrderDetailModal.template;

// =============================================================================
// Fixtures
// =============================================================================
const detailFixture = {
  order: {
    id: 1,
    order_code: 'ORD-PREV-2026-0001',
    status: 'COMPLETED',
    status_label: 'Completada',
    order_type: 'ROUTINE',
    order_type_label: 'Ordinaria',
    scheduled_date: '2026-10-04',
    due_date: '2026-10-06',
    created_at: '2026-09-22 12:00:00',
    updated_at: '2026-10-05 09:35:00',
    started_at: '2026-10-05 09:00:00',
    completed_at: '2026-10-05 09:35:00',
    temperature_measured: 3.2,
    result: 'CONFORME',
    result_label: 'Conforme',
    is_quarantine_triggered: false,
    notes: 'Inspección higiénico-sanitaria inicial conforme.',
    cancellation_reason: null,
    linked_incident_id: 3661
  },
  location: {
    id: 1,
    name: 'Hospital del Mar - Edificio Central',
    site_code: 'SEDE-BCN-01',
    address: 'Passeig Marítim 25, Barcelona'
  },
  machine: {
    id: 1,
    code: 'VEND-0101',
    model: 'Sanden Vendo G-Drink',
    machine_type: 'PERISHABLE_FOOD',
    machine_type_label: 'Alimentos perecederos (Sándwiches y lácteos frescos)',
    floor_wing: 'Planta Baja - Urgencias',
    has_perishables: true,
    sanitary_status: 'OK',
    sanitary_status_label: 'Operativa y vigente'
  },
  technician: {
    assigned: true,
    id: 2,
    name: 'Jordi Técnico Ruta BCN',
    operator_code: 'OP-01'
  },
  validity: {
    state: 'CERRADA',
    state_label: 'Inspección cerrada',
    days_remaining: null,
    is_overdue: false,
    valid_until: '2026-10-06',
    balance_label: 'Inspección completada el 05/10/2026 09:35'
  },
  checklist: {
    has_checklist: true,
    totals: { total: 7, pass: 5, warn: 1, fail: 0, not_applicable: 1, critical_failures: 0 },
    compliance_percent: 83,
    items: [
      {
        item_code: 'TEMPERATURE_READING',
        item_description: 'Temperatura de sonda ≤ 4.0 °C',
        is_critical: true,
        status: 'PASS',
        status_label: 'Conforme',
        observations: 'Sonda estabilizada en 3.2 °C.',
        photo_url: null
      },
      {
        item_code: 'LED_LIGHTING',
        item_description: 'Iluminación LED interior degradada',
        is_critical: false,
        status: 'WARN',
        status_label: 'Con observaciones',
        observations: 'Brillo reducido.',
        photo_url: '/uploads/evidencia-led.jpg'
      }
    ]
  },
  linked_incident: {
    id: 3661,
    ticket_code: 'INC-DEMO-0922',
    status: 'CLOSED',
    status_label: 'Cerrada',
    urgency: 'LOW',
    urgency_label: 'Baja',
    created_at: '2026-09-22 09:15:00'
  },
  certificate: {
    certificate_code: 'CERT-2026-0001',
    inspection_date: '2026-10-05 09:30:00',
    valid_until: '2026-10-19',
    temperature_measured: 3.2,
    result: 'CONFORME',
    result_label: 'Conforme',
    status: 'VALID',
    status_label: 'Vigente',
    inspector: { name: 'Jordi Técnico Ruta BCN', operator_code: 'OP-01' }
  },
  audit_trail: [
    {
      action: 'ASSIGN_PREVENTIVE_ORDER',
      action_label: 'Asignación de técnico',
      created_at: '2026-10-04 10:00:00',
      user_name: 'Sara Coordinadora',
      user_role: 'COORDINATOR'
    },
    {
      action: 'COMPLETE_PREVENTIVE_ORDER',
      action_label: 'Finalización de la inspección',
      created_at: '2026-10-05 09:35:00',
      user_name: 'Jordi Técnico Ruta BCN',
      user_role: 'TECHNICIAN'
    }
  ]
};

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - CoordinatorPreventiveOrderDetailModal');
console.log('======================================================================\n');

// =============================================================================
// GROUP 1: Component contract
// =============================================================================
console.log('--- Group 1: Component contract and structure ---');

assert('1.1 Component name is declared',
  CoordinatorPreventiveOrderDetailModal.name === 'CoordinatorPreventiveOrderDetailModal');
assert('1.2 Default export matches the named export', DefaultExport === CoordinatorPreventiveOrderDetailModal);
assert('1.3 Declares the isOpen and orderId props',
  CoordinatorPreventiveOrderDetailModal.props.isOpen?.type === Boolean
  && Array.isArray(CoordinatorPreventiveOrderDetailModal.props.orderId?.type));
assert('1.4 Emits close and open-incident-detail',
  CoordinatorPreventiveOrderDetailModal.emits.includes('close')
  && CoordinatorPreventiveOrderDetailModal.emits.includes('open-incident-detail'));
assert('1.5 Ficha is teleported to body and locks background scroll',
  TEMPLATE.includes('<Teleport to="body"') && typeof CoordinatorPreventiveOrderDetailModal.methods.handleOpenState === 'function');
assert('1.6 Header offers code, status badge, order type, refresh and close controls',
  TEMPLATE.includes('data-testid="preventive-detail-code"')
  && TEMPLATE.includes('data-testid="preventive-detail-status-badge"')
  && TEMPLATE.includes('data-testid="preventive-detail-order-type"')
  && TEMPLATE.includes('data-testid="preventive-detail-refresh"')
  && TEMPLATE.includes('data-testid="preventive-detail-close"'));
assert('1.7 Footer offers its own close action',
  TEMPLATE.includes('data-testid="preventive-detail-footer-close"') && TEMPLATE.includes('Cerrar'));
assert('1.8 Declares the nine blocks of the contract',
  ['section-location-machine', 'section-validity', 'section-technician', 'section-inspection',
    'section-checklist', 'section-linked-incident', 'section-certificate', 'section-audit-trail']
    .every((testid) => TEMPLATE.includes(`data-testid="${testid}"`)));
assert('1.9 Loading, error-with-retry and empty states exist',
  TEMPLATE.includes('data-testid="preventive-detail-loading"')
  && TEMPLATE.includes('data-testid="preventive-detail-error"')
  && TEMPLATE.includes('data-testid="preventive-detail-retry"')
  && TEMPLATE.includes('data-testid="preventive-detail-empty"'));
assert('1.10 Integrated evidence viewer ships inside the same modal container',
  TEMPLATE.includes('data-testid="preventive-photo-zoom"')
  && TEMPLATE.includes('data-testid="preventive-photo-zoom-image"')
  && TEMPLATE.includes('data-testid="preventive-photo-zoom-close"'));

// =============================================================================
// GROUP 2: Single close glyph and read-only guarantee
// =============================================================================
console.log('\n--- Group 2: Single close glyph and read-only guarantee ---');

const closeButtonMatch = TEMPLATE.match(
  /data-testid="preventive-detail-close"[\s\S]{0,300}?><\/button>/
);
assert('2.1 The header close button carries the shared btn-close class',
  TEMPLATE.includes('class="vg-btn vg-btn-secondary btn-close"')
  && TEMPLATE.includes('data-testid="preventive-detail-close"'));
assert('2.2 The close button renders an EMPTY body so the ::before glyph is the only ✕',
  Boolean(closeButtonMatch), 'No empty close button found in the header template');
assert('2.3 The design system injects exactly one close glyph through .btn-close::before',
  (() => {
    const css = fs.readFileSync(CSS_PATH, 'utf8');
    const glyphs = css.match(/\.btn-close::before\s*\{[^}]*content:\s*'✕'/g) || [];
    return glyphs.length === 1;
  })());
assert('2.4 No assignment, reassignment, cancellation or inspection control inside the ficha',
  !/Asignar técnico|Reasignar|Descartar|Cancelar orden|Comenzar inspección|Completar inspección|Guardar cambios/.test(TEMPLATE));
assert('2.5 The only button labels are read-only affordances',
  />\s*Actualizar\s*</.test(TEMPLATE)
  && />\s*Reintentar\s*</.test(TEMPLATE)
  && /🔍 Ver ficha de avería/.test(TEMPLATE));

// =============================================================================
// GROUP 3: Computed projections
// =============================================================================
console.log('\n--- Group 3: Computed projections ---');

const instance = createInstance({ detail: detailFixture });

assert('3.1 Header falls back to the route identifier while the ficha loads',
  createInstance({ orderId: 'ORD-PREV-2026-0001' }).headerOrderCode === 'ORD-PREV-2026-0001');
assert('3.2 Header shows the order code once loaded', instance.headerOrderCode === 'ORD-PREV-2026-0001');
assert('3.3 Status badge is translated to Spanish',
  instance.statusBadge.label === 'Completada' && instance.statusBadge.icon === '✅');
assert('3.4 Order type label resolves through the shared util',
  instance.orderTypeLabel === 'Ordinaria'
  && createInstance({ detail: { ...detailFixture, order: { ...detailFixture.order, order_type: 'MANUAL_EXTRA', order_type_label: null } } }).orderTypeLabel === 'Extraordinaria');
assert('3.5 Result badge and sanitary badges are present',
  instance.resultBadge.label === 'Conforme'
  && instance.sanitaryBadge.label === 'Operativa y vigente'
  && instance.validityBadge.label === 'Inspección cerrada'
  && instance.certificateBadge.label === 'Vigente');
assert('3.6 Checklist items carry their badge and evidence flag',
  instance.checklistItems.length === 2
  && instance.checklistItems[0].badge.label === 'Conforme'
  && instance.checklistItems[0].hasPhoto === false
  && instance.checklistItems[1].badge.label === 'Con observaciones'
  && instance.checklistItems[1].hasPhoto === true);
assert('3.7 A broken evidence hides the thumbnail in favour of the fallback',
  createInstance({ detail: detailFixture, failedPhotoCodes: ['LED_LIGHTING'] })
    .checklistItems[1].hasPhoto === false);
assert('3.8 Totals default to zero when the block is absent',
  createInstance({ detail: { ...detailFixture, checklist: null } }).checklistTotals.total === 0
  && createInstance({ detail: { ...detailFixture, checklist: null } }).hasChecklist === false);
assert('3.9 Linked incident and certificate blocks are exposed',
  instance.linkedIncident.ticket_code === 'INC-DEMO-0922'
  && instance.certificate.certificate_code === 'CERT-2026-0001'
  && instance.auditTrail.length === 2);
assert('3.10 Quarantine flag drives the critical banner',
  instance.hasQuarantine === false
  && createInstance({ detail: { ...detailFixture, order: { ...detailFixture.order, is_quarantine_triggered: true } } }).hasQuarantine === true);
assert('3.11 Closed orders publish the historical balance without countdown',
  instance.daysRemainingLabel === null && instance.validity.balance_label.includes('Inspección completada el'));
assert('3.12 Countdown wording covers today, the future and the overdue case',
  createInstance({ detail: { ...detailFixture, validity: { ...detailFixture.validity, days_remaining: 0 } } }).daysRemainingLabel === 'Vence hoy'
  && createInstance({ detail: { ...detailFixture, validity: { ...detailFixture.validity, days_remaining: 1 } } }).daysRemainingLabel === 'Faltan 1 día'
  && createInstance({ detail: { ...detailFixture, validity: { ...detailFixture.validity, days_remaining: 5 } } }).daysRemainingLabel === 'Faltan 5 días'
  && createInstance({ detail: { ...detailFixture, validity: { ...detailFixture.validity, days_remaining: -3 } } }).daysRemainingLabel === 'Vencida hace 3 días');

// =============================================================================
// GROUP 4: Formatting helpers
// =============================================================================
console.log('\n--- Group 4: Formatting helpers ---');

assert('4.1 Timestamps render as DD/MM/YYYY HH:MM',
  instance.formatDateTime('2026-10-05 09:35:00') === '05/10/2026 09:35');
assert('4.2 Contract dates render as DD/MM/YYYY',
  instance.formatDate('2026-10-06') === '06/10/2026' && instance.formatDate(null) === '—');
assert('4.3 Temperature renders with one decimal and unit',
  instance.formatTemperature(3.2) === '3.2 °C' && instance.formatTemperature(null) === '—');

// =============================================================================
// GROUP 5: Lifecycle and integrated viewer
// =============================================================================
console.log('\n--- Group 5: Lifecycle and integrated evidence viewer ---');

const closing = createInstance({ detail: detailFixture });
closing.requestClose();
assert('5.1 requestClose emits the close event (no draft guard: read-only ficha)',
  closing.getEmitted().some((e) => e.event === 'close')
  && closing.photoZoomItem === null);

const backdrop = createInstance({ detail: detailFixture });
backdrop.handleBackdropClick({ target: 'backdrop', currentTarget: 'backdrop' });
assert('5.2 Clicking the shaded backdrop closes the ficha',
  backdrop.getEmitted().some((e) => e.event === 'close'));

const innerClick = createInstance({ detail: detailFixture });
innerClick.handleBackdropClick({ target: 'dialog', currentTarget: 'backdrop' });
assert('5.3 Clicks inside the dialog never close it', innerClick.getEmitted().length === 0);

const zoomable = createInstance({ detail: detailFixture });
zoomable.openPhotoZoom(instance.checklistItems[1]);
assert('5.4 The evidence viewer opens for an item with photo',
  zoomable.photoZoomItem?.item_code === 'LED_LIGHTING');
zoomable.openPhotoZoom(instance.checklistItems[0]);
assert('5.5 Items without evidence are not zoomable',
  zoomable.photoZoomItem?.item_code === 'LED_LIGHTING');

const escapeInstance = createInstance({ detail: detailFixture, photoZoomItem: { item_code: 'LED_LIGHTING', photo_url: '/x.jpg' } });
escapeInstance.handleKeyDown({ key: 'Escape' });
assert('5.6 Escape closes the viewer first and keeps the ficha open',
  escapeInstance.photoZoomItem === null && escapeInstance.getEmitted().length === 0);
escapeInstance.handleKeyDown({ key: 'Escape' });
assert('5.7 A second Escape closes the ficha',
  escapeInstance.getEmitted().some((e) => e.event === 'close'));

const notEscape = createInstance({ detail: detailFixture });
notEscape.handleKeyDown({ key: 'Enter' });
assert('5.8 Non-Escape keys never close the ficha', notEscape.getEmitted().length === 0);

const closedInstance = createInstance({ detail: detailFixture, isOpen: false });
closedInstance.handleKeyDown({ key: 'Escape' });
assert('5.9 Escape is ignored while the ficha is closed', closedInstance.getEmitted().length === 0);

const brokenPhoto = createInstance({ detail: detailFixture, photoZoomItem: { item_code: 'LED_LIGHTING' } });
brokenPhoto.handlePhotoError('LED_LIGHTING');
assert('5.10 A broken evidence registers the fallback and closes the viewer',
  brokenPhoto.failedPhotoCodes.includes('LED_LIGHTING') && brokenPhoto.photoZoomItem === null);

// =============================================================================
// GROUP 6: Data loading through the API client
// =============================================================================
console.log('\n--- Group 6: Aggregated loading through the API client ---');

const originalGet = api.get;
let requestedPath = null;

api.get = async (endpoint) => {
  requestedPath = endpoint;
  return detailFixture;
};

const loaded = createInstance({ detail: null });
await loaded.fetchDetail();
assert('6.1 Loading resolves through the dedicated coordinator endpoint',
  requestedPath === '/coordinator/preventive/orders/1/detail');
assert('6.2 The aggregated payload becomes the ficha state',
  loaded.detail?.order?.order_code === 'ORD-PREV-2026-0001' && loaded.isLoading === false);

const failing = createInstance({ detail: detailFixture });
api.get = async () => {
  throw new Error('No se pudo cargar el detalle de la orden preventiva.');
};
await failing.fetchDetail();
assert('6.3 A failed load keeps the error visible for the retry action',
  failing.errorMessage.length > 0 && failing.isLoading === false);

api.get = async () => {
  throw { message: 'Orden preventiva no encontrada.' };
};
const failingCustom = createInstance({ detail: null });
await failingCustom.fetchDetail();
assert('6.4 The server message is surfaced verbatim when available',
  failingCustom.errorMessage === 'Orden preventiva no encontrada.');

const noIdentifier = createInstance({ orderId: null, detail: null });
api.get = async () => {
  throw new Error('should not be called');
};
await noIdentifier.fetchDetail();
assert('6.5 Without an identifier no request is issued', noIdentifier.errorMessage === '');

api.get = originalGet;

// =============================================================================
// GROUP 7: Linked corrective incident jump
// =============================================================================
console.log('\n--- Group 7: Linked corrective incident jump ---');

const jumpInstance = createInstance({ detail: detailFixture });
jumpInstance.openLinkedIncidentDetail();
const jumpEvents = jumpInstance.getEmitted();
assert('7.1 The jump closes the ficha before asking for the incident ficha',
  jumpEvents.some((e) => e.event === 'close'));
assert('7.2 The jump emits the corrective incident id',
  jumpEvents.some((e) => e.event === 'open-incident-detail' && e.payload === 3661));

const noJump = createInstance({ detail: { ...detailFixture, linked_incident: null } });
noJump.openLinkedIncidentDetail();
assert('7.3 Without a linked incident nothing is emitted', noJump.getEmitted().length === 0);

// =============================================================================
// GROUP 8: Shared util, orders tray trigger and dashboard wiring
// =============================================================================
console.log('\n--- Group 8: Shared util, tray trigger and dashboard wiring ---');

assert('8.1 Shared label util exposes the module vocabulary',
  getOrderStatusBadge('EXPIRED').label === 'Vencida'
  && getOrderTypeLabel('REINSPECTION') === 'Reinspección'
  && getChecklistItemBadge('FAIL').label === 'No conforme'
  && getValidityBadge('CUARENTENA').label === 'Cuarentena sanitaria');

const TAB_TEMPLATE = CoordinatorPreventiveOrdersTab.template;
assert('8.2 The orders tray registers the detail modal',
  CoordinatorPreventiveOrdersTab.components?.CoordinatorPreventiveOrderDetailModal === CoordinatorPreventiveOrderDetailModal);
assert('8.3 The tray declares the upward jump event',
  CoordinatorPreventiveOrdersTab.emits.includes('open-incident-detail'));
assert('8.4 Every row offers the Ver detalle trigger next to the quick actions',
  TAB_TEMPLATE.includes('data-testid="btn-view-order-detail"')
  && TAB_TEMPLATE.includes('@click.stop="openDetailModal(order)"')
  && TAB_TEMPLATE.includes('data-testid="btn-assign-order"')
  && TAB_TEMPLATE.includes('data-testid="btn-cancel-order"'));
assert('8.5 The modal is mounted next to the tray own modals with is-open and order-id',
  TAB_TEMPLATE.includes('<CoordinatorPreventiveOrderDetailModal')
  && TAB_TEMPLATE.includes(':is-open="showDetailModal"')
  && TAB_TEMPLATE.includes(':order-id="detailOrderId"')
  && TAB_TEMPLATE.includes('@open-incident-detail="handleOpenIncidentDetail"'));

const trayInstance = {
  detailOrderId: null,
  showDetailModal: false,
  $emit: () => {},
  ...CoordinatorPreventiveOrdersTab.methods,
  getStatusBadge: CoordinatorPreventiveOrdersTab.methods.getStatusBadge,
  getResultBadge: CoordinatorPreventiveOrdersTab.methods.getResultBadge,
  getOrderTypeLabel: CoordinatorPreventiveOrdersTab.methods.getOrderTypeLabel
};
trayInstance.openDetailModal({ id: 42, order_code: 'ORD-PREV-2026-0042' });
assert('8.6 Opening the detail records the order and shows the modal',
  trayInstance.detailOrderId === 42 && trayInstance.showDetailModal === true);
trayInstance.closeDetailModal();
assert('8.7 Closing the detail releases the selection',
  trayInstance.detailOrderId === null && trayInstance.showDetailModal === false);
assert('8.8 The tray badges delegate to the shared util (single source of truth)',
  CoordinatorPreventiveOrdersTab.methods.getStatusBadge.call({}, 'COMPLETED').label === 'Completada'
  && CoordinatorPreventiveOrdersTab.methods.getOrderTypeLabel.call({}, 'ROUTINE') === 'Ordinaria'
  && CoordinatorPreventiveOrdersTab.methods.getResultBadge.call({}, null) === null);

assert('8.9 The dashboard view wires the jump into the existing incident ficha',
  CoordinatorDashboardView.template.includes('@open-incident-detail="openIncidentDetailFromPreventive"')
  && typeof CoordinatorDashboardView.methods.openIncidentDetailFromPreventive === 'function');

const viewInstance = {
  incidents: [{ id: 3661, ticket_code: 'INC-DEMO-0922' }],
  selectedDetailIncident: null,
  showDetailModal: false,
  ...CoordinatorDashboardView.methods
};
viewInstance.openIncidentDetailFromPreventive(3661);
assert('8.10 The jump rehydrates the row and opens the incident detail modal',
  viewInstance.selectedDetailIncident?.id === 3661 && viewInstance.showDetailModal === true);

const viewInstanceUnknown = {
  incidents: [],
  selectedDetailIncident: null,
  showDetailModal: false,
  ...CoordinatorDashboardView.methods
};
viewInstanceUnknown.openIncidentDetailFromPreventive(999);
assert('8.11 An incident outside the loaded tray still opens by identifier',
  viewInstanceUnknown.selectedDetailIncident?.id === 999 && viewInstanceUnknown.showDetailModal === true);
viewInstanceUnknown.openIncidentDetailFromPreventive(null);
assert('8.12 A null identifier never opens the incident ficha',
  viewInstanceUnknown.selectedDetailIncident?.id === 999);

// =============================================================================
// Final result
// =============================================================================
console.log('\n======================================================================');
const passed = assertions - failures;
console.log(` Total Assertions: ${assertions} | Passed: ${passed} | Failed: ${failures}`);
console.log(failures === 0
  ? ' RESULT: 100% IN GREEN. RF-PD-01..10 AND RNF-PD-01..07 VERIFIED.'
  : ' RESULT: FAILURES DETECTED. Review the assertions above.');
console.log('======================================================================');

process.exit(failures === 0 ? 0 : 1);
