/**
 * VendGuard - CoordinatorDashboardView assignment panel Test Suite
 *
 * Certifica el panel de asignación asistida de la bandeja de triaje (T-ZONE-12,
 * Módulo 17): sugerencia destacada, despacho en dos toques, banner sanitario y
 * justificación obligatoria del descarte de frío (Art. V.1).
 *
 * Hecho cuando:
 * 1. Al abrir el modal se pide el ranking explicado y se preselecciona al sugerido
 *    cuando la avería aún no tiene responsable.
 * 2. El primer puesto se destaca con su desglose e insignias y ofrece
 *    `[⚡ Asignar Sugerido]`: abrir el modal + un toque = despacho (RNF-02).
 * 3. Elegir a otro técnico en una avería con escalado sanitario despliega
 *    `override_justification`, cuenta caracteres reales y bloquea por debajo de 20.
 * 4. Una asignación válida envía `override_justification` junto a los parámetros
 *    existentes (urgencia y `reassignment_reason` intactos).
 * 5. Fuera del escalado de frío no se exige justificación adicional (RF-05.2).
 * 6. Un fallo del recomendador no bloquea el despacho manual (RF-06.3).
 *
 * Dogma Vanilla: Node.js nativo, ESM y cero dependencias externas.
 */

// Entorno mínimo de navegador para el transporte y el store global.
const storageMock = (() => {
  let store = {};
  return {
    getItem: (key) => store[key] || null,
    setItem: (key, value) => { store[key] = String(value); },
    removeItem: (key) => { delete store[key]; },
    clear: () => { store = {}; }
  };
})();
globalThis.localStorage = storageMock;
globalThis.window = {
  location: { search: '', href: 'http://localhost/' },
  addEventListener: () => {},
  removeEventListener: () => {},
  dispatchEvent: () => true
};

import { api } from '../../public/assets/js/api.js';
import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) {
      console.error(`         Motivo: ${details}`);
    }
    failures++;
  }
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - Coordinator assign panel (T-ZONE-12)');
console.log('======================================================================\n');

// --------------------------------------------------------------------
// Fixtures del contrato §3.6
// --------------------------------------------------------------------

const makeIncident = (overrides = {}) => ({
  id: 42,
  ticket_code: 'INC-20261020-0042',
  machine_code: 'VEND-0101',
  urgency: 'CRITICAL',
  status: 'REGISTERED',
  assigned_technician_id: null,
  ...overrides
});

const makeColdChainSuggestions = () => ({
  incident: {
    id: 42,
    ticket_code: 'INC-20261020-0042',
    priority: 'CRITICAL',
    is_cold_chain_risk: true,
    location: { id: 4, site_code: 'SEDE-BCN-01', name: 'Hospital del Mar', postal_code: '08005', zone_id: 1, zone_name: 'Barcelona Norte' }
  },
  recommendation: {
    top_technician_id: 7,
    is_cold_chain_escalation: true,
    unattended_zone: false,
    explanation: '🚨 Prioridad Sanitaria Art. II: Titular saturado; técnico de guardia 24/7 antepuesto para asegurar SLA < 4h.'
  },
  ranked_technicians: [
    {
      technician_id: 7,
      name: 'Marta Guardia',
      operator_code: 'OP-07',
      is_on_call: true,
      affinity_type: 'PRIMARY',
      zone_affinity_label: 'Titular de zona',
      total_score: 140,
      score_breakdown: {
        territorial_affinity: 50,
        onsite_presence: 0,
        same_zone_stops: 0,
        active_workload_penalty: -10,
        inter_location_penalty: 0,
        cold_chain_on_call_bonus: 100
      },
      active_workload: { active_tickets_count: 2, pending_info_count: 0, preventive_orders_count: 0 },
      current_status: { has_in_progress_stop: false, in_progress_location_name: null, distance_km: 1.4 },
      is_top_suggestion: true,
      badges: ['RECOMENDADO', 'GUARDIA_24_7', 'TITULAR_ZONA']
    },
    {
      technician_id: 5,
      name: 'Jordi Ruta',
      operator_code: 'OP-05',
      is_on_call: false,
      affinity_type: 'PRIMARY',
      zone_affinity_label: 'Titular de zona',
      total_score: 25,
      score_breakdown: {
        territorial_affinity: 50,
        onsite_presence: 0,
        same_zone_stops: 20,
        active_workload_penalty: -30,
        inter_location_penalty: -15,
        cold_chain_on_call_bonus: 0
      },
      active_workload: { active_tickets_count: 5, pending_info_count: 1, preventive_orders_count: 1 },
      current_status: { has_in_progress_stop: true, in_progress_location_name: 'Torre Glòries', distance_km: 3.8 },
      is_top_suggestion: false,
      badges: ['TITULAR_ZONA', 'EN_CURSO_OTRA_SEDE']
    }
  ]
});

