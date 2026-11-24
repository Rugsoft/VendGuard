/**
 * VendGuard - Frontend Test Suite (restocking_bi_tab.test.mjs)
 *
 * Certifica la sub-pestaña «📦 Métricas de Carga y Reposición» del Módulo 38
 * (RF-06.1, RF-06.2, D4, D5, RNF-04, QA-1, QA-3):
 *   1. Ventana por defecto de 30 días y filtros canónicos sin campos vacíos.
 *   2. Cascada zona/sede que limpia `location_id` fuera de la demarcación.
 *   3. Tarjetas KPI, formato español y aviso térmico no segmentable.
 *   4. Tres tablas con insignias «Baja» y «Planograma sobredimensionado».
 *   5. Dos gráficos Chart.js con tokens computados, recreación y degradación.
 *   6. Integración en `CoordinatorMetricsView` con la barra global oculta.
 *   7. Etiqueta de auditoría, precache `v33` y cero hexadecimales.
 *
 * Dogma Vanilla: Node.js ESM nativo, sin dependencias externas ni red.
 */

import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const storage = new Map();
globalThis.localStorage = {
  getItem: (key) => storage.get(key) ?? null,
  setItem: (key, value) => storage.set(key, String(value)),
  removeItem: (key) => storage.delete(key),
  clear: () => storage.clear()
};

const TOKENS = {
  '--color-primary': 'token-primary',
  '--color-warning': 'token-warning',
  '--color-error': 'token-error',
  '--color-ink-muted': 'token-muted',
  '--color-hairline': 'token-hairline',
  '--color-surface-card': 'token-surface',
  '--font-body': 'token-font'
};

globalThis.document = { documentElement: {} };
globalThis.window = {
  location: { search: '', href: 'http://localhost/' },
  getComputedStyle: () => ({
    getPropertyValue: (name) => TOKENS[name] ?? ''
  })
};

import { api } from '../../public/assets/js/api.js';
import { CoordinatorRestockingBiTab } from '../../public/assets/js/components/CoordinatorRestockingBiTab.js';
import { CoordinatorMetricsView } from '../../public/assets/js/views/CoordinatorMetricsView.js';
import { AUDIT_ACTION_CATALOG } from '../../public/assets/js/utils/AuditActionLabels.js';

const PROJECT_ROOT = fileURLToPath(new URL('../../', import.meta.url));

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
console.log(' VendGuard: Frontend Test Suite - CoordinatorRestockingBiTab');
console.log('======================================================================\n');

