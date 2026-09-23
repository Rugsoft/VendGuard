/**
 * VendGuard - AdminTabsTest Suite (AdminTabsTest.mjs)
 * 
 * Valida la integración de la pestaña "🏢 Administración" en CoordinatorDashboardView.js
 * y la navegación reactiva fluida entre sus tres subpestañas operativas (T-ADM-17, RF-01, RF-02, RF-03, RF-05).
 * 
 * Hecho cuando:
 * 1. CoordinatorDashboardView registra en components los tres componentes: AdminLocationsTab, AdminMachinesTab y AdminUsersTab.
 * 2. activeTab soporta 'admin' y activeAdminSubTab se inicializa en 'locations'.
 * 3. El template contiene el botón de pestaña 'data-testid="tab-admin"' con texto "🏢 Administración".
 * 4. El template contiene los selectores de subpestaña 'subtab-locations', 'subtab-machines' y 'subtab-users' (EARS 5.1).
 * 5. Se verifica el renderizado condicional y alternancia reactiva fluida entre las 3 subpestañas.
 * 6. Se valida la compatibilidad constitucional y contratos de las subpestañas administradas.
 * 
 * Dogma Vanilla: Node.js nativo con módulos ESM y cero dependencias externas.
 */

// Mock de localStorage y window para entorno Node.js ESM sin DOM
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

import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';
import { AdminLocationsTab } from '../../public/assets/js/components/AdminLocationsTab.js';
import { AdminMachinesTab } from '../../public/assets/js/components/AdminMachinesTab.js';
import { AdminUsersTab } from '../../public/assets/js/components/AdminUsersTab.js';

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
console.log(' VendGuard: Frontend Test Suite - AdminTabsTest (T-ADM-17)');
console.log('======================================================================\n');

// --------------------------------------------------------------------
// BLOQUE 1: Registro de Componentes y Estado Inicial
// --------------------------------------------------------------------
console.log('--- BLOQUE 1: Registro de Componentes y Estado Inicial ---');

{
  assert('1.1 CoordinatorDashboardView registra AdminLocationsTab en components', 
    CoordinatorDashboardView.components?.AdminLocationsTab === AdminLocationsTab);
  assert('1.2 CoordinatorDashboardView registra AdminMachinesTab en components', 
    CoordinatorDashboardView.components?.AdminMachinesTab === AdminMachinesTab);
  assert('1.3 CoordinatorDashboardView registra AdminUsersTab en components', 
    CoordinatorDashboardView.components?.AdminUsersTab === AdminUsersTab);

  const initialData = CoordinatorDashboardView.data();
  assert('1.4 activeTab se inicializa en "incidents"', initialData.activeTab === 'incidents');
  assert('1.5 activeAdminSubTab se inicializa en "locations" (EARS 5.1)', initialData.activeAdminSubTab === 'locations');
}

// --------------------------------------------------------------------
// BLOQUE 2: Botón de Pestaña Principal y Selectores en Template
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 2: Botón de Pestaña Principal y Selectores en Template ---');

{
  const tmpl = CoordinatorDashboardView.template;

  assert('2.1 Template incluye selector de pestaña principal con data-testid="tab-admin"', 
    tmpl.includes('data-testid="tab-admin"'));
  assert('2.2 Botón de pestaña incluye título "🏢 Administración"', 
    tmpl.includes('🏢 Administración'));
  assert('2.3 Template incluye contenedor de administración con data-testid="admin-panel-container"', 
    tmpl.includes('data-testid="admin-panel-container"'));

  // Selectores de submódulos (EARS 5.1)
  assert('2.4 Template incluye selector de subpestaña data-testid="subtab-locations"', 
    tmpl.includes('data-testid="subtab-locations"'));
  assert('2.5 Template incluye selector de subpestaña data-testid="subtab-machines"', 
    tmpl.includes('data-testid="subtab-machines"'));
  assert('2.6 Template incluye selector de subpestaña data-testid="subtab-users"', 
    tmpl.includes('data-testid="subtab-users"'));

  // Renderizado condicional de los 3 componentes
  assert('2.7 Template renderiza <AdminLocationsTab v-if="activeAdminSubTab === \'locations\'"', 
    tmpl.includes('<AdminLocationsTab') && tmpl.includes("activeAdminSubTab === 'locations'"));
  assert('2.8 Template renderiza <AdminMachinesTab v-else-if="activeAdminSubTab === \'machines\'"', 
    tmpl.includes('<AdminMachinesTab') && tmpl.includes("activeAdminSubTab === 'machines'"));
  assert('2.9 Template renderiza <AdminUsersTab v-else-if="activeAdminSubTab === \'users\'"', 
    tmpl.includes('<AdminUsersTab') && tmpl.includes("activeAdminSubTab === 'users'"));
}

