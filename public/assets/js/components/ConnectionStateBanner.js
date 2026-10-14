/**
 * VendGuard - ConnectionStateBanner (ConnectionStateBanner.js)
 *
 * Banner flotante global del estado de conexión (RF-04.3, RF-04.4, RNF-06; tarea T-HYBRID-16).
 *
 * EL PROBLEMA QUE RESUELVE
 *
 * El frontend dejó de hablar con una base de datos local para hablar con una remota, y esa
 * conversación puede cortarse: la red del centro cae, el móvil del técnico entra en un sótano
 * sin cobertura o la petición agota su tiempo de espera. Antes de este módulo, eso se
 * traducía en un error crudo y, con demasiada frecuencia, en trabajo perdido: el operario
 * redacta veinte líneas de diagnóstico y descubre que no se han guardado. La credibilidad de
 * todo el protocolo depende de que eso no ocurra, así que el sistema tiene que **decir la
 * verdad sobre la conexión** en el momento en que se pierde, sin tapar la pantalla y sin
 * llevarse por delante nada de lo escrito.
 *
 * QUÉ HACE
 *
 * Escucha el evento nativo `vendguard:network-status` que emite el cliente HTTP (T-HYBRID-17)
 * y traduce su contenido a tres estados visibles:
 *
 *  1. **`idle`** — no hay nada que contar y no se pinta nada. La raíz del componente sigue
 *     montada con su región viva, pero vacía y con `pointer-events: none`: una región viva que
 *     aparece y desaparece del DOM se anuncia tarde o no se anuncia, y a la vez un overlay que
 *     existe no puede quedarse con los clics de nadie.
 *  2. **`disconnected`** — aviso ámbar del sistema (`Art. IV`, RNF-06) con el motivo real si el
 *     transporte lo aporta, el recordatorio de que lo redactado **sigue en el formulario** y el
 *     botón táctil *Reintentar* (≥ 44 px, EARS-04.4). El suelo de 44 px es deliberado y excede
 *     el peldaño más alto del sistema de diseño (40 px): es la altura mínima de un objetivo
 *     táctil real en la mano del técnico, no un escalón de énfasis.
 *  3. **`reconnected`** — confirmación verde que se retira sola a los 3 segundos. Una
 *     confirmación que hay que cerrar a mano es una obligación más; ésta sólo existe para que
 *     el operario sepa que ya puede seguir.
 *
 * DE QUÉ NO ES RESPONSABLE
 *
 * El banner **no decide** si hay conexión ni cuándo se recupera: eso lo sabe el transporte, que
 * es quien ha visto el fallo y quien vuelve a intentarlo. Aquí no hay sondeos, ni reintentos de
 * fondo, ni una segunda verdad sobre el estado de la red. El botón no inventa una petición
 * nueva: invoca el callback de reintento que viaja en el propio evento, de modo que se repite
 * **exactamente** la operación que falló —con su borrador intacto— y es el transporte el que
 * anuncia el desenlace (`reconnected` si sale bien, `disconnected` otra vez si no).
 *
 * DOGMA VANILLA Y DISEÑO
 *
 * Módulo ES nativo de Vue 3 Options API, sin dependencias ni paso de construcción. Todo el
 * color entra por token (`var(--color-warning-bg)`, `var(--color-success-text)`, …): este
 * componente no aporta un solo literal hexadecimal, que es lo que mantiene congelada la deuda
 * visual que vigila `DesignTokenDebtRatchetTest`. Dualismo Lingüístico: código y nombres en
 * inglés, comentarios y mensajes al operario en castellano.
 */

/** Nombre canónico del evento nativo que publica el estado de la conexión. */
export const CONNECTION_STATUS_EVENT = 'vendguard:network-status';

/** Los tres estados visibles del banner. */
export const CONNECTION_STATES = Object.freeze({
  IDLE: 'idle',
  DISCONNECTED: 'disconnected',
  RECONNECTED: 'reconnected'
});

/** Vida de la confirmación verde, en milisegundos (EARS-04.3). */
export const RECONNECTED_NOTICE_MS = 3000;

/** Texto del aviso ámbar cuando el transporte no aporta un motivo propio. */
export const DISCONNECTED_NOTICE_FALLBACK =
  'Sin conexión con el servidor. Lo que has escrito sigue guardado en el formulario: revisa tu red y reintenta cuando la recuperes.';

/** Texto de la confirmación verde. */
export const RECONNECTED_NOTICE_TEXT = 'Conexión restablecida.';

/**
 * Ventana del navegador, o `null` cuando el módulo se evalúa fuera de él (pruebas en Node).
 *
 * @returns {Window|null}
 */
function hostWindow() {
  return typeof window !== 'undefined' && window ? window : null;
}

