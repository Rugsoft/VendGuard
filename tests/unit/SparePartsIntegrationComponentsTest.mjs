/**
 * VendGuard - Spare Parts Integration Components Test Suite (SparePartsIntegrationComponentsTest.mjs)
 * 
 * Valida los requisitos de la tarea T-SPARE-17:
 * 1. TechnicianChecklistModal.js: integración de repuestos sustituidos en checklist preventivo al finalizar inspección.
 * 2. CoordinatorDashboardView.js: registro de pestañas repuestos y analitica-repuestos respetando docs/design.md.
 * 3. TechnicianRouteView.js: enlace de TechnicianSparePartsPauseModal.js y TechnicianResolutionPartsBlock.js.
 * 
 * Dogma Vanilla: Node.js nativo con módulos ESM y cero dependencias externas.
 */

// Mock de entorno navegador para Node.js ESM
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
globalThis.window = {
  location: { search: '', href: 'http://localhost/' }
};

import { api } from '../../public/assets/js/api.js';
import { TechnicianChecklistModal } from '../../public/assets/js/components/TechnicianChecklistModal.js';
import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';
import { TechnicianRouteView } from '../../public/assets/js/views/TechnicianRouteView.js';
import { TechnicianResolutionPartsBlock } from '../../public/assets/js/components/TechnicianResolutionPartsBlock.js';
import { TechnicianSparePartsPauseModal } from '../../public/assets/js/components/TechnicianSparePartsPauseModal.js';
import { CoordinatorSparePartsTab } from '../../public/assets/js/components/CoordinatorSparePartsTab.js';
import { CoordinatorSparePartsAnalyticsTab } from '../../public/assets/js/components/CoordinatorSparePartsAnalyticsTab.js';

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
console.log(' VendGuard: Frontend Test Suite - Spare Parts Integration (T-SPARE-17)');
console.log('======================================================================\n');

// =========================================================================
// BLOQUE 1: TechnicianChecklistModal.js (Integración de repuestos en preventivo)
// =========================================================================
console.log('--- Bloque 1: TechnicianChecklistModal.js ---');

assert(
  'TechnicianChecklistModal registra componente TechnicianResolutionPartsBlock',
  TechnicianChecklistModal.components?.TechnicianResolutionPartsBlock === TechnicianResolutionPartsBlock
);

assert(
  'TechnicianChecklistModal tiene template que renderiza TechnicianResolutionPartsBlock con ref="partsBlockRef"',
  TechnicianChecklistModal.template.includes('<TechnicianResolutionPartsBlock') &&
  TechnicianChecklistModal.template.includes('ref="partsBlockRef"')
);

assert(
  'TechnicianChecklistModal template pasa props de máquina y orden preventiva',
  TechnicianChecklistModal.template.includes(':machine-id=') &&
  TechnicianChecklistModal.template.includes(':preventive-order-id=') &&
  TechnicianChecklistModal.template.includes('@change="replacedPartsData = $event"')
);

{
  const modalData = TechnicianChecklistModal.data();
  assert(
    'TechnicianChecklistModal data() inicializa replacedPartsData correctamente',
    modalData.replacedPartsData !== undefined &&
    modalData.replacedPartsData.replaced_parts_declared === false &&
    Array.isArray(modalData.replacedPartsData.replaced_parts)
  );
}

{
  // Test resetForm()
  const instance = {
    checklistData: { id: 1 },
    temperatureInput: '4.2',
    itemsState: { ITEM1: { status: 'PASS' } },
    replacedPartsData: { replaced_parts_declared: true, replaced_parts: [{ spare_part_id: 1, quantity: 2 }] },
    generalNotes: 'Algo',
    errorMessage: 'Error previo',
    completionResult: { id: 1 }
  };
  TechnicianChecklistModal.methods.resetForm.call(instance);
  assert(
    'resetForm() limpia replacedPartsData a su estado inicial',
    instance.replacedPartsData.replaced_parts_declared === false &&
    instance.replacedPartsData.replaced_parts.length === 0
  );
}

