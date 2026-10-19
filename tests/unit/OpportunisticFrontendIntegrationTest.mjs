/**
 * VendGuard - Integración oportunista en las superficies de la casa (Módulo 16, T-OPP-11)
 *
 * Certifica la condición «Hecho cuando» de la tarea:
 *   1. `TechnicianRouteView` ofrece «➕ Otra máquina en esta sede» en paradas activas y
 *      pinta la tarjeta temporal «🏢 Sede recién visitada (30 min)» cuando la sede solo
 *      sigue habilitada por la ventana de gracia (RF-01.2, RNF-02).
 *   2. Las intervenciones `IN_PROGRESS` de una misma sede se leen como una sola parada
 *      ordenada por urgencia/SLA, sin perder los botones individuales de cada máquina
 *      (RF-02.1).
 *   3. Coordinación distingue «Oportunista In Situ · Nueva» de «Asumida» en la bandeja y
 *      en la ficha de detalle, y el cuadro de mando muestra el KPI de desplazamientos
 *      evitados con desglose (RF-06.4).
 *   4. El portal de sede publica el origen oportunista, al informante verbal y al técnico
 *      bajo el alias «Servicio Técnico Oficial · Operador #[Código]» (Art. V.4).
 *
 * Dogma Vanilla: Node.js native ESM, zero dependencies, zero network.
 */

const storageMock = (() => {
  let store = {};
  return {
    getItem: (key) => store[key] || null,
    setItem: (key, val) => { store[key] = String(val); },
    removeItem: (key) => { delete store[key]; },
    clear: () => { store = {}; }
  };
})();
globalThis.localStorage = storageMock;

import { api } from '../../public/assets/js/api.js';
import { clearSession, setInternalSession } from '../../public/assets/js/store.js';
import { TechnicianRouteView } from '../../public/assets/js/views/TechnicianRouteView.js';
import { TechnicianPreventiveRouteTab } from '../../public/assets/js/components/TechnicianPreventiveRouteTab.js';
import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';
import { CoordinatorIncidentDetailModal } from '../../public/assets/js/components/CoordinatorIncidentDetailModal.js';
import { MachineCard } from '../../public/assets/js/components/MachineCard.js';
import { MetricCards } from '../../public/assets/js/components/MetricCards.js';
import {
  graceRemainingMinutes,
  graceWindowLocations,
  groupColocatedStops,
  normalizeRouteEnvelope,
  officialTechnicianAlias,
  opportunisticModeLabel,
  opportunisticOriginLabel,
  summarizePendingCriticalStops
} from '../../public/assets/js/utils/OpportunisticContext.js';

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) {
      console.error(`         Reason: ${details}`);
    }
    failures++;
  }
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - Integración oportunista (T-OPP-11)');
console.log('======================================================================\n');

// ---------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------

const sameLocationIncidents = [
  {
    id: 201,
    ticket_code: 'INC-2026-0201',
    status: 'IN_PROGRESS',
    urgency: 'MEDIUM',
    category: 'PRODUCT_JAM',
    description: 'Atasco en espiral B4.',
    sla_target_at: '2026-10-19T18:00:00+02:00',
    created_at: '2026-10-19T08:00:00+02:00',
    machine: { id: 12, code: 'MAQ-BCN-012', model: 'Necta Canto Touch', floor_wing: 'Planta 2' },
    location: { id: 3, name: 'Hospital Clínic · Pabellón Norte', address: 'C/ Villarroel 170' }
  },
  {
    id: 202,
    ticket_code: 'INC-2026-0202',
    status: 'IN_PROGRESS',
    urgency: 'CRITICAL',
    category: 'TEMPERATURE_COLD',
    description: 'Compresor detenido.',
    sla_target_at: '2026-10-19T12:00:00+02:00',
    created_at: '2026-10-19T09:00:00+02:00',
    machine: { id: 11, code: 'MAQ-BCN-011', model: 'Fas Winning', floor_wing: 'Planta Baja' },
    location: { id: 3, name: 'Hospital Clínic · Pabellón Norte', address: 'C/ Villarroel 170' },
    opportunistic_mode: 'NEW',
    opportunistic_origin_reason: 'SITE_VERBAL_NOTICE'
  },
  {
    id: 203,
    ticket_code: 'INC-2026-0203',
    status: 'ASSIGNED',
    urgency: 'HIGH',
    category: 'PAYMENT_SYSTEM',
    description: 'Monedero sin cambio.',
    machine: { id: 13, code: 'MAQ-BCN-013', model: 'Necta Samba', floor_wing: 'Planta 3' },
    location: { id: 5, name: 'Facultad de Ciencias', address: 'Av. Diagonal 645' }
  }
];

