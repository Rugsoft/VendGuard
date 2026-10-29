/**
 * VendGuard - Arnés de navegador real para el cerrojo de sincronización (H5)
 *
 * Certifica el hallazgo H5 fuera de los dobles de prueba: dos pestañas reales de
 * Chromium, sobre el mismo perfil y el mismo origen, comparten `IndexedDB` y
 * `navigator.locks`, y arrancan a la vez el vaciado de la bandeja del mismo
 * técnico. Lo que se mide es lo que ocurre de verdad:
 *
 *  - **Exclusión real:** el servidor de pruebas cuenta las peticiones `/send` en
 *    vuelo; si el cerrojo no existiera, las dos pestañas enviarían los mismos
 *    `PENDING` a la vez y el contador llegaría a 2.
 *  - **Sin duplicados:** cada `client_mutation_id` sale a la red exactamente una
 *    vez aunque las dos pestañas vacíen a la vez.
 *  - **Aviso entre pestañas:** `BroadcastChannel` entrega `queue_changed` con
 *    `remote: true` a la pestaña que no estaba trabajando.
 *
 * El arnés usa solo lo que trae Node (servidor `http`, `WebSocket` global para el
 * protocolo DevTools) y el Chromium instalado en la máquina (Chrome o Edge). Si
 * no hay navegador, sale con código 2 para que el canal lo declare no ejecutable
 * en lugar de fingir un verde; nunca se sustituye por un doble.
 *
 * Dogma Vanilla: ESM nativo, cero dependencias npm, cero red externa.
 */

import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { extname, join, normalize, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const PROJECT_ROOT = resolve(fileURLToPath(new URL('../../', import.meta.url)));
const ASSETS_ROOT = join(PROJECT_ROOT, 'public', 'assets', 'js');

/** Milisegundos que el servidor tarda en responder a cada envío. */
const SEND_DELAY_MS = 150;

/** Mutation ids sembrados por la pestaña A. */
const MUTATION_COUNT = 3;

/** Tolerancia del arranque simultáneo de las dos pestañas. */
const START_BARRIER_MS = 150;

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
    return;
  }

  failures++;
  console.log(`  [FAIL] ${description}${details === '' ? '' : ` · ${details}`}`);
}

function notExecutable(reason) {
  console.log(`  [NO EJECUTABLE] ${reason}`);
  process.exit(2);
}

// ─────────────────────────────────────────────────────────────────────────
// 1. Navegador instalado
// ─────────────────────────────────────────────────────────────────────────

function candidateBrowsers() {
  const candidates = [];
  const explicit = process.env.VENDGUARD_CHROME_PATH;
  if (typeof explicit === 'string' && explicit.trim() !== '') {
    candidates.push(explicit.trim());
  }

  if (process.platform === 'win32') {
    for (const base of [process.env.PROGRAMFILES, process.env['PROGRAMFILES(X86)'], process.env.LOCALAPPDATA]) {
      if (typeof base !== 'string' || base === '') {
        continue;
      }
      candidates.push(join(base, 'Google', 'Chrome', 'Application', 'chrome.exe'));
      candidates.push(join(base, 'Microsoft', 'Edge', 'Application', 'msedge.exe'));
      candidates.push(join(base, 'Chromium', 'Application', 'chrome.exe'));
    }
  } else if (process.platform === 'darwin') {
    candidates.push('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome');
    candidates.push('/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge');
    candidates.push('/Applications/Chromium.app/Contents/MacOS/Chromium');
  } else {
    candidates.push('/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/microsoft-edge');
  }

  return candidates;
}

const browserPath = candidateBrowsers().find((candidate) => existsSync(candidate)) ?? null;

if (browserPath === null) {
  notExecutable('No se encontró Chrome, Edge ni Chromium. Define VENDGUARD_CHROME_PATH para certificar el cerrojo de pestañas.');
}

if (typeof WebSocket !== 'function') {
  notExecutable('Este Node no expone `WebSocket` global (requiere Node 22+), necesario para hablar con DevTools.');
}

// ─────────────────────────────────────────────────────────────────────────
// 2. Servidor de pruebas: sirve el harness y mide los envíos
// ─────────────────────────────────────────────────────────────────────────

