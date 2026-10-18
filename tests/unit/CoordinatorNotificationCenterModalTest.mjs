/**
 * VendGuard - Suite Frontend del Centro de Notificaciones de Coordinación (T-NOTIF-14)
 *
 * Convierte en cobertura versionada la sonda efímera que certificó T-NOTIF-12 y
 * cierra la condición «Hecho cuando» de T-NOTIF-14: la suite permanente del
 * cliente HTTP del módulo 14 y del componente que lo consume.
 *
 * 1. **Cliente `api.notifications`** contra el contrato técnico §4.1 a §4.6 con
 *    el transporte interceptado (URL, método y cuerpo reales de cada verbo):
 *    la lista blanca de filtros del listado, el reencolado individual y masivo,
 *    la cancelación con motivo, la lectura y el guardado de la configuración
 *    (con `is_webhook_enabled` como booleano real, jamás texto) y la prueba de
 *    conexión que sólo adjunta los campos presentes en el formulario.
 * 2. **Componente `CoordinatorNotificationCenterModal`** importado en Node:
 *    contrato ESM, accesibilidad del diálogo, las dos pestañas, las cuatro
 *    tarjetas KPI, los 6 estados canónicos con sus parejas semánticas de
 *    tokens, la validación reactiva `https://` del canal corporativo, la
 *    paginación y las cinco acciones contra la API simulada.
 * 3. **Frontera con el panel (T-NOTIF-13):** el modal se monta en la barra del
 *    panel y el evento `outbox-updated` alimenta la insignia roja, de modo que
 *    un reintento apaga la alerta sin esperar al sondeo.
 *
 * Dogma Vanilla: JavaScript puro en módulos ES nativos (cero dependencias).
 * Dualismo Lingüístico: código e identificadores en inglés, documentación y
 * descripciones de aserción en castellano.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

// Mock de localStorage para el arranque sin navegador de api.js (Módulo 04).
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

import { api } from '../../public/assets/js/api.js';
import DefaultExport, {
  CoordinatorNotificationCenterModal
} from '../../public/assets/js/components/CoordinatorNotificationCenterModal.js';
import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const COMPONENT_PATH = path.resolve(HERE, '../../public/assets/js/components/CoordinatorNotificationCenterModal.js');
const COMPONENT_SOURCE = fs.readFileSync(COMPONENT_PATH, 'utf8');

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

// ---------------------------------------------------------------------
// Arnés de transporte: intercepta fetch y devuelve la envolvente canónica.
// ---------------------------------------------------------------------
let lastFetchCall = null;
let mockFetchResponse = null;

globalThis.fetch = async (url, options) => {
  lastFetchCall = { url, options };
  return mockFetchResponse;
};

function jsonEnvelope(data, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    statusText: 'OK',
    headers: new Map([['content-type', 'application/json']]),
    json: async () => ({ success: true, data })
  };
}

// ---------------------------------------------------------------------
// Fixtures del contrato §4.1 y §4.5
// ---------------------------------------------------------------------
const OUTBOX_SUMMARY = {
  in_queue_count: 4,
  pending_count: 2,
  processing_count: 0,
  pending_retry_count: 2,
  sent_last_24h_count: 48,
  failed_permanent_count: 1,
  cancelled_obsolete_count: 4,
  has_permanent_failures_alert: true,
  active_on_call_technicians_count: 2
};

const OUTBOX_ITEM = {
  id: 901,
  incident_id: 142,
  ticket_code: 'TICK-2026-00142',
  channel: 'EMAIL',
  priority: 'CRITICAL_SANITARY',
  template: 'COLD_CHAIN_IMMEDIATE_ALERT',
  template_label: 'Alerta Inmediata de Frío',
  recipient: 'contacto.sede-bcn-01@sede.vendguard.es',
  status: 'FAILED_PERMANENT',
  attempts: 5,
  max_attempts: 5,
  processed_at: '2026-10-16T19:40:00+02:00',
  last_error: 'SMTP 550 5.1.1 <malformed@invalid>: Recipient address rejected',
  obsolete_reason: null,
  tracking_url: 'https://vendguard.onrender.com/?site=SEDE-BCN-01&ticket=TICK-2026-00142',
  can_retry: true,
  can_cancel: true
};

const PENDING_ITEM = {
  id: 902,
  incident_id: 142,
  ticket_code: 'TICK-2026-00142',
  channel: 'WEBHOOK',
  priority: 'STANDARD',
  template: 'INCIDENT_OPENED_SITE',
  template_label: 'Aviso de apertura',
  recipient: 'https://hooks.slack.com/services/T000/B000/X000',
  status: 'PENDING',
  attempts: 0,
  max_attempts: 5,
  last_error: null,
  obsolete_reason: null,
  tracking_url: null,
  can_retry: false,
  can_cancel: true
};

const OUTBOX_DATA = {
  summary: OUTBOX_SUMMARY,
  items: [OUTBOX_ITEM, PENDING_ITEM],
  pagination: { total: 56, limit: 50, offset: 0 }
};

const SETTINGS_DATA = {
  webhook_name: 'Slack #emergencias-frio',
  webhook_url: 'https://hooks.slack.com/services/T000/B000/X000',
  is_webhook_enabled: true,
  last_tested_at: '2026-10-16T20:15:00+02:00',
  last_test_status: 'SUCCESS',
  last_test_error: null,
  on_call_technicians: [
    { id: 2, name: 'Carlos Ruiz', operator_code: 'OP-02', email: 'carlos.ruiz@vendguard.es' }
  ]
};

/**
 * Instancia ligera del componente: datos reales, métodos enlazados y computed
 * expuestos como getters, igual que los cablea Vue sin montar un DOM.
 */