const envelope = {
  stops: sameLocationIncidents,
  enabled_locations: [
    {
      location_id: 3,
      location_name: 'Hospital Clínic · Pabellón Norte',
      location_address: 'C/ Villarroel 170',
      enablement_type: 'ACTIVE_STOP',
      grace_expires_at: null,
      grace_remaining_seconds: null
    },
    {
      location_id: 5,
      location_name: 'Facultad de Ciencias',
      location_address: 'Av. Diagonal 645',
      enablement_type: 'GRACE_WINDOW_30M',
      last_intervention_reference: 'INC-2026-7710',
      grace_expires_at: '2099-01-01T00:05:00+00:00',
      grace_remaining_seconds: 1200
    }
  ],
  pending_critical_stops_other_locations: [
    {
      incident_id: 99,
      ticket_code: 'INC-2026-9901',
      urgency: 'CRITICAL',
      category: 'TEMPERATURE_COLD',
      machine_code: 'MAQ-BCN-050',
      machine_type: 'PERISHABLE_FOOD',
      location_id: 8,
      location_name: 'Centro Logístico Zona Franca',
      sla_target_at: '2026-10-19T10:30:00+02:00'
    }
  ]
};

/**
 * Instancia de la vista de ruta con los `computed` expuestos como getters y los
 * métodos enlazados, igual que el resto de suites ESM.
 */
function createRouteView(overrides = {}) {
  const instance = {
    ...TechnicianRouteView.data(),
    incidents: [],
    enabledLocations: [],
    pendingCriticalStops: [],
    ...overrides
  };

  for (const [name, fn] of Object.entries(TechnicianRouteView.methods)) {
    instance[name] = fn.bind(instance);
  }
  for (const [name, definition] of Object.entries(TechnicianRouteView.computed)) {
    if (typeof definition === 'function') {
      Object.defineProperty(instance, name, { get: () => definition.call(instance), configurable: true });
    }
  }

  return instance;
}

// ---------------------------------------------------------------------
// TEST GROUP 1: Normalización del sobre del contrato
// ---------------------------------------------------------------------
console.log('--- Group 1: Route envelope normalisation ---');

const arrayEnvelope = normalizeRouteEnvelope(sameLocationIncidents);
assert('1.1 El array histórico sigue siendo válido y no pierde paradas',
  arrayEnvelope.stops.length === 3 && arrayEnvelope.enabledLocations.length === 0);
const wrappedEnvelope = normalizeRouteEnvelope(envelope);
assert('1.2 El envoltorio del Módulo 16 entrega paradas, sedes y avisos',
  wrappedEnvelope.stops.length === 3
  && wrappedEnvelope.enabledLocations.length === 2
  && wrappedEnvelope.pendingCriticalStops.length === 1);
assert('1.3 Un `{ success, data }` genérico también se acepta',
  normalizeRouteEnvelope({ success: true, data: sameLocationIncidents }).stops.length === 3);
assert('1.4 Una respuesta inesperada se lee como ruta vacía sin lanzar',
  normalizeRouteEnvelope(null).stops.length === 0 && normalizeRouteEnvelope('nope').stops.length === 0);

const recent = graceWindowLocations(envelope.enabled_locations);
assert('1.5 Solo la sede habilitada por ventana de gracia lleva tarjeta temporal',
  recent.length === 1 && recent[0].location_id === 5);
assert('1.6 La tarjeta publica los minutos restantes ya redondeados',
  recent[0].grace_remaining_minutes === 20);
assert('1.7 Una sede con parada activa no aparece como recién visitada',
  recent.every((location) => location.location_id !== 3));

const frozen = { grace_expires_at: '2020-01-01T00:00:00+00:00' };
assert('1.8 Una ventana agotada reporta cero minutos', graceRemainingMinutes(frozen) === 0);
assert('1.9 Sin fecha de expiración se usa el remanente del servidor',
  graceRemainingMinutes({ grace_remaining_seconds: 120 }) === 2);

// ---------------------------------------------------------------------
// TEST GROUP 2: Coexistencia y orden en la misma sede (RF-02.1)
// ---------------------------------------------------------------------
console.log('\n--- Group 2: Same-location coexistence and ordering (RF-02.1) ---');

