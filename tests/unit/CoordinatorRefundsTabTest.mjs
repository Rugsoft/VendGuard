/**
 * VendGuard - CoordinatorRefundsTab Test Suite (T-REF-18)
 *
 * Covers the global refund inbox, its filters, the double approval of amounts
 * above 10,00 €, the digital settlement with its bank reference and the
 * motivated rejection with a >= 20 character justification.
 *
 * Constitutional coverage:
 * - RF-REF-03: approval only for cases flagged by the domain; 50,00 € cap.
 * - RF-REF-07: settlement without a payment reference is refused.
 * - RF-REF-08: discrepancy warning and individual amount validation.
 * - Art. III: a rejection preserves the case, it never deletes it.
 *
 * Native Node.js ESM; no DOM or third-party packages.
 */

const storageMock = (() => {
  let store = {};
  return {
    getItem: (key) => store[key] || null,
    setItem: (key, value) => { store[key] = String(value); },
    removeItem: (key) => { delete store[key]; },
    clear: () => { store = {}; }
  };
})();
globalThis.localStorage = storageMock;

import { api } from '../../public/assets/js/api.js';
import { App } from '../../public/assets/js/app.js';
import { ModalDialog } from '../../public/assets/js/components/ModalDialog.js';
import { CoordinatorRefundsTab } from '../../public/assets/js/components/CoordinatorRefundsTab.js';
import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';

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
  const instance = { ...CoordinatorRefundsTab.data() };
  Object.assign(instance, CoordinatorRefundsTab.methods);
  for (const [key, getter] of Object.entries(CoordinatorRefundsTab.computed)) {
    Object.defineProperty(instance, key, { get: () => getter.call(instance) });
  }
  return instance;
}

// ---------------------------------------------------------------------
// Fixtures: full financial projection, only the Coordination role sees it
// ---------------------------------------------------------------------
const approvalCase = {
  id: 31,
  incident_id: 101,
  incident_code: 'INC-2026-00101',
  machine_id: 1,
  machine_code: 'VEND-0101',
  location_id: 1,
  location_name: 'Hospital del Mar - Edificio Central',
  claimant_name: 'Laura Sanitaria',
  claimant_contact: '600111222',
  claimed_amount: 15,
  recovered_amount: 12,
  approved_amount: null,
  payable_amount: 15,
  discrepancy_ratio: 0.2,
  product_attempted: 'Sándwich mixto',
  compensation_method: 'TRANSFERENCIA_BANCARIA',
  bizum_phone: null,
  iban: 'ES9121000418450200051332',
  status: 'REQUIRES_COORDINATOR_APPROVAL',
  status_label: 'Requiere visto bueno de coordinación',
  technician_finding: 'FOUND_PHYSICAL',
  cash_custody_action: 'HELD_FOR_CENTRAL',
  technician_justification: 'Recuperadas monedas del canal interno del monedero.',
  coordinator_decision: null,
  coordinator_justification: null,
  payment_reference: null,
  paid_at: null,
  requires_special_supervision: true,
  requires_approval: true,
  awaits_payment: false,
  created_at: '2026-10-01T10:15:00+02:00'
};

const paymentCase = {
  id: 32,
  incident_id: 102,
  incident_code: 'INC-2026-00102',
  machine_id: 2,
  machine_code: 'VEND-0102',
  location_id: 1,
  location_name: 'Hospital del Mar - Edificio Central',
  claimant_name: 'Marc Riera',
  claimant_contact: '600333444',
  claimed_amount: 8,
  recovered_amount: 8,
  approved_amount: 8,
  payable_amount: 8,
  discrepancy_ratio: null,
  product_attempted: 'Café con leche',
  compensation_method: 'BIZUM',
  bizum_phone: '600333444',
  iban: null,
  status: 'VERIFIED_PENDING_PAYMENT',
  status_label: 'Verificado, pendiente de pago',
  technician_finding: 'FOUND_PHYSICAL',
  cash_custody_action: 'HELD_FOR_CENTRAL',
  technician_justification: null,
  coordinator_decision: 'APPROVED',
  coordinator_justification: 'Cuantía verificada contra el registro de ventas.',
  payment_reference: null,
  paid_at: null,
  requires_special_supervision: false,
  requires_approval: false,
  awaits_payment: true,
  created_at: '2026-10-01T11:00:00+02:00'
};

