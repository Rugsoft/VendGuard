/**
 * VendGuard - PendingInfoPauseModal Reactive Unit Tests (PendingInfoPauseModalTest.mjs)
 * 
 * Verifica las condiciones de T-PAUSE-16 y la condición «Hecho cuando» de T-PAUSE-20:
 * 1. Renderizado y disponibilidad de las 4 causas tipificadas.
 * 2. Bloqueo del botón de envío con TODAS las longitudes de 0 a 19 caracteres reales
 *    (barrido exhaustivo, isValidLength: false, canSubmit: false).
 * 3. Habilitación del botón con 20 caracteres reales y causa seleccionada.
 * 4. Botones táctiles con altura mínima de 44px (RNF-03).
 * 5. Guardián de borrador sucio solicitando confirmación si el usuario presiona Escape o hace clic en backdrop.
 * 6. Restablecimiento del formulario tras éxito o cierre limpio.
 * 7. Contrato de la llamada de pausa por rol (técnico y coordinador) y camino de error:
 *    un rechazo del servidor se muestra sin perder el borrador ni cerrar el modal (RNF-06, RF-08.4).
 */

import { PendingInfoPauseModal, PAUSE_REASON_CATEGORIES } from '../../public/assets/js/components/PendingInfoPauseModal.js';
import { api, ApiError } from '../../public/assets/js/api.js';

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
console.log(' VendGuard: Frontend Test Suite - PendingInfoPauseModal (T-PAUSE-16, T-PAUSE-20)');
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

// 2.9 Barrido exhaustivo del rango legal (T-PAUSE-20): NINGUNA longitud de 0 a 19
// caracteres reales habilita el envío, con la causa ya seleccionada.
const blockedLengths = [];
for (let length = 0; length <= 19; length++) {
  modal.reasonText = 'x'.repeat(length);
  if (modal.canSubmit !== false || modal.isValidLength !== false) {
    blockedLengths.push(length);
  }
}
assert('2.9 Con la causa seleccionada, ninguna justificación de 0 a 19 caracteres permite enviar',
  blockedLengths.length === 0,
  `Longitudes que escaparon del bloqueo: ${blockedLengths.join(', ')}`);

// 2.10 El componente nunca confirma el envío por encima del mínimo con texto recortado.
modal.reasonText = `  ${'y'.repeat(20)}  `;
assert('2.10 Con espacios alrededor, los 20 caracteres reales siguen habilitando el envío',
  modal.trimmedReasonLength === 20 && modal.canSubmit === true);

// 3. Ergonomía móvil con botones >= 44px (RNF-03)
console.log('\n--- Grupo 3: Ergonomía Táctil Móvil >= 44px (RNF-03) ---');
const templateStr = PendingInfoPauseModal.template;
assert('3.1 Los botones de causa especifican altura mínima táctil minHeight: 44px',
  templateStr.includes("minHeight: '44px'"));
assert('3.2 El botón de cerrar cabecera cumple altura y anchura mínima táctil de 44px',
  templateStr.includes('min-height: 44px') && templateStr.includes('min-width: 44px'));
// 3.3.b Contrato de layout a 360 px (RNF-03, T-PAUSE-27): el modal debe caber y ser
//       operable en el terminal más estrecho que declara la especificación.
const modalTemplate = PendingInfoPauseModal.template;
const minWidths = [...modalTemplate.matchAll(/min-width:\s*(\d+)px/g)].map(m => Number(m[1]));
const fontSizes = [...modalTemplate.matchAll(/font-size:\s*(\d+)px/g)].map(m => Number(m[1]));

// `max-width` no puede contar como ancho fijo: es justo la cota que hace fluido el diálogo.
const fixedWidths = [...modalTemplate.matchAll(/(?<!max-)(?<!min-)\bwidth:\s*(\d+)px/g)].map(m => Number(m[1]));
assert('3.3.b.1 El diálogo ocupa el 100 % del ancho disponible en vez de un ancho fijo',
  modalTemplate.includes('width: 100%') && fixedWidths.length === 0,
  `anchos fijos declarados: ${JSON.stringify(fixedWidths)}`);

assert('3.3.b.2 El backdrop deja 16 px de margen por lado: a 360 px el diálogo mide 328 px',
  modalTemplate.includes('padding: 16px') && modalTemplate.includes('box-sizing: border-box'));

assert('3.3.b.3 Ningún min-width puede desbordar los 328 px útiles (el mayor es un botón de 140 px)',
  minWidths.length > 0 && Math.max(...minWidths) <= 160,
  `min-width declarados: ${JSON.stringify(minWidths)}`);

assert('3.3.b.4 El cuerpo del formulario es de una sola columna',
  modalTemplate.includes('flex-direction: column') && !modalTemplate.includes('grid-template-columns'));

assert('3.3.b.5 El modal se desplaza en vertical en pantallas bajas en vez de desbordarlas',
  modalTemplate.includes('max-height: 90vh') && modalTemplate.includes('overflow-y: auto'));

assert('3.3.b.6 La tipografía nunca baja de 12 px en el terminal móvil',
  fontSizes.length > 0 && Math.min(...fontSizes) >= 12,
  `font-size declarados: ${JSON.stringify(fontSizes)}`);

