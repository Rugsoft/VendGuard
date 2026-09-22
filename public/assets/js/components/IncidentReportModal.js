/**
 * VendGuard - IncidentReportModal Component (IncidentReportModal.js)
 * 
 * Guided incident reporting dialog with strict duplicate prevention (RF-02, RF-03, RNF-02, RNF-05, T-35).
 * 
 * Features:
 * 1. Fast guided reporting workflow (< 2 min UX, RNF-02).
 * 2. Automatic Health Precaution Alert for temperature loss on PERISHABLE_FOOD machines (Art. II).
 * 3. Strict duplicate detection: if machine already has active ticket, blocks creation and enables
 *    appending comments/photos to the existing ticket log (RF-02 / EARS 2.1, 2.3).
 * 4. Image preview with 5 MB limit check (RNF-05) preserving text data on upload failures (EARS 3.9).
 * 5. Uses ModalDialog (WAI-ARIA, 8px radius) and Docker design tokens.
 */

import { api } from '../api.js';
import { store } from '../store.js';
import { ModalDialog } from './ModalDialog.js';
import { IncidentBadge } from './IncidentBadge.js';
import { ImagePreview } from './ImagePreview.js';

// Predefined incident categories per spec
const INCIDENT_CATEGORIES = [
  {
    value: 'TEMPERATURE_COLD',
    label: 'Temperatura / Pérdida de frío',
    description: 'Fallo en motor frigorífico o máquina marcando temperatura alta.',
    icon: '❄️'
  },
  {
    value: 'PAYMENT_SYSTEM',
    label: 'Fallo en medios de pago',
    description: 'No acepta monedas, billetes, tarjeta bancaria o billetero atascado.',
    icon: '💳'
  },
  {
    value: 'PRODUCT_JAM',
    label: 'Atasco de producto en espiral',
    description: 'El motor gira pero el producto queda atrapado en el carril.',
    icon: '⚠️'
  },
  {
    value: 'ELECTRICAL_OFF',
    label: 'Máquina apagada / Sin suministro',
    description: 'Pantalla apagada, sin iluminación ni respuesta eléctrica.',
    icon: '🔌'
  },
  {
    value: 'COSMETIC_LIGHTING',
    label: 'Iluminación / Desperfecto estético',
    description: 'Luces LED fundidas o pequeños daños externos sin afectar venta.',
    icon: '💡'
  },
  {
    value: 'OTHER',
    label: 'Otro motivo',
    description: 'Cualquier otra anomalía no clasificada anteriormente.',
    icon: '📝'
  }
];

