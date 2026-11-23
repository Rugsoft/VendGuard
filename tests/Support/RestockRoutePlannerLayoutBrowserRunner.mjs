/**
 * VendGuard - Verificación real a 360 x 800 de las superficies M37 (AUD-37-04)
 *
 * Monta los componentes reales de la hoja móvil y de la consola de Coordinación
 * con los estilos de producción. Mide, en un Chromium real, el documento y cada
 * superficie del Planificador de Rutas Periódicas:
 *
 *  1. Hoja móvil con tres bloques, badges, acordeones y acciones.
 *  2. Modal de mapa con origen, pines y polilínea.
 *  3. Mapa sin GPS válido y estado vacío sin paradas.
 *  4. Modal de omisión con un motivo largo.
 *  5. Consola de Coordinación con tablas anchas y scroll interno.
 *
 * La suite no usa dobles de layout: el documento se sirve a 360 x 800, con la
 * barra de scroll oculta para emular un viewport móvil, y se inspeccionan los
 * rectángulos reales. Si no hay navegador o Node 22+, sale con código 2 para
 * que el canal lo declare no ejecutable en lugar de fingir un verde.
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
  restocker: join(SUPPORT_ROOT, 'restock_route_planner_layout_restocker.html'),
  coordinator: join(SUPPORT_ROOT, 'restock_route_planner_layout_coordinator.html')
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

  if (url.pathname === '/restocker.html' || url.pathname === '/coordinator.html') {
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
console.log(' VENDGUARD · Layout móvil 360 x 800 de las superficies M37 (AUD-37-04)');
console.log(` Navegador: ${browserPath}`);
console.log(' Categoría: new_engine (canal propio, fuera de la batería hermética)');
console.log('======================================================================\n');

// ─────────────────────────────────────────────────────────────────────────
// 3. Lanzar Chromium y conectar por DevTools
// ─────────────────────────────────────────────────────────────────────────

const profileDirectory = mkdtempSync(join(tmpdir(), 'vendguard-route-planner-layout-'));

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

  // El mapa intenta cargar teselas de OpenStreetMap. La certificación es
  // offline: se bloquean para que la medición no dependa de la red.
  try {
    await send('Network.enable');
    await send('Network.setBlockedURLs', {
      urls: ['*://*.tile.openstreetmap.org/*', '*://tile.openstreetmap.org/*']
    });
  } catch {
    // La red no es necesaria para medir el layout.
  }

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
      text: (element.textContent ?? '').trim().slice(0, 160),
      value: 'value' in element ? String(element.value).slice(0, 600) : null,
      disabled: 'disabled' in element ? Boolean(element.disabled) : null
    };
  })()`);
}

async function measureDialog(surface, modalSelector) {
  return surface.evaluate(`(() => {
    const viewportWidth = window.innerWidth;
    const modal = document.querySelector(${JSON.stringify(modalSelector)});
    if (modal === null) {
      return { found: false, dialog: null, offenders: [] };
    }

    const dialog = modal.firstElementChild;
    if (dialog === null) {
      return { found: true, dialog: null, offenders: [] };
    }

    const rect = dialog.getBoundingClientRect();
    const offenders = [...dialog.querySelectorAll('*')]
      .filter((element) => {
        const childRect = element.getBoundingClientRect();
        return childRect.width > 0.5
          && childRect.height > 0.5
          && (childRect.right > viewportWidth + 0.5 || childRect.left < -0.5);
      })
      .slice(0, 12)
      .map((element) => {
        const childRect = element.getBoundingClientRect();
        return {
          tag: element.tagName,
          testid: element.getAttribute('data-testid'),
          left: Number(childRect.left.toFixed(1)),
          right: Number(childRect.right.toFixed(1)),
          width: Number(childRect.width.toFixed(1))
        };
      });

    return {
      found: true,
      dialog: {
        left: Number(rect.left.toFixed(1)),
        top: Number(rect.top.toFixed(1)),
        right: Number(rect.right.toFixed(1)),
        bottom: Number(rect.bottom.toFixed(1)),
        width: Number(rect.width.toFixed(1)),
        height: Number(rect.height.toFixed(1)),
        scrollWidth: dialog.scrollWidth,
        clientWidth: dialog.clientWidth,
        scrollHeight: dialog.scrollHeight,
        clientHeight: dialog.clientHeight,
        overflowX: getComputedStyle(dialog).overflowX
      },
      offenders
    };
  })()`);
}

async function measureTable(surface, selector) {
  return surface.evaluate(`(() => {
    const table = document.querySelector(${JSON.stringify(selector)});
    if (table === null) {
      return { found: false };
    }

    const wrapper = table.parentElement;
    if (wrapper === null) {
      return { found: true, wrapper: null };
    }

    const tableRect = table.getBoundingClientRect();
    const wrapperRect = wrapper.getBoundingClientRect();
    return {
      found: true,
      table: {
        left: Number(tableRect.left.toFixed(1)),
        right: Number(tableRect.right.toFixed(1)),
        width: Number(tableRect.width.toFixed(1)),
        scrollWidth: table.scrollWidth
      },
      wrapper: {
        left: Number(wrapperRect.left.toFixed(1)),
        right: Number(wrapperRect.right.toFixed(1)),
        width: Number(wrapperRect.width.toFixed(1)),
        clientWidth: wrapper.clientWidth,
        scrollWidth: wrapper.scrollWidth,
        overflowX: getComputedStyle(wrapper).overflowX
      }
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

async function waitForCondition(surface, expression, timeoutMs = 5000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const value = await surface.evaluate(expression);
    if (value === true) {
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
let cleanupProfile = true;

try {
  cdp = await connectDevTools(devToolsUrl);

  // ───────────────────────────────────────────────────────────────────────
  // 5. Hoja móvil del reponedor
  // ───────────────────────────────────────────────────────────────────────

  const restocker = await openSurface(cdp, 'restocker');

  console.log('--- 1. Hoja móvil: tres bloques, badges, acordeones y acciones ---');
  const restockerDocument = await measureDocument(restocker);
  assert(
    '1.1 El viewport real es 360 x 800 y el documento no se ensancha',
    restockerDocument.viewportWidth === 360
      && restockerDocument.viewportHeight === 800
      && restockerDocument.clientWidth === 360
      && restockerDocument.documentScrollWidth <= 360,
    formatDetails(restockerDocument)
  );
  assert(
    '1.2 El body tampoco genera scroll horizontal',
    restockerDocument.bodyScrollWidth <= 360,
    `bodyScrollWidth=${restockerDocument.bodyScrollWidth}`
  );
  assert(
    '1.3 Ningún elemento visible del documento se sale del viewport',
    restockerDocument.offenders.length === 0,
    formatDetails(restockerDocument.offenders)
  );

  const routeBase = await measureElement(restocker, '[data-testid="restocker-route-base"]');
  assert(
    '1.4 La hoja de ruta está montada y cabe entre 0 y 360 px',
    routeBase.found === true && routeBase.left >= 0 && routeBase.right <= 360 && routeBase.width > 0,
    formatDetails(routeBase)
  );

  const blocks = await restocker.evaluate(`(() => [...document.querySelectorAll('[data-testid="restocker-route-block"]')]
    .map((element) => {
      const rect = element.getBoundingClientRect();
      return {
        block: element.getAttribute('data-block'),
        left: Number(rect.left.toFixed(1)),
        right: Number(rect.right.toFixed(1)),
        width: Number(rect.width.toFixed(1))
      };
    }))()`);
  assert(
    '1.5 Los tres bloques FEFO, demanda y cadencia están presentes y contenidos',
    blocks.length === 3
      && blocks.map((block) => block.block).join('|') === 'FEFO_SANITARY|DEMAND_L1_STOCKOUT|SCHEDULED_CADENCE'
      && blocks.every((block) => block.left >= 0 && block.right <= 360 && block.width > 0),
    formatDetails(blocks)
  );

  const stops = await restocker.evaluate(`(() => [...document.querySelectorAll('[data-testid="restocker-route-stop"]')]
    .map((element) => {
      const rect = element.getBoundingClientRect();
      return {
        order: element.getAttribute('data-stop-order'),
        left: Number(rect.left.toFixed(1)),
        right: Number(rect.right.toFixed(1)),
        width: Number(rect.width.toFixed(1))
      };
    }))()`);
  assert(
    '1.6 Las tres paradas pendientes caben sin desbordarse',
    stops.length === 3 && stops.every((stop) => stop.left >= 0 && stop.right <= 360 && stop.width > 0),
    formatDetails(stops)
  );

  const summaryBadge = await measureElement(restocker, '[data-testid="route-summary-stops"]');
  assert(
    '1.7 El badge de paradas conserva su texto y cabe a 360 px',
    summaryBadge.found === true
      && summaryBadge.text.includes('3 paradas')
      && summaryBadge.left >= 0
      && summaryBadge.right <= 360,
    formatDetails(summaryBadge)
  );

  const routeActions = await restocker.evaluate(`(() => [...document.querySelectorAll('[data-testid="btn-route-start-visit"], [data-testid="btn-route-skip-stop"]')]
    .map((element) => {
      const rect = element.getBoundingClientRect();
      return {
        testid: element.getAttribute('data-testid'),
        left: Number(rect.left.toFixed(1)),
        right: Number(rect.right.toFixed(1)),
        width: Number(rect.width.toFixed(1))
      };
    }))()`);
  assert(
    '1.8 Las acciones Visitar y Omitir de las cuatro máquinas caben en el ancho móvil',
    routeActions.length === 8
      && routeActions.every((action) => action.left >= 0 && action.right <= 360 && action.width > 0),
    formatDetails(routeActions)
  );

  const accordions = await restocker.evaluate(`(() => [...document.querySelectorAll('[data-testid="restocker-route-completed"], [data-testid="restocker-route-skipped"], [data-testid="restocker-route-blocked"]')]
    .map((element) => {
      const rect = element.getBoundingClientRect();
      return {
        testid: element.getAttribute('data-testid'),
        left: Number(rect.left.toFixed(1)),
        right: Number(rect.right.toFixed(1))
      };
    }))()`);
  assert(
    '1.9 Los tres acordeones inferiores están montados y contenidos',
    accordions.length === 3 && accordions.every((item) => item.left >= 0 && item.right <= 360),
    formatDetails(accordions)
  );

  await restocker.evaluate(`(() => {
    document.querySelectorAll('[data-testid="restocker-route-completed"], [data-testid="restocker-route-skipped"], [data-testid="restocker-route-blocked"]').forEach((details) => {
      details.open = true;
    });
  })()`);
  const accordionDocument = await measureDocument(restocker);
  assert(
    '1.10 Al abrir los acordeones el documento sigue midiendo 360 px o menos',
    accordionDocument.documentScrollWidth <= 360 && accordionDocument.bodyScrollWidth <= 360,
    formatDetails({ documentScrollWidth: accordionDocument.documentScrollWidth, bodyScrollWidth: accordionDocument.bodyScrollWidth })
  );

  // ───────────────────────────────────────────────────────────────────────
  // 6. Modal de mapa con origen, pines y polilínea
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 2. Modal de mapa: origen, pines y polilínea ---');
  await restocker.evaluate('window.__harness.openMap()');
  const mapReady = await waitForSelector(restocker, '[data-testid="restocker-route-map-modal"]');
  assert('2.1 El modal de mapa se abre', mapReady === true);

  const mapDialog = await measureDialog(restocker, '[data-testid="restocker-route-map-modal"]');
  assert(
    '2.2 El diálogo del mapa cabe en 360 px sin scroll horizontal',
    mapDialog.found === true
      && mapDialog.dialog !== null
      && mapDialog.dialog.left >= 0
      && mapDialog.dialog.right <= 360
      && mapDialog.dialog.width <= 360
      && mapDialog.dialog.scrollWidth <= mapDialog.dialog.clientWidth + 1,
    formatDetails(mapDialog)
  );
  assert(
    '2.3 Ningún control del mapa se sale del viewport',
    mapDialog.found === true && mapDialog.offenders.length === 0,
    formatDetails(mapDialog.offenders)
  );

  const mapCanvas = await measureElement(restocker, '[data-testid="restocker-route-map-canvas"]');
  assert(
    '2.4 El lienzo Web Mercator ocupa el ancho móvil y conserva su proporción',
    mapCanvas.found === true
      && mapCanvas.left >= 0
      && mapCanvas.right <= 360
      && mapCanvas.width > 0
      && mapCanvas.height > 0
      && Math.abs(mapCanvas.width - mapCanvas.height) <= 2,
    formatDetails(mapCanvas)
  );

  const mapShapes = await restocker.evaluate(`(() => ({
    origin: document.querySelectorAll('g.route-map-origin').length,
    polyline: document.querySelectorAll('polyline.route-map-line').length,
    markers: document.querySelectorAll('g.route-map-marker').length,
    stopCards: document.querySelectorAll('[data-testid="restocker-route-map-stop"]').length
  }))()`);
  assert(
    '2.5 El mapa pinta origen, polilínea y dos pines para las paradas con GPS',
    mapShapes.origin === 1 && mapShapes.polyline === 1 && mapShapes.markers === 2 && mapShapes.stopCards === 3,
    formatDetails(mapShapes)
  );

  const mapStopCards = await restocker.evaluate(`(() => [...document.querySelectorAll('[data-testid="restocker-route-map-stop"]')]
    .map((element) => {
      const rect = element.getBoundingClientRect();
      return {
        left: Number(rect.left.toFixed(1)),
        right: Number(rect.right.toFixed(1)),
        width: Number(rect.width.toFixed(1))
      };
    }))()`);
  assert(
    '2.6 Las tres tarjetas del mapa caben en el ancho móvil',
    mapStopCards.length === 3 && mapStopCards.every((card) => card.left >= 0 && card.right <= 360 && card.width > 0),
    formatDetails(mapStopCards)
  );

  const fullNavigation = await measureElement(restocker, '[data-testid="btn-route-map-full-navigation"]');
  assert(
    '2.7 La acción de ruta completa está visible, habilitada y contenida',
    fullNavigation.found === true
      && fullNavigation.disabled === false
      && fullNavigation.left >= 0
      && fullNavigation.right <= 360,
    formatDetails(fullNavigation)
  );

  const mapDocument = await measureDocument(restocker);
  assert(
    '2.8 Con el mapa abierto el documento sigue midiendo 360 px o menos',
    mapDocument.documentScrollWidth <= 360 && mapDocument.bodyScrollWidth <= 360,
    formatDetails({ documentScrollWidth: mapDocument.documentScrollWidth, bodyScrollWidth: mapDocument.bodyScrollWidth })
  );

  // ───────────────────────────────────────────────────────────────────────
  // 7. Mapa sin GPS válido y estado vacío
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 3. Mapa sin GPS válido y estado vacío ---');
  await restocker.evaluate('window.__harness.closeAll()');
  await restocker.evaluate('window.__harness.openNoGpsMap()');
  const noGpsReady = await waitForCondition(
    restocker,
    `document.querySelector('[data-testid="restocker-route-map-modal"]') !== null
      && document.querySelectorAll('g.route-map-marker').length === 0
      && document.querySelectorAll('[data-testid="restocker-route-map-stop"]').length === 2`
  );
  assert('3.1 El mapa sin GPS se abre y conserva las paradas textuales', noGpsReady === true);

  const noGpsDialog = await measureDialog(restocker, '[data-testid="restocker-route-map-modal"]');
  const noGpsCanvas = await measureElement(restocker, '[data-testid="restocker-route-map-canvas"]');
  const noGpsState = await restocker.evaluate(`(() => ({
    markers: document.querySelectorAll('g.route-map-marker').length,
    stopCards: document.querySelectorAll('[data-testid="restocker-route-map-stop"]').length,
    hasNoGpsLabel: (document.body.textContent ?? '').includes('Sin GPS')
  }))()`);
  assert(
    '3.2 Sin coordenadas válidas no hay pines y cada parada muestra Sin GPS',
    noGpsState.markers === 0 && noGpsState.stopCards === 2 && noGpsState.hasNoGpsLabel === true,
    formatDetails(noGpsState)
  );
  assert(
    '3.3 El mapa sin GPS cabe sin scroll horizontal',
    noGpsDialog.found === true
      && noGpsDialog.dialog !== null
      && noGpsDialog.dialog.left >= 0
      && noGpsDialog.dialog.right <= 360
      && noGpsDialog.dialog.scrollWidth <= noGpsDialog.dialog.clientWidth + 1
      && noGpsCanvas.found === true
      && noGpsCanvas.left >= 0
      && noGpsCanvas.right <= 360,
    formatDetails({ noGpsDialog, noGpsCanvas })
  );

  await restocker.evaluate('window.__harness.closeAll()');
  await restocker.evaluate('window.__harness.openEmptyMap()');
  const emptyReady = await waitForCondition(
    restocker,
    `document.querySelector('[data-testid="restocker-route-map-modal"]') !== null
      && (document.body.textContent ?? '').includes('No hay paradas pendientes para hoy.')
      && document.querySelector('[data-testid="restocker-route-map-canvas"]') === null`
  );
  const emptyDialog = await measureDialog(restocker, '[data-testid="restocker-route-map-modal"]');
  assert(
    '3.4 El estado vacío del mapa se muestra sin lienzo y sin desbordarse',
    emptyReady === true
      && emptyDialog.found === true
      && emptyDialog.dialog !== null
      && emptyDialog.dialog.left >= 0
      && emptyDialog.dialog.right <= 360
      && emptyDialog.dialog.scrollWidth <= emptyDialog.dialog.clientWidth + 1,
    formatDetails(emptyDialog)
  );

  const emptyDocument = await measureDocument(restocker);
  assert(
    '3.5 Con el mapa vacío el documento sigue midiendo 360 px o menos',
    emptyDocument.documentScrollWidth <= 360 && emptyDocument.bodyScrollWidth <= 360,
    formatDetails({ documentScrollWidth: emptyDocument.documentScrollWidth, bodyScrollWidth: emptyDocument.bodyScrollWidth })
  );

  // ───────────────────────────────────────────────────────────────────────
  // 8. Modal de omisión con motivo largo
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 4. Modal de omisión con motivo largo ---');
  await restocker.evaluate('window.__harness.closeAll()');
  await restocker.evaluate('window.__harness.openSkip()');
  const skipReady = await waitForSelector(restocker, '[data-testid="restocker-route-skip-modal"]');
  assert('4.1 El modal de omisión se abre', skipReady === true);

  const skipDialog = await measureDialog(restocker, '[data-testid="restocker-route-skip-modal"]');
  assert(
    '4.2 El diálogo de omisión cabe en 360 px sin scroll horizontal',
    skipDialog.found === true
      && skipDialog.dialog !== null
      && skipDialog.dialog.left >= 0
      && skipDialog.dialog.right <= 360
      && skipDialog.dialog.scrollWidth <= skipDialog.dialog.clientWidth + 1,
    formatDetails(skipDialog)
  );
  assert(
    '4.3 Ningún campo del modal de omisión se sale del viewport',
    skipDialog.found === true && skipDialog.offenders.length === 0,
    formatDetails(skipDialog.offenders)
  );

  const reasonField = await measureElement(restocker, '[data-testid="route-skip-reason"]');
  assert(
    '4.4 El motivo largo se conserva en el textarea y este cabe a 360 px',
    reasonField.found === true
      && reasonField.value !== null
      && reasonField.value.length > 200
      && reasonField.value.length <= 500
      && reasonField.left >= 0
      && reasonField.right <= 360
      && reasonField.scrollWidth <= reasonField.clientWidth + 1,
    formatDetails({ ...reasonField, value: reasonField.value?.length ?? null })
  );

  const skipConfirmVisible = await scrollIntoView(restocker, '[data-testid="btn-confirm-route-skip"]');
  const skipConfirm = await measureElement(restocker, '[data-testid="btn-confirm-route-skip"]');
  assert(
    '4.5 La confirmación es alcanzable con scroll vertical y cabe en el viewport',
    skipConfirmVisible === true
      && skipConfirm.found === true
      && skipConfirm.left >= 0
      && skipConfirm.right <= 360
      && skipConfirm.top >= 0
      && skipConfirm.bottom <= 800,
    formatDetails(skipConfirm)
  );

  const skipDocument = await measureDocument(restocker);
  assert(
    '4.6 Con el modal de omisión abierto el documento sigue midiendo 360 px o menos',
    skipDocument.documentScrollWidth <= 360 && skipDocument.bodyScrollWidth <= 360,
    formatDetails({ documentScrollWidth: skipDocument.documentScrollWidth, bodyScrollWidth: skipDocument.bodyScrollWidth })
  );

  await restocker.close();

  // ───────────────────────────────────────────────────────────────────────
  // 9. Consola de Coordinación con tablas anchas
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 5. Consola de Coordinación y tablas anchas ---');
  const coordinator = await openSurface(cdp, 'coordinator');
  const coordinatorDocument = await measureDocument(coordinator);
  assert(
    '5.1 La consola de Coordinación no se ensancha a 360 px',
    coordinatorDocument.viewportWidth === 360
      && coordinatorDocument.documentScrollWidth <= 360
      && coordinatorDocument.bodyScrollWidth <= 360,
    formatDetails(coordinatorDocument)
  );
  assert(
    '5.2 Ningún elemento visible fuera de las tablas se sale del viewport',
    coordinatorDocument.offenders.length === 0,
    formatDetails(coordinatorDocument.offenders)
  );

  const plannerSection = await measureElement(coordinator, '[data-testid="coordinator-restock-route-planner"]');
  assert(
    '5.3 La consola ocupa el ancho móvil sin desbordarse',
    plannerSection.found === true
      && plannerSection.left >= 0
      && plannerSection.right <= 360
      && plannerSection.width > 0,
    formatDetails(plannerSection)
  );

  const kpis = await coordinator.evaluate(`(() => {
    const container = document.querySelector('[data-testid="route-planner-kpis"]');
    if (container === null) return { count: 0, bad: [] };
    const cards = [...container.children];
    const bad = cards
      .map((card) => {
        const rect = card.getBoundingClientRect();
        return { left: Number(rect.left.toFixed(1)), right: Number(rect.right.toFixed(1)) };
      })
      .filter((card) => card.left < -0.5 || card.right > 360.5);
    return { count: cards.length, bad };
  })()`);
  assert(
    '5.4 Los siete contadores caben en la rejilla móvil',
    kpis.count === 7 && kpis.bad.length === 0,
    formatDetails(kpis)
  );

  const tableSelectors = [
    ['route-planner-uncovered-table', '5.5 La tabla de paradas sin cobertura'],
    ['route-planner-overrides-table', '5.6 La tabla de excepciones vivas'],
    ['route-planner-schedule-table', '5.7 La tabla de agendas periódicas']
  ];

  for (const [testid, label] of tableSelectors) {
    const table = await measureTable(coordinator, `[data-testid="${testid}"]`);
    assert(
      `${label} queda contenida con scroll horizontal propio`,
      table.found === true
        && table.wrapper !== null
        && table.wrapper.left >= 0
        && table.wrapper.right <= 360
        && table.wrapper.overflowX === 'auto'
        && table.wrapper.scrollWidth >= table.wrapper.clientWidth,
      formatDetails(table)
    );
  }

  const routes = await coordinator.evaluate(`(() => [...document.querySelectorAll('[data-testid="route-planner-routes"] .vg-card')]
    .map((element) => {
      const rect = element.getBoundingClientRect();
      return {
        left: Number(rect.left.toFixed(1)),
        right: Number(rect.right.toFixed(1)),
        width: Number(rect.width.toFixed(1))
      };
    }))()`);
  assert(
    '5.8 Las tarjetas de rutas por reponedor caben a 360 px',
    routes.length >= 2 && routes.every((card) => card.left >= 0 && card.right <= 360 && card.width > 0),
    formatDetails(routes)
  );

  const tableScroll = await coordinator.evaluate(`(() => {
    const table = document.querySelector('[data-testid="route-planner-schedule-table"]');
    const wrapper = table?.parentElement;
    if (!wrapper) return { found: false };
    wrapper.scrollLeft = wrapper.scrollWidth;
    return {
      found: true,
      scrollLeft: wrapper.scrollLeft,
      maxScroll: wrapper.scrollWidth - wrapper.clientWidth
    };
  })()`);
  const scrolledDocument = await measureDocument(coordinator);
  assert(
    '5.9 Al desplazar la tabla ancha, el documento no arrastra scroll horizontal',
    tableScroll.found === true
      && tableScroll.maxScroll >= 0
      && scrolledDocument.documentScrollWidth <= 360
      && scrolledDocument.bodyScrollWidth <= 360,
    formatDetails({ tableScroll, scrolledDocument })
  );

  await coordinator.close();
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
      // Windows puede retener el perfil temporal; no altera la medición.
    }
  }
}

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. LAYOUT M37 CERTIFICADO A 360 X 800.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.log(' RESULTADO: FALLO EN EL LAYOUT MÓVIL DE LAS SUPERFICIES M37.');
console.log('======================================================================\n');
process.exit(1);