function createModal(overrides = {}) {
  const emitted = [];
  const modal = {
    ...CoordinatorNotificationCenterModal.data(),
    ...overrides,
    $emit: (event, payload) => { emitted.push({ event, payload }); },
    getEmitted: () => emitted
  };

  for (const [methodName, method] of Object.entries(CoordinatorNotificationCenterModal.methods)) {
    modal[methodName] = method.bind(modal);
  }
  for (const [name, handler] of Object.entries(CoordinatorNotificationCenterModal.computed)) {
    Object.defineProperty(modal, name, {
      get: () => handler.call(modal),
      configurable: true
    });
  }

  return modal;
}

/**
 * Sustituye los verbos del dominio `notifications` por dobles controlables y
 * devuelve la función de restauración, para que ninguna aserción deje la API
 * compartida en un estado falso.
 */
const ORIGINAL_NOTIFICATIONS = { ...api.notifications };

function stubNotifications(overrides = {}) {
  for (const key of Object.keys(api.notifications)) {
    if (!(key in ORIGINAL_NOTIFICATIONS)) {
      delete api.notifications[key];
    }
  }
  Object.assign(api.notifications, ORIGINAL_NOTIFICATIONS, overrides);
}

function restoreNotifications() {
  for (const key of Object.keys(api.notifications)) {
    if (!(key in ORIGINAL_NOTIFICATIONS)) {
      delete api.notifications[key];
    }
  }
  Object.assign(api.notifications, ORIGINAL_NOTIFICATIONS);
}

console.log('======================================================================');
console.log(' VendGuard: Suite Frontend - Centro de Notificaciones de Coordinación (T-NOTIF-14)');
console.log('======================================================================\n');

// ---------------------------------------------------------------------
// GRUPO 1: Contrato del módulo ESM
// ---------------------------------------------------------------------
console.log('--- Grupo 1: Contrato del módulo ESM ---');

assert('1.1 El componente existe en disco', fs.existsSync(COMPONENT_PATH));
assert('1.2 El export nombrado y el export por defecto son el mismo componente',
  CoordinatorNotificationCenterModal === DefaultExport);
assert('1.3 El componente se declara como CoordinatorNotificationCenterModal',
  CoordinatorNotificationCenterModal.name === 'CoordinatorNotificationCenterModal');
assert('1.4 Declara la prop modelValue como booleana (v-model del panel)',
  CoordinatorNotificationCenterModal.props.modelValue.type === Boolean);
assert('1.5 Declara los tres eventos del diálogo',
  ['update:modelValue', 'close', 'outbox-updated'].every((event) =>
    CoordinatorNotificationCenterModal.emits.includes(event)));
assert('1.6 Publica una plantilla de render',
  typeof CoordinatorNotificationCenterModal.template === 'string'
    && CoordinatorNotificationCenterModal.template.length > 2000);
assert('1.7 Importa el cliente HTTP compartido y ningún otro módulo',
  /^import \{ api \} from '\.\.\/api\.js';$/m.test(COMPONENT_SOURCE)
    && (COMPONENT_SOURCE.match(/^import /gm) || []).length === 1);

// ---------------------------------------------------------------------
// GRUPO 2: Accesibilidad, cierre y ciclo de vida
// ---------------------------------------------------------------------
console.log('\n--- Grupo 2: Accesibilidad, cierre y ciclo de vida ---');

const template = CoordinatorNotificationCenterModal.template;

assert('2.1 La capa raíz sólo existe con modelValue y lleva su testid',
  template.includes('v-if="modelValue"') && template.includes('data-testid="modal-notification-center"'));
assert('2.2 El diálogo es WAI-ARIA (role, aria-modal y título asociado)',
  template.includes('role="dialog"')
    && template.includes('aria-modal="true"')
    && template.includes('aria-labelledby="notification-center-title"')
    && template.includes('id="notification-center-title"'));
assert('2.3 La cabecera ofrece el cierre con nombre accesible',
  template.includes('data-testid="btn-close-notification-center"')
    && template.includes('aria-label="Cerrar el centro de notificaciones"'));
assert('2.4 El clic en el fondo sólo cierra si el destino es la propia capa',
  template.includes('@click="handleBackdropClick"')
    && CoordinatorNotificationCenterModal.methods.handleBackdropClick.toString().includes('event.target === event.currentTarget'));

const escapeModal = createModal({ modelValue: true });
escapeModal.handleKeyDown({ key: 'Enter' });
assert('2.5 Una tecla distinta de Escape no cierra el diálogo',
  escapeModal.getEmitted().length === 0);
escapeModal.handleKeyDown({ key: 'Escape' });
assert('2.6 Escape cierra el diálogo emitiendo el v-model y el evento close',
  escapeModal.getEmitted().length === 2
    && escapeModal.getEmitted()[0].event === 'update:modelValue'
    && escapeModal.getEmitted()[0].payload === false
    && escapeModal.getEmitted()[1].event === 'close');

const closedModal = createModal({ modelValue: false });
closedModal.handleKeyDown({ key: 'Escape' });
assert('2.7 Escape no actúa cuando el diálogo está cerrado',
  closedModal.getEmitted().length === 0);

// El bloqueo del desplazamiento de fondo necesita un documento simulado.
const previousDocument = globalThis.document;
globalThis.document = { body: { style: {} } };
const scrollModal = createModal();
scrollModal.toggleScrollLock(true);
const lockedOverflow = globalThis.document.body.style.overflow;
scrollModal.toggleScrollLock(false);
assert('2.8 toggleScrollLock bloquea y libera el desplazamiento de fondo',
  lockedOverflow === 'hidden' && globalThis.document.body.style.overflow === '');