export const IncidentReportModal = {
  name: 'IncidentReportModal',
  components: {
    ModalDialog,
    IncidentBadge,
    ImagePreview
  },
  props: {
    modelValue: {
      type: Boolean,
      default: false
    },
    machine: {
      type: Object,
      default: null
    }
  },
  emits: ['update:modelValue', 'created', 'commented', 'close'],
  data() {
    return {
      // New Incident Form
      category: 'TEMPERATURE_COLD',
      description: '',
      retainedMoney: '',
      reporterName: '',
      reporterPhone: '',
      photoFile: null,

      // Comment Form (for existing duplicate tickets)
      commentText: '',
      commentAuthor: '',
      commentPhotoFile: null,

      // UI State
      isSubmitting: false,
      errorMessage: '',
      duplicateIncidentData: null // Set when a 409 Conflict occurs or machine has active ticket
    };
  },
  computed: {
    isOpen: {
      get() { return this.modelValue; },
      set(val) { this.$emit('update:modelValue', val); }
    },
    hasActiveIncident() {
      return Boolean(this.machine?.active_incident || this.duplicateIncidentData);
    },
    activeIncident() {
      return this.duplicateIncidentData || this.machine?.active_incident || null;
    },
    modalTitle() {
      if (!this.machine) return 'Reportar avería';
      if (this.hasActiveIncident) {
        return `Avería en curso · Máquina ${this.machine.code}`;
      }
      return `Nueva avería · Máquina ${this.machine.code}`;
    },
    modalSubtitle() {
      if (!this.machine) return '';
      return `${this.machine.model || 'Vending'} (${this.machine.floor_wing || 'Ubicación'})`;
    },
    isFoodSafetyCritical() {
      if (!this.machine) return false;
      return this.machine.machine_type === 'PERISHABLE_FOOD' && this.category === 'TEMPERATURE_COLD';
    },
    categories() {
      return INCIDENT_CATEGORIES;
    }
  },
  watch: {
    modelValue(isOpen) {
      if (isOpen) {
        this.resetForm();
        if (this.machine?.active_incident) {
          this.duplicateIncidentData = this.machine.active_incident;
        }
      }
    },
    machine(newMachine) {
      if (newMachine?.active_incident) {
        this.duplicateIncidentData = newMachine.active_incident;
      } else {
        this.duplicateIncidentData = null;
      }
    }
  },
  methods: {
    resetForm() {
      this.category = 'TEMPERATURE_COLD';
      this.description = '';
      this.retainedMoney = '';
      this.photoFile = null;
      this.commentText = '';
      this.commentPhotoFile = null;
      this.errorMessage = '';

      // Prefill reporter name/phone from session if location responsible
      const location = store.state.location;
      if (location?.contact_name && !this.reporterName) {
        this.reporterName = location.contact_name;
      }
      if (!this.commentAuthor && this.reporterName) {
        this.commentAuthor = this.reporterName;
      }
    },
    closeModal() {
      this.isOpen = false;
      this.$emit('close');
    },

    /**
     * Submits a new incident report (RF-02, RF-03, RNF-05)
     */
    async handleSubmitReport() {
      if (!this.machine) return;

      this.errorMessage = '';
      this.isSubmitting = true;
      store.setLoading(true);

      try {
        let payload;
        // Use FormData if image is attached
        if (this.photoFile) {
          payload = new FormData();
          payload.append('machine_id', String(this.machine.id));
          payload.append('category', this.category);
          payload.append('description', this.description.trim());
          payload.append('reporter_name', this.reporterName.trim());
          payload.append('reporter_phone', this.reporterPhone.trim());
          if (this.retainedMoney !== '' && this.retainedMoney !== null) {
            payload.append('retained_money_amount', String(this.retainedMoney));
          }
          payload.append('photo', this.photoFile);
        } else {
          // Standard JSON payload
          payload = {
            machine_id: this.machine.id,
            category: this.category,
            description: this.description.trim(),
            reporter_name: this.reporterName.trim(),
            reporter_phone: this.reporterPhone.trim()
          };
          if (this.retainedMoney !== '' && this.retainedMoney !== null) {
            payload.retained_money_amount = Number(this.retainedMoney);
          }
        }

        const createdIncident = await api.incidents.create(payload);

        store.addAlert(
          `Avería registrada con éxito. Ticket #${createdIncident.ticket_code}`,
          'success',
          6000
        );

        this.$emit('created', createdIncident);
        this.closeModal();
      } catch (err) {
        // Handle 409 Conflict: machine has active incident (RF-02 strict prevention)
        if (err.status === 409) {
          this.duplicateIncidentData = {
            ticket_code: err.details?.ticket_code || 'ACTIVO',
            status: err.details?.status || 'REGISTRADA',
            urgency: err.details?.urgency || 'MEDIUM',
            message: err.message
          };
          this.errorMessage = err.message || 'Esta máquina ya cuenta con un aviso activo. Se ha bloqueado la creación.';
        } else {
          this.errorMessage = err.message || 'Error al registrar la incidencia. Por favor, revise los datos.';
        }
      } finally {
        this.isSubmitting = false;
        store.setLoading(false);
      }
    },

    /**
     * Appends a comment/photo to an active duplicate incident (RF-02 / EARS 2.3)
     */
    async handleSubmitComment() {
      if (!this.activeIncident?.ticket_code) return;

      const ticketCode = this.activeIncident.ticket_code;
      this.errorMessage = '';
      this.isSubmitting = true;
      store.setLoading(true);

      try {
        let payload;
        if (this.commentPhotoFile) {
          payload = new FormData();
          payload.append('author_name', this.commentAuthor.trim() || 'Responsable de Sede');
          payload.append('comment', this.commentText.trim());
          payload.append('photo', this.commentPhotoFile);
        } else {
          payload = {
            author_name: this.commentAuthor.trim() || 'Responsable de Sede',
            comment: this.commentText.trim()
          };
        }

        const commentResponse = await api.incidents.addComment(ticketCode, payload);

        store.addAlert(
          `Información anexada correctamente al Ticket #${ticketCode}.`,
          'success',
          5000
        );

        this.$emit('commented', commentResponse);
        this.closeModal();
      } catch (err) {
        this.errorMessage = err.message || 'Error al anexar comentario al ticket.';
      } finally {
        this.isSubmitting = false;
        store.setLoading(false);
      }
    }
  },
  template: `
    <ModalDialog
      v-model="isOpen"
      :title="modalTitle"
      :subtitle="modalSubtitle"
      size="md"
      @close="closeModal"
    >
      <!-- =================================================================== -->
      <!-- CASE 1: MACHINE WITH ACTIVE INCIDENT (RF-02 DUPLICATE BLOCKED)       -->
      <!-- =================================================================== -->
      <div v-if="hasActiveIncident" class="vg-duplicate-incident-container">
        <!-- Active incident banner -->
        <div
          style="background-color: #fef2f2; border: 1px solid #fee2e2; border-radius: var(--radius-interactive, 4px); padding: 14px; margin-bottom: 20px;"
        >
          <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px;">
            <div style="display: flex; align-items: center; gap: 8px;">
              <span style="font-size: 20px;">🛡️</span>
              <strong style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 15px; color: #991b1b;">
                Aviso activo preexistente · Ticket #{{ activeIncident.ticket_code }}
              </strong>
            </div>
            <div style="display: flex; gap: 4px;">
              <IncidentBadge
                v-if="activeIncident.urgency"
                :value="activeIncident.urgency"
                type="urgency"
                size="sm"
              />
              <IncidentBadge
                v-if="activeIncident.status"
                :value="activeIncident.status"
                type="status"
                size="sm"
              />
            </div>
          </div>
          <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: #7f1d1d; margin: 0; line-height: 1.4;">
            Esta máquina ya cuenta con un parte de avería en curso. El sistema <strong>bloquea automáticamente la creación de partes duplicados</strong> para evitar visitas técnicas solapadas (RF-02).
          </p>
        </div>

        <!-- Add comments / additional photos form -->
        <h4 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 15px; font-weight: 600; color: var(--color-ink, #000000); margin: 0 0 8px 0;">
          Anexar comentarios o fotos adicionales a este ticket
        </h4>
        <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-muted, #6c7e9d); margin: 0 0 16px 0;">
          Tu información se sumará al expediente del técnico sin alterar el registro original ni las fotos previas (EARS 2.3).
        </p>

        <form @submit.prevent="handleSubmitComment">
          <!-- Author Name -->
          <div style="margin-bottom: 14px;">
            <label style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 4px;">
              Tu nombre o cargo
            </label>
            <input
              v-model="commentAuthor"
              type="text"
              class="vg-input"
              placeholder="Ej: Recepción / Conserjería"
              required
              :disabled="isSubmitting"
            />
          </div>

          <!-- Comment Text -->
          <div style="margin-bottom: 14px;">
            <label style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 4px;">
              Comentario o detalle adicional
            </label>
            <textarea
              v-model="commentText"
              class="vg-textarea"
              placeholder="Ej: El cliente indica que además la puerta de recogida está atascada..."
              rows="3"
              required
              :disabled="isSubmitting"
            ></textarea>
          </div>

          <!-- Image upload for comment (<= 5MB) -->
          <ImagePreview
            v-model="commentPhotoFile"
            :max-size-mb="5"
          />

          <!-- Error Alert -->
          <div
            v-if="errorMessage"
            style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 10px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 14px;"
            role="alert"
          >
            {{ errorMessage }}
          </div>

          <!-- Submit Buttons -->
          <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px;">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              @click="closeModal"
              :disabled="isSubmitting"
            >
              Cancelar
            </button>
            <button
              type="submit"
              class="vg-btn vg-btn-primary"
              :disabled="isSubmitting || !commentText.trim()"
            >
              <span v-if="!isSubmitting">Anexar a Ticket #{{ activeIncident.ticket_code }}</span>
              <span v-else>Guardando comentario...</span>
            </button>
          </div>
        </form>
      </div>

      <!-- =================================================================== -->
      <!-- CASE 2: NEW INCIDENT REPORT FORM (RF-03, RNF-02 < 2 min)           -->
      <!-- =================================================================== -->
      <form v-else @submit.prevent="handleSubmitReport" class="vg-report-form">
        <!-- 1. Category Selection -->
        <div style="margin-bottom: 16px;">
          <label style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 6px;">
            Categoría del fallo observado <span style="color: #dc2626;">*</span>
          </label>
          <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px;">
            <label
              v-for="cat in categories"
              :key="cat.value"
              :style="{
                border: category === cat.value ? '2px solid var(--color-primary, #2560ff)' : '1px solid var(--color-hairline, #c8cfda)',
                backgroundColor: category === cat.value ? 'var(--color-primary-subtle, #e5f2fc)' : '#ffffff',
                borderRadius: 'var(--radius-interactive, 4px)',
                padding: '8px 12px',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                gap: '8px',
                transition: 'all 0.15s ease'
              }"
            >
              <input
                type="radio"
                name="incident-category"
                :value="cat.value"
                v-model="category"
                style="display: none;"
              />
              <span style="font-size: 18px;" aria-hidden="true">{{ cat.icon }}</span>
              <div style="min-width: 0;">
                <div style="font-size: 13px; font-weight: 600; color: var(--color-ink, #000000);">
                  {{ cat.label }}
                </div>
              </div>
            </label>
          </div>
        </div>

        <!-- Food Safety Alert (Art. II / EARS 3.2) -->
        <div
          v-if="isFoodSafetyCritical"
          style="background-color: #fee2e2; border: 1px solid #f87171; border-radius: var(--radius-interactive, 4px); padding: 10px 14px; margin-bottom: 16px; display: flex; align-items: flex-start; gap: 10px;"
        >
          <span style="font-size: 20px;">🚨</span>
          <div>
            <strong style="color: #991b1b; font-size: 13px; display: block;">
              Prioridad Sanitaria Crítica (Art. II Constitución)
            </strong>
            <span style="color: #7f1d1d; font-size: 12px; line-height: 1.3; display: block;">
              Esta máquina contiene alimentos perecederos frescos. El aviso se asignará como <strong>CRÍTICA</strong> inmediata con SLA máximo de 60 minutos.
            </span>
          </div>
        </div>

        <!-- 2. Description (Mandatory) -->
        <div style="margin-bottom: 16px;">
          <label for="incident-description" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 4px;">
            Descripción del fallo <span style="color: #dc2626;">*</span>
          </label>
          <textarea
            id="incident-description"
            v-model="description"
            class="vg-textarea"
            placeholder="Describe brevemente qué ocurre (ej: la máquina no enfría y los sándwiches están templados)..."
            rows="3"
            required
            :disabled="isSubmitting"
          ></textarea>
        </div>

        <!-- 3. Retained Money (Optional - EARS 3.8) -->
        <div style="margin-bottom: 16px;">
          <label for="incident-retained-money" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 4px;">
            ¿Se tragó dinero la máquina? <span style="font-size: 11px; color: var(--color-ink-muted, #6c7e9d);">(opcional)</span>
          </label>
          <div style="position: relative; max-width: 180px;">
            <input
              id="incident-retained-money"
              v-model="retainedMoney"
              type="number"
              step="0.05"
              min="0"
              class="vg-input"
              placeholder="0.00"
              style="padding-right: 28px;"
              :disabled="isSubmitting"
            />
            <span style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); font-size: 13px; color: var(--color-ink-muted, #6c7e9d);">€</span>
          </div>
        </div>

        <!-- 4. Photo Upload (Optional <= 5MB - RNF-05, EARS 3.9) -->
        <ImagePreview
          v-model="photoFile"
          :max-size-mb="5"
        />

        <!-- 5. Informant Details (Mandatory) -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
          <div>
            <label for="reporter-name" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 4px;">
              Tu nombre <span style="color: #dc2626;">*</span>
            </label>
            <input
              id="reporter-name"
              v-model="reporterName"
              type="text"
              class="vg-input"
              placeholder="Ej: Laura Sanitaria"
              required
              :disabled="isSubmitting"
            />
          </div>
          <div>
            <label for="reporter-phone" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 4px;">
              Teléfono de contacto <span style="color: #dc2626;">*</span>
            </label>
            <input
              id="reporter-phone"
              v-model="reporterPhone"
              type="tel"
              class="vg-input"
              placeholder="Ej: 600112233"
              required
              :disabled="isSubmitting"
            />
          </div>
        </div>

        <!-- Error alert -->
        <div
          v-if="errorMessage"
          style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 10px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 16px;"
          role="alert"
        >
          {{ errorMessage }}
        </div>

        <!-- Modal Footer Actions -->
        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; border-top: 1px solid var(--color-hairline, #c8cfda); padding-top: 16px;">
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            @click="closeModal"
            :disabled="isSubmitting"
          >
            Cancelar
          </button>
          <button
            type="submit"
            class="vg-btn vg-btn-primary"
            :disabled="isSubmitting || !description.trim() || !reporterName.trim() || !reporterPhone.trim()"
          >
            <span v-if="!isSubmitting">Registrar aviso de avería</span>
            <span v-else>Enviando aviso...</span>
          </button>
        </div>
      </form>
    </ModalDialog>
  `
};

export default IncidentReportModal;
