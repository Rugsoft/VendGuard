/**
 * VendGuard - Guarda de variantes de botón del panel del coordinador
 * (CoordinatorButtonVariantsGuardTest.mjs)
 *
 * Cierra por congelación un defecto de mantenimiento, no de aspecto: cada botón del
 * panel declaraba su propia caja en línea. Un recuento del 2026-10-12 encontró 104
 * botones con `style="…"` y 618 declaraciones de presentación repartidas entre once
 * plantillas: cinco alturas distintas (30, 32, 34, 36 y 38 px) para el mismo papel de
 * acción, tres cuerpos de letra (12, 13 y 14 px), seis rellenos horizontales y los
 * tonos repetidos con hex ajenos al triplete semántico. Dos consecuencias, ambas
 * visibles en producción:
 *
 *   - El sistema de diseño no gobernaba nada: cambiar la altura de una acción de fila
 *     exigía 33 ediciones y ninguna garantía de que la siguiente no volviera a 32.
 *   - Las mismas dos acciones tenían cuerpos distintos según la plantilla que las
 *     pintara, así que el panel se veía como dos paneles.
 *
 * La regla que congela esta guarda: **un botón del panel se viste con una variante del
 * sistema, nunca con su propia geometría en línea.** La escalera vive en
 * `public/assets/css/design-tokens.css` y se lee de los tokens de control
 * (`--control-height-*`, `--control-padding-*`, `--control-font-*`, `--control-gap-*`).
 *
 * Cómo decide (deliberadamente simple y auditable):
 *   - Barrido: `public/assets/js/views` y `public/assets/js/components`, ficheros
 *     `Coordinator*.js` (el panel del coordinador).
 *   - Se extraen las etiquetas `<button …>` respetando comillas (para no tropezar con
 *     manejadores del tipo `@click="() => f(a > b)"`).
 *   - Es riesgo una declaración **de presentación** en el `style` estático: altura,
 *     ancho, relleno, cuerpo de letra, peso, radio, caja, borde, fondo o color. El único
 *     estilo estático admitido es la **colocación** (`position`, `top`, `right`, `bottom`,
 *     `left`, `inset`, `z-index`): dónde se ancla un mando flotante no es cómo se dibuja.
 *   - Es riesgo, además, esa misma geometría dentro de un objeto `:style` dinámico. El
 *     **color sí puede venir de datos** (`pausedTokens.color`): un expediente en espera
 *     se pinta con el color de su estado, y eso es estado, no diseño. Lo que no entra
 *     nunca por la puerta dinámica es la caja ni una propiedad abreviada que la lleve
 *     dentro (`border`, `background`).
 *   - Es riesgo un literal hex en línea, en cualquiera de las dos formas: el color entra
 *     por token o por variante de tono, nunca escrito a mano en la plantilla.
 *   - Es riesgo un botón sin la clase base `vg-btn`.
 *   - Y cada variante declarada en la hoja debe estar **en uso** en alguna plantilla del
 *     panel: una variante muerta es sistema de diseño de mentira.
 *   - Es riesgo, además, que un botón se pinte el **tono de aviso** a mano (un token
 *     `--color-warning*` o un objeto de tokens ámbar): el ámbar entra por
 *     `vg-btn-warning-soft` o `vg-btn-warning`, y así el chip de estado ámbar de al lado
 *     sigue siendo el único que avisa.
 *
 * La guarda se prueba a sí misma sobre muestras sintéticas (sección 3): un escáner roto
 * que devolviera «todo bien» sin mirar nada pasaría la suite, y eso no certifica nada.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const PROJECT_ROOT = fileURLToPath(new URL('../../', import.meta.url));
const SCAN_DIRS = ['public/assets/js/views', 'public/assets/js/components'];
const FILE_PREFIX = 'Coordinator';
const CSS_PATH = join(PROJECT_ROOT, 'public/assets/css/design-tokens.css');

/**
 * Colocación: lo único que un botón del panel puede seguir diciendo en línea.
 * Anclar un mando flotante es una decisión de dónde, no de cómo se dibuja.
 */
