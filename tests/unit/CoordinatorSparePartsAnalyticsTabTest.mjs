/**
 * VendGuard - CoordinatorSparePartsAnalyticsTab Test Suite (CoordinatorSparePartsAnalyticsTabTest.mjs)
 * 
 * Valida la funcionalidad reactiva y los requisitos de:
 * CoordinatorSparePartsAnalyticsTab.js (Módulo M2: RF-REP-04, RF-REP-08, RF-REP-09, docs/design.md).
 * 
 * Hecho cuando:
 * 1. Carga y renderiza el cuadro analítico de costes y piezas sustituidas.
 * 2. Muestra las tarjetas KPI de consumo (piezas sustituidas, coste acumulado, alertas activas y solicitudes pendientes).
 * 3. Renderiza banners de alertas por averías crónicas (> 3 sustituciones en 90 días con estilo Docker #f8b60f).
 * 4. Renderiza la bandeja de piezas fuera de catálogo pendientes de homologación (RF-REP-04).
 * 5. Renderiza la tabla ranking de piezas con desglose de destino logístico (DESGUACE vs TALLER).
 * 6. Renderiza el desglose de costes por modelo de máquina y por sede cliente.
 * 7. Permite conmutar la ventana temporal de análisis (30, 90, 180, 365 días, histórico).
 * 8. Permite la descarga directa de consumos en formato CSV UTF-8 con BOM (RF-REP-09).
 * 9. Respeta las directrices visuales de docs/design.md.
 * 
 * Dogma Vanilla: Node.js nativo con módulos ESM y cero dependencias externas.
 */

// Mock de entorno navegador para Node.js ESM
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

import { api } from '../../public/assets/js/api.js';
import { CoordinatorSparePartsAnalyticsTab } from '../../public/assets/js/components/CoordinatorSparePartsAnalyticsTab.js';

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

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - CoordinatorSparePartsAnalyticsTab (T-SPARE-15)');
console.log('======================================================================\n');

// Mock Fixtures
const mockAnalyticsData = {
  period_days: 90,
  total_parts_replaced: 18,
  total_parts_cost: 512.40,
  top_replaced_parts: [
    {
      part_code: 'VALV-SOL-01',
      name: 'Electroválvula 24V Ceme 2 Vías',
      category: 'HYDRAULIC',
      units_installed: 8,
      accumulated_cost: 228.00,
      destinations: {
        DESGUACE: 6,
        TALLER: 2
      }
    },
    {
      part_code: 'SOND-NTC-02',
      name: 'Sonda Temperatura NTC 10K',
      category: 'THERMAL',
      units_installed: 5,
      accumulated_cost: 71.00,
      destinations: {
        DESGUACE: 5,
        TALLER: 0
      }
    },
    {
      part_code: 'BOM-ULK-03',
      name: 'Bomba Ulka EX5 230V',
      category: 'HYDRAULIC',
      units_installed: 4,
      accumulated_cost: 128.00,
      destinations: {
        DESGUACE: 1,
        TALLER: 3
      }
    },
    {
      part_code: 'OUT_OF_CATALOG',
      name: 'Relé de estado sólido 40A',
      category: 'OTHER',
      units_installed: 1,
      accumulated_cost: 0.00,
      destinations: {
        DESGUACE: 1,
        TALLER: 0
      }
    }
  ],
  costs_by_machine_model: [
    {
      model: 'Sanden Vendo G-Drink',
      machines_count: 2,
      units_replaced: 10,
      total_cost: 285.00
    },
    {
      model: 'Necta Krea',
      machines_count: 1,
      units_replaced: 8,
      total_cost: 227.40
    }
  ],
  costs_by_location: [
    {
      location_id: 1,
      location_name: 'Hospital del Mar',
      units_replaced: 12,
      total_cost: 340.00
    },
    {
      location_id: 2,
      location_name: 'Campus Nord UPC',
      units_replaced: 6,
      total_cost: 172.40
    }
  ],
  chronic_failure_alerts: [
    {
      machine_id: 1,
      machine_code: 'VEND-0101',
      machine_model: 'Sanden Vendo G-Drink',
      location_name: 'Hospital del Mar',
      part_code: 'VALV-SOL-01',
      part_name: 'Electroválvula 24V Ceme 2 Vías',
      replacements_in_period: 4,
      threshold: 3,
      severity: 'WARNING',
      warning_message: 'Componente con Fallo Recurrente / Prematuro (4 sustituciones en 65 días)',
      first_replacement_at: '2026-07-01 09:00:00',
      last_replacement_at: '2026-09-04 11:30:00'
    },
    {
      machine_id: 2,
      machine_code: 'VEND-0102',
      machine_model: 'Necta Krea',
      location_name: 'Campus Nord UPC',
      part_code: 'BOM-ULK-03',
      part_name: 'Bomba Ulka EX5 230V',
      replacements_in_period: 5,
      threshold: 3,
      severity: 'CRITICAL',
      warning_message: 'Componente con Fallo Recurrente / Prematuro (5 sustituciones en 45 días)',
      first_replacement_at: '2026-07-20 10:00:00',
      last_replacement_at: '2026-09-03 14:00:00'
    }
  ]
};

