/**
 * VendGuard - LocationRefundsTab Test Suite (T-REF-17)
 *
 * Covers the anonymized reception list, the "Entregar efectivo" action, the
 * numeric PIN keypad and the handover outcome. Native Node.js ESM; no DOM or
 * third-party packages.
 *
 * Constitutional coverage:
 * - Art. V.4: the tab never projects bank accounts or private phone numbers.
 * - RF-REF-06: a wrong PIN is rejected and the envelope stays at reception.
 * - RF-REF-10: only anonymized claimant names are shown.
 */

import { LocationRefundsTab } from '../../public/assets/js/components/LocationRefundsTab.js';
import { api } from '../../public/assets/js/api.js';

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) console.error(`         Reason: ${details}`);
    failures++;
  }
}

function createTab() {
  const instance = { ...LocationRefundsTab.data() };
  Object.assign(instance, LocationRefundsTab.methods);
  for (const [key, getter] of Object.entries(LocationRefundsTab.computed)) {
    Object.defineProperty(instance, key, { get: () => getter.call(instance) });
  }
  return instance;
}

// ---------------------------------------------------------------------
// Fixtures: only the fields the backend projection exposes (Art. V.4)
// ---------------------------------------------------------------------
const receptionCase = {
  id: 21,
  incident_code: 'INC-2026-00101',
  machine_code: 'VEND-0101',
  claimant_name_anon: 'Laura S.',
  claimed_amount: 2.5,
  compensation_method: 'EN_MANO_SEDE',
  status: 'DEPOSITED_AT_RECEPTION',
  status_label: 'Efectivo en conserjería, listo para entrega',
  ready_for_pickup: true,
  created_at: '2026-10-01T10:15:00+02:00'
};

const pendingPaymentCase = {
  id: 22,
  incident_code: 'INC-2026-00102',
  machine_code: 'VEND-0102',
  claimant_name_anon: 'Marc R.',
  claimed_amount: 7.2,
  compensation_method: 'TRANSFERENCIA_BANCARIA',
  status: 'VERIFIED_PENDING_PAYMENT',
  status_label: 'Verificado, pendiente de pago',
  ready_for_pickup: false,
  created_at: '2026-10-01T11:00:00+02:00'
};

const rejectedCase = {
  id: 23,
  incident_code: 'INC-2026-00103',
  machine_code: 'VEND-0103',
  claimant_name_anon: 'Núria P.',
  claimed_amount: 1.5,
  compensation_method: 'EN_MANO_SEDE',
  status: 'REJECTED',
  status_label: 'Desestimado',
  ready_for_pickup: false,
  created_at: '2026-09-30T09:00:00+02:00'
};

let listCallCount = 0;
let deliveryCalls = [];
let deliveryBehavior = 'accept';

api.site.getRefunds = async () => {
  listCallCount++;
  return {
    total: 3,
    ready_for_pickup_total: 1,
    refunds: [receptionCase, pendingPaymentCase, rejectedCase]
  };
};

api.site.deliverRefund = async (refundId, pickupPin) => {
  deliveryCalls.push({ refundId, pickupPin });
  if (deliveryBehavior === 'reject') {
    const error = new Error('El PIN de recogida introducido no coincide con el expediente de reintegro.');
    error.code = 'INVALID_PICKUP_PIN';
    error.status = 422;
    throw error;
  }
  return {
    id: refundId,
    status: 'REFUNDED_IN_HAND',
    claimed_amount: 2.5,
    claimant_name_anon: 'Laura S.'
  };
};

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - LocationRefundsTab (T-REF-17)');
console.log('======================================================================\n');

// ---------------------------------------------------------------------
// TEST GROUP 1: Anonymized reception list (RF-REF-06, RF-REF-10, Art. V.4)
// ---------------------------------------------------------------------
console.log('--- Group 1: Anonymized reception list ---');

const tab = createTab();
await tab.loadRefunds();

