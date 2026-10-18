/**
 * VendGuard - CoordinatorNotificationCenterModal (CoordinatorNotificationCenterModal.js)
 *
 * Centro de Notificaciones de Coordinación: supervisión de la bandeja de salida
 * garantizada y configuración del Canal Corporativo de Emergencias (Módulo 14,
 * RF-06.3, RF-06.4, RF-06.5 y RNF-05).
 *
 * Pestaña 1 · Bandeja de Salida:
 *  - Tarjetas KPI: en cola, enviadas en las últimas 24 h, fallos definitivos
 *    (resaltados cuando hay alguno) y técnicos de guardia activos.
 *  - Filtros reactivos por los 6 estados canónicos, canal, prioridad y código de
 *    ticket, con paginación sobre el total filtrado.
 *  - Tabla con insignias semánticas de estado, intentos, último error y las
 *    acciones «Reintentar envío» y «Cancelar envío» que el contrato autoriza
 *    (`can_retry` / `can_cancel`).
 *  - «Reintentar todas las fallidas» para el lote completo.
 *
 * Pestaña 2 · Canal Corporativo de Emergencias:
 *  - Nombre identificativo, dirección `https://` con validación en línea y
 *    selector de activación.
 *  - Botón «🔌 Probar conexión» con su diagnóstico inmediato (éxito o motivo del
 *    fallo) y la marca de la última prueba persistida.
 *  - Relación de técnicos con la marca de guardia vigente.
 *
 * El componente no decide reglas de negocio: el servidor revalida el destino
 * anti-SSRF, el estado que admite reintento/cancelación y el refresco del
 * destinatario. Aquí sólo se evita mandar una petición que el contrato ya sabe
 * que va a rechazar (por ejemplo, una URL sin `https://`).
 *
 * Dogma Vanilla: Vue 3 Options API vía ES Modules, sin dependencias externas ni
 * paso de compilación. Los botones visten exclusivamente variantes del sistema
 * de diseño (`vg-btn-*`), sin geometría ni colores en línea.
 *
 * Dualismo Lingüístico: identificadores y métodos en inglés, interfaz y mensajes
 * de negocio en español.
 */

import { api } from '../api.js';

/**
 * Los 6 estados canónicos del contrato (RF-01.1), en el orden en que se ofrecen
 * al operador. `ALL` no es un estado: es la ausencia de filtro.
 */
const STATUS_OPTIONS = [
  { value: 'ALL', label: 'Todos los estados' },
  { value: 'PENDING', label: 'Pendiente' },
  { value: 'PROCESSING', label: 'En proceso' },
  { value: 'PENDING_RETRY', label: 'Pendiente de reintento' },
  { value: 'SENT', label: 'Enviada' },
  { value: 'FAILED_PERMANENT', label: 'Fallo definitivo' },
  { value: 'CANCELLED_OBSOLETE', label: 'Cancelada por obsolescencia' }
];

const CHANNEL_OPTIONS = [
  { value: 'ALL', label: 'Todos los canales' },
  { value: 'EMAIL', label: 'Correo electrónico' },
  { value: 'WEBHOOK', label: 'Webhook de emergencias' },
  // Módulo 15: canal personal del técnico (aviso de asignación).
  { value: 'TELEGRAM', label: 'Telegram del técnico' }
];

const PRIORITY_OPTIONS = [
  { value: 'ALL', label: 'Todas las prioridades' },
  { value: 'CRITICAL_SANITARY', label: 'Crítica sanitaria (Art. II)' },
  { value: 'STANDARD', label: 'Ordinaria' }
];

/**
 * Metadatos visuales de cada estado. Las parejas fondo/texto salen del triplete
 * semántico de los tokens de diseño, de modo que la insignia cumple el contraste
 * AA sin escribir un color a mano.
 */
const STATUS_META = {
  PENDING: {
    label: 'Pendiente',
    icon: '⏳',
    background: 'var(--color-info-bg)',
    color: 'var(--color-info-text)'
  },
  PROCESSING: {
    label: 'En proceso',
    icon: '🔄',
    background: 'var(--color-neutral-bg)',
    color: 'var(--color-info-text)'
  },
  PENDING_RETRY: {
    label: 'Pendiente de reintento',
    icon: '↻',
    background: 'var(--color-warning-bg)',
    color: 'var(--color-warning-text)'
  },
  SENT: {
    label: 'Enviada',
    icon: '✓',
    background: 'var(--color-success-bg)',
    color: 'var(--color-success-text)'
  },
  FAILED_PERMANENT: {
    label: 'Fallo definitivo',
    icon: '✕',
    background: 'var(--color-error-bg)',
    color: 'var(--color-error-text)'
  },
  CANCELLED_OBSOLETE: {
    label: 'Cancelada por obsolescencia',
    icon: '∅',
    background: 'var(--color-neutral-bg)',
    color: 'var(--color-ink-muted)'
  }
};

const PRIORITY_META = {
  CRITICAL_SANITARY: {
    label: 'Crítica sanitaria',
    background: 'var(--color-error-bg)',
    color: 'var(--color-error-text)'
  },
  STANDARD: {
    label: 'Ordinaria',
    background: 'var(--color-neutral-bg)',
    color: 'var(--color-ink-secondary)'
  }
};

