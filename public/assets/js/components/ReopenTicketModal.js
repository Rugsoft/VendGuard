/**
 * VendGuard - ReopenTicketModal Component (ReopenTicketModal.js)
 * 
 * Warranty Reopening Dialog (RF-09, EARS 9.1, 9.2, 9.3, T-36).
 * 
 * Features:
 * 1. Checks 48-hour warranty window since resolution date (EARS 9.1).
 * 2. If < 48h: prompts for mandatory reason, sends POST /api/incidents/{ticket_code}/reopen,
 *    unassigns technician and moves incident to coordinator triage queue.
 * 3. If > 48h: informs user that warranty expired and prompts for new ticket creation (EARS 9.2).
 * 4. Handles chronic incident limit (max 2 reopenings, 3rd blocked as chronic) (EARS 9.3).
 * 5. Uses ModalDialog (WAI-ARIA, 8px radius) and Docker design tokens.
 */

import { api } from '../api.js';
import { store } from '../store.js';
import { ModalDialog } from './ModalDialog.js';
import { IncidentBadge } from './IncidentBadge.js';

export const ReopenTicketModal = {
  name: 'ReopenTicketModal',
  components: {
    ModalDialog,
    IncidentBadge
  },
  props: {
    modelValue: {
      type: Boolean,
      default: false
    },
    machine: {
      type: Object,
      default: null
    }
  },
  emits: ['update:modelValue', 'reopened', 'create-new-ticket', 'close'],
  data() {
    return {
      reason: '',
      isSubmitting: false,
      errorMessage: '',
      isChronicBlocked: false
    };
  },
  computed: {
    isOpen: {
      get() { return this.modelValue; },
      set(val) { this.$emit('update:modelValue', val); }
    },
    activeIncident() {
      return this.machine?.active_incident || null;
    },
    ticketCode() {
      return this.activeIncident?.ticket_code || '';
    },
    modalTitle() {
      if (!this.machine) return 'Reapertura en Garantía';
      return `Reapertura en Garantía · Ticket #${this.ticketCode}`;
    },
    modalSubtitle() {
      if (!this.machine) return '';
      return `${this.machine.code} · ${this.machine.model || 'Vending'} (${this.machine.floor_wing || 'Sede'})`;
    },
    /**
     * Calculates hours elapsed since incident resolution
     */
    hoursSinceResolution() {
      if (!this.activeIncident?.resolved_at) return 0;
      const resolvedTime = new Date(this.activeIncident.resolved_at).getTime();
      const now = Date.now();
      if (isNaN(resolvedTime)) return 0;
      return Math.max(0, (now - resolvedTime) / (1000 * 60 * 60));
    },
    /**
     * Whether warranty window (< 48 hours) is active
     */
    isWarrantyActive() {
      // If status is not RESOLVED or RESUELTA, cannot reopen
      const status = String(this.activeIncident?.status || '').toUpperCase();
      if (status !== 'RESUELTA' && status !== 'RESOLVED') {
        return false;
      }
      return this.hoursSinceResolution <= 48.0;
    },
    remainingWarrantyHours() {
      const remaining = 48.0 - this.hoursSinceResolution;
      return Math.max(0, Math.round(remaining));
    }
  },
  watch: {
    modelValue(isOpen) {
      if (isOpen) {
        this.reason = '';
        this.errorMessage = '';
        this.isChronicBlocked = false;
      }
    }
  },
  methods: {
    closeModal() {
      this.isOpen = false;
      this.$emit('close');
    },

    /**
     * Submits incident reopening request (RF-09)
     */
    async handleReopenSubmit() {
      if (!this.ticketCode || !this.reason.trim()) return;

      this.errorMessage = '';
      this.isSubmitting = true;
      store.setLoading(true);

      try {
        const response = await api.incidents.reopen(this.ticketCode, this.reason.trim());

        store.addAlert(
          `Incidencia #${this.ticketCode} reabierta con éxito en garantía. Trasladada con prioridad a coordinación.`,
          'success',
          6000
        );

        this.$emit('reopened', response);
        this.closeModal();
      } catch (err) {
        // Handle specific business exceptions
        if (err.code === 'CHRONIC_INCIDENT_LIMIT') {
          this.isChronicBlocked = true;
          this.errorMessage = err.message || 'Se ha superado el máximo de 2 reaperturas sucesivas. Expediente marcado como Avería Crónica.';
        } else if (err.code === 'WARRANTY_WINDOW_EXPIRED' || err.status === 422) {
          this.errorMessage = err.message || 'Han transcurrido más de 48 horas desde la resolución. Debe crearse un nuevo ticket.';
        } else {
          this.errorMessage = err.message || 'Error al reabrir la incidencia. Por favor, inténtelo de nuevo.';
        }
      } finally {
        this.isSubmitting = false;
        store.setLoading(false);
      }
    },

    handleCreateNewTicket() {
      this.closeModal();
      this.$emit('create-new-ticket', this.machine);
    }
  },
  template: `
    <ModalDialog
      v-model="isOpen"
      :title="modalTitle"
      :subtitle="modalSubtitle"
      size="md"
      @close="closeModal"
    >
      <div v-if="activeIncident" class="vg-reopen-modal-content">
        <!-- =============================================================== -->
        <!-- SCENARIO A: CHRONIC INCIDENT LIMIT REACHED (EARS 9.3)           -->
        <!-- =============================================================== -->
        <div
          v-if="isChronicBlocked"
          style="background-color: #fee2e2; border: 1px solid #f87171; border-radius: var(--radius-interactive, 4px); padding: 16px; margin-bottom: 16px;"
        >
          <div style="display: flex; align-items: flex-start; gap: 10px;">
            <span style="font-size: 24px;" aria-hidden="true">🚫</span>
            <div>
              <strong style="color: #991b1b; font-size: 15px; font-family: var(--font-display, 'DM Sans', sans-serif); display: block; margin-bottom: 4px;">
                Expediente Bloqueado: Avería Crónica
              </strong>
              <p style="color: #7f1d1d; font-size: 13px; margin: 0 0 10px 0; line-height: 1.4;">
                Esta máquina ha superado el límite de <strong>2 reaperturas sucesivas</strong>. Para garantizar una auditoría técnica en profundidad de la máquina, no es posible reabrirla automáticamente desde este portal.
              </p>
              <div style="font-size: 13px; font-weight: 600; color: #991b1b;">
                Por favor, contacte directamente con el servicio de coordinación telefónico o de guardia.
              </div>
            </div>
          </div>

          <div style="display: flex; justify-content: flex-end; margin-top: 16px;">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              @click="closeModal"
            >
              Entendido
            </button>
          </div>
        </div>

        <!-- =============================================================== -->
        <!-- SCENARIO B: WARRANTY WINDOW EXPIRED (> 48h) (EARS 9.2)          -->
        <!-- =============================================================== -->
        <div
          v-else-if="!isWarrantyActive"
          style="background-color: #fffbeb; border: 1px solid #fef3c7; border-radius: var(--radius-interactive, 4px); padding: 16px; margin-bottom: 16px;"
        >
          <div style="display: flex; align-items: flex-start; gap: 10px;">
            <span style="font-size: 24px;" aria-hidden="true">⏱️</span>
            <div>
              <strong style="color: #92400e; font-size: 15px; font-family: var(--font-display, 'DM Sans', sans-serif); display: block; margin-bottom: 4px;">
                Ventana de Garantía Expirada (> 48 horas)
              </strong>
              <p style="color: #78350f; font-size: 13px; margin: 0 0 12px 0; line-height: 1.4;">
                Han transcurrido más de 48 horas desde que esta avería fue dada por resuelta. Conforme a las normas operativas (RF-09), para una nueva visita debe registrarse un <strong>nuevo parte de avería independiente</strong>.
              </p>
            </div>
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 12px;">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              @click="closeModal"
            >
              Cerrar
            </button>
            <button
              type="button"
              class="vg-btn vg-btn-primary"
              @click="handleCreateNewTicket"
            >
              Abrir nuevo reporte de avería
            </button>
          </div>
        </div>

        <!-- =============================================================== -->
        <!-- SCENARIO C: ACTIVE WARRANTY WINDOW (< 48h) - REOPEN FORM        -->
        <!-- =============================================================== -->
        <form v-else @submit.prevent="handleReopenSubmit">
          <!-- Active Warranty Information Banner -->
          <div
            style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-interactive, 4px); padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px;"
          >
            <div>
              <div style="display: flex; align-items: center; gap: 8px;">
                <span style="color: #166534; font-weight: 700; font-size: 13px;">
                  Garantía de 48h Activa
                </span>
                <IncidentBadge
                  value="RESUELTA"
                  type="status"
                  size="sm"
                />
              </div>
              <div style="font-size: 12px; color: #15803d; margin-top: 2px;">
                La máquina fue reparada recientemente. Quedan aprox. <strong>{{ remainingWarrantyHours }} horas</strong> de ventana de garantía.
              </div>
            </div>

            <div style="text-align: right; font-family: monospace; font-size: 12px; color: #166534; font-weight: 600;">
              Ticket #{{ ticketCode }}
            </div>
          </div>

          <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-slate, #2c333f); margin-bottom: 16px; line-height: 1.4;">
            Al reabrir la incidencia, el ticket pasará de inmediato a estado <strong>REABIERTA</strong>, se desasignará automáticamente al técnico previo para una nueva revisión objetiva y se elevará con prioridad urgente a coordinación (RF-09).
          </p>

          <!-- Reason field (Mandatory) -->
          <div style="margin-bottom: 16px;">
            <label
              for="reopen-reason-input"
              style="display: block; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 6px;"
            >
              ¿Por qué motivo persiste o se reproduce el fallo? <span style="color: #dc2626;">*</span>
            </label>
            <textarea
              id="reopen-reason-input"
              v-model="reason"
              class="vg-textarea"
              placeholder="Explica qué síntoma sigue ocurriendo tras la reparación (ej: el técnico cambió la sonda pero la temperatura sigue subiendo a +10ºC)..."
              rows="4"
              required
              :disabled="isSubmitting"
            ></textarea>
          </div>

          <!-- Error Alert -->
          <div
            v-if="errorMessage"
            style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 10px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 16px;"
            role="alert"
          >
            {{ errorMessage }}
          </div>

          <!-- Actions -->
          <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid var(--color-hairline, #c8cfda); padding-top: 16px;">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              @click="closeModal"
              :disabled="isSubmitting"
            >
              Cancelar
            </button>
            <button
              type="submit"
              class="vg-btn"
              style="background-color: #dc2626; color: #ffffff; border-color: #b91c1c; border-radius: var(--radius-interactive, 4px);"
              :disabled="isSubmitting || !reason.trim()"
            >
              <span v-if="!isSubmitting">Confirmar reapertura en garantía</span>
              <span v-else>Reabriendo ticket...</span>
            </button>
          </div>
        </form>
      </div>
    </ModalDialog>
  `
};

export default ReopenTicketModal;
