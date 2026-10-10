/**
 * VendGuard - TechnicianSanitaryResolutionBlock (TechnicianSanitaryResolutionBlock.js)
 *
 * Bloque táctil de declaraciones sanitarias obligatorias del cierre técnico sobre una
 * máquina en cuarentena o con el Reloj Sanitario Biológico vencido (RF-03.5.2, Art. II).
 *
 * Características:
 * 1. Registro obligatorio de la temperatura real del recinto térmico, con rango plausible.
 * 2. Confirmación positiva de retirada y destrucción del stock perecedero deteriorado.
 * 3. Confirmación positiva del checklist de higienización sanitaria.
 * 4. Touch targets >= 44 px y textos en castellano formal (RNF-03, RNF-04).
 *
 * Las tres declaraciones son actos positivos: ni la temperatura vacía ni una casilla
 * sin marcar dejan cerrar la avería. La máquina NO se desbloquea al declararlas —la
 * vuelta a `OK` pertenece a la reinspección reglamentaria del módulo 05—, pero el
 * expediente queda con la constancia sanitaria que exige el Art. II.
 *
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias externas).
 * Dualismo Lingüístico: Código y propiedades en inglés, textos e interfaz en español.
 */

import { SANITARY_TEMPERATURE_RANGE } from '../utils/IncidentStatusPermissions.js';

