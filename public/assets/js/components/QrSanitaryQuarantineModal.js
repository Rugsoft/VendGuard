/**
 * VendGuard - QrSanitaryQuarantineModal (QrSanitaryQuarantineModal.js)
 * 
 * Componente público desplegado tras escaneo QR de una máquina en cuarentena sanitaria (RF-PREV-01, RF-PREV-04, Art. II).
 * 
 * Mandatos constitucionales cumplidos:
 * - Constitución Art. II: Principio de Precaución y Seguridad Alimentaria.
 *   Exhibe alerta visual prominente en rojo vivo advirtiendo de la prohibición absoluta de consumo.
 * - Bloqueo Total: Impide la compra, dispensación y apertura de nuevos reportes redundantes.
 * - Constitución Art. V.4: Muestra el expediente técnico activo (#INC-xxxx) sin exponer datos privados del técnico.
 * - Dogma Vanilla: Componente Vue 3 ESM puro sin dependencias de compiladores.
 * - Dualismo Lingüístico: Lógica y propiedades en inglés; avisos y textos en español.
 */

import { SANITARY_THERMAL_RISK_LABEL } from '../utils/IncidentStatusPermissions.js';

export const QrSanitaryQuarantineModal = {
  name: 'QrSanitaryQuarantineModal',
  props: {
    machine: {
      type: Object,
      default: () => ({})
    },
    location: {
      type: Object,
      default: () => ({})
    },
    alert: {
      type: Object,
      default: () => ({})
    },
    activeIncident: {
      type: Object,
      default: null
    }
  },
  emits: ['go-home'],
  computed: {
    alertTitle() {
      return this.alert?.title || 'MÁQUINA FUERA DE SERVICIO POR CONTROL HIGIÉNICO-SANITARIO';
    },
    alertMessage() {
      return this.alert?.message || 'Por imperativo del Artículo II de la Constitución (Seguridad Alimentaria), queda prohibida la adquisición y consumo de productos de esta unidad.';
    },
    ticketCode() {
      const code = this.activeIncident?.ticket_code || '';
      if (!code) return '';
      return code.startsWith('#') ? code : `#${code}`;
    },
    incidentStatusLabel() {
      return this.activeIncident?.status_label || 'Intervención técnica prioritaria en curso';
    },
    /**
     * Distintivo literal de riesgo térmico (RF-03.5.1, Art. II). El servidor publica
     * `alert.thermal_risk_label`; si por cualquier motivo no llegara, se cae a la
     * constante compartida con el portal de sede para que el aviso nunca se muestre
     * mudo ni con un copy divergente del que lee el responsable de la máquina.
     */
    thermalRiskLabel() {
      return this.alert?.thermal_risk_label || SANITARY_THERMAL_RISK_LABEL;
    }
  },
  methods: {
    handleGoHome() {
      this.$emit('go-home');
    }
  },
  template: `
    <div
      class="qr-card qr-quarantine-card"
      data-testid="quarantine-card"
      style="background-color: #fef2f2; border: 3px solid #dc2626; border-radius: var(--radius-card, 8px); padding: 24px 20px; box-shadow: 0 10px 15px -3px rgba(220, 38, 38, 0.25); text-align: center; margin-bottom: 24px;"
    >
      <!-- Prominent Stop Icon with Pulsing Effect -->
      <div style="margin-bottom: 12px; display: inline-flex; align-items: center; justify-content: center; width: 64px; height: 64px; border-radius: 50%; background-color: #fee2e2; border: 2px solid #ef4444;">
        <span style="font-size: 36px; line-height: 1;">🛑</span>
      </div>

      <!-- Critical Red Badge -->
      <div style="margin-bottom: 12px;">
        <span
          class="qr-quarantine-badge"
          style="display: inline-block; background-color: #dc2626; color: #ffffff; font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 12px; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; padding: 4px 12px; border-radius: 20px;"
        >
          ALERTA SANITARIA · ARTÍCULO II
        </span>
      </div>

      <!-- Thermal Risk Distinctive (RF-03.5.1, Art. II) -->
      <div
        class="qr-thermal-risk-chip"
        data-testid="quarantine-thermal-risk-badge"
        style="display: block; background-color: var(--color-urgency-critical-bg); border: 2px solid var(--color-urgency-critical); border-radius: 6px; padding: 10px 12px; margin-bottom: 16px; font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 14px; font-weight: 800; color: var(--color-error-text); line-height: 1.35;"
      >
        🌡️ {{ thermalRiskLabel }}
      </div>

      <!-- Alert Title -->
      <h2
        class="qr-quarantine-title"
        data-testid="quarantine-title"
        style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 19px; font-weight: 800; color: #991b1b; text-transform: uppercase; line-height: 1.3; margin: 0 0 12px 0;"
      >
        {{ alertTitle }}
      </h2>

      <!-- Mandatory Constitutional Warning Text (Art. II) -->
      <div
        class="qr-quarantine-notice-box"
        data-testid="quarantine-message"
        style="background-color: #ffffff; border: 1px solid #fca5a5; border-radius: 6px; padding: 14px 16px; margin-bottom: 18px; text-align: left;"
      >
        <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 14px; font-weight: 700; color: #b91c1c; margin: 0; line-height: 1.5;">
          {{ alertMessage }}
        </p>
      </div>

      <!-- Prohibitions Callout -->
      <div style="background-color: #fee2e2; border-radius: 6px; padding: 10px 14px; margin-bottom: 18px; font-family: var(--font-body, Inter, sans-serif); font-size: 12.5px; color: #7f1d1d; font-weight: 600;">
        ⛔ Dispensación de productos y cobros bloqueados automáticamente por seguridad.
      </div>

      <!-- Machine Identification Chip -->
      <div
        class="qr-machine-chip-card"
        style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 14px; margin-bottom: 18px; text-align: left;"
      >
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
          <span style="font-family: monospace; font-size: 13px; font-weight: 700; color: #0f172a;">
            [{{ machine?.code || 'VEND-CODE' }}] {{ machine?.model || '' }}
          </span>
          <span style="font-size: 11px; font-weight: 700; color: #dc2626; background: #fee2e2; padding: 2px 6px; border-radius: 4px;">
            En Cuarentena
          </span>
        </div>
        <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: #475569;">
          📍 {{ location?.name || 'Sede' }} <span v-if="machine?.floor_wing">({{ machine.floor_wing }})</span>
        </div>
      </div>

      <!-- Active Incident Info if Available (Art. V.4: Strict Operator Privacy) -->
      <div
        v-if="ticketCode"
        class="qr-incident-info-box"
        data-testid="quarantine-ticket-info"
        style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; margin-bottom: 20px; font-size: 12px; text-align: left;"
      >
        <div style="color: #475569; font-weight: 600; margin-bottom: 2px;">
          Incidencia técnica correctiva en curso:
        </div>
        <div style="display: flex; align-items: center; justify-content: space-between;">
          <strong style="font-family: monospace; font-size: 13px; color: #0369a1;">
            {{ ticketCode }}
          </strong>
          <span style="color: #15803d; font-weight: 700; font-size: 11px;">
            {{ incidentStatusLabel }}
          </span>
        </div>
      </div>

      <!-- Reporting Block Clarification -->
      <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: #64748b; margin: 0 0 20px 0; line-height: 1.4;">
        No es necesario remitir un nuevo aviso: el servicio técnico ya ha sido movilizado con máxima urgencia.
      </p>

      <!-- Action Button -->
      <button
        type="button"
        class="btn btn-primary qr-btn-block"
        style="width: 100%; height: 42px; font-size: 14px; font-weight: 700; border-radius: var(--radius-interactive, 4px); background-color: #dc2626; border-color: #b91c1c; color: #ffffff;"
        @click="handleGoHome"
      >
        Entendido / Salir
      </button>
    </div>
  `
};

export default QrSanitaryQuarantineModal;