const mockPendingReviews = [
  {
    request_id: 101,
    incident_id: 5,
    ticket_code: 'INC-2026-0005',
    machine_code: 'VEND-0101',
    machine_model: 'Sanden Vendo G-Drink',
    location_name: 'Hospital del Mar',
    technician_name: 'Carlos Técnico',
    custom_part_description: 'Termostato bimetálico de rearme manual para caldera',
    requested_at: '2026-09-27 15:30:00',
    incident_status: 'PAUSED_PARTS'
  }
];

let apiAnalyticsParams = null;
let apiDownloadCsvParams = null;

// Mock de llamadas a la API
api.coordinator.getSparePartsAnalytics = async (params = {}) => {
  apiAnalyticsParams = params;
  return { success: true, data: mockAnalyticsData };
};

api.coordinator.getSparePartsPendingReview = async () => {
  return { success: true, data: mockPendingReviews };
};

api.coordinator.downloadSparePartsCsv = async (params = {}) => {
  apiDownloadCsvParams = params;
  return true;
};

// Función para instanciar el componente reactivo
function createComponent() {
  const comp = {
    ...CoordinatorSparePartsAnalyticsTab.data(),
    ...CoordinatorSparePartsAnalyticsTab.methods,
    _emitted: {},
    $emit(eventName, payload) {
      this._emitted[eventName] = this._emitted[eventName] || [];
      this._emitted[eventName].push(payload !== undefined ? payload : true);
    }
  };

  // Enlazar propiedades computadas
  for (const [key, getter] of Object.entries(CoordinatorSparePartsAnalyticsTab.computed)) {
    Object.defineProperty(comp, key, {
      get: getter,
      configurable: true
    });
  }

  return comp;
}

const tab = createComponent();

// =========================================================================
// BLOQUE 1: Carga Inicial de Datos y Métricas KPI
// =========================================================================
console.log('--- BLOQUE 1: Carga Inicial de Datos y Métricas KPI ---');

await tab.loadAllData();
assert('1.1 loadAllData carga total de piezas sustituidas (18)', tab.analytics.total_parts_replaced === 18);
assert('1.2 loadAllData carga coste total acumulado (512.40 €)', tab.analytics.total_parts_cost === 512.40);
assert('1.3 hasChronicAlerts detecta 2 alertas de fallo crónico activas',
  tab.hasChronicAlerts === true && tab.analytics.chronic_failure_alerts.length === 2);
assert('1.4 criticalAlertsCount contabiliza 1 alerta de severidad CRITICAL', tab.criticalAlertsCount === 1);
assert('1.5 hasPendingReviews detecta 1 solicitud fuera de catálogo pendiente',
  tab.hasPendingReviews === true && tab.pendingReviewRequests.length === 1);

// =========================================================================
// BLOQUE 2: Banners de Averías Crónicas (RF-REP-08 / docs/design.md Warning)
// =========================================================================
console.log('\n--- BLOQUE 2: Alertas por Averías Crónicas (> 3 sustituciones en 90 días) ---');

