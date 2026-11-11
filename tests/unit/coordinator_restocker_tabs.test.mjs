/**
 * VendGuard - Frontend Unit Test Suite (coordinator_restocker_tabs.test.mjs)
 *
 * Certifica la integración del rol `RESTOCKER` en las consolas de Coordinación
 * (T-RES-13 · D2, D3, C1, RF-01.1, RF-01.2, RF-02.4, RF-03.2, RF-05.1, RF-05.2):
 * - Personal Interno: alta de reponedor con `operator_code`, rol inmutable y
 *   guardia nunca marcada (Art. II).
 * - Zonas: bloque «📦 Cuadrante de Carga», cobertura en vivo, traslado de
 *   titularidad y retirada lógica.
 * - Ausencias: filtro por rol, directorio con técnicos y reponedores y alta por
 *   el contrato unificado.
 * - Registro de Horas: filtro por rol, insignia de rol y parámetro `role` en
 *   informe y exportación.
 * - Diseño: el bloque nuevo no introduce colores literales (RNF-03).
 *
 * Dogma Vanilla: runner ES Module puro sin dependencias externas.
 */

const storageMock = new Map();
globalThis.localStorage = {
  getItem: (k) => storageMock.get(k) ?? null,
  setItem: (k, v) => storageMock.set(k, String(v)),
  removeItem: (k) => storageMock.delete(k),
  clear: () => storageMock.clear()
};
globalThis.sessionStorage = { ...globalThis.localStorage };

import { readFileSync } from 'node:fs';
import { api } from '../../public/assets/js/api.js';
import { AdminUsersTab, USER_ROLES } from '../../public/assets/js/components/AdminUsersTab.js';
import { OperationalZoneManagementTab } from '../../public/assets/js/components/OperationalZoneManagementTab.js';
import { CoordinatorAbsencesTab } from '../../public/assets/js/components/CoordinatorAbsencesTab.js';
import { CoordinatorWorkHoursTab } from '../../public/assets/js/components/CoordinatorWorkHoursTab.js';

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - Coordinator Restocker Tabs (T-RES-13)');
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

function buildVm(component, overrides = {}) {
  const vm = { ...component.data(), ...component.methods, ...overrides };
  for (const [name, getter] of Object.entries(component.computed || {})) {
    Object.defineProperty(vm, name, { get: getter, configurable: true });
  }
  return vm;
}

const readComponent = (name) =>
  readFileSync(new URL(`../../public/assets/js/components/${name}.js`, import.meta.url), 'utf8');

const adminSource = readComponent('AdminUsersTab');
const zonesSource = readComponent('OperationalZoneManagementTab');
const absencesSource = readComponent('CoordinatorAbsencesTab');
const workHoursSource = readComponent('CoordinatorWorkHoursTab');

const originalGet = api.get;
const originalPost = api.post;
const originalDelete = api.delete;
const originalGetUsers = api.admin?.getUsers;
const originalCreateUser = api.admin?.createUser;
const originalReport = api.metrics.getWorkHoursReport;

// ─────────────────────────────────────────────────────────────────────────────
// 1. Personal Interno: alta de reponedor (RF-01.1, RF-01.2, C1, Art. II)
// ─────────────────────────────────────────────────────────────────────────────
assert(
  'El catálogo de roles incluye Cargador / Reponedor de Ruta',
  USER_ROLES.some((role) => role.value === 'RESTOCKER' && /Reponedor/.test(role.label))
);

let createdPayload = null;
api.admin = {
  ...(api.admin || {}),
  createUser: async (payload) => {
    createdPayload = payload;
    return { id: 99 };
  },
  getUsers: originalGetUsers
};

const adminVm = buildVm(AdminUsersTab, {
  createForm: {
    name: 'Lucía Gómez',
    email: 'lucia.rep@vendguard.internal',
    role: 'RESTOCKER',
    operator_code: 'rep-09',
    phone: '',
    telegram_chat_id: '',
    password: 'Password123!'
  },
  loadUsers: async () => {}
});
await adminVm.submitCreate();
assert('El alta de reponedor envía el rol RESTOCKER', createdPayload?.role === 'RESTOCKER');
assert('El alta envía el código de operador normalizado', createdPayload?.operator_code === 'REP-09');
assert('El alta fuerza is_on_call = FALSE (Art. II)', createdPayload?.is_on_call === false);

createdPayload = null;
adminVm.createForm.operator_code = 'sin-formato';
await adminVm.submitCreate();
assert('Sin código válido no se envía el alta del reponedor', createdPayload === null);
assert('El error se explica en el campo del código', /REP-01/.test(adminVm.createErrors.operator_code || ''));

