/**
 * VendGuard - CoordinatorWorkHoursTab Component (CoordinatorWorkHoursTab.js)
 *
 * Sub-pestaña «⏱️ Registro de Jornada y Horas» dentro de Métricas y Auditoría
 * (Módulo 22 · RF-02, RF-03, RF-04, RF-05 y RF-06, y las Decisiones D2, D3 y D5).
 *
 * Superficie de solo lectura para las cifras y de escritura auditada para las dos
 * únicas intervenciones administrativas que admite el módulo: rectificar un turno
 * cerrado y forzar el cierre de uno abierto (con hora efectiva opcional). Las cifras
 * no se recalculan aquí: `GET /api/coordinator/metrics/work-hours` es la única fuente
 * y el CSV que se descarga sale del mismo servicio, de modo que pantalla y exportación
 * no puedan discrepar (RNF-01).
 *
 * Dogma Vanilla: Vue 3 Options API vía ES Module nativo, sin bundlers ni dependencias.
 * Sistema de Diseño: solo tokens de `design-tokens.css`, cero colores literales, DM Sans
 * en cifras KPI y titulares, Inter en cuerpo y controles, 4px interactivos y 8px tarjetas.
 * Dualismo Lingüístico: identificadores en inglés, etiquetas y validaciones en castellano.
 */

import { api } from '../api.js';
import { ModalDialog } from './ModalDialog.js';

/** Motivos de rechazo del backend traducidos a una frase accionable (contratos §5.3 y §5.5). */
const SHIFT_FAILURE_MESSAGES = Object.freeze({
  INVALID_ADJUSTMENT_REASON: 'El motivo debe tener al menos 20 caracteres reales (Art. V.1).',
  MISSING_FORCE_CLOSE_REASON: 'El motivo no alcanza la longitud mínima exigida para este cierre.',
  SHIFT_STILL_OPEN: 'El turno sigue abierto: ciérralo antes de poder rectificarlo.',
  SHIFT_OVERLAP_CONFLICT: 'El nuevo intervalo se solapa con otro turno del mismo técnico.',
  SHIFT_FUTURE_TIMESTAMP: 'Ninguna marca horaria puede situarse en el futuro.',
  SHIFT_INVALID_TIME_ORDER: 'La salida debe ser estrictamente posterior a la entrada.',
  SHIFT_EXCEEDS_MAX_DURATION: 'La duración bruta no puede superar las 24 horas.',
  INVALID_MEAL_BREAK_DURATION: 'La pausa debe ser mayor o igual a cero y nunca superar la duración bruta.',
  EFFECTIVE_CLOSE_BEFORE_LAST_ACTIVITY:
    'La hora efectiva no puede ser anterior a una pausa ya registrada ni dejar la jornada por debajo de la pausa acumulada.',
  INVALID_SHIFT_TIMESTAMPS: 'El formato de las marcas horarias no es válido.',
  SHIFT_NOT_FOUND: 'La sesión de turno ya no existe.',
  INVALID_SHIFT_IDENTIFIER: 'El identificador de la sesión no es válido.',
  TECHNICIAN_NOT_FOUND: 'El técnico ya no existe en la plantilla.',
  ACTIVE_INCIDENT_IN_PROGRESS:
    'El técnico mantiene una avería en curso: resuélvela o traspásala antes de cerrar su jornada.',
  INVALID_FILTER_PARAMS: 'El periodo solicitado no es válido.'
});

const MIN_ADJUSTMENT_REASON = 20;
const MIN_FORCE_CLOSE_REASON = 10;

