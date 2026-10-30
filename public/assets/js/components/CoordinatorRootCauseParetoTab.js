/**
 * VendGuard - CoordinatorRootCauseParetoTab Component (CoordinatorRootCauseParetoTab.js)
 *
 * Pestaña analítica del Diagrama de Pareto 80/20 de causas raíz (Módulo 25,
 * RF-05, D5, C3, C6, QA 2 y QA 4).
 *
 * La pestaña no calcula la matemática: el motor Pareto del servidor ya cierra
 * los porcentajes en 100,00 %, marca la Zona Vital 80/20 y separa las averías
 * históricas sin clasificar de la base del 100 %. Aquí se presenta el informe,
 * se acotan sus filtros y se ofrece la exportación CSV en dos secciones.
 *
 * Reglas visuales:
 *   - Cero literales de color: Chart.js resuelve cada trazo leyendo los tokens
 *     CSS computados con `getComputedStyle` (`--color-primary`,
 *     `--color-hairline-soft`, `--color-slate`, `--color-error`).
 *   - Tipografía dual: DM Sans en cifras KPI y titulares; Inter en cuerpo,
 *     tablas, etiquetas y botones.
 *   - Controles con radio interactivo 4 px, tarjetas de 8 px y objetivos
 *     táctiles de 44 px (docs/design.md).
 *
 * Dogma Vanilla: Vue 3 Options API por ES Modules, sin bundler ni
 * `node_modules`. Chart.js viaja vendorizado y se expone como `window.Chart`.
 * Dualismo Lingüístico: código en inglés, interfaz y mensajes en castellano.
 */

import { api } from '../api.js';
import { INCIDENT_CATEGORIES } from '../utils/IncidentStatusPermissions.js';
import { CoordinatorRootCauseCatalogModal } from './CoordinatorRootCauseCatalogModal.js';

const SUMMARY_DEFAULTS = Object.freeze({
  classified_incidents_count: 0,
  unclassified_historical_count: 0,
  total_incidents_in_period: 0,
  vital_few_causes_count: 0,
  total_causes_with_incidents: 0,
  total_replaced_parts_units: 0,
  total_replaced_parts_cost_eur: 0
});

const EMPTY_REPORT = Object.freeze({
  filters: {},
  available_filters: {
    manufacturers: [],
    models_by_manufacturer: {},
    models: []
  },
  summary: { ...SUMMARY_DEFAULTS },
  items: []
});

function padTwo(value) {
  return String(value).padStart(2, '0');
}

function toIsoDate(date) {
  return `${date.getFullYear()}-${padTwo(date.getMonth() + 1)}-${padTwo(date.getDate())}`;
}

/** Ventana por defecto del contrato: 90 días sobre `resolved_at`. */
function defaultStartDate() {
  const start = new Date();
  start.setDate(start.getDate() - 89);
  return toIsoDate(start);
}

function todayIsoDate() {
  return toIsoDate(new Date());
}

