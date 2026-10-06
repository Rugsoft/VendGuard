/**
 * VendGuard - TechnicianMachineHistoryModal Component (TechnicianMachineHistoryModal.js)
 *
 * Read-only modal that shows the full incident history of a single vending machine,
 * opened from the technician's pending-route cards (RF-07 / EARS H.1-H.6 of
 * specs/technical/technician_machine_history_contracts.md).
 *
 * Features:
 * - Read-only: no mutating actions whatsoever (EARS H.5).
 * - Reverse chronological incident list with status/urgency badges (IncidentBadge).
 * - Reopen counter per ticket as chronic-failure evidence (Art. V.6 / EARS 9.3).
 * - Inline error handling for 403 (not assigned) and 404 (machine not found).
 * - Docker design system: ModalDialog shell, 4px controls, 8px card, hairline borders.
 *
 * Dogma Vanilla: pure Vue 3 component with in-browser template, zero dependencies.
 */

import { api, ApiError } from '../api.js';
import { ModalDialog } from './ModalDialog.js';
import { IncidentBadge } from './IncidentBadge.js';

export const TechnicianMachineHistoryModal = {
  name: 'TechnicianMachineHistoryModal',
  components: {
    ModalDialog,
    IncidentBadge
  },
  props: {
    /**
     * Controls dialog visibility (v-model).
     */
    modelValue: {
      type: Boolean,
      default: false
    },
    /**
     * Machine whose history will be loaded when the dialog opens.
     * Expected shape: { id: number, code?: string, model?: string }
     */
    machine: {
      type: Object,
      default: null
    }
  },
  emits: ['update:modelValue', 'close'],
  data() {
    return {
      isLoading: false,
      errorMessage: '',
      errorCode: '',
      machineInfo: null,
      history: []
    };
  },
  watch: {
    modelValue: {
      immediate: true,
      handler(isOpen) {
        if (isOpen && this.machine && this.machine.id) {
          this.fetchHistory();
        }
        if (!isOpen) {
          this.resetState();
        }
      }
    }
  },
  methods: {
    resetState() {
      this.isLoading = false;
      this.errorMessage = '';
      this.errorCode = '';
      this.machineInfo = null;
      this.history = [];
    },

    /**
     * Loads the machine history from the backend (EARS H.1, H.2).
     * Errors are rendered inline: 403 NOT_ASSIGNED_TO_TECHNICIAN and
     * 404 MACHINE_NOT_FOUND per EARS H.3 and H.4.
     */
    async fetchHistory() {
      this.isLoading = true;
      this.errorMessage = '';
      this.errorCode = '';
      this.machineInfo = null;
      this.history = [];
      try {
        const response = await api.technician.getMachineHistory(this.machine.id);
        // api.js unwraps the { success, data } envelope.
        this.machineInfo = response?.machine || this.machine;
        this.history = Array.isArray(response?.history) ? response.history : [];
      } catch (err) {
        if (err instanceof ApiError) {
          this.errorCode = err.code;
          if (err.status === 403) {
            this.errorMessage = 'No puedes consultar el historial de esta máquina porque no tiene ninguna avería activa asignada a tu ruta.';
          } else if (err.status === 404) {
            this.errorMessage = 'La máquina solicitada no existe o ha sido dada de baja.';
          } else {
            this.errorMessage = err.message || 'No se pudo cargar el historial de la máquina.';
          }
        } else {
          this.errorCode = 'UNKNOWN_ERROR';
          this.errorMessage = err?.message || 'No se pudo cargar el historial de la máquina.';
        }
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Formats an ISO/DATETIME string for the compact mobile list.
     * @param {string|null} value
     * @returns {string}
     */
    formatDateTime(value) {
      if (!value) return '—';
      const parsed = new Date(String(value).replace(' ', 'T'));
      if (Number.isNaN(parsed.getTime())) return String(value);
      return parsed.toLocaleString('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
    },

    handleBackdropClose() {
      this.$emit('update:modelValue', false);
      this.$emit('close');
    }
  },
  template: `
    <ModalDialog
      :model-value="modelValue"
      title="Historial de la máquina"
      :subtitle="machineSubtitle"
      size="md"
      @update:model-value="handleBackdropClose"
      @close="handleBackdropClose"
    >
      <!-- Loading state -->
      <div v-if="isLoading" style="padding: 28px 0; text-align: center; font-size: 13px; color: var(--color-slate, #2c333f);">
        Cargando historial de averías...
      </div>

      <!-- Inline error state (EARS H.3 / H.4) -->
      <div
        v-else-if="errorMessage"
        data-testid="machine-history-error"
        style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: var(--radius-interactive, 4px); padding: 12px; font-size: 13px; color: #991b1b; line-height: 1.4;"
      >
        ⚠️ {{ errorMessage }}
        <div style="margin-top: 10px;">
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            style="height: 32px; font-size: 12px; padding: 0 12px;"
            data-testid="btn-retry-machine-history"
            @click="fetchHistory"
          >
            Reintentar
          </button>
        </div>
      </div>

      <!-- History content (EARS H.1 / H.2) -->
      <template v-else>
        <div
          data-testid="machine-history-count"
          style="font-size: 12px; color: var(--color-ink-muted, #6c7e9d); margin-bottom: 10px;"
        >
          {{ history.length }} {{ history.length === 1 ? 'avería registrada' : 'averías registradas' }} · ordenadas de más reciente a más antigua
        </div>

        <!-- Empty state -->
        <div
          v-if="history.length === 0"
          data-testid="machine-history-empty"
          style="padding: 20px 0; text-align: center; font-size: 13px; color: var(--color-slate, #2c333f);"
        >
          No hay averías registradas para esta máquina.
        </div>

        <!-- Incident history list -->
        <div v-else style="display: flex; flex-direction: column; gap: 10px; max-height: 46vh; overflow-y: auto;">
          <div
            v-for="entry in history"
            :key="entry.id"
            :data-testid="'machine-history-entry-' + entry.id"
            style="background-color: #f9fafb; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); padding: 10px 12px;"
          >
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px;">
              <span style="font-family: monospace; font-size: 12px; font-weight: 700; color: var(--color-slate, #2c333f);">
                #{{ entry.ticket_code }}
              </span>
              <div style="display: flex; gap: 6px; align-items: center;">
                <IncidentBadge type="urgency" :value="entry.urgency" />
                <IncidentBadge type="status" :value="entry.status" />
              </div>
            </div>

            <div style="font-size: 13px; color: var(--color-ink, #000000); line-height: 1.35; margin-bottom: 6px;">
              {{ entry.description }}
            </div>

            <div style="font-size: 11px; color: var(--color-ink-muted, #6c7e9d); line-height: 1.5;">
              <div>Reportada: {{ formatDateTime(entry.created_at) }}</div>
              <div v-if="entry.resolved_at">Resuelta: {{ formatDateTime(entry.resolved_at) }}</div>
              <div v-if="entry.closed_at">Cerrada: {{ formatDateTime(entry.closed_at) }}</div>
              <div v-if="entry.technician_name">Técnico: {{ entry.technician_name }}</div>
              <div v-if="entry.reopen_count > 0" style="font-weight: 700; color: #b45309;">
                ♻️ Reabierta {{ entry.reopen_count }} {{ entry.reopen_count === 1 ? 'vez' : 'veces' }} (garantía 48 h)
              </div>
            </div>
          </div>
        </div>
      </template>

      <template #footer>
        <button
          type="button"
          class="vg-btn vg-btn-primary"
          style="height: 36px; font-size: 13px; padding: 0 16px;"
          data-testid="btn-close-machine-history"
          @click="handleBackdropClose"
        >
          Cerrar
        </button>
      </template>
    </ModalDialog>
  `,
  computed: {
    /**
     * Header subtitle with the machine identity (falls back to the prop payload
     * while loading or if the backend omits the machine block).
     * @returns {string}
     */
    machineSubtitle() {
      const info = this.machineInfo || this.machine;
      if (!info) return '';
      const parts = [];
      if (info.code) parts.push(String(info.code));
      if (info.model) parts.push(String(info.model));
      return parts.join(' · ');
    }
  }
};
