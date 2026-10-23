/**
 * VendGuard - SatisfactionSurveyModal (SatisfactionSurveyModal.js)
 *
 * Encuesta de satisfacción del Responsable de Sede (Módulo 20, RF-05.1–05.7,
 * contratos §10.1).
 *
 * QUÉ RESUELVE
 *
 *   El servicio está resuelto y la sede no ha dicho todavía cómo fue. El modal
 *   le enseña el episodio más reciente sin valorar y le deja puntuar de 1 a 5
 *   estrellas con un comentario opcional. Al enviar no decide qué viene después:
 *   emite `submitted` y es el padre quien vuelve a consultar los pendientes, de
 *   modo que el «siguiente» que se pinta es siempre uno que el servidor acaba de
 *   confirmar, no uno que se creía pendiente (R3).
 *
 * DECISIONES QUE NO SON DE ESTILO
 *
 *   · **El 409 no es un error rojo.** `SURVEY_ALREADY_SUBMITTED` significa que
 *     ese episodio ya no está: lo valoró otra pestaña, o el listado venía
 *     desfasado. Se emite `submitted` igual para que el padre refresque, se
 *     informa en tono neutro y la nota escrita no se pierde por un fallo que no
 *     es del usuario.
 *   · **Un fallo de red conserva nota y comentario.** Borrarlos castigaría al
 *     usuario por un fallo del transporte (RF-05.4).
 *   · **El aviso del total no se recorta.** El padre pinta `pending_total` tal
 *     cual; aquí solo se muestra el episodio en curso.
 *
 * Options API, como el resto del repositorio (R1): el proyecto entero trabaja
 * así y no se introduce una excepción nueva (Art. IV.2, desviación heredada del
 * Módulo 19).
 */

import { api } from '../api.js';

/** Clave del error con el que el servidor retira el episodio ya valorado. */
const ALREADY_SUBMITTED_CODE = 'SURVEY_ALREADY_SUBMITTED';

/** Tope de caracteres del comentario, el mismo que valida el servidor. */
const COMMENT_MAX_LENGTH = 500;

