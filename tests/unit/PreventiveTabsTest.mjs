/**
 * VendGuard - Global Preventive Integration Test Suite (PreventiveTabsTest.mjs)
 * 
 * Task: T-PREV-24
 * Requirements: RF-PREV-01 to RF-PREV-08, RNF-01 to RNF-06, Constitución Art. I al VII.
 * 
 * Verifies that:
 * 1. App Root correctly orchestrates role-based navigation and deep link QR routing.
 * 2. Coordinator views, tabs, modals, and settings are fully wired and functional.
 * 3. Technician route, opportunistic claim, checklist, and reinspection modals are integrated.
 * 4. Site responsible portal integrates sanitary semaphores and printable A4 certificates (Art. V.4).
 * 5. Public QR scan handles sanitary quarantine (Art. II) and seasonal pause.
 * 6. ApiClient exposes all preventive endpoints across coordinator, technician, and site.
 * 
 * Dogma Vanilla: Pure JavaScript ESM (Node.js).
 * Dualismo Lingüístico: Test in English; UI assertions in Spanish.
 */

// Mock browser globals for Node ESM environment
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
  location: {
    search: '',
    href: 'http://localhost/'
  },
  print: () => {}
};

import { api } from '../../public/assets/js/api.js';
import { store, clearSession, setInternalSession, setSiteSession } from '../../public/assets/js/store.js';
import { App } from '../../public/assets/js/app.js';
import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';
import { TechnicianRouteView } from '../../public/assets/js/views/TechnicianRouteView.js';
import { LocationPortalView } from '../../public/assets/js/views/LocationPortalView.js';
import { QrReportView } from '../../public/assets/js/views/QrReportView.js';

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
console.log(' VendGuard: Frontend Test Suite - Global Preventive Integration (T-PREV-24)');
console.log('======================================================================\n');

// ---------------------------------------------------------------------
// TEST GROUP 1: App Root View Orchestration & Navigation
// ---------------------------------------------------------------------
console.log('--- Group 1: App Root View Orchestration & Profile Switching ---');

assert('1.1 App registers LocationPortalView component', App.components.LocationPortalView !== undefined);
assert('1.2 App registers CoordinatorDashboardView component', App.components.CoordinatorDashboardView !== undefined);
assert('1.3 App registers TechnicianRouteView component', App.components.TechnicianRouteView !== undefined);
assert('1.4 App registers QrReportView component', App.components.QrReportView !== undefined);

// Test switchView
const appInstance = {
  ...App.data(),
  ...App.methods
};

assert('1.5 Initial currentView is "portal"', appInstance.currentView === 'portal');
appInstance.switchView('coordinator');
assert('1.6 switchView("coordinator") updates currentView to "coordinator"', appInstance.currentView === 'coordinator');
appInstance.switchView('technician');
assert('1.7 switchView("technician") updates currentView to "technician"', appInstance.currentView === 'technician');
appInstance.switchView('portal');
assert('1.8 switchView("portal") updates currentView to "portal"', appInstance.currentView === 'portal');

// Test role-based session restore
clearSession();
setInternalSession(
  { id: 2, name: 'Clara Coordinadora', email: 'coordinacion@vendguard.internal', role: 'COORDINATOR' },
  'coord_token_test'
);

const coordApp = {
  ...App.data(),
  ...App.methods
};
App.created.call(coordApp);
assert('1.9 Authenticated COORDINATOR session auto-routes to "coordinator" view', coordApp.currentView === 'coordinator');

clearSession();
setInternalSession(
  { id: 3, name: 'Jordi Técnico', email: 'jordi.ruta@vendguard.internal', role: 'TECHNICIAN' },
  'tech_token_test'
);

const techApp = {
  ...App.data(),
  ...App.methods
};
App.created.call(techApp);
assert('1.10 Authenticated TECHNICIAN session auto-routes to "technician" view', techApp.currentView === 'technician');

clearSession();
setSiteSession(
  { id: 1, site_code: 'SEDE-BCN-01', name: 'Hospital del Mar' },
  'site_token_test'
);

const siteApp = {
  ...App.data(),
  ...App.methods
};
App.created.call(siteApp);
assert('1.11 Authenticated site session auto-routes to "portal" view', siteApp.currentView === 'portal');
assert('1.12 Site code restored as SEDE-BCN-01', siteApp.siteCode === 'SEDE-BCN-01');

// Deep Link QR route test
window.location.search = '?qr=VEND-0101';
const qrApp = {
  ...App.data(),
  ...App.methods
};
App.created.call(qrApp);
assert('1.13 ?qr=VEND-0101 in URL auto-routes to "qr" view (EARS 3.1)', qrApp.currentView === 'qr');
assert('1.14 qrMachineCode is extracted as VEND-0101', qrApp.qrMachineCode === 'VEND-0101');
window.location.search = '';

