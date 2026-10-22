/**
 * VendGuard - ServiceSheetModal (ServiceSheetModal.js)
 *
 * Albarán de intervención imprimible en A4 (Módulo 19: RF-04, RF-05, RD-10, RD-11,
 * RD-15, RD-17, RNF-03).
 *
 * Qué resuelve:
 * 1. Carga el documento por rol: el técnico asignado o firmante y la coordinación leen por
 *    su propia ruta (`/api/technician/…` o `/api/coordinator/…`); el servidor decide el
 *    acceso y el cliente no lo reinterpreta.
 * 2. Imprime el **documento congelado** de la hoja, no la proyección viva de la avería: una
 *    sede renombrada o una pieza recatalogada no alteran un papel ya firmado (RF-04.3).
 * 3. Cuando la avería no tiene hoja enseña la proyección viva con «Sin firma del
 *    responsable», sin inventar firmante (RF-04.4, RD-11).
 * 4. Marca una hoja histórica con la fecha de la intervención que documenta y permite elegir
 *    entre las visitas anteriores de la misma avería (RF-04.6, RF-05.5).
 * 5. No imprime costes, notas internas, SLA ni reclamaciones: el servidor no los manda y el
 *    componente no los pide (RF-04.2, Art. V.4).
 *
 * La impresión la hace el navegador con `window.print()` y la hoja `service-sheet.css`
 * (A4, oculta la interfaz, nunca parte el bloque de firma). Sin librerías (RNF-03).
 *
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias externas).
 */

import { api } from '../api.js';
import { ModalDialog } from './ModalDialog.js';

/** Etiqueta normativa del bloque de firma (Q8, RD-15). */
export const SERVICE_SHEET_LABEL = 'Firma manuscrita digitalizada';

/** Mención de una avería sin hoja firmada (RD-11). */
export const SERVICE_SHEET_NO_SIGNATURE = 'Sin firma del responsable';

/** Mención de una intervención sin sustitución de componentes (RF-04.1). */
export const SERVICE_SHEET_NO_PARTS = 'Sin sustitución de componentes';

/** Etiqueta del bloque cuando el técnico declaró que no había responsable. */
export const SERVICE_SHEET_ABSENCE_LABEL = 'Responsable ausente en la sede';

