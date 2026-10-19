/**
 * VendGuard - TechnicianOpportunisticModal Test Suite (Módulo 16, T-OPP-10)
 *
 * Certifica la condición "Hecho cuando" de la tarea:
 *   1. El flujo completo se resuelve en 3 interacciones: abrir catálogo, elegir
 *      máquina y confirmar (RNF-02).
 *   2. Flujo A (`CREATE_NEW`) con recordatorio de privacidad y QR para
 *      `PAYMENT_SYSTEM`, sin pedir jamás IBAN ni teléfono (RF-04.3, Art. V.4).
 *   3. Flujo B: asunción directa, traspaso con confirmación y nota interna, y
 *      reapertura en garantía con aviso de 3.ª reincidencia crónica (RF-05).
 *   4. Banner de parada crítica/cadena de frío en otra sede con confirmación
 *      expresa (RF-03.1, Art. II).
 *   5. `form_opened_within_grace = true` en ambos flujos (RF-01.3).
 *   6. Contrato del cliente API para los tres endpoints y la extensión de ruta.
 *
 * Dogma Vanilla: Node.js native ESM, zero dependencies, zero network.
 */

// Mock localStorage for headless Node environment
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

import { api, ApiError } from '../../public/assets/js/api.js';
import { TechnicianOpportunisticModal } from '../../public/assets/js/components/TechnicianOpportunisticModal.js';
import { IncidentBadge } from '../../public/assets/js/components/IncidentBadge.js';

/**
 * Contrato real del cliente API, capturado antes de que los grupos instalen sus
 * dobles. Sin esta copia, el grupo 9 heredaría los stubs del grupo anterior y la
 * certificación de rutas comprobaría el doble en lugar del cliente.
 */
const technicianContract = {
  getOpportunisticMachines: api.technician.getOpportunisticMachines,
  createOpportunisticIncident: api.technician.createOpportunisticIncident,
  assumeOpportunisticIncident: api.technician.assumeOpportunisticIncident,
  getMyRoute: api.technician.getMyRoute
};

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
console.log(' VendGuard: Frontend Test Suite - TechnicianOpportunisticModal (T-OPP-10)');
console.log('======================================================================\n');

// ---------------------------------------------------------------------
// Fixtures del contrato `LocationOpportunisticCatalogDto`
// ---------------------------------------------------------------------

