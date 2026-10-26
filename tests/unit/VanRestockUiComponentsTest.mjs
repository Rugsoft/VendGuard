/**
 * VendGuard - Frontend Unit Test Suite (VanRestockUiComponentsTest.mjs)
 *
 * Certifica la interfaz de las órdenes de carga de furgoneta y el cruce
 * informativo de stock en triaje (Módulo 23, T-RESTOCK-12) sin navegador, sin
 * red y sin dependencias npm:
 *
 *  - **Contrato cliente:** los métodos de `api.js` apuntan a las rutas del
 *    contrato §5.2 a §5.11 y la confirmación móvil no acepta un técnico ajeno.
 *  - **Modo Órdenes de Carga:** propuesta pre-calculada, lote, alta/edición,
 *    expedición parcial, cancelación con justificación real de 20 caracteres y
 *    baja lógica representada por el formulario sin `DELETE`.
 *  - **Recepción del técnico:** `active_dispatched_order` se pinta en una tarjeta
 *    destacada, la confirmación es un solo toque sobre la orden propia y el
 *    comprobante `last_confirmed_order` queda visible (QA 5).
 *  - **Triaje informativo:** `van_stock_match` y `van_stock_readiness` se pintan
 *    como chips de lectura; ni la puntuación ni el escalado sanitario se tocan
 *    en el cliente (Art. II, D2).
 *  - **Picking A4:** el documento existe, imprime con `window.print()` y no
 *    admite ningún coste, precio o importe (Art. V.4).
 *  - **Sistema de diseño:** botones con la escalera `vg-btn`, chips ámbar solo
 *    para lecturas y hoja de impresión con tokens, sin literales hexadecimales.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network.
 */

globalThis.localStorage = {
  _store: {},
  getItem(key) { return this._store[key] ?? null; },
  setItem(key, value) { this._store[key] = String(value); },
  removeItem(key) { delete this._store[key]; },
  clear() { this._store = {}; }
};
globalThis.window = {
  location: { search: '', href: 'http://localhost/' }
};
globalThis.print = () => {
  globalThis.__printCalls = (globalThis.__printCalls || 0) + 1;
};

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { api } from '../../public/assets/js/api.js';
import {
  AUDIT_ACTION_CATALOG,
  AUDIT_ENTITY_LABELS,
  getAuditActionLabel,
  getAuditEntityLabel
} from '../../public/assets/js/utils/AuditActionLabels.js';
import { CoordinatorVanStockTab } from '../../public/assets/js/components/CoordinatorVanStockTab.js';
import { TechnicianVanStockPanel } from '../../public/assets/js/components/TechnicianVanStockPanel.js';
import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';
import { TechnicianRouteView } from '../../public/assets/js/views/TechnicianRouteView.js';

const readSource = (relative) => readFileSync(
  fileURLToPath(new URL(`../../${relative}`, import.meta.url)),
  'utf8'
);

const COORDINATOR_SOURCE = readSource('public/assets/js/components/CoordinatorVanStockTab.js');
const TECHNICIAN_SOURCE = readSource('public/assets/js/components/TechnicianVanStockPanel.js');
const DASHBOARD_SOURCE = readSource('public/assets/js/views/CoordinatorDashboardView.js');
const ROUTE_SOURCE = readSource('public/assets/js/views/TechnicianRouteView.js');
const API_SOURCE = readSource('public/assets/js/api.js');
const PICKING_CSS = readSource('public/assets/css/styles.css');

const HEX_PATTERN = /#[0-9a-fA-F]{3,8}\b/g;
let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) {
      console.error(`         Motivo: ${details}`);
    }
    failures++;
  }
}

/** Instancia vanilla: datos + métodos propios + `computed` como getters. */
function createComponentInstance(component) {
  const instance = Object.assign({}, component.data(), component.methods || {});
  for (const [key, getter] of Object.entries(component.computed || {})) {
    Object.defineProperty(instance, key, {
      get: () => getter.call(instance),
      configurable: true
    });
  }

  return instance;
}

