/**
 * VendGuard - Guarda de retirada de la paleta heredada `.btn`
 * (LegacyButtonPaletteRetirementTest.mjs)
 *
 * Cierra por congelación una deuda de mantenimiento, no un defecto de aspecto: el
 * frontend tenía dos sistemas de botones en paralelo. Uno, el del panel del
 * coordinador, ya hablaba el vocabulario del sistema (`vg-btn` y sus peldaños y tonos,
 * vigilado por `CoordinatorButtonVariantsGuardTest`). El otro, heredado de la primera
 * maqueta, era `.btn` con su propia altura (36 px), sus propios hex por variante y su
 * propio redondeo; y sobre él convivían `.btn-close`, `.btn-group`, `.qr-btn-block` y
 * las utilidades de Bootstrap que arrastraba (`p-0`, `fw-bold`, `w-100`).
 *
 * Las plantillas `Admin*`, la barra superior, el portal de sede, la vista del técnico
 * y la página pública del QR elegían entre uno y otro según quién las escribiera: la
 * misma acción («Cancelar» de un diálogo, «Cerrar» de un aviso, una acción de fila)
 * medía y se pintaba distinto en cada pantalla, y ningún cambio de diseño se propagaba.
 * El 2026-10-12 se retiró el prefijo entero.
 *
 * Lo que esta guarda congela: **el prefijo retirado no vuelve ni a las plantillas ni a
 * la hoja de estilos**, y el vocabulario que lo sustituye (`.vg-btn`, el glifo de cierre
 * `.vg-btn-close`, el cierre inverso `vg-btn-ghost-inverse` y el grupo `vg-row-actions`)
 * sigue declarado y en uso, para que la retirada no deje un hueco silencioso.
 *
 * Ámbito del barrido (deliberadamente explícito):
 *   - Plantillas: `public/assets/js/views`, `public/assets/js/components` y
 *     `public/assets/js/utils`, más las dos plantillas de entrada `public/index.html` y
 *     `public/index.php`. Se salta `public/assets/js/vendor` (Vue embebido, no es código
 *     del proyecto).
 *   - Hojas: todas las `.css` de `public/assets/css`.
 *   - Fuera del ámbito, y por qué: `public/docs/guia_rapida_taller_repuestos_a4.html` es
 *     un documento imprimible autocontenido con su propio `<style>` y su propia paleta de
 *     papel; no carga `design-tokens.css` ni es una pantalla del producto.
 *
 * Cómo decide (simple y auditable): se leen los atributos `class` y `:class` de cada
 * fichero, se parten en tokens por todo lo que no sea `[A-Za-z0-9_-]` (así también se
 * caza el token dentro de un objeto `:class="{ 'btn-primary': cond }"`) y se compara
 * contra el conjunto retirado. En las hojas se buscan selectores `.btn…` / `.qr-btn…`
 * con los comentarios ya despojados: el acta de la retirada puede nombrar los tokens
 * que retiró sin que eso cuente como declararlos. `data-testid="btn-…"` no es una
 * clase y no entra en el barrido.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const PROJECT_ROOT = fileURLToPath(new URL('../../', import.meta.url));

/** Tokens de clase que la retirada deja fuera del frontend, para siempre. */
const RETIRED_TOKENS = new Set([
  'btn',
  'btn-sm', 'btn-lg', 'btn-block', 'btn-info', 'btn-dark', 'btn-light',
  'btn-primary', 'btn-secondary', 'btn-danger', 'btn-success', 'btn-warning',
  'btn-outline-primary', 'btn-outline-secondary', 'btn-outline-danger',
  'btn-outline-success', 'btn-outline-warning',
  'btn-link', 'btn-ghost',
  'btn-close', 'btn-close-white',
  'btn-group', 'btn-group-sm',
  'qr-btn-block', 'qr-btn-submit'
]);

/** Directorios y ficheros del barrido de plantillas. */
const TEMPLATE_DIRS = ['public/assets/js/views', 'public/assets/js/components', 'public/assets/js/utils'];
const TEMPLATE_FILES = ['public/index.html', 'public/index.php'];
/** Vue embebido: no es código del proyecto. */
const EXCLUDED_DIR = 'vendor';

/** Atributo `class` o `:class`, con comillas simples o dobles. */
const CLASS_ATTRIBUTE = /(?<![-\w])(?::?class)=("([^"]*)"|'([^']*)')/g;
/** Selector de la paleta retirada: `.btn`, `.btn-primary`, `.qr-btn-block`… */
const RETIRED_SELECTOR = /\.(qr-)?btn(?:-[A-Za-z0-9_-]+)?(?![A-Za-z0-9_-])/g;

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

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - Retirada de la paleta heredada .btn');
console.log('======================================================================\n');

/**
 * Tokens de un valor de atributo `class` / `:class`.
 *
 * @param {string} value
 * @returns {string[]}
 */
function classTokens(value) {
  return value.split(/[^A-Za-z0-9_-]+/).filter((token) => token !== '');
}

/**
 * Tokens retirados que aparecen en un atributo de clase.
 *
 * @param {string} value
 * @returns {string[]}
 */
