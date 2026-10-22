/**
 * VendGuard - TechnicianServiceSheetBlock Reactive Unit Tests
 * (TechnicianServiceSheetBlockTest.mjs)
 *
 * Certifica el bloque de firma manuscrita digitalizada del cierre correctivo
 * (Módulo 19: RF-01, RF-02, RD-02, RD-12, RD-15; tareas T-SIGN-06 y T-SIGN-07).
 *
 * Grupos:
 *   1. Contrato del componente y guardas de plantilla.
 *   2. Detección de tinta por luminancia (RF-01.6, límite de 150 píxeles).
 *   3. Normalización del documento del firmante (RF-01.5).
 *   4. Matriz de validez: firma presente, firma incompleta y atestación de ausencia (RF-01.4, RF-02.1).
 *   5. Exclusión mutua de las dos vías y borrado del trazo sin tocar los datos (RF-01.3, RF-02.2).
 *   6. Lienzo: escalado por devicePixelRatio, fondo opaco y conservación del trazo al redimensionar (RF-01.7, RF-01.8).
 *
 * Dogma Vanilla: ejecutable con `node` sin dependencias npm.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import {
  TechnicianServiceSheetBlock,
  SERVICE_SHEET_LIMITS,
  countInkPixels,
  normalizeSignerDocument
} from '../../public/assets/js/components/TechnicianServiceSheetBlock.js';

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
console.log(' VendGuard: Frontend Test Suite - TechnicianServiceSheetBlock (T-SIGN-06)');
console.log('======================================================================\n');

const SOURCE_PATH = fileURLToPath(new URL('../../public/assets/js/components/TechnicianServiceSheetBlock.js', import.meta.url));
const SOURCE = readFileSync(SOURCE_PATH, 'utf8');

// ─── Doble de lienzo ──────────────────────────────────────────────────────────

const WHITE = 255;

/**
 * Lienzo sintético con memoria de píxeles: el blanco opaco del papel es el estado
 * inicial y las operaciones de dibujo deciden cuánta tinta queda.
 */
const createCanvasStub = ({ cssWidth = 320, cssHeight = 160, ratio = 1 } = {}) => {
  const width = Math.round(cssWidth * ratio);
  const height = Math.round(cssHeight * ratio);
  const pixels = new Uint8ClampedArray(width * height * 4);

  const paintPaper = () => {
    for (let i = 0; i < pixels.length; i += 4) {
      pixels[i] = WHITE;
      pixels[i + 1] = WHITE;
      pixels[i + 2] = WHITE;
      pixels[i + 3] = 255;
    }
  };
  paintPaper();

  const ctx = {
    fillStyle: '',
    strokeStyle: '',
    lineWidth: 0,
    lineCap: '',
    lineJoin: '',
    operations: [],
    setTransform() { this.operations.push('setTransform'); },
    fillRect() { this.operations.push('fillRect'); paintPaper(); },
    beginPath() { this.operations.push('beginPath'); },
    arc(x, y) { this.operations.push('arc'); this.lastArc = { x, y }; },
    moveTo() { this.operations.push('moveTo'); },
    lineTo() { this.operations.push('lineTo'); },
    fill() { this.operations.push('fill'); },
    stroke() { this.operations.push('stroke'); },
    drawImage() { this.operations.push('drawImage'); },
    getImageData() { return { data: pixels }; }
  };

  const canvas = {
    width,
    height,
    clientWidth: cssWidth,
    clientHeight: cssHeight,
    style: {},
    getContext: () => ctx,
    getBoundingClientRect: () => ({ left: 0, top: 0, width: cssWidth, height: cssHeight }),
    toDataURL: () => 'data:image/png;base64,FAKE',
    setPointerCapture() {},
    releasePointerCapture() {}
  };

  return { canvas, ctx, pixels, paintPaper, width, height };
};

const paintInk = (pixels, count, luminance = 17) => {
  const limit = Math.min(count, Math.floor(pixels.length / 4));
  for (let i = 0; i < limit; i++) {
    const offset = i * 4;
    pixels[offset] = luminance;
    pixels[offset + 1] = luminance;
    pixels[offset + 2] = luminance;
    pixels[offset + 3] = 255;
  }
};

