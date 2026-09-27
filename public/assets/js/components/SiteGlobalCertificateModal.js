/**
 * VendGuard - SiteGlobalCertificateModal (SiteGlobalCertificateModal.js)
 * 
 * Componente modal para visualización e impresión del Certificado Global Consolidado de Sede (RF-PREV-07, EARS 7.2).
 * Cumple con:
 * - Dictamen global transparente: CONFORME, CONFORME_CON_OBSERVACIONES o CONDICIONADO ante incidencias de parque.
 * - Desglose de cada una de las unidades instaladas en el edificio del cliente.
 * - RNF-03: Hoja de estilo para impresión nativa en A4 (@media print).
 * - Art. II: Prioridad sanitaria absoluta ante riesgos alimentarios y cuarentenas activas.
 * - Dogma Vanilla: Vue 3 ESM puro, sin compiladores externos.
 * - Dualismo Lingüístico: Código y lógica en inglés; interfaz gráfica y dictamen en español.
 */

import { api } from '../api.js';

export const SiteGlobalCertificateModal = {
  name: 'SiteGlobalCertificateModal',
  props: {
    modelValue: {
      type: Boolean,
      default: false
    },
    globalData: {
      type: Object,
      default: null
    }
  },
  emits: ['update:modelValue', 'close'],
  data() {
    return {
      loading: false,
      error: '',
      report: null
    };
  },
  computed: {
    globalVerdict() {
      return this.report?.global_verdict || this.report?.verdict || 'CONFORME';
    },
    isCondicionado() {
      return this.globalVerdict === 'CONDICIONADO' || this.report?.has_quarantine_or_expired === true;
    },
    verdictBadgeInfo() {
      if (this.isCondicionado) {
        return {
          label: 'DICTAMEN GLOBAL: CONDICIONADO',
          color: '#b91c1c',
          bg: '#fee2e2',
          border: '#f87171',
          icon: '⚠️'
        };
      }
      if (this.globalVerdict === 'CONFORME_CON_OBSERVACIONES') {
        return {
          label: 'DICTAMEN GLOBAL: CONFORME CON OBSERVACIONES',
          color: '#b45309',
          bg: '#fef3c7',
          border: '#fcd34d',
          icon: 'ℹ️'
        };
      }
      return {
        label: 'DICTAMEN GLOBAL: CONFORME',
        color: '#15803d',
        bg: '#dcfce7',
        border: '#86efac',
        icon: '✅'
      };
    },
    machinesBreakdown() {
      return this.report?.machines_breakdown || [];
    },
    formattedIssueDate() {
      const dStr = this.report?.issue_date;
      if (!dStr) return 'N/D';
      try {
        const d = new Date(dStr);
        return d.toLocaleDateString('es-ES', {
          year: 'numeric',
          month: 'long',
          day: 'numeric',
          hour: '2-digit',
          minute: '2-digit'
        });
      } catch (e) {
        return dStr;
      }
    }
  },
  watch: {
    modelValue(newVal) {
      if (newVal) {
        this.initReport();
      }
    },
    globalData: {
      immediate: true,
      handler(val) {
        if (val) {
          this.report = val;
        }
      }
    }
  },
  methods: {
    async initReport() {
      this.error = '';
      if (this.globalData) {
        this.report = this.globalData;
        return;
      }

      this.loading = true;
      try {
        const data = await api.site.getGlobalCertificate();
        this.report = data;
      } catch (err) {
        this.error = err.message || 'No se pudo obtener el certificado global consolidado de la sede.';
      } finally {
        this.loading = false;
      }
    },

    printCertificate() {
      window.print();
    },

    close() {
      this.$emit('update:modelValue', false);
      this.$emit('close');
    }
  },
  template: `
    <div
      v-if="modelValue"
      class="vg-modal-backdrop"
      style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(15, 23, 42, 0.65); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px; overflow-y: auto;"
      @click.self="close"
    >
      <div
        class="vg-card vg-cert-printable-container"
        id="printable-global-cert"
        style="background: #ffffff; width: 100%; max-width: 900px; border-radius: var(--radius-card, 8px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); overflow: hidden; position: relative; margin: auto; max-height: 90vh; display: flex; flex-direction: column;"
      >
        <!-- Modal Top Actions Bar (No print) -->
        <div
          class="no-print"
          style="display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; background-color: #f8fafc; border-bottom: 1px solid var(--color-hairline, #c8cfda);"
        >
          <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; font-weight: 600; color: #475569;">
            Certificado Global Consolidado de Sede (A4)
          </div>
          <div style="display: flex; gap: 8px;">
            <button
              v-if="report && !error"
              type="button"
              class="vg-btn vg-btn-primary"
              style="font-size: 12px; height: 32px; padding: 0 12px; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px;"
              @click="printCertificate"
            >
              <span>🖨️ Imprimir Certificado Consolidado (A4)</span>
            </button>
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              style="font-size: 12px; height: 32px; padding: 0 12px; border-radius: var(--radius-interactive, 4px);"
              @click="close"
            >
              Cerrar
            </button>
          </div>
        </div>

        <!-- Scrollable Document Body -->
        <div style="padding: 28px 32px; overflow-y: auto; flex: 1;">
          <!-- Loading State -->
          <div v-if="loading" style="text-align: center; padding: 48px 16px;">
            <div style="font-size: 24px; margin-bottom: 8px;">🔄</div>
            <p style="font-family: var(--font-body, Inter, sans-serif); color: var(--color-slate, #2c333f); font-size: 14px;">
              Generando certificado global consolidado de sede...
            </p>
          </div>

          <!-- Error Alert -->
          <div
            v-else-if="error"
            style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 16px 20px; border-radius: var(--radius-interactive, 4px); font-family: var(--font-body, Inter, sans-serif); font-size: 14px;"
            role="alert"
          >
            <div style="font-weight: 700; margin-bottom: 4px;">Error en Emisión de Certificado Global</div>
            <div>{{ error }}</div>
          </div>

          <!-- Global Certificate A4 Content -->
          <div
            v-else-if="report"
            class="vg-cert-page"
            style="border: 2px solid #0284c7; border-radius: 6px; padding: 24px 28px; background: #ffffff; color: #1e293b; font-family: 'Segoe UI', Arial, sans-serif;"
          >
            <!-- Header -->
            <div style="text-align: center; border-bottom: 2px solid #0284c7; padding-bottom: 16px; margin-bottom: 20px;">
              <div style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 6px;">
                <span style="font-size: 22px;">🏢</span>
                <span style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 18px; font-weight: 800; letter-spacing: 0.05em; color: #0369a1;">
                  VENDGUARD · AUDITORÍA SANITARIA CONSOLIDADA
                </span>
              </div>
              <h1 style="margin: 0; color: #0f172a; font-size: 20px; font-weight: 800; text-transform: uppercase;">
                Certificado Global de Conformidad Higiénico-Sanitaria de Sede
              </h1>
              <p style="margin: 6px 0 0; color: #64748b; font-size: 12px;">
                Acreditación general de aptitud higiénica para centros con parque de máquinas expendedoras instaladas
              </p>
              
              <!-- Certificate Code Badge -->
              <div style="margin-top: 12px; display: inline-flex; align-items: center; gap: 8px; padding: 4px 14px; background: #e0f2fe; border: 1px solid #7dd3fc; border-radius: 20px; font-size: 13px; font-weight: 700; color: #0369a1;">
                <span>EXPEDIENTE CONSOLIDADO:</span>
                <span style="font-family: monospace;">{{ report.global_certificate_code }}</span>
              </div>
            </div>

            <!-- Site Identification & Issue Date -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 18px; margin-bottom: 20px; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; font-size: 13px;">
              <div>
                <div><strong>Edificio / Centro:</strong> {{ report.location?.name }}</div>
                <div style="margin-top: 3px;"><strong>Dirección:</strong> {{ report.location?.address }}</div>
                <div style="margin-top: 3px;"><strong>Código de Sede:</strong> <span style="font-family: monospace; font-weight: 700;">{{ report.location?.site_code }}</span></div>
              </div>
              <div style="text-align: right;">
                <div><strong>Fecha de Emisión:</strong> {{ formattedIssueDate }}</div>
                <div style="margin-top: 3px; color: #64748b;">Parque Total Auditado: <strong>{{ machinesBreakdown.length }}</strong> máquina(s)</div>
              </div>
            </div>

            <!-- Global Verdict Prominent Banner -->
            <div
              :style="{
                backgroundColor: verdictBadgeInfo.bg,
                border: '2px solid ' + verdictBadgeInfo.border,
                color: verdictBadgeInfo.color,
                borderRadius: '6px',
                padding: '16px 20px',
                marginBottom: '24px'
              }"
            >
              <div style="display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 800; letter-spacing: 0.02em;">
                <span>{{ verdictBadgeInfo.icon }}</span>
                <span>{{ verdictBadgeInfo.label }}</span>
              </div>
              <p style="margin: 8px 0 0; font-size: 13px; line-height: 1.5; font-weight: 500;">
                {{ report.verdict_explanation || (isCondicionado ? 'El centro dispone de una o más máquinas en cuarentena sanitaria o vencidas que condicionan la aptitud higiénica global hasta su total subsanación técnica.' : 'Todas las unidades instaladas en el centro cumplen satisfactoriamente con la normativa higiénico-sanitaria vigente.') }}
              </p>
            </div>

            <!-- Machines Breakdown Table -->
            <div style="margin-bottom: 24px;">
              <h3 style="margin: 0 0 10px; font-size: 14px; color: #0369a1; text-transform: uppercase; font-weight: 700;">
                Desglose Detallado del Parque de Máquinas
              </h3>
              <table style="width: 100%; border-collapse: collapse; font-size: 12px; background: #ffffff;">
                <thead>
                  <tr style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1;">
                    <th style="padding: 8px 10px; text-align: left; color: #475569; font-weight: 600;">Máquina</th>
                    <th style="padding: 8px 10px; text-align: left; color: #475569; font-weight: 600;">Tipo</th>
                    <th style="padding: 8px 10px; text-align: left; color: #475569; font-weight: 600;">Ubicación</th>
                    <th style="padding: 8px 10px; text-align: center; color: #475569; font-weight: 600;">Dictamen</th>
                    <th style="padding: 8px 10px; text-align: left; color: #475569; font-weight: 600;">Detalle / Observaciones</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="mach in machinesBreakdown"
                    :key="mach.code"
                    :style="{
                      borderBottom: '1px solid #e2e8f0',
                      backgroundColor: mach.quarantine ? '#fef2f2' : 'transparent'
                    }"
                  >
                    <td style="padding: 8px 10px; font-family: monospace; font-weight: 700; color: #0f172a;">
                      {{ mach.code }}
                    </td>
                    <td style="padding: 8px 10px; color: #475569;">
                      {{ mach.machine_type }}
                    </td>
                    <td style="padding: 8px 10px; color: #475569;">
                      {{ mach.floor_wing || 'General' }}
                    </td>
                    <td style="padding: 8px 10px; text-align: center;">
                      <span
                        v-if="mach.quarantine || mach.verdict === 'NO_CONFORME'"
                        style="background: #fee2e2; color: #b91c1c; font-weight: 800; padding: 2px 8px; border-radius: 4px; font-size: 11px; display: inline-block;"
                      >
                        🛑 NO CONFORME
                      </span>
                      <span
                        v-else-if="mach.verdict === 'CONFORME'"
                        style="background: #dcfce7; color: #15803d; font-weight: 700; padding: 2px 8px; border-radius: 4px; font-size: 11px; display: inline-block;"
                      >
                        CONFORME
                      </span>
                      <span
                        v-else
                        style="background: #f1f5f9; color: #64748b; font-weight: 600; padding: 2px 8px; border-radius: 4px; font-size: 11px; display: inline-block;"
                      >
                        {{ mach.verdict || 'PENDIENTE' }}
                      </span>
                    </td>
                    <td style="padding: 8px 10px; color: mach.quarantine ? '#991b1b' : '#334155'; font-size: 11.5px;">
                      {{ mach.detail || '-' }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Legal Sanitary Sign-off Footer -->
            <div style="border-top: 2px solid #0284c7; padding-top: 14px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; font-size: 11px; color: #64748b;">
              <div>
                <strong>Aviso Legal de Responsabilidad:</strong> Este certificado refleja el estado técnico e higiénico auditado in situ
                conforme a las directrices de Sanidad y Buenas Prácticas de Distribución Automática.
              </div>
              <div style="text-align: right; font-family: monospace; color: #94a3b8;">
                VENDGUARD CONSOLIDATED AUDIT SYSTEM
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
};

export default SiteGlobalCertificateModal;
