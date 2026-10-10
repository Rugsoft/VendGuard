/**
 * VendGuard - TechnicianSanitaryResolutionBlock Reactive Unit Tests
 * (TechnicianSanitaryResolutionBlockTest.mjs)
 *
 * Cierra el hueco 5 de la auditoría de trazabilidad del módulo 11 (T-PAUSE-25,
 * RF-03.5.2, Constitución Art. II): el bloque de declaraciones sanitarias del cierre
 * técnico estaba implementado pero sin una sola aserción, y una sonda manual
 * descubrió dos defectos reales que este arnés deja cubiertos:
 *
 *   1. `getPayload()` lanzaba `TypeError: Cannot read properties of null (reading
 *      'toFixed')` con la temperatura vacía o no numérica, y el vigilante reactivo lo
 *      invocaba en cada pulsación (bastaba borrar el campo o escribir una letra).
 *   2. El payload emitido declaraba siempre `stock_destroyed: true` y
 *      `hygiene_checklist: true`, afirmando hechas dos confirmaciones sin marcar.
 *
 * Grupos:
 *   1. Contrato del componente y de las tres declaraciones.
 *   2. Matriz de validez del bloque completo.
 *   3. Lectura térmica: coma decimal, bordes del rango, vacío y no numérico.
 *   4. Mensajes de error de `validate()` uno a uno.
 *   5. Payload y emisión reactiva fiel al estado real (regresión de los dos defectos).
 *   6. Ergonomía móvil y estado deshabilitado (RNF-03).
 *
 * Dogma Vanilla: ejecutable con `node` sin dependencias npm.
 */

import { TechnicianSanitaryResolutionBlock } from '../../public/assets/js/components/TechnicianSanitaryResolutionBlock.js';
import { SANITARY_TEMPERATURE_RANGE } from '../../public/assets/js/utils/IncidentStatusPermissions.js';

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
console.log(' VendGuard: Frontend Test Suite - TechnicianSanitaryResolutionBlock (T-PAUSE-25)');
console.log('======================================================================\n');

/** Instancia ligera con los computed como getters y los métodos enlazados. */
const createInstance = (props = {}) => {
  const instance = {
    ...TechnicianSanitaryResolutionBlock.data(),
    disabled: props.disabled ?? false,
    emitted: [],
    $emit(event, payload) {
      this.emitted.push({ event, payload });
    }
  };

  for (const [key, fn] of Object.entries(TechnicianSanitaryResolutionBlock.computed)) {
    Object.defineProperty(instance, key, { get: fn.bind(instance), configurable: true });
  }

  for (const [key, fn] of Object.entries(TechnicianSanitaryResolutionBlock.methods)) {
    instance[key] = fn.bind(instance);
  }

  return instance;
};

const template = TechnicianSanitaryResolutionBlock.template;

// ─── Grupo 1: Contrato del componente ─────────────────────────────────────────
console.log('--- Grupo 1: Contrato del bloque y de las tres declaraciones (RF-03.5.2, Art. II) ---');

assert('1.1 El componente declara su nombre y el canal de cambio hacia la vista',
  TechnicianSanitaryResolutionBlock.name === 'TechnicianSanitaryResolutionBlock'
    && Array.isArray(TechnicianSanitaryResolutionBlock.emits)
    && TechnicianSanitaryResolutionBlock.emits.includes('change'));

const block = createInstance();
assert('1.2 Nace sin declaración alguna: temperatura vacía y las dos casillas sin marcar',
  block.temperatureC === '' && block.stockDestroyed === false && block.hygieneChecklist === false);

assert('1.3 El rango plausible es -40/80 y no vive suelto en el componente',
  block.minTemperature === -40.0
    && block.maxTemperature === 80.0
    && SANITARY_TEMPERATURE_RANGE.min === -40.0
    && SANITARY_TEMPERATURE_RANGE.max === 80.0
    && block.minTemperature === SANITARY_TEMPERATURE_RANGE.min
    && block.maxTemperature === SANITARY_TEMPERATURE_RANGE.max,
  `frontend [${block.minTemperature}, ${block.maxTemperature}]`);

