/**
 * VendGuard - IncidentCommentThreadModal (IncidentCommentThreadModal.js)
 *
 * Hilo de conversación bidireccional del expediente, con notas internas de taller
 * confidenciales (Módulo 10, RF-01 a RF-07 de specs/10-incident-comments/spec.md).
 *
 * Estructura:
 * 1. Cabecera contextual FIJA (RF-01.4): código de ticket en tipografía monoespaciada,
 *    máquina, sede, insignia de estado y canal de uso de quien abre el hilo (`role`).
 * 2. Cuerpo central con scroll vertical independiente (RNF-03) que alberga el visor
 *    cronológico de mensajes (T-COM-10).
 * 3. Pie FIJO con la acción de cierre (RNF-03).
 *
 * Visor cronológico (T-COM-10):
 * - Carga el hilo del expediente por el canal del usuario (`role`) y auto-desplaza el
 *   cuerpo hasta el mensaje más reciente (RF-01.2, RF-01.4).
 * - Bocadillos diferenciados: públicos en gris neutro, propios en azul suave y notas
 *   internas en ámbar de advertencia con candado y etiqueta (RF-02.3, RF-02.4, RNF-04).
 * - Miniaturas fotográficas con visor ampliado y recuadro de sustitución cuando la
 *   imagen no puede cargarse (RF-04.4).
 * - "Cargar mensajes anteriores" recupera bloques previos por cursor preservando el
 *   punto de lectura visual, sin saltos (RF-01.3).
 *
 * Pendiente en tareas siguientes: el formulario reactivo con el selector de privacidad
 * (T-COM-11, emitirá `comment-added` y refrescará el hilo con `loadThread()`) y el modo
 * sellado con el guardián de formulario sucio (T-COM-12, interceptará `requestClose()`).
 *
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias externas).
 * Dualismo Lingüístico: código en inglés, interfaz y mensajes en español.
 */

import { api } from '../api.js';
import { IncidentBadge } from './IncidentBadge.js';

/** Canales autorizados del hilo; un perfil desconocido no debe poder abrirlo. */
const ALLOWED_ROLES = ['SITE_MANAGER', 'TECHNICIAN', 'COORDINATOR'];

/** Etiqueta castellana del canal activo mostrada en la cabecera (RF-01.4). */
const ROLE_LABELS = {
  SITE_MANAGER: 'Responsable de Sede',
  TECHNICIAN: 'Técnico de Ruta',
  COORDINATOR: 'Coordinación'
};

/** Prefijo de endpoint del hilo por canal (registrados en T-COM-08). */
const CHANNEL_ENDPOINTS = {
  SITE_MANAGER: '/location/incidents',
  TECHNICIAN: '/technician/incidents',
  COORDINATOR: '/coordinator/incidents'
};

/** Bloque de mensajes por petición: los 50 últimos del expediente (RF-01.2, RNF-02). */
const THREAD_PAGE_SIZE = 50;

/** Longitud mínima y máxima del texto del comentario (RF-03.1). */
const MIN_COMMENT_LENGTH = 5;
const MAX_COMMENT_LENGTH = 1000;

