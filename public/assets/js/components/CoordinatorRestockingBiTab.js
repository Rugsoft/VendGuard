/**
 * VendGuard - CoordinatorRestockingBiTab (CoordinatorRestockingBiTab.js)
 *
 * Sub-pestaña «📦 Métricas de Carga y Reposición» del Cuadro de Mando (Módulo
 * 38: RF-06.1, RF-06.2, D4, D5, RNF-04, QA-1, QA-3, `docs/design.md`).
 *
 * La pestaña presenta el informe que ya calcula el motor BI del servidor: no
 * reimplementa la disponibilidad, el desglose de mermas ni la insignia de
 * planograma sobredimensionado. Aquí solo se filtran fechas, zona, sede y
 * reponedor, se pintan las tarjetas KPI, los dos gráficos y las tres tablas, y
 * se ofrece la descarga CSV autenticada.
 *
 * Reglas visuales:
 *   - Cero literales cromáticos: Chart.js resuelve cada trazo leyendo los
 *     tokens CSS computados (`--color-primary`, `--color-warning`,
 *     `--color-error`, `--color-slate`, `--color-hairline`, `--font-body`).
 *   - Sin `window.Chart` el componente degrada a un aviso: las tarjetas y las
 *     tablas siguen disponibles (D5).
 *   - Filtros en cascada: cambiar de zona limpia la sede que ya no pertenece a
 *     la nueva demarcación antes de recargar el informe (QA-L5).
 *
 * Dogma Vanilla: Vue 3 Options API vía ES Modules nativos, sin dependencias.
 * Dualismo Lingüístico: código en inglés, interfaz y mensajes en castellano.
 */

import { api } from '../api.js';

const EMPTY_FILTERS = () => ({
  from: '',
  to: '',
  zone_id: '',
  location_id: '',
  restocker_id: ''
});

const EMPTY_REPORT = () => ({
  filters: {},
  available_filters: { zones: [], locations: [], restockers: [] },
  summary: {},
  spoilage_comparison: {
    commercial_fefo_expired: { units: 0, loss_eur: 0 },
    commercial_damaged: { units: 0, loss_eur: 0 },
    commercial_total: { units: 0, loss_eur: 0 },
    thermal_haccp: {
      segmentable: true,
      non_segmentable_reason: null,
      non_segmentable_message: null,
      records_count: 0,
      units: 0,
      loss_eur: 0
    }
  },
  charts: {
    spoilage_bars: {
      labels: [],
      keys: [],
      eur_values: [],
      unit_values: [],
      thermal_segmentable: true
    },
    top_critical_stockout: { by_machine: [], by_product: [] },
    top_critical_expiry: { by_machine: [], by_product: [] }
  },
  by_machine: [],
  by_product: [],
  by_restocker: []
});

const THERMAL_NOTICE = 'No segmentable por reponedor (ámbito máquina/SAT)';

function todayInMadrid() {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Madrid' }).format(new Date());
}

/** Desplaza una fecha ISO en días naturales sin cruzar husos horarios. */
function shiftIsoDate(isoDate, days) {
  const noonUtc = new Date(`${isoDate}T12:00:00Z`);
  noonUtc.setUTCDate(noonUtc.getUTCDate() + days);
  return noonUtc.toISOString().slice(0, 10);
}

/** Ventana por defecto del contrato: últimos 30 días naturales en Madrid. */
function defaultDateRange() {
  const to = todayInMadrid();
  return { from: shiftIsoDate(to, -29), to };
}

function emptyReport() {
  return EMPTY_REPORT();
}

function unwrap(response) {
  return response && response.data !== undefined ? response.data : response;
}

function asArray(value) {
  return Array.isArray(value) ? value : [];
}

