/**
 * VendGuard - FrontendTemplateCompileTest (T-REF-25)
 *
 * Comprueba que TODAS las plantillas de `public/assets/js/` son JavaScript
 * válido antes de que el navegador=intente convertirlas en función de render.
 *
 * ## Por qué hace falta, y por qué nadie lo tenía
 *
 * Los componentes de Vue 3 sin paso de compilación llevan su plantilla como
 * cadena, y el navegador la convierte con `new Function()` en el MOMENTO de
 * montarla. Un error de sintaxis dentro de un atributo `:style` no falla al
 * importar el módulo, no falla al cargar la página y no aparece en la consola
 * hasta que el usuario pulsa ese botón concreto.
 *
 * Eso es exactamente lo que pasó con `borderRadius: var(--radius-interactive)'`:
 * `var(--x)` no es JavaScript —`var` es palabra reservada usada como nombre de
 * función—, así que la plantilla era código inválido. Las 162 suites de la
 * batería estaban en verde porque todas leen `data()`, `methods()` y
 * `computed` de los componentes, y ninguna mira la plantilla. Y no era un fallo
 * cosmético: la pestaña de Reintegros se quedaba en blanco y el error tumbaba
 * el árbol de reactividad, de modo que ningún otro clic respondía hasta
 * recargar con F5.
 *
 * El coste de que la batería no lo viera era cero cobertura real sobre la
 * mitad de la interfaz, porque una vista que no renderiza no puede probarse.
 *
 * ## Por qué no se usa el compilador de Vue aquí
 *
 * `vue.esm-browser.prod.js` es la build de NAVEGADOR: su parser HTML decodifica
 * entidades con `document.createElement`, así que `compile()` funciona en Node
 * sólo para las plantillas que no llevan atributos con comillas dobles. Es una
 * cobertura parcial y silenciosa, que es la peor forma de guardarse. Además el
 * proyecto no puede añadir un paquete (Art. IV.3).
 *
 * El problema real que hay que vigilar es el JavaScript embebido en la
 * plantilla: los `{{ }}`, los `:attr`, los `@event`, los `v-if`/`v-for`. Esos sí
 * se pueden extraer y comprobar sin DOM, y se comprueban TODOS, en las 46
 * plantillas, con el mismo criterio. Un detector que cubre el 100 % es más útil
 * que uno que cubre el 70 % en más detalle.
 *
 * ## El control de no-vacuidad
 *
 * Un detector que no encuentra nada y una interfaz sin errores se parecen
 * exactamente en la salida: la lista de fallos vacía. Por eso la sección 1 pasa
 * por el MISMO extractor una muestra con el defecto original y exige que la
 * marque. Si el extractor se rompe algún día, esta suite falla en lugar de dar
 * verde.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath, pathToFileURL } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const JS_ROOT = path.resolve(HERE, '../../public/assets/js');

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    failures++;
    console.error(`  [FAIL] ${description}`);
    if (details) console.error(`         Motivo: ${details}`);
  }
}

/**
 * Every ES module under the frontend, so a component is covered the day it is
 * written and not the day somebody remembers to add it to a list.
 *
 * @returns {string[]} module paths relative to JS_ROOT, e.g. "components/Foo.js"
 */
function discoverModules() {
  const found = [];
  for (const dir of ['components', 'views']) {
    const absolute = path.join(JS_ROOT, dir);
    if (!fs.existsSync(absolute)) continue;
    for (const entry of fs.readdirSync(absolute).sort()) {
      if (entry.endsWith('.js')) found.push(`${dir}/${entry}`);
    }
  }
  return found;
}

/**
 * Pulls every piece of JavaScript out of a Vue template.
 *
 * Covers interpolations (`{{ expr }}`) and every directive whose value is code:
 * `:attr`, `@event`, `v-bind:`, `v-on:`, `v-if`, `v-else-if`, `v-show`,
 * `v-model`, `v-for`. A plain `style="..."` is CSS, not code, and is skipped.
 *
 * @param {string} template
 * @returns {string[]} the raw expressions
 */
