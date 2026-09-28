/**
 * VendGuard - TechnicianSparePartsModals Test Suite (TechnicianSparePartsModalsTest.mjs)
 * 
 * Valida la funcionalidad reactiva y los requisitos de:
 * 1. TechnicianSparePartsPauseModal.js (RF-REP-03, RF-REP-04, RNF-REP-03).
 * 2. TechnicianResolutionPartsBlock.js (RF-REP-05, RF-REP-06, RF-REP-07, RNF-REP-03).
 * 
 * Hecho cuando:
 * - TechnicianSparePartsPauseModal.js permite seleccionar piezas compatibles o justificar piezas fuera de catálogo (>= 20 chars).
 * - TechnicianResolutionPartsBlock.js exige la respuesta obligatoria Sí/No y despliega selectores táctiles de repuestos,
 *   cantidades (1–50) y destino DESGUACE/TALLER con objetivos táctiles móviles >= 44px.
 * - node tests/unit/TechnicianSparePartsModalsTest.mjs pasa al 100% en verde.
 * 
 * Dogma Vanilla: Node.js nativo con módulos ESM y cero dependencias externas.
 */

// Mock de entorno navegador para Node.js ESM
const storageMock = (() => {
  let store = {};
  return {
    getItem: (key) => store[key] || null,
    setItem: (key, val) => { store[key] = String(val); },
    removeItem: (key) => { delete store[key]; },
    clear: () => { store = {}; }
  };
})();
globalThis.localStorage = storageMock;
globalThis.window = {
  location: { search: '', href: 'http://localhost/' }
};

import { api } from '../../public/assets/js/api.js';
import { TechnicianSparePartsPauseModal } from '../../public/assets/js/components/TechnicianSparePartsPauseModal.js';
import { TechnicianResolutionPartsBlock } from '../../public/assets/js/components/TechnicianResolutionPartsBlock.js';

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
console.log(' VendGuard: Frontend Test Suite - TechnicianSparePartsModals (T-SPARE-16)');
console.log('======================================================================\n');

// Mock Fixtures
const mockIncident = {
  id: 105,
  ticket_code: 'INC-2026-0105',
  machine_id: 1,
  machine: {
    id: 1,
    code: 'VEND-0101',
    model: 'Sanden Vendo G-Drink'
  }
};

const mockCompatibleParts = [
  {
    id: 1,
    part_code: 'VALV-SOL-01',
    name: 'Electroválvula 24V Ceme 2 Vías',
    category: 'HYDRAULIC',
    category_label: 'Hidráulica y Presión',
    reference_cost: 28.50
  },
  {
    id: 2,
    part_code: 'SOND-NTC-02',
    name: 'Sonda Térmica NTC 10K',
    category: 'THERMAL',
    category_label: 'Térmico y Refrigeración',
    reference_cost: 14.20
  }
];

let apiPausePayload = null;

api.technician.getSparePartsCatalog = async (machineId, incidentId) => {
  return { success: true, data: mockCompatibleParts };
};

api.technician.pauseIncident = async (incidentId, payload) => {
  apiPausePayload = { incidentId, payload };
  return {
    success: true,
    data: {
      id: incidentId,
      status: 'PENDING_PARTS',
      ...payload
    }
  };
};

function createComponentInstance(componentDef, props = {}) {
  const comp = {
    ...componentDef.data(),
    ...props,
    ...componentDef.methods,
    _emitted: {},
    $emit(eventName, payload) {
      this._emitted[eventName] = this._emitted[eventName] || [];
      this._emitted[eventName].push(payload !== undefined ? payload : true);
    }
  };

  if (componentDef.computed) {
    for (const [key, getter] of Object.entries(componentDef.computed)) {
      Object.defineProperty(comp, key, {
        get: getter,
        configurable: true
      });
    }
  }

  return comp;
}

// =========================================================================
// PARTE 1: TechnicianSparePartsPauseModal.js
// =========================================================================
console.log('--- PARTE 1: Pruebas de TechnicianSparePartsPauseModal.js ---');

const pauseModal = createComponentInstance(TechnicianSparePartsPauseModal, {
  modelValue: true,
  incident: mockIncident
});

