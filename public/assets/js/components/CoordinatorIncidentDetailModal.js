/**
 * VendGuard - CoordinatorIncidentDetailModal (CoordinatorIncidentDetailModal.js)
 * 
 * Ficha Integral de Detalle Operativo del modal de triaje (Módulo 09, RF-01 a RF-06).
 * 
 * Estructura maquetada (T-IDM-08):
 * 1. Cabecera fija con el código de ticket, actualización manual, y cierre (RF-02.1).
 * 2. Cuerpo con desplazamiento vertical independiente organizado en tarjetas de sección (RNF-03).
 * 3. Pie de acciones fijo con el cierre del modal (RNF-03).
 * 
 * Alcance de esta tarea: la carcasa estructural y su ciclo de apertura/cierre. Los
 * bloques internos se renderizan en T-IDM-09 (cabecera, metadatos y SLA), T-IDM-10
 * (intervención, repuestos y reintegro), T-IDM-11 (bitácora y formulario en línea) y
 * T-IDM-12 (paneles operativos en línea).
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias externas).
 * Dualismo Lingüístico: código en inglés, interfaz y mensajes en español.
 */

import { api } from '../api.js';

export const CoordinatorIncidentDetailModal = {
  name: 'CoordinatorIncidentDetailModal',
  props: {
    /**
     * Visibilidad del modal, controlada por la bandeja de triaje del coordinador.
     */
    isOpen: {
      type: Boolean,
      default: false
    },
    /**
     * Identificador del ticket a inspeccionar: ID primario o código de ticket.
     */
    incidentId: {
      type: [Number, String],
      default: null
    }
  },
  emits: ['close', 'incident-updated'],
  data() {
    return {
      detail: null,
      isLoading: false,
      errorMessage: ''
    };
  },
  computed: {
    /**
     * Código visible en la cabecera; mientras la ficha carga muestra el identificador recibido.
     */
    headerTicketCode() {
      if (this.detail?.incident?.ticket_code) {
        return `#${this.detail.incident.ticket_code}`;
      }
      if (this.incidentId !== null && this.incidentId !== '') {
        return `#${this.incidentId}`;
      }
      return '#—';
    }
  },
  watch: {
    isOpen: {
      immediate: true,
      handler(isOpen) {
        this.handleOpenState(isOpen);
      }
    },
    incidentId() {
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
     * Sincroniza los efectos de apertura/cierre: bloqueo del scroll de fondo y atajo Escape.
     * El guardián de formulario sucio (T-IDM-13) refinará el cierre sobre `requestClose()`.
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
     * Carga la ficha enriquecida del expediente desde la API de Coordinación (RNF-01).
     */
    async fetchDetail() {
      if (this.incidentId === null || this.incidentId === '') {
        return;
      }

      this.isLoading = true;
      this.errorMessage = '';

      try {
        // api.js ya desenvuelve la envolvente { success, data }, por lo que aquí
        // llega directamente el DTO de detalle enriquecido.
        const response = await api.coordinator.getIncidentDetail(this.incidentId);
        this.detail = response && typeof response === 'object' ? response : null;
      } catch (err) {
        this.detail = null;
        this.errorMessage = err?.message || 'No se pudo cargar el detalle de la incidencia.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Solicita el cierre del modal; el componente padre decide desmontarlo.
     * El guardián de formulario sucio (T-IDM-13) interceptará esta señal antes de emitir.
     */
    requestClose() {
      this.$emit('close');
    },

    /**
     * Notifica al padre que la ficha cambió (asignación, descarte o comentario)
     * para refrescar la fila de la tabla de triaje sin recargar la página.
     * Lo invocarán los paneles en línea (T-IDM-12) y la bitácora (T-IDM-11).
     */
    notifyIncidentUpdated(payload = null) {
      this.$emit('incident-updated', payload);
    },

    handleBackdropClick(event) {
      if (event.target === event.currentTarget) {
        this.requestClose();
      }
    },

    handleKeyDown(event) {
      if (event.key === 'Escape' && this.isOpen) {
        this.requestClose();
      }
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
        class="vg-modal-backdrop incident-detail-backdrop"
        data-testid="incident-detail-backdrop"
        role="presentation"
        style="padding: 24px;"
        @click.self="handleBackdropClick"
      >
        <div
          class="vg-modal-container incident-detail-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="incident-detail-title"
          tabindex="-1"
          data-testid="incident-detail-modal"
          style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); box-shadow: var(--shadow-modal, 0 12px 32px rgba(0, 0, 0, 0.12)); width: min(1080px, 100%); height: min(88vh, 820px); max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; outline: none;"
        >
          <!-- CABECERA FIJA (RF-02.1) -->
          <header
            class="modal-header incident-detail-header"
            data-testid="incident-detail-header"
            style="flex: 0 0 auto; gap: 12px; background-color: #ffffff;"
          >
            <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
              <h2
                id="incident-detail-title"
                class="incident-code"
                data-testid="incident-detail-code"
                style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 20px; font-weight: 500; color: var(--color-ink, #000000); margin: 0; white-space: nowrap;"
              >
                {{ headerTicketCode }}
              </h2>
              <span
                v-if="isLoading"
                class="detail-loading-chip"
                style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 500; color: var(--color-ink-muted, #6c7e9d);"
              >
                Cargando ficha…
              </span>
            </div>

            <div style="display: flex; align-items: center; gap: 8px;">
              <button
                type="button"
                class="vg-btn vg-btn-secondary btn-refresh"
                data-testid="incident-detail-refresh"
                :disabled="isLoading"
                @click="fetchDetail"
              >
                Actualizar
              </button>
              <button
                type="button"
                class="vg-btn vg-btn-secondary btn-close"
                data-testid="incident-detail-close"
                aria-label="Cerrar la ficha de detalle"
                @click="requestClose"
              >
                ✕
              </button>
            </div>
          </header>

          <!-- CUERPO CON SCROLL VERTICAL INDEPENDIENTE (RNF-03) -->
          <main
            class="modal-body modal-body-scrollable incident-detail-body"
            data-testid="incident-detail-body"
            style="background-color: var(--color-canvas, #f9fafb);"
          >
            <div
              v-if="isLoading && !detail"
              class="detail-feedback"
              data-testid="incident-detail-loading"
              style="padding: 48px 20px; text-align: center; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-muted, #6c7e9d);"
            >
              Cargando el expediente de la incidencia…
            </div>

            <div
              v-else-if="errorMessage"
              class="detail-feedback detail-error"
              data-testid="incident-detail-error"
              style="padding: 32px 20px; text-align: center; border: 1px solid var(--color-error, #ff5757); border-radius: var(--radius-card, 8px); background-color: var(--color-error-bg, #fddfdf);"
            >
              <p style="margin: 0 0 12px; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-error-text, #b91c1c);">
                {{ errorMessage }}
              </p>
              <button type="button" class="vg-btn vg-btn-primary" @click="fetchDetail">
                Reintentar
              </button>
            </div>

            <template v-else-if="detail">
              <!-- Bloques de sección: el contenido dinámico llega en T-IDM-09/10/11 -->
              <section
                class="info-card detail-section"
                data-testid="section-location-machine"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 8px;">
                  Ubicación y Máquina
                </h3>
              </section>

              <section
                class="info-card detail-section"
                data-testid="section-description"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 8px;">
                  Descripción del Problema
                </h3>
              </section>

              <section
                class="info-card detail-section"
                data-testid="section-timeline-sla"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 8px;">
                  Ciclo de Vida y Cumplimiento de SLA
                </h3>
              </section>

              <section
                class="info-card detail-section"
                data-testid="section-technical-intervention"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 8px;">
                  Intervención Técnica
                </h3>
              </section>

              <section
                class="info-card detail-section"
                data-testid="section-comments"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 8px;">
                  Bitácora y Notas de Taller
                </h3>
              </section>

              <section
                v-if="detail.refund && detail.refund.has_refund"
                class="info-card detail-section refund-card"
                data-testid="section-refund"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 8px;">
                  Reintegro Económico Vinculado
                </h3>
              </section>
            </template>

            <p
              v-else
              class="detail-feedback"
              data-testid="incident-detail-empty"
              style="padding: 48px 20px; text-align: center; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-muted, #6c7e9d);"
            >
              Seleccione un aviso de la bandeja para inspeccionar su expediente.
            </p>
          </main>

          <!-- PIE DE ACCIONES FIJO (RNF-03) -->
          <footer
            class="modal-footer incident-detail-footer"
            data-testid="incident-detail-footer"
            style="flex: 0 0 auto; background-color: #ffffff; justify-content: flex-end;"
          >
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              data-testid="incident-detail-footer-close"
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

export default CoordinatorIncidentDetailModal;
