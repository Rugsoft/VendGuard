/**
 * VendGuard - PWA Asset and UI Guard Test (OfflinePwaAssetsAndUiGuardTest.mjs)
 *
 * Certifica los artefactos instalables y la interfaz del Modo Sin Conexión
 * (Módulo 24, T-OFFLINE-13) sin navegador, sin red y sin dependencias npm:
 *
 *  - **Manifiesto (RF-01.1):** identidad, modo `standalone`, colores del sistema
 *    de diseño e iconos escalables `192`/`512` realmente presentes en disco.
 *  - **Service Worker (RF-01.2, Art. V.4):** el App Shell precachea todo el
 *    grafo de módulos ES que arranca la aplicación y `/api/*` es `Network-Only`
 *    estricto —una respuesta de API jamás entra en `CacheStorage`—, comprobado
 *    ejecutando el propio `fetch` del Service Worker con la red y la caché
 *    simuladas, no solo leyendo el texto.
 *  - **Precinto sanitario (QA 1, Art. II):** la vista del técnico exige la
 *    confirmación expresa antes de guardar una pausa o preventiva desfavorable
 *    sobre una máquina perecedera.
 *  - **Sin descarte silencioso (QA 1, Art. III.1):** el conflicto se resuelve
 *    con el único botón de volcado; no hay acción de descarte en la interfaz ni
 *    en el motor.
 *  - **Sistema de diseño (RNF-03, DesignTokenDebtRatchetTest):** la bandeja usa
 *    exclusivamente tokens (`var(--color-*)`, `var(--radius-*)`, `var(--font-*)`),
 *    objetivos táctiles de 44 px y ningún literal cromático nuevo.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join, posix } from 'node:path';

const PROJECT_ROOT = fileURLToPath(new URL('../../', import.meta.url));
const PUBLIC_ROOT = join(PROJECT_ROOT, 'public');

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

function publicFile(relativePath) {
  return join(PUBLIC_ROOT, relativePath.replace(/^\//, ''));
}

function readPublic(relativePath) {
  return readFileSync(publicFile(relativePath), 'utf8');
}

/** Quita comentarios para que la guarda no se satisfaga (ni falle) por prosa. */
function stripComments(source) {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '');
}

console.log('======================================================================');
console.log(' VendGuard: PWA asset and offline UI guard');
console.log('======================================================================\n');

console.log('--- 1. Manifiesto de instalación (RF-01.1, EARS 01.1) ---');

const manifestPath = join(PUBLIC_ROOT, 'manifest.webmanifest');
assert('1.1 El manifiesto existe en la raíz pública', existsSync(manifestPath));

let manifest = null;
try {
  manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
  assert('1.2 El manifiesto es JSON válido', true);
} catch (error) {
  assert('1.2 El manifiesto es JSON válido', false, error.message);
}

assert(
  '1.3 Declara nombre completo y nombre corto instalables',
  typeof manifest?.name === 'string' && manifest.name.includes('VendGuard')
    && typeof manifest?.short_name === 'string' && manifest.short_name.length > 0
    && manifest.short_name.length <= 12
);
assert(
  '1.4 Arranca en el ámbito raíz en modo aplicación independiente',
  manifest?.start_url?.startsWith('/') === true
    && manifest?.scope === '/'
    && manifest?.display === 'standalone'
);
assert(
  '1.5 Los colores coinciden con los tokens del sistema de diseño',
  manifest?.theme_color === '#2560ff' && manifest?.background_color === '#f9fafb',
  `theme=${manifest?.theme_color}, background=${manifest?.background_color}`
);
assert(
  '1.6 Declara los dos iconos vectoriales escalables con propósito maskable',
  Array.isArray(manifest?.icons)
    && manifest.icons.length === 2
    && manifest.icons.every((icon) => icon.type === 'image/svg+xml'
      && typeof icon.purpose === 'string'
      && icon.purpose.includes('maskable')
      && icon.src.startsWith('/assets/icons/'))
    && manifest.icons.some((icon) => icon.sizes === '192x192')
    && manifest.icons.some((icon) => icon.sizes === '512x512')
);

for (const icon of manifest?.icons ?? []) {
  const iconSource = existsSync(publicFile(icon.src)) ? readPublic(icon.src) : '';
  const expectedViewBox = icon.sizes.replace('x', ' ');
  assert(
    `1.7 El icono ${icon.sizes} existe y es un SVG autónomo`,
    iconSource.startsWith('<svg')
      && iconSource.includes(`viewBox="0 0 ${expectedViewBox}"`)
      && !/href="https?:/i.test(iconSource),
    icon.src
  );
}

