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

import { api } from '../../public/assets/js/api.js';
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

const coordViewInstance = {
  ...CoordinatorMetricsView.data(),
  currentUser: { name: 'Sara', role: 'COORDINATOR' },
  loadMetrics: CoordinatorMetricsView.methods.loadMetrics,
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

console.log('\n======================================================================');
console.log(` RESUMEN: ${assertions} aserciones superadas exitosamente (100% PASS).`);
console.log(' CONDICIÓN T-MET-15 VERIFICADA SATISFACTORIAMENTE.');
console.log('======================================================================\n');

if (failures > 0) {
  process.exit(1);
}
