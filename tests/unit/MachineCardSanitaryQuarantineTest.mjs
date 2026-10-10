/**
 * VendGuard - MachineCardSanitaryQuarantineTest (tests/unit/MachineCardSanitaryQuarantineTest.mjs)
 *
 * Verifica la condición "Hecho cuando:" de la tarea T-PAUSE-29 (RF-03.5.1, RF-06.1,
 * Constitución Art. II y Art. V.4):
 * 1. El distintivo literal «Riesgo térmico: máquina en cuarentena preventiva» es una
 *    sola pieza de copy compartida: se declara una vez en el vocabulario común
 *    (`IncidentStatusPermissions.js`), lo publica el servidor en `alert.thermal_risk_label`
 *    y lo pintan tanto el escaneo QR público como la tarjeta del portal de sede.
 * 2. `MachineCard.js` enciende el aviso de cuarentena con el estado sanitario real que
 *    publica la API (`machine.sanitary_status === 'QUARANTINE'`), sin inventar estados a
 *    partir de la avería activa, y el filo del riesgo térmico manda sobre cualquier otro.
 * 3. El canal ciudadano sigue siendo el mismo que certifica el servidor: la cuarentena no
 *    arrastra consigo un solo campo interno de la pausa por falta de acceso (Art. V.4).
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '../../');

const CARD_PATH = path.join(ROOT, 'public/assets/js/components/MachineCard.js');
const QR_MODAL_PATH = path.join(ROOT, 'public/assets/js/components/QrSanitaryQuarantineModal.js');
const UTILS_PATH = path.join(ROOT, 'public/assets/js/utils/IncidentStatusPermissions.js');
const SCAN_SERVICE_PATH = path.join(ROOT, 'src/Application/Service/QrScanService.php');
const PORTAL_CONTROLLER_PATH = path.join(ROOT, 'src/Presentation/Controller/LocationPortalController.php');
const SPEC_PATH = path.join(ROOT, 'specs/11-pending-info-sla-pause/spec.md');

const { MachineCard } = await import('../../public/assets/js/components/MachineCard.js');
const { QrSanitaryQuarantineModal } = await import('../../public/assets/js/components/QrSanitaryQuarantineModal.js');
const {
  SANITARY_THERMAL_RISK_LABEL,
  SANITARY_STATUS_QUARANTINE,
  isSanitaryQuarantine
} = await import('../../public/assets/js/utils/IncidentStatusPermissions.js');

const cardSource = fs.readFileSync(CARD_PATH, 'utf8');
const cardTemplate = String(MachineCard.template || '');
const qrModalSource = fs.readFileSync(QR_MODAL_PATH, 'utf8');
const qrModalTemplate = String(QrSanitaryQuarantineModal.template || '');
const utilsSource = fs.readFileSync(UTILS_PATH, 'utf8');
const scanServiceSource = fs.readFileSync(SCAN_SERVICE_PATH, 'utf8');
const portalControllerSource = fs.readFileSync(PORTAL_CONTROLLER_PATH, 'utf8');
const specSource = fs.readFileSync(SPEC_PATH, 'utf8');

const THERMAL_RISK_LITERAL = 'Riesgo térmico: máquina en cuarentena preventiva';

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
console.log(' VendGuard: Frontend Test Suite - Cuarentena sanitaria en el portal de sede (T-PAUSE-29)');
console.log('======================================================================\n');

/** Instancia simulada de MachineCard con binding Options API. */
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

/** Instancia simulada de QrSanitaryQuarantineModal. */
function buildQuarantineModal(alert) {
  const instance = { alert };

  for (const [name, fn] of Object.entries(QrSanitaryQuarantineModal.computed || {})) {
    Object.defineProperty(instance, name, { get: () => fn.call(instance) });
  }

  return instance;
}

const quarantineMachine = {
  id: 301,
  code: 'VEND-0301',
  model: 'Necta Krea Prime',
  machine_type: 'PERISHABLE_FOOD',
  floor_wing: 'Planta baja - Comedor',
  sanitary_status: 'QUARANTINE',
  active_incident: {
    id: 601,
    ticket_code: 'INC-2026-0601',
    status: 'PENDING_INFO',
    pending_info_reason_category: 'SITE_ACCESS_BLOCKED',
    pending_info_reason_category_label: 'Acceso bloqueado a la máquina',
    pending_info_reason_text: 'Almacén cerrado con llave (CONFIDENCIAL)',
    public_comments_count: 0
  }
};

// ─── Grupo 1: El vocabulario compartido y el literal de la especificación ────
console.log('--- Grupo 1: Distintivo de riesgo térmico como copy único (RF-03.5.1) ---');

