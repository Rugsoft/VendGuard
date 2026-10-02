/**
 * VendGuard - LocationRefundsTab (LocationRefundsTab.js)
 *
 * Reception desk refunds tab for the Location Responsible portal
 * (RF-REF-06, RF-REF-10, RNF-REF-05, Constitution Art. V.4).
 *
 * Responsibilities:
 * 1. List the building's refund cases with anonymized claimant names
 *    ("Laura S."), amount, machine and status badge.
 * 2. Offer "Entregar efectivo" only for cases with cash waiting at reception.
 * 3. Open a touch keypad modal to type the 4-digit pickup PIN shown by the
 *    claimant and release the envelope once validated.
 * 4. Never project payment instruments, phone numbers or the stored PIN:
 *    the concierge only needs to recognize the person and confirm the code
 *    the claimant says out loud (Art. V.4).
 *
 * Dogma Vanilla: Vue 3 ESM component, zero dependencies.
 * Dualismo Lingüístico: identifiers and comments in English, UI copy in Spanish.
 */

import { api } from '../api.js';

const STATUS_META = {
  PENDING_INSPECTION: {
    label: 'Pendiente de inspección técnica',
    color: '#475569',
    bg: '#f1f5f9',
    border: '#cbd5e1'
  },
  DEPOSITED_AT_RECEPTION: {
    label: 'Efectivo en conserjería, listo para entrega',
    color: '#166534',
    bg: '#dcfce7',
    border: '#86efac'
  },
  VERIFIED_PENDING_PAYMENT: {
    label: 'Verificado, pendiente de pago',
    color: '#003db5',
    bg: '#e5f2fc',
    border: '#2560ff'
  },
  REQUIRES_COORDINATOR_APPROVAL: {
    label: 'Requiere visto bueno de coordinación',
    color: '#92400e',
    bg: '#fef8e7',
    border: '#f8b60f'
  },
  PENDING_CONTACT: {
    label: 'Pendiente de contacto del afectado',
    color: '#92400e',
    bg: '#fef8e7',
    border: '#f8b60f'
  },
  PAID_DIGITAL: {
    label: 'Reembolsado por vía digital',
    color: '#475569',
    bg: '#f1f5f9',
    border: '#cbd5e1'
  },
  REFUNDED_IN_HAND: {
    label: 'Reembolsado en mano',
    color: '#166534',
    bg: '#dcfce7',
    border: '#86efac'
  },
  REJECTED: {
    label: 'Desestimado',
    color: '#b91c1c',
    bg: '#fee2e2',
    border: '#fca5a5'
  }
};

const PIN_LENGTH = 4;
const KEYPAD_DIGITS = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];

