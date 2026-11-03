/**
 * VendGuard - CoordinatorWorkshopTrayTab (Módulo 27)
 *
 * Bandeja de taller central: la cola donde Coordinación acusa recibo de las piezas
 * que el técnico extrajo, las pasa por el banco y emite el dictamen final.
 *
 * CUATRO LECTURAS DE CABECERA (RF-05.1)
 *
 * Pendientes de entrega, unidades en taller/banco, tasa de recuperación y ahorro
 * neto acumulado. Los tres primeros son recuentos; el cuarto es dinero y por eso
 * lleva `tabular-nums`: una columna de importes que baila de ancho es una columna
 * que no se puede comparar de un vistazo.
 *
 * EL `summary` NO SIGUE A LA PESTAÑA (QA 2)
 *
 * El servidor calcula los indicadores ignorando el filtro de estado: cambiar de
 * "Pendientes" a "Reacondicionadas" no reescribe la cabecera. El componente no
 * recalcula nada en cliente ni suma la página visible, porque una página no es la
 * bandeja.
 *
 * TRES MUTACIONES, TRES DIÁLOGOS
 *
 * Recibir exige nota opcional de 500 caracteres como máximo; iniciar banco es un
 * solo toque; dictaminar exige diagnóstico de 20..1000 caracteres y al menos tres
 * palabras (Art. V.1), más destino de reintegro. Los topes se cuentan en vivo y el
 * botón no se habilita con relleno de símbolos.
 *
 * DOS GUARDAS QUE SE VEN ANTES DE ENVIAR
 *
 *  - `TECHNICIAN_VAN` queda bloqueado si la pieza está fuera de catálogo (C3), con
 *    el motivo escrito al lado y no un error del servidor después.
 *  - Si el coste iguala o supera el valor de reposición, aparece la alerta ámbar
 *    "Reparación sin ahorro neto" (C2). El servidor aplica suelo cero; la interfaz
 *    lo dice antes de firmar.
 *
 * DISEÑO
 *
 * Tokens del sistema (`var(--color-…)`, `var(--radius-…)`, `var(--font-…)`) y ni un
 * literal hexadecimal: la deuda cromática está congelada. Los botones visten la
 * escalera `vg-btn`; el ámbar queda para lecturas y alertas, nunca para pintar un
 * botón primario.
 *
 * Dogma Vanilla: Vue 3 Options API con plantilla en navegador, sin bundler.
 * Dualismo Lingüístico: código en inglés, texto de interfaz en castellano.
 */

import { api } from '../api.js';
import { ModalDialog } from './ModalDialog.js';

/** Rótulos del ciclo canónico de taller. */
const STATUS_LABELS = Object.freeze({
  PENDING_DELIVERY: 'Pendiente de entrega',
  RECEIVED_IN_WORKSHOP: 'Recibida en taller',
  IN_BENCH_TEST: 'En banco de pruebas',
  REFURBISHED_RESTORED: 'Reacondicionada',
  SCRAPPED_IRREPARABLE: 'Irreparable / chatarrería'
});

/** Píldoras de estado de la barra de filtros, en el orden del contrato. */
const STATUS_FILTERS = Object.freeze([
  { value: 'ALL', label: 'Todas' },
  { value: 'PENDING_DELIVERY', label: 'Pendientes de entrega' },
  { value: 'RECEIVED_IN_WORKSHOP', label: 'Recibidas en taller' },
  { value: 'IN_BENCH_TEST', label: 'En banco de pruebas' },
  { value: 'REFURBISHED_RESTORED', label: 'Reacondicionadas' },
  { value: 'SCRAPPED_IRREPARABLE', label: 'Irreparables / Chatarrería' }
]);

const CATEGORY_OPTIONS = Object.freeze([
  { value: 'ALL', label: 'Todas las categorías' },
  { value: 'PAYMENT', label: 'Pago' },
  { value: 'COOLING', label: 'Frío' },
  { value: 'DISPENSING', label: 'Dispensación' },
  { value: 'ELECTRONICS', label: 'Electrónica' },
  { value: 'WATER_COFFEE', label: 'Agua / café' },
  { value: 'OTHER', label: 'Otra' }
]);

