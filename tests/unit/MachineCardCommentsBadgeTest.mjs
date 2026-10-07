/**
 * VendGuard - MachineCard Conversation Badge Test Suite (T-COM-14)
 *
 * Verifica la condición "Hecho cuando" de la tarea T-COM-14 del módulo 10
 * (hilo de comentarios bidireccional con notas internas confidenciales):
 *
 * 1. `MachineCard.js` renderiza una insignia interactiva con icono de diálogo y
 *    contador numérico que contabiliza ÚNICAMENTE los comentarios públicos
 *    (`active_incident.public_comments_count`).
 * 2. Al pulsar la insignia, la tarjeta emite el evento `open-comments` y
 *    `LocationPortalView.js` monta `IncidentCommentThreadModal` con el canal
 *    `SITE_MANAGER`.
 * 3. Al emitirse `comment-added` el portal recarga el estado de las máquinas, de
 *    modo que el contador de la tarjeta se actualiza de forma reactiva.
 *
 * Blindaje constitucional (Art. V.4 / RNF-01): el canal de sede jamás lee el
 * total de mensajes; si la tarjeta dependiera de `comments_count` el contador
 * delataría la existencia de notas internas de taller.
 *
 * Dogma Vanilla: Node.js ESM nativo, cero dependencias externas, cero red. La
 * suite evalúa plantillas y contratos como artefactos, sin DOM real.
 */

// Entorno mínimo de navegador para poder importar la vista (localStorage del store).
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

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CARD_PATH = path.resolve(HERE, '../../public/assets/js/components/MachineCard.js');
const PORTAL_PATH = path.resolve(HERE, '../../public/assets/js/views/LocationPortalView.js');

const { api } = await import('../../public/assets/js/api.js');
const { store, clearSession, setSiteSession } = await import('../../public/assets/js/store.js');
const { MachineCard } = await import('../../public/assets/js/components/MachineCard.js');
const { LocationPortalView } = await import('../../public/assets/js/views/LocationPortalView.js');

const cardSource = fs.readFileSync(CARD_PATH, 'utf8');
const portalSource = fs.readFileSync(PORTAL_PATH, 'utf8');
const cardTemplate = String(MachineCard.template || '');
const portalTemplate = String(LocationPortalView.template || '');

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
console.log(' VendGuard: Frontend Suite - Insignia de conversación del Responsable (T-COM-14)');
console.log('======================================================================\n');

/**
 * Instancia de MachineCard con el mismo binding de Vue en Options API: métodos
 * enlazados a la instancia y computadas resueltas como getters.
 */
function buildCardInstance(machine, overrides = {}) {
  const instance = Object.assign({
    machine,
    $emit(name, payload) { this.emitted.push({ name, payload }); },
    emitted: []
  }, overrides);

  for (const [name, fn] of Object.entries(MachineCard.methods || {})) {
    instance[name] = fn.bind(instance);
  }

  for (const [name, fn] of Object.entries(MachineCard.computed || {})) {
    Object.defineProperty(instance, name, { get: () => fn.call(instance) });
  }

  return instance;
}

// ─── Grupo 1: Contrato visual de la insignia (RF-01.1) ───────────────────────
console.log('--- Grupo 1: Insignia interactiva con icono y contador numérico ---');

assert('1.1 La tarjeta declara la clase de la insignia de conversación',
  cardTemplate.includes('vg-conversation-badge'),
  'Se esperaba `vg-conversation-badge` en la plantilla de MachineCard.');

assert('1.2 La insignia es un botón interactivo real, no un adorno estático',
  /<button[\s\S]{0,600}vg-conversation-badge[\s\S]{0,900}@click="handleOpenComments"/.test(cardTemplate),
  'El botón de la insignia debe enlazar @click="handleOpenComments".');

assert('1.3 La insignia incorpora el icono SVG de diálogo',
  cardTemplate.includes('M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'),
  'Se esperaba el trazado SVG del bocadillo de conversación.');

assert('1.4 La insignia rotula la acción en castellano',
  cardTemplate.includes('Conversación'),
  'Se esperaba la etiqueta «Conversación» en la insignia.');

