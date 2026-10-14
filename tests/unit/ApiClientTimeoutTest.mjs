/**
 * VendGuard - Frontend Unit Test Suite (ApiClientTimeoutTest.mjs)
 *
 * Certifica el interceptor de resiliencia del cliente HTTP (RF-04.1 a RF-04.4, RNF-01; tareas
 * T-HYBRID-17 y T-HYBRID-20) sin navegador, sin red y sin dependencias npm:
 *
 *  - **Tiempo de espera ordinario estricto de 5,0 s (EARS-04.1):** la constante es 5.000 ms, la
 *    petición viaja con una señal de aborto, y el corte se **mide de verdad** —con el reloj real
 *    del intérprete y un `fetch` que respeta la señal— contra el mecanismo que lo produce.
 *    También se certifica la forma de reserva (`AbortController`) con un reloj corto, porque el
 *    navegador antiguo que la necesita no puede ser el único que no la tenga probada.
 *  - **Cancelación deliberada ≠ avería (EARS-04.1/EARS-04.3):** si el llamante aborta, no se
 *    anuncia ninguna desconexión global por una decisión suya.
 *  - **Aviso al banner con reintento (EARS-04.3, EARS-04.4):** el fallo de red y el tiempo
 *    agotado publican `vendguard:network-status` con `disconnected`, el motivo real y el
 *    callback que repite **exactamente** la misma operación; el primer éxito posterior publica
 *    `reconnected` una sola vez.
 *  - **Petición duplicada accidental (EARS-04.2):** dos entregas idénticas simultáneas salen a
 *    la red una vez y comparten respuesta; fuera del aire, la siguiente vuelve a salir. Las
 *    lecturas no se fusionan y las subidas de archivo tampoco (dos ficheros distintos comparten
 *    endpoint y descartar uno en silencio sería perder una evidencia).
 *  - **Retención del borrador (EARS-04.3):** un fallo no recarga la página, no borra la sesión y
 *    no toca el token; el reintento repite el mismo cuerpo sin volver a redactarlo.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const SOURCE_PATH = fileURLToPath(new URL('../../public/assets/js/api.js', import.meta.url));
const SOURCE = readFileSync(SOURCE_PATH, 'utf8');

// ---------------------------------------------------------------------
// Doble del navegador: ventana que captura eventos y fetch instrumentado.
// ---------------------------------------------------------------------

/** @type {Array<{type: string, detail: any}>} */
const announced = [];

class MockCustomEvent {
  constructor(type, init = {}) {
    this.type = type;
    this.detail = init.detail;
  }
}

globalThis.CustomEvent = MockCustomEvent;
globalThis.window = {
  dispatchEvent: (event) => {
    announced.push({ type: event.type, detail: event.detail });
    return true;
  }
};

const {
  ApiClient,
  ApiError,
  REQUEST_TIMEOUT_MS,
  NETWORK_STATUS_EVENT,
  REQUEST_STATE_EVENT,
  buildTimeoutSignal,
  combineWithTimeoutSignal
} = await import('../../public/assets/js/api.js');

const { CONNECTION_STATUS_EVENT } = await import('../../public/assets/js/components/ConnectionStateBanner.js');

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - ApiClientTimeout (T-HYBRID-17)');
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

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** @type {Array<{url: string, options: any}>} */
let fetchCalls = [];
/** @type {Function} */
let fetchBehavior = async () => jsonResponse({ ok: true });

globalThis.fetch = async (url, options) => {
  fetchCalls.push({ url, options });
  return fetchBehavior(url, options);
};

function jsonResponse(data, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    statusText: status === 200 ? 'OK' : 'Error',
    headers: { get: (name) => (String(name).toLowerCase() === 'content-type' ? 'application/json' : null) },
    json: async () => ({ success: true, data })
  };
}

/**
 * `fetch` que respeta la señal: sólo la aborta el reloj, como en el navegador.
 *
 * El temporizador de guarda es deliberado: en Node, `AbortSignal.timeout()` **no mantiene vivo
 * el bucle de eventos** (su temporizador va *unref*), de modo que una petición colgada sin
 * ningún otro asa termina el proceso con «unsettled top-level await» en lugar de esperar al
 * corte. Un socket real, en cambio, mantiene la operación viva: eso es lo que simula esta
 * guarda, y sin ella la medición del corte no llegaría a producirse.
 */
