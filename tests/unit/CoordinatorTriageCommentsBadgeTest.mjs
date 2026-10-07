/**
 * VendGuard - Coordinator Triage Comments Badge Test Suite (T-COM-16)
 *
 * Verifica la condición "Hecho cuando" de la tarea T-COM-16 del módulo 10
 * (hilo de comentarios bidireccional con notas internas confidenciales):
 *
 * 1. La tabla de triaje del coordinador incorpora en cada fila un disparador
 *    interactivo con el recuento TOTAL de mensajes (`comments_count`).
 * 2. Tanto la insignia de la fila como el disparador de
 *    `CoordinatorIncidentDetailModal` abren `IncidentCommentThreadModal` con el
 *    rol `COORDINATOR`, permitiendo participar con notas internas o públicas.
 * 3. Los contadores se mantienen sincronizados: al publicar un mensaje, la
 *    insignia de la fila adopta el total exacto del servidor y la ficha de
 *    detalle abierta por debajo recarga su bitácora.
 *
 * Blindaje constitucional (Art. V.4): el coordinador contabiliza la TOTALIDAD de
 * mensajes, públicos y notas internas de taller, porque su canal tiene acceso
 * legítimo a ambos (RF-02.3); la vista nunca lee el contador segregado de la Sede.
 *
 * Dogma Vanilla: Node.js ESM nativo, cero dependencias externas, cero red.
 */

// Entorno mínimo de navegador para el store de sesión y el bloqueo de scroll.
const storageMock = new Map();
globalThis.localStorage = {
  getItem: (k) => storageMock.get(k) ?? null,
  setItem: (k, v) => storageMock.set(k, String(v)),
  removeItem: (k) => storageMock.delete(k),
  clear: () => storageMock.clear()
};
globalThis.document = { body: { style: { overflow: '' } } };

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const DASHBOARD_PATH = path.resolve(HERE, '../../public/assets/js/views/CoordinatorDashboardView.js');
const DETAIL_MODAL_PATH = path.resolve(HERE, '../../public/assets/js/components/CoordinatorIncidentDetailModal.js');

const { store, clearSession, setInternalSession } = await import('../../public/assets/js/store.js');
const { CoordinatorDashboardView } = await import('../../public/assets/js/views/CoordinatorDashboardView.js');
const { CoordinatorIncidentDetailModal } = await import('../../public/assets/js/components/CoordinatorIncidentDetailModal.js');

const dashboardSource = fs.readFileSync(DASHBOARD_PATH, 'utf8');
const detailSource = fs.readFileSync(DETAIL_MODAL_PATH, 'utf8');
const dashboardTemplate = String(CoordinatorDashboardView.template || '');
const detailTemplate = String(CoordinatorIncidentDetailModal.template || '');

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
console.log(' VendGuard: Frontend Suite - Insignia de conversación del triaje (T-COM-16)');
console.log('======================================================================\n');

/** Averías de la bandeja de triaje: recuentos totales ya publicados por el servidor. */
const triageRows = [
  {
    id: 1,
    ticket_code: 'INC-2026-0001',
    machine_code: 'VEND-0101',
    location_name: 'Hospital del Mar',
    urgency: 'CRITICAL',
    status: 'REGISTERED',
    comments_count: 7,
    sla_breached: true
  },
  {
    id: 2,
    ticket_code: 'INC-2026-0002',
    machine_code: 'VEND-0102',
    location_name: 'Hospital del Mar',
    urgency: 'HIGH',
    status: 'ASSIGNED',
    comments_count: 0,
    sla_breached: false
  },
  {
    id: 3,
    ticket_code: 'INC-2026-0003',
    machine_code: 'VEND-0103',
    location_name: 'Campus Central',
    urgency: 'MEDIUM',
    status: 'IN_PROGRESS',
    sla_breached: false
  }
];