const makeOrdinarySuggestions = () => {
  const payload = makeColdChainSuggestions();
  payload.incident.is_cold_chain_risk = false;
  payload.recommendation.is_cold_chain_escalation = false;
  payload.recommendation.explanation = '⭐ Sugerido por Zona: titular con mayor puntuación y carga equilibrada.';
  payload.ranked_technicians[0].badges = ['RECOMENDADO', 'TITULAR_ZONA'];
  payload.ranked_technicians[0].score_breakdown.cold_chain_on_call_bonus = 0;
  return payload;
};

function createViewInstance() {
  const component = CoordinatorDashboardView;
  const data = component.data();
  const instance = Object.assign(data, component.methods);

  for (const [key, getter] of Object.entries(component.computed || {})) {
    Object.defineProperty(instance, key, {
      get: () => getter.call(instance),
      configurable: true
    });
  }

  instance.emittedEvents = [];
  instance.$emit = (event, value) => instance.emittedEvents.push({ event, value });
  return instance;
}

// Captura de llamadas (sin red).
let suggestionCalls = [];
let assignCalls = [];
let nextSuggestions = makeColdChainSuggestions();
let nextSuggestionError = null;
let nextAssignError = null;

api.coordinator = api.coordinator || {};
api.coordinator.getUsers = async () => [
  { id: 7, name: 'Marta Guardia', email: 'marta.ruta@vendguard.internal' },
  { id: 5, name: 'Jordi Ruta', email: 'jordi.ruta@vendguard.internal' }
];
api.coordinator.getIncidents = async () => [];
api.coordinator.getSuggestedTechnicians = async (incidentId) => {
  suggestionCalls.push(incidentId);
  if (nextSuggestionError) {
    const error = nextSuggestionError;
    nextSuggestionError = null;
    throw error;
  }
  return nextSuggestions;
};
api.coordinator.assignTechnician = async (...args) => {
  assignCalls.push(args);
  if (nextAssignError) {
    const error = nextAssignError;
    nextAssignError = null;
    throw error;
  }
  return { id: args[0], technician_id: args[1] };
};

const resetCalls = () => { suggestionCalls = []; assignCalls = []; };

// =========================================================================
// BLOQUE 1: Estado inicial y contrato de plantilla
// =========================================================================
console.log('--- BLOQUE 1: Estado inicial y contrato de plantilla ---');

const fresh = createViewInstance();
assert('1.1 El panel arranca sin sugerencia ni justificación', fresh.assignmentSuggestions === null
  && fresh.assignOverrideJustification === ''
  && fresh.isLoadingSuggestions === false
  && fresh.suggestionsError === '');

const template = CoordinatorDashboardView.template;
assert('1.2 La plantilla ofrece el botón directo del sugerido',
  template.includes('data-testid="assign-suggested"')
    && template.includes('⚡ Asignar Sugerido')
    && template.includes('assignSuggestedTechnician'));
assert('1.3 La plantilla publica el banner de emergencia sanitaria', template.includes('data-testid="assign-sanitary-banner"')
  && template.includes('Prioridad Sanitaria Art. II')
  && template.includes('< 4 horas') === false
  && template.includes('&lt; 4 horas'));
assert('1.4 La plantilla pide la justificación ligada al motivo del descarte',
  template.includes('data-testid="assign-override-justification"')
    && template.includes('v-model="assignOverrideJustification"')
    && template.includes('assignOverrideJustificationLength')
    && template.includes('mínimo 20 caracteres'));
assert('1.5 La plantilla conserva el motivo de urgencia y el bloque de error existentes',
  template.includes('assignUrgencyReason') && template.includes('assignError'));
assert('1.6 La plantilla pinta el ranking con sus insignias y el aviso de zona desatendida',
  template.includes('data-testid="assign-ranking"')
    && template.includes('suggestionBadgeLabel(badge)')
    && template.includes('data-testid="assign-unattended-warning"'));

// =========================================================================
// BLOQUE 2: Apertura del modal y carga del ranking (RF-04.1)
// =========================================================================
console.log('\n--- BLOQUE 2: Apertura y carga del ranking ---');

resetCalls();
const panel = createViewInstance();
panel.openAssignModal(makeIncident());
assert('2.1 El modal se abre con el expediente seleccionado',
  panel.showAssignModal === true && panel.selectedIncident.id === 42);
await new Promise((resolve) => setTimeout(resolve, 0));

assert('2.2 La apertura pide el ranking de esa avería', suggestionCalls.length === 1 && suggestionCalls[0] === 42);
assert('2.3 El primer puesto del ranking es el técnico sugerido',
  panel.suggestedTechnician?.technician_id === 7 && panel.suggestedTechnicianId === 7);
