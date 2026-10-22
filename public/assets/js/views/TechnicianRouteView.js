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
 * 8. Native link to the printable A4 spare parts quick guide (public/docs asset, RF-REP-03, RF-REP-04).
 * 9. Paused stop (Módulo 11): "En espera de sede" banner, in-situ resume, and the
 *    RF-05.6 notice when the site already answered during the pause.
 */

import { api } from '../api.js';
import { store } from '../store.js';
import { IncidentBadge } from '../components/IncidentBadge.js';
import { ModalDialog } from '../components/ModalDialog.js';
import {
  INCIDENT_STATUSES,
  URGENCY_LEVELS,
  URGENCY_RANKS,
  isCriticalUrgency,
  isPendingInfoStatus,
  normalizeIncidentStatus,
  normalizeUrgency,
  AMBER_TECHNICAL_TOKENS
} from '../utils/IncidentStatusPermissions.js';
import { TechnicianMetricsView } from './TechnicianMetricsView.js';
import { TechnicianPreventiveRouteTab } from '../components/TechnicianPreventiveRouteTab.js';
import { TechnicianChecklistModal } from '../components/TechnicianChecklistModal.js';
import { TechnicianReinspectionModal } from '../components/TechnicianReinspectionModal.js';
import { TechnicianSparePartsPauseModal } from '../components/TechnicianSparePartsPauseModal.js';
import { TechnicianResolutionPartsBlock } from '../components/TechnicianResolutionPartsBlock.js';
import { TechnicianResolutionRefundBlock } from '../components/TechnicianResolutionRefundBlock.js';
import { TechnicianSanitaryResolutionBlock } from '../components/TechnicianSanitaryResolutionBlock.js';
import { TechnicianServiceSheetBlock } from '../components/TechnicianServiceSheetBlock.js';
import { ServiceSheetModal } from '../components/ServiceSheetModal.js';
import { TechnicianRouteMapModal } from '../components/TechnicianRouteMapModal.js';
import { TechnicianMachineHistoryModal } from '../components/TechnicianMachineHistoryModal.js';
import { IncidentCommentThreadModal } from '../components/IncidentCommentThreadModal.js';
import { PendingInfoPauseModal, PAUSE_REASON_CATEGORIES } from '../components/PendingInfoPauseModal.js';
import { TechnicianOpportunisticModal } from '../components/TechnicianOpportunisticModal.js';
import { TechnicianVanStockPanel } from '../components/TechnicianVanStockPanel.js';
import {
  graceRemainingMinutes,
  graceWindowLocations,
  groupColocatedStops,
  normalizeRouteEnvelope,
  opportunisticModeLabel as resolveOpportunisticModeLabel,
  summarizePendingCriticalStops
} from '../utils/OpportunisticContext.js';

