/**
 * VendGuard - Frontend Unit Test Suite (TechnicianMyHoursModalTest.mjs)
 *
 * Certifica el contador diario y la autoconsulta «⏱️ Mis Horas» del técnico
 * (T-TIME-12 · RF-07.1 a RF-07.3, RF-08.1, RNF-04, RNF-05, D4, QA 3 y QA 4):
 * - Contador del día alimentado por `today_summary` en cada transición de turno.
 * - Modal de solo lectura con los totales del mes y las sesiones propias.
 * - Insignias diferenciadas, marcas originales y motivos de Coordinación.
 * - Aislamiento horizontal: la consulta no envía ningún identificador de técnico.
 * - Fidelidad al sistema de diseño en el bloque nuevo (cero colores literales).
 *
 * Dogma Vanilla: runner ES Module puro sin dependencias externas.
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

import { readFileSync } from 'node:fs';
import { api } from '../../public/assets/js/api.js';
import { TechnicianRouteView } from '../../public/assets/js/views/TechnicianRouteView.js';

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - TechnicianMyHoursModal (T-TIME-12)');
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

function buildVm(overrides = {}) {
  const vm = {
    ...TechnicianRouteView.data(),
    ...TechnicianRouteView.methods,
    ...overrides
  };
  for (const [name, getter] of Object.entries(TechnicianRouteView.computed)) {
    Object.defineProperty(vm, name, { get: getter });
  }
  return vm;
}

const viewSource = readFileSync(
  new URL('../../public/assets/js/views/TechnicianRouteView.js', import.meta.url),
  'utf8'
);

const todaySummary = {
  date: '2026-10-25',
  sessions_count: 1,
  gross_seconds: 14400,
  gross_formatted: '04h 00m',
  meal_break_seconds: 1800,
  meal_break_formatted: '00h 30m',
  effective_seconds: 12600,
  effective_formatted: '03h 30m'
};

const monthlySession = {
  id: 31,
  technician_id: 4,
  technician_name: 'Técnico Autenticado',
  shift_date: '2026-10-24',
  status: 'OFF_SHIFT',
  status_label: 'Cerrado',
  is_open: false,
  started_at: '2026-10-24T08:00:00+02:00',
  closed_at: '2026-10-24T17:00:00+02:00',
  gross_seconds: 32400,
  gross_formatted: '09h 00m',
  meal_break_seconds: 3600,
  meal_break_formatted: '01h 00m',
  effective_seconds: 28800,
  effective_formatted: '08h 00m',
  is_anomalous: false,
  is_force_closed: true,
  is_adjusted: true,
  original_started_at: '2026-10-24T08:05:00+02:00',
  original_closed_at: '2026-10-24T17:30:00+02:00',
  original_meal_break_seconds: 1800,
  close_reason: 'Olvido de cierre confirmado por teléfono con el técnico.',
  adjustment_reason: 'El técnico olvidó fichar su salida y lo notificó a primera hora.'
};

// ─────────────────────────────────────────────────────────────────────────────
// 1. Estado inicial
// ─────────────────────────────────────────────────────────────────────────────
const vm = buildVm();
assert('data() incluye todaySummary nulo al arrancar', vm.todaySummary === null);
assert('data() arranca con el modal «Mis Horas» cerrado', vm.showMyHoursModal === false);
assert('data() arranca sin error de autoconsulta', vm.myHoursError === '');
assert(
  'data() inicializa myHours con la forma del contrato §5.6',
  vm.myHours && vm.myHours.month === '' && Array.isArray(vm.myHours.sessions) && typeof vm.myHours.summary === 'object'
);
assert('computed expone myHoursSummary, myHoursSessions y myHoursIsEmpty', typeof vm.myHoursSummary === 'object' && vm.myHoursIsEmpty === true);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Contador diario desde today_summary (RF-07.1)
// ─────────────────────────────────────────────────────────────────────────────
vm.applyShiftResponse({ status: 'ON_SHIFT', today_summary: todaySummary }, 'OFF_SHIFT');
assert('applyShiftResponse() actualiza el estado del turno', vm.shiftStatus === 'ON_SHIFT');
assert('applyShiftResponse() guarda el acumulado del día', vm.todaySummary.effective_formatted === '03h 30m');
assert('El contador refleja también la pausa de comida', vm.todaySummary.meal_break_formatted === '00h 30m');

vm.applyShiftResponse({ data: { status: 'MEAL_BREAK', today_summary: todaySummary } }, 'ON_SHIFT');
assert('applyShiftResponse() desenvuelve el sobre { data } de la API', vm.shiftStatus === 'MEAL_BREAK');

const originalGet = api.get;
let statusUrl = null;
api.get = async (url) => {
  statusUrl = url;
  return { status: 'OFF_SHIFT', today_summary: { ...todaySummary, effective_formatted: '00h 00m' } };
};
const statusVm = buildVm();
await statusVm.loadShiftStatus();
assert('loadShiftStatus() consulta el estado propio del turno', statusUrl === '/technician/shift/status');
assert('loadShiftStatus() refresca el contador del día', statusVm.todaySummary.effective_formatted === '00h 00m');
api.get = originalGet;

const originalPost = api.post;
let startedUrl = null;
api.post = async (url) => {
  startedUrl = url;
  return { status: 'ON_SHIFT', today_summary: todaySummary };
};
const startVm = buildVm();
await startVm.startShift();
assert('startShift() sigue iniciando el turno en su endpoint', startedUrl === '/technician/shift/start');
assert('startShift() deja el contador del día al día', startVm.todaySummary.effective_formatted === '03h 30m');
api.post = originalPost;

// ─────────────────────────────────────────────────────────────────────────────
// 3. Autoconsulta de solo lectura (RF-07.2, RF-08.1)
// ─────────────────────────────────────────────────────────────────────────────
const originalMyHours = api.technicianShift.getMyHours;
let myHoursArgs = null;
api.technicianShift.getMyHours = async (...args) => {
  myHoursArgs = args;
  return {
    data: {
      month: '2026-10',
      from_date: '2026-10-01',
      to_date: '2026-10-31',
      summary: {
        total_sessions: 3,
        gross_formatted: '24h 00m',
        meal_break_formatted: '02h 00m',
        effective_formatted: '22h 00m'
      },
      sessions: [monthlySession]
    }
  };
};

const hoursVm = buildVm();
await hoursVm.openMyHours();
assert('openMyHours() abre el panel', hoursVm.showMyHoursModal === true);
assert('getMyHours() se invoca sin ningún identificador de técnico (Art. V.4)', Array.isArray(myHoursArgs) && myHoursArgs.length === 0);
assert('loadMyHours() desenvuelve el resumen del mes', hoursVm.myHours.month === '2026-10' && hoursVm.myHoursSummary.total_sessions === 3);
assert('loadMyHours() conserva las sesiones propias', hoursVm.myHoursSessions.length === 1 && hoursVm.myHoursSessions[0].id === 31);
assert('myHoursMonthLabel() pinta el mes en castellano', hoursVm.myHoursMonthLabel === 'Octubre de 2026', hoursVm.myHoursMonthLabel);
assert('myHoursIsEmpty es false cuando hay sesiones', hoursVm.myHoursIsEmpty === false);

hoursVm.closeMyHours();
assert('closeMyHours() cierra el panel sin borrar los datos', hoursVm.showMyHoursModal === false && hoursVm.myHoursSessions.length === 1);

api.technicianShift.getMyHours = async () => {
  throw { status: 500, message: 'No se pudieron calcular tus horas.' };
};
const errorVm = buildVm();
await errorVm.openMyHours();
assert('Un fallo de la autoconsulta se declara en su propio estado', errorVm.myHoursError.includes('No se pudieron'));
assert('El fallo no deja el panel colgado en carga', errorVm.myHoursLoading === false);
api.technicianShift.getMyHours = originalMyHours;

// ─────────────────────────────────────────────────────────────────────────────
// 4. Transparencia de ajustes y marcas originales (RF-07.3, QA 3 y QA 4)
// ─────────────────────────────────────────────────────────────────────────────
assert('hasShiftOriginalMarks() reconoce el turno ajustado', hoursVm.hasShiftOriginalMarks(monthlySession) === true);
assert(
  'hasShiftOriginalMarks() no inventa marcas en un turno limpio',
  hoursVm.hasShiftOriginalMarks({ is_adjusted: false, original_meal_break_seconds: null, original_closed_at: null }) === false
);
assert('formatShiftDuration() usa HHh MMm', hoursVm.formatShiftDuration(1800) === '00h 30m', hoursVm.formatShiftDuration(1800));
assert('formatShiftDate() respeta el día natural', hoursVm.formatShiftDate('2026-10-31') === '31/10/2026', hoursVm.formatShiftDate('2026-10-31'));

// ─────────────────────────────────────────────────────────────────────────────
// 5. Interfaz y sistema de diseño (RNF-04, RNF-05)
// ─────────────────────────────────────────────────────────────────────────────
assert('El contador del día vive en la barra de turno', viewSource.includes('data-testid="shift-today-summary"'));
assert(
  'El contador usa today_summary.effective_formatted y meal_break_formatted',
  viewSource.includes('todaySummary.effective_formatted') && viewSource.includes('todaySummary.meal_break_formatted')
);
assert(
  'El botón «⏱️ Mis Horas» existe con su gancho de prueba',
  viewSource.includes('data-testid="btn-open-my-hours"') && viewSource.includes('⏱️ Mis Horas')
);
assert(
  'El modal es de solo lectura: no contiene acciones de ajuste ni cierre',
  viewSource.includes('data-testid="modal-my-hours"') &&
    !/modal-my-hours[\s\S]{0,9000}(adjustShift|forceClose|adjustForm)/.test(viewSource)
);
assert(
  'El modal declara ambas insignias y las marcas originales',
  viewSource.includes('Cierre forzado') &&
    viewSource.includes('Ajustado por Coordinación') &&
    viewSource.includes('Marcas originales del primer fichaje')
);

const modalRegion = viewSource.slice(viewSource.indexOf('data-testid="modal-my-hours"'));
assert(
  'El bloque nuevo no contiene colores hexadecimales literales',
  !/#[0-9a-fA-F]{3,8}\b/.test(modalRegion),
  (modalRegion.match(/#[0-9a-fA-F]{3,8}\b/g) || []).slice(0, 5).join(', ')
);
assert(
  'El bloque nuevo usa la tipografía dual y la paleta de tokens',
  modalRegion.includes('var(--font-display)') &&
    modalRegion.includes('var(--font-body)') &&
    modalRegion.includes('var(--color-slate)') &&
    modalRegion.includes('var(--color-ink-muted)')
);
assert(
  'Los totales usan tarjetas de 8px con borde hairline',
  modalRegion.includes('var(--radius-card)') && modalRegion.includes('1px solid var(--color-hairline)')
);
assert(
  'El acceso «Mis Horas» usa un borde interactivo de 4px',
  viewSource.includes('var(--radius-interactive)')
);

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
console.log('======================================================================');

if (failures > 0) {
  process.exit(1);
}
