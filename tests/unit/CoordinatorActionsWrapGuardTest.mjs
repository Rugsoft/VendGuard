/**
 * VendGuard - Guarda de anchura de los grupos de botones del coordinador
 * (CoordinatorActionsWrapGuardTest.mjs)
 *
 * Cierra por congelación el defecto reportado el 2026-10-11: en el triaje, una avería
 * en pausa de sede sumaba «▶️ Reanudar» a la fila y la etiqueta de «🏷️ Imprimir QR» se
 * partía en dos líneas dentro de un botón de 30 px fijos, saliéndose por debajo del
 * borde. La causa no era el botón sino su grupo: un `display: flex` sin envoltura, así
 * que la compresión de la fila la sufrían los controles. El mismo patrón estaba latente
 * en la celda de acciones del catálogo de repuestos (que además forzaba `white-space:
 * nowrap`), en la barra de pestañas del coordinador y en las de métricas.
 *
 * La regla que congela esta guarda: **un grupo de botones envuelve sus controles**.
 * Ninguna tabla del panel puede depender de tener pocos botones, y una etiqueta nunca
 * se parte dentro de su propio botón.
 *
 * Cómo decide (deliberadamente simple y auditable):
 *   - Barrido: `public/assets/js/views` y `public/assets/js/components`, ficheros
 *     `Coordinator*.js` (el panel del coordinador).
 *   - Se emparejan las etiquetas de apertura y cierre (respetando comillas, para no
 *     tropezar con manejadores del tipo `@click="() => f()"`) y se mide cada subárbol.
 *   - Es candidato todo elemento que declara `display: flex` / `inline-flex` en su
 *     propia etiqueta, no es una columna (`flex-direction: column`) y contiene dos o
 *     más botones.
 *   - Fuera de alcance, y por eso exento: lo que vive dentro de un diálogo —una etiqueta
 *     de componente `*Modal*`, un `role="dialog"`, una clase `*modal*` o un ancestro con
 *     `position: fixed`—. Un pie de diálogo tiene ancho propio acotado y dos acciones
 *     fijas; no lo comprime el contenido de una fila.
 *   - Es riesgo si el grupo no envuelve: ni `flex-wrap: wrap` en la propia etiqueta, ni
 *     la clase compartida (`vg-action-group` / `vg-row-actions`) que lo declara.
 *   - Es riesgo, además, una celda `<td>` con `white-space: nowrap` que sostenga dos o
 *     más botones sin un grupo envolvente dentro: es la firma exacta del defecto, y
 *     `nowrap` en la celda impide que el grupo interior pueda bajar de línea.
 *
 * La guarda se prueba a sí misma sobre una muestra sintética (4.1): un escáner roto que
 * devolviera «todo bien» sin mirar nada pasaría la suite, y eso no certifica nada.
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
const WRAP_CLASSES = ['vg-action-group', 'vg-row-actions'];

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
console.log(' VendGuard: Frontend Test Suite - Guarda de anchura de acciones (coordinador)');
console.log('======================================================================\n');

const TOKEN = /<([A-Za-z][\w-]*)((?:"[^"]*"|'[^']*'|[^>"'])*?)(\/?)>|<\/([A-Za-z][\w-]*)>/g;

/**
 * Empareja las etiquetas de un fuente de plantilla y devuelve sus subárboles.
 *
 * @param {string} source
 * @returns {Array<{start:number,end:number,tag:string,attrs:string,text:string}>}
 */
function elementSpans(source) {
  const stack = [];
  const spans = [];

  for (const match of source.matchAll(TOKEN)) {
    const [raw, openTag, attrs, selfClosing, closeTag] = match;

    if (closeTag !== undefined) {
      const spaceIndex = stack.map((entry) => entry.tag).lastIndexOf(closeTag.toLowerCase());
      if (spaceIndex === -1) {
        continue;
      }
      const [entry] = stack.splice(spaceIndex, 1);
      spans.push({
        start: entry.start,
        end: match.index + raw.length,
        tag: entry.tag,
        attrs: entry.attrs,
        text: source.slice(entry.start, match.index + raw.length)
      });
      continue;
    }

    if (selfClosing === '/') {
      continue;
    }

    stack.push({ start: match.index, tag: openTag.toLowerCase(), attrs });
  }

  return spans.sort((a, b) => a.start - b.start);
}

