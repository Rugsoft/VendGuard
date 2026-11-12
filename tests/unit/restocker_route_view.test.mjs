/**
 * VendGuard - Frontend Unit Test Suite (restocker_route_view.test.mjs)
 *
 * Certifica la vista móvil del Cargador / Reponedor de Ruta (T-RES-12 · D1,
 * RF-04.1 a RF-04.3, Art. V.4, RNF-03):
 * - Acceso exclusivo de `RESTOCKER`: otro rol se rechaza y la sesión se descarta.
 * - Cabecera con nombre, `operator_code` e insignias Titular / Suplente.
 * - Ciclo completo de jornada y cierre directo sin modal de relevo SAT.
 * - Contador diario en vivo y «⏱️ Mis Horas» de solo lectura, sin id ajeno.
 * - Estado base de ruta sin paradas inventadas (Features 24, 26 y 27).
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

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - RestockerRouteView (T-RES-12)');
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
    route_stops: []
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

api.get = originalGet;
api.post = originalPost;

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
console.log('======================================================================');

if (failures > 0) {
  process.exit(1);
}
