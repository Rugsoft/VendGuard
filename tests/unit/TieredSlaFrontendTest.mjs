/**
 * VendGuard - TieredSlaFrontendTest (Módulo 30, T-SLA-13)
 *
 * Certifica las superficies frontend del SLA escalonado:
 * 1. Ruta Móvil y mapa: contrato de sede, vencimiento y distinción explícita
 *    entre riesgo sanitario de cadena de frío y vencimiento contractual.
 * 2. Copia offline: el paquete conserva el contrato y el helper lo normaliza
 *    incluso para snapshots anteriores a este módulo (C6).
 * 3. Portal de Sede: perfil de la propia sede y plazo objetivo de cada aviso.
 * 4. Coordinación: selector, chips, escalado contractual y desglose métrico.
 * 5. Opacidad en el canal QR y en los documentos imprimibles A4 (D5, QA 4).
 * 6. Estilos solo con tokens, bump del App Shell y guardas de diseño en verde.
 *
 * Dogma Vanilla: Node.js ESM nativo, cero dependencias externas.
 */

import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..', '..');

// Entorno mínimo de navegador: los módulos de vista no tocan el DOM al importarse,
// pero la API y el store leen `window.location` y el almacenamiento local.
const storage = new Map();
globalThis.localStorage = {
  getItem: (key) => storage.get(key) ?? null,
  setItem: (key, value) => storage.set(key, String(value)),
  removeItem: (key) => storage.delete(key),
  clear: () => storage.clear()
};
globalThis.sessionStorage = { ...globalThis.localStorage };
globalThis.window = {
  location: { search: '', href: 'http://localhost/' },
  addEventListener: () => {},
  removeEventListener: () => {},
  open: () => null
};

const {
  SLA_TIER_LABELS,
  describeStopSlaContract,
  normalizeSnapshot
} = await import('../../public/assets/js/utils/OfflineStorage.js');
const { TechnicianRouteView } = await import('../../public/assets/js/views/TechnicianRouteView.js');
const { TechnicianRouteMapModal } = await import('../../public/assets/js/components/TechnicianRouteMapModal.js');
const { LocationPortalView } = await import('../../public/assets/js/views/LocationPortalView.js');

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

const read = (relativePath) => readFileSync(join(ROOT, relativePath), 'utf8');

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - SLA escalonado (T-SLA-13)');
console.log('======================================================================\n');

// -------------------------------------------------------------
// 1. Contrato SLA compartido online/offline
// -------------------------------------------------------------
console.log('--- 1. Contrato SLA de parada y tarea ---');

assert('1.1 El catálogo conserva las tres etiquetas oficiales',
  SLA_TIER_LABELS.STANDARD === 'Estándar'
    && SLA_TIER_LABELS.PRIORITY_CORPORATE === 'Prioritario Corporativo'
    && SLA_TIER_LABELS.CRITICAL_INFRASTRUCTURE_24_7 === 'Infraestructura Crítica 24x7');

const onlineContract = describeStopSlaContract({
  sla_tier_label: 'Infraestructura Crítica 24x7',
  sla_due_at: '2026-11-09T12:00:00Z',
  critical_reason: 'COLD_CHAIN'
});
assert('1.2 La parada online publica etiqueta, vencimiento y motivo sanitario',
  onlineContract.tier_label === 'Infraestructura Crítica 24x7'
    && onlineContract.due_at === '2026-11-09T12:00:00Z'
    && onlineContract.critical_reason === 'COLD_CHAIN');
assert('1.3 El riesgo de cadena de frío se rotula sin ambigüedad',
  onlineContract.critical_reason_label === 'Riesgo sanitario de cadena de frío');

const offlineContract = describeStopSlaContract({
  sla_tier: 'PRIORITY_CORPORATE',
  sla_target_at: '2026-11-09T18:00:00Z',
  is_contractual_critical: true
});
assert('1.4 Un snapshot antiguo deriva la etiqueta desde el código sellado',
  offlineContract.tier_label === 'Prioritario Corporativo');
assert('1.5 El vencimiento cae a `sla_target_at` cuando no existe `sla_due_at`',
  offlineContract.due_at === '2026-11-09T18:00:00Z');
assert('1.6 La urgencia contractual se rotula distinta de la sanitaria',
  offlineContract.critical_reason === 'CONTRACTUAL_SLA'
    && offlineContract.critical_reason_label === 'Vencimiento contractual ≤ 4 h');
