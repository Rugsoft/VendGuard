/**
 * VendGuard - ExecutiveReportModal Component (ExecutiveReportModal.js)
 * 
 * Modal y vista maquetada del Informe Resumen Ejecutivo imprimible (RF-06, EARS 6.3).
 * Proporciona salida directa a PDF o impresora física mediante window.print(),
 * respetando las reglas de metrics-print.css (sin barras laterales ni elementos web).
 * 
 * Características:
 * 1. Logotipo y cabecera institucional de VendGuard.
 * 2. Metadatos de emisión: fecha, hora, período evaluado y coordinador emisor.
 * 3. Tarjetas ejecutivas compactas de MTTR global, tasa de resolución y backlog.
 * 4. Cuadro de Alertas Sanitarias: Alimentos Perecederos (SLA 4h, Art. II Constitución).
 * 5. Tablas sintéticas de rendimiento por sede y tipologías de avería.
 * 6. Botones de acción: "🖨️ Imprimir / Guardar como PDF" y "✕ Cerrar".
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias de npm).
 */

export const ExecutiveReportModal = {
  name: 'ExecutiveReportModal',
  props: {
    show: {
      type: Boolean,
      default: false
    },
    summary: {
      type: Object,
      default: () => ({})
    },
    breakdown: {
      type: Object,
      default: () => ({})
    },
    currentUser: {
      type: Object,
      default: () => ({ name: 'Coordinador del Servicio', role: 'COORDINATOR' })
    }
  },
  emits: ['close'],
  computed: {
    kpis() {
      return this.summary?.kpis || {};
    },
    slaAlerts() {
      return this.summary?.sla_alerts || {};
    },
    perishable() {
      return this.slaAlerts?.perishable_food || {};
    },
    general() {
      return this.slaAlerts?.general || {};
    },
    period() {
      return this.summary?.period || {};
    },
    locations() {
      return this.breakdown?.by_location || [];
    },
    categories() {
      return this.breakdown?.by_category || [];
    },
    emissionTimestamp() {
      const d = new Date();
      return d.toLocaleDateString('es-ES', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
      });
    },
    periodFormatted() {
      if (this.period.from && this.period.to) {
        return `${this.period.from.substring(0, 10)} al ${this.period.to.substring(0, 10)}`;
      }
      return 'Últimos 30 días';
    }
  },
  methods: {
    printReport() {
      window.print();
    },
    closeModal() {
      this.$emit('close');
    },
    formatCategoryName(category) {
      const map = {
        'TEMPERATURE_COLD': 'Refrigeración y Frío (Sanitario)',
        'PAYMENT_SYSTEM': 'Sistema de Pago y Billetero',
        'PRODUCT_JAM': 'Atasco Mecánico de Producto',
        'DISPLAY_KEYPAD': 'Pantalla, Teclado y Botonera',
        'POWER_ELECTRICAL': 'Alimentación Eléctrica / Apagada',
        'OTHER': 'Otras Incidencias'
      };
      return map[category] || category || 'General';
    }
  },
  template: `
    <div
      v-if="show"
      class="executive-report-modal-wrap"
      style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); display: flex; justify-content: center; align-items: flex-start; z-index: 2000; overflow-y: auto; padding: 20px 10px;"
    >
      
      <!-- Zona Imprimible (aislada por metrics-print.css) -->
      <div class="executive-report-print-zone" style="max-width: 210mm; width: 100%;">
        
        <!-- Barra de Acciones Superior (Oculta en Impresión) -->
        <div class="executive-report-actions no-print" style="margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; background: #ffffff; padding: 12px 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
          <div>
            <span style="font-weight: 700; color: #000000; font-size: 14px;">📄 Previsualización de Informe Ejecutivo</span>
            <span style="font-size: 12px; color: #64748b; margin-left: 8px;">Listo para impresión directa o guardado en PDF</span>
          </div>
          <div style="display: flex; gap: 10px;">
            <button
              type="button"
              @click="printReport"
              style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #2560ff; color: #ffffff; border: none; border-radius: 4px; font-size: 13px; font-weight: 700; cursor: pointer; transition: background 0.2s ease;"
            >
              <span>🖨️ Imprimir / Guardar PDF</span>
            </button>
            <button
              type="button"
              @click="closeModal"
              style="padding: 8px 14px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer;"
            >
              ✕ Cerrar
            </button>
          </div>
        </div>

        <!-- Hoja de Informe A4 -->
        <div class="executive-report-sheet">
          
          <!-- Encabezado Institucional -->
          <div class="executive-report-header">
            <div>
              <div class="executive-brand-title">
                <span style="background: #2560ff; color: #ffffff; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; font-size: 16px;">V</span>
                <span>VendGuard</span>
              </div>
              <div style="font-size: 13px; font-weight: 600; color: #475569;">
                Informe Ejecutivo de Rendimiento Técnico y Auditoría SLA
              </div>
            </div>
            <div class="executive-meta-box">
              <div><strong>Período evaluado:</strong> {{ periodFormatted }}</div>
              <div><strong>Emisión:</strong> {{ emissionTimestamp }}</div>
              <div><strong>Responsable:</strong> {{ currentUser?.name || 'Coordinador' }}</div>
            </div>
          </div>

          <!-- Bloque 1: Resumen de Indicadores Clave (KPIs) -->
          <div class="executive-section-title">
            1. Indicadores Globales de Rendimiento (KPIs)
          </div>

          <div class="executive-kpi-summary-grid">
            <div class="executive-kpi-box">
              <div class="executive-kpi-label">MTTR Promedio Global</div>
              <div class="executive-kpi-value">{{ kpis.mttr_global_formatted || 'N/A' }}</div>
              <div class="executive-kpi-sub">{{ kpis.mttr_global_hours !== null && kpis.mttr_global_hours !== undefined ? kpis.mttr_global_hours + ' horas' : 'Sin tiempo' }}</div>
            </div>

            <div class="executive-kpi-box">
              <div class="executive-kpi-label">Tasa de Resolución</div>
              <div class="executive-kpi-value">{{ kpis.resolution_rate_percentage !== undefined ? kpis.resolution_rate_percentage + '%' : '0%' }}</div>
              <div class="executive-kpi-sub">{{ kpis.total_tickets_resolved || 0 }} de {{ kpis.total_tickets_created || 0 }} resueltos</div>
            </div>

            <div class="executive-kpi-box">
              <div class="executive-kpi-label">Backlog Activo</div>
              <div class="executive-kpi-value">{{ kpis.active_backlog !== undefined ? kpis.active_backlog : 0 }}</div>
              <div class="executive-kpi-sub">Averías en curso</div>
            </div>

            <div class="executive-kpi-box">
              <div class="executive-kpi-label">Incidencias Críticas</div>
              <div class="executive-kpi-value" :style="{ color: (kpis.critical_sla_breaches || 0) > 0 ? '#b91c1c' : '#0f172a' }">
                {{ kpis.critical_sla_breaches || 0 }}
              </div>
              <div class="executive-kpi-sub">Fuera de SLA</div>
            </div>
          </div>

          <!-- Bloque 2: Estado de Acuerdos de Nivel de Servicio (SLA) con Foco Sanitario -->
          <div class="executive-section-title">
            2. Cumplimiento de Acuerdos de Nivel de Servicio (SLA)
          </div>

          <!-- Banner Alimentos Perecederos (Art. II Constitución) -->
          <div
            class="executive-sla-banner"
            :style="{ backgroundColor: perishable.status === 'BREACHED' ? '#fff1f2' : '#f0fdf4', border: '1px solid ' + (perishable.status === 'BREACHED' ? '#fca5a5' : '#bbf7d0') }"
          >
            <div>
              <div style="font-weight: 700; font-size: 13px;" :style="{ color: perishable.status === 'BREACHED' ? '#991b1b' : '#166534' }">
                🥪 Alimentos Perecederos (Seguridad Alimentaria - Art. II Constitución)
              </div>
              <div style="font-size: 12px; margin-top: 2px;" :style="{ color: perishable.status === 'BREACHED' ? '#7f1d1d' : '#14532d' }">
                Umbral objetivo fijo: <strong>4.0 horas</strong>. Tiempo medio actual registrado: 
                <strong>{{ perishable.current_mttr_hours !== null && perishable.current_mttr_hours !== undefined ? perishable.current_mttr_hours + ' horas' : 'Sin datos' }}</strong>.
              </div>
            </div>
            <div>
              <span
                style="display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 700;"
                :style="{ backgroundColor: perishable.status === 'BREACHED' ? '#dc2626' : (perishable.status === 'COMPLIANT' ? '#16a34a' : '#64748b'), color: '#ffffff' }"
              >
                {{ perishable.status === 'BREACHED' ? 'INCUMPLIDO (> 4h)' : (perishable.status === 'COMPLIANT' ? 'CUMPLE SLA (≤ 4h)' : 'SIN DATOS') }}
              </span>
            </div>
          </div>

          <!-- Banner Parque General -->
          <div
            class="executive-sla-banner"
            :style="{ backgroundColor: general.status === 'BREACHED' ? '#fff1f2' : '#f8fafc', border: '1px solid ' + (general.status === 'BREACHED' ? '#fca5a5' : '#cbd5e1') }"
          >
            <div>
              <div style="font-weight: 700; font-size: 13px; color: #1e293b;">
                ☕ Parque Estándar (Bebidas Calientes, Snacks y Sólidos)
              </div>
              <div style="font-size: 12px; margin-top: 2px; color: #475569;">
                Umbral objetivo estándar: <strong>24.0 horas</strong>. Tiempo medio actual: 
                <strong>{{ general.current_mttr_hours !== null && general.current_mttr_hours !== undefined ? general.current_mttr_hours + ' horas' : 'Sin datos' }}</strong>.
              </div>
            </div>
            <div>
              <span
                style="display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 700;"
                :style="{ backgroundColor: general.status === 'BREACHED' ? '#dc2626' : (general.status === 'COMPLIANT' ? '#16a34a' : '#64748b'), color: '#ffffff' }"
              >
                {{ general.status === 'BREACHED' ? 'INCUMPLIDO' : (general.status === 'COMPLIANT' ? 'CUMPLE SLA' : 'SIN DATOS') }}
              </span>
            </div>
          </div>

          <!-- Bloque 3: Resumen de Desglose por Sedes Clientes -->
          <div class="executive-section-title">
            3. Resumen Operativo por Sede Cliente
          </div>

          <table class="executive-table">
            <thead>
              <tr>
                <th>Sede Cliente</th>
                <th style="width: 100px; text-align: center;">Código</th>
                <th style="width: 100px; text-align: center;">Resueltos</th>
                <th style="width: 110px;">MTTR Formateado</th>
                <th style="width: 100px;">Horas</th>
                <th style="width: 110px;">Estado SLA</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="locations.length === 0">
                <td colspan="6" style="text-align: center; color: #64748b; padding: 14px;">
                  No hay datos operativos de sedes para el período.
                </td>
              </tr>
              <tr v-for="loc in locations" :key="loc.location_id">
                <td style="font-weight: 600;">
                  {{ loc.location_name }}
                  <span v-if="!loc.is_active" style="font-size: 10px; color: #64748b;">(Inactivo)</span>
                </td>
                <td style="text-align: center; font-family: monospace;">{{ loc.site_code }}</td>
                <td style="text-align: center; font-weight: 600;">{{ loc.tickets_resolved }}</td>
                <td style="color: #2560ff; font-weight: 600;">{{ loc.mttr_formatted }}</td>
                <td>{{ loc.mttr_hours !== null && loc.mttr_hours !== undefined ? loc.mttr_hours + 'h' : 'N/A' }}</td>
                <td>
                  <span
                    style="font-size: 11px; font-weight: 700;"
                    :style="{ color: loc.sla_status === 'BREACHED' ? '#b91c1c' : '#15803d' }"
                  >
                    {{ loc.sla_status === 'BREACHED' ? 'Incumplido' : (loc.sla_status === 'COMPLIANT' ? 'Cumple' : 'Sin datos') }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- Bloque 4: Tipologías de Avería -->
          <div class="executive-section-title">
            4. Distribución por Tipología de Avería
          </div>

          <table class="executive-table">
            <thead>
              <tr>
                <th>Tipología de Fallo</th>
                <th style="width: 120px; text-align: center;">Incidencias Resueltas</th>
                <th style="width: 140px;">MTTR Formateado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="categories.length === 0">
                <td colspan="3" style="text-align: center; color: #64748b; padding: 14px;">
                  No hay incidencias registradas en este período.
                </td>
              </tr>
              <tr v-for="cat in categories" :key="cat.category">
                <td style="font-weight: 600;">{{ formatCategoryName(cat.category) }}</td>
                <td style="text-align: center; font-weight: 600;">{{ cat.tickets_resolved }}</td>
                <td style="color: #2560ff; font-weight: 600;">{{ cat.mttr_formatted }}</td>
              </tr>
            </tbody>
          </table>

          <!-- Pie del Informe -->
          <div class="executive-footer-note">
            <span>VendGuard Vending Incident Management — Documento Confidencial de Operación</span>
            <span>Generado electrónicamente bajo el Dogma Vanilla</span>
          </div>

        </div>

      </div>

    </div>
  `
};

export default ExecutiveReportModal;