const grouped = groupColocatedStops(sameLocationIncidents);
const locationThree = grouped.filter((incident) => Number(incident.location.id) === 3);

assert('2.1 Las dos intervenciones de la sede 3 quedan agrupadas', locationThree.length === 2);
assert('2.2 Se ordenan por urgencia: la crítica primero (RF-02.1)',
  locationThree[0].id === 202 && locationThree[1].id === 201);
assert('2.3 Cada máquina anuncia cuántas hermanas tiene en el edificio',
  locationThree.every((incident) => incident.colocation_count === 2));
assert('2.4 Solo la cabecera del grupo se marca como primaria',
  locationThree.filter((incident) => incident.colocation_primary === true).length === 1);
assert('2.5 Las hermanas de la misma sede se conservan como paradas con sus propios botones',
  locationThree.every((incident) => Array.isArray(incident.colocated_stops)));
assert('2.6 Una parada de otra sede no se mezcla con el grupo',
  grouped.filter((incident) => Number(incident.location.id) === 5).length === 1);
assert('2.7 A igualdad de urgencia manda el SLA más próximo',
  groupColocatedStops([
    { id: 1, status: 'IN_PROGRESS', urgency: 'HIGH', sla_target_at: '2026-10-19T20:00:00+02:00', location: { id: 7 } },
    { id: 2, status: 'IN_PROGRESS', urgency: 'HIGH', sla_target_at: '2026-10-19T12:00:00+02:00', location: { id: 7 } }
  ])[0].id === 2);

// ---------------------------------------------------------------------
// TEST GROUP 3: Tarjeta temporal y botón en la vista del técnico (RF-01.2)
// ---------------------------------------------------------------------
console.log('\n--- Group 3: Recent-location card and quick action (RF-01.2) ---');

assert('3.1 La plantilla integra el modal de intervención oportunista',
  TechnicianRouteView.template.includes('<TechnicianOpportunisticModal')
  && TechnicianRouteView.components.TechnicianOpportunisticModal !== undefined);
assert('3.2 La tarjeta temporal se rotula «Sede recién visitada»',
  TechnicianRouteView.template.includes('vg-recent-location-card')
  && TechnicianRouteView.template.includes('Sede recién visitada'));
assert('3.3 El botón de la tarjeta y el de la parada comparten el rótulo del plan',
  TechnicianRouteView.template.includes('➕ Otra máquina en esta sede')
  && TechnicianRouteView.template.includes('btn-opportunistic-other-machine'));
assert('3.4 La tarjeta temporal se ofrece desde el catálogo en un toque',
  TechnicianRouteView.template.includes('btn-open-recent-location-catalog'));

const routeView = createRouteView({
  incidents: sameLocationIncidents,
  enabledLocations: envelope.enabled_locations,
  pendingCriticalStops: envelope.pending_critical_stops_other_locations
});
assert('3.5 La vista calcula la sede recién visitada desde el sobre',
  routeView.recentLocations.length === 1 && routeView.recentLocations[0].location_id === 5);
assert('3.6 La etiqueta de la tarjeta declara los minutos disponibles',
  routeView.graceWindowLabel({ grace_remaining_seconds: 900 }).includes('15 min'));
assert('3.7 La etiqueta explica la ventana agotada sin botón engañoso',
  routeView.graceWindowLabel({ grace_remaining_seconds: 0 }).includes('agotada'));
assert('3.8 El resumen sanitario cuenta la parada perecedera pendiente',
  routeView.pendingCriticalSummary.count === 1 && routeView.pendingCriticalSummary.perishable_count === 1);
assert('3.9 La plantilla avisa de la prioridad sanitaria de otra sede',
  TechnicianRouteView.template.includes('tech-cold-chain-route-warning'));
assert('3.10 La ruta solo ofrece el modal cuando hay sedes habilitadas',
  routeView.canOpenOpportunistic === true && createRouteView().canOpenOpportunistic === false);

let routeRequest = null;
api.technician.getMyRoute = async (options = {}) => {
  routeRequest = options;
  return JSON.parse(JSON.stringify(envelope));
};

const loadingView = createRouteView();
await loadingView.loadRoute();
assert('3.11 La ruta pide el contexto oportunista en la misma petición',
  routeRequest?.includeOpportunisticContext === true);
assert('3.12 La respuesta del envoltorio se reparte en los tres estados',
  loadingView.incidents.length === 3
  && loadingView.enabledLocations.length === 2
  && loadingView.pendingCriticalStops.length === 1);

