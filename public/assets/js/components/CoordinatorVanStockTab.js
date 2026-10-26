/**
 * VendGuard - CoordinatorVanStockTab Component (CoordinatorVanStockTab.js)
 *
 * Stock de repuestos de las furgonetas para la Coordinación (Módulo 18, RF-03,
 * RF-04.2) y ciclo completo de las órdenes de carga (Módulo 23, RF-01 a RF-04).
 *
 * TRES LECTURAS, UN MISMO PANEL
 *
 *  - **Alertas** (modo por defecto): solo las parejas bajo umbral, ya ordenadas por
 *    severidad desde el servidor. Es la superficie operativa de RF-03.2.
 *  - **Inventario completo**: todas las parejas, con el técnico y su código de
 *    operador. Es la superficie de supervisión de RF-04.2.
 *  - **Órdenes de Carga**: el pre-cálculo de reposición, el lote de almacén, el
 *    ciclo del albarán y la vista imprimible de Picking A4 (Módulo 23).
 *
 * El modo de órdenes separa deliberadamente dos cosas que se confunden con
 * facilidad: la **propuesta** es una lectura que jamás persiste nada y la **orden**
 * es la mutación auditada. Crear una orden desde una propuesta solo precarga el
 * formulario; el alta se confirma con `Guardar la orden`.
 *
 * BAJA LÓGICA, NO BORRADO
 *
 * Retirar una línea del formulario de edición solo la quita del envío. El servidor
 * conserva la fila con `removed_at`/`removed_by` y la reactiva si la pieza vuelve a
 * añadirse (QA 1, Art. III.1). El cliente no ejecuta ni puede ejecutar un DELETE.
 *
 * PICKING A4 SIN DINERO
 *
 * El albarán imprime referencias, cantidades, motivos, casillas de verificación
 * física, destinatario y firmas. Ni un coste, precio o importe entra en esta
 * superficie ni en su hoja de impresión (Art. V.4).
 *
 * DISEÑO
 *
 * Tokens del sistema (`var(--color-…)`, `var(--radius-…)`, `var(--font-…)`) y ni un
 * literal hexadecimal: la deuda cromática está congelada por
 * `DesignTokenDebtRatchetTest`. Los botones del panel visten la escalera `vg-btn`
 * con su peldaño y su tono; no declaran geometría en línea, que es lo que vigila
 * `CoordinatorButtonVariantsGuardTest`. El ámbar queda para chips y alertas de
 * lectura (`STOCK_PARCIAL`, destinatario ausente), nunca para pintar un botón.
 *
 * Dogma Vanilla: Vue 3 Options API con plantilla en navegador, sin bundler ni
 * dependencias externas.
 * Dualismo Lingüístico: código en inglés, texto de interfaz en castellano.
 */

import { api } from '../api.js';
import { ModalDialog } from './ModalDialog.js';

/** Estados del formulario de ajuste de stock, para no repetir cadenas sueltas. */
const EMPTY_FORM = Object.freeze({
  technician_id: '',
  spare_part_id: '',
  quantity_available: '',
  min_threshold: ''
});

/** Rótulos de estado del ciclo del albarán (contrato §5.3). */
const ORDER_STATUS_LABELS = Object.freeze({
  PENDING_PICKING: 'Pendiente de picking',
  DISPATCHED: 'Expedida',
  CONFIRMED_BY_TECH: 'Confirmada por el técnico',
  CANCELLED: 'Cancelada'
});

/** Rótulos legibles de la categoría de pieza (contrato §5.3). */
const CATEGORY_LABELS = Object.freeze({
  HYDRAULIC: 'Hidráulica',
  THERMAL: 'Térmica',
  ELECTRONIC: 'Electrónica',
  MECHANICAL: 'Mecánica',
  CONSUMABLE: 'Consumible',
  OTHER: 'Otra'
});

/** Rótulos de motivo de reposición de una línea (contrato §5.3). */
const REASON_LABELS = Object.freeze({
  BOTH: 'Umbral y solicitud',
  PENDING_REQUEST: 'Solicitud pendiente',
  LOW_STOCK: 'Bajo umbral',
  MANUAL: 'Manual'
});

/** Estados que mantienen vivo el hueco abierto del técnico (D3). */
const OPEN_STATUSES = Object.freeze(['PENDING_PICKING', 'DISPATCHED']);

/** Formulario de orden vacío; se clona siempre para no compartir el array. */
function emptyOrderForm() {
  return {
    id: null,
    technician_id: '',
    technician_name: '',
    notes: '',
    items: []
  };
}

