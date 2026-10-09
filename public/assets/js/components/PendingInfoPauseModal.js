/**
 * VendGuard - PendingInfoPauseModal Component (PendingInfoPauseModal.js)
 * 
 * Componente modal reutilizable para pausar incidencias al estado "Pendiente de Información"
 * (PENDING_INFO) con congelamiento de SLA (Módulo 11, T-PAUSE-16, RF-01.2, RF-01.3, RNF-03, RNF-04, RNF-06, Art. V.1).
 * 
 * Requisitos:
 * 1. Selector de 4 causas tipificadas cerradas con botones táctiles >= 44px de altura (RNF-03).
 * 2. Textarea de justificación con contador reactivo dinámico que bloquea el envío si hay < 20 caracteres (RF-01.3, Art. V.1).
 * 3. Guardián de borrador sucio (RNF-06): intercepta Escape y clic en backdrop solicitando confirmación si hay cambios.
 * 4. Dogma Vanilla: módulo nativo ES6 para Vue 3 Options API sin dependencias externas.
 * 5. Tokens de diseño conformes a Docker Design System (docs/design.md, design-tokens.css).
 */

import { api, ApiError } from '../api.js';

export const PAUSE_REASON_CATEGORIES = Object.freeze([
  {
    value: 'BUILDING_CLOSED_NO_ACCESS',
    label: 'Edificio cerrado / Sin acceso a instalaciones',
    icon: '🏢'
  },
  {
    value: 'MACHINE_LOCATION_NOT_FOUND',
    label: 'Máquina no localizada en la planta indicada',
    icon: '📍'
  },
  {
    value: 'EXTERNAL_POWER_CUT',
    label: 'Corte eléctrico o de suministro ajeno a la máquina',
    icon: '⚡'
  },
  {
    value: 'PENDING_SITE_AUTHORIZATION',
    label: 'Pendiente de autorización o contacto de sede',
    icon: '🔑'
  }
]);

