/**
 * VendGuard - PublicRefundTrackingView Test Suite (T-REF-15)
 *
 * Acceptance checks for public tracking by token, status timeline, reception PIN,
 * contact rectification, and app routing through ?track=.
 * Native Node.js ESM only; no DOM or third-party test dependencies.
 */

import { ApiClient, api } from '../../public/assets/js/api.js';
import { App } from '../../public/assets/js/app.js';
import { PublicRefundTrackingView, isValidTrackingIban } from '../../public/assets/js/views/PublicRefundTrackingView.js';

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

function createView(token = 'a'.repeat(64)) {
  const instance = {
    ...PublicRefundTrackingView.data.call({ token }),
    trackingToken: token
  };
  Object.assign(instance, PublicRefundTrackingView.methods);
  for (const [key, getter] of Object.entries(PublicRefundTrackingView.computed)) {
    Object.defineProperty(instance, key, { get: getter });
  }
  return instance;
}

const trackingToken = 'b'.repeat(64);
const pendingContact = {
  claimed_amount: 2.5,
  product_attempted: 'Café con leche',
  compensation_method: 'BIZUM',
  status: 'PENDING_CONTACT',
  status_label: 'Pendiente de contacto para completar datos',
  status_description: 'Necesitamos corregir sus datos de pago.',
  pickup_pin: null,
  can_rectify_data: true,
  machine_code: 'VEND-0101',
  location_name: 'Hospital del Mar',
  created_at: '2026-10-01T10:15:00+02:00',
  updated_at: '2026-10-01T11:45:00+02:00'
};

