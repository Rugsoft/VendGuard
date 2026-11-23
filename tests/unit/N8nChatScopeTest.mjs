/**
 * VendGuard - N8n chat widget scope guard (N8nChatScopeTest.mjs)
 *
 * Certifica los hallazgos H-1, H-2 y H-3 del informe
 * `docs/informe_e2e_recaudacion_modulo_35.md`:
 *
 * H-2 · El asistente solo puede montarse para Coordinación y Técnico de Ruta
 *       (`specs/technical/chat_assistant_contracts.md` §1). El reponedor, la sede
 *       y cualquier otro rol quedan fuera, y un token antiguo en el almacén no
 *       puede resucitar el widget en una sesión que el contrato no contempla.
 *
 * H-1 · El lanzador del asistente es fijo en la esquina inferior derecha. Su
 *       contenedor es un cuadrado transparente de 64 px con `pointer-events: auto`
 *       que robaba clics a la columna «Acciones» de la tabla de recaudación en
 *       anchos estrechos. La hoja de estilos aísla el puntero a la burbuja y el
 *       panel, y la tabla reserva el pasillo del lanzador para poder desplazar su
 *       columna de acciones fuera de él.
 *
 * H-3 · La hoja de estilos, el bundle y el webhook salieron del código a
 *       `config/chatAssistant.js`, con validación y sobrescritura por despliegue.
 *       El montaje es diferido y la carga del bundle tiene techo de espera: un CDN
 *       lento o caído no retrasa el arranque de la consola, solo deja sin asistente.
 *
 * Dogma Vanilla: Node ESM nativo, sin dependencias.
 */

import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');

// DOM mínimo: el módulo consulta `typeof window` y `document` antes de tocar nada.
const appended = [];
globalThis.window = {};
globalThis.document = {
  head: { appendChild: (el) => appended.push(el) },
  createElement: () => ({ setAttribute() {}, appendChild() {}, remove() {}, id: '', rel: '', href: '' }),
  getElementById: () => null,
  querySelector: () => null,
  querySelectorAll: () => [],
  body: { appendChild: () => {} },
  addEventListener: () => {}
};

const { CHAT_ALLOWED_ROLES, isChatRoleAllowed, loadChatBundle, mountChatWidget, unmountChatWidget } =
  await import('../../public/assets/js/utils/n8nChat.js');
const { CHAT_ASSISTANT_DEFAULTS, resolveChatAssistantConfig } = await import(
  '../../public/assets/js/config/chatAssistant.js'
);

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) {
      console.error(`         Reason: ${details}`);
    }
    failures++;
  }
}

const wait = (ms) => new Promise((resolveWait) => setTimeout(resolveWait, ms));
const read = (relative) => readFileSync(resolve(root, relative), 'utf8');

console.log('======================================================================');
console.log(' VendGuard: N8n chat widget scope (H-1 pointer · H-2 roles · H-3 config)');
console.log('======================================================================\n');

// ─────────────────────────────────────────────────────────────────────────
console.log('--- 1. Alcance por rol (H-2) ---');
// ─────────────────────────────────────────────────────────────────────────

assert(
  "1.1 La lista blanca son exactamente Coordinación y Técnico de Ruta",
  Array.isArray(CHAT_ALLOWED_ROLES)
    && CHAT_ALLOWED_ROLES.length === 2
    && CHAT_ALLOWED_ROLES.includes('COORDINATOR')
    && CHAT_ALLOWED_ROLES.includes('TECHNICIAN'),
  JSON.stringify(CHAT_ALLOWED_ROLES)
);

assert(
  "1.2 Un coordinador tiene derecho al asistente",
  isChatRoleAllowed('COORDINATOR') === true
);

assert(
  "1.3 Un técnico de ruta tiene derecho al asistente",
  isChatRoleAllowed('TECHNICIAN') === true
);

assert(
  "1.4 Un reponedor no tiene derecho (H-2 reproducido en la corrida E2E)",
  isChatRoleAllowed('RESTOCKER') === false
);

assert(
  "1.5 Un responsable de sede no tiene derecho",
  isChatRoleAllowed('LOCATION_MANAGER') === false
);

assert(
  "1.6 Una sesión sin rol no tiene derecho",
  isChatRoleAllowed(undefined) === false && isChatRoleAllowed(null) === false && isChatRoleAllowed('') === false
);

assert(
  "1.7 El rol se compara sin distinguir mayúsculas",
  isChatRoleAllowed('coordinator') === true && isChatRoleAllowed(' Technician ') === true
);

assert(
  "1.8 La lista blanca está congelada (no se muta desde la consola)",
  Object.isFrozen(CHAT_ALLOWED_ROLES)
);

