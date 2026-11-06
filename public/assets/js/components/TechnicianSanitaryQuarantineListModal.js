/**
 * VendGuard - TechnicianSanitaryQuarantineListModal
 *
 * Active sanitary quarantines available to any internal operator (C2, QA 3).
 * The list is machine-only: it never carries spoilage records or financial
 * fields. An open corrective incident keeps the lift action disabled (C1), and
 * the lift itself remains online-only (C3).
 *
 * Dogma Vanilla: Vue 3 ESM puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, interfaz y mensajes en español.
 */

import { api } from '../api.js';
import { ModalDialog } from './ModalDialog.js';

export const TechnicianSanitaryQuarantineListModal = {
  name: 'TechnicianSanitaryQuarantineListModal',
  components: {
    ModalDialog
  },
  props: {
    modelValue: {
      type: Boolean,
      default: false
    }
  },
  emits: ['update:modelValue', 'close', 'lift'],
  data() {
    return {
      machines: [],
      isLoading: false,
      errorMessage: '',
      isOnline: typeof navigator === 'undefined' ? true : navigator.onLine !== false
    };
  },
  computed: {
    isOpen: {
      get() {
        return this.modelValue;
      },
      set(value) {
        this.$emit('update:modelValue', value);
      }
    },
    hasMachines() {
      return this.machines.length > 0;
    }
  },
  watch: {
    modelValue(open) {
      if (open) {
        this.syncConnectivity();
        this.loadQuarantines();
      }
    }
  },
  methods: {
    close() {
      this.$emit('update:modelValue', false);
      this.$emit('close');
    },

    syncConnectivity() {
      this.isOnline = typeof navigator === 'undefined' ? true : navigator.onLine !== false;
    },

    async loadQuarantines() {
      this.syncConnectivity();
      if (!this.isOnline) {
        this.errorMessage = 'Sin conexión no es posible consultar ni levantar cuarentenas sanitarias (C3).';
        return;
      }

      this.isLoading = true;
      this.errorMessage = '';

      try {
        const response = await api.technician.getActiveSanitaryQuarantines();
        const data = response?.data || response || {};
        const rows = Array.isArray(data) ? data : (data.machines || []);
        this.machines = (Array.isArray(rows) ? rows : [])
          .filter((machine) => Number(machine?.machine_id ?? 0) > 0)
          .sort((left, right) => String(left.machine_code || '').localeCompare(String(right.machine_code || ''), 'es'));
      } catch (error) {
        this.machines = [];
        this.errorMessage = error?.message || 'No se pudieron cargar las cuarentenas sanitarias activas.';
      } finally {
        this.isLoading = false;
      }
    },

    requestLift(machine) {
      if (!machine || machine.has_open_incident === true) {
        return;
      }
      if (!this.isOnline) {
        this.errorMessage = 'Sin conexión no es posible emitir el acta ni levantar la cuarentena (C3).';
        return;
      }

      this.$emit('lift', machine);
    },

    typeLabel(machineType) {
      switch (String(machineType || '').toUpperCase()) {
        case 'PERISHABLE_FOOD':
          return 'Alimentos perecederos';
        case 'COMBO':
          return 'Máquina mixta';
        case 'COLD_DRINKS':
          return 'Bebidas frías';
        default:
          return 'No refrigerada';
      }
    },

    formatDate(value) {
      if (!value) {
        return '—';
      }
      const parsed = new Date(String(value).replace(' ', 'T'));
      if (Number.isNaN(parsed.getTime())) {
        return String(value);
      }
      return parsed.toLocaleDateString('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
      });
    }
  },
  template: `
    <ModalDialog
      v-model="isOpen"
      title="Cuarentenas sanitarias activas"
      subtitle="Cualquier técnico activo puede certificar la reapertura (C2). El acta oficial no incluye importes."
      size="lg"
      @close="close"
    >
      <div data-testid="technician-quarantine-list-modal">
        <div
          v-if="!isOnline"
          role="alert"
          style="background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); padding: 10px 12px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 13px; margin-bottom: 14px; line-height: 1.45;"
        >
          ⛔ Sin conexión: la consulta y el levantamiento exigen conexión activa con la central (C3).
        </div>

        <div v-if="isLoading" role="status" style="font-family: var(--font-body); font-size: 13px; color: var(--color-ink-muted); padding: 12px 0;">
          ⏳ Cargando cuarentenas sanitarias activas...
        </div>

        <div
          v-else-if="errorMessage"
          role="alert"
          style="background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); padding: 10px 12px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 13px; margin-bottom: 14px; line-height: 1.45;"
        >
          {{ errorMessage }}
          <div style="margin-top: 10px;">
            <button type="button" class="vg-btn vg-btn-secondary vg-btn-sm" @click="loadQuarantines">Reintentar</button>
          </div>
        </div>

        <p v-else-if="!hasMachines" class="vg-spoilage-empty" data-testid="technician-quarantine-empty">
          No hay máquinas en cuarentena sanitaria: el parque está liberado.
        </p>

        <div v-else class="vg-spoilage-quarantine-grid">
          <article
            v-for="machine in machines"
            :key="machine.machine_id"
            class="vg-spoilage-quarantine-card"
            :data-testid="'technician-quarantine-' + machine.machine_code"
          >
            <div class="vg-spoilage-quarantine-card__head">
              <div>
                <strong class="vg-spoilage-quarantine-card__code">{{ machine.machine_code }}</strong>
                <p class="vg-spoilage-muted">
                  {{ machine.machine_model }} · {{ machine.location_name }}
                </p>
              </div>
              <span class="vg-chip vg-chip-danger">CUARENTENA</span>
            </div>

            <dl class="vg-spoilage-quarantine-card__meta">
              <div>
                <dt>Tipología</dt>
                <dd>{{ typeLabel(machine.machine_type) }}</dd>
              </div>
              <div>
                <dt>Última inspección</dt>
                <dd>{{ formatDate(machine.last_sanitary_inspection_at) }}</dd>
              </div>
              <div>
                <dt>Próxima revisión</dt>
                <dd>{{ formatDate(machine.next_sanitary_inspection_due) }}</dd>
              </div>
            </dl>

            <div class="vg-spoilage-quarantine-card__footer">
              <span
                v-if="machine.has_open_incident"
                class="vg-chip vg-chip-warning"
                :data-testid="'technician-quarantine-open-incident-' + machine.machine_code"
              >
                🔒 Bloqueada por avería abierta {{ machine.open_incident_code || '' }}
              </span>
              <span v-else class="vg-spoilage-muted">
                Sin avería abierta: procede el acta de merma y el levantamiento.
              </span>

              <button
                v-if="!machine.has_open_incident"
                type="button"
                class="vg-btn vg-btn-sm vg-btn-primary"
                :disabled="!isOnline"
                @click="requestLift(machine)"
                :data-testid="'technician-spoilage-lift-' + machine.machine_code"
              >
                🧾 Emitir Acta y Levantar Cuarentena
              </button>
            </div>
          </article>
        </div>
      </div>

      <template #footer>
        <button type="button" class="vg-btn vg-btn-secondary" @click="close">
          Cerrar
        </button>
      </template>
    </ModalDialog>
  `
};
