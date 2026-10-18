/**
 * VendGuard - Frontend Unit Test Suite (TechnicianDeepLinkTest.mjs)
 *
 * Certifica el enlace entrante del aviso de asignación (Módulo 15, T-TG-06; RF-TG-08
 * y RF-TG-09):
 *
 * 1. `?tech=1&ticket=INC-…` lleva a la vista del técnico sin autenticar por sí solo.
 * 2. La vista resalta la parada citada cuando la ruta ya está cargada, comparando
 *    el ticket sin distinguir mayúsculas.
 * 3. Si el expediente ya no está en la ruta, se avisa en lugar de mostrar una lista
 *    vacía sin explicación.
 * 4. El enlace se aplica una sola vez: un refresco no vuelve a desplazar la vista.
 *
 * Dogma Vanilla: runner ES Module sin dependencias ni navegador.
 */

// 1. Mock de almacenamiento para el módulo de sesión
const storageMock = new Map();
globalThis.localStorage = {
  getItem: (k) => storageMock.get(k) ?? null,
  setItem: (k, v) => storageMock.set(k, String(v)),
  removeItem: (k) => storageMock.delete(k),
  clear: () => storageMock.clear()
};
globalThis.sessionStorage = { ...globalThis.localStorage };

import { TechnicianRouteView } from '../../public/assets/js/views/TechnicianRouteView.js';
import { App } from '../../public/assets/js/app.js';

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - TechnicianDeepLink (Módulo 15)');
console.log('======================================================================\n');

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.log(`  [FAIL] ${description}`);
    if (details) console.log(`         ${details}`);
    failures++;
  }
}

const routeIncidents = [
  { id: 501, ticket_code: 'INC-2026-H7PU', status: 'ASSIGNED', urgency: 'MEDIUM' },
  { id: 502, ticket_code: 'INC-2026-AAAA', status: 'IN_PROGRESS', urgency: 'HIGH' }
];

/**
 * Contexto mínimo de la vista: sólo lo que usa `applyDeepLink`.
 */
const makeViewContext = (ticket) => ({
  initialTicketCode: ticket,
  incidents: routeIncidents,
  deepLinkHandled: false,
  deepLinkNotice: '',
  highlightedIncidentId: null,
  $nextTick: (fn) => fn(),
  applyDeepLink: TechnicianRouteView.methods.applyDeepLink
});

console.log('--- 1. Contrato de la prop y del enlace ---\n');

assert(
  '1.1 La vista declara el ticket entrante como prop opcional',
  TechnicianRouteView.props
    && TechnicianRouteView.props.initialTicketCode
    && TechnicianRouteView.props.initialTicketCode.default === ''
);

console.log('\n--- 2. Enrutado del deep link en la aplicación ---\n');

globalThis.window = globalThis.window || {};
globalThis.window.location = { search: '?tech=1&ticket=INC-2026-H7PU' };

const appContext = {
  currentView: 'portal',
  trackingToken: '',
  siteCode: 'SEDE-BCN-01',
  qrMachineCode: '',
  qrSiteCode: '',
  deepLinkTicket: ''
};
App.created.call(appContext);

assert(
  '2.1 ?tech=1 abre la vista del técnico',
  appContext.currentView === 'technician',
  `Vista resultante: ${appContext.currentView}`
);
assert(
  '2.2 El ticket citado se conserva para abrir la parada',
  appContext.deepLinkTicket === 'INC-2026-H7PU',
  `Ticket: ${appContext.deepLinkTicket}`
);

globalThis.window.location = { search: '?site=SEDE-BCN-01&ticket=INC-2026-H7PU' };
const portalContext = { ...appContext, currentView: 'portal', deepLinkTicket: '' };
App.created.call(portalContext);
assert(
  '2.3 El enlace de Sede no secuestra la vista del técnico',
  portalContext.currentView === 'portal' && portalContext.deepLinkTicket === 'INC-2026-H7PU'
);

console.log('\n--- 3. Resalte de la parada citada ---\n');

const matching = makeViewContext('INC-2026-H7PU');
matching.applyDeepLink.call(matching);
assert(
  '3.1 La parada citada queda resaltada',
  matching.highlightedIncidentId === 501,
  `Resaltado: ${matching.highlightedIncidentId}`
);
assert('3.2 No se muestra aviso cuando la parada existe', matching.deepLinkNotice === '');
assert('3.3 El enlace queda consumido', matching.deepLinkHandled === true);

const caseInsensitive = makeViewContext('inc-2026-h7pu');
caseInsensitive.applyDeepLink.call(caseInsensitive);
assert(
  '3.4 La comparación del ticket no distingue mayúsculas',
  caseInsensitive.highlightedIncidentId === 501
);

console.log('\n--- 4. Expediente ausente y consumo único ---\n');

const missing = makeViewContext('INC-2026-ZZZZ');
missing.applyDeepLink.call(missing);
assert(
  '4.1 Un expediente que ya no está en la ruta se explica, no se ignora',
  missing.deepLinkNotice.includes('INC-2026-ZZZZ') && missing.deepLinkNotice.includes('ya no está en tu ruta')
);
assert('4.2 Sin parada que resaltar no se marca ninguna', missing.highlightedIncidentId === null);

const repeated = makeViewContext('INC-2026-H7PU');
repeated.applyDeepLink.call(repeated);
const firstHighlight = repeated.highlightedIncidentId;
repeated.highlightedIncidentId = null;
repeated.applyDeepLink.call(repeated);
assert(
  '4.3 Un segundo render no vuelve a aplicar el enlace (sin desplazamientos fantasma)',
  firstHighlight === 501 && repeated.highlightedIncidentId === null
);

const noTicket = makeViewContext('');
noTicket.applyDeepLink.call(noTicket);
assert(
  '4.4 Entrar sin enlace no altera la vista',
  noTicket.deepLinkHandled === true
    && noTicket.highlightedIncidentId === null
    && noTicket.deepLinkNotice === ''
);

assert(
  '4.5 La tarjeta lleva un identificador estable para el desplazamiento',
  String(TechnicianRouteView.template).includes('technician-stop-')
);

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Fallas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: VERDE. El enlace aterriza en la avería, no en la pantalla de inicio.');
  console.log('======================================================================');
  process.exit(0);
}

console.log(' RESULTADO: FALLIDO.');
console.log('======================================================================');
process.exit(1);
