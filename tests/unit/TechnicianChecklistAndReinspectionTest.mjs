/**
 * VendGuard - Technician Checklist and Reinspection Test Suite (TechnicianChecklistAndReinspectionTest.mjs)
 * 
 * Valida la funcionalidad reactiva, ergonomía móvil y salvaguardas térmicas de:
 * 1. TechnicianChecklistModal.js (RF-PREV-03, RF-PREV-04, RNF-01, RNF-06, Art. II)
 * 2. TechnicianReinspectionModal.js (RF-PREV-04, RF-PREV-08, Art. II)
 * 3. Integración en TechnicianRouteView.js
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
import { TechnicianChecklistModal } from '../../public/assets/js/components/TechnicianChecklistModal.js';
import { TechnicianReinspectionModal } from '../../public/assets/js/components/TechnicianReinspectionModal.js';
import { TechnicianRouteView } from '../../public/assets/js/views/TechnicianRouteView.js';

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
console.log(' VendGuard: Frontend Test Suite - Checklist y Reinspección (T-PREV-21)');
console.log('======================================================================\n');

// Mock Fixtures
const mockChecklistResponse = {
  order_id: 10,
  order_code: 'PREV-2026-0010',
  machine: {
    id: 1,
    code: 'VEND-0101',
    model: 'Sanden Vendo G-Drink',
    machine_type: 'PERISHABLE_FOOD',
    is_refrigerated: true,
    is_perishable: true,
    temp_min: -5.0,
    temp_max: 25.0,
    temp_target_max: 4.0
  },
  checklist_items: [
    {
      item_code: 'CLEAN_EVAPORATOR',
      title: 'Limpieza y desinfección de evaporador',
      description: 'Limpieza de bandeja de condensados y cuba de frío',
      is_critical: true,
      requires_photo: false
    },
    {
      item_code: 'CHECK_SEALS',
      title: 'Comprobación de juntas y estanqueidad',
      description: 'Verificar gomas de estanqueidad de la puerta',
      is_critical: false,
      requires_photo: false
    }
  ]
};

let apiCompletePayload = null;
let apiReinspectPayload = null;

api.technician.getPreventiveChecklist = async (orderId) => ({ success: true, data: mockChecklistResponse });
api.technician.completePreventiveInspection = async (orderId, payload) => {
  apiCompletePayload = { orderId, payload };
  const temp = payload.temperature_measured;
  const isNoConforme = temp > 4.0 || payload.items.some(i => i.status === 'FAIL');

  if (isNoConforme) {
    return {
      success: true,
      data: {
        order_id: orderId,
        order_code: 'PREV-2026-0010',
        verdict: 'NO_CONFORME',
        is_quarantine_triggered: true,
        machine_sanitary_status: 'QUARANTINE',
        corrective_action: {
          mode: 'CREATED_NEW_INCIDENT',
          ticket_code: 'INC-2026-0099'
        }
      }
    };
  }

  return {
    success: true,
    data: {
      order_id: orderId,
      order_code: 'PREV-2026-0010',
      verdict: 'CONFORME',
      is_quarantine_triggered: false,
      machine_sanitary_status: 'OK',
      certificate: {
        certificate_code: 'CERT-2026-0044'
      }
    }
  };
};

api.technician.reinspectPreventiveOrder = async (orderId, payload) => {
  apiReinspectPayload = { orderId, payload };
  return {
    success: true,
    data: {
      order_id: orderId,
      reinspection_result: 'CONFORME',
      quarantine_lifted: true,
      machine_sanitary_status: 'OK',
      certificate: {
        certificate_code: 'CERT-2026-0045'
      }
    }
  };
};

// =========================================================================
// BLOQUE 1: TechnicianChecklistModal
// =========================================================================
console.log('--- BLOQUE 1: TechnicianChecklistModal ---');

const checklistModal = {
  ...TechnicianChecklistModal.data(),
  ...TechnicianChecklistModal.methods,
  order: { id: 10, order_code: 'PREV-2026-0010' },
  get isRefrigerated() {
    return TechnicianChecklistModal.computed.isRefrigerated.call(this);
  },
  get isPerishable() {
    return TechnicianChecklistModal.computed.isPerishable.call(this);
  },
  get numericTemperature() {
    return TechnicianChecklistModal.computed.numericTemperature.call(this);
  },
  get isThermalBroken() {
    return TechnicianChecklistModal.computed.isThermalBroken.call(this);
  },
  get allAnswered() {
    return TechnicianChecklistModal.computed.allAnswered.call(this);
  },
  get canSubmit() {
    return TechnicianChecklistModal.computed.canSubmit.call(this);
  },
  $emit(eventName, payload) {
    this._emitted = this._emitted || {};
    this._emitted[eventName] = payload || true;
  }
};

// 1.1 Carga de ítems
await checklistModal.loadChecklist(10);
assert('1.1 loadChecklist carga datos de máquina e ítems normativos', checklistModal.checklistData !== null);
assert('1.2 Máquina identificada como refrigerada', checklistModal.isRefrigerated === true);
assert('1.3 Máquina identificada como perecedera (Art. II)', checklistModal.isPerishable === true);
assert('1.4 Inicialmente canSubmit es false sin temperatura', checklistModal.canSubmit === false);

// 1.2 Validación de rango físico de temperatura [-5.0, 25.0] (EARS 3.1)
checklistModal.temperatureInput = '-6.5';
assert('1.5 Temperatura -6.5 °C rechazada por canSubmit (< -5.0 °C)', checklistModal.canSubmit === false);

checklistModal.temperatureInput = '28.0';
assert('1.6 Temperatura 28.0 °C rechazada por canSubmit (> 25.0 °C)', checklistModal.canSubmit === false);

// 1.3 Alerta de rotura térmica (> 4.0 °C en perecederos)
checklistModal.temperatureInput = '6.8';
assert('1.7 Temperatura 6.8 °C en perecederos activa isThermalBroken = true', checklistModal.isThermalBroken === true);

// 1.4 Aceleración operativa RNF-06 (< 90 segundos) con Marcar Todos Conformes
checklistModal.markAllPass();
assert('1.8 markAllPass establece estado PASS en todos los ítems',
  checklistModal.itemsState['CLEAN_EVAPORATOR'].status === 'PASS' &&
  checklistModal.itemsState['CHECK_SEALS'].status === 'PASS');
assert('1.9 allAnswered es true tras markAllPass', checklistModal.allAnswered === true);

// 1.5 Envío con rotura térmica (fuerza NO_CONFORME y cuarentena Art. II)
await checklistModal.submitChecklist();
assert('1.10 Envío con temperatura 6.8 °C invoca completePreventiveInspection', apiCompletePayload?.orderId === 10);
assert('1.11 completionResult refleja veredicto NO_CONFORME', checklistModal.completionResult?.verdict === 'NO_CONFORME');
assert('1.12 Cuarentena sanitaria activada en el resultado', checklistModal.completionResult?.is_quarantine_triggered === true);
assert('1.13 Incidencia correctiva vinculada consignada', checklistModal.completionResult?.corrective_action?.ticket_code === 'INC-2026-0099');
assert('1.14 Emite evento inspection-completed', checklistModal._emitted['inspection-completed']?.verdict === 'NO_CONFORME');

// 1.6 Envío conforme con temperatura óptima (3.5 °C <= 4.0 °C)
checklistModal.temperatureInput = '3.5';
checklistModal.markAllPass();
assert('1.15 Temperatura 3.5 °C en perecederos no activa rotura térmica', checklistModal.isThermalBroken === false);
await checklistModal.submitChecklist();
assert('1.16 Dictamen con 3.5 °C y todos PASS resulta CONFORME', checklistModal.completionResult?.verdict === 'CONFORME');
assert('1.17 Certificado sanitario oficial emitido', checklistModal.completionResult?.certificate?.certificate_code === 'CERT-2026-0044');

// 1.7 Exigencia de observaciones ante ítem FAIL o WARN
checklistModal.setItemStatus('CHECK_SEALS', 'FAIL');
assert('1.18 Ítem con status FAIL sin observaciones hace allAnswered = false', checklistModal.allAnswered === false);
checklistModal.itemsState['CHECK_SEALS'].observations = 'Junta de goma cuarteada en lateral derecho.';
assert('1.19 Con observaciones explicativas allAnswered vuelve a true', checklistModal.allAnswered === true);

// =========================================================================
// BLOQUE 2: TechnicianReinspectionModal
// =========================================================================
console.log('\n--- BLOQUE 2: TechnicianReinspectionModal ---');

const reinspectModal = {
  ...TechnicianReinspectionModal.data(),
  ...TechnicianReinspectionModal.methods,
  order: {
    id: 10,
    order_code: 'PREV-2026-0010',
    machine: { id: 1, code: 'VEND-0101', is_perishable: true }
  },
  get isPerishable() {
    return TechnicianReinspectionModal.computed.isPerishable.call(this);
  },
  get numericTemperature() {
    return TechnicianReinspectionModal.computed.numericTemperature.call(this);
  },
  get isThermalCompliant() {
    return TechnicianReinspectionModal.computed.isThermalCompliant.call(this);
  },
  get canSubmit() {
    return TechnicianReinspectionModal.computed.canSubmit.call(this);
  },
  $emit(eventName, payload) {
    this._emitted = this._emitted || {};
    this._emitted[eventName] = payload || true;
  }
};

// 2.1 Validación inicial
assert('2.1 Reinspección reconoce máquina perecedera', reinspectModal.isPerishable === true);
assert('2.2 canSubmit es false sin datos', reinspectModal.canSubmit === false);

// 2.2 Intento de levantar cuarentena con temperatura no conforme (> 4.0 °C)
reinspectModal.temperatureInput = '5.2';
reinspectModal.reinspectionNotes = 'Sustituido termostato y relé de compresor.';
assert('2.3 Temperatura 5.2 °C en perecedera hace isThermalCompliant = false', reinspectModal.isThermalCompliant === false);

await reinspectModal.submitReinspection();
assert('2.4 submitReinspection rechaza temperatura > 4.0 °C con error constitucional',
  reinspectModal.errorMessage.includes('4.0 °C en alimentos perecederos'));

// 2.3 Intento sin notas justificativas
reinspectModal.temperatureInput = '3.2';
reinspectModal.reinspectionNotes = '   ';
await reinspectModal.submitReinspection();
assert('2.5 submitReinspection exige obligatoriamente justificación técnica',
  reinspectModal.errorMessage.includes('comprobación técnica'));

// 2.4 Reinspección conforme (3.2 °C <= 4.0 °C con notas completas)
reinspectModal.temperatureInput = '3.2';
reinspectModal.reinspectionNotes = 'Comprobación de régimen térmico tras cambio de sonda. Temperatura estabilizada a 3.2 °C durante 30 minutos.';
assert('2.6 canSubmit y isThermalCompliant son true con 3.2 °C y notas',
  reinspectModal.canSubmit === true && reinspectModal.isThermalCompliant === true);

await reinspectModal.submitReinspection();
assert('2.7 submitReinspection exitoso invoca api.technician.reinspectPreventiveOrder', apiReinspectPayload?.orderId === 10);
assert('2.8 reinspectionResult confirma levantamiento de cuarentena y nuevo certificado',
  reinspectModal.reinspectionResult?.quarantine_lifted === true &&
  reinspectModal.reinspectionResult?.certificate?.certificate_code === 'CERT-2026-0045');
assert('2.9 Emite evento reinspection-completed', reinspectModal._emitted['reinspection-completed']?.quarantine_lifted === true);

// =========================================================================
// BLOQUE 3: Integración en TechnicianRouteView.js
// =========================================================================
console.log('\n--- BLOQUE 3: Integración en TechnicianRouteView.js ---');

assert('3.1 TechnicianRouteView incluye TechnicianChecklistModal en components',
  TechnicianRouteView.components.TechnicianChecklistModal !== undefined);

assert('3.2 TechnicianRouteView incluye TechnicianReinspectionModal en components',
  TechnicianRouteView.components.TechnicianReinspectionModal !== undefined);

assert('3.3 TechnicianRouteView template contiene los componentes modales',
  TechnicianRouteView.template.includes('TechnicianChecklistModal') &&
  TechnicianRouteView.template.includes('TechnicianReinspectionModal'));

console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: ¡Todas las pruebas pasaron con éxito (${assertions} aserciones)! (0 fallos)\n`);
} else {
  console.error(` RESULTADO: ${failures} prueba(s) fallaron de ${assertions} aserciones.\n`);
  process.exit(1);
}
console.log('======================================================================\n');
