/**
 * VendGuard - Verificación real a 360 px de las superficies L1/SAT (H-36-04)
 *
 * Monta los componentes reales (`RestockerRouteView` y `TechnicianRouteView`)
 * con los estilos de producción y mide, en un Chromium real a 360 × 800:
 *
 *  1. La bandeja L1 del reponedor, su tarjeta y la coincidencia en sede.
 *  2. La tarjeta SAT escalada, la nota interna, su foto y la coincidencia en sede.
 *
 * La medición no usa dobles de layout: se sirve el documento a 360 px, con la
 * barra de scroll oculta para emular un viewport móvil, y se inspeccionan
 * `document.documentElement.scrollWidth`, `body.scrollWidth`, los rectángulos
 * reales y los elementos que se salen del viewport. Si no hay navegador o
 * Node 22+, sale con código 2 para que el canal lo declare no ejecutable en
 * lugar de fingir un verde.
 *
 * Dogma Vanilla: ESM nativo, cero dependencias npm, cero red externa.
 */

import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, mkdtempSync, rmSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { extname, join, normalize, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const PROJECT_ROOT = resolve(fileURLToPath(new URL('../../', import.meta.url)));
const PUBLIC_ROOT = join(PROJECT_ROOT, 'public');
const SUPPORT_ROOT = join(PROJECT_ROOT, 'tests', 'Support');
const HARNESSES = {
  l1: join(SUPPORT_ROOT, 'restocker_sat_layout_l1.html'),
  sat: join(SUPPORT_ROOT, 'restocker_sat_layout_sat.html')
};
const VIEWPORT = { width: 360, height: 800 };

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
// 1. Navegador instalado y Node con WebSocket global
// ─────────────────────────────────────────────────────────────────────────

function playwrightChromiumCandidates() {
  const roots = [];
  if (process.platform === 'win32') {
    if (typeof process.env.LOCALAPPDATA === 'string' && process.env.LOCALAPPDATA !== '') {
      roots.push(join(process.env.LOCALAPPDATA, 'ms-playwright'));
    }
  } else if (process.platform === 'darwin') {
    roots.push(join(homedir(), 'Library', 'Caches', 'ms-playwright'));
  } else {
    const cacheRoot = process.env.XDG_CACHE_HOME ?? join(homedir(), '.cache');
    roots.push(join(cacheRoot, 'ms-playwright'));
  }

  const candidates = [];
  for (const root of roots) {
    let entries;
    try {
      entries = readdirSync(root, { withFileTypes: true });
    } catch {
      continue;
    }

    const revisions = entries
      .filter((entry) => entry.isDirectory() && /^chromium-\d+$/.test(entry.name))
      .map((entry) => entry.name)
      .sort((left, right) => right.localeCompare(left, 'en', { numeric: true }));

    for (const revision of revisions) {
      candidates.push(
        join(root, revision, 'chrome-win64', 'chrome.exe'),
        join(root, revision, 'chrome-win', 'chrome.exe'),
        join(root, revision, 'chrome-linux', 'chrome'),
        join(root, revision, 'chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium')
      );
    }
  }

  return candidates.filter((candidate) => existsSync(candidate));
}

function candidateBrowsers() {
  const candidates = [];
  const explicit = process.env.VENDGUARD_CHROME_PATH;
  if (typeof explicit === 'string' && explicit.trim() !== '') {
    candidates.push(explicit.trim());
  }

  const edgeCandidates = [];

  if (process.platform === 'win32') {
    for (const base of [process.env.PROGRAMFILES, process.env['PROGRAMFILES(X86)'], process.env.LOCALAPPDATA]) {
      if (typeof base !== 'string' || base === '') {
        continue;
      }
      candidates.push(join(base, 'Google', 'Chrome', 'Application', 'chrome.exe'));
      candidates.push(join(base, 'Chromium', 'Application', 'chrome.exe'));
      edgeCandidates.push(join(base, 'Microsoft', 'Edge', 'Application', 'msedge.exe'));
    }
  } else if (process.platform === 'darwin') {
    candidates.push('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome');
    candidates.push('/Applications/Chromium.app/Contents/MacOS/Chromium');
    edgeCandidates.push('/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge');
  } else {
    candidates.push('/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser');
    edgeCandidates.push('/usr/bin/microsoft-edge');
  }

  candidates.push(...playwrightChromiumCandidates());
  candidates.push(...edgeCandidates);

  return candidates;
}

const browserPath = candidateBrowsers().find((candidate) => existsSync(candidate)) ?? null;

if (browserPath === null) {
  notExecutable('No se encontró Chrome, Edge ni Chromium. Define VENDGUARD_CHROME_PATH para certificar el layout móvil.');
}

if (typeof WebSocket !== 'function') {
  notExecutable('Este Node no expone `WebSocket` global (requiere Node 22+), necesario para hablar con DevTools.');
}

// ─────────────────────────────────────────────────────────────────────────
// 2. Servidor de pruebas: componentes reales y estilos de producción
// ─────────────────────────────────────────────────────────────────────────

function serveFile(response, filePath) {
  if (!existsSync(filePath)) {
    response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    response.end('not_found');
    return;
  }

  const types = {
    '.html': 'text/html; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.mjs': 'text/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.webp': 'image/webp',
    '.woff2': 'font/woff2'
  };

  response.writeHead(200, {
    'Content-Type': types[extname(filePath)] ?? 'application/octet-stream',
    'Cache-Control': 'no-store'
  });
  response.end(readFileSync(filePath));
}

function serveAsset(response, urlPath) {
  const relative = decodeURIComponent(urlPath.replace(/^\/assets\//, ''));
  const assetsRoot = join(PUBLIC_ROOT, 'assets');
  const target = normalize(join(assetsRoot, relative));

  if (!target.startsWith(assetsRoot + sep) || !existsSync(target)) {
    response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    response.end('not_found');
    return;
  }

  serveFile(response, target);
}

const server = createServer((request, response) => {
  const url = new URL(request.url ?? '/', 'http://127.0.0.1');

  if (url.pathname === '/l1.html' || url.pathname === '/sat.html') {
    const view = url.pathname.slice(1, -'.html'.length);
    serveFile(response, HARNESSES[view]);
    return;
  }

  if (url.pathname.startsWith('/assets/')) {
    serveAsset(response, url.pathname);
    return;
  }

  response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
  response.end('not_found');
});

await new Promise((resolveReady, rejectReady) => {
  server.once('error', rejectReady);
  server.listen(0, '127.0.0.1', resolveReady);
});

const origin = `http://127.0.0.1:${server.address().port}`;

console.log('======================================================================');
console.log(' VENDGUARD · Layout móvil 360 px de avisos L1 y ruta SAT (H-36-04)');
console.log(` Navegador: ${browserPath}`);
console.log(' Categoría: new_engine (canal propio, fuera de la batería hermética)');
console.log('======================================================================\n');

// ─────────────────────────────────────────────────────────────────────────
// 3. Lanzar Chromium y conectar por DevTools
// ─────────────────────────────────────────────────────────────────────────

const profileDirectory = mkdtempSync(join(tmpdir(), 'vendguard-l1-sat-layout-'));

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

async function openSurface(cdp, view) {
  const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });

  const send = (method, params = {}) => cdp.send(method, params, sessionId);
  const evaluate = async (expression) => {
    const result = await send('Runtime.evaluate', {
      expression,
      awaitPromise: true,
      returnByValue: true
    });

    if (result.exceptionDetails) {
      throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text ?? 'Excepción en la pestaña.');
    }

    return result.result?.value;
  };

  await send('Page.enable');
  await send('Runtime.enable');
  await send('Emulation.setDeviceMetricsOverride', {
    width: VIEWPORT.width,
    height: VIEWPORT.height,
    deviceScaleFactor: 1,
    mobile: true,
    screenWidth: VIEWPORT.width,
    screenHeight: VIEWPORT.height
  });

  try {
    await send('Emulation.setScrollbarsHidden', { hidden: true });
  } catch {
    // La barra también se oculta por CSS en el arnés; este ajuste es opcional.
  }

  await send('Page.navigate', { url: `${origin}/${view}.html` });

  const deadline = Date.now() + 20000;
  let ready = false;
  while (Date.now() < deadline) {
    ready = await evaluate('window.__harnessReady === true');
    if (ready === true) {
      break;
    }
    await new Promise((resolveWait) => setTimeout(resolveWait, 50));
  }

  if (ready !== true) {
    const state = await evaluate('window.__harnessState ?? null');
    throw new Error(`El arnés ${view} no terminó de cargar. Estado: ${JSON.stringify(state)}`);
  }

  return {
    targetId,
    sessionId,
    evaluate,
    async close() {
      try {
        await cdp.send('Target.closeTarget', { targetId });
      } catch {
        // La pestaña ya no existe.
      }
    }
  };
}