export const SatisfactionSurveyModal = {
  name: 'SatisfactionSurveyModal',
  props: {
    /** Si el modal está visible. El padre manda; el componente solo avisa. */
    isOpen: {
      type: Boolean,
      default: false
    },
    /** Episodios pendientes; el primero es el que se valora (R3). */
    episodes: {
      type: Array,
      default: () => []
    },
    /** Recarga en curso: se pinta el estado `loading`, no un formulario vacío. */
    loading: {
      type: Boolean,
      default: false
    }
  },
  emits: ['submitted', 'closed'],
  data() {
    return {
      rating: null,
      comment: '',
      submitting: false,
      errorMessage: '',
      noticeMessage: ''
    };
  },
  computed: {
    /** Episodio en curso: el más reciente de los pendientes. */
    current() {
      return Array.isArray(this.episodes) && this.episodes.length > 0 ? this.episodes[0] : null;
    },
    /** Escala completa de estrellas, para no clavar cinco blases en el template. */
    ratingScale() {
      return [1, 2, 3, 4, 5];
    },
    commentLength() {
      return String(this.comment ?? '').length;
    },
    /** Contador `N/500`, que es lo que se anuncia por `aria-live`. */
    commentCounter() {
      return `${this.commentLength}/${COMMENT_MAX_LENGTH}`;
    },
    isCommentTooLong() {
      return this.commentLength > COMMENT_MAX_LENGTH;
    },
    /** Fecha de resolución legible; si no se puede parsear se muestra el valor crudo. */
    resolvedAtLabel() {
      const raw = this.current?.resolved_at;
      if (!raw) return '';
      const parsed = new Date(raw);
      if (Number.isNaN(parsed.getTime())) return String(raw);
      return parsed.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' });
    },
    /** Se envía solo con nota elegida, sin envío en curso y con texto válido. */
    canSubmit() {
      return this.current !== null
        && this.rating !== null
        && !this.submitting
        && !this.isCommentTooLong;
    }
  },
  mounted() {
    this.focusFirstStar();
  },
  methods: {
    /** El foco inicial va al primer radio: el teclado ya está en la nota (RF-05.6). */
    focusFirstStar() {
      const nextTick = typeof this.$nextTick === 'function' ? this.$nextTick.bind(this) : (callback) => callback();

      nextTick(() => {
        const inputs = this.$refs.starInputs;
        const first = Array.isArray(inputs) ? inputs[0] : inputs;

        if (first && typeof first.focus === 'function') {
          first.focus();
        }
      });
    },

    selectRating(value) {
      this.rating = value;
    },

    /**
     * Envía la valoración del episodio en curso.
     *
     * No hace nada sin nota elegida ni con un envío ya en vuelo: el botón queda
     * deshabilitado, pero el guardado vive aquí para que un doble clic o una
     * tecla repetida no manden dos altas.
     */
    async submit() {
      if (this.current === null || this.rating === null || this.submitting || this.isCommentTooLong) {
        return;
      }

      const incidentId = this.current.incident_id;
      const payload = { rating: Number(this.rating) };
      const comment = String(this.comment ?? '').trim();
      if (comment !== '') {
        payload.comment = comment;
      }

      this.submitting = true;
      this.errorMessage = '';
      this.noticeMessage = '';

      try {
        const survey = await api.satisfaction.submit(incidentId, payload);
        this.resetForm();
        this.$emit('submitted', { incidentId, survey, alreadySubmitted: false });
      } catch (error) {
        if (error && error.code === ALREADY_SUBMITTED_CODE) {
          // 409: el episodio ya no está. Se retira sin pintar error rojo y el
          // padre refresca igual que en un alta buena.
          this.resetForm();
          this.noticeMessage = 'Ese servicio ya constaba como valorado. Lo hemos retirado de la lista.';
          this.$emit('submitted', { incidentId, survey: null, alreadySubmitted: true });
        } else {
          // Se conserva lo escrito: el fallo no es del usuario (RF-05.4).
          this.errorMessage = (error && error.message) || 'No se pudo registrar la valoración. Inténtelo de nuevo.';
        }
      } finally {
        this.submitting = false;
      }
    },

    resetForm() {
      this.rating = null;
      this.comment = '';
    },

    onKeydown(event) {
      if (event && event.key === 'Escape') {
        event.preventDefault?.();
        this.close();
      }
    },

    close() {
      this.$emit('closed');
    }
  },
  template: `
    <div
      v-if="isOpen"
      class="vg-csat-modal-backdrop"
      data-testid="satisfaction-survey-modal"
      style="position: fixed; inset: 0; background: rgba(44, 51, 63, 0.45); display: flex; align-items: center; justify-content: center; padding: 16px; z-index: 80;"
      @click.self="close"
    >
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="csat-modal-title"
        tabindex="-1"
        style="width: 100%; max-width: 460px; background: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card, 8px); padding: 24px; font-family: var(--font-body, Inter, sans-serif); color: var(--color-slate);"
        @keydown="onKeydown"
      >
        <h2
          id="csat-modal-title"
          style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 20px; font-weight: 700; color: var(--color-ink); margin: 0 0 6px;"
        >
          ¿Cómo fue el servicio?
        </h2>

        <p
          v-if="loading"
          data-testid="csat-loading"
          style="font-size: 13px; color: var(--color-ink-muted); margin: 0;"
        >
          Cargando servicios por valorar…
        </p>

        <div v-else-if="current === null" data-testid="csat-empty">
          <p style="font-size: 13px; color: var(--color-slate); margin: 0 0 16px;">
            Ya no quedan servicios por valorar en su sede. Gracias por su ayuda.
          </p>
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            style="height: 36px; padding: 0 16px; font-size: 13px; border-radius: var(--radius-interactive, 4px);"
            @click="close"
          >
            Cerrar
          </button>
        </div>

        <template v-else>
          <p style="font-size: 13px; color: var(--color-slate); margin: 0;">
            <strong style="font-family: monospace;">{{ current.ticket_code }}</strong>
            · {{ current.machine_code }}<span v-if="current.machine_model"> · {{ current.machine_model }}</span>
          </p>
          <p style="font-size: 12px; color: var(--color-ink-muted); margin: 2px 0 16px;">
            Resuelto el {{ resolvedAtLabel }}
          </p>

          <fieldset style="border: none; padding: 0; margin: 0 0 16px;">
            <legend style="font-size: 13px; font-weight: 600; color: var(--color-slate); padding: 0;">
              Valore el servicio
            </legend>
            <div style="display: flex; gap: 10px; margin-top: 8px;">
              <label
                v-for="value in ratingScale"
                :key="value"
                style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; font-size: 13px;"
              >
                <input
                  ref="starInputs"
                  type="radio"
                  name="csat-rating"
                  :value="value"
                  v-model="rating"
                  :aria-label="value === 1 ? '1 estrella' : value + ' estrellas'"
                  :disabled="submitting"
                />
                <span aria-hidden="true">★</span>
              </label>
            </div>
          </fieldset>

          <label
            for="csat-comment"
            style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;"
          >
            Comentario (opcional)
          </label>
          <textarea
            id="csat-comment"
            v-model="comment"
            rows="3"
            :maxlength="500"
            :disabled="submitting"
            placeholder="Cuéntenos qué tal fue la intervención."
            style="width: 100%; box-sizing: border-box; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive, 4px); padding: 8px 10px; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-slate); resize: vertical;"
          ></textarea>
          <div
            aria-live="polite"
            style="font-size: 12px; color: var(--color-ink-muted); margin: 4px 0 12px; text-align: right;"
          >
            {{ commentCounter }}
          </div>

          <div
            v-if="errorMessage"
            role="alert"
            data-testid="csat-error"
            style="background-color: var(--color-urgency-critical-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); padding: 8px 10px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 12px;"
          >
            {{ errorMessage }}
          </div>

          <div
            v-if="noticeMessage"
            data-testid="csat-notice"
            style="background-color: var(--color-neutral-bg); border: 1px solid var(--color-hairline); color: var(--color-slate); padding: 8px 10px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 12px;"
          >
            {{ noticeMessage }}
          </div>

          <div style="display: flex; gap: 8px; justify-content: flex-end;">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              style="height: 36px; padding: 0 16px; font-size: 13px; border-radius: var(--radius-interactive, 4px);"
              @click="close"
            >
              Ahora no
            </button>
            <button
              type="button"
              class="vg-btn vg-btn-primary"
              data-testid="csat-submit"
              style="height: 36px; padding: 0 16px; font-size: 13px; border-radius: var(--radius-interactive, 4px);"
              :disabled="!canSubmit"
              @click="submit"
            >
              <span v-if="!submitting">Enviar valoración</span>
              <span v-else>Enviando…</span>
            </button>
          </div>
        </template>
      </section>
    </div>
  `
};

export default SatisfactionSurveyModal;
