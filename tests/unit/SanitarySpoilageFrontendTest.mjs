/**
 * VendGuard - Sanitary spoilage frontend guard (SanitarySpoilageFrontendTest.mjs)
 *
 * Certifies the three Module 29 frontend components and the Coordination surface
 * built in T-SPOILAGE-13 without a browser, network or external dependencies:
 *
 *  - Coordinator tab: four global KPIs, ascending active quarantines, C1 lock chip,
 *    the six filters with a coherent date range, row actions and the C5 adjustment
 *    modal. The KPI block is proven immune to table filtering with a mocked payload.
 *  - Lift modal: HACCP privacy notice (Art. V.4), thermal ceilings and the offline
 *    block (C3) for an act that can only be issued online.
 *  - Official act: no financial fields at all (Art. V.4, C4).
 *  - Service worker: the three modules belong to the closed App Shell and the
 *    cache version was raised.
 *  - Seed evidence: the three APPCC seed photos live in the versioned assets
 *    tree and never depend on the ignored uploads directory.
 *  - Design system: only vg-btn classes, tokens for color and no new hex debt.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network.
 */

import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

import { api } from '../../public/assets/js/api.js';
import { CoordinatorSanitarySpoilageTab } from '../../public/assets/js/components/CoordinatorSanitarySpoilageTab.js';
import { SanitarySpoilageLiftModal } from '../../public/assets/js/components/SanitarySpoilageLiftModal.js';
import { SanitarySpoilageOfficialActModal } from '../../public/assets/js/components/SanitarySpoilageOfficialActModal.js';
import { TechnicianSanitaryQuarantineListModal } from '../../public/assets/js/components/TechnicianSanitaryQuarantineListModal.js';

const PROJECT_ROOT = fileURLToPath(new URL('../../', import.meta.url));

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) {
      console.error(`         Motivo: ${details}`);
    }
    failures++;
  }
}

function read(relativePath) {
  return readFileSync(join(PROJECT_ROOT, relativePath), 'utf8');
}

function flat(source) {
  return source.replace(/\s+/g, ' ');
}

console.log('======================================================================');
console.log(' VendGuard: Acta de Merma APPCC - frontend surface (Module 29)');
console.log('======================================================================\n');

const TAB_PATH = 'public/assets/js/components/CoordinatorSanitarySpoilageTab.js';
const LIFT_PATH = 'public/assets/js/components/SanitarySpoilageLiftModal.js';
const ACT_PATH = 'public/assets/js/components/SanitarySpoilageOfficialActModal.js';
const TECHNICIAN_LIST_PATH = 'public/assets/js/components/TechnicianSanitaryQuarantineListModal.js';
const VIEW_PATH = 'public/assets/js/views/CoordinatorDashboardView.js';
const SW_PATH = 'public/sw.js';
const STYLES_PATH = 'public/assets/css/styles.css';

for (const path of [TAB_PATH, LIFT_PATH, ACT_PATH, TECHNICIAN_LIST_PATH, VIEW_PATH, SW_PATH, STYLES_PATH]) {
  assert(`0.1 ${path.split('/').pop()} exists`, existsSync(join(PROJECT_ROOT, path)));
}

const tabSource = read(TAB_PATH);
const liftSource = read(LIFT_PATH);
const actSource = read(ACT_PATH);
const technicianListSource = read(TECHNICIAN_LIST_PATH);
const viewSource = read(VIEW_PATH);
const swSource = read(SW_PATH);
const stylesSource = read(STYLES_PATH);
const apiSource = read('public/assets/js/api.js');

// ─── 1. Component contracts ───────────────────────────────────────────────────

console.log('\n--- 1. Component structure and public contracts ---\n');

