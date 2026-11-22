/**
 * VendGuard - Frontend Test Suite (coordinator_restock_route_planner.test.mjs)
 *
 * Certifica la quinta sub-vista de Coordinación del Módulo 37 (T-RRP-11):
 *   - Tablero «Ruta del Día» con KPIs de un único `service_date`.
 *   - Bandeja «⚠️ Paradas sin cobertura hoy» y rutas por reponedor.
 *   - Omisiones, desvíos y revocaciones con el contrato del backend.
 *   - CRUD/versionado individual y alta masiva de agendas.
 *   - Modo `TODAY_ROUTE` en la propuesta y el lote de carga M33.
 *   - Cliente API con las ocho rutas del planificador.
 *   - Diseño responsive 360 × 800, tokens CSS y cero hexadecimales.
 *
 * Dogma Vanilla: Node.js ESM nativo, sin dependencias externas.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const storage = new Map();
globalThis.localStorage = {
  getItem: (key) => storage.get(key) ?? null,
  setItem: (key, value) => storage.set(key, String(value)),
  removeItem: (key) => storage.delete(key),
  clear: () => storage.clear()
};
globalThis.window = { location: { search: '', href: 'http://localhost/' } };

import { api } from '../../public/assets/js/api.js';
import { CoordinatorRestockRoutePlannerSubTab } from '../../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js';
import { CoordinatorProductLoadingTab } from '../../public/assets/js/components/CoordinatorProductLoadingTab.js';

const todayIso = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Madrid' }).format(new Date());
const tomorrowIso = () => {
  const noonUtc = new Date(`${todayIso()}T12:00:00Z`);
  noonUtc.setUTCDate(noonUtc.getUTCDate() + 1);
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'UTC' }).format(noonUtc);
};

const TODAY = todayIso();
const TOMORROW = tomorrowIso();

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) console.error(`         Motivo: ${details}`);
    failures++;
  }
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - CoordinatorRestockRoutePlannerSubTab');
console.log('======================================================================\n');

const calls = [];

const realPlannerApi = {
  getRestockSchedules: api.coordinator.getRestockSchedules,
  createRestockSchedule: api.coordinator.createRestockSchedule,
  updateRestockSchedule: api.coordinator.updateRestockSchedule,
  bulkRestockSchedules: api.coordinator.bulkRestockSchedules,
  deactivateRestockSchedule: api.coordinator.deactivateRestockSchedule,
  getRestockRouteDay: api.coordinator.getRestockRouteDay,
  createRestockRouteOverride: api.coordinator.createRestockRouteOverride,
  revokeRestockRouteOverride: api.coordinator.revokeRestockRouteOverride
};

const dayFixture = {
  service_date: TODAY,
  is_future_projection: false,
  kpis: {
    scheduled_machines_count: 5,
    injected_fefo_count: 1,
    injected_demand_count: 2,
    completed_count: 1,
    skipped_count: 1,
    blocked_count: 1,
    uncovered_count: 1
  },
  uncovered_stops: [
    {
      machine_id: 9,
      machine_code: 'VEND-09',
      location_id: 3,
      location_name: 'Sede Sur · Oficinas',
      zone_id: null,
      zone_name: null,
      sla_tier: 'STANDARD',
      coverage_status: 'UNCOVERED',
      priority_block: 'FEFO_SANITARY',
      triggers: ['FEFO_ALERT'],
      earliest_fefo_expiration_date: TODAY
    }
  ],
  restocker_routes: [
    {
      restocker_id: 7,
      restocker_name: 'Marta Reponedora',
      operator_code: 'OP-07',
      shift_status: 'ON_SHIFT',
      summary: {
        pending_stops_count: 1,
        pending_machines_count: 1,
        fefo_alerts_count: 1,
        completed_machines_count: 1,
        skipped_machines_count: 1,
        blocked_machines_count: 1,
        total_distance_km: 4.2,
        unmapped_stops_count: 0
      },
      stops: [
        {
          location_id: 1,
          location_name: 'Sede Central · Hall',
          address: 'Calle Mayor 1',
          priority_block: 'FEFO_SANITARY',
          priority_label: 'Bloque 1 · Riesgo sanitario FEFO',
          distance_km: 1.2,
          navigation_url: 'https://maps.google.com/?q=41.4,2.1',
          machines: [
            {
              machine_id: 1,
              machine_code: 'VEND-01',
              machine_type: 'PERISHABLE_FOOD',
              status: 'PENDING',
              triggers: ['FEFO_ALERT', 'SCHEDULED'],
              has_fefo_alert: true,
              has_expired_cold_batch: true,
              empty_slots_count: 0,
              schedule_id: 10,
              earliest_fefo_expiration_date: TODAY
            }
          ]
        }
      ],
      completed_stops: [
        { machine_id: 2, machine_code: 'VEND-02', location_name: 'Sede Central · Hall', status: 'COMPLETED' }
      ],
      skipped_stops: [
        { machine_id: 3, machine_code: 'VEND-03', location_name: 'Sede Norte', skip_reason_code: 'LOCATION_CLOSED', skip_reason_label: 'Local cerrado', had_active_fefo_alert: false }
      ],
      blocked_stops: [
        { machine_id: 4, machine_code: 'VEND-04', location_name: 'Sede Este', block_reason: 'SANITARY_QUARANTINE', block_reason_label: 'Cuarentena' }
      ]
    }
  ],
  active_overrides: [
    {
      id: 55,
      machine_id: 3,
      service_date: TODAY,
      override_type: 'SKIPPED',
      override_type_label: 'Omitida',
      status: 'ACTIVE',
      status_label: 'Vigente',
      skip_reason_code: 'LOCATION_CLOSED',
      skip_reason_label: 'Local cerrado',
      reassignment_reason_code: null,
      reason_text: 'La conserjería permanece cerrada con persiana metálica bajada.',
      created_by: 1,
      created_at: `${TODAY} 08:00:00+00`
    }
  ],
  blocked_stops: [
    { machine_id: 4, machine_code: 'VEND-04', location_name: 'Sede Este', block_reason: 'SANITARY_QUARANTINE', block_reason_label: 'Cuarentena' }
  ],
  completed_stops: [
    { machine_id: 2, machine_code: 'VEND-02', location_name: 'Sede Central · Hall', status: 'COMPLETED' }
  ],
  skipped_stops: [
    { machine_id: 3, machine_code: 'VEND-03', location_name: 'Sede Norte', skip_reason_code: 'LOCATION_CLOSED', skip_reason_label: 'Local cerrado' }
  ]
};

const scheduleFixture = {
  total: 2,
  limit: 100,
  offset: 0,
  items: [
    {
      machine_id: 1,
      machine_code: 'VEND-01',
      machine_type: 'PERISHABLE_FOOD',
      location_id: 1,
      location_name: 'Sede Central · Hall',
      zone_id: 2,
      zone_name: 'Zona Norte',
      has_schedule: true,
      next_scheduled_date: TOMORROW,
      schedule: {
        id: 10,
        machine_id: 1,
        cadence_type: 'DAILY',
        cadence_label: 'Diaria',
        day_of_week: null,
        anchor_date: null,
        preferred_time_window: 'MORNING',
        preferred_time_window_label: 'Mañana',
        include_weekends: true,
        notes: 'Acceso por muelle antes de las 11:30.',
        is_active: true
      }
    },
    {
      machine_id: 2,
      machine_code: 'VEND-02',
      machine_type: 'SNACKS',
      location_id: 2,
      location_name: 'Sede Norte',
      zone_id: 2,
      zone_name: 'Zona Norte',
      has_schedule: false,
      next_scheduled_date: null,
      schedule: null
    }
  ]
};

api.coordinator.getRestockRouteDay = async (params) => {
  calls.push({ method: 'getRestockRouteDay', params });
  return { data: dayFixture };
};
api.coordinator.getRestockSchedules = async (params) => {
  calls.push({ method: 'getRestockSchedules', params });
  return { data: scheduleFixture };
};
api.coordinator.createRestockSchedule = async (payload) => {
  calls.push({ method: 'createRestockSchedule', payload });
  return { data: { schedule: { id: 11, ...payload } } };
};
api.coordinator.updateRestockSchedule = async (machineId, payload) => {
  calls.push({ method: 'updateRestockSchedule', machineId, payload });
  return { data: { schedule: { id: 12, machine_id: machineId, ...payload } } };
};
api.coordinator.bulkRestockSchedules = async (payload) => {
  calls.push({ method: 'bulkRestockSchedules', payload });
  return { data: { matched_count: 2, created_count: 1, updated_count: 1, skipped_count: 0, machine_ids: [1, 2] } };
};
api.coordinator.deactivateRestockSchedule = async (scheduleId) => {
  calls.push({ method: 'deactivateRestockSchedule', scheduleId });
  return { data: { schedule_id: scheduleId, deactivated: true } };
};
api.coordinator.createRestockRouteOverride = async (payload) => {
  calls.push({ method: 'createRestockRouteOverride', payload });
  return { data: { override: { id: 56, ...payload }, fefo_alert: payload.override_type === 'SKIPPED' } };
};
api.coordinator.revokeRestockRouteOverride = async (overrideId) => {
  calls.push({ method: 'revokeRestockRouteOverride', overrideId });
  return { data: { override: { id: overrideId, status: 'REVOKED' }, revoked: true } };
};
api.coordinator.getProductLoadingProposal = async (params) => {
  calls.push({ method: 'getProductLoadingProposal', params });
  return { data: { demand_mode: params.demand_mode, service_date: params.service_date, items: [] } };
};
api.coordinator.generateProductLoadingBatch = async (payload) => {
  calls.push({ method: 'generateProductLoadingBatch', payload });
  return { data: { created_count: 1, skipped_count: 0, created: [], skipped: [] } };
};

function buildVm() {
  const vm = { ...CoordinatorRestockRoutePlannerSubTab.data(), ...CoordinatorRestockRoutePlannerSubTab.methods };
  for (const [name, getter] of Object.entries(CoordinatorRestockRoutePlannerSubTab.computed || {})) {
    Object.defineProperty(vm, name, { get: getter, configurable: true });
  }
  return vm;
}

async function run() {
  const vm = buildVm();

  // ─────────────────────────────────────────────────────────────────────
  // 1. Tablero del día
  // ─────────────────────────────────────────────────────────────────────
  console.log('--- 1. Tablero «Ruta del Día» ---');

  vm.serviceDate = TODAY;
  vm.dayFilters.restocker_id = '7';
  await vm.loadDay();
  const dayCall = calls.filter((call) => call.method === 'getRestockRouteDay').at(-1);
  assert(
    '1.1 El tablero envía service_date y los filtros saneados',
    dayCall?.params?.service_date === TODAY
      && dayCall?.params?.restocker_id === 7
      && !Object.prototype.hasOwnProperty.call(dayCall.params, 'zone_id')
  );
  assert(
    '1.2 Publica los siete contadores del único día',
    vm.kpis.scheduled_machines_count === 5
      && vm.kpis.injected_fefo_count === 1
      && vm.kpis.completed_count === 1
      && vm.kpis.skipped_count === 1
      && vm.kpis.blocked_count === 1
      && vm.kpis.uncovered_count === 1
  );
  assert('1.3 La bandeja sin cobertura queda visible', vm.uncoveredStops.length === 1 && vm.hasUncovered === true);
  assert('1.4 La ruta del titular se publica con su parada FEFO', vm.routes.length === 1 && vm.routes[0].stops[0].machines[0].has_fefo_alert === true);
  assert('1.5 La fecha futura se marca como proyección', vm.isFuture === false && vm.futureLabel === '');
  vm.serviceDate = TOMORROW;
  assert('1.6 Cambiar a mañana activa la proyección', vm.isFuture === true && vm.futureLabel.includes('proyección'));
  vm.serviceDate = TODAY;

  // ─────────────────────────────────────────────────────────────────────
  // 2. Omisión, desvío y revocación
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 2. Omisión, desvío y revocación ---');

  vm.openSkipModal(1);
  vm.overrideForm.reason_text = 'Corto';
  assert('2.1 Un motivo corto bloquea la omisión en cliente', vm.validateOverride() === false && vm.overrideError.includes('20'));
  vm.overrideForm.reason_text = 'La conserjería permanece cerrada con persiana metálica bajada y sin personal de acceso.';
  await vm.saveOverride();
  const skipCall = calls.filter((call) => call.method === 'createRestockRouteOverride').at(-1);
  assert(
    '2.2 La omisión envía máquina, fecha, causa y motivo',
    skipCall?.payload?.machine_id === 1
      && skipCall?.payload?.service_date === TODAY
      && skipCall?.payload?.override_type === 'SKIPPED'
      && skipCall?.payload?.skip_reason_code === 'LOCATION_CLOSED'
      && skipCall?.payload?.reason_text.length >= 20
  );
  assert('2.3 La omisión cierra el modal y recarga el tablero', vm.showOverrideModal === false && vm.overrideSuccess.includes('omisión'));

  vm.openReassignModal(1);
  vm.overrideForm.target_restocker_id = '';
  assert('2.4 Un desvío sin receptor se rechaza', vm.validateOverride() === false && vm.overrideError.includes('receptor'));
  vm.overrideForm.target_restocker_id = '8';
  await vm.saveOverride();
  const reassignCall = calls.filter((call) => call.method === 'createRestockRouteOverride').at(-1);
  assert(
    '2.5 El desvío envía receptor y causa tipificada',
    reassignCall?.payload?.override_type === 'REASSIGNED'
      && reassignCall?.payload?.target_restocker_id === 8
      && reassignCall?.payload?.reassignment_reason_code === 'ZONE_UNCOVERED'
  );

  await vm.revokeOverride({ id: 55 });
  const revokeCall = calls.filter((call) => call.method === 'revokeRestockRouteOverride').at(-1);
  assert('2.6 La revocación envía el identificador vivo', revokeCall?.overrideId === 55 && vm.overrideSuccess.includes('revocada'));

  // ─────────────────────────────────────────────────────────────────────
  // 3. Agendas individuales y alta masiva
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 3. Agendas individuales y alta masiva ---');

  await vm.loadSchedules();
  const scheduleCall = calls.filter((call) => call.method === 'getRestockSchedules').at(-1);
  assert('3.1 El catálogo se carga con límite y sin filtros vacíos', scheduleCall?.params?.limit === 100 && !Object.prototype.hasOwnProperty.call(scheduleCall.params, 'search'));
  assert('3.2 Distingue máquinas con y sin agenda', vm.schedules.length === 2 && vm.schedules[1].has_schedule === false);

  vm.openScheduleModal();
  vm.scheduleForm.machine_id = '2';
  vm.scheduleForm.cadence_type = 'WEEKLY';
  vm.scheduleForm.day_of_week = '';
  assert('3.3 WEEKLY sin día se rechaza antes de viajar', vm.validateSchedule() === false && vm.scheduleFormError.includes('día'));
  vm.scheduleForm.day_of_week = '3';
  vm.scheduleForm.preferred_time_window = 'MORNING';
  await vm.saveSchedule();
  const createScheduleCall = calls.filter((call) => call.method === 'createRestockSchedule').at(-1);
  assert(
    '3.4 El alta envía la cadencia semanal y el día ISO',
    createScheduleCall?.payload?.machine_id === 2
      && createScheduleCall?.payload?.cadence_type === 'WEEKLY'
      && createScheduleCall?.payload?.day_of_week === 3
  );

  vm.openScheduleModal(scheduleFixture.items[0]);
  assert('3.5 Editar precarga la agenda viva y su máquina', vm.scheduleMode === 'edit' && vm.scheduleForm.machine_id === 1 && vm.scheduleForm.cadence_type === 'DAILY');
  vm.scheduleForm.include_weekends = true;
  await vm.saveSchedule();
  const updateScheduleCall = calls.filter((call) => call.method === 'updateRestockSchedule').at(-1);
  assert(
    '3.6 El versionado usa PUT por máquina y conserva el fin de semana',
    updateScheduleCall?.machineId === 1 && updateScheduleCall?.payload?.include_weekends === true
  );

  vm.openBulkModal();
  vm.bulkForm.machine_ids = '1, 2';
  vm.bulkForm.cadence_type = 'DAILY';
  vm.bulkForm.include_weekends = true;
  await vm.saveBulk();
  const bulkCall = calls.filter((call) => call.method === 'bulkRestockSchedules').at(-1);
  assert(
    '3.7 El alta masiva envía ids explícitos y sobrescritura atómica',
    JSON.stringify(bulkCall?.payload?.machine_ids) === JSON.stringify([1, 2])
      && bulkCall?.payload?.overwrite_existing === true
      && bulkCall?.payload?.include_weekends === true
  );
  assert('3.8 El lote publica creados, actualizados y omitidos', vm.bulkResult?.created_count === 1 && vm.bulkResult?.updated_count === 1);

  await vm.deactivateSchedule(scheduleFixture.items[0]);
  const deactivateCall = calls.filter((call) => call.method === 'deactivateRestockSchedule').at(-1);
  assert('3.9 La baja es lógica y envía el id de la versión', deactivateCall?.scheduleId === 10 && vm.scheduleSuccess.includes('baja'));

  // ─────────────────────────────────────────────────────────────────────
  // 4. Integración con la pestaña M33 y TODAY_ROUTE
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 4. Integración con M33 y TODAY_ROUTE ---');

  const product = { ...CoordinatorProductLoadingTab.data(), ...CoordinatorProductLoadingTab.methods };
  product.proposalForm.restocker_id = 7;
  product.proposalForm.demand_mode = 'TODAY_ROUTE';
  product.proposalForm.service_date = TODAY;
  await product.loadProposal();
  const proposalCall = calls.filter((call) => call.method === 'getProductLoadingProposal').at(-1);
  assert(
    '4.1 La propuesta TODAY_ROUTE envía la fecha de ruta',
    proposalCall?.params?.demand_mode === 'TODAY_ROUTE' && proposalCall?.params?.service_date === TODAY
  );

  product.batchForm.demand_mode = 'TODAY_ROUTE';
  product.batchForm.service_date = TODAY;
  await product.generateBatch();
  const batchCall = calls.filter((call) => call.method === 'generateProductLoadingBatch').at(-1);
  assert(
    '4.2 El lote TODAY_ROUTE envía la fecha de ruta',
    batchCall?.payload?.demand_mode === 'TODAY_ROUTE' && batchCall?.payload?.service_date === TODAY
  );

  assert(
    '4.3 La pestaña registra la quinta sub-vista y su botón',
    CoordinatorProductLoadingTab.components.CoordinatorRestockRoutePlannerSubTab === CoordinatorRestockRoutePlannerSubTab
      && CoordinatorProductLoadingTab.template.includes('data-testid="product-loading-route-planner-section-button"')
      && CoordinatorProductLoadingTab.template.includes('<CoordinatorRestockRoutePlannerSubTab')
  );
  assert(
    '4.4 Los tres formularios M33 ofrecen TODAY_ROUTE',
    (CoordinatorProductLoadingTab.template.match(/value="TODAY_ROUTE"/g) || []).length >= 3
      && CoordinatorProductLoadingTab.template.includes('Ruta del día')
  );

  // ─────────────────────────────────────────────────────────────────────
  // 5. Cliente API
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 5. Cliente API ---');

  Object.assign(api.coordinator, realPlannerApi);
  const paths = [];
  const originalRequest = api.request;
  api.request = async (path, options = {}) => {
    paths.push({ method: options.method || 'GET', path });
    return { data: {} };
  };

  await api.coordinator.getRestockSchedules({ zone_id: 2, has_schedule: false });
  await api.coordinator.createRestockSchedule({ machine_id: 1 });
  await api.coordinator.updateRestockSchedule(1, { cadence_type: 'DAILY' });
  await api.coordinator.bulkRestockSchedules({ machine_ids: [1] });
  await api.coordinator.deactivateRestockSchedule(10);
  await api.coordinator.getRestockRouteDay({ service_date: TODAY, restocker_id: 7 });
  await api.coordinator.createRestockRouteOverride({ machine_id: 1 });
  await api.coordinator.revokeRestockRouteOverride(55);

  api.request = originalRequest;

  assert(
    '5.1 Las ocho rutas del planificador usan los verbos y paths canónicos',
    paths.some((entry) => entry.method === 'GET' && entry.path.startsWith('/coordinator/restock-schedules?'))
      && paths.some((entry) => entry.method === 'POST' && entry.path === '/coordinator/restock-schedules')
      && paths.some((entry) => entry.method === 'PUT' && entry.path === '/coordinator/restock-schedules/machine/1')
      && paths.some((entry) => entry.method === 'POST' && entry.path === '/coordinator/restock-schedules/bulk')
      && paths.some((entry) => entry.method === 'PATCH' && entry.path === '/coordinator/restock-schedules/10/deactivate')
      && paths.some((entry) => entry.method === 'GET' && entry.path.startsWith('/coordinator/restock-route/day?'))
      && paths.some((entry) => entry.method === 'POST' && entry.path === '/coordinator/restock-route/overrides')
      && paths.some((entry) => entry.method === 'POST' && entry.path === '/coordinator/restock-route/overrides/55/revoke')
  );
  assert(
    '5.2 El tablero codifica service_date y restocker_id en la query',
    paths.some((entry) => entry.path.includes(`service_date=${TODAY}`) && entry.path.includes('restocker_id=7'))
  );

  // ─────────────────────────────────────────────────────────────────────
  // 6. Sistema de diseño y responsive 360 × 800
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 6. Sistema de diseño y responsive ---');

  const source = readFileSync(
    fileURLToPath(new URL('../../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js', import.meta.url)),
    'utf8'
  );
  const template = CoordinatorRestockRoutePlannerSubTab.template;
  assert('6.1 No hay literales hexadecimales en el componente', !/#[0-9a-fA-F]{3,8}\b/.test(source));
  assert(
    '6.2 El color entra por tokens del sistema',
    template.includes('var(--color-primary)')
      && template.includes('var(--color-ink-slate)')
      && template.includes('var(--color-ink-muted)')
  );
  assert(
    '6.3 Las tablas anchas se desplazan dentro de su tarjeta, no en la página',
    template.includes('overflow-x: auto')
      && template.includes('minmax(0, 1fr)')
      && template.includes('flex-wrap: wrap')
  );
  assert(
    '6.4 No hay anchos fijos que rompan 360 × 800',
    !/min-width:\s*(?:[5-9]\d\d|\d{4,})px/.test(template)
      && !/width:\s*(?:[5-9]\d\d|\d{4,})px/.test(template)
  );
  assert(
    '6.5 El mapa territorial permanece ajeno al planificador',
    !readFileSync(
      fileURLToPath(new URL('../../public/assets/js/components/CoordinatorTerritorialMapTab.js', import.meta.url)),
      'utf8'
    ).includes('restock-route')
  );

  console.log('\n======================================================================');
  console.log(` Aserciones: ${assertions} · Fallos: ${failures}`);
  console.log(failures === 0 ? ' RESULTADO: 100% EN VERDE' : ' RESULTADO: EN ROJO');
  console.log('======================================================================');

  process.exit(failures === 0 ? 0 : 1);
}

run().catch((error) => {
  console.error('\n[FAIL] Excepción inesperada:', error);
  process.exit(1);
});