/** Instancia ligera con computed como getters y métodos enlazados. */
const createInstance = (props = {}) => {
  const instance = {
    ...TechnicianServiceSheetBlock.data(),
    disabled: props.disabled ?? false,
    $refs: {},
    emitted: [],
    $emit(event, payload) {
      this.emitted.push({ event, payload });
    }
  };

  for (const [key, fn] of Object.entries(TechnicianServiceSheetBlock.computed)) {
    Object.defineProperty(instance, key, { get: fn.bind(instance), configurable: true });
  }

  for (const [key, fn] of Object.entries(TechnicianServiceSheetBlock.methods)) {
    instance[key] = fn.bind(instance);
  }

  return instance;
};

const attachCanvas = (instance, canvas) => {
  instance.$refs = { signatureCanvas: canvas };
  return instance;
};

// ─── Grupo 1: Contrato del componente ─────────────────────────────────────────
console.log('--- Grupo 1: Contrato del componente y plantilla (RF-01.1) ---');

assert('1.1 El componente se llama TechnicianServiceSheetBlock y emite change',
  TechnicianServiceSheetBlock.name === 'TechnicianServiceSheetBlock'
    && Array.isArray(TechnicianServiceSheetBlock.emits)
    && TechnicianServiceSheetBlock.emits.includes('change'));

assert('1.2 La plantilla declara el lienzo y las dos vías del cierre',
  TechnicianServiceSheetBlock.template.includes('data-testid="service-sheet-canvas"')
    && TechnicianServiceSheetBlock.template.includes('data-testid="btn-mode-signature"')
    && TechnicianServiceSheetBlock.template.includes('data-testid="btn-mode-absence"')
    && TechnicianServiceSheetBlock.template.includes('No había responsable disponible en la sede'),
  'faltan el lienzo o los pulsadores de vía');

assert('1.3 La plantilla ofrece borrar el trazo sin tocar nombre ni documento',
  TechnicianServiceSheetBlock.template.includes('data-testid="btn-clear-stroke"')
    && TechnicianServiceSheetBlock.template.includes('@click="clearStroke()"'),
  'no hay pulsador de borrado enlazado a clearStroke');

assert('1.4 El componente no introduce literales hex en JavaScript (trinquete de tokens)',
  !/#[0-9a-fA-F]{3,8}\b/.test(SOURCE),
  'el trinquete DesignTokenDebtRatchetTest fallaría con un color nuevo escrito a mano');

assert('1.5 Los límites del bloque coinciden con el validador del servidor',
  SERVICE_SHEET_LIMITS.MIN_SIGNER_NAME === 3
    && SERVICE_SHEET_LIMITS.MIN_DOCUMENT === 5
    && SERVICE_SHEET_LIMITS.MAX_DOCUMENT === 20
    && SERVICE_SHEET_LIMITS.MIN_ABSENCE_REASON === 10
    && SERVICE_SHEET_LIMITS.MIN_INK_PIXELS === 150,
  JSON.stringify(SERVICE_SHEET_LIMITS));

// ─── Grupo 2: Detección de tinta ──────────────────────────────────────────────
console.log('\n--- Grupo 2: Tinta por luminancia (RF-01.6, RD-12) ---');

{
  const blank = new Uint8ClampedArray(64 * 4);
  for (let i = 0; i < blank.length; i += 4) {
    blank[i] = WHITE; blank[i + 1] = WHITE; blank[i + 2] = WHITE; blank[i + 3] = 255;
  }
  assert('2.1 Un lienzo en blanco no tiene tinta', countInkPixels(blank) === 0, String(countInkPixels(blank)));
}

{
  const pixels = new Uint8ClampedArray(400 * 4);
  paintInk(pixels, 149);
  const counted = countInkPixels(pixels);
  assert('2.2 Ciento cuarenta y nueve píxeles oscuros siguen siendo «sin tinta»',
    counted === 149 && counted < SERVICE_SHEET_LIMITS.MIN_INK_PIXELS,
    `contados=${counted}`);
}

{
  const pixels = new Uint8ClampedArray(400 * 4);
  paintInk(pixels, 150);
  assert('2.3 Ciento cincuenta píxeles oscuros alcanzan el mínimo',
    countInkPixels(pixels) === 150);
}

{
  const pixels = new Uint8ClampedArray(8 * 4);
  paintInk(pixels, 2, 199);
  const light = new Uint8ClampedArray(8 * 4);
  paintInk(light, 2, 200);
  assert('2.4 La luminancia 200 ya es papel y 199 todavía es tinta',
    countInkPixels(pixels) === 2 && countInkPixels(light) === 0,
    `199→${countInkPixels(pixels)} 200→${countInkPixels(light)}`);
}

