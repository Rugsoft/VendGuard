/**
 * VendGuard - Technician Stop Comments Badge Test Suite (T-COM-15)
 *
 * Verifica la condición "Hecho cuando" de la tarea T-COM-15 del módulo 10
 * (hilo de comentarios bidireccional con notas internas confidenciales):
 *
 * 1. Cada tarjeta de parada de la vista móvil "Mi Ruta" incorpora una insignia
 *    interactiva con icono de conversación y contador numérico con la TOTALIDAD
 *    de mensajes (`comments_count`), públicos y notas internas de taller.
 * 2. Al pulsar la insignia se abre `IncidentCommentThreadModal` con el canal
 *    `TECHNICIAN`.
 * 3. Al publicar un mensaje, el contador de la parada se incrementa de forma
 *    inmediata en la interfaz móvil, sin recargar la ruta.
 *
 * Blindaje constitucional (Art. V.4): la segregación del contador va en sentido
 * inverso al de la Sede. El técnico de campo sí contabiliza las notas internas
 * (RF-02.3) porque su canal tiene acceso legítimo a ellas; la vista nunca lee el
 * contador segregado de sede (`public_comments_count`).
 *
 * Dogma Vanilla: Node.js ESM nativo, cero dependencias externas, cero red.
 */

// Entorno mínimo de navegador para el store de sesión (localStorage/sessionStorage).
const storageMock = new Map();
globalThis.localStorage = {
  getItem: (k) => storageMock.get(k) ?? null,
  setItem: (k, v) => storageMock.set(k, String(v)),
  removeItem: (k) => storageMock.delete(k),
  clear: () => storageMock.clear()
};
globalThis.sessionStorage = { ...globalThis.localStorage };

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROUTE_VIEW_PATH = path.resolve(HERE, '../../public/assets/js/views/TechnicianRouteView.js');

const { api } = await import('../../public/assets/js/api.js');
const { TechnicianRouteView } = await import('../../public/assets/js/views/TechnicianRouteView.js');

const viewSource = fs.readFileSync(ROUTE_VIEW_PATH, 'utf8');
const template = String(TechnicianRouteView.template || '');

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
console.log(' VendGuard: Frontend Suite - Insignia de conversación de la parada (T-COM-15)');
console.log('======================================================================\n');

/** Paradas de ruta de prueba: conteos totales ya publicados por el servidor. */
const routeStops = [
  {
    id: 101,
    ticket_code: 'INC-2026-0101',
    urgency: 'HIGH',
    status: 'ASSIGNED',
    category: 'PAYMENT_SYSTEM',
    description: 'El monedero traga monedas de 1 euro y no da cambio.',
    comments_count: 7,
    machine: { id: 1, code: 'VEND-BCN-001', model: 'Vendo ColdDrink 800', floor_wing: 'Planta 1 · Cafetería' },
    location: { id: 1, name: 'Sede Central Barcelona', address: 'Av. Diagonal 123', contact_phone: '933001122' }
  },
  {
    id: 102,
    ticket_code: 'INC-2026-0102',
    urgency: 'CRITICAL',
    status: 'IN_PROGRESS',
    category: 'TEMPERATURE_COLD',
    description: 'Máquina de sándwiches a 14ºC con alerta de frío alimentario.',
    comments_count: 3,
    machine: { id: 2, code: 'VEND-BCN-002', model: 'Vendo FreshMeal 400', floor_wing: 'Planta Baja · Vestíbulo' },
    location: { id: 1, name: 'Sede Central Barcelona', address: 'Av. Diagonal 123', contact_phone: '933001122' }
  },
  {
    id: 103,
    ticket_code: 'INC-2026-0103',
    urgency: 'MEDIUM',
    status: 'PENDING_PARTS',
    category: 'MECHANICAL',
    description: 'Espiral 4 atascada sin dispensar bolsas de patatas.',
    machine: { id: 3, code: 'VEND-BCN-003', model: 'Vendo SnackMaster 600', floor_wing: 'Planta 3 · Sala de Descanso' },
    location: { id: 1, name: 'Sede Central Barcelona', address: 'Av. Diagonal 123', contact_phone: '933001122' }
  }
];

/**
 * Instancia de la vista con el mismo binding de Vue en Options API: estado de
 * `data()`, métodos enlazados y computadas resueltas como getters.
 */
function buildRouteInstance(overrides = {}) {
  const instance = Object.assign({
    ...TechnicianRouteView.data(),
    incidents: JSON.parse(JSON.stringify(routeStops)),
    $emit: () => {}
  }, overrides);

  for (const [name, fn] of Object.entries(TechnicianRouteView.methods || {})) {
    instance[name] = fn.bind(instance);
  }

  return instance;
}

// ─── Grupo 1: Insignia interactiva en cada tarjeta de parada (RF-01.1) ───────
console.log('--- Grupo 1: Insignia de conversación en la tarjeta de parada ---');

