/**
 * VendGuard - CoordinatorMetricsView Component (CoordinatorMetricsView.js)
 * 
 * Vista integral del Cuadro de Mando de Métricas, KPIs y Auditoría para el Coordinador (RF-01, RF-02, RF-03, RF-05, RF-06).
 * 
 * Características:
 * 1. Selector de períodos temporales (7 días, 30 días, mes actual, mes anterior y rango personalizado).
 * 2. Carga paralela de KPIs globales y desglose multidimensional.
 * 3. Pestañas internas:
 *    - "📊 Cuadro de Mandos (KPIs)": Renderiza MetricCards.js.
 *    - "🗂️ Desglose Multidimensional": Renderiza MetricBreakdownTable.js.
 *    - "🛡️ Registro de Auditoría": Renderiza AuditLogViewer.js.
 * 4. Botón de acción: "📥 Exportar CSV" (con BOM UTF-8).
 * 5. Botón de acción: "📄 Generar Informe Ejecutivo" (despliega ExecutiveReportModal.js).
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias de npm).
 */

import { api } from '../api.js';
import { store } from '../store.js';
import { MetricCards } from '../components/MetricCards.js';
import { MetricBreakdownTable } from '../components/MetricBreakdownTable.js';
import { AuditLogViewer } from '../components/AuditLogViewer.js';
import { ExecutiveReportModal } from '../components/ExecutiveReportModal.js';