const inspectionCase = {
  id: 33,
  incident_id: 103,
  incident_code: 'INC-2026-00103',
  machine_id: 3,
  machine_code: 'VEND-0103',
  location_id: 2,
  location_name: 'Campus Tecnológico',
  claimant_name: 'Núria Puig',
  claimant_contact: 'nuria@example.com',
  claimed_amount: 1.5,
  recovered_amount: null,
  approved_amount: null,
  payable_amount: 1.5,
  discrepancy_ratio: null,
  product_attempted: 'Agua mineral',
  compensation_method: 'EN_MANO_SEDE',
  bizum_phone: null,
  iban: null,
  status: 'PENDING_INSPECTION',
  status_label: 'Pendiente de inspección técnica',
  technician_finding: null,
  cash_custody_action: null,
  technician_justification: null,
  coordinator_decision: null,
  coordinator_justification: null,
  payment_reference: null,
  paid_at: null,
  requires_special_supervision: false,
  requires_approval: false,
  awaits_payment: false,
  created_at: '2026-09-30T09:00:00+02:00'
};

const rejectedCase = {
  ...inspectionCase,
  id: 34,
  incident_code: 'INC-2026-00104',
  machine_code: 'VEND-0104',
  location_name: 'Residencia Universitaria',
  status: 'REJECTED',
  status_label: 'Desestimado'
};

let listCalls = [];
let approveCalls = [];
let payCalls = [];
let rejectCalls = [];
let regularizeCalls = [];

api.coordinator.getRefunds = async (filters = {}) => {
  listCalls.push(filters);
  return {
    total: 4,
    requires_approval_total: 1,
    stranded_total: 1,
    limit: 50,
    offset: 0,
    filters,
    totals: { claimed_amount: 32.5, payable_amount: 26 },
    items: [approvalCase, paymentCase, inspectionCase, rejectedCase]
  };
};

api.coordinator.regularizeRefund = async (refundId, payload) => {
  regularizeCalls.push({ refundId, payload });
  return {
    id: refundId,
    status: 'REQUIRES_COORDINATOR_APPROVAL',
    technician_finding: payload?.finding,
    regularized_by_coordinator: true
  };
};

api.coordinator.approveRefund = async (refundId, approvedAmount, notes) => {
  approveCalls.push({ refundId, approvedAmount, notes });
  return {
    id: refundId,
    status: 'VERIFIED_PENDING_PAYMENT',
    approved_amount: approvedAmount,
    claimed_amount: 15,
    coordinator_decision: 'APPROVED',
    justification: notes || null,
    awaits_payment: true
  };
};

api.coordinator.payRefund = async (refundId, paymentReference, paidAmount) => {
  payCalls.push({ refundId, paymentReference, paidAmount });
  return {
    id: refundId,
    status: 'PAID_DIGITAL',
    payment_reference: paymentReference,
    paid_amount: paidAmount,
    approved_amount: 8,
    paid_at: '2026-10-02T09:30:00+02:00'
  };
};

api.coordinator.rejectRefund = async (refundId, rejectionReason) => {
  rejectCalls.push({ refundId, rejectionReason });
  return {
    id: refundId,
    status: 'REJECTED',
    coordinator_decision: 'REJECTED',
    justification: rejectionReason,
    claimed_amount: 1.5,
    paid_amount: null,
    preserved_for_audit: true
  };
};

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - CoordinatorRefundsTab (T-REF-18)');
console.log('======================================================================\n');

// ---------------------------------------------------------------------
// TEST GROUP 1: Global inbox and filters (RF-REF-03)
// ---------------------------------------------------------------------
console.log('--- Group 1: Global refund inbox and filters ---');

const tab = createTab();
await tab.loadRefunds();

assert('1.1 The inbox queries the coordinator refunds endpoint without filters', listCalls.length === 1 && Object.keys(listCalls[0]).length === 0);
assert('1.2 The four cases and the envelope totals are stored', tab.hasItems && tab.items.length === 4 && tab.total === 4);
assert('1.3 The pending-approval counter comes from the envelope', tab.requiresApprovalTotal === 1 && tab.hasApprovalPending);
assert('1.4 The payable liability aggregates the envelope totals', tab.totals.payable_amount === 26);
assert('1.5 Amounts are rendered with two decimals', tab.formatAmount(15) === '15.00 €');