function buildReport() {
  return {
    filters: {
      from: '2026-10-26',
      to: '2026-11-24',
      zone_id: null,
      location_id: null,
      restocker_id: null,
      timezone: 'Europe/Madrid'
    },
    available_filters: {
      zones: [
        { id: 1, code: 'ZN-NORTE', name: 'Zona Norte', is_active: true },
        { id: 2, code: 'ZN-HIST', name: 'Zona Antigua', is_active: false }
      ],
      locations: [
        { id: 3, site_code: 'HOSP-01', name: 'Hospital Universitario', zone_id: 1, is_active: true },
        { id: 8, site_code: 'EXT-04', name: 'Sede Sin Zona', zone_id: null, is_active: true },
        { id: 9, site_code: 'OLD-01', name: 'Sede Antigua', zone_id: 2, is_active: false }
      ],
      restockers: [
        { id: 7, operator_code: 'REP-01', name: 'Lucía Navarro', is_active: true },
        { id: 12, operator_code: null, name: 'Carlos Méndez', is_active: false }
      ]
    },
    summary: {
      completed_visits_count: 20,
      clean_in_stock_visits_count: 17,
      stockout_visits_count: 3,
      visit_in_stock_rate_pct: 85.0,
      visit_stockout_rate_pct: 15.0,
      thermal_aborted_visits_count: 1,
      audited_slots_count: 64,
      stockout_slots_count: 5,
      slot_stockout_rate_pct: 7.81,
      low_threshold_slots_count: 9,
      oversized_machines_count: 1,
      commercial_spoilage_eur: 42.3,
      commercial_spoilage_units: 18,
      thermal_spoilage_eur: 31.5,
      thermal_spoilage_units: 9,
      total_spoilage_eur: 73.8,
      cash_reconciled_ok_count: 15,
      cash_discrepancy_flagged_count: 1,
      cash_accuracy_rate_pct: 93.75,
      l1_resolved_count: 4,
      escalated_to_sat_count: 2
    },
    spoilage_comparison: {
      commercial_fefo_expired: { units: 15, loss_eur: 34.8 },
      commercial_damaged: { units: 3, loss_eur: 7.5 },
      commercial_total: { units: 18, loss_eur: 42.3 },
      thermal_haccp: {
        segmentable: true,
        non_segmentable_reason: null,
        non_segmentable_message: null,
        records_count: 2,
        units: 9,
        loss_eur: 31.5
      }
    },
    charts: {
      spoilage_bars: {
        labels: ['Caducidad FEFO', 'Dañado en ruta', 'Avería Térmica APPCC'],
        keys: ['commercial_fefo_expired', 'commercial_damaged', 'thermal_haccp'],
        eur_values: [34.8, 7.5, 31.5],
        unit_values: [15, 3, 9],
        thermal_segmentable: true
      },
      top_critical_stockout: {
        by_machine: [
          {
            id: 5,
            code: 'MAQ-HOSP-02',
            label: 'MAQ-HOSP-02 · Hospital Universitario',
            is_active: true,
            stockout_slots_count: 3,
            audited_slots_count: 12,
            slot_stockout_rate_pct: 25.0
          }
        ],
        by_product: [
          {
            id: 3,
            code: 'SAND-MIXTO-01',
            label: 'SAND-MIXTO-01 · Sándwich Mixto Fresco',
            is_active: true,
            stockout_slots_count: 3,
            audited_slots_count: 10,
            slot_stockout_rate_pct: 30.0
          }
        ]
      },
      top_critical_expiry: {
        by_machine: [
          {
            id: 5,
            code: 'MAQ-HOSP-02',
            label: 'MAQ-HOSP-02 · Hospital Universitario',
            is_active: true,
            expired_units: 9,
            added_units: 36,
            expiry_rate_pct: 20.0,
            is_oversized_planogram: true
          }
        ],
        by_product: [
          {
            id: 3,
            code: 'SAND-MIXTO-01',
            label: 'SAND-MIXTO-01 · Sándwich Mixto Fresco',
            is_active: true,
            expired_units: 9,
            added_units: 36,
            expiry_rate_pct: 20.0
          }
        ]
      }
    },
    by_machine: [
      {
        machine_id: 5,
        machine_code: 'MAQ-HOSP-02',
        machine_type: 'PERISHABLE_FOOD',
        location_id: 3,
        location_name: 'Hospital Universitario',
        zone_id: 1,
        zone_name: 'Zona Norte',
        is_active: false,
        completed_visits_count: 6,
        clean_in_stock_visits_count: 4,
        visit_in_stock_rate_pct: 66.67,
        thermal_aborted_visits_count: 1,
        audited_slots_count: 12,
        stockout_slots_count: 3,
        slot_stockout_rate_pct: 25.0,
        low_threshold_slots_count: 2,
        added_units: 36,
        expired_units: 9,
        damaged_units: 1,
        expired_loss_eur: 22.5,
        damaged_loss_eur: 2.5,
        commercial_spoilage_loss_eur: 25.0,
        expiry_rate_pct: 20.0,
        is_oversized_planogram: true,
        thermal_spoilage_units: 9,
        thermal_spoilage_loss_eur: 31.5
      }
    ],
    by_product: [
      {
        product_id: 3,
        sku: 'SAND-MIXTO-01',
        name: 'Sándwich Mixto Fresco',
        category: 'PERISHABLE_FOOD',
        is_active: false,
        audited_slots_count: 10,
        stockout_slots_count: 3,
        slot_stockout_rate_pct: 30.0,
        low_threshold_slots_count: 1,
        added_units: 36,
        expired_units: 9,
        damaged_units: 0,
        expired_loss_eur: 22.5,
        damaged_loss_eur: 0.0,
        commercial_spoilage_loss_eur: 22.5,
        expiry_rate_pct: 20.0
      }
    ],
    by_restocker: [
      {
        restocker_id: 7,
        operator_code: 'REP-01',
        name: 'Lucía Navarro',
        is_active: true,
        completed_visits_count: 14,
        thermal_aborted_visits_count: 1,
        valid_shifts_count: 5,
        machines_per_shift: 2.8,
        machines_per_shift_na_reason: null,
        avg_stop_duration_minutes: 18.5,
        l1_resolved_count: 3,
        escalated_to_sat_count: 1,
        reconciled_ok_count: 11,
        discrepancy_flagged_count: 1,
        pending_audit_count: 1,
        cash_accuracy_rate_pct: 91.67,
        total_abs_discrepancy_eur: 2.4,
        expired_units: 10,
        damaged_units: 2,
        commercial_spoilage_loss_eur: 27.8
      },
      {
        restocker_id: 12,
        operator_code: null,
        name: 'Carlos Méndez',
        is_active: false,
        completed_visits_count: 0,
        thermal_aborted_visits_count: 0,
        valid_shifts_count: 0,
        machines_per_shift: null,
        machines_per_shift_na_reason: 'NO_VALID_SHIFTS',
        avg_stop_duration_minutes: null,
        l1_resolved_count: 0,
        escalated_to_sat_count: 0,
        reconciled_ok_count: 0,
        discrepancy_flagged_count: 0,
        pending_audit_count: 0,
        cash_accuracy_rate_pct: null,
        total_abs_discrepancy_eur: 0,
        expired_units: 0,
        damaged_units: 0,
        commercial_spoilage_loss_eur: 0
      }
    ]
  };
}

