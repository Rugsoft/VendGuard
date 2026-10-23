/**
 * VendGuard - MetricCards & Integration Views Test Suite (MetricCardsTest.mjs)
 * 
 * Valida la integración de las vistas completas de Métricas y Auditoría (T-MET-15):
 * 1. CoordinatorDashboardView integra la pestaña "📊 Métricas y Auditoría" y CoordinatorMetricsView.
 * 2. TechnicianRouteView integra la sección "📈 Mis Métricas" y TechnicianMetricsView.
 * 3. CoordinatorMetricsView orquesta MetricCards, MetricBreakdownTable, AuditLogViewer y ExecutiveReportModal.
 * 4. TechnicianMetricsView muestra el MTTR personal del técnico, volumen y primera respuesta (Art. V.4).
 * 
 * Requisitos: RF-03, RF-04, RNF-05.
 * Hecho cuando:
 * - El panel de Coordinación dispone de la nueva sección "📊 Métricas y Auditoría".
 * - El panel de Técnico dispone de "📈 Mis Métricas".
 * - La suite node tests/unit/MetricCardsTest.mjs pasa al 100% en verde.
 */

// Mock de entorno browser para Node.js ESM
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
globalThis.window = {
  location: { search: '', href: 'http://localhost/' },
  open: () => {}
};

import { api, ApiClient } from '../../public/assets/js/api.js';
import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';
import { TechnicianRouteView } from '../../public/assets/js/views/TechnicianRouteView.js';
import { CoordinatorMetricsView } from '../../public/assets/js/views/CoordinatorMetricsView.js';
import { TechnicianMetricsView } from '../../public/assets/js/views/TechnicianMetricsView.js';

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
console.log(' VendGuard: Frontend Test Suite - Views Integration (T-MET-15)');
console.log('======================================================================\n');

// -------------------------------------------------------------
// 1. Integración en CoordinatorDashboardView
// -------------------------------------------------------------
console.log('--- 1. Panel de Coordinación: Pestaña "📊 Métricas y Auditoría" ---');

assert('1.1 CoordinatorMetricsView registrado en CoordinatorDashboardView.components', Boolean(CoordinatorDashboardView.components?.CoordinatorMetricsView));
assert('1.2 CoordinatorDashboardView template contiene botón de pestaña "📊 Métricas y Auditoría"', CoordinatorDashboardView.template.includes('📊 Métricas y Auditoría'));
assert('1.3 CoordinatorDashboardView template incluye data-testid="tab-metrics"', CoordinatorDashboardView.template.includes('data-testid="tab-metrics"'));
assert('1.4 CoordinatorDashboardView template renderiza <CoordinatorMetricsView', CoordinatorDashboardView.template.includes('<CoordinatorMetricsView'));

// -------------------------------------------------------------
// 2. Integración en TechnicianRouteView
// -------------------------------------------------------------
console.log('\n--- 2. Panel del Técnico: Sección "📈 Mis Métricas" ---');

assert('2.1 TechnicianMetricsView registrado en TechnicianRouteView.components', Boolean(TechnicianRouteView.components?.TechnicianMetricsView));
assert('2.2 TechnicianRouteView template contiene botón de sección "📈 Mis Métricas"', TechnicianRouteView.template.includes('📈 Mis Métricas'));
assert('2.3 TechnicianRouteView template incluye data-testid="tech-tab-metrics"', TechnicianRouteView.template.includes('data-testid="tech-tab-metrics"'));
assert('2.4 TechnicianRouteView template renderiza <TechnicianMetricsView', TechnicianRouteView.template.includes('<TechnicianMetricsView'));

// -------------------------------------------------------------
// 3. Orquestación en CoordinatorMetricsView
// -------------------------------------------------------------
console.log('\n--- 3. Orquestación en CoordinatorMetricsView ---');

assert('3.1 MetricCards registrado en CoordinatorMetricsView', Boolean(CoordinatorMetricsView.components?.MetricCards));
assert('3.2 MetricBreakdownTable registrado en CoordinatorMetricsView', Boolean(CoordinatorMetricsView.components?.MetricBreakdownTable));
assert('3.3 AuditLogViewer registrado en CoordinatorMetricsView', Boolean(CoordinatorMetricsView.components?.AuditLogViewer));
assert('3.4 ExecutiveReportModal registrado en CoordinatorMetricsView', Boolean(CoordinatorMetricsView.components?.ExecutiveReportModal));