tab.setStatusFilter('REQUIRES_COORDINATOR_APPROVAL');
assert('1.6 The status filter is sent to the backend contract', listCalls[1]?.status === 'REQUIRES_COORDINATOR_APPROVAL');

tab.setStatusFilter('');
tab.toggleApprovalOnly(true);
assert('1.7 The double-approval shortcut translates to requires_approval_only', listCalls[3]?.requires_approval_only === 1);

tab.toggleApprovalOnly(false);
tab.searchQuery = 'VEND-0102';
assert('1.8 The search narrows by machine code without a backend round trip',
  tab.filteredItems.length === 1 && tab.filteredItems[0].id === 32);
tab.searchQuery = 'campus';
assert('1.9 The search also matches the site name', tab.filteredItems.length === 1 && tab.filteredItems[0].id === 33);
tab.searchQuery = 'laura';
assert('1.10 The search matches the claimant of the full financial projection', tab.filteredItems.length === 1 && tab.filteredItems[0].id === 31);
tab.searchQuery = '';
tab.statusFilter = '';
tab.approvalOnly = false;
assert('1.11 Clearing the filters restores the whole inbox', tab.filteredItems.length === 4);

const failingTab = createTab();
const healthyLoader = api.coordinator.getRefunds;
api.coordinator.getRefunds = async () => { throw new Error('Sesión de coordinación caducada.'); };
await failingTab.loadRefunds();
api.coordinator.getRefunds = healthyLoader;
assert('1.12 A failed load surfaces a retryable error state',
  failingTab.error === 'Sesión de coordinación caducada.' && failingTab.items.length === 0 && failingTab.loading === false);

// ---------------------------------------------------------------------
// TEST GROUP 2: Double approval of the final amount (RF-REF-03)
// ---------------------------------------------------------------------
console.log('\n--- Group 2: Double approval of amounts above 10,00 € ---');

assert('2.1 Only the cases flagged by the domain can be approved',
  tab.canApprove(approvalCase) && !tab.canApprove(paymentCase) && !tab.canApprove(inspectionCase));

tab.openApprovalModal(paymentCase);
assert('2.2 A verified case without pending approval cannot open the approval modal', tab.showApprovalModal === false);

tab.openApprovalModal(approvalCase);
assert('2.3 The approval modal preloads the payable amount', tab.showApprovalModal && tab.approvedAmount === '15');

tab.setApprovalAmount('');
await tab.submitApproval();
assert('2.4 An empty amount is refused with a Spanish message and no API call',
  tab.approvalError.includes('cuantía autorizada') && approveCalls.length === 0);

tab.setApprovalAmount('50.01');
await tab.submitApproval();
assert('2.5 The 50,00 € antifraud cap is enforced client-side', !tab.isApprovalAmountValid && approveCalls.length === 0);

tab.setApprovalAmount('12.5');
tab.approvalNotes = 'Comprobado el registro de ventas y el corte de stock; se autoriza la devolución íntegra.';
await tab.submitApproval();

assert('2.6 The approval is posted with the case id, the final amount and the notes',
  approveCalls.length === 1 && approveCalls[0].refundId === 31
    && approveCalls[0].approvedAmount === 12.5
    && approveCalls[0].notes.includes('registro de ventas'));
assert('2.7 The case transitions to VERIFIED_PENDING_PAYMENT and awaits settlement',
  tab.items.find((item) => item.id === 31)?.status === 'VERIFIED_PENDING_PAYMENT'
    && tab.items.find((item) => item.id === 31)?.awaits_payment === true);
assert('2.8 The approved amount is stored on the local case',
  tab.items.find((item) => item.id === 31)?.approved_amount === 12.5);
assert('2.9 The case no longer requires approval and the counter drops to zero',
  tab.items.find((item) => item.id === 31)?.requires_approval === false && tab.requiresApprovalTotal === 0);
assert('2.10 The modal closes after the approval', tab.showApprovalModal === false && tab.selectedRefund === null);
assert('2.11 A confirmation message names the claimant and the authorized amount',
  tab.actionMessage.includes('Laura Sanitaria') && tab.actionMessage.includes('12.50 €'));