class FakeChart {
  static instances = [];

  constructor(canvas, config) {
    this.canvas = canvas;
    this.config = config;
    this.destroyed = false;
    FakeChart.instances.push(this);
  }

  destroy() {
    this.destroyed = true;
  }
}

const calls = [];
api.getRestockingBi = async (params) => {
  calls.push({ method: 'getRestockingBi', params });
  return { success: true, data: buildReport() };
};
api.downloadRestockingBiCsv = async (params) => {
  calls.push({ method: 'downloadRestockingBiCsv', params });
  return true;
};

function buildInstance() {
  const instance = { ...CoordinatorRestockingBiTab.data() };
  Object.assign(instance, CoordinatorRestockingBiTab.methods);

  for (const [key, getter] of Object.entries(CoordinatorRestockingBiTab.computed)) {
    Object.defineProperty(instance, key, { configurable: true, get: getter });
  }

  instance.$refs = {
    spoilageChartCanvas: { name: 'spoilage' },
    topChartCanvas: { name: 'top' }
  };
  instance.$nextTick = async () => {};

  return instance;
}

const componentSource = readFileSync(
  fileURLToPath(new URL('../../public/assets/js/components/CoordinatorRestockingBiTab.js', import.meta.url)),
  'utf8'
);
const swSource = readFileSync(fileURLToPath(new URL('../../public/sw.js', import.meta.url)), 'utf8');