export const LocationRefundsTab = {
  name: 'LocationRefundsTab',
  data() {
    return {
      loading: false,
      error: '',
      refunds: [],
      successMessage: '',
      selectedRefund: null,
      pin: '',
      pinError: '',
      isDelivering: false,
      keypadDigits: KEYPAD_DIGITS,
      pinLength: PIN_LENGTH
    };
  },
  computed: {
    hasRefunds() {
      return this.refunds.length > 0;
    },
    readyForPickupCount() {
      return this.refunds.filter((refund) => refund.ready_for_pickup === true).length;
    },
    isPinComplete() {
      return this.pin.length === PIN_LENGTH && /^[0-9]{4}$/.test(this.pin);
    },
    pinSlots() {
      return Array.from({ length: PIN_LENGTH }, (_, index) => index < this.pin.length);
    }
  },
  mounted() {
    this.loadRefunds();
  },
  methods: {
    /**
     * Loads the anonymized refund cases of the authenticated site (RF-REF-06).
     */
    async loadRefunds() {
      this.loading = true;
      this.error = '';

      try {
        const data = await api.site.getRefunds();
        this.refunds = Array.isArray(data?.refunds) ? data.refunds : [];
      } catch (err) {
        this.error = err?.message || 'No se pudo cargar la bandeja de reintegros de la sede.';
        this.refunds = [];
      } finally {
        this.loading = false;
      }
    },

    /**
     * Opens the PIN keypad for a case whose cash is physically at reception.
     */
    openPinModal(refund) {
      if (!refund || refund.ready_for_pickup !== true || this.isDelivering) return;
      this.selectedRefund = refund;
      this.pin = '';
      this.pinError = '';
      this.successMessage = '';
    },

    closePinModal() {
      if (this.isDelivering) return;
      this.selectedRefund = null;
      this.pin = '';
      this.pinError = '';
    },

    appendPinDigit(digit) {
      if (this.isDelivering || !/^[0-9]$/.test(String(digit))) return;
      if (this.pin.length >= PIN_LENGTH) return;
      this.pinError = '';
      this.pin = this.pin + String(digit);
    },

    removePinDigit() {
      if (this.isDelivering || this.pin.length === 0) return;
      this.pinError = '';
      this.pin = this.pin.slice(0, -1);
    },

    clearPin() {
      if (this.isDelivering) return;
      this.pin = '';
      this.pinError = '';
    },

    /**
     * Confirms the handover with the typed PIN (RF-REF-06). A rejected PIN
     * leaves the case untouched so the envelope stays in custody.
     */
    async submitPin() {
      if (!this.selectedRefund || !this.isPinComplete || this.isDelivering) return;

      this.isDelivering = true;
      this.pinError = '';

      try {
        const delivered = await api.site.deliverRefund(this.selectedRefund.id, this.pin);
        this.applyDelivery(delivered);
        this.successMessage =
          `Entrega registrada: ${delivered?.claimant_name_anon || this.selectedRefund.claimant_name_anon} · `
          + `${this.formatAmount(delivered?.claimed_amount ?? this.selectedRefund.claimed_amount)}. `
          + 'El expediente queda reembolsado en mano.';
        this.selectedRefund = null;
        this.pin = '';
        this.pinError = '';
      } catch (err) {
        if (err?.code === 'INVALID_PICKUP_PIN' || err?.code === 'MISSING_PICKUP_PIN') {
          this.pinError = 'PIN de recogida incorrecto. El efectivo permanece en custodia en conserjería.';
        } else {
          this.pinError = err?.message || 'No se pudo registrar la entrega del efectivo.';
        }
        this.pin = '';
      } finally {
        this.isDelivering = false;
      }
    },

    /**
     * Reflects a successful handover in the local list without reloading.
     */
    applyDelivery(delivered) {
      const caseId = Number(delivered?.id ?? this.selectedRefund?.id);
      const status = delivered?.status || 'REFUNDED_IN_HAND';

      this.refunds = this.refunds.map((refund) => {
        if (Number(refund.id) !== caseId) return refund;
        return {
          ...refund,
          status,
          status_label: STATUS_META[status]?.label || refund.status_label,
          ready_for_pickup: false
        };
      });
    },

    statusMeta(status) {
      return STATUS_META[status] || {
        label: status || 'Estado desconocido',
        color: '#475569',
        bg: '#f1f5f9',
        border: '#cbd5e1'
      };
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
    <section class="vg-location-refunds-tab" data-testid="location-refunds-tab">
      <!-- Header card: pending envelopes summary -->
      <div
        class="vg-card"
        style="border-radius: var(--radius-card, 8px); padding: 18px 22px; margin-bottom: 20px; background: #ffffff; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px;"
      >
        <div>
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
            <span style="font-size: 20px;">💶</span>
            <h2 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 19px; font-weight: 700; color: var(--color-ink, #000000); margin: 0;">
              Reintegros de la sede
            </h2>
          </div>
          <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-slate, #2c333f); margin: 0;">
            Sobres de efectivo depositados por el técnico y entregas pendientes de validar con el PIN de 4 dígitos del afectado.
          </p>
        </div>

        <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
          <div
            data-testid="ready-for-pickup-counter"
            style="padding: 8px 14px; border-radius: var(--radius-card, 8px); border: 1px solid #86efac; background: #f0fdf4; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; font-weight: 700; color: #166534;"
          >
            Sobres en conserjería: {{ readyForPickupCount }}
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

      <!-- Delivery confirmation banner -->
      <div
        v-if="successMessage"
        role="status"
        data-testid="refund-delivery-success"
        style="background-color: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 12px 18px; border-radius: var(--radius-card, 8px); margin-bottom: 20px; font-family: var(--font-body, Inter, sans-serif); font-size: 13.5px; font-weight: 600;"
      >
        ✅ {{ successMessage }}
      </div>

      <!-- Loading / Error / Empty / List -->
      <div v-if="loading && !hasRefunds" style="text-align: center; padding: 48px;">
        <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 15px; color: var(--color-slate, #2c333f);">
          Cargando los reintegros del centro...
        </p>
      </div>

      <div
        v-else-if="error"
        style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 16px; border-radius: var(--radius-card, 8px); margin-bottom: 24px; text-align: center;"
      >
        <p style="margin: 0 0 10px 0;">{{ error }}</p>
        <button type="button" class="vg-btn vg-btn-secondary" @click="loadRefunds">Reintentar</button>
      </div>

      <div
        v-else-if="!hasRefunds"
        style="text-align: center; padding: 48px; background-color: #ffffff; border: 1px dashed var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px);"
      >
        <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-muted, #6c7e9d); margin: 0;">
          No constan reclamaciones de reintegro asociadas a las máquinas de este centro.
        </p>
      </div>

      <div v-else class="vg-card" style="border-radius: var(--radius-card, 8px); overflow: hidden; background: #ffffff;">
        <div style="overflow-x: auto;">
          <table style="width: 100%; border-collapse: collapse; font-family: var(--font-body, Inter, sans-serif); font-size: 13px;">
            <thead>
              <tr style="background-color: #f8fafc; border-bottom: 1px solid var(--color-hairline, #c8cfda); text-align: left;">
                <th style="padding: 12px 16px; font-weight: 600; color: #475569;">Expediente</th>
                <th style="padding: 12px 16px; font-weight: 600; color: #475569;">Máquina</th>
                <th style="padding: 12px 16px; font-weight: 600; color: #475569;">Afectado</th>
                <th style="padding: 12px 16px; font-weight: 600; color: #475569; text-align: right;">Importe</th>
                <th style="padding: 12px 16px; font-weight: 600; color: #475569;">Alta</th>
                <th style="padding: 12px 16px; font-weight: 600; color: #475569; text-align: center;">Estado</th>
                <th style="padding: 12px 16px; font-weight: 600; color: #475569; text-align: right;">Entrega</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="refund in refunds"
                :key="refund.id"
                :data-testid="'refund-row-' + refund.id"
                :style="{
                  borderBottom: '1px solid #f1f5f9',
                  backgroundColor: refund.ready_for_pickup ? '#f0fdf4' : '#ffffff'
                }"
              >
                <td style="padding: 12px 16px;">
                  <div style="font-family: monospace; font-weight: 700; color: #0f172a; font-size: 13.5px;">
                    {{ refund.incident_code || ('RE-' + refund.id) }}
                  </div>
                </td>

                <td style="padding: 12px 16px; color: #334155;">
                  {{ refund.machine_code || 'N/D' }}
                </td>

                <!-- Anonymized claimant: "Laura S." (Art. V.4, RF-REF-10) -->
                <td style="padding: 12px 16px; color: #334155; font-weight: 600;" data-testid="claimant-anon">
                  {{ refund.claimant_name_anon }}
                </td>

                <td style="padding: 12px 16px; text-align: right; font-weight: 700; color: #0f172a;">
                  {{ formatAmount(refund.claimed_amount) }}
                </td>

                <td style="padding: 12px 16px; color: #334155;">
                  {{ formatDate(refund.created_at) }}
                </td>

                <td style="padding: 12px 16px; text-align: center;">
                  <span
                    :data-testid="'refund-status-' + refund.id"
                    :style="{
                      display: 'inline-flex',
                      alignItems: 'center',
                      padding: '4px 10px',
                      borderRadius: '4px',
                      fontSize: '11px',
                      fontWeight: '700',
                      letterSpacing: '0.02em',
                      backgroundColor: statusMeta(refund.status).bg,
                      color: statusMeta(refund.status).color,
                      border: '1px solid ' + statusMeta(refund.status).border
                    }"
                  >
                    {{ refund.status_label || statusMeta(refund.status).label }}
                  </span>
                </td>

                <td style="padding: 12px 16px; text-align: right;">
                  <button
                    v-if="refund.ready_for_pickup"
                    type="button"
                    class="vg-btn vg-btn-primary"
                    :data-testid="'deliver-refund-' + refund.id"
                    style="font-size: 12.5px; min-height: 44px; padding: 0 14px; border-radius: var(--radius-interactive, 4px);"
                    @click="openPinModal(refund)"
                  >
                    Entregar efectivo
                  </button>
                  <span v-else style="color: #94a3b8; font-size: 12px;">Sin sobre en conserjería</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- PIN keypad modal (RF-REF-06) -->
      <div
        v-if="selectedRefund"
        class="vg-modal-overlay"
        data-testid="refund-pin-modal"
        role="dialog"
        aria-modal="true"
        aria-label="Validación del PIN de recogida"
        style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.55); display: flex; align-items: center; justify-content: center; padding: 16px; z-index: 50;"
      >
        <div
          style="width: 100%; max-width: 380px; background: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 22px 20px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.18);"
        >
          <h3 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 18px; font-weight: 700; color: var(--color-ink, #000000); margin: 0 0 6px;">
            Entrega de efectivo en conserjería
          </h3>
          <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-slate, #2c333f); margin: 0 0 14px; line-height: 1.45;">
            Pida al afectado el PIN de 4 dígitos que muestra en su móvil y técleelo para liberar el sobre.
          </p>

          <div
            data-testid="pin-case-summary"
            style="display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 12px; background: #f9fafb; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); margin-bottom: 16px;"
          >
            <div>
              <div style="font-size: 15px; font-weight: 700; color: #0f172a;">
                {{ selectedRefund.claimant_name_anon }}
              </div>
              <div style="font-size: 12px; color: #596579;">
                {{ selectedRefund.incident_code || ('RE-' + selectedRefund.id) }} · {{ selectedRefund.machine_code || 'N/D' }}
              </div>
            </div>
            <div style="font-size: 16px; font-weight: 800; color: #0f172a;">
              {{ formatAmount(selectedRefund.claimed_amount) }}
            </div>
          </div>

          <!-- PIN slots -->
          <div
            data-testid="pin-slots"
            style="display: flex; justify-content: center; gap: 12px; margin-bottom: 14px;"
          >
            <span
              v-for="(filled, index) in pinSlots"
              :key="'pin-slot-' + index"
              :data-testid="'pin-slot-' + index"
              :style="{
                width: '52px',
                height: '52px',
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontSize: '26px',
                fontWeight: '700',
                color: '#0f172a',
                background: '#ffffff',
                border: '1.5px solid ' + (filled ? 'var(--color-primary, #2560ff)' : 'var(--color-hairline, #c8cfda)'),
                borderRadius: 'var(--radius-interactive, 4px)'
              }"
            >
              {{ filled ? '•' : '' }}
            </span>
          </div>

          <div
            v-if="pinError"
            role="alert"
            data-testid="pin-error"
            style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 10px 12px; border-radius: var(--radius-interactive, 4px); font-family: var(--font-body, Inter, sans-serif); font-size: 13px; margin-bottom: 14px;"
          >
            {{ pinError }}
          </div>

          <!-- Numeric keypad: 48px touch targets -->
          <div data-testid="pin-keypad" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 14px;">
            <button
              v-for="digit in keypadDigits"
              :key="'pin-key-' + digit"
              type="button"
              :data-testid="'pin-key-' + digit"
              :disabled="isDelivering"
              style="min-height: 48px; font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 20px; font-weight: 700; color: var(--color-slate, #2c333f); background: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); cursor: pointer;"
              @click="appendPinDigit(digit)"
            >
              {{ digit }}
            </button>
            <button
              type="button"
              data-testid="pin-key-backspace"
              :disabled="isDelivering || pin.length === 0"
              style="min-height: 48px; font-size: 14px; font-weight: 700; color: var(--color-slate, #2c333f); background: #f1f5f9; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); cursor: pointer;"
              @click="removePinDigit"
            >
              ⌫ Borrar
            </button>
            <button
              type="button"
              data-testid="pin-key-0"
              :disabled="isDelivering"
              style="min-height: 48px; font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 20px; font-weight: 700; color: var(--color-slate, #2c333f); background: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-interactive, 4px); cursor: pointer;"
              @click="appendPinDigit('0')"
            >
              0
            </button>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1.4fr; gap: 8px;">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              data-testid="pin-cancel"
              style="min-height: 48px; border-radius: var(--radius-interactive, 4px);"
              :disabled="isDelivering"
              @click="closePinModal"
            >
              Cancelar
            </button>
            <button
              type="button"
              class="vg-btn vg-btn-primary"
              data-testid="confirm-pin-delivery"
              style="min-height: 48px; border-radius: var(--radius-interactive, 4px);"
              :disabled="!isPinComplete || isDelivering"
              @click="submitPin"
            >
              <span v-if="!isDelivering">Confirmar entrega</span>
              <span v-else>Validando PIN...</span>
            </button>
          </div>
        </div>
      </div>
    </section>
  `
};

export default LocationRefundsTab;
