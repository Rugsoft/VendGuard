/**
 * VendGuard - CoordinatorRootCauseCatalogModal Component
 * (CoordinatorRootCauseCatalogModal.js)
 *
 * Administración del catálogo maestro de causas raíz y soluciones
 * estandarizadas (Módulo 25, RF-01, RF-05, D1, C2, C4, QA 1 y QA 5).
 *
 * El componente no decide las invariantes: el servidor transaccional las
 * impone. Aquí se da la superficie de trabajo y se traducen sus veredictos a
 * avisos claros:
 *   - El alta es atómica: una causa raíz no puede nacer sin su solución
 *     predeterminada inicial.
 *   - `code`, `category` y `root_cause_id` son inmutables y se muestran como
 *     datos, nunca como campos editables.
 *   - No se desactiva la última solución activa ni la predeterminada vigente
 *     sin relevarla antes.
 *   - Reactivar una causa huérfana exige elegir la solución que pasará a ser
 *     predeterminada y activa.
 *
 * Dogma Vanilla: Vue 3 Options API por ES Modules. Sin `npm`, sin bundler y
 * cero literales de color. Dualismo Lingüístico: código en inglés, interfaz y
 * mensajes en castellano.
 */

import { api } from '../api.js';
import { INCIDENT_CATEGORIES } from '../utils/IncidentStatusPermissions.js';
import { ModalDialog } from './ModalDialog.js';

const CODE_PATTERN = /^[A-Z0-9-]{3,32}$/;
const ROOT_CAUSE_NAME_MAX = 120;
const SOLUTION_NAME_MAX = 140;
const ROOT_CAUSE_DESCRIPTION_MAX = 500;

const ERROR_MESSAGES = Object.freeze({
  INITIAL_DEFAULT_SOLUTION_REQUIRED:
    'Toda causa raíz necesita su solución predeterminada inicial. Complete los dos campos del bloque de creación.',
  DUPLICATE_TAXONOMY_CODE:
    'Ya existe una causa raíz o una solución estandarizada con ese código. Use un código distinto.',
  IMMUTABLE_TAXONOMY_FIELD:
    'El código y la categoría de una causa raíz no se pueden modificar tras su creación.',
  DEFAULT_SOLUTION_CANNOT_BE_DEACTIVATED:
    'No se puede desactivar ni desmarcar la solución predeterminada vigente. Marque primero otra solución activa de esta causa como predeterminada.',
  LAST_ACTIVE_SOLUTION_CANNOT_BE_DEACTIVATED:
    'No se puede desactivar la única solución activa de una causa raíz activa. Añada o reactive otra solución antes.',
  CANNOT_REACTIVATE_ORPHAN_ROOT_CAUSE:
    'La causa raíz no tiene una solución predeterminada activa. Elija la solución que debe activarse como predeterminada antes de reactivarla.',
  ROOT_CAUSE_NOT_FOUND:
    'La causa raíz ya no existe. Recargue el catálogo antes de continuar.',
  STANDARD_SOLUTION_NOT_FOUND:
    'La solución estandarizada ya no existe o no pertenece a esta causa raíz. Recargue el catálogo.',
  INVALID_TAXONOMY_CODE:
    'El código no tiene el formato admitido: entre 3 y 32 caracteres, letras mayúsculas, números, guion o guion bajo.',
  INVALID_TAXONOMY_NAME_LENGTH:
    'El nombre no cumple la longitud permitida: entre 5 y 120 caracteres para causas raíz y entre 5 y 140 para soluciones.',
  INVALID_ROOT_CAUSE_CATEGORY:
    'La categoría técnica seleccionada no es válida para una causa raíz.',
  INVALID_ROOT_CAUSE_ID:
    'El identificador de la causa raíz no es válido. Recargue el catálogo.',
  INVALID_SOLUTION_ID:
    'El identificador de la solución estandarizada no es válido. Recargue el catálogo.',
  FORBIDDEN:
    'Acceso denegado: la administración del catálogo exige rol de Coordinación.'
});