// ---------------------------------------------------------------------
// TEST GROUP 2: Coordinator Preventive Integration (T-PREV-18, T-PREV-19)
// ---------------------------------------------------------------------
console.log('\n--- Group 2: Coordinator Preventive Dashboard & Orders (T-PREV-18, T-PREV-19) ---');

assert('2.1 CoordinatorDashboardView registers CoordinatorPreventiveDashboard', CoordinatorDashboardView.components.CoordinatorPreventiveDashboard !== undefined);
assert('2.2 CoordinatorDashboardView registers CoordinatorPreventiveOrdersTab', CoordinatorDashboardView.components.CoordinatorPreventiveOrdersTab !== undefined);
assert('2.3 CoordinatorDashboardView registers CoordinatorPreventiveSettingsModal', CoordinatorDashboardView.components.CoordinatorPreventiveSettingsModal !== undefined);

const coordTemplate = CoordinatorDashboardView.template;
assert('2.4 Coordinator template includes "Mantenimiento Preventivo" navigation tab', coordTemplate.includes('Mantenimiento Preventivo'));
assert('2.5 Coordinator template includes preventive subtabs (dashboard / orders)', coordTemplate.includes("activePreventiveSubTab === 'dashboard'") && coordTemplate.includes("activePreventiveSubTab === 'orders'"));
assert('2.6 Coordinator template renders CoordinatorPreventiveDashboard component', coordTemplate.includes('<CoordinatorPreventiveDashboard'));
assert('2.7 Coordinator template renders CoordinatorPreventiveOrdersTab component', coordTemplate.includes('<CoordinatorPreventiveOrdersTab'));
assert('2.8 Coordinator template renders CoordinatorPreventiveSettingsModal component', coordTemplate.includes('<CoordinatorPreventiveSettingsModal'));

// ---------------------------------------------------------------------
// TEST GROUP 3: Technician Preventive Route & Modals (T-PREV-20, T-PREV-21)
// ---------------------------------------------------------------------
console.log('\n--- Group 3: Technician Route & Preventive Modals (T-PREV-20, T-PREV-21) ---');

assert('3.1 TechnicianRouteView registers TechnicianPreventiveRouteTab', TechnicianRouteView.components.TechnicianPreventiveRouteTab !== undefined);
assert('3.2 TechnicianRouteView registers TechnicianChecklistModal', TechnicianRouteView.components.TechnicianChecklistModal !== undefined);
assert('3.3 TechnicianRouteView registers TechnicianReinspectionModal', TechnicianRouteView.components.TechnicianReinspectionModal !== undefined);

const techTemplate = TechnicianRouteView.template;
assert('3.4 Technician template includes "Preventivo" section tab', techTemplate.includes('Preventivo'));
assert('3.5 Technician template renders TechnicianPreventiveRouteTab component', techTemplate.includes('<TechnicianPreventiveRouteTab'));
assert('3.6 Technician template renders TechnicianChecklistModal component', techTemplate.includes('<TechnicianChecklistModal'));
assert('3.7 Technician template renders TechnicianReinspectionModal component', techTemplate.includes('<TechnicianReinspectionModal'));

// ---------------------------------------------------------------------
// TEST GROUP 4: Location Portal Sanitary Status & Certificates (T-PREV-22)
// ---------------------------------------------------------------------
console.log('\n--- Group 4: Site Sanitary Status & Certificates (T-PREV-22) ---');

assert('4.1 LocationPortalView registers SiteSanitaryStatusTab', LocationPortalView.components.SiteSanitaryStatusTab !== undefined);
assert('4.2 LocationPortalView registers SanitaryCertificateModal', LocationPortalView.components.SanitaryCertificateModal !== undefined);
assert('4.3 LocationPortalView registers SiteGlobalCertificateModal', LocationPortalView.components.SiteGlobalCertificateModal !== undefined);

const portalTemplate = LocationPortalView.template;
assert('4.4 Location portal template includes "Control Higiénico y Certificados" tab button', portalTemplate.includes('Control Higiénico y Certificados'));
assert('4.5 Location portal template renders SiteSanitaryStatusTab component', portalTemplate.includes('<SiteSanitaryStatusTab'));
assert('4.6 Location portal template renders SanitaryCertificateModal component', portalTemplate.includes('<SanitaryCertificateModal'));
assert('4.7 Location portal template renders SiteGlobalCertificateModal component', portalTemplate.includes('<SiteGlobalCertificateModal'));

