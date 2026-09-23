/**
 * VendGuard - CoordinatorDashboardView (CoordinatorDashboardView.js)
 * 
 * Central Triage & Supervision Dashboard for Coordinators (RF-05, RF-06, RF-11, T-37).
 * 
 * Features:
 * 1. Global table of incidents with combined filters (status, urgency, SLA breaches, search).
 * 2. 24/7 SLA monitoring banner with flashing visual alert when critical incidents exceed 60m (RF-11).
 * 3. 60-second polling mechanism keeping the queue fresh without manual page reloads (plan.md).
 * 4. AssignTechnician modal with mandatory justification for urgency overrides (RF-05 / EARS 5.3).
 * 5. CancelIncident modal enforcing mandatory discard reason (RF-06 / EARS 6.1, Soft Delete).
 * 6. Internal login form if unauthenticated or missing coordinator role (RF-04).
 */

import { api } from '../api.js';
import { store } from '../store.js';
import { IncidentBadge } from '../components/IncidentBadge.js';
import { ModalDialog } from '../components/ModalDialog.js';
import { QrLabelModal } from '../components/QrLabelModal.js';
import { QrBatchPrintView } from './QrBatchPrintView.js';
import { CoordinatorFleetTab } from '../components/CoordinatorFleetTab.js';
import { CoordinatorMetricsView } from './CoordinatorMetricsView.js';

// Default list of route technicians from seeds
const DEFAULT_TECHNICIANS = [
  { id: 2, name: 'Jordi Técnico Ruta BCN', email: 'jordi.ruta@vendguard.internal' },
  { id: 3, name: 'Marta Técnica Ruta BCN', email: 'marta.ruta@vendguard.internal' }
];