// ---------------------------------------------------------------------
// TEST GROUP 3: Digital settlement with reference (RF-REF-07)
// ---------------------------------------------------------------------
console.log('\n--- Group 3: Digital settlement with bank reference ---');

assert('3.1 Only verified cases pending payment can be settled',
  tab.canPay(paymentCase) && !tab.canPay(approvalCase) && !tab.canPay(rejectedCase));

tab.openPaymentModal(approvalCase);
assert('3.2 A case that is not awaiting payment cannot open the settlement modal', tab.showPaymentModal === false);

tab.openPaymentModal(paymentCase);
assert('3.3 The settlement modal preloads the payable amount', tab.showPaymentModal && tab.paymentAmount === '8');

await tab.submitPayment();
assert('3.4 A settlement without a payment reference is refused with a Spanish message',
  tab.paymentError.includes('referencia del justificante') && payCalls.length === 0);

tab.paymentReference = 'BIZUM-20261002-998822';
await tab.submitPayment();

assert('3.5 The settlement posts the id, the reference and the amount',
  payCalls.length === 1 && payCalls[0].refundId === 32
    && payCalls[0].paymentReference === 'BIZUM-20261002-998822'
    && payCalls[0].paidAmount === 8);
assert('3.6 The case becomes PAID_DIGITAL with its audited reference',
  tab.items.find((item) => item.id === 32)?.status === 'PAID_DIGITAL'
    && tab.items.find((item) => item.id === 32)?.payment_reference === 'BIZUM-20261002-998822');
assert('3.7 The settlement records the payment timestamp returned by the backend',
  tab.items.find((item) => item.id === 32)?.paid_at === '2026-10-02T09:30:00+02:00');
assert('3.8 The modal closes and no case awaits payment twice',
  tab.showPaymentModal === false && tab.items.find((item) => item.id === 32)?.awaits_payment === false);
assert('3.9 The confirmation message echoes the bank reference',
  tab.actionMessage.includes('BIZUM-20261002-998822'));

// ---------------------------------------------------------------------
// TEST GROUP 4: Motivated rejection >= 20 characters (RF-REF-08, Art. III)
// ---------------------------------------------------------------------
console.log('\n--- Group 4: Motivated rejection ---');

assert('4.1 Rejection is offered only where the lifecycle graph allows it',
  tab.canReject(approvalCase) && !tab.canReject(inspectionCase)
    && !tab.canReject(paymentCase) && !tab.canReject(rejectedCase)
    && !tab.canReject({ ...approvalCase, status: 'REFUNDED_IN_HAND' }));

const rejectTab = createTab();
const rejectionCase = {
  ...approvalCase,
  id: 35,
  incident_code: 'INC-2026-00105',
  claimant_name: 'Pere Vidal'
};
const healthyLoader2 = api.coordinator.getRefunds;
api.coordinator.getRefunds = async () => ({
  total: 1,
  requires_approval_total: 1,
  totals: { claimed_amount: 15, payable_amount: 15 },
  items: [rejectionCase]
});
await rejectTab.loadRefunds();
api.coordinator.getRefunds = healthyLoader2;

rejectTab.openRejectModal(inspectionCase);
assert('4.2 A case outside the approval state cannot open the rejection modal', rejectTab.showRejectModal === false);

rejectTab.openRejectModal(rejectionCase);
assert('4.3 The rejection modal opens over a case awaiting approval',
  rejectTab.showRejectModal === true && rejectTab.selectedRefund?.id === 35);

rejectTab.rejectionReason = 'Sin evidencias.';
await rejectTab.submitRejection();
assert('4.4 An 18-character reason is refused with no API call (Art. V.1)',
  rejectTab.rejectionError.includes('20 caracteres') && rejectCalls.length === 0);

rejectTab.rejectionReason = 'Inspección técnica sin monedas atascadas y máquina operando con normalidad.';
await rejectTab.submitRejection();

assert('4.5 The rejection posts the written justification',
  rejectCalls.length === 1 && rejectCalls[0].refundId === 35
    && rejectCalls[0].rejectionReason.includes('sin monedas atascadas'));
assert('4.6 The case is marked as rejected and never removed (Art. III)',
  rejectTab.items.length === 1 && rejectTab.items[0].status === 'REJECTED');
assert('4.7 The rejection decision and its justification are stored on the case',
  rejectTab.items[0].coordinator_decision === 'REJECTED'
    && rejectTab.items[0].coordinator_justification.length >= 20);