console.log('\n--- 2. Service Worker: App Shell completo y Network-Only para /api/ (RF-01.2, Art. V.4) ---');

const swSource = readPublic('/sw.js');
const shellMatch = /const APP_SHELL_ASSETS = \[([\s\S]*?)\n\];/.exec(swSource);
const shellAssets = shellMatch
  ? [...shellMatch[1].matchAll(/'([^']+)'/g)].map((match) => match[1])
  : [];
const shellSet = new Set(shellAssets);

assert('2.1 El Service Worker declara la lista explícita del App Shell', shellAssets.length > 50, `entradas=${shellAssets.length}`);
assert(
  '2.2 El documento, el manifiesto y la caché versionada entran en el shell',
  shellSet.has('/index.html')
    && !shellSet.has('/')
    && shellSet.has('/manifest.webmanifest')
    && /const CACHE_NAME = 'vendguard-app-shell-v\d+'/.test(swSource)
);
assert(
  '2.2b La raíz no se precachea con el health JSON y `/index.html` se sirve como HTML',
  !shellSet.has('/')
    && !/cache\.add\('\/'\)/.test(swSource)
    && /'html'\s*=>\s*'text\/html; charset=UTF-8'/.test(readPublic('/index.php'))
);
assert(
  '2.3 Los módulos del modo sin conexión están precacheados',
  shellSet.has('/assets/js/utils/OfflineStorage.js')
    && shellSet.has('/assets/js/utils/OfflineSyncManager.js')
    && shellSet.has('/assets/js/components/OfflineSyncTrayModal.js')
);

const missingAssets = shellAssets.filter((asset) => {
  if (asset === '/') {
    return !existsSync(publicFile('/index.html'));
  }

  return !existsSync(publicFile(asset));
});
assert(
  '2.4 Todos los recursos del shell existen en disco (un 404 deja la app a medias sin red)',
  missingAssets.length === 0,
  missingAssets.join(', ')
);

