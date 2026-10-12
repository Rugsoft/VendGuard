/**
 * VendGuard - Frontend Test Suite: CoordinatorSanitarySweepPanel (T-PAUSE-41)
 *
 * Certifica la superficie operativa del barrido sanitario en el panel del coordinador
 * (RF-07.1 a RF-07.5, Art. II y Art. III):
 *
 * 1. **Presentación pura y sin regla propia.** El panel no decide nada: lee el nivel y la
 *    alerta de `requires_attention` —la autoridad única del backend—, pinta el latido con
 *    sus tres lecturas honestas (cargando / nunca corrió / dejó de latir) y la tendencia de
 *    24 h, y emite `refresh` y `open-incident-detail` en lugar de consultar por su cuenta.
 * 2. **Wiring de la vista.** `CoordinatorDashboardView` es la única que pide el payload, lo
 *    pasa al panel y lo usa para el distintivo de la sub-pestaña; la llamada se hace al
 *    montar (para ver el escalado antes de entrar) y una lectura fallida no rompe el triaje.
 * 3. **Disciplina del sistema de diseño.** El bloque no introduce ni un color literal: todo
 *    el color entra por token; los grupos de botones usan la clase envolvente compartida
 *    (T-PAUSE-37).
 *
 * Dogma Vanilla: Node.js nativo con módulos ESM y cero dependencias externas. Casos JS
 * contra Node/CommonJS.
 */

// Entorno de navegador mínimo para los módulos del frontend.
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
globalThis.window = { location: { search: '', href: 'http://localhost/' } };

import { api } from '../../public/assets/js/api.js';
import { CoordinatorSanitarySweepPanel } from '../../public/assets/js/components/CoordinatorSanitarySweepPanel.js';
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
console.log(' VendGuard: Frontend Test Suite - CoordinatorSanitarySweepPanel (T-PAUSE-41)');
console.log('======================================================================\n');

/**
 * Cuerpo del componente con la emisión observable y las propiedades calculadas materializadas
 * como captadores: así un `computed` puede llamar a otro (`bannerLabel` consulta
 * `requiresAttention`) y el grafo de dependencias real del panel se ejercita, no una copia.
 */
const makePanel = (visibility = null) => {
  const panel = {
    visibility,
    isLoading: false,
    errorMessage: '',
    _emitted: {},
    $emit(event, payload) {
      this._emitted[event] = payload === undefined ? true : payload;
    }
  };

  for (const [name, resolver] of Object.entries(CoordinatorSanitarySweepPanel.computed)) {
    Object.defineProperty(panel, name, {
      get() {
        return resolver.call(panel);
      }
    });
  }

  for (const [name, method] of Object.entries(CoordinatorSanitarySweepPanel.methods)) {
    panel[name] = (...args) => method.apply(panel, args);
  }

  return panel;
};

const escalatedPayload = {
  generated_at: '2026-10-12 18:20:00',
  cadence_minutes: 15,
  escalation: {
    level: 'ESCALATED',
    requires_attention: true,
    consecutive_passes_threshold: 3,
    per_pass_threshold: 5,
    open_contentions: 2,
    escalated_contentions: 1,
    fleet_escalation: false,
    level_label: 'Contención persistente: la detección sanitaria se está retrasando'
  },
  sweep: {
    last_pass: {
      pass_id: 412,
      started_at: '2026-10-12 18:15:00',
      finished_at: '2026-10-12 18:15:01',
      candidates: 4,
      evaluated: 2,
      quarantined_count: 1,
      contended_count: 2,
      failures_count: 0,
      duration_ms: 812.34
    },
    minutes_since_last_pass: 5,
    is_stale: false,
    window_hours: 24,
    passes_in_window: 96,
    passes_with_contention: 3,
    deferrals_in_window: 7,
    failures_in_window: 0,
    quarantines_in_window: 31,
    contention_rate_percent: 3.13
  },
  contentions: [
    {
      id: 9,
      incident_id: 142,
      ticket_code: 'INC-2026-00142',
      machine_id: 14,
      machine_code: 'VEND-0101',
      location_name: 'Hospital del Mar',
      floor_wing: 'Planta Baja - Urgencias',
      incident_status: 'IN_PROGRESS',
      machine_sanitary_status: 'OK',
      first_detected_at: '2026-10-12 17:15:00',
      last_detected_at: '2026-10-12 18:15:00',
      consecutive_passes: 5,
      total_passes: 5,
      escalated: true,
      escalated_at: '2026-10-12 17:45:00',
      age_minutes: 65,
      minutes_since_last_detection: 5
    }
  ]
};