adminVm.openEditModal({
  id: 99, name: 'Lucía Gómez', email: 'lucia.rep@vendguard.internal',
  role: 'RESTOCKER', operator_code: 'REP-09', phone: null, telegram_chat_id: null
});
assert('La edición conserva el rol persistido', adminVm.editForm.role === 'RESTOCKER');
assert('La edición publica el código de operador inmutable', adminVm.editForm.operator_code === 'REP-09');
assert('El rol se pinta bloqueado en la edición (C1)', adminSource.includes('data-testid="staff-role-locked"'));
assert('El formulario de alta tiene el campo de código de operador', adminSource.includes('data-testid="staff-operator-code"'));
assert('El filtro de plantilla ofrece Reponedores', adminSource.includes('value="RESTOCKER"') && adminSource.includes('📦 Reponedores de Ruta'));

// ─────────────────────────────────────────────────────────────────────────────
// 2. Zonas: cuadrante de carga, cobertura y titularidad (RF-02.4, RF-03.2)
// ─────────────────────────────────────────────────────────────────────────────
let postedRestocker = null;
let deletedRestocker = null;
api.post = async (url, body) => {
  postedRestocker = { url, body };
  return { data: { assignment: { id: 1 } } };
};
api.delete = async (url) => {
  deletedRestocker = url;
  return { data: { removed: true } };
};

const zoneVm = buildVm(OperationalZoneManagementTab, {
  quadrantZone: { id: 7, name: 'Zona Norte', code: 'ZONA-NORTE' },
  restockerForm: { restocker_id: '12', is_primary: true },
  loadZones: async () => {},
  loadZoneRestockers: async () => {},
  loadRestockerCoverage: async () => {}
});
await zoneVm.submitRestockerAssignment();
assert(
  'El alta de titular de carga usa el endpoint del cuadrante',
  postedRestocker?.url === '/coordinator/zones/7/restockers'
    && postedRestocker?.body?.restocker_id === 12
    && postedRestocker?.body?.is_primary === true
);
assert('El alta de titular no confirma traslado sin conflicto', !('confirm_primary_transfer' in (postedRestocker?.body || {})));
assert('El cuadrante de carga anuncia la vinculación', /titular/.test(zoneVm.restockerNotice));

postedRestocker = null;
api.post = async () => {
  throw { code: 'PRIMARY_ZONE_ALREADY_ASSIGNED', message: 'Ya es titular de otra zona.' };
};
zoneVm.restockerForm = { restocker_id: '12', is_primary: true };
await zoneVm.submitRestockerAssignment();
assert('El conflicto de titularidad pide confirmación explícita', zoneVm.restockerErrors.requiresConfirmation === true);
assert('El conflicto se explica sin romper el formulario', /titular de otra zona/.test(zoneVm.restockerErrors.primary || ''));

api.post = async (url, body) => {
  postedRestocker = { url, body };
  return { data: { assignment: { id: 1 } } };
};
await zoneVm.confirmRestockerPrimaryTransfer();
assert('La confirmación envía confirm_primary_transfer', postedRestocker?.body?.confirm_primary_transfer === true);

await zoneVm.removeRestockerFromQuadrant(12);
assert('La retirada usa DELETE lógico del cuadrante', deletedRestocker === '/coordinator/zones/7/restockers/12');
assert('La retirada habla de histórico conservado', /histórico se conserva/.test(zoneVm.restockerNotice));

assert('El modal publica el bloque de reponedores', zonesSource.includes('data-testid="zone-restockers-block"'));
assert('El bloque pinta la cobertura en vivo', zonesSource.includes('data-testid="zone-restockers-coverage"'));
assert('El bloque permite confirmar el traslado de titularidad', zonesSource.includes('data-testid="zone-restockers-confirm"'));
assert('La tarjeta de zona publica los contadores de carga', zonesSource.includes('primary_restockers_count') && zonesSource.includes('data-testid="zone-restockers-count"'));
assert('El modal carga directorio, cuadrante y cobertura', /loadRestockerDirectory[\s\S]{0,300}loadZoneRestockers[\s\S]{0,300}loadRestockerCoverage/.test(zonesSource));

// ─────────────────────────────────────────────────────────────────────────────
// 3. Ausencias: filtro por rol y alta unificada (RF-05.1)
// ─────────────────────────────────────────────────────────────────────────────
const absenceCalls = [];
api.get = async (url, options) => {
  absenceCalls.push({ url, params: options?.params || {} });
  if (url === '/coordinator/absences') {
    return { data: [{ id: 1, technician_name: 'Lucía Gómez', technician_role: 'RESTOCKER' }] };
  }
  if (url === '/coordinator/users' && options?.params?.role === 'RESTOCKER') {
    return [{ id: 12, name: 'Lucía Gómez', role: 'RESTOCKER', is_active: true }];
  }
  return [{ id: 3, name: 'Jordi Ruta', role: 'TECHNICIAN', is_active: true }];
};