assert('1.1 loadRefunds reaches the site refunds endpoint', listCallCount === 1);
assert('1.2 The list stores the three site cases', tab.hasRefunds && tab.refunds.length === 3);
assert('1.3 readyForPickupCount counts only envelopes physically at reception', tab.readyForPickupCount === 1);
assert('1.4 The envelope row keeps the anonymized claimant name', tab.refunds[0].claimant_name_anon === 'Laura S.');
assert('1.5 Status labels are rendered in Spanish', tab.statusMeta('DEPOSITED_AT_RECEPTION').label.includes('conserjería'));
assert('1.6 Rejected cases are visually flagged as an error state',
  tab.statusMeta('REJECTED').color === '#b91c1c' && tab.statusMeta('REJECTED').bg === '#fee2e2');
assert('1.7 Amounts are formatted with two decimals', tab.formatAmount(2.5) === '2.50 €');
assert('1.8 A load failure surfaces a retryable error state', await (async () => {
  const failingTab = createTab();
  api.site.getRefunds = async () => { throw new Error('Sede no disponible.'); };
  await failingTab.loadRefunds();
  api.site.getRefunds = async () => ({
    total: 3,
    ready_for_pickup_total: 1,
    refunds: [receptionCase, pendingPaymentCase, rejectedCase]
  });
  return failingTab.error === 'Sede no disponible.' && failingTab.refunds.length === 0;
})());

// ---------------------------------------------------------------------
// TEST GROUP 2: PIN modal opening (RF-REF-06)
// ---------------------------------------------------------------------
console.log('\n--- Group 2: PIN modal opening ---');

tab.openPinModal(pendingPaymentCase);
assert('2.1 A case without envelope at reception cannot open the PIN modal', tab.selectedRefund === null);

tab.openPinModal(receptionCase);
assert('2.2 Only the envelope at reception opens the PIN modal', tab.selectedRefund?.id === 21);
assert('2.3 The modal starts with four empty PIN slots', tab.pinSlots.length === 4 && tab.pinSlots.every((filled) => filled === false));
assert('2.4 The modal opens without PIN errors', tab.pinError === '');

// ---------------------------------------------------------------------
// TEST GROUP 3: Numeric keypad (RF-REF-06)
// ---------------------------------------------------------------------
console.log('\n--- Group 3: Numeric keypad ---');

tab.appendPinDigit('4');
assert('3.1 Tapping a keypad digit fills the first PIN slot', tab.pin === '4' && tab.pinSlots[0] === true);
assert('3.2 A partial PIN cannot be confirmed', !tab.isPinComplete);

tab.appendPinDigit('8');
tab.appendPinDigit('2');
tab.appendPinDigit('1');
assert('3.3 Four typed digits complete the PIN', tab.pin === '4821' && tab.isPinComplete);

tab.appendPinDigit('9');
assert('3.4 A fifth digit is ignored', tab.pin === '4821');

tab.appendPinDigit('x');
assert('3.5 Non-numeric keys are ignored', tab.pin === '4821');

tab.removePinDigit();
assert('3.6 Backspace deletes the last digit and reopens the flow', tab.pin === '482' && !tab.isPinComplete);

tab.appendPinDigit('1');
tab.closePinModal();
assert('3.7 Closing the modal clears the selected case and the typed PIN', tab.selectedRefund === null && tab.pin === '');

// ---------------------------------------------------------------------
// TEST GROUP 4: Handover with valid PIN (RF-REF-06)
// ---------------------------------------------------------------------
console.log('\n--- Group 4: Handover with valid PIN ---');

deliveryCalls = [];
tab.openPinModal(receptionCase);
tab.appendPinDigit('4');
tab.appendPinDigit('8');
tab.appendPinDigit('2');
tab.appendPinDigit('1');
await tab.submitPin();

assert('4.1 The handover posts the case id and the typed PIN',
  deliveryCalls.length === 1 && deliveryCalls[0].refundId === 21 && deliveryCalls[0].pickupPin === '4821');
assert('4.2 The delivered case becomes REFUNDED_IN_HAND',
  tab.refunds.find((refund) => refund.id === 21)?.status === 'REFUNDED_IN_HAND');