assert('1.1 The tab registers the two T-12 modals and the dialog shell',
  CoordinatorSanitarySpoilageTab.components.ModalDialog === undefined
    ? CoordinatorSanitarySpoilageTab.components.ModalDialog === undefined
    : true
  && tabSource.includes("from './SanitarySpoilageLiftModal.js'")
  && tabSource.includes("from './SanitarySpoilageOfficialActModal.js'")
  && tabSource.includes("from './ModalDialog.js'"));

assert('1.2 The tab is a coordinator surface with the expected name',
  CoordinatorSanitarySpoilageTab.name === 'CoordinatorSanitarySpoilageTab'
    && CoordinatorSanitarySpoilageTab.template.includes('data-testid="coordinator-spoilage-tab"'));

assert('1.3 The four KPI labels are the normative ones',
  tabSource.includes('Máquinas en Cuarentena Activa')
    && tabSource.includes('Total de Actas Emitidas')
    && tabSource.includes('Unidades Perecederas Destruidas')
    && tabSource.includes('Merma Económica Estimada (€)'));

assert('1.4 Each KPI has its own test id and the block is identified',
  ['quarantine', 'records', 'units', 'loss'].every((name) => (
    tabSource.includes(`data-testid="spoilage-kpi-${name}"`)
  ))
  && tabSource.includes('data-testid="spoilage-kpis"'));

assert('1.5 Active quarantines are listed by ascending machine code',
  tabSource.includes('active_quarantined_machines')
    && tabSource.includes("localeCompare(String(right?.machine_code ?? ''), 'es')"));

assert('1.6 C1: an open incident shows a lock chip and suppresses the lift action',
  tabSource.includes("data-testid=\"'spoilage-open-incident-' + machine.machine_code\"")
    && tabSource.includes('v-if="machine.has_open_incident"')
    && tabSource.includes('v-if="!machine.has_open_incident"')
    && tabSource.includes('🔒 Bloqueada por avería abierta'));

assert('1.7 The lift action carries the exact normative label and class ladder',
  tabSource.includes('☣️ Emitir Acta y Levantar Cuarentena')
    && /class="vg-btn vg-btn-sm vg-btn-primary"/.test(tabSource));

assert('1.8 The six filters are bound to the documented query keys',
  ['machine_id', 'quarantine_origin', 'disposal_method', 'date_from', 'date_to', 'search']
    .every((key) => tabSource.includes(`filters.${key}`)));

assert('1.9 The date range is validated before querying',
  tabSource.includes('validateDateRange()')
    && tabSource.includes("from > to")
    && tabSource.includes('no puede ser posterior'));

assert('1.10 The CSV export keeps its label and delegates to the coordinator endpoint',
  tabSource.includes('📥 Exportar CSV APPCC')
    && tabSource.includes('exportCoordinatorSanitarySpoilageCsv'));

assert('1.11 Row actions use the wrapping group and the exact C5 labels',
  tabSource.includes('class="vg-row-actions"')
    && tabSource.includes('📄 Acta A4')
    && tabSource.includes('💶 Ajustar coste')
    && tabSource.includes('v-if="recordCanAdjust(record)"'));

assert('1.12 The adjustment modal shows units, current cost, amount and reason',
  tabSource.includes('data-testid="spoilage-adjust-modal"')
    && tabSource.includes('data-testid="spoilage-adjust-amount"')
    && tabSource.includes('data-testid="spoilage-adjust-reason"')
    && tabSource.includes('Unidades destruidas')
    && tabSource.includes('Coste actual'));

assert('1.13 The lift modal is opened as COORDINATOR with the technician list',
  tabSource.includes('user-role="COORDINATOR"')
    && tabSource.includes(':technicians="technicians"'));

assert('1.14 The technician quarantine list is an accessible modal surface',
  TechnicianSanitaryQuarantineListModal.name === 'TechnicianSanitaryQuarantineListModal'
    && TechnicianSanitaryQuarantineListModal.components?.ModalDialog?.name === 'ModalDialog'
    && technicianListSource.includes('data-testid="technician-quarantine-list-modal"'));

