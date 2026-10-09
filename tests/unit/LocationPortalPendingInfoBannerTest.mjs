/**
 * VendGuard - LocationPortalPendingInfoBannerTest (tests/unit/LocationPortalPendingInfoBannerTest.mjs)
 *
 * Verifica la condición "Hecho cuando:" de la tarea T-PAUSE-17 (Módulo 11, RF-05.1, RF-05.2, RNF-04):
 * 1. `MachineCard.js` renderiza un banner ámbar destacado cuando la máquina tiene una incidencia activa en PENDING_INFO:
 *    - Colores conformes a RNF-04 y tokens técnicos ámbar (#fef9c3, borde #fde047, texto #854d0e).
 *    - Muestra la causa tipificada de la pausa (`active_incident.pending_info_reason_category_label` o catálogo cerrado).
 *    - Muestra la justificación textual del técnico (`active_incident.pending_info_reason_text`).
 *    - Enmarca la tarjeta con borde ámbar (`#fde047`).
 *    - Ofrece un botón de 1 clic "Aportar información / Responder al técnico" que emite `open-comments` con la máquina.
 * 2. `LocationPortalView.js`:
 *    - Detecta reactivamente máquinas en `PENDING_INFO` a través de `pendingInfoMachines`.
 *    - Renderiza una alerta/banner global ámbar destacando el requerimiento de información de sede y la causa.
 *    - Ofrece un botón de 1 clic que abre el modal de comentarios del primer expediente en espera.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

// Entorno mínimo para localStorage del store
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

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CARD_PATH = path.resolve(HERE, '../../public/assets/js/components/MachineCard.js');
const PORTAL_PATH = path.resolve(HERE, '../../public/assets/js/views/LocationPortalView.js');

const { MachineCard } = await import('../../public/assets/js/components/MachineCard.js');
const { LocationPortalView } = await import('../../public/assets/js/views/LocationPortalView.js');
const { AMBER_TECHNICAL_TOKENS, isPendingInfoStatus } = await import('../../public/assets/js/utils/IncidentStatusPermissions.js');

const cardSource = fs.readFileSync(CARD_PATH, 'utf8');
const portalSource = fs.readFileSync(PORTAL_PATH, 'utf8');
const cardTemplate = String(MachineCard.template || '');
const portalTemplate = String(LocationPortalView.template || '');

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
console.log(' VendGuard: Frontend Test Suite - Banner PENDING_INFO en Sede (T-PAUSE-17)');
console.log('======================================================================\n');

/**
 * Instancia simulada de MachineCard con binding Options API.
 */
function buildCardInstance(machine, overrides = {}) {
  const instance = Object.assign({
    machine,
    $emit(name, payload) { this.emitted.push({ name, payload }); },
    emitted: []
  }, overrides);

  for (const [name, fn] of Object.entries(MachineCard.methods || {})) {
    instance[name] = fn.bind(instance);
  }

  for (const [name, fn] of Object.entries(MachineCard.computed || {})) {
    Object.defineProperty(instance, name, { get: () => fn.call(instance) });
  }

  return instance;
}

// ─── Grupo 1: Contrato Visual y Reactivo de MachineCard.js ───────────────────
console.log('--- Grupo 1: MachineCard.js con Avería en PENDING_INFO (RF-05.1, RNF-04) ---');

const machinePaused = {
  id: 201,
  code: 'VEND-0201',
  model: 'Necta Krea Prime',
  machine_type: 'HOT_DRINKS',
  floor_wing: 'Planta 2 - Sala de Juntas',
  active_incident: {
    id: 501,
    ticket_code: 'INC-2026-0501',
    status: 'PENDING_INFO',
    pending_info_reason_category: 'BUILDING_CLOSED_NO_ACCESS',
    pending_info_reason_category_label: 'Edificio cerrado / Sin acceso a instalaciones',
    pending_info_reason_text: 'Conserjería cerrada. Se requiere llave del cuarto técnico.',
    public_comments_count: 1
  }
};