// Simular carga de métricas en CoordinatorMetricsView
let summaryParams = null;
let breakdownParams = null;
api.metrics.getSummary = async (params) => {
  summaryParams = params;
  return {
    success: true,
    data: {
      kpis: { mttr_global_formatted: '2h 10m', resolution_rate_percentage: 88.0 },
      sla_alerts: { perishable_food: { status: 'COMPLIANT' } }
    }
  };
};
api.metrics.getBreakdown = async (params) => {
  breakdownParams = params;
  return {
    success: true,
    data: {
      by_location: [{ site_code: 'SEDE-01' }],
      by_technician: [{ technician_name: 'Jordi' }]
    }
  };
};
api.metrics.getCsat = async () => ({
  summary: { average: null, responses: 0, distribution: {} },
  by_site: [],
  by_technician: [],
  monthly_trend: []
});

const coordViewInstance = {
  ...CoordinatorMetricsView.data(),
  currentUser: { name: 'Sara', role: 'COORDINATOR' },
  loadMetrics: CoordinatorMetricsView.methods.loadMetrics,
  loadCsat: CoordinatorMetricsView.methods.loadCsat,
  handlePeriodChange: CoordinatorMetricsView.methods.handlePeriodChange,
  applyCustomRange: CoordinatorMetricsView.methods.applyCustomRange,
  exportMetricsCsv: CoordinatorMetricsView.methods.exportMetricsCsv
};

await coordViewInstance.loadMetrics();
assert('3.5 loadMetrics consulta API de summary y breakdown en paralelo', summaryParams?.period === 'last_30_days' && breakdownParams?.period === 'last_30_days');
assert('3.6 Resumen de KPIs asignado a la vista', coordViewInstance.summary.kpis?.mttr_global_formatted === '2h 10m');
assert('3.7 Desglose analítico asignado a la vista', coordViewInstance.breakdown.by_location?.length === 1);

// -------------------------------------------------------------
// 4. Autoconsulta Segregada en TechnicianMetricsView (Art. V.4)
// -------------------------------------------------------------
console.log('\n--- 4. Autoconsulta Segregada en TechnicianMetricsView (Art. V.4) ---');

let techReqParams = null;
api.metrics.getMyMetrics = async (params) => {
  techReqParams = params;
  return {
    success: true,
    data: {
      technician: { id: 2, name: 'Jordi' },
      metrics: {
        my_mttr_formatted: '2h 30m',
        my_mttr_hours: 2.5,
        total_resolved_tickets: 15,
        current_in_progress_tickets: 1,
        avg_first_response_formatted: '0h 35m'
      }
    }
  };
};

const techViewInstance = {
  ...TechnicianMetricsView.data(),
  loadMyMetrics: TechnicianMetricsView.methods.loadMyMetrics
};

await techViewInstance.loadMyMetrics();
assert('4.1 loadMyMetrics consulta endpoint de autoconsulta', techReqParams?.period === 'last_30_days');
assert('4.2 MTTR personal asignado a la vista del técnico', techViewInstance.metricsData.metrics?.my_mttr_formatted === '2h 30m');
assert('4.3 Incidencias en curso calculadas (1)', techViewInstance.metricsData.metrics?.current_in_progress_tickets === 1);
assert('4.4 Tiempo medio de primera respuesta asignado (0h 35m)', techViewInstance.metricsData.metrics?.avg_first_response_formatted === '0h 35m');

// -------------------------------------------------------------
// 5. Bloque CSAT del Cuadro de Mandos (Módulo 20, RF-06)
// -------------------------------------------------------------
console.log('\n--- 5. Bloque CSAT del Cuadro de Mandos (Módulo 20, RF-06) ---');