export const CoordinatorDashboardView = {
  name: 'CoordinatorDashboardView',
  components: {
    IncidentBadge,
    ModalDialog,
    QrLabelModal,
    QrBatchPrintView,
    CoordinatorFleetTab,
    CoordinatorMetricsView
  },
  emits: ['assigned', 'cancelled', 'refresh'],
  data() {
    return {
      // Navigation Tabs (RF-FLEET-01, RF-03)
      activeTab: 'incidents', // 'incidents' | 'fleet' | 'metrics'

      // Login Form
      loginEmail: '',
      loginPassword: '',
      loginError: '',
      isLoggingIn: false,

      // Incidents Data & Polling
      incidents: [],
      isLoading: false,
      incidentsError: '',
      pollingTimer: null,
      lastUpdated: null,

      // Filters
      filterStatus: '',
      filterUrgency: '',
      filterSearch: '',
      filterSlaOnly: false,

      // Available technicians
      technicians: [...DEFAULT_TECHNICIANS],

      // Assign Modal State
      showAssignModal: false,
      selectedIncident: null,
      assignTechnicianId: 2,
      assignUrgencyOverride: '',
      assignUrgencyReason: '',
      isAssigning: false,
      assignError: '',

      // Cancel Modal State
      showCancelModal: false,
      cancelReason: '',
      isCancelling: false,
      cancelError: '',

      // QR Label & Batch Print State (T-QR-14)
      showQrLabelModal: false,
      selectedQrMachine: null,
      showQrBatchView: false,
      selectedBatchLocationId: 1,
      selectedBatchLocationName: 'Hospital del Mar - Edificio Central'
    };
  },
  computed: {
    isAuthenticated() {
      return store.isCoordinator && store.state.user !== null;
    },
    currentUser() {
      return store.state.user;
    },
    slaBreachedIncidents() {
      return this.incidents.filter(inc => {
        const isCritical = String(inc.urgency || '').toUpperCase() === 'CRITICAL';
        const isPending = !inc.assigned_technician_id || ['REGISTRADA', 'REGISTERED', 'REABIERTA', 'REOPENED'].includes(String(inc.status || '').toUpperCase());
        const minutes = Number(inc.waiting_minutes ?? inc.sla_minutes_elapsed ?? 0);
        return inc.sla_breached === true || (isCritical && isPending && minutes > 60);
      });
    },
    filteredIncidents() {
      return this.incidents.filter(inc => {
        // Status filter
        if (this.filterStatus && String(inc.status || '').toUpperCase() !== this.filterStatus) {
          return false;
        }

        // Urgency filter
        if (this.filterUrgency && String(inc.urgency || '').toUpperCase() !== this.filterUrgency) {
          return false;
        }

        // SLA breach only filter
        if (this.filterSlaOnly) {
          const isBreached = inc.sla_breached === true || (
            String(inc.urgency || '').toUpperCase() === 'CRITICAL' &&
            Number(inc.waiting_minutes ?? 0) > 60 &&
            !inc.assigned_technician_id
          );
          if (!isBreached) return false;
        }

        // Text search (ticket code, machine code, site code or location name)
        if (this.filterSearch.trim()) {
          const q = this.filterSearch.trim().toLowerCase();
          const matchCode = String(inc.ticket_code || '').toLowerCase().includes(q);
          const matchMachine = String(inc.machine_code || '').toLowerCase().includes(q);
          const matchSite = String(inc.site_code || '').toLowerCase().includes(q);
          const matchLocation = String(inc.location_name || '').toLowerCase().includes(q);
          if (!matchCode && !matchMachine && !matchSite && !matchLocation) {
            return false;
          }
        }

        return true;
      });
    },
    metrics() {
      const active = this.incidents.filter(i => !['CERRADA', 'CLOSED', 'CANCELADA', 'CANCELLED'].includes(String(i.status || '').toUpperCase()));
      const criticalFood = active.filter(i => String(i.urgency || '').toUpperCase() === 'CRITICAL' && i.machine_type === 'PERISHABLE_FOOD');
      const unassigned = active.filter(i => !i.assigned_technician_id);
      const pendingParts = active.filter(i => ['PENDIENTE_REPUESTO', 'PENDING_PARTS'].includes(String(i.status || '').toUpperCase()));

      return {
        totalActive: active.length,
        criticalFood: criticalFood.length,
        unassigned: unassigned.length,
        pendingParts: pendingParts.length
      };
    }
  },
  mounted() {
    if (this.isAuthenticated) {
      this.loadIncidents();
      this.startPolling();
    }
  },
  beforeUnmount() {
    this.stopPolling();
  },
  methods: {
    startPolling() {
      this.stopPolling();
      // 60s periodic polling for 24/7 SLA updates (RF-11 / plan.md)
      this.pollingTimer = setInterval(() => {
        if (this.isAuthenticated) {
          this.loadIncidents(true);
        }
      }, 60000);
    },
    stopPolling() {
      if (this.pollingTimer) {
        clearInterval(this.pollingTimer);
        this.pollingTimer = null;
      }
    },

    /**
     * Internal Login (RF-04)
     */
    async handleInternalLogin() {
      if (!this.loginEmail.trim() || !this.loginPassword.trim()) {
        this.loginError = 'Por favor, introduzca correo electrónico y contraseña.';
        return;
      }

      this.loginError = '';
      this.isLoggingIn = true;
      store.setLoading(true);

      try {
        const response = await api.auth.internalLogin(this.loginEmail.trim(), this.loginPassword);
        if (response?.user?.role !== 'COORDINATOR') {
          store.clearSession();
          this.loginError = 'Acceso denegado: se requieren permisos de Coordinador del Servicio.';
          return;
        }

        store.setInternalSession(response.user, response.token);
        this.loginPassword = '';
        await this.loadIncidents();
        this.startPolling();
      } catch (err) {
        this.loginError = err.message || 'Credenciales incorrectas o usuario inactivo.';
      } finally {
        this.isLoggingIn = false;
        store.setLoading(false);
      }
    },

    /**
     * Loads incidents queue with SLA indicators
     */
    async loadIncidents(isSilent = false) {
      if (!isSilent) {
        this.isLoading = true;
        store.setLoading(true);
      }
      this.incidentsError = '';

      try {
        const data = await api.coordinator.getIncidents();
        this.incidents = Array.isArray(data) ? data : [];
        this.lastUpdated = new Date();
        this.$emit('refresh', this.incidents);
      } catch (err) {
        this.incidentsError = err.message || 'Error al cargar la bandeja de averías.';
      } finally {
        if (!isSilent) {
          this.isLoading = false;
          store.setLoading(false);
        }
      }
    },

    // --- Modal Triggers ---

    openAssignModal(incident) {
      this.selectedIncident = incident;
      this.assignTechnicianId = incident.assigned_technician_id || (this.technicians[0]?.id || 2);
      this.assignUrgencyOverride = '';
      this.assignUrgencyReason = '';
      this.assignError = '';
      this.showAssignModal = true;
    },

    closeAssignModal() {
      this.showAssignModal = false;
      this.selectedIncident = null;
    },

    openCancelModal(incident) {
      this.selectedIncident = incident;
      this.cancelReason = '';
      this.cancelError = '';
      this.showCancelModal = true;
    },

    closeCancelModal() {
      this.showCancelModal = false;
      this.selectedIncident = null;
    },

    // --- Actions ---

    /**
     * Assigns technician with optional urgency override (RF-05 / EARS 5.1, 5.3)
     */
    async submitAssignment() {
      if (!this.selectedIncident) return;

      // Validate: if urgency is changed, reason is mandatory (EARS 5.3)
      if (this.assignUrgencyOverride && !this.assignUrgencyReason.trim()) {
        this.assignError = 'Es obligatorio indicar el motivo justificado de la reclasificación de urgencia (EARS 5.3).';
        return;
      }

      this.assignError = '';
      this.isAssigning = true;
      store.setLoading(true);

      try {
        const result = await api.coordinator.assignTechnician(
          this.selectedIncident.id,
          this.assignTechnicianId,
          this.assignUrgencyOverride || null,
          this.assignUrgencyReason.trim() || null
        );

        store.addAlert(
          `Incidencia #${this.selectedIncident.ticket_code} asignada correctamente.`,
          'success',
          5000
        );

        this.$emit('assigned', result);
        this.closeAssignModal();
        await this.loadIncidents();
      } catch (err) {
        this.assignError = err.message || 'Error al asignar la incidencia.';
      } finally {
        this.isAssigning = false;
        store.setLoading(false);
      }
    },

    /**
     * Cancels an incident with mandatory reason (RF-06 / EARS 6.1, Soft Delete)
     */
    async submitCancellation() {
      if (!this.selectedIncident) return;

      if (!this.cancelReason.trim()) {
        this.cancelError = 'Debe indicar obligatoriamente el motivo del descarte o cancelación (EARS 6.1).';
        return;
      }

      this.cancelError = '';
      this.isCancelling = true;
      store.setLoading(true);

      try {
        const result = await api.coordinator.cancelIncident(
          this.selectedIncident.id,
          this.cancelReason.trim()
        );

        store.addAlert(
          `Incidencia #${this.selectedIncident.ticket_code} descartada correctamente (Borrado Lógico).`,
          'info',
          5000
        );

        this.$emit('cancelled', result);
        this.closeCancelModal();
        await this.loadIncidents();
      } catch (err) {
        this.cancelError = err.message || 'Error al descartar la incidencia.';
      } finally {
        this.isCancelling = false;
        store.setLoading(false);
      }
    },

    filterBySlaBreach() {
      this.filterSlaOnly = true;
    },

    clearFilters() {
      this.filterStatus = '';
      this.filterUrgency = '';
      this.filterSearch = '';
      this.filterSlaOnly = false;
    },

    /**
     * Gestión de Etiquetas QR individuales y en lote (RF-01, RF-02 / T-QR-14)
     */
    openQrLabelModal(incidentOrMachine) {
      this.selectedQrMachine = {
        id: incidentOrMachine.machine_id || incidentOrMachine.id,
        code: incidentOrMachine.machine_code || incidentOrMachine.code,
        model: incidentOrMachine.machine_model || incidentOrMachine.model,
        machine_type: incidentOrMachine.machine_type,
        floor_wing: incidentOrMachine.floor_wing
      };
      this.showQrLabelModal = true;
    },

    closeQrLabelModal() {
      this.showQrLabelModal = false;
      this.selectedQrMachine = null;
    },

    openQrBatchPrint(locationId = 1, locationName = 'Hospital del Mar - Edificio Central') {
      this.selectedBatchLocationId = locationId;
      this.selectedBatchLocationName = locationName;
      this.showQrBatchView = true;
    },

    closeQrBatchPrint() {
      this.showQrBatchView = false;
    }
  },
  template: `
    <div class="vg-coordinator-dashboard" style="max-width: 1300px; margin: 0 auto; padding: 24px 16px;">
      <!-- =================================================================== -->
      <!-- STATE A: UNAUTHENTICATED COORDINATOR LOGIN                          -->
      <!-- =================================================================== -->
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
            Panel de Coordinación
          </h2>
          <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-muted, #6c7e9d); margin-top: 6px;">
            Identifícate con tus credenciales de operador para supervisar el parque de vending.
          </p>
        </div>

        <form @submit.prevent="handleInternalLogin">
          <div style="margin-bottom: 16px;">
            <label for="coord-email" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 6px;">
              Correo electrónico
            </label>
            <input
              id="coord-email"
              v-model="loginEmail"
              type="email"
              class="vg-input"
              placeholder="coordinacion@vendguard.internal"
              required
              :disabled="isLoggingIn"
            />
          </div>

          <div style="margin-bottom: 16px;">
            <label for="coord-password" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 6px;">
              Contraseña
            </label>
            <input
              id="coord-password"
              v-model="loginPassword"
              type="password"
              class="vg-input"
              required
              :disabled="isLoggingIn"
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
            style="width: 100%; height: 40px; font-size: 15px; border-radius: var(--radius-interactive, 4px);"
            :disabled="isLoggingIn"
          >
            <span v-if="!isLoggingIn">Entrar al panel de triaje</span>
            <span v-else>Verificando credenciales...</span>
          </button>
        </form>
      </div>

      <!-- =================================================================== -->
      <!-- STATE B: AUTHENTICATED COORDINATOR TRIAGE DASHBOARD                 -->
      <!-- =================================================================== -->
      <!-- Vista de Impresión en Lote A4 de Sede (EARS 2.2 / T-QR-14) -->
      <QrBatchPrintView
        v-if="showQrBatchView"
        :location-id="selectedBatchLocationId"
        :location-name="selectedBatchLocationName"
        @close="closeQrBatchPrint"
      />

      <div v-else>
        <!-- 0. Barra de Pestañas de Navegación del Coordinador (RF-FLEET-01) -->
        <div style="display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid var(--color-hairline, #c8cfda); padding-bottom: 8px;">
          <button
            type="button"
            class="vg-btn"
            :class="activeTab === 'incidents' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 38px; font-size: 14px; font-weight: 600; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px;"
            @click="activeTab = 'incidents'"
            data-testid="tab-incidents"
          >
            🚨 Triaje y Avisos (SLA)
            <span
              v-if="slaBreachedIncidents.length > 0"
              style="background-color: #dc2626; color: #ffffff; font-size: 11px; padding: 1px 6px; border-radius: 10px;"
            >
              {{ slaBreachedIncidents.length }}
            </span>
          </button>

          <button
            type="button"
            class="vg-btn"
            :class="activeTab === 'fleet' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 38px; font-size: 14px; font-weight: 600; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px;"
            @click="activeTab = 'fleet'"
            data-testid="tab-fleet"
          >
            🏢 Parque de Sedes y Máquinas
          </button>

          <button
            type="button"
            class="vg-btn"
            :class="activeTab === 'metrics' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 38px; font-size: 14px; font-weight: 600; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px;"
            @click="activeTab = 'metrics'"
            data-testid="tab-metrics"
          >
            📊 Métricas y Auditoría
          </button>
        </div>

        <!-- CONTENIDO PESTAÑA 1: TRIAJE Y AVISOS -->
        <div v-if="activeTab === 'incidents'">
          <!-- 1. Flashing SLA Breaches Alert Banner (RF-11 / EARS 11.1) -->
          <div
            v-if="slaBreachedIncidents.length > 0"
          class="vg-sla-alert-banner"
          style="background-color: #fee2e2; border: 2px solid #ef4444; border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 20px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15);"
        >
          <div style="display: flex; align-items: center; gap: 12px;">
            <span style="font-size: 28px; animation: vg-pulse 1.5s infinite;" aria-hidden="true">🚨</span>
            <div>
              <strong style="color: #991b1b; font-size: 16px; font-family: var(--font-display, 'DM Sans', sans-serif); display: block;">
                ¡ALERTA CRÍTICA DE SLA!: {{ slaBreachedIncidents.length }} incidencia(s) superan 60 min de espera
              </strong>
              <span style="color: #7f1d1d; font-size: 13px; font-family: var(--font-body, Inter, sans-serif);">
                Averías críticas de frío sin técnico asignado que ponen en riesgo la seguridad alimentaria (Art. II Constitución).
              </span>
            </div>
          </div>

          <button
            type="button"
            class="vg-btn"
            style="background-color: #dc2626; color: #ffffff; border-color: #b91c1c; border-radius: var(--radius-interactive, 4px); font-weight: 700; height: 36px; padding: 0 16px;"
            @click="filterBySlaBreach"
          >
            Ver incidencias en riesgo SLA
          </button>
        </div>

        <!-- 2. Metrics Summary Bar (8px cards) -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
          <div class="vg-card" style="padding: 16px; border-radius: var(--radius-card, 8px);">
            <div style="font-size: 12px; color: var(--color-ink-muted, #6c7e9d); font-weight: 600; text-transform: uppercase;">
              Avisos Activos
            </div>
            <div style="font-size: 26px; font-weight: 700; color: var(--color-ink, #000000); font-family: var(--font-display, 'DM Sans', sans-serif); margin-top: 4px;">
              {{ metrics.totalActive }}
            </div>
          </div>

          <div class="vg-card" style="padding: 16px; border-radius: var(--radius-card, 8px); border-left: 4px solid #dc2626;">
            <div style="font-size: 12px; color: #dc2626; font-weight: 600; text-transform: uppercase;">
              Críticas de Frío (Comida)
            </div>
            <div style="font-size: 26px; font-weight: 700; color: #dc2626; font-family: var(--font-display, 'DM Sans', sans-serif); margin-top: 4px;">
              {{ metrics.criticalFood }}
            </div>
          </div>

          <div class="vg-card" style="padding: 16px; border-radius: var(--radius-card, 8px); border-left: 4px solid #f97316;">
            <div style="font-size: 12px; color: #c2410c; font-weight: 600; text-transform: uppercase;">
              Sin Asignar
            </div>
            <div style="font-size: 26px; font-weight: 700; color: #c2410c; font-family: var(--font-display, 'DM Sans', sans-serif); margin-top: 4px;">
              {{ metrics.unassigned }}
            </div>
          </div>

          <div class="vg-card" style="padding: 16px; border-radius: var(--radius-card, 8px); border-left: 4px solid #eab308;">
            <div style="font-size: 12px; color: #854d0e; font-weight: 600; text-transform: uppercase;">
              Pendientes Repuesto
            </div>
            <div style="font-size: 26px; font-weight: 700; color: #854d0e; font-family: var(--font-display, 'DM Sans', sans-serif); margin-top: 4px;">
              {{ metrics.pendingParts }}
            </div>
          </div>
        </div>

        <!-- 3. Combined Filter Bar -->
        <div
          class="vg-card"
          style="padding: 16px 20px; border-radius: var(--radius-card, 8px); margin-bottom: 20px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px;"
        >
          <!-- Controls -->
          <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 12px; flex: 1 1 auto;">
            <!-- Search input -->
            <input
              v-model="filterSearch"
              type="text"
              class="vg-input"
              placeholder="Buscar por ticket, máquina o sede..."
              style="max-width: 260px; height: 36px;"
            />

            <!-- Status filter -->
            <select v-model="filterStatus" class="vg-select" style="max-width: 170px; height: 36px;">
              <option value="">Todos los estados</option>
              <option value="REGISTRADA">Registrada</option>
              <option value="ASIGNADA">Asignada</option>
              <option value="EN_CURSO">En curso</option>
              <option value="PENDIENTE_REPUESTO">Pend. Repuesto</option>
              <option value="RESUELTA">Resuelta</option>
              <option value="REABIERTA">Reabierta</option>
              <option value="CANCELADA">Cancelada</option>
            </select>

            <!-- Urgency filter -->
            <select v-model="filterUrgency" class="vg-select" style="max-width: 160px; height: 36px;">
              <option value="">Todas las urgencias</option>
              <option value="CRITICAL">Crítica</option>
              <option value="HIGH">Alta</option>
              <option value="MEDIUM">Media</option>
              <option value="LOW">Baja</option>
            </select>

            <!-- Only SLA Breach Toggle -->
            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: #991b1b; cursor: pointer;">
              <input type="checkbox" v-model="filterSlaOnly" />
              Solo alerta SLA (>60m)
            </label>

            <button
              v-if="filterStatus || filterUrgency || filterSearch || filterSlaOnly"
              type="button"
              class="vg-btn vg-btn-secondary"
              style="height: 32px; font-size: 12px;"
              @click="clearFilters"
            >
              Limpiar filtros
            </button>
          </div>

          <!-- Refresh and Polling status -->
          <div style="display: flex; align-items: center; gap: 10px;">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              style="height: 36px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;"
              @click="openQrBatchPrint(1, 'Hospital del Mar - Edificio Central')"
              title="Emitir cuadrícula de etiquetas A4 para la sede"
              data-testid="btn-batch-print"
            >
              📄 Etiquetas de Sede (A4)
            </button>

            <span style="font-size: 12px; color: var(--color-ink-muted, #6c7e9d);">
              Sondeo activo (60s)
            </span>
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              style="height: 36px; font-size: 13px;"
              :disabled="isLoading"
              @click="() => loadIncidents(false)"
            >
              Actualizar
            </button>
          </div>
        </div>

        <!-- 4. Incident Table (8px card) -->
        <div class="vg-card" style="border-radius: var(--radius-card, 8px); padding: 0; overflow: hidden;">
          <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-family: var(--font-body, Inter, sans-serif); font-size: 13px;">
              <thead>
                <tr style="background-color: #fafbfc; border-bottom: 1px solid var(--color-hairline, #c8cfda); color: var(--color-ink-muted, #6c7e9d); font-size: 12px; text-transform: uppercase; letter-spacing: 0.03em;">
                  <th style="padding: 12px 16px;">Ticket / SLA</th>
                  <th style="padding: 12px 16px;">Sede / Ubicación</th>
                  <th style="padding: 12px 16px;">Máquina</th>
                  <th style="padding: 12px 16px;">Urgencia</th>
                  <th style="padding: 12px 16px;">Estado</th>
                  <th style="padding: 12px 16px;">Técnico Asignado</th>
                  <th style="padding: 12px 16px; text-align: right;">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="isLoading && incidents.length === 0">
                  <td colspan="7" style="text-align: center; padding: 32px; color: var(--color-ink-muted, #6c7e9d);">
                    Cargando averías del parque...
                  </td>
                </tr>
                <tr v-else-if="filteredIncidents.length === 0">
                  <td colspan="7" style="text-align: center; padding: 32px; color: var(--color-ink-muted, #6c7e9d);">
                    No se han encontrado averías con los filtros actuales.
                  </td>
                </tr>
                <tr
                  v-for="inc in filteredIncidents"
                  :key="inc.id"
                  :style="{
                    borderBottom: '1px solid var(--color-hairline, #c8cfda)',
                    backgroundColor: inc.sla_breached ? '#fff5f5' : '#ffffff',
                    transition: 'background-color 0.15s ease'
                  }"
                  class="vg-incident-row"
                  :data-incident-id="inc.id"
                  :data-sla-breached="inc.sla_breached ? 'true' : 'false'"
                >
                  <!-- 1. Ticket & SLA -->
                  <td style="padding: 14px 16px; vertical-align: top;">
                    <div style="font-weight: 700; color: var(--color-ink, #000000); font-family: monospace; font-size: 13px;">
                      {{ inc.ticket_code }}
                    </div>
                    <div style="margin-top: 4px; display: flex; align-items: center; gap: 4px;">
                      <span
                        v-if="inc.sla_breached"
                        style="background-color: #dc2626; color: #ffffff; font-size: 11px; font-weight: 700; padding: 1px 6px; border-radius: 3px;"
                      >
                        SLA ROTO ({{ inc.waiting_minutes ?? inc.sla_minutes_elapsed }}m)
                      </span>
                      <span
                        v-else
                        style="font-size: 11px; color: var(--color-ink-muted, #6c7e9d);"
                      >
                        Espera: {{ inc.waiting_minutes ?? inc.sla_minutes_elapsed ?? 0 }}m
                      </span>
                    </div>
                  </td>

                  <!-- 2. Sede -->
                  <td style="padding: 14px 16px; vertical-align: top;">
                    <div style="font-weight: 600; color: var(--color-slate, #2c333f);">
                      {{ inc.location_name || inc.site_code }}
                    </div>
                    <div style="font-size: 12px; color: var(--color-ink-muted, #6c7e9d); margin-top: 2px;">
                      {{ inc.floor_wing || 'Ubicación no especificada' }}
                    </div>
                  </td>

                  <!-- 3. Máquina -->
                  <td style="padding: 14px 16px; vertical-align: top;">
                    <div style="font-weight: 600; color: var(--color-ink, #000000);">
                      {{ inc.machine_code }}
                    </div>
                    <div style="font-size: 12px; color: var(--color-ink-muted, #6c7e9d);">
                      {{ inc.machine_model || 'Vending' }}
                    </div>
                  </td>

                  <!-- 4. Urgencia -->
                  <td style="padding: 14px 16px; vertical-align: top;">
                    <IncidentBadge :value="inc.urgency" type="urgency" size="sm" />
                  </td>

                  <!-- 5. Estado -->
                  <td style="padding: 14px 16px; vertical-align: top;">
                    <IncidentBadge :value="inc.status" type="status" size="sm" />
                  </td>

                  <!-- 6. Técnico -->
                  <td style="padding: 14px 16px; vertical-align: top;">
                    <div v-if="inc.assigned_technician?.name || inc.technician_name" style="font-weight: 500; color: var(--color-slate, #2c333f);">
                      👤 {{ inc.assigned_technician?.name || inc.technician_name }}
                    </div>
                    <span v-else style="font-size: 12px; color: #dc2626; font-weight: 600;">
                      Sin asignar
                    </span>
                  </td>

                  <!-- 7. Acciones -->
                  <td style="padding: 14px 16px; vertical-align: top; text-align: right;">
                    <div style="display: inline-flex; gap: 8px;">
                      <!-- Imprimir Etiqueta QR Button (RF-01 / T-QR-14) -->
                      <button
                        type="button"
                        class="vg-btn vg-btn-secondary"
                        style="height: 30px; font-size: 12px; padding: 0 8px; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 4px;"
                        @click="openQrLabelModal(inc)"
                        title="Previsualizar, personalizar e imprimir etiqueta con código QR"
                        data-testid="btn-print-qr"
                      >
                        🏷️ Imprimir QR
                      </button>

                      <!-- Assign / Reassign Button -->
                      <button
                        type="button"
                        class="vg-btn vg-btn-primary"
                        style="height: 30px; font-size: 12px; padding: 0 10px; border-radius: var(--radius-interactive, 4px);"
                        @click="openAssignModal(inc)"
                        title="Asignar o reclasificar técnico"
                      >
                        Asignar
                      </button>

                      <!-- Cancel / Discard Button -->
                      <button
                        type="button"
                        class="vg-btn vg-btn-secondary"
                        style="height: 30px; font-size: 12px; padding: 0 8px; color: #dc2626; border-radius: var(--radius-interactive, 4px);"
                        @click="openCancelModal(inc)"
                        title="Descartar incidencia (Soft Delete)"
                      >
                        Descartar
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- CONTENIDO PESTAÑA 2: PARQUE DE SEDES Y MÁQUINAS (RF-FLEET-01, RF-FLEET-02, RF-FLEET-03) -->
      <CoordinatorFleetTab
        v-else-if="activeTab === 'fleet'"
        @open-qr="openQrLabelModal($event)"
        @open-batch-print="openQrBatchPrint($event.locationId, $event.locationName)"
      />

      <!-- CONTENIDO PESTAÑA 3: MÉTRICAS Y AUDITORÍA (RF-01, RF-02, RF-03, RF-05, RF-06) -->
      <CoordinatorMetricsView
        v-else-if="activeTab === 'metrics'"
      />
    </div>

      <!-- =================================================================== -->
      <!-- MODAL 1: ASSIGN TECHNICIAN & RECLASSIFY (RF-05)                     -->
      <!-- =================================================================== -->
      <ModalDialog
        v-model="showAssignModal"
        title="Asignar Técnico de Ruta"
        :subtitle="selectedIncident ? ('Ticket #' + selectedIncident.ticket_code + ' · Máquina ' + selectedIncident.machine_code) : ''"
        size="md"
        @close="closeAssignModal"
      >
        <form v-if="selectedIncident" @submit.prevent="submitAssignment">
          <!-- Technician Selector -->
          <div style="margin-bottom: 16px;">
            <label for="assign-tech-select" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 6px;">
              Seleccionar Técnico de Campo <span style="color: #dc2626;">*</span>
            </label>
            <select
              id="assign-tech-select"
              v-model="assignTechnicianId"
              class="vg-select"
              required
              :disabled="isAssigning"
            >
              <option v-for="t in technicians" :key="t.id" :value="t.id">
                {{ t.name }} ({{ t.email }})
              </option>
            </select>
          </div>

          <!-- Urgency Reclassification (Optional / Audit trail required) -->
          <div style="background-color: #fafbfc; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); padding: 14px; margin-bottom: 16px;">
            <label style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 6px;">
              Reclasificar nivel de urgencia <span style="font-size: 11px; color: var(--color-ink-muted, #6c7e9d);">(Opcional)</span>
            </label>
            <div style="display: flex; gap: 8px; margin-bottom: 10px;">
              <select v-model="assignUrgencyOverride" class="vg-select" :disabled="isAssigning">
                <option value="">Mantener urgencia actual ({{ selectedIncident.urgency }})</option>
                <option value="CRITICAL">Cambiar a CRÍTICA</option>
                <option value="HIGH">Cambiar a ALTA</option>
                <option value="MEDIUM">Cambiar a MEDIA</option>
                <option value="LOW">Cambiar a BAJA</option>
              </select>
            </div>

            <!-- Mandatory Justification if override is set (EARS 5.3) -->
            <div v-if="assignUrgencyOverride">
              <label for="assign-urgency-reason" style="display: block; font-size: 12px; font-weight: 600; color: #dc2626; margin-bottom: 4px;">
                Motivo justificado del cambio de urgencia <span style="color: #dc2626;">* (Obligatorio en auditoría)</span>
              </label>
              <textarea
                id="assign-urgency-reason"
                v-model="assignUrgencyReason"
                class="vg-textarea"
                rows="2"
                placeholder="Ej: Máquina vacía de perecederos tras fin de semana, se degrada a MEDIA..."
                required
                :disabled="isAssigning"
              ></textarea>
            </div>
          </div>

          <!-- Error Alert -->
          <div
            v-if="assignError"
            style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 10px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 16px;"
            role="alert"
          >
            {{ assignError }}
          </div>

          <!-- Actions -->
          <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid var(--color-hairline, #c8cfda); padding-top: 16px;">
            <button type="button" class="vg-btn vg-btn-secondary" @click="closeAssignModal" :disabled="isAssigning">
              Cancelar
            </button>
            <button type="submit" class="vg-btn vg-btn-primary" :disabled="isAssigning || !assignTechnicianId">
              <span v-if="!isAssigning">Confirmar Asignación</span>
              <span v-else>Guardando...</span>
            </button>
          </div>
        </form>
      </ModalDialog>

      <!-- =================================================================== -->
      <!-- MODAL 2: CANCEL / DISCARD INCIDENT (RF-06)                         -->
      <!-- =================================================================== -->
      <ModalDialog
        v-model="showCancelModal"
        title="Descartar Incidencia"
        :subtitle="selectedIncident ? ('Ticket #' + selectedIncident.ticket_code + ' · Máquina ' + selectedIncident.machine_code) : ''"
        size="md"
        @close="closeCancelModal"
      >
        <form v-if="selectedIncident" @submit.prevent="submitCancellation">
          <p style="font-size: 13px; color: var(--color-slate, #2c333f); margin-bottom: 14px; line-height: 1.4;">
            Conforme a la Constitución (Art. III) y la norma RF-06, la incidencia pasará a estado <strong>CANCELADA</strong> conservando íntegro su historial en base de datos (borrado lógico).
          </p>

          <div style="margin-bottom: 16px;">
            <label for="cancel-reason-input" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 4px;">
              Motivo obligatorio del descarte <span style="color: #dc2626;">*</span>
            </label>
            <textarea
              id="cancel-reason-input"
              v-model="cancelReason"
              class="vg-textarea"
              rows="3"
              placeholder="Ej: Falsa alarma reportada por error del informador / Máquina ya desenchufada por traslado..."
              required
              :disabled="isCancelling"
            ></textarea>
          </div>

          <!-- Error Alert -->
          <div
            v-if="cancelError"
            style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 10px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 16px;"
            role="alert"
          >
            {{ cancelError }}
          </div>

          <!-- Actions -->
          <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid var(--color-hairline, #c8cfda); padding-top: 16px;">
            <button type="button" class="vg-btn vg-btn-secondary" @click="closeCancelModal" :disabled="isCancelling">
              Volver
            </button>
            <button
              type="submit"
              class="vg-btn vg-btn-danger"
              :disabled="isCancelling || !cancelReason.trim()"
            >
              <span v-if="!isCancelling">Confirmar Descarte (Soft Delete)</span>
              <span v-else>Descartando...</span>
            </button>
          </div>
        </form>
      </ModalDialog>

      <!-- =================================================================== -->
      <!-- MODAL 3: QR LABEL PREVIEW & PRINT (RF-01, RF-02 / T-QR-14)          -->
      <!-- =================================================================== -->
      <QrLabelModal
        v-model="showQrLabelModal"
        :machine="selectedQrMachine"
        :machine-id="selectedQrMachine?.id"
        @close="closeQrLabelModal"
      />
    </div>
  `
};

export default CoordinatorDashboardView;
