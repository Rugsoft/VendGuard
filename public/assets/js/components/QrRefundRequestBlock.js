/**
 * VendGuard - QrRefundRequestBlock
 *
 * Optional consumer refund capture for the public QR incident form (RF-REF-01/02).
 * Native Vue Options API and browser ESM only; validation runs locally as fields change.
 */

/**
 * Validates a Spanish Bizum mobile number, allowing common visual separators.
 * @param {string} phone
 * @returns {boolean}
 */
export function isValidBizumPhone(phone) {
  const normalized = String(phone ?? '').trim().replace(/[\s.()\-]/g, '');
  return /^[0-9]{9}$/.test(normalized);
}

/**
 * Validates Spanish IBAN structure and ISO 7064 MOD 97-10 checksum natively.
 * @param {string} iban
 * @returns {boolean}
 */
export function isValidSpanishIban(iban) {
  const normalized = String(iban ?? '').toUpperCase().replace(/[\s-]/g, '');
  if (!/^ES[0-9]{22}$/.test(normalized)) return false;

  const rearranged = normalized.slice(4) + normalized.slice(0, 4);
  let remainder = 0;
  for (const character of rearranged) {
    const digits = /[A-Z]/.test(character)
      ? String(character.charCodeAt(0) - 55)
      : character;
    for (const digit of digits) {
      remainder = (remainder * 10 + Number(digit)) % 97;
    }
  }
  return remainder === 1;
}

/**
 * Checks the data required to create a QR refund claim.
 * @param {Record<string, string|number>} claim
 * @param {boolean} hasPhysicalReception
 * @returns {boolean}
 */
export function isValidRefundClaim(claim, hasPhysicalReception = true) {
  const rawAmount = String(claim?.claimed_amount ?? '').trim();
  const amount = Number(rawAmount);
  if (!/^\d+(?:\.\d{1,2})?$/.test(rawAmount) || !Number.isFinite(amount) || amount <= 0 || amount > 50) return false;
  if (!String(claim?.contact_name ?? '').trim() || !String(claim?.contact_phone ?? '').trim()) return false;

  switch (claim?.compensation_method) {
    case 'EN_MANO_SEDE':
      return hasPhysicalReception;
    case 'BIZUM':
      return isValidBizumPhone(claim?.bizum_phone);
    case 'TRANSFERENCIA_BANCARIA':
      return isValidSpanishIban(claim?.iban);
    default:
      return false;
  }
}