// 1.1 Carga inicial de piezas compatibles
await pauseModal.initModal();
assert('1.1 initModal carga piezas compatibles para la máquina de la avería',
  pauseModal.compatibleParts.length === 2 && pauseModal.compatibleParts[0].part_code === 'VALV-SOL-01');
assert('1.2 canSubmit es false inicialmente (sin piezas ni fuera de catálogo)', pauseModal.canSubmit === false);

// 1.2 Rechazo al enviar sin repuestos
await pauseModal.submitPause();
assert('1.3 submitPause rechaza la solicitud si no hay piezas seleccionadas',
  pauseModal.errorMessage.includes('Debe seleccionar al menos un repuesto compatible'));

// 1.3 Selección estructurada de piezas de catálogo
pauseModal.selectedPartIdToAdd = '1';
pauseModal.quantityToAdd = 2;
pauseModal.addSelectedPart();

assert('1.4 addSelectedPart añade pieza a la lista de repuestos solicitados',
  pauseModal.selectedParts.length === 1 && pauseModal.selectedParts[0].spare_part_id === 1);
assert('1.5 Cantidad seleccionada es 2 unidades', pauseModal.selectedParts[0].quantity === 2);
assert('1.6 canSubmit pasa a true tras añadir pieza de catálogo', pauseModal.canSubmit === true);

// 1.4 Modificación táctil de cantidades
const selectedPart = pauseModal.selectedParts[0];
pauseModal.changePartQuantity(selectedPart, 1);
assert('1.7 changePartQuantity incrementa cantidad a 3', selectedPart.quantity === 3);

pauseModal.changePartQuantity(selectedPart, -2);
assert('1.8 changePartQuantity decrementa cantidad a 1', selectedPart.quantity === 1);

// 1.5 Envío de solicitud estructurada de catálogo
await pauseModal.submitPause();
assert('1.9 submitPause invoca api.technician.pauseIncident con requested_parts',
  apiPausePayload?.payload?.is_out_of_catalog === false &&
  apiPausePayload?.payload?.requested_parts?.length === 1 &&
  apiPausePayload?.payload?.requested_parts[0].spare_part_id === 1 &&
  apiPausePayload?.payload?.requested_parts[0].quantity === 1);
assert('1.10 submitPause emite evento paused', (pauseModal._emitted['paused'] || []).length === 1);

// 1.6 Modo Pieza Fuera de Catálogo (RF-REP-04)
pauseModal.setOutOfCatalogMode(true);
assert('1.11 setOutOfCatalogMode activa isOutOfCatalog = true', pauseModal.isOutOfCatalog === true);
assert('1.12 canSubmit es false sin justificación técnica suficiente', pauseModal.canSubmit === false);

// Justificación insuficiente (< 20 chars)
pauseModal.customPartDescription = 'Sensor roto';
await pauseModal.submitPause();
assert('1.13 submitPause rechaza justificación menor a 20 caracteres',
  pauseModal.errorMessage.includes('debe tener al menos 20 caracteres'));

// Justificación reglamentaria (>= 20 chars)
pauseModal.customPartDescription = 'Sensor de caída infrarrojo especial para canal dispensador 4';
assert('1.14 canSubmit es true con justificación de 60 caracteres (>= 20)', pauseModal.canSubmit === true);

await pauseModal.submitPause();
assert('1.15 submitPause envía payload fuera de catálogo con requested_parts vacío',
  apiPausePayload?.payload?.is_out_of_catalog === true &&
  apiPausePayload?.payload?.requested_parts?.length === 0 &&
  apiPausePayload?.payload?.custom_part_description === 'Sensor de caída infrarrojo especial para canal dispensador 4');

// 1.7 Directrices de diseño en template
const pauseTemplate = TechnicianSparePartsPauseModal.template;
assert('1.16 Template del modal incluye touch targets >= 44px (min-height / min-width)',
  pauseTemplate.includes('min-height: 44px') || pauseTemplate.includes('minHeight: \'44px\''));
assert('1.17 Template incluye color de advertencia #f8b60f', pauseTemplate.includes('#f8b60f'));
assert('1.18 Template incluye data-testids requeridos',
  pauseTemplate.includes('data-testid="technician-spare-parts-pause-modal"') &&
  pauseTemplate.includes('data-testid="toggle-mode-catalog"') &&
  pauseTemplate.includes('data-testid="btn-confirm-pause"'));
