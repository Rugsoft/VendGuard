/**
 * VendGuard - CoordinatorProductLoadingTab (CoordinatorProductLoadingTab.js)
 *
 * Pestaña de Coordinación para las órdenes de carga de producto de las
 * furgonetas de reposición, el inventario por lote y las visitas in situ
 * (Módulos 33, 34 y 35, RF-03.2, RF-04.1, RF-04.2, RF-05.1–RF-05.3, RNF-03,
 * D1-D4, QA 1, QA 3, QA 4, Art. V.1, Art. V.4). Cuatro sub-vistas:
 *
 *   1. «🚚 Órdenes de Carga de Producto y Cámara Frigorífica»: KPIs, propuesta
 *      individual con modo de demanda y colchón, emisión masiva, edición en
 *      preparación, despacho con temperatura de cámara, liquidación de cierre,
 *      cancelación justificada y Hoja de Picking A4 en dos secciones.
 *   2. «🚐 Stock de Producto en Furgonetas (Por Lote)»: filtros por reponedor,
 *      categoría y semáforo FEFO, y ajuste manual auditado.
 *   3. «📋 Visitas de Reposición In Situ y Control FEFO»: cinco KPIs ejecutivos,
 *      filtros por reponedor, estado, máquina y rango de fechas, libro histórico
 *      y expediente carril a carril con checklist, foto y ticket SAT (`RF-05.2`).
 *   4. «🪙 Recaudación, Cambio y Arqueo de Caja»: seis KPIs reactivos al filtro,
 *      recepción en cámara, arqueo ciego con descuadre en vivo, recibo opaco y
 *      exportación CSV (`RF-04.1`, `RF-04.2`, `RF-05.3`, `QA 3`, `QA 4`).
 *
 * La vista no decide ninguna regla sanitaria: el cerrojo térmico, la
 * obligatoriedad de lote/caducidad, el cuadre y la justificación mínima viven
 * en el backend. Aquí solo se anticipan las comprobaciones obvias para evitar
 * un viaje inútil, y se presenta el error canónico que devuelve el servidor.
 *
 * La Hoja A4 no transporta ningún importe: sus tablas solo proyectan las
 * columnas de picking. Todo el color entra por tokens `vg-*`; no hay literales
 * hexadecimales en este módulo (RNF-03).
 *
 * Dogma Vanilla: Vue 3 Options API vía ES Modules nativos, cero dependencias.
 * Dualismo Lingüístico: código en inglés, textos de interfaz en castellano.
 */

import { api } from '../api.js';
import { formatDateTime } from '../utils/DateTimeFormat.js';
import { ModalDialog } from './ModalDialog.js';
import { CoordinatorRestockRoutePlannerSubTab } from './CoordinatorRestockRoutePlannerSubTab.js';
import { CoordinatorDexAuditsSubTab } from './CoordinatorDexAuditsSubTab.js';

const unwrap = (response) => (response && response.data !== undefined ? response.data : response);

const emptyKpis = () => ({
  pending_picking_count: 0,
  dispatched_count: 0,
  confirmed_in_route_count: 0,
  reconciled_today_count: 0
});

const emptyStockKpis = () => ({
  total_lots: 0,
  total_units: 0,
  usable_units: 0,
  expired_lots: 0,
  expiring_soon_lots: 0
});

const emptyVisitKpis = () => ({
  completed_visits_count: 0,
  aborted_thermal_visits_count: 0,
  total_units_added: 0,
  total_units_withdrawn: 0,
  total_commercial_spoilage_loss_eur: 0
});

const emptyCashKpis = () => ({
  pending_vault_bags_count: 0,
  reconciled_ok_count: 0,
  discrepancy_flagged_count: 0,
  total_vault_counted_eur: 0,
  net_discrepancy_eur: 0,
  total_route_cash_deployed_eur: 0
});

const EUR_FORMATTER = new Intl.NumberFormat('es-ES', {
  style: 'currency',
  currency: 'EUR',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
});

const HYGIENE_LABELS = {
  coffee_grounds_emptied: 'Cajón de posos vaciado',
  liquid_waste_bucket_cleaned: 'Cubo de residuos líquidos limpio',
  mixers_and_ducts_rinsed: 'Batidores y conductos enjuagados',
  dispensing_tray_and_glass_cleaned: 'Bandeja de erogación y cristal limpios',
  cabin_spills_checked: 'Derrames de cabina revisados'
};

const blankStockForm = () => ({
  restocker_id: '',
  product_id: '',
  batch_code: '',
  expiration_date: '',
  quantity_available: 0,
  reason: ''
});

