/**
 * VendGuard - IncidentCommentThreadModal Viewer Test Suite
 * (IncidentCommentThreadModalViewerTest.mjs)
 *
 * Verifica la condición "Hecho cuando" de T-COM-10 (módulo 10, visor cronológico del
 * hilo de conversación sobre el scaffold de T-COM-09):
 *
 * 1. El hilo se carga por el canal del usuario (`role`) y el cuerpo se auto-desplaza
 *    hasta el mensaje más reciente al abrir (RF-01.2, RF-01.4).
 * 2. Bocadillos diferenciados: comentarios públicos en gris neutro, mensajes propios en
 *    azul suave y notas internas en ámbar `#fef9c3` con borde `#fde047`, candado SVG y
 *    etiqueta "Nota Interna de Taller (Confidencial)" (RF-02.3, RF-02.4, RNF-04).
 * 3. Miniaturas fotográficas con visor ampliado y recuadro de sustitución
 *    "Evidencia gráfica no disponible" cuando el archivo no carga (RF-04.4).
 * 4. "Cargar mensajes anteriores" recupera el bloque previo por cursor sin duplicados y
 *    preservando el punto de lectura visual, sin saltos (RF-01.3).
 * 5. El orden cronológico del bloque se respeta y los errores se muestran en línea sin
 *    perder los mensajes ya cargados (RF-07.1).
 *
 * Dogma Vanilla: Node.js ESM nativo, cero dependencias externas, cero red real (el
 * cliente `api.get` se sustituye por un doble que registra las peticiones).
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
console.log(' VendGuard: Frontend Suite - Visor del Hilo de Comentarios (T-COM-10)');
console.log('======================================================================\n');

// ─── Doble del transporte HTTP: registra peticiones y devuelve el DTO indicado ──
let apiCalls = [];
let nextResponse = null;
let nextFailure = null;
/** Modo manual: las peticiones quedan retenidas hasta que la prueba las resuelve. */
let manualRequests = false;
let pendingResolvers = [];

api.get = async (endpoint) => {
  apiCalls.push(endpoint);
  if (manualRequests) {
    return new Promise((resolve, reject) => { pendingResolvers.push({ resolve, reject }); });
  }
  if (nextFailure) {
    throw nextFailure;
  }
  return nextResponse;
};