assert('4.8 The rejection message states that the case is preserved for audit',
  rejectTab.actionMessage.includes('auditoría'));
assert('4.9 The rejection modal closes after the decision',
  rejectTab.showRejectModal === false && rejectTab.selectedRefund === null);

// ---------------------------------------------------------------------
// TEST GROUP 5: Discrepancies and double-approval visual alert (RF-REF-08)
// ---------------------------------------------------------------------
console.log('\n--- Group 5: Discrepancy warning and approval alert ---');

const template = CoordinatorRefundsTab.template;

assert('5.1 The discrepancy warning shows recovered vs claimed amounts',
  tab.discrepancyLabel(approvalCase) === 'Recuperado 12.00 € vs Reclamado 15.00 €'
    && tab.discrepancyLabel(paymentCase) === '');
assert('5.2 Cases without discrepancies never render the warning',
  !CoordinatorRefundsTab.methods.discrepancyLabel.call(tab, paymentCase));
assert('5.3 The rows pending approval are highlighted with the institutional warning token and carry the supervision flag',
  template.includes(':style="{') && template.includes("refund.requires_approval ? 'var(--color-warning-bg)'")
    && template.includes('requires_special_supervision') && template.includes('Supervisión especial'));
assert('5.4 The inbox alert announces the number of cases requiring double authorization',
  template.includes('requieren doble autorización') && template.includes('data-testid="approval-alert"'));
assert('5.5 The approval modal explains the 10,00 € threshold and the 50,00 € cap',
  template.includes('La autorización superior a 10,00 € exige este doble visto bueno')
    && template.includes('Tope antifraude: 50,00 € por reclamación'));
assert('5.6 The settlement modal projects the destination data only to Coordination (Art. V.4)',
  template.includes('selectedRefund.bizum_phone') && template.includes('selectedRefund.iban')
    && template.includes("selectedRefund.compensation_method === 'BIZUM'"));
assert('5.7 The rejection modal forbids deleting anything and demands the written reason',
  template.includes('mínimo 20 caracteres') && template.includes('se conserva para auditoría'));

// ---------------------------------------------------------------------
// TEST GROUP 6: Template, design system and dashboard integration
// ---------------------------------------------------------------------
console.log('\n--- Group 6: Template contract and dashboard integration ---');

assert('6.1 The tab delegates its three actions to accessible ModalDialog instances',
  CoordinatorRefundsTab.components.ModalDialog === ModalDialog
    && template.includes('data-testid="approval-modal-body"')
    && template.includes('data-testid="payment-modal-body"')
    && template.includes('data-testid="reject-modal-body"'));
assert('6.2 Every action button is bound to its own guard',
  template.includes('v-if="canApprove(refund)"') && template.includes('v-if="canPay(refund)"')
    && template.includes('v-if="canReject(refund)"'));
assert('6.3 Submit buttons stay disabled while the payload is invalid or in flight',
  template.includes('isApproving || !isApprovalAmountValid')
    && template.includes('isPaying || !isPaymentValid')
    && template.includes('isRejecting || !isRejectionValid'));
assert('6.4 Touch targets and the institutional radii respect the design system (RNF-REF-05)',
  template.includes('min-height: 44px')
    && template.includes('--radius-card, 8px') && template.includes('--radius-interactive, 4px'));
assert('6.5 The status filter covers the eight states of the refund lifecycle',
  tab.statusOptions.length === 8 && tab.statusOptions[0].value === 'PENDING_INSPECTION'
    && tab.statusOptions[7].value === 'REJECTED');
assert('6.6 Spanish labels are used across badges, methods and findings',
  tab.compensationLabel('BIZUM') === 'Bizum'
    && tab.compensationLabel('TRANSFERENCIA_BANCARIA') === 'Transferencia bancaria'
    && tab.findingLabel('FOUND_PHYSICAL').includes('recuperado')
    && tab.custodyLabel('LEFT_AT_RECEPTION').includes('conserjería'));
assert('6.7 The Coordination dashboard registers and mounts the refunds tab',
  CoordinatorDashboardView.components?.CoordinatorRefundsTab === CoordinatorRefundsTab
    && CoordinatorDashboardView.template.includes('data-testid="tab-refunds"')
    && CoordinatorDashboardView.template.includes("activeTab === 'refunds'")
    && CoordinatorDashboardView.template.includes('💶 Reintegros'));
