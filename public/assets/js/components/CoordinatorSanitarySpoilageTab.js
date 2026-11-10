/**
 * VendGuard - CoordinatorSanitarySpoilageTab (CoordinatorSanitarySpoilageTab.js)
 *
 * Coordination surface for the HACCP spoilage log (Module 29, RF-04.1, RF-04.2,
 * RF-05.1, RF-05.2, QA 3, QA 4, C1, C5).
 *
 * What it solves:
 * 1. Four global KPI cards are loaded from the server and are immune to the
 *    table filters: filtering the history never rewrites the park totals.
 * 2. Active quarantines are listed by ascending machine code. A machine with an
 *    open incident shows a lock chip (C1); only a machine without one offers the
 *    lift action.
 * 3. The records table supports machine, origin, disposal method, date range and
 *    text filters, validates the date range before querying, and exports the
 *    filtered log as the CSV APPCC workbook.
 * 4. The loss adjustment modal mirrors C5: only records with discarded units can
 *    be adjusted, the amount must be positive and different from the current one,
 *    and the reason must carry 20-500 characters and at least three real words.
 *
 * Dogma Vanilla: Vue 3 ESM pure, no external dependencies.
 * Language duality: code in English, UI and messages in neutral Spanish.
 */

import { api } from '../api.js';
import { ModalDialog } from './ModalDialog.js';
import { SanitarySpoilageLiftModal } from './SanitarySpoilageLiftModal.js';
import { SanitarySpoilageOfficialActModal } from './SanitarySpoilageOfficialActModal.js';

const ORIGIN_OPTIONS = [
  { value: 'BIOLOGICAL_CLOCK_4H', label: 'Reloj biológico > 4 h (Avería)' },
  { value: 'PREVENTIVE_NON_CONFORMITY', label: 'Inspección preventiva NO CONFORME' },
  { value: 'MANUAL_SANITARY_LOCK', label: 'Bloqueo sanitario manual' }
];

const DISPOSAL_OPTIONS = [
  { value: 'ON_SITE_ORGANIC_SEALED', label: 'Destrucción in situ en contenedor sellado' },
  { value: 'RETURN_TO_CENTRAL_BIOHAZARD', label: 'Retirada precintada a base central' },
  { value: 'AUTHORIZED_WASTE_MANAGER', label: 'Entrega a gestor autorizado de residuos' },
  { value: 'NOT_APPLICABLE_EMPTY', label: 'No aplicable (máquina vacía)' }
];

const EMPTY_BATCH_CODES = 'SIN_STOCK_AFECTADO';
const EMPTY_BATCH_LABEL = 'Sin stock afectado';

const FILTER_KEYS = [
  'machine_id',
  'quarantine_origin',
  'disposal_method',
  'date_from',
  'date_to',
  'search'
];

const EMPTY_FILTERS = Object.freeze({
  machine_id: '',
  quarantine_origin: '',
  disposal_method: '',
  date_from: '',
  date_to: '',
  search: ''
});

/**
 * Unwraps the API envelope used by the backend (`{ data: ... }`).
 *
 * @param {unknown} response
 * @returns {Record<string, unknown>}
 */
function responseData(response) {
  const payload = response && typeof response === 'object' && 'data' in response
    ? response.data
    : response;

  return payload && typeof payload === 'object' ? payload : {};
}

/**
 * Reads the first array found in the response or in one of its known keys.
 *
 * @param {unknown} response
 * @param {string[]} keys
 * @returns {Array<Record<string, unknown>>}
 */
function responseList(response, keys = []) {
  const payload = responseData(response);
  if (Array.isArray(payload)) {
    return payload;
  }

  for (const key of keys) {
    if (Array.isArray(payload[key])) {
      return payload[key];
    }
  }

  return [];
}

function emptyKpis() {
  return {
    active_quarantined_machines_count: 0,
    total_records_count: 0,
    total_discarded_units_count: 0,
    total_estimated_loss_eur: 0
  };
}