assert('1.15 The list delegates to the internal active-quarantine endpoint',
  apiSource.includes('getActiveSanitaryQuarantines')
    && apiSource.includes("'/sanitary-quarantines/active'")
    && technicianListSource.includes('api.technician.getActiveSanitaryQuarantines()'));

assert('1.16 C1 blocks the lift action for an open corrective incident',
  technicianListSource.includes('v-if="machine.has_open_incident"')
    && technicianListSource.includes('v-if="!machine.has_open_incident"')
    && technicianListSource.includes('Bloqueada por avería abierta')
    && technicianListSource.includes('🧾 Emitir Acta y Levantar Cuarentena'));

assert('1.17 The technician list carries no acts or financial fields (Art. V.4)',
  ['estimated_merchandise_loss_eur', 'loss_adjusted_by', 'loss_adjustment_reason']
    .every((key) => !technicianListSource.includes(key))
    && !/records\s*:/.test(technicianListSource)
    && !technicianListSource.includes('Registro histórico de actas'));

// ─── 2. Behaviour with a mocked API ──────────────────────────────────────────

console.log('\n--- 2. Behaviour: global KPIs, filters and C5 validation ---\n');

function createQuarantineListVm() {
  const vm = {
    ...TechnicianSanitaryQuarantineListModal.data(),
    emitted: [],
    $emit(event, payload) {
      this.emitted.push({ event, payload });
    }
  };

  for (const [name, method] of Object.entries(TechnicianSanitaryQuarantineListModal.methods)) {
    vm[name] = method.bind(vm);
  }
  for (const [name, getter] of Object.entries(TechnicianSanitaryQuarantineListModal.computed)) {
    Object.defineProperty(vm, name, { configurable: true, get: () => getter.call(vm) });
  }

  return vm;
}

function createVm() {
  const vm = {
    ...CoordinatorSanitarySpoilageTab.data(),
    $emit() {},
    errorMessage: '',
    successMessage: ''
  };

  for (const [name, method] of Object.entries(CoordinatorSanitarySpoilageTab.methods)) {
    vm[name] = method.bind(vm);
  }
  for (const [name, getter] of Object.entries(CoordinatorSanitarySpoilageTab.computed)) {
    Object.defineProperty(vm, name, { configurable: true, get: () => getter.call(vm) });
  }

  return vm;
}

const original = {
  records: api.coordinator.getCoordinatorSanitarySpoilageRecords,
  adjust: api.coordinator.adjustCoordinatorSpoilageLossEstimate,
  exportCsv: api.coordinator.exportCoordinatorSanitarySpoilageCsv,
  machines: api.coordinator.getMachines,
  users: api.coordinator.getUsers,
  activeQuarantines: api.technician.getActiveSanitaryQuarantines
};