{
  const pixels = new Uint8ClampedArray(8 * 4);
  paintInk(pixels, 2, 17);
  pixels[3] = 0;
  pixels[7] = 0;
  assert('2.5 Los píxeles transparentes no cuentan como tinta', countInkPixels(pixels) === 0);
}

// ─── Grupo 3: Normalización del documento ─────────────────────────────────────
console.log('\n--- Grupo 3: Normalización del documento (RF-01.5) ---');

assert('3.1 El documento pasa a mayúsculas y pierde los espacios internos',
  normalizeSignerDocument('12345678 z') === '12345678Z',
  normalizeSignerDocument('12345678 z'));

assert('3.2 El guion se conserva y todo espacio desaparece',
  normalizeSignerDocument('12 345 678-Z') === '12345678-Z',
  normalizeSignerDocument('12 345 678-Z'));

assert('3.3 Un valor vacío o ausente normaliza a cadena vacía',
  normalizeSignerDocument(null) === '' && normalizeSignerDocument(undefined) === '');

// ─── Grupo 4: Matriz de validez ───────────────────────────────────────────────
console.log('\n--- Grupo 4: Validez y mensajes del bloque (RF-01.4, RF-02.1) ---');

{
  const instance = createInstance();
  const result = instance.validate();
  assert('4.1 Sin ninguna de las dos vías el bloque no es válido',
    result.isValid === false && /firma|ausencia/i.test(result.error),
    JSON.stringify(result));
}

{
  const instance = createInstance();
  instance.mode = 'signature';
  instance.signerName = 'Ab';
  instance.signerDocumentRaw = '12345678Z';
  const result = instance.validate();
  assert('4.2 Un nombre de dos caracteres señala el campo del firmante',
    result.isValid === false && result.field === 'signer_name',
    JSON.stringify(result));
}

{
  const instance = createInstance();
  instance.mode = 'signature';
  instance.signerName = 'María López';
  instance.signerDocumentRaw = '12 34';
  const result = instance.validate();
  assert('4.3 Un documento que no encaja tras normalizar señala su campo',
    result.isValid === false && result.field === 'signer_document',
    JSON.stringify(result));
}

{
  const instance = createInstance();
  instance.mode = 'signature';
  instance.signerName = 'María López';
  instance.signerDocumentRaw = '12345678Z';
  const result = instance.validate();
  assert('4.4 Sin tinta el envío se impide señalando el trazo',
    result.isValid === false && result.field === 'signature_png_base64',
    JSON.stringify(result));
}

{
  const instance = createInstance();
  instance.mode = 'signature';
  instance.signerName = '  María López  ';
  instance.signerDocumentRaw = '12345678 z';
  instance.hasStroke = true;
  instance.strokeInkPixels = 200;
  instance.pngBase64 = 'data:image/png;base64,AAAA';
  const result = instance.validate();
  assert('4.5 La firma completa produce el bloque de tres claves que espera la API',
    result.isValid === true
      && JSON.stringify(Object.keys(result.payload)) === JSON.stringify(['signer_name', 'signer_document', 'signature_png_base64'])
      && result.payload.signer_name === 'María López'
      && result.payload.signer_document === '12345678Z',
    JSON.stringify(result.payload));
}

{
  const instance = createInstance();
  instance.mode = 'absence';
  instance.absenceReason = 'Sin nadie';
  const result = instance.validate();
  assert('4.6 Un motivo de ausencia de nueve caracteres no vale',
    result.isValid === false && result.field === 'absence_reason',
    JSON.stringify(result));
}

{
  const instance = createInstance();
  instance.mode = 'absence';
  instance.absenceReason = '  Sede sin conserjería; llave en la central.  ';
  const result = instance.validate();
  assert('4.7 La ausencia válida emite solo el motivo, sin datos del firmante',
    result.isValid === true
      && JSON.stringify(Object.keys(result.payload)) === JSON.stringify(['absence_reason'])
      && result.payload.absence_reason === 'Sede sin conserjería; llave en la central.',
    JSON.stringify(result.payload));
}

// ─── Grupo 5: Exclusión mutua y borrado ───────────────────────────────────────
console.log('\n--- Grupo 5: Exclusión mutua y borrado del trazo (RF-01.3, RF-02.2) ---');

