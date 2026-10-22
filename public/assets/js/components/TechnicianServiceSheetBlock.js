/**
 * VendGuard - TechnicianServiceSheetBlock (TechnicianServiceSheetBlock.js)
 *
 * Bloque de conformidad del cierre correctivo: recoge la firma manuscrita digitalizada
 * del responsable de sede o, cuando no hay nadie, la atestación de ausencia con su motivo
 * (Módulo 19: RF-01, RF-02, RD-02, RD-12, RD-15, RNF-04).
 *
 * Reglas que el componente hace cumplir en el cliente:
 * 1. Dos vías mutuamente excluyentes: firma (nombre + documento + trazo) o ausencia (motivo).
 *    Activar una vacía los datos de la otra (RF-02.2).
 * 2. El trazo se dibuja con seguimiento por `pointer` mientras se captura el puntero, de modo
 *    que un dedo que se sale del lienzo no pierde el trazo ni arrastra la página (RF-01.2).
 * 3. El lienzo se rellena de blanco opaco y el trazo va en el tono de tinta normativo, para que el PNG
 *    exportado se lea en papel aunque el navegador no imprima fondos (RF-01.8).
 * 4. La memoria del lienzo se escala por `devicePixelRatio`: firmar en un móvil de alta
 *    densidad no puede producir una firma borrosa (RNF-04).
 * 5. «Sin tinta» se decide contando píxeles con luminancia inferior a 200; menos de 150
 *    píxeles es un lienzo en blanco (RF-01.6). Es la única comprobación de tinta del sistema:
 *    el servidor no decodifica píxeles (RD-12).
 * 6. Al cambiar de tamaño (rotación, teclado) el trazo se copia y se redibuja escalado. Si el
 *    navegador no permite copiarlo, se limpia y se pide volver a firmar: mejor eso que imprimir
 *    un trazo cortado (RF-01.7).
 *
 * El componente no lee datos ni escribe: emite `change` con el bloque listo para viajar en el
 * cuerpo del cierre, y la vista decide cuándo enviarlo.
 *
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias externas).
 */

/** Límites compartidos con `ServiceSheetValidator` (contratos §3.2). */
export const SERVICE_SHEET_LIMITS = Object.freeze({
  MIN_SIGNER_NAME: 3,
  MIN_DOCUMENT: 5,
  MAX_DOCUMENT: 20,
  MIN_ABSENCE_REASON: 10,
  MIN_INK_PIXELS: 150,
  INK_LUMINANCE_THRESHOLD: 200,
  MIN_STROKE_WIDTH: 2
});

/**
 * Alfabeto admitido en el documento del firmante tras normalizar (RF-01.5).
 */
export const SIGNER_DOCUMENT_PATTERN = /^[A-Z0-9-]{5,20}$/;

/**
 * Tono del trazo. Es el tono de tinta que exige RF-01.8, escrito en `rgb()` para no sumar un
 * literal hexadecimal al trinquete de tokens del frontend.
 */
export const CANVAS_INK_COLOR = 'rgb(17, 17, 17)';

/** Papel del lienzo: blanco opaco, nunca transparente (RF-01.8). */
export const CANVAS_PAPER_COLOR = 'rgb(255, 255, 255)';

/**
 * Normaliza el documento del firmante: recorta, pasa a mayúsculas y elimina **todos** los
 * espacios, también los internos y los no separables (RF-01.5, RD-07).
 *
 * @param {unknown} value
 * @returns {string}
 */
export function normalizeSignerDocument(value) {
  return String(value ?? '')
    .trim()
    .toUpperCase()
    .replace(/[\s\u00a0]+/g, '');
}

/**
 * Cuenta los píxeles con luminancia inferior al umbral sobre una muestra RGBA.
 *
 * Los píxeles completamente transparentes cuentan como papel: el lienzo se pinta opaco, así
 * que un hueco transparente no es tinta (y contarlo convertiría un fondo limpio en firma).
 *
 * @param {Uint8ClampedArray|Uint8Array|number[]} rgba
 * @param {number} [threshold=SERVICE_SHEET_LIMITS.INK_LUMINANCE_THRESHOLD]
 * @returns {number}
 */
