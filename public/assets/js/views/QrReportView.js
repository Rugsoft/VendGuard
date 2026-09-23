/**
 * VendGuard - QrReportView (QrReportView.js)
 * 
 * Vista móvil pública para el escaneo de código QR y reporte directo de averías (RF-03, RF-04, RF-05).
 * 
 * Características principales:
 * 1. Acceso Contextual Efímero: Sin autenticación previa de sede (EARS 3.1).
 * 2. Máquina Bloqueada: Selección fijada en chip de sólo lectura (EARS 3.2).
 * 3. Mandato Sanitario Art. II: Banner de advertencia en alimentos perecederos (EARS 3.3).
 * 4. Confirmación Post-Envío: Pantalla con código de ticket sin saltar al portal privado (EARS 3.4).
 * 5. Blindaje de Privacidad Art. V.4: Modo ACTIVE_INCIDENT sin exponer datos de técnicos (EARS 4.1).
 * 6. Aportación de Comentarios Adicionales: Sobre averías activas preexistentes (EARS 4.2).
 * 7. Control de Garantía de 48h: Modo UNDER_WARRANTY con opción de reapertura (EARS 4.3).
 * 8. Manejo Amigable de Concurrencia: Unificación automática ante envíos casi simultáneos (EARS 4.5).
 * 9. Mensaje de Cortesía: Ante máquina inactiva o no encontrada (EARS 5.2).
 * 
 * Respeta el Dogma Vanilla y el Sistema de Diseño Docker (design-tokens.css).
 */

import { api } from '../api.js';
import { QrInactiveMachineNotice } from '../components/QrInactiveMachineNotice.js';

export const INCIDENT_CATEGORIES = [
  {
    value: 'TEMPERATURE_COLD',
    label: 'Temperatura / Pérdida de frío',
    description: 'Fallo en motor frigorífico o máquina marcando temperatura alta.',
    icon: '❄️'
  },
  {
    value: 'PAYMENT_SYSTEM',
    label: 'Fallo en medios de pago',
    description: 'No acepta monedas, tarjeta bancaria o billetero atascado.',
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
    value: 'OTHER',
    label: 'Otro motivo',
    description: 'Cualquier otra anomalía no clasificada anteriormente.',
    icon: '📝'
  }
];

