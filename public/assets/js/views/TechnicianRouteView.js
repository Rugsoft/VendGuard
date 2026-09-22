/**
 * VendGuard - TechnicianRouteView (TechnicianRouteView.js)
 * 
 * Vertical Smartphone View for Field Route Technicians (RF-07, RF-08, RNF-01, T-38).
 * 
 * Features:
 * 1. Mobile-first vertical smartphone layout optimized for one-hand operation (RNF-01).
 * 2. Assigned route task list with clear urgency indicators and machine/location details (RF-07).
 * 3. Intervention controls: "Iniciar intervención" (starts / resumes work in EN_CURSO).
 * 4. Suspension modal: "Pausar por repuesto" requiring missing spare part description (EARS 7.2).
 * 5. Resolution modal: "Resolver Avería" enforcing strictly >= 20 chars for diagnosis AND action (RF-08 / EARS 8.1, 8.2).
 * 6. Quick telephone link (tel:) for immediate on-site coordinator or concierge contact.
 * 7. Mobile authentication screen for field technicians (RF-04).
 */

import { api } from '../api.js';
import { store } from '../store.js';
import { IncidentBadge } from '../components/IncidentBadge.js';
import { ModalDialog } from '../components/ModalDialog.js';

export const TechnicianRouteView = {
  name: 'TechnicianRouteView',
  components: {
    IncidentBadge,
    ModalDialog
  },
  data() {
    return {
      incidents: [],
      isLoading: false,
      errorMessage: '',
      feedbackMessage: '',
      filterStatus: 'ALL', // 'ALL', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS'

      // Authentication form (RF-04)
      loginEmail: '',
      loginPassword: '',
      isLoggingIn: false,
      loginError: '',

      // Action loading states
      actionInProgressId: null,

      // Modal 1: Pause intervention for missing spare parts (RF-07 / EARS 7.2)
      showPauseModal: false,
      selectedIncident: null,
      pauseReason: '',
      isPausing: false,
      pauseError: '',

      // Modal 2: Strict closure / resolution documentation (RF-08 / EARS 8.1, 8.2)
      showResolveModal: false,
      resolveDiagnosis: '',
      resolveAction: '',
      isResolving: false,
      resolveError: ''
    };
  },
  computed: {
    /**
     * Checks if current authenticated user has the TECHNICIAN role (RF-04).
     * @returns {boolean}
     */
    isAuthenticated() {
      const user = store.state.user;
      return Boolean(user && user.role === 'TECHNICIAN' && store.state.token);
    },

    /**
     * Returns current user information.
     */
    currentUser() {
      return store.state.user || null;
    },

    /**
     * Metrics summary for route header chips.
     */
    routeMetrics() {
      const total = this.incidents.length;
      let assigned = 0;
      let inProgress = 0;
      let pendingParts = 0;
      let critical = 0;

      for (const inc of this.incidents) {
        if (inc.status === 'ASSIGNED') assigned++;
        if (inc.status === 'IN_PROGRESS') inProgress++;
        if (inc.status === 'PENDING_PARTS') pendingParts++;
        if (inc.urgency === 'CRITICAL') critical++;
      }

      return { total, assigned, inProgress, pendingParts, critical };
    },

    /**
     * Filtered and sorted route list.
     * Orders CRITICAL first, then by operational status (IN_PROGRESS first).
     */
    filteredIncidents() {
      let list = [...this.incidents];

      if (this.filterStatus !== 'ALL') {
        list = list.filter(inc => inc.status === this.filterStatus);
      }

      const urgencyOrder = { CRITICAL: 1, HIGH: 2, MEDIUM: 3, LOW: 4 };
      const statusOrder = { IN_PROGRESS: 1, ASSIGNED: 2, PENDING_PARTS: 3 };

      return list.sort((a, b) => {
        const uA = urgencyOrder[a.urgency] || 99;
        const uB = urgencyOrder[b.urgency] || 99;
        if (uA !== uB) return uA - uB;

        const sA = statusOrder[a.status] || 99;
        const sB = statusOrder[b.status] || 99;
        return sA - sB;
      });
    },

    // ─── Resolution Validation Helpers (RF-08 / EARS 8.1, 8.2) ───────────────
    diagnosisLength() {
      return this.resolveDiagnosis ? this.resolveDiagnosis.trim().length : 0;
    },
    actionLength() {
      return this.resolveAction ? this.resolveAction.trim().length : 0;
    },
    isDiagnosisValid() {
      return this.diagnosisLength >= 20;
    },
    isActionValid() {
      return this.actionLength >= 20;
    },
    canResolve() {
      return this.isDiagnosisValid && this.isActionValid;
    }
  },
  mounted() {
    if (this.isAuthenticated) {
      this.loadRoute();
    }
  },
  methods: {
    /**
     * Loads assigned route incidents from backend (RF-07).
     */
    async loadRoute() {
      this.isLoading = true;
      this.errorMessage = '';
      try {
        const response = await api.technician.getMyRoute();
        // Handler supports both raw array and envelope { success, data: [] }
        if (Array.isArray(response)) {
          this.incidents = response;
        } else if (response && Array.isArray(response.data)) {
          this.incidents = response.data;
        } else {
          this.incidents = [];
        }
      } catch (err) {
        this.errorMessage = err?.message || 'No se pudo sincronizar la ruta de averías. Comprueba tu conexión.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Technician login handler (RF-04).
     */
    async handleInternalLogin() {
      if (!this.loginEmail.trim() || !this.loginPassword.trim()) {
        this.loginError = 'Por favor, introduce correo electrónico y contraseña.';
        return;
      }

      this.loginError = '';
      this.isLoggingIn = true;

      try {
        const response = await api.auth.internalLogin(this.loginEmail.trim(), this.loginPassword);
        if (response?.user?.role !== 'TECHNICIAN') {
          store.clearSession();
          this.loginError = 'Acceso denegado: Esta vista es exclusiva para Técnicos de Campo.';
          return;
        }

        store.setInternalSession(response.user, response.token);
        this.loginEmail = '';
        this.loginPassword = '';
        await this.loadRoute();
      } catch (err) {
        this.loginError = err?.message || 'Credenciales incorrectas o usuario técnico inactivo.';
      } finally {
        this.isLoggingIn = false;
      }
    },

    /**
     * Logs out the technician.
     */
    handleLogout() {
      store.clearSession();
      this.incidents = [];
      this.feedbackMessage = '';
      this.errorMessage = '';
    },

    /**
     * Starts or resumes intervention on an incident (RF-07 / EARS 7.1, 7.3).
     * @param {Object} incident
     */
    async startIntervention(incident) {
      if (this.actionInProgressId) return;

      this.actionInProgressId = incident.id;
      this.feedbackMessage = '';
      this.errorMessage = '';

      try {
        const res = await api.technician.startIncident(incident.id);
        const startedAt = res?.data?.started_at || new Date().toISOString();

        // Update local object immediately for smooth responsive mobile UX
        incident.status = 'IN_PROGRESS';
        incident.started_at = startedAt;

        this.feedbackMessage = 'Intervención iniciada en máquina ' + (incident.machine?.code || incident.ticket_code) + '.';
        this.$emit('started', { incidentId: incident.id, startedAt });
      } catch (err) {
        this.errorMessage = err?.message || 'Error al iniciar la intervención. Revisa el estado de la máquina.';
      } finally {
        this.actionInProgressId = null;
      }
    },

    /**
     * Opens the Pause Modal for missing replacement parts (RF-07 / EARS 7.2).
     * @param {Object} incident
     */
    openPauseModal(incident) {
      this.selectedIncident = incident;
      this.pauseReason = incident.pending_parts_reason || '';
      this.pauseError = '';
      this.showPauseModal = true;
    },

    closePauseModal() {
      this.showPauseModal = false;
      this.selectedIncident = null;
      this.pauseReason = '';
      this.pauseError = '';
    },

    /**
     * Submits the pause reason to the API (RF-07 / EARS 7.2).
     */
    async submitPause() {
      const reason = this.pauseReason.trim();
      if (!reason) {
        this.pauseError = 'Debes describir la pieza o repuesto necesario para pausar la avería.';
        return;
      }

      this.isPausing = true;
      this.pauseError = '';

      try {
        await api.technician.pauseIncident(this.selectedIncident.id, reason);

        // Update local state
        this.selectedIncident.status = 'PENDING_PARTS';
        this.selectedIncident.pending_parts_reason = reason;

        this.feedbackMessage = 'Avería pausada en espera de repuesto: "' + reason + '".';
        this.$emit('paused', { incidentId: this.selectedIncident.id, reason });
        this.closePauseModal();
      } catch (err) {
        this.pauseError = err?.message || 'Error al pausar la intervención. Inténtalo de nuevo.';
      } finally {
        this.isPausing = false;
      }
    },

    /**
     * Opens the Resolve Modal with strict justification (RF-08 / EARS 8.1, 8.2).
     * @param {Object} incident
     */
    openResolveModal(incident) {
      this.selectedIncident = incident;
      this.resolveDiagnosis = '';
      this.resolveAction = '';
      this.resolveError = '';
      this.showResolveModal = true;
    },

    closeResolveModal() {
      this.showResolveModal = false;
      this.selectedIncident = null;
      this.resolveDiagnosis = '';
      this.resolveAction = '';
      this.resolveError = '';
    },

    /**
     * Submits technical resolution to API (RF-08 / EARS 8.1, 8.2, 8.3 / Art. V.1).
     */
    async submitResolve() {
      const diag = this.resolveDiagnosis.trim();
      const act = this.resolveAction.trim();

      // Client-side strict validation (EARS 8.2 / ResolutionValidator)
      if (diag.length < 20 || act.length < 20) {
        this.resolveError = 'Tanto el diagnóstico como la acción correctiva deben contener al menos 20 caracteres descriptivos cada uno.';
        return;
      }

      this.isResolving = true;
      this.resolveError = '';

      try {
        const res = await api.technician.resolveIncident(this.selectedIncident.id, diag, act);
        const resolvedAt = res?.data?.resolved_at || new Date().toISOString();

        this.feedbackMessage = '¡Avería resuelta con éxito! Se ha activado la ventana de garantía de 48 horas.';
        this.$emit('resolved', {
          incidentId: this.selectedIncident.id,
          diagnosis: diag,
          action: act,
          resolvedAt
        });

        const solvedId = this.selectedIncident.id;
        this.closeResolveModal();

        // Remove from active route
        this.incidents = this.incidents.filter(inc => inc.id !== solvedId);
      } catch (err) {
        this.resolveError = err?.message || 'No se pudo resolver la incidencia. Comprueba los requisitos de validación.';
      } finally {
        this.isResolving = false;
      }
    },

    /**
     * Formats an ISO datetime string for mobile display.
     * @param {string} isoString
     * @returns {string}
     */
    formatTime(isoString) {
      if (!isoString) return '';
      try {
        const d = new Date(isoString);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      } catch (e) {
        return isoString;
      }
    }
  },
  template: `
    <div class="vg-technician-route" style="max-width: 500px; margin: 0 auto; padding: 16px 12px; font-family: var(--font-body, Inter, sans-serif);">
      
      <!-- =================================================================== -->
      <!-- STATE A: FIELD TECHNICIAN MOBILE LOGIN (RF-04)                      -->
      <!-- =================================================================== -->
      <div
        v-if="!isAuthenticated"
        style="margin: 30px auto; background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 24px 18px; box-shadow: var(--shadow-card, 0 1px 3px rgba(0,0,0,0.04));"
      >
        <div style="text-align: center; margin-bottom: 20px;">
          <div
            style="width: 48px; height: 48px; border-radius: var(--radius-interactive, 4px); background-color: var(--color-primary, #2560ff); display: inline-flex; align-items: center; justify-content: center; color: #ffffff; font-size: 22px; font-weight: 700; margin-bottom: 10px; font-family: var(--font-display, 'DM Sans', sans-serif);"
          >
            🔧
          </div>
          <h2 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 20px; font-weight: 700; color: var(--color-ink, #000000); margin: 0;">
            Mi Ruta Técnica
          </h2>
          <p style="font-size: 13px; color: var(--color-ink-muted, #6c7e9d); margin-top: 6px; line-height: 1.4;">
            Acceso de campo para gestión y resolución in situ de máquinas de vending.
          </p>
        </div>

        <form @submit.prevent="handleInternalLogin">
          <div style="margin-bottom: 14px;">
            <label for="tech-email" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 6px;">
              Correo Técnico
            </label>
            <input
              id="tech-email"
              v-model="loginEmail"
              type="email"
              class="vg-input"
              placeholder="jordi.ruta@vendguard.internal"
              required
              :disabled="isLoggingIn"
              style="height: 42px; font-size: 14px;"
            />
          </div>

          <div style="margin-bottom: 16px;">
            <label for="tech-password" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 6px;">
              Contraseña
            </label>
            <input
              id="tech-password"
              v-model="loginPassword"
              type="password"
              class="vg-input"
              required
              :disabled="isLoggingIn"
              style="height: 42px; font-size: 14px;"
            />
          </div>

          <div
            v-if="loginError"
            style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 10px 12px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 16px;"
            role="alert"
          >
            {{ loginError }}
          </div>

          <button
            type="submit"
            class="vg-btn vg-btn-primary"
            style="width: 100%; height: 44px; font-size: 15px; font-weight: 600; border-radius: var(--radius-interactive, 4px);"
            :disabled="isLoggingIn"
          >
            <span v-if="!isLoggingIn">Acceder a Mi Ruta</span>
            <span v-else>Comprobando acceso...</span>
          </button>
        </form>
      </div>

      <!-- =================================================================== -->
      <!-- STATE B: AUTHENTICATED MOBILE ROUTE INTERFACE (RNF-01 / RF-07)       -->
      <!-- =================================================================== -->
      <div v-else>
        <!-- Mobile Top Header -->
        <div
          style="display: flex; align-items: center; justify-content: space-between; background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 12px 14px; margin-bottom: 14px; box-shadow: var(--shadow-card, 0 1px 3px rgba(0,0,0,0.04));"
        >
          <div style="display: flex; align-items: center; gap: 10px;">
            <div
              style="width: 38px; height: 38px; border-radius: 50%; background-color: #e5f2fc; color: var(--color-primary, #2560ff); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px;"
            >
              🧑‍🔧
            </div>
            <div>
              <div style="font-weight: 700; font-size: 14px; color: var(--color-slate, #2c333f); line-height: 1.2;">
                {{ currentUser?.name || 'Técnico de Ruta' }}
              </div>
              <div style="font-size: 11px; color: var(--color-ink-muted, #6c7e9d);">
                {{ routeMetrics.total }} avería(s) en tu ruta
              </div>
            </div>
          </div>

          <div style="display: flex; gap: 6px;">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              style="height: 36px; padding: 0 10px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;"
              title="Actualizar ruta"
              @click="loadRoute"
              :disabled="isLoading"
            >
              <span :style="{ display: 'inline-block', transform: isLoading ? 'rotate(180deg)' : 'none', transition: 'transform 0.3s' }">🔄</span>
            </button>
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              style="height: 36px; padding: 0 10px; font-size: 12px; color: #dc2626;"
              title="Cerrar sesión"
              @click="handleLogout"
            >
              Salir
            </button>
          </div>
        </div>

        <!-- Global Toast / Feedback Messages -->
        <div
          v-if="feedbackMessage"
          style="background-color: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; padding: 10px 14px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;"
          role="status"
        >
          <span>✓ {{ feedbackMessage }}</span>
          <button type="button" @click="feedbackMessage = ''" style="background: none; border: none; font-size: 14px; color: #065f46; cursor: pointer;">×</button>
        </div>

        <div
          v-if="errorMessage"
          style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 10px 14px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;"
          role="alert"
        >
          <span>⚠️ {{ errorMessage }}</span>
          <button type="button" @click="errorMessage = ''" style="background: none; border: none; font-size: 14px; color: #b91c1c; cursor: pointer;">×</button>
        </div>

        <!-- Mobile Filter Tabs (Touch Friendly) -->
        <div style="display: flex; gap: 6px; overflow-x: auto; padding-bottom: 8px; margin-bottom: 12px; scrollbar-width: none;">
          <button
            type="button"
            class="vg-btn"
            :class="filterStatus === 'ALL' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 32px; font-size: 12px; padding: 0 12px; white-space: nowrap; border-radius: 16px;"
            @click="filterStatus = 'ALL'"
          >
            Todas ({{ routeMetrics.total }})
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="filterStatus === 'IN_PROGRESS' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 32px; font-size: 12px; padding: 0 12px; white-space: nowrap; border-radius: 16px;"
            @click="filterStatus = 'IN_PROGRESS'"
          >
            En curso ({{ routeMetrics.inProgress }})
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="filterStatus === 'ASSIGNED' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 32px; font-size: 12px; padding: 0 12px; white-space: nowrap; border-radius: 16px;"
            @click="filterStatus = 'ASSIGNED'"
          >
            Por iniciar ({{ routeMetrics.assigned }})
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="filterStatus === 'PENDING_PARTS' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 32px; font-size: 12px; padding: 0 12px; white-space: nowrap; border-radius: 16px;"
            @click="filterStatus = 'PENDING_PARTS'"
          >
            Repuestos ({{ routeMetrics.pendingParts }})
          </button>
        </div>

        <!-- Loading State -->
        <div v-if="isLoading" style="text-align: center; padding: 30px; color: var(--color-ink-muted, #6c7e9d); font-size: 14px;">
          ⏳ Cargando ruta técnica...
        </div>

        <!-- Empty State -->
        <div
          v-else-if="filteredIncidents.length === 0"
          style="background-color: #ffffff; border: 1px dashed var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 36px 16px; text-align: center;"
        >
          <div style="font-size: 36px; margin-bottom: 8px;">🎉</div>
          <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 700; color: var(--color-slate, #2c333f); margin: 0 0 6px 0;">
            ¡Ruta al día!
          </h3>
          <p style="font-size: 13px; color: var(--color-ink-muted, #6c7e9d); margin: 0;">
            No tienes averías asignadas con este filtro. Pulsa refrescar para revisar nuevas asignaciones de coordinación.
          </p>
        </div>

        <!-- Incident Cards List (Optimized for Vertical Smartphone Viewport) -->
        <div v-else style="display: flex; flex-direction: column; gap: 12px;">
          <div
            v-for="incident in filteredIncidents"
            :key="incident.id"
            class="vg-route-card"
            :style="{
              backgroundColor: '#ffffff',
              border: '1px solid var(--color-hairline, #c8cfda)',
              borderLeft: incident.urgency === 'CRITICAL' ? '4px solid #dc2626' : (incident.urgency === 'HIGH' ? '4px solid #f97316' : '4px solid #c8cfda'),
              borderRadius: 'var(--radius-card, 8px)',
              padding: '14px',
              boxShadow: 'var(--shadow-card, 0 1px 3px rgba(0,0,0,0.04))'
            }"
          >
            <!-- Card Header: Ticket & Badges -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
              <span style="font-family: monospace; font-size: 13px; font-weight: 700; color: var(--color-slate, #2c333f);">
                #{{ incident.ticket_code }}
              </span>
              <div style="display: flex; gap: 6px; align-items: center;">
                <IncidentBadge type="urgency" :value="incident.urgency" />
                <IncidentBadge type="status" :value="incident.status" />
              </div>
            </div>

            <!-- Machine and Location Info -->
            <div style="margin-bottom: 10px;">
              <div style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 15px; font-weight: 700; color: var(--color-ink, #000000);">
                {{ incident.machine?.code || 'Máquina' }} · {{ incident.machine?.model || '' }}
              </div>
              <div style="font-size: 12px; color: var(--color-slate, #2c333f); margin-top: 2px;">
                📍 {{ incident.machine?.floor_wing || 'Planta no especificada' }}
              </div>
              <div style="font-size: 12px; color: var(--color-ink-muted, #6c7e9d); margin-top: 2px;">
                🏢 {{ incident.location?.name || 'Sede' }} ({{ incident.location?.address || '' }})
              </div>

              <!-- Quick Direct Telephone Link (One-Touch Call) -->
              <div v-if="incident.location?.contact_phone" style="margin-top: 6px;">
                <a
                  :href="'tel:' + incident.location.contact_phone"
                  class="vg-btn vg-btn-secondary"
                  style="display: inline-flex; align-items: center; gap: 6px; height: 30px; font-size: 12px; padding: 0 10px; text-decoration: none; color: var(--color-primary, #2560ff);"
                >
                  📞 Llamar conserjería: {{ incident.location.contact_phone }}
                </a>
              </div>
            </div>

            <!-- Description Box -->
            <div style="background-color: #f8fafc; border-radius: var(--radius-interactive, 4px); padding: 8px 10px; margin-bottom: 12px; font-size: 13px; color: var(--color-slate, #2c333f); line-height: 1.35;">
              <strong style="font-size: 11px; text-transform: uppercase; color: var(--color-ink-muted, #6c7e9d); display: block; margin-bottom: 2px;">
                Avería reportada:
              </strong>
              {{ incident.description }}
            </div>

            <!-- Status Context Highlights -->
            <!-- 1. Intervención en curso banner -->
            <div
              v-if="incident.status === 'IN_PROGRESS'"
              style="background-color: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; border-radius: var(--radius-interactive, 4px); padding: 6px 10px; font-size: 12px; font-weight: 500; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;"
            >
              <span style="font-size: 14px;">⏱️</span>
              <span>Intervención en curso desde las {{ formatTime(incident.started_at) }}</span>
            </div>

            <!-- 2. Pendiente de repuesto banner -->
            <div
              v-if="incident.status === 'PENDING_PARTS'"
              style="background-color: #fefce8; border: 1px solid #fef08a; color: #854d0e; border-radius: var(--radius-interactive, 4px); padding: 8px 10px; font-size: 12px; margin-bottom: 12px;"
            >
              <strong>⚠️ En espera de repuesto:</strong>
              <div style="margin-top: 2px; font-style: italic;">
                "{{ incident.pending_parts_reason }}"
              </div>
            </div>

            <!-- Action Buttons Bar (Large, Mobile Finger-Friendly Tap Targets >= 44px) -->
            <div style="border-top: 1px solid var(--color-hairline, #c8cfda); padding-top: 10px;">
              <!-- Action Option 1: Start Intervention (when ASSIGNED) -->
              <button
                v-if="incident.status === 'ASSIGNED'"
                type="button"
                class="vg-btn vg-btn-primary"
                style="width: 100%; height: 46px; font-size: 15px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px;"
                :disabled="actionInProgressId === incident.id"
                @click="startIntervention(incident)"
              >
                <span v-if="actionInProgressId !== incident.id">▶ Iniciar intervención</span>
                <span v-else>Iniciando...</span>
              </button>

              <!-- Action Option 2: In Progress Controls (when IN_PROGRESS) -->
              <div v-else-if="incident.status === 'IN_PROGRESS'" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary"
                  style="height: 46px; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 6px;"
                  @click="openPauseModal(incident)"
                >
                  ⏸ Pausar repuesto
                </button>
                <button
                  type="button"
                  class="vg-btn vg-btn-primary"
                  style="height: 46px; font-size: 14px; font-weight: 600; background-color: #059669; border-color: #047857; display: flex; align-items: center; justify-content: center; gap: 6px;"
                  @click="openResolveModal(incident)"
                >
                  ✓ Resolver avería
                </button>
              </div>

              <!-- Action Option 3: Resume from Missing Parts (when PENDING_PARTS) -->
              <button
                v-else-if="incident.status === 'PENDING_PARTS'"
                type="button"
                class="vg-btn vg-btn-primary"
                style="width: 100%; height: 46px; font-size: 15px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px;"
                :disabled="actionInProgressId === incident.id"
                @click="startIntervention(incident)"
              >
                <span v-if="actionInProgressId !== incident.id">▶ Reanudar intervención</span>
                <span v-else>Reanudando...</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- =================================================================== -->
      <!-- MODAL 1: PAUSE BY REPLACEMENT PART (RF-07 / EARS 7.2)               -->
      <!-- =================================================================== -->
      <ModalDialog
        v-model="showPauseModal"
        title="Pausar por Falta de Repuesto"
        :subtitle="selectedIncident ? ('Ticket #' + selectedIncident.ticket_code + ' · ' + (selectedIncident.machine?.code || '')) : ''"
        size="md"
        @close="closePauseModal"
      >
        <form v-if="selectedIncident" @submit.prevent="submitPause">
          <p style="font-size: 13px; color: var(--color-slate, #2c333f); margin-bottom: 12px; line-height: 1.4;">
            Conforme a la norma RF-07 (EARS 7.2), si debes suspender los trabajos por carecer de la pieza en tu furgoneta, describe el componente requerido para solicitarlo a almacén central.
          </p>

          <div style="margin-bottom: 14px;">
            <label for="pause-reason-text" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 6px;">
              Descripción del repuesto necesario <span style="color: #dc2626;">*</span>
            </label>
            <textarea
              id="pause-reason-text"
              v-model="pauseReason"
              class="vg-textarea"
              rows="3"
              placeholder="Ej: Electroválvula de entrada 24V (Ref. VENDO-EV24) quemada. Se requiere pieza nueva para continuar..."
              required
              :disabled="isPausing"
            ></textarea>
          </div>

          <!-- Error Alert -->
          <div
            v-if="pauseError"
            style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 10px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 14px;"
            role="alert"
          >
            {{ pauseError }}
          </div>

          <!-- Actions -->
          <div style="display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid var(--color-hairline, #c8cfda); padding-top: 14px;">
            <button type="button" class="vg-btn vg-btn-secondary" @click="closePauseModal" :disabled="isPausing">
              Volver a la ruta
            </button>
            <button
              type="submit"
              class="vg-btn vg-btn-primary"
              :disabled="isPausing || !pauseReason.trim()"
            >
              <span v-if="!isPausing">Confirmar Pausa</span>
              <span v-else>Guardando...</span>
            </button>
          </div>
        </form>
      </ModalDialog>

      <!-- =================================================================== -->
      <!-- MODAL 2: STRICT RESOLUTION MODAL (RF-08 / EARS 8.1, 8.2 / ART. V.1) -->
      <!-- =================================================================== -->
      <ModalDialog
        v-model="showResolveModal"
        title="Documentar Resolución Técnica"
        :subtitle="selectedIncident ? ('Ticket #' + selectedIncident.ticket_code + ' · ' + (selectedIncident.machine?.code || '')) : ''"
        size="md"
        @close="closeResolveModal"
      >
        <form v-if="selectedIncident" @submit.prevent="submitResolve">
          <!-- Legal / Constitutional Alert Note -->
          <div style="background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--radius-interactive, 4px); padding: 10px 12px; font-size: 12px; color: #1e3a8a; margin-bottom: 14px; line-height: 1.4;">
            ⚖️ <strong>Constitución (Art. V.1) y RF-08:</strong> El cierre de avería exige obligatoriamente un mínimo de <strong>20 caracteres descriptivos</strong> tanto en el diagnóstico como en la solución aplicada para garantizar la trazabilidad de taller.
          </div>

          <!-- Field 1: Diagnosis (>= 20 chars) -->
          <div style="margin-bottom: 14px;">
            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px;">
              <label for="resolve-diagnosis-text" style="font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f);">
                1. Diagnóstico real del fallo <span style="color: #dc2626;">*</span>
              </label>
              <span
                style="font-size: 11px; font-weight: 600;"
                :style="{ color: isDiagnosisValid ? '#059669' : (diagnosisLength > 0 ? '#dc2626' : '#6c7e9d') }"
              >
                {{ diagnosisLength }} / 20 mín.
                <span v-if="isDiagnosisValid">✓</span>
              </span>
            </div>
            <textarea
              id="resolve-diagnosis-text"
              v-model="resolveDiagnosis"
              class="vg-textarea"
              rows="3"
              placeholder="Ej: Bobina del relé térmico del compresor quemada por pico de tensión..."
              required
              :disabled="isResolving"
              :style="{ borderColor: diagnosisLength > 0 && !isDiagnosisValid ? '#ef4444' : '' }"
            ></textarea>
            <div v-if="diagnosisLength > 0 && !isDiagnosisValid" style="font-size: 11px; color: #dc2626; margin-top: 3px;">
              Faltan {{ 20 - diagnosisLength }} caracteres para alcanzar el mínimo de 20.
            </div>
          </div>

          <!-- Field 2: Corrective Action (>= 20 chars) -->
          <div style="margin-bottom: 14px;">
            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px;">
              <label for="resolve-action-text" style="font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f);">
                2. Acción técnica correctiva aplicada <span style="color: #dc2626;">*</span>
              </label>
              <span
                style="font-size: 11px; font-weight: 600;"
                :style="{ color: isActionValid ? '#059669' : (actionLength > 0 ? '#dc2626' : '#6c7e9d') }"
              >
                {{ actionLength }} / 20 mín.
                <span v-if="isActionValid">✓</span>
              </span>
            </div>
            <textarea
              id="resolve-action-text"
              v-model="resolveAction"
              class="vg-textarea"
              rows="3"
              placeholder="Ej: Sustituido relé térmico y comprobado ciclo de frío estabilizado a 4 grados..."
              required
              :disabled="isResolving"
              :style="{ borderColor: actionLength > 0 && !isActionValid ? '#ef4444' : '' }"
            ></textarea>
            <div v-if="actionLength > 0 && !isActionValid" style="font-size: 11px; color: #dc2626; margin-top: 3px;">
              Faltan {{ 20 - actionLength }} caracteres para alcanzar el mínimo de 20.
            </div>
          </div>

          <!-- Error Alert -->
          <div
            v-if="resolveError"
            style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 10px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 14px;"
            role="alert"
          >
            {{ resolveError }}
          </div>

          <!-- Actions -->
          <div style="display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid var(--color-hairline, #c8cfda); padding-top: 14px;">
            <button type="button" class="vg-btn vg-btn-secondary" @click="closeResolveModal" :disabled="isResolving">
              Cancelar
            </button>
            <button
              type="submit"
              class="vg-btn vg-btn-primary"
              style="background-color: #059669; border-color: #047857;"
              :disabled="isResolving || !canResolve"
            >
              <span v-if="!isResolving">Confirmar Resolución (48h Garantía)</span>
              <span v-else>Guardando resolución...</span>
            </button>
          </div>
        </form>
      </ModalDialog>
    </div>
  `
};

export default TechnicianRouteView;
