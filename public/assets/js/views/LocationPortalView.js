/**
 * VendGuard - LocationPortalView (LocationPortalView.js)
 * 
 * Portal for Location Responsible (RF-01, RF-02, RNF-02, T-34).
 * Allows the informant/concierge to:
 * 1. Log in swiftly using site code (e.g. 'SEDE-BCN-01') without passwords.
 * 2. View all machines installed in their building laid out in 8px card grid.
 * 3. Clearly differentiate between operational machines and those with active incidents.
 * 4. Filter by status (All, Active Incidents, Operational).
 * 5. Trigger report, comment or reopen workflows.
 * 6. Supervise sanitary certificates and hand over refund envelopes with the
 *    pickup PIN (RF-PREV-06, RF-PREV-07, RF-REF-06).
 */

import { api } from '../api.js';
import { store } from '../store.js';
import { MachineCard } from '../components/MachineCard.js';
import { IncidentCommentThreadModal } from '../components/IncidentCommentThreadModal.js';
import { IncidentReportModal } from '../components/IncidentReportModal.js';
import { ReopenTicketModal } from '../components/ReopenTicketModal.js';
import { SiteSanitaryStatusTab } from '../components/SiteSanitaryStatusTab.js';
import { SanitaryCertificateModal } from '../components/SanitaryCertificateModal.js';
import { SiteGlobalCertificateModal } from '../components/SiteGlobalCertificateModal.js';
import { LocationRefundsTab } from '../components/LocationRefundsTab.js';
import { isPendingInfoStatus, AMBER_TECHNICAL_TOKENS } from '../utils/IncidentStatusPermissions.js';
import { PAUSE_REASON_CATEGORIES } from '../components/PendingInfoPauseModal.js';