export const QrReportView = {
  name: 'QrReportView',
  components: {
    QrInactiveMachineNotice
  },
  props: {
    code: {
      type: String,
      default: ''
    },
    site: {
      type: String,
      default: ''
    }
  },
  emits: ['close', 'go-home'],
  data() {
    return {
      // Estado de carga y resolución
      loading: true,
      loadError: '',
      statusMode: 'CAN_REPORT', // 'CAN_REPORT' | 'ACTIVE_INCIDENT' | 'UNDER_WARRANTY' | 'NOT_FOUND' | 'INACTIVE'
      inactiveNoticeMessage: '',

      // Datos resueltos de la máquina y sede
      machine: null,
      location: null,
      activeIncident: null,
      resolvedIncident: null,

      // Formulario de nuevo reporte (CAN_REPORT)
      category: 'TEMPERATURE_COLD',
      description: '',
      retainedMoney: '',
      reporterName: '',
      reporterPhone: '',
      photoFile: null,
      photoPreviewUrl: null,

      // Formulario de comentario adicional (ACTIVE_INCIDENT / UNDER_WARRANTY)
      showCommentForm: false,
      commentText: '',
      commentReporterName: '',
      commentReporterPhone: '',
      commentSubmitted: false,

      // Estados de envío y confirmación
      isSubmitting: false,
      submitError: '',
      submitted: false,
      submittedTicket: null
    };
  },
  computed: {
    effectiveMachineCode() {
      if (this.code) {
        return this.code.trim().toUpperCase();
      }
      if (typeof window !== 'undefined' && window.location) {
        const urlParams = new URLSearchParams(window.location.search);
        return (urlParams.get('qr') || urlParams.get('code') || '').trim().toUpperCase();
      }
      return '';
    },
    effectiveSiteCode() {
      if (this.site) {
        return this.site.trim().toUpperCase();
      }
      if (typeof window !== 'undefined' && window.location) {
        const urlParams = new URLSearchParams(window.location.search);
        return (urlParams.get('site') || urlParams.get('site_code') || '').trim().toUpperCase();
      }
      return '';
    },
    isPerishable() {
      return this.machine?.is_perishable === true || this.machine?.machine_type === 'PERISHABLE_FOOD';
    },
    categories() {
      return INCIDENT_CATEGORIES;
    },
    isDescriptionValid() {
      return this.description.trim().length >= 5;
    },
    formattedTicketCode() {
      if (!this.submittedTicket?.ticket_code) return '';
      const code = this.submittedTicket.ticket_code;
      return code.startsWith('#') ? code : `#${code}`;
    }
  },
  mounted() {
    this.resolveMachine();
  },
  methods: {
    /**
     * Resuelve contextualmente el estado de la máquina tras escanear el QR (RF-03, RF-05)
     */
    async resolveMachine() {
      const code = this.effectiveMachineCode;
      if (!code) {
        this.statusMode = 'NOT_FOUND';
        this.loadError = 'No se ha proporcionado un código de máquina válido en el enlace QR.';
        this.loading = false;
        return;
      }

      this.loading = true;
      this.loadError = '';

      try {
        const res = await api.qr.scan(code, this.effectiveSiteCode || null);
        const data = res.data || res;

        // Máquina inactiva o retirada del parque (RF-04, EARS 2.12)
        if (data.status_mode === 'INACTIVE' || data.status === 'INACTIVE' || data.is_active === false || data.allow_reporting === false) {
          this.statusMode = 'INACTIVE';
          this.machine = {
            code: data.code || (data.machine ? data.machine.code : code),
            model: data.model || (data.machine ? data.machine.model : ''),
            machine_type_label: data.machine_type_label || (data.machine ? data.machine.machine_type_label : ''),
            floor_wing: data.floor_wing || (data.machine ? data.machine.floor_wing : ''),
            is_active: false
          };
          this.location = data.location || {
            name: data.location_name || '',
            contact_phone: data.support_phone || data.contact_phone || ''
          };
          this.inactiveNoticeMessage = data.message || 'Esta máquina de vending se encuentra temporalmente retirada o fuera de servicio. No es posible registrar nuevas incidencias sobre este dispositivo.';
          return;
        }

        this.machine = data.machine || null;
        this.location = data.location || null;
        this.statusMode = data.status_mode || 'CAN_REPORT';
        this.activeIncident = data.active_incident || null;
        this.resolvedIncident = data.resolved_incident || null;

        // Seleccionar automáticamente TEMPERATURE_COLD en perecederos como sugerencia prioritaria
        if (this.isPerishable) {
          this.category = 'TEMPERATURE_COLD';
        } else if (this.category === 'TEMPERATURE_COLD') {
          this.category = 'PRODUCT_JAM';
        }
      } catch (err) {
        this.statusMode = 'NOT_FOUND';
        this.loadError = err.message || 'Máquina no identificada o temporalmente fuera de servicio. Si necesitas asistencia, contacta con el servicio técnico.';
      } finally {
        this.loading = false;
      }
    },

    /**
     * Gestiona la selección de fotografía de evidencia fotográfica
     */
    handlePhotoChange(event) {
      const file = event?.target?.files?.[0];
      if (!file) {
        this.photoFile = null;
        this.photoPreviewUrl = null;
        return;
      }

      // Validación de tamaño (máx 5 MB)
      if (file.size > 5 * 1024 * 1024) {
        alert('La fotografía no debe superar los 5 MB de tamaño.');
        if (event.target) event.target.value = '';
        return;
      }

      this.photoFile = file;
      if (typeof URL !== 'undefined' && typeof URL.createObjectURL === 'function') {
        this.photoPreviewUrl = URL.createObjectURL(file);
      }
    },

    /**
     * Envía el formulario público de reporte de avería (RF-03, RF-04)
     */
    async submitReport() {
      if (!this.isDescriptionValid || this.isSubmitting) {
        return;
      }

      this.isSubmitting = true;
      this.submitError = '';

      try {
        let payload;
        // Si hay archivo fotográfico, enviar como FormData (multipart/form-data)
        if (this.photoFile) {
          payload = new FormData();
          payload.append('machine_code', this.machine.code);
          payload.append('category', this.category);
          payload.append('description', this.description.trim());
          if (this.reporterName) payload.append('reporter_name', this.reporterName.trim());
          if (this.reporterPhone) payload.append('reporter_phone', this.reporterPhone.trim());
          if (this.retainedMoney) payload.append('retained_money_amount', String(this.retainedMoney));
          payload.append('photo', this.photoFile);
        } else {
          payload = {
            machine_code: this.machine.code,
            category: this.category,
            description: this.description.trim(),
            reporter_name: this.reporterName.trim() || null,
            reporter_phone: this.reporterPhone.trim() || null,
            retained_money_amount: this.retainedMoney ? Number(this.retainedMoney) : null
          };
        }

        const res = await api.qr.report(payload);
        const data = res.data || res;

        this.submittedTicket = {
          ticket_code: data.ticket_code || '#TICK-REGISTRADO',
          status: data.status || 'REGISTERED',
          urgency: data.urgency || '',
          merged: Boolean(data.merged),
          message: data.message || res.message || 'Incidencia registrada con éxito.'
        };
        this.submitted = true;
      } catch (err) {
        this.submitError = err.message || 'Error al enviar el reporte. Por favor, inténtalo de nuevo.';
      } finally {
        this.isSubmitting = false;
      }
    },

    /**
     * Añade un comentario o evidencia adicional a una avería activa preexistente (EARS 4.2)
     */
    async submitAdditionalComment() {
      if (!this.commentText.trim() || this.isSubmitting) {
        return;
      }

      this.isSubmitting = true;
      this.submitError = '';

      try {
        const payload = {
          machine_code: this.machine.code,
          category: this.activeIncident?.category || 'OTHER',
          description: this.commentText.trim(),
          reporter_name: this.commentReporterName.trim() || null,
          reporter_phone: this.commentReporterPhone.trim() || null
        };

        const res = await api.qr.report(payload);
        const data = res.data || res;

        this.commentSubmitted = true;
        this.submittedTicket = {
          ticket_code: data.ticket_code || this.activeIncident?.ticket_code || '',
          merged: true,
          message: data.message || 'Tus observaciones han sido añadidas al ticket en curso.'
        };
      } catch (err) {
        this.submitError = err.message || 'Error al registrar tus observaciones.';
      } finally {
        this.isSubmitting = false;
      }
    },

    /**
     * Vuelve al inicio o portal principal
     */
    goHome() {
      this.$emit('go-home');
      if (typeof window !== 'undefined' && window.location) {
        window.location.href = '/';
      }
    }
  },
  template: `
    <div class="qr-report-view">
      <!-- 1. Cabecera Institucional Móvil -->
      <header class="qr-header">
        <div class="qr-logo-container">
          <span class="qr-brand-icon">🛡️</span>
          <span class="qr-brand-name">VendGuard</span>
          <span class="qr-badge-public">Asistencia Técnica</span>
        </div>
      </header>

      <main class="qr-content-wrapper">
        <!-- 2. Estado de Carga -->
        <div v-if="loading" class="qr-card qr-card-center" data-testid="loading-state">
          <div class="qr-spinner"></div>
          <p class="qr-loading-text">Identificando máquina y verificando estado del servicio...</p>
        </div>

        <!-- 3. Pantalla de Confirmación Post-Envío (EARS 3.4 / EARS 4.5) -->
        <div v-else-if="submitted" class="qr-card qr-confirmation-card" data-testid="confirmation-card">
          <div class="qr-confirmation-icon">
            <span v-if="submittedTicket?.merged">ℹ️</span>
            <span v-else>✅</span>
          </div>
          
          <h2 class="qr-title-display">
            {{ submittedTicket?.merged ? 'Observación Unificada' : '¡Aviso Registrado con Éxito!' }}
          </h2>
          
          <div class="qr-ticket-badge" data-testid="ticket-code-badge">
            <span class="qr-ticket-label">Código de Ticket:</span>
            <strong class="qr-ticket-number">{{ formattedTicketCode }}</strong>
          </div>

          <div class="qr-confirmation-summary">
            <div class="qr-summary-row">
              <span class="qr-summary-label">Máquina:</span>
              <span class="qr-summary-value">{{ machine?.code }} ({{ machine?.model }})</span>
            </div>
            <div class="qr-summary-row">
              <span class="qr-summary-label">Ubicación:</span>
              <span class="qr-summary-value">{{ location?.name }} - {{ machine?.floor_wing }}</span>
            </div>
          </div>

          <p class="qr-confirmation-message" data-testid="confirmation-message">
            {{ submittedTicket?.message || 'Nuestro equipo técnico ha recibido tu aviso y se ocupará de resolver la avería lo antes posible.' }}
          </p>

          <div class="qr-thank-you-box">
            <p>🙏 Gracias por tu colaboración para mantener el servicio en perfecto estado.</p>
          </div>

          <button type="button" class="btn btn-primary qr-btn-block" @click="goHome">
            Cerrar ventana
          </button>
        </div>

        <!-- 4. Máquina Inactiva / Retirada del Parque (RF-04, EARS 2.12) -->
        <QrInactiveMachineNotice
          v-else-if="statusMode === 'INACTIVE'"
          :machine="machine"
          :location="location"
          :message="inactiveNoticeMessage"
          @go-home="goHome"
        />

        <!-- 5. Error / Máquina No Encontrada (EARS 5.2) -->
        <div v-else-if="statusMode === 'NOT_FOUND'" class="qr-card qr-error-card" data-testid="not-found-card">
          <div class="qr-error-icon">⚠️</div>
          <h2 class="qr-card-title">Máquina No Identificada</h2>
          <p class="qr-error-desc" data-testid="not-found-message">
            {{ loadError || 'Máquina no identificada o temporalmente fuera de servicio. Si necesitas asistencia, contacta con el servicio técnico.' }}
          </p>
          <div class="qr-support-box" v-if="location?.contact_phone">
            <span>Teléfono de asistencia:</span>
            <a :href="'tel:' + location.contact_phone" class="qr-support-phone">📞 {{ location.contact_phone }}</a>
          </div>
          <button type="button" class="btn btn-secondary qr-btn-block" @click="goHome">
            Ir a la portada del servicio
          </button>
        </div>

        <!-- 5. Flujos Operativos Normales (Máquina Identificada) -->
        <div v-else class="qr-flow-container">
          <!-- A) Selector de Máquina Bloqueado (EARS 3.1, 3.2) -->
          <div class="qr-machine-chip-card" data-testid="machine-locked-chip">
            <div class="qr-chip-header">
              <span class="qr-chip-label">Máquina identificada por QR:</span>
              <span class="qr-chip-lock">🔒 Bloqueada en selección</span>
            </div>
            <div class="qr-chip-body">
              <div class="qr-chip-main">
                <span class="qr-machine-code">[{{ machine?.code }}]</span>
                <span class="qr-machine-model">{{ machine?.model }}</span>
              </div>
              <div class="qr-chip-sub">
                📍 {{ location?.name }} ({{ machine?.floor_wing }})
              </div>
            </div>
          </div>

          <!-- B) Banner Sanitario de Alimentos Perecederos (EARS 3.3 / Art. II Constitución) -->
          <div v-if="isPerishable" class="qr-health-alert-banner" data-testid="perishable-health-banner">
            <div class="qr-health-icon">⚠️</div>
            <div class="qr-health-text">
              <strong>Máquina de alimentos frescos:</strong>
              <p>Si los productos están templados o la máquina no enfría, notifícalo como rotura de frío para intervención crítica prioritaria.</p>
            </div>
          </div>

          <!-- C) Caso 1: Máquina con Avería Activa Abierta (EARS 4.1, 4.2 / Art. V.4) -->
          <div v-if="statusMode === 'ACTIVE_INCIDENT'" class="qr-card qr-active-incident-card" data-testid="active-incident-panel">
            <div class="qr-status-indicator-badge">
              <span class="qr-indicator-pulse"></span>
              Avería en curso de atención
            </div>

            <h3 class="qr-active-title">Esta máquina ya tiene un aviso abierto</h3>
            <p class="qr-active-subtitle">
              Nuestro equipo técnico ya está al corriente de la avería. Los técnicos tienen asignado este equipo para su reparación.
            </p>

            <div class="qr-active-details">
              <div class="qr-detail-item">
                <span class="qr-detail-k">Ticket:</span>
                <span class="qr-detail-v font-mono">#{{ activeIncident?.ticket_code }}</span>
              </div>
              <div class="qr-detail-item">
                <span class="qr-detail-k">Estado:</span>
                <span class="qr-detail-v">{{ activeIncident?.status_label || activeIncident?.public_status || 'En trámite' }}</span>
              </div>
              <div class="qr-detail-item" v-if="activeIncident?.reported_at">
                <span class="qr-detail-k">Reportado:</span>
                <span class="qr-detail-v">{{ activeIncident.reported_at }}</span>
              </div>
            </div>

            <!-- Formulario Desplegable para Añadir Observación Adicional (EARS 4.2) -->
            <div class="qr-additional-comment-section">
              <div v-if="!showCommentForm && !commentSubmitted">
                <button type="button" class="btn btn-secondary qr-btn-block" @click="showCommentForm = true" data-testid="btn-add-comment">
                  💬 Aportar comentario adicional o dinero retenido
                </button>
              </div>

              <div v-else-if="commentSubmitted" class="qr-comment-success-notice">
                <p>✅ Tus observaciones adicionales han sido enviadas al ticket #{{ activeIncident?.ticket_code }}.</p>
              </div>

              <form v-else @submit.prevent="submitAdditionalComment" class="qr-comment-form" data-testid="additional-comment-form">
                <h4 class="qr-form-subtitle">Añadir detalles sobre la avería:</h4>
                <div class="form-group">
                  <textarea
                    v-model="commentText"
                    class="form-control"
                    rows="3"
                    placeholder="Describe lo ocurrido (ej: dinero retenido, ruidos extraños, intento de compra fallido)..."
                    required
                  ></textarea>
                </div>
                <div class="form-group-grid">
                  <div class="form-group">
                    <input
                      v-model="commentReporterName"
                      type="text"
                      class="form-control"
                      placeholder="Tu nombre (opcional)"
                    />
                  </div>
                  <div class="form-group">
                    <input
                      v-model="commentReporterPhone"
                      type="tel"
                      class="form-control"
                      placeholder="Teléfono móvil (opcional)"
                    />
                  </div>
                </div>

                <div v-if="submitError" class="alert-error">{{ submitError }}</div>

                <div class="qr-form-actions">
                  <button type="button" class="btn btn-ghost" @click="showCommentForm = false">Cancelar</button>
                  <button type="submit" class="btn btn-primary" :disabled="!commentText.trim() || isSubmitting">
                    {{ isSubmitting ? 'Enviando...' : 'Añadir al ticket' }}
                  </button>
                </div>
              </form>
            </div>
          </div>

          <!-- D) Caso 2: Máquina en Garantía de 48h (EARS 4.3) -->
          <div v-else-if="statusMode === 'UNDER_WARRANTY'" class="qr-card qr-warranty-card" data-testid="warranty-panel">
            <div class="qr-warranty-badge">🛡️ Garantía de Reparación</div>
            <h3 class="qr-warranty-title">Máquina reparada recientemente</h3>
            <p class="qr-warranty-desc">
              Esta máquina fue reparada el {{ resolvedIncident?.resolved_at }}. Si la avería ha vuelto a reproducirse, puedes solicitar la reapertura inmediata.
            </p>
            <button type="button" class="btn btn-warning qr-btn-block" @click="statusMode = 'CAN_REPORT'">
              🔄 El problema continúa / Solicitar reapertura
            </button>
          </div>

          <!-- E) Caso 3: Máquina Limpia - Formulario de Reporte Completo (EARS 3.1–3.4) -->
          <div v-else-if="statusMode === 'CAN_REPORT'" class="qr-card qr-report-card" data-testid="report-form-card">
            <h2 class="qr-card-title">Notificar Avería o Incidencia</h2>
            <p class="qr-card-subtitle">
              Indica qué problema presenta la máquina para enviar al técnico más cercano.
            </p>

            <form @submit.prevent="submitReport" class="qr-form">
              <!-- Categoría -->
              <div class="form-group">
                <label class="form-label" for="category-select">Tipo de Problema <span class="required">*</span></label>
                <select id="category-select" v-model="category" class="form-control" required data-testid="category-select">
                  <option v-for="cat in categories" :key="cat.value" :value="cat.value">
                    {{ cat.icon }} {{ cat.label }}
                  </option>
                </select>
              </div>

              <!-- Descripción -->
              <div class="form-group">
                <label class="form-label" for="description-input">
                  Descripción de lo ocurrido <span class="required">*</span>
                </label>
                <textarea
                  id="description-input"
                  v-model="description"
                  class="form-control"
                  rows="3"
                  placeholder="Explica brevemente qué ha fallado (ej: no cae el producto, no devuelve cambio, pantalla congelada)..."
                  required
                  minlength="5"
                  data-testid="description-input"
                ></textarea>
                <span class="form-hint" :class="{ 'text-error': description.length > 0 && description.length < 5 }">
                  Mínimo 5 caracteres ({{ description.length }} escritos)
                </span>
              </div>

              <!-- Dinero Retenido (opcional) -->
              <div class="form-group">
                <label class="form-label" for="money-input">
                  Dinero retenido / Importe no devuelto (€) <span class="text-muted">(opcional)</span>
                </label>
                <input
                  id="money-input"
                  v-model="retainedMoney"
                  type="number"
                  step="0.05"
                  min="0"
                  class="form-control"
                  placeholder="0.00"
                  data-testid="retained-money-input"
                />
              </div>

              <!-- Datos de Contacto Opcionales -->
              <div class="form-group-grid">
                <div class="form-group">
                  <label class="form-label" for="reporter-name">Tu nombre <span class="text-muted">(opcional)</span></label>
                  <input
                    id="reporter-name"
                    v-model="reporterName"
                    type="text"
                    class="form-control"
                    placeholder="Para informarte de la resolución"
                    data-testid="reporter-name-input"
                  />
                </div>
                <div class="form-group">
                  <label class="form-label" for="reporter-phone">Teléfono móvil <span class="text-muted">(opcional)</span></label>
                  <input
                    id="reporter-phone"
                    v-model="reporterPhone"
                    type="tel"
                    class="form-control"
                    placeholder="600 000 000"
                    data-testid="reporter-phone-input"
                  />
                </div>
              </div>

              <!-- Adjuntar Fotografía -->
              <div class="form-group">
                <label class="form-label">Adjuntar foto del fallo <span class="text-muted">(opcional, máx 5MB)</span></label>
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  @change="handlePhotoChange"
                  class="form-control-file"
                  data-testid="photo-input"
                />
                <div v-if="photoPreviewUrl" class="qr-photo-preview-wrap">
                  <img :src="photoPreviewUrl" alt="Vista previa" class="qr-photo-preview" />
                </div>
              </div>

              <!-- Error de Envío -->
              <div v-if="submitError" class="alert-error" data-testid="submit-error">
                {{ submitError }}
              </div>

              <!-- Botón de Envío -->
              <button
                type="submit"
                class="btn btn-primary qr-btn-block qr-btn-submit"
                :disabled="!isDescriptionValid || isSubmitting"
                data-testid="submit-report-btn"
              >
                <span v-if="isSubmitting" class="qr-btn-spinner"></span>
                <span v-else>🚀 Enviar Reporte de Avería</span>
              </button>
            </form>
          </div>
        </div>
      </main>

      <footer class="qr-footer">
        <p>VendGuard © 2026 • Soporte Técnico de Vending Inteligente</p>
      </footer>
    </div>
  `
};

export default QrReportView;