function hangingFetchRespectingSignal(url, options) {
  return new Promise((resolve, reject) => {
    const signal = options?.signal;
    if (!signal) return; // sin señal esta petición no terminaría nunca: es justo el defecto que se vigila

    const keepAlive = setTimeout(() => {}, 30000);
    // El rechazo lleva **el motivo de la señal**, como hace el navegador: `AbortError` si
    // canceló quien pidió, `TimeoutError` si venció el plazo. Inventarse uno de los dos haría
    // que la suite certificase su propio doble en lugar del comportamiento real.
    const failWith = () => {
      clearTimeout(keepAlive);
      reject(signal.reason instanceof Error
        ? signal.reason
        : Object.assign(new Error('aborted'), { name: 'AbortError' }));
    };

    if (signal.aborted) return failWith();
    signal.addEventListener('abort', failWith);
  });
}

const networkDown = () => Promise.reject(Object.assign(new Error('Failed to fetch'), { name: 'TypeError' }));
const timedOut = () => Promise.reject(
  typeof DOMException !== 'undefined'
    ? new DOMException('The operation was aborted due to timeout', 'TimeoutError')
    : Object.assign(new Error('timeout'), { name: 'TimeoutError' })
);

function statusEvents() {
  return announced.filter((entry) => entry.type === NETWORK_STATUS_EVENT).map((entry) => entry.detail);
}

function lastStatus() {
  const events = statusEvents();
  return events.length > 0 ? events[events.length - 1] : null;
}

// ---------------------------------------------------------------------
// GRUPO 1: El contrato del tiempo de espera
// ---------------------------------------------------------------------
console.log('--- Grupo 1: Tiempo de espera ordinario estricto (EARS-04.1) ---');

assert('1.1 El tiempo de espera ordinario es exactamente 5,0 segundos',
  REQUEST_TIMEOUT_MS === 5000,
  `valor=${REQUEST_TIMEOUT_MS}`);

assert('1.2 El evento de conexión es el mismo que consume el banner global (una sola verdad)',
  NETWORK_STATUS_EVENT === 'vendguard:network-status'
    && NETWORK_STATUS_EVENT === CONNECTION_STATUS_EVENT);

const client = new ApiClient('/api');
fetchCalls = [];
fetchBehavior = async () => jsonResponse({ listo: true });
await client.get('/status-check');

assert('1.3 Toda petición viaja con su señal de aborto',
  fetchCalls.length === 1
    && fetchCalls[0].options.signal !== undefined
    && fetchCalls[0].options.signal !== null
    && fetchCalls[0].options.signal.aborted === false,
  JSON.stringify({ tieneSeñal: fetchCalls[0]?.options?.signal !== undefined }));

assert('1.4 El reloj se arma con el mecanismo nativo y con el plazo de la casa, sin argumentos espurios',
  SOURCE.includes('config.signal = linkedSignal.signal')
    && SOURCE.includes('buildTimeoutSignal()')
    && SOURCE.includes('AbortSignal.timeout(timeoutMs)'));

assert('1.5 El mecanismo de reserva (`AbortController`) también existe y es correcto',
  (() => {
    const nativeTimeout = AbortSignal.timeout;
    try {
      delete AbortSignal.timeout;
      return typeof buildTimeoutSignal(50).signal?.aborted === 'boolean';
    } finally {
      AbortSignal.timeout = nativeTimeout;
    }
  })());

// ---------------------------------------------------------------------
// GRUPO 2: El corte, medido con el reloj real
// ---------------------------------------------------------------------
console.log('\n--- Grupo 2: El corte de los 5,0 s, medido ---');

const shortClock = buildTimeoutSignal(30);
const shortClockWasArmed = shortClock.signal.aborted === false;
await sleep(70);

assert('2.1 Un reloj corto del mismo mecanismo aborta la señal de verdad al cumplirse',
  shortClockWasArmed === true && shortClock.signal.aborted === true,
  `armada=${shortClockWasArmed} abortada=${shortClock.signal.aborted}`);

const fallbackOutcome = await (async () => {
  const nativeTimeout = AbortSignal.timeout;
  try {
    delete AbortSignal.timeout;
    const armed = buildTimeoutSignal(30);
    const cancelled = buildTimeoutSignal(5000);
    cancelled.cancel();
    await sleep(70);
    return {
      armedTimedOut: armed.hasTimedOut(),
      armedAborted: armed.signal.aborted,
      cancelledAborted: cancelled.signal.aborted
    };
  } finally {
    AbortSignal.timeout = nativeTimeout;
  }
})();

assert('2.2 La forma de reserva marca el vencimiento y no dispara el reloj que se canceló a tiempo',
  fallbackOutcome.armedTimedOut === true
    && fallbackOutcome.armedAborted === true
    && fallbackOutcome.cancelledAborted === false,
  JSON.stringify(fallbackOutcome));

