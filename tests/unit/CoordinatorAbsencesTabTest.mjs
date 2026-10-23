/**
 * VendGuard - Frontend Unit Test Suite (CoordinatorAbsencesTabTest.mjs)
 * 
 * Validates CoordinatorAbsencesTab and dashboard integration (T-ASH-10, RF-01, RF-02):
 * - Tab component structure and reactive data properties.
 * - Filters for absences list (by technician and status).
 * - Modal and form for new absence creation.
 * - Assisted reassignment modal for active incidents.
 * - Logical cancellation modal with mandatory reason.
 * 
 * Dogma Vanilla: Pure ES Module test runner without external dependencies.
 */

// Mock storage
const storageMock = new Map();
globalThis.localStorage = {
  getItem: (k) => storageMock.get(k) ?? null,
  setItem: (k, v) => storageMock.set(k, String(v)),
  removeItem: (k) => storageMock.delete(k),
  clear: () => storageMock.clear()
};
globalThis.sessionStorage = { ...globalThis.localStorage };

import { api } from '../../public/assets/js/api.js';
import { CoordinatorAbsencesTab } from '../../public/assets/js/components/CoordinatorAbsencesTab.js';
import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - CoordinatorAbsencesTab (T-ASH-10)');
console.log('======================================================================\n');

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.log(`  [FAIL] ${description}`);
    if (details) console.log(`         ${details}`);
    failures++;
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Integración en CoordinatorDashboardView
// ─────────────────────────────────────────────────────────────────────────────
assert(
  'CoordinatorDashboardView registra CoordinatorAbsencesTab en components',
  Boolean(CoordinatorDashboardView.components && CoordinatorDashboardView.components.CoordinatorAbsencesTab)
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Componente CoordinatorAbsencesTab
// ─────────────────────────────────────────────────────────────────────────────
const vm = {
  ...CoordinatorAbsencesTab.data(),
  ...CoordinatorAbsencesTab.methods,
  $emit: () => {},
};

assert('data() incluye absences como array', Array.isArray(vm.absences));
assert('data() incluye filterStatus con valor por defecto all', vm.filterStatus === 'all');
assert('data() incluye showCreateModal en false', vm.showCreateModal === false);
assert('data() incluye showCancelModal en false', vm.showCancelModal === false);
assert('data() incluye showAssistedModal en false', vm.showAssistedModal === false);

assert('data() incluye createForm con reason VACATION', vm.createForm && vm.createForm.reason === 'VACATION');
assert('data() incluye createForm con is_partial_day false por defecto', vm.createForm && vm.createForm.is_partial_day === false);
assert('data() incluye createForm con start_time y end_time', vm.createForm && vm.createForm.start_time === '09:00' && vm.createForm.end_time === '14:00');
assert('data() incluye cancelForm con cancellation_reason', vm.cancelForm && typeof vm.cancelForm.cancellation_reason === 'string');

// ─────────────────────────────────────────────────────────────────────────────
// 3. Métodos del componente y formateo de fechas
// ─────────────────────────────────────────────────────────────────────────────
assert('methods incluye loadAbsences', typeof CoordinatorAbsencesTab.methods.loadAbsences === 'function');
assert('methods incluye openCreateModal', typeof CoordinatorAbsencesTab.methods.openCreateModal === 'function');
assert('methods incluye submitCreateAbsence', typeof CoordinatorAbsencesTab.methods.submitCreateAbsence === 'function');
assert('methods incluye openCancelModal', typeof CoordinatorAbsencesTab.methods.openCancelModal === 'function');
assert('methods incluye submitCancelAbsence', typeof CoordinatorAbsencesTab.methods.submitCancelAbsence === 'function');

assert(
  'formatDate() formatea jornada completa sin hora si termina en 00:00:00 o 23:59:59',
  !vm.formatDate('2026-11-20T00:00:00+00:00').includes(':')
);
assert(
  'formatDate() formatea franja horaria incluyendo hora para permisos parciales',
  vm.formatDate('2026-11-20T09:30:00+00:00').includes(':')
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Invocación de submitCreateAbsence con franja horaria (sin TDZ ni ReferenceError)
// ─────────────────────────────────────────────────────────────────────────────
let postCalledWith = null;
const originalPost = api.post;
const originalGet = api.get;

api.post = async (url, data) => {
  postCalledWith = { url, data };
  return { data: { absence: { id: 99 }, affected_incidents: [] } };
};
api.get = async () => [];

vm.createForm = {
  technician_id: '5',
  reason: 'OTHER',
  starts_at: '2026-10-26',
  ends_at: '2026-10-26',
  notes: 'Permiso médico de prueba',
  is_partial_day: true,
  start_time: '09:00',
  end_time: '14:00'
};

await vm.submitCreateAbsence();

assert('submitCreateAbsence() no lanza TDZ ReferenceError en payload', vm.createError === '');
assert('submitCreateAbsence() envía starts_at con hora combinada', postCalledWith && postCalledWith.data.starts_at === '2026-10-26 09:00:00');
assert('submitCreateAbsence() envía ends_at con hora combinada', postCalledWith && postCalledWith.data.ends_at === '2026-10-26 14:00:00');
assert('submitCreateAbsence() asigna successMessage', vm.successMessage.length > 0);

api.post = originalPost;
api.get = originalGet;

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
console.log('======================================================================');

if (failures > 0) {
  process.exit(1);
}
