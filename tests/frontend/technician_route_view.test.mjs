/**
 * VendGuard - Frontend Test Suite (technician_route_view.test.mjs)
 *
 * Certifica la integración EVA-DTS `.dex` de la vista móvil del técnico
 * (Módulo 39, T-DEX-12 · RF-03.2, RF-04.3, RF-05.2, RF-06.1, RNF-03,
 * D1, D2, D4, D5, D6, QA-1, QA-3, Art. II y Art. V.4):
 * - pre-filtro offline y proyección en unidades, sin euros ni CA3;
 * - importación online y cola FIFO offline por técnico;
 * - historial y alerta térmica con apertura M16 en un toque;
 * - vínculo `dex_audit_id` en el alta oportunista y en su payload;
 * - volcado de conflictos sin borrar la evidencia.
 *
 * Dogma Vanilla: runner ES Module puro sin dependencias externas.
 */

const storageMock = new Map();
globalThis.localStorage = {
  getItem: (k) => storageMock.get(k) ?? null,
  setItem: (k, v) => storageMock.set(k, String(v)),
  removeItem: (k) => storageMock.delete(k),
  clear: () => storageMock.clear()
};
globalThis.sessionStorage = { ...globalThis.localStorage };

import { readFileSync } from 'node:fs';
import { api } from '../../public/assets/js/api.js';
import { TechnicianRouteView } from '../../public/assets/js/views/TechnicianRouteView.js';
import { TechnicianOpportunisticModal } from '../../public/assets/js/components/TechnicianOpportunisticModal.js';

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - TechnicianRouteView (T-DEX-12)');
console.log('======================================================================\n');

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.log(`  [FAIL] ${description}`);
    if (details) console.log(`         ${details}`);
    failures++;
  }
}

function buildViewModel(definition, overrides = {}) {
  const vm = {
    ...definition.data(),
    ...definition.methods
  };
  for (const [name, getter] of Object.entries(definition.computed || {})) {
    Object.defineProperty(vm, name, { get: getter, configurable: true });
  }
  // Las dependencias de test (usuario, red, guardas) mandan sobre los getters.
  for (const [name, value] of Object.entries(overrides)) {
    Object.defineProperty(vm, name, { value, writable: true, configurable: true, enumerable: true });
  }
  return vm;
}

const viewSource = readFileSync(
  new URL('../../public/assets/js/views/TechnicianRouteView.js', import.meta.url),
  'utf8'
);
const apiSource = readFileSync(new URL('../../public/assets/js/api.js', import.meta.url), 'utf8');

const dexRaw = 'DXS*COMM-DEX12*VA*DEX 6.0\nID4*2\nVA1*2550*41\nEA2*EF01*1*1*0*1\nDXE*FIN';
const dexFile = {
  name: 'tecnico.dex',
  size: new TextEncoder().encode(dexRaw).length,
  type: 'text/plain',
  text: async () => dexRaw
};