const csatPayload = {
  summary: {
    average: 4.17,
    responses: 6,
    distribution: { '1': 0, '2': 0, '3': 1, '4': 3, '5': 2 }
  },
  by_site: [
    { location_id: 1, site_code: 'SEDE-BCN-01', location_name: 'Hospital del Mar', average: 4.5, responses: 4 },
    { location_id: 2, site_code: 'SEDE-BCN-02', location_name: 'CAP Gràcia', average: 3.5, responses: 2 }
  ],
  by_technician: [
    { technician_id: 2, technician_name: 'Jordi', average: 4.2, responses: 5 },
    { technician_id: null, technician_name: 'Sin técnico', average: 4, responses: 1 }
  ],
  monthly_trend: [
    { month: '2026-09', average: 4, responses: 2 },
    { month: '2026-10', average: 4.25, responses: 4 }
  ]
};

/** Instancia con los métodos y las computadas del bloque CSAT enlazados. */
function createCsatView(overrides = {}) {
  const view = { ...CoordinatorMetricsView.data(), ...overrides };

  for (const name of ['loadMetrics', 'loadCsat', 'handlePeriodChange', 'applyCustomRange', 'exportMetricsCsv', 'csatBarWidth', 'formatCsatAverage']) {
    view[name] = CoordinatorMetricsView.methods[name].bind(view);
  }
  for (const [name, fn] of Object.entries(CoordinatorMetricsView.computed)) {
    Object.defineProperty(view, name, { get: () => fn.call(view), configurable: true });
  }

  return view;
}

// 5.1: el cliente apunta al endpoint real con el filtro vigente
const csatClient = new ApiClient('/api');
let csatUrl = null;
csatClient.get = async (endpoint) => { csatUrl = endpoint; return csatPayload; };
await csatClient.metrics.getCsat({ period: 'current_month' });
assert('5.1 api.metrics.getCsat consulta /coordinator/metrics/csat con el periodo',
  csatUrl === '/coordinator/metrics/csat?period=current_month');

// 5.2: la carga del cuadro de mandos pide el bloque con el mismo filtro (RF-06.2)
let csatParams = null;
api.metrics.getSummary = async () => ({ success: true, data: { kpis: { mttr_global_formatted: '2h 10m' } } });
api.metrics.getBreakdown = async () => ({ success: true, data: { by_location: [] } });
api.metrics.getCsat = async (params) => {
  csatParams = params;
  return csatPayload;
};

let csatView = createCsatView();
await csatView.loadMetrics();
assert('5.2 loadMetrics solicita el bloque CSAT con el periodo vigente',
  csatParams?.period === 'last_30_days' && csatView.csat?.summary?.responses === 6);
assert('5.3 El bloque CSAT no altera el resumen de KPIs ya cargado',
  csatView.summary?.kpis?.mttr_global_formatted === '2h 10m');
assert('5.4 El bloque deja de estar en carga', csatView.csatLoading === false && csatView.csatError === '');

// 5.5-5.8: resumen, media y distribución
csatView = createCsatView({ csat: csatPayload });
assert('5.5 El resumen expone media y respuestas',
  csatView.csatAverageLabel === '4.17' && csatView.csatResponses === 6 && csatView.csatHasResponses === true);
assert('5.6 Sin respuestas la media no pinta un cero',
  CoordinatorMetricsView.computed.csatAverageLabel.call({ csatSummary: { average: null, responses: 0 } }) === '—');
assert('5.7 La distribución recorre 5..1 con ceros rellenados',
  csatView.csatDistribution.map((row) => row.stars).join(',') === '5,4,3,2,1'
    && csatView.csatDistribution.map((row) => row.responses).join(',') === '2,3,1,0,0');
assert('5.8 Las barras se miden sobre el total de respuestas',
  csatView.csatBarWidth(2) === '33%' && csatView.csatBarWidth(3) === '50%'
    && CoordinatorMetricsView.methods.csatBarWidth.call({ csatResponses: 0 }, 0) === '0%');
assert('5.9 Las medias de grupo se formatean con dos decimales',
  csatView.formatCsatAverage(4) === '4.00' && csatView.formatCsatAverage(4.25) === '4.25'
    && csatView.formatCsatAverage(undefined) === '—');

// 5.10-5.12: grupos y estado vacío
assert('5.10 Los grupos llegan tal cual de la API',
  csatView.csatBySite.length === 2 && csatView.csatByTechnician[1].technician_name === 'Sin técnico'
    && csatView.csatMonthlyTrend[1].month === '2026-10');
csatView = createCsatView({ csat: {} });
assert('5.11 Sin bloque cargado los grupos son listas vacías',
  csatView.csatBySite.length === 0 && csatView.csatByTechnician.length === 0 && csatView.csatMonthlyTrend.length === 0);
