/**
 * VendGuard - Design Token Debt Ratchet (DesignTokenDebtRatchetTest.mjs)
 *
 * Cierra por congelacion el hallazgo §6 del informe de auditoria arquitectonica: los
 * componentes JS acumulan colores hex hardcodeados en lugar de consumir los tokens de
 * `public/assets/css/design-tokens.css`. La deuda no es un riesgo funcional, pero **crecia**:
 * 2.063 literales el 30/09 y 2.466 el 08/10 (+403 en ocho dias).
 *
 * Regla de conteo (deliberadamente simple y reproducible):
 *   - Raiz: `public/assets/js`, recursivo.
 *   - Extension: `.js`.
 *   - Excluido: cualquier ruta bajo `vendor/` (Vue embebido, no es codigo del proyecto).
 *   - Patron: `#[0-9a-fA-F]{3,8}\b` (colores hex de 3 a 8 digitos).
 *
 * Contrato del trinquete:
 *   - Si el contador SUBE por encima de CEILING, la suite falla: un color nuevo debe entrar por
 *     token (`var(--color-...)`) o el autor debe subir el techo de forma visible en revision.
 *   - Si el contador BAJA (refactor por componentes), hay que bajar CEILING en el mismo commit
 *     para que la mejora quede fijada. La suite imprime el contador actual para ello.
 *   - El refactor completo (extraer los `style=` inline de los cinco componentes mas densos)
 *     sigue en backlog; esta suite solo evita que la deuda siga creciendo.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const PROJECT_ROOT = fileURLToPath(new URL('../../', import.meta.url));
const ROOT = join(PROJECT_ROOT, 'public/assets/js');
const TRIAGE_DOC = join(PROJECT_ROOT, 'specs/technical/auditoria_arquitectura_triage.md');
const EXCLUDED_DIR = 'vendor';
const HEX_PATTERN = /#[0-9a-fA-F]{3,8}\b/g;

/**
 * Techo congelado el 2026-10-08 con el contador medido en esa fecha y registrado en el acta
 * de triaje (`specs/technical/auditoria_arquitectura_triage.md` §6).
 * Bajarlo es la direccion esperada; subirlo exige una decision explicita en el commit.
 */
const CEILING = 2466;

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
console.log(' VendGuard: Frontend Test Suite - Design token debt ratchet');
console.log('======================================================================\n');

/** @returns {{total: number, perFile: Record<string, number>}} */
function countHardcodedColors(directory) {
  const perFile = {};
  let total = 0;

  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const fullPath = join(directory, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === EXCLUDED_DIR) {
        continue;
      }
      const nested = countHardcodedColors(fullPath);
      total += nested.total;
      Object.assign(perFile, nested.perFile);
      continue;
    }

    if (!entry.name.endsWith('.js')) {
      continue;
    }

    const matches = readFileSync(fullPath, 'utf8').match(HEX_PATTERN);
    if (matches && matches.length > 0) {
      perFile[fullPath] = matches.length;
      total += matches.length;
    }
  }

  return { total, perFile };
}

const { total, perFile } = countHardcodedColors(ROOT);
const filesAffected = Object.keys(perFile).length;

console.log(`  Contador actual: ${total} literales hex en ${filesAffected} ficheros JS (techo ${CEILING}).\n`);

assert(
  '1.1 El escáner encuentra literales hex en el frontend (no está roto ni vacío)',
  total > 0 && filesAffected > 0,
  `total=${total}, ficheros=${filesAffected}`
);

assert(
  `1.2 La deuda de colores hardcodeados no supera el techo congelado (${CEILING})`,
  total <= CEILING,
  `contador=${total} > techo=${CEILING}. Usa var(--color-...) en el componente nuevo o baja el contador; subir el techo exige justificarlo en el commit.`
);

// El acta declara la cifra con separador de millar (2.466), así que se normaliza antes de
// comparar: la comprobación debe sobrevivir a cualquier configuración regional del intérprete.
const triageContent = readFileSync(TRIAGE_DOC, 'utf8');
assert(
  '1.3 El techo coincide con la cifra congelada en el acta de triaje (§6)',
  triageContent.replace(/\./g, '').includes(String(CEILING)),
  `El acta no declara ${CEILING}; actualiza specs/technical/auditoria_arquitectura_triage.md §6 junto con el techo.`
);

console.log('\n  Componentes con más deuda (candidatos naturales al refactor por tokens):');
for (const [file, count] of Object.entries(perFile).sort((a, b) => b[1] - a[1]).slice(0, 5)) {
  console.log(`    ${String(count).padStart(4)} ${file.replace(PROJECT_ROOT, '').replace(/\\/g, '/')}`);
}

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. DEUDA DE TOKENS CONGELADA.');
  console.log('======================================================================');
  process.exit(0);
}

console.log(' RESULTADO: FALLO EN LA CONGELACIÓN DE LA DEUDA DE TOKENS.');
console.log('======================================================================');
process.exit(1);
