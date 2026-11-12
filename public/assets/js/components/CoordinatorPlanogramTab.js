/**
 * VendGuard - CoordinatorPlanogramTab (CoordinatorPlanogramTab.js)
 *
 * Pestaña de Coordinación para el catálogo maestro y el planograma por máquina
 * (Módulo 32, RF-05.1, D1, D2, C1–C3). Dos sub-vistas sobre la misma superficie:
 *
 *   1. «Catálogo de Productos y Consumibles»: KPIs, filtros por categoría,
 *      cadena de frío, estado y texto; alta/edición con SKU bloqueado en edición
 *      e indicadores de coste, PVP y margen; baja/reactivación lógica.
 *   2. «Planograma por Máquina»: selector filtrable de máquina, KPIs del
 *      planograma, matriz de carriles con semáforo y barra de llenado, edición
 *      individual, retirada lógica, aplicación de plantilla y clonación.
 *
 * Todo el color sale de los tokens de `design-tokens.css`; no hay hexadecimales
 * en este módulo. Las reglas sanitarias, los códigos de error y la segregación
 * de costes viven en el backend: la vista sólo los presenta.
 *
 * Dogma Vanilla: Vue 3 Options API vía ES Modules nativos, cero dependencias npm.
 * Dualismo Lingüístico: código en inglés, textos de interfaz en castellano.
 */

import { api } from '../api.js';
import { ModalDialog } from './ModalDialog.js';

export const PRODUCT_CATEGORIES = [
  { value: 'COLD_DRINK', label: 'Bebida fría', units: ['UNIT'], directRetail: true },
  { value: 'SNACK', label: 'Snack y sólido', units: ['UNIT'], directRetail: true },
  { value: 'PERISHABLE_FOOD', label: 'Alimento perecedero', units: ['UNIT'], directRetail: true },
  { value: 'HOT_BEVERAGE_INGREDIENT', label: 'Ingrediente de bebida caliente', units: ['GRAMS', 'UNIT'], directRetail: false },
  { value: 'CUP_STIRRER_CONSUMABLE', label: 'Vaso, tapa o paletina', units: ['SLEEVE', 'UNIT'], directRetail: false }
];

export const PRODUCT_UNIT_TYPES = [
  { value: 'UNIT', label: 'Unidad' },
  { value: 'GRAMS', label: 'Gramos' },
  { value: 'SLEEVE', label: 'Tira' }
];

export const STOCK_STATUS_LABELS = {
  STOCKOUT: 'Agotado',
  LOW_STOCK: 'Bajo umbral',
  OK: 'Correcto'
};

const blankProductForm = () => ({
  id: null,
  sku: '',
  name: '',
  category: 'SNACK',
  unit_type: 'UNIT',
  unit_cost_eur: '',
  sale_price_eur: '',
  requires_cold_chain: false,
  max_shelf_life_days: ''
});

const blankSlotForm = () => ({
  slot_code: '',
  product_id: '',
  max_capacity: 10,
  current_estimated_qty: 0,
  min_threshold: 2,
  is_active: true
});

