/**
 * VendGuard - CoordinatorFleetTab Test Suite (CoordinatorFleetTabTest.mjs)
 * 
 * Valida la nueva sección "Parque de Sedes y Máquinas" en el panel de coordinación (RF-FLEET-01, RF-FLEET-02, RF-FLEET-03).
 * 
 * Hecho cuando:
 * 1. Carga y lista sedes activas con conteo de máquinas instaladas.
 * 2. Carga y renderiza el parque de máquinas de la sede seleccionada.
 * 3. Aplica filtros reactivos por texto, estado operativo y alimentos perecederos (Art. II).
 * 4. Emite el evento open-qr con los metadatos de la máquina al pulsar "🏷️ Imprimir QR".
 * 5. Emite el evento open-batch-print con la sede activa al pulsar "📄 Etiquetas de Sede (A4)".
 * 6. CoordinatorDashboardView integra la navegación por pestañas ('incidents' y 'fleet').
 */

// Mock de entorno browser para Node.js ESM
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
import { CoordinatorFleetTab } from '../../public/assets/js/components/CoordinatorFleetTab.js';
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
      console.error(`         Reason: ${details}`);
    }
    failures++;
  }
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - CoordinatorFleetTab (RF-FLEET)');
console.log('======================================================================\n');

// Mock data fixtures
const mockLocations = [
  {
    id: 1,
    site_code: 'SEDE-BCN-01',
    name: 'Hospital del Mar - Edificio Central',
    address: 'Passeig Marítim de la Barceloneta, 25-29, 08003 Barcelona',
    contact_phone: '600111222',
    machine_count: 2
  },
  {
    id: 2,
    site_code: 'SEDE-BCN-02',
    name: 'Campus Nord UPC - Facultat d\'Informàtica',
    address: 'Carrer de Jordi Girona, 1-3, 08034 Barcelona',
    contact_phone: '600333444',
    machine_count: 1
  }
];

const mockMachinesLoc1 = [
  {
    id: 1,
    location_id: 1,
    code: 'VEND-0101',
    model: 'Sanden Vendo G-Drink',
    machine_type: 'PERISHABLE_FOOD',
    floor_wing: 'Planta Baja - Urgencias',
    is_perishable: true,
    operational_status: 'OPERATIONAL',
    active_incident: null
  },
  {
    id: 2,
    location_id: 1,
    code: 'VEND-0102',
    model: 'Bianchi Gaia Espresso',
    machine_type: 'HOT_DRINKS',
    floor_wing: 'Planta 1 - Sala Médica',
    is_perishable: false,
    operational_status: 'ACTIVE_INCIDENT',
    active_incident: {
      ticket_code: 'INC-2026-0001',
      urgency: 'HIGH',
      status: 'ASSIGNED'
    }
  }
];

