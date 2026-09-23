/**
 * VendGuard - QrInactiveMachineNotice Component (QrInactiveMachineNotice.js)
 * 
 * Componente informativo público que se despliega ante la lectura ciudadana del código QR
 * de una máquina dispensadora que ha sido dada de baja lógica o retirada temporalmente (RF-04, EARS 2.12).
 * 
 * Características:
 * 1. Tarjeta visual informativa amigable con iconografía clara de fuera de servicio.
 * 2. Visualización de los metadatos de la máquina identificada (código, modelo, ubicación física).
 * 3. Ocultación total y terminante del formulario de reporte de averías.
 * 4. Mensaje oficial de cortesía indicando que no es posible reportar incidencias sobre el dispositivo.
 * 5. Canales de soporte o contacto de sede si están disponibles.
 * 6. Botón de navegación para regresar a la portada o cerrar la vista.
 * 
 * Dogma Vanilla: Vue 3 Options API en módulos ESM nativos sin dependencias npm externas.
 */

export const QrInactiveMachineNotice = {
  name: 'QrInactiveMachineNotice',
  props: {
    machine: {
      type: Object,
      default: () => ({})
    },
    location: {
      type: [Object, String],
      default: null
    },
    message: {
      type: String,
      default: 'Esta máquina de vending se encuentra temporalmente retirada o fuera de servicio. No es posible registrar nuevas incidencias sobre este dispositivo.'
    },
    supportPhone: {
      type: String,
      default: ''
    }
  },
  emits: ['go-home', 'close'],
  computed: {
    locationName() {
      if (typeof this.location === 'string') return this.location;
      if (this.location && typeof this.location === 'object') {
        return this.location.name || this.location.location_name || '';
      }
      return this.machine?.location_name || '';
    },
    effectivePhone() {
      if (this.supportPhone) return this.supportPhone;
      if (this.location && typeof this.location === 'object' && this.location.contact_phone) {
        return this.location.contact_phone;
      }
      return '';
    },
    displayFloorWing() {
      return this.machine?.floor_wing ? `(${this.machine.floor_wing})` : '';
    }
  },
  methods: {
    handleGoHome() {
      this.$emit('go-home');
    }
  },
  template: `
    <div class="qr-card qr-inactive-notice-card" data-testid="inactive-machine-card" style="max-width: 520px; margin: 0 auto; text-align: center; border-radius: var(--radius-card, 8px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); background: #ffffff; padding: 2rem 1.5rem;">
      <!-- Icono e Indicador de Fuera de Servicio -->
      <div class="qr-inactive-icon-wrap" style="width: 72px; height: 72px; margin: 0 auto 1.25rem; border-radius: 50%; background: #fff3cd; color: #856404; display: flex; align-items: center; justify-content: center; font-size: 2.25rem; border: 2px solid #ffeeba;">
        ⏸️
      </div>

      <!-- Título Amigable -->
      <h2 class="qr-card-title fw-bold text-dark mb-2" style="font-size: 1.4rem; letter-spacing: -0.02em;">
        Máquina Fuera de Servicio
      </h2>
      <p class="text-muted small mb-3">
        Unidad temporalmente retirada del parque activo
      </p>

      <!-- Ficha Contextual de la Máquina Bloqueada -->
      <div class="qr-inactive-chip mb-3 p-2 bg-light rounded text-start d-flex align-items-center justify-content-between" style="border: 1px solid #e9ecef;">
        <div>
          <span class="font-monospace fw-bold text-dark me-2">[{{ machine?.code || 'MÁQUINA' }}]</span>
          <span class="text-secondary small">{{ machine?.model || '' }}</span>
          <div v-if="locationName" class="text-muted small mt-1">
            📍 {{ locationName }} {{ displayFloorWing }}
          </div>
        </div>
        <div>
          <span class="badge bg-secondary" style="font-size: 0.75rem;">Inactiva</span>
        </div>
      </div>

      <!-- Mensaje Oficial de Explicación y Bloqueo de Reporte -->
      <div class="alert alert-warning text-start mb-3" style="font-size: 0.9rem; line-height: 1.5;" data-testid="inactive-notice-message">
        <div class="d-flex align-items-start gap-2">
          <span>ℹ️</span>
          <div>
            <strong>Aviso de servicio:</strong>
            <p class="mb-0 mt-1">
              {{ message }}
            </p>
          </div>
        </div>
      </div>

      <!-- Banner de Bloqueo de Reportes -->
      <div class="p-2 mb-4 rounded bg-light border text-muted small d-flex align-items-center justify-content-center gap-2">
        <span>🔒</span>
        <span>El formulario de averías está deshabilitado para esta máquina.</span>
      </div>

      <!-- Canales de Asistencia -->
      <div v-if="effectivePhone" class="qr-support-box mb-3 small text-muted">
        <span>Para incidencias de sede o consultas generales:</span>
        <div class="mt-1">
          <a :href="'tel:' + effectivePhone" class="btn btn-outline-secondary btn-sm fw-bold">
            📞 Llamar a Asistencia ({{ effectivePhone }})
          </a>
        </div>
      </div>

      <!-- Acción Principal: Portada -->
      <div class="mt-4">
        <button 
          type="button" 
          class="btn btn-primary w-100 py-2 fw-semibold" 
          @click="handleGoHome"
          data-testid="btn-inactive-home"
        >
          Volver a la portada del servicio
        </button>
      </div>
    </div>
  `
};

export default QrInactiveMachineNotice;