const PROPOSAL = {
  technician_id: 2,
  technician_name: 'Carlos Mendoza',
  technician_is_active: true,
  recipient_currently_absent: true,
  open_order_id: null,
  open_order_status: null,
  items: [
    {
      spare_part_id: 14,
      part_code: 'COMP-TH-01',
      part_name: 'Termostato Digital Necta',
      category: 'THERMAL',
      current_quantity: -1,
      min_threshold: 2,
      pending_request_qty: 1,
      suggested_qty: 5,
      max_allowed_qty: 9999,
      reason: 'BOTH'
    },
    {
      spare_part_id: 8,
      part_code: 'VALV-HY-02',
      part_name: 'Electroválvula 24V',
      category: 'HYDRAULIC',
      current_quantity: 0,
      min_threshold: 2,
      pending_request_qty: 0,
      suggested_qty: 3,
      max_allowed_qty: 9999,
      reason: 'LOW_STOCK'
    }
  ]
};

const makeOrder = (overrides = {}) => ({
  id: 42,
  order_code: 'RO-000042',
  technician_id: 2,
  technician_name: 'Carlos Mendoza',
  technician_operator_code: 'OP-02',
  technician_is_active: true,
  recipient_currently_absent: false,
  status: 'PENDING_PICKING',
  notes: 'Preparar en jaula 3.',
  active_items_count: 2,
  total_requested_units: 8,
  total_dispatched_units: null,
  created_by: 1,
  created_by_name: 'Ana Coordinadora',
  created_at: '2026-10-26T08:15:00+00:00',
  dispatched_by: null,
  dispatched_by_name: null,
  dispatched_at: null,
  confirmed_by: null,
  confirmed_by_name: null,
  confirmed_at: null,
  cancelled_by: null,
  cancelled_by_name: null,
  cancelled_at: null,
  cancellation_reason: null,
  updated_at: '2026-10-26T08:15:00+00:00',
  items: [
    {
      id: 101,
      spare_part_id: 14,
      part_code: 'COMP-TH-01',
      part_name: 'Termostato Digital Necta',
      category: 'THERMAL',
      current_quantity_snapshot: -1,
      min_threshold_snapshot: 2,
      pending_request_qty_snapshot: 1,
      requested_qty: 5,
      dispatched_qty: null,
      reason: 'BOTH'
    },
    {
      id: 102,
      spare_part_id: 8,
      part_code: 'VALV-HY-02',
      part_name: 'Electroválvula 24V',
      category: 'HYDRAULIC',
      current_quantity_snapshot: 0,
      min_threshold_snapshot: 2,
      pending_request_qty_snapshot: 0,
      requested_qty: 3,
      dispatched_qty: null,
      reason: 'LOW_STOCK'
    }
  ],
  ...overrides
});

const ACTIVE_DISPATCHED_ORDER = {
  id: 42,
  order_code: 'RO-000042',
  status: 'DISPATCHED',
  notes: 'Entregado en mostrador.',
  dispatched_at: '2026-10-26T08:30:00+00:00',
  dispatched_by_name: 'Ana Coordinadora',
  total_dispatched_units: 4,
  items: [
    {
      spare_part_id: 14,
      part_code: 'COMP-TH-01',
      part_name: 'Termostato Digital Necta',
      category: 'THERMAL',
      requested_qty: 5,
      dispatched_qty: 4,
      reason: 'BOTH'
    }
  ]
};

const LAST_CONFIRMED_ORDER = {
  id: 38,
  order_code: 'RO-000038',
  confirmed_at: '2026-10-24T09:10:00+00:00',
  total_received_units: 6,
  items: [
    {
      spare_part_id: 8,
      part_code: 'VALV-HY-02',
      part_name: 'Electroválvula 24V',
      dispatched_qty: 6
    }
  ]
};

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - Van restock orders UI (T-RESTOCK-12)');
console.log('======================================================================\n');

// =========================================================================
// BLOQUE 1: Contrato cliente y catálogo de auditoría
// =========================================================================
console.log('--- BLOQUE 1: Contrato cliente y catálogo de auditoría ---');

assert(
  '1.1 El cliente declara las ocho operaciones de Coordinación y la confirmación móvil',
  typeof api.coordinator.getVanRestockProposals === 'function'
    && typeof api.coordinator.getVanRestockOrders === 'function'
    && typeof api.coordinator.getVanRestockOrder === 'function'
    && typeof api.coordinator.createVanRestockOrder === 'function'
    && typeof api.coordinator.generateVanRestockBatch === 'function'
    && typeof api.coordinator.updateVanRestockOrder === 'function'
    && typeof api.coordinator.dispatchVanRestockOrder === 'function'
    && typeof api.coordinator.cancelVanRestockOrder === 'function'
    && typeof api.technician.confirmVanRestockOrder === 'function'
);

