/**
 * VendGuard - Frontend Test Suite (technician_and_coordinator_synergy.test.mjs)
 *
 * Certifica T-RSS-10 del Módulo 36 (RF-02.1, RF-04.1, RF-05.2.2, RF-05.3,
 * RNF-03, D2, D4, Art. V.4):
 * - Coordinación filtra por `dispatch_level` y pinta el nivel/taxonomía L1.
 * - El modal de Triaje conmuta el nivel y ofrece el `#1 RECOMENDADO ⭐`.
 * - El despacho L1 viaja al endpoint `dispatch-level` con el reponedor elegido.
 * - La ruta del técnico pinta la insignia de escalado y el banner de
 *   Coincidencia en Sede sin exponer datos al Portal.
 *
 * Dogma Vanilla: runner ES Module puro sin dependencias externas.
 */

const storageMock = new Map();
globalThis.localStorage = {
  getItem: (key) => storageMock.get(key) ?? null,
  setItem: (key, value) => storageMock.set(key, String(value)),
  removeItem: (key) => storageMock.delete(key),
  clear: () => storageMock.clear()
};
globalThis.sessionStorage = { ...globalThis.localStorage };

import { readFileSync } from 'node:fs';
import { api } from '../../public/assets/js/api.js';
import { store } from '../../public/assets/js/store.js';
import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';
import { TechnicianRouteView } from '../../public/assets/js/views/TechnicianRouteView.js';

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) console.error(`         Reason: ${details}`);
    failures++;
  }
}

function buildVm(component, overrides = {}) {
  const vm = {
    ...component.data(),
    ...component.methods,
    ...overrides
  };
  for (const [name, getter] of Object.entries(component.computed || {})) {
    Object.defineProperty(vm, name, { get: getter, configurable: true });
  }
  return vm;
}

const coordinatorSource = readFileSync(
  new URL('../../public/assets/js/views/CoordinatorDashboardView.js', import.meta.url),
  'utf8'
);
const technicianSource = readFileSync(
  new URL('../../public/assets/js/views/TechnicianRouteView.js', import.meta.url),
  'utf8'
);

console.log('======================================================================');
console.log(' VendGuard: Frontend Suite - Sinergia Reponedor ↔ SAT (T-RSS-10)');
console.log('======================================================================\n');

// ─────────────────────────────────────────────────────────────────────────────
// 1. Coordinación: filtro, nivel visible y plantilla del selector
// ─────────────────────────────────────────────────────────────────────────────
const coordinator = buildVm(CoordinatorDashboardView, {
  $emit: () => {},
  incidents: [
    { id: 1, ticket_code: 'INC-1', dispatch_level: 'L1_RESTOCKER', level1_issue_type: 'STOCKOUT_EMPTY_SLOT' },
    { id: 2, ticket_code: 'INC-2', dispatch_level: 'L2_SAT' },
    { id: 3, ticket_code: 'INC-3', dispatch_level: 'L1_RESTOCKER' }
  ]
});