// ---------------------------------------------------------------------
// GRUPO 1: Presentación pura del panel
// ---------------------------------------------------------------------
console.log('--- Grupo 1: Presentación del panel ---');

assert(
  '1.1 El panel declara sus propiedades y sus dos emisiones, sin consultar la API por su cuenta',
  Array.isArray(CoordinatorSanitarySweepPanel.props) === false
  && Object.keys(CoordinatorSanitarySweepPanel.props).join(',') === 'visibility,isLoading,errorMessage'
  && CoordinatorSanitarySweepPanel.emits.join(',') === 'refresh,open-incident-detail'
  && typeof CoordinatorSanitarySweepPanel.methods.requestRefresh === 'function'
);

const escalated = makePanel(escalatedPayload);

assert(
  '1.2 El nivel y la alerta se leen del payload: la interfaz no vuelve a decidir cuándo escalar',
  escalated.level === 'ESCALATED'
  && escalated.requiresAttention === true
  && escalated.escalatedCount === 1
  && escalated.openContentions.length === 1
);

const clearPayload = {
  ...escalatedPayload,
  escalation: { ...escalatedPayload.escalation, level: 'CLEAR', requires_attention: false, escalated_contentions: 0 },
  contentions: []
};
const clear = makePanel(clearPayload);

assert(
  '1.3 El payload manda: sin `requires_attention` no hay alerta aunque el nivel dijera otra cosa',
  clear.requiresAttention === false
  && clear.hasContention === false
  && clear.escalation.requires_attention === false
);

const fleetPayload = {
  ...escalatedPayload,
  escalation: {
    ...escalatedPayload.escalation,
    escalated_contentions: 0,
    open_contentions: 0,
    fleet_escalation: true
  }
};

assert(
  '1.4 El distintivo del copy distingue alarma de estado y añade la señal de parque cuando existe',
  escalated.bannerLabel.includes('Contención persistente')
  && escalated.bannerLabel.includes('pasadas seguidas')
  && escalated.bannerLabel.includes('1 expediente(s)')
  && makePanel(fleetPayload).bannerLabel.includes('umbral de parque')
  && clear.bannerLabel.includes('Sin aplazamientos')
);

assert(
  '1.5 El latido se lee en sus cuatro estados: cargando, nunca corrió, dejó de latir y fresco',
  makePanel(null).heartbeatTone === 'neutral'
  && makePanel(null).heartbeatLabel.includes('Cargando')
  && makePanel({ ...clearPayload, sweep: { ...clearPayload.sweep, last_pass: null, minutes_since_last_pass: null } }).neverRan === true
  && makePanel({ ...clearPayload, sweep: { ...clearPayload.sweep, last_pass: null, minutes_since_last_pass: null } }).heartbeatTone === 'warning'
  && makePanel({ ...clearPayload, sweep: { ...clearPayload.sweep, is_stale: true, minutes_since_last_pass: 45 } }).heartbeatTone === 'danger'
  && makePanel({ ...clearPayload, sweep: { ...clearPayload.sweep, is_stale: true, minutes_since_last_pass: 45 } }).heartbeatLabel.includes('no está corriendo')
  && clear.heartbeatTone === 'success'
  && clear.heartbeatLabel.includes('Última pasada hace 5 min')
  && clear.heartbeatLabel.includes('cadencia 15 min')
);

assert(
  '1.6 La tendencia de la ventana se publica tal y como llega, sin recalcular nada',
  clear.window.hours === 24
  && clear.window.passes === 96
  && clear.window.passesWithContention === 3
  && clear.window.deferrals === 7
  && clear.window.failures === 0
  && clear.window.quarantines === 31
  && clear.window.ratePercent === 3.13
  && clear.thresholds.consecutive === 3
  && clear.thresholds.perPass === 5
  && clear.cadenceMinutes === 15
);

