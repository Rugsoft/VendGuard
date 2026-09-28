/**
 * VendGuard - CoordinatorSparePartsTab Component (CoordinatorSparePartsTab.js)
 * 
 * Pestaña de Gestión y Catálogo Maestro de Repuestos para Coordinación (Módulo M2: RF-REP-01, RF-REP-02).
 * 
 * Características:
 * 1. Buscador en tiempo real por código, nombre, fabricante o notas.
 * 2. Filtros combinados por categoría técnica, modelo de máquina compatible y estado operativo.
 * 3. Conmutador de estado activo/inactivo (baja lógica/reactivación sin borrado físico, Art. III).
 * 4. Modal de alta y edición con selector dinámico de modelos de máquina compatibles.
 * 5. Visualización del coste de referencia oficial y desglose por componentes.
 * 6. Cumplimiento de directrices visuales Docker (docs/design.md):
 *    - Azul eléctrico (#2560ff) como color primario interactivo.
 *    - Radio conservador de 4px en botones, inputs y distintivos (badges).
 *    - Radio de 8px en tarjetas, contenedores de tabla y modales.
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias de npm).
 * Dualismo Lingüístico: Código y atributos en inglés, interfaz y mensajes en español.
 */

import { api } from '../api.js';

export const CoordinatorSparePartsTab = {
  name: 'CoordinatorSparePartsTab',
  emits: ['part-saved', 'status-toggled', 'open-analytics'],
  data() {
    return {
      parts: [],
      models: [],
      isLoading: false,
      errorMessage: '',
      successMessage: '',

      // Filtros reactivos
      searchQuery: '',
      categoryFilter: 'ALL',
      modelFilter: 'ALL',
      statusFilter: 'ALL', // 'ALL' | 'ACTIVE' | 'INACTIVE'

      // Modal de Alta / Edición
      showModal: false,
      isEditing: false,
      editingPartId: null,
      modalLoading: false,
      modalError: '',
      customModelInput: '',
      formData: {
        part_code: '',
        name: '',
        category: 'HYDRAULIC',
        manufacturer: '',
        reference_cost: '',
        compatible_models: [],
        notes: ''
      }
    };
  },
  computed: {
    categoryOptions() {
      return [
        { value: 'HYDRAULIC', label: 'Hidráulica y Presión' },
        { value: 'THERMAL', label: 'Térmico y Refrigeración' },
        { value: 'ELECTRONIC', label: 'Electrónica y Control' },
        { value: 'MECHANICAL', label: 'Mecánica y Extracción' },
        { value: 'PAYMENT_SYSTEM', label: 'Sistemas de Pago' },
        { value: 'CONSUMABLE', label: 'Consumibles y Juntas' },
        { value: 'OTHER', label: 'Otros Componentes' }
      ];
    },
    filteredParts() {
      return this.parts.filter(part => {
        // 1. Buscador en tiempo real (código, nombre, fabricante o notas)
        if (this.searchQuery && this.searchQuery.trim() !== '') {
          const q = this.searchQuery.trim().toLowerCase();
          const codeMatch = (part.part_code || '').toLowerCase().includes(q);
          const nameMatch = (part.name || '').toLowerCase().includes(q);
          const mfgMatch = (part.manufacturer || '').toLowerCase().includes(q);
          const notesMatch = (part.notes || '').toLowerCase().includes(q);
          if (!codeMatch && !nameMatch && !mfgMatch && !notesMatch) {
            return false;
          }
        }

        // 2. Filtro por categoría técnica
        if (this.categoryFilter !== 'ALL' && part.category !== this.categoryFilter) {
          return false;
        }

        // 3. Filtro por modelo de máquina compatible
        if (this.modelFilter !== 'ALL') {
          const partModels = Array.isArray(part.compatible_models) ? part.compatible_models : [];
          if (!partModels.includes(this.modelFilter)) {
            return false;
          }
        }

        // 4. Filtro por estado operativo (activo / inactivo)
        if (this.statusFilter === 'ACTIVE' && !part.is_active) {
          return false;
        }
        if (this.statusFilter === 'INACTIVE' && part.is_active) {
          return false;
        }

        return true;
      });
    },
    metrics() {
      const total = this.parts.length;
      const active = this.parts.filter(p => p.is_active).length;
      const inactive = total - active;
      const totalCost = this.parts.reduce((acc, p) => acc + (parseFloat(p.reference_cost) || 0), 0);
      const averageCost = total > 0 ? (totalCost / total) : 0;

      return {
        total,
        active,
        inactive,
        averageCost
      };
    },
    availableModelsForSelection() {
      const set = new Set([...(this.models || []), ...(this.formData.compatible_models || [])]);
      return Array.from(set).sort();
    }
  },
  mounted() {
    this.loadParts();
    this.loadModels();
  },
  methods: {
    /**
     * Carga el catálogo completo de repuestos desde el backend.
     */
    async loadParts() {
      this.isLoading = true;
      this.errorMessage = '';
      try {
        const res = await api.coordinator.getSparePartsCatalog();
        this.parts = res?.data || res || [];
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar el catálogo maestro de repuestos.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Carga el listado de modelos de máquinas registrados en el parque vending.
     */
    async loadModels() {
      try {
        const res = await api.coordinator.getSparePartModels();
        this.models = res?.data || res || [];
      } catch (err) {
        console.warn('No se pudieron precargar los modelos de máquinas:', err);
      }
    },

    /**
     * Abre el modal para dar de alta un nuevo repuesto en el catálogo.
     */
    openCreateModal() {
      this.isEditing = false;
      this.editingPartId = null;
      this.modalError = '';
      this.customModelInput = '';
      this.formData = {
        part_code: '',
        name: '',
        category: 'HYDRAULIC',
        manufacturer: '',
        reference_cost: '',
        compatible_models: [],
        notes: ''
      };
      this.showModal = true;
    },

    /**
     * Abre el modal en modo edición con los datos del repuesto seleccionado.
     */
    openEditModal(part) {
      this.isEditing = true;
      this.editingPartId = part.id;
      this.modalError = '';
      this.customModelInput = '';
      this.formData = {
        part_code: part.part_code || '',
        name: part.name || '',
        category: part.category || 'HYDRAULIC',
        manufacturer: part.manufacturer || '',
        reference_cost: part.reference_cost !== undefined ? part.reference_cost : '',
        compatible_models: Array.isArray(part.compatible_models) ? [...part.compatible_models] : [],
        notes: part.notes || ''
      };
      this.showModal = true;
    },

    /**
     * Cierra el modal de formulario.
     */
    closeModal() {
      this.showModal = false;
      this.modalError = '';
    },

    /**
     * Alterna la selección de un modelo compatible en el formulario.
     */
    toggleModelSelection(model) {
      const idx = this.formData.compatible_models.indexOf(model);
      if (idx > -1) {
        this.formData.compatible_models.splice(idx, 1);
      } else {
        this.formData.compatible_models.push(model);
      }
    },

    /**
     * Añade un modelo personalizado introducido manualmente por el coordinador.
     */
    addCustomModel() {
      const trimmed = (this.customModelInput || '').trim();
      if (!trimmed) return;

      if (!this.formData.compatible_models.includes(trimmed)) {
        this.formData.compatible_models.push(trimmed);
      }
      if (!this.models.includes(trimmed)) {
        this.models.push(trimmed);
      }
      this.customModelInput = '';
    },

    /**
     * Elimina un modelo de la lista de compatibles seleccionados.
     */
    removeModel(model) {
      this.formData.compatible_models = this.formData.compatible_models.filter(m => m !== model);
    },

    /**
     * Guarda el repuesto (alta o actualización) validando los requisitos RF-REP-01 y RF-REP-02.
     */
    async savePart() {
      this.modalError = '';

      // Validaciones en frontend
      const code = (this.formData.part_code || '').trim().toUpperCase();
      const name = (this.formData.name || '').trim();
      const category = this.formData.category;
      const manufacturer = (this.formData.manufacturer || '').trim();
      const costRaw = parseFloat(this.formData.reference_cost);

      if (!code) {
        this.modalError = 'El código de repuesto es obligatorio (ej: VALV-SOL-01).';
        return;
      }
      if (!name) {
        this.modalError = 'La denominación técnica del repuesto es obligatoria.';
        return;
      }
      if (!category) {
        this.modalError = 'Debe seleccionar una categoría técnica válida.';
        return;
      }
      if (!manufacturer) {
        this.modalError = 'El fabricante o proveedor del repuesto es obligatorio.';
        return;
      }
      if (isNaN(costRaw) || costRaw < 0) {
        this.modalError = 'El coste de referencia debe ser un valor numérico mayor o igual a 0.';
        return;
      }
      if (!this.formData.compatible_models || this.formData.compatible_models.length === 0) {
        this.modalError = 'Debe asociar al menos un modelo de máquina compatible.';
        return;
      }

      this.modalLoading = true;
      const payload = {
        part_code: code,
        name: name,
        category: category,
        manufacturer: manufacturer,
        reference_cost: costRaw,
        compatible_models: [...this.formData.compatible_models],
        notes: (this.formData.notes || '').trim() || null
      };

      try {
        let savedPart = null;
        if (this.isEditing && this.editingPartId) {
          savedPart = await api.coordinator.updateSparePart(this.editingPartId, payload);
          this.successMessage = `Repuesto ${code} actualizado con éxito.`;
        } else {
          savedPart = await api.coordinator.createSparePart(payload);
          this.successMessage = `Repuesto ${code} registrado en el catálogo maestro con éxito.`;
        }

        this.$emit('part-saved', savedPart?.data || savedPart || payload);
        this.closeModal();
        await this.loadParts();
        await this.loadModels();

        setTimeout(() => {
          this.successMessage = '';
        }, 4000);
      } catch (err) {
        this.modalError = err.message || 'Error al guardar el repuesto en el catálogo.';
      } finally {
        this.modalLoading = false;
      }
    },

    /**
     * Conmuta el estado operativo activo/inactivo (baja lógica sin borrado físico, Art. III).
     */
    async toggleStatus(part) {
      const targetStatus = !part.is_active;
      const actionName = targetStatus ? 'reactivar' : 'desactivar';

      try {
        await api.coordinator.toggleSparePartStatus(part.id, targetStatus);
        part.is_active = targetStatus;

        const msg = targetStatus
          ? `Repuesto ${part.part_code} reactivado con éxito en el catálogo.`
          : `Repuesto ${part.part_code} desactivado temporalmente (baja lógica).`;

        this.successMessage = msg;
        this.$emit('status-toggled', { id: part.id, part_code: part.part_code, is_active: targetStatus });

        setTimeout(() => {
          this.successMessage = '';
        }, 4000);
      } catch (err) {
        this.errorMessage = err.message || `Error al ${actionName} el repuesto ${part.part_code}.`;
      }
    },

    /**
     * Emite el evento para navegar a la pestaña analítica de repuestos.
     */
    handleOpenAnalytics() {
      this.$emit('open-analytics');
    },

    /**
     * Formatea un importe numérico en euros según la convención española.
     */
    formatPrice(amount) {
      const num = parseFloat(amount);
      if (isNaN(num)) return '0,00 €';
      return num.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    },

    /**
     * Retorna la etiqueta legible en español de la categoría técnica.
     */
    getCategoryLabel(category) {
      const opt = this.categoryOptions.find(c => c.value === category);
      return opt ? opt.label : (category || 'Sin categoría');
    },

    /**
     * Retorna estilo visual de distintivo según la categoría técnica.
     */
    getCategoryBadgeStyle(category) {
      switch (category) {
        case 'HYDRAULIC':
          return 'background-color: #e5f2fc; color: #003db5; border: 1px solid #c8cfda;';
        case 'THERMAL':
          return 'background-color: #fef3c7; color: #92400e; border: 1px solid #fcd34d;';
        case 'ELECTRONIC':
          return 'background-color: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe;';
        case 'MECHANICAL':
          return 'background-color: #f3f4f6; color: #1f2937; border: 1px solid #d1d5db;';
        case 'PAYMENT_SYSTEM':
          return 'background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0;';
        case 'CONSUMABLE':
          return 'background-color: #fce7f3; color: #831843; border: 1px solid #fbcfe8;';
        default:
          return 'background-color: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb;';
      }
    }
  },
  template: `
    <div class="coordinator-spare-parts-tab" style="padding-top: 8px;">
      <!-- Notificaciones de éxito y error -->
      <div
        v-if="errorMessage"
        style="background-color: #fddfdf; border: 1px solid #ff5757; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; display: flex; align-items: center; justify-content: space-between;"
      >
        <span>⚠️ {{ errorMessage }}</span>
        <button type="button" @click="errorMessage = ''" style="background: none; border: none; font-size: 16px; cursor: pointer; color: #991b1b;">&times;</button>
      </div>

      <div
        v-if="successMessage"
        style="background-color: #ecfdf5; border: 1px solid #38bd7d; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; display: flex; align-items: center; justify-content: space-between;"
      >
        <span>✅ {{ successMessage }}</span>
        <button type="button" @click="successMessage = ''" style="background: none; border: none; font-size: 16px; cursor: pointer; color: #065f46;">&times;</button>
      </div>

      <!-- Tarjetas métricas KPI de catálogo (Docs/design.md) -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
        <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 16px 20px;">
          <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #6c7e9d; margin-bottom: 4px;">Total Catalogados</div>
          <div style="font-size: 26px; font-weight: 700; color: #2c333f;" data-testid="kpi-total-parts">{{ metrics.total }}</div>
          <div style="font-size: 12px; color: #6c7e9d; margin-top: 4px;">Referencias técnicas maestras</div>
        </div>

        <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 16px 20px;">
          <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #38bd7d; margin-bottom: 4px;">Activos para Campo</div>
          <div style="font-size: 26px; font-weight: 700; color: #38bd7d;" data-testid="kpi-active-parts">{{ metrics.active }}</div>
          <div style="font-size: 12px; color: #6c7e9d; margin-top: 4px;">Disponibles para técnicos</div>
        </div>

        <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 16px 20px;">
          <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #ff5757; margin-bottom: 4px;">Bajas Lógicas</div>
          <div style="font-size: 26px; font-weight: 700; color: #ff5757;" data-testid="kpi-inactive-parts">{{ metrics.inactive }}</div>
          <div style="font-size: 12px; color: #6c7e9d; margin-top: 4px;">Desactivados temporalmente</div>
        </div>

        <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 16px 20px;">
          <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #2560ff; margin-bottom: 4px;">Coste Medio Ref.</div>
          <div style="font-size: 26px; font-weight: 700; color: #2560ff;" data-testid="kpi-average-cost">{{ formatPrice(metrics.averageCost) }}</div>
          <div style="font-size: 12px; color: #6c7e9d; margin-top: 4px;">Valor medio por repuesto</div>
        </div>
      </div>

      <!-- Barra de herramientas: Acciones y Filtros -->
      <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 16px 20px; margin-bottom: 20px;">
        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 14px;">
          <div>
            <h2 style="margin: 0; font-size: 18px; font-weight: 600; color: #2c333f;">Catálogo Maestro de Repuestos</h2>
            <p style="margin: 2px 0 0 0; font-size: 13px; color: #6c7e9d;">Control de referencias técnicas oficiales, compatibilidad por modelo y costes congelables.</p>
          </div>

          <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <button
              type="button"
              class="vg-btn"
              style="height: 38px; font-size: 13px; font-weight: 600; background-color: #ffffff; color: #2c333f; border: 1px solid #c8cfda; border-radius: 4px; padding: 0 14px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;"
              @click="handleOpenAnalytics"
              data-testid="btn-open-analytics"
              title="Ir al panel analítico de consumos y averías crónicas"
            >
              📊 Analítica de Consumos
            </button>

            <button
              type="button"
              class="vg-btn"
              style="height: 38px; font-size: 13px; font-weight: 600; background-color: #2560ff; color: #ffffff; border: none; border-radius: 4px; padding: 0 16px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;"
              @click="openCreateModal"
              data-testid="btn-open-create-part"
            >
              ➕ Alta de Repuesto
            </button>
          </div>
        </div>

        <!-- Controles de filtrado reactivo -->
        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding-top: 12px; border-top: 1px solid #c8cfda;">
          <!-- Buscador en tiempo real -->
          <div style="flex: 1 1 240px; min-width: 220px;">
            <input
              v-model="searchQuery"
              type="text"
              class="vg-input"
              placeholder="Buscar por código (ej: VALV-SOL-01), denominación o fabricante..."
              style="width: 100%; height: 36px; font-size: 13px; padding: 0 10px; border: 1px solid #c8cfda; border-radius: 4px; box-sizing: border-box;"
              data-testid="spare-parts-search-input"
            />
          </div>

          <!-- Filtro por categoría -->
          <div style="flex: 0 1 190px;">
            <select
              v-model="categoryFilter"
              class="vg-input"
              style="width: 100%; height: 36px; font-size: 13px; padding: 0 10px; border: 1px solid #c8cfda; border-radius: 4px; background-color: #ffffff; box-sizing: border-box;"
              data-testid="spare-parts-category-filter"
            >
              <option value="ALL">Todas las Categorías</option>
              <option v-for="cat in categoryOptions" :key="cat.value" :value="cat.value">
                {{ cat.label }}
              </option>
            </select>
          </div>

          <!-- Filtro por modelo compatible -->
          <div style="flex: 0 1 190px;">
            <select
              v-model="modelFilter"
              class="vg-input"
              style="width: 100%; height: 36px; font-size: 13px; padding: 0 10px; border: 1px solid #c8cfda; border-radius: 4px; background-color: #ffffff; box-sizing: border-box;"
              data-testid="spare-parts-model-filter"
            >
              <option value="ALL">Todos los Modelos</option>
              <option v-for="m in models" :key="m" :value="m">
                {{ m }}
              </option>
            </select>
          </div>

          <!-- Filtro por estado operativo -->
          <div style="display: flex; gap: 4px;">
            <button
              type="button"
              class="vg-btn"
              :style="{
                height: '36px',
                fontSize: '12px',
                fontWeight: '600',
                padding: '0 12px',
                borderRadius: '4px',
                border: '1px solid',
                cursor: 'pointer',
                borderColor: statusFilter === 'ALL' ? '#2560ff' : '#c8cfda',
                backgroundColor: statusFilter === 'ALL' ? '#2560ff' : '#ffffff',
                color: statusFilter === 'ALL' ? '#ffffff' : '#2c333f'
              }"
              @click="statusFilter = 'ALL'"
              data-testid="spare-parts-status-filter-all"
            >
              Todos ({{ metrics.total }})
            </button>
            <button
              type="button"
              class="vg-btn"
              :style="{
                height: '36px',
                fontSize: '12px',
                fontWeight: '600',
                padding: '0 12px',
                borderRadius: '4px',
                border: '1px solid',
                cursor: 'pointer',
                borderColor: statusFilter === 'ACTIVE' ? '#2560ff' : '#c8cfda',
                backgroundColor: statusFilter === 'ACTIVE' ? '#2560ff' : '#ffffff',
                color: statusFilter === 'ACTIVE' ? '#ffffff' : '#2c333f'
              }"
              @click="statusFilter = 'ACTIVE'"
              data-testid="spare-parts-status-filter-active"
            >
              Activos ({{ metrics.active }})
            </button>
            <button
              type="button"
              class="vg-btn"
              :style="{
                height: '36px',
                fontSize: '12px',
                fontWeight: '600',
                padding: '0 12px',
                borderRadius: '4px',
                border: '1px solid',
                cursor: 'pointer',
                borderColor: statusFilter === 'INACTIVE' ? '#2560ff' : '#c8cfda',
                backgroundColor: statusFilter === 'INACTIVE' ? '#2560ff' : '#ffffff',
                color: statusFilter === 'INACTIVE' ? '#ffffff' : '#2c333f'
              }"
              @click="statusFilter = 'INACTIVE'"
              data-testid="spare-parts-status-filter-inactive"
            >
              Inactivos ({{ metrics.inactive }})
            </button>
          </div>
        </div>
      </div>

      <!-- Tabla de Repuestos -->
      <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; overflow: hidden;">
        <div v-if="isLoading" style="padding: 40px; text-align: center; color: #6c7e9d; font-size: 14px;">
          ⏳ Cargando catálogo maestro de repuestos...
        </div>

        <div v-else-if="filteredParts.length === 0" style="padding: 48px 24px; text-align: center;" data-testid="empty-catalog-message">
          <div style="font-size: 32px; margin-bottom: 8px;">📦</div>
          <div style="font-size: 16px; font-weight: 600; color: #2c333f; margin-bottom: 4px;">No se encontraron repuestos</div>
          <div style="font-size: 13px; color: #6c7e9d;">No hay piezas que coincidan con los filtros aplicados o el término de búsqueda.</div>
        </div>

        <div v-else style="overflow-x: auto;">
          <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
            <thead>
              <tr style="background-color: #f9fafb; border-bottom: 1px solid #c8cfda; color: #6c7e9d; font-weight: 600; font-size: 12px; text-transform: uppercase;">
                <th style="padding: 12px 16px;">Código</th>
                <th style="padding: 12px 16px;">Denominación y Fabricante</th>
                <th style="padding: 12px 16px;">Categoría</th>
                <th style="padding: 12px 16px;">Coste Referencia</th>
                <th style="padding: 12px 16px;">Modelos Compatibles</th>
                <th style="padding: 12px 16px; text-align: center;">Estado</th>
                <th style="padding: 12px 16px; text-align: right;">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="part in filteredParts"
                :key="part.id"
                :data-testid="'part-row-' + part.id"
                style="border-bottom: 1px solid #c8cfda; transition: background-color 0.15s ease;"
                :style="{ backgroundColor: !part.is_active ? '#f9fafb' : '#ffffff', opacity: !part.is_active ? 0.75 : 1 }"
              >
                <!-- Código de pieza -->
                <td style="padding: 12px 16px; white-space: nowrap;">
                  <span style="display: inline-block; font-family: monospace; font-weight: 700; color: #2560ff; background-color: #e5f2fc; padding: 2px 6px; border-radius: 4px; border: 1px solid #c8cfda;">
                    {{ part.part_code }}
                  </span>
                </td>

                <!-- Denominación y Fabricante -->
                <td style="padding: 12px 16px;">
                  <div style="font-weight: 600; color: #2c333f;">{{ part.name }}</div>
                  <div style="font-size: 12px; color: #6c7e9d; margin-top: 2px;">
                    🏭 {{ part.manufacturer || 'Fabricante genérico' }}
                    <span v-if="part.notes" style="margin-left: 8px; font-style: italic;" :title="part.notes">
                      📝 {{ part.notes.length > 35 ? part.notes.substring(0, 35) + '...' : part.notes }}
                    </span>
                  </div>
                </td>

                <!-- Categoría técnica -->
                <td style="padding: 12px 16px; white-space: nowrap;">
                  <span
                    :style="getCategoryBadgeStyle(part.category)"
                    style="display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;"
                  >
                    {{ getCategoryLabel(part.category) }}
                  </span>
                </td>

                <!-- Coste de referencia -->
                <td style="padding: 12px 16px; white-space: nowrap;">
                  <span style="font-weight: 700; color: #2c333f; font-size: 14px;">
                    {{ formatPrice(part.reference_cost) }}
                  </span>
                </td>

                <!-- Modelos compatibles -->
                <td style="padding: 12px 16px;">
                  <div style="display: flex; flex-wrap: wrap; gap: 4px; max-width: 260px;">
                    <span
                      v-for="model in (part.compatible_models || [])"
                      :key="model"
                      style="display: inline-block; background-color: #f3f4f6; color: #2c333f; border: 1px solid #d1d5db; padding: 1px 6px; border-radius: 4px; font-size: 11px; font-weight: 500;"
                    >
                      {{ model }}
                    </span>
                    <span v-if="!part.compatible_models || part.compatible_models.length === 0" style="color: #ff5757; font-size: 11px; font-weight: 600;">
                      ⚠️ Sin modelos asignados
                    </span>
                  </div>
                </td>

                <!-- Estado operativo (Activo/Inactivo) -->
                <td style="padding: 12px 16px; text-align: center; white-space: nowrap;">
                  <span
                    v-if="part.is_active"
                    style="display: inline-block; background-color: #ecfdf5; color: #065f46; border: 1px solid #38bd7d; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700;"
                  >
                    ACTIVO
                  </span>
                  <span
                    v-else
                    style="display: inline-block; background-color: #f3f4f6; color: #6c7e9d; border: 1px solid #c8cfda; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700;"
                  >
                    INACTIVO
                  </span>
                </td>

                <!-- Acciones -->
                <td style="padding: 12px 16px; text-align: right; white-space: nowrap;">
                  <div style="display: inline-flex; align-items: center; gap: 6px;">
                    <button
                      type="button"
                      class="vg-btn"
                      style="height: 30px; font-size: 12px; font-weight: 600; background-color: #ffffff; color: #2c333f; border: 1px solid #c8cfda; border-radius: 4px; padding: 0 10px; cursor: pointer;"
                      @click="openEditModal(part)"
                      :data-testid="'btn-edit-part-' + part.id"
                      title="Editar especificaciones y compatibilidad"
                    >
                      ✏️ Editar
                    </button>

                    <button
                      type="button"
                      class="vg-btn"
                      :style="{
                        height: '30px',
                        fontSize: '12px',
                        fontWeight: '600',
                        borderRadius: '4px',
                        padding: '0 10px',
                        cursor: 'pointer',
                        border: '1px solid',
                        backgroundColor: part.is_active ? '#ffffff' : '#ecfdf5',
                        borderColor: part.is_active ? '#ff5757' : '#38bd7d',
                        color: part.is_active ? '#991b1b' : '#065f46'
                      }"
                      @click="toggleStatus(part)"
                      :data-testid="'btn-toggle-status-' + part.id"
                      :title="part.is_active ? 'Desactivar repuesto (baja lógica)' : 'Reactivar repuesto en el catálogo'"
                    >
                      {{ part.is_active ? '🚫 Desactivar' : '✅ Reactivar' }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Modal de Alta / Edición de Repuesto (Docs/design.md: 8px radius) -->
      <div
        v-if="showModal"
        style="position: fixed; inset: 0; background-color: rgba(0, 0, 0, 0.5); display: flex; align-items: center; justify-content: center; z-index: 1050; padding: 16px;"
        data-testid="spare-part-modal"
      >
        <div
          style="background-color: #ffffff; border-radius: 8px; width: 100%; max-width: 650px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); overflow: hidden;"
        >
          <!-- Encabezado del Modal -->
          <div style="padding: 16px 20px; border-bottom: 1px solid #c8cfda; display: flex; align-items: center; justify-content: space-between; background-color: #f9fafb;">
            <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #2c333f;">
              {{ isEditing ? '✏️ Editar Repuesto: ' + formData.part_code : '➕ Alta de Nuevo Repuesto en Catálogo' }}
            </h3>
            <button
              type="button"
              @click="closeModal"
              style="background: none; border: none; font-size: 20px; font-weight: 700; color: #6c7e9d; cursor: pointer;"
              data-testid="modal-btn-close-header"
            >
              &times;
            </button>
          </div>

          <!-- Cuerpo del Modal (Scrollable) -->
          <div style="padding: 20px; overflow-y: auto; flex: 1 1 auto;">
            <!-- Error dentro del modal -->
            <div
              v-if="modalError"
              style="background-color: #fddfdf; border: 1px solid #ff5757; color: #991b1b; padding: 10px 14px; border-radius: 4px; margin-bottom: 16px; font-size: 13px;"
              data-testid="modal-error-message"
            >
              ⚠️ {{ modalError }}
            </div>

            <form @submit.prevent="savePart" style="display: flex; flex-direction: column; gap: 14px;">
              <!-- Fila 1: Código y Categoría -->
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                <div>
                  <label style="display: block; font-size: 12px; font-weight: 600; color: #2c333f; margin-bottom: 4px;">
                    Código de Repuesto *
                  </label>
                  <input
                    v-model="formData.part_code"
                    type="text"
                    placeholder="Ej: VALV-SOL-01"
                    style="width: 100%; height: 36px; font-size: 13px; font-family: monospace; font-weight: 700; text-transform: uppercase; padding: 0 10px; border: 1px solid #c8cfda; border-radius: 4px; box-sizing: border-box;"
                    data-testid="modal-input-part-code"
                    :disabled="isEditing"
                  />
                  <div style="font-size: 11px; color: #6c7e9d; margin-top: 2px;">
                    {{ isEditing ? 'El código es inmutable para preservar trazabilidad.' : 'Alfanumérico único para identificación técnica.' }}
                  </div>
                </div>

                <div>
                  <label style="display: block; font-size: 12px; font-weight: 600; color: #2c333f; margin-bottom: 4px;">
                    Categoría Técnica *
                  </label>
                  <select
                    v-model="formData.category"
                    style="width: 100%; height: 36px; font-size: 13px; padding: 0 10px; border: 1px solid #c8cfda; border-radius: 4px; background-color: #ffffff; box-sizing: border-box;"
                    data-testid="modal-select-category"
                  >
                    <option v-for="cat in categoryOptions" :key="cat.value" :value="cat.value">
                      {{ cat.label }}
                    </option>
                  </select>
                </div>
              </div>

              <!-- Fila 2: Denominación técnica -->
              <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #2c333f; margin-bottom: 4px;">
                  Denominación Técnica Oficial *
                </label>
                <input
                  v-model="formData.name"
                  type="text"
                  placeholder="Ej: Electroválvula 24V 2 Vías con Bobina"
                  style="width: 100%; height: 36px; font-size: 13px; padding: 0 10px; border: 1px solid #c8cfda; border-radius: 4px; box-sizing: border-box;"
                  data-testid="modal-input-name"
                />
              </div>

              <!-- Fila 3: Fabricante y Coste de Referencia -->
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                <div>
                  <label style="display: block; font-size: 12px; font-weight: 600; color: #2c333f; margin-bottom: 4px;">
                    Fabricante / Proveedor *
                  </label>
                  <input
                    v-model="formData.manufacturer"
                    type="text"
                    placeholder="Ej: Ceme / Parker"
                    style="width: 100%; height: 36px; font-size: 13px; padding: 0 10px; border: 1px solid #c8cfda; border-radius: 4px; box-sizing: border-box;"
                    data-testid="modal-input-manufacturer"
                  />
                </div>

                <div>
                  <label style="display: block; font-size: 12px; font-weight: 600; color: #2c333f; margin-bottom: 4px;">
                    Coste de Referencia (€) *
                  </label>
                  <input
                    v-model="formData.reference_cost"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="0.00"
                    style="width: 100%; height: 36px; font-size: 13px; padding: 0 10px; border: 1px solid #c8cfda; border-radius: 4px; box-sizing: border-box;"
                    data-testid="modal-input-reference-cost"
                  />
                  <div style="font-size: 11px; color: #6c7e9d; margin-top: 2px;">
                    Se congelará de forma inmutable al resolver incidencias.
                  </div>
                </div>
              </div>

              <!-- Fila 4: Modelos Compatibles (Selector Dinámico) -->
              <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #2c333f; margin-bottom: 4px;">
                  Modelos de Máquinas Compatibles * (mínimo 1)
                </label>
                <div style="border: 1px solid #c8cfda; border-radius: 4px; padding: 10px; background-color: #f9fafb; margin-bottom: 8px;">
                  <div style="font-size: 11px; color: #6c7e9d; margin-bottom: 6px;">
                    Seleccione los modelos del parque compatibles con esta referencia técnica:
                  </div>

                  <!-- Chips de selección rápida -->
                  <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px;">
                    <button
                      v-for="model in availableModelsForSelection"
                      :key="model"
                      type="button"
                      :style="{
                        fontSize: '12px',
                        padding: '3px 8px',
                        borderRadius: '4px',
                        border: '1px solid',
                        cursor: 'pointer',
                        borderColor: formData.compatible_models.includes(model) ? '#2560ff' : '#c8cfda',
                        backgroundColor: formData.compatible_models.includes(model) ? '#e5f2fc' : '#ffffff',
                        color: formData.compatible_models.includes(model) ? '#003db5' : '#2c333f',
                        fontWeight: formData.compatible_models.includes(model) ? '600' : 'normal'
                      }"
                      @click="toggleModelSelection(model)"
                      :data-testid="'model-chip-' + model"
                    >
                      <span v-if="formData.compatible_models.includes(model)">✓ </span>
                      {{ model }}
                    </button>
                  </div>

                  <!-- Campo para agregar un nuevo modelo no registrado en la flota actual -->
                  <div style="display: flex; gap: 6px; align-items: center;">
                    <input
                      v-model="customModelInput"
                      type="text"
                      placeholder="Añadir otro modelo no listado..."
                      style="flex: 1 1 auto; height: 32px; font-size: 12px; padding: 0 8px; border: 1px solid #c8cfda; border-radius: 4px; box-sizing: border-box;"
                      @keyup.enter.prevent="addCustomModel"
                      data-testid="modal-input-custom-model"
                    />
                    <button
                      type="button"
                      class="vg-btn"
                      style="height: 32px; font-size: 12px; font-weight: 600; background-color: #ffffff; color: #2560ff; border: 1px solid #2560ff; border-radius: 4px; padding: 0 10px; cursor: pointer;"
                      @click="addCustomModel"
                      data-testid="modal-btn-add-custom-model"
                    >
                      + Añadir
                    </button>
                  </div>
                </div>

                <!-- Chips seleccionados -->
                <div v-if="formData.compatible_models.length > 0" style="display: flex; flex-wrap: wrap; gap: 4px;">
                  <span
                    v-for="model in formData.compatible_models"
                    :key="model"
                    style="display: inline-flex; align-items: center; gap: 4px; background-color: #2560ff; color: #ffffff; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;"
                  >
                    {{ model }}
                    <span
                      @click="removeModel(model)"
                      style="cursor: pointer; font-size: 14px; font-weight: 700; line-height: 1;"
                      title="Eliminar compatibilidad"
                    >&times;</span>
                  </span>
                </div>
              </div>

              <!-- Fila 5: Notas técnicas -->
              <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #2c333f; margin-bottom: 4px;">
                  Notas Técnicas y Observaciones
                </label>
                <textarea
                  v-model="formData.notes"
                  rows="2"
                  placeholder="Observaciones de instalación, tolerancias o números de serie alternativos..."
                  style="width: 100%; font-size: 13px; padding: 8px 10px; border: 1px solid #c8cfda; border-radius: 4px; box-sizing: border-box; resize: vertical;"
                  data-testid="modal-input-notes"
                ></textarea>
              </div>
            </form>
          </div>

          <!-- Pie del Modal -->
          <div style="padding: 14px 20px; border-top: 1px solid #c8cfda; background-color: #f9fafb; display: flex; align-items: center; justify-content: flex-end; gap: 10px;">
            <button
              type="button"
              class="vg-btn"
              style="height: 36px; font-size: 13px; font-weight: 600; background-color: #ffffff; color: #2c333f; border: 1px solid #c8cfda; border-radius: 4px; padding: 0 16px; cursor: pointer;"
              @click="closeModal"
              :disabled="modalLoading"
              data-testid="modal-btn-cancel"
            >
              Cancelar
            </button>
            <button
              type="button"
              class="vg-btn"
              style="height: 36px; font-size: 13px; font-weight: 600; background-color: #2560ff; color: #ffffff; border: none; border-radius: 4px; padding: 0 20px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;"
              @click="savePart"
              :disabled="modalLoading"
              data-testid="modal-btn-save"
            >
              <span v-if="modalLoading">⏳ Guardando...</span>
              <span v-else>{{ isEditing ? 'Guardar Cambios' : 'Registrar Repuesto' }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  `
};
