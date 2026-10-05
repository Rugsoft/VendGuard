/**
 * VendGuard - CoordinatorIncidentDetailModal (CoordinatorIncidentDetailModal.js)
 * 
 * Ficha Integral de Detalle Operativo del modal de triaje (Módulo 09, RF-01 a RF-06).
 * 
 * Estructura maquetada y bloques renderizados:
 * 1. Cabecera fija: código de ticket, insignias de estado y urgencia, distintivo de
 *    reapertura, actualización manual y cierre (RF-02.1, RF-02.2).
 * 2. Cuerpo con desplazamiento vertical independiente (RNF-03):
 *    - Aviso destacado de reapertura en garantía con la fecha y el motivo de la sede (RF-02.2).
 *    - Tarjeta de ubicación y máquina con distintivo sanitario de perecederos (RF-02.3, Art. II).
 *    - Descripción original, canal de reporte y evidencia gráfica ampliable (RF-02.4).
 *    - Cronograma de hitos y monitor de SLA, con cuenta atrás activa o balance histórico
 *      formal cerrado (RF-03.2, RF-03.3).
 * 3. Pie de acciones fijo con el cierre del modal (RNF-03).
 * 
 * Alcance de esta tarea: los bloques de intervención técnica, bitácora y reintegro se
 * renderizan en T-IDM-10 (intervención, repuestos y reintegro), T-IDM-11 (bitácora y
 * formulario en línea) y T-IDM-12 (paneles operativos en línea).
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias externas).
 * Dualismo Lingüístico: código en inglés, interfaz y mensajes en español.
 */

import { api } from '../api.js';
import { IncidentBadge } from './IncidentBadge.js';