// ─────────────────────────────────────────────────────────────────────────
// 4. Mediciones del documento y de las superficies
// ─────────────────────────────────────────────────────────────────────────

async function measureDocument(surface) {
  return surface.evaluate(`(() => {
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const elementIsInsideHorizontalScroller = (element) => {
      let parent = element.parentElement;
      while (parent !== null && parent !== document.body) {
        const style = getComputedStyle(parent);
        if ((style.overflowX === 'auto' || style.overflowX === 'scroll') && parent.scrollWidth > parent.clientWidth + 1) {
          return true;
        }
        parent = parent.parentElement;
      }
      return false;
    };

    const offenders = [...document.body.querySelectorAll('*')]
      .filter((element) => {
        const rect = element.getBoundingClientRect();
        if (rect.width <= 0.5 || rect.height <= 0.5) {
          return false;
        }
        return rect.right > viewportWidth + 0.5 || rect.left < -0.5;
      })
      .filter((element) => !elementIsInsideHorizontalScroller(element))
      .slice(0, 12)
      .map((element) => {
        const rect = element.getBoundingClientRect();
        return {
          tag: element.tagName,
          testid: element.getAttribute('data-testid'),
          className: typeof element.className === 'string' ? element.className : '',
          left: Number(rect.left.toFixed(1)),
          right: Number(rect.right.toFixed(1)),
          width: Number(rect.width.toFixed(1))
        };
      });

    return {
      viewportWidth,
      viewportHeight,
      clientWidth: document.documentElement.clientWidth,
      documentScrollWidth: document.documentElement.scrollWidth,
      bodyScrollWidth: document.body.scrollWidth,
      bodyScrollHeight: document.body.scrollHeight,
      fontsStatus: document.fonts?.status ?? 'unknown',
      offenders
    };
  })()`);
}