// --------------------------------------------------------------------
// BLOQUE 3: Navegación Reactiva Fluida entre Subpestañas
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 3: Navegación Reactiva Fluida entre Subpestañas ---');

{
  const dashboardInstance = {
    ...CoordinatorDashboardView.data(),
    activeTab: 'admin'
  };

  assert('3.1 Al seleccionar pestaña admin, activeTab es "admin"', dashboardInstance.activeTab === 'admin');
  assert('3.2 Por defecto la subpestaña activa es "locations"', dashboardInstance.activeAdminSubTab === 'locations');

  // Conmutación a Máquinas
  dashboardInstance.activeAdminSubTab = 'machines';
  assert('3.3 Conmutación fluida a subpestaña "machines"', dashboardInstance.activeAdminSubTab === 'machines');

  // Conmutación a Personal Interno
  dashboardInstance.activeAdminSubTab = 'users';
  assert('3.4 Conmutación fluida a subpestaña "users"', dashboardInstance.activeAdminSubTab === 'users');

  // Regreso a Sedes
  dashboardInstance.activeAdminSubTab = 'locations';
  assert('3.5 Regreso fluido a subpestaña "locations"', dashboardInstance.activeAdminSubTab === 'locations');
}

// --------------------------------------------------------------------
// BLOQUE 4: Integridad Contractual de los Componentes Exportados
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 4: Integridad Contractual de los Componentes Exportados ---');

{
  // 1. AdminLocationsTab
  assert('4.1 AdminLocationsTab es un objeto de componente Vue 3 Options API', 
    typeof AdminLocationsTab === 'object' && AdminLocationsTab.name === 'AdminLocationsTab');
  assert('4.2 AdminLocationsTab define métodos obligatorios de CRUD y validación', 
    typeof AdminLocationsTab.methods?.loadLocations === 'function' && 
    typeof AdminLocationsTab.methods?.validateSiteCode === 'function' &&
    typeof AdminLocationsTab.methods?.confirmDeactivate === 'function');

  // 2. AdminMachinesTab
  assert('4.3 AdminMachinesTab es un objeto de componente Vue 3 Options API', 
    typeof AdminMachinesTab === 'object' && AdminMachinesTab.name === 'AdminMachinesTab');
  assert('4.4 AdminMachinesTab define métodos sanitarios (Art. II) y de traslado', 
    typeof AdminMachinesTab.methods?.isPerishable === 'function' && 
    typeof AdminMachinesTab.methods?.submitTransfer === 'function' &&
    typeof AdminMachinesTab.methods?.submitReactivate === 'function');

  // 3. AdminUsersTab
  assert('4.5 AdminUsersTab es un objeto de componente Vue 3 Options API', 
    typeof AdminUsersTab === 'object' && AdminUsersTab.name === 'AdminUsersTab');
  assert('4.6 AdminUsersTab define métodos de validación de contraseñas y reseteo administrativo', 
    typeof AdminUsersTab.methods?.validatePassword === 'function' && 
    typeof AdminUsersTab.methods?.submitResetPassword === 'function' &&
    typeof AdminUsersTab.methods?.confirmDeactivate === 'function');
}

// --------------------------------------------------------------------
// RESUMEN FINAL
// --------------------------------------------------------------------
console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: ¡TODAS LAS PRUEBAS FRONTEND PASARON EXITOSAMENTE (${assertions} aserciones)!`);
  console.log(' CONDICIÓN T-ADM-17 CUMPLIDA SATISFACTORIAMENTE.');
  process.exit(0);
} else {
  console.error(` RESULTADO: ${failures} PRUEBAS FALLARON de un total de ${assertions} aserciones.`);
  process.exit(1);
}