assert('6.8 The application root registers the dashboard that hosts the tab',
  App.components?.CoordinatorDashboardView === CoordinatorDashboardView);

assert('6.9 The refunds section is mounted only while its tab is active',
  CoordinatorDashboardView.template.includes('<CoordinatorRefundsTab')
    && CoordinatorDashboardView.template.includes('v-else-if="activeTab === \'refunds\'"')
    && CoordinatorDashboardView.data().activeTab === 'incidents');

// ---------------------------------------------------------------------
// 7. Regularization of a verdict stranded in PENDING_INSPECTION (RF-REF-04/09)
// ---------------------------------------------------------------------
console.log('\n--- 7. Regularización de dictamen atascado (RF-REF-04/09) ---');

const regTab = createTab();
regTab.items = [approvalCase, paymentCase, inspectionCase, rejectedCase];

assert('7.1 Only a case awaiting inspection can be regularized',
  regTab.canRegularize(inspectionCase) === true
    && regTab.canRegularize(approvalCase) === false
    && regTab.canRegularize(rejectedCase) === false);

regTab.openRegularizeModal(inspectionCase);
assert('7.2 Opening the modal targets the stranded case and clears the form',
  regTab.showRegularizeModal === true && regTab.selectedRefund?.id === 33
    && regTab.regularizeFinding === '' && regTab.regularizeRecoveredAmount === '');

assert('7.3 A physical finding without an amount is not submittable',
  (() => {
    regTab.setRegularizeFinding('FOUND_PHYSICAL');
    const withoutAmount = regTab.isRegularizeValid;
    regTab.regularizeRecoveredAmount = '3.50';
    return withoutAmount === false && regTab.isRegularizeValid === true;
  })());

assert('7.4 A finding without an amount clears the amount when switching verdict',
  (() => {
    regTab.setRegularizeFinding('CONFIRMED_NO_CASH');
    return regTab.regularizeRecoveredAmount === '' && regTab.isRegularizeValid === true;
  })());

assert('7.5 UNVERIFIED_NO_CASH demands a >= 20 character justification',
  (() => {
    regTab.setRegularizeFinding('UNVERIFIED_NO_CASH');
    regTab.regularizeJustification = 'corta';
    const short = regTab.isRegularizeValid;
    regTab.regularizeJustification = 'Avería cancelada por falsa alarma, sin evidencia de saldo.';
    return short === false && regTab.isRegularizeValid === true
      && regTab.needsRegularizeJustification === true;
  })());

// The submit path: a physical finding with its exact amount reaches the API.
const submitTab = createTab();
submitTab.items = [inspectionCase];
submitTab.openRegularizeModal(inspectionCase);
submitTab.setRegularizeFinding('FOUND_PHYSICAL');
submitTab.regularizeRecoveredAmount = '1.50';
submitTab.regularizeCustody = 'HELD_FOR_CENTRAL';
submitTab.regularizeJustification = 'Efectivo recuperado en el monedero durante la revisión.';
regularizeCalls = [];
await submitTab.submitRegularize();
assert('7.6 Submitting sends the verdict to the regularization endpoint',
  regularizeCalls.length === 1 && regularizeCalls[0].refundId === 33
    && regularizeCalls[0].payload.finding === 'FOUND_PHYSICAL'
    && regularizeCalls[0].payload.recoveredAmount === 1.5
    && regularizeCalls[0].payload.cashCustodyAction === 'HELD_FOR_CENTRAL');
assert('7.7 A successful regularization updates the row and closes the modal',
  submitTab.showRegularizeModal === false
    && submitTab.items[0].technician_finding === 'FOUND_PHYSICAL'
    && submitTab.items[0].status !== 'PENDING_INSPECTION');
assert('7.8 The confirmation message names the regularized claimant',
  submitTab.actionMessage.includes('Núria Puig'));

// The template must offer the action and the modal with its test hooks.
const regTemplate = CoordinatorRefundsTab.template;
assert('7.9 The row offers the regularize action only through canRegularize',
  regTemplate.includes("v-if=\"canRegularize(refund)\"")
    && regTemplate.includes("'regularize-refund-' + refund.id")
    && regTemplate.includes('Regularizar dictamen'));