async function measureElement(surface, selector) {
  return surface.evaluate(`(() => {
    const element = document.querySelector(${JSON.stringify(selector)});
    if (element === null) {
      return { found: false };
    }

    const rect = element.getBoundingClientRect();
    return {
      found: true,
      left: Number(rect.left.toFixed(1)),
      top: Number(rect.top.toFixed(1)),
      right: Number(rect.right.toFixed(1)),
      bottom: Number(rect.bottom.toFixed(1)),
      width: Number(rect.width.toFixed(1)),
      height: Number(rect.height.toFixed(1)),
      scrollWidth: element.scrollWidth,
      clientWidth: element.clientWidth,
      scrollHeight: element.scrollHeight,
      clientHeight: element.clientHeight,
      overflowX: getComputedStyle(element).overflowX,
      textLength: (element.textContent ?? '').trim().length
    };
  })()`);
}

async function scrollIntoView(surface, selector) {
  return surface.evaluate(`(() => {
    const element = document.querySelector(${JSON.stringify(selector)});
    if (element === null) {
      return false;
    }
    element.scrollIntoView({ block: 'center', inline: 'nearest' });
    return true;
  })()`);
}

async function waitForSelector(surface, selector, timeoutMs = 5000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const found = await surface.evaluate(`document.querySelector(${JSON.stringify(selector)}) !== null`);
    if (found === true) {
      return true;
    }
    await new Promise((resolveWait) => setTimeout(resolveWait, 50));
  }
  return false;
}

function formatDetails(value) {
  return JSON.stringify(value);
}

let cdp = null;

