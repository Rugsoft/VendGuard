/**
 * VendGuard - TechnicianVanStockPanel Component (TechnicianVanStockPanel.js)
 *
 * Inventario de repuestos de la furgoneta del técnico autenticado (Módulo 18, RF-04.1).
 *
 * QUÉ PINTA Y QUÉ NO
 *
 * Pinta lo que el contrato §4.1 devuelve: código, nombre, categoría, cantidad disponible y la
 * insignia de bajo umbral. **No** pinta el umbral mínimo —no es un dato de la vista del técnico,
 * RF-04.1 lo excluye expresamente— ni ningún importe: el coste del repuesto vive congelado en la
 * línea de la avería y la sede no lo ve, así que la furgoneta tampoco (Art. V.4, RD-05).
 *
 * Superficie de solo lectura. No hay aquí ni un control que mute el saldo: quien declara el stock
 * es la Coordinación (RF-01.1). El único botón es *Actualizar*, que vuelve a leer.
 *
 * EL DESCUADRE NO SE ETIQUETA AQUÍ
 *
 * La proyección del técnico no transporta `is_negative` a propósito: el servidor decide qué ve
 * cada público y el técnico ve su cantidad, incluido un negativo, sin la etiqueta de descuadre
 * que la Coordinación usa para triaje (RF-03.3). Reconstruir esa etiqueta en el cliente a partir
 * del signo sería reintroducir por la puerta de atrás un dato que el contrato retiró.
 *
 * DISEÑO
 *
 * Tokens del sistema (`var(--color-…)`, `var(--radius-…)`) y ni un literal hexadecimal: la deuda
 * cromática está congelada por `DesignTokenDebtRatchetTest`. Tarjetas de 8 px, chips de 4 px y
 * objetivo táctil de 44 px en el único botón, porque esta vista se usa de pie frente a la máquina.
 *
 * Dogma Vanilla: Vue 3 Options API con plantilla en navegador, sin bundler ni dependencias.
 * Dualismo Lingüístico: código en inglés, texto de interfaz en castellano.
 */

import { api } from '../api.js';

export const TechnicianVanStockPanel = {
  name: 'TechnicianVanStockPanel',

  data() {
    return {
      technicianId: null,
      items: [],
      isLoading: false,
      errorMessage: ''
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
      } catch (error) {
        this.errorMessage = error.message || 'No se pudo cargar el stock de tu furgoneta.';
        this.items = [];
      } finally {
        this.isLoading = false;
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

        <button
          type="button"
          class="vg-btn vg-btn-secondary"
          style="min-height: 44px; align-self: flex-start;"
          data-testid="tech-van-stock-refresh"
          @click="loadInventory"
        >
          🔄 Actualizar el stock
        </button>
      </template>
    </div>
  `
};

export default TechnicianVanStockPanel;
