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
      isLoading: false,
      errorMessage: '',
      showExecutiveModal: false
    };
  },
  computed: {
    currentUser() {
      return store.state.user || { name: 'Coordinador del Servicio', role: 'COORDINATOR' };
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

        const [resSummary, resBreakdown] = await Promise.all([
          api.metrics.getSummary(params),
          api.metrics.getBreakdown(params)
        ]);

        const summaryData = resSummary?.data !== undefined ? resSummary.data : resSummary;
        this.summary = summaryData || {};

        const breakdownData = resBreakdown?.data !== undefined ? resBreakdown.data : resBreakdown;
        this.breakdown = breakdownData || {};
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar las métricas del servicio.';
      } finally {
        this.isLoading = false;
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
    exportMetricsCsv() {
      const params = {
        period: this.selectedPeriod
      };
      if (this.selectedPeriod === 'custom' && this.customFrom && this.customTo) {
        params.from = this.customFrom;
        params.to = this.customTo;
      }
      const url = api.metrics.exportCsvUrl(params);
      window.open(url, '_blank');
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
              @click="applyCustomRange"
              style="padding: 6px 12px; background: #2560ff; color: #ffffff; border: none; border-radius: 4px; font-size: 12px; font-weight: 600; cursor: pointer;"
            >
              Aplicar
            </button>
          </div>
        </div>

        <!-- Botones de Acción: Informe y Exportación CSV -->
        <div style="display: flex; gap: 10px; align-items: center;">
          <button
            type="button"
            @click="exportMetricsCsv"
            style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: #ffffff; border: 1px solid #c8cfda; border-radius: 4px; font-size: 13px; font-weight: 600; color: #2c333f; cursor: pointer; transition: all 0.2s ease;"
            title="Descargar archivo plano CSV con codificación UTF-8 BOM"
          >
            <span>📥 Exportar CSV</span>
          </button>

          <button
            type="button"
            @click="showExecutiveModal = true"
            style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #2560ff; color: #ffffff; border: none; border-radius: 4px; font-size: 13px; font-weight: 700; cursor: pointer; transition: background 0.2s ease;"
          >
            <span>📄 Generar Informe Ejecutivo</span>
          </button>
        </div>

      </div>

      <!-- Pestañas de Navegación de Métricas -->
      <div style="display: flex; gap: 8px; border-bottom: 2px solid #e5f2fc; padding-bottom: 2px;">
        <button
          type="button"
          @click="activeSubTab = 'kpis'"
          :style="{ padding: '8px 16px', fontSize: '14px', fontWeight: '700', border: 'none', background: 'transparent', cursor: 'pointer', borderBottom: activeSubTab === 'kpis' ? '3px solid #2560ff' : '3px solid transparent', color: activeSubTab === 'kpis' ? '#2560ff' : '#6c7e9d' }"
        >
          📊 Cuadro de Mandos (KPIs)
        </button>

        <button
          type="button"
          @click="activeSubTab = 'breakdown'"
          :style="{ padding: '8px 16px', fontSize: '14px', fontWeight: '700', border: 'none', background: 'transparent', cursor: 'pointer', borderBottom: activeSubTab === 'breakdown' ? '3px solid #2560ff' : '3px solid transparent', color: activeSubTab === 'breakdown' ? '#2560ff' : '#6c7e9d' }"
        >
          🗂️ Desglose Multidimensional
        </button>

        <button
          type="button"
          @click="activeSubTab = 'audit'"
          :style="{ padding: '8px 16px', fontSize: '14px', fontWeight: '700', border: 'none', background: 'transparent', cursor: 'pointer', borderBottom: activeSubTab === 'audit' ? '3px solid #2560ff' : '3px solid transparent', color: activeSubTab === 'audit' ? '#2560ff' : '#6c7e9d' }"
        >
          🛡️ Pistas de Auditoría
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
