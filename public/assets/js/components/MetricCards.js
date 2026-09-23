/**
 * VendGuard - MetricCards Component (MetricCards.js)
 * 
 * Cuadro de mando reactivo con tarjetas ejecutivas de KPIs y alertas de SLA (RF-03, EARS 3.1, 3.2).
 * Da estricto cumplimiento al Artículo II constitucional (Seguridad Alimentaria en Alimentos Perecederos).
 * 
 * Características:
 * 1. MTTR Promedio Global con indicador de tendencia porcentual (verde si disminuye, rojo si sube).
 * 2. Tarjeta prioritaria de Alimentos Perecederos (Sanitario) con SLA fijo de 4 horas (Art. II).
 * 3. Tasa de Resolución porcentual y volumen total creado vs resuelto.
 * 4. Backlog activo en curso y alertas de desviaciones críticas de SLA.
 * 5. Cumplimiento general del parque (SLA objetivo 24h).
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias de npm).
 */

export const MetricCards = {
  name: 'MetricCards',
  props: {
    summary: {
      type: Object,
      default: () => ({})
    },
    isLoading: {
      type: Boolean,
      default: false
    }
  },
  computed: {
    kpis() {
      return this.summary?.kpis || {};
    },
    slaAlerts() {
      return this.summary?.sla_alerts || {};
    },
    perishableAlert() {
      return this.slaAlerts?.perishable_food || {};
    },
    generalAlert() {
      return this.slaAlerts?.general || {};
    },
    trend() {
      const p = this.kpis.mttr_trend_percentage;
      if (p === null || p === undefined) {
        return {
          text: 'Sin periodo previo',
          class: 'vg-trend-neutral',
          icon: '—',
          color: '#6c7e9d'
        };
      }
      if (p < 0) {
        return {
          text: `${Math.abs(p)}% vs anterior (Mejora)`,
          class: 'vg-trend-positive',
          icon: '↓',
          color: '#16a34a'
        };
      }
      if (p > 0) {
        return {
          text: `+${p}% vs anterior (Aumento)`,
          class: 'vg-trend-negative',
          icon: '↑',
          color: '#dc2626'
        };
      }
      return {
        text: '0.0% vs anterior (Estable)',
        class: 'vg-trend-neutral',
        icon: '=',
        color: '#6c7e9d'
      };
    },
    perishableStatusBadge() {
      const status = this.perishableAlert.status || 'NO_DATA';
      const hours = this.perishableAlert.current_mttr_hours;

      if (status === 'BREACHED') {
        return {
          label: `Incumplimiento SLA (${hours !== null ? hours + 'h' : '> 4h'})`,
          class: 'vg-badge-critical',
          bg: '#fee2e2',
          color: '#b91c1c',
          border: '#fca5a5'
        };
      }
      if (status === 'COMPLIANT') {
        return {
          label: `Cumple SLA (${hours !== null ? hours + 'h' : '≤ 4h'})`,
          class: 'vg-badge-success',
          bg: '#dcfce7',
          color: '#15803d',
          border: '#86efac'
        };
      }
      return {
        label: 'Sin averías resueltas',
        class: 'vg-badge-neutral',
        bg: '#f1f5f9',
        color: '#64748b',
        border: '#cbd5e1'
      };
    },
    generalStatusBadge() {
      const status = this.generalAlert.status || 'NO_DATA';
      const hours = this.generalAlert.current_mttr_hours;

      if (status === 'BREACHED') {
        return {
          label: `Incumplimiento SLA (${hours !== null ? hours + 'h' : '> 24h'})`,
          class: 'vg-badge-critical',
          bg: '#fee2e2',
          color: '#b91c1c',
          border: '#fca5a5'
        };
      }
      if (status === 'COMPLIANT') {
        return {
          label: `Cumple SLA (${hours !== null ? hours + 'h' : '≤ 24h'})`,
          class: 'vg-badge-success',
          bg: '#dcfce7',
          color: '#15803d',
          border: '#86efac'
        };
      }
      return {
        label: 'Sin datos',
        class: 'vg-badge-neutral',
        bg: '#f1f5f9',
        color: '#64748b',
        border: '#cbd5e1'
      };
    }
  },
  template: `
    <div class="vg-metric-cards-container" style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 24px;">
      
      <!-- Estado de Carga -->
      <div v-if="isLoading" style="padding: 24px; text-align: center; background: #ffffff; border-radius: 8px; border: 1px solid #c8cfda;">
        <span style="color: #2560ff; font-weight: 600;">Cargando indicadores clave (KPIs)...</span>
      </div>

      <!-- Cuadrícula de Tarjetas de Rendimiento -->
      <div v-else class="vg-kpi-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px;">
        
        <!-- 1. MTTR Global Promedio -->
        <div class="vg-card vg-kpi-card" style="background: #ffffff; border-radius: 8px; border: 1px solid #c8cfda; padding: 20px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
          <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <span style="font-size: 13px; font-weight: 600; color: #434c5f; text-transform: uppercase; letter-spacing: 0.5px;">MTTR Global</span>
              <span style="font-size: 18px;" title="Tiempo Medio de Reparación">⏱️</span>
            </div>
            <div style="font-family: 'DM Sans', sans-serif; font-size: 28px; font-weight: 700; color: #000000; line-height: 1.1;">
              {{ kpis.mttr_global_formatted || 'N/A' }}
            </div>
            <div style="font-size: 12px; color: #6c7e9d; margin-top: 4px;">
              {{ kpis.mttr_global_hours !== null && kpis.mttr_global_hours !== undefined ? kpis.mttr_global_hours + ' horas' : 'Sin tiempo computable' }}
            </div>
          </div>
          <div style="margin-top: 14px; padding-top: 10px; border-top: 1px solid #efefef; display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600;" :style="{ color: trend.color }">
            <span>{{ trend.icon }}</span>
            <span>{{ trend.text }}</span>
          </div>
        </div>

        <!-- 2. Alimentos Perecederos (Art. II Constitución) - Tarjeta Prioritaria Sanitaria -->
        <div class="vg-card vg-kpi-card vg-card-perishable" style="background: #fffbf0; border-radius: 8px; border: 2px solid #f8b60f; padding: 20px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 2px 4px rgba(248, 182, 15, 0.1);">
          <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <span style="font-size: 13px; font-weight: 700; color: #92400e; text-transform: uppercase; letter-spacing: 0.5px;">Perecederos (Art. II)</span>
              <span style="font-size: 18px;" title="Prioridad Sanitaria">🥪</span>
            </div>
            <div style="font-family: 'DM Sans', sans-serif; font-size: 28px; font-weight: 700; color: #92400e; line-height: 1.1;">
              {{ perishableAlert.current_mttr_hours !== null && perishableAlert.current_mttr_hours !== undefined ? perishableAlert.current_mttr_hours + 'h' : 'N/A' }}
            </div>
            <div style="font-size: 12px; color: #78350f; margin-top: 4px;">
              SLA Objetivo Fijo: <strong>4.0 horas</strong>
            </div>
          </div>
          <div style="margin-top: 14px; padding-top: 10px; border-top: 1px solid #fef3c7;">
            <span :class="perishableStatusBadge.class" :style="{ display: 'inline-block', padding: '3px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: '700', backgroundColor: perishableStatusBadge.bg, color: perishableStatusBadge.color, border: '1px solid ' + perishableStatusBadge.border }">
              {{ perishableStatusBadge.label }}
            </span>
          </div>
        </div>

        <!-- 3. Tasa de Resolución de Incidencias -->
        <div class="vg-card vg-kpi-card" style="background: #ffffff; border-radius: 8px; border: 1px solid #c8cfda; padding: 20px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
          <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <span style="font-size: 13px; font-weight: 600; color: #434c5f; text-transform: uppercase; letter-spacing: 0.5px;">Tasa de Resolución</span>
              <span style="font-size: 18px;" title="Efectividad del servicio">📊</span>
            </div>
            <div style="font-family: 'DM Sans', sans-serif; font-size: 28px; font-weight: 700; color: #000000; line-height: 1.1;">
              {{ kpis.resolution_rate_percentage !== undefined ? kpis.resolution_rate_percentage + '%' : '0%' }}
            </div>
            <div style="font-size: 12px; color: #6c7e9d; margin-top: 4px;">
              {{ kpis.total_tickets_resolved || 0 }} resueltos de {{ kpis.total_tickets_created || 0 }} creados
            </div>
          </div>
          <div style="margin-top: 14px; padding-top: 10px; border-top: 1px solid #efefef;">
            <!-- Barra de Progreso Visual -->
            <div style="width: 100%; height: 6px; background: #e5f2fc; border-radius: 3px; overflow: hidden;">
              <div :style="{ width: Math.min(100, Math.max(0, kpis.resolution_rate_percentage || 0)) + '%', height: '100%', backgroundColor: (kpis.resolution_rate_percentage || 0) >= 80 ? '#16a34a' : '#f8b60f', transition: 'width 0.3s ease' }"></div>
            </div>
          </div>
        </div>

        <!-- 4. Backlog Activo y Desviaciones Críticas -->
        <div class="vg-card vg-kpi-card" style="background: #ffffff; border-radius: 8px; border: 1px solid #c8cfda; padding: 20px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
          <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <span style="font-size: 13px; font-weight: 600; color: #434c5f; text-transform: uppercase; letter-spacing: 0.5px;">Backlog Activo</span>
              <span style="font-size: 18px;" title="Incidencias abiertas">📂</span>
            </div>
            <div style="font-family: 'DM Sans', sans-serif; font-size: 28px; font-weight: 700; color: #000000; line-height: 1.1;">
              {{ kpis.active_backlog !== undefined ? kpis.active_backlog : 0 }}
            </div>
            <div style="font-size: 12px; color: #6c7e9d; margin-top: 4px;">
              Incidencias abiertas o en curso
            </div>
          </div>
          <div style="margin-top: 14px; padding-top: 10px; border-top: 1px solid #efefef; display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 12px; font-weight: 600;" :style="{ color: (kpis.critical_sla_breaches || 0) > 0 ? '#dc2626' : '#6c7e9d' }">
              🚨 {{ kpis.critical_sla_breaches || 0 }} fuera de SLA
            </span>
          </div>
        </div>

        <!-- 5. SLA Parque General -->
        <div class="vg-card vg-kpi-card" style="background: #ffffff; border-radius: 8px; border: 1px solid #c8cfda; padding: 20px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
          <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <span style="font-size: 13px; font-weight: 600; color: #434c5f; text-transform: uppercase; letter-spacing: 0.5px;">SLA General (24h)</span>
              <span style="font-size: 18px;" title="Parque estándar">☕</span>
            </div>
            <div style="font-family: 'DM Sans', sans-serif; font-size: 28px; font-weight: 700; color: #000000; line-height: 1.1;">
              {{ generalAlert.current_mttr_hours !== null && generalAlert.current_mttr_hours !== undefined ? generalAlert.current_mttr_hours + 'h' : 'N/A' }}
            </div>
            <div style="font-size: 12px; color: #6c7e9d; margin-top: 4px;">
              SLA Objetivo: <strong>24.0 horas</strong>
            </div>
          </div>
          <div style="margin-top: 14px; padding-top: 10px; border-top: 1px solid #efefef;">
            <span :class="generalStatusBadge.class" :style="{ display: 'inline-block', padding: '3px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: '700', backgroundColor: generalStatusBadge.bg, color: generalStatusBadge.color, border: '1px solid ' + generalStatusBadge.border }">
              {{ generalStatusBadge.label }}
            </span>
          </div>
        </div>

      </div>
    </div>
  `
};

export default MetricCards;
