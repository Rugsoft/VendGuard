/**
 * VendGuard - Frontend Unit Test Suite (ConnectionStateBannerTest.mjs)
 *
 * Certifica el componente global del estado de conexión (RF-04.3, RF-04.4, RNF-06;
 * tarea T-HYBRID-16) sin navegador, sin red y sin dependencias npm:
 *
 *  - **Contrato del módulo:** módulo ES nativo (cero `import`), nombre canónico, escucha del
 *    evento `vendguard:network-status` y los tres estados declarados.
 *  - **`idle` (oculto):** no se pinta aviso y, aun así, la región viva sigue montada —una
 *    región viva que aparece y desaparece del DOM se anuncia tarde o no se anuncia— y la capa
 *    flotante no se queda con los clics de nadie (`pointer-events: none`).
 *  - **`disconnected`:** aviso ámbar con los tokens del sistema de diseño (ni un literal
 *    hexadecimal, que es lo que mantiene congelado el techo de `DesignTokenDebtRatchetTest`),
 *    `role="alert"`, el recordatorio de que el borrador sigue guardado y el botón *Reintentar*
 *    con objetivo táctil ≥ 44 px (EARS-04.4).
 *  - **`reconnected`:** confirmación verde que se retira sola a los 3.000 ms, con un único
 *    temporizador que se reemplaza (nunca dos).
 *  - **Reintento:** invoca el callback publicado por el transporte —el mismo cuerpo, la misma
 *    operación, el mismo borrador—, no se repite mientras corre y, si vuelve a fallar, **no
 *    retira el aviso**: mentir sobre una conexión rota es peor que insistir.
 *  - **Ciclo de vida:** el listener se registra y se retira con el mismo manejador, y la
 *    retirada diferida queda cancelada al desmontar.
 *
 * Aislamiento del arnés: cada caso crea su propia instancia y la conduce **por su propio
 * manejador** (`handleNetworkStatus`), de modo que un caso no puede contaminar a otro. El camino
 * real del evento nativo se certifica aparte, despachando por la ventana instrumentada; sin esa
 * separación, dos instancias vivas a la vez atenderían el mismo evento y cualquier recuento de
 * temporizadores mediría el arnés en lugar del componente.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const SOURCE_PATH = fileURLToPath(new URL('../../public/assets/js/components/ConnectionStateBanner.js', import.meta.url));
const SOURCE = readFileSync(SOURCE_PATH, 'utf8');
const HEX_PATTERN = /#[0-9a-fA-F]{3,8}\b/g;

// ---------------------------------------------------------------------
// Doble del navegador: ventana instrumentada con planificador de mentira.
// ---------------------------------------------------------------------

/** @type {Map<string, Set<Function>>} */
const listeners = new Map();
/** @type {Map<number, {handler: Function, delayMs: number}>} */
const timers = new Map();
let timerSequence = 0;

globalThis.window = {
  addEventListener(name, handler) {
    if (!listeners.has(name)) listeners.set(name, new Set());
    listeners.get(name).add(handler);
  },
  removeEventListener(name, handler) {
    listeners.get(name)?.delete(handler);
  },
  setTimeout(handler, delayMs) {
    timerSequence += 1;
    timers.set(timerSequence, { handler, delayMs });
    return timerSequence;
  },
  clearTimeout(timerId) {
    timers.delete(timerId);
  }
};

const { ConnectionStateBanner, CONNECTION_STATUS_EVENT, CONNECTION_STATES, RECONNECTED_NOTICE_MS } =
  await import('../../public/assets/js/components/ConnectionStateBanner.js');

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - ConnectionStateBanner (T-HYBRID-16)');
console.log('======================================================================\n');

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.log(`  [FAIL] ${description}`);
    if (details) console.log(`         Motivo: ${details}`);
    failures++;
  }
}

/** Entrega un estado a UNA instancia, como haría Vue al invocar su manejador. */
function emit(target, detail) {
  target.handleNetworkStatus({ detail });
}