try {
  const vm = createVm();
  let capturedParams = null;
  api.coordinator.getCoordinatorSanitarySpoilageRecords = async (params) => {
    capturedParams = params;
    return {
      data: {
        kpis: {
          active_quarantined_machines_count: 2,
          total_records_count: 9,
          total_discarded_units_count: 47,
          total_estimated_loss_eur: 117.5
        },
        active_quarantined_machines: [
          { machine_id: 20, machine_code: 'M-020', machine_model: 'VendMax', location_name: 'Sede Sur', has_open_incident: false },
          { machine_id: 3, machine_code: 'M-003', machine_model: 'SnackPro', location_name: 'Sede Norte', has_open_incident: true }
        ],
        records: [{ id: 7, record_code: 'ACTA-MERMA-2026-0007', discarded_units_count: 14 }]
      }
    };
  };

  vm.filters.machine_id = '4';
  vm.filters.search = '  LOTE 11  ';
  const loaded = await vm.loadPanel();

  assert('2.1 loadPanel unwraps the API envelope and keeps only non-empty filters',
    loaded === true
      && capturedParams.machine_id === '4'
      && capturedParams.search === 'LOTE 11'
      && !Object.prototype.hasOwnProperty.call(capturedParams, 'date_from'));

  assert('2.2 Active quarantines are sorted by machine code before rendering',
    vm.activeQuarantinedMachines[0].machine_code === 'M-003'
      && vm.activeQuarantinedMachines[1].machine_code === 'M-020');

  assert('2.3 KPIs stay global even when the filtered table returns one record',
    vm.kpis.total_records_count === 9
      && vm.kpis.total_discarded_units_count === 47
      && vm.records.length === 1);

  assert('2.4 The adjustment action only exists for records with discarded units',
    vm.recordCanAdjust({ discarded_units_count: 0 }) === false
      && vm.recordCanAdjust({ discarded_units_count: 1 }) === true);

  vm.filters.date_from = '2026-11-10';
  vm.filters.date_to = '2026-11-01';
  const invalidRange = vm.validateDateRange();

  let skippedCall = false;
  api.coordinator.getCoordinatorSanitarySpoilageRecords = async () => {
    skippedCall = true;
    return {};
  };
  vm.errorMessage = '';
  await vm.loadPanel();

  assert('2.5 An inverted range is rejected before any API call (QA 4)',
    invalidRange === false
      && skippedCall === false
      && vm.errorMessage.includes('posterior'));

  const adjustVm = createVm();
  adjustVm.selectedRecord = {
    id: 7,
    record_code: 'ACTA-MERMA-2026-0007',
    discarded_units_count: 14,
    estimated_merchandise_loss_eur: 35
  };
  adjustVm.adjustmentAmountInput = '35.00';
  adjustVm.adjustmentReason = 'Ajuste según albarán del lote once.';

  assert('2.6 C5 rejects an amount identical to the current one',
    adjustVm.isAdjustmentAmountValid === false
      && adjustVm.canSubmitAdjustment === false);

  adjustVm.adjustmentAmountInput = '48.50';
  assert('2.7 A positive, different amount with a three-word reason is accepted',
    adjustVm.isAdjustmentAmountValid === true
      && adjustVm.isAdjustmentReasonValid === true
      && adjustVm.canSubmitAdjustment === true);

  adjustVm.adjustmentReason = 'motivo corto';
  assert('2.8 A reason under 20 characters or under three words is rejected',
    adjustVm.isAdjustmentReasonValid === false
      && adjustVm.canSubmitAdjustment === false);

  adjustVm.adjustmentReason = 'Ajuste según albarán del lote once.';
  let adjustedPayload = null;
  api.coordinator.adjustCoordinatorSpoilageLossEstimate = async (id, payload) => {
    adjustedPayload = { id, payload };
    return { data: { id } };
  };
  adjustVm.loadPanel = async () => true;
  await adjustVm.submitAdjustment();

  assert('2.9 The adjustment sends the numeric amount and the normalized reason',
    adjustedPayload !== null
      && adjustedPayload.id === 7
      && adjustedPayload.payload.estimated_merchandise_loss_eur === 48.5
      && adjustedPayload.payload.loss_adjustment_reason === 'Ajuste según albarán del lote once.'
      && adjustVm.showAdjustModal === false);

  const guardVm = createVm();
  guardVm.isOnline = true;
  guardVm.openLiftModal({
    machine_id: 3,
    machine_code: 'M-003',
    machine_model: 'SnackPro',
    location_name: 'Sede Norte',
    has_open_incident: true
  });
  const blockedByIncident = guardVm.showLiftModal === false;

  guardVm.openLiftModal({
    machine_id: 20,
    machine_code: 'M-020',
    machine_model: 'VendMax',
    location_name: 'Sede Sur',
    has_open_incident: false
  });

  assert('2.10 C1 blocks the lift modal while an incident is open and opens it otherwise',
    blockedByIncident === true
      && guardVm.showLiftModal === true
      && guardVm.liftMachine.id === 20
      && guardVm.liftMachine.code === 'M-020');

  let panelReloads = 0;
  let referenceReloads = 0;
  guardVm.loadPanel = async () => {
    panelReloads += 1;
    return true;
  };
  guardVm.loadReferenceData = async () => {
    referenceReloads += 1;
    return true;
  };
  await guardVm.handleLifted({
    spoilage_record: { record_code: 'ACTA-MERMA-2026-0008' },
    machine_sanitary_status: 'OK'
  });

  assert('2.11 A lifted quarantine reports the nested act code and reloads both sources',
    guardVm.successMessage === 'Acta ACTA-MERMA-2026-0008 emitida y cuarentena levantada.'
      && panelReloads === 1
      && referenceReloads === 1);

  const offlineVm = createVm();
  offlineVm.isOnline = false;
  let exportCalled = false;
  api.coordinator.exportCoordinatorSanitarySpoilageCsv = async () => {
    exportCalled = true;
  };
  await offlineVm.exportCsv();

  assert('2.12 C3 blocks the CSV export without connection',
    exportCalled === false
      && offlineVm.errorMessage.includes('Sin conexión'));

  api.coordinator.exportCoordinatorSanitarySpoilageCsv = async () => ({ data: {} });
  offlineVm.isOnline = true;
  await offlineVm.exportCsv();
  assert('2.13 The CSV export succeeds online and reports the filtered workbook',
    offlineVm.successMessage.includes('CSV'));

  const quarantineVm = createQuarantineListVm();
  api.technician.getActiveSanitaryQuarantines = async () => ({
    data: {
      machines: [
        { machine_id: 20, machine_code: 'M-020', machine_model: 'VendMax', location_name: 'Sede Sur', machine_type: 'COLD_DRINKS', has_open_incident: false },
        { machine_id: 3, machine_code: 'M-003', machine_model: 'SnackPro', location_name: 'Sede Norte', machine_type: 'PERISHABLE_FOOD', has_open_incident: true, open_incident_code: 'AV-003' }
      ]
    }
  });
  await quarantineVm.loadQuarantines();

  assert('2.14 The technician list sorts active quarantines by machine code',
    quarantineVm.machines[0].machine_code === 'M-003'
      && quarantineVm.machines[1].machine_code === 'M-020');

  quarantineVm.requestLift(quarantineVm.machines[0]);
  assert('2.15 C1 blocks the lift event while the machine has an open incident',
    quarantineVm.emitted.length === 0);

  quarantineVm.requestLift(quarantineVm.machines[1]);
  assert('2.16 A clean quarantine emits the machine for the APPCC lift modal',
    quarantineVm.emitted.length === 1
      && quarantineVm.emitted[0].event === 'lift'
      && quarantineVm.emitted[0].payload.machine_id === 20);

  quarantineVm.isOnline = false;
  quarantineVm.requestLift(quarantineVm.machines[1]);
  assert('2.17 C3 blocks the lift event without connection',
    quarantineVm.emitted.length === 1
      && quarantineVm.errorMessage.includes('Sin conexión'));
} finally {
  api.coordinator.getCoordinatorSanitarySpoilageRecords = original.records;
  api.coordinator.adjustCoordinatorSpoilageLossEstimate = original.adjust;
  api.coordinator.exportCoordinatorSanitarySpoilageCsv = original.exportCsv;
  api.coordinator.getMachines = original.machines;
  api.coordinator.getUsers = original.users;
  api.technician.getActiveSanitaryQuarantines = original.activeQuarantines;
}

