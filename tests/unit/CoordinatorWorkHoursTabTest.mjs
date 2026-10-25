/**
 * VendGuard - Frontend Unit Test Suite (CoordinatorWorkHoursTabTest.mjs)
 *
 * Certifica la sub-pestaña «⏱️ Registro de Jornada y Horas» (T-TIME-11):
 * - Cliente HTTP: informe, URL del CSV, rectificación, bitácora, cierre forzado
 *   con hora efectiva y autoconsulta del técnico.
 * - Catálogo de auditoría con `ADJUST_TECHNICIAN_SHIFT`.
 * - Montaje en `CoordinatorMetricsView` bajo el filtro global.
 * - Componente: resumen, sesiones, insignias, marcas originales, modales y
 *   validaciones de motivo (>= 20 caracteres reales, Art. V.1).
 * - Fidelidad al sistema de diseño: cero colores hexadecimales literales.
 *
 * Dogma Vanilla: runner ES Module puro sin dependencias externas.
 */

import { readFileSync } from 'node:fs';
import { api } from '../../public/assets/js/api.js';
import { CoordinatorWorkHoursTab } from '../../public/assets/js/components/CoordinatorWorkHoursTab.js';
import { CoordinatorMetricsView } from '../../public/assets/js/views/CoordinatorMetricsView.js';
import { getAuditActionLabel } from '../../public/assets/js/utils/AuditActionLabels.js';

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - CoordinatorWorkHoursTab (T-TIME-11)');
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

function createVm() {
  const vm = {
    ...CoordinatorWorkHoursTab.data(),
    ...CoordinatorWorkHoursTab.methods
  };
  for (const [name, getter] of Object.entries(CoordinatorWorkHoursTab.computed)) {
    Object.defineProperty(vm, name, { get: getter });
  }
  return vm;
}

const componentSource = readFileSync(
  new URL('../../public/assets/js/components/CoordinatorWorkHoursTab.js', import.meta.url),
  'utf8'
);

// ─────────────────────────────────────────────────────────────────────────────
// 1. Cliente HTTP (api.js)
// ─────────────────────────────────────────────────────────────────────────────
const originalGet = api.get;
const originalPost = api.post;
const originalPatch = api.patch;

let lastGet = null;
let lastPost = null;
let lastPatch = null;

api.get = async (endpoint, options = {}) => {
  lastGet = { endpoint, options };
  return { data: {} };
};
api.post = async (endpoint, body = {}) => {
  lastPost = { endpoint, body };
  return { status: 'OK' };
};
api.patch = async (endpoint, body = {}) => {
  lastPatch = { endpoint, body };
  return { data: {} };
};

await api.metrics.getWorkHoursReport({ period: 'current_month', technician_id: 2 });
assert(
  'getWorkHoursReport() consulta /coordinator/metrics/work-hours con el filtro vigente',
  lastGet && lastGet.endpoint === '/coordinator/metrics/work-hours?period=current_month&technician_id=2',
  lastGet?.endpoint
);

await api.metrics.getWorkHoursReport({ period: 'custom', from: '2026-10-01', to: '2026-10-25' });
assert(
  'getWorkHoursReport() propaga el rango personalizado',
  lastGet && lastGet.endpoint.includes('from=2026-10-01') && lastGet.endpoint.includes('to=2026-10-25')
);

const csvUrl = api.metrics.getWorkHoursCsvUrl({ period: 'last_month' });
assert(
  'getWorkHoursCsvUrl() apunta al CSV oficial de jornada',
  typeof csvUrl === 'string' && csvUrl.endsWith('/coordinator/metrics/work-hours/export?period=last_month'),
  csvUrl
);
assert(
  'getWorkHoursCsvUrl() no filtra el token en la query',
  !/[?&]token=/.test(csvUrl),
  csvUrl
);

await api.coordinator.adjustShift(14, {
  started_at: '2026-10-24T08:00:00.000Z',
  closed_at: '2026-10-24T17:00:00.000Z',
  meal_break_seconds: 3600,
  reason: 'Motivo justificado de prueba con veinte caracteres'
});
assert(
  'adjustShift() envía PATCH a /coordinator/shifts/{id}/adjust',
  lastPatch && lastPatch.endpoint === '/coordinator/shifts/14/adjust',
  lastPatch?.endpoint
);
assert(
  'adjustShift() transporta las cuatro claves del contrato §5.3',
  lastPatch &&
    lastPatch.body.started_at === '2026-10-24T08:00:00.000Z' &&
    lastPatch.body.closed_at === '2026-10-24T17:00:00.000Z' &&
    lastPatch.body.meal_break_seconds === 3600 &&
    typeof lastPatch.body.reason === 'string'
);