try {
  cdp = await connectDevTools(devToolsUrl);

  // ───────────────────────────────────────────────────────────────────────
  // 5. RestockerRouteView: bandeja L1 y coincidencia en sede
  // ───────────────────────────────────────────────────────────────────────

  console.log('--- 1. Bandeja de Avisos de Nivel 1 (L1) ---');
  const l1 = await openSurface(cdp, 'l1');
  const l1Ready = await waitForSelector(l1, '[data-testid="restocker-l1-incident-card"]');
  assert('1.1 La tarjeta L1 se monta con datos reales', l1Ready === true);

  const l1Document = await measureDocument(l1);
  assert(
    '1.2 El viewport real es 360 px y el documento no se ensancha',
    l1Document.viewportWidth === 360
      && l1Document.clientWidth === 360
      && l1Document.documentScrollWidth <= 360,
    formatDetails(l1Document)
  );
  assert(
    '1.3 El body tampoco genera scroll horizontal',
    l1Document.bodyScrollWidth <= 360,
    `bodyScrollWidth=${l1Document.bodyScrollWidth}`
  );
  assert(
    '1.4 Ningún elemento visible del documento se sale del viewport',
    l1Document.offenders.length === 0,
    formatDetails(l1Document.offenders)
  );

  const l1Section = await measureElement(l1, '[data-testid="restocker-l1-incidents"]');
  assert(
    '1.5 La bandeja L1 cabe entre 0 y 360 px',
    l1Section.found === true
      && l1Section.left >= 0
      && l1Section.right <= 360
      && l1Section.width > 0,
    formatDetails(l1Section)
  );
  assert(
    '1.6 La bandeja L1 no crea scroll horizontal interno',
    l1Section.found === true && l1Section.scrollWidth <= l1Section.clientWidth + 1,
    formatDetails({ scrollWidth: l1Section.scrollWidth, clientWidth: l1Section.clientWidth })
  );

  const l1Card = await measureElement(l1, '[data-testid="restocker-l1-incident-card"]');
  assert(
    '1.7 La tarjeta L1 cabe entre 0 y 360 px',
    l1Card.found === true
      && l1Card.left >= 0
      && l1Card.right <= 360
      && l1Card.width > 0,
    formatDetails(l1Card)
  );
  assert(
    '1.8 La tarjeta L1 no crea scroll horizontal interno',
    l1Card.found === true && l1Card.scrollWidth <= l1Card.clientWidth + 1,
    formatDetails({ scrollWidth: l1Card.scrollWidth, clientWidth: l1Card.clientWidth })
  );

  const l1Coincidence = await measureElement(l1, '[data-testid="restocker-site-coincidence"]');
  assert(
    '1.9 La coincidencia en sede cabe a 360 px sin scroll interno',
    l1Coincidence.found === true
      && l1Coincidence.left >= 0
      && l1Coincidence.right <= 360
      && l1Coincidence.scrollWidth <= l1Coincidence.clientWidth + 1,
    formatDetails(l1Coincidence)
  );

  const l1ActionVisible = await scrollIntoView(l1, '[data-testid="btn-escalate-l1-incident"]');
  const l1Action = await measureElement(l1, '[data-testid="btn-escalate-l1-incident"]');
  assert(
    '1.10 La acción de escalado es alcanzable con scroll vertical y cabe en el viewport',
    l1ActionVisible === true
      && l1Action.found === true
      && l1Action.left >= 0
      && l1Action.right <= 360
      && l1Action.top >= 0
      && l1Action.bottom <= 800,
    formatDetails(l1Action)
  );

  const l1AfterScroll = await measureDocument(l1);
  assert(
    '1.11 Tras desplazarse a la tarjeta, el documento sigue midiendo 360 px o menos',
    l1AfterScroll.documentScrollWidth <= 360 && l1AfterScroll.bodyScrollWidth <= 360,
    formatDetails({ documentScrollWidth: l1AfterScroll.documentScrollWidth, bodyScrollWidth: l1AfterScroll.bodyScrollWidth })
  );

  await l1.close();

  // ───────────────────────────────────────────────────────────────────────
  // 6. TechnicianRouteView: parada escalada, nota interna, foto y sede
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 2. Tarjeta SAT de una parada escalada ---');
  const sat = await openSurface(cdp, 'sat');
  const satReady = await waitForSelector(sat, '[data-testid="tech-escalated-by-restocker"]');
  assert('2.1 La tarjeta SAT escalada se monta con datos reales', satReady === true);

  const satDocument = await measureDocument(sat);
  assert(
    '2.2 El viewport real es 360 px y el documento no se ensancha',
    satDocument.viewportWidth === 360
      && satDocument.clientWidth === 360
      && satDocument.documentScrollWidth <= 360,
    formatDetails(satDocument)
  );
  assert(
    '2.3 El body tampoco genera scroll horizontal',
    satDocument.bodyScrollWidth <= 360,
    `bodyScrollWidth=${satDocument.bodyScrollWidth}`
  );
  assert(
    '2.4 Ningún elemento visible del documento se sale del viewport',
    satDocument.offenders.length === 0,
    formatDetails(satDocument.offenders)
  );

  const satEscalated = await measureElement(sat, '[data-testid="tech-escalated-by-restocker"]');
  assert(
    '2.5 La insignia de escalado cabe a 360 px',
    satEscalated.found === true
      && satEscalated.left >= 0
      && satEscalated.right <= 360
      && satEscalated.width > 0,
    formatDetails(satEscalated)
  );

  const satNote = await measureElement(sat, '[data-testid="tech-workshop-internal-note"]');
  assert(
    '2.6 La Nota Interna de Taller cabe entre 0 y 360 px',
    satNote.found === true
      && satNote.left >= 0
      && satNote.right <= 360
      && satNote.width > 0
      && satNote.textLength > 20,
    formatDetails(satNote)
  );
  assert(
    '2.7 La Nota Interna de Taller no crea scroll horizontal interno',
    satNote.found === true && satNote.scrollWidth <= satNote.clientWidth + 1,
    formatDetails({ scrollWidth: satNote.scrollWidth, clientWidth: satNote.clientWidth })
  );

  const satPhoto = await measureElement(sat, '[data-testid="tech-workshop-internal-photo"]');
  assert(
    '2.8 La foto del escalado se renderiza y cabe a 360 px',
    satPhoto.found === true
      && satPhoto.width > 0
      && satPhoto.left >= 0
      && satPhoto.right <= 360,
    formatDetails(satPhoto)
  );

  const satCoincidence = await measureElement(sat, '[data-testid="tech-site-coincidence"]');
  assert(
    '2.9 La coincidencia en sede de la tarjeta SAT cabe sin scroll interno',
    satCoincidence.found === true
      && satCoincidence.left >= 0
      && satCoincidence.right <= 360
      && satCoincidence.scrollWidth <= satCoincidence.clientWidth + 1,
    formatDetails(satCoincidence)
  );

  const satCommentsVisible = await scrollIntoView(sat, '[data-testid="btn-stop-comments"]');
  const satComments = await measureElement(sat, '[data-testid="btn-stop-comments"]');
  assert(
    '2.10 El acceso a la conversación es alcanzable y cabe en el viewport',
    satCommentsVisible === true
      && satComments.found === true
      && satComments.left >= 0
      && satComments.right <= 360
      && satComments.top >= 0
      && satComments.bottom <= 800,
    formatDetails(satComments)
  );

  const satAfterScroll = await measureDocument(sat);
  assert(
    '2.11 Tras desplazarse a la conversación, el documento sigue midiendo 360 px o menos',
    satAfterScroll.documentScrollWidth <= 360 && satAfterScroll.bodyScrollWidth <= 360,
    formatDetails({ documentScrollWidth: satAfterScroll.documentScrollWidth, bodyScrollWidth: satAfterScroll.bodyScrollWidth })
  );

  await sat.close();
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

  try {
    rmSync(profileDirectory, { recursive: true, force: true });
  } catch {
    // Windows puede retener el perfil temporal; no altera la medición.
  }
}

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. AVISOS L1 Y TARJETA SAT CERTIFICADOS A 360 PX.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.log(' RESULTADO: FALLO EN EL LAYOUT MÓVIL DE L1/SAT.');
console.log('======================================================================\n');
process.exit(1);
