/**
 * VendGuard - CoordinatorDexAuditsSubTab Test Suite
 *
 * Certifica la consola de Coordinación del libro EVA-DTS `.dex` (Módulo 39;
 * RF-07.1, RF-07.2, C6, QA-2, QA-3, QA-5; Art. III.2, V.1 y V.4):
 *   1. KPIs y perímetro de filtros.
 *   2. Listado y detalle financiero.
 *   3. Carga manual de un `.dex` con puerta COORDINATOR_CONSOLE.
 *   4. Anulación lógica con motivo 20..500 y tres bloques.
 *   5. Descarga del original y exportación CSV.
 *   6. Montaje como sexta sub-vista y rutas canónicas del cliente API.
 *   7. Sistema de diseño y contención responsive.
 *
 * Dogma Vanilla: Node.js ESM nativo, sin dependencias externas.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

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
globalThis.window = { location: { search: '', href: 'http://localhost/' } };

import { api } from '../../public/assets/js/api.js';
import { CoordinatorDexAuditsSubTab } from '../../public/assets/js/components/CoordinatorDexAuditsSubTab.js';
import { CoordinatorProductLoadingTab } from '../../public/assets/js/components/CoordinatorProductLoadingTab.js';

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) console.error(`         Motivo: ${details}`);
    failures++;
  }
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - CoordinatorDexAuditsSubTab');
console.log('======================================================================\n');

const machineFixture = {
  id: 1,
  machine_code: 'VEND-MAD-0001',
  location_name: 'Sede Central · Hall principal',
  site_code: 'MAD-01'
};

const listAudit = {
  id: 31,
  audit_code: 'DEX-2026-0031',
  machine_id: 1,
  machine_code_snapshot: 'VEND-MAD-0001',
  location_name: 'Sede Central · Hall principal',
  location_code: 'MAD-01',
  captured_at: '2026-11-24 09:30:00+00',
  import_source: 'RESTOCKER_ROUTE',
  import_source_label: 'Ruta del Reponedor',
  status: 'ACTIVE',
  status_label: 'Vigente',
  va1_total_sales_eur: 125.5,
  va1_total_vend_units: 42,
  ca3_cashbox_eur: 120.25,
  delta_sales_eur: 12.75,
  delta_vend_units: 5,
  effective_delta_sales_eur: 12.75,
  effective_delta_vend_units: 5,
  identity_mismatch_warning: true,
  counter_reset_detected: false,
  out_of_order_sync: false,
  has_thermal_alert: true
};

const detailAudit = {
  ...listAudit,
  importer_name: 'Marta Reponedora',
  importer_role_snapshot: 'RESTOCKER',
  original_filename: 'DEX-2026-0031.dex',
  file_size_bytes: 320,
  sha256_hash: 'a'.repeat(64),
  dxs_comm_id: 'DXS-1',
  dxs_version: '2.0',
  dex_machine_identifier: 'VM-001',
  id4_decimal_places: 2,
  ignored_unknown_lines_count: 1,
  va1_reset_sales_eur: 10,
  va1_reset_vend_units: 3,
  ca3_coin_tubes_eur: 40,
  ca3_bills_in_eur: 80,
  previous_audit_id: 30,
  counter_reset_detected: false,
  out_of_order_sync: false,
  delta_source: 'PREVIOUS_AUDIT',
  previous_audit_voided: false,
  m34_cross_check: { linked_visit_id: 7, matches: true },
  m35_cross_check: { linked_collection_id: 8, variance_eur: 0 },
  restock_visit_id: 7,
  cash_collection_id: 8,
  incident_id: null,
  void_reason: null,
  voided_by: null,
  voided_by_name_snapshot: null,
  voided_at: null,
  selections: [
    {
      id: 1,
      raw_selection_code: '101',
      normalized_selection_code: '101',
      match_status: 'MATCHED',
      match_status_label: 'Emparejada',
      unit_price_eur: 2.5,
      total_vend_units: 10,
      total_sales_eur: 25,
      delta_vend_units: 2,
      delta_sales_eur: 5
    }
  ],
  events: [
    {
      id: 1,
      source_block: 'EVADTS',
      event_code: 'E01',
      event_category: 'TEMPERATURE',
      event_category_label: 'Temperatura',
      severity: 'CRITICAL',
      severity_label: 'Crítica',
      occurrence_count: 1,
      is_thermal_alert: true,
      description_es: 'Temperatura fuera de rango'
    }
  ]
};

const calls = [];
const realCoordinatorApi = { ...api.coordinator };

function buildVm() {
  const vm = { ...CoordinatorDexAuditsSubTab.data(), ...CoordinatorDexAuditsSubTab.methods };
  for (const [name, getter] of Object.entries(CoordinatorDexAuditsSubTab.computed || {})) {
    Object.defineProperty(vm, name, { get: getter, configurable: true });
  }
  return vm;
}

async function run() {
  api.coordinator.getDexAudits = async (params) => {
    calls.push({ method: 'getDexAudits', params });
    return {
      data: {
        audits: [listAudit],
        total: 1,
        limit: 100,
        offset: 0,
        export_limit: 500,
        kpis: {
          total_active: 1,
          thermal_alert_count: 1,
          warning_count: 1,
          cash_deviation_count: 2,
          voided_count: 3
        }
      }
    };
  };
  api.coordinator.getZones = async () => {
    calls.push({ method: 'getZones' });
    return { data: { zones: [{ id: 9, code: 'ZONA-N', name: 'Zona Norte' }] } };
  };
  api.coordinator.getLocations = async () => {
    calls.push({ method: 'getLocations' });
    return { data: [{ id: 5, name: 'Sede Centro', site_code: 'MAD-01' }] };
  };
  api.coordinator.getDexAudit = async (auditId) => {
    calls.push({ method: 'getDexAudit', auditId });
    return { data: detailAudit };
  };
  api.coordinator.getMachines = async () => {
    calls.push({ method: 'getMachines' });
    return { data: { machines: [machineFixture] } };
  };
  api.coordinator.importDexAudit = async (machineId, payload) => {
    calls.push({ method: 'importDexAudit', machineId, payload });
    return { data: { audit: { ...listAudit, id: 32, audit_code: 'DEX-2026-0032' } } };
  };
  api.coordinator.voidDexAudit = async (auditId, payload) => {
    calls.push({ method: 'voidDexAudit', auditId, payload });
    return { data: { ...detailAudit, status: 'VOIDED', void_reason: payload.void_reason } };
  };
  api.coordinator.downloadDexAuditRaw = async (auditId, auditCode) => {
    calls.push({ method: 'downloadDexAuditRaw', auditId, auditCode });
    return true;
  };
  api.coordinator.exportDexAuditsCsv = async (params) => {
    calls.push({ method: 'exportDexAuditsCsv', params });
    return true;
  };

  const vm = buildVm();

  // ─────────────────────────────────────────────────────────────────────
  // 1. KPIs y perímetro
  // ─────────────────────────────────────────────────────────────────────
  console.log('--- 1. KPIs y perímetro de filtros ---');

  await vm.loadAudits();
  const listCall = calls.filter((call) => call.method === 'getDexAudits').at(-1);
  assert(
    '1.1 La carga inicial pide solo vigentes, sin filtros vacíos y con la primera página',
    listCall?.params?.status === 'ACTIVE'
      && listCall?.params?.limit === 100
      && listCall?.params?.offset === 0
      && !('date_from' in listCall.params)
      && !('alerts_only' in listCall.params)
  );
  assert(
    '1.2 Publica los cuatro KPIs y el contador de anulados',
    vm.safeKpis.total_active === 1
      && vm.safeKpis.thermal_alert_count === 1
      && vm.safeKpis.warning_count === 1
      && vm.safeKpis.cash_deviation_count === 2
      && vm.safeKpis.voided_count === 3
  );
  assert('1.3 El listado y sus advertencias quedan disponibles', vm.audits.length === 1 && vm.auditWarnings(vm.audits[0]).join('|') === 'Identidad|Térmica');

  vm.filters.date_from = '2026-11-01';
  vm.filters.date_to = '2026-11-30';
  vm.filters.status = 'ALL';
  vm.filters.import_source = 'COORDINATOR_CONSOLE';
  vm.filters.machine_id = 1;
  vm.filters.zone_id = 9;
  vm.filters.location_id = 5;
  vm.filters.alerts_only = true;
  await vm.loadAudits();
  const filteredCall = calls.filter((call) => call.method === 'getDexAudits').at(-1);
  assert(
    '1.4 El perímetro viaja completo y alerts_only se serializa como 1',
    filteredCall?.params?.date_from === '2026-11-01'
      && filteredCall?.params?.date_to === '2026-11-30'
      && filteredCall?.params?.status === 'ALL'
      && filteredCall?.params?.import_source === 'COORDINATOR_CONSOLE'
      && filteredCall?.params?.machine_id === 1
      && filteredCall?.params?.zone_id === 9
      && filteredCall?.params?.location_id === 5
      && filteredCall?.params?.alerts_only === '1'
  );
  assert('1.5 El botón de limpiar solo se activa con perímetro', vm.hasActiveFilters === true);
  vm.resetFilters();
  assert('1.6 Limpiar restaura vigentes y borra el perímetro', vm.filters.status === 'ACTIVE' && vm.hasActiveFilters === false);

  await vm.loadFilterCatalogs();
  assert(
    '1.7 Zonas y sedes alimentan los selectores del perímetro',
    vm.zoneOptions.length === 1
      && vm.zoneOptions[0].id === 9
      && vm.locationOptions.length === 1
      && vm.locationOptions[0].id === 5
  );

  const pagedCalls = [];
  const originalPagedGet = api.coordinator.getDexAudits;
  api.coordinator.getDexAudits = async (params) => {
    pagedCalls.push(params);
    return {
      data: {
        audits: [{ ...listAudit, id: params.offset === 0 ? 101 : 102 }],
        total: 2,
        export_limit: 500,
        kpis: {}
      }
    };
  };
  vm.pageSize = 1;
  await vm.loadAudits();
  await vm.loadMore();
  assert(
    '1.8 La consola pagina con limit/offset y acumula «Cargar más»',
    pagedCalls[0]?.limit === 1
      && pagedCalls[0]?.offset === 0
      && pagedCalls[1]?.limit === 1
      && pagedCalls[1]?.offset === 1
      && vm.audits.length === 2
      && vm.hasMore === false
  );
  api.coordinator.getDexAudits = originalPagedGet;

  vm.total = 600;
  vm.exportLimit = 500;
  assert(
    '1.9 El aviso de CSV truncado se activa por encima del tope',
    vm.exportTruncated === true
      && CoordinatorDexAuditsSubTab.template.includes('data-testid="dex-export-warning"')
      && CoordinatorDexAuditsSubTab.template.includes('data-testid="dex-load-more"')
  );
  await vm.loadAudits();

  assert(
    '1.10 La celda Delta no duplica el placeholder cuando no hay unidades',
    vm.deltaUnits({ effective_delta_vend_units: null, delta_vend_units: null }) === null
      && vm.deltaUnits({ effective_delta_vend_units: null, delta_vend_units: 7 }) === 7
      && vm.deltaUnits('') === null
      && CoordinatorDexAuditsSubTab.template.includes('v-if="deltaUnits(audit) !== null"')
      && !CoordinatorDexAuditsSubTab.template.includes('effective_delta_vend_units ?? audit.delta_vend_units) }} uds.')
  );

  assert(
    '1.11 Con el predecesor anulado manda el delta efectivo, incluso cuando es null',
    vm.deltaUnits({ previous_audit_voided: true, effective_delta_vend_units: null, delta_vend_units: 7 }) === null
      && vm.deltaSales({ previous_audit_voided: true, effective_delta_sales_eur: null, delta_sales_eur: 7 }) === null
      && vm.deltaUnits({ previous_audit_voided: true, effective_delta_vend_units: 3, delta_vend_units: 7 }) === 3
      && vm.deltaSales({ previous_audit_voided: true, effective_delta_sales_eur: 12, delta_sales_eur: 7 }) === 12
      && vm.deltaSales({ previous_audit_voided: false, effective_delta_sales_eur: null, delta_sales_eur: 7 }) === 7
      && CoordinatorDexAuditsSubTab.template.includes('money(deltaSales(audit))')
      && CoordinatorDexAuditsSubTab.template.includes('money(deltaSales(detail))')
      && CoordinatorDexAuditsSubTab.template.includes('audit.previous_audit_voided === true')
  );

  // ─────────────────────────────────────────────────────────────────────
  // 2. Listado y detalle
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 2. Listado y detalle financiero ---');

  await vm.loadMachines();
  assert('2.1 Las máquinas se cargan para los selectores', vm.machineOptions.length === 1 && vm.machineOptions[0].label.includes('VEND-MAD-0001'));
  assert('2.2 El estado y la puerta se traducen al castellano', vm.statusLabel('VOIDED') === 'Anulado' && vm.sourceLabel('TECHNICIAN_ROUTE') === 'Ruta del Técnico SAT');
  assert('2.3 Los importes y unidades toleran nulos', vm.money(null) === '—' && vm.integer(undefined) === '—' && vm.money(12.5).includes('12,50'));

  await vm.openDetail(listAudit);
  const detailCall = calls.filter((call) => call.method === 'getDexAudit').at(-1);
  assert('2.4 El detalle se pide por id y abre el modal', detailCall?.auditId === 31 && vm.showDetailModal === true && vm.isLoadingDetail === false);
  assert('2.5 El detalle conserva selecciones, eventos y cruces', vm.detail?.selections?.length === 1 && vm.detail?.events?.length === 1 && vm.detail?.m34_cross_check?.matches === true && vm.detail?.m35_cross_check?.linked_collection_id === 8);
  assert('2.6 El hash y la trazabilidad del fichero están presentes', vm.detail?.sha256_hash?.length === 64 && vm.detail?.original_filename === 'DEX-2026-0031.dex');

  // ─────────────────────────────────────────────────────────────────────
  // 3. Carga manual
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 3. Carga manual de un .dex ---');

  await vm.handleManualFile({ target: { files: [{ name: 'volcado.txt', size: 20, text: async () => 'x' }] } });
  assert('3.1 Un fichero que no es .dex se rechaza antes de leerlo', vm.manualFileError.includes('.dex') && vm.manualForm.raw_payload === '');

  await vm.handleManualFile({ target: { files: [{ name: 'volcado.dex', size: 1048577, text: async () => 'x' }] } });
  assert('3.2 El tope de 1 MB se anticipa en cliente', vm.manualFileError.includes('1 MB'));

  await vm.handleManualFile({
    target: {
      files: [{
        name: 'volcado.dex',
        size: 128,
        text: async () => 'DXS*1*VEND-MAD-0001*V2.0*|\nVA1*001*2.50*10*|\nCA3*120.25*|'
      }]
    }
  });
  assert('3.3 Un .dex válido queda adjunto y conserva nombre y texto', vm.manualForm.raw_payload.includes('VA1') && vm.manualForm.original_filename === 'volcado.dex');

  vm.manualForm.machine_id = 1;
  vm.manualForm.captured_at = '2026-11-25T10:15';
  await vm.submitManualImport();
  const importCall = calls.filter((call) => call.method === 'importDexAudit').at(-1);
  assert(
    '3.4 La importación envía máquina, contenido, captura e idempotencia',
    importCall?.machineId === 1
      && importCall?.payload?.raw_payload.includes('VA1')
      && importCall?.payload?.original_filename === 'volcado.dex'
      && importCall?.payload?.captured_at === '2026-11-25T10:15'
      && typeof importCall?.payload?.client_mutation_id === 'string'
      && importCall.payload.client_mutation_id.length >= 8
  );
  assert('3.5 El alta confirma el código y vacía el formulario', vm.successMessage.includes('DEX-2026-0032') && vm.manualForm.raw_payload === '');

  // ─────────────────────────────────────────────────────────────────────
  // 4. Anulación lógica
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 4. Anulación lógica con motivo reforzado ---');

  vm.openVoid(listAudit);
  vm.voidReason = 'Duplicado corto';
  assert('4.1 Un motivo con menos de 20 caracteres no es válido', vm.voidReasonIsValid === false);
  vm.voidReason = 'Duplicado confirmado';
  assert('4.2 Un motivo sin tres bloques alfanuméricos no es válido', vm.voidReasonBlocks < 3 && vm.voidReasonIsValid === false);

  const validVoidReason = 'Duplicado real detectado en la consola de coordinación; se conserva el original y se anula el duplicado.';
  vm.voidReason = validVoidReason;
  assert('4.3 Un motivo de 20..500 con tres bloques es válido', vm.voidReasonIsValid === true && vm.voidReasonLength <= 500);

  await vm.submitVoid();
  const voidCall = calls.filter((call) => call.method === 'voidDexAudit').at(-1);
  assert('4.4 La anulación es POST lógico con el motivo recortado', voidCall?.auditId === 31 && voidCall?.payload?.void_reason === validVoidReason.trim());
  assert('4.5 El cierre conserva el original y recarga el libro', vm.showVoidModal === false && vm.successMessage.includes('anulado lógicamente'));
  assert(
    '4.6 Los contadores de una unidad usan singular',
    vm.pluralUnit(1, 'anulado', 'anulados') === 'anulado'
      && vm.pluralUnit(2, 'anulado', 'anulados') === 'anulados'
      && vm.pluralUnit('1', 'bloque', 'bloques') === 'bloque'
      && CoordinatorDexAuditsSubTab.template.includes("pluralUnit(safeKpis.voided_count, 'anulado', 'anulados')")
      && CoordinatorDexAuditsSubTab.template.includes("pluralUnit(voidReasonBlocks, 'bloque', 'bloques')")
  );

  // ─────────────────────────────────────────────────────────────────────
  // 5. Raw y CSV
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 5. Descarga del original y exportación CSV ---');

  await vm.downloadRaw(listAudit);
  const rawCall = calls.filter((call) => call.method === 'downloadDexAuditRaw').at(-1);
  assert('5.1 La descarga usa el id y el código del volcado', rawCall?.auditId === 31 && rawCall?.auditCode === 'DEX-2026-0031');

  vm.filters.status = 'VOIDED';
  vm.filters.alerts_only = true;
  await vm.exportCsv();
  const exportCall = calls.filter((call) => call.method === 'exportDexAuditsCsv').at(-1);
  assert('5.2 El CSV hereda el perímetro filtrado', exportCall?.params?.status === 'VOIDED' && exportCall?.params?.alerts_only === '1');

  // ─────────────────────────────────────────────────────────────────────
  // 6. Montaje y rutas canónicas
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 6. Montaje y rutas canónicas ---');

  assert(
    '6.1 La pestaña registra la sexta sub-vista y su botón',
    CoordinatorProductLoadingTab.components.CoordinatorDexAuditsSubTab === CoordinatorDexAuditsSubTab
      && CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-dex-section-button"')
      && CoordinatorProductLoadingTab.template.includes('activeSection === \'dex\'')
      && CoordinatorProductLoadingTab.template.includes('<CoordinatorDexAuditsSubTab')
  );

  Object.assign(api.coordinator, realCoordinatorApi);
  const paths = [];
  const originalRequest = api.request;
  const originalDownloadFile = api.downloadFile;
  api.request = async (path, options = {}) => {
    paths.push({ method: options.method || 'GET', path });
    return { data: {} };
  };
  api.downloadFile = async (endpoint, filename, params = {}) => {
    paths.push({ method: 'DOWNLOAD', path: endpoint, filename, params });
    return true;
  };

  await api.coordinator.getDexAudits({ status: 'ACTIVE', alerts_only: '1' });
  await api.coordinator.getDexAudit(31);
  await api.coordinator.importDexAudit(1, { raw_payload: 'DXS*' });
  await api.coordinator.voidDexAudit(31, { void_reason: 'motivo' });
  await api.coordinator.downloadDexAuditRaw(31, 'DEX-2026-0031');
  await api.coordinator.exportDexAuditsCsv({ status: 'ALL' });

  api.request = originalRequest;
  api.downloadFile = originalDownloadFile;

  assert(
    '6.2 Las seis rutas DEX usan verbo y path canónicos',
    paths.some((entry) => entry.method === 'GET' && entry.path.startsWith('/coordinator/dex-audits?'))
      && paths.some((entry) => entry.method === 'GET' && entry.path === '/coordinator/dex-audits/31')
      && paths.some((entry) => entry.method === 'POST' && entry.path === '/coordinator/machines/1/dex-audits')
      && paths.some((entry) => entry.method === 'POST' && entry.path === '/coordinator/dex-audits/31/void')
      && paths.some((entry) => entry.method === 'DOWNLOAD' && entry.path === '/coordinator/dex-audits/31/raw' && entry.filename === 'DEX-2026-0031.dex')
      && paths.some((entry) => entry.method === 'DOWNLOAD' && entry.path === '/coordinator/dex-audits/export-csv' && entry.filename === 'dex-audits.csv')
  );
  assert('6.3 La query de filtros se codifica en la ruta', paths.some((entry) => entry.path.includes('status=ACTIVE') && entry.path.includes('alerts_only=1')));

  // ─────────────────────────────────────────────────────────────────────
  // 7. Diseño y responsive
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 7. Sistema de diseño y responsive ---');

  const source = readFileSync(
    fileURLToPath(new URL('../../public/assets/js/components/CoordinatorDexAuditsSubTab.js', import.meta.url)),
    'utf8'
  );
  assert('7.1 Usa tokens de diseño y no colores hexadecimales', !/#[0-9a-fA-F]{3,6}\b/.test(source) && (source.match(/var\(--/g) || []).length >= 20);
  assert('7.2 La tabla ancha queda dentro de un contenedor con scroll propio', source.includes('data-testid="dex-table-wrapper"') && source.includes('overflow-x: auto') && source.includes('min-width: 1080px'));
  assert('7.3 Los KPIs y filtros usan rejillas auto-ajustables', (source.match(/repeat\(auto-fit, minmax\(150px, 1fr\)\)/g) || []).length >= 3);
  assert('7.4 No introduce dependencias externas', !/from\s+['"](?!\.\.?\/)/.test(source) && !source.includes('axios') && !source.includes('lodash'));
  assert(
    '7.5 El selector .dex oculta el control nativo y rotula su estado',
    source.includes('data-testid="dex-manual-file-trigger"')
      && source.includes('for="dex-manual-file"')
      && source.includes("'Ningún archivo seleccionado'")
      && /id="dex-manual-file"[\s\S]{0,220}class="visually-hidden"/.test(source)
  );

  console.log('\n======================================================================');
  console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
  console.log(failures === 0 ? ' RESULTADO: 100% EN VERDE.' : ' RESULTADO: FALLOS EN LA CONSOLA DEX.');
  console.log('======================================================================\n');

  if (failures > 0) process.exitCode = 1;
}

try {
  await run();
} catch (error) {
  console.error(`\n[FATAL] ${error?.stack || error}`);
  process.exitCode = 1;
} finally {
  Object.assign(api.coordinator, realCoordinatorApi);
}
