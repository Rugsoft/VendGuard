/**
 * VendGuard - CoordinatorRefundsTab (CoordinatorRefundsTab.js)
 *
 * Global refunds inbox for the Coordination dashboard
 * (RF-REF-03, RF-REF-07, RF-REF-08, RNF-REF-05).
 *
 * Responsibilities:
 * 1. List every refund case with full financial detail, filters by status and
 *    a highlighted alert for cases awaiting double approval.
 * 2. Grant the formal approval of the final payable amount (> 10,00 € or
 *    material discrepancies) without ever exceeding the 50,00 € antifraud cap.
 * 3. Register the digital settlement with its bank / Bizum reference.
 * 4. Reject a claim with a mandatory written justification of >= 20 characters.
 *
 * This is the only projection allowed to show IBAN / Bizum phone: without them
 * Coordination cannot pay anyone (Art. V.4, CoordinatorRefundViewDTO).
 *
 * Dogma Vanilla: Vue 3 ESM component, zero dependencies.
 * Dualismo Lingüístico: identifiers and comments in English, UI copy in Spanish.
 */

import { api } from '../api.js';
import { ModalDialog } from './ModalDialog.js';

const STATUS_META = {
  PENDING_INSPECTION: {
    label: 'Pendiente de inspección técnica',
    color: 'var(--color-ink-secondary)',
    bg: 'var(--color-canvas)',
    border: 'var(--color-hairline)'
  },
  DEPOSITED_AT_RECEPTION: {
    label: 'Efectivo en conserjería',
    color: 'var(--color-success-text)',
    bg: 'var(--color-success-bg)',
    border: 'var(--color-success)'
  },
  VERIFIED_PENDING_PAYMENT: {
    label: 'Verificado, pendiente de pago',
    color: 'var(--color-primary-dark)',
    bg: 'var(--color-primary-subtle)',
    border: 'var(--color-primary)'
  },
  REQUIRES_COORDINATOR_APPROVAL: {
    label: 'Requiere visto bueno de coordinación',
    color: 'var(--color-warning-text)',
    bg: 'var(--color-warning-bg)',
    border: 'var(--color-warning)'
  },
  PENDING_CONTACT: {
    label: 'Pendiente de contacto del afectado',
    color: 'var(--color-warning-text)',
    bg: 'var(--color-warning-bg)',
    border: 'var(--color-warning)'
  },
  PAID_DIGITAL: {
    label: 'Reembolsado por vía digital',
    color: 'var(--color-success-text)',
    bg: 'var(--color-success-bg)',
    border: 'var(--color-success)'
  },
  REFUNDED_IN_HAND: {
    label: 'Reembolsado en mano',
    color: 'var(--color-success-text)',
    bg: 'var(--color-success-bg)',
    border: 'var(--color-success)'
  },
  REJECTED: {
    label: 'Desestimado',
    color: 'var(--color-error-text)',
    bg: 'var(--color-urgency-critical-bg)',
    border: 'var(--color-error)'
  }
};

const APPROVAL_THRESHOLD = 10;
const MAX_APPROVED_AMOUNT = 50;
const MIN_JUSTIFICATION_LENGTH = 20;