assert('1.7 El helper tolera paradas sin contrato sin lanzar',
  describeStopSlaContract(null).tier_label === null
    && describeStopSlaContract(null).critical_reason === null);

// -------------------------------------------------------------
// 2. Ruta Móvil y mapa
// -------------------------------------------------------------
console.log('\n--- 2. Ruta Móvil y mapa ---');

const routeStop = {
  sla_tier_snapshot: 'CRITICAL_INFRASTRUCTURE_24_7',
  tasks: [
    { sla_due_at: '2026-11-09T10:00:00Z', critical_reason: 'CONTRACTUAL_SLA' },
    { sla_due_at: '2026-11-09T09:00:00Z' }
  ]
};
const routeContract = TechnicianRouteView.methods.slaContractFor.call({}, routeStop);
assert('2.1 La parada hereda la etiqueta del snapshot sellado',
  routeContract.tier_label === 'Infraestructura Crítica 24x7');
assert('2.2 La fecha límite de la parada es la más próxima de sus tareas',
  routeContract.due_at === '2026-11-09T09:00:00Z');
assert('2.3 El motivo crítico de la tarea se eleva a la parada',
  routeContract.critical_reason === 'CONTRACTUAL_SLA'
    && routeContract.critical_reason_label === 'Vencimiento contractual ≤ 4 h');
assert('2.4 El bono sanitario pinta rojo y el contractual ámbar',
  TechnicianRouteView.methods.slaCriticalReasonClass.call(
    { slaContractFor: TechnicianRouteView.methods.slaContractFor },
    { critical_reason: 'COLD_CHAIN' }
  ) === 'vg-sla-reason-badge--cold'
    && TechnicianRouteView.methods.slaCriticalReasonClass.call(
      { slaContractFor: TechnicianRouteView.methods.slaContractFor },
      { critical_reason: 'CONTRACTUAL_SLA' }
    ) === 'vg-sla-reason-badge--contractual');
assert('2.5 La fecha límite se formatea para la tarjeta móvil',
  TechnicianRouteView.methods.formatSlaDeadline('2026-11-09T10:00:00Z') !== '');

const routeViewSource = read('public/assets/js/views/TechnicianRouteView.js');
assert('2.6 La tarjeta declara el contrato y el vencimiento con testids propios',
  routeViewSource.includes('data-testid="tech-stop-sla-contract"')
    && routeViewSource.includes('data-testid="tech-stop-sla-deadline"'));
assert('2.7 La tarjeta distingue el motivo crítico sanitario del contractual',
  routeViewSource.includes('data-testid="tech-stop-sla-critical-reason"')
    && routeViewSource.includes("'COLD_CHAIN' ? '🌡️' : '⏱️'"));
assert('2.8 La normalización offline conserva los campos SLA',
  routeViewSource.includes('sla_due_at: stop?.sla_due_at ?? stop?.sla_target_at')
    && routeViewSource.includes('critical_reason: stop?.critical_reason ?? null'));

const mapSource = read('public/assets/js/components/TechnicianRouteMapModal.js');
assert('2.9 El listado y la ficha del mapa muestran el contrato SLA',
  mapSource.includes('data-testid="route-map-stop-sla"')
    && mapSource.includes('data-testid="route-map-selected-sla"'));
assert('2.10 Cada tarea del mapa muestra su propio contrato',
  mapSource.includes('data-testid="route-map-task-sla"')
    && mapSource.includes('taskSlaContract(task)'));
assert('2.11 El resumen del mapa rotula el motivo crítico con la clase correcta',
  TechnicianRouteMapModal.methods.slaReasonClass({ critical_reason: 'CONTRACTUAL_SLA' })
    === 'vg-sla-reason-badge--contractual'
    && TechnicianRouteMapModal.methods.stopSlaContract({ sla_tier_label: 'Estándar' }).tier_label === 'Estándar');

// -------------------------------------------------------------
// 3. Copia offline
// -------------------------------------------------------------
console.log('\n--- 3. Copia offline ---');

