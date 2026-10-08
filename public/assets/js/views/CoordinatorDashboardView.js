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
 * 7. Refunds inbox tab with double approval, digital settlement and motivated rejection (T-REF-18).
 * 8. Per-row "Ver detalle" trigger selecting the incident for the integral detail modal,
 *    kept independent from the preexisting assign/cancel quick actions (RF-01, T-IDM-14).
 * 9. Reactive mount of the integral detail modal over that trigger, refreshing the
 *    corresponding triage row in place on incident-updated (RF-01/RF-07/RF-08, T-IDM-15).
 */

import { api } from '../api.js';
import { store } from '../store.js';
import {
  canQuickAssign,
  canQuickCancel,
  isActiveStatus,
  isCriticalUrgency,
  isPendingAssignment,
  isTerminalStatus,
  normalizeIncidentStatus,
  normalizeUrgency
} from '../utils/IncidentStatusPermissions.js';
import { IncidentBadge } from '../components/IncidentBadge.js';
import { ModalDialog } from '../components/ModalDialog.js';
import { QrLabelModal } from '../components/QrLabelModal.js';
import { QrBatchPrintView } from './QrBatchPrintView.js';
import { CoordinatorFleetTab } from '../components/CoordinatorFleetTab.js';
import { CoordinatorMetricsView } from './CoordinatorMetricsView.js';
import { AdminLocationsTab } from '../components/AdminLocationsTab.js';
import { AdminMachinesTab } from '../components/AdminMachinesTab.js';
import { AdminUsersTab } from '../components/AdminUsersTab.js';
import { CoordinatorPreventiveDashboard } from '../components/CoordinatorPreventiveDashboard.js';
import { CoordinatorPreventiveOrdersTab } from '../components/CoordinatorPreventiveOrdersTab.js';
import { CoordinatorPreventiveSettingsModal } from '../components/CoordinatorPreventiveSettingsModal.js';
import { CoordinatorSparePartsTab } from '../components/CoordinatorSparePartsTab.js';
import { CoordinatorSparePartsAnalyticsTab } from '../components/CoordinatorSparePartsAnalyticsTab.js';
import { CoordinatorTerritorialMapTab } from '../components/CoordinatorTerritorialMapTab.js';
import { CoordinatorRefundsTab } from '../components/CoordinatorRefundsTab.js';
import { CoordinatorIncidentDetailModal } from '../components/CoordinatorIncidentDetailModal.js';
import { IncidentCommentThreadModal } from '../components/IncidentCommentThreadModal.js';// Status classification is delegated to the shared module utils/IncidentStatusPermissions.js
// (frontend mirror of the PHP lifecycle rules): no literal status lists live in this view.