/** Tamaño máximo de evidencia fotográfica adjunta: 5 MB (RF-04.2, Art. V.5). */
const MAX_PHOTO_SIZE_BYTES = 5 * 1024 * 1024;
const ACCEPTED_PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/** Paleta del visor: notas internas en ámbar de advertencia y públicos en gris neutro. */
const INTERNAL_BUBBLE_BACKGROUND = '#fef9c3';
const INTERNAL_BUBBLE_BORDER = '#fde047';
const PUBLIC_BUBBLE_BACKGROUND = '#e5e7eb';
const OWN_BUBBLE_BACKGROUND = 'var(--color-primary-subtle, #e5f2fc)';

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
       * `IncidentCommentThreadDto` del expediente tal y como lo entrega la API.
       */
      thread: null,
      /**
       * Mensajes del hilo en orden cronológico ascendente (el más antiguo primero).
       */
      comments: [],
      /** Paginación retrospectiva: hay bloques anteriores sin cargar (RF-01.3). */
      hasMoreBefore: false,
      /** Cursor del mensaje más antiguo cargado (`before_id` de la siguiente página). */
      oldestId: null,
      /** Evidencia gráfica ampliada en el visor integrado (RF-04.4). */
      expandedPhoto: null,
      /** Evidencias cuyo archivo no pudo cargarse: recuadro de sustitución. */
      brokenPhotos: {},
      isLoading: false,
      isLoadingPrevious: false,
      errorMessage: '',
      errorCode: '',
      /** Texto reactivo redactado por el usuario (5 a 1.000 caracteres, RF-03.1). */
      commentText: '',
      /**
       * Selector reactivo de privacidad (RF-03.3):
       * Preseleccionado en 'INTERNAL' por defecto para técnicos y coordinadores.
       */
      privacyChoice: 'INTERNAL',
      /** Archivo fotográfico seleccionado para adjuntar (RF-04.1). */
      photoFile: null,
      /** URL de objeto para previsualización inmediata de la foto seleccionada. */
      photoPreviewUrl: null,
      /** Error local de validación del formulario o archivo adjunto. */
      formError: '',
      /** Indicador de subida y envío en curso (RF-04.5). */
      isSubmitting: false
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
    },

    /**
     * Identificador del expediente con el que se consulta el hilo: el ID primario
     * cuando la vista lo conoce y, si no, el código de ticket.
     */
    threadIdentifier() {
      const identifier = this.incidentId !== null && this.incidentId !== '' ? this.incidentId : this.ticketCode;

      return identifier === null || identifier === undefined || String(identifier).trim() === ''
        ? null
        : String(identifier).trim();
    },

    /**
     * Recurso REST del hilo según el canal del usuario (plan.md §2.1).
     */
    channelEndpoint() {
      const base = CHANNEL_ENDPOINTS[this.role] || CHANNEL_ENDPOINTS.SITE_MANAGER;

      return this.threadIdentifier === null
        ? ''
        : `${base}/${encodeURIComponent(this.threadIdentifier)}/comments`;
    },

    /** Sin expediente identificado no hay nada que consultar. */
    hasIdentifier() {
      return this.channelEndpoint !== '';
    },

    /** Total de mensajes del expediente informado por el servidor (RF-01.1). */
    totalComments() {
      const total = this.thread?.pagination?.total_comments;

      return typeof total === 'number' ? total : this.comments.length;
    },

    /** El expediente está sellado en solo lectura (RF-05.3): lo usará T-COM-12. */
    isSealed() {
      return this.thread?.incident?.is_sealed === true;
    },

    /**
     * El selector de privacidad solo se muestra a técnicos y coordinadores (RF-03.2, RF-03.3).
     * Los responsables de sede publican siempre comentario público y no ven el selector.
     */
    showPrivacySelector() {
      return this.role === 'TECHNICIAN' || this.role === 'COORDINATOR';
    },

    /**
     * Longitud real del texto normalizado (sin espacios sobrantes a los extremos).
     */
    trimmedCommentLength() {
      return (this.commentText || '').trim().length;
    },

    /**
     * Caracteres restantes respecto al límite máximo de 1.000 (RF-03.1).
     */
    remainingChars() {
      const length = (this.commentText || '').length;
      return MAX_COMMENT_LENGTH - length;
    },

    /**
     * Indica si la longitud del texto cumple el rango exigido de 5 a 1.000 caracteres (RF-03.1).
     */
    isCommentValid() {
      const length = this.trimmedCommentLength;
      return length >= MIN_COMMENT_LENGTH && (this.commentText || '').length <= MAX_COMMENT_LENGTH;
    },

    /**
     * Determina si el botón de envío debe permanecer deshabilitado (RF-03.1, RF-04.5).
     */
    isSubmitDisabled() {
      return !this.isCommentValid || this.isSubmitting || !this.hasIdentifier;
    },

    /**
     * Comprueba si el usuario tiene un borrador de texto o una foto en edición
     * sin enviar (RNF-06, guardián de formulario sucio).
     */
    isDirty() {
      return (this.commentText || '').trim().length > 0 || this.photoFile !== null;
    }
  },
  watch: {
    isOpen: {
      immediate: true,
      handler(isOpen) {
        this.handleOpenState(isOpen);
      }
    },
    /**
     * Cambiar de expediente o de canal con el modal abierto recarga el hilo.
     */
    channelEndpoint() {
      if (this.isOpen) {
        this.loadThread();
      }
    }
  },
  beforeUnmount() {
    this.releaseScrollLock();
  },
  methods: {
    /**
     * Sincroniza los efectos de apertura/cierre: bloqueo del scroll de fondo, atajo
     * `Escape` y carga del hilo. El guardián de formulario sucio (T-COM-12) refinará el
     * cierre a través de `requestClose()` en lugar de tocar estos eventos.
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

      if (isOpen) {
        this.loadThread();
      } else {
        this.resetState();
      }
    },

    /**
     * Estado limpio al cerrar: el hilo se vuelve a cargar al abrir para no mostrar
     * mensajes de una lectura anterior (RF-01.2).
     */
    resetState() {
      this.thread = null;
      this.comments = [];
      this.hasMoreBefore = false;
      this.oldestId = null;
      this.expandedPhoto = null;
      this.brokenPhotos = {};
      this.isLoading = false;
      this.isLoadingPrevious = false;
      this.errorMessage = '';
      this.errorCode = '';
      this.commentText = '';
      this.privacyChoice = 'INTERNAL';
      this.formError = '';
      this.isSubmitting = false;
      this.clearSelectedPhoto();
    },

    /**
     * Carga el bloque más reciente del hilo y auto-desplaza el cuerpo hasta el último
     * mensaje (RF-01.2, criterio 4 de T-COM-10).
     */
    async loadThread() {
      if (!this.hasIdentifier) {
        return;
      }

      this.isLoading = true;
      this.errorMessage = '';
      this.errorCode = '';

      try {
        const dto = await this.fetchThreadPage({ limit: THREAD_PAGE_SIZE });

        // El modal pudo cerrarse durante la petición: el resultado ya no interesa.
        if (!this.isOpen) {
          return;
        }

        this.applyThread(dto);
        await this.$nextTick();
        this.scrollToLatest();
      } catch (err) {
        this.handleLoadError(err);
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Recupera el bloque anterior al más antiguo cargado (RF-01.3) preservando el punto
     * de lectura: el desplazamiento se reajusta con la altura ganada arriba, de modo que
     * el mensaje que el usuario estaba leyendo no salta de sitio.
     */
    async loadPreviousMessages() {
      if (this.isLoadingPrevious || !this.hasMoreBefore || this.oldestId === null) {
        return;
      }

      this.isLoadingPrevious = true;
      this.errorMessage = '';
      this.errorCode = '';

      try {
        const dto = await this.fetchThreadPage({ limit: THREAD_PAGE_SIZE, beforeId: this.oldestId });

        if (!this.isOpen) {
          return;
        }

        const container = this.$refs?.threadScroll || null;
        const previousScrollHeight = container ? Number(container.scrollHeight) || 0 : 0;
        const previousScrollTop = container ? Number(container.scrollTop) || 0 : 0;

        this.mergeOlderComments(dto);

        if (container) {
          await this.$nextTick();
          const grownBy = (Number(container.scrollHeight) || 0) - previousScrollHeight;
          if (grownBy > 0) {
            container.scrollTop = previousScrollTop + grownBy;
          }
        }
      } catch (err) {
        this.handleLoadError(err);
      } finally {
        this.isLoadingPrevious = false;
      }
    },

    /**
     * Petición del hilo por canal y cursor. `api.js` ya desenvuelve la envolvente
     * `{ success, data }`, así que aquí llega directamente el DTO del hilo.
     *
     * @param {{limit?: number, beforeId?: number|null}} params
     * @returns {Promise<Object>}
     */
    fetchThreadPage(params = {}) {
      const query = new URLSearchParams();
      query.set('limit', String(params.limit || THREAD_PAGE_SIZE));

      if (params.beforeId !== undefined && params.beforeId !== null) {
        query.set('before_id', String(params.beforeId));
      }

      return api.get(`${this.channelEndpoint}?${query.toString()}`);
    },

    /**
     * Aplica el bloque más reciente del hilo: cabecera contextual, mensajes y cursor.
     */
    applyThread(dto) {
      const payload = dto && typeof dto === 'object' ? dto : {};

      this.thread = payload;
      this.comments = Array.isArray(payload.comments) ? [...payload.comments] : [];
      this.hasMoreBefore = payload.pagination?.has_more_before === true;
      this.oldestId = payload.pagination?.oldest_id ?? null;
    },

    /**
     * Antepone un bloque histórico a los mensajes ya cargados, sin duplicar los que ya
     * estuvieran en pantalla y actualizando el cursor de paginación.
     */
    mergeOlderComments(dto) {
      const payload = dto && typeof dto === 'object' ? dto : {};
      const incoming = Array.isArray(payload.comments) ? payload.comments : [];
      const known = new Set(this.comments.map((comment) => comment?.id));
      const older = incoming.filter((comment) => comment && !known.has(comment.id));

      this.comments = [...older, ...this.comments];
      this.hasMoreBefore = payload.pagination?.has_more_before === true;
      this.oldestId = payload.pagination?.oldest_id ?? this.oldestId;

      if (payload.incident) {
        this.thread = { ...(this.thread || {}), incident: payload.incident };
      }
    },

    /**
     * Error de carga del hilo: se muestra dentro del cuerpo sin romper el marco y sin
     * perder los mensajes que ya estuvieran cargados (RF-07.1).
     */
    handleLoadError(err) {
      this.errorCode = err?.code || '';
      this.errorMessage = err?.message || 'No se pudo cargar el hilo de conversación.';
    },

    /**
     * Desplaza el cuerpo hasta el mensaje más reciente (criterio 4 de T-COM-10).
     */
    scrollToLatest() {
      const container = this.$refs?.threadScroll || null;
      if (!container) {
        return;
      }

      container.scrollTop = Number(container.scrollHeight) || 0;
    },

    /**
     * Abre la evidencia gráfica del mensaje en el visor ampliado (RF-04.4).
     */
    openPhoto(comment) {
      if (!comment?.photo_url) {
        return;
      }

      this.expandedPhoto = { url: comment.photo_url, commentId: comment.id };
    },

    closePhoto() {
      this.expandedPhoto = null;
    },

    /**
     * La imagen del mensaje no pudo cargarse: se sustituye por un recuadro estético sin
     * quebrar la estructura del mensaje (RF-04.4).
     */
    markPhotoUnavailable(commentId) {
      this.brokenPhotos = { ...this.brokenPhotos, [String(commentId)]: true };
      if (this.expandedPhoto && String(this.expandedPhoto.commentId) === String(commentId)) {
        this.expandedPhoto = null;
      }
    },

    /** ¿La evidencia de este mensaje quedó marcada como no disponible? */
    isPhotoUnavailable(commentId) {
      return this.brokenPhotos[String(commentId)] === true;
    },

    /**
     * Estilo del bocadillo: ámbar con candado para las notas internas (RF-02.4), gris
     * neutro para los comentarios públicos y azul suave para los mensajes propios.
     */
    bubbleStyle(comment) {
      const isInternal = comment?.is_internal === true;
      const isOwn = comment?.is_own_message === true;

      return {
        alignSelf: isOwn ? 'flex-end' : 'flex-start',
        maxWidth: '86%',
        backgroundColor: isInternal
          ? INTERNAL_BUBBLE_BACKGROUND
          : (isOwn ? OWN_BUBBLE_BACKGROUND : PUBLIC_BUBBLE_BACKGROUND),
        border: `1px solid ${isInternal ? INTERNAL_BUBBLE_BORDER : 'var(--color-hairline, #c8cfda)'}`,
        borderRadius: 'var(--radius-card, 8px)',
        padding: '10px 12px',
        fontFamily: 'var(--font-body, Inter, sans-serif)'
      };
    },

    /**
     * Formatea una marca temporal del contrato (`YYYY-MM-DD HH:MM:SS`) como
     * `DD/MM/YYYY HH:MM` sin depender del huso horario del navegador.
     */
    formatDateTime(value) {
      if (!value) {
        return '';
      }
      const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
      if (!match) {
        return String(value);
      }

      return `${match[3]}/${match[2]}/${match[1]} ${match[4]}:${match[5]}`;
    },

    /**
     * Solicita el cierre del modal: si hay borrador sin guardar (texto o foto),
     * solicita confirmación explícita antes de cerrar (RNF-06, RF-07.1).
     * Si el usuario confirma o no hay cambios pendientes, emite 'close'.
     */
    requestClose() {
      if (this.isDirty) {
        const confirmFn = typeof globalThis.confirm === 'function' ? globalThis.confirm : null;
        if (confirmFn !== null) {
          const accepted = confirmFn('¿Descartar mensaje en redacción?');
          if (!accepted) {
            return;
          }
        }
      }

      this.$emit('close');
    },

    handleBackdropClick(event) {
      if (event.target === event.currentTarget) {
        this.requestClose();
      }
    },

    /**
     * Escape cierra primero el visor integrado de la evidencia y, después, el modal.
     */
    handleKeyDown(event) {
      if (event.key !== 'Escape' || !this.isOpen) {
        return;
      }
      if (this.expandedPhoto) {
        this.closePhoto();
        return;
      }

      this.requestClose();
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
    },

    /**
     * Procesa la selección de fotografía desde el selector de archivos (RF-04.1).
     * Valida el formato MIME y el tamaño máximo de 5 MB en cliente antes de la subida (RF-04.2).
     */
    handlePhotoSelect(event) {
      this.formError = '';
      const file = event.target?.files?.[0] || null;

      if (!file) {
        return;
      }

      // 1. Validar tamaño máximo (≤ 5 MB, RF-04.2)
      if (file.size > MAX_PHOTO_SIZE_BYTES) {
        this.formError = `La fotografía supera el límite máximo de 5 MB (${(file.size / (1024 * 1024)).toFixed(1)} MB).`;
        event.target.value = '';
        return;
      }

      // 2. Validar tipo MIME permitido (.jpg, .jpeg, .png, .webp)
      const mime = (file.type || '').toLowerCase();
      if (!ACCEPTED_PHOTO_MIME_TYPES.includes(mime)) {
        this.formError = 'Formato no admitido. Seleccione una imagen en formato JPG, PNG o WEBP.';
        event.target.value = '';
        return;
      }

      // 3. Generar previsualización en miniatura
      this.clearPhotoPreview();
      this.photoFile = file;
      if (typeof URL !== 'undefined' && URL.createObjectURL && typeof Blob !== 'undefined' && file instanceof Blob) {
        try {
          this.photoPreviewUrl = URL.createObjectURL(file);
        } catch (e) {
          this.photoPreviewUrl = null;
        }
      }

      event.target.value = '';
    },

    /**
     * Retira la fotografía seleccionada y revoca su URL de objeto antes del envío.
     */
    clearSelectedPhoto() {
      this.clearPhotoPreview();
      this.photoFile = null;
    },

    clearPhotoPreview() {
      if (this.photoPreviewUrl && typeof URL !== 'undefined' && URL.revokeObjectURL) {
        try {
          URL.revokeObjectURL(this.photoPreviewUrl);
        } catch (e) {
          // Ignorar
        }
      }
      this.photoPreviewUrl = null;
    },

    /**
     * Envía el comentario o nota interna a través del endpoint REST del canal (RF-03.4, RF-04.1).
     * Utiliza FormData cuando hay fotografía adjunta (multipart/form-data) o JSON si solo hay texto.
     */
    async submitComment() {
      if (this.isSubmitDisabled) {
        return;
      }

      const text = (this.commentText || '').trim();
      if (text.length < MIN_COMMENT_LENGTH || (this.commentText || '').length > MAX_COMMENT_LENGTH) {
        this.formError = `El comentario debe contener entre ${MIN_COMMENT_LENGTH} y ${MAX_COMMENT_LENGTH} caracteres descriptivos.`;
        return;
      }

      this.formError = '';
      this.isSubmitting = true;

      try {
        const isInternalFlag = this.showPrivacySelector ? (this.privacyChoice === 'INTERNAL') : false;

        let responseDto;
        if (this.photoFile) {
          const formData = new FormData();
          formData.append('comment_text', text);
          if (this.showPrivacySelector) {
            formData.append('is_internal', isInternalFlag ? '1' : '0');
          }
          formData.append('photo', this.photoFile);

          if (typeof api.upload === 'function') {
            responseDto = await api.upload(this.channelEndpoint, formData);
          } else {
            responseDto = await api.post(this.channelEndpoint, formData);
          }
        } else {
          const payload = {
            comment_text: text
          };
          if (this.showPrivacySelector) {
            payload.is_internal = isInternalFlag;
          }
          responseDto = await api.post(this.channelEndpoint, payload);
        }

        // Si la respuesta incluye el DTO completo del hilo, lo aplicamos; si no, recargamos.
        if (responseDto && Array.isArray(responseDto.comments)) {
          this.applyThread(responseDto);
        } else {
          await this.loadThread();
        }

        // Limpiar el formulario reteniendo la privacidad por defecto (RF-03.4)
        this.commentText = '';
        this.clearSelectedPhoto();
        this.privacyChoice = 'INTERNAL';

        this.$emit('comment-added', responseDto);
        await this.$nextTick();
        this.scrollToLatest();
      } catch (err) {
        // En caso de fallo retiene el borrador íntegro (RF-07.1)
        this.formError = err?.message || 'Error al publicar el comentario. Compruebe la conexión e inténtelo de nuevo.';
      } finally {
        this.isSubmitting = false;
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
          style="position: relative; background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); box-shadow: var(--shadow-modal, 0 12px 32px rgba(0, 0, 0, 0.12)); width: min(680px, 100%); height: min(86vh, 760px); max-height: 92vh; display: flex; flex-direction: column; overflow: hidden; outline: none;"
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
            ref="threadScroll"
            style="flex: 1 1 auto; min-height: 0; overflow-y: auto; background-color: var(--color-canvas, #f9fafb);"
          >
            <div
              v-if="isLoading && comments.length === 0"
              class="incident-comment-loading"
              data-testid="incident-comment-loading"
              style="padding: 40px 16px; text-align: center; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-muted, #6c7e9d);"
            >
              Cargando el hilo de conversación…
            </div>

            <div
              v-else-if="errorMessage"
              class="incident-comment-error"
              data-testid="incident-comment-error"
              role="alert"
              style="padding: 20px 16px; text-align: center; border: 1px solid var(--color-error, #ff5757); border-radius: var(--radius-card, 8px); background-color: var(--color-error-bg, #fddfdf);"
            >
              <p style="margin: 0 0 12px; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-error-text, #b91c1c);">
                {{ errorMessage }}
              </p>
              <button
                type="button"
                class="vg-btn vg-btn-primary"
                data-testid="incident-comment-retry"
                @click="loadThread"
              >
                Reintentar
              </button>
            </div>

            <div
              v-else-if="comments.length === 0"
              class="incident-comment-empty"
              data-testid="incident-comment-empty"
              style="padding: 40px 16px; text-align: center; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-muted, #6c7e9d);"
            >
              Aún no hay mensajes en esta incidencia. Inicie la conversación con el equipo técnico.
            </div>

            <section
              v-else
              class="incident-comment-thread"
              data-testid="incident-comment-thread"
              aria-live="polite"
              style="display: flex; flex-direction: column; gap: 12px;"
            >
              <button
                v-if="hasMoreBefore"
                type="button"
                class="vg-btn vg-btn-secondary incident-comment-load-previous"
                data-testid="incident-comment-load-previous"
                :disabled="isLoadingPrevious"
                style="align-self: stretch; margin-bottom: 4px;"
                @click="loadPreviousMessages"
              >
                {{ isLoadingPrevious ? 'Cargando mensajes anteriores…' : 'Cargar mensajes anteriores' }}
              </button>

              <article
                v-for="comment in comments"
                :key="comment.id"
                class="incident-comment-item"
                :data-testid="'incident-comment-item-' + comment.id"
                :style="bubbleStyle(comment)"
              >
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 4px;">
                  <span
                    :data-testid="'incident-comment-author-' + comment.id"
                    style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 600; color: var(--color-slate, #2c333f); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                  >
                    {{ comment.author_name }}
                  </span>
                  <span
                    style="font-family: var(--font-body, Inter, sans-serif); font-size: 11px; color: var(--color-ink-muted, #6c7e9d); white-space: nowrap;"
                  >
                    {{ formatDateTime(comment.created_at) }}
                  </span>
                </div>

                <div
                  v-if="comment.is_internal"
                  class="incident-comment-internal-tag"
                  :data-testid="'incident-comment-internal-' + comment.id"
                  aria-label="🔒 Nota Interna de Taller (Confidencial)"
                  style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px; font-family: var(--font-body, Inter, sans-serif); font-size: 11px; font-weight: 600; color: var(--color-warning-text, #92400e);"
                >
                  <svg
                    width="12"
                    height="12"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                    :data-testid="'incident-comment-lock-' + comment.id"
                  >
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                  </svg>
                  <span>Nota Interna de Taller (Confidencial)</span>
                </div>

                <p
                  :data-testid="'incident-comment-text-' + comment.id"
                  style="margin: 0; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-slate, #2c333f); line-height: 1.5; white-space: pre-wrap;"
                >
                  {{ comment.comment_text }}
                </p>

                <div v-if="comment.photo_url" style="margin-top: 8px;">
                  <img
                    v-if="!isPhotoUnavailable(comment.id)"
                    :src="comment.photo_url"
                    alt="Evidencia gráfica del mensaje"
                    :data-testid="'incident-comment-photo-' + comment.id"
                    style="max-width: 160px; max-height: 120px; object-fit: cover; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); cursor: zoom-in; background-color: var(--color-canvas, #f9fafb);"
                    @click="openPhoto(comment)"
                    @error="markPhotoUnavailable(comment.id)"
                  />
                  <div
                    v-else
                    :data-testid="'incident-comment-photo-fallback-' + comment.id"
                    style="display: flex; align-items: center; justify-content: center; width: 160px; height: 96px; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-ink-muted, #6c7e9d); background-color: var(--color-canvas, #f9fafb); border: 1px dashed var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); text-align: center; padding: 8px;"
                  >
                    Evidencia gráfica no disponible
                  </div>
                </div>
              </article>
            </section>
          </main>

          <!-- PIE FIJO (RNF-03) -->
          <footer
            class="modal-footer incident-comment-footer"
            data-testid="incident-comment-footer"
            style="flex: 0 0 auto; background-color: #ffffff; padding: 16px; border-top: 1px solid var(--color-hairline, #c8cfda); display: flex; flex-direction: column; gap: 12px;"
          >
            <!-- MODO SELLADO DE AUDITORÍA EN SOLO LECTURA (T-COM-12, RF-05.3) -->
            <div
              v-if="isSealed"
              class="incident-comment-sealed-notice"
              data-testid="incident-comment-sealed-notice"
              role="alert"
              style="display: flex; align-items: center; justify-content: space-between; gap: 12px; width: 100%; padding: 12px 16px; background-color: var(--color-canvas, #f9fafb); border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px);"
            >
              <div style="display: flex; align-items: center; gap: 8px; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-muted, #6c7e9d); font-weight: 500;">
                <svg
                  width="16"
                  height="16"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  aria-hidden="true"
                >
                  <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                  <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
                <span>Expediente archivado: conversación sellada por auditoría</span>
              </div>

              <button
                type="button"
                class="vg-btn vg-btn-secondary"
                data-testid="incident-comment-footer-close"
                @click="requestClose"
              >
                Cerrar
              </button>
            </div>

            <!-- FORMULARIO REACTIVO DE REDACCIÓN (T-COM-11, RF-03, RF-04) -->
            <form
              v-else
              class="incident-comment-form"
              data-testid="incident-comment-form"
              @submit.prevent="submitComment"
              style="display: flex; flex-direction: column; gap: 10px; width: 100%;"
            >
              <!-- Selector de privacidad reactivo (solo técnicos y coordinadores, RF-03.2, RF-03.3) -->
              <div
                v-if="showPrivacySelector"
                class="incident-comment-privacy-selector"
                data-testid="incident-comment-privacy-selector"
                style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;"
              >
                <label
                  style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 500; color: var(--color-slate, #2c333f);"
                >
                  <input
                    type="radio"
                    name="privacyChoice"
                    value="INTERNAL"
                    v-model="privacyChoice"
                    data-testid="incident-comment-privacy-internal"
                    :disabled="isSubmitting"
                    style="cursor: pointer;"
                  />
                  <span>🔒 Nota Interna de Taller (Confidencial)</span>
                </label>

                <label
                  style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 500; color: var(--color-slate, #2c333f);"
                >
                  <input
                    type="radio"
                    name="privacyChoice"
                    value="PUBLIC"
                    v-model="privacyChoice"
                    data-testid="incident-comment-privacy-public"
                    :disabled="isSubmitting"
                    style="cursor: pointer;"
                  />
                  <span>🌐 Mensaje para Sede (Público)</span>
                </label>
              </div>

              <!-- Área de texto con límites de 5 a 1.000 caracteres (RF-03.1) -->
              <div style="display: flex; flex-direction: column; gap: 4px; width: 100%;">
                <textarea
                  v-model="commentText"
                  class="vg-textarea incident-comment-textarea"
                  data-testid="incident-comment-textarea"
                  :placeholder="showPrivacySelector && privacyChoice === 'INTERNAL' ? 'Escriba una nota técnica interna para el equipo de taller…' : 'Escriba un mensaje sobre el estado o avance de la avería…'"
                  minlength="5"
                  maxlength="1000"
                  rows="3"
                  :disabled="isSubmitting"
                  style="min-height: 72px; font-size: 13px; line-height: 1.4;"
                ></textarea>

                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-family: var(--font-body, Inter, sans-serif); font-size: 11px;">
                  <span
                    v-if="formError"
                    class="incident-comment-form-error"
                    data-testid="incident-comment-form-error"
                    role="alert"
                    style="color: var(--color-error-text, #b91c1c); font-weight: 500;"
                  >
                    {{ formError }}
                  </span>
                  <span v-else></span>

                  <!-- Contador reactivo de caracteres restantes (RF-03.1) -->
                  <span
                    class="incident-comment-char-counter"
                    data-testid="incident-comment-char-counter"
                    :style="{ color: (trimmedCommentLength > 0 && !isCommentValid) ? 'var(--color-error-text, #b91c1c)' : 'var(--color-ink-muted, #6c7e9d)', fontWeight: (trimmedCommentLength > 0 && !isCommentValid) ? '600' : 'normal' }"
                  >
                    {{ remainingChars }} caracteres restantes
                  </span>
                </div>
              </div>

              <!-- Miniatura preliminar de la foto seleccionada (RF-04.1) -->
              <div
                v-if="photoFile"
                class="incident-comment-photo-preview"
                data-testid="incident-comment-photo-preview"
                style="display: flex; align-items: center; gap: 10px; padding: 6px 10px; background-color: var(--color-canvas, #f9fafb); border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); align-self: flex-start;"
              >
                <img
                  v-if="photoPreviewUrl"
                  :src="photoPreviewUrl"
                  alt="Miniatura de fotografía adjunta"
                  data-testid="incident-comment-photo-preview-thumb"
                  style="width: 44px; height: 44px; object-fit: cover; border-radius: var(--radius-interactive, 4px); border: 1px solid var(--color-hairline, #c8cfda);"
                />
                <span
                  data-testid="incident-comment-photo-preview-name"
                  style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-slate, #2c333f); max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                >
                  {{ photoFile.name }}
                </span>
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary"
                  data-testid="incident-comment-photo-remove"
                  aria-label="Retirar fotografía adjunta"
                  :disabled="isSubmitting"
                  style="padding: 2px 8px; font-size: 12px; line-height: 1.2;"
                  @click="clearSelectedPhoto"
                >
                  ✕
                </button>
              </div>

              <!-- Barra de acciones: Adjuntar foto, Cerrar y Enviar -->
              <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 4px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                  <!-- Input oculto para adjuntar fotografía (RF-04.1) -->
                  <input
                    type="file"
                    id="incident-comment-photo-input"
                    ref="photoInput"
                    accept="image/jpeg,image/png,image/webp"
                    data-testid="incident-comment-photo-input"
                    style="display: none;"
                    :disabled="isSubmitting"
                    @change="handlePhotoSelect"
                  />
                  <button
                    type="button"
                    class="vg-btn vg-btn-secondary incident-comment-photo-btn"
                    data-testid="incident-comment-photo-btn"
                    :disabled="isSubmitting"
                    style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; padding: 6px 12px;"
                    @click="$refs.photoInput?.click()"
                  >
                    <svg
                      width="14"
                      height="14"
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      aria-hidden="true"
                    >
                      <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                      <circle cx="12" cy="13" r="4"></circle>
                    </svg>
                    <span>Adjuntar foto</span>
                  </button>
                </div>

                <div style="display: flex; align-items: center; gap: 8px;">
                  <button
                    type="button"
                    class="vg-btn vg-btn-secondary"
                    data-testid="incident-comment-footer-close"
                    @click="requestClose"
                  >
                    Cerrar
                  </button>

                  <button
                    type="submit"
                    class="vg-btn vg-btn-primary incident-comment-submit"
                    data-testid="incident-comment-submit"
                    :disabled="isSubmitDisabled"
                    style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; min-width: 90px; justify-content: center;"
                  >
                    <span
                      v-if="isSubmitting"
                      class="spinner-border spinner-border-sm"
                      data-testid="incident-comment-spinner"
                      role="status"
                      aria-hidden="true"
                      style="width: 14px; height: 14px;"
                    ></span>
                    <span>{{ isSubmitting ? 'Enviando…' : 'Enviar' }}</span>
                  </button>
                </div>
              </div>
            </form>
          </footer>

          <!-- VISOR AMPLIADO DE LA EVIDENCIA GRÁFICA (RF-04.4) -->
          <div
            v-if="expandedPhoto"
            class="incident-comment-photo-viewer"
            data-testid="incident-comment-photo-viewer"
            role="dialog"
            aria-modal="true"
            aria-label="Evidencia gráfica ampliada"
            style="position: absolute; inset: 0; z-index: 5; display: flex; align-items: center; justify-content: center; padding: 24px; background-color: rgba(0, 0, 0, 0.75);"
            @click.self="closePhoto"
          >
            <img
              :src="expandedPhoto.url"
              alt="Evidencia gráfica ampliada"
              data-testid="incident-comment-photo-viewer-image"
              style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: var(--radius-card, 8px);"
            />
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              data-testid="incident-comment-photo-viewer-close"
              style="position: absolute; top: 12px; right: 12px;"
              @click="closePhoto"
            >
              ✕ Cerrar vista
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  `
};

export default IncidentCommentThreadModal;
