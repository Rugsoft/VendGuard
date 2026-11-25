/**
 * VendGuard - Verificación real del layout de la consola EVA-DTS M39
 *
 * Monta `CoordinatorDexAuditsSubTab` con los estilos de producción y mide, en
 * un Chromium real:
 *
 *  1. La consola a 360 x 800: documento, KPIs, filtros, carga manual y tabla.
 *  2. El detalle financiero y sus tablas internas con scroll propio.
 *  3. El modal de anulación con un motivo largo y su acción alcanzable.
 *  4. La misma consola a 1440 px: tabla ancha y modal de detalle contenido.
 *
 * No usa dobles de layout: sirve los componentes reales, fija el viewport y
 * mide rectángulos. Si no hay navegador o Node 22+, sale con código 2 para que
 * el canal lo declare no ejecutable en lugar de fingir un verde.
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
const HARNESS = join(PROJECT_ROOT, 'tests', 'Support', 'dex_audit_layout_browser.html');

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

function playwrightChromiumCandidates() {
  const roots = [];
  if (process.platform === 'win32') {
    if (typeof process.env.LOCALAPPDATA === 'string' && process.env.LOCALAPPDATA !== '') {
      roots.push(join(process.env.LOCALAPPDATA, 'ms-playwright'));
    }
  } else if (process.platform === 'darwin') {
    roots.push(join(homedir(), 'Library', 'Caches', 'ms-playwright'));
  } else {
    roots.push(join(process.env.XDG_CACHE_HOME ?? join(homedir(), '.cache'), 'ms-playwright'));
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
      if (typeof base !== 'string' || base === '') continue;
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
  notExecutable('No se encontró Chrome, Edge ni Chromium. Define VENDGUARD_CHROME_PATH para certificar el layout.');
}
if (typeof WebSocket !== 'function') {
  notExecutable('Este Node no expone `WebSocket` global (requiere Node 22+), necesario para hablar con DevTools.');
}

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

  if (url.pathname === '/dex.html') {
    serveFile(response, HARNESS);
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
console.log(' VENDGUARD · Layout de la consola EVA-DTS .dex (M39)');
console.log(` Navegador: ${browserPath}`);
console.log(' Categoría: new_engine (canal propio, fuera de la batería hermética)');
console.log('======================================================================\n');

const profileDirectory = mkdtempSync(join(tmpdir(), 'vendguard-dex-layout-'));

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
    if (payload.id === undefined) return;

    const waiter = pending.get(payload.id);
    pending.delete(payload.id);
    if (waiter === undefined) return;

    if (payload.error) waiter.reject(new Error(payload.error.message ?? 'Error de DevTools.'));
    else waiter.resolve(payload.result ?? {});
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

async function openSurface(cdp, viewport) {
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
    width: viewport.width,
    height: viewport.height,
    deviceScaleFactor: 1,
    mobile: viewport.mobile,
    screenWidth: viewport.width,
    screenHeight: viewport.height
  });
  try {
    await send('Emulation.setScrollbarsHidden', { hidden: true });
  } catch {
    // La barra también se oculta por CSS en el arnés.
  }

  await send('Page.navigate', { url: `${origin}/dex.html` });

  const deadline = Date.now() + 20000;
  let ready = false;
  while (Date.now() < deadline) {
    ready = await evaluate('window.__harnessReady === true');
    if (ready === true) break;
    await new Promise((resolveWait) => setTimeout(resolveWait, 50));
  }

  if (ready !== true) {
    const state = await evaluate('window.__harnessState ?? null');
    throw new Error(`El arnés no terminó de cargar. Estado: ${JSON.stringify(state)}`);
  }
  const state = await evaluate('window.__harnessState');
  if (state?.error) {
    throw new Error(`El arnés falló al montar: ${state.error}`);
  }

  return {
    targetId,
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

function emitMeasurement(name, value) {
  console.log(`  [MEDICIÓN] ${name}: ${JSON.stringify(value)}`);
}

async function measureDocument(surface) {
  return surface.evaluate(`(() => {
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const insideScroller = (element) => {
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
        if (rect.width <= 0.5 || rect.height <= 0.5) return false;
        return rect.right > viewportWidth + 0.5 || rect.left < -0.5;
      })
      .filter((element) => !insideScroller(element))
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
    if (element === null) return { found: false };
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
      text: (element.textContent ?? '').trim().slice(0, 180),
      value: 'value' in element ? String(element.value).slice(0, 600) : null,
      disabled: 'disabled' in element ? Boolean(element.disabled) : null
    };
  })()`);
}

async function measureDialog(surface) {
  return surface.evaluate(`(() => {
    const viewportWidth = window.innerWidth;
    const modal = document.querySelector('.vg-modal-backdrop');
    if (modal === null) return { found: false, dialog: null };
    const dialog = modal.firstElementChild;
    if (dialog === null) return { found: true, dialog: null };
    const rect = dialog.getBoundingClientRect();
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
      viewportWidth
    };
  })()`);
}

async function waitForSelector(surface, selector, timeoutMs = 5000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const found = await surface.evaluate(`document.querySelector(${JSON.stringify(selector)}) !== null`);
    if (found === true) return true;
    await new Promise((resolveWait) => setTimeout(resolveWait, 50));
  }
  return false;
}

let cdp = null;

try {
  cdp = await connectDevTools(devToolsUrl);

  // ───────────────────────────────────────────────────────────────────────
  // 1. Consola a 360 x 800
  // ───────────────────────────────────────────────────────────────────────
  console.log('--- 1. Consola DEX a 360 x 800 ---');
  const mobile = await openSurface(cdp, { width: 360, height: 800, mobile: true });

  const mobileDocument = await measureDocument(mobile);
  emitMeasurement('documento 360', {
    viewport: [mobileDocument.viewportWidth, mobileDocument.viewportHeight],
    scroll: [mobileDocument.documentScrollWidth, mobileDocument.bodyScrollWidth],
    offenders: mobileDocument.offenders
  });
  assert(
    '1.1 El viewport es 360 x 800 y el documento no se ensancha',
    mobileDocument.viewportWidth === 360
      && mobileDocument.viewportHeight === 800
      && mobileDocument.clientWidth === 360
      && mobileDocument.documentScrollWidth <= 360
      && mobileDocument.bodyScrollWidth <= 360,
    JSON.stringify(mobileDocument)
  );
  assert('1.2 Ningún elemento visible fuera de un scroller se sale del viewport', mobileDocument.offenders.length === 0, JSON.stringify(mobileDocument.offenders));

  const root = await measureElement(mobile, '[data-testid="coordinator-dex-audits-subtab"]');
  assert(
    '1.3 La sub-vista real está montada y cabe entre 0 y 360 px',
    root.found === true && root.left >= 0 && root.right <= 360 && root.width > 0,
    JSON.stringify(root)
  );

  const kpis = await mobile.evaluate(`(() => {
    const container = document.querySelector('[data-testid="dex-kpis"]');
    if (container === null) return { count: 0, bad: [], tops: [] };
    const cards = [...container.children];
    return {
      count: cards.length,
      bad: cards.map((card) => {
        const rect = card.getBoundingClientRect();
        return { left: Number(rect.left.toFixed(1)), right: Number(rect.right.toFixed(1)) };
      }).filter((card) => card.left < -0.5 || card.right > 360.5),
      testids: cards.map((card) => card.querySelector('[data-testid]')?.getAttribute('data-testid') ?? '')
    };
  })()`);
  emitMeasurement('KPIs 360', kpis);
  assert(
    '1.4 Los cuatro KPIs están presentes y contenidos',
    kpis.count === 4
      && kpis.bad.length === 0
      && kpis.testids.join('|') === 'dex-kpi-active|dex-kpi-thermal|dex-kpi-warning|dex-kpi-cash',
    JSON.stringify(kpis)
  );

  const filterSelectors = [
    'dex-filter-date-from',
    'dex-filter-date-to',
    'dex-filter-status',
    'dex-filter-source',
    'dex-filter-machine',
    'dex-filter-alerts'
  ];
  const filters = [];
  for (const testid of filterSelectors) {
    const field = await measureElement(mobile, `[data-testid="${testid}"]`);
    filters.push({ testid, ...field });
  }
  emitMeasurement('filtros 360', filters.map((field) => ({ testid: field.testid, left: field.left, right: field.right, width: field.width })));
  assert(
    '1.5 Los seis filtros están montados y contenidos',
    filters.length === 6
      && filters.every((field) => field.found === true && field.left >= 0 && field.right <= 360 && field.width > 0),
    JSON.stringify(filters)
  );

  const manual = await measureElement(mobile, '[data-testid="dex-manual-import"]');
  const fileField = await measureElement(mobile, '[data-testid="dex-manual-file"]');
  assert(
    '1.6 La carga manual y su selector de fichero caben a 360 px',
    manual.found === true
      && manual.left >= 0
      && manual.right <= 360
      && fileField.found === true
      && fileField.left >= 0
      && fileField.right <= 360,
    JSON.stringify({ manual, fileField })
  );

  const table = await measureElement(mobile, '[data-testid="dex-audits-table"]');
  const tableWrapper = await measureElement(mobile, '[data-testid="dex-table-wrapper"]');
  emitMeasurement('tabla 360', { table, tableWrapper });
  assert(
    '1.7 La tabla ancha vive dentro de un contenedor con scroll horizontal propio',
    table.found === true
      && tableWrapper.found === true
      && tableWrapper.overflowX === 'auto'
      && tableWrapper.left >= 0
      && tableWrapper.right <= 360
      && tableWrapper.scrollWidth > tableWrapper.clientWidth
      && table.scrollWidth <= tableWrapper.scrollWidth + 1,
    JSON.stringify({ table, tableWrapper })
  );
  const tableState = await mobile.evaluate(`(() => ({
    rows: [...document.querySelectorAll('[data-testid="dex-audits-table"] tbody tr[data-testid^="dex-audit-row-"]')].length,
    codes: [...document.querySelectorAll('[data-testid="dex-audits-table"] tbody tr[data-testid^="dex-audit-row-"]')]
      .map((row) => row.querySelector('td')?.textContent?.trim() ?? ''),
    voidButtons: document.querySelectorAll('[data-testid^="dex-audit-void-"]').length
  }))()`);
  emitMeasurement('filas 360', tableState);
  assert(
    '1.8 La tabla conserva sus tres filas y acciones de anulación solo en vigentes',
    tableState.rows === 3
      && tableState.codes.join('|') === 'DEX-2026-0031|DEX-2026-0032|DEX-2026-0033'
      && tableState.voidButtons === 2,
    JSON.stringify(tableState)
  );

  await mobile.evaluate(`(() => {
    const wrapper = document.querySelector('[data-testid="dex-table-wrapper"]');
    if (wrapper) wrapper.scrollLeft = wrapper.scrollWidth;
  })()`);
  const scrolledDocument = await measureDocument(mobile);
  assert(
    '1.9 Al desplazar la tabla el documento sigue midiendo 360 px o menos',
    scrolledDocument.documentScrollWidth <= 360 && scrolledDocument.bodyScrollWidth <= 360,
    JSON.stringify({ documentScrollWidth: scrolledDocument.documentScrollWidth, bodyScrollWidth: scrolledDocument.bodyScrollWidth })
  );

  // ───────────────────────────────────────────────────────────────────────
  // 2. Detalle financiero
  // ───────────────────────────────────────────────────────────────────────
  console.log('\n--- 2. Detalle financiero a 360 x 800 ---');
  await mobile.evaluate('window.__harness.openDetail()');
  const detailReady = await waitForSelector(mobile, '[data-testid="dex-detail-body"]');
  assert('2.1 El detalle real se abre con datos', detailReady === true);

  const detailDialog = await measureDialog(mobile);
  emitMeasurement('detalle 360', detailDialog);
  assert(
    '2.2 El diálogo de detalle cabe en 360 px sin scroll horizontal',
    detailDialog.found === true
      && detailDialog.dialog !== null
      && detailDialog.dialog.left >= 0
      && detailDialog.dialog.right <= 360
      && detailDialog.dialog.width <= 360
      && detailDialog.dialog.scrollWidth <= detailDialog.dialog.clientWidth + 1,
    JSON.stringify(detailDialog)
  );

  const detailBody = await measureElement(mobile, '[data-testid="dex-detail-body"]');
  assert(
    '2.3 El cuerpo del detalle está montado y contenido',
    detailBody.found === true && detailBody.left >= 0 && detailBody.right <= 360 && detailBody.width > 0,
    JSON.stringify(detailBody)
  );

  const internalTables = [];
  for (const testid of ['dex-detail-selections-wrapper', 'dex-detail-events-wrapper']) {
    const wrapper = await measureElement(mobile, `[data-testid="${testid}"]`);
    internalTables.push({ testid, ...wrapper });
  }
  emitMeasurement('tablas del detalle 360', internalTables);
  assert(
    '2.4 Las tablas de selecciones y eventos tienen scroll propio y caben',
    internalTables.length === 2
      && internalTables.every((wrapper) => wrapper.found === true
        && wrapper.overflowX === 'auto'
        && wrapper.left >= 0
        && wrapper.right <= 360
        && wrapper.scrollWidth >= wrapper.clientWidth),
    JSON.stringify(internalTables)
  );

  const detailDownload = await measureElement(mobile, '[data-testid="dex-detail-download"]');
  assert(
    '2.5 La descarga del original es visible y cabe en el pie',
    detailDownload.found === true
      && detailDownload.left >= 0
      && detailDownload.right <= 360
      && detailDownload.disabled === false,
    JSON.stringify(detailDownload)
  );

  const detailDocument = await measureDocument(mobile);
  assert(
    '2.6 Con el detalle abierto el documento sigue midiendo 360 px o menos',
    detailDocument.documentScrollWidth <= 360 && detailDocument.bodyScrollWidth <= 360,
    JSON.stringify({ documentScrollWidth: detailDocument.documentScrollWidth, bodyScrollWidth: detailDocument.bodyScrollWidth })
  );

  await mobile.evaluate('window.__harness.closeAll()');

  // ───────────────────────────────────────────────────────────────────────
  // 3. Anulación
  // ───────────────────────────────────────────────────────────────────────
  console.log('\n--- 3. Anulación con motivo largo a 360 x 800 ---');
  await mobile.evaluate('window.__harness.openVoid()');
  const voidReady = await waitForSelector(mobile, '[data-testid="dex-void-body"]');
  assert('3.1 El modal de anulación se abre', voidReady === true);

  const voidDialog = await measureDialog(mobile);
  emitMeasurement('anulación 360', voidDialog);
  assert(
    '3.2 El diálogo de anulación cabe en 360 px sin scroll horizontal',
    voidDialog.found === true
      && voidDialog.dialog !== null
      && voidDialog.dialog.left >= 0
      && voidDialog.dialog.right <= 360
      && voidDialog.dialog.scrollWidth <= voidDialog.dialog.clientWidth + 1,
    JSON.stringify(voidDialog)
  );

  const reason = await measureElement(mobile, '[data-testid="dex-void-reason"]');
  assert(
    '3.3 El motivo largo se conserva en el textarea y este cabe a 360 px',
    reason.found === true
      && reason.value !== null
      && reason.value.length > 100
      && reason.value.length <= 500
      && reason.left >= 0
      && reason.right <= 360
      && reason.scrollWidth <= reason.clientWidth + 1,
    JSON.stringify({ ...reason, value: reason.value?.length ?? null })
  );

  await mobile.evaluate(`(() => {
    const button = document.querySelector('[data-testid="dex-void-confirm"]');
    if (button) button.scrollIntoView({ block: 'center', inline: 'nearest' });
  })()`);
  const voidConfirm = await measureElement(mobile, '[data-testid="dex-void-confirm"]');
  assert(
    '3.4 La confirmación es alcanzable con scroll vertical y cabe en el viewport',
    voidConfirm.found === true
      && voidConfirm.disabled === false
      && voidConfirm.left >= 0
      && voidConfirm.right <= 360
      && voidConfirm.top >= 0
      && voidConfirm.bottom <= 800,
    JSON.stringify(voidConfirm)
  );

  const voidDocument = await measureDocument(mobile);
  assert(
    '3.5 Con la anulación abierta el documento sigue midiendo 360 px o menos',
    voidDocument.documentScrollWidth <= 360 && voidDocument.bodyScrollWidth <= 360,
    JSON.stringify({ documentScrollWidth: voidDocument.documentScrollWidth, bodyScrollWidth: voidDocument.bodyScrollWidth })
  );

  await mobile.evaluate('window.__harness.closeAll()');
  await mobile.close();

  // ───────────────────────────────────────────────────────────────────────
  // 4. Consola a 1440 px
  // ───────────────────────────────────────────────────────────────────────
  console.log('\n--- 4. Consola DEX a 1440 px ---');
  const desktop = await openSurface(cdp, { width: 1440, height: 900, mobile: false });
  const desktopDocument = await measureDocument(desktop);
  emitMeasurement('documento 1440', {
    viewport: [desktopDocument.viewportWidth, desktopDocument.viewportHeight],
    scroll: [desktopDocument.documentScrollWidth, desktopDocument.bodyScrollWidth],
    offenders: desktopDocument.offenders
  });
  assert(
    '4.1 El escritorio no genera scroll horizontal',
    desktopDocument.viewportWidth === 1440
      && desktopDocument.documentScrollWidth <= 1440
      && desktopDocument.bodyScrollWidth <= 1440
      && desktopDocument.offenders.length === 0,
    JSON.stringify(desktopDocument)
  );

  const desktopRoot = await measureElement(desktop, '[data-testid="coordinator-dex-audits-subtab"]');
  assert(
    '4.2 La consola usa el ancho disponible sin desbordarse',
    desktopRoot.found === true && desktopRoot.width >= 1200 && desktopRoot.right <= 1440,
    JSON.stringify(desktopRoot)
  );

  const desktopKpis = await desktop.evaluate(`(() => {
    const container = document.querySelector('[data-testid="dex-kpis"]');
    if (container === null) return { count: 0, rows: [] };
    const cards = [...container.children].map((card) => {
      const rect = card.getBoundingClientRect();
      return { top: Number(rect.top.toFixed(1)), left: Number(rect.left.toFixed(1)), right: Number(rect.right.toFixed(1)) };
    });
    return { count: cards.length, rows: [...new Set(cards.map((card) => card.top))], cards };
  })()`);
  assert(
    '4.3 Los cuatro KPIs se alinean en una fila de escritorio',
    desktopKpis.count === 4 && desktopKpis.rows.length === 1 && desktopKpis.cards.every((card) => card.right <= 1440),
    JSON.stringify(desktopKpis)
  );

  const desktopTable = await measureElement(desktop, '[data-testid="dex-audits-table"]');
  const desktopWrapper = await measureElement(desktop, '[data-testid="dex-table-wrapper"]');
  assert(
    '4.4 La tabla financiera conserva su ancho mínimo y su scroller',
    desktopTable.found === true
      && desktopWrapper.found === true
      && desktopWrapper.overflowX === 'auto'
      && desktopTable.width >= 1080
      && desktopWrapper.right <= 1440,
    JSON.stringify({ desktopTable, desktopWrapper })
  );

  await desktop.evaluate('window.__harness.openDetail()');
  await waitForSelector(desktop, '[data-testid="dex-detail-body"]');
  const desktopDialog = await measureDialog(desktop);
  assert(
    '4.5 El detalle de escritorio se limita a 720 px y no desborda',
    desktopDialog.found === true
      && desktopDialog.dialog !== null
      && desktopDialog.dialog.width <= 720
      && desktopDialog.dialog.left >= 0
      && desktopDialog.dialog.right <= 1440
      && desktopDialog.dialog.scrollWidth <= desktopDialog.dialog.clientWidth + 1,
    JSON.stringify(desktopDialog)
  );
  await desktop.evaluate('window.__harness.closeAll()');
  await desktop.close();
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
  console.log(' RESULTADO: 100% EN VERDE. LAYOUT DE LA CONSOLA DEX CERTIFICADO.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.log(' RESULTADO: FALLO EN EL LAYOUT DE LA CONSOLA DEX.');
console.log('======================================================================\n');
process.exit(1);
