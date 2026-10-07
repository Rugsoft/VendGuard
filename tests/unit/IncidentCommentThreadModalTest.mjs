/**
 * VendGuard - IncidentCommentThreadModal Consolidated Reactive Suite (IncidentCommentThreadModalTest.mjs)
 *
 * Suite canónica exigida por `specs/10-incident-comments/tasks.md` (T-COM-17) y
 * `plan.md` §6.2. Ejercita el comportamiento reactivo real del modal del hilo de
 * comentarios (Módulo 10) verificando las cinco condiciones del "Hecho cuando":
 *
 * 1. Renderizado de bocadillos ámbar `#fef9c3` (borde `#fde047`) con candado SVG y
 *    etiqueta "Nota Interna de Taller (Confidencial)" para las notas internas, frente
 *    al gris neutro `#e5e7eb` de los comentarios públicos (RF-02.4, RNF-04).
 * 2. Selector de privacidad preseleccionado en 'INTERNAL' para técnicos y coordinadores y
 *    completamente ausente para responsables de sede (RF-03.3).
 * 3. Reactividad del contador: envío deshabilitado con 4 caracteres y habilitado
 *    automáticamente desde el 5º hasta 1.000 caracteres (RF-03.1).
 * 4. Guardián de formulario sucio interceptando las pulsaciones de `Escape` (y el clic en
 *    el fondo sombreado) cuando hay texto o foto en borrador (RNF-06).
 * 5. Aviso de auditoría "Expediente archivado: conversación sellada por auditoría" en modo
 *    sellado de solo lectura (RF-05.3).
 *
 * Dogma Vanilla: Node.js ESM nativo, cero dependencias externas y cero red real (el
 * cliente `api.js` se sustituye por un doble que registra las peticiones).
 * Dualismo Lingüístico: identificadores en inglés, mensajes y comentarios en castellano.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

// Entorno mínimo de navegador para ejercitar el ciclo de vida del modal sin DOM real.
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
console.log(' VendGuard: Frontend Suite - Hilo de Comentarios Reactivo (T-COM-17)');
console.log('======================================================================\n');

// ─── Dobles del transporte HTTP: registran peticiones y devuelven el DTO fijado ───
let getCalls = [];
let postCalls = [];
let uploadCalls = [];
let nextGetResponse = null;
let nextGetFailure = null;

api.get = async (endpoint) => {
  getCalls.push(endpoint);
  if (nextGetFailure) {
    throw nextGetFailure;
  }
  return nextGetResponse;
};

api.post = async (endpoint, payload) => {
  postCalls.push({ endpoint, payload });
  return nextGetResponse;
};

api.upload = async (endpoint, formData) => {
  uploadCalls.push({ endpoint, formData });
  return nextGetResponse;
};

const INCIDENT_ACTIVE = {
  id: 142,
  ticket_code: 'TICK-2026-00142',
  machine_code: 'VEN-BCN-001',
  machine_model: 'CoffeMax Pro 3000',
  location_name: 'Hospital del Mar',
  status: 'IN_PROGRESS',
  status_label: 'En Reparación',
  is_sealed: false
};

const makeComment = (id, overrides = {}) => ({
  id,
  author_type: 'TECHNICIAN',
  author_name: 'Carlos Pérez',
  comment_text: `Mensaje ${id}`,
  photo_url: null,
  is_internal: false,
  created_at: '2026-10-06 11:30:12',
  is_own_message: false,
  ...overrides
});

/** Bloque mixto: público ajeno, nota interna y mensaje propio público. */
const MIXED_BLOCK = () => ([
  makeComment(48, {
    author_type: 'REPORTER',
    author_name: 'Conserjería Principal',
    comment_text: 'La máquina está junto a los ascensores B.'
  }),
  makeComment(49, {
    is_internal: true,
    comment_text: 'Fusible recalentado: posible corto en electroválvula.'
  }),
  makeComment(50, {
    is_internal: true,
    is_own_message: true,
    comment_text: 'Repuesto localizado en el almacén de ruta.'
  }),
  makeComment(51, {
    is_own_message: true,
    comment_text: 'Llegando al edificio en 10 minutos.'
  })
]);

