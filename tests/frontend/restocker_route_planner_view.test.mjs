/**
 * VendGuard - Frontend Test Suite (restocker_route_planner_view.test.mjs)
 *
 * Certifica T-RRP-12 del Módulo 37:
 *   - Vista móvil por sede en los 3 bloques del optimizador, con badges FEFO,
 *     L1, qty=0, Franja y Sin GPS, y acordeones inferiores.
 *   - Modal «⏭️ Omitir parada hoy» con motivo de 20..500 caracteres reales.
 *   - Cola offline con snapshot invalidado a medianoche, drenaje tras visitas
 *     M34, volcado de conflictos 409 y limpieza por baja.
 *   - Mapa reutilizando Mercator.js y MapZoomPan.js.
 *   - Precache del shell y responsive 360 × 800 sin scroll horizontal.
 *
 * Dogma Vanilla: Node.js ESM nativo, sin dependencias externas.
 */

const storage = new Map();
globalThis.localStorage = {
  getItem: (key) => storage.get(key) ?? null,
  setItem: (key, value) => storage.set(key, String(value)),
  removeItem: (key) => storage.delete(key),
  clear: () => storage.clear()
};
globalThis.sessionStorage = { ...globalThis.localStorage };

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { api, ApiError } from '../../public/assets/js/api.js';
import { RestockerRouteView } from '../../public/assets/js/components/RestockerRouteView.js';
import { RestockerRouteMapModal } from '../../public/assets/js/components/RestockerRouteMapModal.js';
import {
  RestockRouteOverrideOfflineQueue,
  RESTOCK_ROUTE_OVERRIDE_PENDING,
  RESTOCK_ROUTE_OVERRIDE_CONFLICT
} from '../../public/assets/js/utils/RestockRouteOverrideOfflineQueue.js';

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
console.log(' VendGuard: Frontend Test Suite - Restocker Route Planner (T-RRP-12)');
console.log('======================================================================\n');

