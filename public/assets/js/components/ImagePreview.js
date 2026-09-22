/**
 * VendGuard - ImagePreview Component (ImagePreview.js)
 * 
 * Secure image upload picker & validator (RNF-05).
 * - Enforces client-side 5 MB file size limit.
 * - Restricts formats strictly to JPG, JPEG, PNG, WEBP.
 * - Displays instant thumbnail preview and clear action.
 * - Preserves surrounding text form inputs in case of validation rejection (EARS 3.9).
 */

const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024; // 5 MB
const ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

export const ImagePreview = {
  name: 'ImagePreview',
  props: {
    modelValue: {
      type: [Object, null], // File object or null
      default: null
    },
    maxSizeMb: {
      type: Number,
      default: 5
    }
  },
  emits: ['update:modelValue', 'error'],
  data() {
    return {
      previewUrl: null,
      errorMessage: '',
      isDragging: false,
      inputId: `image-upload-${Math.random().toString(36).substr(2, 9)}`
    };
  },
  watch: {
    modelValue(newVal) {
      if (!newVal) {
        this.clearPreview();
      }
    }
  },
  beforeUnmount() {
    this.revokePreview();
  },
  methods: {
    revokePreview() {
      if (this.previewUrl && this.previewUrl.startsWith('blob:')) {
        try {
          URL.revokeObjectURL(this.previewUrl);
        } catch (e) {
          // Ignore
        }
      }
    },
    clearPreview() {
      this.revokePreview();
      this.previewUrl = null;
      this.errorMessage = '';
    },
    validateAndEmit(file) {
      this.errorMessage = '';

      if (!file) {
        this.clearPreview();
        this.$emit('update:modelValue', null);
        return;
      }

      // 1. Validate file size (max 5 MB - RNF-05)
      const maxBytes = this.maxSizeMb * 1024 * 1024;
      if (file.size > maxBytes) {
        this.errorMessage = `La imagen seleccionada supera el límite máximo de ${this.maxSizeMb} MB (tamaño: ${(file.size / (1024 * 1024)).toFixed(1)} MB).`;
        this.$emit('error', this.errorMessage);
        return;
      }

      // 2. Validate MIME type
      if (!ACCEPTED_MIME_TYPES.includes(file.type.toLowerCase())) {
        this.errorMessage = 'Formato de imagen no permitido. Solo se aceptan archivos .jpg, .jpeg, .png y .webp.';
        this.$emit('error', this.errorMessage);
        return;
      }

      // 3. Generate preview URL
      this.revokePreview();
      if (typeof URL !== 'undefined' && URL.createObjectURL && typeof Blob !== 'undefined' && file instanceof Blob) {
        try {
          this.previewUrl = URL.createObjectURL(file);
        } catch (e) {
          // ObjectURL not supported or invalid blob
        }
      }

      this.$emit('update:modelValue', file);
    },
    onFileInputChange(event) {
      const file = event.target.files?.[0] || null;
      this.validateAndEmit(file);
      // Reset input value so re-selecting same file triggers change
      event.target.value = '';
    },
    onDrop(event) {
      this.isDragging = false;
      const file = event.dataTransfer?.files?.[0] || null;
      this.validateAndEmit(file);
    },
    removeImage() {
      this.clearPreview();
      this.$emit('update:modelValue', null);
    }
  },
  template: `
    <div class="vg-image-preview-container" style="margin-bottom: 16px;">
      <!-- Hidden file input -->
      <input
        :id="inputId"
        type="file"
        accept="image/jpeg,image/png,image/webp"
        style="display: none;"
        @change="onFileInputChange"
      />

      <!-- Drop zone & Selector (when no image selected) -->
      <div
        v-if="!modelValue || !previewUrl"
        :style="{
          border: isDragging ? '2px dashed var(--color-primary, #2560ff)' : '2px dashed var(--color-hairline, #c8cfda)',
          backgroundColor: isDragging ? 'var(--color-primary-subtle, #e5f2fc)' : '#fafbfc',
          borderRadius: 'var(--radius-interactive, 4px)',
          padding: '16px',
          textAlign: 'center',
          cursor: 'pointer',
          transition: 'all 0.15s ease'
        }"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="onDrop"
        @click="$el.querySelector('#' + inputId).click()"
      >
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-ink-muted, #6c7e9d); margin-bottom: 6px;" aria-hidden="true">
          <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
          <circle cx="8.5" cy="8.5" r="1.5"></circle>
          <polyline points="21 15 16 10 5 21"></polyline>
        </svg>
        <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; font-weight: 500; color: var(--color-slate, #2c333f);">
          Adjuntar fotografía de la avería <span style="font-size: 11px; color: var(--color-ink-muted, #6c7e9d);">(opcional)</span>
        </div>
        <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 11px; color: var(--color-ink-muted, #6c7e9d); margin-top: 2px;">
          Arrastra una imagen o pulsa aquí · Máximo 5 MB (JPG, PNG o WebP)
        </div>
      </div>

      <!-- Preview Mode (when image is selected) -->
      <div
        v-else
        style="display: flex; align-items: center; justify-content: space-between; gap: 12px; background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); padding: 8px 12px;"
      >
        <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
          <img
            :src="previewUrl"
            alt="Vista previa de fotografía de avería"
            style="width: 48px; height: 48px; object-fit: cover; border-radius: 3px; border: 1px solid var(--color-hairline, #c8cfda);"
          />
          <div style="min-width: 0;">
            <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 600; color: var(--color-slate, #2c333f); text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
              {{ modelValue.name }}
            </div>
            <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 11px; color: var(--color-ink-muted, #6c7e9d);">
              {{ (modelValue.size / 1024).toFixed(0) }} KB · {{ modelValue.type }}
            </div>
          </div>
        </div>

        <button
          type="button"
          class="vg-btn vg-btn-secondary"
          style="padding: 4px 8px; height: 28px; font-size: 12px; color: #dc2626; border-radius: var(--radius-interactive, 4px);"
          @click.stop="removeImage"
          title="Eliminar foto seleccionada"
        >
          Quitar foto
        </button>
      </div>

      <!-- Error message -->
      <div
        v-if="errorMessage"
        style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 6px 10px; border-radius: var(--radius-interactive, 4px); font-size: 12px; margin-top: 6px; font-family: var(--font-body, Inter, sans-serif);"
        role="alert"
      >
        {{ errorMessage }}
      </div>
    </div>
  `
};

export default ImagePreview;