/** Instancia de la bandeja de triaje con el binding de Vue en Options API. */
function buildDashboardInstance(overrides = {}) {
  const instance = Object.assign({
    ...CoordinatorDashboardView.data(),
    incidents: JSON.parse(JSON.stringify(triageRows)),
    $emit: () => {},
    $refs: {},
    // Modela el drenado de watchers de Vue: el re-bloqueo del fondo se ejecuta tras el
    // cierre del hilo, nunca antes.
    $nextTick: (fn) => fn()
  }, overrides);

  for (const [name, fn] of Object.entries(CoordinatorDashboardView.methods || {})) {
    instance[name] = fn.bind(instance);
  }

  for (const [name, fn] of Object.entries(CoordinatorDashboardView.computed || {})) {
    Object.defineProperty(instance, name, { get: () => fn.call(instance) });
  }

  return instance;
}

/** Instancia de la ficha de detalle con una bitácora mixta ya cargada. */
function buildDetailInstance(comments = [], overrides = {}) {
  const instance = Object.assign({
    ...CoordinatorIncidentDetailModal.data(),
    isOpen: false,
    incidentId: 1,
    commentThreadOpen: false,
    detail: {
      incident: { id: 1, ticket_code: 'INC-2026-0001' },
      comments
    },
    emitted: [],
    $emit(name, payload) { this.emitted.push({ name, payload }); },
    $refs: {}
  }, overrides);

  for (const [name, fn] of Object.entries(CoordinatorIncidentDetailModal.methods || {})) {
    instance[name] = fn.bind(instance);
  }

  for (const [name, fn] of Object.entries(CoordinatorIncidentDetailModal.computed || {})) {
    Object.defineProperty(instance, name, { get: () => fn.call(instance) });
  }

  return instance;
}

clearSession();
setInternalSession(
  { id: 1, name: 'Carles Coordinador', email: 'coord@vendguard.internal', role: 'COORDINATOR' },
  'auth_token_coord'
);

// ─── Grupo 1: Insignia interactiva en la fila de triaje (RF-01.1) ────────────
console.log('--- Grupo 1: Insignia de conversación en la fila de triaje ---');

assert('1.1 La fila de triaje declara la clase de la insignia de conversación',
  dashboardTemplate.includes('vg-row-comments-badge'),
  'Se esperaba `vg-row-comments-badge` en la plantilla de la bandeja.');

assert('1.2 La insignia se renderiza dentro del bucle de filas de la bandeja',
  /v-for="inc in filteredIncidents"[\s\S]*vg-row-comments-badge/.test(dashboardTemplate),
  'La insignia debe declararse dentro del bucle de filas.');

assert('1.3 La insignia es un botón interactivo real que no propaga el clic de fila',
  /<button[\s\S]{0,600}vg-row-comments-badge[\s\S]{0,900}@click\.stop="openCommentsModal\(inc\)"/.test(dashboardTemplate),
  'El botón de la insignia debe enlazar @click.stop="openCommentsModal(inc)".');

assert('1.4 La insignia incorpora el icono de conversación',
  dashboardTemplate.includes('💬'),
  'Se esperaba el icono de diálogo en la insignia de la fila.');

assert('1.5 El contador numérico se renderiza desde la computada commentCountOf',
  dashboardTemplate.includes('{{ commentCountOf(inc) }}') && dashboardTemplate.includes('data-testid="row-comments-count"'),
  'El contador debe interpolar `commentCountOf(inc)` en la chip numérica.');

assert('1.6 La insignia declara identificador estable y descripción del total',
  dashboardTemplate.includes('data-testid="btn-row-comments"')
    && dashboardTemplate.includes("'Abrir el hilo de conversación del expediente (' + commentCountOf(inc) + ' mensajes)'"),
  'Se esperaban el data-testid y el title con el recuento total.');

assert('1.7 La insignia respeta el radio interactivo de 4px del sistema de diseño (RNF-04)',
  /vg-row-comments-badge[\s\S]{0,400}var\(--radius-interactive, 4px\)/.test(dashboardTemplate),
  'Se esperaba `--radius-interactive, 4px` en la insignia.');

// ─── Grupo 2: Contador total de mensajes (RF-01.1, RF-02.3) ──────────────────
console.log('\n--- Grupo 2: Contador total de la avería (públicos + notas internas) ---');

const dashboardInstance = buildDashboardInstance();

assert('2.1 Contabiliza el total de mensajes entregado por la API (7)',
  dashboardInstance.commentCountOf(dashboardInstance.incidents[0]) === 7,
  `Valor obtenido: ${dashboardInstance.commentCountOf(dashboardInstance.incidents[0])}`);