export const CoordinatorProductLoadingTab = {
  name: 'CoordinatorProductLoadingTab',
  components: { ModalDialog, CoordinatorRestockRoutePlannerSubTab, CoordinatorDexAuditsSubTab },
  emits: ['open-incident-detail'],
  data() {
    return {
      activeSection: 'orders',

      restockers: [],
      isLoadingRestockers: false,

      orders: [],
      orderKpis: emptyKpis(),
      orderFilters: { status: '', restocker_id: '' },
      isLoadingOrders: false,
      orderError: '',
      orderSuccess: '',

      showProposalModal: false,
      proposalForm: { restocker_id: '', demand_mode: 'ALERTS_ONLY', safety_buffer_pct: 10, service_date: '' },
      proposal: null,
      proposalError: '',
      isLoadingProposal: false,
      isCreatingOrder: false,

      showBatchModal: false,
      batchForm: { demand_mode: 'ALERTS_ONLY', safety_buffer_pct: 10, service_date: '' },
      batchResult: null,
      batchError: '',
      isGeneratingBatch: false,

      showDetailModal: false,
      selectedOrderId: null,
      orderDetail: null,
      isLoadingDetail: false,
      detailError: '',

      showEditModal: false,
      editForm: { notes: '', demand_mode: 'ALERTS_ONLY', safety_buffer_pct: 10 },
      editItems: [],
      editError: '',
      isSavingOrder: false,

      showDispatchModal: false,
      dispatchForm: { cold_chamber_exit_temp_celsius: '', items: [] },
      dispatchError: '',
      dispatchSuccess: '',
      isDispatching: false,

      showReconcileModal: false,
      reconcileForm: { notes: '', items: [] },
      reconcileError: '',
      reconcileSuccess: '',
      isReconciling: false,

      showCancelModal: false,
      cancelOrderId: null,
      cancelOrderCode: '',
      cancelReason: '',
      cancelError: '',
      isCancelling: false,

      showPickingModal: false,
      pickingSheet: null,
      pickingError: '',
      isLoadingPicking: false,

      vanStocks: [],
      orderVanStocks: [],
      stockKpis: emptyStockKpis(),
      stockFilters: { restocker_id: '', category: '', expiry_status: '', search: '' },
      isLoadingStock: false,
      stockError: '',
      stockSuccess: '',

      products: [],
      isLoadingProducts: false,
      showStockAdjustModal: false,
      stockForm: blankStockForm(),
      stockFormError: '',
      isAdjustingStock: false,

      visits: [],
      visitKpis: emptyVisitKpis(),
      visitFilters: { restocker_id: '', machine_id: '', status: '', date_from: '', date_to: '' },
      isLoadingVisits: false,
      visitError: '',
      showVisitDetailModal: false,
      selectedVisitId: null,
      visitDetail: null,
      isLoadingVisitDetail: false,
      visitDetailError: '',

      cashCollections: [],
      cashKpis: emptyCashKpis(),
      cashFilters: {
        restocker_id: '',
        machine_id: '',
        status: '',
        bag_seal_code: '',
        date_from: '',
        date_to: ''
      },
      isLoadingCash: false,
      cashError: '',
      cashSuccess: '',

      showCashDetailModal: false,
      selectedCashId: null,
      cashDetail: null,
      isLoadingCashDetail: false,
      cashDetailError: '',
      cashReconcileForm: { vault_counted_eur: '', discrepancy_justification: '' },
      cashReconcileError: '',
      isReconcilingCash: false,

      showCashReceiptModal: false,
      cashReceipt: null,
      isLoadingCashReceipt: false,
      cashReceiptError: ''
    };
  },
  computed: {
    statusOptions() {
      return [
        { value: 'PENDING_PICKING', label: 'En preparación (Picking)' },
        { value: 'DISPATCHED', label: 'Despachada' },
        { value: 'CONFIRMED_BY_RESTOCKER', label: 'Recibida en Furgoneta' },
        { value: 'RECONCILED_AT_CLOSE', label: 'Liquidada al Cierre' },
        { value: 'CANCELLED', label: 'Cancelada' }
      ];
    },
    restockerOptions() {
      return this.restockers.map((restocker) => ({
        id: restocker.id,
        label: restocker.operator_code
          ? `${restocker.name} · ${restocker.operator_code}`
          : restocker.name
      }));
    },
    selectedOrder() {
      return this.orderDetail ? this.orderDetail.order : null;
    },
    selectedOrderItems() {
      return this.orderDetail?.items || [];
    },
    proposalItems() {
      return this.proposal?.items || [];
    },
    proposalTotalSuggested() {
      return this.proposalItems.reduce((total, item) => total + Number(item.suggested_qty || 0), 0);
    },
    /** Alerta C2 del destinatario de la propuesta individual. */
    proposalRecipientWarning() {
      const restocker = this.proposal?.restocker;
      if (!restocker) return '';
      if (restocker.is_active === false) return '⚠️ Destinatario inactivo';
      if (restocker.is_currently_absent === true) return '⚠️ Destinatario actualmente ausente';
      return '';
    },
    dispatchHasColdLine() {
      return this.dispatchForm.items.some(
        (item) => item.requires_cold_chain && Number(item.dispatched_qty || 0) > 0
      );
    },
    dispatchTotalDispatched() {
      return this.dispatchForm.items.reduce((total, item) => total + Number(item.dispatched_qty || 0), 0);
    },
    reconcileHasDiscrepancy() {
      return this.reconcileForm.items.some((item) => this.reconcileDiscrepancy(item) !== 0);
    },
    filteredVanStocks() {
      const search = this.stockFilters.search.trim().toLowerCase();
      return this.vanStocks.filter((stock) => {
        if (this.stockFilters.restocker_id && String(stock.restocker_id) !== String(this.stockFilters.restocker_id)) {
          return false;
        }
        if (this.stockFilters.category && stock.product_category !== this.stockFilters.category) {
          return false;
        }
        if (this.stockFilters.expiry_status && stock.expiry_status !== this.stockFilters.expiry_status) {
          return false;
        }
        if (search) {
          const haystack = `${stock.product_sku || ''} ${stock.product_name || ''} ${stock.batch_code || ''}`.toLowerCase();
          if (!haystack.includes(search)) {
            return false;
          }
        }
        return true;
      });
    },
    visitStatusOptions() {
      return [
        { value: 'COMPLETED', label: 'Completada' },
        { value: 'ABORTED_THERMAL_BREACH', label: 'Abortada por temperatura' }
      ];
    },
    visitDetailVisit() {
      return this.visitDetail?.visit || this.visitDetail || null;
    },
    visitItems() {
      return this.visitDetail?.items || [];
    },
    cashStatusOptions() {
      return [
        { value: 'ALL', label: 'Todos los estados' },
        { value: 'SEALED_IN_ROUTE', label: 'Sellada en ruta' },
        { value: 'DELIVERED_TO_VAULT', label: 'En cámara' },
        { value: 'RECONCILED_OK', label: 'Arqueada OK' },
        { value: 'DISCREPANCY_FLAGGED', label: 'Con descuadre' }
      ];
    },
    cashDetailCollection() {
      if (this.cashDetail) {
        return this.cashDetail.collection || this.cashDetail;
      }
      return this.cashCollections.find(
        (collection) => String(collection.id) === String(this.selectedCashId)
      ) || null;
    },
    cashDetailIsTerminal() {
      const collection = this.cashDetailCollection;
      return Boolean(collection)
        && (collection.is_terminal === true
          || collection.status === 'RECONCILED_OK'
          || collection.status === 'DISCREPANCY_FLAGGED');
    },
    cashDetailCanDeliver() {
      return this.cashDetailCollection?.status === 'SEALED_IN_ROUTE';
    },
    cashDetailCanReconcile() {
      const status = this.cashDetailCollection?.status;
      return status === 'SEALED_IN_ROUTE' || status === 'DELIVERED_TO_VAULT';
    },
    cashLiveDiscrepancy() {
      const raw = this.cashReconcileForm.vault_counted_eur;
      if (raw === '' || raw === null || raw === undefined) return null;
      const counted = Number(raw);
      const expected = Number(this.cashDetailCollection?.expected_cashbox_eur);
      if (!Number.isFinite(counted) || !Number.isFinite(expected)) return null;
      return Math.round((counted - expected) * 100) / 100;
    },
    cashRequiresJustification() {
      return this.cashLiveDiscrepancy !== null && Math.abs(this.cashLiveDiscrepancy) > 1;
    },
    visitHygieneItems() {
      const checklist = this.visitDetailVisit?.hygiene_checklist;
      if (!checklist || typeof checklist !== 'object') return [];
      return Object.entries(checklist).map(([key, value]) => ({
        key,
        label: HYGIENE_LABELS[key] || key,
        value
      }));
    }
  },
  mounted() {
    this.loadOrders();
    this.loadRestockers();
    this.loadVanStocks();
    this.loadVisits();
    this.loadCashCollections();
  },
  methods: {
    unwrap,

    formatDateTime,

    realLength(value) {
      return String(value ?? '').trim().length;
    },

    /** Aviso de operario ausente/inactivo sin cancelar nada en silencio (C2). */
    restockerWarning(order) {
      if (!order) return '';
      if (order.restocker_is_active === false) return '⚠️ Destinatario inactivo';
      if (order.restocker_currently_absent === true) return '⚠️ Destinatario actualmente ausente';
      return '';
    },

    statusTone(status) {
      switch (status) {
        case 'PENDING_PICKING':
          return 'vg-badge vg-badge-warning';
        case 'DISPATCHED':
          return 'vg-badge vg-badge-info';
        case 'CONFIRMED_BY_RESTOCKER':
          return 'vg-badge vg-badge-success';
        case 'RECONCILED_AT_CLOSE':
          return 'vg-badge vg-badge-neutral';
        default:
          return 'vg-badge vg-badge-danger';
      }
    },

    expiryTone(status) {
      switch (status) {
        case 'EXPIRED':
          return 'vg-badge vg-badge-danger';
        case 'EXPIRING_SOON':
          return 'vg-badge vg-badge-warning';
        default:
          return 'vg-badge vg-badge-success';
      }
    },

    orderActions(order) {
      return {
        edit: order.status === 'PENDING_PICKING',
        dispatch: order.status === 'PENDING_PICKING',
        reconcile: order.status === 'CONFIRMED_BY_RESTOCKER',
        cancel: order.status === 'PENDING_PICKING' || order.status === 'DISPATCHED'
      };
    },

    async loadRestockers() {
      this.isLoadingRestockers = true;
      try {
        const rows = unwrap(await api.coordinator.getUsers({ role: 'RESTOCKER', status: 'active' }));
        this.restockers = Array.isArray(rows) ? rows : [];
        if (!this.proposalForm.restocker_id && this.restockers[0]) {
          this.proposalForm.restocker_id = this.restockers[0].id;
        }
        if (!this.stockForm.restocker_id && this.restockers[0]) {
          this.stockForm.restocker_id = this.restockers[0].id;
        }
      } catch (error) {
        this.restockers = [];
      } finally {
        this.isLoadingRestockers = false;
      }
    },

    async loadOrders() {
      this.isLoadingOrders = true;
      this.orderError = '';
      try {
        const data = unwrap(await api.coordinator.getProductLoadingOrders(this.orderFilters));
        this.orderKpis = { ...emptyKpis(), ...(data?.kpis || {}) };
        this.orders = Array.isArray(data?.orders) ? data.orders : [];
      } catch (error) {
        this.orderError = error?.message || 'No se pudieron cargar las órdenes de carga.';
      } finally {
        this.isLoadingOrders = false;
      }
    },

    async loadVanStocks() {
      this.isLoadingStock = true;
      this.stockError = '';
      try {
        const data = unwrap(await api.coordinator.getRestockerVanStocks(this.stockFilters));
        this.stockKpis = { ...emptyStockKpis(), ...(data?.kpis || {}) };
        this.vanStocks = Array.isArray(data?.stocks) ? data.stocks : [];
      } catch (error) {
        this.stockError = error?.message || 'No se pudo cargar el stock por lote.';
      } finally {
        this.isLoadingStock = false;
      }
    },

    formatCurrency(value) {
      const amount = Number(value);
      return EUR_FORMATTER.format(Number.isFinite(amount) ? amount : 0);
    },

    visitStatusTone(status) {
      switch (status) {
        case 'COMPLETED':
          return 'vg-badge vg-badge-success';
        case 'ABORTED_THERMAL_BREACH':
          return 'vg-badge vg-badge-danger';
        default:
          return 'vg-badge vg-badge-neutral';
      }
    },

    visitTicketHref(visit) {
      const incidentId = visit?.triggered_incident_id;
      return incidentId === null || incidentId === undefined ? '' : `#incident-${incidentId}`;
    },

    openVisitTicket(visit) {
      const incidentId = visit?.triggered_incident_id;
      if (incidentId === null || incidentId === undefined || incidentId === '') return;
      this.$emit('open-incident-detail', Number(incidentId));
    },

    visitHygieneValue(value) {
      if (value === true) return 'Sí';
      if (value === false) return 'No';
      return 'No aplica';
    },

    visitFilterParams() {
      const params = {};
      Object.entries(this.visitFilters).forEach(([key, value]) => {
        if (value === '' || value === null || value === undefined) return;
        params[key] = value;
      });
      return params;
    },

    async loadVisits() {
      this.isLoadingVisits = true;
      this.visitError = '';
      try {
        const data = unwrap(await api.coordinator.getRestockVisits(this.visitFilterParams()));
        this.visitKpis = { ...emptyVisitKpis(), ...(data?.kpis || {}) };
        this.visits = Array.isArray(data?.visits) ? data.visits : [];
      } catch (error) {
        this.visitError = error?.message || 'No se pudo cargar el libro de visitas de reposición in situ.';
      } finally {
        this.isLoadingVisits = false;
      }
    },

    cashStatusTone(status) {
      switch (status) {
        case 'SEALED_IN_ROUTE':
          return 'vg-badge vg-badge-warning';
        case 'DELIVERED_TO_VAULT':
          return 'vg-badge vg-badge-info';
        case 'RECONCILED_OK':
          return 'vg-badge vg-badge-success';
        case 'DISCREPANCY_FLAGGED':
          return 'vg-badge vg-badge-danger';
        default:
          return 'vg-badge vg-badge-neutral';
      }
    },

    cashFilterParams() {
      const params = {};
      Object.entries(this.cashFilters).forEach(([key, value]) => {
        if (value === '' || value === null || value === undefined) return;
        if (key === 'status' && value === 'ALL') return;
        params[key] = key === 'bag_seal_code' ? String(value).trim().toUpperCase() : value;
      });
      return params;
    },

    async loadCashCollections() {
      this.isLoadingCash = true;
      this.cashError = '';
      try {
        const data = unwrap(await api.coordinator.getCashCollections(this.cashFilterParams()));
        this.cashKpis = { ...emptyCashKpis(), ...(data?.kpis || {}) };
        this.cashCollections = Array.isArray(data?.cash_collections) ? data.cash_collections : [];
      } catch (error) {
        this.cashError = error?.message || 'No se pudo cargar el libro de recaudaciones.';
      } finally {
        this.isLoadingCash = false;
      }
    },

    async exportCashCollectionsCsv() {
      this.cashError = '';
      this.cashSuccess = '';
      try {
        await api.coordinator.exportCashCollectionsCsv(this.cashFilterParams());
        this.cashSuccess = 'Exportación CSV generada con los filtros activos.';
      } catch (error) {
        this.cashError = error?.message || 'No se pudo exportar el libro de recaudaciones.';
      }
    },

    async deliverCashCollection(collection) {
      const collectionId = Number(collection?.id ?? 0);
      if (collectionId <= 0) return;
      this.cashError = '';
      this.cashSuccess = '';
      try {
        const updated = unwrap(await api.coordinator.deliverCashCollectionToVault(collectionId));
        if (this.cashDetail && Number(this.selectedCashId) === collectionId) {
          this.cashDetail = updated;
        }
        this.cashSuccess = `Bolsa ${collection.collection_code || collectionId} recibida en Caja Central.`;
        await this.loadCashCollections();
      } catch (error) {
        this.cashError = error?.message || 'No se pudo recepcionar la bolsa en cámara.';
      }
    },

    async openCashDetailModal(collection) {
      const collectionId = Number(collection?.id ?? 0);
      if (collectionId <= 0) return;
      this.selectedCashId = collectionId;
      this.cashDetail = collection;
      this.cashDetailError = '';
      this.cashReconcileError = '';
      this.cashReconcileForm = { vault_counted_eur: '', discrepancy_justification: '' };
      this.showCashDetailModal = true;
      this.isLoadingCashDetail = true;
      try {
        const detail = unwrap(await api.coordinator.getCashCollection(collectionId));
        this.cashDetail = detail || collection;
        const current = this.cashDetail.collection || this.cashDetail;
        this.cashReconcileForm = {
          vault_counted_eur: current.vault_counted_eur === null || current.vault_counted_eur === undefined
            ? ''
            : current.vault_counted_eur,
          discrepancy_justification: current.discrepancy_justification || ''
        };
      } catch (error) {
        this.cashDetailError = error?.message || 'No se pudo cargar el expediente de la recaudación.';
      } finally {
        this.isLoadingCashDetail = false;
      }
    },

    async openCashReceiptModal(collection) {
      const collectionId = Number(collection?.id ?? 0);
      if (collectionId <= 0) return;
      this.showCashDetailModal = false;
      this.showCashReceiptModal = true;
      this.cashReceipt = null;
      this.cashReceiptError = '';
      this.isLoadingCashReceipt = true;
      try {
        this.cashReceipt = unwrap(await api.coordinator.getCashCollectionReceptionReceipt(collectionId)) || null;
      } catch (error) {
        this.cashReceiptError = error?.message || 'No se pudo cargar el recibo de conserjería.';
      } finally {
        this.isLoadingCashReceipt = false;
      }
    },

    openCashVisit(collection) {
      const visitId = Number(collection?.restock_visit_id ?? 0);
      if (visitId <= 0) return;
      this.showCashDetailModal = false;
      this.openVisitDetailModal(visitId);
    },

    validateCashReconciliation() {
      const raw = String(this.cashReconcileForm.vault_counted_eur ?? '').trim();
      const counted = Number(raw);
      if (raw === '' || !Number.isFinite(counted) || counted < 0 || counted > 10000) {
        this.cashReconcileError = 'El importe contado debe estar entre 0,00 € y 10.000,00 €.';
        return false;
      }

      const expected = Number(this.cashDetailCollection?.expected_cashbox_eur);
      if (!Number.isFinite(expected)) {
        this.cashReconcileError = 'No se pudo calcular el esperado del arqueo ciego.';
        return false;
      }

      const discrepancy = Math.round((counted - expected) * 100) / 100;
      if (Math.abs(discrepancy) > 1) {
        const justification = String(this.cashReconcileForm.discrepancy_justification ?? '').trim();
        const blocks = justification.match(/[A-Za-z0-9ÁÉÍÓÚÜÑáéíóúüñ]+/g) || [];
        if (justification.length < 20 || justification.length > 1000 || blocks.length < 3) {
          this.cashReconcileError = 'Un descuadre superior a ±1,00 € exige una justificación real de 20 a 1.000 caracteres y al menos 3 bloques alfanuméricos.';
          return false;
        }
      }

      this.cashReconcileError = '';
      return true;
    },

    async submitCashReconciliation() {
      const collectionId = Number(this.selectedCashId ?? this.cashDetailCollection?.id ?? 0);
      if (collectionId <= 0 || !this.validateCashReconciliation()) return;

      const payload = { vault_counted_eur: Number(this.cashReconcileForm.vault_counted_eur) };
      const justification = String(this.cashReconcileForm.discrepancy_justification ?? '').trim();
      if (justification !== '') payload.discrepancy_justification = justification;

      this.isReconcilingCash = true;
      this.cashReconcileError = '';
      try {
        const updated = unwrap(await api.coordinator.reconcileCashCollection(collectionId, payload));
        this.cashDetail = updated;
        this.showCashDetailModal = false;
        this.cashSuccess = `Arqueo de ${this.cashDetailCollection?.collection_code || collectionId} sellado.`;
        await this.loadCashCollections();
      } catch (error) {
        this.cashReconcileError = error?.message || 'No se pudo sellar el arqueo ciego.';
      } finally {
        this.isReconcilingCash = false;
      }
    },

    async fetchVisitDetail(visitId) {
      this.isLoadingVisitDetail = true;
      this.visitDetailError = '';
      try {
        const data = unwrap(await api.coordinator.getRestockVisit(visitId));
        this.selectedVisitId = Number(visitId);
        this.visitDetail = data || null;
        return this.visitDetail;
      } catch (error) {
        this.visitDetailError = error?.message || 'No se pudo cargar el expediente de la visita.';
        this.visitDetail = null;
        return null;
      } finally {
        this.isLoadingVisitDetail = false;
      }
    },

    async openVisitDetailModal(visitId) {
      this.showVisitDetailModal = true;
      this.visitDetail = null;
      this.visitDetailError = '';
      await this.fetchVisitDetail(visitId);
    },

    async loadProducts() {
      if (this.products.length > 0 || this.isLoadingProducts) return;
      this.isLoadingProducts = true;
      try {
        const data = unwrap(await api.coordinator.getProducts({ status: 'ACTIVE' }));
        const rows = Array.isArray(data) ? data : data?.products;
        this.products = Array.isArray(rows) ? rows : [];
      } catch (error) {
        this.products = [];
      } finally {
        this.isLoadingProducts = false;
      }
    },

    async openProposalModal() {
      this.showProposalModal = true;
      this.proposal = null;
      this.proposalError = '';
      if (this.restockers.length === 0) {
        await this.loadRestockers();
      }
      if (!this.proposalForm.restocker_id && this.restockers[0]) {
        this.proposalForm.restocker_id = this.restockers[0].id;
      }
    },

    async loadProposal() {
      this.proposalError = '';
      if (!this.proposalForm.restocker_id) {
        this.proposalError = 'Selecciona un reponedor activo para calcular la propuesta.';
        return;
      }
      this.isLoadingProposal = true;
      try {
        const params = {
          restocker_id: this.proposalForm.restocker_id,
          demand_mode: this.proposalForm.demand_mode,
          safety_buffer_pct: Number(this.proposalForm.safety_buffer_pct)
        };
        if (this.proposalForm.demand_mode === 'TODAY_ROUTE' && this.proposalForm.service_date) {
          params.service_date = this.proposalForm.service_date;
        }
        const data = unwrap(await api.coordinator.getProductLoadingProposal(params));
        this.proposal = data || null;
      } catch (error) {
        this.proposal = null;
        this.proposalError = error?.message || 'No se pudo calcular la propuesta de carga.';
      } finally {
        this.isLoadingProposal = false;
      }
    },

    async createOrderFromProposal() {
      this.proposalError = '';
      if (!this.proposal) {
        this.proposalError = 'Calcula primero la propuesta.';
        return;
      }
      const items = this.proposalItems
        .filter((item) => Number(item.suggested_qty) > 0)
        .map((item) => ({
          product_id: Number(item.product_id),
          reason: item.reason,
          requested_qty: Number(item.suggested_qty)
        }));
      if (items.length === 0) {
        this.proposalError = 'La propuesta no tiene líneas con cantidad sugerida.';
        return;
      }
      this.isCreatingOrder = true;
      try {
        const payload = {
          restocker_id: Number(this.proposalForm.restocker_id),
          demand_mode: this.proposalForm.demand_mode,
          safety_buffer_pct: Number(this.proposalForm.safety_buffer_pct),
          items
        };
        if (this.proposalForm.demand_mode === 'TODAY_ROUTE' && this.proposalForm.service_date) {
          payload.service_date = this.proposalForm.service_date;
        }
        const created = unwrap(await api.coordinator.createProductLoadingOrder(payload));
        this.showProposalModal = false;
        this.orderSuccess = `Orden ${created?.order?.order_code || ''} emitida en preparación.`.trim();
        await this.loadOrders();
      } catch (error) {
        this.proposalError = error?.message || 'No se pudo emitir la orden de carga.';
      } finally {
        this.isCreatingOrder = false;
      }
    },

    openBatchModal() {
      this.showBatchModal = true;
      this.batchResult = null;
      this.batchError = '';
    },

    async generateBatch() {
      this.batchError = '';
      this.isGeneratingBatch = true;
      try {
        const payload = {
          demand_mode: this.batchForm.demand_mode,
          safety_buffer_pct: Number(this.batchForm.safety_buffer_pct)
        };
        if (this.batchForm.demand_mode === 'TODAY_ROUTE' && this.batchForm.service_date) {
          payload.service_date = this.batchForm.service_date;
        }
        this.batchResult = unwrap(await api.coordinator.generateProductLoadingBatch(payload));
        await this.loadOrders();
      } catch (error) {
        this.batchResult = null;
        this.batchError = error?.message || 'No se pudo completar la emisión masiva.';
      } finally {
        this.isGeneratingBatch = false;
      }
    },

    async fetchOrderDetail(orderId) {
      this.isLoadingDetail = true;
      this.detailError = '';
      try {
        const data = unwrap(await api.coordinator.getProductLoadingOrder(orderId));
        this.selectedOrderId = Number(orderId);
        this.orderDetail = data || null;
        return this.orderDetail;
      } catch (error) {
        this.detailError = error?.message || 'No se pudo cargar el detalle de la orden.';
        this.orderDetail = null;
        return null;
      } finally {
        this.isLoadingDetail = false;
      }
    },

    async openDetailModal(orderId) {
      this.showDetailModal = true;
      await this.fetchOrderDetail(orderId);
    },

    async openEditModal(orderId) {
      const detail = await this.fetchOrderDetail(orderId);
      if (!detail?.order) return;
      this.showEditModal = true;
      this.editError = '';
      this.editForm = {
        notes: detail.order.notes || '',
        demand_mode: detail.order.demand_mode,
        safety_buffer_pct: detail.order.safety_buffer_pct
      };
      this.editItems = (detail.items || [])
        .filter((item) => item.is_active)
        .map((item) => ({
          product_id: item.product_id,
          product_sku: item.product_sku,
          product_name: item.product_name,
          reason: item.reason,
          requires_cold_chain: item.requires_cold_chain,
          requested_qty: item.requested_qty,
          keep: true
        }));
    },

    removeEditItem(index) {
      this.editItems.splice(index, 1);
    },

    async saveOrder() {
      this.editError = '';
      const items = this.editItems
        .filter((item) => item.keep !== false)
        .map((item) => ({
          product_id: Number(item.product_id),
          reason: item.reason,
          requested_qty: Number(item.requested_qty)
        }));
      if (items.length === 0) {
        this.editError = 'La orden debe conservar al menos una línea activa.';
        return;
      }
      this.isSavingOrder = true;
      try {
        const updated = unwrap(await api.coordinator.updateProductLoadingOrder(this.selectedOrderId, {
          demand_mode: this.editForm.demand_mode,
          safety_buffer_pct: Number(this.editForm.safety_buffer_pct),
          notes: this.editForm.notes,
          items
        }));
        this.orderDetail = updated;
        this.showEditModal = false;
        this.orderSuccess = 'Orden de carga editada sin borrado físico.';
        await this.loadOrders();
      } catch (error) {
        this.editError = error?.message || 'No se pudo editar la orden.';
      } finally {
        this.isSavingOrder = false;
      }
    },

    async openDispatchModal(orderId) {
      const detail = await this.fetchOrderDetail(orderId);
      if (!detail?.order) return;
      this.showDispatchModal = true;
      this.dispatchError = '';
      this.dispatchSuccess = '';
      this.dispatchForm = {
        cold_chamber_exit_temp_celsius: detail.order.cold_chamber_exit_temp_celsius ?? '',
        items: (detail.items || [])
          .filter((item) => item.is_active)
          .map((item) => ({
            product_id: item.product_id,
            product_sku: item.product_sku,
            product_name: item.product_name,
            requires_cold_chain: item.requires_cold_chain,
            requested_qty: item.requested_qty,
            dispatched_qty: item.dispatched_qty ?? item.requested_qty,
            batch_code: item.batch_code || '',
            expiration_date: item.expiration_date || ''
          }))
      };
    },

    validateDispatchForm() {
      this.dispatchError = '';
      const total = this.dispatchTotalDispatched;
      if (!Number.isInteger(total) || total < 1) {
        this.dispatchError = 'El despacho debe sumar al menos 1 unidad.';
        return false;
      }
      if (this.dispatchHasColdLine) {
        const raw = this.dispatchForm.cold_chamber_exit_temp_celsius;
        const temperature = Number(raw);
        if (raw === '' || raw === null || Number.isNaN(temperature)) {
          this.dispatchError = 'Declara la temperatura de salida de cámara frigorífica.';
          return false;
        }
        if (temperature > 4 || temperature < -5) {
          this.dispatchError = 'La temperatura de cámara debe estar entre -5,0 °C y 4,0 °C (Art. II).';
          return false;
        }
        const invalidLine = this.dispatchForm.items.find(
          (item) => item.requires_cold_chain
            && Number(item.dispatched_qty) > 0
            && (!/^[A-Z0-9_-]{2,40}$/.test(String(item.batch_code || '').toUpperCase())
              || String(item.batch_code || '').toUpperCase() === 'SIN-LOTE'
              || !item.expiration_date)
        );
        if (invalidLine) {
          this.dispatchError = `El lote y la caducidad son obligatorios en ${invalidLine.product_name}.`;
          return false;
        }
      }
      return true;
    },

    async submitDispatch() {
      if (!this.validateDispatchForm()) return;
      this.isDispatching = true;
      try {
        const updated = unwrap(await api.coordinator.dispatchProductLoadingOrder(this.selectedOrderId, {
          cold_chamber_exit_temp_celsius: this.dispatchForm.cold_chamber_exit_temp_celsius === ''
            ? null
            : Number(this.dispatchForm.cold_chamber_exit_temp_celsius),
          items: this.dispatchForm.items.map((item) => ({
            product_id: Number(item.product_id),
            dispatched_qty: Number(item.dispatched_qty || 0),
            batch_code: item.batch_code === '' ? null : String(item.batch_code).toUpperCase(),
            expiration_date: item.expiration_date === '' ? null : item.expiration_date
          }))
        }));
        this.orderDetail = updated;
        this.showDispatchModal = false;
        this.orderSuccess = 'Orden despachada con cerrojo sanitario de frío.';
        await this.loadOrders();
      } catch (error) {
        this.dispatchError = error?.message || 'No se pudo despachar la orden.';
      } finally {
        this.isDispatching = false;
      }
    },

    currentLotBalance(item) {
      if (!this.selectedOrder) return Number(item.van_stock_before_reconcile || 0);
      const stock = this.orderVanStocks.find(
        (row) => Number(row.product_id) === Number(item.product_id)
          && String(row.batch_code) === String(item.batch_code || 'SIN-LOTE')
      );
      return Number(stock?.quantity_available ?? item.van_stock_before_reconcile ?? 0);
    },

    /**
     * La liquidación necesita el saldo real del lote del reponedor destinatario,
     * no el subconjunto que estuviera filtrado en la sub-vista de stock. Se consulta
     * siempre por su `restocker_id` para no cuadrar contra un saldo de otra furgoneta.
     */
    async loadOrderLotBalances(restockerId) {
      try {
        const data = unwrap(await api.coordinator.getRestockerVanStocks({ restocker_id: restockerId }));
        this.orderVanStocks = Array.isArray(data?.stocks) ? data.stocks : [];
      } catch (error) {
        this.orderVanStocks = [];
      }
    },

    async openReconcileModal(orderId) {
      const detail = await this.fetchOrderDetail(orderId);
      if (!detail?.order) return;
      await this.loadOrderLotBalances(detail.order.restocker_id);
      this.showReconcileModal = true;
      this.reconcileError = '';
      this.reconcileSuccess = '';
      this.reconcileForm = {
        notes: detail.order.reconciliation_notes || '',
        items: (detail.items || [])
          .filter((item) => item.is_active && Number(item.dispatched_qty || 0) > 0)
          .map((item) => {
            const balance = this.currentLotBalance(item);
            return {
              product_id: item.product_id,
              product_sku: item.product_sku,
              product_name: item.product_name,
              batch_code: item.batch_code || 'SIN-LOTE',
              expiration_date: item.expiration_date,
              dispatched_qty: Number(item.dispatched_qty),
              van_stock_before_reconcile: balance,
              restocked_in_machines_qty: 0,
              withdrawn_spoilage_qty: 0,
              counted_return_qty: balance
            };
          })
      };
    },

    reconcileExpected(item) {
      return Number(item.van_stock_before_reconcile || 0)
        - Number(item.restocked_in_machines_qty || 0)
        - Number(item.withdrawn_spoilage_qty || 0);
    },

    reconcileDiscrepancy(item) {
      return Number(item.counted_return_qty || 0) - this.reconcileExpected(item);
    },

    validateReconciliation() {
      this.reconcileError = '';
      const invalid = this.reconcileForm.items.find(
        (item) => Number(item.restocked_in_machines_qty || 0) < 0
          || Number(item.withdrawn_spoilage_qty || 0) < 0
          || Number(item.counted_return_qty || 0) < 0
          || Number(item.restocked_in_machines_qty || 0) + Number(item.withdrawn_spoilage_qty || 0)
            > Number(item.van_stock_before_reconcile || 0)
      );
      if (invalid) {
        this.reconcileError = `El cuadre de ${invalid.product_name} excede el saldo del lote o contiene negativos.`;
        return false;
      }
      if (this.reconcileHasDiscrepancy && this.realLength(this.reconcileForm.notes) < 20) {
        this.reconcileError = 'La liquidación con descuadre exige una justificación de al menos 20 caracteres reales (Art. V.1).';
        return false;
      }
      return true;
    },

    async submitReconciliation() {
      if (!this.validateReconciliation()) return;
      this.isReconciling = true;
      try {
        const updated = unwrap(await api.coordinator.reconcileProductLoadingOrder(this.selectedOrderId, {
          reconciliation_notes: this.reconcileForm.notes,
          items: this.reconcileForm.items.map((item) => ({
            product_id: Number(item.product_id),
            restocked_in_machines_qty: Number(item.restocked_in_machines_qty || 0),
            withdrawn_spoilage_qty: Number(item.withdrawn_spoilage_qty || 0),
            counted_return_qty: Number(item.counted_return_qty || 0)
          }))
        }));
        this.orderDetail = updated;
        this.showReconcileModal = false;
        this.orderSuccess = 'Orden liquidada al cierre con el stock por lote actualizado.';
        await this.loadOrders();
        await this.loadVanStocks();
      } catch (error) {
        this.reconcileError = error?.message || 'No se pudo liquidar la orden.';
      } finally {
        this.isReconciling = false;
      }
    },

    openCancelModal(order) {
      this.showCancelModal = true;
      this.cancelOrderId = order.id;
      this.cancelOrderCode = order.order_code;
      this.cancelReason = '';
      this.cancelError = '';
    },

    async submitCancellation() {
      this.cancelError = '';
      if (this.realLength(this.cancelReason) < 20) {
        this.cancelError = 'El motivo de cancelación exige al menos 20 caracteres reales (Art. V.1).';
        return;
      }
      this.isCancelling = true;
      try {
        await api.coordinator.cancelProductLoadingOrder(this.cancelOrderId, this.cancelReason.trim());
        this.showCancelModal = false;
        this.orderSuccess = 'Orden cancelada con justificación auditada.';
        await this.loadOrders();
      } catch (error) {
        this.cancelError = error?.message || 'No se pudo cancelar la orden.';
      } finally {
        this.isCancelling = false;
      }
    },

    async openPickingSheet(orderId) {
      this.showPickingModal = true;
      this.pickingSheet = null;
      this.pickingError = '';
      this.isLoadingPicking = true;
      try {
        this.pickingSheet = unwrap(await api.coordinator.getProductLoadingPickingSheet(orderId));
      } catch (error) {
        this.pickingError = error?.message || 'No se pudo cargar la Hoja de Picking A4.';
      } finally {
        this.isLoadingPicking = false;
      }
    },

    printPickingSheet() {
      const printer = typeof globalThis !== 'undefined' ? globalThis.print : null;
      if (typeof printer === 'function') {
        printer();
      }
    },

    async openStockAdjustModal(stock = null) {
      this.showStockAdjustModal = true;
      this.stockFormError = '';
      this.stockForm = blankStockForm();
      if (stock) {
        this.stockForm = {
          restocker_id: stock.restocker_id,
          product_id: stock.product_id,
          batch_code: stock.batch_code,
          expiration_date: stock.expiration_date || '',
          quantity_available: stock.quantity_available,
          reason: ''
        };
      } else if (this.restockers[0]) {
        this.stockForm.restocker_id = this.restockers[0].id;
      }
      await this.loadProducts();
    },

    async submitStockAdjustment() {
      this.stockFormError = '';
      if (this.realLength(this.stockForm.reason) < 20) {
        this.stockFormError = 'El ajuste de stock exige un motivo de al menos 20 caracteres reales (Art. V.1).';
        return;
      }
      if (!this.stockForm.restocker_id || !this.stockForm.product_id) {
        this.stockFormError = 'Selecciona el reponedor y el producto del lote.';
        return;
      }
      this.isAdjustingStock = true;
      try {
        await api.coordinator.adjustRestockerVanStock(Number(this.stockForm.restocker_id), {
          product_id: Number(this.stockForm.product_id),
          batch_code: this.stockForm.batch_code === '' ? null : String(this.stockForm.batch_code).toUpperCase(),
          expiration_date: this.stockForm.expiration_date === '' ? null : this.stockForm.expiration_date,
          quantity_available: Number(this.stockForm.quantity_available),
          reason: this.stockForm.reason.trim()
        });
        this.showStockAdjustModal = false;
        this.stockSuccess = 'Lote ajustado y auditado sin borrado físico.';
        await this.loadVanStocks();
      } catch (error) {
        this.stockFormError = error?.message || 'No se pudo ajustar el lote.';
      } finally {
        this.isAdjustingStock = false;
      }
    }
  },
  template: `
    <div class="vg-product-loading" data-testid="coordinator-product-loading-tab">
      <div class="vg-action-group" style="margin-bottom: 16px; flex-wrap: wrap; justify-content: space-between;">
        <div>
          <h2 style="margin: 0; font-family: var(--font-display); font-size: var(--font-size-heading-lg); color: var(--color-ink-slate);">
            🚚 Carga, Visitas y Arqueo de Caja
          </h2>
          <p style="margin: 4px 0 0; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
            Trazabilidad por lote, control FEFO y custodia del efectivo con arqueo ciego.
          </p>
        </div>
        <div class="vg-action-group" style="gap: 6px;">
          <button
            type="button"
            class="vg-btn"
            :class="activeSection === 'orders' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            data-testid="product-loading-orders-section-button"
            @click="activeSection = 'orders'"
          >
            🚚 Órdenes de Carga
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="activeSection === 'stock' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            data-testid="product-loading-stock-section-button"
            @click="activeSection = 'stock'"
          >
            🚐 Stock por Lote
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="activeSection === 'visits' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            data-testid="product-loading-visits-section-button"
            @click="activeSection = 'visits'"
          >
            📋 Visitas In Situ y FEFO
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="activeSection === 'cash' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            data-testid="product-loading-cash-section-button"
            @click="activeSection = 'cash'"
          >
            🪙 Recaudación y Arqueo
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="activeSection === 'route-planner' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            data-testid="product-loading-route-planner-section-button"
            @click="activeSection = 'route-planner'"
          >
            🗺️ Ruta del día y Agendas
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="activeSection === 'dex' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            data-testid="product-loading-dex-section-button"
            @click="activeSection = 'dex'"
          >
            📄 Auditorías .dex
          </button>
        </div>
      </div>

      <div v-if="orderSuccess" class="vg-alert vg-alert-success" role="status" data-testid="product-loading-success" style="margin-bottom: 14px;">
        ✅ {{ orderSuccess }}
      </div>
      <div v-if="orderError" class="vg-alert vg-alert-error" role="alert" style="margin-bottom: 14px;">
        ⚠️ {{ orderError }}
      </div>

      <!-- Sub-vista 1: órdenes de carga -->
      <section v-if="activeSection === 'orders'" data-testid="product-loading-orders-section">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px;">
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">En picking</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-warning-text);">{{ orderKpis.pending_picking_count }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">Despachadas</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-primary);">{{ orderKpis.dispatched_count }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">En ruta (Confirmadas)</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-success-text);">{{ orderKpis.confirmed_in_route_count }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">Liquidadas hoy</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-ink-slate);">{{ orderKpis.reconciled_today_count }}</div>
          </div>
        </div>

        <div class="vg-card" style="padding: 14px; margin-bottom: 16px;">
          <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;">
            <div>
              <label class="vg-label" for="loading-filter-status">Estado</label>
              <select id="loading-filter-status" v-model="orderFilters.status" class="vg-input" @change="loadOrders">
                <option value="">Todos</option>
                <option v-for="status in statusOptions" :key="status.value" :value="status.value">{{ status.label }}</option>
              </select>
            </div>
            <div>
              <label class="vg-label" for="loading-filter-restocker">Reponedor</label>
              <select id="loading-filter-restocker" v-model="orderFilters.restocker_id" class="vg-input" @change="loadOrders">
                <option value="">Todos</option>
                <option v-for="restocker in restockerOptions" :key="restocker.id" :value="restocker.id">{{ restocker.label }}</option>
              </select>
            </div>
            <button type="button" class="vg-btn vg-btn-secondary" data-testid="product-loading-refresh" @click="loadOrders">
              {{ isLoadingOrders ? 'Cargando…' : '↻ Actualizar' }}
            </button>
            <div class="vg-action-group ms-auto" style="gap: 6px;">
              <button type="button" class="vg-btn vg-btn-secondary" data-testid="product-loading-open-batch" @click="openBatchModal">
                📦 Emisión masiva
              </button>
              <button type="button" class="vg-btn vg-btn-primary" data-testid="product-loading-open-proposal" @click="openProposalModal">
                ✨ Nueva propuesta
              </button>
            </div>
          </div>
        </div>

        <div class="vg-card" style="padding: 0; overflow-x: auto;">
          <table class="vg-table" data-testid="product-loading-orders-table">
            <thead>
              <tr>
                <th>Código</th>
                <th>Reponedor</th>
                <th>Estado</th>
                <th>Líneas</th>
                <th>Solicitado</th>
                <th>Despachado</th>
                <th>Creada</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="order in orders" :key="order.id">
                <td style="font-family: var(--font-display); font-weight: 700;">{{ order.order_code }}</td>
                <td>
                  {{ order.restocker_name }}
                  <div v-if="restockerWarning(order)" style="font-size: var(--font-size-caption); color: var(--color-warning-text);">
                    {{ restockerWarning(order) }}
                  </div>
                </td>
                <td><span :class="statusTone(order.status)">{{ order.status_label }}</span></td>
                <td>{{ order.active_items_count }}</td>
                <td>{{ order.total_requested_qty }}</td>
                <td>{{ order.total_dispatched_qty }}</td>
                <td>{{ formatDateTime(order.created_at) }}</td>
                <td>
                  <div class="vg-action-group" style="gap: 4px; flex-wrap: wrap;">
                    <button type="button" class="vg-btn vg-btn-secondary" :data-testid="'product-loading-detail-' + order.id" @click="openDetailModal(order.id)">Ver</button>
                    <button v-if="orderActions(order).edit" type="button" class="vg-btn vg-btn-secondary" :data-testid="'product-loading-edit-' + order.id" @click="openEditModal(order.id)">Editar</button>
                    <button v-if="orderActions(order).dispatch" type="button" class="vg-btn vg-btn-primary" :data-testid="'product-loading-dispatch-' + order.id" @click="openDispatchModal(order.id)">Despachar</button>
                    <button v-if="orderActions(order).reconcile" type="button" class="vg-btn vg-btn-primary" :data-testid="'product-loading-reconcile-' + order.id" @click="openReconcileModal(order.id)">Liquidar</button>
                    <button v-if="orderActions(order).cancel" type="button" class="vg-btn vg-btn-ghost-danger" :data-testid="'product-loading-cancel-' + order.id" @click="openCancelModal(order)">Cancelar</button>
                    <button type="button" class="vg-btn vg-btn-secondary" :data-testid="'product-loading-print-' + order.id" @click="openPickingSheet(order.id)">🖨️ A4</button>
                  </div>
                </td>
              </tr>
              <tr v-if="!isLoadingOrders && orders.length === 0">
                <td colspan="8" style="text-align: center; padding: 28px; color: var(--color-ink-muted);">
                  No hay órdenes de carga que coincidan con los filtros.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Sub-vista 2: stock por lote -->
      <section v-else-if="activeSection === 'stock'" data-testid="product-loading-stock-section">
        <div v-if="stockSuccess" class="vg-alert vg-alert-success" role="status" style="margin-bottom: 14px;">✅ {{ stockSuccess }}</div>
        <div v-if="stockError" class="vg-alert vg-alert-error" role="alert" style="margin-bottom: 14px;">⚠️ {{ stockError }}</div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px;">
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">Lotes</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm);">{{ stockKpis.total_lots }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">Unidades totales</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm);">{{ stockKpis.total_units }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">🔴 Caducados</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-error-text);">{{ stockKpis.expired_lots }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">🟡 Próximos ≤ 48 h</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-warning-text);">{{ stockKpis.expiring_soon_lots }}</div>
          </div>
        </div>

        <div class="vg-card" style="padding: 14px; margin-bottom: 16px;">
          <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;">
            <div>
              <label class="vg-label" for="stock-filter-restocker">Reponedor</label>
              <select id="stock-filter-restocker" v-model="stockFilters.restocker_id" class="vg-input" @change="loadVanStocks">
                <option value="">Todos</option>
                <option v-for="restocker in restockerOptions" :key="restocker.id" :value="restocker.id">{{ restocker.label }}</option>
              </select>
            </div>
            <div>
              <label class="vg-label" for="stock-filter-category">Categoría</label>
              <select id="stock-filter-category" v-model="stockFilters.category" class="vg-input" @change="loadVanStocks">
                <option value="">Todas</option>
                <option value="COLD_DRINK">Bebida fría</option>
                <option value="SNACK">Snack y sólido</option>
                <option value="PERISHABLE_FOOD">Alimento perecedero</option>
                <option value="HOT_BEVERAGE_INGREDIENT">Ingrediente de bebida caliente</option>
                <option value="CUP_STIRRER_CONSUMABLE">Vaso, tapa o paletina</option>
              </select>
            </div>
            <div>
              <label class="vg-label" for="stock-filter-expiry">Caducidad</label>
              <select id="stock-filter-expiry" v-model="stockFilters.expiry_status" class="vg-input" @change="loadVanStocks">
                <option value="">Todas</option>
                <option value="EXPIRED">🔴 Caducados</option>
                <option value="EXPIRING_SOON">🟡 Próximos ≤ 48 h</option>
                <option value="OK">🟢 Vigentes</option>
              </select>
            </div>
            <div>
              <label class="vg-label" for="stock-filter-search">Buscar</label>
              <input id="stock-filter-search" v-model="stockFilters.search" class="vg-input" placeholder="SKU, producto o lote" />
            </div>
            <button type="button" class="vg-btn vg-btn-primary ms-auto" data-testid="product-loading-open-stock-adjust" @click="openStockAdjustModal()">
              ✏️ Ajustar lote
            </button>
          </div>
        </div>

        <div class="vg-card" style="padding: 0; overflow-x: auto;">
          <table class="vg-table" data-testid="product-loading-stock-table">
            <thead>
              <tr>
                <th>Reponedor</th>
                <th>SKU / Producto</th>
                <th>Lote</th>
                <th>Caducidad</th>
                <th>Semáforo</th>
                <th>Cantidad</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="stock in filteredVanStocks" :key="stock.id">
                <td>{{ stock.restocker_name }}</td>
                <td>
                  <strong>{{ stock.product_sku }}</strong>
                  <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ stock.product_name }}</div>
                </td>
                <td style="font-family: monospace;">{{ stock.batch_code }}</td>
                <td>{{ stock.expiration_date || '—' }}</td>
                <td><span :class="expiryTone(stock.expiry_status)">{{ stock.expiry_status_icon }} {{ stock.expiry_status_label }}</span></td>
                <td>{{ stock.quantity_available }} <span style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ stock.product_unit_type }}</span></td>
                <td>
                  <button type="button" class="vg-btn vg-btn-secondary" :data-testid="'product-loading-stock-adjust-' + stock.id" @click="openStockAdjustModal(stock)">Ajustar</button>
                </td>
              </tr>
              <tr v-if="!isLoadingStock && filteredVanStocks.length === 0">
                <td colspan="7" style="text-align: center; padding: 28px; color: var(--color-ink-muted);">
                  No hay lotes que coincidan con los filtros.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Sub-vista 3: visitas de reposición in situ y control FEFO -->
      <section v-else-if="activeSection === 'visits'" data-testid="product-loading-visits-section">
        <div v-if="visitError" class="vg-alert vg-alert-error" role="alert" data-testid="product-loading-visits-error" style="margin-bottom: 14px;">
          ⚠️ {{ visitError }}
        </div>

        <div data-testid="product-loading-visits-kpis" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px;">
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">✅ Visitas completadas</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-success-text);">{{ visitKpis.completed_visits_count }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">🌡️ Abortos térmicos</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-error-text);">{{ visitKpis.aborted_thermal_visits_count }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">📦 Unidades abastecidas</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-primary);">{{ visitKpis.total_units_added }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">♻️ Unidades retiradas</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-warning-text);">{{ visitKpis.total_units_withdrawn }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">💶 Merma comercial</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-error-text);">{{ formatCurrency(visitKpis.total_commercial_spoilage_loss_eur) }}</div>
          </div>
        </div>

        <div class="vg-card" style="padding: 14px; margin-bottom: 16px;">
          <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;">
            <div>
              <label class="vg-label" for="visit-filter-restocker">Reponedor</label>
              <select id="visit-filter-restocker" v-model="visitFilters.restocker_id" class="vg-input" data-testid="product-loading-visit-filter-restocker" @change="loadVisits">
                <option value="">Todos</option>
                <option v-for="restocker in restockerOptions" :key="restocker.id" :value="restocker.id">{{ restocker.label }}</option>
              </select>
            </div>
            <div>
              <label class="vg-label" for="visit-filter-status">Estado</label>
              <select id="visit-filter-status" v-model="visitFilters.status" class="vg-input" data-testid="product-loading-visit-filter-status" @change="loadVisits">
                <option value="">Todos</option>
                <option v-for="status in visitStatusOptions" :key="status.value" :value="status.value">{{ status.label }}</option>
              </select>
            </div>
            <div>
              <label class="vg-label" for="visit-filter-machine">Máquina (ID)</label>
              <input id="visit-filter-machine" v-model="visitFilters.machine_id" class="vg-input" type="number" min="1" placeholder="Todas" data-testid="product-loading-visit-filter-machine" @change="loadVisits" />
            </div>
            <div>
              <label class="vg-label" for="visit-filter-date-from">Desde</label>
              <input id="visit-filter-date-from" v-model="visitFilters.date_from" class="vg-input" type="date" data-testid="product-loading-visit-filter-date-from" @change="loadVisits" />
            </div>
            <div>
              <label class="vg-label" for="visit-filter-date-to">Hasta</label>
              <input id="visit-filter-date-to" v-model="visitFilters.date_to" class="vg-input" type="date" data-testid="product-loading-visit-filter-date-to" @change="loadVisits" />
            </div>
            <button type="button" class="vg-btn vg-btn-secondary ms-auto" data-testid="product-loading-visits-refresh" @click="loadVisits">
              {{ isLoadingVisits ? 'Cargando…' : '↻ Actualizar' }}
            </button>
          </div>
        </div>

        <div class="vg-card" style="padding: 0; overflow-x: auto;">
          <table class="vg-table" data-testid="product-loading-visits-table">
            <thead>
              <tr>
                <th>Visita</th>
                <th>Máquina / Ubicación</th>
                <th>Reponedor</th>
                <th>Estado</th>
                <th>Temp. cabina</th>
                <th>Abastecidas</th>
                <th>Retiradas</th>
                <th>Merma</th>
                <th>Ticket SAT</th>
                <th>Finalizada</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="visit in visits" :key="visit.id">
                <td style="font-family: var(--font-display); font-weight: 700;">{{ visit.visit_code }}</td>
                <td>
                  <strong>{{ visit.machine_code }}</strong>
                  <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ visit.location_name }}</div>
                </td>
                <td>{{ visit.restocker_name }}</td>
                <td><span :class="visitStatusTone(visit.status)">{{ visit.status_icon }} {{ visit.status_label }}</span></td>
                <td>{{ visit.cabin_temp_celsius === null || visit.cabin_temp_celsius === undefined ? '—' : visit.cabin_temp_celsius + ' °C' }}</td>
                <td>{{ visit.units_added }}</td>
                <td>{{ visit.units_withdrawn }}</td>
                <td>{{ formatCurrency(visit.commercial_spoilage_loss_eur) }}</td>
                <td>
                  <a v-if="visit.triggered_ticket_code" :href="visitTicketHref(visit)" :data-testid="'product-loading-visit-ticket-' + visit.id" @click.prevent="openVisitTicket(visit)">🎫 {{ visit.triggered_ticket_code }}</a>
                  <span v-else style="color: var(--color-ink-muted);">—</span>
                </td>
                <td>{{ formatDateTime(visit.completed_at || visit.started_at) }}</td>
                <td>
                  <button type="button" class="vg-btn vg-btn-secondary" :data-testid="'product-loading-visit-detail-' + visit.id" @click="openVisitDetailModal(visit.id)">Ver expediente</button>
                </td>
              </tr>
              <tr v-if="!isLoadingVisits && visits.length === 0">
                <td colspan="11" style="text-align: center; padding: 28px; color: var(--color-ink-muted);">
                  No hay visitas de reposición in situ que coincidan con los filtros.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Sub-vista 4: recaudación, cambio y arqueo de caja -->
      <section v-else-if="activeSection === 'cash'" data-testid="product-loading-cash-section">
        <div v-if="cashSuccess" class="vg-alert vg-alert-success" role="status" data-testid="product-loading-cash-success" style="margin-bottom: 14px;">✅ {{ cashSuccess }}</div>
        <div v-if="cashError" class="vg-alert vg-alert-error" role="alert" data-testid="product-loading-cash-error" style="margin-bottom: 14px;">⚠️ {{ cashError }}</div>

        <div data-testid="product-loading-cash-kpis" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px;">
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">Bolsas en ruta / cámara (Pendientes)</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-warning-text);">{{ cashKpis.pending_vault_bags_count }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">Bolsas arqueadas OK</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-success-text);">{{ cashKpis.reconciled_ok_count }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">Arqueos con descuadre (&gt; ±1,00 €)</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-error-text);">{{ cashKpis.discrepancy_flagged_count }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">Total recaudado en caja (€)</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-primary);">{{ formatCurrency(cashKpis.total_vault_counted_eur) }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">Descuadre neto acumulado (€)</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm);" :style="{ color: Number(cashKpis.net_discrepancy_eur) === 0 ? 'var(--color-ink-slate)' : 'var(--color-error-text)' }">{{ formatCurrency(cashKpis.net_discrepancy_eur) }}</div>
          </div>
          <div class="vg-card" style="padding: 14px;">
            <div class="vg-label">Cambio cargado + Conserjería (€)</div>
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-ink-slate);">{{ formatCurrency(cashKpis.total_route_cash_deployed_eur) }}</div>
          </div>
        </div>

        <div class="vg-card" style="padding: 14px; margin-bottom: 16px;">
          <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;">
            <div>
              <label class="vg-label" for="cash-filter-restocker">Reponedor</label>
              <select id="cash-filter-restocker" v-model="cashFilters.restocker_id" class="vg-input" data-testid="product-loading-cash-filter-restocker" @change="loadCashCollections">
                <option value="">Todos</option>
                <option v-for="restocker in restockerOptions" :key="restocker.id" :value="restocker.id">{{ restocker.label }}</option>
              </select>
            </div>
            <div>
              <label class="vg-label" for="cash-filter-status">Estado</label>
              <select id="cash-filter-status" v-model="cashFilters.status" class="vg-input" data-testid="product-loading-cash-filter-status" @change="loadCashCollections">
                <option v-for="status in cashStatusOptions" :key="status.value" :value="status.value">{{ status.label }}</option>
              </select>
            </div>
            <div>
              <label class="vg-label" for="cash-filter-machine">Máquina (ID)</label>
              <input id="cash-filter-machine" v-model="cashFilters.machine_id" class="vg-input" type="number" min="1" placeholder="Todas" data-testid="product-loading-cash-filter-machine" @change="loadCashCollections" />
            </div>
            <div>
              <label class="vg-label" for="cash-filter-seal">Precinto</label>
              <input id="cash-filter-seal" v-model="cashFilters.bag_seal_code" class="vg-input" placeholder="BAG-…" data-testid="product-loading-cash-filter-seal" @change="loadCashCollections" />
            </div>
            <div>
              <label class="vg-label" for="cash-filter-date-from">Desde</label>
              <input id="cash-filter-date-from" v-model="cashFilters.date_from" class="vg-input" type="date" data-testid="product-loading-cash-filter-date-from" @change="loadCashCollections" />
            </div>
            <div>
              <label class="vg-label" for="cash-filter-date-to">Hasta</label>
              <input id="cash-filter-date-to" v-model="cashFilters.date_to" class="vg-input" type="date" data-testid="product-loading-cash-filter-date-to" @change="loadCashCollections" />
            </div>
            <div class="vg-action-group ms-auto" style="gap: 6px;">
              <button type="button" class="vg-btn vg-btn-secondary" data-testid="product-loading-cash-refresh" @click="loadCashCollections">
                {{ isLoadingCash ? 'Cargando…' : '↻ Actualizar' }}
              </button>
              <button type="button" class="vg-btn vg-btn-primary" data-testid="product-loading-cash-export" @click="exportCashCollectionsCsv">
                📥 Exportar CSV
              </button>
            </div>
          </div>
        </div>

        <div class="vg-card vg-cash-table-scroll">
          <table class="vg-table" data-testid="product-loading-cash-table">
            <thead>
              <tr>
                <th>Código</th>
                <th>Fecha</th>
                <th>Reponedor</th>
                <th>Máquina / Sede</th>
                <th>Precinto</th>
                <th>Esperado</th>
                <th>Estado</th>
                <th>Contado</th>
                <th>Descuadre</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="collection in cashCollections" :key="collection.id">
                <td style="font-family: var(--font-display); font-weight: 700;">{{ collection.collection_code }}</td>
                <td>{{ formatDateTime(collection.collected_at) }}</td>
                <td>{{ collection.restocker_name_snapshot }}</td>
                <td>
                  <strong>{{ collection.machine_code_snapshot }}</strong>
                  <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ collection.location_name }}</div>
                </td>
                <td style="font-family: monospace;">{{ collection.bag_seal_code }}</td>
                <td>{{ formatCurrency(collection.expected_cashbox_eur) }}</td>
                <td><span :class="cashStatusTone(collection.status)">{{ collection.status_label }}</span></td>
                <td>{{ collection.vault_counted_eur === null || collection.vault_counted_eur === undefined ? '—' : formatCurrency(collection.vault_counted_eur) }}</td>
                <td :style="{ color: Number(collection.discrepancy_eur) === 0 ? 'var(--color-ink-slate)' : 'var(--color-error-text)' }">{{ collection.discrepancy_eur === null || collection.discrepancy_eur === undefined ? '—' : formatCurrency(collection.discrepancy_eur) }}</td>
                <td>
                  <div class="vg-action-group" style="gap: 4px; flex-wrap: wrap;">
                    <button type="button" class="vg-btn vg-btn-secondary" :data-testid="'product-loading-cash-detail-' + collection.id" @click="openCashDetailModal(collection)">
                      {{ collection.is_terminal ? 'Ver' : '🔍 Arquear' }}
                    </button>
                    <button v-if="collection.status === 'SEALED_IN_ROUTE'" type="button" class="vg-btn vg-btn-primary" :data-testid="'product-loading-cash-deliver-' + collection.id" @click="deliverCashCollection(collection)">
                      📥 Recibir
                    </button>
                    <button v-if="Number(collection.reception_cash_delivered_eur) > 0" type="button" class="vg-btn vg-btn-secondary" :data-testid="'product-loading-cash-receipt-' + collection.id" @click="openCashReceiptModal(collection)">
                      🖨️ Recibo
                    </button>
                    <button v-if="collection.restock_visit_id" type="button" class="vg-btn vg-btn-secondary" :data-testid="'product-loading-cash-visit-' + collection.id" @click="openCashVisit(collection)">
                      📋 Visita
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!isLoadingCash && cashCollections.length === 0">
                <td colspan="10" style="text-align: center; padding: 28px; color: var(--color-ink-muted);">
                  No hay recaudaciones que coincidan con los filtros.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Sub-vista 5: planificador de rutas periódicas -->
      <section v-else-if="activeSection === 'route-planner'" data-testid="product-loading-route-planner-section">
        <CoordinatorRestockRoutePlannerSubTab />
      </section>

      <!-- Sub-vista 6: libro EVA-DTS .dex -->
      <section v-else-if="activeSection === 'dex'" data-testid="product-loading-dex-section">
        <CoordinatorDexAuditsSubTab />
      </section>

      <!-- Expediente de visita in situ -->
      <ModalDialog v-model="showVisitDetailModal" :title="visitDetailVisit ? visitDetailVisit.visit_code : 'Expediente de visita'" subtitle="Trazabilidad carril a carril, FEFO y triple cerrojo sanitario." size="xl">
        <div v-if="isLoadingVisitDetail" data-testid="product-loading-visit-detail-loading" style="padding: 24px; text-align: center; color: var(--color-ink-muted);">
          Cargando expediente…
        </div>
        <div v-else-if="visitDetailError" class="vg-alert vg-alert-error" role="alert" data-testid="product-loading-visit-detail-error">
          ⚠️ {{ visitDetailError }}
        </div>
        <div v-else-if="visitDetailVisit" data-testid="product-loading-visit-detail-modal">
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px;">
            <div class="vg-card" style="padding: 12px;">
              <div class="vg-label">Máquina</div>
              <div><strong>{{ visitDetailVisit.machine_code }}</strong> · {{ visitDetailVisit.machine_model }}</div>
              <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ visitDetailVisit.location_name }}</div>
            </div>
            <div class="vg-card" style="padding: 12px;">
              <div class="vg-label">Reponedor</div>
              <div>{{ visitDetailVisit.restocker_name }}</div>
            </div>
            <div class="vg-card" style="padding: 12px;">
              <div class="vg-label">Estado</div>
              <div><span :class="visitStatusTone(visitDetailVisit.status)">{{ visitDetailVisit.status_label }}</span></div>
              <div v-if="visitDetailVisit.is_thermal_abort" class="vg-badge vg-badge-danger" style="margin-top: 6px;">🌡️ Aborto térmico</div>
            </div>
            <div class="vg-card" style="padding: 12px;">
              <div class="vg-label">Temperatura de cabina</div>
              <div>{{ visitDetailVisit.cabin_temp_celsius === null || visitDetailVisit.cabin_temp_celsius === undefined ? '—' : visitDetailVisit.cabin_temp_celsius + ' °C' }}</div>
            </div>
            <div class="vg-card" style="padding: 12px;">
              <div class="vg-label">Inicio / Cierre</div>
              <div>{{ formatDateTime(visitDetailVisit.started_at) }}</div>
              <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ formatDateTime(visitDetailVisit.completed_at) }}</div>
            </div>
            <div class="vg-card" style="padding: 12px;">
              <div class="vg-label">Unidades / Merma</div>
              <div>+{{ visitDetailVisit.units_added }} · −{{ visitDetailVisit.units_withdrawn }}</div>
              <div style="color: var(--color-error-text);">{{ formatCurrency(visitDetailVisit.commercial_spoilage_loss_eur) }}</div>
            </div>
            <div class="vg-card" style="padding: 12px;">
              <div class="vg-label">Sello precautorio</div>
              <div>{{ visitDetailVisit.precautionary_seal_applied ? 'Aplicado' : 'No aplicado' }}</div>
            </div>
            <div class="vg-card" style="padding: 12px;">
              <div class="vg-label">Ticket SAT</div>
              <a v-if="visitDetailVisit.triggered_ticket_code" :href="visitTicketHref(visitDetailVisit)" data-testid="product-loading-visit-detail-ticket" @click.prevent="openVisitTicket(visitDetailVisit)">🎫 {{ visitDetailVisit.triggered_ticket_code }}</a>
              <span v-else style="color: var(--color-ink-muted);">Sin ticket</span>
            </div>
          </div>

          <div v-if="visitDetailVisit.internal_notes" class="vg-alert vg-alert-info" data-testid="product-loading-visit-detail-notes" style="margin-bottom: 16px;">
            📝 {{ visitDetailVisit.internal_notes }}
          </div>

          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 16px;">
            <div class="vg-card" style="padding: 14px;" data-testid="product-loading-visit-detail-hygiene">
              <h4 style="margin: 0 0 10px; font-family: var(--font-display);">🧼 Checklist higiénico</h4>
              <div v-if="visitHygieneItems.length === 0" style="color: var(--color-ink-muted);">Sin checklist registrado.</div>
              <ul v-else style="margin: 0; padding-left: 18px;">
                <li v-for="entry in visitHygieneItems" :key="entry.key">{{ entry.label }}: <strong>{{ visitHygieneValue(entry.value) }}</strong></li>
              </ul>
            </div>
            <div class="vg-card" style="padding: 14px;" data-testid="product-loading-visit-detail-photo">
              <h4 style="margin: 0 0 10px; font-family: var(--font-display);">📷 Evidencia fotográfica</h4>
              <a v-if="visitDetailVisit.photo_evidence_url" :href="visitDetailVisit.photo_evidence_url" target="_blank" rel="noopener" data-testid="product-loading-visit-detail-photo-link">Abrir evidencia</a>
              <div v-else style="color: var(--color-ink-muted);">Sin foto adjunta.</div>
            </div>
          </div>

          <div class="vg-card" style="padding: 0; overflow-x: auto;">
            <table class="vg-table" data-testid="product-loading-visit-detail-items">
              <thead>
                <tr>
                  <th>Carril</th>
                  <th>Producto</th>
                  <th>Antes</th>
                  <th>Retiradas</th>
                  <th>Cargadas</th>
                  <th>Después</th>
                  <th>Lote retirado</th>
                  <th>Lote cargado</th>
                  <th>Caducidad cargada</th>
                  <th>Coste unitario</th>
                  <th>Merma</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in visitItems" :key="item.id">
                  <td style="font-family: monospace;">{{ item.slot_code }}</td>
                  <td>#{{ item.product_id }}</td>
                  <td>{{ item.qty_before }}</td>
                  <td>{{ item.qty_withdrawn_expired + item.qty_withdrawn_damaged }}</td>
                  <td>{{ item.qty_added }}</td>
                  <td>{{ item.qty_after }}</td>
                  <td>{{ item.withdrawn_batch_code || '—' }}</td>
                  <td>{{ item.loaded_batch_code || '—' }}</td>
                  <td>{{ item.loaded_expiration_date || '—' }}</td>
                  <td>{{ formatCurrency(item.unit_cost_snapshot_eur) }}</td>
                  <td>{{ formatCurrency(item.commercial_spoilage_loss_eur) }}</td>
                </tr>
                <tr v-if="visitItems.length === 0">
                  <td colspan="11" style="text-align: center; padding: 24px; color: var(--color-ink-muted);">Sin líneas de carril registradas.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" data-testid="product-loading-visit-detail-close" @click="showVisitDetailModal = false">Cerrar</button>
        </template>
      </ModalDialog>

      <!-- Arqueo ciego de una bolsa precintada -->
      <ModalDialog v-model="showCashDetailModal" title="Arqueo de Bolsa Precintada" subtitle="El esperado se calcula en servidor; aquí solo se cuenta la bolsa y se justifica el descuadre (D2, Art. V.1)." size="lg">
        <div v-if="isLoadingCashDetail" data-testid="product-loading-cash-detail-loading" style="padding: 24px; text-align: center; color: var(--color-ink-muted);">
          Cargando expediente de recaudación…
        </div>
        <div v-else-if="cashDetailError" class="vg-alert vg-alert-error" role="alert" data-testid="product-loading-cash-detail-error">
          ⚠️ {{ cashDetailError }}
        </div>
        <div v-else-if="cashDetailCollection" data-testid="product-loading-cash-detail-modal">
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px;">
            <div class="vg-card" style="padding: 12px;">
              <div class="vg-label">Recaudación</div>
              <div style="font-family: var(--font-display); font-weight: 700;">{{ cashDetailCollection.collection_code }}</div>
              <div style="font-family: monospace; font-size: var(--font-size-caption);">{{ cashDetailCollection.bag_seal_code }}</div>
            </div>
            <div class="vg-card" style="padding: 12px;">
              <div class="vg-label">Máquina / Sede</div>
              <div><strong>{{ cashDetailCollection.machine_code_snapshot }}</strong></div>
              <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ cashDetailCollection.location_name }}</div>
            </div>
            <div class="vg-card" style="padding: 12px;">
              <div class="vg-label">Reponedor</div>
              <div>{{ cashDetailCollection.restocker_name_snapshot }}</div>
            </div>
            <div class="vg-card" style="padding: 12px;">
              <div class="vg-label">Estado de custodia</div>
              <div><span :class="cashStatusTone(cashDetailCollection.status)">{{ cashDetailCollection.status_label }}</span></div>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 16px;">
            <div class="vg-card" style="padding: 14px;" data-testid="product-loading-cash-detail-readings">
              <h4 style="margin: 0 0 10px; font-family: var(--font-display);">Contador de ventas</h4>
              <div style="display: flex; justify-content: space-between; gap: 12px; margin-bottom: 6px;">
                <span class="vg-label">Lectura anterior</span>
                <strong>{{ cashDetailCollection.previous_sales_counter_reading === null || cashDetailCollection.previous_sales_counter_reading === undefined ? '—' : formatCurrency(cashDetailCollection.previous_sales_counter_reading) }}</strong>
              </div>
              <div style="display: flex; justify-content: space-between; gap: 12px;">
                <span class="vg-label">Lectura actual</span>
                <strong>{{ formatCurrency(cashDetailCollection.sales_counter_reading) }}</strong>
              </div>
              <div v-if="cashDetailCollection.is_counter_reset" class="vg-alert vg-alert-warning" role="status" style="margin-top: 10px; margin-bottom: 0;">
                🔄 Reseteo declarado: {{ cashDetailCollection.counter_reset_notes }}
              </div>
            </div>

            <div class="vg-card" style="padding: 14px;" data-testid="product-loading-cash-detail-expected">
              <h4 style="margin: 0 0 10px; font-family: var(--font-display);">Esperado en caja</h4>
              <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-primary);">{{ formatCurrency(cashDetailCollection.expected_cashbox_eur) }}</div>
              <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">Calculado en servidor con la lectura previa, la base o el reseteo justificado.</div>
            </div>

            <div v-if="Number(cashDetailCollection.reception_cash_delivered_eur) > 0" class="vg-card" style="padding: 14px;" data-testid="product-loading-cash-detail-reception">
              <h4 style="margin: 0 0 10px; font-family: var(--font-display);">Conserjería</h4>
              <div>{{ formatCurrency(cashDetailCollection.reception_cash_delivered_eur) }} · {{ cashDetailCollection.receptionist_name }}</div>
              <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">Recibo de nueve campos disponible.</div>
            </div>
          </div>

          <div v-if="cashDetailIsTerminal" class="vg-card" style="padding: 14px; margin-bottom: 16px;" data-testid="product-loading-cash-detail-terminal">
            <h4 style="margin: 0 0 10px; font-family: var(--font-display);">Arqueo terminal</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
              <div>
                <div class="vg-label">Contado</div>
                <div>{{ formatCurrency(cashDetailCollection.vault_counted_eur) }}</div>
              </div>
              <div>
                <div class="vg-label">Descuadre</div>
                <div :style="{ color: Number(cashDetailCollection.discrepancy_eur) === 0 ? 'var(--color-ink-slate)' : 'var(--color-error-text)' }">{{ formatCurrency(cashDetailCollection.discrepancy_eur) }}</div>
              </div>
              <div>
                <div class="vg-label">Firmado por</div>
                <div>{{ cashDetailCollection.vault_reconciled_by_name_snapshot || '—' }}</div>
              </div>
              <div>
                <div class="vg-label">Fecha de arqueo</div>
                <div>{{ formatDateTime(cashDetailCollection.vault_reconciled_at) }}</div>
              </div>
            </div>
            <div v-if="cashDetailCollection.discrepancy_justification" class="vg-alert vg-alert-info" style="margin-top: 12px; margin-bottom: 0;">
              📝 {{ cashDetailCollection.discrepancy_justification }}
            </div>
          </div>

          <div v-else class="vg-card" style="padding: 14px;" data-testid="product-loading-cash-detail-reconcile-form">
            <h4 style="margin: 0 0 10px; font-family: var(--font-display);">Arqueo ciego</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
              <div>
                <label class="vg-label" for="cash-vault-counted">Efectivo contado (€)</label>
                <input id="cash-vault-counted" v-model="cashReconcileForm.vault_counted_eur" class="vg-input" type="number" min="0" max="10000" step="0.01" data-testid="product-loading-cash-vault-counted" />
              </div>
              <div>
                <div class="vg-label">Descuadre en vivo</div>
                <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm);" :style="{ color: cashLiveDiscrepancy === null || Math.abs(cashLiveDiscrepancy) <= 1 ? 'var(--color-success-text)' : 'var(--color-error-text)' }" data-testid="product-loading-cash-live-discrepancy">
                  {{ cashLiveDiscrepancy === null ? '—' : formatCurrency(cashLiveDiscrepancy) }}
                </div>
                <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">Tolerancia ±1,00 €.</div>
              </div>
            </div>

            <div v-if="cashRequiresJustification" style="margin-top: 12px;">
              <div class="vg-alert vg-alert-warning" role="status" style="margin-bottom: 10px;">
                ⚠️ El descuadre supera ±1,00 €: la justificación es obligatoria (20–1.000 caracteres reales y 3 bloques alfanuméricos).
              </div>
              <label class="vg-label" for="cash-discrepancy-justification">Justificación del descuadre</label>
              <textarea id="cash-discrepancy-justification" v-model="cashReconcileForm.discrepancy_justification" class="vg-input" rows="3" maxlength="1000" data-testid="product-loading-cash-justification"></textarea>
              <div style="margin-top: 4px; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                {{ realLength(cashReconcileForm.discrepancy_justification) }} caracteres reales.
              </div>
            </div>

            <div v-if="cashReconcileError" class="vg-alert vg-alert-error" role="alert" data-testid="product-loading-cash-reconcile-error" style="margin-top: 12px; margin-bottom: 0;">
              ⚠️ {{ cashReconcileError }}
            </div>
          </div>
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" data-testid="product-loading-cash-detail-close" @click="showCashDetailModal = false">Cerrar</button>
          <button v-if="cashDetailCanDeliver" type="button" class="vg-btn vg-btn-primary" data-testid="product-loading-cash-detail-deliver" @click="deliverCashCollection(cashDetailCollection)">
            📥 Recibir en cámara
          </button>
          <button v-if="cashDetailCollection && Number(cashDetailCollection.reception_cash_delivered_eur) > 0" type="button" class="vg-btn vg-btn-secondary" data-testid="product-loading-cash-detail-receipt" @click="openCashReceiptModal(cashDetailCollection)">
            🖨️ Recibo conserjería
          </button>
          <button v-if="cashDetailCollection?.restock_visit_id" type="button" class="vg-btn vg-btn-secondary" data-testid="product-loading-cash-detail-visit" @click="openCashVisit(cashDetailCollection)">
            📋 Ver visita
          </button>
          <button v-if="cashDetailCanReconcile" type="button" class="vg-btn vg-btn-primary" :disabled="isReconcilingCash" data-testid="product-loading-cash-submit-reconcile" @click="submitCashReconciliation">
            {{ isReconcilingCash ? 'Sellando…' : '🔐 Sellar arqueo' }}
          </button>
        </template>
      </ModalDialog>

      <!-- Recibo opaco de conserjería -->
      <ModalDialog v-model="showCashReceiptModal" title="Recibo de Conserjería" subtitle="Nueve campos y nada más: sin bolsa, sin arqueo y sin PII del reclamante (QA 2, Art. V.4)." size="md">
        <div v-if="isLoadingCashReceipt" data-testid="product-loading-cash-receipt-loading" style="padding: 24px; text-align: center; color: var(--color-ink-muted);">
          Cargando recibo…
        </div>
        <div v-else-if="cashReceiptError" class="vg-alert vg-alert-error" role="alert" data-testid="product-loading-cash-receipt-error">
          ⚠️ {{ cashReceiptError }}
        </div>
        <div v-else-if="cashReceipt" data-testid="product-loading-cash-receipt-modal">
          <div class="vg-card" style="padding: 16px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
              <div><div class="vg-label">Recaudación</div><strong>{{ cashReceipt.collection_code }}</strong></div>
              <div><div class="vg-label">Fecha</div>{{ formatDateTime(cashReceipt.collected_at) }}</div>
              <div><div class="vg-label">Sede</div>{{ cashReceipt.location_code }} · {{ cashReceipt.location_name }}</div>
              <div><div class="vg-label">Máquina</div>{{ cashReceipt.machine_code }}</div>
              <div><div class="vg-label">Reponedor</div>{{ cashReceipt.restocker_name }}</div>
              <div><div class="vg-label">Conserje</div>{{ cashReceipt.receptionist_name }}</div>
              <div><div class="vg-label">Entregado</div><strong>{{ formatCurrency(cashReceipt.reception_cash_delivered_eur) }}</strong></div>
              <div><div class="vg-label">Reintegro vinculado</div>{{ cashReceipt.linked_refund_request_id ?? '—' }}</div>
            </div>
          </div>
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" data-testid="product-loading-cash-receipt-close" @click="showCashReceiptModal = false">Cerrar</button>
        </template>
      </ModalDialog>

      <!-- Propuesta individual -->
      <ModalDialog v-model="showProposalModal" title="Propuesta de carga matutina" subtitle="Solo máquinas operativas de las zonas cubiertas hoy (QA 1)." size="xl">
        <div v-if="proposalError" class="vg-alert vg-alert-error" role="alert" data-testid="product-loading-proposal-error" style="margin-bottom: 12px;">⚠️ {{ proposalError }}</div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
          <div>
            <label class="vg-label" for="proposal-restocker">Reponedor</label>
            <select id="proposal-restocker" v-model="proposalForm.restocker_id" class="vg-input">
              <option value="">Selecciona…</option>
              <option v-for="restocker in restockerOptions" :key="restocker.id" :value="restocker.id">{{ restocker.label }}</option>
            </select>
          </div>
          <div>
            <label class="vg-label" for="proposal-mode">Modo de demanda</label>
            <select id="proposal-mode" v-model="proposalForm.demand_mode" class="vg-input">
              <option value="ALERTS_ONLY">Solo alertas</option>
              <option value="ALL_GAPS">Ruta completa</option>
              <option value="TODAY_ROUTE">Ruta del día</option>
            </select>
          </div>
          <div v-if="proposalForm.demand_mode === 'TODAY_ROUTE'">
            <label class="vg-label" for="proposal-service-date">Fecha de ruta</label>
            <input id="proposal-service-date" v-model="proposalForm.service_date" class="vg-input" type="date" />
          </div>
          <div>
            <label class="vg-label" for="proposal-buffer">Colchón (%)</label>
            <input id="proposal-buffer" v-model.number="proposalForm.safety_buffer_pct" class="vg-input" type="number" min="0" max="50" />
          </div>
          <div style="display: flex; align-items: flex-end;">
            <button type="button" class="vg-btn vg-btn-secondary" :disabled="isLoadingProposal" data-testid="product-loading-calculate-proposal" @click="loadProposal">
              {{ isLoadingProposal ? 'Calculando…' : 'Calcular propuesta' }}
            </button>
          </div>
        </div>

        <div v-if="proposal && proposalRecipientWarning" class="vg-alert vg-alert-warning" role="status" data-testid="product-loading-proposal-absent-warning" style="margin-top: 14px;">
          {{ proposalRecipientWarning }}
        </div>

        <div v-if="proposal" style="margin-top: 14px;">
          <div class="vg-alert vg-alert-info" style="margin-bottom: 12px;">
            {{ proposal.demand_mode_label }} · colchón {{ proposal.safety_buffer_pct }} % ·
            <strong>{{ proposal.total_suggested_qty }}</strong> unidades sugeridas ·
            {{ proposal.excluded_unavailable_machines_count }} máquinas omitidas por cuarentena o fuera de servicio
          </div>
          <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted); margin-bottom: 8px;">
            Zonas cubiertas: {{ (proposal.zones || []).map((zone) => zone.zone_name || zone.zone_code).join(', ') || '—' }}
          </div>
          <div style="overflow-x: auto;">
            <table class="vg-table" data-testid="product-loading-proposal-table">
              <thead>
                <tr>
                  <th>SKU / Producto</th>
                  <th>Motivo</th>
                  <th>Hueco</th>
                  <th>Stock útil furgoneta</th>
                  <th>Sugerido</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in proposalItems" :key="item.product_id">
                  <td>
                    <strong>{{ item.product_sku }}</strong>
                    <span v-if="item.requires_cold_chain" style="margin-left: 6px;" title="Cadena de frío">❄️</span>
                    <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ item.product_name }}</div>
                  </td>
                  <td>{{ item.reason_label }}</td>
                  <td>{{ item.gap_qty }}</td>
                  <td>{{ item.usable_van_stock_qty }}</td>
                  <td style="max-width: 110px;">
                    <input v-model.number="item.suggested_qty" class="vg-input" type="number" min="1" max="99999" />
                  </td>
                </tr>
                <tr v-if="proposalItems.length === 0">
                  <td colspan="5" style="text-align: center; padding: 20px; color: var(--color-ink-muted);">Sin demanda que cubrir.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showProposalModal = false">Cerrar</button>
          <button type="button" class="vg-btn vg-btn-primary" :disabled="!proposal || isCreatingOrder" data-testid="product-loading-create-order" @click="createOrderFromProposal">
            {{ isCreatingOrder ? 'Emitiendo…' : 'Emitir orden de carga' }}
          </button>
        </template>
      </ModalDialog>

      <!-- Emisión masiva -->
      <ModalDialog v-model="showBatchModal" title="Emisión masiva de órdenes" subtitle="Se omiten ausencias, órdenes activas y reponedores sin demanda (C2)." size="md">
        <div v-if="batchError" class="vg-alert vg-alert-error" role="alert" style="margin-bottom: 12px;">⚠️ {{ batchError }}</div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
          <div>
            <label class="vg-label" for="batch-mode">Modo de demanda</label>
            <select id="batch-mode" v-model="batchForm.demand_mode" class="vg-input">
              <option value="ALERTS_ONLY">Solo alertas</option>
              <option value="ALL_GAPS">Ruta completa</option>
              <option value="TODAY_ROUTE">Ruta del día</option>
            </select>
          </div>
          <div v-if="batchForm.demand_mode === 'TODAY_ROUTE'">
            <label class="vg-label" for="batch-service-date">Fecha de ruta</label>
            <input id="batch-service-date" v-model="batchForm.service_date" class="vg-input" type="date" />
          </div>
          <div>
            <label class="vg-label" for="batch-buffer">Colchón (%)</label>
            <input id="batch-buffer" v-model.number="batchForm.safety_buffer_pct" class="vg-input" type="number" min="0" max="50" />
          </div>
        </div>
        <div v-if="batchResult" style="margin-top: 14px;" data-testid="product-loading-batch-result">
          <div class="vg-alert vg-alert-success" style="margin-bottom: 10px;">
            {{ batchResult.created_count }} órdenes creadas · {{ batchResult.skipped_count }} reponedores omitidos.
          </div>
          <ul style="margin: 0; padding-left: 20px; font-size: var(--font-size-body-sm); color: var(--color-ink-secondary);">
            <li v-for="skipped in batchResult.skipped" :key="skipped.restocker_id">
              {{ skipped.restocker_name }}: {{ skipped.reason_label || skipped.reason }}
            </li>
          </ul>
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showBatchModal = false">Cerrar</button>
          <button type="button" class="vg-btn vg-btn-primary" :disabled="isGeneratingBatch" data-testid="product-loading-generate-batch" @click="generateBatch">
            {{ isGeneratingBatch ? 'Generando…' : 'Generar órdenes' }}
          </button>
        </template>
      </ModalDialog>

      <!-- Detalle -->
      <ModalDialog v-model="showDetailModal" :title="selectedOrder ? selectedOrder.order_code : 'Detalle de la orden'" subtitle="Historial y líneas sin borrado físico (Art. III.1)." size="xl">
        <div v-if="detailError" class="vg-alert vg-alert-error" role="alert" style="margin-bottom: 12px;">⚠️ {{ detailError }}</div>
        <div v-if="isLoadingDetail" style="padding: 20px; color: var(--color-ink-muted);">Cargando detalle…</div>
        <div v-else-if="selectedOrder">
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 14px;">
            <div><span class="vg-label">Reponedor</span>{{ selectedOrder.restocker_name }}</div>
            <div><span class="vg-label">Estado</span><span :class="statusTone(selectedOrder.status)">{{ selectedOrder.status_label }}</span></div>
            <div><span class="vg-label">Modo</span>{{ selectedOrder.demand_mode_label }}</div>
            <div><span class="vg-label">Colchón</span>{{ selectedOrder.safety_buffer_pct }} %</div>
            <div><span class="vg-label">Temperatura salida</span>{{ selectedOrder.cold_chamber_exit_temp_celsius ?? '—' }} °C</div>
            <div><span class="vg-label">Creada</span>{{ formatDateTime(selectedOrder.created_at) }}</div>
          </div>
          <div v-if="selectedOrder.notes" class="vg-picking-sheet__notes" style="margin-bottom: 12px;">
            <span class="vg-picking-sheet__label">Notas</span>
            <p>{{ selectedOrder.notes }}</p>
          </div>
          <table class="vg-table">
            <thead>
              <tr>
                <th>SKU / Producto</th>
                <th>Lote</th>
                <th>Caducidad</th>
                <th>Solicitado</th>
                <th>Despachado</th>
                <th>Cuadre</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in selectedOrderItems" :key="item.id">
                <td>
                  <strong>{{ item.product_sku }}</strong>
                  <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ item.product_name }}</div>
                </td>
                <td>{{ item.batch_code || '—' }}</td>
                <td>{{ item.expiration_date || '—' }}</td>
                <td>{{ item.requested_qty }}</td>
                <td>{{ item.dispatched_qty ?? '—' }}</td>
                <td>{{ item.has_reconcile_data ? (item.discrepancy_qty ?? 0) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showDetailModal = false">Cerrar</button>
          <button v-if="selectedOrder" type="button" class="vg-btn vg-btn-primary" @click="openPickingSheet(selectedOrder.id)">🖨️ Hoja de Picking A4</button>
        </template>
      </ModalDialog>

      <!-- Edición en preparación -->
      <ModalDialog v-model="showEditModal" :title="selectedOrder ? 'Editar ' + selectedOrder.order_code : 'Editar orden'" subtitle="Retirar una línea aplica baja lógica; nunca se borra la fila (C1)." size="xl">
        <div v-if="editError" class="vg-alert vg-alert-error" role="alert" data-testid="product-loading-edit-error" style="margin-bottom: 12px;">⚠️ {{ editError }}</div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
          <div>
            <label class="vg-label" for="edit-mode">Modo de demanda</label>
            <select id="edit-mode" v-model="editForm.demand_mode" class="vg-input">
              <option value="ALERTS_ONLY">Solo alertas</option>
              <option value="ALL_GAPS">Ruta completa</option>
              <option value="TODAY_ROUTE">Ruta del día</option>
            </select>
          </div>
          <div>
            <label class="vg-label" for="edit-buffer">Colchón (%)</label>
            <input id="edit-buffer" v-model.number="editForm.safety_buffer_pct" class="vg-input" type="number" min="0" max="50" />
          </div>
        </div>
        <label class="vg-label" for="edit-notes">Notas para almacén</label>
        <textarea id="edit-notes" v-model="editForm.notes" class="vg-input" rows="2" maxlength="500"></textarea>
        <table class="vg-table" style="margin-top: 12px;">
          <thead>
            <tr>
              <th>SKU / Producto</th>
              <th>Motivo</th>
              <th>Cantidad solicitada</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, index) in editItems" :key="item.product_id">
              <td>
                <strong>{{ item.product_sku }}</strong>
                <span v-if="item.requires_cold_chain" style="margin-left: 6px;">❄️</span>
                <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ item.product_name }}</div>
              </td>
              <td>{{ item.reason }}</td>
              <td style="max-width: 130px;">
                <input v-model.number="item.requested_qty" class="vg-input" type="number" min="1" max="99999" />
              </td>
              <td>
                <button type="button" class="vg-btn vg-btn-ghost-danger" @click="removeEditItem(index)">Retirar</button>
              </td>
            </tr>
          </tbody>
        </table>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showEditModal = false">Cancelar</button>
          <button type="button" class="vg-btn vg-btn-primary" :disabled="isSavingOrder" data-testid="product-loading-save-edit" @click="saveOrder">
            {{ isSavingOrder ? 'Guardando…' : 'Guardar orden' }}
          </button>
        </template>
      </ModalDialog>

      <!-- Despacho -->
      <ModalDialog v-model="showDispatchModal" :title="'Despachar ' + (selectedOrder?.order_code || 'orden')" subtitle="Salida de cámara entre -5,0 °C y 4,0 °C con lote y caducidad (Art. II)." size="xl">
        <div v-if="dispatchError" class="vg-alert vg-alert-error" role="alert" data-testid="product-loading-dispatch-error" style="margin-bottom: 12px;">⚠️ {{ dispatchError }}</div>
        <div v-if="dispatchHasColdLine" class="vg-alert vg-alert-warning" style="margin-bottom: 12px;">
          ❄️ Hay líneas refrigeradas con cantidad despachada: la temperatura y el lote son obligatorios.
        </div>
        <div style="max-width: 260px; margin-bottom: 12px;">
          <label class="vg-label" for="dispatch-temperature">Temperatura de salida de cámara (°C)</label>
          <input id="dispatch-temperature" v-model="dispatchForm.cold_chamber_exit_temp_celsius" class="vg-input" type="number" min="-5" max="4" step="0.1" placeholder="Ej. 3,5" />
        </div>
        <div style="overflow-x: auto;">
          <table class="vg-table" data-testid="product-loading-dispatch-table">
            <thead>
              <tr>
                <th>SKU / Producto</th>
                <th>Solicitado</th>
                <th>Despachado</th>
                <th>Lote</th>
                <th>Caducidad</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in dispatchForm.items" :key="item.product_id">
                <td>
                  <strong>{{ item.product_sku }}</strong>
                  <span v-if="item.requires_cold_chain" style="margin-left: 6px;">❄️</span>
                  <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ item.product_name }}</div>
                </td>
                <td>{{ item.requested_qty }}</td>
                <td style="max-width: 110px;">
                  <input v-model.number="item.dispatched_qty" class="vg-input" type="number" min="0" max="99999" />
                </td>
                <td style="min-width: 130px;">
                  <input v-model="item.batch_code" class="vg-input" maxlength="40" :placeholder="item.requires_cold_chain ? 'Obligatorio' : 'SIN-LOTE'" />
                </td>
                <td style="min-width: 150px;">
                  <input v-model="item.expiration_date" class="vg-input" type="date" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div style="margin-top: 10px; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
          Total despachado: <strong>{{ dispatchTotalDispatched }}</strong> unidades.
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showDispatchModal = false">Cancelar</button>
          <button type="button" class="vg-btn vg-btn-primary" :disabled="isDispatching" data-testid="product-loading-submit-dispatch" @click="submitDispatch">
            {{ isDispatching ? 'Despachando…' : 'Confirmar despacho' }}
          </button>
        </template>
      </ModalDialog>

      <!-- Liquidación -->
      <ModalDialog v-model="showReconcileModal" :title="'Liquidar ' + (selectedOrder?.order_code || 'orden')" subtitle="Saldo del lote − abastecidas − mermas = retorno esperado (D3, QA 4)." size="xl">
        <div v-if="reconcileError" class="vg-alert vg-alert-error" role="alert" data-testid="product-loading-reconcile-error" style="margin-bottom: 12px;">⚠️ {{ reconcileError }}</div>
        <div style="overflow-x: auto;">
          <table class="vg-table" data-testid="product-loading-reconcile-table">
            <thead>
              <tr>
                <th>SKU / Producto</th>
                <th>Lote</th>
                <th>Saldo lote</th>
                <th>Abastecidas</th>
                <th>Mermas</th>
                <th>Retorno contado</th>
                <th>Esperado</th>
                <th>Descuadre</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in reconcileForm.items" :key="item.product_id">
                <td>
                  <strong>{{ item.product_sku }}</strong>
                  <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ item.product_name }}</div>
                </td>
                <td>{{ item.batch_code }}</td>
                <td>{{ item.van_stock_before_reconcile }}</td>
                <td style="max-width: 95px;"><input v-model.number="item.restocked_in_machines_qty" class="vg-input" type="number" min="0" max="99999" /></td>
                <td style="max-width: 95px;"><input v-model.number="item.withdrawn_spoilage_qty" class="vg-input" type="number" min="0" max="99999" /></td>
                <td style="max-width: 95px;"><input v-model.number="item.counted_return_qty" class="vg-input" type="number" min="0" max="99999" /></td>
                <td>{{ reconcileExpected(item) }}</td>
                <td>
                  <span :class="reconcileDiscrepancy(item) === 0 ? 'vg-badge vg-badge-success' : 'vg-badge vg-badge-danger'">
                    {{ reconcileDiscrepancy(item) }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div style="margin-top: 12px;">
          <label class="vg-label" for="reconcile-notes">
            Justificación del descuadre {{ reconcileHasDiscrepancy ? '(obligatoria, ≥ 20 caracteres)' : '(opcional)' }}
          </label>
          <textarea id="reconcile-notes" v-model="reconcileForm.notes" class="vg-input" rows="2" maxlength="1000"></textarea>
          <div style="margin-top: 4px; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
            {{ realLength(reconcileForm.notes) }} caracteres reales.
          </div>
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showReconcileModal = false">Cancelar</button>
          <button type="button" class="vg-btn vg-btn-primary" :disabled="isReconciling" data-testid="product-loading-submit-reconcile" @click="submitReconciliation">
            {{ isReconciling ? 'Liquidando…' : 'Liquidar cierre' }}
          </button>
        </template>
      </ModalDialog>

      <!-- Cancelación -->
      <ModalDialog v-model="showCancelModal" :title="'Cancelar ' + cancelOrderCode" subtitle="La justificación queda auditada; no se altera el stock (Art. V.1)." size="md">
        <div v-if="cancelError" class="vg-alert vg-alert-error" role="alert" data-testid="product-loading-cancel-error" style="margin-bottom: 12px;">⚠️ {{ cancelError }}</div>
        <label class="vg-label" for="cancel-reason">Motivo de cancelación (≥ 20 caracteres reales)</label>
        <textarea id="cancel-reason" v-model="cancelReason" class="vg-input" rows="3" maxlength="1000"></textarea>
        <div style="margin-top: 4px; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
          {{ realLength(cancelReason) }} caracteres reales.
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showCancelModal = false">Volver</button>
          <button type="button" class="vg-btn vg-btn-ghost-danger" :disabled="isCancelling" data-testid="product-loading-submit-cancel" @click="submitCancellation">
            {{ isCancelling ? 'Cancelando…' : 'Cancelar orden' }}
          </button>
        </template>
      </ModalDialog>

      <!-- Hoja de Picking A4 -->
      <ModalDialog v-model="showPickingModal" title="Hoja de Picking A4" subtitle="Dos secciones de almacén, sin importes económicos (Art. V.4)." size="xl">
        <div v-if="pickingError" class="vg-alert vg-alert-error" role="alert" style="margin-bottom: 12px;">⚠️ {{ pickingError }}</div>
        <div v-if="isLoadingPicking" style="padding: 20px; color: var(--color-ink-muted);">Cargando hoja…</div>
        <div v-else-if="pickingSheet" class="vg-picking-sheet" data-testid="product-loading-picking-sheet">
          <header class="vg-picking-sheet__header">
            <div>
              <div class="vg-picking-sheet__eyebrow">VendGuard · Hoja de Picking de Producto</div>
              <h3 class="vg-picking-sheet__title">Preparación de carga de furgoneta</h3>
              <p class="vg-picking-sheet__subtitle">Trazabilidad de frío y lotes · Art. II</p>
            </div>
            <div class="vg-picking-sheet__identity">
              <div class="vg-picking-sheet__code">{{ pickingSheet.order_code }}</div>
              <div class="vg-picking-sheet__meta-line">{{ formatDateTime(pickingSheet.created_at) }}</div>
              <div class="vg-picking-sheet__meta-line">{{ pickingSheet.status_label }}</div>
            </div>
          </header>

          <section class="vg-picking-sheet__meta">
            <div>
              <span class="vg-picking-sheet__label">Reponedor</span>
              {{ pickingSheet.restocker_name }}
            </div>
            <div>
              <span class="vg-picking-sheet__label">Código de operario</span>
              {{ pickingSheet.restocker_operator_code || '—' }}
            </div>
            <div>
              <span class="vg-picking-sheet__label">Modo de demanda</span>
              {{ pickingSheet.demand_mode_label }} · {{ pickingSheet.safety_buffer_pct }} %
            </div>
            <div>
              <span class="vg-picking-sheet__label">Zonas cubiertas</span>
              {{ (pickingSheet.zones || []).map((zone) => zone.zone_name || zone.zone_code).join(', ') || '—' }}
            </div>
          </section>

          <section data-testid="picking-cold-section" style="margin-top: 14px;">
            <h4 style="margin: 0 0 6px; font-size: var(--font-size-body-md); color: var(--color-ink-slate);">
              ❄️ Sección 1: Cámara Frigorífica (Cadena de Frío · Salida ≤ 4,0 °C · Art. II)
            </h4>
            <div class="vg-picking-sheet__notes" style="margin-bottom: 8px;">
              <span class="vg-picking-sheet__label">Temperatura de salida de cámara</span>
              <p><strong>{{ pickingSheet.cold_chamber_exit_temp_celsius ?? 'Pendiente de despacho' }}</strong> °C</p>
            </div>
            <table class="vg-picking-sheet__table">
              <thead>
                <tr>
                  <th class="vg-picking-sheet__col-check">[ ]</th>
                  <th>SKU</th>
                  <th>Producto</th>
                  <th>Unidad</th>
                  <th>Lote</th>
                  <th>Caducidad</th>
                  <th>Solicitado</th>
                  <th>Despachado</th>
                  <th>Motivo</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in pickingSheet.sections?.cold_chain?.items || []" :key="item.product_id">
                  <td class="vg-picking-sheet__check">[ ]</td>
                  <td class="vg-picking-sheet__code-cell">{{ item.sku }}</td>
                  <td>{{ item.name }}</td>
                  <td>{{ item.unit_type }}</td>
                  <td>{{ item.batch_code || '—' }}</td>
                  <td>{{ item.expiration_date || '—' }}</td>
                  <td class="vg-picking-sheet__number">{{ item.requested_qty }}</td>
                  <td class="vg-picking-sheet__number">{{ item.dispatched_qty ?? '—' }}</td>
                  <td>{{ item.reason_label }}</td>
                </tr>
                <tr v-if="(pickingSheet.sections?.cold_chain?.items || []).length === 0">
                  <td colspan="9" style="text-align: center; color: var(--color-ink-muted);">Sin líneas refrigeradas.</td>
                </tr>
              </tbody>
            </table>
          </section>

          <section data-testid="picking-general-section" style="margin-top: 18px;">
            <h4 style="margin: 0 0 6px; font-size: var(--font-size-body-md); color: var(--color-ink-slate);">
              📦 Sección 2: Almacén General y Consumibles
            </h4>
            <table class="vg-picking-sheet__table">
              <thead>
                <tr>
                  <th class="vg-picking-sheet__col-check">[ ]</th>
                  <th>SKU</th>
                  <th>Producto</th>
                  <th>Unidad</th>
                  <th>Lote</th>
                  <th>Caducidad</th>
                  <th>Solicitado</th>
                  <th>Despachado</th>
                  <th>Motivo</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in pickingSheet.sections?.general?.items || []" :key="item.product_id">
                  <td class="vg-picking-sheet__check">[ ]</td>
                  <td class="vg-picking-sheet__code-cell">{{ item.sku }}</td>
                  <td>{{ item.name }}</td>
                  <td>{{ item.unit_type }}</td>
                  <td>{{ item.batch_code || '—' }}</td>
                  <td>{{ item.expiration_date || '—' }}</td>
                  <td class="vg-picking-sheet__number">{{ item.requested_qty }}</td>
                  <td class="vg-picking-sheet__number">{{ item.dispatched_qty ?? '—' }}</td>
                  <td>{{ item.reason_label }}</td>
                </tr>
                <tr v-if="(pickingSheet.sections?.general?.items || []).length === 0">
                  <td colspan="9" style="text-align: center; color: var(--color-ink-muted);">Sin líneas de almacén general.</td>
                </tr>
              </tbody>
            </table>
          </section>

          <footer class="vg-picking-sheet__signature">
            <div>
              <span class="vg-picking-sheet__label">Entregado por (Almacén)</span>
              <div class="vg-picking-sheet__signature-line"></div>
            </div>
            <div>
              <span class="vg-picking-sheet__label">Recibido por (Reponedor)</span>
              <div class="vg-picking-sheet__signature-line"></div>
            </div>
          </footer>
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showPickingModal = false">Cerrar</button>
          <button type="button" class="vg-btn vg-btn-primary" :disabled="!pickingSheet" data-testid="product-loading-print-sheet" @click="printPickingSheet">🖨️ Imprimir A4</button>
        </template>
      </ModalDialog>

      <!-- Ajuste de lote -->
      <ModalDialog v-model="showStockAdjustModal" title="Ajuste auditado de lote en furgoneta" subtitle="El saldo anterior y el nuevo quedan en auditoría; nunca se borra la fila (Art. III.1)." size="md">
        <div v-if="stockFormError" class="vg-alert vg-alert-error" role="alert" data-testid="product-loading-stock-error" style="margin-bottom: 12px;">⚠️ {{ stockFormError }}</div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
          <div>
            <label class="vg-label" for="stock-restocker">Reponedor</label>
            <select id="stock-restocker" v-model="stockForm.restocker_id" class="vg-input">
              <option value="">Selecciona…</option>
              <option v-for="restocker in restockerOptions" :key="restocker.id" :value="restocker.id">{{ restocker.label }}</option>
            </select>
          </div>
          <div>
            <label class="vg-label" for="stock-product">Producto</label>
            <select id="stock-product" v-model="stockForm.product_id" class="vg-input">
              <option value="">Selecciona…</option>
              <option v-for="product in products" :key="product.id" :value="product.id">{{ product.sku }} · {{ product.name }}</option>
            </select>
          </div>
          <div>
            <label class="vg-label" for="stock-batch">Lote</label>
            <input id="stock-batch" v-model="stockForm.batch_code" class="vg-input" maxlength="40" placeholder="SIN-LOTE" />
          </div>
          <div>
            <label class="vg-label" for="stock-expiration">Caducidad</label>
            <input id="stock-expiration" v-model="stockForm.expiration_date" class="vg-input" type="date" />
          </div>
          <div>
            <label class="vg-label" for="stock-quantity">Cantidad disponible</label>
            <input id="stock-quantity" v-model.number="stockForm.quantity_available" class="vg-input" type="number" min="0" max="99999" />
          </div>
        </div>
        <div style="margin-top: 12px;">
          <label class="vg-label" for="stock-reason">Motivo del ajuste (≥ 20 caracteres reales)</label>
          <textarea id="stock-reason" v-model="stockForm.reason" class="vg-input" rows="2" maxlength="1000"></textarea>
          <div style="margin-top: 4px; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
            {{ realLength(stockForm.reason) }} caracteres reales.
          </div>
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showStockAdjustModal = false">Cancelar</button>
          <button type="button" class="vg-btn vg-btn-primary" :disabled="isAdjustingStock" data-testid="product-loading-save-stock" @click="submitStockAdjustment">
            {{ isAdjustingStock ? 'Ajustando…' : 'Guardar ajuste' }}
          </button>
        </template>
      </ModalDialog>
    </div>
  `
};

export default CoordinatorProductLoadingTab;
