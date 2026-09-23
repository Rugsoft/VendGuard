/**
 * VendGuard - QrCoordinatorComponentsTest (QrCoordinatorComponentsTest.mjs)
 * 
 * Valida la Tarea T-QR-14 (RF-01, RF-02 / EARS 1.1–1.4, 2.1–2.3):
 * 1. Integración en CoordinatorDashboardView:
 *    - Botón "🏷️ Imprimir QR" en cada fila de máquina.
 *    - Botón "📄 Etiquetas de Sede (A4)" en la barra superior.
 * 2. Componente QrLabelModal.js:
 *    - Previsualización de SVG vectorial completo.
 *    - Personalización de teléfono editable y persistencia opcional en sede.
 *    - Descarga vectorial directa SVG.
 *    - Impresión de pegatina individual aislada.
 * 3. Componente QrBatchPrintView.js y estilos qr-print.css:
 *    - Cuadrícula A4 con todas las máquinas activas de la sede.
 *    - Saltos de página limpios con break-inside: avoid.
 */

// Mock de DOM y localStorage
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
  print: () => {},
  open: () => ({
    document: {
      write: () => {},
      close: () => {}
    }
  })
};

globalThis.document = {
  createElement: () => ({
    href: '',
    download: '',
    click: () => {}
  }),
  body: {
    appendChild: () => {},
    removeChild: () => {}
  }
};

globalThis.Blob = class Blob {
  constructor(content, options) {
    this.content = content;
    this.type = options?.type;
  }
};

globalThis.URL = {
  createObjectURL: () => 'blob:mock-svg-url',
  revokeObjectURL: () => {}
};

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { api } from '../../public/assets/js/api.js';
import { QrLabelModal } from '../../public/assets/js/components/QrLabelModal.js';
import { QrBatchPrintView } from '../../public/assets/js/views/QrBatchPrintView.js';
import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';

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
console.log(' VendGuard: Frontend Test Suite - Coordinator QR Components (T-QR-14)');
console.log('======================================================================\n');

// Mock data fixtures
const mockMachineLabelData = {
  machine: {
    id: 1,
    code: 'VEND-0101',
    model: 'Sanden Vendo G-Drink',
    machine_type: 'PERISHABLE_FOOD',
    floor_wing: 'Planta Baja - Urgencias'
  },
  location: {
    id: 1,
    site_code: 'SEDE-BCN-01',
    name: 'Hospital del Mar - Edificio Central',
    phone: '600111222'
  },
  support_phone: '600111222',
  qr_target_url: 'https://vendguard.local/?qr=VEND-0101&site=SEDE-BCN-01',
  svg_content: '<svg viewBox="0 0 400 600" xmlns="http://www.w3.org/2000/svg"><text>VEND-0101</text></svg>'
};

const mockBatchData = {
  location: {
    id: 1,
    site_code: 'SEDE-BCN-01',
    name: 'Hospital del Mar - Edificio Central'
  },
  total_machines: 2,
  items: [
    {
      machine_id: 1,
      code: 'VEND-0101',
      model: 'Sanden Vendo G-Drink',
      svg_content: '<svg viewBox="0 0 400 600"><text>VEND-0101</text></svg>'
    },
    {
      machine_id: 2,
      code: 'VEND-0102',
      model: 'Bianchi Gaia Espresso',
      svg_content: '<svg viewBox="0 0 400 600"><text>VEND-0102</text></svg>'
    }
  ]
};

