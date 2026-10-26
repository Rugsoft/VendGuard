/**
 * VendGuard - TechnicianVanStockPanel Component (TechnicianVanStockPanel.js)
 *
 * Inventario de repuestos de la furgoneta del técnico autenticado (Módulo 18,
 * RF-04.1) y recepción móvil de la orden de carga (Módulo 23, RF-05.1 a RF-05.3).
 *
 * QUÉ PINTA Y QUÉ NO
 *
 * Pinta lo que el contrato §4.1 devuelve: código, nombre, categoría, cantidad
 * disponible y la insignia de bajo umbral. **No** pinta el umbral mínimo —no es
 * un dato de la vista del técnico, RF-04.1 lo excluye expresamente— ni ningún
 * importe: el coste del repuesto vive congelado en la línea de la avería y la
 * sede no lo ve, así que la furgoneta tampoco (Art. V.4, RD-05).
 *
 * RECEPCIÓN EN UN TOQUE
 *
 * Cuando el servidor publica `active_dispatched_order`, la tarjeta destacada
 * muestra el albarán pendiente y el botón principal confirma la recepción. Es un
 * único toque, sin consultar ni exigir turno (QA 4), y el servidor aísla la
 * operación al `user_id` del token (Art. V.4). Tras confirmar, `loadInventory()`
 * trae el saldo actualizado y el comprobante `last_confirmed_order`.
 *
 * EL DESCUADRE NO SE ETIQUETA AQUÍ
 *
 * La proyección del técnico no transporta `is_negative` a propósito: el servidor
 * decide qué ve cada público y el técnico ve su cantidad, incluido un negativo,
 * sin la etiqueta de descuadre que la Coordinación usa para triaje (RF-03.3).
 *
 * DISEÑO
 *
 * Tokens del sistema (`var(--color-…)`, `var(--radius-…)`) y ni un literal
 * hexadecimal: la deuda cromática está congelada por
 * `DesignTokenDebtRatchetTest`. Tarjetas de 8 px, chips de 4 px y objetivo táctil
 * de 44 px en la acción principal, porque esta vista se usa de pie frente a la
 * máquina.
 *
 * Dogma Vanilla: Vue 3 Options API con plantilla en navegador, sin bundler ni
 * dependencias.
 * Dualismo Lingüístico: código en inglés, texto de interfaz en castellano.
 */

import { api } from '../api.js';
import { formatDateTime } from '../utils/DateTimeFormat.js';