assert('1.1 La tarjeta de parada declara la clase de la insignia de conversación',
  template.includes('vg-stop-comments-badge'),
  'Se esperaba `vg-stop-comments-badge` en la plantilla de TechnicianRouteView.');

assert('1.2 La insignia se renderiza dentro del bucle de paradas de la ruta',
  /v-for="incident in filteredIncidents"[\s\S]*vg-stop-comments-badge/.test(template),
  'La insignia debe declararse dentro del bucle de tarjetas de parada.');

assert('1.3 La insignia es un botón interactivo real, no un adorno estático',
  /<button[\s\S]{0,900}vg-stop-comments-badge[\s\S]{0,900}@click="openCommentsModal\(incident\)"/.test(template),
  'El botón de la insignia debe enlazar @click="openCommentsModal(incident)".');

assert('1.4 La insignia incorpora el icono de conversación',
  template.includes('💬'),
  'Se esperaba el icono de diálogo en la insignia.');

assert('1.5 El contador numérico se renderiza desde la computada commentCountOf',
  template.includes('{{ commentCountOf(incident) }}') && template.includes('data-testid="stop-comments-count"'),
  'El contador debe interpolar `commentCountOf(incident)` en la chip numérica.');

assert('1.6 La insignia rotula la unidad en castellano',
  template.includes('mensajes'),
  'Se esperaba la unidad «mensajes» junto al contador.');

assert('1.7 La insignia declara identificadores estables y etiqueta accesible',
  template.includes('data-testid="btn-stop-comments"') && template.includes(":aria-label=\"'Conversación de la parada: ' + commentCountOf(incident) + ' mensajes'\""),
  'Se esperaban el data-testid y el aria-label de la insignia.');

assert('1.8 La insignia respeta el objetivo táctil móvil de una sola mano (RNF-03)',
  /vg-stop-comments-badge[\s\S]{0,400}height: 34px/.test(template),
  'Se esperaba una altura táctil móvil explícita en la insignia.');

// ─── Grupo 2: Contador TOTAL de mensajes, públicos e internos (RF-01.1, RF-02.3) ──
console.log('\n--- Grupo 2: Contador total de la parada (públicos + notas internas) ---');

const stopInstance = buildRouteInstance();

assert('2.1 Contabiliza el total de mensajes entregado por la API (7)',
  stopInstance.commentCountOf(stopInstance.incidents[0]) === 7,
  `Valor obtenido: ${stopInstance.commentCountOf(stopInstance.incidents[0])}`);

assert('2.2 Un contador 0 se muestra como 0 y nunca como hueco',
  stopInstance.commentCountOf({ comments_count: 0 }) === 0);

assert('2.3 Parada sin campo de contador degrada a 0 sin romper la tarjeta',
  stopInstance.commentCountOf(stopInstance.incidents[2]) === 0);

assert('2.4 Parada inexistente o sin objeto degrada a 0 de forma defensiva',
  stopInstance.commentCountOf(null) === 0 && stopInstance.commentCountOf({}) === 0);

assert('2.5 Contadores serializados como cadena se interpretan numéricamente',
  stopInstance.commentCountOf({ comments_count: '9' }) === 9,
  `Valor obtenido: ${stopInstance.commentCountOf({ comments_count: '9' })}`);

assert('2.6 El canal técnico contabiliza la TOTALIDAD de mensajes (RF-02.3)',
  viewSource.includes('comments_count') && !viewSource.includes('public_comments_count'),
  'El técnico de campo ve notas internas, así que su contador es el total; el contador segregado de sede no debe aparecer aquí.');

assert('2.7 El contador total se publica en el contrato del backend (RF-01.1)',
  stopInstance.commentCountOf(routeStops[0]) === routeStops[0].comments_count,
  'El valor de la insignia debe proceder del payload de la ruta.');

// ─── Grupo 3: Apertura del hilo con rol TECHNICIAN (RF-01.2, RF-02.3) ───────
console.log('\n--- Grupo 3: Apertura del hilo con canal TECHNICIAN ---');

assert('3.1 La vista registra el componente del hilo de conversación',
  Boolean(TechnicianRouteView.components?.IncidentCommentThreadModal),
  'TechnicianRouteView.components debe incluir IncidentCommentThreadModal.');

assert('3.2 La vista importa el modal del hilo desde el módulo local (Dogma Vanilla)',
  viewSource.includes("import { IncidentCommentThreadModal } from '../components/IncidentCommentThreadModal.js'"),
  'Se esperaba el import ESM local del modal.');

assert('3.3 El modal se monta con el canal TECHNICIAN (RF-02.3, RF-03.3)',
  template.includes('<IncidentCommentThreadModal') && template.includes('role="TECHNICIAN"'),
  'El modal debe montarse con role="TECHNICIAN".');