await api.coordinator.getShiftLogs(14);
assert(
  'getShiftLogs() consulta la bitácora de la sesión',
  lastGet && lastGet.endpoint === '/coordinator/shifts/14/logs',
  lastGet?.endpoint
);

await api.technicianShift.forceClose(7, 'Olvido de cierre confirmado con el técnico');
assert(
  'forceClose() sin hora efectiva no envía effective_closed_at',
  lastPost &&
    lastPost.endpoint === '/coordinator/technicians/7/shifts/force-close' &&
    lastPost.body.reason === 'Olvido de cierre confirmado con el técnico' &&
    !('effective_closed_at' in lastPost.body),
  JSON.stringify(lastPost)
);

await api.technicianShift.forceClose(7, 'Olvido de cierre confirmado con el técnico', '2026-10-24T17:30:00.000Z');
assert(
  'forceClose() con hora efectiva la transporta en effective_closed_at',
  lastPost && lastPost.body.effective_closed_at === '2026-10-24T17:30:00.000Z',
  JSON.stringify(lastPost)
);

await api.technicianShift.getMyHours();
assert(
  'getMyHours() consulta el resumen propio sin parámetro de técnico (Art. V.4)',
  lastGet && lastGet.endpoint === '/technician/shift/my-hours',
  lastGet?.endpoint
);

api.get = originalGet;
api.post = originalPost;
api.patch = originalPatch;