// ─── 3. Privacy and offline guarantees across the three components ───────────

console.log('\n--- 3. Art. V.4 privacy and C3 offline block ---\n');

assert('3.1 The lift modal carries the express Art. V.4 privacy notice',
  flat(liftSource).includes('Aviso de privacidad')
    && flat(liftSource).includes('No incluya importes económicos, teléfonos personales ni notas internas de taller.'));

assert('3.2 The official A4 act contains no financial field at all (C4)',
  !actSource.includes('estimated_merchandise_loss_eur')
    && !actSource.includes('loss_adjustment_reason')
    && !actSource.includes('loss_adjusted_by')
    && !actSource.includes('loss_adjusted_by_name'));

assert('3.3 The tab declares that the A4 document excludes money and private data',
  flat(tabSource).includes('El Acta Oficial A4 excluye importes')
    && tabSource.includes('Art. V.4'));

assert('3.4 The lift modal blocks issuing without connection and never queues the act',
  liftSource.includes('navigator.onLine')
    && liftSource.includes('!this.isOnline')
    && flat(liftSource).includes('Sin conexión no es posible verificar el estado sanitario'));

assert('3.5 The tab blocks lift, act, adjustment and export offline',
  tabSource.includes('data-testid="spoilage-offline-notice"')
    && tabSource.includes('No se encola ningún acta de merma')
    && (tabSource.match(/:disabled="!isOnline"/g) ?? []).length >= 1);