const isDialog = (attrs, tag) => /position:\s*fixed/i.test(attrs)
  || /role="dialog"/i.test(attrs)
  || /modal/i.test(tag)
  || /class="[^"]*modal/i.test(attrs)
  || /data-testid="[^"]*modal/i.test(attrs);

const isFlexRow = (attrs) => /display:\s*(inline-)?flex/i.test(attrs)
  && !/flex-direction:\s*column/i.test(attrs);

const wraps = (attrs) => /flex-wrap:\s*wrap/i.test(attrs)
  || WRAP_CLASSES.some((className) => attrs.includes(className));

const countButtons = (text) => (text.match(/<button\b/gi) || []).length;

/**
 * Audita una fuente de plantilla.
 *
 * @param {string} source
 * @param {string} label
 * @returns {{candidates:number, wrapped:number, risks:Array<{reason:string,line:number,detail:string}>}}
 */
function auditSource(source, label) {
  const spans = elementSpans(source);
  const candidates = [];
  const risks = [];

  for (const span of spans) {
    const ancestors = spans.filter((other) => other.start < span.start && other.end >= span.end);
    const insideDialog = ancestors.some((ancestor) => isDialog(ancestor.attrs, ancestor.tag));

    if (span.tag === 'td'
      && /white-space:\s*nowrap/i.test(span.attrs)
      && countButtons(span.text) >= 2) {
      const hasWrappingGroup = spans.some((other) => other.start > span.start
        && other.end <= span.end
        && other.tag !== 'td'
        && isFlexRow(other.attrs)
        && wraps(other.attrs));
      if (!hasWrappingGroup) {
        risks.push({
          reason: 'celda con white-space: nowrap y varios botones sin grupo envolvente',
          line: source.slice(0, span.start).split('\n').length,
          detail: `${label}: línea ${source.slice(0, span.start).split('\n').length}`
        });
      }
    }

    if (!isFlexRow(span.attrs) || insideDialog || isDialog(span.attrs, span.tag)) {
      continue;
    }

    if (countButtons(span.text) < 2) {
      continue;
    }

    candidates.push(span);

    if (!wraps(span.attrs)) {
      const line = source.slice(0, span.start).split('\n').length;
      risks.push({
        reason: 'grupo de botones sin envoltura',
        line,
        detail: `${label}: línea ${line} · ${span.attrs.trim().slice(0, 70)}`
      });
    }
  }

  return {
    candidates: candidates.length,
    wrapped: candidates.filter((span) => wraps(span.attrs)).length,
    risks
  };
}

const auditFiles = () => {
  const files = [];
  for (const directory of SCAN_DIRS) {
    const absolute = join(PROJECT_ROOT, directory);
    for (const entry of readdirSync(absolute, { withFileTypes: true })) {
      if (entry.isFile() && entry.name.startsWith(FILE_PREFIX) && entry.name.endsWith('.js')) {
        files.push({ path: `${directory}/${entry.name}`, absolute: join(absolute, entry.name) });
      }
    }
  }

  const results = files.map((file) => ({
    path: file.path,
    ...auditSource(readFileSync(file.absolute, 'utf8'), file.path)
  }));

  return results.sort((a, b) => a.path.localeCompare(b.path));
};

const results = auditFiles();
const candidates = results.reduce((total, file) => total + file.candidates, 0);
const wrapped = results.reduce((total, file) => total + file.wrapped, 0);
const risks = results.flatMap((file) => file.risks);

console.log(`  Ficheros auditados: ${results.length} · grupos de botones: ${candidates} · con envoltura: ${wrapped}\n`);
for (const file of results) {
  console.log(`    ${String(file.candidates).padStart(2)} grupos  ${file.path}`);
}
console.log('');

