/**
 * VendGuard - TechnicianResolutionRefundBlock Test Suite (T-REF-16)
 *
 * Covers the mandatory cash verdict, reception custody safeguards, and optional
 * unclaimed cash reporting. Native Node.js ESM; no DOM or third-party packages.
 */

import { TechnicianResolutionRefundBlock } from '../../public/assets/js/components/TechnicianResolutionRefundBlock.js';

let assertions = 0;
let failures = 0;

function assert(description, condition) {
  assertions++;
  if (condition) {
    console.log(`  [${condition ? 'PASS' : 'FAIL'}] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    failures++;
  }
}

function createBlock(refundRequests = [], requiresVerdict = refundRequests.length > 0, allowUnclaimedCash = false) {
  const events = [];
  const instance = {
    ...TechnicianResolutionRefundBlock.data(),
    refundRequests,
    requiresVerdict,
    allowUnclaimedCash,
    $emit: (event, value) => events.push({ event, value })
  };
  Object.assign(instance, TechnicianResolutionRefundBlock.methods);
  for (const [key, getter] of Object.entries(TechnicianResolutionRefundBlock.computed)) {
    Object.defineProperty(instance, key, { get: () => getter.call(instance) });
  }
  instance.events = events;
  return instance;
}

const inHandClaim = {
  id: 17,
  claimed_amount: 2.5,
  product_attempted: 'Café con leche',
  compensation_method: 'EN_MANO_SEDE',
  status: 'PENDING_INSPECTION',
  custody_instruction: 'Deposite el efectivo en recepción.'
};

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - TechnicianResolutionRefundBlock (T-REF-16)');
console.log('======================================================================\n');

console.log('--- Mandatory field verdict and reception delivery ---');
const handBlock = createBlock([inHandClaim]);
assert('A pending request requires a verdict before resolution', !handBlock.validate().isValid);
handBlock.setFinding('FOUND_PHYSICAL');
assert('FOUND_PHYSICAL requires a positive recovered amount', !handBlock.validate().isValid);
handBlock.recoveredAmount = '2.50';
handBlock.setCustodyAction('LEFT_AT_RECEPTION');
assert('Reception custody requires the receiving receptionist name', !handBlock.validate().isValid);
handBlock.receptionistName = 'Ana Conserjería';
const handResult = handBlock.validate();
assert('A valid small in-person claim can be deposited at reception', handResult.isValid);
assert('The verdict payload includes finding, exact amount, custody, and receptionist',
  handResult.payload?.refund_inspection?.finding === 'FOUND_PHYSICAL'
    && handResult.payload.refund_inspection.recovered_amount === 2.5
    && handResult.payload.refund_inspection.cash_custody_action === 'LEFT_AT_RECEPTION'
    && handResult.payload.refund_inspection.receptionist_name === 'Ana Conserjería');

console.log('\n--- Central custody is enforced for digital and elevated amounts ---');
const digitalBlock = createBlock([{
  ...inHandClaim,
  compensation_method: 'BIZUM'
}]);
digitalBlock.setFinding('FOUND_PHYSICAL');
digitalBlock.setRecoveredAmount('4.00');
digitalBlock.setCustodyAction('LEFT_AT_RECEPTION');
digitalBlock.receptionistName = 'No debe aceptar';
assert('A digital compensation request cannot be sent to reception', digitalBlock.requiresCentralCustody);
assert('A digital claim forces HELD_FOR_CENTRAL in the submitted verdict',
  digitalBlock.validate().payload?.refund_inspection?.cash_custody_action === 'HELD_FOR_CENTRAL');
assert('A blocked reception selection is not serialized as an accepted handover',
  digitalBlock.validate().payload?.refund_inspection?.receptionist_name === null);
assert('Reception custody controls are disabled for digital requests',
  TechnicianResolutionRefundBlock.template.includes(':disabled="disabled || requiresCentralCustody"'));

const highAmountBlock = createBlock([inHandClaim]);
highAmountBlock.setFinding('FOUND_PHYSICAL');
highAmountBlock.setRecoveredAmount('10.01');
assert('Recovered cash above 10 euros forces central custody', highAmountBlock.requiresCentralCustody);
assert('Elevated amount cannot be submitted as reception custody',
  highAmountBlock.validate().payload?.refund_inspection?.cash_custody_action === 'HELD_FOR_CENTRAL');
const multiClaimBlock = createBlock([inHandClaim, { ...inHandClaim, id: 18, compensation_method: 'TRANSFERENCIA_BANCARIA' }]);
multiClaimBlock.setFinding('FOUND_PHYSICAL');
multiClaimBlock.setRecoveredAmount('2.50');
assert('A digital request among several pending claims forces central custody for the shared recovery',
  multiClaimBlock.requiresCentralCustody
    && multiClaimBlock.validate().payload?.refund_inspection?.cash_custody_action === 'HELD_FOR_CENTRAL');

console.log('\n--- Closed findings and optional unclaimed cash ---');
const unverifiedBlock = createBlock([inHandClaim]);
unverifiedBlock.setFinding('UNVERIFIED_NO_CASH');
assert('UNVERIFIED_NO_CASH requires at least 20 characters of justification', !unverifiedBlock.validate().isValid);
unverifiedBlock.justification = 'No se observan monedas ni evidencia de saldo retenido.';
assert('A descriptive unverified verdict becomes valid', unverifiedBlock.validate().isValid);
assert('No-cash verdict does not claim a cash custody action',
  unverifiedBlock.validate().payload.refund_inspection.cash_custody_action === null);

const optionalBlock = createBlock([], false, true);
assert('Without pending claims, verdict is optional', optionalBlock.validate().isValid);
assert('Unclaimed-cash registration is restricted to payment incidents with no refund claims',
  optionalBlock.canRegisterUnclaimedCash
    && !createBlock([], false, false).canRegisterUnclaimedCash
    && !createBlock([inHandClaim], true, true).canRegisterUnclaimedCash);
optionalBlock.toggleUnclaimedCash(true);
assert('Enabling unclaimed cash requires a positive amount', !optionalBlock.validate().isValid);
optionalBlock.unclaimedAmount = '1.25';
optionalBlock.unclaimedNotes = 'Monedas recuperadas en el canal del monedero.';
const unclaimedResult = optionalBlock.validate();
assert('A positive unclaimed amount can be submitted without a claimant', unclaimedResult.isValid);
assert('Unclaimed cash payload is separate and contains amount and notes',
  unclaimedResult.payload.unclaimed_cash_found?.amount === 1.25
    && unclaimedResult.payload.unclaimed_cash_found.notes === 'Monedas recuperadas en el canal del monedero.'
    && unclaimedResult.payload.refund_inspection === undefined);
optionalBlock.publishChange();
assert('Component emits the updated validation state and payload to its parent',
  optionalBlock.events.some((event) => event.event === 'change'
    && event.value.isValid === true
    && event.value.unclaimed_cash_found?.amount === 1.25));

console.log('\n--- Mobile block integration contract ---');
assert('The block renders active claim summaries without exposing payment details',
  TechnicianResolutionRefundBlock.template.includes('refundRequests')
    && TechnicianResolutionRefundBlock.template.includes('claimed_amount')
    && TechnicianResolutionRefundBlock.template.includes('product_attempted')
    && !TechnicianResolutionRefundBlock.template.includes('iban')
    && !TechnicianResolutionRefundBlock.template.includes('bizum_phone'));
const findingValues = TechnicianResolutionRefundBlock.computed.findingOptions.call(createBlock());
assert('The closed verdict options and physical-cash controls are present',
  findingValues.map((option) => option.value).join(',') === 'FOUND_PHYSICAL,CONFIRMED_NO_CASH,UNVERIFIED_NO_CASH'
    && TechnicianResolutionRefundBlock.template.includes('inputmode="decimal"'));
assert('The on-site cash finding is optional and its entry is touch-friendly',
  TechnicianResolutionRefundBlock.template.includes('unclaimed-cash-toggle')
    && TechnicianResolutionRefundBlock.template.includes('Importe recuperado (€)')
    && TechnicianResolutionRefundBlock.template.includes('min-height: 48px'));

console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: TODAS LAS PRUEBAS PASARON (${assertions} aserciones, 0 fallos).`);
  console.log(' CONDICIÓN T-REF-16 CUMPLIDA.');
} else {
  console.error(` RESULTADO: ${failures} PRUEBA(S) FALLIDA(S) de ${assertions}.`);
  process.exitCode = 1;
}
console.log('======================================================================');