const events = [];

/** Peticiones `/send` simultáneas: la prueba de que el cerrojo existe. */
let inFlight = 0;
let maxConcurrent = 0;

function json(response, status, payload) {
  const body = JSON.stringify(payload);
  response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
  response.end(body);
}

function serveAsset(response, urlPath) {
  const relative = urlPath.replace(/^\/assets\/js\//, '');
  const target = normalize(join(ASSETS_ROOT, relative));

  if (!target.startsWith(ASSETS_ROOT) || !existsSync(target)) {
    json(response, 404, { success: false, error: 'not_found' });
    return;
  }

  const types = { '.js': 'text/javascript; charset=utf-8', '.mjs': 'text/javascript; charset=utf-8' };
  response.writeHead(200, {
    'Content-Type': types[extname(target)] ?? 'application/octet-stream',
    'Cache-Control': 'no-store'
  });
  response.end(readFileSync(target));
}

function harnessPage() {
  return `<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>VendGuard · cerrojo de sincronización</title></head>
<body>
<script type="module">
import { OfflineStorage, MUTATION_STATUS } from '/assets/js/utils/OfflineStorage.js';
import { OfflineSyncManager } from '/assets/js/utils/OfflineSyncManager.js';

const tab = new URLSearchParams(location.search).get('tab') ?? 'A';
const received = [];
let storage = null;
let manager = null;

function bootstrap() {
  return {
    technician_id: 2,
    technician_email: 'lock@vendguard.test',
    technician_name: 'Técnico de certificación',
    synced_at: new Date().toISOString(),
    route: {
      stops: [
        { incident_id: 701, id: 701, status: 'ASSIGNED' },
        { incident_id: 702, id: 702, status: 'ASSIGNED' },
        { incident_id: 703, id: 703, status: 'ASSIGNED' }
      ],
      enabled_locations: [],
      pending_critical_stops_other_locations: []
    },
    preventive_orders: [],
    preventive_checklists_by_order: {},
    spare_parts_catalog_by_machine: {},
    van_stock: { items: [] }
  };
}

function mutation(index, sequenceOrder) {
  const stopId = 700 + index;

  return {
    client_mutation_id: 'mut_browser_' + tab + '_' + sequenceOrder,
    technician_id: 2,
    technician_email: 'lock@vendguard.test',
    sequence_order: sequenceOrder,
    status: MUTATION_STATUS.PENDING,
    syncing_since: null,
    stop_type: 'INCIDENT',
    stop_id: stopId,
    ticket_or_order_code: 'INC-BROWSER-' + stopId,
    machine_id: stopId,
    machine_code: 'VM-LOCK-' + stopId,
    is_perishable_machine: false,
    operation_type: 'START_INCIDENT',
    is_state_transition: true,
    http_method: 'PATCH',
    endpoint: '/technician/incidents/' + stopId + '/start',
    client_occurred_at: new Date().toISOString(),
    chain_started_at: null,
    chain_ended_at: null,
    sanitary_seal_confirmed: false,
    payload: {},
    photo_attachment: null,
    provisional_parts_deducted: [],
    conflict: null
  };
}

function buildManager(locks) {
  return new OfflineSyncManager({
    storage,
    ...(locks === null ? { locks: null } : {}),
    client: {
      request: async (endpoint, options) => {
        const body = options?.body ?? {};
        const mutationId = encodeURIComponent(String(body.client_mutation_id ?? ''));
        const response = await fetch('/send?tab=' + encodeURIComponent(tab) + '&mutation=' + mutationId, {
          method: 'POST'
        });
        if (!response.ok) {
          throw new Error('El servidor de pruebas rechazó el envío.');
        }
        return { success: true };
      }
    },
    dispatch: (detail) => received.push({ type: detail?.type ?? null, remote: detail?.remote === true })
  });
}

async function seedQueue() {
  await storage.clearTechnician(2);
  await storage.saveSnapshot(bootstrap());
  for (let index = 1; index <= ${MUTATION_COUNT}; index++) {
    await storage.enqueueMutation(2, ({ sequenceOrder }) => mutation(index, sequenceOrder));
  }
}

window.__lockHarness = {
  async arm() {
    storage = new OfflineStorage();

    if (tab === 'A') {
      await seedQueue();
    }

    manager = buildManager(undefined);

    return {
      tab,
      origin: location.origin,
      locks: typeof navigator.locks?.request === 'function',
      queued: (await storage.listMutations(2)).length
    };
  },

  /**
   * Control negativo: mismo escenario con el cerrojo desactivado.
   *
   * Sirve para demostrar que la medición puede detectar el fallo: si el arnés
   * no viera duplicados ni solapamiento aquí, su verde en el caso real no
   * significaría nada.
   */
  async armWithoutLock() {
    if (tab === 'A') {
      await seedQueue();
    }

    manager = buildManager(null);

    return { tab, lock: 'disabled', queued: (await storage.listMutations(2)).length };
  },

  async run() {
    const startedAt = Date.now();
    const summary = await manager.processOutboxQueue(2);
    const remaining = await storage.listMutations(2);

    return {
      tab,
      startedAt,
      summary,
      remaining: remaining.length,
      received
    };
  }
};

window.__lockHarnessReady = true;
</script>
</body>
</html>`;
}

const server = createServer((request, response) => {
  const url = new URL(request.url ?? '/', 'http://127.0.0.1');

  if (url.pathname === '/lock-test.html') {
    response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
    response.end(harnessPage());
    return;
  }

  if (url.pathname.startsWith('/assets/js/')) {
    serveAsset(response, url.pathname);
    return;
  }

  if (url.pathname === '/send') {
    const tab = url.searchParams.get('tab') ?? '?';
    const mutationId = url.searchParams.get('mutation') ?? '?';

    inFlight++;
    maxConcurrent = Math.max(maxConcurrent, inFlight);
    events.push({ kind: 'start', tab, mutationId, at: Date.now() });

    setTimeout(() => {
      inFlight--;
      events.push({ kind: 'end', tab, mutationId, at: Date.now() });
      json(response, 200, { success: true });
    }, SEND_DELAY_MS);

    return;
  }

  if (url.pathname === '/state') {
    json(response, 200, { events, maxConcurrent });
    return;
  }

  if (url.pathname === '/reset') {
    events.length = 0;
    maxConcurrent = inFlight;
    json(response, 200, { success: true });
    return;
  }

  json(response, 404, { success: false, error: 'not_found' });
});

await new Promise((resolveReady, rejectReady) => {
  server.once('error', rejectReady);
  server.listen(0, '127.0.0.1', resolveReady);
});

const origin = `http://127.0.0.1:${server.address().port}`;

console.log('======================================================================');
console.log(' VENDGUARD · Cerrojo de sincronización con dos pestañas reales (H5)');
console.log(` Navegador: ${browserPath}`);
console.log(' Categoría: new_engine (canal propio, fuera de la batería hermética)');
console.log('======================================================================\n');

// ─────────────────────────────────────────────────────────────────────────
// 3. Lanzar Chromium y conectar por DevTools
// ─────────────────────────────────────────────────────────────────────────

const profileDirectory = mkdtempSync(join(tmpdir(), 'vendguard-lock-'));

const browser = spawn(browserPath, [
  '--headless=new',
  '--disable-gpu',
  '--no-first-run',
  '--no-default-browser-check',
  '--disable-extensions',
  '--disable-background-networking',
  '--disable-component-update',
  '--disable-sync',
  '--mute-audio',
  `--user-data-dir=${profileDirectory}`,
  '--remote-debugging-port=0',
  'about:blank'
], { stdio: ['ignore', 'ignore', 'pipe'] });

const devToolsUrl = await new Promise((resolveUrl, rejectUrl) => {
  let buffer = '';
  const deadline = setTimeout(() => rejectUrl(new Error('Chromium no publicó su URL de DevTools en 20 s.')), 20000);

  browser.stderr.setEncoding('utf8');
  browser.stderr.on('data', (chunk) => {
    buffer += chunk;
    const match = buffer.match(/DevTools listening on (ws:\/\/[^\s]+)/);
    if (match !== null) {
      clearTimeout(deadline);
      resolveUrl(match[1]);
    }
  });
  browser.once('exit', (code) => {
    clearTimeout(deadline);
    rejectUrl(new Error(`Chromium terminó con código ${code} antes de publicar DevTools.`));
  });
});

/** Cliente mínimo del protocolo DevTools: una sola conexión, sesiones planas. */
async function connectDevTools(url) {
  const socket = new WebSocket(url);
  const pending = new Map();
  let nextId = 1;

  await new Promise((resolveOpen, rejectOpen) => {
    socket.addEventListener('open', resolveOpen, { once: true });
    socket.addEventListener('error', () => rejectOpen(new Error('No se pudo abrir el socket de DevTools.')), { once: true });
  });

  socket.addEventListener('message', (message) => {
    let payload;
    try {
      payload = JSON.parse(String(message.data));
    } catch {
      return;
    }

    if (payload.id === undefined) {
      return;
    }

    const waiter = pending.get(payload.id);
    pending.delete(payload.id);
    if (waiter === undefined) {
      return;
    }

    if (payload.error) {
      waiter.reject(new Error(payload.error.message ?? 'Error de DevTools.'));
    } else {
      waiter.resolve(payload.result ?? {});
    }
  });

  return {
    send(method, params = {}, sessionId = null) {
      const id = nextId++;
      return new Promise((resolveResult, rejectResult) => {
        pending.set(id, { resolve: resolveResult, reject: rejectResult });
        socket.send(JSON.stringify({
          id,
          method,
          params,
          ...(sessionId === null ? {} : { sessionId })
        }));
      });
    },
    close() {
      socket.close();
    }
  };
}

async function openTab(cdp, tabName) {
  const { targetId } = await cdp.send('Target.createTarget', { url: `${origin}/lock-test.html?tab=${tabName}` });
  const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });

  const evaluate = async (expression) => {
    const result = await cdp.send('Runtime.evaluate', {
      expression,
      awaitPromise: true,
      returnByValue: true
    }, sessionId);

    if (result.exceptionDetails) {
      throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text ?? 'Excepción en la pestaña.');
    }

    return result.result?.value;
  };

  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) {
    const ready = await evaluate('window.__lockHarnessReady === true');
    if (ready === true) {
      break;
    }
    await new Promise((resolveWait) => setTimeout(resolveWait, 50));
  }

  const ready = await evaluate('window.__lockHarnessReady === true');
  if (ready !== true) {
    throw new Error(`La pestaña ${tabName} no terminó de cargar el harness.`);
  }

  return { targetId, sessionId, evaluate };
}

