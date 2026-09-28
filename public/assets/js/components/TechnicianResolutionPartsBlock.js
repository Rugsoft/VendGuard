/**
 * VendGuard - TechnicianResolutionPartsBlock (TechnicianResolutionPartsBlock.js)
 * 
 * Bloque táctil reutilizable para la declaración obligatoria de sustitución de componentes
 * al resolver incidencias o completar inspecciones preventivas (Módulo M2: RF-REP-05, RF-REP-06, RF-REP-07).
 * 
 * Características:
 * 1. Pregunta obligatoria Sí / No con pulsadores táctiles ergonómicos (touch targets >= 44x44px).
 * 2. Bloque condicional que exige al menos una pieza si la respuesta es afirmativa (RF-REP-05).
 * 3. Selector táctil de repuestos compatibles con la máquina y opción de pieza fuera de catálogo.
 * 4. Selector cerrado de destino del material retirado: DESGUACE o TALLER (RF-REP-06).
 * 5. Contador táctil de cantidades de 1 a 50 unidades con botones paso a paso.
 * 6. Cumplimiento de WCAG 2.1 AAA (RNF-REP-03) y diseño Docker (docs/design.md).
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias externas).
 * Dualismo Lingüístico: Código y propiedades en inglés, textos e interfaz en español.
 */

import { api } from '../api.js';