const snapshot = normalizeSnapshot({
  technician_id: 7,
  synced_at: '2026-11-09T08:00:00Z',
  route: {
    stops: [{
      id: 501,
      sla_tier_snapshot: 'CRITICAL_INFRASTRUCTURE_24_7',
      sla_target_minutes: 480,
      sla_target_at: '2026-11-09T16:00:00Z',
      critical_reason: 'COLD_CHAIN',
      machine: { id: 9, code: 'VEND-0901', contact_name: 'No debe persistirse' }
    }]
  }
});
const offlineStop = snapshot.route_envelope.stops[0];
assert('3.1 El paquete offline conserva perfil, objetivo y vencimiento',
  offlineStop.sla_tier_snapshot === 'CRITICAL_INFRASTRUCTURE_24_7'
    && offlineStop.sla_target_minutes === 480
    && offlineStop.sla_target_at === '2026-11-09T16:00:00Z');
assert('3.2 El paquete offline conserva el motivo crítico',
  offlineStop.critical_reason === 'COLD_CHAIN');
assert('3.3 La minimización sigue retirando el contacto personal',
  offlineStop.machine.contact_name === undefined);
assert('3.4 El helper describe la parada leída de IndexedDB',
  describeStopSlaContract(offlineStop).tier_label === 'Infraestructura Crítica 24x7');

// -------------------------------------------------------------
// 4. Portal de Sede
// -------------------------------------------------------------
console.log('\n--- 4. Portal de Sede ---');

const portalLabel = LocationPortalView.computed.locationSlaLabel.call({
  location: { sla_tier: 'PRIORITY_CORPORATE' }
});
assert('4.1 La cabecera deriva el nivel contractual de la sede autenticada',
  portalLabel === 'Prioritario Corporativo');
assert('4.2 La etiqueta publicada por el servidor tiene prioridad',
  LocationPortalView.computed.locationSlaLabel.call({
    location: { sla_tier_label: 'Infraestructura Crítica 24x7', sla_tier: 'STANDARD' }
  }) === 'Infraestructura Crítica 24x7');
assert('4.3 El plazo objetivo se expresa en horas naturales',
  LocationPortalView.methods.slaTargetLabel(1440) === '24 h'
    && LocationPortalView.methods.slaTargetLabel(720) === '12 h'
    && LocationPortalView.methods.slaTargetLabel(480) === '8 h');
assert('4.4 Los objetivos no exactos conservan los minutos',
  LocationPortalView.methods.slaTargetLabel(390) === '6 h 30 min');
assert('4.5 Un aviso sin objetivo sellado no inventa un plazo',
  LocationPortalView.methods.slaTargetLabel(null) === '—');

const portalSource = read('public/assets/js/views/LocationPortalView.js');
assert('4.6 La cabecera expone el contrato de la sede con testid propio',
  portalSource.includes('data-testid="location-portal-sla-tier"')
    && portalSource.includes('Contrato de sede:'));
assert('4.7 Cada aviso de la sede muestra su plazo objetivo comprometido',
  portalSource.includes('data-testid="location-portal-incident-sla"')
    && portalSource.includes('sla_target_minutes')
    && portalSource.includes('sla_target_at'));

// -------------------------------------------------------------
// 5. Superficies de Coordinación (T-SLA-12)
// -------------------------------------------------------------
console.log('\n--- 5. Superficies de Coordinación ---');

const adminSource = read('public/assets/js/components/AdminLocationsTab.js');
assert('5.1 El alta y la edición de sede ofrecen los tres niveles de SLA',
  adminSource.includes('data-testid="create-sla-tier"')
    && adminSource.includes('data-testid="edit-sla-tier"')
    && adminSource.includes('CRITICAL_INFRASTRUCTURE_24_7'));
assert('5.2 La tabla de sedes pinta la etiqueta contractual',
  adminSource.includes('data-testid="location-sla-tier"')
    && adminSource.includes('loc.sla_tier_label'));

const dashboardSource = read('public/assets/js/views/CoordinatorDashboardView.js');
assert('5.3 El triaje muestra el contrato sellado en la fila de sede',
  dashboardSource.includes('data-testid="row-sla-tier"')
    && dashboardSource.includes('inc.sla_tier_label'));

const detailSource = read('public/assets/js/components/CoordinatorIncidentDetailModal.js');
assert('5.4 El detalle muestra chip y tarjeta de contrato con vencimiento',
  detailSource.includes('data-testid="incident-detail-sla-tier"')
    && detailSource.includes('data-testid="detail-sla-contract"')
    && detailSource.includes('sla_target_minutes'));