assert('5.12 Sin respuestas se declara el periodo vacío',
  CoordinatorMetricsView.computed.csatHasResponses.call({ csatSummary: { responses: 0 } }) === false);

// 5.13-5.14: el periodo personalizado viaja al bloque
csatParams = null;
csatView = createCsatView({ selectedPeriod: 'custom', customFrom: '2026-09-01', customTo: '2026-09-30' });
await csatView.loadMetrics();
assert('5.13 El rango personalizado se envía también al bloque CSAT',
  csatParams?.period === 'custom' && csatParams?.from === '2026-09-01' && csatParams?.to === '2026-09-30');

let csatCalls = 0;
api.metrics.getCsat = async () => { csatCalls++; return csatPayload; };
csatView = createCsatView({ selectedPeriod: 'custom', customFrom: '2026-09-01', customTo: '' });
await csatView.loadMetrics();
assert('5.14 Un rango incompleto no dispara la petición del bloque', csatCalls === 0);

// 5.15-5.16: un fallo del bloque no tumba el cuadro de mandos
api.metrics.getCsat = async () => { throw new Error('CSAT no disponible'); };
csatView = createCsatView();
await csatView.loadMetrics();
assert('5.15 El fallo del bloque se declara en su propio estado',
  csatView.csatError === 'CSAT no disponible' && csatView.csatLoading === false);
assert('5.16 El fallo del bloque no borra las métricas de SLA',
  csatView.summary?.kpis?.mttr_global_formatted === '2h 10m' && csatView.errorMessage === '');

// 5.17-5.19: contrato de la pestaña
assert('5.17 La vista añade la subpestaña CSAT y su bloque',
  CoordinatorMetricsView.template.includes('⭐ Satisfacción del Cliente (CSAT)')
    && CoordinatorMetricsView.template.includes("activeSubTab === 'csat'")
    && CoordinatorMetricsView.template.includes('data-testid="csat-block"'));
assert('5.18 El estado vacío dice «Sin valoraciones en el periodo»',
  CoordinatorMetricsView.template.includes('Sin valoraciones en el periodo'));
const csatSection = CoordinatorMetricsView.template.slice(
  CoordinatorMetricsView.template.indexOf('data-testid="csat-block"'),
  CoordinatorMetricsView.template.indexOf('ExecutiveReportModal', CoordinatorMetricsView.template.indexOf('data-testid="csat-block"'))
);
assert('5.19 El bloque no ofrece exportación ni filtro territorial (RF-06.2, RF-06.4)',
  !csatSection.includes('Exportar') && !csatSection.includes('selectedLocation') && !csatSection.includes('province'));

// -------------------------------------------------------------
// 6. Superficie CSAT del técnico (incremento 20.1)
// -------------------------------------------------------------
console.log('\n--- 6. Superficie CSAT del técnico (incremento 20.1) ---');

// Evaluador mínimo de los `computed` sobre un doble de la vista: así se prueba la
// lógica de presentación sin montar Vue.
const computedOf = (instance) => Object.fromEntries(
  Object.entries(TechnicianMetricsView.computed).map(([key, fn]) => [key, fn.call(instance)])
);

// Referencia al método real antes de doblarlo: `api` es un objeto compartido y el
// doble de prueba lo sobrescribe.
const realGetMyCsat = api.metrics.getMyCsat;

let techCsatParams = null;
let techCsatResponse = null;

api.metrics.getMyCsat = async (params) => {
  techCsatParams = params;
  return { success: true, data: techCsatResponse };
};

const techCsatView = {
  ...TechnicianMetricsView.data(),
  loadMyCsat: TechnicianMetricsView.methods.loadMyCsat
};

techCsatResponse = {
  summary: { average: 4.33, responses: 6, distribution: { '1': 0, '2': 0, '3': 1, '4': 2, '5': 3 } },
  monthly_trend: [{ month: '2026-10', average: 4.67, responses: 3 }],
  threshold_met: true
};
await techCsatView.loadMyCsat();
let techCsatComputed = computedOf(techCsatView);

assert('6.1 La vista consulta el CSAT propio con el período seleccionado',
  techCsatParams?.period === 'last_30_days');
