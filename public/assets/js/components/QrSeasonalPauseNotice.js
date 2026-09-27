/**
 * VendGuard - QrSeasonalPauseNotice (QrSeasonalPauseNotice.js)
 * 
 * Componente informativo desplegado tras escaneo QR de una máquina en Pausa Estacional (RF-PREV-01, EARS 1.5, Decisión 9).
 * 
 * Propósito:
 * - Informar de forma transparente de la suspensión temporal del servicio por vacaciones del centro.
 * - Confirmar la ausencia de productos perecederos en el interior de la unidad.
 * - Desactivar el formulario de averías para evitar falsos reportes de clientes.
 * - Dogma Vanilla: Componente Vue 3 ESM puro.
 * - Dualismo Lingüístico: Código en inglés; mensajes al ciudadano en español.
 */

export const QrSeasonalPauseNotice = {
  name: 'QrSeasonalPauseNotice',
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
    }
  },
  emits: ['go-home'],
  computed: {
    alertTitle() {
      return this.alert?.title || 'DISPOSITIVO EN PAUSA ESTACIONAL PROGRAMADA';
    },
    alertMessage() {
      return this.alert?.message || 'Esta máquina se encuentra vacía de productos perecederos por periodo vacacional. Reanudará el servicio tras revisión sanitaria previa.';
    }
  },
  methods: {
    handleGoHome() {
      this.$emit('go-home');
    }
  },
  template: `
    <div
      class="qr-card qr-seasonal-pause-card"
      data-testid="seasonal-pause-card"
      style="background-color: #f0f9ff; border: 2px solid #38bdf8; border-radius: var(--radius-card, 8px); padding: 24px 20px; box-shadow: 0 4px 6px -1px rgba(56, 189, 248, 0.15); text-align: center; margin-bottom: 24px;"
    >
      <!-- Vacation / Pause Icon -->
      <div style="margin-bottom: 12px; display: inline-flex; align-items: center; justify-content: center; width: 60px; height: 60px; border-radius: 50%; background-color: #e0f2fe; border: 2px solid #7dd3fc;">
        <span style="font-size: 32px; line-height: 1;">🏖️</span>
      </div>

      <!-- Blue Badge -->
      <div style="margin-bottom: 12px;">
        <span
          class="qr-pause-badge"
          style="display: inline-block; background-color: #0284c7; color: #ffffff; font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 12px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; padding: 4px 12px; border-radius: 20px;"
        >
          PAUSA ESTACIONAL VACACIONAL
        </span>
      </div>

      <!-- Alert Title -->
      <h2
        class="qr-pause-title"
        data-testid="seasonal-pause-title"
        style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 18px; font-weight: 800; color: #0369a1; text-transform: uppercase; line-height: 1.3; margin: 0 0 12px 0;"
      >
        {{ alertTitle }}
      </h2>

      <!-- Explanatory Notice Box -->
      <div
        class="qr-pause-notice-box"
        data-testid="seasonal-pause-message"
        style="background-color: #ffffff; border: 1px solid #bae6fd; border-radius: 6px; padding: 14px 16px; margin-bottom: 18px; text-align: left;"
      >
        <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 13.5px; font-weight: 500; color: #0c4a6e; margin: 0; line-height: 1.5;">
          {{ alertMessage }}
        </p>
      </div>

      <!-- Machine Details Chip -->
      <div
        class="qr-machine-chip-card"
        style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 14px; margin-bottom: 18px; text-align: left;"
      >
        <div style="font-family: monospace; font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 2px;">
          [{{ machine?.code || 'VEND-CODE' }}] {{ machine?.model || '' }}
        </div>
        <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: #475569;">
          📍 {{ location?.name || 'Sede' }} <span v-if="machine?.floor_wing">({{ machine.floor_wing }})</span>
        </div>
      </div>

      <!-- Note for Users -->
      <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: #64748b; margin: 0 0 20px 0; line-height: 1.4;">
        No es necesario registrar avisos de avería: la unidad reanudará su servicio automáticamente tras la inspección preventiva reglamentaria previa.
      </p>

      <!-- Action Button -->
      <button
        type="button"
        class="btn btn-primary qr-btn-block"
        style="width: 100%; height: 40px; font-size: 14px; font-weight: 600; border-radius: var(--radius-interactive, 4px); background-color: #0284c7; border-color: #0369a1; color: #ffffff;"
        @click="handleGoHome"
      >
        Entendido
      </button>
    </div>
  `
};

export default QrSeasonalPauseNotice;