const absenceVm = buildVm(CoordinatorAbsencesTab, { filterRole: 'RESTOCKER' });
await absenceVm.loadAbsences();
const listCall = absenceCalls.find((call) => call.url === '/coordinator/absences');
assert('El listado de ausencias usa el contrato unificado', Boolean(listCall));
assert('El listado envía el filtro role=RESTOCKER', listCall?.params?.role === 'RESTOCKER');
assert('El listado conserva las ausencias del reponedor', absenceVm.absences.length === 1);

absenceCalls.length = 0;
await absenceVm.loadTechnicians();
assert(
  'El directorio de ausencias carga técnicos y reponedores',
  absenceCalls.length === 2
    && absenceCalls.some((call) => call.params.role === 'TECHNICIAN')
    && absenceCalls.some((call) => call.params.role === 'RESTOCKER')
    && absenceVm.technicians.length === 2
);
assert('roleLabel() distingue Reponedor y Técnico SAT', absenceVm.roleLabel('RESTOCKER') === 'Reponedor' && absenceVm.roleLabel('TECHNICIAN') === 'Técnico SAT');

let createdAbsence = null;
api.post = async (url, body) => {
  createdAbsence = { url, body };
  return { data: { affected_incidents: [] } };
};
absenceVm.createForm = {
  technician_id: '12', reason: 'VACATION', starts_at: '2026-12-01', ends_at: '2026-12-05',
  notes: 'Vacaciones del reponedor.', is_partial_day: false, start_time: '09:00', end_time: '14:00'
};
absenceVm.loadAbsences = async () => {};
await absenceVm.submitCreateAbsence();
assert('El alta de ausencia usa POST /coordinator/absences', createdAbsence?.url === '/coordinator/absences');
assert('El alta incluye technician_id del reponedor', createdAbsence?.body?.technician_id === 12);
assert('La tabla de ausencias publica el rol', absencesSource.includes('a.technician_role_label || roleLabel(a.technician_role)'));
assert('El filtro por rol está en la interfaz', absencesSource.includes('data-testid="filter-absence-role"'));

// ─────────────────────────────────────────────────────────────────────────────
// 4. Registro de Horas: filtro y columna de rol (RF-05.2, QA 4)
// ─────────────────────────────────────────────────────────────────────────────
const hoursVm = buildVm(CoordinatorWorkHoursTab, { period: 'last_30_days', filterRole: 'RESTOCKER' });
const params = hoursVm.buildParams();
assert('El informe envía role=RESTOCKER', params.role === 'RESTOCKER' && params.period === 'last_30_days');

let reportParams = null;
api.metrics.getWorkHoursReport = async (query) => {
  reportParams = query;
  return {
    data: {
      filter: { role: 'RESTOCKER' },
      summary: {},
      by_technician: [{ technician_id: 12, technician_name: 'Lucía Gómez', technician_role: 'RESTOCKER', intervention_formatted: '00h 00m' }],
      sessions: [{ id: 1, technician_id: 12, technician_name: 'Lucía Gómez', technician_role: 'RESTOCKER' }]
    }
  };
};
await hoursVm.loadReport();
assert('El informe solicita el filtro por rol', reportParams?.role === 'RESTOCKER');
assert('El resumen conserva el rol del reponedor', hoursVm.byTechnician[0].technician_role === 'RESTOCKER');
assert('roleLabel() del registro distingue Reponedor', hoursVm.roleLabel('RESTOCKER') === 'Reponedor');
assert('La interfaz ofrece el selector de rol', workHoursSource.includes('data-testid="work-hours-role-filter"'));
assert('La tabla resumen publica la insignia de rol', workHoursSource.includes('data-testid="work-hours-role-badge"'));
assert('El desglose de sesiones publica el rol', workHoursSource.includes('data-testid="work-hours-session-role"'));

const exportParams = hoursVm.buildParams();
assert('La exportación CSV hereda el filtro por rol', exportParams.role === 'RESTOCKER');

// ─────────────────────────────────────────────────────────────────────────────
// 5. Diseño: bloque nuevo con tokens, cero hex literales (RNF-03)
// ─────────────────────────────────────────────────────────────────────────────
const restockerBlock = zonesSource.slice(zonesSource.indexOf('data-testid="zone-restockers-block"'));
assert(
  'El bloque de carga no contiene colores hexadecimales literales',
  !/#[0-9a-fA-F]{3,8}\b/.test(restockerBlock),
  (restockerBlock.match(/#[0-9a-fA-F]{3,8}\b/g) || []).slice(0, 5).join(', ')
);
assert(
  'El bloque de carga usa radios y tokens del sistema',
  restockerBlock.includes('var(--radius-interactive)') && restockerBlock.includes('var(--color-hairline)')
);

api.get = originalGet;
api.post = originalPost;
api.delete = originalDelete;
api.metrics.getWorkHoursReport = originalReport;
if (originalGetUsers) api.admin.getUsers = originalGetUsers;
if (originalCreateUser) api.admin.createUser = originalCreateUser;

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
console.log('======================================================================');

if (failures > 0) {
  process.exit(1);
}