const CATEGORY_LABELS = Object.freeze({
  PAYMENT: 'Pago',
  COOLING: 'Frío',
  DISPENSING: 'Dispensación',
  ELECTRONICS: 'Electrónica',
  WATER_COFFEE: 'Agua / café',
  OTHER: 'Otra'
});

const EMPTY_RESOLVE_FORM = Object.freeze({
  outcome_status: 'REFURBISHED_RESTORED',
  bench_diagnosis: '',
  refurbishment_cost_eur: 0,
  restock_destination: 'CENTRAL_WAREHOUSE',
  restocked_to_technician_id: ''
});

function emptyResolveForm() {
  return { ...EMPTY_RESOLVE_FORM };
}

export const CoordinatorWorkshopTrayTab = {
  name: 'CoordinatorWorkshopTrayTab',

  components: {
    ModalDialog
  },

  props: {
    active: {
      type: Boolean,
      default: true
    }
  },

  emits: ['summary-changed'],

  data() {
    return {
      items: [],
      summary: {},
      total: 0,
      page: 1,
      totalPages: 1,
      technicians: [],
      filters: {
        status: 'ALL',
        technician_id: '',
        category: 'ALL',
        search: ''
      },
      isLoading: false,
      isExporting: false,
      errorMessage: '',
      successMessage: '',
      searchTimer: null,

      showReceiveModal: false,
      receiveTarget: null,
      receptionNotes: '',
      isReceiving: false,
      receiveError: '',

      showResolveModal: false,
      resolveTarget: null,
      resolveForm: emptyResolveForm(),
      isResolving: false,
      resolveError: ''
    };
  },

  computed: {
    statusFilters() {
      return STATUS_FILTERS;
    },

    categoryOptions() {
      return CATEGORY_OPTIONS;
    },

    kpis() {
      return [
        {
          key: 'pending',
          label: 'Pendientes de Entrega',
          value: Number(this.summary.pending_delivery_count || 0),
          hint: 'Aún en la furgoneta del técnico'
        },
        {
          key: 'in-workshop',
          label: 'En Taller / Banco',
          value: Number(this.summary.in_workshop_count || 0),
          hint: 'Recibidas o en comprobación'
        },
        {
          key: 'recovery',
          label: 'Tasa de Recuperación (%)',
          value: this.formatPercent(this.summary.recovery_rate_pct),
          hint: 'Reacondicionadas sobre evaluadas'
        },
        {
          key: 'savings',
          label: 'Ahorro Neto Acumulado (€)',
          value: this.formatMoney(this.summary.total_net_savings_eur),
          hint: 'Valor repuesto menos coste'
        }
      ];
    },

    /** Unidades que esperan una acción: alimenta el badge de la sub-pestaña. */
    activeUnits() {
      return Number(this.summary.pending_delivery_count || 0)
        + Number(this.summary.in_workshop_count || 0);
    },

    isEmpty() {
      return !this.isLoading && this.items.length === 0;
    },

    /** Longitud real del diagnóstico, en puntos de código (Art. V.1). */
    diagnosisLength() {
      return Array.from(String(this.resolveForm.bench_diagnosis || '').trim()).length;
    },

    /** Tokens alfanuméricos: el relleno de símbolos no acredita una revisión. */
    diagnosisWords() {
      const matches = String(this.resolveForm.bench_diagnosis || '').match(/[\p{L}\p{N}]+/gu);

      return matches ? matches.length : 0;
    },

    isDiagnosisValid() {
      return this.diagnosisLength >= 20
        && this.diagnosisLength <= 1000
        && this.diagnosisWords >= 3;
    },

    isOutOfCatalog() {
      return this.resolveTarget?.is_out_of_catalog === true
        || this.resolveTarget?.is_out_of_catalog === 't'
        || this.resolveTarget?.is_out_of_catalog === 1
        || this.resolveTarget?.is_out_of_catalog === '1';
    },

    isRefurbishment() {
      return this.resolveForm.outcome_status === 'REFURBISHED_RESTORED';
    },

    /** C2: el coste iguala o supera el valor de reposición de la pieza. */
    hasZeroNetSavings() {
      if (!this.isRefurbishment) {
        return false;
      }

      const cost = Number(this.resolveForm.refurbishment_cost_eur);
      const value = Number(this.resolveTarget?.unit_cost_snapshot);

      return Number.isFinite(cost) && Number.isFinite(value) && cost >= value;
    },

    isCostValid() {
      const cost = Number(this.resolveForm.refurbishment_cost_eur);

      return Number.isFinite(cost) && cost >= 0 && cost <= 9999.99;
    },

    isRestockValid() {
      if (!this.isRefurbishment) {
        return true;
      }

      if (this.resolveForm.restock_destination === 'CENTRAL_WAREHOUSE') {
        return true;
      }

      if (this.resolveForm.restock_destination !== 'TECHNICIAN_VAN' || this.isOutOfCatalog) {
        return false;
      }

      return String(this.resolveForm.restocked_to_technician_id).trim() !== '';
    },

    canResolve() {
      return this.isDiagnosisValid
        && this.isCostValid
        && this.isRestockValid
        && !this.isResolving;
    },

    canReceive() {
      return this.receptionNotesLength <= 500 && !this.isReceiving;
    },

    receptionNotesLength() {
      return Array.from(String(this.receptionNotes || '').trim()).length;
    },

    resolveTitle() {
      return this.isRefurbishment ? 'Dictamen: reacondicionar la pieza' : 'Dictamen: baja definitiva';
    }
  },

  methods: {
    statusLabel(status) {
      return STATUS_LABELS[String(status || '').toUpperCase()] || String(status || '—');
    },

    categoryLabel(category) {
      return CATEGORY_LABELS[String(category || '').toUpperCase()] || String(category || '—');
    },

    statusChipClass(status) {
      const normalized = String(status || '').toUpperCase();

      if (normalized === 'REFURBISHED_RESTORED') {
        return 'vg-chip vg-chip-success';
      }
      if (normalized === 'SCRAPPED_IRREPARABLE') {
        return 'vg-chip vg-chip-danger';
      }
      if (normalized === 'IN_BENCH_TEST') {
        return 'vg-chip vg-chip-warning';
      }

      return 'vg-chip';
    },

    formatMoney(value) {
      const amount = Number(value);

      return new Intl.NumberFormat('es-ES', {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      }).format(Number.isFinite(amount) ? amount : 0);
    },

    formatPercent(value) {
      const rate = Number(value);

      return `${(Number.isFinite(rate) ? rate : 0).toFixed(2)} %`;
    },

    /** Antigüedad en horas; el servidor la publica y el cliente sólo la redondea. */
    ageHours(item) {
      const provided = Number(item?.age_hours);
      if (Number.isFinite(provided) && provided >= 0) {
        return provided;
      }

      const createdAt = item?.created_at ? new Date(item.created_at) : null;
      if (!createdAt || Number.isNaN(createdAt.getTime())) {
        return null;
      }

      return Math.max(0, (Date.now() - createdAt.getTime()) / 3600000);
    },

    formatAge(item) {
      const hours = this.ageHours(item);

      return hours === null ? '—' : `${hours.toFixed(1)} h`;
    },

    unitBadge(item) {
      return `Unidad ${item?.unit_index ?? '—'} de ${item?.total_units_in_line ?? '—'}`;
    },

    originLabel(item) {
      const type = String(item?.intervention_type || '').toUpperCase();
      if (type === 'PREVENTIVE') {
        return item?.preventive_order_code || 'Orden preventiva';
      }

      return item?.ticket_code || 'Avería';
    },

    originHref(item) {
      const type = String(item?.intervention_type || '').toUpperCase();
      if (type === 'PREVENTIVE') {
        return `#preventive-order-${item?.preventive_order_id ?? ''}`;
      }

      return `#incident-${item?.incident_id ?? ''}`;
    },

    isPending(item) {
      return String(item?.status || '').toUpperCase() === 'PENDING_DELIVERY';
    },

    isInBench(item) {
      return String(item?.status || '').toUpperCase() === 'IN_BENCH_TEST';
    },

    isTerminal(item) {
      const status = String(item?.status || '').toUpperCase();

      return status === 'REFURBISHED_RESTORED' || status === 'SCRAPPED_IRREPARABLE';
    },

    buildParams() {
      const params = {};
      if (this.filters.status !== 'ALL') {
        params.status = this.filters.status;
      }
      if (String(this.filters.technician_id).trim() !== '') {
        params.technician_id = this.filters.technician_id;
      }
      if (this.filters.category !== 'ALL') {
        params.category = this.filters.category;
      }
      if (String(this.filters.search).trim() !== '') {
        params.search = this.filters.search.trim();
      }

      return params;
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
        // El selector no es imprescindible para leer la bandeja.
        this.technicians = [];
      }
    },

    async loadTray() {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        const response = await api.coordinator.fetchCoordinatorWorkshopItems(this.buildParams());
        const payload = response?.data || response || {};

        this.items = Array.isArray(payload.items) ? payload.items : [];
        this.summary = payload.summary && typeof payload.summary === 'object' ? payload.summary : {};
        this.total = Number(payload.total || 0);
        this.page = Number(payload.page || 1);
        this.totalPages = Number(payload.total_pages || 1);

        this.$emit('summary-changed', this.activeUnits);
      } catch (error) {
        this.errorMessage = error.message || 'No se pudo cargar la bandeja de taller.';
        this.items = [];
      } finally {
        this.isLoading = false;
      }
    },

    setStatus(status) {
      if (this.filters.status === status) {
        return;
      }
      this.filters.status = status;
      this.loadTray();
    },

    applyFilters() {
      this.loadTray();
    },

    /** Debounce de 250 ms: teclear no dispara una consulta por pulsación. */
    onSearchInput() {
      if (this.searchTimer !== null) {
        clearTimeout(this.searchTimer);
      }
      this.searchTimer = setTimeout(() => {
        this.searchTimer = null;
        this.loadTray();
      }, 250);
    },

    async changePage(delta) {
      const target = this.page + delta;
      if (target < 1 || target > this.totalPages) {
        return;
      }
      this.page = target;
      await this.loadTray();
    },

    openReceiveModal(item) {
      this.receiveError = '';
      this.successMessage = '';
      this.receiveTarget = item;
      this.receptionNotes = '';
      this.showReceiveModal = true;
    },

    closeReceiveModal() {
      this.showReceiveModal = false;
      this.receiveTarget = null;
      this.receptionNotes = '';
      this.receiveError = '';
    },

    async submitReceive() {
      if (!this.canReceive || !this.receiveTarget) {
        this.receiveError = 'La nota de recepción no puede superar 500 caracteres.';
        return;
      }

      this.isReceiving = true;
      this.receiveError = '';

      try {
        const payload = {};
        const notes = String(this.receptionNotes || '').trim();
        if (notes !== '') {
          payload.reception_notes = notes;
        }

        const response = await api.coordinator.receiveCoordinatorWorkshopItem(
          this.receiveTarget.id,
          payload
        );
        const data = response?.data || response || {};

        this.successMessage = data.message || 'Recepción en taller registrada con éxito.';
        this.closeReceiveModal();
        await this.loadTray();
      } catch (error) {
        this.receiveError = error.message || 'No se pudo registrar la recepción.';
      } finally {
        this.isReceiving = false;
      }
    },

    async startBench(item) {
      if (!item || this.isLoading) {
        return;
      }

      this.errorMessage = '';
      this.successMessage = '';

      try {
        const response = await api.coordinator.startBenchCoordinatorWorkshopItem(item.id);
        const data = response?.data || response || {};

        this.successMessage = data.message || 'Inicio de comprobación en banco registrado.';
        await this.loadTray();
      } catch (error) {
        this.errorMessage = error.message || 'No se pudo iniciar la comprobación en banco.';
      }
    },

    /**
     * Abre el dictamen. El destino y el técnico se preseleccionan con criterio:
     * CENTRAL por defecto y, si la pieza admite furgoneta, el técnico extractor
     * (que es quien la desmontó y quien mejor sabe dónde devolverla).
     */
    openResolveModal(item, outcome = 'REFURBISHED_RESTORED') {
      this.resolveError = '';
      this.successMessage = '';
      this.resolveTarget = item;
      this.resolveForm = {
        ...emptyResolveForm(),
        outcome_status: outcome,
        restock_destination: 'CENTRAL_WAREHOUSE',
        restocked_to_technician_id: item?.extracted_by_technician_id || ''
      };
      this.showResolveModal = true;
    },

    closeResolveModal() {
      this.showResolveModal = false;
      this.resolveTarget = null;
      this.resolveForm = emptyResolveForm();
      this.resolveError = '';
    },

    async submitResolve() {
      if (!this.canResolve || !this.resolveTarget) {
        this.resolveError = 'Revisa el diagnóstico, el coste y el destino antes de firmar.';
        return;
      }

      this.isResolving = true;
      this.resolveError = '';

      try {
        const payload = {
          outcome_status: this.resolveForm.outcome_status,
          bench_diagnosis: String(this.resolveForm.bench_diagnosis || '').trim()
        };

        if (this.isRefurbishment) {
          payload.refurbishment_cost_eur = Number(this.resolveForm.refurbishment_cost_eur);
          payload.restock_destination = this.resolveForm.restock_destination;

          if (this.resolveForm.restock_destination === 'TECHNICIAN_VAN') {
            payload.restocked_to_technician_id = Number(this.resolveForm.restocked_to_technician_id);
          }
        }

        const response = await api.coordinator.resolveCoordinatorWorkshopItem(
          this.resolveTarget.id,
          payload
        );
        const data = response?.data || response || {};

        this.successMessage = data.message
          || 'Dictamen registrado con éxito.';
        this.closeResolveModal();
        await this.loadTray();
      } catch (error) {
        this.resolveError = error.message || 'No se pudo registrar el dictamen.';
      } finally {
        this.isResolving = false;
      }
    },

    async exportCsv() {
      if (this.isExporting) {
        return;
      }

      this.isExporting = true;
      this.errorMessage = '';

      try {
        await api.coordinator.exportCoordinatorWorkshopItemsCsv(this.buildParams());
        this.successMessage = 'Exportación CSV generada con los filtros aplicados.';
      } catch (error) {
        this.errorMessage = error.message || 'No se pudo exportar la bandeja de taller.';
      } finally {
        this.isExporting = false;
      }
    }
  },

  watch: {
    active(isActive) {
      if (isActive) {
        this.loadTray();
      }
    }
  },

  mounted() {
    this.loadTechnicians();
    if (this.active) {
      this.loadTray();
    }
  },

  beforeUnmount() {
    if (this.searchTimer !== null) {
      clearTimeout(this.searchTimer);
      this.searchTimer = null;
    }
  },

  template: `
    <div class="coordinator-workshop-tray-tab" data-testid="coord-workshop-tray-tab" style="padding-top: 8px;">
      <div
        v-if="errorMessage"
        role="alert"
        data-testid="coord-workshop-error"
        class="vg-alert vg-alert-error"
        style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; padding: 12px 16px;"
      >
        <span>⚠️ {{ errorMessage }}</span>
        <button type="button" class="vg-btn vg-btn-icon vg-btn-ghost vg-btn-ghost-danger" title="Cerrar el aviso" @click="errorMessage = ''">&times;</button>
      </div>

      <div
        v-if="successMessage"
        data-testid="coord-workshop-success"
        class="vg-alert"
        style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; padding: 12px 16px; background-color: var(--color-success-bg); border: 1px solid var(--color-success); color: var(--color-success-text);"
      >
        <span>✅ {{ successMessage }}</span>
        <button type="button" class="vg-btn vg-btn-icon vg-btn-ghost vg-btn-ghost-success" title="Cerrar el aviso" @click="successMessage = ''">&times;</button>
      </div>

      <!-- KPIs de cabecera (RF-05.1) -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; margin-bottom: 20px;">
        <div
          v-for="kpi in kpis"
          :key="kpi.key"
          :data-testid="'coord-workshop-kpi-' + kpi.key"
          style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 20px;"
        >
          <div style="font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; text-transform: uppercase; letter-spacing: 0.02em; color: var(--color-ink-muted); margin-bottom: 4px;">
            {{ kpi.label }}
          </div>
          <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); font-weight: 600; color: var(--color-ink); font-variant-numeric: tabular-nums;">
            {{ kpi.value }}
          </div>
          <div style="font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted); margin-top: 4px;">
            {{ kpi.hint }}
          </div>
        </div>
      </div>

      <!-- Filtros y exportación (RF-04.2, RF-05.2) -->
      <div style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 16px 20px; margin-bottom: 20px;">
        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px;">
          <div class="vg-action-group" role="group" aria-label="Filtros por estado de taller">
            <button
              v-for="filter in statusFilters"
              :key="filter.value"
              type="button"
              class="vg-btn vg-btn-sm"
              :class="filters.status === filter.value ? 'vg-btn-primary' : 'vg-btn-secondary'"
              :data-testid="'coord-workshop-status-' + filter.value"
              @click="setStatus(filter.value)"
            >
              {{ filter.label }}
            </button>
          </div>

          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            data-testid="coord-workshop-export"
            :disabled="isExporting"
            @click="exportCsv"
          >
            {{ isExporting ? 'Exportando…' : '⬇️ Exportar CSV' }}
          </button>
        </div>

        <div style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; padding-top: 12px; border-top: 1px solid var(--color-hairline);">
          <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
            Técnico extractor
            <select
              v-model="filters.technician_id"
              class="vg-input"
              data-testid="coord-workshop-filter-technician"
              style="min-width: 220px;"
              @change="applyFilters"
            >
              <option value="">Todos los técnicos</option>
              <option v-for="tech in technicians" :key="tech.id" :value="String(tech.id)">
                {{ tech.name }}<template v-if="tech.operatorCode"> · {{ tech.operatorCode }}</template>
              </option>
            </select>
          </label>

          <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
            Categoría
            <select
              v-model="filters.category"
              class="vg-input"
              data-testid="coord-workshop-filter-category"
              style="min-width: 190px;"
              @change="applyFilters"
            >
              <option v-for="category in categoryOptions" :key="category.value" :value="category.value">
                {{ category.label }}
              </option>
            </select>
          </label>

          <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary); flex: 1 1 260px;">
            Buscar
            <input
              v-model="filters.search"
              type="search"
              class="vg-input"
              placeholder="Pieza, máquina, sede o diagnóstico…"
              data-testid="coord-workshop-search"
              @input="onSearchInput"
            />
          </label>
        </div>
      </div>

      <div v-if="isLoading" data-testid="coord-workshop-loading" style="padding: 16px; color: var(--color-ink-muted); font-family: var(--font-body); font-size: var(--font-size-body-sm);">
        Cargando la bandeja de taller…
      </div>

      <div
        v-else-if="isEmpty"
        data-testid="coord-workshop-empty"
        style="padding: 20px; background-color: var(--color-surface-2); border: 1px dashed var(--color-hairline); border-radius: var(--radius-card); color: var(--color-ink-muted); font-family: var(--font-body); font-size: var(--font-size-body-sm);"
      >
        No hay expedientes que cumplan los filtros. Las piezas extraídas con destino TALLER aparecen aquí en cuanto se cierra la intervención.
      </div>

      <div v-else style="overflow-x: auto;">
        <table data-testid="coord-workshop-table" style="width: 100%; border-collapse: collapse; font-family: var(--font-body); font-size: var(--font-size-body-sm);">
          <thead>
            <tr style="text-align: left; color: var(--color-ink-secondary);">
              <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Unidad</th>
              <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Pieza</th>
              <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Origen</th>
              <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Máquina / Sede</th>
              <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline);">Técnico</th>
              <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline); text-align: right;">Antigüedad</th>
              <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline); text-align: right;">Desglose</th>
              <th scope="col" style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline); text-align: right;">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="item in items"
              :key="item.id"
              :data-testid="'coord-workshop-row-' + item.id"
            >
              <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-ink);">
                <div style="font-family: var(--font-display); font-weight: 600; font-variant-numeric: tabular-nums;">{{ unitBadge(item) }}</div>
                <span :class="statusChipClass(item.status)" style="margin-top: 4px;">{{ statusLabel(item.status) }}</span>
              </td>
              <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-ink);">
                <div style="font-weight: 600;">{{ item.part_name || 'Pieza sin nombre' }}</div>
                <div style="color: var(--color-ink-muted); font-size: var(--font-size-caption);">
                  <span v-if="item.part_code">{{ item.part_code }} · </span>{{ categoryLabel(item.part_category) }}
                </div>
                <span
                  v-if="item.is_out_of_catalog"
                  data-testid="coord-workshop-out-of-catalog"
                  class="vg-chip vg-chip-warning"
                  style="margin-top: 4px;"
                >
                  Fuera de catálogo
                </span>
              </td>
              <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft);">
                <a
                  :href="originHref(item)"
                  :data-testid="'coord-workshop-origin-' + item.id"
                  style="color: var(--color-primary); text-decoration: none; font-weight: 600;"
                >
                  {{ originLabel(item) }}
                </a>
                <div style="color: var(--color-ink-muted); font-size: var(--font-size-caption);">
                  {{ item.intervention_type === 'PREVENTIVE' ? 'Preventivo' : 'Avería' }}
                </div>
              </td>
              <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-ink);">
                <div style="font-weight: 600;">{{ item.machine_code || '—' }}</div>
                <div style="color: var(--color-ink-muted); font-size: var(--font-size-caption);">{{ item.location_name || '—' }}</div>
              </td>
              <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); color: var(--color-ink-secondary);">
                {{ item.technician_name || '—' }}
              </td>
              <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right; color: var(--color-ink); font-family: var(--font-display); font-variant-numeric: tabular-nums;">
                {{ formatAge(item) }}
              </td>
              <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right; color: var(--color-ink); font-variant-numeric: tabular-nums;">
                <div>{{ formatMoney(item.unit_cost_snapshot) }}</div>
                <div style="color: var(--color-ink-muted); font-size: var(--font-size-caption);">
                  Coste {{ formatMoney(item.refurbishment_cost_eur) }} · Ahorro {{ formatMoney(item.net_savings_eur) }}
                </div>
              </td>
              <td style="padding: 8px 10px; border-bottom: 1px solid var(--color-hairline-soft); text-align: right;">
                <div class="vg-row-actions">
                  <button
                    v-if="isPending(item)"
                    type="button"
                    class="vg-btn vg-btn-sm vg-btn-primary"
                    :data-testid="'coord-workshop-receive-' + item.id"
                    @click="openReceiveModal(item)"
                  >
                    Recibir
                  </button>
                  <button
                    v-if="!isPending(item) && !isInBench(item) && !isTerminal(item)"
                    type="button"
                    class="vg-btn vg-btn-sm vg-btn-secondary"
                    :data-testid="'coord-workshop-bench-' + item.id"
                    @click="startBench(item)"
                  >
                    Iniciar banco
                  </button>
                  <button
                    v-if="!isTerminal(item)"
                    type="button"
                    class="vg-btn vg-btn-sm vg-btn-primary-outline"
                    :data-testid="'coord-workshop-resolve-' + item.id"
                    @click="openResolveModal(item, isPending(item) ? 'SCRAPPED_IRREPARABLE' : 'REFURBISHED_RESTORED')"
                  >
                    Dictaminar
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="totalPages > 1" style="display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 12px; margin-top: 12px; font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-ink-secondary);">
        <button type="button" class="vg-btn vg-btn-sm vg-btn-secondary" :disabled="page <= 1" @click="changePage(-1)">Anterior</button>
        <span style="font-variant-numeric: tabular-nums;">Página {{ page }} de {{ totalPages }} · {{ total }} expedientes</span>
        <button type="button" class="vg-btn vg-btn-sm vg-btn-secondary" :disabled="page >= totalPages" @click="changePage(1)">Siguiente</button>
      </div>

      <!-- Modal de recepción física (RF-02.1) -->
      <ModalDialog
        v-model="showReceiveModal"
        title="Recepción física en taller"
        :subtitle="receiveTarget ? (receiveTarget.part_name + ' · ' + unitBadge(receiveTarget)) : ''"
        size="md"
        @close="closeReceiveModal"
      >
        <form @submit.prevent="submitReceive">
          <div v-if="receiveError" role="alert" class="vg-alert vg-alert-error" data-testid="coord-workshop-receive-error" style="margin-bottom: 12px;">
            ⚠️ {{ receiveError }}
          </div>

          <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
            Nota de recepción (opcional, máximo 500 caracteres)
            <textarea
              v-model="receptionNotes"
              class="vg-input"
              rows="3"
              data-testid="coord-workshop-receive-notes"
              style="resize: vertical;"
            ></textarea>
          </label>
          <div style="margin-top: 4px; text-align: right; font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted); font-variant-numeric: tabular-nums;">
            {{ receptionNotesLength }}/500
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px;">
            <button type="button" class="vg-btn vg-btn-secondary" @click="closeReceiveModal">Cancelar</button>
            <button type="submit" class="vg-btn vg-btn-primary" data-testid="coord-workshop-receive-submit" :disabled="!canReceive">
              {{ isReceiving ? 'Registrando…' : 'Registrar recepción' }}
            </button>
          </div>
        </form>
      </ModalDialog>

      <!-- Modal de dictamen (RF-03) -->
      <ModalDialog
        v-model="showResolveModal"
        :title="resolveTitle"
        :subtitle="resolveTarget ? (resolveTarget.part_name + ' · ' + unitBadge(resolveTarget)) : ''"
        size="md"
        @close="closeResolveModal"
      >
        <form @submit.prevent="submitResolve">
          <div v-if="resolveError" role="alert" class="vg-alert vg-alert-error" data-testid="coord-workshop-resolve-error" style="margin-bottom: 12px;">
            ⚠️ {{ resolveError }}
          </div>

          <div v-if="isOutOfCatalog" class="vg-alert vg-alert-warning" data-testid="coord-workshop-resolve-out-of-catalog" style="margin-bottom: 12px;">
            ⚠️ Pieza fuera de catálogo: no puede reintegrarse a la furgoneta de un técnico (D1/C3).
          </div>

          <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
            Diagnóstico de banco (20–1000 caracteres y al menos 3 palabras)
            <textarea
              v-model="resolveForm.bench_diagnosis"
              class="vg-input"
              rows="4"
              data-testid="coord-workshop-resolve-diagnosis"
              style="resize: vertical;"
            ></textarea>
          </label>
          <div style="margin-top: 4px; display: flex; justify-content: space-between; font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted); font-variant-numeric: tabular-nums;">
            <span>{{ diagnosisWords }} palabras</span>
            <span>{{ diagnosisLength }}/1000</span>
          </div>

          <div v-if="isRefurbishment" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-top: 12px;">
            <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
              Coste de reparación (EUR)
              <input
                v-model.number="resolveForm.refurbishment_cost_eur"
                type="number"
                min="0"
                max="9999.99"
                step="0.01"
                class="vg-input"
                data-testid="coord-workshop-resolve-cost"
              />
            </label>

            <label style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);">
              Destino de reintegro
              <select
                v-model="resolveForm.restock_destination"
                class="vg-input"
                data-testid="coord-workshop-resolve-destination"
              >
                <option value="CENTRAL_WAREHOUSE">Almacén central</option>
                <option value="TECHNICIAN_VAN" :disabled="isOutOfCatalog">Furgoneta de técnico</option>
              </select>
            </label>

            <label
              v-if="resolveForm.restock_destination === 'TECHNICIAN_VAN'"
              style="display: flex; flex-direction: column; gap: 4px; font-family: var(--font-body); font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-secondary);"
            >
              Técnico destinatario
              <select
                v-model="resolveForm.restocked_to_technician_id"
                class="vg-input"
                data-testid="coord-workshop-resolve-technician"
              >
                <option value="">Selecciona un técnico activo</option>
                <option v-for="tech in technicians" :key="tech.id" :value="String(tech.id)">
                  {{ tech.name }}
                </option>
              </select>
            </label>
          </div>

          <div
            v-if="hasZeroNetSavings"
            class="vg-alert vg-alert-warning"
            data-testid="coord-workshop-zero-savings"
            style="margin-top: 12px;"
          >
            ⚠️ Reparación sin ahorro neto: el coste iguala o supera el valor de reposición de la pieza. El ahorro se registrará en 0,00 € (C2).
          </div>

          <div v-if="!isDiagnosisValid" style="margin-top: 12px; font-family: var(--font-body); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
            El diagnóstico debe tener entre 20 y 1000 caracteres y al menos 3 palabras reales (Art. V.1).
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px;">
            <button type="button" class="vg-btn vg-btn-secondary" @click="closeResolveModal">Cancelar</button>
            <button type="submit" class="vg-btn vg-btn-primary" data-testid="coord-workshop-resolve-submit" :disabled="!canResolve">
              {{ isResolving ? 'Firmando…' : 'Firmar dictamen' }}
            </button>
          </div>
        </form>
      </ModalDialog>
    </div>
  `
};