export const TechnicianVanStockPanel = {
  name: 'TechnicianVanStockPanel',

  data() {
    return {
      technicianId: null,
      items: [],
      activeDispatchedOrder: null,
      lastConfirmedOrder: null,
      isLoading: false,
      errorMessage: '',
      receptionNote: '',
      isConfirming: false,
      confirmError: '',
      confirmMessage: ''
    };
  },

  computed: {
    /** Referencias distintas que la furgoneta lleva declaradas. */
    referenceCount() {
      return this.items.length;
    },

    /** Suma de unidades: un negativo resta, que es justo lo que debe verse. */
    totalUnits() {
      return this.items.reduce((sum, item) => sum + Number(item.quantity_available || 0), 0);
    },

    /** Parejas que la Coordinación tiene que reponer (RF-03.1). */
    alertCount() {
      return this.items.filter((item) => item.is_below_threshold === true).length;
    },

    isEmpty() {
      return !this.isLoading && this.items.length === 0;
    },

    /** La orden despachada pendiente de recepción (QA 5). */
    hasActiveOrder() {
      return this.activeDispatchedOrder !== null
        && this.activeDispatchedOrder.id !== undefined
        && this.activeDispatchedOrder.id !== null;
    },

    /** Unidades que el técnico va a recibir físicamente al confirmar. */
    activeOrderUnits() {
      if (!this.hasActiveOrder) {
        return 0;
      }

      const reported = Number(this.activeDispatchedOrder.total_dispatched_units);
      if (Number.isFinite(reported)) {
        return reported;
      }

      return (this.activeDispatchedOrder.items || []).reduce(
        (total, line) => total + Number(line.dispatched_qty || 0),
        0
      );
    },

    /** Las tres cifras de cabecera, ya resueltas para no calcular en la plantilla. */
    kpis() {
      return [
        { key: 'references', label: 'Referencias', value: this.referenceCount },
        { key: 'units', label: 'Unidades', value: this.totalUnits },
        { key: 'alerts', label: 'Por reponer', value: this.alertCount }
      ];
    },

    /** Lectura para el técnico: explica el estado vacío en vez de dejarlo mudo (RF-04.3). */
    emptyMessage() {
      return 'Todavía no hay stock declarado para tu furgoneta. Coordinación es quien carga las '
        + 'cantidades en el sistema; si necesitas repuestos, avisa antes de salir a ruta.';
    }
  },

  methods: {
    async loadInventory() {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        const response = await api.technician.getVanStock();
        const data = response?.data || response || {};
        this.technicianId = data.technician_id ?? null;
        this.items = Array.isArray(data.items) ? data.items : [];
        this.activeDispatchedOrder = data.active_dispatched_order || null;
        this.lastConfirmedOrder = data.last_confirmed_order || null;
      } catch (error) {
        this.errorMessage = error.message || 'No se pudo cargar el stock de tu furgoneta.';
        this.items = [];
        this.activeDispatchedOrder = null;
        this.lastConfirmedOrder = null;
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Confirma en un toque la carga expedida. El endpoint usa el sujeto del token:
     * la petición no acepta ni envía un identificador de técnico (Art. V.4).
     */
    async confirmActiveOrder() {
      if (!this.hasActiveOrder || this.isConfirming) {
        return;
      }

      this.isConfirming = true;
      this.confirmError = '';
      this.confirmMessage = '';

      try {
        const payloadToSend = {};
        if (this.receptionNote && this.receptionNote.trim()) {
          payloadToSend.reception_note = this.receptionNote.trim();
        }
        const response = await api.technician.confirmVanRestockOrder(this.activeDispatchedOrder.id, payloadToSend);
        const payload = response?.data || response || {};
        this.confirmMessage = payload.message
          || 'Carga confirmada. El stock de tu furgoneta ya está actualizado.';
        this.receptionNote = '';

        await this.loadInventory();
      } catch (error) {
        this.confirmError = error.message || 'No se pudo confirmar la recepción de la carga.';
      } finally {
        this.isConfirming = false;
      }
    },

    /** Categorías legibles: el enum del catálogo no se enseña en crudo al técnico. */
    categoryLabel(category) {
      const labels = {
        HYDRAULIC: 'Hidráulica',
        THERMAL: 'Térmica',
        ELECTRONIC: 'Electrónica',
        MECHANICAL: 'Mecánica',
        CONSUMABLE: 'Consumible',
        OTHER: 'Otra'
      };

      return labels[String(category || '').toUpperCase()] || String(category || '');
    },

    formatDateTime(value) {
      return formatDateTime(value);
    }
  },

  mounted() {
    this.loadInventory();
  },

  template: `
    <div class="technician-van-stock-panel" data-testid="tech-van-stock-panel" style="display: flex; flex-direction: column; gap: 12px;">

      <!-- Error recuperable: el técnico puede reintentar sin recargar la vista entera -->
      <div
        v-if="errorMessage"
        role="alert"
        data-testid="tech-van-stock-error"
        style="display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); border-radius: var(--radius-card); color: var(--color-error-text); font-family: var(--font-body); font-size: var(--font-size-body-sm);"
      >
        <span>⚠️ {{ errorMessage }}</span>
        <button
          type="button"
          class="vg-btn vg-btn-secondary"
          style="min-height: 44px;"
          @click="loadInventory"
        >
          Reintentar
        </button>
      </div>

      <div
        v-if="isLoading"
        data-testid="tech-van-stock-loading"
        style="padding: 16px; color: var(--color-ink-muted); font-family: var(--font-body); font-size: var(--font-size-body-sm);"
      >
        Cargando el stock de tu furgoneta…
      </div>

      <template v-else>
        <!-- Cifras de cabecera: cuántas referencias, cuántas unidades y cuántas hay que reponer -->
        <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px;">
          <div
            v-for="kpi in kpis"
            :key="kpi.key"
            :data-testid="'tech-van-stock-kpi-' + kpi.key"
            style="padding: 12px; background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card);"
          >
            <div style="font-family: var(--font-display); font-size: var(--font-size-heading-md); font-weight: 700; color: var(--color-ink); line-height: 1.1;">
              {{ kpi.value }}
            </div>
            <div style="margin-top: 2px; font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
              {{ kpi.label }}
            </div>
          </div>
        </div>

        <!-- Carga expedida pendiente de recepción: la tarjeta que el técnico ve al llegar a almacén -->
        <section
          v-if="hasActiveOrder"
          data-testid="tech-van-stock-active-order"
          style="padding: 14px; background-color: var(--color-primary-subtle); border: 1px solid var(--color-primary); border-radius: var(--radius-card);"
        >
          <div style="display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 10px;">
            <div>
              <div style="font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: var(--color-primary-dark);">
                📦 Carga pendiente de recibir
              </div>
              <div style="margin-top: 2px; font-family: var(--font-display); font-size: var(--font-size-heading-md); font-weight: 700; color: var(--color-ink);">
                {{ activeDispatchedOrder.order_code }}
              </div>
            </div>
            <span class="vg-chip vg-chip-warning">Expedida</span>
          </div>

          <div style="display: flex; flex-wrap: wrap; gap: 16px; margin-top: 8px; font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-ink-secondary);">
            <span>Expedida: <strong>{{ formatDateTime(activeDispatchedOrder.dispatched_at) }}</strong></span>
            <span>Por: <strong>{{ activeDispatchedOrder.dispatched_by_name || '—' }}</strong></span>
            <span data-testid="tech-van-stock-active-units">Unidades: <strong>{{ activeOrderUnits }}</strong></span>
          </div>

          <ul data-testid="tech-van-stock-active-order-items" style="list-style: none; margin: 10px 0 0; padding: 0; display: flex; flex-direction: column; gap: 6px;">
            <li
              v-for="line in (activeDispatchedOrder.items || [])"
              :key="line.spare_part_id"
              :data-testid="'tech-van-stock-active-line-' + line.spare_part_id"
              style="display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 7px 9px; background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: var(--font-size-body-sm);"
            >
              <span style="min-width: 0;">
                <strong style="font-family: monospace; font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ line.part_code }}</strong>
                <span style="display: block; color: var(--color-ink);">{{ line.part_name }}</span>
              </span>
              <strong style="font-family: var(--font-display); font-size: var(--font-size-heading-md); color: var(--color-ink); white-space: nowrap;">
                {{ line.dispatched_qty }} u.
              </strong>
            </li>
          </ul>

          <p v-if="activeDispatchedOrder.notes" style="margin: 8px 0 0; font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-secondary);">
            Nota de almacén: {{ activeDispatchedOrder.notes }}
          </p>

          <div style="margin-top: 10px;">
            <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
              <span>Nota de recepción opcional <small style="font-weight: 400;">({{ (receptionNote || '').length }}/500)</small></span>
              <textarea
                v-model="receptionNote"
                maxlength="500"
                rows="2"
                class="vg-input vg-textarea"
                placeholder="Observación al recibir el material (opcional)"
                data-testid="tech-van-stock-reception-note"
              ></textarea>
            </label>
          </div>
        </section>

        <!-- Comprobante de la última carga confirmada (QA 5) -->
        <section
          v-if="lastConfirmedOrder"
          data-testid="tech-van-stock-last-confirmed"
          style="padding: 12px 14px; background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card);"
        >
          <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px;">
            <div style="font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: var(--color-ink-muted);">
              🧾 Última recepción confirmada
            </div>
            <span style="font-family: monospace; font-size: var(--font-size-caption); font-weight: 700; color: var(--color-ink-secondary);">
              {{ lastConfirmedOrder.order_code }}
            </span>
          </div>
          <div style="margin-top: 4px; font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-ink-secondary);">
            {{ formatDateTime(lastConfirmedOrder.confirmed_at) }} · <strong>{{ lastConfirmedOrder.total_received_units }}</strong> unidades recibidas
          </div>
          <p v-if="lastConfirmedOrder.reception_note" data-testid="tech-van-stock-last-reception-note" style="margin: 6px 0 0; font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted); font-style: italic;">
            Nota del técnico: "{{ lastConfirmedOrder.reception_note }}"
          </p>
          <ul data-testid="tech-van-stock-last-confirmed-items" style="list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-direction: column; gap: 4px;">
            <li
              v-for="line in (lastConfirmedOrder.items || [])"
              :key="line.spare_part_id"
              style="display: flex; align-items: center; justify-content: space-between; gap: 10px; font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-secondary);"
            >
              <span>{{ line.part_code }} · {{ line.part_name }}</span>
              <strong style="color: var(--color-ink);">{{ line.dispatched_qty }} u.</strong>
            </li>
          </ul>
        </section>

        <!-- Estado vacío explicativo (RF-04.3): una furgoneta sin declarar no es un error -->
        <div
          v-if="isEmpty"
          data-testid="tech-van-stock-empty"
          style="padding: 16px; background-color: var(--color-surface-2); border: 1px dashed var(--color-hairline); border-radius: var(--radius-card); color: var(--color-ink-muted); font-family: var(--font-body); font-size: var(--font-size-body-sm); line-height: 1.5;"
        >
          {{ emptyMessage }}
        </div>

        <!-- Inventario: una tarjeta por pieza, pensada para leerse de pie junto a la máquina -->
        <ul
          v-else
          data-testid="tech-van-stock-list"
          style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px;"
        >
          <li
            v-for="item in items"
            :key="item.spare_part_id"
            :data-testid="'tech-van-stock-item-' + item.spare_part_id"
            style="padding: 12px 14px; background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card);"
          >
            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;">
              <div style="min-width: 0;">
                <span
                  style="display: inline-block; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-neutral-bg); border: 1px solid var(--color-neutral-border); color: var(--color-ink-slate); font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 700; letter-spacing: 0.02em;"
                >{{ item.part_code }}</span>
                <div style="margin-top: 6px; font-family: var(--font-body); font-size: var(--font-size-body-md); font-weight: 600; color: var(--color-ink); line-height: 1.3;">
                  {{ item.name }}
                </div>
                <div style="margin-top: 2px; font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                  {{ categoryLabel(item.category) }}
                </div>
              </div>

              <div style="text-align: right; flex-shrink: 0;">
                <div
                  :data-testid="'tech-van-stock-quantity-' + item.spare_part_id"
                  style="font-family: var(--font-display); font-size: var(--font-size-heading-lg); font-weight: 700; color: var(--color-ink); line-height: 1;"
                >{{ item.quantity_available }}</div>
                <div style="font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                  u.
                </div>
              </div>
            </div>

            <div
              v-if="item.is_below_threshold"
              data-testid="tech-van-stock-alert"
              style="margin-top: 10px; display: inline-flex; align-items: center; gap: 6px; padding: 3px 8px; border-radius: var(--radius-interactive); background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 700;"
            >
              <span aria-hidden="true">▼</span>
              Reponer
            </div>
          </li>
        </ul>

        <div
          v-if="confirmError"
          role="alert"
          data-testid="tech-van-stock-confirm-error"
          style="padding: 10px 12px; background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); border-radius: var(--radius-card); color: var(--color-error-text); font-family: var(--font-body); font-size: var(--font-size-body-sm);"
        >
          ⚠️ {{ confirmError }}
        </div>

        <div
          v-if="confirmMessage"
          data-testid="tech-van-stock-confirm-success"
          style="padding: 10px 12px; background-color: var(--color-success-bg); border: 1px solid var(--color-success); border-radius: var(--radius-card); color: var(--color-success-text); font-family: var(--font-body); font-size: var(--font-size-body-sm);"
        >
          ✅ {{ confirmMessage }}
        </div>

        <!-- Acción principal: confirmar la carga si está pendiente; si no, releer el inventario -->
        <button
          type="button"
          class="vg-btn"
          :class="hasActiveOrder ? 'vg-btn-primary' : 'vg-btn-secondary'"
          style="min-height: 44px; align-self: flex-start;"
          data-testid="tech-van-stock-primary-action"
          :disabled="isConfirming"
          @click="hasActiveOrder ? confirmActiveOrder() : loadInventory()"
        >
          <template v-if="hasActiveOrder">
            {{ isConfirming ? 'Confirmando…' : ('📦 Confirmar recepción (' + activeOrderUnits + ' u.)') }}
          </template>
          <template v-else>
            🔄 Actualizar el stock
          </template>
        </button>
      </template>
    </div>
  `
};

export default TechnicianVanStockPanel;
