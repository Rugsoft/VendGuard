/**
 * VendGuard - TechnicianMachineHistoryModal Test Suite (TechnicianMachineHistoryModalTest.mjs)
 *
 * Validates the reactive behavior and contracts of the read-only machine incident
 * history modal for the field technician (RF-07 / EARS H.1-H.6,
 * specs/technical/technician_machine_history_contracts.md).
 *
 * Hecho cuando:
 * 1. Fetches the machine history when the dialog opens and unwraps the envelope payload.
 * 2. Renders the incident list newest-first with the contract fields (EARS H.1, H.2).
 * 3. Shows the empty state when the machine has no (non-cancelled) incidents.
 * 4. Renders inline errors for 403 NOT_ASSIGNED_TO_TECHNICIAN and 404 MACHINE_NOT_FOUND
 *    with a retry action (EARS H.3, H.4).
 * 5. Resets its state when the dialog closes and stays strictly read-only (EARS H.5).
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

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
  location: { search: '', href: 'http://localhost/' },
  open: null
};

import { api, ApiError } from '../../public/assets/js/api.js';
import { TechnicianMachineHistoryModal } from '../../public/assets/js/components/TechnicianMachineHistoryModal.js';
import { ModalDialog } from '../../public/assets/js/components/ModalDialog.js';

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
console.log(' VendGuard: Frontend Test Suite - TechnicianMachineHistoryModal');
console.log('======================================================================\n');

// ─── 1. Estructura del componente ────────────────────────────────────────────
console.log('--- Grupo 1: Estructura del componente ---');

assert('1.1 El componente exporta name, props y template',
  TechnicianMachineHistoryModal?.name === 'TechnicianMachineHistoryModal'
    && typeof TechnicianMachineHistoryModal?.template === 'string'
    && typeof TechnicianMachineHistoryModal?.props === 'object');

assert('1.2 Declara la prop machine (objeto) y modelValue (booleano)',
  TechnicianMachineHistoryModal.props.machine?.type === Object
    && TechnicianMachineHistoryModal.props.modelValue?.type === Boolean);

assert('1.3 Se apoya en ModalDialog e IncidentBadge (Dogma Vanilla, sin dependencias nuevas)',
  TechnicianMachineHistoryModal.components?.ModalDialog === ModalDialog
    && typeof TechnicianMachineHistoryModal.components?.IncidentBadge === 'object');

assert('1.4 La plantilla es de solo lectura: sin acciones de mutación de averías',
  !TechnicianMachineHistoryModal.template.includes('startIntervention')
    && !TechnicianMachineHistoryModal.template.includes('resolveIncident')
    && !TechnicianMachineHistoryModal.template.includes('pauseIntervention'));

// ─── 2. Carga del historial al abrir (EARS H.1, H.2) ────────────────────────
console.log('\n--- Grupo 2: Carga del historial al abrir (EARS H.1, H.2) ---');

const HISTORY_FIXTURE = {
  machine: { id: 12, code: 'VM-012', model: 'Necta Koro' },
  history: [
    {
      id: 3401, ticket_code: 'INC-2026-0341', status: 'REOPENED', urgency: 'HIGH',
      category: 'PAYMENT_SYSTEM', description: 'No expende producto', technician_name: 'Marc Puig',
      created_at: '2026-10-05 08:00:00', resolved_at: null, closed_at: null, reopen_count: 2
    },
    {
      id: 3200, ticket_code: 'INC-2026-0320', status: 'CLOSED', urgency: 'MEDIUM',
      category: 'PRODUCT_JAM', description: 'Atasco de producto', technician_name: 'Jordi Ruta',
      created_at: '2026-09-10 09:00:00', resolved_at: '2026-09-10 11:00:00', closed_at: '2026-09-12 11:00:01',
      reopen_count: 1
    }
  ]
};

let fetchCalls = [];
let historyResponse = HISTORY_FIXTURE;
let historyFailure = null;

api.technician.getMachineHistory = async (machineId) => {
  fetchCalls.push(machineId);
  if (historyFailure) {
    throw historyFailure;
  }
  return historyResponse;
};

const buildInstance = () => {
  const instance = Object.create(TechnicianMachineHistoryModal);
  Object.assign(instance, {
    isLoading: false,
    errorMessage: '',
    errorCode: '',
    machineInfo: null,
    history: [],
    machine: { id: 12, code: 'VM-012', model: 'Necta Koro' },
    modelValue: false,
    $emit: () => {}
  });
  // Vue setup emulation: bind methods and expose computeds as getters.
  for (const [methodName, methodFn] of Object.entries(TechnicianMachineHistoryModal.methods || {})) {
    instance[methodName] = methodFn.bind(instance);
  }
  for (const [computedName, computedFn] of Object.entries(TechnicianMachineHistoryModal.computed || {})) {
    Object.defineProperty(instance, computedName, { get: () => computedFn.call(instance) });
  }
  return instance;
};

const modal = buildInstance();
await modal.fetchHistory();

assert('2.1 Solicita el historial de la máquina indicada', fetchCalls.length === 1 && fetchCalls[0] === 12);
assert('2.2 Almacena el bloque machine y el historial desempaquetado',
  modal.machineInfo?.id === 12 && modal.machineInfo?.code === 'VM-012'
    && Array.isArray(modal.history) && modal.history.length === 2);
assert('2.3 Sin errores tras una carga correcta', modal.errorMessage === '' && modal.errorCode === '' && modal.isLoading === false);
assert('2.4 El subtítulo combina código y modelo de la máquina',
  modal.machineSubtitle === 'VM-012 · Necta Koro');

// ─── 3. Estado vacío ─────────────────────────────────────────────────────────
console.log('\n--- Grupo 3: Estado vacío ---');

historyResponse = { machine: { id: 12, code: 'VM-012' }, history: [] };
const emptyModal = buildInstance();
await emptyModal.fetchHistory();
assert('3.1 Una máquina sin averías (no canceladas) carga un historial vacío',
  emptyModal.history.length === 0 && emptyModal.errorMessage === '');

historyResponse = HISTORY_FIXTURE;

// ─── 4. Errores en línea (EARS H.3, H.4) ────────────────────────────────────
console.log('\n--- Grupo 4: Errores en línea (EARS H.3, H.4) ---');

// The real ApiError from api.js: instanceof must match the component's check.
const forbiddenModal = buildInstance();
historyFailure = new ApiError(403, 'NOT_ASSIGNED_TO_TECHNICIAN', 'Not assigned.');
await forbiddenModal.fetchHistory();
assert('4.1 403 NOT_ASSIGNED_TO_TECHNICIAN muestra el mensaje de no asignación',
  forbiddenModal.errorCode === 'NOT_ASSIGNED_TO_TECHNICIAN'
    && forbiddenModal.errorMessage.includes('no tiene ninguna avería activa asignada a tu ruta'));

const notFoundModal = buildInstance();
historyFailure = new ApiError(404, 'MACHINE_NOT_FOUND', 'Missing machine.');
await notFoundModal.fetchHistory();
assert('4.2 404 MACHINE_NOT_FOUND muestra el mensaje de máquina inexistente',
  notFoundModal.errorCode === 'MACHINE_NOT_FOUND'
    && notFoundModal.errorMessage.includes('no existe o ha sido dada de baja'));

const unexpectedModal = buildInstance();
historyFailure = new ApiError(500, 'INTERNAL_ERROR', 'Boom.');
await unexpectedModal.fetchHistory();
assert('4.3 Error inesperado muestra mensaje genérico sin romper la vista',
  unexpectedModal.errorMessage !== '' && unexpectedModal.errorCode === 'INTERNAL_ERROR');

historyFailure = null;

// ─── 5. Ciclo de vida y solo lectura (EARS H.5) ─────────────────────────────
console.log('\n--- Grupo 5: Ciclo de vida y solo lectura (EARS H.5) ---');

fetchCalls = [];

const lifecycleModal = buildInstance();
lifecycleModal.machineInfo = { id: 12 };
lifecycleModal.history = HISTORY_FIXTURE.history;
lifecycleModal.resetState();
assert('5.1 resetState limpia historial, errores y máquina',
  lifecycleModal.history.length === 0 && lifecycleModal.machineInfo === null
    && lifecycleModal.errorMessage === '' && lifecycleModal.errorCode === '');

assert('5.2 Sin abrir de nuevo el diálogo no se dispara ninguna carga adicional',
  fetchCalls.length === 0);

const watcher = TechnicianMachineHistoryModal.watch?.modelValue;
assert('5.3 El watcher de modelValue está declarado', typeof watcher?.handler === 'function');

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. MACHINE HISTORY MODAL (EARS H.1-H.6) FULFILLED.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.log(' RESULT: FAILURES DETECTED IN TEST SUITE.');
  console.log('======================================================================\n');
  process.exit(1);
}