const unresolvedImports = [];
for (const asset of shellAssets.filter((entry) => entry.endsWith('.js'))) {
  if (!existsSync(publicFile(asset))) {
    continue;
  }

  const moduleSource = readPublic(asset);
  for (const match of moduleSource.matchAll(/import\s+(?:[^'"]*?\s+from\s+)?['"]([^'"]+)['"]/g)) {
    const specifier = match[1];
    if (!specifier.startsWith('.')) {
      continue;
    }

    const resolved = posix.normalize(posix.join(posix.dirname(asset), specifier));
    if (!shellSet.has(resolved)) {
      unresolvedImports.push(`${resolved} (importado por ${asset.replace('/assets/js/', '')})`);
    }
  }
}
assert(
  '2.5 El shell es cerrado: todo módulo importado por un recurso precacheado también se precachea',
  unresolvedImports.length === 0,
  [...new Set(unresolvedImports)].join(' · ')
);

assert(
  '2.6 La superficie de API se declara como /api/ y el corte Network-Only precede a cualquier caché del handler',
  /const API_PATH_PREFIX = '\/api\/'/.test(swSource)
    && /request\.method !== 'GET' \|\| isApiRequest\(request\.url\)/.test(swSource)
    && (() => {
      const fetchHandler = swSource.slice(swSource.indexOf("self.addEventListener('fetch'"));
      return fetchHandler.indexOf('isApiRequest(request.url)') < fetchHandler.indexOf('cache.put(');
    })()
);

/*
 * Ejecución real del `fetch` del Service Worker con red y caché simuladas: la
 * regla Network-Only se certifica por comportamiento, no por texto. Una respuesta
 * de API cacheada sería la sesión de otro técnico en un dispositivo compartido
 * (Art. V.4), así que también se comprueba que `cache.put` no se invoca nunca
 * para `/api/*`.
 */
const handlers = {};
const cacheOpenCalls = [];
const cachePutCalls = [];
const cacheAdded = [];
let fetchCalls = [];
let fetchImpl = async () => ({ ok: true, type: 'basic', source: 'network', clone() { return this; } });
let cacheMatchImpl = async () => null;

const cacheStub = {
  add: async (asset) => { cacheAdded.push(asset); },
  match: async (request) => cacheMatchImpl(request),
  put: async (request, response) => { cachePutCalls.push({ request, response }); }
};

globalThis.self = {
  location: { origin: 'https://vendguard.test' },
  addEventListener: (type, handler) => { handlers[type] = handler; },
  skipWaiting: async () => {},
  clients: { claim: async () => {} }
};
globalThis.caches = {
  open: async (name) => { cacheOpenCalls.push(name); return cacheStub; },
  keys: async () => [],
  delete: async () => true
};
globalThis.fetch = async (request) => {
  fetchCalls.push(request);
  return fetchImpl(request);
};
globalThis.Response = globalThis.Response ?? { error: () => ({ __networkError: true }) };

await import('../../public/sw.js');

assert(
  '2.7 El Service Worker registra los ciclos install, activate y fetch',
  typeof handlers.install === 'function'
    && typeof handlers.activate === 'function'
    && typeof handlers.fetch === 'function'
);

function fetchEvent(request) {
  const waits = [];
  let responded = null;

  return {
    request,
    respondWith(promise) { responded = promise; },
    waitUntil(promise) { waits.push(promise); },
    async settle() {
      const body = await responded;
      await Promise.allSettled(waits);
      return body;
    }
  };
}

function resetCounters() {
  fetchCalls = [];
  cacheOpenCalls.length = 0;
  cachePutCalls.length = 0;
  cacheAdded.length = 0;
}

resetCounters();
const apiRequest = {
  method: 'GET',
  url: 'https://vendguard.test/api/technician/incidents/101/start',
  mode: 'cors'
};
const apiEvent = fetchEvent(apiRequest);
handlers.fetch(apiEvent);
const apiResponse = await apiEvent.settle();

assert(
  '2.8 Un GET de /api/ va a la red y jamás abre la caché',
  fetchCalls.length === 1
    && fetchCalls[0] === apiRequest
    && cacheOpenCalls.length === 0
    && cachePutCalls.length === 0
    && apiResponse?.source === 'network'
);

resetCounters();
fetchImpl = async () => { throw new Error('Sin cobertura'); };
const offlineApiEvent = fetchEvent({
  method: 'GET',
  url: 'https://vendguard.test/api/technician/offline/bootstrap',
  mode: 'cors'
});
handlers.fetch(offlineApiEvent);
let offlineApiResponse = null;
try {
  offlineApiResponse = await offlineApiEvent.settle();
} catch (error) {
  offlineApiResponse = { rejected: true };
}

assert(
  '2.9 Sin red la API falla: no se sustituye por una respuesta cacheada',
  cacheOpenCalls.length === 0
    && cachePutCalls.length === 0
    && (offlineApiResponse?.rejected === true || offlineApiResponse?.__networkError === true),
  JSON.stringify(offlineApiResponse)
);

resetCounters();
fetchImpl = async () => ({ ok: true, type: 'basic', source: 'network', clone() { return this; } });
const postEvent = fetchEvent({
  method: 'POST',
  url: 'https://vendguard.test/technician/incidents/101/resolve',
  mode: 'cors'
});
handlers.fetch(postEvent);
await postEvent.settle();
assert(
  '2.10 Cualquier método distinto de GET es Network-Only aunque no sea /api/',
  fetchCalls.length === 1 && cacheOpenCalls.length === 0 && cachePutCalls.length === 0
);

resetCounters();
cacheMatchImpl = async (request) => (String(request.url ?? request).includes('/assets/js/app.js')
  ? { ok: true, type: 'basic', source: 'cache' }
  : null);
const cachedRequest = { method: 'GET', url: 'https://vendguard.test/assets/js/app.js', mode: 'no-cors' };
const cachedEvent = fetchEvent(cachedRequest);
handlers.fetch(cachedEvent);
const cachedResponse = await cachedEvent.settle();
assert(
  '2.11 Un activo del shell se sirve desde la caché y se refresca en segundo plano',
  cachedResponse?.source === 'cache' && cachePutCalls.length === 1 && cachePutCalls[0].request === cachedRequest
);

resetCounters();
cacheMatchImpl = async (request) => (String(request.url ?? request).endsWith('/index.html')
  ? { ok: true, type: 'basic', source: 'shell' }
  : null);
fetchImpl = async () => { throw new Error('Sin cobertura'); };
const navigationEvent = fetchEvent({
  method: 'GET',
  url: 'https://vendguard.test/tecnico/ruta',
  mode: 'navigate'
});
handlers.fetch(navigationEvent);
const navigationResponse = await navigationEvent.settle();
assert(
  '2.12 Sin red, una navegación HTML cae al App Shell precacheado y no a la pantalla de error',
  navigationResponse?.source === 'shell'
);

console.log('\n--- 3. Precinto sanitario y ausencia de descarte silencioso (QA 1, EARS 03.4) ---');

const routeViewSource = stripComments(readPublic('/assets/js/views/TechnicianRouteView.js'));
const traySource = stripComments(readPublic('/assets/js/components/OfflineSyncTrayModal.js'));
const navbarSource = stripComments(readPublic('/assets/js/components/AppNavbar.js'));
const managerSource = stripComments(readPublic('/assets/js/utils/OfflineSyncManager.js'));

assert(
  '3.1 La vista muestra el bloque de precinto y lo enlaza a la confirmación expresa',
  routeViewSource.includes('data-testid="offline-sanitary-seal-block"')
    && /v-model="sanitarySealConfirmed"/.test(routeViewSource)
    && routeViewSource.includes('offlineSealRequiredForPause() && this.sanitarySealConfirmed !== true')
);
assert(
  '3.2 El precinto se exige antes de encolar, no después de enviar',
  /offlineSealRequiredForPause\(\)[\s\S]{0,400}sanitarySealConfirmed !== true/.test(routeViewSource)
    && routeViewSource.includes('sanitarySealConfirmed: this.sanitarySealConfirmed === true')
);
assert(
  '3.3 La bandeja explica el aviso ámbar, el bloqueo de 12 h y la falta de copia',
  traySource.includes('data-testid="offline-stale-warning"')
    && traySource.includes('data-testid="offline-readonly-warning"')
    && traySource.includes('data-testid="offline-missing-copy"')
);
assert(
  '3.4 El conflicto ofrece un único botón de volcado en un toque',
  traySource.includes('data-testid="offline-conflict-dump"')
    && traySource.includes('Volcar trabajo al expediente y cerrar conflicto')
    && (traySource.match(/data-testid="offline-conflict-dump"/g) ?? []).length === 1
);
assert(
  '3.5 No hay acción de descarte en la tarjeta de conflicto ni en el motor',
  !/offline-conflict-(discard|delete|remove)/.test(traySource)
    && !/discardConflict|dropConflict|discardMutation/.test(managerSource)
    && /async dumpConflict\(/.test(managerSource)
);
assert(
  '3.6 La barra superior bloquea el cierre de sesión con cola pendiente y limpia la copia al salir',
  navbarSource.includes('logout-blocked')
    && navbarSource.includes('offlineQueue?.total')
    && navbarSource.includes('clearTechnician')
);
assert(
  '3.7 La vista anuncia el albarán diferido y la re-autenticación del titular',
  routeViewSource.includes('Albarán oficial A4 disponible al sincronizar')
    && routeViewSource.includes('reauth')
);

console.log('\n--- 4. Sistema de diseño y trinquete de tokens (RNF-03) ---');

const hexPattern = /#[0-9a-fA-F]{3,8}\b/g;
const newModules = {
  'OfflineSyncTrayModal.js': traySource,
  'OfflineStorage.js': stripComments(readPublic('/assets/js/utils/OfflineStorage.js')),
  'OfflineSyncManager.js': managerSource
};

for (const [name, source] of Object.entries(newModules)) {
  const literals = source.match(hexPattern) ?? [];
  assert(`4.1 ${name} no introduce literales hexadecimales`, literals.length === 0, literals.join(', '));
}

assert(
  '4.2 La bandeja consume exclusivamente tokens de color, radio y tipografía',
  traySource.includes('var(--color-')
    && traySource.includes('var(--radius-interactive)')
    && traySource.includes('var(--radius-card)')
    && traySource.includes('var(--font-body)')
);
assert(
  '4.3 Los controles de la bandeja respetan el objetivo táctil de 44 px',
  (traySource.match(/min-height: 44px/g) ?? []).length >= 3
);
assert(
  '4.4 Las tarjetas conservan el borde hairline y el radio conservador de 8 px',
  /border: 1px solid var\(--color-hairline\)/.test(traySource)
    && traySource.includes('var(--radius-card)')
);

console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. T-OFFLINE-13 PWA ASSETS AND UI CERTIFIED.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
console.log('======================================================================\n');
process.exit(1);