export const CoordinatorPlanogramTab = {
  name: 'CoordinatorPlanogramTab',
  components: { ModalDialog },
  data() {
    return {
      activeSection: 'catalog',

      products: [],
      catalogKpis: {
        total_products: 0,
        active_products: 0,
        inactive_products: 0,
        cold_chain_products: 0,
        consumable_and_ingredient_products: 0
      },
      productFilters: {
        search: '',
        category: '',
        requires_cold_chain: '',
        status: 'ACTIVE'
      },
      isLoadingProducts: false,
      productError: '',
      productSuccess: '',

      showProductModal: false,
      productForm: blankProductForm(),
      productErrors: {},
      isSavingProduct: false,

      machines: [],
      machineFilters: {
        search: '',
        location_id: '',
        machine_type: ''
      },
      selectedMachineId: '',
      planogram: null,
      isLoadingPlanogram: false,
      planogramError: '',
      planogramSuccess: '',

      showSlotModal: false,
      slotForm: blankSlotForm(),
      slotErrors: {},
      isSavingSlot: false,

      showPresetModal: false,
      presetStockMode: 'ZERO',
      isApplyingPreset: false,

      showCloneModal: false,
      cloneSourceId: '',
      cloneStockMode: 'ZERO',
      cloneError: '',
      isCloning: false
    };
  },
  computed: {
    categoryOptions() {
      return PRODUCT_CATEGORIES;
    },
    unitTypeOptions() {
      const selected = PRODUCT_CATEGORIES.find((category) => category.value === this.productForm.category);
      const allowed = selected ? selected.units : PRODUCT_UNIT_TYPES.map((unit) => unit.value);
      return PRODUCT_UNIT_TYPES.filter((unit) => allowed.includes(unit.value));
    },
    filteredProducts() {
      const search = this.productFilters.search.trim().toLowerCase();
      return this.products.filter((product) => {
        if (search) {
          const sku = String(product.sku || '').toLowerCase();
          const name = String(product.name || '').toLowerCase();
          if (!sku.includes(search) && !name.includes(search)) {
            return false;
          }
        }
        if (this.productFilters.category && product.category !== this.productFilters.category) {
          return false;
        }
        if (this.productFilters.requires_cold_chain !== '') {
          const expected = this.productFilters.requires_cold_chain === 'true';
          if (product.requires_cold_chain !== expected) {
            return false;
          }
        }
        if (this.productFilters.status === 'ACTIVE' && product.is_active !== true) {
          return false;
        }
        if (this.productFilters.status === 'INACTIVE' && product.is_active !== false) {
          return false;
        }
        return true;
      });
    },
    productCategoryLabels() {
      const labels = {};
      PRODUCT_CATEGORIES.forEach((category) => {
        labels[category.value] = category.label;
      });
      return labels;
    },
    machineLocationOptions() {
      const seen = new Map();
      this.machines.forEach((machine) => {
        if (machine.location_id && !seen.has(machine.location_id)) {
          seen.set(machine.location_id, {
            id: machine.location_id,
            label: machine.location_name || machine.location_site_code || `Sede ${machine.location_id}`
          });
        }
      });
      return Array.from(seen.values());
    },
    filteredMachines() {
      const search = this.machineFilters.search.trim().toLowerCase();
      return this.machines.filter((machine) => {
        if (this.machineFilters.location_id !== ''
          && String(machine.location_id) !== String(this.machineFilters.location_id)) {
          return false;
        }
        if (this.machineFilters.machine_type !== ''
          && machine.machine_type !== this.machineFilters.machine_type) {
          return false;
        }
        if (search) {
          const haystack = [
            machine.code,
            machine.model,
            machine.location_name,
            machine.zone_name,
            machine.floor_wing
          ].join(' ').toLowerCase();
          if (!haystack.includes(search)) {
            return false;
          }
        }
        return true;
      });
    },
    selectedMachine() {
      return this.machines.find((machine) => String(machine.id) === String(this.selectedMachineId)) || null;
    },
    planogramSummary() {
      return this.planogram?.summary || {
        active_slots_count: 0,
        stockout_slots_count: 0,
        low_stock_slots_count: 0,
        fill_rate_percent: 0
      };
    },
    planogramSlots() {
      return this.planogram?.slots || [];
    },
    activeProductOptions() {
      const current = Number(this.slotForm.product_id) || 0;
      return this.products.filter((product) => product.is_active === true || Number(product.id) === current);
    },
    cloneSourceOptions() {
      if (!this.selectedMachine) {
        return [];
      }
      const fromContract = this.planogram?.compatible_source_machines;
      if (Array.isArray(fromContract) && fromContract.length > 0) {
        return fromContract;
      }
      return this.machines.filter((machine) =>
        String(machine.id) !== String(this.selectedMachine.id)
        && machine.machine_type === this.selectedMachine.machine_type
        && machine.is_active !== false
      );
    }
  },
  watch: {
    filteredMachines: {
      handler: 'syncMachineSelection'
    }
  },
  mounted() {
    this.loadProducts();
    this.loadMachines();
  },
  methods: {
    async loadProducts() {
      this.isLoadingProducts = true;
      this.productError = '';
      try {
        const response = await api.coordinator.getProducts();
        const data = response.data || response || {};
        this.products = Array.isArray(data.products) ? data.products : [];
        this.catalogKpis = { ...this.catalogKpis, ...(data.kpis || {}) };
      } catch (error) {
        this.productError = error.message || 'No se pudo cargar el catálogo de productos.';
      } finally {
        this.isLoadingProducts = false;
      }
    },

    // Keeps the selected machine inside the filtered set. A single match is
    // auto-selected; otherwise the stale planogram is cleared.
    async syncMachineSelection() {
      const filtered = this.filteredMachines;
      const selectedId = String(this.selectedMachineId || '');

      if (selectedId !== '' && filtered.some((machine) => String(machine.id) === selectedId)) {
        return;
      }
      if (filtered.length === 1) {
        await this.selectMachine(filtered[0].id);
        return;
      }
      if (selectedId !== '') {
        await this.selectMachine('');
      }
    },

    async loadMachines() {
      try {
        const response = await api.coordinator.getMachines();
        const data = response.data || response || [];
        this.machines = Array.isArray(data) ? data.filter((machine) => machine.is_active !== false) : [];
        if (this.machines.length > 0 && !this.selectedMachineId) {
          await this.selectMachine(this.machines[0].id);
        }
      } catch (error) {
        this.planogramError = error.message || 'No se pudo cargar el parque de máquinas.';
      }
    },

    async selectMachine(machineId) {
      this.selectedMachineId = machineId;
      this.planogram = null;
      this.planogramError = '';
      this.planogramSuccess = '';
      if (!machineId) {
        return;
      }
      await this.loadPlanogram(machineId);
    },

    async loadPlanogram(machineId = this.selectedMachineId) {
      if (!machineId) {
        return;
      }
      this.isLoadingPlanogram = true;
      this.planogramError = '';
      try {
        const response = await api.coordinator.getMachinePlanogram(machineId);
        this.planogram = response.data || response || null;
      } catch (error) {
        this.planogramError = error.message || 'No se pudo cargar el planograma de la máquina.';
      } finally {
        this.isLoadingPlanogram = false;
      }
    },

    categoryLabel(category) {
      return this.productCategoryLabels[category] || category || '—';
    },

    unitTypeLabel(unitType) {
      const match = PRODUCT_UNIT_TYPES.find((unit) => unit.value === unitType);
      return match ? match.label : unitType || '—';
    },

    formatMoney(value) {
      const number = Number(value);
      return Number.isFinite(number) ? `${number.toFixed(2)} €` : '—';
    },

    productMargin(product) {
      if (product.unit_margin_eur !== undefined && product.unit_margin_eur !== null) {
        return Number(product.unit_margin_eur);
      }
      return Number(product.sale_price_eur || 0) - Number(product.unit_cost_eur || 0);
    },

    stockStatusLabel(status) {
      return STOCK_STATUS_LABELS[status] || status || '—';
    },

    stockStatusStyle(status) {
      if (status === 'STOCKOUT') {
        return {
          backgroundColor: 'var(--color-error-bg)',
          color: 'var(--color-error-text)',
          border: '1px solid var(--color-error-border)'
        };
      }
      if (status === 'LOW_STOCK') {
        return {
          backgroundColor: 'var(--color-warning-bg)',
          color: 'var(--color-warning-text)',
          border: '1px solid var(--color-warning-border)'
        };
      }
      return {
        backgroundColor: 'var(--color-success-bg)',
        color: 'var(--color-success-text)',
        border: '1px solid var(--color-success)'
      };
    },

    slotFillPercent(slot) {
      const capacity = Number(slot.max_capacity);
      if (!Number.isFinite(capacity) || capacity <= 0) {
        return 0;
      }
      return Math.max(0, Math.min(100, Math.round((Number(slot.current_estimated_qty) / capacity) * 100)));
    },

    machineTypeLabel(type) {
      const labels = {
        HOT_DRINKS: 'Bebidas calientes',
        COLD_DRINKS: 'Bebidas frías',
        SNACKS: 'Snacks',
        PERISHABLE_FOOD: 'Perecederos',
        COMBO: 'Mixta'
      };
      return labels[type] || type || '—';
    },

    openProductModal(product = null) {
      this.productError = '';
      this.productSuccess = '';
      this.productErrors = {};
      if (product) {
        this.productForm = {
          id: product.id,
          sku: product.sku,
          name: product.name,
          category: product.category,
          unit_type: product.unit_type,
          unit_cost_eur: product.unit_cost_eur,
          sale_price_eur: product.sale_price_eur,
          requires_cold_chain: product.requires_cold_chain === true,
          max_shelf_life_days: product.max_shelf_life_days ?? ''
        };
      } else {
        this.productForm = blankProductForm();
      }
      this.showProductModal = true;
    },

    validateProductForm() {
      const errors = {};
      if (!this.productForm.sku.trim()) {
        errors.sku = 'El SKU es obligatorio.';
      }
      if (this.productForm.name.trim().length < 2) {
        errors.name = 'El nombre debe tener al menos 2 caracteres.';
      }
      if (!(Number(this.productForm.unit_cost_eur) > 0)) {
        errors.unit_cost_eur = 'El coste unitario debe ser mayor que 0.';
      }
      const category = PRODUCT_CATEGORIES.find((item) => item.value === this.productForm.category);
      const salePrice = Number(this.productForm.sale_price_eur);
      if (!(salePrice >= 0)) {
        errors.sale_price_eur = 'El PVP no puede ser negativo.';
      } else if (category && category.directRetail && !(salePrice > 0)) {
        errors.sale_price_eur = 'Las categorías de venta directa exigen un PVP mayor que 0.';
      }
      const requiresShelfLife = this.productForm.requires_cold_chain === true
        || this.productForm.category === 'PERISHABLE_FOOD';
      if (requiresShelfLife && !(Number(this.productForm.max_shelf_life_days) >= 1)) {
        errors.max_shelf_life_days = 'La cadena de frío y los perecederos exigen una vida útil entre 1 y 3650 días.';
      }
      this.productErrors = errors;
      return Object.keys(errors).length === 0;
    },

    async submitProduct() {
      if (!this.validateProductForm()) {
        return;
      }
      this.isSavingProduct = true;
      this.productError = '';
      try {
        const payload = {
          sku: this.productForm.sku.trim().toUpperCase(),
          name: this.productForm.name.trim(),
          category: this.productForm.category,
          unit_type: this.productForm.unit_type,
          unit_cost_eur: Number(this.productForm.unit_cost_eur),
          sale_price_eur: Number(this.productForm.sale_price_eur || 0),
          requires_cold_chain: this.productForm.requires_cold_chain === true,
          max_shelf_life_days: this.productForm.max_shelf_life_days === ''
            ? null
            : Number(this.productForm.max_shelf_life_days)
        };
        if (this.productForm.id) {
          await api.coordinator.updateProduct(this.productForm.id, payload);
          this.productSuccess = 'Producto actualizado.';
        } else {
          await api.coordinator.createProduct(payload);
          this.productSuccess = 'Producto creado.';
        }
        this.showProductModal = false;
        await this.loadProducts();
      } catch (error) {
        this.productError = error.message || 'No se pudo guardar el producto.';
      } finally {
        this.isSavingProduct = false;
      }
    },

    async toggleProductStatus(product) {
      this.productError = '';
      this.productSuccess = '';
      try {
        await api.coordinator.changeProductStatus(product.id, product.is_active !== true);
        this.productSuccess = product.is_active
          ? 'Producto dado de baja lógicamente. Sus carriles se conservan.'
          : 'Producto reactivado.';
        await this.loadProducts();
      } catch (error) {
        this.productError = error.message || 'No se pudo cambiar el estado del producto.';
      }
    },

    openSlotModal(slot = null) {
      this.slotErrors = {};
      this.planogramError = '';
      if (slot) {
        this.slotForm = {
          slot_code: slot.slot_code,
          product_id: slot.product_id,
          max_capacity: slot.max_capacity,
          current_estimated_qty: slot.current_estimated_qty,
          min_threshold: slot.min_threshold,
          is_active: slot.is_active === true
        };
      } else {
        this.slotForm = blankSlotForm();
        const firstProduct = this.activeProductOptions[0];
        if (firstProduct) {
          this.slotForm.product_id = firstProduct.id;
        }
      }
      this.showSlotModal = true;
    },

    validateSlotForm() {
      const errors = {};
      if (!String(this.slotForm.slot_code).trim()) {
        errors.slot_code = 'El código de carril es obligatorio.';
      }
      if (!this.slotForm.product_id) {
        errors.product_id = 'Selecciona una referencia.';
      }
      if (!(Number(this.slotForm.max_capacity) >= 1)) {
        errors.max_capacity = 'La capacidad debe ser al menos 1.';
      }
      if (Number(this.slotForm.min_threshold) >= Number(this.slotForm.max_capacity)) {
        errors.min_threshold = 'El umbral debe ser menor que la capacidad.';
      }
      if (Number(this.slotForm.current_estimated_qty) > Number(this.slotForm.max_capacity)) {
        errors.current_estimated_qty = 'La existencia no puede superar la capacidad.';
      }
      this.slotErrors = errors;
      return Object.keys(errors).length === 0;
    },

    async submitSlot() {
      if (!this.validateSlotForm() || !this.selectedMachineId) {
        return;
      }
      this.isSavingSlot = true;
      this.planogramError = '';
      try {
        await api.coordinator.savePlanogramSlot(
          this.selectedMachineId,
          this.slotForm.slot_code.trim().toUpperCase(),
          {
            product_id: Number(this.slotForm.product_id),
            max_capacity: Number(this.slotForm.max_capacity),
            current_estimated_qty: Number(this.slotForm.current_estimated_qty),
            min_threshold: Number(this.slotForm.min_threshold),
            is_active: this.slotForm.is_active !== false
          }
        );
        this.showSlotModal = false;
        this.planogramSuccess = 'Carril guardado.';
        await this.loadPlanogram();
      } catch (error) {
        this.planogramError = error.message || 'No se pudo guardar el carril.';
      } finally {
        this.isSavingSlot = false;
      }
    },

    async deactivateSlot(slot) {
      if (!this.selectedMachineId) {
        return;
      }
      this.planogramError = '';
      try {
        await api.coordinator.deactivatePlanogramSlot(this.selectedMachineId, slot.slot_code);
        this.planogramSuccess = `Carril ${slot.slot_code} retirado lógicamente.`;
        await this.loadPlanogram();
      } catch (error) {
        this.planogramError = error.message || 'No se pudo retirar el carril.';
      }
    },

    async applyPreset() {
      if (!this.selectedMachineId) {
        return;
      }
      this.isApplyingPreset = true;
      this.planogramError = '';
      try {
        await api.coordinator.applyPlanogramPreset(this.selectedMachineId, this.presetStockMode);
        this.showPresetModal = false;
        this.planogramSuccess = 'Plantilla del modelo aplicada.';
        await this.loadPlanogram();
      } catch (error) {
        this.planogramError = error.message || 'No se pudo aplicar la plantilla.';
      } finally {
        this.isApplyingPreset = false;
      }
    },

    openCloneModal() {
      this.cloneError = '';
      this.cloneSourceId = '';
      this.cloneStockMode = 'ZERO';
      this.showCloneModal = true;
    },

    async submitClone() {
      if (!this.selectedMachineId || !this.cloneSourceId) {
        this.cloneError = 'Selecciona la máquina origen.';
        return;
      }
      this.isCloning = true;
      this.cloneError = '';
      try {
        await api.coordinator.clonePlanogram(
          this.selectedMachineId,
          this.cloneSourceId,
          this.cloneStockMode
        );
        this.showCloneModal = false;
        this.planogramSuccess = 'Planograma clonado. Los productos en baja se han omitido.';
        await this.loadPlanogram();
      } catch (error) {
        this.cloneError = error.message || 'No se pudo clonar el planograma.';
      } finally {
        this.isCloning = false;
      }
    }
  },
  template: `
    <div class="coordinator-planogram-tab" style="padding-top: 8px;" data-testid="planogram-panel">
      <div class="vg-action-group" style="margin-bottom: 16px; gap: 8px;">
        <button
          type="button"
          class="vg-btn"
          :class="activeSection === 'catalog' ? 'vg-btn-primary' : 'vg-btn-secondary'"
          @click="activeSection = 'catalog'"
          data-testid="planogram-subtab-catalog"
        >
          🍫 Catálogo de Productos y Consumibles
        </button>
        <button
          type="button"
          class="vg-btn"
          :class="activeSection === 'planogram' ? 'vg-btn-primary' : 'vg-btn-secondary'"
          @click="activeSection = 'planogram'"
          data-testid="planogram-subtab-matrix"
        >
          🗄️ Planograma por Máquina (Matriz de Carriles)
        </button>
      </div>

      <div v-if="productError" class="vg-alert vg-alert-error" style="margin-bottom: 14px;">⚠️ {{ productError }}</div>
      <div v-if="productSuccess" class="vg-alert" style="margin-bottom: 14px; background-color: var(--color-success-bg); color: var(--color-success-text); border-color: var(--color-success);">✅ {{ productSuccess }}</div>

      <!-- ── Catálogo maestro ─────────────────────────────────────────── -->
      <section v-if="activeSection === 'catalog'" data-testid="planogram-catalog-section">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px;">
          <div class="vg-card" style="padding: 14px 16px;">
            <div style="font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted); text-transform: uppercase;">Referencias</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); font-weight: 500; color: var(--color-slate);">{{ catalogKpis.total_products }}</div>
          </div>
          <div class="vg-card" style="padding: 14px 16px;">
            <div style="font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted); text-transform: uppercase;">Activas</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); font-weight: 500; color: var(--color-success-text);">{{ catalogKpis.active_products }}</div>
          </div>
          <div class="vg-card" style="padding: 14px 16px;">
            <div style="font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted); text-transform: uppercase;">Cadena de frío</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); font-weight: 500; color: var(--color-info-text);">{{ catalogKpis.cold_chain_products }}</div>
          </div>
          <div class="vg-card" style="padding: 14px 16px;">
            <div style="font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted); text-transform: uppercase;">Consumibles e ingredientes</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); font-weight: 500; color: var(--color-slate);">{{ catalogKpis.consumable_and_ingredient_products }}</div>
          </div>
        </div>

        <div class="vg-card" style="padding: 14px 16px; margin-bottom: 16px;">
          <div style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px;">
            <div style="flex: 1 1 220px;">
              <label class="vg-label" for="planogram-product-search">Buscar SKU o nombre</label>
              <input id="planogram-product-search" v-model="productFilters.search" class="vg-input" type="search" placeholder="Ej: SAND-JAMON o café" />
            </div>
            <div>
              <label class="vg-label" for="planogram-product-category">Categoría</label>
              <select id="planogram-product-category" v-model="productFilters.category" class="vg-input">
                <option value="">Todas</option>
                <option v-for="category in categoryOptions" :key="category.value" :value="category.value">{{ category.label }}</option>
              </select>
            </div>
            <div>
              <label class="vg-label" for="planogram-product-cold">Cadena de frío</label>
              <select id="planogram-product-cold" v-model="productFilters.requires_cold_chain" class="vg-input">
                <option value="">Todas</option>
                <option value="true">Con frío</option>
                <option value="false">Sin frío</option>
              </select>
            </div>
            <div>
              <label class="vg-label" for="planogram-product-status">Estado</label>
              <select id="planogram-product-status" v-model="productFilters.status" class="vg-input">
                <option value="ACTIVE">Activos</option>
                <option value="INACTIVE">Inactivos</option>
                <option value="ALL">Todos</option>
              </select>
            </div>
            <button type="button" class="vg-btn vg-btn-primary" @click="openProductModal()" data-testid="planogram-new-product">
              ＋ Nuevo producto
            </button>
          </div>
        </div>

        <div v-if="isLoadingProducts" style="padding: 28px; text-align: center; color: var(--color-ink-muted);">Cargando catálogo…</div>
        <div v-else class="table-responsive vg-card" style="padding: 0; overflow: auto;">
          <table class="table table-hover" data-testid="planogram-product-table">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Producto</th>
                <th>Categoría / Unidad</th>
                <th>Coste</th>
                <th>PVP</th>
                <th>Margen</th>
                <th>Carriles</th>
                <th>Estado</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="product in filteredProducts" :key="product.id" :data-product-sku="product.sku">
                <td style="font-family: var(--font-body); font-weight: 600; white-space: nowrap;">{{ product.sku }}</td>
                <td>
                  <div style="font-weight: 600; color: var(--color-slate);">{{ product.name }}</div>
                  <span v-if="product.requires_cold_chain" style="font-size: var(--font-size-caption); color: var(--color-info-text);">❄️ Cadena de frío</span>
                </td>
                <td>{{ categoryLabel(product.category) }}<br><span style="color: var(--color-ink-muted);">{{ unitTypeLabel(product.unit_type) }}</span></td>
                <td style="white-space: nowrap;">{{ formatMoney(product.unit_cost_eur) }}</td>
                <td style="white-space: nowrap;">{{ formatMoney(product.sale_price_eur) }}</td>
                <td style="white-space: nowrap; color: var(--color-success-text);">{{ formatMoney(productMargin(product)) }}</td>
                <td>{{ product.active_slots_count ?? 0 }}</td>
                <td>
                  <span
                    style="display: inline-block; padding: 2px 8px; border-radius: var(--radius-interactive); font-size: var(--font-size-caption); font-weight: 600;"
                    :style="product.is_active
                      ? { backgroundColor: 'var(--color-success-bg)', color: 'var(--color-success-text)' }
                      : { backgroundColor: 'var(--color-error-bg)', color: 'var(--color-error-text)' }"
                  >{{ product.is_active ? 'Activo' : 'Inactivo' }}</span>
                </td>
                <td>
                  <div class="vg-row-actions">
                    <button type="button" class="vg-btn vg-btn-sm vg-btn-secondary" @click="openProductModal(product)" :data-testid="'planogram-edit-product-' + product.sku">Editar</button>
                    <button type="button" class="vg-btn vg-btn-sm" :class="product.is_active ? 'vg-btn-danger-soft' : 'vg-btn-secondary'" @click="toggleProductStatus(product)" :data-testid="'planogram-toggle-product-' + product.sku">
                      {{ product.is_active ? 'Dar de baja' : 'Reactivar' }}
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="filteredProducts.length === 0">
                <td colspan="9" style="text-align: center; padding: 28px; color: var(--color-ink-muted);">No hay referencias que coincidan con los filtros.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ── Planograma por máquina ──────────────────────────────────── -->
      <section v-else data-testid="planogram-matrix-section">
        <div class="vg-card" style="padding: 14px 16px; margin-bottom: 16px;">
          <div style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px;">
            <div style="flex: 1 1 240px;">
              <label class="vg-label" for="planogram-machine-search">Buscar máquina</label>
              <input id="planogram-machine-search" v-model="machineFilters.search" class="vg-input" type="search" placeholder="Código, modelo, sede o zona" />
            </div>
            <div>
              <label class="vg-label" for="planogram-machine-location">Sede</label>
              <select id="planogram-machine-location" v-model="machineFilters.location_id" class="vg-input">
                <option value="">Todas</option>
                <option v-for="location in machineLocationOptions" :key="location.id" :value="location.id">{{ location.label }}</option>
              </select>
            </div>
            <div>
              <label class="vg-label" for="planogram-machine-type">Tipología</label>
              <select id="planogram-machine-type" v-model="machineFilters.machine_type" class="vg-input">
                <option value="">Todas</option>
                <option value="HOT_DRINKS">Bebidas calientes</option>
                <option value="COLD_DRINKS">Bebidas frías</option>
                <option value="SNACKS">Snacks</option>
                <option value="PERISHABLE_FOOD">Perecederos</option>
                <option value="COMBO">Mixta</option>
              </select>
            </div>
            <div style="flex: 1 1 260px;">
              <label class="vg-label" for="planogram-machine-select">Máquina</label>
              <select id="planogram-machine-select" class="vg-input" :value="selectedMachineId" @change="selectMachine($event.target.value)">
                <option value="">Selecciona una máquina…</option>
                <option v-for="machine in filteredMachines" :key="machine.id" :value="machine.id">
                  {{ machine.code }} · {{ machine.location_name || 'Sin sede' }} · {{ machineTypeLabel(machine.machine_type) }}
                </option>
              </select>
            </div>
          </div>
        </div>

        <div v-if="planogramError" class="vg-alert vg-alert-error" style="margin-bottom: 14px;">⚠️ {{ planogramError }}</div>
        <div v-if="planogramSuccess" class="vg-alert" style="margin-bottom: 14px; background-color: var(--color-success-bg); color: var(--color-success-text); border-color: var(--color-success);">✅ {{ planogramSuccess }}</div>

        <div v-if="!selectedMachine" class="vg-card" style="padding: 36px; text-align: center; color: var(--color-ink-muted);">
          Selecciona una máquina para consultar o editar su matriz de carriles.
        </div>

        <template v-else>
          <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px;">
            <div>
              <h3 style="margin: 0; font-family: var(--font-display); font-size: var(--font-size-heading-md); font-weight: 500; color: var(--color-slate);">
                {{ selectedMachine.code }} · {{ selectedMachine.model }}
              </h3>
              <div style="font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                {{ selectedMachine.location_name || 'Sede no informada' }} · {{ machineTypeLabel(selectedMachine.machine_type) }}
              </div>
            </div>
            <div class="vg-action-group" style="gap: 8px;">
              <button type="button" class="vg-btn vg-btn-secondary" @click="showPresetModal = true" data-testid="planogram-apply-preset">⚡ Aplicar Plantilla del Modelo</button>
              <button type="button" class="vg-btn vg-btn-secondary" @click="openCloneModal" data-testid="planogram-clone">📋 Clonar desde otra Máquina</button>
              <button type="button" class="vg-btn vg-btn-primary" @click="openSlotModal()" data-testid="planogram-add-slot">＋ Añadir carril</button>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px;">
            <div class="vg-card" style="padding: 12px 14px;">
              <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted); text-transform: uppercase;">Carriles activos</div>
              <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); font-weight: 500;">{{ planogramSummary.active_slots_count }}</div>
            </div>
            <div class="vg-card" style="padding: 12px 14px;">
              <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted); text-transform: uppercase;">🔴 Agotados</div>
              <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); font-weight: 500; color: var(--color-error-text);">{{ planogramSummary.stockout_slots_count }}</div>
            </div>
            <div class="vg-card" style="padding: 12px 14px;">
              <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted); text-transform: uppercase;">🟡 Bajo umbral</div>
              <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); font-weight: 500; color: var(--color-warning-text);">{{ planogramSummary.low_stock_slots_count }}</div>
            </div>
            <div class="vg-card" style="padding: 12px 14px;">
              <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted); text-transform: uppercase;">% Llenado estimado</div>
              <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); font-weight: 500; color: var(--color-primary);">{{ Number(planogramSummary.fill_rate_percent || 0).toFixed(1) }} %</div>
            </div>
          </div>

          <div v-if="isLoadingPlanogram" style="padding: 28px; text-align: center; color: var(--color-ink-muted);">Cargando matriz de carriles…</div>
          <div v-else class="table-responsive vg-card" style="padding: 0; overflow: auto;">
            <table class="table table-hover" data-testid="planogram-slot-table">
              <thead>
                <tr>
                  <th>Carril</th>
                  <th>Producto</th>
                  <th>Categoría</th>
                  <th>Nivel</th>
                  <th>Umbral</th>
                  <th>Semáforo</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="slot in planogramSlots" :key="slot.id || slot.slot_code" :data-slot-code="slot.slot_code">
                  <td style="font-family: var(--font-body); font-weight: 700;">{{ slot.slot_code }}</td>
                  <td>
                    <div style="font-weight: 600;">{{ slot.product_name }}</div>
                    <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ slot.product_sku }}</div>
                    <span v-if="slot.requires_cold_chain" style="font-size: var(--font-size-caption); color: var(--color-info-text);">❄️ Cadena de frío</span>
                    <span v-if="slot.product_is_active === false" style="margin-left: 6px; font-size: var(--font-size-caption); color: var(--color-error-text);">⚠️ Producto dado de baja (Sustituir)</span>
                  </td>
                  <td>{{ categoryLabel(slot.category || slot.product_category) }}</td>
                  <td style="min-width: 150px;">
                    <div style="display: flex; justify-content: space-between; font-size: var(--font-size-caption);">
                      <span>{{ slot.current_estimated_qty }} / {{ slot.max_capacity }}</span>
                      <span>{{ slotFillPercent(slot) }} %</span>
                    </div>
                    <div style="height: 6px; background-color: var(--color-surface-1); border-radius: var(--radius-xs); overflow: hidden; margin-top: 4px;">
                      <div :style="{ width: slotFillPercent(slot) + '%', height: '100%', backgroundColor: slot.stock_status === 'STOCKOUT' ? 'var(--color-error)' : (slot.stock_status === 'LOW_STOCK' ? 'var(--color-warning)' : 'var(--color-success)') }"></div>
                    </div>
                  </td>
                  <td>{{ slot.min_threshold }}</td>
                  <td>
                    <span style="display: inline-block; padding: 2px 8px; border-radius: var(--radius-interactive); font-size: var(--font-size-caption); font-weight: 600;" :style="stockStatusStyle(slot.stock_status)">{{ stockStatusLabel(slot.stock_status) }}</span>
                  </td>
                  <td>
                    <div class="vg-row-actions">
                      <button type="button" class="vg-btn vg-btn-sm vg-btn-secondary" @click="openSlotModal(slot)" :data-testid="'planogram-edit-slot-' + slot.slot_code">Editar</button>
                      <button type="button" class="vg-btn vg-btn-sm vg-btn-danger-soft" @click="deactivateSlot(slot)" :data-testid="'planogram-deactivate-slot-' + slot.slot_code">Retirar</button>
                    </div>
                  </td>
                </tr>
                <tr v-if="planogramSlots.length === 0">
                  <td colspan="7" style="text-align: center; padding: 28px; color: var(--color-ink-muted);">La máquina no tiene carriles activos.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </section>

      <!-- Modal de producto -->
      <ModalDialog v-model="showProductModal" :title="productForm.id ? 'Editar producto' : 'Nuevo producto'" :subtitle="productForm.id ? 'El SKU es inmutable tras el alta (C1).' : 'Alta en el catálogo maestro con validación sanitaria.'" size="lg">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
          <div>
            <label class="vg-label" for="product-sku">SKU</label>
            <input id="product-sku" v-model="productForm.sku" class="vg-input" :disabled="Boolean(productForm.id)" maxlength="32" />
            <div v-if="productErrors.sku" class="vg-field-error">{{ productErrors.sku }}</div>
          </div>
          <div>
            <label class="vg-label" for="product-name">Nombre</label>
            <input id="product-name" v-model="productForm.name" class="vg-input" maxlength="120" />
            <div v-if="productErrors.name" class="vg-field-error">{{ productErrors.name }}</div>
          </div>
          <div>
            <label class="vg-label" for="product-category">Categoría</label>
            <select id="product-category" v-model="productForm.category" class="vg-input" @change="productForm.unit_type = unitTypeOptions[0]?.value || productForm.unit_type">
              <option v-for="category in categoryOptions" :key="category.value" :value="category.value">{{ category.label }}</option>
            </select>
          </div>
          <div>
            <label class="vg-label" for="product-unit">Unidad</label>
            <select id="product-unit" v-model="productForm.unit_type" class="vg-input">
              <option v-for="unit in unitTypeOptions" :key="unit.value" :value="unit.value">{{ unit.label }}</option>
            </select>
          </div>
          <div>
            <label class="vg-label" for="product-cost">Coste unitario (€)</label>
            <input id="product-cost" v-model="productForm.unit_cost_eur" class="vg-input" type="number" min="0.01" step="0.01" />
            <div v-if="productErrors.unit_cost_eur" class="vg-field-error">{{ productErrors.unit_cost_eur }}</div>
          </div>
          <div>
            <label class="vg-label" for="product-sale">PVP (€)</label>
            <input id="product-sale" v-model="productForm.sale_price_eur" class="vg-input" type="number" min="0" step="0.01" />
            <div v-if="productErrors.sale_price_eur" class="vg-field-error">{{ productErrors.sale_price_eur }}</div>
          </div>
          <div>
            <label class="vg-label" for="product-shelf">Vida útil (días)</label>
            <input id="product-shelf" v-model="productForm.max_shelf_life_days" class="vg-input" type="number" min="1" max="3650" :disabled="!productForm.requires_cold_chain" />
            <div v-if="productErrors.max_shelf_life_days" class="vg-field-error">{{ productErrors.max_shelf_life_days }}</div>
          </div>
          <div style="display: flex; align-items: flex-end; padding-bottom: 4px;">
            <label style="display: inline-flex; align-items: center; gap: 8px; font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-slate);">
              <input v-model="productForm.requires_cold_chain" type="checkbox" />
              ❄️ Exige cadena de frío
            </label>
          </div>
          <div>
            <label class="vg-label">Margen unitario</label>
            <div style="font-family: var(--font-body); font-size: var(--font-size-body-md); color: var(--color-success-text);">
              {{ formatMoney(Number(productForm.sale_price_eur || 0) - Number(productForm.unit_cost_eur || 0)) }}
            </div>
          </div>
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showProductModal = false">Cancelar</button>
          <button type="button" class="vg-btn vg-btn-primary" :disabled="isSavingProduct" @click="submitProduct" data-testid="planogram-save-product">
            {{ isSavingProduct ? 'Guardando…' : 'Guardar producto' }}
          </button>
        </template>
      </ModalDialog>

      <!-- Modal de carril -->
      <ModalDialog v-model="showSlotModal" :title="slotForm.slot_code ? 'Carril ' + slotForm.slot_code : 'Nuevo carril'" subtitle="El guardado es un UPSERT; retirar un carril nunca borra la fila (C1)." size="md">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
          <div>
            <label class="vg-label" for="slot-code">Código de carril</label>
            <input id="slot-code" v-model="slotForm.slot_code" class="vg-input" maxlength="24" />
            <div v-if="slotErrors.slot_code" class="vg-field-error">{{ slotErrors.slot_code }}</div>
          </div>
          <div>
            <label class="vg-label" for="slot-product">Producto</label>
            <select id="slot-product" v-model="slotForm.product_id" class="vg-input">
              <option value="">Selecciona…</option>
              <option v-for="product in activeProductOptions" :key="product.id" :value="product.id">{{ product.sku }} · {{ product.name }}</option>
            </select>
            <div v-if="slotErrors.product_id" class="vg-field-error">{{ slotErrors.product_id }}</div>
          </div>
          <div>
            <label class="vg-label" for="slot-capacity">Capacidad</label>
            <input id="slot-capacity" v-model="slotForm.max_capacity" class="vg-input" type="number" min="1" max="10000" />
          </div>
          <div>
            <label class="vg-label" for="slot-qty">Existencia estimada</label>
            <input id="slot-qty" v-model="slotForm.current_estimated_qty" class="vg-input" type="number" min="0" />
          </div>
          <div>
            <label class="vg-label" for="slot-threshold">Umbral mínimo</label>
            <input id="slot-threshold" v-model="slotForm.min_threshold" class="vg-input" type="number" min="0" />
            <div v-if="slotErrors.min_threshold" class="vg-field-error">{{ slotErrors.min_threshold }}</div>
          </div>
          <div style="display: flex; align-items: flex-end; padding-bottom: 4px;">
            <label style="display: inline-flex; align-items: center; gap: 8px; font-size: var(--font-size-body-sm);">
              <input v-model="slotForm.is_active" type="checkbox" /> Carril activo
            </label>
          </div>
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showSlotModal = false">Cancelar</button>
          <button type="button" class="vg-btn vg-btn-primary" :disabled="isSavingSlot" @click="submitSlot" data-testid="planogram-save-slot">
            {{ isSavingSlot ? 'Guardando…' : 'Guardar carril' }}
          </button>
        </template>
      </ModalDialog>

      <!-- Modal de plantilla -->
      <ModalDialog v-model="showPresetModal" title="Aplicar plantilla del modelo" subtitle="Se preserva la existencia de los carriles cuyo producto no cambia (D1, C3)." size="sm">
        <label class="vg-label" for="preset-stock-mode">Stock inicial de carriles nuevos</label>
        <select id="preset-stock-mode" v-model="presetStockMode" class="vg-input">
          <option value="ZERO">Vacía (0)</option>
          <option value="FULL">Llena (100 %)</option>
        </select>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showPresetModal = false">Cancelar</button>
          <button type="button" class="vg-btn vg-btn-primary" :disabled="isApplyingPreset" @click="applyPreset" data-testid="planogram-confirm-preset">
            {{ isApplyingPreset ? 'Aplicando…' : 'Aplicar plantilla' }}
          </button>
        </template>
      </ModalDialog>

      <!-- Modal de clonación -->
      <ModalDialog v-model="showCloneModal" title="Clonar desde otra máquina" subtitle="Los productos dados de baja se omiten y se informan al terminar (QA 3)." size="md">
        <div v-if="cloneError" class="vg-alert vg-alert-error" style="margin-bottom: 12px;">⚠️ {{ cloneError }}</div>
        <div style="display: grid; gap: 12px;">
          <div>
            <label class="vg-label" for="clone-source">Máquina origen</label>
            <select id="clone-source" v-model="cloneSourceId" class="vg-input">
              <option value="">Selecciona una máquina…</option>
              <option v-for="machine in cloneSourceOptions" :key="machine.id" :value="machine.id">{{ machine.code }} · {{ machine.location_name || machine.model }}</option>
            </select>
          </div>
          <div>
            <label class="vg-label" for="clone-stock-mode">Stock inicial</label>
            <select id="clone-stock-mode" v-model="cloneStockMode" class="vg-input">
              <option value="ZERO">Vacía (0)</option>
              <option value="FULL">Llena (100 %)</option>
            </select>
          </div>
          <p style="margin: 0; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
            La máquina destino es <strong>{{ selectedMachine?.code || '—' }}</strong>. Las existencias de origen nunca se copian.
          </p>
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showCloneModal = false">Cancelar</button>
          <button type="button" class="vg-btn vg-btn-primary" :disabled="isCloning" @click="submitClone" data-testid="planogram-confirm-clone">
            {{ isCloning ? 'Clonando…' : 'Clonar planograma' }}
          </button>
        </template>
      </ModalDialog>
    </div>
  `
};

export default CoordinatorPlanogramTab;