export const CoordinatorRootCauseParetoTab = {
  name: 'CoordinatorRootCauseParetoTab',
  components: {
    CoordinatorRootCauseCatalogModal
  },
  data() {
    return {
      filters: {
        startDate: defaultStartDate(),
        endDate: todayIsoDate(),
        category: '',
        manufacturer: '',
        machineModel: '',
        locationId: '',
        technicianId: '',
        vitalFewOnly: false
      },
      report: {
        filters: {},
        available_filters: {
          manufacturers: [],
          models_by_manufacturer: {},
          models: []
        },
        summary: { ...SUMMARY_DEFAULTS },
        items: []
      },
      locations: [],
      technicians: [],
      isLoading: false,
      isExporting: false,
      errorMessage: '',
      successMessage: '',
      filterOptionsError: '',
      chartUnavailable: false,
      chartInstance: null,
      successTimer: null,
      showCatalog: false
    };
  },
  computed: {
    categoryOptions() {
      return INCIDENT_CATEGORIES;
    },
    summary() {
      return this.report?.summary || SUMMARY_DEFAULTS;
    },
    items() {
      return Array.isArray(this.report?.items) ? this.report.items : [];
    },
    availableFilters() {
      return this.report?.available_filters || EMPTY_REPORT.available_filters;
    },
    manufacturers() {
      return Array.isArray(this.availableFilters.manufacturers)
        ? this.availableFilters.manufacturers
        : [];
    },
    models() {
      const models = Array.isArray(this.availableFilters.models)
        ? this.availableFilters.models
        : [];
      if (!this.filters.manufacturer) {
        return models;
      }

      const byManufacturer = this.availableFilters.models_by_manufacturer || {};
      const scoped = byManufacturer[this.filters.manufacturer];

      return Array.isArray(scoped) ? scoped : [];
    },
    hasParetoData() {
      return Number(this.summary.classified_incidents_count) > 0 && this.items.length > 0;
    },
    isBusy() {
      return this.isLoading || this.isExporting;
    },
    vitalConcentrationLabel() {
      const vitalItems = this.items.filter((item) => item && item.is_vital_few === true);
      if (vitalItems.length === 0) {
        return '0,00 %';
      }

      const lastVital = vitalItems[vitalItems.length - 1];
      return this.formatPercent(lastVital.cumulative_percentage);
    },
    vitalConcentrationHint() {
      const classified = Number(this.summary.classified_incidents_count) || 0;
      const total = Number(this.summary.total_causes_with_incidents) || 0;
      if (classified === 0) {
        return 'Sin averías clasificadas en el periodo.';
      }

      return `${this.summary.vital_few_causes_count} de ${total} causas concentran el 80,00 % del volumen.`;
    }
  },
  mounted() {
    this.loadFilterOptions();
    this.loadReport();
  },
  beforeUnmount() {
    if (this.successTimer !== null) {
      clearTimeout(this.successTimer);
      this.successTimer = null;
    }
    this.destroyChart();
  },
  methods: {
    /**
     * Carga las sedes y los técnicos que alimentan los filtros auxiliares.
     *
     * El contrato Pareto publica fabricantes y modelos; sedes y técnicos se
     * piden por sus lecturas ya existentes y un fallo suyo no tumba el informe.
     */
    async loadFilterOptions() {
      this.filterOptionsError = '';

      const results = await Promise.allSettled([
        api.coordinator.getLocations(),
        api.coordinator.getTechnicianQuadrants()
      ]);

      const [locationsResult, techniciansResult] = results;

      if (locationsResult.status === 'fulfilled') {
        const payload = locationsResult.value;
        const locations = Array.isArray(payload)
          ? payload
          : (Array.isArray(payload?.locations) ? payload.locations : []);
        this.locations = locations
          .filter((location) => location && location.id !== undefined && location.id !== null)
          .map((location) => ({
            id: Number(location.id),
            name: String(location.name || `Sede #${location.id}`)
          }));
      } else {
        this.locations = [];
        this.filterOptionsError = 'No se pudieron cargar las sedes para el filtro.';
      }

      if (techniciansResult.status === 'fulfilled') {
        const payload = techniciansResult.value || {};
        const technicians = Array.isArray(payload.technicians) ? payload.technicians : [];
        this.technicians = technicians
          .filter((technician) => technician && technician.technician_id !== undefined)
          .map((technician) => ({
            id: Number(technician.technician_id),
            name: String(technician.name || `Técnico #${technician.technician_id}`)
          }));
      } else {
        this.technicians = [];
        this.filterOptionsError = this.filterOptionsError
          || 'No se pudieron cargar los técnicos para el filtro.';
      }
    },

    /**
     * Solicita el informe Pareto con el filtro vigente y repinta el gráfico.
     */
    async loadReport() {
      if (!this.validateDateRange()) {
        return;
      }

      this.isLoading = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        const report = await api.getRootCausesPareto(this.buildFilterParams());
        this.report = {
          filters: report?.filters || {},
          available_filters: {
            manufacturers: Array.isArray(report?.available_filters?.manufacturers)
              ? report.available_filters.manufacturers
              : [],
            models_by_manufacturer: report?.available_filters?.models_by_manufacturer || {},
            models: Array.isArray(report?.available_filters?.models)
              ? report.available_filters.models
              : []
          },
          summary: {
            ...SUMMARY_DEFAULTS,
            ...(report?.summary || {})
          },
          items: Array.isArray(report?.items) ? report.items : []
        };
      } catch (error) {
        this.errorMessage = error?.message
          || 'No se pudo cargar el análisis de Pareto de causas raíz.';
      } finally {
        this.isLoading = false;
        await this.$nextTick();
        this.renderChart();
      }
    },

    /**
     * Filtros canónicos del contrato §6.1. `buildParetoQuery` traduce las
     * fechas de la vista a `from`/`to` y omite los campos en blanco.
     */
    buildFilterParams() {
      return {
        start_date: this.filters.startDate,
        end_date: this.filters.endDate,
        category: this.filters.category,
        manufacturer: this.filters.manufacturer,
        machine_model: this.filters.machineModel,
        location_id: this.filters.locationId,
        technician_id: this.filters.technicianId,
        vital_few_only: this.filters.vitalFewOnly
      };
    },

    validateDateRange() {
      if (!this.filters.startDate || !this.filters.endDate) {
        this.errorMessage = 'Indique la fecha inicial y la fecha final del análisis.';
        return false;
      }

      if (this.filters.startDate > this.filters.endDate) {
        this.errorMessage = 'La fecha inicial no puede ser posterior a la fecha final.';
        return false;
      }

      return true;
    },

    /** Aplica la barra de filtros completa. */
    applyFilters() {
      this.loadReport();
    },

    /** El modelo debe pertenecer al fabricante elegido; si no, se limpia. */
    handleManufacturerChange() {
      if (!this.filters.machineModel) {
        return;
      }

      if (!this.models.includes(this.filters.machineModel)) {
        this.filters.machineModel = '';
      }
    },

    /** Botón «Catálogo maestro»: abre la administración del catálogo. */
    openCatalog() {
      this.showCatalog = true;
    },

    /** El catálogo cambió: los nombres vigentes del informe pueden haber cambiado. */
    async handleCatalogUpdated() {
      this.flashSuccess('El catálogo maestro se ha actualizado.');
      await this.loadReport();
    },

    async exportCsv() {
      if (!this.validateDateRange()) {
        return;
      }

      this.isExporting = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        await api.exportRootCausesParetoCsv(this.buildFilterParams());
        this.flashSuccess(
          'El CSV de Pareto y garantías se ha descargado con UTF-8 BOM y sus dos secciones.'
        );
      } catch (error) {
        this.errorMessage = error?.message
          || 'No se pudo exportar el informe Pareto a CSV.';
      } finally {
        this.isExporting = false;
      }
    },

    flashSuccess(message) {
      this.successMessage = message;

      if (this.successTimer !== null) {
        clearTimeout(this.successTimer);
      }

      this.successTimer = setTimeout(() => {
        this.successMessage = '';
        this.successTimer = null;
      }, 5000);
    },

    /**
     * Resuelve un token CSS computado. Se lee en cada render para que el
     * gráfico siga al tema vigente sin declarar un solo literal de color.
     */
    tokenColor(tokenName) {
      if (typeof window === 'undefined' || typeof document === 'undefined' || !document.documentElement) {
        return '';
      }

      const value = window.getComputedStyle(document.documentElement)
        .getPropertyValue(tokenName)
        .trim();

      return value || '';
    },

    tokenFont() {
      if (typeof window === 'undefined' || typeof document === 'undefined' || !document.documentElement) {
        return 'sans-serif';
      }

      const value = window.getComputedStyle(document.documentElement)
        .getPropertyValue('--font-body')
        .trim();

      return value || 'sans-serif';
    },

    destroyChart() {
      if (this.chartInstance) {
        this.chartInstance.destroy();
        this.chartInstance = null;
      }
    },

    /**
     * Gráfico combinado de doble eje: barras de frecuencia por causa y curva
     * del porcentaje acumulado con línea discontinua de corte en el 80 %.
     */
    renderChart() {
      const canvas = this.$refs.paretoChartCanvas;
      const ChartConstructor = typeof window !== 'undefined' ? window.Chart : null;

      this.destroyChart();

      if (!this.hasParetoData || !canvas) {
        this.chartUnavailable = false;
        return;
      }

      if (typeof ChartConstructor !== 'function') {
        this.chartUnavailable = true;
        return;
      }

      this.chartUnavailable = false;

      const labels = this.items.map((item) => String(item.root_cause_code || ''));
      const counts = this.items.map((item) => Number(item.incident_count) || 0);
      const cumulative = this.items.map((item) => Number(item.cumulative_percentage) || 0);

      const primary = this.tokenColor('--color-primary');
      const trivial = this.tokenColor('--color-hairline-soft');
      const line = this.tokenColor('--color-slate');
      const threshold = this.tokenColor('--color-error');
      const grid = this.tokenColor('--color-hairline');
      const muted = this.tokenColor('--color-ink-muted');
      const surface = this.tokenColor('--color-surface-card');
      const fontFamily = this.tokenFont();

      const vitalColors = this.items.map((item) => (item.is_vital_few === true ? primary : trivial));

      this.chartInstance = new ChartConstructor(canvas, {
        type: 'bar',
        data: {
          labels,
          datasets: [
            {
              type: 'bar',
              label: 'Averías',
              data: counts,
              backgroundColor: vitalColors,
              borderColor: vitalColors,
              borderWidth: 1,
              borderRadius: 4,
              maxBarThickness: 46,
              yAxisID: 'yCount',
              order: 2
            },
            {
              type: 'line',
              label: '% acumulado',
              data: cumulative,
              borderColor: line,
              backgroundColor: line,
              pointBackgroundColor: line,
              pointBorderColor: surface,
              pointRadius: 3,
              pointHoverRadius: 5,
              borderWidth: 2,
              tension: 0.25,
              fill: false,
              yAxisID: 'yCumulative',
              order: 0
            },
            {
              type: 'line',
              label: 'Umbral 80/20',
              data: labels.map(() => 80),
              borderColor: threshold,
              borderDash: [6, 4],
              borderWidth: 2,
              pointRadius: 0,
              fill: false,
              yAxisID: 'yCumulative',
              order: 1
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: 'index',
            intersect: false
          },
          plugins: {
            legend: {
              position: 'bottom',
              labels: {
                color: muted,
                usePointStyle: true,
                boxWidth: 10,
                font: {
                  family: fontFamily,
                  size: 12
                }
              }
            },
            tooltip: {
              callbacks: {
                label: (context) => {
                  const suffix = context.dataset.yAxisID === 'yCumulative' ? ' %' : '';
                  return `${context.dataset.label}: ${context.formattedValue}${suffix}`;
                }
              }
            }
          },
          scales: {
            x: {
              ticks: {
                color: muted,
                font: {
                  family: fontFamily,
                  size: 12
                }
              },
              grid: {
                display: false
              }
            },
            yCount: {
              position: 'left',
              beginAtZero: true,
              ticks: {
                precision: 0,
                color: muted,
                font: {
                  family: fontFamily,
                  size: 12
                }
              },
              grid: {
                color: grid
              },
              title: {
                display: true,
                text: 'Averías',
                color: muted,
                font: {
                  family: fontFamily,
                  size: 12
                }
              }
            },
            yCumulative: {
              position: 'right',
              beginAtZero: true,
              min: 0,
              max: 100,
              ticks: {
                color: muted,
                callback: (value) => `${value} %`,
                font: {
                  family: fontFamily,
                  size: 12
                }
              },
              grid: {
                drawOnChartArea: false
              },
              title: {
                display: true,
                text: '% acumulado',
                color: muted,
                font: {
                  family: fontFamily,
                  size: 12
                }
              }
            }
          }
        }
      });
    },

    formatPercent(value) {
      const numeric = Number(value);
      if (!Number.isFinite(numeric)) {
        return '0,00 %';
      }

      return `${numeric.toFixed(2).replace('.', ',')} %`;
    },

    formatHours(value) {
      const numeric = Number(value);
      if (!Number.isFinite(numeric)) {
        return '0,00 h';
      }

      return `${numeric.toFixed(2).replace('.', ',')} h`;
    },

    formatCurrency(value) {
      const numeric = Number(value);
      if (!Number.isFinite(numeric)) {
        return '0,00 €';
      }

      return new Intl.NumberFormat('es-ES', {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      }).format(numeric);
    },

    formatInteger(value) {
      const numeric = Number(value);
      if (!Number.isFinite(numeric)) {
        return '0';
      }

      return new Intl.NumberFormat('es-ES', { maximumFractionDigits: 0 }).format(numeric);
    },

    /**
     * El contrato vigente no publica el desglose de marca/modelo por causa.
     * La columna se mantiene para no inventar un dato que el motor no entrega.
     */
    affectedManufacturerModel(item) {
      const value = item?.top_manufacturer_model
        || item?.top_machine_model
        || item?.top_model
        || '';

      return value || '—';
    }
  },
  template: `
    <div class="vg-pareto-tab" data-testid="coordinator-root-cause-pareto-tab">
      <!-- La hoja vive en styles.css y esta pestaña la carga bajo demanda. -->
      <link rel="stylesheet" href="/assets/css/styles.css" />

      <header class="vg-taxonomy-panel__header">
        <div>
          <h2 class="vg-taxonomy-panel__title">Análisis Pareto 80/20 de Causas Raíz</h2>
          <p class="vg-taxonomy-panel__subtitle">
            Concentración de averías por causa, curva acumulada y trazabilidad de garantías
            sobre las averías clasificadas del periodo.
          </p>
        </div>

        <div class="vg-taxonomy-panel__actions">
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            data-testid="pareto-open-catalog"
            @click="openCatalog"
          >
            🗂️ Catálogo maestro
          </button>
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            data-testid="pareto-export-csv"
            :disabled="isBusy"
            @click="exportCsv"
          >
            <span v-if="isExporting">⏳ Generando…</span>
            <span v-else>📥 Exportar CSV (2 secciones)</span>
          </button>
        </div>
      </header>

      <div
        v-if="errorMessage"
        class="vg-taxonomy-alert vg-taxonomy-alert--error"
        role="alert"
        data-testid="pareto-error"
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
        data-testid="pareto-success"
      >
        <span>✅ {{ successMessage }}</span>
        <button
          type="button"
          class="vg-btn vg-btn-icon vg-btn-ghost"
          aria-label="Cerrar el aviso de confirmación"
          @click="successMessage = ''"
        >×</button>
      </div>

      <div
        v-if="filterOptionsError"
        class="vg-taxonomy-alert vg-taxonomy-alert--warning"
        role="status"
        data-testid="pareto-filter-options-warning"
      >
        <span>ℹ️ {{ filterOptionsError }}</span>
      </div>

      <section class="vg-taxonomy-card vg-taxonomy-filters" aria-label="Filtros del análisis Pareto">
        <div class="vg-taxonomy-filters__grid">
          <label class="vg-taxonomy-field" for="pareto-start-date">
            <span class="vg-taxonomy-field__label">Desde</span>
            <input
              id="pareto-start-date"
              class="vg-taxonomy-input"
              type="date"
              v-model="filters.startDate"
              data-testid="pareto-filter-start-date"
            />
          </label>

          <label class="vg-taxonomy-field" for="pareto-end-date">
            <span class="vg-taxonomy-field__label">Hasta</span>
            <input
              id="pareto-end-date"
              class="vg-taxonomy-input"
              type="date"
              v-model="filters.endDate"
              data-testid="pareto-filter-end-date"
            />
          </label>

          <label class="vg-taxonomy-field" for="pareto-category">
            <span class="vg-taxonomy-field__label">Categoría</span>
            <select
              id="pareto-category"
              class="vg-taxonomy-input"
              v-model="filters.category"
              data-testid="pareto-filter-category"
            >
              <option value="">Todas las categorías</option>
              <option v-for="category in categoryOptions" :key="category.value" :value="category.value">
                {{ category.label }}
              </option>
            </select>
          </label>

          <label class="vg-taxonomy-field" for="pareto-manufacturer">
            <span class="vg-taxonomy-field__label">Fabricante / Marca</span>
            <select
              id="pareto-manufacturer"
              class="vg-taxonomy-input"
              v-model="filters.manufacturer"
              data-testid="pareto-filter-manufacturer"
              @change="handleManufacturerChange"
            >
              <option value="">Todos los fabricantes</option>
              <option v-for="manufacturer in manufacturers" :key="manufacturer" :value="manufacturer">
                {{ manufacturer }}
              </option>
            </select>
          </label>

          <label class="vg-taxonomy-field" for="pareto-model">
            <span class="vg-taxonomy-field__label">Modelo de máquina</span>
            <select
              id="pareto-model"
              class="vg-taxonomy-input"
              v-model="filters.machineModel"
              data-testid="pareto-filter-model"
            >
              <option value="">Todos los modelos</option>
              <option v-for="model in models" :key="model" :value="model">{{ model }}</option>
            </select>
          </label>

          <label class="vg-taxonomy-field" for="pareto-location">
            <span class="vg-taxonomy-field__label">Sede</span>
            <select
              id="pareto-location"
              class="vg-taxonomy-input"
              v-model="filters.locationId"
              data-testid="pareto-filter-location"
            >
              <option value="">Todas las sedes</option>
              <option v-for="location in locations" :key="location.id" :value="location.id">
                {{ location.name }}
              </option>
            </select>
          </label>

          <label class="vg-taxonomy-field" for="pareto-technician">
            <span class="vg-taxonomy-field__label">Técnico</span>
            <select
              id="pareto-technician"
              class="vg-taxonomy-input"
              v-model="filters.technicianId"
              data-testid="pareto-filter-technician"
            >
              <option value="">Todos los técnicos</option>
              <option v-for="technician in technicians" :key="technician.id" :value="technician.id">
                {{ technician.name }}
              </option>
            </select>
          </label>

          <div class="vg-taxonomy-filters__switch">
            <label class="vg-taxonomy-toggle" for="pareto-vital-few">
              <input
                id="pareto-vital-few"
                type="checkbox"
                v-model="filters.vitalFewOnly"
                data-testid="pareto-filter-vital"
              />
              <span>Solo Zona Vital 80/20</span>
            </label>

            <button
              type="button"
              class="vg-btn vg-btn-primary"
              data-testid="pareto-apply-filters"
              :disabled="isBusy"
              @click="applyFilters"
            >
              <span v-if="isLoading">⏳ Calculando…</span>
              <span v-else>🔎 Aplicar filtros</span>
            </button>
          </div>
        </div>
      </section>

      <div v-if="isLoading" class="vg-taxonomy-loading" role="status" data-testid="pareto-loading">
        Calculando la concentración de causas raíz…
      </div>

      <template v-else>
        <section class="vg-taxonomy-kpis" aria-label="Indicadores del análisis Pareto">
          <article class="vg-taxonomy-kpi">
            <span class="vg-taxonomy-kpi__label">Averías clasificadas</span>
            <strong class="vg-taxonomy-kpi__value" data-testid="pareto-kpi-classified">
              {{ formatInteger(summary.classified_incidents_count) }}
            </strong>
            <span class="vg-taxonomy-kpi__hint">Base del 100 % de Pareto</span>
          </article>

          <article class="vg-taxonomy-kpi">
            <span class="vg-taxonomy-kpi__label">Causas en Zona Vital</span>
            <strong class="vg-taxonomy-kpi__value" data-testid="pareto-kpi-vital">
              {{ formatInteger(summary.vital_few_causes_count) }}
            </strong>
            <span class="vg-taxonomy-kpi__hint">Hasta cruzar el 80,00 % acumulado</span>
          </article>

          <article class="vg-taxonomy-kpi">
            <span class="vg-taxonomy-kpi__label">Concentración 80/20</span>
            <strong class="vg-taxonomy-kpi__value vg-taxonomy-kpi__value--primary" data-testid="pareto-kpi-concentration">
              {{ vitalConcentrationLabel }}
            </strong>
            <span class="vg-taxonomy-kpi__hint">{{ vitalConcentrationHint }}</span>
          </article>

          <article class="vg-taxonomy-kpi">
            <span class="vg-taxonomy-kpi__label">Avisos históricos sin clasificar</span>
            <strong class="vg-taxonomy-kpi__value" data-testid="pareto-kpi-unclassified">
              {{ formatInteger(summary.unclassified_historical_count) }}
            </strong>
            <span class="vg-taxonomy-kpi__hint">Excluidos de la base del Pareto</span>
          </article>
        </section>

        <section class="vg-taxonomy-card vg-taxonomy-chart" aria-label="Gráfico Pareto 80/20">
          <div class="vg-taxonomy-card__heading">
            <div>
              <h3 class="vg-taxonomy-card__title">Diagrama de Pareto</h3>
              <p class="vg-taxonomy-card__subtitle">
                Barras azules: causas de la Zona Vital. Barras neutras: cola del 20 % trivial.
                Línea discontinua: umbral del 80 %.
              </p>
            </div>
          </div>

          <div v-if="hasParetoData" class="vg-pareto-chart__canvas-wrap">
            <canvas
              ref="paretoChartCanvas"
              class="vg-pareto-chart__canvas"
              data-testid="pareto-chart"
              aria-label="Gráfico combinado de averías por causa y porcentaje acumulado"
              role="img"
            ></canvas>
          </div>

          <div v-else class="vg-taxonomy-empty" data-testid="pareto-empty">
            <span class="vg-taxonomy-empty__icon" aria-hidden="true">📉</span>
            <strong>Sin averías clasificadas en esta ventana.</strong>
            <span>Amplíe el rango de fechas o retire filtros para ver la curva 80/20.</span>
          </div>

          <p
            v-if="chartUnavailable && hasParetoData"
            class="vg-taxonomy-note vg-taxonomy-note--warning"
            role="alert"
          >
            No se pudo cargar el motor local de gráficos. La tabla inferior sigue disponible.
          </p>
        </section>

        <section class="vg-taxonomy-card" aria-label="Desglose de causas raíz">
          <div class="vg-taxonomy-card__heading">
            <div>
              <h3 class="vg-taxonomy-card__title">Desglose de Causas Raíz</h3>
              <p class="vg-taxonomy-card__subtitle">
                Orden determinista por volumen y código. El coste de repuestos se congela por
                snapshot y el MTTR es el tiempo neto medio de resolución.
              </p>
            </div>
            <span class="vg-taxonomy-chip vg-taxonomy-chip--neutral">
              {{ formatInteger(summary.total_replaced_parts_units) }} uds ·
              {{ formatCurrency(summary.total_replaced_parts_cost_eur) }}
            </span>
          </div>

          <div v-if="items.length === 0" class="vg-taxonomy-empty">
            <span class="vg-taxonomy-empty__icon" aria-hidden="true">🗂️</span>
            <strong>No hay causas que mostrar.</strong>
            <span>El filtro actual no devuelve averías clasificadas.</span>
          </div>

          <div v-else class="vg-taxonomy-table-wrap">
            <table class="vg-taxonomy-table" data-testid="pareto-table">
              <thead>
                <tr>
                  <th scope="col">#</th>
                  <th scope="col">Causa raíz</th>
                  <th scope="col">Categoría</th>
                  <th scope="col" class="is-numeric">Averías</th>
                  <th scope="col" class="is-numeric">% individual</th>
                  <th scope="col" class="is-numeric">% acumulado</th>
                  <th scope="col">Zona 80/20</th>
                  <th scope="col">Solución más aplicada</th>
                  <th scope="col">Marca / modelo más afectado</th>
                  <th scope="col" class="is-numeric">MTTR medio</th>
                  <th scope="col" class="is-numeric">Repuestos</th>
                  <th scope="col" class="is-numeric">Coste repuestos</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="item in items"
                  :key="item.root_cause_id"
                  :class="{ 'is-vital': item.is_vital_few === true }"
                  :data-testid="'pareto-row-' + item.root_cause_id"
                >
                  <td class="vg-taxonomy-table__rank">{{ item.rank }}</td>
                  <td>
                    <span class="vg-taxonomy-code">{{ item.root_cause_code }}</span>
                    <span class="vg-taxonomy-table__name">{{ item.root_cause_name }}</span>
                  </td>
                  <td>{{ item.category_label }}</td>
                  <td class="is-numeric">{{ formatInteger(item.incident_count) }}</td>
                  <td class="is-numeric">{{ formatPercent(item.percentage) }}</td>
                  <td class="is-numeric">{{ formatPercent(item.cumulative_percentage) }}</td>
                  <td>
                    <span
                      v-if="item.is_vital_few === true"
                      class="vg-taxonomy-chip vg-taxonomy-chip--vital"
                    >
                      ZONA VITAL 80/20
                    </span>
                    <span v-else class="vg-taxonomy-chip vg-taxonomy-chip--neutral">Cola 20 %</span>
                  </td>
                  <td>
                    <template v-if="item.top_solution">
                      <span class="vg-taxonomy-code">{{ item.top_solution.code }}</span>
                      <span class="vg-taxonomy-table__name">{{ item.top_solution.name }}</span>
                      <span class="vg-taxonomy-table__meta">
                        {{ formatInteger(item.top_solution.applications_count) }} aplicaciones
                      </span>
                    </template>
                    <span v-else class="vg-taxonomy-table__muted">Sin solución registrada</span>
                  </td>
                  <td
                    class="vg-taxonomy-table__muted"
                    title="El motor Pareto no desglosa fabricante y modelo por causa; acote la consulta con los filtros superiores."
                  >
                    {{ affectedManufacturerModel(item) }}
                  </td>
                  <td class="is-numeric">{{ formatHours(item.avg_mttr_hours) }}</td>
                  <td class="is-numeric">{{ formatInteger(item.replaced_parts_units) }}</td>
                  <td class="is-numeric">{{ formatCurrency(item.replaced_parts_cost_eur) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <p class="vg-taxonomy-note">
            El contrato Pareto no desglosa el fabricante y el modelo por causa. Acote la
            consulta con los filtros superiores para leer el parque afectado.
          </p>
        </section>
      </template>

      <CoordinatorRootCauseCatalogModal
        v-model="showCatalog"
        @updated="handleCatalogUpdated"
      />
    </div>
  `
};

export default CoordinatorRootCauseParetoTab;