const windowEvents = { added: [], removed: [] };
const previousWindow = globalThis.window;
globalThis.window = {
  addEventListener: (type, handler) => windowEvents.added.push({ type, handler }),
  removeEventListener: (type, handler) => windowEvents.removed.push({ type, handler })
};
const lifecycleModal = createModal();
// Los ganchos de ciclo de vida no viven en `methods`: se invocan como los llama Vue.
CoordinatorNotificationCenterModal.mounted.call(lifecycleModal);
CoordinatorNotificationCenterModal.beforeUnmount.call(lifecycleModal);
globalThis.window = previousWindow;
globalThis.document = previousDocument;

assert('2.9 mounted registra el listener de teclado en la ventana',
  windowEvents.added.length === 1
    && windowEvents.added[0].type === 'keydown'
    && windowEvents.added[0].handler === lifecycleModal.handleKeyDown);
assert('2.10 beforeUnmount retira el mismo listener y libera el bloqueo',
  windowEvents.removed.length === 1
    && windowEvents.removed[0].type === 'keydown'
    && windowEvents.removed[0].handler === lifecycleModal.handleKeyDown);

assert('2.11 La cancelación pide el motivo en línea: nunca window.prompt',
  !/window\.prompt/.test(COMPONENT_SOURCE)
    && template.includes('data-testid="notification-cancel-panel"')
    && template.includes('data-testid="input-notification-cancel-reason"'));
assert('2.12 La pestaña Bandeja de Salida carga bandeja y configuración al abrir',
  CoordinatorNotificationCenterModal.methods.loadAll.toString().includes('this.loadOutbox')
    && CoordinatorNotificationCenterModal.methods.loadAll.toString().includes('this.loadSettings')
    && COMPONENT_SOURCE.includes('this.loadAll();'));

// ---------------------------------------------------------------------
// GRUPO 3: Pestaña Bandeja de Salida
// ---------------------------------------------------------------------
console.log('\n--- Grupo 3: Pestaña Bandeja de Salida ---');

assert('3.1 Declara las dos pestañas del centro',
  template.includes('data-testid="tab-notification-outbox"')
    && template.includes('data-testid="tab-notification-webhook"')
    && template.includes("activeTab = 'outbox'")
    && template.includes("activeTab = 'webhook'"));
assert('3.2 Ofrece las cuatro tarjetas KPI del contrato',
  ['kpi-in-queue', 'kpi-sent-24h', 'kpi-failed-permanent', 'kpi-on-call']
    .every((id) => template.includes(`data-testid="${id}"`)));
assert('3.3 La tarjeta de fallos cambia borde y color sólo cuando hay fallos',
  template.includes("borderColor: hasPermanentFailures ? 'var(--color-error)' : 'var(--color-hairline)'")
    && template.includes("color: hasPermanentFailures ? 'var(--color-error-text)' : 'var(--color-ink)'")
    && template.includes('Requiere revisión de Coordinación')
    && template.includes('Sin fallos pendientes'));
assert('3.4 El selector de estado ofrece los 6 estados canónicos más ALL',
  CoordinatorNotificationCenterModal.data().statusOptions.length === 7
    && CoordinatorNotificationCenterModal.data().statusOptions[0].value === 'ALL'
    && ['PENDING', 'PROCESSING', 'PENDING_RETRY', 'SENT', 'FAILED_PERMANENT', 'CANCELLED_OBSOLETE']
      .every((status) => CoordinatorNotificationCenterModal.data().statusOptions.some((option) => option.value === status)));
assert('3.5 Los cuatro filtros reactivos están presentes con su testid',
  template.includes('data-testid="filter-notification-status"')
    && template.includes('data-testid="filter-notification-channel"')
    && template.includes('data-testid="filter-notification-priority"')
    && template.includes('data-testid="filter-notification-ticket"'));
assert('3.6 El filtro de ticket admite Enter como atajo de búsqueda',
  template.includes('@keyup.enter="applyFilters"'));
assert('3.7 Cada fila publica el estado con su pareja semántica de tokens',
  template.includes(':data-status="item.status"')
    && template.includes('backgroundColor: statusMetaOf(item.status).background')
    && template.includes('color: statusMetaOf(item.status).color'));
assert('3.8 Las acciones de fila se ofrecen sólo cuando el servidor las autoriza',
  template.includes('v-if="item.can_retry"')
    && template.includes('v-if="item.can_cancel"')
    && template.includes('data-testid="btn-retry-notification"')
    && template.includes('data-testid="btn-cancel-notification"'));
assert('3.9 El reintento masivo se deshabilita sin fallos definitivos',
  template.includes('data-testid="btn-retry-all-failed"')
    && template.includes(':disabled="isActionRunning || failedPermanentCount === 0"'));
assert('3.10 La tabla y la barra de lote declaran el total y la página',
  template.includes('data-testid="notification-row"')
    && template.includes('{{ pagination.total }} notificación(es) · página {{ currentPage }} de {{ totalPages }}'));
assert('3.11 La paginación se gatea con los computed de frontera',
  template.includes('data-testid="btn-notification-prev-page"')
    && template.includes('data-testid="btn-notification-next-page"')
    && template.includes(':disabled="!canGoPrevious || isLoading"')
    && template.includes(':disabled="!canGoNext || isLoading"'));
assert('3.12 Expone estados de carga y de bandeja vacía',
  template.includes('data-testid="notification-loading-state"')
    && template.includes('data-testid="notification-empty-state"'));
assert('3.13 Los avisos de éxito y de error tienen su propia franja',
  template.includes('data-testid="notification-success-banner"')
    && template.includes('data-testid="notification-error-banner"'));
