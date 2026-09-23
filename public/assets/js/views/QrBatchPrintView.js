/**
 * VendGuard - QrBatchPrintView (QrBatchPrintView.js)
 * 
 * Vista de impresión en lote para el Coordinador (RF-02, EARS 2.2 / T-QR-14).
 * Compone una hoja imprimible en cuadrícula para tamaño A4 con todas las máquinas activas
 * de una sede física, aplicando saltos de página automáticos para impedir que ninguna
 * pegatina quede cortada entre dos páginas.
 * 
 * Respeta el Dogma Vanilla y los estilos de qr-print.css.
 */

import { api } from '../api.js';

export const QrBatchPrintView = {
  name: 'QrBatchPrintView',
  props: {
    locationId: {
      type: [Number, String],
      required: true
    },
    locationName: {
      type: String,
      default: ''
    }
  },
  emits: ['close'],
  data() {
    return {
      loading: true,
      errorMessage: '',
      location: null,
      totalMachines: 0,
      items: []
    };
  },
  watch: {
    locationId: {
      immediate: true,
      handler(newVal) {
        if (newVal) {
          this.fetchBatchData();
        }
      }
    }
  },
  methods: {
    /**
     * Carga el conjunto completo de etiquetas SVG de la sede (EARS 2.2)
     */
    async fetchBatchData() {
      const locId = Number(this.locationId);
      if (!locId || locId <= 0) {
        this.errorMessage = 'Identificador de sede no válido.';
        this.loading = false;
        return;
      }

      this.loading = true;
      this.errorMessage = '';

      try {
        const res = await api.qr.getLocationBatch(locId);
        const data = res.data || res;

        this.location = data.location || null;
        this.totalMachines = data.total_machines || (data.items ? data.items.length : 0);
        this.items = data.items || [];
      } catch (err) {
        this.errorMessage = err.message || 'Error al obtener el lote de etiquetas de la sede.';
      } finally {
        this.loading = false;
      }
    },

    /**
     * Lanza la impresión de la hoja A4 completa (EARS 2.2)
     */
    triggerPrint() {
      if (typeof window !== 'undefined') {
        window.print();
      }
    },

    closeView() {
      this.$emit('close');
    }
  },
  template: `
    <div class="qr-batch-preview-container">
      <!-- Barra de Herramientas Superior (Oculta en Impresión) -->
      <div class="qr-batch-toolbar no-print" data-testid="batch-toolbar">
        <div class="qr-batch-info">
          <h2>📄 Lote de Etiquetas de Sede (A4)</h2>
          <p v-if="location">
            <strong>{{ location.name }}</strong> ({{ location.site_code }}) • {{ totalMachines }} máquinas activas
          </p>
        </div>

        <div class="qr-batch-actions">
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            @click="closeView"
            data-testid="btn-close-batch"
          >
            ← Volver al Panel
          </button>

          <button
            type="button"
            class="vg-btn vg-btn-primary"
            :disabled="loading || items.length === 0"
            @click="triggerPrint"
            data-testid="btn-print-batch"
          >
            🖨️ Imprimir Lote A4
          </button>
        </div>
      </div>

      <!-- Estado de Carga -->
      <div v-if="loading" class="qr-card qr-card-center no-print" data-testid="batch-loading">
        <div class="qr-spinner"></div>
        <p class="text-muted">Generando lote de etiquetas vectoriales para la sede...</p>
      </div>

      <!-- Mensaje de Error -->
      <div v-else-if="errorMessage" class="qr-card alert-error no-print" data-testid="batch-error">
        {{ errorMessage }}
      </div>

      <!-- Aviso si no hay máquinas activas -->
      <div v-else-if="items.length === 0" class="qr-card qr-card-center no-print" data-testid="batch-empty">
        <p>No se han encontrado máquinas activas en esta sede para generar etiquetas.</p>
      </div>

      <!-- Zona de Impresión A4 (Visible en Pantalla e Impresión) -->
      <div v-else class="qr-print-zone" data-testid="print-zone">
        <div class="qr-a4-sheet">
          <div class="qr-batch-grid" data-testid="batch-grid">
            <div
              v-for="item in items"
              :key="item.machine_id"
              class="qr-label-cell"
              :data-machine-code="item.code"
              v-html="item.svg_content"
            ></div>
          </div>
        </div>
      </div>
    </div>
  `
};

export default QrBatchPrintView;