async function runTests() {
  console.log('======================================================================');
  console.log(' VendGuard: Frontend Test Suite - PublicRefundTrackingView (T-REF-15)');
  console.log('======================================================================\n');

  console.log('--- Public API client contract ---');
  const client = new ApiClient('/api');
  let fetchCall = null;
  globalThis.fetch = async (url, options) => {
    fetchCall = { url, options };
    return {
      ok: true,
      status: 200,
      headers: new Map([['content-type', 'application/json']]),
      json: async () => ({ success: true, data: { status: 'PENDING_INSPECTION' } })
    };
  };
  await client.publicRefunds.track(trackingToken);
  assert('Tracking GET calls the public endpoint with an encoded token',
    fetchCall?.url === `/api/public/refunds/track?token=${trackingToken}` && fetchCall?.options.method === 'GET');
  await client.publicRefunds.rectify(trackingToken, { bizum_phone: '600111222' });
  assert('Rectification PATCH sends only the public endpoint and supplied correction',
    fetchCall?.url === `/api/public/refunds/track?token=${trackingToken}`
      && fetchCall?.options.method === 'PATCH'
      && fetchCall?.options.body === JSON.stringify({ bizum_phone: '600111222' }));

  console.log('\n--- App deep-link routing and view registration ---');
  globalThis.window = { location: { search: `?track=${trackingToken}` } };
  const appInstance = { ...App.data() };
  App.created.call(appInstance);
  assert('The ?track= deep link selects the public tracking view before session restore',
    appInstance.currentView === 'tracking' && appInstance.trackingToken === trackingToken);
  assert('App registers and renders PublicRefundTrackingView without the private shell',
    App.components.PublicRefundTrackingView === PublicRefundTrackingView
      && App.template.includes('<PublicRefundTrackingView')
      && App.template.includes("currentView !== 'tracking'"));

  console.log('\n--- Tracking state, timeline, and public receipt ---');
  let trackedToken = '';
  api.publicRefunds.track = async (token) => {
    trackedToken = token;
    return { ...pendingContact };
  };
  const instance = createView(trackingToken);
  await instance.loadTracking();
  assert('The view loads the DTO using its URL token', trackedToken === trackingToken && instance.refund?.status === 'PENDING_CONTACT');
  assert('The status timeline marks the payment step as current',
    instance.timelineSteps[2].state === 'current' && instance.timelineSteps[1].state === 'complete');
  assert('The public summary includes amount and machine/site context',
    instance.formattedAmount.replace(/\s/g, '') === '2,50€' && instance.refund.machine_code === 'VEND-0101' && instance.refund.location_name === 'Hospital del Mar');
  assert('The native IBAN validation accepts a valid international checksum', isValidTrackingIban('GB82 WEST 1234 5698 7654 32'));
  assert('The native IBAN validation rejects a false checksum', !isValidTrackingIban('GB82 WEST 1234 5698 7654 3211'));
  assert('The tracking template contains the timeline and public status description',
    PublicRefundTrackingView.template.includes('tracking-timeline')
      && PublicRefundTrackingView.template.includes('status_description'));
  assert('The template never renders stored payment details from the public DTO',
    !PublicRefundTrackingView.template.includes('refund.iban')
      && !PublicRefundTrackingView.template.includes('refund.bizum_phone'));

  console.log('\n--- Pickup PIN and contact correction ---');
  api.publicRefunds.track = async () => ({
    ...pendingContact,
    status: 'DEPOSITED_AT_RECEPTION',
    status_label: 'Efectivo depositado en conserjería',
    pickup_pin: '4821',
    can_rectify_data: false
  });
  const deposited = createView(trackingToken);
  await deposited.loadTracking();
  assert('The reception card shows the PIN only for DEPOSITED_AT_RECEPTION',
    deposited.showPickupPin && deposited.refund.pickup_pin === '4821'
      && PublicRefundTrackingView.template.includes('pickup_pin'));

  let correction = null;
  let reloads = 0;
  api.publicRefunds.rectify = async (token, payload) => {
    correction = { token, payload };
    return { status: 'VERIFIED_PENDING_PAYMENT' };
  };
  api.publicRefunds.track = async () => {
    reloads++;
    return { ...pendingContact, status: 'VERIFIED_PENDING_PAYMENT', can_rectify_data: false };
  };
  const rectifying = createView(trackingToken);
  rectifying.refund = { ...pendingContact };
  rectifying.bizumPhone = '600111222';
  assert('PENDING_CONTACT exposes a valid Bizum correction', rectifying.canRectify && rectifying.isRectificationValid);
  await rectifying.submitRectification();
  assert('Submitting correction sends the token and Bizum field, then reloads status',
    correction?.token === trackingToken
      && correction?.payload?.bizum_phone === '600111222'
      && rectifying.refund.status === 'VERIFIED_PENDING_PAYMENT'
      && reloads === 1);
  assert('The form is shown only for PENDING_CONTACT with can_rectify_data',
    PublicRefundTrackingView.template.includes('v-if="canRectify"')
      && PublicRefundTrackingView.computed.canRectify.call({ refund: { status: 'PENDING_CONTACT', can_rectify_data: true } })
      && !PublicRefundTrackingView.computed.canRectify.call({ refund: { status: 'PENDING_CONTACT', can_rectify_data: false } }));

  const ibanRectification = createView(trackingToken);
  ibanRectification.refund = { ...pendingContact, compensation_method: 'TRANSFERENCIA_BANCARIA' };
  ibanRectification.iban = 'GB82 WEST 1234 5698 7654 32';
  assert('PENDING_CONTACT accepts a valid international IBAN for transfer', ibanRectification.isRectificationValid);
  await ibanRectification.submitRectification();
  assert('IBAN rectification submits the normalized value through the public PATCH',
    correction?.payload?.iban === 'GB82WEST12345698765432'
      && correction?.payload?.bizum_phone === undefined);

  const missing = createView('');
  let missingTokenRequested = false;
  api.publicRefunds.track = async () => { missingTokenRequested = true; return {}; };
  await missing.loadTracking();
  assert('An absent token displays an error without making an API request',
    Boolean(missing.errorMessage) && !missingTokenRequested);

  console.log('\n======================================================================');
  if (failures === 0) {
    console.log(` RESULTADO: TODAS LAS PRUEBAS PASARON (${assertions} aserciones, 0 fallos).`);
    console.log(' CONDICIÓN T-REF-15 CUMPLIDA.');
  } else {
    console.error(` RESULTADO: ${failures} PRUEBA(S) FALLIDA(S) de ${assertions}.`);
    process.exitCode = 1;
  }
  console.log('======================================================================');
}

runTests().catch((error) => {
  console.error('Fatal test error:', error);
  process.exitCode = 1;
});