export const TechnicianSanitaryResolutionBlock = {
  name: 'TechnicianSanitaryResolutionBlock',
  props: {
    disabled: {
      type: Boolean,
      default: false
    }
  },
  emits: ['change'],
  data() {
    return {
      temperatureC: '',
      stockDestroyed: false,
      hygieneChecklist: false,
      // El rango plausible se declara una sola vez en el módulo compartido, para no
      // poder desincronizarse del validador del servidor.
      minTemperature: SANITARY_TEMPERATURE_RANGE.min,
      maxTemperature: SANITARY_TEMPERATURE_RANGE.max
    };
  },
  computed: {
    /**
     * Lectura térmica interpretada (acepta coma decimal de teclados móviles en español).
     */
    parsedTemperature() {
      if (this.temperatureC === '' || this.temperatureC === null) return null;
      const value = Number(String(this.temperatureC).replace(',', '.'));
      return Number.isFinite(value) ? value : null;
    },
    isTemperatureValid() {
      return this.parsedTemperature !== null
        && this.parsedTemperature >= this.minTemperature
        && this.parsedTemperature <= this.maxTemperature;
    },
    isValid() {
      return this.isTemperatureValid && this.stockDestroyed && this.hygieneChecklist;
    }
  },
  watch: {
    temperatureC() {
      this.emitChange();
    },
    stockDestroyed() {
      this.emitChange();
    },
    hygieneChecklist() {
      this.emitChange();
    }
  },
  methods: {
    /**
     * Valida el bloque completo y devuelve el contrato esperado por la resolución
     * (mismo patrón que el bloque de repuestos y el de saldo).
     */
    validate() {
      if (this.temperatureC === '' || this.temperatureC === null) {
        return {
          isValid: false,
          error: 'Registre la temperatura real del recinto térmico antes de cerrar una avería en cuarentena sanitaria (Art. II).'
        };
      }

      if (!this.isTemperatureValid) {
        return {
          isValid: false,
          error: `La temperatura declarada queda fuera del rango plausible [${this.minTemperature}, ${this.maxTemperature}] °C; revise la lectura del termómetro.`
        };
      }

      if (!this.stockDestroyed) {
        return {
          isValid: false,
          error: 'Confirme la retirada y destrucción del stock perecedero deteriorado por la rotura de frío.'
        };
      }

      if (!this.hygieneChecklist) {
        return {
          isValid: false,
          error: 'Confirme la ejecución del checklist de higienización sanitaria.'
        };
      }

      return {
        isValid: true,
        error: '',
        payload: { sanitary_declarations: this.getPayload() }
      };
    },

    /**
     * Payload normalizado conforme al contrato de resolución (RF-03.5.2).
     *
     * Se emite el estado REAL del bloque: mientras la lectura no sea válida viaja
     * como `null` —en lugar de inventar un número a partir de una cadena no
     * numérica— y cada confirmación refleja su casilla, no una constante. Sin esta
     * guarda, el vigilante reactivo que emite en cada pulsación reventaba con
     * `TypeError: Cannot read properties of null (reading 'toFixed')` al vaciar el
     * campo o al escribir una letra, y además declaraba hechas las dos
     * confirmaciones que el técnico todavía no había marcado.
     */
    getPayload() {
      return {
        temperature_c: this.parsedTemperature === null
          ? null
          : Number(this.parsedTemperature.toFixed(2)),
        stock_destroyed: this.stockDestroyed === true,
        hygiene_checklist: this.hygieneChecklist === true
      };
    },

    /**
     * Emite el estado reactivo al padre.
     */
    emitChange() {
      this.$emit('change', {
        isValid: this.isValid,
        ...this.getPayload()
      });
    }
  },
  template: `
    <div
      style="border: 1px solid var(--color-urgency-critical); background-color: var(--color-urgency-critical-bg); border-radius: 6px; padding: 12px; margin-bottom: 14px;"
      data-testid="sanitary-resolution-block"
    >
      <div style="display: flex; align-items: flex-start; gap: 8px; margin-bottom: 10px;">
        <span aria-hidden="true" style="font-size: 18px; line-height: 1;">🛡️</span>
        <div>
          <div style="font-size: 13px; font-weight: 700; color: var(--color-error-text);">
            Declaraciones sanitarias obligatorias (Art. II)
          </div>
          <div style="font-size: 12px; color: var(--color-slate); line-height: 1.4; margin-top: 2px;">
            La máquina está fuera de servicio por control higiénico-sanitario: la cuarentena
            sólo se levanta con la reinspección reglamentaria del módulo 05 tras declarar
            estos extremos. No se puede cerrar la avería sin ellos.
          </div>
        </div>
      </div>

      <!-- 1. Temperatura real del recinto térmico -->
      <div style="margin-bottom: 12px;">
        <label
          for="sanitary-temperature-input"
          style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 4px;"
        >
          1. Temperatura real del recinto térmico (°C) <span style="color: var(--color-urgency-critical);">*</span>
        </label>
        <input
          id="sanitary-temperature-input"
          type="text"
          inputmode="decimal"
          :value="temperatureC"
          @input="temperatureC = $event.target.value"
          :disabled="disabled"
          placeholder="Ej: 3,5"
          style="width: 100%; min-height: 44px; padding: 8px 10px; border: 1px solid var(--color-hairline); border-radius: 4px; font-size: 14px; box-sizing: border-box;"
          :style="{ borderColor: temperatureC !== '' && !isTemperatureValid ? 'var(--color-urgency-critical)' : 'var(--color-hairline)' }"
          data-testid="sanitary-temperature-input"
        />
        <div
          v-if="temperatureC !== '' && !isTemperatureValid"
          style="font-size: 11px; color: var(--color-urgency-critical); margin-top: 3px;"
        >
          Indique una lectura entre {{ minTemperature }} y {{ maxTemperature }} °C.
        </div>
      </div>

      <!-- 2. Retirada y destrucción del stock perecedero -->
      <label
        style="display: flex; align-items: center; gap: 10px; min-height: 44px; padding: 8px; background-color: var(--color-surface-card); border: 1px solid var(--color-urgency-critical); border-radius: 4px; margin-bottom: 8px; cursor: pointer;"
        data-testid="sanitary-stock-destroyed-label"
      >
        <input
          type="checkbox"
          :checked="stockDestroyed"
          @change="stockDestroyed = $event.target.checked"
          :disabled="disabled"
          style="width: 22px; height: 22px; accent-color: var(--color-error-text);"
          data-testid="sanitary-stock-destroyed-checkbox"
        />
        <span style="font-size: 13px; color: var(--color-slate); line-height: 1.35;">
          2. Confirmo la <strong>retirada y destrucción del stock perecedero</strong>
          deteriorado por la rotura de frío.
        </span>
      </label>

      <!-- 3. Checklist de higienización -->
      <label
        style="display: flex; align-items: center; gap: 10px; min-height: 44px; padding: 8px; background-color: var(--color-surface-card); border: 1px solid var(--color-urgency-critical); border-radius: 4px; cursor: pointer;"
        data-testid="sanitary-hygiene-checklist-label"
      >
        <input
          type="checkbox"
          :checked="hygieneChecklist"
          @change="hygieneChecklist = $event.target.checked"
          :disabled="disabled"
          style="width: 22px; height: 22px; accent-color: var(--color-error-text);"
          data-testid="sanitary-hygiene-checklist-checkbox"
        />
        <span style="font-size: 13px; color: var(--color-slate); line-height: 1.35;">
          3. Confirmo la <strong>ejecución del checklist de higienización sanitaria</strong>
          del recinto y las superficies de dispensación.
        </span>
      </label>
    </div>
  `
};
