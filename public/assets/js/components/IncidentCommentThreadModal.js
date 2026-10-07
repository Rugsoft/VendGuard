/**
 * VendGuard - IncidentCommentThreadModal (IncidentCommentThreadModal.js)
 *
 * Hilo de conversación bidireccional del expediente, con notas internas de taller
 * confidenciales (Módulo 10, RF-01 a RF-07 de specs/10-incident-comments/spec.md).
 *
 * Estructura maquetada (T-COM-09):
 * 1. Cabecera contextual FIJA (RF-01.4): código de ticket en tipografía monoespaciada,
 *    máquina, sede, insignia de estado y canal de uso de quien abre el hilo (`role`).
 * 2. Cuerpo central con scroll vertical independiente y lienzo del hilo (RNF-03).
 * 3. Pie FIJO con la acción de cierre (RNF-03).
 *
 * Alcance de esta tarea: la carcasa estructural, el contrato de props/eventos y el
 * ciclo de apertura/cierre. El visor cronológico con bocadillos diferenciados y la
 * carga del hilo llegan en T-COM-10 (poblarán `thread`), el formulario reactivo con
 * el selector de privacidad en T-COM-11 (emitirá `comment-added`) y el modo sellado
 * junto al guardián de formulario sucio en T-COM-12 (interceptarán `requestClose()`).
 *
 * Mientras `thread` es null la cabecera trabaja con el identificador recibido por
 * props y el cuerpo muestra el estado vacío de la especificación (§6.1): al no
 * existir todavía ninguna carga en el scaffold, no hay dato inventado que mostrar.
 *
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias externas).
 * Dualismo Lingüístico: código en inglés, interfaz y mensajes en español.
 */

import { IncidentBadge } from './IncidentBadge.js';

/** Canales autorizados del hilo; un perfil desconocido no debe poder abrirlo. */
const ALLOWED_ROLES = ['SITE_MANAGER', 'TECHNICIAN', 'COORDINATOR'];

/** Etiqueta castellana del canal activo mostrada en la cabecera (RF-01.4). */
const ROLE_LABELS = {
  SITE_MANAGER: 'Responsable de Sede',
  TECHNICIAN: 'Técnico de Ruta',
  COORDINATOR: 'Coordinación'
};

