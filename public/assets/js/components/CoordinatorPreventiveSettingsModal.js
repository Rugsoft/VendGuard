/**
 * VendGuard - CoordinatorPreventiveSettingsModal (CoordinatorPreventiveSettingsModal.js)
 * 
 * Componente Vue 3 ESM para la gestión de Frecuencias Normativas y Pausas Estacionales (RF-PREV-01, Constitución Art. II).
 * 
 * Características:
 * 1. Configuración de frecuencias estándar por tipología de máquina (PERISHABLE_FOOD, HOT_DRINKS, COLD_DRINKS, SNACKS, COMBO).
 * 2. Blindaje Constitucional Art. II: Validación y bloqueo absoluto ante intentos de fijar > 15 días en alimentos perecederos.
 * 3. Gestión de Pausas Estacionales por máquina (EARS 1.5): activación con motivo justificado obligatorio y fecha de reanudación.
 * 4. Reanudación de servicio y recálculo automático de vigencias (EARS 1.4).
 * 5. Ajuste de frecuencia preventiva individual por máquina.
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias externas).
 * Dualismo Lingüístico: Código en inglés, interfaz y mensajes en español.
 */

import { api } from '../api.js';

export const CoordinatorPreventiveSettingsModal = {
  name: 'CoordinatorPreventiveSettingsModal',
  props: {
    modelValue: {
      type: Boolean,
      default: false
    }
  },
  emits: ['update:modelValue', 'close', 'settings-updated', 'pause-updated'],
  data() {
    return {
      activeTab: 'types', // 'types' | 'machine'
      settingsList: [],
      machinesList: [],
      isLoading: false,
      isSaving: false,
      errorMessage: '',
      successMessage: '',

      // Formulario de edición por tipología
      selectedTypeSetting: null,
      typeForm: {
        machine_type: '',
        default_frequency_days: 15,
        max_allowed_days: 15,
        advance_warning_days: 5
      },
      typeError: '',

      // Formulario de máquina individual y pausa estacional
      selectedMachineId: '',
      machineSettings: null,
      machineForm: {
        is_seasonal_pause: false,
        seasonal_pause_reason: '',
        seasonal_pause_until: '',
        sanitary_frequency_days: ''
      },
      machineError: ''
    };
  },
  watch: {
    modelValue(val) {
      if (val) {
        this.errorMessage = '';
        this.successMessage = '';
        this.loadSettings();
        this.loadMachines();
      }
    },
    selectedMachineId(newId) {
      if (newId) {
        this.loadMachineConfig(newId);
      } else {
        this.machineSettings = null;
      }
    }
  },
  mounted() {
    if (this.modelValue) {
      this.loadSettings();
      this.loadMachines();
    }
  },
  methods: {
    close() {
      this.$emit('update:modelValue', false);
      this.$emit('close');
    },

    /**
     * Carga el catálogo de frecuencias estándar por tipología (RF-PREV-01)
     */
    async loadSettings() {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        const res = await api.coordinator.getPreventiveSettings();
        this.settingsList = res?.data || (Array.isArray(res) ? res : []);
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar las frecuencias normativas.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Carga el catálogo de máquinas para el selector de configuración individual
     */
    async loadMachines() {
      try {
        const res = await api.coordinator.getMachines();
        this.machinesList = res?.data || res || [];
      } catch (err) {
        console.warn('No se pudo cargar la lista de máquinas para pausa estacional:', err);
      }
    },

    /**
     * Selecciona una tipología para edición
     */
    selectTypeForEdit(setting) {
      this.selectedTypeSetting = setting;
      this.typeForm = {
        machine_type: setting.machine_type,
        default_frequency_days: setting.default_frequency_days,
        max_allowed_days: setting.max_allowed_days,
        advance_warning_days: setting.advance_warning_days || 5
      };
      this.typeError = '';
    },

    /**
     * Guarda la configuración de una tipología con validación Art. II
     */
    async saveTypeSettings() {
      const type = this.typeForm.machine_type;
      const defaultDays = Number(this.typeForm.default_frequency_days);
      const maxDays = Number(this.typeForm.max_allowed_days);
      const advanceDays = Number(this.typeForm.advance_warning_days || 5);

      // BLINDAJE CONSTITUCIONAL ARTÍCULO II (Seguridad Alimentaria)
      if (type === 'PERISHABLE_FOOD' && (defaultDays > 15 || maxDays > 15)) {
        this.typeError = 'Por imperativo del Artículo II de la Constitución (Seguridad Alimentaria), las máquinas dispensadoras de alimentos perecederos no pueden superar los 15 días naturales entre inspecciones sanitarias.';
        return;
      }

      if (defaultDays <= 0 || maxDays <= 0) {
        this.typeError = 'Los días de frecuencia deben ser valores numéricos positivos mayores a cero.';
        return;
      }

      if (defaultDays > maxDays) {
        this.typeError = 'La frecuencia por defecto no puede ser mayor que el tope máximo permitido.';
        return;
      }

      this.isSaving = true;
      this.typeError = '';

      try {
        await api.coordinator.updatePreventiveSettings({
          machine_type: type,
          default_frequency_days: defaultDays,
          max_allowed_days: maxDays,
          advance_warning_days: advanceDays
        });

        this.successMessage = `Frecuencias normativas para '${this.getTypeLabel(type)}' actualizadas correctamente.`;
        this.selectedTypeSetting = null;
        await this.loadSettings();
        this.$emit('settings-updated');
      } catch (err) {
        this.typeError = err.message || 'Error al actualizar la configuración de tipología.';
      } finally {
        this.isSaving = false;
      }
    },

    /**
     * Carga la configuración de una máquina individual (pausa estacional y frecuencia)
     */
    async loadMachineConfig(machineId) {
      this.isLoading = true;
      this.machineError = '';

      try {
        const res = await api.coordinator.getMachinePreventiveConfig(machineId);
        const data = res?.data || res || {};
        this.machineSettings = data;

        this.machineForm = {
          is_seasonal_pause: Boolean(data.is_seasonal_pause),
          seasonal_pause_reason: data.seasonal_pause_reason || '',
          seasonal_pause_until: data.seasonal_pause_until || '',
          sanitary_frequency_days: data.sanitary_frequency_days !== null && data.sanitary_frequency_days !== undefined
            ? String(data.sanitary_frequency_days)
            : ''
        };
      } catch (err) {
        this.machineError = err.message || 'Error al cargar la configuración de la máquina.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Guarda la configuración de máquina individual o activa/desactiva pausa estacional
     */
    async saveMachineConfig() {
      if (!this.selectedMachineId) {
        this.machineError = 'Seleccione una máquina primero.';
        return;
      }

      const payload = {};
      const isPause = this.machineForm.is_seasonal_pause;
      payload.is_seasonal_pause = isPause;

      // VALIDACIÓN DE PAUSA ESTACIONAL (EARS 1.5)
      if (isPause) {
        const reason = (this.machineForm.seasonal_pause_reason || '').trim();
        if (!reason) {
          this.machineError = 'Debe justificar documentalmente el motivo de la pausa estacional (EARS 1.5).';
          return;
        }
        payload.seasonal_pause_reason = reason;
        if (this.machineForm.seasonal_pause_until) {
          payload.seasonal_pause_until = this.machineForm.seasonal_pause_until;
        }
      }

      // VALIDACIÓN DE FRECUENCIA INDIVIDUAL (Art. II)
      if (this.machineForm.sanitary_frequency_days !== '') {
        const freqDays = Number(this.machineForm.sanitary_frequency_days);
        const isPerishable = this.machineSettings?.is_perishable || this.machineSettings?.machine_type === 'PERISHABLE_FOOD';

        if (isPerishable && freqDays > 15) {
          this.machineError = 'Por imperativo del Artículo II de la Constitución (Seguridad Alimentaria), las máquinas dispensadoras de alimentos perecederos no pueden superar los 15 días naturales entre inspecciones sanitarias.';
          return;
        }
        if (freqDays <= 0) {
          this.machineError = 'La frecuencia individual debe ser un número entero mayor que cero.';
          return;
        }
        payload.sanitary_frequency_days = freqDays;
      } else {
        payload.sanitary_frequency_days = null; // restablecer a la de tipología
      }

      this.isSaving = true;
      this.machineError = '';

      try {
        await api.coordinator.updateMachinePreventiveConfig(this.selectedMachineId, payload);
        this.successMessage = isPause
          ? `Pausa estacional activada para ${this.machineSettings?.machine_code || 'la máquina'}.`
          : `Configuración preventiva actualizada para ${this.machineSettings?.machine_code || 'la máquina'}.`;

        await this.loadMachineConfig(this.selectedMachineId);
        this.$emit('pause-updated');
      } catch (err) {
        this.machineError = err.message || 'Error al actualizar la configuración de la máquina.';
      } finally {
        this.isSaving = false;
      }
    },

    /**
     * Helpers de etiquetado
     */
    getTypeLabel(type) {
      const map = {
        PERISHABLE_FOOD: '🥩 Alimentos Perecederos (Sándwiches/Lácteos)',
        HOT_DRINKS: '☕ Bebidas Calientes (Café/Té)',
        COLD_DRINKS: '🥤 Bebidas Frías (Refrescos/Agua)',
        SNACKS: '🍪 Snacks y No Perecederos',
        COMBO: '🍱 Mixta / Combinada'
      };
      return map[type] || type || 'General';
    }
  },
  template: `
    <div
      v-if="modelValue"
      style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); display: flex; align-items: center; justify-content: center; z-index: 1050; padding: 16px;"
      data-testid="modal-preventive-settings"
    >
      <div style="background: #ffffff; width: 100%; max-width: 680px; max-height: 90vh; border-radius: 8px; box-shadow: 0 12px 30px rgba(0,0,0,0.25); display: flex; flex-direction: column; overflow: hidden;">
        <!-- Cabecera -->
        <div style="padding: 18px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
          <div>
            <h3 style="margin: 0 0 2px; font-size: 18px; font-family: var(--font-display, 'DM Sans', sans-serif); color: #1e293b; display: flex; align-items: center; gap: 8px;">
              <span>⚙️</span> Frecuencias Sanitarias y Pausas Estacionales
            </h3>
            <p style="margin: 0; font-size: 13px; color: #64748b;">
              Gestión de periodicidades legales y salvaguarda de alimentos perecederos (Art. II Constitución).
            </p>
          </div>
          <button type="button" @click="close" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b; line-height: 1;">✕</button>
        </div>

        <!-- Pestañas de navegación interna del Modal -->
        <div style="display: flex; border-bottom: 1px solid #e2e8f0; background: #ffffff; padding: 0 24px;">
          <button
            type="button"
            style="padding: 12px 16px; border: none; background: none; font-size: 14px; font-weight: 600; cursor: pointer; border-bottom: 2px solid transparent; transition: all 0.2s ease;"
            :style="{ color: activeTab === 'types' ? '#2560ff' : '#64748b', borderBottomColor: activeTab === 'types' ? '#2560ff' : 'transparent' }"
            @click="activeTab = 'types'"
            data-testid="tab-settings-types"
          >
            📋 Frecuencias por Tipología
          </button>
          <button
            type="button"
            style="padding: 12px 16px; border: none; background: none; font-size: 14px; font-weight: 600; cursor: pointer; border-bottom: 2px solid transparent; transition: all 0.2s ease;"
            :style="{ color: activeTab === 'machine' ? '#2560ff' : '#64748b', borderBottomColor: activeTab === 'machine' ? '#2560ff' : 'transparent' }"
            @click="activeTab = 'machine'"
            data-testid="tab-settings-machine"
          >
            🏖️ Pausa Estacional por Máquina
          </button>
        </div>

        <!-- Cuerpo del Modal -->
        <div style="padding: 20px 24px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 16px;">
          <!-- Mensajes de éxito y error global -->
          <div v-if="successMessage" style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 10px 14px; border-radius: 4px; font-size: 13px; display: flex; justify-content: space-between; align-items: center;">
            <span>✅ {{ successMessage }}</span>
            <button type="button" @click="successMessage = ''" style="background: none; border: none; font-size: 14px; cursor: pointer; color: #166534;">✕</button>
          </div>

          <div v-if="errorMessage" style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 10px 14px; border-radius: 4px; font-size: 13px; display: flex; justify-content: space-between; align-items: center;">
            <span>⚠️ {{ errorMessage }}</span>
            <button type="button" @click="errorMessage = ''" style="background: none; border: none; font-size: 14px; cursor: pointer; color: #991b1b;">✕</button>
          </div>

          <!-- ============================================================= -->
          <!-- PESTAÑA 1: FRECUENCIAS POR TIPOLOGÍA (Art. II)               -->
          <!-- ============================================================= -->
          <div v-if="activeTab === 'types'" style="display: flex; flex-direction: column; gap: 16px;">
            <div style="background: #f8fafc; border-left: 4px solid #0284c7; padding: 10px 14px; font-size: 12px; color: #334155; border-radius: 2px;">
              🛡️ <strong>Artículo II Constitución:</strong> La periodicidad máxima permitida para alimentos perecederos es de <strong>15 días naturales</strong>. El sistema bloquea automáticamente cualquier valor superior.
            </div>

            <!-- Tabla de Tipologías -->
            <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
              <thead>
                <tr style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1; color: #475569;">
                  <th style="padding: 10px 12px;">Tipología</th>
                  <th style="padding: 10px 12px; text-align: center;">Frecuencia Habitual</th>
                  <th style="padding: 10px 12px; text-align: center;">Tope Legal</th>
                  <th style="padding: 10px 12px; text-align: right;">Acción</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="s in settingsList"
                  :key="s.id || s.machine_type"
                  style="border-bottom: 1px solid #e2e8f0;"
                  :style="{ background: s.machine_type === 'PERISHABLE_FOOD' ? '#fff5f5' : 'transparent' }"
                >
                  <td style="padding: 10px 12px;">
                    <div style="font-weight: 600; color: #1e293b;">
                      {{ getTypeLabel(s.machine_type) }}
                    </div>
                  </td>
                  <td style="padding: 10px 12px; text-align: center; font-weight: 700; color: #2560ff;">
                    {{ s.default_frequency_days }} días
                  </td>
                  <td style="padding: 10px 12px; text-align: center; font-weight: 600;">
                    <span :style="{ color: s.machine_type === 'PERISHABLE_FOOD' ? '#991b1b' : '#475569' }">
                      {{ s.max_allowed_days }} días
                    </span>
                  </td>
                  <td style="padding: 10px 12px; text-align: right;">
                    <button
                      type="button"
                      class="vg-btn"
                      style="background: #f1f5f9; color: #1e293b; border: 1px solid #cbd5e1; padding: 4px 8px; font-size: 12px; border-radius: 4px; cursor: pointer;"
                      @click="selectTypeForEdit(s)"
                      data-testid="btn-edit-type"
                    >
                      ✏️ Editar
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>

            <!-- Formulario de Edición de Tipología -->
            <div
              v-if="selectedTypeSetting"
              style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 16px; margin-top: 8px; display: flex; flex-direction: column; gap: 12px;"
              data-testid="form-edit-type"
            >
              <div style="display: flex; justify-content: space-between; align-items: center;">
                <h4 style="margin: 0; font-size: 14px; color: #1e293b;">
                  Editar Frecuencias: <strong>{{ getTypeLabel(typeForm.machine_type) }}</strong>
                </h4>
                <button type="button" @click="selectedTypeSetting = null" style="background: none; border: none; font-size: 14px; cursor: pointer; color: #64748b;">✕</button>
              </div>

              <div v-if="typeError" style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 8px 12px; border-radius: 4px; font-size: 12px;" data-testid="type-error-msg">
                ⚠️ {{ typeError }}
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <!-- Frecuencia habitual -->
                <div style="display: flex; flex-direction: column; gap: 4px;">
                  <label style="font-size: 12px; font-weight: 600; color: #334155;">Frecuencia Habitual (días) *</label>
                  <input
                    type="number"
                    v-model.number="typeForm.default_frequency_days"
                    min="1"
                    :max="typeForm.machine_type === 'PERISHABLE_FOOD' ? 15 : 120"
                    class="vg-input"
                    style="height: 36px; padding: 0 8px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 13px;"
                    data-testid="input-default-days"
                  />
                  <small v-if="typeForm.machine_type === 'PERISHABLE_FOOD'" style="color: #991b1b; font-size: 11px;">
                    Tope constitucional máximo: 15 días (Art. II)
                  </small>
                </div>

                <!-- Tope legal -->
                <div style="display: flex; flex-direction: column; gap: 4px;">
                  <label style="font-size: 12px; font-weight: 600; color: #334155;">Tope Máximo Permitido (días) *</label>
                  <input
                    type="number"
                    v-model.number="typeForm.max_allowed_days"
                    min="1"
                    :max="typeForm.machine_type === 'PERISHABLE_FOOD' ? 15 : 120"
                    class="vg-input"
                    style="height: 36px; padding: 0 8px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 13px;"
                    data-testid="input-max-days"
                  />
                </div>
              </div>

              <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button
                  type="button"
                  class="vg-btn"
                  style="background: #ffffff; color: #475569; border: 1px solid #cbd5e1; height: 34px; padding: 0 12px; font-size: 12px; border-radius: 4px; cursor: pointer;"
                  @click="selectedTypeSetting = null"
                >
                  Cancelar
                </button>
                <button
                  type="button"
                  class="vg-btn"
                  style="background: #2560ff; color: #ffffff; border: 1px solid #1a4cd8; height: 34px; padding: 0 14px; font-size: 12px; font-weight: 600; border-radius: 4px; cursor: pointer;"
                  :disabled="isSaving"
                  @click="saveTypeSettings"
                  data-testid="btn-save-type"
                >
                  <span v-if="isSaving">Guardando...</span>
                  <span v-else>Guardar Frecuencia</span>
                </button>
              </div>
            </div>
          </div>

          <!-- ============================================================= -->
          <!-- PESTAÑA 2: PAUSA ESTACIONAL POR MÁQUINA (EARS 1.5)           -->
          <!-- ============================================================= -->
          <div v-else-if="activeTab === 'machine'" style="display: flex; flex-direction: column; gap: 16px;">
            <div style="background: #f0fdf4; border-left: 4px solid #22c55e; padding: 10px 14px; font-size: 12px; color: #166534; border-radius: 2px;">
              🏖️ <strong>Pausa Estacional Justificada (EARS 1.5):</strong> Suspende temporalmente la obligación de inspección sanitaria sin teñir la máquina de rojo ante periodos vacacionales o cierres de sede. Requiere vaciado previo de perecederos.
            </div>

            <!-- Selector de Máquina -->
            <div style="display: flex; flex-direction: column; gap: 6px;">
              <label style="font-size: 13px; font-weight: 600; color: #334155;">Seleccionar Máquina *</label>
              <select
                v-model="selectedMachineId"
                class="vg-input"
                style="height: 38px; padding: 0 10px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 14px;"
                data-testid="select-pause-machine"
              >
                <option value="">Seleccione una máquina del catálogo...</option>
                <option v-for="m in machinesList" :key="m.id" :value="m.id">
                  {{ m.code }} - {{ m.model }} ({{ m.location_name || ('Sede #' + m.location_id) }})
                </option>
              </select>
            </div>

            <div v-if="machineError" style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 10px; border-radius: 4px; font-size: 13px;" data-testid="machine-error-msg">
              ⚠️ {{ machineError }}
            </div>

            <!-- Panel de Configuración de la Máquina Seleccionada -->
            <div
              v-if="machineSettings"
              style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 16px; display: flex; flex-direction: column; gap: 14px;"
              data-testid="machine-config-panel"
            >
              <div style="display: flex; justify-content: space-between; align-items: baseline; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                <div>
                  <strong style="font-size: 15px; color: #0f172a;">{{ machineSettings.machine_code }}</strong>
                  <span style="font-size: 13px; color: #64748b; margin-left: 6px;">({{ machineSettings.machine_type }})</span>
                </div>
                <span
                  style="padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: bold;"
                  :style="{
                    background: machineSettings.is_seasonal_pause ? '#e0e7ff' : '#dcfce7',
                    color: machineSettings.is_seasonal_pause ? '#3730a3' : '#166534'
                  }"
                >
                  {{ machineSettings.is_seasonal_pause ? '🏖️ En Pausa Estacional' : '🟢 Activa en Servicio' }}
                </span>
              </div>

              <!-- Switch / Checkbox de Pausa Estacional -->
              <div style="display: flex; align-items: center; gap: 10px; padding: 8px 0;">
                <input
                  type="checkbox"
                  id="chk-pause"
                  v-model="machineForm.is_seasonal_pause"
                  style="width: 18px; height: 18px; cursor: pointer;"
                  data-testid="chk-seasonal-pause"
                />
                <label for="chk-pause" style="font-size: 14px; font-weight: 600; color: #1e293b; cursor: pointer;">
                  Activar Pausa Estacional Programada
                </label>
              </div>

              <!-- Campos adicionales si está en pausa -->
              <div v-if="machineForm.is_seasonal_pause" style="display: flex; flex-direction: column; gap: 12px; background: #ffffff; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                <!-- Motivo Obligatorio (EARS 1.5) -->
                <div style="display: flex; flex-direction: column; gap: 4px;">
                  <label style="font-size: 12px; font-weight: 600; color: #334155;">
                    Motivo Justificado de Pausa Estacional * (ej: vacaciones de verano, reformas de sede)
                  </label>
                  <textarea
                    v-model="machineForm.seasonal_pause_reason"
                    rows="2"
                    class="vg-input"
                    style="padding: 8px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 13px;"
                    placeholder="Especifique el motivo documentado de la suspensión estacional..."
                    data-testid="input-pause-reason"
                  ></textarea>
                </div>

                <!-- Fecha estimada de reanudación -->
                <div style="display: flex; flex-direction: column; gap: 4px;">
                  <label style="font-size: 12px; font-weight: 600; color: #334155;">Fecha Estimada de Reanudación</label>
                  <input
                    type="date"
                    v-model="machineForm.seasonal_pause_until"
                    class="vg-input"
                    style="height: 36px; padding: 0 8px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 13px;"
                    data-testid="input-pause-until"
                  />
                </div>
              </div>

              <!-- Frecuencia individual opcional -->
              <div style="display: flex; flex-direction: column; gap: 4px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
                <label style="font-size: 12px; font-weight: 600; color: #334155;">
                  Frecuencia Sanitaria Individual (días, opcional)
                </label>
                <div style="display: flex; gap: 8px; align-items: center;">
                  <input
                    type="number"
                    v-model="machineForm.sanitary_frequency_days"
                    min="1"
                    :max="machineSettings?.is_perishable ? 15 : 120"
                    placeholder="Heredar frecuencia de tipología"
                    class="vg-input"
                    style="height: 36px; padding: 0 8px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 13px; max-width: 250px;"
                    data-testid="input-individual-freq"
                  />
                  <span style="font-size: 12px; color: #64748b;">
                    (Efectiva: {{ machineSettings.effective_frequency_days || machineSettings.default_frequency_days }} días)
                  </span>
                </div>
              </div>

              <!-- Botón de guardar máquina -->
              <div style="display: flex; justify-content: flex-end; margin-top: 8px;">
                <button
                  type="button"
                  class="vg-btn"
                  style="background: #2560ff; color: #ffffff; border: 1px solid #1a4cd8; height: 38px; padding: 0 16px; font-weight: 600; border-radius: 4px; cursor: pointer;"
                  :disabled="isSaving"
                  @click="saveMachineConfig"
                  data-testid="btn-save-machine-config"
                >
                  <span v-if="isSaving">Guardando...</span>
                  <span v-else>Guardar Configuración</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Pie del Modal -->
        <div style="padding: 14px 24px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; background: #f8fafc;">
          <button
            type="button"
            class="vg-btn"
            style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; height: 36px; padding: 0 16px; border-radius: 4px; cursor: pointer;"
            @click="close"
          >
            Cerrar
          </button>
        </div>
      </div>
    </div>
  `
};
export default CoordinatorPreventiveSettingsModal;
