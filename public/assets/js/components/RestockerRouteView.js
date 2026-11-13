/**
 * VendGuard - RestockerRouteView (RestockerRouteView.js)
 *
 * Vista móvil dedicada al Cargador / Reponedor de Ruta (`RESTOCKER`), Módulos 31
 * y 33 (`D1`, `RF-04.1` a `RF-04.3`, `D3`, `D4`, `C4`, Art. V.4).
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
 *
 * Dogma Vanilla: Vue 3 Options API en ES Module nativo, sin bundler ni
 * dependencias externas. El diseño sigue `docs/design.md` con tokens CSS.
 */

import { api } from '../api.js';
import { store } from '../store.js';

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

      // Consulta de solo lectura del planograma (T-PLN-13, D2, QA 2, Art. V.4)
      showPlanogramModal: false,
      selectedMachine: null,
      planogram: null,
      planogramLoading: false,
      planogramError: '',

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
    }
  },
  mounted() {
    if (this.isAuthenticated) {
      this.loadMyRoute();
      this.loadShiftStatus();
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
        this.activeLoadingOrder = data.active_loading_order || null;
        this.lastReconciledOrder = data.last_reconciled_order || null;
        this.vanProductStocks = Array.isArray(data.van_product_stocks) ? data.van_product_stocks : [];
        this.vanStockKpis = data.van_stock_kpis && typeof data.van_stock_kpis === 'object'
          ? data.van_stock_kpis
          : this.vanStockKpis;
        this.hasExpiredVanLots = data.has_expired_van_lots === true
          || Number(this.vanStockKpis.expired_lots || 0) > 0;
        this.applyShiftResponse(data.shift || {}, 'OFF_SHIFT');
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

    normalizeApiData(response) {
      return response?.data && typeof response.data === 'object' ? response.data : (response || {});
    },

    realLength(value) {
      return String(value ?? '').trim().length;
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
              </div>

              <button
                type="button"
                class="vg-btn vg-btn-secondary vg-btn-sm mt-2"
                data-testid="btn-open-planogram"
                :data-machine-id="machine.id"
                @click="openPlanogram(machine)"
              >
                Ver planograma
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
    </div>
  `
};

export default RestockerRouteView;