const EMPTY_SUMMARY = {
  in_queue_count: 0,
  pending_count: 0,
  processing_count: 0,
  pending_retry_count: 0,
  sent_last_24h_count: 0,
  failed_permanent_count: 0,
  cancelled_obsolete_count: 0,
  has_permanent_failures_alert: false,
  active_on_call_technicians_count: 0
};

export const CoordinatorNotificationCenterModal = {
  name: 'CoordinatorNotificationCenterModal',
  props: {
    /**
     * Controls dialog visibility (v-model).
     */
    modelValue: {
      type: Boolean,
      default: false
    }
  },
  emits: ['update:modelValue', 'close', 'outbox-updated'],
  data() {
    return {
      activeTab: 'outbox', // 'outbox' | 'webhook'
      statusOptions: STATUS_OPTIONS,
      channelOptions: CHANNEL_OPTIONS,
      priorityOptions: PRIORITY_OPTIONS,

      filters: {
        status: 'ALL',
        channel: 'ALL',
        priority: 'ALL',
        ticket_code: ''
      },

      summary: { ...EMPTY_SUMMARY },
      items: [],
      pagination: { total: 0, limit: 50, offset: 0 },

      isLoading: false,
      isActionRunning: false,
      errorMessage: '',
      successMessage: '',

      cancelTarget: null,
      cancelReason: '',

      webhookForm: {
        webhook_name: 'Canal Central de Emergencias',
        webhook_url: '',
        is_webhook_enabled: false
      },
      webhookTest: {
        status: null,
        testedAt: null,
        error: null
      },
      onCallTechnicians: [],
      isSavingWebhook: false,
      isTestingWebhook: false,
      testResult: null
    };
  },
  computed: {
    hasPermanentFailures() {
      return Number(this.summary.failed_permanent_count || 0) > 0;
    },
    failedPermanentCount() {
      return Number(this.summary.failed_permanent_count || 0);
    },
    inQueueCount() {
      return Number(this.summary.in_queue_count || 0);
    },
    sentLast24hCount() {
      return Number(this.summary.sent_last_24h_count || 0);
    },
    onCallCount() {
      return Number(this.summary.active_on_call_technicians_count || 0);
    },
    totalPages() {
      const limit = Number(this.pagination.limit || 0);
      if (limit <= 0) {
        return 1;
      }

      return Math.max(1, Math.ceil(Number(this.pagination.total || 0) / limit));
    },
    currentPage() {
      const limit = Number(this.pagination.limit || 1);
      return Math.floor(Number(this.pagination.offset || 0) / limit) + 1;
    },
    canGoPrevious() {
      return Number(this.pagination.offset || 0) > 0;
    },
    canGoNext() {
      const limit = Number(this.pagination.limit || 0);
      return limit > 0 && Number(this.pagination.offset || 0) + limit < Number(this.pagination.total || 0);
    },
    /**
     * La dirección es válida sólo si empieza por `https://` y tiene host. El
     * servidor aplica además el blindaje anti-SSRF; esta comprobación evita
     * enviar una petición que ya sabemos que va a ser rechazada.
     */
    webhookUrlError() {
      const url = String(this.webhookForm.webhook_url || '').trim();

      if (url === '') {
        return this.webhookForm.is_webhook_enabled
          ? 'La dirección del Webhook es obligatoria para activar el canal corporativo.'
          : '';
      }
      if (!/^https:\/\//i.test(url)) {
        return 'La dirección debe comenzar por https:// (protocolo cifrado obligatorio).';
      }
      if (!/^https:\/\/[^\s/]+/i.test(url)) {
        return 'La dirección no contiene un host válido detrás de https://.';
      }

      return '';
    },
    canSaveWebhook() {
      return !this.isSavingWebhook && this.webhookUrlError === '';
    },
    canTestWebhook() {
      const url = String(this.webhookForm.webhook_url || '').trim();
      return !this.isTestingWebhook && url !== '' && this.webhookUrlError === '' && this.isUrlHttps(url);
    },
    isWebhookConfigured() {
      return this.webhookForm.is_webhook_enabled === true;
    },
    lastTestLabel() {
      if (this.webhookTest.status === 'SUCCESS') {
        return 'Última prueba: correcta';
      }
      if (this.webhookTest.status === 'FAILED') {
        return 'Última prueba: fallida';
      }
      return 'Sin pruebas registradas';
    },
    hasItems() {
      return Array.isArray(this.items) && this.items.length > 0;
    },
    isOutboxEmpty() {
      return !this.isLoading && !this.hasItems;
    }
  },
  watch: {
    modelValue: {
      immediate: true,
      handler(isOpen) {
        this.toggleScrollLock(isOpen);

        if (isOpen) {
          this.errorMessage = '';
          this.successMessage = '';
          this.loadAll();
        }
      }
    }
  },
  mounted() {
    if (typeof window !== 'undefined') {
      window.addEventListener('keydown', this.handleKeyDown);
    }
  },
  beforeUnmount() {
    this.toggleScrollLock(false);

    if (typeof window !== 'undefined') {
      window.removeEventListener('keydown', this.handleKeyDown);
    }
  },
  methods: {
    close() {
      this.$emit('update:modelValue', false);
      this.$emit('close');
    },

    handleKeyDown(event) {
      if (event && event.key === 'Escape' && this.modelValue) {
        this.close();
      }
    },

    toggleScrollLock(isOpen) {
      if (typeof document === 'undefined' || !document.body) {
        return;
      }
      document.body.style.overflow = isOpen ? 'hidden' : '';
    },

    handleBackdropClick(event) {
      if (event && event.target === event.currentTarget) {
        this.close();
      }
    },

    /**
     * Carga en paralelo las dos superficies del centro: la bandeja (con sus
     * contadores) y la configuración del canal corporativo.
     */
    async loadAll() {
      await Promise.all([this.loadOutbox(), this.loadSettings()]);
    },

    async loadOutbox() {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        const data = this.unwrap(await api.notifications.getOutbox({
          ...this.filters,
          limit: this.pagination.limit,
          offset: this.pagination.offset
        }));

        this.summary = { ...EMPTY_SUMMARY, ...(data?.summary || {}) };
        this.items = Array.isArray(data?.items) ? data.items : [];
        this.pagination = {
          total: Number(data?.pagination?.total || 0),
          limit: Number(data?.pagination?.limit || this.pagination.limit || 50),
          offset: Number(data?.pagination?.offset || 0)
        };
        this.$emit('outbox-updated', { ...this.summary });
      } catch (error) {
        this.errorMessage = this.describeError(error, 'No se pudo cargar la bandeja de salida.');
      } finally {
        this.isLoading = false;
      }
    },

    async loadSettings() {
      try {
        const data = this.unwrap(await api.notifications.getSettings());
        this.webhookForm = {
          webhook_name: data?.webhook_name || 'Canal Central de Emergencias',
          webhook_url: data?.webhook_url || '',
          is_webhook_enabled: Boolean(data?.is_webhook_enabled)
        };
        this.webhookTest = {
          status: data?.last_test_status || null,
          testedAt: data?.last_tested_at || null,
          error: data?.last_test_error || null
        };
        this.onCallTechnicians = Array.isArray(data?.on_call_technicians) ? data.on_call_technicians : [];
      } catch (error) {
        // La bandeja y el canal son superficies independientes: un fallo al leer
        // el canal no debe vaciar la tabla que el operador ya tiene delante.
        if (!this.errorMessage) {
          this.errorMessage = this.describeError(error, 'No se pudo cargar la configuración del canal corporativo.');
        }
      }
    },

    /**
     * Aplica los filtros desde el principio de la colección: mantener el
     * `offset` anterior sobre un conjunto más pequeño devolvería una página vacía.
     */
    applyFilters() {
      this.pagination.offset = 0;
      this.loadOutbox();
    },

    clearFilters() {
      this.filters = { status: 'ALL', channel: 'ALL', priority: 'ALL', ticket_code: '' };
      this.applyFilters();
    },

    goToPage(delta) {
      const limit = Number(this.pagination.limit || 0);
      if (limit <= 0) {
        return;
      }

      const nextOffset = Number(this.pagination.offset || 0) + (limit * delta);
      if (nextOffset < 0 || nextOffset >= Number(this.pagination.total || 0)) {
        return;
      }

      this.pagination.offset = nextOffset;
      this.loadOutbox();
    },

    statusMetaOf(status) {
      return STATUS_META[status] || {
        label: String(status || '—'),
        icon: '•',
        background: 'var(--color-neutral-bg)',
        color: 'var(--color-ink-secondary)'
      };
    },

    priorityMetaOf(priority) {
      return PRIORITY_META[priority] || PRIORITY_META.STANDARD;
    },

    channelLabel(channel) {
      if (channel === 'WEBHOOK') {
        return 'Webhook';
      }
      if (channel === 'EMAIL') {
        return 'Correo';
      }
      if (channel === 'TELEGRAM') {
        return 'Telegram';
      }
      return String(channel || '—');
    },

    formatDate(value) {
      if (!value) {
        return '—';
      }

      const parsed = new Date(value);
      if (Number.isNaN(parsed.getTime())) {
        return String(value);
      }

      try {
        return new Intl.DateTimeFormat('es-ES', {
          dateStyle: 'short',
          timeStyle: 'short'
        }).format(parsed);
      } catch (error) {
        return parsed.toISOString();
      }
    },

    attemptLabel(item) {
      const attempts = Number(item?.attempts || 0);
      const max = Number(item?.max_attempts || 5);
      return `${attempts}/${max}`;
    },

    ticketLabel(item) {
      return item?.ticket_code || (item?.incident_id ? `#${item.incident_id}` : '—');
    },

    async retryNotification(item) {
      if (!item || this.isActionRunning) {
        return;
      }

      this.isActionRunning = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        await api.notifications.retryItem(item.id);
        this.successMessage = `Envío #${item.id} reencolado con el destinatario vigente.`;
        await this.loadOutbox();
      } catch (error) {
        this.errorMessage = this.describeError(error, `No se pudo reintentar el envío #${item.id}.`);
      } finally {
        this.isActionRunning = false;
      }
    },

    async retryAllFailed() {
      if (this.isActionRunning) {
        return;
      }

      this.isActionRunning = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        const data = this.unwrap(await api.notifications.retryAllFailed());
        const count = Number(data?.retried_count || 0);
        this.successMessage = count > 0
          ? `Se han reencolado ${count} notificaciones fallidas para nuevo intento de envío.`
          : 'No había notificaciones fallidas que reencolar.';
        await this.loadOutbox();
      } catch (error) {
        this.errorMessage = this.describeError(error, 'No se pudo reencolar el lote de fallos definitivos.');
      } finally {
        this.isActionRunning = false;
      }
    },

    /**
     * Abre la confirmación de cancelación en línea. Se pide el motivo —opcional
     * para el contrato— porque es la evidencia que queda en la bandeja y en la
     * auditoría; el servidor documenta un motivo por defecto si se deja vacío.
     */
    askCancel(item) {
      if (!item) {
        return;
      }
      this.cancelTarget = item;
      this.cancelReason = '';
      this.errorMessage = '';
      this.successMessage = '';
    },

    dismissCancel() {
      this.cancelTarget = null;
      this.cancelReason = '';
    },

    async confirmCancel() {
      if (!this.cancelTarget || this.isActionRunning) {
        return;
      }

      const target = this.cancelTarget;
      this.isActionRunning = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        await api.notifications.cancelItem(target.id, this.cancelReason);
        this.successMessage = `Envío #${target.id} cancelado por obsolescencia.`;
        this.dismissCancel();
        await this.loadOutbox();
      } catch (error) {
        this.errorMessage = this.describeError(error, `No se pudo cancelar el envío #${target.id}.`);
      } finally {
        this.isActionRunning = false;
      }
    },

    async saveWebhook() {
      if (!this.canSaveWebhook) {
        return;
      }

      this.isSavingWebhook = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        const data = this.unwrap(await api.notifications.updateSettings({
          webhook_name: this.webhookForm.webhook_name,
          webhook_url: this.webhookForm.webhook_url,
          is_webhook_enabled: this.webhookForm.is_webhook_enabled
        }));

        this.webhookForm = {
          webhook_name: data?.webhook_name || this.webhookForm.webhook_name,
          webhook_url: data?.webhook_url || '',
          is_webhook_enabled: Boolean(data?.is_webhook_enabled)
        };
        this.successMessage = 'Configuración del canal corporativo de emergencias actualizada.';
        await this.loadOutbox();
      } catch (error) {
        this.errorMessage = this.describeError(error, 'No se pudo guardar la configuración del canal corporativo.');
      } finally {
        this.isSavingWebhook = false;
      }
    },

    async testConnection() {
      if (!this.canTestWebhook) {
        return;
      }

      this.isTestingWebhook = true;
      this.testResult = null;
      this.errorMessage = '';

      try {
        const data = this.unwrap(await api.notifications.testWebhook({
          webhook_name: this.webhookForm.webhook_name,
          webhook_url: this.webhookForm.webhook_url
        }));

        this.testResult = {
          passed: data?.test_passed === true,
          status: data?.status || 'FAILED',
          httpCode: data?.http_code ?? null,
          durationMs: Number(data?.duration_ms || 0),
          testedAt: data?.tested_at || null,
          error: data?.error || null
        };
        await this.loadSettings();
      } catch (error) {
        this.testResult = {
          passed: false,
          status: 'FAILED',
          httpCode: null,
          durationMs: 0,
          testedAt: null,
          error: this.describeError(error, 'La prueba de conexión no pudo completarse.')
        };
      } finally {
        this.isTestingWebhook = false;
      }
    },

    isUrlHttps(url) {
      return /^https:\/\//i.test(String(url || '').trim());
    },

    unwrap(payload) {
      if (payload && typeof payload === 'object' && Object.prototype.hasOwnProperty.call(payload, 'data')) {
        return payload.data;
      }
      return payload;
    },

    describeError(error, fallback) {
      const message = error && error.message ? String(error.message) : '';
      return message !== '' ? message : fallback;
    }
  },
  template: `
    <div
      v-if="modelValue"
      class="vg-modal-backdrop"
      style="position: fixed; inset: 0; background-color: rgba(0, 0, 0, 0.45); display: flex; align-items: center; justify-content: center; z-index: 1050; padding: 16px;"
      @click="handleBackdropClick"
      data-testid="modal-notification-center"
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="notification-center-title"
        style="background-color: var(--color-surface-card); width: 100%; max-width: 1080px; max-height: 92vh; border-radius: var(--radius-card); border: 1px solid var(--color-hairline); box-shadow: var(--shadow-modal, 0 12px 32px rgba(0, 0, 0, 0.15)); display: flex; flex-direction: column; overflow: hidden;"
      >
        <!-- Cabecera -->
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--color-hairline); display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;">
          <div>
            <h3
              id="notification-center-title"
              style="margin: 0; font-family: var(--font-display); font-size: var(--font-size-heading-md); font-weight: 600; color: var(--color-ink); display: flex; align-items: center; gap: var(--spacing-sm);"
            >
              <span>🔔</span> Centro de Notificaciones
            </h3>
            <p style="margin: 4px 0 0 0; font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
              Bandeja de salida garantizada y Canal Corporativo de Emergencias · Art. II y Art. III de la Constitución.
            </p>
          </div>
          <button
            type="button"
            class="vg-btn vg-btn-icon vg-btn-ghost vg-btn-ghost-muted"
            title="Cerrar el centro de notificaciones"
            aria-label="Cerrar el centro de notificaciones"
            @click="close"
            data-testid="btn-close-notification-center"
          >✕</button>
        </div>

        <!-- Pestañas -->
        <div style="display: flex; border-bottom: 1px solid var(--color-hairline); padding: 0 var(--spacing-base); background-color: var(--color-surface-card);">
          <button
            type="button"
            class="vg-btn vg-btn-tab"
            :class="{ 'vg-btn-tab-active': activeTab === 'outbox' }"
            @click="activeTab = 'outbox'"
            data-testid="tab-notification-outbox"
          >
            📤 Bandeja de Salida
          </button>
          <button
            type="button"
            class="vg-btn vg-btn-tab"
            :class="{ 'vg-btn-tab-active': activeTab === 'webhook' }"
            @click="activeTab = 'webhook'"
            data-testid="tab-notification-webhook"
          >
            🔌 Canal de Emergencias
          </button>
        </div>

        <!-- Cuerpo -->
        <div style="padding: var(--spacing-base); overflow-y: auto; flex: 1 1 auto; display: flex; flex-direction: column; gap: var(--spacing-base);">
          <!-- Avisos -->
          <div
            v-if="successMessage"
            style="background-color: var(--color-success-bg); border: 1px solid var(--color-success); color: var(--color-success-text); padding: 10px 14px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: var(--font-size-body-sm); display: flex; justify-content: space-between; align-items: center; gap: 8px;"
            data-testid="notification-success-banner"
          >
            <span>✅ {{ successMessage }}</span>
            <button
              type="button"
              class="vg-btn vg-btn-icon vg-btn-ghost vg-btn-ghost-success"
              title="Cerrar el aviso"
              @click="successMessage = ''"
            >✕</button>
          </div>

          <div
            v-if="errorMessage"
            style="background-color: var(--color-error-bg); border: 1px solid var(--color-error); color: var(--color-error-text); padding: 10px 14px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: var(--font-size-body-sm); display: flex; justify-content: space-between; align-items: center; gap: 8px;"
            data-testid="notification-error-banner"
          >
            <span>⚠️ {{ errorMessage }}</span>
            <button
              type="button"
              class="vg-btn vg-btn-icon vg-btn-ghost vg-btn-ghost-danger"
              title="Cerrar el aviso"
              @click="errorMessage = ''"
            >✕</button>
          </div>

          <!-- ============================================================= -->
          <!-- PESTAÑA 1 · BANDEJA DE SALIDA                                 -->
          <!-- ============================================================= -->
          <div v-if="activeTab === 'outbox'" style="display: flex; flex-direction: column; gap: var(--spacing-base);">
            <!-- Tarjetas KPI -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: var(--spacing-md);">
              <div
                style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: var(--spacing-md) var(--spacing-base);"
                data-testid="kpi-in-queue"
              >
                <div style="font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); text-transform: uppercase; letter-spacing: 0.04em;">
                  En cola
                </div>
                <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); font-weight: 600; color: var(--color-ink); margin-top: 4px;">
                  {{ inQueueCount }}
                </div>
                <div style="font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                  {{ summary.pending_count || 0 }} pendientes · {{ summary.pending_retry_count || 0 }} en espera
                </div>
              </div>

              <div
                style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: var(--spacing-md) var(--spacing-base);"
                data-testid="kpi-sent-24h"
              >
                <div style="font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); text-transform: uppercase; letter-spacing: 0.04em;">
                  Enviadas (24 h)
                </div>
                <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); font-weight: 600; color: var(--color-success-text); margin-top: 4px;">
                  {{ sentLast24hCount }}
                </div>
                <div style="font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                  Confirmadas por el proveedor
                </div>
              </div>

              <div
                style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: var(--spacing-md) var(--spacing-base);"
                :style="{ borderColor: hasPermanentFailures ? 'var(--color-error)' : 'var(--color-hairline)' }"
                data-testid="kpi-failed-permanent"
              >
                <div style="font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); text-transform: uppercase; letter-spacing: 0.04em;">
                  Fallos definitivos
                </div>
                <div
                  style="font-family: var(--font-display); font-size: var(--font-size-display-sm); font-weight: 600; margin-top: 4px;"
                  :style="{ color: hasPermanentFailures ? 'var(--color-error-text)' : 'var(--color-ink)' }"
                >
                  {{ failedPermanentCount }}
                </div>
                <div style="font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                  {{ hasPermanentFailures ? 'Requiere revisión de Coordinación' : 'Sin fallos pendientes' }}
                </div>
              </div>

              <div
                style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: var(--spacing-md) var(--spacing-base);"
                data-testid="kpi-on-call"
              >
                <div style="font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); text-transform: uppercase; letter-spacing: 0.04em;">
                  Técnicos de guardia
                </div>
                <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); font-weight: 600; color: var(--color-info-text); margin-top: 4px;">
                  {{ onCallCount }}
                </div>
                <div style="font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                  Reciben las alertas sin asignar
                </div>
              </div>
            </div>

            <!-- Filtros -->
            <div
              style="background-color: var(--color-canvas); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: var(--spacing-md); display: flex; flex-wrap: wrap; align-items: flex-end; gap: var(--spacing-md);"
            >
              <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-slate); flex: 1 1 170px;">
                Estado
                <select v-model="filters.status" class="vg-select" data-testid="filter-notification-status">
                  <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
              </label>

              <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-slate); flex: 0 1 170px;">
                Canal
                <select v-model="filters.channel" class="vg-select" data-testid="filter-notification-channel">
                  <option v-for="option in channelOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
              </label>

              <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-slate); flex: 0 1 190px;">
                Prioridad
                <select v-model="filters.priority" class="vg-select" data-testid="filter-notification-priority">
                  <option v-for="option in priorityOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
              </label>

              <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-slate); flex: 1 1 190px;">
                Código de ticket
                <input
                  v-model="filters.ticket_code"
                  type="search"
                  class="vg-input"
                  placeholder="Ej. INC-2026-A1B2"
                  data-testid="filter-notification-ticket"
                  @keyup.enter="applyFilters"
                />
              </label>

              <div style="display: flex; align-items: center; gap: var(--spacing-sm);">
                <button
                  type="button"
                  class="vg-btn vg-btn-sm vg-btn-primary"
                  :disabled="isLoading"
                  @click="applyFilters"
                  data-testid="btn-apply-notification-filters"
                >Filtrar</button>
                <button
                  type="button"
                  class="vg-btn vg-btn-sm vg-btn-neutral"
                  :disabled="isLoading"
                  @click="clearFilters"
                  data-testid="btn-clear-notification-filters"
                >Limpiar</button>
              </div>
            </div>

            <!-- Barra de lote -->
            <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--spacing-sm);">
              <div style="font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                {{ pagination.total }} notificación(es) · página {{ currentPage }} de {{ totalPages }}
              </div>
              <button
                type="button"
                class="vg-btn vg-btn-sm vg-btn-secondary"
                :disabled="isActionRunning || failedPermanentCount === 0"
                @click="retryAllFailed"
                data-testid="btn-retry-all-failed"
              >
                ↻ Reintentar todas las fallidas ({{ failedPermanentCount }})
              </button>
            </div>

            <!-- Tabla -->
            <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); overflow-x: auto;">
              <table style="width: 100%; border-collapse: collapse; font-family: var(--font-body); font-size: var(--font-size-caption); text-align: left;">
                <thead>
                  <tr style="background-color: var(--color-canvas); border-bottom: 1px solid var(--color-hairline); color: var(--color-ink-secondary);">
                    <th style="padding: 8px 10px; font-weight: 600;">Ticket</th>
                    <th style="padding: 8px 10px; font-weight: 600;">Destinatario</th>
                    <th style="padding: 8px 10px; font-weight: 600;">Aviso</th>
                    <th style="padding: 8px 10px; font-weight: 600;">Enlace de seguimiento</th>
                    <th style="padding: 8px 10px; font-weight: 600;">Estado</th>
                    <th style="padding: 8px 10px; font-weight: 600; text-align: center;">Intentos</th>
                    <th style="padding: 8px 10px; font-weight: 600;">Último error</th>
                    <th style="padding: 8px 10px; font-weight: 600; text-align: right;">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="item in items"
                    :key="item.id"
                    style="border-bottom: 1px solid var(--color-hairline); vertical-align: top;"
                    data-testid="notification-row"
                  >
                    <td style="padding: 8px 10px; white-space: nowrap;">
                      <div style="font-weight: 600; color: var(--color-ink);">{{ ticketLabel(item) }}</div>
                      <div style="color: var(--color-ink-muted);">{{ channelLabel(item.channel) }}</div>
                    </td>
                    <td style="padding: 8px 10px; max-width: 220px; word-break: break-all;">
                      {{ item.recipient }}
                    </td>
                    <td style="padding: 8px 10px;">
                      <div style="font-weight: 600; color: var(--color-slate);">{{ item.template_label || item.template }}</div>
                      <span
                        class="vg-badge"
                        style="margin-top: 3px;"
                        :style="{
                          backgroundColor: priorityMetaOf(item.priority).background,
                          color: priorityMetaOf(item.priority).color
                        }"
                      >{{ priorityMetaOf(item.priority).label }}</span>
                    </td>
                    <td style="padding: 8px 10px; max-width: 260px;">
                      <a
                        v-if="item.tracking_url"
                        :href="item.tracking_url"
                        :title="item.tracking_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        style="color: var(--color-primary); word-break: break-all;"
                        data-testid="outbox-tracking-url"
                      >{{ item.tracking_url }}</a>
                      <span v-else style="color: var(--color-ink-muted);">—</span>
                    </td>
                    <td style="padding: 8px 10px;">
                      <span
                        class="vg-badge"
                        :style="{
                          backgroundColor: statusMetaOf(item.status).background,
                          color: statusMetaOf(item.status).color
                        }"
                        :data-status="item.status"
                      >
                        {{ statusMetaOf(item.status).icon }} {{ statusMetaOf(item.status).label }}
                      </span>
                      <div v-if="item.processed_at" style="color: var(--color-ink-muted); margin-top: 3px;">
                        {{ formatDate(item.processed_at) }}
                      </div>
                    </td>
                    <td style="padding: 8px 10px; text-align: center; color: var(--color-ink-secondary);">
                      {{ attemptLabel(item) }}
                    </td>
                    <td style="padding: 8px 10px; max-width: 240px; color: var(--color-error-text); word-break: break-word;">
                      {{ item.last_error || item.obsolete_reason || '—' }}
                    </td>
                    <td style="padding: 8px 10px;">
                      <div style="display: flex; justify-content: flex-end; gap: var(--spacing-xs);">
                        <button
                          v-if="item.can_retry"
                          type="button"
                          class="vg-btn vg-btn-sm vg-btn-secondary"
                          :disabled="isActionRunning"
                          @click="retryNotification(item)"
                          data-testid="btn-retry-notification"
                        >↻ Reintentar envío</button>
                        <button
                          v-if="item.can_cancel"
                          type="button"
                          class="vg-btn vg-btn-sm vg-btn-danger-soft"
                          :disabled="isActionRunning"
                          @click="askCancel(item)"
                          data-testid="btn-cancel-notification"
                        >✕ Cancelar envío</button>
                        <span
                          v-if="!item.can_retry && !item.can_cancel"
                          style="font-size: var(--font-size-caption); color: var(--color-ink-muted);"
                        >—</span>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>

              <div
                v-if="isLoading"
                style="padding: var(--spacing-lg); text-align: center; font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-ink-muted);"
                data-testid="notification-loading-state"
              >Cargando bandeja de salida…</div>

              <div
                v-else-if="isOutboxEmpty"
                style="padding: var(--spacing-lg); text-align: center; font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-ink-muted);"
                data-testid="notification-empty-state"
              >No hay notificaciones que cumplan los filtros seleccionados.</div>
            </div>

            <!-- Paginación -->
            <div style="display: flex; align-items: center; justify-content: flex-end; gap: var(--spacing-sm);">
              <button
                type="button"
                class="vg-btn vg-btn-sm vg-btn-neutral"
                :disabled="!canGoPrevious || isLoading"
                @click="goToPage(-1)"
                data-testid="btn-notification-prev-page"
              >← Anterior</button>
              <span style="font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                {{ currentPage }} / {{ totalPages }}
              </span>
              <button
                type="button"
                class="vg-btn vg-btn-sm vg-btn-neutral"
                :disabled="!canGoNext || isLoading"
                @click="goToPage(1)"
                data-testid="btn-notification-next-page"
              >Siguiente →</button>
            </div>

            <!-- Confirmación de cancelación en línea -->
            <div
              v-if="cancelTarget"
              style="background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); border-radius: var(--radius-card); padding: var(--spacing-md); display: flex; flex-direction: column; gap: var(--spacing-sm);"
              data-testid="notification-cancel-panel"
            >
              <div style="font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-slate);">
                Vas a cancelar el envío <strong>#{{ cancelTarget.id }}</strong> hacia
                <strong>{{ cancelTarget.recipient }}</strong>. La fila se conserva como
                «Cancelada por obsolescencia» y el motivo queda auditado (Art. III).
              </div>
              <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-slate);">
                Motivo de la cancelación (opcional)
                <textarea
                  v-model="cancelReason"
                  rows="2"
                  class="vg-textarea"
                  placeholder="Ej. Contacto telefónico directo con la sede; el aviso ya no aplica."
                  data-testid="input-notification-cancel-reason"
                ></textarea>
              </label>
              <div style="display: flex; justify-content: flex-end; gap: var(--spacing-sm);">
                <button
                  type="button"
                  class="vg-btn vg-btn-sm vg-btn-neutral"
                  :disabled="isActionRunning"
                  @click="dismissCancel"
                  data-testid="btn-dismiss-cancel-notification"
                >Descartar</button>
                <button
                  type="button"
                  class="vg-btn vg-btn-sm vg-btn-danger"
                  :disabled="isActionRunning"
                  @click="confirmCancel"
                  data-testid="btn-confirm-cancel-notification"
                >Confirmar cancelación</button>
              </div>
            </div>
          </div>

          <!-- ============================================================= -->
          <!-- PESTAÑA 2 · CANAL CORPORATIVO DE EMERGENCIAS                  -->
          <!-- ============================================================= -->
          <div v-else-if="activeTab === 'webhook'" style="display: flex; flex-direction: column; gap: var(--spacing-base);">
            <div style="background-color: var(--color-info-bg); border-left: 4px solid var(--color-info); padding: 10px 14px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-slate);">
              🛡️ <strong>Canal Corporativo de Emergencias (Art. II):</strong> recibe las alertas de frío,
              cuarentena sanitaria y escalado del barrido. Sólo se admite una dirección cifrada
              <code>https://</code> hacia un dominio público: los destinos locales y los rangos de red
              privada se rechazan para impedir ataques SSRF.
            </div>

            <div style="display: flex; flex-direction: column; gap: var(--spacing-md); background-color: var(--color-canvas); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: var(--spacing-base);">
              <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-slate);">
                Nombre identificativo del canal
                <input
                  v-model="webhookForm.webhook_name"
                  type="text"
                  class="vg-input"
                  placeholder="Ej. Slack #emergencias-frio"
                  data-testid="input-webhook-name"
                />
              </label>

              <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-slate);">
                Dirección del Webhook (obligatoriamente https://)
                <input
                  v-model="webhookForm.webhook_url"
                  type="url"
                  class="vg-input"
                  placeholder="https://hooks.example.org/services/T000/B000/X000"
                  data-testid="input-webhook-url"
                  :aria-invalid="webhookUrlError !== ''"
                />
              </label>

              <div
                v-if="webhookUrlError"
                style="background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); padding: 8px 12px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: var(--font-size-caption);"
                data-testid="webhook-url-error"
              >⚠️ {{ webhookUrlError }}</div>

              <label style="display: flex; align-items: center; gap: var(--spacing-sm); font-family: var(--font-body); font-size: var(--font-size-body-sm); font-weight: 600; color: var(--color-slate);">
                <input
                  v-model="webhookForm.is_webhook_enabled"
                  type="checkbox"
                  style="width: 16px; height: 16px; cursor: pointer;"
                  data-testid="input-webhook-enabled"
                />
                Canal corporativo activado
              </label>

              <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--spacing-sm);">
                <div style="font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                  {{ lastTestLabel }}
                  <span v-if="webhookTest.testedAt"> · {{ formatDate(webhookTest.testedAt) }}</span>
                  <div v-if="webhookTest.error" style="color: var(--color-error-text); margin-top: 2px;">
                    {{ webhookTest.error }}
                  </div>
                </div>

                <div style="display: flex; gap: var(--spacing-sm);">
                  <button
                    type="button"
                    class="vg-btn vg-btn-secondary"
                    :disabled="!canTestWebhook"
                    @click="testConnection"
                    data-testid="btn-test-webhook"
                  >
                    <span v-if="isTestingWebhook">Probando…</span>
                    <span v-else>🔌 Probar conexión</span>
                  </button>
                  <button
                    type="button"
                    class="vg-btn vg-btn-primary"
                    :disabled="!canSaveWebhook"
                    @click="saveWebhook"
                    data-testid="btn-save-webhook"
                  >
                    <span v-if="isSavingWebhook">Guardando…</span>
                    <span v-else>Guardar configuración</span>
                  </button>
                </div>
              </div>

              <div
                v-if="testResult"
                style="border-radius: var(--radius-interactive); padding: 10px 14px; font-family: var(--font-body); font-size: var(--font-size-body-sm);"
                :style="{
                  backgroundColor: testResult.passed ? 'var(--color-success-bg)' : 'var(--color-error-bg)',
                  color: testResult.passed ? 'var(--color-success-text)' : 'var(--color-error-text)'
                }"
                data-testid="webhook-test-result"
              >
                <div v-if="testResult.passed">
                  ✅ Conexión verificada en {{ testResult.durationMs }} ms
                  <span v-if="testResult.httpCode"> (HTTP {{ testResult.httpCode }})</span>.
                </div>
                <div v-else>
                  ⚠️ La prueba de conexión falló
                  <span v-if="testResult.httpCode"> (HTTP {{ testResult.httpCode }})</span>:
                  {{ testResult.error || 'el destino no respondió como se esperaba.' }}
                </div>
              </div>
            </div>

            <div style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: var(--spacing-base); display: flex; flex-direction: column; gap: var(--spacing-sm);">
              <div style="font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); text-transform: uppercase; letter-spacing: 0.04em;">
                Técnicos de guardia ({{ onCallTechnicians.length }})
              </div>

              <div
                v-if="onCallTechnicians.length === 0"
                style="font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-warning-text); background-color: var(--color-warning-bg); border-radius: var(--radius-interactive); padding: 8px 12px;"
                data-testid="on-call-empty-state"
              >
                ⚠️ No hay ningún técnico con la marca de guardia. Las alertas de frío sin asignar
                se enviarán igualmente a Coordinación con la advertencia de asignación manual inmediata.
              </div>

              <div v-else style="display: flex; flex-wrap: wrap; gap: var(--spacing-sm);" data-testid="on-call-technicians">
                <span
                  v-for="technician in onCallTechnicians"
                  :key="technician.id"
                  class="vg-chip vg-chip-success"
                  :title="technician.email"
                >
                  🛡️ {{ technician.name }} · {{ technician.operator_code }}
                </span>
              </div>

              <p style="margin: 0; font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                La marca de guardia se activa o retira desde el directorio de Personal Interno.
              </p>
            </div>
          </div>
        </div>

        <!-- Pie -->
        <div style="padding: 12px 20px; border-top: 1px solid var(--color-hairline); background-color: var(--color-canvas); display: flex; justify-content: flex-end;">
          <button
            type="button"
            class="vg-btn vg-btn-neutral"
            @click="close"
            data-testid="btn-close-footer-notification-center"
          >Cerrar</button>
        </div>
      </div>
    </div>
  `
};

export default CoordinatorNotificationCenterModal;
