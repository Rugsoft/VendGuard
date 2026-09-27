/**
 * VendGuard - TechnicianPreventiveRouteTab Test Suite (TechnicianPreventiveRouteTabTest.mjs)
 * 
 * Valida la funcionalidad reactiva y contratos de:
 * TechnicianPreventiveRouteTab.js (RF-PREV-02, EARS 2.3, RNF-01).
 * 
 * Hecho cuando:
 * 1. Carga la ruta de inspecciones del técnico y el selector de sedes in situ.
 * 2. Agrupa órdenes asignadas (SCHEDULED, IN_INSPECTION, EXPIRED) y destaca órdenes pendientes en sede (PENDING_ASSIGNMENT).
 * 3. EARS 2.3 (Visita Oportunista): Permite autoasignarse inmediatamente con un solo toque una orden pendiente.
 * 4. Invoca startPreventiveInspection y emite eventos inspection-started y open-checklist.
 * 5. Permite abrir el checklist sanitario en órdenes ya en estado IN_INSPECTION.
 * 6. Integración en TechnicianRouteView.js como sección preventiva accesible.
 * 
 * Dogma Vanilla: Node.js nativo con módulos ESM y cero dependencias externas.
 */

// Mock de entorno navegador para Node.js ESM
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
globalThis.window = {
  location: { search: '', href: 'http://localhost/' }
};

import { api } from '../../public/assets/js/api.js';
import { TechnicianPreventiveRouteTab } from '../../public/assets/js/components/TechnicianPreventiveRouteTab.js';
import { TechnicianRouteView } from '../../public/assets/js/views/TechnicianRouteView.js';

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
console.log(' VendGuard: Frontend Test Suite - TechnicianPreventiveRouteTab (T-PREV-20)');
console.log('======================================================================\n');

// Fixtures
const mockOrdersRoute = [
  {
    id: 10,
    order_code: 'PREV-2026-0010',
    machine_id: 1,
    location_id: 1,
    assigned_technician_id: 2,
    status: 'SCHEDULED',
    order_type: 'ROUTINE',
    scheduled_date: '2026-09-28',
    due_date: '2026-09-30',
    machine: {
      id: 1,
      code: 'VEND-0101',
      model: 'Sanden Vendo G-Drink',
      machine_type: 'PERISHABLE_FOOD',
      is_perishable: true,
      floor_wing: 'Planta Baja'
    },
    location: { id: 1, name: 'Hospital del Mar' }
  },
  {
    id: 11,
    order_code: 'PREV-2026-0011',
    machine_id: 2,
    location_id: 1,
    assigned_technician_id: 2,
    status: 'IN_INSPECTION',
    order_type: 'ROUTINE',
    scheduled_date: '2026-09-28',
    due_date: '2026-10-01',
    machine: {
      id: 2,
      code: 'VEND-0102',
      model: 'Necta Krea',
      machine_type: 'HOT_DRINKS',
      is_perishable: false,
      floor_wing: 'Planta 1'
    },
    location: { id: 1, name: 'Hospital del Mar' }
  },
  {
    id: 12,
    order_code: 'PREV-2026-0012',
    machine_id: 3,
    location_id: 1,
    assigned_technician_id: null,
    status: 'PENDING_ASSIGNMENT',
    order_type: 'ROUTINE',
    scheduled_date: '2026-09-29',
    due_date: '2026-09-29',
    machine: {
      id: 3,
      code: 'VEND-0103',
      model: 'FAS Fast 1050',
      machine_type: 'PERISHABLE_FOOD',
      is_perishable: true,
      floor_wing: 'Planta 2 - Quirófanos'
    },
    location: { id: 1, name: 'Hospital del Mar' }
  }
];

const mockLocations = [
  { id: 1, site_code: 'SEDE-BCN-01', name: 'Hospital del Mar' },
  { id: 2, site_code: 'SEDE-BCN-02', name: 'Campus Nord UPC' }
];

let apiRouteCalledWithLocation = null;
let apiClaimCalledId = null;
let apiStartInspectionCalledId = null;

api.coordinator.getLocations = async () => mockLocations;
api.technician.getPreventiveRoute = async (locId) => {
  apiRouteCalledWithLocation = locId;
  return { success: true, data: mockOrdersRoute };
};
api.technician.claimPreventiveOrder = async (orderId) => {
  apiClaimCalledId = orderId;
  return { success: true, data: { order_id: orderId, status: 'SCHEDULED' } };
};
api.technician.startPreventiveInspection = async (orderId) => {
  apiStartInspectionCalledId = orderId;
  return { success: true, data: { order_id: orderId, status: 'IN_INSPECTION' } };
};

const tab = {
  ...TechnicianPreventiveRouteTab.data(),
  ...TechnicianPreventiveRouteTab.methods,
  get assignedOrders() {
    return TechnicianPreventiveRouteTab.computed.assignedOrders.call(this);
  },
  get opportunisticOrders() {
    return TechnicianPreventiveRouteTab.computed.opportunisticOrders.call(this);
  },
  get metrics() {
    return TechnicianPreventiveRouteTab.computed.metrics.call(this);
  },
  $emit(eventName, payload) {
    this._emitted = this._emitted || {};
    this._emitted[eventName] = payload || true;
  }
};

