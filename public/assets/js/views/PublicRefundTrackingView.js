/**
 * VendGuard - PublicRefundTrackingView
 *
 * Anonymous consumer tracking page for refund cases (RF-REF-02, RF-REF-07).
 * The URL token is the only credential; the API DTO deliberately contains no
 * claimant identity or payment instrument, so this view never displays either.
 */

import { api } from '../api.js';
import { isValidBizumPhone } from '../components/QrRefundRequestBlock.js';

/**
 * Validates an IBAN using ISO 13616 / ISO 7064 MOD 97-10 without dependencies.
 * @param {string} iban
 * @returns {boolean}
 */
export function isValidTrackingIban(iban) {
  const normalized = String(iban ?? '').toUpperCase().replace(/[\s-]/g, '');
  if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/.test(normalized)) return false;
  if (normalized.startsWith('ES') && normalized.length !== 24) return false;

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

export const PublicRefundTrackingView = {
  name: 'PublicRefundTrackingView',
  props: {
    token: {
      type: String,
      default: ''
    }
  },
  data() {
    return {
      trackingToken: this.token || '',
      refund: null,
      loading: false,
      isSubmitting: false,
      errorMessage: '',
      successMessage: '',
      bizumPhone: '',
      iban: ''
    };
  },
  computed: {
    timelineSteps() {
      const status = this.refund?.status;
      const terminal = ['PAID_DIGITAL', 'REFUNDED_IN_HAND', 'REJECTED'].includes(status);
      const processingStarted = Boolean(status && status !== 'PENDING_INSPECTION');
      const processingComplete = terminal;
      return [
        { id: 'registered', label: 'Solicitud registrada', state: 'complete' },
        { id: 'inspection', label: 'Inspección técnica', state: status === 'PENDING_INSPECTION' ? 'current' : 'complete' },
        { id: 'processing', label: status === 'DEPOSITED_AT_RECEPTION' ? 'Efectivo disponible en conserjería' : (status === 'REQUIRES_COORDINATOR_APPROVAL' ? 'Revisión de Coordinación' : 'Tramitación de la devolución'), state: processingComplete ? 'complete' : (processingStarted ? 'current' : 'pending') },
        { id: 'finished', label: 'Resolución del expediente', state: terminal ? 'complete' : 'pending' }
      ];
    },
    showPickupPin() {
      return this.refund?.status === 'DEPOSITED_AT_RECEPTION'
        && /^[0-9]{4}$/.test(String(this.refund?.pickup_pin || ''));
    },
    canRectify() {
      return this.refund?.status === 'PENDING_CONTACT' && this.refund?.can_rectify_data === true;
    },
    rectificationMethod() {
      return this.refund?.compensation_method === 'BIZUM' ? 'BIZUM' : 'TRANSFERENCIA_BANCARIA';
    },
    isRectificationValid() {
      return this.rectificationMethod === 'BIZUM'
        ? isValidBizumPhone(this.bizumPhone)
        : isValidTrackingIban(this.iban);
    },
    formattedAmount() {
      const amount = Number(this.refund?.claimed_amount);
      return Number.isFinite(amount)
        ? new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(amount)
        : '—';
    }
  },
  mounted() {
    this.loadTracking();
  },
  methods: {
    async loadTracking() {
      this.errorMessage = '';
      if (!this.trackingToken.trim()) {
        this.refund = null;
        this.errorMessage = 'El enlace de seguimiento no contiene un localizador válido.';
        return;
      }

      this.loading = true;
      try {
        const result = await api.publicRefunds.track(this.trackingToken.trim());
        this.refund = result?.data !== undefined ? result.data : result;
      } catch (error) {
        this.refund = null;
        this.errorMessage = error.message || 'No ha sido posible consultar la devolución. Comprueba el enlace e inténtalo de nuevo.';
      } finally {
        this.loading = false;
      }
    },
    async submitRectification() {
      if (!this.canRectify || !this.isRectificationValid || this.isSubmitting) return;

      this.isSubmitting = true;
      this.errorMessage = '';
      this.successMessage = '';
      const payload = this.rectificationMethod === 'BIZUM'
        ? { bizum_phone: this.bizumPhone.trim() }
        : { iban: this.iban.trim().toUpperCase().replace(/[\s-]/g, '') };

      try {
        await api.publicRefunds.rectify(this.trackingToken.trim(), payload);
        this.successMessage = 'Hemos actualizado tus datos. Coordinación continuará tramitando tu devolución.';
        this.bizumPhone = '';
        this.iban = '';
        await this.loadTracking();
      } catch (error) {
        this.errorMessage = error.message || 'No se han podido actualizar los datos. Revísalos e inténtalo de nuevo.';
      } finally {
        this.isSubmitting = false;
      }
    },
    formatDate(value) {
      if (!value) return '—';
      const date = new Date(value);
      return Number.isNaN(date.getTime())
        ? String(value)
        : new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
    }
  },
  template: `
    <div class="public-refund-tracking" style="width: min(calc(100% - 32px), 720px); margin: 28px auto; color: var(--color-ink-slate);">
      <header style="margin-bottom: 20px;">
        <div style="font-size: 13px; font-weight: 700; color: var(--color-primary); letter-spacing: .04em;">VENDGUARD · ATENCIÓN AL CONSUMIDOR</div>
        <h1 style="margin: 8px 0 4px; font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 28px;">Seguimiento de tu devolución</h1>
        <p style="margin: 0; color: var(--color-ink-muted); font-size: 14px;">Consulta el estado de tu solicitud de forma segura.</p>
      </header>

      <section v-if="loading" class="vg-card" aria-live="polite" style="padding: 22px; text-align: center;">Consultando tu expediente...</section>
      <section v-else-if="errorMessage && !refund" class="vg-card" role="alert" style="padding: 20px; border-color: var(--color-error); background: var(--color-error-bg);">
        <strong>No se ha podido cargar el seguimiento</strong>
        <p style="margin: 8px 0 14px;">{{ errorMessage }}</p>
        <button type="button" class="vg-btn vg-btn-secondary" @click="loadTracking">Volver a intentar</button>
      </section>

      <template v-else-if="refund">
        <section class="vg-card" style="padding: 20px; margin-bottom: 16px;">
          <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; align-items: flex-start;">
            <div>
              <div style="font-size: 12px; color: var(--color-ink-muted);">ESTADO ACTUAL</div>
              <h2 style="margin: 5px 0; font-size: 20px;">{{ refund.status_label }}</h2>
            </div>
            <strong style="font-size: 24px; color: var(--color-primary); white-space: nowrap;">{{ formattedAmount }}</strong>
          </div>
          <p style="margin: 10px 0 0; line-height: 1.5;">{{ refund.status_description }}</p>
          <div style="display: flex; flex-wrap: wrap; gap: 8px 18px; margin-top: 14px; font-size: 13px; color: var(--color-ink-muted);">
            <span v-if="refund.machine_code">Máquina: <strong>{{ refund.machine_code }}</strong></span>
            <span v-if="refund.location_name">Centro: <strong>{{ refund.location_name }}</strong></span>
            <span v-if="refund.product_attempted">Producto: <strong>{{ refund.product_attempted }}</strong></span>
          </div>
          <div style="margin-top: 12px; font-size: 12px; color: var(--color-ink-muted);">Solicitud: {{ formatDate(refund.created_at) }} · Última actualización: {{ formatDate(refund.updated_at) }}</div>
        </section>

        <section class="vg-card" style="padding: 20px; margin-bottom: 16px;" aria-labelledby="refund-timeline-title">
          <h2 id="refund-timeline-title" style="margin: 0 0 16px; font-size: 17px;">Evolución de tu solicitud</h2>
          <ol class="tracking-timeline" style="list-style: none; padding: 0; margin: 0; display: grid; gap: 14px;">
            <li v-for="(step, index) in timelineSteps" :key="step.id" :data-state="step.state" style="display: flex; align-items: flex-start; gap: 12px;">
              <span aria-hidden="true" :style="{ width: '26px', height: '26px', flex: '0 0 26px', display: 'grid', placeItems: 'center', borderRadius: '50%', border: '2px solid', borderColor: step.state === 'pending' ? 'var(--color-hairline)' : 'var(--color-primary)', background: step.state === 'complete' ? 'var(--color-primary)' : 'var(--color-surface-card)', color: step.state === 'complete' ? 'var(--color-surface-card)' : 'var(--color-primary)', fontWeight: '700' }">{{ step.state === 'complete' ? '✓' : index + 1 }}</span>
              <span :style="{ paddingTop: '4px', color: step.state === 'pending' ? 'var(--color-ink-muted)' : 'var(--color-ink-slate)', fontWeight: step.state === 'current' ? '700' : '500' }">{{ step.label }}<small v-if="step.state === 'current'" style="display: block; color: var(--color-primary); font-weight: 600; margin-top: 3px;">En curso</small></span>
            </li>
          </ol>
        </section>

        <section v-if="showPickupPin" class="vg-card" data-testid="pickup-pin-card" style="padding: 20px; margin-bottom: 16px; border-color: var(--color-success); background: var(--color-success-bg); text-align: center;">
          <h2 style="margin: 0 0 6px; font-size: 18px;">Tu dinero está en conserjería</h2>
          <p style="margin: 0 0 10px;">Presenta este PIN de cuatro cifras para recogerlo en el centro indicado.</p>
          <strong style="display: block; color: var(--color-primary); font-size: 36px; letter-spacing: .24em;" aria-label="PIN de recogida">{{ refund.pickup_pin }}</strong>
          <span style="font-size: 13px;">Importe disponible: {{ formattedAmount }}</span>
        </section>

        <section v-if="canRectify" class="vg-card" style="padding: 20px; margin-bottom: 16px;" aria-labelledby="rectification-title">
          <h2 id="rectification-title" style="margin: 0 0 6px; font-size: 18px;">Corrige tus datos de pago</h2>
          <p style="margin: 0 0 14px; color: var(--color-ink-muted);">El dato corregido se enviará de forma segura a Coordinación. No mostraremos tus datos bancarios en el seguimiento.</p>
          <form @submit.prevent="submitRectification">
            <div v-if="rectificationMethod === 'BIZUM'" style="margin-bottom: 14px;">
              <label for="tracking-bizum-phone" style="display: block; margin-bottom: 6px; font-weight: 600;">Nuevo móvil de Bizum</label>
              <input id="tracking-bizum-phone" v-model="bizumPhone" type="tel" inputmode="numeric" autocomplete="tel" maxlength="15" placeholder="600 000 000" required style="box-sizing: border-box; width: 100%; padding: 10px 12px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); font: inherit;" />
              <small v-if="bizumPhone && !isRectificationValid" role="alert" style="display: block; margin-top: 5px; color: var(--color-error-text);">Introduce un móvil español de 9 dígitos.</small>
            </div>
            <div v-else style="margin-bottom: 14px;">
              <label for="tracking-iban" style="display: block; margin-bottom: 6px; font-weight: 600;">Nuevo IBAN español</label>
              <input id="tracking-iban" v-model="iban" type="text" autocomplete="off" autocapitalize="characters" maxlength="29" placeholder="ES00 0000 0000 0000 0000 0000" required style="box-sizing: border-box; width: 100%; padding: 10px 12px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); font: inherit;" />
              <small v-if="iban && !isRectificationValid" role="alert" style="display: block; margin-top: 5px; color: var(--color-error-text);">Comprueba los 24 caracteres y el dígito de control del IBAN.</small>
            </div>
            <button type="submit" class="vg-btn vg-btn-primary" :disabled="!isRectificationValid || isSubmitting" style="min-height: 40px;">{{ isSubmitting ? 'Guardando...' : 'Actualizar datos' }}</button>
          </form>
        </section>

        <p v-if="successMessage" role="status" aria-live="polite" style="padding: 12px 14px; border: 1px solid var(--color-success); border-radius: var(--radius-interactive); background: var(--color-success-bg); color: var(--color-success-text);">{{ successMessage }}</p>
        <p v-if="errorMessage" role="alert" style="padding: 12px 14px; border: 1px solid var(--color-error); border-radius: var(--radius-interactive); background: var(--color-error-bg); color: var(--color-error-text);">{{ errorMessage }}</p>
        <div style="margin: 18px 0 30px; text-align: center; font-size: 12px; color: var(--color-ink-muted);">Tu enlace es personal. No compartas este localizador con otras personas.</div>
      </template>
    </div>
  `
};

export default PublicRefundTrackingView;
