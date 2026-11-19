/**
 * VendGuard - RestockerRouteView (RestockerRouteView.js)
 *
 * Vista móvil dedicada al Cargador / Reponedor de Ruta (`RESTOCKER`), Módulos 31,
 * 33 y 34 (`D1`, `RF-02` a `RF-05.1`, `D3`, `D4`, `C3`, `C4`, Art. II, Art. V.4).
 *
 * Responsabilidades:
 * 1. Autenticación exclusiva de `RESTOCKER`: cualquier otro rol se rechaza y la
 *    sesión se descarta, de modo que esta vista no abre la consola SAT.
 * 2. Cabecera con el nombre del operario, su `operator_code` y las insignias de
 *    sus zonas asignadas (`Titular` / `Suplente`).
 * 3. Barra de jornada completa (`Iniciar Turno`, `Pausa para Comer`, `Reanudar`,
 *    `Cerrar Turno`) con cierre directo, sin modal de relevo de averías SAT.
 * 4. Contador diario en vivo y autoconsulta de solo lectura «⏱️ Mis Horas», que
 *    no envía ningún identificador ajeno: el backend resuelve el `user_id` del
 *    token (Art. V.4).
 * 5. Estado base informativo de la ruta de abastecimiento, listo para las
 *    Features 24, 26 y 27 sin inventar paradas.
 * 6. Ciclo móvil de la orden de carga: recepción en un toque, liquidación por
 *    lote al cierre, stock FEFO con semáforo y comprobante de la última orden
 *    liquidada (`C4`), siempre sin costes internos (Art. V.4).
 * 7. Asistente de visita in situ: control térmico con aborto automático, retirada
 *    y carga FEFO, checklist adaptativo, evidencia fotográfica y cola offline
 *    IndexedDB aislada por `restocker_id` con sincronización FIFO (`C3`, `D4`).
 *
 * Dogma Vanilla: Vue 3 Options API en ES Module nativo, sin bundler ni
 * dependencias externas. El diseño sigue `docs/design.md` con tokens CSS.
 */

import { api } from '../api.js';
import { store } from '../store.js';
import {
  RestockVisitOfflineQueue,
  RESTOCK_VISIT_CONFLICT,
  RESTOCK_VISIT_PENDING
} from '../utils/RestockVisitOfflineQueue.js';
import {
  CashCollectionOfflineQueue,
  CASH_COLLECTION_CONFLICT,
  CASH_COLLECTION_PENDING
} from '../utils/CashCollectionOfflineQueue.js';
import {
  RestockerIncidentOfflineQueue,
  RESTOCKER_INCIDENT_CONFLICT,
  RESTOCKER_INCIDENT_PENDING,
  RESTOCKER_INCIDENT_OPERATIONS
} from '../utils/RestockerIncidentOfflineQueue.js';

const HYGIENE_ITEMS = [
  { key: 'coffee_grounds_emptied', label: 'Cajón de posos vaciado', scope: 'hot' },
  { key: 'liquid_waste_bucket_cleaned', label: 'Cubo de residuos líquidos limpio', scope: 'hot' },
  { key: 'mixers_and_ducts_rinsed', label: 'Batidores y conductos enjuagados', scope: 'hot' },
  { key: 'dispensing_tray_and_glass_cleaned', label: 'Bandeja de erogación y cristal limpios', scope: 'all' },
  { key: 'cabin_spills_checked', label: 'Derrames de cabina revisados', scope: 'cold' }
];

const THERMAL_CEILINGS = Object.freeze({
  PERISHABLE_FOOD: 4,
  COMBO: 4,
  COLD_DRINKS: 8
});

const HOT_MACHINE_TYPES = new Set(['HOT_DRINKS', 'COMBO']);

const CASH_RECEIPT_FIELDS = Object.freeze([
  'collection_code',
  'collected_at',
  'location_code',
  'location_name',
  'machine_code',
  'restocker_name',
  'receptionist_name',
  'reception_cash_delivered_eur',
  'linked_refund_request_id'
]);

const CASH_COLLECTION_PUBLIC_FIELDS = Object.freeze([
  'id',
  'collection_code',
  'restock_visit_id',
  'machine_id',
  'machine_code',
  'bag_seal_code',
  'sales_counter_reading',
  'change_tubes_loaded_eur',
  'is_counter_reset',
  'counter_reset_notes',
  'reception_cash_delivered_eur',
  'receptionist_name',
  'linked_refund_request_id',
  'has_reception_receipt',
  'status',
  'status_label',
  'collected_at',
  'created_at'
]);

const blankCashForm = () => ({
  client_mutation_id: '',
  bag_seal_code: '',
  sales_counter_reading: '',
  change_tubes_loaded_eur: 0,
  is_counter_reset: false,
  counter_reset_notes: '',
  reception_cash_delivered_eur: 0,
  receptionist_name: '',
  linked_refund_request_id: null,
  route_notes: '',
  collected_at: ''
});

const blankCashRouteSummary = () => ({
  total_collections_count: 0,
  total_change_tubes_loaded_eur: 0,
  total_reception_cash_delivered_eur: 0,
  pending_vault_bags_count: 0,
  last_collected_at: null
});

const blankVisitForm = () => ({
  client_mutation_id: '',
  started_at: '',
  cabin_temp_celsius: '',
  precautionary_seal_applied: false,
  photo_evidence_url: '',
  internal_notes: '',
  hygiene_checklist: {},
  items: []
});