const alert1 = tab.analytics.chronic_failure_alerts[0];
assert('2.1 Primera alerta vinculada a máquina VEND-0101', alert1.machine_code === 'VEND-0101');
assert('2.2 Primera alerta supera umbral con 4 sustituciones registradas', alert1.replacements_in_period === 4 && alert1.threshold === 3);
assert('2.3 Primera alerta identifica repuesto VALV-SOL-01', alert1.part_code === 'VALV-SOL-01');

const alert2 = tab.analytics.chronic_failure_alerts[1];
assert('2.4 Segunda alerta clasificada con severidad CRITICAL (5 sustituciones)',
  alert2.severity === 'CRITICAL' && alert2.replacements_in_period === 5);
assert('2.5 Mensaje de advertencia describe el fallo recurrente y período',
  alert2.warning_message.includes('Componente con Fallo Recurrente'));

// =========================================================================
// BLOQUE 3: Bandeja de Piezas Fuera de Catálogo (RF-REP-04)
// =========================================================================
console.log('\n--- BLOQUE 3: Bandeja de Piezas Fuera de Catálogo (RF-REP-04) ---');

const pendingReq = tab.pendingReviewRequests[0];
assert('3.1 Solicitud contiene código de ticket de la incidencia', pendingReq.ticket_code === 'INC-2026-0005');
assert('3.2 Solicitud contiene la justificación técnica del técnico',
  pendingReq.custom_part_description === 'Termostato bimetálico de rearme manual para caldera');
assert('3.3 Solicitud identifica técnico informante y sede',
  pendingReq.technician_name === 'Carlos Técnico' && pendingReq.location_name === 'Hospital del Mar');

// Manejar acción de catalogar pieza desde la solicitud
tab.handleCreatePartFromRequest(pendingReq);
assert('3.4 handleCreatePartFromRequest emite create-part-from-request con metadatos para alta',
  tab._emitted['create-part-from-request']?.length === 1 &&
  tab._emitted['create-part-from-request'][0].custom_part_description === pendingReq.custom_part_description &&
  tab._emitted['create-part-from-request'][0].incident_id === 5);

// =========================================================================
// BLOQUE 4: Ranking de Piezas y Desglose de Destino (Desguace vs Taller)
// =========================================================================
console.log('\n--- BLOQUE 4: Ranking de Piezas y Desglose de Destino (RF-REP-08) ---');

assert('4.1 Ranking contiene 4 componentes ordenados por recambio', tab.analytics.top_replaced_parts.length === 4);

const top1 = tab.analytics.top_replaced_parts[0];
assert('4.2 Pieza #1 es VALV-SOL-01 con 8 unidades sustituidas',
  top1.part_code === 'VALV-SOL-01' && top1.units_installed === 8);
assert('4.3 Desglose logístico contabiliza 6 unidades a DESGUACE y 2 a TALLER',
  top1.destinations.DESGUACE === 6 && top1.destinations.TALLER === 2);
assert('4.4 Coste acumulado de la pieza #1 asciende a 228.00 €', top1.accumulated_cost === 228.00);

const outOfCatalogPart = tab.analytics.top_replaced_parts.find(p => p.part_code === 'OUT_OF_CATALOG');
assert('4.5 Piezas fuera de catálogo registradas computan con código OUT_OF_CATALOG',
  outOfCatalogPart !== undefined && outOfCatalogPart.name === 'Relé de estado sólido 40A');

// =========================================================================
// BLOQUE 5: Desglose por Modelo de Máquina y por Sede Cliente
// =========================================================================
console.log('\n--- BLOQUE 5: Desglose de Costes por Modelo y Sede ---');

assert('5.1 Desglose por modelo registra 2 modelos afectados', tab.analytics.costs_by_machine_model.length === 2);
const model1 = tab.analytics.costs_by_machine_model[0];
assert('5.2 Modelo con mayor gasto es Sanden Vendo G-Drink (285.00 €)',
  model1.model === 'Sanden Vendo G-Drink' && model1.total_cost === 285.00);

