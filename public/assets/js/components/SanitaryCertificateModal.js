/**
 * VendGuard - SanitaryCertificateModal (SanitaryCertificateModal.js)
 * 
 * Componente modal para visualización e impresión de Certificado Sanitario Oficial Individual (RF-PREV-07, EARS 7.1, 7.4).
 * Cumple con:
 * - RNF-03: Hoja de estilo nativa para impresión en formato estándar A4 (@media print).
 * - Art. V.4 / RNF-05: Privacidad innegociable de técnicos. Muestra exclusivamente Código de Operador Técnico Oficial (ej: OP-03)
 *   y nombre profesional, omitiendo cualquier dato sensible (DNI, teléfono personal).
 * - Dogma Vanilla: Vue 3 ESM puro, sin dependencias externas.
 * - Dualismo Lingüístico: Código y atributos en inglés; textos, avisos y certificado en español.
 */

import { api } from '../api.js';

export const SanitaryCertificateModal = {
  name: 'SanitaryCertificateModal',
  props: {
    modelValue: {
      type: Boolean,
      default: false
    },
    machineCode: {
      type: String,
      default: ''
    },
    certificateData: {
      type: Object,
      default: null
    }
  },
  emits: ['update:modelValue', 'close'],
  data() {
    return {
      loading: false,
      error: '',
      cert: null
    };
  },
  computed: {
    inspectorOperatorCode() {
      return this.cert?.inspector?.operator_code || 'OP-N/A';
    },
    inspectorName() {
      return this.cert?.inspector?.name || 'Técnico Oficial VendGuard';
    },
    isConforme() {
      const res = this.cert?.result || '';
      return res === 'CONFORME' || res === 'CONFORME_CON_OBSERVACIONES';
    },
    isSuspended() {
      return this.cert?.status === 'SUSPENDED';
    },
    formattedInspectionDate() {
      if (!this.cert?.inspection_date) return 'N/D';
      try {
        const d = new Date(this.cert.inspection_date);
        return d.toLocaleDateString('es-ES', {
          year: 'numeric',
          month: 'long',
          day: 'numeric',
          hour: '2-digit',
          minute: '2-digit'
        });
      } catch (e) {
        return this.cert.inspection_date;
      }
    },
    formattedValidUntil() {
      if (!this.cert?.valid_until) return 'N/D';
      try {
        const d = new Date(this.cert.valid_until);
        return d.toLocaleDateString('es-ES', {
          year: 'numeric',
          month: 'long',
          day: 'numeric'
        });
      } catch (e) {
        return this.cert.valid_until;
      }
    }
  },
  watch: {
    modelValue(newVal) {
      if (newVal) {
        this.initCertificate();
      }
    },
    machineCode() {
      if (this.modelValue) {
        this.initCertificate();
      }
    },
    certificateData: {
      immediate: true,
      handler(val) {
        if (val) {
          this.cert = val;
        }
      }
    }
  },
  methods: {
    async initCertificate() {
      this.error = '';
      if (this.certificateData) {
        this.cert = this.certificateData;
        return;
      }

      if (!this.machineCode) {
        this.cert = null;
        return;
      }

      this.loading = true;
      try {
        const data = await api.site.getMachineCertificate(this.machineCode);
        this.cert = data;
      } catch (err) {
        this.error = err.message || 'No se pudo cargar el certificado sanitario de la máquina solicitada.';
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
        id="printable-sanitary-cert"
        style="background: #ffffff; width: 100%; max-width: 820px; border-radius: var(--radius-card, 8px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); overflow: hidden; position: relative; margin: auto; max-height: 90vh; display: flex; flex-direction: column;"
      >
        <!-- Modal Top Actions Bar (No print) -->
        <div
          class="no-print"
          style="display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; background-color: #f8fafc; border-bottom: 1px solid var(--color-hairline, #c8cfda);"
        >
          <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; font-weight: 600; color: #475569;">
            Documento Oficial de Inspección Sanitaria (A4)
          </div>
          <div style="display: flex; gap: 8px;">
            <button
              v-if="cert && !error"
              type="button"
              class="vg-btn vg-btn-primary"
              style="font-size: 12px; height: 32px; padding: 0 12px; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px;"
              @click="printCertificate"
            >
              <span>🖨️ Imprimir / Guardar PDF (A4)</span>
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
              Cargando certificado sanitario oficial...
            </p>
          </div>

          <!-- Error Alert -->
          <div
            v-else-if="error"
            style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 16px 20px; border-radius: var(--radius-interactive, 4px); font-family: var(--font-body, Inter, sans-serif); font-size: 14px;"
            role="alert"
          >
            <div style="font-weight: 700; margin-bottom: 4px;">Aviso Sanitario (Art. II / RF-PREV-07)</div>
            <div>{{ error }}</div>
          </div>

          <!-- Certificate A4 Content -->
          <div
            v-else-if="cert"
            class="vg-cert-page"
            style="border: 2px solid #0284c7; border-radius: 6px; padding: 24px 28px; background: #ffffff; color: #1e293b; font-family: 'Segoe UI', Arial, sans-serif;"
          >
            <!-- Certificate Header -->
            <div style="text-align: center; border-bottom: 2px solid #0284c7; padding-bottom: 16px; margin-bottom: 20px;">
              <div style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 6px;">
                <span style="font-size: 22px;">🛡️</span>
                <span style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 18px; font-weight: 800; letter-spacing: 0.05em; color: #0369a1;">
                  VENDGUARD · PROTOCOLO HIGIÉNICO-SANITARIO
                </span>
              </div>
              <h1 style="margin: 0; color: #0f172a; font-size: 20px; font-weight: 800; text-transform: uppercase;">
                Certificado Oficial de Inspección y Aptitud Higiénica
              </h1>
              <p style="margin: 6px 0 0; color: #64748b; font-size: 12px;">
                Conforme al Artículo II de la Constitución de VendGuard y Reglamentación Sanitaria Técnico-Vending
              </p>
              
              <!-- Certificate Code Badge -->
              <div style="margin-top: 12px; display: inline-flex; align-items: center; gap: 8px; padding: 4px 14px; background: #e0f2fe; border: 1px solid #7dd3fc; border-radius: 20px; font-size: 13px; font-weight: 700; color: #0369a1;">
                <span>Nº REGISTRO:</span>
                <span style="font-family: monospace;">{{ cert.certificate_code }}</span>
              </div>

              <!-- Suspended Warning Badge if applicable -->
              <div
                v-if="isSuspended"
                style="margin-top: 10px; background-color: #fee2e2; border: 1px solid #f87171; color: #b91c1c; padding: 8px 12px; border-radius: 4px; font-size: 12px; font-weight: 700;"
              >
                ⚠️ ESTADO: CERTIFICADO SUSPENDIDO CAUTELARMENTE POR AVERÍA TÉCNICA SOBREVENIDA (EARS 7.3)
              </div>
            </div>

            <!-- Two-Column Grid: Machine Data & Location/Inspection Data -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 20px;">
              <!-- Machine Box -->
              <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px;">
                <h3 style="margin: 0 0 10px; font-size: 13px; color: #0369a1; text-transform: uppercase; font-weight: 700; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px;">
                  1. Unidad Dispensadora
                </h3>
                <div style="font-size: 13px; line-height: 1.6;">
                  <div><strong>Código de Máquina:</strong> <span style="font-family: monospace; font-weight: 700;">{{ cert.machine?.code }}</span></div>
                  <div><strong>Modelo:</strong> {{ cert.machine?.model || 'Estándar' }}</div>
                  <div><strong>Número de Serie:</strong> <span style="font-family: monospace;">{{ cert.machine?.serial_number || 'N/D' }}</span></div>
                  <div><strong>Tipología:</strong> {{ cert.machine?.machine_type || 'N/D' }}</div>
                </div>
              </div>

              <!-- Location & Inspection Result Box -->
              <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px;">
                <h3 style="margin: 0 0 10px; font-size: 13px; color: #0369a1; text-transform: uppercase; font-weight: 700; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px;">
                  2. Emplazamiento y Dictamen
                </h3>
                <div style="font-size: 13px; line-height: 1.6;">
                  <div><strong>Centro / Sede:</strong> {{ cert.location?.name }}</div>
                  <div><strong>Dirección:</strong> {{ cert.location?.address }}</div>
                  <div>
                    <strong>Dictamen Oficial:</strong>
                    <span
                      :style="{
                        fontWeight: '800',
                        color: isConforme ? '#15803d' : '#b91c1c'
                      }"
                    >
                      {{ cert.result || (isConforme ? 'CONFORME' : 'NO_CONFORME') }}
                    </span>
                  </div>
                  <div>
                    <strong>Temperatura Registrada:</strong>
                    <span style="font-weight: 700;">
                      {{ cert.temperature_measured !== null && cert.temperature_measured !== undefined ? cert.temperature_measured + ' °C' : 'N/A' }}
                    </span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Inspector Box (Art. V.4: Strict Operator Code) -->
            <div style="background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 16px; margin-bottom: 20px;">
              <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; font-size: 13px;">
                <div>
                  <span style="color: #475569; font-weight: 600;">Inspector Técnico Habilitado:</span>
                  <span style="font-weight: 700; margin-left: 6px;">{{ inspectorName }}</span>
                </div>
                <div>
                  <span style="color: #475569; font-weight: 600;">Código de Operador Oficial (Art. V.4):</span>
                  <span style="font-family: monospace; font-weight: 800; background: #e2e8f0; padding: 2px 8px; border-radius: 4px; margin-left: 6px; color: #0f172a;">
                    {{ inspectorOperatorCode }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Inspected Items Checklist Table -->
            <div style="margin-bottom: 20px;">
              <h3 style="margin: 0 0 8px; font-size: 13px; color: #0369a1; text-transform: uppercase; font-weight: 700;">
                3. Puntos Críticos Verificados en Auditoría
              </h3>
              <table style="width: 100%; border-collapse: collapse; font-size: 12px; background: #ffffff;">
                <thead>
                  <tr style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1;">
                    <th style="padding: 8px 12px; text-align: left; color: #475569; font-weight: 600;">Punto de Control Higiénico / Técnico</th>
                    <th style="padding: 8px 12px; text-align: right; color: #475569; font-weight: 600; width: 140px;">Resultado</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="(item, idx) in (cert.inspected_items || [])"
                    :key="idx"
                    style="border-bottom: 1px solid #e2e8f0;"
                  >
                    <td style="padding: 7px 12px; color: #334155;">
                      {{ item.item || item.title || 'Punto de Verificación' }}
                    </td>
                    <td style="padding: 7px 12px; text-align: right; font-weight: 700;">
                      <span
                        :style="{
                          color: (item.status === 'PASS' || item.status === 'CONFORME') ? '#15803d' : (item.status === 'WARN' ? '#b45309' : '#b91c1c')
                        }"
                      >
                        {{ item.status === 'PASS' ? 'CONFORME' : (item.status === 'WARN' ? 'ADVERTENCIA' : item.status) }}
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Validity and Legal Sign-off Footer -->
            <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px; border-top: 2px solid #0284c7; padding-top: 14px; font-size: 12px;">
              <div>
                <div><strong>Fecha de Inspección:</strong> {{ formattedInspectionDate }}</div>
                <div style="margin-top: 4px;">
                  <strong>Válido Hasta:</strong>
                  <span style="font-weight: 700; color: #0369a1;">{{ formattedValidUntil }}</span>
                </div>
              </div>
              <div style="text-align: right; color: #64748b;">
                <div style="font-weight: 700; color: #0369a1;">Sello y Firma Digital Certificada</div>
                <div style="font-size: 11px; margin-top: 2px;">Sistema VendGuard Sanidad v2.0</div>
                <div style="font-family: monospace; font-size: 10px; color: #94a3b8; margin-top: 2px;">
                  HASH-SHA256: VALID-{{ cert.certificate_code }}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
};

export default SanitaryCertificateModal;