// Los cuatro botones de causa se pintan con la forma camelCase del binding de estilo; los
// tres restantes (cerrar y pie de acciones) lo declaran como CSS en línea.
const literalTouchTargets = (modalTemplate.match(/min-height: 44px/g) || []).length;
assert('3.3.b.7 Los cuatro botones de causa y los tres de acción respetan el objetivo táctil de 44 px',
  literalTouchTargets >= 3 && modalTemplate.includes("minHeight: '44px'"),
  `min-height literales: ${literalTouchTargets} · binding de causas presente: ${modalTemplate.includes("minHeight: '44px'")}`);

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

// 5.4 El contrato de la llamada viaja completo y con el texto recortado (RF-01.3, RNF-02).
let pauseCall = null;
api.pauseIncidentPendingInfo = async (id, category, text, role) => {
  pauseCall = { id, category, text, role };
  return { incident_id: id, status: 'PENDING_INFO', is_sla_paused: true };
};

const technicianModal = createInstance({ role: 'TECHNICIAN' });
technicianModal.selectCategory('PENDING_SITE_AUTHORIZATION');
technicianModal.reasonText = '  El conserje no ha dejado la llave y la sala sigue cerrada con llave.  ';
await technicianModal.submitPause();
assert('5.4 El técnico envía causa, justificación recortada y su rol al endpoint de pausa',
  pauseCall?.id === 101 && pauseCall?.category === 'PENDING_SITE_AUTHORIZATION' &&
  pauseCall?.text === 'El conserje no ha dejado la llave y la sala sigue cerrada con llave.' &&
  pauseCall?.role === 'TECHNICIAN',
  JSON.stringify(pauseCall));
assert('5.5 Tras el éxito el formulario queda limpio y sin envío en curso',
  technicianModal.reasonText === '' && technicianModal.selectedCategory === '' &&
  technicianModal.isSubmitting === false && technicianModal.errorMessage === '');

// 5.6 La misma instancia reutilizable sirve al canal de Coordinación (RF-01.1: ambos roles).
const coordinatorModal = createInstance({ role: 'COORDINATOR' });
coordinatorModal.selectCategory('EXTERNAL_POWER_CUT');
coordinatorModal.reasonText = 'Corte eléctrico del cuadro general ajeno a la máquina y a la sede.';
await coordinatorModal.submitPause();
assert('5.6 La pausa declarada desde coordinación viaja con su propio rol',
  pauseCall?.role === 'COORDINATOR' && pauseCall?.category === 'EXTERNAL_POWER_CUT',
  JSON.stringify(pauseCall));

// 6. Camino de error y guardián con el modal cerrado
console.log('\n--- Grupo 6: Rechazo del servidor y guardián fuera de la pausa en curso ---');

const rejectedModal = createInstance();
rejectedModal.selectCategory('BUILDING_CLOSED_NO_ACCESS');
rejectedModal.reasonText = 'El edificio permanece cerrado por obras y no hay acceso al cuarto de máquinas.';
api.pauseIncidentPendingInfo = async () => {
  throw new ApiError(422, 'INVALID_STATUS_FOR_PAUSE', 'La incidencia ya está pausada por bloqueo de sede.');
};

await rejectedModal.submitPause();
assert('6.1 Un rechazo del servidor se muestra al usuario con el mensaje de la API (RF-08.4)',
  rejectedModal.errorMessage === 'La incidencia ya está pausada por bloqueo de sede.');
assert('6.2 El rechazo conserva íntegro el borrador y no cierra el modal',
  rejectedModal.reasonText === 'El edificio permanece cerrado por obras y no hay acceso al cuarto de máquinas.' &&
  rejectedModal.selectedCategory === 'BUILDING_CLOSED_NO_ACCESS' &&
  !rejectedModal.emitted['close'] && !rejectedModal.emitted['paused']);
assert('6.3 El indicador de envío en curso se libera también cuando la pausa falla',
  rejectedModal.isSubmitting === false);
assert('6.4 Un error sin envoltorio ApiError también publica su mensaje sin romper el modal',
  await (async () => {
    const genericModal = createInstance();
    genericModal.selectCategory('MACHINE_LOCATION_NOT_FOUND');
    genericModal.reasonText = 'La máquina no aparece en ninguna de las plantas indicadas por la sede.';
    api.pauseIncidentPendingInfo = async () => { throw new Error('Red no disponible'); };
    await genericModal.submitPause();
    return genericModal.errorMessage === 'Red no disponible' && genericModal.isSubmitting === false;
  })());

// 6.5 Escape con el modal cerrado no dispara ninguna confirmación ni evento (isOpen false).
const closedModal = createInstance({ isOpen: false });
closedModal.reasonText = 'Borrador que no debe evaluarse';
let closedConfirmCount = 0;
const previousConfirm = globalThis.confirm;
globalThis.confirm = () => { closedConfirmCount++; return true; };
closedModal.handleKeyDown({ key: 'Escape', preventDefault() {} });
globalThis.confirm = previousConfirm;
assert('6.5 Escape fuera de la pausa en curso no pide confirmación ni cierra nada',
  closedConfirmCount === 0 && !closedModal.emitted['close'] && closedModal.reasonText === 'Borrador que no debe evaluarse');
assert('6.6 Seleccionar causa limpia el error anterior para no arrastrar avisos obsoletos',
  await (async () => {
    const reusableModal = createInstance();
    reusableModal.errorMessage = 'Error anterior del servidor';
    reusableModal.selectCategory('EXTERNAL_POWER_CUT');
    return reusableModal.errorMessage === '';
  })());

// Resumen final
console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Pasadas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. CONDICIONES T-PAUSE-16 Y T-PAUSE-20 CUMPLIDAS SATISFACTORIAMENTE.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULTADO: HAY ${failures} FALLOS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
