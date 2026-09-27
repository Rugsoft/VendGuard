/**
 * VendGuard - TechnicianChecklistModal (TechnicianChecklistModal.js)
 * 
 * Modal táctil móvil Vue 3 ESM para la cumplimentación del Checklist Digital Normativo in situ.
 * Requisitos: RF-PREV-03, RF-PREV-04, RNF-01, RNF-06, Constitución Art. II.
 * 
 * Características:
 * 1. Ergonomía táctil para smartphone (targets >= 44px, navegación ágil con una mano, RNF-01).
 * 2. Validación térmica estricta en máquinas refrigeradas con rango físico [-5.0 °C, +25.0 °C] (EARS 3.1).
 * 3. Alerta visual reactiva de rotura de frío (> 4.0 °C en perecederos) advirtiendo de cuarentena obligatoria (Art. II).
 * 4. Botón de aceleración operativa "⚡ Marcar Todos Conformes" para completar checklist en < 90 segundos (RNF-06).
 * 5. Control de severidades (PASS, WARN, FAIL) con obligación de observaciones en fallos o avisos.
 * 6. Emisión oficial de certificado ante conformidad o activación automática de cuarentena y correctivo ante fallo.
 * 
 * Dogma Vanilla: Cero dependencias externas (Vue 3 ESM puro).
 * Dualismo Lingüístico: Código en inglés, interfaz y mensajes en español.
 */

import { api } from '../api.js';