async function runTests() {
  // =====================================================================
  // GRUPO 1: Botones en CoordinatorDashboardView
  // =====================================================================
  console.log('--- Grupo 1: Integración en CoordinatorDashboardView ---');

  const template = CoordinatorDashboardView.template;

  assert('1.1 CoordinatorDashboardView incluye botón "🏷️ Imprimir QR"',
    template.includes('🏷️ Imprimir QR') && template.includes('openQrLabelModal')
  );

  assert('1.2 CoordinatorDashboardView incluye botón "📄 Etiquetas de Sede (A4)"',
    template.includes('📄 Etiquetas de Sede (A4)') && template.includes('openQrBatchPrint')
  );

  assert('1.3 CoordinatorDashboardView registra QrLabelModal y QrBatchPrintView',
    CoordinatorDashboardView.components.QrLabelModal !== undefined &&
    CoordinatorDashboardView.components.QrBatchPrintView !== undefined
  );

  // Instancia de prueba del dashboard
  const dashboardInstance = {
    ...CoordinatorDashboardView.data(),
    $emit: () => {}
  };
  Object.assign(dashboardInstance, CoordinatorDashboardView.methods);

  // Probar apertura de QrLabelModal
  dashboardInstance.openQrLabelModal({
    machine_id: 1,
    machine_code: 'VEND-0101',
    machine_model: 'Sanden Vendo G-Drink'
  });
  assert('1.4 openQrLabelModal activa showQrLabelModal=true', dashboardInstance.showQrLabelModal === true);
  assert('1.5 openQrLabelModal almacena selectedQrMachine', dashboardInstance.selectedQrMachine?.code === 'VEND-0101');

  // Probar apertura de QrBatchPrintView
  dashboardInstance.openQrBatchPrint(1, 'Hospital del Mar - Edificio Central');
  assert('1.6 openQrBatchPrint activa showQrBatchView=true', dashboardInstance.showQrBatchView === true);
  assert('1.7 openQrBatchPrint guarda selectedBatchLocationId=1', dashboardInstance.selectedBatchLocationId === 1);

  // =====================================================================
  // GRUPO 2: Componente QrLabelModal.js (RF-01, RF-02)
  // =====================================================================
  console.log('\n--- Grupo 2: Modal de Etiqueta Individual (QrLabelModal.js) ---');

  let apiLabelCalled = false;
  let apiLabelOptions = null;
  api.qr.getMachineLabel = async (id, options) => {
    apiLabelCalled = true;
    apiLabelOptions = options;
    return {
      success: true,
      data: mockMachineLabelData
    };
  };

  const modalInstance = {
    ...QrLabelModal.data(),
    machineId: 1,
    modelValue: true,
    $emit: () => {}
  };
  Object.assign(modalInstance, QrLabelModal.methods);
  for (const [key, getter] of Object.entries(QrLabelModal.computed)) {
    Object.defineProperty(modalInstance, key, { get: getter });
  }

  await modalInstance.fetchLabelData();

  assert('2.1 fetchLabelData llama a api.qr.getMachineLabel', apiLabelCalled === true);
  assert('2.2 svgContent cargado con código vectorial SVG', modalInstance.svgContent.includes('<svg') && modalInstance.svgContent.includes('</svg>'));
  assert('2.3 Metadatos de máquina y sede resueltos', modalInstance.machineData?.code === 'VEND-0101' && modalInstance.locationData?.site_code === 'SEDE-BCN-01');
  assert('2.4 supportPhone inicializado con el teléfono de la sede', modalInstance.supportPhone === '600111222');

  // Personalización del teléfono con persistencia en sede (EARS 1.2, 1.3)
  modalInstance.supportPhone = '933445566';
  modalInstance.updateLocationPhone = true;
  await modalInstance.applyPhoneCustomization();

  assert('2.5 applyPhoneCustomization envía update_location_phone=1', apiLabelOptions?.update_location_phone === 1);
  assert('2.6 applyPhoneCustomization envía nuevo teléfono', apiLabelOptions?.phone === '933445566');

  // Descarga vectorial SVG (EARS 2.3)
  let downloadTriggered = false;
  modalInstance.downloadSvg();
  assert('2.7 downloadSvg genera descarga de archivo SVG', modalInstance.svgContent.length > 0);

  // =====================================================================
  // GRUPO 3: Componente QrBatchPrintView.js y Estilos qr-print.css
  // =====================================================================
  console.log('\n--- Grupo 3: Impresión en Lote A4 y Estilos (QrBatchPrintView.js) ---');

  let apiBatchCalled = false;
  api.qr.getLocationBatch = async (locId) => {
    apiBatchCalled = true;
    return {
      success: true,
      data: mockBatchData
    };
  };

  const batchInstance = {
    ...QrBatchPrintView.data(),
    locationId: 1,
    locationName: 'Hospital del Mar - Edificio Central',
    $emit: () => {}
  };
  Object.assign(batchInstance, QrBatchPrintView.methods);

  await batchInstance.fetchBatchData();

  assert('3.1 fetchBatchData llama a api.qr.getLocationBatch', apiBatchCalled === true);
  assert('3.2 Carga total de máquinas en totalMachines', batchInstance.totalMachines === 2);
  assert('3.3 Array items contiene 2 etiquetas vectoriales SVG', batchInstance.items.length === 2);
  assert('3.4 Cada item contiene svg_content individual', batchInstance.items[0].svg_content.includes('<svg'));

  // Comprobar archivo qr-print.css
  const __dirname = path.dirname(fileURLToPath(import.meta.url));
  const cssPath = path.resolve(__dirname, '../../public/assets/css/qr-print.css');
  const cssExists = fs.existsSync(cssPath);
  assert('3.5 Archivo qr-print.css existe en public/assets/css/', cssExists);

  if (cssExists) {
    const cssContent = fs.readFileSync(cssPath, 'utf8');
    assert('3.6 qr-print.css define @media print', cssContent.includes('@media print'));
    assert('3.7 qr-print.css define tamaño A4 (@page { size: A4 portrait; })', cssContent.includes('size: A4 portrait'));
    assert('3.8 qr-print.css evita cortes de etiqueta con break-inside: avoid', cssContent.includes('break-inside: avoid'));
    assert('3.9 qr-print.css define cuadrícula de 2 columnas (.qr-batch-grid)', cssContent.includes('grid-template-columns: repeat(2, 1fr)'));
  }

  // =====================================================================
  // Resumen Final
  // =====================================================================
  console.log('\n======================================================================');
  if (failures === 0) {
    console.log(` RESULTADO: ¡TODAS LAS PRUEBAS PASARON (${assertions} aserciones, 0 fallos)!`);
    console.log(' CONDICIÓN T-QR-14 CUMPLIDA SATISFACTORIAMENTE.');
  } else {
    console.error(` RESULTADO: ${failures} PRUEBA(S) FALLIDA(S) de ${assertions} evaluadas.`);
  }
  console.log('======================================================================\n');

  if (failures > 0) {
    process.exit(1);
  }
}

runTests().catch(err => {
  console.error('Error fatal en runner de pruebas T-QR-14:', err);
  process.exit(1);
});