async function runTests() {
  // =====================================================================
  // CASO 1: Carga Inicial de Sedes y Preselección
  // =====================================================================
  console.log('--- Caso 1: Carga Inicial de Sedes y Preselección ---');

  api.coordinator.getLocations = async () => ({
    success: true,
    data: mockLocations
  });

  api.coordinator.getLocationMachines = async (locId) => ({
    success: true,
    data: {
      location: mockLocations.find(l => l.id === Number(locId)) || mockLocations[0],
      machines: Number(locId) === 1 ? mockMachinesLoc1 : []
    }
  });

  const fleetInstance = {
    ...CoordinatorFleetTab.data(),
    $emit(event, payload) {
      this._lastEmitted = { event, payload };
    }
  };
  Object.assign(fleetInstance, CoordinatorFleetTab.methods);
  for (const [key, getter] of Object.entries(CoordinatorFleetTab.computed)) {
    Object.defineProperty(fleetInstance, key, { get: getter });
  }

  await fleetInstance.loadLocations();

  assert('1.1 locations cargadas correctamente (2 sedes)', fleetInstance.locations.length === 2);
  assert('1.2 Preselecciona automáticamente la primera sede (ID 1)', fleetInstance.selectedLocationId === 1);
  assert('1.3 selectedLocation inicializado con SEDE-BCN-01', fleetInstance.selectedLocation?.site_code === 'SEDE-BCN-01');
  assert('1.4 machines cargadas para la sede 1 (2 máquinas)', fleetInstance.machines.length === 2);

  // =====================================================================
  // CASO 2: Métricas y Filtrado Reactivo
  // =====================================================================
  console.log('\n--- Caso 2: Métricas y Filtrado Reactivo ---');

  const metrics = fleetInstance.metrics;
  assert('2.1 metrics.total reporta 2 máquinas', metrics.total === 2);
  assert('2.2 metrics.operational reporta 1 máquina operativa', metrics.operational === 1);
  assert('2.3 metrics.incidents reporta 1 máquina con avería', metrics.incidents === 1);
  assert('2.4 metrics.perishables reporta 1 máquina de alimentos perecederos', metrics.perishables === 1);

  // Filtro por texto de búsqueda
  fleetInstance.searchQuery = 'VEND-0101';
  assert('2.5 Buscador filtra por código exacto', fleetInstance.filteredMachines.length === 1 && fleetInstance.filteredMachines[0].code === 'VEND-0101');

  fleetInstance.searchQuery = '';

  // Filtro por estado operativo
  fleetInstance.filterStatus = 'OPERATIONAL';
  assert('2.6 Filtro OPERATIONAL devuelve solo máquinas en servicio', fleetInstance.filteredMachines.length === 1 && fleetInstance.filteredMachines[0].operational_status === 'OPERATIONAL');

  fleetInstance.filterStatus = 'INCIDENT';
  assert('2.7 Filtro INCIDENT devuelve solo máquinas con avería', fleetInstance.filteredMachines.length === 1 && fleetInstance.filteredMachines[0].operational_status === 'ACTIVE_INCIDENT');

  fleetInstance.filterStatus = 'PERISHABLE';
  assert('2.8 Filtro PERISHABLE devuelve solo máquinas perecederas (Art. II)', fleetInstance.filteredMachines.length === 1 && fleetInstance.filteredMachines[0].is_perishable === true);

  fleetInstance.filterStatus = 'ALL';

  // =====================================================================
  // CASO 3: Emisión de Eventos QR (Individual y Lote A4)
  // =====================================================================
  console.log('\n--- Caso 3: Emisión de Eventos QR (Individual y Lote A4) ---');

  // 3.1 Click en "🏷️ Imprimir QR"
  fleetInstance.handleOpenQr(mockMachinesLoc1[0]);
  assert('3.1 Emite evento open-qr', fleetInstance._lastEmitted?.event === 'open-qr');
  assert('3.2 Payload open-qr contiene código VEND-0101', fleetInstance._lastEmitted?.payload?.code === 'VEND-0101');
  assert('3.3 Payload open-qr contiene objeto location', fleetInstance._lastEmitted?.payload?.location?.site_code === 'SEDE-BCN-01');

  // 3.2 Click en "📄 Etiquetas de Sede (A4)"
  fleetInstance.handleOpenBatchPrint();
  assert('3.4 Emite evento open-batch-print', fleetInstance._lastEmitted?.event === 'open-batch-print');
  assert('3.5 Payload open-batch-print contiene locationId = 1', fleetInstance._lastEmitted?.payload?.locationId === 1);

  // =====================================================================
  // CASO 4: Integración en CoordinatorDashboardView
  // =====================================================================
  console.log('\n--- Caso 4: Integración en CoordinatorDashboardView ---');

  assert('4.1 CoordinatorDashboardView registra CoordinatorFleetTab en components', 
    CoordinatorDashboardView.components.CoordinatorFleetTab === CoordinatorFleetTab);

  const dashData = CoordinatorDashboardView.data();
  assert('4.2 activeTab inicializado en "incidents"', dashData.activeTab === 'incidents');

  assert('4.3 Template incluye selector de pestaña tab-incidents', 
    CoordinatorDashboardView.template.includes('data-testid="tab-incidents"'));
  assert('4.4 Template incluye selector de pestaña tab-fleet', 
    CoordinatorDashboardView.template.includes('data-testid="tab-fleet"'));
  assert('4.5 Template incluye renderizado condicional de CoordinatorFleetTab', 
    CoordinatorDashboardView.template.includes('<CoordinatorFleetTab'));

  // =====================================================================
  // Resumen Final
  // =====================================================================
  console.log('\n======================================================================');
  if (failures === 0) {
    console.log(` RESULTADO: ¡TODAS LAS PRUEBAS PASARON (${assertions} aserciones, 0 fallos)!`);
    console.log(' SECCIÓN DE PARQUE DE SEDES Y MÁQUINAS VERIFICADA SATISFACTORIAMENTE.');
  } else {
    console.error(` RESULTADO: ${failures} PRUEBA(S) FALLIDA(S) de ${assertions} evaluadas.`);
  }
  console.log('======================================================================\n');

  if (failures > 0) {
    process.exit(1);
  }
}

runTests().catch(err => {
  console.error('Error fatal en ejecución de tests:', err);
  process.exit(1);
});