/** Despacha por la ventana instrumentada: el camino real del evento nativo. */
function dispatchThroughWindow(detail) {
  for (const handler of listeners.get(CONNECTION_STATUS_EVENT) ?? []) {
    handler({ detail });
  }
}

/** Ejecuta el temporizador pendiente más antiguo, como haría el reloj del navegador. */
function runPendingTimer() {
  const [id, timer] = [...timers.entries()][0] ?? [];
  if (id === undefined) return false;
  timers.delete(id);
  timer.handler();
  return true;
}

/** Deja el banco de temporizadores en cero antes de un caso que los cuenta. */
function resetTimers() {
  timers.clear();
}

/**
 * Instancia del componente con sus `data`, `computed` y `methods` cableados como lo haría Vue,
 * y su ciclo de vida gobernado a mano (no hay DOM en Node).
 */
function createBanner() {
  const emitted = [];
  const instance = {
    ...ConnectionStateBanner.data(),
    $emit: (event, payload) => { emitted.push({ event, payload }); },
    getEmitted: () => emitted
  };

  for (const [name, method] of Object.entries(ConnectionStateBanner.methods)) {
    instance[name] = method.bind(instance);
  }
  for (const name of Object.keys(ConnectionStateBanner.computed)) {
    Object.defineProperty(instance, name, {
      get: () => ConnectionStateBanner.computed[name].call(instance)
    });
  }

  ConnectionStateBanner.mounted.call(instance);

  return instance;
}

const RETRY_ONE = () => Promise.resolve('usado');

// ---------------------------------------------------------------------
// GRUPO 1: Contrato del módulo
// ---------------------------------------------------------------------
console.log('--- Grupo 1: Contrato del módulo ---');

assert('1.1 El componente se exporta con su nombre canónico y una plantilla de Vue',
  ConnectionStateBanner.name === 'ConnectionStateBanner'
    && typeof ConnectionStateBanner.template === 'string'
    && ConnectionStateBanner.template.includes('data-testid="connection-state-banner"'));

assert('1.2 Escucha el evento canónico que publica el transporte',
  CONNECTION_STATUS_EVENT === 'vendguard:network-status'
    && SOURCE.includes('host.addEventListener(CONNECTION_STATUS_EVENT, this.handleNetworkStatus)'));

assert('1.3 Declara exactamente los tres estados del contrato',
  CONNECTION_STATES.IDLE === 'idle'
    && CONNECTION_STATES.DISCONNECTED === 'disconnected'
    && CONNECTION_STATES.RECONNECTED === 'reconnected'
    && Object.keys(CONNECTION_STATES).length === 3);

assert('1.4 La confirmación verde vive 3.000 ms', RECONNECTED_NOTICE_MS === 3000);

const overlayInstance = createBanner();
assert('1.5 La capa flota fija arriba con z-index 9999 y ancho completo',
  overlayInstance.overlayStyle.position === 'fixed'
    && overlayInstance.overlayStyle.top === '0'
    && overlayInstance.overlayStyle.left === '0'
    && overlayInstance.overlayStyle.width === '100%'
    && overlayInstance.overlayStyle.zIndex === '9999',
  JSON.stringify(overlayInstance.overlayStyle));

assert('1.6 Sin aviso la capa no se queda con los clics y el aviso sí los recupera',
  overlayInstance.overlayStyle.pointerEvents === 'none'
    && overlayInstance.noticeStyle.pointerEvents === 'auto');

assert('1.7 El camino real del evento nativo llega al componente montado',
  (() => {
    const target = createBanner();
    const before = listeners.get(CONNECTION_STATUS_EVENT)?.size ?? 0;
    dispatchThroughWindow({ status: 'disconnected', retry: RETRY_ONE });
    return before >= 1 && target.state === 'disconnected';
  })());

// ---------------------------------------------------------------------
// GRUPO 2: `idle` — oculto, pero con la región viva montada
// ---------------------------------------------------------------------
console.log('\n--- Grupo 2: Estado idle (oculto) ---');

