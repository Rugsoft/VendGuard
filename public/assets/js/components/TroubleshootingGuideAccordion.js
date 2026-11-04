/**
 * VendGuard - TroubleshootingGuideAccordion (TroubleshootingGuideAccordion.js)
 *
 * Acordeón de guías rápidas de diagnóstico por modelo (Módulo 28, RF-01.1–RF-01.3,
 * RF-02.1–RF-02.3, RF-03.1, C4–C6, D1–D3, QA 3 y QA 4).
 *
 * El componente es presentacional: recibe la colección ya resuelta por la API o
 * por la copia local (`guides`) y no vuelve a pedirla. Así el técnico sin
 * cobertura y el coordinador con red comparten exactamente la misma superficie.
 *
 * QUÉ PINTA
 *
 *   1. **Banner sanitario prioritario** cuando la máquina es perecedera o alguna
 *      guía pertenece a `TEMPERATURE_COLD` (Art. II).
 *   2. **Conmutador de guías** «Ver todas las guías de este modelo» dentro de
 *      `vg-action-group`, con la escalera `vg-btn vg-btn-sm` y sin clases
 *      heredadas `.btn*` ni geometría inline en los botones (docs/design.md).
 *   3. **Buscador instantáneo de códigos de error** sobre `error_codes`, con
 *      input de radio 4px (`vg-input`).
 *   4. **Pasos 1..N** con casillas persistidas en el dispositivo por avería y
 *      guía (`C6`); el estado vive en `localStorage`, nunca en el servidor.
 *   5. **Repuestos recomendados** como chips de lectura con el semáforo de la
 *      furgoneta y sin ningún importe (`Art. V.4`).
 *   6. **Causa raíz sugerida** con el botón «Aplicar causa sugerida por la
 *      guía». El componente solo emite la elección: no escribe diagnóstico ni
 *      acción, que siguen siendo texto libre del técnico (`D2`, Art. V.1).
 *
 * Dogma Vanilla: Vue 3 Options API vía ESM, cero dependencias externas,
 * interpolación segura (sin `v-html`) y textos en castellano.
 */

const STEP_STORAGE_PREFIX = 'vendguard:troubleshooting-guide-steps:';

/** Orden canónico de categorías cuando el servidor no lo impone (`QA 3`). */
const CATEGORY_RANK = Object.freeze({
  TEMPERATURE_COLD: 0,
  PAYMENT_SYSTEM: 1,
  PRODUCT_JAM: 2,
  ELECTRICAL_OFF: 3,
  OTHER: 4
});

/** Modelo normalizado: trim, espacios colapsados y minúsculas (contrato §2). */
function normalizeModelKey(value) {
  return String(value ?? '').trim().replace(/\s+/g, ' ').toLowerCase();
}

/** Categoría normalizada a mayúsculas para comparar sin depender del origen. */
function normalizeCategory(value) {
  return String(value ?? '').trim().toUpperCase();
}

