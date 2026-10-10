/**
 * VendGuard - LocationPortalAccessConfirmationAndSealedNoticeTest
 * (tests/unit/LocationPortalAccessConfirmationAndSealedNoticeTest.mjs)
 *
 * Cierra el hueco de cobertura JS detectado en la reauditoría del módulo 11: el
 * contrato de servidor de RF-04.6 y RF-05.4 ya estaba certificado, pero el portal
 * de sede no tenía suite propia para estos dos flujos de interfaz.
 *
 * 1. Confirmación formal de acceso (RF-04.6, Art. V.2) en `IncidentReportModal`:
 *    - `requiresAccessConfirmation` solo se enciende con `is_blocked_no_access`.
 *    - Bloque destacado, casilla obligatoria con su literal exacto y objetivo táctil.
 *    - Sin la casilla el envío no llega a la API y el borrador no se pierde.
 *    - El payload JSON y el multipart publican `access_confirmed` solo cuando procede.
 *    - `resetForm()` limpia la confirmación: es un acto por aviso, no una preferencia
 *      pegajosa (defecto real corregido junto con esta suite).
 * 2. Aviso de conversación sellada del canal de sede (RF-05.4, Art. III) en
 *    `IncidentCommentThreadModal` montado con `role="SITE_MANAGER"`:
 *    - Expediente cancelado por inactividad => rama de solo lectura, sin formulario.
 *    - Literal «Expediente archivado: conversación sellada por auditoría» con su botón.
 *    - El rechazo 403 del servidor se muestra íntegro a la sede (incluida la guía de
 *      acceso que publica RF-05.4) reteniendo el borrador (RF-07.1).
 *
 * Dogma Vanilla: Node.js ESM nativo, cero dependencias externas, cero red.
 */

// Entorno mínimo headless (localStorage del store y listeners del modal)
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
globalThis.window = globalThis.window || {
  addEventListener: () => {},
  removeEventListener: () => {}
};
globalThis.document = globalThis.document || { body: { style: { overflow: '' } } };

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPORT_PATH = path.resolve(HERE, '../../public/assets/js/components/IncidentReportModal.js');
const PORTAL_PATH = path.resolve(HERE, '../../public/assets/js/views/LocationPortalView.js');

const { api } = await import('../../public/assets/js/api.js');
const { IncidentReportModal } = await import('../../public/assets/js/components/IncidentReportModal.js');
const { IncidentCommentThreadModal } = await import('../../public/assets/js/components/IncidentCommentThreadModal.js');
const { LocationPortalView } = await import('../../public/assets/js/views/LocationPortalView.js');

const portalSource = fs.readFileSync(PORTAL_PATH, 'utf8');
const reportTemplate = String(IncidentReportModal.template || '');
const threadTemplate = String(IncidentCommentThreadModal.template || '');
const normalizedReportTemplate = reportTemplate.replace(/\s+/g, ' ');
const normalizedThreadTemplate = threadTemplate.replace(/\s+/g, ' ');

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
console.log(' VendGuard: Frontend Suite - Confirmación de acceso y aviso sellado de sede');
console.log('======================================================================\n');

/** Instancia de IncidentReportModal con binding Options API completo. */
function buildReportInstance(machine, overrides = {}) {
  const instance = {
    modelValue: true,
    machine,
    ...IncidentReportModal.data.call(IncidentReportModal),
    ...overrides,
    emitted: [],
    $emit(name, payload) { this.emitted.push({ name, payload }); }
  };

  for (const [name, fn] of Object.entries(IncidentReportModal.methods || {})) {
    instance[name] = fn.bind(instance);
  }

  for (const [name, definition] of Object.entries(IncidentReportModal.computed || {})) {
    const getter = typeof definition === 'function' ? definition : definition.get;
    const setter = typeof definition === 'function' ? null : definition.set;
    Object.defineProperty(instance, name, {
      configurable: true,
      enumerable: true,
      get: () => getter.call(instance),
      ...(setter ? { set: (value) => setter.call(instance, value) } : {})
    });
  }

  return instance;
}