assert('2.2 Un contador 0 se muestra como 0 y nunca como hueco',
  dashboardInstance.commentCountOf({ comments_count: 0 }) === 0);

assert('2.3 Fila sin campo de contador degrada a 0 sin romper la tabla',
  dashboardInstance.commentCountOf(dashboardInstance.incidents[2]) === 0);

assert('2.4 Fila inexistente o sin objeto degrada a 0 de forma defensiva',
  dashboardInstance.commentCountOf(null) === 0 && dashboardInstance.commentCountOf({}) === 0);

assert('2.5 Contadores serializados como cadena se interpretan numéricamente',
  dashboardInstance.commentCountOf({ comments_count: '9' }) === 9,
  `Valor obtenido: ${dashboardInstance.commentCountOf({ comments_count: '9' })}`);

assert('2.6 El canal del coordinador contabiliza la TOTALIDAD de mensajes (RF-02.3)',
  dashboardSource.includes('comments_count') && !dashboardSource.includes('public_comments_count'),
  'El coordinador ve notas internas, así que su contador es el total; el contador segregado de sede no debe aparecer aquí.');

// ─── Grupo 3: Apertura del hilo con rol COORDINATOR (RF-01.2) ────────────────
console.log('\n--- Grupo 3: Apertura del hilo con canal COORDINATOR ---');

assert('3.1 La bandeja registra el componente del hilo de conversación',
  Boolean(CoordinatorDashboardView.components?.IncidentCommentThreadModal),
  'CoordinatorDashboardView.components debe incluir IncidentCommentThreadModal.');

assert('3.2 La bandeja importa el modal del hilo desde el módulo local (Dogma Vanilla)',
  dashboardSource.includes("import { IncidentCommentThreadModal } from '../components/IncidentCommentThreadModal.js'"),
  'Se esperaba el import ESM local del modal.');

assert('3.3 El modal se monta con el canal COORDINATOR (RF-02.3, RF-03.3)',
  dashboardTemplate.includes('<IncidentCommentThreadModal') && dashboardTemplate.includes('role="COORDINATOR"'),
  'El modal debe montarse con role="COORDINATOR".');

assert('3.4 El modal se abre con el expediente seleccionado en la tabla o en la ficha',
  dashboardTemplate.includes(':is-open="showCommentsModal"')
    && dashboardTemplate.includes(':incident-id="selectedCommentIncident?.id ?? selectedCommentIncident?.ticket_code ?? null"')
    && dashboardTemplate.includes(':ticket-code="selectedCommentIncident?.ticket_code ?? null"'),
  'Se esperaba el montaje con visibilidad, id y código de ticket del expediente.');

assert('3.5 El modal se cierra y sincroniza contadores al publicar (RF-03.4)',
  dashboardTemplate.includes('@close="closeCommentsModal"') && dashboardTemplate.includes('@comment-added="onCommentAdded"'),
  'Se esperaban los enlaces @close y @comment-added del modal.');

assert('3.6 El hilo se monta DESPUÉS de la ficha de detalle para quedar por encima',
  dashboardTemplate.indexOf('<CoordinatorIncidentDetailModal') < dashboardTemplate.indexOf('<IncidentCommentThreadModal'),
  'El orden de montaje fija la capa cuando el hilo se abre desde el propio detalle.');

const openInstance = buildDashboardInstance();
openInstance.openCommentsModal(openInstance.incidents[0]);

assert('3.7 openCommentsModal abre el modal y rehidrata la fila reactiva de la bandeja',
  openInstance.showCommentsModal === true
    && openInstance.selectedCommentIncident === openInstance.incidents[0],
  'La insignia debe actualizar el mismo objeto de fila que pinta la tabla.');

const fallbackInstance = buildDashboardInstance({
  selectedDetailIncident: { id: 2, ticket_code: 'INC-2026-0002' }
});
fallbackInstance.openCommentsModal(null);

assert('3.8 Sin argumento, el hilo se abre sobre la avería seleccionada en la ficha de detalle',
  fallbackInstance.showCommentsModal === true && fallbackInstance.selectedCommentIncident?.id === 2);