function emptyCreateForm() {
  return {
    code: '',
    category: '',
    name: '',
    description: '',
    initialCode: '',
    initialName: ''
  };
}

function normalizeCode(value) {
  return String(value || '').trim().toUpperCase();
}

function cleanText(value) {
  return String(value || '').trim();
}

export const CoordinatorRootCauseCatalogModal = {
  name: 'CoordinatorRootCauseCatalogModal',
  components: {
    ModalDialog
  },
  props: {
    modelValue: {
      type: Boolean,
      default: false
    }
  },
  emits: ['update:modelValue', 'close', 'updated'],
  data() {
    return {
      items: [],
      isLoading: false,
      isSaving: false,
      errorMessage: '',
      successMessage: '',
      showCreateForm: false,
      createForm: emptyCreateForm(),
      successTimer: null
    };
  },
  computed: {
    categoryOptions() {
      return INCIDENT_CATEGORIES;
    },
    totalCauses() {
      return this.items.length;
    },
    activeCauses() {
      return this.items.filter((cause) => cause.is_active).length;
    },
    isBusy() {
      return this.isLoading || this.isSaving;
    }
  },
  watch: {
    modelValue: {
      immediate: true,
      handler(isOpen) {
        if (isOpen) {
          this.loadCatalog();
        }
      }
    }
  },
  beforeUnmount() {
    if (this.successTimer !== null) {
      clearTimeout(this.successTimer);
      this.successTimer = null;
    }
  },
  methods: {
    handleVisibility(value) {
      this.$emit('update:modelValue', value);
      if (!value) {
        this.$emit('close');
      }
    },

    /**
     * Carga el catálogo completo (activas e inactivas) con sus contadores de
     * uso. Cada fila recibe un borrador local para no mutar la respuesta.
     */
    async loadCatalog(options = {}) {
      const preserveMessages = options?.preserveMessages === true;
      this.isLoading = true;
      if (!preserveMessages) {
        this.errorMessage = '';
        this.successMessage = '';
      }

      try {
        const payload = await api.getCoordinatorRootCauses({});
        const rawItems = Array.isArray(payload)
          ? payload
          : (Array.isArray(payload?.items) ? payload.items : []);

        this.items = rawItems.map((cause) => this.prepareCause(cause));
      } catch (error) {
        this.items = [];
        this.showError(error, 'No se pudo cargar el catálogo maestro de causas raíz.');
      } finally {
        this.isLoading = false;
      }
    },

    prepareCause(cause) {
      const solutions = Array.isArray(cause?.solutions) ? cause.solutions : [];

      return {
        ...cause,
        solutions: solutions.map((solution) => ({
          ...solution,
          _draftName: solution.name || ''
        })),
        _draft: {
          name: cause?.name || '',
          description: cause?.description || '',
          is_active: cause?.is_active === true,
          default_solution_id: cause?.default_solution_id ?? ''
        },
        _newSolution: {
          code: '',
          name: '',
          is_default: false
        },
        _showNewSolution: false,
        _saving: false
      };
    },

    async refreshCatalog() {
      await this.loadCatalog({ preserveMessages: true });
      this.$emit('updated');
    },

    validateName(value, label, maxLength = ROOT_CAUSE_NAME_MAX) {
      const name = cleanText(value);
      if (name.length < 5 || name.length > maxLength) {
        this.errorMessage = `${label} debe tener entre 5 y ${maxLength} caracteres reales.`;
        return false;
      }

      return true;
    },

    validateCode(value, label) {
      const code = normalizeCode(value);
      if (!CODE_PATTERN.test(code)) {
        this.errorMessage = `${label} debe usar entre 3 y 32 caracteres: letras mayúsculas, números o guion.`;
        return false;
      }

      return true;
    },

    validateCreateForm() {
      if (!this.createForm.category) {
        this.errorMessage = 'Seleccione la categoría técnica de la causa raíz.';
        return false;
      }

      return this.validateCode(this.createForm.code, 'El código de la causa raíz')
        && this.validateName(
          this.createForm.name,
          'El nombre de la causa raíz',
          ROOT_CAUSE_NAME_MAX
        )
        && this.validateCode(this.createForm.initialCode, 'El código de la solución inicial')
        && this.validateName(
          this.createForm.initialName,
          'El nombre de la solución inicial',
          SOLUTION_NAME_MAX
        );
    },

    /**
     * Alta atómica: la causa raíz y su solución predeterminada inicial viajan
     * en la misma petición y se persisten en una única transacción (`QA 1`).
     */
    async createRootCause() {
      if (this.isBusy || !this.validateCreateForm()) {
        return;
      }

      this.isSaving = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        await api.createRootCause({
          code: normalizeCode(this.createForm.code),
          category: this.createForm.category,
          name: cleanText(this.createForm.name),
          description: cleanText(this.createForm.description) || null,
          initial_solution: {
            code: normalizeCode(this.createForm.initialCode),
            name: cleanText(this.createForm.initialName)
          }
        });

        this.createForm = emptyCreateForm();
        this.showCreateForm = false;
        this.flashSuccess(
          'Causa raíz creada junto con su solución predeterminada inicial.'
        );
        await this.refreshCatalog();
      } catch (error) {
        this.showError(error, 'No se pudo dar de alta la causa raíz.');
      } finally {
        this.isSaving = false;
      }
    },

    /**
     * Guarda nombre, descripción y vigencia de la causa. En una reactivación
     * huérfana, `default_solution_id` designa y activa la predeterminada.
     */
    async saveRootCause(cause) {
      if (this.isBusy || !cause) {
        return;
      }

      if (!this.validateName(
        cause._draft.name,
        'El nombre de la causa raíz',
        ROOT_CAUSE_NAME_MAX
      )) {
        return;
      }

      if (cause._draft.is_active
        && !cause.default_solution_id
        && !cause._draft.default_solution_id
      ) {
        this.errorMessage = ERROR_MESSAGES.CANNOT_REACTIVATE_ORPHAN_ROOT_CAUSE;
        return;
      }

      cause._saving = true;
      this.isSaving = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        const payload = {
          name: cleanText(cause._draft.name),
          description: cleanText(cause._draft.description) || null,
          is_active: cause._draft.is_active === true
        };

        // Sólo se designa predeterminada cuando la causa carece de una activa:
        // reenviar la que ya lo era la desmarcaría por la secuencia del servicio.
        if (payload.is_active && !cause.default_solution_id && cause._draft.default_solution_id) {
          payload.default_solution_id = Number(cause._draft.default_solution_id);
        }

        await api.updateRootCause(cause.id, payload);
        this.flashSuccess('Causa raíz actualizada correctamente.');
        await this.refreshCatalog();
      } catch (error) {
        this.showError(error, 'No se pudo actualizar la causa raíz.');
      } finally {
        cause._saving = false;
        this.isSaving = false;
      }
    },

    /** Guarda únicamente el nombre mutable de una solución (`code` es inmutable). */
    async saveSolutionName(cause, solution) {
      if (this.isBusy || !cause || !solution) {
        return;
      }

      if (!this.validateName(
        solution._draftName,
        'El nombre de la solución estandarizada',
        SOLUTION_NAME_MAX
      )) {
        return;
      }

      cause._saving = true;
      this.isSaving = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        await api.updateStandardSolution(cause.id, solution.id, {
          name: cleanText(solution._draftName)
        });
        this.flashSuccess('Solución estandarizada actualizada correctamente.');
        await this.refreshCatalog();
      } catch (error) {
        this.showError(error, 'No se pudo actualizar la solución estandarizada.');
      } finally {
        cause._saving = false;
        this.isSaving = false;
      }
    },

    /**
     * Conmutación de la predeterminada en una sola acción. Si la solución está
     * inactiva, se reactiva y designa en la misma petición (`D1`, `QA 1`).
     */
    async setDefaultSolution(cause, solution) {
      if (this.isBusy || !cause || !solution
        || (solution.is_default && solution.is_active)
      ) {
        return;
      }

      cause._saving = true;
      this.isSaving = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        await api.updateStandardSolution(cause.id, solution.id, {
          name: cleanText(solution._draftName || solution.name),
          is_active: true,
          is_default: true
        });
        this.flashSuccess('Solución predeterminada conmutada correctamente.');
        await this.refreshCatalog();
      } catch (error) {
        this.showError(error, 'No se pudo conmutar la solución predeterminada.');
      } finally {
        cause._saving = false;
        this.isSaving = false;
      }
    },

    /**
     * Baja lógica con las guardas `C2` declaradas antes de llamar al servidor.
     * El servidor sigue siendo la autoridad y devuelve `409` si el estado
     * cambió mientras el modal estaba abierto.
     */
    async toggleSolutionActive(cause, solution) {
      if (this.isBusy || !cause || !solution) {
        return;
      }

      if (solution.is_active) {
        if (solution.is_default) {
          this.errorMessage = ERROR_MESSAGES.DEFAULT_SOLUTION_CANNOT_BE_DEACTIVATED;
          return;
        }

        const activeSolutions = cause.solutions.filter((entry) => entry.is_active);
        if (cause.is_active && activeSolutions.length <= 1) {
          this.errorMessage = ERROR_MESSAGES.LAST_ACTIVE_SOLUTION_CANNOT_BE_DEACTIVATED;
          return;
        }
      }

      cause._saving = true;
      this.isSaving = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        await api.updateStandardSolution(cause.id, solution.id, {
          name: cleanText(solution._draftName || solution.name),
          is_active: solution.is_active !== true,
          is_default: false
        });
        this.flashSuccess(
          solution.is_active
            ? 'Solución estandarizada desactivada. Deja de ofrecerse en nuevas resoluciones.'
            : 'Solución estandarizada reactivada como no predeterminada.'
        );
        await this.refreshCatalog();
      } catch (error) {
        this.showError(error, 'No se pudo cambiar la vigencia de la solución estandarizada.');
      } finally {
        cause._saving = false;
        this.isSaving = false;
      }
    },

    toggleNewSolutionForm(cause) {
      if (!cause) {
        return;
      }

      cause._showNewSolution = !cause._showNewSolution;
      if (cause._showNewSolution) {
        cause._newSolution = { code: '', name: '', is_default: false };
      }
    },

    async addSolution(cause) {
      if (this.isBusy || !cause) {
        return;
      }

      const form = cause._newSolution;

      if (!this.validateCode(form.code, 'El código de la solución')) {
        return;
      }

      if (!this.validateName(form.name, 'El nombre de la solución', SOLUTION_NAME_MAX)) {
        return;
      }

      cause._saving = true;
      this.isSaving = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        await api.createStandardSolution(cause.id, {
          code: normalizeCode(form.code),
          name: cleanText(form.name),
          is_default: form.is_default === true
        });

        cause._newSolution = { code: '', name: '', is_default: false };
        cause._showNewSolution = false;
        this.flashSuccess('Solución estandarizada añadida al catálogo de la causa.');
        await this.refreshCatalog();
      } catch (error) {
        this.showError(error, 'No se pudo añadir la solución estandarizada.');
      } finally {
        cause._saving = false;
        this.isSaving = false;
      }
    },

    /** Traduce el código canónico del servidor a un aviso operativo claro. */
    showError(error, fallbackMessage) {
      const code = String(error?.code || '');
      const mapped = ERROR_MESSAGES[code];

      if (mapped) {
        this.errorMessage = mapped;
        return;
      }

      const message = String(error?.message || '').trim()
        .replace(/^[A-Z][A-Z0-9_]{2,63}\s*:\s*/, '');
      this.errorMessage = message !== '' ? message : fallbackMessage;
    },

    flashSuccess(message) {
      this.successMessage = message;

      if (this.successTimer !== null) {
        clearTimeout(this.successTimer);
      }

      this.successTimer = setTimeout(() => {
        this.successMessage = '';
        this.successTimer = null;
      }, 6000);
    },

    formatInteger(value) {
      const numeric = Number(value);
      if (!Number.isFinite(numeric)) {
        return '0';
      }

      return new Intl.NumberFormat('es-ES', { maximumFractionDigits: 0 }).format(numeric);
    }
  },
  template: `
    <ModalDialog
      :model-value="modelValue"
      title="Catálogo Maestro de Causas Raíz y Soluciones"
      subtitle="Alta atómica, edición de nombres, conmutación de la solución predeterminada y vigencia del catálogo. Coordinación exclusivamente."
      size="lg"
      @update:model-value="handleVisibility"
    >
      <div class="vg-taxonomy-catalog" data-testid="root-cause-catalog-modal">
        <div
          v-if="errorMessage"
          class="vg-taxonomy-alert vg-taxonomy-alert--error"
          role="alert"
          data-testid="catalog-error"
        >
          <span>⚠️ {{ errorMessage }}</span>
          <button
            type="button"
            class="vg-btn vg-btn-icon vg-btn-ghost"
            aria-label="Cerrar el aviso de error"
            @click="errorMessage = ''"
          >×</button>
        </div>

        <div
          v-if="successMessage"
          class="vg-taxonomy-alert vg-taxonomy-alert--success"
          role="status"
          data-testid="catalog-success"
        >
          <span>✅ {{ successMessage }}</span>
          <button
            type="button"
            class="vg-btn vg-btn-icon vg-btn-ghost"
            aria-label="Cerrar el aviso de confirmación"
            @click="successMessage = ''"
          >×</button>
        </div>

        <div class="vg-taxonomy-catalog__toolbar">
          <div class="vg-taxonomy-catalog__summary" data-testid="catalog-summary">
            <span class="vg-taxonomy-chip vg-taxonomy-chip--neutral">
              {{ formatInteger(totalCauses) }} causas
            </span>
            <span class="vg-taxonomy-chip vg-taxonomy-chip--success">
              {{ formatInteger(activeCauses) }} activas
            </span>
          </div>

          <button
            type="button"
            class="vg-btn vg-btn-primary"
            data-testid="catalog-create-root-cause-toggle"
            :disabled="isBusy"
            @click="showCreateForm = !showCreateForm"
          >
            {{ showCreateForm ? '✖️ Cancelar alta' : '➕ Nueva causa raíz' }}
          </button>
        </div>

        <form
          v-if="showCreateForm"
          class="vg-taxonomy-create"
          data-testid="catalog-create-root-cause-form"
          @submit.prevent="createRootCause"
        >
          <h3 class="vg-taxonomy-card__title">Alta atómica de causa raíz</h3>
          <p class="vg-taxonomy-card__subtitle">
            La causa raíz y su primera solución predeterminada se crean en una única
            transacción. El código y la categoría quedan congelados desde el nacimiento.
          </p>

          <div class="vg-taxonomy-create__grid">
            <label class="vg-taxonomy-field" for="catalog-cause-code">
              <span class="vg-taxonomy-field__label">Código de causa *</span>
              <input
                id="catalog-cause-code"
                class="vg-taxonomy-input vg-taxonomy-input--code"
                type="text"
                maxlength="32"
                v-model="createForm.code"
                placeholder="RC-PAY-09"
                data-testid="catalog-cause-code"
              />
            </label>

            <label class="vg-taxonomy-field" for="catalog-cause-category">
              <span class="vg-taxonomy-field__label">Categoría técnica *</span>
              <select
                id="catalog-cause-category"
                class="vg-taxonomy-input"
                v-model="createForm.category"
                data-testid="catalog-cause-category"
              >
                <option value="">Seleccione una categoría</option>
                <option v-for="category in categoryOptions" :key="category.value" :value="category.value">
                  {{ category.label }}
                </option>
              </select>
            </label>

            <label class="vg-taxonomy-field vg-taxonomy-field--wide" for="catalog-cause-name">
              <span class="vg-taxonomy-field__label">Nombre de la causa raíz *</span>
              <input
                id="catalog-cause-name"
                class="vg-taxonomy-input"
                type="text"
                :maxlength="120"
                v-model="createForm.name"
                placeholder="Atasco de billete en el apilador del billetero"
                data-testid="catalog-cause-name"
              />
            </label>

            <label class="vg-taxonomy-field vg-taxonomy-field--wide" for="catalog-cause-description">
              <span class="vg-taxonomy-field__label">Descripción técnica</span>
              <textarea
                id="catalog-cause-description"
                class="vg-taxonomy-input vg-taxonomy-textarea"
                rows="2"
                maxlength="500"
                v-model="createForm.description"
                placeholder="Condición física y contexto de la avería (opcional)."
              ></textarea>
            </label>
          </div>

          <fieldset class="vg-taxonomy-create__initial">
            <legend>Solución predeterminada inicial *</legend>
            <div class="vg-taxonomy-create__grid">
              <label class="vg-taxonomy-field" for="catalog-initial-code">
                <span class="vg-taxonomy-field__label">Código de solución *</span>
                <input
                  id="catalog-initial-code"
                  class="vg-taxonomy-input vg-taxonomy-input--code"
                  type="text"
                  maxlength="32"
                  v-model="createForm.initialCode"
                  placeholder="SOL-PAY-09A"
                  data-testid="catalog-initial-code"
                />
              </label>

              <label class="vg-taxonomy-field" for="catalog-initial-name">
                <span class="vg-taxonomy-field__label">Nombre de la solución *</span>
                <input
                  id="catalog-initial-name"
                  class="vg-taxonomy-input"
                  type="text"
                  :maxlength="140"
                  v-model="createForm.initialName"
                  placeholder="Apertura del apilador, retirada del billete y limpieza de correas"
                  data-testid="catalog-initial-name"
                />
              </label>
            </div>
          </fieldset>

          <div class="vg-taxonomy-form-actions">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              :disabled="isSaving"
              @click="showCreateForm = false"
            >
              Cancelar
            </button>
            <button
              type="submit"
              class="vg-btn vg-btn-primary"
              data-testid="catalog-create-root-cause-submit"
              :disabled="isSaving"
            >
              <span v-if="isSaving">⏳ Guardando…</span>
              <span v-else>💾 Crear causa y solución inicial</span>
            </button>
          </div>
        </form>

        <div v-if="isLoading" class="vg-taxonomy-loading" role="status" data-testid="catalog-loading">
          Cargando el catálogo maestro…
        </div>

        <div v-else-if="items.length === 0" class="vg-taxonomy-empty" data-testid="catalog-empty">
          <span class="vg-taxonomy-empty__icon" aria-hidden="true">🗂️</span>
          <strong>El catálogo está vacío.</strong>
          <span>Cree la primera causa raíz con su solución predeterminada inicial.</span>
        </div>

        <div v-else class="vg-taxonomy-catalog__list">
          <article
            v-for="cause in items"
            :key="cause.id"
            class="vg-taxonomy-catalog__cause"
            :class="{ 'is-inactive': !cause.is_active }"
            :data-testid="'catalog-root-cause-row-' + cause.id"
          >
            <header class="vg-taxonomy-catalog__cause-header">
              <div class="vg-taxonomy-catalog__identity">
                <span class="vg-taxonomy-code">{{ cause.code }}</span>
                <strong class="vg-taxonomy-catalog__cause-name">{{ cause.name }}</strong>
                <span class="vg-taxonomy-chip vg-taxonomy-chip--neutral">
                  {{ cause.category_label }}
                </span>
                <span
                  class="vg-taxonomy-chip"
                  :class="cause.is_active ? 'vg-taxonomy-chip--success' : 'vg-taxonomy-chip--danger'"
                >
                  {{ cause.is_active ? 'Activa' : 'Inactiva' }}
                </span>
                <span class="vg-taxonomy-table__meta">
                  {{ formatInteger(cause.incidents_count) }} averías históricas
                </span>
              </div>

              <button
                type="button"
                class="vg-btn vg-btn-primary vg-btn-sm"
                data-testid="catalog-save-root-cause"
                :disabled="isBusy"
                @click="saveRootCause(cause)"
              >
                <span v-if="cause._saving">⏳ Guardando…</span>
                <span v-else>💾 Guardar causa</span>
              </button>
            </header>

            <div class="vg-taxonomy-catalog__fields">
              <label class="vg-taxonomy-field" :for="'catalog-cause-name-' + cause.id">
                <span class="vg-taxonomy-field__label">Nombre vigente</span>
                <input
                  :id="'catalog-cause-name-' + cause.id"
                  class="vg-taxonomy-input"
                  type="text"
                  :maxlength="120"
                  v-model="cause._draft.name"
                />
              </label>

              <label class="vg-taxonomy-field" :for="'catalog-cause-description-' + cause.id">
                <span class="vg-taxonomy-field__label">Descripción técnica</span>
                <textarea
                  :id="'catalog-cause-description-' + cause.id"
                  class="vg-taxonomy-input vg-taxonomy-textarea"
                  rows="2"
                  maxlength="500"
                  v-model="cause._draft.description"
                ></textarea>
              </label>

              <div class="vg-taxonomy-catalog__vigency">
                <label class="vg-taxonomy-toggle" :for="'catalog-cause-active-' + cause.id">
                  <input
                    :id="'catalog-cause-active-' + cause.id"
                    type="checkbox"
                    v-model="cause._draft.is_active"
                  />
                  <span>Activa para nuevas resoluciones</span>
                </label>

                <p class="vg-taxonomy-note">
                  El código <strong>{{ cause.code }}</strong> y la categoría
                  <strong>{{ cause.category_label }}</strong> son inmutables tras el alta.
                </p>
              </div>

              <div
                v-if="cause._draft.is_active && !cause.default_solution_id"
                class="vg-taxonomy-reactivate"
                data-testid="catalog-reactivate-default"
              >
                <label class="vg-taxonomy-field" :for="'catalog-reactivate-' + cause.id">
                  <span class="vg-taxonomy-field__label">
                    Solución a activar como predeterminada *
                  </span>
                  <select
                    :id="'catalog-reactivate-' + cause.id"
                    class="vg-taxonomy-input"
                    v-model="cause._draft.default_solution_id"
                  >
                    <option value="">Seleccione una solución de esta causa</option>
                    <option
                      v-for="solution in cause.solutions"
                      :key="solution.id"
                      :value="solution.id"
                    >
                      {{ solution.code }} · {{ solution.name }}
                      {{ solution.is_active ? '(activa)' : '(inactiva)' }}
                    </option>
                  </select>
                </label>
                <p class="vg-taxonomy-note vg-taxonomy-note--warning">
                  Reactivar una causa huérfana exige designar en la misma acción una solución
                  activa y predeterminada.
                </p>
              </div>
            </div>

            <section class="vg-taxonomy-catalog__solutions" aria-label="Soluciones estandarizadas">
              <div class="vg-taxonomy-catalog__solutions-header">
                <h4>
                  Soluciones estandarizadas
                  <span class="vg-taxonomy-table__meta">
                    ({{ formatInteger(cause.active_solutions_count) }} activas de
                    {{ formatInteger(cause.total_solutions_count ?? cause.solutions.length) }})
                  </span>
                </h4>

                <button
                  type="button"
                  class="vg-btn vg-btn-secondary vg-btn-sm"
                  data-testid="catalog-toggle-new-solution"
                  :disabled="isBusy"
                  @click="toggleNewSolutionForm(cause)"
                >
                  {{ cause._showNewSolution ? '✖️ Cancelar solución' : '➕ Añadir solución' }}
                </button>
              </div>

              <div
                v-for="solution in cause.solutions"
                :key="solution.id"
                class="vg-taxonomy-catalog__solution"
                :class="{ 'is-inactive': !solution.is_active }"
                :data-testid="'catalog-solution-row-' + solution.id"
              >
                <span class="vg-taxonomy-code">{{ solution.code }}</span>

                <input
                  class="vg-taxonomy-input"
                  type="text"
                  :maxlength="140"
                  v-model="solution._draftName"
                  :aria-label="'Nombre de la solución ' + solution.code"
                />

                <div class="vg-taxonomy-catalog__solution-badges">
                  <span
                    v-if="solution.is_default && solution.is_active"
                    class="vg-taxonomy-chip vg-taxonomy-chip--vital"
                  >
                    Predeterminada
                  </span>
                  <span
                    class="vg-taxonomy-chip"
                    :class="solution.is_active ? 'vg-taxonomy-chip--success' : 'vg-taxonomy-chip--danger'"
                  >
                    {{ solution.is_active ? 'Activa' : 'Inactiva' }}
                  </span>
                </div>

                <div class="vg-taxonomy-catalog__solution-actions">
                  <button
                    v-if="!solution.is_default || !solution.is_active"
                    type="button"
                    class="vg-btn vg-btn-secondary vg-btn-sm"
                    data-testid="catalog-set-default-solution"
                    :disabled="isBusy"
                    @click="setDefaultSolution(cause, solution)"
                  >
                    ⭐ Predeterminada
                  </button>

                  <button
                    type="button"
                    class="vg-btn vg-btn-secondary vg-btn-sm"
                    :class="{ 'vg-taxonomy-btn--danger': solution.is_active }"
                    data-testid="catalog-toggle-solution-active"
                    :disabled="isBusy"
                    @click="toggleSolutionActive(cause, solution)"
                  >
                    {{ solution.is_active ? '⏸️ Desactivar' : '▶️ Reactivar' }}
                  </button>

                  <button
                    type="button"
                    class="vg-btn vg-btn-secondary vg-btn-sm"
                    data-testid="catalog-save-solution-name"
                    :disabled="isBusy"
                    @click="saveSolutionName(cause, solution)"
                  >
                    💾 Guardar nombre
                  </button>
                </div>
              </div>

              <div v-if="cause.solutions.length === 0" class="vg-taxonomy-note vg-taxonomy-note--warning">
                Esta causa no tiene soluciones. Añada una y actívela como predeterminada.
              </div>

              <div
                v-if="cause._showNewSolution"
                class="vg-taxonomy-catalog__new-solution"
                data-testid="catalog-new-solution-form"
              >
                <label class="vg-taxonomy-field" :for="'catalog-new-code-' + cause.id">
                  <span class="vg-taxonomy-field__label">Código *</span>
                  <input
                    :id="'catalog-new-code-' + cause.id"
                    class="vg-taxonomy-input vg-taxonomy-input--code"
                    type="text"
                    maxlength="32"
                    v-model="cause._newSolution.code"
                    placeholder="SOL-PAY-01C"
                  />
                </label>

                <label class="vg-taxonomy-field" :for="'catalog-new-name-' + cause.id">
                  <span class="vg-taxonomy-field__label">Nombre *</span>
                  <input
                    :id="'catalog-new-name-' + cause.id"
                    class="vg-taxonomy-input"
                    type="text"
                    :maxlength="140"
                    v-model="cause._newSolution.name"
                    placeholder="Acción técnica estandarizada"
                  />
                </label>

                <label class="vg-taxonomy-toggle" :for="'catalog-new-default-' + cause.id">
                  <input
                    :id="'catalog-new-default-' + cause.id"
                    type="checkbox"
                    v-model="cause._newSolution.is_default"
                  />
                  <span>Marcar como predeterminada</span>
                </label>

                <button
                  type="button"
                  class="vg-btn vg-btn-primary vg-btn-sm"
                  data-testid="catalog-add-solution-submit"
                  :disabled="isBusy"
                  @click="addSolution(cause)"
                >
                  💾 Añadir solución
                </button>
              </div>
            </section>
          </article>
        </div>
      </div>

      <template #footer>
        <button
          type="button"
          class="vg-btn vg-btn-secondary"
          @click="handleVisibility(false)"
        >
          Cerrar
        </button>
      </template>
    </ModalDialog>
  `
};

export default CoordinatorRootCauseCatalogModal;
