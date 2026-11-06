/**
 * VendGuard - SanitarySpoilageOfficialActModal (SanitarySpoilageOfficialActModal.js)
 *
 * Acta Oficial de Destrucción de Género Perecedero en formato A4 (Módulo 29,
 * RF-05.1, C4, QA 3, Art. V.4).
 *
 * Qué resuelve:
 * 1. Carga el documento normalizado que emite el servidor y lo presenta como una
 *    hoja A4 lista para imprimir con `window.print()`.
 * 2. No contiene ningún campo económico: ni coste estimado, ni autor del ajuste,
 *    ni motivo del ajuste. El servidor tampoco los envía (Art. V.4).
 * 3. La fotografía de evidencia se incrusta en el papel, porque el acta debe
 *    acreditar la retirada o el interior vacío ante Sanidad (Art. V.5).
 * 4. Las reglas de impresión se inyectan con el componente para que la hoja
 *    salga limpia sin depender de una hoja de estilos global.
 *
 * Dogma Vanilla: Vue 3 ESM puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documento y mensajes en español.
 */

import { api } from '../api.js';
import { ModalDialog } from './ModalDialog.js';

const PRINT_STYLE_ID = 'vg-spoilage-official-act-print-styles';
const EMPTY_BATCH_CODES = 'SIN_STOCK_AFECTADO';
const EMPTY_BATCH_LABEL = 'Sin stock afectado';

const PRINT_STYLES = `
.vg-spoilage-act-sheet {
  background-color: var(--color-surface-card);
  color: var(--color-ink);
  max-width: 180mm;
  margin: 0 auto;
  padding: 8mm;
  font-family: Inter, Arial, sans-serif;
  font-size: 12px;
  line-height: 1.45;
}
.vg-spoilage-act-sheet__eyebrow {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--color-primary);
  margin-bottom: 4px;
}
.vg-spoilage-act-sheet__title {
  font-size: 17px;
  font-weight: 700;
  margin: 0 0 4px 0;
  color: var(--color-ink);
}
.vg-spoilage-act-sheet__subtitle {
  font-size: 12px;
  color: var(--color-ink-secondary);
  margin: 0 0 12px 0;
}
.vg-spoilage-act-sheet__meta {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  border: 1px solid var(--color-hairline);
  border-radius: 4px;
  padding: 8px 10px;
  margin-bottom: 12px;
}
.vg-spoilage-act-sheet__meta-label {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-ink-muted);
}
.vg-spoilage-act-sheet__meta-value {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-ink);
  word-break: break-word;
}
.vg-spoilage-act-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 12px;
}
.vg-spoilage-act-table th,
.vg-spoilage-act-table td {
  border: 1px solid var(--color-hairline);
  padding: 6px 8px;
  vertical-align: top;
  text-align: left;
}
.vg-spoilage-act-table th {
  width: 34%;
  background-color: var(--color-canvas);
  font-size: 11px;
  font-weight: 700;
  color: var(--color-slate);
}
.vg-spoilage-act-table td {
  font-size: 12px;
  color: var(--color-ink);
}
.vg-spoilage-act-sheet__section-title {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-slate);
  margin: 14px 0 6px 0;
}
.vg-spoilage-act-sheet__notes {
  border: 1px solid var(--color-hairline);
  border-radius: 4px;
  padding: 8px 10px;
  font-size: 12px;
  line-height: 1.5;
  white-space: pre-wrap;
  margin-bottom: 12px;
}
.vg-spoilage-act-sheet__photo {
  display: block;
  max-width: 90mm;
  width: 100%;
  height: auto;
  border: 1px solid var(--color-hairline);
  border-radius: 4px;
  margin-bottom: 12px;
}
.vg-spoilage-act-sheet__legal {
  border-top: 1px solid var(--color-hairline);
  padding-top: 8px;
  font-size: 10px;
  line-height: 1.45;
  color: var(--color-ink-secondary);
  margin-top: 12px;
}
.vg-spoilage-act-sheet__footer {
  margin-top: 10px;
  font-size: 9px;
  color: var(--color-ink-muted);
  text-align: right;
}
@media print {
  @page {
    size: A4;
    margin: 12mm;
  }
  body > *:not(.vg-modal-backdrop:has(.vg-spoilage-act-sheet)) {
    display: none !important;
  }
  .vg-modal-backdrop:has(.vg-spoilage-act-sheet) {
    position: static !important;
    display: block !important;
    padding: 0 !important;
    background: transparent !important;
    backdrop-filter: none !important;
  }
  .vg-modal-backdrop:has(.vg-spoilage-act-sheet) .vg-modal {
    max-width: none !important;
    max-height: none !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    overflow: visible !important;
  }
  .vg-modal-backdrop:has(.vg-spoilage-act-sheet) .vg-modal > div:first-child,
  .vg-modal-backdrop:has(.vg-spoilage-act-sheet) .vg-modal > div:last-child {
    display: none !important;
  }
  .vg-modal-backdrop:has(.vg-spoilage-act-sheet) .vg-modal > div:nth-child(2) {
    padding: 0 !important;
    overflow: visible !important;
  }
  .vg-spoilage-act-sheet {
    max-width: none !important;
    width: auto !important;
    margin: 0 !important;
    padding: 0 !important;
  }
  .vg-spoilage-act-no-print {
    display: none !important;
  }
}
`;

