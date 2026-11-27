/**
 * VendGuard - CoordinatorDexAuditsSubTab
 *
 * Sexta sub-vista de `CoordinatorProductLoadingTab` para el libro EVA-DTS
 * `.dex` (Módulo 39; RF-07.1, RF-07.2, C6, QA-2, QA-3, QA-5; Art. III.2,
 * V.1 y V.4).
 *
 * Superficie de Coordinación:
 *   1. Cuatro KPIs del perímetro filtrado: vigentes, alertas térmicas,
 *      advertencias de identidad/reinicio/desorden y desviaciones de caja.
 *   2. Filtros por fecha, estado, puerta de importación, máquina y alertas.
 *   3. Carga manual de un `.dex` en escritorio sobre una máquina concreta.
 *   4. Listado financiero con descarga del original y anulación auditada.
 *   5. Detalle completo con líneas de selección, eventos y cruces M34/M35.
 *   6. Exportación CSV del mismo perímetro.
 *
 * El componente no reimplementa las reglas del servidor: la idempotencia, la
 * coherencia de la puerta, el mínimo de 20..500 caracteres y los tres bloques
 * alfanuméricos del motivo se validan también en el backend. Aquí solo se
 * anticipan las comprobaciones obvias para no enviar una petición condenada.
 *
 * Dogma Vanilla: Vue 3 Options API vía ES Modules nativos, cero dependencias.
 * Dualismo Lingüístico: código en inglés, textos de interfaz en castellano.
 */

import { api } from '../api.js';
import { formatDateTime } from '../utils/DateTimeFormat.js';
import { ModalDialog } from './ModalDialog.js';

const unwrap = (response) => (response && response.data !== undefined ? response.data : response);

const extractList = (payload, keys = []) => {
  if (Array.isArray(payload)) return payload;
  for (const key of keys) {
    if (Array.isArray(payload?.[key])) return payload[key];
  }
  return [];
};

const MAX_DEX_FILE_SIZE_BYTES = 1048576;

const emptyKpis = () => ({
  total_active: 0,
  thermal_alert_count: 0,
  warning_count: 0,
  cash_deviation_count: 0,
  voided_count: 0
});

const emptyFilters = () => ({
  date_from: '',
  date_to: '',
  status: 'ACTIVE',
  import_source: '',
  machine_id: '',
  alerts_only: false
});

const emptyManualForm = () => ({
  machine_id: '',
  captured_at: localDateTimeValue(),
  original_filename: '',
  raw_payload: '',
  expected_machine_code: ''
});

const IMPORT_SOURCE_LABELS = {
  RESTOCKER_ROUTE: 'Ruta del Reponedor',
  TECHNICIAN_ROUTE: 'Ruta del Técnico SAT',
  COORDINATOR_CONSOLE: 'Consola de Coordinación'
};

const STATUS_LABELS = {
  ACTIVE: 'Vigente',
  VOIDED: 'Anulado'
};

const newMutationId = () => {
  if (typeof globalThis !== 'undefined' && globalThis.crypto?.randomUUID) {
    return globalThis.crypto.randomUUID();
  }
  return `dex-${Date.now()}-${Math.random().toString(16).slice(2)}`;
};

const localDateTimeValue = () => {
  const now = new Date();
  const pad = (value) => String(value).padStart(2, '0');
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
};

