/**
 * VendGuard - CoordinatorProductLoadingTab Test Suite
 *
 * Certifica la pestaña de Coordinación del Módulo 33 (RF-03.2, RF-05.1,
 * RNF-03, D1-D4, QA 1, Art. V.4):
 *   1. KPIs, filtros y carga de órdenes.
 *   2. Propuesta individual y emisión masiva.
 *   3. Edición, despacho térmico, liquidación y cancelación.
 *   4. Hoja de Picking A4 en dos secciones, sin importes.
 *   5. Stock por lote FEFO y ajuste auditado.
 *   6. Integración en el dashboard, cliente API y Service Worker.
 *
 * Dogma Vanilla: Node.js ESM nativo, sin dependencias externas.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const storageMock = (() => {
  let store = {};
  return {
    getItem: (key) => store[key] || null,
    setItem: (key, value) => { store[key] = String(value); },
    removeItem: (key) => { delete store[key]; },
    clear: () => { store = {}; }
  };
})();
globalThis.localStorage = storageMock;
globalThis.window = { location: { search: '', href: 'http://localhost/' } };

import { api } from '../../public/assets/js/api.js';
import { CoordinatorProductLoadingTab } from '../../public/assets/js/components/CoordinatorProductLoadingTab.js';
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
      console.error(`         Motivo: ${details}`);
    }
    failures++;
  }
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - CoordinatorProductLoadingTab');
console.log('======================================================================\n');

const restockers = [
  { id: 7, name: 'Marta Reponedora', operator_code: 'OP-07', is_active: true },
  { id: 8, name: 'Luis Cargador', operator_code: 'OP-08', is_active: true }
];

const orders = [
  {
    id: 101,
    order_code: 'PLO-2026-0101',
    restocker_id: 7,
    restocker_name: 'Marta Reponedora',
    restocker_is_active: true,
    restocker_currently_absent: false,
    status: 'PENDING_PICKING',
    status_label: 'En preparación (Picking)',
    demand_mode: 'ALERTS_ONLY',
    demand_mode_label: 'Solo alertas',
    safety_buffer_pct: 10,
    active_items_count: 2,
    total_requested_qty: 15,
    total_dispatched_qty: 0,
    created_at: '2026-11-13 07:15:00+00'
  },
  {
    id: 102,
    order_code: 'PLO-2026-0102',
    restocker_id: 8,
    restocker_name: 'Luis Cargador',
    restocker_is_active: true,
    restocker_currently_absent: false,
    status: 'DISPATCHED',
    status_label: 'Despachada',
    demand_mode: 'ALL_GAPS',
    demand_mode_label: 'Ruta completa',
    safety_buffer_pct: 5,
    active_items_count: 1,
    total_requested_qty: 8,
    total_dispatched_qty: 8,
    created_at: '2026-11-13 07:20:00+00'
  },
  {
    id: 103,
    order_code: 'PLO-2026-0103',
    restocker_id: 7,
    restocker_name: 'Marta Reponedora',
    restocker_is_active: true,
    restocker_currently_absent: false,
    status: 'CONFIRMED_BY_RESTOCKER',
    status_label: 'Recibida en Furgoneta',
    demand_mode: 'ALERTS_ONLY',
    demand_mode_label: 'Solo alertas',
    safety_buffer_pct: 10,
    active_items_count: 1,
    total_requested_qty: 10,
    total_dispatched_qty: 10,
    created_at: '2026-11-13 07:25:00+00'
  },
  {
    id: 104,
    order_code: 'PLO-2026-0104',
    restocker_id: 8,
    restocker_name: 'Luis Cargador',
    restocker_is_active: true,
    restocker_currently_absent: true,
    status: 'PENDING_PICKING',
    status_label: 'En preparación (Picking)',
    demand_mode: 'ALERTS_ONLY',
    demand_mode_label: 'Solo alertas',
    safety_buffer_pct: 10,
    active_items_count: 1,
    total_requested_qty: 4,
    total_dispatched_qty: 0,
    created_at: '2026-11-13 08:00:00+00'
  }
];

const coldItem = {
  id: 1,
  order_id: 101,
  product_id: 1,
  product_sku: 'SAND-MIXTO-01',
  product_name: 'Sándwich mixto',
  product_category: 'PERISHABLE_FOOD',
  product_category_label: 'Alimento perecedero',
  product_unit_type: 'UNIT',
  requires_cold_chain: true,
  reason: 'STOCKOUT_ALERT',
  reason_label: 'Rotura de stock',
  requested_qty: 10,
  dispatched_qty: null,
  batch_code: null,
  expiration_date: null,
  van_stock_before_reconcile: null,
  restocked_in_machines_qty: null,
  withdrawn_spoilage_qty: null,
  expected_return_qty: null,
  counted_return_qty: null,
  discrepancy_qty: null,
  is_active: true,
  is_dispatched: false,
  has_reconcile_data: false,
  has_discrepancy: false
};

const generalItem = {
  id: 2,
  order_id: 101,
  product_id: 2,
  product_sku: 'AGUA-50-01',
  product_name: 'Agua mineral 50 cl',
  product_category: 'COLD_DRINK',
  product_category_label: 'Bebida fría',
  product_unit_type: 'UNIT',
  requires_cold_chain: false,
  reason: 'LOW_STOCK_ALERT',
  reason_label: 'Bajo umbral',
  requested_qty: 5,
  dispatched_qty: null,
  batch_code: null,
  expiration_date: null,
  van_stock_before_reconcile: null,
  restocked_in_machines_qty: null,
  withdrawn_spoilage_qty: null,
  expected_return_qty: null,
  counted_return_qty: null,
  discrepancy_qty: null,
  is_active: true,
  is_dispatched: false,
  has_reconcile_data: false,
  has_discrepancy: false
};

const buildOrder = (overrides = {}) => ({
  ...orders[0],
  restocker_operator_code: 'OP-07',
  notes: 'Carro isotérmico separado.',
  cold_chamber_exit_temp_celsius: null,
  confirmed_at: null,
  reconciled_at: null,
  cancelled_at: null,
  ...overrides
});

const detailPending = {
  order: buildOrder(),
  items: [coldItem, generalItem],
  picking_sheet: null
};

const dispatchedItem = {
  ...coldItem,
  dispatched_qty: 10,
  batch_code: 'LOTE-SW-01',
  expiration_date: '2026-11-16',
  is_dispatched: true
};

const detailDispatched = {
  order: buildOrder({
    status: 'DISPATCHED',
    status_label: 'Despachada',
    cold_chamber_exit_temp_celsius: 3.5
  }),
  items: [dispatchedItem],
  picking_sheet: null
};

const detailConfirmed = {
  order: buildOrder({
    status: 'CONFIRMED_BY_RESTOCKER',
    status_label: 'Recibida en Furgoneta',
    cold_chamber_exit_temp_celsius: 3.5,
    confirmed_at: '2026-11-13 08:00:00+00'
  }),
  items: [dispatchedItem],
  picking_sheet: null
};

const pickingSheet = {
  order_code: 'PLO-2026-0101',
  created_at: '2026-11-13 07:15:00+00',
  restocker_name: 'Marta Reponedora',
  restocker_operator_code: 'OP-07',
  zones: [{ zone_code: 'ZN-01', zone_name: 'Zona Norte' }],
  demand_mode: 'ALERTS_ONLY',
  demand_mode_label: 'Solo alertas',
  safety_buffer_pct: 10,
  status: 'PENDING_PICKING',
  status_label: 'En preparación (Picking)',
  cold_chamber_exit_temp_celsius: 3.5,
  sections: {
    cold_chain: {
      title: '❄️ Cámara Frigorífica (Salida ≤ 4,0 °C · Art. II)',
      items: [
        {
          product_id: 1,
          sku: 'SAND-MIXTO-01',
          name: 'Sándwich mixto',
          unit_type: 'UNIT',
          batch_code: 'LOTE-SW-01',
          expiration_date: '2026-11-16',
          requested_qty: 10,
          dispatched_qty: 10,
          reason: 'STOCKOUT_ALERT',
          reason_label: 'Rotura de stock',
          checkbox: false
        }
      ]
    },
    general: {
      title: '📦 Almacén General y Consumibles',
      items: [
        {
          product_id: 2,
          sku: 'AGUA-50-01',
          name: 'Agua mineral 50 cl',
          unit_type: 'UNIT',
          batch_code: null,
          expiration_date: null,
          requested_qty: 5,
          dispatched_qty: 5,
          reason: 'LOW_STOCK_ALERT',
          reason_label: 'Bajo umbral',
          checkbox: false
        }
      ]
    }
  },
  totals: {
    cold_items_count: 1,
    general_items_count: 1,
    total_requested_qty: 15,
    total_dispatched_qty: 15
  }
};

const vanStocks = [
  {
    id: 1,
    restocker_id: 7,
    restocker_name: 'Marta Reponedora',
    restocker_operator_code: 'OP-07',
    product_id: 1,
    product_sku: 'SAND-MIXTO-01',
    product_name: 'Sándwich mixto',
    product_category: 'PERISHABLE_FOOD',
    product_category_label: 'Alimento perecedero',
    product_unit_type: 'UNIT',
    requires_cold_chain: true,
    batch_code: 'LOTE-SW-01',
    expiration_date: '2026-11-16',
    quantity_available: 10,
    usable_quantity: 10,
    expiry_status: 'EXPIRING_SOON',
    expiry_status_label: 'Caducidad próxima ≤ 48 h',
    expiry_status_icon: '🟡',
    is_expired: false
  },
  {
    id: 2,
    restocker_id: 8,
    restocker_name: 'Luis Cargador',
    restocker_operator_code: 'OP-08',
    product_id: 2,
    product_sku: 'AGUA-50-01',
    product_name: 'Agua mineral 50 cl',
    product_category: 'COLD_DRINK',
    product_category_label: 'Bebida fría',
    product_unit_type: 'UNIT',
    requires_cold_chain: false,
    batch_code: 'SIN-LOTE',
    expiration_date: null,
    quantity_available: 12,
    usable_quantity: 12,
    expiry_status: 'OK',
    expiry_status_label: 'Vigente',
    expiry_status_icon: '🟢',
    is_expired: false
  },
  {
    id: 3,
    restocker_id: 7,
    restocker_name: 'Marta Reponedora',
    restocker_operator_code: 'OP-07',
    product_id: 3,
    product_sku: 'YOGUR-01',
    product_name: 'Yogur natural',
    product_category: 'PERISHABLE_FOOD',
    product_category_label: 'Alimento perecedero',
    product_unit_type: 'UNIT',
    requires_cold_chain: true,
    batch_code: 'LOTE-ANTIGUO',
    expiration_date: '2026-11-11',
    quantity_available: 4,
    usable_quantity: 0,
    expiry_status: 'EXPIRED',
    expiry_status_label: 'Caducado',
    expiry_status_icon: '🔴',
    is_expired: true
  }
];

const proposal = {
  restocker: { ...restockers[0], is_currently_absent: false },
  zones: [{ zone_code: 'ZN-01', zone_name: 'Zona Norte' }],
  demand_mode: 'ALERTS_ONLY',
  demand_mode_label: 'Solo alertas',
  safety_buffer_pct: 10,
  total_van_stock_qty: 14,
  usable_van_stock_qty: 10,
  excluded_unavailable_machines_count: 1,
  total_suggested_qty: 11,
  items: [
    {
      product_id: 1,
      product_sku: 'SAND-MIXTO-01',
      product_name: 'Sándwich mixto',
      product_category: 'PERISHABLE_FOOD',
      product_unit_type: 'UNIT',
      requires_cold_chain: true,
      gap_qty: 8,
      gross_demand_qty: 9,
      usable_van_stock_qty: 0,
      suggested_qty: 9,
      reason: 'STOCKOUT_ALERT',
      reason_label: 'Rotura de stock',
      reason_priority: 1
    },
    {
      product_id: 2,
      product_sku: 'AGUA-50-01',
      product_name: 'Agua mineral 50 cl',
      product_category: 'COLD_DRINK',
      product_unit_type: 'UNIT',
      requires_cold_chain: false,
      gap_qty: 2,
      gross_demand_qty: 2,
      usable_van_stock_qty: 0,
      suggested_qty: 2,
      reason: 'LOW_STOCK_ALERT',
      reason_label: 'Bajo umbral',
      reason_priority: 2
    }
  ]
};

const absentProposal = {
  ...proposal,
  restocker: { ...restockers[1], is_currently_absent: true },
  items: [proposal.items[0]]
};

const products = [
  { id: 1, sku: 'SAND-MIXTO-01', name: 'Sándwich mixto', category: 'PERISHABLE_FOOD', requires_cold_chain: true },
  { id: 2, sku: 'AGUA-50-01', name: 'Agua mineral 50 cl', category: 'COLD_DRINK', requires_cold_chain: false }
];

const calls = [];
api.coordinator.getUsers = async (params) => {
  calls.push({ method: 'getUsers', params });
  return { data: restockers };
};
api.coordinator.getProductLoadingOrders = async (params) => {
  calls.push({ method: 'getProductLoadingOrders', params });
  return {
    data: {
      kpis: {
        pending_picking_count: 2,
        dispatched_count: 1,
        confirmed_in_route_count: 1,
        reconciled_today_count: 3
      },
      orders
    }
  };
};
api.coordinator.getProductLoadingOrder = async (orderId) => {
  calls.push({ method: 'getProductLoadingOrder', orderId });
  if (Number(orderId) === 103) return { data: detailConfirmed };
  if (Number(orderId) === 102) return { data: detailDispatched };
  return { data: detailPending };
};
api.coordinator.getProductLoadingPickingSheet = async (orderId) => {
  calls.push({ method: 'getProductLoadingPickingSheet', orderId });
  return { data: pickingSheet };
};
api.coordinator.getProductLoadingProposal = async (params) => {
  calls.push({ method: 'getProductLoadingProposal', params });
  return { data: Number(params.restocker_id) === 8 ? absentProposal : proposal };
};
api.coordinator.createProductLoadingOrder = async (payload) => {
  calls.push({ method: 'createProductLoadingOrder', payload });
  return { data: { order: { id: 105, order_code: 'PLO-2026-0105' }, items: payload.items } };
};
api.coordinator.generateProductLoadingBatch = async (payload) => {
  calls.push({ method: 'generateProductLoadingBatch', payload });
  return {
    data: {
      created: [{ order_id: 106, order_code: 'PLO-2026-0106', restocker_name: 'Marta Reponedora' }],
      skipped: [{ restocker_id: 8, restocker_name: 'Luis Cargador', reason: 'ABSENT', reason_label: 'Ausencia vigente' }],
      created_count: 1,
      skipped_count: 1
    }
  };
};
api.coordinator.updateProductLoadingOrder = async (orderId, payload) => {
  calls.push({ method: 'updateProductLoadingOrder', orderId, payload });
  return { data: { ...detailPending, order: { ...detailPending.order, safety_buffer_pct: payload.safety_buffer_pct } } };
};
api.coordinator.dispatchProductLoadingOrder = async (orderId, payload) => {
  calls.push({ method: 'dispatchProductLoadingOrder', orderId, payload });
  return {
    data: {
      ...detailDispatched,
      order: { ...detailDispatched.order, cold_chamber_exit_temp_celsius: payload.cold_chamber_exit_temp_celsius }
    }
  };
};
api.coordinator.reconcileProductLoadingOrder = async (orderId, payload) => {
  calls.push({ method: 'reconcileProductLoadingOrder', orderId, payload });
  return {
    data: {
      ...detailConfirmed,
      order: { ...detailConfirmed.order, status: 'RECONCILED_AT_CLOSE', status_label: 'Liquidada al Cierre' }
    }
  };
};
api.coordinator.cancelProductLoadingOrder = async (orderId, reason) => {
  calls.push({ method: 'cancelProductLoadingOrder', orderId, reason });
  return { data: { order: { id: orderId, status: 'CANCELLED', status_label: 'Cancelada' } } };
};
api.coordinator.getRestockerVanStocks = async (params) => {
  calls.push({ method: 'getRestockerVanStocks', params });
  return {
    data: {
      kpis: {
        total_lots: 3,
        total_units: 26,
        usable_units: 22,
        expired_lots: 1,
        expiring_soon_lots: 1
      },
      stocks: vanStocks
    }
  };
};
api.coordinator.adjustRestockerVanStock = async (restockerId, payload) => {
  calls.push({ method: 'adjustRestockerVanStock', restockerId, payload });
  return {
    data: {
      stock: { ...vanStocks[0], quantity_available: payload.quantity_available },
      previous_quantity: 10,
      reason: payload.reason,
      audit_event_id: 9001
    }
  };
};
api.coordinator.getProducts = async (params) => {
  calls.push({ method: 'getProducts', params });
  return { data: { products } };
};

function buildInstance() {
  const instance = { ...CoordinatorProductLoadingTab.data() };
  Object.assign(instance, CoordinatorProductLoadingTab.methods);
  for (const [key, getter] of Object.entries(CoordinatorProductLoadingTab.computed)) {
    Object.defineProperty(instance, key, { get: getter });
  }
  return instance;
}

async function runTests() {
  const component = buildInstance();

  // ─────────────────────────────────────────────────────────────────────
  // 1. Órdenes, KPIs y filtros
  // ─────────────────────────────────────────────────────────────────────
  console.log('--- 1. Órdenes, KPIs y filtros ---');

  await component.loadOrders();
  assert('1.1 Carga las cuatro órdenes y los KPIs del servicio', component.orders.length === 4 && component.orderKpis.pending_picking_count === 2);
  assert('1.2 La sub-vista inicial es la de órdenes', component.activeSection === 'orders');

  component.orderFilters.status = 'PENDING_PICKING';
  await component.loadOrders();
  const statusCall = calls.filter((call) => call.method === 'getProductLoadingOrders').at(-1);
  assert('1.3 El filtro de estado viaja al API', statusCall?.params?.status === 'PENDING_PICKING');
  component.orderFilters.status = '';

  await component.loadRestockers();
  assert('1.4 Carga dos reponedores y preselecciona el primero', component.restockers.length === 2 && String(component.proposalForm.restocker_id) === '7');
  assert('1.5 Las acciones dependen del estado de la orden', (() => {
    const pending = component.orderActions(orders[0]);
    const dispatched = component.orderActions(orders[1]);
    const confirmed = component.orderActions(orders[2]);
    return pending.edit && pending.dispatch && pending.cancel
      && !dispatched.edit && dispatched.cancel && !dispatched.dispatch
      && !confirmed.edit && !confirmed.cancel && confirmed.reconcile;
  })());
  assert(
    '1.6 Un destinatario ausente muestra el aviso C2',
    component.restockerWarning(orders[3]) === '⚠️ Destinatario actualmente ausente'
  );
  assert(
    '1.7 El semáforo de estados usa clases del sistema',
    component.statusTone('PENDING_PICKING').includes('vg-badge-warning')
      && component.statusTone('CANCELLED').includes('vg-badge-danger')
  );

  // ─────────────────────────────────────────────────────────────────────
  // 2. Propuesta y emisión masiva
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 2. Propuesta y emisión masiva ---');

  await component.openProposalModal();
  component.proposalForm.demand_mode = 'ALL_GAPS';
  component.proposalForm.safety_buffer_pct = 15;
  await component.loadProposal();
  const proposalCall = calls.find((call) => call.method === 'getProductLoadingProposal');
  assert(
    '2.1 La propuesta envía reponedor, modo y colchón',
    component.proposal?.excluded_unavailable_machines_count === 1
      && String(proposalCall?.params?.restocker_id) === '7'
      && proposalCall?.params?.demand_mode === 'ALL_GAPS'
      && proposalCall?.params?.safety_buffer_pct === 15
  );
  assert('2.2 La propuesta expone zonas y total sugerido', component.proposal.zones[0].zone_name === 'Zona Norte' && component.proposalTotalSuggested === 11);
  assert(
    '2.3 El aviso QA 1 informa de las máquinas omitidas',
    CoordinatorProductLoadingTab.template.includes('máquinas omitidas por cuarentena o fuera de servicio')
  );

  component.proposal.items[1].suggested_qty = 0;
  await component.createOrderFromProposal();
  const createCall = calls.find((call) => call.method === 'createProductLoadingOrder');
  assert(
    '2.4 La emisión individual envía solo líneas con cantidad y el motivo',
    Boolean(createCall)
      && createCall.payload.restocker_id === 7
      && createCall.payload.items.length === 1
      && createCall.payload.items[0].requested_qty === 9
      && createCall.payload.items[0].reason === 'STOCKOUT_ALERT'
  );
  assert('2.5 El alta cierra el diálogo y recarga el listado', component.showProposalModal === false && component.orderSuccess.includes('PLO-2026-0105'));

  const absentComponent = buildInstance();
  await absentComponent.loadRestockers();
  absentComponent.proposalForm.restocker_id = 8;
  await absentComponent.openProposalModal();
  await absentComponent.loadProposal();
  assert(
    '2.5b La propuesta de un ausente muestra la alerta C2',
    absentComponent.proposalRecipientWarning === '⚠️ Destinatario actualmente ausente'
      && absentComponent.proposal?.restocker?.is_currently_absent === true
      && CoordinatorProductLoadingTab.template.includes('product-loading-proposal-absent-warning')
  );
  await absentComponent.createOrderFromProposal();
  const absentCreateCall = calls.filter((call) => call.method === 'createProductLoadingOrder').at(-1);
  assert(
    '2.5c La orden individual del ausente se emite desde su propuesta',
    absentCreateCall?.payload?.restocker_id === 8
      && absentCreateCall.payload.items.length === 1
      && absentCreateCall.payload.items[0].requested_qty === 9
      && absentComponent.showProposalModal === false
  );

  component.openBatchModal();
  component.batchForm.demand_mode = 'ALL_GAPS';
  await component.generateBatch();
  const batchCall = calls.find((call) => call.method === 'generateProductLoadingBatch');
  assert(
    '2.6 La emisión masiva envía el modo y publica creados/omitidos',
    batchCall?.payload?.demand_mode === 'ALL_GAPS'
      && component.batchResult.created_count === 1
      && component.batchResult.skipped_count === 1
  );
  component.showBatchModal = false;

  // ─────────────────────────────────────────────────────────────────────
  // 3. Edición, despacho, liquidación y cancelación
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 3. Edición, despacho, liquidación y cancelación ---');

  await component.openEditModal(101);
  assert('3.1 La edición carga las dos líneas activas', component.showEditModal === true && component.editItems.length === 2);
  component.editItems[0].requested_qty = 12;
  component.editForm.safety_buffer_pct = 20;
  await component.saveOrder();
  const updateCall = calls.find((call) => call.method === 'updateProductLoadingOrder');
  assert(
    '3.2 Guardar envía el UPSERT de líneas y el colchón',
    Boolean(updateCall)
      && updateCall.orderId === 101
      && updateCall.payload.items.length === 2
      && updateCall.payload.items[0].requested_qty === 12
      && updateCall.payload.safety_buffer_pct === 20
  );
  component.editItems.splice(0, component.editItems.length);
  await component.saveOrder();
  assert('3.3 Una orden no puede quedarse sin líneas activas', component.editError.includes('al menos una línea'));
  component.showEditModal = false;

  await component.openDispatchModal(101);
  assert('3.4 El despacho precarga lo solicitado y detecta frío', component.dispatchForm.items.length === 2 && component.dispatchHasColdLine === true);
  component.dispatchForm.items[0].dispatched_qty = 10;
  component.dispatchForm.items[0].batch_code = 'LOTE-SW-01';
  component.dispatchForm.items[0].expiration_date = '2026-11-16';
  component.dispatchForm.cold_chamber_exit_temp_celsius = '5.2';
  assert('3.5 Una cámara a 5,2 °C bloquea el despacho (Art. II)', component.validateDispatchForm() === false && component.dispatchError.includes('4,0'));
  component.dispatchForm.cold_chamber_exit_temp_celsius = '3.5';
  assert('3.6 Una cámara a 3,5 °C con lote válido permite continuar', component.validateDispatchForm() === true);
  await component.submitDispatch();
  const dispatchCall = calls.find((call) => call.method === 'dispatchProductLoadingOrder');
  assert(
    '3.7 El despacho envía temperatura, lote y caducidad de la línea',
    Boolean(dispatchCall)
      && dispatchCall.orderId === 101
      && dispatchCall.payload.cold_chamber_exit_temp_celsius === 3.5
      && dispatchCall.payload.items[0].batch_code === 'LOTE-SW-01'
      && dispatchCall.payload.items[0].expiration_date === '2026-11-16'
  );
  component.showDispatchModal = false;

  await component.loadVanStocks();
  assert('3.8 El stock FEFO carga tres lotes y sus KPIs', component.vanStocks.length === 3 && component.stockKpis.expired_lots === 1);

  await component.openReconcileModal(103);
  assert('3.9 La liquidación toma el saldo real del lote', component.reconcileForm.items.length === 1 && component.reconcileForm.items[0].van_stock_before_reconcile === 10);
  const line = component.reconcileForm.items[0];
  line.restocked_in_machines_qty = 6;
  line.withdrawn_spoilage_qty = 0;
  line.counted_return_qty = 3;
  assert('3.10 El cálculo en vivo marca el descuadre −1', component.reconcileExpected(line) === 4 && component.reconcileDiscrepancy(line) === -1);
  assert('3.11 Un descuadre sin justificación suficiente no se liquida', component.validateReconciliation() === false && component.reconcileError.includes('20'));
  component.reconcileForm.notes = 'Merma no documentada en la ruta de esta mañana.';
  assert('3.12 Una justificación real de 20+ caracteres desbloquea el cuadre', component.validateReconciliation() === true);
  await component.submitReconciliation();
  const reconcileCall = calls.find((call) => call.method === 'reconcileProductLoadingOrder');
  assert(
    '3.13 La liquidación envía el cuadre por producto y la nota',
    Boolean(reconcileCall)
      && reconcileCall.payload.items[0].product_id === 1
      && reconcileCall.payload.items[0].restocked_in_machines_qty === 6
      && reconcileCall.payload.items[0].counted_return_qty === 3
      && reconcileCall.payload.reconciliation_notes.length >= 20
  );
  component.showReconcileModal = false;

  component.openCancelModal(orders[0]);
  component.cancelReason = 'Demasiado corta';
  await component.submitCancellation();
  assert('3.14 Un motivo de cancelación corto se rechaza en cliente', component.cancelError.includes('20') && !calls.some((call) => call.method === 'cancelProductLoadingOrder'));
  component.cancelReason = 'Pedido duplicado detectado antes de cargar la furgoneta.';
  await component.submitCancellation();
  const cancelCall = calls.find((call) => call.method === 'cancelProductLoadingOrder');
  assert(
    '3.15 La cancelación válida envía el motivo justificado',
    Boolean(cancelCall) && cancelCall.orderId === 101 && cancelCall.reason.length >= 20
  );
  component.showCancelModal = false;

  // ─────────────────────────────────────────────────────────────────────
  // 4. Hoja de Picking A4
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 4. Hoja de Picking A4 ---');

  await component.openPickingSheet(101);
  assert(
    '4.1 La Hoja A4 se carga con sus dos secciones',
    component.pickingSheet?.sections?.cold_chain?.items.length === 1
      && component.pickingSheet?.sections?.general?.items.length === 1
  );
  assert(
    '4.2 La sección de frío declara la temperatura y el límite Art. II',
    CoordinatorProductLoadingTab.template.includes('Sección 1: Cámara Frigorífica')
      && CoordinatorProductLoadingTab.template.includes('Salida ≤ 4,0 °C · Art. II')
  );
  assert(
    '4.3 La hoja incluye firmas de almacén y reponedor',
    CoordinatorProductLoadingTab.template.includes('Entregado por (Almacén)')
      && CoordinatorProductLoadingTab.template.includes('Recibido por (Reponedor)')
  );
  let printed = false;
  const previousPrint = globalThis.print;
  globalThis.print = () => { printed = true; };
  component.printPickingSheet();
  globalThis.print = previousPrint;
  assert('4.4 La impresión usa window.print nativo', printed === true);
  component.showPickingModal = false;

  // ─────────────────────────────────────────────────────────────────────
  // 5. Stock por lote y ajuste auditado
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 5. Stock por lote y ajuste auditado ---');

  component.stockFilters.expiry_status = 'EXPIRED';
  assert('5.1 El filtro de caducidad deja el lote caducado', component.filteredVanStocks.length === 1 && component.filteredVanStocks[0].batch_code === 'LOTE-ANTIGUO');
  component.stockFilters.expiry_status = '';
  component.stockFilters.restocker_id = 7;
  assert('5.2 El filtro por reponedor aísla sus dos lotes', component.filteredVanStocks.length === 2);
  component.stockFilters.restocker_id = '';
  assert('5.3 El semáforo traduce EXPIRED y OK', component.expiryTone('EXPIRED').includes('vg-badge-danger') && component.expiryTone('OK').includes('vg-badge-success'));

  await component.openStockAdjustModal(vanStocks[0]);
  assert('5.4 El ajuste precarga lote, caducidad y saldo', component.stockForm.batch_code === 'LOTE-SW-01' && component.stockForm.quantity_available === 10);
  component.stockForm.quantity_available = 8;
  component.stockForm.reason = 'Retirada breve';
  await component.submitStockAdjustment();
  assert('5.5 Un motivo corto bloquea el ajuste', component.stockFormError.includes('20') && !calls.some((call) => call.method === 'adjustRestockerVanStock'));
  component.stockForm.reason = 'Retirada de dos unidades dañadas durante la carga.';
  await component.submitStockAdjustment();
  const adjustCall = calls.find((call) => call.method === 'adjustRestockerVanStock');
  assert(
    '5.6 El ajuste auditado envía saldo, lote y motivo',
    Boolean(adjustCall)
      && adjustCall.restockerId === 7
      && adjustCall.payload.product_id === 1
      && adjustCall.payload.quantity_available === 8
      && adjustCall.payload.reason.length >= 20
  );
  assert('5.7 El ajuste no envía operaciones de borrado', !calls.some((call) => String(call.method).toLowerCase().includes('delete')));

  // ─────────────────────────────────────────────────────────────────────
  // 6. Integración, API y sistema de diseño
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 6. Integración, API y sistema de diseño ---');

  assert('6.1 El dashboard registra CoordinatorProductLoadingTab', CoordinatorDashboardView.components.CoordinatorProductLoadingTab === CoordinatorProductLoadingTab);
  assert('6.2 El dashboard incluye la pestaña tab-product-loading', CoordinatorDashboardView.template.includes('data-testid="tab-product-loading"'));
  assert(
    '6.3 El dashboard renderiza la pestaña con v-else-if',
    CoordinatorDashboardView.template.includes('<CoordinatorProductLoadingTab v-else-if="activeTab === \'product-loading\'"')
  );
  assert(
    '6.4 El panel ofrece las sub-vistas de órdenes y stock',
    CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-orders-section"')
      && CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-stock-section"')
  );
  assert(
    '6.5 El componente usa tokens CSS y no hexadecimales',
    CoordinatorProductLoadingTab.template.includes('var(--color-primary)')
      && CoordinatorProductLoadingTab.template.includes('var(--color-error-text)')
  );

  const source = readFileSync(
    fileURLToPath(new URL('../../public/assets/js/components/CoordinatorProductLoadingTab.js', import.meta.url)),
    'utf8'
  );
  assert('6.6 El componente no introduce ningún hexadecimal', !/#[0-9a-fA-F]{3,8}\b/.test(source));
  assert('6.7 La Hoja A4 no proyecta costes ni precios', !/unit_cost_eur|sale_price_eur/.test(source));

  const swSource = readFileSync(fileURLToPath(new URL('../../public/sw.js', import.meta.url)), 'utf8');
  assert(
    '6.8 El Service Worker precachea el componente nuevo',
    swSource.includes('/assets/js/components/CoordinatorProductLoadingTab.js')
      && swSource.includes('vendguard-app-shell-v19')
  );

  const apiMethods = [
    'getRestockerVanStocks',
    'adjustRestockerVanStock',
    'getProductLoadingProposal',
    'getProductLoadingOrders',
    'getProductLoadingOrder',
    'getProductLoadingPickingSheet',
    'createProductLoadingOrder',
    'generateProductLoadingBatch',
    'updateProductLoadingOrder',
    'dispatchProductLoadingOrder',
    'reconcileProductLoadingOrder',
    'cancelProductLoadingOrder'
  ];
  assert(
    '6.9 El API expone las doce operaciones del módulo',
    apiMethods.every((method) => typeof api.coordinator[method] === 'function')
  );

  console.log('\n======================================================================');
  if (failures === 0) {
    console.log(` RESULTADO: ¡TODAS LAS PRUEBAS PASARON (${assertions} aserciones, 0 fallos)!`);
    console.log(' PESTAÑA DE ÓRDENES DE CARGA Y STOCK POR LOTE VERIFICADA.');
  } else {
    console.error(` RESULTADO: ${failures} PRUEBA(S) FALLIDA(S) de ${assertions} evaluadas.`);
  }
  console.log('======================================================================\n');

  if (failures > 0) {
    process.exit(1);
  }
}

runTests().catch((error) => {
  console.error('Error fatal en ejecución de tests:', error);
  process.exit(1);
});