// ─────────────────────────────────────────────────────────────────────────────
// 2. Catálogo de auditoría
// ─────────────────────────────────────────────────────────────────────────────
assert(
  'AuditActionLabels registra ADJUST_TECHNICIAN_SHIFT con etiqueta en castellano',
  getAuditActionLabel('ADJUST_TECHNICIAN_SHIFT') === 'Rectificación Horaria de Turno',
  getAuditActionLabel('ADJUST_TECHNICIAN_SHIFT')
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Integración en CoordinatorMetricsView
// ─────────────────────────────────────────────────────────────────────────────
assert(
  'CoordinatorMetricsView registra CoordinatorWorkHoursTab en components',
  Boolean(CoordinatorMetricsView.components && CoordinatorMetricsView.components.CoordinatorWorkHoursTab)
);
assert(
  'La sub-pestaña work-hours existe en el cuadro de mandos',
  CoordinatorMetricsView.template.includes('tab-work-hours') &&
    CoordinatorMetricsView.template.includes('⏱️ Registro de Jornada y Horas')
);
assert(
  'La pestaña recibe el periodo global y el rango personalizado (D5)',
  CoordinatorMetricsView.template.includes(':period="selectedPeriod"') &&
    CoordinatorMetricsView.template.includes(':from-date="customFrom"') &&
    CoordinatorMetricsView.template.includes(':to-date="customTo"')
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Componente: estado y cifras
// ─────────────────────────────────────────────────────────────────────────────
const vm = createVm();
assert('data() arranca con un informe vacío pero con forma de contrato', vm.report && vm.report.sessions.length === 0 && vm.report.by_technician.length === 0);
assert('data() no tiene modales abiertos al inicio', vm.adjustShiftTarget === null && vm.forceCloseTarget === null && vm.logsTarget === null);
assert('data() arranca sin filtro de técnico', vm.technicianId === '');

const originalGetWorkHours = api.metrics.getWorkHoursReport;
api.metrics.getWorkHoursReport = async () => ({
  data: {
    filter: { period: 'current_month', from_date: '2026-10-01', to_date: '2026-10-25', technician_id: null, timezone: 'Europe/Madrid' },
    summary: {
      total_sessions: 2,
      open_sessions_count: 1,
      anomalous_sessions_count: 1,
      adjusted_sessions_count: 1,
      gross_formatted: '16h 00m',
      meal_break_formatted: '01h 00m',
      effective_formatted: '15h 00m',
      intervention_formatted: '09h 00m',
      route_formatted: '06h 00m',
      resolved_interventions_count: 5
    },
    orphan_open_shifts: [],
    by_technician: [
      {
        technician_id: 2,
        technician_name: 'Carlos Mendoza',
        operator_code: 'TEC-001',
        is_active: true,
        sessions_count: 2,
        anomalous_sessions_count: 1,
        adjusted_sessions_count: 1,
        gross_formatted: '16h 00m',
        meal_break_formatted: '01h 00m',
        effective_formatted: '15h 00m',
        intervention_formatted: '09h 00m',
        route_formatted: '06h 00m',
        resolved_interventions_count: 5,
        has_out_of_shift_interventions: false
      }
    ],
    sessions: [
      {
        id: 14,
        technician_id: 2,
        technician_name: 'Carlos Mendoza',
        shift_date: '2026-10-25',
        status: 'ON_SHIFT',
        status_label: 'En curso',
        is_open: true,
        started_at: '2026-10-25T08:00:00+01:00',
        closed_at: null,
        gross_formatted: '04h 00m',
        meal_break_formatted: '00h 30m',
        effective_formatted: '03h 30m',
        meal_break_seconds: 1800,
        is_anomalous: false,
        is_force_closed: false,
        is_adjusted: false,
        original_started_at: '2026-10-25T08:00:00+01:00',
        original_closed_at: null,
        original_meal_break_seconds: null,
        close_reason: null,
        adjustment_reason: null
      }
    ]
  }
});

await vm.loadReport();
assert('loadReport() desenvuelve el sobre { data } del backend', vm.report.summary.total_sessions === 2, JSON.stringify(vm.summary));
assert('loadReport() conserva el resumen, la plantilla y las sesiones', vm.byTechnician.length === 1 && vm.sessions.length === 1);
assert('hasAnomalousSessions detecta el turno > 12h', vm.hasAnomalousSessions === true);
assert('periodRangeLabel declara el rango y la zona horaria del servidor', vm.periodRangeLabel.includes('01/10/2026') && vm.periodRangeLabel.includes('Europe/Madrid'), vm.periodRangeLabel);

// Filtro por técnico y rango personalizado incompleto.
let capturedParams = null;
api.metrics.getWorkHoursReport = async (params) => {
  capturedParams = params;
  return { data: { filter: {}, summary: {}, orphan_open_shifts: [], by_technician: [], sessions: [] } };
};
vm.technicianId = '2';
vm.period = 'current_month';
await vm.loadReport();
assert('loadReport() envía technician_id cuando hay filtro', capturedParams && capturedParams.technician_id === 2, JSON.stringify(capturedParams));

capturedParams = null;
vm.period = 'custom';
vm.fromDate = '';
vm.toDate = '';
await vm.loadReport();
assert('loadReport() no consulta un rango personalizado incompleto', capturedParams === null && vm.isLoading === false);

api.metrics.getWorkHoursReport = originalGetWorkHours;

// Formateo y marcas originales.
const now = Date.now();
const startedIso = new Date(now - 8 * 3600 * 1000).toISOString();
const closedIso = new Date(now - 1 * 3600 * 1000).toISOString();
assert('formatDateTime() pinta DD/MM/YYYY HH:MM', /^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/.test(vm.formatDateTime(closedIso)), vm.formatDateTime(closedIso));
assert('formatDate() respeta el día natural de imputación', vm.formatDate('2026-10-31') === '31/10/2026', vm.formatDate('2026-10-31'));
assert('formatDuration() usa el formato legible HHh MMm', vm.formatDuration(3661) === '01h 01m', vm.formatDuration(3661));
assert(
  'hasOriginalMarks() reconoce un turno ajustado',
  vm.hasOriginalMarks({ is_adjusted: true, original_meal_break_seconds: null, original_closed_at: null }) === true
);
assert(
  'hasOriginalMarks() no inventa marcas en un turno limpio',
  vm.hasOriginalMarks({ is_adjusted: false, original_meal_break_seconds: null, original_closed_at: null }) === false
);
assert(
  'toLocalInput() produce el formato de datetime-local',
  /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(vm.toLocalInput(closedIso)),
  vm.toLocalInput(closedIso)
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Rectificación auditada (RF-03, Art. V.1)
// ─────────────────────────────────────────────────────────────────────────────
const originalAdjust = api.coordinator.adjustShift;
let adjustArgs = null;
api.coordinator.adjustShift = async (shiftId, payload) => {
  adjustArgs = { shiftId, payload };
  return { data: { id: shiftId } };
};

vm.loadReport = async () => {};
vm.openAdjustShift({
  id: 14,
  technician_id: 2,
  technician_name: 'Carlos Mendoza',
  shift_date: '2026-10-24',
  is_open: false,
  started_at: startedIso,
  closed_at: closedIso,
  meal_break_seconds: 1800,
  original_started_at: startedIso,
  original_closed_at: null,
  original_meal_break_seconds: null,
  is_adjusted: false
});
assert('openAdjustShift() abre el turno cerrado con sus marcas vigentes', vm.adjustShiftTarget !== null && vm.adjustForm.reason === '');

vm.adjustForm = {
  started_at: vm.toLocalInput(startedIso),
  closed_at: vm.toLocalInput(closedIso),
  meal_break_minutes: 30,
  reason: 'motivo corto'
};
const shortReason = vm.validateAdjustForm();
assert('validateAdjustForm() exige 20 caracteres reales (Art. V.1)', shortReason.includes('al menos 20'), shortReason);

await vm.submitAdjustShift();
assert('submitAdjustShift() rechaza el motivo corto sin llamar a la API', adjustArgs === null && vm.adjustError.includes('20'));

vm.adjustForm.reason = '   El técnico olvidó fichar su salida y lo notificó a primera hora.   ';
const validReason = vm.validateAdjustForm();
assert('validateAdjustForm() acepta un motivo válido ignorando espacios (trim)', validReason === '', validReason);

await vm.submitAdjustShift();
assert('submitAdjustShift() llama PATCH con las marcas vigentes', adjustArgs && adjustArgs.shiftId === 14 && adjustArgs.payload.closed_at === new Date(vm.adjustForm.closed_at).toISOString());
assert('submitAdjustShift() convierte los minutos de pausa a segundos', adjustArgs && adjustArgs.payload.meal_break_seconds === 1800);
assert('submitAdjustShift() recorta el motivo antes de enviarlo', adjustArgs && adjustArgs.payload.reason === 'El técnico olvidó fichar su salida y lo notificó a primera hora.');
assert('submitAdjustShift() cierra el modal y anuncia el ajuste', vm.adjustShiftTarget === null && vm.successMessage.includes('rectificado'));

vm.adjustForm = {
  started_at: vm.toLocalInput(closedIso),
  closed_at: vm.toLocalInput(new Date(now + 3600 * 1000).toISOString()),
  meal_break_minutes: 0,
  reason: 'Motivo justificado con más de veinte caracteres reales'
};
assert('validateAdjustForm() rechaza marcas futuras', vm.validateAdjustForm().includes('futuro'), vm.validateAdjustForm());

vm.adjustForm.meal_break_minutes = 600;
vm.adjustForm.started_at = vm.toLocalInput(startedIso);
vm.adjustForm.closed_at = vm.toLocalInput(closedIso);
assert('validateAdjustForm() rechaza una pausa mayor que la duración bruta', vm.validateAdjustForm().includes('pausa'), vm.validateAdjustForm());

api.coordinator.adjustShift = originalAdjust;

// ─────────────────────────────────────────────────────────────────────────────
// 6. Cierre forzado con hora efectiva (RF-02, Art. II, Art. V.1)
// ─────────────────────────────────────────────────────────────────────────────
const originalForceClose = api.technicianShift.forceClose;
let forceArgs = null;
api.technicianShift.forceClose = async (technicianId, reason, effectiveClosedAt = null) => {
  forceArgs = { technicianId, reason, effectiveClosedAt };
  return { status: 'OFF_SHIFT' };
};

vm.openForceCloseShift({
  id: 14,
  technician_id: 2,
  technician_name: 'Carlos Mendoza',
  started_at: startedIso,
  closed_at: null,
  is_open: true
});
assert('openForceCloseShift() abre el modal con el turno objetivo', vm.forceCloseTarget !== null && vm.forceCloseTarget.id === 14);

vm.forceForm = { effective_closed_at: '', reason: 'corto' };
await vm.submitForceCloseShift();
assert('submitForceCloseShift() exige 10 caracteres en tiempo real', forceArgs === null && vm.forceError.includes('al menos 10'), vm.forceError);

const effectiveLocal = vm.toLocalInput(closedIso);
vm.forceForm = {
  effective_closed_at: effectiveLocal,
  reason: 'Motivo insuficiente'
};
assert('forceReasonMinimum sube a 20 con hora efectiva', vm.forceReasonMinimum === 20);
await vm.submitForceCloseShift();
assert('submitForceCloseShift() exige 20 caracteres si hay hora efectiva', forceArgs === null && vm.forceError.includes('al menos 20'), vm.forceError);
assert('forceClosePreview declara la duración bruta resultante', vm.forceClosePreview.includes('Duración bruta resultante'), vm.forceClosePreview);

vm.forceForm = {
  effective_closed_at: effectiveLocal,
  reason: 'El técnico olvidó cerrar su turno al terminar la ruta y lo confirmó por teléfono.'
};
await vm.submitForceCloseShift();
assert('submitForceCloseShift() envía el técnico, el motivo y la hora efectiva', forceArgs && forceArgs.technicianId === 2 && forceArgs.effectiveClosedAt === new Date(effectiveLocal).toISOString(), JSON.stringify(forceArgs));
assert('submitForceCloseShift() cierra el modal y anuncia el cierre', vm.forceCloseTarget === null && vm.successMessage.includes('Art. II'));

vm.openForceCloseShift({ id: 15, technician_id: 3, technician_name: 'Otro', started_at: startedIso, is_open: true });
vm.forceForm = { effective_closed_at: '', reason: 'Olvido de cierre confirmado con el técnico' };
await vm.submitForceCloseShift();
assert('submitForceCloseShift() sin hora efectiva envía null', forceArgs && forceArgs.effectiveClosedAt === null);

api.technicianShift.forceClose = originalForceClose;

// ─────────────────────────────────────────────────────────────────────────────
// 7. Bitácora y traducción de rechazos
// ─────────────────────────────────────────────────────────────────────────────
const originalLogs = api.coordinator.getShiftLogs;
api.coordinator.getShiftLogs = async () => ({
  data: {
    logs: [
      {
        id: 1,
        event_type: 'START_SHIFT',
        event_type_label: 'Inicio de turno',
        actor_user_id: 2,
        effective_at: startedIso,
        created_at: startedIso,
        previous_state: null,
        new_state: { status: 'ON_SHIFT', started_at: startedIso },
        reason: null
      },
      {
        id: 2,
        event_type: 'ADJUST_SHIFT',
        event_type_label: 'Rectificación horaria por Coordinación',
        actor_user_id: 1,
        effective_at: closedIso,
        created_at: closedIso,
        previous_state: { status: 'OFF_SHIFT' },
        new_state: { status: 'OFF_SHIFT', closed_at: closedIso, is_force_closed: true },
        reason: 'El técnico olvidó fichar su salida y lo notificó a primera hora.'
      }
    ]
  }
});

await vm.openLogs({ id: 14, technician_name: 'Carlos Mendoza' });
assert('openLogs() desenvuelve la bitácora del contrato §5.4', vm.logs.length === 2 && vm.logs[1].event_type === 'ADJUST_SHIFT');
assert('logAuthor() identifica al usuario ejecutor', vm.logAuthor(vm.logs[1]) === 'Usuario #1');
assert(
  'describeLogState() resume la instantánea resultante',
  vm.describeLogState(vm.logs[1].new_state).includes('Estado: OFF_SHIFT') &&
    vm.describeLogState(vm.logs[1].new_state).includes('Cierre forzado'),
  vm.describeLogState(vm.logs[1].new_state)
);
vm.closeLogs();
assert('closeLogs() vacía la selección', vm.logsTarget === null && vm.logs.length === 0);

api.coordinator.getShiftLogs = originalLogs;

assert(
  'describeShiftFailure() traduce el código de motivo insuficiente',
  vm.describeShiftFailure({ code: 'INVALID_ADJUSTMENT_REASON' }).includes('20 caracteres reales')
);
assert(
  'describeShiftFailure() traduce el solapamiento de turnos',
  vm.describeShiftFailure({ code: 'SHIFT_OVERLAP_CONFLICT' }).includes('solapa')
);
assert(
  'describeShiftFailure() conserva el mensaje del servidor si el código es desconocido',
  vm.describeShiftFailure({ code: 'OTRO', message: 'Fallo concreto' }) === 'Fallo concreto'
);

// ─────────────────────────────────────────────────────────────────────────────
// 8. Sistema de diseño (RNF-05, docs/design.md)
// ─────────────────────────────────────────────────────────────────────────────
assert(
  'CoordinatorWorkHoursTab no contiene colores hexadecimales literales',
  !/#[0-9a-fA-F]{3,8}\b/.test(componentSource),
  (componentSource.match(/#[0-9a-fA-F]{3,8}\b/g) || []).slice(0, 5).join(', ')
);
assert(
  'Usa la tipografía dual display/body',
  componentSource.includes('var(--font-display)') && componentSource.includes('var(--font-body)')
);
assert(
  'Usa la paleta y los bordes conservadores del sistema',
  componentSource.includes('var(--color-primary)') &&
    componentSource.includes('var(--color-slate)') &&
    componentSource.includes('var(--color-hairline)') &&
    componentSource.includes('var(--radius-card)') &&
    componentSource.includes('var(--radius-interactive)')
);
assert(
  'Expone los ganchos de prueba de la tabla y los tres modales',
  componentSource.includes('data-testid="work-hours-sessions-table"') &&
    componentSource.includes('data-testid="modal-adjust-shift"') &&
    componentSource.includes('data-testid="modal-force-close-shift"') &&
    componentSource.includes('data-testid="modal-shift-logs"')
);

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
console.log('======================================================================');

if (failures > 0) {
  process.exit(1);
}