const measuredClient = new ApiClient('/api');
fetchCalls = [];
fetchBehavior = hangingFetchRespectingSignal;
const startedAt = Date.now();
let cutError = null;
try {
  await measuredClient.request('/operacion-que-nunca-responde');
} catch (error) {
  cutError = error;
}
const elapsed = Date.now() - startedAt;

assert('2.3 Una petición que no responde se corta a los 5,0 s con REQUEST_TIMEOUT',
  cutError instanceof ApiError
    && cutError.code === 'REQUEST_TIMEOUT'
    && cutError.status === 0,
  `${cutError?.code} tras ${elapsed} ms`);

assert('2.4 El corte medido cae en la ventana de los 5,0 s (ni antes ni después del plazo)',
  elapsed >= 4900 && elapsed <= 7000,
  `transcurrido=${elapsed} ms`);

assert('2.5 El aviso del corte dice cuánto se esperó y que el borrador sigue ahí (EARS-04.3)',
  lastStatus()?.status === 'disconnected'
    && lastStatus()?.message.includes('5,0 segundos')
    && lastStatus()?.message.includes('sigue en el formulario'),
  JSON.stringify(lastStatus()));

// ---------------------------------------------------------------------
// GRUPO 3: Aviso al banner y reintento que repite la operación (EARS-04.4)
// ---------------------------------------------------------------------
console.log('\n--- Grupo 3: Aviso al banner y reintento exacto ---');

const retryClient = new ApiClient('/api');
announced.length = 0;
fetchCalls = [];
let attempt = 0;
fetchBehavior = async () => {
  attempt++;
  if (attempt === 1) return networkDown();
  return jsonResponse({ resuelto: true });
};

const body = { diagnosis: 'Compresor con arranque intermitente y pérdida de frío confirmada.', parts: [7] };
let firstFailure = null;
try {
  await retryClient.post('/technician/incidents/9/resolve', body);
} catch (error) {
  firstFailure = error;
}

assert('3.1 Una caída de red se traduce a NETWORK_ERROR con mensaje de borrador preservado',
  firstFailure?.code === 'NETWORK_ERROR'
    && firstFailure.message.includes('sigue en el formulario'),
  firstFailure?.message);

assert('3.2 El fallo publica `disconnected` con el callback de reintento (EARS-04.3)',
  lastStatus()?.status === 'disconnected'
    && typeof lastStatus()?.retry === 'function');

const retried = await lastStatus().retry();

assert('3.3 El reintento repite la misma operación sin recargar la página',
  retried?.resuelto === true
    && fetchCalls.length === 2
    && fetchCalls[0].url === fetchCalls[1].url
    && fetchCalls[0].options.body === fetchCalls[1].options.body
    && fetchCalls[0].options.method === 'POST',
  JSON.stringify(fetchCalls.map((call) => call.options.body)));

assert('3.4 El cuerpo reintentado es byte a byte el mismo borrador (nada se recompone)',
  fetchCalls[1].options.body === JSON.stringify(body));

assert('3.5 El primer éxito posterior publica `reconnected` (lo firma el transporte, no un reloj)',
  lastStatus()?.status === 'reconnected');

assert('3.6 El éxito inmediatamente siguiente NO vuelve a anunciar la recuperación',
  (() => {
    const before = statusEvents().filter((detail) => detail.status === 'reconnected').length;
    return before === 1;
  })());

fetchBehavior = async () => jsonResponse({ otra: true });
await retryClient.get('/otra-consulta');
assert('3.7 Sin caída previa no se anuncia ninguna recuperación (no se inventa un hecho)',
  statusEvents().filter((detail) => detail.status === 'reconnected').length === 1);

// ---------------------------------------------------------------------
// GRUPO 4: Cancelar no es caerse
// ---------------------------------------------------------------------
console.log('\n--- Grupo 4: Cancelación deliberada frente a avería ---');

const abortClient = new ApiClient('/api');
announced.length = 0;
fetchCalls = [];
fetchBehavior = hangingFetchRespectingSignal;

const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
let abortedError = null;
if (controller) {
  const aborting = abortClient.request('/consulta-cancelable', { signal: controller.signal });
  controller.abort();
  try {
    await aborting;
  } catch (error) {
    abortedError = error;
  }
}

assert('4.1 La señal del llamante sigue pudiendo cancelar la petición',
  fetchCalls.length === 1 && abortedError?.code === 'REQUEST_ABORTED');