// ---------------------------------------------------------------------
// TEST GROUP 5: Public QR Scan Quarantine & Pause (T-PREV-23)
// ---------------------------------------------------------------------
console.log('\n--- Group 5: Public QR Scan Quarantine & Seasonal Pause (T-PREV-23) ---');

assert('5.1 QrReportView registers QrSanitaryQuarantineModal', QrReportView.components.QrSanitaryQuarantineModal !== undefined);
assert('5.2 QrReportView registers QrSeasonalPauseNotice', QrReportView.components.QrSeasonalPauseNotice !== undefined);

const qrTemplate = QrReportView.template;
assert('5.3 QrReportView template conditionally renders QrSanitaryQuarantineModal on SANITARY_QUARANTINE', qrTemplate.includes('<QrSanitaryQuarantineModal') && qrTemplate.includes("statusMode === 'SANITARY_QUARANTINE'"));
assert('5.4 QrReportView template conditionally renders QrSeasonalPauseNotice on SEASONAL_PAUSE', qrTemplate.includes('<QrSeasonalPauseNotice') && qrTemplate.includes("statusMode === 'SEASONAL_PAUSE'"));

// ---------------------------------------------------------------------
// TEST GROUP 6: ApiClient Preventive API Completeness
// ---------------------------------------------------------------------
console.log('\n--- Group 6: ApiClient Preventive Methods Completeness ---');

// Coordinator preventive endpoints
assert('6.1 api.coordinator.getPreventiveDashboard exists', typeof api.coordinator.getPreventiveDashboard === 'function');
assert('6.2 api.coordinator.getPreventiveOrders exists', typeof api.coordinator.getPreventiveOrders === 'function');
assert('6.3 api.coordinator.createPreventiveOrder exists', typeof api.coordinator.createPreventiveOrder === 'function');
assert('6.4 api.coordinator.generateDuePreventiveOrders exists', typeof api.coordinator.generateDuePreventiveOrders === 'function');
assert('6.5 api.coordinator.assignPreventiveOrder exists', typeof api.coordinator.assignPreventiveOrder === 'function');
assert('6.6 api.coordinator.cancelPreventiveOrder exists', typeof api.coordinator.cancelPreventiveOrder === 'function');
assert('6.7 api.coordinator.getPreventiveSettings exists', typeof api.coordinator.getPreventiveSettings === 'function');
assert('6.8 api.coordinator.updatePreventiveSettings exists', typeof api.coordinator.updatePreventiveSettings === 'function');
assert('6.9 api.coordinator.updateMachinePreventiveConfig exists', typeof api.coordinator.updateMachinePreventiveConfig === 'function');
assert('6.9b api.coordinator.getPreventiveOrderDetail exists (ficha integral, RF-PD-01)',
  typeof api.coordinator.getPreventiveOrderDetail === 'function');

// La ficha preventiva debe apuntar al endpoint de detalle con el identificador codificado.
const originalApiGetForDetail = api.get;
let preventiveDetailPath = null;
api.get = (endpoint) => {
  preventiveDetailPath = endpoint;
  return Promise.resolve({});
};
api.coordinator.getPreventiveOrderDetail('ORD-PREV-2026-0001');
api.get = originalApiGetForDetail;
assert('6.9c La ficha preventiva resuelve contra /coordinator/preventive/orders/{id}/detail',
  preventiveDetailPath === '/coordinator/preventive/orders/ORD-PREV-2026-0001/detail');

// Technician preventive endpoints
assert('6.10 api.technician.getPreventiveRoute exists', typeof api.technician.getPreventiveRoute === 'function');
assert('6.11 api.technician.claimPreventiveOrder exists', typeof api.technician.claimPreventiveOrder === 'function');
assert('6.12 api.technician.getPreventiveChecklist exists', typeof api.technician.getPreventiveChecklist === 'function');
assert('6.13 api.technician.startPreventiveInspection exists', typeof api.technician.startPreventiveInspection === 'function');
assert('6.14 api.technician.completePreventiveInspection exists', typeof api.technician.completePreventiveInspection === 'function');
assert('6.15 api.technician.reinspectPreventiveOrder exists', typeof api.technician.reinspectPreventiveOrder === 'function');

// Site sanitary endpoints
assert('6.16 api.site.getSanitaryStatus exists', typeof api.site.getSanitaryStatus === 'function');
assert('6.17 api.site.getMachineCertificate exists', typeof api.site.getMachineCertificate === 'function');
assert('6.18 api.site.getGlobalCertificate exists', typeof api.site.getGlobalCertificate === 'function');

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-PREV-24 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