assert('4.3 The delivered case no longer accepts another handover',
  tab.refunds.find((refund) => refund.id === 21)?.ready_for_pickup === false);
assert('4.4 The pending envelope counter drops to zero', tab.readyForPickupCount === 0);
assert('4.5 The modal closes after the handover', tab.selectedRefund === null && tab.pin === '');
assert('4.6 A confirmation banner names the anonymized claimant and amount',
  tab.successMessage.includes('Laura S.') && tab.successMessage.includes('2.50 €'));
assert('4.7 Other cases are left untouched',
  tab.refunds.find((refund) => refund.id === 22)?.status === 'VERIFIED_PENDING_PAYMENT');

// ---------------------------------------------------------------------
// TEST GROUP 5: Wrong PIN keeps the cash in custody (RF-REF-06)
// ---------------------------------------------------------------------
console.log('\n--- Group 5: Wrong PIN keeps the cash in custody ---');

deliveryBehavior = 'reject';
const custodyTab = createTab();
await custodyTab.loadRefunds();
custodyTab.openPinModal(receptionCase);
['9', '9', '9', '9'].forEach((digit) => custodyTab.appendPinDigit(digit));
await custodyTab.submitPin();

assert('5.1 A rejected PIN shows the "PIN de recogida incorrecto" warning',
  custodyTab.pinError.includes('PIN de recogida incorrecto'));
assert('5.2 The modal stays open to retry without losing the case',
  custodyTab.selectedRefund?.id === 21 && custodyTab.pin === '');
assert('5.3 The envelope remains ready for pickup at reception',
  custodyTab.refunds.find((refund) => refund.id === 21)?.ready_for_pickup === true);
assert('5.4 No delivery banner is shown on failure', custodyTab.successMessage === '');
assert('5.5 The attempt did not mutate the loaded list', custodyTab.readyForPickupCount === 1);

// ---------------------------------------------------------------------
// TEST GROUP 6: Template contract and segregation safeguards (Art. V.4)
// ---------------------------------------------------------------------
console.log('\n--- Group 6: Template contract and segregation safeguards ---');

const template = LocationRefundsTab.template;
const lowerTemplate = template.toLowerCase();

assert('6.1 The tab exposes the anonymized list and the delivery action',
  template.includes('claimant_name_anon') && template.includes('Entregar efectivo'));
assert('6.2 The delivery action is bound to ready_for_pickup rows only',
  template.includes('v-if="refund.ready_for_pickup"') && template.includes('openPinModal(refund)'));
assert('6.3 The modal renders the numeric keypad and the 4-digit PIN slots',
  template.includes('pin-keypad') && template.includes("appendPinDigit(digit)") && template.includes('pinSlots'));
assert('6.4 The confirmation button is disabled until the PIN is complete',
  template.includes('!isPinComplete || isDelivering'));
assert('6.5 Touch targets follow the 48px mobile guideline (RNF-REF-05)',
  template.includes('min-height: 48px'));
assert('6.6 The tab uses the institutional card and interactive radii',
  template.includes('--radius-card, 8px') && template.includes('--radius-interactive, 4px'));
assert('6.7 No bank account or private phone field is ever projected (Art. V.4)',
  !lowerTemplate.includes('iban') && !lowerTemplate.includes('bizum')
    && !lowerTemplate.includes('telefono') && !lowerTemplate.includes('teléfono'),
  'The template must not reference bank accounts or private phones.');
const statusProbe = createTab();
assert('6.8 The eight refund statuses have a Spanish badge',
  ['PENDING_INSPECTION', 'DEPOSITED_AT_RECEPTION', 'VERIFIED_PENDING_PAYMENT',
    'REQUIRES_COORDINATOR_APPROVAL', 'PENDING_CONTACT', 'PAID_DIGITAL',
    'REFUNDED_IN_HAND', 'REJECTED'].every((status) => typeof statusProbe.statusMeta(status).label === 'string'));

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-REF-17 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
