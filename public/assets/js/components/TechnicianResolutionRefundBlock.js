/**
 * VendGuard - TechnicianResolutionRefundBlock
 *
 * Mobile-first cash verdict form for incident resolution (RF-REF-04/05).
 * Keeps refund data limited to claim amount, attempted product and custody
 * instruction; claimant identity and payment instruments are never projected.
 */

const FINDINGS = [
  {
    value: 'FOUND_PHYSICAL',
    label: 'He recuperado dinero físico en la máquina.'
  },
  {
    value: 'CONFIRMED_NO_CASH',
    label: 'Fallo verificado de cobro, sin monedas recuperadas.'
  },
  {
    value: 'UNVERIFIED_NO_CASH',
    label: 'No localizo dinero ni evidencia técnica de saldo retenido.'
  }
];

const RECEPTION_LIMIT = 10;

export const TechnicianResolutionRefundBlock = {
  name: 'TechnicianResolutionRefundBlock',
  props: {
    refundRequests: {
      type: Array,
      default: () => []
    },
    requiresVerdict: {
      type: Boolean,
      default: false
    },
    allowUnclaimedCash: {
      type: Boolean,
      default: false
    },
    disabled: {
      type: Boolean,
      default: false
    }
  },
  emits: ['change'],
  data() {
    return {
      finding: '',
      recoveredAmount: '',
      custodyAction: '',
      receptionistName: '',
      justification: '',
      includeUnclaimedCash: false,
      unclaimedAmount: '',
      unclaimedNotes: ''
    };
  },
  computed: {
    hasPendingRequests() {
      return this.requiresVerdict || this.refundRequests.some((request) => request.status === 'PENDING_INSPECTION');
    },
    canRegisterUnclaimedCash() {
      return this.allowUnclaimedCash && this.refundRequests.length === 0;
    },
    requiresCentralCustody() {
      const pendingRequests = this.refundRequests.filter((request) => request.status === 'PENDING_INSPECTION');
      if (!pendingRequests.length) return false;
      if (pendingRequests.some((request) => request.compensation_method !== 'EN_MANO_SEDE')) return true;
      const amount = Number(this.recoveredAmount);
      return Number.isFinite(amount) && amount > RECEPTION_LIMIT;
    },
    isRecoveredAmountValid() {
      const amount = Number(this.recoveredAmount);
      return this.finding !== 'FOUND_PHYSICAL' || (this.recoveredAmount !== '' && Number.isFinite(amount) && amount > 0);
    },
    isJustificationValid() {
      return this.finding !== 'UNVERIFIED_NO_CASH' || this.justification.trim().length >= 20;
    },
    isReceptionistValid() {
      return !this.requiresReceptionCustody || this.receptionistName.trim().length > 0;
    },
    requiresReceptionCustody() {
      return this.finding === 'FOUND_PHYSICAL'
        && !this.requiresCentralCustody
        && this.custodyAction === 'LEFT_AT_RECEPTION';
    },
    isCustodyValid() {
      return this.finding !== 'FOUND_PHYSICAL'
        || this.requiresCentralCustody
        || this.custodyAction === 'LEFT_AT_RECEPTION'
        || this.custodyAction === 'HELD_FOR_CENTRAL';
    },
    isUnclaimedAmountValid() {
      if (!this.includeUnclaimedCash) return true;
      const amount = Number(this.unclaimedAmount);
      return this.unclaimedAmount !== '' && Number.isFinite(amount) && amount > 0;
    },
    isValid() {
      if (!this.hasPendingRequests && !this.includeUnclaimedCash) return true;
      if (this.hasPendingRequests && !this.finding) return false;
      return this.isRecoveredAmountValid
        && this.isJustificationValid
        && this.isReceptionistValid
        && this.isCustodyValid
        && this.isUnclaimedAmountValid;
    },
    findingOptions() {
      return FINDINGS;
    }
  },
  watch: {
    recoveredAmount() {
      this.publishChange();
    },
    receptionistName() {
      this.publishChange();
    },
    justification() {
      this.publishChange();
    },
    unclaimedAmount() {
      this.publishChange();
    },
    unclaimedNotes() {
      this.publishChange();
    }
  },
  methods: {
    setFinding(value) {
      if (this.disabled || !FINDINGS.some((option) => option.value === value)) return;
      this.finding = value;
      if (value !== 'FOUND_PHYSICAL') {
        this.recoveredAmount = '';
        this.custodyAction = '';
        this.receptionistName = '';
      } else {
        this.custodyAction = 'HELD_FOR_CENTRAL';
      }
      this.publishChange();
    },
    setRecoveredAmount(value) {
      if (this.disabled) return;
      const previousRequiresCentralCustody = this.requiresCentralCustody;
      this.recoveredAmount = String(value ?? '');
      if (this.requiresCentralCustody) {
        this.custodyAction = 'HELD_FOR_CENTRAL';
        this.receptionistName = '';
      } else if (previousRequiresCentralCustody && this.custodyAction === 'HELD_FOR_CENTRAL') {
        this.custodyAction = '';
      }
      this.publishChange();
    },
    setCustodyAction(value) {
      if (this.disabled) return;
      if (value === 'LEFT_AT_RECEPTION' && this.requiresCentralCustody) return;
      if (value !== 'LEFT_AT_RECEPTION' && value !== 'HELD_FOR_CENTRAL') return;
      this.custodyAction = value;
      if (value !== 'LEFT_AT_RECEPTION') this.receptionistName = '';
      this.publishChange();
    },
    toggleUnclaimedCash(value) {
      if (this.disabled || !this.canRegisterUnclaimedCash) return;
      this.includeUnclaimedCash = Boolean(value);
      if (!this.includeUnclaimedCash) {
        this.unclaimedAmount = '';
        this.unclaimedNotes = '';
      }
      this.publishChange();
    },
    buildPayload() {
      const payload = {};
      if (this.hasPendingRequests) {
        const centralCustody = this.finding === 'FOUND_PHYSICAL' && this.requiresCentralCustody;
        payload.refund_inspection = {
          finding: this.finding,
          recovered_amount: this.finding === 'FOUND_PHYSICAL' ? Number(this.recoveredAmount) : null,
          cash_custody_action: this.finding === 'FOUND_PHYSICAL'
            ? (centralCustody ? 'HELD_FOR_CENTRAL' : (this.custodyAction || null))
            : null,
          receptionist_name: this.finding === 'FOUND_PHYSICAL'
            && this.custodyAction === 'LEFT_AT_RECEPTION'
            && !centralCustody
            ? this.receptionistName.trim()
            : null,
          justification: this.finding === 'UNVERIFIED_NO_CASH' ? this.justification.trim() : null
        };
      }
      if (this.includeUnclaimedCash && this.canRegisterUnclaimedCash) {
        payload.unclaimed_cash_found = {
          amount: Number(this.unclaimedAmount),
          notes: this.unclaimedNotes.trim()
        };
      }
      return payload;
    },
    validate() {
      let error = '';
      if (this.hasPendingRequests && !this.finding) {
        error = 'Debe seleccionar el dictamen obligatorio del saldo retenido.';
      } else if (!this.isRecoveredAmountValid) {
        error = 'Indique el importe exacto de dinero recuperado.';
      } else if (!this.isJustificationValid) {
        error = 'La justificación de ausencia de efectivo debe contener al menos 20 caracteres.';
      } else if (!this.isReceptionistValid) {
        error = 'Indique el nombre de la persona que recibe el sobre en conserjería.';
      } else if (!this.isCustodyValid) {
        error = 'Seleccione dónde custodiar el efectivo recuperado.';
      } else if (!this.isUnclaimedAmountValid) {
        error = 'El importe recuperado de oficio debe ser mayor que cero.';
      }
      return { isValid: error === '', error, payload: error === '' ? this.buildPayload() : null };
    },
    publishChange() {
      this.$emit('change', {
        isValid: this.isValid,
        ...this.buildPayload()
      });
    }
  },
  template: `
    <section v-if="hasPendingRequests || canRegisterUnclaimedCash" class="technician-resolution-refund-block" data-testid="technician-resolution-refund-block" style="margin-bottom: 16px; padding: 16px; background: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card);">
      <h3 style="margin: 0 0 6px; font-size: 16px; color: var(--color-ink-slate);">💶 Efectivo retenido (RF-REF-04/05)</h3>
      <p style="margin: 0 0 14px; color: var(--color-ink-muted); font-size: 12px; line-height: 1.45;">
        {{ hasPendingRequests ? 'Esta avería tiene reclamaciones pendientes: registra el dictamen antes de resolver.' : 'Si encuentras monedas atascadas sin reclamación previa, puedes anotarlas aquí.' }}
      </p>

      <div v-if="hasPendingRequests" data-testid="pending-refund-requests" style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px;">
        <article v-for="request in refundRequests" :key="request.id" style="padding: 10px 12px; background: var(--color-canvas); border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);">
          <strong>{{ Number(request.claimed_amount).toFixed(2) }} €</strong>
          <span v-if="request.product_attempted"> · {{ request.product_attempted }}</span>
          <p style="margin: 4px 0 0; color: var(--color-ink-muted); font-size: 12px;">{{ request.custody_instruction }}</p>
        </article>
      </div>

      <fieldset v-if="hasPendingRequests" style="padding: 0; margin: 0; border: 0;" :disabled="disabled">
        <legend style="margin-bottom: 8px; font-size: 13px; font-weight: 700;">Dictamen de saldo obligatorio</legend>
        <div style="display: grid; gap: 8px;">
          <button v-for="option in findingOptions" :key="option.value" type="button" :aria-pressed="finding === option.value" :data-testid="'finding-' + option.value" @click="setFinding(option.value)" :style="{ minHeight: '48px', padding: '10px 12px', textAlign: 'left', font: 'inherit', fontSize: '13px', fontWeight: finding === option.value ? '700' : '500', color: finding === option.value ? 'var(--color-primary-dark)' : 'var(--color-ink-slate)', background: finding === option.value ? 'var(--color-primary-subtle)' : 'var(--color-surface-card)', border: '1px solid ' + (finding === option.value ? 'var(--color-primary)' : 'var(--color-hairline)'), borderRadius: var(--radius-interactive)', cursor: disabled ? 'not-allowed' : 'pointer' }">{{ option.label }}</button>
        </div>

        <div v-if="finding === 'FOUND_PHYSICAL'" style="margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--color-hairline);">
          <label for="refund-recovered-amount" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600;">Importe exacto recuperado (€) *</label>
          <input id="refund-recovered-amount" :value="recoveredAmount" @input="setRecoveredAmount($event.target.value)" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="0,00" :disabled="disabled" style="box-sizing: border-box; width: 100%; min-height: 48px; padding: 10px 12px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); font: inherit;" />

          <div style="margin-top: 12px;">
            <div style="margin-bottom: 8px; font-size: 13px; font-weight: 600;">Destino del efectivo *</div>
            <p v-if="requiresCentralCustody" data-testid="central-custody-forced" role="status" style="margin: 0 0 8px; padding: 9px 10px; color: var(--color-warning-text); background: var(--color-warning-bg); border: 1px solid var(--color-warning); border-radius: var(--radius-interactive); font-size: 12px;">Por el método de compensación o el importe recuperado, el efectivo debe ir a caja central.</p>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
              <button type="button" :disabled="disabled || requiresCentralCustody" :aria-pressed="custodyAction === 'LEFT_AT_RECEPTION'" @click="setCustodyAction('LEFT_AT_RECEPTION')" style="min-height: 48px; padding: 8px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); background: var(--color-surface-card); font: inherit; font-size: 12px;">Dejar en conserjería</button>
              <button type="button" :disabled="disabled" :aria-pressed="custodyAction === 'HELD_FOR_CENTRAL' || requiresCentralCustody" @click="setCustodyAction('HELD_FOR_CENTRAL')" style="min-height: 48px; padding: 8px; border: 1px solid var(--color-primary); border-radius: var(--radius-interactive); background: var(--color-primary-subtle); color: var(--color-primary-dark); font: inherit; font-size: 12px;">Custodiar para caja central</button>
            </div>
          </div>

          <div v-if="custodyAction === 'LEFT_AT_RECEPTION' && !requiresCentralCustody" style="margin-top: 12px;">
            <label for="refund-receptionist-name" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600;">Persona que recibe el sobre *</label>
            <input id="refund-receptionist-name" v-model="receptionistName" type="text" maxlength="100" autocomplete="off" placeholder="Nombre de quien recibe el sobre" :disabled="disabled" style="box-sizing: border-box; width: 100%; min-height: 48px; padding: 10px 12px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); font: inherit;" />
          </div>
        </div>

        <div v-if="finding === 'UNVERIFIED_NO_CASH'" style="margin-top: 14px;">
          <label for="refund-inspection-justification" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600;">Justificación (20 caracteres como mínimo) *</label>
          <textarea id="refund-inspection-justification" v-model="justification" rows="3" minlength="20" :disabled="disabled" placeholder="Describe por qué no hay evidencia de saldo retenido." style="box-sizing: border-box; width: 100%; padding: 10px 12px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); font: inherit;"></textarea>
          <small>{{ justification.trim().length }} / 20 caracteres</small>
        </div>
      </fieldset>

      <div v-if="canRegisterUnclaimedCash" style="margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--color-hairline);">
        <label style="display: flex; align-items: flex-start; gap: 10px; min-height: 48px; cursor: pointer; font-size: 13px; font-weight: 600;">
          <input type="checkbox" :checked="includeUnclaimedCash" :disabled="disabled" @change="toggleUnclaimedCash($event.target.checked)" data-testid="unclaimed-cash-toggle" style="width: 20px; height: 20px; margin-top: 2px; accent-color: var(--color-primary);" />
          <span>Efectivo atascado recuperado de oficio (sin reclamación previa)</span>
        </label>
        <div v-if="includeUnclaimedCash" style="display: grid; gap: 10px; margin-top: 10px;">
          <label for="unclaimed-cash-amount" style="font-size: 13px; font-weight: 600;">Importe recuperado (€) *</label>
          <input id="unclaimed-cash-amount" :value="unclaimedAmount" @input="unclaimedAmount = $event.target.value" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="0,00" :disabled="disabled" style="box-sizing: border-box; width: 100%; min-height: 48px; padding: 10px 12px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); font: inherit;" />
          <label for="unclaimed-cash-notes" style="font-size: 13px; font-weight: 600;">Observación (opcional)</label>
          <textarea id="unclaimed-cash-notes" :value="unclaimedNotes" @input="unclaimedNotes = $event.target.value" rows="2" :disabled="disabled" placeholder="Dónde se encontró el efectivo" style="box-sizing: border-box; width: 100%; padding: 10px 12px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); font: inherit;"></textarea>
          <p style="margin: 0; color: var(--color-ink-muted); font-size: 12px;">Este efectivo queda registrado como sobrante no reclamado para caja central.</p>
        </div>
      </div>
    </section>
  `
};

export default TechnicianResolutionRefundBlock;