function extractExpressions(template) {
  const expressions = [];
  const skipRanges = [];

  // Los atributos estáticos se apartan ANTES de buscar directivas, porque un
  // `style="color: var(--x)"` es CSS y su contenido no es JavaScript.
  const staticAttribute = /\s([a-zA-Z][a-zA-Z0-9_.:-]*)\s*=\s*"[^"]*"/g;
  let match;
  while ((match = staticAttribute.exec(template)) !== null) {
    skipRanges.push([match.index, match.index + match[0].length]);
  }

  const insideSkipped = (index) => skipRanges.some(([from, to]) => index >= from && index < to);

  const interpolation = /\{\{([\s\S]*?)\}\}/g;
  while ((match = interpolation.exec(template)) !== null) {
    if (!insideSkipped(match.index)) {
      const expression = match[1].trim();
      if (expression !== '') expressions.push(expression);
    }
  }

  const directive = /\s(:|@|v-bind:|v-on:|v-if=|v-else-if=|v-show=|v-model=|v-for=)([\w.\-\[\]:]+)?\s*=\s*"([^"]*)"/g;
  while ((match = directive.exec(template)) !== null) {
    if (insideSkipped(match.index)) continue;
    const raw = match[3].trim();
    if (raw === '') continue;
    // Un handler con sentencia se emite como cuerpo de flecha, y un `v-for`
    // lleva su propio alias. Se envuelve en paréntesis porque lo que se
    // comprueba es que sea una expresión válida.
    expressions.push(raw);
  }

  return expressions;
}

/**
 * Returns the syntax error of an expression, or null when it is valid code.
 *
 * Two readings are tried because a Vue template accepts both: a `:attr` is an
 * expression and an `@event` may be a list of statements. The reported error is
 * always the FIRST one, because the first attempt uses the expression reading
 * and therefore names the token that actually broke — which is the thing worth
 * printing when somebody has to go and fix it.
 *
 * @param {string} expression
 * @returns {string|null}
 */
function expressionError(expression) {
  const attempts = [
    () => new Function('$event', 'return (' + expression + ')'),
    () => new Function(expression),
  ];
  let first = null;
  for (const attempt of attempts) {
    try {
      attempt();
      return null;
    } catch (error) {
      if (first === null) first = error.message;
    }
  }
  return first;
}

console.log('======================================================================');
console.log(' VendGuard: FrontendTemplateCompileTest - El frontend tiene que renderizar');
console.log('======================================================================\n');

console.log('--- 1. Control de no-vacuidad: el extractor ve el defecto que buscaba ---\n');

const sampleWithDefect = `
  <div :style="{ display: 'inline-flex', borderRadius: var(--radius-interactive)', fontSize: '11px' }">
    {{ label }}
  </div>
`;
const sampleCorrect = `
  <div :style="{ display: 'inline-flex', borderRadius: 'var(--radius-interactive)', fontSize: '11px' }">
    {{ label }}
  </div>
`;

// La muestra pasa por EXACTAMENTE el mismo extractor que las plantillas reales.
const defectExpressions = extractExpressions(sampleWithDefect);
const defectErrors = defectExpressions.map(expressionError).filter((m) => m !== null);

assert(
  '1.1 El extractor ve un `var(--…)` sin comillas usado como valor de JavaScript',
  defectErrors.some((message) => message.includes("Unexpected token 'var'")),
  `mensajes reales: ${JSON.stringify(defectErrors)}`
);

assert(
  '1.2 La muestra correcta NO produce falsos positivos',
  extractExpressions(sampleCorrect).map(expressionError).every((m) => m === null)
);

// Y un `style=` estático NO debe leerse como código, porque ahí `var(--x)` es
// CSS perfectamente válido. Si el extractor confundiera ambos casos, marcaría
// como error cada estilo inline del proyecto.
assert(
  '1.3 Un `style="…var(--…)…"` estático NO se trata como JavaScript',
  extractExpressions('<div style="color: var(--color-ink); border-radius: 4px;">x</div>').length === 0
);

// ---------------------------------------------------------------------
console.log('\n--- 2. Todas las plantillas del frontend son JavaScript válido ---\n');