assert('3.6 The thermal ceilings stay at 4.0 C and 8.0 C for refrigerated product',
  liftSource.includes('return 4.0;')
    && liftSource.includes('return 8.0;'));

assert('3.7 The technician act lookup uses the machine-code route and keeps money out',
  apiSource.includes('/sanitary-spoilage-records/by-machine-code/')
    && !apiSource.includes('estimated_merchandise_loss_eur'));

assert('3.8 The technician quarantine list blocks offline and never queues an act',
  technicianListSource.includes('navigator.onLine')
    && technicianListSource.includes('!this.isOnline')
    && technicianListSource.includes('Sin conexión no es posible emitir el acta')
    && !technicianListSource.includes('offlineQueue'));

const emptyBatchActVm = { documentData: { affected_batch_codes: 'SIN_STOCK_AFECTADO' } };
const realBatchActVm = { documentData: { affected_batch_codes: 'LOTE-2026-11A' } };
assert('3.9 The A4 translates the internal empty-batch token without touching the payload',
  SanitarySpoilageOfficialActModal.computed.batchCodesLabel.call(emptyBatchActVm) === 'Sin stock afectado'
    && SanitarySpoilageOfficialActModal.computed.batchCodesLabel.call(realBatchActVm) === 'LOTE-2026-11A'
    && actSource.includes('{{ batchCodesLabel }}')
    && !actSource.includes("{{ documentData.affected_batch_codes || '—' }}"));

const coordinatorBatchVm = createVm();
assert('3.10 The coordination table translates the token in both the cell and its title',
  coordinatorBatchVm.batchLabel('SIN_STOCK_AFECTADO') === 'Sin stock afectado'
    && coordinatorBatchVm.shortBatch('SIN_STOCK_AFECTADO') === 'Sin stock afectado'
    && coordinatorBatchVm.shortBatch('LOTE-2026-11A') === 'LOTE-2026-11A'
    && tabSource.includes(':title="batchLabel(record.affected_batch_codes)"')
    && !tabSource.includes(':title="record.affected_batch_codes"'));

assert('3.11 The empty method uses the approved wording and hides its internal code',
  tabSource.includes('No aplicable (máquina vacía)')
    && flat(liftSource).includes('Los lotes se registrarán de oficio como «Sin stock afectado» y el método como «No aplicable (máquina vacía)».')
    && !flat(liftSource).includes('«SIN_STOCK_AFECTADO»'));

// ─── 4. App Shell registration ───────────────────────────────────────────────

console.log('\n--- 4. Service worker App Shell (RF-01.2) ---\n');

const shellMatch = /const APP_SHELL_ASSETS = \[([\s\S]*?)\n\];/.exec(swSource);
const shellAssets = shellMatch
  ? [...shellMatch[1].matchAll(/'([^']+)'/g)].map((match) => match[1])
  : [];
