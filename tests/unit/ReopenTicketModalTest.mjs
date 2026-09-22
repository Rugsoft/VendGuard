/**
 * VendGuard - ReopenTicketModal Test Suite (T-36)
 * 
 * Verifies:
 * 1. Evaluates 48-hour warranty window on RESOLVED incidents (RF-09, EARS 9.1).
 * 2. Displays "Reabrir incidencia" and form when < 48h since resolution.
 * 3. Dispatches POST /api/incidents/{ticket_code}/reopen with mandatory reason.
 * 4. Displays expiration notice and prompts for new ticket creation when > 48h (EARS 9.2).
 * 5. Handles chronic incident limit (3rd consecutive reopening block) (EARS 9.3).
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

import { api, ApiError } from '../../public/assets/js/api.js';
import { store, clearSession } from '../../public/assets/js/store.js';
import { ReopenTicketModal } from '../../public/assets/js/components/ReopenTicketModal.js';
import { MachineCard } from '../../public/assets/js/components/MachineCard.js';

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
console.log(' VendGuard: Frontend Test Suite - ReopenTicketModal (T-36)');
console.log('======================================================================\n');

// Mock dates
const now = Date.now();
const date10HoursAgo = new Date(now - (10 * 60 * 60 * 1000)).toISOString();
const date50HoursAgo = new Date(now - (50 * 60 * 60 * 1000)).toISOString();

const machineUnderWarranty = {
  id: 1,
  code: 'VEND-0101',
  model: 'Sanden G-Drink',
  floor_wing: 'Planta Baja',
  active_incident: {
    id: 10,
    ticket_code: 'INC-2026-0010',
    status: 'RESUELTA',
    urgency: 'HIGH',
    resolved_at: date10HoursAgo
  }
};

const machineExpiredWarranty = {
  id: 2,
  code: 'VEND-0102',
  model: 'Necta Canto',
  floor_wing: 'Planta 1',
  active_incident: {
    id: 11,
    ticket_code: 'INC-2026-0011',
    status: 'RESUELTA',
    urgency: 'MEDIUM',
    resolved_at: date50HoursAgo
  }
};

// ---------------------------------------------------------------------
// TEST GROUP 1: MachineCard Warranty Button Rendering
// ---------------------------------------------------------------------
console.log('--- Group 1: MachineCard Warranty Action Visibility ---');

// Helper to evaluate MachineCard computed properties
function evalCard(machine) {
  const instance = {
    machine,
    get activeIncident() { return MachineCard.computed.activeIncident.call(this); },
    get isUnderWarranty() { return MachineCard.computed.isUnderWarranty.call(this); }
  };
  return {
    isUnderWarranty: instance.isUnderWarranty
  };
}

assert('1.1 MachineCard detects resolved status as under warranty', evalCard(machineUnderWarranty).isUnderWarranty === true);
assert('1.2 MachineCard template renders "Reabrir incidencia" button', MachineCard.template.includes('Reabrir incidencia'));

// ---------------------------------------------------------------------
// TEST GROUP 2: ReopenTicketModal 48h Window Calculation
// ---------------------------------------------------------------------
console.log('\n--- Group 2: Warranty Window Evaluation (< 48h vs > 48h) ---');

function createReopenModal(machine, initialData = {}) {
  let emits = [];
  const instance = {
    modelValue: true,
    machine,
    reason: '',
    isSubmitting: false,
    errorMessage: '',
    isChronicBlocked: false,
    closeModal() {
      this.modelValue = false;
      emits.push({ evt: 'close' });
    },
    ...initialData,
    $emit: (evt, val) => { emits.push({ evt, val }); },
    getEmits: () => emits
  };

  Object.defineProperty(instance, 'activeIncident', {
    get: () => ReopenTicketModal.computed.activeIncident.call(instance)
  });
  Object.defineProperty(instance, 'ticketCode', {
    get: () => ReopenTicketModal.computed.ticketCode.call(instance)
  });
  Object.defineProperty(instance, 'hoursSinceResolution', {
    get: () => ReopenTicketModal.computed.hoursSinceResolution.call(instance)
  });
  Object.defineProperty(instance, 'isWarrantyActive', {
    get: () => ReopenTicketModal.computed.isWarrantyActive.call(instance)
  });
  Object.defineProperty(instance, 'remainingWarrantyHours', {
    get: () => ReopenTicketModal.computed.remainingWarrantyHours.call(instance)
  });
  Object.defineProperty(instance, 'modalTitle', {
    get: () => ReopenTicketModal.computed.modalTitle.call(instance)
  });

  return instance;
}

// 2.1: Machine with 10h resolution (< 48h)
const activeWarrantyModal = createReopenModal(machineUnderWarranty);
assert('2.1 hoursSinceResolution is ~10 hours', Math.round(activeWarrantyModal.hoursSinceResolution) === 10);
assert('2.2 isWarrantyActive is true for < 48 hours', activeWarrantyModal.isWarrantyActive === true);
assert('2.3 remainingWarrantyHours is ~38 hours', activeWarrantyModal.remainingWarrantyHours >= 37 && activeWarrantyModal.remainingWarrantyHours <= 38);
assert('2.4 Modal title contains ticket code INC-2026-0010', activeWarrantyModal.modalTitle.includes('INC-2026-0010'));

// 2.5: Machine with 50h resolution (> 48h)
const expiredWarrantyModal = createReopenModal(machineExpiredWarranty);
assert('2.5 hoursSinceResolution is ~50 hours', Math.round(expiredWarrantyModal.hoursSinceResolution) === 50);
assert('2.6 isWarrantyActive is false for > 48 hours', expiredWarrantyModal.isWarrantyActive === false);

// 2.7: Expired scenario offers "create-new-ticket" button
ReopenTicketModal.methods.handleCreateNewTicket.call(expiredWarrantyModal);
assert('2.7 handleCreateNewTicket emits "create-new-ticket" event', expiredWarrantyModal.getEmits().some(e => e.evt === 'create-new-ticket' && e.val.id === 2));

// ---------------------------------------------------------------------
// TEST GROUP 3: Successful Reopening Submission (RF-09)
// ---------------------------------------------------------------------
console.log('\n--- Group 3: Submitting Reopen Request with Mandatory Reason ---');

let lastReopenTicket = null;
let lastReopenReason = null;

api.incidents.reopen = async (ticketCode, reason) => {
  lastReopenTicket = ticketCode;
  lastReopenReason = reason;
  return {
    ticket_code: ticketCode,
    status: 'REABIERTA',
    assigned_technician_id: null,
    reopen_reason: reason
  };
};

const modalToSubmit = createReopenModal(machineUnderWarranty, {
  reason: 'La máquina de sándwiches vuelve a marcar +12ºC tras la visita técnica.'
});

await ReopenTicketModal.methods.handleReopenSubmit.call(modalToSubmit);

assert('3.1 api.incidents.reopen was called with ticket INC-2026-0010', lastReopenTicket === 'INC-2026-0010');
assert('3.2 api.incidents.reopen received mandatory reason', lastReopenReason.includes('vuelve a marcar +12ºC'));
assert('3.3 handleReopenSubmit emits "reopened" event', modalToSubmit.getEmits().some(e => e.evt === 'reopened' && e.val.status === 'REABIERTA'));
assert('3.4 Flash alert added to store', store.state.alerts.some(a => a.message.includes('reabierta con éxito')));

// ---------------------------------------------------------------------
// TEST GROUP 4: Chronic Incident Limit Handling (EARS 9.3)
// ---------------------------------------------------------------------
console.log('\n--- Group 4: Chronic Incident Limit Protection (EARS 9.3) ---');

api.incidents.reopen = async () => {
  const err = new ApiError(
    422,
    'CHRONIC_INCIDENT_LIMIT',
    'Se ha superado el máximo de 2 reaperturas sucesivas. Expediente marcado como Avería Crónica.'
  );
  throw err;
};

const modalChronic = createReopenModal(machineUnderWarranty, {
  reason: 'Tercer intento consecutivo de reapertura'
});

await ReopenTicketModal.methods.handleReopenSubmit.call(modalChronic);

assert('4.1 Chronic incident error switches isChronicBlocked to true', modalChronic.isChronicBlocked === true);
assert('4.2 Error message informs user about chronic limit', modalChronic.errorMessage.includes('Avería Crónica'));
assert('4.3 Template contains chronic warning alert text', ReopenTicketModal.template.includes('Expediente Bloqueado: Avería Crónica'));

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-36 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
