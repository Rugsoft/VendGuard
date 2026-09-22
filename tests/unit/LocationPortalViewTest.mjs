/**
 * VendGuard - LocationPortalView & MachineCard Test Suite (T-34)
 * 
 * Verifies:
 * 1. Location Responsible logs in with site code (SEDE-BCN-01) without passwords (RF-01).
 * 2. Visualizes machines in 8px radius cards (--radius-card, RNF-06).
 * 3. Clearly distinguishes between operational machines and those with active incidents (RF-02).
 * 4. Informs user that duplicate tickets are blocked on machines with open incidents (RF-02).
 * 5. Provides reopen action for machines under 48h warranty.
 * 6. Filters machines by status (All, Active Incident, Operational).
 */

// Mock localStorage for headless Node environment
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
import { store, clearSession, setSiteSession } from '../../public/assets/js/store.js';
import { MachineCard } from '../../public/assets/js/components/MachineCard.js';
import { LocationPortalView } from '../../public/assets/js/views/LocationPortalView.js';

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
console.log(' VendGuard: Frontend Test Suite - LocationPortalView (T-34)');
console.log('======================================================================\n');

// Mock data fixtures
const mockMachines = [
  {
    id: 1,
    code: 'VEND-0101',
    model: 'Sanden Vendo G-Drink',
    machine_type: 'PERISHABLE_FOOD',
    floor_wing: 'Planta Baja - Urgencias',
    notes: 'Máquina de sándwiches y lácteos frescos',
    active_incident: null // Operational
  },
  {
    id: 2,
    code: 'VEND-0102',
    model: 'Necta Canto Touch',
    machine_type: 'HOT_DRINKS',
    floor_wing: 'Planta 1 - Sala de Espera',
    notes: 'Café en grano y solubles',
    active_incident: {
      id: 101,
      ticket_code: 'INC-2026-0042',
      status: 'EN_CURSO',
      urgency: 'HIGH',
      category: 'PAYMENT_SYSTEM',
      created_at: '2026-09-22 10:00:00'
    } // Active incident
  },
  {
    id: 3,
    code: 'VEND-0103',
    model: 'Fas Fast 1050',
    machine_type: 'SNACKS',
    floor_wing: 'Planta 2 - Cafetería de Personal',
    notes: 'Snacks y frutos secos',
    active_incident: {
      id: 99,
      ticket_code: 'INC-2026-0035',
      status: 'RESUELTA',
      urgency: 'MEDIUM',
      category: 'PRODUCT_JAM',
      created_at: '2026-09-21 15:00:00',
      resolved_at: '2026-09-22 08:00:00'
    } // Under warranty (<48h)
  }
];

// ---------------------------------------------------------------------
// TEST GROUP 1: Site Code Authentication Workflow
// ---------------------------------------------------------------------
console.log('--- Group 1: Site Code Authentication Flow (RF-01) ---');

clearSession();
assert('1.1 Initial view starts unauthenticated', store.isSiteSession === false);

// Mock api.auth.siteLogin
api.auth.siteLogin = async (code) => {
  if (code === 'SEDE-BCN-01') {
    return {
      token: 'site_token_bcn_test',
      location: {
        id: 1,
        site_code: 'SEDE-BCN-01',
        name: 'Hospital del Mar - Edificio Central',
        address: 'Passeig Marítim 25, Barcelona',
        contact_name: 'Laura Sanitaria'
      }
    };
  }
  const err = new Error('Código de sede no reconocido.');
  err.status = 401;
  throw err;
};

// Test unauthenticated template
assert('1.2 Unauthenticated template renders site code input', LocationPortalView.template.includes('id="site-code-input"'));
assert('1.3 Unauthenticated template renders uppercase input hint', LocationPortalView.template.includes('SEDE-BCN-01'));

// Simulate login call
const viewInstance = {
  siteCodeInput: 'SEDE-BCN-01',
  loginError: '',
  isLoggingIn: false,
  loadMachines: async () => {},
  ...LocationPortalView.methods
};

await viewInstance.handleSiteLogin();

assert('1.4 handleSiteLogin() authenticates and updates store session', store.isSiteSession === true);
assert('1.5 store.state.location matches logged in site', store.state.location?.site_code === 'SEDE-BCN-01');
assert('1.6 siteCodeInput is cleared upon success', viewInstance.siteCodeInput === '');

// ---------------------------------------------------------------------
// TEST GROUP 2: MachineCard Rendering & 8px Card Radius
// ---------------------------------------------------------------------
console.log('\n--- Group 2: MachineCard 8px Container Radius and Styles (RNF-06) ---');

assert('2.1 MachineCard template defines 8px card border radius', MachineCard.template.includes("borderRadius: 'var(--radius-card, 8px)'"));
assert('2.2 MachineCard uses .vg-card container class', MachineCard.template.includes('class="vg-card vg-machine-card"'));

// Helper to evaluate MachineCard computed properties
function evalMachine(machine) {
  const instance = {
    machine,
    get activeIncident() { return MachineCard.computed.activeIncident.call(this); },
    get hasActiveIncident() { return MachineCard.computed.hasActiveIncident.call(this); },
    get isUnderWarranty() { return MachineCard.computed.isUnderWarranty.call(this); },
    get typeInfo() { return MachineCard.computed.typeInfo.call(this); },
    get cardBorderColor() { return MachineCard.computed.cardBorderColor.call(this); }
  };
  return {
    hasActiveIncident: instance.hasActiveIncident,
    isUnderWarranty: instance.isUnderWarranty,
    typeInfo: instance.typeInfo,
    cardBorderColor: instance.cardBorderColor
  };
}