assert(
  '1.7 Un payload incompleto o ausente no revienta el panel: cae a valores neutros',
  makePanel({}).openContentions.length === 0
  && makePanel({}).lastPass === null
  && makePanel({}).window.passes === 0
  && makePanel({}).cadenceMinutes === 15
  && makePanel({}).thresholds.consecutive === 3
);

assert(
  '1.8 Los helpers de fila rotulan las pasadas, la antigüedad y el tono del escalado',
  escalated.passesLabel({ consecutive_passes: 1 }) === '1 pasada aplazada'
  && escalated.passesLabel({ consecutive_passes: 4 }) === '4 pasadas aplazadas'
  && escalated.formatAge(45) === '45 min'
  && escalated.formatAge(60) === '1 h'
  && escalated.formatAge(135) === '2 h 15 min'
  && escalated.rowTone({ escalated: true }) === 'danger'
  && escalated.rowTone({ escalated: false }) === 'warning'
);

assert(
  '1.9 El panel emite `refresh` y abre la ficha del expediente con su identificador numérico',
  (escalated.requestRefresh(), escalated._emitted.refresh === true)
  && (escalated.openIncident('142'), escalated._emitted['open-incident-detail'] === 142)
  && (escalated.openIncident(0), escalated._emitted['open-incident-detail'] === 142)
  && (escalated.openIncident('abc'), escalated._emitted['open-incident-detail'] === 142)
);

// ---------------------------------------------------------------------
// GRUPO 2: Contrato de la plantilla y disciplina del sistema de diseño
// ---------------------------------------------------------------------
console.log('\n--- Grupo 2: Plantilla y sistema de diseño ---');

const template = CoordinatorSanitarySweepPanel.template;

assert(
  '2.1 La plantilla monta los cuatro bloques con sus anclas de prueba',
  template.includes('data-testid="sanitary-sweep-panel"')
  && template.includes('data-testid="sweep-escalation-banner"')
  && template.includes('data-testid="sweep-heartbeat"')
  && template.includes('data-testid="sweep-last-pass"')
  && template.includes('data-testid="sweep-window"')
  && template.includes('data-testid="sweep-contentions-empty"')
  && template.includes('data-testid="sweep-contention-row"')
  && template.includes('data-testid="sweep-contention-state"')
  && template.includes('data-testid="sweep-open-incident"')
  && template.includes('data-testid="sweep-visibility-error"')
);

assert(
  '2.2 La alerta y el latido cambian de color por TOKEN, nunca por un literal hexadecimal',
  /#(?:[0-9a-f]{3}){1,2}\b/i.test(template) === false
  && template.includes('var(--color-error-bg)')
  && template.includes('var(--color-error-border)')
  && template.includes('var(--color-warning-text)')
  && template.includes('var(--color-info-bg)')
);

assert(
  '2.3 Los grupos de botones del panel usan la clase envolvente compartida (T-PAUSE-37)',
  template.includes('class="vg-action-group"')
  && template.includes('vg-btn vg-btn-secondary')
  && template.includes(':disabled="isLoading"')
);

assert(
  '2.4 La fila publica el estado del escalado ya resuelto por el dominio y ofrece abrir la ficha',
  template.includes(':data-escalated="contention.escalated ? \'true\' : \'false\'"')
  && template.includes("@click=\"openIncident(contention.incident_id)\"")
  && template.includes('Abrir ficha')
);

// ---------------------------------------------------------------------
// GRUPO 3: Wiring de la vista del coordinador
// ---------------------------------------------------------------------
console.log('\n--- Grupo 3: Wiring de CoordinatorDashboardView ---');

let requestedPath = null;
const originalGet = api.get.bind(api);
api.get = (endpoint) => {
  requestedPath = endpoint;
  return Promise.resolve({ ok: true });
};

const apiResult = await api.coordinator.getSanitarySweepContention();
api.get = originalGet;