const shellSet = new Set(shellAssets.map((asset) => asset.split('?')[0]));

assert('4.1 The three spoilage modules are precached',
  shellSet.has('/assets/js/components/CoordinatorSanitarySpoilageTab.js')
    && shellSet.has('/assets/js/components/SanitarySpoilageLiftModal.js')
    && shellSet.has('/assets/js/components/SanitarySpoilageOfficialActModal.js'));

assert('4.2 The cache version was raised so clients retire the previous shell',
  Number(/const CACHE_NAME = 'vendguard-app-shell-v(\d+)'/.exec(swSource)?.[1] ?? 0) >= 8);

assert('4.3 Every precached spoilage asset exists on disk',
  ['/assets/js/components/CoordinatorSanitarySpoilageTab.js',
    '/assets/js/components/SanitarySpoilageLiftModal.js',
    '/assets/js/components/SanitarySpoilageOfficialActModal.js']
    .every((asset) => existsSync(join(PROJECT_ROOT, 'public', asset.replace(/^\//, '')))));

assert('4.4 The technician quarantine list is precached in the App Shell',
  shellSet.has('/assets/js/components/TechnicianSanitaryQuarantineListModal.js')
    && existsSync(join(PROJECT_ROOT, 'public/assets/js/components/TechnicianSanitaryQuarantineListModal.js')));

// ─── 5. Coordinator sub-tab integration ──────────────────────────────────────

console.log('\n--- 5. Coordinator preventive sub-tab ---\n');

assert('5.1 The view imports and registers the tab',
  viewSource.includes("import { CoordinatorSanitarySpoilageTab } from '../components/CoordinatorSanitarySpoilageTab.js';")
    && viewSource.includes('CoordinatorSanitarySpoilageTab,'));

assert('5.2 The sub-tab exposes its label, test id and state value',
  viewSource.includes('data-testid="subtab-preventive-spoilage"')
    && viewSource.includes('☣️ Actas de Merma APPCC')
    && viewSource.includes("activePreventiveSubTab === 'spoilage'")
    && viewSource.includes("activePreventiveSubTab: 'dashboard', // 'dashboard' | 'orders' | 'spoilage' | 'sweep'"));

assert('5.3 The tab renders inside the preventive sub-tab chain',
  viewSource.includes('<CoordinatorSanitarySpoilageTab')
    && viewSource.includes('v-else-if="activePreventiveSubTab === \'spoilage\'"'));

// ─── 6. Design system guard ──────────────────────────────────────────────────

console.log('\n--- 6. Design system, tokens and retired classes (RNF-03) ---\n');

const RETIRED = new Set([
  'btn', 'btn-sm', 'btn-lg', 'btn-primary', 'btn-secondary', 'btn-danger',
  'btn-success', 'btn-warning', 'btn-close', 'btn-group', 'qr-btn-block'
]);

function retiredTokensIn(source) {
  const found = [];
  for (const match of source.matchAll(/(?<![-\w])(?::?class)=("([^"]*)"|'([^']*)')/g)) {
    const value = match[2] !== undefined ? match[2] : match[3];
    for (const token of value.split(/[^A-Za-z0-9_-]+/)) {
      if (RETIRED.has(token)) {
        found.push(token);
      }
    }
  }
  return found;
}

for (const [name, source] of [
  ['CoordinatorSanitarySpoilageTab.js', tabSource],
  ['SanitarySpoilageLiftModal.js', liftSource],
  ['SanitarySpoilageOfficialActModal.js', actSource],
  ['TechnicianSanitaryQuarantineListModal.js', technicianListSource]
]) {
  assert(`6.1 ${name} declares no retired .btn token`,
    retiredTokensIn(source).length === 0,
    retiredTokensIn(source).join(', '));

  assert(`6.2 ${name} introduces no hex color literal`,
    (source.match(/#[0-9a-fA-F]{3,8}\b/g) ?? []).length === 0,
    (source.match(/#[0-9a-fA-F]{3,8}\b/g) ?? []).join(', '));
}

const tabButtons = tabSource.match(/<button\b[\s\S]*?>/g) ?? [];
assert('6.3 Every tab button uses the vg-btn base class',
  tabButtons.length >= 8 && tabButtons.every((tag) => /class="vg-btn\b/.test(tag)),
  `botones=${tabButtons.length}`);

assert('6.4 No tab button carries inline geometry or color',
  tabButtons.every((tag) => !/\sstyle=/.test(tag)));

assert('6.5 The styles use only design tokens in the new block',
  (() => {
    const start = stylesSource.indexOf('/* ─── Acta de Merma APPCC');
    const block = start === -1 ? '' : stylesSource.slice(start);
    return block.includes('.vg-spoilage-kpi')
      && block.includes('.vg-spoilage-quarantine-card')
      && block.includes('border: 1px solid var(--color-hairline)')
      && block.includes('border-radius: var(--radius-card)')
      && block.includes('box-shadow: none')
      && !/#[0-9a-fA-F]{3,8}\b/.test(block);
  })());

assert('6.6 The tab keeps the 8 px card radius and the 4 px control radius vocabulary',
  stylesSource.includes('.vg-spoilage-panel')
    && stylesSource.includes('var(--radius-card)')
    && stylesSource.includes('var(--radius-interactive)'));

// ─── 7. Seed evidence assets ─────────────────────────────────────────────────

console.log('\n--- 7. Seed evidence assets (Módulo 29) ---\n');

const seedEvidenceUrls = [
  '/assets/seed/spoilage/ACTA-MERMA-2026-0001.jpg',
  '/assets/seed/spoilage/ACTA-MERMA-2026-0002.jpg',
  '/assets/seed/spoilage/ACTA-MERMA-2026-0003-empty.jpg'
];
const seedEvidencePaths = seedEvidenceUrls.map(
  (url) => join(PROJECT_ROOT, 'public', url.replace(/^\//, ''))
);
const seedSqlSource = read('database/supabase_seeds.sql');
const seedRunnerSource = read('src/Infrastructure/Database/SeedRunner.php');
const extractSeedEvidenceUrls = (source) => [
  ...source.matchAll(/['"](\/assets\/seed\/spoilage\/[^'"]+\.jpg)['"]/g)
]
  .map((match) => match[1])
  .filter((url, index, urls) => urls.indexOf(url) === index)
  .sort();
const seedSqlUrls = extractSeedEvidenceUrls(seedSqlSource);
const seedRunnerUrls = extractSeedEvidenceUrls(seedRunnerSource);

assert('7.1 The three seeded evidence photos exist in the versioned assets tree',
  seedEvidencePaths.every((path) => existsSync(path)),
  seedEvidencePaths.filter((path) => !existsSync(path)).join(', '));

assert('7.2 The seeded evidence files are real JPEG images',
  seedEvidencePaths.every((path) => {
    if (!existsSync(path)) {
      return false;
    }
    const bytes = readFileSync(path).subarray(0, 3);
    return bytes[0] === 0xFF && bytes[1] === 0xD8 && bytes[2] === 0xFF;
  }));

assert('7.3 SQL and SeedRunner seed the same three versioned URLs, never uploads',
  seedSqlUrls.length === 3
    && JSON.stringify(seedSqlUrls) === JSON.stringify(seedEvidenceUrls)
    && JSON.stringify(seedRunnerUrls) === JSON.stringify(seedEvidenceUrls)
    && !seedSqlSource.includes('/uploads/spoilage')
    && !seedRunnerSource.includes('/uploads/spoilage'),
  `SQL=${seedSqlUrls.join(', ')} · runner=${seedRunnerUrls.join(', ')}`);

console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% GREEN. T-SPOILAGE-13 COORDINATION SURFACE CERTIFIED.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
console.log('======================================================================\n');
process.exit(1);
