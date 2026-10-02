/**
 * VendGuard - QrRefundRequestBlock Test Suite (T-REF-14)
 *
 * Acceptance checks for the optional QR refund request, live payment validation,
 * and the permanent receipt shown after a successful submission.
 *
 * Dogma Vanilla: native Node.js ESM, no DOM or third-party test dependencies.
 */

import { api } from '../../public/assets/js/api.js';
import {
  QrRefundRequestBlock,
  isValidBizumPhone,
  isValidSpanishIban,
  isValidRefundClaim
} from '../../public/assets/js/components/QrRefundRequestBlock.js';
import { QrReportView } from '../../public/assets/js/views/QrReportView.js';

let assertions = 0;
let failures = 0;

function assert(description, condition) {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    failures++;
  }
}

function createView() {
  const instance = {
    ...QrReportView.data(),
    machine: { code: 'VEND-0101' },
    description: 'La máquina retuvo dos euros y no entregó el producto.',
    $emit: () => {}
  };
  Object.assign(instance, QrReportView.methods);
  for (const [key, getter] of Object.entries(QrReportView.computed)) {
    Object.defineProperty(instance, key, { get: getter });
  }
  return instance;
}

async function runTests() {
  console.log('======================================================================');
  console.log(' VendGuard: Frontend Test Suite - QrRefundRequestBlock (T-REF-14)');
  console.log('======================================================================\n');

  console.log('--- Live Bizum and Spanish IBAN validation ---');
  assert('Accepts a nine-digit Bizum mobile', isValidBizumPhone('600111222'));
  assert('Accepts standard display separators in Bizum phone', isValidBizumPhone('600 111 222'));
  assert('Rejects an eight-digit Bizum phone', !isValidBizumPhone('60011122'));
  assert('Accepts a Spanish IBAN with a valid MOD 97 checksum', isValidSpanishIban('ES91 2100 0418 4502 0005 1332'));
  assert('Rejects a Spanish IBAN with a false checksum', !isValidSpanishIban('ES91 2100 0418 4502 0005 1399'));
  assert('Rejects a non-Spanish IBAN in this QR form', !isValidSpanishIban('GB82WEST12345698765432'));

  console.log('\n--- Optional claim fields and reception availability ---');
  assert('A claim needs a positive amount capped at 50 euros', !isValidRefundClaim({
    claimed_amount: '50.01', contact_name: 'Laura', contact_phone: '600111222', compensation_method: 'EN_MANO_SEDE'
  }, true));
  assert('A valid in-person claim is accepted when reception exists', isValidRefundClaim({
    claimed_amount: '2.50', contact_name: 'Laura', contact_phone: '600111222', compensation_method: 'EN_MANO_SEDE'
  }, true));
  assert('In-person compensation is rejected when reception is unavailable', !isValidRefundClaim({
    claimed_amount: '2.50', contact_name: 'Laura', contact_phone: '600111222', compensation_method: 'EN_MANO_SEDE'
  }, false));
  assert('Bizum requires a valid number', !isValidRefundClaim({
    claimed_amount: '2.50', contact_name: 'Laura', contact_phone: '600111222', compensation_method: 'BIZUM', bizum_phone: '123'
  }, true));
  assert('Transfer requires a valid IBAN', !isValidRefundClaim({
    claimed_amount: '2.50', contact_name: 'Laura', contact_phone: '600111222', compensation_method: 'TRANSFERENCIA_BANCARIA', iban: 'ES000'
  }, true));

  console.log('\n--- Component and QR view integration ---');
  const locationWithReception = {
    ...QrReportView.data(),
    code: 'VEND-0101',
    $emit: () => {}
  };
  Object.assign(locationWithReception, QrReportView.methods);
  for (const [key, getter] of Object.entries(QrReportView.computed)) {
    Object.defineProperty(locationWithReception, key, { get: getter });
  }
  api.qr.scan = async () => ({
    status_mode: 'CAN_REPORT',
    machine: { code: 'VEND-0101', model: 'Vending' },
    location: { name: 'Sede sin recepción', has_physical_reception: false },
    active_incident: null
  });
  await locationWithReception.resolveMachine();
  assert('QR scan exposes physical reception availability for the form', locationWithReception.location?.has_physical_reception === false);

  assert('The refund block is a registered QrReportView child', QrReportView.components?.QrRefundRequestBlock === QrRefundRequestBlock);
  assert('The block exposes an accessible collapsed opt-in and live validation fields',
    QrRefundRequestBlock.template.includes('id="refund-request-toggle"')
      && QrRefundRequestBlock.template.includes('refund-bizum-phone')
      && QrRefundRequestBlock.template.includes('refund-iban')
      && QrRefundRequestBlock.template.includes('aria-invalid')
      && Object.hasOwn(QrRefundRequestBlock.computed, 'bizumValid')
      && Object.hasOwn(QrRefundRequestBlock.computed, 'ibanValid'));
  assert('The handover option is conditional on physical reception', QrRefundRequestBlock.template.includes('hasPhysicalReception'));

  const view = createView();
  let capturedPayload = null;
  api.qr.report = async (payload) => {
    capturedPayload = payload;
    return {
      ticket_code: 'INC-2026-0140',
      status: 'REGISTERED',
      refund: {
        id: 14,
        claimed_amount: 2.5,
        compensation_method: 'EN_MANO_SEDE',
        pickup_pin: '4821',
        tracking_token: 'a'.repeat(64),
        tracking_url: '/?track=' + 'a'.repeat(64)
      }
    };
  };

  const claimInstance = {
    isExpanded: true,
    claimedAmount: '2.50',
    contactName: 'Laura Sanitaria',
    contactPhone: '600111222',
    compensationMethod: 'EN_MANO_SEDE',
    bizumPhone: '',
    iban: '',
    productAttempted: 'Café con leche',
    hasPhysicalReception: true,
    claimValid: true,
    claim: {
      claimed_amount: 2.5,
      contact_name: 'Laura Sanitaria',
      contact_phone: '600111222',
      compensation_method: 'EN_MANO_SEDE',
      bizum_phone: '',
      iban: '',
      product_attempted: 'Café con leche'
    },
    $emit: (event, value) => {
      if (event === 'update:modelValue') view.handleRefundClaimUpdate(value);
      if (event === 'validity-change') view.handleRefundClaimValidity(value);
    }
  };
  QrRefundRequestBlock.methods.publishClaim.call(claimInstance);
  view.refundClaimValid = true;
  await view.submitReport();

  assert('The QR report sends the optional refund data with the incident',
    capturedPayload?.refund_requested === true
      && capturedPayload?.claimed_amount === 2.5
      && capturedPayload?.contact_name === 'Laura Sanitaria'
      && capturedPayload?.compensation_method === 'EN_MANO_SEDE');
  assert('The legacy retained-money field is not submitted when the full claim is captured', !Object.hasOwn(capturedPayload || {}, 'retained_money_amount'));
  assert('A successful report retains its refund receipt in confirmation state',
    view.submittedTicket?.refund?.pickup_pin === '4821'
      && view.submittedTicket?.refund?.tracking_url === '/?track=' + 'a'.repeat(64));
  assert('The confirmation template renders the PIN and permanent tracking link',
    QrReportView.template.includes('refund-receipt-card')
      && QrReportView.template.includes('pickup_pin')
      && QrReportView.template.includes('tracking_url'));

  const invalidView = createView();
  invalidView.refundClaim = { refund_requested: true, claimed_amount: '2', contact_name: '', contact_phone: '', compensation_method: 'BIZUM', bizum_phone: '123' };
  invalidView.refundClaimValid = false;
  let invalidSubmitCalled = false;
  api.qr.report = async () => { invalidSubmitCalled = true; return {}; };
  await invalidView.submitReport();
  assert('Invalid refund data cannot be submitted', !invalidSubmitCalled && !invalidView.submitted);

  console.log('\n======================================================================');
  if (failures === 0) {
    console.log(` RESULTADO: TODAS LAS PRUEBAS PASARON (${assertions} aserciones, 0 fallos).`);
    console.log(' CONDICIÓN T-REF-14 CUMPLIDA.');
  } else {
    console.error(` RESULTADO: ${failures} PRUEBA(S) FALLIDA(S) de ${assertions}.`);
    process.exitCode = 1;
  }
  console.log('======================================================================');
}

runTests().catch((error) => {
  console.error('Error fatal en la suite:', error);
  process.exitCode = 1;
});
