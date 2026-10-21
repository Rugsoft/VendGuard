/**
 * VendGuard - CoordinatorVanStockTab Component (CoordinatorVanStockTab.js)
 *
 * Stock de repuestos de las furgonetas para la Coordinación (Módulo 18, RF-03, RF-04.2).
 *
 * DOS LECTURAS, UNA TABLA
 *
 *  - **Alertas** (modo por defecto): solo las parejas bajo umbral, ya ordenadas por severidad
 *    desde el servidor. Es la superficie operativa de RF-03.2: el descuadre negativo encabeza la
 *    lista y la marca de severidad se pinta desde `is_negative`, que esta proyección sí trae.
 *  - **Inventario completo**: todas las parejas, con el técnico y su código de operador. Es la
 *    superficie de supervisión de RF-04.2.
 *
 * El filtro por técnico se ofrece en los dos modos porque el endpoint lo admite (contrato §4.2).
 * Las opciones salen de `/api/coordinator/users` con rol técnico activo —no de las filas ya
 * cargadas—: si se derivaran del inventario, un técnico sin ninguna pareja declarada no podría
 * elegirse y, justo ése, es el primero al que hay que cargarle la furgoneta.
 *
 * AJUSTE ABSOLUTO
 *
 * El formulario declara **cuántas unidades hay**, no un delta (contrato §4.3). Al abrirlo desde
 * una fila llega precargado con el saldo actual; al guardarlo, la respuesta devuelve la cantidad
 * previa y el aviso la repite, de modo que el coordinador ve el efecto de lo que acaba de
 * declarar. Un saldo negativo es un valor legítimo —descuadre declarado, RD-04—, así que el
 * campo lo acepta: truncar a cero escondería la señal de negocio.
 *
 * El selector de piezas usa el catálogo maestro (`/api/coordinator/spare-parts`). Una pieza dada
 * de baja no aparece: no tiene sentido cargar en una furgoneta algo que ya no se instala.
 *
 * DISEÑO
 *
 * Tokens del sistema (`var(--color-…)`, `var(--radius-…)`) y ni un literal hexadecimal: la deuda
 * cromática está congelada por `DesignTokenDebtRatchetTest`. Tabla con desplazamiento horizontal
 * contenido para no romper el panel en anchos medios.
 *
 * Dogma Vanilla: Vue 3 Options API con plantilla en navegador, sin bundler ni dependencias.
 * Dualismo Lingüístico: código en inglés, texto de interfaz en castellano.
 */

import { api } from '../api.js';

/** Estados del formulario, para no repetir cadenas sueltas. */
const EMPTY_FORM = Object.freeze({
  technician_id: '',
  spare_part_id: '',
  quantity_available: '',
  min_threshold: ''
});