const money = (value) => {
  if (value === null || value === undefined || value === '') return '—';
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) return '—';
  return new Intl.NumberFormat('es-ES', {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(numeric);
};

const integer = (value) => {
  if (value === null || value === undefined || value === '') return '—';
  const numeric = Number(value);
  return Number.isFinite(numeric) ? new Intl.NumberFormat('es-ES').format(numeric) : '—';
};

const reasonBlockCount = (reason) => {
  const blocks = String(reason ?? '').match(/[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9]+/g);
  return Array.isArray(blocks) ? blocks.length : 0;
};

export const CoordinatorDexAuditsSubTab = {
  name: 'CoordinatorDexAuditsSubTab',
  components: { ModalDialog },
  data() {
    return {
      audits: [],
      kpis: emptyKpis(),
      filters: emptyFilters(),
      machines: [],
      isLoading: false,
      loadError: '',
      successMessage: '',
      requestSequence: 0,

      manualForm: emptyManualForm(),
      manualFileError: '',
      manualImportError: '',
      isImporting: false,
      manualFileInputKey: 0,

      showDetailModal: false,
      selectedAudit: null,
      detail: null,
      detailError: '',
      isLoadingDetail: false,
      rawError: '',

      showVoidModal: false,
      voidTarget: null,
      voidReason: '',
      voidError: '',
      isVoiding: false
    };
  },
  computed: {
    safeKpis() {
      return { ...emptyKpis(), ...(this.kpis || {}) };
    },
    machineOptions() {
      return this.machines
        .map((machine) => ({
          id: Number(machine.id),
          label: `${machine.machine_code || machine.code || `#${machine.id}`}${machine.location_name ? ` · ${machine.location_name}` : ''}${machine.site_code ? ` (${machine.site_code})` : ''}`
        }))
        .filter((machine) => Number.isFinite(machine.id) && machine.id > 0);
    },
    voidReasonLength() {
      return String(this.voidReason ?? '').trim().length;
    },
    voidReasonBlocks() {
      return reasonBlockCount(this.voidReason);
    },
    voidReasonIsValid() {
      return this.voidReasonLength >= 20
        && this.voidReasonLength <= 500
        && this.voidReasonBlocks >= 3;
    },
    hasActiveFilters() {
      return Boolean(
        this.filters.date_from
        || this.filters.date_to
        || this.filters.import_source
        || this.filters.machine_id
        || this.filters.alerts_only
        || this.filters.status !== 'ACTIVE'
      );
    },
    exportsLabel() {
      return this.filters.status === 'ALL' ? 'Exportar CSV (todos)' : `Exportar CSV (${STATUS_LABELS[this.filters.status] || this.filters.status})`;
    }
  },
  mounted() {
    this.loadAudits();
    this.loadMachines();
  },
  methods: {
    formatDateTime,
    money,
    integer,
    pluralUnit(count, singular, plural) {
      return Number(count) === 1 ? singular : plural;
    },
    deltaUnits(audit) {
      const raw = audit?.effective_delta_vend_units ?? audit?.delta_vend_units;
      if (raw === null || raw === undefined || raw === '') return null;
      const value = Number(raw);
      return Number.isFinite(value) ? value : null;
    },
    sourceLabel(value, fallback = '') {
      return IMPORT_SOURCE_LABELS[value] || fallback || value || '—';
    },
    statusLabel(value, fallback = '') {
      return STATUS_LABELS[value] || fallback || value || '—';
    },
    statusTone(value) {
      return value === 'VOIDED' ? { color: 'var(--color-error-text)' } : { color: 'var(--color-success-text)' };
    },
    auditWarnings(audit) {
      const warnings = [];
      if (audit?.identity_mismatch_warning) warnings.push('Identidad');
      if (audit?.counter_reset_detected) warnings.push('Reinicio');
      if (audit?.out_of_order_sync) warnings.push('Desorden');
      if (audit?.has_thermal_alert) warnings.push('Térmica');
      return warnings;
    },
    machineLabel(machineId, fallback = '') {
      const found = this.machineOptions.find((machine) => Number(machine.id) === Number(machineId));
      return found?.label || fallback || (machineId ? `#${machineId}` : '—');
    },
    buildFilterParams() {
      const params = {};
      for (const field of ['date_from', 'date_to', 'status', 'import_source', 'machine_id']) {
        const value = this.filters[field];
        if (value !== '' && value !== null && value !== undefined) {
          params[field] = value;
        }
      }
      if (this.filters.alerts_only) {
        params.alerts_only = '1';
      }
      return params;
    },
    async loadMachines() {
      try {
        const payload = unwrap(await api.coordinator.getMachines());
        this.machines = extractList(payload, ['machines', 'data']);
      } catch {
        // El selector de máquina es una ayuda; el listado sigue siendo útil.
        this.machines = [];
      }
    },
    async loadAudits() {
      const sequence = ++this.requestSequence;
      this.isLoading = true;
      this.loadError = '';
      try {
        const payload = unwrap(await api.coordinator.getDexAudits(this.buildFilterParams()));
        if (sequence !== this.requestSequence) return;
        this.audits = extractList(payload, ['audits']);
        this.kpis = { ...emptyKpis(), ...(payload?.kpis || {}) };
      } catch (error) {
        if (sequence !== this.requestSequence) return;
        this.loadError = error?.message || 'No se pudo cargar el libro EVA-DTS.';
      } finally {
        if (sequence === this.requestSequence) {
          this.isLoading = false;
        }
      }
    },
    resetFilters() {
      this.filters = emptyFilters();
      this.loadAudits();
    },
    async handleManualFile(event) {
      const file = event?.target?.files?.[0];
      this.manualFileError = '';
      this.manualImportError = '';
      this.successMessage = '';
      if (!file) return;

      if (!/\.dex$/i.test(file.name)) {
        this.manualFileError = 'Selecciona un fichero con extensión .dex.';
        return;
      }
      if (file.size < 1 || file.size > MAX_DEX_FILE_SIZE_BYTES) {
        this.manualFileError = 'El volcado debe pesar entre 1 byte y 1 MB.';
        return;
      }

      try {
        const text = await file.text();
        if (typeof text !== 'string' || text.trim() === '') {
          this.manualFileError = 'El fichero .dex está vacío o no se pudo leer.';
          return;
        }
        this.manualForm.raw_payload = text;
        this.manualForm.original_filename = file.name;
      } catch {
        this.manualFileError = 'No se pudo leer el fichero .dex seleccionado.';
      }
    },
    async submitManualImport() {
      this.manualImportError = '';
      this.successMessage = '';
      if (!this.manualForm.machine_id) {
        this.manualImportError = 'Selecciona la máquina del volcado.';
        return;
      }
      if (!this.manualForm.raw_payload) {
        this.manualImportError = 'Adjunta un fichero .dex antes de importar.';
        return;
      }

      this.isImporting = true;
      try {
        const payload = {
          raw_payload: this.manualForm.raw_payload,
          original_filename: this.manualForm.original_filename || 'volcado.dex',
          captured_at: this.manualForm.captured_at || localDateTimeValue(),
          client_mutation_id: newMutationId()
        };
        if (this.manualForm.expected_machine_code.trim() !== '') {
          payload.expected_machine_code = this.manualForm.expected_machine_code.trim();
        }

        const response = unwrap(await api.coordinator.importDexAudit(this.manualForm.machine_id, payload));
        const audit = response?.audit || response;
        this.successMessage = `Volcado ${audit?.audit_code || ''} importado y auditado sin borrado físico.`;
        this.manualForm = emptyManualForm();
        this.manualFileInputKey += 1;
        await this.loadAudits();
      } catch (error) {
        this.manualImportError = error?.message || 'No se pudo importar el volcado .dex.';
      } finally {
        this.isImporting = false;
      }
    },
    async openDetail(audit) {
      this.selectedAudit = audit;
      this.detail = null;
      this.detailError = '';
      this.rawError = '';
      this.showDetailModal = true;
      this.isLoadingDetail = true;
      try {
        const payload = unwrap(await api.coordinator.getDexAudit(audit.id));
        this.detail = payload?.audit || payload;
      } catch (error) {
        this.detailError = error?.message || 'No se pudo cargar el detalle del volcado.';
      } finally {
        this.isLoadingDetail = false;
      }
    },
    closeDetail() {
      this.showDetailModal = false;
      this.detail = null;
      this.selectedAudit = null;
      this.detailError = '';
      this.rawError = '';
    },
    async downloadRaw(audit) {
      this.rawError = '';
      try {
        await api.coordinator.downloadDexAuditRaw(audit.id, audit.audit_code);
      } catch (error) {
        this.rawError = error?.message || 'No se pudo descargar el volcado original.';
      }
    },
    openVoid(audit) {
      this.voidTarget = audit;
      this.voidReason = '';
      this.voidError = '';
      this.showDetailModal = false;
      this.showVoidModal = true;
    },
    closeVoid() {
      this.showVoidModal = false;
      this.voidTarget = null;
      this.voidReason = '';
      this.voidError = '';
    },
    async submitVoid() {
      this.voidError = '';
      if (!this.voidReasonIsValid) {
        this.voidError = 'El motivo debe tener entre 20 y 500 caracteres y al menos 3 bloques alfanuméricos.';
        return;
      }

      this.isVoiding = true;
      try {
        await api.coordinator.voidDexAudit(this.voidTarget.id, { void_reason: this.voidReason.trim() });
        this.successMessage = `Volcado ${this.voidTarget.audit_code} anulado lógicamente; el original se conserva.`;
        this.closeVoid();
        await this.loadAudits();
      } catch (error) {
        this.voidError = error?.message || 'No se pudo anular el volcado.';
      } finally {
        this.isVoiding = false;
      }
    },
    async exportCsv() {
      this.loadError = '';
      try {
        await api.coordinator.exportDexAuditsCsv(this.buildFilterParams());
      } catch (error) {
        this.loadError = error?.message || 'No se pudo exportar el CSV de auditorías.';
      }
    }
  },
  template: `
    <div class="vg-stack" data-testid="coordinator-dex-audits-subtab">
      <div class="vg-action-group" style="flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 10px;">
        <div>
          <h3 style="margin: 0; font-family: var(--font-display); font-size: var(--font-size-heading-md); color: var(--color-ink-slate);">
            📄 Auditorías EVA-DTS (.dex)
          </h3>
          <p style="margin: 4px 0 0; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
            Libro financiero inmutable con trazabilidad del fichero original, cruces M34/M35 y anulación lógica.
          </p>
        </div>
        <div class="vg-action-group" style="gap: 6px; flex-wrap: wrap;">
          <button type="button" class="vg-btn vg-btn-secondary" data-testid="dex-refresh" :disabled="isLoading" @click="loadAudits">
            {{ isLoading ? 'Cargando…' : '↻ Actualizar' }}
          </button>
          <button type="button" class="vg-btn vg-btn-secondary" data-testid="dex-export-csv" @click="exportCsv">
            ⬇️ {{ exportsLabel }}
          </button>
        </div>
      </div>

      <div v-if="loadError" class="vg-alert vg-alert-error" role="alert" data-testid="dex-load-error">
        ⚠️ {{ loadError }}
      </div>
      <div v-if="successMessage" class="vg-alert vg-alert-success" role="status" data-testid="dex-success">
        ✅ {{ successMessage }}
      </div>

      <div data-testid="dex-kpis" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;">
        <div class="vg-card" style="padding: 14px;">
          <div class="vg-label">🟢 Vigentes</div>
          <div data-testid="dex-kpi-active" style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-primary);">
            {{ integer(safeKpis.total_active) }}
          </div>
        </div>
        <div class="vg-card" style="padding: 14px;">
          <div class="vg-label">🌡️ Alertas térmicas</div>
          <div data-testid="dex-kpi-thermal" style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-error-text);">
            {{ integer(safeKpis.thermal_alert_count) }}
          </div>
        </div>
        <div class="vg-card" style="padding: 14px;">
          <div class="vg-label">⚠️ Advertencias</div>
          <div data-testid="dex-kpi-warning" style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-warning-text);">
            {{ integer(safeKpis.warning_count) }}
          </div>
        </div>
        <div class="vg-card" style="padding: 14px;">
          <div class="vg-label">🪙 Desviación de caja</div>
          <div data-testid="dex-kpi-cash" style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-ink-slate);">
            {{ integer(safeKpis.cash_deviation_count) }}
          </div>
          <div v-if="safeKpis.voided_count > 0" style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">
            {{ integer(safeKpis.voided_count) }} {{ pluralUnit(safeKpis.voided_count, 'anulado', 'anulados') }} en el perímetro
          </div>
        </div>
      </div>

      <div class="vg-card" style="padding: 14px;">
        <div class="vg-label" style="margin-bottom: 10px;">Perímetro del libro</div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
          <div>
            <label class="vg-label" for="dex-filter-date-from">Desde</label>
            <input id="dex-filter-date-from" v-model="filters.date_from" class="vg-input" type="date" data-testid="dex-filter-date-from" @change="loadAudits">
          </div>
          <div>
            <label class="vg-label" for="dex-filter-date-to">Hasta</label>
            <input id="dex-filter-date-to" v-model="filters.date_to" class="vg-input" type="date" data-testid="dex-filter-date-to" @change="loadAudits">
          </div>
          <div>
            <label class="vg-label" for="dex-filter-status">Estado</label>
            <select id="dex-filter-status" v-model="filters.status" class="vg-input" data-testid="dex-filter-status" @change="loadAudits">
              <option value="ACTIVE">Vigentes</option>
              <option value="VOIDED">Anulados</option>
              <option value="ALL">Todos</option>
            </select>
          </div>
          <div>
            <label class="vg-label" for="dex-filter-source">Puerta</label>
            <select id="dex-filter-source" v-model="filters.import_source" class="vg-input" data-testid="dex-filter-source" @change="loadAudits">
              <option value="">Todas</option>
              <option value="RESTOCKER_ROUTE">Ruta del Reponedor</option>
              <option value="TECHNICIAN_ROUTE">Ruta del Técnico SAT</option>
              <option value="COORDINATOR_CONSOLE">Consola de Coordinación</option>
            </select>
          </div>
          <div>
            <label class="vg-label" for="dex-filter-machine">Máquina</label>
            <select id="dex-filter-machine" v-model="filters.machine_id" class="vg-input" data-testid="dex-filter-machine" @change="loadAudits">
              <option value="">Todas</option>
              <option v-for="machine in machineOptions" :key="machine.id" :value="machine.id">{{ machine.label }}</option>
            </select>
          </div>
          <label class="vg-label" style="display: flex; align-items: center; gap: 8px; padding-top: 18px; text-transform: none;">
            <input v-model="filters.alerts_only" type="checkbox" data-testid="dex-filter-alerts" @change="loadAudits">
            Solo con alertas
          </label>
        </div>
        <div class="vg-action-group" style="margin-top: 10px; gap: 8px;">
          <button type="button" class="vg-btn vg-btn-secondary" data-testid="dex-reset-filters" :disabled="!hasActiveFilters" @click="resetFilters">
            Limpiar filtros
          </button>
          <span v-if="hasActiveFilters" style="font-size: var(--font-size-caption); color: var(--color-ink-muted); align-self: center;">
            El CSV y los KPIs respetan este perímetro.
          </span>
        </div>
      </div>

      <div class="vg-card" style="padding: 14px;" data-testid="dex-manual-import">
        <div class="vg-label" style="margin-bottom: 4px;">Carga manual en escritorio</div>
        <p style="margin: 0 0 10px; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
          La puerta queda sellada como Consola de Coordinación. El servidor calcula el hash, encadena el delta y conserva el original.
        </p>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
          <div>
            <label class="vg-label" for="dex-manual-machine">Máquina</label>
            <select id="dex-manual-machine" v-model="manualForm.machine_id" class="vg-input" data-testid="dex-manual-machine">
              <option value="">Selecciona una máquina</option>
              <option v-for="machine in machineOptions" :key="machine.id" :value="machine.id">{{ machine.label }}</option>
            </select>
          </div>
          <div>
            <label class="vg-label" for="dex-manual-captured">Captura</label>
            <input id="dex-manual-captured" v-model="manualForm.captured_at" class="vg-input" type="datetime-local" data-testid="dex-manual-captured">
          </div>
          <div>
            <label class="vg-label" for="dex-manual-file">Fichero .dex (máx. 1 MB)</label>
            <input
              :key="manualFileInputKey"
              id="dex-manual-file"
              class="vg-input"
              type="file"
              accept=".dex,text/plain"
              data-testid="dex-manual-file"
              @change="handleManualFile"
            >
          </div>
          <div>
            <label class="vg-label" for="dex-manual-expected-code">Código esperado (opcional)</label>
            <input id="dex-manual-expected-code" v-model="manualForm.expected_machine_code" class="vg-input" type="text" placeholder="VEND-MAD-0001" data-testid="dex-manual-expected-code">
          </div>
        </div>
        <div v-if="manualForm.original_filename" style="margin-top: 8px; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
          Adjunto: <strong>{{ manualForm.original_filename }}</strong> · {{ integer(manualForm.raw_payload.length) }} bytes
        </div>
        <div v-if="manualFileError" class="vg-alert vg-alert-error" role="alert" style="margin-top: 10px;" data-testid="dex-manual-file-error">
          ⚠️ {{ manualFileError }}
        </div>
        <div v-if="manualImportError" class="vg-alert vg-alert-error" role="alert" style="margin-top: 10px;" data-testid="dex-manual-import-error">
          ⚠️ {{ manualImportError }}
        </div>
        <div class="vg-action-group" style="margin-top: 10px; gap: 8px;">
          <button type="button" class="vg-btn vg-btn-primary" data-testid="dex-manual-submit" :disabled="isImporting" @click="submitManualImport">
            {{ isImporting ? 'Importando…' : '⬆️ Importar volcado' }}
          </button>
        </div>
      </div>

      <div class="vg-card" style="padding: 0; max-width: 100%; overflow-x: auto;" data-testid="dex-table-wrapper">
        <table class="vg-table" data-testid="dex-audits-table" style="min-width: 1080px;">
          <thead>
            <tr>
              <th>Código</th>
              <th>Máquina / Sede</th>
              <th>Capturado</th>
              <th>Puerta</th>
              <th>Estado</th>
              <th>Ventas</th>
              <th>Unidades</th>
              <th>Delta</th>
              <th>Caja</th>
              <th>Alertas</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="audit in audits" :key="audit.id" :data-testid="'dex-audit-row-' + audit.id">
              <td style="font-family: monospace; white-space: nowrap;">{{ audit.audit_code }}</td>
              <td>
                <strong>{{ audit.machine_code_snapshot }}</strong>
                <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                  {{ audit.location_name || '—' }}<span v-if="audit.location_code"> · {{ audit.location_code }}</span>
                </div>
              </td>
              <td style="white-space: nowrap;">{{ formatDateTime(audit.captured_at) }}</td>
              <td>{{ sourceLabel(audit.import_source, audit.import_source_label) }}</td>
              <td :style="statusTone(audit.status)">{{ statusLabel(audit.status, audit.status_label) }}</td>
              <td style="white-space: nowrap;">{{ money(audit.va1_total_sales_eur) }}</td>
              <td>{{ integer(audit.va1_total_vend_units) }}</td>
              <td style="white-space: nowrap;">
                <div>{{ money(audit.effective_delta_sales_eur ?? audit.delta_sales_eur) }}</div>
                <div v-if="deltaUnits(audit) !== null" style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ integer(deltaUnits(audit)) }} uds.</div>
              </td>
              <td style="white-space: nowrap;">{{ money(audit.ca3_cashbox_eur) }}</td>
              <td>
                <span v-for="warning in auditWarnings(audit)" :key="warning" style="display: inline-block; margin-right: 4px; font-size: var(--font-size-caption); color: var(--color-warning-text);">
                  {{ warning }}
                </span>
                <span v-if="auditWarnings(audit).length === 0" style="color: var(--color-ink-muted);">—</span>
              </td>
              <td>
                <div class="vg-action-group" style="gap: 4px; flex-wrap: nowrap;">
                  <button type="button" class="vg-btn vg-btn-secondary" :data-testid="'dex-audit-detail-' + audit.id" @click="openDetail(audit)">Detalle</button>
                  <button type="button" class="vg-btn vg-btn-secondary" :data-testid="'dex-audit-download-' + audit.id" @click="downloadRaw(audit)">.dex</button>
                  <button
                    v-if="audit.status === 'ACTIVE'"
                    type="button"
                    class="vg-btn vg-btn-secondary"
                    :data-testid="'dex-audit-void-' + audit.id"
                    @click="openVoid(audit)"
                  >Anular</button>
                </div>
              </td>
            </tr>
            <tr v-if="!isLoading && audits.length === 0">
              <td colspan="11" style="text-align: center; padding: 28px; color: var(--color-ink-muted);" data-testid="dex-empty">
                No hay volcados .dex que coincidan con el perímetro.
              </td>
            </tr>
            <tr v-if="isLoading && audits.length === 0">
              <td colspan="11" style="text-align: center; padding: 28px; color: var(--color-ink-muted);">
                Cargando el libro EVA-DTS…
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="rawError" class="vg-alert vg-alert-error" role="alert" data-testid="dex-raw-error">⚠️ {{ rawError }}</div>

      <ModalDialog
        :model-value="showDetailModal"
        size="lg"
        title="Detalle del volcado EVA-DTS"
        subtitle="Lectura financiera reservada a Coordinación · Art. V.4"
        @update:model-value="showDetailModal = $event"
        @close="closeDetail"
      >
        <div v-if="isLoadingDetail" style="padding: 16px; color: var(--color-ink-muted);">Cargando detalle…</div>
        <div v-else-if="detailError" class="vg-alert vg-alert-error" role="alert" data-testid="dex-detail-error">⚠️ {{ detailError }}</div>
        <div v-else-if="detail" data-testid="dex-detail-body">
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 16px;">
            <div class="vg-card" style="padding: 10px;"><div class="vg-label">Código</div><strong style="font-family: monospace;">{{ detail.audit_code }}</strong></div>
            <div class="vg-card" style="padding: 10px;"><div class="vg-label">Máquina</div>{{ detail.machine_code }}</div>
            <div class="vg-card" style="padding: 10px;"><div class="vg-label">Estado</div><span :style="statusTone(detail.status)">{{ statusLabel(detail.status, detail.status_label) }}</span></div>
            <div class="vg-card" style="padding: 10px;"><div class="vg-label">Capturado</div>{{ formatDateTime(detail.captured_at) }}</div>
            <div class="vg-card" style="padding: 10px;"><div class="vg-label">Puerta</div>{{ sourceLabel(detail.import_source, detail.import_source_label) }}</div>
            <div class="vg-card" style="padding: 10px;"><div class="vg-label">Importado por</div>{{ detail.importer_name || '—' }}</div>
            <div class="vg-card" style="padding: 10px;"><div class="vg-label">Ventas VA1</div><strong>{{ money(detail.va1_total_sales_eur) }}</strong></div>
            <div class="vg-card" style="padding: 10px;"><div class="vg-label">Unidades VA1</div><strong>{{ integer(detail.va1_total_vend_units) }}</strong></div>
            <div class="vg-card" style="padding: 10px;"><div class="vg-label">Caja CA3</div><strong>{{ money(detail.ca3_cashbox_eur) }}</strong></div>
            <div class="vg-card" style="padding: 10px;"><div class="vg-label">Delta ventas</div>{{ money(detail.effective_delta_sales_eur ?? detail.delta_sales_eur) }}</div>
            <div class="vg-card" style="padding: 10px;"><div class="vg-label">Delta unidades</div>{{ integer(detail.effective_delta_vend_units ?? detail.delta_vend_units) }}</div>
            <div class="vg-card" style="padding: 10px;"><div class="vg-label">Fuente del delta</div>{{ detail.delta_source || '—' }}</div>
          </div>

          <div v-if="auditWarnings(detail).length > 0" class="vg-alert vg-alert-warning" role="status" style="margin-bottom: 14px;">
            ⚠️ {{ auditWarnings(detail).join(' · ') }}
          </div>

          <div class="vg-card" style="padding: 12px; margin-bottom: 14px;">
            <div class="vg-label" style="margin-bottom: 6px;">Trazabilidad del fichero y vínculos</div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; font-size: var(--font-size-caption);">
              <div><strong>Fichero:</strong> {{ detail.original_filename || '—' }}</div>
              <div><strong>Bytes:</strong> {{ integer(detail.file_size_bytes) }}</div>
              <div style="overflow-wrap: anywhere;"><strong>SHA-256:</strong> <span style="font-family: monospace;">{{ detail.sha256_hash || '—' }}</span></div>
              <div><strong>DXS:</strong> {{ detail.dxs_comm_id || '—' }} / {{ detail.dxs_version || '—' }}</div>
              <div><strong>ID máquina DEX:</strong> {{ detail.dex_machine_identifier || '—' }}</div>
              <div><strong>Decimales ID4:</strong> {{ detail.id4_decimal_places ?? '—' }}</div>
              <div><strong>Líneas ignoradas:</strong> {{ detail.ignored_unknown_lines_count ?? 0 }}</div>
              <div><strong>Visita M37:</strong> {{ detail.restock_visit_id || '—' }}</div>
              <div><strong>Recaudación M35:</strong> {{ detail.cash_collection_id || '—' }}</div>
              <div><strong>Incidencia M16:</strong> {{ detail.incident_id || '—' }}</div>
              <div><strong>Predecesor:</strong> {{ detail.previous_audit_id || '—' }}</div>
              <div><strong>Reinicio detectado:</strong> {{ detail.counter_reset_detected ? 'Sí' : 'No' }}</div>
              <div><strong>Desorden de sincronización:</strong> {{ detail.out_of_order_sync ? 'Sí' : 'No' }}</div>
              <div><strong>Predecesor anulado:</strong> {{ detail.previous_audit_voided ? 'Sí' : 'No' }}</div>
            </div>
            <div v-if="detail.void_reason" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--color-hairline);">
              <div class="vg-label">Anulación</div>
              <div style="white-space: pre-wrap;">{{ detail.void_reason }}</div>
              <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                {{ detail.voided_by_name_snapshot || '—' }} · {{ formatDateTime(detail.voided_at) }}
              </div>
            </div>
          </div>

          <div v-if="detail.m34_cross_check || detail.m35_cross_check" class="vg-card" style="padding: 12px; margin-bottom: 14px;">
            <div class="vg-label" style="margin-bottom: 6px;">Cruces no destructivos</div>
            <div v-if="detail.m34_cross_check" style="margin-bottom: 8px;">
              <strong>M34:</strong>
              <pre style="white-space: pre-wrap; overflow-wrap: anywhere; margin: 4px 0 0; font-size: var(--font-size-caption);">{{ JSON.stringify(detail.m34_cross_check, null, 2) }}</pre>
            </div>
            <div v-if="detail.m35_cross_check">
              <strong>M35:</strong>
              <pre style="white-space: pre-wrap; overflow-wrap: anywhere; margin: 4px 0 0; font-size: var(--font-size-caption);">{{ JSON.stringify(detail.m35_cross_check, null, 2) }}</pre>
            </div>
          </div>

          <div class="vg-label" style="margin-bottom: 6px;">Líneas de selección ({{ detail.selections?.length || 0 }})</div>
          <div style="overflow-x: auto; border: 1px solid var(--color-hairline); margin-bottom: 16px;" data-testid="dex-detail-selections-wrapper">
            <table class="vg-table" style="min-width: 720px;">
              <thead>
                <tr><th>Código</th><th>Normalizado</th><th>Match</th><th>Precio</th><th>Unidades</th><th>Ventas</th><th>Delta uds.</th><th>Delta €</th></tr>
              </thead>
              <tbody>
                <tr v-for="selection in (detail.selections || [])" :key="selection.id">
                  <td style="font-family: monospace;">{{ selection.raw_selection_code }}</td>
                  <td style="font-family: monospace;">{{ selection.normalized_selection_code }}</td>
                  <td>{{ selection.match_status_label || selection.match_status }}</td>
                  <td>{{ money(selection.unit_price_eur) }}</td>
                  <td>{{ integer(selection.total_vend_units) }}</td>
                  <td>{{ money(selection.total_sales_eur) }}</td>
                  <td>{{ integer(selection.delta_vend_units) }}</td>
                  <td>{{ money(selection.delta_sales_eur) }}</td>
                </tr>
                <tr v-if="(detail.selections || []).length === 0"><td colspan="8" style="text-align: center; color: var(--color-ink-muted);">Sin líneas de selección.</td></tr>
              </tbody>
            </table>
          </div>

          <div class="vg-label" style="margin-bottom: 6px;">Eventos ({{ detail.events?.length || 0 }})</div>
          <div style="overflow-x: auto; border: 1px solid var(--color-hairline);" data-testid="dex-detail-events-wrapper">
            <table class="vg-table" style="min-width: 720px;">
              <thead>
                <tr><th>Bloque</th><th>Código</th><th>Categoría</th><th>Severidad</th><th>Ocurrencias</th><th>Térmica</th><th>Descripción</th></tr>
              </thead>
              <tbody>
                <tr v-for="event in (detail.events || [])" :key="event.id">
                  <td>{{ event.source_block }}</td>
                  <td style="font-family: monospace;">{{ event.event_code }}</td>
                  <td>{{ event.event_category_label || event.event_category }}</td>
                  <td>{{ event.severity_label || event.severity }}</td>
                  <td>{{ integer(event.occurrence_count) }}</td>
                  <td>{{ event.is_thermal_alert ? 'Sí' : 'No' }}</td>
                  <td>{{ event.description_es || '—' }}</td>
                </tr>
                <tr v-if="(detail.events || []).length === 0"><td colspan="7" style="text-align: center; color: var(--color-ink-muted);">Sin eventos.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" data-testid="dex-detail-close" @click="closeDetail">Cerrar</button>
          <button v-if="detail" type="button" class="vg-btn vg-btn-secondary" data-testid="dex-detail-download" @click="downloadRaw(detail)">⬇️ Descargar .dex</button>
          <button v-if="detail && detail.status === 'ACTIVE'" type="button" class="vg-btn vg-btn-secondary" data-testid="dex-detail-void" @click="openVoid(detail)">Anular</button>
        </template>
      </ModalDialog>

      <ModalDialog
        :model-value="showVoidModal"
        size="md"
        title="Anular volcado .dex"
        subtitle="La anulación es lógica: el fichero, sus líneas y su hash se conservan."
        @update:model-value="showVoidModal = $event"
        @close="closeVoid"
      >
        <div v-if="voidTarget" data-testid="dex-void-body">
          <p style="margin-top: 0;">
            Vas a anular <strong style="font-family: monospace;">{{ voidTarget.audit_code }}</strong> de
            <strong>{{ voidTarget.machine_code_snapshot }}</strong>. El delta efectivo se recalculará sin destruir el original.
          </p>
          <label class="vg-label" for="dex-void-reason">Motivo (20–500 caracteres, mínimo 3 bloques alfanuméricos)</label>
          <textarea
            id="dex-void-reason"
            v-model="voidReason"
            class="vg-input"
            rows="5"
            maxlength="500"
            data-testid="dex-void-reason"
            placeholder="Explica qué invalida el volcado y qué evidencia lo respalda…"
            style="width: 100%; resize: vertical;"
          ></textarea>
          <div style="display: flex; justify-content: space-between; gap: 8px; margin-top: 4px; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
            <span>{{ voidReasonLength }} / 500 caracteres · {{ voidReasonBlocks }} {{ pluralUnit(voidReasonBlocks, 'bloque', 'bloques') }}</span>
            <span v-if="voidReasonLength > 0 && !voidReasonIsValid">Aún no cumple el mínimo.</span>
          </div>
          <div v-if="voidError" class="vg-alert vg-alert-error" role="alert" style="margin-top: 10px;" data-testid="dex-void-error">
            ⚠️ {{ voidError }}
          </div>
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" data-testid="dex-void-cancel" @click="closeVoid">Cancelar</button>
          <button type="button" class="vg-btn vg-btn-primary" data-testid="dex-void-confirm" :disabled="isVoiding || !voidReasonIsValid" @click="submitVoid">
            {{ isVoiding ? 'Anulando…' : 'Anular volcado' }}
          </button>
        </template>
      </ModalDialog>
    </div>
  `
};

export default CoordinatorDexAuditsSubTab;