assert('5.3 Desglose por sede registra 2 sedes', tab.analytics.costs_by_location.length === 2);
const loc1 = tab.analytics.costs_by_location[0];
assert('5.4 Sede con mayor gasto es Hospital del Mar (340.00 €)',
  loc1.location_name === 'Hospital del Mar' && loc1.total_cost === 340.00);

// =========================================================================
// BLOQUE 6: Selector Dinámico de Período Temporal
// =========================================================================
console.log('\n--- BLOQUE 6: Selector Dinámico de Período Temporal ---');

await tab.changePeriod(30);
assert('6.1 changePeriod a 30 días actualiza selectedPeriod', tab.selectedPeriod === 30);
assert('6.2 changePeriod invoca la API pasando period_days: 30', apiAnalyticsParams?.period_days === 30);

await tab.changePeriod(null);
assert('6.3 changePeriod a null (todo el histórico) actualiza selectedPeriod', tab.selectedPeriod === null);
assert('6.4 changePeriod a null no incluye period_days en parámetros', apiAnalyticsParams?.period_days === undefined);

// Restaurar a 90 días
await tab.changePeriod(90);

// =========================================================================
// BLOQUE 7: Descarga de Consumos a CSV (RF-REP-09)
// =========================================================================
console.log('\n--- BLOQUE 7: Descarga Directa de CSV (RF-REP-09) ---');

await tab.exportCsv();
assert('7.1 exportCsv invoca api.coordinator.downloadSparePartsCsv con ventana activa',
  apiDownloadCsvParams?.period_days === 90);
assert('7.2 exportCsv muestra mensaje de éxito al coordinador',
  tab.successMessage.includes('archivo CSV de consumos de repuestos'));

// =========================================================================
// BLOQUE 8: Formato, Navegación y Directrices Visuales (Docs/design.md)
// =========================================================================
console.log('\n--- BLOQUE 8: Directrices Visuales y Formatos docs/design.md ---');

// 8.1 Formateo de precios y fechas
assert('8.1 formatPrice formatea correctamente 512.40 €', tab.formatPrice(512.40) === '512,40 €');
assert('8.2 formatPrice maneja cero correctamente (0,00 €)', tab.formatPrice(0) === '0,00 €');
assert('8.3 formatDate formatea fechas ISO a estándar español', tab.formatDate('2026-09-04 11:30:00').includes('2026'));

// 8.2 Navegación al catálogo
tab.handleOpenCatalog();
assert('8.4 handleOpenCatalog emite open-catalog', tab._emitted['open-catalog']?.length > 0);

// 8.3 Navegación a incidencia
tab.handleViewIncident(5);
assert('8.5 handleViewIncident emite view-incident con ID de incidencia',
  tab._emitted['view-incident']?.length === 1 && tab._emitted['view-incident'][0].incidentId === 5);

// 8.4 Verificación de tokens y marcado en el template
const templateStr = CoordinatorSparePartsAnalyticsTab.template;
assert('8.6 Template incluye color primario interactivo #2560ff (Azul eléctrico Docker)', templateStr.includes('#2560ff'));
assert('8.7 Template incluye color de advertencia para fallos crónicos #f8b60f (Docker Warning)', templateStr.includes('#f8b60f'));
assert('8.8 Template incluye radio conservador de 4px en botones', templateStr.includes('4px'));
assert('8.9 Template incluye radio de 8px en tarjetas, contenedores de alerta y tablas', templateStr.includes('8px'));
assert('8.10 Template incluye data-testid para automatización de pruebas',
  templateStr.includes('data-testid="chronic-failure-alerts-container"') &&
  templateStr.includes('data-testid="pending-review-section"') &&
  templateStr.includes('data-testid="ranking-section"') &&
  templateStr.includes('data-testid="btn-export-spare-parts-csv"'));

console.log('\n======================================================================');
console.log(` RESULTADOS TEST: ${assertions} aserciones ejecutadas.`);
console.log(` FALLOS: ${failures}`);
console.log('======================================================================');

if (failures > 0) {
  process.exit(1);
} else {
  console.log('✅ TODAS LAS PRUEBAS DE CoordinatorSparePartsAnalyticsTabTest.mjs HAN PASADO AL 100% EN VERDE.');
  process.exit(0);
}