const PLACEMENT_PROPERTIES = new Set([
  'position', 'top', 'right', 'bottom', 'left', 'inset', 'z-index'
]);

/**
 * Caja y pintura: prohibidas en línea, tanto en `style` como en `:style`. En el objeto
 * dinámico el color se sustrae aparte, porque un estado sí puede traer su propio color.
 */
const CONTROL_PROPERTIES = new Set([
  'height', 'minheight', 'maxheight',
  'width', 'minwidth', 'maxwidth',
  'padding', 'paddingtop', 'paddingright', 'paddingbottom', 'paddingleft',
  'margin', 'margintop', 'marginright', 'marginbottom', 'marginleft',
  'font', 'fontsize', 'fontweight', 'lineheight', 'fontfamily',
  'border', 'borderwidth', 'borderstyle', 'borderradius',
  'display', 'alignitems', 'justifycontent', 'gap', 'flex', 'flexwrap',
  'cursor', 'background', 'backgroundcolor', 'color'
]);

/** En un `:style` dinámico se admite el color de estado, y sólo eso. */
const DYNAMIC_PAINT_ALLOWED = new Set(['color', 'backgroundcolor']);

/**
 * Tonos de aviso. En un botón del panel entran por variante (`vg-btn-warning-soft`
 * o `vg-btn-warning`), nunca escritos a mano: un mando ámbar improvisado convive con
 * el chip de estado ámbar de al lado y el lector ya no sabe cuál de los dos es el
 * aviso. Un aviso, un tono, una definición.
 */
const WARNING_TONE_PATTERN = /--color-warning|pausedTokens|amberTokens/;

/** Variantes que el sistema declara y que el panel consume. */
const DECLARED_VARIANTS = [
  'vg-btn-primary',
  'vg-btn-secondary',
  'vg-btn-danger',
  'vg-btn-neutral',
  'vg-btn-info-soft',
  'vg-btn-success-soft',
  'vg-btn-danger-soft',
  'vg-btn-primary-outline',
  'vg-btn-warning-soft',
  'vg-btn-ghost',
  'vg-btn-ghost-danger',
  'vg-btn-ghost-success',
  'vg-btn-ghost-muted',
  'vg-btn-icon',
  'vg-btn-tab',
  'vg-btn-tab-active',
  'vg-chip',
  'vg-chip-danger'
];

/** Tokens de la escalera de controles. */
const CONTROL_TOKENS = [
  '--control-height-sm', '--control-height-md', '--control-height-lg',
  '--control-padding-sm', '--control-padding-md', '--control-padding-lg',
  '--control-font-sm', '--control-font-md', '--control-font-lg',
  '--control-gap-sm', '--control-gap-md'
];

const HEX_PATTERN = /#[0-9a-fA-F]{3,8}\b/;
const BUTTON_PATTERN = /<button\b(?:"[^"]*"|'[^']*'|[^>"'])*>/g;

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
console.log(' VendGuard: Frontend Test Suite - Variantes de botón del panel (coordinador)');
console.log('======================================================================\n');

/**
 * Recorre las plantillas del panel.
 *
 * @returns {Array<{file:string,source:string}>}
 */
function panelTemplates() {
  const templates = [];

  for (const relative of SCAN_DIRS) {
    const absolute = join(PROJECT_ROOT, relative);

    for (const entry of readdirSync(absolute, { withFileTypes: true })) {
      if (!entry.isFile() || !entry.name.startsWith(FILE_PREFIX) || !entry.name.endsWith('.js')) {
        continue;
      }
      templates.push({
        file: `${relative}/${entry.name}`,
        source: readFileSync(join(absolute, entry.name), 'utf8')
      });
    }
  }

  return templates;
}

/**
 * Normaliza el nombre de una propiedad de estilo: `border-radius`, `borderRadius` y
 * `BORDER-RADIUS` son la misma propiedad.
 *
 * @param {string} raw
 * @returns {string}
 */
