/**
 * VendGuard - QrLabelModalScanModeTest (QrLabelModalScanModeTest.mjs)
 *
 * Valida la Enmienda 1 (T-QR-18/T-QR-19) del módulo QR — RF-06, EARS 6.1–6.5:
 * 1. Acción "🔍 Modo escaneo" en el modal de etiqueta y overlay a pantalla completa.
 * 2. Recorte del viewBox al área QR (matriz + zona de silencio) y maximización.
 * 3. Cierre sin pérdida de estado (teléfono y SVG conservados) y aviso de brillo/reflejos.
 * 4. Estilos del modo escaneo en qr-print.css (86% del lado menor, fondo blanco, sin sombras,
 *    ocultación del marco decorativo y exclusión de impresión).
 *
 * Respeta el Dogma Vanilla (Node ESM puro, cero dependencias externas).
 */

// Mocks mínimos de entorno (mismo patrón que el resto de suites frontend)
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
import { QrLabelModal } from '../../public/assets/js/components/QrLabelModal.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

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
console.log(' VendGuard: Frontend Test Suite - Modo Escaneo QR (Enmienda 1 · RF-06)');
console.log('======================================================================\n');

// =====================================================================
// GRUPO 1: Plantilla del modal
// =====================================================================
console.log('--- Grupo 1: Acción y Overlay en la Plantilla ---');

const template = QrLabelModal.template;

assert('1.1 El modal ofrece el botón "Modo escaneo"', template.includes('data-testid="btn-scan-mode"'));
assert('1.2 El botón invoca openScanMode', template.includes('@click="openScanMode"'));
assert('1.3 El botón queda deshabilitado mientras no hay SVG', template.includes(':disabled="!svgContent"'));
assert('1.4 Existe la capa a pantalla completa (qr-scan-overlay)', template.includes('class="qr-scan-overlay"') && template.includes('data-testid="qr-scan-overlay"'));
assert('1.5 El lienzo del QR usa referencia scanStage', template.includes('ref="scanStage"') && template.includes('class="qr-scan-stage"'));
assert('1.6 El SVG de la etiqueta se inyecta en el lienzo', template.includes('v-html="svgContent"'));
assert('1.7 Instrucción de escaneo visible (EARS 6.3)', template.includes('Acerca la cámara del otro dispositivo'));
assert('1.8 Recomendación de brillo y reflejos (EARS 6.3)', template.includes('sube el brillo de la pantalla'));
assert('1.9 Metadatos de máquina y sede en el modo escaneo', template.includes('{{ machineCodeDisplay }}') && template.includes('locationData?.name'));
assert('1.10 Botón de cierre claramente visible (EARS 6.1)', template.includes('data-testid="btn-scan-close"'));
assert('1.11 Tocar el fondo cierra el modo escaneo', template.includes('@click.self="closeScanMode"'));

// =====================================================================
// GRUPO 2: Comportamiento reactivo (EARS 6.1, 6.2, 6.4)
// =====================================================================
console.log('\n--- Grupo 2: Apertura, Recorte y Cierre sin Pérdida de Estado ---');

const appliedAttributes = {};
const fakeStage = {
  querySelector: (selector) => {
    if (selector !== 'svg') return null;
    return {
      setAttribute: (name, value) => { appliedAttributes[name] = value; }
    };
  }
};

const vm = {
  svgContent: '<svg viewBox="0 0 400 600" xmlns="http://www.w3.org/2000/svg"></svg>',
  supportPhone: '600111222',
  scanMode: false,
  $refs: { scanStage: fakeStage },
  $nextTick: (callback) => callback()
};

QrLabelModal.methods.openScanMode.call(vm);
assert('2.1 openScanMode activa el modo escaneo', vm.scanMode === true);
assert('2.2 El viewBox se recorta al área QR (100 220 200 200)', appliedAttributes.viewBox === '100 220 200 200', JSON.stringify(appliedAttributes));
assert('2.3 preserveAspectRatio "xMidYMid meet" garantizado', appliedAttributes.preserveAspectRatio === 'xMidYMid meet');

QrLabelModal.methods.closeScanMode.call(vm);
assert('2.4 closeScanMode desactiva el modo escaneo', vm.scanMode === false);
assert('2.5 El cierre conserva el SVG ya cargado (EARS 6.4)', vm.svgContent.startsWith('<svg'));
assert('2.6 El cierre conserva el teléfono personalizado (EARS 6.4)', vm.supportPhone === '600111222');

const vmEmpty = {
  svgContent: '',
  scanMode: false,
  $refs: {},
  $nextTick: (callback) => callback()
};
QrLabelModal.methods.openScanMode.call(vmEmpty);
assert('2.7 Sin SVG cargado no se abre el modo escaneo', vmEmpty.scanMode === false);

const vmWithoutStage = {
  svgContent: '<svg></svg>',
  scanMode: false,
  $refs: {},
  $nextTick: (callback) => callback()
};
QrLabelModal.methods.openScanMode.call(vmWithoutStage);
assert('2.8 Sin referencia al lienzo no se lanza excepción y el modo queda activo', vmWithoutStage.scanMode === true);

// =====================================================================
// GRUPO 3: Estilos del modo escaneo (EARS 6.2, 6.5)
// =====================================================================
console.log('\n--- Grupo 3: Estilos en qr-print.css ---');

const cssPath = path.join(__dirname, '../../public/assets/css/qr-print.css');
const cssContent = fs.readFileSync(cssPath, 'utf8');

assert('3.1 El overlay es fijo y cubre la pantalla', /\.qr-scan-overlay\s*{[^}]*position:\s*fixed[^}]*inset:\s*0/.test(cssContent));
assert('3.2 Fondo blanco opaco y sin adornos', /\.qr-scan-overlay\s*{[^}]*background-color:\s*#ffffff/.test(cssContent));
assert('3.3 Por encima del modal', /\.qr-scan-overlay\s*{[^}]*z-index:\s*1200/.test(cssContent));
assert('3.4 El QR completo ocupa el 86% del lado menor (EARS 6.2)', /\.qr-scan-stage\s*{[^}]*width:\s*86vmin[^}]*height:\s*86vmin/.test(cssContent));
assert('3.5 El SVG del modo escaneo se expande sin sombra', /\.qr-scan-stage svg\s*{[^}]*width:\s*100%[^}]*height:\s*100%[^}]*box-shadow:\s*none/.test(cssContent));
assert('3.6 Se oculta el marco decorativo para preservar la zona de silencio', cssContent.includes('rect[fill="#ffffff"][stroke="#e2e8f0"]'));
assert('3.7 El modo escaneo nunca se imprime', /@media print\s*{\s*\.qr-scan-overlay\s*{\s*display:\s*none\s*!important/.test(cssContent));
assert('3.8 En apaisado la leyenda pasa al lateral (EARS 6.5)', /@media \(orientation: landscape\) and \(max-height: 520px\)[\s\S]*?\.qr-scan-caption\s*{[^}]*text-align:\s*left/.test(cssContent));

// =====================================================================
// RESUMEN
// =====================================================================
console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Fallos: ${failures}`);
if (failures === 0) {
  console.log(' RESULTADO: ¡TODAS LAS PRUEBAS DEL MODO ESCANEO PASARON (0 fallos)!');
  console.log(' CONDICIÓN T-QR-18 CUMPLIDA.');
} else {
  console.log(` RESULTADO: ${failures} PRUEBA(S) FALLIDA(S).`);
}
console.log('======================================================================');

process.exit(failures === 0 ? 0 : 1);
