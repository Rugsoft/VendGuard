/**
 * VendGuard - QrLabelModal Component (QrLabelModal.js)
 * 
 * Diálogo modal para la emisión, personalización, previsualización e impresión
 * de la etiqueta adhesiva con código QR para una máquina (RF-01, RF-02 / T-QR-14).
 * 
 * Requisitos cubiertos:
 * - EARS 1.1: Genera el código QR codificando sede y código unívoco de máquina.
 * - EARS 1.2: Muestra datos de máquina y campo editable para el teléfono de asistencia.
 * - EARS 1.3: Casilla de verificación opcional para actualizar el teléfono maestro de la sede.
 * - EARS 1.4: Previsualización en tiempo real del SVG vectorial completo (400x600 px).
 * - EARS 2.1: Impresión directa individual mediante window.print().
 * - EARS 2.3: Descarga vectorial SVG nativa descargable como archivo para imprenta.
 * 
 * Respeta el Dogma Vanilla y utiliza ModalDialog.js.
 */

import { api } from '../api.js';
import { ModalDialog } from './ModalDialog.js';

export const QrLabelModal = {
  name: 'QrLabelModal',
  components: {
    ModalDialog
  },
  props: {
    modelValue: {
      type: Boolean,
      default: false
    },
    machine: {
      type: Object,
      default: null
    },
    machineId: {
      type: [Number, String],
      default: null
    }
  },
  emits: ['update:modelValue', 'close', 'updated'],
  data() {
    return {
      loading: false,
      svgContent: '',
      supportPhone: '',
      updateLocationPhone: false,
      machineData: null,
      locationData: null,
      errorMessage: '',
      isApplyingPhone: false,
      saveSuccessNotice: false
    };
  },
  computed: {
    effectiveMachineId() {
      if (this.machineId) return Number(this.machineId);
      if (this.machine && this.machine.id) return Number(this.machine.id);
      if (this.machine && this.machine.machine_id) return Number(this.machine.machine_id);
      return null;
    },
    machineCodeDisplay() {
      return this.machineData?.code || this.machine?.machine_code || this.machine?.code || '';
    },
    modalSubtitle() {
      if (!this.machineCodeDisplay) return 'Configuración de Etiqueta Adhesiva';
      return `Máquina [${this.machineCodeDisplay}] · ${this.locationData?.name || ''}`;
    }
  },
  watch: {
    modelValue(newVal) {
      if (newVal && this.effectiveMachineId) {
        this.fetchLabelData();
      } else if (!newVal) {
        this.resetState();
      }
    },
    effectiveMachineId(newVal) {
      if (this.modelValue && newVal) {
        this.fetchLabelData();
      }
    }
  },
  mounted() {
    if (this.modelValue && this.effectiveMachineId) {
      this.fetchLabelData();
    }
  },
  methods: {
    resetState() {
      this.svgContent = '';
      this.supportPhone = '';
      this.updateLocationPhone = false;
      this.machineData = null;
      this.locationData = null;
      this.errorMessage = '';
      this.saveSuccessNotice = false;
    },

    /**
     * Recupera la etiqueta y su contenido SVG desde el backend (RF-01)
     */
    async fetchLabelData(phoneOverride = null) {
      if (!this.effectiveMachineId) return;

      this.loading = true;
      this.errorMessage = '';

      try {
        const options = {};
        if (phoneOverride !== null) {
          options.phone = phoneOverride;
        } else if (this.supportPhone) {
          options.phone = this.supportPhone;
        }

        if (this.updateLocationPhone) {
          options.update_location_phone = 1;
        }

        const res = await api.qr.getMachineLabel(this.effectiveMachineId, options);
        const data = res.data || res;

        this.svgContent = data.svg_content || '';
        this.machineData = data.machine || null;
        this.locationData = data.location || null;
        if (!this.supportPhone && data.support_phone) {
          this.supportPhone = data.support_phone;
        }
      } catch (err) {
        this.errorMessage = err.message || 'Error al generar la etiqueta con código QR.';
      } finally {
        this.loading = false;
      }
    },

    /**
     * Actualiza la previsualización al modificar el teléfono de asistencia (EARS 1.2, 1.3)
     */
    async applyPhoneCustomization() {
      this.isApplyingPhone = true;
      this.saveSuccessNotice = false;
      try {
        await this.fetchLabelData(this.supportPhone.trim());
        if (this.updateLocationPhone) {
          this.saveSuccessNotice = true;
          this.$emit('updated', {
            locationId: this.locationData?.id,
            phone: this.supportPhone.trim()
          });
          setTimeout(() => {
            this.saveSuccessNotice = false;
          }, 4000);
        }
      } finally {
        this.isApplyingPhone = false;
      }
    },

    /**
     * Descarga directa del archivo vectorial SVG para imprenta (EARS 2.3)
     */
    downloadSvg() {
      if (!this.svgContent) return;

      const code = this.machineCodeDisplay || 'MAQUINA';
      const filename = `etiqueta-${code}.svg`;

      const blob = new Blob([this.svgContent], { type: 'image/svg+xml;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
    },

    /**
     * Lanza la ventana de impresión para la etiqueta adhesiva (EARS 2.1)
     */
    printSingleLabel() {
      if (!this.svgContent) return;

      const printWindow = window.open('', '_blank', 'width=600,height=800');
      if (!printWindow) {
        alert('Por favor, permite ventanas emergentes para imprimir la etiqueta.');
        return;
      }

      printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset="utf-8">
          <title>Etiqueta QR - ${this.machineCodeDisplay}</title>
          <style>
            @page {
              size: auto;
              margin: 10mm;
            }
            body {
              margin: 0;
              padding: 0;
              display: flex;
              justify-content: center;
              align-items: center;
              min-height: 100vh;
              background-color: #ffffff;
            }
            .label-wrapper {
              width: 100%;
              max-width: 90mm;
              margin: auto;
              text-align: center;
            }
            svg {
              width: 100%;
              height: auto;
              display: block;
            }
          </style>
        </head>
        <body>
          <div class="label-wrapper">
            ${this.svgContent}
          </div>
          <script>
            window.onload = function() {
              window.focus();
              window.print();
              setTimeout(function() { window.close(); }, 500);
            };
          </script>
        </body>
        </html>
      `);
      printWindow.document.close();
    },

    closeModal() {
      this.$emit('update:modelValue', false);
      this.$emit('close');
    }
  },
  template: `
    <ModalDialog
      :model-value="modelValue"
      title="🏷️ Etiqueta con Código QR"
      :subtitle="modalSubtitle"
      size="lg"
      @update:model-value="$emit('update:modelValue', $event)"
      @close="closeModal"
    >
      <div v-if="loading && !svgContent" class="qr-card-center" data-testid="modal-loading">
        <div class="qr-spinner"></div>
        <p class="text-muted">Generando matriz vectorial y renderizando etiqueta...</p>
      </div>

      <div v-else-if="errorMessage" class="alert-error" data-testid="modal-error">
        {{ errorMessage }}
      </div>

      <div v-else class="qr-modal-layout" data-testid="modal-content">
        <!-- Columna Izquierda: Previsualización SVG en Tiempo Real (EARS 1.4) -->
        <div class="qr-modal-preview-col" data-testid="svg-preview-container">
          <div v-if="svgContent" v-html="svgContent" class="qr-svg-holder"></div>
        </div>

        <!-- Columna Derecha: Configuración, Teléfono y Acciones -->
        <div class="qr-modal-controls-col">
          <!-- Metadatos de la Máquina (EARS 1.2) -->
          <div class="qr-meta-box">
            <div class="qr-meta-row">
              <span class="qr-meta-k">Código:</span>
              <span class="qr-meta-v font-mono">{{ machineData?.code }}</span>
            </div>
            <div class="qr-meta-row">
              <span class="qr-meta-k">Modelo:</span>
              <span class="qr-meta-v">{{ machineData?.model }}</span>
            </div>
            <div class="qr-meta-row">
              <span class="qr-meta-k">Tipo:</span>
              <span class="qr-meta-v">{{ machineData?.machine_type }}</span>
            </div>
            <div class="qr-meta-row">
              <span class="qr-meta-k">Sede:</span>
              <span class="qr-meta-v">{{ locationData?.name }}</span>
            </div>
            <div class="qr-meta-row">
              <span class="qr-meta-k">Ubicación:</span>
              <span class="qr-meta-v">{{ machineData?.floor_wing }}</span>
            </div>
          </div>

          <!-- Personalización del Teléfono de Asistencia (EARS 1.2, 1.3) -->
          <div class="form-group" style="margin-bottom: 8px;">
            <label class="form-label" for="qr-phone-input">
              Teléfono de Asistencia Técnica en la Pegatina:
            </label>
            <div style="display: flex; gap: 8px;">
              <input
                id="qr-phone-input"
                v-model="supportPhone"
                type="tel"
                class="form-control"
                placeholder="600 000 000"
                data-testid="phone-input"
              />
              <button
                type="button"
                class="vg-btn vg-btn-secondary"
                @click="applyPhoneCustomization"
                :disabled="isApplyingPhone || !supportPhone.trim()"
                data-testid="btn-apply-phone"
              >
                {{ isApplyingPhone ? '...' : 'Actualizar' }}
              </button>
            </div>
          </div>

          <!-- Casilla de Verificación para Persistencia Maestra en Sede (EARS 1.3) -->
          <div class="form-check" style="margin-bottom: 12px;">
            <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 13px; cursor: pointer; color: var(--color-slate, #2c333f);">
              <input
                type="checkbox"
                v-model="updateLocationPhone"
                data-testid="update-location-checkbox"
                style="margin-top: 2px;"
              />
              <span>Actualizar también como teléfono predeterminado de la sede</span>
            </label>
          </div>

          <div v-if="saveSuccessNotice" class="alert-success" style="font-size: 12px; padding: 6px 10px;" data-testid="save-success">
            ✓ Teléfono maestro de la sede actualizado correctamente en la base de datos.
          </div>

          <!-- Botones de Acción: Impresión y Descarga SVG (EARS 2.1, 2.3) -->
          <div style="margin-top: auto; display: flex; flex-direction: column; gap: 10px;">
            <button
              type="button"
              class="vg-btn vg-btn-primary"
              style="width: 100%; height: 42px; font-size: 14px; font-weight: 600;"
              @click="printSingleLabel"
              data-testid="btn-print-single"
            >
              🖨️ Imprimir Etiqueta
            </button>

            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              style="width: 100%; height: 38px; font-size: 13px; font-weight: 600;"
              @click="downloadSvg"
              data-testid="btn-download-svg"
            >
              💾 Descargar Archivo Vectorial (.SVG)
            </button>
          </div>
        </div>
      </div>
    </ModalDialog>
  `
};

export default QrLabelModal;