function normalizeProperty(raw) {
  return raw.replace(/["']/g, '').trim().toLowerCase().replace(/-/g, '');
}

/**
 * Propiedades declaradas en un `style` estático.
 *
 * @param {string} value
 * @returns {string[]}
 */
function staticProperties(value) {
  return value
    .split(';')
    .map((declaration) => declaration.split(':')[0])
    .filter((property) => property.trim() !== '')
    .map((property) => property.trim().toLowerCase());
}

/**
 * Propiedades declaradas en un objeto `:style="{ … }"` (claves camelCase o kebab).
 *
 * @param {string} value
 * @returns {string[]}
 */
function dynamicProperties(value) {
  const body = value.trim().replace(/^\{/, '').replace(/\}$/, '');
  const keys = [];
  let depth = 0;
  let current = '';

  for (const character of body) {
    if ('([{'.includes(character)) depth++;
    if (')]}'.includes(character)) depth--;
    if (character === ',' && depth === 0) {
      keys.push(current);
      current = '';
      continue;
    }
    current += character;
  }
  keys.push(current);

  return keys
    .map((entry) => entry.split(':')[0])
    .filter((key) => key.trim() !== '')
    .map((key) => normalizeProperty(key));
}

/**
 * Audita una fuente y devuelve los hallazgos.
 *
 * @param {string} source
 * @param {string} label
 * @returns {{buttons:number, missingBase:Array<string>, staticGeometry:Array<string>,
 *           dynamicGeometry:Array<string>, hexLiterals:Array<string>,
 *           warningPaint:Array<string>, placements:Set<string>}}
 */
function audit(source, label) {
  const findings = {
    buttons: 0,
    missingBase: [],
    staticGeometry: [],
    dynamicGeometry: [],
    hexLiterals: [],
    warningPaint: [],
    placements: new Set()
  };

  for (const match of source.matchAll(BUTTON_PATTERN)) {
    const tag = match[0];
    const line = source.slice(0, match.index).split('\n').length;
    findings.buttons++;

    const classMatch = tag.match(/\sclass="([^"]*)"/);
    const classTokens = classMatch ? classMatch[1].split(/\s+/).filter(Boolean) : [];
    if (!classTokens.includes('vg-btn')) {
      findings.missingBase.push(`${label}:${line}`);
    }

    if (WARNING_TONE_PATTERN.test(tag)) {
      findings.warningPaint.push(`${label}:${line}`);
    }

    const staticMatch = tag.match(/(?<![:\w-])style="([^"]*)"/);
    if (staticMatch) {
      const properties = staticProperties(staticMatch[1]);
      const offenders = properties.filter((property) => !PLACEMENT_PROPERTIES.has(property));
      if (offenders.length > 0) {
        findings.staticGeometry.push(`${label}:${line} (${offenders.join(', ')})`);
      }
      for (const property of properties) {
        findings.placements.add(property);
      }
      if (HEX_PATTERN.test(staticMatch[1])) {
        findings.hexLiterals.push(`${label}:${line}`);
      }
    }

    const dynamicMatch = tag.match(/:style="(\{[^"]*\})"/);
    if (dynamicMatch) {
      const offenders = dynamicProperties(dynamicMatch[1])
        .filter((property) => CONTROL_PROPERTIES.has(property) && !DYNAMIC_PAINT_ALLOWED.has(property));
      if (offenders.length > 0) {
        findings.dynamicGeometry.push(`${label}:${line} (${offenders.join(', ')})`);
      }
      if (HEX_PATTERN.test(dynamicMatch[1])) {
        findings.hexLiterals.push(`${label}:${line}`);
      }
    }
  }

  return findings;
}

/**
 * Cuerpo de una regla CSS por su selector exacto.
 *
 * @param {string} css
 * @param {string} selector
 * @returns {string}
 */
function ruleBody(css, selector) {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = css.match(new RegExp(`(?:^|[,\\n])\\s*${escaped}\\s*\\{([^}]*)\\}`));
  return match ? match[1] : '';
}

const css = readFileSync(CSS_PATH, 'utf8');
const templates = panelTemplates();

// ─── 1. La escalera vive en los tokens ────────────────────────────────────────

console.log('--- 1. La escalera de controles vive en los tokens (RNF-06) ---\n');