{
  // Test submitChecklist() validación de repuestos y envío de payload
  let capturedPayload = null;
  api.technician.completePreventiveInspection = async (orderId, payload) => {
    capturedPayload = { orderId, payload };
    return { data: { order_id: orderId, result: 'CONFORME', replaced_parts_count: payload.replaced_parts.length } };
  };

  const emitted = [];
  const instance = {
    order: { id: 44, machine: { id: 10, is_refrigerated: false } },
    checklistData: {
      machine: { id: 10, is_refrigerated: false },
      checklist_items: [{ item_code: 'CLEAN_CABIN', title: 'Limpieza de cabina' }]
    },
    itemsState: {
      CLEAN_CABIN: { status: 'PASS', observations: '' }
    },
    numericTemperature: null,
    isRefrigerated: false,
    generalNotes: 'Inspección completada con éxito',
    replacedPartsData: {
      replaced_parts_declared: true,
      replaced_parts: [
        { spare_part_id: 1, quantity: 1, destination: 'DESGUACE', notes: 'Válvula calcificada' }
      ]
    },
    $refs: {
      partsBlockRef: {
        validate: () => ({ isValid: true, error: '' })
      }
    },
    $emit: (evt, data) => emitted.push({ evt, data }),
    errorMessage: '',
    isSubmitting: false,
    completionResult: null
  };

  await TechnicianChecklistModal.methods.submitChecklist.call(instance);

  assert(
    'submitChecklist() envía payload con replaced_parts_declared y replaced_parts',
    capturedPayload !== null &&
    capturedPayload.orderId === 44 &&
    capturedPayload.payload.replaced_parts_declared === true &&
    capturedPayload.payload.replaced_parts.length === 1 &&
    capturedPayload.payload.replaced_parts[0].spare_part_id === 1
  );

  assert(
    'submitChecklist() emite inspection-completed tras éxito',
    emitted.some(e => e.evt === 'inspection-completed')
  );
}

{
  // Test submitChecklist() detiene envío si validate() de repuestos falla
  let calledApi = false;
  api.technician.completePreventiveInspection = async () => {
    calledApi = true;
    return {};
  };

  const instance = {
    order: { id: 45 },
    checklistData: {
      machine: { id: 10, is_refrigerated: false },
      checklist_items: [{ item_code: 'CLEAN_CABIN', title: 'Limpieza' }]
    },
    itemsState: {
      CLEAN_CABIN: { status: 'PASS', observations: '' }
    },
    numericTemperature: null,
    isRefrigerated: false,
    generalNotes: '',
    replacedPartsData: { replaced_parts_declared: true, replaced_parts: [] },
    $refs: {
      partsBlockRef: {
        validate: () => ({ isValid: false, error: 'Debe seleccionar al menos una pieza instalada.' })
      }
    },
    errorMessage: '',
    isSubmitting: false
  };

  await TechnicianChecklistModal.methods.submitChecklist.call(instance);

  assert(
    'submitChecklist() bloquea el envío y muestra error si partsBlockRef.validate() falla',
    calledApi === false &&
    instance.errorMessage === 'Debe seleccionar al menos una pieza instalada.'
  );
}

// =========================================================================
// BLOQUE 2: CoordinatorDashboardView.js (Pestañas de Repuestos y Analítica)
// =========================================================================
console.log('\n--- Bloque 2: CoordinatorDashboardView.js ---');

assert(
  'CoordinatorDashboardView registra CoordinatorSparePartsTab',
  CoordinatorDashboardView.components?.CoordinatorSparePartsTab === CoordinatorSparePartsTab
);

assert(
  'CoordinatorDashboardView registra CoordinatorSparePartsAnalyticsTab',
  CoordinatorDashboardView.components?.CoordinatorSparePartsAnalyticsTab === CoordinatorSparePartsAnalyticsTab
);

assert(
  'CoordinatorDashboardView data() documenta activeTab con repuestos y analitica-repuestos',
  CoordinatorDashboardView.template.includes("activeTab === 'repuestos'") &&
  CoordinatorDashboardView.template.includes("activeTab === 'analitica-repuestos'")
);

assert(
  'CoordinatorDashboardView barra de navegación incluye botón data-testid="tab-repuestos"',
  CoordinatorDashboardView.template.includes('data-testid="tab-repuestos"') &&
  CoordinatorDashboardView.template.includes("@click=\"activeTab = 'repuestos'\"")
);

