/**
 * VendGuard - IncidentCommentThreadModal Form & Privacy Suite (T-COM-11)
 * (IncidentCommentThreadModalFormTest.mjs)
 *
 * Verifica la condición "Hecho cuando" de T-COM-11:
 * 1. Selector reactivo de privacidad para técnicos y coordinadores con la opción
 *    "Nota Interna de Taller" PRESELECCIONADA por defecto ('INTERNAL') y completamente
 *    ausente para responsables de sede (RF-03.2, RF-03.3).
 * 2. Área de texto con contador reactivo de caracteres restantes (5 a 1.000), bloqueando
 *    el botón de envío si el texto es inferior a 5 caracteres (RF-03.1).
 * 3. Selector de fotografía con previsualización en miniatura y botón para retirarla antes
 *    del envío (RF-04.1).
 * 4. Indicador visual de carga (spinner) deshabilitando el botón durante la subida (RF-04.5).
 * 5. Emisión del evento 'comment-added' y reseteo del formulario preservando la privacidad por defecto.
 *
 * Dogma Vanilla: Node.js ESM nativo, cero dependencias externas.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const registeredListeners = new Map();
globalThis.window = {
  addEventListener: (type, handler) => { registeredListeners.set(type, handler); },
  removeEventListener: (type) => { registeredListeners.delete(type); }
};
globalThis.document = { body: { style: { overflow: '' } } };

const HERE = path.dirname(fileURLToPath(import.meta.url));
const COMPONENT_PATH = path.resolve(HERE, '../../public/assets/js/components/IncidentCommentThreadModal.js');

const { api } = await import('../../public/assets/js/api.js');
const { IncidentCommentThreadModal } = await import('../../public/assets/js/components/IncidentCommentThreadModal.js');

const componentSource = fs.readFileSync(COMPONENT_PATH, 'utf8');
const template = String(IncidentCommentThreadModal.template || '');

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
console.log(' VendGuard: Frontend Suite - Formulario y Selector de Privacidad (T-COM-11)');
console.log('======================================================================\n');

// ─── Doble de api.post y api.upload ──────────────────────────────────────────
let apiCalls = [];
let nextPostResponse = null;
let nextPostError = null;

api.post = async (endpoint, payload) => {
  apiCalls.push({ method: 'POST', endpoint, payload });
  if (nextPostError) {
    throw nextPostError;
  }
  return nextPostResponse;
};

api.upload = async (endpoint, formData) => {
  apiCalls.push({ method: 'UPLOAD', endpoint, formData });
  if (nextPostError) {
    throw nextPostError;
  }
  return nextPostResponse;
};

function buildInstance(overrides = {}) {
  const instance = Object.create(IncidentCommentThreadModal);
  const data = typeof IncidentCommentThreadModal.data === 'function'
    ? IncidentCommentThreadModal.data.call(instance)
    : {};

  Object.assign(instance, {
    isOpen: true,
    incidentId: 142,
    ticketCode: 'TICK-2026-00142',
    role: 'TECHNICIAN',
    ...data,
    ...overrides,
    $refs: {
      photoInput: { click: () => { instance._photoInputClicked = true; } },
      threadScroll: { scrollTop: 0, scrollHeight: 500, clientHeight: 300 }
    },
    $nextTick: async () => {},
    $emit: (name, payload) => {
      instance.emitted = instance.emitted || [];
      instance.emitted.push({ name, payload });
    }
  });

  for (const [name, fn] of Object.entries(IncidentCommentThreadModal.methods || {})) {
    instance[name] = fn.bind(instance);
  }

  for (const [name, fn] of Object.entries(IncidentCommentThreadModal.computed || {})) {
    Object.defineProperty(instance, name, {
      configurable: true,
      enumerable: true,
      get: () => fn.call(instance)
    });
  }

  return instance;
}

// ─── Grupo 1: Selector de Privacidad Reactivo por Defecto (RF-03.2, RF-03.3) ───
console.log('--- Grupo 1: Selector de Privacidad Reactivo (RF-03.2, RF-03.3) ---');

const techInstance = buildInstance({ role: 'TECHNICIAN' });
assert('1.1 privacyChoice está preseleccionado en INTERNAL por defecto',
  techInstance.privacyChoice === 'INTERNAL');

assert('1.2 Técnico muestra el selector de privacidad (showPrivacySelector === true)',
  techInstance.showPrivacySelector === true);

const coordInstance = buildInstance({ role: 'COORDINATOR' });
assert('1.3 Coordinador muestra el selector de privacidad (showPrivacySelector === true)',
  coordInstance.showPrivacySelector === true);

const siteInstance = buildInstance({ role: 'SITE_MANAGER' });
assert('1.4 Responsable de Sede NO muestra el selector de privacidad (showPrivacySelector === false)',
  siteInstance.showPrivacySelector === false);

assert('1.5 La plantilla incluye radio buttons para INTERNAL y PUBLIC bajo v-if="showPrivacySelector"',
  template.includes('data-testid="incident-comment-privacy-internal"') &&
  template.includes('data-testid="incident-comment-privacy-public"') &&
  template.includes('v-if="showPrivacySelector"'));

assert('1.6 La opción interna describe claramente el taller confidencial',
  template.includes('🔒 Nota Interna de Taller (Confidencial)'));

assert('1.7 La opción pública describe claramente el mensaje para sede',
  template.includes('🌐 Mensaje para Sede (Público)'));

// ─── Grupo 2: Validación de Longitud y Contador Reactivo (RF-03.1) ───────────
console.log('\n--- Grupo 2: Validación de Longitud y Contador Reactivo (RF-03.1) ---');

const formModal = buildInstance();
assert('2.1 Contador inicial marca 1000 caracteres restantes',
  formModal.remainingChars === 1000);

assert('2.2 Texto vacío tiene isCommentValid === false',
  formModal.isCommentValid === false);

assert('2.3 Botón de envío está deshabilitado inicialmente (isSubmitDisabled === true)',
  formModal.isSubmitDisabled === true);

formModal.commentText = '1234'; // 4 caracteres
assert('2.4 Con 4 caracteres isCommentValid === false (mínimo 5 requerido)',
  formModal.isCommentValid === false && formModal.isSubmitDisabled === true);

formModal.commentText = '12345'; // 5 caracteres exactos
assert('2.5 Con 5 caracteres isCommentValid === true y isSubmitDisabled === false',
  formModal.isCommentValid === true && formModal.isSubmitDisabled === false);

assert('2.6 Contador reactivo calcula 995 caracteres restantes',
  formModal.remainingChars === 995);

formModal.commentText = '   1234   '; // 4 caracteres tras trim
assert('2.7 Texto con espacios que tras trim tiene menos de 5 es inválido',
  formModal.isCommentValid === false && formModal.isSubmitDisabled === true);

formModal.commentText = 'a'.repeat(1000); // 1.000 caracteres
assert('2.8 Con 1.000 caracteres isCommentValid === true y remainingChars === 0',
  formModal.isCommentValid === true && formModal.remainingChars === 0 && formModal.isSubmitDisabled === false);

formModal.commentText = 'a'.repeat(1001); // 1.001 caracteres
assert('2.9 Con 1.001 caracteres isCommentValid === false y remainingChars < 0',
  formModal.isCommentValid === false && formModal.remainingChars === -1 && formModal.isSubmitDisabled === true);

assert('2.10 La plantilla declara textarea con minlength=5 y maxlength=1000',
  template.includes('data-testid="incident-comment-textarea"') &&
  template.includes('minlength="5"') &&
  template.includes('maxlength="1000"'));

assert('2.11 La plantilla renderiza el contador reactivo con data-testid',
  template.includes('data-testid="incident-comment-char-counter"') &&
  template.includes('caracteres restantes'));

// ─── Grupo 3: Adjuntos Fotográficos y Miniatura (RF-04.1, RF-04.2) ───────────
console.log('\n--- Grupo 3: Adjuntos Fotográficos y Miniatura (RF-04.1, RF-04.2) ---');

const photoModal = buildInstance();

assert('3.1 Botón de adjuntar foto existe y activa el input de archivo',
  template.includes('data-testid="incident-comment-photo-btn"') &&
  template.includes('data-testid="incident-comment-photo-input"'));

// Rechazo de archivo superior a 5 MB
const oversizedFile = { name: 'pesada.jpg', size: 6 * 1024 * 1024, type: 'image/jpeg' };
photoModal.handlePhotoSelect({ target: { files: [oversizedFile], value: 'dummy' } });
assert('3.2 Fotografía superior a 5 MB es rechazada y muestra error',
  photoModal.photoFile === null && photoModal.formError.includes('5 MB'));

// Rechazo de formato no permitido
const invalidMimeFile = { name: 'archivo.pdf', size: 1024, type: 'application/pdf' };
photoModal.handlePhotoSelect({ target: { files: [invalidMimeFile], value: 'dummy' } });
assert('3.3 Formato no admitido (.pdf) es rechazado',
  photoModal.photoFile === null && photoModal.formError.includes('Formato no admitido'));

// Aceptación de foto válida
const validPhoto = { name: 'evidencia.webp', size: 2 * 1024 * 1024, type: 'image/webp' };
photoModal.handlePhotoSelect({ target: { files: [validPhoto], value: 'dummy' } });
assert('3.4 Foto válida <= 5 MB es aceptada',
  photoModal.photoFile === validPhoto && photoModal.formError === '');

assert('3.5 La plantilla contiene la previsualización de foto y el botón para retirarla',
  template.includes('data-testid="incident-comment-photo-preview"') &&
  template.includes('data-testid="incident-comment-photo-remove"'));

photoModal.clearSelectedPhoto();
assert('3.6 clearSelectedPhoto retira la foto seleccionada',
  photoModal.photoFile === null && photoModal.photoPreviewUrl === null);

// ─── Grupo 4: Envío, Spinner e Inviolabilidad (RF-04.5, RF-03.4) ─────────────
console.log('\n--- Grupo 4: Envío, Spinner e Inviolabilidad (RF-04.5, RF-03.4) ---');

const submitModal = buildInstance({ role: 'TECHNICIAN' });
submitModal.commentText = 'Revisando electroválvula de entrada';
submitModal.privacyChoice = 'INTERNAL';

nextPostResponse = {
  incident: { id: 142, ticket_code: 'TICK-2026-00142' },
  comments: [
    { id: 101, comment_text: 'Revisando electroválvula de entrada', is_internal: true }
  ]
};

apiCalls = [];
await submitModal.submitComment();

assert('4.1 Envío exitoso llama a la API con comment_text y is_internal: true',
  apiCalls.length === 1 &&
  apiCalls[0].payload.comment_text === 'Revisando electroválvula de entrada' &&
  apiCalls[0].payload.is_internal === true);

assert('4.2 Tras el envío exitoso se limpia el texto del comentario',
  submitModal.commentText === '');

assert('4.3 Tras el envío la privacidad vuelve a su valor seguro por defecto (INTERNAL)',
  submitModal.privacyChoice === 'INTERNAL');

assert('4.4 submitComment emite el evento "comment-added"',
  submitModal.emitted?.some(e => e.name === 'comment-added'));

// Envío con fotografía multipart
const multipartModal = buildInstance({ role: 'COORDINATOR' });
multipartModal.commentText = 'Adjunto fotografía del frontal';
multipartModal.privacyChoice = 'PUBLIC';
multipartModal.photoFile = validPhoto;

apiCalls = [];
await multipartModal.submitComment();

assert('4.5 Envío con foto utiliza FormData (multipart) con photo y is_internal="0"',
  apiCalls.length === 1 &&
  apiCalls[0].formData instanceof FormData &&
  apiCalls[0].formData.get('comment_text') === 'Adjunto fotografía del frontal' &&
  apiCalls[0].formData.get('is_internal') === '0' &&
  apiCalls[0].formData.has('photo'));

assert('4.6 Tras envío con foto se limpia la foto seleccionada',
  multipartModal.photoFile === null);

// Indicador de spinner
assert('4.7 La plantilla incluye spinner de carga con data-testid="incident-comment-spinner"',
  template.includes('data-testid="incident-comment-spinner"') &&
  template.includes('spinner-border'));

assert('4.8 isSubmitting deshabilita el botón de envío y los campos',
  template.includes(':disabled="isSubmitDisabled"') &&
  template.includes(':disabled="isSubmitting"'));

// ─── Grupo 5: Resiliencia ante Fallos de Red (RF-07.1) ────────────────────────
console.log('\n--- Grupo 5: Resiliencia ante Fallos de Red (RF-07.1) ---');

const failureModal = buildInstance();
failureModal.commentText = 'Texto que no debe perderse ante corte de red';
failureModal.photoFile = validPhoto;

nextPostError = new Error('Network timeout in 4G connection');
await failureModal.submitComment();

assert('5.1 Ante fallo de red se retiene íntegro el texto del borrador (RF-07.1)',
  failureModal.commentText === 'Texto que no debe perderse ante corte de red');

assert('5.2 Ante fallo de red se retiene la fotografía adjunta (RF-07.1)',
  failureModal.photoFile === validPhoto);

assert('5.3 Ante fallo de red se muestra mensaje de error descriptivo',
  failureModal.formError.includes('Network timeout'));

assert('5.4 isSubmitting regresa a false tras el fallo permitiendo reintento inmediato',
  failureModal.isSubmitting === false && failureModal.isSubmitDisabled === false);

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-COM-11 FULFILLED.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.log(' RESULT: FAILURES DETECTED IN TEST SUITE.');
console.log('======================================================================\n');
process.exit(1);
