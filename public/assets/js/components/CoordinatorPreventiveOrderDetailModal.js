/**
 * VendGuard - CoordinatorPreventiveOrderDetailModal (CoordinatorPreventiveOrderDetailModal.js)
 *
 * Ficha Integral de Detalle de una Orden de Mantenimiento Preventivo (Módulo 05,
 * RF-PD-01 a RF-PD-10). Espejo estructural del modal de detalle de averías
 * (Módulo 09), restringido estrictamente a consulta.
 *
 * Bloques renderizados:
 * 1. Cabecera fija: código de orden, insignia de estado, tipo de orden, distintivo de
 *    cuarentena sanitaria si procede, actualización manual y UN único botón de cierre.
 * 2. Cuerpo con desplazamiento vertical independiente (RNF-PD-03):
 *    - Sede y máquina con el distintivo sanitario de perecederos (RF-PD-03, Art. II).
 *    - Monitor de vigencia sanitaria: cuenta atrás en órdenes abiertas y balance
 *      histórico formal en órdenes completadas o canceladas (RF-PD-03.3).
 *    - Técnico inspector identificado por su Código de Operador Oficial (RF-PD-04, Art. V.4).
 *    - Checklist normativo respondido con severidades, observaciones, evidencias
 *      ampliables y resumen de cumplimiento (RF-PD-05).
 *    - Dictamen, temperatura de sonda, notas de inspección y motivo de descarte (RF-PD-06).
 *    - Avería correctiva vinculada con salto a su ficha integral (RF-PD-07, Art. V.2).
 *    - Certificado sanitario vinculado, en modo consulta (RF-PD-08, Art. V.4).
 *    - Trazabilidad de auditoría inmutable de la orden (RF-PD-09, Art. III).
 * 3. Pie de acciones fijo con el cierre de la ficha (RNF-PD-03).
 *
 * La ficha NO contiene formularios ni acciones de escritura: asignar, reprogramar y
 * cancelar siguen residiendo en los modales ya existentes de la fila de la tabla
 * (RNF-PD-06). Por ese motivo no existe guardián de borradores: cualquier señal de
 * cierre (Escape, fondo sombreado, botón de cabecera o de pie) cierra de inmediato.
 *
 * La evidencia fotográfica de un ítem se amplía en un visor integrado en el propio
 * contenedor modal, sin capas modales superpuestas (RNF-PD-06).
 *
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias externas).
 * Dualismo Lingüístico: código en inglés, interfaz y mensajes en español.
 */

import { api } from '../api.js';
import {
  getCertificateStatusBadge,
  getChecklistItemBadge,
  getOrderResultBadge,
  getOrderStatusBadge,
  getOrderTypeLabel,
  getSanitaryStatusBadge,
  getValidityBadge
} from '../utils/PreventiveLabels.js';