assert('1.4 El bloque publica las tres declaraciones exigidas por Art. II en su plantilla',
  template.includes('Temperatura real del recinto térmico')
    && template.includes('retirada y destrucción del stock perecedero')
    && template.includes('checklist de higienización sanitaria'));

// ─── Grupo 2: Matriz de validez ───────────────────────────────────────────────
console.log('\n--- Grupo 2: Matriz de validez del bloque completo ---');

const matrix = createInstance();
assert('2.1 Bloque vacío => no válido', matrix.isValid === false && matrix.validate().isValid === false);

matrix.temperatureC = '3,5';
assert('2.2 Sólo con la lectura térmica todavía no se puede cerrar',
  matrix.isTemperatureValid === true && matrix.isValid === false);

matrix.stockDestroyed = true;
assert('2.3 Con la retirada del stock y sin checklist sigue bloqueado',
  matrix.isValid === false && matrix.validate().error.includes('higienización'));

matrix.hygieneChecklist = true;
assert('2.4 Con las tres declaraciones el bloque es válido',
  matrix.isValid === true && matrix.validate().isValid === true);

matrix.stockDestroyed = false;
assert('2.5 Desmarcar una confirmación vuelve a invalidar el bloque y nombra la que falta',
  matrix.isValid === false && matrix.validate().error.includes('retirada y destrucción'));

// ─── Grupo 3: Lectura térmica ─────────────────────────────────────────────────
console.log('\n--- Grupo 3: Lectura térmica, coma decimal y bordes del rango ---');

const temperatureCases = [
  ['3.1', '3,5', true, 'la coma decimal de los teclados móviles se interpreta'],
  ['3.2', '3.5', true, 'el punto decimal también se acepta'],
  ['3.3', '-40', true, 'el borde inferior es inclusivo'],
  ['3.4', '80', true, 'el borde superior es inclusivo'],
  ['3.5', '-40.1', false, 'un grado por debajo del borde se rechaza'],
  ['3.6', '80.1', false, 'un grado por encima del borde se rechaza'],
  ['3.7', '120', false, 'una lectura increíble se rechaza'],
  ['3.8', 'abc', false, 'una cadena no numérica se rechaza sin reventar'],
  ['3.9', '', false, 'el campo vacío se rechaza sin reventar']
];

for (const [id, value, expected, title] of temperatureCases) {
  const subject = createInstance();
  subject.temperatureC = value;
  subject.stockDestroyed = true;
  subject.hygieneChecklist = true;

  let crashed = false;
  try {
    subject.emitChange();
  } catch (error) {
    crashed = true;
  }

  assert(`${id} Con temperatura "${value}", ${title}`,
    subject.isTemperatureValid === expected && subject.isValid === expected && crashed === false,
    `isTemperatureValid=${subject.isTemperatureValid} isValid=${subject.isValid} crashed=${crashed}`);
}

// ─── Grupo 4: Mensajes de error ───────────────────────────────────────────────
console.log('\n--- Grupo 4: Mensajes de error de validate() ---');

const errorCases = [
  ['4.1 Bloque vacío', {}, 'temperatura real del recinto térmico'],
  ['4.2 Lectura fuera de rango', { temperatureC: '120', stockDestroyed: true, hygieneChecklist: true }, 'fuera del rango plausible'],
  ['4.3 Sin retirada de stock', { temperatureC: '3,5', hygieneChecklist: true }, 'retirada y destrucción del stock'],
  ['4.4 Sin checklist de higienización', { temperatureC: '3,5', stockDestroyed: true }, 'checklist de higienización']
];

for (const [id, state, expectedFragment] of errorCases) {
  const subject = createInstance();
  Object.assign(subject, state);
  const verdict = subject.validate();
  assert(`${id} El rechazo explica qué falta y por qué`,
    verdict.isValid === false && verdict.error.includes(expectedFragment),
    `error: ${verdict.error}`);
}

