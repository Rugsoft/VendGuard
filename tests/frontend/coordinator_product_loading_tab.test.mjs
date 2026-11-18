/**
 * VendGuard - CoordinatorProductLoadingTab Test Suite
 *
 * Certifica la pestaña de Coordinación de los Módulos 33 y 34 (RF-03.2,
 * RF-05.1, RF-05.2, RNF-03, D1-D4, QA 1, QA 3, Art. V.4):
 *   1. KPIs, filtros y carga de órdenes.
 *   2. Propuesta individual y emisión masiva.
 *   3. Edición, despacho térmico, liquidación y cancelación.
 *   4. Hoja de Picking A4 en dos secciones, sin importes.
 *   5. Stock por lote FEFO y ajuste auditado.
 *   6. Integración en el dashboard, cliente API y Service Worker.
 *   7. Visitas de reposición in situ, cinco KPIs y expediente FEFO.
 *   8. Recaudación, cambio y arqueo de caja, seis KPIs, recepción y CSV.
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

const restockVisits = [
  {
    id: 501,
    visit_code: 'MRV-2026-0501',
    machine_id: 1,
    machine_code: 'VEND-RVF10-01',
    machine_model: 'RVF 10 Food',
    machine_type: 'PERISHABLE_FOOD',
    location_name: 'Sede Central · Hall',
    restocker_id: 7,
    restocker_name: 'Marta Reponedora',
    status: 'COMPLETED',
    status_label: 'Completada',
    status_icon: '✅',
    started_at: '2026-11-13 08:10:00+00',
    completed_at: '2026-11-13 08:42:00+00',
    cabin_temp_celsius: 3.4,
    triggered_incident_id: null,
    triggered_ticket_code: null,
    units_added: 13,
    units_withdrawn: 2,
    commercial_spoilage_loss_eur: 2.5,
    created_at: '2026-11-13 08:10:00+00'
  },
  {
    id: 502,
    visit_code: 'MRV-2026-0502',
    machine_id: 2,
    machine_code: 'VEND-RVF10-02',
    machine_model: 'RVF 10 Food',
    machine_type: 'PERISHABLE_FOOD',
    location_name: 'Sede Norte · Cafetería',
    restocker_id: 8,
    restocker_name: 'Luis Cargador',
    status: 'ABORTED_THERMAL_BREACH',
    status_label: 'Abortada por temperatura',
    status_icon: '🌡️',
    started_at: '2026-11-13 09:05:00+00',
    completed_at: '2026-11-13 09:30:00+00',
    cabin_temp_celsius: 8.6,
    triggered_incident_id: 77,
    triggered_ticket_code: 'SAT-2026-0007',
    units_added: 0,
    units_withdrawn: 2,
    commercial_spoilage_loss_eur: 6.5,
    created_at: '2026-11-13 09:05:00+00'
  }
];

const visitKpis = {
  completed_visits_count: 1,
  aborted_thermal_visits_count: 1,
  total_units_added: 13,
  total_units_withdrawn: 4,
  total_commercial_spoilage_loss_eur: 9.0
};

const visitDetail = {
  ...restockVisits[0],
  hygiene_checklist: {
    coffee_grounds_emptied: true,
    liquid_waste_bucket_cleaned: true,
    mixers_and_ducts_rinsed: false,
    dispensing_tray_and_glass_cleaned: true,
    cabin_spills_checked: null
  },
  precautionary_seal_applied: true,
  photo_evidence_url: '/uploads/restock/visit-501.jpg',
  internal_notes: 'Reposición completada con retirada de dos unidades caducadas.',
  client_mutation_id: '8f2c1f9e-8d0e-4f47-9a9c-1e7f3b2a6c11',
  is_thermal_abort: false,
  items: [
    {
      id: 1,
      visit_id: 501,
      slot_id: 10,
      slot_code: 'A1',
      product_id: 1,
      qty_before: 2,
      qty_withdrawn_expired: 1,
      qty_withdrawn_damaged: 1,
      qty_added: 6,
      qty_after: 6,
      withdrawn_batch_code: 'LOTE-ANTIGUO',
      withdrawn_expiration_date: '2026-11-11',
      loaded_batch_code: 'LOTE-SW-01',
      loaded_expiration_date: '2026-11-16',
      unit_cost_snapshot_eur: 1.25,
      commercial_spoilage_loss_eur: 2.5,
      created_at: '2026-11-13 08:42:00+00'
    },
    {
      id: 2,
      visit_id: 501,
      slot_id: 11,
      slot_code: 'A2',
      product_id: 2,
      qty_before: 4,
      qty_withdrawn_expired: 0,
      qty_withdrawn_damaged: 0,
      qty_added: 7,
      qty_after: 11,
      withdrawn_batch_code: null,
      withdrawn_expiration_date: null,
      loaded_batch_code: 'LOTE-SW-02',
      loaded_expiration_date: '2026-11-20',
      unit_cost_snapshot_eur: 0.4,
      commercial_spoilage_loss_eur: 0,
      created_at: '2026-11-13 08:42:00+00'
    }
  ]
};

const cashCollections = [
  {
    id: 9001,
    collection_code: 'MCC-2026-9001',
    restock_visit_id: 501,
    machine_id: 1,
    machine_code_snapshot: 'VEND-RVF10-01',
    restocker_id: 7,
    restocker_name_snapshot: 'Marta Reponedora',
    restocker_code_snapshot: 'OP-07',
    bag_seal_code: 'BAG-2026-009101',
    change_tubes_loaded_eur: 10,
    sales_counter_reading: 1350,
    previous_sales_counter_reading: 1200,
    initial_baseline_reading_eur: 0,
    is_counter_reset: false,
    counter_reset_notes: null,
    expected_cashbox_eur: 150,
    reception_cash_delivered_eur: 0,
    receptionist_name: null,
    linked_refund_request_id: null,
    route_notes: null,
    status: 'SEALED_IN_ROUTE',
    status_label: 'Sellada en ruta',
    collected_at: '2026-11-13 08:42:00+00',
    delivered_to_vault_at: null,
    vault_counted_eur: null,
    discrepancy_eur: null,
    vault_reconciled_by_name_snapshot: null,
    vault_reconciled_at: null,
    discrepancy_justification: null,
    is_terminal: false,
    location_code: 'SEDE-CENTRAL',
    location_name: 'Sede Central · Hall'
  },
  {
    id: 9002,
    collection_code: 'MCC-2026-9002',
    restock_visit_id: null,
    machine_id: 2,
    machine_code_snapshot: 'VEND-RVF10-02',
    restocker_id: 8,
    restocker_name_snapshot: 'Luis Cargador',
    restocker_code_snapshot: 'OP-08',
    bag_seal_code: 'BAG-2026-009102',
    change_tubes_loaded_eur: 0,
    sales_counter_reading: 1080,
    previous_sales_counter_reading: 1000,
    initial_baseline_reading_eur: 0,
    is_counter_reset: false,
    counter_reset_notes: null,
    expected_cashbox_eur: 80,
    reception_cash_delivered_eur: 2.5,
    receptionist_name: 'Carlos Ruiz',
    linked_refund_request_id: 44,
    route_notes: null,
    status: 'DELIVERED_TO_VAULT',
    status_label: 'En cámara',
    collected_at: '2026-11-13 09:30:00+00',
    delivered_to_vault_at: '2026-11-13 10:00:00+00',
    vault_counted_eur: null,
    discrepancy_eur: null,
    vault_reconciled_by_name_snapshot: null,
    vault_reconciled_at: null,
    discrepancy_justification: null,
    is_terminal: false,
    location_code: 'SEDE-NORTE',
    location_name: 'Sede Norte · Cafetería'
  },
  {
    id: 9003,
    collection_code: 'MCC-2026-9003',
    restock_visit_id: null,
    machine_id: 1,
    machine_code_snapshot: 'VEND-RVF10-01',
    restocker_id: 7,
    restocker_name_snapshot: 'Marta Reponedora',
    restocker_code_snapshot: 'OP-07',
    bag_seal_code: 'BAG-2026-009103',
    change_tubes_loaded_eur: 5,
    sales_counter_reading: 1200,
    previous_sales_counter_reading: 1100,
    initial_baseline_reading_eur: 0,
    is_counter_reset: false,
    counter_reset_notes: null,
    expected_cashbox_eur: 100,
    reception_cash_delivered_eur: 0,
    receptionist_name: null,
    linked_refund_request_id: null,
    route_notes: null,
    status: 'RECONCILED_OK',
    status_label: 'Arqueada OK',
    collected_at: '2026-11-12 08:00:00+00',
    delivered_to_vault_at: '2026-11-12 09:00:00+00',
    vault_counted_eur: 100,
    discrepancy_eur: 0,
    vault_reconciled_by_name_snapshot: 'Coordinación Central',
    vault_reconciled_at: '2026-11-12 09:15:00+00',
    discrepancy_justification: null,
    is_terminal: true,
    location_code: 'SEDE-CENTRAL',
    location_name: 'Sede Central · Hall'
  },
  {
    id: 9004,
    collection_code: 'MCC-2026-9004',
    restock_visit_id: null,
    machine_id: 2,
    machine_code_snapshot: 'VEND-RVF10-02',
    restocker_id: 8,
    restocker_name_snapshot: 'Luis Cargador',
    restocker_code_snapshot: 'OP-08',
    bag_seal_code: 'BAG-2026-009104',
    change_tubes_loaded_eur: 0,
    sales_counter_reading: 1210,
    previous_sales_counter_reading: 1000,
    initial_baseline_reading_eur: 0,
    is_counter_reset: false,
    counter_reset_notes: null,
    expected_cashbox_eur: 210,
    reception_cash_delivered_eur: 0,
    receptionist_name: null,
    linked_refund_request_id: null,
    route_notes: null,
    status: 'DISCREPANCY_FLAGGED',
    status_label: 'Con descuadre',
    collected_at: '2026-11-11 08:00:00+00',
    delivered_to_vault_at: '2026-11-11 09:00:00+00',
    vault_counted_eur: 205,
    discrepancy_eur: -5,
    vault_reconciled_by_name_snapshot: 'Coordinación Central',
    vault_reconciled_at: '2026-11-11 09:15:00+00',
    discrepancy_justification: 'Faltan cinco euros del cambio entregado en la ruta de la mañana.',
    is_terminal: true,
    location_code: 'SEDE-NORTE',
    location_name: 'Sede Norte · Cafetería'
  }
];

const cashKpis = {
  pending_vault_bags_count: 2,
  reconciled_ok_count: 1,
  discrepancy_flagged_count: 1,
  total_vault_counted_eur: 305.50,
  net_discrepancy_eur: -5,
  total_route_cash_deployed_eur: 17.50
};

const cashReceipt = {
  collection_code: 'MCC-2026-9002',
  collected_at: '2026-11-13 09:30:00+00',
  location_code: 'SEDE-NORTE',
  location_name: 'Sede Norte · Cafetería',
  machine_code: 'VEND-RVF10-02',
  restocker_name: 'Luis Cargador',
  receptionist_name: 'Carlos Ruiz',
  reception_cash_delivered_eur: 2.5,
  linked_refund_request_id: 44
};

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
api.coordinator.getRestockVisits = async (params) => {
  calls.push({ method: 'getRestockVisits', params });
  return { data: { visits: restockVisits, kpis: visitKpis } };
};
api.coordinator.getRestockVisit = async (visitId) => {
  calls.push({ method: 'getRestockVisit', visitId });
  if (Number(visitId) === 999) {
    throw new Error('No existe la visita de reposición solicitada.');
  }
  return { data: visitDetail };
};
api.coordinator.getCashCollections = async (params) => {
  calls.push({ method: 'getCashCollections', params });
  return { data: { cash_collections: cashCollections, kpis: cashKpis } };
};
api.coordinator.getCashCollection = async (collectionId) => {
  calls.push({ method: 'getCashCollection', collectionId });
  const collection = cashCollections.find((item) => String(item.id) === String(collectionId));
  return { data: collection ? { ...collection } : null };
};
api.coordinator.deliverCashCollectionToVault = async (collectionId) => {
  calls.push({ method: 'deliverCashCollectionToVault', collectionId });
  return {
    data: {
      ...cashCollections[0],
      id: Number(collectionId),
      status: 'DELIVERED_TO_VAULT',
      status_label: 'En cámara',
      delivered_to_vault_at: '2026-11-13 10:00:00+00'
    }
  };
};
api.coordinator.reconcileCashCollection = async (collectionId, payload) => {
  calls.push({ method: 'reconcileCashCollection', collectionId, payload });
  const expected = Number(cashCollections.find((item) => String(item.id) === String(collectionId))?.expected_cashbox_eur || 0);
  const discrepancy = Math.round((Number(payload.vault_counted_eur) - expected) * 100) / 100;
  return {
    data: {
      ...cashCollections[0],
      id: Number(collectionId),
      status: Math.abs(discrepancy) <= 1 ? 'RECONCILED_OK' : 'DISCREPANCY_FLAGGED',
      status_label: Math.abs(discrepancy) <= 1 ? 'Arqueada OK' : 'Con descuadre',
      vault_counted_eur: Number(payload.vault_counted_eur),
      discrepancy_eur: discrepancy,
      discrepancy_justification: payload.discrepancy_justification || null,
      vault_reconciled_by_name_snapshot: 'Coordinación Central',
      vault_reconciled_at: '2026-11-13 10:15:00+00',
      is_terminal: true
    }
  };
};
api.coordinator.getCashCollectionReceptionReceipt = async (collectionId) => {
  calls.push({ method: 'getCashCollectionReceptionReceipt', collectionId });
  return { data: cashReceipt };
};
api.coordinator.exportCashCollectionsCsv = async (params) => {
  calls.push({ method: 'exportCashCollectionsCsv', params });
  return true;
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
    CoordinatorDashboardView.template.includes('<CoordinatorProductLoadingTab')
      && CoordinatorDashboardView.template.includes('v-else-if="activeTab === \'product-loading\'"')
      && CoordinatorDashboardView.template.includes('@open-incident-detail="openIncidentDetailFromPreventive"')
  );
  assert(
    '6.4 El panel ofrece las cuatro sub-vistas: órdenes, stock, visitas y recaudación',
    CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-orders-section"')
      && CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-stock-section"')
      && CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-visits-section"')
      && CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-cash-section"')
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
      && swSource.includes('vendguard-app-shell-v25')
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
    'cancelProductLoadingOrder',
    'getRestockVisits',
    'getRestockVisit',
    'getCashCollections',
    'getCashCollection',
    'deliverCashCollectionToVault',
    'reconcileCashCollection',
    'getCashCollectionReceptionReceipt',
    'exportCashCollectionsCsv'
  ];
  assert(
    '6.9 El API expone las veinte operaciones del módulo',
    apiMethods.every((method) => typeof api.coordinator[method] === 'function')
  );

  // ─────────────────────────────────────────────────────────────────────
  // 7. Visitas de reposición in situ y control FEFO
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 7. Visitas de reposición in situ y control FEFO ---');

  const visitsComponent = buildInstance();
  await visitsComponent.loadVisits();
  assert(
    '7.1 Carga el libro de visitas y sus cinco KPIs ejecutivos',
    visitsComponent.visits.length === 2
      && visitsComponent.visitKpis.completed_visits_count === 1
      && visitsComponent.visitKpis.aborted_thermal_visits_count === 1
      && visitsComponent.visitKpis.total_units_added === 13
      && visitsComponent.visitKpis.total_units_withdrawn === 4
      && visitsComponent.visitKpis.total_commercial_spoilage_loss_eur === 9
  );
  assert(
    '7.2 El semáforo de visitas distingue completada y aborto térmico',
    visitsComponent.visitStatusTone('COMPLETED').includes('vg-badge-success')
      && visitsComponent.visitStatusTone('ABORTED_THERMAL_BREACH').includes('vg-badge-danger')
  );

  visitsComponent.visitFilters = {
    restocker_id: 7,
    machine_id: '',
    status: 'COMPLETED',
    date_from: '2026-11-01',
    date_to: ''
  };
  await visitsComponent.loadVisits();
  const visitCall = calls.filter((call) => call.method === 'getRestockVisits').at(-1);
  assert(
    '7.3 Los filtros saneados viajan al API sin parámetros vacíos',
    visitCall?.params?.restocker_id === 7
      && visitCall?.params?.status === 'COMPLETED'
      && visitCall?.params?.date_from === '2026-11-01'
      && !Object.prototype.hasOwnProperty.call(visitCall.params, 'machine_id')
      && !Object.prototype.hasOwnProperty.call(visitCall.params, 'date_to')
  );

  visitsComponent.visitFilters = { restocker_id: '', machine_id: '', status: '', date_from: '', date_to: '' };
  await visitsComponent.openVisitDetailModal(501);
  assert(
    '7.4 El expediente carga la visita, sus líneas y su merma',
    visitsComponent.showVisitDetailModal === true
      && visitsComponent.selectedVisitId === 501
      && visitsComponent.visitDetailVisit?.visit_code === 'MRV-2026-0501'
      && visitsComponent.visitItems.length === 2
      && visitsComponent.visitDetailVisit?.commercial_spoilage_loss_eur === 2.5
  );
  assert(
    '7.5 El expediente conserva coste unitario y merma por carril',
    visitsComponent.visitItems[0].unit_cost_snapshot_eur === 1.25
      && visitsComponent.visitItems[0].commercial_spoilage_loss_eur === 2.5
      && visitsComponent.visitItems[0].qty_withdrawn_expired + visitsComponent.visitItems[0].qty_withdrawn_damaged === 2
      && visitsComponent.visitItems[1].loaded_batch_code === 'LOTE-SW-02'
  );
  assert(
    '7.6 El checklist higiénico se proyecta con etiquetas y valores triestado',
    visitsComponent.visitHygieneItems.length === 5
      && visitsComponent.visitHygieneItems[0].label === 'Cajón de posos vaciado'
      && visitsComponent.visitHygieneValue(true) === 'Sí'
      && visitsComponent.visitHygieneValue(false) === 'No'
      && visitsComponent.visitHygieneValue(null) === 'No aplica'
  );
  assert(
    '7.7 El ticket SAT y la evidencia fotográfica quedan enlazados',
    visitsComponent.visitTicketHref(restockVisits[1]) === '#incident-77'
      && visitsComponent.visitTicketHref(restockVisits[0]) === ''
      && visitsComponent.visitDetailVisit?.photo_evidence_url === '/uploads/restock/visit-501.jpg'
  );
  assert(
    '7.8 La merma se formatea en euros sin exponer precios de venta',
    visitsComponent.formatCurrency(2.5).includes('2,50')
      && visitsComponent.formatCurrency(2.5).includes('€')
  );
  assert(
    '7.9 El expediente muestra checklist, foto, ticket y costes carril a carril',
    CoordinatorProductLoadingTab.template.includes('product-loading-visits-kpis')
      && CoordinatorProductLoadingTab.template.includes('product-loading-visits-table')
      && CoordinatorProductLoadingTab.template.includes('product-loading-visit-detail-modal')
      && CoordinatorProductLoadingTab.template.includes('product-loading-visit-detail-hygiene')
      && CoordinatorProductLoadingTab.template.includes('product-loading-visit-detail-photo')
      && CoordinatorProductLoadingTab.template.includes('product-loading-visit-detail-ticket')
      && CoordinatorProductLoadingTab.template.includes('product-loading-visit-detail-items')
      && CoordinatorProductLoadingTab.template.includes('unit_cost_snapshot_eur')
      && CoordinatorProductLoadingTab.template.includes('commercial_spoilage_loss_eur')
  );
  assert(
    '7.10 El libro no ofrece ninguna operación de borrado',
    !calls.some((call) => String(call.method).toLowerCase().includes('delete'))
      && !CoordinatorProductLoadingTab.methods.openVisitDetailModal.toString().toLowerCase().includes('delete')
  );
  await visitsComponent.openVisitDetailModal(999);
  assert(
    '7.11 Un expediente inexistente se comunica sin romper el modal',
    visitsComponent.visitDetail === null
      && visitsComponent.visitDetailError.includes('No existe')
      && visitsComponent.showVisitDetailModal === true
  );
  assert(
    '7.12 El cliente API expone las dos lecturas de visitas',
    typeof api.coordinator.getRestockVisits === 'function'
      && typeof api.coordinator.getRestockVisit === 'function'
  );

  const emittedEvents = [];
  visitsComponent.$emit = (event, payload) => emittedEvents.push({ event, payload });
  visitsComponent.openVisitTicket(restockVisits[1]);
  assert(
    '7.13 El ticket SAT se delega en la ficha integral de incidencias',
    emittedEvents.length === 1
      && emittedEvents[0].event === 'open-incident-detail'
      && emittedEvents[0].payload === 77
  );

  visitsComponent.showVisitDetailModal = false;
  visitsComponent.visitDetailError = '';

  // ─────────────────────────────────────────────────────────────────────
  // 8. Recaudación, cambio y arqueo de caja
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 8. Recaudación, cambio y arqueo de caja ---');

  const cashComponent = buildInstance();
  await cashComponent.loadCashCollections();
  assert(
    '8.1 Carga las cuatro recaudaciones y los seis KPIs ejecutivos',
    cashComponent.cashCollections.length === 4
      && cashComponent.cashKpis.pending_vault_bags_count === 2
      && cashComponent.cashKpis.reconciled_ok_count === 1
      && cashComponent.cashKpis.discrepancy_flagged_count === 1
      && cashComponent.cashKpis.total_vault_counted_eur === 305.5
      && cashComponent.cashKpis.net_discrepancy_eur === -5
      && cashComponent.cashKpis.total_route_cash_deployed_eur === 17.5
  );
  assert(
    '8.2 El semáforo distingue los cuatro estados de custodia',
    cashComponent.cashStatusTone('SEALED_IN_ROUTE').includes('vg-badge-warning')
      && cashComponent.cashStatusTone('DELIVERED_TO_VAULT').includes('vg-badge-info')
      && cashComponent.cashStatusTone('RECONCILED_OK').includes('vg-badge-success')
      && cashComponent.cashStatusTone('DISCREPANCY_FLAGGED').includes('vg-badge-danger')
  );

  cashComponent.cashFilters = {
    restocker_id: 7,
    machine_id: 1,
    status: 'DISCREPANCY_FLAGGED',
    bag_seal_code: ' bag-2026-009104 ',
    date_from: '2026-11-01',
    date_to: ''
  };
  await cashComponent.loadCashCollections();
  const cashCall = calls.filter((call) => call.method === 'getCashCollections').at(-1);
  assert(
    '8.3 Los filtros saneados viajan al API, incluido el precinto normalizado',
    cashCall?.params?.restocker_id === 7
      && cashCall?.params?.machine_id === 1
      && cashCall?.params?.status === 'DISCREPANCY_FLAGGED'
      && cashCall?.params?.bag_seal_code === 'BAG-2026-009104'
      && cashCall?.params?.date_from === '2026-11-01'
      && !Object.prototype.hasOwnProperty.call(cashCall.params, 'date_to')
  );
  cashComponent.cashFilters = {
    restocker_id: '',
    machine_id: '',
    status: 'ALL',
    bag_seal_code: '',
    date_from: '',
    date_to: ''
  };
  assert('8.4 El estado ALL no viaja al backend', Object.keys(cashComponent.cashFilterParams()).length === 0);

  await cashComponent.openCashDetailModal(cashCollections[0]);
  assert(
    '8.5 El modal de arqueo carga lecturas, esperado y formulario vacío',
    cashComponent.showCashDetailModal === true
      && cashComponent.selectedCashId === 9001
      && cashComponent.cashDetailCollection?.previous_sales_counter_reading === 1200
      && cashComponent.cashDetailCollection?.expected_cashbox_eur === 150
      && cashComponent.cashReconcileForm.vault_counted_eur === ''
  );

  cashComponent.cashReconcileForm.vault_counted_eur = '145.50';
  assert(
    '8.6 El descuadre se calcula en vivo y exige justificación',
    cashComponent.cashLiveDiscrepancy === -4.5
      && cashComponent.cashRequiresJustification === true
  );
  const callsBeforeInvalidCash = calls.filter((call) => call.method === 'reconcileCashCollection').length;
  assert(
    '8.7 Un descuadre sin justificación real no se envía al API',
    cashComponent.validateCashReconciliation() === false
      && cashComponent.cashReconcileError.includes('20')
      && calls.filter((call) => call.method === 'reconcileCashCollection').length === callsBeforeInvalidCash
  );
  cashComponent.cashReconcileForm.discrepancy_justification = 'Faltan 4,50 € del cambio de la ruta de la mañana.';
  assert('8.8 Una justificación real desbloquea el arqueo', cashComponent.validateCashReconciliation() === true);
  await cashComponent.submitCashReconciliation();
  const cashReconcileCall = calls.filter((call) => call.method === 'reconcileCashCollection').at(-1);
  assert(
    '8.9 El arqueo envía el contado y la justificación, y sella el descuadre',
    cashReconcileCall?.collectionId === 9001
      && cashReconcileCall?.payload?.vault_counted_eur === 145.5
      && cashReconcileCall?.payload?.discrepancy_justification.length >= 20
      && cashComponent.showCashDetailModal === false
      && cashComponent.cashSuccess.includes('MCC-2026-9001')
  );

  const exactComponent = buildInstance();
  await exactComponent.openCashDetailModal(cashCollections[0]);
  exactComponent.cashReconcileForm.vault_counted_eur = '150.50';
  assert(
    '8.10 Un descuadre dentro de ±1,00 € no exige justificación',
    exactComponent.cashLiveDiscrepancy === 0.5
      && exactComponent.cashRequiresJustification === false
      && exactComponent.validateCashReconciliation() === true
  );
  exactComponent.showCashDetailModal = false;

  const deliveryComponent = buildInstance();
  await deliveryComponent.loadCashCollections();
  await deliveryComponent.deliverCashCollection(cashCollections[0]);
  const deliveryCall = calls.filter((call) => call.method === 'deliverCashCollectionToVault').at(-1);
  assert(
    '8.11 La recepción en cámara se ejecuta en un toque',
    deliveryCall?.collectionId === 9001
      && deliveryComponent.cashSuccess.includes('recibida en Caja Central')
  );

  await cashComponent.openCashReceiptModal(cashCollections[1]);
  assert(
    '8.12 El recibo de conserjería expone exactamente nueve campos sin PII ni PIN',
    cashComponent.showCashReceiptModal === true
      && Object.keys(cashComponent.cashReceipt).length === 9
      && cashComponent.cashReceipt.reception_cash_delivered_eur === 2.5
      && !Object.prototype.hasOwnProperty.call(cashComponent.cashReceipt, 'bag_seal_code')
      && !Object.prototype.hasOwnProperty.call(cashComponent.cashReceipt, 'pickup_pin')
      && !Object.prototype.hasOwnProperty.call(cashComponent.cashReceipt, 'expected_cashbox_eur')
  );
  cashComponent.showCashReceiptModal = false;

  await cashComponent.openCashVisit(cashCollections[0]);
  assert(
    '8.13 La visita de reposición vinculada se abre desde el arqueo',
    cashComponent.showCashDetailModal === false
      && cashComponent.visitDetailVisit?.visit_code === 'MRV-2026-0501'
  );

  await cashComponent.exportCashCollectionsCsv();
  const exportCall = calls.filter((call) => call.method === 'exportCashCollectionsCsv').at(-1);
  assert(
    '8.14 La exportación CSV usa el mismo filtro activo',
    Boolean(exportCall)
      && exportCall.params.status === undefined
      && cashComponent.cashSuccess.includes('CSV')
  );

  assert(
    '8.15 La sub-vista publica KPIs, tabla, modal de arqueo, recibo y CSV',
    CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-cash-section-button"')
      && CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-cash-kpis"')
      && CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-cash-table"')
      && CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-cash-detail-modal"')
      && CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-cash-detail-terminal"')
      && CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-cash-receipt-modal"')
      && CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-cash-export"')
      && CoordinatorProductLoadingTab.template.includes('Bolsas en ruta / cámara (Pendientes)')
      && CoordinatorProductLoadingTab.template.includes('Bolsas arqueadas OK')
      && CoordinatorProductLoadingTab.template.includes('Arqueos con descuadre')
      && CoordinatorProductLoadingTab.template.includes('Total recaudado en caja')
      && CoordinatorProductLoadingTab.template.includes('Descuadre neto acumulado')
      && CoordinatorProductLoadingTab.template.includes('Cambio cargado + Conserjería')
  );
  assert(
    '8.16 El cliente API expone las seis operaciones de recaudación',
    [
      'getCashCollections',
      'getCashCollection',
      'deliverCashCollectionToVault',
      'reconcileCashCollection',
      'getCashCollectionReceptionReceipt',
      'exportCashCollectionsCsv'
    ].every((method) => typeof api.coordinator[method] === 'function')
  );
  assert(
    '8.17 El módulo no ofrece ninguna operación de borrado',
    !calls.some((call) => String(call.method).toLowerCase().includes('delete'))
  );

  console.log('\n======================================================================');
  if (failures === 0) {
    console.log(` RESULTADO: ¡TODAS LAS PRUEBAS PASARON (${assertions} aserciones, 0 fallos)!`);
    console.log(' PESTAÑA DE ÓRDENES, STOCK, VISITAS IN SITU Y ARQUEO DE CAJA VERIFICADA.');
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