export const TechnicianResolutionPartsBlock = {
  name: 'TechnicianResolutionPartsBlock',
  props: {
    machineId: {
      type: [Number, String],
      required: true
    },
    machineModel: {
      type: String,
      default: ''
    },
    incidentId: {
      type: [Number, String],
      default: null
    },
    preventiveOrderId: {
      type: [Number, String],
      default: null
    },
    disabled: {
      type: Boolean,
      default: false
    }
  },
  emits: ['update:modelValue', 'change'],
  data() {
    return {
      hasReplacedParts: null, // null (sin responder) | true (Sí) | false (No)
      compatibleParts: [],
      isLoadingCatalog: false,
      replacedParts: [], // Array de piezas añadidas a la resolución
      isAddingOutOfCatalog: false,
      selectedPartIdToAdd: '',
      customPartNameToAdd: '',
      quantityToAdd: 1,
      destinationToAdd: 'DESGUACE', // 'DESGUACE' | 'TALLER'
      notesToAdd: '',
      catalogError: ''
    };
  },
  computed: {
    isAnswered() {
      return this.hasReplacedParts !== null;
    },
    isValid() {
      if (this.hasReplacedParts === null) return false;
      if (this.hasReplacedParts === false) return true;
      return this.replacedParts.length > 0;
    },
    totalReplacedUnits() {
      return this.replacedParts.reduce((acc, p) => acc + (parseInt(p.quantity, 10) || 0), 0);
    },
    estimatedTotalCost() {
      return this.replacedParts.reduce((acc, p) => {
        const cost = parseFloat(p.reference_cost) || 0;
        const qty = parseInt(p.quantity, 10) || 0;
        return acc + (cost * qty);
      }, 0);
    }
  },
  watch: {
    machineId(newVal) {
      if (newVal) {
        this.loadCatalog();
      }
    }
  },
  mounted() {
    this.loadCatalog();
  },
  methods: {
    /**
     * Carga el catálogo de piezas compatibles con la máquina asignada.
     */
    async loadCatalog() {
      if (!this.machineId) return;

      this.isLoadingCatalog = true;
      this.catalogError = '';
      try {
        const res = await api.technician.getSparePartsCatalog(this.machineId, this.incidentId);
        const data = res?.data || res || [];
        this.compatibleParts = Array.isArray(data) ? data : (data.compatible_parts || []);
      } catch (err) {
        this.catalogError = err.message || 'Error al precargar piezas compatibles.';
      } finally {
        this.isLoadingCatalog = false;
      }
    },

    /**
     * Establece la respuesta obligatoria a la pregunta de sustitución (Sí / No).
     */
    setReplacedDeclaration(value) {
      if (this.disabled) return;

      this.hasReplacedParts = value;
      if (!value) {
        this.replacedParts = [];
      }
      this.emitChange();
    },

    /**
     * Alterna entre añadir pieza de catálogo o fuera de catálogo.
     */
    toggleAddOutOfCatalog(active) {
      this.isAddingOutOfCatalog = active;
      this.selectedPartIdToAdd = '';
      this.customPartNameToAdd = '';
    },

    /**
     * Ajusta la cantidad temporal para añadir.
     */
    stepQuantityToAdd(delta) {
      const next = this.quantityToAdd + delta;
      if (next >= 1 && next <= 50) {
        this.quantityToAdd = next;
      }
    },

    /**
     * Ajusta la cantidad de una pieza ya en la lista.
     */
    stepItemQuantity(item, delta) {
      if (this.disabled) return;
      const next = item.quantity + delta;
      if (next >= 1 && next <= 50) {
        item.quantity = next;
        this.emitChange();
      }
    },

    /**
     * Cambia el destino logístico de un componente retirado (DESGUACE o TALLER).
     */
    setItemDestination(item, destination) {
      if (this.disabled) return;
      item.old_part_destination = destination;
      this.emitChange();
    },

    /**
     * Añade una pieza a la lista de componentes sustituidos en la intervención.
     */
    addReplacedPart() {
      if (this.disabled) return;

      if (this.isAddingOutOfCatalog) {
        const customName = (this.customPartNameToAdd || '').trim();
        if (!customName || customName.length < 3) {
          return;
        }

        this.replacedParts.push({
          spare_part_id: null,
          is_out_of_catalog: true,
          custom_part_name: customName,
          part_code: 'OUT_OF_CATALOG',
          part_name: customName,
          quantity: this.quantityToAdd,
          old_part_destination: this.destinationToAdd,
          notes: (this.notesToAdd || '').trim() || null,
          reference_cost: 0.00
        });
      } else {
        if (!this.selectedPartIdToAdd) return;

        const part = this.compatibleParts.find(p => p.id === Number(this.selectedPartIdToAdd));
        if (!part) return;

        this.replacedParts.push({
          spare_part_id: part.id,
          is_out_of_catalog: false,
          custom_part_name: null,
          part_code: part.part_code,
          part_name: part.name,
          category: part.category,
          quantity: this.quantityToAdd,
          old_part_destination: this.destinationToAdd,
          notes: (this.notesToAdd || '').trim() || null,
          reference_cost: parseFloat(part.reference_cost) || 0.00
        });
      }

      // Reiniciar controles de añadir
      this.selectedPartIdToAdd = '';
      this.customPartNameToAdd = '';
      this.quantityToAdd = 1;
      this.notesToAdd = '';
      this.emitChange();
    },

    /**
     * Elimina una pieza de la lista de sustituciones.
     */
    removePart(index) {
      if (this.disabled) return;
      this.replacedParts.splice(index, 1);
      this.emitChange();
    },

    /**
     * Construye y devuelve el payload estructurado conforme al contrato 5.2 / 5.4.
     */
    getPayload() {
      return {
        replaced_parts_declared: this.hasReplacedParts === true,
        replaced_parts: this.hasReplacedParts === true
          ? this.replacedParts.map(p => ({
              spare_part_id: p.is_out_of_catalog ? null : Number(p.spare_part_id),
              is_out_of_catalog: Boolean(p.is_out_of_catalog),
              custom_part_name: p.is_out_of_catalog ? p.custom_part_name : null,
              quantity: Number(p.quantity),
              old_part_destination: p.old_part_destination,
              notes: p.notes ? p.notes.trim() : null
            }))
          : []
      };
    },

    /**
     * Valida el estado actual y retorna un resultado con mensaje explicativo en español.
     */
    validate() {
      if (this.hasReplacedParts === null) {
        return {
          isValid: false,
          error: 'Debe responder obligatoriamente si la intervención conllevó sustitución física de componentes (campo Sí / No).'
        };
      }

      if (this.hasReplacedParts === true && this.replacedParts.length === 0) {
        return {
          isValid: false,
          error: 'Ha indicado que hubo sustitución de componentes: debe registrar al menos una pieza instalada.'
        };
      }

      return {
        isValid: true,
        error: '',
        payload: this.getPayload()
      };
    },

    /**
     * Emite los eventos reactivos al padre.
     */
    emitChange() {
      const payload = this.getPayload();
      this.$emit('update:modelValue', payload);
      this.$emit('change', {
        isValid: this.isValid,
        hasReplacedParts: this.hasReplacedParts,
        ...payload
      });
    },

    /**
     * Formatea un valor numérico como euros.
     */
    formatPrice(amount) {
      const num = parseFloat(amount);
      if (isNaN(num)) return '0,00 €';
      return num.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }
  },
  template: `
    <div
      class="technician-resolution-parts-block"
      style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 16px; margin-bottom: 16px;"
      data-testid="technician-resolution-parts-block"
    >
      <!-- Encabezado del bloque -->
      <div style="margin-bottom: 12px;">
        <label style="display: block; font-size: 13px; font-weight: 700; color: #2c333f; margin-bottom: 4px;">
          ⚙️ Sustitución de Componentes Físicos (RF-REP-05) *
        </label>
        <p style="margin: 0; font-size: 12px; color: #6c7e9d; line-height: 1.4;">
          ¿Durante esta intervención se instaló o reemplazó algún componente técnico en la máquina?
        </p>
      </div>

      <!-- Pregunta Obligatoria Sí / No con Touch Targets >= 44px (RNF-REP-03) -->
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px;" data-testid="radio-replacement-declaration">
        <!-- Botón Sí -->
        <button
          type="button"
          :style="{
            minHeight: '48px',
            fontSize: '14px',
            fontWeight: '700',
            padding: '10px 14px',
            borderRadius: '4px',
            border: '2px solid',
            cursor: disabled ? 'not-allowed' : 'pointer',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: '8px',
            borderColor: hasReplacedParts === true ? '#2560ff' : '#c8cfda',
            backgroundColor: hasReplacedParts === true ? '#e5f2fc' : '#ffffff',
            color: hasReplacedParts === true ? '#003db5' : '#2c333f',
            opacity: disabled ? 0.6 : 1
          }"
          @click="setReplacedDeclaration(true)"
          data-testid="btn-declared-yes"
        >
          <span>✅ Sí, hubo sustitución</span>
        </button>

        <!-- Botón No -->
        <button
          type="button"
          :style="{
            minHeight: '48px',
            fontSize: '14px',
            fontWeight: '700',
            padding: '10px 14px',
            borderRadius: '4px',
            border: '2px solid',
            cursor: disabled ? 'not-allowed' : 'pointer',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: '8px',
            borderColor: hasReplacedParts === false ? '#38bd7d' : '#c8cfda',
            backgroundColor: hasReplacedParts === false ? '#ecfdf5' : '#ffffff',
            color: hasReplacedParts === false ? '#065f46' : '#2c333f',
            opacity: disabled ? 0.6 : 1
          }"
          @click="setReplacedDeclaration(false)"
          data-testid="btn-declared-no"
        >
          <span>❎ No se cambiaron piezas</span>
        </button>
      </div>

      <!-- Feedback si no se ha respondido -->
      <div
        v-if="hasReplacedParts === null"
        style="padding: 10px 12px; background-color: #fffbeb; border: 1px dashed #fcd34d; border-radius: 4px; font-size: 12px; color: #92400e; margin-bottom: 8px;"
        data-testid="unanswered-alert"
      >
        ⚠️ Respuesta obligatoria antes de poder confirmar la resolución.
      </div>

      <!-- DESPLIEGUE CONDICIONAL CUANDO hasReplacedParts === true -->
      <div v-if="hasReplacedParts === true" style="display: flex; flex-direction: column; gap: 14px; border-top: 1px solid #e5e7eb; padding-top: 14px;" data-testid="replacement-details-section">
        <!-- Formulario táctil para añadir repuesto instalado -->
        <div style="background-color: #f9fafb; border: 1px solid #c8cfda; border-radius: 6px; padding: 14px;">
          <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
            <span style="font-size: 13px; font-weight: 700; color: #2c333f;">
              Añadir componente instalado:
            </span>
            <!-- Toggle Catálogo vs Fuera de Catálogo -->
            <button
              type="button"
              style="font-size: 11px; font-weight: 600; color: #2560ff; background: none; border: none; cursor: pointer; text-decoration: underline;"
              @click="toggleAddOutOfCatalog(!isAddingOutOfCatalog)"
              data-testid="btn-toggle-out-of-catalog"
            >
              {{ isAddingOutOfCatalog ? '← Ver piezas de catálogo' : '+ Pieza fuera de catálogo' }}
            </button>
          </div>

          <!-- Selector de pieza de catálogo -->
          <div v-if="!isAddingOutOfCatalog" style="margin-bottom: 10px;">
            <select
              v-model="selectedPartIdToAdd"
              style="width: 100%; min-height: 44px; font-size: 13px; padding: 0 10px; border: 1px solid #c8cfda; border-radius: 4px; background-color: #ffffff;"
              data-testid="select-replaced-part"
            >
              <option value="">-- Selecciona el repuesto instalado --</option>
              <option v-for="part in compatibleParts" :key="part.id" :value="part.id">
                {{ part.part_code }} · {{ part.name }} ({{ formatPrice(part.reference_cost) }})
              </option>
            </select>
          </div>

          <!-- Input de texto para pieza fuera de catálogo -->
          <div v-else style="margin-bottom: 10px;">
            <input
              v-model="customPartNameToAdd"
              type="text"
              placeholder="Denominación técnica de la pieza fuera de catálogo..."
              style="width: 100%; min-height: 44px; font-size: 13px; padding: 0 10px; border: 1px solid #c8cfda; border-radius: 4px; box-sizing: border-box;"
              data-testid="input-custom-part-name"
            />
          </div>

          <!-- Selector táctil de Destino y Cantidad -->
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
            <!-- Destino del material retirado (RF-REP-06: DESGUACE o TALLER) -->
            <div>
              <label style="display: block; font-size: 11px; font-weight: 600; color: #6c7e9d; margin-bottom: 4px;">
                Destino pieza retirada *
              </label>
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px;">
                <button
                  type="button"
                  :style="{
                    minHeight: '44px',
                    fontSize: '12px',
                    fontWeight: '600',
                    borderRadius: '4px',
                    border: '1px solid',
                    cursor: 'pointer',
                    borderColor: destinationToAdd === 'DESGUACE' ? '#ef4444' : '#c8cfda',
                    backgroundColor: destinationToAdd === 'DESGUACE' ? '#fee2e2' : '#ffffff',
                    color: destinationToAdd === 'DESGUACE' ? '#991b1b' : '#2c333f'
                  }"
                  @click="destinationToAdd = 'DESGUACE'"
                  data-testid="btn-destination-desguace"
                >
                  🗑️ Desguace
                </button>
                <button
                  type="button"
                  :style="{
                    minHeight: '44px',
                    fontSize: '12px',
                    fontWeight: '600',
                    borderRadius: '4px',
                    border: '1px solid',
                    cursor: 'pointer',
                    borderColor: destinationToAdd === 'TALLER' ? '#f8b60f' : '#c8cfda',
                    backgroundColor: destinationToAdd === 'TALLER' ? '#fef3c7' : '#ffffff',
                    color: destinationToAdd === 'TALLER' ? '#92400e' : '#2c333f'
                  }"
                  @click="destinationToAdd = 'TALLER'"
                  data-testid="btn-destination-taller"
                >
                  🔧 Taller
                </button>
              </div>
            </div>

            <!-- Cantidad con Stepper Táctil (>= 44px) -->
            <div>
              <label style="display: block; font-size: 11px; font-weight: 600; color: #6c7e9d; margin-bottom: 4px;">
                Cantidad instalada *
              </label>
              <div style="display: flex; align-items: center; gap: 4px;">
                <button
                  type="button"
                  style="min-width: 44px; min-height: 44px; font-size: 18px; font-weight: 700; background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;"
                  @click="stepQuantityToAdd(-1)"
                  :disabled="quantityToAdd <= 1"
                  data-testid="btn-decrement-qty"
                >
                  -
                </button>
                <input
                  v-model.number="quantityToAdd"
                  type="number"
                  min="1"
                  max="50"
                  style="flex: 1 1 auto; min-height: 44px; text-align: center; font-size: 15px; font-weight: 700; border: 1px solid #c8cfda; border-radius: 4px; width: 40px;"
                  data-testid="input-qty"
                />
                <button
                  type="button"
                  style="min-width: 44px; min-height: 44px; font-size: 18px; font-weight: 700; background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;"
                  @click="stepQuantityToAdd(1)"
                  :disabled="quantityToAdd >= 50"
                  data-testid="btn-increment-qty"
                >
                  +
                </button>
              </div>
            </div>
          </div>

          <!-- Botón Añadir Componente (>= 44px) -->
          <button
            type="button"
            class="vg-btn"
            style="width: 100%; min-height: 44px; font-size: 13px; font-weight: 700; background-color: #2560ff; color: #ffffff; border: none; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;"
            @click="addReplacedPart"
            :disabled="(!isAddingOutOfCatalog && !selectedPartIdToAdd) || (isAddingOutOfCatalog && !customPartNameToAdd.trim())"
            data-testid="btn-add-replaced-part"
          >
            <span>+ Registrar Pieza Sustituida</span>
          </button>
        </div>

        <!-- Lista de piezas efectivamente registradas -->
        <div>
          <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
            <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #6c7e9d;">
              Componentes Instalados ({{ replacedParts.length }})
            </span>
            <span v-if="replacedParts.length > 0" style="font-size: 12px; font-weight: 600; color: #2560ff;">
              Total: {{ totalReplacedUnits }} ud(s) · Est. {{ formatPrice(estimatedTotalCost) }}
            </span>
          </div>

          <!-- Aviso si no ha añadido ninguna pieza -->
          <div
            v-if="replacedParts.length === 0"
            style="padding: 14px; text-align: center; background-color: #fffbeb; border: 1px dashed #fcd34d; border-radius: 6px; font-size: 13px; color: #92400e;"
            data-testid="no-parts-added-warning"
          >
            ⚠️ Has marcado que sí hubo sustitución: debes añadir al menos una pieza para poder resolver.
          </div>

          <!-- Fichas de piezas añadidas -->
          <div v-else style="display: flex; flex-direction: column; gap: 8px;">
            <div
              v-for="(part, idx) in replacedParts"
              :key="idx"
              style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 6px; padding: 10px 12px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px;"
              :data-testid="'replaced-part-item-' + idx"
            >
              <div style="flex: 1 1 200px;">
                <div style="display: flex; align-items: center; gap: 6px;">
                  <span style="font-family: monospace; font-weight: 700; font-size: 12px; color: #2560ff;">
                    {{ part.part_code }}
                  </span>
                  <span
                    style="font-size: 11px; font-weight: 600; padding: 2px 6px; border-radius: 4px;"
                    :style="part.old_part_destination === 'DESGUACE' ? 'background-color: #fee2e2; color: #991b1b;' : 'background-color: #fef3c7; color: #92400e;'"
                  >
                    Destino: {{ part.old_part_destination }}
                  </span>
                </div>
                <div style="font-size: 13px; font-weight: 600; color: #2c333f; margin-top: 2px;">
                  {{ part.part_name }}
                </div>
              </div>

              <!-- Stepper táctil para cada fila -->
              <div style="display: flex; align-items: center; gap: 6px;">
                <button
                  type="button"
                  style="min-width: 44px; min-height: 44px; font-size: 16px; font-weight: 700; background-color: #f9fafb; border: 1px solid #c8cfda; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;"
                  @click="stepItemQuantity(part, -1)"
                  :disabled="part.quantity <= 1 || disabled"
                  :data-testid="'btn-item-decrement-' + idx"
                >
                  -
                </button>
                <span style="min-width: 28px; text-align: center; font-weight: 700; font-size: 14px;" :data-testid="'item-qty-' + idx">
                  {{ part.quantity }}
                </span>
                <button
                  type="button"
                  style="min-width: 44px; min-height: 44px; font-size: 16px; font-weight: 700; background-color: #f9fafb; border: 1px solid #c8cfda; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;"
                  @click="stepItemQuantity(part, 1)"
                  :disabled="part.quantity >= 50 || disabled"
                  :data-testid="'btn-item-increment-' + idx"
                >
                  +
                </button>

                <!-- Eliminar fila (>= 44px) -->
                <button
                  type="button"
                  style="min-width: 44px; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; background-color: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; border-radius: 4px; cursor: pointer; font-size: 16px;"
                  @click="removePart(idx)"
                  :disabled="disabled"
                  :data-testid="'btn-remove-replaced-part-' + idx"
                  title="Eliminar pieza"
                >
                  🗑️
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
};
