/**
 * VendGuard - IncidentCommentThreadModal Sealed Mode & Dirty Guard Suite (T-COM-12)
 * (IncidentCommentThreadModalSealedGuardTest.mjs)
 *
 * Verifica la condición "Hecho cuando" de T-COM-12:
 * 1. Si el ticket se encuentra en estado cerrado o cancelado (`is_sealed == true`), el pie
 *    del modal oculta el formulario y muestra el aviso de auditoría
 *    "Expediente archivado: conversación sellada por auditoría" (RF-05.3).
 * 2. Al pulsar la tecla Escape o hacer clic en el fondo sombreado exterior mientras hay texto
 *    o foto en edición, el modal solicita confirmación explícita ("¿Descartar mensaje en redacción?")
 *    antes de cerrar (RNF-06).
 * 3. Si el usuario cancela la confirmación (window.confirm => false), el modal permanece abierto
 *    y conserva el borrador de texto y fotografía (RNF-06).
 * 4. Si el usuario acepta la confirmación (window.confirm => true), el modal emite 'close' (RNF-06).
 * 5. Ante errores de red móvil (HTTP 4xx/5xx), retiene íntegros el texto redactado y la fotografía
 *    para permitir reintentos manuales inmediatos (RF-07.1).
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
console.log(' VendGuard: Frontend Suite - Modo Sellado y Guardián Sucio (T-COM-12)');
console.log('======================================================================\n');

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
      photoInput: { click: () => {} },
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

// ─── Grupo 1: Modo Sellado de Solo Lectura (RF-05.3) ───────────────────────────
console.log('--- Grupo 1: Modo Sellado de Solo Lectura (RF-05.3) ---');

assert('1.1 La plantilla incluye el aviso de conversación sellada por auditoría',
  template.includes('data-testid="incident-comment-sealed-notice"') &&
  template.includes('Expediente archivado: conversación sellada por auditoría'));

assert('1.2 El aviso de sellado se muestra condicionado a v-if="isSealed"',
  template.includes('v-if="isSealed"') &&
  template.includes('v-else'));

assert('1.3 El aviso de sellado contiene el botón para cerrar el modal',
  template.includes('data-testid="incident-comment-sealed-notice"') &&
  template.includes('data-testid="incident-comment-footer-close"'));

const unsealedModal = buildInstance({
  thread: {
    incident: { id: 142, status: 'IN_PROGRESS', is_sealed: false }
  }
});
assert('1.4 Expediente activo o en garantía tiene isSealed === false',
  unsealedModal.isSealed === false);

const sealedClosedModal = buildInstance({
  thread: {
    incident: { id: 142, status: 'CLOSED', is_sealed: true }
  }
});
assert('1.5 Expediente cerrado tiene isSealed === true',
  sealedClosedModal.isSealed === true);

const sealedCancelledModal = buildInstance({
  thread: {
    incident: { id: 142, status: 'CANCELLED', is_sealed: true }
  }
});
assert('1.6 Expediente cancelado tiene isSealed === true',
  sealedCancelledModal.isSealed === true);

// ─── Grupo 2: Guardián de Formulario Sucio - Estado Dirty (RNF-06) ───────────
console.log('\n--- Grupo 2: Guardián de Formulario Sucio - Estado Dirty (RNF-06) ---');

const pristineModal = buildInstance();
assert('2.1 Sin texto ni foto, isDirty es false',
  pristineModal.isDirty === false);

const textDirtyModal = buildInstance();
textDirtyModal.commentText = 'Borrador en curso';
assert('2.2 Con texto en el área de redacción, isDirty es true',
  textDirtyModal.isDirty === true);

const spaceTextModal = buildInstance();
spaceTextModal.commentText = '     ';
assert('2.3 Solo espacios en blanco no se consideran borrador sucio (isDirty === false)',
  spaceTextModal.isDirty === false);

const photoDirtyModal = buildInstance();
photoDirtyModal.photoFile = { name: 'foto.jpg', size: 1000, type: 'image/jpeg' };
assert('2.4 Con fotografía seleccionada, isDirty es true',
  photoDirtyModal.isDirty === true);

// ─── Grupo 3: Intercepción de Cierre por Escape y Backdrop (RNF-06) ──────────
console.log('\n--- Grupo 3: Intercepción de Cierre por Escape y Backdrop (RNF-06) ---');

let confirmCalls = [];
let nextConfirmResult = true;
globalThis.confirm = (msg) => {
  confirmCalls.push(msg);
  return nextConfirmResult;
};

// Cierre sin cambios: no pide confirmación y cierra directo
const cleanModal = buildInstance();
cleanModal.requestClose();
assert('3.1 Modal limpio emite close directamente sin invocar confirm',
  confirmCalls.length === 0 && cleanModal.emitted?.length === 1);

// Cierre con texto cuando el usuario CANCELA
confirmCalls = [];
nextConfirmResult = false;
const dirtyCancelModal = buildInstance();
dirtyCancelModal.commentText = 'Notas técnicas que no quiero perder';
dirtyCancelModal.requestClose();

assert('3.2 Con borrador sucio requestClose invoca confirm con el mensaje exacto',
  confirmCalls.length === 1 && confirmCalls[0] === '¿Descartar mensaje en redacción?');

assert('3.3 Si el usuario cancela (false), no se emite close y el modal queda abierto',
  dirtyCancelModal.emitted === undefined || dirtyCancelModal.emitted.length === 0);

assert('3.4 El texto se retiene intacto tras cancelar el descarte',
  dirtyCancelModal.commentText === 'Notas técnicas que no quiero perder');

// Cierre con Escape cuando el usuario CANCELA
confirmCalls = [];
nextConfirmResult = false;
const escapeCancelModal = buildInstance();
escapeCancelModal.commentText = 'Texto en edición';
escapeCancelModal.handleKeyDown({ key: 'Escape' });

assert('3.5 Pulsar Escape con borrador sucio solicita confirmación',
  confirmCalls.length === 1 && confirmCalls[0] === '¿Descartar mensaje en redacción?');

assert('3.6 Si el usuario cancela con Escape, el modal NO emite close',
  escapeCancelModal.emitted === undefined || escapeCancelModal.emitted.length === 0);

// Cierre con Backdrop cuando el usuario CANCELA
confirmCalls = [];
nextConfirmResult = false;
const backdropCancelModal = buildInstance();
backdropCancelModal.photoFile = { name: 'adjunto.png' };
const backdropElem = { id: 'backdrop' };
backdropCancelModal.handleBackdropClick({ target: backdropElem, currentTarget: backdropElem });

assert('3.7 Clic en el fondo sombreado con foto solicita confirmación',
  confirmCalls.length === 1 && confirmCalls[0] === '¿Descartar mensaje en redacción?');

assert('3.8 Si el usuario cancela tras clic en backdrop, el modal NO emite close y conserva la foto',
  (backdropCancelModal.emitted === undefined || backdropCancelModal.emitted.length === 0) &&
  backdropCancelModal.photoFile !== null);

// Cierre cuando el usuario ACEPTA descartar
confirmCalls = [];
nextConfirmResult = true;
const acceptModal = buildInstance();
acceptModal.commentText = 'Borrador que se descarta voluntariamente';
acceptModal.requestClose();

assert('3.9 Si el usuario acepta descartar (true), el modal emite close',
  acceptModal.emitted?.length === 1 && acceptModal.emitted[0].name === 'close');

// ─── Grupo 4: Retención de Datos ante Error de Red (RF-07.1) ─────────────────
console.log('\n--- Grupo 4: Retención de Datos ante Error de Red (RF-07.1) ---');

api.upload = async () => {
  const err = new Error('HTTP 503 Service Unavailable');
  err.status = 503;
  throw err;
};

api.post = async () => {
  const err = new Error('HTTP 503 Service Unavailable');
  err.status = 503;
  throw err;
};

const networkFailModal = buildInstance();
const draftedText = 'Observación crítica sobre electroválvula 3';
const draftedPhoto = { name: 'valvula_quemada.jpg', size: 1024, type: 'image/jpeg' };

networkFailModal.commentText = draftedText;
networkFailModal.photoFile = draftedPhoto;

await networkFailModal.submitComment();

assert('4.1 Ante error 503 el texto redactado se retiene íntegro en el modal',
  networkFailModal.commentText === draftedText);

assert('4.2 Ante error 503 la fotografía adjunta se retiene íntegra en el modal',
  networkFailModal.photoFile === draftedPhoto);

assert('4.3 Se muestra el mensaje de error para informar al usuario',
  networkFailModal.formError.includes('503'));

assert('4.4 isSubmitting vuelve a false permitiendo reintento manual inmediato',
  networkFailModal.isSubmitting === false && networkFailModal.isSubmitDisabled === false);

// ─── Grupo 5: Solo lectura por reapertura sin reasignar (RF-05.4) ────────────
console.log('\n--- Grupo 5: Solo lectura por reapertura pendiente de reasignación (RF-05.4) ---');

assert('5.1 La plantilla incluye el aviso de expediente reabierto pendiente de reasignación',
  template.includes('data-testid="incident-comment-reopened-notice"') &&
  template.includes('Expediente reabierto pendiente de reasignación: el historial se mantiene consultable'));

assert('5.2 El aviso se muestra con v-else-if="isReadOnlyReopened" y el formulario conserva el v-else',
  template.includes('v-else-if="isReadOnlyReopened"') &&
  template.includes('class="incident-comment-form"'));

assert('5.3 El aviso de reapertura ofrece el botón de cierre del modal',
  template.includes('data-testid="incident-comment-reopened-notice"') &&
  template.includes('data-testid="incident-comment-footer-close"'));

const reopenedReadOnlyModal = buildInstance({
  thread: {
    incident: {
      id: 142,
      status: 'REOPENED',
      is_sealed: false,
      can_comment: false,
      read_only_reason: 'REOPENED_AWAITING_REASSIGNMENT',
    }
  }
});
assert('5.4 Expediente reabierto sin reasignar => isReadOnlyReopened === true',
  reopenedReadOnlyModal.isReadOnlyReopened === true);

const assignedTechnicianModal = buildInstance({
  thread: {
    incident: {
      id: 142,
      status: 'IN_PROGRESS',
      is_sealed: false,
      can_comment: true,
      read_only_reason: null,
    }
  }
});
assert('5.5 Expediente con publicación habilitada => isReadOnlyReopened === false',
  assignedTechnicianModal.isReadOnlyReopened === false);

const sealedPrecedenceModal = buildInstance({
  thread: {
    incident: {
      id: 142,
      status: 'CLOSED',
      is_sealed: true,
      can_comment: false,
      read_only_reason: 'REOPENED_AWAITING_REASSIGNMENT',
    }
  }
});
assert('5.6 El sellado tiene precedencia: no se etiqueta como reapertura pendiente',
  sealedPrecedenceModal.isSealed === true && sealedPrecedenceModal.isReadOnlyReopened === false);

const activeWithoutReasonModal = buildInstance({
  thread: {
    incident: {
      id: 142,
      status: 'IN_PROGRESS',
      is_sealed: false,
      can_comment: false,
      read_only_reason: null,
    }
  }
});
assert('5.7 Sin motivo de reapertura no se anuncia reapertura pendiente',
  activeWithoutReasonModal.isReadOnlyReopened === false);

// Guarda de cortesía: ni siquiera se llama al API cuando el hilo está en solo lectura por reapertura.
let reopenPostCalls = 0;
api.post = async () => { reopenPostCalls++; return {}; };
api.upload = async () => { reopenPostCalls++; return {}; };

const guardedModal = buildInstance({
  commentText: 'Intento de nota interna antes de la reasignación',
  thread: {
    incident: {
      id: 142,
      status: 'REOPENED',
      is_sealed: false,
      can_comment: false,
      read_only_reason: 'REOPENED_AWAITING_REASSIGNMENT',
    }
  }
});
await guardedModal.submitComment();

assert('5.8 En solo lectura por reapertura el envío se bloquea sin llamar al API',
  reopenPostCalls === 0 && guardedModal.formError.includes('pendiente de reasignación'),
  `llamadas=${reopenPostCalls} error='${guardedModal.formError}'`);

assert('5.9 El texto redactado se conserva tras el bloqueo (RF-07.1)',
  guardedModal.commentText === 'Intento de nota interna antes de la reasignación');

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-COM-12 FULFILLED.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.log(' RESULT: FAILURES DETECTED IN TEST SUITE.');
console.log('======================================================================\n');
process.exit(1);