const makeThreadDto = (comments, incidentOverrides = {}, paginationOverrides = {}) => ({
  incident: { ...INCIDENT_ACTIVE, ...incidentOverrides },
  pagination: {
    total_comments: comments.length,
    loaded_count: comments.length,
    has_more_before: false,
    oldest_id: comments.length > 0 ? comments[0].id : null,
    latest_id: comments.length > 0 ? comments[comments.length - 1].id : null,
    ...paginationOverrides
  },
  comments
});

/**
 * Instancia con el binding de Options API: datos reactivos reales del componente,
 * métodos enlazados a la instancia, computadas resueltas como getters y contenedor de
 * scroll simulado que crece con los mensajes en pantalla.
 */
function buildInstance(overrides = {}) {
  const instance = Object.create(IncidentCommentThreadModal);
  const data = typeof IncidentCommentThreadModal.data === 'function'
    ? IncidentCommentThreadModal.data.call(instance)
    : {};

  Object.assign(instance, {
    isOpen: true,
    incidentId: null,
    ticketCode: 'TICK-2026-00142',
    role: 'TECHNICIAN',
    ...data,
    ...overrides,
    emitted: [],
    $emit(name, payload) { this.emitted.push({ name, payload }); },
    $nextTick: () => Promise.resolve(),
    $refs: {
      photoInput: { click: () => { instance._photoInputClicked = true; } },
      threadScroll: {
        scrollTop: 0,
        get scrollHeight() { return 480 + (instance.comments.length * 120); }
      }
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

// ─── Grupo 1: Bocadillos ámbar con candado y etiqueta (RF-02.4, RNF-04) ──────
console.log('--- Grupo 1: Bocadillos ámbar con candado y etiqueta (RF-02.4) ---');

const viewerModal = buildInstance();

const internalBubble = viewerModal.bubbleStyle(makeComment(49, { is_internal: true }));
assert('1.1 La nota interna usa el fondo ámbar #fef9c3 y el borde #fde047',
  internalBubble.backgroundColor === '#fef9c3' && internalBubble.border === '1px solid #fde047',
  `bocadillo real: ${JSON.stringify(internalBubble)}`);

const publicBubble = viewerModal.bubbleStyle(makeComment(48));
assert('1.2 El comentario público usa el gris neutro #e5e7eb con borde hairline',
  publicBubble.backgroundColor === '#e5e7eb' && publicBubble.border === '1px solid var(--color-hairline, #c8cfda)',
  `bocadillo real: ${JSON.stringify(publicBubble)}`);

const ownBubble = viewerModal.bubbleStyle(makeComment(51, { is_own_message: true }));
assert('1.3 El mensaje propio se alinea al extremo con azul suave corporativo',
  ownBubble.alignSelf === 'flex-end' && ownBubble.backgroundColor === 'var(--color-primary-subtle, #e5f2fc)');

assert('1.4 El bocadillo interno nunca hereda el azul de los mensajes propios',
  internalBubble.backgroundColor !== ownBubble.backgroundColor
    && viewerModal.bubbleStyle(makeComment(49, { is_internal: true, is_own_message: true })).backgroundColor === '#fef9c3');

assert('1.5 La plantilla marca las notas internas con la clase condicional is-internal (plan.md §6.2)',
  template.includes('class="incident-comment-item"')
    && template.includes(":class=\"{ 'is-internal': comment.is_internal }\"")
    && /:data-testid="'incident-comment-item-' \+ comment\.id"\s*\n\s*:style="bubbleStyle\(comment\)"/.test(template.replace(/\s+:class=[^\n]*\n/g, '\n')));

assert('1.6 El candado es un SVG real (rect + arco) con marcador de prueba por mensaje',
  template.includes(":data-testid=\"'incident-comment-lock-' + comment.id\"")
    && /<rect x="3" y="11" width="18" height="11" rx="2" ry="2"><\/rect>\s*\n\s*<path d="M7 11V7a5 5 0 0 1 10 0v4"><\/path>/.test(template));

assert('1.7 La etiqueta de confidencialidad se rotula sobre la nota interna',
  template.includes('Nota Interna de Taller (Confidencial)')
    && template.includes(":data-testid=\"'incident-comment-internal-' + comment.id\"")
    && template.includes('aria-label="🔒 Nota Interna de Taller (Confidencial)"'));

assert('1.8 La etiqueta interna se renderiza condicionada a comment.is_internal',
  /<div\s*\n\s*v-if="comment\.is_internal"\s*\n\s*class="incident-comment-internal-tag"/.test(template));

// Recorrido real: cargar el hilo mixto por el canal del técnico y comprobar cada burbuja.
getCalls = [];
nextGetFailure = null;
nextGetResponse = makeThreadDto(MIXED_BLOCK());

const threadModal = buildInstance({ incidentId: 142, role: 'TECHNICIAN' });
await threadModal.loadThread();

assert('1.9 El hilo mixto se carga por el endpoint del técnico con su bloque de 50 mensajes',
  getCalls.length === 1 && getCalls[0] === '/technician/incidents/142/comments?limit=50',
  `llamadas reales: ${JSON.stringify(getCalls)}`);

const internalRendered = threadModal.comments.filter((c) => c.is_internal === true);
assert('1.10 El hilo conserva las notas internas para el técnico (2 de 4 mensajes)',
  internalRendered.length === 2 && internalRendered.every((c) => c.comment_text.length > 0));

assert('1.11 Cada nota interna cargada se pinta en ámbar y los públicos en gris',
  threadModal.comments.every((c) => threadModal.bubbleStyle(c).backgroundColor === (c.is_internal ? '#fef9c3' : (c.is_own_message ? 'var(--color-primary-subtle, #e5f2fc)' : '#e5e7eb'))));

assert('1.12 El técnico ve el nombre nominal completo de sus compañeros en el hilo',
  threadModal.comments.some((c) => c.author_name === 'Carlos Pérez'));

// ─── Grupo 2: Selector de privacidad preseleccionado y ausente en sede (RF-03.3) ──
console.log('\n--- Grupo 2: Selector de privacidad por rol (RF-03.3) ---');

const technicianForm = buildInstance({ role: 'TECHNICIAN' });
assert('2.1 El técnico arranca con la privacidad preseleccionada en INTERNAL',
  technicianForm.privacyChoice === 'INTERNAL' && technicianForm.showPrivacySelector === true);

const coordinatorForm = buildInstance({ role: 'COORDINATOR' });
assert('2.2 El coordinador arranca igualmente preseleccionado en INTERNAL',
  coordinatorForm.privacyChoice === 'INTERNAL' && coordinatorForm.showPrivacySelector === true);

const siteForm = buildInstance({ role: 'SITE_MANAGER' });
assert('2.3 El responsable de sede no dispone del selector de privacidad',
  siteForm.showPrivacySelector === false);

const freshDefaults = typeof IncidentCommentThreadModal.data === 'function'
  ? IncidentCommentThreadModal.data.call(Object.create(IncidentCommentThreadModal))
  : {};
assert('2.4 El valor por defecto del estado reactivo es INTERNAL (preselección segura)',
  freshDefaults.privacyChoice === 'INTERNAL');

coordinatorForm.privacyChoice = 'PUBLIC';
coordinatorForm.resetState();
assert('2.5 Al resetear el modal la privacidad vuelve a INTERNAL, no a PUBLIC',
  coordinatorForm.privacyChoice === 'INTERNAL');

assert('2.6 La plantilla declara el grupo de radios bajo v-if="showPrivacySelector"',
  /v-if="showPrivacySelector"/.test(template)
    && template.includes('data-testid="incident-comment-privacy-selector"')
    && template.includes('data-testid="incident-comment-privacy-internal"')
    && template.includes('data-testid="incident-comment-privacy-public"')
    && (template.match(/name="privacyChoice"/g) || []).length === 2);

assert('2.7 Ambos radios van enlazados al modelo reactivo privacyChoice',
  (template.match(/v-model="privacyChoice"/g) || []).length === 2
    && /value="INTERNAL"[\s\S]{0,120}v-model="privacyChoice"/.test(template));

assert('2.8 La opción interna describe el taller confidencial y la pública el mensaje para sede',
  template.includes('🔒 Nota Interna de Taller (Confidencial)')
    && template.includes('🌐 Mensaje para Sede (Público)'));

// Garantía de segregación en el envío: la sede jamás puede marcar un comentario interno.
postCalls = [];
nextGetResponse = makeThreadDto(MIXED_BLOCK());
const siteSubmit = buildInstance({ role: 'SITE_MANAGER', incidentId: 142 });
siteSubmit.commentText = 'La avería persiste tras el reinicio indicado.';
await siteSubmit.submitComment();

assert('2.9 El responsable de sede publica sin la marca is_internal en el payload (Art. V.4)',
  postCalls.length === 1
    && postCalls[0].endpoint === '/location/incidents/142/comments'
    && !Object.prototype.hasOwnProperty.call(postCalls[0].payload, 'is_internal'),
  `payload real: ${JSON.stringify(postCalls[0]?.payload)}`);

// ─── Grupo 3: Contador reactivo y bloqueo del envío (RF-03.1) ────────────────
console.log('\n--- Grupo 3: Contador reactivo y bloqueo del envío (RF-03.1) ---');

const counterModal = buildInstance({ incidentId: 142, role: 'TECHNICIAN' });

assert('3.1 En reposo el contador marca 1.000 restantes y el envío está bloqueado',
  counterModal.remainingChars === 1000
    && counterModal.isCommentValid === false
    && counterModal.isSubmitDisabled === true);

counterModal.commentText = '1234';
assert('3.2 Con 4 caracteres el envío sigue deshabilitado (mínimo 5)',
  counterModal.isCommentValid === false && counterModal.isSubmitDisabled === true);

counterModal.commentText = '12345';
assert('3.3 Con 5 caracteres exactos el envío se habilita y quedan 995',
  counterModal.isCommentValid === true && counterModal.isSubmitDisabled === false && counterModal.remainingChars === 995);

counterModal.commentText = 'a'.repeat(1000);
assert('3.4 Con 1.000 caracteres el envío sigue habilitado y el contador marca 0',
  counterModal.isCommentValid === true && counterModal.isSubmitDisabled === false && counterModal.remainingChars === 0);

counterModal.commentText = 'a'.repeat(1001);
assert('3.5 Con 1.001 caracteres el límite superior bloquea el envío',
  counterModal.isCommentValid === false && counterModal.isSubmitDisabled === true && counterModal.remainingChars === -1);

counterModal.commentText = '   1234   ';
assert('3.6 Un borrador que solo alcanza 4 caracteres tras recortar espacios no es válido',
  counterModal.isCommentValid === false && counterModal.isSubmitDisabled === true);

// Recorrido carácter a carácter: el botón se habilita automáticamente en el 5º.
const typingModal = buildInstance({ incidentId: 142, role: 'TECHNICIAN' });
const disabledWhileTyping = [];
for (let length = 1; length <= 5; length++) {
  typingModal.commentText = 'b'.repeat(length);
  disabledWhileTyping.push(typingModal.isSubmitDisabled);
}

assert('3.7 El envío permanece bloqueado del 1er al 4º carácter',
  disabledWhileTyping.slice(0, 4).every((disabled) => disabled === true));

assert('3.8 El envío se habilita automáticamente al teclear el 5º carácter',
  disabledWhileTyping[4] === false);

assert('3.9 La plantilla declara el textarea con límites 5–1.000 y botón ligado a isSubmitDisabled',
  template.includes('data-testid="incident-comment-textarea"')
    && template.includes('minlength="5"')
    && template.includes('maxlength="1000"')
    && template.includes('data-testid="incident-comment-submit"')
    && template.includes(':disabled="isSubmitDisabled"')
    && template.includes('data-testid="incident-comment-char-counter"')
    && template.includes('caracteres restantes'));

assert('3.10 El marcador de posición cambia según la privacidad elegida (técnico y coordinador)',
  template.includes("showPrivacySelector && privacyChoice === 'INTERNAL'")
    && template.includes('Escriba una nota técnica interna para el equipo de taller…')
    && template.includes('Escriba un mensaje sobre el estado o avance de la avería…'));

// ─── Grupo 4: Guardián de formulario sucio interceptando Escape (RNF-06) ─────
console.log('\n--- Grupo 4: Guardián de formulario sucio vs Escape (RNF-06) ---');

let confirmCalls = [];
let nextConfirmResult = true;
globalThis.confirm = (message) => {
  confirmCalls.push(message);
  return nextConfirmResult;
};

assert('4.1 Sin borrador isDirty es falso; con texto o foto pasa a verdadero',
  buildInstance().isDirty === false
    && (() => { const m = buildInstance(); m.commentText = 'Borrador técnico'; return m.isDirty === true; })()
    && (() => { const m = buildInstance(); m.photoFile = { name: 'evidencia.jpg', size: 1024, type: 'image/jpeg' }; return m.isDirty === true; })());

confirmCalls = [];
const cleanEscape = buildInstance({ incidentId: 142 });
cleanEscape.handleKeyDown({ key: 'Escape' });
assert('4.2 Sin borrador, Escape cierra el modal sin invocar confirmación',
  confirmCalls.length === 0 && cleanEscape.emitted.length === 1 && cleanEscape.emitted[0].name === 'close');

confirmCalls = [];
nextConfirmResult = false;
const dirtyEscape = buildInstance({ incidentId: 142 });
dirtyEscape.commentText = 'Notas técnicas que no quiero perder';
dirtyEscape.handleKeyDown({ key: 'Escape' });

assert('4.3 Escape con borrador intercepta el cierre y pide confirmación explícita',
  confirmCalls.length === 1 && confirmCalls[0] === '¿Descartar mensaje en redacción?');

assert('4.4 Si el usuario cancela, no se emite close y el texto queda intacto',
  dirtyEscape.emitted.length === 0 && dirtyEscape.commentText === 'Notas técnicas que no quiero perder');

confirmCalls = [];
nextConfirmResult = true;
const acceptingEscape = buildInstance({ incidentId: 142 });
acceptingEscape.commentText = 'Borrador que se descarta voluntariamente';
acceptingEscape.handleKeyDown({ key: 'Escape' });

assert('4.5 Si el usuario acepta el descarte, Escape cierra el modal una única vez',
  confirmCalls.length === 1 && acceptingEscape.emitted.length === 1 && acceptingEscape.emitted[0].name === 'close');

confirmCalls = [];
nextConfirmResult = false;
const dirtyBackdrop = buildInstance({ incidentId: 142 });
dirtyBackdrop.photoFile = { name: 'adjunto.png', size: 2048, type: 'image/png' };
const backdropElement = { id: 'backdrop' };
dirtyBackdrop.handleBackdropClick({ target: backdropElement, currentTarget: backdropElement });

assert('4.6 El clic en el fondo sombreado también pasa por el guardián y conserva la foto',
  confirmCalls.length === 1 && dirtyBackdrop.emitted.length === 0 && dirtyBackdrop.photoFile !== null);

confirmCalls = [];
nextConfirmResult = false;
const dirtyOtherKey = buildInstance({ incidentId: 142 });
dirtyOtherKey.commentText = 'Texto en edición';
dirtyOtherKey.handleKeyDown({ key: 'Enter' });

assert('4.7 Otras teclas no disparan el guardián ni cierran el hilo',
  confirmCalls.length === 0 && dirtyOtherKey.emitted.length === 0);

assert('4.8 Todas las vías de cierre del modal pasan por requestClose (RF-07.1, RNF-06)',
  componentSource.includes('requestClose()')
    && (template.match(/@click="requestClose"/g) || []).length >= 2
    && template.includes('@click.self="handleBackdropClick"'));

// ─── Grupo 5: Aviso de auditoría en modo sellado de solo lectura (RF-05.3) ───
console.log('\n--- Grupo 5: Modo sellado de solo lectura (RF-05.3) ---');

const sealedClosed = buildInstance({ thread: { incident: { ...INCIDENT_ACTIVE, status: 'CLOSED', is_sealed: true } } });
assert('5.1 Un expediente CLOSED se marca como sellado (isSealed === true)',
  sealedClosed.isSealed === true);

const sealedCancelled = buildInstance({ thread: { incident: { ...INCIDENT_ACTIVE, status: 'CANCELLED', is_sealed: true } } });
assert('5.2 Un expediente CANCELLED también se marca como sellado',
  sealedCancelled.isSealed === true);

const activeThread = buildInstance({ thread: { incident: { ...INCIDENT_ACTIVE } } });
assert('5.3 Un expediente activo o en garantía no se sella',
  activeThread.isSealed === false);

assert('5.4 La plantilla muestra el aviso de auditoría condicionado a v-if="isSealed"',
  /<div\s*\n\s*v-if="isSealed"\s*\n\s*class="incident-comment-sealed-notice"/.test(template)
    && template.includes('data-testid="incident-comment-sealed-notice"'));

assert('5.5 El aviso reproduce literalmente el texto normativo de sellado',
  template.includes('Expediente archivado: conversación sellada por auditoría'));

assert('5.6 En modo sellado se oculta el formulario y se ofrece el cierre del hilo',
  /v-if="isSealed"/.test(template)
    && /v-else\s*\n\s*class="incident-comment-form"/.test(template)
    && template.includes('data-testid="incident-comment-sealed-notice"')
    && template.includes('data-testid="incident-comment-footer-close"'));

assert('5.7 El aviso de sellado se anuncia como alerta accesible',
  /data-testid="incident-comment-sealed-notice"\s*\n\s*role="alert"/.test(template));

// Recorrido real: cargar un expediente sellado y comprobar el modo de solo lectura.
getCalls = [];
nextGetFailure = null;
nextGetResponse = makeThreadDto(
  MIXED_BLOCK(),
  { status: 'CLOSED', status_label: 'Cerrada', is_sealed: true }
);

const sealedLoaded = buildInstance({ incidentId: 142, role: 'COORDINATOR' });
await sealedLoaded.loadThread();

assert('5.8 El hilo sellado se carga igualmente en solo lectura, sin perder los mensajes',
  sealedLoaded.isSealed === true && sealedLoaded.comments.length === 4);

assert('5.9 En modo sellado el contador y el borrador quedan vacíos (no hay formulario activo)',
  sealedLoaded.commentText === '' && sealedLoaded.isSubmitDisabled === true);

// ─── Grupo 6: Recorrido integrado del hilo por canal (RF-01.2, RF-02.4, RF-03.1) ──
console.log('\n--- Grupo 6: Recorrido integrado del hilo ---');

getCalls = [];
postCalls = [];
nextGetFailure = null;
nextGetResponse = makeThreadDto(MIXED_BLOCK());

const journey = buildInstance({ incidentId: 142, role: 'TECHNICIAN' });
await journey.loadThread();

assert('6.1 Al cargar, el cuerpo se auto-desplaza hasta el mensaje más reciente',
  journey.$refs.threadScroll.scrollTop === journey.$refs.threadScroll.scrollHeight
    && journey.$refs.threadScroll.scrollTop > 0);

assert('6.2 El hilo quedó en orden cronológico ascendente (el más antiguo primero)',
  journey.comments.map((c) => c.id).join(',') === '48,49,50,51');

// El técnico escribe una nota interna: la preselección INTERNAL viaja en el payload.
journey.commentText = 'Válvula sustituida, pendiente de prueba de presión.';
assert('6.3 Con el borrador válido el envío queda habilitado sin tocar el selector',
  journey.privacyChoice === 'INTERNAL' && journey.isSubmitDisabled === false);

nextGetResponse = makeThreadDto(MIXED_BLOCK());
await journey.submitComment();

assert('6.4 La nota interna se publica marcada como is_internal: true por defecto',
  postCalls.length === 1 && postCalls[0].payload.is_internal === true
    && postCalls[0].payload.comment_text === 'Válvula sustituida, pendiente de prueba de presión.');

assert('6.5 Tras publicar, el modal emite comment-added y limpia el borrador',
  journey.emitted.some((e) => e.name === 'comment-added')
    && journey.commentText === ''
    && journey.privacyChoice === 'INTERNAL');

// El responsable de sede solo recibe comentarios públicos: ninguna burbuja ámbar.
getCalls = [];
nextGetResponse = makeThreadDto(MIXED_BLOCK().filter((c) => c.is_internal !== true));

const siteJourney = buildInstance({ incidentId: 142, role: 'SITE_MANAGER' });
await siteJourney.loadThread();

assert('6.6 El portal de sede consulta su propio endpoint y solo ve mensajes públicos',
  getCalls[0] === '/location/incidents/142/comments?limit=50'
    && siteJourney.comments.length === 2
    && siteJourney.comments.every((c) => c.is_internal !== true));

assert('6.7 Ninguna burbuja del hilo de sede se pinta en ámbar (cero fugas visuales, Art. V.4)',
  siteJourney.comments.every((c) => siteJourney.bubbleStyle(c).backgroundColor !== '#fef9c3'));

// ─── Grupo 7: Sanidad de la plantilla del formulario y del aviso ─────────────
console.log('\n--- Grupo 7: Sanidad del marcado ---');

const footerIndex = template.indexOf('data-testid="incident-comment-footer"');
const sealedIndex = template.indexOf('data-testid="incident-comment-sealed-notice"');
const formIndex = template.indexOf('data-testid="incident-comment-form"');

assert('7.1 El aviso de sellado y el formulario son mutuamente excluyentes dentro del pie',
  footerIndex > -1 && sealedIndex > footerIndex && formIndex > sealedIndex);

assert('7.2 El cuerpo del hilo mantiene la lista de mensajes con aria-live para lectores de pantalla',
  template.includes('data-testid="incident-comment-thread"')
    && template.includes('aria-live="polite"')
    && template.includes('v-for="comment in comments"'));

assert('7.3 El modal deja el contrato de eventos intacto para las vistas operativas',
  Array.isArray(IncidentCommentThreadModal.emits)
    && IncidentCommentThreadModal.emits.includes('close')
    && IncidentCommentThreadModal.emits.includes('comment-added'));

assert('7.4 Dogma Vanilla: el componente sigue usando api.js y todos sus imports son relativos',
  componentSource.includes("import { api } from '../api.js'")
    && !componentSource.includes('fetch(')
    && [...componentSource.matchAll(/^\s*import\s+[^'"]*['"]([^'"]+)['"]/gm)]
      .map((m) => m[1])
      .every((specifier) => specifier.startsWith('./') || specifier.startsWith('../')));

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-COM-17 FULFILLED.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.log(' RESULT: FAILURES DETECTED IN TEST SUITE.');
console.log('======================================================================\n');
process.exit(1);