export const TechnicianChecklistModal = {
  name: 'TechnicianChecklistModal',
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
  emits: ['update:modelValue', 'close', 'inspection-completed'],
  data() {
    return {
      checklistData: null,
      temperatureInput: '',
      itemsState: {}, // { item_code: { status: 'PASS'|'WARN'|'FAIL', observations: '' } }
      generalNotes: '',
      isLoading: false,
      isSubmitting: false,
      errorMessage: '',
      completionResult: null
    };
  },
  computed: {
    isRefrigerated() {
      return Boolean(
        this.checklistData?.machine?.is_refrigerated ||
        this.checklistData?.machine?.is_perishable ||
        this.checklistData?.machine?.machine_type === 'PERISHABLE_FOOD' ||
        this.checklistData?.machine?.machine_type === 'COLD_DRINKS'
      );
    },
    isPerishable() {
      return Boolean(
        this.checklistData?.machine?.is_perishable ||
        this.checklistData?.machine?.machine_type === 'PERISHABLE_FOOD'
      );
    },
    numericTemperature() {
      if (this.temperatureInput === '' || this.temperatureInput === null || this.temperatureInput === undefined) {
        return null;
      }
      return parseFloat(String(this.temperatureInput).replace(',', '.'));
    },
    isThermalBroken() {
      if (!this.isPerishable || this.numericTemperature === null) {
        return false;
      }
      return this.numericTemperature > 4.0;
    },
    allAnswered() {
      const items = this.checklistData?.checklist_items || [];
      if (items.length === 0) return true;
      for (const item of items) {
        const state = this.itemsState[item.item_code];
        if (!state || !state.status) {
          return false;
        }
        // Si es WARN o FAIL, exige observaciones
        if (['WARN', 'FAIL'].includes(state.status) && (!state.observations || state.observations.trim() === '')) {
          return false;
        }
      }
      return true;
    },
    canSubmit() {
      if (this.isRefrigerated) {
        if (this.numericTemperature === null || isNaN(this.numericTemperature)) {
          return false;
        }
        if (this.numericTemperature < -5.0 || this.numericTemperature > 25.0) {
          return false;
        }
      }
      return this.allAnswered;
    }
  },
  watch: {
    modelValue(val) {
      if (val && this.order?.id) {
        this.resetForm();
        this.loadChecklist(this.order.id);
      }
    }
  },
  methods: {
    close() {
      this.$emit('update:modelValue', false);
      this.$emit('close');
    },

    resetForm() {
      this.checklistData = null;
      this.temperatureInput = '';
      this.itemsState = {};
      this.generalNotes = '';
      this.errorMessage = '';
      this.completionResult = null;
    },

    /**
     * Carga el catálogo normativo adaptado a la tipología de la máquina (RF-PREV-03)
     */
    async loadChecklist(orderId) {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        const res = await api.technician.getPreventiveChecklist(orderId);
        const data = res?.data || res || {};
        this.checklistData = data;

        // Inicializar estados de los ítems
        const stateMap = {};
        for (const item of data.checklist_items || []) {
          stateMap[item.item_code] = {
            status: '',
            observations: ''
          };
        }
        this.itemsState = stateMap;

        // Si la máquina no es refrigerada pero es snacks o caliente, sugerir temperatura o no exigir
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar el checklist normativo.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Marca todos los ítems como conformes (PASS) con un toque (RNF-06: < 90 segundos)
     */
    markAllPass() {
      for (const code of Object.keys(this.itemsState)) {
        this.itemsState[code].status = 'PASS';
        this.itemsState[code].observations = '';
      }
    },

    /**
     * Establece el estado de un ítem individual
     */
    setItemStatus(itemCode, status) {
      if (!this.itemsState[itemCode]) {
        this.itemsState[itemCode] = { status: '', observations: '' };
      }
      this.itemsState[itemCode].status = status;
      if (status === 'PASS') {
        this.itemsState[itemCode].observations = '';
      }
    },

    /**
     * Envía la evaluación del checklist para dictamen oficial
     */
    async submitChecklist() {
      this.errorMessage = '';

      // Validación térmica estricta
      if (this.isRefrigerated) {
        if (this.numericTemperature === null || isNaN(this.numericTemperature)) {
          this.errorMessage = 'Debe indicar la temperatura de la cuba en grados Celsius.';
          return;
        }
        if (this.numericTemperature < -5.0 || this.numericTemperature > 25.0) {
          this.errorMessage = 'La temperatura debe estar dentro de los límites físicos [-5.0 °C, +25.0 °C].';
          return;
        }
      }

      // Validar que todos los ítems estén respondidos
      for (const item of this.checklistData?.checklist_items || []) {
        const state = this.itemsState[item.item_code];
        if (!state || !state.status) {
          this.errorMessage = `Debe responder el ítem '${item.title}'.`;
          return;
        }
        if (['WARN', 'FAIL'].includes(state.status) && (!state.observations || state.observations.trim() === '')) {
          this.errorMessage = `Debe incluir observaciones justificativas para el ítem '${item.title}'.`;
          return;
        }
      }

      this.isSubmitting = true;

      try {
        const itemsPayload = Object.entries(this.itemsState).map(([code, val]) => ({
          item_code: code,
          status: val.status,
          observations: val.observations ? val.observations.trim() : null
        }));

        const payload = {
          temperature_measured: this.numericTemperature,
          items: itemsPayload,
          general_notes: this.generalNotes.trim() || undefined
        };

        const res = await api.technician.completePreventiveInspection(this.order.id, payload);
        const resData = res?.data || res || {};
        this.completionResult = resData;

        this.$emit('inspection-completed', resData);
      } catch (err) {
        this.errorMessage = err.message || 'Error al procesar la inspección preventiva.';
      } finally {
        this.isSubmitting = false;
      }
    }
  },
  template: `
    <div
      v-if="modelValue"
      style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); display: flex; align-items: flex-end; justify-content: center; z-index: 1060; padding: 0;"
      data-testid="modal-technician-checklist"
    >
      <!-- Contenedor móvil Bottom Sheet / Modal (max-width 520px) -->
      <div style="background: #ffffff; width: 100%; max-width: 520px; max-height: 94vh; border-top-left-radius: 16px; border-top-right-radius: 16px; box-shadow: 0 -4px 20px rgba(0,0,0,0.2); display: flex; flex-direction: column; overflow: hidden; animation: vg-slide-up 0.25s ease;">
        
        <!-- Cabecera Móvil Táctil -->
        <div style="padding: 14px 18px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
          <div>
            <div style="display: flex; align-items: center; gap: 6px;">
              <span style="font-size: 18px;">📋</span>
              <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #1e293b;">
                Checklist Sanitario in situ
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

        <!-- Pantalla de Resultado si la inspección finalizó -->
        <div v-if="completionResult" style="padding: 24px 18px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px; text-align: center;">
          <div v-if="completionResult.verdict === 'CONFORME' || completionResult.verdict === 'CONFORME_CON_OBSERVACIONES'">
            <div style="font-size: 48px; margin-bottom: 8px;">🎉</div>
            <h3 style="margin: 0 0 6px; font-size: 18px; color: #15803d;">
              ¡Inspección Completada Conforme!
            </h3>
            <p style="margin: 0; font-size: 13px; color: #166534;">
              Se ha emitido el Certificado Sanitario Oficial
              <strong v-if="completionResult.certificate?.certificate_code">
                ({{ completionResult.certificate.certificate_code }})
              </strong>.
              El parque queda actualizado con semáforo verde.
            </p>
          </div>

          <div v-else-if="completionResult.verdict === 'NO_CONFORME'">
            <div style="font-size: 48px; margin-bottom: 8px;">🚨</div>
            <h3 style="margin: 0 0 6px; font-size: 18px; color: #991b1b;">
              Inspección NO CONFORME — Cuarentena Activada
            </h3>
            <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 12px; border-radius: 8px; font-size: 13px; color: #7f1d1d; text-align: left;">
              <p style="margin: 0 0 6px;">
                🛑 <strong>Seguridad Alimentaria (Art. II):</strong> La máquina ha entrado en <strong>CUARENTENA SANITARIA</strong> con ventas y reportes ciudadanos bloqueados.
              </p>
              <p v-if="completionResult.corrective_action" style="margin: 0;">
                🔧 <strong>Acción Correctiva:</strong> Incidencia vinculada <strong>{{ completionResult.corrective_action.ticket_code }}</strong> abierta de urgencia.
              </p>
            </div>
          </div>

          <button
            type="button"
            class="vg-btn"
            style="width: 100%; min-height: 48px; background: #2560ff; color: #ffffff; font-weight: 700; font-size: 15px; border-radius: 6px; border: none; cursor: pointer; margin-top: 10px;"
            @click="close"
          >
            Aceptar y Volver a la Ruta
          </button>
        </div>

        <!-- Cuerpo del Checklist para responder -->
        <div v-else style="padding: 16px 18px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 16px;">
          <!-- Loader de carga -->
          <div v-if="isLoading" style="text-align: center; padding: 30px; color: #64748b; font-size: 14px;">
            ⏳ Cargando plantilla de checklist normativo...
          </div>

          <div v-else style="display: flex; flex-direction: column; gap: 16px;">
            <!-- Error Alert -->
            <div v-if="errorMessage" style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 10px 14px; border-radius: 6px; font-size: 13px;" data-testid="checklist-error-msg">
              ⚠️ {{ errorMessage }}
            </div>

            <!-- Botón de Aceleración Operativa (RNF-06) -->
            <div style="display: flex; justify-content: space-between; align-items: center; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 8px 12px; border-radius: 6px;">
              <span style="font-size: 12px; color: #166534; font-weight: 600;">
                ⚡ Inspección Rápida (&lt; 90s):
              </span>
              <button
                type="button"
                class="vg-btn"
                style="background: #16a34a; color: #ffffff; border: none; border-radius: 4px; padding: 6px 12px; font-size: 12px; font-weight: 700; cursor: pointer; min-height: 36px;"
                @click="markAllPass"
                data-testid="btn-mark-all-pass"
              >
                ✅ Marcar Todos Conformes (PASS)
              </button>
            </div>

            <!-- SECCIÓN 1: MEDICIÓN DE TEMPERATURA OBLIGATORIA (Art. II / EARS 3.1) -->
            <div
              v-if="isRefrigerated"
              style="background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 8px; padding: 14px; display: flex; flex-direction: column; gap: 10px;"
              data-testid="temperature-section"
            >
              <div style="display: flex; justify-content: space-between; align-items: baseline;">
                <label style="font-size: 13px; font-weight: 700; color: #1e293b;">
                  🌡️ Registro Térmico de Cuba (°C) *
                </label>
                <span style="font-size: 11px; color: #64748b;">Límites: [-5.0 °C, +25.0 °C]</span>
              </div>

              <!-- Input numérico táctil optimizado para teclado móvil -->
              <div style="display: flex; gap: 10px; align-items: center;">
                <input
                  type="number"
                  step="0.1"
                  min="-5.0"
                  max="25.0"
                  inputmode="decimal"
                  v-model="temperatureInput"
                  placeholder="Ej: 3.8"
                  class="vg-input"
                  style="flex: 1; height: 48px; font-size: 20px; font-weight: 800; text-align: center; border-radius: 6px; border: 2px solid #cbd5e1;"
                  :style="{ borderColor: isThermalBroken ? '#ef4444' : '#cbd5e1' }"
                  data-testid="input-temperature"
                />
                <span style="font-size: 18px; font-weight: 700; color: #475569;">°C</span>
              </div>

              <!-- ALERTA DE ROTURA DE FRÍO (Art. II Constitución) -->
              <div
                v-if="isThermalBroken"
                style="background: #fef2f2; border: 1px solid #ef4444; border-radius: 6px; padding: 10px; font-size: 12px; color: #991b1b; display: flex; align-items: flex-start; gap: 8px;"
                data-testid="thermal-broken-alert"
              >
                <span style="font-size: 18px;">🚨</span>
                <div>
                  <strong>ROTURA TÉRMICA DETECTADA (> 4.0 °C en perecederos):</strong>
                  Por imperativo del Artículo II de la Constitución, este valor forzará dictamen <strong>NO_CONFORME</strong> y activará la <strong>CUARENTENA SANITARIA</strong> inmediata del dispositivo.
                </div>
              </div>

              <small v-else-if="isPerishable" style="font-size: 11px; color: #166534;">
                ✅ Rango óptimo en perecederos: ≤ 4.0 °C para mantener la cadena de frío.
              </small>
            </div>

            <!-- SECCIÓN 2: ÍTEMS DEL CHECKLIST DIGITAL (RF-PREV-03) -->
            <div style="display: flex; flex-direction: column; gap: 12px;">
              <h4 style="margin: 0; font-size: 13px; font-weight: 700; color: #475569; text-transform: uppercase;">
                Puntos de Comprobación Higiénica
              </h4>

              <div
                v-for="item in checklistData?.checklist_items || []"
                :key="item.item_code"
                style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; display: flex; flex-direction: column; gap: 10px;"
                :style="{
                  borderLeft: itemsState[item.item_code]?.status === 'FAIL' ? '4px solid #ef4444' : itemsState[item.item_code]?.status === 'WARN' ? '4px solid #eab308' : itemsState[item.item_code]?.status === 'PASS' ? '4px solid #22c55e' : '1px solid #e2e8f0'
                }"
                data-testid="checklist-item-card"
              >
                <!-- Título y criticidad -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                  <div>
                    <strong style="font-size: 13px; color: #1e293b; display: block;">
                      {{ item.title }}
                    </strong>
                    <span v-if="item.description" style="font-size: 11px; color: #64748b;">
                      {{ item.description }}
                    </span>
                  </div>
                  <span
                    v-if="item.is_critical"
                    style="font-size: 10px; font-weight: bold; background: #fee2e2; color: #991b1b; padding: 2px 6px; border-radius: 4px; white-space: nowrap;"
                    title="Fallo en este ítem activa No Conformidad"
                  >
                    CRÍTICO
                  </span>
                </div>

                <!-- Grupo de Botones Táctiles PASS / WARN / FAIL (min-height 44px) -->
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px;">
                  <!-- PASS -->
                  <button
                    type="button"
                    class="vg-btn"
                    style="min-height: 44px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; border: 1px solid transparent; display: flex; align-items: center; justify-content: center; gap: 4px;"
                    :style="{
                      background: itemsState[item.item_code]?.status === 'PASS' ? '#22c55e' : '#f0fdf4',
                      color: itemsState[item.item_code]?.status === 'PASS' ? '#ffffff' : '#166534',
                      borderColor: '#bbf7d0'
                    }"
                    @click="setItemStatus(item.item_code, 'PASS')"
                    data-testid="btn-item-pass"
                  >
                    <span>✅</span> Conforme
                  </button>

                  <!-- WARN -->
                  <button
                    type="button"
                    class="vg-btn"
                    style="min-height: 44px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; border: 1px solid transparent; display: flex; align-items: center; justify-content: center; gap: 4px;"
                    :style="{
                      background: itemsState[item.item_code]?.status === 'WARN' ? '#eab308' : '#fefce8',
                      color: itemsState[item.item_code]?.status === 'WARN' ? '#ffffff' : '#854d0e',
                      borderColor: '#fef08a'
                    }"
                    @click="setItemStatus(item.item_code, 'WARN')"
                    data-testid="btn-item-warn"
                  >
                    <span>🟡</span> Obs.
                  </button>

                  <!-- FAIL -->
                  <button
                    type="button"
                    class="vg-btn"
                    style="min-height: 44px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; border: 1px solid transparent; display: flex; align-items: center; justify-content: center; gap: 4px;"
                    :style="{
                      background: itemsState[item.item_code]?.status === 'FAIL' ? '#ef4444' : '#fef2f2',
                      color: itemsState[item.item_code]?.status === 'FAIL' ? '#ffffff' : '#991b1b',
                      borderColor: '#fecaca'
                    }"
                    @click="setItemStatus(item.item_code, 'FAIL')"
                    data-testid="btn-item-fail"
                  >
                    <span>❌</span> Fallo
                  </button>
                </div>

                <!-- Campo de Observaciones obligatorio si WARN o FAIL -->
                <div v-if="['WARN', 'FAIL'].includes(itemsState[item.item_code]?.status)" style="display: flex; flex-direction: column; gap: 4px; margin-top: 4px;">
                  <label style="font-size: 11px; font-weight: 600; color: #b91c1c;">
                    Observaciones obligatorias *
                  </label>
                  <input
                    type="text"
                    v-model="itemsState[item.item_code].observations"
                    placeholder="Describa brevemente la anomalía detectada..."
                    class="vg-input"
                    style="height: 38px; padding: 0 8px; border-radius: 4px; border: 1px solid #f87171; font-size: 12px;"
                    data-testid="input-item-obs"
                  />
                </div>
              </div>
            </div>

            <!-- Notas Generales de la Inspección -->
            <div style="display: flex; flex-direction: column; gap: 4px;">
              <label style="font-size: 12px; font-weight: 600; color: #334155;">
                Notas u Observaciones Generales (Opcional)
              </label>
              <textarea
                v-model="generalNotes"
                rows="2"
                class="vg-input"
                style="padding: 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px;"
                placeholder="Comentarios adicionales sobre el estado de la máquina..."
              ></textarea>
            </div>
          </div>
        </div>

        <!-- Barra Inferior de Acción Móvil Táctil -->
        <div v-if="!completionResult" style="padding: 12px 18px; border-top: 1px solid #e2e8f0; background: #f8fafc; display: flex; gap: 10px;">
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
            style="flex: 1; min-height: 48px; background: #2560ff; color: #ffffff; border: none; border-radius: 6px; font-weight: 700; font-size: 15px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;"
            :disabled="isSubmitting || !canSubmit"
            :style="{ opacity: canSubmit ? 1 : 0.6, cursor: canSubmit ? 'pointer' : 'not-allowed' }"
            @click="submitChecklist"
            data-testid="btn-submit-checklist"
          >
            <span v-if="isSubmitting">⏳ Procesando dictamen...</span>
            <span v-else>🏁 Finalizar y Emitir Dictamen</span>
          </button>
        </div>
      </div>
    </div>
  `
};
export default TechnicianChecklistModal;