export const TroubleshootingGuideAccordion = {
  name: 'TroubleshootingGuideAccordion',
  props: {
    /** Avería activa: aísla las casillas guardadas en el dispositivo (`C6`). */
    incidentId: {
      type: [Number, String],
      default: null
    },
    /** Modelo de la máquina; solo se usa como cabecera legible. */
    machineModel: {
      type: String,
      default: ''
    },
    /** Categoría reportada en la avería, para subir la coincidencia exacta. */
    breakdownType: {
      type: String,
      default: ''
    },
    /** Tipo de máquina (`PERISHABLE_FOOD`) para el aviso sanitario. */
    machineType: {
      type: String,
      default: ''
    },
    /** Colección enriquecida de guías (API o copia local). */
    guides: {
      type: Array,
      default: () => []
    },
    /** Fuerza el banner sanitario aunque ninguna guía sea de frío. */
    sanitaryWarning: {
      type: Boolean,
      default: false
    },
    isLoading: {
      type: Boolean,
      default: false
    },
    errorMessage: {
      type: String,
      default: ''
    }
  },
  emits: ['apply-root-cause'],
  data() {
    return {
      selectedGuideId: null,
      showAllGuides: false,
      searchQuery: '',
      checkedSteps: {}
    };
  },
  computed: {
    /** Guías vigentes, con la coincidencia exacta primero y orden canónico. */
    orderedGuides() {
      const active = (Array.isArray(this.guides) ? this.guides : [])
        .filter((guide) => guide && guide.is_active !== false);
      const requested = normalizeCategory(this.breakdownType);

      return active
        .map((guide, index) => ({ guide, index }))
        .sort((left, right) => {
          const rank = (guide) => {
            const category = normalizeCategory(guide?.breakdown_type);
            if (requested !== '' && category === requested) return -1;
            return CATEGORY_RANK[category] ?? 99;
          };

          const diff = rank(left.guide) - rank(right.guide);
          return diff !== 0 ? diff : left.index - right.index;
        })
        .map((entry) => entry.guide);
    },

    hasMultipleGuides() {
      return this.orderedGuides.length > 1;
    },

    selectedGuide() {
      const selectedId = Number(this.selectedGuideId ?? 0);
      return this.orderedGuides.find((guide) => Number(guide?.id) === selectedId)
        ?? this.orderedGuides[0]
        ?? null;
    },

    /** Texto de búsqueda ya normalizado. */
    normalizedSearch() {
      return String(this.searchQuery ?? '').trim().toLowerCase();
    },

    /**
     * Guías visibles: la sugerida por defecto, el catálogo completo si se abre
     * el conmutador, o las que casan con el código de error buscado.
     */
    visibleGuides() {
      if (this.normalizedSearch !== '') {
        return this.orderedGuides.filter((guide) => this.matchesSearch(guide));
      }
      if (this.showAllGuides) {
        return this.orderedGuides;
      }

      return this.selectedGuide ? [this.selectedGuide] : [];
    },

    /**
     * El aviso sanitario se enciende por máquina perecedera, por categoría de
     * frío solicitada o porque alguna guía del modelo sea de cadena de frío.
     */
    showSanitaryBanner() {
      if (this.sanitaryWarning) {
        return true;
      }
      if (normalizeCategory(this.machineType) === 'PERISHABLE_FOOD') {
        return true;
      }
      if (normalizeCategory(this.breakdownType) === 'TEMPERATURE_COLD') {
        return true;
      }

      return this.orderedGuides.some(
        (guide) => guide?.sanitary_cold_warning === true
          || normalizeCategory(guide?.breakdown_type) === 'TEMPERATURE_COLD'
      );
    },

    stepStorageKey() {
      const incidentId = String(this.incidentId ?? '').trim();
      return incidentId === '' ? '' : `${STEP_STORAGE_PREFIX}${incidentId}`;
    }
  },
  watch: {
    guides: {
      immediate: true,
      handler() {
        this.ensureSelectedGuide();
        this.loadCheckedSteps();
      }
    },
    breakdownType() {
      this.selectedGuideId = null;
      this.ensureSelectedGuide();
    },
    incidentId() {
      this.loadCheckedSteps();
    }
  },
  methods: {
    /** Mantiene una guía seleccionada válida cuando cambia la colección. */
    ensureSelectedGuide() {
      const exists = this.orderedGuides.some(
        (guide) => Number(guide?.id) === Number(this.selectedGuideId ?? 0)
      );
      if (exists) {
        return;
      }

      const requested = normalizeCategory(this.breakdownType);
      const exact = this.orderedGuides.find(
        (guide) => normalizeCategory(guide?.breakdown_type) === requested
      );
      this.selectedGuideId = Number((exact ?? this.orderedGuides[0])?.id ?? 0) || null;
    },

    /** Carga del dispositivo las casillas de la avería abierta (`C6`). */
    loadCheckedSteps() {
      if (this.stepStorageKey === '') {
        this.checkedSteps = {};
        return;
      }

      try {
        const raw = globalThis.localStorage?.getItem(this.stepStorageKey);
        const parsed = raw ? JSON.parse(raw) : null;
        this.checkedSteps = parsed && typeof parsed === 'object' && !Array.isArray(parsed)
          ? parsed
          : {};
      } catch {
        this.checkedSteps = {};
      }
    },

    /** Clave estable de un paso dentro de su guía y su avería. */
    stepKey(guide, index) {
      return `${Number(guide?.id ?? 0)}:${index + 1}`;
    },

    isStepChecked(guide, index) {
      return this.checkedSteps[this.stepKey(guide, index)] === true;
    },

    /** Marca o desmarca un paso y persiste solo en este dispositivo. */
    toggleStep(guide, index) {
      const key = this.stepKey(guide, index);
      const next = { ...this.checkedSteps };

      if (next[key] === true) {
        delete next[key];
      } else {
        next[key] = true;
      }

      this.checkedSteps = next;

      if (this.stepStorageKey === '') {
        return;
      }

      try {
        globalThis.localStorage?.setItem(this.stepStorageKey, JSON.stringify(next));
      } catch {
        // Sin almacenamiento disponible el paso sigue marcado en memoria.
      }
    },

    /** Coincidencia instantánea contra código o significado del error. */
    matchesSearch(guide) {
      if (this.normalizedSearch === '') {
        return true;
      }

      return (Array.isArray(guide?.error_codes) ? guide.error_codes : []).some((entry) => {
        const code = String(entry?.code ?? '').toLowerCase();
        const meaning = String(entry?.meaning ?? '').toLowerCase();

        return code.includes(this.normalizedSearch) || meaning.includes(this.normalizedSearch);
      });
    },

    /** Abre el catálogo completo del modelo o vuelve a la guía sugerida. */
    toggleShowAllGuides() {
      this.showAllGuides = !this.showAllGuides;
      if (!this.showAllGuides) {
        this.searchQuery = '';
      }
    },

    /** Selecciona una guía concreta sin abrir el catálogo completo. */
    selectGuide(guide) {
      this.selectedGuideId = Number(guide?.id ?? 0) || null;
      this.showAllGuides = false;
      this.searchQuery = '';
    },

    /** Etiqueta del semáforo de furgoneta, sin importes (`Art. V.4`). */
    stockLabel(part) {
      if (!part || part.is_active === false) {
        return 'Inactiva en catálogo';
      }

      const quantity = Number(part.van_stock_quantity ?? 0);
      return quantity > 0
        ? `✅ En tu furgoneta: ${quantity} uds.`
        : '⚠️ Sin stock en tu furgoneta';
    },

    /** Tono del chip de stock: verde, ámbar o rojo. */
    stockClass(part) {
      if (!part || part.is_active === false) {
        return 'vg-chip-danger';
      }

      return Number(part.van_stock_quantity ?? 0) > 0 ? 'vg-chip-success' : 'vg-chip-warning';
    },

    /** Identidad del modelo para el encabezado cuando el padre no la envía. */
    modelLabel() {
      return String(this.machineModel || this.selectedGuide?.machine_model || '').trim()
        || 'Guías de diagnóstico';
    },

    /**
     * Aplica la causa sugerida: solo emite la elección. Ni el diagnóstico ni la
     * acción se rellenan aquí (Art. V.1, `D2`).
     */
    applySuggestedRootCause(guide) {
      const rootCause = guide?.suggested_root_cause ?? null;
      if (!rootCause) {
        return;
      }

      this.$emit('apply-root-cause', { rootCause, guide });
    }
  },
  template: `
    <section class="vg-guide-accordion" data-testid="troubleshooting-guide-accordion" :aria-label="'Guías de diagnóstico de ' + modelLabel()">
      <header class="vg-guide-accordion__header">
        <div>
          <span class="vg-guide-accordion__eyebrow">Guías rápidas de diagnóstico</span>
          <h3 class="vg-guide-accordion__title">📖 {{ modelLabel() }}</h3>
        </div>
        <span v-if="orderedGuides.length > 0" class="vg-chip" data-testid="guide-count">
          {{ orderedGuides.length }} {{ orderedGuides.length === 1 ? 'guía' : 'guías' }}
        </span>
      </header>

      <div
        v-if="showSanitaryBanner"
        class="vg-alert vg-alert-error"
        role="alert"
        data-testid="guide-sanitary-warning"
      >
        ❄️ <strong>Alerta sanitaria prioritaria:</strong> máquina de alimentos perecederos o avería de cadena de frío. Asegura el precinto y respeta el SLA crítico antes de cerrar la intervención.
      </div>

      <p v-if="isLoading" role="status" class="vg-guide-accordion__message" data-testid="guide-loading">
        Cargando guías de diagnóstico…
      </p>

      <div v-else-if="errorMessage" class="vg-alert vg-alert-warning" role="alert" data-testid="guide-error">
        {{ errorMessage }}
      </div>

      <template v-else-if="orderedGuides.length > 0">
        <div
          v-if="hasMultipleGuides"
          class="vg-action-group vg-guide-accordion__switch"
          role="group"
          aria-label="Conmutador de guías del modelo"
        >
          <button
            type="button"
            class="vg-btn vg-btn-sm"
            :class="showAllGuides ? 'vg-btn-primary' : 'vg-btn-primary-outline'"
            data-testid="guide-toggle-all"
            :aria-expanded="showAllGuides ? 'true' : 'false'"
            @click="toggleShowAllGuides"
          >
            {{ showAllGuides ? 'Ver solo la guía sugerida' : 'Ver todas las guías de este modelo (' + orderedGuides.length + ')' }}
          </button>
        </div>

        <label class="vg-guide-accordion__search" for="guide-error-search">
          <span>Buscar por código de error</span>
          <input
            id="guide-error-search"
            v-model="searchQuery"
            type="search"
            class="vg-input"
            data-testid="guide-error-search"
            placeholder="Ej.: E04, AL-TEMP, MDB-01…"
            autocomplete="off"
          >
        </label>

        <p
          v-if="normalizedSearch !== '' && visibleGuides.length === 0"
          class="vg-guide-accordion__message"
          role="status"
          data-testid="guide-search-empty"
        >
          Ninguna guía de este modelo declara ese código de error.
        </p>

        <article
          v-for="guide in visibleGuides"
          :key="guide.id"
          class="vg-guide-accordion__guide"
          data-testid="guide-card"
        >
          <header class="vg-guide-accordion__guide-header">
            <div>
              <span v-if="hasMultipleGuides && !showAllGuides" class="vg-guide-accordion__eyebrow">
                Guía sugerida para esta avería
              </span>
              <h4 class="vg-guide-accordion__guide-title">{{ guide.title }}</h4>
            </div>
            <button
              v-if="hasMultipleGuides && showAllGuides"
              type="button"
              class="vg-btn vg-btn-sm vg-btn-primary-outline"
              data-testid="guide-select"
              @click="selectGuide(guide)"
            >
              Ver esta guía
            </button>
          </header>

          <div class="vg-action-group vg-guide-accordion__badges">
            <span class="vg-chip">{{ guide.breakdown_type_label || guide.breakdown_type }}</span>
            <span
              v-if="guide.sanitary_cold_warning"
              class="vg-chip vg-chip-danger"
              data-testid="guide-cold-chip"
            >
              ❄️ Cadena de frío
            </span>
          </div>

          <div v-if="(guide.error_codes || []).length > 0" class="vg-guide-accordion__codes">
            <h5>Códigos de display</h5>
            <ul>
              <li v-for="entry in guide.error_codes" :key="entry.code">
                <span class="vg-chip">{{ entry.code }}</span>
                <span>{{ entry.meaning }}</span>
              </li>
            </ul>
          </div>

          <ol class="vg-guide-accordion__steps">
            <li v-for="(step, index) in (guide.steps || [])" :key="index">
              <label class="vg-guide-accordion__step-check">
                <input
                  type="checkbox"
                  :checked="isStepChecked(guide, index)"
                  :disabled="incidentId === null || incidentId === ''"
                  :data-testid="'guide-step-' + (index + 1)"
                  @change="toggleStep(guide, index)"
                >
                <strong>{{ step.step_order || (index + 1) }}. {{ step.title }}</strong>
              </label>
              <p>{{ step.instruction }}</p>
            </li>
          </ol>

          <div
            v-if="(guide.recommended_spare_parts || []).length > 0"
            class="vg-guide-accordion__parts"
            data-testid="guide-spare-parts"
          >
            <h5>Repuestos recomendados</h5>
            <div class="vg-action-group">
              <span
                v-for="part in guide.recommended_spare_parts"
                :key="part.spare_part_id || part.part_code"
                class="vg-chip"
                :class="stockClass(part)"
                :title="part.name"
              >
                {{ part.part_code }} · {{ stockLabel(part) }}
              </span>
            </div>
          </div>

          <div
            v-if="guide.suggested_root_cause"
            class="vg-guide-accordion__cause"
            data-testid="guide-root-cause"
          >
            <h5>Causa raíz sugerida</h5>
            <p class="vg-guide-accordion__cause-name">
              <strong v-if="guide.suggested_root_cause.code">[{{ guide.suggested_root_cause.code }}]</strong>
              {{ guide.suggested_root_cause.name }}
            </p>
            <p v-if="guide.suggested_root_cause.default_solution" class="vg-guide-accordion__solution">
              Solución: {{ guide.suggested_root_cause.default_solution.name }}
            </p>
            <button
              type="button"
              class="vg-btn vg-btn-primary-outline"
              data-testid="guide-apply-root-cause"
              @click="applySuggestedRootCause(guide)"
            >
              Aplicar causa sugerida por la guía
            </button>
          </div>
        </article>
      </template>

      <p v-else class="vg-guide-accordion__message" data-testid="guide-empty">
        No hay guías activas para este modelo.
      </p>
    </section>
  `
};

export default TroubleshootingGuideAccordion;
