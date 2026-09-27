/**
 * VendGuard - QR Preventive Scan Test Suite (T-PREV-23)
 * 
 * Verifies:
 * 1. QrSanitaryQuarantineModal: Prominent red alert banner, constitutional Art. II message,
 *    purchase and reporting block, and active incident badge (RF-PREV-01, RF-PREV-04, Art. II).
 * 2. QrSeasonalPauseNotice: Vacation notice, perishable absence disclaimer, reporting disabled (RF-PREV-01, EARS 1.5).
 * 3. QrReportView Integration: Correct status_mode resolution and component rendering for
 *    SANITARY_QUARANTINE and SEASONAL_PAUSE.
 * 
 * Dogma Vanilla: Pure JavaScript ESM (Node.js).
 * Dualismo Lingüístico: Test in English; UI assertions in Spanish.
 */

// Mock browser globals for Node ESM
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
    search: '?qr=VEND-0101',
    href: 'http://localhost/?qr=VEND-0101'
  }
};

import { api } from '../../public/assets/js/api.js';
import { QrSanitaryQuarantineModal } from '../../public/assets/js/components/QrSanitaryQuarantineModal.js';
import { QrSeasonalPauseNotice } from '../../public/assets/js/components/QrSeasonalPauseNotice.js';
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
console.log(' VendGuard: Frontend Test Suite - QR Preventive Modes (T-PREV-23)');
console.log('======================================================================\n');

// Mock fixtures
const quarantineScanData = {
  status_mode: 'SANITARY_QUARANTINE',
  machine: {
    id: 14,
    code: 'VEND-BCN-101',
    model: 'Sanden Vendo G-Drink',
    machine_type: 'PERISHABLE_FOOD',
    floor_wing: 'Planta Baja - Urgencias',
    is_perishable: true
  },
  location: {
    name: 'Hospital del Mar - Edificio Central'
  },
  alert: {
    title: 'MÁQUINA FUERA DE SERVICIO POR CONTROL HIGIÉNICO-SANITARIO',
    message: 'Por imperativo del Artículo II de la Constitución (Seguridad Alimentaria), queda prohibida la adquisición y consumo de productos de esta unidad.',
    severity: 'CRITICAL_DANGER'
  },
  can_report: false,
  active_incident: {
    ticket_code: 'INC-2026-0091',
    status_label: 'Intervención técnica prioritaria en curso'
  }
};

const seasonalPauseScanData = {
  status_mode: 'SEASONAL_PAUSE',
  machine: {
    code: 'VEND-BCN-105',
    model: 'Sanden Vendo G-Drink',
    machine_type: 'PERISHABLE_FOOD',
    floor_wing: 'Planta 1 - Vestíbulo'
  },
  location: {
    name: 'Colegio Mayor Universitario'
  },
  alert: {
    title: 'DISPOSITIVO EN PAUSA ESTACIONAL PROGRAMADA',
    message: 'Esta máquina se encuentra vacía de productos perecederos por periodo vacacional. Reanudará el servicio tras revisión sanitaria previa.',
    severity: 'INFO'
  },
  can_report: false
};

// ---------------------------------------------------------------------
// TEST GROUP 1: QrSanitaryQuarantineModal Component (Art. II)
// ---------------------------------------------------------------------
console.log('--- Group 1: QrSanitaryQuarantineModal Component (Art. II) ---');

function createQuarantineModalInstance(props = {}) {
  const instance = {
    ...QrSanitaryQuarantineModal.methods,
    machine: props.machine || {},
    location: props.location || {},
    alert: props.alert || {},
    activeIncident: props.activeIncident || null,
    get alertTitle() { return QrSanitaryQuarantineModal.computed.alertTitle.call(this); },
    get alertMessage() { return QrSanitaryQuarantineModal.computed.alertMessage.call(this); },
    get ticketCode() { return QrSanitaryQuarantineModal.computed.ticketCode.call(this); },
    get incidentStatusLabel() { return QrSanitaryQuarantineModal.computed.incidentStatusLabel.call(this); }
  };
  return instance;
}