const missingTokens = CONTROL_TOKENS.filter((token) => !css.includes(`${token}:`));
assert('1.1 La hoja declara los once tokens de geometría de control',
  missingTokens.length === 0,
  `faltan: ${missingTokens.join(', ') || 'ninguno'}`);

const baseButtonBody = ruleBody(css, '.vg-btn');
assert('1.2 `.vg-btn` toma su altura del peldaño estándar',
  baseButtonBody.includes('height: var(--control-height-md)'),
  'la regla base no lee --control-height-md');

const sizes = [
  ['sm', '--control-height-sm', '--control-padding-sm', '--control-font-sm'],
  ['lg', '--control-height-lg', '--control-padding-lg', '--control-font-lg']
];

let sizeAssertion = 3;
for (const [name, height, padding, font] of sizes) {
  const body = ruleBody(css, `.vg-btn-${name}`);
  const reads = [height, padding, font].every((token) => body.includes(`var(${token})`));
  assert(`1.${sizeAssertion} El peldaño \`vg-btn-${name}\` lee altura, relleno y cuerpo de sus tokens`,
    reads && body.includes('height:') && body.includes('padding:') && body.includes('font-size:'),
    `cuerpo medido: ${body.replace(/\s+/g, ' ').trim() || 'regla ausente'}`);
  sizeAssertion++;
  assert(`1.${sizeAssertion} El peldaño \`vg-btn-${name}\` no fija geometría a mano`,
    !/\d+px/.test(body),
    `literal en px: ${(body.match(/\d+px/g) || []).join(', ')}`);
  sizeAssertion++;
}

const missingVariants = DECLARED_VARIANTS.filter((variant) => ruleBody(css, `.${variant}`) === '');
assert('1.7 La hoja declara todos los tonos y modificadores que el panel consume',
  missingVariants.length === 0,
  `sin regla: ${missingVariants.join(', ') || 'ninguno'}`);

const iconBody = ruleBody(css, '.vg-btn-icon');
const chipBody = ruleBody(css, '.vg-chip');
assert('1.8 El mando con icono y el chip leen su caja de los tokens de control',
  iconBody.includes('var(--control-height-sm)') && iconBody.includes('var(--control-font-lg)')
    && chipBody.includes('var(--spacing-sm)') && chipBody.includes('var(--control-padding-md)'),
  'el icono o el chip fijan su caja con literales');

const declaredSources = templates.map((entry) => entry.source).join('\n');
const unusedVariants = DECLARED_VARIANTS.filter((variant) => !declaredSources.includes(variant));
assert('1.9 Ninguna variante declarada está muerta: todas las consume el panel',
  unusedVariants.length === 0,
  `sin uso: ${unusedVariants.join(', ') || 'ninguna'}`);

// ─── 2. Ningún botón del panel se dibuja solo ─────────────────────────────────

console.log('\n--- 2. Ningún botón del panel lleva su geometría en línea ---\n');

const audited = templates.map((entry) => audit(entry.source, entry.file));
const totalButtons = audited.reduce((total, entry) => total + entry.buttons, 0);
const missingBase = audited.flatMap((entry) => entry.missingBase);
const staticGeometry = audited.flatMap((entry) => entry.staticGeometry);
const dynamicGeometry = audited.flatMap((entry) => entry.dynamicGeometry);
const hexLiterals = audited.flatMap((entry) => entry.hexLiterals);
const placements = new Set(audited.flatMap((entry) => [...entry.placements]));

assert('2.1 El barrido cubre el panel entero (al menos 12 plantillas y 140 botones)',
  templates.length >= 12 && totalButtons >= 140,
  `plantillas: ${templates.length} | botones: ${totalButtons}`);

assert('2.2 Todo botón del panel declara la clase base `vg-btn`',
  missingBase.length === 0,
  missingBase.slice(0, 6).join(' · '));

assert('2.3 Ningún botón del panel declara su geometría en un `style` estático',
  staticGeometry.length === 0,
  staticGeometry.slice(0, 6).join(' · '));

assert('2.4 Ningún botón del panel declara geometría en un `:style` dinámico (el color sí puede ser estado)',
  dynamicGeometry.length === 0,
  dynamicGeometry.slice(0, 6).join(' · '));