assert('1.19 Modo fuera de catálogo enlaza la guía rápida A4 de repuestos (ayuda contextual)',
  pauseTemplate.includes('data-testid="link-quick-guide"') &&
  pauseTemplate.includes('/docs/guia_rapida_taller_repuestos_a4.html') &&
  pauseTemplate.includes('rel="noopener"'));

// =========================================================================
// PARTE 2: TechnicianResolutionPartsBlock.js
// =========================================================================
console.log('\n--- PARTE 2: Pruebas de TechnicianResolutionPartsBlock.js ---');

const resolutionBlock = createComponentInstance(TechnicianResolutionPartsBlock, {
  machineId: 1,
  incidentId: 105,
  machineModel: 'Sanden Vendo G-Drink'
});

await resolutionBlock.loadCatalog();
assert('2.1 loadCatalog carga 2 piezas compatibles', resolutionBlock.compatibleParts.length === 2);

// 2.1 Estado inicial no respondido (RF-REP-05)
assert('2.2 hasReplacedParts es null inicialmente (pregunta no respondida)', resolutionBlock.hasReplacedParts === null);
assert('2.3 isAnswered es false inicialmente', resolutionBlock.isAnswered === false);
assert('2.4 isValid es false inicialmente', resolutionBlock.isValid === false);

let valResult = resolutionBlock.validate();
assert('2.5 validate() rechaza antes de responder la pregunta obligatoria Sí/No',
  valResult.isValid === false && valResult.error.includes('Debe responder obligatoriamente'));

// 2.2 Respuesta negativa: No hubo sustitución
resolutionBlock.setReplacedDeclaration(false);
assert('2.6 setReplacedDeclaration(false) establece hasReplacedParts = false', resolutionBlock.hasReplacedParts === false);
assert('2.7 isAnswered pasa a true', resolutionBlock.isAnswered === true);
assert('2.8 isValid pasa a true al marcar NO', resolutionBlock.isValid === true);

valResult = resolutionBlock.validate();
assert('2.9 validate() aprueba respuesta negativa sin piezas requeridas',
  valResult.isValid === true && valResult.payload.replaced_parts_declared === false && valResult.payload.replaced_parts.length === 0);

// 2.3 Respuesta afirmativa: Sí hubo sustitución
resolutionBlock.setReplacedDeclaration(true);
assert('2.10 setReplacedDeclaration(true) establece hasReplacedParts = true', resolutionBlock.hasReplacedParts === true);
assert('2.11 isValid es false si no se ha añadido ninguna pieza tras decir Sí', resolutionBlock.isValid === false);

valResult = resolutionBlock.validate();
assert('2.12 validate() exige al menos una pieza si se declaró Sí',
  valResult.isValid === false && valResult.error.includes('debe registrar al menos una pieza instalada'));

// 2.4 Añadir pieza de catálogo con destino DESGUACE
resolutionBlock.selectedPartIdToAdd = '1';
resolutionBlock.quantityToAdd = 2;
resolutionBlock.destinationToAdd = 'DESGUACE';
resolutionBlock.notesToAdd = 'Pieza rota por sobrepresión';
resolutionBlock.addReplacedPart();

assert('2.13 addReplacedPart añade la pieza a la lista de sustituciones', resolutionBlock.replacedParts.length === 1);
const addedPart1 = resolutionBlock.replacedParts[0];
assert('2.14 Pieza añadida tiene código VALV-SOL-01 y cantidad 2',
  addedPart1.part_code === 'VALV-SOL-01' && addedPart1.quantity === 2);
assert('2.15 Destino asignado es DESGUACE (RF-REP-06)', addedPart1.old_part_destination === 'DESGUACE');
assert('2.16 totalReplacedUnits calcula 2 unidades', resolutionBlock.totalReplacedUnits === 2);
assert('2.17 estimatedTotalCost calcula 57.00 € (2 * 28.50)', Math.abs(resolutionBlock.estimatedTotalCost - 57.00) < 0.01);