const quarantineComp = createQuarantineModalInstance({
  machine: quarantineScanData.machine,
  location: quarantineScanData.location,
  alert: quarantineScanData.alert,
  activeIncident: quarantineScanData.active_incident
});

assert('1.1 alertTitle matches mandatory title', quarantineComp.alertTitle.includes('CONTROL HIGIÉNICO-SANITARIO'));
assert('1.2 alertMessage contains constitutional Art. II statement', quarantineComp.alertMessage.includes('Artículo II de la Constitución (Seguridad Alimentaria)'));
assert('1.3 ticketCode formats with hash #', quarantineComp.ticketCode === '#INC-2026-0091');
assert('1.4 incidentStatusLabel matches status text', quarantineComp.incidentStatusLabel.includes('Intervención técnica prioritaria'));

// Template assertions
const qModalTemplate = QrSanitaryQuarantineModal.template;
assert('1.5 Template includes ALERTA SANITARIA · ARTÍCULO II badge', qModalTemplate.includes('ALERTA SANITARIA · ARTÍCULO II'));
assert('1.6 Template includes stop icon 🛑', qModalTemplate.includes('🛑'));
assert('1.7 Template clearly states sales and dispensing are blocked', qModalTemplate.includes('Dispensación de productos y cobros bloqueados'));
assert('1.8 Template states reporting form is deactivated', qModalTemplate.includes('No es necesario remitir un nuevo aviso'));
assert('1.9 Art. V.4: Template does NOT contain technician personal phone or DNI', !qModalTemplate.includes('teléfono personal') && !qModalTemplate.includes('DNI'));

// Test go-home event emission
let emittedQuarantineEvents = [];
quarantineComp.$emit = (evt, val) => { emittedQuarantineEvents.push({ evt, val }); };
quarantineComp.handleGoHome();
assert('1.10 handleGoHome emits "go-home"', emittedQuarantineEvents.some(e => e.evt === 'go-home'));

// Default fallback props test
const fallbackQuarantineComp = createQuarantineModalInstance({});
assert('1.11 Fallback alertTitle defaults to constitutional sanitary alert', fallbackQuarantineComp.alertTitle.includes('CONTROL HIGIÉNICO-SANITARIO'));
assert('1.12 Fallback alertMessage defaults to Article II warning', fallbackQuarantineComp.alertMessage.includes('Artículo II'));
assert('1.13 Fallback ticketCode is empty when no incident', fallbackQuarantineComp.ticketCode === '');

// ---------------------------------------------------------------------
// TEST GROUP 2: QrSeasonalPauseNotice Component
// ---------------------------------------------------------------------
console.log('\n--- Group 2: QrSeasonalPauseNotice Component (RF-PREV-01, EARS 1.5) ---');

function createPauseNoticeInstance(props = {}) {
  const instance = {
    ...QrSeasonalPauseNotice.methods,
    machine: props.machine || {},
    location: props.location || {},
    alert: props.alert || {},
    get alertTitle() { return QrSeasonalPauseNotice.computed.alertTitle.call(this); },
    get alertMessage() { return QrSeasonalPauseNotice.computed.alertMessage.call(this); }
  };
  return instance;
}

const pauseComp = createPauseNoticeInstance({
  machine: seasonalPauseScanData.machine,
  location: seasonalPauseScanData.location,
  alert: seasonalPauseScanData.alert
});

assert('2.1 alertTitle matches pause title', pauseComp.alertTitle.includes('PAUSA ESTACIONAL PROGRAMADA'));
assert('2.2 alertMessage explains perishable absence', pauseComp.alertMessage.includes('vacía de productos perecederos por periodo vacacional'));