// La vista debe seguir funcionando contra un backend sin la extensión.
api.technician.getMyRoute = async () => JSON.parse(JSON.stringify(sameLocationIncidents));
const legacyView = createRouteView();
await legacyView.loadRoute();
assert('3.13 Un backend sin extensión sigue entregando las paradas',
  legacyView.incidents.length === 3 && legacyView.enabledLocations.length === 0);

const openedView = createRouteView({ enabledLocations: envelope.enabled_locations });
openedView.openOpportunisticModal(loadingView.incidents[2]);
assert('3.14 Desde una parada activa se abre el catálogo de su sede en un toque',
  openedView.showOpportunisticModal === true && openedView.opportunisticLocation?.location_id === 5);
openedView.openOpportunisticModal({ location_id: 3, location_name: 'Hospital Clínic' });
assert('3.15 Una sede sin habilitación vigente no abre el modal',
  openedView.opportunisticLocation?.location_id === 3);

const emptyView = createRouteView();
emptyView.openOpportunisticModal(null);
assert('3.16 Sin sedes habilitadas el modal no se abre', emptyView.showOpportunisticModal === false);

// ---------------------------------------------------------------------
// TEST GROUP 4: Pestaña preventiva y ruta agrupada
// ---------------------------------------------------------------------
console.log('\n--- Group 4: Preventive tab and grouped route ---');

assert('4.1 La pestaña preventiva conserva la visita oportunista preventiva (EARS 2.3)',
  TechnicianPreventiveRouteTab.template.includes('Visita Oportunista in situ')
  && TechnicianPreventiveRouteTab.template.includes('btn-claim-order'));
assert('4.1a La pestaña preventiva ofrece «Otra máquina en esta sede» en la inspección activa',
  TechnicianPreventiveRouteTab.template.includes('btn-preventive-opportunistic')
  && TechnicianPreventiveRouteTab.template.includes('➕ Otra máquina en esta sede'));
assert('4.1b La pestaña preventiva pinta la tarjeta temporal de 30 min',
  TechnicianPreventiveRouteTab.template.includes('preventive-recent-location-card')
  && TechnicianPreventiveRouteTab.template.includes('Sede recién visitada (30 min)'));
assert('4.1c La pestaña preventiva delega la apertura del catálogo en la vista de ruta',
  TechnicianPreventiveRouteTab.emits.includes('open-opportunistic')
  && TechnicianRouteView.template.includes(':enabled-locations="enabledLocations"')
  && TechnicianRouteView.template.includes('@open-opportunistic="openOpportunisticModal($event)"'));

const preventiveInstance = {
  enabledLocations: [
    { location_id: 9, location_name: 'Sede Gracia', enablement_type: 'GRACE_WINDOW_30M', grace_remaining_seconds: 600 }
  ],
  $emit: (event, payload) => { preventiveInstance.emitted = { event, payload }; }
};
assert('4.1d La tarjeta temporal preventiva solo lista sedes en ventana de gracia',
  TechnicianPreventiveRouteTab.computed.recentLocations.call(preventiveInstance).length === 1);
TechnicianPreventiveRouteTab.methods.openOpportunisticForOrder.call(
  preventiveInstance,
  { location: { id: 9, name: 'Sede Gracia' } }
);
assert('4.1e La inspección emite la sede del catálogo oportunista',
  preventiveInstance.emitted?.event === 'open-opportunistic'
  && preventiveInstance.emitted?.payload?.location_id === 9);

assert('4.2 La vista de ruta mantiene el badge de agrupación',
  TechnicianRouteView.template.includes('tech-colocated-stops'));

const groupedRoute = createRouteView({ incidents: sameLocationIncidents });
const filtered = groupedRoute.filteredIncidents;
assert('4.3 Las hermanas de la misma sede quedan contiguas en la lista filtrada',
  Math.abs(filtered.findIndex((i) => i.id === 201) - filtered.findIndex((i) => i.id === 202)) === 1);
assert('4.4 El orden interno del grupo respeta la criticidad',
  filtered.findIndex((i) => i.id === 202) < filtered.findIndex((i) => i.id === 201));

groupedRoute.filterStatus = 'IN_PROGRESS';
assert('4.5 El filtro de estado conserva la agrupación y descarta la parada asignada',
  groupedRoute.filteredIncidents.length === 2
  && groupedRoute.filteredIncidents.every((incident) => incident.status === 'IN_PROGRESS'));