{
  const { canvas } = createCanvasStub();
  const instance = attachCanvas(createInstance(), canvas);
  instance.prepareCanvas();
  instance.mode = 'signature';
  instance.signerName = 'María López';
  instance.signerDocumentRaw = '12345678Z';
  instance.hasStroke = true;
  instance.pngBase64 = 'data:image/png;base64,AAAA';

  instance.setMode('absence');

  assert('5.1 Activar la ausencia vacía nombre, documento y trazo',
    instance.signerName === '' && instance.signerDocumentRaw === ''
      && instance.hasStroke === false && instance.pngBase64 === '',
    JSON.stringify({ name: instance.signerName, doc: instance.signerDocumentRaw, png: instance.pngBase64 }));

  instance.absenceReason = 'Sede sin conserjería; llave en la central.';
  instance.setMode('signature');

  assert('5.2 Volver a la firma vacía el motivo de ausencia',
    instance.absenceReason === '' && instance.mode === 'signature',
    instance.absenceReason);
}

{
  const { canvas } = createCanvasStub();
  const instance = attachCanvas(createInstance(), canvas);
  instance.prepareCanvas();
  instance.mode = 'signature';
  instance.signerName = 'María López';
  instance.signerDocumentRaw = '12345678Z';
  instance.hasStroke = true;
  instance.strokeInkPixels = 240;
  instance.pngBase64 = 'data:image/png;base64,AAAA';

  instance.clearStroke();

  assert('5.3 Borrar el trazo no toca ni el nombre ni el documento',
    instance.signerName === 'María López'
      && instance.signerDocumentRaw === '12345678Z'
      && instance.hasStroke === false
      && instance.pngBase64 === '',
    JSON.stringify({ name: instance.signerName, doc: instance.signerDocumentRaw, png: instance.pngBase64 }));
}

{
  const instance = createInstance();
  instance.mode = 'signature';
  instance.signerName = 'María López';
  instance.signerDocumentRaw = '12345678Z';
  instance.emitChange();
  const last = instance.emitted.at(-1);

  assert('5.4 Un bloque incompleto emite change con payload nulo',
    last.event === 'change' && last.payload.isValid === false && last.payload.payload === null,
    JSON.stringify(last.payload));
}

{
  const instance = createInstance();
  instance.setMode('absence');
  instance.absenceReason = 'Sede sin conserjería; llave en la central.';
  instance.emitChange();
  const last = instance.emitted.at(-1);

  assert('5.5 El bloque válido emite el payload que viajará en el cierre',
    last.payload.isValid === true
      && last.payload.mode === 'absence'
      && last.payload.payload.absence_reason === 'Sede sin conserjería; llave en la central.',
    JSON.stringify(last.payload));
}

// ─── Grupo 6: Lienzo ─────────────────────────────────────────────────────────
console.log('\n--- Grupo 6: Lienzo escalado, opaco y conservador del trazo (RF-01.7, RF-01.8) ---');

{
  const { canvas, ctx } = createCanvasStub({ cssWidth: 300, cssHeight: 150, ratio: 1 });
  globalThis.window = { devicePixelRatio: 2 };
  const instance = attachCanvas(createInstance(), canvas);
  instance.mode = 'signature';
  instance.prepareCanvas();

  assert('6.1 El lienzo escala su memoria por devicePixelRatio y pinta papel opaco',
    canvas.width === 600 && canvas.height === 300
      && ctx.fillStyle === 'rgb(255, 255, 255)'
      && ctx.operations.includes('fillRect'),
    `width=${canvas.width} height=${canvas.height} fill=${ctx.fillStyle}`);

  instance.onPointerDown({ clientX: 10, clientY: 10, pointerId: 1, preventDefault() {} });
  instance.onPointerMove({ clientX: 40, clientY: 24, pointerId: 1, preventDefault() {} });

  assert('6.2 El trazo se dibuja en el tono #111 (rgb 17,17,17)',
    ctx.strokeStyle === 'rgb(17, 17, 17)' && ctx.lineWidth >= 2,
    `stroke=${ctx.strokeStyle} width=${ctx.lineWidth}`);

  delete globalThis.window;
}

{
  const stub = createCanvasStub();
  const instance = attachCanvas(createInstance(), stub.canvas);
  instance.mode = 'signature';
  instance.prepareCanvas();
  paintInk(stub.pixels, 210);
  instance.refreshStrokeState();

  assert('6.3 Con tinta suficiente se exporta el PNG del trazo',
    instance.hasStroke === true
      && instance.strokeInkPixels === 210
      && instance.pngBase64 === 'data:image/png;base64,FAKE',
    JSON.stringify({ ink: instance.strokeInkPixels, png: instance.pngBase64 }));
}

