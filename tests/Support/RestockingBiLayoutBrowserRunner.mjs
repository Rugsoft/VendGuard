/**
 * VendGuard - Verificación real de la sub-pestaña M38 (T-RBI-07)
 *
 * Monta `CoordinatorMetricsView` con sus estilos de producción y la API
 * simulada en `fetch`, y mide en un Chromium real la sub-pestaña «📦 Métricas
 * de Carga y Reposición»:
 *
 *  1. Documento a 360 x 800 sin desbordamiento horizontal y sin la barra global
 *     de SAT duplicada (`QA-3`).
 *  2. Filtros: cascada zona/sede que limpia `location_id` fuera de zona.
 *  3. Tarjetas KPI, tablas con badges y wrappers con scroll propio.
 *  4. Dos `<canvas>` Chart.js reales con datos y conmutadores €/Unidades y
 *     Rotura/Caducidad × Máquina/Producto; el eje Y del «Top 10» envuelve
 *     código y nombre completo a 360 px.
 *  5. Aviso térmico no segmentable al filtrar por reponedor (`D2`).
 *  6. Descarga CSV autenticada vía `fetch` + `Blob` con cabecera Bearer.
 *  7. Regresión del «Top 10»: un criterio vacío seguido de uno con datos se
 *     pinta en el primer clic.
 *  8. Sin errores de consola ni excepciones no capturadas.
 *  9. Ida y vuelta de la sub-pestaña con la barra global (QA-3).
 * 10. Rejilla de escritorio a 1280 x 900 sin desbordamiento.
 *
 * La suite no usa dobles de layout: sirve el documento real, bloquea/intercepta
 * las tipografías externas y mide los rectángulos. Si no hay navegador o Node
 * 22+, sale con código 2 para que el canal lo declare no ejecutable.
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
const HARNESS = join(PROJECT_ROOT, 'tests', 'Support', 'restocking_bi_layout_harness.html');
const MOBILE = { width: 360, height: 800 };
const DESKTOP = { width: 1280, height: 900 };

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

function formatDetails(value) {
  return JSON.stringify(value);
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
  notExecutable('No se encontró Chrome, Edge ni Chromium. Define VENDGUARD_CHROME_PATH para certificar el layout del M38.');
}

if (typeof WebSocket !== 'function') {
  notExecutable('Este Node no expone `WebSocket` global (requiere Node 22+), necesario para hablar con DevTools.');
}

// ─────────────────────────────────────────────────────────────────────────
// 2. Servidor local: arnés real y estilos de producción
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

  if (url.pathname === '/harness.html') {
    serveFile(response, HARNESS);
    return;
  }

  if (url.pathname.startsWith('/assets/')) {
    serveAsset(response, url.pathname);
    return;
  }

  if (url.pathname === '/favicon.ico') {
    response.writeHead(204);
    response.end();
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
console.log(' VENDGUARD · Layout real de la sub-pestaña M38 (T-RBI-07)');
console.log(` Navegador: ${browserPath}`);
console.log(' Categoría: new_engine (canal propio, fuera de la batería hermética)');
console.log('======================================================================\n');

// ─────────────────────────────────────────────────────────────────────────
// 3. Lanzar Chromium y conectar por DevTools
// ─────────────────────────────────────────────────────────────────────────

const profileDirectory = mkdtempSync(join(tmpdir(), 'vendguard-restocking-bi-layout-'));

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

/** Cliente mínimo del protocolo DevTools: sesiones planas y eventos. */
async function connectDevTools(url) {
  const socket = new WebSocket(url);
  const pending = new Map();
  const eventHandlers = new Set();
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
      for (const handler of eventHandlers) {
        handler(payload);
      }
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
    onEvent(handler) {
      eventHandlers.add(handler);
      return () => eventHandlers.delete(handler);
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

  const consoleErrors = [];
  const unsubscribe = cdp.onEvent((payload) => {
    if (payload.sessionId !== sessionId) {
      return;
    }

    if (payload.method === 'Runtime.consoleAPICalled' && payload.params.type === 'error') {
      consoleErrors.push(payload.params.args
        .map((argument) => argument.value ?? argument.description ?? '')
        .join(' '));
    }

    if (payload.method === 'Runtime.exceptionThrown') {
      const details = payload.params.exceptionDetails;
      consoleErrors.push(details.exception?.description ?? details.text ?? 'Excepción no capturada');
    }

    if (payload.method === 'Log.entryAdded' && payload.params.entry.level === 'error') {
      consoleErrors.push(payload.params.entry.text ?? 'Entrada de error');
    }
  });

  try {
    await send('Log.enable');
  } catch {
    // La captura por Runtime sigue disponible.
  }

  // Las tipografías externas se sirven vacías: la medición no depende de la red
  // y la suite no produce errores de consola por un CDN inalcanzable.
  try {
    await send('Fetch.enable', {
      patterns: [
        { urlPattern: '*fonts.googleapis.com*' },
        { urlPattern: '*fonts.gstatic.com*' }
      ]
    });
    cdp.onEvent((payload) => {
      if (payload.sessionId !== sessionId || payload.method !== 'Fetch.requestPaused') {
        return;
      }

      const url = payload.params.request.url;
      const contentType = url.includes('fonts.googleapis.com')
        ? 'text/css; charset=utf-8'
        : 'font/woff2';

      send('Fetch.fulfillRequest', {
        requestId: payload.params.requestId,
        responseCode: 200,
        responseHeaders: [{ name: 'Content-Type', value: contentType }],
        body: ''
      }).catch(() => {});
    });
  } catch {
    // Sin intercepción la suite sigue midiendo; solo podría quedar el aviso del CDN.
  }

  await send('Emulation.setDeviceMetricsOverride', {
    width: viewport.width,
    height: viewport.height,
    deviceScaleFactor: 1,
    mobile: viewport.width <= 480,
    screenWidth: viewport.width,
    screenHeight: viewport.height
  });

  try {
    await send('Emulation.setScrollbarsHidden', { hidden: true });
  } catch {
    // La barra también se oculta por CSS en el arnés.
  }

  await send('Page.navigate', { url: `${origin}/harness.html` });

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
    throw new Error(`El arnés no terminó de cargar. Estado: ${JSON.stringify(state)}`);
  }

  return {
    targetId,
    sessionId,
    evaluate,
    consoleErrors,
    async setViewport(nextViewport) {
      await send('Emulation.setDeviceMetricsOverride', {
        width: nextViewport.width,
        height: nextViewport.height,
        deviceScaleFactor: 1,
        mobile: nextViewport.width <= 480,
        screenWidth: nextViewport.width,
        screenHeight: nextViewport.height
      });
    },
    async close() {
      unsubscribe();
      try {
        await cdp.send('Target.closeTarget', { targetId });
      } catch {
        // La pestaña ya no existe.
      }
    }
  };
}