export const LocationPortalView = {
  name: 'LocationPortalView',
  components: {
    MachineCard,
    IncidentCommentThreadModal,
    IncidentReportModal,
    ReopenTicketModal,
    SiteSanitaryStatusTab,
    SanitaryCertificateModal,
    SiteGlobalCertificateModal,
    LocationRefundsTab
  },
  emits: ['report-incident', 'open-comments', 'reopen-incident'],
  data() {
    return {
      siteCodeInput: '',
      accessCodeInput: '',
      loginError: '',
      isLoggingIn: false,
      machines: [],
      isLoadingMachines: false,
      machinesError: '',
      activePortalTab: 'machines', // 'machines' | 'sanitary' | 'refunds'
      activeFilter: 'all', // 'all' | 'incident' | 'operational'
      selectedMachine: null,
      showReportModal: false,
      showCommentsModal: false,
      showReopenModal: false,
      showSanitaryCertModal: false,
      showGlobalCertModal: false,
      selectedCertMachineCode: ''
    };
  },
  computed: {
    isAuthenticated() {
      return store.isSiteSession && store.state.location !== null;
    },
    location() {
      return store.state.location;
    },
    filteredMachines() {
      if (this.activeFilter === 'incident') {
        return this.machines.filter(m => m.active_incident !== null);
      }
      if (this.activeFilter === 'operational') {
        return this.machines.filter(m => m.active_incident === null);
      }
      return this.machines;
    },
    totalCount() {
      return this.machines.length;
    },
    incidentCount() {
      return this.machines.filter(m => m.active_incident !== null).length;
    },
    operationalCount() {
      return this.machines.filter(m => m.active_incident === null).length;
    },
    /**
     * Máquinas de la sede con una avería en estado PENDING_INFO (RF-05.1).
     */
    pendingInfoMachines() {
      return this.machines.filter(m => m.active_incident && isPendingInfoStatus(m.active_incident.status));
    },
    /**
     * Primera máquina con avería en pausa de información para destacar en cabecera si existe.
     */
    firstPendingInfoMachine() {
      return this.pendingInfoMachines[0] || null;
    },
    /**
     * Etiqueta descriptiva de la causa de pausa de la primera máquina pausada.
     */
    firstPendingInfoReasonLabel() {
      const inc = this.firstPendingInfoMachine?.active_incident;
      if (!inc) return '';
      const direct = inc.pending_info_reason_category_label;
      if (direct && typeof direct === 'string' && direct.trim() !== '') {
        return direct;
      }
      const raw = inc.pending_info_reason_category;
      if (raw) {
        const found = PAUSE_REASON_CATEGORIES.find(c => c.value === raw);
        if (found) return found.label;
      }
      return 'Información pendiente de la sede';
    },
    amberTokens() {
      return AMBER_TECHNICAL_TOKENS;
    },
    /**
     * Avería activa cuya tarjeta abrió el hilo de conversación (Módulo 10).
     */
    activeCommentIncident() {
      return this.selectedMachine?.active_incident || null;
    },
    /**
     * Identificador numérico del expediente abierto en el hilo de conversación.
     */
    activeCommentIncidentId() {
      return this.activeCommentIncident?.id ?? null;
    },
    /**
     * Código de ticket visible del expediente abierto en el hilo.
     */
    activeCommentTicketCode() {
      return this.activeCommentIncident?.ticket_code ?? null;
    }
  },
  mounted() {
    if (this.isAuthenticated && this.location?.site_code) {
      this.loadMachines();
    }
  },
  methods: {
    /**
     * Authenticates the location responsible with the two site credentials (RF-01):
     * the site code identifies the centre and the access code — handed over in person
     * by coordination — is the credential that opens the portal.
     */
    async handleSiteLogin() {
      const code = this.siteCodeInput.trim().toUpperCase();
      if (!code) {
        this.loginError = 'Por favor, introduzca un código de sede válido (ej: SEDE-BCN-01).';
        return;
      }

      const accessCode = this.accessCodeInput.trim();
      if (!accessCode) {
        this.loginError = 'Introduzca la clave de centro entregada por el servicio técnico.';
        return;
      }

      this.loginError = '';
      this.isLoggingIn = true;
      store.setLoading(true);

      try {
        const response = await api.auth.siteLogin(code, accessCode);
        store.setSiteSession(response.location, response.token);
        this.siteCodeInput = '';
        this.accessCodeInput = '';
        await this.loadMachines();
      } catch (err) {
        this.loginError = err.message || 'Código o clave no reconocidos. Contacte con el servicio técnico.';
      } finally {
        this.isLoggingIn = false;
        store.setLoading(false);
      }
    },

    /**
     * Loads machines for the current authenticated location
     */
    async loadMachines() {
      if (!this.location?.site_code) return;

      this.isLoadingMachines = true;
      this.machinesError = '';
      store.setLoading(true);

      try {
        const data = await api.locations.getMachines(this.location.site_code);
        this.machines = Array.isArray(data) ? data : [];
      } catch (err) {
        this.machinesError = err.message || 'Error al cargar las máquinas de la sede.';
      } finally {
        this.isLoadingMachines = false;
        store.setLoading(false);
      }
    },

    setFilter(filter) {
      this.activeFilter = filter;
    },

    onReport(machine) {
      this.selectedMachine = machine;
      this.showReportModal = true;
      this.$emit('report-incident', machine);
    },

    /**
     * Abre el hilo de conversación del expediente activo desde la insignia de la
     * tarjeta (RF-01.1, RF-01.2). El modal se monta con el canal 'SITE_MANAGER':
     * al ser responsable de sede solo recibe y publica comentarios públicos
     * (RF-02.1, RF-03.2, Art. V.4).
     */
    onOpenComments(machine) {
      this.selectedMachine = machine;
      this.showCommentsModal = true;
      this.$emit('open-comments', machine);
    },

    /**
     * Cierre del hilo: libera la selección para que la próxima tarjeta pulsada
     * vuelva a fijar su expediente.
     */
    onCloseComments() {
      this.showCommentsModal = false;
      this.selectedMachine = null;
    },

    onReopen(machine) {
      this.selectedMachine = machine;
      this.showReopenModal = true;
      this.$emit('reopen-incident', machine);
    },

    onIncidentCreated() {
      this.loadMachines();
    },

    onCommentAdded() {
      this.loadMachines();
    },

    onIncidentReopened() {
      this.loadMachines();
    },

    onCreateNewTicketFromExpired(machine) {
      this.selectedMachine = machine;
      this.showReportModal = true;
    },

    setPortalTab(tab) {
      this.activePortalTab = tab;
    },

    onViewMachineCertificate(machineCode) {
      this.selectedCertMachineCode = machineCode;
      this.showSanitaryCertModal = true;
    },

    onViewGlobalCertificate() {
      this.showGlobalCertModal = true;
    }
  },
  template: `
    <div class="vg-location-portal" style="max-width: 1200px; margin: 0 auto; padding: 24px 16px;">
      <!-- ================================================================= -->
      <!-- STATE A: UNAUTHENTICATED SITE LOGIN FORM                          -->
      <!-- ================================================================= -->
      <div
        v-if="!isAuthenticated"
        style="max-width: 440px; margin: 40px auto; background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 32px 24px; box-shadow: var(--shadow-card, 0 1px 3px rgba(0, 0, 0, 0.04));"
      >
        <div style="text-align: center; margin-bottom: 24px;">
          <div
            style="width: 48px; height: 48px; border-radius: var(--radius-interactive, 4px); background-color: var(--color-primary, #2560ff); display: inline-flex; align-items: center; justify-content: center; color: #ffffff; font-size: 24px; font-weight: 700; margin-bottom: 12px; font-family: var(--font-display, 'DM Sans', sans-serif);"
          >
            V
          </div>
          <h2 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 22px; font-weight: 700; color: var(--color-ink, #000000); margin: 0;">
            Portal del Responsable
          </h2>
          <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-muted, #6c7e9d); margin-top: 6px;">
            Introduce el código de tu sede y la clave de centro entregada por el servicio técnico.
          </p>
        </div>

        <form @submit.prevent="handleSiteLogin">
          <!-- Site Code Input -->
          <div style="margin-bottom: 16px;">
            <label
              for="site-code-input"
              style="display: block; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 6px;"
            >
              Código de Sede / Edificio
            </label>
            <input
              id="site-code-input"
              v-model="siteCodeInput"
              type="text"
              class="vg-input"
              placeholder="Ej: SEDE-BCN-01"
              autocapitalize="characters"
              style="text-transform: uppercase; font-family: monospace; font-size: 15px; font-weight: 600; letter-spacing: 0.05em; border-radius: var(--radius-interactive, 4px);"
              :disabled="isLoggingIn"
              required
            />
          </div>

          <!-- Access Code Input (S-4: centre credential handed over in person) -->
          <div style="margin-bottom: 16px;">
            <label
              for="access-code-input"
              style="display: block; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;"
            >
              Clave de Centro
            </label>
            <input
              id="access-code-input"
              v-model="accessCodeInput"
              type="text"
              class="vg-input"
              placeholder="Ej: K7M4P-2QX9R"
              autocapitalize="characters"
              autocomplete="off"
              style="text-transform: uppercase; font-family: monospace; font-size: 15px; font-weight: 600; letter-spacing: 0.05em; border-radius: var(--radius-interactive, 4px);"
              :disabled="isLoggingIn"
              required
            />
          </div>

          <!-- Error Alert -->
          <div
            v-if="loginError"
            style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 10px 12px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 16px; font-family: var(--font-body, Inter, sans-serif);"
            role="alert"
          >
            {{ loginError }}
          </div>

          <!-- Submit Button -->
          <button
            type="submit"
            class="vg-btn vg-btn-primary"
            style="width: 100%; height: 40px; font-size: 15px; border-radius: var(--radius-interactive, 4px);"
            :disabled="isLoggingIn"
          >
            <span v-if="!isLoggingIn">Acceder a mi sede</span>
            <span v-else>Verificando sede...</span>
          </button>
        </form>

        <div style="text-align: center; margin-top: 20px; font-size: 12px; color: var(--color-ink-muted, #6c7e9d); font-family: var(--font-body, Inter, sans-serif);">
          El código de centro aparece en la etiqueta frontal de cualquiera de las máquinas de vending. La clave de centro se entrega en mano: si no la tiene, solicítela al servicio técnico.
        </div>
      </div>

      <!-- ================================================================= -->
      <!-- STATE B: AUTHENTICATED LOCATION DASHBOARD                         -->
      <!-- ================================================================= -->
      <div v-else>
        <!-- Location Header Banner (8px card radius) -->
        <div
          class="vg-card"
          style="border-radius: var(--radius-card, 8px); margin-bottom: 24px; padding: 20px 24px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px;"
        >
          <div>
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
              <span
                style="background-color: var(--color-primary, #2560ff); color: #ffffff; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 700; padding: 3px 8px; border-radius: var(--radius-interactive, 4px);"
              >
                {{ location.site_code }}
              </span>
              <h2 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 20px; font-weight: 700; color: var(--color-ink, #000000); margin: 0;">
                {{ location.name }}
              </h2>
            </div>
            <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-slate, #2c333f); margin: 0;">
              📍 {{ location.address }} · Contacto: {{ location.contact_name || 'No especificado' }}
            </p>
          </div>

          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            style="border-radius: var(--radius-interactive, 4px); font-size: 13px;"
            :disabled="isLoadingMachines"
            @click="loadMachines"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;" aria-hidden="true">
              <polyline points="23 4 23 10 17 10"></polyline>
              <polyline points="1 20 1 14 7 14"></polyline>
              <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
            </svg>
            Actualizar estado
          </button>
        </div>

        <!-- Portal Section Tabs: Machines vs Sanitary (RF-PREV-06, RF-PREV-07) -->
        <div style="display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid var(--color-hairline, #c8cfda); padding-bottom: 10px;">
          <button
            type="button"
            class="vg-btn"
            :style="{
              backgroundColor: activePortalTab === 'machines' ? 'var(--color-primary, #2560ff)' : '#ffffff',
              color: activePortalTab === 'machines' ? '#ffffff' : 'var(--color-slate, #2c333f)',
              border: '1px solid ' + (activePortalTab === 'machines' ? 'var(--color-primary, #2560ff)' : 'var(--color-hairline, #c8cfda)'),
              borderRadius: 'var(--radius-interactive, 4px)',
              fontSize: '13px',
              fontWeight: '600',
              height: '36px',
              padding: '0 16px'
            }"
            @click="setPortalTab('machines')"
          >
            📋 Máquinas e Incidencias
          </button>
          <button
            type="button"
            class="vg-btn"
            :style="{
              backgroundColor: activePortalTab === 'sanitary' ? 'var(--color-primary, #2560ff)' : '#ffffff',
              color: activePortalTab === 'sanitary' ? '#ffffff' : 'var(--color-slate, #2c333f)',
              border: '1px solid ' + (activePortalTab === 'sanitary' ? 'var(--color-primary, #2560ff)' : 'var(--color-hairline, #c8cfda)'),
              borderRadius: 'var(--radius-interactive, 4px)',
              fontSize: '13px',
              fontWeight: '600',
              height: '36px',
              padding: '0 16px'
            }"
            @click="setPortalTab('sanitary')"
          >
            🛡️ Control Higiénico y Certificados
          </button>
          <button
            type="button"
            class="vg-btn"
            :style="{
              backgroundColor: activePortalTab === 'refunds' ? 'var(--color-primary, #2560ff)' : '#ffffff',
              color: activePortalTab === 'refunds' ? '#ffffff' : 'var(--color-slate, #2c333f)',
              border: '1px solid ' + (activePortalTab === 'refunds' ? 'var(--color-primary, #2560ff)' : 'var(--color-hairline, #c8cfda)'),
              borderRadius: 'var(--radius-interactive, 4px)',
              fontSize: '13px',
              fontWeight: '600',
              height: '36px',
              padding: '0 16px'
            }"
            @click="setPortalTab('refunds')"
          >
            💶 Reintegros
          </button>
        </div>

        <!-- TAB 1: Machines & Incidents View -->
        <div v-if="activePortalTab === 'machines'">
          <!-- Global Amber Banner when at least one machine is in PENDING_INFO (RF-05.1, RF-05.2, RNF-04) -->
          <div
            v-if="pendingInfoMachines.length > 0"
            class="vg-portal-pending-info-alert"
            data-testid="location-portal-pending-info-alert"
            :style="{
              background: amberTokens.bg,
              border: '1px solid ' + amberTokens.border,
              padding: '12px 16px',
              borderRadius: 'var(--radius-card, 8px)',
              marginBottom: '20px',
              display: 'flex',
              flexDirection: 'column',
              gap: '8px'
            }"
          >
            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
              <div>
                <div
                  :style="{
                    fontFamily: 'var(--font-display, \'DM Sans\', sans-serif)',
                    fontSize: '14px',
                    fontWeight: '700',
                    color: amberTokens.color
                  }"
                >
                  ⏸️ Intervención en Pausa: El servicio técnico requiere acceso o información
                </div>
                <div
                  :style="{
                    fontFamily: 'var(--font-body, Inter, sans-serif)',
                    fontSize: '13px',
                    color: 'var(--color-warning-text, ' + amberTokens.color + ')',
                    marginTop: '2px'
                  }"
                >
                  Hay {{ pendingInfoMachines.length === 1 ? '1 máquina' : pendingInfoMachines.length + ' máquinas' }} en espera de respuesta en esta sede.
                  <span v-if="pendingInfoMachines.length === 1 && firstPendingInfoReasonLabel">
                    <strong>Causa:</strong> {{ firstPendingInfoReasonLabel }}.
                  </span>
                </div>
              </div>
              <button
                type="button"
                class="vg-btn vg-btn-primary"
                data-testid="location-portal-pending-info-action-btn"
                style="height: 36px; padding: 0 14px; font-size: 13px; font-weight: 600; border-radius: var(--radius-interactive, 4px); white-space: nowrap;"
                @click="onOpenComments(firstPendingInfoMachine)"
              >
                💬 Aportar información / Responder al técnico
              </button>
            </div>
          </div>

          <!-- Filter Tabs & Stats -->
          <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px;">
            <!-- Tabs -->
            <div style="display: flex; gap: 6px; background-color: #f3f4f6; padding: 4px; border-radius: var(--radius-interactive, 4px);">
              <button
                type="button"
                class="vg-btn"
                :style="{
                  backgroundColor: activeFilter === 'all' ? '#ffffff' : 'transparent',
                  color: activeFilter === 'all' ? 'var(--color-primary, #2560ff)' : 'var(--color-slate, #2c333f)',
                  boxShadow: activeFilter === 'all' ? '0 1px 2px rgba(0,0,0,0.05)' : 'none',
                  height: '32px',
                  fontSize: '13px',
                  padding: '0 12px',
                  borderRadius: 'var(--radius-interactive, 4px)'
                }"
                @click="setFilter('all')"
              >
                Todas ({{ totalCount }})
              </button>
              <button
                type="button"
                class="vg-btn"
                :style="{
                  backgroundColor: activeFilter === 'incident' ? '#ffffff' : 'transparent',
                  color: activeFilter === 'incident' ? '#b91c1c' : 'var(--color-slate, #2c333f)',
                  boxShadow: activeFilter === 'incident' ? '0 1px 2px rgba(0,0,0,0.05)' : 'none',
                  height: '32px',
                  fontSize: '13px',
                  padding: '0 12px',
                  borderRadius: 'var(--radius-interactive, 4px)'
                }"
                @click="setFilter('incident')"
              >
                Con Avería ({{ incidentCount }})
              </button>
              <button
                type="button"
                class="vg-btn"
                :style="{
                  backgroundColor: activeFilter === 'operational' ? '#ffffff' : 'transparent',
                  color: activeFilter === 'operational' ? '#15803d' : 'var(--color-slate, #2c333f)',
                  boxShadow: activeFilter === 'operational' ? '0 1px 2px rgba(0,0,0,0.05)' : 'none',
                  height: '32px',
                  fontSize: '13px',
                  padding: '0 12px',
                  borderRadius: 'var(--radius-interactive, 4px)'
                }"
                @click="setFilter('operational')"
              >
                Operativas ({{ operationalCount }})
              </button>
            </div>

            <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-muted, #6c7e9d);">
              Mostrando {{ filteredMachines.length }} de {{ totalCount }} máquinas
            </div>
          </div>

          <!-- Machines Grid (8px cards) -->
          <div v-if="isLoadingMachines && machines.length === 0" style="text-align: center; padding: 48px;">
            <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 15px; color: var(--color-slate, #2c333f);">
              Cargando el parque de máquinas del centro...
            </p>
          </div>

          <div
            v-else-if="machinesError"
            style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 16px; border-radius: var(--radius-card, 8px); margin-bottom: 24px; text-align: center;"
          >
            <p style="margin: 0 0 10px 0;">{{ machinesError }}</p>
            <button type="button" class="vg-btn vg-btn-secondary" @click="loadMachines">Reintentar</button>
          </div>

          <div
            v-else-if="filteredMachines.length === 0"
            style="text-align: center; padding: 48px; background-color: #ffffff; border: 1px dashed var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px);"
          >
            <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-muted, #6c7e9d); margin: 0;">
              No se han encontrado máquinas con el filtro seleccionado.
            </p>
          </div>

          <!-- Responsive Card Grid with 8px border radius cards -->
          <div
            v-else
            class="vg-machine-grid"
            style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;"
          >
            <MachineCard
              v-for="m in filteredMachines"
              :key="m.id"
              :machine="m"
              @report="onReport"
              @open-comments="onOpenComments"
              @reopen="onReopen"
            />
          </div>
        </div>

        <!-- TAB 2: Sanitary Status & Certificates Tab (RF-PREV-06, RF-PREV-07) -->
        <div v-else-if="activePortalTab === 'sanitary'">
          <SiteSanitaryStatusTab
            @view-certificate="onViewMachineCertificate"
            @view-global-certificate="onViewGlobalCertificate"
          />
        </div>

        <!-- TAB 3: Refunds Desk Tab (RF-REF-06, RF-REF-10, Art. V.4) -->
        <div v-else-if="activePortalTab === 'refunds'">
          <LocationRefundsTab />
        </div>

        <!-- Incident Report Modal -->
        <IncidentReportModal
          v-model="showReportModal"
          :machine="selectedMachine"
          @created="onIncidentCreated"
          @commented="onCommentAdded"
        />

        <!-- Conversation Thread Modal (Módulo 10: canal SITE_MANAGER, RF-01.1) -->
        <IncidentCommentThreadModal
          :is-open="showCommentsModal"
          :incident-id="activeCommentIncidentId"
          :ticket-code="activeCommentTicketCode"
          role="SITE_MANAGER"
          @close="onCloseComments"
          @comment-added="onCommentAdded"
        />

        <!-- Reopen Ticket in Warranty Modal -->
        <ReopenTicketModal
          v-model="showReopenModal"
          :machine="selectedMachine"
          @reopened="onIncidentReopened"
          @create-new-ticket="onCreateNewTicketFromExpired"
        />

        <!-- Sanitary Individual Certificate Modal (RF-PREV-07, Art. V.4) -->
        <SanitaryCertificateModal
          v-model="showSanitaryCertModal"
          :machine-code="selectedCertMachineCode"
        />

        <!-- Site Global Consolidated Certificate Modal (RF-PREV-07, EARS 7.2) -->
        <SiteGlobalCertificateModal
          v-model="showGlobalCertModal"
        />
      </div>
    </div>
  `
};

export default LocationPortalView;
