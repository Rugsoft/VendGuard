/**
 * VendGuard - ServiceSheetModal Reactive Unit Tests
 * (ServiceSheetModalTest.mjs)
 *
 * Certifica el albarán A4 imprimible de la intervención (Módulo 19: RF-04, RF-05,
 * RD-10, RD-11, RD-15; tareas T-SIGN-07 y T-SIGN-08).
 *
 * Grupos:
 *   1. Contrato del componente, rutas de lectura por rol y guardas de plantilla.
 *   2. Documento congelado, histórico y piezas.
 *   3. Bloque de firma: trazo, atestación de ausencia y «sin firma del responsable».
 *   4. Comportamiento: carga, selector de hojas anteriores, impresión y formato de fecha.
 *   5. Hoja de estilos de impresión A4 y enlace desde la página.
 *
 * Dogma Vanilla: ejecutable con `node` sin dependencias npm.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { ApiClient } from '../../public/assets/js/api.js';
import {
  ServiceSheetModal,
  SERVICE_SHEET_LABEL,
  SERVICE_SHEET_NO_SIGNATURE,
  SERVICE_SHEET_NO_PARTS,
  SERVICE_SHEET_ABSENCE_LABEL
} from '../../public/assets/js/components/ServiceSheetModal.js';

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
console.log(' VendGuard: Frontend Test Suite - ServiceSheetModal (T-SIGN-07)');
console.log('======================================================================\n');

const MODAL_SOURCE = readFileSync(
  fileURLToPath(new URL('../../public/assets/js/components/ServiceSheetModal.js', import.meta.url)),
  'utf8'
);
const CSS_SOURCE = readFileSync(
  fileURLToPath(new URL('../../public/assets/css/service-sheet.css', import.meta.url)),
  'utf8'
);
const INDEX_SOURCE = readFileSync(
  fileURLToPath(new URL('../../public/index.html', import.meta.url)),
  'utf8'
);

/** Instancia ligera con computed como getters y métodos enlazados. */
const createInstance = (props = {}) => {
  const instance = {
    ...ServiceSheetModal.data(),
    modelValue: props.modelValue ?? true,
    incidentId: props.incidentId ?? 123,
    audience: props.audience ?? 'technician',
    emitted: [],
    $emit(event, payload) {
      this.emitted.push({ event, payload });
    }
  };

  for (const [key, fn] of Object.entries(ServiceSheetModal.computed)) {
    if (typeof fn === 'function') {
      Object.defineProperty(instance, key, { get: fn.bind(instance), configurable: true });
      continue;
    }
    Object.defineProperty(instance, key, {
      get: fn.get.bind(instance),
      set: typeof fn.set === 'function' ? fn.set.bind(instance) : undefined,
      configurable: true
    });
  }

  for (const [key, fn] of Object.entries(ServiceSheetModal.methods)) {
    instance[key] = fn.bind(instance);
  }

  return instance;
};

const SIGNED_SHEET = {
  id: 41,
  recorded_at: '2026-10-22 17:42:11',
  resolved_at: '2026-10-22 17:42:11',
  current: true,
  signed: true,
  signer_name: 'María López Ferrer',
  signer_document: '12345678Z',
  png_base64: 'iVBORw0KGgoAAAANSUhEUg',
  absence_reason: null
};

const FROZEN_DOCUMENT = {
  version: 1,
  ticket_code: 'INC-000123',
  resolved_at: '2026-10-22T17:42:11+02:00',
  location: { name: 'Hospital Sant Pau', address: 'C/ Sant Quintí 89' },
  machine: { code: 'MAQ-014', model: 'VendMaster 600' },
  technician: { name: 'Jordi Serra' },
  diagnosis: 'Bobina del relé térmico quemada por pico de tensión.',
  action: 'Sustituido relé térmico y verificado ciclo de frío a 4 ºC.',
  parts: [
    { name: 'Relé térmico 16 A', quantity: 1 },
    { name: 'Fusible cerámico 10 A', quantity: 2 }
  ]
};

const buildData = (overrides = {}) => ({
  ticket_code: 'INC-000123',
  status: 'RESOLVED',
  current: true,
  document: FROZEN_DOCUMENT,
  sheet: SIGNED_SHEET,
  history: [SIGNED_SHEET],
  ...overrides
});

// ─── Grupo 1: Contrato y rutas ────────────────────────────────────────────────
console.log('--- Grupo 1: Contrato del componente y rutas de lectura (RF-04, RF-05) ---');

assert('1.1 El componente se llama ServiceSheetModal y cierra por v-model',
  ServiceSheetModal.name === 'ServiceSheetModal'
    && typeof ServiceSheetModal.props.incidentId !== 'undefined'
    && typeof ServiceSheetModal.props.audience !== 'undefined'
    && ServiceSheetModal.emits.includes('update:modelValue'));