export const CoordinatorVanStockTab = {
  name: 'CoordinatorVanStockTab',

  components: {
    ModalDialog
  },

  data() {
    return {
      mode: 'alert', // 'alert' | 'inventory' | 'orders'
      items: [],
      technicians: [],
      catalogue: [],
      selectedTechnicianId: '',
      isLoading: false,
      errorMessage: '',
      successMessage: '',

      showForm: false,
      isSaving: false,
      formError: '',
      form: { ...EMPTY_FORM },

      // Módulo 23: propuesta pre-calculada, ciclo del albarán y sus diálogos.
      proposals: [],
      orders: [],
      isLoadingOrders: false,
      ordersError: '',
      ordersSuccess: '',
      isGeneratingBatch: false,

      showOrderForm: false,
      isSavingOrder: false,
      orderFormError: '',
      orderForm: emptyOrderForm(),

      showDispatchModal: false,
      isDispatching: false,
      dispatchError: '',
      dispatchForm: { id: null, order_code: '', notes: '', items: [] },

      showCancelOrderModal: false,
      isCancellingOrder: false,
      cancelOrderError: '',
      cancelOrderReason: '',
      cancelOrderTarget: null,

      showPickingModal: false,
      isLoadingPicking: false,
      pickingOrder: null
    };
  },

  computed: {
    isAlertMode() {
      return this.mode === 'alert';
    },

    isInventoryMode() {
      return this.mode === 'inventory';
    },

    isOrdersMode() {
      return this.mode === 'orders';
    },

    isEmpty() {
      return !this.isLoading && this.items.length === 0;
    },

    emptyMessage() {
      return this.isAlertMode
        ? 'Ninguna furgoneta está por debajo de su umbral. El parque está cubierto.'
        : 'Todavía no hay stock declarado en ninguna furgoneta.';
    },

    /** El ajuste exige las cuatro cotas: técnico, pieza, cantidad y umbral (contrato §4.3). */
    canSave() {
      const technicianOk = String(this.form.technician_id).trim() !== '';
      const partOk = String(this.form.spare_part_id).trim() !== '';

      return technicianOk
        && partOk
        && this.isIntegerLike(this.form.quantity_available)
        && this.isIntegerLike(this.form.min_threshold);
    },

    formTitle() {
      return this.form.spare_part_id === '' ? 'Declarar stock de una furgoneta' : 'Ajustar el stock declarado';
    },

    // --- Módulo 23: cifras y estado del ciclo -------------------------------

    openOrders() {
      return this.orders.filter((order) => OPEN_STATUSES.includes(order.status));
    },

    pendingPickingOrders() {
      return this.orders.filter((order) => order.status === 'PENDING_PICKING');
    },

    dispatchedOrders() {
      return this.orders.filter((order) => order.status === 'DISPATCHED');
    },

    /** Cifras de cabecera del modo de órdenes, ya resueltas para la plantilla. */
    orderKpis() {
      return [
        { key: 'open', label: 'Órdenes abiertas', value: this.openOrders.length },
        { key: 'picking', label: 'Pendientes de picking', value: this.pendingPickingOrders.length },
        { key: 'dispatched', label: 'Expedidas sin confirmar', value: this.dispatchedOrders.length }
      ];
    },

    orderFormTitle() {
      return this.orderForm.id === null ? 'Nueva orden de carga' : 'Editar orden de carga';
    },

    orderFormSubtitle() {
      if (this.orderForm.id !== null) {
        return `Orden ${this.orderForm.order_code || `#${this.orderForm.id}`} · ${this.orderForm.technician_name || 'Técnico destinatario'}`;
      }

      return 'La orden nace en Pendiente de picking; el stock no cambia hasta que el técnico confirme la recepción.';
    },

    /** Un formulario de orden exige destinatario y al menos una línea completa. */
    canSaveOrder() {
      if (String(this.orderForm.technician_id).trim() === '') {
        return false;
      }

      if (!Array.isArray(this.orderForm.items) || this.orderForm.items.length === 0) {
        return false;
      }

      return this.orderForm.items.every((line) => this.isOrderLineValid(line));
    },

    /** La expedición exige todas las líneas activas y al menos una unidad servida. */
    dispatchTotalUnits() {
      return this.dispatchForm.items.reduce((total, line) => {
        const quantity = this.isBoundedInteger(line.dispatched_qty, 0, 9999)
          ? Number(line.dispatched_qty)
          : 0;
        return total + quantity;
      }, 0);
    },

    canDispatch() {
      if (this.dispatchForm.items.length === 0) {
        return false;
      }

      const allValid = this.dispatchForm.items.every(
        (line) => this.isBoundedInteger(line.dispatched_qty, 0, 9999)
      );

      return allValid && this.dispatchTotalUnits >= 1;
    },

    /** Contador real de la justificación de cancelación, en puntos de código (Art. V.1). */
    cancelOrderReasonLength() {
      return Array.from(String(this.cancelOrderReason).trim()).length;
    },

    isCancelOrderReasonValid() {
      return this.cancelOrderReasonLength >= 20;
    },

    /** Fecha de emisión visible del albarán; el documento no depende del reloj del servidor. */
    pickingIssuedAt() {
      return new Intl.DateTimeFormat('es-ES', {
        dateStyle: 'short',
        timeStyle: 'short'
      }).format(new Date());
    }
  },

  methods: {
    isIntegerLike(value) {
      return /^-?\d+$/.test(String(value).trim());
    },

    /** Marca de severidad: el descuadre manda sobre el simple bajo umbral (RF-03.2). */
    severityOf(item) {
      if (item.is_negative === true) {
        return { label: 'Descuadre', testid: 'descuadre' };
      }
      if (item.is_below_threshold === true) {
        return { label: 'Reponer', testid: 'reponer' };
      }

      return { label: 'Cubierto', testid: 'cubierto' };
    },

    async loadTechnicians() {
      try {
        const response = await api.coordinator.getUsers({ role: 'TECHNICIAN', status: 'active' });
        const rows = response?.data || response || [];
        this.technicians = (Array.isArray(rows) ? rows : []).map((user) => ({
          id: user.id,
          name: user.name,
          operatorCode: user.operator_code || ''
        }));
      } catch (error) {
        // El catálogo de técnicos solo alimenta el formulario; la tabla sigue siendo legible.
        this.technicians = [];
      }
    },

    async loadCatalogue() {
      try {
        const response = await api.coordinator.getSparePartsCatalog({ is_active: 1 });
        const rows = response?.data || response || [];
        this.catalogue = Array.isArray(rows) ? rows : [];
      } catch (error) {
        this.catalogue = [];
      }
    },

    async loadStock() {
      this.isLoading = true;
      this.errorMessage = '';

      const params = {};
      if (String(this.selectedTechnicianId).trim() !== '') {
        params.technician_id = this.selectedTechnicianId;
      }
      if (this.isAlertMode) {
        params.below_threshold = 1;
      }

      try {
        const response = await api.coordinator.getVanStocks(params);
        const data = response?.data || response || {};
        this.items = Array.isArray(data.items) ? data.items : [];
      } catch (error) {
        this.errorMessage = error.message || 'No se pudo cargar el stock de las furgonetas.';
        this.items = [];
      } finally {
        this.isLoading = false;
      }
    },

    setMode(mode) {
      if (this.mode === mode) {
        return;
      }
      this.mode = mode;

      if (mode === 'orders') {
        this.loadOrderWorkspace();
        return;
      }

      this.loadStock();
    },

    applyTechnicianFilter() {
      this.loadStock();
    },

    /** Abre el formulario de ajuste; con fila, precarga el saldo actual de esa pareja. */
    openForm(item = null) {
      this.successMessage = '';
      this.formError = '';
      this.showForm = true;

      if (item) {
        this.form = {
          technician_id: item.technician_id,
          spare_part_id: item.spare_part_id,
          quantity_available: item.quantity_available,
          min_threshold: item.min_threshold
        };
        return;
      }

      this.form = {
        ...EMPTY_FORM,
        technician_id: this.selectedTechnicianId === '' ? '' : Number(this.selectedTechnicianId)
      };
    },

    closeForm() {
      this.showForm = false;
      this.formError = '';
      this.form = { ...EMPTY_FORM };
    },

    async saveStock() {
      if (!this.canSave || this.isSaving) {
        return;
      }

      this.isSaving = true;
      this.formError = '';
      this.successMessage = '';

      try {
        const response = await api.coordinator.setVanStock({
          technician_id: Number(this.form.technician_id),
          spare_part_id: Number(this.form.spare_part_id),
          quantity_available: Number(this.form.quantity_available),
          min_threshold: Number(this.form.min_threshold)
        });
        const saved = response?.data || response || {};
        const previous = saved.previous_quantity_available;

        this.successMessage = previous === null || previous === undefined
          ? `Stock declarado: ${saved.quantity_available} unidades.`
          : `Stock actualizado: ${saved.quantity_available} unidades (antes ${previous}).`;

        this.showForm = false;
        this.form = { ...EMPTY_FORM };
        await this.loadStock();
      } catch (error) {
        this.formError = error.message || 'No se pudo guardar el stock de la furgoneta.';
      } finally {
        this.isSaving = false;
      }
    },

    // ---------------------------------------------------------------------
    // Módulo 23 · Propuestas, órdenes y Picking A4
    // ---------------------------------------------------------------------

    /**
     * Carga en paralelo la propuesta viva y el listado canónico de órdenes.
     * La lectura no bloquea el modo de alertas ni comparte su estado de error.
     */
    async loadOrderWorkspace() {
      this.isLoadingOrders = true;
      this.ordersError = '';

      try {
        const [proposalResponse, orderResponse] = await Promise.all([
          api.coordinator.getVanRestockProposals(),
          api.coordinator.getVanRestockOrders()
        ]);

        const proposalPayload = proposalResponse?.data || proposalResponse || {};
        const orderPayload = orderResponse?.data || orderResponse || {};

        this.proposals = Array.isArray(proposalPayload.proposals) ? proposalPayload.proposals : [];
        this.orders = Array.isArray(orderPayload.items) ? orderPayload.items : [];
      } catch (error) {
        this.ordersError = error.message || 'No se pudo cargar el ciclo de órdenes de carga.';
        this.proposals = [];
        this.orders = [];
      } finally {
        this.isLoadingOrders = false;
      }
    },

    orderStatusLabel(status) {
      return ORDER_STATUS_LABELS[String(status || '').toUpperCase()] || String(status || '—');
    },

    orderStatusChipClass(status) {
      const normalized = String(status || '').toUpperCase();

      if (normalized === 'CONFIRMED_BY_TECH') {
        return 'vg-chip vg-chip-success';
      }
      if (normalized === 'CANCELLED') {
        return 'vg-chip vg-chip-danger';
      }
      if (normalized === 'DISPATCHED') {
        return 'vg-chip vg-chip-warning';
      }

      return 'vg-chip';
    },

    reasonLabel(reason) {
      return REASON_LABELS[String(reason || '').toUpperCase()] || String(reason || '—');
    },

    categoryLabel(category) {
      return CATEGORY_LABELS[String(category || '').toUpperCase()] || String(category || '—');
    },

    /**
     * Alerta de lectura del destinatario (C2, EARS 03.3): ausencia vigente o
     * baja sobrevenida. El servidor publica ambas marcas y la interfaz no debe
     * olvidar ninguna de las dos.
     */
    hasRecipientAlert(recipient) {
      return Boolean(recipient)
        && (recipient.technician_is_active === false || recipient.recipient_currently_absent === true);
    },

    formatDateTime(value) {
      if (value === null || value === undefined || value === '') {
        return '—';
      }

      const normalized = String(value).includes('T')
        ? String(value)
        : String(value).replace(' ', 'T');
      const parsed = new Date(normalized);

      if (Number.isNaN(parsed.getTime())) {
        return String(value);
      }

      return new Intl.DateTimeFormat('es-ES', {
        dateStyle: 'short',
        timeStyle: 'short'
      }).format(parsed);
    },

    proposalUnits(proposal) {
      return (proposal?.items || []).reduce(
        (total, line) => total + Number(line.suggested_qty || 0),
        0
      );
    },

    isOpenOrder(order) {
      return OPEN_STATUSES.includes(String(order?.status || '').toUpperCase());
    },

    /**
     * Abre el alta desde una propuesta o la edición de una orden existente.
     * En edición, el técnico queda fijado: el servidor no permite cambiarlo.
     */
    openOrderForm(order = null, proposal = null) {
      this.ordersSuccess = '';
      this.orderFormError = '';

      if (order) {
        this.orderForm = {
          id: order.id,
          order_code: order.order_code,
          technician_id: order.technician_id,
          technician_name: order.technician_name,
          notes: order.notes || '',
          items: (order.items || []).map((line) => ({
            spare_part_id: line.spare_part_id,
            requested_qty: line.requested_qty,
            reason: line.reason || 'MANUAL'
          }))
        };
        this.showOrderForm = true;
        return;
      }

      if (proposal) {
        this.orderForm = {
          ...emptyOrderForm(),
          technician_id: proposal.technician_id,
          technician_name: proposal.technician_name,
          items: (proposal.items || []).map((line) => ({
            spare_part_id: line.spare_part_id,
            requested_qty: line.suggested_qty,
            reason: line.reason || 'MANUAL'
          }))
        };
        this.showOrderForm = true;
        return;
      }

      this.orderForm = {
        ...emptyOrderForm(),
        technician_id: this.technicians[0]?.id || ''
      };
      this.showOrderForm = true;
    },

    closeOrderForm() {
      this.showOrderForm = false;
      this.orderFormError = '';
      this.orderForm = emptyOrderForm();
    },

    /** Entero exacto dentro de un rango: una cantidad no admite decimales ni notación científica. */
    isBoundedInteger(value, minimum, maximum) {
      const text = String(value).trim();
      if (!/^\d+$/.test(text)) {
        return false;
      }

      const parsed = Number(text);
      return Number.isSafeInteger(parsed) && parsed >= minimum && parsed <= maximum;
    },

    isOrderLineValid(line) {
      return this.isBoundedInteger(line?.spare_part_id, 1, Number.MAX_SAFE_INTEGER)
        && this.isBoundedInteger(line?.requested_qty, 1, 9999);
    },

    linePartOptions(index) {
      const used = new Set(
        this.orderForm.items
          .map((line, position) => (position === index ? null : Number(line.spare_part_id)))
          .filter((id) => Number.isFinite(id) && id > 0)
      );

      return this.catalogue.filter((part) => !used.has(Number(part.id)));
    },

    addOrderLine() {
      this.orderForm.items.push({
        spare_part_id: '',
        requested_qty: 1,
        reason: 'MANUAL'
      });
    },

    removeOrderLine(index) {
      this.orderForm.items.splice(index, 1);
    },

    async saveOrder() {
      if (!this.canSaveOrder || this.isSavingOrder) {
        return;
      }

      this.isSavingOrder = true;
      this.orderFormError = '';
      this.ordersSuccess = '';

      const items = this.orderForm.items.map((line) => ({
        spare_part_id: Number(line.spare_part_id),
        requested_qty: Number(line.requested_qty),
        reason: line.reason || 'MANUAL'
      }));

      try {
        const isEdit = this.orderForm.id !== null;
        const response = isEdit
          ? await api.coordinator.updateVanRestockOrder(this.orderForm.id, {
            notes: this.orderForm.notes,
            items
          })
          : await api.coordinator.createVanRestockOrder({
            technician_id: Number(this.orderForm.technician_id),
            notes: this.orderForm.notes,
            items
          });

        const payload = response?.data || response || {};
        this.ordersSuccess = payload.message || (isEdit
          ? 'Orden de carga actualizada correctamente.'
          : 'Orden de carga creada en estado Pendiente de Picking.');

        this.closeOrderForm();
        await this.loadOrderWorkspace();
      } catch (error) {
        this.orderFormError = error.message || 'No se pudo guardar la orden de carga.';
      } finally {
        this.isSavingOrder = false;
      }
    },

    /** Genera las órdenes del lote matinal y publica el desglose de omitidas. */
    async generateBatch() {
      if (this.isGeneratingBatch) {
        return;
      }

      this.isGeneratingBatch = true;
      this.ordersError = '';
      this.ordersSuccess = '';

      try {
        const response = await api.coordinator.generateVanRestockBatch({});
        const payload = response?.data || response || {};
        const created = Number(payload.created_count || 0);
        const skipped = Number(payload.skipped_count || 0);

        this.ordersSuccess = payload.message
          || `Generación por lotes completada: ${created} órdenes creadas, ${skipped} técnicos omitidos.`;
        await this.loadOrderWorkspace();
      } catch (error) {
        this.ordersError = error.message || 'No se pudo generar el lote de órdenes de carga.';
      } finally {
        this.isGeneratingBatch = false;
      }
    },

    /** Abre la expedición con todas las líneas activas y la cantidad sugerida. */
    openDispatchModal(order) {
      if (!order || order.status !== 'PENDING_PICKING') {
        return;
      }

      this.dispatchError = '';
      this.dispatchForm = {
        id: order.id,
        order_code: order.order_code,
        notes: order.notes || '',
        items: (order.items || []).map((line) => ({
          spare_part_id: line.spare_part_id,
          part_code: line.part_code,
          part_name: line.part_name,
          requested_qty: line.requested_qty,
          dispatched_qty: line.requested_qty
        }))
      };
      this.showDispatchModal = true;
    },

    closeDispatchModal() {
      this.showDispatchModal = false;
      this.dispatchError = '';
      this.dispatchForm = { id: null, order_code: '', notes: '', items: [] };
    },

    async submitDispatch() {
      if (!this.canDispatch || this.isDispatching) {
        return;
      }

      this.isDispatching = true;
      this.dispatchError = '';
      this.ordersSuccess = '';

      try {
        const payloadItems = this.dispatchForm.items.map((line) => ({
          spare_part_id: Number(line.spare_part_id),
          dispatched_qty: Number(line.dispatched_qty)
        }));

        const response = await api.coordinator.dispatchVanRestockOrder(this.dispatchForm.id, {
          notes: this.dispatchForm.notes,
          items: payloadItems
        });
        const payload = response?.data || response || {};

        this.ordersSuccess = payload.message
          || 'Orden marcada como Expedida. Pendiente de confirmación por el técnico.';
        this.closeDispatchModal();
        await this.loadOrderWorkspace();
      } catch (error) {
        this.dispatchError = error.message || 'No se pudo expedir la orden de carga.';
      } finally {
        this.isDispatching = false;
      }
    },

    openCancelOrderModal(order) {
      if (!this.isOpenOrder(order)) {
        return;
      }

      this.cancelOrderTarget = order;
      this.cancelOrderReason = '';
      this.cancelOrderError = '';
      this.showCancelOrderModal = true;
    },

    closeCancelOrderModal() {
      this.showCancelOrderModal = false;
      this.cancelOrderTarget = null;
      this.cancelOrderReason = '';
      this.cancelOrderError = '';
    },

    async submitCancelOrder() {
      if (!this.isCancelOrderReasonValid || this.isCancellingOrder) {
        this.cancelOrderError = 'La justificación debe contener al menos 20 caracteres reales (Art. V.1).';
        return;
      }

      this.isCancellingOrder = true;
      this.cancelOrderError = '';
      this.ordersSuccess = '';

      try {
        const response = await api.coordinator.cancelVanRestockOrder(
          this.cancelOrderTarget?.id,
          this.cancelOrderReason
        );
        const payload = response?.data || response || {};

        this.ordersSuccess = payload.message || 'La orden de carga ha sido cancelada.';
        this.closeCancelOrderModal();
        await this.loadOrderWorkspace();
      } catch (error) {
        this.cancelOrderError = error.message || 'No se pudo cancelar la orden de carga.';
      } finally {
        this.isCancellingOrder = false;
      }
    },

    /** Carga el detalle íntegro antes de abrir el papel para no imprimir una fila obsoleta. */
    async openPickingModal(order) {
      if (!order || (order.id === undefined && order.id === null)) {
        return;
      }

      this.isLoadingPicking = true;
      this.ordersError = '';

      try {
        const response = await api.coordinator.getVanRestockOrder(order.id);
        this.pickingOrder = response?.data || response || null;
        this.showPickingModal = this.pickingOrder !== null;
      } catch (error) {
        this.ordersError = error.message || 'No se pudo cargar el albarán de Picking.';
      } finally {
        this.isLoadingPicking = false;
      }
    },

    closePickingModal() {
      this.showPickingModal = false;
      this.pickingOrder = null;
    },

    /** Impresión nativa: la hoja A4 la resuelve `@media print`, sin librerías PDF. */
    printPickingSheet() {
      const printer = typeof globalThis !== 'undefined' ? globalThis.print : null;

      if (typeof printer === 'function') {
        printer();
      }
    }
  },

  mounted() {
    this.loadTechnicians();
    this.loadCatalogue();
    this.loadStock();
  },

  template: `
    <div class="coordinator-van-stock-tab" data-testid="coord-van-stock-tab" style="padding-top: 8px;">
      <!-- Hoja del Picking A4: se enlaza al montar la pestaña para que el papel
           tenga sus reglas incluso cuando la aplicación no la cargue globalmente. -->
      <link rel="stylesheet" href="/assets/css/styles.css" />

      <!-- Avisos -->
      <div
        v-if="errorMessage"
        role="alert"
        data-testid="coord-van-stock-error"
        class="vg-alert vg-alert-error"
        style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; padding: 12px 16px;"
      >
        <span>⚠️ {{ errorMessage }}</span>
        <button type="button" class="vg-btn vg-btn-icon vg-btn-ghost vg-btn-ghost-danger" title="Cerrar el aviso" @click="errorMessage = ''">&times;</button>
      </div>

      <div
        v-if="successMessage"
        data-testid="coord-van-stock-success"
        class="vg-alert"
        style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; padding: 12px 16px; background-color: var(--color-success-bg); border: 1px solid var(--color-success); color: var(--color-success-text);"
      >
        <span>✅ {{ successMessage }}</span>
        <button type="button" class="vg-btn vg-btn-icon vg-btn-ghost vg-btn-ghost-success" title="Cerrar el aviso" @click="successMessage = ''">&times;</button>
      </div>

      <!-- Controles: modo de lectura, filtro por furgoneta y alta -->
      <div style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px; margin-bottom: 16px;">
        <div class="vg-action-group" role="tablist" aria-label="Vistas del stock de furgonetas">
          <button
            type="button"
            class="vg-btn vg-btn-tab"
            :class="{ 'vg-btn-tab-active': isAlertMode }"
            data-testid="coord-van-stock-mode-alert"
            @click="setMode('alert')"
          >
            ⚠️ Alertas
          </button>
          <button
            type="button"
            class="vg-btn vg-btn-tab"
            :class="{ 'vg-btn-tab-active': isInventoryMode }"
            data-testid="coord-van-stock-mode-inventory"
            @click="setMode('inventory')"
          >
            📦 Inventario completo
          </button>
          <button
            type="button"
            class="vg-btn vg-btn-tab"
            :class="{ 'vg-btn-tab-active': isOrdersMode }"
            data-testid="coord-van-stock-mode-orders"
            @click="setMode('orders')"
          >
            🚚 Órdenes de Carga
          </button>
        </div>

        <label
          v-if="!isOrdersMode"
          style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);"
        >
          Furgoneta
          <select
            v-model="selectedTechnicianId"
            data-testid="coord-van-stock-filter"
            class="vg-input"
            style="min-width: 220px;"
            @change="applyTechnicianFilter"
          >
            <option value="">Todas las furgonetas</option>
            <option v-for="tech in technicians" :key="tech.id" :value="String(tech.id)">
              {{ tech.name }}<template v-if="tech.operatorCode"> · {{ tech.operatorCode }}</template>
            </option>
          </select>
        </label>

        <button
          v-if="!isOrdersMode"
          type="button"
          class="vg-btn vg-btn-primary"
          data-testid="coord-van-stock-new"
          @click="openForm()"
        >
          ＋ Declarar stock
        </button>
      </div>

      <!-- ================================================================= -->
      <!-- MODO ÓRDENES DE CARGA (Módulo 23)                                 -->
      <!-- ================================================================= -->
      <section v-if="isOrdersMode" data-testid="coord-van-stock-orders-panel">
        <header style="display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 16px;">
          <div>
            <h3 style="margin: 0; font-family: var(--font-display); font-size: var(--font-size-heading-md); font-weight: 600; color: var(--color-ink);">
              🚚 Órdenes de Carga de Furgoneta
            </h3>
            <p style="margin: 4px 0 0; font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-ink-muted);">
              Propuesta de reposición, preparación en almacén y recepción móvil en un solo ciclo auditado.
            </p>
          </div>
          <div class="vg-action-group">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              data-testid="coord-restock-batch"
              :disabled="isGeneratingBatch"
              @click="generateBatch"
            >
              {{ isGeneratingBatch ? 'Generando…' : '⚡ Generar lote' }}
            </button>
            <button
              type="button"
              class="vg-btn vg-btn-primary"
              data-testid="coord-restock-order-create"
              @click="openOrderForm()"
            >
              ＋ Nueva orden
            </button>
          </div>
        </header>

        <div class="vg-alert vg-alert-error" v-if="ordersError" role="alert" data-testid="coord-restock-error" style="margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
          <span>⚠️ {{ ordersError }}</span>
          <button type="button" class="vg-btn vg-btn-icon vg-btn-ghost vg-btn-ghost-danger" title="Cerrar el aviso" @click="ordersError = ''">&times;</button>
        </div>

        <div class="vg-alert" v-if="ordersSuccess" data-testid="coord-restock-success" style="margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; background-color: var(--color-success-bg); border: 1px solid var(--color-success); color: var(--color-success-text);">
          <span>✅ {{ ordersSuccess }}</span>
          <button type="button" class="vg-btn vg-btn-icon vg-btn-ghost vg-btn-ghost-success" title="Cerrar el aviso" @click="ordersSuccess = ''">&times;</button>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin-bottom: 16px;">
          <div
            v-for="kpi in orderKpis"
            :key="kpi.key"
            :data-testid="'coord-restock-kpi-' + kpi.key"
            style="padding: 12px; background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card);"
          >
            <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); font-weight: 700; line-height: 1.1; color: var(--color-ink);">
              {{ kpi.value }}
            </div>
            <div style="margin-top: 2px; font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
              {{ kpi.label }}
            </div>
          </div>
        </div>

        <div v-if="isLoadingOrders" data-testid="coord-restock-loading" style="padding: 16px; color: var(--color-ink-muted); font-family: var(--font-body); font-size: var(--font-size-body-sm);">
          Cargando propuestas y órdenes de carga…
        </div>

        <template v-else>
          <!-- Propuesta pre-calculada por técnico (RF-01) -->
          <section data-testid="coord-restock-proposals" style="margin-bottom: 20px;">
            <h4 style="margin: 0 0 8px; font-family: var(--font-display); font-size: var(--font-size-heading-md); font-weight: 600; color: var(--color-ink);">
              Propuesta de reposición
            </h4>

            <div v-if="proposals.length === 0" data-testid="coord-restock-proposals-empty" style="padding: 16px; background-color: var(--color-surface-2); border: 1px dashed var(--color-hairline); border-radius: var(--radius-card); color: var(--color-ink-muted); font-family: var(--font-body); font-size: var(--font-size-body-sm);">
              Ningún técnico activo tiene déficit de stock ni solicitudes pendientes que reponer.
            </div>

            <div v-else style="overflow-x: auto;">
              <table data-testid="coord-restock-proposals-table" style="width: 100%; border-collapse: collapse; font-family: var(--font-body); font-size: var(--font-size-body-sm);">
                <thead>
                  <tr style="text-align: left; color: var(--color-ink-secondary);">
                    <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Furgoneta</th>
                    <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Referencias</th>
                    <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Unidades sugeridas</th>
                    <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Estado</th>
                    <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline); text-align: right;">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="proposal in proposals"
                    :key="proposal.technician_id"
                    :data-testid="'coord-restock-proposal-row-' + proposal.technician_id"
                  >
                    <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-ink);">
                      <div style="font-weight: 600;">{{ proposal.technician_name }}</div>
                      <div v-if="hasRecipientAlert(proposal)" data-testid="coord-restock-absent-warning" style="margin-top: 4px; display: inline-block; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); font-size: var(--font-size-caption); font-weight: 700;">
                        ⚠️ Destinatario actualmente ausente / inactivo
                      </div>
                    </td>
                    <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-ink-secondary);">
                      {{ (proposal.items || []).length }}
                    </td>
                    <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-ink); font-family: var(--font-display); font-weight: 700;">
                      {{ proposalUnits(proposal) }}
                    </td>
                    <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft);">
                      <span v-if="proposal.open_order_id" class="vg-chip vg-chip-warning">
                        {{ orderStatusLabel(proposal.open_order_status) }} · {{ proposal.open_order_id }}
                      </span>
                      <span v-else style="color: var(--color-ink-muted);">Sin orden abierta</span>
                    </td>
                    <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right;">
                      <div class="vg-row-actions">
                        <button
                          type="button"
                          class="vg-btn vg-btn-sm vg-btn-primary-outline"
                          :data-testid="'coord-restock-proposal-create-' + proposal.technician_id"
                          :disabled="proposal.open_order_id || (proposal.items || []).length === 0"
                          @click="openOrderForm(null, proposal)"
                        >
                          Crear orden
                        </button>
                        <button
                          v-if="proposal.open_order_id"
                          type="button"
                          class="vg-btn vg-btn-sm vg-btn-secondary"
                          :data-testid="'coord-restock-proposal-open-' + proposal.technician_id"
                          @click="openPickingModal({ id: proposal.open_order_id })"
                        >
                          Ver albarán
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <!-- Ciclo de órdenes -->
          <section data-testid="coord-restock-orders">
            <h4 style="margin: 0 0 8px; font-family: var(--font-display); font-size: var(--font-size-heading-md); font-weight: 600; color: var(--color-ink);">
              Órdenes registradas
            </h4>

            <div v-if="orders.length === 0" data-testid="coord-restock-orders-empty" style="padding: 16px; background-color: var(--color-surface-2); border: 1px dashed var(--color-hairline); border-radius: var(--radius-card); color: var(--color-ink-muted); font-family: var(--font-body); font-size: var(--font-size-body-sm);">
              Todavía no hay órdenes de carga. Genera el lote o crea la primera desde una propuesta.
            </div>

            <div v-else style="overflow-x: auto;">
              <table data-testid="coord-restock-orders-table" style="width: 100%; border-collapse: collapse; font-family: var(--font-body); font-size: var(--font-size-body-sm);">
                <thead>
                  <tr style="text-align: left; color: var(--color-ink-secondary);">
                    <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Orden</th>
                    <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Destinatario</th>
                    <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Estado</th>
                    <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline); text-align: right;">Unidades</th>
                    <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Creada</th>
                    <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline); text-align: right;">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="order in orders"
                    :key="order.id"
                    :data-testid="'coord-restock-order-row-' + order.id"
                  >
                    <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); font-family: monospace; font-weight: 700; color: var(--color-ink);">
                      {{ order.order_code }}
                    </td>
                    <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-ink);">
                      <div style="font-weight: 600;">{{ order.technician_name }}</div>
                      <div v-if="hasRecipientAlert(order)" :data-testid="'coord-restock-order-absent-' + order.id" style="margin-top: 4px; display: inline-block; padding: 2px 6px; border-radius: var(--radius-interactive); background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); font-size: var(--font-size-caption); font-weight: 700;">
                        ⚠️ Destinatario actualmente ausente / inactivo
                      </div>
                    </td>
                    <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft);">
                      <span :class="orderStatusChipClass(order.status)">{{ orderStatusLabel(order.status) }}</span>
                    </td>
                    <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right; color: var(--color-ink); font-family: var(--font-display); font-weight: 700;">
                      <template v-if="order.total_dispatched_units !== null">
                        {{ order.total_dispatched_units }} / {{ order.total_requested_units }}
                      </template>
                      <template v-else>{{ order.total_requested_units }}</template>
                    </td>
                    <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-ink-secondary);">
                      {{ formatDateTime(order.created_at) }}
                    </td>
                    <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right;">
                      <div class="vg-row-actions">
                        <button
                          type="button"
                          class="vg-btn vg-btn-sm vg-btn-primary-outline"
                          :data-testid="'coord-restock-order-print-' + order.id"
                          @click="openPickingModal(order)"
                        >
                          Ver / imprimir
                        </button>
                        <button
                          v-if="order.status === 'PENDING_PICKING'"
                          type="button"
                          class="vg-btn vg-btn-sm vg-btn-secondary"
                          :data-testid="'coord-restock-order-edit-' + order.id"
                          @click="openOrderForm(order)"
                        >
                          Editar
                        </button>
                        <button
                          v-if="order.status === 'PENDING_PICKING'"
                          type="button"
                          class="vg-btn vg-btn-sm vg-btn-primary"
                          :data-testid="'coord-restock-order-dispatch-' + order.id"
                          @click="openDispatchModal(order)"
                        >
                          Expedir
                        </button>
                        <button
                          v-if="isOpenOrder(order)"
                          type="button"
                          class="vg-btn vg-btn-sm vg-btn-danger-soft"
                          :data-testid="'coord-restock-order-cancel-' + order.id"
                          @click="openCancelOrderModal(order)"
                        >
                          Cancelar
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </template>
      </section>

      <!-- ================================================================= -->
      <!-- MODOS DE STOCK (Módulo 18)                                        -->
      <!-- ================================================================= -->
      <template v-else>
        <!-- Formulario de ajuste absoluto (contrato §4.3) -->
        <div
          v-if="showForm"
          data-testid="coord-van-stock-form"
          style="margin-bottom: 16px; padding: 16px; background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card);"
        >
          <h3 style="margin: 0 0 12px; font-family: var(--font-display); font-size: var(--font-size-heading-md); color: var(--color-ink);">
            {{ formTitle }}
          </h3>

          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
            <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
              Técnico
              <select v-model="form.technician_id" class="vg-input" data-testid="coord-van-stock-form-technician">
                <option value="">Selecciona un técnico</option>
                <option v-for="tech in technicians" :key="tech.id" :value="tech.id">
                  {{ tech.name }}<template v-if="tech.operatorCode"> · {{ tech.operatorCode }}</template>
                </option>
              </select>
            </label>

            <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
              Pieza del catálogo
              <select v-model="form.spare_part_id" class="vg-input" data-testid="coord-van-stock-form-part">
                <option value="">Selecciona una pieza</option>
                <option v-for="part in catalogue" :key="part.id" :value="part.id">
                  {{ part.part_code }} · {{ part.name }}
                </option>
              </select>
            </label>

            <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
              Unidades disponibles
              <input v-model="form.quantity_available" type="number" step="1" class="vg-input" data-testid="coord-van-stock-form-quantity" />
            </label>

            <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
              Umbral mínimo
              <input v-model="form.min_threshold" type="number" step="1" min="0" class="vg-input" data-testid="coord-van-stock-form-threshold" />
            </label>
          </div>

          <p style="margin: 10px 0 0; font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
            La cantidad es el saldo que queda en la furgoneta, no una suma. Un saldo negativo se
            admite y queda marcado como descuadre.
          </p>

          <div
            v-if="formError"
            role="alert"
            data-testid="coord-van-stock-form-error"
            style="margin-top: 12px; padding: 10px 12px; background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); border-radius: var(--radius-interactive); color: var(--color-error-text); font-family: var(--font-body); font-size: var(--font-size-body-sm);"
          >
            ⚠️ {{ formError }}
          </div>

          <div class="vg-action-group" style="margin-top: 14px;">
            <button
              type="button"
              class="vg-btn vg-btn-primary"
              data-testid="coord-van-stock-form-save"
              :disabled="!canSave || isSaving"
              @click="saveStock"
            >
              {{ isSaving ? 'Guardando…' : 'Guardar el stock' }}
            </button>
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              data-testid="coord-van-stock-form-cancel"
              @click="closeForm"
            >
              Cancelar
            </button>
          </div>
        </div>

        <!-- Tabla del parque -->
        <div
          v-if="isLoading"
          data-testid="coord-van-stock-loading"
          style="padding: 16px; color: var(--color-ink-muted); font-family: var(--font-body); font-size: var(--font-size-body-sm);"
        >
          Cargando el stock de las furgonetas…
        </div>

        <div
          v-else-if="isEmpty"
          data-testid="coord-van-stock-empty"
          style="padding: 16px; background-color: var(--color-surface-2); border: 1px dashed var(--color-hairline); border-radius: var(--radius-card); color: var(--color-ink-muted); font-family: var(--font-body); font-size: var(--font-size-body-sm);"
        >
          {{ emptyMessage }}
        </div>

        <div v-else style="overflow-x: auto;">
          <table
            data-testid="coord-van-stock-table"
            style="width: 100%; border-collapse: collapse; font-family: var(--font-body); font-size: var(--font-size-body-sm);"
          >
            <thead>
              <tr style="text-align: left; color: var(--color-ink-secondary);">
                <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Furgoneta</th>
                <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Pieza</th>
                <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline); text-align: right;">Disponible</th>
                <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline); text-align: right;">Umbral</th>
                <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Estado</th>
                <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline); text-align: right;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in items"
                :key="item.technician_id + '-' + item.spare_part_id"
                :data-testid="'coord-van-stock-row-' + item.technician_id + '-' + item.spare_part_id"
              >
                <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-ink);">
                  <div style="font-weight: 600;">{{ item.technician_name }}</div>
                  <div v-if="item.operator_code" style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ item.operator_code }}</div>
                </td>
                <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-ink);">
                  <div style="font-weight: 600;">{{ item.name }}</div>
                  <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ item.part_code }}</div>
                </td>
                <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right; font-weight: 700; color: var(--color-ink);">
                  {{ item.quantity_available }}
                </td>
                <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right; color: var(--color-ink-secondary);">
                  {{ item.min_threshold }}
                </td>
                <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft);">
                  <span
                    :data-testid="'coord-van-stock-badge-' + severityOf(item).testid"
                    :style="severityOf(item).testid === 'descuadre'
                      ? 'display: inline-block; padding: 2px 8px; border-radius: var(--radius-interactive); background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); font-size: var(--font-size-caption); font-weight: 700;'
                      : (severityOf(item).testid === 'reponer'
                        ? 'display: inline-block; padding: 2px 8px; border-radius: var(--radius-interactive); background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); font-size: var(--font-size-caption); font-weight: 700;'
                        : 'display: inline-block; padding: 2px 8px; border-radius: var(--radius-interactive); background-color: var(--color-success-bg); border: 1px solid var(--color-success); color: var(--color-success-text); font-size: var(--font-size-caption); font-weight: 700;')"
                  >
                    {{ severityOf(item).label }}
                  </span>
                </td>
                <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right;">
                  <button
                    type="button"
                    class="vg-btn vg-btn-sm vg-btn-secondary"
                    :data-testid="'coord-van-stock-adjust-' + item.technician_id + '-' + item.spare_part_id"
                    @click="openForm(item)"
                  >
                    Ajustar
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <!-- ================================================================= -->
      <!-- MODAL: ALTA / EDICIÓN DE ORDEN DE CARGA                           -->
      <!-- ================================================================= -->
      <ModalDialog
        v-model="showOrderForm"
        :title="orderFormTitle"
        :subtitle="orderFormSubtitle"
        size="lg"
        @close="closeOrderForm"
      >
        <form data-testid="coord-restock-order-form" @submit.prevent="saveOrder">
          <div v-if="orderForm.id === null" style="margin-bottom: 14px;">
            <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
              Técnico destinatario
              <select v-model="orderForm.technician_id" class="vg-input" data-testid="coord-restock-order-form-technician">
                <option value="">Selecciona un técnico</option>
                <option v-for="tech in technicians" :key="tech.id" :value="tech.id">
                  {{ tech.name }}<template v-if="tech.operatorCode"> · {{ tech.operatorCode }}</template>
                </option>
              </select>
            </label>
          </div>

          <p v-else style="margin: 0 0 14px; font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-ink-secondary);">
            Destinatario: <strong>{{ orderForm.technician_name }}</strong>
          </p>

          <label style="display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
            Notas para almacén
            <textarea v-model="orderForm.notes" class="vg-input vg-textarea" rows="2" data-testid="coord-restock-order-form-notes"></textarea>
          </label>

          <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px;">
            <h4 style="margin: 0; font-family: var(--font-display); font-size: var(--font-size-heading-md); font-weight: 600; color: var(--color-ink);">
              Líneas de la orden
            </h4>
            <button
              type="button"
              class="vg-btn vg-btn-sm vg-btn-primary-outline"
              data-testid="coord-restock-order-form-add-line"
              @click="addOrderLine"
            >
              ＋ Añadir pieza
            </button>
          </div>

          <div v-if="orderForm.items.length === 0" data-testid="coord-restock-order-form-empty" style="padding: 12px; border: 1px dashed var(--color-hairline); border-radius: var(--radius-interactive); color: var(--color-ink-muted); font-family: var(--font-body); font-size: var(--font-size-body-sm);">
            La orden necesita al menos una pieza. Añádela para continuar.
          </div>

          <div
            v-for="(line, index) in orderForm.items"
            :key="'order-line-' + index"
            data-testid="coord-restock-order-form-line"
            style="display: grid; grid-template-columns: minmax(0, 2fr) minmax(90px, 0.6fr) minmax(150px, 1fr) auto; gap: 8px; align-items: end; padding: 10px 0; border-bottom: 1px solid var(--color-hairline-soft);"
          >
            <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
              Pieza
              <select v-model="line.spare_part_id" class="vg-input" :data-testid="'coord-restock-order-form-part-' + index">
                <option value="">Selecciona una pieza</option>
                <option v-for="part in linePartOptions(index)" :key="part.id" :value="part.id">
                  {{ part.part_code }} · {{ part.name }}
                </option>
              </select>
            </label>

            <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
              Cantidad
              <input v-model="line.requested_qty" type="number" min="1" max="9999" step="1" class="vg-input" :data-testid="'coord-restock-order-form-qty-' + index" />
            </label>

            <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
              Motivo
              <select v-model="line.reason" class="vg-input" :data-testid="'coord-restock-order-form-reason-' + index">
                <option value="BOTH">Umbral y solicitud</option>
                <option value="PENDING_REQUEST">Solicitud pendiente</option>
                <option value="LOW_STOCK">Bajo umbral</option>
                <option value="MANUAL">Manual</option>
              </select>
            </label>

            <button
              type="button"
              class="vg-btn vg-btn-sm vg-btn-danger-soft"
              :data-testid="'coord-restock-order-form-remove-' + index"
              @click="removeOrderLine(index)"
            >
              Quitar
            </button>
          </div>

          <div v-if="orderFormError" role="alert" data-testid="coord-restock-order-form-error" class="vg-alert vg-alert-error" style="margin-top: 12px;">
            ⚠️ {{ orderFormError }}
          </div>
        </form>

        <template #footer>
          <div class="vg-action-group">
            <button type="button" class="vg-btn vg-btn-secondary" @click="closeOrderForm">Cancelar</button>
            <button
              type="button"
              class="vg-btn vg-btn-primary"
              data-testid="coord-restock-order-form-save"
              :disabled="!canSaveOrder || isSavingOrder"
              @click="saveOrder"
            >
              {{ isSavingOrder ? 'Guardando…' : 'Guardar la orden' }}
            </button>
          </div>
        </template>
      </ModalDialog>

      <!-- ================================================================= -->
      <!-- MODAL: EXPEDICIÓN DESDE ALMACÉN                                   -->
      <!-- ================================================================= -->
      <ModalDialog
        v-model="showDispatchModal"
        title="Expedir la orden de carga"
        :subtitle="dispatchForm.order_code ? ('Orden ' + dispatchForm.order_code + ' · declara las unidades servidas de cada línea') : ''"
        size="lg"
        @close="closeDispatchModal"
      >
        <div data-testid="coord-restock-dispatch-modal">
          <p style="margin: 0 0 12px; font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-ink-secondary);">
            La suma servida debe ser al menos 1 unidad. Una línea sin material se declara con 0; si no hay nada, cancela la orden.
          </p>

          <div
            v-for="(line, index) in dispatchForm.items"
            :key="'dispatch-line-' + line.spare_part_id"
            style="display: grid; grid-template-columns: minmax(0, 1fr) 120px; gap: 10px; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--color-hairline-soft);"
          >
            <div style="min-width: 0; font-family: var(--font-body);">
              <div style="font-family: monospace; font-size: var(--font-size-caption); font-weight: 700; color: var(--color-ink-muted);">{{ line.part_code }}</div>
              <div style="font-weight: 600; color: var(--color-ink);">{{ line.part_name }}</div>
              <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">Solicitado: {{ line.requested_qty }} u.</div>
            </div>
            <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
              Servido
              <input v-model="line.dispatched_qty" type="number" min="0" max="9999" step="1" class="vg-input" :data-testid="'coord-restock-dispatch-qty-' + index" />
            </label>
          </div>

          <label style="display: flex; flex-direction: column; gap: 4px; margin-top: 12px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
            Notas del despacho
            <textarea v-model="dispatchForm.notes" class="vg-input vg-textarea" rows="2" data-testid="coord-restock-dispatch-notes"></textarea>
          </label>

          <p data-testid="coord-restock-dispatch-total" style="margin: 10px 0 0; font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-ink);">
            Total servido: <strong>{{ dispatchTotalUnits }}</strong> unidades.
          </p>

          <div v-if="dispatchError" role="alert" data-testid="coord-restock-dispatch-error" class="vg-alert vg-alert-error" style="margin-top: 12px;">
            ⚠️ {{ dispatchError }}
          </div>
        </div>

        <template #footer>
          <div class="vg-action-group">
            <button type="button" class="vg-btn vg-btn-secondary" @click="closeDispatchModal">Cancelar</button>
            <button
              type="button"
              class="vg-btn vg-btn-primary"
              data-testid="coord-restock-dispatch-submit"
              :disabled="!canDispatch || isDispatching"
              @click="submitDispatch"
            >
              {{ isDispatching ? 'Expidiendo…' : 'Marcar como expedida' }}
            </button>
          </div>
        </template>
      </ModalDialog>

      <!-- ================================================================= -->
      <!-- MODAL: CANCELACIÓN AUDITADA (Art. V.1)                           -->
      <!-- ================================================================= -->
      <ModalDialog
        v-model="showCancelOrderModal"
        title="Cancelar la orden de carga"
        :subtitle="cancelOrderTarget ? (cancelOrderTarget.order_code + ' · ' + cancelOrderTarget.technician_name) : ''"
        size="md"
        @close="closeCancelOrderModal"
      >
        <div data-testid="coord-restock-cancel-modal">
          <p style="margin: 0 0 12px; font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-ink-secondary); line-height: 1.45;">
            La orden pasa a <strong>Cancelada</strong> y el stock de la furgoneta no cambia. La justificación queda en el historial inmutable y es obligatoria (Art. V.1).
          </p>

          <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
            Justificación de la cancelación
            <textarea v-model="cancelOrderReason" class="vg-input vg-textarea" rows="4" data-testid="coord-restock-cancel-reason"></textarea>
          </label>

          <p
            data-testid="coord-restock-cancel-counter"
            style="margin: 6px 0 0; font-family: var(--font-body); font-size: var(--font-size-caption);"
            :style="{ color: isCancelOrderReasonValid ? 'var(--color-success-text)' : 'var(--color-ink-muted)' }"
          >
            {{ cancelOrderReasonLength }} / 20 caracteres reales mínimos
          </p>

          <div v-if="cancelOrderError" role="alert" data-testid="coord-restock-cancel-error" class="vg-alert vg-alert-error" style="margin-top: 12px;">
            ⚠️ {{ cancelOrderError }}
          </div>
        </div>

        <template #footer>
          <div class="vg-action-group">
            <button type="button" class="vg-btn vg-btn-secondary" @click="closeCancelOrderModal">Volver</button>
            <button
              type="button"
              class="vg-btn vg-btn-danger"
              data-testid="coord-restock-cancel-submit"
              :disabled="!isCancelOrderReasonValid || isCancellingOrder"
              @click="submitCancelOrder"
            >
              {{ isCancellingOrder ? 'Cancelando…' : 'Cancelar la orden' }}
            </button>
          </div>
        </template>
      </ModalDialog>

      <!-- ================================================================= -->
      <!-- MODAL: ALBARÁN DE PICKING A4 (RF-03, Art. V.4)                   -->
      <!-- ================================================================= -->
      <ModalDialog
        v-model="showPickingModal"
        title="Albarán de Picking A4"
        :subtitle="pickingOrder ? (pickingOrder.order_code + ' · documento sin importes') : ''"
        size="lg"
        @close="closePickingModal"
      >
        <div v-if="isLoadingPicking" style="padding: 12px; font-family: var(--font-body); color: var(--color-ink-muted);">
          Cargando el albarán…
        </div>

        <div v-else-if="pickingOrder" class="vg-picking-sheet" data-testid="coord-picking-sheet">
          <header class="vg-picking-sheet__header">
            <div>
              <div class="vg-picking-sheet__eyebrow">VendGuard · Carga de furgoneta</div>
              <h2 class="vg-picking-sheet__title">Albarán de Picking</h2>
              <p class="vg-picking-sheet__subtitle">Documento de preparación y recepción física · sin importes (Art. V.4)</p>
            </div>
            <div class="vg-picking-sheet__identity">
              <div class="vg-picking-sheet__code" data-testid="coord-picking-order-code">{{ pickingOrder.order_code }}</div>
              <div class="vg-picking-sheet__meta-line">{{ orderStatusLabel(pickingOrder.status) }}</div>
              <div class="vg-picking-sheet__meta-line">Fecha de la orden: {{ formatDateTime(pickingOrder.created_at) }}</div>
              <div class="vg-picking-sheet__meta-line">Impreso: {{ pickingIssuedAt }}</div>
            </div>
          </header>

          <section class="vg-picking-sheet__meta">
            <div>
              <span class="vg-picking-sheet__label">Destinatario</span>
              <strong>{{ pickingOrder.technician_name }}</strong>
            </div>
            <div>
              <span class="vg-picking-sheet__label">Operador</span>
              <strong>{{ pickingOrder.technician_operator_code || '—' }}</strong>
            </div>
            <div>
              <span class="vg-picking-sheet__label">Preparado por</span>
              <strong>{{ pickingOrder.created_by_name || '—' }}</strong>
            </div>
            <div>
              <span class="vg-picking-sheet__label">Expedido</span>
              <strong>{{ formatDateTime(pickingOrder.dispatched_at) }}</strong>
            </div>
            <div>
              <span class="vg-picking-sheet__label">Confirmado</span>
              <strong>{{ formatDateTime(pickingOrder.confirmed_at) }}</strong>
            </div>
          </section>

          <div v-if="hasRecipientAlert(pickingOrder)" class="vg-alert vg-alert-warning" data-testid="coord-picking-absent-warning">
            ⚠️ Destinatario actualmente ausente / inactivo. La caja se prepara igualmente y se entrega a su incorporación.
          </div>

          <table class="vg-picking-sheet__table" data-testid="coord-picking-table">
            <colgroup>
              <col class="vg-picking-sheet__col-check" />
              <col class="vg-picking-sheet__col-reference" />
              <col class="vg-picking-sheet__col-piece" />
              <col class="vg-picking-sheet__col-category" />
              <col class="vg-picking-sheet__col-quantity" />
              <col class="vg-picking-sheet__col-quantity" />
              <col class="vg-picking-sheet__col-quantity" />
              <col class="vg-picking-sheet__col-quantity" />
              <col class="vg-picking-sheet__col-quantity" />
              <col class="vg-picking-sheet__col-reason" />
            </colgroup>
            <thead>
              <tr>
                <th scope="col">Hecho</th>
                <th scope="col">Referencia</th>
                <th scope="col">Pieza</th>
                <th scope="col">Categoría</th>
                <th scope="col" class="vg-picking-sheet__number">Saldo</th>
                <th scope="col" class="vg-picking-sheet__number">Umbral</th>
                <th scope="col" class="vg-picking-sheet__number">Pedidas</th>
                <th scope="col" class="vg-picking-sheet__number">Solicitado</th>
                <th scope="col" class="vg-picking-sheet__number">Servido</th>
                <th scope="col">Motivo</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="line in (pickingOrder.items || [])" :key="line.spare_part_id">
                <td class="vg-picking-sheet__check">[ ]</td>
                <td class="vg-picking-sheet__code-cell">{{ line.part_code }}</td>
                <td>{{ line.part_name }}</td>
                <td>{{ categoryLabel(line.category) }}</td>
                <td class="vg-picking-sheet__number">{{ line.current_quantity_snapshot }}</td>
                <td class="vg-picking-sheet__number">{{ line.min_threshold_snapshot }}</td>
                <td class="vg-picking-sheet__number">{{ line.pending_request_qty_snapshot }}</td>
                <td class="vg-picking-sheet__number">{{ line.requested_qty }}</td>
                <td class="vg-picking-sheet__number">{{ line.dispatched_qty === null ? '—' : line.dispatched_qty }}</td>
                <td>{{ reasonLabel(line.reason) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="7">Total de la orden</td>
                <td class="vg-picking-sheet__number">{{ pickingOrder.total_requested_units }}</td>
                <td class="vg-picking-sheet__number">{{ pickingOrder.total_dispatched_units === null ? '—' : pickingOrder.total_dispatched_units }}</td>
                <td></td>
              </tr>
            </tfoot>
          </table>

          <section v-if="pickingOrder.notes" class="vg-picking-sheet__notes">
            <span class="vg-picking-sheet__label">Notas para almacén</span>
            <p>{{ pickingOrder.notes }}</p>
          </section>

          <section class="vg-picking-sheet__signature">
            <div>
              <span class="vg-picking-sheet__label">Preparado por (almacén)</span>
              <div class="vg-picking-sheet__signature-line"></div>
            </div>
            <div>
              <span class="vg-picking-sheet__label">Recibido por (técnico)</span>
              <div class="vg-picking-sheet__signature-line"></div>
            </div>
          </section>
        </div>

        <template #footer>
          <div class="vg-action-group">
            <button type="button" class="vg-btn vg-btn-secondary" @click="closePickingModal">Cerrar</button>
            <button
              type="button"
              class="vg-btn vg-btn-primary"
              data-testid="coord-picking-print"
              :disabled="!pickingOrder"
              @click="printPickingSheet"
            >
              🖨️ Imprimir A4
            </button>
          </div>
        </template>
      </ModalDialog>
    </div>
  `
};

export default CoordinatorVanStockTab;