const idleBanner = createBanner();

assert('2.1 El estado inicial es idle y no hay nada visible',
  idleBanner.state === 'idle' && idleBanner.isVisible === false);

assert('2.2 El aviso no se pinta en el DOM hasta que hay algo que contar',
  ConnectionStateBanner.template.includes('v-if="isVisible"')
    && ConnectionStateBanner.template.includes('data-testid="connection-state-notice"'));

assert('2.3 La región viva sigue montada en estado idle (se anuncia sin aparecer y desaparecer)',
  ConnectionStateBanner.template.includes(':role="isDisconnected ? \'alert\' : \'status\'"')
    && ConnectionStateBanner.template.includes(':aria-live="isDisconnected ? \'assertive\' : \'polite\'"')
    && ConnectionStateBanner.template.indexOf(':aria-live')
      < ConnectionStateBanner.template.indexOf('data-testid="connection-state-notice"'));

assert('2.4 En idle no hay aviso que reintentar',
  idleBanner.retryCallback === null && idleBanner.isRetrying === false);

emit(idleBanner, { status: 'weather-report' });
assert('2.5 Un estado desconocido no cambia nada (el banner no inventa conexiones)',
  idleBanner.state === 'idle' && idleBanner.isVisible === false && timers.size === 0);

// ---------------------------------------------------------------------
// GRUPO 3: `disconnected` — ámbar, accesible y táctil
// ---------------------------------------------------------------------
console.log('\n--- Grupo 3: Estado disconnected (aviso ámbar) ---');

resetTimers();
const offlineBanner = createBanner();
emit(offlineBanner, { status: 'disconnected', retry: RETRY_ONE });

assert('3.1 El evento deja el aviso visible en estado disconnected',
  offlineBanner.state === 'disconnected' && offlineBanner.isVisible === true);

assert('3.2 La superficie es el ámbar del sistema, por tokens y sin un solo literal hexadecimal',
  offlineBanner.noticeStyle.backgroundColor === 'var(--color-warning-bg)'
    && offlineBanner.noticeStyle.color === 'var(--color-warning-text)'
    && offlineBanner.noticeStyle.borderBottom === '1px solid var(--color-warning-border)'
    && (SOURCE.match(HEX_PATTERN) || []).length === 0,
  `hex encontrados: ${JSON.stringify(SOURCE.match(HEX_PATTERN))}`);

assert('3.3 El aviso se anuncia como alerta y no como simple estado',
  ConnectionStateBanner.template.includes(":role=\"isDisconnected ? 'alert' : 'status'\""));

assert('3.4 El texto recuerda que lo redactado sigue guardado (EARS-04.3)',
  offlineBanner.noticeMessage.includes('sigue guardado en el formulario'),
  offlineBanner.noticeMessage);

assert('3.5 El motivo real del transporte manda sobre el texto por defecto',
  (() => {
    const detailed = createBanner();
    emit(detailed, { status: 'disconnected', message: 'Tiempo de espera agotado (5,0 s) al guardar la resolución.' });
    return detailed.noticeMessage === 'Tiempo de espera agotado (5,0 s) al guardar la resolución.';
  })());

assert('3.6 El botón Reintentar vive sólo en el aviso de desconexión',
  ConnectionStateBanner.template.includes('v-if="isDisconnected"')
    && ConnectionStateBanner.template.includes('data-testid="connection-state-retry"')
    && ConnectionStateBanner.template.includes('Reintentar'));

assert('3.7 El objetivo táctil del botón alcanza los 44 px de alto y de ancho (EARS-04.4)',
  ConnectionStateBanner.template.includes('min-height: 44px')
    && ConnectionStateBanner.template.includes('min-width: 44px'));

assert('3.8 El botón viste el tono de cautela del sistema y no declara color propio',
  ConnectionStateBanner.template.includes('class="vg-btn vg-btn-warning"')
    && !/data-testid="connection-state-retry"[^>]*style="[^"]*#[0-9a-fA-F]{3,8}/.test(ConnectionStateBanner.template));