const routePlanFixture = () => ({
  origin: { source: 'BASE_CENTRAL', latitude: 41.385064, longitude: 2.1734035 },
  summary: {
    pending_stops_count: 3,
    pending_machines_count: 3,
    completed_machines_count: 1,
    skipped_machines_count: 1,
    blocked_machines_count: 1,
    fefo_alerts_count: 1,
    total_distance_km: 6.4,
    unmapped_stops_count: 1
  },
  full_route_navigation_url: 'https://www.google.com/maps/dir/?api=1&origin=41.385064,2.1734035&destination=41.4036299,2.1743558&travelmode=driving',
  waypoints_truncated: false,
  stops: [
    {
      stop_order: 1,
      location_id: 1,
      location_name: 'Hospital Universitario Central',
      address: 'Av. Diagonal 450, Barcelona',
      sla_tier: 'CRITICAL_INFRASTRUCTURE_24_7',
      priority_block: 'FEFO_SANITARY',
      has_valid_coordinates: true,
      latitude: 41.4036299,
      longitude: 2.1743558,
      distance_km: 6.4,
      navigation_url: 'https://www.google.com/maps/dir/?api=1&destination=41.4036299,2.1743558&travelmode=driving',
      earliest_fefo_expiration_date: '2026-11-21',
      preferred_time_window: 'MORNING',
      site_coincidence: null,
      machines: [
        {
          machine_id: 5,
          machine_code: 'VEND-MAD-005',
          machine_type: 'PERISHABLE_FOOD',
          schedule_id: 12,
          triggers: ['FEFO_ALERT', 'SCHEDULED'],
          has_fefo_alert: true,
          has_expired_cold_batch: true,
          empty_slots_count: 2,
          active_l1_incident: null,
          is_temporary_reassignment: false,
          earliest_fefo_expiration_date: '2026-11-21',
          preferred_time_window: 'MORNING',
          status: 'PENDING'
        }
      ]
    },
    {
      stop_order: 2,
      location_id: 2,
      location_name: 'Oficinas Centro',
      address: 'Calle Balmes 12, Barcelona',
      sla_tier: 'PRIORITY_CORPORATE',
      priority_block: 'DEMAND_L1_STOCKOUT',
      has_valid_coordinates: false,
      latitude: null,
      longitude: null,
      distance_km: null,
      navigation_url: null,
      earliest_fefo_expiration_date: null,
      preferred_time_window: 'MIDDAY',
      site_coincidence: null,
      machines: [
        {
          machine_id: 8,
          machine_code: 'VEND-MAD-008',
          machine_type: 'SNACKS',
          schedule_id: null,
          triggers: ['L1_INCIDENT', 'EMPTY_SLOT'],
          has_fefo_alert: false,
          has_expired_cold_batch: false,
          empty_slots_count: 3,
          active_l1_incident: { id: 77, assigned_restocker_id: 12 },
          is_temporary_reassignment: true,
          earliest_fefo_expiration_date: null,
          preferred_time_window: 'MIDDAY',
          status: 'PENDING'
        }
      ]
    },
    {
      stop_order: 3,
      location_id: 3,
      location_name: 'Residencial Norte',
      address: 'Av. Vallcarca 88, Barcelona',
      sla_tier: 'STANDARD',
      priority_block: 'SCHEDULED_CADENCE',
      has_valid_coordinates: true,
      latitude: 41.412345,
      longitude: 2.156789,
      distance_km: 3.1,
      navigation_url: 'https://www.google.com/maps/dir/?api=1&destination=41.412345,2.156789&travelmode=driving',
      earliest_fefo_expiration_date: null,
      preferred_time_window: 'AFTERNOON',
      site_coincidence: null,
      machines: [
        {
          machine_id: 9,
          machine_code: 'VEND-MAD-009',
          machine_type: 'COLD_DRINKS',
          schedule_id: 13,
          triggers: ['SCHEDULED'],
          has_fefo_alert: false,
          has_expired_cold_batch: false,
          empty_slots_count: 0,
          active_l1_incident: null,
          is_temporary_reassignment: false,
          earliest_fefo_expiration_date: null,
          preferred_time_window: 'AFTERNOON',
          status: 'PENDING'
        }
      ]
    }
  ],
  completed_stops: [
    { machine_id: 2, machine_code: 'VEND-MAD-002', location_id: 1, location_name: 'Hospital Universitario Central', visit_status: 'COMPLETED' }
  ],
  skipped_stops: [
    { machine_id: 3, machine_code: 'VEND-MAD-003', location_id: 2, location_name: 'Oficinas Centro', skip_reason_label: 'Local cerrado', had_active_fefo_alert: false }
  ],
  blocked_stops: [
    { machine_id: 4, machine_code: 'VEND-MAD-004', location_id: 3, location_name: 'Residencial Norte', block_reason: 'SANITARY_QUARANTINE', block_reason_label: 'Bloqueada por cuarentena sanitaria activa' }
  ]
});

function buildView(overrides = {}) {
  const vm = {
    ...RestockerRouteView.data(),
    ...RestockerRouteView.methods,
    ...overrides
  };
  for (const [name, getter] of Object.entries(RestockerRouteView.computed || {})) {
    Object.defineProperty(vm, name, { get: getter, configurable: true });
  }
  return vm;
}

