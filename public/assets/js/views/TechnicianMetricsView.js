/**
 * VendGuard - TechnicianMetricsView Component (TechnicianMetricsView.js)
 * 
 * Panel Móvil de Rendimiento Individual para el Técnico de Ruta (RF-04, EARS 4.1, 4.3).
 * Da cumplimiento estricto al principio constitucional de Mínimo Privilegio (Art. V.4):
 * - Visualización exclusiva de las métricas propias del técnico autenticado.
 * - Sin acceso a datos globales ni comparativas con otros técnicos.
 * 
 * Características:
 * 1. MTTR promedio personal en el período seleccionado.
 * 2. Total de incidencias resueltas por el técnico.
 * 3. Incidencias actualmente en curso asignadas.
 * 4. Tiempo promedio de primera respuesta técnica (minutos desde reporte a inicio).
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias de npm).
 */

import { api } from '../api.js';
import { store } from '../store.js';

export const TechnicianMetricsView = {
  name: 'TechnicianMetricsView',
  data() {
    return {
      selectedPeriod: 'last_30_days',
      metricsData: null,
      csatData: null,
      csatError: '',
      isLoading: false,
      errorMessage: ''
    };
  },
  computed: {
    currentUser() {
      return store.state.user || { name: 'Técnico de Ruta', role: 'TECHNICIAN' };
    },
    metrics() {
      return this.metricsData?.metrics || {};
    },
    period() {
      return this.metricsData?.period || {};
    },
    /**
     * Media CSAT propia ya formateada, o `null` cuando no se puede publicar:
     * sin respuestas o por debajo del umbral de muestra (RF-20.1.3, RF-20.1.4).
     */
    csatAverage() {
      const average = this.csatData?.summary?.average;
      if (!this.csatData?.threshold_met || average === null || average === undefined) {
        return null;
      }

      return Number(average).toFixed(2);
    },
    /** Distribución 1–5 lista para pintar, de cinco estrellas a una. */
    csatDistribution() {
      const distribution = this.csatData?.summary?.distribution || {};
      const total = Object.values(distribution).reduce((sum, count) => sum + Number(count || 0), 0);

      return [5, 4, 3, 2, 1].map((stars) => {
        const count = Number(distribution[String(stars)] ?? distribution[stars] ?? 0);

        return { stars, count, percent: total > 0 ? Math.round((count / total) * 100) : 0 };
      });
    },
    csatMonthlyTrend() {
      return Array.isArray(this.csatData?.monthly_trend) ? this.csatData.monthly_trend : [];
    },
    /** Sin ninguna valoración en el periodo: el mensaje es de vacío, nunca un 0. */
    csatEmpty() {
      return Number(this.csatData?.summary?.responses || 0) === 0;
    }
  },
  mounted() {
    this.reload();
  },
  methods: {
    reload() {
      this.loadMyMetrics();
      this.loadMyCsat();
    },
    async loadMyCsat() {
      this.csatError = '';

      try {
        const res = await api.metrics.getMyCsat({ period: this.selectedPeriod });
        const data = res?.data !== undefined ? res.data : res;
        this.csatData = data && typeof data === 'object' ? data : null;
      } catch (err) {
        // Dominio de fallo propio: la tarjeta CSAT cae sola, el resto del panel vive.
        this.csatData = null;
        this.csatError = err.message || 'No se pudieron consultar tus valoraciones.';
      }
    },
    async loadMyMetrics() {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        const res = await api.metrics.getMyMetrics({ period: this.selectedPeriod });
        const data = res?.data !== undefined ? res.data : res;
        if (data && (data.metrics || data.technician)) {
          this.metricsData = data;
        } else {
          this.metricsData = null;
        }
      } catch (err) {
        this.errorMessage = err.message || 'Error al consultar tus métricas personales.';
      } finally {
        this.isLoading = false;
      }
    }
  },
  template: `
    <div class="vg-technician-metrics-view" style="display: flex; flex-direction: column; gap: 14px;">
      
      <!-- Cabecera de Sección y Selector de Período -->
      <div style="background: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
          <div>
            <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #000000; display: flex; align-items: center; gap: 6px;">
              <span>📈 Mis Métricas de Rendimiento</span>
            </h3>
            <span style="font-size: 11px; color: #6c7e9d;">Autoconsulta individual protegida (Art. V.4)</span>
          </div>
          <button
            type="button"
            @click="reload"
            :disabled="isLoading"
            style="padding: 6px 10px; font-size: 12px; background: #e5f2fc; color: #2560ff; border: 1px solid #bfdbfe; border-radius: 4px; font-weight: 600; cursor: pointer;"
          >
            <span :style="{ display: 'inline-block', transform: isLoading ? 'rotate(180deg)' : 'none', transition: 'transform 0.3s' }">🔄</span>
          </button>
        </div>

        <!-- Selector de Período -->
        <div style="display: flex; gap: 6px;">
          <button
            type="button"
            @click="selectedPeriod = 'last_7_days'; reload();"
            :style="{ flex: 1, padding: '6px 8px', fontSize: '11px', fontWeight: '600', borderRadius: '4px', border: '1px solid #c8cfda', backgroundColor: selectedPeriod === 'last_7_days' ? '#2560ff' : '#ffffff', color: selectedPeriod === 'last_7_days' ? '#ffffff' : '#434c5f', cursor: 'pointer' }"
          >
            7 Días
          </button>
          <button
            type="button"
            @click="selectedPeriod = 'last_30_days'; reload();"
            :style="{ flex: 1, padding: '6px 8px', fontSize: '11px', fontWeight: '600', borderRadius: '4px', border: '1px solid #c8cfda', backgroundColor: selectedPeriod === 'last_30_days' ? '#2560ff' : '#ffffff', color: selectedPeriod === 'last_30_days' ? '#ffffff' : '#434c5f', cursor: 'pointer' }"
          >
            30 Días
          </button>
          <button
            type="button"
            @click="selectedPeriod = 'current_month'; reload();"
            :style="{ flex: 1, padding: '6px 8px', fontSize: '11px', fontWeight: '600', borderRadius: '4px', border: '1px solid #c8cfda', backgroundColor: selectedPeriod === 'current_month' ? '#2560ff' : '#ffffff', color: selectedPeriod === 'current_month' ? '#ffffff' : '#434c5f', cursor: 'pointer' }"
          >
            Mes Actual
          </button>
        </div>
      </div>

      <!-- Mensaje de Error -->
      <div v-if="errorMessage" style="background: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 12px; border-radius: 6px; font-size: 13px;">
        {{ errorMessage }}
      </div>

      <!-- Estado de Carga -->
      <div v-if="isLoading" style="text-align: center; padding: 30px; color: #2560ff; font-weight: 600;">
        Calculando tus métricas individuales...
      </div>

      <!-- Cuadrícula de Métricas Individuales Móvil -->
      <div v-else-if="metricsData" style="display: flex; flex-direction: column; gap: 12px;">
        
        <!-- 1. Mi MTTR Individual -->
        <div style="background: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: 8px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <span style="font-size: 12px; font-weight: 700; color: #434c5f; text-transform: uppercase;">Mi MTTR Promedio</span>
            <span style="font-size: 18px;">⏱️</span>
          </div>
          <div style="font-family: 'DM Sans', sans-serif; font-size: 26px; font-weight: 700; color: #2560ff; line-height: 1.1;">
            {{ metrics.my_mttr_formatted || 'N/A' }}
          </div>
          <div style="font-size: 11px; color: #6c7e9d; margin-top: 4px;">
            {{ metrics.my_mttr_hours !== null && metrics.my_mttr_hours !== undefined ? metrics.my_mttr_hours + ' horas por avería' : 'Sin tiempo computable' }}
          </div>
        </div>

        <!-- 2. Averías Resueltas y En Curso (Grid 2 columnas) -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
          
          <div style="background: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: 8px; padding: 14px;">
            <div style="font-size: 11px; font-weight: 700; color: #434c5f; text-transform: uppercase; margin-bottom: 4px;">
              Resueltas
            </div>
            <div style="font-family: 'DM Sans', sans-serif; font-size: 24px; font-weight: 700; color: #16a34a;">
              {{ metrics.total_resolved_tickets || 0 }}
            </div>
            <div style="font-size: 11px; color: #6c7e9d; margin-top: 2px;">
              En el período
            </div>
          </div>

          <div style="background: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: 8px; padding: 14px;">
            <div style="font-size: 11px; font-weight: 700; color: #434c5f; text-transform: uppercase; margin-bottom: 4px;">
              En Curso
            </div>
            <div style="font-family: 'DM Sans', sans-serif; font-size: 24px; font-weight: 700; color: #c2410c;">
              {{ metrics.current_in_progress_tickets || 0 }}
            </div>
            <div style="font-size: 11px; color: #6c7e9d; margin-top: 2px;">
              Actualmente
            </div>
          </div>

        </div>

        <!-- 3. Tiempo Medio de Primera Respuesta -->
        <div style="background: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: 8px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <span style="font-size: 12px; font-weight: 700; color: #434c5f; text-transform: uppercase;">Primera Respuesta Media</span>
            <span style="font-size: 18px;">🚀</span>
          </div>
          <div style="font-family: 'DM Sans', sans-serif; font-size: 24px; font-weight: 700; color: #000000; line-height: 1.1;">
            {{ metrics.avg_first_response_formatted || 'N/A' }}
          </div>
          <div style="font-size: 11px; color: #6c7e9d; margin-top: 4px;">
            Tiempo medio desde el reporte ciudadano hasta tu inicio de intervención
          </div>
        </div>

        <!-- 4. Mi Valoración CSAT propia (incremento 20.1, RF-20.1.1) -->
        <div
          v-if="csatData || csatError"
          style="background: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: 8px; padding: 16px;"
        >
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <span style="font-size: 12px; font-weight: 700; color: var(--color-ink-secondary); text-transform: uppercase;">Mi Valoración CSAT</span>
            <span style="font-size: 18px;">⭐</span>
          </div>

          <div v-if="csatError" style="font-size: 12px; color: var(--color-error-text); background: var(--color-error-bg); border-radius: 4px; padding: 8px;">
            {{ csatError }}
          </div>

          <div v-else-if="csatEmpty" style="font-size: 12px; color: var(--color-ink-muted);">
            Aún no hay valoraciones en el periodo
          </div>

          <div v-else-if="!csatData.threshold_met" style="font-size: 12px; color: var(--color-ink-muted);">
            Aún no hay valoraciones suficientes para mostrar una media
          </div>

          <template v-else>
            <div style="display: flex; align-items: baseline; gap: 8px;">
              <span style="font-family: 'DM Sans', sans-serif; font-size: 26px; font-weight: 700; color: var(--color-primary); line-height: 1.1;">{{ csatAverage }}</span>
              <span style="font-size: 12px; color: var(--color-ink-muted);">/ 5 · {{ csatData.summary.responses }} valoraciones</span>
            </div>

            <div style="display: flex; flex-direction: column; gap: 4px; margin-top: 10px;">
              <div
                v-for="row in csatDistribution"
                :key="row.stars"
                style="display: flex; align-items: center; gap: 8px; font-size: 11px; color: var(--color-ink-muted);"
              >
                <span style="width: 26px;">{{ row.stars }}★</span>
                <span style="flex: 1; height: 6px; background: var(--color-surface-1); border-radius: 3px; overflow: hidden;">
                  <span :style="{ display: 'block', height: '100%', width: row.percent + '%', background: 'var(--color-warning)' }"></span>
                </span>
                <span style="width: 28px; text-align: right;">{{ row.count }}</span>
              </div>
            </div>

            <div v-if="csatMonthlyTrend.length" style="margin-top: 10px; font-size: 11px; color: var(--color-ink-muted);">
              <span v-for="month in csatMonthlyTrend" :key="month.month" style="margin-right: 8px;">
                {{ month.month }}: {{ month.average }} ({{ month.responses }})
              </span>
            </div>
          </template>
        </div>

        <!-- Garantía Constitucional de Privacidad (Art. V.4) -->
        <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 10px; font-size: 11px; color: #64748b; line-height: 1.4; display: flex; align-items: flex-start; gap: 6px;">
          <span>🔒</span>
          <span>
            <strong>Privacidad garantizada (Art. V.4):</strong> Estas métricas son estrictamente confidenciales y personales. Ningún otro técnico puede visualizar tu rendimiento individual.
          </span>
        </div>

      </div>

    </div>
  `
};

export default TechnicianMetricsView;