// ─────────────────────────────────────────────────────────────────────────
console.log('\n--- 2. El montaje respeta el alcance (H-2) ---');
// ─────────────────────────────────────────────────────────────────────────

mountChatWidget('auth_token_de_prueba', { role: 'RESTOCKER' });
await wait(30);
assert(
  "2.1 Un reponedor no inyecta la hoja de estilos del widget",
  appended.length === 0,
  `elementos añadidos: ${appended.length}`
);

mountChatWidget('auth_token_de_prueba', { role: 'LOCATION_MANAGER' });
await wait(30);
assert(
  "2.2 Un responsable de sede tampoco monta nada",
  appended.length === 0
);

mountChatWidget('', { role: 'COORDINATOR' });
await wait(30);
assert(
  "2.3 Sin token no se monta el widget aunque el rol tenga derecho",
  appended.length === 0
);

// Con rol autorizado el módulo programa la carga del bundle remoto. En Node esa
// URL no es un especificador válido, así que la importación falla y el módulo lo
// registra sin romper la sesión: es la prueba de que el rol sí pasó la guarda.
globalThis.window.VENDGUARD_CHAT_CONFIG = { mountDelayMs: 0, loadTimeoutMs: 40 };
let warned = '';
const originalWarn = console.warn;
console.warn = (...args) => {
  warned += args.map((a) => String(a)).join(' ');
};
mountChatWidget('auth_token_de_prueba', { role: 'COORDINATOR' });

assert(
  "2.4 El montaje devuelve el control sin esperar al bundle (arranque libre de CDN)",
  appended.length === 0 && warned === ''
);

await wait(120);
console.warn = originalWarn;

assert(
  "2.5 Un coordinador supera la guarda y solo avisa cuando el bundle no llega",
  warned.includes('el asistente no está disponible'),
  warned.slice(0, 120)
);

// ─────────────────────────────────────────────────────────────────────────
console.log('\n--- 3. Configuración del asistente (H-3) ---');
// ─────────────────────────────────────────────────────────────────────────

const widgetSource = read('public/assets/js/utils/n8nChat.js');
const configSource = read('public/assets/js/config/chatAssistant.js');
const swSource = read('public/sw.js');

assert(
  "3.1 n8nChat.js ya no fija el CDN ni el webhook en el código",
  !widgetSource.includes('jsdelivr')
    && !widgetSource.includes('n8n.cloud')
    && !/WEBHOOK_URL\s*=/.test(widgetSource)
    && !/BUNDLE_URL\s*=/.test(widgetSource)
);

assert(
  "3.2 La configuración declara hoja, bundle y webhook por defecto",
  CHAT_ASSISTANT_DEFAULTS.styleHref.startsWith('https://')
    && CHAT_ASSISTANT_DEFAULTS.bundleUrl.startsWith('https://')
    && CHAT_ASSISTANT_DEFAULTS.webhookUrl.startsWith('https://')
    && CHAT_ASSISTANT_DEFAULTS.enabled === true
);

assert(
  "3.3 Sin sobrescrituras se usan los valores por defecto",
  JSON.stringify(resolveChatAssistantConfig()) === JSON.stringify({ ...CHAT_ASSISTANT_DEFAULTS })
);

const overridden = resolveChatAssistantConfig({
  webhookUrl: 'https://hooks.example.com/webhook/test/chat',
  bundleUrl: 'https://static.example.com/chat.bundle.es.js',
  styleHref: 'https://static.example.com/style.css',
  loadTimeoutMs: 250,
  mountDelayMs: 0
});

assert(
  "3.4 Un despliegue puede reapuntar los tres destinos sin tocar el código",
  overridden.webhookUrl === 'https://hooks.example.com/webhook/test/chat'
    && overridden.bundleUrl === 'https://static.example.com/chat.bundle.es.js'
    && overridden.styleHref === 'https://static.example.com/style.css'
    && overridden.loadTimeoutMs === 250
    && overridden.mountDelayMs === 0
);

const insecure = resolveChatAssistantConfig({
  webhookUrl: 'http://insecure.example.com/chat',
  bundleUrl: '/assets/js/vendor/local.js',
  loadTimeoutMs: 0
});

assert(
  "3.5 Una configuración insegura o inválida degrada al valor por defecto",
  insecure.webhookUrl === CHAT_ASSISTANT_DEFAULTS.webhookUrl
    && insecure.bundleUrl === CHAT_ASSISTANT_DEFAULTS.bundleUrl
    && insecure.loadTimeoutMs === CHAT_ASSISTANT_DEFAULTS.loadTimeoutMs
);

assert(
  "3.6 El asistente puede retirarse por completo con enabled: false",
  resolveChatAssistantConfig({ enabled: false }).enabled === false
);

