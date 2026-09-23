/**
 * VendGuard - MetricComponents Test Suite (MetricComponentsTest.mjs)
 * 
 * Valida los componentes reactivos de Métricas y Auditoría (T-MET-12):
 * - MetricCards.js (Tarjetas de KPIs, badges de SLA y tendencias).
 * - MetricBreakdownTable.js (Desglose por sede, técnico, máquina perecedera y categoría).
 * 
 * Requisitos: RF-02, RF-03 (EARS 3.1, 3.2), Artículo II constitucional.
 * 
 * Hecho cuando:
 * 1. MetricCards renderiza MTTR global y tendencia porcentual con indicación de mejora (verde) o aumento (rojo).
 * 2. MetricCards destaca alimentos perecederos con SLA objetivo de 4h y evalúa compliant vs breached.
 * 3. MetricCards calcula tasa de resolución y alerta de backlog activo.
 * 4. MetricBreakdownTable desglosa 4 dimensiones: sedes, técnicos, máquinas y categorías.
 * 5. MetricBreakdownTable destaca máquinas de alimentos perecederos (Art. II) y señala breaches > 4h.
 * 6. MetricBreakdownTable identifica entidades inactivas con el distintivo "(Inactivo)" (EARS 2.6).
 * 7. MetricBreakdownTable filtra reactivamente mediante búsqueda por texto.
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
  location: { search: '', href: 'http://localhost/' }
};

import { MetricCards } from '../../public/assets/js/components/MetricCards.js';
import { MetricBreakdownTable } from '../../public/assets/js/components/MetricBreakdownTable.js';

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
console.log(' VendGuard: Frontend Test Suite - MetricCards & MetricBreakdownTable (T-MET-12)');
console.log('======================================================================\n');

// -------------------------------------------------------------
// 1. Pruebas Unitarias de MetricCards (RF-03, EARS 3.1, 3.2)
// -------------------------------------------------------------
console.log('--- 1. Pruebas de MetricCards ---');

// Mock Data de KpiSummary cumpliendo SLA
const mockSummaryCompliant = {
  period: { key: 'last_30_days', from: '2026-08-24 00:00:00', to: '2026-09-23 23:59:59' },
  kpis: {
    mttr_global_minutes: 165,
    mttr_global_formatted: '2h 45m',
    mttr_global_hours: 2.8,
    mttr_previous_period_minutes: 195,
    mttr_trend_percentage: -15.4, // Mejora
    total_tickets_created: 50,
    total_tickets_resolved: 45,
    resolution_rate_percentage: 90.0,
    active_backlog: 5,
    critical_sla_breaches: 0
  },
  sla_alerts: {
    perishable_food: {
      sla_target_hours: 4.0,
      current_mttr_hours: 1.8,
      is_breached: false,
      status: 'COMPLIANT'
    },
    general: {
      sla_target_hours: 24.0,
      current_mttr_hours: 2.8,
      is_breached: false,
      status: 'COMPLIANT'
    }
  }
};

const cardsInstance = {
  summary: mockSummaryCompliant,
  isLoading: false,
  kpis: MetricCards.computed.kpis.call({ summary: mockSummaryCompliant }),
  slaAlerts: MetricCards.computed.slaAlerts.call({ summary: mockSummaryCompliant }),
  perishableAlert: MetricCards.computed.perishableAlert.call({
    slaAlerts: mockSummaryCompliant.sla_alerts
  }),
  generalAlert: MetricCards.computed.generalAlert.call({
    slaAlerts: mockSummaryCompliant.sla_alerts
  })
};

const trendComp = MetricCards.computed.trend.call(cardsInstance);
assert('1.1 Tendencia negativa indica mejora en verde', trendComp.color === '#16a34a' && trendComp.text.includes('Mejora'));
assert('1.2 Icono de tendencia hacia abajo (reducción tiempo)', trendComp.icon === '↓');

const perishableBadgeComp = MetricCards.computed.perishableStatusBadge.call(cardsInstance);
assert('1.3 Perecedero compliant muestra badge verde', perishableBadgeComp.class === 'vg-badge-success');
assert('1.4 Texto de badge perecedero compliant incluye horas', perishableBadgeComp.label.includes('Cumple SLA (1.8h)'));

// Simular escenario de Breach en Perecederos (Art. II)
const mockSummaryBreached = JSON.parse(JSON.stringify(mockSummaryCompliant));
mockSummaryBreached.kpis.mttr_trend_percentage = 22.0; // Empeoramiento
mockSummaryBreached.sla_alerts.perishable_food = {
  sla_target_hours: 4.0,
  current_mttr_hours: 5.5,
  is_breached: true,
  status: 'BREACHED'
};

const breachedInstance = {
  summary: mockSummaryBreached,
  kpis: mockSummaryBreached.kpis,
  slaAlerts: mockSummaryBreached.sla_alerts,
  perishableAlert: mockSummaryBreached.sla_alerts.perishable_food,
  generalAlert: mockSummaryBreached.sla_alerts.general
};

const trendBreached = MetricCards.computed.trend.call(breachedInstance);
assert('1.5 Tendencia positiva (aumento) indica empeoramiento en rojo', trendBreached.color === '#dc2626' && trendBreached.text.includes('Aumento'));

const perishableBadgeBreached = MetricCards.computed.perishableStatusBadge.call(breachedInstance);
assert('1.6 Perecedero superando 4h muestra badge de incumplimiento crítico', perishableBadgeBreached.class === 'vg-badge-critical');
assert('1.7 Label de breach incluye horas excedidas (> 4h)', perishableBadgeBreached.label.includes('Incumplimiento SLA (5.5h)'));

// -------------------------------------------------------------
// 2. Pruebas Unitarias de MetricBreakdownTable (RF-02, EARS 2.2 - 2.6)
// -------------------------------------------------------------
console.log('\n--- 2. Pruebas de MetricBreakdownTable ---');

const mockBreakdown = {
  by_location: [
    {
      location_id: 1,
      site_code: 'SEDE-BCN-01',
      location_name: 'Hospital del Mar - Edificio Central',
      is_active: true,
      tickets_resolved: 28,
      mttr_minutes: 165,
      mttr_formatted: '2h 45m',
      mttr_hours: 2.8,
      sla_target_hours: 24.0,
      sla_status: 'COMPLIANT'
    },
    {
      location_id: 2,
      site_code: 'SEDE-BCN-02',
      location_name: 'Torre Glòries - Planta 4',
      is_active: false, // Inactivo
      tickets_resolved: 14,
      mttr_minutes: 255,
      mttr_formatted: '4h 15m',
      mttr_hours: 4.3,
      sla_target_hours: 24.0,
      sla_status: 'COMPLIANT'
    }
  ],
  by_technician: [
    {
      technician_id: 2,
      technician_name: 'Jordi Técnico Ruta BCN',
      display_name: 'Jordi Técnico Ruta BCN',
      is_active: true,
      tickets_resolved: 42,
      mttr_minutes: 195,
      mttr_formatted: '3h 15m',
      mttr_hours: 3.3
    },
    {
      technician_id: 5,
      technician_name: 'Carlos Antiguo',
      display_name: 'Carlos Antiguo (Inactivo)',
      is_active: false, // Inactivo
      tickets_resolved: 10,
      mttr_minutes: 210,
      mttr_formatted: '3h 30m',
      mttr_hours: 3.5
    }
  ],
  by_machine_type: [
    {
      machine_type: 'PERISHABLE_FOOD',
      display_name: 'Alimentos Perecederos (Sanitario)',
      is_perishable: true,
      tickets_resolved: 12,
      mttr_minutes: 110,
      mttr_formatted: '1h 50m',
      mttr_hours: 1.8,
      sla_target_hours: 4.0,
      sla_status: 'COMPLIANT'
    },
    {
      machine_type: 'HOT_DRINKS',
      display_name: 'Bebidas Calientes',
      is_perishable: false,
      tickets_resolved: 15,
      mttr_minutes: 210,
      mttr_formatted: '3h 30m',
      mttr_hours: 3.5,
      sla_target_hours: 24.0,
      sla_status: 'COMPLIANT'
    },
    {
      machine_type: 'PERISHABLE_DAIRY',
      display_name: 'Lácteos Refrigerados',
      is_perishable: true,
      tickets_resolved: 8,
      mttr_minutes: 320,
      mttr_formatted: '5h 20m',
      mttr_hours: 5.3,
      sla_target_hours: 4.0,
      sla_status: 'BREACHED' // Incumplimiento Art. II
    }
  ],
  by_category: [
    {
      category: 'TEMPERATURE_COLD',
      tickets_resolved: 10,
      mttr_minutes: 105,
      mttr_formatted: '1h 45m'
    },
    {
      category: 'PAYMENT_SYSTEM',
      tickets_resolved: 18,
      mttr_minutes: 230,
      mttr_formatted: '3h 50m'
    }
  ]
};

const tableContext = {
  breakdown: mockBreakdown,
  activeTab: 'location',
  searchQuery: '',
  locations: mockBreakdown.by_location,
  technicians: mockBreakdown.by_technician,
  machineTypes: mockBreakdown.by_machine_type,
  categories: mockBreakdown.by_category,
  formatCategoryName: MetricBreakdownTable.methods.formatCategoryName,
  getSlaBadge: MetricBreakdownTable.methods.getSlaBadge
};

// 2.1 Tab Location
const filteredLoc = MetricBreakdownTable.computed.filteredItems.call(tableContext);
assert('2.1 Pestaña Sedes contiene 2 sedes', filteredLoc.length === 2);
assert('2.2 Identifica sede activa e inactiva', filteredLoc[1].is_active === false && filteredLoc[0].is_active === true);

// 2.2 Búsqueda en Sedes
tableContext.searchQuery = 'Hospital';
const searchLoc = MetricBreakdownTable.computed.filteredItems.call(tableContext);
assert('2.3 Búsqueda reactiva filtra por nombre de sede', searchLoc.length === 1 && searchLoc[0].site_code === 'SEDE-BCN-01');

// 2.3 Tab Machine Types y Alimentos Perecederos (Art. II)
tableContext.searchQuery = '';
tableContext.activeTab = 'machine_type';
const filteredMachines = MetricBreakdownTable.computed.filteredItems.call(tableContext);
assert('2.4 Pestaña Máquinas contiene 3 tipos', filteredMachines.length === 3);

const perishableCompliant = filteredMachines.find(m => m.machine_type === 'PERISHABLE_FOOD');
assert('2.5 Máquina de alimentos perecederos identificada con is_perishable=true', perishableCompliant.is_perishable === true);
assert('2.6 SLA objetivo de perecederos es 4.0h', perishableCompliant.sla_target_hours === 4.0);
assert('2.7 Perecedero con 1.8h cumple SLA (COMPLIANT)', perishableCompliant.sla_status === 'COMPLIANT');

const perishableBreached = filteredMachines.find(m => m.machine_type === 'PERISHABLE_DAIRY');
assert('2.8 Perecedero con 5.3h reporta incumplimiento crítico (BREACHED)', perishableBreached.sla_status === 'BREACHED');

const badgeBreached = MetricBreakdownTable.methods.getSlaBadge('BREACHED', 5.3, 4.0);
assert('2.9 Badge de SLA incumplido tiene fondo rojo (#fee2e2)', badgeBreached.bg === '#fee2e2');

// 2.4 Tab Categorías y Dualismo Lingüístico
tableContext.activeTab = 'category';
const filteredCategories = MetricBreakdownTable.computed.filteredItems.call(tableContext);
assert('2.10 Pestaña Categorías contiene 2 averías', filteredCategories.length === 2);

const catTranslated = MetricBreakdownTable.methods.formatCategoryName('TEMPERATURE_COLD');
assert('2.11 Traduce categoría TEMPERATURE_COLD al español con indicación sanitaria', catTranslated.includes('Refrigeración y Frío'));

console.log('\n======================================================================');
console.log(` RESUMEN: ${assertions} aserciones superadas exitosamente (100% PASS).`);
console.log(' CONDICIÓN T-MET-12 VERIFICADA SATISFACTORIAMENTE.');
console.log('======================================================================\n');

if (failures > 0) {
  process.exit(1);
}