// =========================================================================
// BLOQUE 1: Carga inicial y agrupación reactiva
// =========================================================================
console.log('--- BLOQUE 1: Carga y agrupación reactiva ---');

await tab.loadInitialData();
assert('1.1 loadInitialData carga catálogo de sedes', tab.locations.length === 2);
assert('1.2 getPreventiveRoute invocado', apiRouteCalledWithLocation !== undefined);
assert('1.3 Órdenes cargadas en array (3 órdenes)', tab.orders.length === 3);

// Computed: assignedOrders
const assigned = TechnicianPreventiveRouteTab.computed.assignedOrders.call(tab);
assert('1.4 assignedOrders filtra SCHEDULED e IN_INSPECTION (2 órdenes)', assigned.length === 2);

// Computed: opportunisticOrders (EARS 2.3)
const opportunistic = TechnicianPreventiveRouteTab.computed.opportunisticOrders.call(tab);
assert('1.5 opportunisticOrders filtra PENDING_ASSIGNMENT (1 orden)',
  opportunistic.length === 1 && opportunistic[0].order_code === 'PREV-2026-0012');

// Computed: metrics
const metrics = TechnicianPreventiveRouteTab.computed.metrics.call(tab);
assert('1.6 metrics.assigned es 2', metrics.assigned === 2);
assert('1.7 metrics.inInspection es 1', metrics.inInspection === 1);
assert('1.8 metrics.opportunistic es 1', metrics.opportunistic === 1);

// =========================================================================
// BLOQUE 2: Visita Oportunista (EARS 2.3)
// =========================================================================
console.log('\n--- BLOQUE 2: Visita Oportunista (Autoasignación in situ) ---');

// Autoasignación con un solo toque
const orderToClaim = opportunistic[0];
await tab.claimOrder(orderToClaim);

assert('2.1 claimOrder invoca api.technician.claimPreventiveOrder con ID 12', apiClaimCalledId === 12);
assert('2.2 Mensaje de éxito confirma la visita oportunista',
  tab.successMessage.includes('Visita oportunista confirmada') && tab.successMessage.includes('PREV-2026-0012'));
assert('2.3 Emite evento order-claimed', tab._emitted['order-claimed']?.id === 12);

// =========================================================================
// BLOQUE 3: Inicio de Inspección in situ y Navegación a Checklist
// =========================================================================
console.log('\n--- BLOQUE 3: Inicio de Inspección y Checklist ---');

// Iniciar inspección sobre orden SCHEDULED (ID 10)
const scheduledOrder = assigned.find(o => o.status === 'SCHEDULED');
await tab.startInspection(scheduledOrder);

assert('3.1 startInspection invoca api.technician.startPreventiveInspection con ID 10', apiStartInspectionCalledId === 10);
assert('3.2 Emite evento inspection-started', tab._emitted['inspection-started']?.id === 10);
assert('3.3 Emite evento open-checklist con status IN_INSPECTION',
  tab._emitted['open-checklist']?.id === 10 && tab._emitted['open-checklist']?.status === 'IN_INSPECTION');

// Abrir checklist en orden ya en inspección (ID 11)
const inInspectionOrder = assigned.find(o => o.status === 'IN_INSPECTION');
tab.openChecklist(inInspectionOrder);
assert('3.4 openChecklist emite open-checklist con la orden seleccionada', tab._emitted['open-checklist']?.id === 11);

// =========================================================================
// BLOQUE 4: Cambio de Sede in situ
// =========================================================================
console.log('\n--- BLOQUE 4: Cambio de Sede in situ ---');

tab.selectedLocationId = 2;
tab.onLocationChange();
assert('4.1 onLocationChange recarga la ruta con location_id = 2', apiRouteCalledWithLocation === 2);

// =========================================================================
// BLOQUE 5: Integración en TechnicianRouteView.js
// =========================================================================
console.log('\n--- BLOQUE 5: Integración en TechnicianRouteView.js ---');

assert('5.1 TechnicianRouteView registra TechnicianPreventiveRouteTab en components',
  TechnicianRouteView.components.TechnicianPreventiveRouteTab !== undefined);

assert('5.2 TechnicianRouteView template contiene botón y sección para tab preventiva',
  TechnicianRouteView.template.includes('tech-tab-preventive') &&
  TechnicianRouteView.template.includes('TechnicianPreventiveRouteTab'));

console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: ¡Todas las pruebas pasaron con éxito (${assertions} aserciones)! (0 fallos)\n`);
} else {
  console.error(` RESULTADO: ${failures} prueba(s) fallaron de ${assertions} aserciones.\n`);
  process.exit(1);
}
console.log('======================================================================\n');