assert(
  '1.2 Las rutas cliente coinciden con el contrato §5.2 a §5.11',
  API_SOURCE.includes('/coordinator/van-restock-orders/proposals')
    && API_SOURCE.includes('/coordinator/van-restock-orders/generate-batch')
    && API_SOURCE.includes("`/coordinator/van-restock-orders/${encodeURIComponent(orderId)}/dispatch`")
    && API_SOURCE.includes("`/coordinator/van-restock-orders/${encodeURIComponent(orderId)}/cancel`")
    && API_SOURCE.includes("`/technician/van-stock/restock-orders/${encodeURIComponent(orderId)}/confirm`")
);

assert(
  '1.3 La confirmación móvil solo envía el identificador de la orden, nunca un técnico',
  /confirmVanRestockOrder:\s*\(orderId\)/.test(API_SOURCE)
    && !/confirmVanRestockOrder[\s\S]{0,250}technician_id/.test(API_SOURCE)
);

const restockActions = [
  'CREATE_VAN_RESTOCK_ORDER',
  'UPDATE_VAN_RESTOCK_ORDER',
  'DISPATCH_VAN_RESTOCK_ORDER',
  'CONFIRM_VAN_RESTOCK_ORDER',
  'CANCEL_VAN_RESTOCK_ORDER'
];
assert(
  '1.4 Las cinco acciones del ciclo están catalogadas con etiqueta y grupo',
  restockActions.every((code) => {
    const entry = AUDIT_ACTION_CATALOG.find((action) => action.code === code);
    return entry && entry.label && entry.group === 'restock';
  })
);
assert(
  '1.5 Las acciones se resuelven con su etiqueta en castellano y el catálogo conserva sus entidades',
  getAuditActionLabel('CONFIRM_VAN_RESTOCK_ORDER') === 'Recepción de Carga Confirmada'
    && getAuditEntityLabel('TECHNICIAN_VAN_STOCK') === AUDIT_ENTITY_LABELS.TECHNICIAN_VAN_STOCK
);

// =========================================================================
// BLOQUE 2: Modo Órdenes de Carga y formularios
// =========================================================================
console.log('\n--- BLOQUE 2: Modo Órdenes de Carga (RF-02 a RF-04) ---');

const tab = createComponentInstance(CoordinatorVanStockTab);
assert(
  '2.1 La pestaña arranca en alertas y ofrece el tercer modo de órdenes',
  tab.mode === 'alert'
    && tab.isOrdersMode === false
    && COORDINATOR_SOURCE.includes('data-testid="coord-van-stock-mode-orders"')
    && COORDINATOR_SOURCE.includes('data-testid="coord-van-stock-orders-panel"')
);

assert(
  '2.2 La plantilla publica los anclajes de propuesta, lote, tabla y alta',
  COORDINATOR_SOURCE.includes('data-testid="coord-restock-proposals"')
    && COORDINATOR_SOURCE.includes('data-testid="coord-restock-batch"')
    && COORDINATOR_SOURCE.includes('data-testid="coord-restock-order-create"')
    && COORDINATOR_SOURCE.includes('data-testid="coord-restock-orders-table"')
);

let workspaceCalls = 0;
api.coordinator.getVanRestockProposals = async () => {
  workspaceCalls++;
  return { proposals: [PROPOSAL] };
};
api.coordinator.getVanRestockOrders = async () => {
  workspaceCalls++;
  return { items: [makeOrder()] };
};

await tab.loadOrderWorkspace();
assert(
  '2.3 La carga pide propuesta y órdenes en la misma lectura y pinta sus cifras',
  workspaceCalls === 2
    && tab.proposals.length === 1
    && tab.orders.length === 1
    && tab.orderKpis[0].value === 1
    && tab.orderKpis[1].value === 1
    && tab.orderKpis[2].value === 0
);

assert(
  '2.4 La propuesta publica referencias, unidades y la alerta de ausencia',
  tab.proposalUnits(PROPOSAL) === 8
    && COORDINATOR_SOURCE.includes('⚠️ Destinatario actualmente ausente / inactivo')
    && COORDINATOR_SOURCE.includes('data-testid="coord-restock-absent-warning"')
);

