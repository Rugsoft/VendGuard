/**
 * VendGuard - CoordinatorPreventiveDashboard (CoordinatorPreventiveDashboard.js)
 * 
 * Componente Vue 3 ESM para el Panel de Mando de Mantenimiento Preventivo y Checklists Sanitarios (M1).
 * Requisitos: RF-PREV-02, RF-PREV-06, Constitución Art. II.
 * 
 * Características:
 * 1. KPIs de semáforos de parque (Verde/Vigente, Amarillo/Próxima, Rojo/Vencida, Cuarentena, Pausa).
 * 2. Tasa de cumplimiento normativo global en porcentaje.
 * 3. Banner prioritario de Alerta Sanitaria ante máquinas en Cuarentena con tickets correctivos vinculados.
 * 4. Botón "⚡ Generar Preventivos Inminentes" (ventana anticipada de 5 días) con feedback en tiempo real.
 * 5. Navegación directa hacia el listado de órdenes filtrado.
 * 
 * Dogma Vanilla: Cero dependencias externas (Vue 3 ESM nativo).
 * Dualismo Lingüístico: Código en inglés, interfaz y mensajes en español.
 */

import { api } from '../api.js';

export const CoordinatorPreventiveDashboard = {
  name: 'CoordinatorPreventiveDashboard',
  emits: ['view-orders', 'open-settings', 'refresh'],
  data() {
    return {
      summary: {
        total_machines: 0,
        status_green: 0,
        status_yellow: 0,
        status_red: 0,
        status_quarantine: 0,
        status_seasonal_pause: 0,
        compliance_rate_percent: 100.0
      },
      urgentActions: {
        quarantine_machines: [],
        expired_orders_count: 0,
        due_soon_orders_count: 0
      },
      isLoading: false,
      isGenerating: false,
      errorMessage: '',
      successMessage: '',
      horizonDays: 5
    };
  },
  computed: {
    hasQuarantines() {
      return (this.summary.status_quarantine > 0) || 
             (this.urgentActions.quarantine_machines && this.urgentActions.quarantine_machines.length > 0);
    },
    hasUrgentAlerts() {
      return this.hasQuarantines || (this.urgentActions.expired_orders_count > 0);
    }
  },
  mounted() {
    this.loadDashboard();
  },
  methods: {
    /**
     * Carga el resumen de KPIs y acciones urgentes desde la API
     */
    async loadDashboard() {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        const res = await api.coordinator.getPreventiveDashboard();
        const data = res?.data || res || {};

        if (data.summary) {
          this.summary = { ...this.summary, ...data.summary };
        }
        if (data.urgent_actions) {
          this.urgentActions = { ...this.urgentActions, ...data.urgent_actions };
        }
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar los indicadores de mantenimiento preventivo.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Dispara la generación automática anticipada de órdenes preventivas (RF-PREV-02, EARS 2.1)
     */
    async handleGenerateDue() {
      this.isGenerating = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        const res = await api.coordinator.generateDuePreventiveOrders(this.horizonDays);
        const count = res?.data?.orders_generated_count ?? res?.orders_generated_count ?? 0;
        const msg = res?.message || `Se han generado ${count} órdenes de inspección preventiva para máquinas próximas a vencer.`;
        
        this.successMessage = msg;
        await this.loadDashboard();
        this.$emit('refresh');
      } catch (err) {
        this.errorMessage = err.message || 'No fue posible ejecutar la generación de órdenes preventivas.';
      } finally {
        this.isGenerating = false;
      }
    },

    /**
     * Emite evento para navegar a la pestaña de órdenes con un filtro específico
     * @param {string} statusFilter
     */
    navigateToOrders(statusFilter = '') {
      this.$emit('view-orders', { status: statusFilter });
    },

    /**
     * Emite evento para abrir modal de configuración preventiva
     */
    openSettingsModal() {
      this.$emit('open-settings');
    }
  },
  template: `
    <div class="vg-preventive-dashboard" style="display: flex; flex-direction: column; gap: 20px;">
      <!-- 1. Cabecera y Barra de Acciones Principales -->
      <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px; background: #ffffff; padding: 20px; border-radius: var(--radius-card, 8px); border: 1px solid var(--color-border, #e2e8f0); box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div>
          <h2 style="margin: 0 0 6px; font-size: 20px; font-family: var(--font-display, 'DM Sans', sans-serif); color: var(--color-ink, #1e293b); display: flex; align-items: center; gap: 8px;">
            <span>🛡️</span> Panel de Mando Preventivo y Sanitario
          </h2>
          <p style="margin: 0; font-size: 14px; color: var(--color-ink-muted, #64748b);">
            Supervisión higiénico-sanitaria del parque de máquinas y cumplimiento de periodicidades (RF-PREV-06, Art. II).
          </p>
        </div>

        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
          <button
            type="button"
            class="vg-btn"
            style="background: #2560ff; color: #ffffff; border: 1px solid #1a4cd8; height: 38px; padding: 0 16px; font-weight: 600; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px; cursor: pointer;"
            :disabled="isGenerating"
            @click="handleGenerateDue"
            data-testid="btn-generate-due"
          >
            <span v-if="isGenerating">⏳ Generando...</span>
            <span v-else>⚡ Generar Preventivos Inminentes</span>
          </button>

          <button
            type="button"
            class="vg-btn"
            style="background: #ffffff; color: var(--color-ink, #1e293b); border: 1px solid var(--color-border, #cbd5e1); height: 38px; padding: 0 14px; font-weight: 600; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px; cursor: pointer;"
            @click="openSettingsModal"
            data-testid="btn-preventive-settings"
          >
            ⚙️ Frecuencias Normativas
          </button>

          <button
            type="button"
            class="vg-btn"
            style="background: #f8fafc; color: var(--color-ink, #334155); border: 1px solid var(--color-border, #cbd5e1); height: 38px; padding: 0 12px; font-weight: 600; border-radius: var(--radius-interactive, 4px); cursor: pointer;"
            :disabled="isLoading"
            @click="loadDashboard"
            title="Actualizar datos"
            data-testid="btn-refresh-dashboard"
          >
            🔄
          </button>
        </div>
      </div>

      <!-- Alertas de estado / retroalimentación -->
      <div v-if="successMessage" style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 12px 16px; border-radius: var(--radius-interactive, 4px); font-size: 14px; display: flex; justify-content: space-between; align-items: center;">
        <span>✅ {{ successMessage }}</span>
        <button type="button" @click="successMessage = ''" style="background: none; border: none; font-size: 16px; cursor: pointer; color: #166534;">✕</button>
      </div>

      <div v-if="errorMessage" style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 12px 16px; border-radius: var(--radius-interactive, 4px); font-size: 14px; display: flex; justify-content: space-between; align-items: center;">
        <span>⚠️ {{ errorMessage }}</span>
        <button type="button" @click="errorMessage = ''" style="background: none; border: none; font-size: 16px; cursor: pointer; color: #991b1b;">✕</button>
      </div>

      <!-- 2. BANNER PRIORITARIO: Cuarentena Sanitaria Activa (Art. II / RF-PREV-04) -->
      <div
        v-if="hasQuarantines"
        class="vg-quarantine-alert-banner"
        style="background: #fef2f2; border: 2px solid #ef4444; border-radius: var(--radius-card, 8px); padding: 18px 20px; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.15); display: flex; flex-direction: column; gap: 14px;"
        data-testid="quarantine-alert-banner"
      >
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
          <div style="display: flex; align-items: center; gap: 12px;">
            <span style="font-size: 32px; animation: vg-pulse 1.5s infinite;" aria-hidden="true">🚨</span>
            <div>
              <strong style="color: #991b1b; font-size: 16px; font-family: var(--font-display, 'DM Sans', sans-serif); display: block;">
                ALERTA DE SEGURIDAD ALIMENTARIA (Art. II Constitución): {{ summary.status_quarantine || urgentActions.quarantine_machines.length }} MÁQUINA(S) EN CUARENTENA
              </strong>
              <span style="color: #7f1d1d; font-size: 13px;">
                Estas unidades se encuentran bloqueadas para venta y reportes públicos por fallo crítico o rotura de frío.
              </span>
            </div>
          </div>

          <button
            type="button"
            class="vg-btn"
            style="background: #dc2626; color: #ffffff; border: 1px solid #b91c1c; border-radius: var(--radius-interactive, 4px); font-weight: 700; height: 36px; padding: 0 16px; cursor: pointer;"
            @click="navigateToOrders('EXPIRED')"
          >
            Ver órdenes prioritarias
          </button>
        </div>

        <!-- Lista detallada de máquinas en cuarentena -->
        <div v-if="urgentActions.quarantine_machines && urgentActions.quarantine_machines.length > 0" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; margin-top: 4px;">
          <div
            v-for="mach in urgentActions.quarantine_machines"
            :key="mach.machine_id"
            style="background: #ffffff; border: 1px solid #fecaca; border-radius: 6px; padding: 12px; display: flex; flex-direction: column; gap: 6px;"
          >
            <div style="display: flex; justify-content: space-between; align-items: baseline;">
              <strong style="color: #991b1b; font-size: 14px;">{{ mach.machine_code }}</strong>
              <span style="background: #fee2e2; color: #991b1b; font-size: 11px; font-weight: bold; padding: 2px 6px; border-radius: 4px;">
                CUARENTENA
              </span>
            </div>
            <div style="font-size: 12px; color: #475569;">
              📍 {{ mach.location_name }} — {{ mach.floor_wing || 'Planta general' }}
            </div>
            <div style="font-size: 12px; color: #7f1d1d; background: #fff5f5; padding: 6px; border-radius: 4px; border-left: 3px solid #ef4444;">
              ⚠️ {{ mach.quarantine_reason || 'Riesgo higiénico / rotura térmica' }}
            </div>
            <div v-if="mach.active_incident_code" style="font-size: 11px; color: #64748b; display: flex; justify-content: space-between;">
              <span>Ticket Correctivo: <strong>{{ mach.active_incident_code }}</strong></span>
            </div>
          </div>
        </div>
      </div>

      <!-- 3. Tarjetas de Semáforos Sanitarios de Parque (RF-PREV-06, EARS 6.1) -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;">
        <!-- Total de Máquinas -->
        <div class="vg-card" style="background: #ffffff; padding: 16px; border-radius: var(--radius-card, 8px); border: 1px solid var(--color-border, #e2e8f0); display: flex; flex-direction: column; gap: 6px;">
          <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Parque Total</span>
          <div style="font-size: 28px; font-weight: 800; color: #1e293b; font-family: var(--font-display, 'DM Sans', sans-serif);">
            {{ summary.total_machines }}
          </div>
          <span style="font-size: 12px; color: #64748b;">Máquinas activas</span>
        </div>

        <!-- Semáforo Verde (Vigentes) -->
        <div
          class="vg-card"
          style="background: #f0fdf4; padding: 16px; border-radius: var(--radius-card, 8px); border: 1px solid #bbf7d0; cursor: pointer; transition: transform 0.15s ease;"
          @click="navigateToOrders('COMPLETED')"
          title="Ver inspecciones conformes"
        >
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 12px; font-weight: 700; color: #166534; text-transform: uppercase;">🟢 Vigente / OK</span>
            <span style="font-size: 16px;">✅</span>
          </div>
          <div style="font-size: 28px; font-weight: 800; color: #15803d; font-family: var(--font-display, 'DM Sans', sans-serif); margin-top: 6px;">
            {{ summary.status_green }}
          </div>
          <span style="font-size: 12px; color: #166534;">Inspección al día (> 5d)</span>
        </div>

        <!-- Semáforo Amarillo (Próximas a vencer) -->
        <div
          class="vg-card"
          style="background: #fefce8; padding: 16px; border-radius: var(--radius-card, 8px); border: 1px solid #fef08a; cursor: pointer; transition: transform 0.15s ease;"
          @click="navigateToOrders('PENDING_ASSIGNMENT')"
          title="Ver máquinas próximas a vencer"
        >
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 12px; font-weight: 700; color: #854d0e; text-transform: uppercase;">🟡 Próximas (≤ 5d)</span>
            <span style="font-size: 16px;">⏳</span>
          </div>
          <div style="font-size: 28px; font-weight: 800; color: #a16207; font-family: var(--font-display, 'DM Sans', sans-serif); margin-top: 6px;">
            {{ summary.status_yellow }}
          </div>
          <span style="font-size: 12px; color: #854d0e;">Requieren orden preventiva</span>
        </div>

        <!-- Semáforo Rojo (Vencidas) -->
        <div
          class="vg-card"
          style="background: #fef2f2; padding: 16px; border-radius: var(--radius-card, 8px); border: 1px solid #fecaca; cursor: pointer; transition: transform 0.15s ease;"
          @click="navigateToOrders('EXPIRED')"
          title="Ver inspecciones vencidas"
        >
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 12px; font-weight: 700; color: #991b1b; text-transform: uppercase;">🔴 Vencidas</span>
            <span style="font-size: 16px;">⚠️</span>
          </div>
          <div style="font-size: 28px; font-weight: 800; color: #b91c1c; font-family: var(--font-display, 'DM Sans', sans-serif); margin-top: 6px;">
            {{ summary.status_red }}
          </div>
          <span style="font-size: 12px; color: #991b1b;">Plazo legal expirado</span>
        </div>

        <!-- Semáforo Cuarentena -->
        <div
          class="vg-card"
          style="background: #fdf2f8; padding: 16px; border-radius: var(--radius-card, 8px); border: 1px solid #fbcfe8; cursor: pointer; transition: transform 0.15s ease;"
          @click="navigateToOrders('')"
          title="Ver máquinas en cuarentena"
        >
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 12px; font-weight: 700; color: #9d174d; text-transform: uppercase;">🛑 Cuarentena</span>
            <span style="font-size: 16px;">🔒</span>
          </div>
          <div style="font-size: 28px; font-weight: 800; color: #be185d; font-family: var(--font-display, 'DM Sans', sans-serif); margin-top: 6px;">
            {{ summary.status_quarantine }}
          </div>
          <span style="font-size: 12px; color: #9d174d;">Bloqueo sanitario Art. II</span>
        </div>

        <!-- Pausa Estacional -->
        <div class="vg-card" style="background: #f8fafc; padding: 16px; border-radius: var(--radius-card, 8px); border: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 6px;">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase;">🏖️ Pausa Estacional</span>
            <span style="font-size: 16px;">⏸️</span>
          </div>
          <div style="font-size: 28px; font-weight: 800; color: #334155; font-family: var(--font-display, 'DM Sans', sans-serif); margin-top: 6px;">
            {{ summary.status_seasonal_pause }}
          </div>
          <span style="font-size: 12px; color: #64748b;">Vacaciones justificadas</span>
        </div>
      </div>

      <!-- 4. Barra de Progreso y Tasa de Cumplimiento -->
      <div style="background: #ffffff; padding: 20px; border-radius: var(--radius-card, 8px); border: 1px solid var(--color-border, #e2e8f0); display: flex; flex-direction: column; gap: 12px;">
        <div style="display: flex; justify-content: space-between; align-items: baseline;">
          <div>
            <strong style="font-size: 15px; color: var(--color-ink, #1e293b);">Tasa Global de Cumplimiento Sanitario</strong>
            <span style="font-size: 13px; color: #64748b; margin-left: 8px;">(Máquinas operativas con inspección vigente al día)</span>
          </div>
          <span style="font-size: 20px; font-weight: 800; color: #2560ff; font-family: var(--font-display, 'DM Sans', sans-serif);">
            {{ Number(summary.compliance_rate_percent || 0).toFixed(1) }}%
          </span>
        </div>

        <!-- Barra visual -->
        <div style="height: 10px; width: 100%; background: #e2e8f0; border-radius: 5px; overflow: hidden;">
          <div
            :style="{
              width: Math.min(100, Math.max(0, summary.compliance_rate_percent || 0)) + '%',
              height: '100%',
              background: (summary.compliance_rate_percent >= 90) ? '#22c55e' : (summary.compliance_rate_percent >= 75) ? '#eab308' : '#ef4444',
              transition: 'width 0.5s ease'
            }"
          ></div>
        </div>

        <!-- Enlaces rápidos a listado de órdenes -->
        <div style="display: flex; gap: 16px; margin-top: 4px; font-size: 13px;">
          <a href="javascript:void(0)" @click="navigateToOrders('')" style="color: #2560ff; text-decoration: none; font-weight: 600;">
            📋 Ver todas las órdenes preventivas →
          </a>
          <a href="javascript:void(0)" @click="navigateToOrders('PENDING_ASSIGNMENT')" style="color: #854d0e; text-decoration: none; font-weight: 600;">
            🟡 Órdenes pendientes de asignar ({{ urgentActions.due_soon_orders_count || 0 }}) →
          </a>
          <a href="javascript:void(0)" @click="navigateToOrders('EXPIRED')" style="color: #991b1b; text-decoration: none; font-weight: 600;">
            🔴 Órdenes vencidas ({{ urgentActions.expired_orders_count || 0 }}) →
          </a>
        </div>
      </div>
    </div>
  `
};
export default CoordinatorPreventiveDashboard;