export const CoordinatorDashboardView = {
  name: 'CoordinatorDashboardView',
  components: {
    IncidentBadge,
    ModalDialog,
    QrLabelModal,
    QrBatchPrintView,
    CoordinatorFleetTab,
    CoordinatorMetricsView,
    AdminLocationsTab,
    AdminMachinesTab,
    AdminUsersTab,
    CoordinatorPreventiveDashboard,
    CoordinatorPreventiveOrdersTab,
    CoordinatorPreventiveSettingsModal,
    CoordinatorSparePartsTab,
    CoordinatorSparePartsAnalyticsTab,
    CoordinatorTerritorialMapTab,
    CoordinatorRefundsTab,
    CoordinatorIncidentDetailModal,
    IncidentCommentThreadModal
  },
  emits: ['assigned', 'cancelled', 'refresh'],
  data() {
    return {
      // Navigation Tabs (RF-FLEET-01, RF-03, RF-05, RF-PREV-02, RF-REP-01, RF-REP-09)
      activeTab: 'incidents', // 'incidents' | 'fleet' | 'mapa-territorial' | 'preventive' | 'repuestos' | 'analitica-repuestos' | 'refunds' | 'admin' | 'metrics'
      activeAdminSubTab: 'locations', // 'locations' | 'machines' | 'users'
      activePreventiveSubTab: 'dashboard', // 'dashboard' | 'orders'
      showPreventiveSettingsModal: false,

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

      // Available technicians (loaded from GET /api/coordinator/users?role=TECHNICIAN
      // before the first assignment; no hardcoded seed list so personnel changes are
      // reflected immediately and EARS 3.9 keeps deactivated staff out of triage).
      technicians: [],
      techniciansLoaded: false,
      techniciansErrorMessage: '',
      isLoadingTechnicians: false,

      // Assign Modal State
      showAssignModal: false,
      selectedIncident: null,
      assignTechnicianId: 2,
      assignUrgencyOverride: '',
      assignUrgencyReason: '',
      isAssigning: false,
      assignError: '',

      // Bulk assignment modal state (reached from the territorial map, RF-MAP-09):
      // one technician + optional urgency reclassification applied to every active
      // incident of the chosen site (unassigned ones plus assigned ones on sites
      // worked by several technicians, enabling one-click consolidated reassignment)
      // in a single confirmation.
      showBulkAssignModal: false,
      bulkAssignSite: null,
      bulkAssignIncidents: [],
      bulkAssignTechnicianId: 2,
      bulkAssignUrgencyOverride: '',
      bulkAssignUrgencyReason: '',
      // Justification for the incidents of the batch that already have an active owner:
      // the backend only accepts a reassignment with >= 10 real characters (RF-07.3).
      bulkAssignReassignmentReason: '',
      bulkAssignError: '',
      isBulkAssigning: false,

      // Cancel Modal State
      showCancelModal: false,
      cancelReason: '',
      isCancelling: false,
      cancelError: '',

      // Incident Detail State (RF-01, T-IDM-14): the row trigger records the incident
      // chosen for the integral detail modal, mounted by T-IDM-15.
      showDetailModal: false,
      selectedDetailIncident: null,

      // Conversation Thread State (Módulo 10, RF-01.1, RF-02.3): fila de triaje y
      // ficha de detalle comparten el mismo hilo con canal 'COORDINATOR'.
      showCommentsModal: false,
      selectedCommentIncident: null,

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

    /** Identificador (ID primario o código de ticket) de la incidencia abierta en el detalle (T-IDM-15). */
    detailIncidentId() {
      const selected = this.selectedDetailIncident;
      if (!selected) {
        return null;
      }
      return selected.id ?? selected.ticket_code ?? null;
    },

    slaBreachedIncidents() {
      return this.incidents.filter(inc => {
        const isCritical = isCriticalUrgency(inc.urgency);
        const isPending = isPendingAssignment(inc);
        const minutes = Number(inc.waiting_minutes ?? inc.sla_minutes_elapsed ?? 0);
        return inc.sla_breached === true || (isCritical && isPending && minutes > 60);
      });
    },
    filteredIncidents() {
      return this.incidents.filter(inc => {
        // Status filter (supports both canonical English and localized Spanish)
        if (this.filterStatus) {
          const filterNorm = normalizeIncidentStatus(this.filterStatus);
          const incStatusNorm = normalizeIncidentStatus(inc.status);
          if (filterNorm && incStatusNorm !== filterNorm) {
            return false;
          }
        }

        // Urgency filter
        if (this.filterUrgency && normalizeUrgency(inc.urgency) !== normalizeUrgency(this.filterUrgency)) {
          return false;
        }

        // SLA breach only filter
        if (this.filterSlaOnly) {
          const isBreached = inc.sla_breached === true || (
            isCriticalUrgency(inc.urgency) &&
            Number(inc.waiting_minutes ?? 0) > 60 &&
            !inc.assigned_technician_id
          );
          if (!isBreached) return false;
        }

        // Text search (ticket code, machine code, site code or location name). The
        // incidents API serializes the site code as `location_site_code` (Incident.php),
        // with a legacy `site_code` fallback for older payloads.
        if (this.filterSearch.trim()) {
          const q = this.filterSearch.trim().toLowerCase();
          const matchCode = String(inc.ticket_code || '').toLowerCase().includes(q);
          const matchMachine = String(inc.machine_code || '').toLowerCase().includes(q);
          const matchSite = String(inc.location_site_code || inc.site_code || '').toLowerCase().includes(q);
          const matchLocation = String(inc.location_name || '').toLowerCase().includes(q);
          if (!matchCode && !matchMachine && !matchSite && !matchLocation) {
            return false;
          }
        }

        return true;
      });
    },
    metrics() {
      const active = this.incidents.filter(i => isActiveStatus(i.status));
      const criticalFood = active.filter(i => isCriticalUrgency(i.urgency) && i.machine_type === 'PERISHABLE_FOOD');
      const unassigned = active.filter(i => !i.assigned_technician_id);
      const pendingParts = active.filter(i => normalizeIncidentStatus(i.status) === 'PENDING_PARTS');

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
      this.loadTechnicians();
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
        this.loadTechnicians();
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

    /**
     * Starts the technical assignment flow for an unassigned site chosen on the
     * territorial triage map (RF-MAP-09): switches to the triage tab filtered by
     * the site so the coordinator can assign its incidents one by one.
     */
    handleTerritorialAssign(payload) {
      // Reached from a territorial-map card: open the bulk assignment modal directly
      // over that site's pending incidents (RF-MAP-09) instead of redirecting the
      // coordinator to a pre-filtered triage list.
      this.ensureTechniciansLoaded();
      const locationId = payload && payload.locationId !== undefined && payload.locationId !== null
        ? Number(payload.locationId)
        : null;
      const siteCode = payload && payload.siteCode ? String(payload.siteCode) : '';
      const pending = this.incidents.filter(inc => {
        const site = String(inc.location_site_code || inc.site_code || '').toUpperCase();
        const matchesSite = locationId !== null ? Number(inc.location_id) === locationId : (siteCode ? site === siteCode.toUpperCase() : false);
        // Active tickets only: unassigned ones start the flow, assigned ones on sites
        // worked by several technicians are included so the coordinator can consolidate
        // the whole building under a single active owner in one click (T-MAP-15).
        return matchesSite && !isTerminalStatus(inc.status);
      });
      this.bulkAssignSite = {
        locationId,
        siteCode,
        name: (pending[0] && pending[0].location_name) || siteCode
      };
      this.bulkAssignIncidents = pending;
      this.bulkAssignTechnicianId = this.technicians[0]?.id || null;
      this.bulkAssignUrgencyOverride = '';
      this.bulkAssignUrgencyReason = '';
      this.bulkAssignReassignmentReason = '';
      this.bulkAssignError = '';
      this.showBulkAssignModal = true;
    },

    closeBulkAssignModal() {
      this.showBulkAssignModal = false;
      this.bulkAssignSite = null;
      this.bulkAssignIncidents = [];
      this.bulkAssignReassignmentReason = '';
    },

    /**
     * Refreshes the territorial map in place when its tab is mounted (RF-MAP-09):
     * markers and site cards react immediately after a bulk assignment without the
     * manual "Actualizar" click, and the current zoom/pan view is preserved.
     */
    refreshTerritorialMap() {
      const mapTab = this.$refs && this.$refs.territorialMap;
      if (mapTab && typeof mapTab.loadTerritorialData === 'function') {
        return mapTab.loadTerritorialData(true);
      }
      return null;
    },

    /**
     * Incidents of the batch that already have an active owner: those are the ones the
     * backend treats as a reassignment and only accepts with a justified reason (RF-07.3).
     */
    bulkAssignReassignmentTargets(incidents = this.bulkAssignIncidents) {
      return (incidents || []).filter(incident => incident
        && incident.assigned_technician_id !== null
        && incident.assigned_technician_id !== undefined);
    },

    /** True when the confirmation carries a consolidated reassignment over the site. */
    hasAssignedIncidents(incidents = this.bulkAssignIncidents) {
      return this.bulkAssignReassignmentTargets(incidents).length > 0;
    },

    /** Real characters (Unicode code points) typed in the reassignment reason (RF-07.3). */
    bulkAssignReassignmentReasonLength() {
      return Array.from(String(this.bulkAssignReassignmentReason || '').trim()).length;
    },

    /**
     * Bulk assignment: one technician (+ optional audited urgency reclassification)
     * applied to every pending incident of the site chosen on the territorial map.
     * Incidents with an active owner are reassignments and therefore send the mandatory
     * justified reason (RF-07.3, one reason for the whole consolidated batch).
     * Failure-safe: the loop keeps assigning after a per-incident error and reports
     * a partial result instead of leaving the rest unassigned silently.
     */
    async submitBulkAssignment() {
      if (!this.bulkAssignIncidents.length) return;

      // Guard: no bulk assignment without a chosen active technician (EARS 5.5).
      if (!this.bulkAssignTechnicianId) {
        this.bulkAssignError = 'Selecciona un técnico de ruta activo antes de confirmar la asignación.';
        return;
      }

      // Validate: if urgency is reclassified, reason is mandatory (EARS 5.3)
      if (this.bulkAssignUrgencyOverride && !this.bulkAssignUrgencyReason.trim()) {
        this.bulkAssignError = 'Es obligatorio indicar el motivo justificado de la reclasificación de urgencia (EARS 5.3).';
        return;
      }

      // Validate the consolidated reassignment of the whole batch up front: incidents with
      // an active owner can only change hands with a justified reason of >= 10 real
      // characters, so a mixed confirmation must never fail incident by incident (RF-07.3).
      const reassignmentTargets = this.bulkAssignReassignmentTargets();
      const reassignmentReason = String(this.bulkAssignReassignmentReason || '').trim();
      if (reassignmentTargets.length > 0) {
        if (reassignmentReason === '') {
          this.bulkAssignError = `El lote incluye ${reassignmentTargets.length} incidencia(s) ya asignada(s): indica el motivo justificado de la reasignación antes de confirmar (RF-07.3).`;
          return;
        }
        if (this.bulkAssignReassignmentReasonLength() < 10) {
          this.bulkAssignError = 'El motivo de la reasignación debe contener al menos 10 caracteres.';
          return;
        }
      }

      this.bulkAssignError = '';
      this.isBulkAssigning = true;
      store.setLoading(true);

      const assigned = [];
      const failed = [];
      try {
        for (const incident of this.bulkAssignIncidents) {
          const isReassignment = reassignmentTargets.includes(incident);
          try {
            await api.coordinator.assignTechnician(
              incident.id,
              this.bulkAssignTechnicianId,
              this.bulkAssignUrgencyOverride || null,
              this.bulkAssignUrgencyReason.trim() || null,
              isReassignment ? reassignmentReason : null
            );
            assigned.push(incident.ticket_code);
          } catch (err) {
            failed.push({ ticket: incident.ticket_code, message: err.message || 'Error desconocido' });
          }
        }

        if (failed.length === 0) {
          store.addAlert(`Sede ${this.bulkAssignSite.siteCode}: ${assigned.length} incidencia(s) asignada(s) correctamente.`, 'success', 5000);
        } else if (assigned.length === 0) {
          this.bulkAssignError = failed.map(f => `#${f.ticket}: ${f.message}`).join(' · ');
        } else {
          store.addAlert(`Asignación parcial en ${this.bulkAssignSite.siteCode}: ${assigned.length} correcta(s), ${failed.length} con error.`, 'warning', 7000);
          this.bulkAssignError = failed.map(f => `#${f.ticket}: ${f.message}`).join(' · ');
        }

        this.$emit('bulk-assigned', { site: this.bulkAssignSite, assigned, failed });
        await this.loadIncidents();
        if (assigned.length > 0) {
          this.refreshTerritorialMap();
        }
        if (failed.length === 0) {
          this.closeBulkAssignModal();
        } else {
          // Keep the modal open listing what failed; the remaining (already-assigned)
          // incidents are removed from the pending list after the reload.
          this.bulkAssignIncidents = this.incidents.filter(inc => failed.some(f => f.ticket === inc.ticket_code));
        }
      } finally {
        this.isBulkAssigning = false;
        store.setLoading(false);
      }
    },

    // --- Per-Row Quick Action Gating (EARS 5.5 / EARS 6.4) ---

    /**
     * Quick "Asignar" action is only offered for statuses that accept an initial
     * assignment (EARS 5.5). Delegates to the shared module
     * utils/IncidentStatusPermissions.js (frontend mirror of the PHP state-machine
     * guard): reassignment stays exclusive to the integral detail modal (RF-07.3).
     */
    canQuickAssign(incident) {
      return canQuickAssign(incident);
    },

    /**
     * Quick "Descartar" action is only offered for active tickets (EARS 6.4).
     * Delegates to the shared module; unknown or empty statuses fail safe by hiding
     * the destructive action.
     */
    canQuickCancel(incident) {
      return canQuickCancel(incident);
    },

    // --- Modal Triggers ---

    /**
     * Loads the real active route technicians from the users endpoint, mirroring the
     * integral detail modal (single source of truth, no hardcoded seed list):
     * `GET /api/coordinator/users?status=active&role=TECHNICIAN` (EARS 3.9 keeps
     * deactivated staff out of triage selection).
     */
    async loadTechnicians() {
      if (this.isLoadingTechnicians) return;
      this.isLoadingTechnicians = true;
      this.techniciansErrorMessage = '';
      try {
        const response = await api.coordinator.getUsers({ status: 'active', role: 'TECHNICIAN' });
        const list = Array.isArray(response) ? response : (Array.isArray(response?.data) ? response.data : []);
        this.technicians = list;
        this.techniciansLoaded = true;
      } catch (err) {
        this.techniciansErrorMessage = err?.message || 'No se pudieron cargar los técnicos activos.';
      } finally {
        this.isLoadingTechnicians = false;
      }
    },

    /**
     * Refreshes the technician roster right before the assignment modal opens: any
     * personnel change (new hires, logical deletions) is reflected without waiting
     * for a page reload. A previous failed load is retried transparently.
     */
    ensureTechniciansLoaded() {
      if (!this.techniciansLoaded || this.techniciansErrorMessage) {
        this.loadTechnicians();
      }
    },

    openAssignModal(incident) {
      this.selectedIncident = incident;
      this.assignTechnicianId = incident.assigned_technician_id || (this.technicians[0]?.id || null);
      this.assignUrgencyOverride = '';
      this.assignUrgencyReason = '';
      this.assignError = '';
      this.ensureTechniciansLoaded();
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

    /**
     * Selecciona la incidencia y solicita la apertura de la ficha de detalle integral
     * (RF-01.1, T-IDM-14). El modal de detalle se monta sobre este estado (T-IDM-15) y
     * permanece independiente de los modales de asignación y descarte de la fila (RF-01.2).
     */
    openDetailModal(incident) {
      this.selectedDetailIncident = incident;
      this.showDetailModal = true;
    },

    /**
     * Contador total de mensajes de una avería para la insignia de conversación de la
     * fila de triaje (RF-01.1). El coordinador contabiliza públicos y notas internas de
     * taller, porque su canal tiene acceso legítimo a ambos (RF-02.3).
     *
     * @param {Object} incident Fila de la bandeja de triaje.
     * @returns {number} Entero no negativo; 0 si el expediente aún no tiene hilo.
     */
    commentCountOf(incident) {
      const parsed = Number.parseInt(incident?.comments_count, 10);
      return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    },

    /**
     * Abre el hilo de conversación de una avería con el canal 'COORDINATOR' (RF-01.2):
     * admite tanto la insignia de la fila de triaje como el disparador de la ficha de
     * detalle, que reutiliza la fila ya seleccionada en la bandeja.
     *
     * @param {Object|null} incident Fila de triaje o expediente mínimo con `id`.
     */
    openCommentsModal(incident) {
      const target = incident || this.selectedDetailIncident;
      if (!target || (target.id === undefined && target.ticket_code === undefined)) {
        return;
      }

      // Si el disparador viene de una fila de la bandeja, se rehidrata la fila real para
      // que el contador sincronizado apunte al mismo objeto reactivo de la tabla.
      const knownRow = this.incidents.find(inc => Number(inc.id) === Number(target.id));
      this.selectedCommentIncident = knownRow || target;
      this.showCommentsModal = true;
    },

    /**
     * Disparador del hilo desde la ficha de detalle integral (RF-01.1): el modal de
     * detalle sólo conoce su expediente, así que se abre el hilo sobre la fila que la
     * bandeja tiene seleccionada.
     */
    onDetailOpenComments() {
      this.openCommentsModal(this.selectedDetailIncident);
    },

    /**
     * Cierra el hilo de conversación y libera la selección.
     *
     * El modal del hilo libera el bloqueo de scroll del fondo al cerrarse, pero la ficha
     * de detalle puede seguir abierta debajo: se restablece el bloqueo para que el cuerpo
     * no se desplace por detrás de un modal aún visible (RNF-06).
     */
    closeCommentsModal() {
      this.showCommentsModal = false;
      this.selectedCommentIncident = null;

      if (this.showDetailModal && typeof document !== 'undefined') {
        // El modal del hilo libera el bloqueo de scroll en su propio watcher de cierre,
        // que se ejecuta DESPUÉS de este manejador: con la ficha de detalle todavía
        // abierta hay que reimponer el bloqueo una vez drenado ese ciclo.
        const relockBackground = () => {
          document.body.style.overflow = 'hidden';
        };

        if (typeof this.$nextTick === 'function') {
          this.$nextTick(relockBackground);
        } else {
          relockBackground();
        }
      }
    },

    /**
     * Sincroniza los contadores tras publicar un mensaje en el hilo (RF-03.4):
     * (1) la insignia de la fila de triaje adopta el total exacto devuelto por el
     * servidor (o incrementa una unidad si no llegara), y (2) si la ficha de detalle
     * sigue abierta debajo, se recarga para que su bitácora y su disparador muestren el
     * mensaje recién publicado.
     *
     * @param {Object|null} threadDto DTO del hilo emitido por el modal.
     */
    async onCommentAdded(threadDto) {
      const incidentId = this.selectedCommentIncident?.id;

      if (incidentId !== undefined && incidentId !== null) {
        const row = this.incidents.find(inc => Number(inc.id) === Number(incidentId));
        if (row) {
          const reportedTotal = Number.parseInt(threadDto?.pagination?.total_comments, 10);
          row.comments_count = Number.isFinite(reportedTotal)
            ? reportedTotal
            : this.commentCountOf(row) + 1;
        }
      }

      if (this.showDetailModal) {
        const detailModal = this.$refs?.detailModalRef;
        if (detailModal && typeof detailModal.fetchDetail === 'function') {
          await detailModal.fetchDetail();
        }
      }
    },

    /**
     * Cierra la ficha de detalle integral y libera la selección (T-IDM-15). El modal ya
     * reinició sus borradores antes de emitir `close` (T-IDM-13), de modo que aquí solo
     * queda desmontar el estado de la bandeja sin residuos.
     */
    closeDetailModal() {
      this.showDetailModal = false;
      this.selectedDetailIncident = null;
    },

    /**
     * Abre la ficha integral de la avería correctiva vinculada a una orden preventiva
     * (RF-PD-07.2): reutiliza el modal de detalle de incidencias ya montado en esta vista,
     * sin duplicar la ficha correctiva. La orden solo conoce el ID del ticket, así que se
     * rehidrata la fila desde la bandeja cargada cuando está presente y, si no lo está
     * (por filtros o paginación), el modal acepta igualmente el identificador directo.
     */
    openIncidentDetailFromPreventive(incidentId) {
      if (incidentId === null || incidentId === undefined || incidentId === '') {
        return;
      }

      const knownIncident = this.incidents.find(
        (incident) => Number(incident.id) === Number(incidentId)
      );

      this.selectedDetailIncident = knownIncident || { id: Number(incidentId) };
      this.showDetailModal = true;
    },

    /**
     * Refresca en caliente la fila de la bandeja correspondiente a la incidencia abierta
     * en el modal de detalle (T-IDM-15, RF-07.3/RF-07.4): vuelve a pedir el listado al
     * servidor y sustituye únicamente la fila afectada mediante splice reactivo, sin
     * recargar la página completa ni disparar el spinner de carga. El payload del modal
     * ({ reason: 'assign' | 'cancel' | 'comment' }) no hace falta para localizar la fila:
     * la bandeja ya conoce la incidencia abierta en `selectedDetailIncident`.
     */
    async handleIncidentUpdated() {
      const incidentId = this.selectedDetailIncident?.id;
      if (incidentId === undefined || incidentId === null) {
        await this.loadIncidents(true);
        return;
      }
      await this.refreshIncidentRow(incidentId);
    },

    /**
     * Refreshes a single triage row in place with the server truth (T-IDM-15,
     * EARS 5.6/6.5): re-requests the listing and substitutes only the affected row via
     * reactive splice, without a full page reload or the loading spinner. Shared by the
     * detail-modal `incident-updated` handler and by the stale-state defense of the row
     * quick actions (a backend rejection re-syncs the row while the panel keeps showing
     * the rejection reason).
     */
    async refreshIncidentRow(incidentId) {
      try {
        const data = await api.coordinator.getIncidents();
        const freshRows = Array.isArray(data) ? data : [];
        const rowIndex = this.incidents.findIndex(inc => Number(inc.id) === Number(incidentId));
        const freshRow = freshRows.find(inc => Number(inc.id) === Number(incidentId));

        if (rowIndex !== -1 && freshRow) {
          // Sustitución en sitio: Vue 3 repinta únicamente la fila afectada.
          this.incidents.splice(rowIndex, 1, freshRow);
          this.lastUpdated = new Date();
        } else {
          // El ticket salió del alcance del listado del coordinador: sincroniza el
          // conjunto en silencio, siempre sin recargar la página completa.
          await this.loadIncidents(true);
        }
      } catch (refreshError) {
        // Fallo de red en el refresco de fondo: la tabla conserva sus datos actuales y el
        // panel ya informó del resultado real de la acción; el sondeo de 60 s seguirá
        // sincronizando la bandeja. Nunca se recarga la página.
      }
    },

    // --- Actions ---

    /**
     * Assigns technician with optional urgency override (RF-05 / EARS 5.1, 5.3)
     */
    async submitAssignment() {
      if (!this.selectedIncident) return;

      // Guard: no assignment is sent without a chosen active technician (EARS 5.5,
      // API contract requires technician_id of an active route technician).
      if (!this.assignTechnicianId) {
        this.assignError = 'Selecciona un técnico de ruta activo antes de confirmar la asignación.';
        return;
      }

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
        // Stale-row defense (EARS 5.6): the backend rejected the operation because the
        // real state no longer allows it. Re-sync the affected row with the server truth
        // while the panel stays open showing the rejection reason.
        if (this.selectedIncident?.id !== undefined && this.selectedIncident?.id !== null) {
          await this.refreshIncidentRow(this.selectedIncident.id);
        }
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
        // Stale-row defense (EARS 6.5): the backend rejected the discard because the
        // real state no longer allows it. Re-sync the affected row with the server truth
        // while the panel stays open showing the rejection reason.
        if (this.selectedIncident?.id !== undefined && this.selectedIncident?.id !== null) {
          await this.refreshIncidentRow(this.selectedIncident.id);
        }
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
            :class="activeTab === 'mapa-territorial' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 38px; font-size: 14px; font-weight: 600; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px;"
            @click="activeTab = 'mapa-territorial'"
            data-testid="tab-mapa-territorial"
          >
            🗺️ Mapa Territorial
          </button>

          <button
            type="button"
            class="vg-btn"
            :class="activeTab === 'preventive' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 38px; font-size: 14px; font-weight: 600; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px;"
            @click="activeTab = 'preventive'"
            data-testid="tab-preventive"
          >
            🛡️ Mantenimiento Preventivo
          </button>

          <button
            type="button"
            class="vg-btn"
            :class="activeTab === 'repuestos' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 38px; font-size: 14px; font-weight: 600; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px;"
            @click="activeTab = 'repuestos'"
            data-testid="tab-repuestos"
          >
            📦 Repuestos
          </button>

          <button
            type="button"
            class="vg-btn"
            :class="activeTab === 'analitica-repuestos' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 38px; font-size: 14px; font-weight: 600; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px;"
            @click="activeTab = 'analitica-repuestos'"
            data-testid="tab-analitica-repuestos"
          >
            📈 Analítica Repuestos
          </button>

          <button
            type="button"
            class="vg-btn"
            :class="activeTab === 'refunds' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 38px; font-size: 14px; font-weight: 600; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px;"
            @click="activeTab = 'refunds'"
            data-testid="tab-refunds"
          >
            💶 Reintegros
          </button>

          <button
            type="button"
            class="vg-btn"
            :class="activeTab === 'admin' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 38px; font-size: 14px; font-weight: 600; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px;"
            @click="activeTab = 'admin'"
            data-testid="tab-admin"
          >
            🏢 Administración
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
            <select v-model="filterStatus" class="vg-select" style="max-width: 170px; height: 36px;" data-testid="select-status-filter">
              <option value="">Todos los estados</option>
              <option value="REGISTERED">Registrada</option>
              <option value="ASSIGNED">Asignada</option>
              <option value="IN_PROGRESS">En curso</option>
              <option value="PENDING_PARTS">Pend. Repuesto</option>
              <option value="RESOLVED">Resuelta</option>
              <option value="REOPENED">Reabierta</option>
              <option value="CLOSED">Cerrada</option>
              <option value="CANCELLED">Cancelada</option>
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
                      {{ inc.location_name || inc.location_site_code || inc.site_code }}
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

                      <!-- Conversation Thread Badge with the total message count (RF-01.1) -->
                      <button
                        type="button"
                        class="vg-btn vg-btn-secondary vg-row-comments-badge"
                        style="height: 30px; font-size: 12px; padding: 0 8px; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 4px;"
                        @click.stop="openCommentsModal(inc)"
                        :title="'Abrir el hilo de conversación del expediente (' + commentCountOf(inc) + ' mensajes)'"
                        data-testid="btn-row-comments"
                      >
                        💬<span data-testid="row-comments-count">{{ commentCountOf(inc) }}</span>
                      </button>

                      <!-- View Detail Button (RF-01.1 / T-IDM-14) -->
                      <button
                        type="button"
                        class="vg-btn vg-btn-secondary"
                        style="height: 30px; font-size: 12px; padding: 0 8px; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 4px;"
                        @click.stop="openDetailModal(inc)"
                        title="Ver el detalle integral de la incidencia"
                        data-testid="btn-view-detail"
                      >
                        🔍 Ver detalle
                      </button>

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

                      <!-- Assign Button: visible only in assignable states (EARS 5.5) -->
                      <button
                        v-if="canQuickAssign(inc)"
                        type="button"
                        class="vg-btn vg-btn-primary"
                        style="height: 30px; font-size: 12px; padding: 0 10px; border-radius: var(--radius-interactive, 4px);"
                        @click="openAssignModal(inc)"
                        title="Asignar o reclasificar técnico"
                      >
                        Asignar
                      </button>

                      <!-- Cancel / Discard Button: visible only in active states (EARS 6.4) -->
                      <button
                        v-if="canQuickCancel(inc)"
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

      <!-- CONTENIDO PESTAÑA: MANTENIMIENTO PREVENTIVO Y SANITARIO (RF-PREV-02, RF-PREV-06) -->
      <div v-else-if="activeTab === 'preventive'" class="vg-preventive-management-container" data-testid="preventive-panel-container">
        <!-- Sub-barra de navegación con subpestañas operativas -->
        <div class="card mb-4 shadow-sm" style="border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); background-color: #ffffff; padding: 10px 16px; margin-bottom: 20px;">
          <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 12px;">
              <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Módulo Preventivo:</span>
              <div style="display: flex; gap: 6px;">
                <button
                  type="button"
                  class="vg-btn"
                  :class="activePreventiveSubTab === 'dashboard' ? 'vg-btn-primary' : 'vg-btn-secondary'"
                  style="height: 34px; font-size: 13px; font-weight: 600; padding: 0 14px; border-radius: var(--radius-interactive, 4px);"
                  @click="activePreventiveSubTab = 'dashboard'"
                  data-testid="subtab-preventive-dashboard"
                >
                  📊 Semáforos e Indicadores
                </button>
                <button
                  type="button"
                  class="vg-btn"
                  :class="activePreventiveSubTab === 'orders' ? 'vg-btn-primary' : 'vg-btn-secondary'"
                  style="height: 34px; font-size: 13px; font-weight: 600; padding: 0 14px; border-radius: var(--radius-interactive, 4px);"
                  @click="activePreventiveSubTab = 'orders'"
                  data-testid="subtab-preventive-orders"
                >
                  📋 Listado de Órdenes
                </button>
              </div>
            </div>
            <div style="font-size: 12px; color: #64748b;">
              <span>🛡️ M1: Periodicidad higiénico-sanitaria y salvaguarda alimentaria (Art. II)</span>
            </div>
          </div>
        </div>

        <!-- Subpestaña 1: Semáforos y Panel Preventivo (RF-PREV-06) -->
        <CoordinatorPreventiveDashboard
          v-if="activePreventiveSubTab === 'dashboard'"
          @view-orders="activePreventiveSubTab = 'orders'"
          @open-settings="showPreventiveSettingsModal = true"
          @refresh="loadIncidents(true)"
        />

        <!-- Subpestaña 2: Listado y Asignación de Órdenes (RF-PREV-02) -->
        <CoordinatorPreventiveOrdersTab
          v-else-if="activePreventiveSubTab === 'orders'"
          @order-assigned="loadIncidents(true)"
          @order-cancelled="loadIncidents(true)"
          @open-incident-detail="openIncidentDetailFromPreventive"
        />
      </div>

      <!-- CONTENIDO PESTAÑA 3: ADMINISTRACIÓN INTEGRAL (RF-01, RF-02, RF-03, RF-05) -->
      <div v-else-if="activeTab === 'admin'" class="vg-admin-management-container" data-testid="admin-panel-container">
        <!-- Sub-barra de navegación con subpestañas operativas (EARS 5.1) -->
        <div class="card mb-4 shadow-sm" style="border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); background-color: #ffffff;">
          <div class="card-body py-2 px-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
              <span class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em; font-size: 12px;">Submódulos:</span>
              <div class="btn-group" role="group" aria-label="Submódulos de Administración" style="box-shadow: 0 1px 2px rgba(0,0,0,0.04);">
                <button
                  type="button"
                  class="btn"
                  :class="activeAdminSubTab === 'locations' ? 'btn-primary' : 'btn-outline-primary'"
                  style="height: 34px; font-size: 13px; font-weight: 600; padding: 0 14px;"
                  @click="activeAdminSubTab = 'locations'"
                  data-testid="subtab-locations"
                >
                  🏢 Sedes
                </button>
                <button
                  type="button"
                  class="btn"
                  :class="activeAdminSubTab === 'machines' ? 'btn-primary' : 'btn-outline-primary'"
                  style="height: 34px; font-size: 13px; font-weight: 600; padding: 0 14px;"
                  @click="activeAdminSubTab = 'machines'"
                  data-testid="subtab-machines"
                >
                  🎰 Parque de Máquinas
                </button>
                <button
                  type="button"
                  class="btn"
                  :class="activeAdminSubTab === 'users' ? 'btn-primary' : 'btn-outline-primary'"
                  style="height: 34px; font-size: 13px; font-weight: 600; padding: 0 14px;"
                  @click="activeAdminSubTab = 'users'"
                  data-testid="subtab-users"
                >
                  🧑‍🔧 Personal Interno
                </button>
              </div>
            </div>
            <div class="text-muted small" style="font-size: 12px;">
              <span>🛡️ Gestión centralizada con trazabilidad inmutable (Art. III)</span>
            </div>
          </div>
        </div>

        <!-- Subpestaña 1: Sedes Clientes (RF-01) -->
        <AdminLocationsTab v-if="activeAdminSubTab === 'locations'" />

        <!-- Subpestaña 2: Catálogo de Máquinas (RF-02) -->
        <AdminMachinesTab v-else-if="activeAdminSubTab === 'machines'" />

        <!-- Subpestaña 3: Directorio de Personal (RF-03) -->
        <AdminUsersTab v-else-if="activeAdminSubTab === 'users'" />
      </div>

      <!-- CONTENIDO PESTAÑA: CATÁLOGO Y GESTIÓN DE REPUESTOS (RF-REP-01, RF-REP-08 / T-SPARE-14, T-SPARE-17) -->
      <CoordinatorSparePartsTab
        v-else-if="activeTab === 'repuestos'"
        @open-analytics="activeTab = 'analitica-repuestos'"
      />

      <!-- CONTENIDO PESTAÑA: ANALÍTICA Y PREDICCIÓN DE REPUESTOS (RF-REP-09 / T-SPARE-15, T-SPARE-17) -->
      <CoordinatorSparePartsAnalyticsTab
        v-else-if="activeTab === 'analitica-repuestos'"
        @open-catalog="activeTab = 'repuestos'"
      />

      <!-- CONTENIDO PESTAÑA: REINTEGROS Y LIQUIDACIÓN DIGITAL (RF-REF-03, RF-REF-07, RF-REF-08 / T-REF-18) -->
      <CoordinatorRefundsTab
        v-else-if="activeTab === 'refunds'"
      />

      <!-- CONTENIDO PESTAÑA: MAPA TERRITORIAL DE TRIAJE (RF-MAP-09 / T-MAP-16) -->
      <CoordinatorTerritorialMapTab
        v-else-if="activeTab === 'mapa-territorial'"
        ref="territorialMap"
        :current-user="currentUser"
        @assign-incidents="handleTerritorialAssign"
      />

      <!-- CONTENIDO PESTAÑA 4: MÉTRICAS Y AUDITORÍA (RF-01, RF-02, RF-03, RF-05, RF-06) -->
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
              <option v-if="isLoadingTechnicians" value="" disabled>Cargando técnicos activos...</option>
              <option v-else-if="technicians.length === 0" value="" disabled>
                {{ techniciansErrorMessage || 'No hay técnicos de ruta activos disponibles.' }}
              </option>
              <option v-for="t in technicians" :key="t.id" :value="t.id">
                {{ t.name }} ({{ t.email }})
              </option>
            </select>
            <p v-if="techniciansErrorMessage && technicians.length > 0" style="margin: 6px 0 0; font-size: 12px; color: #b91c1c;" role="alert">
              {{ techniciansErrorMessage }}
            </p>
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
      <!-- MODAL 1B: BULK SITE ASSIGNMENT (reached from the territorial map,  -->
      <!-- RF-MAP-09): one technician + optional audited urgency applied to   -->
      <!-- every pending incident of the chosen site.                         -->
      <!-- =================================================================== -->
      <ModalDialog
        v-model="showBulkAssignModal"
        title="Asignar Técnico a la Sede"
        :subtitle="bulkAssignSite ? (bulkAssignSite.name + ' · ' + bulkAssignIncidents.length + ' incidencia(s) pendiente(s)') : ''"
        size="md"
        @close="closeBulkAssignModal"
      >
        <form v-if="bulkAssignSite" @submit.prevent="submitBulkAssignment" data-testid="bulk-assign-form">
          <!-- Pending incidents summary -->
          <div style="background-color: #fafbfc; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); padding: 12px 14px; margin-bottom: 16px;">
            <div style="font-size: 12px; font-weight: 700; color: var(--color-slate, #2c333f); text-transform: uppercase; margin-bottom: 8px;">
              Incidencias activas de {{ bulkAssignSite.siteCode }}
            </div>
            <ul style="margin: 0; padding-left: 18px; font-size: 13px; color: var(--color-ink, #000000);">
              <li v-for="inc in bulkAssignIncidents" :key="inc.id" style="margin-bottom: 4px;">
                <strong>#{{ inc.ticket_code }}</strong> · {{ inc.machine_code }} · {{ inc.category_label || inc.category }}
                <IncidentBadge :value="inc.urgency" type="urgency" size="sm" />
              </li>
            </ul>
          </div>

          <!-- Technician Selector -->
          <div style="margin-bottom: 16px;">
            <label for="bulk-assign-tech-select" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 6px;">
              Técnico de Campo responsable <span style="color: #dc2626;">*</span>
            </label>
            <select
              id="bulk-assign-tech-select"
              v-model="bulkAssignTechnicianId"
              class="vg-select"
              required
              :disabled="isBulkAssigning"
              data-testid="bulk-assign-tech-select"
            >
              <option v-if="isLoadingTechnicians" value="" disabled>Cargando técnicos activos...</option>
              <option v-else-if="technicians.length === 0" value="" disabled>
                {{ techniciansErrorMessage || 'No hay técnicos de ruta activos disponibles.' }}
              </option>
              <option v-for="t in technicians" :key="t.id" :value="t.id">
                {{ t.name }} ({{ t.email }})
              </option>
            </select>
            <div style="font-size: 12px; color: var(--color-ink-muted, #6c7e9d); margin-top: 4px;">
              El mismo técnico quedará como único responsable activo de todas las incidencias listadas (Art. II: un responsable activo por incidencia).
            </div>
          </div>

          <!-- Reassignment Reason (mandatory as soon as the batch carries assigned incidents, RF-07.3) -->
          <div v-if="hasAssignedIncidents()" style="background-color: #fafbfc; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); padding: 14px; margin-bottom: 16px;">
            <label for="bulk-assign-reassignment-reason" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 6px;">
              Motivo de la reasignación <span style="color: #dc2626;">* (Obligatorio para las incidencias ya asignadas)</span>
            </label>
            <textarea
              id="bulk-assign-reassignment-reason"
              v-model="bulkAssignReassignmentReason"
              class="vg-textarea"
              rows="2"
              placeholder="Ej: Consolidación de la sede en un único técnico para resolver las averías en una sola visita..."
              required
              :disabled="isBulkAssigning"
              data-testid="bulk-assign-reassignment-reason"
            ></textarea>
            <div style="font-size: 12px; color: var(--color-ink-muted, #6c7e9d); margin-top: 4px;">
              El cambio de responsable exige al menos 10 caracteres reales y queda registrado en el historial inmutable de cada incidencia (RF-07.3).
            </div>
          </div>

          <!-- Urgency Reclassification (Optional / Audit trail required) -->
          <div style="background-color: #fafbfc; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); padding: 14px; margin-bottom: 16px;">
            <label for="bulk-assign-urgency" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 6px;">
              Reclasificar urgencia del lote <span style="font-size: 11px; color: var(--color-ink-muted, #6c7e9d);">(Opcional, afecta a todas)</span>
            </label>
            <select id="bulk-assign-urgency" v-model="bulkAssignUrgencyOverride" class="vg-select" :disabled="isBulkAssigning" data-testid="bulk-assign-urgency">
              <option value="">Mantener la urgencia actual de cada incidencia</option>
              <option value="CRITICAL">Cambiar todo a CRÍTICA</option>
              <option value="HIGH">Cambiar todo a ALTA</option>
              <option value="MEDIUM">Cambiar todo a MEDIA</option>
              <option value="LOW">Cambiar todo a BAJA</option>
            </select>

            <!-- Mandatory Justification if override is set (EARS 5.3) -->
            <div v-if="bulkAssignUrgencyOverride" style="margin-top: 10px;">
              <label for="bulk-assign-urgency-reason" style="display: block; font-size: 12px; font-weight: 600; color: #dc2626; margin-bottom: 4px;">
                Motivo justificado del cambio <span style="color: #dc2626;">* (Obligatorio en auditoría)</span>
              </label>
              <textarea
                id="bulk-assign-urgency-reason"
                v-model="bulkAssignUrgencyReason"
                class="vg-textarea"
                rows="2"
                placeholder="Ej: Corte de refrigeración general de la sede, todas las máquinas de frío se degradan a CRÍTICA..."
                required
                :disabled="isBulkAssigning"
              ></textarea>
            </div>
          </div>

          <!-- Error Alert -->
          <div
            v-if="bulkAssignError"
            style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 10px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 16px;"
            role="alert"
            data-testid="bulk-assign-error"
          >
            {{ bulkAssignError }}
          </div>

          <!-- Actions -->
          <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid var(--color-hairline, #c8cfda); padding-top: 16px;">
            <button type="button" class="vg-btn vg-btn-secondary" @click="closeBulkAssignModal" :disabled="isBulkAssigning">
              Cancelar
            </button>
            <button type="submit" class="vg-btn vg-btn-primary" :disabled="isBulkAssigning || !bulkAssignTechnicianId || bulkAssignIncidents.length === 0" data-testid="bulk-assign-submit">
              <span v-if="!isBulkAssigning">Asignar {{ bulkAssignIncidents.length }} incidencia(s)</span>
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

      <!-- =================================================================== -->
      <!-- MODAL 4: PREVENTIVE SETTINGS & SEASONAL PAUSE (RF-PREV-01 / T-PREV-19) -->
      <!-- =================================================================== -->
      <CoordinatorPreventiveSettingsModal
        v-model="showPreventiveSettingsModal"
        @settings-updated="loadIncidents(true)"
        @pause-updated="loadIncidents(true)"
      />

      <!-- =================================================================== -->
      <!-- MODAL 5: INTEGRAL INCIDENT DETAIL (RF-01, RF-07, RF-08 / T-IDM-15)  -->
      <!-- =================================================================== -->
      <CoordinatorIncidentDetailModal
        ref="detailModalRef"
        :is-open="showDetailModal"
        :incident-id="detailIncidentId"
        :comment-thread-open="showCommentsModal"
        @close="closeDetailModal"
        @incident-updated="handleIncidentUpdated"
        @open-comments="onDetailOpenComments"
      />

      <!-- =================================================================== -->
      <!-- MODAL 6: HILO DE CONVERSACIÓN (RF-01.1, RF-02.3 / T-COM-16)          -->
      <!-- Se monta DESPUÉS de la ficha de detalle para que su capa quede por    -->
      <!-- encima cuando el disparador parte del propio detalle.                 -->
      <!-- =================================================================== -->
      <IncidentCommentThreadModal
        :is-open="showCommentsModal"
        :incident-id="selectedCommentIncident?.id ?? selectedCommentIncident?.ticket_code ?? null"
        :ticket-code="selectedCommentIncident?.ticket_code ?? null"
        role="COORDINATOR"
        @close="closeCommentsModal"
        @comment-added="onCommentAdded"
      />
    </div>
  `
};

export default CoordinatorDashboardView;
