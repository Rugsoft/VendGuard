/**
 * VendGuard - TroubleshootingGuideUiContractTest
 *
 * Certifica el contrato de interfaz que cierra el hueco de EARS 01.2.3 del
 * Módulo 28: cuando la búsqueda de guías de una parada termina y el modelo no
 * tiene ninguna ficha activa, la tarjeta de ruta debe declararlo con un estado
 * informativo discreto, sin añadir acciones ni bloquear la operativa.
 *
 * Comprueba:
 *   1. El estado existe con su testid y su texto.
 *   2. Sólo se pinta en paradas asignadas o en curso.
 *   3. Sólo se pinta cuando la resolución de guías ya terminó.
 *   4. Sólo se pinta cuando no hay ninguna guía para el modelo.
 *   5. El bloque no añade botones ni manejadores que bloqueen la parada.
 *   6. El acordeón conserva su `v-if` de guías para no duplicar estados.
 *   7. La carga marca la resolución tanto en la ruta offline como en la API.
 *   8. La clase CSS usa tokens, sin hexadecimal ni sombra.
 *
 * Dogma Vanilla: Node ESM puro, sin dependencias externas.
 * Dualismo Lingüístico: aserciones en castellano, código en inglés.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(here, '../..');

let assertions = 0;
let failures = 0;

function assert(title, condition, detail = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${title}`);
    return;
  }
  failures++;
  console.log(`  [FAIL] ${title}`);
  if (detail !== '') {
    console.log(`         Motivo: ${detail}`);
  }
}

console.log('======================================================================');
console.log(' VendGuard: TroubleshootingGuideUiContractTest (Módulo 28, EARS 01.2.3)');
console.log('======================================================================');

const viewPath = path.join(projectRoot, 'public/assets/js/views/TechnicianRouteView.js');
const cssPath = path.join(projectRoot, 'public/assets/css/styles.css');

const view = fs.readFileSync(viewPath, 'utf8');
const css = fs.readFileSync(cssPath, 'utf8');

const marker = 'guide-empty-inline';
const markerIndex = view.indexOf(marker);
const block = markerIndex === -1 ? '' : view.slice(Math.max(0, markerIndex - 700), markerIndex + 350);

console.log('\n--- 1. El estado informativo existe y dice qué ocurre ---');

assert('1.1 La tarjeta de ruta declara el estado con su testid', block.includes('data-testid="guide-empty-inline"'));
assert(
  '1.2 El texto informa de que aún no hay guía para el modelo',
  block.includes('Sin guía de diagnóstico para este modelo todavía.')
);
assert('1.3 El estado tiene una clase propia', block.includes('class="vg-guide-empty-inline"'));

console.log('\n--- 2. Sólo aparece cuando la resolución terminó y no hay guías ---');

assert(
  '2.1 Se exige que la resolución de guías haya terminado',
  block.includes('hasTroubleshootingGuidesLoaded(incident)')
);
assert(
  '2.2 Se exige que el modelo no tenga ninguna guía',
  block.includes('troubleshootingGuidesFor(incident).length === 0')
);
assert(
  '2.3 Sólo se muestra en paradas asignadas o en curso',
  block.includes('isAssigned(incident)') && block.includes('isInProgress(incident)')
);

console.log('\n--- 3. La resolución se marca en ambos caminos de carga ---');

assert(
  '3.1 El estado declara su mapa de averías resueltas',
  view.includes('troubleshootingGuidesLoaded: {}')
);
assert(
  '3.2 El método de consulta existe y lee el mapa',
  view.includes('hasTroubleshootingGuidesLoaded(incident) {')
    && view.includes('this.troubleshootingGuidesLoaded?.[incident?.id] === true')
);
assert(
  '3.3 La ruta offline marca la avería como resuelta',
  (view.match(/loaded\[incident\.id\] = true;/g) ?? []).length >= 2
);

console.log('\n--- 4. El estado no bloquea ni añade acciones ---');

assert('4.1 El bloque no añade ningún botón', !/<button/i.test(block));
assert('4.2 El bloque no enlaza ningún manejador de acción', !/@click=/i.test(block));

console.log('\n--- 5. El acordeón conserva su condición para no duplicar estados ---');

assert(
  '5.1 El acordeón sigue exigiendo al menos una guía',
  /<TroubleshootingGuideAccordion[\s\S]{0,400}?v-if="[^"]*troubleshootingGuidesFor\(incident\)\.length > 0"/.test(view)
);

console.log('\n--- 6. La clase del estado respeta el sistema de diseño ---');

const cssBlock = (css.match(/\.vg-guide-empty-inline\s*\{[^}]*\}/s) ?? [''])[0];
assert('6.1 La clase está declarada en la hoja', cssBlock !== '');
assert(
  '6.2 Usa tokens del sistema',
  cssBlock.includes('var(--color-hairline)')
    && cssBlock.includes('var(--color-ink-muted)')
    && cssBlock.includes('var(--radius-xs)')
);
assert('6.3 No introduce hexadecimales', !/#[0-9a-f]{3,8}\b/i.test(cssBlock));
assert('6.4 No introduce sombras en reposo', !/box-shadow/i.test(cssBlock));

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. EARS 01.2.3 CERTIFICADO EN RUTA.');
  console.log('======================================================================');
  process.exit(0);
}

console.log(` RESULTADO: FALLO EN ${failures} ASERCIONES.`);
console.log('======================================================================');
process.exit(1);