const createClientMutationId = () => {
  try {
    if (typeof globalThis !== 'undefined' && typeof globalThis.crypto?.randomUUID === 'function') {
      return globalThis.crypto.randomUUID();
    }
  } catch (error) {
    // El identificador de respaldo sigue siendo válido sin Web Crypto.
  }

  return `MRV-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
};

const hygieneItemRequired = (item, machineType) => {
  if (item.scope === 'all') return true;
  const isHot = HOT_MACHINE_TYPES.has(String(machineType || ''));
  return item.scope === 'hot' ? isHot : !isHot;
};

const hygieneDefaults = (machineType) => {
  const defaults = {};
  for (const item of HYGIENE_ITEMS) {
    defaults[item.key] = hygieneItemRequired(item, machineType) ? false : null;
  }
  return defaults;
};

export const RestockerRouteView = {
  name: 'RestockerRouteView',
  data() {
    return {
      // Autenticación (RF-04.1)
      loginEmail: '',
      loginPassword: '',
      isLoggingIn: false,
      loginError: '',

      // Perfil, zonas y ruta base (RF-04.1, D1)
      restocker: null,
      zones: { primary_zone: null, backup_zones: [] },
      routeStops: [],
      machines: [],
      isLoading: false,
      loadError: '',

      // Avisos de Nivel 1 y Coincidencia en Sede (Módulo 36, T-RSS-11)
      assignedL1Incidents: [],
      l1TaxonomyCatalog: [],
      siteCoincidence: {},
      showL1ResolveModal: false,
      l1ResolveIncident: null,
      l1ResolveForm: { root_cause_id: '', standard_solution_id: '', diagnosis: '', action: '', client_mutation_id: '' },
      l1ResolveError: '',
      l1ResolveSuccess: '',
      isResolvingL1: false,
      showEscalateModal: false,
      escalateMachine: null,
      escalateIncident: null,
      escalateForm: { category: 'ELECTRICAL_OFF', workshop_internal_note: '', photo_evidence_url: '', client_mutation_id: '' },
      escalatePhotoFile: null,
      escalatePhotoName: '',
      escalateError: '',
      escalateSuccess: '',
      isEscalating: false,
      pendingIncidentMutations: [],
      incidentQueueSequence: 0,
      isSyncingIncidents: false,
      incidentSyncMessage: '',
      incidentSyncError: '',

      // Consulta de solo lectura del planograma (T-PLN-13, D2, QA 2, Art. V.4)
      showPlanogramModal: false,
      selectedMachine: null,
      planogram: null,
      planogramLoading: false,
      planogramError: '',

      // Visita in situ, historial y cola offline (Módulo 34, T-RVF-12)
      showVisitModal: false,
      visitMachine: null,
      visitPlanogram: null,
      visitLoading: false,
      visitError: '',
      visitSuccess: '',
      visitSubmitting: false,
      visitForm: blankVisitForm(),
      visitPhotoFile: null,
      visitPhotoName: '',
      visitPhotoPreviewUrl: '',
      recentVisits: [],
      pendingVisitMutations: [],
      visitQueueSequence: 0,
      isSyncingVisits: false,
      visitSyncMessage: '',
      visitSyncError: '',

      // Recaudación, cambio y conserjería (Módulo 35, T-CCV-11, D1–D4)
      showCashModal: false,
      cashContext: 'INDEPENDENT',
      cashMachine: null,
      cashForm: blankCashForm(),
      cashError: '',
      cashSuccess: '',
      cashSubmitting: false,
      visitCashEnabled: false,
      visitCashForm: blankCashForm(),
      pendingReceptionRefunds: [],
      recentCashCollections: [],
      cashRouteSummary: blankCashRouteSummary(),
      pendingCashMutations: [],
      cashQueueSequence: 0,
      isSyncingCash: false,
      cashSyncMessage: '',
      cashSyncError: '',
      showCashReceiptModal: false,
      cashReceipt: {},
      cashReceiptLoading: false,
      cashReceiptError: '',

      // Órdenes de carga de producto y stock por lote (Módulo 33, T-PLD-12)
      activeLoadingOrder: null,
      lastReconciledOrder: null,
      vanProductStocks: [],
      vanStockKpis: {
        total_lots: 0,
        total_units: 0,
        usable_units: 0,
        expired_lots: 0,
        expiring_soon_lots: 0
      },
      hasExpiredVanLots: false,
      isConfirmingLoading: false,
      loadingActionError: '',
      loadingActionMessage: '',
      showReconcileLoadingModal: false,
      reconcileLoadingForm: { notes: '', items: [] },
      reconcileLoadingError: '',
      isReconcilingLoading: false,

      // Jornada (RF-04.2)
      shiftStatus: 'OFF_SHIFT',
      activeShiftId: null,
      todaySummary: null,
      isShiftLoading: false,
      shiftError: '',
      feedbackMessage: '',

      // «⏱️ Mis Horas» (RF-04.3)
      showMyHoursModal: false,
      myHoursLoading: false,
      myHoursError: '',
      myHours: {
        month: '',
        from_date: '',
        to_date: '',
        summary: {},
        sessions: []
      }
    };
  },
  computed: {
    currentUser() {
      return store.state.user;
    },
    isAuthenticated() {
      return Boolean(store.state.user && store.state.user.role === 'RESTOCKER');
    },
    operatorCode() {
      return this.restocker?.operator_code || this.currentUser?.operator_code || '—';
    },
    primaryZone() {
      return this.zones?.primary_zone || null;
    },
    backupZones() {
      return Array.isArray(this.zones?.backup_zones) ? this.zones.backup_zones : [];
    },
    hasZones() {
      return Boolean(this.primaryZone) || this.backupZones.length > 0;
    },
    planogramSlots() {
      return Array.isArray(this.planogram?.slots) ? this.planogram.slots : [];
    },
    planogramSummary() {
      return this.planogram?.summary || {
        active_slots_count: 0,
        stockout_slots_count: 0,
        low_stock_slots_count: 0,
        fill_rate_percent: 0
      };
    },
    shiftStatusLabel() {
      if (this.shiftStatus === 'ON_SHIFT') return 'En turno';
      if (this.shiftStatus === 'MEAL_BREAK') return 'Pausa comida';
      return 'Fuera de turno';
    },
    todayEffectiveFormatted() {
      return this.todaySummary?.effective_formatted || '00h 00m';
    },
    todayMealFormatted() {
      return this.todaySummary?.meal_break_formatted || '00h 00m';
    },
    myHoursSummary() {
      return this.myHours?.summary || {};
    },
    myHoursSessions() {
      return Array.isArray(this.myHours?.sessions) ? this.myHours.sessions : [];
    },
    myHoursIsEmpty() {
      return this.myHoursSessions.length === 0;
    },
    myHoursMonthLabel() {
      const month = this.myHours?.month;
      if (!month || !/^\d{4}-\d{2}$/.test(String(month))) return 'Mes en curso';
      const names = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
      const [year, monthNumber] = String(month).split('-');
      const name = names[Number(monthNumber) - 1] || '';
      return name ? `${name} de ${year}` : 'Mes en curso';
    },
    activeLoadingOrderData() {
      return this.activeLoadingOrder?.order || null;
    },
    activeLoadingOrderItems() {
      return Array.isArray(this.activeLoadingOrder?.items) ? this.activeLoadingOrder.items : [];
    },
    activeLoadingOrderVisibleItems() {
      return this.activeLoadingOrderItems.filter((item) => item.is_active !== false);
    },
    activeLoadingOrderStatus() {
      return this.activeLoadingOrderData?.status || '';
    },
    activeLoadingOrderIsDispatched() {
      return this.activeLoadingOrderStatus === 'DISPATCHED';
    },
    activeLoadingOrderIsConfirmed() {
      return this.activeLoadingOrderStatus === 'CONFIRMED_BY_RESTOCKER';
    },
    lastReconciledOrderData() {
      return this.lastReconciledOrder?.order || null;
    },
    lastReconciledOrderItems() {
      return Array.isArray(this.lastReconciledOrder?.items) ? this.lastReconciledOrder.items : [];
    },
    lastReconciledVisibleItems() {
      return this.lastReconciledOrderItems.filter((item) => item.is_active !== false);
    },
    vanStockLots() {
      return [...this.vanProductStocks].sort((left, right) => {
        const leftDate = left?.expiration_date ? String(left.expiration_date) : null;
        const rightDate = right?.expiration_date ? String(right.expiration_date) : null;
        if (leftDate === null && rightDate === null) return String(left?.product_sku || '').localeCompare(String(right?.product_sku || ''));
        if (leftDate === null) return 1;
        if (rightDate === null) return -1;
        return leftDate.localeCompare(rightDate) || String(left?.product_sku || '').localeCompare(String(right?.product_sku || ''));
      });
    },
    reconcileLoadingHasDiscrepancy() {
      return this.reconcileLoadingForm.items.some((item) => this.reconcileLoadingDiscrepancy(item) !== 0);
    },
    navigatorOnline() {
      return typeof navigator === 'undefined' ? true : navigator.onLine !== false;
    },
    visitRestockerId() {
      return Number(this.restocker?.id ?? this.currentUser?.id ?? 0) || null;
    },
    visitThermalCeiling() {
      return THERMAL_CEILINGS[String(this.visitMachine?.machine_type || '')] ?? null;
    },
    visitTemperatureRequired() {
      return this.visitThermalCeiling !== null;
    },
    visitCabinTemperature() {
      const raw = this.visitForm?.cabin_temp_celsius;
      if (raw === '' || raw === null || raw === undefined) return null;
      const value = Number(raw);
      return Number.isFinite(value) ? value : null;
    },
    visitThermalBreach() {
      return this.visitThermalCeiling !== null
        && this.visitCabinTemperature !== null
        && this.visitCabinTemperature > this.visitThermalCeiling;
    },
    visitMode() {
      return this.visitThermalBreach ? 'ABORTED_THERMAL_BREACH' : 'COMPLETED';
    },
    visitIsThermalAbort() {
      return this.visitMode === 'ABORTED_THERMAL_BREACH';
    },
    visitInputsLocked() {
      return this.visitIsThermalAbort;
    },
    visitHygieneItems() {
      const checklist = this.visitForm?.hygiene_checklist || {};
      return HYGIENE_ITEMS.map((item) => ({
        ...item,
        required: hygieneItemRequired(item, this.visitMachine?.machine_type),
        value: checklist[item.key] ?? null
      }));
    },
    visitHygieneMissing() {
      return this.visitHygieneItems.filter((item) => item.required && item.value !== true);
    },
    visitTotalAdded() {
      return (this.visitForm?.items || []).reduce((total, item) => total + Number(item.qty_added || 0), 0);
    },
    visitTotalWithdrawn() {
      return (this.visitForm?.items || []).reduce(
        (total, item) => total + Number(item.qty_withdrawn_expired || 0) + Number(item.qty_withdrawn_damaged || 0),
        0
      );
    },
    visitHasWithdrawals() {
      return this.visitTotalWithdrawn > 0;
    },
    visitPhotoReady() {
      return Boolean(this.visitPhotoFile || this.visitForm?.photo_evidence_url);
    },
    visitNeedsEvidence() {
      return this.visitIsThermalAbort || this.visitHasWithdrawals;
    },
    pendingVisitCount() {
      return this.pendingVisitMutations.filter((item) => item.status === RESTOCK_VISIT_PENDING).length;
    },
    pendingIncidentCount() {
      return this.pendingIncidentMutations.filter((item) => item.status === RESTOCKER_INCIDENT_PENDING).length;
    },
    conflictIncidentCount() {
      return this.pendingIncidentMutations.filter((item) => item.status === RESTOCKER_INCIDENT_CONFLICT).length;
    },
    l1ResolveSolutions() {
      const cause = this.l1TaxonomyCatalog.find(
        (row) => Number(row.id) === Number(this.l1ResolveForm.root_cause_id)
      );
      return Array.isArray(cause?.solutions) ? cause.solutions : [];
    },
    conflictVisitCount() {
      return this.pendingVisitMutations.filter((item) => item.status === RESTOCK_VISIT_CONFLICT).length;
    },
    cashRestockerId() {
      return this.visitRestockerId;
    },
    pendingCashCount() {
      return this.pendingCashMutations.filter((item) => item.status === CASH_COLLECTION_PENDING).length;
    },
    conflictCashCount() {
      return this.pendingCashMutations.filter((item) => item.status === CASH_COLLECTION_CONFLICT).length;
    },
    cashContextIsVisit() {
      return this.cashContext === 'VISIT';
    },
    cashModalMachine() {
      return this.cashMachine || this.visitMachine || null;
    },
    cashMachineHasReception() {
      return this.cashModalMachine?.has_physical_reception === true;
    },
    cashPendingRefunds() {
      return this.machinePendingRefunds(this.cashModalMachine);
    },
    cashSelectedRefund() {
      const id = Number(this.cashForm?.linked_refund_request_id || 0);
      if (id < 1) return null;
      return this.cashPendingRefunds.find((refund) => Number(refund.id) === id) || null;
    },
    cashDeliveryAmount() {
      const value = Number(this.cashForm?.reception_cash_delivered_eur || 0);
      return Number.isFinite(value) ? value : 0;
    },
    visitCashConfigured() {
      return this.visitCashEnabled && String(this.visitCashForm?.bag_seal_code || '').trim() !== '';
    }
  },
  mounted() {
    if (this.isAuthenticated) {
      this.loadMyRoute();
      this.loadShiftStatus();
      this.loadPendingVisitQueue();
      this.loadPendingCashQueue();
      this.loadPendingIncidentQueue();
    }
    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
      this.visitOnlineHandler = () => {
        if (!this.navigatorOnline) return;
        this.syncPendingVisits();
        this.syncPendingCashCollections();
        this.syncPendingIncidentMutations();
      };
      window.addEventListener('online', this.visitOnlineHandler);
    }
  },
  beforeUnmount() {
    if (typeof window !== 'undefined' && typeof window.removeEventListener === 'function' && this.visitOnlineHandler) {
      window.removeEventListener('online', this.visitOnlineHandler);
    }
  },
  methods: {
    /**
     * Acceso del reponedor (RF-04.1). El rol se comprueba en el cliente y el
     * servidor vuelve a imponerlo en cada petición: esta vista no es la frontera
     * de seguridad, solo evita montar una consola que el token no autoriza.
     */
    async handleLogin() {
      if (!this.loginEmail.trim() || !this.loginPassword.trim()) {
        this.loginError = 'Por favor, introduce correo electrónico y contraseña.';
        return;
      }

      this.loginError = '';
      this.isLoggingIn = true;
      try {
        const response = await api.auth.internalLogin(this.loginEmail.trim(), this.loginPassword);
        if (response?.user?.role !== 'RESTOCKER') {
          store.clearSession();
          this.loginError = 'Acceso denegado: esta vista es exclusiva de Cargadores / Reponedores de Ruta.';
          return;
        }

        store.setInternalSession(response.user, response.token, response.chat_token);
        this.loginEmail = '';
        this.loginPassword = '';
        await this.loadMyRoute();
        await this.loadShiftStatus();
      } catch (err) {
        this.loginError = err?.message || 'Credenciales incorrectas o usuario reponedor inactivo.';
      } finally {
        this.isLoggingIn = false;
      }
    },

    /** Bootstrap móvil: perfil, zonas, turno y estado base de la ruta (D1). */
    async loadMyRoute() {
      this.isLoading = true;
      this.loadError = '';
      try {
        const response = await api.get('/restocker/my-route');
        const data = response?.data && typeof response.data === 'object' ? response.data : (response || {});
        this.restocker = data.restocker || null;
        this.zones = {
          primary_zone: data.zones?.primary_zone || null,
          backup_zones: Array.isArray(data.zones?.backup_zones) ? data.zones.backup_zones : []
        };
        this.routeStops = Array.isArray(data.route_stops) ? data.route_stops : [];
        this.machines = Array.isArray(data.machines) ? data.machines : [];
        this.assignedL1Incidents = Array.isArray(data.assigned_l1_incidents) ? data.assigned_l1_incidents : [];
        this.l1TaxonomyCatalog = Array.isArray(data.l1_taxonomy_catalog) ? data.l1_taxonomy_catalog : [];
        this.siteCoincidence = data.site_coincidence && typeof data.site_coincidence === 'object'
          ? data.site_coincidence
          : {};
        this.activeLoadingOrder = data.active_loading_order || null;
        this.lastReconciledOrder = data.last_reconciled_order || null;
        this.vanProductStocks = Array.isArray(data.van_product_stocks) ? data.van_product_stocks : [];
        this.recentVisits = Array.isArray(data.recent_visits) ? data.recent_visits.slice(0, 5) : [];
        this.pendingReceptionRefunds = Array.isArray(data.pending_reception_refunds)
          ? data.pending_reception_refunds
          : [];
        this.recentCashCollections = (Array.isArray(data.recent_cash_collections)
          ? data.recent_cash_collections
          : []).map((row) => this.sanitizeCashCollection(row)).filter(Boolean);
        this.cashRouteSummary = data.cash_route_summary && typeof data.cash_route_summary === 'object'
          ? { ...blankCashRouteSummary(), ...data.cash_route_summary }
          : blankCashRouteSummary();
        this.vanStockKpis = data.van_stock_kpis && typeof data.van_stock_kpis === 'object'
          ? data.van_stock_kpis
          : this.vanStockKpis;
        this.hasExpiredVanLots = data.has_expired_van_lots === true
          || Number(this.vanStockKpis.expired_lots || 0) > 0;
        this.applyShiftResponse(data.shift || {}, 'OFF_SHIFT');
        await this.loadPendingVisitQueue();
        await this.loadPendingCashQueue();
      } catch (err) {
        this.loadError = err?.message || 'No se pudo cargar tu ruta de reposición.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Aplica la respuesta del ciclo de jornada. El bootstrap entrega
     * `today_effective_seconds`; las transiciones entregan `today_summary`.
     */
    applyShiftResponse(response, fallbackStatus) {
      const data = response && typeof response === 'object' && response.data && typeof response.data === 'object'
        ? response.data
        : response;
      this.shiftStatus = data?.status || fallbackStatus;
      if (data?.active_shift_id) {
        this.activeShiftId = data.active_shift_id;
      }
      if (data?.today_summary && typeof data.today_summary === 'object') {
        this.todaySummary = data.today_summary;
      } else if (typeof data?.today_effective_seconds === 'number') {
        const seconds = Math.max(0, data.today_effective_seconds);
        this.todaySummary = {
          ...(this.todaySummary || {}),
          effective_seconds: seconds,
          effective_formatted: this.formatDuration(seconds)
        };
      }
      return data;
    },

    async loadShiftStatus() {
      try {
        const response = await api.get('/technician/shift/status');
        this.applyShiftResponse(response, 'OFF_SHIFT');
      } catch (err) {
        this.shiftError = err?.message || 'No se pudo consultar tu jornada.';
      }
    },

    async startShift() {
      await this.runShiftAction('/technician/shift/start', 'ON_SHIFT', 'No se pudo iniciar el turno.');
    },

    async pauseMealShift() {
      await this.runShiftAction('/technician/shift/pause-meal', 'MEAL_BREAK', 'No se pudo pausar para comer.');
    },

    async resumeMealShift() {
      await this.runShiftAction('/technician/shift/resume', 'ON_SHIFT', 'No se pudo reanudar el turno.');
    },

    /**
     * Cierre directo (RF-04.2, D1): sin relevo de averías ni traspaso de
     * tickets SAT. Desde `MEAL_BREAK` el servidor cierra igualmente la jornada.
     */
    async closeShift() {
      this.isShiftLoading = true;
      this.shiftError = '';
      this.feedbackMessage = '';
      try {
        const response = await api.post('/technician/shift/close', {});
        this.applyShiftResponse(response, 'OFF_SHIFT');
        this.activeShiftId = null;
        this.feedbackMessage = 'Jornada cerrada. Tu ruta de reposición queda fuera de turno.';
      } catch (err) {
        this.shiftError = err?.message || 'No se pudo cerrar el turno.';
      } finally {
        this.isShiftLoading = false;
      }
    },

    async runShiftAction(url, fallbackStatus, errorMessage) {
      this.isShiftLoading = true;
      this.shiftError = '';
      this.feedbackMessage = '';
      try {
        const response = await api.post(url);
        this.applyShiftResponse(response, fallbackStatus);
      } catch (err) {
        this.shiftError = err?.message || errorMessage;
      } finally {
        this.isShiftLoading = false;
      }
    },

    /** Autoconsulta de solo lectura, sin identificador de compañero (Art. V.4). */
    openMyHours() {
      this.showMyHoursModal = true;
      this.myHoursError = '';
      this.myHours = { month: '', from_date: '', to_date: '', summary: {}, sessions: [] };
      return this.loadMyHours();
    },

    closeMyHours() {
      this.showMyHoursModal = false;
    },

    async loadMyHours() {
      this.myHoursLoading = true;
      this.myHoursError = '';
      try {
        const response = await api.technicianShift.getMyHours();
        const data = response && (response.summary || response.sessions || response.month)
          ? response
          : (response?.data && typeof response.data === 'object' ? response.data : {});
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

    formatDuration(seconds) {
      const total = Math.max(0, Number(seconds) || 0);
      const hours = Math.floor(total / 3600);
      const minutes = Math.floor((total % 3600) / 60);
      return `${String(hours).padStart(2, '0')}h ${String(minutes).padStart(2, '0')}m`;
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

    hasShiftOriginalMarks(session) {
      return Boolean(
        session?.is_adjusted
        || session?.original_started_at
        || session?.original_closed_at
        || session?.original_meal_break_seconds
      );
    },

    zoneRoleLabel(zone) {
      return zone?.role_in_zone === 'PRIMARY' ? 'Titular' : 'Suplente';
    },

    machineTypeLabel(type) {
      const labels = {
        HOT_DRINKS: 'Bebidas calientes',
        COLD_DRINKS: 'Bebidas frías',
        SNACKS: 'Snacks',
        PERISHABLE_FOOD: 'Perecederos',
        COMBO: 'Mixta'
      };
      return labels[type] || type || '—';
    },

    sanitaryStatusLabel(status) {
      const labels = {
        OK: 'Operativa y vigente',
        ATTENTION_REQUIRED: 'Requiere atención',
        EXPIRED: 'Inspección vencida',
        QUARANTINE: 'Cuarentena sanitaria',
        SEASONAL_PAUSE: 'Pausa estacional'
      };
      return labels[status] || 'Sin estado sanitario';
    },

    sanitaryStatusStyle(status) {
      if (status === 'OK') {
        return { backgroundColor: 'var(--color-success-bg)', color: 'var(--color-success-text)' };
      }
      if (status === 'ATTENTION_REQUIRED') {
        return { backgroundColor: 'var(--color-warning-bg)', color: 'var(--color-warning-text)' };
      }
      if (status === 'EXPIRED' || status === 'QUARANTINE') {
        return { backgroundColor: 'var(--color-error-bg)', color: 'var(--color-error-text)' };
      }
      return { backgroundColor: 'var(--color-surface-1)', color: 'var(--color-ink-muted)' };
    },

    categoryLabel(category) {
      const labels = {
        COLD_DRINK: 'Bebida fría',
        SNACK: 'Snack',
        PERISHABLE_FOOD: 'Perecedero',
        HOT_BEVERAGE_INGREDIENT: 'Ingrediente',
        CUP_STIRRER_CONSUMABLE: 'Consumible'
      };
      return labels[category] || category || '—';
    },

    unitTypeLabel(unitType) {
      const labels = { UNIT: 'Unidad', GRAMS: 'Gramos', SLEEVE: 'Tira' };
      return labels[unitType] || unitType || '—';
    },

    machineHasAlert(machine) {
      return Number(machine?.stockout_slots_count || 0) > 0
        || Number(machine?.low_stock_slots_count || 0) > 0;
    },

    stockStatusLabel(status) {
      const labels = { STOCKOUT: 'Agotado', LOW_STOCK: 'Bajo umbral', OK: 'Abastecido' };
      return labels[status] || status || '—';
    },

    stockStatusStyle(status) {
      if (status === 'STOCKOUT') {
        return {
          backgroundColor: 'var(--color-error-bg)',
          color: 'var(--color-error-text)'
        };
      }
      if (status === 'LOW_STOCK') {
        return {
          backgroundColor: 'var(--color-warning-bg)',
          color: 'var(--color-warning-text)'
        };
      }
      return {
        backgroundColor: 'var(--color-success-bg)',
        color: 'var(--color-success-text)'
      };
    },

    stockStatusColor(status) {
      if (status === 'STOCKOUT') return 'var(--color-error)';
      if (status === 'LOW_STOCK') return 'var(--color-warning)';
      return 'var(--color-success)';
    },

    slotFillPercent(slot) {
      if (slot?.fill_rate_percent !== undefined && slot?.fill_rate_percent !== null) {
        return Math.max(0, Math.min(100, Math.round(Number(slot.fill_rate_percent))));
      }
      const capacity = Number(slot?.max_capacity);
      if (!Number.isFinite(capacity) || capacity <= 0) return 0;
      return Math.max(0, Math.min(100, Math.round((Number(slot.current_estimated_qty) / capacity) * 100)));
    },

    formatPrice(value) {
      const number = Number(value);
      return Number.isFinite(number) ? `${number.toFixed(2)} €` : '—';
    },

    openPlanogram(machine) {
      if (!machine?.id) return;
      this.selectedMachine = machine;
      this.planogram = null;
      this.planogramError = '';
      this.showPlanogramModal = true;
      return this.loadPlanogram(machine.id);
    },

    closePlanogram() {
      this.showPlanogramModal = false;
      this.selectedMachine = null;
      this.planogram = null;
      this.planogramError = '';
    },

    async loadPlanogram(machineId) {
      this.planogramLoading = true;
      this.planogramError = '';
      try {
        const response = await api.restocker.getMachinePlanogram(machineId);
        const data = response?.data && typeof response.data === 'object' ? response.data : (response || {});
        this.planogram = data?.machine ? data : null;
        if (!this.planogram) {
          this.planogramError = 'No se pudo leer el planograma de esta máquina.';
        }
      } catch (err) {
        this.planogramError = err?.message || 'No se pudo cargar el planograma. Comprueba que la máquina sigue en tus zonas.';
      } finally {
        this.planogramLoading = false;
      }
    },

    machineCanRestock(machine) {
      if (!machine) return false;
      if (machine.can_restock === false) return false;
      if (machine.is_blocked_no_access === true) return false;
      return String(machine.sanitary_status || '') !== 'QUARANTINE';
    },

    machineRestockBlockReason(machine) {
      const sanitaryStatus = String(machine?.sanitary_status || '');
      if (sanitaryStatus === 'QUARANTINE') {
        return 'Máquina en cuarentena sanitaria: no se puede iniciar una visita de reposición.';
      }
      if (machine?.is_blocked_no_access === true) {
        return 'Máquina fuera de servicio (acceso bloqueado): no se puede iniciar una visita de reposición.';
      }
      if (machine?.is_seasonal_pause === true) {
        return 'Máquina en pausa estacional: no admite visitas de reposición.';
      }

      return 'Esta máquina no admite una visita de reposición ahora mismo.';
    },

    machineStockStatus(machine) {
      if (Number(machine?.stockout_slots_count || 0) > 0) return 'STOCKOUT';
      if (Number(machine?.low_stock_slots_count || 0) > 0) return 'LOW_STOCK';
      return 'OK';
    },

    visitStatusLabel(status) {
      const labels = {
        COMPLETED: 'Completada',
        ABORTED_THERMAL_BREACH: 'Abortada por temperatura',
        PENDING: 'Pendiente de sincronizar'
      };
      return labels[status] || status || '—';
    },

    visitStatusIcon(status) {
      if (status === 'ABORTED_THERMAL_BREACH') return '🌡️';
      if (status === 'PENDING') return '📥';
      return '✅';
    },

    visitStatusStyle(status) {
      if (status === 'ABORTED_THERMAL_BREACH') {
        return { backgroundColor: 'var(--color-error-bg)', color: 'var(--color-error-text)' };
      }
      if (status === 'PENDING') {
        return { backgroundColor: 'var(--color-warning-bg)', color: 'var(--color-warning-text)' };
      }
      return { backgroundColor: 'var(--color-success-bg)', color: 'var(--color-success-text)' };
    },

    visitBatchLabel(batch) {
      const expiry = batch?.expiration_date ? ` · cad. ${this.formatShiftDate(batch.expiration_date)}` : '';
      return `${batch?.batch_code || 'SIN-LOTE'}${expiry} · ${Number(batch?.quantity_available || 0)} uds.`;
    },

    visitSelectedBatch(item) {
      if (!item) return null;
      return (item.available_van_batches || []).find(
        (batch) => String(batch.batch_code) === String(item.loaded_batch_code)
      ) || null;
    },

    visitItemWithdrawn(item) {
      return Number(item?.qty_withdrawn_expired || 0) + Number(item?.qty_withdrawn_damaged || 0);
    },

    visitItemMustWithdrawBeforeLoading(item) {
      if (!item) return false;
      const requiresFefo = item.fefo_withdrawal_required === true
        || item.slot_expiry_status === 'EXPIRED'
        || item.slot_expiry_status === 'EXPIRING_SOON';
      return requiresFefo && this.visitItemWithdrawn(item) < Number(item.qty_before || 0);
    },

    visitItemNeedsWithdrawal(item) {
      if (!item) return false;
      if (item.slot_expiry_status === 'EXPIRED') {
        return this.visitItemWithdrawn(item) < Number(item.qty_before || 0);
      }
      return Number(item.qty_added || 0) > 0 && this.visitItemMustWithdrawBeforeLoading(item);
    },

    visitItemQtyAfter(item) {
      return Number(item?.qty_before || 0) - this.visitItemWithdrawn(item) + Number(item?.qty_added || 0);
    },

    visitItemMaxAddable(item) {
      if (!item || this.visitInputsLocked || this.visitItemMustWithdrawBeforeLoading(item)) return 0;
      const batch = this.visitSelectedBatch(item);
      const space = Number(item.max_capacity || 0) - this.visitItemQtyAfter(item);
      return Math.max(0, Math.min(space, Number(batch?.quantity_available || 0)));
    },

    withdrawVisitRemainder(item) {
      if (!item || this.visitInputsLocked) return;
      item.qty_withdrawn_expired = Math.max(0, Number(item.qty_before || 0));
      item.qty_withdrawn_damaged = 0;
      item.qty_added = 0;
      this.visitError = '';
    },

    fillVisitItem(item) {
      if (!item || this.visitInputsLocked) return;
      if (this.visitItemMustWithdrawBeforeLoading(item)) {
        this.visitError = `Carril ${item.slot_code}: retira primero el remanente FEFO antes de cargar.`;
        return;
      }
      const maxAddable = this.visitItemMaxAddable(item);
      if (maxAddable <= 0) {
        this.visitError = `Carril ${item.slot_code}: no hay hueco o lote de furgoneta disponible.`;
        return;
      }
      item.qty_added = maxAddable;
      this.visitError = '';
    },

    markHygieneComplete() {
      for (const entry of this.visitHygieneItems) {
        this.visitForm.hygiene_checklist[entry.key] = entry.required ? true : null;
      }
    },

    initializeVisitItems(slots) {
      return (Array.isArray(slots) ? slots : []).map((slot) => {
        const expiryStatus = String(slot.slot_expiry_status || 'OK');
        const batches = Array.isArray(slot.available_van_batches)
          ? slot.available_van_batches.map((batch) => ({
            product_id: Number(batch.product_id ?? slot.product_id),
            batch_code: String(batch.batch_code || ''),
            expiration_date: batch.expiration_date || null,
            quantity_available: Number(batch.quantity_available || 0)
          })).filter((batch) => batch.batch_code !== '' && batch.quantity_available > 0)
          : [];
        const recommended = batches.some(
          (batch) => String(batch.batch_code) === String(slot.fefo_recommended_batch_code)
        ) ? String(slot.fefo_recommended_batch_code) : (batches[0]?.batch_code || '');

        return {
          slot_id: slot.id ?? null,
          slot_code: String(slot.slot_code || ''),
          product_id: Number(slot.product_id || 0),
          product_sku: slot.product_sku || null,
          product_name: slot.product_name || 'Producto sin nombre',
          qty_before: Number(slot.current_estimated_qty || 0),
          max_capacity: Number(slot.max_capacity || 0),
          min_threshold: Number(slot.min_threshold || 0),
          slot_expiry_status: expiryStatus,
          current_batch_code: slot.current_batch_code || null,
          current_expiration_date: slot.current_expiration_date || null,
          fefo_withdrawal_required: slot.fefo_withdrawal_required === true
            || expiryStatus === 'EXPIRED'
            || expiryStatus === 'EXPIRING_SOON',
          available_van_batches: batches,
          loaded_batch_code: recommended,
          qty_withdrawn_expired: 0,
          qty_withdrawn_damaged: 0,
          qty_added: 0
        };
      });
    },

    async openVisitAssistant(machine) {
      if (!machine?.id) return;
      if (!this.machineCanRestock(machine)) {
        this.loadingActionError = this.machineRestockBlockReason(machine);
        return;
      }

      this.visitMachine = machine;
      this.visitPlanogram = null;
      this.visitError = '';
      this.visitSuccess = '';
      this.loadingActionError = '';
      this.showVisitModal = true;
      this.visitForm = blankVisitForm();
      this.visitForm.client_mutation_id = createClientMutationId();
      this.visitForm.started_at = new Date().toISOString();
      this.visitForm.hygiene_checklist = hygieneDefaults(machine.machine_type);
      this.visitCashEnabled = false;
      this.visitCashForm = blankCashForm();
      this.cashContext = 'INDEPENDENT';
      this.cashMachine = null;
      this.showCashModal = false;
      this.cashError = '';
      this.cashSuccess = '';
      this.clearVisitPhoto();
      await this.loadVisitPlanogram(machine.id);
    },

    closeVisitAssistant() {
      this.showVisitModal = false;
      this.visitMachine = null;
      this.visitPlanogram = null;
      this.visitError = '';
      this.visitLoading = false;
      this.visitSubmitting = false;
      this.visitCashEnabled = false;
      this.visitCashForm = blankCashForm();
      this.showCashModal = false;
      this.cashContext = 'INDEPENDENT';
      this.cashMachine = null;
      this.clearVisitPhoto();
    },

    async loadVisitPlanogram(machineId) {
      this.visitLoading = true;
      this.visitError = '';
      try {
        const response = await api.restocker.getMachinePlanogram(machineId);
        const data = this.normalizeApiData(response);
        this.visitPlanogram = data?.machine ? data : null;
        if (!this.visitPlanogram) {
          this.visitError = 'No se pudo leer el planograma para preparar la visita.';
          return;
        }
        this.visitForm.items = this.initializeVisitItems(this.visitPlanogram.slots);
      } catch (error) {
        this.visitError = error?.message || 'No se pudo cargar el planograma para la visita.';
      } finally {
        this.visitLoading = false;
      }
    },

    normalizeApiData(response) {
      return response?.data && typeof response.data === 'object' ? response.data : (response || {});
    },

    realLength(value) {
      return String(value ?? '').trim().length;
    },

    handleVisitPhoto(event) {
      const file = event?.target?.files?.[0];
      if (!file) return;

      this.visitError = '';
      const size = Number(file.size || 0);
      if (size > 5 * 1024 * 1024) {
        this.visitError = 'La fotografía no puede superar 5 MB (Art. V.5).';
        this.visitPhotoFile = null;
        this.visitPhotoName = '';
        this.visitPhotoPreviewUrl = '';
        if (event.target) event.target.value = '';
        return;
      }

      const allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
      const mime = String(file.type || '').toLowerCase();
      if (mime !== '' && !allowed.includes(mime)) {
        this.visitError = 'La evidencia debe ser una imagen JPG, PNG o WebP (Art. V.5).';
        this.visitPhotoFile = null;
        this.visitPhotoName = '';
        this.visitPhotoPreviewUrl = '';
        if (event.target) event.target.value = '';
        return;
      }

      this.clearVisitPhoto();
      this.visitPhotoFile = file;
      this.visitPhotoName = String(file.name || 'visit-evidence');
      if (typeof URL !== 'undefined' && typeof URL.createObjectURL === 'function' && typeof Blob !== 'undefined' && file instanceof Blob) {
        this.visitPhotoPreviewUrl = URL.createObjectURL(file);
      }
    },

    clearVisitPhoto() {
      if (this.visitPhotoPreviewUrl && typeof URL !== 'undefined' && typeof URL.revokeObjectURL === 'function') {
        URL.revokeObjectURL(this.visitPhotoPreviewUrl);
      }
      this.visitPhotoPreviewUrl = '';
      this.visitPhotoFile = null;
      this.visitPhotoName = '';
      if (this.visitForm) this.visitForm.photo_evidence_url = '';
    },

    isNetworkFailure(error) {
      return !this.navigatorOnline
        || error?.status === 0
        || error?.code === 'NETWORK_ERROR'
        || error?.code === 'REQUEST_TIMEOUT';
    },

    /**
     * Fallo que conserva la captura en `PENDING` (`RF-05`, `C4`, `D4`).
     *
     * Un 401 o un 403 de la puerta de sesión (`FORBIDDEN`/`FORBIDDEN_ROLE`)
     * no invalida el dato: la sesión caducó mientras el operario estaba sin
     * cobertura y volverá a estar vigente al iniciar sesión. Un 5xx es una
     * avería transitoria del servidor. Los 403 de negocio —máquina fuera de
     * zona, rol sobre la operación o visita ajena— y los 409/422 siguen siendo
     * irreversibles para esa captura y se archivan como `CONFLICT`.
     */
    isRetryableSyncFailure(error) {
      if (this.isNetworkFailure(error)) return true;
      const status = Number(error?.status);
      if (status === 401) return true;
      if (status === 403) {
        const code = String(error?.code || '');
        return code === 'FORBIDDEN' || code === 'FORBIDDEN_ROLE';
      }
      return status >= 500 && status <= 599;
    },

    /** Motivo visible del reintento; nunca consume la entrada de la cola. */
    syncRetryMessage(error, noun) {
      const status = Number(error?.status);
      if (status === 401 || status === 403) {
        return `La sesión ha caducado: las ${noun} siguen en la cola. Vuelve a iniciar sesión para sincronizarlas.`;
      }
      if (status >= 500) {
        return `El servidor no está disponible ahora mismo: las ${noun} siguen en la cola y se reintentarán más tarde.`;
      }
      return `Se perdió la conexión durante la sincronización. La cola conserva las ${noun} restantes.`;
    },

    validateVisit() {
      this.visitError = '';
      if (!this.visitMachine?.id) {
        this.visitError = 'No hay una máquina seleccionada para la visita.';
        return false;
      }
      if (!this.visitPlanogram || this.visitLoading) {
        this.visitError = 'Espera a que cargue el planograma de la máquina.';
        return false;
      }

      const temperature = this.visitCabinTemperature;
      if (this.visitTemperatureRequired && temperature === null) {
        this.visitError = 'Registra la temperatura de cabina antes de continuar (Art. II).';
        return false;
      }
      if (temperature !== null && (temperature < -5 || temperature > 35)) {
        this.visitError = 'La temperatura de cabina debe estar entre -5 °C y 35 °C.';
        return false;
      }

      if (this.visitIsThermalAbort) {
        if (this.visitTotalAdded > 0 || this.visitTotalWithdrawn > 0) {
          this.visitError = 'El aborto térmico no puede cargar ni retirar existencias.';
          return false;
        }
        if (this.visitForm.precautionary_seal_applied !== true) {
          this.visitError = 'Confirma el precinto de seguridad antes de abortar la visita (Art. II).';
          return false;
        }
        if (!this.visitPhotoReady) {
          this.visitError = 'El aborto térmico exige una fotografía de evidencia (Art. II).';
          return false;
        }
        if (this.realLength(this.visitForm.internal_notes) < 20) {
          this.visitError = 'El aborto térmico exige una justificación de al menos 20 caracteres reales.';
          return false;
        }
        return true;
      }

      if (this.visitHygieneMissing.length > 0) {
        this.visitError = `Completa el checklist de higiene: falta ${this.visitHygieneMissing.map((item) => item.label).join(', ')}.`;
        return false;
      }

      if (this.visitHasWithdrawals && !this.visitPhotoReady) {
        this.visitError = 'Toda retirada FEFO exige fotografía de evidencia (Art. II).';
        return false;
      }
      if (this.visitHasWithdrawals && this.realLength(this.visitForm.internal_notes) < 20) {
        this.visitError = 'Toda retirada FEFO exige una justificación de al menos 20 caracteres reales.';
        return false;
      }

      for (const item of this.visitForm.items) {
        if (this.visitItemNeedsWithdrawal(item)) {
          this.visitError = `Carril ${item.slot_code}: retira el remanente FEFO antes de cargar.`;
          return false;
        }

        const after = this.visitItemQtyAfter(item);
        if (after < 0 || after > Number(item.max_capacity || 0)) {
          this.visitError = `Carril ${item.slot_code}: la cantidad final no cabe en el carril.`;
          return false;
        }

        if (Number(item.qty_added || 0) > 0) {
          if (!item.loaded_batch_code) {
            this.visitError = `Carril ${item.slot_code}: selecciona un lote de la furgoneta.`;
            return false;
          }
          const batch = this.visitSelectedBatch(item);
          if (!batch || Number(batch.quantity_available || 0) < Number(item.qty_added || 0)) {
            this.visitError = `Carril ${item.slot_code}: el lote seleccionado no tiene saldo suficiente.`;
            return false;
          }
        }
      }

      if (this.visitCashEnabled) {
        const cash = this.validateCashForm(this.visitCashForm, this.visitMachine, true);
        if (!cash.ok) {
          this.visitError = cash.error;
          return false;
        }
      }

      return true;
    },

    buildVisitPayload() {
      const items = this.visitIsThermalAbort ? [] : this.visitForm.items.map((item) => ({
        slot_code: item.slot_code,
        qty_withdrawn_expired: Number(item.qty_withdrawn_expired || 0),
        qty_withdrawn_damaged: Number(item.qty_withdrawn_damaged || 0),
        qty_added: Number(item.qty_added || 0),
        loaded_batch_code: item.loaded_batch_code || null
      }));

      const payload = {
        client_mutation_id: this.visitForm.client_mutation_id,
        status: this.visitMode,
        started_at: this.visitForm.started_at,
        completed_at: new Date().toISOString(),
        cabin_temp_celsius: this.visitCabinTemperature,
        precautionary_seal_applied: this.visitForm.precautionary_seal_applied === true,
        photo_evidence_url: this.visitForm.photo_evidence_url || null,
        internal_notes: String(this.visitForm.internal_notes || '').trim() || null,
        hygiene_checklist: { ...this.visitForm.hygiene_checklist },
        items
      };

      // Art. II.2: el bloque de efectivo jamás viaja dentro de un aborto térmico.
      if (this.visitCashEnabled && !this.visitIsThermalAbort) {
        payload.cash_collection = this.buildCashCollectionPayload(this.visitCashForm);
      }

      return payload;
    },

    extractPhotoEvidenceUrl(response) {
      const url = response?.photo_evidence_url
        ?? response?.data?.photo_evidence_url
        ?? response?.url
        ?? null;
      return typeof url === 'string' ? url : '';
    },

    ensureVisitQueue() {
      if (!this.visitQueue) {
        this.visitQueue = new RestockVisitOfflineQueue();
      }
      return this.visitQueue;
    },

    ensureIncidentQueue() {
      if (!this.incidentQueue) {
        this.incidentQueue = new RestockerIncidentOfflineQueue();
      }
      return this.incidentQueue;
    },

    async loadPendingIncidentQueue() {
      const restockerId = this.visitRestockerId;
      if (!restockerId) {
        this.pendingIncidentMutations = [];
        return;
      }
      try {
        this.pendingIncidentMutations = await this.ensureIncidentQueue().list(restockerId);
      } catch (error) {
        this.pendingIncidentMutations = [];
        this.incidentSyncError = error?.message || 'No se pudo leer la cola offline de avisos L1.';
      }
    },

    l1IssueLabel(type) {
      const labels = {
        STOCKOUT_EMPTY_SLOT: 'Producto agotado / Carril vacío',
        MISSING_CONSUMABLES: 'Sin vasos / azúcar / paletinas',
        EMPTY_CHANGE_TUBES: 'No devuelve cambio (tubos vacíos)',
        MINOR_SPIRAL_JAM: 'Atasco leve en espiral'
      };
      return labels[type] || type || 'Aviso de Nivel 1';
    },

    l1StatusLabel(status) {
      const labels = { ASSIGNED: 'Pendiente', IN_PROGRESS: 'En curso', PENDING_INFO: 'En espera de sede', REOPENED: 'Reabierta' };
      return labels[status] || status || 'Pendiente';
    },

    siteCoincidenceForLocation(locationId) {
      const rows = this.siteCoincidence?.[String(locationId)] ?? this.siteCoincidence?.[locationId];
      return Array.isArray(rows) ? rows : [];
    },

    siteCoincidenceSummary(locationId) {
      return this.siteCoincidenceForLocation(locationId)
        .map((entry) => `${entry.name}${entry.activity_summary ? ` (${entry.activity_summary})` : ''}`)
        .join(' · ');
    },

    machineHasActiveL1(machine) {
      return Boolean(machine?.active_l1_incident)
        || this.assignedL1Incidents.some((incident) => Number(incident.machine_id) === Number(machine?.id));
    },

    machineHasActiveSat(machine) {
      return machine?.has_active_sat_incident === true;
    },

    scrollToL1Incidents() {
      if (typeof document === 'undefined') return;
      document.getElementById('restocker-l1-incidents')?.scrollIntoView({ behavior: 'smooth' });
    },

    charCount(value) {
      return Array.from(String(value ?? '').trim()).length;
    },

    async startL1(incident) {
      const restockerId = this.visitRestockerId;
      if (!restockerId || !incident) return;
      const mutationId = createClientMutationId();
      const payload = { client_mutation_id: mutationId };
      try {
        if (!this.navigatorOnline) {
          await this.enqueueIncidentOperation(RESTOCKER_INCIDENT_OPERATIONS.START_L1, incident, payload);
          incident.status = 'IN_PROGRESS';
          this.incidentSyncMessage = 'Intervención L1 guardada en la cola offline.';
          await this.loadPendingIncidentQueue();
          return;
        }
        await api.restocker.startL1Incident(incident.id, payload);
        this.incidentSyncMessage = 'Intervención de Nivel 1 iniciada.';
        await this.loadMyRoute();
      } catch (error) {
        this.incidentSyncError = error?.message || 'No se pudo iniciar el aviso de Nivel 1.';
      }
    },

    openL1Resolve(incident) {
      const firstCause = this.l1TaxonomyCatalog[0] || null;
      const firstSolution = Array.isArray(firstCause?.solutions) ? firstCause.solutions[0] : null;
      this.l1ResolveIncident = incident;
      this.l1ResolveForm = {
        root_cause_id: firstCause?.id ?? '',
        standard_solution_id: firstSolution?.id ?? '',
        diagnosis: '',
        action: '',
        client_mutation_id: createClientMutationId()
      };
      this.l1ResolveError = '';
      this.l1ResolveSuccess = '';
      this.showL1ResolveModal = true;
    },

    onL1RootCauseChange() {
      const first = this.l1ResolveSolutions[0] || null;
      this.l1ResolveForm.standard_solution_id = first?.id ?? '';
    },

    async submitL1Resolution() {
      const incident = this.l1ResolveIncident;
      const restockerId = this.visitRestockerId;
      if (!incident || !restockerId || this.isResolvingL1) return;
      if (this.charCount(this.l1ResolveForm.diagnosis) < 20 || this.charCount(this.l1ResolveForm.action) < 20) {
        this.l1ResolveError = 'El diagnóstico y la acción deben contener al menos 20 caracteres reales (Art. V.1).';
        return;
      }
      if (!this.l1ResolveForm.root_cause_id || !this.l1ResolveForm.standard_solution_id) {
        this.l1ResolveError = 'Selecciona la causa raíz y la solución estandarizada de Nivel 1.';
        return;
      }

      this.isResolvingL1 = true;
      this.l1ResolveError = '';
      const machine = this.machines.find((row) => Number(row.id) === Number(incident.machine_id)) || {};
      const payload = {
        machine_id: Number(incident.machine_id),
        sanitary_status: machine.sanitary_status ?? null,
        root_cause_id: Number(this.l1ResolveForm.root_cause_id),
        standard_solution_id: Number(this.l1ResolveForm.standard_solution_id),
        diagnosis: String(this.l1ResolveForm.diagnosis).trim(),
        action: String(this.l1ResolveForm.action).trim(),
        client_mutation_id: this.l1ResolveForm.client_mutation_id
      };
      try {
        if (!this.navigatorOnline) {
          await this.enqueueIncidentOperation(RESTOCKER_INCIDENT_OPERATIONS.RESOLVE_L1, incident, payload);
          this.l1ResolveSuccess = 'Resolución L1 guardada en la cola offline.';
          this.showL1ResolveModal = false;
          this.incidentSyncMessage = 'Resolución L1 pendiente de sincronizar.';
          await this.loadPendingIncidentQueue();
          return;
        }
        await api.restocker.resolveL1Incident(incident.id, payload);
        this.l1ResolveSuccess = 'Nivel 1 resuelto y custodia cerrada.';
        this.showL1ResolveModal = false;
        await this.loadMyRoute();
      } catch (error) {
        this.l1ResolveError = error?.message || 'No se pudo resolver el aviso de Nivel 1.';
      } finally {
        this.isResolvingL1 = false;
      }
    },

    openEscalate(machine, incident = null) {
      this.escalateMachine = machine;
      this.escalateIncident = incident;
      this.escalateForm = {
        category: incident?.category || 'ELECTRICAL_OFF',
        workshop_internal_note: '',
        photo_evidence_url: '',
        client_mutation_id: createClientMutationId()
      };
      this.escalatePhotoFile = null;
      this.escalatePhotoName = '';
      this.escalateError = '';
      this.escalateSuccess = '';
      this.showEscalateModal = true;
    },

    onEscalatePhotoChange(event) {
      const file = event?.target?.files?.[0] ?? null;
      if (file && Number(file.size) > 5 * 1024 * 1024) {
        this.escalatePhotoFile = null;
        this.escalatePhotoName = '';
        this.escalateError = 'La fotografía no puede superar los 5 MB (Art. V.5).';
        return;
      }
      this.escalatePhotoFile = file;
      this.escalatePhotoName = file?.name || '';
      this.escalateError = '';
    },

    async submitEscalation() {
      const machine = this.escalateMachine;
      const restockerId = this.visitRestockerId;
      if (!machine || !restockerId || this.isEscalating) return;
      if (this.charCount(this.escalateForm.workshop_internal_note) < 20) {
        this.escalateError = 'La Nota Interna de Taller debe contener al menos 20 caracteres reales (Art. V.1).';
        return;
      }
      if (!this.escalatePhotoFile && !this.escalateForm.photo_evidence_url) {
        this.escalateError = 'Adjunta una fotografía de evidencia de hasta 5 MB (Art. V.5).';
        return;
      }

      this.isEscalating = true;
      this.escalateError = '';
      const payload = {
        category: this.escalateForm.category,
        workshop_internal_note: String(this.escalateForm.workshop_internal_note).trim(),
        client_mutation_id: this.escalateForm.client_mutation_id
      };
      try {
        if (!this.navigatorOnline) {
          await this.enqueueIncidentOperation(
            RESTOCKER_INCIDENT_OPERATIONS.ESCALATE_TO_SAT,
            { id: this.escalateIncident?.id ?? null, machine_id: machine.id, machine_code: machine.code },
            payload,
            this.escalatePhotoFile
          );
          this.escalateSuccess = 'Escalado guardado en la cola offline.';
          this.showEscalateModal = false;
          this.incidentSyncMessage = 'Escalado al SAT pendiente de sincronizar.';
          await this.loadPendingIncidentQueue();
          return;
        }

        let photoPath = this.escalateForm.photo_evidence_url;
        if (this.escalatePhotoFile) {
          const uploaded = await api.restocker.uploadIncidentPhoto(this.escalatePhotoFile, this.escalatePhotoName);
          photoPath = this.extractPhotoEvidenceUrl(uploaded);
          if (!photoPath) throw new Error('La fotografía no devolvió una URL válida.');
        }
        await api.restocker.escalateMachineToSat(machine.id, {
          ...payload,
          photo_path: photoPath,
          photo_size_bytes: Number(this.escalatePhotoFile?.size || 0),
          photo_mime_type: String(this.escalatePhotoFile?.type || '')
        });
        this.escalateSuccess = 'Avería escalada al SAT.';
        this.showEscalateModal = false;
        await this.loadMyRoute();
      } catch (error) {
        this.escalateError = error?.message || 'No se pudo escalar la avería al SAT.';
      } finally {
        this.isEscalating = false;
      }
    },

    async enqueueIncidentOperation(operation, incident, payload, photo = null) {
      const restockerId = this.visitRestockerId;
      if (!restockerId) throw new Error('Inicia sesión como reponedor para guardar la operación.');
      const sequence = Date.now() * 1000 + (Number(this.incidentQueueSequence || 0) % 1000);
      this.incidentQueueSequence = (Number(this.incidentQueueSequence || 0) + 1) % 1000;
      return this.ensureIncidentQueue().enqueue({
        client_mutation_id: payload.client_mutation_id,
        restocker_id: restockerId,
        operation_type: operation,
        incident_id: incident?.id ?? null,
        machine_id: incident?.machine_id ?? incident?.id,
        machine_code: incident?.machine_code ?? null,
        payload,
        photo,
        photo_name: this.escalatePhotoName || null,
        sequence_order: sequence
      });
    },

    async syncPendingIncidentMutations() {
      const restockerId = this.visitRestockerId;
      if (!restockerId) return;
      if (!this.navigatorOnline) {
        this.incidentSyncError = 'Sin conexión. Las operaciones L1 siguen en la cola offline.';
        return;
      }
      if (this.isSyncingIncidents) return;

      this.isSyncingIncidents = true;
      this.incidentSyncError = '';
      this.incidentSyncMessage = '';
      let synced = 0;
      let conflicts = 0;
      try {
        const queue = this.ensureIncidentQueue();
        const pending = await queue.list(restockerId, [RESTOCKER_INCIDENT_PENDING]);
        for (const item of pending) {
          try {
            const payload = { ...item.payload };
            if (item.operation_type === RESTOCKER_INCIDENT_OPERATIONS.START_L1) {
              await api.restocker.startL1Incident(item.incident_id, payload);
            } else if (item.operation_type === RESTOCKER_INCIDENT_OPERATIONS.RESOLVE_L1) {
              await api.restocker.resolveL1Incident(item.incident_id, payload);
            } else if (item.operation_type === RESTOCKER_INCIDENT_OPERATIONS.ESCALATE_TO_SAT) {
              if (item.photo) {
                const uploaded = await api.restocker.uploadIncidentPhoto(item.photo, item.photo_name);
                const photoPath = this.extractPhotoEvidenceUrl(uploaded);
                if (!photoPath) throw new Error('La fotografía del escalado no devolvió una URL válida.');
                payload.photo_path = photoPath;
                payload.photo_size_bytes = Number(item.photo.size || 0);
                payload.photo_mime_type = String(item.photo.type || '');
              }
              await api.restocker.escalateMachineToSat(item.machine_id, payload);
            }
            await queue.remove(item.client_mutation_id);
            synced += 1;
          } catch (error) {
            if (this.isRetryableSyncFailure(error)) {
              this.incidentSyncError = this.syncRetryMessage(error, 'avisos L1');
              break;
            }
            await queue.markConflict(
              item.client_mutation_id,
              error?.message || 'La operación L1 offline fue rechazada por el servidor.'
            );
            conflicts += 1;
          }
        }

        await this.loadPendingIncidentQueue();
        if (synced > 0) await this.loadMyRoute();
        if (synced > 0 || conflicts > 0) {
          this.incidentSyncMessage = `${synced} operación(es) L1 sincronizada(s).${conflicts > 0 ? ` ${conflicts} conflicto(s) pendientes de archivar.` : ''}`;
        } else if (!this.incidentSyncError) {
          this.incidentSyncMessage = 'No hay operaciones L1 pendientes de sincronizar.';
        }
      } catch (error) {
        this.incidentSyncError = error?.message || 'No se pudo sincronizar la cola offline de avisos L1.';
      } finally {
        this.isSyncingIncidents = false;
      }
    },

    async archiveIncidentConflict(item) {
      if (!item?.client_mutation_id || this.isSyncingIncidents) return;
      this.isSyncingIncidents = true;
      try {
        await api.restocker.dumpIncidentConflict({
          machine_id: item.machine_id,
          client_mutation_id: item.client_mutation_id,
          reason: item.conflict_reason || 'La operación L1 offline ya no puede aplicarse.',
          conflict: { operation_type: item.operation_type }
        });
        await this.ensureIncidentQueue().remove(item.client_mutation_id);
        await this.loadPendingIncidentQueue();
        this.incidentSyncMessage = 'Conflicto L1 archivado en auditoría.';
      } catch (error) {
        this.incidentSyncError = error?.message || 'No se pudo archivar el conflicto L1.';
      } finally {
        this.isSyncingIncidents = false;
      }
    },

    async loadPendingVisitQueue() {
      const restockerId = this.visitRestockerId;
      if (!restockerId) {
        this.pendingVisitMutations = [];
        return;
      }

      try {
        this.pendingVisitMutations = await this.ensureVisitQueue().list(restockerId);
      } catch (error) {
        this.pendingVisitMutations = [];
        this.visitSyncError = error?.message || 'No se pudo leer la cola offline de visitas.';
      }
    },

    async submitVisit() {
      if (this.visitSubmitting || !this.validateVisit()) return;

      const payload = this.buildVisitPayload();
      let photo = this.visitPhotoFile;
      this.visitSubmitting = true;
      this.visitSuccess = '';
      try {
        if (!this.navigatorOnline) {
          await this.queueOfflineVisit(payload, photo);
          return;
        }

        if (photo) {
          const uploaded = await api.restocker.uploadVisitPhoto(photo, this.visitPhotoName);
          const photoUrl = this.extractPhotoEvidenceUrl(uploaded);
          if (!photoUrl) {
            throw new Error('La subida de la fotografía no devolvió una URL de evidencia.');
          }
          payload.photo_evidence_url = photoUrl;
          this.visitForm.photo_evidence_url = photoUrl;
          photo = null;
        }

        const saved = await api.restocker.createRestockVisit(this.visitMachine.id, payload);
        const savedVisit = saved?.visit || saved;
        if (savedVisit && typeof savedVisit === 'object') {
          this.recentVisits = [savedVisit, ...this.recentVisits].slice(0, 5);
          if (savedVisit.cash_collection_summary) {
            await this.applyCashCollectionResult(savedVisit.cash_collection_summary);
          }
        }
        this.showVisitModal = false;
        this.visitSuccess = `Visita registrada en ${this.visitMachine.code || 'la máquina'}.`;
        this.loadingActionMessage = this.visitSuccess;
        await this.loadMyRoute();
      } catch (error) {
        if (this.isNetworkFailure(error)) {
          await this.queueOfflineVisit(payload, photo);
        } else {
          this.visitError = error?.message || 'No se pudo registrar la visita de reposición.';
        }
      } finally {
        this.visitSubmitting = false;
      }
    },

    async queueOfflineVisit(payload, photo = null) {
      const machine = this.visitMachine;
      const restockerId = this.visitRestockerId;
      if (!machine?.id || !restockerId) {
        this.visitError = 'No se pudo identificar la máquina o el reponedor para guardar la visita offline.';
        return;
      }

      try {
        const sequence = Date.now() * 1000 + (Number(this.visitQueueSequence || 0) % 1000);
        this.visitQueueSequence = (Number(this.visitQueueSequence || 0) + 1) % 1000;
        await this.ensureVisitQueue().enqueue({
          client_mutation_id: payload.client_mutation_id,
          restocker_id: restockerId,
          machine_id: machine.id,
          machine_code: machine.code || null,
          payload,
          photo,
          photo_name: this.visitPhotoName || photo?.name || null,
          created_at: payload.completed_at,
          sequence_order: sequence
        });
        this.applyOptimisticVisit(machine, payload);
        await this.loadPendingVisitQueue();
        this.showVisitModal = false;
        this.visitSuccess = 'Visita guardada en la cola offline. Se sincronizará al recuperar conexión.';
        this.loadingActionMessage = this.visitSuccess;
      } catch (error) {
        this.visitError = error?.message || 'No se pudo guardar la visita en la cola offline.';
      }
    },

    applyOptimisticVisit(machine, payload) {
      const items = this.visitForm.items || [];
      for (const item of items) {
        const added = Number(item.qty_added || 0);
        const withdrawn = this.visitItemWithdrawn(item);
        if (added > 0) {
          const stock = this.vanProductStocks.find(
            (row) => Number(row.product_id) === Number(item.product_id)
              && String(row.batch_code || 'SIN-LOTE') === String(item.loaded_batch_code || 'SIN-LOTE')
          );
          if (stock) {
            stock.quantity_available = Math.max(0, Number(stock.quantity_available || 0) - added);
            if (stock.usable_quantity !== undefined) {
              stock.usable_quantity = Math.max(0, Number(stock.usable_quantity || 0) - added);
            }
          }
        }

        const slot = this.visitPlanogram?.slots?.find(
          (row) => String(row.slot_code) === String(item.slot_code)
        );
        if (slot) {
          slot.current_estimated_qty = Math.max(0, Number(slot.current_estimated_qty || 0) - withdrawn + added);
          if (withdrawn >= Number(item.qty_before || 0)) {
            slot.fefo_withdrawal_required = false;
            slot.slot_expiry_status = 'OK';
            slot.current_batch_code = item.loaded_batch_code || null;
            slot.current_expiration_date = this.visitSelectedBatch(item)?.expiration_date || null;
          }
          slot.stock_status = slot.current_estimated_qty <= 0
            ? 'STOCKOUT'
            : (slot.current_estimated_qty <= Number(slot.min_threshold || 0) ? 'LOW_STOCK' : 'OK');
        }
      }

      const routeMachine = this.machines.find((row) => Number(row.id) === Number(machine.id));
      if (routeMachine && this.visitPlanogram?.slots) {
        const slots = this.visitPlanogram.slots.filter((slot) => slot.is_active !== false);
        const capacity = slots.reduce((total, slot) => total + Number(slot.max_capacity || 0), 0);
        const estimated = slots.reduce((total, slot) => total + Number(slot.current_estimated_qty || 0), 0);
        routeMachine.stockout_slots_count = slots.filter((slot) => Number(slot.current_estimated_qty || 0) <= 0).length;
        routeMachine.low_stock_slots_count = slots.filter((slot) => {
          const qty = Number(slot.current_estimated_qty || 0);
          return qty > 0 && qty <= Number(slot.min_threshold || 0);
        }).length;
        routeMachine.ok_slots_count = slots.filter((slot) => Number(slot.current_estimated_qty || 0) > Number(slot.min_threshold || 0)).length;
        routeMachine.total_capacity = capacity;
        routeMachine.total_estimated_qty = estimated;
        routeMachine.fill_rate_percent = capacity > 0 ? Math.round((estimated / capacity) * 10000) / 100 : 0;
        routeMachine.expired_slots_count = slots.filter((slot) => Number(slot.current_estimated_qty || 0) > 0 && slot.slot_expiry_status === 'EXPIRED').length;
        routeMachine.expiring_soon_slots_count = slots.filter((slot) => Number(slot.current_estimated_qty || 0) > 0 && slot.slot_expiry_status === 'EXPIRING_SOON').length;
        routeMachine.pending_visit_sync = true;
        this.visitMachine = routeMachine;
      }

      const localVisit = {
        id: `offline-${payload.client_mutation_id}`,
        visit_code: 'PENDIENTE',
        machine_id: machine.id,
        machine_code: machine.code || null,
        machine_location_name: machine.location_name || null,
        status: payload.status,
        completed_at: payload.completed_at,
        cabin_temp_celsius: payload.cabin_temp_celsius,
        units_added: items.reduce((total, item) => total + Number(item.qty_added || 0), 0),
        units_withdrawn: items.reduce((total, item) => total + this.visitItemWithdrawn(item), 0),
        is_offline_pending: true,
        cash_collection_pending: Boolean(payload.cash_collection)
      };
      this.recentVisits = [localVisit, ...this.recentVisits.filter((visit) => visit.id !== localVisit.id)].slice(0, 5);
    },

    async syncPendingVisits() {
      const restockerId = this.visitRestockerId;
      if (!restockerId) {
        this.visitSyncError = 'Inicia sesión como reponedor para sincronizar visitas.';
        return;
      }
      if (!this.navigatorOnline) {
        this.visitSyncError = 'Sin conexión. Las visitas seguirán guardadas en la cola offline.';
        return;
      }
      if (this.isSyncingVisits) return;

      this.isSyncingVisits = true;
      this.visitSyncError = '';
      this.visitSyncMessage = '';
      let synced = 0;
      let conflicts = 0;
      try {
        const queue = this.ensureVisitQueue();
        const pending = await queue.list(restockerId, [RESTOCK_VISIT_PENDING]);
        for (const item of pending) {
          try {
            const payload = { ...item.payload };
            if (item.photo) {
              const uploaded = await api.restocker.uploadVisitPhoto(item.photo, item.photo_name);
              const photoUrl = this.extractPhotoEvidenceUrl(uploaded);
              if (!photoUrl) throw new Error('La fotografía de la visita no devolvió una URL válida.');
              payload.photo_evidence_url = photoUrl;
            }
            await api.restocker.createRestockVisit(item.machine_id, payload);
            await queue.remove(item.client_mutation_id);
            synced += 1;
          } catch (error) {
            if (this.isRetryableSyncFailure(error)) {
              this.visitSyncError = this.syncRetryMessage(error, 'visitas');
              break;
            }
            await queue.markConflict(
              item.client_mutation_id,
              error?.message || 'La visita offline fue rechazada por el servidor.'
            );
            conflicts += 1;
          }
        }

        await this.loadPendingVisitQueue();
        if (synced > 0) await this.loadMyRoute();
        if (synced > 0 || conflicts > 0) {
          this.visitSyncMessage = `${synced} visita(s) sincronizada(s).${conflicts > 0 ? ` ${conflicts} conflicto(s) pendientes de archivar.` : ''}`;
        } else if (!this.visitSyncError) {
          this.visitSyncMessage = 'No hay visitas pendientes de sincronizar.';
        }
      } catch (error) {
        this.visitSyncError = error?.message || 'No se pudo sincronizar la cola offline de visitas.';
      } finally {
        this.isSyncingVisits = false;
      }
    },

    async archiveVisitConflict(item) {
      if (!item?.client_mutation_id || this.isSyncingVisits) return;
      this.isSyncingVisits = true;
      this.visitSyncError = '';
      this.visitSyncMessage = '';
      try {
        await api.restocker.dumpVisitConflict({
          machine_id: item.machine_id,
          client_mutation_id: item.client_mutation_id,
          reason: item.conflict_reason || 'La visita offline ya no puede aplicarse.'
        });
        await this.ensureVisitQueue().remove(item.client_mutation_id);
        await this.loadPendingVisitQueue();
        this.visitSyncMessage = 'Conflicto archivado en auditoría. La cola local queda limpia.';
      } catch (error) {
        this.visitSyncError = error?.message || 'No se pudo archivar el conflicto offline.';
      } finally {
        this.isSyncingVisits = false;
      }
    },

    expiryStatusLabel(status) {
      const labels = { EXPIRED: 'Lote caducado', EXPIRING_SOON: 'Caducidad próxima ≤ 48 h', OK: 'Vigente' };
      return labels[status] || status || 'Sin estado';
    },

    expiryStatusIcon(status) {
      if (status === 'EXPIRED') return '🔴';
      if (status === 'EXPIRING_SOON') return '🟡';
      return '🟢';
    },

    expiryStatusStyle(status) {
      if (status === 'EXPIRED') {
        return { backgroundColor: 'var(--color-error-bg)', color: 'var(--color-error-text)' };
      }
      if (status === 'EXPIRING_SOON') {
        return { backgroundColor: 'var(--color-warning-bg)', color: 'var(--color-warning-text)' };
      }
      return { backgroundColor: 'var(--color-success-bg)', color: 'var(--color-success-text)' };
    },

    async refreshVanStock() {
      try {
        const data = this.normalizeApiData(await api.restocker.getVanStocks());
        this.vanProductStocks = Array.isArray(data.stocks) ? data.stocks : [];
        this.vanStockKpis = data.kpis && typeof data.kpis === 'object' ? data.kpis : this.vanStockKpis;
        this.hasExpiredVanLots = data.has_expired_lots === true
          || Number(this.vanStockKpis.expired_lots || 0) > 0;
      } catch (error) {
        this.loadingActionError = error?.message || 'No se pudo refrescar el stock de la furgoneta.';
      }
    },

    /** Recepción atómica en un toque de la orden propia despachada (D3). */
    async confirmActiveLoadingOrder() {
      const order = this.activeLoadingOrderData;
      if (!order?.id || !this.activeLoadingOrderIsDispatched) return;

      this.isConfirmingLoading = true;
      this.loadingActionError = '';
      this.loadingActionMessage = '';
      try {
        const detail = this.normalizeApiData(await api.restocker.confirmLoadingOrder(order.id));
        if (detail?.order) {
          this.activeLoadingOrder = detail;
        } else {
          await this.loadMyRoute();
        }
        this.loadingActionMessage = `Recepción confirmada: ${order.order_code} ya está en tu furgoneta.`;
        await this.refreshVanStock();
      } catch (error) {
        this.loadingActionError = error?.message || 'No se pudo confirmar la recepción de la orden.';
      } finally {
        this.isConfirmingLoading = false;
      }
    },

    currentLoadingLotBalance(item) {
      const batch = item?.batch_code || 'SIN-LOTE';
      const stock = this.vanProductStocks.find(
        (row) => Number(row.product_id) === Number(item?.product_id)
          && String(row.batch_code || 'SIN-LOTE') === String(batch)
      );
      return Number(stock?.quantity_available ?? item?.van_stock_before_reconcile ?? 0);
    },

    /** Abre el cuadre con el saldo real por lote de la furgoneta (QA 4). */
    async openReconcileLoadingModal() {
      const order = this.activeLoadingOrderData;
      if (!order?.id || !this.activeLoadingOrderIsConfirmed) return;

      this.reconcileLoadingError = '';
      await this.refreshVanStock();
      this.reconcileLoadingForm = {
        notes: order.reconciliation_notes || '',
        items: this.activeLoadingOrderItems
          .filter((item) => item.is_active !== false && Number(item.dispatched_qty || 0) > 0)
          .map((item) => {
            const balance = this.currentLoadingLotBalance(item);
            const suggestedRestocked = Math.max(0, Number(item.suggested_restocked_in_machines_qty || 0));
            const suggestedWithdrawn = Math.max(0, Number(item.suggested_withdrawn_spoilage_qty || 0));
            return {
              product_id: item.product_id,
              product_sku: item.product_sku,
              product_name: item.product_name,
              batch_code: item.batch_code || 'SIN-LOTE',
              expiration_date: item.expiration_date,
              dispatched_qty: Number(item.dispatched_qty),
              van_stock_before_reconcile: balance,
              restocked_in_machines_qty: suggestedRestocked,
              withdrawn_spoilage_qty: suggestedWithdrawn,
              counted_return_qty: Math.max(0, balance - suggestedRestocked - suggestedWithdrawn)
            };
          })
      };
      this.showReconcileLoadingModal = true;
    },

    closeReconcileLoadingModal() {
      this.showReconcileLoadingModal = false;
      this.reconcileLoadingError = '';
    },

    reconcileLoadingExpected(item) {
      return Number(item.van_stock_before_reconcile || 0)
        - Number(item.restocked_in_machines_qty || 0)
        - Number(item.withdrawn_spoilage_qty || 0);
    },

    reconcileLoadingDiscrepancy(item) {
      return Number(item.counted_return_qty || 0) - this.reconcileLoadingExpected(item);
    },

    validateLoadingReconciliation() {
      this.reconcileLoadingError = '';
      if (this.reconcileLoadingForm.items.length === 0) {
        this.reconcileLoadingError = 'No hay líneas despachadas que liquidar.';
        return false;
      }

      const invalid = this.reconcileLoadingForm.items.find(
        (item) => Number(item.restocked_in_machines_qty || 0) < 0
          || Number(item.withdrawn_spoilage_qty || 0) < 0
          || Number(item.counted_return_qty || 0) < 0
          || Number(item.restocked_in_machines_qty || 0) + Number(item.withdrawn_spoilage_qty || 0)
            > Number(item.van_stock_before_reconcile || 0)
      );
      if (invalid) {
        this.reconcileLoadingError = `El cuadre de ${invalid.product_name} excede el saldo del lote o contiene negativos.`;
        return false;
      }

      if (this.reconcileLoadingHasDiscrepancy && this.realLength(this.reconcileLoadingForm.notes) < 20) {
        this.reconcileLoadingError = 'El descuadre exige una justificación de al menos 20 caracteres reales (Art. V.1).';
        return false;
      }

      return true;
    },

    async submitLoadingReconciliation() {
      const order = this.activeLoadingOrderData;
      if (!order?.id || !this.validateLoadingReconciliation()) return;

      this.isReconcilingLoading = true;
      this.loadingActionError = '';
      this.loadingActionMessage = '';
      try {
        const detail = this.normalizeApiData(await api.restocker.reconcileLoadingOrder(order.id, {
          reconciliation_notes: this.reconcileLoadingForm.notes,
          items: this.reconcileLoadingForm.items.map((item) => ({
            product_id: Number(item.product_id),
            restocked_in_machines_qty: Number(item.restocked_in_machines_qty || 0),
            withdrawn_spoilage_qty: Number(item.withdrawn_spoilage_qty || 0),
            counted_return_qty: Number(item.counted_return_qty || 0)
          }))
        }));

        if (detail?.order) {
          this.lastReconciledOrder = detail;
          this.activeLoadingOrder = null;
        } else {
          await this.loadMyRoute();
        }
        this.showReconcileLoadingModal = false;
        this.loadingActionMessage = `Cierre liquidado: ${order.order_code}. El stock de tu furgoneta queda cuadrado.`;
        await this.refreshVanStock();
      } catch (error) {
        this.reconcileLoadingError = error?.message || 'No se pudo liquidar el cierre de la orden.';
      } finally {
        this.isReconcilingLoading = false;
      }
    },

    // ─────────────────────────────────────────────────────────────────────
    // Recaudación, cambio y conserjería (Módulo 35, T-CCV-11)
    // ─────────────────────────────────────────────────────────────────────

    machinePendingRefunds(machine) {
      return Array.isArray(machine?.pending_reception_refunds)
        ? machine.pending_reception_refunds
        : [];
    },

    machinePendingRefundsCount(machine) {
      const explicit = Number(machine?.pending_reception_refunds_count);
      if (Number.isFinite(explicit) && explicit >= 0) return explicit;
      return this.machinePendingRefunds(machine).length;
    },

    sanitizeCashCollection(row) {
      if (!row || typeof row !== 'object') return null;
      const clean = {};
      for (const key of CASH_COLLECTION_PUBLIC_FIELDS) {
        if (Object.prototype.hasOwnProperty.call(row, key)) clean[key] = row[key];
      }
      if (!clean.id && !clean.collection_code) return null;
      return clean;
    },

    sanitizeCashReceipt(row) {
      const clean = {};
      for (const key of CASH_RECEIPT_FIELDS) {
        clean[key] = row && Object.prototype.hasOwnProperty.call(row, key) ? row[key] : null;
      }
      return clean;
    },

    openCashCollection(machine, context = 'INDEPENDENT') {
      const target = machine || this.visitMachine;
      if (!target?.id) return;
      if (!this.machineCanRestock(target)) {
        this.loadingActionError = this.machineRestockBlockReason(target);
        return;
      }

      this.cashContext = context === 'VISIT' ? 'VISIT' : 'INDEPENDENT';
      this.cashMachine = target;
      this.cashError = '';
      this.cashSuccess = '';

      if (this.cashContext === 'VISIT' && String(this.visitCashForm?.bag_seal_code || '').trim() !== '') {
        this.cashForm = { ...this.visitCashForm };
      } else {
        this.cashForm = blankCashForm();
        this.cashForm.client_mutation_id = createClientMutationId();
        this.cashForm.collected_at = new Date().toISOString();
      }
      if (!this.cashForm.client_mutation_id) this.cashForm.client_mutation_id = createClientMutationId();
      if (!this.cashForm.collected_at) this.cashForm.collected_at = new Date().toISOString();
      this.showCashModal = true;
    },

    onVisitCashToggle() {
      if (!this.visitCashEnabled) {
        this.visitCashForm = blankCashForm();
        if (this.cashContextIsVisit) this.showCashModal = false;
        return;
      }
      this.openCashCollection(this.visitMachine, 'VISIT');
    },

    selectCashRefund(refund) {
      const id = Number(refund?.id || 0);
      if (id < 1) return;
      if (Number(this.cashForm.linked_refund_request_id || 0) === id) {
        this.cashForm.linked_refund_request_id = null;
        this.cashForm.reception_cash_delivered_eur = 0;
        this.cashForm.receptionist_name = '';
        return;
      }
      this.cashForm.linked_refund_request_id = id;
      this.cashForm.reception_cash_delivered_eur = Number(refund.claimed_amount || 0);
    },

    buildCashCollectionPayload(form) {
      const reset = form?.is_counter_reset === true;
      const reception = Number(form?.reception_cash_delivered_eur || 0);
      const refundId = Number(form?.linked_refund_request_id || 0) || null;
      return {
        client_mutation_id: form?.client_mutation_id || createClientMutationId(),
        bag_seal_code: String(form?.bag_seal_code || '').trim().toUpperCase(),
        sales_counter_reading: Number(form?.sales_counter_reading),
        change_tubes_loaded_eur: Number(form?.change_tubes_loaded_eur || 0),
        is_counter_reset: reset,
        counter_reset_notes: reset ? String(form?.counter_reset_notes || '').trim() : null,
        reception_cash_delivered_eur: reception,
        receptionist_name: reception > 0 ? String(form?.receptionist_name || '').trim() : null,
        linked_refund_request_id: refundId,
        route_notes: String(form?.route_notes || '').trim() || null,
        collected_at: form?.collected_at || new Date().toISOString()
      };
    },

    validateCashForm(form, machine, forVisit = false) {
      if (forVisit && this.visitIsThermalAbort) {
        return { ok: false, error: 'El aborto térmico no admite recaudación ni carga de cambio (Art. II.2).', payload: null };
      }
      if (!machine?.id) {
        return { ok: false, error: 'No hay una máquina seleccionada para la recaudación.', payload: null };
      }

      const seal = String(form?.bag_seal_code || '').trim().toUpperCase();
      if (!/^[A-Z0-9_-]{6,40}$/.test(seal) || !/[0-9]{3,}/.test(seal)) {
        return {
          ok: false,
          error: 'El precinto debe tener 6–40 caracteres A–Z, 0–9, guion o guion bajo y al menos 3 dígitos (C1).',
          payload: null
        };
      }

      const reset = form?.is_counter_reset === true;
      const notes = String(form?.counter_reset_notes || '').trim();
      if (reset) {
        const blocks = notes.match(/[A-Za-z0-9ÁÉÍÓÚÜÑáéíóúüñ]+/g) || [];
        if (notes.length < 20 || blocks.length < 3) {
          return {
            ok: false,
            error: 'El reseteo del contador exige una nota de al menos 20 caracteres reales y 3 bloques alfanuméricos (Art. V.1).',
            payload: null
          };
        }
      }

      if (form?.sales_counter_reading === '' || form?.sales_counter_reading === null || form?.sales_counter_reading === undefined) {
        return { ok: false, error: 'Registra la lectura actual del contador de ventas.', payload: null };
      }
      const sales = Number(form.sales_counter_reading);
      if (!Number.isFinite(sales) || sales < 0 || sales > 9999999.99) {
        return { ok: false, error: 'La lectura del contador debe estar entre 0,00 € y 9.999.999,99 € (C3).', payload: null };
      }

      const change = Number(form?.change_tubes_loaded_eur || 0);
      if (!Number.isFinite(change) || change < 0 || change > 500) {
        return { ok: false, error: 'La carga de tubos de cambio debe estar entre 0,00 € y 500,00 € (C3).', payload: null };
      }

      const delivery = Number(form?.reception_cash_delivered_eur || 0);
      if (!Number.isFinite(delivery) || delivery < 0 || delivery > 10) {
        return { ok: false, error: 'La entrega en conserjería no puede superar 10,00 € (D3, QA 2).', payload: null };
      }

      const refundId = Number(form?.linked_refund_request_id || 0) || null;
      if (refundId !== null) {
        const refund = this.machinePendingRefunds(machine).find(
          (row) => Number(row.id) === refundId
        );
        if (!refund) {
          return { ok: false, error: 'El reintegro seleccionado ya no está pendiente en esta máquina.', payload: null };
        }
        if (Math.abs(Number(refund.claimed_amount || 0) - delivery) > 0.001) {
          return { ok: false, error: 'La entrega debe coincidir exactamente con el importe reclamado del reintegro.', payload: null };
        }
      }

      if (delivery > 0) {
        if (machine.has_physical_reception !== true) {
          return { ok: false, error: 'Esta sede no tiene conserjería física para entregar efectivo (D3).', payload: null };
        }
        const receptionist = String(form?.receptionist_name || '').trim();
        if (receptionist.length < 3 || receptionist.length > 120) {
          return { ok: false, error: 'Indica el nombre del conserje (3–120 caracteres) que recibe el efectivo (D3).', payload: null };
        }
      }

      return { ok: true, error: '', payload: this.buildCashCollectionPayload({ ...form, bag_seal_code: seal }) };
    },

    async saveCashFromModal() {
      if (this.cashSubmitting) return;
      const result = this.validateCashForm(this.cashForm, this.cashModalMachine, this.cashContextIsVisit);
      if (!result.ok) {
        this.cashError = result.error;
        return;
      }

      this.cashError = '';
      if (this.cashContextIsVisit) {
        this.visitCashForm = { ...this.cashForm, bag_seal_code: result.payload.bag_seal_code };
        this.visitCashEnabled = true;
        this.cashSuccess = 'Recaudación preparada para registrarse de forma atómica con la visita.';
        this.loadingActionMessage = this.cashSuccess;
        this.showCashModal = false;
        return;
      }

      await this.submitIndependentCashCollection(result.payload);
    },

    cancelCashModal() {
      if (this.cashContextIsVisit && String(this.visitCashForm?.bag_seal_code || '').trim() === '') {
        this.visitCashEnabled = false;
      }
      this.showCashModal = false;
      this.cashError = '';
    },

    async submitIndependentCashCollection(payload) {
      if (!this.cashModalMachine?.id) return;
      this.cashSubmitting = true;
      this.cashError = '';
      this.cashSuccess = '';
      try {
        if (!this.navigatorOnline) {
          await this.queueOfflineCashCollection(payload);
          return;
        }

        const response = await api.restocker.createCashCollection(this.cashModalMachine.id, payload);
        const collection = this.normalizeApiData(response);
        await this.applyCashCollectionResult(collection);
        this.cashSuccess = `Recaudación ${collection.collection_code || ''} registrada en bolsa precintada.`;
        this.loadingActionMessage = this.cashSuccess;
        this.showCashModal = false;
      } catch (error) {
        if (this.isNetworkFailure(error)) {
          await this.queueOfflineCashCollection(payload);
        } else {
          this.cashError = error?.message || 'No se pudo registrar la recaudación en ruta.';
        }
      } finally {
        this.cashSubmitting = false;
      }
    },

    async queueOfflineCashCollection(payload) {
      const machine = this.cashModalMachine;
      const restockerId = this.cashRestockerId;
      if (!machine?.id || !restockerId) {
        this.cashError = 'No se pudo identificar la máquina o el reponedor para guardar la recaudación offline.';
        return;
      }

      try {
        const sequence = Date.now() * 1000 + (Number(this.cashQueueSequence || 0) % 1000);
        this.cashQueueSequence = (Number(this.cashQueueSequence || 0) + 1) % 1000;
        await this.ensureCashQueue().enqueue({
          client_mutation_id: payload.client_mutation_id,
          restocker_id: restockerId,
          machine_id: machine.id,
          machine_code: machine.code || null,
          payload,
          created_at: payload.collected_at,
          sequence_order: sequence
        });
        this.applyOptimisticCash(machine, payload);
        await this.loadPendingCashQueue();
        this.showCashModal = false;
        this.cashSuccess = 'Recaudación guardada en la cola offline. Se sincronizará al recuperar conexión.';
        this.loadingActionMessage = this.cashSuccess;
      } catch (error) {
        this.cashError = error?.message || 'No se pudo guardar la recaudación en la cola offline.';
      }
    },

    applyOptimisticCash(machine, payload) {
      machine.has_prior_cash_collection = true;
      const refundId = Number(payload?.linked_refund_request_id || 0);
      if (refundId > 0) {
        machine.pending_reception_refunds = this.machinePendingRefunds(machine).filter(
          (row) => Number(row.id) !== refundId
        );
        machine.pending_reception_refunds_count = machine.pending_reception_refunds.length;
        this.pendingReceptionRefunds = this.pendingReceptionRefunds.filter(
          (row) => Number(row.id) !== refundId
        );
      }

      const summary = this.cashRouteSummary;
      this.cashRouteSummary = {
        ...summary,
        total_collections_count: Number(summary.total_collections_count || 0) + 1,
        total_change_tubes_loaded_eur: Math.round((Number(summary.total_change_tubes_loaded_eur || 0) + Number(payload.change_tubes_loaded_eur || 0)) * 100) / 100,
        total_reception_cash_delivered_eur: Math.round((Number(summary.total_reception_cash_delivered_eur || 0) + Number(payload.reception_cash_delivered_eur || 0)) * 100) / 100,
        pending_vault_bags_count: Number(summary.pending_vault_bags_count || 0) + 1,
        last_collected_at: payload.collected_at || new Date().toISOString()
      };

      const local = {
        id: `offline-${payload.client_mutation_id}`,
        collection_code: 'PENDIENTE',
        machine_id: machine.id,
        machine_code: machine.code || null,
        bag_seal_code: payload.bag_seal_code,
        sales_counter_reading: payload.sales_counter_reading,
        change_tubes_loaded_eur: payload.change_tubes_loaded_eur,
        reception_cash_delivered_eur: payload.reception_cash_delivered_eur,
        receptionist_name: payload.receptionist_name,
        linked_refund_request_id: payload.linked_refund_request_id,
        has_reception_receipt: Number(payload.reception_cash_delivered_eur || 0) > 0,
        status: 'PENDING_SYNC',
        status_label: 'Pendiente de sincronizar',
        collected_at: payload.collected_at,
        is_offline_pending: true
      };
      this.recentCashCollections = [local, ...this.recentCashCollections].slice(0, 20);
    },

    async applyCashCollectionResult(collection, options = {}) {
      const clean = this.sanitizeCashCollection(collection);
      if (!clean) return;

      this.recentCashCollections = [
        clean,
        ...this.recentCashCollections.filter(
          (row) => String(row.id) !== String(clean.id)
            && String(row.collection_code || '') !== String(clean.collection_code || '')
        )
      ].slice(0, 20);

      const machineId = Number(clean.machine_id || 0);
      const machine = this.machines.find((row) => Number(row.id) === machineId);
      if (machine) {
        machine.has_prior_cash_collection = true;
        const refundId = Number(clean.linked_refund_request_id || 0);
        if (refundId > 0) {
          machine.pending_reception_refunds = this.machinePendingRefunds(machine).filter(
            (row) => Number(row.id) !== refundId
          );
          machine.pending_reception_refunds_count = machine.pending_reception_refunds.length;
        }
      }
      const refundId = Number(clean.linked_refund_request_id || 0);
      if (refundId > 0) {
        this.pendingReceptionRefunds = this.pendingReceptionRefunds.filter(
          (row) => Number(row.id) !== refundId
        );
      }

      const summary = this.cashRouteSummary;
      this.cashRouteSummary = {
        ...summary,
        total_collections_count: Number(summary.total_collections_count || 0) + 1,
        total_change_tubes_loaded_eur: Math.round((Number(summary.total_change_tubes_loaded_eur || 0) + Number(clean.change_tubes_loaded_eur || 0)) * 100) / 100,
        total_reception_cash_delivered_eur: Math.round((Number(summary.total_reception_cash_delivered_eur || 0) + Number(clean.reception_cash_delivered_eur || 0)) * 100) / 100,
        pending_vault_bags_count: Number(summary.pending_vault_bags_count || 0) + 1,
        last_collected_at: clean.collected_at || new Date().toISOString()
      };

      const hasReceipt = clean.has_reception_receipt === true
        || Number(clean.reception_cash_delivered_eur || 0) > 0;
      if (options.openReceipt !== false && hasReceipt && clean.id) {
        await this.openCashReceipt(clean);
      }
    },

    async openCashReceipt(collection) {
      const id = Number(collection?.id || 0);
      if (id < 1) return;
      this.showCashReceiptModal = true;
      this.cashReceipt = {};
      this.cashReceiptLoading = true;
      this.cashReceiptError = '';
      try {
        const response = await api.restocker.getCashCollectionReceptionReceipt(id);
        this.cashReceipt = this.sanitizeCashReceipt(this.normalizeApiData(response));
      } catch (error) {
        this.cashReceiptError = error?.message || 'No se pudo recuperar el recibo de conserjería.';
      } finally {
        this.cashReceiptLoading = false;
      }
    },

    closeCashReceipt() {
      this.showCashReceiptModal = false;
      this.cashReceipt = {};
      this.cashReceiptError = '';
    },

    printCashReceipt() {
      if (typeof window !== 'undefined' && typeof window.print === 'function') {
        window.print();
      }
    },

    ensureCashQueue() {
      if (!this.cashQueue) {
        this.cashQueue = new CashCollectionOfflineQueue();
      }
      return this.cashQueue;
    },

    async loadPendingCashQueue() {
      const restockerId = this.cashRestockerId;
      if (!restockerId) {
        this.pendingCashMutations = [];
        return;
      }

      try {
        this.pendingCashMutations = await this.ensureCashQueue().list(restockerId);
      } catch (error) {
        this.pendingCashMutations = [];
        this.cashSyncError = error?.message || 'No se pudo leer la cola offline de recaudaciones.';
      }
    },

    async syncPendingCashCollections() {
      const restockerId = this.cashRestockerId;
      if (!restockerId) {
        this.cashSyncError = 'Inicia sesión como reponedor para sincronizar recaudaciones.';
        return;
      }
      if (!this.navigatorOnline) {
        this.cashSyncError = 'Sin conexión. Las recaudaciones seguirán guardadas en la cola offline.';
        return;
      }
      if (this.isSyncingCash) return;

      this.isSyncingCash = true;
      this.cashSyncError = '';
      this.cashSyncMessage = '';
      let synced = 0;
      let conflicts = 0;
      try {
        const queue = this.ensureCashQueue();
        const pending = await queue.list(restockerId, [CASH_COLLECTION_PENDING]);
        for (const item of pending) {
          try {
            const response = await api.restocker.createCashCollection(item.machine_id, item.payload);
            await this.applyCashCollectionResult(this.normalizeApiData(response), { openReceipt: false });
            await queue.remove(item.client_mutation_id);
            synced += 1;
          } catch (error) {
            if (this.isRetryableSyncFailure(error)) {
              this.cashSyncError = this.syncRetryMessage(error, 'recaudaciones');
              break;
            }
            await queue.markConflict(
              item.client_mutation_id,
              error?.message || 'La recaudación offline fue rechazada por el servidor.'
            );
            conflicts += 1;
          }
        }

        await this.loadPendingCashQueue();
        if (synced > 0 || conflicts > 0) {
          this.cashSyncMessage = `${synced} recaudación(es) sincronizada(s).${conflicts > 0 ? ` ${conflicts} conflicto(s) pendientes de archivar.` : ''}`;
        } else if (!this.cashSyncError) {
          this.cashSyncMessage = 'No hay recaudaciones pendientes de sincronizar.';
        }
      } catch (error) {
        this.cashSyncError = error?.message || 'No se pudo sincronizar la cola offline de recaudaciones.';
      } finally {
        this.isSyncingCash = false;
      }
    },

    async archiveCashConflict(item) {
      if (!item?.client_mutation_id || this.isSyncingCash) return;
      this.isSyncingCash = true;
      this.cashSyncError = '';
      this.cashSyncMessage = '';
      try {
        await api.restocker.dumpCashCollectionConflict({
          machine_id: item.machine_id,
          client_mutation_id: item.client_mutation_id,
          bag_seal_code: item.payload?.bag_seal_code || null,
          reason: item.conflict_reason || 'La recaudación offline ya no puede aplicarse.',
          offline_created_at: item.created_at
        });
        await this.ensureCashQueue().remove(item.client_mutation_id);
        await this.loadPendingCashQueue();
        this.cashSyncMessage = 'Conflicto de recaudación archivado en auditoría. La cola local queda limpia.';
      } catch (error) {
        this.cashSyncError = error?.message || 'No se pudo archivar el conflicto de recaudación.';
      } finally {
        this.isSyncingCash = false;
      }
    },

    cashStatusLabel(collection) {
      if (collection?.status_label) return collection.status_label;
      const labels = {
        SEALED_IN_ROUTE: 'Precintada en ruta',
        DELIVERED_TO_VAULT: 'Entregada en cámara',
        PROCESSED_IN_VAULT: 'Arqueada en caja',
        PENDING_SYNC: 'Pendiente de sincronizar'
      };
      return labels[collection?.status] || collection?.status || 'Sin estado';
    },

    cashStatusStyle(status) {
      if (status === 'PROCESSED_IN_VAULT' || status === 'RECONCILED_OK') {
        return { backgroundColor: 'var(--color-success-bg)', color: 'var(--color-success-text)' };
      }
      if (status === 'DELIVERED_TO_VAULT') {
        return { backgroundColor: 'var(--color-info-bg)', color: 'var(--color-info-text)' };
      }
      if (status === 'PENDING_SYNC') {
        return { backgroundColor: 'var(--color-surface-1)', color: 'var(--color-ink-muted)' };
      }
      return { backgroundColor: 'var(--color-warning-bg)', color: 'var(--color-warning-text)' };
    },

    handleLogout() {
      store.clearSession();
      this.restocker = null;
      this.zones = { primary_zone: null, backup_zones: [] };
      this.routeStops = [];
      this.machines = [];
      this.activeLoadingOrder = null;
      this.lastReconciledOrder = null;
      this.vanProductStocks = [];
      this.vanStockKpis = { total_lots: 0, total_units: 0, usable_units: 0, expired_lots: 0, expiring_soon_lots: 0 };
      this.hasExpiredVanLots = false;
      this.loadingActionError = '';
      this.loadingActionMessage = '';
      this.showReconcileLoadingModal = false;
      this.showPlanogramModal = false;
      this.selectedMachine = null;
      this.planogram = null;
      this.showVisitModal = false;
      this.visitMachine = null;
      this.visitPlanogram = null;
      this.recentVisits = [];
      this.pendingVisitMutations = [];
      this.visitSyncMessage = '';
      this.visitSyncError = '';
      this.showCashModal = false;
      this.cashMachine = null;
      this.cashForm = blankCashForm();
      this.visitCashEnabled = false;
      this.visitCashForm = blankCashForm();
      this.pendingReceptionRefunds = [];
      this.recentCashCollections = [];
      this.cashRouteSummary = blankCashRouteSummary();
      this.pendingCashMutations = [];
      this.cashSyncMessage = '';
      this.cashSyncError = '';
      this.showCashReceiptModal = false;
      this.cashReceipt = {};
      this.clearVisitPhoto();
      this.shiftStatus = 'OFF_SHIFT';
      this.todaySummary = null;
    }
  },
  template: `
    <div
      data-testid="restocker-route-view"
      style="max-width: 480px; margin: 0 auto; padding: 0 12px 24px; font-family: var(--font-body); color: var(--color-slate);"
    >
      <!-- Acceso exclusivo del reponedor (RF-04.1) -->
      <section
        v-if="!isAuthenticated"
        data-testid="restocker-login"
        style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 20px; margin-top: 16px;"
      >
        <h1 style="font-family: var(--font-display); font-size: 22px; margin: 0 0 6px 0;">📦 Ruta de Reposición</h1>
        <p style="font-size: 13px; color: var(--color-ink-muted); margin: 0 0 16px 0;">
          Acceso exclusivo para Cargadores / Reponedores de Ruta.
        </p>

        <label style="display: block; font-size: 12px; margin-bottom: 4px;">Correo electrónico</label>
        <input
          v-model="loginEmail"
          type="email"
          autocomplete="username"
          data-testid="restocker-login-email"
          style="width: 100%; box-sizing: border-box; height: 36px; padding: 0 10px; margin-bottom: 10px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);"
        />
        <label style="display: block; font-size: 12px; margin-bottom: 4px;">Contraseña</label>
        <input
          v-model="loginPassword"
          type="password"
          autocomplete="current-password"
          data-testid="restocker-login-password"
          style="width: 100%; box-sizing: border-box; height: 36px; padding: 0 10px; margin-bottom: 12px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);"
        />

        <div
          v-if="loginError"
          data-testid="restocker-login-error"
          style="font-size: 12px; color: var(--color-urgency-critical); background: var(--color-urgency-critical-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-bottom: 10px;"
        >
          {{ loginError }}
        </div>

        <button
          type="button"
          class="vg-btn vg-btn-lg vg-btn-primary w-100"
          data-testid="btn-restocker-login"
          :disabled="isLoggingIn"
          @click="handleLogin"
        >
          {{ isLoggingIn ? 'Entrando…' : 'Entrar a mi ruta' }}
        </button>
      </section>

      <template v-else>
        <!-- Cabecera de operario y zonas asignadas (RF-04.1) -->
        <header
          data-testid="restocker-header"
          style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 14px; margin-top: 16px;"
        >
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">
            <div>
              <h1 style="font-family: var(--font-display); font-size: 20px; margin: 0 0 2px 0;">
                {{ restocker?.name || currentUser?.name || 'Reponedor' }}
              </h1>
              <p style="margin: 0; font-size: 12px; color: var(--color-ink-muted);">
                Código de operario:
                <strong data-testid="restocker-operator-code" style="font-family: var(--font-display); color: var(--color-slate);">{{ operatorCode }}</strong>
              </p>
            </div>
            <button
              type="button"
              class="vg-btn vg-btn-ghost vg-btn-sm"
              @click="handleLogout"
            >
              Salir
            </button>
          </div>

          <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px;">
            <span
              v-if="primaryZone"
              data-testid="zone-badge-primary"
              style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; font-size: 11px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); background-color: var(--color-primary-subtle);"
            >
              <span style="width: 8px; height: 8px; border-radius: 50%; display: inline-block;" :style="{ backgroundColor: primaryZone.color_hex || 'var(--color-primary)' }"></span>
              <strong>Titular</strong> · {{ primaryZone.name }}
            </span>
            <span
              v-for="zone in backupZones"
              :key="zone.id"
              data-testid="zone-badge-backup"
              style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; font-size: 11px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); background-color: var(--color-surface-card);"
            >
              <span style="width: 8px; height: 8px; border-radius: 50%; display: inline-block;" :style="{ backgroundColor: zone.color_hex || 'var(--color-ink-muted)' }"></span>
              <strong>Suplente</strong> · {{ zone.name }}
            </span>
            <span
              v-if="!hasZones"
              style="font-size: 11px; color: var(--color-ink-muted);"
            >
              Sin zonas asignadas todavía.
            </span>
          </div>
        </header>

        <!-- Barra de control de jornada (RF-04.2, D1) -->
        <section
          data-testid="restocker-shift-bar"
          style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 12px 14px; margin-top: 12px;"
        >
          <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
            <div style="display: flex; align-items: center; gap: 8px;">
              <span
                style="width: 10px; height: 10px; border-radius: 50%; display: inline-block;"
                :style="{
                  backgroundColor: shiftStatus === 'ON_SHIFT'
                    ? 'var(--color-success)'
                    : (shiftStatus === 'MEAL_BREAK' ? 'var(--color-warning)' : 'var(--color-ink-muted)')
                }"
              ></span>
              <span style="font-size: 13px; font-weight: 600;">{{ shiftStatusLabel }}</span>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
              <button
                v-if="shiftStatus === 'OFF_SHIFT'"
                type="button"
                class="vg-btn vg-btn-sm vg-btn-primary"
                data-testid="btn-start-shift"
                :disabled="isShiftLoading"
                @click="startShift"
              >
                ▶ Iniciar Turno
              </button>

              <template v-else-if="shiftStatus === 'ON_SHIFT'">
                <button
                  type="button"
                  class="vg-btn vg-btn-sm vg-btn-secondary"
                  data-testid="btn-pause-meal"
                  :disabled="isShiftLoading"
                  @click="pauseMealShift"
                >
                  🥪 Pausa para Comer
                </button>
                <button
                  type="button"
                  class="vg-btn vg-btn-sm vg-btn-danger"
                  data-testid="btn-close-shift"
                  :disabled="isShiftLoading"
                  @click="closeShift"
                >
                  ⏹ Cerrar Turno
                </button>
              </template>

              <template v-else-if="shiftStatus === 'MEAL_BREAK'">
                <button
                  type="button"
                  class="vg-btn vg-btn-sm vg-btn-primary"
                  data-testid="btn-resume-shift"
                  :disabled="isShiftLoading"
                  @click="resumeMealShift"
                >
                  ▶ Reanudar Turno
                </button>
                <button
                  type="button"
                  class="vg-btn vg-btn-sm vg-btn-danger"
                  data-testid="btn-close-shift"
                  :disabled="isShiftLoading"
                  @click="closeShift"
                >
                  ⏹ Cerrar Turno
                </button>
              </template>
            </div>
          </div>

          <!-- Contador diario y «Mis Horas» (RF-04.3) -->
          <div
            data-testid="restocker-today-summary"
            style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding-top: 10px; margin-top: 10px; border-top: 1px solid var(--color-hairline);"
          >
            <span style="font-size: 12px; color: var(--color-ink-muted);">
              Hoy:
              <strong style="font-family: var(--font-display); font-size: 14px; font-weight: 500; color: var(--color-slate);">{{ todayEffectiveFormatted }}</strong>
              efectivas ·
              <strong style="font-family: var(--font-display); font-size: 14px; font-weight: 500; color: var(--color-slate);">{{ todayMealFormatted }}</strong>
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
        </section>

        <div
          v-if="shiftError"
          data-testid="restocker-shift-error"
          style="font-size: 12px; color: var(--color-urgency-critical); background: var(--color-urgency-critical-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 10px;"
        >
          {{ shiftError }}
        </div>
        <div
          v-if="feedbackMessage"
          data-testid="restocker-shift-feedback"
          style="font-size: 12px; color: var(--color-success); background: var(--color-success-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 10px;"
        >
          {{ feedbackMessage }}
        </div>

        <div
          v-if="loadingActionMessage"
          data-testid="restocker-loading-feedback"
          style="font-size: 12px; color: var(--color-success-text); background: var(--color-success-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 10px;"
        >
          ✅ {{ loadingActionMessage }}
        </div>
        <div
          v-if="loadingActionError"
          data-testid="restocker-loading-error"
          style="font-size: 12px; color: var(--color-error-text); background: var(--color-error-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 10px;"
        >
          ⚠️ {{ loadingActionError }}
        </div>

        <!-- Orden de carga activa: recepción 1-Tap y liquidación (Módulo 33, D3, D4, C4) -->
        <section
          v-if="activeLoadingOrderData"
          data-testid="restocker-active-loading-order"
          style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 14px; margin-top: 12px;"
        >
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
            <div>
              <h2 style="font-family: var(--font-display); font-size: 16px; font-weight: 500; margin: 0 0 4px 0;">🚚 Orden de carga</h2>
              <p style="font-size: 12px; color: var(--color-ink-muted); margin: 0;">
                <strong style="color: var(--color-slate);">{{ activeLoadingOrderData.order_code }}</strong>
                · {{ activeLoadingOrderData.demand_mode_label }} · {{ activeLoadingOrderData.safety_buffer_pct }} % colchón
              </p>
            </div>
            <span
              style="font-size: 10px; font-weight: 600; padding: 3px 7px; border-radius: var(--radius-interactive); white-space: nowrap;"
              :style="activeLoadingOrderIsDispatched ? { backgroundColor: 'var(--color-info-bg)', color: 'var(--color-info-text)' } : { backgroundColor: 'var(--color-success-bg)', color: 'var(--color-success-text)' }"
            >{{ activeLoadingOrderData.status_label }}</span>
          </div>

          <div
            v-if="activeLoadingOrderIsDispatched"
            data-testid="restocker-loading-confirm-banner"
            style="margin-top: 12px; padding: 10px; border: 1px solid var(--color-hairline); border-radius: var(--radius-card); background-color: var(--color-primary-subtle);"
          >
            <strong style="display: block; font-size: 13px; color: var(--color-slate);">📦 Mercancía despachada desde almacén</strong>
            <p style="font-size: 12px; color: var(--color-ink-muted); margin: 4px 0 0 0;">
              Salida de cámara:
              <strong style="color: var(--color-slate);">{{ activeLoadingOrderData.cold_chamber_exit_temp_celsius ?? '—' }} °C</strong>
              · Revisa los lotes y confirma en un toque.
            </p>
            <button
              type="button"
              class="vg-btn vg-btn-lg vg-btn-primary w-100 mt-2"
              data-testid="btn-confirm-loading-order"
              :disabled="isConfirmingLoading"
              @click="confirmActiveLoadingOrder"
            >
              {{ isConfirmingLoading ? 'Confirmando recepción…' : '✅ Confirmar Recepción en Furgoneta (1 Toque)' }}
            </button>
          </div>

          <div
            v-else-if="activeLoadingOrderIsConfirmed"
            data-testid="restocker-loading-reconcile-card"
            style="margin-top: 12px; padding: 10px; border: 1px solid var(--color-hairline); border-radius: var(--radius-card); background-color: var(--color-success-bg);"
          >
            <strong style="display: block; font-size: 13px; color: var(--color-success-text);">✅ Orden recibida en tu furgoneta</strong>
            <p style="font-size: 12px; color: var(--color-ink-muted); margin: 4px 0 0 0;">
              Al terminar el recorrido, declara abastecidas, mermas y retorno físico.
            </p>
            <button
              type="button"
              class="vg-btn vg-btn-lg vg-btn-primary w-100 mt-2"
              data-testid="btn-open-reconcile-loading"
              @click="openReconcileLoadingModal"
            >
              📋 Liquidar Cierre de Furgoneta
            </button>
          </div>

          <p v-else style="font-size: 12px; color: var(--color-ink-muted); margin: 10px 0 0 0;">
            Orden en preparación en almacén. Todavía no requiere recepción.
          </p>

          <ul style="margin: 12px 0 0 0; padding: 0; list-style: none; display: grid; gap: 8px;">
            <li
              v-for="item in activeLoadingOrderVisibleItems"
              :key="item.product_id"
              data-testid="restocker-loading-item"
              style="border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); padding: 8px 10px;"
            >
              <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                <div style="min-width: 0;">
                  <strong style="display: block; font-size: 12px; font-family: var(--font-display); font-weight: 500;">{{ item.product_sku }}</strong>
                  <span style="display: block; font-size: 11px; color: var(--color-ink-muted);">{{ item.product_name }}</span>
                </div>
                <span style="font-size: 11px; font-weight: 600; white-space: nowrap;">
                  {{ item.dispatched_qty ?? item.requested_qty }} uds.
                </span>
              </div>
              <div style="display: flex; flex-wrap: wrap; gap: 6px; font-size: 10px; color: var(--color-ink-muted); margin-top: 5px;">
                <span v-if="item.requires_cold_chain" style="padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-info-bg); color: var(--color-info-text);">❄️ Frío</span>
                <span>Lote {{ item.batch_code || '—' }}</span>
                <span>Caduca {{ item.expiration_date || '—' }}</span>
                <span>{{ item.reason_label }}</span>
              </div>
            </li>
          </ul>
        </section>

        <!-- Stock de la furgoneta por lote y semáforo FEFO (Módulo 33, D4, Art. V.4) -->
        <section
          data-testid="restocker-van-stock"
          style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 14px; margin-top: 12px;"
        >
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
            <div>
              <h2 style="font-family: var(--font-display); font-size: 16px; font-weight: 500; margin: 0 0 4px 0;">🚐 Mi Stock en Furgoneta (Por Lote / FEFO)</h2>
              <p style="font-size: 12px; color: var(--color-ink-muted); margin: 0;">Rotación por caducidad más próxima primero.</p>
            </div>
            <span style="font-size: 10px; padding: 3px 7px; border-radius: var(--radius-interactive); background-color: var(--color-surface-1); color: var(--color-slate); white-space: nowrap;">
              {{ vanStockKpis.total_units || 0 }} uds.
            </span>
          </div>

          <div
            v-if="hasExpiredVanLots"
            data-testid="restocker-van-stock-expired-alert"
            style="margin-top: 10px; padding: 8px 10px; border-radius: var(--radius-interactive); background-color: var(--color-error-bg); color: var(--color-error-text); font-size: 12px;"
          >
            🔴 Hay lotes caducados en la furgoneta. Retíralos antes de continuar la ruta (Art. II).
          </div>

          <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px;">
            <span style="font-size: 10px; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-surface-1); color: var(--color-slate);">{{ vanStockKpis.total_lots || 0 }} lotes</span>
            <span style="font-size: 10px; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-success-bg); color: var(--color-success-text);">{{ vanStockKpis.usable_units || 0 }} uds. útiles</span>
            <span style="font-size: 10px; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-warning-bg); color: var(--color-warning-text);">{{ vanStockKpis.expiring_soon_lots || 0 }} próximos ≤ 48 h</span>
          </div>

          <p v-if="vanStockLots.length === 0" style="font-size: 13px; color: var(--color-ink-muted); margin: 12px 0 0 0;">
            Todavía no hay producto cargado en tu furgoneta.
          </p>
          <ul v-else style="margin: 12px 0 0 0; padding: 0; list-style: none; display: grid; gap: 8px;">
            <li
              v-for="lot in vanStockLots"
              :key="lot.id"
              data-testid="restocker-van-stock-lot"
              :data-batch-code="lot.batch_code"
              style="border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); padding: 8px 10px;"
            >
              <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                <div style="min-width: 0;">
                  <strong style="display: block; font-size: 12px; font-family: var(--font-display); font-weight: 500;">{{ lot.product_sku }}</strong>
                  <span style="display: block; font-size: 11px; color: var(--color-ink-muted);">{{ lot.product_name }}</span>
                </div>
                <strong style="font-size: 13px; white-space: nowrap;">{{ lot.quantity_available }} uds.</strong>
              </div>
              <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: 10px; color: var(--color-ink-muted); margin-top: 5px;">
                <span
                  data-testid="restocker-van-stock-expiry"
                  :data-expiry-status="lot.expiry_status"
                  :style="expiryStatusStyle(lot.expiry_status)"
                  style="padding: 2px 6px; border-radius: var(--radius-interactive); font-weight: 600;"
                >{{ expiryStatusIcon(lot.expiry_status) }} {{ lot.expiry_status_label || expiryStatusLabel(lot.expiry_status) }}</span>
                <span>Lote {{ lot.batch_code }}</span>
                <span>Caduca {{ lot.expiration_date || '—' }}</span>
                <span v-if="lot.requires_cold_chain">❄️ Frío</span>
              </div>
            </li>
          </ul>
        </section>

        <!-- Comprobante C4: última orden liquidada, solo lectura -->
        <section
          v-if="lastReconciledOrderData"
          data-testid="restocker-last-reconciled-order"
          style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 14px; margin-top: 12px;"
        >
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
            <div>
              <h2 style="font-family: var(--font-display); font-size: 16px; font-weight: 500; margin: 0 0 4px 0;">🧾 Última orden liquidada</h2>
              <p style="font-size: 12px; color: var(--color-ink-muted); margin: 0;">
                {{ lastReconciledOrderData.order_code }} · {{ formatShiftDate(lastReconciledOrderData.reconciled_at) }}
              </p>
            </div>
            <span style="font-size: 10px; font-weight: 600; padding: 3px 7px; border-radius: var(--radius-interactive); background-color: var(--color-success-bg); color: var(--color-success-text); white-space: nowrap;">Solo lectura</span>
          </div>
          <ul style="margin: 12px 0 0 0; padding: 0; list-style: none; display: grid; gap: 8px;">
            <li
              v-for="item in lastReconciledVisibleItems"
              :key="item.product_id"
              data-testid="restocker-last-reconciled-item"
              style="border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); padding: 8px 10px;"
            >
              <div style="display: flex; justify-content: space-between; gap: 8px; font-size: 12px;">
                <span><strong>{{ item.product_sku }}</strong> · {{ item.product_name }}</span>
                <span style="white-space: nowrap;">{{ item.counted_return_qty ?? 0 }} retorno</span>
              </div>
              <div style="display: flex; flex-wrap: wrap; gap: 6px; font-size: 10px; color: var(--color-ink-muted); margin-top: 4px;">
                <span>Lote {{ item.batch_code || '—' }}</span>
                <span>{{ item.restocked_in_machines_qty ?? 0 }} abastecidas</span>
                <span>{{ item.withdrawn_spoilage_qty ?? 0 }} mermas</span>
                <span :style="{ color: Number(item.discrepancy_qty || 0) === 0 ? 'var(--color-success-text)' : 'var(--color-error-text)' }">
                  Descuadre {{ item.discrepancy_qty ?? 0 }}
                </span>
              </div>
            </li>
          </ul>
          <p v-if="lastReconciledOrderData.reconciliation_notes" style="font-size: 11px; color: var(--color-ink-muted); margin: 10px 0 0 0;">
            Nota de cierre: {{ lastReconciledOrderData.reconciliation_notes }}
          </p>
        </section>

        <!-- Estado base de la ruta de abastecimiento (Features 24, 26 y 27) -->
        <section
          data-testid="restocker-route-base"
          style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 14px; margin-top: 12px;"
        >
          <h2 style="font-family: var(--font-display); font-size: 16px; margin: 0 0 6px 0;">Ruta de abastecimiento</h2>
          <div v-if="isLoading" style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--color-ink-muted); margin: 6px 0;">
            <span class="spinner-border spinner-border-sm" role="status" style="width: 14px; height: 14px; border-width: 2px;"></span>
            <span>Sincronizando paradas y zonas de abastecimiento…</span>
          </div>
          <p v-else-if="loadError" data-testid="restocker-route-error" style="font-size: 13px; color: var(--color-urgency-critical); margin: 0;">{{ loadError }}</p>
          <p v-else-if="routeStops.length === 0" style="font-size: 13px; color: var(--color-ink-muted); margin: 0;">
            Sin paradas asignadas por ahora. Las órdenes de carga y las visitas a máquinas
            llegarán con las Features 24, 26 y 27.
          </p>
          <ul v-else style="margin: 8px 0 0 0; padding: 0; list-style: none; display: grid; gap: 8px;">
            <li
              v-for="stop in routeStops"
              :key="stop.id"
              style="border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); padding: 8px 10px; font-size: 13px;"
            >
              {{ stop.label || stop.machine_code || stop.location_name || ('Parada #' + stop.id) }}
            </li>
          </ul>
        </section>

        <!-- Máquinas de las zonas vivas y planograma de solo lectura (T-PLN-13, D2, QA 2, Art. V.4) -->
        <section
          data-testid="restocker-machines"
          style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 14px; margin-top: 12px;"
        >
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
            <div>
              <h2 style="font-family: var(--font-display); font-size: 16px; font-weight: 500; margin: 0 0 4px 0;">🗄️ Máquinas de mis zonas</h2>
              <p style="font-size: 12px; color: var(--color-ink-muted); margin: 0;">
                Consulta de solo lectura. La configuración la define Coordinación.
              </p>
            </div>
            <span
              v-if="machines.length > 0"
              data-testid="restocker-machines-count"
              style="font-size: 11px; padding: 3px 8px; border-radius: var(--radius-interactive); background-color: var(--color-primary-subtle); color: var(--color-primary); white-space: nowrap;"
            >{{ machines.length }} en zona</span>
          </div>

          <p v-if="isLoading" style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--color-ink-muted); margin: 12px 0 0 0;">
            <span class="spinner-border spinner-border-sm" role="status" style="width: 14px; height: 14px; border-width: 2px;"></span>
            <span>Cargando las máquinas de tus zonas…</span>
          </p>
          <p v-else-if="loadError" style="font-size: 13px; color: var(--color-urgency-critical); margin: 12px 0 0 0;">
            {{ loadError }}
          </p>
          <p v-else-if="machines.length === 0" data-testid="restocker-machines-empty" style="font-size: 13px; color: var(--color-ink-muted); margin: 12px 0 0 0;">
            No hay máquinas activas en tus zonas operativas vivas.
          </p>

          <ul v-else style="margin: 12px 0 0 0; padding: 0; list-style: none; display: grid; gap: 10px;">
            <li
              v-for="machine in machines"
              :key="machine.id"
              data-testid="restocker-machine-card"
              :data-machine-id="machine.id"
              style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px 12px;"
            >
              <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                <div style="min-width: 0;">
                  <strong style="display: block; font-family: var(--font-display); font-size: 14px; font-weight: 500;">{{ machine.code }}</strong>
                  <span style="display: block; font-size: 12px; color: var(--color-slate);">{{ machine.model }} · {{ machineTypeLabel(machine.machine_type) }}</span>
                  <span style="display: block; font-size: 11px; color: var(--color-ink-muted); margin-top: 2px;">
                    {{ machine.location_name }}<template v-if="machine.zone_name"> · {{ machine.zone_name }}</template>
                  </span>
                </div>
                <span
                  v-if="machineHasAlert(machine)"
                  data-testid="restocker-machine-alert"
                  style="font-size: 10px; font-weight: 600; padding: 3px 7px; border-radius: var(--radius-interactive); background-color: var(--color-warning-bg); color: var(--color-warning-text); white-space: nowrap;"
                >⚠️ Reponer</span>
                <span
                  v-else
                  style="font-size: 10px; font-weight: 600; padding: 3px 7px; border-radius: var(--radius-interactive); background-color: var(--color-success-bg); color: var(--color-success-text); white-space: nowrap;"
                >Al día</span>
              </div>

              <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px;">
                <span
                  data-testid="restocker-machine-sanitary-status"
                  :data-sanitary-status="machine.sanitary_status || 'UNKNOWN'"
                  :style="sanitaryStatusStyle(machine.sanitary_status)"
                  style="font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: var(--radius-interactive);"
                >Sanidad · {{ sanitaryStatusLabel(machine.sanitary_status) }}</span>
                <span style="font-size: 10px; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-surface-1); color: var(--color-slate);">{{ machine.active_slots_count }} carriles</span>
                <span style="font-size: 10px; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-error-bg); color: var(--color-error-text);">{{ machine.stockout_slots_count }} agotados</span>
                <span style="font-size: 10px; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-warning-bg); color: var(--color-warning-text);">{{ machine.low_stock_slots_count }} bajo umbral</span>
                <span style="font-size: 10px; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-info-bg); color: var(--color-info-text);">{{ Number(machine.fill_rate_percent || 0).toFixed(0) }} % llenado</span>
                <span
                  data-testid="restocker-machine-stock-status"
                  :data-stock-status="machineStockStatus(machine)"
                  :style="stockStatusStyle(machineStockStatus(machine))"
                  style="font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: var(--radius-interactive);"
                >{{ stockStatusLabel(machineStockStatus(machine)) }}</span>
                <span
                  v-if="Number(machine.expired_slots_count || 0) > 0"
                  data-testid="restocker-machine-expired-slots"
                  style="font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-error-bg); color: var(--color-error-text);"
                >🔴 {{ machine.expired_slots_count }} lote(s) caducado(s)</span>
                <span
                  v-if="Number(machine.expiring_soon_slots_count || 0) > 0"
                  data-testid="restocker-machine-expiring-slots"
                  style="font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-warning-bg); color: var(--color-warning-text);"
                >🟡 {{ machine.expiring_soon_slots_count }} caducidad(es) próxima(s)</span>
                <span
                  v-if="machine.pending_visit_sync"
                  data-testid="restocker-machine-pending-visit"
                  style="font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-warning-bg); color: var(--color-warning-text);"
                >📥 Visita pendiente</span>
                <span
                  v-if="machinePendingRefundsCount(machine) > 0"
                  data-testid="restocker-machine-pending-refunds"
                  :data-pending-refunds-count="machinePendingRefundsCount(machine)"
                  style="font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-info-bg); color: var(--color-info-text);"
                >🪙 {{ machinePendingRefundsCount(machine) }} reintegro(s)</span>
              </div>

              <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px;">
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary vg-btn-sm"
                  data-testid="btn-open-planogram"
                  :data-machine-id="machine.id"
                  @click="openPlanogram(machine)"
                >
                  Ver planograma
                </button>
                <button
                  type="button"
                  class="vg-btn vg-btn-primary vg-btn-sm"
                  data-testid="btn-start-visit"
                  :data-machine-id="machine.id"
                  :disabled="!machineCanRestock(machine)"
                  :title="machineCanRestock(machine) ? 'Abrir el asistente de visita in situ' : machineRestockBlockReason(machine)"
                  @click="openVisitAssistant(machine)"
                >
                  📋 Iniciar Visita de Reposición
                </button>
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary vg-btn-sm"
                  data-testid="btn-quick-cash-collection"
                  :data-machine-id="machine.id"
                  :disabled="!machineCanRestock(machine)"
                  :title="machineCanRestock(machine) ? 'Recaudar hucha, cargar cambio y entregar reintegros en conserjería' : machineRestockBlockReason(machine)"
                  @click="openCashCollection(machine)"
                >
                  🪙 Recaudación / Cambio
                </button>
              </div>
              <div
                v-if="siteCoincidenceForLocation(machine.location_id).length > 0"
                data-testid="restocker-site-coincidence"
                style="font-size: 11px; background-color: var(--color-info-bg); color: var(--color-info-text); border-radius: var(--radius-interactive); padding: 6px 8px; margin-top: 8px;"
              >
                🤝 <strong>Coincidencia en Sede:</strong> {{ siteCoincidenceSummary(machine.location_id) }}
              </div>
              <div v-if="machineHasActiveL1(machine) || machineHasActiveSat(machine)" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px;">
                <span v-if="machineHasActiveL1(machine)" data-testid="restocker-machine-l1-badge" style="font-size: 10px; font-weight: 600; padding: 3px 7px; border-radius: var(--radius-interactive); background-color: var(--color-warning-bg); color: var(--color-warning-text);">🛒 Aviso Nivel 1</span>
                <span v-if="machineHasActiveSat(machine)" data-testid="restocker-machine-sat-badge" style="font-size: 10px; font-weight: 600; padding: 3px 7px; border-radius: var(--radius-interactive); background-color: var(--color-info-bg); color: var(--color-info-text);">🔧 SAT en curso</span>
              </div>

              <p
                v-if="!machineCanRestock(machine)"
                data-testid="restocker-machine-block-reason"
                style="font-size: 11px; color: var(--color-warning-text); background-color: var(--color-warning-bg); border-radius: var(--radius-interactive); padding: 6px 8px; margin: 6px 0 0 0;"
              >
                ⚠️ {{ machineRestockBlockReason(machine) }}
              </p>
              <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px;">
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary vg-btn-sm"
                  data-testid="btn-escalate-machine-to-sat"
                  :data-machine-id="machine.id"
                  @click="openEscalate(machine)"
                >
                  ⚡ Escalar Avería al SAT
                </button>
                <button
                  v-if="machineHasActiveL1(machine)"
                  type="button"
                  class="vg-btn vg-btn-primary vg-btn-sm"
                  data-testid="btn-open-l1-incidents"
                  @click="scrollToL1Incidents"
                >
                  🛒 Atender aviso Nivel 1
                </button>
              </div>
            </li>
          </ul>
        </section>

        <!-- Bandeja de Avisos de Nivel 1 y cola offline (Módulo 36, RF-03.1–03.2, C2) -->
        <section
          id="restocker-l1-incidents"
          data-testid="restocker-l1-incidents"
          style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 14px; margin-top: 12px;"
        >
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
            <div>
              <h2 style="font-family: var(--font-display); font-size: 16px; font-weight: 500; margin: 0 0 4px 0;">🛒 Avisos de Nivel 1</h2>
              <p style="font-size: 12px; color: var(--color-ink-muted); margin: 0;">
                Resuelve in situ los avisos leves de tu zona o escálalos al SAT.
              </p>
            </div>
            <button
              v-if="pendingIncidentMutations.length > 0"
              type="button"
              class="vg-btn vg-btn-secondary vg-btn-sm"
              data-testid="btn-sync-l1-incidents"
              :disabled="isSyncingIncidents"
              @click="syncPendingIncidentMutations"
            >
              🔄 Sincronizar ({{ pendingIncidentCount }})
            </button>
          </div>

          <div v-if="incidentSyncError" data-testid="restocker-l1-sync-error" style="font-size: 12px; color: var(--color-error-text); background-color: var(--color-error-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 10px;">⚠️ {{ incidentSyncError }}</div>
          <div v-if="incidentSyncMessage" data-testid="restocker-l1-sync-message" style="font-size: 12px; color: var(--color-success-text); background-color: var(--color-success-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 10px;">{{ incidentSyncMessage }}</div>

          <p v-if="assignedL1Incidents.length === 0" data-testid="restocker-l1-empty" style="font-size: 13px; color: var(--color-ink-muted); margin: 12px 0 0 0;">
            No tienes avisos de Nivel 1 asignados.
          </p>
          <ul v-else style="margin: 12px 0 0 0; padding: 0; list-style: none; display: grid; gap: 10px;">
            <li
              v-for="incident in assignedL1Incidents"
              :key="incident.id"
              data-testid="restocker-l1-incident-card"
              :data-incident-id="incident.id"
              style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px 12px;"
            >
              <div style="display: flex; justify-content: space-between; gap: 8px;">
                <strong style="font-family: var(--font-display); font-size: 14px;">#{{ incident.ticket_code }}</strong>
                <span data-testid="restocker-l1-status" style="font-size: 10px; font-weight: 600; padding: 3px 7px; border-radius: var(--radius-interactive); background-color: var(--color-warning-bg); color: var(--color-warning-text);">{{ l1StatusLabel(incident.status) }}</span>
              </div>
              <div style="font-size: 12px; color: var(--color-slate); margin-top: 3px;">
                🛒 {{ l1IssueLabel(incident.level1_issue_type) }} · {{ incident.machine_code }} · {{ incident.location_name }}
              </div>
              <p style="font-size: 12px; color: var(--color-ink-muted); margin: 4px 0 0 0;">{{ incident.description }}</p>
              <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px;">
                <button
                  v-if="incident.status === 'ASSIGNED' || incident.status === 'REOPENED'"
                  type="button"
                  class="vg-btn vg-btn-primary vg-btn-sm"
                  data-testid="btn-start-l1-incident"
                  @click="startL1(incident)"
                >▶ Iniciar Nivel 1</button>
                <button
                  v-if="incident.status === 'IN_PROGRESS'"
                  type="button"
                  class="vg-btn vg-btn-primary vg-btn-sm"
                  data-testid="btn-resolve-l1-incident"
                  @click="openL1Resolve(incident)"
                >✓ Resolver en 1 toque</button>
                <button
                  type="button"
                  class="vg-btn vg-btn-secondary vg-btn-sm"
                  data-testid="btn-escalate-l1-incident"
                  @click="openEscalate(machines.find((row) => Number(row.id) === Number(incident.machine_id)) || { id: incident.machine_id, code: incident.machine_code }, incident)"
                >⚡ Escalar Avería al SAT</button>
              </div>
            </li>
          </ul>

          <div v-if="pendingIncidentMutations.length > 0" data-testid="restocker-offline-incident-queue" style="margin-top: 10px; padding: 10px; border: 1px solid var(--color-hairline); border-radius: var(--radius-card); background-color: var(--color-warning-bg);">
            <strong style="display: block; font-size: 12px; color: var(--color-warning-text);">📥 Cola offline de avisos L1 · {{ pendingIncidentCount }} pendiente(s) · {{ conflictIncidentCount }} conflicto(s)</strong>
            <ul style="margin: 8px 0 0 0; padding: 0; list-style: none; display: grid; gap: 6px;">
              <li v-for="item in pendingIncidentMutations" :key="item.client_mutation_id" data-testid="restocker-offline-incident-item" style="font-size: 11px; color: var(--color-slate);">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 6px;">
                  <span><strong>{{ item.machine_code || ('Máquina #' + item.machine_id) }}</strong> · {{ item.operation_type }} · {{ item.status === 'CONFLICT' ? 'Conflicto' : 'Pendiente' }}</span>
                  <button v-if="item.status === 'CONFLICT'" type="button" class="vg-btn vg-btn-ghost vg-btn-sm" data-testid="btn-archive-l1-conflict" :disabled="isSyncingIncidents" @click="archiveIncidentConflict(item)">📥 Archivar</button>
                </div>
              </li>
            </ul>
          </div>
        </section>

        <!-- Historial reciente y cola offline de visitas (Módulo 34, RF-05.1, C3, D4) -->
        <section
          data-testid="restocker-recent-visits"
          style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 14px; margin-top: 12px;"
        >
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
            <div>
              <h2 style="font-family: var(--font-display); font-size: 16px; font-weight: 500; margin: 0 0 4px 0;">🕓 Mis Visitas de Reposición Recientes</h2>
              <p style="font-size: 12px; color: var(--color-ink-muted); margin: 0;">
                Últimas cinco visitas registradas en tus zonas.
              </p>
            </div>
            <button
              v-if="pendingVisitMutations.length > 0"
              type="button"
              class="vg-btn vg-btn-secondary vg-btn-sm"
              data-testid="btn-sync-visits"
              :disabled="isSyncingVisits"
              @click="syncPendingVisits"
            >
              {{ isSyncingVisits ? 'Sincronizando…' : '🔄 Sincronizar Visitas Pendientes' }}
            </button>
          </div>

          <div
            v-if="visitSyncMessage"
            data-testid="restocker-visit-sync-message"
            style="font-size: 12px; color: var(--color-success-text); background-color: var(--color-success-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 10px;"
          >
            {{ visitSyncMessage }}
          </div>
          <div
            v-if="visitSyncError"
            data-testid="restocker-visit-sync-error"
            style="font-size: 12px; color: var(--color-error-text); background-color: var(--color-error-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 10px;"
          >
            ⚠️ {{ visitSyncError }}
          </div>

          <div
            v-if="pendingVisitMutations.length > 0"
            data-testid="restocker-offline-visit-queue"
            style="margin-top: 10px; padding: 10px; border: 1px solid var(--color-hairline); border-radius: var(--radius-card); background-color: var(--color-warning-bg);"
          >
            <strong style="display: block; font-size: 12px; color: var(--color-warning-text);">
              📥 Cola offline de visitas · {{ pendingVisitCount }} pendiente(s) · {{ conflictVisitCount }} conflicto(s)
            </strong>
            <ul style="margin: 8px 0 0 0; padding: 0; list-style: none; display: grid; gap: 6px;">
              <li
                v-for="item in pendingVisitMutations"
                :key="item.client_mutation_id"
                data-testid="restocker-offline-visit-item"
                style="font-size: 11px; color: var(--color-slate);"
              >
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 6px;">
                  <span>
                    <strong>{{ item.machine_code || ('Máquina #' + item.machine_id) }}</strong>
                    · {{ item.status === 'CONFLICT' ? 'Conflicto' : 'Pendiente' }}
                    · {{ item.payload?.status === 'ABORTED_THERMAL_BREACH' ? 'Aborto térmico' : 'Visita completada' }}
                  </span>
                  <button
                    v-if="item.status === 'CONFLICT'"
                    type="button"
                    class="vg-btn vg-btn-ghost vg-btn-sm"
                    data-testid="btn-archive-visit-conflict"
                    :disabled="isSyncingVisits"
                    @click="archiveVisitConflict(item)"
                  >
                    📥 Archivar Conflicto Offline en Auditoría
                  </button>
                </div>
                <span v-if="item.conflict_reason" style="display: block; color: var(--color-error-text); margin-top: 2px;">
                  {{ item.conflict_reason }}
                </span>
              </li>
            </ul>
          </div>

          <p v-if="recentVisits.length === 0" data-testid="restocker-recent-visits-empty" style="font-size: 13px; color: var(--color-ink-muted); margin: 12px 0 0 0;">
            Todavía no hay visitas registradas.
          </p>
          <ul v-else style="margin: 12px 0 0 0; padding: 0; list-style: none; display: grid; gap: 8px;">
            <li
              v-for="visit in recentVisits"
              :key="visit.id || visit.visit_code"
              data-testid="restocker-recent-visit-item"
              style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px 10px;"
            >
              <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                <div style="min-width: 0;">
                  <strong style="display: block; font-size: 12px; font-family: var(--font-display); font-weight: 500;">
                    {{ visit.machine_code || 'Máquina' }} · {{ visit.visit_code || 'Visita' }}
                  </strong>
                  <span style="display: block; font-size: 11px; color: var(--color-ink-muted);">
                    {{ visit.location_name || visit.machine_location_name || '—' }} · {{ formatShiftDate(visit.completed_at || visit.created_at) }}
                  </span>
                </div>
                <span
                  :style="visitStatusStyle(visit.status)"
                  style="font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: var(--radius-interactive); white-space: nowrap;"
                >{{ visitStatusIcon(visit.status) }} {{ visit.status_label || visitStatusLabel(visit.status) }}</span>
              </div>
              <div style="display: flex; flex-wrap: wrap; gap: 8px; font-size: 11px; color: var(--color-ink-muted); margin-top: 5px;">
                <span>{{ visit.cabin_temp_celsius ?? '—' }} °C</span>
                <span>+{{ visit.units_added ?? 0 }} uds. cargadas</span>
                <span>−{{ visit.units_withdrawn ?? 0 }} uds. retiradas</span>
                <span v-if="visit.cash_collection_pending" data-testid="restocker-visit-cash-pending">🪙 Recaudación pendiente</span>
              </div>
            </li>
          </ul>
        </section>

        <!-- Historial de bolsas y cola offline de recaudaciones (T-CCV-11, C2, C4, Art. V.4) -->
        <section
          data-testid="restocker-cash-history"
          style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 14px; margin-top: 12px;"
        >
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
            <div>
              <h2 style="font-family: var(--font-display); font-size: 16px; font-weight: 500; margin: 0 0 4px 0;">🪙 Mis Bolsas Precintadas y Cambios de Ruta</h2>
              <p style="font-size: 12px; color: var(--color-ink-muted); margin: 0;">
                Arqueo Ciego: solo consta lo que declaraste en ruta. La cámara concilia en privado.
              </p>
            </div>
            <button
              v-if="pendingCashMutations.length > 0"
              type="button"
              class="vg-btn vg-btn-secondary vg-btn-sm"
              data-testid="btn-sync-cash-collections"
              :disabled="isSyncingCash"
              @click="syncPendingCashCollections"
            >
              {{ isSyncingCash ? 'Sincronizando…' : '🔄 Sincronizar Recaudaciones' }}
            </button>
          </div>

          <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-top: 10px;">
            <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px;">
              <span style="display: block; font-size: 10px; color: var(--color-ink-muted); text-transform: uppercase;">Recaudaciones</span>
              <strong data-testid="cash-summary-total" style="font-family: var(--font-display); font-size: 15px;">{{ Number(cashRouteSummary.total_collections_count || 0) }}</strong>
            </div>
            <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px;">
              <span style="display: block; font-size: 10px; color: var(--color-ink-muted); text-transform: uppercase;">Pendientes de cámara</span>
              <strong data-testid="cash-summary-pending-vault" style="font-family: var(--font-display); font-size: 15px; color: var(--color-warning-text);">{{ Number(cashRouteSummary.pending_vault_bags_count || 0) }}</strong>
            </div>
            <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px;">
              <span style="display: block; font-size: 10px; color: var(--color-ink-muted); text-transform: uppercase;">Cambio cargado</span>
              <strong style="font-family: var(--font-display); font-size: 15px; color: var(--color-info-text);">{{ formatPrice(cashRouteSummary.total_change_tubes_loaded_eur) }}</strong>
            </div>
            <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px;">
              <span style="display: block; font-size: 10px; color: var(--color-ink-muted); text-transform: uppercase;">Entregado en conserjería</span>
              <strong style="font-family: var(--font-display); font-size: 15px; color: var(--color-success-text);">{{ formatPrice(cashRouteSummary.total_reception_cash_delivered_eur) }}</strong>
            </div>
          </div>

          <div
            v-if="cashSyncMessage"
            data-testid="restocker-cash-sync-message"
            style="font-size: 12px; color: var(--color-success-text); background-color: var(--color-success-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 10px;"
          >
            {{ cashSyncMessage }}
          </div>
          <div
            v-if="cashSyncError"
            data-testid="restocker-cash-sync-error"
            style="font-size: 12px; color: var(--color-error-text); background-color: var(--color-error-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 10px;"
          >
            ⚠️ {{ cashSyncError }}
          </div>

          <div
            v-if="pendingCashMutations.length > 0"
            data-testid="restocker-offline-cash-queue"
            style="margin-top: 10px; padding: 10px; border: 1px solid var(--color-hairline); border-radius: var(--radius-card); background-color: var(--color-warning-bg);"
          >
            <strong style="display: block; font-size: 12px; color: var(--color-warning-text);">
              📥 Cola offline de recaudaciones · {{ pendingCashCount }} pendiente(s) · {{ conflictCashCount }} conflicto(s)
            </strong>
            <ul style="margin: 8px 0 0 0; padding: 0; list-style: none; display: grid; gap: 6px;">
              <li
                v-for="item in pendingCashMutations"
                :key="item.client_mutation_id"
                data-testid="restocker-offline-cash-item"
                style="font-size: 11px; color: var(--color-slate);"
              >
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 6px;">
                  <span>
                    <strong>{{ item.machine_code || ('Máquina #' + item.machine_id) }}</strong>
                    · {{ item.status === 'CONFLICT' ? 'Conflicto' : 'Pendiente' }}
                    · {{ item.payload?.bag_seal_code || 'Sin precinto' }}
                  </span>
                  <button
                    v-if="item.status === 'CONFLICT'"
                    type="button"
                    class="vg-btn vg-btn-ghost vg-btn-sm"
                    data-testid="btn-archive-cash-conflict"
                    :disabled="isSyncingCash"
                    @click="archiveCashConflict(item)"
                  >
                    📥 Archivar Conflicto en Auditoría
                  </button>
                </div>
                <span v-if="item.conflict_reason" style="display: block; color: var(--color-error-text); margin-top: 2px;">
                  {{ item.conflict_reason }}
                </span>
              </li>
            </ul>
          </div>

          <p v-if="recentCashCollections.length === 0" data-testid="restocker-cash-history-empty" style="font-size: 13px; color: var(--color-ink-muted); margin: 12px 0 0 0;">
            Todavía no has precintado ninguna bolsa ni cargado cambio en ruta.
          </p>
          <ul v-else style="margin: 12px 0 0 0; padding: 0; list-style: none; display: grid; gap: 8px;">
            <li
              v-for="collection in recentCashCollections"
              :key="collection.id || collection.collection_code"
              data-testid="restocker-cash-collection-item"
              :data-collection-id="collection.id"
              style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px 10px;"
            >
              <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                <div style="min-width: 0;">
                  <strong style="display: block; font-size: 12px; font-family: var(--font-display); font-weight: 500;">
                    {{ collection.collection_code || 'Recaudación' }} · {{ collection.machine_code || 'Máquina' }}
                  </strong>
                  <span style="display: block; font-size: 11px; color: var(--color-ink-muted);">
                    {{ formatShiftDate(collection.collected_at || collection.created_at) }} · precinto {{ collection.bag_seal_code || '—' }}
                  </span>
                </div>
                <span
                  :style="cashStatusStyle(collection.status)"
                  style="font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: var(--radius-interactive); white-space: nowrap;"
                >{{ cashStatusLabel(collection) }}</span>
              </div>
              <div style="display: flex; flex-wrap: wrap; gap: 8px; font-size: 11px; color: var(--color-ink-muted); margin-top: 5px;">
                <span>Contador {{ formatPrice(collection.sales_counter_reading) }}</span>
                <span>Cambio {{ formatPrice(collection.change_tubes_loaded_eur) }}</span>
                <span v-if="Number(collection.reception_cash_delivered_eur || 0) > 0">Conserjería {{ formatPrice(collection.reception_cash_delivered_eur) }}</span>
              </div>
              <button
                v-if="collection.has_reception_receipt && collection.id && !collection.is_offline_pending"
                type="button"
                class="vg-btn vg-btn-ghost vg-btn-sm mt-1"
                data-testid="btn-open-cash-receipt"
                @click="openCashReceipt(collection)"
              >
                🧾 Ver Recibo de Conserjería
              </button>
            </li>
          </ul>
        </section>

        <!-- Modal de planograma en solo lectura (T-PLN-13, D2, QA 2, Art. V.4) -->
        <div
          v-if="showPlanogramModal"
          data-testid="modal-restocker-planogram"
          style="position: fixed; inset: 0; background-color: color-mix(in srgb, var(--color-slate) 45%, transparent); display: flex; align-items: flex-end; justify-content: center; z-index: 70; padding: 0;"
          @click.self="closePlanogram"
        >
          <div
            style="background-color: var(--color-surface-card); width: 100%; max-width: 480px; max-height: 88vh; overflow-y: auto; border-top-left-radius: var(--radius-card); border-top-right-radius: var(--radius-card); border: 1px solid var(--color-hairline); padding: 16px; box-sizing: border-box;"
          >
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
              <div style="min-width: 0;">
                <h2 style="font-family: var(--font-display); font-size: 18px; font-weight: 500; margin: 0;">
                  {{ selectedMachine?.code || 'Planograma' }}
                </h2>
                <p style="font-size: 12px; color: var(--color-ink-muted); margin: 2px 0 0 0;">
                  {{ selectedMachine?.model }} · {{ selectedMachine?.location_name }}<template v-if="selectedMachine?.zone_name"> · {{ selectedMachine.zone_name }}</template>
                </p>
              </div>
              <button
                type="button"
                class="vg-btn vg-btn-ghost vg-btn-sm"
                data-testid="btn-close-planogram"
                @click="closePlanogram"
              >
                Cerrar
              </button>
            </div>

            <p
              style="display: inline-flex; align-items: center; gap: 6px; margin: 10px 0 0 0; padding: 4px 8px; font-size: 10px; font-weight: 600; border-radius: var(--radius-interactive); background-color: var(--color-info-bg); color: var(--color-info-text);"
            >
              🔒 Solo lectura · sin coste interno
            </p>

            <p v-if="planogramLoading" style="font-size: 13px; color: var(--color-ink-muted); margin-top: 12px;">
              Cargando el planograma…
            </p>
            <div
              v-else-if="planogramError"
              data-testid="restocker-planogram-error"
              style="font-size: 12px; color: var(--color-urgency-critical); background-color: var(--color-urgency-critical-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 12px;"
            >
              {{ planogramError }}
            </div>

            <template v-else-if="planogram">
              <div data-testid="restocker-planogram-summary" style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-top: 12px;">
                <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px;">
                  <span style="display: block; font-size: 10px; color: var(--color-ink-muted); text-transform: uppercase;">Carriles</span>
                  <strong style="font-family: var(--font-display); font-size: 16px; font-weight: 500;">{{ planogramSummary.active_slots_count }}</strong>
                </div>
                <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px;">
                  <span style="display: block; font-size: 10px; color: var(--color-ink-muted); text-transform: uppercase;">Llenado</span>
                  <strong style="font-family: var(--font-display); font-size: 16px; font-weight: 500; color: var(--color-primary);">{{ Number(planogramSummary.fill_rate_percent || 0).toFixed(1) }} %</strong>
                </div>
                <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px;">
                  <span style="display: block; font-size: 10px; color: var(--color-ink-muted); text-transform: uppercase;">Agotados</span>
                  <strong style="font-family: var(--font-display); font-size: 16px; font-weight: 500; color: var(--color-error-text);">{{ planogramSummary.stockout_slots_count }}</strong>
                </div>
                <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px;">
                  <span style="display: block; font-size: 10px; color: var(--color-ink-muted); text-transform: uppercase;">Bajo umbral</span>
                  <strong style="font-family: var(--font-display); font-size: 16px; font-weight: 500; color: var(--color-warning-text);">{{ planogramSummary.low_stock_slots_count }}</strong>
                </div>
              </div>

              <p v-if="planogramSlots.length === 0" style="font-size: 13px; color: var(--color-ink-muted); margin-top: 12px;">
                Esta máquina no tiene carriles activos.
              </p>

              <ul v-else style="margin: 12px 0 0 0; padding: 0; list-style: none; display: grid; gap: 8px;">
                <li
                  v-for="slot in planogramSlots"
                  :key="slot.id || slot.slot_code"
                  data-testid="restocker-planogram-slot"
                  :data-slot-code="slot.slot_code"
                  style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px;"
                >
                  <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                    <div style="min-width: 0;">
                      <strong style="display: block; font-family: var(--font-display); font-size: 13px; font-weight: 500;">{{ slot.slot_code }} · {{ slot.product_name }}</strong>
                      <span style="display: block; font-size: 11px; color: var(--color-ink-muted);">{{ slot.product_sku }}</span>
                    </div>
                    <span
                      style="font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: var(--radius-interactive); white-space: nowrap;"
                      :style="stockStatusStyle(slot.stock_status)"
                    >{{ stockStatusLabel(slot.stock_status) }}</span>
                  </div>

                  <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px;">
                    <span style="font-size: 10px; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-surface-1); color: var(--color-slate);">{{ categoryLabel(slot.product_category) }} · {{ unitTypeLabel(slot.product_unit_type) }}</span>
                    <span v-if="slot.requires_cold_chain" style="font-size: 10px; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-info-bg); color: var(--color-info-text);">❄️ Frío</span>
                    <span v-if="slot.product_is_active === false" style="font-size: 10px; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-error-bg); color: var(--color-error-text);">⚠️ Producto dado de baja (Sustituir)</span>
                  </div>

                  <div style="display: flex; justify-content: space-between; gap: 8px; font-size: 11px; margin-top: 8px;">
                    <span>{{ slot.current_estimated_qty }} / {{ slot.max_capacity }} uds.</span>
                    <span style="color: var(--color-ink-muted);">Umbral {{ slot.min_threshold }}</span>
                  </div>
                  <div style="height: 6px; background-color: var(--color-surface-1); border-radius: var(--radius-interactive); overflow: hidden; margin-top: 4px;">
                    <div :style="{ width: slotFillPercent(slot) + '%', height: '100%', backgroundColor: stockStatusColor(slot.stock_status) }"></div>
                  </div>

                  <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px; font-size: 11px; margin-top: 6px; color: var(--color-ink-muted);">
                    <span v-if="Number(slot.restock_needed_qty || 0) > 0">Reponer {{ slot.restock_needed_qty }} uds.</span>
                    <span v-else>Sin reposición pendiente</span>
                    <span>PVP {{ formatPrice(slot.sale_price_eur) }}</span>
                  </div>
                </li>
              </ul>
            </template>
          </div>
        </div>

        <!-- Modal rápido de recaudación, cambio y conserjería (T-CCV-11, D1, D3) -->
        <div
          v-if="showCashModal"
          data-testid="modal-cash-collection"
          style="position: fixed; inset: 0; background-color: color-mix(in srgb, var(--color-slate) 45%, transparent); display: flex; align-items: flex-end; justify-content: center; z-index: 78; padding: 0;"
          @click.self="cancelCashModal"
        >
          <div
            style="background-color: var(--color-surface-card); width: 100%; max-width: 480px; max-height: 92vh; overflow-y: auto; border-top-left-radius: var(--radius-card); border-top-right-radius: var(--radius-card); border: 1px solid var(--color-hairline); padding: 16px; box-sizing: border-box;"
          >
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
              <div style="min-width: 0;">
                <h2 style="font-family: var(--font-display); font-size: 18px; font-weight: 500; margin: 0;">
                  🪙 {{ cashContextIsVisit ? 'Recaudación integrada en la visita' : 'Recaudación / Cambio en ruta' }}
                </h2>
                <p style="font-size: 12px; color: var(--color-ink-muted); margin: 2px 0 0 0;">
                  {{ cashModalMachine?.code || 'Máquina' }} · {{ cashModalMachine?.location_name || '—' }}
                </p>
              </div>
              <button type="button" class="vg-btn vg-btn-ghost vg-btn-sm" data-testid="btn-close-cash" @click="cancelCashModal">
                Cerrar
              </button>
            </div>

            <div
              v-if="cashError"
              data-testid="restocker-cash-error"
              style="font-size: 12px; color: var(--color-error-text); background-color: var(--color-error-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 10px;"
            >
              ⚠️ {{ cashError }}
            </div>

            <p style="font-size: 10px; color: var(--color-ink-muted); margin: 10px 0 0 0;">
              Arqueo Ciego: declara la lectura del contador y el cambio cargado. El esperado de caja, la lectura anterior y el arqueo de cámara no se muestran al reponedor (C2, Art. V.4).
            </p>

            <label style="display: block; font-size: 11px; font-weight: 600; color: var(--color-slate); margin-top: 12px;">
              Precinto de la bolsa · obligatorio
            </label>
            <input
              v-model="cashForm.bag_seal_code"
              type="text"
              maxlength="40"
              placeholder="BAG-XXXX-0000"
              data-testid="cash-bag-seal"
              style="width: 100%; box-sizing: border-box; height: 36px; margin-top: 4px; padding: 0 8px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); text-transform: uppercase;"
            />
            <p style="font-size: 10px; color: var(--color-ink-muted); margin: 4px 0 0 0;">
              6–40 caracteres A–Z, 0–9, guion o guion bajo, con al menos 3 dígitos. El precinto es global e irrepetible (C1).
            </p>

            <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-top: 10px;">
              <label style="display: block; font-size: 11px; color: var(--color-slate);">
                Lectura del contador (€)
                <input
                  v-model.number="cashForm.sales_counter_reading"
                  type="number"
                  step="0.01"
                  min="0"
                  max="9999999.99"
                  data-testid="cash-counter-reading"
                  style="width: 100%; box-sizing: border-box; height: 34px; margin-top: 3px; padding: 0 6px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);"
                />
              </label>
              <label style="display: block; font-size: 11px; color: var(--color-slate);">
                Tubos de cambio (€)
                <input
                  v-model.number="cashForm.change_tubes_loaded_eur"
                  type="number"
                  step="0.01"
                  min="0"
                  max="500"
                  data-testid="cash-change-tubes"
                  style="width: 100%; box-sizing: border-box; height: 34px; margin-top: 3px; padding: 0 6px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);"
                />
              </label>
            </div>

            <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 12px; margin-top: 10px;">
              <input v-model="cashForm.is_counter_reset" type="checkbox" data-testid="cash-counter-reset" style="margin-top: 2px;" />
              <span>La máquina se ha reseteado y la lectura vuelve a cero.</span>
            </label>
            <label v-if="cashForm.is_counter_reset" style="display: block; font-size: 11px; color: var(--color-slate); margin-top: 8px;">
              Justificación del reseteo · mín. 20 caracteres y 3 bloques
              <textarea
                v-model="cashForm.counter_reset_notes"
                rows="2"
                maxlength="500"
                data-testid="cash-reset-notes"
                style="width: 100%; box-sizing: border-box; margin-top: 3px; padding: 6px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 12px;"
              ></textarea>
            </label>

            <div
              v-if="cashMachineHasReception"
              style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px; margin-top: 12px;"
            >
              <strong style="display: block; font-size: 12px; color: var(--color-slate);">🏢 Entrega en conserjería · máximo 10,00 €</strong>
              <p style="font-size: 10px; color: var(--color-ink-muted); margin: 4px 0 0 0;">
                Selecciona un reintegro EN_MANO_SEDE para igualar importe y reclamante, o declara una entrega manual con el nombre del conserje.
              </p>
              <div v-if="cashPendingRefunds.length > 0" style="display: grid; gap: 6px; margin-top: 8px;">
                <button
                  v-for="refund in cashPendingRefunds"
                  :key="refund.id"
                  type="button"
                  class="vg-btn vg-btn-sm"
                  :class="Number(cashForm.linked_refund_request_id) === Number(refund.id) ? 'vg-btn-primary' : 'vg-btn-secondary'"
                  data-testid="cash-refund-option"
                  :data-refund-id="refund.id"
                  @click="selectCashRefund(refund)"
                >
                  Reintegro #{{ refund.id }} · {{ formatPrice(refund.claimed_amount) }} · 1 toque
                </button>
              </div>
              <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-top: 8px;">
                <label style="display: block; font-size: 11px; color: var(--color-slate);">
                  Efectivo entregado (€)
                  <input
                    v-model.number="cashForm.reception_cash_delivered_eur"
                    type="number"
                    step="0.01"
                    min="0"
                    max="10"
                    data-testid="cash-reception-amount"
                    style="width: 100%; box-sizing: border-box; height: 34px; margin-top: 3px; padding: 0 6px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);"
                  />
                </label>
                <label style="display: block; font-size: 11px; color: var(--color-slate);">
                  Nombre del conserje
                  <input
                    v-model="cashForm.receptionist_name"
                    type="text"
                    maxlength="120"
                    data-testid="cash-receptionist-name"
                    style="width: 100%; box-sizing: border-box; height: 34px; margin-top: 3px; padding: 0 6px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);"
                  />
                </label>
              </div>
              <p v-if="cashSelectedRefund" data-testid="cash-refund-locked" style="font-size: 10px; color: var(--color-success-text); margin: 6px 0 0 0;">
                Importe bloqueado al reclamado del expediente #{{ cashSelectedRefund.id }} ({{ formatPrice(cashSelectedRefund.claimed_amount) }}).
              </p>
            </div>
            <p v-else style="font-size: 11px; color: var(--color-warning-text); background-color: var(--color-warning-bg); border-radius: var(--radius-interactive); padding: 7px 8px; margin-top: 12px;">
              Esta sede no tiene conserjería física: la bolsa y el cambio se registran, pero no puede entregarse efectivo aquí (D3).
            </p>

            <label style="display: block; font-size: 11px; color: var(--color-slate); margin-top: 10px;">
              Notas de ruta · opcionales
              <textarea
                v-model="cashForm.route_notes"
                rows="2"
                maxlength="500"
                data-testid="cash-route-notes"
                style="width: 100%; box-sizing: border-box; margin-top: 3px; padding: 6px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 12px;"
              ></textarea>
            </label>

            <p style="font-size: 10px; color: var(--color-ink-muted); margin: 10px 0 0 0;">
              Sin conexión, la recaudación se guarda en una cola IndexedDB FIFO y se sincroniza al recuperar cobertura (D4, C4).
            </p>

            <div style="display: flex; gap: 8px; margin-top: 14px;">
              <button type="button" class="vg-btn vg-btn-lg vg-btn-secondary flex-grow-1" @click="cancelCashModal">
                Cancelar
              </button>
              <button
                type="button"
                class="vg-btn vg-btn-lg vg-btn-primary flex-grow-1"
                data-testid="btn-save-cash-collection"
                :disabled="cashSubmitting"
                @click="saveCashFromModal"
              >
                {{ cashSubmitting ? 'Registrando…' : (cashContextIsVisit ? 'Añadir a la visita' : 'Registrar recaudación') }}
              </button>
            </div>
          </div>
        </div>

        <!-- Recibo opaco de conserjería: exactamente nueve campos (QA 2, Art. V.4) -->
        <div
          v-if="showCashReceiptModal"
          data-testid="cash-reception-receipt-modal"
          style="position: fixed; inset: 0; background-color: color-mix(in srgb, var(--color-slate) 45%, transparent); display: flex; align-items: flex-end; justify-content: center; z-index: 82; padding: 0;"
          @click.self="closeCashReceipt"
        >
          <div
            style="background-color: var(--color-surface-card); width: 100%; max-width: 480px; max-height: 92vh; overflow-y: auto; border-top-left-radius: var(--radius-card); border-top-right-radius: var(--radius-card); border: 1px solid var(--color-hairline); padding: 16px; box-sizing: border-box;"
          >
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
              <div>
                <h2 style="font-family: var(--font-display); font-size: 18px; font-weight: 500; margin: 0;">🧾 Recibo de Conserjería</h2>
                <p style="font-size: 12px; color: var(--color-ink-muted); margin: 2px 0 0 0;">
                  Entrega de efectivo en sede · sin datos de la bolsa ni PIN del reclamante.
                </p>
              </div>
              <button type="button" class="vg-btn vg-btn-ghost vg-btn-sm" data-testid="btn-close-cash-receipt" @click="closeCashReceipt">
                Cerrar
              </button>
            </div>
            <p v-if="cashReceiptLoading" style="font-size: 13px; color: var(--color-ink-muted); margin-top: 12px;">Cargando el recibo…</p>
            <p v-if="cashReceiptError" data-testid="restocker-cash-receipt-error" style="font-size: 12px; color: var(--color-error-text); margin-top: 12px;">⚠️ {{ cashReceiptError }}</p>
            <div v-if="!cashReceiptLoading && !cashReceiptError" style="margin-top: 12px; border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 12px;">
              <div style="display: flex; justify-content: space-between; gap: 8px; font-size: 12px; padding: 4px 0;">
                <span style="color: var(--color-ink-muted);">Recaudación</span><strong>{{ cashReceipt.collection_code || '—' }}</strong>
              </div>
              <div style="display: flex; justify-content: space-between; gap: 8px; font-size: 12px; padding: 4px 0;">
                <span style="color: var(--color-ink-muted);">Fecha</span><strong>{{ formatShiftDate(cashReceipt.collected_at) }}</strong>
              </div>
              <div style="display: flex; justify-content: space-between; gap: 8px; font-size: 12px; padding: 4px 0;">
                <span style="color: var(--color-ink-muted);">Sede</span><strong>{{ cashReceipt.location_code }} · {{ cashReceipt.location_name }}</strong>
              </div>
              <div style="display: flex; justify-content: space-between; gap: 8px; font-size: 12px; padding: 4px 0;">
                <span style="color: var(--color-ink-muted);">Máquina</span><strong>{{ cashReceipt.machine_code || '—' }}</strong>
              </div>
              <div style="display: flex; justify-content: space-between; gap: 8px; font-size: 12px; padding: 4px 0;">
                <span style="color: var(--color-ink-muted);">Reponedor</span><strong>{{ cashReceipt.restocker_name || '—' }}</strong>
              </div>
              <div style="display: flex; justify-content: space-between; gap: 8px; font-size: 12px; padding: 4px 0;">
                <span style="color: var(--color-ink-muted);">Conserje</span><strong>{{ cashReceipt.receptionist_name || '—' }}</strong>
              </div>
              <div style="display: flex; justify-content: space-between; gap: 8px; font-size: 12px; padding: 4px 0;">
                <span style="color: var(--color-ink-muted);">Efectivo entregado</span><strong style="color: var(--color-success-text);">{{ formatPrice(cashReceipt.reception_cash_delivered_eur) }}</strong>
              </div>
              <div style="display: flex; justify-content: space-between; gap: 8px; font-size: 12px; padding: 4px 0;">
                <span style="color: var(--color-ink-muted);">Expediente</span><strong>#{{ cashReceipt.linked_refund_request_id || '—' }}</strong>
              </div>
            </div>
            <button
              type="button"
              class="vg-btn vg-btn-primary vg-btn-lg w-100 mt-2"
              data-testid="btn-print-cash-receipt"
              @click="printCashReceipt"
            >
              🖨️ Imprimir recibo
            </button>
          </div>
        </div>

        <!-- Asistente de visita in situ (Módulo 34, RF-02 a RF-05.1, Art. II) -->
        <div
          v-if="showVisitModal"
          data-testid="modal-restock-visit"
          style="position: fixed; inset: 0; background-color: color-mix(in srgb, var(--color-slate) 45%, transparent); display: flex; align-items: flex-end; justify-content: center; z-index: 75; padding: 0;"
          @click.self="closeVisitAssistant"
        >
          <div
            style="background-color: var(--color-surface-card); width: 100%; max-width: 480px; max-height: 92vh; overflow-y: auto; border-top-left-radius: var(--radius-card); border-top-right-radius: var(--radius-card); border: 1px solid var(--color-hairline); padding: 16px; box-sizing: border-box;"
          >
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
              <div style="min-width: 0;">
                <h2 style="font-family: var(--font-display); font-size: 18px; font-weight: 500; margin: 0;">
                  📋 Visita in situ · {{ visitMachine?.code || 'Máquina' }}
                </h2>
                <p style="font-size: 12px; color: var(--color-ink-muted); margin: 2px 0 0 0;">
                  {{ visitMachine?.model }} · {{ visitMachine?.location_name }}<template v-if="visitMachine?.zone_name"> · {{ visitMachine.zone_name }}</template>
                </p>
              </div>
              <button
                type="button"
                class="vg-btn vg-btn-ghost vg-btn-sm"
                data-testid="btn-close-visit"
                @click="closeVisitAssistant"
              >
                Cerrar
              </button>
            </div>

            <p v-if="visitLoading" style="font-size: 13px; color: var(--color-ink-muted); margin-top: 12px;">
              Cargando carriles y lotes de la furgoneta…
            </p>
            <div
              v-if="visitError"
              data-testid="restocker-visit-error"
              style="font-size: 12px; color: var(--color-error-text); background-color: var(--color-error-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 12px;"
            >
              ⚠️ {{ visitError }}
            </div>

            <template v-if="visitPlanogram && !visitLoading">
              <!-- Control térmico y aborto automático (Art. II) -->
              <div
                style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px; margin-top: 12px;"
              >
                <label style="display: block; font-size: 11px; font-weight: 600; color: var(--color-slate);">
                  Temperatura de cabina (°C) {{ visitTemperatureRequired ? '· obligatoria' : '· opcional' }}
                </label>
                <input
                  v-model.number="visitForm.cabin_temp_celsius"
                  type="number"
                  step="0.1"
                  min="-5"
                  max="35"
                  data-testid="visit-cabin-temperature"
                  style="width: 100%; box-sizing: border-box; height: 36px; margin-top: 4px; padding: 0 8px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);"
                />
                <p v-if="visitThermalCeiling !== null" style="font-size: 10px; color: var(--color-ink-muted); margin: 4px 0 0 0;">
                  Techo sanitario para {{ machineTypeLabel(visitMachine?.machine_type) }}: ≤ {{ visitThermalCeiling }} °C.
                </p>
                <div
                  v-if="visitThermalBreach"
                  data-testid="visit-thermal-breach"
                  style="font-size: 12px; color: var(--color-error-text); background-color: var(--color-error-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 8px;"
                >
                  🌡️ <strong>ABORTED_THERMAL_BREACH</strong> · La cabina supera el techo sanitario. La carga y la retirada quedan bloqueadas. Precinta la máquina, fotografía la evidencia y justifica el aborto.
                </div>
              </div>

              <template v-if="visitIsThermalAbort">
                <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 12px; margin-top: 12px;">
                  <input v-model="visitForm.precautionary_seal_applied" type="checkbox" data-testid="visit-precautionary-seal" style="margin-top: 2px;" />
                  <span>Precinto de seguridad aplicado en la máquina (obligatorio en aborto térmico).</span>
                </label>
              </template>

              <!-- Carriles y movimientos FEFO (RF-03, RF-03.1, RF-05) -->
              <template v-else>
                <p v-if="visitForm.items.length === 0" style="font-size: 13px; color: var(--color-ink-muted); margin-top: 12px;">
                  Esta máquina no tiene carriles activos para reponer.
                </p>
                <ul v-else style="margin: 12px 0 0 0; padding: 0; list-style: none; display: grid; gap: 8px;">
                  <li
                    v-for="item in visitForm.items"
                    :key="item.slot_code"
                    data-testid="visit-slot"
                    :data-slot-code="item.slot_code"
                    style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px;"
                  >
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                      <div style="min-width: 0;">
                        <strong style="display: block; font-size: 12px; font-family: var(--font-display); font-weight: 500;">{{ item.slot_code }} · {{ item.product_name }}</strong>
                        <span style="display: block; font-size: 11px; color: var(--color-ink-muted);">
                          {{ item.qty_before }} / {{ item.max_capacity }} uds. · lote {{ item.current_batch_code || '—' }}
                          <template v-if="item.current_expiration_date"> · cad. {{ formatShiftDate(item.current_expiration_date) }}</template>
                        </span>
                      </div>
                      <span
                        v-if="item.slot_expiry_status !== 'OK'"
                        data-testid="visit-slot-fefo-badge"
                        :style="expiryStatusStyle(item.slot_expiry_status)"
                        style="font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: var(--radius-interactive); white-space: nowrap;"
                      >{{ expiryStatusIcon(item.slot_expiry_status) }} {{ expiryStatusLabel(item.slot_expiry_status) }}</span>
                    </div>

                    <div
                      v-if="visitItemMustWithdrawBeforeLoading(item)"
                      data-testid="visit-fefo-warning"
                      style="font-size: 11px; color: var(--color-warning-text); background-color: var(--color-warning-bg); border-radius: var(--radius-interactive); padding: 7px 8px; margin-top: 7px;"
                    >
                      <span style="display: block;">⚠️ FEFO: retira todo el remanente de este carril antes de cargar el lote nuevo.</span>
                      <button
                        type="button"
                        class="vg-btn vg-btn-secondary vg-btn-sm mt-1"
                        data-testid="btn-withdraw-fefo"
                        @click="withdrawVisitRemainder(item)"
                      >
                        🗑️ Retirar remanente {{ item.slot_expiry_status === 'EXPIRED' ? 'caducado' : 'próximo a caducar' }} ({{ item.qty_before }} uds.)
                      </button>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; margin-top: 8px;">
                      <label style="display: block; font-size: 10px; color: var(--color-ink-muted);">
                        Retirar caducado
                        <input v-model.number="item.qty_withdrawn_expired" type="number" min="0" max="9999" data-testid="visit-withdraw-expired" style="width: 100%; box-sizing: border-box; height: 32px; margin-top: 3px; padding: 0 5px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);" />
                      </label>
                      <label style="display: block; font-size: 10px; color: var(--color-ink-muted);">
                        Retirar dañado
                        <input v-model.number="item.qty_withdrawn_damaged" type="number" min="0" max="9999" data-testid="visit-withdraw-damaged" style="width: 100%; box-sizing: border-box; height: 32px; margin-top: 3px; padding: 0 5px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);" />
                      </label>
                      <label style="display: block; font-size: 10px; color: var(--color-ink-muted);">
                        Cargar
                        <input
                          v-model.number="item.qty_added"
                          type="number"
                          min="0"
                          :max="visitItemMaxAddable(item)"
                          :disabled="visitItemMustWithdrawBeforeLoading(item)"
                          data-testid="visit-add-qty"
                          style="width: 100%; box-sizing: border-box; height: 32px; margin-top: 3px; padding: 0 5px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);"
                        />
                      </label>
                    </div>

                    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 8px;">
                      <select
                        v-model="item.loaded_batch_code"
                        data-testid="visit-batch-select"
                        style="flex: 1 1 180px; min-width: 0; height: 32px; padding: 0 5px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); font-size: 11px;"
                      >
                        <option value="">Selecciona lote FEFO</option>
                        <option v-for="batch in item.available_van_batches" :key="batch.batch_code" :value="batch.batch_code">
                          {{ visitBatchLabel(batch) }}
                        </option>
                      </select>
                      <button
                        type="button"
                        class="vg-btn vg-btn-secondary vg-btn-sm"
                        data-testid="btn-fill-slot"
                        :disabled="visitInputsLocked || visitItemMustWithdrawBeforeLoading(item) || visitItemMaxAddable(item) <= 0"
                        @click="fillVisitItem(item)"
                      >
                        ⚡ Llenar al máximo ({{ visitItemMaxAddable(item) }})
                      </button>
                    </div>

                    <div style="display: flex; justify-content: space-between; gap: 8px; font-size: 11px; color: var(--color-ink-muted); margin-top: 6px;">
                      <span>Quedarán {{ visitItemQtyAfter(item) }} uds.</span>
                      <span v-if="visitItemWithdrawn(item) > 0" style="color: var(--color-warning-text);">Retiradas {{ visitItemWithdrawn(item) }} uds.</span>
                    </div>
                  </li>
                </ul>

                <!-- Checklist adaptativo (RF-02, Art. II) -->
                <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px; margin-top: 12px;">
                  <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                    <strong style="font-size: 12px; color: var(--color-slate);">🧽 Checklist de higiene</strong>
                    <button type="button" class="vg-btn vg-btn-ghost vg-btn-sm" data-testid="btn-complete-hygiene" @click="markHygieneComplete">
                      Marcar completo
                    </button>
                  </div>
                  <label
                    v-for="entry in visitHygieneItems"
                    v-show="entry.required"
                    :key="entry.key"
                    style="display: flex; align-items: flex-start; gap: 8px; font-size: 12px; margin-top: 8px;"
                  >
                    <input v-model="visitForm.hygiene_checklist[entry.key]" type="checkbox" :data-testid="'visit-hygiene-' + entry.key" style="margin-top: 2px;" />
                    <span>{{ entry.label }}</span>
                  </label>
                  <p v-if="visitHygieneMissing.length > 0" style="font-size: 10px; color: var(--color-warning-text); margin: 7px 0 0 0;">
                    Faltan {{ visitHygieneMissing.length }} comprobaciones obligatorias.
                  </p>
                </div>

                <!-- Recaudación integrada opcional, jamás en aborto térmico (T-CCV-11, D1, Art. II.2) -->
                <div
                  v-if="!visitIsThermalAbort"
                  data-testid="visit-cash-block"
                  style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px; margin-top: 12px;"
                >
                  <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 12px;">
                    <input
                      v-model="visitCashEnabled"
                      type="checkbox"
                      data-testid="visit-cash-enabled"
                      style="margin-top: 2px;"
                      @change="onVisitCashToggle"
                    />
                    <span>🪙 Recaudar Hucha / Cargar Cambio / Conserjería (opcional)</span>
                  </label>
                  <p style="font-size: 10px; color: var(--color-ink-muted); margin: 5px 0 0 0;">
                    Se registrará de forma atómica dentro de esta visita. El esperado de caja y el arqueo de la cámara quedan ocultos.
                  </p>
                  <div
                    v-if="visitCashConfigured"
                    data-testid="visit-cash-summary"
                    style="font-size: 11px; background-color: var(--color-surface-1); border-radius: var(--radius-interactive); padding: 8px; margin-top: 8px;"
                  >
                    <strong style="display: block;">Precinto {{ visitCashForm.bag_seal_code }}</strong>
                    <span>Cambio {{ formatPrice(visitCashForm.change_tubes_loaded_eur) }} · Contador {{ formatPrice(visitCashForm.sales_counter_reading) }}</span>
                    <span v-if="Number(visitCashForm.reception_cash_delivered_eur || 0) > 0" style="display: block; color: var(--color-success-text); margin-top: 2px;">
                      Conserjería {{ formatPrice(visitCashForm.reception_cash_delivered_eur) }} · {{ visitCashForm.receptionist_name }}
                    </span>
                    <button
                      type="button"
                      class="vg-btn vg-btn-ghost vg-btn-sm mt-1"
                      data-testid="btn-edit-visit-cash"
                      @click="openCashCollection(visitMachine, 'VISIT')"
                    >
                      Editar recaudación
                    </button>
                  </div>
                  <button
                    v-else-if="visitCashEnabled"
                    type="button"
                    class="vg-btn vg-btn-secondary vg-btn-sm mt-2"
                    data-testid="btn-configure-visit-cash"
                    @click="openCashCollection(visitMachine, 'VISIT')"
                  >
                    Configurar recaudación
                  </button>
                </div>
              </template>

              <!-- Evidencia común: foto y notas (Art. II, Art. V.5) -->
              <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px; margin-top: 12px;">
                <strong style="display: block; font-size: 12px; color: var(--color-slate);">📷 Evidencia de la visita</strong>
                <p style="font-size: 10px; color: var(--color-ink-muted); margin: 4px 0 0 0;">
                  JPG, PNG o WebP · máximo 5 MB. Obligatoria en aborto térmico o si retiras existencias.
                </p>
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  data-testid="visit-photo-input"
                  style="display: block; width: 100%; box-sizing: border-box; margin-top: 7px; font-size: 11px;"
                  @change="handleVisitPhoto"
                />
                <div v-if="visitPhotoPreviewUrl" style="margin-top: 7px;">
                  <img :src="visitPhotoPreviewUrl" alt="Evidencia de la visita" style="max-width: 100%; max-height: 150px; border-radius: var(--radius-interactive); border: 1px solid var(--color-hairline);" />
                  <button type="button" class="vg-btn vg-btn-ghost vg-btn-sm ms-1" data-testid="btn-clear-visit-photo" @click="clearVisitPhoto">
                    Quitar foto
                  </button>
                </div>
                <label style="display: block; font-size: 11px; font-weight: 600; color: var(--color-slate); margin-top: 9px;">
                  Notas internas {{ visitNeedsEvidence ? '· obligatorias (≥ 20 caracteres)' : '· opcionales' }}
                </label>
                <textarea
                  v-model="visitForm.internal_notes"
                  rows="3"
                  maxlength="500"
                  data-testid="visit-notes"
                  style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 7px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 12px;"
                ></textarea>
                <div style="font-size: 10px; color: var(--color-ink-muted); margin-top: 3px;">
                  {{ realLength(visitForm.internal_notes) }} caracteres reales.
                </div>
              </div>

              <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-top: 12px;">
                <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px;">
                  <span style="display: block; font-size: 10px; color: var(--color-ink-muted); text-transform: uppercase;">Cargadas</span>
                  <strong style="font-family: var(--font-display); font-size: 15px; color: var(--color-success-text);">{{ visitTotalAdded }} uds.</strong>
                </div>
                <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px;">
                  <span style="display: block; font-size: 10px; color: var(--color-ink-muted); text-transform: uppercase;">Retiradas</span>
                  <strong style="font-family: var(--font-display); font-size: 15px; color: var(--color-warning-text);">{{ visitTotalWithdrawn }} uds.</strong>
                </div>
              </div>

              <button
                type="button"
                class="vg-btn vg-btn-secondary w-100 mt-3"
                data-testid="btn-escalate-from-visit"
                :data-machine-id="visitMachine?.id"
                @click="openEscalate(visitMachine, visitMachine?.active_l1_incident || null)"
              >
                ⚡ Escalar Avería al SAT
              </button>

              <div style="display: flex; gap: 8px; margin-top: 14px;">
                <button type="button" class="vg-btn vg-btn-lg vg-btn-secondary flex-grow-1" @click="closeVisitAssistant">
                  Cancelar
                </button>
                <button
                  type="button"
                  class="vg-btn vg-btn-lg vg-btn-primary flex-grow-1"
                  data-testid="btn-submit-visit"
                  :disabled="visitSubmitting"
                  @click="submitVisit"
                >
                  {{ visitSubmitting ? 'Registrando…' : (visitIsThermalAbort ? 'Abortar visita y precintar' : 'Registrar visita') }}
                </button>
              </div>
            </template>
          </div>
        </div>

        <!-- Modal de liquidación al cierre (Módulo 33, D3, QA 4, Art. V.1) -->
        <div
          v-if="showReconcileLoadingModal"
          data-testid="modal-reconcile-loading"
          style="position: fixed; inset: 0; background-color: color-mix(in srgb, var(--color-slate) 45%, transparent); display: flex; align-items: flex-end; justify-content: center; z-index: 80; padding: 0;"
          @click.self="closeReconcileLoadingModal"
        >
          <div
            style="background-color: var(--color-surface-card); width: 100%; max-width: 480px; max-height: 92vh; overflow-y: auto; border-top-left-radius: var(--radius-card); border-top-right-radius: var(--radius-card); border: 1px solid var(--color-hairline); padding: 16px; box-sizing: border-box;"
          >
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
              <div>
                <h2 style="font-family: var(--font-display); font-size: 18px; font-weight: 500; margin: 0;">📋 Liquidar cierre de furgoneta</h2>
                <p style="font-size: 12px; color: var(--color-ink-muted); margin: 2px 0 0 0;">
                  {{ activeLoadingOrderData?.order_code || 'Orden en ruta' }} · declara el cuadre por lote.
                </p>
              </div>
              <button
                type="button"
                class="vg-btn vg-btn-ghost vg-btn-sm"
                data-testid="btn-close-reconcile-loading"
                @click="closeReconcileLoadingModal"
              >
                Cerrar
              </button>
            </div>

            <div
              v-if="reconcileLoadingError"
              data-testid="restocker-reconcile-error"
              style="font-size: 12px; color: var(--color-error-text); background-color: var(--color-error-bg); border-radius: var(--radius-interactive); padding: 8px 10px; margin-top: 12px;"
            >
              ⚠️ {{ reconcileLoadingError }}
            </div>

            <p style="font-size: 11px; color: var(--color-ink-muted); margin: 10px 0 0 0;">
              Retorno esperado = saldo del lote − abastecidas − mermas. Si el conteo físico no coincide, explica el descuadre.
            </p>
            <p
              data-testid="restocker-c4-prefill"
              style="font-size: 11px; color: var(--color-info-text); background-color: var(--color-info-bg); border-radius: var(--radius-interactive); padding: 7px 9px; margin: 8px 0 0 0;"
            >
              📋 Abastecidas y mermas vienen precargadas desde tus visitas in situ (C4), sin costes.
            </p>

            <ul style="margin: 12px 0 0 0; padding: 0; list-style: none; display: grid; gap: 10px;">
              <li
                v-for="item in reconcileLoadingForm.items"
                :key="item.product_id"
                data-testid="restocker-reconcile-line"
                style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px;"
              >
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                  <div style="min-width: 0;">
                    <strong style="display: block; font-size: 12px; font-family: var(--font-display); font-weight: 500;">{{ item.product_sku }}</strong>
                    <span style="display: block; font-size: 11px; color: var(--color-ink-muted);">{{ item.product_name }} · Lote {{ item.batch_code }}</span>
                  </div>
                  <span style="font-size: 10px; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-surface-1); color: var(--color-slate); white-space: nowrap;">
                    Saldo {{ item.van_stock_before_reconcile }}
                  </span>
                </div>

                <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-top: 9px;">
                  <label style="display: block; font-size: 10px; color: var(--color-ink-muted);">
                    Abastecidas
                    <input v-model.number="item.restocked_in_machines_qty" type="number" min="0" max="99999" data-testid="reconcile-restocked" style="width: 100%; box-sizing: border-box; height: 34px; margin-top: 3px; padding: 0 6px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);" />
                  </label>
                  <label style="display: block; font-size: 10px; color: var(--color-ink-muted);">
                    Mermas
                    <input v-model.number="item.withdrawn_spoilage_qty" type="number" min="0" max="99999" data-testid="reconcile-spoilage" style="width: 100%; box-sizing: border-box; height: 34px; margin-top: 3px; padding: 0 6px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);" />
                  </label>
                  <label style="display: block; font-size: 10px; color: var(--color-ink-muted);">
                    Retorno contado
                    <input v-model.number="item.counted_return_qty" type="number" min="0" max="99999" data-testid="reconcile-counted" style="width: 100%; box-sizing: border-box; height: 34px; margin-top: 3px; padding: 0 6px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);" />
                  </label>
                </div>

                <div style="display: flex; justify-content: space-between; gap: 8px; font-size: 11px; margin-top: 8px;">
                  <span style="color: var(--color-ink-muted);">Esperado: {{ reconcileLoadingExpected(item) }} uds.</span>
                  <strong :style="{ color: reconcileLoadingDiscrepancy(item) === 0 ? 'var(--color-success-text)' : 'var(--color-error-text)' }">
                    Descuadre: {{ reconcileLoadingDiscrepancy(item) }}
                  </strong>
                </div>
              </li>
            </ul>

            <label style="display: block; font-size: 11px; font-weight: 600; color: var(--color-slate); margin-top: 12px;">
              Justificación del descuadre {{ reconcileLoadingHasDiscrepancy ? '(obligatoria, ≥ 20 caracteres)' : '(opcional)' }}
            </label>
            <textarea
              v-model="reconcileLoadingForm.notes"
              rows="3"
              maxlength="500"
              data-testid="reconcile-loading-notes"
              style="width: 100%; box-sizing: border-box; margin-top: 4px; padding: 7px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 12px;"
            ></textarea>
            <div style="font-size: 10px; color: var(--color-ink-muted); margin-top: 3px;">
              {{ realLength(reconcileLoadingForm.notes) }} caracteres reales.
            </div>

            <div style="display: flex; gap: 8px; margin-top: 14px;">
              <button
                type="button"
                class="vg-btn vg-btn-lg vg-btn-secondary flex-grow-1"
                @click="closeReconcileLoadingModal"
              >
                Cancelar
              </button>
              <button
                type="button"
                class="vg-btn vg-btn-lg vg-btn-primary flex-grow-1"
                data-testid="btn-submit-reconcile-loading"
                :disabled="isReconcilingLoading"
                @click="submitLoadingReconciliation"
              >
                {{ isReconcilingLoading ? 'Liquidando…' : 'Liquidar cierre' }}
              </button>
            </div>
          </div>
        </div>

        <!-- Modal de solo lectura «⏱️ Mis Horas» (RF-04.3, Art. V.4) -->
        <div
          v-if="showMyHoursModal"
          data-testid="modal-my-hours"
          style="position: fixed; inset: 0; background-color: color-mix(in srgb, var(--color-slate) 45%, transparent); display: flex; align-items: flex-end; justify-content: center; z-index: 60; padding: 0;"
          @click.self="closeMyHours"
        >
          <div
            style="background-color: var(--color-surface-card); width: 100%; max-width: 480px; max-height: 85vh; overflow-y: auto; border-top-left-radius: var(--radius-card); border-top-right-radius: var(--radius-card); border: 1px solid var(--color-hairline); padding: 16px; box-sizing: border-box;"
          >
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
              <div>
                <h2 style="font-family: var(--font-display); font-size: 18px; margin: 0;">⏱️ Mis Horas</h2>
                <p style="font-size: 12px; color: var(--color-ink-muted); margin: 2px 0 0 0;">{{ myHoursMonthLabel }}</p>
              </div>
              <button
                type="button"
                class="vg-btn vg-btn-ghost vg-btn-sm"
                data-testid="btn-close-my-hours"
                @click="closeMyHours"
              >
                Cerrar
              </button>
            </div>

            <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-top: 12px;">
              <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px;">
                <span style="display: block; font-size: 10px; color: var(--color-ink-muted); text-transform: uppercase;">Brutas</span>
                <strong style="font-family: var(--font-display); font-size: 15px;">{{ myHoursSummary.gross_formatted || '00h 00m' }}</strong>
              </div>
              <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px;">
                <span style="display: block; font-size: 10px; color: var(--color-ink-muted); text-transform: uppercase;">Comida</span>
                <strong style="font-family: var(--font-display); font-size: 15px;">{{ myHoursSummary.meal_break_formatted || '00h 00m' }}</strong>
              </div>
              <div style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px;">
                <span style="display: block; font-size: 10px; color: var(--color-ink-muted); text-transform: uppercase;">Efectivas</span>
                <strong style="font-family: var(--font-display); font-size: 15px; color: var(--color-primary);">{{ myHoursSummary.effective_formatted || '00h 00m' }}</strong>
              </div>
            </div>

            <p v-if="myHoursLoading" style="font-size: 13px; color: var(--color-ink-muted); margin-top: 12px;">Cargando tus horas…</p>
            <p v-else-if="myHoursError" style="font-size: 13px; color: var(--color-urgency-critical); margin-top: 12px;">{{ myHoursError }}</p>
            <p v-else-if="myHoursIsEmpty" style="font-size: 13px; color: var(--color-ink-muted); margin-top: 12px;">
              Todavía no hay sesiones cerradas este mes.
            </p>

            <ul v-else style="margin: 12px 0 0 0; padding: 0; list-style: none; display: grid; gap: 8px;">
              <li
                v-for="session in myHoursSessions"
                :key="session.id"
                style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 8px 10px;"
              >
                <div style="display: flex; justify-content: space-between; gap: 8px; font-size: 12px;">
                  <strong>{{ formatShiftDate(session.shift_date) }}</strong>
                  <span style="font-family: var(--font-display);">{{ session.effective_formatted }}</span>
                </div>
                <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px;">
                  <span v-if="session.is_force_closed" style="font-size: 10px; padding: 2px 6px; border-radius: var(--radius-interactive); background: var(--color-warning-bg); color: var(--color-warning);">Cierre forzado</span>
                  <span v-if="session.is_adjusted" style="font-size: 10px; padding: 2px 6px; border-radius: var(--radius-interactive); background: var(--color-info-bg); color: var(--color-info);">Ajustado por Coordinación</span>
                  <span v-if="hasShiftOriginalMarks(session)" style="font-size: 10px; color: var(--color-ink-muted);">Marcas originales del primer fichaje</span>
                </div>
              </li>
            </ul>
          </div>
        </div>
      </template>

      <!-- MODAL L1: resolución in situ con taxonomía y textos mínimos (RF-03.2) -->
      <div
        v-if="showL1ResolveModal"
        data-testid="restocker-l1-resolve-modal"
        style="position: fixed; inset: 0; background-color: color-mix(in srgb, var(--color-slate) 45%, transparent); display: flex; align-items: flex-end; justify-content: center; z-index: 85; padding: 0;"
      >
        <div style="background-color: var(--color-surface-card); width: 100%; max-height: 92vh; overflow-y: auto; border-radius: var(--radius-card) var(--radius-card) 0 0; padding: 18px 16px; padding-bottom: calc(18px + env(safe-area-inset-bottom, 0px));">
          <h2 style="font-family: var(--font-display); font-size: 17px; margin: 0 0 4px 0;">✓ Resolver Nivel 1</h2>
          <p style="font-size: 12px; color: var(--color-ink-muted); margin: 0 0 12px 0;">#{{ l1ResolveIncident?.ticket_code }} · Diagnóstico y acción de al menos 20 caracteres.</p>
          <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Causa raíz</label>
          <select v-model="l1ResolveForm.root_cause_id" class="vg-select" data-testid="l1-root-cause" @change="onL1RootCauseChange">
            <option v-for="cause in l1TaxonomyCatalog" :key="cause.id" :value="cause.id">{{ cause.name }}</option>
          </select>
          <label style="display: block; font-size: 12px; font-weight: 600; margin: 10px 0 4px 0;">Solución estandarizada</label>
          <select v-model="l1ResolveForm.standard_solution_id" class="vg-select" data-testid="l1-standard-solution">
            <option v-for="solution in l1ResolveSolutions" :key="solution.id" :value="solution.id">{{ solution.name }}</option>
          </select>
          <label style="display: block; font-size: 12px; font-weight: 600; margin: 10px 0 4px 0;">Diagnóstico ({{ charCount(l1ResolveForm.diagnosis) }}/20)</label>
          <textarea v-model="l1ResolveForm.diagnosis" class="vg-textarea" rows="3" data-testid="l1-diagnosis" placeholder="Qué has encontrado al abrir la máquina…"></textarea>
          <label style="display: block; font-size: 12px; font-weight: 600; margin: 10px 0 4px 0;">Acción ejecutada ({{ charCount(l1ResolveForm.action) }}/20)</label>
          <textarea v-model="l1ResolveForm.action" class="vg-textarea" rows="3" data-testid="l1-action" placeholder="Qué has reparado o repuesto in situ…"></textarea>
          <p v-if="l1ResolveError" role="alert" style="font-size: 12px; color: var(--color-error-text); background-color: var(--color-error-bg); padding: 8px; border-radius: var(--radius-interactive);">⚠️ {{ l1ResolveError }}</p>
          <div style="display: flex; gap: 8px; margin-top: 14px;">
            <button type="button" class="vg-btn vg-btn-secondary" :disabled="isResolvingL1" @click="showL1ResolveModal = false">Cancelar</button>
            <button type="button" class="vg-btn vg-btn-primary" data-testid="btn-submit-l1-resolution" :disabled="isResolvingL1" @click="submitL1Resolution">Confirmar resolución</button>
          </div>
        </div>
      </div>

      <!-- MODAL ESCALADO: nota interna y foto obligatorias (RF-04.1, Art. V.1/V.5) -->
      <div
        v-if="showEscalateModal"
        data-testid="restocker-escalate-modal"
        style="position: fixed; inset: 0; background-color: color-mix(in srgb, var(--color-slate) 45%, transparent); display: flex; align-items: flex-end; justify-content: center; z-index: 86; padding: 0;"
      >
        <div style="background-color: var(--color-surface-card); width: 100%; max-height: 92vh; overflow-y: auto; border-radius: var(--radius-card) var(--radius-card) 0 0; padding: 18px 16px; padding-bottom: calc(18px + env(safe-area-inset-bottom, 0px));">
          <h2 style="font-family: var(--font-display); font-size: 17px; margin: 0 0 4px 0;">⚡ Escalar Avería al SAT</h2>
          <p style="font-size: 12px; color: var(--color-ink-muted); margin: 0 0 12px 0;">{{ escalateMachine?.code || 'Máquina' }} · La nota y la foto viajan al SAT como nota interna.</p>
          <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Categoría técnica</label>
          <select v-model="escalateForm.category" class="vg-select" data-testid="escalate-category">
            <option value="ELECTRICAL_OFF">Máquina apagada / Sin corriente</option>
            <option value="TEMPERATURE_COLD">Temperatura / Pérdida de frío</option>
            <option value="PAYMENT_SYSTEM">Sistema de pago / Monedero</option>
            <option value="PRODUCT_JAM">Atasco de producto en espiral</option>
            <option value="OTHER">Otras averías</option>
          </select>
          <label style="display: block; font-size: 12px; font-weight: 600; margin: 10px 0 4px 0;">Nota Interna de Taller ({{ charCount(escalateForm.workshop_internal_note) }}/20)</label>
          <textarea v-model="escalateForm.workshop_internal_note" class="vg-textarea" rows="3" data-testid="escalate-internal-note" placeholder="Describe el fallo electromecánico observado…"></textarea>
          <label style="display: block; font-size: 12px; font-weight: 600; margin: 10px 0 4px 0;">Fotografía de evidencia (máx. 5 MB)</label>
          <input type="file" accept="image/jpeg,image/png,image/webp" class="vg-input" data-testid="escalate-photo" @change="onEscalatePhotoChange" />
          <p v-if="escalatePhotoName" style="font-size: 11px; color: var(--color-ink-muted); margin: 4px 0 0 0;">📷 {{ escalatePhotoName }}</p>
          <p v-if="escalateError" role="alert" style="font-size: 12px; color: var(--color-error-text); background-color: var(--color-error-bg); padding: 8px; border-radius: var(--radius-interactive); margin-top: 10px;">⚠️ {{ escalateError }}</p>
          <div style="display: flex; gap: 8px; margin-top: 14px;">
            <button type="button" class="vg-btn vg-btn-secondary" :disabled="isEscalating" @click="showEscalateModal = false">Cancelar</button>
            <button type="button" class="vg-btn vg-btn-primary" data-testid="btn-submit-escalation" :disabled="isEscalating" @click="submitEscalation">Escalar al SAT</button>
          </div>
        </div>
      </div>
    </div>
  `
};

export default RestockerRouteView;
