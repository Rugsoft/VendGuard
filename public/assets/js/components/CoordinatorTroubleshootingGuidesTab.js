/**
 * VendGuard - CoordinatorTroubleshootingGuidesTab (CoordinatorTroubleshootingGuidesTab.js)
 *
 * Gestión del catálogo de guías rápidas de diagnóstico (Módulo 28, RF-04.1–RF-04.3,
 * RF-05.1, RNF-01–RNF-03, C1–C3, QA 1 y QA 2).
 *
 * TRES LECTURAS EN UNA PANTALLA
 *
 *  - **Cobertura (`QA 1`):** tres tarjetas KPI con las guías activas, los modelos
 *    del parque cubiertos y los modelos activos que aún no tienen guía. El
 *    resumen lo calcula el servidor sobre el parque global y no se apaga al
 *    filtrar la tabla inferior.
 *  - **Catálogo filtrable:** modelo, categoría, estado y búsqueda textual. La
 *    tabla solo pinta; las reglas viven en `TroubleshootingGuideService`.
 *  - **Alta y edición auditadas (`C1`–`C3`, `QA 2`):** el modal respeta los
 *    límites del contrato (título 5..150, 2..10 pasos, 0..20 códigos, 0..10
 *    repuestos, causa raíz de la categoría o `OTHER`) y en edición bloquea
 *    modelo y categoría, que son inmutables.
 *
 * SIN DINERO
 *
 * Ni la tabla, ni el modal, ni los chips de repuestos exponen coste, precio o
 * importe: el catálogo de guías es una herramienta de diagnóstico (`Art. V.4`).
 *
 * DISEÑO
 *
 * Tokens del sistema (`var(--color-…)`, `var(--radius-…)`, `var(--font-…)`) y
 * cero literales hexadecimales. Los botones visten la escalera `vg-btn` con su
 * peldaño y su tono, sin geometría inline: lo vigilan
 * `CoordinatorButtonVariantsGuardTest`, `LegacyButtonPaletteRetirementTest` y
 * `DesignTokenDebtRatchetTest`.
 *
 * Dogma Vanilla: Vue 3 Options API con plantilla en navegador, sin dependencias.
 * Dualismo Lingüístico: código en inglés, interfaz y mensajes en castellano.
 */

import { api } from '../api.js';
import { ModalDialog } from './ModalDialog.js';

/** Categorías canónicas del contrato (`incident_category`). */
const CATEGORY_OPTIONS = Object.freeze([
  { value: 'TEMPERATURE_COLD', label: 'Cadena de frío' },
  { value: 'PAYMENT_SYSTEM', label: 'Sistema de pago' },
  { value: 'PRODUCT_JAM', label: 'Atasco de producto' },
  { value: 'ELECTRICAL_OFF', label: 'Fallo eléctrico' },
  { value: 'OTHER', label: 'Otra causa / transversal' }
]);

const CATEGORY_LABELS = Object.freeze(
  CATEGORY_OPTIONS.reduce((index, option) => {
    index[option.value] = option.label;
    return index;
  }, {})
);

/** Pasos mínimos y máximos del editor (contrato §2). */
const MIN_STEPS = 2;
const MAX_STEPS = 10;
const MAX_ERROR_CODES = 20;
const MAX_SPARE_PARTS = 10;

/** Cuenta bloques alfanuméricos reales de una instrucción (`Art. V.1`, `C3`). */
function realWordBlocks(value) {
  return (String(value ?? '').match(/[\p{L}\p{N}]+/gu) ?? []).length;
}

/** Formulario vacío; se clona siempre para no compartir arrays. */
function emptyForm() {
  return {
    id: null,
    machine_model: '',
    custom_model: '',
    breakdown_type: 'TEMPERATURE_COLD',
    title: '',
    error_codes: [],
    steps: [
      { title: '', instruction: '' },
      { title: '', instruction: '' }
    ],
    recommended_spare_part_ids: [],
    suggested_root_cause_id: ''
  };
}