export const CoordinatorWorkHoursTab = {
  name: 'CoordinatorWorkHoursTab',
  components: { ModalDialog },
  props: {
    /** Periodo global del cuadro de mandos (D5, RF-05.1). */
    period: {
      type: String,
      default: 'last_30_days'
    },
    /** Inicio del rango personalizado del selector global. */
    fromDate: {
      type: String,
      default: ''
    },
    /** Fin del rango personalizado del selector global. */
    toDate: {
      type: String,
      default: ''
    }
  },
  data() {
    return {
      report: {
        filter: {},
        summary: {},
        orphan_open_shifts: [],
        by_technician: [],
        sessions: []
      },
      isLoading: false,
      errorMessage: '',
      technicianId: '',
      successMessage: '',
      adjustShiftTarget: null,
      adjustForm: {
        started_at: '',
        closed_at: '',
        meal_break_minutes: 0,
        reason: ''
      },
      adjustError: '',
      adjustSubmitting: false,
      forceCloseTarget: null,
      forceForm: {
        effective_closed_at: '',
        reason: ''
      },
      forceError: '',
      forceSubmitting: false,
      logsTarget: null,
      logs: [],
      logsLoading: false,
      logsError: ''
    };
  },
  computed: {
    filter() {
      return this.report?.filter || {};
    },
    summary() {
      return this.report?.summary || {};
    },
    sessions() {
      return Array.isArray(this.report?.sessions) ? this.report.sessions : [];
    },
    byTechnician() {
      return Array.isArray(this.report?.by_technician) ? this.report.by_technician : [];
    },
    orphanOpenShifts() {
      return Array.isArray(this.report?.orphan_open_shifts) ? this.report.orphan_open_shifts : [];
    },
    /** Sin sesiones ni plantilla no hay nada que enseñar (C5). */
    isEmpty() {
      return this.sessions.length === 0 && this.byTechnician.length === 0;
    },
    hasOutOfShiftInterventions() {
      return this.byTechnician.some((row) => row.has_out_of_shift_interventions === true);
    },
    hasAnomalousSessions() {
      return this.byTechnician.some((row) => Number(row.anomalous_sessions_count) > 0);
    },
    periodRangeLabel() {
      if (!this.filter.from_date || !this.filter.to_date) {
        return 'Sin periodo resuelto por el servidor.';
      }
      return `Del ${this.formatDate(this.filter.from_date)} al ${this.formatDate(this.filter.to_date)} · ${
        this.filter.timezone || 'Europe/Madrid'
      }`;
    },
    /** Motivo exigido por el contrato §5.5: 20 caracteres reales si hay hora efectiva. */
    forceReasonMinimum() {
      return this.forceForm.effective_closed_at ? MIN_ADJUSTMENT_REASON : MIN_FORCE_CLOSE_REASON;
    },
    forceReasonLength() {
      return String(this.forceForm.reason || '').trim().length;
    },
    adjustReasonLength() {
      return String(this.adjustForm.reason || '').trim().length;
    },
    /** Duración bruta resultante y tiempo descontado con la hora efectiva indicada. */
    forceClosePreview() {
      if (!this.forceForm.effective_closed_at || !this.forceCloseTarget?.started_at) {
        return '';
      }
      const start = new Date(this.forceCloseTarget.started_at);
      const effective = new Date(this.forceForm.effective_closed_at);
      if (Number.isNaN(start.getTime()) || Number.isNaN(effective.getTime())) {
        return '';
      }
      const grossSeconds = Math.floor((effective.getTime() - start.getTime()) / 1000);
      if (grossSeconds <= 0) {
        return 'La hora efectiva debe ser posterior a la entrada del turno.';
      }
      const discountedSeconds = Math.max(0, Math.floor((Date.now() - effective.getTime()) / 1000));
      return `Duración bruta resultante: ${this.formatDuration(grossSeconds)} · Minutos descontados del cómputo: ${Math.round(
        discountedSeconds / 60
      )}`;
    }
  },
  watch: {
    period() {
      this.loadReport();
    },
    fromDate() {
      this.loadReport();
    },
    toDate() {
      this.loadReport();
    }
  },
  mounted() {
    this.loadReport();
  },
  methods: {
    /**
     * Parámetros del contrato §5.1. El rango personalizado incompleto no se envía:
     * el backend resolvería otro periodo y la pantalla mostraría datos ajenos al filtro.
     * @returns {Object}
     */
    buildParams() {
      const params = {};
      if (this.period === 'custom') {
        params.period = 'custom';
        if (this.fromDate) params.from = this.fromDate;
        if (this.toDate) params.to = this.toDate;
      } else if (this.period) {
        params.period = this.period;
      }
      if (this.technicianId !== '' && this.technicianId !== null && this.technicianId !== undefined) {
        params.technician_id = Number(this.technicianId);
      }
      return params;
    },

    async loadReport() {
      if (this.period === 'custom' && (!this.fromDate || !this.toDate)) {
        this.isLoading = false;
        return;
      }

      this.isLoading = true;
      this.errorMessage = '';

      try {
        const response = await api.metrics.getWorkHoursReport(this.buildParams());
        const data = response && response.filter ? response : response?.data ?? {};
        this.report = {
          filter: data.filter || {},
          summary: data.summary || {},
          orphan_open_shifts: Array.isArray(data.orphan_open_shifts) ? data.orphan_open_shifts : [],
          by_technician: Array.isArray(data.by_technician) ? data.by_technician : [],
          sessions: Array.isArray(data.sessions) ? data.sessions : []
        };
      } catch (err) {
        this.errorMessage = this.describeShiftFailure(err);
      } finally {
        this.isLoading = false;
      }
    },

    /** @param {string} value */
    pad2(value) {
      return String(value).padStart(2, '0');
    },

    /** Marca completa en la zona horaria operativa del navegador (Europe/Madrid en producción). */
    formatDateTime(value) {
      if (!value) return '—';
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return '—';
      return `${this.pad2(date.getDate())}/${this.pad2(date.getMonth() + 1)}/${date.getFullYear()} ${this.pad2(
        date.getHours()
      )}:${this.pad2(date.getMinutes())}`;
    },

    formatTime(value) {
      if (!value) return '—';
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return '—';
      return `${this.pad2(date.getHours())}:${this.pad2(date.getMinutes())}`;
    },

    /** `shift_date` ya llega como día natural de imputación (C1): no se reinterpreta. */
    formatDate(value) {
      if (!value) return '—';
      const plain = String(value).slice(0, 10).split('-');
      if (plain.length === 3) {
        return `${plain[2]}/${plain[1]}/${plain[0]}`;
      }
      return this.formatDateTime(value).split(' ')[0];
    },

    /** `HHh MMm`, el mismo formato legible que publica el backend (RNF-01). */
    formatDuration(seconds) {
      const total = Math.max(0, Math.round(Number(seconds) || 0));
      const hours = Math.floor(total / 3600);
      const minutes = Math.floor((total % 3600) / 60);
      return `${this.pad2(hours)}h ${this.pad2(minutes)}m`;
    },

    /** Valor ISO -> `YYYY-MM-DDTHH:mm` para `datetime-local`, en hora local del operador. */
    toLocalInput(value) {
      if (!value) return '';
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return '';
      return `${date.getFullYear()}-${this.pad2(date.getMonth() + 1)}-${this.pad2(date.getDate())}T${this.pad2(
        date.getHours()
      )}:${this.pad2(date.getMinutes())}`;
    },

    /** Un turno ajustado conserva las marcas del primer fichaje del trabajador (Art. III.3). */
    hasOriginalMarks(session) {
      if (!session) return false;
      return (
        session.is_adjusted === true ||
        (session.original_meal_break_seconds !== null && session.original_meal_break_seconds !== undefined) ||
        Boolean(session.original_closed_at)
      );
    },

    /** @returns {string} Motivo legible del rechazo, sin exponer el código crudo al coordinador. */
    describeShiftFailure(error) {
      const code = error?.code || error?.error?.code || '';
      if (SHIFT_FAILURE_MESSAGES[code]) {
        return SHIFT_FAILURE_MESSAGES[code];
      }
      return error?.message || 'No se pudo completar la operación sobre el turno.';
    },

    async exportWorkHoursCsv() {
      this.errorMessage = '';
      try {
        const url = api.metrics.getWorkHoursCsvUrl(this.buildParams());
        await api.downloadFile(url, 'vendguard_registro_jornada.csv');
      } catch (err) {
        this.errorMessage = err?.message || 'No se pudo descargar el CSV del registro de jornada.';
      }
    },

    openAdjustShift(session) {
      if (!session || session.is_open) {
        return;
      }
      this.adjustShiftTarget = session;
      this.adjustError = '';
      this.adjustForm = {
        started_at: this.toLocalInput(session.started_at),
        closed_at: this.toLocalInput(session.closed_at),
        meal_break_minutes: Math.round((Number(session.meal_break_seconds) || 0) / 60),
        reason: ''
      };
    },

    closeAdjustShift() {
      this.adjustShiftTarget = null;
      this.adjustError = '';
      this.adjustSubmitting = false;
    },

    /** Validación cuádruple del cliente (C4); el backend vuelve a imponerla (RF-03.3). */
    validateAdjustForm() {
      const reason = String(this.adjustForm.reason || '').trim();
      if (reason.length < MIN_ADJUSTMENT_REASON) {
        return `El motivo debe tener al menos ${MIN_ADJUSTMENT_REASON} caracteres reales (Art. V.1).`;
      }
      if (!this.adjustForm.started_at || !this.adjustForm.closed_at) {
        return 'Indica la entrada y la salida vigentes del turno.';
      }

      const startedAt = new Date(this.adjustForm.started_at);
      const closedAt = new Date(this.adjustForm.closed_at);
      if (Number.isNaN(startedAt.getTime()) || Number.isNaN(closedAt.getTime())) {
        return 'El formato de las marcas horarias no es válido.';
      }

      const grossSeconds = Math.floor((closedAt.getTime() - startedAt.getTime()) / 1000);
      if (grossSeconds <= 0) {
        return 'La salida debe ser estrictamente posterior a la entrada.';
      }
      if (closedAt.getTime() > Date.now()) {
        return 'Ninguna marca horaria puede situarse en el futuro.';
      }
      if (grossSeconds > 86400) {
        return 'La duración bruta no puede superar las 24 horas.';
      }

      const mealMinutes = Number(this.adjustForm.meal_break_minutes);
      if (!Number.isFinite(mealMinutes) || mealMinutes < 0) {
        return 'La pausa de comida debe ser mayor o igual a cero.';
      }
      if (Math.round(mealMinutes * 60) > grossSeconds) {
        return 'La pausa de comida no puede superar la duración bruta del turno.';
      }

      return '';
    },

    async submitAdjustShift() {
      this.adjustError = '';
      const invalid = this.validateAdjustForm();
      if (invalid) {
        this.adjustError = invalid;
        return;
      }
      if (this.adjustSubmitting || !this.adjustShiftTarget) {
        return;
      }

      const shiftId = this.adjustShiftTarget.id;
      this.adjustSubmitting = true;

      try {
        await api.coordinator.adjustShift(shiftId, {
          started_at: new Date(this.adjustForm.started_at).toISOString(),
          closed_at: new Date(this.adjustForm.closed_at).toISOString(),
          meal_break_seconds: Math.round(Number(this.adjustForm.meal_break_minutes) * 60),
          reason: String(this.adjustForm.reason).trim()
        });
        this.successMessage = `Turno #${shiftId} rectificado: las marcas originales del trabajador se conservan y el ajuste queda auditado.`;
        this.adjustShiftTarget = null;
        await this.loadReport();
      } catch (err) {
        this.adjustError = this.describeShiftFailure(err);
      } finally {
        this.adjustSubmitting = false;
      }
    },

    openForceCloseShift(session) {
      if (!session) return;
      this.forceCloseTarget = session;
      this.forceError = '';
      this.forceForm = {
        effective_closed_at: '',
        reason: ''
      };
    },

    closeForceCloseShift() {
      this.forceCloseTarget = null;
      this.forceError = '';
      this.forceSubmitting = false;
    },

    async submitForceCloseShift() {
      this.forceError = '';
      if (!this.forceCloseTarget) return;

      const reason = String(this.forceForm.reason || '').trim();
      if (reason.length < this.forceReasonMinimum) {
        this.forceError = `El motivo debe tener al menos ${this.forceReasonMinimum} caracteres reales.`;
        return;
      }

      let effectiveIso = null;
      if (this.forceForm.effective_closed_at) {
        const effective = new Date(this.forceForm.effective_closed_at);
        if (Number.isNaN(effective.getTime())) {
          this.forceError = 'El formato de la hora efectiva no es válido.';
          return;
        }
        if (effective.getTime() > Date.now()) {
          this.forceError = 'La hora efectiva no puede situarse en el futuro.';
          return;
        }
        const startedAt = this.forceCloseTarget.started_at ? new Date(this.forceCloseTarget.started_at) : null;
        if (startedAt && effective.getTime() <= startedAt.getTime()) {
          this.forceError = 'La hora efectiva debe ser estrictamente posterior a la entrada del turno.';
          return;
        }
        effectiveIso = effective.toISOString();
      }

      if (this.forceSubmitting) return;
      this.forceSubmitting = true;

      const technicianId = this.forceCloseTarget.technician_id;
      const shiftId = this.forceCloseTarget.id;

      try {
        await api.technicianShift.forceClose(technicianId, reason, effectiveIso);
        this.successMessage = effectiveIso
          ? `Turno #${shiftId} cerrado con la hora efectiva indicada; el protocolo sanitario del Art. II se ha fechado con el instante real.`
          : `Turno #${shiftId} cerrado en tiempo real.`;
        this.forceCloseTarget = null;
        await this.loadReport();
      } catch (err) {
        this.forceError = this.describeShiftFailure(err);
      } finally {
        this.forceSubmitting = false;
      }
    },

    async openLogs(session) {
      if (!session) return;
      this.logsTarget = session;
      this.logs = [];
      this.logsError = '';
      this.logsLoading = true;

      try {
        const response = await api.coordinator.getShiftLogs(session.id);
        const list = Array.isArray(response?.logs)
          ? response.logs
          : Array.isArray(response?.data?.logs)
            ? response.data.logs
            : Array.isArray(response)
              ? response
              : [];
        this.logs = list;
      } catch (err) {
        this.logsError = this.describeShiftFailure(err);
      } finally {
        this.logsLoading = false;
      }
    },

    closeLogs() {
      this.logsTarget = null;
      this.logs = [];
      this.logsError = '';
    },

    /** Autor del evento: la bitácora publica el identificador del usuario ejecutor. */
    logAuthor(log) {
      const actorId = log?.actor_user_id;
      return actorId ? `Usuario #${actorId}` : 'Sistema';
    },

    /** Instantánea de estado legible para la bitácora (claves del contrato §5.4). */
    describeLogState(state) {
      if (!state || typeof state !== 'object') return '—';
      const parts = [];
      if (state.status) parts.push(`Estado: ${state.status}`);
      if (state.started_at) parts.push(`Entrada: ${this.formatDateTime(state.started_at)}`);
      if (state.closed_at) parts.push(`Salida: ${this.formatDateTime(state.closed_at)}`);
      if (state.effective_closed_at) {
        parts.push(`Hora efectiva: ${this.formatDateTime(state.effective_closed_at)}`);
      }
      if (state.meal_break_seconds !== undefined && state.meal_break_seconds !== null) {
        parts.push(`Comida: ${this.formatDuration(state.meal_break_seconds)}`);
      }
      if (state.is_force_closed === true) parts.push('Cierre forzado');
      return parts.length > 0 ? parts.join(' · ') : '—';
    }
  },
  template: `
    <div class="vg-work-hours-tab" data-testid="work-hours-tab" style="display: flex; flex-direction: column; gap: 16px; font-family: var(--font-body);">
      <!-- Cabecera y filtro por técnico -->
      <div class="vg-card" style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 14px;">
        <div>
          <h3 style="font-family: var(--font-display); font-size: var(--font-size-heading-md); font-weight: 500; color: var(--color-slate); margin: 0 0 4px;">
            ⏱️ Registro de Jornada y Horas
          </h3>
          <p style="font-size: var(--font-size-caption); color: var(--color-ink-muted); margin: 0;">
            {{ periodRangeLabel }}
          </p>
        </div>

        <div style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px;">
          <label style="display: flex; flex-direction: column; gap: 4px; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
            Técnico
            <select
              v-model="technicianId"
              data-testid="work-hours-technician-filter"
              class="vg-select"
              style="min-width: 200px; font-family: var(--font-body);"
              @change="loadReport"
            >
              <option value="">Todos los técnicos</option>
              <option v-for="row in byTechnician" :key="row.technician_id" :value="row.technician_id">
                {{ row.technician_name || 'Técnico #' + row.technician_id }}{{ row.operator_code ? ' · ' + row.operator_code : '' }}
              </option>
            </select>
          </label>

          <button
            type="button"
            class="vg-btn vg-btn-secondary vg-btn-sm"
            data-testid="btn-download-work-hours-csv"
            @click="exportWorkHoursCsv"
          >
            📥 Exportar Jornada CSV
          </button>
        </div>
      </div>

      <div
        v-if="successMessage"
        role="status"
        style="padding: 10px 14px; background-color: var(--color-success-bg); color: var(--color-success-text); border: 1px solid var(--color-success); border-radius: var(--radius-card); font-size: var(--font-size-body-sm);"
      >
        {{ successMessage }}
      </div>

      <!-- Aviso de turnos abiertos huérfanos de fechas anteriores (RF-04.2, QA 4) -->
      <div
        v-if="orphanOpenShifts.length > 0"
        data-testid="work-hours-orphan-banner"
        role="alert"
        style="background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); border-radius: var(--radius-card); padding: 14px 16px;"
      >
        <strong style="display: block; font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-warning-text); margin-bottom: 6px;">
          ⚠️ Turnos abiertos pendientes de cierre de fechas anteriores
        </strong>
        <ul style="margin: 0; padding-left: 18px; display: flex; flex-direction: column; gap: 6px;">
          <li
            v-for="orphan in orphanOpenShifts"
            :key="'orphan-' + orphan.id"
            style="font-size: var(--font-size-caption); color: var(--color-warning-text);"
          >
            <strong>{{ orphan.technician_name || 'Técnico #' + orphan.technician_id }}</strong>
            · {{ formatDate(orphan.shift_date) }}
            · entrada {{ formatTime(orphan.started_at) }}
            · {{ orphan.gross_formatted }} brutas
            <div style="margin-top: 6px;">
              <button
                type="button"
                class="vg-btn vg-btn-warning-soft vg-btn-sm"
                @click="openForceCloseShift(orphan)"
              >
                🛑 Forzar Cierre
              </button>
            </div>
          </li>
        </ul>
      </div>

      <div
        v-if="errorMessage"
        role="alert"
        style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; padding: 12px 16px; background-color: var(--color-error-bg); color: var(--color-error-text); border-radius: var(--radius-card); font-size: var(--font-size-body-sm);"
      >
        <span>{{ errorMessage }}</span>
        <button type="button" class="vg-btn vg-btn-secondary vg-btn-sm" @click="loadReport">Reintentar</button>
      </div>

      <p v-if="isLoading" data-testid="work-hours-loading" style="font-size: var(--font-size-body-sm); color: var(--color-ink-muted); margin: 0;">
        Cargando el registro de jornada del periodo…
      </p>

      <p v-else-if="isEmpty" data-testid="work-hours-empty" style="font-size: var(--font-size-body-sm); color: var(--color-ink-muted); margin: 0;">
        Sin sesiones de jornada registradas en el periodo seleccionado.
      </p>

      <template v-else>
        <!-- Tarjetas de resumen del periodo (D3) -->
        <div
          data-testid="work-hours-summary-cards"
          style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;"
        >
          <div class="vg-card" style="padding: 14px 16px;">
            <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">Sesiones</div>
            <div style="font-family: var(--font-display); font-weight: 500; font-size: var(--font-size-display-sm); color: var(--color-slate);">
              {{ summary.total_sessions || 0 }}
            </div>
            <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">
              {{ summary.open_sessions_count || 0 }} en curso
            </div>
          </div>

          <div class="vg-card" style="padding: 14px 16px;">
            <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">Horas brutas</div>
            <div style="font-family: var(--font-display); font-weight: 500; font-size: var(--font-size-display-sm); color: var(--color-slate);">
              {{ summary.gross_formatted || '00h 00m' }}
            </div>
          </div>

          <div class="vg-card" style="padding: 14px 16px;">
            <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">Pausa de comida</div>
            <div style="font-family: var(--font-display); font-weight: 500; font-size: var(--font-size-display-sm); color: var(--color-slate);">
              {{ summary.meal_break_formatted || '00h 00m' }}
            </div>
          </div>

          <div class="vg-card" style="padding: 14px 16px;">
            <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">Horas efectivas netas</div>
            <div style="font-family: var(--font-display); font-weight: 500; font-size: var(--font-size-display-sm); color: var(--color-primary);">
              {{ summary.effective_formatted || '00h 00m' }}
            </div>
          </div>

          <div class="vg-card" style="padding: 14px 16px;">
            <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">Intervención in situ</div>
            <div style="font-family: var(--font-display); font-weight: 500; font-size: var(--font-size-display-sm); color: var(--color-slate);">
              {{ summary.intervention_formatted || '00h 00m' }}
            </div>
          </div>

          <div class="vg-card" style="padding: 14px 16px;">
            <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">Tiempo en ruta</div>
            <div style="font-family: var(--font-display); font-weight: 500; font-size: var(--font-size-display-sm); color: var(--color-slate);">
              {{ summary.route_formatted || '00h 00m' }}
            </div>
            <div v-if="hasOutOfShiftInterventions" style="font-size: var(--font-size-caption); color: var(--color-warning-text);">
              ⚠️ Intervención registrada fuera de turno
            </div>
          </div>

          <div class="vg-card" style="padding: 14px 16px;">
            <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">Intervenciones resueltas</div>
            <div style="font-family: var(--font-display); font-weight: 500; font-size: var(--font-size-display-sm); color: var(--color-slate);">
              {{ summary.resolved_interventions_count || 0 }}
            </div>
          </div>

          <div class="vg-card" style="padding: 14px 16px;">
            <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">Turnos anómalos (&gt; 12h)</div>
            <div
              style="font-family: var(--font-display); font-weight: 500; font-size: var(--font-size-display-sm);"
              :style="{ color: hasAnomalousSessions ? 'var(--color-warning-text)' : 'var(--color-slate)' }"
            >
              {{ summary.anomalous_sessions_count || 0 }}
            </div>
            <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">
              {{ summary.adjusted_sessions_count || 0 }} ajustados
            </div>
          </div>
        </div>

        <!-- Tabla resumen por técnico (C5, D3) -->
        <div class="vg-card" style="padding: 0; overflow: hidden;">
          <h4 style="font-family: var(--font-display); font-size: var(--font-size-body-md); font-weight: 500; color: var(--color-slate); margin: 0; padding: 14px 16px; border-bottom: 1px solid var(--color-hairline);">
            Resumen por técnico
          </h4>
          <div style="overflow-x: auto;">
            <table data-testid="work-hours-summary-table" style="width: 100%; border-collapse: collapse; font-size: var(--font-size-body-sm);">
              <thead>
                <tr style="background-color: var(--color-canvas);">
                  <th style="text-align: left; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Técnico</th>
                  <th style="text-align: left; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Plantilla</th>
                  <th style="text-align: right; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Sesiones</th>
                  <th style="text-align: right; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Brutas</th>
                  <th style="text-align: right; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Comida</th>
                  <th style="text-align: right; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Efectivas</th>
                  <th style="text-align: right; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Intervención</th>
                  <th style="text-align: right; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Ruta</th>
                  <th style="text-align: right; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Intervenciones</th>
                  <th style="text-align: left; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Insignias</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in byTechnician" :key="'tech-' + row.technician_id" style="border-bottom: 1px solid var(--color-hairline);">
                  <td style="padding: 10px 12px; color: var(--color-slate);">
                    <strong>{{ row.technician_name || 'Técnico #' + row.technician_id }}</strong>
                    <span v-if="row.operator_code" style="color: var(--color-ink-muted);"> · {{ row.operator_code }}</span>
                  </td>
                  <td style="padding: 10px 12px; color: var(--color-ink-muted);">{{ row.is_active === false ? 'Inactivo' : 'Activo' }}</td>
                  <td style="padding: 10px 12px; text-align: right; font-family: var(--font-display); color: var(--color-slate);">{{ row.sessions_count || 0 }}</td>
                  <td style="padding: 10px 12px; text-align: right; font-family: var(--font-display); color: var(--color-slate);">{{ row.gross_formatted || '00h 00m' }}</td>
                  <td style="padding: 10px 12px; text-align: right; font-family: var(--font-display); color: var(--color-slate);">{{ row.meal_break_formatted || '00h 00m' }}</td>
                  <td style="padding: 10px 12px; text-align: right; font-family: var(--font-display); color: var(--color-primary);">{{ row.effective_formatted || '00h 00m' }}</td>
                  <td style="padding: 10px 12px; text-align: right; font-family: var(--font-display); color: var(--color-slate);">{{ row.intervention_formatted || '00h 00m' }}</td>
                  <td style="padding: 10px 12px; text-align: right; font-family: var(--font-display); color: var(--color-slate);">{{ row.route_formatted || '00h 00m' }}</td>
                  <td style="padding: 10px 12px; text-align: right; font-family: var(--font-display); color: var(--color-slate);">{{ row.resolved_interventions_count || 0 }}</td>
                  <td style="padding: 10px 12px;">
                    <span v-if="row.is_active === false" style="display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; background-color: var(--color-neutral-bg); color: var(--color-slate); border: 1px solid var(--color-neutral-border); border-radius: var(--radius-interactive); font-size: var(--font-size-caption);">Inactivo</span>
                    <span v-if="Number(row.anomalous_sessions_count) > 0" style="display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; background-color: var(--color-warning-bg); color: var(--color-warning-text); border: 1px solid var(--color-warning-border); border-radius: var(--radius-interactive); font-size: var(--font-size-caption);">⚠️ &gt; 12h</span>
                    <span v-if="row.has_out_of_shift_interventions" style="display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; background-color: var(--color-warning-bg); color: var(--color-warning-text); border: 1px solid var(--color-warning-border); border-radius: var(--radius-interactive); font-size: var(--font-size-caption);">⚠️ Fuera de turno</span>
                    <span v-if="Number(row.adjusted_sessions_count) > 0" style="display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; background-color: var(--color-info-bg); color: var(--color-info-text); border: 1px solid var(--color-info-border); border-radius: var(--radius-interactive); font-size: var(--font-size-caption);">Ajustado</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Desglose de sesiones con acciones de gestión (RF-05.3) -->
        <div class="vg-card" style="padding: 0; overflow: hidden;">
          <h4 style="font-family: var(--font-display); font-size: var(--font-size-body-md); font-weight: 500; color: var(--color-slate); margin: 0; padding: 14px 16px; border-bottom: 1px solid var(--color-hairline);">
            Sesiones de jornada
          </h4>

          <p v-if="sessions.length === 0" data-testid="work-hours-no-sessions" style="padding: 14px 16px; margin: 0; font-size: var(--font-size-body-sm); color: var(--color-ink-muted);">
            La plantilla no registra sesiones en este periodo.
          </p>

          <div v-else style="overflow-x: auto;">
            <table data-testid="work-hours-sessions-table" style="width: 100%; border-collapse: collapse; font-size: var(--font-size-body-sm);">
              <thead>
                <tr style="background-color: var(--color-canvas);">
                  <th style="text-align: left; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Fecha</th>
                  <th style="text-align: left; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Técnico</th>
                  <th style="text-align: left; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Entrada</th>
                  <th style="text-align: left; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Salida</th>
                  <th style="text-align: right; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Bruto</th>
                  <th style="text-align: right; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Comida</th>
                  <th style="text-align: right; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Efectivas</th>
                  <th style="text-align: left; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Estado</th>
                  <th style="text-align: left; padding: 10px 12px; font-size: var(--font-size-caption); font-weight: 600; color: var(--color-ink-muted); border-bottom: 1px solid var(--color-hairline);">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="session in sessions" :key="'session-' + session.id">
                  <tr style="border-bottom: 1px solid var(--color-hairline);">
                    <td style="padding: 10px 12px; color: var(--color-slate); white-space: nowrap;">{{ formatDate(session.shift_date) }}</td>
                    <td style="padding: 10px 12px; color: var(--color-slate);">{{ session.technician_name || 'Técnico #' + session.technician_id }}</td>
                    <td style="padding: 10px 12px; font-family: var(--font-display); color: var(--color-slate); white-space: nowrap;">{{ formatTime(session.started_at) }}</td>
                    <td style="padding: 10px 12px; font-family: var(--font-display); color: var(--color-slate); white-space: nowrap;">
                      {{ session.is_open ? 'En curso' : formatTime(session.closed_at) }}
                    </td>
                    <td style="padding: 10px 12px; text-align: right; font-family: var(--font-display); color: var(--color-slate);">{{ session.gross_formatted }}</td>
                    <td style="padding: 10px 12px; text-align: right; font-family: var(--font-display); color: var(--color-slate);">{{ session.meal_break_formatted }}</td>
                    <td style="padding: 10px 12px; text-align: right; font-family: var(--font-display); color: var(--color-primary);">{{ session.effective_formatted }}</td>
                    <td style="padding: 10px 12px;">
                      <span
                        v-if="session.is_open"
                        style="display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; background-color: var(--color-success-bg); color: var(--color-success-text); border: 1px solid var(--color-success); border-radius: var(--radius-interactive); font-size: var(--font-size-caption);"
                      >
                        En curso
                      </span>
                      <span v-else style="display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; background-color: var(--color-neutral-bg); color: var(--color-slate); border: 1px solid var(--color-neutral-border); border-radius: var(--radius-interactive); font-size: var(--font-size-caption);">
                        {{ session.status_label || 'Cerrado' }}
                      </span>

                      <span
                        v-if="session.is_anomalous"
                        style="display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; background-color: var(--color-warning-bg); color: var(--color-warning-text); border: 1px solid var(--color-warning-border); border-radius: var(--radius-interactive); font-size: var(--font-size-caption);"
                      >
                        ⚠️ &gt; 12h
                      </span>
                      <span
                        v-if="session.is_force_closed"
                        style="display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; background-color: var(--color-warning-bg); color: var(--color-warning-text); border: 1px solid var(--color-warning-border); border-radius: var(--radius-interactive); font-size: var(--font-size-caption);"
                      >
                        Cierre forzado
                      </span>
                      <span
                        v-if="session.is_adjusted"
                        style="display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; background-color: var(--color-info-bg); color: var(--color-info-text); border: 1px solid var(--color-info-border); border-radius: var(--radius-interactive); font-size: var(--font-size-caption);"
                      >
                        Ajustado por Coordinación
                      </span>
                    </td>
                    <td style="padding: 10px 12px;">
                      <div class="vg-action-group">
                        <button
                          v-if="!session.is_open"
                          type="button"
                          class="vg-btn vg-btn-primary-outline vg-btn-sm"
                          data-testid="btn-open-adjust-shift"
                          @click="openAdjustShift(session)"
                        >
                          ✏️ Ajustar Turno
                        </button>
                        <button
                          v-else
                          type="button"
                          class="vg-btn vg-btn-danger-soft vg-btn-sm"
                          data-testid="btn-open-force-close"
                          @click="openForceCloseShift(session)"
                        >
                          🛑 Forzar Cierre
                        </button>
                        <button
                          type="button"
                          class="vg-btn vg-btn-ghost vg-btn-sm"
                          data-testid="btn-open-shift-logs"
                          @click="openLogs(session)"
                        >
                          📜 Bitácora
                        </button>
                      </div>
                    </td>
                  </tr>

                  <!-- Marcas originales del primer fichaje del trabajador y motivos (Art. III.3) -->
                  <tr v-if="hasOriginalMarks(session)" style="background-color: var(--color-canvas); border-bottom: 1px solid var(--color-hairline);">
                    <td colspan="9" style="padding: 8px 12px; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                      <strong style="color: var(--color-slate);">Marcas originales del fichaje:</strong>
                      entrada {{ formatDateTime(session.original_started_at) }}
                      · salida {{ session.original_closed_at ? formatDateTime(session.original_closed_at) : '—' }}
                      · comida
                      {{ session.original_meal_break_seconds !== null && session.original_meal_break_seconds !== undefined
                        ? formatDuration(session.original_meal_break_seconds)
                        : '—' }}
                      <span v-if="session.adjustment_reason">
                        · <strong style="color: var(--color-slate);">Motivo del ajuste:</strong> {{ session.adjustment_reason }}
                      </span>
                      <span v-else-if="session.close_reason">
                        · <strong style="color: var(--color-slate);">Motivo del cierre:</strong> {{ session.close_reason }}
                      </span>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      <!-- Modal de rectificación auditada (RF-03) -->
      <ModalDialog
        :model-value="adjustShiftTarget !== null"
        title="✏️ Ajustar Turno"
        subtitle="Rectificación auditada de un turno cerrado. Las marcas originales del trabajador no se modifican."
        size="md"
        @update:model-value="closeAdjustShift"
      >
        <div v-if="adjustShiftTarget" data-testid="modal-adjust-shift" style="display: flex; flex-direction: column; gap: 14px;">
          <div style="padding: 10px 12px; background-color: var(--color-canvas); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
            <div>
              <strong style="color: var(--color-slate);">{{ adjustShiftTarget.technician_name || 'Técnico #' + adjustShiftTarget.technician_id }}</strong>
              · {{ formatDate(adjustShiftTarget.shift_date) }}
            </div>
            <div>
              Vigente: entrada {{ formatDateTime(adjustShiftTarget.started_at) }}
              · salida {{ adjustShiftTarget.closed_at ? formatDateTime(adjustShiftTarget.closed_at) : '—' }}
              · comida {{ formatDuration(adjustShiftTarget.meal_break_seconds) }}
            </div>
            <div v-if="hasOriginalMarks(adjustShiftTarget)">
              Original: entrada {{ formatDateTime(adjustShiftTarget.original_started_at) }}
              · salida {{ adjustShiftTarget.original_closed_at ? formatDateTime(adjustShiftTarget.original_closed_at) : '—' }}
            </div>
          </div>

          <label style="display: flex; flex-direction: column; gap: 4px; font-size: var(--font-size-body-sm); color: var(--color-slate);">
            Entrada vigente
            <input v-model="adjustForm.started_at" type="datetime-local" class="vg-input" style="font-family: var(--font-body);" />
          </label>

          <label style="display: flex; flex-direction: column; gap: 4px; font-size: var(--font-size-body-sm); color: var(--color-slate);">
            Salida vigente
            <input v-model="adjustForm.closed_at" type="datetime-local" class="vg-input" style="font-family: var(--font-body);" />
          </label>

          <label style="display: flex; flex-direction: column; gap: 4px; font-size: var(--font-size-body-sm); color: var(--color-slate);">
            Pausa de comida (minutos)
            <input v-model.number="adjustForm.meal_break_minutes" type="number" min="0" step="1" class="vg-input" style="font-family: var(--font-body); max-width: 160px;" />
          </label>

          <label style="display: flex; flex-direction: column; gap: 4px; font-size: var(--font-size-body-sm); color: var(--color-slate);">
            Motivo justificado (mínimo 20 caracteres reales, Art. V.1)
            <textarea v-model="adjustForm.reason" rows="3" class="vg-textarea" style="font-family: var(--font-body);"></textarea>
            <span :style="{ fontSize: 'var(--font-size-caption)', color: adjustReasonLength >= 20 ? 'var(--color-success-text)' : 'var(--color-ink-muted)' }">
              {{ adjustReasonLength }} / 20 caracteres reales
            </span>
          </label>

          <div v-if="adjustError" role="alert" style="padding: 10px 12px; background-color: var(--color-error-bg); color: var(--color-error-text); border-radius: var(--radius-interactive); font-size: var(--font-size-caption);">
            {{ adjustError }}
          </div>
        </div>

        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="closeAdjustShift">Cancelar</button>
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            :disabled="adjustSubmitting || adjustReasonLength < 20"
            @click="submitAdjustShift"
          >
            {{ adjustSubmitting ? 'Guardando…' : 'Guardar rectificación' }}
          </button>
        </template>
      </ModalDialog>

      <!-- Modal de cierre forzado con hora efectiva opcional (RF-02) -->
      <ModalDialog
        :model-value="forceCloseTarget !== null"
        title="🛑 Forzar Cierre"
        subtitle="El protocolo sanitario de frío se fecha siempre con el instante real (Art. II)."
        size="md"
        @update:model-value="closeForceCloseShift"
      >
        <div v-if="forceCloseTarget" data-testid="modal-force-close-shift" style="display: flex; flex-direction: column; gap: 14px;">
          <div style="padding: 10px 12px; background-color: var(--color-canvas); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
            <strong style="color: var(--color-slate);">{{ forceCloseTarget.technician_name || 'Técnico #' + forceCloseTarget.technician_id }}</strong>
            · entrada {{ formatDateTime(forceCloseTarget.started_at) }}
            · {{ forceCloseTarget.is_open === false ? 'sesión ya cerrada' : 'turno abierto' }}
          </div>

          <label style="display: flex; flex-direction: column; gap: 4px; font-size: var(--font-size-body-sm); color: var(--color-slate);">
            Hora real de salida (opcional)
            <input v-model="forceForm.effective_closed_at" type="datetime-local" class="vg-input" style="font-family: var(--font-body);" />
            <span style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">
              Si indicas la hora efectiva, el motivo debe alcanzar los 20 caracteres reales y se marcarán «Cierre forzado» y «Ajustado por Coordinación».
            </span>
          </label>

          <div v-if="forceClosePreview" style="padding: 10px 12px; background-color: var(--color-info-bg); color: var(--color-info-text); border-radius: var(--radius-interactive); font-size: var(--font-size-caption);">
            {{ forceClosePreview }}
          </div>

          <label style="display: flex; flex-direction: column; gap: 4px; font-size: var(--font-size-body-sm); color: var(--color-slate);">
            Motivo del cierre (mínimo {{ forceReasonMinimum }} caracteres reales)
            <textarea v-model="forceForm.reason" rows="3" class="vg-textarea" style="font-family: var(--font-body);"></textarea>
            <span :style="{ fontSize: 'var(--font-size-caption)', color: forceReasonLength >= forceReasonMinimum ? 'var(--color-success-text)' : 'var(--color-ink-muted)' }">
              {{ forceReasonLength }} / {{ forceReasonMinimum }} caracteres reales
            </span>
          </label>

          <div v-if="forceError" role="alert" style="padding: 10px 12px; background-color: var(--color-error-bg); color: var(--color-error-text); border-radius: var(--radius-interactive); font-size: var(--font-size-caption);">
            {{ forceError }}
          </div>
        </div>

        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="closeForceCloseShift">Cancelar</button>
          <button
            type="button"
            class="vg-btn vg-btn-danger"
            :disabled="forceSubmitting || forceReasonLength < forceReasonMinimum"
            @click="submitForceCloseShift"
          >
            {{ forceSubmitting ? 'Cerrando…' : 'Forzar cierre' }}
          </button>
        </template>
      </ModalDialog>

      <!-- Modal de bitácora inmutable (RF-01.1) -->
      <ModalDialog
        :model-value="logsTarget !== null"
        title="📜 Bitácora del Turno"
        subtitle="Registro inmutable de eventos de jornada: no se edita ni se elimina (Art. III.1 y III.3)."
        size="lg"
        @update:model-value="closeLogs"
      >
        <div v-if="logsTarget" data-testid="modal-shift-logs">
          <p v-if="logsLoading" style="font-size: var(--font-size-body-sm); color: var(--color-ink-muted); margin: 0;">
            Cargando los eventos del turno…
          </p>
          <p v-else-if="logsError" role="alert" style="font-size: var(--font-size-body-sm); color: var(--color-error-text); margin: 0;">
            {{ logsError }}
          </p>
          <p v-else-if="logs.length === 0" style="font-size: var(--font-size-body-sm); color: var(--color-ink-muted); margin: 0;">
            La bitácora de esta sesión aún no contiene eventos.
          </p>
          <ol v-else style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px;">
            <li
              v-for="log in logs"
              :key="'log-' + log.id"
              style="padding: 10px 12px; background-color: var(--color-canvas); border: 1px solid var(--color-hairline); border-radius: var(--radius-card);"
            >
              <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px;">
                <strong style="font-family: var(--font-body); font-size: var(--font-size-body-sm); color: var(--color-slate);">
                  {{ log.event_type_label || log.event_type }}
                </strong>
                <span style="font-family: var(--font-display); font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                  {{ formatDateTime(log.effective_at) }}
                </span>
              </div>
              <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted); margin-top: 4px;">
                {{ logAuthor(log) }}
                <span v-if="log.created_at"> · registrado {{ formatDateTime(log.created_at) }}</span>
              </div>
              <div v-if="log.previous_state || log.new_state" style="font-size: var(--font-size-caption); color: var(--color-slate); margin-top: 4px;">
                <span v-if="log.previous_state">Previos: {{ describeLogState(log.previous_state) }}</span>
                <span v-if="log.previous_state && log.new_state"> → </span>
                <span v-if="log.new_state">Resultantes: {{ describeLogState(log.new_state) }}</span>
              </div>
              <div v-if="log.reason" style="font-size: var(--font-size-caption); color: var(--color-slate); margin-top: 4px;">
                <strong>Motivo:</strong> {{ log.reason }}
              </div>
            </li>
          </ol>
        </div>

        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="closeLogs">Cerrar</button>
        </template>
      </ModalDialog>
    </div>
  `
};

export default CoordinatorWorkHoursTab;