export const QrRefundRequestBlock = {
  name: 'QrRefundRequestBlock',
  props: {
    hasPhysicalReception: {
      type: Boolean,
      default: true
    },
    disabled: {
      type: Boolean,
      default: false
    },
    modelValue: {
      type: Object,
      default: () => ({ refund_requested: false })
    }
  },
  emits: ['update:modelValue', 'validity-change'],
  data() {
    return {
      isExpanded: false,
      claimedAmount: '',
      contactName: '',
      contactPhone: '',
      compensationMethod: this.hasPhysicalReception ? 'EN_MANO_SEDE' : 'BIZUM',
      bizumPhone: '',
      iban: '',
      productAttempted: ''
    };
  },
  computed: {
    claim() {
      return {
        claimed_amount: this.claimedAmount === '' ? '' : Number(this.claimedAmount),
        contact_name: this.contactName,
        contact_phone: this.contactPhone,
        compensation_method: this.compensationMethod,
        bizum_phone: this.bizumPhone,
        iban: this.iban,
        product_attempted: this.productAttempted
      };
    },
    claimValid() {
      return !this.isExpanded || isValidRefundClaim(this.claim, this.hasPhysicalReception);
    },
    amountOverApprovalThreshold() {
      return Number(this.claimedAmount) > 10;
    },
    bizumValid() {
      return this.compensationMethod !== 'BIZUM' || isValidBizumPhone(this.bizumPhone);
    },
    ibanValid() {
      return this.compensationMethod !== 'TRANSFERENCIA_BANCARIA' || isValidSpanishIban(this.iban);
    }
  },
  watch: {
    hasPhysicalReception(available) {
      if (!available && this.compensationMethod === 'EN_MANO_SEDE') {
        this.compensationMethod = 'BIZUM';
      }
      this.publishClaim();
    }
  },
  methods: {
    toggleClaim(event) {
      this.isExpanded = Boolean(event?.target?.checked);
      this.publishClaim();
    },
    publishClaim() {
      this.$emit('update:modelValue', this.isExpanded
        ? { refund_requested: true, ...this.claim }
        : { refund_requested: false });
      this.$emit('validity-change', this.claimValid);
    }
  },
  template: `
    <section class="qr-refund-request" data-testid="qr-refund-request">
      <label class="qr-refund-toggle-row" for="refund-request-toggle">
        <input
          id="refund-request-toggle"
          class="qr-refund-toggle"
          type="checkbox"
          :checked="isExpanded"
          :disabled="disabled"
          @change="toggleClaim"
          data-testid="refund-request-toggle"
        />
        <span class="form-label">¿La máquina te ha tragado dinero o cobrado sin entregar producto?</span>
      </label>

      <div v-if="isExpanded" class="qr-refund-fields" data-testid="refund-request-fields">
        <p class="form-hint">Completa estos datos para registrar tu solicitud de devolución junto con el aviso.</p>

        <div class="form-group-grid">
          <div class="form-group">
            <label class="form-label" for="refund-claimant-name">Nombre del beneficiario <span class="required">*</span></label>
            <input id="refund-claimant-name" v-model="contactName" type="text" class="form-control" maxlength="100" required :disabled="disabled" @input="publishClaim" autocomplete="name" />
          </div>
          <div class="form-group">
            <label class="form-label" for="refund-claimant-contact">Teléfono o correo de contacto <span class="required">*</span></label>
            <input id="refund-claimant-contact" v-model="contactPhone" type="text" class="form-control" maxlength="100" required :disabled="disabled" @input="publishClaim" autocomplete="email" />
          </div>
        </div>

        <div class="form-group-grid">
          <div class="form-group">
            <label class="form-label" for="refund-claimed-amount">Importe retenido (€) <span class="required">*</span></label>
            <input id="refund-claimed-amount" v-model="claimedAmount" type="number" min="0.01" max="50" step="0.01" class="form-control" placeholder="0,00" required :disabled="disabled" @input="publishClaim" data-testid="refund-claimed-amount" />
            <span v-if="amountOverApprovalThreshold" class="form-hint">Los importes superiores a 10,00 € requieren revisión de Coordinación.</span>
            <span v-if="claimedAmount !== '' && (Number(claimedAmount) <= 0 || Number(claimedAmount) > 50)" class="form-hint text-error" role="alert">El importe debe ser mayor que 0,00 € y no superar 50,00 €.</span>
          </div>
          <div class="form-group">
            <label class="form-label" for="refund-compensation-method">Cómo quieres recibirlo <span class="required">*</span></label>
            <select id="refund-compensation-method" v-model="compensationMethod" class="form-control" required :disabled="disabled" @change="publishClaim" data-testid="refund-compensation-method">
              <option v-if="hasPhysicalReception" value="EN_MANO_SEDE">Recoger en conserjería</option>
              <option value="BIZUM">Bizum</option>
              <option value="TRANSFERENCIA_BANCARIA">Transferencia bancaria</option>
            </select>
          </div>
        </div>

        <div v-if="compensationMethod === 'BIZUM'" class="form-group">
          <label class="form-label" for="refund-bizum-phone">Móvil de Bizum <span class="required">*</span></label>
          <input id="refund-bizum-phone" v-model="bizumPhone" type="tel" inputmode="numeric" autocomplete="tel" class="form-control" placeholder="600 000 000" maxlength="15" required :disabled="disabled" :aria-invalid="bizumPhone !== '' && !bizumValid" @input="publishClaim" data-testid="refund-bizum-phone" />
          <span v-if="bizumPhone !== '' && !bizumValid" class="form-hint text-error" role="alert">Introduce un móvil español de 9 dígitos para Bizum.</span>
          <span v-else class="form-hint">El número debe tener 9 dígitos.</span>
        </div>

        <div v-if="compensationMethod === 'TRANSFERENCIA_BANCARIA'" class="form-group">
          <label class="form-label" for="refund-iban">IBAN español <span class="required">*</span></label>
          <input id="refund-iban" v-model="iban" type="text" autocomplete="off" autocapitalize="characters" class="form-control" placeholder="ES00 0000 0000 0000 0000 0000" maxlength="29" required :disabled="disabled" :aria-invalid="iban !== '' && !ibanValid" @input="publishClaim" data-testid="refund-iban" />
          <span v-if="iban !== '' && !ibanValid" class="form-hint text-error" role="alert">El IBAN español no es válido. Comprueba sus 24 caracteres y el dígito de control.</span>
          <span v-else class="form-hint">El IBAN se validará mientras lo escribes.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="refund-product-attempted">Producto que intentabas comprar <span class="text-muted">(opcional)</span></label>
          <input id="refund-product-attempted" v-model="productAttempted" type="text" maxlength="100" class="form-control" :disabled="disabled" @input="publishClaim" />
        </div>
      </div>
    </section>
  `
};

export default QrRefundRequestBlock;
