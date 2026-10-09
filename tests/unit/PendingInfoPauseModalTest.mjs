/**
 * VendGuard - PendingInfoPauseModal Reactive Unit Tests (PendingInfoPauseModalTest.mjs)
 * 
 * Verifica las condiciones de T-PAUSE-16 (y T-PAUSE-20):
 * 1. Renderizado y disponibilidad de las 4 causas tipificadas.
 * 2. Bloqueo del botón de envío con 0 a 19 caracteres (isValidLength: false, canSubmit: false).
 * 3. Habilitación del botón con 20 caracteres reales y causa seleccionada.
 * 4. Botones táctiles con altura mínima de 44px (RNF-03).
 * 5. Guardián de borrador sucio solicitando confirmación si el usuario presiona Escape o hace clic en backdrop.
 * 6. Restablecimiento del formulario tras éxito o cierre limpio.
 */

import { PendingInfoPauseModal, PAUSE_REASON_CATEGORIES } from '../../public/assets/js/components/PendingInfoPauseModal.js';
import { api } from '../../public/assets/js/api.js';

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
console.log(' VendGuard: Frontend Test Suite - PendingInfoPauseModal (T-PAUSE-16)');
console.log('======================================================================\n');

// 1. Selector de 4 causas tipificadas
console.log('--- Grupo 1: Selector de 4 causas tipificadas (RF-01.2) ---');
assert('1.1 Constante PAUSE_REASON_CATEGORIES define exactamente 4 opciones',
  Array.isArray(PAUSE_REASON_CATEGORIES) && PAUSE_REASON_CATEGORIES.length === 4);

const expectedKeys = [
  'BUILDING_CLOSED_NO_ACCESS',
  'MACHINE_LOCATION_NOT_FOUND',
  'EXTERNAL_POWER_CUT',
  'PENDING_SITE_AUTHORIZATION'
];
const actualKeys = PAUSE_REASON_CATEGORIES.map(c => c.value);
assert('1.2 Las 4 causas coinciden con el catálogo de dominio',
  expectedKeys.every(k => actualKeys.includes(k)));

// 2. Instanciación y estado inicial reactivo
console.log('\n--- Grupo 2: Validación Reactiva de Longitud y Bloqueo de Envío (RF-01.3, Art. V.1) ---');
const createInstance = (props = {}) => {
  const data = PendingInfoPauseModal.data();
  const instance = {
    ...data,
    isOpen: props.isOpen ?? true,
    incident: props.incident ?? { id: 101, ticket_code: 'INC-2026-0101', machine: { code: 'VEND-0101' } },
    role: props.role ?? 'TECHNICIAN',
    emitted: {},
    $emit(event, payload) {
      if (!this.emitted[event]) this.emitted[event] = [];
      this.emitted[event].push(payload);
    }
  };

  // Enlazar getters computados
  for (const [key, fn] of Object.entries(PendingInfoPauseModal.computed)) {
    Object.defineProperty(instance, key, {
      get: fn.bind(instance),
      configurable: true
    });
  }

  // Enlazar métodos
  for (const [key, fn] of Object.entries(PendingInfoPauseModal.methods)) {
    instance[key] = fn.bind(instance);
  }

  return instance;
};

const modal = createInstance();

assert('2.1 Inicialmente la longitud es 0 y faltan 20 caracteres',
  modal.trimmedReasonLength === 0 && modal.remainingChars === 20);
assert('2.2 isValidLength es false con texto vacío',
  modal.isValidLength === false);
assert('2.3 canSubmit es false sin causa ni texto',
  modal.canSubmit === false);

// 2.4 Seleccionar causa sin texto suficiente
modal.selectCategory('BUILDING_CLOSED_NO_ACCESS');
assert('2.4 Causa seleccionada pero sin texto aún no permite enviar',
  modal.selectedCategory === 'BUILDING_CLOSED_NO_ACCESS' && modal.canSubmit === false);

// 2.5 Texto de 1 a 19 caracteres
modal.reasonText = '1234567890123456789'; // 19 caracteres
assert('2.5 Con 19 caracteres exactos, faltan 1 y canSubmit sigue false',
  modal.trimmedReasonLength === 19 && modal.remainingChars === 1 && modal.canSubmit === false && modal.isValidLength === false);

// 2.6 Texto de 20 caracteres con espacios que se recortan (< 20 útiles)
modal.reasonText = '   1234567890123456789   '; // 19 reales + espacios
assert('2.6 Espacios en blanco superfluos no cuentan para el mínimo legal (Art. V.1)',
  modal.trimmedReasonLength === 19 && modal.isValidLength === false);