assert('2.5 Ningún botón del panel escribe un color en línea',
  hexLiterals.length === 0,
  hexLiterals.slice(0, 6).join(' · '));

const strayPlacement = [...placements].filter((property) => !PLACEMENT_PROPERTIES.has(property));
assert('2.6 Lo único que sigue en línea es la colocación de un mando flotante',
  strayPlacement.length === 0,
  `propiedades fuera del contrato: ${strayPlacement.join(', ') || 'ninguna'}`);

const warningPaint = audited.flatMap((entry) => entry.warningPaint);
assert('2.7 Ningún botón del panel se pinta el tono de aviso a mano (entra por variante)',
  warningPaint.length === 0,
  warningPaint.slice(0, 6).join(' · '));

// ─── 3. La guarda se prueba a sí misma ────────────────────────────────────────

console.log('\n--- 3. La guarda detecta el defecto que vigila (sonda sintética) ---\n');

const probeDirty = '<button type="button" class="vg-btn" style="height: 30px; padding: 0 8px; font-size: 12px;">A</button>';
const probeDynamic = '<button type="button" class="vg-btn" :style="{ height: \'30px\', fontSize: \'12px\' }">B</button>';
const probeHex = '<button type="button" class="vg-btn" :style="{ color: \'#991b1b\' }">C</button>';
const probeNaked = '<button type="button" style="position: absolute;" data-testid="naked">D</button>';
const probeClean = '<button type="button" class="vg-btn vg-btn-sm vg-btn-secondary" '
  + ':class="hasStranded ? \'vg-chip-danger\' : \'\'" :style="{ borderColor: token.color, color: token.color }" '
  + '@click="f(a > b)">E</button>';

const dirty = audit(probeDirty, 'sonda');
const dynamic = audit(probeDynamic, 'sonda');
const hexed = audit(probeHex, 'sonda');
const naked = audit(probeNaked, 'sonda');
const clean = audit(probeClean, 'sonda');

assert('3.1 Detecta la geometría en un `style` estático',
  dirty.staticGeometry.length === 1 && dirty.buttons === 1,
  JSON.stringify(dirty));
assert('3.2 Detecta la geometría dentro de un objeto `:style` dinámico',
  dynamic.dynamicGeometry.length === 1,
  JSON.stringify(dynamic.dynamicGeometry));
assert('3.3 Detecta el color escrito a mano (aunque el resto sea correcto)',
  hexed.hexLiterals.length === 1 && hexed.staticGeometry.length === 0 && hexed.dynamicGeometry.length === 0,
  JSON.stringify(hexed));
assert('3.4 Detecta el botón sin la clase base del sistema',
  naked.missingBase.length === 1,
  JSON.stringify(naked.missingBase));
assert('3.5 Una muestra correcta no produce falsos positivos (color de estado, `:class` y flechas en el manejador)',
  clean.buttons === 1
    && clean.missingBase.length === 0
    && clean.staticGeometry.length === 0
    && clean.dynamicGeometry.length === 0
    && clean.hexLiterals.length === 0,
  JSON.stringify(clean));

const probeAmber = '<button type="button" class="vg-btn vg-btn-secondary" '
  + ':style="{ borderColor: pausedTokens.color, color: pausedTokens.color }">F</button>';
const amber = audit(probeAmber, 'sonda');
assert('3.6 Detecta un botón que se pinta el ámbar a mano en vez de vestir la variante',
  amber.warningPaint.length === 1 && clean.warningPaint.length === 0,
  JSON.stringify({ sonda: amber.warningPaint, limpia: clean.warningPaint }));

// Summary
console.log('\n======================================================================');
console.log(` Aserciones: ${assertions} | Fallos: ${failures} | Plantillas: ${templates.length} | Botones: ${totalButtons}`);
console.log(` Variantes en uso: ${DECLARED_VARIANTS.length} | Tokens de control: ${CONTROL_TOKENS.length}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. Los botones del panel visten la escalera del sistema.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULTADO: FALLAN ${failures} ASERCIONES.`);
  console.log('======================================================================\n');
  process.exit(1);
}