assert('1.5 El contador numérico se renderiza desde la computada publicCommentsCount',
  cardTemplate.includes('{{ publicCommentsCount }}') && cardTemplate.includes('vg-conversation-badge__count'),
  'El contador debe interpolar `publicCommentsCount` dentro de la chip numérica.');

assert('1.6 La insignia declara identificadores estables para verificación y accesibilidad',
  cardTemplate.includes('data-testid="machine-card-comments-badge"')
    && cardTemplate.includes('data-testid="machine-card-comments-count"')
    && cardTemplate.includes(':aria-label="conversationBadgeLabel"'),
  'Se esperaban los data-testid de la insignia y del contador, más su aria-label.');

assert('1.7 La insignia se ofrece en avería en curso y en garantía de 48h (RF-05.2)',
  (cardTemplate.match(/vg-conversation-badge/g) || []).length >= 2
    && cardTemplate.includes('hasActiveIncident && !isUnderWarranty')
    && cardTemplate.includes('hasActiveIncident && isUnderWarranty'),
  'La conversación debe poder abrirse tanto en avería activa como en la ventana de garantía.');

assert('1.8 La insignia respeta el radio interactivo de 4px del sistema de diseño (RNF-04)',
  /vg-conversation-badge[\s\S]{0,400}var\(--radius-interactive, 4px\)/.test(cardTemplate),
  'Se esperaba `--radius-interactive, 4px` en la insignia.');

// ─── Grupo 2: Contador segregado sólo de comentarios públicos (Art. V.4) ─────
console.log('\n--- Grupo 2: Contador segregado exclusivamente público (Art. V.4, RNF-01) ---');

const cardWithPublicComments = buildCardInstance({
  id: 10,
  code: 'VEND-0102',
  active_incident: { id: 142, ticket_code: 'TICK-2026-00142', status: 'IN_PROGRESS', public_comments_count: 3 }
});

assert('2.1 Contabiliza el recuento público entregado por la API',
  cardWithPublicComments.publicCommentsCount === 3,
  `Valor obtenido: ${cardWithPublicComments.publicCommentsCount}`);

assert('2.2 La etiqueta accesible refleja el contador público',
  cardWithPublicComments.conversationBadgeLabel === 'Conversación (3)',
  `Etiqueta obtenida: ${cardWithPublicComments.conversationBadgeLabel}`);

assert('2.3 El recuento público 0 se muestra como 0 y nunca como hueco',
  buildCardInstance({
    id: 11,
    code: 'VEND-0103',
    active_incident: { id: 143, ticket_code: 'TICK-2026-00143', status: 'IN_PROGRESS', public_comments_count: 0 }
  }).publicCommentsCount === 0);

assert('2.4 Avería sin campo de recuento degrada a 0 sin romper la tarjeta',
  buildCardInstance({
    id: 12,
    code: 'VEND-0104',
    active_incident: { id: 144, ticket_code: 'TICK-2026-00144', status: 'IN_PROGRESS' }
  }).publicCommentsCount === 0);

assert('2.5 Máquina operativa (sin avería) no muestra contador',
  buildCardInstance({ id: 13, code: 'VEND-0105', active_incident: null }).publicCommentsCount === 0);

assert('2.6 Fuga bloqueada: el total `comments_count` jamás alimenta el contador de la sede',
  buildCardInstance({
    id: 14,
    code: 'VEND-0106',
    active_incident: { id: 145, ticket_code: 'TICK-2026-00145', status: 'IN_PROGRESS', comments_count: 9 }
  }).publicCommentsCount === 0,
  'Si la tarjeta leyera el total, el contador delataría las notas internas (Art. V.4).');

assert('2.7 El código de la tarjeta no contiene ninguna lectura del total de mensajes',
  !cardSource.includes('comments_count') || !/comments_count/.test(cardSource.replace(/public_comments_count/g, '')),
  'La única clave de recuento permitida en el canal de sede es `public_comments_count`.');

assert('2.8 Tarjeta con avería activa declara el contador público en su contrato',
  Object.keys(MachineCard.computed || {}).includes('publicCommentsCount')
    && (MachineCard.computed.publicCommentsCount.toString().includes('public_comments_count')),
  'La computada publicCommentsCount debe leer exclusivamente `public_comments_count`.');