function retiredTokensIn(value) {
  return classTokens(value).filter((token) => RETIRED_TOKENS.has(token));
}

/**
 * Plantillas del frontend: `{ file, source }`.
 *
 * @returns {Array<{file:string, source:string}>}
 */
function templateSources() {
  const sources = [];
  const visit = (relative) => {
    for (const entry of readdirSync(join(PROJECT_ROOT, relative), { withFileTypes: true })) {
      if (entry.isDirectory()) {
        if (entry.name === EXCLUDED_DIR) {
          continue;
        }
        visit(`${relative}/${entry.name}`);
        continue;
      }
      if (entry.isFile() && entry.name.endsWith('.js')) {
        sources.push({ file: `${relative}/${entry.name}`, source: readFileSync(join(PROJECT_ROOT, relative, entry.name), 'utf8') });
      }
    }
  };

  for (const directory of TEMPLATE_DIRS) {
    visit(directory);
  }
  for (const file of TEMPLATE_FILES) {
    sources.push({ file, source: readFileSync(join(PROJECT_ROOT, file), 'utf8') });
  }

  return sources;
}

/**
 * Hallazgos de paleta retirada en una plantilla.
 *
 * @param {string} source
 * @param {string} label
 * @returns {string[]}
 */
function retiredInTemplate(source, label) {
  const findings = [];
  for (const match of source.matchAll(CLASS_ATTRIBUTE)) {
    const value = match[2] !== undefined ? match[2] : match[3];
    const line = source.slice(0, match.index).split('\n').length;
    for (const token of retiredTokensIn(value)) {
      findings.push(`${label}:${line} (${token})`);
    }
  }
  return findings;
}

/**
 * Hojas de estilo del frontend y sus selectores de la paleta retirada.
 *
 * @returns {Array<{file:string, selectors:string[]}>}
 */
function styleSheets() {
  const directory = join(PROJECT_ROOT, 'public/assets/css');
  const sheets = [];
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    if (!entry.isFile() || !entry.name.endsWith('.css')) {
      continue;
    }
    sheets.push({ file: `public/assets/css/${entry.name}`, source: readFileSync(join(directory, entry.name), 'utf8') });
  }
  return sheets;
}

/**
 * Selectores retirados declarados en una hoja.
 *
 * @param {string} source
 * @returns {string[]}
 */