coordinator.filterDispatchLevel = 'L1_RESTOCKER';
assert(
  'filteredIncidents deja solo el nivel L1',
  coordinator.filteredIncidents.length === 2
    && coordinator.filteredIncidents.every((incident) => incident.dispatch_level === 'L1_RESTOCKER')
);
coordinator.filterDispatchLevel = '';
assert('El filtro vacío equivale a ALL', coordinator.filteredIncidents.length === 3);
assert(
  'La etiqueta de fila distingue L1 y L2',
  coordinator.dispatchLevelLabel({ dispatch_level: 'L1_RESTOCKER' }).includes('Reponedor')
    && coordinator.dispatchLevelLabel({ dispatch_level: 'L2_SAT' }).includes('SAT')
);
assert(
  'La plantilla publica el filtro de nivel operativo',
  coordinatorSource.includes('data-testid="select-dispatch-level-filter"')
    && coordinatorSource.includes('filterDispatchLevel')
);
assert(
  'La fila publica dispatch_level y la taxonomía L1',
  coordinatorSource.includes('data-testid="row-dispatch-level"')
    && coordinatorSource.includes('inc.level1_issue_type')
);
assert(
  'El modal de Triaje incluye el selector de nivel',
  coordinatorSource.includes('data-testid="assign-dispatch-level-select"')
    && coordinatorSource.includes('data-testid="assign-level1-issue-type-select"')
    && coordinatorSource.includes('assignDispatchLevel')
);
assert(
  'La sugerencia del reponedor se rotula #1 RECOMENDADO',
  coordinatorSource.includes('#1 RECOMENDADO ⭐')
    && coordinatorSource.includes('data-testid="assign-restocker-suggestions"')
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Coordinación: consulta filtrada y despacho L1
// ─────────────────────────────────────────────────────────────────────────────
const originalGetIncidents = api.coordinator.getIncidents;
const originalGetSuggestedRestockers = api.coordinator.getSuggestedRestockers;
const originalChangeDispatchLevel = api.coordinator.changeDispatchLevel;
const originalSetLoading = store.setLoading;
let requestedFilters = null;
let dispatched = null;

api.coordinator.getIncidents = async (filters = {}) => {
  requestedFilters = filters;
  return [];
};
store.setLoading = () => {};

coordinator.filterDispatchLevel = 'L2_SAT';
await coordinator.loadIncidents(true);
assert(
  'loadIncidents envía dispatch_level al servidor',
  requestedFilters?.dispatch_level === 'L2_SAT',
  JSON.stringify(requestedFilters)
);

api.coordinator.getSuggestedRestockers = async () => ([
  { user_id: 9, name: 'Lucía Gómez', operator_code: 'REP-01', active_l1_incidents_count: 1 },
  { user_id: 10, name: 'Mario Ruiz', operator_code: 'REP-02', active_l1_incidents_count: 3 }
]);
coordinator.assignDispatchLevel = 'L1_RESTOCKER';
coordinator.assignRestockerId = null;
await coordinator.loadAssignRestockerSuggestions(4);
assert(
  'El #1 recomendado es el primer puesto del escalafón',
  coordinator.recommendedRestocker?.user_id === 9
);
assert(
  'El #1 recomendado queda preseleccionado',
  Number(coordinator.assignRestockerId) === 9
);

api.coordinator.changeDispatchLevel = async (id, level, assigneeId, level1IssueType) => {
  dispatched = { id, level, assigneeId, level1IssueType };
  return { id, dispatch_level: level, assigned_technician_id: assigneeId };
};
coordinator.selectedIncident = { id: 4, ticket_code: 'INC-4', category: 'PRODUCT_JAM' };
coordinator.assignRestockerId = 10;
coordinator.assignLevel1IssueType = 'MINOR_SPIRAL_JAM';
coordinator.loadIncidents = async () => {};
let emitted = null;
coordinator.$emit = (event, payload) => { emitted = { event, payload }; };

await coordinator.performLevel1Dispatch();
assert(
  'El despacho L1 llama a dispatch-level con el reponedor elegido',
  dispatched?.id === 4 && dispatched?.level === 'L1_RESTOCKER' && dispatched?.assigneeId === 10
    && dispatched?.level1IssueType === 'MINOR_SPIRAL_JAM',
  JSON.stringify(dispatched)
);
assert('El despacho L1 emite assigned para refrescar la vista', emitted?.event === 'assigned');

// PR22-02: un expediente que nació en L1 y se conmuta a L2 debe viajar por
// dispatch-level, no por assignTechnician (que dejaba el nivel intacto).
let level2Dispatched = null;
let level2Calls = 0;
let assignTechnicianCalls = 0;
const originalAssignTechnician = api.coordinator.assignTechnician;
api.coordinator.changeDispatchLevel = async (
  id,
  level,
  assigneeId,
  level1IssueType,
  urgencyOverride,
  urgencyReason
) => {
  level2Calls++;
  level2Dispatched = { id, level, assigneeId, level1IssueType, urgencyOverride, urgencyReason };
  return { id, dispatch_level: level, assigned_technician_id: assigneeId };
};
api.coordinator.assignTechnician = async () => {
  assignTechnicianCalls++;
  return {};
};
coordinator.selectedIncident = {
  id: 5,
  ticket_code: 'INC-5',
  dispatch_level: 'L1_RESTOCKER',
  assigned_technician_id: 9
};
coordinator.assignDispatchLevel = 'L2_SAT';
coordinator.assignTechnicianId = 10;
coordinator.assignUrgencyOverride = 'HIGH';
coordinator.assignUrgencyReason = '';
coordinator.refreshTerritorialMap = () => {};
await coordinator.submitAssignment();
assert(
  'El cambio L1→L2 exige motivo cuando se reclasifica la urgencia',
  level2Calls === 0 && coordinator.assignError.includes('motivo justificado'),
  JSON.stringify({ level2Calls, error: coordinator.assignError })
);
coordinator.assignUrgencyReason = 'La sede confirma el bloqueo del cobro y exige prioridad alta.';
await coordinator.submitAssignment();
assert(
  'El cambio L1→L2 llama a dispatch-level con técnico y urgencia elegidos',
  level2Dispatched?.id === 5
    && level2Dispatched?.level === 'L2_SAT'
    && level2Dispatched?.assigneeId === 10
    && level2Dispatched?.urgencyOverride === 'HIGH'
    && level2Dispatched?.urgencyReason === 'La sede confirma el bloqueo del cobro y exige prioridad alta.'
    && assignTechnicianCalls === 0,
  JSON.stringify({ level2Dispatched, assignTechnicianCalls })
);
api.coordinator.assignTechnician = originalAssignTechnician;

const modalVm = buildVm(CoordinatorDashboardView, {
  technicians: [{ id: 7, name: 'Técnico SAT' }],
  ensureTechniciansLoaded: () => {},
  loadAssignmentSuggestions: async () => {},
  loadAssignRestockerSuggestions: async () => {}
});
modalVm.openAssignModal({
  id: 6,
  ticket_code: 'INC-6',
  dispatch_level: 'L1_RESTOCKER',
  assigned_technician_id: 9
});
assert(
  'El modal L1 no preselecciona al reponedor como técnico SAT',
  Number(modalVm.assignTechnicianId) === 7
);

api.coordinator.getIncidents = originalGetIncidents;
api.coordinator.getSuggestedRestockers = originalGetSuggestedRestockers;
api.coordinator.changeDispatchLevel = originalChangeDispatchLevel;
store.setLoading = originalSetLoading;

// ─────────────────────────────────────────────────────────────────────────────
// 3. Ruta del técnico: insignia de escalado y banner de coincidencia
// ─────────────────────────────────────────────────────────────────────────────
const technician = buildVm(TechnicianRouteView);
const coincidence = [
  {
    user_id: 9,
    name: 'Lucía Gómez',
    operator_code: 'REP-09',
    phone: '+34 600 111 222',
    activity_summary: '1 aviso(s) L1 activo(s) en esta sede'
  },
  {
    user_id: 10,
    name: 'Mario Ruiz',
    operator_code: 'REP-10',
    phone: '+34 600 333 444',
    activity_summary: 'Cobertura de zona activa en esta sede'
  }
];

assert(
  'La ruta lee site_coincidence de la parada',
  technician.siteCoincidenceFor({ site_coincidence: coincidence }).length === 2
    && technician.siteCoincidenceFor({}).length === 0
);
assert(
  'La coincidencia se resume y el banner muestra nombre, código y tel:',
  technician.siteCoincidenceSummary({ site_coincidence: coincidence }).includes('Lucía Gómez (REP-09)')
    && technician.siteCoincidenceSummary({ site_coincidence: coincidence }).includes('1 aviso')
    && !technician.siteCoincidenceSummary({ site_coincidence: coincidence }).includes('user_id')
    && technicianSource.includes('v-for="entry in siteCoincidenceFor(incident)"')
    && technicianSource.includes('{{ siteCoincidenceLabel(entry) }}')
    && technicianSource.includes("'tel:' + entry.phone")
    && technicianSource.includes('entry.operator_code')
);
assert(
  'La insignia detecta el escalado y la tarjeta reconoce nota/foto internas',
  technician.wasEscalatedByRestocker({ escalated_from_restocker_id: 9 }) === true
    && technician.wasEscalatedByRestocker({ escalated_to_sat_at: '2026-11-18T10:00:00Z' }) === true
    && technician.wasEscalatedByRestocker({ escalated_from_restocker_id: null }) === false
    && technician.hasWorkshopInternalContext({ workshop_internal_note: 'El carril queda bloqueado.' }) === true
    && technician.hasWorkshopInternalContext({ photo_path: '/uploads/incidents/rss08_escalation.jpg' }) === true
    && technician.hasWorkshopInternalContext({}) === false
    && technician.workshopInternalPhoto({ workshop_internal_photo_path: '/uploads/nota.jpg', photo_path: '/uploads/ticket.jpg' }) === '/uploads/nota.jpg'
);
assert(
  'La tarjeta incluye la insignia y la Nota Interna/foto del escalado',
  technicianSource.includes('data-testid="tech-escalated-by-restocker"')
    && technicianSource.includes('⚡ Escalado por Reponedor In Situ')
    && technicianSource.includes('data-testid="tech-workshop-internal-note"')
    && technicianSource.includes('workshop_internal_note')
    && technicianSource.includes('workshop_internal_photo_path')
    && technicianSource.includes('data-testid="tech-workshop-internal-photo"')
);
assert(
  'La tarjeta incluye el banner 🤝 Coincidencia en Sede',
  technicianSource.includes('data-testid="tech-site-coincidence"')
    && technicianSource.includes('🤝 Coincidencia en Sede')
);
assert(
  'La vista técnica no publica dispatch_level en su plantilla',
  !technicianSource.includes('{{ incident.dispatch_level }}')
);

// O-36-01: al abrir turno la ruta debe recargarse para que la coincidencia,
// calculada con el turno ya abierto, llegue al banner sin refrescar la página.
const shiftStartVm = buildVm(TechnicianRouteView, {
  guardOnlineOnly: () => true,
  persistOfflineShiftStatus: async () => {},
  refreshOfflineSnapshotFromServer: async () => {},
  loadTroubleshootingGuidesForRoute: async () => {}
});
const originalPost = api.post;
const originalTechnicianGetMyRoute = api.technician.getMyRoute;
let startedPath = null;
let routeLoads = 0;
api.post = async (path) => {
  startedPath = path;
  return { status: 'ON_SHIFT', active_shift_id: 77, today_effective_seconds: 3600 };
};
api.technician.getMyRoute = async () => {
  routeLoads++;
  return [{ id: 501, machine_id: 7, site_coincidence: coincidence }];
};
try {
  await shiftStartVm.startShift();
} finally {
  api.post = originalPost;
  api.technician.getMyRoute = originalTechnicianGetMyRoute;
}
assert(
  'O-36-01: iniciar turno recarga la ruta y publica la coincidencia sin refrescar',
  startedPath === '/technician/shift/start'
    && routeLoads === 1
    && shiftStartVm.shiftStatus === 'ON_SHIFT'
    && shiftStartVm.siteCoincidenceFor(shiftStartVm.incidents[0]).length === 2
    && shiftStartVm.siteCoincidenceSummary(shiftStartVm.incidents[0]).includes('Lucía Gómez (REP-09)')
);

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Fallidas: ${failures}`);
console.log(failures === 0
  ? ' RESULTADO: 100% EN VERDE. SINERGIA DE TRIAJE Y RUTA CERTIFICADA (T-RSS-10).'
  : ' RESULTADO: FALLO.');
console.log('======================================================================');

process.exit(failures === 0 ? 0 : 1);