// 2.7 Texto de 20 caracteres reales
modal.reasonText = '12345678901234567890'; // 20 caracteres
assert('2.7 Con 20 caracteres reales, isValidLength es true y remainingChars es 0',
  modal.trimmedReasonLength === 20 && modal.remainingChars === 0 && modal.isValidLength === true);
assert('2.8 canSubmit pasa a true con causa y 20 caracteres',
  modal.canSubmit === true);

// 3. Ergonomía móvil con botones >= 44px (RNF-03)
console.log('\n--- Grupo 3: Ergonomía Táctil Móvil >= 44px (RNF-03) ---');
const templateStr = PendingInfoPauseModal.template;
assert('3.1 Los botones de causa especifican altura mínima táctil minHeight: 44px',
  templateStr.includes("minHeight: '44px'"));
assert('3.2 El botón de cerrar cabecera cumple altura y anchura mínima táctil de 44px',
  templateStr.includes('min-height: 44px') && templateStr.includes('min-width: 44px'));
assert('3.3 Los botones de acción de pie cumplen min-height: 44px',
  templateStr.includes('min-height: 44px; min-width: 140px') && templateStr.includes('min-height: 44px; min-width: 90px'));

// 4. Guardián de borrador sucio (RNF-06)
console.log('\n--- Grupo 4: Guardián de Borrador Sucio ante Escape / Clic Exterior (RNF-06) ---');
let confirmPrompt = null;
let confirmResult = false;
globalThis.confirm = (msg) => {
  confirmPrompt = msg;
  return confirmResult;
};

// 4.1 Formulario limpio: cierra sin preguntar
const cleanModal = createInstance();
assert('4.1 Formulario sin cambios no se considera sucio (isDirty === false)',
  cleanModal.isDirty === false);
cleanModal.requestClose();
assert('4.2 Formulario limpio emite "close" sin confirmación',
  cleanModal.emitted['close']?.length === 1 && confirmPrompt === null);

// 4.2 Formulario sucio con rechazo del usuario
const dirtyModal = createInstance();
dirtyModal.reasonText = 'Texto en redacción...';
assert('4.3 Formulario con texto se considera sucio (isDirty === true)',
  dirtyModal.isDirty === true);

confirmPrompt = null;
confirmResult = false; // El usuario dice "No descartar"
dirtyModal.handleKeyDown({ key: 'Escape', preventDefault() {} });
assert('4.4 Pulsar Escape con borrador sucio solicita confirmación al usuario',
  confirmPrompt !== null && confirmPrompt.includes('borrador'));
assert('4.5 Si el usuario rechaza descartar, no se emite close y el borrador se conserva',
  !dirtyModal.emitted['close'] && dirtyModal.reasonText === 'Texto en redacción...');

// 4.3 Clic en backdrop con aceptación del usuario
confirmPrompt = null;
confirmResult = true; // El usuario dice "Sí, descartar"
const fakeBackdropEvent = { target: 'backdrop', currentTarget: 'backdrop' };
dirtyModal.handleBackdropClick(fakeBackdropEvent);
assert('4.6 Clic exterior con aceptación emite close y reinicia el formulario',
  dirtyModal.emitted['close']?.length === 1 && dirtyModal.reasonText === '');

// 5. Envío exitoso y emisión de evento paused
console.log('\n--- Grupo 5: Envío Exitoso y Notificación al Padre ---');
const submitModal = createInstance();
submitModal.selectCategory('MACHINE_LOCATION_NOT_FOUND');
submitModal.reasonText = 'La máquina no se encuentra en la sala 2B indicada por la sede.';

let pauseApiCalled = false;
api.pauseIncidentPendingInfo = async (id, cat, text, role) => {
  pauseApiCalled = true;
  return {
    incident_id: id,
    status: 'PENDING_INFO',
    reason_category: cat,
    reason_text: text,
    is_sla_paused: true
  };
};

await submitModal.submitPause();
assert('5.1 submitPause invoca api.pauseIncidentPendingInfo', pauseApiCalled === true);
assert('5.2 submitPause emite evento "paused" con el payload y el identificador',
  submitModal.emitted['paused']?.length === 1 &&
  submitModal.emitted['paused'][0].incidentId === 101 &&
  submitModal.emitted['paused'][0].ticketCode === 'INC-2026-0101');
assert('5.3 submitPause emite "close" y restablece el formulario tras el éxito',
  submitModal.emitted['close']?.length === 1 && submitModal.reasonText === '' && submitModal.selectedCategory === '');

// Resumen final
console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Pasadas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-16 CUMPLIDA SATISFACTORIAMENTE.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULTADO: HAY ${failures} FALLOS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