const cardInstance = buildCardInstance(machinePaused);

assert('1.1 isPendingInfo reconoce el estado PENDING_INFO en active_incident',
  cardInstance.isPendingInfo === true,
  `isPendingInfo=${cardInstance.isPendingInfo}`);

assert('1.2 cardBorderColor adopta el borde técnico ámbar (#fde047)',
  cardInstance.cardBorderColor === AMBER_TECHNICAL_TOKENS.border && cardInstance.cardBorderColor === '#fde047',
  `cardBorderColor=${cardInstance.cardBorderColor}`);

assert('1.3 pendingInfoReasonLabel resuelve la etiqueta tipificada en castellano',
  cardInstance.pendingInfoReasonLabel === 'Edificio cerrado / Sin acceso a instalaciones',
  `pendingInfoReasonLabel=${cardInstance.pendingInfoReasonLabel}`);

assert('1.4 pendingInfoReasonLabel resuelve fallback desde el enum si falta label directo',
  buildCardInstance({
    id: 202,
    code: 'VEND-0202',
    active_incident: {
      status: 'PENDING_INFO',
      pending_info_reason_category: 'MACHINE_LOCATION_NOT_FOUND',
      pending_info_reason_text: 'No se encuentra en la planta 1.'
    }
  }).pendingInfoReasonLabel === 'Máquina no localizada en la planta indicada');

assert('1.5 pendingInfoReasonText entrega la justificación obligatoria del técnico',
  cardInstance.pendingInfoReasonText === 'Conserjería cerrada. Se requiere llave del cuarto técnico.',
  `pendingInfoReasonText=${cardInstance.pendingInfoReasonText}`);

assert('1.6 Plantilla de MachineCard declara el contenedor vg-pending-info-banner con testid',
  cardTemplate.includes('vg-pending-info-banner') &&
  cardTemplate.includes('data-testid="machine-card-pending-info-banner"'),
  'Se esperaba vg-pending-info-banner y data-testid="machine-card-pending-info-banner" en la plantilla.');

assert('1.7 Banner incluye el titular informativo con el emoji de pausa',
  cardTemplate.includes('Intervención en Pausa: El técnico necesita tu ayuda') &&
  cardTemplate.includes('⏸️'),
  'Se esperaba el encabezado canónico de pausa en el banner.');

assert('1.8 Banner incluye el botón de 1 clic para aportar información',
  cardTemplate.includes('vg-pending-info-action-btn') &&
  cardTemplate.includes('Aportar información / Responder al técnico') &&
  cardTemplate.includes('data-testid="machine-card-pending-info-reply-btn"'),
  'Se esperaba el botón con el texto exacto de la spec y su testid.');

cardInstance.handleOpenComments();
assert('1.9 El botón del banner emite open-comments con la máquina correspondiente',
  cardInstance.emitted.length === 1 &&
  cardInstance.emitted[0].name === 'open-comments' &&
  cardInstance.emitted[0].payload.id === 201,
  `Eventos emitidos: ${JSON.stringify(cardInstance.emitted)}`);

assert('1.10 Máquina operativa no activa el banner de pausa',
  buildCardInstance({ id: 203, code: 'VEND-0203', active_incident: null }).isPendingInfo === false);

assert('1.11 Máquina en curso normal (IN_PROGRESS) no activa el banner de pausa',
  buildCardInstance({ id: 204, code: 'VEND-0204', active_incident: { status: 'IN_PROGRESS' } }).isPendingInfo === false);

assert('1.12 Máquina en garantía (RESOLVED) no activa el banner de pausa',
  buildCardInstance({ id: 205, code: 'VEND-0205', active_incident: { status: 'RESOLVED' } }).isPendingInfo === false);

// ─── Grupo 2: Contrato Visual y Reactivo de LocationPortalView.js ─────────────
console.log('\n--- Grupo 2: LocationPortalView.js con Máquinas Pausadas (RF-05.1, RF-05.2) ---');

