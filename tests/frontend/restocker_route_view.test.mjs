/**
 * VendGuard - Frontend Test Suite (restocker_route_view.test.mjs)
 *
 * Certifica la vista móvil del Cargador / Reponedor de Ruta (T-RES-12 y
 * T-PLN-13 · D1, D2, RF-04.1 a RF-04.3, RF-05.2, QA 2, Art. V.4, RNF-03):
 * - Acceso exclusivo de `RESTOCKER`: otro rol se rechaza y la sesión se descarta.
 * - Cabecera con nombre, `operator_code` e insignias Titular / Suplente.
 * - Ciclo completo de jornada y cierre directo sin modal de relevo SAT.
 * - Contador diario en vivo y «⏱️ Mis Horas» de solo lectura, sin id ajeno.
 * - Estado base de ruta sin paradas inventadas.
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
import { CashCollectionOfflineQueue } from '../../public/assets/js/utils/CashCollectionOfflineQueue.js';
import {
  RestockerIncidentOfflineQueue,
  RESTOCKER_INCIDENT_PENDING,
  RESTOCKER_INCIDENT_CONFLICT,
  RESTOCKER_INCIDENT_OPERATIONS
} from '../../public/assets/js/utils/RestockerIncidentOfflineQueue.js';
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
assert('El estado base de ruta queda vacío sin paradas inventadas', routeVm.routeStops.length === 0);
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
assert('El estado base de ruta está presente con el copy vigente', viewSource.includes('data-testid="restocker-route-base"') && viewSource.includes('aparecerán aquí cuando coordinación las asigne') && !viewSource.includes('Features 24, 26 y 27'));
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
assert(
  'machineCanRestock() bloquea cuarentena y fuera de servicio',
  visitVm.machineCanRestock({ ...visitMachine, sanitary_status: 'QUARANTINE', can_restock: true }) === false
    && visitVm.machineCanRestock({ ...visitMachine, is_blocked_no_access: true }) === false
);
assert(
  'machineRestockBlockReason() explica el bloqueo sanitario',
  visitVm.machineRestockBlockReason(visitVm.machines[1]).includes('cuarentena')
    && visitVm.machineRestockBlockReason({ ...visitMachine, is_blocked_no_access: true }).includes('fuera de servicio')
);
assert('machineStockStatus() resume el estado de la tarjeta', visitVm.machineStockStatus(visitVm.machines[0]) === 'STOCKOUT' && visitVm.machineStockStatus({ stockout_slots_count: 0, low_stock_slots_count: 0 }) === 'OK');
assert('La vista pinta las insignias FEFO de la tarjeta', viewSource.includes('data-testid="restocker-machine-expired-slots"') && viewSource.includes('data-testid="restocker-machine-expiring-slots"'));
assert('El botón de visita se deshabilita con aviso explícito', viewSource.includes('data-testid="btn-start-visit"') && viewSource.includes(':disabled="!machineCanRestock(machine)"') && viewSource.includes('data-testid="restocker-machine-block-reason"'));

await visitVm.openVisitAssistant(visitVm.machines[0]);
assert('La visita carga el planograma de la máquina elegida', loadedVisitMachineId === 7 && visitVm.showVisitModal === true && visitVm.visitPlanogram.slots.length === 3);
assert(
  'El asistente de visita ofrece el escalado al SAT con la máquina y su aviso L1',
  visitModalSource.includes('data-testid="btn-escalate-from-visit"')
    && visitModalSource.includes('@click="openEscalate(visitMachine, visitMachine?.active_l1_incident || null)"')
    && visitModalSource.includes('⚡ Escalar Avería al SAT')
);
const visitEscalationIncident = { id: 501, machine_id: 7, status: 'ASSIGNED' };
const visitEscalationMachine = { ...visitVm.visitMachine, active_l1_incident: visitEscalationIncident };
visitVm.openEscalate(visitEscalationMachine, visitEscalationMachine.active_l1_incident);
assert(
  'El escalado desde la visita abre el modal con la máquina y la incidencia abiertas',
  visitVm.showEscalateModal === true
    && visitVm.showVisitModal === true
    && visitVm.escalateMachine === visitEscalationMachine
    && visitVm.escalateIncident === visitEscalationIncident
);
visitVm.showEscalateModal = false;
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

// ─────────────────────────────────────────────────────────────────────────────
// 11. Recaudación, cambio y conserjería (T-CCV-11, D1–D4, C1–C4, QA 2)
// ─────────────────────────────────────────────────────────────────────────────
const cashQueueSource = readFileSync(
  new URL('../../public/assets/js/utils/CashCollectionOfflineQueue.js', import.meta.url),
  'utf8'
);
const cashHistorySource = viewSource.slice(
  viewSource.indexOf('data-testid="restocker-cash-history"'),
  viewSource.indexOf('data-testid="modal-restocker-planogram"')
);
const cashModalSource = viewSource.slice(
  viewSource.indexOf('data-testid="modal-cash-collection"'),
  viewSource.indexOf('data-testid="cash-reception-receipt-modal"')
);
const cashReceiptSource = viewSource.slice(
  viewSource.indexOf('data-testid="cash-reception-receipt-modal"'),
  viewSource.indexOf('data-testid="modal-restock-visit"')
);

const originalCreateCashCollection = api.restocker.createCashCollection;
const originalCashReceipt = api.restocker.getCashCollectionReceptionReceipt;
const originalDumpCashConflict = api.restocker.dumpCashCollectionConflict;
const originalCashGet = api.get;

const cashRefund = {
  id: 501,
  machine_id: 7,
  machine_code: 'VEND-0701',
  claimed_amount: 4.5,
  created_at: '2026-11-14T08:00:00+00:00'
};
const cashCollectionRow = {
  id: 901,
  collection_code: 'MCC-2026-0901',
  restock_visit_id: null,
  machine_id: 7,
  machine_code: 'VEND-0701',
  bag_seal_code: 'BAG-CCV11-0001',
  sales_counter_reading: 120,
  change_tubes_loaded_eur: 10,
  is_counter_reset: false,
  counter_reset_notes: null,
  reception_cash_delivered_eur: 2.5,
  receptionist_name: 'Conserje Recepción',
  linked_refund_request_id: null,
  has_reception_receipt: true,
  status: 'SEALED_IN_ROUTE',
  status_label: 'Precintada en ruta',
  collected_at: '2026-11-14T09:00:00+00:00',
  created_at: '2026-11-14T09:00:00+00:00',
  previous_sales_counter_reading: 80,
  expected_cashbox_eur: 40,
  vault_counted_eur: 40,
  discrepancy_eur: 0,
  discrepancy_justification: 'Dato privado que jamás debe llegar al reponedor.'
};
const cashBootstrap = JSON.parse(JSON.stringify(bootstrapPayload));
cashBootstrap.data.machines[0].has_physical_reception = true;
cashBootstrap.data.machines[0].has_prior_cash_collection = false;
cashBootstrap.data.machines[0].pending_reception_refunds = [cashRefund];
cashBootstrap.data.machines[0].pending_reception_refunds_count = 1;
cashBootstrap.data.pending_reception_refunds = [cashRefund];
cashBootstrap.data.recent_cash_collections = [cashCollectionRow];
cashBootstrap.data.cash_route_summary = {
  total_collections_count: 1,
  total_change_tubes_loaded_eur: 10,
  total_reception_cash_delivered_eur: 2.5,
  pending_vault_bags_count: 1,
  last_collected_at: '2026-11-14T09:00:00+00:00'
};

api.get = async (url) => {
  if (url === '/restocker/my-route') return cashBootstrap;
  throw new Error('URL inesperada: ' + url);
};
const cashRouteVm = buildVm({
  visitQueue: new RestockVisitOfflineQueue({ indexedDB: createFakeIndexedDb() }),
  cashQueue: new CashCollectionOfflineQueue({ indexedDB: createFakeIndexedDb() })
});
await cashRouteVm.loadMyRoute();

assert(
  'El bootstrap móvil publica resumen, historial y reintegros de conserjería',
  cashRouteVm.cashRouteSummary.total_collections_count === 1
    && cashRouteVm.recentCashCollections.length === 1
    && cashRouteVm.pendingReceptionRefunds.length === 1
    && cashRouteVm.machinePendingRefundsCount(cashRouteVm.machines[0]) === 1
    && cashRouteVm.machinePendingRefundsCount(cashRouteVm.machines[1]) === 0
);
assert(
  'El historial descarta las cinco claves privadas del Arqueo Ciego',
  cashRouteVm.recentCashCollections.length === 1
    && !('previous_sales_counter_reading' in cashRouteVm.recentCashCollections[0])
    && !('expected_cashbox_eur' in cashRouteVm.recentCashCollections[0])
    && !('vault_counted_eur' in cashRouteVm.recentCashCollections[0])
    && !('discrepancy_eur' in cashRouteVm.recentCashCollections[0])
    && !('discrepancy_justification' in cashRouteVm.recentCashCollections[0])
);
assert(
  'La tarjeta de máquina anuncia la recaudación rápida y sus reintegros',
  viewSource.includes('data-testid="btn-quick-cash-collection"')
    && viewSource.includes('data-testid="restocker-machine-pending-refunds"')
    && viewSource.includes(':data-pending-refunds-count="machinePendingRefundsCount(machine)"')
);

cashRouteVm.openCashCollection(cashRouteVm.machines[0]);
assert(
  'El modal rápido abre una captura independiente con mutación propia',
  cashRouteVm.showCashModal === true
    && cashRouteVm.cashContext === 'INDEPENDENT'
    && cashRouteVm.cashForm.client_mutation_id.length > 8
    && cashRouteVm.cashForm.collected_at.length > 10
);

cashRouteVm.cashForm.bag_seal_code = 'BAG';
let cashValidation = cashRouteVm.validateCashForm(cashRouteVm.cashForm, cashRouteVm.cashModalMachine);
assert(
  'El precinto exige patrón, longitud y al menos tres dígitos (C1)',
  cashValidation.ok === false && cashValidation.error.includes('precinto')
);

cashRouteVm.cashForm.bag_seal_code = 'bag-ccv11-0002';
cashRouteVm.cashForm.sales_counter_reading = 120;
cashRouteVm.cashForm.change_tubes_loaded_eur = 10;
cashRouteVm.selectCashRefund(cashRefund);
assert(
  'Seleccionar un reintegro en un toque fija importe y expediente',
  cashRouteVm.cashForm.linked_refund_request_id === 501
    && cashRouteVm.cashForm.reception_cash_delivered_eur === 4.5
    && cashRouteVm.cashSelectedRefund?.claimed_amount === 4.5
);

cashRouteVm.cashForm.receptionist_name = '';
cashValidation = cashRouteVm.validateCashForm(cashRouteVm.cashForm, cashRouteVm.cashModalMachine);
assert(
  'La entrega en conserjería exige el nombre del conserje (D3)',
  cashValidation.ok === false && cashValidation.error.includes('conserje')
);

cashRouteVm.cashForm.receptionist_name = 'Conserje Recepción';
cashValidation = cashRouteVm.validateCashForm(cashRouteVm.cashForm, cashRouteVm.cashModalMachine);
assert(
  'La recaudación válida normaliza el precinto y respeta el Arqueo Ciego',
  cashValidation.ok === true
    && cashValidation.payload.bag_seal_code === 'BAG-CCV11-0002'
    && cashValidation.payload.linked_refund_request_id === 501
    && cashValidation.payload.reception_cash_delivered_eur === 4.5
    && !('expected_cashbox_eur' in cashValidation.payload)
    && !('previous_sales_counter_reading' in cashValidation.payload)
);

const overLimit = cashRouteVm.validateCashForm({
  ...cashRouteVm.cashForm,
  linked_refund_request_id: null,
  reception_cash_delivered_eur: 10.01
}, cashRouteVm.cashModalMachine);
assert(
  'La entrega en conserjería nunca supera 10,00 € (D3, QA 2)',
  overLimit.ok === false && overLimit.error.includes('10,00')
);
const shortReset = cashRouteVm.validateCashForm({
  ...cashRouteVm.cashForm,
  is_counter_reset: true,
  counter_reset_notes: 'corto'
}, cashRouteVm.cashModalMachine);
assert(
  'El reseteo exige nota real con tres bloques alfanuméricos (C3, Art. V.1)',
  shortReset.ok === false && shortReset.error.includes('20 caracteres')
);
const validReset = cashRouteVm.validateCashForm({
  ...cashRouteVm.cashForm,
  is_counter_reset: true,
  counter_reset_notes: 'Reseteo autorizado tras sustituir la placa del contador.'
}, cashRouteVm.cashModalMachine);
assert('Una nota de reseteo suficiente valida la captura', validReset.ok === true);

const cashCreateCalls = [];
const cashReceiptCalls = [];
api.restocker.createCashCollection = async (machineId, payload) => {
  cashCreateCalls.push({ machineId, payload });
  return {
    data: {
      ...cashCollectionRow,
      id: 902,
      collection_code: 'MCC-2026-0902',
      machine_id: 7,
      bag_seal_code: payload.bag_seal_code,
      change_tubes_loaded_eur: payload.change_tubes_loaded_eur,
      reception_cash_delivered_eur: payload.reception_cash_delivered_eur,
      receptionist_name: payload.receptionist_name,
      linked_refund_request_id: payload.linked_refund_request_id,
      has_reception_receipt: true
    }
  };
};
api.restocker.getCashCollectionReceptionReceipt = async (collectionId) => {
  cashReceiptCalls.push(collectionId);
  return {
    data: {
      collection_code: 'MCC-2026-0902',
      collected_at: '2026-11-14T10:00:00+00:00',
      location_code: 'HOSP-MAR',
      location_name: 'Hospital del Mar',
      machine_code: 'VEND-0701',
      restocker_name: 'Lucía Gómez',
      receptionist_name: 'Conserje Recepción',
      reception_cash_delivered_eur: 4.5,
      linked_refund_request_id: 501,
      bag_seal_code: 'BAG-CCV11-0002',
      pickup_pin: '1234',
      expected_cashbox_eur: 9999
    }
  };
};

await cashRouteVm.saveCashFromModal();
assert(
  'La captura online llama al endpoint independiente con la mutación',
  cashCreateCalls.length === 1
    && cashCreateCalls[0].machineId === 7
    && cashCreateCalls[0].payload.client_mutation_id === cashRouteVm.cashForm.client_mutation_id
    && cashCreateCalls[0].payload.linked_refund_request_id === 501
);
assert(
  'El alta exitosa retira el reintegro y abre el recibo de conserjería',
  cashRouteVm.showCashModal === false
    && cashRouteVm.machinePendingRefundsCount(cashRouteVm.machines[0]) === 0
    && cashReceiptCalls.length === 1
    && cashReceiptCalls[0] === 902
    && cashRouteVm.showCashReceiptModal === true
);
assert(
  'El recibo proyecta exactamente nueve campos y descarta bolsa, PIN y arqueo',
  Object.keys(cashRouteVm.cashReceipt).length === 9
    && cashRouteVm.cashReceipt.reception_cash_delivered_eur === 4.5
    && !('bag_seal_code' in cashRouteVm.cashReceipt)
    && !('pickup_pin' in cashRouteVm.cashReceipt)
    && !('expected_cashbox_eur' in cashRouteVm.cashReceipt)
);
assert(
  'El historial y el resumen reflejan la recaudación recién precintada',
  cashRouteVm.recentCashCollections[0].id === 902
    && cashRouteVm.cashRouteSummary.total_collections_count === 2
    && cashRouteVm.cashRouteSummary.total_reception_cash_delivered_eur === 7
);
cashRouteVm.closeCashReceipt();

api.restocker.createCashCollection = async () => {
  const error = new Error('Sin cobertura');
  error.status = 0;
  error.code = 'NETWORK_ERROR';
  throw error;
};
cashRouteVm.openCashCollection(cashRouteVm.machines[0]);
cashRouteVm.cashForm.bag_seal_code = 'BAG-CCV11-0003';
cashRouteVm.cashForm.sales_counter_reading = 130;
cashRouteVm.cashForm.change_tubes_loaded_eur = 5;
await cashRouteVm.saveCashFromModal();
assert(
  'Sin red, la recaudación cae en la cola offline y cierra el modal',
  cashRouteVm.showCashModal === false
    && cashRouteVm.pendingCashMutations.length === 1
    && cashRouteVm.pendingCashMutations[0].status === 'PENDING'
);
assert(
  'La cola IndexedDB aísla la recaudación por restocker_id',
  (await cashRouteVm.cashQueue.list(12)).length === 1
    && (await cashRouteVm.cashQueue.list(13)).length === 0
);
assert(
  'La captura offline se refleja de inmediato en el historial local',
  cashRouteVm.recentCashCollections[0].status === 'PENDING_SYNC'
    && cashRouteVm.recentCashCollections[0].bag_seal_code === 'BAG-CCV11-0003'
);

const cashQueue = new CashCollectionOfflineQueue({ indexedDB: createFakeIndexedDb() });
const cashQueueRecord = (id, sequence) => ({
  client_mutation_id: id,
  restocker_id: 12,
  machine_id: 7,
  payload: {
    client_mutation_id: id,
    bag_seal_code: 'BAG-' + id.toUpperCase() + '-0001',
    sales_counter_reading: 140,
    change_tubes_loaded_eur: 0,
    reception_cash_delivered_eur: 0
  },
  created_at: '2026-11-14T11:00:00+00:00',
  sequence_order: sequence
});
await cashQueue.clear(12);
await cashQueue.enqueue(cashQueueRecord('cash-2', 2));
await cashQueue.enqueue(cashQueueRecord('cash-1', 1));
await cashQueue.enqueue({ ...cashQueueRecord('cash-other', 3), restocker_id: 13 });
assert(
  'La cola FIFO ordena por secuencia y no mezcla reponedores',
  (await cashQueue.list(12)).map((item) => item.client_mutation_id).join(',') === 'cash-1,cash-2'
    && (await cashQueue.list(13)).length === 1
);
await cashQueue.markConflict('cash-1', 'OFFLINE_CASH_COLLECTION_CONFLICT');
assert(
  'Marcar conflicto conserva la recaudación con su motivo',
  (await cashQueue.list(12, ['CONFLICT']))[0].conflict_reason === 'OFFLINE_CASH_COLLECTION_CONFLICT'
);
await cashQueue.remove('cash-2');
await cashQueue.clear(12);
assert(
  'Vaciar la cola respeta el aislamiento del reponedor',
  (await cashQueue.list(12)).length === 0 && (await cashQueue.list(13)).length === 1
);
const memoryCashQueue = new CashCollectionOfflineQueue({ indexedDB: null });
await memoryCashQueue.enqueue(cashQueueRecord('memory-cash', 1));
assert(
  'La cola de recaudaciones degrada a memoria sin IndexedDB',
  memoryCashQueue.isMemoryBacked() === true && (await memoryCashQueue.list(12)).length === 1
);

const syncCashQueue = new CashCollectionOfflineQueue({ indexedDB: createFakeIndexedDb() });
await syncCashQueue.enqueue(cashQueueRecord('sync-cash-1', 1));
await syncCashQueue.enqueue(cashQueueRecord('sync-cash-2', 2));
const syncCashVm = buildVm({
  restocker: bootstrapPayload.data.restocker,
  cashQueue: syncCashQueue
});
const syncCashCalls = [];
api.restocker.createCashCollection = async (machineId, payload) => {
  syncCashCalls.push({ machineId, payload });
  if (payload.client_mutation_id === 'sync-cash-2') {
    const error = new Error('OFFLINE_CASH_COLLECTION_CONFLICT');
    error.status = 409;
    error.code = 'OFFLINE_CASH_COLLECTION_CONFLICT';
    throw error;
  }
  return {
    data: {
      ...cashCollectionRow,
      id: 903,
      collection_code: 'MCC-2026-0903',
      bag_seal_code: payload.bag_seal_code,
      reception_cash_delivered_eur: 0,
      receptionist_name: null,
      has_reception_receipt: false
    }
  };
};
await syncCashVm.syncPendingCashCollections();
assert(
  'La sincronización FIFO respeta el orden de la cola de recaudaciones',
  syncCashCalls.length === 2
    && syncCashCalls[0].payload.client_mutation_id === 'sync-cash-1'
    && syncCashCalls[1].payload.client_mutation_id === 'sync-cash-2'
);
assert(
  'Un 409 de recaudación se conserva como conflicto sin bucle',
  (await syncCashQueue.list(12)).length === 1
    && (await syncCashQueue.list(12, ['CONFLICT']))[0].client_mutation_id === 'sync-cash-2'
    && syncCashVm.cashSyncMessage.includes('1 recaudación(es) sincronizada(s)')
    && syncCashVm.cashSyncMessage.includes('1 conflicto(s)')
);

// PR-05 · Un fallo de sesión o del servidor no invalida la captura: sigue PENDING.
const retryCashQueue = new CashCollectionOfflineQueue({ indexedDB: createFakeIndexedDb() });
await retryCashQueue.enqueue(cashQueueRecord('retry-cash-1', 1));
await retryCashQueue.enqueue(cashQueueRecord('retry-cash-2', 2));
const retryCashVm = buildVm({
  restocker: bootstrapPayload.data.restocker,
  cashQueue: retryCashQueue
});
const retryCashCalls = [];
const sessionExpired = new Error('Sesión caducada');
sessionExpired.status = 401;
sessionExpired.code = 'UNAUTHORIZED';
api.restocker.createCashCollection = async (machineId, payload) => {
  retryCashCalls.push(payload.client_mutation_id);
  throw sessionExpired;
};
await retryCashVm.syncPendingCashCollections();
assert(
  'Un 401 de sesión conserva la recaudación en PENDING y no la marca como conflicto',
  retryCashCalls.length === 1
    && (await retryCashQueue.list(12, ['PENDING'])).length === 2
    && (await retryCashQueue.list(12, ['CONFLICT'])).length === 0
    && retryCashVm.cashSyncError.includes('sesión')
);

const transientServerError = new Error('Fallo interno transitorio');
transientServerError.status = 500;
transientServerError.code = 'INTERNAL_SERVER_ERROR';
api.restocker.createCashCollection = async (machineId, payload) => {
  retryCashCalls.push(payload.client_mutation_id);
  throw transientServerError;
};
await retryCashVm.syncPendingCashCollections();
assert(
  'Un 500 transitorio conserva la recaudación en PENDING sin consumir la cola',
  retryCashCalls.length === 2
    && (await retryCashQueue.list(12, ['PENDING'])).length === 2
    && (await retryCashQueue.list(12, ['CONFLICT'])).length === 0
    && retryCashVm.cashSyncError.includes('servidor')
);

const businessForbidden = new Error('Máquina fuera de las zonas del reponedor');
businessForbidden.status = 403;
businessForbidden.code = 'MACHINE_OUTSIDE_RESTOCKER_ZONES';
api.restocker.createCashCollection = async (machineId, payload) => {
  retryCashCalls.push(payload.client_mutation_id);
  throw businessForbidden;
};
await retryCashVm.syncPendingCashCollections();
assert(
  'Un 403 de negocio sí se archiva como conflicto irreversible',
  (await retryCashQueue.list(12, ['CONFLICT'])).length === 2
    && (await retryCashQueue.list(12, ['PENDING'])).length === 0
    && retryCashVm.cashSyncMessage.includes('2 conflicto(s)')
);

const retryVisitQueue = new RestockVisitOfflineQueue({ indexedDB: createFakeIndexedDb() });
await retryVisitQueue.enqueue(queueRecord('retry-visit-1', 1));
const retryVisitVm = buildVm({
  restocker: bootstrapPayload.data.restocker,
  visitQueue: retryVisitQueue
});
const forbiddenVisit = new Error('Sesión sin permiso');
forbiddenVisit.status = 403;
forbiddenVisit.code = 'FORBIDDEN';
api.restocker.createRestockVisit = async () => {
  throw forbiddenVisit;
};
await retryVisitVm.syncPendingVisits();
assert(
  'Un 403 de sesión conserva la visita en PENDING y no la marca como conflicto',
  (await retryVisitQueue.list(12, ['PENDING'])).length === 1
    && (await retryVisitQueue.list(12, ['CONFLICT'])).length === 0
    && retryVisitVm.visitSyncError.includes('sesión')
);

const dumpCashCalls = [];
api.restocker.dumpCashCollectionConflict = async (payload) => {
  dumpCashCalls.push(payload);
  return { logged: true, action: 'OFFLINE_CASH_COLLECTION_CONFLICT_DUMP' };
};
const conflictedCash = (await syncCashQueue.list(12, ['CONFLICT']))[0];
await syncCashVm.archiveCashConflict(conflictedCash);
assert(
  'Archivar el conflicto de recaudación llama a auditoría con su precinto',
  dumpCashCalls.length === 1
    && dumpCashCalls[0].machine_id === 7
    && dumpCashCalls[0].client_mutation_id === 'sync-cash-2'
    && dumpCashCalls[0].bag_seal_code === 'BAG-SYNC-CASH-2-0001'
    && (await syncCashQueue.list(12)).length === 0
);

api.restocker.getMachinePlanogram = async () => visitPlanogramPayload;
const cashVisitVm = buildVm({
  restocker: bootstrapPayload.data.restocker,
  machines: JSON.parse(JSON.stringify([{
    ...visitMachine,
    has_physical_reception: true,
    pending_reception_refunds: [cashRefund],
    pending_reception_refunds_count: 1
  }])),
  vanProductStocks: JSON.parse(JSON.stringify(visitStocks)),
  visitQueue: new RestockVisitOfflineQueue({ indexedDB: createFakeIndexedDb() }),
  cashQueue: new CashCollectionOfflineQueue({ indexedDB: createFakeIndexedDb() })
});
await cashVisitVm.openVisitAssistant(cashVisitVm.machines[0]);
cashVisitVm.visitForm.cabin_temp_celsius = 3.5;
cashVisitVm.markHygieneComplete();
cashVisitVm.visitCashEnabled = true;
cashVisitVm.onVisitCashToggle();
assert(
  'El bloque de la visita abre la misma captura en contexto atómico',
  cashVisitVm.showCashModal === true && cashVisitVm.cashContext === 'VISIT'
);
cashVisitVm.cashForm.bag_seal_code = 'BAG-CCV11-0004';
cashVisitVm.cashForm.sales_counter_reading = 150;
cashVisitVm.cashForm.change_tubes_loaded_eur = 8;
cashVisitVm.cashForm.reception_cash_delivered_eur = 4.5;
cashVisitVm.cashForm.receptionist_name = 'Conserje Visita';
await cashVisitVm.saveCashFromModal();
assert(
  'Guardar el bloque prepara la recaudación sin enviarla por separado',
  cashVisitVm.showCashModal === false
    && cashVisitVm.visitCashConfigured === true
    && cashVisitVm.visitCashForm.bag_seal_code === 'BAG-CCV11-0004'
);
const integratedVisitPayload = cashVisitVm.buildVisitPayload();
assert(
  'El payload de la visita incorpora la recaudación integral (D1)',
  integratedVisitPayload.cash_collection?.bag_seal_code === 'BAG-CCV11-0004'
    && integratedVisitPayload.cash_collection.reception_cash_delivered_eur === 4.5
    && integratedVisitPayload.cash_collection.receptionist_name === 'Conserje Visita'
    && !('expected_cashbox_eur' in integratedVisitPayload.cash_collection)
);

cashVisitVm.visitForm.cabin_temp_celsius = 4.1;
const thermalVisitPayload = cashVisitVm.buildVisitPayload();
const thermalCashValidation = cashVisitVm.validateCashForm(
  cashVisitVm.visitCashForm,
  cashVisitVm.visitMachine,
  true
);
assert(
  'El aborto térmico excluye y bloquea cualquier recaudación (Art. II.2)',
  thermalVisitPayload.status === 'ABORTED_THERMAL_BREACH'
    && !('cash_collection' in thermalVisitPayload)
    && thermalCashValidation.ok === false
    && thermalCashValidation.error.includes('Art. II.2')
);
assert(
  'El bloque de efectivo queda oculto cuando la visita aborta por temperatura',
  viewSource.includes('data-testid="visit-cash-block"')
    && /v-if="!visitIsThermalAbort"[\s\S]{0,80}data-testid="visit-cash-block"/.test(viewSource)
);

assert(
  'El cliente API expone las cuatro operaciones móviles de recaudación',
  typeof api.restocker.createCashCollection === 'function'
    && typeof api.restocker.getCashCollections === 'function'
    && typeof api.restocker.getCashCollectionReceptionReceipt === 'function'
    && typeof api.restocker.dumpCashCollectionConflict === 'function'
);
assert(
  'Las rutas móviles son las del contrato de recaudación',
  apiSource.includes('/restocker/machines/${encodeURIComponent(machineId)}/cash-collections')
    && apiSource.includes('cash-collections${q ? `?${q}`')
    && apiSource.includes('/restocker/cash-collections/${encodeURIComponent(collectionId)}/reception-receipt')
    && apiSource.includes('/restocker/cash-collections/conflict-dump')
);
assert(
  'El modal rápido declara precinto, contador, cambio, conserjería y cola offline',
  cashModalSource.includes('data-testid="cash-bag-seal"')
    && cashModalSource.includes('data-testid="cash-counter-reading"')
    && cashModalSource.includes('data-testid="cash-change-tubes"')
    && cashModalSource.includes('data-testid="cash-refund-option"')
    && cashModalSource.includes('data-testid="cash-reception-amount"')
    && cashModalSource.includes('data-testid="cash-receptionist-name"')
    && cashModalSource.includes('cola IndexedDB FIFO')
);
assert(
  'El historial usa el copy canónico y no publica las cinco claves privadas',
  cashHistorySource.includes('🪙 Mis Bolsas Precintadas y Cambios de Ruta')
    && !cashHistorySource.includes('expected_cashbox_eur')
    && !cashHistorySource.includes('previous_sales_counter_reading')
    && !cashHistorySource.includes('vault_counted_eur')
    && !cashHistorySource.includes('discrepancy_eur')
    && !cashHistorySource.includes('discrepancy_justification')
);
assert(
  'El recibo muestra los nueve campos sin bolsa, PIN ni arqueo',
  cashReceiptSource.includes('data-testid="cash-reception-receipt-modal"')
    && cashReceiptSource.includes('linked_refund_request_id')
    && !cashReceiptSource.includes('bag_seal_code')
    && !cashReceiptSource.includes('pickup_pin')
    && !cashReceiptSource.includes('expected_cashbox_eur')
);
assert(
  'La cola de recaudaciones usa IndexedDB, clave idempotente e índice por reponedor',
  cashQueueSource.includes('indexedDB')
    && cashQueueSource.includes('client_mutation_id')
    && cashQueueSource.includes('by_restocker')
    && cashQueueSource.includes('vendguard-cash-collections')
);
assert(
  'El Service Worker precachea la cola de recaudaciones y sube la versión',
  readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8')
    .includes('/assets/js/utils/CashCollectionOfflineQueue.js')
    && readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8')
      .includes('vendguard-app-shell-v26')
);
assert(
  'La vista completa no expone ninguna de las cinco claves del Arqueo Ciego',
  !viewSource.includes('previous_sales_counter_reading')
    && !viewSource.includes('expected_cashbox_eur')
    && !viewSource.includes('vault_counted_eur')
    && !viewSource.includes('discrepancy_eur')
    && !viewSource.includes('discrepancy_justification')
);

// ─────────────────────────────────────────────────────────────────────────────
// T-RSS-11 · Avisos L1, escalado y cola offline del reponedor (Módulo 36)
// ─────────────────────────────────────────────────────────────────────────────
const incidentVm = buildVm({ restocker: { id: 12, role: 'RESTOCKER' } });
assert(
  'data() arranca la bandeja L1, la taxonomía y la coincidencia sin inventar datos',
  Array.isArray(incidentVm.assignedL1Incidents) && incidentVm.assignedL1Incidents.length === 0
    && Array.isArray(incidentVm.l1TaxonomyCatalog) && incidentVm.l1TaxonomyCatalog.length === 0
    && typeof incidentVm.siteCoincidence === 'object'
);
assert(
  'La vista rotula la taxonomía L1 y su estado',
  incidentVm.l1IssueLabel('STOCKOUT_EMPTY_SLOT').includes('Carril vacío')
    && incidentVm.l1IssueLabel('MISSING_CONSUMABLES').includes('vasos')
    && incidentVm.l1StatusLabel('IN_PROGRESS') === 'En curso'
);
incidentVm.siteCoincidence = {
  4: [{
    user_id: 7,
    name: 'Jordi SAT',
    operator_code: 'SAT-07',
    phone: '+34 600 123 456',
    activity_summary: '1 avería SAT activa en esta sede'
  }]
};
assert(
  'La Coincidencia en Sede muestra el código y el enlace tel: sin user_id',
  incidentVm.siteCoincidenceForLocation(4).length === 1
    && incidentVm.siteCoincidenceSummary(4).includes('Jordi SAT (SAT-07)')
    && !incidentVm.siteCoincidenceSummary(4).includes('user_id')
    && viewSource.includes('v-for="entry in siteCoincidenceForLocation(machine.location_id)"')
    && viewSource.includes('{{ siteCoincidenceLabel(entry) }}')
    && viewSource.includes("'tel:' + entry.phone")
    && viewSource.includes('entry.operator_code')
);
assert(
  'La máquina distingue aviso L1 y SAT abierto',
  incidentVm.machineHasActiveL1({ id: 1, active_l1_incident: { level1_issue_type: 'STOCKOUT_EMPTY_SLOT' } }) === true
    && incidentVm.machineHasActiveSat({ id: 1, has_active_sat_incident: true }) === true
    && incidentVm.machineHasActiveL1({ id: 2 }) === false
);

assert(
  'La vista incorpora bandeja, badge, banner y botón de escalado L1',
  viewSource.includes('data-testid="restocker-l1-incidents"')
    && viewSource.includes('data-testid="restocker-machine-l1-badge"')
    && viewSource.includes('data-testid="restocker-site-coincidence"')
    && viewSource.includes('⚡ Escalar Avería al SAT')
);
assert(
  'El modal de resolución exige taxonomía y 20 caracteres en diagnóstico y acción',
  viewSource.includes('data-testid="restocker-l1-resolve-modal"')
    && viewSource.includes('data-testid="l1-root-cause"')
    && viewSource.includes('data-testid="l1-standard-solution"')
    && viewSource.includes('data-testid="l1-diagnosis"')
    && viewSource.includes('data-testid="l1-action"')
);
assert(
  'El modal de escalado exige nota interna y foto de hasta 5 MB',
  viewSource.includes('data-testid="restocker-escalate-modal"')
    && viewSource.includes('máx. 5 MB')
    && viewSource.includes('workshop_internal_note')
    && viewSource.includes('uploadIncidentPhoto')
);

assert(
  'El Service Worker precachea la cola L1 y sube la versión',
  readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8')
    .includes('/assets/js/utils/RestockerIncidentOfflineQueue.js')
    && readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8')
      .includes('vendguard-app-shell-v26')
);
assert(
  'El cliente API expone inicio, resolución, escalado y volcado de conflicto L1',
  typeof api.restocker.startL1Incident === 'function'
    && typeof api.restocker.resolveL1Incident === 'function'
    && typeof api.restocker.escalateMachineToSat === 'function'
    && typeof api.restocker.dumpIncidentConflict === 'function'
);

const incidentQueue = new RestockerIncidentOfflineQueue();
const queuedStart = await incidentQueue.enqueue({
  client_mutation_id: 't-rss11-start-1',
  restocker_id: 12,
  operation_type: RESTOCKER_INCIDENT_OPERATIONS.START_L1,
  incident_id: 501,
  machine_id: 7,
  machine_code: 'VEND-0701',
  payload: { client_mutation_id: 't-rss11-start-1' }
});
assert(
  'La cola L1 usa memoria cuando no hay IndexedDB y preserva la operación',
  incidentQueue.isMemoryBacked() === true
    && queuedStart.status === RESTOCKER_INCIDENT_PENDING
    && queuedStart.operation_type === 'START_L1_INCIDENT'
);
const listedQueue = await incidentQueue.list(12);
assert('La cola L1 se aísla por restocker_id', listedQueue.length === 1 && listedQueue[0].incident_id === 501);
incidentQueue.enqueue({
  client_mutation_id: 't-rss11-start-1',
  restocker_id: 12,
  operation_type: RESTOCKER_INCIDENT_OPERATIONS.START_L1,
  incident_id: 501,
  machine_id: 7,
  payload: { client_mutation_id: 't-rss11-start-1' }
});
assert('La cola L1 es idempotente por client_mutation_id', (await incidentQueue.list(12)).length === 1);
await incidentQueue.markConflict('t-rss11-start-1', 'Ticket ya resuelto por otro operario.');
const conflictedQueue = await incidentQueue.list(12, [RESTOCKER_INCIDENT_CONFLICT]);
assert(
  'La cola L1 conserva el motivo de un conflicto irresoluble',
  conflictedQueue.length === 1 && conflictedQueue[0].conflict_reason.includes('otro operario')
);
await incidentQueue.clear(12);
assert('La cola L1 se vacía al cerrar la sesión del reponedor', (await incidentQueue.list(12)).length === 0);

const originalStartL1 = api.restocker.startL1Incident;
const originalResolveL1 = api.restocker.resolveL1Incident;
const originalEscalateMachine = api.restocker.escalateMachineToSat;
const originalUploadIncidentPhoto = api.restocker.uploadIncidentPhoto;
let startCalled = 0;
let resolvePayload = null;
let escalatedPayload = null;
let uploadedIncidentPhoto = 0;
api.restocker.startL1Incident = async () => { startCalled++; return {}; };
api.restocker.resolveL1Incident = async (_id, payload) => { resolvePayload = payload; return {}; };
api.restocker.escalateMachineToSat = async (_id, payload) => { escalatedPayload = payload; return {}; };
api.restocker.uploadIncidentPhoto = async () => {
  uploadedIncidentPhoto++;
  return { photo_evidence_url: '/uploads/incidents/t-rss11.png' };
};

const offlineVm = buildVm({ restocker: { id: 12, role: 'RESTOCKER' } });
Object.defineProperty(offlineVm, 'navigatorOnline', { get: () => false });
const offlineIncident = { id: 501, machine_id: 7, machine_code: 'VEND-0701', status: 'ASSIGNED' };
await offlineVm.startL1(offlineIncident);
assert(
  'Sin conexión el inicio L1 se encola y no golpea la API',
  startCalled === 0 && offlineIncident.status === 'IN_PROGRESS'
    && (await offlineVm.ensureIncidentQueue().list(12)).length === 1
);
assert(
  'El primer aviso offline queda pendiente de sincronizar',
  offlineVm.incidentSyncMessage.includes('cola offline')
);
await offlineVm.ensureIncidentQueue().clear(12);

const resolveVm = buildVm({ restocker: { id: 12, role: 'RESTOCKER' } });
resolveVm.assignedL1Incidents = [{ id: 501, machine_id: 7, machine_code: 'VEND-0701', status: 'IN_PROGRESS' }];
resolveVm.loadMyRoute = async () => {};
resolveVm.l1TaxonomyCatalog = [{
  id: 31,
  name: 'Carril vacío',
  solutions: [{ id: 41, name: 'Reposición de producto' }]
}];
resolveVm.openL1Resolve(resolveVm.assignedL1Incidents[0]);
resolveVm.l1ResolveForm.diagnosis = 'Carril sin producto y muelle correcto';
resolveVm.l1ResolveForm.action = 'Repongo producto y verifico la entrega completa';
await resolveVm.submitL1Resolution();
assert(
  'La resolución online viaja con máquina, taxonomía y textos',
  resolvePayload?.machine_id === 7 && resolvePayload?.root_cause_id === 31
    && resolvePayload?.standard_solution_id === 41
    && resolvePayload?.diagnosis.includes('Carril sin producto')
    && resolvePayload?.action.includes('Repongo producto')
);

const shortResolveVm = buildVm({ restocker: { id: 12, role: 'RESTOCKER' } });
shortResolveVm.assignedL1Incidents = [{ id: 502, machine_id: 8, status: 'IN_PROGRESS' }];
shortResolveVm.openL1Resolve(shortResolveVm.assignedL1Incidents[0]);
shortResolveVm.l1ResolveForm.diagnosis = 'Corto';
shortResolveVm.l1ResolveForm.action = 'Corto';
await shortResolveVm.submitL1Resolution();
assert(
  'La resolución L1 bloquea textos de menos de 20 caracteres reales (Art. V.1)',
  shortResolveVm.l1ResolveError.includes('20 caracteres')
);

const escalateVm = buildVm({ restocker: { id: 12, role: 'RESTOCKER' } });
escalateVm.loadMyRoute = async () => {};
const escalationMachine = { id: 7, code: 'VEND-0701', location_id: 4 };
escalateVm.openEscalate(escalationMachine);
escalateVm.escalateForm.workshop_internal_note = 'Electroválvula con fuga en el grupo de café';
await escalateVm.submitEscalation();
assert(
  'El escalado exige fotografía de evidencia (Art. V.5)',
  escalateVm.escalateError.includes('fotografía')
);
escalateVm.escalatePhotoFile = new Blob(['evidence'], { type: 'image/png' });
escalateVm.escalatePhotoName = 'fuga.png';
await escalateVm.submitEscalation();
assert(
  'El escalado online sube la foto y sella tamaño y MIME reales',
  uploadedIncidentPhoto === 1 && escalatedPayload?.photo_path === '/uploads/incidents/t-rss11.png'
    && escalatedPayload?.photo_mime_type === 'image/png'
    && Number(escalatedPayload?.photo_size_bytes) === 8
    && escalatedPayload?.workshop_internal_note.includes('Electroválvula')
);

const incidentSyncVm = buildVm({ restocker: { id: 12, role: 'RESTOCKER' } });
incidentSyncVm.incidentSyncError = '';
incidentSyncVm.loadMyRoute = async () => {};
await incidentSyncVm.ensureIncidentQueue().enqueue({
  client_mutation_id: 't-rss11-escalate-sync',
  restocker_id: 12,
  operation_type: RESTOCKER_INCIDENT_OPERATIONS.ESCALATE_TO_SAT,
  machine_id: 7,
  machine_code: 'VEND-0701',
  payload: { workshop_internal_note: 'Electroválvula con fuga en el grupo de café' },
  photo: new Blob(['evidence'], { type: 'image/png' }),
  photo_name: 'fuga.png'
});
await incidentSyncVm.syncPendingIncidentMutations();
assert(
  'La sincronización L1 reenvía el escalado con su foto y vacía la cola',
  uploadedIncidentPhoto === 2
    && escalatedPayload?.photo_path === '/uploads/incidents/t-rss11.png'
    && (await incidentSyncVm.ensureIncidentQueue().list(12)).length === 0
);

api.restocker.startL1Incident = originalStartL1;
api.restocker.resolveL1Incident = originalResolveL1;
api.restocker.escalateMachineToSat = originalEscalateMachine;
api.restocker.uploadIncidentPhoto = originalUploadIncidentPhoto;

api.restocker.createCashCollection = originalCreateCashCollection;
api.restocker.getCashCollectionReceptionReceipt = originalCashReceipt;
api.restocker.dumpCashCollectionConflict = originalDumpCashConflict;
api.get = originalCashGet;

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