assert(
  'CoordinatorDashboardView barra de navegación incluye botón data-testid="tab-analitica-repuestos"',
  CoordinatorDashboardView.template.includes('data-testid="tab-analitica-repuestos"') &&
  CoordinatorDashboardView.template.includes("@click=\"activeTab = 'analitica-repuestos'\"")
);

assert(
  'Botones de pestaña de repuestos respetan estilo Docker Design (border-radius: var(--radius-interactive, 4px))',
  CoordinatorDashboardView.template.includes("data-testid=\"tab-repuestos\"") &&
  CoordinatorDashboardView.template.includes("var(--radius-interactive, 4px)")
);

assert(
  'CoordinatorDashboardView template renderiza CoordinatorSparePartsTab condicionalmente',
  CoordinatorDashboardView.template.includes('<CoordinatorSparePartsTab') &&
  CoordinatorDashboardView.template.includes("v-else-if=\"activeTab === 'repuestos'\"")
);

assert(
  'CoordinatorDashboardView template renderiza CoordinatorSparePartsAnalyticsTab condicionalmente',
  CoordinatorDashboardView.template.includes('<CoordinatorSparePartsAnalyticsTab') &&
  CoordinatorDashboardView.template.includes("v-else-if=\"activeTab === 'analitica-repuestos'\"")
);

assert(
  'Pestaña de repuestos conecta evento @open-analytics para alternar a analítica',
  CoordinatorDashboardView.template.includes("@open-analytics=\"activeTab = 'analitica-repuestos'\"")
);

assert(
  'Pestaña de analítica conecta evento @open-catalog para alternar a catálogo',
  CoordinatorDashboardView.template.includes("@open-catalog=\"activeTab = 'repuestos'\"")
);

// =========================================================================
// BLOQUE 3: TechnicianRouteView.js (Enlace de modales táctiles móviles)
// =========================================================================
console.log('\n--- Bloque 3: TechnicianRouteView.js ---');

assert(
  'TechnicianRouteView registra TechnicianSparePartsPauseModal',
  TechnicianRouteView.components?.TechnicianSparePartsPauseModal === TechnicianSparePartsPauseModal
);

assert(
  'TechnicianRouteView registra TechnicianResolutionPartsBlock',
  TechnicianRouteView.components?.TechnicianResolutionPartsBlock === TechnicianResolutionPartsBlock
);

assert(
  'TechnicianRouteView template renderiza TechnicianSparePartsPauseModal con v-model="showPauseModal"',
  TechnicianRouteView.template.includes('<TechnicianSparePartsPauseModal') &&
  TechnicianRouteView.template.includes('v-model="showPauseModal"') &&
  TechnicianRouteView.template.includes(':incident="selectedIncident"') &&
  TechnicianRouteView.template.includes('@paused="handleIncidentPaused"')
);

assert(
  'TechnicianRouteView template renderiza TechnicianResolutionPartsBlock dentro del modal de resolución',
  TechnicianRouteView.template.includes('<TechnicianResolutionPartsBlock') &&
  TechnicianRouteView.template.includes('ref="resolutionPartsBlockRef"') &&
  TechnicianRouteView.template.includes('@change="resolvePartsData = $event"')
);

{
  const routeData = TechnicianRouteView.data();
  assert(
    'TechnicianRouteView data() inicializa resolvePartsData correctamente',
    routeData.resolvePartsData !== undefined &&
    routeData.resolvePartsData.replaced_parts_declared === false &&
    Array.isArray(routeData.resolvePartsData.replaced_parts)
  );
}

{
  // Test handleIncidentPaused
  const emitted = [];
  let routeLoaded = false;
  const instance = {
    selectedIncident: { id: 101, status: 'IN_PROGRESS', pending_parts_reason: null },
    showPauseModal: true,
    feedbackMessage: '',
    $emit: (evt, data) => emitted.push({ evt, data }),
    closePauseModal: () => { instance.showPauseModal = false; },
    loadRoute: () => { routeLoaded = true; }
  };

  const pauseEvent = {
    incidentId: 101,
    payload: {
      is_out_of_catalog: false,
      requested_parts: [{ spare_part_id: 2, quantity: 1 }]
    }
  };

  TechnicianRouteView.methods.handleIncidentPaused.call(instance, pauseEvent);

  assert(
    'handleIncidentPaused actualiza el estado de la incidencia local a PENDING_PARTS',
    instance.selectedIncident.status === 'PENDING_PARTS' &&
    instance.showPauseModal === false &&
    routeLoaded === true
  );

  assert(
    'handleIncidentPaused emite el evento paused hacia arriba',
    emitted.some(e => e.evt === 'paused')
  );
}