// 2.5 Añadir segunda pieza con destino TALLER
resolutionBlock.selectedPartIdToAdd = '2';
resolutionBlock.quantityToAdd = 1;
resolutionBlock.destinationToAdd = 'TALLER';
resolutionBlock.addReplacedPart();

assert('2.18 Se añade segunda pieza a la resolución', resolutionBlock.replacedParts.length === 2);
const addedPart2 = resolutionBlock.replacedParts[1];
assert('2.19 Segunda pieza tiene destino TALLER (RF-REP-06)', addedPart2.old_part_destination === 'TALLER');
assert('2.20 totalReplacedUnits calcula 3 unidades en total', resolutionBlock.totalReplacedUnits === 3);

// 2.6 Añadir pieza fuera de catálogo
resolutionBlock.toggleAddOutOfCatalog(true);
assert('2.21 toggleAddOutOfCatalog activa modo fuera de catálogo', resolutionBlock.isAddingOutOfCatalog === true);

resolutionBlock.customPartNameToAdd = 'Fusible cerámico 10A 250V';
resolutionBlock.quantityToAdd = 1;
resolutionBlock.destinationToAdd = 'DESGUACE';
resolutionBlock.addReplacedPart();

assert('2.22 Se añade pieza fuera de catálogo con código OUT_OF_CATALOG',
  resolutionBlock.replacedParts.length === 3 &&
  resolutionBlock.replacedParts[2].is_out_of_catalog === true &&
  resolutionBlock.replacedParts[2].custom_part_name === 'Fusible cerámico 10A 250V');

// 2.7 Ajustes táctiles de cantidad y destino en la lista
resolutionBlock.stepItemQuantity(addedPart1, 1);
assert('2.23 stepItemQuantity incrementa cantidad de pieza 1 a 3', addedPart1.quantity === 3);

resolutionBlock.setItemDestination(addedPart1, 'TALLER');
assert('2.24 setItemDestination actualiza destino a TALLER', addedPart1.old_part_destination === 'TALLER');

// Eliminar pieza de la lista
resolutionBlock.removePart(2); // Eliminar la fuera de catálogo
assert('2.25 removePart reduce lista de piezas a 2', resolutionBlock.replacedParts.length === 2);

// 2.8 Validación final del payload
valResult = resolutionBlock.validate();
assert('2.26 validate() es válido con 2 piezas declaradas', valResult.isValid === true);
assert('2.27 Payload contiene replaced_parts_declared: true', valResult.payload.replaced_parts_declared === true);
assert('2.28 Payload contiene exactamente las 2 piezas con formato de contrato 5.2',
  valResult.payload.replaced_parts.length === 2 &&
  valResult.payload.replaced_parts[0].spare_part_id === 1 &&
  valResult.payload.replaced_parts[0].quantity === 3 &&
  valResult.payload.replaced_parts[0].old_part_destination === 'TALLER' &&
  valResult.payload.replaced_parts[1].spare_part_id === 2 &&
  valResult.payload.replaced_parts[1].old_part_destination === 'TALLER');

// 2.9 Directrices de diseño en template
const resolutionTemplate = TechnicianResolutionPartsBlock.template;
assert('2.29 Template incluye touch targets móviles >= 44px (min-height)',
  resolutionTemplate.includes('minHeight: \'48px\'') || resolutionTemplate.includes('min-height: 44px'));
assert('2.30 Template incluye selectores cerrados DESGUACE y TALLER',
  resolutionTemplate.includes('DESGUACE') && resolutionTemplate.includes('TALLER'));
assert('2.31 Template incluye data-testids requeridos',
  resolutionTemplate.includes('data-testid="btn-declared-yes"') &&
  resolutionTemplate.includes('data-testid="btn-declared-no"') &&
  resolutionTemplate.includes('data-testid="btn-destination-desguace"') &&
  resolutionTemplate.includes('data-testid="btn-destination-taller"'));

console.log('\n======================================================================');
console.log(` RESULTADOS TEST: ${assertions} aserciones ejecutadas.`);
console.log(` FALLOS: ${failures}`);
console.log('======================================================================');

if (failures > 0) {
  process.exit(1);
} else {
  console.log('✅ TODAS LAS PRUEBAS DE TechnicianSparePartsModalsTest.mjs HAN PASADO AL 100% EN VERDE.');
  process.exit(0);
}