const INCIDENT = {
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

const NEWEST_BLOCK = () => ([
  makeComment(48, { author_type: 'REPORTER', author_name: 'Conserjería Principal', comment_text: 'La máquina está junto a los ascensores B.' }),
  makeComment(49, { is_internal: true, comment_text: 'Fusible recalentado: posible corto en electroválvula.', photo_url: '/uploads/9f2c7a1b.jpg' }),
  makeComment(50, { is_own_message: true, comment_text: 'Llegando al edificio en 10 minutos.' })
]);

const OLDER_BLOCK = () => ([
  makeComment(12, { author_type: 'REPORTER', author_name: 'Conserjería Principal', comment_text: 'Primer aviso del atasco.' }),
  makeComment(35, { is_internal: true, comment_text: 'Anotación de taller previa.', is_own_message: true })
]);

const makeThreadDto = (comments, paginationOverrides = {}, incidentOverrides = {}) => ({
  incident: { ...INCIDENT, ...incidentOverrides },
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
 * Instancia con el binding de Options API: métodos enlazados, computadas como getters y
 * `$nextTick` resuelto en microtarea (Vue lo hace en el navegador).
 */
function buildInstance(overrides = {}) {
  const instance = Object.create(IncidentCommentThreadModal);
  Object.assign(instance, {
    thread: null,
    comments: [],
    hasMoreBefore: false,
    oldestId: null,
    expandedPhoto: null,
    brokenPhotos: {},
    isLoading: false,
    isLoadingPrevious: false,
    errorMessage: '',
    errorCode: '',
    isOpen: true,
    incidentId: null,
    ticketCode: 'TICK-2026-00142',
    role: 'TECHNICIAN',
    emitted: [],
    $emit(name, payload) { this.emitted.push({ name, payload }); },
    $nextTick: () => Promise.resolve(),
    ...overrides
  });

  for (const [name, fn] of Object.entries(IncidentCommentThreadModal.methods || {})) {
    instance[name] = fn.bind(instance);
  }

  for (const [name, fn] of Object.entries(IncidentCommentThreadModal.computed || {})) {
    Object.defineProperty(instance, name, { get: () => fn.call(instance) });
  }

  // Contenedor con scroll simulado: su altura crece con los mensajes en pantalla.
  instance.$refs = {
    threadScroll: {
      scrollTop: 0,
      get scrollHeight() { return 1000 + instance.comments.length * 100; }
    }
  };

  return instance;
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

// ─── Grupo 1: Carga del hilo por canal y auto-scroll (RF-01.2, RF-01.4) ──────
console.log('--- Grupo 1: Carga del hilo por canal y auto-scroll ---');

apiCalls = [];
nextFailure = null;
nextResponse = makeThreadDto(NEWEST_BLOCK(), { has_more_before: true });

const technicianModal = buildInstance({ incidentId: 142, role: 'TECHNICIAN' });
await technicianModal.loadThread();

assert('1.1 El técnico consulta el endpoint de su ruta con el bloque de 50 mensajes',
  apiCalls.length === 1 && apiCalls[0] === '/technician/incidents/142/comments?limit=50',
  `llamadas reales: ${JSON.stringify(apiCalls)}`);

const siteModal = buildInstance({ incidentId: 142, role: 'SITE_MANAGER' });
apiCalls = [];
await siteModal.loadThread();
assert('1.2 El portal de sede consulta su propio endpoint de hilo',
  apiCalls[0] === '/location/incidents/142/comments?limit=50',
  `llamadas reales: ${JSON.stringify(apiCalls)}`);

const coordinatorModal = buildInstance({ incidentId: 142, role: 'COORDINATOR' });
apiCalls = [];
await coordinatorModal.loadThread();
assert('1.3 Coordinación consulta el endpoint de la bandeja de triaje',
  apiCalls[0] === '/coordinator/incidents/142/comments?limit=50',
  `llamadas reales: ${JSON.stringify(apiCalls)}`);

const codeModal = buildInstance({ ticketCode: '#TICK-2026-00142', role: 'TECHNICIAN' });
apiCalls = [];
await codeModal.loadThread();
assert('1.4 Sin ID numérico el hilo se consulta por código de ticket URL-encoded',
  apiCalls[0] === '/technician/incidents/%23TICK-2026-00142/comments?limit=50',
  `llamadas reales: ${JSON.stringify(apiCalls)}`);

const unidentified = buildInstance({ ticketCode: null, incidentId: null });
apiCalls = [];
await unidentified.loadThread();
assert('1.5 Sin expediente identificado no se consulta nada a la API',
  apiCalls.length === 0 && unidentified.hasIdentifier === false);

assert('1.6 El hilo cargado alimenta cabecera, mensajes y paginación',
  technicianModal.thread?.incident?.ticket_code === 'TICK-2026-00142'
    && technicianModal.comments.length === 3
    && technicianModal.comments[0].id === 48
    && technicianModal.oldestId === 48
    && technicianModal.hasMoreBefore === true
    && technicianModal.totalComments === 3,
  `estado real: comments=${technicianModal.comments.length}, oldest=${technicianModal.oldestId}`);

assert('1.7 El cuerpo se auto-desplaza hasta el mensaje más reciente al cargar',
  technicianModal.$refs.threadScroll.scrollTop === technicianModal.$refs.threadScroll.scrollHeight
    && technicianModal.$refs.threadScroll.scrollTop === 1300,
  `scrollTop=${technicianModal.$refs.threadScroll.scrollTop}`);

assert('1.8 Al abrir el modal se dispara la carga del hilo',
  (() => {
    const opened = buildInstance({ incidentId: 142 });
    apiCalls = [];
    opened.handleOpenState(true);
    return apiCalls.length === 1;
  })());

apiCalls = [];
nextFailure = { status: 403, code: 'NOT_ASSIGNED_TO_TECHNICIAN', message: 'Esta incidencia no está asignada a tu ruta técnica.' };
const forbiddenModal = buildInstance({ incidentId: 999, role: 'TECHNICIAN' });
await forbiddenModal.loadThread();
assert('1.9 Un 403 del backend se muestra en línea con su código y mensaje',
  forbiddenModal.errorCode === 'NOT_ASSIGNED_TO_TECHNICIAN'
    && forbiddenModal.errorMessage === 'Esta incidencia no está asignada a tu ruta técnica.'
    && forbiddenModal.isLoading === false);

nextFailure = { message: '' };
const genericErrorModal = buildInstance({ incidentId: 142 });
await genericErrorModal.loadThread();
assert('1.10 Un fallo sin mensaje del backend cae a un aviso genérico en castellano',
  genericErrorModal.errorMessage === 'No se pudo cargar el hilo de conversación.');

nextFailure = { status: 500, code: 'INTERNAL_SERVER_ERROR', message: 'Fallo del servidor.' };
const preservedModal = buildInstance({ incidentId: 142 });
preservedModal.comments = NEWEST_BLOCK();
preservedModal.thread = makeThreadDto(NEWEST_BLOCK());
await preservedModal.loadThread();
assert('1.11 Un fallo de red no borra los mensajes que ya estaban en pantalla (RF-07.1)',
  preservedModal.comments.length === 3 && preservedModal.errorMessage === 'Fallo del servidor.');
nextFailure = null;

// ─── Grupo 2: Bocadillos diferenciados (RF-02.3, RF-02.4, RNF-04) ────────────
console.log('\n--- Grupo 2: Bocadillos diferenciados ---');

const publicBubble = IncidentCommentThreadModal.methods.bubbleStyle.call(
  technicianModal, makeComment(60, { is_internal: false, is_own_message: false })
);
const ownBubble = IncidentCommentThreadModal.methods.bubbleStyle.call(
  technicianModal, makeComment(61, { is_internal: false, is_own_message: true })
);
const internalBubble = IncidentCommentThreadModal.methods.bubbleStyle.call(
  technicianModal, makeComment(62, { is_internal: true, is_own_message: false })
);

assert('2.1 El comentario público se pinta en gris neutro del sistema (RNF-04)',
  publicBubble.backgroundColor === '#e5e7eb', `real: ${publicBubble.backgroundColor}`);

assert('2.2 El mensaje propio del usuario se pinta en azul suave',
  ownBubble.backgroundColor === 'var(--color-primary-subtle, #e5f2fc)',
  `real: ${ownBubble.backgroundColor}`);

assert('2.3 La nota interna se pinta en ámbar de advertencia técnica',
  internalBubble.backgroundColor === '#fef9c3' && internalBubble.border.includes('#fde047'),
  `real: ${internalBubble.backgroundColor} / ${internalBubble.border}`);

assert('2.4 Los mensajes propios se alinean a la derecha y los ajenos a la izquierda',
  ownBubble.alignSelf === 'flex-end' && publicBubble.alignSelf === 'flex-start');

assert('2.5 El bocadillo usa el radio de tarjeta y la tipografía de cuerpo del sistema',
  publicBubble.borderRadius === 'var(--radius-card, 8px)'
    && String(publicBubble.fontFamily).includes('Inter'));

assert('2.6 La nota interna lleva candado SVG y etiqueta de confidencialidad (RF-02.4)',
  template.includes(":data-testid=\"'incident-comment-lock-' + comment.id\"")
    && template.includes("v-if=\"comment.is_internal\"")
    && template.includes('Nota Interna de Taller (Confidencial)'));

assert('2.7 El candado viaja identificado por mensaje para poder auditarlo',
  template.includes(":data-testid=\"'incident-comment-internal-' + comment.id\"")
    && template.includes('aria-label="🔒 Nota Interna de Taller (Confidencial)"'));

assert('2.8 Los mensajes se pintan en el orden cronológico que entrega el servidor',
  technicianModal.comments.map((comment) => comment.id).join(',') === '48,49,50');

assert('2.9 Cada mensaje muestra su autoría y su marca temporal formateada',
  template.includes(":data-testid=\"'incident-comment-author-' + comment.id\"")
    && technicianModal.formatDateTime('2026-10-06 11:30:12') === '06/10/2026 11:30'
    && technicianModal.formatDateTime('') === '');

assert('2.10 Un comentario público no arrastra el candado de confidencialidad',
  IncidentCommentThreadModal.methods.bubbleStyle.call(technicianModal, makeComment(63)).backgroundColor === '#e5e7eb');

// ─── Grupo 3: Carga de históricos previos (RF-01.3) ──────────────────────────
console.log('\n--- Grupo 3: Carga de históricos previos (RF-01.3) ---');

assert('3.1 El botón de mensajes anteriores depende del cursor del servidor',
  template.includes('data-testid="incident-comment-load-previous"')
    && template.includes('v-if="hasMoreBefore"')
    && template.includes('Cargar mensajes anteriores'));

nextResponse = makeThreadDto(NEWEST_BLOCK(), { has_more_before: true });
const historyModal = buildInstance({ incidentId: 142 });
await historyModal.loadThread();
historyModal.$refs.threadScroll.scrollTop = 400;
apiCalls = [];
nextResponse = makeThreadDto(OLDER_BLOCK(), { has_more_before: false, oldest_id: 12 });

await historyModal.loadPreviousMessages();

assert('3.2 El histórico se pide por cursor con el mensaje más antiguo cargado',
  apiCalls[0] === '/technician/incidents/142/comments?limit=50&before_id=48',
  `llamadas reales: ${JSON.stringify(apiCalls)}`);

assert('3.3 Los bloques previos se anteponen sin duplicar ni desordenar el hilo',
  historyModal.comments.map((comment) => comment.id).join(',') === '12,35,48,49,50');

assert('3.4 El cursor y la bandera de histórico se actualizan con la nueva página',
  historyModal.oldestId === 12 && historyModal.hasMoreBefore === false);

assert('3.5 Al anteponer mensajes se preserva el punto de lectura sin saltos',
  historyModal.$refs.threadScroll.scrollTop === 600,
  `scrollTop real: ${historyModal.$refs.threadScroll.scrollTop} (esperado 400 + 200 px de altura ganada)`);

apiCalls = [];
await historyModal.loadPreviousMessages();
assert('3.6 Sin histórico pendiente no se consulta la API de nuevo',
  apiCalls.length === 0);

nextResponse = makeThreadDto(NEWEST_BLOCK(), { has_more_before: true });
const failedHistoryModal = buildInstance({ incidentId: 142 });
await failedHistoryModal.loadThread();
apiCalls = [];
nextFailure = { status: 500, code: 'INTERNAL_SERVER_ERROR', message: 'Fallo al paginar.' };
await failedHistoryModal.loadPreviousMessages();
assert('3.7 Un fallo al paginar conserva los mensajes y muestra el aviso',
  failedHistoryModal.comments.length === 3
    && failedHistoryModal.errorMessage === 'Fallo al paginar.'
    && failedHistoryModal.isLoadingPrevious === false);
nextFailure = null;

// ─── Grupo 4: Evidencias gráficas ampliables (RF-04.4) ──────────────────────
console.log('\n--- Grupo 4: Evidencias gráficas ampliables (RF-04.4) ---');

assert('4.1 La miniatura se pinta desde el photo_url del mensaje',
  template.includes(":data-testid=\"'incident-comment-photo-' + comment.id\"")
    && template.includes(':src="comment.photo_url"')
    && template.includes('cursor: zoom-in'));

const photoComment = makeComment(49, { photo_url: '/uploads/9f2c7a1b.jpg' });
technicianModal.openPhoto(photoComment);
assert('4.2 Pulsar la miniatura abre el visor ampliado con esa evidencia',
  technicianModal.expandedPhoto?.url === '/uploads/9f2c7a1b.jpg'
    && technicianModal.expandedPhoto?.commentId === 49);

assert('4.3 El visor ampliado se declara en el marcado como diálogo ampliable',
  template.includes('data-testid="incident-comment-photo-viewer"')
    && template.includes('Evidencia gráfica ampliada'));

const escapeInViewer = buildInstance({ incidentId: 142 });
escapeInViewer.openPhoto(photoComment);
escapeInViewer.handleKeyDown({ key: 'Escape' });
assert('4.4 Escape cierra primero el visor de la evidencia, sin cerrar el hilo',
  escapeInViewer.expandedPhoto === null && escapeInViewer.emitted.length === 0);

escapeInViewer.handleKeyDown({ key: 'Escape' });
assert('4.5 Un segundo Escape sí solicita el cierre del hilo',
  escapeInViewer.emitted.length === 1 && escapeInViewer.emitted[0].name === 'close');

technicianModal.closePhoto();
assert('4.6 El visor se cierra con su botón o al pulsar fuera de la imagen',
  technicianModal.expandedPhoto === null && template.includes('@click.self="closePhoto"'));

const textOnlyModal = buildInstance({ incidentId: 142 });
assert('4.7 Un mensaje sin fotografía no ofrece miniatura',
  textOnlyModal.openPhoto(makeComment(70, { photo_url: null })) === undefined
    && textOnlyModal.expandedPhoto === null);

const brokenModal = buildInstance({ incidentId: 142 });
brokenModal.openPhoto(photoComment);
brokenModal.markPhotoUnavailable(49);
assert('4.8 Una evidencia que no carga se sustituye por el recuadro estético (RF-04.4)',
  brokenModal.isPhotoUnavailable(49) === true
    && brokenModal.isPhotoUnavailable(50) === false
    && brokenModal.expandedPhoto === null
    && template.includes('Evidencia gráfica no disponible')
    && template.includes('@error="markPhotoUnavailable(comment.id)"'));

// ─── Grupo 5: Ciclo de vida del hilo cargado ────────────────────────────────
console.log('\n--- Grupo 5: Ciclo de vida del hilo cargado ---');

const lifecycleModal = buildInstance({ incidentId: 142 });
nextResponse = makeThreadDto(NEWEST_BLOCK());
await lifecycleModal.loadThread();
lifecycleModal.errorMessage = 'Error previo.';
lifecycleModal.errorCode = 'SOMETHING';
lifecycleModal.markPhotoUnavailable(49);
lifecycleModal.handleOpenState(false);

assert('5.1 Cerrar el hilo limpia mensajes, errores y evidencias ampliadas',
  lifecycleModal.thread === null
    && lifecycleModal.comments.length === 0
    && lifecycleModal.hasMoreBefore === false
    && lifecycleModal.oldestId === null
    && lifecycleModal.errorMessage === ''
    && lifecycleModal.errorCode === ''
    && lifecycleModal.expandedPhoto === null
    && lifecycleModal.isPhotoUnavailable(49) === false);

apiCalls = [];
nextResponse = makeThreadDto(NEWEST_BLOCK());
lifecycleModal.handleOpenState(true);
await flush();
assert('5.2 Reabrir el hilo recarga los mensajes del expediente',
  apiCalls.length === 1 && lifecycleModal.comments.length === 3);

assert('5.3 Cambiar de expediente o de canal recarga el hilo con el modal abierto',
  typeof IncidentCommentThreadModal.watch.channelEndpoint === 'function'
    && (() => {
      const switcher = buildInstance({ incidentId: 142, isOpen: true });
      apiCalls = [];
      IncidentCommentThreadModal.watch.channelEndpoint.call(switcher);
      const switched = apiCalls.length === 1;
      switcher.isOpen = false;
      apiCalls = [];
      IncidentCommentThreadModal.watch.channelEndpoint.call(switcher);
      return switched && apiCalls.length === 0;
    })());

const lateModal = buildInstance({ incidentId: 142 });
apiCalls = [];
manualRequests = true;
const pendingLoad = lateModal.loadThread();
lateModal.isOpen = false;
pendingResolvers[0].resolve(makeThreadDto(NEWEST_BLOCK()));
await pendingLoad;
manualRequests = false;
pendingResolvers = [];
assert('5.4 Un hilo que llega tarde, con el modal ya cerrado, se descarta',
  lateModal.thread === null && lateModal.comments.length === 0);

assert('5.5 El visor mantiene el contrato de cierre de T-COM-09 (requestClose)',
  componentSource.includes('requestClose()')
    && IncidentCommentThreadModal.emits.includes('close')
    && template.includes('@click="requestClose"'));

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-COM-10 FULFILLED.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.log(' RESULT: FAILURES DETECTED IN TEST SUITE.');
console.log('======================================================================\n');
process.exit(1);