export const PendingInfoPauseModal = {
  name: 'PendingInfoPauseModal',
  props: {
    isOpen: {
      type: Boolean,
      default: false
    },
    incident: {
      type: Object,
      default: null
    },
    role: {
      type: String,
      default: 'TECHNICIAN'
    }
  },
  emits: ['close', 'paused'],
  data() {
    return {
      reasonCategories: PAUSE_REASON_CATEGORIES,
      selectedCategory: '',
      reasonText: '',
      isSubmitting: false,
      errorMessage: ''
    };
  },
  computed: {
    incidentId() {
      return this.incident?.id || this.incident?.incident_id || null;
    },
    ticketCode() {
      return this.incident?.ticket_code || this.incident?.code || 'INC-???';
    },
    machineCode() {
      return this.incident?.machine?.code || this.incident?.machine_code || 'VEND-???';
    },
    trimmedReasonLength() {
      return (this.reasonText || '').trim().length;
    },
    remainingChars() {
      return Math.max(0, 20 - this.trimmedReasonLength);
    },
    isValidLength() {
      return this.trimmedReasonLength >= 20;
    },
    canSubmit() {
      return Boolean(this.selectedCategory) && this.isValidLength && !this.isSubmitting;
    },
    isDirty() {
      return Boolean((this.reasonText || '').trim()) || Boolean(this.selectedCategory);
    }
  },
  watch: {
    isOpen: {
      immediate: true,
      handler(val) {
        if (val) {
          this.resetForm();
          this.attachListeners();
        } else {
          this.detachListeners();
        }
      }
    }
  },
  beforeUnmount() {
    this.detachListeners();
  },
  methods: {
    resetForm() {
      this.selectedCategory = '';
      this.reasonText = '';
      this.isSubmitting = false;
      this.errorMessage = '';
    },
    selectCategory(catValue) {
      this.selectedCategory = catValue;
      this.errorMessage = '';
    },
    attachListeners() {
      if (typeof window !== 'undefined') {
        window.addEventListener('keydown', this.handleKeyDown);
      }
      if (typeof document !== 'undefined' && document.body) {
        document.body.style.overflow = 'hidden';
      }
    },
    detachListeners() {
      if (typeof window !== 'undefined') {
        window.removeEventListener('keydown', this.handleKeyDown);
      }
      if (typeof document !== 'undefined' && document.body) {
        document.body.style.overflow = '';
      }
    },
    confirmDiscardChanges() {
      if (!this.isDirty) return true;
      const confirmFn = typeof globalThis.confirm === 'function' ? globalThis.confirm : null;
      if (confirmFn === null) return true;
      return confirmFn('¿Descartar el borrador de pausa y salir sin guardar?') === true;
    },
    requestClose() {
      if (this.confirmDiscardChanges()) {
        this.resetForm();
        this.$emit('close');
      }
    },
    handleKeyDown(event) {
      if (event.key === 'Escape' && this.isOpen) {
        event.preventDefault();
        this.requestClose();
      }
    },
    handleBackdropClick(event) {
      if (event.target === event.currentTarget) {
        this.requestClose();
      }
    },
    async submitPause() {
      if (!this.canSubmit) return;

      this.isSubmitting = true;
      this.errorMessage = '';

      try {
        const payload = await api.pauseIncidentPendingInfo(
          this.incidentId,
          this.selectedCategory,
          this.reasonText.trim(),
          this.role
        );

        this.$emit('paused', {
          incidentId: this.incidentId,
          ticketCode: this.ticketCode,
          pauseData: payload
        });

        this.resetForm();
        this.$emit('close');
      } catch (err) {
        if (err instanceof ApiError) {
          this.errorMessage = err.message || `Error ${err.status}: ${err.code}`;
        } else {
          this.errorMessage = err?.message || 'Error inesperado al declarar la pausa de la avería.';
        }
      } finally {
        this.isSubmitting = false;
      }
    }
  },
  template: `
    <div
      v-if="isOpen"
      class="vg-modal-backdrop pending-info-pause-backdrop"
      style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); display: flex; align-items: center; justify-content: center; z-index: 1050; padding: 16px; backdrop-filter: blur(2px);"
      @click="handleBackdropClick"
      role="presentation"
    >
      <div
        class="vg-modal-dialog pending-info-pause-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pause-modal-title"
        style="background: var(--color-surface-card); border-radius: var(--radius-card); width: 100%; max-width: 540px; max-height: 90vh; overflow-y: auto; box-shadow: var(--shadow-modal); border: 1px solid var(--color-hairline); display: flex; flex-direction: column;"
        @click.stop
      >
        <!-- Cabecera Informativa Ámbar -->
        <div style="background: var(--color-warning-bg); border-bottom: 1px solid var(--color-warning); padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; border-top-left-radius: var(--radius-card); border-top-right-radius: var(--radius-card);">
          <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 20px;">⏸️</span>
            <div>
              <h3 id="pause-modal-title" style="margin: 0; font-size: 16px; font-weight: 700; color: var(--color-warning-text); font-family: var(--font-display, 'DM Sans', sans-serif);">
                Pausar por Falta de Acceso / Sede
              </h3>
              <p style="margin: 2px 0 0 0; font-size: 12px; color: var(--color-warning-text); font-family: var(--font-body, 'Inter', sans-serif);">
                Avería {{ ticketCode }} · Máquina {{ machineCode }} (Congela SLA)
              </p>
            </div>
          </div>
          <button
            type="button"
            class="vg-modal-close-btn"
            style="background: transparent; border: none; font-size: 20px; color: var(--color-warning-text); cursor: pointer; min-height: 44px; min-width: 44px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-interactive);"
            aria-label="Cerrar modal"
            @click="requestClose"
          >
            ×
          </button>
        </div>

        <!-- Cuerpo del Formulario -->
        <div style="padding: 20px; display: flex; flex-direction: column; gap: 16px;">
          <!-- Error alert -->
          <div
            v-if="errorMessage"
            style="background: var(--color-error-bg); border: 1px solid var(--color-error); color: var(--color-error-text); padding: 10px 14px; border-radius: var(--radius-interactive); font-size: 13px;"
            role="alert"
          >
            ⚠️ {{ errorMessage }}
          </div>

          <!-- Selector de 4 causas tipificadas -->
          <div>
            <label style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 8px;">
              Causa de bloqueo justificada <span style="color: var(--color-error);">*</span>
            </label>
            <div style="display: flex; flex-direction: column; gap: 8px;">
              <button
                v-for="cat in reasonCategories"
                :key="cat.value"
                type="button"
                :class="['vg-cause-btn', { 'selected': selectedCategory === cat.value }]"
                :style="{
                  minHeight: '44px',
                  display: 'flex',
                  alignItems: 'center',
                  gap: '10px',
                  padding: '10px 14px',
                  borderRadius: 'var(--radius-interactive)',
                  border: selectedCategory === cat.value ? '2px solid var(--color-primary)' : '1px solid var(--color-hairline)',
                  background: selectedCategory === cat.value ? 'var(--color-primary-subtle)' : 'var(--color-surface-card)',
                  color: selectedCategory === cat.value ? 'var(--color-primary-dark)' : 'var(--color-slate)',
                  cursor: 'pointer',
                  fontSize: '13px',
                  textAlign: 'left',
                  fontWeight: selectedCategory === cat.value ? '600' : '400',
                  boxSizing: 'border-box'
                }"
                @click="selectCategory(cat.value)"
              >
                <span style="font-size: 16px;">{{ cat.icon }}</span>
                <span>{{ cat.label }}</span>
              </button>
            </div>
          </div>

          <!-- Textarea de justificación obligatoria >= 20 chars -->
          <div>
            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px;">
              <label for="pause-reason-text" style="font-size: 13px; font-weight: 600; color: var(--color-slate);">
                Detalle explicativo (Mínimo 20 caracteres) <span style="color: var(--color-error);">*</span>
              </label>
              <!-- Contador dinámico de caracteres -->
              <span
                :style="{
                  fontSize: '12px',
                  fontWeight: '600',
                  color: isValidLength ? 'var(--color-success-text)' : 'var(--color-error-text)'
                }"
              >
                <template v-if="!isValidLength">
                  Faltan {{ remainingChars }} caracteres
                </template>
                <template v-else>
                  ✓ {{ trimmedReasonLength }} caracteres
                </template>
              </span>
            </div>

            <textarea
              id="pause-reason-text"
              v-model="reasonText"
              rows="4"
              maxlength="500"
              placeholder="Describa el motivo exacto del impedimento (ej. Edificio cerrado por festivo local, conserje sin llave, corte de suministro ajeno...)"
              style="width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: var(--radius-interactive); border: 1px solid var(--color-hairline); font-size: 13px; font-family: var(--font-body, 'Inter', sans-serif); color: var(--color-slate); resize: vertical; outline: none;"
              :style="{ borderColor: isDirty && !isValidLength ? 'var(--color-warning)' : (isValidLength ? 'var(--color-success)' : 'var(--color-hairline)') }"
            ></textarea>
          </div>
        </div>

        <!-- Pie de acciones (Botones táctiles >= 44px) -->
        <div style="padding: 14px 20px; background: var(--color-canvas); border-top: 1px solid var(--color-hairline); display: flex; justify-content: flex-end; gap: 10px; border-bottom-left-radius: var(--radius-card); border-bottom-right-radius: var(--radius-card);">
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            style="min-height: 44px; min-width: 90px; padding: 0 16px; border-radius: var(--radius-interactive); border: 1px solid var(--color-hairline); background: var(--color-surface-card); color: var(--color-slate); font-size: 13px; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;"
            @click="requestClose"
            :disabled="isSubmitting"
          >
            Cancelar
          </button>
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            style="min-height: 44px; min-width: 140px; padding: 0 18px; border-radius: var(--radius-interactive); border: none; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px;"
            :style="{
              background: canSubmit ? 'var(--color-primary)' : 'var(--color-hairline-soft)',
              color: 'var(--color-surface-card)',
              cursor: canSubmit ? 'pointer' : 'not-allowed'
            }"
            :disabled="!canSubmit"
            @click="submitPause"
          >
            <span v-if="isSubmitting">Pausando...</span>
            <span v-else>Confirmar Pausa</span>
          </button>
        </div>
      </div>
    </div>
  `
};

export default PendingInfoPauseModal;