function retiredSelectorsIn(source) {
  const selectors = [];
  // Sin comentarios: el acta de la retirada nombra los tokens que retiró.
  const body = source.replace(/\/\*[\s\S]*?\*\//g, ' ');
  for (const match of body.matchAll(RETIRED_SELECTOR)) {
    const token = match[0].slice(1);
    if (RETIRED_TOKENS.has(token)) {
      selectors.push(token);
    }
  }
  return selectors;
}

const templates = templateSources();
const sheets = styleSheets();
const templateInventory = templates.map((entry) => entry.source).join('\n');
const cssSource = sheets.map((entry) => entry.source).join('\n');

// ─── 1. Las plantillas ya no declaran la paleta retirada ──────────────────────

console.log('--- 1. Ninguna plantilla del frontend declara la paleta retirada ---\n');

assert('1.1 El barrido cubre el frontend entero (al menos 50 plantillas)',
  templates.length >= 50,
  `plantillas: ${templates.length}`);

const templateFindings = templates.flatMap((entry) => retiredInTemplate(entry.source, entry.file));
assert('1.2 Ningún atributo `class` / `:class` declara un token de la paleta retirada',
  templateFindings.length === 0,
  templateFindings.slice(0, 8).join(' · '));

// ─── 2. La hoja ya no dibuja la paleta retirada ───────────────────────────────

console.log('\n--- 2. Ninguna hoja del frontend dibuja la paleta retirada ---\n');

assert('2.1 El barrido lee las hojas del frontend (al menos una)',
  sheets.length >= 1,
  `hojas: ${sheets.length}`);

const selectorFindings = sheets.flatMap((entry) => retiredSelectorsIn(entry.source).map((token) => `${entry.file} (.${token})`));
assert('2.2 Ninguna hoja declara reglas `.btn…` ni `.qr-btn…`: el prefijo se retiró de verdad',
  selectorFindings.length === 0,
  selectorFindings.slice(0, 8).join(' · '));

assert('2.3 `.input-group` sigue encajando el botón adosado con la clase vigente',
  cssSource.includes('.input-group > .vg-btn'),
  'la regla quedó sin retargetear al retirar el prefijo');

assert('2.4 El cierre de un aviso descartable sigue anclado por la clase vigente',
  cssSource.includes('.alert-dismissible .vg-btn-close'),
  'la regla quedó sin retargetear al retirar el prefijo');

// ─── 3. El vocabulario que sustituye a la paleta sigue vivo ───────────────────

console.log('\n--- 3. El vocabulario vigente no quedó a medias ---\n');

assert('3.1 La hoja declara la clase base `.vg-btn` y el frontend la consume',
  /(?:^|[\s,{])\.vg-btn\s*\{/.test(cssSource) && classTokens(templateInventory).includes('vg-btn'));

assert('3.2 La hoja declara el glifo de cierre `.vg-btn-close::before` con una sola ✕',
  (cssSource.match(/\.vg-btn-close::before\s*\{[^}]*content:\s*'✕'/g) || []).length === 1,
  'el glifo compartido de cierre no está declarado exactamente una vez');

assert('3.3 El glifo de cierre lo consumen las plantillas (ninguna lo reimplementa)',
  classTokens(templateInventory).includes('vg-btn-close'));

assert('3.4 La hoja declara el cierre sobre cabecera oscura y hay plantillas que lo usan',
  /(?:^|[\s,{])\.vg-btn-ghost-inverse\s*\{/.test(cssSource)
  && classTokens(templateInventory).includes('vg-btn-ghost-inverse'));

assert('3.5 La hoja declara el grupo de acciones `vg-row-actions` y sustituye a `.btn-group`',
  /(?:^|[\s,{])\.vg-row-actions\s*\{/.test(cssSource)
  && classTokens(templateInventory).includes('vg-row-actions'));

assert('3.6 El tono de aviso (sólido y suave) está declarado y lo consumen las acciones de cautela',
  /(?:^|[\s,{])\.vg-btn-warning\s*\{/.test(cssSource)
  && /(?:^|[\s,{])\.vg-btn-warning-soft\s*\{/.test(cssSource)
  && classTokens(templateInventory).includes('vg-btn-warning')
  && classTokens(templateInventory).includes('vg-btn-warning-soft'),
  'el tono de aviso quedó sin declarar o sin usar');

// ─── 4. La guarda se prueba a sí misma ────────────────────────────────────────

console.log('\n--- 4. La guarda detecta el defecto que vigila (sonda sintética) ---\n');

const dirtyTemplate = '<button class="btn btn-primary btn-sm" @click="save()">Guardar</button>';
const dynamicTemplate = '<button class="vg-btn" :class="{ \'btn-danger\': isRisky }">Borrar</button>';
const closeTemplate = '<button class="btn-close btn-close-white" aria-label="Cerrar"></button>';
const blockTemplate = '<button class="btn btn-primary qr-btn-block">Cerrar ventana</button>';
const cleanTemplate = '<button class="vg-btn vg-btn-sm vg-btn-secondary" :class="isRisky ? \'vg-btn-danger-soft\' : \'vg-btn-secondary\'" data-testid="btn-close-receipt">Cerrar</button>'
  + '<button class="territorial-map-btn" data-testid="btn-zoom-in">+</button>';

assert('4.1 Detecta una clase retirada suelta en un atributo `class`',
  retiredInTemplate(dirtyTemplate, 'sonda').length === 3,
  JSON.stringify(retiredInTemplate(dirtyTemplate, 'sonda')));

assert('4.2 Detecta el token escondido en un objeto `:class`',
  retiredInTemplate(dynamicTemplate, 'sonda').join() === 'sonda:1 (btn-danger)',
  JSON.stringify(retiredInTemplate(dynamicTemplate, 'sonda')));

assert('4.3 Detecta el cierre heredado y el botón de bloque del QR',
  retiredInTemplate(closeTemplate, 'sonda').length === 2
  && retiredInTemplate(blockTemplate, 'sonda').join() === 'sonda:1 (btn),sonda:1 (btn-primary),sonda:1 (qr-btn-block)',
  JSON.stringify(retiredInTemplate(blockTemplate, 'sonda')));

assert('4.4 No produce falsos positivos con `vg-btn-*`, `territorial-map-btn`, `btn-refresh` ni `data-testid="btn-…"`',
  retiredInTemplate(cleanTemplate, 'sonda').length === 0
  && retiredInTemplate('<button class="vg-btn vg-btn-secondary btn-refresh">Actualizar</button>', 'sonda').length === 0,
  JSON.stringify(retiredInTemplate(cleanTemplate, 'sonda')));

const syntheticSheet = '.btn { height: 36px; }\n.vg-btn { height: var(--control-height-md); }\n.qr-btn-block { width: 100%; }';
assert('4.5 Detecta la regla retirada en una hoja sintética y no confunde `vg-btn` con `.btn`',
  retiredSelectorsIn(syntheticSheet).join() === 'btn,qr-btn-block',
  JSON.stringify(retiredSelectorsIn(syntheticSheet)));

assert('4.6 Un acta dentro de un comentario CSS no cuenta como declaración',
  retiredSelectorsIn('/* se retiró .btn, .btn-primary y .qr-btn-block */\n.vg-btn { height: 36px; }').length === 0,
  JSON.stringify(retiredSelectorsIn('/* se retiró .btn */\n.vg-btn { height: 36px; }')));

// Summary
console.log('\n======================================================================');
console.log(` Aserciones: ${assertions} | Fallos: ${failures} | Plantillas: ${templates.length} | Hojas: ${sheets.length}`);
console.log(` Tokens retirados vigilados: ${RETIRED_TOKENS.size}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. La paleta heredada .btn no está en el frontend.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULTADO: FALLAN ${failures} ASERCIONES.`);
  console.log('======================================================================\n');
  process.exit(1);
}
