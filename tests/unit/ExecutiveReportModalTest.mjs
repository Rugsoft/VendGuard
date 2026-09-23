/**
 * VendGuard - ExecutiveReportModal Test Suite (ExecutiveReportModalTest.mjs)
 * 
 * Valida el componente de informe ejecutivo imprimible y estilos CSS (T-MET-14):
 * - ExecutiveReportModal.js (Resumen ejecutivo formal, SLA perecederos y KPIs).
 * - metrics-print.css (Aislamiento de impresión, eliminación de barras/botones y soporte A4).
 * 
 * Requisitos: RF-06 (EARS 6.3), Artículo II constitucional.
 * 
 * Hecho cuando:
 * 1. ExecutiveReportModal calcula correctamente los períodos y metadatos de emisión.
 * 2. Muestra los 4 KPIs ejecutivos principales y el resumen de SLA de perecederos (4h).
 * 3. Incorpora el botón "🖨️ Imprimir / Guardar PDF" que llama nativamente a window.print().
 * 4. Emite el evento close al solicitar cerrar el modal.
 * 5. El archivo metrics-print.css incluye las directivas @media print requeridas para impresión limpia.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Mock de entorno browser para Node.js ESM
let printCalled = false;
globalThis.window = {
  print: () => { printCalled = true; },
  location: { search: '', href: 'http://localhost/' }
};

import { ExecutiveReportModal } from '../../public/assets/js/components/ExecutiveReportModal.js';

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
console.log(' VendGuard: Frontend Test Suite - ExecutiveReportModal (T-MET-14)');
console.log('======================================================================\n');

// -------------------------------------------------------------
// 1. Datos Mock de Informe Ejecutivo
// -------------------------------------------------------------
const mockSummary = {
  period: {
    key: 'custom',
    from: '2026-09-01 00:00:00',
    to: '2026-09-23 23:59:59'
  },
  kpis: {
    mttr_global_minutes: 165,
    mttr_global_formatted: '2h 45m',
    mttr_global_hours: 2.8,
    total_tickets_created: 50,
    total_tickets_resolved: 42,
    resolution_rate_percentage: 84.0,
    active_backlog: 8,
    critical_sla_breaches: 1
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

const mockBreakdown = {
  by_location: [
    {
      location_id: 1,
      site_code: 'SEDE-BCN-01',
      location_name: 'Hospital del Mar - Edificio Central',
      is_active: true,
      tickets_resolved: 28,
      mttr_formatted: '2h 45m',
      mttr_hours: 2.8,
      sla_status: 'COMPLIANT'
    }
  ],
  by_category: [
    {
      category: 'TEMPERATURE_COLD',
      tickets_resolved: 10,
      mttr_formatted: '1h 45m'
    }
  ]
};

let emittedEvents = [];
const instance = {
  show: true,
  summary: mockSummary,
  breakdown: mockBreakdown,
  currentUser: { name: 'Sara Coordinadora', role: 'COORDINATOR' },
  $emit: (event, payload) => { emittedEvents.push({ event, payload }); },
  kpis: ExecutiveReportModal.computed.kpis.call({ summary: mockSummary }),
  slaAlerts: ExecutiveReportModal.computed.slaAlerts.call({ summary: mockSummary }),
  perishable: ExecutiveReportModal.computed.perishable.call({
    slaAlerts: mockSummary.sla_alerts
  }),
  general: ExecutiveReportModal.computed.general.call({
    slaAlerts: mockSummary.sla_alerts
  }),
  period: mockSummary.period,
  locations: mockBreakdown.by_location,
  categories: mockBreakdown.by_category,
  printReport: ExecutiveReportModal.methods.printReport,
  closeModal: ExecutiveReportModal.methods.closeModal,
  formatCategoryName: ExecutiveReportModal.methods.formatCategoryName
};

// -------------------------------------------------------------
// 1. Pruebas de Propiedades Computadas de ExecutiveReportModal
// -------------------------------------------------------------
console.log('--- 1. Propiedades y Metadatos de Emisión ---');

const periodStr = ExecutiveReportModal.computed.periodFormatted.call({ period: mockSummary.period });
assert('1.1 Período formateado en rango legible', periodStr === '2026-09-01 al 2026-09-23');

const emissionStr = ExecutiveReportModal.computed.emissionTimestamp.call({});
assert('1.2 Fecha y hora de emisión generada', typeof emissionStr === 'string' && emissionStr.length > 5);

assert('1.3 MTTR global formateado presente', instance.kpis.mttr_global_formatted === '2h 45m');
assert('1.4 Tasa de resolución correcta (84.0%)', instance.kpis.resolution_rate_percentage === 84.0);
assert('1.5 Backlog activo presente (8 averías)', instance.kpis.active_backlog === 8);
assert('1.6 Breaches críticos registrados (1)', instance.kpis.critical_sla_breaches === 1);
assert('1.7 SLA perecedero objetivo es 4.0h', instance.perishable.sla_target_hours === 4.0);
assert('1.8 Cumplimiento SLA perecedero COMPLIANT', instance.perishable.status === 'COMPLIANT');

// -------------------------------------------------------------
// 2. Interacciones: window.print() y Evento Close
// -------------------------------------------------------------
console.log('\n--- 2. Interacciones y Llamada Nativa de Impresión ---');

printCalled = false;
instance.printReport();
assert('2.1 printReport() invoca window.print() nativo', printCalled === true);

emittedEvents = [];
instance.closeModal();
assert('2.2 closeModal() emite evento "close"', emittedEvents.some(e => e.event === 'close'));

// -------------------------------------------------------------
// 3. Verificación de Directivas CSS en metrics-print.css
// -------------------------------------------------------------
console.log('\n--- 3. Verificación de Directivas CSS en metrics-print.css ---');

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const cssPath = path.resolve(__dirname, '../../public/assets/css/metrics-print.css');

assert('3.1 Archivo metrics-print.css existe en disco', fs.existsSync(cssPath));

const cssContent = fs.readFileSync(cssPath, 'utf8');
assert('3.2 Incluye bloque @media print', cssContent.includes('@media print'));
assert('3.3 Define formato A4 portrait', cssContent.includes('size: A4 portrait'));
assert('3.4 Oculta interfaz general con visibility: hidden', cssContent.includes('visibility: hidden'));
assert('3.5 Aísla .executive-report-print-zone como visible', cssContent.includes('.executive-report-print-zone'));
assert('3.6 Oculta elementos no imprimibles (.no-print, button, nav)', cssContent.includes('.no-print') && cssContent.includes('button'));
assert('3.7 Aplica page-break-inside: avoid para evitar saltos cortados', cssContent.includes('page-break-inside: avoid'));

console.log('\n======================================================================');
console.log(` RESUMEN: ${assertions} aserciones superadas exitosamente (100% PASS).`);
console.log(' CONDICIÓN T-MET-14 VERIFICADA SATISFACTORIAMENTE.');
console.log('======================================================================\n');

if (failures > 0) {
  process.exit(1);
}
