/**
 * VendGuard - TechnicianReinspectionModal (TechnicianReinspectionModal.js)
 * 
 * Modal táctil móvil Vue 3 ESM para la Reinspección y Verificación de Levantamiento de Cuarentena (RF-PREV-04, RF-PREV-08).
 * 
 * Características:
 * 1. Mobile-first para smartphone con targets táctiles >= 44px (RNF-01).
 * 2. Comprobación térmica estricta [-5.0 °C, +25.0 °C] exigiendo <= 4.0 °C en perecederos para levantar cuarentena (Art. II).
 * 3. Alerta en tiempo real si la lectura térmica sigue fuera de rango (> 4.0 °C), bloqueando el levantamiento de cuarentena.
 * 4. Verificación de reparación correctiva y notas justificativas obligatorias.
 * 5. Invocación de POST /api/technician/preventive/orders/{id}/reinspect y emisión oficial de nuevo certificado.
 * 
 * Dogma Vanilla: Cero dependencias externas (Vue 3 ESM puro).
 * Dualismo Lingüístico: Código en inglés, interfaz y mensajes en español.
 */

import { api } from '../api.js';

export const TechnicianReinspectionModal = {
  name: 'TechnicianReinspectionModal',
  props: {
    modelValue: {
      type: Boolean,
      default: false
    },
    order: {
      type: Object,
      default: null
    }
  },
  emits: ['update:modelValue', 'close', 'reinspection-completed'],
  data() {
    return {
      temperatureInput: '',
      reinspectionNotes: '',
      isSubmitting: false,
      errorMessage: '',
      successMessage: '',
      reinspectionResult: null
    };
  },
  computed: {
    isPerishable() {
      return Boolean(
        this.order?.machine?.is_perishable ||
        this.order?.machine?.machine_type === 'PERISHABLE_FOOD'
      );
    },
    numericTemperature() {
      if (this.temperatureInput === '' || this.temperatureInput === null || this.temperatureInput === undefined) {
        return null;
      }
      return parseFloat(String(this.temperatureInput).replace(',', '.'));
    },
    isThermalCompliant() {
      if (this.numericTemperature === null || isNaN(this.numericTemperature)) {
        return false;
      }
      if (this.numericTemperature < -5.0 || this.numericTemperature > 25.0) {
        return false;
      }
      if (this.isPerishable) {
        return this.numericTemperature <= 4.0;
      }
      return true;
    },
    canSubmit() {
      if (this.numericTemperature === null || isNaN(this.numericTemperature)) {
        return false;
      }
      if (this.numericTemperature < -5.0 || this.numericTemperature > 25.0) {
        return false;
      }
      if (!this.reinspectionNotes.trim()) {
        return false;
      }
      return true;
    }
  },
  watch: {
    modelValue(val) {
      if (val) {
        this.temperatureInput = '';
        this.reinspectionNotes = '';
        this.errorMessage = '';
        this.successMessage = '';
        this.reinspectionResult = null;
      }
    }
  },
  methods: {
    close() {
      this.$emit('update:modelValue', false);
      this.$emit('close');
    },

    /**
     * Envía la reinspección sanitaria para levantar la cuarentena (RF-PREV-08)
     */
    async submitReinspection() {
      this.errorMessage = '';

      if (this.numericTemperature === null || isNaN(this.numericTemperature)) {
        this.errorMessage = 'Debe indicar la temperatura de comprobación.';
        return;
      }
      if (this.numericTemperature < -5.0 || this.numericTemperature > 25.0) {
        this.errorMessage = 'La temperatura debe encontrarse dentro del rango físico [-5.0 °C, +25.0 °C].';
        return;
      }

      if (this.isPerishable && this.numericTemperature > 4.0) {
        this.errorMessage = 'No es posible declarar CONFORME ni levantar la cuarentena si la temperatura supera los 4.0 °C en alimentos perecederos (Art. II Constitución).';
        return;
      }

      const notes = this.reinspectionNotes.trim();
      if (!notes) {
        this.errorMessage = 'Debe detallar la comprobación técnica realizada tras resolver la avería.';
        return;
      }

      this.isSubmitting = true;

      try {
        const payload = {
          temperature_measured: this.numericTemperature,
          notes: notes,
          items: [
            {
              item_code: 'THERMAL_STABILITY',
              status: this.isThermalCompliant ? 'PASS' : 'FAIL',
              observations: `Comprobación térmica oficial: ${this.numericTemperature} °C.`
            }
          ]
        };

        const res = await api.technician.reinspectPreventiveOrder(this.order.id, payload);
        const resData = res?.data || res || {};
        this.reinspectionResult = resData;

        this.$emit('reinspection-completed', resData);
      } catch (err) {
        this.errorMessage = err.message || 'Error al procesar la reinspección sanitaria.';
      } finally {
        this.isSubmitting = false;
      }
    }
  },
  template: `
    <div
      v-if="modelValue"
      style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); display: flex; align-items: flex-end; justify-content: center; z-index: 1060; padding: 0;"
      data-testid="modal-technician-reinspection"
    >
      <div style="background: #ffffff; width: 100%; max-width: 500px; max-height: 90vh; border-top-left-radius: 16px; border-top-right-radius: 16px; box-shadow: 0 -4px 20px rgba(0,0,0,0.2); display: flex; flex-direction: column; overflow: hidden; animation: vg-slide-up 0.25s ease;">
        
        <!-- Cabecera -->
        <div style="padding: 14px 18px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
          <div>
            <div style="display: flex; align-items: center; gap: 6px;">
              <span style="font-size: 18px;">🔄</span>
              <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #1e293b;">
                Reinspección Sanitaria (Levantamiento de Cuarentena)
              </h3>
            </div>
            <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
              {{ order?.order_code }} · Máquina <strong>{{ order?.machine?.code || ('#' + order?.machine_id) }}</strong>
            </div>
          </div>
          <button
            type="button"
            @click="close"
            style="min-width: 44px; min-height: 44px; background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b; display: flex; align-items: center; justify-content: center;"
            aria-label="Cerrar modal"
          >
            ✕
          </button>
        </div>

        <!-- Pantalla de Éxito tras levantar cuarentena -->
        <div v-if="reinspectionResult" style="padding: 24px 18px; overflow-y: auto; text-align: center; display: flex; flex-direction: column; gap: 14px;">
          <div style="font-size: 48px;">✅</div>
          <h3 style="margin: 0; font-size: 18px; color: #15803d;">
            ¡Cuarentena Levantada con Éxito!
          </h3>
          <p style="margin: 0; font-size: 13px; color: #166534;">
            La máquina ha superado la comprobación de régimen térmico y recupera el estado sanitario <strong>OK</strong>.
            <span v-if="reinspectionResult.certificate?.certificate_code">
              Certificado emitido: <strong>{{ reinspectionResult.certificate.certificate_code }}</strong>.
            </span>
          </p>
          <button
            type="button"
            class="vg-btn"
            style="width: 100%; min-height: 48px; background: #2560ff; color: #ffffff; font-weight: 700; font-size: 15px; border-radius: 6px; border: none; cursor: pointer; margin-top: 10px;"
            @click="close"
          >
            Aceptar y Continuar Ruta
          </button>
        </div>

        <!-- Formulario de Reinspección -->
        <div v-else style="padding: 16px 18px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 14px;">
          <div style="background: #f8fafc; border-left: 4px solid #2560ff; padding: 10px 12px; font-size: 12px; color: #334155; border-radius: 2px;">
            ℹ️ <strong>Condición de Desbloqueo (Art. II / Art. V.1):</strong> Debe verificarse que la temperatura está estabilizada a ≤ 4.0 °C tras la reparación del grupo de frío antes de reanudar la dispensación.
          </div>

          <div v-if="errorMessage" style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 10px 14px; border-radius: 6px; font-size: 13px;" data-testid="reinspect-error-msg">
            ⚠️ {{ errorMessage }}
          </div>

          <!-- Medición Térmica de Verificación -->
          <div style="background: #ffffff; border: 2px solid #e2e8f0; border-radius: 8px; padding: 14px; display: flex; flex-direction: column; gap: 10px;">
            <label style="font-size: 13px; font-weight: 700; color: #1e293b;">
              🌡️ Temperatura de Comprobación Oficial (°C) *
            </label>
            <div style="display: flex; gap: 10px; align-items: center;">
              <input
                type="number"
                step="0.1"
                min="-5.0"
                max="25.0"
                inputmode="decimal"
                v-model="temperatureInput"
                placeholder="Ej: 3.2"
                class="vg-input"
                style="flex: 1; height: 48px; font-size: 20px; font-weight: 800; text-align: center; border-radius: 6px; border: 2px solid #cbd5e1;"
                :style="{ borderColor: (numericTemperature !== null && !isThermalCompliant) ? '#ef4444' : '#cbd5e1' }"
                data-testid="input-reinspect-temp"
              />
              <span style="font-size: 18px; font-weight: 700; color: #475569;">°C</span>
            </div>

            <!-- Alerta si temperatura no es conforme -->
            <div
              v-if="numericTemperature !== null && isPerishable && numericTemperature > 4.0"
              style="background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: 8px 10px; border-radius: 4px; font-size: 12px;"
              data-testid="reinspect-temp-alert"
            >
              ⚠️ La temperatura ({{ numericTemperature }} °C) excede los 4.0 °C. No se puede levantar la cuarentena.
            </div>

            <div
              v-else-if="numericTemperature !== null && isThermalCompliant"
              style="background: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 8px 10px; border-radius: 4px; font-size: 12px;"
            >
              ✅ Temperatura conforme (≤ 4.0 °C). Régimen frigorífico apto para reanudar servicio.
            </div>
          </div>

          <!-- Justificación documental -->
          <div style="display: flex; flex-direction: column; gap: 6px;">
            <label style="font-size: 13px; font-weight: 600; color: #334155;">
              Comprobación Técnica Realizada *
            </label>
            <textarea
              v-model="reinspectionNotes"
              rows="3"
              class="vg-input"
              style="padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px;"
              placeholder="Describa la prueba de estabilidad térmica efectuada tras resolver el correctivo..."
              data-testid="input-reinspect-notes"
            ></textarea>
          </div>
        </div>

        <!-- Botones de Acción Móviles -->
        <div v-if="!reinspectionResult" style="padding: 12px 18px; border-top: 1px solid #e2e8f0; background: #f8fafc; display: flex; gap: 10px;">
          <button
            type="button"
            class="vg-btn"
            style="min-height: 48px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 0 16px; border-radius: 6px; font-weight: 600; font-size: 14px; cursor: pointer;"
            @click="close"
          >
            Cancelar
          </button>

          <button
            type="button"
            class="vg-btn"
            style="flex: 1; min-height: 48px; background: #16a34a; color: #ffffff; border: none; border-radius: 6px; font-weight: 700; font-size: 15px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;"
            :disabled="isSubmitting || !canSubmit || !isThermalCompliant"
            :style="{ opacity: (canSubmit && isThermalCompliant) ? 1 : 0.6, cursor: (canSubmit && isThermalCompliant) ? 'pointer' : 'not-allowed' }"
            @click="submitReinspection"
            data-testid="btn-submit-reinspection"
          >
            <span v-if="isSubmitting">⏳ Validando reinspección...</span>
            <span v-else>🏁 Levantar Cuarentena y Certificar</span>
          </button>
        </div>
      </div>
    </div>
  `
};
export default TechnicianReinspectionModal;