const assignSource = read('public/assets/js/components/AssignSuggestionPanel.js');
assert('5.5 El escalado contractual 24/7 se distingue del sanitario',
  assignSource.includes('data-testid="assign-contractual-escalation-banner"')
    && assignSource.includes('data-testid="assign-contractual-escalation-badge"')
    && assignSource.includes('contractual_24_7_escalation_active'));
assert('5.6 Los bonos +60 y +100 quedan explícitos en pantalla',
  assignSource.includes('+60 pts') && assignSource.includes('+100 pts'));

const breakdownSource = read('public/assets/js/components/MetricBreakdownTable.js');
assert('5.7 El desglose por sede añade nivel, objetivo nominal y brechas',
  breakdownSource.includes('data-testid="breakdown-sla-tier"')
    && breakdownSource.includes('data-testid="breakdown-sla-breaches"')
    && breakdownSource.includes('Objetivo Nominal'));
assert('5.8 El estado SLA usa los tres veredictos honestos',
  breakdownSource.includes("label: 'Incumple'")
    && breakdownSource.includes("label: 'Cumple'")
    && breakdownSource.includes("label: 'Sin datos'"));

const metricsSource = read('public/assets/js/views/CoordinatorMetricsView.js');
assert('5.9 Métricas resume contratos, cumplimiento y brechas del periodo',
  metricsSource.includes('data-testid="sla-breakdown-summary"')
    && metricsSource.includes('slaBreakdownSummary'));

// -------------------------------------------------------------
// 6. Opacidad en QR y documentos A4
// -------------------------------------------------------------
console.log('\n--- 6. Opacidad QR y A4 ---');

const opacityPattern = /\bsla_(tier|target|due)|critical_reason/i;
for (const [label, path] of [
  ['QR público', 'public/assets/js/views/QrReportView.js'],
  ['Albarán de Servicio A4', 'public/assets/js/components/ServiceSheetModal.js'],
  ['Acta Oficial de Merma A4', 'public/assets/js/components/SanitarySpoilageOfficialActModal.js']
]) {
  assert(`6.x ${label} no expone el contrato comercial`, !opacityPattern.test(read(path)));
}

// -------------------------------------------------------------
// 7. App Shell, estilos y guardas de diseño
// -------------------------------------------------------------
console.log('\n--- 7. PWA, estilos y guardas ---');

const swSource = read('public/sw.js');
const cacheVersion = Number(/const CACHE_NAME = 'vendguard-app-shell-v(\d+)'/.exec(swSource)?.[1] ?? 0);
assert('7.1 El App Shell invalida la caché con la nueva versión',
  cacheVersion >= 11, `versión detectada: ${cacheVersion}`);

const stylesSource = read('public/assets/css/styles.css');
assert('7.2 Las clases de contrato SLA existen en la hoja compartida',
  stylesSource.includes('.vg-sla-contract-badge')
    && stylesSource.includes('.vg-sla-reason-badge--cold')
    && stylesSource.includes('.vg-sla-reason-badge--contractual')
    && stylesSource.includes('.vg-portal-incident-sla'));

const blockStart = stylesSource.indexOf('Módulo 30 · Contrato SLA');
const blockEnd = stylesSource.indexOf('@media (max-width: 1100px)', blockStart);
const slaCssBlock = blockStart >= 0 && blockEnd > blockStart
  ? stylesSource.slice(blockStart, blockEnd)
  : '';
assert('7.3 El bloque SLA no introduce literales hex ni sombras en reposo',
  slaCssBlock !== ''
    && !/#[0-9a-f]{3,8}/i.test(slaCssBlock)
    && !/box-shadow/i.test(slaCssBlock));

for (const guard of [
  'CoordinatorButtonVariantsGuardTest.mjs',
  'LegacyButtonPaletteRetirementTest.mjs',
  'DesignTokenDebtRatchetTest.mjs'
]) {
  const result = spawnSync(process.execPath, [join(HERE, guard)], {
    encoding: 'utf8',
    timeout: 120000
  });
  assert(`7.4 ${guard} permanece en verde`, result.status === 0,
    (result.stderr || result.stdout || '').slice(-500));
}

console.log('\n======================================================================');
console.log(` RESUMEN: ${assertions} aserciones, ${failures} fallo(s).`);
console.log(failures === 0
  ? ' CONDICIÓN T-SLA-13 VERIFICADA SATISFACTORIAMENTE.'
  : ' CONDICIÓN T-SLA-13 NO SUPERADA.');
console.log('======================================================================\n');

if (failures > 0) {
  process.exit(1);
}