export const ServiceSheetModal = {
  name: 'ServiceSheetModal',
  components: {
    ModalDialog
  },
  props: {
    modelValue: {
      type: Boolean,
      default: false
    },
    incidentId: {
      type: [Number, String],
      default: null
    },
    /**
     * Rol del lector: `technician` lee por su ruta (asignado o firmante) y `coordinator`
     * por la de coordinación. El servidor es quien autoriza (RD-17).
     */
    audience: {
      type: String,
      default: 'technician'
    }
  },
  emits: ['update:modelValue', 'loaded', 'error'],
  data() {
    return {
      isLoading: false,
      loadError: '',
      data: null,
      selectedSheetId: null
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
    document() {
      return this.data?.document || null;
    },
    sheet() {
      return this.data?.sheet || null;
    },
    history() {
      return Array.isArray(this.data?.history) ? this.data.history : [];
    },
    hasSheet() {
      return Boolean(this.sheet);
    },
    isHistorical() {
      return Boolean(this.data) && this.data.current === false;
    },
    parts() {
      const parts = this.document?.parts;
      return Array.isArray(parts) ? parts : [];
    },
    partsLabel() {
      return this.parts.length > 0 ? '' : SERVICE_SHEET_NO_PARTS;
    },
    signatureLabel() {
      if (!this.hasSheet) {
        return SERVICE_SHEET_NO_SIGNATURE;
      }
      return this.sheet?.signed ? SERVICE_SHEET_LABEL : SERVICE_SHEET_ABSENCE_LABEL;
    },
    signatureImage() {
      if (!this.hasSheet || !this.sheet?.signed) {
        return '';
      }
      const png = this.sheet?.png_base64;
      if (!png) {
        return '';
      }
      return String(png).startsWith('data:') ? String(png) : `data:image/png;base64,${png}`;
    },
    signatureRecordedAt() {
      return this.formatDateTime(this.sheet?.recorded_at);
    },
    interventionDate() {
      return this.formatDateTime(this.sheet?.resolved_at || this.document?.resolved_at);
    }
  },
  watch: {
    modelValue(open) {
      if (open) {
        this.load();
      }
    },
    incidentId() {
      if (this.modelValue) {
        this.load();
      }
    }
  },
  methods: {
    /** Cliente del rol que lee: el servidor decide si tiene derecho (RD-17). */
    clientFor(audience = this.audience) {
      return audience === 'coordinator' ? api.coordinator : api.technician;
    },

    /**
     * Carga el albarán vigente o una hoja concreta del historial.
     *
     * @param {number|string|null} [sheetId=null]
     * @returns {Promise<Object|null>}
     */
    async load(sheetId = null) {
      if (!this.incidentId) {
        return null;
      }

      this.isLoading = true;
      this.loadError = '';

      try {
        const response = await this.clientFor().getServiceSheet(this.incidentId, sheetId);
        this.data = response?.data || response || null;
        this.selectedSheetId = this.data?.sheet?.id ?? null;
        this.$emit('loaded', this.data);
        return this.data;
      } catch (error) {
        this.data = null;
        this.loadError = error?.message || 'No se pudo cargar el albarán de servicio.';
        this.$emit('error', error);
        return null;
      } finally {
        this.isLoading = false;
      }
    },

    /** Pide una hoja del historial por su identificador (RF-05.5). */
    selectSheet(sheetId) {
      this.selectedSheetId = sheetId;
      return this.load(sheetId);
    },

    print() {
      if (typeof window !== 'undefined' && typeof window.print === 'function') {
        window.print();
      }
    },

    /**
     * Fecha legible para el papel. Acepta ISO con zona y la marca `Y-m-d H:i:s` de la base,
     * y devuelve cadena vacía cuando no hay valor utilizable.
     */
    formatDateTime(value) {
      if (value === null || value === undefined || value === '') {
        return '';
      }

      const normalized = String(value).replace(' ', 'T');
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
    }
  },
  template: `
    <ModalDialog
      v-model="isOpen"
      title="Albarán de intervención"
      :subtitle="document ? ('Ticket ' + document.ticket_code) : ''"
      size="lg"
    >
      <div class="vg-service-sheet" data-testid="service-sheet-modal">
        <div class="vg-service-sheet__toolbar vg-service-sheet__no-print">
          <label v-if="history.length > 1" class="vg-service-sheet__selector">
            <span>Visita documentada:</span>
            <select
              :value="selectedSheetId"
              data-testid="service-sheet-selector"
              @change="selectSheet(Number($event.target.value))"
            >
              <option v-for="entry in history" :key="entry.id" :value="entry.id">
                {{ formatDateTime(entry.resolved_at) }} · {{ entry.signed ? 'Firmada' : 'Sin firma' }}{{ entry.current ? ' · vigente' : '' }}
              </option>
            </select>
          </label>
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            data-testid="service-sheet-print"
            :disabled="isLoading || !document"
            @click="print"
          >
            🖨️ Imprimir / guardar como PDF
          </button>
        </div>

        <div v-if="isLoading" class="vg-service-sheet__feedback" role="status" data-testid="service-sheet-loading">
          Cargando el albarán de servicio…
        </div>

        <div v-else-if="loadError" class="vg-service-sheet__feedback vg-service-sheet__feedback--error" role="alert" data-testid="service-sheet-error">
          {{ loadError }}
        </div>

        <div v-else-if="isHistorical" class="vg-service-sheet__notice" role="note" data-testid="service-sheet-historical">
          ⚠️ Albarán histórico: documenta la intervención del {{ interventionDate }}. No es el estado actual de la avería.
        </div>

        <article v-if="document" class="vg-service-sheet__paper" data-testid="service-sheet-paper">
          <header class="vg-service-sheet__header">
            <div>
              <h1 class="vg-service-sheet__title">Albarán de intervención</h1>
              <p class="vg-service-sheet__ticket">Ticket {{ document.ticket_code }}</p>
            </div>
            <div class="vg-service-sheet__meta">
              <p>Resolución: {{ formatDateTime(document.resolved_at) }}</p>
              <p>Intervención: {{ interventionDate }}</p>
            </div>
          </header>

          <section class="vg-service-sheet__section">
            <h2>Ubicación del servicio</h2>
            <p class="vg-service-sheet__strong">{{ document.location?.name || '' }}</p>
            <p>{{ document.location?.address || '' }}</p>
          </section>

          <section class="vg-service-sheet__section">
            <h2>Máquina atendida</h2>
            <p class="vg-service-sheet__strong">{{ document.machine?.code || '' }} · {{ document.machine?.model || '' }}</p>
          </section>

          <section class="vg-service-sheet__section">
            <h2>Técnico que resolvió</h2>
            <p>{{ document.technician?.name || '' }}</p>
          </section>

          <section class="vg-service-sheet__section">
            <h2>Diagnóstico</h2>
            <p>{{ document.diagnosis || '' }}</p>
          </section>

          <section class="vg-service-sheet__section">
            <h2>Solución aplicada</h2>
            <p>{{ document.action || '' }}</p>
          </section>

          <section class="vg-service-sheet__section">
            <h2>Componentes sustituidos</h2>
            <table v-if="parts.length > 0" class="vg-service-sheet__parts">
              <thead>
                <tr>
                  <th>Componente</th>
                  <th class="vg-service-sheet__qty">Cantidad</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(part, index) in parts" :key="index">
                  <td>{{ part.name }}</td>
                  <td class="vg-service-sheet__qty">{{ part.quantity }}</td>
                </tr>
              </tbody>
            </table>
            <p v-else class="vg-service-sheet__muted" data-testid="service-sheet-no-parts">{{ partsLabel }}</p>
          </section>

          <section class="vg-service-sheet__section vg-service-sheet__signature" data-testid="service-sheet-signature">
            <h2>{{ signatureLabel }}</h2>

            <template v-if="hasSheet && sheet.signed">
              <img
                v-if="signatureImage"
                class="vg-service-sheet__stroke"
                :src="signatureImage"
                alt="Trazo de la firma del responsable"
                data-testid="service-sheet-stroke"
              />
              <p class="vg-service-sheet__strong">{{ sheet.signer_name }}</p>
              <p>Documento: {{ sheet.signer_document }}</p>
              <p class="vg-service-sheet__muted">Firmado el {{ signatureRecordedAt }}</p>
            </template>

            <template v-else-if="hasSheet">
              <p>{{ sheet.absence_reason || '' }}</p>
              <p class="vg-service-sheet__muted">Declarado por el técnico el {{ signatureRecordedAt }}</p>
            </template>

            <p v-else class="vg-service-sheet__muted" data-testid="service-sheet-unsigned">
              Esta intervención se cerró sin firma recogida.
            </p>
          </section>
        </article>
      </div>
    </ModalDialog>
  `
};

export default ServiceSheetModal;