assert('3.9 Un aviso repetido actualiza el motivo sin programar retirada alguna',
  (() => {
    const repeated = createBanner();
    emit(repeated, { status: 'disconnected', retry: RETRY_ONE });
    emit(repeated, { status: 'disconnected', message: 'Sigue sin haber conexión.' });
    return repeated.state === 'disconnected'
      && repeated.noticeMessage === 'Sigue sin haber conexión.'
      && timers.size === 0
      && repeated.isVisible === true;
  })());

assert('3.10 El aviso repetido conserva el callback de la operación que quedó a medias',
  offlineBanner.retryCallback === RETRY_ONE);

// ---------------------------------------------------------------------
// GRUPO 4: `reconnected` — verde por 3 segundos
// ---------------------------------------------------------------------
console.log('\n--- Grupo 4: Estado reconnected (verde, 3 s) ---');

resetTimers();
const backBanner = createBanner();
emit(backBanner, { status: 'disconnected', retry: RETRY_ONE });
emit(backBanner, { status: 'reconnected' });

assert('4.1 La recuperación deja el aviso en verde y visible',
  backBanner.state === 'reconnected' && backBanner.isVisible === true);

assert('4.2 La superficie es el verde del sistema, por tokens',
  backBanner.noticeStyle.backgroundColor === 'var(--color-success-bg)'
    && backBanner.noticeStyle.color === 'var(--color-success-text)'
    && backBanner.noticeStyle.borderBottom === '1px solid var(--color-success)');

assert('4.3 Se programa un único temporizador de 3.000 ms',
  timers.size === 1 && [...timers.values()][0].delayMs === 3000,
  JSON.stringify([...timers.values()]));

assert('4.4 Al cumplirse los 3 segundos el banner se retira solo',
  runPendingTimer() === true
    && backBanner.state === 'idle'
    && backBanner.isVisible === false
    && backBanner.noticeMessage === 'Sin conexión con el servidor. Lo que has escrito sigue guardado en el formulario: revisa tu red y reintenta cuando la recuperes.'
    && timers.size === 0);

assert('4.5 Dos confirmaciones seguidas dejan un solo temporizador (el último)',
  (() => {
    resetTimers();
    const twice = createBanner();
    emit(twice, { status: 'reconnected' });
    const first = [...timers.keys()][0];
    emit(twice, { status: 'reconnected' });
    return timers.size === 1 && !timers.has(first);
  })());

assert('4.6 Un idle explícito retira el aviso en el acto y cancela la retirada pendiente',
  (() => {
    resetTimers();
    const cancelled = createBanner();
    emit(cancelled, { status: 'reconnected' });
    const hadTimer = timers.size === 1;
    emit(cancelled, { status: 'idle' });
    return hadTimer && cancelled.state === 'idle' && timers.size === 0;
  })());

// ---------------------------------------------------------------------
// GRUPO 5: Reintento sin recargar y sin perder el borrador (EARS-04.4)
// ---------------------------------------------------------------------
console.log('\n--- Grupo 5: Reintento de la operación que falló ---');

let resolveRetry;
const pendingRetry = new Promise((resolve) => { resolveRetry = resolve; });
let pendingCalls = 0;

const retryBanner = createBanner();
emit(retryBanner, { status: 'disconnected', retry: () => { pendingCalls++; return pendingRetry; } });

const inFlight = retryBanner.retry();

assert('5.1 Mientras el reintento corre el botón queda deshabilitado',
  retryBanner.isRetrying === true);

const secondAttempt = retryBanner.retry();
resolveRetry('hecho');
await Promise.all([inFlight, secondAttempt]);

assert('5.2 Una segunda pulsación durante el reintento no repite la operación',
  pendingCalls === 1 && retryBanner.isRetrying === false,
  `llamadas=${pendingCalls}`);

assert('5.3 El reintento se anuncia con su desenlace',
  retryBanner.getEmitted().some((entry) => entry.event === 'retry' && entry.payload?.ok === true),
  JSON.stringify(retryBanner.getEmitted()));