export const CoordinatorTroubleshootingGuidesTab = {
  name: 'CoordinatorTroubleshootingGuidesTab',
  components: {
    ModalDialog
  },
  data() {
    return {
      guides: [],
      coverage: {},
      models: [],
      spareParts: [],
      rootCauses: [],
      isLoading: false,
      isSaving: false,
      errorMessage: '',
      successMessage: '',

      categoryOptions: CATEGORY_OPTIONS,
      minSteps: MIN_STEPS,
      maxSteps: MAX_STEPS,
      maxErrorCodes: MAX_ERROR_CODES,
      maxSpareParts: MAX_SPARE_PARTS,

      modelFilter: 'ALL',
      categoryFilter: 'ALL',
      statusFilter: 'ALL',
      searchQuery: '',

      showModal: false,
      isEditing: false,
      formError: '',
      form: emptyForm(),
      originalSuggestedRootCauseId: null,
      originalSparePartIds: []
    };
  },
  computed: {
    activeGuides() {
      return this.guides.filter((guide) => guide.is_active === true).length;
    },

    totalActiveGuides() {
      const value = Number(this.coverage?.total_active_guides);
      return Number.isFinite(value) ? value : this.activeGuides;
    },

    coveredModels() {
      const value = Number(this.coverage?.covered_fleet_models_count);
      return Number.isFinite(value) ? value : 0;
    },

    totalFleetModels() {
      const value = Number(this.coverage?.total_active_fleet_models_count);
      return Number.isFinite(value) ? value : 0;
    },

    orphanModels() {
      return Array.isArray(this.coverage?.orphan_fleet_models) ? this.coverage.orphan_fleet_models : [];
    },

    orphanCount() {
      const value = Number(this.coverage?.orphan_fleet_models_count);
      return Number.isFinite(value) ? value : this.orphanModels.length;
    },

    /** Modelos seleccionables: parque real + modelos ya gobernados por el catálogo. */
    modelOptions() {
      const unique = new Map();
      for (const model of this.models) {
        const label = typeof model === 'string' ? model : (model?.machine_model || model?.model || '');
        const trimmed = String(label).trim();
        if (trimmed !== '') {
          unique.set(trimmed.toLowerCase(), trimmed);
        }
      }
      for (const guide of this.guides) {
        const trimmed = String(guide?.machine_model ?? '').trim();
        if (trimmed !== '') {
          unique.set(trimmed.toLowerCase(), trimmed);
        }
      }

      return [...unique.values()].sort((left, right) => left.localeCompare(right));
    },

    activeSpareParts() {
      return this.spareParts.filter((part) => part && part.is_active !== false);
    },

    /** Causas activas de la categoría del formulario más las transversales `OTHER`. */
    rootCauseOptions() {
      const category = String(this.form.breakdown_type ?? '').trim().toUpperCase();
      const active = this.rootCauses.filter((cause) => cause && cause.is_active !== false);

      return active
        .filter((cause) => category === '' || category === 'OTHER'
          || String(cause.category ?? '').toUpperCase() === category
          || String(cause.category ?? '').toUpperCase() === 'OTHER')
        .sort((left, right) => String(left.code ?? '').localeCompare(String(right.code ?? '')));
    },

    filteredGuides() {
      const query = this.searchQuery.trim().toLowerCase();

      return this.guides.filter((guide) => {
        if (this.modelFilter !== 'ALL'
          && String(guide.machine_model_key ?? '').toLowerCase() !== this.modelFilter.toLowerCase()) {
          return false;
        }
        if (this.categoryFilter !== 'ALL' && guide.breakdown_type !== this.categoryFilter) {
          return false;
        }
        if (this.statusFilter === 'ACTIVE' && guide.is_active !== true) {
          return false;
        }
        if (this.statusFilter === 'INACTIVE' && guide.is_active === true) {
          return false;
        }
        if (query !== '') {
          const haystack = [
            guide.machine_model,
            guide.title,
            ...(Array.isArray(guide.error_codes) ? guide.error_codes.map((entry) => entry.code) : [])
          ].join(' ').toLowerCase();
          if (!haystack.includes(query)) {
            return false;
          }
        }

        return true;
      });
    },

    resolvedModel() {
      return String(this.form.custom_model ?? '').trim() || String(this.form.machine_model ?? '').trim();
    },

    titleLength() {
      return Array.from(String(this.form.title ?? '').trim()).length;
    },

    errorCodesValid() {
      const seen = new Set();

      return this.form.error_codes.every((entry) => {
        const code = String(entry?.code ?? '').trim();
        const meaning = String(entry?.meaning ?? '').trim();
        const meaningLength = Array.from(meaning).length;

        if (code === '' || code.length > 20 || meaningLength < 5 || meaningLength > 200) {
          return false;
        }
        const key = code.toLowerCase();
        if (seen.has(key)) {
          return false;
        }
        seen.add(key);

        return true;
      });
    },

    isFormValid() {
      if (this.resolvedModel.length < 2 || this.resolvedModel.length > 100) {
        return false;
      }
      if (this.titleLength < 5 || this.titleLength > 150) {
        return false;
      }
      if (!Array.isArray(this.form.steps) || this.form.steps.length < MIN_STEPS) {
        return false;
      }
      if (!this.errorCodesValid) {
        return false;
      }

      return this.form.steps.every((step) => this.stepErrors(step).length === 0);
    }
  },
  mounted() {
    this.loadCatalog();
  },
  methods: {
    categoryLabel(category) {
      return CATEGORY_LABELS[category] || String(category ?? '');
    },

    async loadCatalog() {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        const [catalog, models, parts, causes] = await Promise.all([
          api.getCoordinatorTroubleshootingGuides(),
          api.coordinator.getSparePartModels().catch(() => []),
          api.coordinator.getSparePartsCatalog().catch(() => []),
          api.getCoordinatorRootCauses({ only_active: true }).catch(() => [])
        ]);

        this.coverage = catalog?.coverage_summary && typeof catalog.coverage_summary === 'object'
          ? catalog.coverage_summary
          : {};
        this.guides = Array.isArray(catalog?.guides) ? catalog.guides : [];

        const modelList = Array.isArray(models) ? models : (Array.isArray(models?.items) ? models.items : []);
        this.models = modelList;

        const partList = Array.isArray(parts) ? parts : (Array.isArray(parts?.items) ? parts.items : []);
        this.spareParts = partList;

        const causeList = Array.isArray(causes) ? causes : (Array.isArray(causes?.items) ? causes.items : []);
        this.rootCauses = causeList;
      } catch (error) {
        this.errorMessage = error?.message
          || 'No se pudo cargar el catálogo de guías de diagnóstico.';
      } finally {
        this.isLoading = false;
      }
    },

    openCreate(model = '') {
      this.isEditing = false;
      this.formError = '';
      this.form = emptyForm();
      this.originalSuggestedRootCauseId = null;
      this.originalSparePartIds = [];
      const label = String(model ?? '').trim();
      if (label !== '' && this.modelOptions.some((option) => option.toLowerCase() === label.toLowerCase())) {
        this.form.machine_model = this.modelOptions.find((option) => option.toLowerCase() === label.toLowerCase());
      } else {
        this.form.custom_model = label;
      }
      this.showModal = true;
    },

    openEdit(guide) {
      this.isEditing = true;
      this.formError = '';
      this.originalSuggestedRootCauseId = guide.suggested_root_cause_id == null
        ? null
        : Number(guide.suggested_root_cause_id);
      this.originalSparePartIds = Array.isArray(guide.recommended_spare_part_ids)
        ? guide.recommended_spare_part_ids.map(Number)
        : [];
      this.form = {
        id: Number(guide.id),
        machine_model: guide.machine_model,
        custom_model: '',
        breakdown_type: guide.breakdown_type,
        title: guide.title,
        error_codes: Array.isArray(guide.error_codes)
          ? guide.error_codes.map((entry) => ({ code: entry.code, meaning: entry.meaning }))
          : [],
        steps: Array.isArray(guide.steps) && guide.steps.length >= MIN_STEPS
          ? guide.steps.map((step) => ({ title: step.title, instruction: step.instruction }))
          : emptyForm().steps,
        recommended_spare_part_ids: Array.isArray(guide.recommended_spare_part_ids)
          ? guide.recommended_spare_part_ids.map(Number)
          : [],
        suggested_root_cause_id: this.originalSuggestedRootCauseId ?? ''
      };
      this.showModal = true;
    },

    closeModal() {
      this.showModal = false;
      this.formError = '';
      this.form = emptyForm();
      this.originalSuggestedRootCauseId = null;
      this.originalSparePartIds = [];
    },

    sameIdList(left, right) {
      if (left.length !== right.length) {
        return false;
      }

      const normalizedLeft = [...left].map(Number).sort((a, b) => a - b);
      const normalizedRight = [...right].map(Number).sort((a, b) => a - b);
      return normalizedLeft.every((value, index) => value === normalizedRight[index]);
    },

    addErrorCode() {
      if (this.form.error_codes.length >= MAX_ERROR_CODES) {
        return;
      }
      this.form.error_codes.push({ code: '', meaning: '' });
    },

    removeErrorCode(index) {
      this.form.error_codes.splice(index, 1);
    },

    addStep() {
      if (this.form.steps.length >= MAX_STEPS) {
        return;
      }
      this.form.steps.push({ title: '', instruction: '' });
    },

    removeStep(index) {
      if (this.form.steps.length <= MIN_STEPS) {
        return;
      }
      this.form.steps.splice(index, 1);
    },

    /** Errores de un paso, con los mismos límites que el validador del servidor. */
    stepErrors(step) {
      const errors = [];
      const titleLength = Array.from(String(step?.title ?? '').trim()).length;
      const instruction = String(step?.instruction ?? '').trim();
      const instructionLength = Array.from(instruction).length;

      if (titleLength < 3 || titleLength > 120) {
        errors.push('título 3..120');
      }
      if (instructionLength < 20 || instructionLength > 500) {
        errors.push('instrucción 20..500');
      }
      if (realWordBlocks(instruction) < 3) {
        errors.push('≥ 3 palabras reales');
      }

      return errors;
    },

    stepErrorLabel(step) {
      const errors = this.stepErrors(step);
      return errors.length === 0 ? '' : errors.join(' · ');
    },

    isPartSelected(partId) {
      return this.form.recommended_spare_part_ids.includes(Number(partId));
    },

    togglePart(partId) {
      const id = Number(partId);
      const index = this.form.recommended_spare_part_ids.indexOf(id);

      if (index >= 0) {
        this.form.recommended_spare_part_ids.splice(index, 1);
      } else if (this.form.recommended_spare_part_ids.length < MAX_SPARE_PARTS) {
        this.form.recommended_spare_part_ids.push(id);
      }
    },

    async submitForm() {
      if (!this.isFormValid || this.isSaving) {
        this.formError = 'Revisa el modelo, el título y los pasos antes de guardar.';
        return;
      }

      this.isSaving = true;
      this.formError = '';

      const selectedPartIds = this.form.recommended_spare_part_ids
        .map(Number)
        .filter((id) => this.activeSpareParts.some((part) => Number(part.id) === id));
      const selectedRootCauseId = this.form.suggested_root_cause_id === ''
        ? null
        : Number(this.form.suggested_root_cause_id);

      const payload = {
        title: String(this.form.title).trim(),
        error_codes: this.form.error_codes
          .map((entry) => ({
            code: String(entry.code ?? '').trim(),
            meaning: String(entry.meaning ?? '').trim()
          }))
          .filter((entry) => entry.code !== '' || entry.meaning !== ''),
        steps: this.form.steps.map((step, index) => ({
          step_order: index + 1,
          title: String(step.title ?? '').trim(),
          instruction: String(step.instruction ?? '').trim()
        })),
        recommended_spare_part_ids: selectedPartIds,
        suggested_root_cause_id: selectedRootCauseId
      };

      try {
        if (this.isEditing) {
          // C2: una causa o una pieza desactivada después no bloquea la edición
          // del resto. Sólo se envían los campos mutables que cambiaron de verdad.
          if (this.sameIdList(selectedPartIds, this.originalSparePartIds)) {
            delete payload.recommended_spare_part_ids;
          }
          if (selectedRootCauseId === this.originalSuggestedRootCauseId) {
            delete payload.suggested_root_cause_id;
          }

          await api.updateTroubleshootingGuide(this.form.id, payload);
          this.successMessage = 'Guía de diagnóstico actualizada correctamente.';
        } else {
          await api.createTroubleshootingGuide({
            ...payload,
            machine_model: this.resolvedModel,
            breakdown_type: this.form.breakdown_type
          });
          this.successMessage = 'Guía de diagnóstico creada correctamente.';
        }

        this.closeModal();
        await this.loadCatalog();
      } catch (error) {
        this.formError = error?.message || 'No se pudo guardar la guía de diagnóstico.';
      } finally {
        this.isSaving = false;
      }
    },

    async toggleStatus(guide) {
      if (!guide || this.isSaving) {
        return;
      }

      this.isSaving = true;
      this.errorMessage = '';
      try {
        await api.updateTroubleshootingGuideStatus(guide.id, guide.is_active !== true);
        this.successMessage = guide.is_active
          ? 'Guía de diagnóstico desactivada correctamente.'
          : 'Guía de diagnóstico reactivada correctamente.';
        await this.loadCatalog();
      } catch (error) {
        this.errorMessage = error?.message || 'No se pudo cambiar la vigencia de la guía.';
      } finally {
        this.isSaving = false;
      }
    },

    formatDate(value) {
      if (!value) {
        return '—';
      }
      const date = new Date(value);
      return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString('es-ES');
    }
  },
  template: `
    <div class="vg-guides-tab" data-testid="coordinator-guides-tab">
      <div v-if="errorMessage" class="vg-guides-tab__alert vg-guides-tab__alert--error" role="alert">
        <span>⚠️ {{ errorMessage }}</span>
        <button type="button" class="vg-btn vg-btn-sm vg-btn-ghost vg-btn-ghost-danger" @click="errorMessage = ''">Descartar</button>
      </div>
      <div v-if="successMessage" class="vg-guides-tab__alert vg-guides-tab__alert--success" role="status">
        <span>✅ {{ successMessage }}</span>
        <button type="button" class="vg-btn vg-btn-sm vg-btn-ghost vg-btn-ghost-success" @click="successMessage = ''">Descartar</button>
      </div>

      <div class="vg-guides-tab__kpis">
        <article class="vg-guides-tab__kpi" data-testid="guides-kpi-active">
          <span class="vg-guides-tab__kpi-label">Total de Guías Activas</span>
          <strong class="vg-guides-tab__kpi-value">{{ totalActiveGuides }}</strong>
          <span class="vg-guides-tab__kpi-hint">Diagnósticos vigentes en el catálogo maestro.</span>
        </article>

        <article class="vg-guides-tab__kpi" data-testid="guides-kpi-covered">
          <span class="vg-guides-tab__kpi-label">Modelos del Parque Cubiertos</span>
          <strong class="vg-guides-tab__kpi-value">{{ coveredModels }} / {{ totalFleetModels }}</strong>
          <span class="vg-guides-tab__kpi-hint">Modelos activos con al menos una guía vigente.</span>
        </article>

        <article class="vg-guides-tab__kpi" data-testid="guides-kpi-orphans">
          <span class="vg-guides-tab__kpi-label">Modelos Activos sin Guía</span>
          <strong class="vg-guides-tab__kpi-value">{{ orphanCount }}</strong>
          <span class="vg-guides-tab__kpi-hint">Cobertura pendiente de Coordinación (QA 1).</span>
          <div v-if="orphanModels.length > 0" class="vg-action-group">
            <button
              v-for="orphan in orphanModels"
              :key="orphan.machine_model_key || orphan.machine_model"
              type="button"
              class="vg-btn vg-btn-sm vg-btn-primary-outline"
              :data-testid="'guide-create-for-' + (orphan.machine_model_key || orphan.machine_model)"
              @click="openCreate(orphan.machine_model)"
            >
              + Crear guía para {{ orphan.machine_model }}
            </button>
          </div>
        </article>
      </div>

      <section class="vg-guides-tab__toolbar" aria-label="Filtros del catálogo de guías">
        <div class="vg-guides-tab__toolbar-head">
          <div>
            <h2 class="vg-guides-tab__title">📖 Guías de Diagnóstico</h2>
            <p class="vg-guides-tab__subtitle">Diagnósticos por modelo, conmutador de categoría y cobertura del parque.</p>
          </div>
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            data-testid="guide-open-create"
            @click="openCreate()"
          >
            + Nueva Guía Rápida
          </button>
        </div>

        <div class="vg-guides-tab__filters">
          <label class="vg-guides-tab__field">
            <span>Modelo</span>
            <select v-model="modelFilter" class="vg-select" data-testid="guide-model-filter">
              <option value="ALL">Todos los modelos</option>
              <option v-for="model in modelOptions" :key="model" :value="model">{{ model }}</option>
            </select>
          </label>

          <label class="vg-guides-tab__field">
            <span>Categoría</span>
            <select v-model="categoryFilter" class="vg-select" data-testid="guide-category-filter">
              <option value="ALL">Todas las categorías</option>
              <option v-for="category in categoryOptions" :key="category.value" :value="category.value">
                {{ category.label }}
              </option>
            </select>
          </label>

          <label class="vg-guides-tab__field vg-guides-tab__field--search">
            <span>Buscar</span>
            <input
              v-model="searchQuery"
              type="search"
              class="vg-input"
              placeholder="Modelo, título o código de error…"
              data-testid="guide-search"
            >
          </label>

          <div class="vg-action-group" role="group" aria-label="Estado de la guía">
            <button
              type="button"
              class="vg-btn vg-btn-sm"
              :class="statusFilter === 'ALL' ? 'vg-btn-primary' : 'vg-btn-secondary'"
              data-testid="guide-status-all"
              @click="statusFilter = 'ALL'"
            >Todas</button>
            <button
              type="button"
              class="vg-btn vg-btn-sm"
              :class="statusFilter === 'ACTIVE' ? 'vg-btn-primary' : 'vg-btn-secondary'"
              data-testid="guide-status-active"
              @click="statusFilter = 'ACTIVE'"
            >Activas</button>
            <button
              type="button"
              class="vg-btn vg-btn-sm"
              :class="statusFilter === 'INACTIVE' ? 'vg-btn-primary' : 'vg-btn-secondary'"
              data-testid="guide-status-inactive"
              @click="statusFilter = 'INACTIVE'"
            >Inactivas</button>
          </div>
        </div>
      </section>

      <p v-if="isLoading" class="vg-guides-tab__empty" role="status" data-testid="guides-loading">
        Cargando el catálogo de guías de diagnóstico…
      </p>

      <div v-else-if="filteredGuides.length === 0" class="vg-guides-tab__empty" data-testid="guides-empty">
        No hay guías que coincidan con los filtros seleccionados.
      </div>

      <div v-else class="vg-guides-tab__table-wrap">
        <table class="vg-guides-tab__table">
          <thead>
            <tr>
              <th scope="col">Modelo</th>
              <th scope="col">Categoría</th>
              <th scope="col">Guía</th>
              <th scope="col">Repuestos</th>
              <th scope="col">Estado</th>
              <th scope="col">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="guide in filteredGuides" :key="guide.id" :data-testid="'guide-row-' + guide.id">
              <td>
                <strong>{{ guide.machine_model }}</strong>
                <span class="vg-guides-tab__muted">Actualizada: {{ formatDate(guide.updated_at) }}</span>
              </td>
              <td><span class="vg-chip">{{ categoryLabel(guide.breakdown_type) }}</span></td>
              <td>
                <span>{{ guide.title }}</span>
                <span v-if="guide.breakdown_type === 'TEMPERATURE_COLD'" class="vg-chip vg-chip-danger">❄️ Frío</span>
              </td>
              <td>{{ (guide.recommended_spare_part_ids || []).length }}</td>
              <td>
                <span class="vg-chip" :class="guide.is_active ? 'vg-chip-success' : 'vg-chip-danger'">
                  {{ guide.is_active ? 'Activa' : 'Inactiva' }}
                </span>
              </td>
              <td>
                <div class="vg-row-actions">
                  <button
                    type="button"
                    class="vg-btn vg-btn-sm vg-btn-primary-outline"
                    :data-testid="'guide-edit-' + guide.id"
                    @click="openEdit(guide)"
                  >Editar</button>
                  <button
                    type="button"
                    class="vg-btn vg-btn-sm"
                    :class="guide.is_active ? 'vg-btn-danger-soft' : 'vg-btn-success-soft'"
                    :data-testid="'guide-toggle-' + guide.id"
                    :disabled="isSaving"
                    @click="toggleStatus(guide)"
                  >{{ guide.is_active ? 'Desactivar' : 'Reactivar' }}</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <ModalDialog
        v-model="showModal"
        :title="isEditing ? 'Editar Guía de Diagnóstico' : 'Nueva Guía Rápida'"
        :subtitle="isEditing ? 'Modelo y categoría inmutables (C1).' : 'Diagnóstico auditado del catálogo de modelos.'"
        size="lg"
        @close="closeModal"
      >
        <form class="vg-guides-tab__form" @submit.prevent="submitForm">
          <div v-if="formError" class="vg-guides-tab__alert vg-guides-tab__alert--error" role="alert">
            {{ formError }}
          </div>

          <div class="vg-guides-tab__form-grid">
            <label class="vg-guides-tab__field">
              <span>Modelo del parque</span>
              <select
                v-model="form.machine_model"
                class="vg-select"
                :disabled="isEditing"
                data-testid="guide-form-model"
                @change="form.custom_model = ''"
              >
                <option value="">Selecciona un modelo…</option>
                <option v-for="model in modelOptions" :key="model" :value="model">{{ model }}</option>
              </select>
            </label>

            <label class="vg-guides-tab__field">
              <span>O escribe un modelo nuevo</span>
              <input
                v-model="form.custom_model"
                type="text"
                class="vg-input"
                :disabled="isEditing"
                placeholder="Ej.: Fas Perla"
                data-testid="guide-form-custom-model"
                @input="form.machine_model = ''"
              >
            </label>

            <label class="vg-guides-tab__field">
              <span>Categoría</span>
              <select
                v-model="form.breakdown_type"
                class="vg-select"
                :disabled="isEditing"
                data-testid="guide-form-category"
                @change="form.suggested_root_cause_id = ''"
              >
                <option v-for="category in categoryOptions" :key="category.value" :value="category.value">
                  {{ category.label }}
                </option>
              </select>
            </label>

            <label class="vg-guides-tab__field vg-guides-tab__field--wide">
              <span>Título</span>
              <input
                v-model="form.title"
                type="text"
                class="vg-input"
                maxlength="150"
                data-testid="guide-form-title"
              >
              <small class="vg-guides-tab__counter">{{ titleLength }} / 150 caracteres (mínimo 5)</small>
            </label>

            <label class="vg-guides-tab__field vg-guides-tab__field--wide">
              <span>Causa raíz sugerida</span>
              <select v-model="form.suggested_root_cause_id" class="vg-select" data-testid="guide-form-root-cause">
                <option value="">Sin causa sugerida</option>
                <option v-for="cause in rootCauseOptions" :key="cause.id" :value="cause.id">
                  [{{ cause.code }}] {{ cause.name }} — {{ categoryLabel(cause.category) }}
                </option>
              </select>
            </label>
          </div>

          <section class="vg-guides-tab__editor" aria-labelledby="guide-codes-heading">
            <div class="vg-guides-tab__editor-head">
              <h3 id="guide-codes-heading">Códigos de display</h3>
              <button
                type="button"
                class="vg-btn vg-btn-sm vg-btn-secondary"
                :disabled="form.error_codes.length >= maxErrorCodes"
                data-testid="guide-add-error-code"
                @click="addErrorCode"
              >+ Añadir código</button>
            </div>
            <p v-if="form.error_codes.length === 0" class="vg-guides-tab__muted">Sin códigos: la guía puede diagnosticarse por pasos.</p>
            <div v-for="(entry, index) in form.error_codes" :key="'code-' + index" class="vg-guides-tab__editor-row">
              <input v-model="entry.code" type="text" class="vg-input" maxlength="20" placeholder="Código (ej. E04)">
              <input v-model="entry.meaning" type="text" class="vg-input" maxlength="200" placeholder="Significado (5..200)">
              <button
                type="button"
                class="vg-btn vg-btn-sm vg-btn-danger-soft"
                @click="removeErrorCode(index)"
              >Quitar</button>
            </div>
          </section>

          <section class="vg-guides-tab__editor" aria-labelledby="guide-steps-heading">
            <div class="vg-guides-tab__editor-head">
              <h3 id="guide-steps-heading">Pasos de diagnóstico</h3>
              <button
                type="button"
                class="vg-btn vg-btn-sm vg-btn-secondary"
                :disabled="form.steps.length >= maxSteps"
                data-testid="guide-add-step"
                @click="addStep"
              >+ Añadir paso</button>
            </div>
            <div v-for="(step, index) in form.steps" :key="'step-' + index" class="vg-guides-tab__step">
              <div class="vg-guides-tab__step-head">
                <strong>Paso {{ index + 1 }}</strong>
                <button
                  type="button"
                  class="vg-btn vg-btn-sm vg-btn-danger-soft"
                  :disabled="form.steps.length <= minSteps"
                  @click="removeStep(index)"
                >Quitar</button>
              </div>
              <input v-model="step.title" type="text" class="vg-input" maxlength="120" placeholder="Título (3..120)">
              <textarea v-model="step.instruction" class="vg-textarea" rows="3" maxlength="500" placeholder="Instrucción verificable (20..500 y al menos 3 palabras reales)"></textarea>
              <small class="vg-guides-tab__counter">
                {{ step.instruction.length }} / 500 caracteres · {{ stepErrorLabel(step) || 'Longitud y palabras válidas' }}
              </small>
            </div>
          </section>

          <section class="vg-guides-tab__editor" aria-labelledby="guide-parts-heading">
            <div class="vg-guides-tab__editor-head">
              <h3 id="guide-parts-heading">Repuestos recomendados (máximo {{ maxSpareParts }})</h3>
              <span class="vg-chip">{{ form.recommended_spare_part_ids.length }} seleccionados</span>
            </div>
            <div class="vg-guides-tab__checklist">
              <label v-for="part in activeSpareParts" :key="part.id" class="vg-guides-tab__check">
                <input
                  type="checkbox"
                  :checked="isPartSelected(part.id)"
                  :disabled="!isPartSelected(part.id) && form.recommended_spare_part_ids.length >= maxSpareParts"
                  @change="togglePart(part.id)"
                >
                <span><strong>{{ part.part_code }}</strong> · {{ part.name }}</span>
              </label>
            </div>
          </section>
        </form>

        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" :disabled="isSaving" @click="closeModal">Cancelar</button>
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            :disabled="isSaving || !isFormValid"
            data-testid="guide-form-save"
            @click="submitForm"
          >{{ isSaving ? 'Guardando…' : 'Guardar guía' }}</button>
        </template>
      </ModalDialog>
    </div>
  `
};

export default CoordinatorTroubleshootingGuidesTab;
