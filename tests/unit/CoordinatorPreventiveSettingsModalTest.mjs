/**
 * VendGuard - CoordinatorPreventiveSettingsModal Test Suite (CoordinatorPreventiveSettingsModalTest.mjs)
 * 
 * Valida la funcionalidad reactiva y salvaguardas constitucionales de:
 * CoordinatorPreventiveSettingsModal.js (RF-PREV-01, Constitución Art. II, EARS 1.3, 1.4, 1.5).
 * 
 * Hecho cuando:
 * 1. Carga el catálogo de frecuencias por tipología y máquinas disponibles.
 * 2. Art. II (Seguridad Alimentaria): Bloquea en frontend cualquier intento de configurar > 15 días en perecederos.
 * 3. Permite actualizar frecuencias de tipologías no perecederas llamando a la API.
 * 4. EARS 1.5 (Pausa Estacional): Exige motivo justificado obligatorio al activar pausa estacional.
 * 5. Permite guardar pausa estacional justificada con fecha de reanudación.
 * 6. EARS 1.4 / Art. II: Aplica la salvaguarda de 15 días en la frecuencia individual de máquinas perecederas.
 * 7. Emite eventos settings-updated y pause-updated.
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
import { CoordinatorPreventiveSettingsModal } from '../../public/assets/js/components/CoordinatorPreventiveSettingsModal.js';

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
console.log(' VendGuard: Frontend Test Suite - CoordinatorPreventiveSettingsModal (T-PREV-19)');
console.log('======================================================================\n');

// Mock Fixtures
const mockSettings = [
  { id: 1, machine_type: 'PERISHABLE_FOOD', default_frequency_days: 15, max_allowed_days: 15, advance_warning_days: 5 },
  { id: 2, machine_type: 'HOT_DRINKS', default_frequency_days: 30, max_allowed_days: 60, advance_warning_days: 5 },
  { id: 3, machine_type: 'COLD_DRINKS', default_frequency_days: 45, max_allowed_days: 90, advance_warning_days: 5 },
  { id: 4, machine_type: 'SNACKS', default_frequency_days: 60, max_allowed_days: 90, advance_warning_days: 5 },
  { id: 5, machine_type: 'COMBO', default_frequency_days: 15, max_allowed_days: 45, advance_warning_days: 5 }
];

const mockMachines = [
  { id: 1, code: 'VEND-0101', model: 'Sanden Vendo G-Drink', machine_type: 'PERISHABLE_FOOD', location_name: 'Hospital del Mar' },
  { id: 2, code: 'VEND-0102', model: 'Necta Krea', machine_type: 'HOT_DRINKS', location_name: 'Hospital del Mar' }
];

let apiSettingsUpdatedPayload = null;
let apiMachineConfigUpdatedPayload = null;

api.coordinator.getPreventiveSettings = async () => ({ success: true, data: mockSettings });
api.coordinator.getMachines = async () => mockMachines;
api.coordinator.updatePreventiveSettings = async (payload) => {
  apiSettingsUpdatedPayload = payload;
  return { success: true, data: payload };
};
api.coordinator.getMachinePreventiveConfig = async (machineId) => {
  if (Number(machineId) === 1) {
    return {
      success: true,
      data: {
        machine_id: 1,
        machine_code: 'VEND-0101',
        machine_type: 'PERISHABLE_FOOD',
        is_perishable: true,
        sanitary_status: 'OK',
        is_seasonal_pause: false,
        seasonal_pause_reason: null,
        seasonal_pause_until: null,
        sanitary_frequency_days: null,
        default_frequency_days: 15,
        effective_frequency_days: 15
      }
    };
  }
  return {
    success: true,
    data: {
      machine_id: 2,
      machine_code: 'VEND-0102',
      machine_type: 'HOT_DRINKS',
      is_perishable: false,
      sanitary_status: 'OK',
      is_seasonal_pause: true,
      seasonal_pause_reason: 'Cierre de sede en agosto',
      seasonal_pause_until: '2026-09-01',
      sanitary_frequency_days: 20,
      default_frequency_days: 30,
      effective_frequency_days: 20
    }
  };
};
api.coordinator.updateMachinePreventiveConfig = async (machineId, payload) => {
  apiMachineConfigUpdatedPayload = { machineId, payload };
  return { success: true, data: payload };
};

const modal = {
  ...CoordinatorPreventiveSettingsModal.data(),
  ...CoordinatorPreventiveSettingsModal.methods,
  $emit(eventName, payload) {
    this._emitted = this._emitted || {};
    this._emitted[eventName] = payload || true;
  }
};

// =========================================================================
// BLOQUE 1: Carga inicial de datos
// =========================================================================
console.log('--- BLOQUE 1: Carga de configuraciones y máquinas ---');

await modal.loadSettings();
assert('1.1 loadSettings carga 5 tipologías normativas', modal.settingsList.length === 5);

await modal.loadMachines();
assert('1.2 loadMachines carga catálogo de máquinas', modal.machinesList.length === 2);

// =========================================================================
// BLOQUE 2: Blindaje Constitucional Art. II en Frecuencias por Tipología
// =========================================================================
console.log('\n--- BLOQUE 2: Blindaje Art. II en Frecuencias por Tipología ---');

// 2.1 Seleccionar PERISHABLE_FOOD para edición
const perishableSetting = modal.settingsList.find(s => s.machine_type === 'PERISHABLE_FOOD');
modal.selectTypeForEdit(perishableSetting);
assert('2.1 selectTypeForEdit prepara formulario para PERISHABLE_FOOD', modal.typeForm.machine_type === 'PERISHABLE_FOOD');

// 2.2 Intentar configurar > 15 días en perecederos (Art. II / EARS 1.3)
modal.typeForm.default_frequency_days = 20;
modal.typeForm.max_allowed_days = 20;
await modal.saveTypeSettings();
assert('2.2 Guardar 20 días en perecederos es bloqueado con error Art. II',
  modal.typeError.includes('Artículo II de la Constitución (Seguridad Alimentaria)'));
assert('2.3 No se ejecutó llamada de API al violar Art. II', apiSettingsUpdatedPayload === null);

// 2.3 Configurar valor válido para perecederos (ej. 10 días en recintos hospitalarios)
modal.typeForm.default_frequency_days = 10;
modal.typeForm.max_allowed_days = 15;
await modal.saveTypeSettings();
assert('2.4 Guardar 10 días (<= 15) es permitido', modal.typeError === '');
assert('2.5 Invocada api.coordinator.updatePreventiveSettings con valores válidos',
  apiSettingsUpdatedPayload?.machine_type === 'PERISHABLE_FOOD' && apiSettingsUpdatedPayload?.default_frequency_days === 10);
assert('2.6 Emite settings-updated tras actualización exitosa', modal._emitted['settings-updated'] === true);

// 2.4 Actualizar tipología no perecedera (HOT_DRINKS a 25 días)
const hotDrinksSetting = modal.settingsList.find(s => s.machine_type === 'HOT_DRINKS');
modal.selectTypeForEdit(hotDrinksSetting);
modal.typeForm.default_frequency_days = 25;
modal.typeForm.max_allowed_days = 45;
await modal.saveTypeSettings();
assert('2.7 Actualización de HOT_DRINKS a 25 días permitida',
  apiSettingsUpdatedPayload?.machine_type === 'HOT_DRINKS' && apiSettingsUpdatedPayload?.default_frequency_days === 25);

// =========================================================================
// BLOQUE 3: Pausas Estacionales por Máquina (EARS 1.5)
// =========================================================================
console.log('\n--- BLOQUE 3: Gestión de Pausas Estacionales por Máquina ---');

modal.activeTab = 'machine';
modal.selectedMachineId = 1; // VEND-0101 (PERISHABLE_FOOD)
await modal.loadMachineConfig(1);

assert('3.1 loadMachineConfig carga datos de VEND-0101', modal.machineSettings?.machine_code === 'VEND-0101');
assert('3.2 VEND-0101 inicialmente no está en pausa estacional', modal.machineForm.is_seasonal_pause === false);

// 3.3 Intentar activar pausa estacional sin consignar motivo (EARS 1.5)
modal.machineForm.is_seasonal_pause = true;
modal.machineForm.seasonal_pause_reason = '   ';
await modal.saveMachineConfig();
assert('3.3 Activar pausa sin motivo es rechazado obligando a justificar documentalmente (EARS 1.5)',
  modal.machineError.includes('justificar documentalmente el motivo'));

// 3.4 Activar pausa estacional con motivo válido y fecha
modal.machineForm.seasonal_pause_reason = 'Cierre de la facultad por periodo vacacional de verano.';
modal.machineForm.seasonal_pause_until = '2026-09-15';
await modal.saveMachineConfig();
assert('3.4 Activar pausa estacional con motivo justificado es exitoso', modal.machineError === '');
assert('3.5 Payload enviado incluye is_seasonal_pause: true y motivo',
  apiMachineConfigUpdatedPayload?.payload?.is_seasonal_pause === true &&
  apiMachineConfigUpdatedPayload?.payload?.seasonal_pause_reason.includes('vacacional'));
assert('3.6 Emite pause-updated', modal._emitted['pause-updated'] === true);

// =========================================================================
// BLOQUE 4: Frecuencia Sanitaria Individual por Máquina (Art. II / EARS 1.4)
// =========================================================================
console.log('\n--- BLOQUE 4: Frecuencia Sanitaria Individual y Salvaguardas ---');

// 4.1 Intentar asignar frecuencia > 15 días a máquina perecedera VEND-0101
modal.machineForm.sanitary_frequency_days = '25';
await modal.saveMachineConfig();
assert('4.1 Intentar asignar 25 días a máquina perecedera es bloqueado por Art. II',
  modal.machineError.includes('Artículo II de la Constitución (Seguridad Alimentaria)'));

// 4.2 Asignar frecuencia estricta válida a máquina perecedera (7 días)
modal.machineForm.sanitary_frequency_days = '7';
await modal.saveMachineConfig();
assert('4.2 Asignar 7 días a máquina perecedera es aceptado', modal.machineError === '');
assert('4.3 sanitary_frequency_days = 7 enviado en payload',
  apiMachineConfigUpdatedPayload?.payload?.sanitary_frequency_days === 7);

// 4.3 Desactivar pausa estacional (Reanudación de servicio)
modal.machineForm.is_seasonal_pause = false;
modal.machineForm.seasonal_pause_reason = '';
await modal.saveMachineConfig();
assert('4.4 Desactivar pausa estacional envía is_seasonal_pause: false',
  apiMachineConfigUpdatedPayload?.payload?.is_seasonal_pause === false);

console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: ¡Todas las pruebas pasaron con éxito (${assertions} aserciones)! (0 fallos)\n`);
} else {
  console.error(` RESULTADO: ${failures} prueba(s) fallaron de ${assertions} aserciones.\n`);
  process.exit(1);
}
console.log('======================================================================\n');
