/**
 * VendGuard - DateTimeFormat Unit Tests (DateTimeFormatTest.mjs)
 */

import { formatDateTime } from '../../public/assets/js/utils/DateTimeFormat.js';

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

console.log('--- Pruebas de DateTimeFormat ---');

assert('1. Nulos y vacíos devuelven guión',
  formatDateTime(null) === '—' &&
  formatDateTime(undefined) === '—' &&
  formatDateTime('') === '—' &&
  formatDateTime('   ') === '—'
);

const postgresShortTz = '2026-10-25 06:05:00+00';
const resPostgres = formatDateTime(postgresShortTz);
assert('2. PostgreSQL short offset +00 se formatea correctamente',
  resPostgres !== '—' && !resPostgres.includes('+00') && !resPostgres.includes('2026-'),
  `Obtenido: ${resPostgres}`
);

const standardIso = '2026-10-25T08:30:00Z';
const resIso = formatDateTime(standardIso);
assert('3. ISO estándar con Z se formatea correctamente',
  resIso !== '—' && !resIso.includes('T') && !resIso.includes('Z')
);

const sqlNoTz = '2026-10-25 12:00:00';
const resSql = formatDateTime(sqlNoTz);
assert('4. Formato SQL sin offset se interpreta como fecha válida',
  resSql !== '—' && !resSql.includes('12:00:00')
);

assert('5. Fecha inválida devuelve guión en lugar de la cadena cruda',
  formatDateTime('invalida') === '—' &&
  formatDateTime('2026-99-99 99:99') === '—'
);

console.log(`\nTotal: ${assertions} | Pasadas: ${assertions - failures} | Fallidas: ${failures}`);
if (failures > 0) process.exit(1);