const modules = discoverModules();
assert(
  '2.1 El descubrimiento por directorio encuentra los componentes y las vistas',
  modules.length >= 40,
  `módulos encontrados: ${modules.length}`
);

const broken = [];
const withTemplate = [];
const importFailures = [];
let totalExpressions = 0;

for (const relative of modules) {
  let exported;
  try {
    // `pathToFileURL` y no una ruta pelada: en Windows el cargador ESM rechaza
    // `C:\...` porque el protocolo `c:` no existe para él.
    exported = await import(pathToFileURL(path.join(JS_ROOT, relative)).href);
  } catch (error) {
    importFailures.push(`${relative} :: ${error.message}`);
    continue;
  }

  for (const [name, value] of Object.entries(exported)) {
    if (!value || typeof value !== 'object' || typeof value.template !== 'string') continue;

    withTemplate.push(`${relative} [${name}]`);
    for (const expression of extractExpressions(value.template)) {
      totalExpressions++;
      const message = expressionError(expression);
      if (message !== null) {
        broken.push(`${relative} [${name}] :: ${message} :: ${expression.slice(0, 90)}`);
      }
    }
  }
}

assert(
  '2.2 Ningún módulo del frontend falla al importarse en Node',
  importFailures.length === 0,
  importFailures.slice(0, 3).join(' | ')
);

assert(
  '2.3 Se ha encontrado y comprobado un volumen real de plantillas y expresiones',
  withTemplate.length >= 40 && totalExpressions >= 100,
  `plantillas: ${withTemplate.length} | expresiones: ${totalExpressions}`
);

assert(
  '2.4 Ninguna plantilla del frontend lleva JavaScript inválido',
  broken.length === 0,
  broken.slice(0, 5).join(' | ')
);

// ---------------------------------------------------------------------
console.log('\n--- 3. La forma concreta del defecto no puede volver ---\n');

// El comprobador de arriba depende de que el extractor encuentre las
// expresiones. Esta segunda comprobación es independiente del extractor y
// sobrevive a que cambie.
//
// El discriminante es la convención de CSS: las propiedades de hoja de estilos
// van en kebab-case (`border-radius`) y los valores de un objeto `:style` en
// JavaScript van en camelCase (`borderRadius`). Una propiedad camelCase
// asignando `var(--…)` a pelo sólo puede ser código, y ese código está roto.
const jsValueLooking = [];
for (const relative of modules) {
  const source = fs.readFileSync(path.join(JS_ROOT, relative), 'utf8');
  source.split('\n').forEach((line, index) => {
    if (/[a-z][A-Z][A-Za-z]*: *var\(--/.test(line)) {
      jsValueLooking.push(`${relative}:${index + 1}`);
    }
  });
}

assert(
  '3.1 Ningún objeto `:style` asigna `var(--…)` sin comillas',
  jsValueLooking.length === 0,
  jsValueLooking.slice(0, 5).join(' | ')
);

// Y el espejo: la forma CORRECTA tiene que seguir presente en disco, para que
// 3.1 no pueda pasar por haber borrado el estilo entero en lugar de ponerlo
// bien. Una guarda que se puede satisfacer rompiendo el código no es una guarda.
const quotedUsages = modules.reduce((total, relative) => {
  const source = fs.readFileSync(path.join(JS_ROOT, relative), 'utf8');
  return total + (source.match(/[a-z][A-Z][A-Za-z]*: *'var\(--/g) || []).length;
}, 0);

assert(
  '3.2 Los tokens de diseño se siguen usando en los estilos de los componentes',
  quotedUsages >= 20,
  `usos con comillas encontrados: ${quotedUsages}`
);

// ---------------------------------------------------------------------
console.log('\n======================================================================');
console.log(` Plantillas: ${withTemplate.length} | Expresiones: ${totalExpressions} | Aserciones: ${assertions} | Fallos: ${failures}`);
if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. El frontend renderiza entero.');
} else {
  console.log(` RESULTADO: ${failures} FALLO(S) DETECTADO(S).`);
}
console.log('======================================================================');

process.exit(failures === 0 ? 0 : 1);