/**
 * VendGuard - Frontend Test Suite (restocker_route_view.test.mjs)
 *
 * Certifica la vista móvil del Cargador / Reponedor de Ruta (T-RES-12 y
 * T-PLN-13 · D1, D2, RF-04.1 a RF-04.3, RF-05.2, QA 2, Art. V.4, RNF-03):
 * - Acceso exclusivo de `RESTOCKER`: otro rol se rechaza y la sesión se descarta.
 * - Cabecera con nombre, `operator_code` e insignias Titular / Suplente.
 * - Ciclo completo de jornada y cierre directo sin modal de relevo SAT.
 * - Contador diario en vivo y «⏱️ Mis Horas» de solo lectura, sin id ajeno.
 * - Estado base de ruta sin paradas inventadas (Features 24, 26 y 27).
 * - Listado de máquinas de las zonas vivas con resumen de stock y modal de
 *   planograma de solo lectura, sin coste ni margen (T-PLN-13, QA 2, Art. V.4).
 * - Enrutamiento de sesión del Shell Vue 3 hacia `restocker`.
 * - Fidelidad al sistema de diseño: tokens CSS, cero colores literales.
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
import { store } from '../../public/assets/js/store.js';
import { RestockerRouteView } from '../../public/assets/js/components/RestockerRouteView.js';
import { RestockVisitOfflineQueue } from '../../public/assets/js/utils/RestockVisitOfflineQueue.js';
import { createFakeIndexedDb } from '../Support/FakeIndexedDb.mjs';

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - RestockerRouteView (T-RES-12 / T-PLN-13)');
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
    ...RestockerRouteView.data(),
    ...RestockerRouteView.methods,
    ...overrides
  };
  for (const [name, getter] of Object.entries(RestockerRouteView.computed)) {
    Object.defineProperty(vm, name, { get: getter, configurable: true });
  }
  return vm;
}

const viewSource = readFileSync(
  new URL('../../public/assets/js/components/RestockerRouteView.js', import.meta.url),
  'utf8'
);
const appSource = readFileSync(new URL('../../public/assets/js/app.js', import.meta.url), 'utf8');
const storeSource = readFileSync(new URL('../../public/assets/js/store.js', import.meta.url), 'utf8');
const apiSource = readFileSync(new URL('../../public/assets/js/api.js', import.meta.url), 'utf8');

// ─────────────────────────────────────────────────────────────────────────────
// 1. Estado inicial y perfil
// ─────────────────────────────────────────────────────────────────────────────
const vm = buildVm();
assert('data() arranca fuera de turno', vm.shiftStatus === 'OFF_SHIFT');
assert('data() no inventa zonas al arrancar', vm.zones.primary_zone === null && vm.zones.backup_zones.length === 0);
assert('data() arranca sin paradas de ruta', Array.isArray(vm.routeStops) && vm.routeStops.length === 0);
assert('data() arranca con «Mis Horas» cerrado', vm.showMyHoursModal === false);
assert('data() no expone estado de relevo SAT', !('showShiftHandoverModal' in vm) && !('handoverMode' in vm));

store.state.user = { id: 12, name: 'Lucía Gómez', role: 'RESTOCKER' };
assert('isAuthenticated exige el rol RESTOCKER', vm.isAuthenticated === true);
store.state.user = { id: 3, name: 'Jordi Ruta', role: 'TECHNICIAN' };
assert('isAuthenticated rechaza a un técnico SAT', vm.isAuthenticated === false);
store.state.user = null;

// ─────────────────────────────────────────────────────────────────────────────
// 2. Bootstrap de ruta y cabecera de zonas (RF-04.1)
// ─────────────────────────────────────────────────────────────────────────────
const originalGet = api.get;
const originalPost = api.post;
let requestedUrls = [];

const bootstrapPayload = {
  data: {
    restocker: {
      id: 12,
      name: 'Lucía Gómez',
      email: 'lucia.restocker@vendguard.local',
      operator_code: 'REP-01',
      role: 'RESTOCKER'
    },
    zones: {
      primary_zone: { id: 1, code: 'ZONA-BCN-NORTE', name: 'Barcelona Litoral y Norte', role_in_zone: 'PRIMARY' },
      backup_zones: [{ id: 2, code: 'ZONA-BCN-CENTRO', name: 'Barcelona Distrito Centro', role_in_zone: 'BACKUP' }]
    },
    shift: { status: 'MEAL_BREAK', active_shift_id: 45, today_effective_seconds: 7200 },
    machines: [
      {
        id: 7,
        code: 'VEND-0701',
        model: 'Jofemar Vision',
        machine_type: 'COMBO',
        location_name: 'Hospital del Mar',
        site_code: 'HOSP-MAR',
        zone_id: 1,
        zone_code: 'ZONA-BCN-NORTE',
        zone_name: 'Barcelona Litoral y Norte',
        sanitary_status: 'OK',
        active_slots_count: 6,
        stockout_slots_count: 1,
        low_stock_slots_count: 2,
        ok_slots_count: 3,
        inactive_product_slots_count: 1,
        total_capacity: 60,
        total_estimated_qty: 38,
        fill_rate_percent: 63.33
      },
      {
        id: 8,
        code: 'VEND-0801',
        model: 'Necta Korinto',
        machine_type: 'HOT_DRINKS',
        location_name: 'Torre Glòries',
        site_code: 'TORRE-GLO',
        zone_id: 2,
        zone_code: 'ZONA-BCN-CENTRO',
        zone_name: 'Barcelona Distrito Centro',
        sanitary_status: 'OK',
        active_slots_count: 4,
        stockout_slots_count: 0,
        low_stock_slots_count: 0,
        ok_slots_count: 4,
        inactive_product_slots_count: 0,
        total_capacity: 40,
        total_estimated_qty: 36,
        fill_rate_percent: 90
      }
    ],
    route_stops: [],
    recent_visits: [
      {
        id: 31,
        visit_code: 'MRV-2026-0031',
        machine_id: 7,
        machine_code: 'VEND-0701',
        location_name: 'Hospital del Mar',
        status: 'COMPLETED',
        status_label: 'Completada',
        completed_at: '2026-11-13T09:15:00+00:00',
        cabin_temp_celsius: 3.8,
        units_added: 12,
        units_withdrawn: 2
      }
    ]
  }
};

api.get = async (url) => {
  requestedUrls.push(url);
  if (url === '/restocker/my-route') {
    return bootstrapPayload;
  }
  if (url === '/technician/shift/status') {
    return { status: 'ON_SHIFT', today_summary: { effective_formatted: '02h 30m', meal_break_formatted: '00h 15m' } };
  }
  throw new Error('URL inesperada: ' + url);
};

const routeVm = buildVm();
await routeVm.loadMyRoute();
assert('loadMyRoute() consulta el bootstrap propio del reponedor', requestedUrls[0] === '/restocker/my-route');
assert('loadMyRoute() publica el perfil y el código de operario', routeVm.restocker.name === 'Lucía Gómez' && routeVm.operatorCode === 'REP-01');
assert('loadMyRoute() publica la zona titular y la suplente', routeVm.primaryZone.id === 1 && routeVm.backupZones.length === 1);
assert('zoneRoleLabel() distingue Titular y Suplente', routeVm.zoneRoleLabel(routeVm.primaryZone) === 'Titular' && routeVm.zoneRoleLabel(routeVm.backupZones[0]) === 'Suplente');
assert('loadMyRoute() aplica el estado de turno del bootstrap', routeVm.shiftStatus === 'MEAL_BREAK' && routeVm.activeShiftId === 45);
assert('El contador diario formatea los segundos efectivos', routeVm.todayEffectiveFormatted === '02h 00m', routeVm.todayEffectiveFormatted);
assert('El estado base de ruta queda vacío hasta las Features 24/26/27', routeVm.routeStops.length === 0);
assert('loadMyRoute() publica las dos máquinas de sus zonas', routeVm.machines.length === 2 && routeVm.machines[0].code === 'VEND-0701');
assert('loadMyRoute() publica el historial reciente de visitas', routeVm.recentVisits.length === 1 && routeVm.recentVisits[0].visit_code === 'MRV-2026-0031');
assert('machineHasAlert() distingue la máquina con alertas', routeVm.machineHasAlert(routeVm.machines[0]) === true && routeVm.machineHasAlert(routeVm.machines[1]) === false);
assert('machineTypeLabel() traduce las tipologías', routeVm.machineTypeLabel('COMBO') === 'Mixta' && routeVm.machineTypeLabel('HOT_DRINKS') === 'Bebidas calientes');

requestedUrls = [];
await routeVm.loadShiftStatus();
assert('loadShiftStatus() consulta la ruta compartida del turno', requestedUrls[0] === '/technician/shift/status');
assert('loadShiftStatus() refresca el contador con today_summary', routeVm.todayEffectiveFormatted === '02h 30m');

// ─────────────────────────────────────────────────────────────────────────────
// 3. Ciclo de jornada y cierre directo (RF-04.2, D1)
// ─────────────────────────────────────────────────────────────────────────────
const posts = [];
api.post = async (url, body) => {
  posts.push({ url, body });
  if (url === '/technician/shift/start') {
    return { status: 'ON_SHIFT', today_summary: { effective_formatted: '00h 05m', meal_break_formatted: '00h 00m' } };
  }
  if (url === '/technician/shift/pause-meal') {
    return { status: 'MEAL_BREAK', today_summary: { effective_formatted: '02h 00m', meal_break_formatted: '00h 10m' } };
  }
  if (url === '/technician/shift/resume') {
    return { status: 'ON_SHIFT', today_summary: { effective_formatted: '02h 10m', meal_break_formatted: '00h 10m' } };
  }
  if (url === '/technician/shift/close') {
    return { data: { status: 'OFF_SHIFT', today_summary: { effective_formatted: '02h 20m', meal_break_formatted: '00h 10m' } } };
  }
  throw new Error('URL inesperada: ' + url);
};

const shiftVm = buildVm();
await shiftVm.startShift();
assert('Iniciar Turno llama al endpoint compartido', posts[0].url === '/technician/shift/start' && shiftVm.shiftStatus === 'ON_SHIFT');
await shiftVm.pauseMealShift();
assert('Pausa para Comer deja el turno en MEAL_BREAK', posts[1].url === '/technician/shift/pause-meal' && shiftVm.shiftStatus === 'MEAL_BREAK');
await shiftVm.resumeMealShift();
assert('Reanudar Turno vuelve a ON_SHIFT', posts[2].url === '/technician/shift/resume' && shiftVm.shiftStatus === 'ON_SHIFT');

shiftVm.shiftStatus = 'MEAL_BREAK';
await shiftVm.closeShift();
const closeCall = posts[posts.length - 1];
assert('Cerrar Turno desde MEAL_BREAK cierra directamente', closeCall.url === '/technician/shift/close' && shiftVm.shiftStatus === 'OFF_SHIFT');
assert('El cierre directo no envía relevo, receptor ni tickets SAT', closeCall.body && Object.keys(closeCall.body).length === 0);
assert('El cierre directo limpia el identificador de turno', shiftVm.activeShiftId === null);
assert('El cierre directo deja un acuse al reponedor', shiftVm.feedbackMessage.includes('Jornada cerrada'));

// ─────────────────────────────────────────────────────────────────────────────
// 4. «⏱️ Mis Horas» de solo lectura y aislamiento (RF-04.3, Art. V.4)
// ─────────────────────────────────────────────────────────────────────────────
const originalMyHours = api.technicianShift.getMyHours;
let myHoursArgs = null;
api.technicianShift.getMyHours = async (...args) => {
  myHoursArgs = args;
  return {
    data: {
      month: '2026-11',
      from_date: '2026-11-01',
      to_date: '2026-11-30',
      summary: { total_sessions: 2, gross_formatted: '16h 00m', meal_break_formatted: '01h 00m', effective_formatted: '15h 00m' },
      sessions: [
        {
          id: 45,
          shift_date: '2026-11-11',
          effective_formatted: '07h 30m',
          is_force_closed: true,
          is_adjusted: true,
          original_closed_at: '2026-11-11T17:30:00+01:00'
        }
      ]
    }
  };
};

const hoursVm = buildVm();
await hoursVm.openMyHours();
assert('openMyHours() abre el panel y carga la autoconsulta', hoursVm.showMyHoursModal === true);
assert('getMyHours() se invoca sin ningún identificador ajeno (Art. V.4)', Array.isArray(myHoursArgs) && myHoursArgs.length === 0);
assert('La autoconsulta publica el mes y su resumen', hoursVm.myHours.month === '2026-11' && hoursVm.myHoursSummary.effective_formatted === '15h 00m');
assert('myHoursMonthLabel() pinta el mes en castellano', hoursVm.myHoursMonthLabel === 'Noviembre de 2026', hoursVm.myHoursMonthLabel);
assert('Las sesiones propias se conservan sin duplicar', hoursVm.myHoursSessions.length === 1 && hoursVm.myHoursIsEmpty === false);
assert('hasShiftOriginalMarks() reconoce las marcas originales', hoursVm.hasShiftOriginalMarks(hoursVm.myHoursSessions[0]) === true);
hoursVm.closeMyHours();
assert('closeMyHours() cierra el panel sin borrar los datos', hoursVm.showMyHoursModal === false && hoursVm.myHoursSessions.length === 1);
api.technicianShift.getMyHours = originalMyHours;

// ─────────────────────────────────────────────────────────────────────────────
// 5. Acceso exclusivo del rol (RF-04.1)
// ─────────────────────────────────────────────────────────────────────────────
const originalLogin = api.auth.internalLogin;
api.auth.internalLogin = async () => ({
  user: { id: 3, name: 'Jordi Ruta', role: 'TECHNICIAN' },
  token: 'auth_token_tecnico'
});
const deniedVm = buildVm({ loginEmail: 'jordi@vendguard.internal', loginPassword: 'Password123!' });
await deniedVm.handleLogin();
assert('Un técnico SAT no entra en la vista de reposición', deniedVm.loginError.includes('exclusiva de Cargadores'));
assert('Un rol ajeno no deja sesión abierta', store.state.token === null);

api.auth.internalLogin = async () => ({
  user: { id: 12, name: 'Lucía Gómez', role: 'RESTOCKER', operator_code: 'REP-01' },
  token: 'auth_token_rep01'
});
requestedUrls = [];
const allowedVm = buildVm({ loginEmail: 'lucia@vendguard.local', loginPassword: 'Password123!' });
await allowedVm.handleLogin();
assert('El reponedor entra con su propia cuenta', allowedVm.loginError === '' && store.state.user.role === 'RESTOCKER');
assert('Tras el acceso se cargan ruta y jornada propias', requestedUrls.includes('/restocker/my-route') && requestedUrls.includes('/technician/shift/status'));
assert('El getter del store reconoce la sesión de reposición', store.isRestocker === true && store.isCoordinator === false && store.isTechnician === false);
store.clearSession();
api.auth.internalLogin = originalLogin;

// ─────────────────────────────────────────────────────────────────────────────
// 6. Enrutamiento del Shell Vue 3 (D1)
// ─────────────────────────────────────────────────────────────────────────────
assert('El Shell importa RestockerRouteView', appSource.includes("import { RestockerRouteView } from './components/RestockerRouteView.js'"));
assert('El Shell registra el componente', /components:\s*\{[\s\S]*RestockerRouteView/.test(appSource));
assert('La restauración de sesión enruta RESTOCKER a restocker', /role === 'RESTOCKER'[\s\S]{0,80}currentView = 'restocker'/.test(appSource));
assert('sessionView() reconoce la vista de reposición', /role === 'RESTOCKER'[\s\S]{0,80}return 'restocker'/.test(appSource));
assert('La plantilla monta RestockerRouteView para su vista', appSource.includes('currentView === \'restocker\'') && appSource.includes('<RestockerRouteView'));
assert('El store expone isRestocker', storeSource.includes('get isRestocker()'));
assert('El service worker precachea la vista del reponedor', readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8').includes('/assets/js/components/RestockerRouteView.js'));

// ─────────────────────────────────────────────────────────────────────────────
// 7. Interfaz y sistema de diseño (RF-04.1 a RF-04.3, RNF-03)
// ─────────────────────────────────────────────────────────────────────────────
assert('La cabecera publica nombre y código de operario', viewSource.includes('data-testid="restocker-header"') && viewSource.includes('data-testid="restocker-operator-code"'));
assert('Las insignias de zona existen', viewSource.includes('data-testid="zone-badge-primary"') && viewSource.includes('data-testid="zone-badge-backup"'));
assert('La barra de jornada tiene los cuatro controles', viewSource.includes('data-testid="btn-start-shift"') && viewSource.includes('data-testid="btn-pause-meal"') && viewSource.includes('data-testid="btn-resume-shift"') && viewSource.includes('data-testid="btn-close-shift"'));
const templateBlock = viewSource.slice(viewSource.indexOf('template:'));
assert(
  'La plantilla no contiene modal de relevo ni campos de traspaso',
  !/handover|target_technician_id|relevo de averías/i.test(templateBlock)
);
assert('La vista no consume la ruta SAT', !viewSource.includes('/technician/my-route') && !viewSource.includes('/coordinator/'));
assert('El estado base de ruta está presente', viewSource.includes('data-testid="restocker-route-base"') && viewSource.includes('Features 24, 26 y 27'));
assert('«Mis Horas» es un modal de solo lectura con sus insignias', viewSource.includes('data-testid="modal-my-hours"') && viewSource.includes('Cierre forzado') && viewSource.includes('Ajustado por Coordinación'));
assert('El contador diario y su acceso están en la barra', viewSource.includes('data-testid="restocker-today-summary"') && viewSource.includes('data-testid="btn-open-my-hours"'));

const styleBlocks = templateBlock;
assert(
  'La vista no contiene colores hexadecimales literales',
  !/#[0-9a-fA-F]{3,8}\b/.test(styleBlocks),
  (styleBlocks.match(/#[0-9a-fA-F]{3,8}\b/g) || []).slice(0, 5).join(', ')
);
assert(
  'La vista usa tipografía dual y paleta de tokens',
  styleBlocks.includes('var(--font-display)') &&
    styleBlocks.includes('var(--font-body)') &&
    styleBlocks.includes('var(--color-slate)') &&
    styleBlocks.includes('var(--color-ink-muted)')
);
assert(
  'Los bordes son conservadores: 4px interactivo y 8px tarjeta',
  styleBlocks.includes('var(--radius-interactive)') && styleBlocks.includes('var(--radius-card)')
);
assert(
  'El diseño es mobile-first sin anchos fijos de escritorio',
  styleBlocks.includes('max-width: 480px') && !styleBlocks.includes('min-width: 1024px')
);

// ─────────────────────────────────────────────────────────────────────────────
// 8. Máquinas de zona y planograma de solo lectura (T-PLN-13, D2, QA 2, Art. V.4)
// ─────────────────────────────────────────────────────────────────────────────
const originalRestockerPlanogram = api.restocker.getMachinePlanogram;
let loadedPlanogramMachineId = null;

const restockerPlanogramPayload = {
  data: {
    machine: {
      id: 7,
      code: 'VEND-0701',
      model: 'Jofemar Vision',
      machine_type: 'COMBO',
      location_name: 'Hospital del Mar',
      zone_name: 'Barcelona Litoral y Norte',
      sanitary_status: 'OK'
    },
    summary: {
      active_slots_count: 2,
      stockout_slots_count: 1,
      low_stock_slots_count: 1,
      ok_slots_count: 0,
      inactive_product_slots_count: 1,
      total_capacity: 20,
      total_estimated_qty: 3,
      fill_rate_percent: 15
    },
    slots: [
      {
        id: 101,
        slot_code: '11',
        product_id: 1,
        product_sku: 'SAND-JAMON',
        product_name: 'Sándwich mixto',
        product_category: 'PERISHABLE_FOOD',
        product_unit_type: 'UNIT',
        requires_cold_chain: true,
        product_is_active: true,
        sale_price_eur: 2.4,
        max_capacity: 10,
        current_estimated_qty: 0,
        min_threshold: 3,
        restock_needed_qty: 10,
        stock_status: 'STOCKOUT',
        stock_status_label: 'Agotado',
        is_active: true,
        fill_rate_percent: 0
      },
      {
        id: 102,
        slot_code: '12',
        product_id: 2,
        product_sku: 'SNACK-PATATAS',
        product_name: 'Patatas saladas',
        product_category: 'SNACK',
        product_unit_type: 'UNIT',
        requires_cold_chain: false,
        product_is_active: false,
        sale_price_eur: 1.2,
        max_capacity: 10,
        current_estimated_qty: 3,
        min_threshold: 3,
        restock_needed_qty: 7,
        stock_status: 'LOW_STOCK',
        stock_status_label: 'Bajo umbral',
        is_active: true,
        fill_rate_percent: 30
      }
    ]
  }
};

api.restocker.getMachinePlanogram = async (machineId) => {
  loadedPlanogramMachineId = machineId;
  return restockerPlanogramPayload;
};

assert('El cliente API expone el planograma territorial del reponedor', typeof api.restocker.getMachinePlanogram === 'function');
assert('El endpoint del cliente es el planograma /restocker/machines/{id}/planogram', apiSource.includes('/restocker/machines/${encodeURIComponent(machineId)}/planogram'));

await routeVm.openPlanogram(routeVm.machines[0]);
assert('Abrir el planograma fija la máquina y el modal', routeVm.showPlanogramModal === true && routeVm.selectedMachine.id === 7);
assert('El modal carga el planograma de la máquina elegida', loadedPlanogramMachineId === 7 && routeVm.planogram.machine.code === 'VEND-0701');
assert('El resumen publica carriles, agotados, bajo umbral y llenado', routeVm.planogramSummary.active_slots_count === 2 && routeVm.planogramSummary.stockout_slots_count === 1 && routeVm.planogramSummary.low_stock_slots_count === 1 && routeVm.planogramSummary.fill_rate_percent === 15);
assert('La parrilla conserva los dos carriles activos', routeVm.planogramSlots.length === 2 && routeVm.planogramSlots[0].slot_code === '11');
assert('Los carriles leídos no incluyen coste ni margen', !('unit_cost_eur' in routeVm.planogramSlots[0]) && !('unit_margin_eur' in routeVm.planogramSlots[0]));
assert('El semáforo traduce los estados del carril', routeVm.stockStatusLabel('STOCKOUT') === 'Agotado' && routeVm.stockStatusLabel('LOW_STOCK') === 'Bajo umbral' && routeVm.stockStatusLabel('OK') === 'Abastecido');
assert('categoryLabel() y unitTypeLabel() traducen la referencia', routeVm.categoryLabel('PERISHABLE_FOOD') === 'Perecedero' && routeVm.unitTypeLabel('UNIT') === 'Unidad');
assert('slotFillPercent() respeta el porcentaje del servidor', routeVm.slotFillPercent(routeVm.planogramSlots[1]) === 30);
assert('formatPrice() publica el PVP sin coste interno', routeVm.formatPrice(routeVm.planogramSlots[0].sale_price_eur) === '2.40 €');
assert('El modal identifica el producto dado de baja para sustituirlo', routeVm.planogramSlots[1].product_is_active === false);
assert('La insignia de baja usa el copy canónico', viewSource.includes('⚠️ Producto dado de baja (Sustituir)'));

routeVm.closePlanogram();
assert('Cerrar el modal limpia selección y planograma', routeVm.showPlanogramModal === false && routeVm.selectedMachine === null && routeVm.planogram === null);

api.restocker.getMachinePlanogram = async () => {
  throw new Error('MACHINE_OUTSIDE_RESTOCKER_ZONES');
};
await routeVm.openPlanogram(routeVm.machines[0]);
assert('Un 403 del servidor se declara sin cerrar el modal', routeVm.showPlanogramModal === true && routeVm.planogramError.includes('MACHINE_OUTSIDE_RESTOCKER_ZONES'));
assert('El fallo de lectura no deja cargando el modal', routeVm.planogramLoading === false);
routeVm.closePlanogram();
api.restocker.getMachinePlanogram = originalRestockerPlanogram;

const planogramModalSource = viewSource.slice(
  viewSource.indexOf('data-testid="modal-restocker-planogram"'),
  viewSource.indexOf('data-testid="modal-my-hours"')
);
assert('La vista declara el listado de máquinas de zona', viewSource.includes('data-testid="restocker-machines"') && viewSource.includes('data-testid="restocker-machine-card"'));
assert('La tarjeta muestra resumen de agotados y bajo umbral', viewSource.includes('machine.stockout_slots_count') && viewSource.includes('machine.low_stock_slots_count'));
assert(
  'La tarjeta publica el estado sanitario y traduce sus valores',
  viewSource.includes('data-testid="restocker-machine-sanitary-status"')
    && viewSource.includes('machine.sanitary_status')
    && routeVm.sanitaryStatusLabel('OK') === 'Operativa y vigente'
    && routeVm.sanitaryStatusLabel('ATTENTION_REQUIRED') === 'Requiere atención'
    && routeVm.sanitaryStatusLabel('QUARANTINE') === 'Cuarentena sanitaria'
    && routeVm.sanitaryStatusLabel(null) === 'Sin estado sanitario'
);
assert(
  'La insignia sanitaria usa exclusivamente tokens semánticos',
  routeVm.sanitaryStatusStyle('OK').backgroundColor === 'var(--color-success-bg)'
    && routeVm.sanitaryStatusStyle('ATTENTION_REQUIRED').color === 'var(--color-warning-text)'
    && routeVm.sanitaryStatusStyle('EXPIRED').backgroundColor === 'var(--color-error-bg)'
    && routeVm.sanitaryStatusStyle('QUARANTINE').color === 'var(--color-error-text)'
    && routeVm.sanitaryStatusStyle('SEASONAL_PAUSE').backgroundColor === 'var(--color-surface-1)'
);
assert('La tarjeta abre el planograma con su acción', viewSource.includes('data-testid="btn-open-planogram"') && viewSource.includes('@click="openPlanogram(machine)"'));
assert('El modal de planograma y sus carriles existen', viewSource.includes('data-testid="modal-restocker-planogram"') && viewSource.includes('data-testid="restocker-planogram-slot"'));
assert('El modal se declara de solo lectura', planogramModalSource.includes('Solo lectura') && !/savePlanogram|deactivatePlanogram|applyPlanogram|clonePlanogram/.test(planogramModalSource));
assert('La vista no declara campos económicos internos', !viewSource.includes('unit_cost_eur') && !viewSource.includes('unit_margin_eur') && !viewSource.includes('margin_eur'));
assert('La vista usa el endpoint segregado del reponedor', viewSource.includes('api.restocker.getMachinePlanogram'));

// ─────────────────────────────────────────────────────────────────────────────
// 9. Recepción 1-Tap, liquidación, stock FEFO y comprobante C4 (T-PLD-12)
// ─────────────────────────────────────────────────────────────────────────────
const dispatchedLoadingDetail = {
  order: {
    id: 501,
    order_code: 'PLO-2026-0501',
    status: 'DISPATCHED',
    status_label: 'Despachada',
    demand_mode_label: 'Solo alertas',
    safety_buffer_pct: 10,
    cold_chamber_exit_temp_celsius: 3.5,
    restocker_name: 'Lucía Gómez',
    reconciled_at: null,
    reconciliation_notes: null
  },
  items: [
    {
      product_id: 1,
      product_sku: 'SAND-MIXTO-01',
      product_name: 'Sándwich mixto',
      requires_cold_chain: true,
      reason_label: 'Rotura de stock',
      requested_qty: 5,
      dispatched_qty: 5,
      batch_code: 'LOTE-SW-01',
      expiration_date: '2026-11-16',
      is_active: true
    },
    {
      product_id: 2,
      product_sku: 'AGUA-50-01',
      product_name: 'Agua mineral 50 cl',
      requires_cold_chain: false,
      reason_label: 'Bajo umbral',
      requested_qty: 3,
      dispatched_qty: 3,
      batch_code: 'SIN-LOTE',
      expiration_date: null,
      is_active: true
    },
    {
      product_id: 99,
      product_sku: 'RETIRADO-01',
      product_name: 'Línea retirada',
      requires_cold_chain: false,
      reason_label: 'Manual',
      requested_qty: 1,
      dispatched_qty: 0,
      batch_code: null,
      expiration_date: null,
      is_active: false
    }
  ]
};

const confirmedLoadingDetail = {
  order: {
    ...dispatchedLoadingDetail.order,
    status: 'CONFIRMED_BY_RESTOCKER',
    status_label: 'Recibida en Furgoneta',
    confirmed_at: '2026-11-13T08:10:00+00:00'
  },
  items: [dispatchedLoadingDetail.items[0]]
};
confirmedLoadingDetail.items[0] = {
  ...confirmedLoadingDetail.items[0],
  suggested_restocked_in_machines_qty: 2,
  suggested_withdrawn_spoilage_qty: 1
};

const reconciledLoadingDetail = {
  order: {
    ...confirmedLoadingDetail.order,
    status: 'RECONCILED_AT_CLOSE',
    status_label: 'Liquidada al Cierre',
    reconciled_at: '2026-11-13T18:20:00+00:00',
    reconciliation_notes: 'Diferencia detectada en el recuento físico del cierre.'
  },
  items: [
    {
      ...confirmedLoadingDetail.items[0],
      van_stock_before_reconcile: 5,
      restocked_in_machines_qty: 3,
      withdrawn_spoilage_qty: 1,
      expected_return_qty: 1,
      counted_return_qty: 0,
      discrepancy_qty: -1,
      has_reconcile_data: true,
      has_discrepancy: true
    }
  ]
};

const vanStockPayload = {
  data: {
    kpis: {
      total_lots: 2,
      total_units: 7,
      usable_units: 5,
      expired_lots: 1,
      expiring_soon_lots: 1,
      affected_restockers_count: 1
    },
    has_expired_lots: true,
    stocks: [
      {
        id: 91,
        restocker_id: 12,
        product_id: 1,
        product_sku: 'SAND-MIXTO-01',
        product_name: 'Sándwich mixto',
        product_category: 'PERISHABLE_FOOD',
        product_unit_type: 'UNIT',
        requires_cold_chain: true,
        batch_code: 'LOTE-SW-01',
        expiration_date: '2026-11-16',
        quantity_available: 5,
        usable_quantity: 5,
        expiry_status: 'EXPIRING_SOON',
        expiry_status_label: 'Caducidad próxima ≤ 48 h',
        expiry_status_icon: '🟡',
        is_expired: false
      },
      {
        id: 92,
        restocker_id: 12,
        product_id: 3,
        product_sku: 'YOGUR-01',
        product_name: 'Yogur natural',
        product_category: 'PERISHABLE_FOOD',
        product_unit_type: 'UNIT',
        requires_cold_chain: true,
        batch_code: 'LOTE-VIEJO',
        expiration_date: '2026-11-10',
        quantity_available: 2,
        usable_quantity: 0,
        expiry_status: 'EXPIRED',
        expiry_status_label: 'Caducado',
        expiry_status_icon: '🔴',
        is_expired: true
      }
    ]
  }
};

const originalBootstrapGet = api.get;
api.get = async (url) => {
  if (url !== '/restocker/my-route') throw new Error('URL inesperada: ' + url);
  return {
    data: {
      ...bootstrapPayload.data,
      active_loading_order: dispatchedLoadingDetail,
      last_reconciled_order: reconciledLoadingDetail,
      van_product_stocks: vanStockPayload.data.stocks,
      van_stock_kpis: vanStockPayload.data.kpis,
      has_expired_van_lots: true
    }
  };
};
const loadingVm = buildVm();
await loadingVm.loadMyRoute();
api.get = originalBootstrapGet;

assert('El bootstrap móvil publica la orden de carga activa', loadingVm.activeLoadingOrderData.id === 501 && loadingVm.activeLoadingOrderStatus === 'DISPATCHED');
assert('El bootstrap móvil publica el stock FEFO y sus KPIs', loadingVm.vanProductStocks.length === 2 && loadingVm.vanStockKpis.total_lots === 2);
assert('El bootstrap móvil publica la última orden liquidada (C4)', loadingVm.lastReconciledOrderData.order_code === 'PLO-2026-0501' && loadingVm.lastReconciledOrderItems.length === 1);
assert('El bootstrap móvil alerta de lotes caducados', loadingVm.hasExpiredVanLots === true);
assert('activeLoadingOrderVisibleItems() omite las líneas retiradas', loadingVm.activeLoadingOrderVisibleItems.length === 2);
assert('vanStockLots() ordena FEFO con el lote caducado primero', loadingVm.vanStockLots[0].batch_code === 'LOTE-VIEJO' && loadingVm.vanStockLots[1].batch_code === 'LOTE-SW-01');
assert(
  'El semáforo FEFO usa tokens y etiquetas canónicas',
  loadingVm.expiryStatusLabel('EXPIRED') === 'Lote caducado'
    && loadingVm.expiryStatusIcon('EXPIRING_SOON') === '🟡'
    && loadingVm.expiryStatusStyle('EXPIRED').backgroundColor === 'var(--color-error-bg)'
);

const loadingConfirmCalls = [];
const originalConfirmLoadingOrder = api.restocker.confirmLoadingOrder;
const originalRestockerVanStocks = api.restocker.getVanStocks;
api.restocker.confirmLoadingOrder = async (orderId) => {
  loadingConfirmCalls.push({ method: 'confirmLoadingOrder', orderId });
  return { data: confirmedLoadingDetail };
};
api.restocker.getVanStocks = async () => {
  loadingConfirmCalls.push({ method: 'getVanStocks' });
  return vanStockPayload;
};

await loadingVm.confirmActiveLoadingOrder();
assert('La recepción 1-Tap confirma la orden propia', loadingConfirmCalls.some((call) => call.method === 'confirmLoadingOrder' && call.orderId === 501));
assert('Tras confirmar, la orden pasa a recibida en furgoneta', loadingVm.activeLoadingOrderStatus === 'CONFIRMED_BY_RESTOCKER' && loadingVm.loadingActionMessage.includes('Recepción confirmada'));
assert('La confirmación refresca el stock por lote', loadingConfirmCalls.some((call) => call.method === 'getVanStocks') && loadingVm.vanProductStocks.length === 2);

loadingVm.activeLoadingOrder = confirmedLoadingDetail;
await loadingVm.openReconcileLoadingModal();
assert('Abrir la liquidación carga el saldo real por lote', loadingVm.showReconcileLoadingModal === true && loadingVm.reconcileLoadingForm.items.length === 1);
const reconciliationLine = loadingVm.reconcileLoadingForm.items[0];
assert('El saldo del lote coincide con el stock de la furgoneta', reconciliationLine.van_stock_before_reconcile === 5);
assert(
  'La liquidación C4 precarga abastecidas y mermas desde las visitas, sin costes',
  reconciliationLine.restocked_in_machines_qty === 2
    && reconciliationLine.withdrawn_spoilage_qty === 1
    && reconciliationLine.counted_return_qty === 2
    && !('unit_cost_eur' in reconciliationLine)
);
reconciliationLine.restocked_in_machines_qty = 3;
reconciliationLine.withdrawn_spoilage_qty = 1;
reconciliationLine.counted_return_qty = 0;
assert('El cálculo en vivo marca el descuadre −1', loadingVm.reconcileLoadingExpected(reconciliationLine) === 1 && loadingVm.reconcileLoadingDiscrepancy(reconciliationLine) === -1);
assert('Un descuadre sin justificación no se liquida', loadingVm.validateLoadingReconciliation() === false && loadingVm.reconcileLoadingError.includes('20'));
loadingVm.reconcileLoadingForm.notes = 'Diferencia detectada en el recuento físico del cierre.';
assert('Una justificación de 20+ caracteres desbloquea el cuadre', loadingVm.validateLoadingReconciliation() === true);

const reconcileLoadingCalls = [];
const originalReconcileLoadingOrder = api.restocker.reconcileLoadingOrder;
api.restocker.reconcileLoadingOrder = async (orderId, payload) => {
  reconcileLoadingCalls.push({ orderId, payload });
  return { data: reconciledLoadingDetail };
};
await loadingVm.submitLoadingReconciliation();
assert('La liquidación envía el cuadre y la justificación', reconcileLoadingCalls.length === 1 && reconcileLoadingCalls[0].orderId === 501 && reconcileLoadingCalls[0].payload.items[0].counted_return_qty === 0 && reconcileLoadingCalls[0].payload.reconciliation_notes.length >= 20);
assert('Tras liquidar, la orden activa desaparece y queda el comprobante C4', loadingVm.activeLoadingOrder === null && loadingVm.lastReconciledOrderData.status === 'RECONCILED_AT_CLOSE');
assert('La liquidación refresca el stock de la furgoneta', loadingVm.loadingActionMessage.includes('Cierre liquidado') && loadingVm.vanProductStocks.length === 2);

api.restocker.confirmLoadingOrder = originalConfirmLoadingOrder;
api.restocker.getVanStocks = originalRestockerVanStocks;
api.restocker.reconcileLoadingOrder = originalReconcileLoadingOrder;

assert('El API móvil expone stock, confirmación y liquidación', typeof api.restocker.getVanStocks === 'function' && typeof api.restocker.confirmLoadingOrder === 'function' && typeof api.restocker.reconcileLoadingOrder === 'function');
assert('Los endpoints móviles son los del contrato', apiSource.includes('/restocker/van-stocks') && apiSource.includes('/restocker/product-loading-orders/${encodeURIComponent(orderId)}/confirm') && apiSource.includes('/restocker/product-loading-orders/${encodeURIComponent(orderId)}/reconcile'));
assert('La vista publica el banner, la tarjeta de liquidación y el stock FEFO', viewSource.includes('data-testid="restocker-loading-confirm-banner"') && viewSource.includes('data-testid="restocker-loading-reconcile-card"') && viewSource.includes('data-testid="restocker-van-stock"'));
assert('El botón de recepción usa el copy canónico 1-Tap', viewSource.includes('Confirmar Recepción en Furgoneta (1 Toque)'));
assert('El botón de liquidación usa el copy canónico de cierre', viewSource.includes('Liquidar Cierre de Furgoneta'));
assert('El comprobante C4 es de solo lectura', viewSource.includes('data-testid="restocker-last-reconciled-order"') && viewSource.includes('Solo lectura'));
assert('La vista declara las tres entradas del cuadre por línea', viewSource.includes('data-testid="reconcile-restocked"') && viewSource.includes('data-testid="reconcile-spoilage"') && viewSource.includes('data-testid="reconcile-counted"'));
assert('La vista móvil no declara campos económicos internos', !viewSource.includes('unit_cost_eur') && !viewSource.includes('unit_margin_eur'));

// ─────────────────────────────────────────────────────────────────────────────
// 10. Visita in situ, FEFO, evidencia y cola offline (T-RVF-12, D4, C3, Art. II)
// ─────────────────────────────────────────────────────────────────────────────
const queueSource = readFileSync(
  new URL('../../public/assets/js/utils/RestockVisitOfflineQueue.js', import.meta.url),
  'utf8'
);
const visitModalSource = viewSource.slice(
  viewSource.indexOf('data-testid="modal-restock-visit"'),
  viewSource.indexOf('data-testid="modal-reconcile-loading"')
);
const originalVisitPlanogram = api.restocker.getMachinePlanogram;
const originalCreateRestockVisit = api.restocker.createRestockVisit;
const originalUploadVisitPhoto = api.restocker.uploadVisitPhoto;
const originalDumpVisitConflict = api.restocker.dumpVisitConflict;
const originalGetVisits = api.restocker.getVisits;

const visitPlanogramPayload = {
  data: {
    machine: {
      id: 7,
      code: 'VEND-0701',
      model: 'Jofemar Vision',
      machine_type: 'COMBO',
      location_name: 'Hospital del Mar',
      zone_name: 'Barcelona Litoral y Norte',
      sanitary_status: 'OK'
    },
    summary: { active_slots_count: 3, stockout_slots_count: 1, low_stock_slots_count: 1, ok_slots_count: 1, total_capacity: 27, total_estimated_qty: 6, fill_rate_percent: 22.22 },
    slots: [
      {
        id: 201,
        slot_code: '21',
        product_id: 1,
        product_sku: 'SAND-MIXTO-01',
        product_name: 'Sándwich mixto',
        product_category: 'PERISHABLE_FOOD',
        product_unit_type: 'UNIT',
        max_capacity: 10,
        current_estimated_qty: 2,
        min_threshold: 2,
        is_active: true,
        slot_expiry_status: 'EXPIRED',
        current_batch_code: 'LOTE-VIEJO',
        current_expiration_date: '2026-11-10',
        fefo_withdrawal_required: true,
        available_van_batches: [
          { product_id: 1, batch_code: 'LOTE-NEW1', expiration_date: '2026-11-20', quantity_available: 8 }
        ],
        fefo_recommended_batch_code: 'LOTE-NEW1'
      },
      {
        id: 202,
        slot_code: '22',
        product_id: 2,
        product_sku: 'YOGUR-01',
        product_name: 'Yogur natural',
        product_category: 'PERISHABLE_FOOD',
        product_unit_type: 'UNIT',
        max_capacity: 12,
        current_estimated_qty: 4,
        min_threshold: 3,
        is_active: true,
        slot_expiry_status: 'EXPIRING_SOON',
        current_batch_code: 'LOTE-OLD2',
        current_expiration_date: '2026-11-15',
        fefo_withdrawal_required: true,
        available_van_batches: [
          { product_id: 2, batch_code: 'LOTE-NEW2', expiration_date: '2026-11-22', quantity_available: 6 }
        ],
        fefo_recommended_batch_code: 'LOTE-NEW2'
      },
      {
        id: 203,
        slot_code: '23',
        product_id: 3,
        product_sku: 'AGUA-50-01',
        product_name: 'Agua mineral 50 cl',
        product_category: 'COLD_DRINK',
        product_unit_type: 'UNIT',
        max_capacity: 5,
        current_estimated_qty: 0,
        min_threshold: 1,
        is_active: true,
        slot_expiry_status: 'OK',
        current_batch_code: null,
        current_expiration_date: null,
        fefo_withdrawal_required: false,
        available_van_batches: [
          { product_id: 3, batch_code: 'LOTE-AGUA', expiration_date: '2027-01-10', quantity_available: 3 }
        ],
        fefo_recommended_batch_code: 'LOTE-AGUA'
      }
    ]
  }
};

const visitMachine = {
  id: 7,
  code: 'VEND-0701',
  model: 'Jofemar Vision',
  machine_type: 'COMBO',
  location_name: 'Hospital del Mar',
  zone_name: 'Barcelona Litoral y Norte',
  sanitary_status: 'OK',
  can_restock: true,
  active_slots_count: 3,
  stockout_slots_count: 1,
  low_stock_slots_count: 1,
  ok_slots_count: 1,
  total_capacity: 27,
  total_estimated_qty: 6,
  fill_rate_percent: 22.22,
  expired_slots_count: 1,
  expiring_soon_slots_count: 1
};
const blockedVisitMachine = {
  id: 8,
  code: 'VEND-0801',
  model: 'Necta Korinto',
  machine_type: 'COLD_DRINKS',
  location_name: 'Torre Glòries',
  sanitary_status: 'QUARANTINE',
  can_restock: false,
  expired_slots_count: 0,
  expiring_soon_slots_count: 0
};
const visitStocks = [
  { product_id: 1, batch_code: 'LOTE-NEW1', quantity_available: 8, usable_quantity: 8 },
  { product_id: 2, batch_code: 'LOTE-NEW2', quantity_available: 6, usable_quantity: 6 },
  { product_id: 3, batch_code: 'LOTE-AGUA', quantity_available: 3, usable_quantity: 3 }
];

let loadedVisitMachineId = null;
api.restocker.getMachinePlanogram = async (machineId) => {
  loadedVisitMachineId = machineId;
  return visitPlanogramPayload;
};
const visitVm = buildVm({
  restocker: bootstrapPayload.data.restocker,
  machines: JSON.parse(JSON.stringify([visitMachine, blockedVisitMachine])),
  vanProductStocks: JSON.parse(JSON.stringify(visitStocks)),
  visitQueue: new RestockVisitOfflineQueue({ indexedDB: createFakeIndexedDb() })
});

assert('machineCanRestock() permite una máquina operativa', visitVm.machineCanRestock(visitVm.machines[0]) === true);
assert('machineCanRestock() bloquea cuarentena y fuera de servicio', visitVm.machineCanRestock(visitVm.machines[1]) === false);
assert('machineRestockBlockReason() explica el bloqueo sanitario', visitVm.machineRestockBlockReason(visitVm.machines[1]).includes('cuarentena'));
assert('machineStockStatus() resume el estado de la tarjeta', visitVm.machineStockStatus(visitVm.machines[0]) === 'STOCKOUT' && visitVm.machineStockStatus({ stockout_slots_count: 0, low_stock_slots_count: 0 }) === 'OK');
assert('La vista pinta las insignias FEFO de la tarjeta', viewSource.includes('data-testid="restocker-machine-expired-slots"') && viewSource.includes('data-testid="restocker-machine-expiring-slots"'));
assert('El botón de visita se deshabilita con aviso explícito', viewSource.includes('data-testid="btn-start-visit"') && viewSource.includes(':disabled="!machineCanRestock(machine)"') && viewSource.includes('data-testid="restocker-machine-block-reason"'));

await visitVm.openVisitAssistant(visitVm.machines[0]);
assert('La visita carga el planograma de la máquina elegida', loadedVisitMachineId === 7 && visitVm.showVisitModal === true && visitVm.visitPlanogram.slots.length === 3);
assert('La visita inicializa un ítem por carril y un client_mutation_id', visitVm.visitForm.items.length === 3 && visitVm.visitForm.client_mutation_id.length > 8 && visitVm.visitForm.started_at.length > 10);
assert('La visita preselecciona el lote FEFO recomendado', visitVm.visitForm.items[0].loaded_batch_code === 'LOTE-NEW1' && visitVm.visitForm.items[1].loaded_batch_code === 'LOTE-NEW2');
assert('El checklist inicial adapta los cinco bloques al tipo de máquina', visitVm.visitHygieneItems.length === 5 && visitVm.visitHygieneMissing.length === 4 && visitVm.visitForm.hygiene_checklist.cabin_spills_checked === null);

visitVm.visitForm.cabin_temp_celsius = 4.1;
assert('El techo térmico de COMBO es 4 °C', visitVm.visitThermalCeiling === 4);
assert('Superar el techo activa ABORTED_THERMAL_BREACH', visitVm.visitThermalBreach === true && visitVm.visitMode === 'ABORTED_THERMAL_BREACH' && visitVm.visitInputsLocked === true);
visitVm.visitForm.cabin_temp_celsius = 4;
assert('El umbral exacto de 4 °C no aborta la visita', visitVm.visitThermalBreach === false && visitVm.visitMode === 'COMPLETED');
visitVm.visitMachine.machine_type = 'COLD_DRINKS';
visitVm.visitForm.cabin_temp_celsius = 8.1;
assert('El techo térmico de frío es 8 °C', visitVm.visitThermalCeiling === 8 && visitVm.visitThermalBreach === true);
visitVm.visitForm.cabin_temp_celsius = 8;
assert('El umbral exacto de 8 °C no aborta la visita', visitVm.visitThermalBreach === false);
visitVm.visitMachine.machine_type = 'HOT_DRINKS';
visitVm.visitForm.cabin_temp_celsius = 45;
assert('Las máquinas sin techo térmico no abortan por temperatura', visitVm.visitThermalCeiling === null && visitVm.visitThermalBreach === false);
visitVm.visitMachine.machine_type = 'COMBO';
visitVm.visitForm.cabin_temp_celsius = 3.5;

const expiredVisitItem = visitVm.visitForm.items[0];
assert('Un carril caducado exige retirada FEFO antes de cargar', visitVm.visitItemMustWithdrawBeforeLoading(expiredVisitItem) === true && visitVm.visitItemMaxAddable(expiredVisitItem) === 0);
visitVm.fillVisitItem(expiredVisitItem);
assert('No se puede llenar un carril FEFO sin retirar el remanente', expiredVisitItem.qty_added === 0 && visitVm.visitError.includes('FEFO'));
visitVm.withdrawVisitRemainder(expiredVisitItem);
assert('La retirada FEFO descuenta todo el remanente caducado', expiredVisitItem.qty_withdrawn_expired === 2 && visitVm.visitItemMustWithdrawBeforeLoading(expiredVisitItem) === false);
visitVm.fillVisitItem(expiredVisitItem);
assert('Llenar al máximo respeta hueco y stock de furgoneta', expiredVisitItem.qty_added === 8 && visitVm.visitItemQtyAfter(expiredVisitItem) === 8);

const expiringVisitItem = visitVm.visitForm.items[1];
visitVm.fillVisitItem(expiringVisitItem);
assert('Un carril próximo a caducar también exige retirada previa', expiringVisitItem.qty_added === 0 && visitVm.visitItemMustWithdrawBeforeLoading(expiringVisitItem) === true);
visitVm.withdrawVisitRemainder(expiringVisitItem);
visitVm.fillVisitItem(expiringVisitItem);
assert('El carril próximo a caducar se llena tras la retirada FEFO', expiringVisitItem.qty_withdrawn_expired === 4 && expiringVisitItem.qty_added === 6);
const healthyVisitItem = visitVm.visitForm.items[2];
visitVm.fillVisitItem(healthyVisitItem);
assert('El carril sano se llena con el stock FEFO disponible', healthyVisitItem.qty_added === 3 && visitVm.visitTotalAdded === 17 && visitVm.visitTotalWithdrawn === 6);

visitVm.markHygieneComplete();
assert('Marcar el checklist cubre las cuatro comprobaciones de COMBO', visitVm.visitHygieneMissing.length === 0);
const visitPayload = visitVm.buildVisitPayload();
assert('El payload de visita envía movimientos, lote y checklist sin costes', visitPayload.items.length === 3 && visitPayload.items[0].loaded_batch_code === 'LOTE-NEW1' && visitPayload.hygiene_checklist.coffee_grounds_emptied === true && !('unit_cost_eur' in visitPayload) && !('cost' in visitPayload));

visitVm.visitMachine.machine_type = 'COLD_DRINKS';
visitVm.markHygieneComplete();
assert('El checklist de frío queda en dos comprobaciones y anula las de café', visitVm.visitHygieneItems.filter((entry) => entry.required).length === 2 && visitVm.visitForm.hygiene_checklist.coffee_grounds_emptied === null && visitVm.visitForm.hygiene_checklist.cabin_spills_checked === true);
visitVm.visitMachine.machine_type = 'COMBO';
visitVm.markHygieneComplete();

visitVm.handleVisitPhoto({ target: { files: [{ name: 'grande.jpg', size: 5 * 1024 * 1024 + 1, type: 'image/jpeg' }], value: 'x' } });
assert('La evidencia rechaza fotografías de más de 5 MB', visitVm.visitPhotoFile === null && visitVm.visitError.includes('5 MB'));
visitVm.handleVisitPhoto({ target: { files: [{ name: 'evidencia.pdf', size: 1000, type: 'application/pdf' }], value: 'x' } });
assert('La evidencia rechaza formatos que no son imagen', visitVm.visitPhotoFile === null && visitVm.visitError.includes('JPG'));
const evidenceFile = { name: 'evidencia.jpg', size: 1024, type: 'image/jpeg' };
visitVm.handleVisitPhoto({ target: { files: [evidenceFile], value: '' } });
assert('La evidencia válida queda adjunta a la visita', visitVm.visitPhotoFile === evidenceFile && visitVm.visitPhotoName === 'evidencia.jpg' && visitVm.visitPhotoReady === true);
assert('Una visita con retiradas exige foto y notas de 20 caracteres', visitVm.validateVisit() === false && visitVm.visitError.includes('justificación'));
visitVm.visitForm.internal_notes = 'Retirada FEFO justificada por caducidad y control sanitario.';
assert('La visita completa supera la validación', visitVm.validateVisit() === true);
assert('La validación no confunde el techo térmico exacto con un aborto', visitVm.visitMode === 'COMPLETED');

visitVm.visitForm.cabin_temp_celsius = 4.1;
assert('El aborto térmico bloquea los movimientos ya declarados', visitVm.validateVisit() === false && visitVm.visitError.includes('no puede cargar'));
for (const item of visitVm.visitForm.items) {
  item.qty_withdrawn_expired = 0;
  item.qty_withdrawn_damaged = 0;
  item.qty_added = 0;
}
assert('El aborto térmico exige precinto de seguridad', visitVm.validateVisit() === false && visitVm.visitError.includes('precinto'));
visitVm.visitForm.precautionary_seal_applied = true;
assert('El aborto con precinto, foto y notas es válido', visitVm.validateVisit() === true);
const abortPayload = visitVm.buildVisitPayload();
assert('El aborto térmico envía estado y cero movimientos', abortPayload.status === 'ABORTED_THERMAL_BREACH' && abortPayload.items.length === 0);

const onlineGet = api.get;
const onlineCreate = api.restocker.createRestockVisit;
const onlineUpload = api.restocker.uploadVisitPhoto;
const uploadCalls = [];
const onlineCreateCalls = [];
api.get = async (url) => {
  if (url === '/restocker/my-route') return bootstrapPayload;
  throw new Error('URL inesperada: ' + url);
};
api.restocker.uploadVisitPhoto = async (photo, name) => {
  uploadCalls.push({ photo, name });
  return { photo_evidence_url: '/uploads/visits/evidencia.jpg' };
};
api.restocker.createRestockVisit = async (machineId, payload) => {
  onlineCreateCalls.push({ machineId, payload });
  return { id: 88, visit_code: 'MRV-2026-0088', status: 'COMPLETED', machine_code: 'VEND-0701' };
};
const onlineVisitVm = buildVm({
  restocker: bootstrapPayload.data.restocker,
  machines: JSON.parse(JSON.stringify([visitMachine])),
  vanProductStocks: JSON.parse(JSON.stringify(visitStocks)),
  visitQueue: new RestockVisitOfflineQueue({ indexedDB: createFakeIndexedDb() })
});
await onlineVisitVm.openVisitAssistant(onlineVisitVm.machines[0]);
onlineVisitVm.visitForm.cabin_temp_celsius = 3.5;
onlineVisitVm.markHygieneComplete();
onlineVisitVm.withdrawVisitRemainder(onlineVisitVm.visitForm.items[0]);
onlineVisitVm.visitForm.internal_notes = 'Evidencia de retirada FEFO para el cierre.';
onlineVisitVm.visitPhotoFile = { name: 'evidencia.jpg', size: 1024, type: 'image/jpeg' };
onlineVisitVm.visitPhotoName = 'evidencia.jpg';
await onlineVisitVm.submitVisit();
assert('La visita online sube la foto antes del alta idempotente', uploadCalls.length === 1 && uploadCalls[0].name === 'evidencia.jpg' && onlineCreateCalls.length === 1 && onlineCreateCalls[0].machineId === 7);
assert('La visita online envía la URL de evidencia y cierra el asistente', onlineCreateCalls[0].payload.photo_evidence_url === '/uploads/visits/evidencia.jpg' && onlineVisitVm.showVisitModal === false && onlineVisitVm.loadingActionMessage.includes('Visita registrada'));
api.get = onlineGet;
api.restocker.createRestockVisit = onlineCreate;
api.restocker.uploadVisitPhoto = onlineUpload;

visitVm.visitForm.cabin_temp_celsius = 3.5;
const offlineVisitVm = buildVm({
  restocker: bootstrapPayload.data.restocker,
  machines: JSON.parse(JSON.stringify([visitMachine])),
  vanProductStocks: JSON.parse(JSON.stringify(visitStocks)),
  visitQueue: new RestockVisitOfflineQueue({ indexedDB: createFakeIndexedDb() })
});
await offlineVisitVm.openVisitAssistant(offlineVisitVm.machines[0]);
offlineVisitVm.visitForm.cabin_temp_celsius = 3.5;
offlineVisitVm.markHygieneComplete();
offlineVisitVm.withdrawVisitRemainder(offlineVisitVm.visitForm.items[0]);
offlineVisitVm.fillVisitItem(offlineVisitVm.visitForm.items[0]);
const offlinePayload = offlineVisitVm.buildVisitPayload();
await offlineVisitVm.queueOfflineVisit(offlinePayload, null);
assert('La visita se guarda en la cola offline y cierra el asistente', offlineVisitVm.showVisitModal === false && offlineVisitVm.pendingVisitMutations.length === 1);
assert('La cola offline aísla la visita por restocker_id', (await offlineVisitVm.visitQueue.list(12)).length === 1 && (await offlineVisitVm.visitQueue.list(13)).length === 0);
assert('La visita offline descuenta preventivamente el stock de furgoneta', offlineVisitVm.vanProductStocks.find((row) => row.batch_code === 'LOTE-NEW1').quantity_available === 0);
assert('La visita offline refleja el carril y el historial local', offlineVisitVm.machines[0].expired_slots_count === 0 && offlineVisitVm.recentVisits[0].is_offline_pending === true);

const queue = offlineVisitVm.visitQueue;
await queue.clear(12);
const queueRecord = (id, sequence) => ({
  client_mutation_id: id,
  restocker_id: 12,
  machine_id: 7,
  payload: { client_mutation_id: id, status: 'COMPLETED', items: [] },
  created_at: '2026-11-14T08:00:00+00:00',
  sequence_order: sequence
});
await queue.enqueue(queueRecord('visit-2', 2));
await queue.enqueue(queueRecord('visit-1', 1));
await queue.enqueue({ ...queueRecord('visit-other', 3), restocker_id: 13 });
assert('La cola FIFO ordena por secuencia y no mezcla reponedores', (await queue.list(12)).map((item) => item.client_mutation_id).join(',') === 'visit-1,visit-2' && (await queue.list(13)).length === 1);
await queue.markConflict('visit-1', 'OFFLINE_VISIT_CONFLICT');
assert('Marcar conflicto conserva la visita con su motivo', (await queue.list(12, ['CONFLICT']))[0].conflict_reason === 'OFFLINE_VISIT_CONFLICT');
await queue.remove('visit-2');
assert('Eliminar de la cola no toca otras visitas', (await queue.list(12)).length === 1);
await queue.clear(12);
assert('Vaciar la cola respeta el aislamiento del reponedor', (await queue.list(12)).length === 0 && (await queue.list(13)).length === 1);
const memoryQueue = new RestockVisitOfflineQueue({ indexedDB: null });
await memoryQueue.enqueue(queueRecord('memory-visit', 1));
assert('La cola degrada a memoria en Node sin IndexedDB', memoryQueue.isMemoryBacked() === true && (await memoryQueue.list(12)).length === 1);

const syncQueue = new RestockVisitOfflineQueue({ indexedDB: createFakeIndexedDb() });
await syncQueue.enqueue(queueRecord('sync-1', 1));
await syncQueue.enqueue(queueRecord('sync-2', 2));
const syncVm = buildVm({ restocker: bootstrapPayload.data.restocker, visitQueue: syncQueue });
const syncCalls = [];
api.restocker.createRestockVisit = async (machineId, payload) => {
  syncCalls.push({ machineId, payload });
  if (payload.client_mutation_id === 'sync-2') {
    const error = new Error('OFFLINE_VISIT_CONFLICT');
    error.status = 409;
    error.code = 'OFFLINE_VISIT_CONFLICT';
    throw error;
  }
  return { id: 77, visit_code: 'MRV-2026-0077', status: 'COMPLETED' };
};
const syncGet = api.get;
api.get = async (url) => {
  if (url === '/restocker/my-route') return bootstrapPayload;
  throw new Error('URL inesperada: ' + url);
};
await syncVm.syncPendingVisits();
assert('La sincronización FIFO respeta el orden de la cola', syncCalls.length === 2 && syncCalls[0].payload.client_mutation_id === 'sync-1' && syncCalls[1].payload.client_mutation_id === 'sync-2');
assert('Un 409 se conserva como conflicto y no se reintenta en bucle', (await syncQueue.list(12)).length === 1 && (await syncQueue.list(12, ['CONFLICT']))[0].client_mutation_id === 'sync-2');
assert('La sincronización informa de visitas y conflictos', syncVm.visitSyncMessage.includes('1 visita(s) sincronizada(s)') && syncVm.visitSyncMessage.includes('1 conflicto(s)'));

const dumpCalls = [];
api.restocker.dumpVisitConflict = async (payload) => {
  dumpCalls.push(payload);
  return { status: 'ARCHIVED' };
};
const conflictedVisit = (await syncQueue.list(12, ['CONFLICT']))[0];
await syncVm.archiveVisitConflict(conflictedVisit);
assert('Archivar el conflicto llama a auditoría con su mutación', dumpCalls.length === 1 && dumpCalls[0].machine_id === 7 && dumpCalls[0].client_mutation_id === 'sync-2');
assert('Archivar el conflicto vacía la cola local', (await syncQueue.list(12)).length === 0 && syncVm.visitSyncMessage.includes('auditoría'));

assert('El cliente API expone las cuatro operaciones de visita', typeof api.restocker.getVisits === 'function' && typeof api.restocker.createRestockVisit === 'function' && typeof api.restocker.uploadVisitPhoto === 'function' && typeof api.restocker.dumpVisitConflict === 'function');
assert('Los endpoints de visita son los del contrato móvil', apiSource.includes('/restocker/visits') && apiSource.includes('/restocker/machines/${encodeURIComponent(machineId)}/visits') && apiSource.includes('/restocker/visits/photo') && apiSource.includes('/restocker/visits/conflict-dump'));
assert('La vista declara el asistente, historial y cola offline', viewSource.includes('data-testid="modal-restock-visit"') && viewSource.includes('data-testid="restocker-recent-visits"') && viewSource.includes('data-testid="restocker-offline-visit-queue"'));
assert('La cola ofrece sincronización y archivado del conflicto', viewSource.includes('data-testid="btn-sync-visits"') && viewSource.includes('data-testid="btn-archive-visit-conflict"') && viewSource.includes('Archivar Conflicto Offline en Auditoría'));
assert('La vista pre-rellena la liquidación desde C4 sin costes', viewSource.includes('data-testid="restocker-c4-prefill"') && viewSource.includes('suggested_restocked_in_machines_qty') && viewSource.includes('suggested_withdrawn_spoilage_qty'));
assert('La cola offline usa IndexedDB, clave idempotente e índice por reponedor', queueSource.includes('indexedDB') && queueSource.includes('client_mutation_id') && queueSource.includes('by_restocker'));
assert('La vista exige foto de 5 MB y techo térmico explícito', viewSource.includes('5 * 1024 * 1024') && viewSource.includes('PERISHABLE_FOOD: 4') && viewSource.includes('COLD_DRINKS: 8'));
assert('El modal de visita no publica costes ni márgenes', !/unit_cost|unit_margin|margin_eur|coste interno/i.test(visitModalSource));
assert('El service worker precachea la cola offline de visitas', readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8').includes('/assets/js/utils/RestockVisitOfflineQueue.js'));

api.restocker.getMachinePlanogram = originalVisitPlanogram;
api.restocker.createRestockVisit = originalCreateRestockVisit;
api.restocker.uploadVisitPhoto = originalUploadVisitPhoto;
api.restocker.dumpVisitConflict = originalDumpVisitConflict;
api.restocker.getVisits = originalGetVisits;
api.get = syncGet;

api.get = originalGet;
api.post = originalPost;

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
console.log('======================================================================');

if (failures > 0) {
  process.exit(1);
}
