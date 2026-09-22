/**
 * VendGuard - ModalDialog Component (ModalDialog.js)
 * 
 * Accessible, reusable modal dialog conforming to WAI-ARIA and Docker Design Tokens.
 * Follows Dogma Vanilla: pure Vue 3 component with in-browser template.
 * 
 * Features:
 * - WAI-ARIA compliance: role="dialog", aria-modal="true", aria-labelledby.
 * - Keyboard accessibility: closes on 'Escape' key press.
 * - Prevents background page scrolling while modal is active.
 * - 8px card border radius conforming to RNF-06 containers.
 * - Named slots: header, default (body), footer (action buttons).
 */

export const ModalDialog = {
  name: 'ModalDialog',
  props: {
    /**
     * Controls dialog visibility (v-model)
     */
    modelValue: {
      type: Boolean,
      default: false
    },
    /**
     * Main title displayed in the modal header
     */
    title: {
      type: String,
      default: ''
    },
    /**
     * Optional secondary subtitle
     */
    subtitle: {
      type: String,
      default: ''
    },
    /**
     * Max-width size variant: 'sm' (420px) | 'md' (560px) | 'lg' (720px)
     */
    size: {
      type: String,
      default: 'md'
    },
    /**
     * Whether clicking on the backdrop closes the modal
     */
    closeOnBackdrop: {
      type: Boolean,
      default: true
    },
    /**
     * Whether to show the top-right close 'x' button
     */
    showCloseButton: {
      type: Boolean,
      default: true
    }
  },
  emits: ['update:modelValue', 'close'],
  data() {
    return {
      titleId: `modal-title-${Math.random().toString(36).substr(2, 9)}`
    };
  },
  computed: {
    maxWidthPx() {
      switch (this.size) {
        case 'sm': return '420px';
        case 'lg': return '720px';
        default: return '560px';
      }
    }
  },
  watch: {
    modelValue: {
      immediate: true,
      handler(isOpen) {
        if (typeof document !== 'undefined') {
          if (isOpen) {
            document.body.style.overflow = 'hidden';
            window.addEventListener('keydown', this.handleKeyDown);
          } else {
            document.body.style.overflow = '';
            window.removeEventListener('keydown', this.handleKeyDown);
          }
        }
      }
    }
  },
  beforeUnmount() {
    if (typeof document !== 'undefined') {
      document.body.style.overflow = '';
      window.removeEventListener('keydown', this.handleKeyDown);
    }
  },
  methods: {
    close() {
      this.$emit('update:modelValue', false);
      this.$emit('close');
    },
    handleBackdropClick(event) {
      if (this.closeOnBackdrop && event.target === event.currentTarget) {
        this.close();
      }
    },
    handleKeyDown(event) {
      if (event.key === 'Escape' && this.modelValue) {
        this.close();
      }
    }
  },
  template: `
    <Teleport to="body" :disabled="typeof document === 'undefined'">
      <div
        v-if="modelValue"
        class="vg-modal-backdrop"
        style="position: fixed; inset: 0; background-color: rgba(0, 0, 0, 0.45); display: flex; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(2px); padding: 16px;"
        @click="handleBackdropClick"
        role="presentation"
      >
        <div
          class="vg-modal"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="titleId"
          tabindex="-1"
          style="background-color: #ffffff; border-radius: var(--radius-card, 8px); box-shadow: var(--shadow-modal, 0 12px 32px rgba(0, 0, 0, 0.15)); width: 100%; max-height: 90vh; display: flex; flex-direction: column; border: 1px solid var(--color-hairline, #c8cfda); outline: none; overflow: hidden;"
          :style="{ maxWidth: maxWidthPx }"
        >
          <!-- Header -->
          <div
            style="padding: 16px 20px; border-bottom: 1px solid var(--color-hairline, #c8cfda); display: flex; align-items: flex-start; justify-content: space-between; background-color: #ffffff;"
          >
            <div>
              <slot name="header">
                <h3
                  :id="titleId"
                  style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 18px; font-weight: 600; color: var(--color-ink, #000000); margin: 0;"
                >
                  {{ title }}
                </h3>
                <p
                  v-if="subtitle"
                  style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-muted, #6c7e9d); margin: 4px 0 0 0;"
                >
                  {{ subtitle }}
                </p>
              </slot>
            </div>

            <button
              v-if="showCloseButton"
              type="button"
              class="vg-btn-close"
              aria-label="Cerrar ventana modal"
              @click="close"
              style="background: transparent; border: none; font-size: 20px; line-height: 1; cursor: pointer; color: var(--color-ink-muted, #6c7e9d); border-radius: 4px; padding: 4px 8px; margin: -4px -8px 0 0; transition: color 0.15s ease;"
              onmouseover="this.style.color='#000000'"
              onmouseout="this.style.color='#6c7e9d'"
            >
              &times;
            </button>
          </div>

          <!-- Body / Content -->
          <div
            style="padding: 20px; overflow-y: auto; flex: 1 1 auto; font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-slate, #2c333f); line-height: 1.5;"
          >
            <slot></slot>
          </div>

          <!-- Footer (Optional) -->
          <div
            v-if="$slots.footer"
            style="padding: 12px 20px; border-top: 1px solid var(--color-hairline, #c8cfda); background-color: #fafbfc; display: flex; align-items: center; justify-content: flex-end; gap: 10px;"
          >
            <slot name="footer"></slot>
          </div>
        </div>
      </div>
    </Teleport>
  `
};

export default ModalDialog;