// ─────────────────────────────────────────────────────────────────────────
// 4. Mediciones
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
      overflowX: getComputedStyle(element).overflowX,
      text: (element.textContent ?? '').trim().slice(0, 200),
      value: 'value' in element ? String(element.value).slice(0, 300) : null,
      disabled: 'disabled' in element ? Boolean(element.disabled) : null
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
      rows: table.querySelectorAll('tbody tr').length,
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

async function waitForCondition(surface, expression, timeoutMs = 8000) {
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

async function click(surface, selector) {
  return surface.evaluate(`(() => {
    const element = document.querySelector(${JSON.stringify(selector)});
    if (element === null) {
      return false;
    }
    element.click();
    return true;
  })()`);
}

async function clickByText(surface, text) {
  return surface.evaluate(`(() => {
    const button = [...document.querySelectorAll('button')]
      .find((candidate) => (candidate.textContent ?? '').includes(${JSON.stringify(text)}));
    if (button === undefined) {
      return false;
    }
    button.click();
    return true;
  })()`);
}

async function writeSelect(surface, testId, value) {
  return surface.evaluate(`(() => {
    const select = document.querySelector('[data-testid="${testId}"]');
    if (select === null) {
      return null;
    }
    select.value = ${JSON.stringify(String(value))};
    select.dispatchEvent(new Event('change', { bubbles: true }));
    return select.value;
  })()`);
}

function chartStateExpression() {
  return `(() => {
    const spoilageCanvas = document.querySelector('[data-testid="restocking-bi-spoilage-chart"] canvas');
    const topCanvas = document.querySelector('[data-testid="restocking-bi-top-chart"] canvas');
    const spoilageChart = spoilageCanvas !== null && typeof window.Chart === 'function'
      ? (window.Chart.getChart(spoilageCanvas) ?? null)
      : null;
    const topChart = topCanvas !== null && typeof window.Chart === 'function'
      ? (window.Chart.getChart(topCanvas) ?? null)
      : null;
    const rectOf = (canvas) => {
      if (canvas === null) return null;
      const rect = canvas.getBoundingClientRect();
      return {
        left: Number(rect.left.toFixed(1)),
        right: Number(rect.right.toFixed(1)),
        width: Number(rect.width.toFixed(1)),
        height: Number(rect.height.toFixed(1))
      };
    };

    return {
      hasEngine: typeof window.Chart === 'function',
      spoilage: spoilageChart === null ? null : {
        labels: Array.from(spoilageChart.data.labels ?? []),
        values: Array.from(spoilageChart.data.datasets?.[0]?.data ?? []),
        rect: rectOf(spoilageCanvas)
      },
      top: topChart === null ? null : {
        labels: Array.from(topChart.data.labels ?? []),
        tickLabels: Array.from(topChart.scales?.y?.ticks ?? []).map((tick) => tick.label),
        values: Array.from(topChart.data.datasets?.[0]?.data ?? []),
        indexAxis: topChart.options?.indexAxis ?? 'x',
        chartArea: topChart.chartArea === undefined ? null : {
          left: Number(topChart.chartArea.left.toFixed(1)),
          width: Number(topChart.chartArea.width.toFixed(1))
        },
        rect: rectOf(topCanvas)
      }
    };
  })()`;
}

let cdp = null;

try {
  cdp = await connectDevTools(devToolsUrl);

  const surface = await openSurface(cdp, MOBILE);
  const initialDocument = await measureDocument(surface);

  // ───────────────────────────────────────────────────────────────────────
  // 1. Documento móvil y ausencia de la barra global duplicada (QA-3)
  // ───────────────────────────────────────────────────────────────────────

  console.log('--- 1. Documento 360 x 800 y barra global de SAT oculta (QA-3) ---');
  assert(
    '1.1 El viewport real es 360 x 800 y el documento no se ensancha',
    initialDocument.viewportWidth === 360
      && initialDocument.viewportHeight === 800
      && initialDocument.clientWidth === 360
      && initialDocument.documentScrollWidth <= 360
      && initialDocument.bodyScrollWidth <= 360,
    formatDetails(initialDocument)
  );
  assert(
    '1.2 Ningún elemento visible se sale del viewport sin un contenedor con scroll propio',
    initialDocument.offenders.length === 0,
    formatDetails(initialDocument.offenders)
  );

  const tabBlock = await measureElement(surface, '[data-testid="restocking-bi-tab"]');
  assert(
    '1.3 La sub-pestaña está montada y cabe entre 0 y 360 px',
    tabBlock.found === true
      && tabBlock.left >= 0
      && tabBlock.right <= 360
      && tabBlock.width > 0,
    formatDetails(tabBlock)
  );

  const globalBarState = await surface.evaluate(`(() => ({
    hasGlobalPeriod: document.querySelector('select option[value="last_30_days"]') !== null,
    exportButtons: [...document.querySelectorAll('button')]
      .filter((button) => (button.textContent ?? '').includes('Exportar CSV')).length,
    metricsTabs: document.querySelectorAll('[data-testid="tab-restocking-bi"]').length
  }))()`);
  assert(
    '1.4 La barra global de periodo y su CSV no se duplican en la pestaña (QA-3)',
    globalBarState.hasGlobalPeriod === false
      && globalBarState.exportButtons === 1
      && globalBarState.metricsTabs === 1,
    formatDetails(globalBarState)
  );

  const filterIds = [
    'restocking-bi-filter-from',
    'restocking-bi-filter-to',
    'restocking-bi-filter-zone',
    'restocking-bi-filter-location',
    'restocking-bi-filter-restocker'
  ];
  const filters = await surface.evaluate(`(() => ${JSON.stringify(filterIds)}.map((testid) => {
    const element = document.querySelector('[data-testid="' + testid + '"]');
    if (element === null) return { testid, found: false };
    const rect = element.getBoundingClientRect();
    return {
      testid,
      found: true,
      left: Number(rect.left.toFixed(1)),
      right: Number(rect.right.toFixed(1)),
      width: Number(rect.width.toFixed(1))
    };
  }))()`);
  assert(
    '1.5 Los cinco controles de la barra propia están montados y contenidos',
    filters.every((filter) => filter.found === true
      && filter.left >= 0
      && filter.right <= 360
      && filter.width > 0),
    formatDetails(filters)
  );

  // ───────────────────────────────────────────────────────────────────────
  // 2. Filtros en cascada
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 2. Filtros en cascada zona / sede ---');
  const locationBefore = await writeSelect(surface, 'restocking-bi-filter-location', 9);
  assert(
    '2.1 La sede histórica se selecciona sin zona activa',
    locationBefore === '9',
    `value=${locationBefore}`
  );

  await writeSelect(surface, 'restocking-bi-filter-zone', 1);
  const cascadeApplied = await waitForCondition(
    surface,
    `document.querySelector('[data-testid="restocking-bi-filter-location"]').value === ''
      && window.__requests.some((entry) => entry.url.includes('/coordinator/metrics/restocking')
        && !entry.url.includes('/export')
        && entry.url.includes('zone_id=1')
        && !entry.url.includes('location_id=9'))`
  );

  const cascadeState = await surface.evaluate(`(() => ({
    locationValue: document.querySelector('[data-testid="restocking-bi-filter-location"]').value,
    zoneValue: document.querySelector('[data-testid="restocking-bi-filter-zone"]').value,
    lastRestockingRequest: [...window.__requests].reverse()
      .find((entry) => entry.url.includes('/coordinator/metrics/restocking') && !entry.url.includes('/export'))?.url ?? null
  }))()`);
  assert(
    '2.2 Cambiar de zona limpia la sede fuera de la demarcación antes de recargar',
    cascadeApplied === true
      && cascadeState.locationValue === ''
      && cascadeState.zoneValue === '1'
      && cascadeState.lastRestockingRequest !== null
      && cascadeState.lastRestockingRequest.includes('zone_id=1')
      && !cascadeState.lastRestockingRequest.includes('location_id='),
    formatDetails(cascadeState)
  );

  // ───────────────────────────────────────────────────────────────────────
  // 3. Tarjetas KPI y tablas
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 3. Tarjetas KPI, tablas e insignias ---');
  const kpis = await surface.evaluate(`(() => {
    const container = document.querySelector('[data-testid="restocking-bi-summary-cards"]');
    if (container === null) return { count: 0, bad: [], thermal: null, inStock: null };
    const cards = [...container.children];
    const bad = cards.map((card) => {
      const rect = card.getBoundingClientRect();
      return { left: Number(rect.left.toFixed(1)), right: Number(rect.right.toFixed(1)) };
    }).filter((card) => card.left < -0.5 || card.right > 360.5);
    return {
      count: cards.length,
      bad,
      thermal: document.querySelector('[data-testid="restocking-bi-kpi-thermal-spoilage"] strong')?.textContent?.trim().replace(/\u00a0/g, ' ') ?? null,
      inStock: document.querySelector('[data-testid="restocking-bi-kpi-visit-stockout"] strong')?.textContent?.trim() ?? null
    };
  })()`);
  assert(
    '3.1 Las nueve tarjetas KPI caben en una sola columna móvil',
    kpis.count === 9 && kpis.bad.length === 0,
    formatDetails(kpis)
  );
  assert(
    '3.2 Los KPIs publican la disponibilidad y la merma térmica en formato español',
    kpis.inStock === '85,00 %' && kpis.thermal === '31,50 €',
    formatDetails({ inStock: kpis.inStock, thermal: kpis.thermal })
  );

  const tableSelectors = [
    ['restocking-bi-machines-table', 'máquinas'],
    ['restocking-bi-products-table', 'productos'],
    ['restocking-bi-restockers-table', 'reponedores']
  ];

  for (const [testId, label] of tableSelectors) {
    const table = await measureTable(surface, `[data-testid="${testId}"]`);
    assert(
      `3.3 La tabla de ${label} queda contenida con scroll horizontal propio`,
      table.found === true
        && table.rows === 3
        && table.wrapper !== null
        && table.wrapper.left >= 0
        && table.wrapper.right <= 360
        && table.wrapper.overflowX === 'auto'
        && table.wrapper.scrollWidth >= table.wrapper.clientWidth,
      formatDetails(table)
    );
  }

  const badges = await surface.evaluate(`(() => ({
    baja: [...document.querySelectorAll('[data-testid="restocking-bi-tab"] span')]
      .filter((span) => (span.textContent ?? '').trim() === 'Baja').length,
    oversized: [...document.querySelectorAll('[data-testid="restocking-bi-tab"] span')]
      .filter((span) => (span.textContent ?? '').trim() === 'Planograma sobredimensionado').length
  }))()`);
  assert(
    '3.4 Las insignias «Baja» y «Planograma sobredimensionado» se pintan en las tablas',
    badges.baja >= 2 && badges.oversized === 1,
    formatDetails(badges)
  );

  const scrollState = await surface.evaluate(`(() => {
    const wrappers = ['restocking-bi-machines-table', 'restocking-bi-products-table', 'restocking-bi-restockers-table']
      .map((testid) => document.querySelector('[data-testid="' + testid + '"]')?.parentElement ?? null);
    wrappers.forEach((wrapper) => {
      if (wrapper !== null) wrapper.scrollLeft = wrapper.scrollWidth;
    });
    return {
      found: wrappers.every((wrapper) => wrapper !== null),
      maxScroll: wrappers.map((wrapper) => wrapper === null ? 0 : wrapper.scrollWidth - wrapper.clientWidth)
    };
  })()`);
  const scrolledDocument = await measureDocument(surface);
  assert(
    '3.5 Al desplazar las tres tablas, el documento no arrastra scroll horizontal',
    scrollState.found === true
      && scrolledDocument.documentScrollWidth <= 360
      && scrolledDocument.bodyScrollWidth <= 360
      && scrolledDocument.offenders.length === 0,
    formatDetails({ scrollState, scrolledDocument })
  );

  // ───────────────────────────────────────────────────────────────────────
  // 4. Chart.js real y conmutadores
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 4. Chart.js real y conmutadores ---');
  const initialCharts = await surface.evaluate(chartStateExpression());
  assert(
    '4.1 Ambos canvas existen y Chart.js ha creado sus dos instancias',
    initialCharts.hasEngine === true
      && initialCharts.spoilage !== null
      && initialCharts.top !== null,
    formatDetails(initialCharts)
  );
  assert(
    '4.2 El gráfico de mermas pinta las tres barras del contrato dentro del viewport',
    initialCharts.spoilage?.labels.length === 3
      && initialCharts.spoilage.values.join(',') === '34.8,7.5,31.5'
      && initialCharts.spoilage.rect.left >= 0
      && initialCharts.spoilage.rect.right <= 360
      && initialCharts.spoilage.rect.width > 0,
    formatDetails(initialCharts.spoilage)
  );
  assert(
    '4.3 El Top 10 es horizontal, tiene tres filas y cabe a 360 px',
    initialCharts.top?.indexAxis === 'y'
      && initialCharts.top.labels.length === 3
      && initialCharts.top.rect.left >= 0
      && initialCharts.top.rect.right <= 360
      && initialCharts.top.rect.width > 0,
    formatDetails(initialCharts.top)
  );

  await click(surface, '[data-testid="restocking-bi-spoilage-units"]');
  const unitsApplied = await waitForCondition(
    surface,
    `(() => {
      const canvas = document.querySelector('[data-testid="restocking-bi-spoilage-chart"] canvas');
      const chart = canvas === null ? null : window.Chart.getChart(canvas);
      return chart !== null && Array.from(chart.data.datasets[0].data).join(',') === '15,3,9';
    })()`
  );
  assert('4.4 El conmutador €/Unidades repinta la serie en unidades', unitsApplied === true);

  await click(surface, '[data-testid="restocking-bi-top-expiry"]');
  await click(surface, '[data-testid="restocking-bi-top-product"]');
  const expiryApplied = await waitForCondition(
    surface,
    `(() => {
      const canvas = document.querySelector('[data-testid="restocking-bi-top-chart"] canvas');
      const chart = canvas === null ? null : window.Chart.getChart(canvas);
      return chart !== null && String(chart.data.labels[0]).startsWith('SAND-MIXTO-01');
    })()`
  );
  const expiryState = await surface.evaluate(chartStateExpression());
  assert(
    '4.5 El Top 10 conmuta a caducidad FEFO por producto',
    expiryApplied === true
      && expiryState.top?.labels[0] === 'SAND-MIXTO-01 · Sándwich Mixto Fresco'
      && expiryState.top.values[0] === 20,
    formatDetails(expiryState.top)
  );

  assert(
    '4.6 El eje Y del Top 10 muestra código y nombre en varias líneas a 360 px',
    Array.isArray(expiryState.top?.tickLabels?.[0])
      && expiryState.top.tickLabels[0][0] === 'SAND-MIXTO-01'
      && expiryState.top.tickLabels[0].join(' ') === 'SAND-MIXTO-01 Sándwich Mixto Fresco'
      && expiryState.top.labels[0] === 'SAND-MIXTO-01 · Sándwich Mixto Fresco'
      && Number(expiryState.top.chartArea?.width) > 100,
    formatDetails({ tickLabels: expiryState.top?.tickLabels, chartArea: expiryState.top?.chartArea })
  );

  const chartDocument = await measureDocument(surface);
  assert(
    '4.7 Con ambos gráficos repintados el documento sigue midiendo 360 px o menos',
    chartDocument.documentScrollWidth <= 360
      && chartDocument.bodyScrollWidth <= 360
      && chartDocument.offenders.length === 0,
    formatDetails(chartDocument)
  );

  // ───────────────────────────────────────────────────────────────────────
  // 5. Filtro por reponedor y aviso térmico
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 5. Filtro por reponedor y aviso térmico no segmentable ---');
  await writeSelect(surface, 'restocking-bi-filter-restocker', 7);
  await click(surface, '[data-testid="restocking-bi-apply-filters"]');
  const thermalApplied = await waitForCondition(
    surface,
    `document.querySelector('[data-testid="restocking-bi-thermal-notice"]') !== null
      && document.querySelector('[data-testid="restocking-bi-kpi-thermal-spoilage"] strong')?.textContent?.trim() === '—'
      && (() => {
        const canvas = document.querySelector('[data-testid="restocking-bi-spoilage-chart"] canvas');
        const chart = canvas === null ? null : window.Chart.getChart(canvas);
        return chart !== null && Array.from(chart.data.labels).length === 2;
      })()`
  );
  const thermalState = await surface.evaluate(`(() => ({
    notice: document.querySelector('[data-testid="restocking-bi-thermal-notice"]')?.textContent?.trim() ?? null,
    kpi: document.querySelector('[data-testid="restocking-bi-kpi-thermal-spoilage"] strong')?.textContent?.trim() ?? null,
    labels: (() => {
      const canvas = document.querySelector('[data-testid="restocking-bi-spoilage-chart"] canvas');
      const chart = canvas === null ? null : window.Chart.getChart(canvas);
      return chart === null ? [] : Array.from(chart.data.labels);
    })()
  }))()`);
  assert(
    '5.1 Filtrar por reponedor activa el aviso canónico y retira la barra térmica',
    thermalApplied === true
      && thermalState.notice.includes('No segmentable por reponedor (ámbito máquina/SAT)')
      && thermalState.kpi === '—'
      && thermalState.labels.length === 2,
    formatDetails(thermalState)
  );

  // ───────────────────────────────────────────────────────────────────────
  // 6. Descarga CSV autenticada
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 6. Descarga CSV autenticada ---');
  await click(surface, '[data-testid="restocking-bi-export-csv"]');
  const downloadApplied = await waitForCondition(
    surface,
    `window.__requests.some((entry) => entry.url.includes('/coordinator/metrics/restocking/export'))
      && window.__downloads.length > 0
      && document.querySelector('[data-testid="restocking-bi-export-csv"]').disabled === false`
  );
  const downloadState = await surface.evaluate(`(() => {
    const request = [...window.__requests].reverse()
      .find((entry) => entry.url.includes('/coordinator/metrics/restocking/export'));
    if (request === undefined) return { request: null, download: null };
    const authorization = Object.entries(request.headers)
      .find(([name]) => name.toLowerCase() === 'authorization');
    return {
      method: request.method,
      url: request.url,
      authorization: authorization === undefined ? null : authorization[1],
      download: window.__downloads[window.__downloads.length - 1] ?? null
    };
  })()`);
  assert(
    '6.1 La descarga sale por fetch autenticado con Bearer y dispara el archivo',
    downloadApplied === true
      && downloadState.request !== null
      && downloadState.method === 'GET'
      && downloadState.authorization === 'Bearer harness-coordinator-token'
      && downloadState.download !== null
      && String(downloadState.download.download).endsWith('.csv'),
    formatDetails(downloadState)
  );

  const downloadDocument = await measureDocument(surface);
  assert(
    '6.2 Tras la descarga el documento conserva el ancho móvil',
    downloadDocument.documentScrollWidth <= 360 && downloadDocument.bodyScrollWidth <= 360,
    formatDetails(downloadDocument)
  );

  // ───────────────────────────────────────────────────────────────────────
  // 7. Regresión: Top 10 de vacío a datos en un solo clic
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 7. Regresión del Top 10: de vacío a datos en un clic ---');
  await click(surface, '[data-testid="restocking-bi-top-stockout"]');
  const stockoutWithData = await waitForCondition(
    surface,
    `(() => {
      const canvas = document.querySelector('[data-testid="restocking-bi-top-chart"] canvas');
      const chart = canvas === null ? null : window.Chart.getChart(canvas);
      return chart?.data?.datasets?.[0]?.data?.[0] === 30;
    })()`
  );

  await surface.evaluate(`window.__harnessScenario = 'stockout-empty'`);
  await click(surface, '[data-testid="restocking-bi-apply-filters"]');
  const stockoutEmptied = await waitForCondition(
    surface,
    `document.querySelector('[data-testid="restocking-bi-top-chart"]') === null
      && document.querySelector('[data-testid="restocking-bi-top-empty"]') !== null`
  );

  await click(surface, '[data-testid="restocking-bi-top-expiry"]');
  const paintedOnFirstClick = await waitForCondition(
    surface,
    `(() => {
      const canvas = document.querySelector('[data-testid="restocking-bi-top-chart"] canvas');
      const chart = canvas === null ? null : window.Chart.getChart(canvas);
      return chart?.data?.labels?.[0] === 'SAND-MIXTO-01 · Sándwich Mixto Fresco'
        && chart?.data?.datasets?.[0]?.data?.[0] === 20;
    })()`,
    3000
  );
  const firstClickState = await surface.evaluate(chartStateExpression());

  assert(
    '7.1 Pasar de un criterio vacío a uno con datos pinta el Top 10 en el primer clic',
    stockoutWithData === true
      && stockoutEmptied === true
      && paintedOnFirstClick === true
      && firstClickState.top !== null
      && firstClickState.top.labels[0] === 'SAND-MIXTO-01 · Sándwich Mixto Fresco'
      && firstClickState.top.values[0] === 20,
    formatDetails({ stockoutWithData, stockoutEmptied, paintedOnFirstClick, top: firstClickState.top })
  );

  await surface.evaluate(`window.__harnessScenario = null`);

  // ───────────────────────────────────────────────────────────────────────
  // 8. Consola limpia
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 8. Consola y excepciones ---');
  await new Promise((resolveWait) => setTimeout(resolveWait, 250));
  assert(
    '8.1 No hay errores de consola ni excepciones no capturadas',
    surface.consoleErrors.length === 0,
    formatDetails(surface.consoleErrors)
  );

  // ───────────────────────────────────────────────────────────────────────
  // 9. La barra global vuelve al salir de la sub-pestaña (QA-3)
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 9. Ida y vuelta de la pestaña (QA-3) ---');
  await clickByText(surface, 'Cuadro de Mandos (KPIs)');
  const returnedToKpis = await waitForCondition(
    surface,
    `document.querySelector('select option[value="last_30_days"]') !== null
      && document.querySelector('[data-testid="restocking-bi-tab"]') === null`
  );
  assert(
    '9.1 Al volver a KPIs reaparece la barra global y desaparece la pestaña de reposición',
    returnedToKpis === true
  );

  await click(surface, '[data-testid="tab-restocking-bi"]');
  const returnedToRestocking = await waitForCondition(
    surface,
    `document.querySelector('[data-testid="restocking-bi-tab"]') !== null
      && document.querySelector('select option[value="last_30_days"]') === null
      && document.querySelectorAll('[data-testid="restocking-bi-tab"] canvas').length === 2
      && (() => {
        const canvas = document.querySelector('[data-testid="restocking-bi-spoilage-chart"] canvas');
        const chart = canvas === null ? null : window.Chart.getChart(canvas);
        return chart !== null && Array.from(chart.data.labels).length === 3;
      })()`
  );
  assert(
    '9.2 Al volver a reposición se oculta la barra global y se remontan los dos gráficos con el informe por defecto',
    returnedToRestocking === true
  );

  await surface.close();

  // ───────────────────────────────────────────────────────────────────────
  // 10. Escritorio 1280 x 900 (carga propia: el layout se mide desde el montaje)
  // ───────────────────────────────────────────────────────────────────────

  console.log('\n--- 10. Escritorio 1280 x 900 ---');
  const desktopSurface = await openSurface(cdp, DESKTOP);
  const desktopDocument = await measureDocument(desktopSurface);
  assert(
    '10.1 En escritorio el documento mide 1280 px y no se ensancha',
    desktopDocument.viewportWidth === 1280
      && desktopDocument.clientWidth === 1280
      && desktopDocument.documentScrollWidth <= 1280
      && desktopDocument.bodyScrollWidth <= 1280,
    formatDetails(desktopDocument)
  );
  assert(
    '10.2 Ningún elemento visible se sale del viewport de escritorio',
    desktopDocument.offenders.length === 0,
    formatDetails(desktopDocument.offenders)
  );

  const desktopCharts = await desktopSurface.evaluate(chartStateExpression());
  assert(
    '10.3 Los dos gráficos se redimensionan al ancho de escritorio',
    desktopCharts.spoilage !== null
      && desktopCharts.top !== null
      && desktopCharts.spoilage.rect.width > 700
      && desktopCharts.top.rect.width > 700
      && desktopCharts.spoilage.rect.right <= 1280
      && desktopCharts.top.rect.right <= 1280,
    formatDetails(desktopCharts)
  );

  const desktopKpis = await desktopSurface.evaluate(`(() => {
    const container = document.querySelector('[data-testid="restocking-bi-summary-cards"]');
    if (container === null) return { count: 0, bad: [] };
    const cards = [...container.children];
    return {
      count: cards.length,
      bad: cards.map((card) => {
        const rect = card.getBoundingClientRect();
        return { left: Number(rect.left.toFixed(1)), right: Number(rect.right.toFixed(1)) };
      }).filter((card) => card.left < -0.5 || card.right > 1280.5)
    };
  })()`);
  assert(
    '10.4 Las nueve tarjetas KPI se reorganizan en rejilla de escritorio sin desbordarse',
    desktopKpis.count === 9 && desktopKpis.bad.length === 0,
    formatDetails(desktopKpis)
  );

  for (const [testId, label] of tableSelectors) {
    const table = await measureTable(desktopSurface, `[data-testid="${testId}"]`);
    assert(
      `10.5 La tabla de ${label} conserva su wrapper y cabe en escritorio`,
      table.found === true
        && table.wrapper !== null
        && table.wrapper.left >= 0
        && table.wrapper.right <= 1280,
      formatDetails(table)
    );
  }

  await new Promise((resolveWait) => setTimeout(resolveWait, 250));
  assert(
    '10.6 La carga de escritorio no emite errores de consola',
    desktopSurface.consoleErrors.length === 0,
    formatDetails(desktopSurface.consoleErrors)
  );

  await desktopSurface.close();
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
  console.log(' RESULTADO: 100% EN VERDE. LAYOUT M38 CERTIFICADO A 360 X 800 Y ESCRITORIO.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.log(' RESULTADO: FALLO EN EL LAYOUT DE LA SUB-PESTAÑA M38.');
console.log('======================================================================\n');
process.exit(1);
