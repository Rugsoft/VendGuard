/**
 * VendGuard - ModalDialog Component (ModalDialog.js)
 * 
 * Accessible, reusable modal dialog conforming to WAI-ARIA and Docker Design Tokens.
 * Follows Dogma Vanilla: pure Vue 3 component with in-browser template.
 * 
 * Features:
 * - WAI-ARIA compliance: role="dialog", aria-modal="true", aria-labelledby.
 * - Keyboard accessibility: initial focus, focus trap and 'Escape' close.
 * - Restores focus to the trigger after closing.
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
      titleId: `modal-title-${Math.random().toString(36).substr(2, 9)}`,
      previousFocus: null
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
            this.previousFocus = document.activeElement;
            document.body.style.overflow = 'hidden';
            if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
              window.addEventListener('keydown', this.handleKeyDown);
            }
            this.requestInitialFocus();
          } else {
            document.body.style.overflow = '';
            if (typeof window !== 'undefined' && typeof window.removeEventListener === 'function') {
              window.removeEventListener('keydown', this.handleKeyDown);
            }
            this.restoreFocus();
          }
        }
      }
    }
  },
  beforeUnmount() {
    if (typeof document !== 'undefined') {
      document.body.style.overflow = '';
      if (typeof window !== 'undefined' && typeof window.removeEventListener === 'function') {
        window.removeEventListener('keydown', this.handleKeyDown);
      }
    }
  },
  methods: {
    close() {
      this.$emit('update:modelValue', false);
      this.$emit('close');
    },
    requestInitialFocus() {
      const focusInitial = () => this.focusInitialElement();
      if (typeof this.$nextTick === 'function') this.$nextTick(focusInitial);
      else focusInitial();
    },
    focusInitialElement() {
      const target = this.focusableElements()[0] || this.$refs?.modalDialog;
      if (target && typeof target.focus === 'function') target.focus();
    },
    restoreFocus() {
      const trigger = this.previousFocus;
      this.previousFocus = null;
      if (!trigger || trigger.isConnected === false || typeof trigger.focus !== 'function') return;
      const focusTrigger = () => {
        if (trigger.isConnected === false) return;
        trigger.focus();
      };
      if (typeof this.$nextTick === 'function') this.$nextTick(focusTrigger);
      else focusTrigger();
    },
    focusableElements() {
      const dialog = this.$refs?.modalDialog;
      if (!dialog || typeof dialog.querySelectorAll !== 'function') return [];
      const selector = [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled]):not([type="hidden"])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])'
      ].join(', ');
      return Array.from(dialog.querySelectorAll(selector))
        .filter((element) => element && typeof element.focus === 'function')
        .filter((element) => !element.hidden && (typeof element.getClientRects !== 'function' || element.getClientRects().length > 0));
    },
    trapFocus(event) {
      const dialog = this.$refs?.modalDialog;
      if (!dialog) return;
      const focusable = this.focusableElements();
      if (focusable.length === 0) {
        if (typeof event.preventDefault === 'function') event.preventDefault();
        if (typeof dialog.focus === 'function') dialog.focus();
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      const active = typeof document !== 'undefined' ? document.activeElement : null;
      const inside = !!(active && typeof dialog.contains === 'function' && dialog.contains(active));
      if (event.shiftKey && (!inside || active === first || active === dialog)) {
        if (typeof event.preventDefault === 'function') event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (!inside || active === last || active === dialog)) {
        if (typeof event.preventDefault === 'function') event.preventDefault();
        first.focus();
      }
    },
    handleBackdropClick(event) {
      if (this.closeOnBackdrop && event.target === event.currentTarget) {
        this.close();
      }
    },
    handleKeyDown(event) {
      if (!this.modelValue) return;
      if (event.key === 'Escape') {
        this.close();
        return;
      }
      if (event.key === 'Tab') this.trapFocus(event);
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
          ref="modalDialog"
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
              class="vg-btn vg-btn-ghost vg-btn-icon vg-btn-close"
              aria-label="Cerrar ventana modal"
              @click="close"
            ></button>
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