/** Instancia de IncidentCommentThreadModal con binding Options API completo. */
function buildThreadInstance(overrides = {}) {
  const instance = {
    isOpen: true,
    incidentId: 501,
    ticketCode: 'INC-2026-0501',
    role: 'SITE_MANAGER',
    ...IncidentCommentThreadModal.data.call(IncidentCommentThreadModal),
    ...overrides,
    emitted: [],
    $nextTick: async () => {},
    $emit(name, payload) { this.emitted.push({ name, payload }); }
  };

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

// ─── Grupo 1: La casilla nace de la máquina bloqueada por acceso (RF-04.6) ─────
console.log('--- Grupo 1: Confirmación obligatoria de acceso en el aviso nuevo (RF-04.6) ---');

const blockedMachine = {
  id: 7,
  code: 'VEND-0007',
  model: 'Necta Krea Prime',
  machine_type: 'COLD_DRINKS',
  floor_wing: 'Planta 1 - Consultas',
  is_blocked_no_access: true,
  active_incident: null
};

const freeMachine = {
  id: 8,
  code: 'VEND-0008',
  model: 'Necta Krea Prime',
  machine_type: 'COLD_DRINKS',
  floor_wing: 'Planta 2 - Laboratorio',
  is_blocked_no_access: false,
  active_incident: null
};

assert('1.1 Solo la máquina bloqueada por falta de acceso exige la confirmación formal',
  buildReportInstance(blockedMachine).requiresAccessConfirmation === true
    && buildReportInstance(freeMachine).requiresAccessConfirmation === false,
  `bloqueada=${buildReportInstance(blockedMachine).requiresAccessConfirmation} libre=${buildReportInstance(freeMachine).requiresAccessConfirmation}`);

assert('1.2 Sin máquina no se exige confirmación (ni se inventa el bloqueo)',
  buildReportInstance(null).requiresAccessConfirmation === false);

assert('1.3 El bloque de confirmación está condicionado a requiresAccessConfirmation',
  /v-if="requiresAccessConfirmation"[\s\S]{0,400}data-testid="access-confirmation-block"/.test(reportTemplate));

assert('1.4 La casilla declara el literal exacto de la spec y viaja con v-model',
  normalizedReportTemplate.includes('Confirmo formalmente que las instalaciones y la máquina se encuentran abiertas y accesibles para el servicio técnico.')
    && reportTemplate.includes('v-model="accessConfirmed"')
    && reportTemplate.includes('data-testid="access-confirmation-checkbox"'));

assert('1.5 El objetivo táctil de la casilla cumple el mínimo de 44 px (RNF-03)',
  /min-height: 44px[\s\S]{0,420}data-testid="access-confirmation-checkbox"/.test(reportTemplate));

assert('1.6 El botón de registro se deshabilita sin la casilla marcada',
  reportTemplate.includes('(requiresAccessConfirmation && !accessConfirmed)'));

// ─── Grupo 2: El envío no pasa sin la casilla y el payload es honesto ─────────
console.log('\n--- Grupo 2: Bloqueo del envío y payload de acceso (RF-04.6, Art. V.2) ---');

let createCalls = [];
api.incidents.create = async (payload) => {
  createCalls.push(payload);
  return { id: 900, ticket_code: 'INC-2026-0900', status: 'REGISTERED', machine_id: 7 };
};

createCalls = [];
const blockedUnchecked = buildReportInstance(blockedMachine, {
  description: 'La máquina no dispensa y la sala sigue cerrada.',
  reporterName: 'Laura Sede',
  reporterPhone: '600111222'
});
await blockedUnchecked.handleSubmitReport();

assert('2.1 Sin la casilla marcada no se llama a la API de creación',
  createCalls.length === 0,
  `llamadas=${createCalls.length}`);

assert('2.2 El error nombra la casilla obligatoria y el requisito (RF-04.6)',
  blockedUnchecked.errorMessage.includes('casilla de confirmación formal de acceso')
    && blockedUnchecked.errorMessage.includes('RF-04.6'),
  blockedUnchecked.errorMessage);

assert('2.3 El borrador no se pierde tras el bloqueo y no queda envío en curso',
  blockedUnchecked.description === 'La máquina no dispensa y la sala sigue cerrada.'
    && blockedUnchecked.isSubmitting === false);

createCalls = [];
const blockedChecked = buildReportInstance(blockedMachine, {
  accessConfirmed: true,
  description: 'La máquina no dispensa; confirmo que la sala ya está abierta.',
  reporterName: 'Laura Sede',
  reporterPhone: '600111222'
});
await blockedChecked.handleSubmitReport();

assert('2.4 Con la casilla marcada el alta viaja con access_confirmed === true',
  createCalls.length === 1 && createCalls[0].access_confirmed === true,
  JSON.stringify(createCalls[0] ?? null));

assert('2.5 La confirmación acompaña al aviso de la máquina bloqueada correcta',
  createCalls[0]?.machine_id === 7
    && blockedChecked.emitted.some((event) => event.name === 'created'),
  `machine_id=${createCalls[0]?.machine_id}`);

createCalls = [];
const freeSubmit = buildReportInstance(freeMachine, {
  description: 'La máquina no enfría lo suficiente.',
  reporterName: 'Laura Sede',
  reporterPhone: '600111222'
});
await freeSubmit.handleSubmitReport();

assert('2.6 Una máquina no bloqueada no publica access_confirmed en el contrato',
  createCalls.length === 1 && !Object.prototype.hasOwnProperty.call(createCalls[0], 'access_confirmed'),
  JSON.stringify(createCalls[0] ?? null));

createCalls = [];
const blockedWithPhoto = buildReportInstance(blockedMachine, {
  accessConfirmed: true,
  description: 'Adjunto foto de la sala abierta.',
  reporterName: 'Laura Sede',
  reporterPhone: '600111222',
  photoFile: new Blob([new Uint8Array(64)], { type: 'image/jpeg' })
});
await blockedWithPhoto.handleSubmitReport();

assert('2.7 La vía multipart también publica la confirmación de acceso',
  createCalls.length === 1
    && createCalls[0] instanceof FormData
    && createCalls[0].get('access_confirmed') === 'true'
    && createCalls[0].get('machine_id') === '7',
  createCalls[0] instanceof FormData
    ? `access_confirmed=${createCalls[0].get('access_confirmed')}`
    : 'el payload no fue FormData');

// ─── Grupo 3: La confirmación es un acto por aviso (defecto corregido) ────────
console.log('\n--- Grupo 3: La confirmación no sobrevive a la reapertura del modal ---');

assert('3.1 El estado inicial de la confirmación es siempre falso',
  IncidentReportModal.data().accessConfirmed === false);

const resetCheck = buildReportInstance(blockedMachine, { accessConfirmed: true });
resetCheck.resetForm();
assert('3.2 resetForm() limpia la confirmación formal de acceso',
  resetCheck.accessConfirmed === false,
  `accessConfirmed=${resetCheck.accessConfirmed}`);

const reopenCheck = buildReportInstance(blockedMachine, { accessConfirmed: true });
IncidentReportModal.watch.modelValue.call(reopenCheck, true);
assert('3.3 Reabrir el modal vuelve a exigir la marca expresa del usuario',
  reopenCheck.accessConfirmed === false,
  `accessConfirmed=${reopenCheck.accessConfirmed}`);

// ─── Grupo 4: Aviso de conversación sellada en el canal de sede (RF-05.4) ─────
console.log('\n--- Grupo 4: Aviso de sellado del portal de sede (RF-05.4) ---');

assert('4.1 El portal de sede monta el hilo con el canal SITE_MANAGER',
  /<IncidentCommentThreadModal[\s\S]{0,500}role="SITE_MANAGER"/.test(portalSource)
    && portalSource.includes(':is-open="showCommentsModal"')
    && portalSource.includes('@close="onCloseComments"'),
  'se esperaba el modal de hilo con role SITE_MANAGER en LocationPortalView');

const sealedSiteModal = buildThreadInstance({
  thread: {
    incident: {
      id: 501,
      ticket_code: 'INC-2026-0501',
      status: 'CANCELLED',
      is_sealed: true,
      can_comment: false
    }
  }
});

assert('4.2 Un expediente cancelado por inactividad activa el modo sellado',
  sealedSiteModal.isSealed === true && sealedSiteModal.isReadOnlyReopened === false);

assert('4.3 Un expediente activo conserva el formulario y no se marca sellado',
  buildThreadInstance({
    thread: { incident: { id: 502, status: 'IN_PROGRESS', is_sealed: false, can_comment: true } }
  }).isSealed === false);

assert('4.4 El aviso muestra el literal auditado con su rol de alerta',
  normalizedThreadTemplate.includes('Expediente archivado: conversación sellada por auditoría')
    && /data-testid="incident-comment-sealed-notice"[\s\S]{0,160}role="alert"/.test(threadTemplate));

const sealedBranch = threadTemplate.slice(
  threadTemplate.indexOf('v-if="isSealed"'),
  threadTemplate.indexOf('v-else-if="isReadOnlyReopened"')
);
assert('4.5 La rama sellada ofrece solo el cierre y excluye el formulario editable',
  sealedBranch.includes('data-testid="incident-comment-sealed-notice"')
    && sealedBranch.includes('data-testid="incident-comment-footer-close"')
    && !sealedBranch.includes('incident-comment-form'),
  sealedBranch.slice(0, 120));

assert('4.6 El formulario editable queda en el v-else de la cadena de solo lectura',
  /<form\s+v-else/.test(threadTemplate) && threadTemplate.includes('class="incident-comment-form"'));

assert('4.7 El canal de sede consulta el endpoint de ubicación del expediente',
  sealedSiteModal.channelEndpoint === '/location/incidents/501/comments',
  sealedSiteModal.channelEndpoint);

// ─── Grupo 5: El rechazo 403 llega a la sede con su guía (RF-05.4, RF-07.1) ───
console.log('\n--- Grupo 5: El rechazo del servidor se muestra íntegro a la sede (RF-05.4) ---');

const sealedServerMessage = 'El expediente está archivado: la conversación quedó sellada por auditoría y no admite nuevos mensajes.'
  + ' Para solicitar una nueva asistencia, confirme el acceso a la máquina y registre un nuevo aviso marcando la casilla:'
  + ' «Confirmo formalmente que las instalaciones y la máquina se encuentran abiertas y accesibles para el servicio técnico».';

let postCalls = [];
api.post = async (url, payload) => {
  postCalls.push({ url, payload });
  const error = new Error(sealedServerMessage);
  error.status = 403;
  error.code = 'CONVERSATION_SEALED';
  throw error;
};

const sealedRejectModal = buildThreadInstance({
  thread: {
    incident: { id: 501, ticket_code: 'INC-2026-0501', status: 'IN_PROGRESS', is_sealed: false, can_comment: true }
  },
  commentText: 'La sala ya está abierta, puede pasar cuando quiera.'
});
await sealedRejectModal.submitComment();

assert('5.1 El comentario de sede se publica por su endpoint antes del rechazo',
  postCalls.length === 1 && postCalls[0].url === '/location/incidents/501/comments',
  JSON.stringify(postCalls.map((call) => call.url)));

assert('5.2 La sede ve la guía de acceso que exige RF-05.4 en el rechazo',
  sealedRejectModal.formError.includes('confirme el acceso a la máquina')
    && sealedRejectModal.formError.includes('nueva asistencia'),
  sealedRejectModal.formError);

assert('5.3 El rechazo retiene el borrador íntegro para el reintento (RF-07.1)',
  sealedRejectModal.commentText === 'La sala ya está abierta, puede pasar cuando quiera.'
    && sealedRejectModal.isSubmitting === false);

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. COBERTURA DEL PORTAL DE SEDE CERRADA.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.log(' RESULTADO: FALLO EN LA COBERTURA DEL PORTAL DE SEDE.');
console.log('======================================================================');
process.exit(1);