assert(
  '3.1 El cliente pide la ruta documentada de la superficie, sin parámetros',
  requestedPath === '/coordinator/sanitary-sweep/contention'
  && apiResult.ok === true
);

const makeView = () => {
  const view = {
    sweepVisibility: null,
    isLoadingSweepVisibility: false,
    sweepVisibilityError: ''
  };

  // Sólo los accesores que la superficie usa de verdad: la vista completa trae decenas de
  // propiedades calculadas que dependen de datos del triaje y no aportan nada aquí.
  for (const name of ['sweepEscalationCount', 'sweepOpenContentionCount']) {
    Object.defineProperty(view, name, {
      get() {
        return CoordinatorDashboardView.computed[name].call(view);
      }
    });
  }

  view.loadSanitarySweepVisibility = () =>
    CoordinatorDashboardView.methods.loadSanitarySweepVisibility.call(view);

  return view;
};

const view = makeView();
api.get = () => Promise.resolve({ escalation: { requires_attention: true, escalated_contentions: 2, open_contentions: 3 } });
await view.loadSanitarySweepVisibility();

assert(
  '3.2 La vista guarda el payload, apaga el cargando y no deja error',
  view.sweepVisibility?.escalation?.open_contentions === 3
  && view.isLoadingSweepVisibility === false
  && view.sweepVisibilityError === ''
);

assert(
  '3.3 El distintivo de la sub-pestaña se deriva del payload del backend, no de una regla propia',
  view.sweepEscalationCount === 2
  && view.sweepOpenContentionCount === 3
);

const quietView = makeView();
api.get = () => Promise.resolve({ escalation: { requires_attention: false, escalated_contentions: 0, open_contentions: 0 } });
await quietView.loadSanitarySweepVisibility();

assert(
  '3.4 Sin atención requerida el distintivo no se pinta aunque hubiera recuentos',
  quietView.sweepEscalationCount === 0
);

const failingView = makeView();
api.get = () => { throw new Error('La superficie del barrido no está disponible.'); };
const failureCaught = await failingView.loadSanitarySweepVisibility().then(() => null).catch((err) => err);

assert(
  '3.5 Una lectura fallida se reporta en el panel y NO rompe el triaje (no se propaga)',
  failureCaught === null
  && failingView.sweepVisibilityError.includes('no está disponible')
  && failingView.isLoadingSweepVisibility === false
);

api.get = originalGet;

assert(
  '3.6 La vista monta el panel, le pasa el payload y reutiliza el modal de detalle',
  Object.keys(CoordinatorDashboardView.components).includes('CoordinatorSanitarySweepPanel')
  && CoordinatorDashboardView.template.includes('<CoordinatorSanitarySweepPanel')
  && CoordinatorDashboardView.template.includes("activePreventiveSubTab === 'sweep'")
  && CoordinatorDashboardView.template.includes(':visibility="sweepVisibility"')
  && CoordinatorDashboardView.template.includes(':is-loading="isLoadingSweepVisibility"')
  && CoordinatorDashboardView.template.includes(':error-message="sweepVisibilityError"')
  && CoordinatorDashboardView.template.includes('@refresh="loadSanitarySweepVisibility"')
  && CoordinatorDashboardView.template.includes('@open-incident-detail="openIncidentDetailFromPreventive"')
);

assert(
  '3.7 El latido se pide al montar y tras autenticarse: el escalado se ve antes de abrir la sub-pestaña',
  CoordinatorDashboardView.template.includes('data-testid="subtab-preventive-sweep"')
  && CoordinatorDashboardView.template.includes('data-testid="subtab-preventive-sweep-badge"')
  && CoordinatorDashboardView.template.includes('v-if="sweepEscalationCount > 0"')
  && String(CoordinatorDashboardView.mounted).includes('loadSanitarySweepVisibility')
  && String(CoordinatorDashboardView.methods.handleInternalLogin).includes('loadSanitarySweepVisibility')
);

console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. T-PAUSE-41 FRONTEND CONDITIONS FULFILLED.');
  console.log('======================================================================');
  process.exit(0);
}

console.log(` RESULT: ${failures} FAILURE(S) DETECTED.`);
console.log('======================================================================');
process.exit(1);