async function run() {
  // ─────────────────────────────────────────────────────────────────────
  // 1. Cola offline y snapshot
  // ─────────────────────────────────────────────────────────────────────
  console.log('--- 1. Cola offline y snapshot ---');

  const queue = new RestockRouteOverrideOfflineQueue({ indexedDB: null });
  assert('1.1 La cola degrada a memoria sin IndexedDB', queue.isMemoryBacked() === true);

  const enqueued = await queue.enqueue({
    client_mutation_id: 'mut-skip-0001',
    restocker_id: 12,
    machine_id: 5,
    machine_code: 'VEND-MAD-005',
    payload: { service_date: '2026-11-21', skip_reason_code: 'LOCATION_CLOSED', reason_text: 'La conserjería permanece cerrada con persiana metálica bajada.' }
  });
  await queue.enqueue({
    client_mutation_id: 'mut-skip-0001',
    restocker_id: 12,
    machine_id: 5,
    machine_code: 'VEND-MAD-005',
    payload: { service_date: '2026-11-21', skip_reason_code: 'LOCATION_CLOSED', reason_text: 'La conserjería permanece cerrada con persiana metálica bajada.' }
  });
  await queue.enqueue({
    client_mutation_id: 'mut-skip-0002',
    restocker_id: 99,
    machine_id: 6,
    payload: { service_date: '2026-11-21' }
  });
  const ownerItems = await queue.list(12);
  assert('1.2 La cola es idempotente por client_mutation_id', ownerItems.length === 1 && enqueued.status === RESTOCK_ROUTE_OVERRIDE_PENDING);
  assert('1.3 La cola aísla por restocker_id', (await queue.list(99)).length === 1 && (await queue.list(99))[0].machine_id === 6);

  const reactivePlan = new Proxy(routePlanFixture(), {});
  const reactiveSnapshot = await queue.saveSnapshot(12, '2026-11-21', reactivePlan);
  let reactivePlanCloneable = true;
  try {
    structuredClone(reactiveSnapshot.route_plan);
  } catch (error) {
    reactivePlanCloneable = false;
  }
  assert(
    '1.3b El snapshot desenvuelve el plan reactivo en un objeto clonable (H-37-01)',
    reactivePlanCloneable
      && reactiveSnapshot.route_plan !== reactivePlan
      && reactiveSnapshot.route_plan.stops.length === 3
  );

  const snapshot = await queue.saveSnapshot(12, '2026-11-21', routePlanFixture(), '2026-11-21T22:30:00+01:00');
  assert('1.4 El snapshot conserva la ruta y su fecha de servicio', snapshot.service_date === '2026-11-21' && snapshot.route_plan.stops.length === 3);
  assert('1.5 El snapshot vigente se recupera para el mismo día', (await queue.loadSnapshot(12, '2026-11-21'))?.route_plan.stops.length === 3);
  assert('1.6 El snapshot de ayer se invalida al cambiar el día', (await queue.loadSnapshot(12, '2026-11-22')) === null);
  assert('1.7 La invalidación borra el snapshot caducado', (await queue.loadSnapshot(12, '2026-11-21')) === null);

  await queue.saveSnapshot(12, '2026-11-21', routePlanFixture());
  const conflicted = await queue.markConflict('mut-skip-0001', 'La parada ya figura completada hoy.');
  assert('1.8 El conflicto conserva la entrada y su motivo', conflicted?.status === RESTOCK_ROUTE_OVERRIDE_CONFLICT && conflicted.conflict_reason.includes('completada'));
  await queue.clear(12);
  assert('1.9 La baja limpia cola y snapshot del reponedor', (await queue.list(12)).length === 0 && (await queue.loadSnapshot(12, '2026-11-21')) === null);

  // ─────────────────────────────────────────────────────────────────────
  // 2. Vista por bloques y badges
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 2. Vista por bloques y badges ---');

  const vm = buildView({
    restocker: { id: 12, name: 'Lucía Gómez' },
    currentUser: { id: 12 },
    routePlan: routePlanFixture(),
    routeServiceDate: '2026-11-21'
  });
  vm.routeToday = () => '2026-11-21';

  assert('2.1 La vista publica las tres paradas pendientes', vm.routePlanStops.length === 3 && vm.routePlanSummary.pending_machines_count === 3);
  assert(
    '2.2 Los bloques respetan el orden FEFO → demanda → cadencia',
    vm.routeBlocks.map((block) => block.key).join('|') === 'FEFO_SANITARY|DEMAND_L1_STOCKOUT|SCHEDULED_CADENCE'
  );
  assert('2.3 Cada bloque agrupa su parada de sede', vm.routeBlocks[0].stops.length === 1 && vm.routeBlocks[2].stops[0].location_name === 'Residencial Norte');
  assert('2.4 El FEFO se reconoce por máquina y por parada', vm.routeMachineHasFefo(vm.routePlanStops[0].machines[0]) === true && vm.routeStopHasFefo(vm.routePlanStops[0]) === true);
  assert('2.5 L1 y qty=0 se reconocen en la máquina de demanda', vm.routeMachineHasL1(vm.routePlanStops[1].machines[0]) === true && vm.routeMachineHasEmptySlots(vm.routePlanStops[1].machines[0]) === true);
  assert('2.6 La franja y la degradación sin GPS se traducen', vm.routeTimeWindowLabel('MORNING') === 'Mañana' && vm.routeStopHasGps(vm.routePlanStops[1]) === false);
  assert('2.7 El bloque y los disparadores tienen etiquetas canónicas', vm.routeBlockLabel('FEFO_SANITARY').includes('Bloque 1') && vm.routeTriggerLabel('FEFO_ALERT') === 'FEFO');
  assert('2.8 El resumen inferior conserva completadas, omitidas y bloqueadas', vm.routePlanCompleted.length === 1 && vm.routePlanSkipped.length === 1 && vm.routePlanBlocked.length === 1);

  const coincidencePlan = routePlanFixture();
  coincidencePlan.stops[0].site_coincidence = [{
    user_id: 77,
    name: 'Jordi SAT',
    operator_code: 'SAT-07',
    role: 'TECHNICIAN',
    activity_summary: '1 avería SAT activa en esta sede'
  }];
  const coincidenceVm = buildView({
    restocker: { id: 12, name: 'Lucía Gómez' },
    currentUser: { id: 12 },
    routePlan: coincidencePlan,
    routeServiceDate: '2026-11-21',
    routeToday: () => '2026-11-21'
  });
  assert(
    '2.9 La vista lee site_coincidence desde las paradas de route_plan',
    coincidenceVm.siteCoincidenceForLocation(1).length === 1
      && coincidenceVm.siteCoincidenceSummary(1).includes('Jordi SAT (SAT-07)')
  );

  // ─────────────────────────────────────────────────────────────────────
  // 3. Omisión online, offline y drenaje
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 3. Omisión online, offline y drenaje ---');

  const originalSkip = api.restocker.skipRestockStop;
  const originalDump = api.restocker.dumpRestockOverrideConflict;
  const skipCalls = [];
  const dumpCalls = [];
  api.restocker.skipRestockStop = async (machineId, payload) => {
    skipCalls.push({ machineId, payload });
    return { data: { override: { id: 32, machine_id: machineId, ...payload } } };
  };
  api.restocker.dumpRestockOverrideConflict = async (payload) => {
    dumpCalls.push(payload);
    return { data: { archived: true } };
  };

  vm.loadMyRoute = async () => {};
  vm.loadPendingRouteOverrides = async () => {};
  vm.openSkipRouteModal(vm.routePlanStops[0].machines[0], vm.routePlanStops[0]);
  assert('3.1 El modal precarga máquina, fecha y mutación idempotente', vm.showSkipRouteModal === true && vm.skipRouteForm.service_date === '2026-11-21' && vm.skipRouteForm.client_mutation_id.length >= 8);
  vm.skipRouteForm.reason_text = 'Corto';
  assert('3.2 Un motivo corto se rechaza antes de viajar', vm.validateSkipRoute() === false && vm.skipRouteError.includes('20'));
  vm.skipRouteForm.reason_text = 'La conserjería permanece cerrada con persiana metálica bajada y sin personal de acceso.';
  await vm.submitSkipRoute();
  assert(
    '3.3 La omisión online envía máquina, causa y motivo normalizado',
    skipCalls.at(-1)?.machineId === 5
      && skipCalls.at(-1)?.payload?.service_date === '2026-11-21'
      && skipCalls.at(-1)?.payload?.skip_reason_code === 'LOCATION_CLOSED'
      && skipCalls.at(-1)?.payload?.reason_text.length >= 20
  );

  const offlineVm = buildView({
    restocker: { id: 12, name: 'Lucía Gómez' },
    currentUser: { id: 12 },
    routePlan: routePlanFixture(),
    routeServiceDate: '2026-11-21',
    routeToday: () => '2026-11-21'
  });
  const previousNavigator = globalThis.navigator;
  Object.defineProperty(globalThis, 'navigator', { value: { onLine: false }, configurable: true });
  await offlineVm.ensureRouteOverrideQueue().clear(12);
  offlineVm.loadMyRoute = async () => {};
  offlineVm.openSkipRouteModal(offlineVm.routePlanStops[0].machines[0], offlineVm.routePlanStops[0]);
  offlineVm.skipRouteForm.reason_text = 'La conserjería permanece cerrada con persiana metálica bajada y sin personal de acceso.';
  await offlineVm.submitSkipRoute();
  assert('3.4 Sin red la omisión se encola y se refleja de inmediato', offlineVm.pendingRouteOverrides.length === 1 && offlineVm.skipRouteSuccess.includes('cola offline'));
  assert('3.5 La parada omitida desaparece de la ruta pendiente', offlineVm.routePlan.stops.every((stop) => stop.machines.every((machine) => machine.machine_id !== 5)));
  assert('3.6 La omisión offline se publica en el acordeón inferior', offlineVm.routePlanSkipped.some((stop) => stop.machine_id === 5));

  Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true });

  const syncVm = buildView({ restocker: { id: 12 }, currentUser: { id: 12 }, routePlan: routePlanFixture(), routeServiceDate: '2026-11-21' });
  syncVm.routeToday = () => '2026-11-21';
  syncVm.loadMyRoute = async () => {};
  await syncVm.ensureRouteOverrideQueue().clear(12);
  await syncVm.ensureRouteOverrideQueue().enqueue({
    client_mutation_id: 'mut-sync-0001',
    restocker_id: 12,
    machine_id: 8,
    machine_code: 'VEND-MAD-008',
    payload: { service_date: '2026-11-21', skip_reason_code: 'LOCAL_HOLIDAY', reason_text: 'Edificio municipal cerrado todo el día por festivo local confirmado.' }
  });
  await syncVm.syncPendingRouteOverrides();
  assert('3.7 El drenaje online reenvía y vacía la cola', skipCalls.at(-1)?.machineId === 8 && (await syncVm.ensureRouteOverrideQueue().list(12)).length === 0);

  await syncVm.ensureRouteOverrideQueue().enqueue({
    client_mutation_id: 'mut-sync-409',
    restocker_id: 12,
    machine_id: 9,
    machine_code: 'VEND-MAD-009',
    payload: { service_date: '2026-11-21', skip_reason_code: 'LOCATION_CLOSED', reason_text: 'La conserjería permanece cerrada con persiana metálica bajada.' }
  });
  api.restocker.skipRestockStop = async () => { throw new ApiError(409, 'RESTOCK_STOP_ALREADY_COMPLETED_TODAY', 'La parada ya figura completada hoy.'); };
  await syncVm.syncPendingRouteOverrides();
  assert(
    '3.8 Un 409 se vuelca a auditoría y no queda en bucle',
    dumpCalls.at(-1)?.http_status === 409
      && dumpCalls.at(-1)?.error_code === 'RESTOCK_STOP_ALREADY_COMPLETED_TODAY'
      && (await syncVm.ensureRouteOverrideQueue().list(12)).length === 0
  );

  const originalCreateVisit = api.restocker.createRestockVisit;
  const drainOrder = [];
  api.restocker.createRestockVisit = async (machineId) => {
    drainOrder.push(`VISIT:${machineId}`);
    return { data: { id: 9001 } };
  };
  api.restocker.skipRestockStop = async (machineId) => {
    drainOrder.push(`SKIP:${machineId}`);
    return { data: { override: { id: 33, machine_id: machineId } } };
  };
  const orderedDrainVm = buildView({
    restocker: { id: 12 },
    currentUser: { id: 12 },
    routePlan: routePlanFixture(),
    routeServiceDate: '2026-11-21',
    routeToday: () => '2026-11-21'
  });
  orderedDrainVm.loadMyRoute = async () => {};
  await orderedDrainVm.ensureVisitQueue().clear(12);
  await orderedDrainVm.ensureRouteOverrideQueue().clear(12);
  await orderedDrainVm.ensureVisitQueue().enqueue({
    client_mutation_id: 'mut-visit-drain-0001',
    restocker_id: 12,
    machine_id: 8,
    machine_code: 'VEND-MAD-008',
    payload: { status: 'COMPLETED', client_mutation_id: 'mut-visit-drain-0001', items: [] }
  });
  await orderedDrainVm.ensureRouteOverrideQueue().enqueue({
    client_mutation_id: 'mut-skip-drain-0001',
    restocker_id: 12,
    machine_id: 8,
    machine_code: 'VEND-MAD-008',
    payload: { service_date: '2026-11-21', skip_reason_code: 'LOCATION_CLOSED', reason_text: 'La conserjería permanece cerrada con persiana metálica bajada.' }
  });
  await orderedDrainVm.syncPendingVisits();
  assert(
    '3.9 El drenaje sincroniza visitas M34 antes que omisiones SKIP sobre la misma máquina',
    drainOrder.join('->') === 'VISIT:8->SKIP:8'
  );
  api.restocker.createRestockVisit = originalCreateVisit;

  Object.defineProperty(globalThis, 'navigator', { value: previousNavigator, configurable: true });
  api.restocker.skipRestockStop = originalSkip;
  api.restocker.dumpRestockOverrideConflict = originalDump;

  // ─────────────────────────────────────────────────────────────────────
  // 4. Snapshot de recuperación y limpieza por baja
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 4. Snapshot de recuperación y limpieza por baja ---');

  const snapshotVm = buildView({ restocker: { id: 12 }, currentUser: { id: 12 } });
  snapshotVm.routeToday = () => '2026-11-21';
  snapshotVm.loadPendingVisitQueue = async () => {};
  snapshotVm.loadPendingCashQueue = async () => {};
  await snapshotVm.ensureRouteOverrideQueue().saveSnapshot(12, '2026-11-21', routePlanFixture());
  const originalGet = api.get;
  api.get = async () => { throw Object.assign(new Error('Sin red'), { status: 0 }); };
  await snapshotVm.loadMyRoute();
  assert('4.1 Sin red se restaura el snapshot vigente', snapshotVm.routePlan?.stops.length === 3 && snapshotVm.routeSnapshotRestored === true);
  assert('4.2 La vista avisa de que trabaja con la copia local', snapshotVm.loadError.includes('Sin conexión'));
  api.get = originalGet;

  const logoutVm = buildView({ restocker: { id: 12 }, currentUser: { id: 12 } });
  const logoutDumpCalls = [];
  api.restocker.dumpRestockOverrideConflict = async (payload) => {
    logoutDumpCalls.push(payload);
    return { data: { archived: true } };
  };
  await logoutVm.ensureRouteOverrideQueue().enqueue({ client_mutation_id: 'mut-logout-1', restocker_id: 12, machine_id: 5, payload: {} });
  await logoutVm.ensureRouteOverrideQueue().saveSnapshot(12, '2026-11-21', routePlanFixture());
  await logoutVm.handleLogout();
  api.restocker.dumpRestockOverrideConflict = originalDump;
  assert(
    '4.3 La baja vuelca los pendientes a auditoría antes de limpiar la cola y el snapshot',
    logoutDumpCalls.length === 1
      && logoutDumpCalls[0].client_mutation_id === 'mut-logout-1'
      && logoutDumpCalls[0].error_code === 'ACCOUNT_DEACTIVATED_DUMP'
      && (await logoutVm.ensureRouteOverrideQueue().list(12)).length === 0
      && (await logoutVm.ensureRouteOverrideQueue().loadSnapshot(12, '2026-11-21')) === null
  );

  // ─────────────────────────────────────────────────────────────────────
  // 5. Mapa, API y contrato de diseño
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 5. Mapa, API y contrato de diseño ---');

  const mapVm = { ...RestockerRouteMapModal.data(), ...RestockerRouteMapModal.methods, routePlan: routePlanFixture(), modelValue: true };
  for (const [name, getter] of Object.entries(RestockerRouteMapModal.computed || {})) {
    Object.defineProperty(mapVm, name, { get: getter, configurable: true });
  }
  assert('5.1 El mapa publica las tres paradas y sus tiles', mapVm.stops.length === 3 && mapVm.mapTiles.length > 0);
  assert('5.2 El marcador y el origen se proyectan en el canvas', Number.isFinite(mapVm.markerFor(mapVm.stops[0]).x) && Number.isFinite(mapVm.originPoint().x));
  assert('5.3 La navegación por parada y la ruta completa son enlaces', mapVm.stopNavigationUrl(mapVm.stops[0]).includes('google.com/maps') && mapVm.fullRouteUrl.includes('google.com/maps'));
  assert('5.4 El color del marcador entra por tokens', mapVm.stopColor(mapVm.stops[0]).includes('var(--color-'));

  const requestPaths = [];
  const originalRequest = api.request;
  api.request = async (path, options = {}) => { requestPaths.push({ path, method: options.method || 'GET' }); return { data: {} }; };
  await api.restocker.skipRestockStop(5, { service_date: '2026-11-21' });
  await api.restocker.dumpRestockOverrideConflict({ machine_id: 5, http_status: 409 });
  api.request = originalRequest;
  assert(
    '5.5 El cliente móvil usa las dos rutas contractuales',
    requestPaths.some((entry) => entry.method === 'POST' && entry.path === '/restocker/route/stops/5/skip')
      && requestPaths.some((entry) => entry.method === 'POST' && entry.path === '/restocker/route/override-conflict-dump')
  );

  const viewSource = readFileSync(fileURLToPath(new URL('../../public/assets/js/components/RestockerRouteView.js', import.meta.url)), 'utf8');
  const mapSource = readFileSync(fileURLToPath(new URL('../../public/assets/js/components/RestockerRouteMapModal.js', import.meta.url)), 'utf8');
  const swSource = readFileSync(fileURLToPath(new URL('../../public/sw.js', import.meta.url)), 'utf8');
  assert('5.6 La vista no contiene hexadecimales literales', !/#[0-9a-fA-F]{3,8}\b/.test(viewSource));
  assert('5.7 El mapa no contiene hexadecimales literales', !/#[0-9a-fA-F]{3,8}\b/.test(mapSource));
  assert(
    '5.8 La vista declara los tres bloques, los badges y los acordeones',
    viewSource.includes('restocker-route-block')
      && viewSource.includes('restocker-route-stop-coincidence')
      && viewSource.includes('qty=0')
      && viewSource.includes('Sin GPS')
      && viewSource.includes('<details')
      && viewSource.includes('⏭️ Omitir parada hoy')
  );
  assert('5.9 El mapa reutiliza Mercator y MapZoomPan', mapSource.includes("from '../utils/Mercator.js'") && mapSource.includes("from '../utils/MapZoomPan.js'"));
  assert('5.10 El Service Worker precachea los módulos y sube la versión', swSource.includes('/assets/js/components/RestockerRouteMapModal.js') && swSource.includes('/assets/js/utils/RestockRouteOverrideOfflineQueue.js') && /vendguard-app-shell-v(\d+)/.exec(swSource)?.[1] >= 27);
  assert('5.11 La vista mantiene el ancho móvil y no fija anchos de escritorio', viewSource.includes('max-width: 480px') && !/min-width:\s*(?:[4-9]\d\d|\d{4,})px/.test(viewSource));
  assert('5.12 La vista registra el modal de mapa', RestockerRouteView.components?.RestockerRouteMapModal === RestockerRouteMapModal);

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