assert('3.14 La tabla publica la URL final del botón de seguimiento de cada correo',
  template.includes('Enlace de seguimiento')
    && template.includes('data-testid="outbox-tracking-url"')
    && template.includes(':href="item.tracking_url"')
    && template.includes(':title="item.tracking_url"')
    && template.includes('rel="noopener noreferrer"')
    && template.includes('target="_blank"'));
assert('3.15 Sin enlace (internos y Webhook) la celda muestra un guión, no una URL inventada',
  template.includes('v-if="item.tracking_url"')
    && template.includes('<span v-else style="color: var(--color-ink-muted);">—</span>')
    && OUTBOX_ITEM.tracking_url === 'https://vendguard.onrender.com/?site=SEDE-BCN-01&ticket=TICK-2026-00142'
    && PENDING_ITEM.tracking_url === null);

// ---------------------------------------------------------------------
// GRUPO 4: Pestaña Canal Corporativo de Emergencias y sistema de diseño
// ---------------------------------------------------------------------
console.log('\n--- Grupo 4: Canal Corporativo de Emergencias y sistema de diseño ---');

assert('4.1 El canal declara nombre, URL, activación y su testid',
  template.includes('data-testid="input-webhook-name"')
    && template.includes('data-testid="input-webhook-url"')
    && template.includes('data-testid="input-webhook-enabled"'));
assert('4.2 La URL inválida explica el motivo y marca aria-invalid',
  template.includes('data-testid="webhook-url-error"')
    && template.includes(':aria-invalid="webhookUrlError !== \'\''));
assert('4.3 El botón de prueba de conexión y el de guardado se gatean con los computed',
  template.includes('🔌 Probar conexión')
    && template.includes('data-testid="btn-test-webhook"')
    && template.includes(':disabled="!canTestWebhook"')
    && template.includes('data-testid="btn-save-webhook"')
    && template.includes(':disabled="!canSaveWebhook"'));
assert('4.4 El diagnóstico de la prueba se pinta con su resultado',
  template.includes('data-testid="webhook-test-result"')
    && template.includes('✅ Conexión verificada en {{ testResult.durationMs }} ms')
    && template.includes('⚠️ La prueba de conexión falló'));
assert('4.5 La relación de técnicos de guardia incluye la advertencia de lista vacía',
  template.includes('data-testid="on-call-technicians"')
    && template.includes('data-testid="on-call-empty-state"')
    && template.includes('No hay ningún técnico con la marca de guardia'));
assert('4.6 La URL se valida contra https:// antes de salir a la red',
  CoordinatorNotificationCenterModal.methods.isUrlHttps('https://hooks.example.org/x') === true
    && CoordinatorNotificationCenterModal.methods.isUrlHttps('http://hooks.example.org/x') === false);
