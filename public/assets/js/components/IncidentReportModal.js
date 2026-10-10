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
import { QrRefundRequestBlock } from './QrRefundRequestBlock.js';
import { INCIDENT_CATEGORIES, INCIDENT_STATUSES, URGENCY_LEVELS } from '../utils/IncidentStatusPermissions.js';

export const IncidentReportModal = {
  name: 'IncidentReportModal',
  components: {
    ModalDialog,
    IncidentBadge,
    ImagePreview,
    QrRefundRequestBlock
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
      refundClaim: { refund_requested: false },
      refundClaimValid: true,
      lastRefundReceipt: null,
      // Casilla obligatoria de confirmación de acceso (RF-04.6, Art. V.2).
      accessConfirmed: false,

      // Comment Form (for existing duplicate tickets)
      commentText: '',
      commentAuthor: '',
      commentPhotoFile: null,

      // UI State
      isSubmitting: false,
      errorMessage: '',
      duplicateIncidentData: null, // Set when a 409 Conflict occurs or machine has active ticket
      submittedSuccessData: null // Set when an incident with refund is created to show confirmation screen
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
      if (this.submittedSuccessData) return 'Aviso y Solicitud Registrados';
      if (!this.machine) return 'Reportar avería';
      if (this.hasActiveIncident) {
        return `Avería en curso · Máquina ${this.machine.code}`;
      }
      return `Nueva avería · Máquina ${this.machine.code}`;
    },
    modalSubtitle() {
      if (this.submittedSuccessData) {
        return `Ticket #${this.submittedSuccessData.ticket_code} · ${this.machine?.code || ''}`;
      }
      if (!this.machine) return '';
      return `${this.machine.model || 'Vending'} (${this.machine.floor_wing || 'Ubicación'})`;
    },
    isFoodSafetyCritical() {
      if (!this.machine) return false;
      return this.machine.machine_type === 'PERISHABLE_FOOD' && this.category === 'TEMPERATURE_COLD';
    },
    categories() {
      return INCIDENT_CATEGORIES;
    },
    refundRequested() {
      return this.refundClaim?.refund_requested === true;
    },
    hasPhysicalReception() {
      return store.state.location?.has_physical_reception !== false;
    },
    /**
     * La máquina figura fuera de servicio por falta de acceso previo: el aviso nuevo
     * exige la confirmación formal de la sede (RF-04.6, Art. V.2) y el servidor la
     * vuelve a exigir; la casilla es la constancia del acto, no una sugerencia.
     */
    requiresAccessConfirmation() {
      return this.machine?.is_blocked_no_access === true;
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
      this.refundClaim = { refund_requested: false };
      this.refundClaimValid = true;
      this.lastRefundReceipt = null;
      this.submittedSuccessData = null;
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

    handleRefundClaimUpdate(newClaim) {
      this.refundClaim = newClaim || { refund_requested: false };
      if (this.refundRequested && newClaim?.claimed_amount) {
        this.retainedMoney = String(newClaim.claimed_amount);
      }
    },
    handleRefundClaimValidity(isValid) {
      this.refundClaimValid = Boolean(isValid);
    },

    /**
     * Submits a new incident report (RF-02, RF-03, RNF-05)
     */
    async handleSubmitReport() {
      if (!this.machine) return;
      if (this.refundRequested && !this.refundClaimValid) return;

      // RF-04.6: sin la casilla marcada no hay aviso posible sobre una máquina
      // bloqueada por falta de acceso; la confirmación viaja en el propio alta.
      if (this.requiresAccessConfirmation && !this.accessConfirmed) {
        this.errorMessage = 'Debe marcar la casilla de confirmación formal de acceso antes de registrar el aviso (RF-04.6).';
        return;
      }

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
          if (this.refundRequested) {
            for (const [key, value] of Object.entries(this.refundClaim)) {
              payload.append(key, value === null || value === undefined ? '' : String(value));
            }
          }
          if (this.requiresAccessConfirmation) {
            payload.append('access_confirmed', 'true');
          }
          payload.append('photo', this.photoFile);
        } else {
          // Standard JSON payload
          payload = {
            machine_id: this.machine.id,
            category: this.category,
            description: this.description.trim(),
            reporter_name: this.reporterName.trim(),
            reporter_phone: this.reporterPhone.trim(),
            ...(this.refundRequested ? this.refundClaim : {}),
            ...(this.requiresAccessConfirmation ? { access_confirmed: true } : {})
          };
          if (this.retainedMoney !== '' && this.retainedMoney !== null) {
            payload.retained_money_amount = Number(this.retainedMoney);
          }
        }

        const createdIncident = await api.incidents.create(payload);

        let successMsg = `Avería registrada con éxito. Ticket #${createdIncident.ticket_code}`;
        if (createdIncident.refund) {
          this.lastRefundReceipt = createdIncident.refund;
          this.submittedSuccessData = createdIncident;
          if (createdIncident.refund.pickup_pin) {
            successMsg += ` · Expediente de reintegro abierto con PIN: ${createdIncident.refund.pickup_pin}`;
          } else {
            successMsg += ' · Expediente de reintegro digital abierto correctamente.';
          }
        }

        store.addAlert(
          successMsg,
          'success',
          8000
        );

        this.$emit('created', createdIncident);

        // Si hay resguardo de devolución (especialmente entrega en mano con PIN),
        // mantenemos el modal abierto mostrando la pantalla de resguardo hasta que pulse Entendido
        if (!createdIncident.refund) {
          this.closeModal();
        }
      } catch (err) {
        // Handle 409 Conflict: machine has active incident (RF-02 strict prevention)
        if (err.status === 409) {
          this.duplicateIncidentData = {
            ticket_code: err.details?.ticket_code || 'ACTIVO',
            status: err.details?.status || INCIDENT_STATUSES.REGISTERED,
            urgency: err.details?.urgency || URGENCY_LEVELS.MEDIUM,
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
      <!-- CASE 0: SUCCESS CONFIRMATION WITH REFUND RECEIPT & PICKUP PIN       -->
      <!-- =================================================================== -->
      <div v-if="submittedSuccessData" class="vg-refund-success-receipt" style="padding: 4px 0;">
        <div style="text-align: center; margin-bottom: 20px;">
          <div style="width: 52px; height: 52px; border-radius: 50%; background-color: #eaf8f1; color: #0d824d; font-size: 26px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 10px;">
            ✓
          </div>
          <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 18px; font-weight: 700; color: var(--color-ink, #000000); margin: 0 0 4px 0;">
            Aviso y Reintegro Registrados
          </h3>
          <p style="font-size: 13px; color: var(--color-ink-muted, #6c7e9d); margin: 0;">
            Se ha creado el ticket <strong>#{{ submittedSuccessData.ticket_code }}</strong> para la máquina <strong>{{ machine?.code }}</strong>.
          </p>
        </div>

        <!-- Tarjeta destacada de PIN de Entrega en Conserjería -->
        <div
          v-if="submittedSuccessData.refund?.pickup_pin"
          style="background: #ffffff; border: 2px solid var(--color-primary, #2560ff); border-radius: 8px; padding: 18px; text-align: center; margin-bottom: 16px; box-shadow: 0 4px 12px rgba(37, 96, 255, 0.08);"
          data-testid="refund-pin-receipt"
        >
          <span style="display: block; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-primary, #2560ff); margin-bottom: 6px;">
            PIN de Recogida en Conserjería
          </span>
          <div style="font-size: 38px; font-weight: 800; letter-spacing: 0.25em; color: var(--color-ink, #000000); font-family: monospace; margin: 4px 0 10px 0;">
            {{ submittedSuccessData.refund.pickup_pin }}
          </div>
          <p style="font-size: 13px; color: var(--color-slate, #2c333f); margin: 0; line-height: 1.4;">
            Facilita este <strong>PIN de 4 dígitos</strong> a la persona afectada.<br>
            El conserje o recepcionista lo requerirá en la pestaña <em>Reintegros</em> para entregarle el sobre con el dinero.
          </p>
        </div>

        <!-- Resumen de Reintegro Digital (Bizum / Transferencia) -->
        <div
          v-else-if="submittedSuccessData.refund"
          style="background: #f8fafc; border: 1px solid var(--color-hairline, #c8cfda); border-radius: 6px; padding: 14px; margin-bottom: 16px;"
        >
          <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 4px;">
            <span style="color: var(--color-ink-muted, #6c7e9d);">Vía de compensación:</span>
            <strong>{{ submittedSuccessData.refund.compensation_method === 'BIZUM' ? 'Bizum' : 'Transferencia Bancaria' }}</strong>
          </div>
          <div style="display: flex; justify-content: space-between; font-size: 13px;">
            <span style="color: var(--color-ink-muted, #6c7e9d);">Importe solicitado:</span>
            <strong>{{ Number(submittedSuccessData.refund.claimed_amount).toFixed(2) }} €</strong>
          </div>
        </div>

        <!-- Enlace público de seguimiento si aplica -->
        <div v-if="submittedSuccessData.refund?.tracking_url" style="margin-bottom: 20px; text-align: center;">
          <a
            :href="submittedSuccessData.refund.tracking_url"
            target="_blank"
            class="vg-btn vg-btn-secondary"
            style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; height: 32px; text-decoration: none;"
          >
            🔗 Abrir enlace de seguimiento del afectado
          </a>
        </div>

        <!-- Botón de Cierre -->
        <div style="display: flex; justify-content: flex-end; border-top: 1px solid var(--color-hairline, #c8cfda); padding-top: 14px;">
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            style="width: 100%; height: 40px; font-size: 14px; font-weight: 600;"
            @click="closeModal"
            data-testid="btn-close-receipt"
          >
            Entendido, cerrar resguardo
          </button>
        </div>
      </div>

      <!-- =================================================================== -->
      <!-- CASE 1: MACHINE WITH ACTIVE INCIDENT (RF-02 DUPLICATE BLOCKED)       -->
      <!-- =================================================================== -->
      <div v-else-if="hasActiveIncident" class="vg-duplicate-incident-container">
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

        <!-- 1.b Confirmación obligatoria de acceso (RF-04.6, Art. V.2) -->
        <div
          v-if="requiresAccessConfirmation"
          style="background-color: var(--color-urgency-critical-bg); border: 1px solid var(--color-urgency-critical); border-radius: var(--radius-interactive, 4px); padding: 12px; margin-bottom: 16px;"
          data-testid="access-confirmation-block"
        >
          <div style="font-size: 13px; font-weight: 700; color: var(--color-error-text); margin-bottom: 6px;">
            ⚠️ Máquina fuera de servicio por falta de acceso
          </div>
          <div style="font-size: 12px; color: var(--color-slate); line-height: 1.4; margin-bottom: 10px;">
            El aviso anterior se canceló tras 72 horas hábiles sin poder acceder a la máquina.
            Para abrir uno nuevo es obligatorio confirmar formalmente el acceso (Art. V.2).
          </div>
          <label
            style="display: flex; align-items: center; gap: 10px; min-height: 44px; padding: 8px; background-color: var(--color-surface-card); border: 1px solid var(--color-urgency-critical); border-radius: 4px; cursor: pointer;"
          >
            <input
              type="checkbox"
              v-model="accessConfirmed"
              :disabled="isSubmitting"
              style="width: 22px; height: 22px; accent-color: var(--color-error-text);"
              data-testid="access-confirmation-checkbox"
            />
            <span style="font-size: 13px; color: var(--color-slate); line-height: 1.35;">
              <strong>Confirmo formalmente que las instalaciones y la máquina se encuentran
              abiertas y accesibles para el servicio técnico.</strong>
            </span>
          </label>
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

        <!-- 3. Solicitud de Reintegro Formal (HU-02 / RF-REF-01) o Retained Money Informativo -->
        <div style="margin-bottom: 16px;">
          <QrRefundRequestBlock
            :model-value="refundClaim"
            :has-physical-reception="hasPhysicalReception"
            :disabled="isSubmitting"
            @update:model-value="handleRefundClaimUpdate"
            @validity-change="handleRefundClaimValidity"
          />
          <div v-if="!refundRequested" style="margin-top: 8px;">
            <label for="incident-retained-money" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 4px;">
              ¿Se tragó dinero la máquina sin tramitar reintegro? <span style="font-size: 11px; color: var(--color-ink-muted, #6c7e9d);">(opcional informativo)</span>
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
            :disabled="isSubmitting || !description.trim() || !reporterName.trim() || !reporterPhone.trim() || (requiresAccessConfirmation && !accessConfirmed)"
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