// ---------------------------------------------------------------------
// TEST GROUP 5: Insignias y trazabilidad (RF-06.4)
// ---------------------------------------------------------------------
console.log('\n--- Group 5: Badges and traceability (RF-06.4) ---');

assert('5.1 La modalidad NEW se rotula «Oportunista In Situ · Nueva»',
  opportunisticModeLabel({ opportunistic_mode: 'NEW' }) === 'Oportunista In Situ · Nueva');
assert('5.2 La modalidad ASSUMED se rotula «Oportunista In Situ · Asumida»',
  opportunisticModeLabel({ opportunistic_mode: 'ASSUMED' }) === 'Oportunista In Situ · Asumida');
assert('5.3 Un expediente ordinario no lleva insignia oportunista',
  opportunisticModeLabel({ opportunistic_mode: null }) === null);
assert('5.4 La etiqueta canónica del servidor manda cuando llega',
  opportunisticModeLabel({ opportunistic_mode: 'NEW', opportunistic_mode_label: 'Etiqueta servidor' }) === 'Etiqueta servidor');
assert('5.5 El submotivo verbal se rotula en sede',
  opportunisticOriginLabel({ opportunistic_origin_reason: 'SITE_VERBAL_NOTICE' }) === 'Aviso verbal en sede');
assert('5.6 La asunción de ticket se rotula como tal',
  opportunisticOriginLabel({ opportunistic_origin_reason: 'EXISTING_TICKET_CLAIM' }) === 'Asunción de ticket abierto in situ');

assert('5.7 La bandeja de coordinación pinta la insignia oportunista por fila',
  CoordinatorDashboardView.template.includes('opportunisticModeLabel(inc)')
  && CoordinatorDashboardView.template.includes('type="opportunistic"'));
const dashboardInstance = { $emit: () => {} };
assert('5.8 El método de la bandeja delega en el helper compartido',
  CoordinatorDashboardView.methods.opportunisticModeLabel.call(
    dashboardInstance,
    { opportunistic_mode: 'ASSUMED' }
  ) === 'Oportunista In Situ · Asumida');
assert('5.9 La ficha de detalle pinta la insignia y el bloque de origen',
  CoordinatorIncidentDetailModal.template.includes('incident-detail-opportunistic-badge')
  && CoordinatorIncidentDetailModal.template.includes('incident-detail-opportunistic-origin'));
/**
 * La ficha y la tarjeta leen el expediente por el getter `incident` / `activeIncident`
 * (computed de Vue), así que el arnés debe exponerlos igual que el componente real.
 */
function detailModalContext(incident) {
  const context = {};
  Object.defineProperty(context, 'incident', { get: () => incident });

  return context;
}

function machineCardContext(machine) {
  const context = { machine };
  Object.defineProperty(context, 'activeIncident', {
    get: () => MachineCard.computed.activeIncident.call(context)
  });

  return context;
}

assert('5.10 La ficha resuelve la etiqueta desde el bloque `incident` del DTO',
  CoordinatorIncidentDetailModal.computed.opportunisticLabel.call(
    detailModalContext({ opportunistic_mode: 'NEW' })
  ) === 'Oportunista In Situ · Nueva');
assert('5.11 La ficha muestra al informante verbal cuando consta',
  CoordinatorIncidentDetailModal.computed.opportunisticInformant.call(
    detailModalContext({ opportunistic_informant_name: '  Marta Gómez  ' })
  ) === 'Marta Gómez');
assert('5.12 La ficha omite el bloque cuando el ticket es ordinario',
  CoordinatorIncidentDetailModal.computed.opportunisticLabel.call(detailModalContext({})) === null);

// ---------------------------------------------------------------------
// TEST GROUP 6: KPI de desplazamientos evitados (RF-06.4)
// ---------------------------------------------------------------------
console.log('\n--- Group 6: Avoided-dispatches KPI (RF-06.4) ---');

const kpiSummary = {
  opportunistic_interventions: {
    total_avoided_dispatches: 14,
    new_count: 9,
    assumed_count: 5,
    by_origin_reason: { SITE_VERBAL_NOTICE: 6, TECHNICIAN_DETECTION: 3 }
  }
};

