/**
 * VendGuard - TechnicianSparePartsPauseModal (TechnicianSparePartsPauseModal.js)
 * 
 * Modal táctil para smartphone para pausar una incidencia por repuestos pendientes (Módulo M2: RF-REP-03, RF-REP-04).
 * 
 * Características:
 * 1. Ergonomía táctil para técnicos en campo (touch targets >= 44x44px, RNF-REP-03).
 * 2. Carga reactiva de repuestos compatibles con el modelo específico de la máquina (RF-REP-03).
 * 3. Selección estructurada de piezas con contador táctil de cantidad (1 a 50 unidades).
 * 4. Opción no bloqueante de "Pieza fuera de catálogo" con justificación obligatoria >= 20 caracteres (RF-REP-04).
 * 5. Eliminación de texto libre arbitrario para repuestos catalogados.
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias externas).
 * Dualismo Lingüístico: Código y propiedades en inglés, textos e interfaz en español.
 */

import { api } from '../api.js';

export const TechnicianSparePartsPauseModal = {
  name: 'TechnicianSparePartsPauseModal',
  props: {
    modelValue: {
      type: Boolean,
      default: false
    },
    incident: {
      type: Object,
      default: null
    }
  },
  emits: ['update:modelValue', 'close', 'paused'],
  data() {
    return {
      compatibleParts: [],
      selectedParts: [], // Array de { spare_part_id, part_code, name, quantity, reference_cost }
      selectedPartIdToAdd: '',
      quantityToAdd: 1,
      isOutOfCatalog: false,
      customPartDescription: '',
      isLoadingCatalog: false,
      isSubmitting: false,
      errorMessage: ''
    };
  },
  computed: {
    machineModel() {
      return this.incident?.machine?.model || this.incident?.machine_model || 'Modelo no especificado';
    },
    machineCode() {
      return this.incident?.machine?.code || this.incident?.machine_code || 'VEND-???';
    },
    ticketCode() {
      return this.incident?.ticket_code || 'INC-???';
    },
    availablePartsForSelection() {
      const selectedIds = new Set(this.selectedParts.map(p => Number(p.spare_part_id)));
      return this.compatibleParts.filter(p => !selectedIds.has(Number(p.id)));
    },
    canSubmit() {
      if (this.isOutOfCatalog) {
        return (this.customPartDescription || '').trim().length >= 20;
      }
      return this.selectedParts.length > 0;
    }
  },
  watch: {
    modelValue(newVal) {
      if (newVal) {
        this.initModal();
      }
    },
    incident() {
      if (this.modelValue) {
        this.initModal();
      }
    }
  },
  mounted() {
    if (this.modelValue) {
      this.initModal();
    }
  },
  methods: {
    /**
     * Inicializa los datos del modal y carga los repuestos compatibles de la máquina.
     */
    async initModal() {
      this.selectedParts = [];
      this.selectedPartIdToAdd = '';
      this.quantityToAdd = 1;
      this.isOutOfCatalog = false;
      this.customPartDescription = '';
      this.errorMessage = '';

      if (!this.incident) return;

      const machineId = this.incident.machine?.id || this.incident.machine_id;
      if (!machineId) {
        this.compatibleParts = [];
        return;
      }

      this.isLoadingCatalog = true;
      try {
        const res = await api.technician.getSparePartsCatalog(machineId, this.incident.id);
        const data = res?.data || res || [];
        this.compatibleParts = Array.isArray(data) ? data : (data.compatible_parts || []);
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar el catálogo de repuestos compatibles.';
      } finally {
        this.isLoadingCatalog = false;
      }
    },

    /**
     * Cierra el modal y notifica al componente padre.
     */
    close() {
      this.$emit('update:modelValue', false);
      this.$emit('close');
    },

    /**
     * Alterna entre modo de repuesto de catálogo y pieza fuera de catálogo.
     */
    setOutOfCatalogMode(active) {
      this.isOutOfCatalog = active;
      this.errorMessage = '';
    },

    /**
     * Añade la pieza seleccionada a la lista de repuestos solicitados.
     */
    addSelectedPart() {
      if (!this.selectedPartIdToAdd) return;

      const part = this.compatibleParts.find(p => p.id === Number(this.selectedPartIdToAdd));
      if (!part) return;

      const qty = parseInt(this.quantityToAdd, 10);
      const safeQty = isNaN(qty) || qty < 1 ? 1 : Math.min(qty, 50);

      this.selectedParts.push({
        spare_part_id: part.id,
        part_code: part.part_code,
        name: part.name,
        category: part.category,
        reference_cost: part.reference_cost,
        quantity: safeQty
      });

      this.selectedPartIdToAdd = '';
      this.quantityToAdd = 1;
    },

    /**
     * Elimina un repuesto de la lista de piezas solicitadas.
     */
    removePart(index) {
      this.selectedParts.splice(index, 1);
    },

    /**
     * Modifica la cantidad de una pieza solicitada (mínimo 1, máximo 50).
     */
    changePartQuantity(part, delta) {
      const next = part.quantity + delta;
      if (next >= 1 && next <= 50) {
        part.quantity = next;
      }
    },

    /**
     * Incrementa o decrementa la cantidad temporal para añadir.
     */
    stepQuantityToAdd(delta) {
      const next = this.quantityToAdd + delta;
      if (next >= 1 && next <= 50) {
        this.quantityToAdd = next;
      }
    },

    /**
     * Envía la solicitud estructurada de pausa por repuesto (RF-REP-03 / RF-REP-04).
     */
    async submitPause() {
      this.errorMessage = '';

      if (!this.incident || !this.incident.id) {
        this.errorMessage = 'No se ha proporcionado la incidencia a pausar.';
        return;
      }

      // Validaciones en cliente
      if (this.isOutOfCatalog) {
        const desc = (this.customPartDescription || '').trim();
        if (desc.length < 20) {
          this.errorMessage = 'La justificación técnica de la pieza fuera de catálogo debe tener al menos 20 caracteres (actualmente: ' + desc.length + ').';
          return;
        }
      } else {
        if (this.selectedParts.length === 0) {
          this.errorMessage = 'Debe seleccionar al menos un repuesto compatible del catálogo o indicar una pieza fuera de catálogo.';
          return;
        }
      }

      this.isSubmitting = true;

      const payload = {
        is_out_of_catalog: this.isOutOfCatalog,
        custom_part_description: this.isOutOfCatalog ? this.customPartDescription.trim() : null,
        requested_parts: this.isOutOfCatalog ? [] : this.selectedParts.map(p => ({
          spare_part_id: Number(p.spare_part_id),
          quantity: Number(p.quantity)
        }))
      };

      try {
        const res = await api.technician.pauseIncident(this.incident.id, payload);
        this.$emit('paused', {
          incidentId: this.incident.id,
          payload,
          result: res
        });
        this.close();
      } catch (err) {
        this.errorMessage = err.message || 'Error al pausar la intervención por repuestos.';
      } finally {
        this.isSubmitting = false;
      }
    }
  },
  template: `
    <div
      v-if="modelValue"
      class="technician-pause-modal-backdrop"
      style="position: fixed; inset: 0; background-color: rgba(0, 0, 0, 0.6); display: flex; align-items: flex-end; justify-content: center; z-index: 1100; backdrop-filter: blur(2px);"
      data-testid="technician-spare-parts-pause-modal"
    >
      <div
        class="technician-pause-modal-container"
        style="background-color: #ffffff; width: 100%; max-width: 520px; border-top-left-radius: 12px; border-top-right-radius: 12px; max-height: 92vh; display: flex; flex-direction: column; box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.15); overflow: hidden;"
      >
        <!-- Encabezado táctil móvil -->
        <div style="padding: 16px 18px; border-bottom: 1px solid #c8cfda; background-color: #f9fafb; display: flex; align-items: center; justify-content: space-between;">
          <div>
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #f8b60f; letter-spacing: 0.5px;">
              ⏸️ Pausa por Repuesto (RF-REP-03)
            </div>
            <h3 style="margin: 2px 0 0 0; font-size: 16px; font-weight: 700; color: #2c333f;">
              {{ ticketCode }} · {{ machineCode }}
            </h3>
            <div style="font-size: 12px; color: #6c7e9d; margin-top: 1px;">
              Modelo: <strong>{{ machineModel }}</strong>
            </div>
          </div>

          <button
            type="button"
            @click="close"
            style="min-width: 44px; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; background: none; border: none; font-size: 24px; color: #6c7e9d; cursor: pointer;"
            data-testid="btn-close-pause-modal"
          >
            &times;
          </button>
        </div>

        <!-- Mensaje de error general -->
        <div
          v-if="errorMessage"
          style="background-color: #fddfdf; border-bottom: 1px solid #ff5757; color: #991b1b; padding: 12px 18px; font-size: 13px;"
          data-testid="pause-modal-error-message"
        >
          ⚠️ {{ errorMessage }}
        </div>

        <!-- Cuerpo scrollable -->
        <div style="padding: 18px; overflow-y: auto; flex: 1 1 auto; display: flex; flex-direction: column; gap: 16px;">
          <!-- Conmutador táctil: Pieza de catálogo vs Pieza fuera de catálogo -->
          <div>
            <label style="display: block; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #6c7e9d; margin-bottom: 8px;">
              Tipo de Repuesto Requerido
            </label>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
              <button
                type="button"
                :style="{
                  minHeight: '44px',
                  fontSize: '13px',
                  fontWeight: '600',
                  padding: '8px 12px',
                  borderRadius: '4px',
                  border: '1px solid',
                  cursor: 'pointer',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '6px',
                  borderColor: !isOutOfCatalog ? '#2560ff' : '#c8cfda',
                  backgroundColor: !isOutOfCatalog ? '#e5f2fc' : '#ffffff',
                  color: !isOutOfCatalog ? '#003db5' : '#2c333f'
                }"
                @click="setOutOfCatalogMode(false)"
                data-testid="toggle-mode-catalog"
              >
                <span>📦 Pieza de Catálogo</span>
              </button>

              <button
                type="button"
                :style="{
                  minHeight: '44px',
                  fontSize: '13px',
                  fontWeight: '600',
                  padding: '8px 12px',
                  borderRadius: '4px',
                  border: '1px solid',
                  cursor: 'pointer',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '6px',
                  borderColor: isOutOfCatalog ? '#f8b60f' : '#c8cfda',
                  backgroundColor: isOutOfCatalog ? '#fffbeb' : '#ffffff',
                  color: isOutOfCatalog ? '#92400e' : '#2c333f'
                }"
                @click="setOutOfCatalogMode(true)"
                data-testid="toggle-mode-out-of-catalog"
              >
                <span>⚙️ Fuera de Catálogo</span>
              </button>
            </div>
          </div>

          <!-- MODO 1: PIEZAS COMPATIBLES DEL CATÁLOGO (RF-REP-03) -->
          <div v-if="!isOutOfCatalog" style="display: flex; flex-direction: column; gap: 14px;" data-testid="mode-catalog-section">
            <!-- Selector dinámico de repuesto y cantidad -->
            <div style="background-color: #f9fafb; border: 1px solid #c8cfda; border-radius: 8px; padding: 14px;">
              <label style="display: block; font-size: 13px; font-weight: 600; color: #2c333f; margin-bottom: 6px;">
                Seleccionar componente compatible:
              </label>

              <div v-if="isLoadingCatalog" style="padding: 12px; text-align: center; color: #6c7e9d; font-size: 13px;">
                ⏳ Cargando piezas compatibles con {{ machineModel }}...
              </div>

              <div v-else-if="compatibleParts.length === 0" style="padding: 12px; text-align: center; color: #78350f; background-color: #fffbeb; border-radius: 4px; font-size: 13px;">
                ⚠️ No hay repuestos catalogados para {{ machineModel }}. Usa la opción "Fuera de Catálogo".
              </div>

              <div v-else style="display: flex; flex-direction: column; gap: 10px;">
                <select
                  v-model="selectedPartIdToAdd"
                  style="width: 100%; min-height: 44px; font-size: 13px; padding: 0 10px; border: 1px solid #c8cfda; border-radius: 4px; background-color: #ffffff;"
                  data-testid="select-compatible-part"
                >
                  <option value="">-- Elige una pieza del catálogo --</option>
                  <option v-for="part in availablePartsForSelection" :key="part.id" :value="part.id">
                    {{ part.part_code }} · {{ part.name }} ({{ part.category_label || part.category }})
                  </option>
                </select>

                <!-- Stepper táctil móvil de cantidad (>= 44px) -->
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                  <span style="font-size: 13px; font-weight: 600; color: #2c333f;">Cantidad:</span>
                  <div style="display: flex; align-items: center; gap: 6px;">
                    <button
                      type="button"
                      style="min-width: 44px; min-height: 44px; font-size: 18px; font-weight: 700; background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;"
                      @click="stepQuantityToAdd(-1)"
                      :disabled="quantityToAdd <= 1"
                      data-testid="btn-decrement-add-qty"
                    >
                      -
                    </button>
                    <input
                      v-model.number="quantityToAdd"
                      type="number"
                      min="1"
                      max="50"
                      style="width: 54px; min-height: 44px; text-align: center; font-size: 16px; font-weight: 700; border: 1px solid #c8cfda; border-radius: 4px;"
                      data-testid="input-add-qty"
                    />
                    <button
                      type="button"
                      style="min-width: 44px; min-height: 44px; font-size: 18px; font-weight: 700; background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;"
                      @click="stepQuantityToAdd(1)"
                      :disabled="quantityToAdd >= 50"
                      data-testid="btn-increment-add-qty"
                    >
                      +
                    </button>
                  </div>

                  <button
                    type="button"
                    class="vg-btn"
                    style="min-height: 44px; font-size: 13px; font-weight: 600; background-color: #2560ff; color: #ffffff; border: none; border-radius: 4px; padding: 0 16px; cursor: pointer;"
                    @click="addSelectedPart"
                    :disabled="!selectedPartIdToAdd"
                    data-testid="btn-add-part-to-list"
                  >
                    + Añadir
                  </button>
                </div>
              </div>
            </div>

            <!-- Lista de piezas añadidas a la solicitud -->
            <div>
              <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #6c7e9d; margin-bottom: 6px;">
                Repuestos a Solicitar ({{ selectedParts.length }})
              </div>

              <div v-if="selectedParts.length === 0" style="padding: 16px; text-align: center; background-color: #f9fafb; border: 1px dashed #c8cfda; border-radius: 6px; font-size: 13px; color: #6c7e9d;" data-testid="empty-selected-parts-notice">
                No has añadido ningún repuesto todavía.
              </div>

              <div v-else style="display: flex; flex-direction: column; gap: 8px;">
                <div
                  v-for="(part, idx) in selectedParts"
                  :key="part.spare_part_id"
                  style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 6px; padding: 10px 12px; display: flex; align-items: center; justify-content: space-between; gap: 8px;"
                  :data-testid="'selected-part-row-' + part.spare_part_id"
                >
                  <div style="flex: 1 1 auto;">
                    <span style="font-family: monospace; font-weight: 700; color: #2560ff; font-size: 12px;">
                      {{ part.part_code }}
                    </span>
                    <div style="font-weight: 600; color: #2c333f; font-size: 13px;">{{ part.name }}</div>
                  </div>

                  <!-- Control táctil de cantidad de la fila -->
                  <div style="display: flex; align-items: center; gap: 4px;">
                    <button
                      type="button"
                      style="min-width: 44px; min-height: 44px; font-size: 16px; font-weight: 700; background-color: #f9fafb; border: 1px solid #c8cfda; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;"
                      @click="changePartQuantity(part, -1)"
                      :disabled="part.quantity <= 1"
                      :data-testid="'btn-part-decrement-' + part.spare_part_id"
                    >
                      -
                    </button>
                    <span style="min-width: 32px; text-align: center; font-weight: 700; font-size: 14px; color: #2c333f;" :data-testid="'part-qty-' + part.spare_part_id">
                      {{ part.quantity }}
                    </span>
                    <button
                      type="button"
                      style="min-width: 44px; min-height: 44px; font-size: 16px; font-weight: 700; background-color: #f9fafb; border: 1px solid #c8cfda; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;"
                      @click="changePartQuantity(part, 1)"
                      :disabled="part.quantity >= 50"
                      :data-testid="'btn-part-increment-' + part.spare_part_id"
                    >
                      +
                    </button>
                  </div>

                  <!-- Botón táctil para eliminar fila -->
                  <button
                    type="button"
                    style="min-width: 44px; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; background-color: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; border-radius: 4px; cursor: pointer; font-size: 16px;"
                    @click="removePart(idx)"
                    :data-testid="'btn-remove-part-' + part.spare_part_id"
                    title="Eliminar repuesto"
                  >
                    🗑️
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- MODO 2: PIEZA FUERA DE CATÁLOGO (RF-REP-04) -->
          <div v-else style="display: flex; flex-direction: column; gap: 10px;" data-testid="mode-out-of-catalog-section">
            <div style="background-color: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px; padding: 10px 12px; font-size: 12px; color: #92400e;">
              ℹ️ Estás solicitando una pieza especial no presente en catálogo. Esta solicitud quedará marcada para revisión por coordinación sin bloquear tu operativa.
            </div>

            <label style="display: block; font-size: 13px; font-weight: 600; color: #2c333f;">
              Justificación Técnica Obligatoria * (mínimo 20 caracteres)
            </label>
            <textarea
              v-model="customPartDescription"
              rows="4"
              placeholder="Describe detalladamente el componente, función, referencia visible o tolerancias mecánicas..."
              style="width: 100%; font-size: 13px; padding: 10px; border: 1px solid #c8cfda; border-radius: 4px; box-sizing: border-box; resize: vertical;"
              data-testid="textarea-out-of-catalog-description"
            ></textarea>
            <div style="display: flex; justify-content: space-between; font-size: 11px; color: #6c7e9d;">
              <span>Mínimo reglamentario: 20 caracteres</span>
              <span :style="{ color: customPartDescription.trim().length >= 20 ? '#38bd7d' : '#ff5757', fontWeight: '700' }">
                {{ customPartDescription.trim().length }} / 20
              </span>
            </div>
          </div>
        </div>

        <!-- Pie táctil móvil (Touch targets >= 44px) -->
        <div style="padding: 14px 18px; border-top: 1px solid #c8cfda; background-color: #f9fafb; display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
          <button
            type="button"
            class="vg-btn"
            style="min-height: 44px; font-size: 14px; font-weight: 600; background-color: #ffffff; color: #2c333f; border: 1px solid #c8cfda; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center;"
            @click="close"
            :disabled="isSubmitting"
            data-testid="btn-cancel-pause"
          >
            Cancelar
          </button>

          <button
            type="button"
            class="vg-btn"
            style="min-height: 44px; font-size: 14px; font-weight: 600; background-color: #f8b60f; color: #000000; border: none; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;"
            @click="submitPause"
            :disabled="!canSubmit || isSubmitting"
            data-testid="btn-confirm-pause"
          >
            <span v-if="isSubmitting">⏳ Pausando...</span>
            <span v-else>⏸️ Confirmar Pausa</span>
          </button>
        </div>
      </div>
    </div>
  `
};