export const IncidentCommentThreadModal = {
  name: 'IncidentCommentThreadModal',
  components: {
    IncidentBadge
  },
  props: {
    /**
     * Visibilidad del modal, controlada por la vista que abre el hilo (RF-01.1).
     */
    isOpen: {
      type: Boolean,
      default: false
    },
    /**
     * ID primario de la incidencia: identifica el expediente cuando la vista no
     * dispone del código de ticket.
     */
    incidentId: {
      type: [Number, String],
      default: null
    },
    /**
     * Código de ticket visible (ej: 'TICK-2026-00142'); admite el prefijo '#'.
     */
    ticketCode: {
      type: [String, Number],
      default: null
    },
    /**
     * Canal que usa el hilo ('SITE_MANAGER' | 'TECHNICIAN' | 'COORDINATOR'): decide la
     * segregación de notas internas, la identidad visible y el selector de privacidad
     * del formulario (RF-02.1, RF-02.3, RF-03.2, RF-03.3).
     */
    role: {
      type: String,
      default: 'SITE_MANAGER',
      validator: (value) => ALLOWED_ROLES.includes(value)
    }
  },
  emits: ['close', 'comment-added'],
  data() {
    return {
      /**
       * `IncidentCommentThreadDto` del expediente. Lo poblará T-COM-10 al cargar el
       * hilo; hasta entonces la cabecera se apoya en el identificador de las props y
       * el cuerpo muestra el estado vacío.
       */
      thread: null
    };
  },
  computed: {
    /**
     * Código de ticket de la cabecera: prioriza el del hilo cargado y cae al prop
     * `ticketCode` y al `incidentId` mientras no haya dato del servidor.
     */
    headerTicketCode() {
      const raw = this.thread?.incident?.ticket_code ?? this.ticketCode ?? this.incidentId;

      if (raw === null || raw === undefined || String(raw).trim() === '') {
        return '#—';
      }

      return `#${String(raw).trim().replace(/^#/, '')}`;
    },

    /**
     * Máquina del expediente con su modelo (ej: 'VEN-BCN-001 · CoffeMax Pro 3000').
     */
    headerMachineLabel() {
      const code = this.thread?.incident?.machine_code;
      const model = this.thread?.incident?.machine_model;

      if (!code) {
        return '';
      }

      return model ? `${code} · ${model}` : String(code);
    },

    /**
     * Sede del expediente: el portal de centro y la ruta necesitan saber de qué
     * ubicación es la avería antes de escribir (RF-01.4).
     */
    headerLocationLabel() {
      return this.thread?.incident?.location_name ? String(this.thread.incident.location_name) : '';
    },

    /**
     * Estado del expediente para la insignia de la cabecera; vacío mientras el hilo
     * no está cargado (la insignia no debe inventar un estado).
     */
    headerStatus() {
      return this.thread?.incident?.status ? String(this.thread.incident.status) : '';
    },

    /**
     * Canal activo del hilo en castellano para la cabecera.
     */
    roleLabel() {
      return ROLE_LABELS[this.role] || 'Canal no reconocido';
    }
  },
  watch: {
    isOpen: {
      immediate: true,
      handler(isOpen) {
        this.handleOpenState(isOpen);
      }
    }
  },
  beforeUnmount() {
    this.releaseScrollLock();
  },
  methods: {
    /**
     * Sincroniza los efectos de apertura/cierre: bloqueo del scroll de fondo y atajo
     * `Escape`. El guardián de formulario sucio (T-COM-12) refinará el cierre a través
     * de `requestClose()` en lugar de tocar estos eventos.
     */
    handleOpenState(isOpen) {
      if (typeof document !== 'undefined') {
        document.body.style.overflow = isOpen ? 'hidden' : '';
      }

      if (typeof window !== 'undefined') {
        if (isOpen) {
          window.addEventListener('keydown', this.handleKeyDown);
        } else {
          window.removeEventListener('keydown', this.handleKeyDown);
        }
      }
    },

    /**
     * Solicita el cierre del modal: el componente padre decide desmontarlo. T-COM-12
     * interceptará esta señal para preguntar antes de descartar un borrador.
     */
    requestClose() {
      this.$emit('close');
    },

    handleBackdropClick(event) {
      if (event.target === event.currentTarget) {
        this.requestClose();
      }
    },

    handleKeyDown(event) {
      if (event.key === 'Escape' && this.isOpen) {
        this.requestClose();
      }
    },

    /**
     * Libera el bloqueo de scroll y el atajo de teclado (cierre y desmontaje).
     */
    releaseScrollLock() {
      if (typeof document !== 'undefined') {
        document.body.style.overflow = '';
      }

      if (typeof window !== 'undefined') {
        window.removeEventListener('keydown', this.handleKeyDown);
      }
    }
  },
  template: `
    <Teleport to="body" :disabled="typeof document === 'undefined'">
      <div
        v-if="isOpen"
        class="vg-modal-backdrop incident-comment-backdrop"
        data-testid="incident-comment-backdrop"
        role="presentation"
        style="padding: 24px;"
        @click.self="handleBackdropClick"
      >
        <div
          class="vg-modal-container incident-comment-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="incident-comment-title"
          tabindex="-1"
          data-testid="incident-comment-modal"
          style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); box-shadow: var(--shadow-modal, 0 12px 32px rgba(0, 0, 0, 0.12)); width: min(680px, 100%); height: min(86vh, 760px); max-height: 92vh; display: flex; flex-direction: column; overflow: hidden; outline: none;"
        >
          <!-- CABECERA CONTEXTUAL FIJA (RF-01.4) -->
          <header
            class="modal-header incident-comment-header"
            data-testid="incident-comment-header"
            style="flex: 0 0 auto; gap: 12px; background-color: #ffffff;"
          >
            <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
              <h2
                id="incident-comment-title"
                class="font-monospace incident-comment-code"
                data-testid="incident-comment-code"
                style="font-size: 15px; font-weight: 600; color: var(--color-ink, #000000); margin: 0; white-space: nowrap;"
              >
                {{ headerTicketCode }}
              </h2>
              <span
                class="incident-comment-channel"
                data-testid="incident-comment-channel"
                style="font-family: var(--font-body, Inter, sans-serif); font-size: 11px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; color: var(--color-ink-muted, #6c7e9d); border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); padding: 2px 6px; white-space: nowrap;"
              >
                {{ roleLabel }}
              </span>
            </div>

            <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
              <div
                v-if="thread"
                class="incident-comment-context"
                data-testid="incident-comment-context"
                style="display: flex; flex-direction: column; align-items: flex-end; gap: 2px; min-width: 0;"
              >
                <span
                  data-testid="incident-comment-machine"
                  style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 600; color: var(--color-slate, #2c333f); max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                >
                  {{ headerMachineLabel }}
                </span>
                <span
                  data-testid="incident-comment-location"
                  style="font-family: var(--font-body, Inter, sans-serif); font-size: 11px; color: var(--color-ink-muted, #6c7e9d); max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                >
                  {{ headerLocationLabel }}
                </span>
              </div>

              <IncidentBadge v-if="headerStatus" :value="headerStatus" type="status" size="sm" />

              <button
                type="button"
                class="vg-btn vg-btn-secondary incident-comment-close"
                data-testid="incident-comment-close"
                aria-label="Cerrar el hilo de conversación"
                @click="requestClose"
              >
                ✕
              </button>
            </div>
          </header>

          <!-- CUERPO CON SCROLL VERTICAL INDEPENDIENTE (RNF-03) -->
          <main
            class="modal-body incident-comment-body"
            data-testid="incident-comment-body"
            style="flex: 1 1 auto; min-height: 0; overflow-y: auto; background-color: var(--color-canvas, #f9fafb);"
          >
            <section
              v-if="!thread"
              class="incident-comment-empty"
              data-testid="incident-comment-empty"
              style="padding: 40px 16px; text-align: center; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-muted, #6c7e9d);"
            >
              Aún no hay mensajes en esta incidencia. Inicie la conversación con el equipo técnico.
            </section>

            <section
              v-else
              class="incident-comment-thread"
              data-testid="incident-comment-thread"
              aria-live="polite"
            >
              <!-- Visor cronológico, bocadillos y "Cargar mensajes anteriores": T-COM-10 -->
            </section>
          </main>

          <!-- PIE FIJO (RNF-03) -->
          <footer
            class="modal-footer incident-comment-footer"
            data-testid="incident-comment-footer"
            style="flex: 0 0 auto; background-color: #ffffff; justify-content: flex-end;"
          >
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              data-testid="incident-comment-footer-close"
              @click="requestClose"
            >
              Cerrar
            </button>
          </footer>
        </div>
      </div>
    </Teleport>
  `
};

export default IncidentCommentThreadModal;