let cdp = null;
let cleanupProfile = true;

try {
  cdp = await connectDevTools(devToolsUrl);

  const tabA = await openTab(cdp, 'A');
  const tabB = await openTab(cdp, 'B');

  const armedA = await tabA.evaluate('window.__lockHarness.arm()');
  const armedB = await tabB.evaluate('window.__lockHarness.arm()');

  console.log('--- 1. Dos pestañas reales sobre el mismo origen ---');
  assert(
    '1.1 Las dos pestañas viven en el mismo origen y comparten el perfil',
    armedA.origin === armedB.origin && armedA.origin.startsWith('http://127.0.0.1:'),
    `${armedA.origin} / ${armedB.origin}`
  );
  assert(
    '1.2 Web Locks está disponible en las dos pestañas (no se simula)',
    armedA.locks === true && armedB.locks === true,
    JSON.stringify({ a: armedA.locks, b: armedB.locks })
  );
  assert(
    '1.3 La siembra deja las tres operaciones en la bandeja compartida',
    armedA.queued === MUTATION_COUNT,
    `encoladas=${armedA.queued}`
  );

  console.log('\n--- 2. Vaciado simultáneo de la misma cola ---');
  const [runA, runB] = await Promise.all([
    tabA.evaluate('window.__lockHarness.run()'),
    tabB.evaluate('window.__lockHarness.run()')
  ]);

  const state = await (await fetch(`${origin}/state`)).json();
  const sends = state.events.filter((event) => event.kind === 'start');
  const duplicates = sends
    .map((event) => event.mutationId)
    .filter((mutationId, index, list) => list.indexOf(mutationId) !== index);

  // El arranque se mide dentro de cada pestaña: la primera petición del perdedor
  // llega después de que el ganador suelte el cerrojo, así que comparar envíos no
  // diría nada sobre la simultaneidad de la llamada.
  const startDelta = Math.abs(Number(runA.startedAt) - Number(runB.startedAt));

  assert(
    '2.1 Las dos pestañas arrancan su vaciado a la vez (diferencia < 150 ms)',
    Number.isFinite(startDelta) && startDelta < START_BARRIER_MS,
    `diferencia=${startDelta} ms`
  );
  assert(
    '2.2 El servidor de pruebas nunca ve dos envíos en vuelo (maxConcurrent = 1)',
    state.maxConcurrent === 1,
    `maxConcurrent=${state.maxConcurrent}`
  );
  assert(
    '2.3 Cada operación sale a la red exactamente una vez',
    sends.length === MUTATION_COUNT && duplicates.length === 0,
    `envíos=${sends.length} duplicados=${duplicates.length}`
  );
  assert(
    '2.4 La suma de aplicadas es la de la cola, no el doble',
    Number(runA.summary?.applied ?? 0) + Number(runB.summary?.applied ?? 0) === MUTATION_COUNT,
    JSON.stringify({ a: runA.summary, b: runB.summary })
  );
  assert(
    '2.5 La bandeja queda vacía en las dos pestañas',
    runA.remaining === 0 && runB.remaining === 0,
    `a=${runA.remaining} b=${runB.remaining}`
  );

  console.log('\n--- 3. Aviso entre pestañas ---');
  const peerAnnouncements = [...(runA.received ?? []), ...(runB.received ?? [])]
    .filter((entry) => entry.type === 'queue_changed' && entry.remote === true);

  assert(
    '3.1 La pestaña que no trabajaba recibe el aviso remoto de la bandeja',
    peerAnnouncements.length >= 1,
    JSON.stringify({ a: runA.received, b: runB.received })
  );
  assert(
    '3.2 El aviso recibido se marca como remoto para no reemitirse',
    [...(runA.received ?? []), ...(runB.received ?? [])]
      .filter((entry) => entry.type === 'queue_changed')
      .every((entry) => entry.remote === true)
  );

  console.log('\n--- 4. Control negativo: el arnés detecta el fallo sin cerrojo ---');
  await fetch(`${origin}/reset`, { method: 'POST' });
  await tabA.evaluate('window.__lockHarness.armWithoutLock()');
  await tabB.evaluate('window.__lockHarness.armWithoutLock()');

  await Promise.all([
    tabA.evaluate('window.__lockHarness.run()'),
    tabB.evaluate('window.__lockHarness.run()')
  ]);
  const unlockedState = await (await fetch(`${origin}/state`)).json();
  const unlockedSends = unlockedState.events.filter((event) => event.kind === 'start');

  assert(
    '4.1 Sin cerrojo las dos pestañas envían el mismo trabajo (más de 3 envíos)',
    unlockedSends.length > MUTATION_COUNT,
    `envíos=${unlockedSends.length}`
  );
  assert(
    '4.2 Sin cerrojo el servidor sí ve dos envíos en vuelo',
    unlockedState.maxConcurrent >= 2,
    `maxConcurrent=${unlockedState.maxConcurrent}`
  );
} catch (error) {
  failures++;
  console.log(`\n  [FAIL] La certificación se interrumpió: ${error?.message ?? error}`);
} finally {
  cdp?.close();
  browser.kill();

  await new Promise((resolveExit) => {
    const deadline = setTimeout(() => {
      try {
        process.kill(browser.pid, 'SIGKILL');
      } catch {
        // El proceso ya no existe.
      }
      resolveExit();
    }, 3000);

    browser.once('exit', () => {
      clearTimeout(deadline);
      resolveExit();
    });
  });

  server.close();

  if (cleanupProfile) {
    try {
      rmSync(profileDirectory, { recursive: true, force: true });
    } catch {
      // El perfil temporal se queda si Windows aún lo retiene; no altera la medición.
    }
  }
}

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. CERROJO ENTRE PESTAÑAS CERTIFICADO EN NAVEGADOR REAL.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.log(' RESULTADO: FALLO EN EL CERROJO ENTRE PESTAÑAS.');
console.log('======================================================================\n');
process.exit(1);