const failingBanner = createBanner();
emit(failingBanner, { status: 'disconnected', retry: () => Promise.reject(new Error('Sigue sin red.')) });
await failingBanner.retry();

assert('5.4 Si el reintento vuelve a fallar el aviso NO se retira (no se miente sobre la conexión)',
  failingBanner.state === 'disconnected'
    && failingBanner.isVisible === true
    && failingBanner.isRetrying === false);

assert('5.5 El fallo del reintento se publica con su motivo',
  failingBanner.getEmitted().some((entry) => entry.event === 'retry'
    && entry.payload?.ok === false
    && entry.payload?.message === 'Sigue sin red.'),
  JSON.stringify(failingBanner.getEmitted()));

assert('5.6 El reintento usa el callback más reciente publicado por el transporte',
  (() => {
    const swapped = createBanner();
    const used = [];
    emit(swapped, { status: 'disconnected', retry: () => { used.push('viejo'); return Promise.resolve(); } });
    emit(swapped, { status: 'disconnected', retry: () => { used.push('nuevo'); return Promise.resolve(); } });
    swapped.retry();
    return used[0] === 'nuevo';
  })());

assert('5.7 Sin operación a la que volver el botón no finge un reintento',
  (() => {
    const empty = createBanner();
    emit(empty, { status: 'disconnected' });
    empty.retry();
    const last = empty.getEmitted().at(-1);
    return last?.payload?.ok === false
      && last.payload.message.includes('No hay ninguna operación pendiente');
  })());

assert('5.8 El reintento nunca recarga la página (el borrador vive en memoria reactiva)',
  !SOURCE.includes('location.reload')
    && !SOURCE.includes('window.location')
    && !SOURCE.includes('document.'),
  'Recargar la página destruiría el borrador que EARS-04.3 manda preservar.');

// ---------------------------------------------------------------------
// GRUPO 6: Ciclo de vida y disciplina del sistema de diseño
// ---------------------------------------------------------------------
console.log('\n--- Grupo 6: Ciclo de vida y disciplina visual ---');

assert('6.1 Desmontar retira exactamente el manejador que se registró al montar',
  (() => {
    const target = createBanner();
    const before = new Set(listeners.get(CONNECTION_STATUS_EVENT) ?? []);
    ConnectionStateBanner.beforeUnmount.call(target);
    const after = listeners.get(CONNECTION_STATUS_EVENT) ?? new Set();
    return before.size - after.size === 1
      && SOURCE.includes('host.removeEventListener(CONNECTION_STATUS_EVENT, this.handleNetworkStatus)');
  })());

assert('6.2 Desmontar cancela la retirada diferida pendiente',
  (() => {
    resetTimers();
    const unmounted = createBanner();
    emit(unmounted, { status: 'reconnected' });
    const hadTimer = timers.size === 1;
    ConnectionStateBanner.beforeUnmount.call(unmounted);
    return hadTimer && timers.size === 0 && unmounted.idleTimer === null;
  })());

assert('6.3 El componente no aporta ni un literal hexadecimal (techo de deuda visual intacto)',
  (SOURCE.match(HEX_PATTERN) || []).length === 0);

assert('6.4 El texto visible es castellano y el código es inglés (Dualismo Lingüístico)',
  CONNECTION_STATES.IDLE === 'idle'
    && ConnectionStateBanner.template.includes('Reintentar')
    && SOURCE.includes('Conexión restablecida.')
    && SOURCE.includes('Sin conexión con el servidor'));

assert('6.5 Módulo ES nativo sin una sola dependencia externa (Dogma Vanilla)',
  !/^\s*import\s/m.test(SOURCE) && !SOURCE.includes('require('));

// ---------------------------------------------------------------------
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);
if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-HYBRID-16 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.log(' RESULT: FAILURES DETECTED IN TEST SUITE.');
  console.log('======================================================================\n');
  process.exit(1);
}