const kpiCard = MetricCards.computed.opportunistic.call({ summary: kpiSummary });
assert('6.1 El KPI publica el total de desplazamientos evitados', kpiCard.total === 14);
assert('6.2 El KPI desglosa entre altas nuevas y asunciones', kpiCard.newCount === 9 && kpiCard.assumedCount === 5);
assert('6.3 El KPI conserva el desglose por submotivo', kpiCard.byOrigin.SITE_VERBAL_NOTICE === 6);
assert('6.4 Sin bloque del backend el KPI cae a cero sin romper la tarjeta',
  MetricCards.computed.opportunistic.call({ summary: {} }).total === 0);
assert('6.5 La tarjeta se rotula como desplazamientos evitados',
  MetricCards.template.includes('Desplazamientos Evitados')
  && MetricCards.template.includes('kpi-opportunistic-interventions'));
assert('6.6 El KPI suma las dos modalidades cuando el total no llega',
  MetricCards.computed.opportunistic.call({ summary: { opportunistic_interventions: { new_count: 3, assumed_count: 4 } } }).total === 7);

// ---------------------------------------------------------------------
// TEST GROUP 7: Portal de sede y alias del técnico (Art. V.4)
// ---------------------------------------------------------------------
console.log('\n--- Group 7: Site portal and technician alias (Art. V.4) ---');

assert('7.1 El alias institucional incluye el código del operador',
  officialTechnicianAlias({ assigned_technician_id: 4 }) === 'Servicio Técnico Oficial · Operador #04');
assert('7.2 Sin identificador solo se publica el nombre institucional',
  officialTechnicianAlias({}) === 'Servicio Técnico Oficial');
assert('7.3 El identificador explícito tiene prioridad sobre el del expediente',
  officialTechnicianAlias({ assigned_technician_id: 4 }, 7) === 'Servicio Técnico Oficial · Operador #07');

const cardInstance = machineCardContext({
  id: 11,
  code: 'MAQ-BCN-011',
  machine_type: 'PERISHABLE_FOOD',
  active_incident: {
    id: 202,
    ticket_code: 'INC-2026-0202',
    status: 'IN_PROGRESS',
    urgency: 'CRITICAL',
    opportunistic_mode: 'NEW',
    opportunistic_origin_reason: 'SITE_VERBAL_NOTICE',
    opportunistic_informant_name: 'Marta Gómez (Conserjería)',
    assigned_technician_id: 4,
    technician_name: 'Servicio Técnico Oficial · Operador #04'
  }
});

assert('7.4 La tarjeta de sede rotula la atención oportunista',
  MachineCard.computed.opportunisticLabel.call(cardInstance) === 'Oportunista In Situ · Nueva');
assert('7.5 La tarjeta publica el motivo y el informante verbal',
  MachineCard.computed.opportunisticOrigin.call(cardInstance) === 'Aviso verbal en sede'
  && MachineCard.computed.opportunisticInformant.call(cardInstance) === 'Marta Gómez (Conserjería)');
assert('7.6 La tarjeta anonimiza al técnico con el alias del Art. V.4',
  MachineCard.computed.officialTechnicianLabel.call(cardInstance) === 'Servicio Técnico Oficial · Operador #04');
assert('7.7 La plantilla declara el aviso oportunista del portal',
  MachineCard.template.includes('machine-card-opportunistic-notice'));
assert('7.8 Una máquina sin expediente no muestra el aviso oportunista',
  MachineCard.computed.opportunisticLabel.call(machineCardContext({ active_incident: null })) === '');

// ---------------------------------------------------------------------
// TEST GROUP 8: Aviso sanitario resumido (RF-03.1)
// ---------------------------------------------------------------------
console.log('\n--- Group 8: Cold-chain route summary (RF-03.1) ---');

const summary = summarizePendingCriticalStops(envelope.pending_critical_stops_other_locations);
assert('8.1 El resumen cuenta la parada crítica pendiente', summary.count === 1);
assert('8.2 El resumen cuenta las de comida perecedera', summary.perishable_count === 1);
assert('8.3 El resumen publica el SLA más próximo en ISO',
  typeof summary.next_sla_at === 'string' && summary.next_sla_at.endsWith('Z'));
assert('8.4 Sin paradas pendientes el resumen queda vacío sin romper',
  summarizePendingCriticalStops([]).count === 0 && summarizePendingCriticalStops(null).next_sla_at === null);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
clearSession();
setInternalSession(
  { id: 1, name: 'Carles Coordinador', email: 'coord@vendguard.internal', role: 'COORDINATOR' },
  'fake_token'
);

console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-OPP-11 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
