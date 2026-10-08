/**
 * VendGuard - Coordinator Preventive Components Test Suite (CoordinatorPreventiveComponentsTest.mjs)
 * 
 * Valida la funcionalidad reactiva y contratos de:
 * 1. CoordinatorPreventiveDashboard.js (RF-PREV-06, EARS 6.1, 6.3)
 * 2. CoordinatorPreventiveOrdersTab.js (RF-PREV-02, EARS 2.1, 2.2, 2.5, Art. III)
 * 3. Integración en CoordinatorDashboardView.js
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
import { CoordinatorPreventiveDashboard } from '../../public/assets/js/components/CoordinatorPreventiveDashboard.js';
import { CoordinatorPreventiveOrdersTab } from '../../public/assets/js/components/CoordinatorPreventiveOrdersTab.js';
import { CoordinatorPreventiveOrderDetailModal } from '../../public/assets/js/components/CoordinatorPreventiveOrderDetailModal.js';
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
console.log(' VendGuard: Frontend Test Suite - Coordinator Preventive Components (T-PREV-18)');
console.log('======================================================================\n');

// Mock Fixtures
const mockDashboardData = {
  summary: {
    total_machines: 142,
    status_green: 118,
    status_yellow: 14,
    status_red: 6,
    status_quarantine: 2,
    status_seasonal_pause: 2,
    compliance_rate_percent: 83.1
  },
  urgent_actions: {
    quarantine_machines: [
      {
        machine_id: 14,
        machine_code: 'VEND-0101',
        model: 'Sanden Vendo G-Drink',
        location_name: 'Hospital del Mar',
        floor_wing: 'Planta Baja - Urgencias',
        quarantine_reason: 'Rotura de frío: 6.8 °C (> 4.0 °C)',
        active_incident_code: 'INC-2026-0091',
        since: '2026-09-27T10:30:00Z'
      }
    ],
    expired_orders_count: 6,
    due_soon_orders_count: 14
  }
};

const mockOrders = [
  {
    id: 1,
    order_code: 'PREV-2026-0001',
    machine_id: 1,
    location_id: 1,
    assigned_technician_id: 2,
    status: 'SCHEDULED',
    order_type: 'ROUTINE',
    scheduled_date: '2026-10-01',
    due_date: '2026-10-05',
    is_quarantine_triggered: false,
    machine: {
      id: 1,
      code: 'VEND-0101',
      model: 'Sanden Vendo G-Drink',
      machine_type: 'PERISHABLE_FOOD',
      is_perishable: true
    },
    location: {
      id: 1,
      name: 'Hospital del Mar',
      floor_wing: 'Planta Baja'
    },
    technician: {
      id: 2,
      name: 'Jordi Técnico',
      operator_code: 'OP-02'
    },
    result: null
  },
  {
    id: 2,
    order_code: 'PREV-2026-0002',
    machine_id: 2,
    location_id: 1,
    assigned_technician_id: null,
    status: 'PENDING_ASSIGNMENT',
    order_type: 'ROUTINE',
    scheduled_date: '2026-10-02',
    due_date: '2026-10-04',
    is_quarantine_triggered: false,
    machine: {
      id: 2,
      code: 'VEND-0102',
      model: 'Necta Krea',
      machine_type: 'HOT_DRINKS',
      is_perishable: false
    },
    location: {
      id: 1,
      name: 'Hospital del Mar',
      floor_wing: 'Planta 1'
    },
    technician: null,
    result: null
  },
  {
    id: 3,
    order_code: 'PREV-2026-0003',
    machine_id: 3,
    location_id: 2,
    assigned_technician_id: 2,
    status: 'COMPLETED',
    order_type: 'ROUTINE',
    scheduled_date: '2026-09-25',
    due_date: '2026-09-27',
    is_quarantine_triggered: false,
    machine: {
      id: 3,
      code: 'VEND-0103',
      model: 'Bianchi BVM',
      machine_type: 'COLD_DRINKS',
      is_perishable: false
    },
    location: {
      id: 2,
      name: 'Campus Nord UPC',
      floor_wing: 'Edificio A'
    },
    technician: {
      id: 2,
      name: 'Jordi Técnico',
      operator_code: 'OP-02'
    },
    result: 'CONFORME'
  }
];

const mockLocations = [
  { id: 1, site_code: 'SEDE-BCN-01', name: 'Hospital del Mar' },
  { id: 2, site_code: 'SEDE-BCN-02', name: 'Campus Nord UPC' }
];

const mockTechnicians = [
  { id: 2, name: 'Jordi Técnico', operator_code: 'OP-02' },
  { id: 3, name: 'Marta Técnica', operator_code: 'OP-03' }
];

// =========================================================================
// BLOQUE 1: Pruebas de CoordinatorPreventiveDashboard
// =========================================================================
console.log('--- BLOQUE 1: CoordinatorPreventiveDashboard ---');

// Mockear llamadas API
let apiDashboardCalled = false;
let apiGenerateDueCalled = false;
let generateDueHorizon = 0;

api.coordinator.getPreventiveDashboard = async () => {
  apiDashboardCalled = true;
  return { success: true, data: mockDashboardData };
};

api.coordinator.generateDuePreventiveOrders = async (horizon) => {
  apiGenerateDueCalled = true;
  generateDueHorizon = horizon;
  return {
    success: true,
    data: { orders_generated_count: 5, machines_evaluated_count: 142 },
    message: 'Se han generado 5 órdenes de inspección preventiva.'
  };
};

const dashboard = {
  ...CoordinatorPreventiveDashboard.data(),
  ...CoordinatorPreventiveDashboard.methods,
  $emit(eventName, payload) {
    this._emitted = this._emitted || {};
    this._emitted[eventName] = payload || true;
  }
};

// 1.1 Carga inicial de datos
await dashboard.loadDashboard();
assert('1.1 Dashboard llama a getPreventiveDashboard()', apiDashboardCalled);
assert('1.2 Total de máquinas cargado correctamente (142)', dashboard.summary.total_machines === 142);
assert('1.3 Máquinas en estado verde cargadas (118)', dashboard.summary.status_green === 118);
assert('1.4 Máquinas en estado amarillo cargadas (14)', dashboard.summary.status_yellow === 14);
assert('1.5 Máquinas en estado rojo cargadas (6)', dashboard.summary.status_red === 6);
assert('1.6 Máquinas en cuarentena cargadas (2)', dashboard.summary.status_quarantine === 2);
assert('1.7 Tasa de cumplimiento cargada (83.1%)', dashboard.summary.compliance_rate_percent === 83.1);

// 1.2 Computed properties
const computedHasQuarantines = CoordinatorPreventiveDashboard.computed.hasQuarantines.call(dashboard);
assert('1.8 hasQuarantines es true cuando hay cuarentenas', computedHasQuarantines === true);

const computedHasUrgent = CoordinatorPreventiveDashboard.computed.hasUrgentAlerts.call(dashboard);
assert('1.9 hasUrgentAlerts es true cuando hay cuarentenas o vencidas', computedHasUrgent === true);

// 1.3 Generación anticipada de órdenes
await dashboard.handleGenerateDue();
assert('1.10 handleGenerateDue llama a generateDuePreventiveOrders(5)', apiGenerateDueCalled && generateDueHorizon === 5);
assert('1.11 Mensaje de éxito consignado', dashboard.successMessage.includes('5 órdenes'));
assert('1.12 Emite evento refresh', dashboard._emitted['refresh'] === true);

// 1.4 Navegación a órdenes
dashboard.navigateToOrders('EXPIRED');
assert('1.13 navigateToOrders emite view-orders con filtro', dashboard._emitted['view-orders']?.status === 'EXPIRED');

// 1.5 Abrir modal de configuración
dashboard.openSettingsModal();
assert('1.14 openSettingsModal emite open-settings', dashboard._emitted['open-settings'] === true);

// =========================================================================
// BLOQUE 2: Pruebas de CoordinatorPreventiveOrdersTab
// =========================================================================
console.log('\n--- BLOQUE 2: CoordinatorPreventiveOrdersTab ---');

let apiOrdersCalled = false;
let apiAssignCalled = false;
let assignParams = null;
let apiCancelCalled = false;
let cancelParams = null;
let apiCreateCalled = false;

api.coordinator.getPreventiveOrders = async (filters) => {
  apiOrdersCalled = true;
  return { success: true, data: mockOrders };
};
api.coordinator.getLocations = async () => mockLocations;
api.coordinator.getUsers = async () => mockTechnicians;
api.coordinator.getMachines = async () => [
  { id: 1, code: 'VEND-0101', model: 'Sanden Vendo G-Drink', machine_type: 'PERISHABLE_FOOD' }
];

api.coordinator.assignPreventiveOrder = async (orderId, techId, date, reassignmentReason = null) => {
  apiAssignCalled = true;
  assignParams = { orderId, techId, date, reason: reassignmentReason };
  return { success: true, data: { order_id: orderId, status: 'SCHEDULED' } };
};

api.coordinator.cancelPreventiveOrder = async (orderId, reason) => {
  apiCancelCalled = true;
  cancelParams = { orderId, reason };
  return { success: true, data: { order_id: orderId, status: 'CANCELLED' } };
};

api.coordinator.createPreventiveOrder = async (payload) => {
  apiCreateCalled = true;
  return { success: true, data: { id: 99, order_code: 'PREV-2026-0099' } };
};

const ordersTab = {
  ...CoordinatorPreventiveOrdersTab.data(),
  ...CoordinatorPreventiveOrdersTab.methods,
  $emit(eventName, payload) {
    this._emitted = this._emitted || {};
    this._emitted[eventName] = payload || true;
  }
};

// 2.1 Carga inicial
await ordersTab.loadInitialData();
assert('2.1 loadInitialData carga órdenes', apiOrdersCalled);
assert('2.2 Órdenes cargadas en array (3 órdenes)', ordersTab.orders.length === 3);
assert('2.3 Sedes cargadas (2 sedes)', ordersTab.locations.length === 2);
assert('2.4 Técnicos cargados (2 técnicos)', ordersTab.technicians.length === 2);

// 2.2 Filtro reactivo por estado
ordersTab.filterStatus = 'PENDING_ASSIGNMENT';
let filtered = CoordinatorPreventiveOrdersTab.computed.filteredOrders.call(ordersTab);
assert('2.5 Filtro por PENDING_ASSIGNMENT devuelve 1 orden', filtered.length === 1 && filtered[0].order_code === 'PREV-2026-0002');

// 2.3 Filtro por sede
ordersTab.filterStatus = '';
ordersTab.filterLocationId = '2';
filtered = CoordinatorPreventiveOrdersTab.computed.filteredOrders.call(ordersTab);
assert('2.6 Filtro por location_id = 2 devuelve 1 orden', filtered.length === 1 && filtered[0].order_code === 'PREV-2026-0003');

// 2.4 Filtro por texto de búsqueda
ordersTab.filterLocationId = '';
ordersTab.filterSearch = 'VEND-0101';
filtered = CoordinatorPreventiveOrdersTab.computed.filteredOrders.call(ordersTab);
assert('2.7 Filtro por texto "VEND-0101" devuelve orden asociada', filtered.length === 1 && filtered[0].order_code === 'PREV-2026-0001');

// 2.5 Visual helpers
const badgeScheduled = ordersTab.getStatusBadge('SCHEDULED');
assert('2.8 Badge SCHEDULED tiene label "Programada"', badgeScheduled.label === 'Programada');
const badgeCompleted = ordersTab.getStatusBadge('COMPLETED');
assert('2.9 Badge COMPLETED tiene label "Completada"', badgeCompleted.label === 'Completada');

const badgeConforme = ordersTab.getResultBadge('CONFORME');
assert('2.10 ResultBadge CONFORME tiene label "Conforme"', badgeConforme.label === 'Conforme');

const typeLabel = ordersTab.getOrderTypeLabel('ROUTINE');
assert('2.11 getOrderTypeLabel ROUTINE devuelve "Ordinaria"', typeLabel === 'Ordinaria');

// 2.6 Modal de Asignación Técnica (RF-PREV-02, EARS 2.2)
ordersTab.openAssignModal(mockOrders[1]);
assert('2.12 openAssignModal abre modal y setea selectedOrderToAssign', ordersTab.showAssignModal === true && ordersTab.selectedOrderToAssign?.id === 2);

// Validación ante campos omitidos
ordersTab.assignForm.technician_id = '';
await ordersTab.submitAssignOrder();
assert('2.13 Asignación rechaza técnico vacío con mensaje de error', ordersTab.assignError.includes('técnico'));

// Asignación correcta
ordersTab.assignForm.technician_id = 3;
ordersTab.assignForm.scheduled_date = '2026-10-03';
await ordersTab.submitAssignOrder();
assert('2.14 Asignación exitosa invoca api.coordinator.assignPreventiveOrder', apiAssignCalled);
assert('2.15 Parámetros de asignación correctos (order 2, tech 3, 2026-10-03)', assignParams.orderId === 2 && assignParams.techId === 3 && assignParams.date === '2026-10-03');
assert('2.16 Modal de asignación se cierra tras submit', ordersTab.showAssignModal === false);
assert('2.17 Emite order-assigned', ordersTab._emitted['order-assigned'] === true);

// 2.7 Modal de Cancelación Lógica Justificada (Art. III)
ordersTab.openCancelModal(mockOrders[0]);
assert('2.18 openCancelModal abre modal', ordersTab.showCancelModal === true);

// Validación de motivo obligatorio
ordersTab.cancelForm.reason = '   ';
await ordersTab.submitCancelOrder();
assert('2.19 Cancelación rechaza motivo vacío por Art. III', ordersTab.cancelError.includes('motivo justificado'));

// Cancelación con motivo justificado
ordersTab.cancelForm.reason = 'Traslado físico de la máquina VEND-0101 a nueva sede de Urgencias.';
await ordersTab.submitCancelOrder();
assert('2.20 Cancelación exitosa invoca api.coordinator.cancelPreventiveOrder', apiCancelCalled);
assert('2.21 Parámetros de cancelación correctos', cancelParams.orderId === 1 && cancelParams.reason.includes('Traslado'));
assert('2.22 Modal de cancelación se cierra', ordersTab.showCancelModal === false);
assert('2.23 Emite order-cancelled', ordersTab._emitted['order-cancelled'] === true);

// 2.8 Modal de Creación Manual
await ordersTab.openCreateModal();
assert('2.24 openCreateModal abre modal y prepara formulario', ordersTab.showCreateModal === true);

ordersTab.createForm.machine_id = '';
await ordersTab.submitCreateOrder();
assert('2.25 Creación rechaza sin máquina seleccionada', ordersTab.createError.includes('máquina'));

ordersTab.createForm.machine_id = 1;
ordersTab.createForm.order_type = 'MANUAL_EXTRA';
ordersTab.createForm.notes = 'Revisión extraordinaria solicitada.';
await ordersTab.submitCreateOrder();
assert('2.26 Creación manual invoca api.coordinator.createPreventiveOrder', apiCreateCalled);
assert('2.27 Modal de creación se cierra', ordersTab.showCreateModal === false);
assert('2.28 Emite order-created', ordersTab._emitted['order-created'] === true);

// 2.9 Rótulo contextual Asignar / Reasignar (RF-PREV-02)
assert('2.29 hasAssignedTechnician distingue la orden con técnico vivo de la pendiente',
  ordersTab.hasAssignedTechnician(mockOrders[0]) === true
  && ordersTab.hasAssignedTechnician(mockOrders[1]) === false
  && ordersTab.hasAssignedTechnician(null) === false
  && ordersTab.hasAssignedTechnician({ assigned_technician_id: null, technician: { name: null } }) === false);

assert('2.30 La fila rotula «Reasignar» con técnico vivo y «Asignar» sin él',
  CoordinatorPreventiveOrdersTab.template.includes("hasAssignedTechnician(order) ? '🔁 Reasignar' : '👤 Asignar'")
  && CoordinatorPreventiveOrdersTab.template.includes(':title="hasAssignedTechnician(order) ?'));

assert('2.31 El modal adapta su título, su entradilla y su confirmación',
  CoordinatorPreventiveOrdersTab.template.includes("'🔁 Reasignar Orden Preventiva' : '👤 Asignar Orden Preventiva'")
  && CoordinatorPreventiveOrdersTab.template.includes("'Reasignación' : 'Asignación'")
  && CoordinatorPreventiveOrdersTab.template.includes("'Confirmar Reasignación' : 'Confirmar Asignación'"));

// Reasignación real de la orden programada con técnico (RF-PREV-02, EARS 2.6)
ordersTab.openAssignModal(mockOrders[0]);
assert('2.32 openAssignModal sobre una orden con técnico entra en modo reasignación',
  ordersTab.hasAssignedTechnician(ordersTab.selectedOrderToAssign) === true);

assert('2.33 El modo reasignación sólo se activa si el técnico seleccionado difiere del responsable vivo',
  ordersTab.isAssignReassignment() === false
    && Number(ordersTab.assignForm.technician_id) === Number(mockOrders[0].technician.id));

ordersTab.assignForm.technician_id = 4;
ordersTab.assignForm.scheduled_date = '2026-10-09';
ordersTab.assignReassignmentReason = '';
apiAssignCalled = false;
assignParams = null;
await ordersTab.submitAssignOrder();
assert('2.34 Reasignar sin motivo justificado se bloquea antes de llamar a la API (EARS 2.6)',
  apiAssignCalled === false && ordersTab.assignError.includes('motivo justificado'));

ordersTab.assignReassignmentReason = 'Corto';
await ordersTab.submitAssignOrder();
assert('2.35 Un motivo por debajo de 10 caracteres reales se rechaza (EARS 2.6)',
  apiAssignCalled === false && ordersTab.assignError.includes('al menos 10 caracteres'));

ordersTab.assignReassignmentReason = 'Baja médica de la técnica previa';
ordersTab.successMessage = '';
await ordersTab.submitAssignOrder();
assert('2.36 Reasignación invoca la API con la orden programada y su motivo',
  apiAssignCalled === true && assignParams.orderId === 1 && assignParams.techId === 4 && assignParams.date === '2026-10-09'
    && assignParams.reason === 'Baja médica de la técnica previa');
assert('2.37 El mensaje de éxito distingue reasignación de asignación inicial',
  ordersTab.successMessage.includes('reasignada correctamente'));

// La reprogramación de fecha que conserva al mismo técnico no es una reasignación (EARS 2.6)
ordersTab.openAssignModal(mockOrders[0]);
ordersTab.assignForm.scheduled_date = '2026-10-20';
ordersTab.assignReassignmentReason = '';
apiAssignCalled = false;
assignParams = null;
await ordersTab.submitAssignOrder();
assert('2.38 La reprogramación de fecha del mismo técnico sigue exenta de motivo (EARS 2.6)',
  apiAssignCalled === true && assignParams.reason === null && ordersTab.assignError === '');

// La asignación inicial de una orden sin responsable no exige motivo (EARS 2.6)
ordersTab.openAssignModal(mockOrders[1]);
assert('2.39 La orden sin responsable no entra en modo reasignación',
  ordersTab.isAssignReassignment() === false);
ordersTab.assignForm.technician_id = 3;
ordersTab.assignForm.scheduled_date = '2026-10-22';
apiAssignCalled = false;
assignParams = null;
await ordersTab.submitAssignOrder();
assert('2.40 La asignación inicial se resuelve sin motivo justificado (EARS 2.6)',
  apiAssignCalled === true && assignParams.techId === 3 && assignParams.reason === null);

assert('2.41 Plantilla: el campo de motivo sólo se ofrece al cambiar de técnico',
  CoordinatorPreventiveOrdersTab.template.includes('v-if="isAssignReassignment()"')
    && CoordinatorPreventiveOrdersTab.template.includes('data-testid="assign-reassignment-reason"')
    && CoordinatorPreventiveOrdersTab.template.includes('v-model="assignReassignmentReason"'));

// =========================================================================
// BLOQUE 3: Integración en CoordinatorDashboardView
// =========================================================================
console.log('\n--- BLOQUE 3: Integración en CoordinatorDashboardView ---');

assert('3.1 CoordinatorDashboardView incluye CoordinatorPreventiveDashboard en components',
  CoordinatorDashboardView.components.CoordinatorPreventiveDashboard !== undefined);

assert('3.2 CoordinatorDashboardView incluye CoordinatorPreventiveOrdersTab en components',
  CoordinatorDashboardView.components.CoordinatorPreventiveOrdersTab !== undefined);

const coordViewData = CoordinatorDashboardView.data();
assert('3.3 CoordinatorDashboardView define activePreventiveSubTab con valor inicial "dashboard"',
  coordViewData.activePreventiveSubTab === 'dashboard');

// =========================================================================
// BLOQUE 4: Ficha integral de detalle de la orden (RF-PD-01, T-PREV-29/30)
// =========================================================================
console.log('\n--- BLOQUE 4: Ficha integral de detalle de la orden preventiva ---');

assert('4.1 La pestaña registra el modal de detalle preventivo',
  CoordinatorPreventiveOrdersTab.components?.CoordinatorPreventiveOrderDetailModal !== undefined
  && CoordinatorPreventiveOrderDetailModal.name === 'CoordinatorPreventiveOrderDetailModal');

assert('4.2 La pestaña declara el evento de salto a la ficha de avería',
  CoordinatorPreventiveOrdersTab.emits.includes('open-incident-detail'));

assert('4.3 Cada fila ofrece el disparador "Ver detalle" junto a los botones rápidos',
  CoordinatorPreventiveOrdersTab.template.includes('data-testid="btn-view-order-detail"')
  && CoordinatorPreventiveOrdersTab.template.includes('@click.stop="openDetailModal(order)"')  
  && CoordinatorPreventiveOrdersTab.template.includes('data-testid="btn-assign-order"')
  && CoordinatorPreventiveOrdersTab.template.includes('data-testid="btn-cancel-order"'));

assert('4.4 El modal se monta con is-open y order-id sobre el estado de la pestaña',
  CoordinatorPreventiveOrdersTab.template.includes('<CoordinatorPreventiveOrderDetailModal')
  && CoordinatorPreventiveOrdersTab.template.includes(':is-open="showDetailModal"')
  && CoordinatorPreventiveOrdersTab.template.includes(':order-id="detailOrderId"'));

const detailTrayInstance = {
  detailOrderId: null,
  showDetailModal: false,
  ...CoordinatorPreventiveOrdersTab.methods
};
detailTrayInstance.openDetailModal({ id: 7, order_code: 'ORD-PREV-2026-0007' });
assert('4.5 Abrir el detalle fija la orden seleccionada y muestra el modal',
  detailTrayInstance.detailOrderId === 7 && detailTrayInstance.showDetailModal === true);
detailTrayInstance.closeDetailModal();
assert('4.6 Cerrar el detalle libera la selección sin tocar la tabla',
  detailTrayInstance.detailOrderId === null && detailTrayInstance.showDetailModal === false);

assert('4.7 Los badges de la tabla delegan en el util compartido (fuente única de etiquetas)',
  CoordinatorPreventiveOrdersTab.methods.getStatusBadge.call({}, 'COMPLETED').label === 'Completada'
  && CoordinatorPreventiveOrdersTab.methods.getOrderTypeLabel.call({}, 'ROUTINE') === 'Ordinaria');

assert('4.8 La vista de Coordinación cablea el salto a la ficha de avería existente',
  CoordinatorDashboardView.template.includes('@open-incident-detail="openIncidentDetailFromPreventive"')
  && typeof CoordinatorDashboardView.methods.openIncidentDetailFromPreventive === 'function');

const jumpViewInstance = {
  incidents: [{ id: 3661, ticket_code: 'INC-DEMO-0922' }],
  selectedDetailIncident: null,
  showDetailModal: false,
  ...CoordinatorDashboardView.methods
};
jumpViewInstance.openIncidentDetailFromPreventive(3661);
assert('4.9 El salto abre el modal de detalle de incidencias con el ticket vinculado',
  jumpViewInstance.selectedDetailIncident?.id === 3661 && jumpViewInstance.showDetailModal === true);

console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: ¡Todas las pruebas frontend pasaron con éxito (${assertions} aserciones)! (0 fallos)\n`);
} else {
  console.error(` RESULTADO: ${failures} prueba(s) fallaron de ${assertions} aserciones.\n`);
  process.exit(1);
}
console.log('======================================================================\n');