assert('3.4 El modal se abre con el expediente de la parada pulsada',
  template.includes(':is-open="showCommentsModal"')
    && template.includes(':incident-id="selectedCommentIncident?.id ?? null"')
    && template.includes(':ticket-code="selectedCommentIncident?.ticket_code ?? null"'),
  'Se esperaba el montaje con visibilidad, id y código de ticket de la parada.');

assert('3.5 El modal se cierra y refresca el contador al publicar (RF-03.4)',
  template.includes('@close="closeCommentsModal"') && template.includes('@comment-added="onCommentAdded"'),
  'Se esperaban los enlaces @close y @comment-added del modal.');

const openInstance = buildRouteInstance();
openInstance.openCommentsModal(openInstance.incidents[1]);

assert('3.6 openCommentsModal abre el modal y fija la parada seleccionada',
  openInstance.showCommentsModal === true
    && openInstance.selectedCommentIncident?.ticket_code === 'INC-2026-0102',
  `Estado: modal=${openInstance.showCommentsModal}, parada=${JSON.stringify(openInstance.selectedCommentIncident?.ticket_code)}`);

openInstance.openCommentsModal(null);
assert('3.7 openCommentsModal tolera una parada vacía sin abrir el hilo',
  openInstance.selectedCommentIncident?.id === 102,
  'Llamar sin parada no debe cambiar la selección previa.');

openInstance.closeCommentsModal();
assert('3.8 closeCommentsModal cierra el modal y libera la selección',
  openInstance.showCommentsModal === false && openInstance.selectedCommentIncident === null);

// ─── Grupo 4: Incremento inmediato y reactivo del contador (RF-03.4) ─────────
console.log('\n--- Grupo 4: Incremento inmediato del contador de la parada ---');

let routeReloads = 0;
api.technician.getMyRoute = async () => {
  routeReloads++;
  return [];
};

const publishInstance = buildRouteInstance();
publishInstance.loadRoute = async () => { routeReloads++; };

publishInstance.openCommentsModal(publishInstance.incidents[1]);
publishInstance.onCommentAdded({ pagination: { total_comments: 4 } });

assert('4.1 Publicar un mensaje actualiza el contador de la parada de inmediato',
  publishInstance.incidents.find(inc => inc.id === 102).comments_count === 4,
  `Contador tras publicar: ${publishInstance.incidents.find(inc => inc.id === 102).comments_count}`);

assert('4.2 La insignia refleja el nuevo contador sin recargar la ruta',
  publishInstance.commentCountOf(publishInstance.incidents.find(inc => inc.id === 102)) === 4
    && routeReloads === 0,
  `Recargas de ruta detectadas: ${routeReloads}`);

assert('4.3 El resto de paradas de la ruta conserva su contador intacto',
  publishInstance.incidents.find(inc => inc.id === 101).comments_count === 7
    && publishInstance.incidents.find(inc => inc.id === 103).comments_count === undefined);

const fallbackInstance = buildRouteInstance();
fallbackInstance.openCommentsModal(fallbackInstance.incidents[0]);
fallbackInstance.onCommentAdded({});

assert('4.4 Sin total del servidor el contador se incrementa en una unidad',
  fallbackInstance.incidents.find(inc => inc.id === 101).comments_count === 8,
  `Contador obtenido: ${fallbackInstance.incidents.find(inc => inc.id === 101).comments_count}`);

const firstMessageInstance = buildRouteInstance();
firstMessageInstance.openCommentsModal(firstMessageInstance.incidents[2]);
firstMessageInstance.onCommentAdded(null);

assert('4.5 La primera publicación sobre una parada sin hilo arranca el contador en 1',
  firstMessageInstance.commentCountOf(firstMessageInstance.incidents[2]) === 1,
  `Contador obtenido: ${firstMessageInstance.commentCountOf(firstMessageInstance.incidents[2])}`);

const guardedInstance = buildRouteInstance();
guardedInstance.selectedCommentIncident = null;

assert('4.6 Sin parada seleccionada el refresco del contador no rompe la vista',
  guardedInstance.onCommentAdded({ pagination: { total_comments: 99 } }) === undefined
    && guardedInstance.incidents.every(inc => inc.comments_count !== 99));

const exactInstance = buildRouteInstance();
exactInstance.openCommentsModal(exactInstance.incidents[0]);

assert('4.7 El total exacto del servidor prevalece sobre un incremento optimista',
  exactInstance.onCommentAdded({ pagination: { total_comments: 12 } }) === undefined
    && exactInstance.incidents[0].comments_count === 12,
  `Contador obtenido: ${exactInstance.incidents[0].comments_count}`);

assert('4.8 La vista sigue cargando la ruta por el endpoint oficial del técnico',
  viewSource.includes('api.technician.getMyRoute()'),
  'El refresco del contador no debe inventar endpoints ni sustituir la carga oficial.');

// RESUMEN DE EJECUCIÓN
console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. CONDICIÓN T-COM-15 CUMPLIDA.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.error(` RESULTADO: FALLO EN ${failures} ASERCIONES.`);
console.log('======================================================================\n');
process.exit(1);