async function runTests() {
  // ───────────────────────────────────────────────────────────────────────
  // 1. Ventana por defecto, carga y filtros canónicos
  // ───────────────────────────────────────────────────────────────────────
  console.log('--- 1. Ventana por defecto y filtros canónicos ---');

  const component = buildInstance();
  const defaultDays = Math.round(
    (new Date(`${component.filters.to}T12:00:00Z`) - new Date(`${component.filters.from}T12:00:00Z`))
    / 86400000
  );

  assert(
    '1.1 La ventana por defecto cubre los últimos 30 días naturales',
    defaultDays === 29 && component.filters.from < component.filters.to,
    `${component.filters.from} → ${component.filters.to}`
  );
  assert(
    '1.2 Zona, sede y reponedor arrancan sin selección',
    component.filters.zone_id === ''
      && component.filters.location_id === ''
      && component.filters.restocker_id === ''
  );

  await component.loadReport();

  assert(
    '1.3 La carga pide el informe una sola vez y guarda el resumen',
    calls.length === 1
      && calls[0].method === 'getRestockingBi'
      && component.summary.completed_visits_count === 20
  );
  assert(
    '1.4 Los filtros vacíos no viajan como cadena vacía',
    'zone_id' in calls[0].params === false
      && 'location_id' in calls[0].params === false
      && 'restocker_id' in calls[0].params === false
      && calls[0].params.from === component.filters.from
  );
  assert(
    '1.5 El catálogo se adapta a los tres listados del contrato',
    component.zones.length === 2
      && component.allLocations.length === 3
      && component.restockerOptions.length === 2
  );

  // ───────────────────────────────────────────────────────────────────────
  // 2. Cascada zona / sede (QA-L5)
  // ───────────────────────────────────────────────────────────────────────
  console.log('\n--- 2. Cascada territorial zona / sede ---');

  calls.length = 0;
  component.filters.zone_id = 1;
  component.filters.location_id = 9;
  await component.handleZoneChange();

  assert(
    '2.1 Una sede fuera de la zona elegida se limpia antes de recargar',
    component.filters.location_id === ''
      && calls.length === 1
      && calls[0].params.zone_id === 1
      && !('location_id' in calls[0].params)
  );

  calls.length = 0;
  component.filters.location_id = 3;
  await component.handleZoneChange();

  assert(
    '2.2 Una sede dentro de la zona se conserva y viaja al informe',
    component.filters.location_id === 3
      && calls[0].params.zone_id === 1
      && calls[0].params.location_id === 3
  );

  calls.length = 0;
  component.filters.zone_id = '';
  await component.handleZoneChange();

  assert(
    '2.3 Sin zona se conserva la sede elegida para la consulta general',
    component.filters.location_id === 3 && calls[0].params.location_id === 3
  );

  assert(
    '2.4 El selector de sedes sin zona no ofrece sedes de otra demarcación',
    (() => {
      const scoped = buildInstance();
      scoped.report = { ...scoped.report, available_filters: buildReport().available_filters };
      scoped.filters.zone_id = 1;
      return scoped.locationsInZone.length === 1 && scoped.locationsInZone[0].id === 3;
    })()
  );

  // ───────────────────────────────────────────────────────────────────────
  // 3. Tarjetas KPI, formato y aviso térmico
  // ───────────────────────────────────────────────────────────────────────
  console.log('\n--- 3. Tarjetas KPI, formato y aviso térmico ---');

  assert(
    '3.1 Se pintan las nueve tarjetas KPI del informe',
    component.summaryCards.length === 9
      && component.summaryCards.some((card) => card.key === 'cash-accuracy')
  );
  assert(
    '3.2 La disponibilidad se muestra con dos decimales españoles',
    component.summaryCards.find((card) => card.key === 'visit-stockout').value === '85,00 %'
  );
  assert(
    '3.3 Una tasa sin denominador se declara con raya, nunca con 0 %',
    component.formatPercent(null) === '—' && component.formatPercent(undefined) === '—'
  );
  assert(
    '3.4 Un importe nulo no inventa cifra y uno real usa coma decimal',
    component.formatCurrency(null) === '—'
      && component.formatCurrency(42.3).includes('42,30')
  );
  assert(
    '3.5 Un contador ausente también se declara con raya',
    component.formatInteger(undefined) === '—' && component.formatInteger(0) === '0'
  );
  assert(
    '3.6 Máquinas por turno distingue sus dos motivos nulos',
    component.machinesPerShiftLabel({ machines_per_shift: null, machines_per_shift_na_reason: 'NOT_SEGMENTABLE_BY_TERRITORY' }) === 'No segmentable'
      && component.machinesPerShiftLabel({ machines_per_shift: null, machines_per_shift_na_reason: 'NO_VALID_SHIFTS' }) === 'Sin turnos'
      && component.machinesPerShiftLabel({ machines_per_shift: 2.8 }) === '2,80'
  );
  assert(
    '3.7 Las entidades con baja lógica se etiquetan en los selectores',
    component.zoneLabel({ id: 2, code: 'ZN-HIST', name: 'Zona Antigua', is_active: false }).includes('(Baja)')
      && component.locationLabel({ id: 9, site_code: 'OLD-01', name: 'Sede Antigua', is_active: false }).includes('(Baja)')
      && component.restockerLabel({ id: 12, operator_code: null, name: 'Carlos', is_active: false }).startsWith('—')
  );
  assert(
    '3.8 Sin filtro por reponedor la merma térmica es segmentable',
    component.thermalNotSegmentable === false
      && component.summaryCards.find((card) => card.key === 'thermal-spoilage').hint.includes('actas APPCC')
  );

  const thermalComponent = buildInstance();
  thermalComponent.report = {
    ...thermalComponent.report,
    summary: { ...component.summary, thermal_spoilage_eur: null, thermal_spoilage_units: null },
    spoilage_comparison: {
      ...component.comparison,
      thermal_haccp: {
        segmentable: false,
        non_segmentable_reason: 'NOT_SEGMENTABLE_BY_RESTOCKER',
        non_segmentable_message: 'No segmentable por reponedor (ámbito máquina/SAT)',
        records_count: null,
        units: null,
        loss_eur: null
      }
    },
    charts: {
      ...component.charts,
      spoilage_bars: {
        ...component.charts.spoilage_bars,
        labels: ['Caducidad FEFO', 'Dañado en ruta'],
        keys: ['commercial_fefo_expired', 'commercial_damaged'],
        eur_values: [34.8, 7.5],
        unit_values: [15, 3],
        thermal_segmentable: false
      }
    }
  };

  assert(
    '3.9 Filtrar por reponedor activa el aviso térmico canónico',
    thermalComponent.thermalNotSegmentable === true
      && thermalComponent.thermalNoticeMessage === 'No segmentable por reponedor (ámbito máquina/SAT)'
  );
  assert(
    '3.10 La tarjeta térmica queda en raya y el gráfico pierde la tercera barra',
    thermalComponent.summaryCards.find((card) => card.key === 'thermal-spoilage').value === '—'
      && thermalComponent.spoilageRows.length === 2
      && thermalComponent.spoilageRows.every((row) => row.key !== 'thermal_haccp')
  );
  assert(
    '3.11 El aviso térmico vive en el template con su testid estable',
    CoordinatorRestockingBiTab.template.includes('data-testid="restocking-bi-thermal-notice"')
      && CoordinatorRestockingBiTab.template.includes('{{ thermalNoticeMessage }}')
  );

  // ───────────────────────────────────────────────────────────────────────
  // 4. Tablas e insignias
  // ───────────────────────────────────────────────────────────────────────
  console.log('\n--- 4. Tablas e insignias ---');

  assert(
    '4.1 Las tres tablas publican sus filas del contrato',
    component.machines.length === 1
      && component.products.length === 1
      && component.restockers.length === 2
  );
  assert(
    '4.2 Una sede sin zona se muestra como «Sin zona»',
    component.machineLocationLabel({ location_name: 'Sede Sin Zona', zone_name: null }) === 'Sede Sin Zona · Sin zona'
  );
  assert(
    '4.3 Los abortos térmicos se destacan en la tabla de máquinas',
    component.thermalAbortsLabel({ thermal_aborted_visits_count: 1 }) === '1 ⚠️'
      && component.thermalAbortsLabel({ thermal_aborted_visits_count: 0 }) === '0'
  );
  assert(
    '4.4 Las tres tablas tienen testid estable en el template',
    ['machines', 'products', 'restockers'].every(
      (name) => CoordinatorRestockingBiTab.template.includes(`data-testid="restocking-bi-${name}-table"`)
    )
  );
  assert(
    '4.5 La insignia «Baja» se pinta en las tres tablas',
    (CoordinatorRestockingBiTab.template.match(/>Baja<\/span>/g) ?? []).length >= 3
  );
  assert(
    '4.6 La insignia de planograma sobredimensionado usa el copy canónico',
    /Planograma sobredimensionado<\/span>/.test(CoordinatorRestockingBiTab.template)
  );
  assert(
    '4.7 El periodo vacío se declara sin inventar tasas',
    CoordinatorRestockingBiTab.template.includes('data-testid="restocking-bi-empty"')
      && CoordinatorRestockingBiTab.template.includes('«—»')
  );

  // ───────────────────────────────────────────────────────────────────────
  // 5. Gráficos Chart.js, tokens y degradación
  // ───────────────────────────────────────────────────────────────────────
  console.log('\n--- 5. Gráficos Chart.js, tokens y degradación ---');

  FakeChart.instances = [];
  globalThis.window.Chart = FakeChart;

  component.renderCharts();

  const [spoilageChart, topChart] = FakeChart.instances;
  assert(
    '5.1 La recarga recrea exactamente dos gráficos',
    FakeChart.instances.length === 2
      && spoilageChart?.canvas?.name === 'spoilage'
      && topChart?.canvas?.name === 'top'
  );
  assert(
    '5.2 El gráfico de mermas usa las tres barras del contrato en euros',
    spoilageChart?.config?.data?.labels?.length === 3
      && spoilageChart?.config?.data?.datasets[0].data.join(',') === '34.8,7.5,31.5'
      && spoilageChart?.config?.data?.datasets[0].label.includes('€')
  );
  assert(
    '5.3 Los colores salen de tokens computados, no de literales',
    spoilageChart?.config?.data?.datasets[0].backgroundColor.join(',') === 'token-primary,token-warning,token-error'
      && spoilageChart?.config?.options?.plugins?.tooltip?.backgroundColor === 'token-surface'
      && spoilageChart?.config?.options?.scales?.x?.ticks?.font?.family === 'token-font'
  );
  assert(
    '5.4 El Top 10 es horizontal y parte de la tasa de rotura por máquina',
    topChart?.config?.options?.indexAxis === 'y'
      && topChart?.config?.data?.labels[0] === 'MAQ-HOSP-02 · Hospital Universitario'
      && topChart?.config?.data?.datasets[0].data[0] === 25
  );

  component.spoilageMetric = 'units';
  component.renderCharts();

  assert(
    '5.5 El conmutador €/Unidades alimenta la serie en unidades',
    spoilageChart.destroyed === true
      && topChart.destroyed === true
      && FakeChart.instances.length === 4
      && FakeChart.instances[2].config.data.datasets[0].data.join(',') === '15,3,9'
  );

  component.topMetric = 'expiry';
  component.topDimension = 'product';
  component.renderCharts();

  const expiryChart = FakeChart.instances[5];
  assert(
    '5.6 El Top 10 conmuta a caducidad FEFO por producto',
    expiryChart?.config?.data?.labels[0] === 'SAND-MIXTO-01 · Sándwich Mixto Fresco'
      && expiryChart?.config?.data?.datasets[0].data[0] === 20
      && expiryChart?.config?.data?.datasets[0].label.includes('Caducidad FEFO')
  );

  const instanceCount = FakeChart.instances.length;
  delete globalThis.window.Chart;
  component.renderCharts();

  assert(
    '5.7 Sin window.Chart se declara la degradación sin destruir el informe',
    component.chartUnavailable === true
      && FakeChart.instances.length === instanceCount
      && CoordinatorRestockingBiTab.template.includes('data-testid="restocking-bi-chart-unavailable"')
  );

  globalThis.window.Chart = FakeChart;
  component.renderCharts();
  assert(
    '5.8 Al volver el motor gráfico se limpia el aviso y se repinta',
    component.chartUnavailable === false && FakeChart.instances.length === instanceCount + 2
  );

  const emptyComponent = buildInstance();
  emptyComponent.report = {
    ...emptyComponent.report,
    charts: {
      spoilage_bars: { labels: [], keys: [], eur_values: [], unit_values: [], thermal_segmentable: true },
      top_critical_stockout: { by_machine: [], by_product: [] },
      top_critical_expiry: { by_machine: [], by_product: [] }
    }
  };
  const beforeEmpty = FakeChart.instances.length;
  emptyComponent.renderCharts();
  assert(
    '5.9 Un periodo vacío no instancia gráficos ni muestra un falso error',
    FakeChart.instances.length === beforeEmpty && emptyComponent.chartUnavailable === false
  );

  // ───────────────────────────────────────────────────────────────────────
  // 6. Exportación y errores
  // ───────────────────────────────────────────────────────────────────────
  console.log('\n--- 6. Exportación autenticada y errores ---');

  calls.length = 0;
  await component.exportCsv();

  assert(
    '6.1 La descarga CSV usa el cliente autenticado con los filtros vigentes',
    calls.length === 1
      && calls[0].method === 'downloadRestockingBiCsv'
      && !('zone_id' in calls[0].params)
      && calls[0].params.location_id === 3
      && component.isExporting === false
  );
  assert(
    '6.2 La descarga correcta deja confirmación visible',
    component.successMessage.includes('UTF-8 BOM')
  );

  const failingComponent = buildInstance();
  const realGet = api.getRestockingBi;
  api.getRestockingBi = async () => {
    throw new Error('El rango temporal máximo permitido es de 366 días naturales.');
  };
  await failingComponent.loadReport();
  api.getRestockingBi = realGet;

  assert(
    '6.3 Un 400 del servidor se declara en la banda de error sin romper la vista',
    failingComponent.errorMessage.includes('366')
      && failingComponent.isLoading === false
  );

  // ───────────────────────────────────────────────────────────────────────
  // 7. Integración, auditoría, PWA y sistema de diseño
  // ───────────────────────────────────────────────────────────────────────
  console.log('\n--- 7. Integración, auditoría, PWA y diseño ---');

  assert(
    '7.1 CoordinatorMetricsView registra el componente',
    CoordinatorMetricsView.components?.CoordinatorRestockingBiTab === CoordinatorRestockingBiTab
  );
  assert(
    '7.2 La sub-pestaña declara su testid y su etiqueta canónica',
    CoordinatorMetricsView.template.includes('data-testid="tab-restocking-bi"')
      && CoordinatorMetricsView.template.includes('📦 Métricas de Carga y Reposición')
  );
  assert(
    '7.3 La barra global de SAT se oculta solo en la pestaña de reposición (QA-3)',
    CoordinatorMetricsView.template.includes('v-if="activeSubTab !== \'restocking-bi\'"')
  );
  assert(
    '7.4 El bloque se monta con v-else-if después del registro de jornada',
    CoordinatorMetricsView.template.indexOf('data-testid="restocking-bi-block"')
      > CoordinatorMetricsView.template.indexOf('activeSubTab === \'work-hours\'')
      && CoordinatorMetricsView.template.includes('<CoordinatorRestockingBiTab />')
  );
  assert(
    '7.5 La etiqueta de auditoría cubre EXPORT_RESTOCKING_BI_CSV',
    AUDIT_ACTION_CATALOG.some(
      (entry) => entry.code === 'EXPORT_RESTOCKING_BI_CSV'
        && entry.label === 'Exportación CSV de Métricas de Carga y Reposición'
        && entry.group === 'restock'
        && entry.tone === 'neutral'
    )
  );
  assert(
    '7.6 El Service Worker precachea el componente y sube la caché a v33',
    swSource.includes('/assets/js/components/CoordinatorRestockingBiTab.js')
      && /const CACHE_NAME = 'vendguard-app-shell-v33'/.test(swSource)
  );
  assert(
    '7.7 El fichero precacheado existe en disco',
    existsSync(fileURLToPath(new URL('../../public/assets/js/components/CoordinatorRestockingBiTab.js', import.meta.url)))
  );
  assert(
    '7.8 El componente no introduce ningún hexadecimal',
    !/#[0-9a-fA-F]{3,8}\b/.test(componentSource)
  );
  assert(
    '7.9 El componente consume exclusivamente tokens de color, radio y tipografía',
    componentSource.includes('var(--color-primary-subtle)')
      && componentSource.includes('var(--color-surface-card)')
      && componentSource.includes('var(--radius-card)')
      && componentSource.includes('var(--font-display)')
      && componentSource.includes("'--font-body'")
  );
  assert(
    '7.10 Los catorce marcadores data-testid del contrato están presentes',
    [
      'restocking-bi-tab',
      'restocking-bi-filter-from',
      'restocking-bi-filter-to',
      'restocking-bi-filter-zone',
      'restocking-bi-filter-location',
      'restocking-bi-filter-restocker',
      'restocking-bi-apply-filters',
      'restocking-bi-export-csv',
      'restocking-bi-summary-cards',
      'restocking-bi-spoilage-chart',
      'restocking-bi-top-chart',
      'restocking-bi-thermal-notice',
      'restocking-bi-machines-table',
      'restocking-bi-products-table',
      'restocking-bi-restockers-table'
    ].every((testId) => CoordinatorRestockingBiTab.template.includes(`data-testid="${testId}"`))
  );
  assert(
    '7.11 El cliente API expone las dos operaciones del módulo',
    typeof api.getRestockingBi === 'function' && typeof api.downloadRestockingBiCsv === 'function'
  );
  assert(
    '7.12 El componente no abre ventanas ni compone URLs de descarga',
    !/window\.open|exportCsvUrl/.test(componentSource)
      && componentSource.includes('api.downloadRestockingBiCsv')
  );

  console.log('\n======================================================================');
  if (failures === 0) {
    console.log(` RESULTADO: ¡TODAS LAS PRUEBAS PASARON (${assertions} aserciones, 0 fallos)!`);
    console.log(' PESTAÑA DE MÉTRICAS DE CARGA Y REPOSICIÓN VERIFICADA.');
  } else {
    console.error(` RESULTADO: ${failures} PRUEBA(S) FALLIDA(S) de ${assertions} evaluadas.`);
  }
  console.log('======================================================================\n');

  if (failures > 0) {
    process.exit(1);
  }
}

runTests().catch((error) => {
  console.error('Error fatal en ejecución de tests:', error);
  process.exit(1);
});
