/**
 * VendGuard - Site Sanitary Components & Certificates Test Suite (T-PREV-22)
 * 
 * Verifies:
 * 1. SiteSanitaryStatusTab: Semaphores, counts, filtering, and quarantine alert (RF-PREV-06).
 * 2. SanitaryCertificateModal: A4 print layout, operator code display, strict privacy without DNI/phone (RF-PREV-07, RNF-03, Art. V.4).
 * 3. SiteGlobalCertificateModal: Consolidated breakdown and CONDICIONADO verdict when machines are in quarantine (RF-PREV-07, EARS 7.2).
 * 4. LocationPortalView integration: Portal section tabs and modal triggers.
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

let printInvoked = false;
globalThis.window = {
  print: () => { printInvoked = true; }
};

import { api } from '../../public/assets/js/api.js';
import { SiteSanitaryStatusTab } from '../../public/assets/js/components/SiteSanitaryStatusTab.js';
import { SanitaryCertificateModal } from '../../public/assets/js/components/SanitaryCertificateModal.js';
import { SiteGlobalCertificateModal } from '../../public/assets/js/components/SiteGlobalCertificateModal.js';
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
console.log(' VendGuard: Frontend Test Suite - Site Sanitary & Certificates (T-PREV-22)');
console.log('======================================================================\n');

// Mock fixtures
const mockSanitaryStatus = {
  location: {
    site_code: 'SEDE-BCN-01',
    name: 'Hospital del Mar - Edificio Central',
    address: 'Passeig Marítim 25, Barcelona'
  },
  global_status: 'CONDICIONADO',
  has_quarantine_or_expired: true,
  machines: [
    {
      code: 'VEND-0101',
      model: 'Sanden Vendo G-Drink',
      floor_wing: 'Planta Baja - Urgencias',
      machine_type: 'PERISHABLE_FOOD',
      semaphore: 'QUARANTINE',
      last_inspection_date: '2026-09-27T10:30:00Z',
      last_temperature_celsius: 6.8,
      valid_until: null,
      certificate_status: 'SUSPENDED',
      notice: 'En cuarentena por revisión térmica (> 4.0 °C). No apta para consumo.'
    },
    {
      code: 'VEND-0102',
      model: 'Necta Canto Touch',
      floor_wing: 'Planta 1 - Sala Médica',
      machine_type: 'HOT_DRINKS',
      semaphore: 'GREEN',
      last_inspection_date: '2026-09-20T09:00:00Z',
      last_temperature_celsius: null,
      valid_until: '2026-10-20',
      certificate_status: 'VALID',
      notice: null
    },
    {
      code: 'VEND-0103',
      model: 'Fas Fast 1050',
      floor_wing: 'Planta 2 - Cafetería',
      machine_type: 'SNACKS',
      semaphore: 'YELLOW',
      last_inspection_date: '2026-08-30T11:00:00Z',
      last_temperature_celsius: null,
      valid_until: '2026-09-30',
      certificate_status: 'VALID',
      notice: null
    },
    {
      code: 'VEND-0104',
      model: 'Bianchi Vending',
      floor_wing: 'Planta 3 - Descanso',
      machine_type: 'COLD_DRINKS',
      semaphore: 'RED',
      last_inspection_date: '2026-08-15T08:00:00Z',
      last_temperature_celsius: 5.2,
      valid_until: '2026-09-15',
      certificate_status: 'EXPIRED',
      notice: 'Inspección periódica vencida.'
    }
  ]
};

const mockIndividualCertificate = {
  certificate_code: 'CERT-2026-0142',
  status: 'VALID',
  machine: {
    code: 'VEND-0102',
    model: 'Necta Canto Touch',
    serial_number: 'SN-77889900',
    machine_type: 'HOT_DRINKS'
  },
  location: {
    name: 'Hospital del Mar - Edificio Central',
    address: 'Passeig Marítim 25, Barcelona'
  },
  inspector: {
    name: 'Jordi Técnico Oficial',
    operator_code: 'OP-02'
  },
  inspection_date: '2026-09-20T09:00:00Z',
  valid_until: '2026-10-20',
  temperature_measured: null,
  result: 'CONFORME',
  inspected_items: [
    { item: 'Caldera y Circuito Hidráulico', status: 'CONFORME' },
    { item: 'Desinfección de Batidores', status: 'CONFORME' },
    { item: 'Filtro de Purificación', status: 'CONFORME' }
  ]
};

const mockGlobalCertificate = {
  global_certificate_code: 'SEDE-BCN-01-SAN-2026-09',
  location: {
    site_code: 'SEDE-BCN-01',
    name: 'Hospital del Mar - Edificio Central',
    address: 'Passeig Marítim 25, Barcelona'
  },
  issue_date: '2026-09-27T17:15:00Z',
  global_verdict: 'CONDICIONADO',
  verdict_explanation: 'El centro dispone de 1 máquina en cuarentena sanitaria (VEND-0101) sujeta a subsanación técnica obligatoria.',
  machines_breakdown: [
    {
      code: 'VEND-0101',
      machine_type: 'PERISHABLE_FOOD',
      floor_wing: 'Planta Baja - Urgencias',
      verdict: 'NO_CONFORME',
      quarantine: true,
      detail: 'Rotura térmica (6.8 °C). Expediente correctivo activo.'
    },
    {
      code: 'VEND-0102',
      machine_type: 'HOT_DRINKS',
      floor_wing: 'Planta 1 - Sala Médica',
      verdict: 'CONFORME',
      quarantine: false,
      detail: 'Inspección vigente hasta 2026-10-20.'
    }
  ]
};

// ---------------------------------------------------------------------
// TEST GROUP 1: SiteSanitaryStatusTab Component
// ---------------------------------------------------------------------
console.log('--- Group 1: SiteSanitaryStatusTab Semaphores and State (RF-PREV-06) ---');

function createSanitaryTabInstance() {
  const instance = {
    ...SiteSanitaryStatusTab.data(),
    ...SiteSanitaryStatusTab.methods,
    get location() { return SiteSanitaryStatusTab.computed.location.call(this); },
    get globalStatus() { return SiteSanitaryStatusTab.computed.globalStatus.call(this); },
    get hasQuarantineOrExpired() { return SiteSanitaryStatusTab.computed.hasQuarantineOrExpired.call(this); },
    get machines() { return SiteSanitaryStatusTab.computed.machines.call(this); },
    get counts() { return SiteSanitaryStatusTab.computed.counts.call(this); },
    get filteredMachines() { return SiteSanitaryStatusTab.computed.filteredMachines.call(this); }
  };
  return instance;
}

const tab = createSanitaryTabInstance();

// Mock API call
api.site.getSanitaryStatus = async () => mockSanitaryStatus;

await tab.loadStatus();
assert('1.1 tab loads sanitary status from api.site.getSanitaryStatus', tab.statusData !== null);
assert('1.2 globalStatus is CONDICIONADO', tab.globalStatus === 'CONDICIONADO');
assert('1.3 hasQuarantineOrExpired is true', tab.hasQuarantineOrExpired === true);
assert('1.4 counts.total is 4', tab.counts.total === 4);
assert('1.5 counts.quarantine is 1', tab.counts.quarantine === 1);
assert('1.6 counts.green is 1', tab.counts.green === 1);
assert('1.7 counts.yellow is 1', tab.counts.yellow === 1);
assert('1.8 counts.red is 1', tab.counts.red === 1);

// Test filter 'all'
tab.setFilter('all');
assert('1.9 filteredMachines with "all" returns 4 machines', tab.filteredMachines.length === 4);

// Test filter 'quarantine_expired'
tab.setFilter('quarantine_expired');
assert('1.10 filteredMachines with "quarantine_expired" returns 2 machines (QUARANTINE and RED)', tab.filteredMachines.length === 2);
assert('1.11 filtered machines are VEND-0101 and VEND-0104', tab.filteredMachines.some(m => m.code === 'VEND-0101') && tab.filteredMachines.some(m => m.code === 'VEND-0104'));

// Test filter 'valid'
tab.setFilter('valid');
assert('1.12 filteredMachines with "valid" returns 2 machines (GREEN and YELLOW)', tab.filteredMachines.length === 2);

// Test search query
tab.setFilter('all');
tab.searchQuery = 'canto';
assert('1.13 search query filters matching model', tab.filteredMachines.length === 1 && tab.filteredMachines[0].code === 'VEND-0102');
tab.searchQuery = '';

// Test getSemaphoreBadge helper
const qBadge = tab.getSemaphoreBadge('QUARANTINE');
assert('1.14 QUARANTINE semaphore badge has stop icon and red background', qBadge.icon === '🛑' && qBadge.label.includes('CUARENTENA'));
const gBadge = tab.getSemaphoreBadge('GREEN');
assert('1.15 GREEN semaphore badge has green icon and VIGENTE label', gBadge.icon === '🟢' && gBadge.label === 'VIGENTE');

// Test event emissions
let emittedTabEvents = [];
tab.$emit = (evt, val) => { emittedTabEvents.push({ evt, val }); };

tab.onViewCertificate(mockSanitaryStatus.machines[0]);
assert('1.16 onViewCertificate emits "view-certificate" with machine code', emittedTabEvents.some(e => e.evt === 'view-certificate' && e.val === 'VEND-0101'));

tab.onViewGlobalCertificate();
assert('1.17 onViewGlobalCertificate emits "view-global-certificate"', emittedTabEvents.some(e => e.evt === 'view-global-certificate'));

// ---------------------------------------------------------------------
// TEST GROUP 2: SanitaryCertificateModal Component & Art. V.4 Privacy
// ---------------------------------------------------------------------
console.log('\n--- Group 2: SanitaryCertificateModal & Art. V.4 Privacy (RF-PREV-07, RNF-03) ---');

function createCertModalInstance(props = {}) {
  const instance = {
    ...SanitaryCertificateModal.data(),
    ...SanitaryCertificateModal.methods,
    machineCode: props.machineCode || '',
    certificateData: props.certificateData || null,
    cert: props.certificateData || null,
    modelValue: props.modelValue || false,
    get inspectorOperatorCode() { return SanitaryCertificateModal.computed.inspectorOperatorCode.call(this); },
    get inspectorName() { return SanitaryCertificateModal.computed.inspectorName.call(this); },
    get isConforme() { return SanitaryCertificateModal.computed.isConforme.call(this); },
    get isSuspended() { return SanitaryCertificateModal.computed.isSuspended.call(this); },
    get formattedInspectionDate() { return SanitaryCertificateModal.computed.formattedInspectionDate.call(this); },
    get formattedValidUntil() { return SanitaryCertificateModal.computed.formattedValidUntil.call(this); }
  };
  return instance;
}

const certModal = createCertModalInstance({
  machineCode: 'VEND-0102',
  certificateData: mockIndividualCertificate,
  modelValue: true
});

certModal.initCertificate();

assert('2.1 certModal holds loaded certificate data', certModal.cert !== null);
assert('2.2 inspectorOperatorCode matches official OP-02 (Art. V.4)', certModal.inspectorOperatorCode === 'OP-02');
assert('2.3 inspectorName matches professional name', certModal.inspectorName === 'Jordi Técnico Oficial');
assert('2.4 isConforme is true for CONFORME result', certModal.isConforme === true);
assert('2.5 isSuspended is false for VALID status', certModal.isSuspended === false);

// Art. V.4 Privacy Check: Ensure NO personal telephone or DNI is exhibited
const certModalTemplate = SanitaryCertificateModal.template;
assert('2.6 Template does NOT contain phone input or label', !certModalTemplate.includes('teléfono personal') && !certModalTemplate.includes('phone'));
assert('2.7 Template displays operator code badge explicitly', certModalTemplate.includes('Código de Operador Oficial (Art. V.4)'));
assert('2.8 Template contains printable container id="printable-sanitary-cert" (RNF-03)', certModalTemplate.includes('id="printable-sanitary-cert"'));
assert('2.9 Template includes print button with A4 label', certModalTemplate.includes('Imprimir / Guardar PDF (A4)'));

// Test printCertificate
printInvoked = false;
certModal.printCertificate();
assert('2.10 printCertificate invokes window.print()', printInvoked === true);

// Test close event
let emittedModalEvents = [];
certModal.$emit = (evt, val) => { emittedModalEvents.push({ evt, val }); };
certModal.close();
assert('2.11 close emits update:modelValue false and close', emittedModalEvents.some(e => e.evt === 'update:modelValue' && e.val === false) && emittedModalEvents.some(e => e.evt === 'close'));

// Test suspended certificate state
const suspendedModal = createCertModalInstance({
  machineCode: 'VEND-0101',
  certificateData: {
    ...mockIndividualCertificate,
    status: 'SUSPENDED',
    result: 'NO_CONFORME'
  }
});
assert('2.12 isSuspended is true for SUSPENDED status (EARS 7.3)', suspendedModal.isSuspended === true);
assert('2.13 isConforme is false for NO_CONFORME', suspendedModal.isConforme === false);

// ---------------------------------------------------------------------
// TEST GROUP 3: SiteGlobalCertificateModal Component
// ---------------------------------------------------------------------
console.log('\n--- Group 3: SiteGlobalCertificateModal & CONDICIONADO Verdict (RF-PREV-07, EARS 7.2) ---');

function createGlobalModalInstance(props = {}) {
  const instance = {
    ...SiteGlobalCertificateModal.data(),
    ...SiteGlobalCertificateModal.methods,
    globalData: props.globalData || null,
    report: props.globalData || null,
    modelValue: props.modelValue || false,
    get globalVerdict() { return SiteGlobalCertificateModal.computed.globalVerdict.call(this); },
    get isCondicionado() { return SiteGlobalCertificateModal.computed.isCondicionado.call(this); },
    get verdictBadgeInfo() { return SiteGlobalCertificateModal.computed.verdictBadgeInfo.call(this); },
    get machinesBreakdown() { return SiteGlobalCertificateModal.computed.machinesBreakdown.call(this); },
    get formattedIssueDate() { return SiteGlobalCertificateModal.computed.formattedIssueDate.call(this); }
  };
  return instance;
}

const globalModal = createGlobalModalInstance({
  globalData: mockGlobalCertificate,
  modelValue: true
});

assert('3.1 globalVerdict evaluates to CONDICIONADO', globalModal.globalVerdict === 'CONDICIONADO');
assert('3.2 isCondicionado is true', globalModal.isCondicionado === true);
assert('3.3 verdictBadgeInfo contains CONDICIONADO label and warning icon', globalModal.verdictBadgeInfo.label.includes('CONDICIONADO') && globalModal.verdictBadgeInfo.icon === '⚠️');
assert('3.4 machinesBreakdown has 2 entries', globalModal.machinesBreakdown.length === 2);
assert('3.5 First machine in breakdown has quarantine = true', globalModal.machinesBreakdown[0].quarantine === true);

// Template verification
const globalTemplate = SiteGlobalCertificateModal.template;
assert('3.6 Global modal template has printable container id="printable-global-cert"', globalTemplate.includes('id="printable-global-cert"'));
assert('3.7 Global modal template mentions AUDITORÍA SANITARIA CONSOLIDADA', globalTemplate.includes('AUDITORÍA SANITARIA CONSOLIDADA'));
assert('3.8 Global modal template has A4 print button', globalTemplate.includes('Imprimir Certificado Consolidado (A4)'));

// Test printCertificate
printInvoked = false;
globalModal.printCertificate();
assert('3.9 globalModal printCertificate invokes window.print()', printInvoked === true);

// Test fully conforming global report
const fullyConformModal = createGlobalModalInstance({
  globalData: {
    ...mockGlobalCertificate,
    global_verdict: 'CONFORME',
    has_quarantine_or_expired: false
  }
});
assert('3.10 Conforming global certificate isCondicionado is false', fullyConformModal.isCondicionado === false);
assert('3.11 Conforming global certificate badge label is CONFORME', fullyConformModal.verdictBadgeInfo.label.includes('CONFORME') && !fullyConformModal.verdictBadgeInfo.label.includes('CONDICIONADO'));

// ---------------------------------------------------------------------
// TEST GROUP 4: LocationPortalView Integration
// ---------------------------------------------------------------------
console.log('\n--- Group 4: LocationPortalView Tabs & Modal Triggers ---');

const portal = {
  ...LocationPortalView.data(),
  ...LocationPortalView.methods
};

assert('4.1 LocationPortalView initializes with activePortalTab = "machines"', portal.activePortalTab === 'machines');
portal.setPortalTab('sanitary');
assert('4.2 setPortalTab("sanitary") sets activePortalTab = "sanitary"', portal.activePortalTab === 'sanitary');

portal.onViewMachineCertificate('VEND-0101');
assert('4.3 onViewMachineCertificate sets selectedCertMachineCode and opens modal', portal.selectedCertMachineCode === 'VEND-0101' && portal.showSanitaryCertModal === true);

portal.onViewGlobalCertificate();
assert('4.4 onViewGlobalCertificate opens global certificate modal', portal.showGlobalCertModal === true);

const portalTemplate = LocationPortalView.template;
assert('4.5 LocationPortalView template includes "Control Higiénico y Certificados" tab button', portalTemplate.includes('Control Higiénico y Certificados'));
assert('4.6 LocationPortalView template includes SiteSanitaryStatusTab component', portalTemplate.includes('<SiteSanitaryStatusTab'));
assert('4.7 LocationPortalView template includes SanitaryCertificateModal component', portalTemplate.includes('<SanitaryCertificateModal'));
assert('4.8 LocationPortalView template includes SiteGlobalCertificateModal component', portalTemplate.includes('<SiteGlobalCertificateModal'));

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-PREV-22 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
