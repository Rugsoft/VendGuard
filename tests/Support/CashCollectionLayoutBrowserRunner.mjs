/**
 * VendGuard - Verificación real a 360 px de las superficies de recaudación (FIX-35-02)
 *
 * Monta los componentes reales (`RestockerRouteView` y
 * `CoordinatorProductLoadingTab`) con los estilos de producción y mide, en un
 * Chromium real, las cuatro superficies móviles del módulo 35:
 *
 *  1. Modal rápido de recaudación / cambio.
 *  2. Bloque de recaudación integrada en la visita.
 *  3. Historial «Mis Bolsas Precintadas y Cambios de Ruta».
 *  4. Recibo de conserjería.
 *
 * Además comprueba la sub-vista de recaudación de Coordinación, que conserva
 * una tabla con scroll horizontal interno y no debe arrastrar al documento.
 *
 * La medición no usa dobles de layout: el documento se sirve a 360 px, con la
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
  restocker: join(SUPPORT_ROOT, 'cash_collection_layout_restocker.html'),
  coordinator: join(SUPPORT_ROOT, 'cash_collection_layout_coordinator.html')
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
console.log(' VENDGUARD · Layout móvil 360 px de recaudación (FIX-35-02)');
console.log(` Navegador: ${browserPath}`);
console.log(' Categoría: new_engine (canal propio, fuera de la batería hermética)');
console.log('======================================================================\n');

// ─────────────────────────────────────────────────────────────────────────
// 3. Lanzar Chromium y conectar por DevTools
// ─────────────────────────────────────────────────────────────────────────

const profileDirectory = mkdtempSync(join(tmpdir(), 'vendguard-cash-layout-'));

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
    throw new Error(`El arnés ${view} no terminó de cargar.`);
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
      overflowX: getComputedStyle(element).overflowX
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
let cleanupProfile = true;

try {
  cdp = await connectDevTools(devToolsUrl);

  // ───────────────────────────────────────────────────────────────────────
  // 5. RestockerRouteView: historial, modal rápido, visita y recibo
  // ───────────────────────────────────────────────────────────────────────

  const restocker = await openSurface(cdp, 'restocker');

  console.log('--- 1. Historial de bolsas precintadas y cambios de ruta ---');
  const restockerDocument = await measureDocument(restocker);
  assert(
    '1.1 El viewport real es 360 px y el documento no se ensancha',
    restockerDocument.viewportWidth === 360
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

  const history = await measureElement(restocker, '[data-testid="restocker-cash-history"]');
  assert(
    '1.4 El historial está montado y cabe entre 0 y 360 px',
    history.found === true && history.left >= 0 && history.right <= 360 && history.width > 0,
    formatDetails(history)
  );
  assert(
    '1.5 El historial no crea scroll horizontal interno',
    history.found === true && history.scrollWidth <= history.clientWidth + 1,
    formatDetails({ scrollWidth: history.scrollWidth, clientWidth: history.clientWidth })
  );

  const historyItem = await measureElement(restocker, '[data-testid="restocker-cash-collection-item"]');
  assert(
    '1.6 La tarjeta de una bolsa precintada también cabe a 360 px',
    historyItem.found === true && historyItem.left >= 0 && historyItem.right <= 360,
    formatDetails(historyItem)
  );

  console.log('\n--- 2. Modal rápido de recaudación / cambio ---');
  await restocker.evaluate('window.__harness.openCash()');
  const cashModalReady = await waitForSelector(restocker, '[data-testid="modal-cash-collection"]');
  assert('2.1 El modal rápido se abre', cashModalReady === true);

  const cashDialog = await measureDialog(restocker, '[data-testid="modal-cash-collection"]');
  assert(
    '2.2 El diálogo cabe en el viewport sin desplazarse en horizontal',
    cashDialog.found === true
      && cashDialog.dialog !== null
      && cashDialog.dialog.left >= 0
      && cashDialog.dialog.right <= 360
      && cashDialog.dialog.width <= 360
      && cashDialog.dialog.scrollWidth <= cashDialog.dialog.clientWidth + 1,
    formatDetails(cashDialog)
  );
  assert(
    '2.3 Ningún control del modal se sale del viewport',
    cashDialog.found === true && cashDialog.offenders.length === 0,
    formatDetails(cashDialog.offenders)
  );

  const cashActionVisible = await scrollIntoView(restocker, '[data-testid="btn-save-cash-collection"]');
  const cashAction = await measureElement(restocker, '[data-testid="btn-save-cash-collection"]');
  assert(
    '2.4 La acción principal es alcanzable con scroll vertical y queda dentro del viewport',
    cashActionVisible === true
      && cashAction.found === true
      && cashAction.left >= 0
      && cashAction.right <= 360
      && cashAction.top >= 0
      && cashAction.bottom <= 800,
    formatDetails(cashAction)
  );

  const cashDocument = await measureDocument(restocker);
  assert(
    '2.5 Con el modal abierto el documento sigue midiendo 360 px o menos',
    cashDocument.documentScrollWidth <= 360 && cashDocument.bodyScrollWidth <= 360,
    formatDetails({ documentScrollWidth: cashDocument.documentScrollWidth, bodyScrollWidth: cashDocument.bodyScrollWidth })
  );

  console.log('\n--- 3. Recaudación integrada en la visita ---');
  await restocker.evaluate('window.__harness.closeAll()');
  await restocker.evaluate('window.__harness.openVisit()');
  const visitModalReady = await waitForSelector(restocker, '[data-testid="modal-restock-visit"]');
  const visitBlockReady = await waitForSelector(restocker, '[data-testid="visit-cash-block"]');
  assert(
    '3.1 La visita monta el bloque de recaudación integrada',
    visitModalReady === true && visitBlockReady === true
  );

  const visitDialog = await measureDialog(restocker, '[data-testid="modal-restock-visit"]');
  assert(
    '3.2 El asistente de visita no tiene scroll horizontal',
    visitDialog.found === true
      && visitDialog.dialog !== null
      && visitDialog.dialog.left >= 0
      && visitDialog.dialog.right <= 360
      && visitDialog.dialog.scrollWidth <= visitDialog.dialog.clientWidth + 1,
    formatDetails(visitDialog)
  );

  const visitBlockVisible = await scrollIntoView(restocker, '[data-testid="visit-cash-block"]');
  const visitBlock = await measureElement(restocker, '[data-testid="visit-cash-block"]');
  assert(
    '3.3 El bloque de recaudación se alcanza y cabe a 360 px',
    visitBlockVisible === true
      && visitBlock.found === true
      && visitBlock.left >= 0
      && visitBlock.right <= 360
      && visitBlock.top >= 0
      && visitBlock.bottom <= 800,
    formatDetails(visitBlock)
  );
  assert(
    '3.4 Ningún control del asistente de visita se sale del viewport',
    visitDialog.found === true && visitDialog.offenders.length === 0,
    formatDetails(visitDialog.offenders)
  );

  const visitDocument = await measureDocument(restocker);
  assert(
    '3.5 Con la visita abierta el documento no se ensancha',
    visitDocument.documentScrollWidth <= 360 && visitDocument.bodyScrollWidth <= 360,
    formatDetails({ documentScrollWidth: visitDocument.documentScrollWidth, bodyScrollWidth: visitDocument.bodyScrollWidth })
  );

  console.log('\n--- 4. Recibo de conserjería ---');
  await restocker.evaluate('window.__harness.closeAll()');
  await restocker.evaluate('window.__harness.openReceipt()');
  const receiptReady = await waitForSelector(restocker, '[data-testid="cash-reception-receipt-modal"]');
  assert('4.1 El recibo de conserjería se abre', receiptReady === true);

  const receiptDialog = await measureDialog(restocker, '[data-testid="cash-reception-receipt-modal"]');
  assert(
    '4.2 El recibo cabe sin scroll horizontal',
    receiptDialog.found === true
      && receiptDialog.dialog !== null
      && receiptDialog.dialog.left >= 0
      && receiptDialog.dialog.right <= 360
      && receiptDialog.dialog.scrollWidth <= receiptDialog.dialog.clientWidth + 1,
    formatDetails(receiptDialog)
  );
  assert(
    '4.3 Ningún campo del recibo se sale del viewport',
    receiptDialog.found === true && receiptDialog.offenders.length === 0,
    formatDetails(receiptDialog.offenders)
  );

  const receiptRows = await restocker.evaluate(`(() => {
    const modal = document.querySelector('[data-testid="cash-reception-receipt-modal"]');
    if (modal === null) {
      return { count: 0, bad: [] };
    }
    const rows = [...modal.querySelectorAll('div[style*="justify-content: space-between"]')];
    const bad = rows
      .map((row) => {
        const rect = row.getBoundingClientRect();
        return {
          text: (row.textContent ?? '').trim().slice(0, 40),
          left: Number(rect.left.toFixed(1)),
          right: Number(rect.right.toFixed(1))
        };
      })
      .filter((row) => row.left < -0.5 || row.right > 360.5);
    return { count: rows.length, bad };
  })()`);
  assert(
    '4.4 Los campos del recibo conservan sus filas completas dentro de 360 px',
    receiptRows.count >= 8 && receiptRows.bad.length === 0,
    formatDetails(receiptRows)
  );

  const receiptPrintVisible = await scrollIntoView(restocker, '[data-testid="btn-print-cash-receipt"]');
  const receiptPrint = await measureElement(restocker, '[data-testid="btn-print-cash-receipt"]');
  assert(
    '4.5 La impresión del recibo es alcanzable y cabe en el viewport',
    receiptPrintVisible === true
      && receiptPrint.found === true
      && receiptPrint.left >= 0
      && receiptPrint.right <= 360
      && receiptPrint.top >= 0
      && receiptPrint.bottom <= 800,
    formatDetails(receiptPrint)
  );

  const receiptDocument = await measureDocument(restocker);
  assert(
    '4.6 Con el recibo abierto el documento no se ensancha',
    receiptDocument.documentScrollWidth <= 360 && receiptDocument.bodyScrollWidth <= 360,
    formatDetails({ documentScrollWidth: receiptDocument.documentScrollWidth, bodyScrollWidth: receiptDocument.bodyScrollWidth })
  );

  await restocker.close();

  // ───────────────────────────────────────────────────────────────────────
  // 6. Coordinación: tabla con scroll interno y modal de detalle
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 5. Sub-vista de recaudación de Coordinación ---');
  const coordinator = await openSurface(cdp, 'coordinator');
  const coordinatorDocument = await measureDocument(coordinator);
  assert(
    '5.1 La página de Coordinación no se ensancha a 360 px',
    coordinatorDocument.viewportWidth === 360
      && coordinatorDocument.documentScrollWidth <= 360
      && coordinatorDocument.bodyScrollWidth <= 360,
    formatDetails(coordinatorDocument)
  );
  assert(
    '5.2 Ningún elemento visible fuera de la tabla se sale del viewport',
    coordinatorDocument.offenders.length === 0,
    formatDetails(coordinatorDocument.offenders)
  );

  const coordinatorSection = await measureElement(coordinator, '[data-testid="product-loading-cash-section"]');
  assert(
    '5.3 La sección de recaudación ocupa el ancho móvil sin desbordarse',
    coordinatorSection.found === true
      && coordinatorSection.left >= 0
      && coordinatorSection.right <= 360,
    formatDetails(coordinatorSection)
  );

  const tableWrap = await coordinator.evaluate(`(() => {
    const table = document.querySelector('[data-testid="product-loading-cash-table"]');
    const wrapper = table?.parentElement;
    if (wrapper === undefined || wrapper === null) {
      return { found: false };
    }
    return {
      found: true,
      clientWidth: wrapper.clientWidth,
      scrollWidth: wrapper.scrollWidth,
      overflowX: getComputedStyle(wrapper).overflowX,
      left: Number(wrapper.getBoundingClientRect().left.toFixed(1)),
      right: Number(wrapper.getBoundingClientRect().right.toFixed(1))
    };
  })()`);
  assert(
    '5.4 La tabla ancha queda contenida en un contenedor con scroll horizontal propio',
    tableWrap.found === true
      && tableWrap.left >= 0
      && tableWrap.right <= 360
      && tableWrap.scrollWidth > tableWrap.clientWidth
      && tableWrap.overflowX === 'auto',
    formatDetails(tableWrap)
  );
  assert(
    '5.5 La tabla no arrastra el scroll horizontal al documento',
    coordinatorDocument.documentScrollWidth <= 360,
    formatDetails({ documentScrollWidth: coordinatorDocument.documentScrollWidth, wrap: tableWrap })
  );

  console.log('\n--- 6. Modal de detalle de Coordinación ---');
  await coordinator.evaluate('window.__harness.openDetail()');
  const detailReady = await waitForSelector(coordinator, '.vg-modal');
  assert('6.1 El detalle de la bolsa se abre', detailReady === true);

  const detailDialog = await measureDialog(coordinator, '.vg-modal-backdrop');
  const detailFallback = detailDialog.found ? detailDialog : await measureDialog(coordinator, '.vg-modal');
  assert(
    '6.2 El detalle cabe en 360 px y no crea scroll horizontal',
    detailFallback.found === true
      && detailFallback.dialog !== null
      && detailFallback.dialog.left >= 0
      && detailFallback.dialog.right <= 360
      && detailFallback.dialog.scrollWidth <= detailFallback.dialog.clientWidth + 1,
    formatDetails(detailFallback)
  );
  assert(
    '6.3 Ningún control del detalle se sale del viewport',
    detailFallback.found === true && detailFallback.offenders.length === 0,
    formatDetails(detailFallback.offenders)
  );

  const detailFooter = await coordinator.evaluate(`(() => {
    const modal = document.querySelector('.vg-modal');
    if (modal === null) {
      return { found: false, buttons: [] };
    }
    const buttons = [...modal.querySelectorAll('button')]
      .map((button) => {
        const rect = button.getBoundingClientRect();
        return {
          text: (button.textContent ?? '').trim().slice(0, 30),
          left: Number(rect.left.toFixed(1)),
          right: Number(rect.right.toFixed(1)),
          bottom: Number(rect.bottom.toFixed(1))
        };
      })
      .filter((button) => button.right > 0 && button.bottom > 0);
    return { found: true, buttons };
  })()`);
  assert(
    '6.4 Los botones del detalle quedan dentro del ancho móvil',
    detailFooter.found === true
      && detailFooter.buttons.length >= 3
      && detailFooter.buttons.every((button) => button.left >= -0.5 && button.right <= 360.5),
    formatDetails(detailFooter)
  );

  const coordinatorDetailDocument = await measureDocument(coordinator);
  assert(
    '6.5 Con el detalle abierto el documento sigue sin scroll horizontal',
    coordinatorDetailDocument.documentScrollWidth <= 360 && coordinatorDetailDocument.bodyScrollWidth <= 360,
    formatDetails({ documentScrollWidth: coordinatorDetailDocument.documentScrollWidth, bodyScrollWidth: coordinatorDetailDocument.bodyScrollWidth })
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
  console.log(' RESULTADO: 100% EN VERDE. LAYOUT DE RECAUDACIÓN CERTIFICADO A 360 PX.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.log(' RESULTADO: FALLO EN EL LAYOUT MÓVIL DE RECAUDACIÓN.');
console.log('======================================================================\n');
process.exit(1);