assert('1.1 El vocabulario compartido declara el estado de cuarentena del servidor',
  SANITARY_STATUS_QUARANTINE === 'QUARANTINE',
  `SANITARY_STATUS_QUARANTINE=${SANITARY_STATUS_QUARANTINE}`);

assert('1.2 El distintivo compartido dice exactamente el literal de RF-03.5.1',
  SANITARY_THERMAL_RISK_LABEL === THERMAL_RISK_LITERAL,
  `literal=${SANITARY_THERMAL_RISK_LABEL}`);

assert('1.3 El literal compartido es el que manda la especificación del módulo 11',
  specSource.includes(THERMAL_RISK_LITERAL),
  'la spec no contiene el distintivo literal');

assert('1.4 El literal se declara UNA sola vez en el frontend (sin copias divergentes)',
  (utilsSource.match(new RegExp(THERMAL_RISK_LITERAL.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g')) || []).length === 1
    && !cardSource.includes(`'${THERMAL_RISK_LITERAL}'`)
    && !qrModalSource.includes(`'${THERMAL_RISK_LITERAL}'`),
  'el literal aparece duplicado fuera del vocabulario compartido');

assert('1.5 El servidor publica el mismo literal en el aviso público del QR',
  scanServiceSource.includes(`'${THERMAL_RISK_LITERAL}'`),
  'QrScanService no publica el distintivo literal');

assert('1.6 isSanitaryQuarantine reconoce el estado real y tolera espacios/mayúsculas',
  isSanitaryQuarantine('QUARANTINE') === true
    && isSanitaryQuarantine(' quarantine ') === true,
  'la cuarentena no se reconoce');

assert('1.7 isSanitaryQuarantine no confunde ningún otro semáforo sanitario',
  isSanitaryQuarantine('OK') === false
    && isSanitaryQuarantine('ATTENTION_REQUIRED') === false
    && isSanitaryQuarantine('EXPIRED') === false
    && isSanitaryQuarantine('SEASONAL_PAUSE') === false
    && isSanitaryQuarantine(undefined) === false
    && isSanitaryQuarantine(null) === false
    && isSanitaryQuarantine('') === false,
  'algún estado ajeno enciende el riesgo térmico');

// ─── Grupo 2: La tarjeta del portal de sede ──────────────────────────────────
console.log('\n--- Grupo 2: MachineCard.js pinta el aviso de cuarentena (Art. II) ---');

const quarantinedCard = buildCardInstance(quarantineMachine);

assert('2.1 La tarjeta detecta la cuarentena desde sanitary_status, no desde la avería',
  quarantinedCard.isSanitaryQuarantine === true,
  `isSanitaryQuarantine=${quarantinedCard.isSanitaryQuarantine}`);

assert('2.2 thermalRiskLabel resuelve el literal compartido',
  quarantinedCard.thermalRiskLabel === THERMAL_RISK_LITERAL,
  `thermalRiskLabel=${quarantinedCard.thermalRiskLabel}`);

assert('2.3 El filo de la tarjeta es el crítico del sistema de diseño (manda sobre la avería)',
  quarantinedCard.cardBorderColor === 'var(--color-urgency-critical)'
    && quarantinedCard.hasActiveIncident === true,
  `cardBorderColor=${quarantinedCard.cardBorderColor}`);

assert('2.4 Una máquina operativa no tiene riesgo térmico ni filo crítico',
  buildCardInstance({ ...quarantineMachine, sanitary_status: 'OK', active_incident: null }).isSanitaryQuarantine === false
    && buildCardInstance({ ...quarantineMachine, sanitary_status: 'OK', active_incident: null }).cardBorderColor === 'var(--color-hairline, #c8cfda)',
  'una máquina sana enciende el aviso');

assert('2.5 Una máquina sin campo sanitario (contrato antiguo) no se marca en cuarentena',
  buildCardInstance({ id: 1, code: 'VEND-0001', machine_type: 'SNACKS', active_incident: null }).isSanitaryQuarantine === false,
  'el silencio del contrato se interpretó como riesgo');

assert('2.6 La plantilla declara el aviso con su data-testid de verificación',
  cardTemplate.includes('data-testid="machine-card-sanitary-quarantine-banner"')
    && cardTemplate.includes('vg-sanitary-quarantine-banner'),
  'falta el bloque del aviso de cuarentena');

assert('2.7 El aviso pinta el distintivo literal y la consecuencia operativa',
  cardTemplate.includes('{{ thermalRiskLabel }}')
    && cardTemplate.includes('fuera de servicio hasta que se acredite el control higiénico-sanitario'),
  'el aviso no explica el bloqueo');

assert('2.8 El aviso vive fuera de la cadena v-if de la avería (una máquina sin ticket también lo muestra)',
  cardTemplate.indexOf('machine-card-sanitary-quarantine-banner') < cardTemplate.indexOf('machine-card-pending-info-banner')
    && cardTemplate.includes('v-if="isSanitaryQuarantine"'),
  'el aviso compite con los banners de avería en lugar de acompañarlos');

// El bloque del aviso sanitario, acotado a lo que realmente pinta: desde su comentario
// hasta el arranque de la cadena de banners de avería. Las aserciones que siguen miden
// ESE recorte, no la plantilla completa, para no confundir el distintivo de cuarentena
// con el banner ámbar de la pausa que vive más abajo.
const quarantineBannerBlock = cardTemplate.slice(
  cardTemplate.indexOf('Middle: Sanitary Quarantine Banner'),
  cardTemplate.indexOf('Middle: Incident Status Banner')
);

assert('2.9 El aviso de cuarentena ocupa un bloque localizable de la plantilla',
  quarantineBannerBlock.includes('machine-card-sanitary-quarantine-banner')
    && quarantineBannerBlock.includes('{{ thermalRiskLabel }}'),
  'el recorte del aviso no contiene el banner esperado');

assert('2.10 El aviso de cuarentena no filtra el motivo interno de la pausa (Art. V.4)',
  !quarantineBannerBlock.includes('pendingInfoReasonText')
    && !quarantineBannerBlock.includes('pendingInfoReasonLabel')
    && !quarantineBannerBlock.includes('activeIncident'),
  'el aviso sanitario imprime datos internos del expediente');

assert('2.11 El aviso no introduce ni un solo color hexadecimal nuevo (trinquete de tokens)',
  !/#[0-9a-fA-F]{3,8}\b/.test(quarantineBannerBlock),
  'el aviso hardcodea colores en lugar de consumir tokens');

// ─── Grupo 3: El escaneo QR público ──────────────────────────────────────────
console.log('\n--- Grupo 3: QrSanitaryQuarantineModal.js y el canal ciudadano (RF-06.1) ---');

const quarantineAlert = {
  title: 'MÁQUINA FUERA DE SERVICIO POR CONTROL HIGIÉNICO-SANITARIO',
  message: 'Por imperativo del Artículo II de la Constitución (Seguridad Alimentaria), queda prohibida la adquisición y consumo de productos de esta unidad.',
  severity: 'CRITICAL_DANGER',
  thermal_risk_label: THERMAL_RISK_LITERAL
};

assert('3.1 El modal resuelve el distintivo que publica el servidor',
  buildQuarantineModal(quarantineAlert).thermalRiskLabel === THERMAL_RISK_LITERAL,
  'el distintivo del servidor no llega a la pantalla');

assert('3.2 Sin distintivo del servidor cae al literal compartido, nunca a un aviso mudo',
  buildQuarantineModal({}).thermalRiskLabel === THERMAL_RISK_LITERAL,
  'el respaldo del modal divergió del literal');

assert('3.3 El modal renderiza el distintivo con su data-testid',
  qrModalTemplate.includes('data-testid="quarantine-thermal-risk-badge"')
    && qrModalTemplate.includes('{{ thermalRiskLabel }}'),
  'falta el distintivo de riesgo térmico en el modal');

assert('3.4 El aviso constitucional previo se conserva intacto',
  qrModalTemplate.includes('ALERTA SANITARIA · ARTÍCULO II')
    && buildQuarantineModal(quarantineAlert).alertTitle.includes('CONTROL HIGIÉNICO-SANITARIO'),
  'la adición del distintivo rompió el aviso constitucional');

assert('3.5 El distintivo del modal consume tokens (cero hex nuevos)',
  !/#[0-9a-fA-F]{3,8}\b/.test(qrModalTemplate.slice(
    qrModalTemplate.indexOf('qr-thermal-risk-chip'),
    qrModalTemplate.indexOf('Alert Title')
  )),
  'el distintivo hardcodea colores');

console.log('\n--- Grupo 4: Contrato de la API y del portal de sede ---');

assert('4.1 El portal publica el estado sanitario en cada máquina del parque',
  portalControllerSource.includes("'sanitary_status' =>")
    && portalControllerSource.includes('SANITARY_STATUS_OK'),
  'el controlador no publica el estado sanitario');

assert('4.2 El catálogo del repositorio publica el estado sanitario normalizado',
  /'sanitary_status'\s*=>/.test(fs.readFileSync(path.join(ROOT, 'src/Infrastructure/Repository/PdoMachineRepository.php'), 'utf8')),
  'el catálogo de máquinas no expone sanitary_status');

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. CUARENTENA SANITARIA PUBLICADA EN LOS DOS CANALES.');
  console.log('======================================================================');
  process.exit(0);
}

console.log(' RESULTADO: FALLO EN EL CONTRATO DE CUARENTENA SANITARIA.');
console.log('======================================================================');
process.exit(1);
