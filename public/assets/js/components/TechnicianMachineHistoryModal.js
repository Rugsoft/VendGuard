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
  emits: ['update:modelValue', 'close', 'open-official-act'],
  data() {
    return {
      isLoading: false,
      errorMessage: '',
      errorCode: '',
      machineInfo: null,
      history: [],
      spoilageCode: '',
      spoilageMachine: null,
      spoilageRecords: [],
      spoilageLoading: false,
      spoilageError: '',
      spoilageSearched: false,
      /** Detalles ampliados por id de incidencia (EARS 04.1, T-TAXONOMY-18). */
      details: {},
      detailLoading: {},
      detailErrors: {}
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
      this.spoilageCode = '';
      this.spoilageMachine = null;
      this.spoilageRecords = [];
      this.spoilageLoading = false;
      this.spoilageError = '';
      this.spoilageSearched = false;
      this.details = {};
      this.detailLoading = {};
      this.detailErrors = {};
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
     * Busca las actas de cualquier máquina por su código visible (QA 3.1).
     * La proyección pública del Técnico nunca contiene importes (Art. V.4).
     */
    async fetchSpoilageActs() {
      const code = String(this.spoilageCode || '').trim().toUpperCase();
      this.spoilageCode = code;
      this.spoilageError = '';
      this.spoilageSearched = true;

      if (code === '') {
        this.spoilageMachine = null;
        this.spoilageRecords = [];
        this.spoilageError = 'Introduce el código de la máquina para consultar sus actas.';
        return;
      }

      this.spoilageLoading = true;
      try {
        const response = await api.technician.getMachineSanitarySpoilageRecordsByCode(code);
        const data = response?.data || response || {};
        this.spoilageMachine = data;
        this.spoilageRecords = Array.isArray(data.records) ? data.records : [];
      } catch (err) {
        this.spoilageMachine = null;
        this.spoilageRecords = [];
        this.spoilageError = err instanceof ApiError && err.status === 404
          ? 'No existe una máquina activa con ese código.'
          : (err?.message || 'No se pudieron cargar las actas de la máquina.');
      } finally {
        this.spoilageLoading = false;
      }
    },

    openSpoilageAct(record) {
      const id = Number(record?.id);
      if (Number.isInteger(id) && id > 0) {
        this.$emit('open-official-act', id);
      }
    },

    /**
     * Etiqueta de la clasificación congelada de un expediente (EARS 04.1).
     *
     * Sólo pinta código, categoría y nombre cuando los cinco snapshots están
     * presentes; una avería histórica sin clasificar no inventa una etiqueta.
     * @param {Object} entry
     * @returns {string}
     */
    taxonomyLabel(entry) {
      const cause = entry?.root_cause;
      const solution = entry?.standard_solution;
      if (!cause || !solution || !cause.name || !solution.name) {
        return '';
      }

      const causeLabel = `${cause.code ? `[${cause.code}] ` : ''}${cause.name}`.trim();
      const solutionLabel = `${solution.code ? `[${solution.code}] ` : ''}${solution.name}`.trim();

      return `${causeLabel}${cause.category_label ? ` (${cause.category_label})` : ''} → ${solutionLabel}`;
    },

    /**
     * Abre o cierra el detalle ampliado de un expediente (T-TAXONOMY-18).
     * El primer despliegue pide el detalle al backend; los siguientes reutilizan
     * la respuesta ya cargada en memoria.
     * @param {Object} entry
     */
    async toggleDetail(entry) {
      const incidentId = entry?.id;
      if (!incidentId) return;

      if (this.details[incidentId]) {
        const remaining = { ...this.details };
        delete remaining[incidentId];
        this.details = remaining;
        return;
      }

      await this.fetchDetail(entry);
    },

    /**
     * Carga el detalle read-only del expediente (`GET /api/technician/incidents/{id}/detail`).
     * @param {Object} entry
     */
    async fetchDetail(entry) {
      const incidentId = entry?.id;
      if (!incidentId) return;

      this.detailLoading = { ...this.detailLoading, [incidentId]: true };
      this.detailErrors = { ...this.detailErrors, [incidentId]: '' };

      try {
        const detail = await api.technician.getIncidentDetail(incidentId);
        this.details = { ...this.details, [incidentId]: detail };
      } catch (err) {
        this.detailErrors = {
          ...this.detailErrors,
          [incidentId]: err?.message || 'No se pudo cargar el detalle del expediente.'
        };
      } finally {
        this.detailLoading = { ...this.detailLoading, [incidentId]: false };
      }
    },

    /**
     * Etiqueta de un cierre anterior preservado (C1, EARS 04.1).
     * @param {Object} classification
     * @returns {string}
     */
    previousClassificationLabel(classification) {
      return this.taxonomyLabel({
        root_cause: classification?.root_cause,
        standard_solution: classification?.standard_solution
      });
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
      title="Historial y actas de la máquina"
      :subtitle="machineSubtitle"
      size="md"
      @update:model-value="handleBackdropClose"
      @close="handleBackdropClose"
    >
      <!-- Búsqueda directa de actas de cualquier máquina (QA 3.1) -->
      <section
        data-testid="machine-spoilage-lookup"
        style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card, 8px); padding: 12px; margin-bottom: 16px; background-color: var(--color-surface-1);"
      >
        <div style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 15px; font-weight: 600; color: var(--color-slate); margin-bottom: 4px;">
          Actas Oficiales APPCC
        </div>
        <p style="margin: 0 0 10px; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; line-height: 1.45; color: var(--color-ink-muted);">
          Consulta e imprime el Acta A4 de cualquier máquina por su código. Sin importes económicos (Art. V.4).
        </p>
        <form @submit.prevent="fetchSpoilageActs" style="display: flex; gap: 8px; align-items: stretch;">
          <label for="machine-spoilage-code" style="position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;">Código de máquina</label>
          <input
            id="machine-spoilage-code"
            v-model="spoilageCode"
            class="vg-input"
            type="text"
            maxlength="80"
            placeholder="Ej. VEND-0101"
            style="min-height: 44px; flex: 1 1 auto;"
            data-testid="machine-spoilage-code"
          />
          <button
            type="submit"
            class="vg-btn vg-btn-primary"
            style="min-height: 44px; white-space: nowrap;"
            :disabled="spoilageLoading"
            data-testid="btn-search-machine-spoilage"
          >
            {{ spoilageLoading ? 'Buscando…' : 'Buscar actas' }}
          </button>
        </form>
        <div
          v-if="spoilageError"
          role="alert"
          data-testid="machine-spoilage-error"
          style="margin-top: 8px; font-size: 12px; color: var(--color-error-text); line-height: 1.4;"
        >
          ⚠️ {{ spoilageError }}
        </div>
        <div v-else-if="spoilageRecords.length > 0" style="margin-top: 10px;">
          <div data-testid="machine-spoilage-machine" style="font-size: 12px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;">
            {{ spoilageMachineLabel }} · {{ spoilageRecords.length }} acta(s)
          </div>
          <div style="display: flex; flex-direction: column; gap: 6px; max-height: 28vh; overflow-y: auto;">
            <div
              v-for="record in spoilageRecords"
              :key="record.id"
              style="display: flex; align-items: center; justify-content: space-between; gap: 8px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive, 4px); padding: 8px 10px; background-color: var(--color-surface-card);"
            >
              <div style="font-size: 12px; color: var(--color-slate); line-height: 1.4;">
                <strong>{{ record.record_code }}</strong>
                <div style="color: var(--color-ink-muted);">
                  {{ formatDateTime(record.created_at) }} · {{ record.discarded_units_count }} uds. · {{ record.certificate_code }}
                </div>
              </div>
              <button
                type="button"
                class="vg-btn vg-btn-secondary"
                style="min-height: 44px; font-size: 12px; padding: 0 10px; white-space: nowrap;"
                :data-testid="'btn-machine-spoilage-act-' + record.id"
                @click="openSpoilageAct(record)"
              >
                Acta A4
              </button>
            </div>
          </div>
        </div>
        <div
          v-else-if="spoilageSearched && !spoilageLoading"
          data-testid="machine-spoilage-empty"
          style="margin-top: 8px; font-size: 12px; color: var(--color-ink-muted);"
        >
          Esta máquina no tiene actas de merma registradas.
        </div>
      </section>

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
      <template v-else-if="hasMachineContext">
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

            <div
              v-if="taxonomyLabel(entry)"
              :data-testid="'machine-history-taxonomy-' + entry.id"
              style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive, 4px); padding: 6px 8px; margin-bottom: 6px; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 600; color: var(--color-slate); line-height: 1.45;"
            >
              {{ taxonomyLabel(entry) }}
            </div>

            <!-- Detalle ampliado read-only (T-TAXONOMY-18, EARS 04.1) -->
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              :data-testid="'btn-machine-history-detail-' + entry.id"
              :aria-expanded="Boolean(details[entry.id])"
              style="min-height: 44px; width: 100%; font-size: 12px; padding: 0 12px; margin-bottom: 6px; border-radius: var(--radius-interactive, 4px);"
              @click="toggleDetail(entry)"
            >
              {{ details[entry.id] ? 'Ocultar detalle' : 'Ver detalle del expediente' }}
            </button>

            <div
              v-if="detailLoading[entry.id]"
              :data-testid="'machine-history-detail-loading-' + entry.id"
              style="padding: 8px 0; font-size: 12px; color: var(--color-ink-muted);"
            >
              Cargando detalle del expediente...
            </div>

            <div
              v-else-if="detailErrors[entry.id]"
              :data-testid="'machine-history-detail-error-' + entry.id"
              style="background-color: var(--color-error-bg); border: 1px solid var(--color-error); border-radius: var(--radius-interactive, 4px); padding: 8px 10px; margin-bottom: 6px; font-size: 12px; color: var(--color-error); line-height: 1.4;"
            >
              ⚠️ {{ detailErrors[entry.id] }}
              <div style="margin-top: 8px;">
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary"
                  :data-testid="'btn-retry-machine-history-detail-' + entry.id"
                  style="min-height: 44px; font-size: 12px; padding: 0 12px; border-radius: var(--radius-interactive, 4px);"
                  @click="fetchDetail(entry)"
                >
                  Reintentar
                </button>
              </div>
            </div>

            <div
              v-else-if="details[entry.id]"
              :data-testid="'machine-history-detail-' + entry.id"
              style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive, 4px); padding: 10px; margin-bottom: 6px; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-slate); line-height: 1.5;"
            >
              <div v-if="details[entry.id].resolution.diagnosis">
                <span style="font-weight: 700;">Diagnóstico:</span>
                {{ details[entry.id].resolution.diagnosis }}
              </div>
              <div v-if="details[entry.id].resolution.corrective_action" style="margin-top: 6px;">
                <span style="font-weight: 700;">Acción correctiva:</span>
                {{ details[entry.id].resolution.corrective_action }}
              </div>
              <div v-if="!details[entry.id].resolution.diagnosis && !details[entry.id].resolution.corrective_action" style="color: var(--color-ink-muted);">
                Este expediente no conserva diagnóstico ni acción correctiva.
              </div>

              <div v-if="details[entry.id].previous_classifications && details[entry.id].previous_classifications.length" style="margin-top: 10px;">
                <div style="font-weight: 700; color: var(--color-slate);">
                  Cierres anteriores preservados
                </div>
                <ul style="margin: 6px 0 0; padding-left: 18px;">
                  <li
                    v-for="(classification, index) in details[entry.id].previous_classifications"
                    :key="index"
                    style="margin-bottom: 4px;"
                  >
                    {{ previousClassificationLabel(classification) }}
                    <span v-if="classification.preserved_at" style="color: var(--color-ink-muted);">
                      · {{ formatDateTime(classification.preserved_at) }}
                    </span>
                  </li>
                </ul>
              </div>
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

      <div
        v-else
        data-testid="machine-history-no-machine"
        style="padding: 16px 0 4px; text-align: center; font-size: 13px; color: var(--color-slate); line-height: 1.45;"
      >
        Selecciona una avería de tu ruta o busca un acta por código de máquina.
      </div>

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
    hasMachineContext() {
      const info = this.machineInfo || this.machine;
      return Boolean(info && info.id);
    },
    spoilageMachineLabel() {
      const machine = this.spoilageMachine || {};
      return [machine.machine_code, machine.machine_type].filter(Boolean).join(' · ') || 'Máquina consultada';
    },
    /**
     * Header subtitle with the machine identity (falls back to the prop payload
     * while loading or if the backend omits the machine block).
     * @returns {string}
     */
    machineSubtitle() {
      const info = this.machineInfo || this.machine;
      if (!info) return 'Consulta de actas APPCC por código';
      const parts = [];
      if (info.code) parts.push(String(info.code));
      if (info.model) parts.push(String(info.model));
      return parts.join(' · ');
    }
  }
};