const guardedInstance = buildDashboardInstance();
guardedInstance.openCommentsModal(null);

assert('3.9 Sin avería seleccionada el hilo no se abre de forma vacía',
  guardedInstance.showCommentsModal === false && guardedInstance.selectedCommentIncident === null);

openInstance.closeCommentsModal();

assert('3.10 closeCommentsModal cierra el modal y libera la selección',
  openInstance.showCommentsModal === false && openInstance.selectedCommentIncident === null);

const nestedCloseInstance = buildDashboardInstance({ showDetailModal: true, showCommentsModal: true });
nestedCloseInstance.closeCommentsModal();

assert('3.11 Cerrar el hilo con la ficha de detalle abierta debajo mantiene bloqueado el fondo (RNF-06)',
  globalThis.document.body.style.overflow === 'hidden',
  `overflow real: '${globalThis.document.body.style.overflow}'`);

const detailTriggerInstance = buildDashboardInstance({
  showDetailModal: true,
  selectedDetailIncident: { id: 3, ticket_code: 'INC-2026-0003' }
});
detailTriggerInstance.onDetailOpenComments();

assert('3.12 El disparador de la ficha abre el hilo sobre la avería inspeccionada',
  detailTriggerInstance.showCommentsModal === true
    && detailTriggerInstance.selectedCommentIncident === detailTriggerInstance.incidents[2],
  'Se esperaba rehidratar la fila real de la tabla para el contador sincronizado.');

// ─── Grupo 4: Disparador dentro de la ficha de detalle (RF-01.1) ─────────────
console.log('\n--- Grupo 4: Disparador del hilo completo en la ficha de detalle ---');

assert('4.1 La ficha de detalle declara el evento open-comments',
  Array.isArray(CoordinatorIncidentDetailModal.emits) && CoordinatorIncidentDetailModal.emits.includes('open-comments'),
  `emits reales: ${JSON.stringify(CoordinatorIncidentDetailModal.emits)}`);

assert('4.2 La ficha declara la prop commentThreadOpen para el hilo superpuesto',
  CoordinatorIncidentDetailModal.props?.commentThreadOpen?.type === Boolean
    && CoordinatorIncidentDetailModal.props.commentThreadOpen.default === false);

assert('4.3 La sección de bitácora incorpora el disparador con recuento numérico',
  detailTemplate.includes('data-testid="btn-open-comment-thread"')
    && detailTemplate.includes('data-testid="comment-thread-count"')
    && detailTemplate.includes('{{ commentThreadCount }}'),
  'Se esperaba el botón «Hilo completo» con su chip numérica.');

assert('4.4 El disparador queda ligado a openCommentThread',
  /btn-open-comment-thread[\s\S]{0,900}@click="openCommentThread"/.test(detailTemplate),
  'El botón debe invocar openCommentThread().');

const detailInstance = buildDetailInstance([
  { id: 1, author_type: 'REPORTER', author_name: 'Conserjería', comment_text: 'Acceso por recepción.', is_internal: false, created_at: '2026-10-06 10:15:30' },
  { id: 2, author_type: 'TECHNICIAN', author_name: 'Jordi Técnico', comment_text: 'Nota interna: fusible recalentado.', is_internal: true, created_at: '2026-10-06 10:45:00' }
]);

assert('4.5 El recuento del disparador es el total de la bitácora del expediente (2)',
  detailInstance.commentThreadCount === 2,
  `Valor obtenido: ${detailInstance.commentThreadCount}`);

assert('4.6 Una bitácora vacía publica contador 0, no un valor ausente',
  buildDetailInstance([]).commentThreadCount === 0);

detailInstance.openCommentThread();

assert('4.7 openCommentThread emite open-comments con el expediente inspeccionado',
  detailInstance.emitted.length === 1
    && detailInstance.emitted[0].name === 'open-comments'
    && detailInstance.emitted[0].payload?.incidentId === 1
    && detailInstance.emitted[0].payload?.ticketCode === 'INC-2026-0001',
  `Eventos emitidos: ${JSON.stringify(detailInstance.emitted)}`);