tab.openOrderForm(null, PROPOSAL);
assert(
  '2.5 Crear desde propuesta precarga destinatario y líneas sugeridas, sin persistir todavía',
  tab.showOrderForm === true
    && tab.orderForm.technician_id === 2
    && tab.orderForm.items.length === 2
    && tab.orderForm.items[0].requested_qty === 5
    && tab.canSaveOrder === true
);

tab.orderForm.items[0].requested_qty = 0;
assert('2.6 Una cantidad fuera de [1, 9999] bloquea el alta', tab.canSaveOrder === false);
tab.orderForm.items[0].requested_qty = '5.5';
assert('2.6a Una cantidad fraccionaria no es un entero de almacén', tab.canSaveOrder === false);
tab.orderForm.items[0].requested_qty = 5;

tab.addOrderLine();
assert(
  '2.7 Añadir una línea manual deja una pieza sin elegir y bloquea el guardado',
  tab.orderForm.items.length === 3
    && tab.orderForm.items[2].reason === 'MANUAL'
    && tab.canSaveOrder === false
);
tab.removeOrderLine(2);
assert('2.8 Retirar la línea manual devuelve el formulario a un estado válido', tab.canSaveOrder === true);

const createPayloads = [];
api.coordinator.createVanRestockOrder = async (payload) => {
  createPayloads.push(payload);
  return {
    order: makeOrder(),
    message: 'Orden de carga creada en estado Pendiente de Picking.'
  };
};
tab.loadOrderWorkspace = async () => {
  tab.orders = [makeOrder()];
};
await tab.saveOrder();
assert(
  '2.9 El alta envía destinatario, notas y líneas, y cierra el formulario',
  createPayloads.length === 1
    && createPayloads[0].technician_id === 2
    && createPayloads[0].items.length === 2
    && createPayloads[0].items[0].spare_part_id === 14
    && createPayloads[0].items[0].requested_qty === 5
    && tab.showOrderForm === false
    && tab.ordersSuccess.includes('Pendiente de Picking')
);