// ─── Grupo 3: Emisión del evento y montaje del modal SITE_MANAGER (RF-01.2) ──
console.log('\n--- Grupo 3: Apertura del hilo con rol SITE_MANAGER ---');

assert('3.1 La tarjeta declara open-comments entre sus eventos emitidos',
  Array.isArray(MachineCard.emits) && MachineCard.emits.includes('open-comments'),
  `emits reales: ${JSON.stringify(MachineCard.emits)}`);

assert('3.2 El evento `comment` previo del módulo 08 queda sustituido por `open-comments`',
  !MachineCard.emits.includes('comment') && !cardSource.includes('handleCommentClick'),
  'El plan §4.2.A sustituye el botón genérico de comentarios por la insignia de conversación.');

const cardWithIncident = buildCardInstance({
  id: 10,
  code: 'VEND-0102',
  active_incident: { id: 142, ticket_code: 'TICK-2026-00142', status: 'IN_PROGRESS', public_comments_count: 3 }
});
cardWithIncident.handleOpenComments();

assert('3.3 Pulsar la insignia emite open-comments con la máquina pulsada',
  cardWithIncident.emitted.length === 1
    && cardWithIncident.emitted[0].name === 'open-comments'
    && cardWithIncident.emitted[0].payload?.code === 'VEND-0102',
  `Eventos emitidos: ${JSON.stringify(cardWithIncident.emitted)}`);

assert('3.4 El portal registra el componente del hilo de conversación',
  Boolean(LocationPortalView.components?.IncidentCommentThreadModal),
  'LocationPortalView.components debe incluir IncidentCommentThreadModal.');

assert('3.5 El portal importa el modal del hilo desde el módulo local (Dogma Vanilla)',
  portalSource.includes("import { IncidentCommentThreadModal } from '../components/IncidentCommentThreadModal.js'"),
  'Se esperaba el import ESM local del modal.');

assert('3.6 El portal monta el hilo con el canal SITE_MANAGER (RF-02.1, RF-03.2)',
  portalTemplate.includes('<IncidentCommentThreadModal')
    && portalTemplate.includes('role="SITE_MANAGER"'),
  'El modal debe montarse con role="SITE_MANAGER".');

assert('3.7 El modal se abre con el expediente de la tarjeta pulsada',
  portalTemplate.includes(':is-open="showCommentsModal"')
    && portalTemplate.includes(':incident-id="activeCommentIncidentId"')
    && portalTemplate.includes(':ticket-code="activeCommentTicketCode"'),
  'Se esperaba el montaje con visibilidad, id y código de ticket del expediente.');

assert('3.8 El modal se cierra y refresca el parque al publicar (RF-03.4, RNF-06)',
  portalTemplate.includes('@close="onCloseComments"')
    && portalTemplate.includes('@comment-added="onCommentAdded"'),
  'Se esperaban los enlaces @close y @comment-added del modal.');

assert('3.9 La tarjeta queda enlazada al disparador del portal',
  portalTemplate.includes('@open-comments="onOpenComments"'),
  'Se esperaba @open-comments="onOpenComments" en el bucle de MachineCard.');

const portalInstance = Object.assign({
  selectedMachine: null,
  showCommentsModal: false,
  $emit(name, payload) { this.emitted.push({ name, payload }); },
  emitted: []
}, {});

for (const [name, fn] of Object.entries(LocationPortalView.methods || {})) {
  portalInstance[name] = fn.bind(portalInstance);
}
for (const [name, fn] of Object.entries(LocationPortalView.computed || {})) {
  Object.defineProperty(portalInstance, name, { get: () => fn.call(portalInstance) });
}

portalInstance.onOpenComments({
  id: 10,
  code: 'VEND-0102',
  active_incident: { id: 142, ticket_code: 'TICK-2026-00142', status: 'IN_PROGRESS', public_comments_count: 3 }
});

assert('3.10 onOpenComments abre el modal y fija el expediente seleccionado',
  portalInstance.showCommentsModal === true
    && portalInstance.selectedMachine?.active_incident?.ticket_code === 'TICK-2026-00142',
  `Estado: modal=${portalInstance.showCommentsModal}, machine=${JSON.stringify(portalInstance.selectedMachine)}`);