assert(
  "3.7 La configuración está congelada (no se muta en caliente)",
  Object.isFrozen(CHAT_ASSISTANT_DEFAULTS) && Object.isFrozen(overridden)
);

// ─────────────────────────────────────────────────────────────────────────
console.log('\n--- 4. Un CDN caído no retrasa el arranque (H-3) ---');
// ─────────────────────────────────────────────────────────────────────────

const hungStart = Date.now();
const hungResult = await loadChatBundle('https://cdn.example.com/chat.js', 60, () => new Promise(() => {}));
const hungElapsed = Date.now() - hungStart;

assert(
  "4.1 Un bundle que nunca responde se rinde en el techo de espera",
  hungResult === null && hungElapsed >= 50 && hungElapsed < 1000,
  `transcurrido: ${hungElapsed} ms`
);

const failingResult = await loadChatBundle('https://cdn.example.com/chat.js', 200, () => Promise.reject(new Error('offline')));
assert(
  "4.2 Un CDN inalcanzable se resuelve como asistente no disponible, sin lanzar",
  failingResult === null
);

const fakeBundle = { createChat: () => {} };
const loadedResult = await loadChatBundle('https://cdn.example.com/chat.js', 200, () => Promise.resolve(fakeBundle));
assert(
  "4.3 Un bundle válido se entrega cuando llega dentro del techo",
  loadedResult === fakeBundle
);

const slowResult = await loadChatBundle('https://cdn.example.com/chat.js', 200, () => Promise.resolve({ notAWidget: true }));
assert(
  "4.4 Un módulo sin createChat no se acepta como bundle del asistente",
  slowResult === null
);

appended.length = 0;
globalThis.window.VENDGUARD_CHAT_CONFIG = { mountDelayMs: 40, loadTimeoutMs: 40 };
unmountChatWidget();
mountChatWidget('auth_token_de_prueba', { role: 'COORDINATOR' });
unmountChatWidget();
await wait(140);

assert(
  "4.5 Cerrar sesión cancela el montaje programado y no deja restos",
  appended.length === 0,
  `elementos añadidos: ${appended.length}`
);

assert(
  "4.6 El Service Worker precachea el módulo de configuración (shell cerrado)",
  swSource.includes('/assets/js/config/chatAssistant.js')
    && /vendguard-app-shell-v29/.test(swSource)
);

assert(
  "4.7 El webhook y el CDN solo aparecen en el módulo de configuración",
  configSource.includes('cdn.jsdelivr.net')
    && configSource.includes('n8n.cloud')
    && !widgetSource.includes('cdn.jsdelivr.net')
);

delete globalThis.window.VENDGUARD_CHAT_CONFIG;

// ─────────────────────────────────────────────────────────────────────────
console.log('\n--- 5. Puntero del widget y pasillo de la tabla (H-1) ---');
// ─────────────────────────────────────────────────────────────────────────

const styles = read('public/assets/css/styles.css');
const tab = read('public/assets/js/components/CoordinatorProductLoadingTab.js');

assert(
  "5.1 El contenedor del widget no captura el puntero",
  /\.chat-window-wrapper\s*\{[^}]*pointer-events:\s*none\s*!important/s.test(styles)
);

assert(
  "5.2 La burbuja y el panel sí capturan el puntero",
  /\.chat-window-wrapper\s+\.chat-window,\s*[\r\n\s]*\.chat-window-wrapper\s+\.chat-window-toggle\s*\{\s*[\r\n\s]*pointer-events:\s*auto\s*!important/s.test(styles)
);

assert(
  "5.3 La tabla de recaudación es la que monta el contenedor con pasillo",
  /class="vg-card vg-cash-table-scroll">\s*<table class="vg-table" data-testid="product-loading-cash-table">/.test(tab)
);

assert(
  "5.4 El pasillo se aplica en anchos estrechos con el ancho del lanzador",
  /@media\s*\(max-width:\s*1024px\)\s*\{\s*\.vg-cash-table-scroll\s*\{\s*padding-right:\s*88px;/s.test(styles)
);

assert(
  "5.5 El contenedor conserva el desplazamiento horizontal de la tabla",
  /\.vg-cash-table-scroll\s*\{[^}]*overflow-x:\s*auto/s.test(styles)
);

assert(
  "5.6 El módulo del widget declara la guarda de rol",
  /isChatRoleAllowed\(user\?\.role\)/.test(widgetSource)
);

console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESUMEN: ${assertions} aserciones superadas exitosamente (100% PASS).`);
  console.log(' H-1, H-2 y H-3 CERTIFICADOS: sin robo de clics, sin roles fuera de contrato y sin CDN en el arranque.');
} else {
  console.error(` RESUMEN: ${failures} de ${assertions} aserciones fallaron.`);
}
console.log('======================================================================');

process.exit(failures === 0 ? 0 : 1);
