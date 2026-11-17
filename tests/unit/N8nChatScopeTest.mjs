/**
 * VendGuard - N8n chat widget scope guard (N8nChatScopeTest.mjs)
 *
 * Certifica los dos hallazgos H-1 y H-2 del informe
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
  createElement: () => ({ setAttribute() {}, appendChild() {}, id: '', rel: '', href: '' }),
  querySelector: () => null,
  querySelectorAll: () => [],
  body: { appendChild: () => {} },
  addEventListener: () => {}
};

const { CHAT_ALLOWED_ROLES, isChatRoleAllowed, mountChatWidget } = await import(
  '../../public/assets/js/utils/n8nChat.js'
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

console.log('======================================================================');
console.log(' VendGuard: N8n chat widget scope (H-1 pointer isolation · H-2 roles)');
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

await mountChatWidget('auth_token_de_prueba', { role: 'RESTOCKER' });
assert(
  "2.1 Un reponedor no inyecta la hoja de estilos del widget",
  appended.length === 0,
  `elementos añadidos: ${appended.length}`
);

await mountChatWidget('auth_token_de_prueba', { role: 'LOCATION_MANAGER' });
assert(
  "2.2 Un responsable de sede tampoco monta nada",
  appended.length === 0
);

await mountChatWidget('', { role: 'COORDINATOR' });
assert(
  "2.3 Sin token no se monta el widget aunque el rol tenga derecho",
  appended.length === 0
);

// Con rol autorizado el módulo intenta cargar el bundle remoto. En Node esa
// URL no es un especificador válido, así que la importación falla y el módulo
// lo registra sin romper la sesión: es la prueba de que el rol sí pasó la guarda.
let warned = '';
const originalWarn = console.warn;
console.warn = (...args) => {
  warned += args.map((a) => String(a)).join(' ');
};
await mountChatWidget('auth_token_de_prueba', { role: 'COORDINATOR' });
console.warn = originalWarn;

assert(
  "2.4 Un coordinador supera la guarda y falla solo al cargar el bundle remoto",
  warned.includes('No se pudo inicializar el widget de chat'),
  warned.slice(0, 120)
);

// ─────────────────────────────────────────────────────────────────────────
console.log('\n--- 3. Puntero del widget y pasillo de la tabla (H-1) ---');
// ─────────────────────────────────────────────────────────────────────────

const styles = readFileSync(resolve(root, 'public/assets/css/styles.css'), 'utf8');
const tab = readFileSync(resolve(root, 'public/assets/js/components/CoordinatorProductLoadingTab.js'), 'utf8');
const widget = readFileSync(resolve(root, 'public/assets/js/utils/n8nChat.js'), 'utf8');

assert(
  "3.1 El contenedor del widget no captura el puntero",
  /\.chat-window-wrapper\s*\{[^}]*pointer-events:\s*none\s*!important/s.test(styles)
);

assert(
  "3.2 La burbuja y el panel sí capturan el puntero",
  /\.chat-window-wrapper\s+\.chat-window,\s*[\r\n\s]*\.chat-window-wrapper\s+\.chat-window-toggle\s*\{\s*[\r\n\s]*pointer-events:\s*auto\s*!important/s.test(styles)
);

assert(
  "3.3 La tabla de recaudación es la que monta el contenedor con pasillo",
  /class="vg-card vg-cash-table-scroll">\s*<table class="vg-table" data-testid="product-loading-cash-table">/.test(tab)
);

assert(
  "3.4 El pasillo se aplica en anchos estrechos con el ancho del lanzador",
  /@media\s*\(max-width:\s*1024px\)\s*\{\s*\.vg-cash-table-scroll\s*\{\s*padding-right:\s*88px;/s.test(styles)
);

assert(
  "3.5 El contenedor conserva el desplazamiento horizontal de la tabla",
  /\.vg-cash-table-scroll\s*\{[^}]*overflow-x:\s*auto/s.test(styles)
);

assert(
  "3.6 El módulo del widget declara la guarda de rol",
  /isChatRoleAllowed\(user\?\.role\)/.test(widget)
);

console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESUMEN: ${assertions} aserciones superadas exitosamente (100% PASS).`);
  console.log(' H-1 y H-2 CERTIFICADOS: el chat no roba clics ni se monta fuera de su contrato.');
} else {
  console.error(` RESUMEN: ${failures} de ${assertions} aserciones fallaron.`);
}
console.log('======================================================================');

process.exit(failures === 0 ? 0 : 1);