// Template assertions
const pauseTemplate = QrSeasonalPauseNotice.template;
assert('2.3 Template includes vacation badge PAUSA ESTACIONAL VACACIONAL', pauseTemplate.includes('PAUSA ESTACIONAL VACACIONAL'));
assert('2.4 Template includes vacation icon 🏖️', pauseTemplate.includes('🏖️'));
assert('2.5 Template explains that report registration is not needed', pauseTemplate.includes('No es necesario registrar avisos de avería'));

// Test go-home event
let emittedPauseEvents = [];
pauseComp.$emit = (evt, val) => { emittedPauseEvents.push({ evt, val }); };
pauseComp.handleGoHome();
assert('2.6 handleGoHome emits "go-home"', emittedPauseEvents.some(e => e.evt === 'go-home'));

// Fallback test
const fallbackPauseComp = createPauseNoticeInstance({});
assert('2.7 Fallback pause title defaults correctly', fallbackPauseComp.alertTitle.includes('PAUSA ESTACIONAL'));
assert('2.8 Fallback pause message defaults correctly', fallbackPauseComp.alertMessage.includes('vacía de productos perecederos'));

// ---------------------------------------------------------------------
// TEST GROUP 3: QrReportView Integration
// ---------------------------------------------------------------------
console.log('\n--- Group 3: QrReportView Resolution & Template Rendering ---');

// Test Case A: Scan returns SANITARY_QUARANTINE
api.qr.scan = async () => quarantineScanData;

const qrViewQuarantine = {
  ...QrReportView.data(),
  ...QrReportView.methods,
  code: 'VEND-BCN-101',
  effectiveMachineCode: 'VEND-BCN-101',
  effectiveSiteCode: ''
};

await qrViewQuarantine.resolveMachine();

assert('3.1 QrReportView resolves statusMode to SANITARY_QUARANTINE', qrViewQuarantine.statusMode === 'SANITARY_QUARANTINE');
assert('3.2 QrReportView stores alertData', qrViewQuarantine.alertData !== null && qrViewQuarantine.alertData.severity === 'CRITICAL_DANGER');
assert('3.3 QrReportView stores activeIncident with ticket code', qrViewQuarantine.activeIncident?.ticket_code === 'INC-2026-0091');
assert('3.4 QrReportView loading is false after resolution', qrViewQuarantine.loading === false);

// Test Case B: Scan returns SEASONAL_PAUSE
api.qr.scan = async () => seasonalPauseScanData;

const qrViewPause = {
  ...QrReportView.data(),
  ...QrReportView.methods,
  code: 'VEND-BCN-105',
  effectiveMachineCode: 'VEND-BCN-105',
  effectiveSiteCode: ''
};

await qrViewPause.resolveMachine();

assert('3.5 QrReportView resolves statusMode to SEASONAL_PAUSE', qrViewPause.statusMode === 'SEASONAL_PAUSE');
assert('3.6 QrReportView stores alertData for pause', qrViewPause.alertData !== null && qrViewPause.alertData.severity === 'INFO');
assert('3.7 QrReportView loading is false after pause resolution', qrViewPause.loading === false);

// Template checks in QrReportView
const qrViewTemplate = QrReportView.template;
assert('3.8 QrReportView registers QrSanitaryQuarantineModal component', QrReportView.components.QrSanitaryQuarantineModal !== undefined);
assert('3.9 QrReportView registers QrSeasonalPauseNotice component', QrReportView.components.QrSeasonalPauseNotice !== undefined);
assert('3.10 QrReportView template conditionally renders QrSanitaryQuarantineModal on SANITARY_QUARANTINE', qrViewTemplate.includes('<QrSanitaryQuarantineModal') && qrViewTemplate.includes("statusMode === 'SANITARY_QUARANTINE'"));
assert('3.11 QrReportView template conditionally renders QrSeasonalPauseNotice on SEASONAL_PAUSE', qrViewTemplate.includes('<QrSeasonalPauseNotice') && qrViewTemplate.includes("statusMode === 'SEASONAL_PAUSE'"));

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-PREV-23 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