assert('3.11 El portal calcula el id y el código de ticket del expediente abierto',
  portalInstance.activeCommentIncidentId === 142
    && portalInstance.activeCommentTicketCode === 'TICK-2026-00142');

assert('3.12 onOpenComments propaga el evento open-comments hacia la aplicación',
  portalInstance.emitted.some((e) => e.name === 'open-comments' && e.payload?.code === 'VEND-0102'));

portalInstance.onCloseComments();

assert('3.13 onCloseComments cierra el modal y libera la selección',
  portalInstance.showCommentsModal === false && portalInstance.selectedMachine === null);

assert('3.14 El portal ya no delega la conversación en el modal de reporte',
  !portalSource.includes('add-comment') && !portalSource.includes('onComment(machine)'),
  'El evento `add-comment` del módulo 08 queda sustituido por `open-comments`.');

// ─── Grupo 4: Recarga reactiva del parque de máquinas (RF-01.1) ──────────────
console.log('\n--- Grupo 4: Recarga reactiva del contador tras publicar ---');

clearSession();
setSiteSession(
  { id: 1, site_code: 'SEDE-BCN-01', name: 'Hospital del Mar', address: 'Passeig Marítim 25' },
  'site_token_bcn_test'
);

const machinesBefore = [
  {
    id: 10,
    code: 'VEND-0102',
    model: 'Necta Canto Touch',
    machine_type: 'HOT_DRINKS',
    floor_wing: 'Planta 1',
    notes: null,
    active_incident: { id: 142, ticket_code: 'TICK-2026-00142', status: 'IN_PROGRESS', public_comments_count: 3 }
  }
];

const machinesAfter = [
  {
    ...machinesBefore[0],
    active_incident: { ...machinesBefore[0].active_incident, public_comments_count: 4 }
  }
];

let requestedSites = [];
api.locations.getMachines = async (siteCode) => {
  requestedSites.push(siteCode);
  return machinesAfter;
};

const reactivePortal = Object.assign({
  machines: machinesBefore,
  isLoadingMachines: false,
  machinesError: '',
  selectedMachine: machinesBefore[0],
  showCommentsModal: true
}, {});

for (const [name, fn] of Object.entries(LocationPortalView.methods || {})) {
  reactivePortal[name] = fn.bind(reactivePortal);
}
for (const [name, fn] of Object.entries(LocationPortalView.computed || {})) {
  Object.defineProperty(reactivePortal, name, { get: () => fn.call(reactivePortal) });
}

assert('4.1 El contador de la tarjeta antes de publicar es 3',
  buildCardInstance(reactivePortal.machines[0]).publicCommentsCount === 3);

await reactivePortal.onCommentAdded();

assert('4.2 Publicar un comentario dispara la recarga del parque de máquinas',
  requestedSites.length === 1 && requestedSites[0] === 'SEDE-BCN-01',
  `Peticiones realizadas: ${JSON.stringify(requestedSites)}`);

assert('4.3 El estado de máquinas se refresca con la respuesta del servidor',
  reactivePortal.machines[0].active_incident.public_comments_count === 4,
  `Contador recibido: ${reactivePortal.machines[0].active_incident.public_comments_count}`);

assert('4.4 La insignia de la tarjeta refleja el nuevo contador de forma reactiva (RF-01.1)',
  buildCardInstance(reactivePortal.machines[0]).publicCommentsCount === 4
    && buildCardInstance(reactivePortal.machines[0]).conversationBadgeLabel === 'Conversación (4)');

assert('4.5 La recarga reutiliza el canal autenticado de la sede y no inventa endpoints',
  portalSource.includes('api.locations.getMachines(this.location.site_code)'),
  'La recarga debe hacerlo por el endpoint oficial del parque de máquinas.');

// RESUMEN DE EJECUCIÓN
console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. CONDICIÓN T-COM-14 CUMPLIDA.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.error(` RESULTADO: FALLO EN ${failures} ASERCIONES.`);
console.log('======================================================================\n');
process.exit(1);