{
  const stub = createCanvasStub({ cssWidth: 300, cssHeight: 150, ratio: 1 });
  const instance = attachCanvas(createInstance(), stub.canvas);
  instance.mode = 'signature';
  instance.prepareCanvas();
  instance.hasStroke = true;
  instance.pngBase64 = 'data:image/png;base64,FAKE';
  stub.canvas.clientWidth = 420;
  stub.canvas.clientHeight = 160;
  stub.canvas.getBoundingClientRect = () => ({ left: 0, top: 0, width: 420, height: 160 });

  class ImageStub {
    set src(value) {
      this._src = value;
      if (typeof this.onload === 'function') this.onload();
    }
  }
  globalThis.Image = ImageStub;
  stub.ctx.drawImage = () => paintInk(stub.pixels, 200);

  instance.preserveStrokeOnResize();

  assert('6.4 Al redimensionar el trazo se redibuja escalado y no se pierde',
    instance.mustResign === false && instance.hasStroke === true,
    JSON.stringify({ mustResign: instance.mustResign, hasStroke: instance.hasStroke }));

  delete globalThis.Image;
}

{
  const stub = createCanvasStub({ cssWidth: 300, cssHeight: 150, ratio: 1 });
  const instance = attachCanvas(createInstance(), stub.canvas);
  instance.mode = 'signature';
  instance.prepareCanvas();
  instance.hasStroke = true;
  instance.pngBase64 = 'data:image/png;base64,FAKE';
  stub.canvas.clientWidth = 420;
  stub.canvas.clientHeight = 160;
  stub.canvas.getBoundingClientRect = () => ({ left: 0, top: 0, width: 420, height: 160 });
  stub.canvas.toDataURL = () => { throw new Error('bitmap no copiable'); };

  instance.preserveStrokeOnResize();

  assert('6.5 Si el navegador no deja copiar el mapa de bits se pide volver a firmar',
    instance.mustResign === true && instance.hasStroke === false && instance.pngBase64 === '',
    JSON.stringify({ mustResign: instance.mustResign, png: instance.pngBase64 }));
}

{
  const stub = createCanvasStub();
  const instance = attachCanvas(createInstance({ disabled: true }), stub.canvas);
  instance.mode = 'signature';
  instance.prepareCanvas();

  instance.onPointerDown({ clientX: 10, clientY: 10, pointerId: 1, preventDefault() {} });
  instance.onPointerMove({ clientX: 30, clientY: 30, pointerId: 1, preventDefault() {} });

  assert('6.6 Un bloque deshabilitado no dibuja',
    instance.isDrawing === false && !stub.ctx.operations.includes('stroke'),
    JSON.stringify({ drawing: instance.isDrawing, ops: stub.ctx.operations }));
}

{
  // Regresión: el lienzo nace dentro de un `v-if`, así que al montar el bloque todavía no
  // existe y la memoria queda en el tamaño por defecto del navegador (300×150). Con el CSS
  // estirándolo a todo el ancho, el navegador escalaba el mapa de bits y el trazo se separaba
  // del puntero a medida que avanzaba hacia la derecha.
  const stub = createCanvasStub({ cssWidth: 467, cssHeight: 178, ratio: 1 });
  stub.canvas.width = 300;
  stub.canvas.height = 150;
  const instance = attachCanvas(createInstance(), stub.canvas);
  instance.mode = 'signature';

  instance.onPointerDown({ clientX: 400, clientY: 90, pointerId: 1, preventDefault() {} });

  assert('6.7 El lienzo sincroniza su memoria antes de dibujar y el trazo cae bajo el puntero',
    stub.canvas.width === 467 && stub.canvas.height === 178
      && stub.ctx.lastArc && stub.ctx.lastArc.x === 400 && stub.ctx.lastArc.y === 90,
    JSON.stringify({ bitmap: `${stub.canvas.width}x${stub.canvas.height}`, arc: stub.ctx.lastArc }));
}


// ─── Resumen ─────────────────────────────────────────────────────────────────
console.log('\n' + '='.repeat(70));
console.log(` Total Aserciones: ${assertions}`);
console.log(` RESULTADO: ${failures === 0 ? '100% EN VERDE.' : failures + ' FALLOS.'}`);
console.log('='.repeat(70) + '\n');

if (failures > 0) {
  process.exit(1);
}