const nestedGuardInstance = buildDetailInstance([], { isOpen: true, commentThreadOpen: true });
let nestedCloseRequests = 0;
nestedGuardInstance.requestClose = () => { nestedCloseRequests++; };

nestedGuardInstance.handleKeyDown({ key: 'Escape' });

assert('4.8 Con el hilo abierto encima, Escape no cierra la ficha de debajo',
  nestedCloseRequests === 0,
  `Cierres solicitados: ${nestedCloseRequests}`);

const soloGuardInstance = buildDetailInstance([], { isOpen: true, commentThreadOpen: false });
soloGuardInstance.requestClose = () => { nestedCloseRequests++; };
soloGuardInstance.handleKeyDown({ key: 'Escape' });

assert('4.9 Sin hilo superpuesto, Escape sigue cerrando la ficha de detalle',
  nestedCloseRequests === 1,
  `Cierres solicitados: ${nestedCloseRequests}`);

let backdropCloseRequests = 0;
const backdropGuardInstance = buildDetailInstance([], { isOpen: true, commentThreadOpen: true });
backdropGuardInstance.requestClose = () => { backdropCloseRequests++; };
backdropGuardInstance.handleBackdropClick({ target: 'backdrop', currentTarget: 'backdrop' });

assert('4.10 Con el hilo abierto encima, el clic en el fondo no cierra la ficha',
  backdropCloseRequests === 0,
  `Cierres solicitados: ${backdropCloseRequests}`);

// ─── Grupo 5: Sincronización de contadores (RF-03.4) ─────────────────────────
console.log('\n--- Grupo 5: Sincronización de contadores tras publicar ---');

let detailReloads = 0;
const syncInstance = buildDashboardInstance({
  showDetailModal: true,
  selectedDetailIncident: { id: 1, ticket_code: 'INC-2026-0001' },
  $refs: { detailModalRef: { fetchDetail: async () => { detailReloads++; } } }
});
syncInstance.openCommentsModal(syncInstance.incidents[0]);

await syncInstance.onCommentAdded({ pagination: { total_comments: 8 } });

assert('5.1 La insignia de la fila adopta el total exacto devuelto por el servidor',
  syncInstance.incidents.find(inc => inc.id === 1).comments_count === 8,
  `Contador obtenido: ${syncInstance.incidents.find(inc => inc.id === 1).comments_count}`);

assert('5.2 La ficha de detalle abierta por debajo recarga su bitácora',
  detailReloads === 1,
  `Recargas de la ficha: ${detailReloads}`);

assert('5.3 El resto de filas de la bandeja conserva su contador intacto',
  syncInstance.incidents.find(inc => inc.id === 2).comments_count === 0
    && syncInstance.incidents.find(inc => inc.id === 1).comments_count !== 7);

const fallbackSyncInstance = buildDashboardInstance();
fallbackSyncInstance.openCommentsModal(fallbackSyncInstance.incidents[0]);
await fallbackSyncInstance.onCommentAdded({});

assert('5.4 Sin total del servidor el contador de la fila se incrementa en una unidad',
  fallbackSyncInstance.incidents[0].comments_count === 8,
  `Contador obtenido: ${fallbackSyncInstance.incidents[0].comments_count}`);

const closedDetailInstance = buildDashboardInstance({
  showDetailModal: false,
  $refs: { detailModalRef: { fetchDetail: async () => { detailReloads++; } } }
});
closedDetailInstance.openCommentsModal(closedDetailInstance.incidents[0]);
await closedDetailInstance.onCommentAdded({ pagination: { total_comments: 4 } });

assert('5.5 Con la ficha cerrada no se recarga ningún detalle ajeno',
  detailReloads === 1,
  `Recargas de la ficha acumuladas: ${detailReloads}`);

assert('5.6 La ficha publica el recuento del hilo sobre el mismo contrato del servidor',
  dashboardSource.includes('api.coordinator.getIncidents()'),
  'Los contadores proceden del listado oficial de triaje, sin endpoints inventados.');

// RESUMEN DE EJECUCIÓN
console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. CONDICIÓN T-COM-16 CUMPLIDA.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.error(` RESULTADO: FALLO EN ${failures} ASERCIONES.`);
console.log('======================================================================\n');
process.exit(1);