assert('4.7 Todos los botones visten variantes del sistema de diseño',
  (template.match(/<button\b[^>]*>/g) || []).length >= 15
    && (template.match(/<button\b[^>]*>/g) || []).every((tag) => /class="vg-btn/.test(tag)));
assert('4.8 La geometría usa tokens de radio y borde hairline',
  template.includes('var(--radius-card)')
    && template.includes('var(--radius-interactive)')
    && template.includes('var(--color-hairline)')
    && template.includes('var(--color-canvas)')
    && template.includes('var(--font-display)')
    && template.includes('var(--font-body)'));
assert('4.9 Los 6 estados se pintan con el triplete semántico de tokens',
  ['var(--color-info-bg)', 'var(--color-info-text)', 'var(--color-neutral-bg)',
   'var(--color-warning-bg)', 'var(--color-warning-text)', 'var(--color-success-bg)',
   'var(--color-success-text)', 'var(--color-error-bg)', 'var(--color-error-text)',
   'var(--color-ink-muted)'].every((token) => (COMPONENT_SOURCE.match(new RegExp(token.replace(/[()]/g, '\\$&'), 'g')) || []).length >= 1));
assert('4.10 Cero literales hex nuevos en el componente',
  !/#[0-9a-fA-F]{3,8}\b/.test(COMPONENT_SOURCE));

// ---------------------------------------------------------------------
// GRUPO 5: Cliente HTTP contra el contrato (§4.1 a §4.7)
// ---------------------------------------------------------------------
console.log('\n--- Grupo 5: Cliente HTTP del módulo contra el contrato ---');

mockFetchResponse = jsonEnvelope(OUTBOX_DATA);
const outbox = await api.notifications.getOutbox({
  status: 'FAILED_PERMANENT',
  channel: 'ALL',
  priority: 'ALL',
  ticket_code: '   ',
  limit: 25,
  offset: 0
});
assert('5.1 getOutbox compone sólo la lista blanca de seis parámetros y omite los vacíos',
  lastFetchCall.url === '/api/coordinator/notifications/outbox?status=FAILED_PERMANENT&channel=ALL&priority=ALL&limit=25&offset=0'
    && lastFetchCall.options.method === 'GET',
  `URL obtenida: ${lastFetchCall.url}`);
assert('5.2 getOutbox desenvuelve el sobre y entrega contadores, ítems y paginación',
  outbox?.summary?.failed_permanent_count === 1
    && Array.isArray(outbox?.items) && outbox.items.length === 2
    && outbox?.pagination?.total === 56);

mockFetchResponse = jsonEnvelope(OUTBOX_DATA);
await api.notifications.getOutbox({ evil: 'DROP TABLE', sort: 'id' });
assert('5.3 Un filtro fuera de la lista blanca jamás llega a la URL',
  lastFetchCall.url === '/api/coordinator/notifications/outbox',
  `URL obtenida: ${lastFetchCall.url}`);

mockFetchResponse = jsonEnvelope({ id: 901, status: 'PENDING', attempts: 0 });
await api.notifications.retryItem(901);
assert('5.4 retryItem reencola por POST en la ruta individual del contrato',
  lastFetchCall.url === '/api/coordinator/notifications/outbox/901/retry'
    && lastFetchCall.options.method === 'POST',
  `URL obtenida: ${lastFetchCall.url}`);

mockFetchResponse = jsonEnvelope({ retried_count: 3, retried_ids: [901, 904, 912] });
const retried = await api.notifications.retryAllFailed();
assert('5.5 retryAllFailed publica el lote completo y devuelve el recuento',
  lastFetchCall.url === '/api/coordinator/notifications/outbox/retry-all-failed'
    && lastFetchCall.options.method === 'POST'
    && retried?.retried_count === 3);

mockFetchResponse = jsonEnvelope({ id: 901, status: 'CANCELLED_OBSOLETE' });
await api.notifications.cancelItem(901, '   Contacto telefónico directo   ');
assert('5.6 cancelItem recorta el motivo antes de enviarlo',
  lastFetchCall.url === '/api/coordinator/notifications/outbox/901/cancel'
    && lastFetchCall.options.body === JSON.stringify({ reason: 'Contacto telefónico directo' }),
  `Cuerpo obtenido: ${lastFetchCall.options.body}`);

mockFetchResponse = jsonEnvelope({ id: 902, status: 'CANCELLED_OBSOLETE' });
await api.notifications.cancelItem(902, '   ');
assert('5.7 cancelItem sin motivo envía un cuerpo vacío para que el servidor documente el suyo',
  lastFetchCall.options.body === '{}',
  `Cuerpo obtenido: ${lastFetchCall.options.body}`);

mockFetchResponse = jsonEnvelope(SETTINGS_DATA);
const settings = await api.notifications.getSettings();
assert('5.8 getSettings lee el canal y la guardia vigente por GET',
  lastFetchCall.url === '/api/coordinator/notifications/settings'
    && lastFetchCall.options.method === 'GET'
    && settings?.on_call_technicians?.length === 1);

mockFetchResponse = jsonEnvelope(SETTINGS_DATA);
await api.notifications.updateSettings({
  webhook_name: '  Slack #emergencias-frio  ',
  webhook_url: '  https://hooks.slack.com/services/T000/B000/X000  ',
  is_webhook_enabled: true
});
const settingsBody = JSON.parse(lastFetchCall.options.body);
assert('5.9 updateSettings guarda por PUT recortando nombre y URL',
  lastFetchCall.url === '/api/coordinator/notifications/settings'
    && lastFetchCall.options.method === 'PUT'
    && settingsBody.webhook_name === 'Slack #emergencias-frio'
    && settingsBody.webhook_url === 'https://hooks.slack.com/services/T000/B000/X000'
    && settingsBody.is_webhook_enabled === true);

await api.notifications.updateSettings({ is_webhook_enabled: undefined });
assert('5.10 updateSettings normaliza el interruptor a booleano real (nunca texto)',
  JSON.parse(lastFetchCall.options.body).is_webhook_enabled === false);

mockFetchResponse = jsonEnvelope({
  test_passed: true, status: 'SUCCESS', http_code: 200, duration_ms: 142,
  tested_at: '2026-10-16T20:36:00+02:00', error: null
});
const tested = await api.notifications.testWebhook({
  webhook_name: '  Slack #emergencias-frio  ',
  webhook_url: '   '
});
assert('5.11 testWebhook sólo adjunta los campos con contenido del formulario',
  lastFetchCall.url === '/api/coordinator/notifications/settings/test-webhook'
    && lastFetchCall.options.method === 'POST'
    && lastFetchCall.options.body === JSON.stringify({ webhook_name: 'Slack #emergencias-frio' })
    && tested?.test_passed === true,
  `Cuerpo obtenido: ${lastFetchCall.options.body}`);

mockFetchResponse = jsonEnvelope({ id: 5, is_on_call: true });
await api.admin.setUserOnCall(5, true);
assert('5.12 setUserOnCall marca la guardia por PATCH con booleano real',
  lastFetchCall.url === '/api/coordinator/users/5/on-call'
    && lastFetchCall.options.method === 'PATCH'
    && lastFetchCall.options.body === JSON.stringify({ is_on_call: true }));

// ---------------------------------------------------------------------
// GRUPO 6: Comportamiento del componente contra la API simulada
// ---------------------------------------------------------------------
console.log('\n--- Grupo 6: Comportamiento del componente ---');

let outboxCalls = [];
stubNotifications({
  getOutbox: async (filters) => { outboxCalls.push(filters); return OUTBOX_DATA; },
  getSettings: async () => SETTINGS_DATA
});

const modal = createModal();
await modal.loadOutbox();
assert('6.1 loadOutbox pide la página con los filtros vigentes y la paginación actual',
  outboxCalls.length === 1
    && outboxCalls[0].status === 'ALL'
    && outboxCalls[0].limit === 50
    && outboxCalls[0].offset === 0);
assert('6.2 loadOutbox vuelca resumen, ítems y paginación',
  modal.summary.failed_permanent_count === 1
    && modal.items.length === 2
    && modal.pagination.total === 56
    && modal.hasItems === true);
assert('6.3 loadOutbox publica outbox-updated con los contadores para la insignia del panel',
  modal.getEmitted().length === 1
    && modal.getEmitted()[0].event === 'outbox-updated'
    && modal.getEmitted()[0].payload.failed_permanent_count === 1);
assert('6.4 Los computed de KPI leen el resumen del servidor',
  modal.failedPermanentCount === 1
    && modal.hasPermanentFailures === true
    && modal.inQueueCount === 4
    && modal.sentLast24hCount === 48
    && modal.onCallCount === 2);

stubNotifications({ getOutbox: async () => { throw new Error('Bandeja caída'); } });
const failingModal = createModal();
await failingModal.loadOutbox();
assert('6.5 Un fallo del listado se cuenta al operador y apaga la carga',
  failingModal.errorMessage === 'Bandeja caída' && failingModal.isLoading === false);

stubNotifications({ getOutbox: async () => { throw new Error(''); } });
const silentModal = createModal();
await silentModal.loadOutbox();
assert('6.6 Un error sin mensaje usa el texto de respaldo en castellano',
  silentModal.errorMessage === 'No se pudo cargar la bandeja de salida.');

stubNotifications({ getSettings: async () => SETTINGS_DATA });
const settingsModal = createModal();
await settingsModal.loadSettings();
assert('6.7 loadSettings vuelca el canal corporativo y la guardia vigente',
  settingsModal.webhookForm.webhook_name === 'Slack #emergencias-frio'
    && settingsModal.webhookForm.is_webhook_enabled === true
    && settingsModal.webhookTest.status === 'SUCCESS'
    && settingsModal.onCallTechnicians.length === 1);
assert('6.8 lastTestLabel traduce el último resultado persistido',
  settingsModal.lastTestLabel === 'Última prueba: correcta'
    && createModal({ webhookTest: { status: 'FAILED', testedAt: null, error: null } }).lastTestLabel === 'Última prueba: fallida'
    && createModal().lastTestLabel === 'Sin pruebas registradas');

stubNotifications({ getSettings: async () => { throw new Error('Canal caído'); } });
const channelFailureModal = createModal({ errorMessage: 'Fallo previo de la bandeja' });
await channelFailureModal.loadSettings();
assert('6.9 Un fallo del canal no pisa el error que el operador ya está leyendo',
  channelFailureModal.errorMessage === 'Fallo previo de la bandeja');

stubNotifications({
  getOutbox: async (filters) => { outboxCalls.push(filters); return OUTBOX_DATA; },
  getSettings: async () => SETTINGS_DATA
});
outboxCalls = [];
const pagingModal = createModal();
pagingModal.pagination = { total: 120, limit: 50, offset: 50 };
pagingModal.filters.ticket_code = 'TICK-2026';
pagingModal.applyFilters();
assert('6.10 applyFilters vuelve a la primera página y conserva los filtros escritos',
  pagingModal.pagination.offset === 0
    && outboxCalls.length === 1
    && outboxCalls[0].ticket_code === 'TICK-2026'
    && outboxCalls[0].offset === 0);

outboxCalls = [];
pagingModal.goToPage(1);
assert('6.11 goToPage avanza un bloque completo cuando queda página siguiente',
  pagingModal.pagination.offset === 50 && outboxCalls.length === 1);

outboxCalls = [];
pagingModal.pagination = { total: 120, limit: 50, offset: 0 };
pagingModal.goToPage(-1);
assert('6.12 goToPage no retrocede más allá del primer bloque',
  pagingModal.pagination.offset === 0 && outboxCalls.length === 0);

pagingModal.pagination = { total: 120, limit: 50, offset: 100 };
pagingModal.goToPage(1);
assert('6.13 goToPage no pide una página más allá del total filtrado',
  pagingModal.pagination.offset === 100 && outboxCalls.length === 0);

outboxCalls = [];
pagingModal.clearFilters();
assert('6.14 clearFilters devuelve los cuatro filtros a su valor inicial y recarga',
  pagingModal.filters.status === 'ALL'
    && pagingModal.filters.channel === 'ALL'
    && pagingModal.filters.priority === 'ALL'
    && pagingModal.filters.ticket_code === ''
    && outboxCalls.length === 1);

assert('6.15 La paginación calcula páginas con un límite degenerado sin dividir por cero',
  createModal({ pagination: { total: 10, limit: 0, offset: 0 } }).totalPages === 1
    && createModal({ pagination: { total: 120, limit: 50, offset: 50 } }).currentPage === 2
    && createModal({ pagination: { total: 120, limit: 50, offset: 50 } }).canGoPrevious === true
    && createModal({ pagination: { total: 120, limit: 50, offset: 120 } }).canGoNext === false);

const retryCalls = [];
stubNotifications({
  getOutbox: async (filters) => { outboxCalls.push(filters); return OUTBOX_DATA; },
  retryItem: async (id) => { retryCalls.push(id); return { id, status: 'PENDING' }; }
});
outboxCalls = [];
const retryModal = createModal();
await retryModal.retryNotification(OUTBOX_ITEM);
assert('6.16 retryNotification reencola el envío y refresca la bandeja',
  retryCalls.length === 1 && retryCalls[0] === 901
    && retryModal.successMessage.includes('#901')
    && outboxCalls.length === 1);

stubNotifications({ retryItem: async () => { throw new Error('409 INVALID_OUTBOX_STATUS_FOR_RETRY'); } });
await retryModal.retryNotification(OUTBOX_ITEM);
assert('6.17 Un rechazo del servidor al reintentar se muestra tal cual',
  retryModal.errorMessage === '409 INVALID_OUTBOX_STATUS_FOR_RETRY');

retryCalls.length = 0;
retryModal.isActionRunning = true;
await retryModal.retryNotification(OUTBOX_ITEM);
assert('6.18 retryNotification no dispara dos acciones simultáneas',
  retryCalls.length === 0 && retryModal.isActionRunning === true);
retryModal.isActionRunning = false;

stubNotifications({
  getOutbox: async () => OUTBOX_DATA,
  retryAllFailed: async () => ({ retried_count: 3, retried_ids: [901, 904, 912] })
});
const batchModal = createModal();
await batchModal.retryAllFailed();
assert('6.19 El reintento masivo informa del número reencolado',
  batchModal.successMessage.includes('3 notificaciones fallidas'));

stubNotifications({
  getOutbox: async () => OUTBOX_DATA,
  retryAllFailed: async () => ({ retried_count: 0, retried_ids: [] })
});
const emptyBatchModal = createModal();
await emptyBatchModal.retryAllFailed();
assert('6.20 El reintento masivo sin fallos lo dice sin inventar un recuento',
  emptyBatchModal.successMessage === 'No había notificaciones fallidas que reencolar.');

const cancelCalls = [];
stubNotifications({
  getOutbox: async () => OUTBOX_DATA,
  cancelItem: async (id, reason) => { cancelCalls.push({ id, reason }); return { id, status: 'CANCELLED_OBSOLETE' }; }
});
const cancelModal = createModal();
cancelModal.askCancel(OUTBOX_ITEM);
assert('6.21 askCancel abre la confirmación en línea con el envío señalado',
  cancelModal.cancelTarget?.id === 901 && cancelModal.cancelReason === '');
cancelModal.cancelReason = 'Contacto telefónico directo';
await cancelModal.confirmCancel();
assert('6.22 confirmCancel envía el motivo y cierra el panel tras la cancelación',
  cancelCalls.length === 1
    && cancelCalls[0].id === 901
    && cancelCalls[0].reason === 'Contacto telefónico directo'
    && cancelModal.cancelTarget === null
    && cancelModal.successMessage.includes('#901'));

cancelCalls.length = 0;
cancelModal.dismissCancel();
await cancelModal.confirmCancel();
assert('6.23 Sin envío señalado la cancelación no sale a la red',
  cancelCalls.length === 0);

const urlErrorCases = [
  ['', false, ''],
  ['', true, 'obligatoria'],
  ['http://hooks.example.org/x', true, 'https://'],
  ['https://', false, 'host válido']
];
const urlErrorResults = urlErrorCases.map(([url, enabled, expectedFragment]) => {
  const candidate = createModal({
    webhookForm: { webhook_name: 'Canal', webhook_url: url, is_webhook_enabled: enabled }
  });
  return candidate.webhookUrlError.toLowerCase().includes(expectedFragment.toLowerCase());
});
assert('6.24 La validación reactiva cubre vacío, esquema y host ausente',
  urlErrorResults.every(Boolean));

const validWebhookModal = createModal({
  webhookForm: { webhook_name: 'Canal', webhook_url: 'https://hooks.example.org/x', is_webhook_enabled: true }
});
assert('6.25 Guardar y probar se habilitan con una dirección https:// válida',
  validWebhookModal.webhookUrlError === ''
    && validWebhookModal.canSaveWebhook === true
    && validWebhookModal.canTestWebhook === true);
assert('6.26 Guardar se bloquea mientras hay una escritura en vuelo',
  createModal({
    isSavingWebhook: true,
    webhookForm: { webhook_name: 'Canal', webhook_url: 'https://hooks.example.org/x', is_webhook_enabled: true }
  }).canSaveWebhook === false);

const savedPayloads = [];
stubNotifications({
  getOutbox: async () => OUTBOX_DATA,
  updateSettings: async (payload) => {
    savedPayloads.push(payload);
    return { ...SETTINGS_DATA, webhook_url: payload.webhook_url, is_webhook_enabled: payload.is_webhook_enabled };
  }
});
const saveModal = createModal({
  webhookForm: { webhook_name: 'Canal', webhook_url: 'https://hooks.example.org/x', is_webhook_enabled: true }
});
await saveModal.saveWebhook();
assert('6.27 saveWebhook guarda el formulario y confirma al operador',
  savedPayloads.length === 1
    && savedPayloads[0].webhook_url === 'https://hooks.example.org/x'
    && saveModal.successMessage.includes('canal corporativo'));

await saveModal.saveWebhook();
saveModal.webhookForm.webhook_url = 'http://inseguro.example.org/x';
const payloadsBeforeInvalidSave = savedPayloads.length;
await saveModal.saveWebhook();
assert('6.28 Una dirección inválida no llega a salir: el servidor no recibe basura',
  savedPayloads.length === payloadsBeforeInvalidSave);

const testPayloads = [];
stubNotifications({
  getOutbox: async () => OUTBOX_DATA,
  getSettings: async () => SETTINGS_DATA,
  testWebhook: async (payload) => {
    testPayloads.push(payload);
    return { test_passed: true, status: 'SUCCESS', http_code: 200, duration_ms: 142, tested_at: '2026-10-16T20:36:00+02:00', error: null };
  }
});
const testModal = createModal({
  webhookForm: { webhook_name: 'Canal', webhook_url: 'https://hooks.example.org/x', is_webhook_enabled: true }
});
await testModal.testConnection();
assert('6.29 testConnection publica el diagnóstico inmediato (éxito con duración y HTTP)',
  testPayloads.length === 1
    && testModal.testResult.passed === true
    && testModal.testResult.durationMs === 142
    && testModal.testResult.httpCode === 200
    && testModal.isTestingWebhook === false);

stubNotifications({
  getOutbox: async () => OUTBOX_DATA,
  getSettings: async () => SETTINGS_DATA,
  testWebhook: async () => { throw new Error('WEBHOOK_TEST_TIMEOUT'); }
});
const testFailureModal = createModal({
  webhookForm: { webhook_name: 'Canal', webhook_url: 'https://hooks.example.org/x', is_webhook_enabled: true }
});
await testFailureModal.testConnection();
assert('6.30 Una prueba fallida deja el motivo a la vista sin romper la pestaña',
  testFailureModal.testResult.passed === false
    && testFailureModal.testResult.error === 'WEBHOOK_TEST_TIMEOUT'
    && testFailureModal.isTestingWebhook === false);

const helperModal = createModal();
assert('6.31 Los metadatos de presentación traducen estado, prioridad, canal e intentos',
  helperModal.statusMetaOf('FAILED_PERMANENT').label === 'Fallo definitivo'
    && helperModal.statusMetaOf('FAILED_PERMANENT').color === 'var(--color-error-text)'
    && helperModal.statusMetaOf('DESCONOCIDO').label === 'DESCONOCIDO'
    && helperModal.priorityMetaOf('CRITICAL_SANITARY').label === 'Crítica sanitaria'
    && helperModal.priorityMetaOf('OTRA').label === 'Ordinaria'
    && helperModal.channelLabel('WEBHOOK') === 'Webhook'
    && helperModal.channelLabel('EMAIL') === 'Correo'
    && helperModal.attemptLabel({ attempts: 5, max_attempts: 5 }) === '5/5'
    && helperModal.attemptLabel({}) === '0/5'
    && helperModal.ticketLabel(OUTBOX_ITEM) === 'TICK-2026-00142'
    && helperModal.ticketLabel({ incident_id: 142 }) === '#142'
    && helperModal.ticketLabel({}) === '—');
assert('6.32 formatDate no inventa fechas: marca el hueco, respeta el valor ilegible y formatea en es-ES',
  helperModal.formatDate(null) === '—'
    && helperModal.formatDate('no-es-fecha') === 'no-es-fecha'
    && helperModal.formatDate('2026-10-16T19:40:00+02:00').includes('16/10')
    && helperModal.formatDate('2026-10-16T19:40:00+02:00').includes('19:40'));

stubNotifications({ getOutbox: async () => OUTBOX_DATA, getSettings: async () => SETTINGS_DATA });
const loaderModal = createModal();
await loaderModal.loadAll();
assert('6.33 loadAll carga las dos superficies del centro en la misma apertura',
  loaderModal.items.length === 2 && loaderModal.webhookForm.webhook_url === SETTINGS_DATA.webhook_url);

restoreNotifications();

// ---------------------------------------------------------------------
// GRUPO 7: Frontera con el panel de coordinación (T-NOTIF-13)
// ---------------------------------------------------------------------
console.log('\n--- Grupo 7: Frontera con el panel de coordinación ---');

const dashboardTemplate = CoordinatorDashboardView.template;

assert('7.1 El panel registra el centro de notificaciones como componente',
  Boolean(CoordinatorDashboardView.components.CoordinatorNotificationCenterModal)
    && Boolean(CoordinatorDashboardView.components.CoordinatorNotificationCenterModal.name));
assert('7.2 El modal se monta en la vista y no en una pestaña concreta',
  dashboardTemplate.includes('<CoordinatorNotificationCenterModal')
    && dashboardTemplate.includes('v-model="showNotificationModal"'));
assert('7.3 El panel alimenta su insignia con el evento del modal',
  dashboardTemplate.includes('@outbox-updated="handleNotificationOutboxUpdated"')
    && typeof CoordinatorDashboardView.methods.handleNotificationOutboxUpdated === 'function');
assert('7.4 El botón y la insignia de alerta viven en la barra de pestañas',
  dashboardTemplate.includes('data-testid="open-notification-center"')
    && dashboardTemplate.includes('🔔 Notificaciones')
    && dashboardTemplate.includes('data-testid="notification-failure-badge"'));

const dashboardView = {
  showNotificationModal: false,
  notificationSummary: { in_queue_count: 0, failed_permanent_count: 0, has_permanent_failures_alert: false }
};
Object.assign(dashboardView, {
  openNotificationCenter: CoordinatorDashboardView.methods.openNotificationCenter,
  handleNotificationOutboxUpdated: CoordinatorDashboardView.methods.handleNotificationOutboxUpdated,
  applyNotificationSummary: CoordinatorDashboardView.methods.applyNotificationSummary
});
for (const name of ['permanentNotificationFailures', 'hasPermanentNotificationFailures']) {
  Object.defineProperty(dashboardView, name, {
    get: () => CoordinatorDashboardView.computed[name].call(dashboardView),
    configurable: true
  });
}

dashboardView.handleNotificationOutboxUpdated({ in_queue_count: 2, failed_permanent_count: 3, has_permanent_failures_alert: true });
assert('7.5 El contador del modal enciende la insignia roja del panel',
  dashboardView.permanentNotificationFailures === 3
    && dashboardView.hasPermanentNotificationFailures === true);
dashboardView.handleNotificationOutboxUpdated({ in_queue_count: 2, failed_permanent_count: 0, has_permanent_failures_alert: false });
assert('7.6 Reintentar el último fallo apaga la insignia sin esperar al sondeo',
  dashboardView.permanentNotificationFailures === 0
    && dashboardView.hasPermanentNotificationFailures === false);
dashboardView.handleNotificationOutboxUpdated({ in_queue_count: 1, failed_permanent_count: 2, has_permanent_failures_alert: true });
dashboardView.handleNotificationOutboxUpdated(null);
assert('7.7 Un resumen ausente conserva la alerta vigente en vez de apagarla a ciegas',
  dashboardView.permanentNotificationFailures === 2
    && dashboardView.hasPermanentNotificationFailures === true);
dashboardView.handleNotificationOutboxUpdated({ in_queue_count: 0, failed_permanent_count: 0, has_permanent_failures_alert: false });
dashboardView.openNotificationCenter();
assert('7.8 El botón de la barra abre el diálogo del centro',
  dashboardView.showNotificationModal === true);

// ---------------------------------------------------------------------
// Resumen
// ---------------------------------------------------------------------
console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% EN VERDE. CONDICIÓN T-NOTIF-14 CUMPLIDA SATISFACTORIAMENTE.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