assert('2.4 Sin responsable previo, el sugerido queda preseleccionado',
  Number(panel.assignTechnicianId) === 7 && panel.assignIsSuggestedTechnician === true);
assert('2.5 El banner sanitario se enciende con el escalado de frío',
  panel.hasSanitarySuggestionBanner === true
    && panel.isColdChainSuggestion === true
    && panel.isUnattendedZone === false);
assert('2.6 Elegir al sugerido no exige justificación adicional',
  panel.assignRequiresOverrideJustification === false && panel.assignOverrideJustificationValid === true);
assert('2.7 Las insignias del motor se traducen al castellano',
  panel.suggestionBadgeLabel('GUARDIA_24_7') === '🚨 Guardia 24/7'
    && panel.suggestionBadgeLabel('TITULAR_ZONA') === 'Titular de zona'
    && panel.suggestionBadgeLabel('DESCONOCIDA') === 'DESCONOCIDA');
assert('2.8 El desglose explica la puntuación y la distancia de referencia',
  panel.suggestionBreakdown(panel.suggestedTechnician).includes('Guardia +100')
    && panel.suggestionBreakdown(panel.suggestedTechnician).includes('Afinidad +50')
    && panel.suggestionDistance(panel.suggestedTechnician) === '1.4 km');
assert('2.9 La distancia inválida no se inventa', panel.suggestionDistance({ current_status: { distance_km: null } }) === '—');

// =========================================================================
// BLOQUE 3: Salvaguarda sanitaria del descarte (RF-05.2, Art. V.1)
// =========================================================================
console.log('\n--- BLOQUE 3: Salvaguarda sanitaria del descarte ---');

resetCalls();
const overridePanel = createViewInstance();
overridePanel.openAssignModal(makeIncident());
await new Promise((resolve) => setTimeout(resolve, 0));

overridePanel.assignTechnicianId = 5;
overridePanel.onAssignTechnicianChange();
assert('3.1 Descartar al sugerido despliega la justificación obligatoria',
  overridePanel.assignRequiresOverrideJustification === true
    && overridePanel.assignOverrideJustificationValid === false);

await overridePanel.submitAssignment();
assert('3.2 Sin justificación no se llama a la API',
  assignCalls.length === 0 && overridePanel.assignError.includes('20 caracteres'));

overridePanel.assignOverrideJustification = 'x'.repeat(19);
assert('3.3 Diecinueve caracteres reales siguen siendo insuficientes',
  overridePanel.assignOverrideJustificationLength === 19
    && overridePanel.assignOverrideJustificationValid === false);

await overridePanel.submitAssignment();
assert('3.4 Con texto insuficiente tampoco se envía', assignCalls.length === 0);

overridePanel.assignOverrideJustification = 'x'.repeat(20);
assert('3.5 Veinte caracteres reales habilitan la confirmación',
  overridePanel.assignOverrideJustificationLength === 20
    && overridePanel.assignOverrideJustificationValid === true);

overridePanel.assignOverrideJustification = '   Sustitución justificada por frío en la zona sur.   ';
const trimmedLength = overridePanel.assignOverrideJustificationLength;
await overridePanel.submitAssignment();
assert('3.6 El umbral se mide sobre el texto recortado y en puntos de código',
  trimmedLength === 48 && assignCalls.length === 1,
  `longitud=${trimmedLength} llamadas=${assignCalls.length}`);
assert('3.7 La asignación viaja con la justificación como sexto argumento y sin tocar la reasignación',
  assignCalls[0][0] === 42
    && assignCalls[0][1] === 5
    && assignCalls[0][2] === null
    && assignCalls[0][3] === null
    && assignCalls[0][4] === null
    && assignCalls[0][5] === 'Sustitución justificada por frío en la zona sur.');
assert('3.8 Tras confirmar, el modal se cierra y la bandeja se recarga',
  overridePanel.showAssignModal === false
    && overridePanel.emittedEvents.some(event => event.event === 'assigned'));

// Rechazo del servidor: el panel mantiene abierto el formulario y publica el motivo.
resetCalls();
const rejectedPanel = createViewInstance();
rejectedPanel.openAssignModal(makeIncident());
await new Promise((resolve) => setTimeout(resolve, 0));
rejectedPanel.assignTechnicianId = 5;
rejectedPanel.onAssignTechnicianChange();
rejectedPanel.assignOverrideJustification = 'Justificación válida de más de veinte caracteres.';
nextAssignError = {
  code: 'COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED',
  message: 'La justificación del descarte sanitario no alcanza el mínimo constitucional.'
};
await rejectedPanel.submitAssignment();
assert('3.9 El 422 constitucional se muestra junto al campo y no cierra el panel',
  rejectedPanel.assignError.includes('mínimo constitucional') && rejectedPanel.showAssignModal === true);