export const TechnicianRouteView = {
  name: 'TechnicianRouteView',
  props: {
    /**
     * Ticket citado por un enlace entrante (Módulo 15, RF-TG-08): el aviso de
     * Telegram abre `/?tech=1&ticket=INC-…` y la vista resalta esa parada en
     * cuanto la ruta está cargada. Opcional: entrar sin enlace es lo normal.
     */
    initialTicketCode: { type: String, default: '' }
  },
  components: {
    IncidentBadge,
    ModalDialog,
    TechnicianMetricsView,
    TechnicianPreventiveRouteTab,
    TechnicianChecklistModal,
    TechnicianReinspectionModal,
    TechnicianSparePartsPauseModal,
    TechnicianResolutionPartsBlock,
    TechnicianResolutionRefundBlock,
    TechnicianSanitaryResolutionBlock,
    TechnicianServiceSheetBlock,
    ServiceSheetModal,
    TechnicianRouteMapModal,
    TechnicianMachineHistoryModal,
    IncidentCommentThreadModal,
    PendingInfoPauseModal,
    TechnicianOpportunisticModal,
    TechnicianVanStockPanel
  },
  data() {
    return {
      activeSection: 'route', // 'route' | 'preventive' | 'metrics'
      showPreventiveChecklistModal: false,
      showPreventiveReinspectionModal: false,
      selectedPreventiveOrder: null,
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

      // Enlace entrante del aviso de asignación (Módulo 15, RF-TG-08): se aplica
      // una sola vez, cuando la ruta ya está cargada, y se marca como consumido
      // para que un refresco no vuelva a desplazar la pantalla del técnico.
      deepLinkHandled: false,
      deepLinkNotice: '',
      highlightedIncidentId: null,

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
      resolvePartsData: { replaced_parts_declared: false, replaced_parts: [] },
      resolveRefundData: { isValid: true },
      resolveSanitaryData: { isValid: false },
      // Conformidad del responsable de sede (Módulo 19, RF-01, RF-02): bloque obligatorio
      // del cierre correctivo. `null` mientras ninguna de las dos vías esté completa.
      resolveServiceSheetData: { isValid: false },
      showServiceSheetModal: false,
      serviceSheetIncidentId: null,
      // Declaraciones sanitarias del cierre (RF-03.5.2, Art. II): la puerta se abre
      // cuando la ruta publica `sanitary_resolution_required` o cuando el servidor
      // la exige al intentar cerrar (el reloj biológico pudo vencer entre medias).
      sanitaryResolutionRequired: false,
      refundRequests: [],
      hasPendingRefundVerdict: false,
      isLoadingRefundInspection: false,
      refundInspectionFailed: false,
      refundInspectionRequestId: 0,
      isResolving: false,
      resolveError: '',

      // Interactive route map (RF-MAP-07, RF-MAP-08)
      showRouteMapModal: false,

      // Machine incident history modal (RF-07 / EARS H.1-H.6, specs/technical/technician_machine_history_contracts.md)
      showMachineHistoryModal: false,
      historyMachine: null,

      // Modal 5: Hilo de conversación de la parada (Módulo 10, RF-01.1, RF-02.3)
      showCommentsModal: false,
      selectedCommentIncident: null,

      // Modal 6: Pausa por bloqueo de sede / falta de acceso (Módulo 11, T-PAUSE-18, RF-01.1)
      showPendingInfoPauseModal: false,
      pendingInfoIncident: null,
      isResumingPendingInfo: false,
      pendingInfoResumeError: '',

      // Módulo 16: sedes habilitadas, aviso sanitario y modal de intervención in situ.
      // El contexto viaja en la misma petición de ruta (`include_opportunistic_context=1`)
      // para no duplicar viajes al motor desde el móvil del técnico (RNF-02).
      enabledLocations: [],
      pendingCriticalStops: [],
      showOpportunisticModal: false,
      opportunisticLocation: null
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
      let pendingInfo = 0;
      let critical = 0;

      for (const inc of this.incidents) {
        const status = normalizeIncidentStatus(inc.status);
        if (status === INCIDENT_STATUSES.ASSIGNED) assigned++;
        if (status === INCIDENT_STATUSES.IN_PROGRESS) inProgress++;
        if (status === INCIDENT_STATUSES.PENDING_PARTS) pendingParts++;
        // Paradas pausadas por bloqueo de sede (Módulo 11, RF-05.5): siguen en la
        // ruta del día, pero contabilizadas aparte de las intervenciones activas.
        if (status === INCIDENT_STATUSES.PENDING_INFO) pendingInfo++;
        if (isCriticalUrgency(inc.urgency)) critical++;
      }

      return { total, assigned, inProgress, pendingParts, pendingInfo, critical };
    },

    /**
     * Tokens ámbar técnicos (RNF-04) ya declarados en el módulo compartido, de modo
     * que la insignia y el banner de la parada pausada no añaden un solo color
     * hexadecimal nuevo al trinquete de deuda de tokens.
     */
    pendingInfoTokens() {
      return AMBER_TECHNICAL_TOKENS;
    },

    /**
     * Sedes que solo siguen habilitadas por la ventana de gracia de 30 minutos
     * (RF-01.2): son las que ganan la tarjeta temporal «Sede recién visitada».
     */
    recentLocations() {
      return graceWindowLocations(this.enabledLocations);
    },

    /**
     * Resumen del aviso sanitario de otra sede (RF-03.1): cuántas paradas críticas o
     * de cadena de frío esperan y cuál es el SLA más próximo.
     */
    pendingCriticalSummary() {
      return summarizePendingCriticalStops(this.pendingCriticalStops);
    },

    /**
     * Sede sobre la que se abre el catálogo oportunista. Con la tarjeta temporal se
     * usa su sede; desde una parada activa, la del expediente.
     */
    canOpenOpportunistic() {
      return this.enabledLocations.length > 0;
    },

    /**
     * Filtered and sorted route list.
     * Orders CRITICAL first, then by operational status (IN_PROGRESS first).
     */
    filteredIncidents() {
      // RF-02.1: las intervenciones en curso de una misma sede se leen como una sola
      // parada física ordenada por criticidad. La agrupación no descarta filas: cada
      // máquina conserva sus botones individuales de pausa y resolución.
      let list = groupColocatedStops([...this.incidents]);

      if (this.filterStatus !== 'ALL') {
        const filterNorm = normalizeIncidentStatus(this.filterStatus);
        list = list.filter(inc => normalizeIncidentStatus(inc.status) === filterNorm);
      }

      const statusOrder = {
        [INCIDENT_STATUSES.IN_PROGRESS]: 1,
        [INCIDENT_STATUSES.ASSIGNED]: 2,
        [INCIDENT_STATUSES.PENDING_PARTS]: 3,
        [INCIDENT_STATUSES.PENDING_INFO]: 4
      };

      // Las paradas ya llegan agrupadas por sede y ordenadas internamente por
      // criticidad (groupColocatedStops). El orden global conserva esa secuencia de
      // grupo y aplica después la prioridad de estado y urgencia entre grupos, de
      // modo que los hermanos de una misma sede no se separan.
      const groupRank = new Map();
      list.forEach((incident) => {
        const locationId = Number(incident?.location?.id ?? incident?.location_id ?? 0);
        if (!groupRank.has(locationId)) {
          groupRank.set(locationId, groupRank.size);
        }
      });

      return list.sort((a, b) => {
        const pausedA = isPendingInfoStatus(a.status) ? 1 : 0;
        const pausedB = isPendingInfoStatus(b.status) ? 1 : 0;
        if (pausedA !== pausedB) return pausedA - pausedB;

        const locationA = Number(a?.location?.id ?? a?.location_id ?? 0);
        const locationB = Number(b?.location?.id ?? b?.location_id ?? 0);
        if (locationA !== locationB) {
          const rankA = groupRank.get(locationA) ?? 99;
          const rankB = groupRank.get(locationB) ?? 99;
          if (rankA !== rankB) return rankA - rankB;
        }

        const uA = URGENCY_RANKS[normalizeUrgency(a.urgency)] || 99;
        const uB = URGENCY_RANKS[normalizeUrgency(b.urgency)] || 99;
        if (uA !== uB) return uA - uB;

        const sA = statusOrder[normalizeIncidentStatus(a.status)] || 99;
        const sB = statusOrder[normalizeIncidentStatus(b.status)] || 99;
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
      return this.isDiagnosisValid
        && this.isActionValid
        && this.resolveRefundData.isValid
        && (!this.sanitaryResolutionRequired || this.resolveSanitaryData.isValid);
    },

    /**
     * Full-day Google Maps navigation with waypoints for the ordered route (RF-MAP-08).
     */
    fullRouteNavigationUrl() {
      const stops = [];
      for (const inc of this.incidents) {
        const lat = Number(inc.location?.latitude);
        const lng = Number(inc.location?.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
          continue;
        }
        stops.push(`${lat},${lng}`);
      }
      if (stops.length === 0) {
        return '';
      }
      const params = new URLSearchParams();
      params.set('api', '1');
      params.set('destination', stops[stops.length - 1]);
      if (stops.length > 1) {
        // Raw pipe is encoded as %7C by URLSearchParams, matching the Maps contract.
        params.set('waypoints', stops.slice(0, -1).join('|'));
      }
      params.set('travelmode', 'driving');
      return `https://www.google.com/maps/dir/?${params.toString()}`;
    }
  },
  mounted() {
    if (this.isAuthenticated) {
      this.loadRoute().then(() => this.applyDeepLink());
    }
  },
  methods: {
    /**
     * One-tap Google Maps driving navigation to the exact stop coordinates (RF-MAP-08).
     */
    navigateToStopLocation(incident) {
      const lat = Number(incident.location?.latitude);
      const lng = Number(incident.location?.longitude);
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return;
      }
      const url = `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}&travelmode=driving`;
      if (typeof window !== 'undefined' && window.open) {
        window.open(url, '_blank', 'noopener');
      }
    },

    /**
     * Loads assigned route incidents from backend (RF-07).
     */
    async loadRoute() {
      this.isLoading = true;
      this.errorMessage = '';
      try {
        // Módulo 16: el contexto oportunista viaja en la misma petición (RF-01.2). El
        // normalizador acepta el array histórico y el envoltorio, de modo que esta
        // vista sigue funcionando contra un backend sin la extensión.
        const response = await api.technician.getMyRoute({ includeOpportunisticContext: true });
        const envelope = normalizeRouteEnvelope(response);
        this.incidents = envelope.stops;
        this.enabledLocations = envelope.enabledLocations;
        this.pendingCriticalStops = envelope.pendingCriticalStops;
      } catch (err) {
        this.errorMessage = err?.message || 'No se pudo sincronizar la ruta de averías. Comprueba tu conexión.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Abre el catálogo oportunista de una sede (toque 1 del flujo, RNF-02).
     *
     * @param {{location_id?: number, location?: Object}|null} source Sede habilitada o parada de la ruta.
     */
    openOpportunisticModal(source = null) {
      const locationId = Number(
        source?.location_id ?? source?.location?.id ?? this.enabledLocations[0]?.location_id ?? 0
      );

      if (!(locationId > 0)) {
        return;
      }

      this.opportunisticLocation = this.enabledLocations.find(
        (location) => Number(location.location_id) === locationId
      ) || {
        location_id: locationId,
        location_name: source?.location?.name ?? source?.location_name ?? 'la sede actual'
      };
      this.showOpportunisticModal = true;
    },

    /**
     * Cierra el modal y refresca la ruta: el alta o la asunción cambian la hoja y la
     * ventana de gracia de la sede (RF-01.2).
     */
    async handleOpportunisticClosed() {
      this.showOpportunisticModal = false;
      await this.loadRoute();
    },

    /**
     * Texto de la tarjeta temporal «Sede recién visitada» (RF-01.2).
     *
     * @param {Object} location Sede habilitada por ventana de gracia.
     * @returns {string}
     */
    graceWindowLabel(location) {
      const minutes = graceRemainingMinutes(location);
      if (minutes <= 0) {
        return 'Sede recién visitada · ventana de gracia agotada';
      }

      return `Sede recién visitada · disponible ${minutes} min más`;
    },

    /**
     * Aplica el enlace entrante de una asignación (Módulo 15, RF-TG-08).
     *
     * Reglas: se aplica una sola vez; compara el ticket sin distinguir
     * mayúsculas; si la parada ya no está en la ruta avisa con un mensaje
     * explícito en lugar de dejar al técnico mirando una lista vacía; y el
     * desplazamiento y el resalte son opcionales para que la lógica se pueda
     * certificar sin navegador.
     */
    applyDeepLink() {
      if (this.deepLinkHandled) return;

      const ticket = String(this.initialTicketCode || '').trim();
      this.deepLinkHandled = true;
      if (ticket === '') return;

      const target = this.incidents.find(
        inc => String(inc?.ticket_code || '').toUpperCase() === ticket.toUpperCase()
      );

      if (!target) {
        this.deepLinkNotice = `El expediente ${ticket} ya no está en tu ruta. Puede haberse resuelto, cancelado o reasignado.`;
        return;
      }

      this.deepLinkNotice = '';
      this.highlightedIncidentId = target.id;

      if (typeof document !== 'undefined' && typeof document.getElementById === 'function') {
        this.$nextTick(() => {
          const element = document.getElementById(`technician-stop-${target.id}`);
          if (element && typeof element.scrollIntoView === 'function') {
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
        });
      }

      // El resalte es una pista, no un estado: se apaga solo para que la tarjeta
      // vuelva a leerse como las demás.
      const timer = setTimeout(() => {
        this.highlightedIncidentId = null;
      }, 2500);
      if (timer && typeof timer.unref === 'function') timer.unref();
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
        // El enlace del mensaje ya estaba en la URL: se aplica después del
        // acceso, que es el único momento en que la ruta es visible.
        this.applyDeepLink();
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

    isAssigned(incident) {
      return normalizeIncidentStatus(incident?.status) === INCIDENT_STATUSES.ASSIGNED;
    },
    isInProgress(incident) {
      return normalizeIncidentStatus(incident?.status) === INCIDENT_STATUSES.IN_PROGRESS;
    },
    isPendingParts(incident) {
      return normalizeIncidentStatus(incident?.status) === INCIDENT_STATUSES.PENDING_PARTS;
    },
    cardBorderLeft(incident) {
      // La parada pausada lleva el filo ámbar técnico (RNF-04): se lee como "en
      // espera de la sede", no como una avería crítica pendiente de intervención.
      if (isPendingInfoStatus(incident?.status)) return '4px solid var(--color-warning)';
      const urg = normalizeUrgency(incident?.urgency);
      if (urg === URGENCY_LEVELS.CRITICAL) return '4px solid #dc2626';
      if (urg === URGENCY_LEVELS.HIGH) return '4px solid #f97316';
      return '4px solid #c8cfda';
    },

    /**
     * Etiqueta de trazabilidad oportunista de la tarjeta (Módulo 16, RF-06.4).
     *
     * El puente es obligatorio: la plantilla compilada resuelve las llamadas
     * contra la instancia (`_ctx`), no contra el ámbito del módulo. Importar el
     * helper sin exponerlo aquí dejaba la vista en blanco con «is not a
     * function» en cuanto había una avería que pintar.
     *
     * @param {Object} incident
     * @returns {string|null}
     */
    opportunisticModeLabel(incident) {
      return resolveOpportunisticModeLabel(incident);
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
        incident.status = INCIDENT_STATUSES.IN_PROGRESS;
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
     * Opens the read-only machine incident history modal (RF-07 / EARS H.1).
     * @param {Object} incident Route incident carrying the machine payload.
     */
    openMachineHistoryModal(incident) {
      if (!incident?.machine?.id) return;
      this.historyMachine = incident.machine;
      this.showMachineHistoryModal = true;
    },

    closeMachineHistoryModal() {
      this.showMachineHistoryModal = false;
      this.historyMachine = null;
    },

    /**
     * Contador total de mensajes de una parada para la insignia de conversación
     * (RF-01.1). El técnico contabiliza públicos y notas internas de taller.
     *
     * @param {Object} incident Parada de la ruta.
     * @returns {number} Entero no negativo; 0 si la parada aún no tiene hilo.
     */
    commentCountOf(incident) {
      const parsed = Number.parseInt(incident?.comments_count, 10);
      return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    },

    /**
     * Abre el hilo de conversación de la parada pulsada (RF-01.2). El modal se
     * monta con el canal 'TECHNICIAN': muestra notas internas con distintivo
     * ámbar y publica en modo 'Nota Interna de Taller' por defecto (RF-02.3,
     * RF-03.3).
     *
     * @param {Object} incident Parada de la ruta.
     */
    openCommentsModal(incident) {
      if (!incident) return;
      this.selectedCommentIncident = incident;
      this.showCommentsModal = true;
    },

    closeCommentsModal() {
      this.showCommentsModal = false;
      this.selectedCommentIncident = null;
    },

    /**
     * Refresco inmediato del contador de la parada tras publicar un mensaje
     * (RF-03.4): usa el total exacto del hilo devuelto por el servidor y, si no
     * llegara, incrementa el valor actual en una unidad sin recargar la ruta.
     *
     * @param {Object|null} threadDto DTO del hilo emitido por el modal.
     */
    onCommentAdded(threadDto) {
      const incidentId = this.selectedCommentIncident?.id ?? null;
      if (incidentId === null) return;

      const target = this.incidents.find(inc => inc.id === incidentId);
      if (!target) return;

      const reportedTotal = Number.parseInt(threadDto?.pagination?.total_comments, 10);
      target.comments_count = Number.isFinite(reportedTotal)
        ? reportedTotal
        : this.commentCountOf(target) + 1;
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
     * Handler invoked when TechnicianSparePartsPauseModal completes structured pause (RF-REP-03, RF-REP-04).
     * @param {Object} event
     */
    handleIncidentPaused(event) {
      if (this.selectedIncident) {
        this.selectedIncident.status = INCIDENT_STATUSES.PENDING_PARTS;
        this.selectedIncident.pending_parts_reason = event?.payload?.is_out_of_catalog
          ? event?.payload?.custom_part_description
          : 'Repuestos solicitados de catálogo';
      }
      this.feedbackMessage = 'Avería pausada en espera de repuesto.';
      this.$emit('paused', event);
      this.closePauseModal();
      this.loadRoute();
    },

    /**
     * True si la parada está pausada esperando información de la sede (RF-05.5).
     * @param {Object} incident
     * @returns {boolean}
     */
    isPendingInfo(incident) {
      return isPendingInfoStatus(incident?.status);
    },

    /**
     * True si la sede ya respondió dentro de la pausa vigente (RF-05.6).
     *
     * La marca llega derivada en `site_response_since_pause`: es `true` cuando existe
     * un comentario público de la sede posterior al inicio de la pausa. El mensaje
     * informa —nunca despausa: la pausa sólo la cierra el propio técnico con la
     * reanudación in situ o el coordinador desde central (enmienda del Product Owner,
     * 2026-10-11).
     * @param {Object} incident
     * @returns {boolean}
     */
    hasSiteResponseSincePause(incident) {
      return incident?.site_response_since_pause === true
        || incident?.site_response_since_pause === 1
        || incident?.site_response_since_pause === '1';
    },

    /**
     * True si la parada admite la pausa por bloqueo imputable a la sede (RF-01.1):
     * sólo desde asignada, en curso o pendiente de repuesto. Un expediente terminal
     * o una pausa ya viva (doble pausa) nunca ofrecen el botón.
     * @param {Object} incident
     * @returns {boolean}
     */
    canPausePendingInfo(incident) {
      const status = normalizeIncidentStatus(incident?.status);
      return status === INCIDENT_STATUSES.ASSIGNED
        || status === INCIDENT_STATUSES.IN_PROGRESS
        || status === INCIDENT_STATUSES.PENDING_PARTS;
    },

    /**
     * Abre el modal de pausa por falta de acceso (RF-01.2, RF-01.3, RNF-03).
     * @param {Object} incident Parada de la ruta.
     */
    openPendingInfoPauseModal(incident) {
      if (!incident) return;
      this.pendingInfoIncident = incident;
      this.pendingInfoResumeError = '';
      this.showPendingInfoPauseModal = true;
    },

    closePendingInfoPauseModal() {
      this.showPendingInfoPauseModal = false;
      this.pendingInfoIncident = null;
    },

    /**
     * Confirma la pausa declarada por el técnico (RF-01.4, RF-05.5): la parada pasa a
     * "En espera de sede" con su insignia ámbar y su contador de tiempo pausado, se
     * desprioriza en la lista y deja de bloquear el resto de visitas del día. El
     * estado se reconcilia en segundo plano contra la API para leer el acumulado
     * exacto en segundos (RNF-01).
     * @param {Object} event Evento emitido por PendingInfoPauseModal.
     */
    handlePendingInfoPaused(event) {
      const incidentId = event?.incidentId ?? this.pendingInfoIncident?.id ?? null;
      const pauseData = event?.pauseData || {};
      const target = this.incidents.find((inc) => inc.id === incidentId);

      if (target) {
        target.status = INCIDENT_STATUSES.PENDING_INFO;
        target.paused_at = pauseData.paused_at || new Date().toISOString();

        const accumulatedMinutes = Number.parseInt(pauseData.accumulated_pause_minutes, 10);
        if (Number.isFinite(accumulatedMinutes) && accumulatedMinutes >= 0) {
          target.total_pending_info_seconds = accumulatedMinutes * 60;
        }

        target.pending_info_reason_category = pauseData.reason_category || target.pending_info_reason_category || null;
        target.pending_info_reason_category_label = pauseData.reason_category_label
          || target.pending_info_reason_category_label
          || null;
        target.pending_info_reason_text = pauseData.reason_text || target.pending_info_reason_text || null;
      }

      this.feedbackMessage = 'Parada pausada en espera de sede: reloj de SLA congelado y ruta desbloqueada.';
      this.$emit('pending-info-paused', event);
      this.closePendingInfoPauseModal();
      this.loadRoute();
    },

    /**
     * Reanuda la intervención in situ con un solo toque (RF-02.2, RF-02.3): devuelve la
     * parada a "En curso" y el servidor reactiva el reloj contractual con el
     * desplazamiento comercial de los minutos pausados.
     * @param {Object} incident Parada pausada.
     */
    async resumePendingInfoIntervention(incident) {
      if (!incident?.id || this.isResumingPendingInfo) return;

      this.isResumingPendingInfo = true;
      this.pendingInfoResumeError = '';
      this.errorMessage = '';

      try {
        const payload = await api.technician.resumeIncidentPendingInfo(
          incident.id,
          INCIDENT_STATUSES.IN_PROGRESS
        ) || {};

        incident.status = INCIDENT_STATUSES.IN_PROGRESS;
        incident.paused_at = null;
        if (!incident.started_at) {
          incident.started_at = new Date().toISOString();
        }

        this.feedbackMessage = 'Intervención reanudada in situ. Reloj contractual de SLA reactivado.';
        this.$emit('pending-info-resumed', {
          incidentId: incident.id,
          status: payload.status || INCIDENT_STATUSES.IN_PROGRESS
        });
      } catch (err) {
        this.pendingInfoResumeError = err?.message || 'No se pudo reanudar la intervención. Inténtalo de nuevo.';
      } finally {
        this.isResumingPendingInfo = false;
      }
    },

    /**
     * Minutos transcurridos de la pausa viva (RF-05.5, RNF-01): la interfaz redondea
     * al minuto lo que la base de datos guarda al segundo, y cae al acumulado cuando
     * el intervalo ya se cerró.
     * @param {Object} incident
     * @returns {number}
     */
    pendingInfoElapsedMinutes(incident) {
      const pausedAt = incident?.paused_at ? new Date(incident.paused_at) : null;
      if (pausedAt && Number.isFinite(pausedAt.getTime())) {
        const diffMs = Date.now() - pausedAt.getTime();
        return diffMs > 0 ? Math.floor(diffMs / 60000) : 0;
      }

      const accumulatedSeconds = Number.parseInt(incident?.total_pending_info_seconds, 10);
      return Number.isFinite(accumulatedSeconds) && accumulatedSeconds > 0
        ? Math.floor(accumulatedSeconds / 60)
        : 0;
    },

    /**
     * Etiqueta del contador de tiempo pausado de la insignia (RF-05.5).
     * @param {Object} incident
     * @returns {string}
     */
    pendingInfoElapsedLabel(incident) {
      const minutes = this.pendingInfoElapsedMinutes(incident);
      if (minutes <= 0) return 'Pausada hace menos de 1 min';
      if (minutes < 60) return `Pausada hace ${minutes} min`;

      const hours = Math.floor(minutes / 60);
      const rest = minutes % 60;
      return rest === 0 ? `Pausada hace ${hours} h` : `Pausada hace ${hours} h ${rest} min`;
    },

    /**
     * Causa tipificada de la pausa (RF-01.2), con el catálogo del modal como respaldo
     * si el servidor no envió la etiqueta.
     * @param {Object} incident
     * @returns {string}
     */
    pendingInfoReasonLabel(incident) {
      const direct = incident?.pending_info_reason_category_label;
      if (typeof direct === 'string' && direct.trim() !== '') {
        return direct;
      }

      const raw = incident?.pending_info_reason_category;
      const found = raw ? PAUSE_REASON_CATEGORIES.find((cat) => cat.value === raw) : null;
      return found ? found.label : 'Información pendiente de la sede';
    },

    /**
     * Justificación íntegra declarada por el técnico al pausar (RF-01.4).
     * @param {Object} incident
     * @returns {string}
     */
    pendingInfoReasonText(incident) {
      return incident?.pending_info_reason_text || '';
    },

    /**
     * Submits the pause reason to the API (RF-07 / EARS 7.2) - Backwards compatible method.
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
        this.selectedIncident.status = INCIDENT_STATUSES.PENDING_PARTS;
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
     * Opens the Resolve Modal with strict justification and spare parts block (RF-08 / EARS 8.1, 8.2 / RF-REP-05).
     * @param {Object} incident
     */
    async openResolveModal(incident) {
      this.selectedIncident = incident;
      this.resolveDiagnosis = '';
      this.resolveAction = '';
      this.resolvePartsData = { replaced_parts_declared: false, replaced_parts: [] };
      this.resolveRefundData = { isValid: true };
      this.resolveSanitaryData = { isValid: false };
      this.resolveServiceSheetData = { isValid: false };
      this.sanitaryResolutionRequired = Boolean(incident?.sanitary_resolution_required);
      this.refundRequests = [];
      this.hasPendingRefundVerdict = false;
      this.refundInspectionFailed = false;
      this.refundInspectionRequestId++;
      this.isLoadingRefundInspection = true;
      this.resolveError = '';
      this.showResolveModal = true;
      await this.loadRefundInspection(incident);
    },

    closeResolveModal() {
      this.showResolveModal = false;
      this.selectedIncident = null;
      this.resolveDiagnosis = '';
      this.resolveAction = '';
      this.resolvePartsData = { replaced_parts_declared: false, replaced_parts: [] };
      this.resolveRefundData = { isValid: true };
      this.resolveSanitaryData = { isValid: false };
      this.resolveServiceSheetData = { isValid: false };
      this.sanitaryResolutionRequired = false;
      this.refundRequests = [];
      this.hasPendingRefundVerdict = false;
      this.refundInspectionFailed = false;
      this.refundInspectionRequestId++;
      this.isLoadingRefundInspection = false;
      this.resolveError = '';
    },

    /**
     * Loads privacy-safe refund claims before the technician can resolve an incident (T-REF-16).
     * A failed lookup blocks resolution rather than assuming there is no pending claim.
     * @param {Object} incident
     */
    async loadRefundInspection(incident) {
      const incidentId = incident?.id;
      if (!incidentId) {
        this.isLoadingRefundInspection = false;
        this.refundInspectionFailed = true;
        this.hasPendingRefundVerdict = true;
        this.resolveRefundData = { isValid: false };
        this.resolveError = 'No se pudo identificar la incidencia para consultar sus reclamaciones de saldo.';
        return;
      }

      const requestId = this.refundInspectionRequestId;
      this.refundInspectionFailed = false;
      this.isLoadingRefundInspection = true;
      try {
        const response = await api.technician.getRefundInspection(incidentId);
        if (requestId !== this.refundInspectionRequestId || !this.showResolveModal || this.selectedIncident?.id !== incidentId) return;
        const data = response?.data || response || {};
        if (!Array.isArray(data.requests) || typeof data.has_pending_verdict !== 'boolean') {
          throw new Error('La respuesta de reclamaciones no tiene el formato esperado.');
        }
        this.refundRequests = data.requests;
        this.hasPendingRefundVerdict = data.has_pending_verdict;
        this.refundInspectionFailed = false;
        const hasPendingRequest = data.requests.some((request) => request.status === 'PENDING_INSPECTION');
        this.resolveRefundData = { isValid: !data.has_pending_verdict && !hasPendingRequest };
      } catch (err) {
        if (requestId !== this.refundInspectionRequestId || !this.showResolveModal || this.selectedIncident?.id !== incidentId) return;
        this.refundRequests = [];
        this.hasPendingRefundVerdict = true;
        this.refundInspectionFailed = true;
        this.resolveRefundData = { isValid: false };
        this.resolveError = err?.message || 'No se pudieron consultar las reclamaciones de saldo. Inténtalo de nuevo.';
      } finally {
        if (requestId === this.refundInspectionRequestId) this.isLoadingRefundInspection = false;
      }
    },

    /**
     * Captures the validity and optional inspection payload from the refund block.
     * @param {Object} event
     */
    handleRefundInspectionChange(event) {
      this.resolveRefundData = event && typeof event === 'object' ? event : { isValid: false };
    },

    /**
     * Captura la validez del bloque de declaraciones sanitarias (RF-03.5.2, Art. II).
     * @param {Object} payload
     */
    handleSanitaryChange(payload) {
      this.resolveSanitaryData = payload && typeof payload === 'object' ? payload : { isValid: false };
    },

    /**
     * Submits technical resolution to API (RF-08 / EARS 8.1, 8.2, 8.3 / Art. V.1 / RF-REP-05, RF-REP-06).
     */
    async submitResolve() {
      const diag = this.resolveDiagnosis.trim();
      const act = this.resolveAction.trim();

      // Client-side strict validation (EARS 8.2 / ResolutionValidator)
      if (diag.length < 20 || act.length < 20) {
        this.resolveError = 'Tanto el diagnóstico como la acción correctiva deben contener al menos 20 caracteres descriptivos cada uno.';
        return;
      }

      if (this.isLoadingRefundInspection) {
        this.resolveError = 'Espera a que termine la consulta de reclamaciones de saldo antes de resolver.';
        return;
      }
      if (this.refundInspectionFailed) {
        this.resolveError = 'No se pudo verificar si hay reclamaciones de saldo; vuelve a abrir la resolución para intentarlo de nuevo.';
        return;
      }

      let partsVal = null;
      if (this.$refs?.resolutionPartsBlockRef && typeof this.$refs.resolutionPartsBlockRef.validate === 'function') {
        partsVal = this.$refs.resolutionPartsBlockRef.validate();
        if (!partsVal.isValid) {
          this.resolveError = partsVal.error || 'Debe completar la declaración de repuestos sustituidos.';
          return;
        }
      }

      let refundPayload = {};
      if (this.$refs?.resolutionRefundBlockRef && typeof this.$refs.resolutionRefundBlockRef.validate === 'function') {
        const refundVal = this.$refs.resolutionRefundBlockRef.validate();
        if (!refundVal.isValid) {
          this.resolveError = refundVal.error || 'Debe completar el dictamen de saldo antes de resolver.';
          return;
        }
        refundPayload = refundVal.payload || {};
      } else if (this.hasPendingRefundVerdict || !this.resolveRefundData?.isValid) {
        this.resolveError = 'No se ha podido verificar o completar el dictamen obligatorio de saldo.';
        return;
      }

      // Bloqueo sanitario del cierre (RF-03.5.2, Art. II): si la máquina está en
      // cuarentena o el reloj biológico venció, las tres declaraciones son
      // obligatorias. El servidor vuelve a exigirlas: esta validación es la
      // ergonomía de campo, no la puerta de seguridad.
      let sanitaryPayload = {};
      if (this.sanitaryResolutionRequired) {
        const sanitaryVal = this.$refs?.resolutionSanitaryBlockRef?.validate?.();
        if (!sanitaryVal || !sanitaryVal.isValid) {
          this.resolveError = sanitaryVal?.error || 'No se pudo verificar el bloque de declaraciones sanitarias; vuelve a abrir la resolución para intentarlo de nuevo.';
          return;
        }
        sanitaryPayload = sanitaryVal.payload || {};
      }

      // Conformidad del responsable de sede (Módulo 19, RF-01.4, RF-02.1, RD-02): en la
      // interfaz la firma o la atestación de ausencia son obligatorias, y es el bloque
      // quien decide si el trazo tiene tinta. El servidor revalida el bloque completo.
      const serviceSheetVal = this.$refs?.resolutionServiceSheetBlockRef?.validate?.();
      if (!serviceSheetVal || !serviceSheetVal.isValid) {
        this.resolveError = serviceSheetVal?.error || 'No se pudo verificar el bloque de conformidad del responsable; vuelve a abrir la resolución para intentarlo de nuevo.';
        return;
      }
      const serviceSheetPayload = serviceSheetVal.payload || null;

      this.isResolving = true;
      this.resolveError = '';

      try {
        const hasParts = Boolean(this.resolvePartsData?.replaced_parts_declared);
        const replacedParts = hasParts ? (this.resolvePartsData?.replaced_parts || []) : [];
        const payload = {
          diagnosis: diag,
          action_taken: act,
          ...refundPayload,
          ...sanitaryPayload
        };
        if (hasParts) {
          payload.replaced_parts_declared = true;
          payload.replaced_parts = replacedParts;
        }
        if (serviceSheetPayload) {
          payload.service_sheet = serviceSheetPayload;
        }
        const hasRefundPayload = Object.keys(refundPayload).length > 0;
        const hasSanitaryPayload = Object.keys(sanitaryPayload).length > 0;
        const res = hasParts || hasRefundPayload || hasSanitaryPayload || serviceSheetPayload !== null
          ? await api.technician.resolveIncident(this.selectedIncident.id, payload)
          : await api.technician.resolveIncident(this.selectedIncident.id, diag, act);
        const resolvedAt = res?.data?.resolved_at || new Date().toISOString();

        this.feedbackMessage = '¡Avería resuelta con éxito! Se ha activado la ventana de garantía de 48 horas.';
        this.$emit('resolved', {
          incidentId: this.selectedIncident.id,
          diagnosis: diag,
          action: act,
          resolvedAt,
          replaced_parts_declared: hasParts,
          replaced_parts: replacedParts,
          service_sheet: res?.data?.service_sheet || null,
          ...refundPayload
        });

        const solvedId = this.selectedIncident.id;
        // El albarán se imprime justo después de cerrar: se recuerda el expediente porque
        // la avería sale de la ruta en el acto (RF-05.4).
        this.serviceSheetIncidentId = solvedId;
        this.closeResolveModal();

        // Remove from active route
        this.incidents = this.incidents.filter(inc => inc.id !== solvedId);
      } catch (err) {
        // El reloj biológico pudo vencer entre la carga de la ruta y el intento de
        // cierre: el servidor exige las declaraciones y la puerta se abre en el acto
        // para que el técnico pueda completarlas sin reabrir el modal.
        //
        // Y si la cuarentena fue sobrevenida —la declaró la central mientras él trabajaba
        // sin cobertura— el código es SANITARY_QUARANTINE_OVERTAKEN y la puerta debe abrirse
        // igual: el servidor ya guardó su parte a resguardo, así que lo único que le falta
        // para cerrar es el protocolo sanitario que el formulario ni siquiera mostraba
        // (RF-10.1/EARS-10.2, Art. II). El informe redactado sigue en memoria: nada se borra.
        if (err?.code === 'SANITARY_CHECKLIST_REQUIRED' || err?.code === 'SANITARY_QUARANTINE_OVERTAKEN') {
          this.sanitaryResolutionRequired = true;
        }
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
    },

    /**
     * Emits event and opens the preventive checklist or reinspection modal (RF-PREV-03, RF-PREV-08)
     */
    handleOpenPreventiveChecklist(order) {
      this.selectedPreventiveOrder = order;
      if (order?.order_type === 'REINSPECTION' || order?.status === 'REINSPECTION_PENDING') {
        this.showPreventiveReinspectionModal = true;
      } else {
        this.showPreventiveChecklistModal = true;
      }
      this.$emit('open-preventive-checklist', order);
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
            <a
              href="/docs/guia_rapida_taller_repuestos_a4.html"
              target="_blank"
              rel="noopener"
              class="vg-btn vg-btn-secondary"
              style="height: 36px; padding: 0 10px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; text-decoration: none;"
              title="Guía rápida de repuestos (A4 imprimible): cuándo pausar, justificar piezas y errores comunes"
              aria-label="Abrir guía rápida de repuestos en formato A4"
              data-testid="btn-quick-guide"
            >
              <span>🧰</span>
            </a>
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

        <!-- Barra de Navegación Móvil del Técnico (RF-07, RF-04, RF-PREV-02) -->
        <div style="display: flex; gap: 6px; margin-bottom: 14px; background: #e5f2fc; padding: 4px; border-radius: 8px;">
          <button
            type="button"
            @click="activeSection = 'route'"
            :style="{ flex: 1, padding: '8px 8px', fontSize: '12px', fontWeight: '700', borderRadius: '6px', border: 'none', cursor: 'pointer', backgroundColor: activeSection === 'route' ? '#2560ff' : 'transparent', color: activeSection === 'route' ? '#ffffff' : '#2c333f', transition: 'all 0.2s ease' }"
            data-testid="tech-tab-route"
          >
            📍 Averías ({{ routeMetrics.total }})
          </button>
          <button
            type="button"
            @click="activeSection = 'preventive'"
            :style="{ flex: 1, padding: '8px 8px', fontSize: '12px', fontWeight: '700', borderRadius: '6px', border: 'none', cursor: 'pointer', backgroundColor: activeSection === 'preventive' ? '#2560ff' : 'transparent', color: activeSection === 'preventive' ? '#ffffff' : '#2c333f', transition: 'all 0.2s ease' }"
            data-testid="tech-tab-preventive"
          >
            🛡️ Preventivo
          </button>
          <button
            type="button"
            @click="activeSection = 'metrics'"
            :style="{ flex: 1, padding: '8px 8px', fontSize: '12px', fontWeight: '700', borderRadius: '6px', border: 'none', cursor: 'pointer', backgroundColor: activeSection === 'metrics' ? '#2560ff' : 'transparent', color: activeSection === 'metrics' ? '#ffffff' : '#2c333f', transition: 'all 0.2s ease' }"
            data-testid="tech-tab-metrics"
          >
            📈 Mis Métricas
          </button>
        </div>

        <!-- SECCIÓN 2: MIS MÉTRICAS DE RENDIMIENTO (RF-04) -->
        <div v-if="activeSection === 'metrics'">
          <TechnicianMetricsView />
        </div>

        <!-- SECCIÓN 3: MANTENIMIENTO PREVENTIVO Y VISITA OPORTUNISTA (RF-PREV-02, EARS 2.3) -->
        <div v-else-if="activeSection === 'preventive'">
          <TechnicianPreventiveRouteTab
            :enabled-locations="enabledLocations"
            @open-checklist="handleOpenPreventiveChecklist($event)"
            @order-claimed="loadRoute"
            @inspection-started="loadRoute"
            @open-opportunistic="openOpportunisticModal($event)"
          />
        </div>

        <!-- SECCIÓN 1: RUTA OPERATIVA DE TRABAJO (RF-07, RF-08) -->
        <div v-else>
          <!-- Stock de la furgoneta (Módulo 18, RF-04.1). Va en un bloque plegable nativo: el
               técnico lo abre cuando lo necesita sin que la lista de averías —lo urgente— quede
               empujada fuera de la primera pantalla del móvil. -->
          <details
            data-testid="tech-van-stock-section"
            style="margin-bottom: 12px; padding: 12px 16px; background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card);"
          >
            <summary style="cursor: pointer; font-family: var(--font-body); font-size: var(--font-size-body-md); font-weight: 700; color: var(--color-ink);">
              🧰 Stock de mi furgoneta
            </summary>
            <div style="margin-top: 12px;">
              <TechnicianVanStockPanel />
            </div>
          </details>

          <!-- Prominent "Ver Mapa de Ruta" button plus full-day Google Maps link (RF-MAP-08) -->
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px;">
            <button
              type="button"
              class="vg-btn vg-btn-primary"
              style="height: 48px; font-size: 15px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 8px;"
              data-testid="btn-open-route-map"
              @click="showRouteMapModal = true"
            >
              🗺️ Ver Mapa de Ruta
            </button>
            <a
              v-if="fullRouteNavigationUrl"
              :href="fullRouteNavigationUrl"
              target="_blank"
              rel="noopener"
              class="vg-btn vg-btn-secondary"
              style="height: 48px; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none;"
              data-testid="btn-full-route-navigation"
            >
              🚗 Navegación GPS del día
            </a>
          </div>

          <!-- Global Toast / Feedback Messages -->
          <div
            v-if="feedbackMessage"
          style="background-color: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; padding: 10px 14px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;"
          role="status"
        >
          <span>✓ {{ feedbackMessage }}</span>
          <button
            v-if="serviceSheetIncidentId"
            type="button"
            class="vg-btn vg-btn-success-soft"
            data-testid="btn-open-service-sheet"
            @click="showServiceSheetModal = true"
          >
            🧾 Ver albarán
          </button>
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

        <!-- Enlace entrante de una asignación (Módulo 15, RF-TG-08): si el
             expediente citado ya no está en la ruta, se dice con claridad en
             lugar de dejar al técnico mirando una lista que no lo contiene. -->
        <div
          v-if="deepLinkNotice"
          style="background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); padding: 10px 14px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;"
          role="status"
          data-testid="deep-link-notice"
        >
          <span>ℹ️ {{ deepLinkNotice }}</span>
          <button type="button" @click="deepLinkNotice = ''" style="background: none; border: none; font-size: 14px; color: var(--color-warning-text); cursor: pointer;">×</button>
        </div>

        <!-- Aviso sanitario de otra sede (Módulo 16, RF-03.1, Art. II): la decisión de
             quedarse aquí se confirma en el modal; la ruta solo avisa de que existe la
             emergencia para que el técnico no la descubra al abrir el catálogo. -->
        <div
          v-if="pendingCriticalSummary.count > 0"
          class="vg-alert vg-alert-warning"
          data-testid="tech-cold-chain-route-warning"
          role="status"
          style="margin-bottom: 12px; display: flex; flex-direction: column; gap: 4px;"
        >
          <strong style="font-size: 13px;">⚠️ Prioridad sanitaria en otra sede (Art. II)</strong>
          <span style="font-size: 12px;">
            Tienes {{ pendingCriticalSummary.count }} parada(s) crítica(s) esperando
            <span v-if="pendingCriticalSummary.perishable_count > 0">
              ({{ pendingCriticalSummary.perishable_count }} de comida perecedera)
            </span>.
          </span>
        </div>

        <!-- Tarjeta temporal de sede recién visitada (Módulo 16, RF-01.2): puerta de
             entrada a la intervención oportunista en ≤ 3 toques. -->
        <div
          v-for="recent in recentLocations"
          :key="'recent-location-' + recent.location_id"
          class="vg-card vg-recent-location-card"
          data-testid="tech-recent-location-card"
          style="margin-bottom: 12px; border-left: 4px solid var(--color-primary); display: flex; flex-direction: column; gap: 6px;"
        >
          <strong style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 14px; color: var(--color-ink);">
            🏢 Sede recién visitada (30 min) · {{ recent.location_name }}
          </strong>
          <span style="font-size: 12px; color: var(--color-ink-muted);">
            {{ graceWindowLabel(recent) }} por si surge otra avería in situ.
          </span>
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            style="width: 100%; height: 44px; font-size: 14px; font-weight: 600;"
            data-testid="btn-open-recent-location-catalog"
            @click="openOpportunisticModal(recent)"
          >
            ➕ Otra máquina en esta sede
          </button>
        </div>

        <!-- Mobile Filter Tabs (Touch Friendly): wrap on the card so no chip ends cut -->
        <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px;">
          <button
            type="button"
            class="vg-btn"
            :class="filterStatus === 'ALL' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 32px; font-size: 12px; padding: 0 12px; white-space: nowrap;"
            @click="filterStatus = 'ALL'"
          >
            Todas ({{ routeMetrics.total }})
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="filterStatus === 'IN_PROGRESS' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 32px; font-size: 12px; padding: 0 12px; white-space: nowrap;"
            @click="filterStatus = 'IN_PROGRESS'"
          >
            En curso ({{ routeMetrics.inProgress }})
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="filterStatus === 'ASSIGNED' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 32px; font-size: 12px; padding: 0 12px; white-space: nowrap;"
            @click="filterStatus = 'ASSIGNED'"
          >
            Por iniciar ({{ routeMetrics.assigned }})
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="filterStatus === 'PENDING_PARTS' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 32px; font-size: 12px; padding: 0 12px; white-space: nowrap;"
            @click="filterStatus = 'PENDING_PARTS'"
          >
            Repuestos ({{ routeMetrics.pendingParts }})
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="filterStatus === 'PENDING_INFO' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 32px; font-size: 12px; padding: 0 12px; white-space: nowrap;"
            data-testid="filter-pending-info"
            @click="filterStatus = 'PENDING_INFO'"
          >
            En espera ({{ routeMetrics.pendingInfo }})
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
            :id="'technician-stop-' + incident.id"
            class="vg-route-card"
            :style="{
              backgroundColor: '#ffffff',
              border: '1px solid var(--color-hairline, #c8cfda)',
              borderLeft: cardBorderLeft(incident),
              borderRadius: 'var(--radius-card, 8px)',
              padding: '14px',
              boxShadow: highlightedIncidentId === incident.id
                ? '0 0 0 2px var(--color-primary)'
                : 'var(--shadow-card, 0 1px 3px rgba(0,0,0,0.04))',
              transition: 'box-shadow 220ms ease-out'
            }"
          >
            <!-- Card Header: Ticket & Badges -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
              <span style="font-family: monospace; font-size: 13px; font-weight: 700; color: var(--color-slate, #2c333f);">
                #{{ incident.ticket_code }}
              </span>
              <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                <IncidentBadge type="urgency" :value="incident.urgency" />
                <IncidentBadge type="status" :value="incident.status" />
                <!-- Trazabilidad oportunista (Módulo 16, RF-06.4). -->
                <IncidentBadge
                  v-if="opportunisticModeLabel(incident)"
                  :value="incident.opportunistic_mode"
                  type="opportunistic"
                  size="sm"
                />
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

              <!-- One-tap GPS navigation to the exact machine location (RF-MAP-08) -->
              <div v-if="incident.location?.latitude !== null && incident.location?.latitude !== undefined" style="margin-top: 6px;">
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary"
                  style="display: inline-flex; align-items: center; gap: 6px; height: 30px; font-size: 12px; padding: 0 10px;"
                  data-testid="btn-navigate-stop"
                  @click="navigateToStopLocation(incident)"
                >
                  🧭 Navegar con GPS
                </button>
              </div>

              <!-- Agrupación de la misma sede (RB-02.1 / RF-02.1): en la primera tarjeta
                   del grupo se anuncia cuántas máquinas del edificio esperan, para que el
                   técnico no las busque como paradas independientes. -->
              <div
                v-if="incident.colocation_count > 1 && incident.colocation_primary"
                class="vg-alert vg-alert-info"
                data-testid="tech-colocated-stops"
                style="margin-top: 6px; font-size: 12px;"
              >
                🏢 {{ incident.colocation_count }} intervenciones en curso en esta sede, ordenadas por urgencia y SLA.
              </div>

              <!-- Read-only incident history of this machine (RF-07 / EARS H.1, H.6) -->
              <div v-if="incident.machine?.id" style="margin-top: 6px;">
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary"
                  style="display: inline-flex; align-items: center; gap: 6px; height: 30px; font-size: 12px; padding: 0 10px;"
                  data-testid="btn-machine-history"
                  @click="openMachineHistoryModal(incident)"
                >
                  🕘 Historial de la máquina
                </button>
              </div>

              <!-- Conversation thread badge with the total message count (RF-01.1, RF-02.3) -->
              <div style="margin-top: 6px;">
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary vg-stop-comments-badge"
                  style="display: inline-flex; align-items: center; gap: 6px; height: 34px; font-size: 13px; font-weight: 600; padding: 0 12px;"
                  data-testid="btn-stop-comments"
                  :aria-label="'Conversación de la parada: ' + commentCountOf(incident) + ' mensajes'"
                  @click="openCommentsModal(incident)"
                >
                  <span aria-hidden="true">💬</span>
                  <span data-testid="stop-comments-count">{{ commentCountOf(incident) }}</span> mensajes
                </button>
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
              v-if="isInProgress(incident)"
              style="background-color: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; border-radius: var(--radius-interactive, 4px); padding: 6px 10px; font-size: 12px; font-weight: 500; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;"
            >
              <span style="font-size: 14px;">⏱️</span>
              <span>Intervención en curso desde las {{ formatTime(incident.started_at) }}</span>
            </div>

            <!-- 2. Pendiente de repuesto banner -->
            <div
              v-if="isPendingParts(incident)"
              style="background-color: #fefce8; border: 1px solid #fef08a; color: #854d0e; border-radius: var(--radius-interactive, 4px); padding: 8px 10px; font-size: 12px; margin-bottom: 12px;"
            >
              <strong>⚠️ En espera de repuesto:</strong>
              <div style="margin-top: 2px; font-style: italic;">
                "{{ incident.pending_parts_reason }}"
              </div>
            </div>

            <!-- 3. Parada pausada en espera de la sede (Módulo 11, RF-05.5, RNF-04) -->
            <div
              v-if="isPendingInfo(incident)"
              class="vg-pending-info-stop"
              data-testid="tech-stop-pending-info"
              :style="{
                backgroundColor: pendingInfoTokens.bg,
                border: '1px solid ' + pendingInfoTokens.border,
                color: pendingInfoTokens.color,
                borderRadius: 'var(--radius-interactive, 4px)',
                padding: '10px 12px',
                fontSize: '12px',
                marginBottom: '12px'
              }"
            >
              <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                <strong data-testid="tech-stop-pending-info-badge" style="font-size: 13px; font-weight: 700;">
                  ⏸️ En espera de sede
                </strong>
                <span data-testid="tech-stop-pending-info-timer" style="font-size: 12px; font-weight: 600;">
                  {{ pendingInfoElapsedLabel(incident) }}
                </span>
              </div>
              <div style="margin-top: 4px;">
                <strong>Causa:</strong> {{ pendingInfoReasonLabel(incident) }}
              </div>
              <p
                v-if="pendingInfoReasonText(incident)"
                style="margin: 4px 0 0 0; font-style: italic; line-height: 1.35;"
              >
                "{{ pendingInfoReasonText(incident) }}"
              </p>
              <p style="margin: 6px 0 0 0; line-height: 1.35;">
                Parada despriorizada: puede continuar con las siguientes visitas del día sin bloqueo de ruta.
              </p>
              <!-- Aviso de respuesta de la sede (RF-05.6, enmienda E-1): la respuesta
                   esperada ya está en el hilo y la pausa sigue viva hasta que el
                   técnico la cierre in situ (o el coordinador desde central). El aviso
                   lleva incorporada la reanudación de RF-02.2 para que el técnico actúe
                   donde lee: con el aviso visible la barra de acciones no repite el
                   botón (un solo control de reanudación por tarjeta). -->
              <div
                v-if="hasSiteResponseSincePause(incident)"
                data-testid="tech-stop-site-response"
                :style="{
                  marginTop: '8px',
                  paddingTop: '8px',
                  borderTop: '1px dashed ' + pendingInfoTokens.border,
                  lineHeight: 1.35
                }"
              >
                <strong role="status">✉️ La sede ha respondido · pendiente de tu reanudación</strong>
                <button
                  type="button"
                  class="vg-btn vg-btn-primary"
                  style="width: 100%; height: 44px; margin-top: 8px; font-size: 14px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px;"
                  data-testid="btn-resume-pending-info"
                  :disabled="isResumingPendingInfo"
                  @click="resumePendingInfoIntervention(incident)"
                >
                  <span v-if="!isResumingPendingInfo">▶ Reanudar intervención in situ</span>
                  <span v-else>Reanudando...</span>
                </button>
              </div>
            </div>

            <!-- Action Buttons Bar (Large, Mobile Finger-Friendly Tap Targets >= 44px) -->
            <div style="border-top: 1px solid var(--color-hairline, #c8cfda); padding-top: 10px;">
              <!-- Action Option 0: Reanudación in situ de la pausa con 1 toque (Módulo 11, RF-02.2, RF-02.3).
                   Cuando el aviso RF-05.6 está visible la acción vive dentro del aviso y
                   aquí no se repite: un solo control de reanudación por tarjeta. -->
              <button
                v-if="isPendingInfo(incident) && !hasSiteResponseSincePause(incident)"
                type="button"
                class="vg-btn vg-btn-primary"
                style="width: 100%; height: 46px; font-size: 15px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px;"
                data-testid="btn-resume-pending-info"
                :disabled="isResumingPendingInfo"
                @click="resumePendingInfoIntervention(incident)"
              >
                <span v-if="!isResumingPendingInfo">▶ Reanudar intervención in situ</span>
                <span v-else>Reanudando...</span>
              </button>
              <div
                v-if="isPendingInfo(incident) && pendingInfoResumeError"
                role="alert"
                style="background-color: var(--color-error-bg); border: 1px solid var(--color-error); color: var(--color-error-text); padding: 8px 10px; border-radius: var(--radius-interactive, 4px); font-size: 12px; margin-top: 8px;"
              >
                ⚠️ {{ pendingInfoResumeError }}
              </div>

              <!-- Action Option 1: Start Intervention (when ASSIGNED) -->
              <button
                v-else-if="isAssigned(incident)"
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
              <div v-else-if="isInProgress(incident)" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
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
                v-else-if="isPendingParts(incident)"
                type="button"
                class="vg-btn vg-btn-primary"
                style="width: 100%; height: 46px; font-size: 15px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px;"
                :disabled="actionInProgressId === incident.id"
                @click="startIntervention(incident)"
              >
                <span v-if="actionInProgressId !== incident.id">▶ Reanudar intervención</span>
                <span v-else>Reanudando...</span>
              </button>

              <!-- Action Option 3-bis: Otra máquina en esta sede (Módulo 16, RF-01.2, RNF-02).
                   Solo en paradas activas (asignadas o en curso) y cuando la sede sigue
                   habilitada para el técnico. -->
              <div
                v-if="(isAssigned(incident) || isInProgress(incident)) && canOpenOpportunistic"
                style="margin-top: 8px;"
              >
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary"
                  style="width: 100%; height: 44px; font-size: 14px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px;"
                  data-testid="btn-opportunistic-other-machine"
                  @click="openOpportunisticModal(incident)"
                >
                  ➕ Otra máquina en esta sede
                </button>
              </div>

              <!-- Action Option 4: Pausa por falta de acceso imputable a la sede (Módulo 11, RF-01.1) -->
              <div v-if="canPausePendingInfo(incident)" style="margin-top: 8px;">
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary"
                  style="width: 100%; height: 46px; font-size: 14px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px;"
                  data-testid="btn-pause-no-access"
                  @click="openPendingInfoPauseModal(incident)"
                >
                  ⏸️ Pausar por falta de acceso
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

      <!-- =================================================================== -->
      <!-- MODAL 0: INTERACTIVE ROUTE MAP (RF-MAP-06, RF-MAP-07, RF-MAP-08) -->
      <!-- =================================================================== -->
      <TechnicianRouteMapModal v-model="showRouteMapModal" />

      <!-- =================================================================== -->
      <!-- MODAL 0-bis: MACHINE INCIDENT HISTORY (read-only, EARS H.1-H.6) -->
      <!-- =================================================================== -->
      <TechnicianMachineHistoryModal
        v-model="showMachineHistoryModal"
        :machine="historyMachine"
        @close="closeMachineHistoryModal"
      />

      <!-- =================================================================== -->
      <!-- MODAL: HILO DE CONVERSACIÓN DE LA PARADA (Módulo 10, canal TÉCNICO)  -->
      <!-- =================================================================== -->
      <IncidentCommentThreadModal
        :is-open="showCommentsModal"
        :incident-id="selectedCommentIncident?.id ?? null"
        :ticket-code="selectedCommentIncident?.ticket_code ?? null"
        role="TECHNICIAN"
        @close="closeCommentsModal"
        @comment-added="onCommentAdded"
      />

      <!-- =================================================================== -->
      <!-- MODAL: INTERVENCIÓN OPORTUNISTA IN SITU (Módulo 16, T-OPP-11)          -->
      <!-- =================================================================== -->
      <TechnicianOpportunisticModal
        v-model="showOpportunisticModal"
        :location-id="opportunisticLocation?.location_id ?? null"
        :location-name="opportunisticLocation?.location_name ?? ''"
        @created="handleOpportunisticClosed"
        @assumed="handleOpportunisticClosed"
        @close="showOpportunisticModal = false"
      />

      <!-- =================================================================== -->
      <!-- MODAL 6: PAUSA POR FALTA DE ACCESO / BLOQUEO DE SEDE (Módulo 11, T-PAUSE-18) -->
      <!-- =================================================================== -->
      <PendingInfoPauseModal
        :is-open="showPendingInfoPauseModal"
        :incident="pendingInfoIncident"
        role="TECHNICIAN"
        @close="closePendingInfoPauseModal"
        @paused="handlePendingInfoPaused"
      />

      <!-- =================================================================== -->
      <!-- MODAL 1: PAUSE BY REPLACEMENT PART (RF-REP-03, RF-REP-04 / T-SPARE-16, T-SPARE-17) -->
      <!-- =================================================================== -->
      <TechnicianSparePartsPauseModal
        v-model="showPauseModal"
        :incident="selectedIncident"
        @paused="handleIncidentPaused"
        @close="closePauseModal"
      />

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

          <!-- Field 3: Spare Parts Declaration (RF-REP-05, RF-REP-06 / T-SPARE-16, T-SPARE-17) -->
          <div v-if="isLoadingRefundInspection" role="status" style="margin-bottom: 14px; padding: 12px; color: #596579; background: #f9fafb; border: 1px solid #c8cfda; border-radius: 4px; font-size: 13px;">
            ⏳ Consultando reclamaciones de saldo...
          </div>
          <TechnicianResolutionRefundBlock
            v-else
            ref="resolutionRefundBlockRef"
            :refund-requests="refundRequests"
            :requires-verdict="hasPendingRefundVerdict"
            :allow-unclaimed-cash="selectedIncident?.category === 'PAYMENT_SYSTEM'"
            :disabled="isResolving || refundInspectionFailed"
            @change="handleRefundInspectionChange"
          />

          <TechnicianSanitaryResolutionBlock
            v-if="sanitaryResolutionRequired"
            ref="resolutionSanitaryBlockRef"
            :key="'sanitary-' + (selectedIncident?.id || 0)"
            :disabled="isResolving"
            @change="handleSanitaryChange"
          />

          <TechnicianResolutionPartsBlock
            ref="resolutionPartsBlockRef"
            :machine-id="selectedIncident?.machine?.id || selectedIncident?.machine_id || 0"
            :machine-model="selectedIncident?.machine?.model || selectedIncident?.machine_model || ''"
            :incident-id="selectedIncident?.id"
            @change="resolvePartsData = $event"
          />

          <TechnicianServiceSheetBlock
            ref="resolutionServiceSheetBlockRef"
            :key="'service-sheet-' + (selectedIncident?.id || 0)"
            :disabled="isResolving"
            @change="resolveServiceSheetData = $event"
          />

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
              :disabled="isResolving || isLoadingRefundInspection || refundInspectionFailed || !canResolve || !resolveRefundData.isValid"
            >
              <span v-if="!isResolving">Confirmar Resolución (48h Garantía)</span>
              <span v-else>Guardando resolución...</span>
            </button>
          </div>
        </form>
      </ModalDialog>

      <!-- =================================================================== -->
      <!-- MODAL 3: CHECKLIST SANITARIO DIGITAL IN SITU (RF-PREV-03 / T-PREV-21) -->
      <!-- =================================================================== -->
      <TechnicianChecklistModal
        v-model="showPreventiveChecklistModal"
        :order="selectedPreventiveOrder"
        @inspection-completed="loadRoute"
      />

      <!-- =================================================================== -->
      <!-- MODAL 4: REINSPECCIÓN SANITARIA (RF-PREV-04, RF-PREV-08 / T-PREV-21) -->
      <!-- =================================================================== -->
      <TechnicianReinspectionModal
        v-model="showPreventiveReinspectionModal"
        :order="selectedPreventiveOrder"
        @reinspection-completed="loadRoute"
      />

      <!-- =================================================================== -->
      <!-- MODAL 5: ALBARÁN DE SERVICIO A4 (Módulo 19, RF-04, RF-05)          -->
      <!-- =================================================================== -->
      <ServiceSheetModal
        v-model="showServiceSheetModal"
        :incident-id="serviceSheetIncidentId"
        audience="technician"
      />
    </div>
  `
};

export default TechnicianRouteView;