/**
 * Programa una retirada diferida del aviso, en la ventana si existe y en el reloj global si no.
 *
 * Se resuelve en el momento de la llamada —y no al cargar el módulo— para que una prueba pueda
 * sustituir el planificador sin tocar el componente.
 *
 * @param {Function} handler
 * @param {number} delayMs
 * @returns {*} Identificador del temporizador, para poder cancelarlo.
 */
function scheduleTimeout(handler, delayMs) {
  const host = hostWindow();
  if (host && typeof host.setTimeout === 'function') {
    return host.setTimeout(handler, delayMs);
  }
  return setTimeout(handler, delayMs);
}

/**
 * Cancela una retirada diferida programada con `scheduleTimeout()`.
 *
 * @param {*} timerId
 * @returns {void}
 */
function cancelTimeout(timerId) {
  const host = hostWindow();
  if (host && typeof host.clearTimeout === 'function') {
    host.clearTimeout(timerId);
    return;
  }
  clearTimeout(timerId);
}

export const ConnectionStateBanner = {
  name: 'ConnectionStateBanner',

  emits: ['retry'],

  data() {
    return {
      /** Estado visible: `idle` (oculto), `disconnected` o `reconnected`. */
      state: CONNECTION_STATES.IDLE,
      /** Motivo publicado por el transporte, si lo trajo. */
      notice: '',
      /** Callback de reintento de la operación que falló (viaja en el evento). */
      retryCallback: null,
      /** Un reintento en curso no se repite: el botón queda deshabilitado. */
      isRetrying: false,
      /** Temporizador de la confirmación verde (uno solo, siempre reemplazado). */
      idleTimer: null
    };
  },

  computed: {
    isDisconnected() {
      return this.state === CONNECTION_STATES.DISCONNECTED;
    },

    isReconnected() {
      return this.state === CONNECTION_STATES.RECONNECTED;
    },

    /** `idle` no pinta contenido: ahí el banner está oculto (EARS-04.3). */
    isVisible() {
      return this.isDisconnected || this.isReconnected;
    },

    /** Glifo del estado, decorativo para el lector de pantalla. */
    stateIcon() {
      return this.isDisconnected ? '⚠️' : '✅';
    },

    /** Texto del aviso: el motivo del transporte manda; si no lo trae, el del sistema. */
    noticeMessage() {
      if (this.isReconnected) {
        return this.notice !== '' ? this.notice : RECONNECTED_NOTICE_TEXT;
      }
      return this.notice !== '' ? this.notice : DISCONNECTED_NOTICE_FALLBACK;
    },

    /**
     * Capa que flota sobre la aplicación.
     *
     * `pointer-events: none` es la garantía de no interferencia: mientras el aviso está oculto
     * esta capa no captura un clic, y sólo su contenido —cuando existe— vuelve a habilitarlos.
     */
    overlayStyle() {
      return {
        position: 'fixed',
        top: '0',
        left: '0',
        width: '100%',
        zIndex: '9999',
        display: 'flex',
        justifyContent: 'center',
        pointerEvents: 'none'
      };
    },

    /** Superficie del aviso: ámbar del sistema cuando se pierde, verde cuando se recupera. */
    noticeStyle() {
      const surface = this.isDisconnected
        ? {
            backgroundColor: 'var(--color-warning-bg)',
            color: 'var(--color-warning-text)',
            borderBottom: '1px solid var(--color-warning-border)'
          }
        : {
            backgroundColor: 'var(--color-success-bg)',
            color: 'var(--color-success-text)',
            borderBottom: '1px solid var(--color-success)'
          };

      return {
        ...surface,
        display: 'flex',
        flexWrap: 'wrap',
        alignItems: 'center',
        justifyContent: 'center',
        gap: 'var(--spacing-md, 12px)',
        width: '100%',
        padding: 'var(--spacing-sm, 8px) var(--spacing-base, 20px)',
        borderBottomLeftRadius: 'var(--radius-sm, 8px)',
        borderBottomRightRadius: 'var(--radius-sm, 8px)',
        boxShadow: 'var(--shadow-card)',
        fontFamily: 'var(--font-body, Inter, sans-serif)',
        fontSize: 'var(--font-size-body-sm, 14px)',
        lineHeight: '1.4',
        pointerEvents: 'auto'
      };
    },

    messageStyle() {
      return {
        margin: '0',
        flex: '1 1 240px',
        minWidth: '0'
      };
    }
  },

  mounted() {
    const host = hostWindow();
    if (host && typeof host.addEventListener === 'function') {
      host.addEventListener(CONNECTION_STATUS_EVENT, this.handleNetworkStatus);
    }
  },

  beforeUnmount() {
    const host = hostWindow();
    if (host && typeof host.removeEventListener === 'function') {
      host.removeEventListener(CONNECTION_STATUS_EVENT, this.handleNetworkStatus);
    }
    this.clearIdleTimer();
  },

  methods: {
    /**
     * Traduce el evento del transporte a un estado visible.
     *
     * Un estado desconocido **no cambia nada**: el banner prefiere quedarse como estaba a
     * inventarse una conexión que nadie ha anunciado.
     *
     * @param {{detail?: {status?: string, message?: string, retry?: Function}|undefined}} event
     * @returns {void}
     */
    handleNetworkStatus(event) {
      const detail = event && typeof event === 'object' && event.detail && typeof event.detail === 'object'
        ? event.detail
        : {};
      const status = typeof detail.status === 'string' ? detail.status.toLowerCase() : '';

      if (status === CONNECTION_STATES.DISCONNECTED) {
        this.showDisconnected(detail);
        return;
      }

      if (status === CONNECTION_STATES.RECONNECTED) {
        this.showReconnected(detail);
        return;
      }

      if (status === CONNECTION_STATES.IDLE) {
        this.dismiss();
      }
    },

    /**
     * Muestra el aviso ámbar de desconexión.
     *
     * Un aviso repetido **actualiza** el motivo y el callback sin reiniciar nada: el banner no
     * parpadea por recibir dos veces el mismo hecho, y el callback más reciente es el que
     * apunta a la operación que de verdad quedó a medias.
     *
     * @param {{message?: string, retry?: Function}} detail
     * @returns {void}
     */
    showDisconnected(detail) {
      this.clearIdleTimer();
      this.state = CONNECTION_STATES.DISCONNECTED;
      this.notice = typeof detail.message === 'string' ? detail.message.trim() : '';
      this.retryCallback = typeof detail.retry === 'function' ? detail.retry : this.retryCallback;
      this.isRetrying = false;
    },

    /**
     * Muestra la confirmación verde y programa su retirada a los 3 segundos.
     *
     * @param {{message?: string}} detail
     * @returns {void}
     */
    showReconnected(detail) {
      this.clearIdleTimer();
      this.state = CONNECTION_STATES.RECONNECTED;
      this.notice = typeof detail.message === 'string' ? detail.message.trim() : '';
      this.retryCallback = null;
      this.isRetrying = false;
      this.idleTimer = scheduleTimeout(() => {
        this.idleTimer = null;
        this.state = CONNECTION_STATES.IDLE;
        this.notice = '';
      }, RECONNECTED_NOTICE_MS);
    },

    /** Vuelve al estado oculto, cancelando cualquier retirada diferida pendiente. */
    dismiss() {
      this.clearIdleTimer();
      this.state = CONNECTION_STATES.IDLE;
      this.notice = '';
      this.retryCallback = null;
      this.isRetrying = false;
    },

    clearIdleTimer() {
      if (this.idleTimer !== null) {
        cancelTimeout(this.idleTimer);
        this.idleTimer = null;
      }
    },

    /**
     * Reintenta la operación que falló, sin recargar la página (EARS-04.4).
     *
     * La invocación es la del callback publicado por el transporte: el mismo cuerpo, la misma
     * operación, el mismo borrador. Si el reintento vuelve a fallar, el banner **no se retira**
     * —sería mentir sobre una conexión que sigue rota— y el fallo se publica en `retry` para
     * quien quiera contarlo. Y si no hay ninguna operación a la que volver, el botón no finge
     * un reintento que nadie ha pedido: lo dice.
     *
     * @returns {Promise<void>}
     */
    async retry() {
      if (this.isRetrying) {
        return;
      }

      const callback = this.retryCallback;
      if (typeof callback !== 'function') {
        this.$emit('retry', {
          ok: false,
          message: 'No hay ninguna operación pendiente de reintento.'
        });
        return;
      }

      this.isRetrying = true;

      try {
        await callback();
        this.$emit('retry', { ok: true });
      } catch (error) {
        this.$emit('retry', {
          ok: false,
          message: error && error.message ? error.message : 'El reintento no ha podido completarse.'
        });
      } finally {
        this.isRetrying = false;
      }
    }
  },

  template: `
    <div
      class="vg-connection-overlay"
      data-testid="connection-state-banner"
      :data-state="state"
      :role="isDisconnected ? 'alert' : 'status'"
      :aria-live="isDisconnected ? 'assertive' : 'polite'"
      :style="overlayStyle"
    >
      <div
        v-if="isVisible"
        class="vg-connection-notice"
        data-testid="connection-state-notice"
        :style="noticeStyle"
      >
        <span aria-hidden="true" data-testid="connection-state-icon">{{ stateIcon }}</span>

        <p data-testid="connection-state-message" :style="messageStyle">{{ noticeMessage }}</p>

        <button
          v-if="isDisconnected"
          type="button"
          class="vg-btn vg-btn-warning"
          data-testid="connection-state-retry"
          :disabled="isRetrying"
          style="min-height: 44px; min-width: 44px; padding: 0 var(--spacing-base, 20px); flex: 0 0 auto;"
          @click="retry"
        >
          {{ isRetrying ? 'Reintentando…' : 'Reintentar' }}
        </button>
      </div>
    </div>
  `
};

export default ConnectionStateBanner;
