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
import { OfflineSyncTrayModal } from '../components/OfflineSyncTrayModal.js';
import {
  MUTATION_STATUS,
  OFFLINE_MESSAGES,
  OfflineStorage,
  PERISHABLE_MACHINE_TYPES,
  SNAPSHOT_VALIDITY,
  evaluateSnapshotValidity,
  requiresSanitarySeal
} from '../utils/OfflineStorage.js';
import { OFFLINE_QUEUE_EVENT, OfflineSyncManager } from '../utils/OfflineSyncManager.js';

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
    TechnicianVanStockPanel,
    OfflineSyncTrayModal
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
      opportunisticLocation: null,

      // Módulo 21: Control de Turno y Relevo de Fin de Jornada (RF-03, RF-05)
      shiftStatus: 'OFF_SHIFT',
      isShiftLoading: false,
      shiftError: '',
      showShiftHandoverModal: false,
      handoverMode: 'CLOSE_ONLY',
      selectedHandoverTicketIds: [],
      targetHandoverTechId: '',
      handoverNotes: '',
      closeReason: '',
      isSubmittingHandover: false,
      handoverError: '',
      handoverTargets: [],
      availableReceptors: [],

      // Módulo 22: contador del día y autoconsulta «Mis Horas» (RF-07, D4, Art. V.4)
      todaySummary: null,
      showMyHoursModal: false,
      myHoursLoading: false,
      myHoursError: '',
      myHours: {
        month: '',
        from_date: '',
        to_date: '',
        summary: {},
        sessions: []
      },

      // ───────────────────────────────────────────────────────────────────
      // Módulo 24 · Modo sin conexión (RF-02 a RF-06)
      //
      // La copia local de ruta y la bandeja viven en `IndexedDB` aisladas por
      // `technician_id` (Art. V.4). La vista solo lee el estado ya calculado por
      // `OfflineStorage` / `OfflineSyncManager`: la política de vigencia (4 h / 12 h),
      // la cuota fotográfica y el encadenamiento temporal no se reimplementan aquí.
      // ───────────────────────────────────────────────────────────────────
      offlineStorage: null,
      offlineManager: null,
      offlineSnapshot: null,
      offlineValidity: { status: SNAPSHOT_VALIDITY.MISSING, canEnqueueNewActions: false, ageMinutes: null },
      offlineQueue: { pending: 0, syncing: 0, conflicts: 0, total: 0, photoCount: 0, photoBytes: 0 },
      offlineQueueItems: [],
      offlineSyncProgress: { current: 0, total: 0 },
      isOfflineMode: false,
      isSyncingOffline: false,
      offlineNotice: '',
      showOfflineTray: false,
      offlineReauthOwner: null,
      offlineDumpInProgressId: '',

      // Pausa sin conexión: formulario propio de la vista porque los modales
      // enriquecidos de repuesto y bloqueo de sede exigen red para completarse.
      showOfflinePauseModal: false,
      offlinePauseIncident: null,
      offlinePauseOperation: 'PAUSE_INCIDENT_PARTS',
      offlinePauseCategory: '',
      offlinePauseReason: '',
      offlinePauseError: '',
      isOfflinePausing: false,
      sanitarySealConfirmed: false,

      // Comentario in situ sin conexión (RF-03.1.5, D3, QA 3).
      showOfflineCommentModal: false,
      offlineCommentIncident: null,
      offlineCommentText: '',
      offlineCommentInternal: true,
      offlineCommentPhoto: null,
      offlineCommentPhotoError: '',
      offlineCommentError: '',
      isOfflineCommenting: false,

      // Checklist preventivo sin conexión (RF-03.1.4, EARS 03.4).
      showOfflineChecklistModal: false,
      offlineChecklistOrder: null,
      offlineChecklistOperation: 'COMPLETE_PREVENTIVE',
      offlineChecklistItems: [],
      offlineChecklistTemperature: '',
      offlineChecklistGeneralNotes: '',
      offlineChecklistReinspectionNotes: '',
      offlineChecklistError: '',
      offlineChecklistSealConfirmed: false,
      isOfflineChecklistSubmitting: false,
      offlinePreventiveSelection: ''
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
    /** Totales del mes propio que publica `GET /api/technician/shift/my-hours` (RF-07.2). */
    myHoursSummary() {
      return this.myHours?.summary || {};
    },

    /** Sesiones propias del mes, en orden cronológico; nunca las de otro compañero (Art. V.4). */
    myHoursSessions() {
      return Array.isArray(this.myHours?.sessions) ? this.myHours.sessions : [];
    },

    myHoursIsEmpty() {
      return this.myHoursSessions.length === 0;
    },

    /**
     * Estado de conexión que pinta la bandeja (`RF-02.2`).
     *
     * Se deriva del estado del motor y del navegador: una sincronización en curso
     * manda sobre la etiqueta de red para que el técnico vea el progreso real.
     *
     * @returns {string} `ONLINE` | `OFFLINE` | `SYNCING`
     */
    offlineConnectionStatus() {
      if (this.isSyncingOffline) {
        return 'SYNCING';
      }

      return this.isOfflineMode ? 'OFFLINE' : 'ONLINE';
    },

    /** Paradas con una resolución esperando en la cola (`QA 4`). */
    pendingResolutionStops() {
      return this.offlineQueueItems.filter(
        (item) => item?.operation_type === 'RESOLVE_INCIDENT'
      );
    },

    /** ¿Hay que avisar de que el Albarán A4 aún no puede imprimirse? (`QA 4`). */
    hasPendingServiceSheets() {
      return this.pendingResolutionStops.length > 0;
    },

    /** ¿La copia local admite nuevas acciones? (`RF-02.4`, `D6`). */
    canRegisterOfflineActions() {
      return this.offlineValidity?.canEnqueueNewActions === true;
    },

    /** Catálogo de causas de pausa por bloqueo de sede, expuesto a la plantilla. */
    pauseReasonCategories() {
      return PAUSE_REASON_CATEGORIES;
    },

    /** Órdenes preventivas ya asignadas que la copia local permite inspeccionar. */
    offlinePreventiveOrders() {
      const orders = this.offlineSnapshot?.preventive_orders;

      return Array.isArray(orders)
        ? orders.filter((order) => ['SCHEDULED', 'IN_INSPECTION', 'REINSPECTION_PENDING'].includes(
          String(order?.status ?? '')
        ))
        : [];
    },

    /** ¿El checklist local es una reinspección sanitaria? */
    offlineChecklistIsReinspection() {
      return this.offlineChecklistOperation === 'REINSPECT_PREVENTIVE';
    },

    /** Tipo de máquina de la orden preventiva en curso. */
    offlineChecklistMachineType() {
      return String(this.offlineChecklistOrder?.machine_type ?? '').toUpperCase();
    },

    /** ¿La inspección preventiva exige precinto físico in situ? (`EARS 03.4`). */
    offlineChecklistSealRequired() {
      if (this.offlineChecklistIsReinspection) {
        return false;
      }

      return requiresSanitarySeal(
        'COMPLETE_PREVENTIVE',
        { items: this.offlineChecklistItems.map((item) => ({ status: item.status })) },
        this.offlineChecklistMachineType
      );
    },

    /** «Octubre de 2026» a partir del `month` del contrato §5.6. */
    myHoursMonthLabel() {
      const month = this.myHours?.month;
      if (!month || !/^\d{4}-\d{2}$/.test(String(month))) {
        return 'Mes en curso';
      }
      const [year, monthNumber] = String(month).split('-').map(Number);
      const names = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
      const name = names[monthNumber - 1] || '';
      return `${name.charAt(0).toUpperCase()}${name.slice(1)} de ${year}`;
    },

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
     * Módulo 21: Detecta si existe alguna avería en curso que bloquee el cierre de turno (RF-04).
     */
    hasInProgressIncidents() {
      const list = this.assignedIncidents || this.incidents || [];
      return list.some(inc => normalizeIncidentStatus(inc.status) === INCIDENT_STATUSES.IN_PROGRESS);
    },

    /**
     * Módulo 21: Averías elegibles para relevo de fin de turno (ASSIGNED, PENDING_PARTS, PENDING_INFO) (RF-05).
     */
    eligibleHandoverIncidents() {
      const list = this.assignedIncidents || this.incidents || [];
      const eligibleStatuses = [
        INCIDENT_STATUSES.ASSIGNED,
        INCIDENT_STATUSES.PENDING_PARTS,
        INCIDENT_STATUSES.PENDING_INFO,
      ];
      return list.filter(inc => eligibleStatuses.includes(normalizeIncidentStatus(inc.status)));
    },

    /**
     * Nombre del receptor elegido, para el texto del botón de confirmación.
     */
    selectedHandoverTargetName() {
      const target = this.handoverTargets.find(
        tech => String(tech.id) === String(this.targetHandoverTechId)
      );
      return target ? target.name : '';
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
    // El motor local arranca antes de la primera petición: si la ruta cae por
    // falta de cobertura, la copia ya está disponible para hidratar la pantalla.
    this.initializeOfflineMode().then(() => {
      // Cola heredada de la sesión anterior con cobertura disponible: se envía
      // sin esperar a que el técnico pulse nada (`EARS 04.1`).
      if (this.isAuthenticated && this.offlineQueue.total > 0 && !this.isBrowserOffline()) {
        this.syncOfflineQueue({ silent: true });
      }
    });

    // El reloj de antigüedad debe avanzar solo: una pestaña abierta toda la
    // mañana sin cobertura tiene que cruzar el aviso de 4 h y el bloqueo de
    // 12 h sin que el técnico toque nada (`EARS 02.2` a `EARS 02.4`).
    if (typeof setInterval === 'function') {
      this._offlineClock = setInterval(() => this.refreshOfflineState(), 60 * 1000);
    }

    if (this.isAuthenticated) {
      this.loadRoute().then(() => this.applyDeepLink());
      this.loadShiftStatus();
    }
  },
  beforeUnmount() {
    this.detachOfflineListeners();

    if (this._offlineClock) {
      clearInterval(this._offlineClock);
      this._offlineClock = null;
    }
  },
  methods: {
    /**
     * Aplica la respuesta del ciclo de jornada: estado y acumulado del día.
     *
     * `today_summary` viaja de forma aditiva en `status`, `start`, `pause-meal` y
     * `resume`, así que el contador se refresca sin una segunda petición (RF-07.1).
     */
    applyShiftResponse(response, fallbackStatus) {
      const data = response && typeof response === 'object' && response.data && typeof response.data === 'object'
        ? response.data
        : response;
      this.shiftStatus = data?.status || fallbackStatus;
      if (data?.today_summary && typeof data.today_summary === 'object') {
        this.todaySummary = data.today_summary;
      }
      return data;
    },

    async loadShiftStatus() {
      try {
        const res = await api.get('/technician/shift/status');
        this.applyShiftResponse(res, 'OFF_SHIFT');
      } catch (err) {
        console.error('Error al cargar estado de turno:', err);
      }
    },

    async startShift() {
      if (!this.guardOnlineOnly('Iniciar turno')) {
        this.shiftError = 'Iniciar turno requiere conexión en vivo con el servidor.';
        return;
      }

      this.isShiftLoading = true;
      this.shiftError = '';
      try {
        const res = await api.post('/technician/shift/start');
        this.applyShiftResponse(res, 'ON_SHIFT');
      } catch (err) {
        this.shiftError = err?.message || 'No se pudo iniciar el turno.';
      } finally {
        this.isShiftLoading = false;
      }
    },

    async pauseMealShift() {
      // EARS 06.2 / QA 5: la pausa de comida exige la cola vacía o volcada.
      if (!(await this.ensureOfflineQueueReadyForShift())) {
        return;
      }

      this.isShiftLoading = true;
      this.shiftError = '';
      try {
        const res = await api.post('/technician/shift/pause-meal');
        this.applyShiftResponse(res, 'MEAL_BREAK');
      } catch (err) {
        this.shiftError = err?.message || 'No se pudo pausar para comida.';
      } finally {
        this.isShiftLoading = false;
      }
    },

    async resumeMealShift() {
      if (!this.guardOnlineOnly('Reanudar turno')) {
        this.shiftError = 'Reanudar turno requiere conexión en vivo con el servidor.';
        return;
      }

      this.isShiftLoading = true;
      this.shiftError = '';
      try {
        const res = await api.post('/technician/shift/resume');
        this.applyShiftResponse(res, 'ON_SHIFT');
      } catch (err) {
        this.shiftError = err?.message || 'No se pudo reanudar el turno.';
      } finally {
        this.isShiftLoading = false;
      }
    },

    /** Abre la autoconsulta de solo lectura y reinicia su estado de error (RF-07.2). */
    openMyHours() {
      this.showMyHoursModal = true;
      this.myHoursError = '';
      this.myHours = { month: '', from_date: '', to_date: '', summary: {}, sessions: [] };
      return this.loadMyHours();
    },

    closeMyHours() {
      this.showMyHoursModal = false;
    },

    /**
     * Carga el resumen mensual propio. Sin parámetro de técnico: el backend lo
     * resuelve desde el token y jamás acepta un id ajeno (Art. V.4, RF-08.1).
     */
    async loadMyHours() {
      this.myHoursLoading = true;
      this.myHoursError = '';
      try {
        const res = await api.technicianShift.getMyHours();
        const data = res && (res.summary || res.sessions || res.month)
          ? res
          : (res?.data && typeof res.data === 'object' ? res.data : {});
        this.myHours = {
          month: data.month || '',
          from_date: data.from_date || '',
          to_date: data.to_date || '',
          summary: data.summary && typeof data.summary === 'object' ? data.summary : {},
          sessions: Array.isArray(data.sessions) ? data.sessions : []
        };
      } catch (err) {
        this.myHoursError = err?.message || 'No se pudieron cargar tus horas.';
      } finally {
        this.myHoursLoading = false;
      }
    },

    formatShiftDate(value) {
      if (!value) return '—';
      const plain = String(value).slice(0, 10).split('-');
      if (plain.length === 3) {
        return `${plain[2]}/${plain[1]}/${plain[0]}`;
      }
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return '—';
      return `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')}/${date.getFullYear()}`;
    },

    formatShiftDateTime(value) {
      if (!value) return '—';
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return '—';
      return `${this.formatShiftDate(value)} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
    },

    formatShiftDuration(seconds) {
      const total = Math.max(0, Math.round(Number(seconds) || 0));
      return `${String(Math.floor(total / 3600)).padStart(2, '0')}h ${String(Math.floor((total % 3600) / 60)).padStart(2, '0')}m`;
    },

    /** Un turno ajustado conserva visibles las marcas del primer fichaje (Art. III.3). */
    hasShiftOriginalMarks(session) {
      if (!session) return false;
      return (
        session.is_adjusted === true ||
        (session.original_meal_break_seconds !== null && session.original_meal_break_seconds !== undefined) ||
        Boolean(session.original_closed_at)
      );
    },

    async openShiftHandoverModal() {
      // El cierre de turno solo puede evaluar el estado real de las paradas
      // (`EARS 06.2`): primero se envía la cola y se exige volcar conflictos.
      if (!(await this.ensureOfflineQueueReadyForShift())) {
        return;
      }

      this.showShiftHandoverModal = true;
      this.handoverMode = 'CLOSE_ONLY';
      this.handoverError = '';
      this.handoverNotes = '';
      this.closeReason = '';
      this.targetHandoverTechId = '';
      this.selectedHandoverTicketIds = [];
      // En modo cierre no se consulta la lista de compañeros: el modo seguro
      // no debe depender de una lectura que puede fallar.
    },

    /**
     * Cambia entre cierre sin relevo y traspaso explícito.
     *
     * La lista de receptores se carga solo al entrar en TRASFER; abrir el modal
     * en modo cierre no genera tráfico ni preselecciona averías.
     */
    async setHandoverMode(mode) {
      if (!['CLOSE_ONLY', 'TRANSFER'].includes(mode)) {
        return;
      }

      this.handoverMode = mode;
      this.handoverError = '';

      if (mode === 'CLOSE_ONLY') {
        this.targetHandoverTechId = '';
        this.selectedHandoverTicketIds = [];
        return;
      }

      if (this.eligibleHandoverIncidents.length === 0) {
        this.handoverError = 'No tienes averías pendientes para traspasar. Puedes cerrar sin relevo.';
        return;
      }

      if (this.handoverTargets.length === 0) {
        await this.loadHandoverTargets();
      }
    },

    async loadHandoverTargets() {
      try {
        // Endpoint propio del técnico: el cuadrante de Coordinación exige rol
        // COORDINATOR y devolvía 403 desde el móvil (A6).
        const res = await api.get('/technician/handover-targets');
        const raw = res?.data || res || [];
        const list = Array.isArray(raw) ? raw : [];
        const myId = Number(this.currentUser?.id);

        this.handoverTargets = list
          .filter(t => Number(t.id) !== myId && t.is_active !== false)
          .map(t => {
            const availability = t.availability || 'AVAILABLE';
            const canReceive = t.is_available_for_handover !== undefined
              ? Boolean(t.is_available_for_handover)
              : availability === 'AVAILABLE';

            return {
              ...t,
              availability,
              availability_label: t.availability_label || availability,
              is_available_for_handover: canReceive,
            };
          });

        this.availableReceptors = this.handoverTargets.filter(t => t.is_available_for_handover);
      } catch (e) {
        this.handoverTargets = [];
        this.availableReceptors = [];
        this.handoverError = 'No se pudieron cargar los compañeros disponibles para el relevo.';
      }
    },

    selectAllHandoverIncidents() {
      this.selectedHandoverTicketIds = this.eligibleHandoverIncidents.map(i => i.id);
    },

    clearHandoverSelection() {
      this.selectedHandoverTicketIds = [];
    },

    /**
     * Etiqueta de máquina del listado de relevo. La ruta publica `machine.code`
     * anidado; el modal antiguo solo miraba `machine_code` y siempre caía a
     * "Máquina #undefined" (A6).
     */
    handoverMachineLabel(incident) {
      const code = incident?.machine?.code || incident?.machine_code;
      if (code) {
        return code;
      }
      const machineId = incident?.machine?.id || incident?.machine_id;
      return machineId ? `Máquina #${machineId}` : 'Máquina sin identificar';
    },

    closeShiftHandoverModal() {
      this.showShiftHandoverModal = false;
      this.handoverError = '';
    },

    async submitShiftHandover() {
      if (!this.guardOnlineOnly('Cerrar turno y relevo')) {
        this.handoverError = 'Cerrar turno requiere conexión en vivo con el servidor.';
        return;
      }

      if (this.hasInProgressIncidents) {
        this.handoverError = 'No es posible cerrar turno con averías en curso. Debe resolver la intervención o pausarla.';
        return;
      }

      const isTransfer = this.handoverMode === 'TRANSFER';

      if (isTransfer) {
        if (this.selectedHandoverTicketIds.length === 0) {
          this.handoverError = 'Selecciona al menos una avería para traspasar o cambia a cierre sin relevo.';
          return;
        }
        if (!this.targetHandoverTechId) {
          this.handoverError = 'Debe seleccionar un técnico receptor para las averías traspasadas.';
          return;
        }
        const target = this.handoverTargets.find(
          tech => String(tech.id) === String(this.targetHandoverTechId)
        );
        if (target && target.is_available_for_handover === false) {
          this.handoverError = 'El técnico seleccionado no está disponible para recibir el relevo.';
          return;
        }
        if (this.handoverNotes.trim().length < 10) {
          this.handoverError = 'La nota de traspaso debe tener al menos 10 caracteres.';
          return;
        }
      }

      this.isSubmittingHandover = true;
      this.handoverError = '';
      try {
        const payload = isTransfer
          ? {
              target_technician_id: Number(this.targetHandoverTechId),
              incident_ids: this.selectedHandoverTicketIds,
              handover_notes: this.handoverNotes,
              close_reason: this.closeReason || 'Fin de jornada con relevo',
            }
          : {
              target_technician_id: null,
              incident_ids: [],
              handover_notes: '',
              close_reason: 'Fin de jornada sin relevo',
            };

        await api.post('/technician/shift/close-handover', payload);
        this.showShiftHandoverModal = false;
        this.shiftStatus = 'OFF_SHIFT';
        await this.loadRoute();
        // El cierre también consolida el acumulado del día en el contador (RF-07.1).
        await this.loadShiftStatus();
      } catch (err) {
        if (err?.code === 'CONCURRENT_ROUTE_MODIFICATION' || err?.status === 409) {
          this.handoverError = 'La ruta ha cambiado concurrentemente. Se ha actualizado la lista de incidencias.';
          await this.loadRoute();
        } else if (err?.code === 'TARGET_TECHNICIAN_UNAVAILABLE') {
          this.handoverError = err?.message || 'El técnico seleccionado no está disponible para recibir el relevo.';
          this.targetHandoverTechId = '';
          this.handoverTargets = [];
          await this.loadHandoverTargets();
        } else {
          this.handoverError = err?.message || 'Error al cerrar el turno.';
        }
      } finally {
        this.isSubmittingHandover = false;
      }
    },
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

    // =======================================================================
    // Módulo 24 · Modo sin conexión (RF-02 a RF-06)
    // =======================================================================

    /** ¿El navegador declara estar sin red? (`API` disponible fuera del navegador → `false`). */
    isBrowserOffline() {
      return typeof navigator !== 'undefined' && navigator.onLine === false;
    },

    /** ¿El fallo es de transporte (caída o plazo agotado), no una respuesta del servidor? */
    isNetworkFailure(error) {
      const status = Number(error?.status ?? -1);
      const code = String(error?.code ?? '');

      return status === 0 || code === 'NETWORK_ERROR' || code === 'REQUEST_TIMEOUT';
    },

    /** Identificador del técnico autenticado: dueño único de su copia local (Art. V.4). */
    offlineTechnicianId() {
      return Number(this.currentUser?.id ?? 0);
    },

    /**
     * Enciende el motor local y se suscribe a los cambios de red y de bandeja.
     *
     * Se ejecuta en `mounted()` antes de la primera carga de ruta para que la
     * hidratación desde `IndexedDB` no dependa del orden de las peticiones.
     */
    initializeOfflineMode() {
      if (this.offlineStorage === null) {
        this.offlineStorage = new OfflineStorage();
      }

      if (this.offlineManager === null) {
        this.offlineManager = new OfflineSyncManager({ storage: this.offlineStorage });
      }

      this.isOfflineMode = this.isBrowserOffline();
      this.attachOfflineListeners();

      return this.refreshOfflineState();
    },

    /** Registra los eventos nativos `online` / `offline` y el aviso de la cola. */
    attachOfflineListeners() {
      if (typeof window === 'undefined' || typeof window.addEventListener !== 'function') {
        return;
      }

      if (this._offlineHandlers) {
        return;
      }

      this._offlineHandlers = {
        online: () => this.handleConnectivityChange(true),
        offline: () => this.handleConnectivityChange(false),
        queue: (event) => this.handleOfflineQueueEvent(event)
      };

      window.addEventListener('online', this._offlineHandlers.online);
      window.addEventListener('offline', this._offlineHandlers.offline);
      window.addEventListener(OFFLINE_QUEUE_EVENT, this._offlineHandlers.queue);
    },

    /** Retira los listeners al desmontar la vista (sin fugas entre sesiones). */
    detachOfflineListeners() {
      if (typeof window === 'undefined' || typeof window.removeEventListener !== 'function') {
        return;
      }

      const handlers = this._offlineHandlers;
      if (!handlers) {
        return;
      }

      window.removeEventListener('online', handlers.online);
      window.removeEventListener('offline', handlers.offline);
      window.removeEventListener(OFFLINE_QUEUE_EVENT, handlers.queue);
      this._offlineHandlers = null;
    },

    /** Relee vigencia, cola y conflictos del técnico autenticado. */
    async refreshOfflineState() {
      const technicianId = this.offlineTechnicianId();
      if (!(technicianId > 0) || this.offlineStorage === null) {
        return;
      }

      try {
        const snapshot = await this.offlineStorage.getSnapshot(technicianId);
        this.offlineSnapshot = snapshot;
        this.offlineValidity = evaluateSnapshotValidity(snapshot, Date.now());

        const items = await this.offlineStorage.listMutations(technicianId);
        const count = (status) => items.filter((item) => item?.status === status).length;
        const withPhoto = items.filter((item) => item?.photo_attachment);

        this.offlineQueueItems = items;
        this.offlineQueue = {
          pending: count(MUTATION_STATUS.PENDING),
          syncing: count(MUTATION_STATUS.SYNCING),
          conflicts: count(MUTATION_STATUS.CONFLICT),
          total: items.length,
          photoCount: withPhoto.length,
          photoBytes: withPhoto.reduce(
            (sum, item) => sum + Number(item.photo_attachment?.size ?? 0),
            0
          )
        };
      } catch (error) {
        // Sin `IndexedDB` legible la ruta online sigue funcionando: el modo local
        // no puede tumbar la pantalla que lo contiene.
      }
    },

    /**
     * Guarda la copia local desde el paquete de bootstrap del servidor (`EARS 02.1`).
     *
     * Un fallo aquí no rompe la carga de ruta: la ruta ya está pintada y lo único
     * que se pierde es la posibilidad de trabajar sin cobertura en este viaje.
     */
    async refreshOfflineSnapshotFromServer() {
      const technicianId = this.offlineTechnicianId();
      if (!(technicianId > 0) || this.offlineManager === null) {
        return null;
      }

      try {
        const result = await this.offlineManager.refreshSnapshot(technicianId);
        this.offlineSnapshot = result.snapshot;
        this.offlineValidity = result.validity;

        return result.snapshot;
      } catch (error) {
        return null;
      }
    },

    /** Normaliza una parada del paquete local a la forma que pinta la tarjeta. */
    normalizeOfflineStop(stop) {
      const machine = stop?.machine && typeof stop.machine === 'object' ? { ...stop.machine } : {};
      const pendingInfo = stop?.pending_info && typeof stop.pending_info === 'object'
        ? stop.pending_info
        : {};

      return {
        ...stop,
        id: Number(stop?.id ?? stop?.incident_id ?? 0),
        comments_count: Number(stop?.comments_count ?? 0),
        site_response_since_pause: stop?.site_response_since_pause === true,
        pending_info_reason_category: stop?.pending_info_reason_category ?? pendingInfo.category ?? null,
        pending_info_reason_text: stop?.pending_info_reason_text ?? pendingInfo.reason ?? null,
        machine
      };
    },

    /**
     * Hidrata la pantalla desde la copia local cuando no hay red (`RF-02.1`).
     *
     * @returns {Promise<boolean>} `true` si había copia local del técnico.
     */
    async hydrateOfflineRoute() {
      const technicianId = this.offlineTechnicianId();
      if (!(technicianId > 0) || this.offlineStorage === null) {
        return false;
      }

      try {
        const snapshot = this.offlineSnapshot ?? (await this.offlineStorage.getSnapshot(technicianId));
        this.offlineValidity = evaluateSnapshotValidity(snapshot, Date.now());

        if (snapshot === null) {
          return false;
        }

        this.offlineSnapshot = snapshot;
        const envelope = snapshot.route_envelope ?? {};
        this.incidents = Array.isArray(envelope.stops)
          ? envelope.stops.map((stop) => this.normalizeOfflineStop(stop))
          : [];
        this.enabledLocations = Array.isArray(envelope.enabled_locations)
          ? envelope.enabled_locations
          : [];
        this.pendingCriticalStops = Array.isArray(envelope.pending_critical_stops_other_locations)
          ? envelope.pending_critical_stops_other_locations
          : [];

        return true;
      } catch (error) {
        return false;
      }
    },

    /**
     * Encola una acción de parada con sus metadatos de sincronización (`D1`, `D5`).
     *
     * @param {Object} request
     * @returns {Promise<Object>} Mutación persistida en la bandeja local
     */
    async enqueueOfflineAction(request) {
      const technicianId = this.offlineTechnicianId();
      if (!(technicianId > 0) || this.offlineManager === null) {
        throw new Error('No se pudo identificar tu cuenta para guardar el trabajo sin conexión.');
      }

      const incident = request?.incident ?? null;
      let machineType = String(
        incident?.machine?.machine_type ?? incident?.machine?.type ?? ''
      ).toUpperCase();

      // La ruta online no publica el tipo de máquina; la copia local sí. Sin este
      // respaldo, una caída de red justo al pausar una máquina perecedera perdería
      // la puerta del precinto y el servidor rechazaría la pausa al sincronizar.
      if (machineType === '') {
        const localStop = (this.offlineSnapshot?.route_envelope?.stops ?? []).find(
          (stop) => Number(stop?.id ?? stop?.incident_id) === Number(incident?.id)
        );
        machineType = String(localStop?.machine?.machine_type ?? '').toUpperCase();
      }

      const mutation = await this.offlineManager.enqueue({
        technicianId,
        technicianEmail: this.currentUser?.email ?? null,
        stopType: request.stopType,
        stopId: request.stopId,
        operationType: request.operationType,
        payload: request.payload ?? {},
        photoFile: request.photoFile ?? null,
        sanitarySealConfirmed: request.sanitarySealConfirmed === true,
        ticketOrOrderCode: incident?.ticket_code ?? request.ticketOrOrderCode ?? null,
        machineId: incident?.machine?.id ?? incident?.machine_id ?? null,
        machineCode: incident?.machine?.code ?? null,
        machineType: machineType === '' ? null : machineType
      });

      await this.refreshOfflineState();

      return mutation;
    },

    /**
     * Procesa la cola completa en FIFO y resume el resultado para el técnico
     * (`EARS 04.1`, `RF-05`).
     *
     * @param {{silent?: boolean}} [options={}]
     * @returns {Promise<{applied: number, conflicts: number, pending: number, interrupted: (string|null)}>}
     */
    async syncOfflineQueue(options = {}) {
      const silent = options?.silent === true;
      const technicianId = this.offlineTechnicianId();

      if (this.isSyncingOffline) {
        return { applied: 0, conflicts: 0, pending: this.offlineQueue.total, interrupted: 'already_running' };
      }

      if (!(technicianId > 0) || this.offlineManager === null) {
        return { applied: 0, conflicts: 0, pending: 0, interrupted: 'unavailable' };
      }

      if (this.isBrowserOffline()) {
        this.isOfflineMode = true;
        if (!silent) {
          this.offlineNotice = 'Sin cobertura: la cola queda guardada y se enviará automáticamente al recuperar la conexión.';
        }
        return { applied: 0, conflicts: 0, pending: this.offlineQueue.total, interrupted: 'offline' };
      }

      if (this.offlineQueue.total === 0) {
        await this.refreshOfflineState();
      }

      const pendingBefore = Math.max(1, this.offlineQueue.pending + this.offlineQueue.syncing);
      // Resoluciones que esperan sello: al confirmarse el envío se habilita de
      // inmediato el Albarán oficial A4 de la primera (`QA 4`, `EARS 03.2.2`).
      const pendingResolutionIds = this.offlineQueueItems
        .filter((item) => item?.operation_type === 'RESOLVE_INCIDENT')
        .map((item) => Number(item?.stop_id))
        .filter((id) => id > 0);
      this.isSyncingOffline = true;
      this.offlineSyncProgress = { current: 0, total: pendingBefore };

      try {
        const summary = await this.offlineManager.processOutboxQueue(technicianId);
        this.offlineSyncProgress = {
          current: Math.min(pendingBefore, summary.applied + summary.conflicts),
          total: pendingBefore
        };
        await this.refreshOfflineState();

        if (summary.conflicts > 0) {
          this.offlineNotice = `Hay ${summary.conflicts} intervención(es) en conflicto. `
            + 'Debes volcarlas al expediente antes de cerrar sesión o turno.';
          this.showOfflineTray = true;
        } else if (!silent && summary.applied > 0) {
          this.feedbackMessage = `Se han sincronizado ${summary.applied} intervención(es) con el servidor.`;
        }

        if (summary.applied > 0 && summary.conflicts === 0 && pendingResolutionIds.length > 0) {
          this.serviceSheetIncidentId = pendingResolutionIds[0];
        }

        if (summary.interrupted === 'reauth_required') {
          await this.handleReauthRequired({ technicianId });
        } else if (summary.interrupted === 'account_deactivated') {
          // EARS 06.4 / QA 5: el volcado ya archivó el trabajo como constancia de
          // auditoría; el dispositivo se libera cerrando la sesión de esa cuenta.
          this.offlineNotice = 'Tu cuenta ha sido desactivada. El trabajo pendiente se ha volcado '
            + 'como constancia de auditoría y la sesión se cierra para liberar el dispositivo.';
          store.clearSession();
        } else if (summary.interrupted === 'network') {
          this.isOfflineMode = true;
        }

        return summary;
      } catch (error) {
        if (!silent) {
          this.offlineNotice = error?.message || 'No se pudo completar la sincronización. La cola sigue guardada.';
        }

        return { applied: 0, conflicts: 0, pending: this.offlineQueue.total, interrupted: 'error' };
      } finally {
        this.isSyncingOffline = false;
      }
    },

    /** Reacciona a la recuperación o pérdida de red. */
    async handleConnectivityChange(isOnline) {
      this.isOfflineMode = !isOnline;

      if (!isOnline) {
        if (this.incidents.length === 0) {
          await this.hydrateOfflineRoute();
        }
        await this.refreshOfflineState();
        this.offlineNotice = 'Estás sin conexión: tu ruta se muestra desde la copia local del dispositivo.';
        return;
      }

      this.offlineNotice = '';
      const summary = await this.syncOfflineQueue({ silent: true });

      if (summary?.interrupted !== 'reauth_required') {
        await this.loadRoute();
      }
    },

    /** Lee los eventos del motor de cola y mantiene la bandeja al día. */
    handleOfflineQueueEvent(event) {
      const detail = event?.detail ?? {};

      if (detail.type === 'reauth_required') {
        this.handleReauthRequired(detail);
      } else if (detail.type === 'conflict') {
        this.offlineNotice = 'Una operación ha quedado en conflicto: vuelca el trabajo al expediente desde la bandeja.';
      } else if (detail.type === 'account_dump_completed') {
        this.offlineNotice = 'Tu cuenta ya no está activa: el trabajo pendiente se ha volcado como constancia de auditoría.';
      } else if (detail.type === 'account_dump_failed') {
        this.errorMessage = 'No se pudo volcar la cola de la cuenta desactivada. Conserva el dispositivo encendido y con cobertura.';
      }

      return this.refreshOfflineState();
    },

    /**
     * Conserva la identidad del titular de la cola cuando la sesión cadua al
     * sincronizar (`EARS 06.3`, `D4`).
     */
    async handleReauthRequired(detail = {}) {
      const technicianId = Number(detail?.technicianId ?? this.offlineTechnicianId() ?? 0);
      const snapshot = technicianId > 0 && this.offlineStorage !== null
        ? await this.offlineStorage.getSnapshot(technicianId)
        : null;

      this.offlineReauthOwner = {
        technician_id: technicianId,
        technician_name: snapshot?.technician_name ?? this.currentUser?.name ?? null,
        technician_email: snapshot?.technician_email ?? this.currentUser?.email ?? null
      };
      this.offlineNotice = 'Tu sesión ha caducado. La cola sigue intacta en el dispositivo: '
        + 'vuelve a identificarte con la misma cuenta para sincronizarla.';
      this.showOfflineTray = true;
    },

    /** Abre el formulario de acceso desde la bandeja (re-autenticación, `EARS 06.3`). */
    requestOfflineReauth() {
      this.showOfflineTray = false;
      this.loginError = this.offlineReauthOwner?.technician_email
        ? `Identifícate como ${this.offlineReauthOwner.technician_email} para sincronizar su trabajo pendiente.`
        : 'Vuelve a identificarte con la misma cuenta para sincronizar el trabajo pendiente.';

      if (typeof window !== 'undefined' && typeof window.scrollTo === 'function') {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
    },

    /** Volcado de 1 toque de una transición en conflicto (`RF-05.3`, `QA 1`). */
    async dumpOfflineConflict(clientMutationId) {
      if (this.offlineManager === null || !clientMutationId) {
        return;
      }

      this.offlineDumpInProgressId = String(clientMutationId);
      this.offlineNotice = '';

      try {
        await this.offlineManager.dumpConflict(clientMutationId);
        this.feedbackMessage = 'Trabajo volcado al expediente como Nota Interna de Taller. Conflicto cerrado.';
      } catch (error) {
        this.errorMessage = error?.message || 'No se pudo volcar el conflicto al expediente. Inténtalo de nuevo.';
      } finally {
        this.offlineDumpInProgressId = '';
        await this.refreshOfflineState();
      }
    },

    /** Estado local de una parada: `''`, `PENDING` o `CONFLICT`. */
    offlineStopState(incident) {
      const stopId = Number(incident?.id ?? 0);
      const items = this.offlineQueueItems.filter(
        (item) => item?.stop_type === 'INCIDENT' && Number(item?.stop_id) === stopId
      );

      if (items.some((item) => item?.status === MUTATION_STATUS.CONFLICT)) {
        return 'CONFLICT';
      }

      return items.length > 0 ? 'PENDING' : '';
    },

    /** Etiqueta de la insignia «⏳ Pendiente de sincronizar» (`EARS 03.3.2`). */
    offlineStopBadgeLabel(incident) {
      const state = this.offlineStopState(incident);
      if (state === 'CONFLICT') return '⚠️ Conflicto sin volcar';
      if (state === 'PENDING') return '⏳ Pendiente de sincronizar';

      return '';
    },

    /** Tono de esa insignia: semántica de aviso o de error, nunca un color nuevo. */
    offlineStopBadgeStyle(incident) {
      if (this.offlineStopState(incident) === 'CONFLICT') {
        return {
          backgroundColor: 'var(--color-error-bg)',
          borderColor: 'var(--color-error-border)',
          color: 'var(--color-error-text)'
        };
      }

      return {
        backgroundColor: 'var(--color-warning-bg)',
        borderColor: 'var(--color-warning-border)',
        color: 'var(--color-warning-text)'
      };
    },

    /** Tipo de máquina del paquete local (necesario para la puerta sanitaria). */
    offlineMachineType(incident) {
      return String(incident?.machine?.machine_type ?? incident?.machine?.type ?? '').toUpperCase();
    },

    /** ¿La operación que se va a encolar exige precinto físico in situ? (`EARS 03.4`). */
    offlineSealRequiredForPause() {
      return requiresSanitarySeal(
        this.offlinePauseOperation,
        {},
        this.offlineMachineType(this.offlinePauseIncident)
      );
    },

    /** Bloquea las operaciones que exigen red en vivo (`RF-03.2`, `D1`). */
    guardOnlineOnly(operationLabel) {
      if (!this.isOfflineMode && !this.isBrowserOffline()) {
        return true;
      }

      this.errorMessage = `${operationLabel} requiere conexión en vivo con el servidor. `
        + 'Recupera cobertura para completarla; el trabajo ya guardado en la bandeja no se pierde.';

      return false;
    },

    /**
     * Sincronización previa obligatoria antes de pausar o cerrar turno
     * (`EARS 06.2`, `QA 5`).
     *
     * @returns {Promise<boolean>} `true` si la jornada puede cambiar de estado.
     */
    async ensureOfflineQueueReadyForShift() {
      if (this.isOfflineMode || this.isBrowserOffline()) {
        this.shiftError = 'Los cambios de turno requieren conexión en vivo con el servidor.';
        return false;
      }

      await this.refreshOfflineState();

      if (this.offlineQueue.total === 0) {
        return true;
      }

      const summary = await this.syncOfflineQueue({ silent: true });
      await this.refreshOfflineState();

      if (summary?.interrupted === 'reauth_required' || this.offlineQueue.conflicts > 0) {
        this.shiftError = 'Antes de pausar o cerrar turno debes volcar al expediente las intervenciones en conflicto.';
        this.showOfflineTray = true;
        return false;
      }

      if (this.offlineQueue.total > 0) {
        this.shiftError = 'No se han podido sincronizar todas las intervenciones pendientes. Reintenta cuando recuperes cobertura.';
        this.showOfflineTray = true;
        return false;
      }

      return true;
    },

    /** Abre la pausa simplificada sin conexión (repuesto o falta de acceso). */
    openOfflinePauseModal(incident, operationType) {
      this.offlinePauseIncident = incident;
      this.offlinePauseOperation = operationType;
      this.offlinePauseCategory = '';
      this.offlinePauseReason = '';
      this.offlinePauseError = '';
      this.sanitarySealConfirmed = false;
      this.showOfflinePauseModal = true;
      this.selectedIncident = incident;
    },

    closeOfflinePauseModal() {
      this.showOfflinePauseModal = false;
      this.offlinePauseIncident = null;
      this.offlinePauseError = '';
      this.offlinePauseReason = '';
      this.sanitarySealConfirmed = false;
    },

    /** Valida y encola la pausa sin conexión (`RF-03.1.2`, `EARS 03.4`). */
    async submitOfflinePause() {
      const incident = this.offlinePauseIncident;
      const reason = this.offlinePauseReason.trim();
      const isPendingInfo = this.offlinePauseOperation === 'PAUSE_INCIDENT_PENDING_INFO';
      const minimum = isPendingInfo ? 20 : 10;

      if (isPendingInfo && !this.offlinePauseCategory) {
        this.offlinePauseError = 'Selecciona la causa tipificada de la falta de acceso o información.';
        return;
      }

      if (reason.length < minimum) {
        this.offlinePauseError = `La justificación debe tener al menos ${minimum} caracteres reales para quedar trazada (Art. V.1).`;
        return;
      }

      if (this.offlineSealRequiredForPause() && this.sanitarySealConfirmed !== true) {
        this.offlinePauseError = OFFLINE_MESSAGES.SANITARY_SEAL_REQUIRED;
        return;
      }

      if (!this.canRegisterOfflineActions) {
        this.offlinePauseError = this.offlineValidity?.errorMessage
          || 'La copia local de la ruta ha caducado: conéctate para actualizarla.';
        return;
      }

      this.isOfflinePausing = true;
      this.offlinePauseError = '';

      const payload = isPendingInfo
        ? { reason_category: this.offlinePauseCategory, reason_text: reason }
        : { pending_parts_reason: reason };

      try {
        await this.enqueueOfflineAction({
          incident,
          stopType: 'INCIDENT',
          stopId: incident.id,
          operationType: this.offlinePauseOperation,
          payload,
          sanitarySealConfirmed: this.sanitarySealConfirmed === true
        });

        incident.status = isPendingInfo ? INCIDENT_STATUSES.PENDING_INFO : INCIDENT_STATUSES.PENDING_PARTS;
        if (!isPendingInfo) {
          incident.pending_parts_reason = reason;
        }

        this.feedbackMessage = 'Pausa guardada en el dispositivo: ⏳ pendiente de sincronizar.';
        this.closeOfflinePauseModal();
      } catch (error) {
        this.offlinePauseError = error?.message || 'No se pudo guardar la pausa sin conexión.';
      } finally {
        this.isOfflinePausing = false;
      }
    },

    /** Abre el comentario in situ sin conexión (`RF-03.1.5`). */
    openOfflineCommentModal(incident) {
      this.offlineCommentIncident = incident;
      this.offlineCommentText = '';
      this.offlineCommentInternal = true;
      this.offlineCommentPhoto = null;
      this.offlineCommentPhotoError = '';
      this.offlineCommentError = '';
      this.showOfflineCommentModal = true;
    },

    closeOfflineCommentModal() {
      this.showOfflineCommentModal = false;
      this.offlineCommentIncident = null;
      this.offlineCommentText = '';
      this.offlineCommentPhoto = null;
      this.offlineCommentPhotoError = '';
      this.offlineCommentError = '';
    },

    /** Captura la evidencia fotográfica del selector nativo (sin red). */
    handleOfflineCommentPhoto(event) {
      const file = event?.target?.files?.[0] ?? null;
      this.offlineCommentPhoto = file;
      this.offlineCommentPhotoError = '';
    },

    /** Encola el comentario con su cuota fotográfica (`D3`, `QA 3`). */
    async submitOfflineComment() {
      const incident = this.offlineCommentIncident;
      const text = this.offlineCommentText.trim();

      if (text.length < 3) {
        this.offlineCommentError = 'Escribe el comentario antes de guardarlo en la bandeja.';
        return;
      }

      if (!this.canRegisterOfflineActions) {
        this.offlineCommentError = this.offlineValidity?.errorMessage
          || 'La copia local de la ruta ha caducado: conéctate para actualizarla.';
        return;
      }

      this.isOfflineCommenting = true;
      this.offlineCommentError = '';

      try {
        await this.enqueueOfflineAction({
          incident,
          stopType: 'INCIDENT',
          stopId: incident.id,
          operationType: 'ADD_INCIDENT_COMMENT',
          payload: {
            comment_text: text,
            is_internal: this.offlineCommentInternal === true
          },
          photoFile: this.offlineCommentPhoto
        });

        if (incident) {
          incident.comments_count = this.commentCountOf(incident) + 1;
        }

        this.feedbackMessage = 'Comentario guardado en el dispositivo: ⏳ pendiente de sincronizar.';
        this.closeOfflineCommentModal();
      } catch (error) {
        this.offlineCommentError = error?.message || 'No se pudo guardar el comentario sin conexión.';
      } finally {
        this.isOfflineCommenting = false;
      }
    },

    /**
     * Loads assigned route incidents from backend (RF-07).
     *
     * Con red guarda además la copia local de la jornada (`EARS 02.1`). Si la
     * petición cae por falta de cobertura, la vista se hidrata desde esa copia
     * en lugar de quedarse en blanco (`EARS 01.2`, `EARS 02.4`).
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
        this.isOfflineMode = false;
        this.offlineNotice = '';

        // La copia local se guarda con el paquete completo (preventivas, catálogo
        // y furgoneta): la ruta sola no basta para trabajar en el sótano.
        await this.refreshOfflineSnapshotFromServer();
      } catch (err) {
        if (this.isNetworkFailure(err)) {
          this.isOfflineMode = true;
          const hydrated = await this.hydrateOfflineRoute();

          if (hydrated) {
            this.offlineNotice = 'Estás sin conexión: tu ruta se muestra desde la copia local del dispositivo. '
              + 'Los cambios se enviarán al recuperar cobertura.';
          } else {
            this.errorMessage = 'No hay conexión ni copia local de tu ruta en este dispositivo. '
              + 'Conéctate una vez para descargarla.';
          }
        } else {
          this.errorMessage = err?.message || 'No se pudo sincronizar la ruta de averías. Comprueba tu conexión.';
        }
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
      // Reclamar una máquina fuera de la ruta exige verificar la titularidad en
      // vivo (`RF-03.2`, `D1`, Art. V.3).
      if (!this.guardOnlineOnly('Registrar una avería oportunista')) {
        return;
      }

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
      // El acceso inicial exige red en vivo (`D1`); sin ella solo cabe reutilizar
      // una sesión ya restaurada en el dispositivo.
      if (this.isBrowserOffline()) {
        this.loginError = 'Necesitas conexión a la red para identificarte. La sesión ya abierta sigue funcionando sin cobertura.';
        return;
      }

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

        // EARS 06.3 y Art. V.4: la cola pendiente pertenece a UN técnico. Si el
        // dispositivo guarda trabajo de otro operario, no se mezcla ni se envía
        // con credenciales ajenas: se nombra al titular que debe entrar.
        const queueOwnerId = Number(this.offlineReauthOwner?.technician_id ?? 0);
        if (queueOwnerId > 0 && Number(response.user.id) !== queueOwnerId) {
          const ownerEmail = this.offlineReauthOwner?.technician_email
            || this.offlineReauthOwner?.technician_name
            || 'el técnico titular de la cola';
          store.clearSession();
          this.loginError = `Este dispositivo tiene trabajo sin sincronizar de ${ownerEmail}. `
            + 'Identifícate con esa misma cuenta para enviarlo antes de cambiar de usuario.';
          return;
        }

        store.setInternalSession(response.user, response.token);
        this.loginEmail = '';
        this.loginPassword = '';
        this.offlineReauthOwner = null;
        await this.loadRoute();

        if (this.offlineQueue.total > 0) {
          await this.syncOfflineQueue({ silent: false });
        }
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
     * Cierra la sesión del técnico sin dejar trabajo atrapado (`EARS 06.1`, `EARS 06.5`).
     *
     * Con acciones pendientes o en conflicto la salida se bloquea y se abre la
     * bandeja con el recuento exacto. Sin cola se borra la copia local del técnico
     * —paradas, catálogos y bandeja— y el dispositivo queda limpio para otro turno
     * (Art. V.4).
     */
    async handleLogout() {
      await this.refreshOfflineState();

      if (this.offlineQueue.total > 0) {
        const pending = this.offlineQueue.pending + this.offlineQueue.syncing;
        this.errorMessage = `No puedes cerrar sesión: ${this.offlineQueue.total} operación(es) `
          + `sin sincronizar (${pending} pendiente(s), ${this.offlineQueue.conflicts} en conflicto). `
          + 'Sincroniza y vuelca los conflictos antes de salir.';
        this.showOfflineTray = true;
        return;
      }

      try {
        const technicianId = this.offlineTechnicianId();
        if (technicianId > 0 && this.offlineStorage !== null) {
          await this.offlineStorage.clearTechnician(technicianId);
        }
      } catch (error) {
        // La sesión se cierra igualmente: sin la cuenta, la copia local no sirve.
      }

      store.clearSession();
      this.offlineSnapshot = null;
      this.offlineQueueItems = [];
      this.offlineQueue = { pending: 0, syncing: 0, conflicts: 0, total: 0, photoCount: 0, photoBytes: 0 };
      this.incidents = [];
      this.feedbackMessage = '';
      this.errorMessage = '';
      this.offlineNotice = '';
      this.showOfflineTray = false;
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
     * Tono del chip de disponibilidad del repuesto en la furgoneta (Módulo 23, QA 3).
     *
     * `READY_IN_VAN` afirma cobertura completa; `SHARED_IN_VAN` avisa de que el
     * saldo se comparte con otras averías del mismo técnico. Es una lectura: el
     * técnico no cambia nada al pulsarla porque no es un control.
     */
    vanStockReadinessChipClass(status) {
      return String(status || '') === 'SHARED_IN_VAN'
        ? 'vg-chip vg-chip-warning'
        : 'vg-chip vg-chip-success';
    },

    /**
     * Starts or resumes intervention on an incident (RF-07 / EARS 7.1, 7.3).
     * @param {Object} incident
     */
    async startIntervention(incident) {
      if (this.actionInProgressId) return;

      if (this.shiftStatus !== 'ON_SHIFT') {
        const msg = this.shiftStatus === 'MEAL_BREAK'
          ? 'No puedes iniciar una intervención mientras estás en pausa de comida. Reanuda tu turno primero.'
          : 'Debes iniciar tu turno antes de comenzar una intervención.';
        this.errorMessage = msg;
        if (typeof window !== 'undefined' && typeof window.scrollTo === 'function') {
          window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        return;
      }

      this.actionInProgressId = incident.id;
      this.feedbackMessage = '';
      this.errorMessage = '';

      // Sin cobertura la acción se guarda con su instante real y se refleja al
      // instante en la parada local (`EARS 03.1`, `EARS 03.3.2`).
      if (this.isOfflineMode || this.isBrowserOffline()) {
        await this.queueStartOffline(incident);
        this.actionInProgressId = null;
        return;
      }

      try {
        const res = await api.technician.startIncident(incident.id);
        const startedAt = res?.data?.started_at || new Date().toISOString();

        // Update local object immediately for smooth responsive mobile UX
        incident.status = INCIDENT_STATUSES.IN_PROGRESS;
        incident.started_at = startedAt;

        this.feedbackMessage = 'Intervención iniciada en máquina ' + (incident.machine?.code || incident.ticket_code) + '.';
        this.$emit('started', { incidentId: incident.id, startedAt });
      } catch (err) {
        // Una caída de red en el umbral de la máquina no pierde la pulsación:
        // se convierte en la primera operación de la cadena local (`QA 4`).
        if (this.isNetworkFailure(err)) {
          await this.queueStartOffline(incident);
        } else {
          this.errorMessage = err?.message || 'Error al iniciar la intervención. Revisa el estado de la máquina.';
        }
      } finally {
        this.actionInProgressId = null;
      }
    },

    /** Encola el inicio/reanudación de una intervención (`D1`, `D5`). */
    async queueStartOffline(incident) {
      try {
        await this.enqueueOfflineAction({
          incident,
          stopType: 'INCIDENT',
          stopId: incident.id,
          operationType: 'START_INCIDENT',
          payload: {}
        });

        const startedAt = new Date().toISOString();
        incident.status = INCIDENT_STATUSES.IN_PROGRESS;
        incident.started_at = startedAt;
        this.feedbackMessage = 'Intervención iniciada sin conexión: ⏳ pendiente de sincronizar.';
        this.$emit('started', { incidentId: incident.id, startedAt, offline: true });
      } catch (error) {
        this.errorMessage = error?.message || 'No se pudo guardar el inicio de la intervención sin conexión.';
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

      // El hilo online requiere red; sin cobertura se publica la nota in situ
      // con la misma garantía de cola y cuota fotográfica (`RF-03.1.5`, `D3`).
      if (this.isOfflineMode || this.isBrowserOffline()) {
        this.openOfflineCommentModal(incident);
        return;
      }

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
      // Sin red el modal estructurado de repuestos no puede consultar el catálogo
      // ni confirmar piezas: se usa el formulario local equivalente (`RF-03.1.2`).
      if (this.isOfflineMode || this.isBrowserOffline()) {
        this.openOfflinePauseModal(incident, 'PAUSE_INCIDENT_PARTS');
        return;
      }

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

      // Sin red se usa el formulario local con la misma causa tipificada y el
      // umbral de 20 caracteres reales del Art. V.1 (`RF-03.1.2`).
      if (this.isOfflineMode || this.isBrowserOffline()) {
        this.openOfflinePauseModal(incident, 'PAUSE_INCIDENT_PENDING_INFO');
        return;
      }

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

      if (this.shiftStatus !== 'ON_SHIFT') {
        const msg = this.shiftStatus === 'MEAL_BREAK'
          ? 'No puedes reanudar una intervención mientras estás en pausa de comida. Reanuda tu turno primero.'
          : 'Debes iniciar tu turno antes de reanudar una intervención.';
        this.errorMessage = msg;
        this.pendingInfoResumeError = msg;
        if (typeof window !== 'undefined' && typeof window.scrollTo === 'function') {
          window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        return;
      }

      this.isResumingPendingInfo = true;
      this.pendingInfoResumeError = '';
      this.errorMessage = '';

      if (this.isOfflineMode || this.isBrowserOffline()) {
        await this.queueResumeOffline(incident);
        this.isResumingPendingInfo = false;
        return;
      }

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
        if (this.isNetworkFailure(err)) {
          await this.queueResumeOffline(incident);
        } else {
          this.pendingInfoResumeError = err?.message || 'No se pudo reanudar la intervención. Inténtalo de nuevo.';
        }
      } finally {
        this.isResumingPendingInfo = false;
      }
    },

    /** Encola la reanudación de una pausa por bloqueo de sede (`D1`, `QA 4`). */
    async queueResumeOffline(incident) {
      try {
        await this.enqueueOfflineAction({
          incident,
          stopType: 'INCIDENT',
          stopId: incident.id,
          operationType: 'RESUME_INCIDENT_PENDING_INFO',
          payload: {}
        });

        incident.status = INCIDENT_STATUSES.IN_PROGRESS;
        incident.paused_at = null;
        if (!incident.started_at) {
          incident.started_at = new Date().toISOString();
        }

        this.feedbackMessage = 'Intervención reanudada sin conexión: ⏳ pendiente de sincronizar.';
        this.$emit('pending-info-resumed', {
          incidentId: incident.id,
          status: INCIDENT_STATUSES.IN_PROGRESS,
          offline: true
        });
      } catch (error) {
        this.pendingInfoResumeError = error?.message || 'No se pudo guardar la reanudación sin conexión.';
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

      // Sin red no se puede consultar el sub-bloque de reintegro. El cierre offline
      // se admite sin veredicto y el servidor resolverá la colisión parcial al
      // sincronizar, anexando el dictamen como Nota Interna (`EARS 05.2`, `QA 2`).
      if (this.isOfflineMode || this.isBrowserOffline()) {
        this.refundRequests = [];
        this.hasPendingRefundVerdict = false;
        this.refundInspectionFailed = false;
        this.resolveRefundData = { isValid: true };
        this.isLoadingRefundInspection = false;
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

      // Sin cobertura la resolución viaja entera a la bandeja: diagnóstico, acción,
      // piezas, reintegro y firma. El Albarán A4 solo se habilita cuando el servidor
      // confirma el cierre (`EARS 03.2.2`, `QA 4`).
      if (this.isOfflineMode || this.isBrowserOffline()) {
        await this.queueResolutionOffline({ incident: this.selectedIncident, payload, diag, act, hasParts, replacedParts });
        this.isResolving = false;
        return;
      }

      try {
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
        // Caída de red en el último segundo: el parte íntegro se guarda en la
        // bandeja en lugar de perderse o quedar a medias.
        if (this.isNetworkFailure(err)) {
          await this.queueResolutionOffline({ incident: this.selectedIncident, payload, diag, act, hasParts, replacedParts });
          return;
        }
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
     * Guarda la resolución completa en la bandeja local (`EARS 05.3`, `QA 4`).
     *
     * El aviso del Albarán se muestra en positivo mientras el envío está pendiente:
     * el documento oficial solo existe cuando el servidor sella la resolución.
     */
    async queueResolutionOffline({ incident, payload, diag, act, hasParts, replacedParts }) {
      try {
        await this.enqueueOfflineAction({
          incident,
          stopType: 'INCIDENT',
          stopId: incident.id,
          operationType: 'RESOLVE_INCIDENT',
          payload
        });

        const resolvedAt = new Date().toISOString();
        incident.status = INCIDENT_STATUSES.RESOLVED;
        this.feedbackMessage = 'Resolución guardada sin conexión. '
          + '📄 Albarán oficial A4 disponible al sincronizar con el servidor.';
        this.$emit('resolved', {
          incidentId: incident.id,
          diagnosis: diag,
          action: act,
          resolvedAt,
          replaced_parts_declared: hasParts,
          replaced_parts: replacedParts,
          service_sheet: null,
          offline: true
        });

        this.closeResolveModal();
        this.incidents = this.incidents.filter(inc => inc.id !== incident.id);
      } catch (error) {
        this.resolveError = error?.message || 'No se pudo guardar la resolución sin conexión.';
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

      // Sin cobertura, el checklist se cumplimenta contra la copia local y se
      // encola con su operación real (`RF-03.1.4`, `EARS 03.1`).
      if (this.isOfflineMode || this.isBrowserOffline()) {
        this.openOfflineChecklistModal(order);
        this.$emit('open-preventive-checklist', order);
        return;
      }

      if (order?.order_type === 'REINSPECTION' || order?.status === 'REINSPECTION_PENDING') {
        this.showPreventiveReinspectionModal = true;
      } else {
        this.showPreventiveChecklistModal = true;
      }
      this.$emit('open-preventive-checklist', order);
    },

    /** Abre el checklist de la orden elegida en el selector local (`RF-03.1.4`). */
    openSelectedOfflineChecklist() {
      const selectedId = Number(this.offlinePreventiveSelection);
      const order = this.offlinePreventiveOrders.find((candidate) => Number(candidate.id) === selectedId);

      if (!order) {
        this.errorMessage = 'Selecciona una orden preventiva asignada de tu copia local.';
        return;
      }

      this.openOfflineChecklistModal(order);
    },

    /** Abre el checklist preventivo de la copia local (`RF-03.1.4`). */
    openOfflineChecklistModal(order) {
      const isReinspection = order?.order_type === 'REINSPECTION'
        || order?.status === 'REINSPECTION_PENDING';
      const checklists = this.offlineSnapshot?.preventive_checklists_by_order ?? {};
      const entry = checklists[String(order?.id ?? '')] ?? checklists[order?.id] ?? null;
      const items = Array.isArray(entry?.items) ? entry.items : [];

      if (!isReinspection && items.length === 0) {
        this.errorMessage = 'La copia local de la ruta no contiene el checklist de esta orden. '
          + 'Conéctate para descargarlo antes de inspeccionarla sin cobertura.';
        return;
      }

      this.offlineChecklistOrder = order;
      this.offlineChecklistOperation = isReinspection ? 'REINSPECT_PREVENTIVE' : 'COMPLETE_PREVENTIVE';
      this.offlineChecklistItems = items.map((item) => ({
        item_code: String(item?.item_code ?? ''),
        title: String(item?.item_description ?? item?.item_code ?? 'Punto de verificación'),
        is_critical: item?.is_critical === true,
        status: '',
        observations: ''
      }));
      this.offlineChecklistTemperature = '';
      this.offlineChecklistGeneralNotes = '';
      this.offlineChecklistReinspectionNotes = '';
      this.offlineChecklistError = '';
      this.offlineChecklistSealConfirmed = false;
      this.showOfflineChecklistModal = true;
    },

    closeOfflineChecklistModal() {
      this.showOfflineChecklistModal = false;
      this.offlineChecklistOrder = null;
      this.offlineChecklistItems = [];
      this.offlineChecklistError = '';
    },

    /** Valida y encola la inspección preventiva sin cobertura (`D1`, `QA 1`). */
    async submitOfflineChecklist() {
      const order = this.offlineChecklistOrder;
      if (!order?.id) {
        return;
      }

      const temperatureRaw = String(this.offlineChecklistTemperature ?? '').trim();
      const temperature = temperatureRaw === '' ? null : Number(temperatureRaw);

      if (temperatureRaw !== '' && !Number.isFinite(temperature)) {
        this.offlineChecklistError = 'La temperatura debe ser un número en grados Celsius.';
        return;
      }

      if (!this.canRegisterOfflineActions) {
        this.offlineChecklistError = this.offlineValidity?.errorMessage
          || 'La copia local de la ruta ha caducado: conéctate para actualizarla.';
        return;
      }

      let payload = {
        temperature_measured: temperature,
        general_notes: this.offlineChecklistGeneralNotes.trim() || undefined
      };

      if (this.offlineChecklistIsReinspection) {
        const notes = this.offlineChecklistReinspectionNotes.trim();
        if (notes.length < 10) {
          this.offlineChecklistError = 'Detalla la comprobación técnica realizada (mínimo 10 caracteres).';
          return;
        }

        const machineType = this.offlineChecklistMachineType;
        if (PERISHABLE_MACHINE_TYPES.includes(machineType) && Number.isFinite(temperature) && temperature > 4.0) {
          this.offlineChecklistError = 'No se puede declarar conforme una reinspección de perecederos por encima de 4,0 °C (Art. II).';
          return;
        }

        payload = {
          temperature_measured: temperature,
          notes,
          items: [{
            item_code: 'THERMAL_STABILITY',
            status: Number.isFinite(temperature) && temperature > 4.0 ? 'FAIL' : 'PASS',
            observations: Number.isFinite(temperature)
              ? `Comprobación térmica in situ sin conexión: ${temperature} °C.`
              : 'Comprobación térmica in situ sin conexión.'
          }]
        };
      } else {
        // Todo punto debe quedar respondido; un desfavorable exige su justificación
        // y, en perecederos, el precinto físico (Art. II, `QA 1`).
        for (const item of this.offlineChecklistItems) {
          if (!['PASS', 'WARN', 'FAIL'].includes(String(item.status ?? ''))) {
            this.offlineChecklistError = `Debes responder el punto «${item.title}».`;
            return;
          }

          if (['WARN', 'FAIL'].includes(String(item.status)) && String(item.observations ?? '').trim() === '') {
            this.offlineChecklistError = `Justifica el resultado desfavorable de «${item.title}».`;
            return;
          }
        }

        if (this.offlineChecklistSealRequired && this.offlineChecklistSealConfirmed !== true) {
          this.offlineChecklistError = OFFLINE_MESSAGES.SANITARY_SEAL_REQUIRED;
          return;
        }

        payload = {
          temperature_measured: temperature,
          general_notes: this.offlineChecklistGeneralNotes.trim() || undefined,
          items: this.offlineChecklistItems.map((item) => ({
            item_code: item.item_code,
            status: item.status,
            observations: String(item.observations ?? '').trim() || null
          }))
        };
      }

      this.isOfflineChecklistSubmitting = true;
      this.offlineChecklistError = '';

      const machineRef = {
        ticket_code: order.order_code ?? null,
        machine: {
          id: order.machine_id ?? null,
          code: order.machine_code ?? null,
          machine_type: order.machine_type ?? null
        }
      };

      try {
        // Completar exige que la orden esté formalmente en inspección: si el
        // técnico no llegó a pulsar «Iniciar» con cobertura, el arranque entra
        // delante en la misma cadena FIFO (`RF-03.1.4`, `QA 4`).
        if (!this.offlineChecklistIsReinspection && String(order.status ?? '') !== 'IN_INSPECTION') {
          await this.enqueueOfflineAction({
            stopType: 'PREVENTIVE_ORDER',
            stopId: order.id,
            operationType: 'START_PREVENTIVE',
            payload: {},
            ticketOrOrderCode: order.order_code ?? null,
            incident: machineRef
          });
        }

        await this.enqueueOfflineAction({
          stopType: 'PREVENTIVE_ORDER',
          stopId: order.id,
          operationType: this.offlineChecklistOperation,
          payload,
          ticketOrOrderCode: order.order_code ?? null,
          incident: machineRef,
          sanitarySealConfirmed: this.offlineChecklistSealConfirmed === true
        });

        order.status = 'COMPLETED';
        this.feedbackMessage = 'Inspección preventiva guardada sin conexión: ⏳ pendiente de sincronizar.';
        this.closeOfflineChecklistModal();
      } catch (error) {
        this.offlineChecklistError = error?.message || 'No se pudo guardar la inspección sin conexión.';
      } finally {
        this.isOfflineChecklistSubmitting = false;
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
          <div style="margin-top: 10px;">
            <a
              href="/docs/guia_rapida_por_rol_a4.html#guia-tecnico"
              target="_blank"
              rel="noopener"
              class="vg-btn vg-btn-secondary"
              style="height: 28px; font-size: 12px; padding: 0 10px; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; border-radius: var(--radius-interactive, 4px);"
              title="Abrir guía rápida del Técnico de Campo (A4 imprimible)"
              aria-label="Guía rápida del Técnico de Campo"
              data-testid="link-role-guide-login-technician"
            >
              <span>📖 Guía rápida del técnico</span>
            </a>
          </div>
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
              href="/docs/guia_rapida_por_rol_a4.html#guia-tecnico"
              target="_blank"
              rel="noopener"
              class="vg-btn vg-btn-secondary"
              style="height: 36px; padding: 0 10px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; text-decoration: none;"
              title="Guía rápida del Técnico de Campo (A4 imprimible): flujo de ruta, intervenciones y diagnóstico"
              aria-label="Abrir guía rápida del técnico en formato A4"
              data-testid="btn-role-guide-technician"
            >
              <span>📖</span>
            </a>
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

        <!-- Barra de estado y bandeja sin conexión (Módulo 24, RF-02.2 a RF-02.4) -->
        <OfflineSyncTrayModal
          :visible="isAuthenticated"
          :open="showOfflineTray"
          :connection-status="offlineConnectionStatus"
          :validity="offlineValidity"
          :queue="offlineQueue"
          :items="offlineQueueItems"
          :progress="offlineSyncProgress"
          :reauth-owner="offlineReauthOwner"
          :dumping-mutation-id="offlineDumpInProgressId"
          @update:open="showOfflineTray = $event"
          @sync-now="syncOfflineQueue({ silent: false })"
          @dump-conflict="dumpOfflineConflict"
          @reauth="requestOfflineReauth"
        />

        <!-- Barra de Control de Turno y Jornada (Módulo 21, RF-03) -->
        <div
          data-testid="shift-control-bar"
          style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px 14px; margin-bottom: 14px; display: flex; flex-direction: column; align-items: stretch; gap: 8px;"
        >
          <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
          <div style="display: flex; align-items: center; gap: 8px;">
            <span
              style="width: 10px; height: 10px; border-radius: 50%; display: inline-block;"
              :style="{
                backgroundColor: shiftStatus === 'ON_SHIFT' ? 'var(--color-success)' : (shiftStatus === 'MEAL_BREAK' ? 'var(--color-warning)' : 'var(--color-ink-muted)')
              }"
            ></span>
            <span style="font-size: 13px; font-weight: 600; color: var(--color-slate);">
              {{ shiftStatus === 'ON_SHIFT' ? 'En turno' : (shiftStatus === 'MEAL_BREAK' ? 'Pausa comida' : 'Fuera de turno') }}
            </span>
          </div>

          <div style="display: flex; flex-wrap: wrap; gap: 6px;">
            <button
              v-if="shiftStatus === 'OFF_SHIFT'"
              type="button"
              class="vg-btn vg-btn-primary"
              style="height: 32px; font-size: 12px; padding: 0 12px; border-radius: var(--radius-interactive);"
              :disabled="isShiftLoading"
              @click="startShift"
            >
              ▶ Iniciar Turno
            </button>

            <template v-else-if="shiftStatus === 'ON_SHIFT'">
              <button
                type="button"
                class="vg-btn vg-btn-secondary"
                style="height: 32px; font-size: 12px; padding: 0 10px; border-radius: var(--radius-interactive);"
                :disabled="isShiftLoading"
                @click="pauseMealShift"
                title="Pausar para comida"
              >
                🥪 Pausa Comida
              </button>
              <button
                type="button"
                class="vg-btn vg-btn-secondary"
                style="height: 32px; font-size: 12px; padding: 0 10px; border-radius: var(--radius-interactive); color: var(--color-urgency-critical);"
                :disabled="isShiftLoading"
                @click="openShiftHandoverModal"
                title="Cerrar turno y relevo"
              >
                ⏹ Cerrar Turno
              </button>
            </template>

            <template v-else-if="shiftStatus === 'MEAL_BREAK'">
              <button
                type="button"
                class="vg-btn vg-btn-primary"
                style="height: 32px; font-size: 12px; padding: 0 10px; border-radius: var(--radius-interactive);"
                :disabled="isShiftLoading"
                @click="resumeMealShift"
              >
                ▶ Reanudar
              </button>
              <button
                type="button"
                class="vg-btn vg-btn-secondary"
                style="height: 32px; font-size: 12px; padding: 0 10px; border-radius: var(--radius-interactive); color: var(--color-urgency-critical);"
                :disabled="isShiftLoading"
                @click="openShiftHandoverModal"
              >
                ⏹ Cerrar
              </button>
            </template>
          </div>
          </div>

          <!-- Contador diario y acceso a «Mis Horas» (Módulo 22, RF-07.1 y RF-07.2) -->
          <div
            data-testid="shift-today-summary"
            style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding-top: 8px; border-top: 1px solid var(--color-hairline);"
          >
            <span style="font-family: var(--font-body); font-size: 12px; color: var(--color-ink-muted);">
              Hoy:
              <strong style="font-family: var(--font-display); font-size: 14px; font-weight: 500; color: var(--color-slate);">
                {{ (todaySummary && todaySummary.effective_formatted) || '00h 00m' }}
              </strong>
              efectivas ·
              <strong style="font-family: var(--font-display); font-size: 14px; font-weight: 500; color: var(--color-slate);">
                {{ (todaySummary && todaySummary.meal_break_formatted) || '00h 00m' }}
              </strong>
              de comida
            </span>

            <button
              type="button"
              class="vg-btn vg-btn-ghost vg-btn-sm"
              data-testid="btn-open-my-hours"
              @click="openMyHours"
            >
              ⏱️ Mis Horas
            </button>
          </div>
        </div>

        <div v-if="shiftError" style="color: var(--color-urgency-critical); font-size: 12px; margin-bottom: 10px; padding: 6px 10px; background: var(--color-urgency-critical-bg); border-radius: var(--radius-interactive);">
          {{ shiftError }}
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
          <!-- Operaciones online-only del bloque preventivo (RF-03.2, QA 4).
               Sin red se puede trabajar lo ya asignado, pero no reclamar órdenes
               libres: la unicidad de titularidad se verifica en el servidor. -->
          <div
            v-if="isOfflineMode"
            data-testid="offline-preventive-notice"
            role="status"
            style="background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); border-radius: var(--radius-card); padding: 10px 12px; font-family: var(--font-body); font-size: 12px; line-height: 1.45; margin-bottom: 12px;"
          >
            🟠 Sin conexión: solo puedes inspeccionar las órdenes preventivas ya asignadas de tu ruta.
            El reclamo de órdenes libres y la recepción de órdenes de carga requieren conexión en vivo.
          </div>

          <div
            v-if="isOfflineMode && offlinePreventiveOrders.length > 0"
            data-testid="offline-preventive-launcher"
            style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 12px; margin-bottom: 12px;"
          >
            <label for="offline-preventive-order" style="display: block; font-family: var(--font-body); font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;">
              Inspeccionar sin conexión
            </label>
            <select
              id="offline-preventive-order"
              v-model="offlinePreventiveSelection"
              class="vg-select"
              style="min-height: 44px; width: 100%; margin-bottom: 10px;"
            >
              <option value="">Selecciona una orden asignada…</option>
              <option v-for="order in offlinePreventiveOrders" :key="order.id" :value="String(order.id)">
                {{ order.order_code }} · {{ order.machine_code }} ({{ order.status }})
              </option>
            </select>
            <button
              type="button"
              class="vg-btn vg-btn-primary"
              style="width: 100%; min-height: 44px; font-weight: 600;"
              @click="openSelectedOfflineChecklist"
            >
              Abrir checklist sin conexión
            </button>
          </div>

          <TechnicianPreventiveRouteTab
            :enabled-locations="enabledLocations"
            :is-offline="isOfflineMode"
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
              <div
                v-if="isOfflineMode"
                role="status"
                style="background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); border-radius: var(--radius-interactive); padding: 8px 10px; font-family: var(--font-body); font-size: 12px; line-height: 1.4; margin-bottom: 10px;"
              >
                El saldo visible incluye los descuentos provisionales de tu bandeja.
                Confirmar la recepción de una orden de carga requiere conexión en vivo.
              </div>
              <TechnicianVanStockPanel :is-offline="isOfflineMode" />
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

        <!-- Aviso de modo sin conexión y trabajo en cola (Módulo 24, RF-02.2) -->
        <div
          v-if="offlineNotice"
          data-testid="offline-route-notice"
          role="status"
          style="background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); padding: 10px 14px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 13px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; gap: 10px;"
        >
          <span>ℹ️ {{ offlineNotice }}</span>
          <button type="button" @click="offlineNotice = ''" style="background: none; border: none; font-size: 14px; color: var(--color-warning-text); cursor: pointer;">×</button>
        </div>

        <!-- Albarán A4 diferido mientras la resolución espera en la cola (QA 4) -->
        <div
          v-if="hasPendingServiceSheets"
          data-testid="offline-service-sheet-pending"
          role="status"
          style="background-color: var(--color-info-bg); border: 1px solid var(--color-info-border); color: var(--color-info-text); padding: 10px 14px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 13px; margin-bottom: 12px; line-height: 1.4;"
        >
          📄 Albarán oficial A4 disponible al sincronizar con el servidor.
          <span style="display: block; margin-top: 4px; font-size: 12px;">
            Resolución(es) en cola: {{ pendingResolutionStops.length }}. El documento se sella cuando el servidor confirma el cierre.
          </span>
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
                <!-- Insignia de trabajo local pendiente de envío (EARS 03.3.2) -->
                <span
                  v-if="offlineStopBadgeLabel(incident)"
                  data-testid="offline-stop-badge"
                  style="display: inline-flex; align-items: center; padding: 2px 8px; border: 1px solid; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 11px; font-weight: 700;"
                  :style="offlineStopBadgeStyle(incident)"
                >
                  {{ offlineStopBadgeLabel(incident) }}
                </span>
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

              <!-- Disponibilidad del repuesto en la furgoneta (Módulo 23, RF-05.4 / QA 3).
                   El descriptor lo calcula el servidor; la vista solo lo lee. -->
              <div v-if="incident.van_stock_readiness?.badge_label" style="margin-top: 6px;">
                <span
                  data-testid="tech-stop-van-stock-readiness"
                  :class="vanStockReadinessChipClass(incident.van_stock_readiness.status)"
                >
                  {{ incident.van_stock_readiness.badge_label }}
                </span>
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
              <div v-else-if="isAssigned(incident)">
                <button
                  type="button"
                  class="vg-btn vg-btn-primary"
                  style="width: 100%; height: 46px; font-size: 15px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px;"
                  :disabled="actionInProgressId === incident.id"
                  @click="startIntervention(incident)"
                >
                  <span v-if="actionInProgressId !== incident.id">▶ Iniciar intervención</span>
                  <span v-else>Iniciando...</span>
                </button>
                <div v-if="shiftStatus !== 'ON_SHIFT'" style="margin-top: 6px; font-size: 12px; color: var(--color-ink-muted); text-align: center;">
                  ℹ️ Debes iniciar turno arriba para intervenir
                </div>
              </div>

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
              <div v-else-if="isPendingParts(incident)">
                <button
                  type="button"
                  class="vg-btn vg-btn-primary"
                  style="width: 100%; height: 46px; font-size: 15px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px;"
                  :disabled="actionInProgressId === incident.id"
                  @click="startIntervention(incident)"
                >
                  <span v-if="actionInProgressId !== incident.id">▶ Reanudar intervención</span>
                  <span v-else>Reanudando...</span>
                </button>
                <div v-if="shiftStatus !== 'ON_SHIFT'" style="margin-top: 6px; font-size: 12px; color: var(--color-ink-muted); text-align: center;">
                  ℹ️ Debes iniciar turno arriba para intervenir
                </div>
              </div>

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
      <!-- MODAL 24-A: PAUSA SIN CONEXIÓN (Módulo 24, RF-03.1.2, EARS 03.4)      -->
      <!-- =================================================================== -->
      <ModalDialog
        v-model="showOfflinePauseModal"
        title="Pausar intervención sin conexión"
        :subtitle="offlinePauseIncident ? ('Ticket ' + offlinePauseIncident.ticket_code + ' · ' + (offlinePauseIncident.machine?.code || '')) : ''"
        size="md"
        @close="closeOfflinePauseModal"
      >
        <form @submit.prevent="submitOfflinePause">
          <div style="background-color: var(--color-info-bg); border: 1px solid var(--color-info-border); border-radius: var(--radius-interactive); padding: 10px 12px; font-family: var(--font-body); font-size: 12px; color: var(--color-info-text); margin-bottom: 14px; line-height: 1.4;">
            La pausa se guarda en el dispositivo con la hora real y se enviará al recuperar cobertura.
            La insignia «⏳ Pendiente de sincronizar» quedará visible en la parada.
          </div>

          <div v-if="offlinePauseOperation === 'PAUSE_INCIDENT_PENDING_INFO'" style="margin-bottom: 14px;">
            <label for="offline-pause-category" style="display: block; font-family: var(--font-body); font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;">
              Causa tipificada <span style="color: var(--color-error-text);">*</span>
            </label>
            <select
              id="offline-pause-category"
              v-model="offlinePauseCategory"
              class="vg-select"
              :disabled="isOfflinePausing"
              style="min-height: 44px; width: 100%;"
            >
              <option value="">Selecciona el motivo…</option>
              <option v-for="category in pauseReasonCategories" :key="category.value" :value="category.value">
                {{ category.icon }} {{ category.label }}
              </option>
            </select>
          </div>

          <div style="margin-bottom: 14px;">
            <label for="offline-pause-reason" style="display: block; font-family: var(--font-body); font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;">
              Justificación de la pausa (mínimo {{ offlinePauseOperation === 'PAUSE_INCIDENT_PENDING_INFO' ? 20 : 10 }} caracteres) <span style="color: var(--color-error-text);">*</span>
            </label>
            <textarea
              id="offline-pause-reason"
              v-model="offlinePauseReason"
              class="vg-textarea"
              rows="3"
              :disabled="isOfflinePausing"
              placeholder="Describe la pieza que falta o el impedimento real encontrado in situ…"
            ></textarea>
          </div>

          <div
            v-if="offlineSealRequiredForPause()"
            data-testid="offline-sanitary-seal-block"
            style="background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); border-radius: var(--radius-card); padding: 12px; margin-bottom: 14px; line-height: 1.45;"
          >
            <div style="font-family: var(--font-display); font-size: 15px; font-weight: 500; margin-bottom: 6px;">
              ⚠️ Alerta sanitaria crítica (Art. II)
            </div>
            <p style="font-family: var(--font-body); font-size: 13px; margin: 0 0 10px 0;">
              Máquina de alimentos perecederos. La pausa solo se guarda si confirmas el precinto físico in situ.
            </p>
            <label style="display: flex; align-items: flex-start; gap: 10px; min-height: 44px; cursor: pointer; font-family: var(--font-body); font-size: 13px;">
              <input type="checkbox" v-model="sanitarySealConfirmed" style="width: 20px; height: 20px; margin-top: 2px; flex: 0 0 auto;" />
              <span>
                Confirmo que he precintado físicamente la máquina / inhabilitado la venta in situ
                y acudiré a una zona con cobertura al finalizar para transmitir la alerta sanitaria.
              </span>
            </label>
          </div>

          <div
            v-if="offlinePauseError"
            role="alert"
            style="background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); padding: 10px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 13px; margin-bottom: 14px;"
          >
            {{ offlinePauseError }}
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid var(--color-hairline); padding-top: 14px;">
            <button type="button" class="vg-btn vg-btn-secondary" style="min-height: 44px; padding: 0 14px;" :disabled="isOfflinePausing" @click="closeOfflinePauseModal">
              Cancelar
            </button>
            <button type="submit" class="vg-btn vg-btn-primary" style="min-height: 44px; padding: 0 14px; font-weight: 600;" :disabled="isOfflinePausing">
              {{ isOfflinePausing ? 'Guardando en la bandeja…' : 'Guardar pausa sin conexión' }}
            </button>
          </div>
        </form>
      </ModalDialog>

      <!-- =================================================================== -->
      <!-- MODAL 24-B: COMENTARIO IN SITU SIN CONEXIÓN (RF-03.1.5, D3)          -->
      <!-- =================================================================== -->
      <ModalDialog
        v-model="showOfflineCommentModal"
        title="Comentario sin conexión"
        :subtitle="offlineCommentIncident ? ('Ticket ' + offlineCommentIncident.ticket_code + ' · ' + (offlineCommentIncident.machine?.code || '')) : ''"
        size="md"
        @close="closeOfflineCommentModal"
      >
        <form @submit.prevent="submitOfflineComment">
          <div style="background-color: var(--color-info-bg); border: 1px solid var(--color-info-border); border-radius: var(--radius-interactive); padding: 10px 12px; font-family: var(--font-body); font-size: 12px; color: var(--color-info-text); margin-bottom: 14px; line-height: 1.4;">
            El mensaje viaja en la bandeja local con la cuota de 5 fotografías o 25 MB.
            La firma digital no consume ese cupo.
          </div>

          <div style="margin-bottom: 14px;">
            <label for="offline-comment-text" style="display: block; font-family: var(--font-body); font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;">
              Mensaje o nota de taller <span style="color: var(--color-error-text);">*</span>
            </label>
            <textarea
              id="offline-comment-text"
              v-model="offlineCommentText"
              class="vg-textarea"
              rows="3"
              :disabled="isOfflineCommenting"
              placeholder="Describe lo observado o lo actuado in situ…"
            ></textarea>
          </div>

          <label style="display: flex; align-items: center; gap: 10px; min-height: 44px; margin-bottom: 12px; font-family: var(--font-body); font-size: 13px; color: var(--color-slate); cursor: pointer;">
            <input type="checkbox" v-model="offlineCommentInternal" style="width: 20px; height: 20px;" />
            <span>Nota Interna de Taller (oculta al Portal de Sede, Art. V.4)</span>
          </label>

          <div style="margin-bottom: 14px;">
            <label for="offline-comment-photo" style="display: block; font-family: var(--font-body); font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;">
              Evidencia fotográfica (opcional · JPG, PNG o WebP · máx. 5 MB)
            </label>
            <input
              id="offline-comment-photo"
              type="file"
              accept=".jpg,.jpeg,.png,.webp"
              :disabled="isOfflineCommenting"
              style="min-height: 44px; width: 100%; font-family: var(--font-body); font-size: 13px;"
              @change="handleOfflineCommentPhoto"
            />
            <div v-if="offlineCommentPhoto" style="font-family: var(--font-body); font-size: 12px; color: var(--color-ink-muted); margin-top: 4px;">
              Adjunta: {{ offlineCommentPhoto.name }}
            </div>
            <div v-if="offlineCommentPhotoError" style="font-family: var(--font-body); font-size: 12px; color: var(--color-error-text); margin-top: 4px;">
              {{ offlineCommentPhotoError }}
            </div>
          </div>

          <div
            v-if="offlineCommentError"
            role="alert"
            style="background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); padding: 10px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 13px; margin-bottom: 14px;"
          >
            {{ offlineCommentError }}
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid var(--color-hairline); padding-top: 14px;">
            <button type="button" class="vg-btn vg-btn-secondary" style="min-height: 44px; padding: 0 14px;" :disabled="isOfflineCommenting" @click="closeOfflineCommentModal">
              Cancelar
            </button>
            <button type="submit" class="vg-btn vg-btn-primary" style="min-height: 44px; padding: 0 14px; font-weight: 600;" :disabled="isOfflineCommenting">
              {{ isOfflineCommenting ? 'Guardando en la bandeja…' : 'Guardar comentario sin conexión' }}
            </button>
          </div>
        </form>
      </ModalDialog>

      <!-- =================================================================== -->
      <!-- MODAL 24-C: CHECKLIST PREVENTIVO SIN CONEXIÓN (RF-03.1.4)             -->
      <!-- =================================================================== -->
      <ModalDialog
        v-model="showOfflineChecklistModal"
        :title="offlineChecklistIsReinspection ? 'Reinspección sanitaria sin conexión' : 'Checklist preventivo sin conexión'"
        :subtitle="offlineChecklistOrder ? ((offlineChecklistOrder.order_code || 'Orden') + ' · ' + (offlineChecklistOrder.machine_code || '')) : ''"
        size="md"
        @close="closeOfflineChecklistModal"
      >
        <form @submit.prevent="submitOfflineChecklist">
          <div style="background-color: var(--color-info-bg); border: 1px solid var(--color-info-border); border-radius: var(--radius-interactive); padding: 10px 12px; font-family: var(--font-body); font-size: 12px; color: var(--color-info-text); margin-bottom: 14px; line-height: 1.4;">
            La inspección se guarda con la hora real en la bandeja del dispositivo y se enviará al recuperar cobertura.
          </div>

          <div style="margin-bottom: 14px;">
            <label for="offline-checklist-temperature" style="display: block; font-family: var(--font-body); font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;">
              Temperatura medida (°C)
            </label>
            <input
              id="offline-checklist-temperature"
              v-model="offlineChecklistTemperature"
              class="vg-input"
              type="number"
              step="0.1"
              min="-5"
              max="25"
              inputmode="decimal"
              :disabled="isOfflineChecklistSubmitting"
              placeholder="Ej: 3,5"
              style="min-height: 44px;"
            />
          </div>

          <div v-if="!offlineChecklistIsReinspection" style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 14px;">
            <div
              v-for="item in offlineChecklistItems"
              :key="item.item_code"
              data-testid="offline-checklist-item"
              style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px 12px;"
            >
              <div style="font-family: var(--font-body); font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;">
                <span v-if="item.is_critical" style="color: var(--color-error-text);">● </span>{{ item.title }}
              </div>
              <select
                v-model="item.status"
                class="vg-select"
                :disabled="isOfflineChecklistSubmitting"
                style="min-height: 44px; width: 100%;"
              >
                <option value="">Sin responder…</option>
                <option value="PASS">Conforme</option>
                <option value="WARN">Aviso</option>
                <option value="FAIL">No conforme</option>
              </select>
              <textarea
                v-if="item.status === 'WARN' || item.status === 'FAIL'"
                v-model="item.observations"
                class="vg-textarea"
                rows="2"
                :disabled="isOfflineChecklistSubmitting"
                placeholder="Justifica el resultado desfavorable…"
                style="margin-top: 8px;"
              ></textarea>
            </div>
          </div>

          <div v-else style="margin-bottom: 14px;">
            <label for="offline-reinspection-notes" style="display: block; font-family: var(--font-body); font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;">
              Comprobación técnica realizada <span style="color: var(--color-error-text);">*</span>
            </label>
            <textarea
              id="offline-reinspection-notes"
              v-model="offlineChecklistReinspectionNotes"
              class="vg-textarea"
              rows="3"
              :disabled="isOfflineChecklistSubmitting"
              placeholder="Detalla la verificación térmica y sanitaria efectuada in situ…"
            ></textarea>
          </div>

          <div v-if="!offlineChecklistIsReinspection" style="margin-bottom: 14px;">
            <label for="offline-checklist-notes" style="display: block; font-family: var(--font-body); font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;">
              Observaciones generales
            </label>
            <textarea
              id="offline-checklist-notes"
              v-model="offlineChecklistGeneralNotes"
              class="vg-textarea"
              rows="2"
              :disabled="isOfflineChecklistSubmitting"
              placeholder="Anotaciones de la inspección para el expediente…"
            ></textarea>
          </div>

          <div
            v-if="offlineChecklistSealRequired"
            data-testid="offline-preventive-seal-block"
            style="background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); border-radius: var(--radius-card); padding: 12px; margin-bottom: 14px; line-height: 1.45;"
          >
            <div style="font-family: var(--font-display); font-size: 15px; font-weight: 500; margin-bottom: 6px;">
              ⚠️ Alerta sanitaria crítica (Art. II)
            </div>
            <p style="font-family: var(--font-body); font-size: 13px; margin: 0 0 10px 0;">
              La inspección ha detectado un resultado desfavorable en una máquina de alimentos perecederos.
            </p>
            <label style="display: flex; align-items: flex-start; gap: 10px; min-height: 44px; cursor: pointer; font-family: var(--font-body); font-size: 13px;">
              <input type="checkbox" v-model="offlineChecklistSealConfirmed" style="width: 20px; height: 20px; margin-top: 2px; flex: 0 0 auto;" />
              <span>
                Confirmo que he precintado físicamente la máquina / inhabilitado la venta in situ
                y acudiré a una zona con cobertura al finalizar para transmitir la alerta sanitaria.
              </span>
            </label>
          </div>

          <div
            v-if="offlineChecklistError"
            role="alert"
            style="background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); padding: 10px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 13px; margin-bottom: 14px;"
          >
            {{ offlineChecklistError }}
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid var(--color-hairline); padding-top: 14px;">
            <button type="button" class="vg-btn vg-btn-secondary" style="min-height: 44px; padding: 0 14px;" :disabled="isOfflineChecklistSubmitting" @click="closeOfflineChecklistModal">
              Cancelar
            </button>
            <button type="submit" class="vg-btn vg-btn-primary" style="min-height: 44px; padding: 0 14px; font-weight: 600;" :disabled="isOfflineChecklistSubmitting">
              {{ isOfflineChecklistSubmitting ? 'Guardando en la bandeja…' : 'Guardar inspección sin conexión' }}
            </button>
          </div>
        </form>
      </ModalDialog>

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

      <!-- =================================================================== -->
      <!-- MODAL 6: FIN DE JORNADA Y RELEVO DE RUTA (Módulo 21, RF-05)         -->
      <!-- =================================================================== -->
      <ModalDialog
        v-model="showShiftHandoverModal"
        title="Fin de Jornada y Relevo de Ruta"
        @close="closeShiftHandoverModal"
      >
        <div v-if="hasInProgressIncidents" style="background-color: var(--color-urgency-critical-bg); border: 1px solid var(--color-hairline); color: var(--color-error-text); padding: 12px; border-radius: var(--radius-interactive); margin-bottom: 14px; font-size: 13px;">
          <strong>⚠️ Bloqueo de Cierre:</strong> Tienes averías en curso (EN CURSO). Debes resolver la intervención o pausarla por repuesto o falta de acceso antes de poder cerrar la jornada.
        </div>

        <div v-else>
          <div style="display: flex; gap: 8px; margin-bottom: 14px;">
            <button
              type="button"
              class="vg-btn"
              :class="handoverMode === 'CLOSE_ONLY' ? 'vg-btn-primary' : 'vg-btn-secondary'"
              @click="setHandoverMode('CLOSE_ONLY')"
            >
              Cerrar sin relevo
            </button>
            <button
              type="button"
              class="vg-btn"
              :class="handoverMode === 'TRANSFER' ? 'vg-btn-primary' : 'vg-btn-secondary'"
              :disabled="eligibleHandoverIncidents.length === 0"
              @click="setHandoverMode('TRANSFER')"
            >
              Traspasar averías
            </button>
          </div>

          <div
            v-if="handoverMode === 'CLOSE_ONLY'"
            style="background: var(--color-canvas); border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); padding: 12px; margin-bottom: 14px; font-size: 13px; color: var(--color-slate);"
          >
            <strong>Cerrar sin traspaso.</strong> Las averías ordinarias se retendrán en tu ruta para mañana. Las máquinas de perecederos se derivan automáticamente a la guardia sanitaria o se desasignan con alarma crítica a Coordinación (Art. II).
          </div>

          <div v-else>
            <p style="font-size: 13px; color: var(--color-slate); margin-bottom: 12px;">
              Selecciona explícitamente las averías que deseas traspasar. Las no seleccionadas se retendrán en tu ruta para mañana (salvo máquinas de perecederos, que se derivan automáticamente a la guardia sanitaria según Art. II).
            </p>

            <div v-if="eligibleHandoverIncidents.length > 0" style="margin-bottom: 14px;">
              <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px;">
                <label style="display: block; font-size: 12px; font-weight: 700; color: var(--color-slate);">
                  Averías elegibles para traspaso ({{ eligibleHandoverIncidents.length }}):
                </label>
                <button
                  v-if="selectedHandoverTicketIds.length < eligibleHandoverIncidents.length"
                  type="button"
                  class="vg-btn vg-btn-secondary"
                  style="font-size: 11px; padding: 4px 8px;"
                  @click="selectAllHandoverIncidents"
                >
                  Seleccionar todas
                </button>
                <button
                  v-else
                  type="button"
                  class="vg-btn vg-btn-secondary"
                  style="font-size: 11px; padding: 4px 8px;"
                  @click="clearHandoverSelection"
                >
                  Quitar selección
                </button>
              </div>
              <div style="max-height: 140px; overflow-y: auto; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); padding: 6px;">
                <div
                  v-for="inc in eligibleHandoverIncidents"
                  :key="inc.id"
                  style="display: flex; align-items: center; gap: 8px; padding: 4px 6px; font-size: 12px;"
                >
                  <input
                    type="checkbox"
                    :id="'handover-inc-' + inc.id"
                    :value="inc.id"
                    v-model="selectedHandoverTicketIds"
                  />
                  <label :for="'handover-inc-' + inc.id" style="cursor: pointer; flex: 1;">
                    <strong>{{ inc.ticket_code }}</strong> - {{ handoverMachineLabel(inc) }} ({{ inc.status }})
                  </label>
                </div>
              </div>
            </div>
            <div v-else style="font-size: 13px; color: var(--color-ink-muted); margin-bottom: 14px;">
              ℹ️ No tienes averías pendientes para traspasar.
            </div>

            <div v-if="selectedHandoverTicketIds.length > 0">
              <div style="margin-bottom: 12px;">
                <label for="handover-target-tech" style="display: block; font-size: 12px; font-weight: 700; color: var(--color-slate); margin-bottom: 4px;">
                  Técnico Receptor *
                </label>
                <select
                  id="handover-target-tech"
                  v-model="targetHandoverTechId"
                  class="vg-input"
                  style="width: 100%; height: 38px; font-size: 13px;"
                  required
                >
                  <option value="" disabled>Selecciona técnico de relevo...</option>
                  <option
                    v-for="tech in handoverTargets"
                    :key="tech.id"
                    :value="tech.id"
                    :disabled="!tech.is_available_for_handover"
                  >
                    {{ tech.name }} — {{ tech.availability_label }}
                  </option>
                </select>
                <div v-if="handoverTargets.length === 0" style="font-size: 12px; color: var(--color-ink-muted); margin-top: 4px;">
                  No hay compañeros disponibles. Puedes cerrar sin relevo.
                </div>
              </div>

              <div style="margin-bottom: 12px;">
                <label for="handover-notes" style="display: block; font-size: 12px; font-weight: 700; color: var(--color-slate); margin-bottom: 4px;">
                  Nota Interna de Taller (Art. V.4, confidencial) *
                </label>
                <textarea
                  id="handover-notes"
                  v-model="handoverNotes"
                  class="vg-input"
                  rows="3"
                  style="width: 100%; font-size: 13px;"
                  placeholder="Detalles técnicos para el compañero que retoma la ruta (mínimo 10 caracteres)..."
                  required
                ></textarea>
              </div>
            </div>
          </div>

          <div v-if="handoverError" style="color: var(--color-urgency-critical); font-size: 12px; margin-bottom: 12px;">
            {{ handoverError }}
          </div>
        </div>

        <template #footer>
          <div style="display: flex; justify-content: flex-end; gap: 8px;">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              @click="closeShiftHandoverModal"
            >
              Cancelar
            </button>
            <button
              type="button"
              class="vg-btn vg-btn-primary"
              :disabled="hasInProgressIncidents || isSubmittingHandover"
              @click="submitShiftHandover"
            >
              <span v-if="!isSubmittingHandover">
                {{ handoverMode === 'CLOSE_ONLY'
                  ? 'Confirmar cierre sin relevo'
                  : 'Traspasar ' + selectedHandoverTicketIds.length + ' avería(s) a ' + (selectedHandoverTargetName || '...') }}
              </span>
              <span v-else>Cerrando...</span>
            </button>
          </div>
        </template>
      </ModalDialog>

      <!-- Módulo 22: autoconsulta de solo lectura de horas propias (RF-07.2, RF-07.3, Art. V.4) -->
      <ModalDialog
        v-model="showMyHoursModal"
        title="⏱️ Mis Horas"
        subtitle="Tu resumen del mes en curso. Solo tú puedes ver estas sesiones."
        size="lg"
      >
        <div v-if="showMyHoursModal" data-testid="modal-my-hours" style="display: flex; flex-direction: column; gap: 14px;">
          <p v-if="myHoursLoading" style="font-size: 13px; color: var(--color-ink-muted); margin: 0;">
            Cargando tus horas del mes…
          </p>

          <div v-else-if="myHoursError" role="alert" style="padding: 10px 12px; background-color: var(--color-error-bg); color: var(--color-error-text); border-radius: var(--radius-card); font-size: 13px;">
            <div>{{ myHoursError }}</div>
            <button type="button" class="vg-btn vg-btn-secondary vg-btn-sm" style="margin-top: 8px;" @click="loadMyHours">
              Reintentar
            </button>
          </div>

          <template v-else>
            <div>
              <div style="font-family: var(--font-display); font-size: 18px; font-weight: 500; color: var(--color-slate);">
                {{ myHoursMonthLabel }}
              </div>
              <div style="font-size: 12px; color: var(--color-ink-muted);">
                {{ formatShiftDate(myHours.from_date) }} — {{ formatShiftDate(myHours.to_date) }}
              </div>
            </div>

            <div data-testid="my-hours-summary" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px;">
              <div style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 12px 14px;">
                <div style="font-size: 12px; color: var(--color-ink-muted);">Sesiones</div>
                <div style="font-family: var(--font-display); font-size: 24px; font-weight: 500; color: var(--color-slate);">
                  {{ myHoursSummary.total_sessions || 0 }}
                </div>
              </div>
              <div style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 12px 14px;">
                <div style="font-size: 12px; color: var(--color-ink-muted);">Horas brutas</div>
                <div style="font-family: var(--font-display); font-size: 24px; font-weight: 500; color: var(--color-slate);">
                  {{ myHoursSummary.gross_formatted || '00h 00m' }}
                </div>
              </div>
              <div style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 12px 14px;">
                <div style="font-size: 12px; color: var(--color-ink-muted);">Comida</div>
                <div style="font-family: var(--font-display); font-size: 24px; font-weight: 500; color: var(--color-slate);">
                  {{ myHoursSummary.meal_break_formatted || '00h 00m' }}
                </div>
              </div>
              <div style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 12px 14px;">
                <div style="font-size: 12px; color: var(--color-ink-muted);">Efectivas netas</div>
                <div style="font-family: var(--font-display); font-size: 24px; font-weight: 500; color: var(--color-primary);">
                  {{ myHoursSummary.effective_formatted || '00h 00m' }}
                </div>
              </div>
            </div>

            <div>
              <h4 style="font-family: var(--font-display); font-size: 16px; font-weight: 500; color: var(--color-slate); margin: 0 0 8px;">
                Mis sesiones del mes
              </h4>

              <p v-if="myHoursIsEmpty" data-testid="my-hours-empty" style="font-size: 13px; color: var(--color-ink-muted); margin: 0;">
                Sin sesiones registradas este mes.
              </p>

              <ul v-else data-testid="my-hours-sessions" style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px;">
                <li
                  v-for="session in myHoursSessions"
                  :key="'my-hours-' + session.id"
                  style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px 12px;"
                >
                  <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px;">
                    <strong style="font-family: var(--font-body); font-size: 14px; color: var(--color-slate);">
                      {{ formatShiftDate(session.shift_date) }}
                    </strong>
                    <span style="font-family: var(--font-display); font-size: 14px; color: var(--color-primary);">
                      {{ session.effective_formatted }}
                    </span>
                  </div>

                  <div style="font-size: 12px; color: var(--color-ink-muted); margin-top: 2px;">
                    {{ formatTime(session.started_at) }} → {{ session.is_open ? 'En curso' : formatTime(session.closed_at) }}
                    · Bruto {{ session.gross_formatted }}
                    · Comida {{ session.meal_break_formatted }}
                  </div>

                  <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px;">
                    <span
                      v-if="session.is_open"
                      style="display: inline-block; padding: 2px 8px; background-color: var(--color-success-bg); color: var(--color-success-text); border: 1px solid var(--color-success); border-radius: var(--radius-interactive); font-size: 12px;"
                    >
                      En curso
                    </span>
                    <span
                      v-if="session.is_anomalous"
                      style="display: inline-block; padding: 2px 8px; background-color: var(--color-warning-bg); color: var(--color-warning-text); border: 1px solid var(--color-warning-border); border-radius: var(--radius-interactive); font-size: 12px;"
                    >
                      ⚠️ &gt; 12h
                    </span>
                    <span
                      v-if="session.is_force_closed"
                      style="display: inline-block; padding: 2px 8px; background-color: var(--color-warning-bg); color: var(--color-warning-text); border: 1px solid var(--color-warning-border); border-radius: var(--radius-interactive); font-size: 12px;"
                    >
                      Cierre forzado
                    </span>
                    <span
                      v-if="session.is_adjusted"
                      style="display: inline-block; padding: 2px 8px; background-color: var(--color-info-bg); color: var(--color-info-text); border: 1px solid var(--color-info-border); border-radius: var(--radius-interactive); font-size: 12px;"
                    >
                      Ajustado por Coordinación
                    </span>
                  </div>

                  <div
                    v-if="hasShiftOriginalMarks(session)"
                    style="margin-top: 8px; padding: 8px 10px; background-color: var(--color-canvas); border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); font-size: 12px; color: var(--color-ink-muted);"
                  >
                    <div>
                      <strong style="color: var(--color-slate);">Marcas originales del primer fichaje:</strong>
                      entrada {{ formatShiftDateTime(session.original_started_at) }}
                      · salida {{ session.original_closed_at ? formatShiftDateTime(session.original_closed_at) : '—' }}
                      · comida
                      {{ (session.original_meal_break_seconds !== null && session.original_meal_break_seconds !== undefined)
                        ? formatShiftDuration(session.original_meal_break_seconds)
                        : '—' }}
                    </div>
                    <div v-if="session.adjustment_reason" style="margin-top: 4px;">
                      <strong style="color: var(--color-slate);">Motivo del ajuste:</strong> {{ session.adjustment_reason }}
                    </div>
                    <div v-else-if="session.close_reason" style="margin-top: 4px;">
                      <strong style="color: var(--color-slate);">Motivo del cierre:</strong> {{ session.close_reason }}
                    </div>
                  </div>

                  <div
                    v-else-if="session.is_force_closed && session.close_reason"
                    style="margin-top: 8px; font-size: 12px; color: var(--color-ink-muted);"
                  >
                    <strong style="color: var(--color-slate);">Motivo del cierre:</strong> {{ session.close_reason }}
                  </div>
                </li>
              </ul>
            </div>
          </template>
        </div>

        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="closeMyHours">Cerrar</button>
        </template>
      </ModalDialog>
    </div>
  `
};

export default TechnicianRouteView;