assert('6.2 La media se pinta con dos decimales y su conteo',
  techCsatComputed.csatAverage === '4.33' && techCsatView.csatData.summary.responses === 6);

const bars = techCsatComputed.csatDistribution;
assert('6.3 La distribución se ordena de cinco estrellas a una',
  bars.map((row) => row.stars).join(',') === '5,4,3,2,1');
assert('6.4 Cada barra calcula su porcentaje sobre las respuestas',
  bars.map((row) => row.percent).join(',') === '50,33,17,0,0',
  bars.map((row) => row.percent).join(','));
assert('6.5 La tendencia mensual llega tal cual la sirve el servidor',
  techCsatComputed.csatMonthlyTrend.length === 1
    && techCsatComputed.csatMonthlyTrend[0].month === '2026-10');
assert('6.6 Con valoraciones el estado no es de vacío', techCsatComputed.csatEmpty === false);

techCsatResponse = {
  summary: { average: null, responses: 2, distribution: { '1': 0, '2': 0, '3': 0, '4': 0, '5': 0 } },
  monthly_trend: [],
  threshold_met: false
};
await techCsatView.loadMyCsat();
techCsatComputed = computedOf(techCsatView);

assert('6.7 Por debajo del umbral no se publica ninguna media (RF-20.1.4)',
  techCsatComputed.csatAverage === null && techCsatComputed.csatEmpty === false);
assert('6.8 La plantilla declara el mensaje del umbral (RF-20.1.4)',
  TechnicianMetricsView.template.includes('Aún no hay valoraciones suficientes para mostrar una media'));

techCsatResponse = {
  summary: { average: null, responses: 0, distribution: { '1': 0, '2': 0, '3': 0, '4': 0, '5': 0 } },
  monthly_trend: [],
  threshold_met: false
};
await techCsatView.loadMyCsat();

assert('6.9 Sin valoraciones el estado es de vacío, nunca un cero (RF-20.1.3)',
  computedOf(techCsatView).csatEmpty === true
    && TechnicianMetricsView.template.includes('Aún no hay valoraciones en el periodo'));

api.metrics.getMyMetrics = async () => ({
  success: true,
  data: { metrics: { my_mttr_formatted: '2h 30m' } }
});
api.metrics.getMyCsat = async () => {
  throw new Error('CSAT no disponible');
};

const isolatedView = {
  ...TechnicianMetricsView.data(),
  loadMyMetrics: TechnicianMetricsView.methods.loadMyMetrics,
  loadMyCsat: TechnicianMetricsView.methods.loadMyCsat
};

await isolatedView.loadMyMetrics();
await isolatedView.loadMyCsat();

assert('6.10 El fallo del CSAT no borra el resto del panel (RF-20.1.5)',
  isolatedView.metricsData?.metrics?.my_mttr_formatted === '2h 30m'
    && isolatedView.errorMessage === '');
assert('6.11 El fallo se declara en el estado propio de la tarjeta',
  isolatedView.csatError === 'CSAT no disponible' && isolatedView.csatData === null);

const reloadedView = {
  calls: [],
  loadMyMetrics() { this.calls.push('metrics'); },
  loadMyCsat() { this.calls.push('csat'); }
};
TechnicianMetricsView.methods.reload.call(reloadedView);

assert('6.12 La recarga del panel pide las dos superficies',
  reloadedView.calls.join(',') === 'metrics,csat',
  reloadedView.calls.join(','));

assert('6.13 La tarjeta no expone comentarios, sedes ni códigos de sede (RF-20.1.6)',
  !TechnicianMetricsView.template.includes('by_site')
    && !TechnicianMetricsView.template.includes('site_code')
    && !TechnicianMetricsView.template.includes('comment'));

assert('6.14 El CSAT propio vive en su propio endpoint',
  typeof realGetMyCsat === 'function'
    && realGetMyCsat.toString().includes('/technician/my-metrics/csat'));

console.log('\n======================================================================');
console.log(` RESUMEN: ${assertions - failures} de ${assertions} aserciones superadas.`);
if (failures === 0) {
  console.log(' CONDICIÓN T-MET-15 VERIFICADA SATISFACTORIAMENTE.');
}
console.log('======================================================================\n');

if (failures > 0) {
  process.exit(1);
}