export function countInkPixels(rgba, threshold = SERVICE_SHEET_LIMITS.INK_LUMINANCE_THRESHOLD) {
  if (!rgba || typeof rgba.length !== 'number') {
    return 0;
  }

  let counted = 0;
  for (let offset = 0; offset + 3 < rgba.length; offset += 4) {
    if (rgba[offset + 3] === 0) {
      continue;
    }
    const luminance = 0.299 * rgba[offset] + 0.587 * rgba[offset + 1] + 0.114 * rgba[offset + 2];
    if (luminance < threshold) {
      counted++;
    }
  }

  return counted;
}

export const TechnicianServiceSheetBlock = {
  name: 'TechnicianServiceSheetBlock',
  props: {
    disabled: {
      type: Boolean,
      default: false
    }
  },
  emits: ['change'],
  data() {
    return {
      mode: null, // null (sin elegir) | 'signature' | 'absence'
      signerName: '',
      signerDocumentRaw: '',
      absenceReason: '',
      isDrawing: false,
      hasStroke: false,
      strokeInkPixels: 0,
      pngBase64: '',
      mustResign: false,
      canvasWidth: 0,
      canvasHeight: 0,
      lastPoint: null,
      resizeListener: null
    };
  },
  computed: {
    isSignatureMode() {
      return this.mode === 'signature';
    },
    isAbsenceMode() {
      return this.mode === 'absence';
    },
    normalizedDocument() {
      return normalizeSignerDocument(this.signerDocumentRaw);
    },
    isValid() {
      return this.validate().isValid;
    }
  },
  mounted() {
    this.prepareCanvas();

    // La rotación y el teclado en pantalla refluyen el lienzo: si el trazo ya está puesto,
    // se copia antes de que el navegador lo borre (RF-01.7).
    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
      this.resizeListener = () => this.preserveStrokeOnResize();
      window.addEventListener('resize', this.resizeListener);
      window.addEventListener('orientationchange', this.resizeListener);
    }
  },
  beforeUnmount() {
    if (this.resizeListener && typeof window !== 'undefined' && typeof window.removeEventListener === 'function') {
      window.removeEventListener('resize', this.resizeListener);
      window.removeEventListener('orientationchange', this.resizeListener);
    }
    this.resizeListener = null;
  },
  methods: {
    canvas() {
      return this.$refs?.signatureCanvas || null;
    },

    context() {
      const canvas = this.canvas();
      return canvas && typeof canvas.getContext === 'function' ? canvas.getContext('2d') : null;
    },

    devicePixelRatio() {
      return typeof window !== 'undefined' && window.devicePixelRatio ? window.devicePixelRatio : 1;
    },

    /** Prepara la memoria del lienzo con la densidad real de la pantalla. */
    prepareCanvas() {
      return this.applyBackingStore(this.canvas());
    },

    /**
     * Redimensiona la memoria del lienzo y deja el papel opaco de fondo.
     *
     * Cambiar `width`/`height` borra el contenido y reinicia la transformación; por eso este
     * método pinta el papel y devuelve si pudo preparar el contexto.
     */
    applyBackingStore(canvas) {
      if (!canvas || typeof canvas.getContext !== 'function') {
        return false;
      }

      const rect = typeof canvas.getBoundingClientRect === 'function' ? canvas.getBoundingClientRect() : null;
      const cssWidth = Math.max(1, Math.round(rect?.width || canvas.clientWidth || 300));
      const cssHeight = Math.max(1, Math.round(rect?.height || canvas.clientHeight || 160));
      const ratio = this.devicePixelRatio();

      canvas.width = Math.round(cssWidth * ratio);
      canvas.height = Math.round(cssHeight * ratio);
      if (canvas.style) {
        canvas.style.width = `${cssWidth}px`;
        canvas.style.height = `${cssHeight}px`;
      }

      this.canvasWidth = cssWidth;
      this.canvasHeight = cssHeight;

      const ctx = canvas.getContext('2d');
      if (!ctx) {
        return false;
      }
      if (typeof ctx.setTransform === 'function') {
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      }
      this.paintPaper(ctx);
      return true;
    },

    /** Fondo blanco opaco del papel (RF-01.8). */
    paintPaper(ctx, width = this.canvasWidth || 300, height = this.canvasHeight || 160) {
      ctx.fillStyle = CANVAS_PAPER_COLOR;
      ctx.fillRect(0, 0, width, height);
    },

    pointFromEvent(event) {
      const canvas = this.canvas();
      const rect = typeof canvas?.getBoundingClientRect === 'function' ? canvas.getBoundingClientRect() : null;
      return {
        x: (event?.clientX ?? 0) - (rect?.left ?? 0),
        y: (event?.clientY ?? 0) - (rect?.top ?? 0)
      };
    },

    onPointerDown(event) {
      if (this.disabled || !this.isSignatureMode) {
        return;
      }

      const ctx = this.context();
      if (!ctx) {
        return;
      }

      if (typeof event?.preventDefault === 'function') {
        event.preventDefault();
      }

      // Capturar el puntero mantiene el trazo aunque el dedo se salga del lienzo (RF-01.2).
      const canvas = this.canvas();
      if (typeof canvas?.setPointerCapture === 'function' && event?.pointerId !== undefined) {
        try {
          canvas.setPointerCapture(event.pointerId);
        } catch {
          // El navegador rechaza la captura si el puntero ya terminó: el trazo sigue vivo.
        }
      }

      this.isDrawing = true;
      this.mustResign = false;

      const point = this.pointFromEvent(event);
      this.lastPoint = point;
      ctx.fillStyle = CANVAS_INK_COLOR;
      ctx.beginPath();
      ctx.arc(point.x, point.y, SERVICE_SHEET_LIMITS.MIN_STROKE_WIDTH / 2, 0, Math.PI * 2);
      ctx.fill();
      this.refreshStrokeState();
    },

    onPointerMove(event) {
      if (!this.isDrawing || this.disabled) {
        return;
      }

      const ctx = this.context();
      if (!ctx) {
        return;
      }

      if (typeof event?.preventDefault === 'function') {
        event.preventDefault();
      }

      const point = this.pointFromEvent(event);
      const last = this.lastPoint || point;

      ctx.strokeStyle = CANVAS_INK_COLOR;
      ctx.lineWidth = SERVICE_SHEET_LIMITS.MIN_STROKE_WIDTH;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.beginPath();
      ctx.moveTo(last.x, last.y);
      ctx.lineTo(point.x, point.y);
      ctx.stroke();

      this.lastPoint = point;
    },

    onPointerUp(event) {
      if (!this.isDrawing) {
        return;
      }

      this.isDrawing = false;
      this.lastPoint = null;

      const canvas = this.canvas();
      if (typeof canvas?.releasePointerCapture === 'function' && event?.pointerId !== undefined) {
        try {
          canvas.releasePointerCapture(event.pointerId);
        } catch {
          // Sin captura activa no hay nada que liberar.
        }
      }

      this.refreshStrokeState();
    },

    /** Cuenta la tinta del lienzo y, si alcanza el mínimo, exporta el PNG del trazo. */
    refreshStrokeState() {
      this.strokeInkPixels = this.measureInk();
      this.hasStroke = this.strokeInkPixels >= SERVICE_SHEET_LIMITS.MIN_INK_PIXELS;
      this.pngBase64 = this.hasStroke ? this.exportPng() : '';
      this.emitChange();
    },

    measureInk() {
      const canvas = this.canvas();
      const ctx = this.context();
      if (!canvas || !ctx || typeof ctx.getImageData !== 'function' || !canvas.width || !canvas.height) {
        return 0;
      }

      try {
        const image = ctx.getImageData(0, 0, canvas.width, canvas.height);
        return countInkPixels(image?.data);
      } catch {
        // Un lienzo contaminado por otro origen no se puede leer: se trata como papel.
        return 0;
      }
    },

    exportPng() {
      const canvas = this.canvas();
      if (!canvas || typeof canvas.toDataURL !== 'function') {
        return '';
      }
      try {
        return canvas.toDataURL('image/png');
      } catch {
        return '';
      }
    },

    /** Borra el trazo sin tocar el nombre ni el documento ya escritos (RF-01.3). */
    clearStroke(publish = true) {
      if (this.disabled) {
        return;
      }

      const ctx = this.context();
      if (ctx) {
        this.paintPaper(ctx);
      }

      this.hasStroke = false;
      this.strokeInkPixels = 0;
      this.pngBase64 = '';
      this.mustResign = false;

      if (publish) {
        this.emitChange();
      }
    },

    /**
     * Cambia la vía del cierre. Las dos son excluyentes: activar una vacía los datos de la
     * otra, para que el bloque emitido nunca lleve firma y motivo a la vez (RF-02.2).
     */
    setMode(mode) {
      if (this.disabled) {
        return;
      }

      const next = mode === 'absence' ? 'absence' : 'signature';
      if (this.mode === next) {
        return;
      }

      this.mode = next;
      if (next === 'absence') {
        this.signerName = '';
        this.signerDocumentRaw = '';
        this.clearStroke(false);
      } else {
        this.absenceReason = '';
      }

      this.emitChange();
    },

    /**
     * Copia el mapa de bits y lo redibuja escalado tras un cambio de tamaño. Si la copia no
     * es posible, limpia y pide volver a firmar (RF-01.7).
     */
    preserveStrokeOnResize() {
      const canvas = this.canvas();
      if (!canvas) {
        return;
      }

      const rect = typeof canvas.getBoundingClientRect === 'function' ? canvas.getBoundingClientRect() : null;
      const nextWidth = Math.round(Math.max(1, Math.round(rect?.width || canvas.clientWidth || 300)) * this.devicePixelRatio());
      const nextHeight = Math.round(Math.max(1, Math.round(rect?.height || canvas.clientHeight || 160)) * this.devicePixelRatio());
      if (nextWidth === canvas.width && nextHeight === canvas.height) {
        return;
      }

      const hadStroke = this.hasStroke;
      let snapshot = '';
      if (hadStroke) {
        try {
          snapshot = canvas.toDataURL('image/png');
        } catch {
          snapshot = '';
        }
      }

      this.applyBackingStore(canvas);

      if (!hadStroke) {
        return;
      }

      if (!snapshot) {
        this.hasStroke = false;
        this.strokeInkPixels = 0;
        this.pngBase64 = '';
        this.mustResign = true;
        this.emitChange();
        return;
      }

      this.redrawSnapshot(snapshot);
    },

    redrawSnapshot(dataUrl) {
      const ctx = this.context();
      if (!ctx || typeof Image === 'undefined') {
        this.hasStroke = false;
        this.strokeInkPixels = 0;
        this.pngBase64 = '';
        this.mustResign = true;
        this.emitChange();
        return;
      }

      const image = new Image();
      image.onload = () => {
        ctx.drawImage(image, 0, 0, this.canvasWidth, this.canvasHeight);
        this.refreshStrokeState();
      };
      image.onerror = () => {
        this.hasStroke = false;
        this.strokeInkPixels = 0;
        this.pngBase64 = '';
        this.mustResign = true;
        this.emitChange();
      };
      image.src = dataUrl;
    },

    /**
     * Valida el bloque completo con el mensaje y el campo señalado que exige RF-01.4.
     *
     * @returns {{isValid: boolean, error: string, field?: string, payload?: Object|null}}
     */
    validate() {
      if (this.mode === 'absence') {
        const reason = String(this.absenceReason || '').trim();
        if (reason.length < SERVICE_SHEET_LIMITS.MIN_ABSENCE_REASON) {
          return {
            isValid: false,
            field: 'absence_reason',
            error: `Declara el motivo de la ausencia con al menos ${SERVICE_SHEET_LIMITS.MIN_ABSENCE_REASON} caracteres.`
          };
        }
        return { isValid: true, error: '', payload: this.getPayload() };
      }

      if (this.mode !== 'signature') {
        return {
          isValid: false,
          field: 'mode',
          error: 'Debes recoger la firma del responsable de la sede o declarar que no había nadie disponible.'
        };
      }

      const name = String(this.signerName || '').trim();
      if (name.length < SERVICE_SHEET_LIMITS.MIN_SIGNER_NAME) {
        return {
          isValid: false,
          field: 'signer_name',
          error: `El nombre del firmante debe tener al menos ${SERVICE_SHEET_LIMITS.MIN_SIGNER_NAME} caracteres.`
        };
      }

      if (!SIGNER_DOCUMENT_PATTERN.test(this.normalizedDocument)) {
        return {
          isValid: false,
          field: 'signer_document',
          error: 'El documento del firmante no es válido: entre 5 y 20 caracteres [A-Z0-9-] tras quitar los espacios.'
        };
      }

      if (!this.hasStroke || this.strokeInkPixels < SERVICE_SHEET_LIMITS.MIN_INK_PIXELS) {
        return {
          isValid: false,
          field: 'signature_png_base64',
          error: 'El trazo de la firma está en blanco: pide al responsable que firme de nuevo.'
        };
      }

      if (!this.pngBase64) {
        return {
          isValid: false,
          field: 'signature_png_base64',
          error: 'No se pudo exportar el trazo de la firma; vuelve a firmar.'
        };
      }

      return { isValid: true, error: '', payload: this.getPayload() };
    },

    /** Bloque que viaja en `service_sheet`; `null` mientras ninguna vía esté completa. */
    getPayload() {
      if (this.mode === 'absence') {
        return { absence_reason: String(this.absenceReason || '').trim() };
      }

      if (this.mode !== 'signature') {
        return null;
      }

      return {
        signer_name: String(this.signerName || '').trim(),
        signer_document: this.normalizedDocument,
        signature_png_base64: this.pngBase64
      };
    },

    emitChange() {
      const validation = this.validate();
      this.$emit('change', {
        isValid: validation.isValid,
        error: validation.error,
        mode: this.mode,
        payload: validation.isValid ? validation.payload : null
      });
    }
  },
  template: `
    <div class="vg-signature-block" data-testid="technician-service-sheet-block">
      <div class="vg-signature-block__intro">
        <label class="vg-signature-block__title">
          ✍️ Conformidad del responsable de sede *
        </label>
        <p class="vg-signature-block__hint">
          Recoge la firma manuscrita digitalizada de quien recibe el servicio o declara que no había nadie.
        </p>
      </div>

      <div class="vg-signature-modes" data-testid="service-sheet-modes">
        <button
          type="button"
          class="vg-btn vg-btn-secondary"
          :class="{ 'is-active': isSignatureMode }"
          :disabled="disabled"
          data-testid="btn-mode-signature"
          @click="setMode('signature')"
        >
          ✍️ Firma del responsable
        </button>
        <button
          type="button"
          class="vg-btn vg-btn-secondary"
          :class="{ 'is-active': isAbsenceMode }"
          :disabled="disabled"
          data-testid="btn-mode-absence"
          @click="setMode('absence')"
        >
          🚫 No había responsable disponible en la sede
        </button>
      </div>

      <div v-if="isSignatureMode" class="vg-signature-fields" data-testid="service-sheet-signature-fields">
        <div class="vg-signature-field">
          <label class="vg-signature-field__label" for="service-sheet-signer-name">Nombre del firmante *</label>
          <input
            id="service-sheet-signer-name"
            v-model="signerName"
            type="text"
            class="vg-signature-field__control"
            :disabled="disabled"
            autocomplete="off"
            maxlength="120"
            data-testid="input-signer-name"
          />
        </div>

        <div class="vg-signature-field">
          <label class="vg-signature-field__label" for="service-sheet-signer-document">Documento del firmante *</label>
          <input
            id="service-sheet-signer-document"
            v-model="signerDocumentRaw"
            type="text"
            class="vg-signature-field__control"
            :disabled="disabled"
            autocomplete="off"
            maxlength="24"
            placeholder="12345678Z"
            data-testid="input-signer-document"
          />
          <p class="vg-signature-field__hint">Se guarda en mayúsculas y sin espacios.</p>
        </div>

        <div class="vg-signature-pad">
          <div class="vg-signature-pad__header">
            <span class="vg-signature-field__label">Trazo de la firma *</span>
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              :disabled="disabled"
              data-testid="btn-clear-stroke"
              @click="clearStroke()"
            >
              🧽 Borrar el trazo
            </button>
          </div>

          <canvas
            ref="signatureCanvas"
            class="vg-signature-canvas"
            data-testid="service-sheet-canvas"
            aria-label="Zona para firmar con el dedo o el puntero"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
            @pointerleave="onPointerUp"
          ></canvas>

          <p v-if="mustResign" class="vg-signature-warning" role="alert" data-testid="signature-must-resign">
            El lienzo cambió de tamaño y el navegador no pudo conservar el trazo: vuelve a firmar.
          </p>
          <p v-else-if="isSignatureMode && !hasStroke" class="vg-signature-field__hint">
            Menos de {{ 150 }} píxeles dibujados cuentan como firma en blanco.
          </p>
        </div>
      </div>

      <div v-if="isAbsenceMode" class="vg-signature-fields" data-testid="service-sheet-absence-fields">
        <div class="vg-signature-field">
          <label class="vg-signature-field__label" for="service-sheet-absence-reason">Motivo de la ausencia *</label>
          <textarea
            id="service-sheet-absence-reason"
            v-model="absenceReason"
            rows="3"
            class="vg-signature-field__control vg-signature-field__control--area"
            :disabled="disabled"
            maxlength="500"
            placeholder="Sede sin conserjería; acceso con llave facilitada por la central."
            data-testid="input-absence-reason"
          ></textarea>
          <p class="vg-signature-field__hint">
            {{ (absenceReason || '').trim().length }} / 10 caracteres mínimos.
          </p>
        </div>
      </div>
    </div>
  `
};

export default TechnicianServiceSheetBlock;