export const CoordinatorSanitarySpoilageTab = {
  name: 'CoordinatorSanitarySpoilageTab',
  components: {
    ModalDialog,
    SanitarySpoilageLiftModal,
    SanitarySpoilageOfficialActModal
  },
  data() {
    return {
      kpis: emptyKpis(),
      activeQuarantinedMachines: [],
      records: [],
      machines: [],
      technicians: [],
      filters: { ...EMPTY_FILTERS },
      originOptions: ORIGIN_OPTIONS,
      disposalOptions: DISPOSAL_OPTIONS,
      isLoading: false,
      isExporting: false,
      errorMessage: '',
      successMessage: '',
      isOnline: typeof navigator === 'undefined' ? true : navigator.onLine !== false,
      showAdjustModal: false,
      selectedRecord: null,
      adjustmentAmountInput: '',
      adjustmentReason: '',
      adjustmentError: '',
      isAdjusting: false,
      showLiftModal: false,
      liftMachine: null,
      showOfficialActModal: false,
      officialActRecordId: null
    };
  },
  computed: {
    isBusy() {
      return this.isLoading || this.isExporting;
    },
    hasRecords() {
      return this.records.length > 0;
    },
    hasQuarantines() {
      return this.activeQuarantinedMachines.length > 0;
    },
    machineOptions() {
      const seen = new Set();
      const options = [];

      for (const machine of this.machines) {
        const id = Number(machine?.id ?? machine?.machine_id ?? 0);
        if (!Number.isInteger(id) || id <= 0 || seen.has(id)) {
          continue;
        }
        seen.add(id);
        const code = String(machine.code ?? machine.machine_code ?? `Máquina ${id}`);
        const location = String(machine.location_name ?? machine.location ?? '').trim();
        options.push({
          value: String(id),
          label: location === '' ? code : `${code} · ${location}`
        });
      }

      return options.sort((left, right) => left.label.localeCompare(right.label, 'es'));
    },
    currentAmount() {
      const value = Number(this.selectedRecord?.estimated_merchandise_loss_eur ?? 0);
      return Number.isFinite(value) ? value : 0;
    },
    adjustmentAmount() {
      const raw = String(this.adjustmentAmountInput ?? '').trim().replace(',', '.');
      if (raw === '' || !/^\d+(\.\d{1,2})?$/.test(raw)) {
        return null;
      }
      const value = Number(raw);
      return Number.isFinite(value) ? value : null;
    },
    normalizedAdjustmentReason() {
      return String(this.adjustmentReason ?? '').trim().replace(/\s+/g, ' ');
    },
    adjustmentReasonLength() {
      return [...this.normalizedAdjustmentReason].length;
    },
    adjustmentReasonBlockCount() {
      const blocks = this.normalizedAdjustmentReason.match(/[\p{L}\p{N}]+/gu);
      return Array.isArray(blocks) ? blocks.length : 0;
    },
    isAdjustmentAmountValid() {
      const amount = this.adjustmentAmount;
      return amount !== null
        && amount > 0
        && amount <= 99999.99
        && Math.abs(amount - this.currentAmount) >= 0.005;
    },
    isAdjustmentReasonValid() {
      return this.adjustmentReasonLength >= 20
        && this.adjustmentReasonLength <= 500
        && this.adjustmentReasonBlockCount >= 3;
    },
    canSubmitAdjustment() {
      return this.isOnline
        && this.selectedRecord !== null
        && this.isAdjustmentAmountValid
        && this.isAdjustmentReasonValid
        && !this.isAdjusting;
    }
  },
  mounted() {
    if (typeof window !== 'undefined') {
      window.addEventListener('online', this.syncConnectivity);
      window.addEventListener('offline', this.syncConnectivity);
    }
    this.loadAll();
  },
  beforeUnmount() {
    if (typeof window !== 'undefined') {
      window.removeEventListener('online', this.syncConnectivity);
      window.removeEventListener('offline', this.syncConnectivity);
    }
  },
  methods: {
    syncConnectivity() {
      this.isOnline = typeof navigator === 'undefined' ? true : navigator.onLine !== false;
    },

    async loadAll() {
      await Promise.all([this.loadPanel(), this.loadReferenceData()]);
    },

    /**
     * Prepares the query string without sending blank filters.
     *
     * @returns {Record<string, string>}
     */
    buildFilterParams() {
      const params = {};
      for (const key of FILTER_KEYS) {
        const value = String(this.filters[key] ?? '').trim();
        if (value !== '') {
          params[key] = value;
        }
      }
      return params;
    },

    /**
     * QA 4: the range must be coherent before the server is queried.
     *
     * @returns {boolean}
     */
    validateDateRange() {
      const from = String(this.filters.date_from ?? '').trim();
      const to = String(this.filters.date_to ?? '').trim();

      if (from !== '' && to !== '' && from > to) {
        this.errorMessage = 'La fecha «Desde» no puede ser posterior a la fecha «Hasta». Corrige el rango antes de filtrar.';
        return false;
      }

      return true;
    },

    async loadPanel() {
      if (!this.validateDateRange()) {
        return false;
      }

      this.isLoading = true;
      this.errorMessage = '';

      try {
        const response = await api.coordinator.getCoordinatorSanitarySpoilageRecords(this.buildFilterParams());
        const data = responseData(response);
        const kpis = data.kpis && typeof data.kpis === 'object' ? data.kpis : {};

        this.kpis = {
          active_quarantined_machines_count: Number(kpis.active_quarantined_machines_count ?? 0) || 0,
          total_records_count: Number(kpis.total_records_count ?? 0) || 0,
          total_discarded_units_count: Number(kpis.total_discarded_units_count ?? 0) || 0,
          total_estimated_loss_eur: Number(kpis.total_estimated_loss_eur ?? 0) || 0
        };

        const quarantines = Array.isArray(data.active_quarantined_machines)
          ? data.active_quarantined_machines
          : [];
        this.activeQuarantinedMachines = [...quarantines].sort((left, right) => (
          String(left?.machine_code ?? '').localeCompare(String(right?.machine_code ?? ''), 'es')
        ));

        this.records = Array.isArray(data.records) ? data.records : [];
        return true;
      } catch (error) {
        this.errorMessage = error?.message || 'No se pudieron cargar las actas de merma APPCC.';
        return false;
      } finally {
        this.isLoading = false;
      }
    },

    async loadReferenceData() {
      const [machinesResponse, usersResponse] = await Promise.all([
        api.coordinator.getMachines().catch(() => []),
        api.coordinator.getUsers({ role: 'TECHNICIAN', status: 'active' }).catch(() => [])
      ]);

      this.machines = responseList(machinesResponse, ['machines', 'items']);
      const users = responseList(usersResponse, ['users', 'items']);
      this.technicians = users
        .filter((user) => user?.is_active !== false)
        .map((user) => ({
          id: user.id,
          name: user.name || user.email || `Técnico #${user.id}`
        }));
    },

    async applyFilters() {
      this.successMessage = '';
      await this.loadPanel();
    },

    async resetFilters() {
      this.filters = { ...EMPTY_FILTERS };
      this.successMessage = '';
      await this.loadPanel();
    },

    async exportCsv() {
      if (!this.isOnline) {
        this.errorMessage = 'Sin conexión no es posible exportar el libro registro APPCC (C3).';
        return;
      }
      if (!this.validateDateRange()) {
        return;
      }

      this.isExporting = true;
      this.errorMessage = '';

      try {
        await api.coordinator.exportCoordinatorSanitarySpoilageCsv(this.buildFilterParams());
        this.successMessage = 'Libro registro APPCC exportado en CSV con los filtros vigentes.';
      } catch (error) {
        this.errorMessage = error?.message || 'No se pudo exportar el CSV APPCC.';
      } finally {
        this.isExporting = false;
      }
    },

    recordUnits(record) {
      const value = Number(record?.discarded_units_count ?? 0);
      return Number.isFinite(value) ? value : 0;
    },

    recordCanAdjust(record) {
      return this.recordUnits(record) > 0;
    },

    openLiftModal(machine) {
      if (!this.isOnline) {
        this.errorMessage = 'Sin conexión no es posible emitir el acta ni levantar la cuarentena (C3).';
        return;
      }
      if (machine?.has_open_incident === true) {
        return;
      }

      this.liftMachine = {
        id: Number(machine?.machine_id ?? 0),
        code: String(machine?.machine_code ?? ''),
        model: String(machine?.machine_model ?? ''),
        machine_type: String(machine?.machine_type ?? ''),
        location_name: String(machine?.location_name ?? '')
      };
      this.showLiftModal = true;
    },

    closeLiftModal() {
      this.showLiftModal = false;
      this.liftMachine = null;
    },

    async handleLifted(result) {
      const recordCode = String(result?.spoilage_record?.record_code ?? '').trim();
      this.successMessage = recordCode === ''
        ? 'Acta de merma emitida y cuarentena levantada.'
        : `Acta ${recordCode} emitida y cuarentena levantada.`;
      await Promise.all([this.loadPanel(), this.loadReferenceData()]);
    },

    openOfficialAct(record) {
      if (!this.isOnline) {
        this.errorMessage = 'Sin conexión no es posible consultar el acta oficial A4 (C3).';
        return;
      }
      const id = Number(record?.id ?? 0);
      if (!Number.isInteger(id) || id <= 0) {
        this.errorMessage = 'No se pudo identificar el acta oficial solicitada.';
        return;
      }

      this.showLiftModal = false;
      this.officialActRecordId = id;
      this.showOfficialActModal = true;
    },

    handleOpenOfficialAct(payload) {
      if (typeof payload === 'object' && payload !== null) {
        this.openOfficialAct(payload);
        return;
      }

      this.openOfficialAct({ id: payload });
    },

    openAdjustModal(record) {
      if (!this.isOnline) {
        this.errorMessage = 'Sin conexión no es posible ajustar el coste de la merma (C3).';
        return;
      }
      if (!this.recordCanAdjust(record)) {
        return;
      }

      this.selectedRecord = record;
      this.adjustmentAmountInput = Number(record.estimated_merchandise_loss_eur ?? 0).toFixed(2);
      this.adjustmentReason = String(record.loss_adjustment_reason ?? '');
      this.adjustmentError = '';
      this.showAdjustModal = true;
    },

    closeAdjustModal() {
      this.showAdjustModal = false;
      this.selectedRecord = null;
      this.adjustmentAmountInput = '';
      this.adjustmentReason = '';
      this.adjustmentError = '';
    },

    async submitAdjustment() {
      if (!this.canSubmitAdjustment) {
        this.adjustmentError = 'Revisa el importe y el motivo: el importe debe ser distinto del actual y el motivo debe tener al menos 3 palabras.';
        return;
      }

      this.isAdjusting = true;
      this.adjustmentError = '';

      try {
        const record = this.selectedRecord;
        await api.coordinator.adjustCoordinatorSpoilageLossEstimate(record.id, {
          estimated_merchandise_loss_eur: this.adjustmentAmount,
          loss_adjustment_reason: this.normalizedAdjustmentReason
        });

        const amountLabel = this.formatCurrency(this.adjustmentAmount);
        this.closeAdjustModal();
        this.successMessage = `Coste de la merma ajustado a ${amountLabel} con motivo auditado.`;
        await this.loadPanel();
      } catch (error) {
        this.adjustmentError = error?.message || 'No se pudo ajustar el coste de la merma.';
      } finally {
        this.isAdjusting = false;
      }
    },

    formatInteger(value) {
      const number = Number(value ?? 0);
      return new Intl.NumberFormat('es-ES').format(Number.isFinite(number) ? number : 0);
    },

    formatCurrency(value) {
      const number = Number(value ?? 0);
      return new Intl.NumberFormat('es-ES', {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      }).format(Number.isFinite(number) ? number : 0);
    },

    formatTemperature(value) {
      const number = Number(value);
      if (value === null || value === undefined || value === '' || !Number.isFinite(number)) {
        return '—';
      }
      return `${number.toFixed(1).replace('.', ',')} °C`;
    },

    recordTemperature(record) {
      return `${this.formatTemperature(record?.cabin_temp_at_removal)} → ${this.formatTemperature(record?.restored_stabilized_temp_c)}`;
    },

    formatDate(value) {
      if (value === null || value === undefined || value === '') {
        return '—';
      }
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) {
        return '—';
      }
      return new Intl.DateTimeFormat('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
      }).format(date);
    },

    formatDateTime(value) {
      if (value === null || value === undefined || value === '') {
        return '—';
      }
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) {
        return '—';
      }
      return new Intl.DateTimeFormat('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      }).format(date);
    },

    originLabel(value) {
      const normalized = String(value ?? '').trim().toUpperCase();
      const option = ORIGIN_OPTIONS.find((entry) => entry.value === normalized);
      return option ? option.label : this.humanizeCode(normalized);
    },

    disposalMethodLabel(value) {
      const normalized = String(value ?? '').trim().toUpperCase();
      const option = DISPOSAL_OPTIONS.find((entry) => entry.value === normalized);
      return option ? option.label : this.humanizeCode(normalized);
    },

    machineTypeLabel(value) {
      switch (String(value ?? '').trim().toUpperCase()) {
        case 'PERISHABLE_FOOD':
          return 'Alimentos perecederos';
        case 'COMBO':
          return 'Máquina mixta';
        case 'COLD_DRINKS':
          return 'Bebidas frías';
        case 'SNACKS':
          return 'Snacks';
        case 'NON_REFRIGERATED':
          return 'Sin refrigeración';
        default:
          return this.humanizeCode(value);
      }
    },

    humanizeCode(value) {
      const normalized = String(value ?? '').trim();
      if (normalized === '') {
        return '—';
      }
      const words = normalized.replace(/_/g, ' ').toLowerCase();
      return words.charAt(0).toUpperCase() + words.slice(1);
    },

    batchLabel(value) {
      const text = String(value ?? '').trim();
      if (text === '') {
        return '—';
      }
      return text.toUpperCase() === EMPTY_BATCH_CODES ? EMPTY_BATCH_LABEL : text;
    },

    shortBatch(value) {
      const text = this.batchLabel(value);
      if (text === '—') {
        return '—';
      }
      return text.length > 42 ? `${text.slice(0, 39)}…` : text;
    }
  },
  template: `
    <section class="vg-spoilage-tab" data-testid="coordinator-spoilage-tab">
      <!-- La tabla y los KPIs viven en styles.css, enlazada globalmente por index.html. -->

      <header class="vg-spoilage-tab__header">
        <div>
          <h3 class="vg-spoilage-tab__title">☣️ Actas de Merma y Cuarentena Sanitaria</h3>
          <p class="vg-spoilage-tab__subtitle">
            Trazabilidad APPCC del producto perecedero destruido. El Acta Oficial A4 excluye importes,
            datos personales y notas internas de taller (Art. V.4).
          </p>
        </div>
        <div class="vg-action-group">
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            :disabled="isBusy || !isOnline"
            @click="loadPanel"
            data-testid="spoilage-refresh"
          >
            🔄 Actualizar
          </button>
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            :disabled="isBusy || !isOnline"
            @click="exportCsv"
            data-testid="spoilage-export-csv"
          >
            📥 Exportar CSV APPCC
          </button>
        </div>
      </header>

      <div
        v-if="!isOnline"
        class="vg-spoilage-offline"
        role="alert"
        data-testid="spoilage-offline-notice"
      >
        Sin conexión: el alta del acta, el ajuste del coste y la consulta del documento oficial requieren
        validación central y numeración oficial (C3). No se encola ningún acta de merma.
      </div>

      <div v-if="errorMessage" class="vg-alert vg-alert-error" role="alert" data-testid="spoilage-error">
        <span>{{ errorMessage }}</span>
        <button type="button" class="vg-btn vg-btn-ghost vg-btn-icon vg-btn-close" aria-label="Cerrar aviso de error" @click="errorMessage = ''"></button>
      </div>

      <div v-if="successMessage" class="vg-alert vg-alert-info" role="status" data-testid="spoilage-success">
        <span>{{ successMessage }}</span>
        <button type="button" class="vg-btn vg-btn-ghost vg-btn-icon vg-btn-close" aria-label="Cerrar aviso de confirmación" @click="successMessage = ''"></button>
      </div>

      <div class="vg-spoilage-kpi-grid" data-testid="spoilage-kpis">
        <article class="vg-spoilage-kpi" data-testid="spoilage-kpi-quarantine">
          <span class="vg-spoilage-kpi__label">Máquinas en Cuarentena Activa</span>
          <strong class="vg-spoilage-kpi__value">{{ formatInteger(kpis.active_quarantined_machines_count) }}</strong>
          <span class="vg-spoilage-kpi__hint">Parque bloqueado pendiente de levantamiento</span>
        </article>
        <article class="vg-spoilage-kpi" data-testid="spoilage-kpi-records">
          <span class="vg-spoilage-kpi__label">Total de Actas Emitidas</span>
          <strong class="vg-spoilage-kpi__value">{{ formatInteger(kpis.total_records_count) }}</strong>
          <span class="vg-spoilage-kpi__hint">Registro histórico APPCC completo</span>
        </article>
        <article class="vg-spoilage-kpi" data-testid="spoilage-kpi-units">
          <span class="vg-spoilage-kpi__label">Unidades Perecederas Destruidas</span>
          <strong class="vg-spoilage-kpi__value">{{ formatInteger(kpis.total_discarded_units_count) }}</strong>
          <span class="vg-spoilage-kpi__hint">Producto retirado y destruido</span>
        </article>
        <article class="vg-spoilage-kpi" data-testid="spoilage-kpi-loss">
          <span class="vg-spoilage-kpi__label">Merma Económica Estimada (€)</span>
          <strong class="vg-spoilage-kpi__value">{{ formatCurrency(kpis.total_estimated_loss_eur) }}</strong>
          <span class="vg-spoilage-kpi__hint">Visible solo para Coordinación (Art. V.4)</span>
        </article>
      </div>

      <section class="vg-spoilage-panel" data-testid="spoilage-quarantine-list">
        <header class="vg-spoilage-panel__header">
          <div>
            <h4 class="vg-spoilage-panel__title">Máquinas activas en cuarentena</h4>
            <p class="vg-spoilage-panel__subtitle">
              Ordenadas por código ascendente. Una avería abierta bloquea el levantamiento hasta resolverse (C1).
            </p>
          </div>
          <span class="vg-chip vg-chip-warning">{{ formatInteger(activeQuarantinedMachines.length) }} activas</span>
        </header>

        <p v-if="!hasQuarantines" class="vg-spoilage-empty" data-testid="spoilage-quarantine-empty">
          No hay máquinas en cuarentena sanitaria: el parque está liberado.
        </p>

        <div v-else class="vg-spoilage-quarantine-grid">
          <article
            v-for="machine in activeQuarantinedMachines"
            :key="machine.machine_id"
            class="vg-spoilage-quarantine-card"
            :data-testid="'spoilage-quarantine-' + machine.machine_code"
          >
            <div class="vg-spoilage-quarantine-card__head">
              <div>
                <strong class="vg-spoilage-quarantine-card__code">{{ machine.machine_code }}</strong>
                <p class="vg-spoilage-muted">
                  {{ machine.machine_model }} · {{ machine.location_name }}
                </p>
              </div>
              <span class="vg-chip vg-chip-danger">CUARENTENA</span>
            </div>

            <dl class="vg-spoilage-quarantine-card__meta">
              <div>
                <dt>Tipología</dt>
                <dd>{{ machineTypeLabel(machine.machine_type) }}</dd>
              </div>
              <div>
                <dt>Última inspección</dt>
                <dd>{{ formatDate(machine.last_sanitary_inspection_at) }}</dd>
              </div>
              <div>
                <dt>Próxima revisión</dt>
                <dd>{{ formatDate(machine.next_sanitary_inspection_due) }}</dd>
              </div>
            </dl>

            <div class="vg-spoilage-quarantine-card__footer">
              <span
                v-if="machine.has_open_incident"
                class="vg-chip vg-chip-warning"
                :data-testid="'spoilage-open-incident-' + machine.machine_code"
              >
                🔒 Bloqueada por avería abierta {{ machine.open_incident_code || '' }}
              </span>
              <span v-else class="vg-spoilage-muted">
                Sin avería abierta: procede el acta de merma y el levantamiento.
              </span>

              <button
                v-if="!machine.has_open_incident"
                type="button"
                class="vg-btn vg-btn-sm vg-btn-primary"
                :disabled="!isOnline"
                @click="openLiftModal(machine)"
                :data-testid="'spoilage-lift-' + machine.machine_code"
              >
                ☣️ Emitir Acta y Levantar Cuarentena
              </button>
            </div>
          </article>
        </div>
      </section>

      <section class="vg-spoilage-panel">
        <header class="vg-spoilage-panel__header">
          <div>
            <h4 class="vg-spoilage-panel__title">Registro histórico de actas</h4>
            <p class="vg-spoilage-panel__subtitle">
              Los cuatro indicadores superiores son globales y no cambian al filtrar la tabla.
            </p>
          </div>
        </header>

        <div class="vg-spoilage-filters">
          <label class="vg-spoilage-field">
            <span>Máquina</span>
            <select v-model="filters.machine_id" class="vg-select" data-testid="spoilage-filter-machine" :disabled="isLoading">
              <option value="">Todas las máquinas</option>
              <option v-for="machine in machineOptions" :key="machine.value" :value="machine.value">
                {{ machine.label }}
              </option>
            </select>
          </label>

          <label class="vg-spoilage-field">
            <span>Origen de cuarentena</span>
            <select v-model="filters.quarantine_origin" class="vg-select" data-testid="spoilage-filter-origin" :disabled="isLoading">
              <option value="">Todos los orígenes</option>
              <option v-for="option in originOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>

          <label class="vg-spoilage-field">
            <span>Método de destrucción</span>
            <select v-model="filters.disposal_method" class="vg-select" data-testid="spoilage-filter-disposal" :disabled="isLoading">
              <option value="">Todos los métodos</option>
              <option v-for="option in disposalOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>

          <label class="vg-spoilage-field">
            <span>Desde</span>
            <input v-model="filters.date_from" type="date" class="vg-input" data-testid="spoilage-filter-date-from" :disabled="isLoading">
          </label>

          <label class="vg-spoilage-field">
            <span>Hasta</span>
            <input v-model="filters.date_to" type="date" class="vg-input" data-testid="spoilage-filter-date-to" :disabled="isLoading">
          </label>

          <label class="vg-spoilage-field vg-spoilage-field--search">
            <span>Buscar</span>
            <input
              v-model="filters.search"
              type="search"
              class="vg-input"
              placeholder="Acta, lote, certificado, máquina o sede"
              data-testid="spoilage-filter-search"
              :disabled="isLoading"
              @keyup.enter="applyFilters"
            >
          </label>
        </div>

        <div class="vg-action-group vg-spoilage-filter-actions">
          <button type="button" class="vg-btn vg-btn-primary" :disabled="isLoading" @click="applyFilters" data-testid="spoilage-apply-filters">
            🔎 Filtrar
          </button>
          <button type="button" class="vg-btn vg-btn-secondary" :disabled="isLoading" @click="resetFilters" data-testid="spoilage-reset-filters">
            Limpiar filtros
          </button>
        </div>

        <p v-if="isLoading" class="vg-spoilage-empty" data-testid="spoilage-loading">Cargando el libro registro APPCC…</p>

        <div v-else class="vg-spoilage-table-wrap">
          <table class="vg-spoilage-table" data-testid="spoilage-records-table">
            <thead>
              <tr>
                <th>Acta</th>
                <th>Fecha</th>
                <th>Máquina / Sede</th>
                <th>Origen</th>
                <th>Unidades</th>
                <th>Lotes</th>
                <th>Temperaturas</th>
                <th>Destrucción</th>
                <th>Técnico</th>
                <th>Merma estimada</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="record in records" :key="record.id" :data-testid="'spoilage-record-' + record.record_code">
                <td>
                  <strong>{{ record.record_code }}</strong>
                  <span class="vg-spoilage-muted">{{ record.certificate_code }}</span>
                </td>
                <td>{{ formatDateTime(record.created_at) }}</td>
                <td>
                  <strong>{{ record.machine_code }}</strong>
                  <span class="vg-spoilage-muted">{{ record.machine_model }} · {{ record.location_name }}</span>
                </td>
                <td>{{ originLabel(record.quarantine_origin) }}</td>
                <td class="vg-spoilage-num">{{ formatInteger(record.discarded_units_count) }}</td>
                <td><span :title="batchLabel(record.affected_batch_codes)">{{ shortBatch(record.affected_batch_codes) }}</span></td>
                <td class="vg-spoilage-num">{{ recordTemperature(record) }}</td>
                <td>{{ disposalMethodLabel(record.disposal_method) }}</td>
                <td>{{ record.technician_name || '—' }}</td>
                <td class="vg-spoilage-num">
                  <strong>{{ formatCurrency(record.estimated_merchandise_loss_eur) }}</strong>
                  <span v-if="record.loss_adjusted_by_name" class="vg-spoilage-muted">
                    Ajustada por {{ record.loss_adjusted_by_name }}
                  </span>
                </td>
                <td class="vg-spoilage-table__actions">
                  <div class="vg-row-actions">
                    <button
                      type="button"
                      class="vg-btn vg-btn-sm vg-btn-primary-outline"
                      :disabled="!isOnline"
                      @click="openOfficialAct(record)"
                      :data-testid="'spoilage-act-' + record.record_code"
                    >
                      📄 Acta A4
                    </button>
                    <button
                      v-if="recordCanAdjust(record)"
                      type="button"
                      class="vg-btn vg-btn-sm vg-btn-secondary"
                      :disabled="!isOnline"
                      @click="openAdjustModal(record)"
                      :data-testid="'spoilage-adjust-' + record.record_code"
                    >
                      💶 Ajustar coste
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>

          <p v-if="!hasRecords" class="vg-spoilage-empty" data-testid="spoilage-records-empty">
            No hay actas que coincidan con los filtros seleccionados.
          </p>
        </div>
      </section>

      <ModalDialog
        v-model="showAdjustModal"
        title="Ajustar coste estimado de la merma"
        subtitle="El ajuste queda auditado con su autor y motivo. Solo se admite en actas con unidades destruidas (C5)."
        size="md"
        @close="closeAdjustModal"
      >
        <form data-testid="spoilage-adjust-modal" @submit.prevent="submitAdjustment">
          <div v-if="selectedRecord" class="vg-spoilage-adjust-summary">
            <div>
              <span class="vg-spoilage-muted">Acta</span>
              <strong>{{ selectedRecord.record_code }}</strong>
            </div>
            <div>
              <span class="vg-spoilage-muted">Unidades destruidas</span>
              <strong>{{ formatInteger(selectedRecord.discarded_units_count) }}</strong>
            </div>
            <div>
              <span class="vg-spoilage-muted">Coste actual</span>
              <strong>{{ formatCurrency(currentAmount) }}</strong>
            </div>
          </div>

          <label class="vg-spoilage-field">
            <span>Nuevo coste estimado (€)</span>
            <input
              v-model="adjustmentAmountInput"
              type="number"
              class="vg-input"
              min="0.01"
              max="99999.99"
              step="0.01"
              inputmode="decimal"
              data-testid="spoilage-adjust-amount"
              :disabled="isAdjusting"
            >
          </label>
          <p class="vg-spoilage-hint">
            Debe ser mayor que 0,00 €, no superar 99.999,99 € y ser distinto del coste actual.
          </p>

          <label class="vg-spoilage-field">
            <span>Motivo del ajuste</span>
            <textarea
              v-model="adjustmentReason"
              class="vg-textarea"
              rows="3"
              maxlength="500"
              placeholder="Justifica el importe con el albarán o el coste unitario aplicado…"
              data-testid="spoilage-adjust-reason"
              :disabled="isAdjusting"
            ></textarea>
          </label>
          <p class="vg-spoilage-hint">
            {{ adjustmentReasonLength }}/500 caracteres · mínimo 20 y al menos 3 palabras reales (Art. V.1).
          </p>

          <p v-if="adjustmentError" class="vg-spoilage-inline-error" role="alert" data-testid="spoilage-adjust-error">
            {{ adjustmentError }}
          </p>
        </form>

        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" :disabled="isAdjusting" @click="closeAdjustModal">
            Cancelar
          </button>
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            :disabled="!canSubmitAdjustment"
            @click="submitAdjustment"
            data-testid="spoilage-adjust-submit"
          >
            💾 Guardar ajuste auditado
          </button>
        </template>
      </ModalDialog>

      <SanitarySpoilageLiftModal
        v-model="showLiftModal"
        :machine="liftMachine"
        user-role="COORDINATOR"
        :technicians="technicians"
        @lifted="handleLifted"
        @open-official-act="handleOpenOfficialAct"
        @close="closeLiftModal"
      />

      <SanitarySpoilageOfficialActModal
        v-model="showOfficialActModal"
        :record-id="officialActRecordId"
        @close="officialActRecordId = null"
      />
    </section>
  `
};

export default CoordinatorSanitarySpoilageTab;