assert('1.1 El escáner encuentra grupos de botones en el panel del coordinador (no está roto ni vacío)',
  results.length >= 8 && candidates > 0,
  `ficheros=${results.length}, grupos=${candidates}`);

assert('1.2 Todo grupo de botones de fila, barra o pestaña envuelve sus controles (RNF-03)',
  risks.filter((risk) => risk.reason === 'grupo de botones sin envoltura').length === 0,
  risks.filter((risk) => risk.reason === 'grupo de botones sin envoltura').map((risk) => risk.detail).join(' ; '));

assert('1.3 Ninguna celda fuerza una sola línea con varios botones sin grupo envolvente (RNF-03)',
  risks.filter((risk) => risk.reason.startsWith('celda')).length === 0,
  risks.filter((risk) => risk.reason.startsWith('celda')).map((risk) => risk.detail).join(' ; '));

const cssSource = readFileSync(CSS_PATH, 'utf8');
const groupRule = (cssSource.match(/\.vg-action-group,\s*\.vg-row-actions\s*\{[^}]*\}/) || [''])[0];
const childRule = cssSource.match(/\.vg-action-group\s*>\s*\.vg-btn,\s*\.vg-row-actions\s*>\s*\.vg-btn\s*\{[^}]*\}/);

assert('2.1 La hoja de tokens declara la clase envolvente compartida para toda tabla (design.md)',
  groupRule.includes('flex-wrap: wrap') && groupRule.includes('max-width: 100%'),
  'La clase compartida debe declarar la envoltura; sin ella los grupos vuelven a comprimirse.');

assert('2.2 Los botones del grupo conservan su ancho natural y no parten su etiqueta (RNF-03)',
  childRule !== null
    && childRule[0].includes('flex-shrink: 0')
    && childRule[0].includes('white-space: nowrap'),
  'Sin `flex-shrink: 0` el botón se encoge; sin `white-space: nowrap` la etiqueta se parte dentro del botón.');

// La guarda se prueba a sí misma: si el escáner dejara de detectar la firma del defecto,
// las comprobaciones anteriores pasarían sin haber mirado nada.
const brokenProbe = auditSource(
  '<div><table><tr><td style="text-align: right; white-space: nowrap;">'
  + '<button type="button">🏷️ Imprimir QR</button><button type="button">▶️ Reanudar</button>'
  + '</td></tr></table>'
  + '<div style="display: flex; gap: 8px;"><button type="button">A</button><button type="button">B</button></div>'
  + '</div>',
  'sonda'
);
const healthyProbe = auditSource(
  '<div class="vg-row-actions"><button type="button">A</button><button type="button">B</button></div>'
  + '<div><div class="vg-modal-backdrop" style="position: fixed;"><div style="display: flex; gap: 10px;">'
  + '<button type="button">Cancelar</button><button type="button">Confirmar</button></div></div></div>',
  'sonda-sana'
);

const brokenReasons = brokenProbe.risks.map((risk) => risk.reason);
assert('4.1 La sonda sintética con el defecto se detecta (el escáner no puede aprobar en falso)',
  brokenReasons.some((reason) => reason.startsWith('celda'))
    && brokenReasons.includes('grupo de botones sin envoltura'),
  `riesgos detectados=[${brokenReasons.join(' ; ')}] (esperado: la celda sin envoltura y el grupo sin envoltura)`);

assert('4.2 La sonda sana —grupo con clase y pie de diálogo— no dispara ningún riesgo',
  healthyProbe.risks.length === 0,
  `riesgos detectados=${healthyProbe.risks.length}`);

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. NINGUNA TABLA DEL COORDINADOR DEPENDE DE TENER POCOS CONTROLES.');
  console.log('======================================================================');
  process.exit(0);
}

console.log(' RESULTADO: HAY GRUPOS DE BOTONES QUE NO ENVUELVEN.');
console.log('======================================================================');
process.exit(1);