export const CoordinatorIncidentDetailModal = {
  name: 'CoordinatorIncidentDetailModal',
  components: {
    IncidentBadge
  },
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
      errorMessage: '',
      /** Visor de evidencia gráfica integrado en el propio modal (RF-02.4, RNF-06). */
      photoZoomOpen: false,
      /** Marca la evidencia rota o inaccesible para mostrar el recuadro de sustitución. */
      photoFailed: false
    };
  },
  computed: {
    /**
     * Código visible en la cabecera; mientras la ficha carga muestra el identificador recibido.
     */
    headerTicketCode() {
      if (this.incident?.ticket_code) {
        return `#${this.incident.ticket_code}`;
      }
      if (this.incidentId !== null && this.incidentId !== '') {
        return `#${this.incidentId}`;
      }
      return '#—';
    },

    /** Bloque de cabecera del ticket (RF-02). */
    incident() {
      return this.detail?.incident || null;
    },

    /** Sede cliente donde vive la máquina (RF-02). */
    location() {
      return this.detail?.location || null;
    },

    /** Metadatos de la máquina, con el indicador sanitario (RF-02, Art. II). */
    machine() {
      return this.detail?.machine || null;
    },

    /** Hitos del ciclo de vida con sus tiempos derivados (RF-03). */
    timeline() {
      return this.detail?.timeline || null;
    },

    /** Evaluación de SLA de frío, activa o histórica (RF-03, Art. II). */
    sla() {
      return this.detail?.sla || null;
    },

    /** Incidencia reabierta por la sede dentro de la ventana de garantía (RF-02.2). */
    hasReopening() {
      return Boolean(this.incident?.is_reopened);
    },

    /** Traducción del canal de reporte registrado por el sistema. */
    reportChannelLabel() {
      const channel = this.incident?.report_channel || '';
      if (channel === 'QR_CODE') {
        return 'Lectura QR Ciudadana';
      }
      if (channel === 'LOCATION_PORTAL') {
        return 'Portal de Sede';
      }
      return channel || 'No especificado';
    },

    /**
     * Línea temporal ordenada del expediente (RF-03.1). Los hitos aún no alcanzados se
     * muestran como pendientes; la pausa y la reapertura solo aparecen si realmente
     * ocurrieron.
     */
    timelineMilestones() {
      const timeline = this.timeline;
      if (!timeline) {
        return [];
      }

      const milestones = [
        { key: 'created', label: 'Aviso registrado', at: timeline.created_at || null, done: Boolean(timeline.created_at) },
        { key: 'assigned', label: 'Asignación técnica', at: timeline.assigned_at || null, done: Boolean(timeline.assigned_at) },
        { key: 'started', label: 'Intervención iniciada in situ', at: timeline.started_at || null, done: Boolean(timeline.started_at) },
        { key: 'paused', label: 'Pausa por repuestos', at: timeline.paused_at || null, done: Boolean(timeline.paused_at) },
        { key: 'resolved', label: 'Resolución técnica documentada', at: timeline.resolved_at || null, done: Boolean(timeline.resolved_at) }
      ].filter((milestone) => milestone.key !== 'paused' || milestone.done);

      if (this.hasReopening) {
        milestones.push({
          key: 'reopened',
          label: 'Reapertura en garantía',
          at: this.incident.reopened_at || null,
          detail: this.incident.reopened_reason ? `Motivo: ${this.incident.reopened_reason}` : null,
          done: true
        });
      }

      milestones.push({
        key: 'closed',
        label: 'Cierre definitivo formal',
        at: timeline.closed_at || null,
        done: Boolean(timeline.closed_at)
      });

      return milestones;
    },

    /** Tiempos derivados del expediente que se muestran bajo la línea temporal (RF-03.1). */
    timelineMetrics() {
      const timeline = this.timeline;
      if (!timeline) {
        return [];
      }

      const metrics = [];
      if (timeline.time_to_assign_minutes !== null && timeline.time_to_assign_minutes !== undefined) {
        metrics.push({ key: 'assign', label: 'Hasta asignación', value: this.formatMinutes(timeline.time_to_assign_minutes) });
      }
      if (timeline.time_to_first_response_minutes !== null && timeline.time_to_first_response_minutes !== undefined) {
        metrics.push({ key: 'response', label: 'Primera respuesta', value: this.formatMinutes(timeline.time_to_first_response_minutes) });
      }
      if (timeline.total_elapsed_minutes !== null && timeline.total_elapsed_minutes !== undefined) {
        metrics.push({ key: 'total', label: 'Tiempo total', value: this.formatMinutes(timeline.total_elapsed_minutes) });
      }
      return metrics;
    },

    /** Solo las máquinas perecederas soportan el objetivo de cadena de frío (Art. II). */
    hasSla() {
      return Boolean(this.sla?.has_sla_limit);
    },

    /** Presentación del monitor de SLA: ámbar/rojo si está incumplido, verde si cumplido. */
    slaMonitorStyle() {
      const breached = Boolean(this.sla?.is_breached);
      return {
        backgroundColor: breached ? 'var(--color-error-bg, #fddfdf)' : 'var(--color-success-bg, #eaf8f1)',
        border: breached ? '1px solid var(--color-error, #ff5757)' : '1px solid var(--color-success, #38bd7d)',
        borderRadius: 'var(--radius-card, 8px)',
        padding: '14px 16px'
      };
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
      this.photoZoomOpen = false;
      this.photoFailed = false;

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

    /**
     * Abre el visor integrado de la evidencia gráfica (RF-02.4, RNF-06).
     */
    openPhotoZoom() {
      if (!this.incident?.photo_url || this.photoFailed) {
        return;
      }
      this.photoZoomOpen = true;
    },

    closePhotoZoom() {
      this.photoZoomOpen = false;
    },

    /**
     * Evidencia gráfica rota o inaccesible: recuadro de sustitución sin romper la estructura.
     */
    handlePhotoError() {
      this.photoFailed = true;
      this.photoZoomOpen = false;
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
     * Formatea minutos como `X h Y min` (o `Y min` si no llega a la hora).
     */
    formatMinutes(minutes) {
      const total = Math.max(0, Math.round(Number(minutes)));
      if (!Number.isFinite(total)) {
        return '—';
      }
      const hours = Math.floor(total / 60);
      const remaining = total % 60;
      if (hours === 0) {
        return `${remaining} min`;
      }
      return `${hours} h ${remaining} min`;
    },

    handleBackdropClick(event) {
      if (event.target === event.currentTarget) {
        this.requestClose();
      }
    },

    /**
     * Escape cierra primero el visor integrado de la evidencia y, después, el modal.
     */
    handleKeyDown(event) {
      if (event.key !== 'Escape' || !this.isOpen) {
        return;
      }
      if (this.photoZoomOpen) {
        this.closePhotoZoom();
        return;
      }
      this.requestClose();
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
          style="position: relative; background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); box-shadow: var(--shadow-modal, 0 12px 32px rgba(0, 0, 0, 0.12)); width: min(1080px, 100%); height: min(88vh, 820px); max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; outline: none;"
        >
          <!-- CABECERA FIJA (RF-02.1) -->
          <header
            class="modal-header incident-detail-header"
            data-testid="incident-detail-header"
            style="flex: 0 0 auto; gap: 12px; background-color: #ffffff;"
          >
            <div style="display: flex; align-items: center; gap: 10px; min-width: 0; flex-wrap: wrap;">
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
              <template v-if="incident">
                <IncidentBadge
                  :value="incident.status"
                  type="status"
                  :custom-label="incident.status_label"
                  data-testid="incident-detail-status-badge"
                />
                <IncidentBadge
                  :value="incident.urgency"
                  type="urgency"
                  :custom-label="incident.urgency_label"
                  data-testid="incident-detail-urgency-badge"
                />
                <span
                  v-if="hasReopening"
                  class="badge badge-reopened"
                  data-testid="incident-detail-reopened-chip"
                  style="display: inline-flex; align-items: center; gap: 6px; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 600; background-color: var(--color-warning-bg, #fef8e7); color: var(--color-warning-text, #92400e); border: 1px solid var(--color-warning, #f8b60f); border-radius: var(--radius-interactive, 4px); padding: 3px 8px;"
                >
                  ⚠️ Reabierta en Garantía
                </span>
              </template>
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
              <!-- 1. Aviso de Reapertura en Garantía (RF-02.2) -->
              <section
                v-if="hasReopening"
                class="reopened-banner"
                data-testid="reopened-banner"
                style="background-color: var(--color-warning-bg, #fef8e7); border: 1px solid var(--color-warning, #f8b60f); border-radius: var(--radius-card, 8px); padding: 14px 16px; margin-bottom: 16px;"
              >
                <strong style="display: block; font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 15px; font-weight: 500; color: var(--color-warning-text, #92400e); margin-bottom: 4px;">
                  ⚠️ Reabierta en Garantía · {{ formatDateTime(incident.reopened_at) }}
                </strong>
                <p data-testid="reopened-reason" style="margin: 0; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-warning-text, #92400e); line-height: 1.45;">
                  Motivo aportado por la sede: {{ incident.reopened_reason || 'Sin motivo registrado.' }}
                </p>
              </section>

              <!-- 2. Información de Sede y Máquina (RF-02.3, Art. II) -->
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
                    <span v-if="location.code">({{ location.code }})</span>
                  </p>
                  <p data-testid="location-zone" style="margin: 0 0 4px; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f);">
                    <strong>Ubicación física:</strong> {{ location.floor_zone || 'No especificada' }}
                  </p>
                  <p v-if="location.address" style="margin: 0 0 4px; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f);">
                    <strong>Dirección:</strong> {{ location.address }}
                  </p>
                  <p style="margin: 0; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-secondary, #434c5f);">
                    <strong>Recepción física:</strong> {{ location.has_physical_reception ? 'Sí' : 'No' }}
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
                  <p v-if="machine.manufacturer" style="margin: 0 0 4px; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f);">
                    <strong>Fabricante:</strong> {{ machine.manufacturer }}
                  </p>
                  <p data-testid="machine-type" style="margin: 0 0 8px; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f);">
                    <strong>Tipo:</strong> {{ machine.type_label || machine.type || 'No especificado' }}
                  </p>
                  <span
                    v-if="machine.has_perishables"
                    class="perishable-warning"
                    data-testid="machine-perishable"
                    style="display: inline-flex; align-items: center; gap: 6px; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 600; background-color: var(--color-error-bg, #fddfdf); color: var(--color-error-text, #b91c1c); border: 1px solid var(--color-error, #ff5757); border-radius: var(--radius-interactive, 4px); padding: 4px 8px;"
                  >
                    ❄️ Alimentos Perecederos (SLA crítico {{ sla?.sla_limit_hours ?? 4 }} h)
                  </span>
                </div>
              </section>

              <!-- 3. Reporte Original y Evidencia Gráfica (RF-02.4) -->
              <section
                class="info-card detail-section"
                data-testid="section-description"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 8px;">
                  📝 Descripción del Problema
                </h3>
                <p data-testid="incident-description" style="margin: 0 0 8px; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-slate, #2c333f); line-height: 1.5;">
                  {{ incident.description || 'Sin descripción registrada.' }}
                </p>
                <p style="margin: 0 0 12px; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-secondary, #434c5f);">
                  <strong>Canal de reporte:</strong> {{ reportChannelLabel }}
                </p>

                <div
                  v-if="incident.photo_url && !photoFailed"
                  class="photo-preview"
                  style="display: inline-flex; flex-direction: column; gap: 6px;"
                >
                  <img
                    :src="incident.photo_url"
                    alt="Evidencia gráfica del reporte"
                    data-testid="incident-photo-thumb"
                    style="max-width: 180px; max-height: 140px; object-fit: cover; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); cursor: zoom-in; background-color: var(--color-canvas, #f9fafb);"
                    @click="openPhotoZoom"
                    @error="handlePhotoError"
                  />
                  <span style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-ink-muted, #6c7e9d);">
                    🔍 Pulsa la miniatura para ampliar
                  </span>
                </div>

                <div
                  v-else-if="incident.photo_url && photoFailed"
                  class="photo-fallback"
                  data-testid="incident-photo-fallback"
                  style="display: flex; align-items: center; justify-content: center; width: 180px; height: 100px; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-ink-muted, #6c7e9d); background-color: var(--color-canvas, #f9fafb); border: 1px dashed var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); text-align: center; padding: 8px;"
                >
                  Evidencia gráfica no disponible
                </div>
              </section>

              <!-- 4. Cronograma y Monitor de SLA (RF-03) -->
              <section
                class="info-card detail-section"
                data-testid="section-timeline-sla"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 12px;">
                  ⏱️ Ciclo de Vida y Cumplimiento de SLA
                </h3>

                <ol
                  class="incident-timeline"
                  data-testid="incident-timeline"
                  style="list-style: none; margin: 0 0 12px; padding: 0; display: flex; flex-direction: column;"
                >
                  <li
                    v-for="milestone in timelineMilestones"
                    :key="milestone.key"
                    :data-testid="'timeline-milestone-' + milestone.key"
                    class="timeline-row"
                    style="display: flex; align-items: flex-start; gap: 10px; padding-bottom: 12px;"
                  >
                    <span
                      class="timeline-dot"
                      aria-hidden="true"
                      :style="{ marginTop: '4px', width: '10px', height: '10px', borderRadius: '50%', flex: '0 0 auto', backgroundColor: milestone.done ? 'var(--color-primary, #2560ff)' : 'var(--color-hairline-soft, #a9b4c6)' }"
                    ></span>
                    <div>
                      <div :style="{ fontFamily: 'var(--font-body, Inter, sans-serif)', fontSize: '13px', fontWeight: '600', color: milestone.done ? 'var(--color-ink-slate, #2c333f)' : 'var(--color-ink-muted, #6c7e9d)' }">
                        {{ milestone.label }}
                      </div>
                      <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-ink-muted, #6c7e9d);">
                        {{ milestone.at ? formatDateTime(milestone.at) : 'Pendiente' }}
                      </div>
                      <div v-if="milestone.detail" style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-ink-secondary, #434c5f); margin-top: 2px; line-height: 1.4;">
                        {{ milestone.detail }}
                      </div>
                    </div>
                  </li>
                </ol>

                <div
                  v-if="timelineMetrics.length"
                  class="timeline-metrics"
                  data-testid="timeline-metrics"
                  style="display: flex; flex-wrap: wrap; gap: 16px; padding: 10px 12px; margin-bottom: 12px; background-color: var(--color-canvas, #f9fafb); border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-ink-secondary, #434c5f);"
                >
                  <span v-for="metric in timelineMetrics" :key="metric.key">
                    {{ metric.label }}: <strong style="color: var(--color-ink-slate, #2c333f);">{{ metric.value }}</strong>
                  </span>
                </div>

                <div
                  v-if="hasSla"
                  class="sla-monitor-card"
                  data-testid="sla-monitor"
                  :style="slaMonitorStyle"
                >
                  <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <strong :style="{ fontFamily: 'var(--font-display, DM Sans, sans-serif)', fontSize: '14px', fontWeight: '500', color: sla.is_breached ? 'var(--color-error-text, #b91c1c)' : 'var(--color-success-text, #065f46)' }">
                      SLA de Cadena de Frío ({{ sla.sla_limit_hours }} h)
                    </strong>
                    <span style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 600; color: var(--color-ink-secondary, #434c5f);">
                      {{ sla.is_active_countdown ? 'Cuenta atrás activa' : 'Balance histórico formal' }}
                    </span>
                  </div>
                  <p data-testid="sla-balance" :style="{ margin: '8px 0 0', fontFamily: 'var(--font-body, Inter, sans-serif)', fontSize: '15px', fontWeight: '600', color: sla.is_breached ? 'var(--color-error-text, #b91c1c)' : 'var(--color-success-text, #065f46)' }">
                    {{ sla.historical_balance }}
                  </p>
                  <p v-if="sla.sla_target_at" style="margin: 4px 0 0; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-ink-secondary, #434c5f);">
                    Objetivo de cumplimiento: {{ formatDateTime(sla.sla_target_at) }}
                  </p>
                </div>
              </section>

              <!-- Bloques pendientes de contenido: T-IDM-10 (intervención y reintegro) y T-IDM-11 (bitácora) -->
              <section
                class="info-card detail-section"
                data-testid="section-technical-intervention"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 8px;">
                  🔧 Intervención Técnica
                </h3>
              </section>

              <section
                class="info-card detail-section"
                data-testid="section-comments"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 8px;">
                  💬 Bitácora y Notas de Taller
                </h3>
              </section>

              <section
                v-if="detail.refund && detail.refund.has_refund"
                class="info-card detail-section refund-card"
                data-testid="section-refund"
                style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 16px;"
              >
                <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 500; color: var(--color-ink, #000000); margin: 0 0 8px;">
                  💰 Reintegro Económico Vinculado
                </h3>
              </section>

              <!-- Visor Integrado de Evidencia Gráfica (RNF-06: sin modales superpuestos) -->
              <div
                v-if="photoZoomOpen"
                class="photo-zoom-overlay"
                data-testid="photo-zoom"
                style="position: absolute; inset: 0; z-index: 5; display: flex; align-items: center; justify-content: center; padding: 24px; background-color: rgba(0, 0, 0, 0.75);"
                @click.self="closePhotoZoom"
              >
                <img
                  :src="incident.photo_url"
                  alt="Evidencia gráfica ampliada"
                  data-testid="photo-zoom-image"
                  style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: var(--radius-card, 8px);"
                />
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary"
                  data-testid="photo-zoom-close"
                  style="position: absolute; top: 12px; right: 12px;"
                  @click="closePhotoZoom"
                >
                  ✕ Cerrar vista
                </button>
              </div>
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