// Emoji: una unidad UTF-16 no cuenta como dos caracteres reales.
const emojiPanel = createViewInstance();
emojiPanel.openAssignModal(makeIncident());
await new Promise((resolve) => setTimeout(resolve, 0));
emojiPanel.assignTechnicianId = 5;
emojiPanel.onAssignTechnicianChange();
emojiPanel.assignOverrideJustification = '🚨'.repeat(20);
assert('3.10 Veinte emojis son veinte caracteres reales, no cuarenta',
  emojiPanel.assignOverrideJustificationLength === 20
    && emojiPanel.assignOverrideJustificationValid === true);

// =========================================================================
// BLOQUE 4: Despacho en un toque del sugerido (RNF-02)
// =========================================================================
console.log('\n--- BLOQUE 4: Despacho en un toque del sugerido ---');

resetCalls();
const quickPanel = createViewInstance();
quickPanel.openAssignModal(makeIncident());
await new Promise((resolve) => setTimeout(resolve, 0));

await quickPanel.assignSuggestedTechnician();
assert('4.1 El botón directo asigna al primer puesto sin justificación',
  assignCalls.length === 1
    && assignCalls[0][1] === 7
    && assignCalls[0][5] === null);
assert('4.2 Abrir el modal y pulsar el botón bastan: dos interacciones', 
  assignCalls.length === 1 && quickPanel.showAssignModal === false);

// =========================================================================
// BLOQUE 5: Avería ordinaria y resiliencia del recomendador (RF-05.2 / RF-06.3)
// =========================================================================
console.log('\n--- BLOQUE 5: Avería ordinaria y resiliencia ---');

nextSuggestions = makeOrdinarySuggestions();
resetCalls();
const ordinaryPanel = createViewInstance();
ordinaryPanel.openAssignModal(makeIncident({ urgency: 'MEDIUM' }));
await new Promise((resolve) => setTimeout(resolve, 0));
ordinaryPanel.assignTechnicianId = 5;
ordinaryPanel.onAssignTechnicianChange();
assert('5.1 Sin escalado sanitario no se exige justificación adicional',
  ordinaryPanel.isColdChainSuggestion === false
    && ordinaryPanel.assignRequiresOverrideJustification === false
    && ordinaryPanel.hasSanitarySuggestionBanner === false);

await ordinaryPanel.submitAssignment();
assert('5.2 El despacho ordinario no envía override_justification',
  assignCalls.length === 1 && assignCalls[0][5] === null);

nextSuggestions = makeColdChainSuggestions();
resetCalls();
nextSuggestionError = new Error('El recomendador no responde.');
const resilientPanel = createViewInstance();
resilientPanel.openAssignModal(makeIncident());
await new Promise((resolve) => setTimeout(resolve, 0));
resilientPanel.assignTechnicianId = 5;
resilientPanel.onAssignTechnicianChange();
const reportedSuggestionError = resilientPanel.suggestionsError;
await resilientPanel.submitAssignment();
assert('5.3 Un fallo del recomendador se declara sin bloquear el despacho manual',
  reportedSuggestionError.includes('no responde')
    && resilientPanel.assignmentSuggestions === null
    && assignCalls.length === 1
    && assignCalls[0][5] === null,
  `error="${reportedSuggestionError}" llamadas=${assignCalls.length} tecnico=${resilientPanel.assignTechnicianId}`);

// La selección manual no se pisa si el ranking llega después.
suggestionCalls = [];
const latePanel = createViewInstance();
latePanel.selectedIncident = makeIncident({ assigned_technician_id: 9 });
latePanel.showAssignModal = true;
latePanel.assignTechnicianId = 9;
latePanel.assignTechnicianChosenByUser = false;
await latePanel.loadAssignmentSuggestions(42);
assert('5.4 Una avería con responsable vigente conserva su técnico al cargar el ranking',
  Number(latePanel.assignTechnicianId) === 9);

// La respuesta de otro expediente no se pinta en el modal actual.
const switchedPanel = createViewInstance();
switchedPanel.selectedIncident = makeIncident({ id: 99 });
switchedPanel.showAssignModal = true;
await switchedPanel.loadAssignmentSuggestions(42);
assert('5.5 Un ranking de otra avería no contamina el panel abierto',
  switchedPanel.assignmentSuggestions === null);

// =========================================================================
// RESUMEN FINAL
// =========================================================================
console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: ¡TODAS LAS PRUEBAS FRONTEND PASARON EXITOSAMENTE (${assertions} aserciones)!`);
  console.log(' CONDICIÓN T-ZONE-12 (panel de asignación) CUMPLIDA.');
  process.exit(0);
} else {
  console.error(` RESULTADO: ${failures} fallos detectados de ${assertions} aserciones.`);
  process.exit(1);
}