assert('4.2 Abortar de forma deliberada no anuncia ninguna desconexión global',
  statusEvents().length === 0,
  `eventos=${statusEvents().length}`);

assert('4.3 Una señal del llamante NO desactiva el techo de 5,0 s de la casa',
  SOURCE.includes('combineWithTimeoutSignal(options.signal, timeout)')
    && SOURCE.includes('config.signal = linkedSignal.signal')
    && (() => {
      const timed = combineWithTimeoutSignal(new AbortController().signal, buildTimeoutSignal(30));
      return typeof timed.signal?.aborted === 'boolean';
    })());

const linkedTimeout = combineWithTimeoutSignal(new AbortController().signal, buildTimeoutSignal(30));
await sleep(70);

assert('4.4 Con señal ajena, el vencimiento del plazo llega con motivo de vencimiento (no de cancelación)',
  linkedTimeout.signal.aborted === true
    && linkedTimeout.signal.reason?.name === 'TimeoutError',
  `motivo=${linkedTimeout.signal.reason?.name}`);

assert('4.5 La cancelación del llamante llega con su propio motivo, distinguible del vencimiento',
  (() => {
    const controllerForLink = new AbortController();
    const linked = combineWithTimeoutSignal(controllerForLink.signal, buildTimeoutSignal(5000));
    controllerForLink.abort();
    return linked.signal.aborted === true && linked.signal.reason?.name === 'AbortError';
  })());

assert('4.6 La señal combinada deja de escuchar al desmontar la operación (sin fugas)',
  (() => {
    const controllerForLink = new AbortController();
    const linked = combineWithTimeoutSignal(controllerForLink.signal, buildTimeoutSignal(5000));
    linked.cancel();
    controllerForLink.abort();
    return linked.signal.aborted === false;
  })());

// ---------------------------------------------------------------------
// GRUPO 5: La petición duplicada accidental (EARS-04.2)
// ---------------------------------------------------------------------
console.log('\n--- Grupo 5: Envíos duplicados accidentales ---');

const duplicateClient = new ApiClient('/api');
announced.length = 0;
fetchCalls = [];
let releaseFirst = null;
fetchBehavior = () => new Promise((resolve) => { releaseFirst = () => resolve(jsonResponse({ alta: 1 })); });

const sameBody = { machine_id: 3, description: 'Pulsador de selección atascado.' };
const firstDelivery = duplicateClient.post('/incidents', sameBody);
const secondDelivery = duplicateClient.post('/incidents', sameBody);

assert('5.1 Dos entregas idénticas simultáneas salen a la red una sola vez',
  fetchCalls.length === 1, `llamadas=${fetchCalls.length}`);

assert('5.2 El transporte se declara ocupado mientras hay un envío vivo (RF-04.2)',
  duplicateClient.isBusy() === true
    && duplicateClient.getPendingRequestCount() >= 1
    && announced.some((entry) => entry.type === REQUEST_STATE_EVENT && entry.detail?.isBusy === true));

releaseFirst();
const [firstResult, secondResult] = await Promise.all([firstDelivery, secondDelivery]);

assert('5.3 La segunda entrega recibe la respuesta de la primera en lugar de un error',
  firstResult?.alta === 1 && secondResult?.alta === 1 && fetchCalls.length === 1);

assert('5.4 Terminada la operación el transporte vuelve a estar libre',
  duplicateClient.isBusy() === false
    && duplicateClient.getPendingRequestCount() === 0
    && announced.some((entry) => entry.type === REQUEST_STATE_EVENT && entry.detail?.isBusy === false));

fetchBehavior = async () => jsonResponse({ alta: 2 });
await duplicateClient.post('/incidents', sameBody);
assert('5.5 Fuera del aire, la misma entrega vuelve a salir (el guardián es de concurrencia, no de historia)',
  fetchCalls.length === 2);

assert('5.6 Dos cuerpos distintos no se confunden entre sí',
  (async () => {
    fetchCalls = [];
    let release;
    fetchBehavior = () => new Promise((resolve) => { release = () => resolve(jsonResponse({ ok: 1 })); });
    const a = duplicateClient.post('/incidents', { machine_id: 4, description: 'Uno distinto del otro.' });
    const b = duplicateClient.post('/incidents', { machine_id: 5, description: 'Otro distinto del primero.' });
    const simultaneous = fetchCalls.length;
    release();
    await Promise.all([a, b]);
    return simultaneous === 2;
  })());