export const CoordinatorMetricsView = {
  name: 'CoordinatorMetricsView',
  components: {
    MetricCards,
    MetricBreakdownTable,
    AuditLogViewer,
    ExecutiveReportModal
  },
  data() {
    return {
      activeSubTab: 'kpis', // 'kpis' | 'breakdown' | 'audit'
      selectedPeriod: 'last_30_days', // 'last_7_days' | 'last_30_days' | 'current_month' | 'last_month' | 'custom'
      customFrom: '',
      customTo: '',
      summary: {},
      breakdown: {},
      /** Bloque CSAT del Módulo 20: misma carga y mismo filtro que el resto (RF-06.2). */
      csat: {},
      csatLoading: false,
      csatError: '',
      isLoading: false,
      errorMessage: '',
      showExecutiveModal: false
    };
  },
  computed: {
    currentUser() {
      return store.state.user || { name: 'Coordinador del Servicio', role: 'COORDINATOR' };
    },
    /** Resumen del periodo: media y número de respuestas (RF-06.1). */
    csatSummary() {
      return this.csat?.summary || {};
    },
    csatResponses() {
      return Number(this.csatSummary.responses) || 0;
    },
    /** Sin respuestas no se pinta un cero: se declara el periodo vacío (RF-06.3). */
    csatHasResponses() {
      return this.csatResponses > 0;
    },
    /** Media con dos decimales; sin respuestas no hay cifra que enseñar. */
    csatAverageLabel() {
      const average = this.csatSummary.average;
      if (average === null || average === undefined) return '—';
      const value = Number(average);
      return Number.isFinite(value) ? value.toFixed(2) : '—';
    },
    /** Distribución de cinco estrellas a cero, de 5 a 1 (R7). */
    csatDistribution() {
      const distribution = this.csatSummary.distribution || {};
      return [5, 4, 3, 2, 1].map((stars) => ({
        stars,
        responses: Number(distribution[String(stars)] ?? distribution[stars] ?? 0) || 0
      }));
    },
    /** Grupos tal como llegan: solo con respuestas (R7) y con el nombre congelado (R8). */
    csatBySite() {
      return Array.isArray(this.csat?.by_site) ? this.csat.by_site : [];
    },
    csatByTechnician() {
      return Array.isArray(this.csat?.by_technician) ? this.csat.by_technician : [];
    },
    csatMonthlyTrend() {
      return Array.isArray(this.csat?.monthly_trend) ? this.csat.monthly_trend : [];
    }
  },
  mounted() {
    this.loadMetrics();
  },
  methods: {
    async loadMetrics() {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        const params = {
          period: this.selectedPeriod
        };

        if (this.selectedPeriod === 'custom') {
          if (!this.customFrom || !this.customTo) {
            this.isLoading = false;
            return;
          }
          params.from = this.customFrom;
          params.to = this.customTo;
        }

        const csatRequest = this.loadCsat(params);

        const [resSummary, resBreakdown] = await Promise.all([
          api.metrics.getSummary(params),
          api.metrics.getBreakdown(params)
        ]);

        const summaryData = resSummary?.data !== undefined ? resSummary.data : resSummary;
        this.summary = summaryData || {};

        const breakdownData = resBreakdown?.data !== undefined ? resBreakdown.data : resBreakdown;
        this.breakdown = breakdownData || {};

        await csatRequest;
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar las métricas del servicio.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Carga el bloque CSAT con el filtro vigente (RF-06.2).
     *
     * Un fallo del bloque no puede tumbar el cuadro de mandos: se declara en su
     * propio estado y las métricas de SLA siguen visibles.
     */
    async loadCsat(params) {
      this.csatLoading = true;
      this.csatError = '';

      try {
        const data = await api.metrics.getCsat(params);
        this.csat = data || {};
      } catch (err) {
        this.csat = {};
        this.csatError = err.message || 'Error al cargar la satisfacción de las sedes.';
      } finally {
        this.csatLoading = false;
      }
    },
    handlePeriodChange() {
      if (this.selectedPeriod !== 'custom') {
        this.loadMetrics();
      }
    },
    applyCustomRange() {
      if (this.customFrom && this.customTo) {
        this.loadMetrics();
      }
    },
    /** Ancho de la barra de distribución respecto al total de respuestas. */
    csatBarWidth(responses) {
      const total = this.csatResponses;
      if (!total) return '0%';
      const share = Math.round(((Number(responses) || 0) / total) * 100);
      return `${share}%`;
    },

    /** Media de un grupo con dos decimales; un valor no numérico no inventa cifra. */
    formatCsatAverage(value) {
      const number = Number(value);
      return Number.isFinite(number) ? number.toFixed(2) : '—';
    },

    async exportMetricsCsv() {
      const params = {
        period: this.selectedPeriod
      };
      if (this.selectedPeriod === 'custom' && this.customFrom && this.customTo) {
        params.from = this.customFrom;
        params.to = this.customTo;
      }
      try {
        if (typeof api.metrics.downloadCsv === 'function') {
          await api.metrics.downloadCsv(params);
        } else {
          const url = api.metrics.exportCsvUrl(params);
          window.open(url, '_blank');
        }
      } catch (err) {
        const url = api.metrics.exportCsvUrl(params);
        window.open(url, '_blank');
      }
    }
  },
  template: `
    <div class="vg-coordinator-metrics-view" style="display: flex; flex-direction: column; gap: 20px;">
      
      <!-- Barra Superior de Control y Período -->
      <div style="background: #ffffff; border-radius: 8px; border: 1px solid #c8cfda; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 14px;">
        
        <!-- Selector de Período -->
        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 10px;">
          <label style="font-size: 13px; font-weight: 700; color: #2c333f; display: flex; align-items: center; gap: 6px;">
            <span>📅 Período:</span>
          </label>
          <select
            v-model="selectedPeriod"
            @change="handlePeriodChange"
            style="padding: 7px 12px; font-size: 13px; border: 1px solid #c8cfda; border-radius: 4px; background: #ffffff; font-weight: 600; color: #000000;"
          >
            <option value="last_7_days">Últimos 7 días</option>
            <option value="last_30_days">Últimos 30 días</option>
            <option value="current_month">Mes actual</option>
            <option value="last_month">Mes anterior</option>
            <option value="custom">Personalizado (Rango)</option>
          </select>

          <!-- Rango Personalizado -->
          <div v-if="selectedPeriod === 'custom'" style="display: inline-flex; align-items: center; gap: 6px;">
            <input
              type="date"
              v-model="customFrom"
              style="padding: 6px 10px; font-size: 12px; border: 1px solid #c8cfda; border-radius: 4px;"
            />
            <span style="font-size: 12px; color: #6c7e9d;">a</span>
            <input
              type="date"
              v-model="customTo"
              style="padding: 6px 10px; font-size: 12px; border: 1px solid #c8cfda; border-radius: 4px;"
            />
            <button
              type="button"
              class="vg-btn vg-btn-sm vg-btn-primary"
              @click="applyCustomRange"
            >
              Aplicar
            </button>
          </div>
        </div>

        <!-- Botones de Acción: Informe y Exportación CSV -->
        <div class="vg-action-group" style="gap: 10px;">
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            @click="exportMetricsCsv"
            title="Descargar archivo plano CSV con codificación UTF-8 BOM"
          >
            <span>📥 Exportar CSV</span>
          </button>

          <button
            type="button"
            class="vg-btn vg-btn-primary"
            @click="showExecutiveModal = true"
          >
            <span>📄 Generar Informe Ejecutivo</span>
          </button>
        </div>

      </div>

      <!-- Pestañas de Navegación de Métricas: envuelven en pantallas medianas -->
      <div class="vg-action-group" style="border-bottom: 2px solid #e5f2fc; padding-bottom: 2px;">
        <button
          type="button"
          class="vg-btn vg-btn-tab"
          :class="{ 'vg-btn-tab-active': activeSubTab === 'kpis' }"
          @click="activeSubTab = 'kpis'"
        >
          📊 Cuadro de Mandos (KPIs)
        </button>

        <button
          type="button"
          class="vg-btn vg-btn-tab"
          :class="{ 'vg-btn-tab-active': activeSubTab === 'breakdown' }"
          @click="activeSubTab = 'breakdown'"
        >
          🗂️ Desglose Multidimensional
        </button>

        <button
          type="button"
          class="vg-btn vg-btn-tab"
          :class="{ 'vg-btn-tab-active': activeSubTab === 'audit' }"
          @click="activeSubTab = 'audit'"
        >
          🛡️ Pistas de Auditoría
        </button>

        <button
          type="button"
          class="vg-btn vg-btn-tab"
          :class="{ 'vg-btn-tab-active': activeSubTab === 'csat' }"
          @click="activeSubTab = 'csat'"
        >
          ⭐ Satisfacción del Cliente (CSAT)
        </button>
      </div>

      <!-- Mensaje de Error si aplica -->
      <div v-if="errorMessage" style="padding: 16px 20px; background: #fee2e2; color: #b91c1c; border-radius: 8px; font-size: 13px;">
        {{ errorMessage }}
      </div>

      <!-- SUBPESTAÑA 1: KPIs y SLA -->
      <div v-if="activeSubTab === 'kpis'">
        <MetricCards :summary="summary" :is-loading="isLoading" />
      </div>

      <!-- SUBPESTAÑA 2: Desglose Multidimensional -->
      <div v-else-if="activeSubTab === 'breakdown'">
        <MetricBreakdownTable :breakdown="breakdown" :is-loading="isLoading" />
      </div>

      <!-- SUBPESTAÑA 3: Registro Inmutable de Auditoría -->
      <div v-else-if="activeSubTab === 'audit'">
        <AuditLogViewer />
      </div>

      <!-- SUBPESTAÑA 4: Satisfacción del Cliente (Módulo 20, RF-06.1–06.4) -->
      <div v-else-if="activeSubTab === 'csat'" data-testid="csat-block">
        <div class="vg-card">
          <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 18px; font-weight: 700; color: var(--color-ink); margin: 0 0 4px;">
            ⭐ Satisfacción del Cliente (CSAT)
          </h3>
          <p style="font-size: 12px; color: var(--color-ink-muted); margin: 0 0 16px;">
            Mismo periodo que el resto del cuadro de mandos.
          </p>

          <p v-if="csatLoading" data-testid="csat-loading" style="font-size: 13px; color: var(--color-ink-muted); margin: 0;">
            Cargando la satisfacción de las sedes…
          </p>

          <div v-else-if="csatError" data-testid="csat-error" role="alert" style="padding: 12px 16px; background-color: var(--color-error-bg); color: var(--color-error-text); border-radius: var(--radius-card); font-size: 13px;">
            {{ csatError }}
          </div>

          <p v-else-if="!csatHasResponses" data-testid="csat-empty" style="font-size: 13px; color: var(--color-ink-muted); margin: 0;">
            Sin valoraciones en el periodo
          </p>

          <div v-else data-testid="csat-ready">
            <!-- Resumen del periodo -->
            <div style="display: flex; flex-wrap: wrap; gap: 32px; margin-bottom: 20px;">
              <div>
                <div style="font-size: 12px; color: var(--color-ink-muted);">Media global</div>
                <div style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 32px; font-weight: 700; color: var(--color-primary);">
                  {{ csatAverageLabel }}<span style="font-size: 14px; color: var(--color-ink-muted); font-weight: 400;"> / 5</span>
                </div>
              </div>
              <div>
                <div style="font-size: 12px; color: var(--color-ink-muted);">Respuestas</div>
                <div style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 32px; font-weight: 700; color: var(--color-slate);">
                  {{ csatResponses }}
                </div>
              </div>
            </div>

            <!-- Distribución por estrellas -->
            <h4 style="font-size: 13px; font-weight: 700; color: var(--color-slate); margin: 0 0 8px;">Distribución por estrellas</h4>
            <div style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 24px;">
              <div
                v-for="row in csatDistribution"
                :key="row.stars"
                style="display: flex; align-items: center; gap: 10px;"
              >
                <span style="width: 48px; font-size: 12px; color: var(--color-slate);">{{ row.stars }} ★</span>
                <div style="flex: 1; height: 8px; background-color: var(--color-canvas); border-radius: var(--radius-interactive, 4px); overflow: hidden;">
                  <div :style="{ width: csatBarWidth(row.responses), height: '100%', backgroundColor: 'var(--color-primary)' }"></div>
                </div>
                <span style="width: 32px; text-align: right; font-size: 12px; color: var(--color-slate);">{{ row.responses }}</span>
              </div>
            </div>

            <!-- Tabla por sede -->
            <h4 style="font-size: 13px; font-weight: 700; color: var(--color-slate); margin: 0 0 8px;">Por sede</h4>
            <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 24px;">
              <thead>
                <tr>
                  <th style="text-align: left; font-size: 12px; color: var(--color-ink-muted); font-weight: 600; padding: 6px 4px; border-bottom: 1px solid var(--color-hairline);">Sede</th>
                  <th style="text-align: right; font-size: 12px; color: var(--color-ink-muted); font-weight: 600; padding: 6px 4px; border-bottom: 1px solid var(--color-hairline);">Media</th>
                  <th style="text-align: right; font-size: 12px; color: var(--color-ink-muted); font-weight: 600; padding: 6px 4px; border-bottom: 1px solid var(--color-hairline);">Respuestas</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in csatBySite" :key="row.location_id">
                  <td style="padding: 6px 4px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-slate);">
                    <strong>{{ row.site_code }}</strong> · {{ row.location_name }}
                  </td>
                  <td style="padding: 6px 4px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right;">{{ formatCsatAverage(row.average) }}</td>
                  <td style="padding: 6px 4px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right;">{{ row.responses }}</td>
                </tr>
              </tbody>
            </table>

            <!-- Tabla por técnico: nombre congelado en la encuesta (R8) -->
            <h4 style="font-size: 13px; font-weight: 700; color: var(--color-slate); margin: 0 0 8px;">Por técnico</h4>
            <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 24px;">
              <thead>
                <tr>
                  <th style="text-align: left; font-size: 12px; color: var(--color-ink-muted); font-weight: 600; padding: 6px 4px; border-bottom: 1px solid var(--color-hairline);">Técnico</th>
                  <th style="text-align: right; font-size: 12px; color: var(--color-ink-muted); font-weight: 600; padding: 6px 4px; border-bottom: 1px solid var(--color-hairline);">Media</th>
                  <th style="text-align: right; font-size: 12px; color: var(--color-ink-muted); font-weight: 600; padding: 6px 4px; border-bottom: 1px solid var(--color-hairline);">Respuestas</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in csatByTechnician" :key="row.technician_name">
                  <td style="padding: 6px 4px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-slate);">{{ row.technician_name }}</td>
                  <td style="padding: 6px 4px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right;">{{ formatCsatAverage(row.average) }}</td>
                  <td style="padding: 6px 4px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right;">{{ row.responses }}</td>
                </tr>
              </tbody>
            </table>

            <!-- Serie mensual -->
            <h4 style="font-size: 13px; font-weight: 700; color: var(--color-slate); margin: 0 0 8px;">Tendencia mensual</h4>
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
              <thead>
                <tr>
                  <th style="text-align: left; font-size: 12px; color: var(--color-ink-muted); font-weight: 600; padding: 6px 4px; border-bottom: 1px solid var(--color-hairline);">Mes</th>
                  <th style="text-align: right; font-size: 12px; color: var(--color-ink-muted); font-weight: 600; padding: 6px 4px; border-bottom: 1px solid var(--color-hairline);">Media</th>
                  <th style="text-align: right; font-size: 12px; color: var(--color-ink-muted); font-weight: 600; padding: 6px 4px; border-bottom: 1px solid var(--color-hairline);">Respuestas</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in csatMonthlyTrend" :key="row.month">
                  <td style="padding: 6px 4px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-slate);">{{ row.month }}</td>
                  <td style="padding: 6px 4px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right;">{{ formatCsatAverage(row.average) }}</td>
                  <td style="padding: 6px 4px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right;">{{ row.responses }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Modal de Informe Resumen Ejecutivo Imprimible (RF-06, EARS 6.3) -->
      <ExecutiveReportModal
        :show="showExecutiveModal"
        :summary="summary"
        :breakdown="breakdown"
        :current-user="currentUser"
        @close="showExecutiveModal = false"
      />

    </div>
  `
};

export default CoordinatorMetricsView;