// 2.3: Operational machine
const opCard = evalMachine(mockMachines[0]);
assert('2.3 Operational machine: hasActiveIncident is false', opCard.hasActiveIncident === false);
assert('2.4 Operational machine: isUnderWarranty is false', opCard.isUnderWarranty === false);
assert('2.5 Perishable food machine identified', opCard.typeInfo.isPerishable === true);

// 2.6: Active Incident machine
const activeCard = evalMachine(mockMachines[1]);
assert('2.6 Active incident machine: hasActiveIncident is true', activeCard.hasActiveIncident === true);
assert('2.7 Active incident machine: isUnderWarranty is false', activeCard.isUnderWarranty === false);
assert('2.8 Active incident machine card border is reddish (#fca5a5)', activeCard.cardBorderColor === '#fca5a5');

// 2.9: Machine under warranty (<48h)
const warrantyCard = evalMachine(mockMachines[2]);
assert('2.9 Machine under warranty: hasActiveIncident is true', warrantyCard.hasActiveIncident === true);
assert('2.10 Machine under warranty: isUnderWarranty is true', warrantyCard.isUnderWarranty === true);
assert('2.11 Warranty machine card border is greenish (#86efac)', warrantyCard.cardBorderColor === '#86efac');

// ---------------------------------------------------------------------
// TEST GROUP 3: Strict Prevention of Duplicates in UI (RF-02)
// ---------------------------------------------------------------------
console.log('\n--- Group 3: Prevention of Duplicates & Contextual Actions (RF-02) ---');

// Check MachineCard template conditional buttons
assert('3.1 Operational machine displays "Reportar avería" primary button', MachineCard.template.includes('Reportar avería') && MachineCard.template.includes('vg-btn-primary'));
assert('3.2 Machine with active incident blocks new report and displays "Añadir comentarios / fotos"', MachineCard.template.includes('Añadir comentarios / fotos'));
assert('3.3 Machine with active incident explains duplicate block', MachineCard.template.includes('No es posible abrir un nuevo ticket para esta máquina'));
assert('3.4 Machine under warranty provides "Reabrir incidencia" button', MachineCard.template.includes('Reabrir incidencia'));

// Check button events
let emittedCardEvents = [];
const mockCardInstance = {
  machine: mockMachines[0],
  $emit: (evt, val) => { emittedCardEvents.push({ evt, val }); }
};

MachineCard.methods.handleReportClick.call(mockCardInstance);
assert('3.5 Clicking report button emits "report" event with machine data', emittedCardEvents.some(e => e.evt === 'report' && e.val.id === 1));

emittedCardEvents = [];
mockCardInstance.machine = mockMachines[1];
MachineCard.methods.handleCommentClick.call(mockCardInstance);
assert('3.6 Clicking comment button emits "comment" event with machine data', emittedCardEvents.some(e => e.evt === 'comment' && e.val.id === 2));

emittedCardEvents = [];
mockCardInstance.machine = mockMachines[2];
MachineCard.methods.handleReopenClick.call(mockCardInstance);
assert('3.7 Clicking reopen button emits "reopen" event with machine data', emittedCardEvents.some(e => e.evt === 'reopen' && e.val.id === 3));

// ---------------------------------------------------------------------
// TEST GROUP 4: LocationPortalView Filtering and Grid
// ---------------------------------------------------------------------
console.log('\n--- Group 4: LocationPortalView Filtering & Responsive Grid ---');

const portalInstance = {
  machines: mockMachines,
  activeFilter: 'all',
  ...LocationPortalView.computed
};

// 4.1: Filter 'all'
let filtered = LocationPortalView.computed.filteredMachines.call(portalInstance);
assert('4.1 Filter "all" returns all 3 machines', filtered.length === 3);
assert('4.2 totalCount is 3', LocationPortalView.computed.totalCount.call(portalInstance) === 3);

// 4.2: Filter 'incident'
portalInstance.activeFilter = 'incident';
filtered = LocationPortalView.computed.filteredMachines.call(portalInstance);
assert('4.2 Filter "incident" returns 2 machines with active or warranty incident', filtered.length === 2);
assert('4.3 incidentCount is 2', LocationPortalView.computed.incidentCount.call(portalInstance) === 2);

// 4.3: Filter 'operational'
portalInstance.activeFilter = 'operational';
filtered = LocationPortalView.computed.filteredMachines.call(portalInstance);
assert('4.4 Filter "operational" returns 1 machine', filtered.length === 1 && filtered[0].id === 1);
assert('4.5 operationalCount is 1', LocationPortalView.computed.operationalCount.call(portalInstance) === 1);

// 4.6: Responsive grid template verification
assert('4.6 Template defines responsive CSS grid with minmax(280px, 1fr)', LocationPortalView.template.includes('repeat(auto-fill, minmax(280px, 1fr))'));
assert('4.7 Template renders MachineCard components with event listeners', LocationPortalView.template.includes('<MachineCard') && LocationPortalView.template.includes('@report="onReport"'));

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-34 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