// ─────────────────────────────────────────────────────────────────────────────
// 1. Contrato del cliente y plantilla
// ─────────────────────────────────────────────────────────────────────────────
assert(
  'api.technician publica import/list/dump de .dex',
  typeof api.technician.importDexAudit === 'function'
    && typeof api.technician.getDexAudits === 'function'
    && typeof api.technician.dumpDexAuditConflict === 'function'
);
assert(
  'Los endpoints del técnico son los contractuales',
  apiSource.includes('/technician/machines/${encodeURIComponent(machineId)}/dex-audits')
    && apiSource.includes("'/technician/dex-audits/conflict-dump'")
);
assert(
  'La tarjeta de parada abre el modal .dex',
  viewSource.includes('data-testid="btn-open-dex-audit"') && viewSource.includes('openDexModal(incident)')
);
assert(
  'El modal declara selector, historial, alerta térmica y cola offline',
  viewSource.includes('data-testid="technician-dex-file"')
    && viewSource.includes('data-testid="technician-dex-history"')
    && viewSource.includes('data-testid="technician-dex-thermal-banner"')
    && viewSource.includes('data-testid="technician-dex-queue"')
);
assert(
  'El selector .dex es un disparador legible y anuncia el estado vacío',
  viewSource.includes('data-testid="technician-dex-file-trigger"')
    && viewSource.includes('for="technician-dex-file-input"')
    && viewSource.includes("'Ningún archivo seleccionado'")
    && /id="technician-dex-file-input"[\s\S]{0,220}class="visually-hidden"/.test(viewSource)
);
assert(
  'La vista del técnico no publica el prefill económico ni CA3',
  !viewSource.includes('va1_total_sales_eur')
    && !viewSource.includes('ca3_')
    && !viewSource.includes('prefill_sales_counter_reading_eur')
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Pre-filtro y proyección por rol
// ─────────────────────────────────────────────────────────────────────────────
const originalImport = api.technician.importDexAudit;
const originalList = api.technician.getDexAudits;
let importPayload = null;
api.technician.getDexAudits = async () => ({
  data: {
    audits: [{
      id: 500,
      audit_code: 'DEX-500',
      has_thermal_alert: false,
      va1_total_vend_units: 30,
      delta_vend_units: 4,
      events: []
    }]
  }
});
api.technician.importDexAudit = async (machineId, payload) => {
  importPayload = { machineId, payload };
  return {
    data: {
      audit: {
        id: 902,
        audit_code: 'DEX-902',
        has_thermal_alert: true,
        va1_total_vend_units: 41,
        delta_vend_units: 41,
        events: [{ event_code: 'EF01', severity: 'CRITICAL', event_category: 'PRODUCT_JAM' }]
      },
      has_thermal_alert: true,
      thermal_alert_enqueued: true,
      suggested_escalation_payload: {
        action: 'OPEN_OPPORTUNISTIC_INCIDENT',
        breakdown_category: 'PRODUCT_JAM',
        internal_note: '[DEX DEX-902] Códigos EA1/EA2: EF01',
        dex_audit_id: 902,
        is_internal: true
      }
    }
  };
};

const incident = {
  id: 44,
  ticket_code: 'INC-0044',
  machine: { id: 7, code: 'VEND-0701' },
  location: { id: 1, name: 'Hospital del Mar' }
};
const vm = buildViewModel(TechnicianRouteView, {
  currentUser: { id: 6, name: 'Jordi Ruta', role: 'TECHNICIAN' },
  isBrowserOffline: () => false,
  enabledLocations: [{ location_id: 1, location_name: 'Hospital del Mar' }],
  guardOnlineOnly: () => true
});

await vm.openDexModal(incident);
assert('openDexModal fija la parada y carga el historial', vm.showDexModal === true && vm.dexHistory.length === 1);
assert(
  'El historial se publica en unidades y delta de unidades',
  vm.dexAuditUnits(vm.dexHistory[0]) === 30 && vm.dexAuditDeltaUnits(vm.dexHistory[0]) === 4
);

const formattedDexCapturedAt = vm.formatDateTime('2026-11-25 13:06:05+00');
assert(
  'La fecha del historial .dex se localiza y no expone la zona UTC',
  /^\d{2}\/\d{2}\/\d{2}, \d{2}:\d{2}$/.test(formattedDexCapturedAt)
    && !formattedDexCapturedAt.includes('+00')
    && vm.formatDateTime(null) === '—'
    && TechnicianRouteView.template.includes('formatDateTime(audit.captured_at)')
);

const baselineAudit = {
  id: 900,
  audit_code: 'DEX-900',
  va1_total_vend_units: 118,
  delta_vend_units: null,
  delta_source: 'INITIAL_BASELINE'
};
assert(
  'Un volcado base sin delta se pinta como — y no como 0',
  vm.dexAuditDeltaUnits(baselineAudit) === null
    && vm.dexAuditDeltaLabel(baselineAudit) === '—'
    && vm.dexAuditDeltaLabel(vm.dexHistory[0]) === '4 u'
    && TechnicianRouteView.template.includes('dexAuditDeltaLabel(audit)')
);

await vm.handleDexFile({ target: { files: [dexFile] } });
assert(
  'El pre-filtro del técnico no recibe el importe VA1',
  vm.dexPreview?.ok === true
    && vm.dexPreview.va1_total_vend_units === 41
    && !Object.prototype.hasOwnProperty.call(vm.dexPreview, 'va1_total_sales_eur')
);
assert(
  'El pre-filtro reconoce el evento EA2/EF01',
  vm.dexPreview.events.length === 1 && vm.dexPreview.events[0].event_code === 'EF01'
);

await vm.submitDexAudit();
assert(
  'La importación online envía el payload crudo y la mutación idempotente',
  importPayload?.machineId === 7
    && importPayload.payload.raw_payload === dexRaw
    && importPayload.payload.original_filename === 'tecnico.dex'
    && /^[A-Za-z0-9_.:-]{8,64}$/.test(importPayload.payload.client_mutation_id)
);
assert(
  'La respuesta térmica activa banner y sugerencia M16',
  vm.dexThermalAudit?.id === 902
    && vm.dexSuggestedEscalation?.action === 'OPEN_OPPORTUNISTIC_INCIDENT'
    && vm.dexSuggestedEscalation?.breakdown_category === 'PRODUCT_JAM'
);
assert(
  'El técnico no recibe prefill M35 en la respuesta',
  !Object.prototype.hasOwnProperty.call(vm, 'prefill_sales_counter_reading_eur')
);

const originalAddComment = api.technician.addComment;
let internalNotePayload = null;
api.technician.addComment = async (incidentId, payload) => {
  internalNotePayload = { incidentId, payload };
  return { data: { id: 1 } };
};
await vm.attachDexInternalNote(vm.dexThermalAudit);
assert(
  'El ticket activo recibe una nota interna con los códigos del .dex',
  internalNotePayload?.incidentId === 44
    && internalNotePayload.payload.is_internal === true
    && internalNotePayload.payload.comment_text.includes('EF01')
    && vm.dexSuccess.includes('Nota interna')
);
api.technician.addComment = originalAddComment;

// ─────────────────────────────────────────────────────────────────────────────
// 3. Alta oportunista M16 en un toque
// ─────────────────────────────────────────────────────────────────────────────
vm.openDexOpportunistic(vm.dexThermalAudit);
assert(
  'El alta M16 conserva máquina, audit_id, categoría y nota interna',
  vm.showOpportunisticModal === true
    && vm.opportunisticDexContext?.machine_id === 7
    && vm.opportunisticDexContext?.dex_audit_id === 902
    && vm.opportunisticDexContext?.breakdown_category === 'PRODUCT_JAM'
    && vm.opportunisticDexContext?.internal_note.includes('EF01')
);
assert(
  'El alta M16 usa la sede de la parada',
  Number(vm.opportunisticLocation?.location_id) === 1
);

const modalVm = buildViewModel(TechnicianOpportunisticModal, {
  dexAuditContext: {
    machine_id: 7,
    dex_audit_id: 902,
    breakdown_category: 'PRODUCT_JAM',
    internal_note: '[DEX DEX-902] Códigos EA1/EA2: EF01'
  }
});
modalVm.machines = [{ id: 7, code: 'VEND-0701', opportunistic_action: 'CREATE_NEW' }];
modalVm.applyDexAuditContext();
assert(
  'El modal M16 preselecciona la máquina y el síntoma del .dex',
  Number(modalVm.selectedMachineId) === 7
    && modalVm.category === 'PRODUCT_JAM'
    && modalVm.description.includes('EF01')
);
const modalCreatePayload = modalVm.buildCreatePayload();
assert(
  'El payload M16 incluye dex_audit_id y no inventa otra máquina',
  modalCreatePayload.machine_id === 7 && modalCreatePayload.dex_audit_id === 902
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Cola offline y replay FIFO
// ─────────────────────────────────────────────────────────────────────────────
const offlineVm = buildViewModel(TechnicianRouteView, {
  currentUser: { id: 6, name: 'Jordi Ruta', role: 'TECHNICIAN' },
  isBrowserOffline: () => true,
  guardOnlineOnly: () => true
});
offlineVm.dexMachine = { id: 7, code: 'VEND-0701' };
offlineVm.dexFile = dexFile;
offlineVm.dexFileName = 'tecnico.dex';
offlineVm.dexPreview = { ok: true, va1_total_vend_units: 41, events: [] };
await offlineVm.submitDexAudit();
const pending = await offlineVm.ensureDexQueue().list(6);
assert(
  'Sin cobertura el .dex se encola aislado por técnico',
  pending.length === 1
    && pending[0].actor_id === 6
    && pending[0].actor_role === 'TECHNICIAN'
    && pending[0].machine_id === 7
    && pending[0].status === 'PENDING'
);

let replayedPayload = null;
api.technician.importDexAudit = async (machineId, payload) => {
  replayedPayload = { machineId, payload };
  return { data: { audit: { id: 903 }, idempotent_replay: false } };
};
offlineVm.isBrowserOffline = () => false;
offlineVm.loadDexHistory = async () => {};
await offlineVm.syncPendingDexAudits();
assert(
  'El replay FIFO reenvía el mismo client_mutation_id y vacía la cola',
  replayedPayload?.payload.client_mutation_id === pending[0].client_mutation_id
    && replayedPayload?.machineId === 7
    && (await offlineVm.ensureDexQueue().list(6)).length === 0
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Conflicto y volcado de evidencia
// ─────────────────────────────────────────────────────────────────────────────
const conflictVm = buildViewModel(TechnicianRouteView, {
  currentUser: { id: 6, name: 'Jordi Ruta', role: 'TECHNICIAN' }
});
await conflictVm.enqueueDexAudit({
  client_mutation_id: 'dex12-tech-conflict',
  machine_id: 7,
  machine_code: 'VEND-0701',
  captured_at: new Date().toISOString(),
  raw_payload: dexRaw,
  original_filename: 'tecnico.dex'
});
await conflictVm.ensureDexQueue().markConflict('dex12-tech-conflict', {
  code: 'MACHINE_OUTSIDE_TECHNICIAN_ZONES',
  message: 'La máquina ya no está asignada al técnico.',
  http_status: 403
});
const [conflict] = await conflictVm.ensureDexQueue().list(6, ['CONFLICT']);
let dumpedPayload = null;
const originalDump = api.technician.dumpDexAuditConflict;
api.technician.dumpDexAuditConflict = async (payload) => {
  dumpedPayload = payload;
  return { ok: true, outcome_status: 'CONFLICT_DUMPED' };
};
await conflictVm.archiveDexConflict(conflict);
assert(
  'El conflicto .dex se archiva con la máquina y el motivo reales',
  dumpedPayload?.machine_id === 7
    && dumpedPayload?.reason.includes('asignada')
    && (await conflictVm.ensureDexQueue().list(6)).length === 0
);
api.technician.dumpDexAuditConflict = originalDump;
api.technician.importDexAudit = originalImport;
api.technician.getDexAudits = originalList;

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
console.log('======================================================================');

if (failures > 0) {
  process.exit(1);
}