export const CoordinatorPreventiveOrderDetailModal = {
  name: 'CoordinatorPreventiveOrderDetailModal',
  props: {
    /**
     * Visibilidad de la ficha, controlada por la bandeja de órdenes preventivas.
     */
    isOpen: {
      type: Boolean,
      default: false
    },
    /**
     * Identificador de la orden a inspeccionar: ID primario o código de orden.
     */
    orderId: {
      type: [Number, String],
      default: null
    }
  },
  emits: ['close', 'open-incident-detail'],
  data() {
    return {
      detail: null,
      isLoading: false,
      errorMessage: '',
      /** Ítem del checklist cuya evidencia gráfica se está ampliando (RF-PD-05.1). */
      photoZoomItem: null,
      /** Evidencias rotas o inaccesibles, para mostrar el recuadro de sustitución. */
      failedPhotoCodes: []
    };
  },
  computed: {
    /** Código visible en la cabecera; mientras la ficha carga muestra el identificador recibido. */
    headerOrderCode() {
      if (this.order?.order_code) {
        return this.order.order_code;
      }
      if (this.orderId !== null && this.orderId !== '') {
        return String(this.orderId);
      }
      return '—';
    },

    /** Bloque de cabecera de la orden (RF-PD-02). */
    order() {
      return this.detail?.order || null;
    },

    /** Sede cliente donde vive la máquina (RF-PD-03). */
    location() {
      return this.detail?.location || null;
    },

    /** Metadatos de la máquina, con el indicador sanitario (RF-PD-03, Art. II). */
    machine() {
      return this.detail?.machine || null;
    },

    /** Técnico inspector vigente (RF-PD-04). */
    technician() {
      return this.detail?.technician || { assigned: false, name: null, operator_code: null };
    },

    /** Semáforo de vigencia sanitaria derivado en servidor (RF-PD-03.3). */
    validity() {
      return this.detail?.validity || null;
    },

    /** Bloque completo del checklist normativo (RF-PD-05). */
    checklist() {
      return this.detail?.checklist || null;
    },

    /** Respuestas del checklist listas para pintar, con su insignia y evidencia. */
    checklistItems() {
      const items = Array.isArray(this.checklist?.items) ? this.checklist.items : [];
      return items.map((item) => ({
        ...item,
        badge: getChecklistItemBadge(item.status),
        hasPhoto: Boolean(item.photo_url) && !this.failedPhotoCodes.includes(item.item_code)
      }));
    },

    /** Contadores del checklist (RF-PD-05.2). */
    checklistTotals() {
      return this.checklist?.totals || {
        total: 0,
        pass: 0,
        warn: 0,
        fail: 0,
        not_applicable: 0,
        critical_failures: 0
      };
    },

    /** La orden ya tiene respuestas registradas (RF-PD-05.3). */
    hasChecklist() {
      return Boolean(this.checklist?.has_checklist);
    },

    /** Avería correctiva abierta desde la inspección (RF-PD-07). */
    linkedIncident() {
      return this.detail?.linked_incident || null;
    },

    /** Certificado sanitario más reciente de la máquina (RF-PD-08). */
    certificate() {
      return this.detail?.certificate || null;
    },

    /** Trazabilidad de auditoría de la orden (RF-PD-09). */
    auditTrail() {
      return Array.isArray(this.detail?.audit_trail) ? this.detail.audit_trail : [];
    },

    /** La inspección declaró cuarentena sanitaria (RF-PD-06.2, Art. II). */
    hasQuarantine() {
      return Boolean(this.order?.is_quarantine_triggered);
    },

    /** Insignia del estado de la orden para la cabecera. */
    statusBadge() {
      return getOrderStatusBadge(this.order?.status);
    },

    /** Etiqueta del tipo de orden (ordinaria, reinspección o extraordinaria). */
    orderTypeLabel() {
      return this.order?.order_type_label || getOrderTypeLabel(this.order?.order_type);
    },

    /** Insignia del dictamen de la inspección, si ya existe. */
    resultBadge() {
      return this.order ? getOrderResultBadge(this.order.result) : null;
    },

    /** Insignia del semáforo de vigencia sanitaria. */
    validityBadge() {
      return this.validity ? getValidityBadge(this.validity.state) : null;
    },

    /** Insignia del semáforo sanitario de la máquina. */
    sanitaryBadge() {
      return this.machine ? getSanitaryStatusBadge(this.machine.sanitary_status) : null;
    },

    /** Insignia del estado del certificado sanitario vinculado. */
    certificateBadge() {
      return this.certificate ? getCertificateStatusBadge(this.certificate.status) : null;
    },

    /**
     * Días restantes en lenguaje natural: negativo cuando la inspección ya venció
     * (RF-PD-03.3). Devuelve null en órdenes cerradas, que no tienen cuenta atrás.
     */
    daysRemainingLabel() {
      const days = this.validity?.days_remaining;
      if (days === null || days === undefined) {
        return null;
      }
      if (days === 0) {
        return 'Vence hoy';
      }
      if (days > 0) {
        return `Faltan ${days} día${days === 1 ? '' : 's'}`;
      }
      const overdue = Math.abs(days);
      return `Vencida hace ${overdue} día${overdue === 1 ? '' : 's'}`;
    }
  },
  watch: {
    isOpen: {
      immediate: true,
      handler(isOpen) {
        this.handleOpenState(isOpen);
      }
    },
    orderId() {
      if (this.isOpen) {
        this.fetchDetail();
      }
    }
  },
  beforeUnmount() {
    this.releaseScrollLock();
  },
  methods: {
    /**
     * Sincroniza los efectos de apertura/cierre: bloqueo del scroll de fondo y atajo
     * Escape. Todas las señales de cierre pasan por `requestClose()`.
     */
    handleOpenState(isOpen) {
      if (typeof document !== 'undefined') {
        document.body.style.overflow = isOpen ? 'hidden' : '';
      }

      if (typeof window !== 'undefined') {
        if (isOpen) {
          window.addEventListener('keydown', this.handleKeyDown);
        } else {
          window.removeEventListener('keydown', this.handleKeyDown);
        }
      }

      if (isOpen) {
        this.fetchDetail();
      }
    },

    /**
     * Carga la ficha integral de la orden con una única lectura agregada (RNF-PD-01).
     */
    async fetchDetail() {
      if (this.orderId === null || this.orderId === '') {
        return;
      }

      this.isLoading = true;
      this.errorMessage = '';
      this.photoZoomItem = null;
      this.failedPhotoCodes = [];

      try {
        // api.js ya desenvuelve la envolvente { success, data }, por lo que aquí
        // llega directamente el DTO de detalle con sus nueve bloques.
        const response = await api.coordinator.getPreventiveOrderDetail(this.orderId);
        this.detail = response && typeof response === 'object' ? response : null;
      } catch (err) {
        this.detail = null;
        this.errorMessage = err?.message || 'No se pudo cargar el detalle de la orden preventiva.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Cierra la ficha. Es una vista de consulta sin formularios, así que no hay
     * borradores que proteger (RF-PD-10.1).
     */
    requestClose() {
      this.photoZoomItem = null;
      this.$emit('close');
    },

    handleBackdropClick(event) {
      if (event.target === event.currentTarget) {
        this.requestClose();
      }
    },

    /**
     * Escape cierra primero el visor integrado de la evidencia y, después, la ficha.
     */
    handleKeyDown(event) {
      if (event.key !== 'Escape' || !this.isOpen) {
        return;
      }
      if (this.photoZoomItem) {
        this.closePhotoZoom();
        return;
      }
      this.requestClose();
    },

    /**
     * Abre el visor integrado de la evidencia de un ítem del checklist (RF-PD-05.1).
     */
    openPhotoZoom(item) {
      if (!item?.photo_url) {
        return;
      }
      this.photoZoomItem = item;
    },

    closePhotoZoom() {
      this.photoZoomItem = null;
    },

    /**
     * Evidencia rota o inaccesible: recuadro de sustitución sin romper la estructura.
     */
    handlePhotoError(itemCode) {
      if (!this.failedPhotoCodes.includes(itemCode)) {
        this.failedPhotoCodes.push(itemCode);
      }
      if (this.photoZoomItem?.item_code === itemCode) {
        this.photoZoomItem = null;
      }
    },

    /**
     * Solicita a la vista de Coordinación el salto a la ficha integral de la avería
     * correctiva vinculada (RF-PD-07.2). La ficha preventiva se cierra antes para no
     * apilar dos modales (RNF-PD-06).
     */
    openLinkedIncidentDetail() {
      if (!this.linkedIncident) {
        return;
      }
      const incidentId = this.linkedIncident.id;
      this.requestClose();
      this.$emit('open-incident-detail', incidentId);
    },

    /**
     * Formatea una marca temporal del contrato (`YYYY-MM-DD HH:MM:SS`) como `DD/MM/YYYY HH:MM`
     * sin depender del huso horario del navegador.
     */
    formatDateTime(value) {
      if (!value) {
        return '';
      }
      const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
      if (!match) {
        return String(value);
      }
      return `${match[3]}/${match[2]}/${match[1]} ${match[4]}:${match[5]}`;
    },

    /**
     * Formatea una fecha del contrato (`YYYY-MM-DD`) como `DD/MM/YYYY`.
     */
    formatDate(value) {
      if (!value) {
        return '—';
      }
      const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
      if (!match) {
        return String(value);
      }
      return `${match[3]}/${match[2]}/${match[1]}`;
    },

    /**
     * Formatea la temperatura de sonda con un decimal y su unidad (RNF-06).
     */
    formatTemperature(value) {
      if (value === null || value === undefined || value === '') {
        return '—';
      }
      const temperature = Number(value);
      if (!Number.isFinite(temperature)) {
        return '—';
      }
      return `${temperature.toFixed(1)} °C`;
    },

    releaseScrollLock() {
      if (typeof document !== 'undefined') {
        document.body.style.overflow = '';
      }
      if (typeof window !== 'undefined') {
        window.removeEventListener('keydown', this.handleKeyDown);
      }
    }
  },
  template: `
    <Teleport to="body" :disabled="typeof document === 'undefined'">
      <div
        v-if="isOpen"
        class="vg-modal-backdrop preventive-detail-backdrop"
        data-testid="preventive-detail-backdrop"
        role="presentation"
        style="padding: 24px;"
        @click.self="handleBackdropClick"
      >
        <div
          class="vg-modal-container preventive-detail-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="preventive-detail-title"
          tabindex="-1"
          data-testid="preventive-detail-modal"
          style="position: relative; background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); box-shadow: var(--shadow-modal, 0 12px 32px rgba(0, 0, 0, 0.12)); width: min(1080px, 100%); height: min(88vh, 820px); max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; outline: none;"
        >
          <!-- CABECERA FIJA (RF-PD-02.1) -->
          <header
            class="modal-header preventive-detail-header"
            data-testid="preventive-detail-header"
            style="flex: 0 0 auto; gap: 12px; background-color: #ffffff;"
          >
            <div style="display: flex; align-items: center; gap: 10px; min-width: 0; flex-wrap: wrap;">
              <h2
                id="preventive-detail-title"
                class="preventive-code"
                data-testid="preventive-detail-code"
                style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 20px; font-weight: 500; color: var(--color-ink, #000000); margin: 0; white-space: nowrap;"
              >
                {{ headerOrderCode }}
              </h2>
              <span
                v-if="isLoading"
                class="detail-loading-chip"
                data-testid="preventive-detail-loading-chip"
                style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 500; color: var(--color-ink-muted, #6c7e9d);"
              >
                Cargando ficha…
              </span>
              <template v-if="order">
                <span
                  class="badge badge-status"
                  data-testid="preventive-detail-status-badge"
                  :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontFamily: 'var(--font-body, Inter, sans-serif)', fontSize: '12px', fontWeight: '600', backgroundColor: statusBadge.bg, color: statusBadge.color, border: '1px solid ' + statusBadge.bg, borderRadius: 'var(--radius-interactive, 4px)', padding: '3px 8px' }"
                >
                  {{ statusBadge.icon }} {{ statusBadge.label }}
                </span>
                <span
                  class="badge badge-order-type"
                  data-testid="preventive-detail-order-type"
                  style="display: inline-flex; align-items: center; gap: 6px; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 500; background-color: var(--color-canvas, #f9fafb); color: var(--color-ink-secondary, #434c5f); border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); padding: 3px 8px;"
                >
                  Tipo: {{ orderTypeLabel }}
                </span>
                <span
                  v-if="hasQuarantine"
                  class="badge badge-quarantine"
                  data-testid="preventive-detail-quarantine-chip"
                  style="display: inline-flex; align-items: center; gap: 6px; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 600; background-color: var(--color-error-bg, #fddfdf); color: var(--color-error-text, #b91c1c); border: 1px solid var(--color-error, #ff5757); border-radius: var(--radius-interactive, 4px); padding: 3px 8px;"
                >
                  🚫 Cuarentena sanitaria
                </span>
              </template>
            </div>

            <div style="display: flex; align-items: center; gap: 8px;">
              <button
                type="button"
                class="vg-btn vg-btn-secondary btn-refresh"
                data-testid="preventive-detail-refresh"
                :disabled="isLoading"
                @click="fetchDetail"
              >
                Actualizar
              </button>
              <!--
                El glifo de cierre lo aporta el pseudo-elemento ::before de la clase
                compartida .btn-close (design-tokens.css): el botón se deja vacío para
                no duplicar la ✕ (una sola aspa en la cabecera).
              -->
              <button
                type="button"
                class="vg-btn vg-btn-secondary btn-close"
                data-testid="preventive-detail-close"
                aria-label="Cerrar la ficha de la orden preventiva"
                @click="requestClose"
              ></button>
            </div>
          </header>

          <!-- CUERPO CON SCROLL VERTICAL INDEPENDIENTE (RNF-PD-03) -->
          <main
            class="modal-body modal-body-scrollable preventive-detail-body"
            data-testid="preventive-detail-body"
            style="background-color: var(--color-canvas, #f9fafb);"
          >
            <div
              v-if="isLoading && !detail"
              class="detail-feedback"
              data-testid="preventive-detail-loading"
              style="padding: 48px 20px; text-align: center; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-muted, #6c7e9d);"
            >
              Cargando el expediente de la orden preventiva…
            </div>

            <div
              v-else-if="errorMessage"
              class="detail-feedback detail-error"
              data-testid="preventive-detail-error"
              style="padding: 32px 20px; text-align: center; border: 1px solid var(--color-error, #ff5757); border-radius: var(--radius-card, 8px); background-color: var(--color-error-bg, #fddfdf);"
            >
              <p style="margin: 0 0 12px; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-error-text, #b91c1c);">
                {{ errorMessage }}
              </p>
              <button
                type="button"
                class="vg-btn vg-btn-primary"
                data-testid="preventive-detail-retry"
                @click="fetchDetail"
              >
                Reintentar
              </button>
            </div>

            <template v-else-if="detail">
              <!-- 1. Alerta de Cuarentena Sanitaria (RF-PD-06.2, Art. II) -->
              <section
                v-if="hasQuarantine"
                class="quarantine-banner"
                data-testid="quarantine-banner"
                style="background-color: var(--color-error-bg, #fddfdf); border: 1px solid var(--color-error, #ff5757); border-radius: var(--radius-card, 8px); padding: 14px 16px; margin-bottom: 16px;"
              >
                <strong style="display: block; font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 15px; font-weight: 500; color: var(--color-error-text, #b91c1c); margin-bottom: 4px;">
                  🚫 Cuarentena sanitaria activada por esta inspección
                </strong>
                <p style="margin: 0; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-error-text, #b91c1c); line-height: 1.45;">
                  Por imperativo del Artículo II de la Constitución (Seguridad Alimentaria), la máquina queda fuera de servicio hasta superar la reinspección sanitaria obligatoria.
                </p>
              </section>

              <!-- 2. Información de Sede y Máquina (RF-PD-03) -->
              <section
                class="section-grid-2 detail-section"
                data-testid="section-location-machine"
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 16px;"
              >
                <div
                  class="info-card"
                  style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px;"
                >
                  <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 8px;">
                    🏢 Ubicación y Centro
                  </h3>
                  <p data-testid="location-name" style="margin: 0 0 4px; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f);">
                    <strong>Sede:</strong> {{ location.name || 'No especificada' }}
                    <span v-if="location.site_code">({{ location.site_code }})</span>
                  </p>
                  <p v-if="location.address" data-testid="location-address" style="margin: 0; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f);">
                    <strong>Dirección:</strong> {{ location.address }}
                  </p>
                </div>

                <div
                  class="info-card"
                  style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px;"
                >
                  <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 8px;">
                    🎰 Máquina
                  </h3>
                  <p data-testid="machine-code" style="margin: 0 0 4px; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f);">
                    <strong>Código:</strong> {{ machine.code || 'No especificado' }}
                    <span v-if="machine.model">· {{ machine.model }}</span>
                  </p>
                  <p data-testid="machine-type" style="margin: 0 0 4px; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f);">
                    <strong>Tipo:</strong> {{ machine.machine_type_label || machine.machine_type || 'No especificado' }}
                  </p>
                  <p v-if="machine.floor_wing" data-testid="machine-floor-wing" style="margin: 0 0 8px; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f);">
                    <strong>Ubicación física:</strong> {{ machine.floor_wing }}
                  </p>
                  <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                    <span
                      v-if="machine.has_perishables"
                      class="perishable-warning"
                      data-testid="machine-perishable"
                      style="display: inline-flex; align-items: center; gap: 6px; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 600; background-color: var(--color-error-bg, #fddfdf); color: var(--color-error-text, #b91c1c); border: 1px solid var(--color-error, #ff5757); border-radius: var(--radius-interactive, 4px); padding: 4px 8px;"
                    >
                      ❄️ Alimentos Perecederos (Inspección cada 15 días)
                    </span>
                    <span
                      v-if="sanitaryBadge"
                      class="sanitary-status-chip"
                      data-testid="machine-sanitary-status"
                      :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontFamily: 'var(--font-body, Inter, sans-serif)', fontSize: '12px', fontWeight: '600', backgroundColor: sanitaryBadge.bg, color: sanitaryBadge.color, borderRadius: 'var(--radius-interactive, 4px)', padding: '4px 8px' }"
                    >
                      {{ sanitaryBadge.icon }} {{ sanitaryBadge.label }}
                    </span>
                  </div>
                </div>
              </section>

              <!-- 3. Vigencia Sanitaria y Técnico Inspector (RF-PD-03.3, RF-PD-04) -->
              <section
                class="section-grid-2 detail-section"
                data-testid="section-validity"
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 16px;"
              >
                <div
                  class="validity-monitor-card"
                  data-testid="validity-monitor"
                  style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px;"
                >
                  <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 10px;">
                    ⏱️ Vigencia Sanitaria de la Orden
                  </h3>
                  <span
                    v-if="validityBadge"
                    data-testid="validity-badge"
                    :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontFamily: 'var(--font-body, Inter, sans-serif)', fontSize: '13px', fontWeight: '700', backgroundColor: validityBadge.bg, color: validityBadge.color, borderRadius: 'var(--radius-interactive, 4px)', padding: '4px 10px' }"
                  >
                    {{ validityBadge.icon }} {{ validityBadge.label }}
                  </span>
                  <p v-if="daysRemainingLabel" data-testid="validity-days" style="margin: 10px 0 0; font-family: var(--font-body, Inter, sans-serif); font-size: 15px; font-weight: 600; color: var(--color-ink-slate, #2c333f);">
                    {{ daysRemainingLabel }}
                  </p>
                  <p data-testid="validity-valid-until" style="margin: 6px 0 0; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-secondary, #434c5f);">
                    Fecha límite de vigencia: {{ formatDate(validity.valid_until) }}
                  </p>
                  <p v-if="validity.balance_label" data-testid="validity-balance" style="margin: 6px 0 0; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-secondary, #434c5f);">
                    {{ validity.balance_label }}
                  </p>
                  <p data-testid="validity-scheduled" style="margin: 6px 0 0; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-secondary, #434c5f);">
                    Programada para el {{ formatDate(order.scheduled_date) }}
                  </p>
                </div>

                <div
                  class="info-card"
                  data-testid="section-technician"
                  style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px;"
                >
                  <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 10px;">
                    👤 Técnico Inspector
                  </h3>
                  <p v-if="technician.assigned" data-testid="technician-summary" style="margin: 0; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f);">
                    {{ technician.name }}
                    <span v-if="technician.operator_code">({{ technician.operator_code }})</span>
                  </p>
                  <p v-else data-testid="technician-pending" style="margin: 0; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; font-weight: 600; color: var(--color-warning-text, #92400e);">
                    ⏳ Pendiente de asignación técnica.
                  </p>
                </div>
              </section>

              <!-- 4. Dictamen, Temperatura y Notas de la Inspección (RF-PD-06) -->
              <section
                class="info-card detail-section"
                data-testid="section-inspection"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 10px;">
                  🧪 Resultado de la Inspección
                </h3>
                <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-bottom: 10px;">
                  <span
                    v-if="resultBadge"
                    data-testid="inspection-result"
                    :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontFamily: 'var(--font-body, Inter, sans-serif)', fontSize: '13px', fontWeight: '700', backgroundColor: resultBadge.bg, color: resultBadge.color, borderRadius: 'var(--radius-interactive, 4px)', padding: '4px 10px' }"
                  >
                    {{ order.result_label || resultBadge.label }}
                  </span>
                  <span v-else data-testid="inspection-result-pending" style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-muted, #6c7e9d);">
                    Sin dictamen registrado todavía.
                  </span>
                  <span v-if="order.temperature_measured !== null && order.temperature_measured !== undefined" data-testid="inspection-temperature" style="font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f);">
                    🌡️ Temperatura de sonda: <strong>{{ formatTemperature(order.temperature_measured) }}</strong>
                  </span>
                </div>
                <p v-if="order.started_at" data-testid="inspection-started" style="margin: 0 0 4px; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-secondary, #434c5f);">
                  <strong>Inicio de inspección:</strong> {{ formatDateTime(order.started_at) }}
                </p>
                <p v-if="order.completed_at" data-testid="inspection-completed" style="margin: 0 0 4px; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-secondary, #434c5f);">
                  <strong>Finalización:</strong> {{ formatDateTime(order.completed_at) }}
                </p>
                <p v-if="order.notes" data-testid="inspection-notes" style="margin: 8px 0 0; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f); line-height: 1.5;">
                  {{ order.notes }}
                </p>
                <p v-if="order.cancellation_reason" data-testid="inspection-cancellation" style="margin: 8px 0 0; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-error-text, #b91c1c); line-height: 1.45;">
                  <strong>Motivo de cancelación lógica:</strong> {{ order.cancellation_reason }}
                </p>
              </section>

              <!-- 5. Checklist Normativo Respondido (RF-PD-05) -->
              <section
                class="info-card detail-section"
                data-testid="section-checklist"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 10px;">
                  ✅ Checklist Normativo de la Inspección
                </h3>

                <template v-if="hasChecklist">
                  <div
                    class="checklist-summary"
                    data-testid="checklist-summary"
                    style="display: flex; flex-wrap: wrap; gap: 14px; padding: 10px 12px; margin-bottom: 12px; background-color: var(--color-canvas, #f9fafb); border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-ink-secondary, #434c5f);"
                  >
                    <span data-testid="checklist-compliance">Cumplimiento: <strong style="color: var(--color-ink-slate, #2c333f);">{{ checklist.compliance_percent }}%</strong></span>
                    <span data-testid="checklist-total">Ítems: <strong>{{ checklistTotals.total }}</strong></span>
                    <span data-testid="checklist-pass">Conformes: <strong>{{ checklistTotals.pass }}</strong></span>
                    <span data-testid="checklist-warn">Observaciones: <strong>{{ checklistTotals.warn }}</strong></span>
                    <span data-testid="checklist-fail">No conformes: <strong>{{ checklistTotals.fail }}</strong></span>
                    <span data-testid="checklist-not-applicable">No aplicables: <strong>{{ checklistTotals.not_applicable }}</strong></span>
                    <span data-testid="checklist-critical-failures">Fallos críticos: <strong>{{ checklistTotals.critical_failures }}</strong></span>
                  </div>

                  <ul
                    class="checklist-items"
                    data-testid="checklist-items"
                    style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px;"
                  >
                    <li
                      v-for="item in checklistItems"
                      :key="item.item_code"
                      class="checklist-item"
                      data-testid="checklist-item"
                      :data-item-code="item.item_code"
                      style="border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); padding: 10px 12px;"
                    >
                      <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                        <div style="min-width: 0;">
                          <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; font-weight: 600; color: var(--color-ink-slate, #2c333f);">
                            {{ item.item_description }}
                          </div>
                          <div
                            :style="{ fontFamily: 'var(--font-body, Inter, sans-serif)', fontSize: '11px', fontWeight: '600', marginTop: '2px', color: item.is_critical ? 'var(--color-error-text, #b91c1c)' : 'var(--color-ink-muted, #6c7e9d)' }"
                          >
                            {{ item.is_critical ? '⚠️ Ítem crítico' : 'Ítem secundario' }} · {{ item.item_code }}
                          </div>
                        </div>
                        <span
                          :data-testid="'checklist-item-status-' + item.item_code"
                          :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontFamily: 'var(--font-body, Inter, sans-serif)', fontSize: '12px', fontWeight: '700', backgroundColor: item.badge.bg, color: item.badge.color, borderRadius: 'var(--radius-interactive, 4px)', padding: '3px 8px', flex: '0 0 auto' }"
                        >
                          {{ item.badge.icon }} {{ item.badge.label }}
                        </span>
                      </div>
                      <p v-if="item.observations" :data-testid="'checklist-item-observations-' + item.item_code" style="margin: 8px 0 0; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-secondary, #434c5f); line-height: 1.45;">
                        {{ item.observations }}
                      </p>
                      <div v-if="item.photo_url" style="margin-top: 8px;">
                        <img
                          v-if="item.hasPhoto"
                          :src="item.photo_url"
                          alt="Evidencia gráfica del ítem del checklist"
                          :data-testid="'checklist-item-photo-' + item.item_code"
                          style="max-width: 150px; max-height: 110px; object-fit: cover; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); cursor: zoom-in; background-color: var(--color-canvas, #f9fafb);"
                          @click="openPhotoZoom(item)"
                          @error="handlePhotoError(item.item_code)"
                        />
                        <span
                          v-else
                          :data-testid="'checklist-item-photo-fallback-' + item.item_code"
                          style="display: inline-flex; align-items: center; justify-content: center; width: 150px; height: 70px; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-ink-muted, #6c7e9d); background-color: var(--color-canvas, #f9fafb); border: 1px dashed var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); text-align: center; padding: 8px;"
                        >
                          Evidencia gráfica no disponible
                        </span>
                      </div>
                    </li>
                  </ul>
                </template>

                <p v-else data-testid="checklist-empty" style="margin: 0; padding: 12px; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-muted, #6c7e9d); background-color: var(--color-canvas, #f9fafb); border: 1px dashed var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px);">
                  Sin checklist registrado: la inspección todavía no se ha ejecutado en campo.
                </p>
              </section>

              <!-- 6. Avería Correctiva Vinculada (RF-PD-07, Art. V.2) -->
              <section
                class="info-card detail-section"
                data-testid="section-linked-incident"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 10px;">
                  🔧 Avería Correctiva Vinculada
                </h3>

                <template v-if="linkedIncident">
                  <p data-testid="linked-incident-summary" style="margin: 0 0 10px; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f); line-height: 1.6;">
                    <strong>Ticket:</strong> {{ linkedIncident.ticket_code }} ·
                    <strong>Estado:</strong> {{ linkedIncident.status_label }} ·
                    <strong>Urgencia:</strong> {{ linkedIncident.urgency_label }}
                    <span v-if="linkedIncident.created_at"> · abierta el {{ formatDateTime(linkedIncident.created_at) }}</span>
                  </p>
                  <button
                    type="button"
                    class="vg-btn vg-btn-primary"
                    data-testid="btn-open-incident-detail"
                    @click="openLinkedIncidentDetail"
                  >
                    🔍 Ver ficha de avería
                  </button>
                </template>

                <p v-else data-testid="linked-incident-empty" style="margin: 0; padding: 12px; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-muted, #6c7e9d); background-color: var(--color-canvas, #f9fafb); border: 1px dashed var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px);">
                  Esta inspección no abrió ninguna avería correctiva vinculada.
                </p>
              </section>

              <!-- 7. Certificado Sanitario Vinculado (RF-PD-08, Art. V.4) -->
              <section
                class="info-card detail-section"
                data-testid="section-certificate"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 10px;">
                  📜 Certificado Sanitario Vinculado
                </h3>

                <template v-if="certificate">
                  <p data-testid="certificate-summary" style="margin: 0 0 6px; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f);">
                    <strong>Certificado:</strong> {{ certificate.certificate_code }} ·
                    <strong>Dictamen:</strong> {{ certificate.result_label || certificate.result }}
                  </p>
                  <p data-testid="certificate-validity" style="margin: 0 0 6px; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-secondary, #434c5f);">
                    Inspeccionado el {{ formatDate(certificate.inspection_date) }} · vigente hasta el {{ formatDate(certificate.valid_until) }}
                  </p>
                  <p data-testid="certificate-temperature" style="margin: 0 0 6px; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-secondary, #434c5f);">
                    Temperatura certificada: <strong>{{ formatTemperature(certificate.temperature_measured) }}</strong>
                  </p>
                  <p data-testid="certificate-inspector" style="margin: 0 0 10px; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-secondary, #434c5f);">
                    Inspector: {{ certificate.inspector?.name }} <span v-if="certificate.inspector?.operator_code">({{ certificate.inspector.operator_code }})</span>
                  </p>
                  <span
                    v-if="certificateBadge"
                    data-testid="certificate-status"
                    :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontFamily: 'var(--font-body, Inter, sans-serif)', fontSize: '12px', fontWeight: '700', backgroundColor: certificateBadge.bg, color: certificateBadge.color, borderRadius: 'var(--radius-interactive, 4px)', padding: '3px 8px' }"
                  >
                    {{ certificateBadge.label }}
                  </span>
                </template>

                <p v-else data-testid="certificate-empty" style="margin: 0; padding: 12px; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-muted, #6c7e9d); background-color: var(--color-canvas, #f9fafb); border: 1px dashed var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px);">
                  La máquina no dispone de certificado sanitario emitido.
                </p>
              </section>

              <!-- 8. Trazabilidad de Auditoría (RF-PD-09, Art. III) -->
              <section
                class="info-card detail-section"
                data-testid="section-audit-trail"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 10px;">
                  🛡️ Trazabilidad de Auditoría
                </h3>

                <ol
                  v-if="auditTrail.length"
                  class="audit-trail"
                  data-testid="audit-trail"
                  style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px;"
                >
                  <li
                    v-for="(event, index) in auditTrail"
                    :key="event.action + '-' + index"
                    class="audit-event"
                    data-testid="audit-event"
                    style="display: flex; align-items: flex-start; gap: 10px; border-left: 2px solid var(--color-primary, #2560ff); padding-left: 10px;"
                  >
                    <div>
                      <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; font-weight: 600; color: var(--color-ink-slate, #2c333f);">
                        {{ event.action_label }}
                      </div>
                      <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-ink-muted, #6c7e9d);">
                        {{ formatDateTime(event.created_at) }} · {{ event.user_name }} ({{ event.user_role }})
                      </div>
                    </div>
                  </li>
                </ol>

                <p v-else data-testid="audit-empty" style="margin: 0; padding: 12px; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-muted, #6c7e9d); background-color: var(--color-canvas, #f9fafb); border: 1px dashed var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px);">
                  Sin eventos de auditoría registrados para esta orden.
                </p>
              </section>

              <!-- 9. Visor Integrado de Evidencias (RF-PD-05.1, RNF-PD-06) -->
              <div
                v-if="photoZoomItem"
                class="photo-zoom-overlay"
                data-testid="preventive-photo-zoom"
                style="position: fixed; inset: 0; z-index: 60; background-color: rgba(0, 0, 0, 0.82); display: flex; align-items: center; justify-content: center; padding: 24px;"
                @click.self="closePhotoZoom"
              >
                <img
                  :src="photoZoomItem.photo_url"
                  alt="Evidencia gráfica ampliada"
                  data-testid="preventive-photo-zoom-image"
                  style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: var(--radius-card, 8px);"
                />
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary"
                  data-testid="preventive-photo-zoom-close"
                  style="position: absolute; top: 16px; right: 16px;"
                  @click="closePhotoZoom"
                >
                  ✕ Cerrar vista
                </button>
              </div>
            </template>

            <p
              v-else
              class="detail-feedback"
              data-testid="preventive-detail-empty"
              style="padding: 48px 20px; text-align: center; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-muted, #6c7e9d);"
            >
              Seleccione una orden de la bandeja para inspeccionar su expediente preventivo.
            </p>
          </main>

          <!-- PIE DE ACCIONES FIJO (RNF-PD-03) -->
          <footer
            class="modal-footer preventive-detail-footer"
            data-testid="preventive-detail-footer"
            style="flex: 0 0 auto; display: flex; justify-content: flex-end; gap: 8px; background-color: #ffffff; border-top: 1px solid var(--color-hairline, #c8cfda); padding: 12px 20px;"
          >
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              data-testid="preventive-detail-footer-close"
              @click="requestClose"
            >
              Cerrar
            </button>
          </footer>
        </div>
      </div>
    </Teleport>
  `
};

export default CoordinatorPreventiveOrderDetailModal;
