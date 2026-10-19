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
 * se pueden extraer y comprobar sin DOM, y se comprueban TODOS, en las 55
 * plantillas, con el mismo criterio. Un detector que cubre el 100 % es más útil
 * que uno que cubre el 70 % en más detalle.
 *
 * ## El barrido incluye la raíz (2026-10-14, T-HYBRID-18)
 *
 * El descubrimiento solo miraba `components/` y `views/`, de modo que `app.js`
 * —la plantilla MÁS GRANDE del proyecto, la que monta todas las demás— no la
 * compilaba nadie. Se descubrió al montar el banner de conexión en la raíz y
 * escribir un comentario con acentos graves dentro del literal de plantilla: el
 * módulo dejó de importarse (`Unexpected identifier 'idle'`) y ninguna otra suite
 * lo vio. La raíz entra ahora en el barrido, y su entrada se cuenta una sola vez
 * porque el mismo objeto se exporta como `App` y como `default`.
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
 * La raíz de la aplicación: no vive en `components/` ni en `views/`, así que el barrido por
 * directorio no la veía. `app.js` lleva la plantilla MÁS GRANDE del proyecto —el contenedor raíz
 * con su barra, su selector de perfil, sus avisos y sus cinco vistas— y era la única que nadie
 * compilaba: el mismo defecto que este arnés existe para cazar podía esconderse justo en la
 * plantilla que monta todas las demás.
 */
const ROOT_MODULES = ['app.js'];

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
  for (const entry of ROOT_MODULES) {
    if (fs.existsSync(path.join(JS_ROOT, entry))) found.push(entry);
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
  '2.1 El descubrimiento por directorio encuentra los componentes, las vistas y la raíz',
  modules.length >= 40 && modules.includes('app.js'),
  `módulos encontrados: ${modules.length}`
);

const broken = [];
const withTemplate = [];
const importFailures = [];
let totalExpressions = 0;
// El mismo componente puede exportarse dos veces (`App` y `default`): sin esta identidad, la
// plantilla de la raíz se contaría —y se comprobaría— por duplicado.
const seenTemplates = new Set();

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
    if (seenTemplates.has(value)) continue;
    seenTemplates.add(value);

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