function numeric(value) {
  if (value === null || value === undefined || value === '') {
    return null;
  }

  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

export const CoordinatorRestockingBiTab = {
  name: 'CoordinatorRestockingBiTab',
  data() {
    const range = defaultDateRange();

    return {
      filters: {
        ...EMPTY_FILTERS(),
        from: range.from,
        to: range.to
      },
      report: emptyReport(),
      spoilageMetric: 'eur',
      topMetric: 'stockout',
      topDimension: 'machine',
      isLoading: false,
      isExporting: false,
      errorMessage: '',
      successMessage: '',
      chartUnavailable: false,
      spoilageChartInstance: null,
      topChartInstance: null
    };
  },
  computed: {
    summary() {
      return this.report?.summary || {};
    },
    comparison() {
      return this.report?.spoilage_comparison || emptyReport().spoilage_comparison;
    },
    thermal() {
      return this.comparison?.thermal_haccp || emptyReport().spoilage_comparison.thermal_haccp;
    },
    charts() {
      return this.report?.charts || emptyReport().charts;
    },
    machines() {
      return asArray(this.report?.by_machine);
    },
    products() {
      return asArray(this.report?.by_product);
    },
    restockers() {
      return asArray(this.report?.by_restocker);
    },
    zones() {
      return asArray(this.report?.available_filters?.zones);
    },
    allLocations() {
      return asArray(this.report?.available_filters?.locations);
    },
    /** Sedes acotadas a la zona vigente; sin zona, el catálogo completo. */
    locationsInZone() {
      if (this.filters.zone_id === '' || this.filters.zone_id === null) {
        return this.allLocations;
      }

      return this.allLocations.filter(
        (location) => String(location?.zone_id ?? '') === String(this.filters.zone_id)
      );
    },
    restockerOptions() {
      return asArray(this.report?.available_filters?.restockers);
    },
    isBusy() {
      return this.isLoading || this.isExporting;
    },
    thermalNotSegmentable() {
      return this.thermal?.segmentable === false;
    },
    thermalNoticeMessage() {
      return this.thermal?.non_segmentable_message || THERMAL_NOTICE;
    },
    hasRows() {
      return this.machines.length > 0 || this.products.length > 0 || this.restockers.length > 0;
    },
    summaryCards() {
      const summary = this.summary;
      const thermalHint = this.thermalNotSegmentable
        ? this.thermalNoticeMessage
        : `${this.formatInteger(this.thermal?.records_count)} actas APPCC`;

      return [
        {
          key: 'visits',
          label: 'Visitas completadas',
          value: this.formatInteger(summary.completed_visits_count),
          hint: `${this.formatInteger(summary.thermal_aborted_visits_count)} abortos térmicos`
        },
        {
          key: 'visit-stockout',
          label: 'Disponibilidad por visita',
          value: this.formatPercent(summary.visit_in_stock_rate_pct),
          hint: `Rotura de visita: ${this.formatPercent(summary.visit_stockout_rate_pct)}`
        },
        {
          key: 'slot-stockout',
          label: 'Rotura por carril',
          value: this.formatPercent(summary.slot_stockout_rate_pct),
          hint: `${this.formatInteger(summary.stockout_slots_count)} de ${this.formatInteger(summary.audited_slots_count)} carriles`
        },
        {
          key: 'low-threshold',
          label: 'Carriles bajo umbral',
          value: this.formatInteger(summary.low_threshold_slots_count),
          hint: 'Producto disponible por debajo del mínimo'
        },
        {
          key: 'commercial-spoilage',
          label: 'Merma comercial',
          value: this.formatCurrency(summary.commercial_spoilage_eur),
          hint: `${this.formatInteger(summary.commercial_spoilage_units)} uds (FEFO + dañado)`
        },
        {
          key: 'thermal-spoilage',
          label: 'Merma térmica APPCC',
          value: this.formatCurrency(summary.thermal_spoilage_eur),
          hint: thermalHint
        },
        {
          key: 'oversized',
          label: 'Planogramas sobredimensionados',
          value: this.formatInteger(summary.oversized_machines_count),
          hint: 'Tasa de caducidad ≥ 15,00 % con actividad mínima'
        },
        {
          key: 'cash-accuracy',
          label: 'Exactitud de arqueos',
          value: this.formatPercent(summary.cash_accuracy_rate_pct),
          hint: `${this.formatInteger(summary.cash_reconciled_ok_count)} OK · ${this.formatInteger(summary.cash_discrepancy_flagged_count)} descuadre`
        },
        {
          key: 'l1-synergy',
          label: 'L1 resueltas / escaladas SAT',
          value: `${this.formatInteger(summary.l1_resolved_count)} / ${this.formatInteger(summary.escalated_to_sat_count)}`,
          hint: 'Sinergia Nivel 1 sobre incidencias M36'
        }
      ];
    },
    spoilageRows() {
      const bars = this.charts?.spoilage_bars || {};
      const labels = asArray(bars.labels);
      const keys = asArray(bars.keys);
      const eurValues = asArray(bars.eur_values);
      const unitValues = asArray(bars.unit_values);

      if (labels.length > 0) {
        return labels.map((label, index) => ({
          key: keys[index] || `bar-${index}`,
          label,
          eur: numeric(eurValues[index]) ?? 0,
          units: numeric(unitValues[index]) ?? 0
        }));
      }

      const fallback = [
        {
          key: 'commercial_fefo_expired',
          label: 'Caducidad FEFO',
          eur: numeric(this.comparison?.commercial_fefo_expired?.loss_eur) ?? 0,
          units: numeric(this.comparison?.commercial_fefo_expired?.units) ?? 0
        },
        {
          key: 'commercial_damaged',
          label: 'Dañado en ruta',
          eur: numeric(this.comparison?.commercial_damaged?.loss_eur) ?? 0,
          units: numeric(this.comparison?.commercial_damaged?.units) ?? 0
        }
      ];

      if (!this.thermalNotSegmentable) {
        fallback.push({
          key: 'thermal_haccp',
          label: 'Avería Térmica APPCC',
          eur: numeric(this.thermal?.loss_eur) ?? 0,
          units: numeric(this.thermal?.units) ?? 0
        });
      }

      return fallback;
    },
    spoilageSeries() {
      return {
        labels: this.spoilageRows.map((row) => row.label),
        values: this.spoilageRows.map((row) => (this.spoilageMetric === 'units' ? row.units : row.eur))
      };
    },
    topSource() {
      return this.topMetric === 'expiry'
        ? this.charts?.top_critical_expiry
        : this.charts?.top_critical_stockout;
    },
    topRows() {
      const source = this.topSource || {};
      return this.topDimension === 'product' ? asArray(source.by_product) : asArray(source.by_machine);
    },
    topSeries() {
      return {
        labels: this.topRows.map((row) => row.label || row.code || `#${row.id}`),
        values: this.topRows.map((row) => {
          const rate = this.topMetric === 'expiry' ? row.expiry_rate_pct : row.slot_stockout_rate_pct;
          return numeric(rate) ?? 0;
        })
      };
    },
    topMetricLabel() {
      return this.topMetric === 'expiry' ? 'Caducidad FEFO' : 'Rotura de stock';
    },
    /** Un periodo sin merma no pinta barras a cero: declara el vacío. */
    hasSpoilageSeries() {
      return this.spoilageSeries.values.some((value) => value > 0);
    },
    hasTopSeries() {
      return this.topSeries.values.length > 0;
    }
  },
  mounted() {
    this.loadReport();
  },
  beforeUnmount() {
    this.destroyCharts();
  },
  methods: {
    /** Parámetros canónicos: solo viajan los filtros con valor. */
    buildFilterParams() {
      const params = {
        from: this.filters.from,
        to: this.filters.to,
        zone_id: this.filters.zone_id,
        location_id: this.filters.location_id,
        restocker_id: this.filters.restocker_id
      };

      return Object.fromEntries(
        Object.entries(params).filter(([, value]) => value !== '' && value !== null && value !== undefined)
      );
    },

    validateDateRange() {
      if (!this.filters.from || !this.filters.to) {
        this.errorMessage = 'Indique la fecha inicial y la fecha final del informe.';
        return false;
      }

      if (this.filters.from > this.filters.to) {
        this.errorMessage = 'La fecha inicial no puede ser posterior a la fecha final.';
        return false;
      }

      return true;
    },

    async loadReport() {
      if (!this.validateDateRange()) {
        return;
      }

      this.isLoading = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        const payload = unwrap(await api.getRestockingBi(this.buildFilterParams()));
        const base = emptyReport();
        this.report = {
          filters: payload?.filters || {},
          available_filters: {
            zones: asArray(payload?.available_filters?.zones),
            locations: asArray(payload?.available_filters?.locations),
            restockers: asArray(payload?.available_filters?.restockers)
          },
          summary: { ...base.summary, ...(payload?.summary || {}) },
          spoilage_comparison: {
            ...base.spoilage_comparison,
            ...(payload?.spoilage_comparison || {})
          },
          charts: {
            spoilage_bars: {
              ...base.charts.spoilage_bars,
              ...(payload?.charts?.spoilage_bars || {})
            },
            top_critical_stockout: {
              ...base.charts.top_critical_stockout,
              ...(payload?.charts?.top_critical_stockout || {})
            },
            top_critical_expiry: {
              ...base.charts.top_critical_expiry,
              ...(payload?.charts?.top_critical_expiry || {})
            }
          },
          by_machine: asArray(payload?.by_machine),
          by_product: asArray(payload?.by_product),
          by_restocker: asArray(payload?.by_restocker)
        };

        if (typeof this.$nextTick === 'function') {
          await this.$nextTick();
        }
        this.renderCharts();
      } catch (error) {
        this.errorMessage = error?.message || 'No se pudo cargar el informe de reposición y mermas.';
      } finally {
        this.isLoading = false;
      }
    },

    applyFilters() {
      return this.loadReport();
    },

    /**
     * Cascada territorial (QA-L5): la sede elegida solo puede pertenecer a la
     * zona vigente. Si deja de pertenecer, se limpia antes de recargar.
     */
    async handleZoneChange() {
      if (this.filters.location_id !== '' && this.filters.location_id !== null) {
        const belongsToZone = this.locationsInZone.some(
          (location) => String(location.id) === String(this.filters.location_id)
        );

        if (!belongsToZone) {
          this.filters.location_id = '';
        }
      }

      await this.loadReport();
    },

    async exportCsv() {
      this.isExporting = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        await api.downloadRestockingBiCsv(this.buildFilterParams());
        this.successMessage = 'El CSV de reposición y mermas se ha descargado con UTF-8 BOM.';
      } catch (error) {
        this.errorMessage = error?.message || 'No se pudo descargar el CSV de reposición y mermas.';
      } finally {
        this.isExporting = false;
      }
    },

    // ---------------------------------------------------------------------
    // Formato
    // ---------------------------------------------------------------------

    formatInteger(value) {
      const number = numeric(value);
      return number === null ? '—' : new Intl.NumberFormat('es-ES', { maximumFractionDigits: 0 }).format(number);
    },

    formatPercent(value) {
      const number = numeric(value);
      return number === null
        ? '—'
        : `${new Intl.NumberFormat('es-ES', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        }).format(number)} %`;
    },

    formatCurrency(value) {
      const number = numeric(value);
      return number === null
        ? '—'
        : new Intl.NumberFormat('es-ES', {
          style: 'currency',
          currency: 'EUR',
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        }).format(number);
    },

    formatDecimal(value, digits = 1) {
      const number = numeric(value);
      return number === null
        ? '—'
        : new Intl.NumberFormat('es-ES', {
          minimumFractionDigits: digits,
          maximumFractionDigits: digits
        }).format(number);
    },

    stopDurationLabel(row) {
      const minutes = numeric(row?.avg_stop_duration_minutes);
      return minutes === null ? '—' : `${this.formatDecimal(minutes, 1)} min`;
    },

    machinesPerShiftLabel(row) {
      if (row?.machines_per_shift_na_reason === 'NOT_SEGMENTABLE_BY_TERRITORY') {
        return 'No segmentable';
      }

      if (row?.machines_per_shift_na_reason === 'NO_VALID_SHIFTS') {
        return 'Sin turnos';
      }

      return this.formatDecimal(row?.machines_per_shift, 2);
    },

    zoneLabel(zone) {
      const code = zone?.code || `#${zone?.id}`;
      const name = zone?.name || 'Zona sin nombre';
      return zone?.is_active === false ? `${code} · ${name} (Baja)` : `${code} · ${name}`;
    },

    locationLabel(location) {
      const code = location?.site_code || `#${location?.id}`;
      const name = location?.name || 'Sede sin nombre';
      const suffix = location?.is_active === false ? ' (Baja)' : '';
      return `${code} · ${name}${suffix}`;
    },

    restockerLabel(restocker) {
      const code = restocker?.operator_code || '—';
      const name = restocker?.name || `Reponedor #${restocker?.id}`;
      const suffix = restocker?.is_active === false ? ' (Baja)' : '';
      return `${code} · ${name}${suffix}`;
    },

    machineLocationLabel(row) {
      const zone = row?.zone_name || 'Sin zona';
      const location = row?.location_name || 'Sede sin nombre';
      return `${location} · ${zone}`;
    },

    thermalAbortsLabel(row) {
      const aborts = numeric(row?.thermal_aborted_visits_count) ?? 0;
      return aborts > 0 ? `${this.formatInteger(aborts)} ⚠️` : '0';
    },

    // ---------------------------------------------------------------------
    // Gráficos
    // ---------------------------------------------------------------------

    tokenColor(tokenName) {
      if (typeof window === 'undefined' || typeof document === 'undefined' || !document.documentElement) {
        return '';
      }

      if (typeof window.getComputedStyle !== 'function') {
        return '';
      }

      return window.getComputedStyle(document.documentElement)
        .getPropertyValue(tokenName)
        .trim();
    },

    tokenFont() {
      return this.tokenColor('--font-body') || 'sans-serif';
    },

    destroyCharts() {
      // `stop()` cancela la animación viva antes de destruir: sin él, un frame
      // ya encolado intenta dibujar sobre un contexto nulo al recrear el
      // gráfico en el mismo canvas (excepción real en Chromium, T-RBI-07).
      for (const key of ['spoilageChartInstance', 'topChartInstance']) {
        const chart = this[key];
        if (!chart) {
          continue;
        }

        if (typeof chart.stop === 'function') {
          chart.stop();
        }
        chart.destroy();
        this[key] = null;
      }
    },

    /**
     * Switching the criterion can mount the canvas behind `v-if`. Wait for
     * Vue's DOM patch so `renderCharts()` can resolve the template ref.
     */
    async setTopMetric(metric) {
      if (this.topMetric === metric) {
        return;
      }

      this.topMetric = metric;
      await this.$nextTick();
      this.renderCharts();
    },

    /** Same wait as `setTopMetric`: the dimension can mount the canvas too. */
    async setTopDimension(dimension) {
      if (this.topDimension === dimension) {
        return;
      }

      this.topDimension = dimension;
      await this.$nextTick();
      this.renderCharts();
    },

    /** Recrea ambos gráficos en cada recarga o cambio de conmutador (D5). */
    renderCharts() {
      this.destroyCharts();

      const ChartConstructor = typeof window !== 'undefined' ? window.Chart : null;
      const spoilageCanvas = this.$refs?.spoilageChartCanvas;
      const topCanvas = this.$refs?.topChartCanvas;
      const hasSpoilage = this.hasSpoilageSeries;
      const hasTop = this.hasTopSeries;

      if (typeof ChartConstructor !== 'function') {
        this.chartUnavailable = Boolean(hasSpoilage || hasTop);
        return;
      }

      this.chartUnavailable = false;

      if (hasSpoilage && spoilageCanvas) {
        this.spoilageChartInstance = new ChartConstructor(spoilageCanvas, this.buildSpoilageChartConfig());
      }

      if (hasTop && topCanvas) {
        this.topChartInstance = new ChartConstructor(topCanvas, this.buildTopChartConfig());
      }
    },

    buildSpoilageChartConfig() {
      const fontFamily = this.tokenFont();
      const muted = this.tokenColor('--color-ink-muted');
      const grid = this.tokenColor('--color-hairline');
      const surface = this.tokenColor('--color-surface-card');
      const isUnits = this.spoilageMetric === 'units';

      const colors = this.spoilageRows.map((row) => {
        if (row.key === 'thermal_haccp') {
          return this.tokenColor('--color-error');
        }

        return row.key === 'commercial_damaged'
          ? this.tokenColor('--color-warning')
          : this.tokenColor('--color-primary');
      });

      return {
        type: 'bar',
        data: {
          labels: this.spoilageSeries.labels,
          datasets: [
            {
              label: isUnits ? 'Unidades retiradas' : 'Pérdida estimada (€)',
              data: this.spoilageSeries.values,
              backgroundColor: colors,
              borderColor: colors,
              borderWidth: 1,
              borderRadius: 4,
              maxBarThickness: 76
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: surface,
              titleColor: muted,
              bodyColor: muted,
              borderColor: grid,
              borderWidth: 1,
              callbacks: {
                label: (context) => {
                  const value = Number(context.raw) || 0;
                  return isUnits
                    ? `${context.dataset.label}: ${this.formatInteger(value)} uds`
                    : `${context.dataset.label}: ${this.formatCurrency(value)}`;
                }
              }
            }
          },
          scales: {
            x: {
              ticks: { color: muted, font: { family: fontFamily, size: 12 } },
              grid: { display: false }
            },
            y: {
              beginAtZero: true,
              ticks: { color: muted, font: { family: fontFamily, size: 12 } },
              grid: { color: grid }
            }
          }
        }
      };
    },

    buildTopChartConfig() {
      const fontFamily = this.tokenFont();
      const muted = this.tokenColor('--color-ink-muted');
      const grid = this.tokenColor('--color-hairline');
      const surface = this.tokenColor('--color-surface-card');
      const barColor = this.topMetric === 'expiry'
        ? this.tokenColor('--color-warning')
        : this.tokenColor('--color-primary');

      return {
        type: 'bar',
        data: {
          labels: this.topSeries.labels,
          datasets: [
            {
              label: `${this.topMetricLabel} (%)`,
              data: this.topSeries.values,
              backgroundColor: barColor,
              borderColor: barColor,
              borderWidth: 1,
              borderRadius: 4,
              maxBarThickness: 28
            }
          ]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          animation: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: surface,
              titleColor: muted,
              bodyColor: muted,
              borderColor: grid,
              borderWidth: 1,
              callbacks: {
                label: (context) => `${this.topMetricLabel}: ${this.formatPercent(context.raw)}`
              }
            }
          },
          scales: {
            x: {
              beginAtZero: true,
              ticks: {
                color: muted,
                font: { family: fontFamily, size: 12 },
                callback: (value) => `${value} %`
              },
              grid: { color: grid }
            },
            y: {
              ticks: { color: muted, font: { family: fontFamily, size: 12 } },
              grid: { display: false }
            }
          }
        }
      };
    }
  },
  template: `
    <div class="vg-restocking-bi" data-testid="restocking-bi-tab" style="display: flex; flex-direction: column; gap: 20px;">
      <!-- Barra de filtros propia: la barra global de SAT se oculta en esta pestaña (QA-3). -->
      <div
        style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 20px; display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px 16px;"
      >
        <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 700; color: var(--color-ink-secondary);">
          <span>📅 Desde</span>
          <input
            type="date"
            v-model="filters.from"
            data-testid="restocking-bi-filter-from"
            style="min-height: 36px; padding: 6px 10px; font-size: 13px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); background-color: var(--color-surface-card); color: var(--color-ink);"
          />
        </label>

        <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 700; color: var(--color-ink-secondary);">
          <span>📅 Hasta</span>
          <input
            type="date"
            v-model="filters.to"
            data-testid="restocking-bi-filter-to"
            style="min-height: 36px; padding: 6px 10px; font-size: 13px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); background-color: var(--color-surface-card); color: var(--color-ink);"
          />
        </label>

        <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 700; color: var(--color-ink-secondary);">
          <span>🗺️ Zona</span>
          <select
            v-model="filters.zone_id"
            data-testid="restocking-bi-filter-zone"
            @change="handleZoneChange"
            style="min-height: 36px; max-width: 220px; padding: 6px 10px; font-size: 13px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); background-color: var(--color-surface-card); color: var(--color-ink);"
          >
            <option value="">Todas las zonas</option>
            <option v-for="zone in zones" :key="zone.id" :value="zone.id">{{ zoneLabel(zone) }}</option>
          </select>
        </label>

        <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 700; color: var(--color-ink-secondary);">
          <span>🏢 Sede</span>
          <select
            v-model="filters.location_id"
            data-testid="restocking-bi-filter-location"
            style="min-height: 36px; max-width: 240px; padding: 6px 10px; font-size: 13px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); background-color: var(--color-surface-card); color: var(--color-ink);"
          >
            <option value="">Todas las sedes</option>
            <option v-for="location in locationsInZone" :key="location.id" :value="location.id">
              {{ locationLabel(location) }}
            </option>
          </select>
        </label>

        <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 700; color: var(--color-ink-secondary);">
          <span>📦 Reponedor</span>
          <select
            v-model="filters.restocker_id"
            data-testid="restocking-bi-filter-restocker"
            style="min-height: 36px; max-width: 240px; padding: 6px 10px; font-size: 13px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); background-color: var(--color-surface-card); color: var(--color-ink);"
          >
            <option value="">Todos los reponedores</option>
            <option v-for="restocker in restockerOptions" :key="restocker.id" :value="restocker.id">
              {{ restockerLabel(restocker) }}
            </option>
          </select>
        </label>

        <button
          type="button"
          class="vg-btn vg-btn-primary"
          data-testid="restocking-bi-apply-filters"
          :disabled="isBusy"
          @click="applyFilters"
        >
          <span v-if="isLoading">⏳ Calculando…</span>
          <span v-else>🔎 Aplicar filtros</span>
        </button>

        <button
          type="button"
          class="vg-btn vg-btn-secondary"
          data-testid="restocking-bi-export-csv"
          :disabled="isBusy"
          @click="exportCsv"
          title="Descarga autenticada del CSV de cinco secciones con UTF-8 BOM"
        >
          <span>📥 Exportar CSV</span>
        </button>
      </div>

      <p style="margin: 0; font-size: 12px; color: var(--color-ink-muted);">
        Ventana máxima de 366 días naturales · Zona horaria Europe/Madrid · Solo Coordinación
      </p>

      <div
        v-if="errorMessage"
        role="alert"
        data-testid="restocking-bi-error"
        style="padding: 12px 16px; background-color: var(--color-error-bg); color: var(--color-error-text); border-radius: var(--radius-card); font-size: 13px;"
      >
        {{ errorMessage }}
      </div>

      <div
        v-if="successMessage"
        data-testid="restocking-bi-success"
        style="padding: 12px 16px; background-color: var(--color-success-bg); color: var(--color-success-text); border-radius: var(--radius-card); font-size: 13px;"
      >
        {{ successMessage }}
      </div>

      <div
        v-if="isLoading"
        role="status"
        data-testid="restocking-bi-loading"
        style="padding: 12px 16px; background-color: var(--color-primary-subtle); color: var(--color-info-text); border-radius: var(--radius-card); font-size: 13px;"
      >
        Calculando disponibilidad, mermas y rendimiento de reposición…
      </div>

      <!-- Aviso térmico: la merma APPCC no se puede atribuir a un reponedor concreto (D2). -->
      <div
        v-if="thermalNotSegmentable"
        role="alert"
        data-testid="restocking-bi-thermal-notice"
        style="padding: 12px 16px; background-color: var(--color-warning-bg); color: var(--color-warning-text); border: 1px solid var(--color-warning-border); border-radius: var(--radius-card); font-size: 13px;"
      >
        <strong>⚠️ Merma térmica no segmentable:</strong> {{ thermalNoticeMessage }}
      </div>

      <!-- Tarjetas KPI -->
      <section
        data-testid="restocking-bi-summary-cards"
        aria-label="Indicadores de reposición y mermas"
        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;"
      >
        <article
          v-for="card in summaryCards"
          :key="card.key"
          :data-testid="'restocking-bi-kpi-' + card.key"
          style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 14px 16px; display: flex; flex-direction: column; gap: 4px; min-width: 0;"
        >
          <span style="font-size: 12px; font-weight: 600; color: var(--color-ink-muted);">{{ card.label }}</span>
          <strong style="font-family: var(--font-display); font-size: 24px; font-weight: 700; color: var(--color-slate); overflow-wrap: anywhere;">
            {{ card.value }}
          </strong>
          <span style="font-size: 11px; color: var(--color-ink-muted); overflow-wrap: anywhere;">{{ card.hint }}</span>
        </article>
      </section>

      <!-- Comparativa de mermas -->
      <section
        style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 20px;"
      >
        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px;">
          <div>
            <h3 style="margin: 0; font-family: var(--font-display); font-size: 18px; color: var(--color-slate);">Mermas comercial vs. térmica</h3>
            <p style="margin: 4px 0 0; font-size: 12px; color: var(--color-ink-muted);">
              Caducidad FEFO y dañado en ruta (M34) frente a las actas APPCC de avería térmica (M29).
            </p>
          </div>
          <div class="vg-action-group" style="gap: 6px;">
            <button
              type="button"
              class="vg-btn vg-btn-tab vg-btn-sm"
              :class="{ 'vg-btn-tab-active': spoilageMetric === 'eur' }"
              data-testid="restocking-bi-spoilage-eur"
              @click="spoilageMetric = 'eur'; renderCharts()"
            >
              €
            </button>
            <button
              type="button"
              class="vg-btn vg-btn-tab vg-btn-sm"
              :class="{ 'vg-btn-tab-active': spoilageMetric === 'units' }"
              data-testid="restocking-bi-spoilage-units"
              @click="spoilageMetric = 'units'; renderCharts()"
            >
              Unidades
            </button>
          </div>
        </div>

        <p
          v-if="chartUnavailable"
          role="alert"
          data-testid="restocking-bi-chart-unavailable"
          style="margin: 0 0 12px; padding: 10px 12px; background-color: var(--color-warning-bg); color: var(--color-warning-text); border-radius: var(--radius-interactive); font-size: 12px;"
        >
          No se pudo cargar el motor local de gráficos. Las tarjetas y las tablas siguen disponibles.
        </p>

        <div v-if="hasSpoilageSeries" data-testid="restocking-bi-spoilage-chart" style="position: relative; width: 100%; height: 260px;">
          <canvas ref="spoilageChartCanvas" role="img" aria-label="Comparativa de mermas comercial y térmica" style="width: 100%; height: 100%;"></canvas>
        </div>
        <p v-else data-testid="restocking-bi-spoilage-empty" style="margin: 0; font-size: 13px; color: var(--color-ink-muted);">
          Sin mermas registradas en el periodo filtrado.
        </p>
      </section>

      <!-- Top 10 críticos -->
      <section
        style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 20px;"
      >
        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px;">
          <div>
            <h3 style="margin: 0; font-family: var(--font-display); font-size: 18px; color: var(--color-slate);">Top 10 críticos</h3>
            <p style="margin: 4px 0 0; font-size: 12px; color: var(--color-ink-muted);">
              Ranking por tasa de rotura de carril o tasa de caducidad FEFO.
            </p>
          </div>
          <div class="vg-action-group" style="gap: 6px;">
            <button
              type="button"
              class="vg-btn vg-btn-tab vg-btn-sm"
              :class="{ 'vg-btn-tab-active': topMetric === 'stockout' }"
              data-testid="restocking-bi-top-stockout"
              @click="setTopMetric('stockout')"
            >
              Rotura
            </button>
            <button
              type="button"
              class="vg-btn vg-btn-tab vg-btn-sm"
              :class="{ 'vg-btn-tab-active': topMetric === 'expiry' }"
              data-testid="restocking-bi-top-expiry"
              @click="setTopMetric('expiry')"
            >
              Caducidad
            </button>
            <button
              type="button"
              class="vg-btn vg-btn-tab vg-btn-sm"
              :class="{ 'vg-btn-tab-active': topDimension === 'machine' }"
              data-testid="restocking-bi-top-machine"
              @click="setTopDimension('machine')"
            >
              Máquinas
            </button>
            <button
              type="button"
              class="vg-btn vg-btn-tab vg-btn-sm"
              :class="{ 'vg-btn-tab-active': topDimension === 'product' }"
              data-testid="restocking-bi-top-product"
              @click="setTopDimension('product')"
            >
              Productos
            </button>
          </div>
        </div>

        <div v-if="hasTopSeries" data-testid="restocking-bi-top-chart" style="position: relative; width: 100%; height: 320px;">
          <canvas ref="topChartCanvas" role="img" aria-label="Top 10 de máquinas o productos críticos" style="width: 100%; height: 100%;"></canvas>
        </div>
        <p v-else data-testid="restocking-bi-top-empty" style="margin: 0; font-size: 13px; color: var(--color-ink-muted);">
          Sin máquinas ni productos críticos con el criterio elegido.
        </p>
      </section>

      <!-- Tabla de máquinas -->
      <section
        style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 20px; min-width: 0;"
      >
        <h3 style="margin: 0 0 12px; font-family: var(--font-display); font-size: 18px; color: var(--color-slate);">
          Rendimiento y planograma por máquina
        </h3>

        <p v-if="machines.length === 0" data-testid="restocking-bi-machines-empty" style="margin: 0; font-size: 13px; color: var(--color-ink-muted);">
          Sin actividad de reposición en las máquinas del filtro.
        </p>

        <div v-else style="overflow-x: auto; max-width: 100%;">
          <table data-testid="restocking-bi-machines-table" style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <thead>
              <tr>
                <th style="text-align: left; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Máquina</th>
                <th style="text-align: left; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Sede · Zona</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Visitas</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Disponibilidad</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Rotura carril</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Bajo umbral</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Abortos térmicos</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Merma comercial</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Tasa caducidad</th>
                <th style="text-align: left; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Insignias</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in machines" :key="row.machine_id">
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); color: var(--color-slate);">
                  <strong>{{ row.machine_code }}</strong>
                  <span style="display: block; font-size: 11px; color: var(--color-ink-muted);">{{ row.machine_type }}</span>
                </td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); color: var(--color-slate);">
                  {{ machineLocationLabel(row) }}
                </td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatInteger(row.completed_visits_count) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatPercent(row.visit_in_stock_rate_pct) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatPercent(row.slot_stockout_rate_pct) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatInteger(row.low_threshold_slots_count) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ thermalAbortsLabel(row) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatCurrency(row.commercial_spoilage_loss_eur) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatPercent(row.expiry_rate_pct) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline);">
                  <span
                    v-if="row.is_active === false"
                    style="display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; border-radius: var(--radius-interactive); background-color: var(--color-neutral-bg); color: var(--color-slate); font-size: 11px; font-weight: 600;"
                  >Baja</span>
                  <span
                    v-if="row.is_oversized_planogram === true"
                    style="display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; border-radius: var(--radius-interactive); background-color: var(--color-warning-bg); color: var(--color-warning-text); font-size: 11px; font-weight: 600;"
                  >Planograma sobredimensionado</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Tabla de productos -->
      <section
        style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 20px; min-width: 0;"
      >
        <h3 style="margin: 0 0 12px; font-family: var(--font-display); font-size: 18px; color: var(--color-slate);">
          Rotación y roturas por producto / SKU
        </h3>

        <p v-if="products.length === 0" data-testid="restocking-bi-products-empty" style="margin: 0; font-size: 13px; color: var(--color-ink-muted);">
          Sin carriles auditados para los productos del filtro.
        </p>

        <div v-else style="overflow-x: auto; max-width: 100%;">
          <table data-testid="restocking-bi-products-table" style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <thead>
              <tr>
                <th style="text-align: left; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">SKU</th>
                <th style="text-align: left; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Producto</th>
                <th style="text-align: left; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Estado</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Carriles</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Stockout</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">% rotura</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Bajo umbral</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Merma comercial</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Tasa caducidad</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in products" :key="row.product_id">
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); color: var(--color-slate);">
                  <strong>{{ row.sku }}</strong>
                </td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); color: var(--color-slate);">{{ row.name }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline);">
                  <span
                    v-if="row.is_active === false"
                    style="display: inline-block; padding: 2px 8px; border-radius: var(--radius-interactive); background-color: var(--color-neutral-bg); color: var(--color-slate); font-size: 11px; font-weight: 600;"
                  >Baja</span>
                  <span v-else style="font-size: 11px; color: var(--color-ink-muted);">Activo</span>
                </td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatInteger(row.audited_slots_count) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatInteger(row.stockout_slots_count) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatPercent(row.slot_stockout_rate_pct) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatInteger(row.low_threshold_slots_count) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatCurrency(row.commercial_spoilage_loss_eur) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatPercent(row.expiry_rate_pct) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Tabla de reponedores -->
      <section
        style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 20px; min-width: 0;"
      >
        <h3 style="margin: 0 0 12px; font-family: var(--font-display); font-size: 18px; color: var(--color-slate);">
          Rendimiento operativo y arqueos por reponedor
        </h3>

        <p v-if="restockers.length === 0" data-testid="restocking-bi-restockers-empty" style="margin: 0; font-size: 13px; color: var(--color-ink-muted);">
          Sin actividad de reponedores en el periodo filtrado.
        </p>

        <div v-else style="overflow-x: auto; max-width: 100%;">
          <table data-testid="restocking-bi-restockers-table" style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <thead>
              <tr>
                <th style="text-align: left; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Reponedor</th>
                <th style="text-align: left; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Estado</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Visitas</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Turnos</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Máq./turno</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Parada media</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">L1 resueltas</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Escaladas SAT</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Arqueos OK / descuadre</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Exactitud</th>
                <th style="text-align: right; padding: 6px 8px; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Merma comercial</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in restockers" :key="row.restocker_id">
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); color: var(--color-slate);">
                  <strong>{{ row.operator_code || '—' }}</strong>
                  <span style="display: block; font-size: 11px; color: var(--color-ink-muted);">{{ row.name }}</span>
                </td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline);">
                  <span
                    v-if="row.is_active === false"
                    style="display: inline-block; padding: 2px 8px; border-radius: var(--radius-interactive); background-color: var(--color-neutral-bg); color: var(--color-slate); font-size: 11px; font-weight: 600;"
                  >Baja</span>
                  <span v-else style="font-size: 11px; color: var(--color-ink-muted);">Activo</span>
                </td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatInteger(row.completed_visits_count) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatInteger(row.valid_shifts_count) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ machinesPerShiftLabel(row) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ stopDurationLabel(row) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatInteger(row.l1_resolved_count) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatInteger(row.escalated_to_sat_count) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatInteger(row.reconciled_ok_count) }} / {{ formatInteger(row.discrepancy_flagged_count) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatPercent(row.cash_accuracy_rate_pct) }}</td>
                <td style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); text-align: right;">{{ formatCurrency(row.commercial_spoilage_loss_eur) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <p v-if="!hasRows && !isLoading" data-testid="restocking-bi-empty" style="margin: 0; font-size: 13px; color: var(--color-ink-muted);">
        Sin actividad de reposición en el periodo filtrado. Las tasas sin denominador se muestran como «—».
      </p>
    </div>
  `
};

export default CoordinatorRestockingBiTab;