{
  // Test submitResolve con repuestos
  let capturedResolve = null;
  api.technician.resolveIncident = async (id, payload) => {
    capturedResolve = { id, payload };
    return { data: { id, status: 'RESOLVED', resolved_at: '2026-09-28T16:00:00Z' } };
  };

  const emitted = [];
  const instance = {
    selectedIncident: { id: 102, ticket_code: 'INC-2026-0102' },
    resolveDiagnosis: 'Sustituido motor extractor averiado por bloqueo mecánico',
    resolveAction: 'Instalado motor extractor nuevo modelo compatible de repuesto',
    resolvePartsData: {
      replaced_parts_declared: true,
      replaced_parts: [
        { spare_part_id: 3, quantity: 1, destination: 'TALLER', notes: 'Motor atascado para bobinar' }
      ]
    },
    $refs: {
      resolutionPartsBlockRef: {
        validate: () => ({ isValid: true, error: '' })
      }
    },
    // Estado del dictamen de saldo (RF-REF-04, T-REF-16). El bloqueo de repuestos
    // ya no es la única condición de envío: `submitResolve()` es fail-closed y
    // aborta si no puede verificar las reclamaciones de saldo. Estos son los
    // valores por defecto que la vista inicializa en `data()` para una avería
    // sin reclamaciones colgadas, así que el envío debe prosperar.
    isLoadingRefundInspection: false,
    refundInspectionFailed: false,
    hasPendingRefundVerdict: false,
    resolveRefundData: { isValid: true },
    incidents: [{ id: 102 }, { id: 103 }],
    showResolveModal: true,
    feedbackMessage: '',
    $emit: (evt, data) => emitted.push({ evt, data }),
    closeResolveModal: () => { instance.showResolveModal = false; },
    resolveError: '',
    isResolving: false
  };

  await TechnicianRouteView.methods.submitResolve.call(instance);

  assert(
    'submitResolve() envía diagnóstico, acción correctiva y repuestos sustituidos',
    capturedResolve !== null &&
    capturedResolve.id === 102 &&
    capturedResolve.payload.diagnosis.length >= 20 &&
    capturedResolve.payload.action_taken.length >= 20 &&
    capturedResolve.payload.replaced_parts_declared === true &&
    capturedResolve.payload.replaced_parts.length === 1 &&
    capturedResolve.payload.replaced_parts[0].destination === 'TALLER'
  );

  assert(
    'submitResolve() retira la incidencia resuelta de la ruta activa y emite resolved',
    instance.incidents.length === 1 &&
    instance.incidents[0].id === 103 &&
    emitted.some(e => e.evt === 'resolved')
  );
}

{
  // Test submitResolve falla si resolutionPartsBlockRef.validate() falla
  let apiCalled = false;
  api.technician.resolveIncident = async () => {
    apiCalled = true;
    return {};
  };

  const instance = {
    selectedIncident: { id: 104 },
    resolveDiagnosis: 'Diagnóstico suficientemente largo para cumplir los 20 caracteres',
    resolveAction: 'Acción correctiva suficientemente larga para cumplir los 20 caracteres',
    $refs: {
      resolutionPartsBlockRef: {
        validate: () => ({ isValid: false, error: 'Debe responder si hubo sustitución de piezas.' })
      }
    },
    resolveError: '',
    isResolving: false
  };

  await TechnicianRouteView.methods.submitResolve.call(instance);

  assert(
    'submitResolve() valida resolutionPartsBlockRef y detiene envío si es inválido',
    apiCalled === false &&
    instance.resolveError === 'Debe responder si hubo sustitución de piezas.'
  );
}

console.log('\n======================================================================');
console.log(` RESULTADO FINAL: ${assertions - failures}/${assertions} aserciones correctas (${failures} fallos).`);
console.log('======================================================================\n');

if (failures > 0) {
  process.exit(1);
} else {
  process.exit(0);
}