const mockMachinesList = [
  { id: 1, code: 'VEND-0001', active_incident: null },
  { id: 2, code: 'VEND-0002', active_incident: { id: 10, status: 'IN_PROGRESS' } },
  machinePaused
];

const portalInstance = Object.assign({
  machines: mockMachinesList,
  activeFilter: 'all',
  selectedMachine: null,
  showCommentsModal: false,
  $emit(name, payload) { this.emitted.push({ name, payload }); },
  emitted: []
}, {});

for (const [name, fn] of Object.entries(LocationPortalView.methods || {})) {
  portalInstance[name] = fn.bind(portalInstance);
}
for (const [name, fn] of Object.entries(LocationPortalView.computed || {})) {
  Object.defineProperty(portalInstance, name, { get: () => fn.call(portalInstance) });
}

assert('2.1 pendingInfoMachines filtra exactamente las máquinas en PENDING_INFO',
  portalInstance.pendingInfoMachines.length === 1 &&
  portalInstance.pendingInfoMachines[0].id === 201,
  `Máquinas detectadas: ${portalInstance.pendingInfoMachines.length}`);

assert('2.2 firstPendingInfoMachine entrega la primera máquina en espera',
  portalInstance.firstPendingInfoMachine?.code === 'VEND-0201');

assert('2.3 firstPendingInfoReasonLabel expone la causa para el banner global',
  portalInstance.firstPendingInfoReasonLabel === 'Edificio cerrado / Sin acceso a instalaciones');

assert('2.4 Plantilla de LocationPortalView incluye la alerta global vg-portal-pending-info-alert',
  portalTemplate.includes('vg-portal-pending-info-alert') &&
  portalTemplate.includes('data-testid="location-portal-pending-info-alert"'),
  'Se esperaba vg-portal-pending-info-alert en la plantilla del portal.');

assert('2.5 Alerta global contiene el botón de llamada a la acción hacia el modal de comentarios',
  portalTemplate.includes('data-testid="location-portal-pending-info-action-btn"') &&
  portalTemplate.includes('Aportar información / Responder al técnico'),
  'Se esperaba el botón con testid y texto de respuesta.');

portalInstance.onOpenComments(portalInstance.firstPendingInfoMachine);
assert('2.6 Pulsar la acción del banner global abre el modal de comentarios del expediente pausado',
  portalInstance.showCommentsModal === true &&
  portalInstance.selectedMachine?.active_incident?.ticket_code === 'INC-2026-0501' &&
  portalInstance.activeCommentIncidentId === 501 &&
  portalInstance.activeCommentTicketCode === 'INC-2026-0501',
  `Estado: modal=${portalInstance.showCommentsModal}, ticket=${portalInstance.activeCommentTicketCode}`);

// ─── Grupo 3: Respeto a los Tokens de Diseño y Trinquete de Deuda (RNF-04) ────
console.log('\n--- Grupo 3: Tokens de Diseño y Deuda de Colores (RNF-04) ---');

assert('3.1 MachineCard y LocationPortalView consumen AMBER_TECHNICAL_TOKENS compartido',
  cardSource.includes('AMBER_TECHNICAL_TOKENS') && portalSource.includes('AMBER_TECHNICAL_TOKENS'),
  'Se esperaba la importación de AMBER_TECHNICAL_TOKENS para no añadir colores hardcodeados.');

assert('3.2 El helper isPendingInfoStatus se importa desde IncidentStatusPermissions.js',
  cardSource.includes('isPendingInfoStatus') && portalSource.includes('isPendingInfoStatus'),
  'Se esperaba el uso de isPendingInfoStatus del espejo de dominio.');

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-17 CUMPLIDA.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULTADO: FALLO EN ${failures} ASERCIONES.`);
  console.log('======================================================================\n');
  process.exit(1);
}