const complete = createInstance();
Object.assign(complete, { temperatureC: '2,5', stockDestroyed: true, hygieneChecklist: true });
assert('4.5 El camino válido no arrastra mensaje de error y publica el payload del contrato',
  complete.validate().isValid === true
    && complete.validate().error === ''
    && complete.validate().payload.sanitary_declarations.temperature_c === 2.5);

// ─── Grupo 5: Payload fiel al estado real (regresión de los dos defectos) ─────
console.log('\n--- Grupo 5: Payload y emisión reactiva fieles al estado real ---');

const honest = createInstance();
honest.stockDestroyed = false;
honest.hygieneChecklist = false;
assert('5.1 Sin marcar nada, el payload no declara hechas las confirmaciones pendientes',
  honest.getPayload().stock_destroyed === false && honest.getPayload().hygiene_checklist === false);

honest.stockDestroyed = true;
assert('5.2 Marcar la retirada del stock viaja en el payload aunque el checklist siga pendiente',
  honest.getPayload().stock_destroyed === true && honest.getPayload().hygiene_checklist === false);

honest.temperatureC = '4';
assert('5.3 La temperatura válida se normaliza a dos decimales en el payload',
  honest.getPayload().temperature_c === 4);

let emitCrash = null;
try {
  const reactive = createInstance();
  reactive.temperatureC = 'abc';
  TechnicianSanitaryResolutionBlock.watch.temperatureC.call(reactive);
  reactive.temperatureC = '';
  TechnicianSanitaryResolutionBlock.watch.temperatureC.call(reactive);
} catch (error) {
  emitCrash = error;
}
assert('5.4 Vaciar el campo o escribir una letra ya no revienta el vigilante reactivo',
  emitCrash === null,
  emitCrash ? `${emitCrash.name}: ${emitCrash.message}` : '');

const emitter = createInstance();
emitter.stockDestroyed = true;
TechnicianSanitaryResolutionBlock.watch.stockDestroyed.call(emitter);
assert('5.5 La emisión reactiva publica la validez real y el payload del contrato',
  emitter.emitted.length === 1
    && emitter.emitted[0].event === 'change'
    && emitter.emitted[0].payload.isValid === false
    && emitter.emitted[0].payload.stock_destroyed === true
    && emitter.emitted[0].payload.temperature_c === null,
  JSON.stringify(emitter.emitted));

// ─── Grupo 6: Ergonomía móvil ─────────────────────────────────────────────────
console.log('\n--- Grupo 6: Ergonomía táctil y estado deshabilitado (RNF-03) ---');

assert('6.1 La entrada de temperatura y las dos casillas respetan el objetivo táctil de 44 px',
  template.includes('min-height: 44px')
    && (template.match(/min-height: 44px/g) || []).length >= 3);

assert('6.2 Las tres declaraciones llevan identificador estable para las pruebas de interfaz',
  template.includes('data-testid="sanitary-temperature-input"')
    && template.includes('data-testid="sanitary-stock-destroyed-checkbox"')
    && template.includes('data-testid="sanitary-hygiene-checklist-checkbox"'));

assert('6.3 La entrada acepta el teclado decimal de los terminales móviles',
  template.includes('inputmode="decimal"'));

assert('6.4 El estado deshabilitado se propaga a los tres controles',
  ['disabled'].every((key) => Object.prototype.hasOwnProperty.call(TechnicianSanitaryResolutionBlock.props, key))
    && (template.match(/:disabled="disabled"/g) || []).length >= 3);

assert('6.5 La insignia de riesgo térmico reutiliza tokens del sistema, sin color nuevo',
  template.includes('var(--color-urgency-critical)')
    && template.includes('var(--color-urgency-critical-bg)')
    && !/#[0-9a-fA-F]{6}/.test(template));

// Resumen final
console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Pasadas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-25 CUMPLIDA SATISFACTORIAMENTE.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULTADO: HAY ${failures} FALLOS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