export const CoordinatorRefundsTab = {
  name: 'CoordinatorRefundsTab',
  components: { ModalDialog },
  data() {
    return {
      loading: false,
      error: '',
      items: [],
      total: 0,
      requiresApprovalTotal: 0,
      totals: { claimed_amount: 0, payable_amount: 0 },

      statusFilter: '',
      approvalOnly: false,
      searchQuery: '',

      actionMessage: '',

      // Double approval modal
      showApprovalModal: false,
      selectedRefund: null,
      approvedAmount: '',
      approvalNotes: '',
      isApproving: false,
      approvalError: '',

      // Digital settlement modal
      showPaymentModal: false,
      paymentAmount: '',
      paymentReference: '',
      isPaying: false,
      paymentError: '',

      // Motivated rejection modal
      showRejectModal: false,
      rejectionReason: '',
      isRejecting: false,
      rejectionError: '',

      approvalThreshold: APPROVAL_THRESHOLD,
      maxApprovedAmount: MAX_APPROVED_AMOUNT,
      minJustificationLength: MIN_JUSTIFICATION_LENGTH
    };
  },
  computed: {
    hasItems() {
      return this.items.length > 0;
    },
    isFiltered() {
      return this.statusFilter !== '' || this.approvalOnly || this.searchQuery.trim() !== '';
    },
    filteredItems() {
      const query = this.searchQuery.trim().toLowerCase();
      if (!query) return this.items;

      return this.items.filter((item) => [
        item.incident_code,
        item.machine_code,
        item.location_name,
        item.claimant_name
      ].some((value) => String(value ?? '').toLowerCase().includes(query)));
    },
    hasApprovalPending() {
      return this.requiresApprovalTotal > 0;
    },
    statusOptions() {
      return Object.entries(STATUS_META).map(([value, meta]) => ({ value, label: meta.label }));
    },
    isApprovalAmountValid() {
      const amount = Number(this.approvedAmount);
      return this.approvedAmount !== ''
        && Number.isFinite(amount)
        && amount > 0
        && amount <= MAX_APPROVED_AMOUNT;
    },
    isPaymentValid() {
      const amount = Number(this.paymentAmount);
      return this.paymentReference.trim().length > 0
        && this.paymentAmount !== ''
        && Number.isFinite(amount)
        && amount > 0;
    },
    isRejectionValid() {
      return this.rejectionReason.trim().length >= MIN_JUSTIFICATION_LENGTH;
    }
  },
  mounted() {
    this.loadRefunds();
  },
  methods: {
    /**
     * Loads the global inbox honoring the active server-side filters.
     */
    async loadRefunds() {
      this.loading = true;
      this.error = '';

      try {
        const data = await api.coordinator.getRefunds(this.buildFilters());
        this.items = Array.isArray(data?.items) ? data.items : [];
        this.total = Number(data?.total ?? this.items.length);
        this.requiresApprovalTotal = Number(data?.requires_approval_total ?? 0);
        this.totals = {
          claimed_amount: Number(data?.totals?.claimed_amount ?? 0),
          payable_amount: Number(data?.totals?.payable_amount ?? 0)
        };
      } catch (err) {
        this.error = err?.message || 'No se pudo cargar la bandeja global de reintegros.';
        this.items = [];
      } finally {
        this.loading = false;
      }
    },

    /**
     * Only the filters the backend contract understands are sent to the API.
     * @returns {{status?: string, requires_approval_only?: number}}
     */
    buildFilters() {
      const filters = {};
      if (this.statusFilter !== '') filters.status = this.statusFilter;
      if (this.approvalOnly) filters.requires_approval_only = 1;
      return filters;
    },

    setStatusFilter(value) {
      this.statusFilter = String(value ?? '');
      this.loadRefunds();
    },

    toggleApprovalOnly(value) {
      this.approvalOnly = Boolean(value);
      this.loadRefunds();
    },

    /**
     * Only cases flagged by the domain as requiring double approval can be
     * approved; the backend rejects the rest with 409 (RF-REF-03).
     */
    canApprove(refund) {
      return Boolean(refund) && refund.requires_approval === true;
    },

    canPay(refund) {
      return Boolean(refund) && refund.awaits_payment === true;
    },

    /**
     * Rejection follows the lifecycle graph: `REJECTED` is only reachable from
     * `REQUIRES_COORDINATOR_APPROVAL`, so offering the action anywhere else
     * would just make the coordinator collect a 409 (RF-REF-08).
     */
    canReject(refund) {
      return Boolean(refund) && String(refund.status) === 'REQUIRES_COORDINATOR_APPROVAL';
    },

    openApprovalModal(refund) {
      if (!this.canApprove(refund)) return;
      this.selectedRefund = refund;
      this.approvedAmount = String(refund.payable_amount ?? refund.claimed_amount ?? '');
      this.approvalNotes = '';
      this.approvalError = '';
      this.actionMessage = '';
      this.showApprovalModal = true;
    },

    closeApprovalModal() {
      if (this.isApproving) return;
      this.resetApprovalModal();
    },

    resetApprovalModal() {
      this.showApprovalModal = false;
      this.selectedRefund = null;
      this.approvedAmount = '';
      this.approvalNotes = '';
      this.approvalError = '';
    },

    setApprovalAmount(value) {
      this.approvedAmount = String(value ?? '');
      this.approvalError = '';
    },

    /**
     * Grants the double approval. The antifraud cap of 50,00 € is enforced
     * client-side as well as in the domain (RF-REF-03).
     */
    async submitApproval() {
      if (!this.selectedRefund || this.isApproving) return;

      if (!this.isApprovalAmountValid) {
        this.approvalError = 'Indique una cuantía autorizada mayor que 0,00 € y no superior al tope antifraude de 50,00 €.';
        return;
      }

      const refund = this.selectedRefund;
      const amount = Number(this.approvedAmount);
      this.isApproving = true;
      this.approvalError = '';

      try {
        const result = await api.coordinator.approveRefund(refund.id, amount, this.approvalNotes.trim());
        const status = result?.status || 'VERIFIED_PENDING_PAYMENT';

        this.applyUpdate(refund.id, {
          status,
          status_label: this.statusMeta(status).label,
          approved_amount: Number(result?.approved_amount ?? amount),
          coordinator_decision: result?.coordinator_decision || 'APPROVED',
          coordinator_justification: result?.justification ?? (this.approvalNotes.trim() || null),
          requires_approval: false,
          awaits_payment: true
        });

        this.actionMessage = `Visto bueno registrado: ${refund.claimant_name} · ${this.formatAmount(amount)}. El expediente queda listo para su liquidación.`;
        this.resetApprovalModal();
      } catch (err) {
        this.approvalError = err?.message || 'No se pudo registrar el visto bueno del expediente.';
      } finally {
        this.isApproving = false;
      }
    },

    openPaymentModal(refund) {
      if (!this.canPay(refund)) return;
      this.selectedRefund = refund;
      // RF-REF-03: la liquidación solo admite el importe aprobado o, sin
      // visto bueno formal, el reclamado. `payable_amount` es una sugerencia
      // de trabajo y cae al importe verificado cuando hay discrepancia dentro
      // del 20% tolerado, cifra que el backend ya no acepta como liquidación.
      this.paymentAmount = String(refund.approved_amount ?? refund.claimed_amount ?? '');
      this.paymentReference = '';
      this.paymentError = '';
      this.actionMessage = '';
      this.showPaymentModal = true;
    },

    closePaymentModal() {
      if (this.isPaying) return;
      this.resetPaymentModal();
    },

    resetPaymentModal() {
      this.showPaymentModal = false;
      this.selectedRefund = null;
      this.paymentAmount = '';
      this.paymentReference = '';
      this.paymentError = '';
    },

    setPaymentAmount(value) {
      this.paymentAmount = String(value ?? '');
      this.paymentError = '';
    },

    /**
     * Registers the digital settlement with its bank / Bizum reference
     * (RF-REF-07). VendGuard never talks to a payment gateway (Art. VI).
     */
    async submitPayment() {
      if (!this.selectedRefund || this.isPaying) return;

      if (this.paymentReference.trim() === '') {
        this.paymentError = 'Indique la referencia del justificante bancario o de Bizum con la que se ha liquidado.';
        return;
      }

      if (!this.isPaymentValid) {
        this.paymentError = 'Indique un importe liquidado mayor que 0,00 €.';
        return;
      }

      const refund = this.selectedRefund;
      const reference = this.paymentReference.trim();
      this.isPaying = true;
      this.paymentError = '';

      try {
        const result = await api.coordinator.payRefund(refund.id, reference, Number(this.paymentAmount));
        const status = result?.status || 'PAID_DIGITAL';

        this.applyUpdate(refund.id, {
          status,
          status_label: this.statusMeta(status).label,
          payment_reference: result?.payment_reference || reference,
          paid_at: result?.paid_at || null,
          approved_amount: result?.approved_amount ?? refund.approved_amount ?? null,
          awaits_payment: false
        });

        this.actionMessage = `Pago digital registrado con la referencia ${reference}. Expediente liquidado y auditado.`;
        this.resetPaymentModal();
      } catch (err) {
        this.paymentError = err?.message || 'No se pudo registrar la liquidación digital.';
      } finally {
        this.isPaying = false;
      }
    },

    openRejectModal(refund) {
      if (!this.canReject(refund)) return;
      this.selectedRefund = refund;
      this.rejectionReason = '';
      this.rejectionError = '';
      this.actionMessage = '';
      this.showRejectModal = true;
    },

    closeRejectModal() {
      if (this.isRejecting) return;
      this.resetRejectModal();
    },

    resetRejectModal() {
      this.showRejectModal = false;
      this.selectedRefund = null;
      this.rejectionReason = '';
      this.rejectionError = '';
    },

    /**
     * Rejects a claim with a mandatory justification of >= 20 characters
     * (RF-REF-08). Nothing is deleted: the case stays for audit (Art. III).
     */
    async submitRejection() {
      if (!this.selectedRefund || this.isRejecting) return;

      if (!this.isRejectionValid) {
        this.rejectionError = 'La motivación del rechazo debe contener al menos 20 caracteres descriptivos (RF-REF-08).';
        return;
      }

      const refund = this.selectedRefund;
      const reason = this.rejectionReason.trim();
      this.isRejecting = true;
      this.rejectionError = '';

      try {
        const result = await api.coordinator.rejectRefund(refund.id, reason);
        const status = result?.status || 'REJECTED';

        this.applyUpdate(refund.id, {
          status,
          status_label: this.statusMeta(status).label,
          coordinator_decision: result?.coordinator_decision || 'REJECTED',
          coordinator_justification: result?.justification || reason,
          requires_approval: false,
          awaits_payment: false
        });

        this.actionMessage = `Reclamación desestimada con motivo registrado. El expediente se conserva para su auditoría (Art. III).`;
        this.resetRejectModal();
      } catch (err) {
        this.rejectionError = err?.message || 'No se pudo desestimar la reclamación.';
      } finally {
        this.isRejecting = false;
      }
    },

    /**
     * Reflects an action result in the local inbox without reloading.
     */
    applyUpdate(refundId, patch) {
      this.items = this.items.map((item) => (
        Number(item.id) === Number(refundId) ? { ...item, ...patch } : item
      ));

      this.requiresApprovalTotal = this.items.filter((item) => item.requires_approval === true).length;
    },

    statusMeta(status) {
      return STATUS_META[status] || {
        label: status || 'Estado desconocido',
        color: 'var(--color-ink-secondary)',
        bg: 'var(--color-canvas)',
        border: 'var(--color-hairline)'
      };
    },

    compensationLabel(method) {
      switch (method) {
        case 'EN_MANO_SEDE':
          return 'Entrega en mano en la sede';
        case 'BIZUM':
          return 'Bizum';
        case 'TRANSFERENCIA_BANCARIA':
          return 'Transferencia bancaria';
        default:
          return method || 'Canal no especificado';
      }
    },

    findingLabel(finding) {
      switch (finding) {
        case 'FOUND_PHYSICAL':
          return 'Dinero recuperado físicamente';
        case 'CONFIRMED_NO_CASH':
          return 'Fallo verificado sin efectivo recuperado';
        case 'UNVERIFIED_NO_CASH':
          return 'Sin evidencia de retención de saldo';
        default:
          return 'Pendiente de dictamen técnico';
      }
    },

    custodyLabel(action) {
      switch (action) {
        case 'LEFT_AT_RECEPTION':
          return 'Efectivo depositado en conserjería';
        case 'HELD_FOR_CENTRAL':
          return 'Efectivo custodiado para caja central';
        default:
          return 'Sin custodia declarada';
      }
    },

    discrepancyLabel(refund) {
      if (refund?.discrepancy_ratio === null || refund?.discrepancy_ratio === undefined) return '';
      return `Recuperado ${this.formatAmount(refund.recovered_amount)} vs Reclamado ${this.formatAmount(refund.claimed_amount)}`;
    },

    formatAmount(value) {
      const amount = Number(value);
      if (!Number.isFinite(amount)) return '0.00 €';
      return `${amount.toFixed(2)} €`;
    },

    formatDate(dateStr) {
      if (!dateStr) return 'N/D';
      try {
        return new Date(dateStr).toLocaleDateString('es-ES', {
          day: '2-digit',
          month: '2-digit',
          year: 'numeric'
        });
      } catch (e) {
        return dateStr;
      }
    }
  },
  template: `
    <section class="vg-coordinator-refunds-tab" data-testid="coordinator-refunds-tab">
      <!-- Header card: liability summary -->
      <div
        class="vg-card"
        style="border-radius: var(--radius-card, 8px); padding: 18px 22px; margin-bottom: 20px; background: var(--color-surface-card); display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px;"
      >
        <div>
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
            <span style="font-size: 20px;">💶</span>
            <h2 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 19px; font-weight: 700; color: var(--color-ink, var(--color-ink)); margin: 0;">
              Reintegros e Importe Retenido
            </h2>
          </div>
          <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-slate, var(--color-ink-slate)); margin: 0;">
            Bandeja global con doble visto bueno para importes superiores a 10,00 € o discrepancias, y liquidación digital con justificante bancario.
          </p>
        </div>

        <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
          <div style="padding: 8px 14px; border-radius: var(--radius-card, 8px); border: 1px solid var(--color-hairline, var(--color-hairline)); background: var(--color-surface-card); font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-secondary);">
            Expedientes: <strong style="color: var(--color-ink-slate);">{{ total }}</strong>
          </div>
          <div
            data-testid="approval-pending-counter"
            :style="{
              padding: '8px 14px',
              borderRadius: 'var(--radius-card, 8px)',
              border: '1px solid ' + (hasApprovalPending ? 'var(--color-warning)' : 'var(--color-hairline)'),
              background: hasApprovalPending ? 'var(--color-warning-bg)' : 'var(--color-surface-card)',
              fontFamily: 'var(--font-body, Inter, sans-serif)',
              fontSize: '13px',
              color: hasApprovalPending ? 'var(--color-warning-text)' : 'var(--color-ink-secondary)'
            }"
          >
            Pendientes de visto bueno: <strong>{{ requiresApprovalTotal }}</strong>
          </div>
          <div style="padding: 8px 14px; border-radius: var(--radius-card, 8px); border: 1px solid var(--color-success); background: var(--color-success-bg); font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-success-text);">
            Pendiente de pago: <strong>{{ formatAmount(totals.payable_amount) }}</strong>
          </div>
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            style="border-radius: var(--radius-interactive, 4px); font-size: 13px; height: 36px;"
            :disabled="loading"
            @click="loadRefunds"
          >
            <span v-if="!loading">🔄 Actualizar</span>
            <span v-else>Cargando...</span>
          </button>
        </div>
      </div>

      <!-- Double approval alert (RF-REF-03) -->
      <div
        v-if="hasApprovalPending"
        role="alert"
        data-testid="approval-alert"
        style="background-color: var(--color-warning-bg); border: 2px solid var(--color-warning); color: var(--color-warning-text); padding: 14px 18px; border-radius: var(--radius-card, 8px); margin-bottom: 20px; display: flex; align-items: flex-start; gap: 12px;"
      >
        <span style="font-size: 20px; line-height: 1;">⚠️</span>
        <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; line-height: 1.5;">
          <strong>{{ requiresApprovalTotal }} expediente(s) requieren doble autorización</strong> por superar los 10,00 €, presentar discrepancias de saldo o no constar evidencia de efectivo recuperado (RF-REF-03).
        </div>
      </div>

      <!-- Action feedback -->
      <div
        v-if="actionMessage"
        role="status"
        data-testid="refund-action-success"
        style="background-color: var(--color-success-bg); border: 1px solid var(--color-success); color: var(--color-success-text); padding: 12px 18px; border-radius: var(--radius-card, 8px); margin-bottom: 20px; font-family: var(--font-body, Inter, sans-serif); font-size: 13.5px; font-weight: 600;"
      >
        ✅ {{ actionMessage }}
      </div>

      <!-- Filters toolbar -->
      <div
        class="vg-card"
        style="border-radius: var(--radius-card, 8px); padding: 14px 18px; margin-bottom: 20px; background: var(--color-surface-card); display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px;"
      >
        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 12px; flex: 1 1 auto;">
          <select
            :value="statusFilter"
            class="vg-select"
            data-testid="refund-status-filter"
            style="max-width: 230px; height: 36px; border-radius: var(--radius-interactive, 4px);"
            @change="setStatusFilter($event.target.value)"
          >
            <option value="">Todos los estados</option>
            <option v-for="option in statusOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>

          <label style="display: flex; align-items: center; gap: 8px; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; font-weight: 600; color: var(--color-warning-text); cursor: pointer; min-height: 44px;">
            <input
              type="checkbox"
              :checked="approvalOnly"
              data-testid="refund-approval-filter"
              style="width: 18px; height: 18px; accent-color: var(--color-warning);"
              @change="toggleApprovalOnly($event.target.checked)"
            />
            Solo pendientes de visto bueno
          </label>

          <input
            v-model="searchQuery"
            type="text"
            class="vg-input"
            data-testid="refund-search"
            placeholder="Buscar por ticket, máquina, sede o afectado..."
            style="max-width: 300px; height: 36px; border-radius: var(--radius-interactive, 4px);"
          />
        </div>

        <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-ink-muted, var(--color-ink-muted));">
          Mostrando {{ filteredItems.length }} de {{ total }} expediente(s)
        </div>
      </div>

      <!-- Loading / Error / Empty / Refunds table -->
      <div v-if="loading && !hasItems" style="text-align: center; padding: 48px;">
        <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 15px; color: var(--color-slate, var(--color-ink-slate));">
          Cargando la bandeja global de reintegros...
        </p>
      </div>

      <div
        v-else-if="error"
        style="background-color: var(--color-urgency-critical-bg); border: 1px solid var(--color-error); color: var(--color-error-text); padding: 16px; border-radius: var(--radius-card, 8px); margin-bottom: 24px; text-align: center;"
      >
        <p style="margin: 0 0 10px 0;">{{ error }}</p>
        <button type="button" class="vg-btn vg-btn-secondary" @click="loadRefunds">Reintentar</button>
      </div>

      <div
        v-else-if="!filteredItems.length"
        style="text-align: center; padding: 48px; background-color: var(--color-surface-card); border: 1px dashed var(--color-hairline, var(--color-hairline)); border-radius: var(--radius-card, 8px);"
      >
        <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-muted, var(--color-ink-muted)); margin: 0;">
          No hay expedientes de reintegro con los filtros seleccionados.
        </p>
      </div>

      <div v-else class="vg-card" style="border-radius: var(--radius-card, 8px); overflow: hidden; background: var(--color-surface-card);">
        <div style="overflow-x: auto;">
          <table style="width: 100%; border-collapse: collapse; font-family: var(--font-body, Inter, sans-serif); font-size: 13px;">
            <thead>
              <tr style="background-color: var(--color-surface-card); border-bottom: 1px solid var(--color-hairline, var(--color-hairline)); text-align: left;">
                <th style="padding: 12px 16px; font-weight: 600; color: var(--color-ink-secondary);">Expediente</th>
                <th style="padding: 12px 16px; font-weight: 600; color: var(--color-ink-secondary);">Sede / Máquina</th>
                <th style="padding: 12px 16px; font-weight: 600; color: var(--color-ink-secondary);">Afectado</th>
                <th style="padding: 12px 16px; font-weight: 600; color: var(--color-ink-secondary); text-align: right;">Reclamado / A pagar</th>
                <th style="padding: 12px 16px; font-weight: 600; color: var(--color-ink-secondary);">Dictamen técnico</th>
                <th style="padding: 12px 16px; font-weight: 600; color: var(--color-ink-secondary); text-align: center;">Estado</th>
                <th style="padding: 12px 16px; font-weight: 600; color: var(--color-ink-secondary); text-align: right;">Resolución</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="refund in filteredItems"
                :key="refund.id"
                :data-testid="'refund-row-' + refund.id"
                :style="{
                  borderBottom: '1px solid var(--color-canvas)',
                  backgroundColor: refund.requires_approval ? 'var(--color-warning-bg)' : 'var(--color-surface-card)'
                }"
              >
                <td style="padding: 12px 16px;">
                  <div style="font-family: monospace; font-weight: 700; color: var(--color-ink-slate); font-size: 13.5px;">
                    {{ refund.incident_code || ('RE-' + refund.id) }}
                  </div>
                  <div style="font-size: 12px; color: var(--color-ink-muted);">
                    {{ formatDate(refund.created_at) }}
                  </div>
                </td>

                <td style="padding: 12px 16px; color: var(--color-ink-secondary);">
                  <div style="font-weight: 600;">{{ refund.location_name || 'Sede no especificada' }}</div>
                  <div style="font-size: 12px; color: var(--color-ink-muted);">{{ refund.machine_code || 'N/D' }}</div>
                </td>

                <td style="padding: 12px 16px; color: var(--color-ink-secondary);">
                  <div style="font-weight: 600;">{{ refund.claimant_name }}</div>
                  <div style="font-size: 12px; color: var(--color-ink-muted);">{{ compensationLabel(refund.compensation_method) }}</div>
                </td>

                <td style="padding: 12px 16px; text-align: right;">
                  <div style="font-weight: 700; color: var(--color-ink-slate);">{{ formatAmount(refund.claimed_amount) }}</div>
                  <div style="font-size: 12px; color: var(--color-success-text);">A pagar: {{ formatAmount(refund.payable_amount) }}</div>
                </td>

                <td style="padding: 12px 16px; color: var(--color-ink-secondary);">
                  <div style="font-weight: 600;">{{ findingLabel(refund.technician_finding) }}</div>
                  <div style="font-size: 12px; color: var(--color-ink-muted);">{{ custodyLabel(refund.cash_custody_action) }}</div>
                  <div
                    v-if="discrepancyLabel(refund)"
                    data-testid="discrepancy-note"
                    style="margin-top: 4px; font-size: 12px; font-weight: 700; color: var(--color-warning-text);"
                  >
                    ⚠️ {{ discrepancyLabel(refund) }}
                  </div>
                </td>

                <td style="padding: 12px 16px; text-align: center;">
                  <span
                    :data-testid="'refund-status-' + refund.id"
                    :style="{
                      display: 'inline-flex',
                      alignItems: 'center',
                      padding: '4px 10px',
                      borderRadius: 'var(--radius-interactive)',
                      fontSize: '11px',
                      fontWeight: '700',
                      backgroundColor: statusMeta(refund.status).bg,
                      color: statusMeta(refund.status).color,
                      border: '1px solid ' + statusMeta(refund.status).border
                    }"
                  >
                    {{ refund.status_label || statusMeta(refund.status).label }}
                  </span>
                  <div
                    v-if="refund.requires_special_supervision"
                    data-testid="special-supervision-flag"
                    style="margin-top: 4px; font-size: 11px; font-weight: 700; color: var(--color-error-text);"
                  >
                    Supervisión especial (&gt; 10,00 € / discrepancia)
                  </div>
                </td>

                <td style="padding: 12px 16px; text-align: right;">
                  <div style="display: inline-flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end;">
                    <button
                      v-if="canApprove(refund)"
                      type="button"
                      class="vg-btn vg-btn-primary"
                      :data-testid="'approve-refund-' + refund.id"
                      style="font-size: 12px; min-height: 36px; padding: 0 12px; border-radius: var(--radius-interactive, 4px);"
                      @click="openApprovalModal(refund)"
                    >
                      Dar visto bueno
                    </button>
                    <button
                      v-if="canPay(refund)"
                      type="button"
                      class="vg-btn vg-btn-primary"
                      :data-testid="'pay-refund-' + refund.id"
                      style="font-size: 12px; min-height: 36px; padding: 0 12px; border-radius: var(--radius-interactive, 4px);"
                      @click="openPaymentModal(refund)"
                    >
                      Registrar pago
                    </button>
                    <button
                      v-if="canReject(refund)"
                      type="button"
                      class="vg-btn vg-btn-secondary"
                      :data-testid="'reject-refund-' + refund.id"
                      style="font-size: 12px; min-height: 36px; padding: 0 10px; border-radius: var(--radius-interactive, 4px); color: var(--color-error-text);"
                      @click="openRejectModal(refund)"
                    >
                      Desestimar
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- =============================================================== -->
      <!-- MODAL 1: DOUBLE APPROVAL OF THE FINAL AMOUNT (RF-REF-03)        -->
      <!-- =============================================================== -->
      <ModalDialog
        v-model="showApprovalModal"
        title="Visto bueno de la cuantía final"
        :subtitle="selectedRefund ? (selectedRefund.claimant_name + ' · ' + (selectedRefund.incident_code || ('RE-' + selectedRefund.id))) : ''"
        size="md"
        @close="closeApprovalModal"
      >
        <form v-if="selectedRefund" data-testid="approval-modal-body" @submit.prevent="submitApproval">
          <div style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline, var(--color-hairline)); border-radius: var(--radius-interactive, 4px); padding: 12px 14px; margin-bottom: 16px; font-size: 13px;">
            <div style="display: flex; justify-content: space-between; gap: 12px; margin-bottom: 6px;">
              <span style="color: var(--color-ink-muted);">Importe reclamado</span>
              <strong>{{ formatAmount(selectedRefund.claimed_amount) }}</strong>
            </div>
            <div style="display: flex; justify-content: space-between; gap: 12px; margin-bottom: 6px;">
              <span style="color: var(--color-ink-muted);">Efectivo recuperado</span>
              <strong>{{ selectedRefund.recovered_amount === null || selectedRefund.recovered_amount === undefined ? 'No consta' : formatAmount(selectedRefund.recovered_amount) }}</strong>
            </div>
            <div style="display: flex; justify-content: space-between; gap: 12px;">
              <span style="color: var(--color-ink-muted);">Vía solicitada</span>
              <strong>{{ compensationLabel(selectedRefund.compensation_method) }}</strong>
            </div>
          </div>

          <div
            v-if="discrepancyLabel(selectedRefund)"
            role="alert"
            data-testid="approval-discrepancy-alert"
            style="background-color: var(--color-warning-bg); border: 1px solid var(--color-warning); color: var(--color-warning-text); padding: 10px 12px; border-radius: var(--radius-interactive, 4px); font-size: 12.5px; margin-bottom: 16px; line-height: 1.45;"
          >
            ⚠️ Discrepancia de saldo: {{ discrepancyLabel(selectedRefund) }}. Autorice la cuantía final según el histórico de ventas de la máquina (RF-REF-08).
          </div>

          <div style="margin-bottom: 16px; font-size: 13px; line-height: 1.5; color: var(--color-ink-slate);">
            <div style="font-weight: 700; margin-bottom: 4px;">Dictamen técnico</div>
            <div>{{ findingLabel(selectedRefund.technician_finding) }} · {{ custodyLabel(selectedRefund.cash_custody_action) }}</div>
            <p v-if="selectedRefund.technician_justification" style="margin: 6px 0 0; color: var(--color-ink-muted); font-size: 12.5px;">
              «{{ selectedRefund.technician_justification }}»
            </p>
          </div>

          <div style="margin-bottom: 16px;">
            <label for="approval-amount" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-ink-slate); margin-bottom: 6px;">
              Cuantía final autorizada (€) <span style="color: var(--color-urgency-critical);">*</span>
            </label>
            <input
              id="approval-amount"
              :value="approvedAmount"
              type="number"
              min="0.01"
              max="50"
              step="0.01"
              inputmode="decimal"
              data-testid="approval-amount-input"
              :disabled="isApproving"
              style="box-sizing: border-box; width: 100%; min-height: 44px; padding: 10px 12px; border: 1px solid var(--color-hairline, var(--color-hairline)); border-radius: var(--radius-interactive, 4px); font-family: var(--font-body, Inter, sans-serif); font-size: 14px;"
              @input="setApprovalAmount($event.target.value)"
            />
            <small style="display: block; margin-top: 4px; font-size: 12px; color: var(--color-ink-muted);">
              Tope antifraude: 50,00 € por reclamación. La autorización superior a 10,00 € exige este doble visto bueno.
            </small>
          </div>

          <div style="margin-bottom: 16px;">
            <label for="approval-notes" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-ink-slate); margin-bottom: 6px;">
              Notas de la decisión <span style="font-size: 11px; color: var(--color-ink-muted);">(opcional)</span>
            </label>
            <textarea
              id="approval-notes"
              :value="approvalNotes"
              rows="3"
              maxlength="500"
              data-testid="approval-notes-input"
              :disabled="isApproving"
              placeholder="Ej: Comprobado el registro de ventas y el corte de stock; se autoriza la devolución íntegra."
              style="box-sizing: border-box; width: 100%; padding: 10px 12px; border: 1px solid var(--color-hairline, var(--color-hairline)); border-radius: var(--radius-interactive, 4px); font-family: var(--font-body, Inter, sans-serif); font-size: 13px;"
              @input="approvalNotes = $event.target.value"
            ></textarea>
          </div>

          <div
            v-if="approvalError"
            role="alert"
            data-testid="approval-error"
            style="background-color: var(--color-urgency-critical-bg); border: 1px solid var(--color-error); color: var(--color-error-text); padding: 10px 12px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 16px;"
          >
            {{ approvalError }}
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid var(--color-hairline, var(--color-hairline)); padding-top: 16px;">
            <button type="button" class="vg-btn vg-btn-secondary" :disabled="isApproving" @click="closeApprovalModal">
              Cancelar
            </button>
            <button
              type="submit"
              class="vg-btn vg-btn-primary"
              data-testid="approval-submit"
              :disabled="isApproving || !isApprovalAmountValid"
            >
              <span v-if="!isApproving">Confirmar visto bueno</span>
              <span v-else>Registrando...</span>
            </button>
          </div>
        </form>
      </ModalDialog>

      <!-- =============================================================== -->
      <!-- MODAL 2: DIGITAL SETTLEMENT WITH PAYMENT REFERENCE (RF-REF-07)  -->
      <!-- =============================================================== -->
      <ModalDialog
        v-model="showPaymentModal"
        title="Registrar liquidación digital"
        :subtitle="selectedRefund ? (selectedRefund.claimant_name + ' · ' + formatAmount(selectedRefund.payable_amount)) : ''"
        size="md"
        @close="closePaymentModal"
      >
        <form v-if="selectedRefund" data-testid="payment-modal-body" @submit.prevent="submitPayment">
          <div style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline, var(--color-hairline)); border-radius: var(--radius-interactive, 4px); padding: 12px 14px; margin-bottom: 16px; font-size: 13px;">
            <div style="font-weight: 700; margin-bottom: 8px;">Destino del pago</div>
            <div style="display: flex; justify-content: space-between; gap: 12px; margin-bottom: 6px;">
              <span style="color: var(--color-ink-muted);">Vía</span>
              <strong>{{ compensationLabel(selectedRefund.compensation_method) }}</strong>
            </div>
            <div v-if="selectedRefund.compensation_method === 'BIZUM'" style="display: flex; justify-content: space-between; gap: 12px; margin-bottom: 6px;">
              <span style="color: var(--color-ink-muted);">Teléfono Bizum</span>
              <strong style="font-family: monospace;">{{ selectedRefund.bizum_phone || 'No informado' }}</strong>
            </div>
            <div v-if="selectedRefund.compensation_method === 'TRANSFERENCIA_BANCARIA'" style="display: flex; justify-content: space-between; gap: 12px; margin-bottom: 6px;">
              <span style="color: var(--color-ink-muted);">IBAN</span>
              <strong style="font-family: monospace; font-size: 12px;">{{ selectedRefund.iban || 'No informado' }}</strong>
            </div>
            <div style="display: flex; justify-content: space-between; gap: 12px;">
              <span style="color: var(--color-ink-muted);">Afectado</span>
              <strong>{{ selectedRefund.claimant_name }} · {{ selectedRefund.claimant_contact }}</strong>
            </div>
          </div>

          <div style="margin-bottom: 16px;">
            <label for="payment-amount" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-ink-slate); margin-bottom: 6px;">
              Importe liquidado (€) <span style="color: var(--color-urgency-critical);">*</span>
            </label>
            <input
              id="payment-amount"
              :value="paymentAmount"
              type="number"
              min="0.01"
              step="0.01"
              inputmode="decimal"
              data-testid="payment-amount-input"
              :disabled="isPaying"
              style="box-sizing: border-box; width: 100%; min-height: 44px; padding: 10px 12px; border: 1px solid var(--color-hairline, var(--color-hairline)); border-radius: var(--radius-interactive, 4px); font-family: var(--font-body, Inter, sans-serif); font-size: 14px;"
              @input="setPaymentAmount($event.target.value)"
            />
          </div>

          <div style="margin-bottom: 16px;">
            <label for="payment-reference" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-ink-slate); margin-bottom: 6px;">
              Referencia del justificante bancario o de Bizum <span style="color: var(--color-urgency-critical);">*</span>
            </label>
            <input
              id="payment-reference"
              :value="paymentReference"
              type="text"
              maxlength="100"
              autocomplete="off"
              data-testid="payment-reference-input"
              :disabled="isPaying"
              placeholder="Ej: BIZUM-20261001-998822"
              style="box-sizing: border-box; width: 100%; min-height: 44px; padding: 10px 12px; border: 1px solid var(--color-hairline, var(--color-hairline)); border-radius: var(--radius-interactive, 4px); font-family: var(--font-body, Inter, sans-serif); font-size: 14px;"
              @input="paymentReference = $event.target.value"
            />
            <small style="display: block; margin-top: 4px; font-size: 12px; color: var(--color-ink-muted);">
              Sin esta referencia no existe asiento contable que justifique la salida de dinero (RF-REF-07).
            </small>
          </div>

          <div
            v-if="paymentError"
            role="alert"
            data-testid="payment-error"
            style="background-color: var(--color-urgency-critical-bg); border: 1px solid var(--color-error); color: var(--color-error-text); padding: 10px 12px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 16px;"
          >
            {{ paymentError }}
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid var(--color-hairline, var(--color-hairline)); padding-top: 16px;">
            <button type="button" class="vg-btn vg-btn-secondary" :disabled="isPaying" @click="closePaymentModal">
              Cancelar
            </button>
            <button
              type="submit"
              class="vg-btn vg-btn-primary"
              data-testid="payment-submit"
              :disabled="isPaying || !isPaymentValid"
            >
              <span v-if="!isPaying">Confirmar liquidación</span>
              <span v-else>Registrando...</span>
            </button>
          </div>
        </form>
      </ModalDialog>

      <!-- =============================================================== -->
      <!-- MODAL 3: MOTIVATED REJECTION >= 20 CHARS (RF-REF-08, Art. III)  -->
      <!-- =============================================================== -->
      <ModalDialog
        v-model="showRejectModal"
        title="Desestimar reclamación"
        :subtitle="selectedRefund ? (selectedRefund.claimant_name + ' · ' + (selectedRefund.incident_code || ('RE-' + selectedRefund.id))) : ''"
        size="md"
        @close="closeRejectModal"
      >
        <form v-if="selectedRefund" data-testid="reject-modal-body" @submit.prevent="submitRejection">
          <p style="font-size: 13px; color: var(--color-ink-slate); margin: 0 0 14px 0; line-height: 1.5;">
            La reclamación quedará en estado <strong>DESESTIMADO</strong> con su motivo escrito. Nada se elimina: el expediente completo se conserva para auditoría (Art. III).
          </p>

          <div style="margin-bottom: 16px;">
            <label for="rejection-reason" style="display: block; font-size: 13px; font-weight: 600; color: var(--color-ink-slate); margin-bottom: 6px;">
              Motivo de la desestimación <span style="color: var(--color-urgency-critical);">*</span> (mínimo 20 caracteres)
            </label>
            <textarea
              id="rejection-reason"
              :value="rejectionReason"
              rows="4"
              minlength="20"
              data-testid="rejection-reason-input"
              :disabled="isRejecting"
              placeholder="Ej: Inspección técnica sin monedas atascadas y máquina operando con normalidad según auditoría de ventas."
              style="box-sizing: border-box; width: 100%; padding: 10px 12px; border: 1px solid var(--color-hairline, var(--color-hairline)); border-radius: var(--radius-interactive, 4px); font-family: var(--font-body, Inter, sans-serif); font-size: 13px;"
              @input="rejectionReason = $event.target.value"
            ></textarea>
            <small style="display: block; margin-top: 4px; font-size: 12px; color: var(--color-ink-muted);">
              {{ rejectionReason.trim().length }} / 20 caracteres
            </small>
          </div>

          <div
            v-if="rejectionError"
            role="alert"
            data-testid="rejection-error"
            style="background-color: var(--color-urgency-critical-bg); border: 1px solid var(--color-error); color: var(--color-error-text); padding: 10px 12px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 16px;"
          >
            {{ rejectionError }}
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid var(--color-hairline, var(--color-hairline)); padding-top: 16px;">
            <button type="button" class="vg-btn vg-btn-secondary" :disabled="isRejecting" @click="closeRejectModal">
              Volver
            </button>
            <button
              type="submit"
              class="vg-btn vg-btn-danger"
              data-testid="rejection-submit"
              :disabled="isRejecting || !isRejectionValid"
            >
              <span v-if="!isRejecting">Confirmar desestimación</span>
              <span v-else>Registrando...</span>
            </button>
          </div>
        </form>
      </ModalDialog>
    </section>
  `
};

export default CoordinatorRefundsTab;