assert('1.2 El albarán imprime la etiqueta «Firma manuscrita digitalizada» y la mención sin firma',
  SERVICE_SHEET_LABEL === 'Firma manuscrita digitalizada'
    && SERVICE_SHEET_NO_SIGNATURE === 'Sin firma del responsable'
    && ServiceSheetModal.template.includes('{{ signatureLabel }}')
    && ServiceSheetModal.template.includes('data-testid="service-sheet-unsigned"'),
  `label=${SERVICE_SHEET_LABEL} / ${SERVICE_SHEET_NO_SIGNATURE}`);

assert('1.3 Sin piezas el albarán dice «Sin sustitución de componentes»',
  SERVICE_SHEET_NO_PARTS === 'Sin sustitución de componentes'
    && ServiceSheetModal.template.includes('{{ partsLabel }}')
    && ServiceSheetModal.template.includes('data-testid="service-sheet-no-parts"'));

assert('1.4 El componente no introduce literales hex en JavaScript (trinquete de tokens)',
  !/#[0-9a-fA-F]{3,8}\b/.test(MODAL_SOURCE));

{
  const client = new ApiClient();
  client.get = (path) => Promise.resolve(path);
  const plain = await client.technician.getServiceSheet(7);
  const specific = await client.technician.getServiceSheet(7, 41);

  assert('1.5 El cliente del técnico lee el albarán por avería y por hoja',
    plain === '/technician/incidents/7/service-sheet'
      && specific === '/technician/incidents/7/service-sheet?sheet_id=41',
    `${plain} | ${specific}`);
}

{
  const client = new ApiClient();
  client.get = (path) => Promise.resolve(path);
  const plain = await client.coordinator.getServiceSheet(7);
  const specific = await client.coordinator.getServiceSheet(7, 39);

  assert('1.6 El cliente de coordinación usa su propia ruta y el mismo parámetro',
    plain === '/coordinator/incidents/7/service-sheet'
      && specific === '/coordinator/incidents/7/service-sheet?sheet_id=39',
    `${plain} | ${specific}`);
}

// ─── Grupo 2: Documento, histórico y piezas ──────────────────────────────────
console.log('\n--- Grupo 2: Documento congelado, histórico y piezas (RF-04.3, RF-04.6) ---');

{
  const instance = createInstance();
  instance.data = buildData();

  assert('2.1 La hoja vigente no se marca como histórica',
    instance.isHistorical === false && instance.hasSheet === true,
    JSON.stringify({ historical: instance.isHistorical, sheet: instance.hasSheet }));
}

{
  const instance = createInstance();
  instance.data = buildData({ current: false, history: [SIGNED_SHEET, { ...SIGNED_SHEET, id: 39, current: false }] });

  assert('2.2 Una hoja anterior se presenta como histórica con su fecha de intervención',
    instance.isHistorical === true
      && instance.history.length === 2
      && instance.formatDateTime(SIGNED_SHEET.resolved_at) !== '',
    JSON.stringify({ historical: instance.isHistorical, history: instance.history.length }));
}

{
  const instance = createInstance();
  instance.data = buildData();

  assert('2.3 Las piezas se leen del documento congelado, no de la incidencia viva',
    instance.parts.length === 2
      && instance.parts[1].quantity === 2
      && instance.partsLabel === '',
    JSON.stringify(instance.parts));
}

{
  const instance = createInstance();
  instance.data = buildData({ document: { ...FROZEN_DOCUMENT, parts: [] } });

  assert('2.4 Un documento sin piezas usa la mención normativa',
    instance.partsLabel === SERVICE_SHEET_NO_PARTS, instance.partsLabel);
}

// ─── Grupo 3: Bloque de firma ────────────────────────────────────────────────
console.log('\n--- Grupo 3: Firma, ausencia y ausencia de firma (RF-04.1, RD-15) ---');

{
  const instance = createInstance();
  instance.data = buildData();

  assert('3.1 Con firma se muestran nombre, documento y fecha de registro',
    instance.signatureLabel === SERVICE_SHEET_LABEL
      && instance.sheet.signer_name === 'María López Ferrer'
      && instance.sheet.signer_document === '12345678Z'
      && instance.formatDateTime(instance.sheet.recorded_at) !== '',
    instance.signatureLabel);
}

{
  const instance = createInstance();
  instance.data = buildData({
    sheet: {
      ...SIGNED_SHEET,
      signed: false,
      signer_name: null,
      signer_document: null,
      png_base64: null,
      absence_reason: 'Sede sin conserjería; acceso con llave facilitada por la central.'
    }
  });

  assert('3.2 La atestación de ausencia imprime su motivo y no un hueco de firma',
    instance.signatureLabel === SERVICE_SHEET_ABSENCE_LABEL
      && instance.sheet.absence_reason.includes('llave'),
    instance.signatureLabel);
}

{
  const instance = createInstance();
  instance.data = buildData({ sheet: null, current: false, status: 'REOPENED' });

  assert('3.3 Sin hoja el albarán dice «Sin firma del responsable» y no inventa firmante',
    instance.hasSheet === false
      && instance.signatureLabel === SERVICE_SHEET_NO_SIGNATURE
      && instance.signatureImage === '',
    JSON.stringify({ label: instance.signatureLabel, image: instance.signatureImage }));
}

{
  const instance = createInstance();
  instance.data = buildData();

  assert('3.4 La imagen de la firma se compone como data URL PNG',
    instance.signatureImage === 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg',
    instance.signatureImage);
}

{
  const instance = createInstance();
  instance.data = buildData({
    sheet: { ...SIGNED_SHEET, signed: false, absence_reason: 'Sede cerrada; acceso por conserje del turno.' }
  });

  assert('3.5 Una hoja sin firma pero con motivo no pinta imagen',
    instance.signatureImage === '' && instance.sheet.absence_reason !== null);
}

// ─── Grupo 4: Comportamiento ────────────────────────────────────────────────
console.log('\n--- Grupo 4: Carga, selector de hojas e impresión (RF-05.1, RF-05.5) ---');

{
  const { api } = await import('../../public/assets/js/api.js');
  const calls = [];
  const original = api.technician.getServiceSheet;
  api.technician.getServiceSheet = (incidentId, sheetId) => {
    calls.push({ incidentId, sheetId });
    return Promise.resolve({ success: true, data: buildData() });
  };

  const instance = createInstance({ incidentId: 123, audience: 'technician' });
  await instance.load();

  assert('4.1 Cargar el albarán usa el rol del lector y guarda el documento',
    calls.length === 1 && calls[0].incidentId === 123
      && instance.data?.ticket_code === 'INC-000123'
      && instance.selectedSheetId === 41
      && instance.loadError === '',
    JSON.stringify({ calls, error: instance.loadError }));

  await instance.selectSheet(39);
  assert('4.2 Elegir una hoja anterior la pide por identificador',
    calls.length === 2 && calls[1].sheetId === 39,
    JSON.stringify(calls));

  api.technician.getServiceSheet = original;
}

{
  const { api } = await import('../../public/assets/js/api.js');
  const original = api.coordinator.getServiceSheet;
  api.coordinator.getServiceSheet = () => Promise.reject(new Error('SERVICE_SHEET_FORBIDDEN'));

  const instance = createInstance({ audience: 'coordinator' });
  await instance.load();

  assert('4.3 Un rechazo del servidor deja el albarán vacío con su mensaje',
    instance.data === null
      && instance.loadError === 'SERVICE_SHEET_FORBIDDEN'
      && instance.emitted.some(e => e.event === 'error'),
    JSON.stringify({ error: instance.loadError }));

  api.coordinator.getServiceSheet = original;
}

{
  let printed = 0;
  globalThis.window = { print: () => { printed++; } };
  const instance = createInstance();
  instance.print();

  assert('4.4 Imprimir delega en window.print() sin librerías', printed === 1, String(printed));
  delete globalThis.window;
}

{
  const instance = createInstance();
  const iso = instance.formatDateTime('2026-10-22T17:42:11+02:00');
  const sql = instance.formatDateTime('2026-10-22 17:42:11');

  assert('4.5 El formato de fecha admite ISO y la marca SQL, y no rompe con vacío',
    iso !== '' && sql !== '' && instance.formatDateTime(null) === '',
    `${iso} | ${sql} | ${instance.formatDateTime(null)}`);
}

// ─── Grupo 5: Hoja de impresión A4 ──────────────────────────────────────────
console.log('\n--- Grupo 5: Hoja de estilos A4 (RF-04.7, RF-05.2) ---');

assert('5.1 La hoja declara el tamaño A4 y márgenes de página',
  /@page\s*\{[^}]*size:\s*A4/i.test(CSS_SOURCE) && /@page\s*\{[^}]*margin:/i.test(CSS_SOURCE),
  'falta @page size A4 o margin');

assert('5.2 El bloque de firma nunca se parte entre páginas',
  /\.vg-service-sheet__signature[^{]*\{[^}]*break-inside:\s*avoid/i.test(CSS_SOURCE)
    && /\.vg-service-sheet__signature[^{]*\{[^}]*page-break-inside:\s*avoid/i.test(CSS_SOURCE));

assert('5.3 Al imprimir se oculta todo lo que no es el documento',
  /@media\s+print/i.test(CSS_SOURCE)
    && /visibility:\s*hidden/i.test(CSS_SOURCE)
    && /\.vg-service-sheet__paper[\s\S]*?visibility:\s*visible/i.test(CSS_SOURCE),
  'no se oculta la interfaz al imprimir');

assert('5.4 El lienzo de la firma cancela el gesto táctil para no hacer scroll',
  /\.vg-signature-canvas[^{]*\{[^}]*touch-action:\s*none/i.test(CSS_SOURCE),
  'falta touch-action: none en el lienzo');

assert('5.5 La página carga la hoja de estilos del albarán',
  INDEX_SOURCE.includes('service-sheet.css'));

// ─── Resumen ────────────────────────────────────────────────────────────────
console.log('\n' + '='.repeat(70));
console.log(` Total Aserciones: ${assertions}`);
console.log(` RESULTADO: ${failures === 0 ? '100% EN VERDE.' : failures + ' FALLOS.'}`);
console.log('='.repeat(70) + '\n');

if (failures > 0) {
  process.exit(1);
}