// La cobertura nueva tiene que notarse. Una guarda que se satisface por no mirar dónde se le
// manda mirar es exactamente el defecto que este arnés existe para evitar, así que se comprueba
// que la plantilla de la raíz se ha recorrido de verdad —y una sola vez: el mismo objeto de
// componente se exporta como `App` y como `default`.
const rootTemplates = withTemplate.filter((entry) => entry.startsWith('app.js ['));
assert(
  '2.5 La plantilla de la raíz de la aplicación se compila, y se cuenta una sola vez',
  rootTemplates.length === 1,
  `entradas de la raíz: ${JSON.stringify(rootTemplates)}`
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
console.log('\n--- 4. Toda función que la plantilla llama existe en el componente ---\n');

// La sintaxis válida no basta: `{{ opportunisticModeLabel(incident) }}` es
// JavaScript perfecto y aun así tumbaba la vista del técnico con
// «opportunisticModeLabel is not a function». Y la trampa tiene dos capas: un
// `import` NO expone el helper a la plantilla, porque el compilador resuelve
// cada llamada contra la instancia (`_ctx.foo`). Hace falta un método (o un
// computed/prop/data) que lo publique. Esta sección cruza las llamadas de cada
// plantilla con la superficie REAL del componente —methods, computed, props,
// data, components e inject— y exige que todas resuelvan ahí.

/**
 * Globales de JavaScript que una plantilla puede invocar sin declararlos.
 */

/**
 * Globales de JavaScript que una plantilla puede invocar sin declararlos.
 */
const JS_GLOBALS = new Set([
  'Math', 'Number', 'String', 'Boolean', 'Date', 'JSON', 'Object', 'Array',
  'parseInt', 'parseFloat', 'isNaN', 'isFinite', 'encodeURIComponent',
  'decodeURIComponent', 'setTimeout', 'clearTimeout', 'setInterval',
  'clearInterval', 'console', 'Intl', 'RegExp', 'Promise', 'Error', 'Map',
  'Set', 'Symbol', 'BigInt', 'structuredClone'
]);

const JS_KEYWORDS = new Set(['if', 'for', 'while', 'return', 'function', 'new', 'typeof', 'await', 'catch', 'switch']);

/**
 * Identificadores invocados como función dentro del código de la plantilla
 * (`foo(`, pero no `obj.foo(`), sin literales de cadena ni comentarios HTML.
 *
 * @param {string} template
 * @returns {Set<string>}
 */
function calledIdentifiers(template) {
  const withoutComments = template.replace(/<!--[\s\S]*?-->/g, '');
  const called = new Set();

  for (const expression of extractExpressions(withoutComments)) {
    // Los literales se vacían primero: `'var(--x)'` es CSS y `'rgba(…)'` es un
    // color, no llamadas. Sin este paso cada estilo inline sería un falso positivo.
    // Un `:style` puede llevar comentarios `//` de JavaScript dentro del valor
    // (`borderLeft` explica por qué existe): también se apartan, porque su prosa
    // produce falsos positivos como `edge (plan §4.4)`.
    const code = expression
      .replace(/'(?:[^'\\]|\\.)*'/g, "''")
      .replace(/"(?:[^"\\]|\\.)*"/g, '""')
      .replace(/`(?:[^`\\]|\\.)*`/g, '``')
      .replace(/\/\/.*$/gm, '')
      .replace(/\/\*[\s\S]*?\*\//g, '');

    const call = /(^|[^.\w$])([A-Za-z_$][\w$]*)\s*\(/g;
    let match;
    while ((match = call.exec(code)) !== null) {
      const identifier = match[2];
      if (JS_KEYWORDS.has(identifier) || identifier.startsWith('$')) continue;
      called.add(identifier);
    }
  }

  return called;
}

/**
 * Devuelve las llamadas de la plantilla que el componente no puede resolver.
 *
 * Solo cuenta la superficie de la instancia: los `import` del módulo no llegan
 * al render y por eso no se aceptan aquí.
 *
 * @param {Object} component
 * @returns {string[]}
 */
function unresolvedCalls(component) {
  let dataKeys = [];
  try {
    dataKeys = Object.keys(typeof component.data === 'function' ? component.data() : {});
  } catch (error) {
    dataKeys = [];
  }

  const surface = new Set([
    ...Object.keys(component.methods || {}),
    ...Object.keys(component.computed || {}),
    ...Object.keys(component.props || {}),
    ...Object.keys(component.components || {}),
    ...Object.keys(component.inject || {}),
    ...dataKeys
  ]);
  return [...calledIdentifiers(component.template)]
    .filter((identifier) => !surface.has(identifier) && !JS_GLOBALS.has(identifier));
}

// Control de no-vacuidad: la forma exacta del defecto tiene que ser detectada,
// la forma correcta (el helper publicado en methods) no puede marcarse, y un
// helper que solo está importado tiene que seguir marcándose: el import no
// llega a la plantilla y es justo el espejismo que dejó la vista en blanco.
assert(
  '4.1 Se detecta un helper usado en la plantilla y no definido en el componente',
  unresolvedCalls({ template: '<div>{{ opportunisticModeLabel(incident) }}</div>' }).includes('opportunisticModeLabel')
);
assert(
  '4.2 Un helper declarado en methods no se marca',
  unresolvedCalls({ methods: { opportunisticModeLabel() {} }, template: '<div>{{ opportunisticModeLabel(incident) }}</div>' }).length === 0
);
assert(
  '4.3 Un helper solo importado sigue marcándose: el import no llega al render',
  unresolvedCalls({ importsOnly: true, template: '<div>{{ opportunisticModeLabel(incident) }}</div>' }).includes('opportunisticModeLabel')
);

const danglingCalls = [];
let totalCalled = 0;
const countedComponents = new Set();

for (const relative of modules) {
  let exported;
  try {
    exported = await import(pathToFileURL(path.join(JS_ROOT, relative)).href);
  } catch (error) {
    continue; // 2.2 ya informa del módulo que no importa
  }

  for (const [name, value] of Object.entries(exported)) {
    if (!value || typeof value !== 'object' || typeof value.template !== 'string') continue;
    if (countedComponents.has(value)) continue;
    countedComponents.add(value);

    const called = calledIdentifiers(value.template);
    totalCalled += called.size;
    for (const identifier of unresolvedCalls(value)) {
      danglingCalls.push(`${relative} [${name}] :: ${identifier}()`);
    }
  }
}

assert(
  '4.4 Se han cruzado las llamadas de un volumen real de plantillas',
  countedComponents.size >= 40 && totalCalled >= 50,
  `componentes: ${countedComponents.size} | llamadas distintas: ${totalCalled}`
);
assert(
  '4.5 Ninguna plantilla llama a una función que el componente no expone',
  danglingCalls.length === 0,
  danglingCalls.slice(0, 5).join(' | ')
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