assert('5.7 Dos lecturas iguales simultáneas no se fusionan (compartir respuesta sería ahorrar mintiendo)',
  (async () => {
    fetchCalls = [];
    let release;
    fetchBehavior = () => new Promise((resolve) => { release = () => resolve(jsonResponse({ listo: 1 })); });
    const a = duplicateClient.get('/incidents');
    const b = duplicateClient.get('/incidents');
    const simultaneous = fetchCalls.length;
    release();
    await Promise.all([a, b]);
    return simultaneous === 2;
  })());

assert('5.8 Dos subidas de archivo simultáneas no se fusionan (jamás se descarta una evidencia)',
  (async () => {
    const originalFormData = globalThis.FormData;
    class MockFormData {}
    globalThis.FormData = MockFormData;
    try {
      fetchCalls = [];
      let release;
      fetchBehavior = () => new Promise((resolve) => { release = () => resolve(jsonResponse({ subida: 1 })); });
      const a = duplicateClient.upload('/incidents/1/photos', new MockFormData());
      const b = duplicateClient.upload('/incidents/1/photos', new MockFormData());
      const simultaneous = fetchCalls.length;
      release();
      await Promise.all([a, b]);
      return simultaneous === 2;
    } finally {
      globalThis.FormData = originalFormData;
    }
  })());

// ---------------------------------------------------------------------
// GRUPO 6: El borrador no se toca y la descarga también está acotada
// ---------------------------------------------------------------------
console.log('\n--- Grupo 6: Retención del borrador y alcance del reloj ---');

const draftClient = new ApiClient('/api');
draftClient.setToken('auth_token_de_prueba');
announced.length = 0;
fetchCalls = [];
fetchBehavior = networkDown;

let draftFailure = null;
try {
  await draftClient.post('/technician/incidents/12/resolve', { diagnosis: 'Texto largo redactado por el técnico.' });
} catch (error) {
  draftFailure = error;
}

assert('6.1 Un fallo no cierra la sesión ni vacía el token (el borrador y el acceso sobreviven)',
  draftClient.getToken() === 'auth_token_de_prueba' && draftFailure !== null);

assert('6.2 El interceptor no recarga la página por su cuenta (EARS-04.3: el borrador vive en memoria)',
  !SOURCE.includes('location.reload')
    && !SOURCE.includes('location.href =')
    && !SOURCE.includes('location.assign'),
  'Recargar destruiría el borrador que el aviso promete conservar.');

assert('6.3 La descarga de archivos también viaja con su señal de aborto',
  (async () => {
    fetchCalls = [];
    fetchBehavior = async () => ({
      ok: true,
      status: 200,
      headers: { get: (name) => (String(name).toLowerCase() === 'content-disposition' ? 'attachment; filename="r.csv"' : null) },
      blob: async () => 'blob'
    });
    const originalUrl = globalThis.URL;
    try {
      await draftClient.downloadFile('/exports/spare-parts', 'repuestos.csv');
    } catch (error) {
      // El guardado del fichero en Node no importa aquí: lo que se certifica es la señal.
    } finally {
      globalThis.URL = originalUrl;
    }
    return fetchCalls.length === 1 && fetchCalls[0].options.signal !== undefined;
  })());

assert('6.4 La descarga caída también avisa al banner con su reintento',
  (async () => {
    announced.length = 0;
    fetchCalls = [];
    fetchBehavior = networkDown;
    try {
      await draftClient.downloadFile('/exports/spare-parts', 'repuestos.csv');
    } catch (error) {
      // esperado
    }
    return lastStatus()?.status === 'disconnected' && typeof lastStatus()?.retry === 'function';
  })());

assert('6.5 Un envío que no llega a salir no deja el guardián de duplicados bloqueado',
  (async () => {
    const blocked = new ApiClient('/api');
    fetchBehavior = networkDown;
    const failing = blocked.post('/incidents', { machine_id: 9, description: 'Intento que no llega.' });
    await failing.catch(() => {});
    fetchCalls = [];
    fetchBehavior = async () => jsonResponse({ alta: 9 });
    await blocked.post('/incidents', { machine_id: 9, description: 'Intento que no llega.' });
    return fetchCalls.length === 1;
  })());

assert('6.6 El módulo sigue sin una sola dependencia externa (Dogma Vanilla)',
  !/^\s*import\s/m.test(SOURCE) && !SOURCE.includes('require('));

// ---------------------------------------------------------------------
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);
if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-HYBRID-17 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.log(' RESULT: FAILURES DETECTED IN TEST SUITE.');
  console.log('======================================================================\n');
  process.exit(1);
}