export const SanitarySpoilageOfficialActModal = {
  name: 'SanitarySpoilageOfficialActModal',
  components: {
    ModalDialog
  },
  props: {
    modelValue: {
      type: Boolean,
      default: false
    },
    recordId: {
      type: [Number, String],
      default: null
    },
    act: {
      type: Object,
      default: null
    }
  },
  emits: ['update:modelValue', 'close'],
  data() {
    return {
      loadedAct: null,
      isLoading: false,
      loadError: ''
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
    documentData() {
      return this.loadedAct || this.act || null;
    },
    recordCode() {
      return this.documentData?.record_code || 'Acta de merma';
    },
    issuedAtLabel() {
      return this.formatDateTime(this.documentData?.issued_at);
    },
    machineLabel() {
      const machine = this.documentData?.machine || {};
      return [machine.code, machine.model].filter(Boolean).join(' · ') || '—';
    },
    technicianLabel() {
      const technician = this.documentData?.technician || {};
      const name = technician.name || '—';
      return technician.operator_code ? `${name} (${technician.operator_code})` : name;
    },
    registeredByLabel() {
      return this.documentData?.registered_by?.name || '—';
    },
    unitsLabel() {
      const units = Number(this.documentData?.discarded_units_count ?? 0);
      if (units === 0) {
        return '0 unidades · Máquina verificada sin stock afectado';
      }
      return `${units} ${units === 1 ? 'unidad destruida' : 'unidades destruidas'}`;
    },
    batchCodesLabel() {
      const raw = String(this.documentData?.affected_batch_codes || '').trim();
      if (raw === '') {
        return '—';
      }
      return raw.toUpperCase() === EMPTY_BATCH_CODES ? EMPTY_BATCH_LABEL : raw;
    },
    isPhotoAvailable() {
      const url = String(this.documentData?.evidence_photo_url || '').trim();
      return url !== '';
    },
    photoUrl() {
      return String(this.documentData?.evidence_photo_url || '').trim();
    }
  },
  watch: {
    modelValue(open) {
      if (open) {
        this.load();
      }
    },
    recordId() {
      if (this.modelValue) {
        this.load();
      }
    }
  },
  mounted() {
    this.ensurePrintStyles();
  },
  methods: {
    close() {
      this.$emit('update:modelValue', false);
      this.$emit('close');
    },

    ensurePrintStyles() {
      if (typeof document === 'undefined') {
        return;
      }
      if (document.getElementById(PRINT_STYLE_ID)) {
        return;
      }
      const style = document.createElement('style');
      style.id = PRINT_STYLE_ID;
      style.textContent = PRINT_STYLES;
      document.head.appendChild(style);
    },

    async load() {
      if (this.act && (this.recordId === null || this.recordId === undefined || this.recordId === '')) {
        this.loadedAct = this.act;
        this.loadError = '';
        return;
      }

      const id = Number(this.recordId);
      if (!Number.isInteger(id) || id <= 0) {
        this.loadedAct = this.act || null;
        this.loadError = this.loadedAct ? '' : 'No se pudo identificar el acta oficial.';
        return;
      }

      this.isLoading = true;
      this.loadError = '';

      try {
        const response = await api.technician.getSanitarySpoilageOfficialAct(id);
        this.loadedAct = response?.data || response || null;
      } catch (error) {
        this.loadedAct = null;
        this.loadError = error?.message || 'No se pudo cargar el Acta Oficial de Merma.';
      } finally {
        this.isLoading = false;
      }
    },

    print() {
      if (typeof window !== 'undefined' && typeof window.print === 'function') {
        window.print();
      }
    },

    formatDateTime(value) {
      if (!value) {
        return '—';
      }
      // PostgreSQL serializes TIMESTAMPTZ offsets as '+00'; Date needs '+00:00'.
      const normalized = String(value)
        .trim()
        .replace(' ', 'T')
        .replace(/([+-]\d{2})$/, '$1:00');
      const parsed = new Date(normalized);
      if (Number.isNaN(parsed.getTime())) {
        return String(value);
      }
      return parsed.toLocaleString('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
    },

    formatTemperature(value) {
      const numeric = Number(value);
      if (!Number.isFinite(numeric)) {
        return '—';
      }
      return `${numeric.toFixed(1).replace('.', ',')} °C`;
    }
  },
  template: `
    <ModalDialog
      v-model="isOpen"
      title="Acta Oficial de Merma APPCC"
      :subtitle="recordCode"
      size="lg"
      @close="close"
    >
      <div class="vg-spoilage-act-no-print" style="display: flex; justify-content: flex-end; gap: 8px; margin-bottom: 12px;">
        <button type="button" class="vg-btn vg-btn-secondary" @click="close">Cerrar</button>
        <button type="button" class="vg-btn vg-btn-primary" :disabled="isLoading || !documentData" @click="print">
          🖨️ Imprimir Acta A4
        </button>
      </div>

      <div v-if="isLoading" role="status" style="font-family: var(--font-body); font-size: 13px; color: var(--color-ink-muted); padding: 12px 0;">
        ⏳ Cargando el acta oficial...
      </div>

      <div
        v-else-if="loadError"
        role="alert"
        style="background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); padding: 10px 12px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 13px; line-height: 1.45;"
      >
        {{ loadError }}
        <div style="margin-top: 10px;">
          <button type="button" class="vg-btn vg-btn-secondary vg-btn-sm" @click="load">Reintentar</button>
        </div>
      </div>

      <article v-else-if="documentData" class="vg-spoilage-act-sheet" data-testid="spoilage-official-act">
        <div class="vg-spoilage-act-sheet__eyebrow">Registro APPCC / HACCP · VendGuard</div>
        <h1 class="vg-spoilage-act-sheet__title">{{ documentData.document_title || 'ACTA OFICIAL DE DESTRUCCIÓN DE GÉNERO PERECEDERO' }}</h1>
        <p class="vg-spoilage-act-sheet__subtitle">
          Documento sanitario oficial de retirada y destrucción del género perecedero expuesto.
        </p>

        <div class="vg-spoilage-act-sheet__meta">
          <div>
            <div class="vg-spoilage-act-sheet__meta-label">Código de acta</div>
            <div class="vg-spoilage-act-sheet__meta-value">{{ documentData.record_code || '—' }}</div>
          </div>
          <div>
            <div class="vg-spoilage-act-sheet__meta-label">Certificado higiénico</div>
            <div class="vg-spoilage-act-sheet__meta-value">{{ documentData.certificate_code || '—' }}</div>
          </div>
          <div>
            <div class="vg-spoilage-act-sheet__meta-label">Fecha de emisión</div>
            <div class="vg-spoilage-act-sheet__meta-value">{{ issuedAtLabel }}</div>
          </div>
        </div>

        <table class="vg-spoilage-act-table">
          <tbody>
            <tr>
              <th>Sede</th>
              <td>{{ documentData.machine?.location_name || '—' }}</td>
            </tr>
            <tr>
              <th>Máquina</th>
              <td>{{ machineLabel }}</td>
            </tr>
            <tr>
              <th>Técnico certificador in situ</th>
              <td>{{ technicianLabel }}</td>
            </tr>
            <tr>
              <th>Registrado por</th>
              <td>{{ registeredByLabel }}</td>
            </tr>
            <tr>
              <th>Origen de la cuarentena</th>
              <td>{{ documentData.quarantine_origin_label || documentData.quarantine_origin || '—' }}</td>
            </tr>
            <tr>
              <th>Avería / orden vinculada</th>
              <td>
                <span v-if="documentData.incident_id">Avería #{{ documentData.incident_id }}</span>
                <span v-if="documentData.incident_id && documentData.maintenance_order_id"> · </span>
                <span v-if="documentData.maintenance_order_id">Orden preventiva #{{ documentData.maintenance_order_id }}</span>
                <span v-if="!documentData.incident_id && !documentData.maintenance_order_id">Sin vínculo documental</span>
              </td>
            </tr>
            <tr>
              <th>Temperatura de cabina al retirar</th>
              <td>{{ formatTemperature(documentData.cabin_temp_at_removal) }}</td>
            </tr>
            <tr>
              <th>Temperatura estabilizada de reapertura</th>
              <td>{{ formatTemperature(documentData.restored_stabilized_temp_c) }}</td>
            </tr>
            <tr>
              <th>Producto destruido</th>
              <td>{{ unitsLabel }}</td>
            </tr>
            <tr>
              <th>Lotes afectados</th>
              <td>{{ batchCodesLabel }}</td>
            </tr>
            <tr>
              <th>Método de destrucción</th>
              <td>{{ documentData.disposal_method_label || documentData.disposal_method || '—' }}</td>
            </tr>
          </tbody>
        </table>

        <div class="vg-spoilage-act-sheet__section-title">Observaciones de higienización</div>
        <div class="vg-spoilage-act-sheet__notes">{{ documentData.sanitary_notes || '—' }}</div>

        <div class="vg-spoilage-act-sheet__section-title">Fotografía de evidencia</div>
        <img
          v-if="isPhotoAvailable"
          class="vg-spoilage-act-sheet__photo"
          :src="photoUrl"
          alt="Fotografía de evidencia del acta de merma"
        />
        <div v-else class="vg-spoilage-act-sheet__notes">Sin fotografía adjunta.</div>

        <div class="vg-spoilage-act-sheet__legal">
          {{ documentData.legal_notice || 'Documento sanitario oficial que acredita la retirada y destrucción del género perecedero expuesto, conforme al protocolo APPCC de VendGuard. No contiene información económica y se entrega a los servicios de inspección sanitaria y a la sede afectada.' }}
        </div>
        <div class="vg-spoilage-act-sheet__footer">
          Documento emitido por VendGuard · Registro APPCC de Actas de Merma
        </div>
      </article>
    </ModalDialog>
  `
};

export default SanitarySpoilageOfficialActModal;