export const CoordinatorVanStockTab = {
  name: 'CoordinatorVanStockTab',

  data() {
    return {
      mode: 'alert', // 'alert' | 'inventory'
      items: [],
      technicians: [],
      catalogue: [],
      selectedTechnicianId: '',
      isLoading: false,
      errorMessage: '',
      successMessage: '',

      showForm: false,
      isSaving: false,
      formError: '',
      form: { ...EMPTY_FORM }
    };
  },

  computed: {
    isAlertMode() {
      return this.mode === 'alert';
    },

    isEmpty() {
      return !this.isLoading && this.items.length === 0;
    },

    emptyMessage() {
      return this.isAlertMode
        ? 'Ninguna furgoneta está por debajo de su umbral. El parque está cubierto.'
        : 'Todavía no hay stock declarado en ninguna furgoneta.';
    },

    /** El ajuste exige las cuatro cotas: técnico, pieza, cantidad y umbral (contrato §4.3). */
    canSave() {
      const technicianOk = String(this.form.technician_id).trim() !== '';
      const partOk = String(this.form.spare_part_id).trim() !== '';

      return technicianOk
        && partOk
        && this.isIntegerLike(this.form.quantity_available)
        && this.isIntegerLike(this.form.min_threshold);
    },

    formTitle() {
      return this.form.spare_part_id === '' ? 'Declarar stock de una furgoneta' : 'Ajustar el stock declarado';
    }
  },

  methods: {
    isIntegerLike(value) {
      return /^-?\d+$/.test(String(value).trim());
    },

    /** Marca de severidad: el descuadre manda sobre el simple bajo umbral (RF-03.2). */
    severityOf(item) {
      if (item.is_negative === true) {
        return { label: 'Descuadre', testid: 'descuadre' };
      }
      if (item.is_below_threshold === true) {
        return { label: 'Reponer', testid: 'reponer' };
      }

      return { label: 'Cubierto', testid: 'cubierto' };
    },

    async loadTechnicians() {
      try {
        const response = await api.coordinator.getUsers({ role: 'TECHNICIAN', status: 'active' });
        const rows = response?.data || response || [];
        this.technicians = (Array.isArray(rows) ? rows : []).map((user) => ({
          id: user.id,
          name: user.name,
          operatorCode: user.operator_code || ''
        }));
      } catch (error) {
        // El catálogo de técnicos solo alimenta el formulario; la tabla sigue siendo legible.
        this.technicians = [];
      }
    },

    async loadCatalogue() {
      try {
        const response = await api.coordinator.getSparePartsCatalog({ is_active: 1 });
        const rows = response?.data || response || [];
        this.catalogue = Array.isArray(rows) ? rows : [];
      } catch (error) {
        this.catalogue = [];
      }
    },

    async loadStock() {
      this.isLoading = true;
      this.errorMessage = '';

      const params = {};
      if (String(this.selectedTechnicianId).trim() !== '') {
        params.technician_id = this.selectedTechnicianId;
      }
      if (this.isAlertMode) {
        params.below_threshold = 1;
      }

      try {
        const response = await api.coordinator.getVanStocks(params);
        const data = response?.data || response || {};
        this.items = Array.isArray(data.items) ? data.items : [];
      } catch (error) {
        this.errorMessage = error.message || 'No se pudo cargar el stock de las furgonetas.';
        this.items = [];
      } finally {
        this.isLoading = false;
      }
    },

    setMode(mode) {
      if (this.mode === mode) {
        return;
      }
      this.mode = mode;
      this.loadStock();
    },

    applyTechnicianFilter() {
      this.loadStock();
    },

    /** Abre el formulario; con fila, precarga el saldo actual de esa pareja. */
    openForm(item = null) {
      this.successMessage = '';
      this.formError = '';
      this.showForm = true;

      if (item) {
        this.form = {
          technician_id: item.technician_id,
          spare_part_id: item.spare_part_id,
          quantity_available: item.quantity_available,
          min_threshold: item.min_threshold
        };
        return;
      }

      this.form = {
        ...EMPTY_FORM,
        technician_id: this.selectedTechnicianId === '' ? '' : Number(this.selectedTechnicianId)
      };
    },

    closeForm() {
      this.showForm = false;
      this.formError = '';
      this.form = { ...EMPTY_FORM };
    },

    async saveStock() {
      if (!this.canSave || this.isSaving) {
        return;
      }

      this.isSaving = true;
      this.formError = '';
      this.successMessage = '';

      try {
        const response = await api.coordinator.setVanStock({
          technician_id: Number(this.form.technician_id),
          spare_part_id: Number(this.form.spare_part_id),
          quantity_available: Number(this.form.quantity_available),
          min_threshold: Number(this.form.min_threshold)
        });
        const saved = response?.data || response || {};
        const previous = saved.previous_quantity_available;

        this.successMessage = previous === null || previous === undefined
          ? `Stock declarado: ${saved.quantity_available} unidades.`
          : `Stock actualizado: ${saved.quantity_available} unidades (antes ${previous}).`;

        this.showForm = false;
        this.form = { ...EMPTY_FORM };
        await this.loadStock();
      } catch (error) {
        this.formError = error.message || 'No se pudo guardar el stock de la furgoneta.';
      } finally {
        this.isSaving = false;
      }
    }
  },

  mounted() {
    this.loadTechnicians();
    this.loadCatalogue();
    this.loadStock();
  },

  template: `
    <div class="coordinator-van-stock-tab" data-testid="coord-van-stock-tab" style="padding-top: 8px;">

      <!-- Avisos -->
      <div
        v-if="errorMessage"
        role="alert"
        data-testid="coord-van-stock-error"
        style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; padding: 12px 16px; background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); border-radius: var(--radius-card); color: var(--color-error-text); font-family: var(--font-body); font-size: var(--font-size-body-sm);"
      >
        <span>⚠️ {{ errorMessage }}</span>
        <button type="button" class="vg-btn vg-btn-icon vg-btn-ghost vg-btn-ghost-danger" title="Cerrar el aviso" @click="errorMessage = ''">&times;</button>
      </div>

      <div
        v-if="successMessage"
        data-testid="coord-van-stock-success"
        style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; padding: 12px 16px; background-color: var(--color-success-bg); border: 1px solid var(--color-success); border-radius: var(--radius-card); color: var(--color-success-text); font-family: var(--font-body); font-size: var(--font-size-body-sm);"
      >
        <span>✅ {{ successMessage }}</span>
        <button type="button" class="vg-btn vg-btn-icon vg-btn-ghost vg-btn-ghost-success" title="Cerrar el aviso" @click="successMessage = ''">&times;</button>
      </div>

      <!-- Controles: modo de lectura, filtro por furgoneta y alta -->
      <div style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; margin-bottom: 16px;">
        <div style="display: flex; flex-wrap: wrap; gap: 6px; background-color: var(--color-primary-subtle); padding: 4px; border-radius: var(--radius-card);">
          <button
            type="button"
            class="vg-btn"
            :class="isAlertMode ? 'vg-btn-primary' : 'vg-btn-secondary'"
            data-testid="coord-van-stock-mode-alert"
            @click="setMode('alert')"
          >
            ⚠️ Alertas
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="isAlertMode ? 'vg-btn-secondary' : 'vg-btn-primary'"
            data-testid="coord-van-stock-mode-inventory"
            @click="setMode('inventory')"
          >
            📦 Inventario completo
          </button>
        </div>

        <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
          Furgoneta
          <select
            v-model="selectedTechnicianId"
            data-testid="coord-van-stock-filter"
            class="vg-input"
            style="min-width: 220px;"
            @change="applyTechnicianFilter"
          >
            <option value="">Todas las furgonetas</option>
            <option v-for="tech in technicians" :key="tech.id" :value="String(tech.id)">
              {{ tech.name }}<template v-if="tech.operatorCode"> · {{ tech.operatorCode }}</template>
            </option>
          </select>
        </label>

        <button
          type="button"
          class="vg-btn vg-btn-primary"
          data-testid="coord-van-stock-new"
          @click="openForm()"
        >
          ＋ Declarar stock
        </button>
      </div>

      <!-- Formulario de ajuste absoluto (contrato §4.3) -->
      <div
        v-if="showForm"
        data-testid="coord-van-stock-form"
        style="margin-bottom: 16px; padding: 16px; background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card);"
      >
        <h3 style="margin: 0 0 12px; font-family: var(--font-display); font-size: var(--font-size-heading-md); color: var(--color-ink);">
          {{ formTitle }}
        </h3>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
          <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
            Técnico
            <select v-model="form.technician_id" class="vg-input" data-testid="coord-van-stock-form-technician">
              <option value="">Selecciona un técnico</option>
              <option v-for="tech in technicians" :key="tech.id" :value="tech.id">
                {{ tech.name }}<template v-if="tech.operatorCode"> · {{ tech.operatorCode }}</template>
              </option>
            </select>
          </label>

          <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
            Pieza del catálogo
            <select v-model="form.spare_part_id" class="vg-input" data-testid="coord-van-stock-form-part">
              <option value="">Selecciona una pieza</option>
              <option v-for="part in catalogue" :key="part.id" :value="part.id">
                {{ part.part_code }} · {{ part.name }}
              </option>
            </select>
          </label>

          <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
            Unidades disponibles
            <input v-model="form.quantity_available" type="number" step="1" class="vg-input" data-testid="coord-van-stock-form-quantity" />
          </label>

          <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
            Umbral mínimo
            <input v-model="form.min_threshold" type="number" step="1" min="0" class="vg-input" data-testid="coord-van-stock-form-threshold" />
          </label>
        </div>

        <p style="margin: 10px 0 0; font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
          La cantidad es el saldo que queda en la furgoneta, no una suma. Un saldo negativo se
          admite y queda marcado como descuadre.
        </p>

        <div
          v-if="formError"
          role="alert"
          data-testid="coord-van-stock-form-error"
          style="margin-top: 12px; padding: 10px 12px; background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); border-radius: var(--radius-interactive); color: var(--color-error-text); font-family: var(--font-body); font-size: var(--font-size-body-sm);"
        >
          ⚠️ {{ formError }}
        </div>

        <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px;">
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            data-testid="coord-van-stock-form-save"
            :disabled="!canSave || isSaving"
            @click="saveStock"
          >
            {{ isSaving ? 'Guardando…' : 'Guardar el stock' }}
          </button>
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            data-testid="coord-van-stock-form-cancel"
            @click="closeForm"
          >
            Cancelar
          </button>
        </div>
      </div>

      <!-- Tabla del parque -->
      <div
        v-if="isLoading"
        data-testid="coord-van-stock-loading"
        style="padding: 16px; color: var(--color-ink-muted); font-family: var(--font-body); font-size: var(--font-size-body-sm);"
      >
        Cargando el stock de las furgonetas…
      </div>

      <div
        v-else-if="isEmpty"
        data-testid="coord-van-stock-empty"
        style="padding: 16px; background-color: var(--color-surface-2); border: 1px dashed var(--color-hairline); border-radius: var(--radius-card); color: var(--color-ink-muted); font-family: var(--font-body); font-size: var(--font-size-body-sm);"
      >
        {{ emptyMessage }}
      </div>

      <div v-else style="overflow-x: auto;">
        <table
          data-testid="coord-van-stock-table"
          style="width: 100%; border-collapse: collapse; font-family: var(--font-body); font-size: var(--font-size-body-sm);"
        >
          <thead>
            <tr style="text-align: left; color: var(--color-ink-secondary);">
              <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Furgoneta</th>
              <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Pieza</th>
              <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline); text-align: right;">Disponible</th>
              <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline); text-align: right;">Umbral</th>
              <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Estado</th>
              <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline); text-align: right;">Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="item in items"
              :key="item.technician_id + '-' + item.spare_part_id"
              :data-testid="'coord-van-stock-row-' + item.technician_id + '-' + item.spare_part_id"
            >
              <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-ink);">
                <div style="font-weight: 600;">{{ item.technician_name }}</div>
                <div v-if="item.operator_code" style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ item.operator_code }}</div>
              </td>
              <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-ink);">
                <div style="font-weight: 600;">{{ item.name }}</div>
                <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ item.part_code }}</div>
              </td>
              <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right; font-weight: 700; color: var(--color-ink);">
                {{ item.quantity_available }}
              </td>
              <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right; color: var(--color-ink-secondary);">
                {{ item.min_threshold }}
              </td>
              <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft);">
                <span
                  :data-testid="'coord-van-stock-badge-' + severityOf(item).testid"
                  :style="severityOf(item).testid === 'descuadre'
                    ? 'display: inline-block; padding: 2px 8px; border-radius: var(--radius-interactive); background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); font-size: var(--font-size-caption); font-weight: 700;'
                    : (severityOf(item).testid === 'reponer'
                      ? 'display: inline-block; padding: 2px 8px; border-radius: var(--radius-interactive); background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); font-size: var(--font-size-caption); font-weight: 700;'
                      : 'display: inline-block; padding: 2px 8px; border-radius: var(--radius-interactive); background-color: var(--color-success-bg); border: 1px solid var(--color-success); color: var(--color-success-text); font-size: var(--font-size-caption); font-weight: 700;')"
                >
                  {{ severityOf(item).label }}
                </span>
              </td>
              <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right;">
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary"
                  :data-testid="'coord-van-stock-adjust-' + item.technician_id + '-' + item.spare_part_id"
                  @click="openForm(item)"
                >
                  Ajustar
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  `
};

export default CoordinatorVanStockTab;