const editTab = createComponentInstance(CoordinatorVanStockTab);
const updatePayloads = [];
editTab.loadOrderWorkspace = async () => {};
api.coordinator.updateVanRestockOrder = async (id, payload) => {
  updatePayloads.push({ id, payload });
  return { order: makeOrder(), message: 'Orden de carga actualizada correctamente.' };
};
editTab.openOrderForm(makeOrder());
editTab.orderForm.items = [editTab.orderForm.items[0]];
await editTab.saveOrder();
assert(
  '2.10 La edición conserva el identificador y envía solo las líneas activas (baja lógica en servidor)',
  updatePayloads.length === 1
    && updatePayloads[0].id === 42
    && updatePayloads[0].payload.items.length === 1
    && !/DELETE FROM/i.test(COORDINATOR_SOURCE)
    && !/\.delete\(/i.test(COORDINATOR_SOURCE)
);

const orderFormCatalogue = createComponentInstance(CoordinatorVanStockTab);
orderFormCatalogue.catalogue = [
  { id: 14, part_code: 'COMP-TH-01', name: 'Termostato Digital Necta' },
  { id: 8, part_code: 'VALV-HY-02', name: 'Electroválvula 24V' }
];
orderFormCatalogue.openOrderForm(null, PROPOSAL);
assert(
  '2.11 El selector de una línea no permite repetir una pieza ya presente',
  orderFormCatalogue.linePartOptions(0).length === 1
    && orderFormCatalogue.linePartOptions(0)[0].id === 14
    && !orderFormCatalogue.linePartOptions(0).some((part) => Number(part.id) === 8)
);

// =========================================================================
// BLOQUE 3: Expedición, cancelación y Picking A4
// =========================================================================
console.log('\n--- BLOQUE 3: Expedición, cancelación y Picking A4 (RF-04, Art. V.1/V.4) ---');

const dispatchTab = createComponentInstance(CoordinatorVanStockTab);
dispatchTab.openDispatchModal(makeOrder());
assert(
  '3.1 La expedición precarga todas las líneas con la cantidad solicitada',
  dispatchTab.showDispatchModal === true
    && dispatchTab.dispatchForm.items.length === 2
    && dispatchTab.dispatchForm.items[0].dispatched_qty === 5
    && dispatchTab.canDispatch === true
);

dispatchTab.dispatchForm.items[0].dispatched_qty = 0;
dispatchTab.dispatchForm.items[1].dispatched_qty = 0;
assert('3.2 Expedir cero unidades en todas las líneas está bloqueado (C3)', dispatchTab.canDispatch === false);

dispatchTab.dispatchForm.items[0].dispatched_qty = 4;
const dispatchPayloads = [];
api.coordinator.dispatchVanRestockOrder = async (id, payload) => {
  dispatchPayloads.push({ id, payload });
  return {
    order: makeOrder({ status: 'DISPATCHED', dispatched_at: '2026-10-26T08:30:00+00:00' }),
    message: 'Orden marcada como Expedida. Pendiente de confirmación por el técnico.'
  };
};
dispatchTab.loadOrderWorkspace = async () => {};
await dispatchTab.submitDispatch();
assert(
  '3.3 La expedición parcial viaja con cada cantidad servida y cierra el modal',
  dispatchPayloads.length === 1
    && dispatchPayloads[0].id === 42
    && dispatchPayloads[0].payload.items.length === 2
    && dispatchPayloads[0].payload.items[0].dispatched_qty === 4
    && dispatchPayloads[0].payload.items[1].dispatched_qty === 0
    && dispatchTab.showDispatchModal === false
);

const cancelTab = createComponentInstance(CoordinatorVanStockTab);
cancelTab.openCancelOrderModal(makeOrder());
cancelTab.cancelOrderReason = 'Motivo corto';
assert(
  '3.4 Doce caracteres reales no alcanzan la justificación de cancelación (Art. V.1)',
  cancelTab.cancelOrderReasonLength === 12 && cancelTab.isCancelOrderReasonValid === false,
  `longitud=${cancelTab.cancelOrderReasonLength}`
);
cancelTab.cancelOrderReason = '   Motivo corto   ';
assert(
  '3.5 La justificación se mide sobre el texto recortado',
  cancelTab.cancelOrderReasonLength === 12 && cancelTab.isCancelOrderReasonValid === false,
  `longitud=${cancelTab.cancelOrderReasonLength}`
);
cancelTab.cancelOrderReason = 'Motivo válido con veinte caracteres reales.';
assert('3.6 Veinte caracteres reales habilitan la cancelación', cancelTab.isCancelOrderReasonValid === true);
cancelTab.cancelOrderReason = '📦'.repeat(20);
assert(
  '3.6a Veinte emojis son veinte caracteres reales, no cuarenta unidades UTF-16',
  cancelTab.cancelOrderReasonLength === 20 && cancelTab.isCancelOrderReasonValid === true
);

cancelTab.cancelOrderReason = 'Motivo válido con veinte caracteres reales.';
const cancelPayloads = [];
api.coordinator.cancelVanRestockOrder = async (id, reason) => {
  cancelPayloads.push({ id, reason });
  return { order: makeOrder({ status: 'CANCELLED' }), message: 'La orden de carga ha sido cancelada.' };
};
cancelTab.loadOrderWorkspace = async () => {};
await cancelTab.submitCancelOrder();
assert(
  '3.7 La cancelación envía la justificación y refresca el ciclo',
  cancelPayloads.length === 1
    && cancelPayloads[0].id === 42
    && cancelPayloads[0].reason.includes('veinte')
    && cancelTab.showCancelOrderModal === false
);

const batchTab = createComponentInstance(CoordinatorVanStockTab);
const batchPayloads = [];
api.coordinator.generateVanRestockBatch = async (payload) => {
  batchPayloads.push(payload);
  return {
    created_count: 2,
    skipped_count: 1,
    message: 'Generación por lotes completada: 2 órdenes creadas, 1 técnicos omitidos.'
  };
};
batchTab.loadOrderWorkspace = async () => {};
await batchTab.generateBatch();
assert(
  '3.8 El lote se genera con cuerpo vacío y publica el desglose de omitidas',
  batchPayloads.length === 1
    && Object.keys(batchPayloads[0]).length === 0
    && batchTab.ordersSuccess.includes('2 órdenes creadas')
);

const pickingTab = createComponentInstance(CoordinatorVanStockTab);
api.coordinator.getVanRestockOrder = async (id) => {
  assert('3.9 El detalle del albarán se pide por el identificador de la orden', id === 42);
  return makeOrder({ status: 'DISPATCHED', dispatched_at: '2026-10-26T08:30:00+00:00' });
};
await pickingTab.openPickingModal(makeOrder());
assert(
  '3.10 El albarán se abre con el detalle íntegro y su hoja imprimible',
  pickingTab.showPickingModal === true
    && pickingTab.pickingOrder.order_code === 'RO-000042'
    && COORDINATOR_SOURCE.includes('data-testid="coord-picking-sheet"')
    && COORDINATOR_SOURCE.includes('data-testid="coord-picking-print"')
);
const printBefore = globalThis.__printCalls || 0;
pickingTab.printPickingSheet();
assert('3.11 Imprimir llama al mecanismo nativo, sin librería PDF', (globalThis.__printCalls || 0) === printBefore + 1);

assert(
  '3.12 El Picking A4 no transporta coste, precio ni importe (Art. V.4)',
  !/reference_cost|unit_cost|price|cost_snapshot/.test(COORDINATOR_SOURCE)
);

assert(
  '3.13 El A4 publica operador, fecha de la orden y las diez columnas de EARS 03.1',
  ['Hecho', 'Referencia', 'Pieza', 'Categoría', 'Saldo', 'Umbral', 'Pedidas', 'Solicitado', 'Servido', 'Motivo']
    .every((heading) => COORDINATOR_SOURCE.includes(`>${heading}<`))
    && COORDINATOR_SOURCE.includes('technician_operator_code')
    && COORDINATOR_SOURCE.includes('Fecha de la orden:')
    && COORDINATOR_SOURCE.includes('current_quantity_snapshot')
    && COORDINATOR_SOURCE.includes('min_threshold_snapshot')
    && COORDINATOR_SOURCE.includes('pending_request_qty_snapshot')
);

assert(
  '3.14 El aviso de destinatario cubre la baja sobrevenida además de la ausencia (C2)',
  pickingTab.hasRecipientAlert(makeOrder({ technician_is_active: false, recipient_currently_absent: false })) === true
    && pickingTab.hasRecipientAlert(makeOrder({ technician_is_active: true, recipient_currently_absent: true })) === true
    && pickingTab.hasRecipientAlert(makeOrder({ technician_is_active: true, recipient_currently_absent: false })) === false
    && COORDINATOR_SOURCE.includes('hasRecipientAlert(proposal)')
    && COORDINATOR_SOURCE.includes('hasRecipientAlert(order)')
    && COORDINATOR_SOURCE.includes('hasRecipientAlert(pickingOrder)')
);

assert(
  '3.15 La categoría del A4 se traduce al castellano y no se enseña el enum crudo',
  pickingTab.categoryLabel('THERMAL') === 'Térmica'
    && pickingTab.categoryLabel('HYDRAULIC') === 'Hidráulica'
    && COORDINATOR_SOURCE.includes('categoryLabel(line.category)')
);

// =========================================================================
// BLOQUE 4: Recepción móvil del técnico
// =========================================================================
console.log('\n--- BLOQUE 4: Recepción móvil del técnico (RF-05, QA 4/QA 5) ---');

const panel = createComponentInstance(TechnicianVanStockPanel);
api.technician.getVanStock = async () => ({
  technician_id: 7,
  items: [
    {
      spare_part_id: 14,
      part_code: 'COMP-TH-01',
      name: 'Termostato Digital Necta',
      category: 'THERMAL',
      quantity_available: -1,
      is_below_threshold: true
    }
  ],
  active_dispatched_order: ACTIVE_DISPATCHED_ORDER,
  last_confirmed_order: LAST_CONFIRMED_ORDER
});
await panel.loadInventory();
assert(
  '4.1 La carga publica la orden pendiente y el comprobante de la última recepción',
  panel.hasActiveOrder === true
    && panel.activeDispatchedOrder.order_code === 'RO-000042'
    && panel.activeOrderUnits === 4
    && panel.lastConfirmedOrder.total_received_units === 6
);
assert(
  '4.2 La tarjeta destacada y el comprobante tienen anclaje en la plantilla',
  TECHNICIAN_SOURCE.includes('data-testid="tech-van-stock-active-order"')
    && TECHNICIAN_SOURCE.includes('data-testid="tech-van-stock-last-confirmed"')
    && TECHNICIAN_SOURCE.includes('data-testid="tech-van-stock-primary-action"')
);

const confirmCalls = [];
api.technician.confirmVanRestockOrder = async (orderId) => {
  confirmCalls.push(orderId);
  return {
    order: {
      id: 42,
      order_code: 'RO-000042',
      status: 'CONFIRMED_BY_TECH',
      confirmed_at: '2026-10-26T08:45:00+00:00',
      total_received_units: 4
    },
    message: 'Carga confirmada. Se han sumado 4 unidades al stock de tu furgoneta.'
  };
};
// El reload posterior refleja la orden ya confirmada y el saldo actualizado.
api.technician.getVanStock = async () => ({
  technician_id: 7,
  items: [
    {
      spare_part_id: 14,
      part_code: 'COMP-TH-01',
      name: 'Termostato Digital Necta',
      category: 'THERMAL',
      quantity_available: 3,
      is_below_threshold: false
    }
  ],
  active_dispatched_order: null,
  last_confirmed_order: {
    ...LAST_CONFIRMED_ORDER,
    id: 42,
    order_code: 'RO-000042',
    total_received_units: 4
  }
});
await panel.confirmActiveOrder();
assert(
  '4.3 La confirmación es un solo toque sobre la orden propia',
  confirmCalls.length === 1 && confirmCalls[0] === 42
);
assert(
  '4.4 Tras confirmar desaparece la tarjeta pendiente y el saldo llega actualizado',
  panel.hasActiveOrder === false
    && panel.items[0].quantity_available === 3
    && panel.confirmMessage.includes('4 unidades')
    && panel.lastConfirmedOrder.order_code === 'RO-000042'
);

const TECHNICIAN_TEMPLATE = TECHNICIAN_SOURCE.slice(TECHNICIAN_SOURCE.indexOf('template:'));
assert(
  '4.5 El panel no menciona umbral, coste ni reconstruye el descuadre',
  !/min_threshold|reference_cost|unit_cost|price|cost_snapshot/.test(TECHNICIAN_SOURCE)
    && !/is_negative|Descuadre/.test(TECHNICIAN_TEMPLATE)
);

const buttonTags = TECHNICIAN_SOURCE.match(/<button\b/g) || [];
assert(
  '4.6 La acción principal mide 44 px y el panel conserva sus dos botones (reintento y acción)',
  buttonTags.length === 2 && /min-height:\s*44px/.test(TECHNICIAN_SOURCE)
);

// =========================================================================
// BLOQUE 5: Triaje informativo y tarjetas de avería
// =========================================================================
console.log('\n--- BLOQUE 5: Triaje informativo y tarjetas de avería (D2, QA 3) ---');

const dashboard = createComponentInstance(CoordinatorDashboardView);
dashboard.assignmentSuggestions = {
  incident: { id: 105, is_cold_chain_risk: false },
  recommendation: { is_cold_chain_escalation: false },
  ranked_technicians: [
    {
      technician_id: 2,
      name: 'Carlos Mendoza',
      total_score: 115,
      score_breakdown: { territorial_affinity: 100, active_workload_penalty: -10 },
      van_stock_match: {
        status: 'FULL_MATCH',
        evaluation_level: 'EXPLICIT_REQUESTS',
        badge: 'STOCK_COMPATIBLE',
        label: 'Repuesto solicitado en furgoneta (2/2 ref.)'
      }
    },
    {
      technician_id: 3,
      name: 'Laura Gómez',
      total_score: 90,
      score_breakdown: { territorial_affinity: 75 },
      van_stock_match: {
        status: 'PARTIAL_MATCH',
        evaluation_level: 'EXPLICIT_REQUESTS',
        badge: 'STOCK_PARCIAL',
        label: 'Repuesto solicitado parcial (1/2 ref.)'
      }
    }
  ]
};

assert(
  '5.1 El ranking publica una fila por técnico con su etiqueta de stock',
  dashboard.vanStockMatchRows.length === 2
    && dashboard.vanStockMatchRows[0].label.includes('2/2')
    && dashboard.vanStockMatchRows[1].badge === 'STOCK_PARCIAL'
);
assert(
  '5.2 Los tonos son chips de lectura: verde, ámbar y rojo; nunca botones',
  dashboard.vanStockMatchChipClass('STOCK_COMPATIBLE') === 'vg-chip vg-chip-success'
    && dashboard.vanStockMatchChipClass('STOCK_PARCIAL') === 'vg-chip vg-chip-warning'
    && dashboard.vanStockMatchChipClass('SIN_STOCK_COMPATIBLE') === 'vg-chip vg-chip-danger'
);
assert(
  '5.3 El cruce se muestra en el modal unitario y en el lote',
  DASHBOARD_SOURCE.includes('data-testid="coord-assign-van-stock-match"')
    && DASHBOARD_SOURCE.includes('data-testid="coord-bulk-van-stock-match"')
    && DASHBOARD_SOURCE.includes('van_stock_match')
);

const scoreBefore = dashboard.assignmentSuggestions.ranked_technicians[0].total_score;
const breakdownBefore = JSON.stringify(dashboard.assignmentSuggestions.ranked_technicians[0].score_breakdown);
void dashboard.vanStockMatchRows;
assert(
  '5.4 El cliente no toca la puntuación ni el desglose al leer el cruce (Art. II)',
  dashboard.assignmentSuggestions.ranked_technicians[0].total_score === scoreBefore
    && JSON.stringify(dashboard.assignmentSuggestions.ranked_technicians[0].score_breakdown) === breakdownBefore
    && !/TechnicianWorkloadScorer/.test(DASHBOARD_SOURCE)
);

assert(
  '5.5 La bandeja pinta el chip de disponibilidad de la avería activa (QA 3)',
  DASHBOARD_SOURCE.includes('data-testid="row-van-stock-readiness"')
    && DASHBOARD_SOURCE.includes('inc.van_stock_readiness?.badge_label')
);

assert(
  '5.6 Mi Ruta pinta el mismo chip de disponibilidad',
  ROUTE_SOURCE.includes('data-testid="tech-stop-van-stock-readiness"')
    && ROUTE_SOURCE.includes('incident.van_stock_readiness?.badge_label')
    && TechnicianRouteView.methods.vanStockReadinessChipClass('SHARED_IN_VAN').includes('vg-chip-warning')
);

// =========================================================================
// BLOQUE 6: Sistema de diseño y Picking A4
// =========================================================================
console.log('\n--- BLOQUE 6: Sistema de diseño y Picking A4 ---');

assert(
  '6.1 La hoja de impresión declara A4 y @media print',
  /@media\s+print/.test(PICKING_CSS)
    && /size:\s*A4 portrait/.test(PICKING_CSS)
    && PICKING_CSS.includes('.vg-picking-sheet')
);
assert(
  '6.2 La hoja A4 usa solo tokens y no introduce literales hexadecimales',
  (PICKING_CSS.match(HEX_PATTERN) || []).length === 0
    && PICKING_CSS.includes('var(--color-ink)')
    && PICKING_CSS.includes('var(--font-display)')
);
assert(
  '6.3 El albarán declara casillas de verificación física y firmas, sin importes',
  COORDINATOR_SOURCE.includes('[ ]')
    && COORDINATOR_SOURCE.includes('Preparado por (almacén)')
    && COORDINATOR_SOURCE.includes('Recibido por (técnico)')
    && COORDINATOR_SOURCE.includes('sin importes (Art. V.4)')
);
assert(
  '6.4 Ni el componente de coordinación ni el del técnico declaran un hex',
  (COORDINATOR_SOURCE.match(HEX_PATTERN) || []).length === 0
    && (TECHNICIAN_SOURCE.match(HEX_PATTERN) || []).length === 0
);
assert(
  '6.5 Los botones nuevos visten `vg-btn` con peldaño y tono, sin geometría en línea',
  /<button[^>]*class="vg-btn vg-btn-sm vg-btn-primary"/.test(COORDINATOR_SOURCE)
    && /<button[^>]*class="vg-btn vg-btn-tab"/.test(COORDINATOR_SOURCE)
    && !/<button[^>]*\sstyle="/.test(COORDINATOR_SOURCE)
);

console.log('\n======================================================================');
console.log(` Total Asserts: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. CONDICIÓN T-RESTOCK-12 CUMPLIDA.');
  console.log('======================================================================');
  process.exit(0);
}
console.log(' RESULTADO: HAY FALLOS. REVISAR EL DETALLE ANTERIOR.');
console.log('======================================================================');
process.exit(1);