const coldChainWarning = {
  requires_confirmation: true,
  pending_critical_stops: [
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

const baseCatalog = {
  location: {
    id: 3,
    site_code: 'HOSP-CLINIC-N',
    name: 'Hospital Clínic · Pabellón Norte',
    address: 'C/ Villarroel 170, Barcelona',
    enablement_type: 'ACTIVE_STOP',
    grace_expires_at: null,
    grace_remaining_seconds: null
  },
  active_intervention_conflict: null,
  cold_chain_route_warning: {
    requires_confirmation: false,
    pending_critical_stops: []
  },
  machines: [
    {
      id: 12,
      code: 'MAQ-BCN-012',
      model: 'Necta Canto Touch',
      machine_type: 'HOT_DRINKS',
      floor_wing: 'Planta 2 · Cafetería',
      is_blocked_no_access: false,
      sanitary_status: 'OK',
      opportunistic_action: 'CREATE_NEW',
      active_incident: null
    },
    {
      id: 16,
      code: 'MAQ-BCN-016',
      model: 'Fas Winning',
      machine_type: 'SNACKS',
      floor_wing: 'Planta Baja · Hall',
      is_blocked_no_access: false,
      sanitary_status: 'OK',
      opportunistic_action: 'ASSUME_DIRECT',
      active_incident: {
        id: 103,
        ticket_code: 'INC-2026-8103',
        status: 'REGISTERED',
        urgency: 'HIGH',
        category: 'PRODUCT_JAM',
        description: 'Espiral B2 no entrega el producto.',
        assigned_technician_id: null,
        assigned_technician_name: null,
        is_in_warranty: false,
        reopen_count: 0
      }
    },
    {
      id: 13,
      code: 'MAQ-BCN-013',
      model: 'Necta Samba',
      machine_type: 'SNACKS',
      floor_wing: 'Planta 3 · Sala Espera',
      is_blocked_no_access: false,
      sanitary_status: 'OK',
      opportunistic_action: 'ASSUME_WITH_CONFIRMATION',
      active_incident: {
        id: 102,
        ticket_code: 'INC-2026-8105',
        status: 'ASSIGNED',
        urgency: 'MEDIUM',
        category: 'PAYMENT_SYSTEM',
        description: 'Monedero no devuelve cambio.',
        assigned_technician_id: 5,
        assigned_technician_name: 'Laura Gómez',
        is_in_warranty: false,
        reopen_count: 0
      }
    },
    {
      id: 14,
      code: 'MAQ-BCN-014',
      model: 'Jofemar Vision',
      machine_type: 'COLD_DRINKS',
      floor_wing: 'Planta 1 · Vestíbulo',
      is_blocked_no_access: false,
      sanitary_status: 'OK',
      opportunistic_action: 'REOPEN_IN_SITU',
      active_incident: {
        id: 95,
        ticket_code: 'INC-2026-7990',
        status: 'RESOLVED',
        urgency: 'MEDIUM',
        category: 'PRODUCT_JAM',
        description: 'Lata atascada en canal 3.',
        assigned_technician_id: 5,
        assigned_technician_name: 'Laura Gómez',
        resolved_at: '2026-10-18T16:00:00+02:00',
        is_in_warranty: true,
        reopen_count: 2,
        will_trigger_chronic_replacement: true
      }
    },
    {
      id: 11,
      code: 'MAQ-BCN-011',
      model: 'Fas Winning',
      machine_type: 'PERISHABLE_FOOD',
      floor_wing: 'Planta Baja · Urgencias',
      is_blocked_no_access: false,
      sanitary_status: 'OK',
      opportunistic_action: 'CURRENT_TECHNICIAN_IN_PROGRESS',
      active_incident: {
        id: 101,
        ticket_code: 'INC-2026-8100',
        status: 'IN_PROGRESS',
        urgency: 'CRITICAL',
        category: 'TEMPERATURE_COLD',
        description: 'Compresor detenido.',
        assigned_technician_id: 4,
        assigned_technician_name: 'Carlos Ruiz',
        is_in_warranty: false,
        reopen_count: 0
      }
    },
    {
      id: 15,
      code: 'MAQ-BCN-015',
      model: 'Jofemar Vision',
      machine_type: 'COLD_DRINKS',
      floor_wing: 'Planta 2 · Pasillo',
      is_blocked_no_access: true,
      sanitary_status: 'OK',
      opportunistic_action: 'BLOCKED_OTHER_TECH_IN_PROGRESS',
      active_incident: {
        id: 105,
        ticket_code: 'INC-2026-8120',
        status: 'IN_PROGRESS',
        urgency: 'HIGH',
        category: 'PRODUCT_JAM',
        description: 'Motor de espiral bloqueado.',
        assigned_technician_id: 6,
        assigned_technician_name: 'Diego Ferrer',
        is_in_warranty: false,
        reopen_count: 0
      }
    }
  ]
};

/**
 * Crea una instancia de componente con los métodos enlazados y los `computed`
 * expuestos como getters, igual que el resto de suites ESM de la casa.
 */
function createModal(overrides = {}) {
  const emits = [];
  const instance = {
    modelValue: true,
    isOpen: false,
    locationId: 3,
    locationName: 'Hospital Clínic · Pabellón Norte',
    loading: false,
    catalogLoaded: false,
    catalogError: '',
    machines: [],
    location: null,
    activeInterventionConflict: null,
    coldChainWarning: null,
    selectedMachineId: null,
    mode: '',
    category: 'PRODUCT_JAM',
    originReason: 'SITE_VERBAL_NOTICE',
    informantName: '',
    description: '',
    photoFile: null,
    photoError: '',
    confirmColdChainOverride: false,
    takeoverConfirmed: false,
    takeoverNote: '',
    reopenReason: '',
    submitting: false,
    errorMessage: '',
    noticeMessage: '',
    formOpenedAt: null,
    formOpenedWithinGrace: false,
    ...overrides,
    $emit: (event, value) => emits.push({ event, value }),
    emits
  };

  for (const [name, fn] of Object.entries(TechnicianOpportunisticModal.methods)) {
    instance[name] = fn.bind(instance);
  }
  for (const [name, definition] of Object.entries(TechnicianOpportunisticModal.computed)) {
    Object.defineProperty(instance, name, {
      get: () => definition.call(instance)
    });
  }

  return instance;
}

/** Restaura los dobles del cliente API entre grupos.
 *
 * Cada apertura recibe una copia profunda: el modal muta el catálogo al resolver una
 * colisión (máquina que ya tenía expediente), y compartir la misma referencia entre
 * casos convertiría una prueba en el estado inicial de la siguiente.
 */
function stubCatalog(payload = baseCatalog) {
  api.technician.getOpportunisticMachines = async () => JSON.parse(JSON.stringify(payload));
}

function stubCreate(handler) {
  api.technician.createOpportunisticIncident = handler;
}

function stubAssume(handler) {
  api.technician.assumeOpportunisticIncident = handler;
}

function machineById(instance, id) {
  return instance.machines.find((machine) => Number(machine.id) === Number(id));
}

// ---------------------------------------------------------------------
// TEST GROUP 1: Identidad y contrato del componente
// ---------------------------------------------------------------------
console.log('--- Group 1: Component identity and contract ---');

assert('1.1 El componente se llama TechnicianOpportunisticModal', TechnicianOpportunisticModal.name === 'TechnicianOpportunisticModal');
assert('1.2 Declara las props del plan (is-open, location-id, location-name)',
  ['isOpen', 'locationId', 'locationName', 'modelValue'].every((key) => Object.prototype.hasOwnProperty.call(TechnicianOpportunisticModal.props, key)));
assert('1.3 Declara los eventos created/assumed/close para que la vista de ruta reaccione',
  ['created', 'assumed', 'close', 'update:modelValue'].every((event) => TechnicianOpportunisticModal.emits.includes(event)));
assert('1.4 La plantilla usa el diálogo accesible de la casa', TechnicianOpportunisticModal.template.includes('<ModalDialog'));
assert('1.5 La apertura responde a v-model o al alias is-open', TechnicianOpportunisticModal.computed.dialogOpen.call({ modelValue: false, isOpen: true }) === true);
assert('1.6 El catálogo y el formulario declaran puntos de anclaje para la suite y la vista',
  ['technician-opportunistic-modal', 'opportunistic-submit', 'opportunistic-cold-chain-banner', 'opportunistic-payment-privacy']
    .every((id) => TechnicianOpportunisticModal.template.includes(id)));

// ---------------------------------------------------------------------
// TEST GROUP 2: Apertura del catálogo (toque 1)
// ---------------------------------------------------------------------
console.log('\n--- Group 2: Opening the location catalog (touch 1) ---');

stubCatalog();
const opened = createModal();
await opened.handleOpened();

assert('2.1 handleOpened registra el instante de apertura del formulario', typeof opened.formOpenedAt === 'string' && opened.formOpenedAt.length >= 20);
assert('2.2 handleOpened activa el margen de tolerancia de RF-01.3', opened.formOpenedWithinGrace === true);
assert('2.3 El catálogo se publica con sus máquinas', opened.machines.length === 6 && opened.catalogLoaded === true);
assert('2.4 La sede del contrato manda sobre el nombre de la prop', opened.displayLocationName === 'Hospital Clínic · Pabellón Norte');
assert('2.5 Las máquinas con acción se consideran seleccionables', opened.isMachineSelectable(machineById(opened, 12)) === true);
assert('2.6 La máquina ocupada por otro técnico no es seleccionable', opened.isMachineSelectable(machineById(opened, 15)) === false);
assert('2.7 Las etiquetas de acción cubren las seis variantes del contrato',
  ['CREATE_NEW', 'ASSUME_DIRECT', 'ASSUME_WITH_CONFIRMATION', 'REOPEN_IN_SITU', 'CURRENT_TECHNICIAN_IN_PROGRESS', 'BLOCKED_OTHER_TECH_IN_PROGRESS']
    .every((action) => opened.actionLabel({ opportunistic_action: action }) !== 'Seleccionar'));

api.technician.getOpportunisticMachines = async () => {
  throw new ApiError(403, 'LOCATION_NOT_ENABLED_FOR_OPPORTUNISTIC', 'La sede ya no está habilitada para una intervención oportunista.');
};
const failingCatalog = createModal();
await failingCatalog.handleOpened();
assert('2.8 Un 403 de sede no habilitada se explica sin romper el modal',
  failingCatalog.catalogError.includes('no está habilitada') && failingCatalog.loading === false);

// ---------------------------------------------------------------------
// TEST GROUP 3: Flujo A en tres interacciones (RNF-02, RF-04)
// ---------------------------------------------------------------------
console.log('\n--- Group 3: Flow A in three interactions (RNF-02) ---');

stubCatalog();
let createCalls = [];
stubCreate(async (payload) => {
  createCalls.push(payload);
  return { id: 104, ticket_code: 'INC-2026-8821', status: 'IN_PROGRESS', opportunistic_mode: 'NEW' };
});

const flowA = createModal();
await flowA.handleOpened();                 // Toque 1: abrir catálogo
flowA.selectMachine(machineById(flowA, 12)); // Toque 2: elegir máquina
flowA.description = 'Atasco en espiral B4 reportado por conserjería';
const created = await flowA.submit();        // Toque 3: confirmar

assert('3.1 La máquina sin expediente abre el flujo CREATE_NEW', flowA.mode === 'CREATE_NEW');
assert('3.2 El alta se resuelve en tres interacciones y una sola escritura', created === true && createCalls.length === 1);
assert('3.3 El payload identifica la máquina y la categoría', createCalls[0].machine_id === 12 && createCalls[0].category === 'PRODUCT_JAM');
assert('3.4 El payload declara el origen verbal por defecto', createCalls[0].origin_reason === 'SITE_VERBAL_NOTICE');
assert('3.5 El informante opcional viaja cuando existe', createCalls[0].informant_name === null);
assert('3.6 El envío acoge el margen de formulario abierto (RF-01.3)', createCalls[0].form_opened_within_grace === true);
assert('3.7 Sin emergencia ajena no se declara override sanitario', createCalls[0].confirm_cold_chain_override === false);
assert('3.8 El alta emite created y cierra el diálogo',
  flowA.emits.some((entry) => entry.event === 'created' && entry.value.ticket_code === 'INC-2026-8821')
  && flowA.emits.some((entry) => entry.event === 'update:modelValue' && entry.value === false));

const detection = createModal();
stubCatalog();
await detection.handleOpened();
detection.selectMachine(machineById(detection, 12));
detection.originReason = 'TECHNICIAN_DETECTION';
detection.informantName = 'No debe viajar';
detection.description = 'Motor de espiral con holgura detectado en la visita';
createCalls = [];
await detection.submit();
assert('3.9 La detección propia no conserva informante (RF-04.1.2)', createCalls[0].informant_name === null);

const verbal = createModal();
await verbal.handleOpened();
verbal.selectMachine(machineById(verbal, 12));
verbal.informantName = '  Marta Gómez (Conserjería)  ';
verbal.description = 'Atasco en espiral B4 reportado en persona';
createCalls = [];
await verbal.submit();
assert('3.10 El informante verbal se recorta y se conserva', createCalls[0].informant_name === 'Marta Gómez (Conserjería)');

const shortText = createModal();
await shortText.handleOpened();
shortText.selectMachine(machineById(shortText, 12));
shortText.description = 'Corto';
createCalls = [];
const shortResult = await shortText.submit();
assert('3.11 Una descripción de menos de 10 caracteres no llega al servidor',
  shortResult === false && createCalls.length === 0 && shortText.errorMessage.includes('10 caracteres'));
assert('3.12 El texto redactado se conserva tras el rechazo', shortText.description === 'Corto');

// Fotografía: guarda de cliente del Art. V.5
const photoModal = createModal();
const oversized = { name: 'evidencia.jpg', size: 6 * 1024 * 1024 };
assert('3.13 Una foto de más de 5 MB se rechaza en cliente', photoModal.validatePhoto(oversized).includes('5 MB'));
const unsafe = { name: 'evidencia.php', size: 1024 };
assert('3.14 Una extensión no gráfica se rechaza en cliente', photoModal.validatePhoto(unsafe).includes('Formato no admitido'));
const safePhoto = new Blob(['jpeg-bytes'], { type: 'image/jpeg' });
safePhoto.name = 'evidencia.webp';
assert('3.15 Una foto JPG/PNG/WEBP bajo el límite se acepta', photoModal.validatePhoto(safePhoto) === '');

// Privacidad de la categoría de pago (RF-04.3, Art. V.4)
assert('3.16 La categoría PAYMENT_SYSTEM enciende el recordatorio de privacidad',
  createModal({ category: 'PAYMENT_SYSTEM' }).isPaymentSystemCategory === true);
assert('3.17 El recordatorio invita al QR exterior y prohíbe datos bancarios',
  TechnicianOpportunisticModal.template.includes('código QR exterior')
  && TechnicianOpportunisticModal.template.includes('Nunca pidas IBAN'));
assert('3.18 El formulario no declara ningún campo de IBAN, Bizum o teléfono',
  !TechnicianOpportunisticModal.template.includes('v-model="iban"')
  && !TechnicianOpportunisticModal.template.includes('v-model="bizum_phone"')
  && !TechnicianOpportunisticModal.template.includes('name="iban"'));

// ---------------------------------------------------------------------
// TEST GROUP 4: Advertencia sanitaria de otra sede (RF-03.1)
// ---------------------------------------------------------------------
console.log('\n--- Group 4: Cold-chain safeguard in another location (RF-03.1) ---');

stubCatalog({
  ...baseCatalog,
  cold_chain_route_warning: coldChainWarning
});
const coldChain = createModal();
await coldChain.handleOpened();
coldChain.selectMachine(machineById(coldChain, 12));
coldChain.description = 'Bebida caliente derramada sobre el monedero';

assert('4.1 El catálogo exige confirmación expresa', coldChain.requiresColdChainConfirmation === true);
assert('4.2 La parada crítica llega con máquina, sede y SLA', coldChain.coldChainStops.length === 1
  && coldChain.coldChainStops[0].machine_code === 'MAQ-BCN-050'
  && coldChain.coldChainStops[0].location_name === 'Centro Logístico Zona Franca'
  && coldChain.coldChainStops[0].sla_target_at === '2026-10-19T10:30:00+02:00');
assert('4.3 El banner nombra el Artículo II', TechnicianOpportunisticModal.template.includes('Art. II'));

createCalls = [];
const blockedByColdChain = await coldChain.submit();
assert('4.4 Sin confirmación no se envía nada', blockedByColdChain === false && createCalls.length === 0);
assert('4.5 El rechazo explica la prioridad sanitaria', coldChain.errorMessage.includes('Art. II'));

coldChain.confirmColdChainOverride = true;
const confirmedColdChain = await coldChain.submit();
assert('4.6 Con confirmación expresa el alta prosigue', confirmedColdChain === true && createCalls.length === 1);
assert('4.7 El payload declara el override para que Coordinación reciba la alerta (RF-03.2)', createCalls[0].confirm_cold_chain_override === true);

// ---------------------------------------------------------------------
// TEST GROUP 5: Flujo B — asunción directa y traspaso (RF-05.2, RF-05.3)
// ---------------------------------------------------------------------
console.log('\n--- Group 5: Flow B - direct assumption and takeover ---');

stubCatalog();
let assumeCalls = [];
stubAssume(async (incidentId, payload) => {
  assumeCalls.push({ incidentId, payload });
  return { id: incidentId, status: 'IN_PROGRESS', opportunistic_mode: 'ASSUMED' };
});

const direct = createModal();
await direct.handleOpened();
direct.selectMachine(machineById(direct, 16));
assert('5.1 Un expediente sin técnico abre ASSUME_DIRECT', direct.mode === 'ASSUME' && direct.requiresTakeoverConfirmation === false);

const assumedDirect = await direct.submit();
assert('5.2 La asunción directa se envía al endpoint del expediente existente',
  assumedDirect === true && assumeCalls[0].incidentId === 103);
assert('5.3 La asunción no inventa confirmación de traspaso cuando no hace falta',
  !Object.prototype.hasOwnProperty.call(assumeCalls[0].payload, 'confirm_takeover_from_other_tech')
  && !Object.prototype.hasOwnProperty.call(assumeCalls[0].payload, 'takeover_internal_note'));
assert('5.4 La asunción también declara form_opened_within_grace = true', assumeCalls[0].payload.form_opened_within_grace === true);
assert('5.5 La asunción emite assumed con el expediente devuelto',
  direct.emits.some((entry) => entry.event === 'assumed' && entry.value.id === 103));

const takeover = createModal();
await takeover.handleOpened();
takeover.selectMachine(machineById(takeover, 13));
assert('5.6 Un expediente de otro compañero exige confirmación', takeover.requiresTakeoverConfirmation === true);
assert('5.7 La interfaz nombra al compañero que lo tenía asignado', activeIncidentName(takeover) === 'Laura Gómez');

assumeCalls = [];
const blockedTakeover = await takeover.submit();
assert('5.8 Sin marcar el traspaso no se envía la asunción', blockedTakeover === false && assumeCalls.length === 0);
assert('5.9 El rechazo cita la regla de traspaso', takeover.errorMessage.includes('traspaso'));

takeover.takeoverConfirmed = true;
takeover.takeoverNote = '  Traspaso por presencia física en la sede  ';
const assumedTakeover = await takeover.submit();
assert('5.10 Con confirmación el traspaso viaja al servidor', assumedTakeover === true && assumeCalls.length === 1);
assert('5.11 El payload declara confirm_takeover_from_other_tech', assumeCalls[0].payload.confirm_takeover_from_other_tech === true);
assert('5.12 La nota de traspaso se envía recortada y como nota interna',
  assumeCalls[0].payload.takeover_internal_note === 'Traspaso por presencia física en la sede');
assert('5.13 La plantilla avisa de que la nota es invisible para la Sede (Art. V.4)',
  TechnicianOpportunisticModal.template.includes('Estrictamente interna'));

function activeIncidentName(instance) {
  return instance.activeIncident?.assigned_technician_name || null;
}

// ---------------------------------------------------------------------
// TEST GROUP 6: Reapertura en garantía y 3.ª reincidencia (RF-05.4)
// ---------------------------------------------------------------------
console.log('\n--- Group 6: In-warranty reopening and 3rd recurrence ---');

stubCatalog();
assumeCalls = [];
stubAssume(async (incidentId, payload) => {
  assumeCalls.push({ incidentId, payload });
  return { id: 200, chronic_replacement_created: true, chronic_predecessor_incident_id: incidentId };
});

const reopen = createModal();
await reopen.handleOpened();
reopen.selectMachine(machineById(reopen, 14));

assert('6.1 Un expediente en garantía abre REOPEN_IN_SITU', reopen.mode === 'REOPEN');
assert('6.2 La 3.ª reincidencia se detecta antes de enviar', reopen.willTriggerChronicReplacement === true);
assert('6.3 La plantilla advierte del cierre como Avería Crónica y del sucesor (RF-05.4.2)',
  TechnicianOpportunisticModal.template.includes('Avería Crónica')
  && TechnicianOpportunisticModal.template.includes('nuevo expediente limpio'));

reopen.reopenReason = 'Falla';
const shortReopen = await reopen.submit();
assert('6.4 Un motivo de reincidencia corto no se envía', shortReopen === false && assumeCalls.length === 0);
assert('6.5 El rechazo exige los 10 caracteres mínimos', reopen.errorMessage.includes('10 caracteres'));

reopen.reopenReason = 'El compresor vuelve a detenerse cada dos horas';
const reopened = await reopen.submit();
assert('6.6 La reapertura envía el motivo al expediente correcto', reopened === true && assumeCalls[0].incidentId === 95);
assert('6.7 El motivo viaja completo y sin espacios sobrantes', assumeCalls[0].payload.reopen_reason === 'El compresor vuelve a detenerse cada dos horas');
assert('6.8 La reapertura también acoge el margen de formulario abierto', assumeCalls[0].payload.form_opened_within_grace === true);
assert('6.9 La sustitución crónica se anuncia en el evento assumed',
  reopen.emits.some((entry) => entry.event === 'assumed' && entry.value.chronic_replacement_created === true));

// ---------------------------------------------------------------------
// TEST GROUP 7: Estados sin acción posible (RF-05.5, RF-02.1)
// ---------------------------------------------------------------------
console.log('\n--- Group 7: States with no available action ---');

stubCatalog();
createCalls = [];
assumeCalls = [];
stubCreate(async (payload) => { createCalls.push(payload); return {}; });
stubAssume(async (id, payload) => { assumeCalls.push({ id, payload }); return {}; });

const own = createModal();
await own.handleOpened();
own.selectMachine(machineById(own, 11));
const ownResult = await own.submit();
assert('7.1 Una máquina ya en curso por el propio técnico no reabre nada',
  own.mode === 'CURRENT' && ownResult === false && createCalls.length === 0 && assumeCalls.length === 0);
assert('7.2 El aviso invita a continuar desde la parada de la ruta', own.errorMessage.includes('parada de tu ruta'));

const blocked = createModal();
await blocked.handleOpened();
blocked.selectMachine(machineById(blocked, 15));
const blockedResult = await blocked.submit();
assert('7.3 Un compañero interviniendo bloquea cualquier asunción (Art. V.3)',
  blocked.mode === 'BLOCKED' && blockedResult === false && assumeCalls.length === 0);
assert('7.4 El mensaje explica el bloqueo absoluto', blocked.errorMessage.includes('Art. V.3'));

// ---------------------------------------------------------------------
// TEST GROUP 8: Errores del contrato y conservación de datos
// ---------------------------------------------------------------------
console.log('\n--- Group 8: Contract errors and data preservation ---');

// 8.1: colisión concurrente en el alta -> se cambia al Flujo B sin recargar
stubCatalog();
stubCreate(async () => {
  throw new ApiError(
    409,
    'ACTIVE_INCIDENT_ALREADY_EXISTS_ASSUME_INSTEAD',
    'La máquina ya tiene un expediente activo.',
    {
      existing_incident: {
        id: 102,
        machine_id: 12,
        ticket_code: 'INC-2026-8105',
        status: 'ASSIGNED',
        urgency: 'MEDIUM',
        category: 'PAYMENT_SYSTEM',
        description: 'Monedero no devuelve cambio.',
        assigned_technician_id: 5,
        assigned_technician_name: 'Laura Gómez',
        reopen_count: 0
      },
      opportunistic_action: 'ASSUME_WITH_CONFIRMATION'
    }
  );
});

const duplicate = createModal();
await duplicate.handleOpened();
duplicate.selectMachine(machineById(duplicate, 12));
duplicate.description = 'Monedero bloqueado con monedas dentro';
const duplicateResult = await duplicate.submit();
assert('8.1 Un 409 de expediente existente cambia la vista al Flujo B', duplicateResult === false && duplicate.mode === 'ASSUME');
assert('8.2 El expediente recibido queda seleccionado sin recargar el catálogo',
  duplicate.activeIncident?.id === 102 && duplicate.requiresTakeoverConfirmation === true);
assert('8.3 La colisión se explica como prevención de duplicados (Art. V.2)', duplicate.noticeMessage.includes('Art. V.2'));
assert('8.4 La descripción redactada se conserva tras la colisión', duplicate.description === 'Monedero bloqueado con monedas dentro');

// 8.5: un 409 de cadena de frío reabre el banner y exige confirmación
stubCatalog();
stubCreate(async () => {
  throw new ApiError(
    409,
    'COLD_CHAIN_ROUTE_WARNING_CONFIRMATION_REQUIRED',
    'Debes confirmar la advertencia sanitaria.',
    { pending_critical_stops: coldChainWarning.pending_critical_stops }
  );
});
const coldError = createModal();
await coldError.handleOpened();
coldError.selectMachine(machineById(coldError, 12));
coldError.description = 'No enfría la cuba de lácteos';
await coldError.submit();
assert('8.5 Un 409 sanitario enciende el banner local sin perder el formulario',
  coldError.requiresColdChainConfirmation === true && coldError.coldChainStops.length === 1,
  `modo=${coldError.mode} · aviso=${coldError.errorMessage}`);
assert('8.6 La confirmación vuelve a quedar desmarcada tras el rechazo', coldError.confirmColdChainOverride === false);

// 8.7: traspaso sin confirmación
stubCatalog();
stubAssume(async () => {
  throw new ApiError(409, 'TAKEOVER_CONFIRMATION_REQUIRED', 'Falta confirmar el traspaso.', null);
});
const takeoverError = createModal();
await takeoverError.handleOpened();
takeoverError.selectMachine(machineById(takeoverError, 13));
takeoverError.takeoverConfirmed = true;
await takeoverError.submit();
assert('8.7 Un 409 de traspaso desmarca la confirmación y explica el paso',
  takeoverError.takeoverConfirmed === false && takeoverError.errorMessage.includes('traspaso'));

// 8.8: reapertura fuera de garantía
stubCatalog();
stubAssume(async () => {
  throw new ApiError(422, 'REOPEN_WINDOW_EXPIRED', '', null);
});
const expired = createModal();
await expired.handleOpened();
expired.selectMachine(machineById(expired, 14));
expired.reopenReason = 'El fallo vuelve a aparecer';
await expired.submit();
assert('8.8 Una reapertura fuera de las 48 h se explica con el Art. V.6', expired.errorMessage.includes('48 horas'),
  `modo=${expired.mode} · motivo=${expired.reopenReason} · aviso=${expired.errorMessage}`);

// 8.9: otro técnico ya en curso
stubCatalog();
stubAssume(async () => {
  throw new ApiError(409, 'INCIDENT_ALREADY_IN_PROGRESS_BY_OTHER_TECHNICIAN', '', null);
});
const occupied = createModal();
await occupied.handleOpened();
occupied.selectMachine(machineById(occupied, 13));
occupied.takeoverConfirmed = true;
await occupied.submit();
assert('8.9 Un 409 de compañero en curso conserva el bloqueo absoluto', occupied.errorMessage.includes('Art. V.3'),
  `modo=${occupied.mode} · aviso=${occupied.errorMessage}`);

// 8.10: intervención olvidada en otra sede
stubCatalog();
stubCreate(async () => {
  throw new ApiError(
    409,
    'ACTIVE_INTERVENTION_AT_DIFFERENT_LOCATION',
    'Mantienes una intervención en curso en otra sede.',
    { active_conflict: { ticket_code: 'INC-2026-7701' } }
  );
});
const conflict = createModal();
await conflict.handleOpened();
conflict.selectMachine(machineById(conflict, 12));
conflict.description = 'Atasco en el canal de snacks';
await conflict.submit();
assert('8.10 El conflicto de otra sede nombra el expediente que hay que cerrar',
  conflict.errorMessage.includes('INC-2026-7701'),
  `modo=${conflict.mode} · aviso=${conflict.errorMessage}`);

// ---------------------------------------------------------------------
// TEST GROUP 9: Contrato del cliente API (api.js)
// ---------------------------------------------------------------------
console.log('\n--- Group 9: API client contract ---');

const originalGet = api.get;
const originalPost = api.post;
let lastEndpoint = null;
let lastBody = null;

// Los grupos anteriores sustituyeron los métodos del técnico para no tocar la red:
// aquí se recupera el contrato real antes de espiar el transporte.
Object.assign(api.technician, technicianContract);

api.get = async (endpoint) => { lastEndpoint = endpoint; return {}; };
api.post = async (endpoint, body) => { lastEndpoint = endpoint; lastBody = body; return {}; };

await api.technician.getOpportunisticMachines(3);
assert('9.1 El catálogo consulta la sede por su identificador',
  lastEndpoint === '/technician/locations/3/opportunistic-machines');

await api.technician.createOpportunisticIncident({ machine_id: 12 });
assert('9.2 El alta oportunista usa el endpoint del Módulo 16',
  lastEndpoint === '/technician/opportunistic/incidents' && lastBody.machine_id === 12);

await api.technician.assumeOpportunisticIncident(102, { confirm_takeover_from_other_tech: true });
assert('9.3 La asunción usa el endpoint del expediente existente',
  lastEndpoint === '/technician/opportunistic/incidents/102/assume'
  && lastBody.confirm_takeover_from_other_tech === true);

await api.technician.getMyRoute({ includeOpportunisticContext: true, formOpenedWithinGrace: true });
assert('9.4 La ruta pide el contexto oportunista y el margen de gracia sin romper el contrato previo',
  lastEndpoint === '/technician/my-route?include_opportunistic_context=1&form_opened_within_grace=1');

await api.technician.getMyRoute();
assert('9.5 La ruta sin opciones conserva exactamente el endpoint histórico',
  lastEndpoint === '/technician/my-route');

api.get = originalGet;
api.post = originalPost;
Object.assign(api.technician, technicianContract);

// ---------------------------------------------------------------------
// TEST GROUP 10: Insignias oportunistas (RF-06.4)
// ---------------------------------------------------------------------
console.log('\n--- Group 10: Opportunistic badges ---');

const newBadge = IncidentBadge.computed.resolvedConfig.call({ value: 'NEW', type: 'opportunistic' });
const assumedBadge = IncidentBadge.computed.resolvedConfig.call({ value: 'ASSUMED', type: 'opportunistic' });

assert('10.1 La insignia NEW rotula "Oportunista In Situ · Nueva"', newBadge.label === 'Oportunista In Situ · Nueva');
assert('10.2 La insignia ASSUMED rotula "Oportunista In Situ · Asumida"', assumedBadge.label === 'Oportunista In Situ · Asumida');
assert('10.3 Las insignias no introducen literales hexadecimales nuevos',
  !/#[0-9a-fA-F]{3,8}/.test(String(newBadge.bg) + String(newBadge.color) + String(newBadge.border)));
assert('10.4 Un valor desconocido sigue cayendo en el contrato compartido de la casa',
  IncidentBadge.computed.resolvedConfig.call({ value: 'CRITICAL', type: 'urgency' }).label === 'Crítica');

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-OPP-10 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
