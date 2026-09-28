/**
 * VendGuard - CoordinatorSparePartsAnalyticsTab Component (CoordinatorSparePartsAnalyticsTab.js)
 * 
 * Cuadro de Mando Analítico de Repuestos, Fiabilidad y Detección de Averías Crónicas (Módulo M2).
 * Cumple con:
 * - RF-REP-04: Bandeja de revisión de piezas fuera de catálogo emitidas en movilidad.
 * - RF-REP-08: Panel analítico de costes, ranking de piezas sustituidas, desglose de destino (Desguace/Taller)
 *              y banners de alerta por componentes con fallo crónico/recurrente (> 3 sustituciones en 90 días).
 * - RF-REP-09: Descarga inmediata de consumos históricos o filtrados en formato CSV UTF-8.
 * - Docs/design.md: Directrices visuales Docker (Azul eléctrico #2560ff, alertas en #f8b60f, radios 4px/8px).
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias externas npm/bundlers).
 * Dualismo Lingüístico: Código y propiedades en inglés, interfaz y textos en español.
 */

import { api } from '../api.js';

export const CoordinatorSparePartsAnalyticsTab = {
  name: 'CoordinatorSparePartsAnalyticsTab',
  emits: ['open-catalog', 'create-part-from-request', 'view-incident'],
  data() {
    return {
      selectedPeriod: 90, // 30 | 90 | 180 | 365 | null (todo el histórico)
      analytics: {
        period_days: 90,
        total_parts_replaced: 0,
        total_parts_cost: 0,
        top_replaced_parts: [],
        costs_by_machine_model: [],
        costs_by_location: [],
        chronic_failure_alerts: []
      },
      pendingReviewRequests: [],
      isLoading: false,
      isExporting: false,
      errorMessage: '',
      successMessage: ''
    };
  },
  computed: {
    hasChronicAlerts() {
      return Array.isArray(this.analytics.chronic_failure_alerts) && this.analytics.chronic_failure_alerts.length > 0;
    },
    hasPendingReviews() {
      return Array.isArray(this.pendingReviewRequests) && this.pendingReviewRequests.length > 0;
    },
    criticalAlertsCount() {
      if (!this.hasChronicAlerts) return 0;
      return this.analytics.chronic_failure_alerts.filter(a => a.severity === 'CRITICAL').length;
    }
  },
  mounted() {
    this.loadAllData();
  },
  methods: {
    /**
     * Carga en paralelo la analítica consolidada y las solicitudes pendientes de revisión.
     */
    async loadAllData() {
      this.isLoading = true;
      this.errorMessage = '';
      try {
        await Promise.all([
          this.loadAnalytics(),
          this.loadPendingReviews()
        ]);
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar los datos analíticos del parque de repuestos.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Carga el cuadro analítico de consumos, costes, ranking y alertas (RF-REP-08).
     */
    async loadAnalytics() {
      const params = {};
      if (this.selectedPeriod !== null && this.selectedPeriod !== undefined && this.selectedPeriod !== '') {
        params.period_days = this.selectedPeriod;
      }

      const res = await api.coordinator.getSparePartsAnalytics(params);
      const data = res?.data || res || {};

      this.analytics = {
        period_days: data.period_days ?? this.selectedPeriod,
        total_parts_replaced: data.total_parts_replaced ?? 0,
        total_parts_cost: parseFloat(data.total_parts_cost ?? 0),
        top_replaced_parts: Array.isArray(data.top_replaced_parts) ? data.top_replaced_parts : [],
        costs_by_machine_model: Array.isArray(data.costs_by_machine_model) ? data.costs_by_machine_model : [],
        costs_by_location: Array.isArray(data.costs_by_location) ? data.costs_by_location : [],
        chronic_failure_alerts: Array.isArray(data.chronic_failure_alerts) ? data.chronic_failure_alerts : []
      };
    },

    /**
     * Carga la bandeja de piezas fuera de catálogo pendientes de revisión (RF-REP-04).
     */
    async loadPendingReviews() {
      try {
        const res = await api.coordinator.getSparePartsPendingReview();
        this.pendingReviewRequests = res?.data || res || [];
      } catch (err) {
        console.warn('No se pudo cargar la bandeja de revisión de piezas fuera de catálogo:', err);
      }
    },

    /**
     * Cambia la ventana temporal de análisis y recarga las métricas.
     */
    async changePeriod(days) {
      this.selectedPeriod = days;
      this.isLoading = true;
      try {
        await this.loadAnalytics();
      } catch (err) {
        this.errorMessage = err.message || 'Error al actualizar el período analítico.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Descarga inmediata del archivo plano CSV UTF-8 con BOM (RF-REP-09).
     */
    async exportCsv() {
      this.isExporting = true;
      this.errorMessage = '';
      try {
        const params = {};
        if (this.selectedPeriod) {
          params.period_days = this.selectedPeriod;
        }

        await api.coordinator.downloadSparePartsCsv(params);
        this.successMessage = 'El archivo CSV de consumos de repuestos se ha generado y descargado correctamente.';
        setTimeout(() => {
          this.successMessage = '';
        }, 4000);
      } catch (err) {
        this.errorMessage = err.message || 'Error al exportar los consumos a CSV.';
      } finally {
        this.isExporting = false;
      }
    },

    /**
     * Emite el evento para dar de alta en catálogo una pieza solicitada fuera de catálogo (RF-REP-04).
     */
    handleCreatePartFromRequest(request) {
      this.$emit('create-part-from-request', {
        custom_part_description: request.custom_part_description,
        machine_model: request.machine_model,
        incident_id: request.incident_id,
        ticket_code: request.ticket_code
      });
    },

    /**
     * Emite el evento para abrir el catálogo maestro de repuestos.
     */
    handleOpenCatalog() {
      this.$emit('open-catalog');
    },

    /**
     * Emite el evento para visualizar los detalles de una incidencia.
     */
    handleViewIncident(incidentId) {
      this.$emit('view-incident', { incidentId });
    },

    /**
     * Formatea un valor numérico como divisa española (ej: 142,50 €).
     */
    formatPrice(amount) {
      const num = parseFloat(amount);
      if (isNaN(num)) return '0,00 €';
      return num.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    },

    /**
     * Formatea una cadena de fecha ISO o MySQL a formato amigable en español.
     */
    formatDate(dateStr) {
      if (!dateStr) return 'N/A';
      try {
        const d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' });
      } catch {
        return dateStr;
      }
    },

    /**
     * Obtiene el estilo de la insignia de destino de la pieza retirada.
     */
    getDestinationBadgeStyle(destination) {
      if (destination === 'DESGUACE') {
        return 'background-color: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;';
      }
      return 'background-color: #fef3c7; color: #92400e; border: 1px solid #fcd34d;';
    }
  },
  template: `
    <div class="coordinator-spare-parts-analytics-tab" style="padding-top: 8px;">
      <!-- Notificaciones generales -->
      <div
        v-if="errorMessage"
        style="background-color: #fddfdf; border: 1px solid #ff5757; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; display: flex; align-items: center; justify-content: space-between;"
      >
        <span>⚠️ {{ errorMessage }}</span>
        <button type="button" @click="errorMessage = ''" style="background: none; border: none; font-size: 16px; cursor: pointer; color: #991b1b;">&times;</button>
      </div>

      <div
        v-if="successMessage"
        style="background-color: #ecfdf5; border: 1px solid #38bd7d; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; display: flex; align-items: center; justify-content: space-between;"
      >
        <span>✅ {{ successMessage }}</span>
        <button type="button" @click="successMessage = ''" style="background: none; border: none; font-size: 16px; cursor: pointer; color: #065f46;">&times;</button>
      </div>

      <!-- Barra superior: Título, Filtro de período y Acciones -->
      <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 16px 20px; margin-bottom: 20px;">
        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px;">
          <div>
            <h2 style="margin: 0; font-size: 18px; font-weight: 600; color: #2c333f;">Analítica de Repuestos y Fiabilidad</h2>
            <p style="margin: 2px 0 0 0; font-size: 13px; color: #6c7e9d;">
              Monitorización de consumos de piezas, costes acumulados, alertas de fallos recurrentes y control de piezas fuera de catálogo.
            </p>
          </div>

          <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <!-- Botón hacia el Catálogo Maestro -->
            <button
              type="button"
              class="vg-btn"
              style="height: 38px; font-size: 13px; font-weight: 600; background-color: #ffffff; color: #2c333f; border: 1px solid #c8cfda; border-radius: 4px; padding: 0 14px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;"
              @click="handleOpenCatalog"
              data-testid="btn-open-catalog"
              title="Volver a la gestión del catálogo maestro de piezas"
            >
              📦 Catálogo Maestro
            </button>

            <!-- Botón de Exportación CSV (RF-REP-09) -->
            <button
              type="button"
              class="vg-btn"
              style="height: 38px; font-size: 13px; font-weight: 600; background-color: #2560ff; color: #ffffff; border: none; border-radius: 4px; padding: 0 16px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;"
              @click="exportCsv"
              :disabled="isExporting"
              data-testid="btn-export-spare-parts-csv"
              title="Descargar registro de consumos en formato CSV UTF-8 con BOM para Microsoft Excel"
            >
              <span v-if="isExporting">⏳ Descargando...</span>
              <span v-else>📥 Exportar Consumos (CSV)</span>
            </button>
          </div>
        </div>

        <!-- Selector de Ventana Temporal (Período) -->
        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-top: 14px; padding-top: 12px; border-top: 1px solid #c8cfda;">
          <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 13px; font-weight: 600; color: #2c333f;">📅 Ventana de Análisis:</span>
            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
              <button
                type="button"
                class="vg-btn"
                :style="{
                  height: '32px',
                  fontSize: '12px',
                  fontWeight: '600',
                  padding: '0 12px',
                  borderRadius: '4px',
                  border: '1px solid',
                  cursor: 'pointer',
                  borderColor: selectedPeriod === 30 ? '#2560ff' : '#c8cfda',
                  backgroundColor: selectedPeriod === 30 ? '#2560ff' : '#ffffff',
                  color: selectedPeriod === 30 ? '#ffffff' : '#2c333f'
                }"
                @click="changePeriod(30)"
                data-testid="period-btn-30"
              >
                Últimos 30 días
              </button>
              <button
                type="button"
                class="vg-btn"
                :style="{
                  height: '32px',
                  fontSize: '12px',
                  fontWeight: '600',
                  padding: '0 12px',
                  borderRadius: '4px',
                  border: '1px solid',
                  cursor: 'pointer',
                  borderColor: selectedPeriod === 90 ? '#2560ff' : '#c8cfda',
                  backgroundColor: selectedPeriod === 90 ? '#2560ff' : '#ffffff',
                  color: selectedPeriod === 90 ? '#ffffff' : '#2c333f'
                }"
                @click="changePeriod(90)"
                data-testid="period-btn-90"
              >
                Últimos 90 días (Defecto)
              </button>
              <button
                type="button"
                class="vg-btn"
                :style="{
                  height: '32px',
                  fontSize: '12px',
                  fontWeight: '600',
                  padding: '0 12px',
                  borderRadius: '4px',
                  border: '1px solid',
                  cursor: 'pointer',
                  borderColor: selectedPeriod === 180 ? '#2560ff' : '#c8cfda',
                  backgroundColor: selectedPeriod === 180 ? '#2560ff' : '#ffffff',
                  color: selectedPeriod === 180 ? '#ffffff' : '#2c333f'
                }"
                @click="changePeriod(180)"
                data-testid="period-btn-180"
              >
                Últimos 180 días
              </button>
              <button
                type="button"
                class="vg-btn"
                :style="{
                  height: '32px',
                  fontSize: '12px',
                  fontWeight: '600',
                  padding: '0 12px',
                  borderRadius: '4px',
                  border: '1px solid',
                  cursor: 'pointer',
                  borderColor: selectedPeriod === 365 ? '#2560ff' : '#c8cfda',
                  backgroundColor: selectedPeriod === 365 ? '#2560ff' : '#ffffff',
                  color: selectedPeriod === 365 ? '#ffffff' : '#2c333f'
                }"
                @click="changePeriod(365)"
                data-testid="period-btn-365"
              >
                1 Año
              </button>
              <button
                type="button"
                class="vg-btn"
                :style="{
                  height: '32px',
                  fontSize: '12px',
                  fontWeight: '600',
                  padding: '0 12px',
                  borderRadius: '4px',
                  border: '1px solid',
                  cursor: 'pointer',
                  borderColor: selectedPeriod === null ? '#2560ff' : '#c8cfda',
                  backgroundColor: selectedPeriod === null ? '#2560ff' : '#ffffff',
                  color: selectedPeriod === null ? '#ffffff' : '#2c333f'
                }"
                @click="changePeriod(null)"
                data-testid="period-btn-all"
              >
                Todo el Histórico
              </button>
            </div>
          </div>

          <div style="font-size: 12px; color: #6c7e9d;">
            <span>🔄 Estado: </span>
            <span v-if="isLoading" style="font-weight: 600; color: #2560ff;">Actualizando métricas...</span>
            <span v-else style="font-weight: 600; color: #38bd7d;">Datos actualizados</span>
          </div>
        </div>
      </div>

      <!-- Tarjetas KPI de Gasto y Consumo (Docs/design.md: 8px radius) -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <!-- KPI 1: Piezas Sustituidas Totales -->
        <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 16px 20px;">
          <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #6c7e9d; margin-bottom: 4px;">Piezas Sustituidas</div>
          <div style="font-size: 28px; font-weight: 700; color: #2c333f;" data-testid="kpi-total-parts-replaced">{{ analytics.total_parts_replaced }}</div>
          <div style="font-size: 12px; color: #6c7e9d; margin-top: 4px;">Unidades instaladas en campo</div>
        </div>

        <!-- KPI 2: Gasto Económico Total Acumulado -->
        <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 16px 20px;">
          <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #2560ff; margin-bottom: 4px;">Coste Acumulado Piezas</div>
          <div style="font-size: 28px; font-weight: 700; color: #2560ff;" data-testid="kpi-total-parts-cost">{{ formatPrice(analytics.total_parts_cost) }}</div>
          <div style="font-size: 12px; color: #6c7e9d; margin-top: 4px;">Costes congelados por snapshot</div>
        </div>

        <!-- KPI 3: Alertas por Fallos Crónicos / Recurrentes -->
        <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 16px 20px;">
          <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #f8b60f; margin-bottom: 4px;">Alertas Fallos Crónicos</div>
          <div style="font-size: 28px; font-weight: 700;" :style="{ color: hasChronicAlerts ? '#f8b60f' : '#38bd7d' }" data-testid="kpi-chronic-alerts-count">
            {{ (analytics.chronic_failure_alerts || []).length }}
          </div>
          <div style="font-size: 12px; color: #6c7e9d; margin-top: 4px;">
            <span v-if="hasChronicAlerts" style="color: #92400e; font-weight: 600;">⚠️ Requiere inspección técnica</span>
            <span v-else style="color: #38bd7d; font-weight: 600;">✅ Fiabilidad dentro de umbrales</span>
          </div>
        </div>

        <!-- KPI 4: Piezas Fuera de Catálogo Pendientes -->
        <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 16px 20px;">
          <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #ff5757; margin-bottom: 4px;">Pendientes Revisión</div>
          <div style="font-size: 28px; font-weight: 700; color: #2c333f;" data-testid="kpi-pending-reviews-count">{{ pendingReviewRequests.length }}</div>
          <div style="font-size: 12px; color: #6c7e9d; margin-top: 4px;">Solicitudes fuera de catálogo</div>
        </div>
      </div>

      <!-- BANNERS DE ALERTA POR AVERÍAS CRÓNICAS (RF-REP-08 / docs/design.md: Warning #f8b60f) -->
      <div
        v-if="hasChronicAlerts"
        style="background-color: #fffbeb; border: 1px solid #f8b60f; border-left: 6px solid #f8b60f; border-radius: 8px; padding: 16px 20px; margin-bottom: 24px;"
        data-testid="chronic-failure-alerts-container"
      >
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px;">
          <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 20px;">⚠️</span>
            <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #92400e;">
              Alertas de Fiabilidad: Componentes con Fallo Crónico o Recurrente
            </h3>
          </div>
          <span style="font-size: 12px; font-weight: 600; background-color: #fef3c7; color: #92400e; padding: 3px 8px; border-radius: 4px; border: 1px solid #fcd34d;">
            Umbral: > 3 sustituciones en 90 días
          </span>
        </div>

        <p style="margin: 0 0 14px 0; font-size: 13px; color: #78350f;">
          Se han detectado máquinas físicas donde el mismo componente ha sido reemplazado de manera anormalmente repetitiva.
          Esto puede indicar un defecto de fábrica, fallo en la fuente de alimentación o un problema de instalación hidráulica.
        </p>

        <div style="display: flex; flex-direction: column; gap: 10px;">
          <div
            v-for="(alert, idx) in analytics.chronic_failure_alerts"
            :key="idx"
            style="background-color: #ffffff; border: 1px solid #fcd34d; border-radius: 6px; padding: 12px 16px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px;"
            :data-testid="'chronic-alert-item-' + idx"
          >
            <div>
              <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                <span style="font-family: monospace; font-weight: 700; color: #2560ff; background-color: #e5f2fc; padding: 2px 6px; border-radius: 4px; font-size: 12px;">
                  {{ alert.machine_code }}
                </span>
                <span style="font-weight: 600; color: #2c333f; font-size: 14px;">
                  {{ alert.machine_model }}
                </span>
                <span style="font-size: 12px; color: #6c7e9d;">
                  📍 {{ alert.location_name }}
                </span>
              </div>
              <div style="font-size: 13px; color: #2c333f;">
                <strong>Pieza Afectada:</strong> {{ alert.part_name }} (<code>{{ alert.part_code }}</code>)
              </div>
              <div style="font-size: 12px; color: #78350f; margin-top: 2px;">
                🚨 {{ alert.warning_message }} · Primer reemplazo: {{ formatDate(alert.first_replacement_at) }} · Último: {{ formatDate(alert.last_replacement_at) }}
              </div>
            </div>

            <div style="display: flex; align-items: center; gap: 8px;">
              <span
                style="padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase;"
                :style="{
                  backgroundColor: alert.severity === 'CRITICAL' ? '#fee2e2' : '#fef3c7',
                  color: alert.severity === 'CRITICAL' ? '#991b1b' : '#92400e',
                  border: alert.severity === 'CRITICAL' ? '1px solid #fca5a5' : '1px solid #fcd34d'
                }"
              >
                {{ alert.replacements_in_period }} sustituciones
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- BANDEJA DE PIEZAS FUERA DE CATÁLOGO PENDIENTES DE REVISIÓN (RF-REP-04) -->
      <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 18px 20px; margin-bottom: 24px;" data-testid="pending-review-section">
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px;">
          <div>
            <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #2c333f;">
              Bandeja de Piezas Fuera de Catálogo Pendientes de Homologación (RF-REP-04)
            </h3>
            <p style="margin: 2px 0 0 0; font-size: 13px; color: #6c7e9d;">
              Averías pausadas en campo donde el técnico solicitó una pieza no presente en el catálogo oficial con su justificación técnica obligatoria.
            </p>
          </div>
          <span
            style="font-size: 12px; font-weight: 700; padding: 3px 8px; border-radius: 4px;"
            :style="{
              backgroundColor: pendingReviewRequests.length > 0 ? '#fee2e2' : '#ecfdf5',
              color: pendingReviewRequests.length > 0 ? '#991b1b' : '#065f46',
              border: pendingReviewRequests.length > 0 ? '1px solid #fca5a5' : '1px solid #38bd7d'
            }"
            data-testid="pending-review-count-badge"
          >
            {{ pendingReviewRequests.length }} pendiente(s)
          </span>
        </div>

        <!-- Sin solicitudes pendientes -->
        <div
          v-if="pendingReviewRequests.length === 0"
          style="padding: 24px; text-align: center; background-color: #f9fafb; border-radius: 6px; border: 1px dashed #c8cfda;"
          data-testid="no-pending-reviews-message"
        >
          <div style="font-size: 24px; margin-bottom: 4px;">✅</div>
          <div style="font-size: 14px; font-weight: 600; color: #2c333f;">Bandeja al día</div>
          <div style="font-size: 12px; color: #6c7e9d;">No existen piezas fuera de catálogo pendientes de revisión en este momento.</div>
        </div>

        <!-- Tabla de solicitudes pendientes de revisión -->
        <div v-else style="overflow-x: auto;">
          <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
            <thead>
              <tr style="background-color: #f9fafb; border-bottom: 1px solid #c8cfda; color: #6c7e9d; font-weight: 600; font-size: 12px; text-transform: uppercase;">
                <th style="padding: 10px 14px;">Incidencia</th>
                <th style="padding: 10px 14px;">Máquina y Sede</th>
                <th style="padding: 10px 14px;">Técnico Informante</th>
                <th style="padding: 10px 14px;">Justificación Técnica Obligatoria</th>
                <th style="padding: 10px 14px;">Fecha</th>
                <th style="padding: 10px 14px; text-align: right;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="req in pendingReviewRequests"
                :key="req.request_id"
                style="border-bottom: 1px solid #c8cfda;"
                :data-testid="'pending-review-row-' + req.request_id"
              >
                <td style="padding: 10px 14px; white-space: nowrap;">
                  <span style="font-family: monospace; font-weight: 700; color: #2560ff;">
                    {{ req.ticket_code }}
                  </span>
                </td>
                <td style="padding: 10px 14px;">
                  <div style="font-weight: 600; color: #2c333f;">{{ req.machine_code }} ({{ req.machine_model }})</div>
                  <div style="font-size: 12px; color: #6c7e9d;">📍 {{ req.location_name }}</div>
                </td>
                <td style="padding: 10px 14px; white-space: nowrap; color: #2c333f;">
                  👤 {{ req.technician_name || 'Técnico de campo' }}
                </td>
                <td style="padding: 10px 14px;">
                  <div style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px; padding: 6px 10px; font-style: italic; color: #2c333f; font-size: 12px; max-width: 380px;">
                    "{{ req.custom_part_description }}"
                  </div>
                </td>
                <td style="padding: 10px 14px; white-space: nowrap; color: #6c7e9d; font-size: 12px;">
                  {{ formatDate(req.requested_at) }}
                </td>
                <td style="padding: 10px 14px; text-align: right; white-space: nowrap;">
                  <button
                    type="button"
                    class="vg-btn"
                    style="height: 30px; font-size: 12px; font-weight: 600; background-color: #ffffff; color: #2560ff; border: 1px solid #2560ff; border-radius: 4px; padding: 0 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;"
                    @click="handleCreatePartFromRequest(req)"
                    :data-testid="'btn-catalog-request-' + req.request_id"
                    title="Dar de alta esta pieza en el catálogo maestro e iniciar homologación"
                  >
                    ➕ Catalogar Pieza
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TABLA RANKING DE PIEZAS SUSTITUIDAS CON DESGLOSE DE DESTINO (RF-REP-08) -->
      <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 18px 20px; margin-bottom: 24px;" data-testid="ranking-section">
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px;">
          <div>
            <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #2c333f;">
              Ranking de Piezas Sustituidas y Destino de Material Retirado (RF-REP-08)
            </h3>
            <p style="margin: 2px 0 0 0; font-size: 13px; color: #6c7e9d;">
              Componentes con mayor tasa de recambio y desglose de destino logístico (Desguace vs Reparación en Taller Central).
            </p>
          </div>
        </div>

        <div v-if="analytics.top_replaced_parts.length === 0" style="padding: 32px; text-align: center; color: #6c7e9d; font-size: 13px;">
          No se registran piezas sustituidas en la ventana temporal seleccionada.
        </div>

        <div v-else style="overflow-x: auto;">
          <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
            <thead>
              <tr style="background-color: #f9fafb; border-bottom: 1px solid #c8cfda; color: #6c7e9d; font-weight: 600; font-size: 12px; text-transform: uppercase;">
                <th style="padding: 10px 14px; width: 40px;">#</th>
                <th style="padding: 10px 14px;">Código de Pieza</th>
                <th style="padding: 10px 14px;">Denominación Oficial</th>
                <th style="padding: 10px 14px; text-align: center;">Unidades</th>
                <th style="padding: 10px 14px; text-align: center;">Desglose Destino Logístico</th>
                <th style="padding: 10px 14px; text-align: right;">Coste Total Acumulado</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(part, idx) in analytics.top_replaced_parts"
                :key="idx"
                style="border-bottom: 1px solid #c8cfda;"
                :data-testid="'top-part-row-' + idx"
              >
                <!-- Posición en ranking -->
                <td style="padding: 10px 14px; font-weight: 700; color: #6c7e9d;">
                  #{{ idx + 1 }}
                </td>

                <!-- Código -->
                <td style="padding: 10px 14px; white-space: nowrap;">
                  <span style="font-family: monospace; font-weight: 700; color: #2560ff; background-color: #e5f2fc; padding: 2px 6px; border-radius: 4px; font-size: 12px;">
                    {{ part.part_code }}
                  </span>
                </td>

                <!-- Denominación -->
                <td style="padding: 10px 14px; font-weight: 600; color: #2c333f;">
                  {{ part.name }}
                </td>

                <!-- Unidades instaladas -->
                <td style="padding: 10px 14px; text-align: center; font-weight: 700; color: #2c333f; font-size: 14px;">
                  {{ part.units_installed }}
                </td>

                <!-- Desglose de Destino (Desguace vs Taller) -->
                <td style="padding: 10px 14px; text-align: center;">
                  <div style="display: inline-flex; align-items: center; gap: 8px;">
                    <span
                      style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;"
                      :style="getDestinationBadgeStyle('DESGUACE')"
                      title="Piezas no recuperables enviadas a desguace"
                    >
                      🗑️ Desguace: {{ part.destinations ? part.destinations.DESGUACE : 0 }}
                    </span>
                    <span
                      style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;"
                      :style="getDestinationBadgeStyle('TALLER')"
                      title="Piezas susceptibles de revisión y reparación en taller central"
                    >
                      🔧 Taller: {{ part.destinations ? part.destinations.TALLER : 0 }}
                    </span>
                  </div>
                </td>

                <!-- Coste Acumulado -->
                <td style="padding: 10px 14px; text-align: right; font-weight: 700; color: #2c333f; font-size: 14px;">
                  {{ formatPrice(part.accumulated_cost) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- DESGLOSE SECUNDARIO: COSTES POR MODELO DE MÁQUINA Y POR SEDE -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 20px;">
        <!-- Costes por Modelo de Máquina -->
        <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 18px 20px;" data-testid="model-cost-breakdown">
          <h4 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #2c333f;">
            Coste de Repuestos por Modelo de Máquina
          </h4>
          <div v-if="analytics.costs_by_machine_model.length === 0" style="padding: 24px; text-align: center; color: #6c7e9d; font-size: 13px;">
            Sin datos de costes por modelo.
          </div>
          <table v-else style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
            <thead>
              <tr style="background-color: #f9fafb; border-bottom: 1px solid #c8cfda; color: #6c7e9d; font-size: 11px; text-transform: uppercase;">
                <th style="padding: 8px 10px;">Modelo</th>
                <th style="padding: 8px 10px; text-align: center;">Máquinas</th>
                <th style="padding: 8px 10px; text-align: center;">Piezas</th>
                <th style="padding: 8px 10px; text-align: right;">Coste Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(m, idx) in analytics.costs_by_machine_model" :key="idx" style="border-bottom: 1px solid #c8cfda;">
                <td style="padding: 8px 10px; font-weight: 600; color: #2c333f;">{{ m.model }}</td>
                <td style="padding: 8px 10px; text-align: center; color: #6c7e9d;">{{ m.machines_count }}</td>
                <td style="padding: 8px 10px; text-align: center; font-weight: 600;">{{ m.units_replaced }}</td>
                <td style="padding: 8px 10px; text-align: right; font-weight: 700; color: #2560ff;">{{ formatPrice(m.total_cost) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Costes por Sede Cliente -->
        <div style="background-color: #ffffff; border: 1px solid #c8cfda; border-radius: 8px; padding: 18px 20px;" data-testid="location-cost-breakdown">
          <h4 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #2c333f;">
            Coste de Repuestos por Sede Cliente
          </h4>
          <div v-if="analytics.costs_by_location.length === 0" style="padding: 24px; text-align: center; color: #6c7e9d; font-size: 13px;">
            Sin datos de costes por sede.
          </div>
          <table v-else style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
            <thead>
              <tr style="background-color: #f9fafb; border-bottom: 1px solid #c8cfda; color: #6c7e9d; font-size: 11px; text-transform: uppercase;">
                <th style="padding: 8px 10px;">Sede</th>
                <th style="padding: 8px 10px; text-align: center;">Piezas</th>
                <th style="padding: 8px 10px; text-align: right;">Coste Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(loc, idx) in analytics.costs_by_location" :key="idx" style="border-bottom: 1px solid #c8cfda;">
                <td style="padding: 8px 10px; font-weight: 600; color: #2c333f;">📍 {{ loc.location_name }}</td>
                <td style="padding: 8px 10px; text-align: center; font-weight: 600;">{{ loc.units_replaced }}</td>
                <td style="padding: 8px 10px; text-align: right; font-weight: 700; color: #2560ff;">{{ formatPrice(loc.total_cost) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  `
};