assert('7.10 The modal exposes the finding, amount, custody and justification hooks',
  regTemplate.includes('data-testid="regularize-modal-body"')
    && regTemplate.includes('data-testid="regularize-finding-input"')
    && regTemplate.includes('data-testid="regularize-amount-input"')
    && regTemplate.includes('data-testid="regularize-custody-input"')
    && regTemplate.includes('data-testid="regularize-justification-input"')
    && regTemplate.includes('data-testid="regularize-submit"'));
assert('7.11 The submit button is gated on the same validation as the method',
  regTemplate.includes('isRegularizing || !isRegularizeValid'));
assert('7.12 The API client exposes regularizeRefund and posts the snake_case body',
  typeof api.coordinator.regularizeRefund === 'function');

// ---------------------------------------------------------------------
// 8. Stranded-in-inspection filter (RF-REF-09)
// ---------------------------------------------------------------------
console.log('\n--- 8. Filtro de atascados en inspección (RF-REF-09) ---');

// A case awaiting inspection whose incident is already terminal is stranded:
// the technician can no longer rule on it, so only Coordination can regularize.
const strandedCase = { ...inspectionCase, id: 41, incident_status: 'CANCELLED' };
const closedIncidentCase = { ...inspectionCase, id: 42, incident_status: 'CLOSED' };
const liveIncidentCase = { ...inspectionCase, id: 43, incident_status: 'IN_PROGRESS' };

const strTab = createTab();

assert('8.1 A pending case on a cancelled/closed incident is stranded',
  strTab.isStranded(strandedCase) === true
    && strTab.isStranded(closedIncidentCase) === true
    && strTab.isStranded(liveIncidentCase) === false);
assert('8.2 A case already ruled on is never stranded, whatever the incident says',
  strTab.isStranded({ ...approvalCase, incident_status: 'CANCELLED' }) === false
    && strTab.isStranded({ ...rejectedCase, incident_status: 'CANCELLED' }) === false);
assert('8.3 The terminal incident set is exactly CLOSED and CANCELLED',
  strTab.isTerminalIncident('CLOSED') === true
    && strTab.isTerminalIncident('CANCELLED') === true
    && strTab.isTerminalIncident('RESOLVED') === false
    && strTab.isTerminalIncident(null) === false);

assert('8.4 The shortcut is sent to the server so the definition cannot drift',
  (() => {
    const t = createTab();
    t.strandedOnly = true;
    return t.buildFilters().stranded_only === 1;
  })());
assert('8.5 The shortcut is absent when inactive',
  createTab().buildFilters().stranded_only === undefined);

// Turning the stranded shortcut on must release the other, incompatible one:
// a case awaiting approval is not stranded, and vice versa.
listCalls = [];
const toggleTab = createTab();
toggleTab.approvalOnly = true;
toggleTab.statusFilter = 'PAID_DIGITAL';
toggleTab.toggleStrandedOnly(true);
assert('8.6 Enabling the stranded shortcut clears the approval one and the status filter',
  toggleTab.strandedOnly === true && toggleTab.approvalOnly === false
    && toggleTab.statusFilter === '');
assert('8.7 It reloads the inbox with the server-side shortcut',
  listCalls.length === 1 && listCalls[0].stranded_only === 1
    && listCalls[0].requires_approval_only === undefined);

assert('8.8 The counter is read from the server response',
  (() => {
    const t = createTab();
    t.strandedTotal = 0;
    return t.hasStranded === false;
  })());

const strTemplate = CoordinatorRefundsTab.template;
assert('8.9 The toolbar offers the stranded filter with its test hook',
  strTemplate.includes('data-testid="refund-stranded-filter"')
    && strTemplate.includes('Solo atascados en inspección')
    && strTemplate.includes('toggleStrandedOnly($event.target.checked)'));
assert('8.10 The header exposes a clickable stranded counter',
  strTemplate.includes('data-testid="stranded-counter"')
    && strTemplate.includes('Atascados en inspección')
    && strTemplate.includes('toggleStrandedOnly(true)'));
assert('8.11 A stranded row is flagged visually',
  strTemplate.includes('data-testid="stranded-flag"')
    && strTemplate.includes('v-if="isStranded(refund)"'));

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-REF-18 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
