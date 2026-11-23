/**
 * VendGuard - CoordinatorRestockRoutePlannerSubTab
 *
 * Quinta sub-vista de `CoordinatorProductLoadingTab` para el Planificador de
 * Rutas Periódicas de Carga (Módulo 37; RF-01.3, RF-02.3, RF-03.3, RF-04.1,
 * RF-05.3, RNF-04, D5, D7, QA-4–QA-6, Art. IV y Art. VI).
 *
 * Superficie de Coordinación:
 *   1. Tablero instantáneo «Ruta del Día» con los siete contadores de un único
 *      `service_date` (Art. VI), fecha consultable y marca de proyección.
 *   2. Bandeja prioritaria «⚠️ Paradas sin cobertura hoy» con desvío directo.
 *   3. Rutas por reponedor agrupadas por sede y bloque, con omisión/desvío por
 *      máquina y lectura de completadas, omitidas y bloqueadas.
 *   4. Excepciones vivas con revocación auditada.
 *   5. CRUD versionado de agendas, baja lógica y alta masiva atómica.
 *
 * El componente no decide reglas de negocio: el perímetro, el motivo mínimo de
 * 20..500 caracteres, la coherencia de cadencia, el sello sanitario y el
 * versionado inmutable viven en el backend. Aquí solo se anticipan las
 * comprobaciones obvias y se muestra el error canónico.
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

const todayIso = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Madrid' }).format(new Date());

const emptyKpis = () => ({
  scheduled_machines_count: 0,
  injected_fefo_count: 0,
  injected_demand_count: 0,
  completed_count: 0,
  skipped_count: 0,
  blocked_count: 0,
  uncovered_count: 0
});

const emptyScheduleForm = () => ({
  machine_id: '',
  machine_code: '',
  cadence_type: 'DAILY',
  day_of_week: '',
  anchor_date: '',
  preferred_time_window: 'MORNING',
  include_weekends: false,
  notes: ''
});

const emptyBulkForm = () => ({
  zone_id: '',
  machine_type: '',
  machine_ids: [],
  cadence_type: 'DAILY',
  day_of_week: '',
  anchor_date: '',
  preferred_time_window: 'MORNING',
  include_weekends: false,
  notes: '',
  overwrite_existing: true
});

const emptyOverrideForm = () => ({
  machine_id: '',
  machine_code: '',
  service_date: '',
  override_type: 'SKIPPED',
  skip_reason_code: 'LOCATION_CLOSED',
  reassignment_reason_code: 'ZONE_UNCOVERED',
  target_restocker_id: '',
  reason_text: ''
});

const TRIGGER_LABELS = {
  FEFO_ALERT: 'FEFO',
  L1_INCIDENT: 'Aviso L1',
  EMPTY_SLOT: 'Carril vacío',
  SCHEDULED: 'Cadencia',
  INCOMING_REASSIGNMENT: 'Desvío entrante'
};

const BLOCK_LABELS = {
  FEFO_SANITARY: 'Bloque 1 · Riesgo sanitario FEFO',
  DEMAND_L1_STOCKOUT: 'Bloque 2 · Demanda L1 o stockout',
  SCHEDULED_CADENCE: 'Bloque 3 · Cadencia programada'
};

const SKIP_REASON_LABELS = {
  LOCATION_CLOSED: 'Local cerrado',
  NO_KEY_OR_ACCESS: 'Sin llave o acceso',
  LOCAL_HOLIDAY: 'Festivo local',
  FLEET_CAPACITY_SHORTAGE: 'Falta de capacidad de flota',
  OTHER_OPERATIONAL: 'Otra causa operativa'
};

const REASSIGNMENT_REASON_LABELS = {
  ZONE_UNCOVERED: 'Zona sin cobertura',
  LOAD_BALANCING: 'Equilibrio de carga',
  FEFO_RESCUE: 'Rescate FEFO',
  OTHER_REASSIGNMENT: 'Otro desvío operativo'
};

export const CoordinatorRestockRoutePlannerSubTab = {
  name: 'CoordinatorRestockRoutePlannerSubTab',
  components: { ModalDialog },
  data() {
    return {
      serviceDate: todayIso(),
      dayFilters: { restocker_id: '', zone_id: '' },
      day: null,
      isLoadingDay: false,
      dayRequestSeq: 0,
      dayError: '',

      plannerMachines: [],
      plannerZones: [],
      plannerRestockers: [],
      isLoadingPlannerOptions: false,
      plannerOptionsError: '',

      schedules: [],
      scheduleTotal: 0,
      scheduleFilters: { search: '', cadence_type: '', has_schedule: '' },
      isLoadingSchedules: false,
      scheduleError: '',
      scheduleSuccess: '',

      showScheduleModal: false,
      scheduleMode: 'create',
      scheduleForm: emptyScheduleForm(),
      scheduleFormError: '',
      isSavingSchedule: false,

      showBulkModal: false,
      bulkForm: emptyBulkForm(),
      bulkError: '',
      bulkResult: null,
      isSavingBulk: false,

      showOverrideModal: false,
      overrideForm: emptyOverrideForm(),
      overrideError: '',
      overrideSuccess: '',
      isSavingOverride: false,
      revokingOverrideId: null
    };
  },
  computed: {
    kpis() {
      return { ...emptyKpis(), ...(this.day?.kpis || {}) };
    },
    routes() {
      return Array.isArray(this.day?.restocker_routes) ? this.day.restocker_routes : [];
    },
    uncoveredStops() {
      return Array.isArray(this.day?.uncovered_stops) ? this.day.uncovered_stops : [];
    },
    activeOverrides() {
      return Array.isArray(this.day?.active_overrides) ? this.day.active_overrides : [];
    },
    hasUncovered() {
      return this.uncoveredStops.length > 0;
    },
    isFuture() {
      return this.serviceDate > todayIso();
    },
    futureLabel() {
      return this.isFuture ? 'Vista de proyección futura' : '';
    },
    isEditingSchedule() {
      return this.scheduleMode === 'edit';
    },
    overrideTitle() {
      return this.overrideForm.override_type === 'REASSIGNED'
        ? 'Desviar parada por un día'
        : 'Omitir parada por un día';
    },
    overrideIsSkip() {
      return this.overrideForm.override_type === 'SKIPPED';
    },
    scheduleCadenceOptions() {
      return [
        { value: 'DAILY', label: 'Diaria' },
        { value: 'MON_WED_FRI', label: 'Lunes, miércoles y viernes' },
        { value: 'TUE_THU', label: 'Martes y jueves' },
        { value: 'WEEKLY', label: 'Semanal' },
        { value: 'BIWEEKLY', label: 'Quincenal' }
      ];
    },
    timeWindowOptions() {
      return [
        { value: 'MORNING', label: 'Mañana' },
        { value: 'MIDDAY', label: 'Mediodía' },
        { value: 'AFTERNOON', label: 'Tarde' },
        { value: 'ANY', label: 'Cualquier franja' }
      ];
    },
    skipReasonOptions() {
      return Object.entries(SKIP_REASON_LABELS).map(([value, label]) => ({ value, label }));
    },
    reassignmentReasonOptions() {
      return Object.entries(REASSIGNMENT_REASON_LABELS).map(([value, label]) => ({ value, label }));
    },
    scheduleMachineOptions() {
      const machines = this.plannerMachines;
      const currentId = Number(this.scheduleForm.machine_id);
      if (!this.isEditingSchedule || !Number.isInteger(currentId) || currentId <= 0) return machines;
      if (machines.some((machine) => Number(machine.id) === currentId)) return machines;

      return [
        ...machines,
        {
          id: currentId,
          code: this.scheduleForm.machine_code || `Máquina ${currentId}`,
          location_name: 'Fuera del catálogo activo'
        }
      ];
    }
  },
  mounted() {
    this.loadDay();
    this.loadSchedules();
    this.loadPlannerOptions();
  },
  methods: {
    onServiceDateChange(event) {
      const value = String(event?.target?.value || '').trim();
      if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return;
      }

      this.serviceDate = value;
      return this.loadDay();
    },
    async loadDay() {
      const requestSeq = ++this.dayRequestSeq;
      this.isLoadingDay = true;
      this.dayError = '';
      const params = { service_date: this.serviceDate };
      if (this.dayFilters.restocker_id !== '') params.restocker_id = Number(this.dayFilters.restocker_id);
      if (this.dayFilters.zone_id !== '') params.zone_id = Number(this.dayFilters.zone_id);

      try {
        const nextDay = unwrap(await api.coordinator.getRestockRouteDay(params)) || null;
        if (requestSeq !== this.dayRequestSeq) return;
        this.day = nextDay;
      } catch (error) {
        if (requestSeq !== this.dayRequestSeq) return;
        this.dayError = error?.message || 'No se pudo cargar la ruta del día.';
      } finally {
        if (requestSeq === this.dayRequestSeq) {
          this.isLoadingDay = false;
        }
      }
    },
    async loadSchedules() {
      this.isLoadingSchedules = true;
      this.scheduleError = '';
      const params = { limit: 100 };
      if (this.scheduleFilters.search.trim() !== '') params.search = this.scheduleFilters.search.trim();
      if (this.scheduleFilters.cadence_type !== '') params.cadence_type = this.scheduleFilters.cadence_type;
      if (this.scheduleFilters.has_schedule !== '') params.has_schedule = this.scheduleFilters.has_schedule === 'true';

      try {
        const data = unwrap(await api.coordinator.getRestockSchedules(params)) || {};
        this.schedules = Array.isArray(data.items) ? data.items : [];
        this.scheduleTotal = Number(data.total || this.schedules.length);
      } catch (error) {
        this.scheduleError = error?.message || 'No se pudieron cargar las agendas.';
      } finally {
        this.isLoadingSchedules = false;
      }
    },
    async loadPlannerOptions() {
      this.isLoadingPlannerOptions = true;
      this.plannerOptionsError = '';
      const [machinesResult, zonesResult, restockersResult] = await Promise.allSettled([
        api.coordinator.getMachines({ status: 'active' }),
        api.coordinator.getZones(),
        api.coordinator.getUsers({ role: 'RESTOCKER', status: 'active' })
      ]);

      if (machinesResult.status === 'fulfilled') {
        this.plannerMachines = extractList(unwrap(machinesResult.value), ['machines', 'items'])
          .filter((machine) => machine && machine.is_active !== false);
      }
      if (zonesResult.status === 'fulfilled') {
        this.plannerZones = extractList(unwrap(zonesResult.value), ['zones'])
          .filter((zone) => zone && zone.is_active !== false);
      }
      if (restockersResult.status === 'fulfilled') {
        this.plannerRestockers = extractList(unwrap(restockersResult.value), ['users', 'items'])
          .filter((restocker) => restocker && restocker.is_active !== false);
      }
      if ([machinesResult, zonesResult, restockersResult].some((result) => result.status === 'rejected')) {
        this.plannerOptionsError = 'No se pudieron cargar todos los catálogos. Reintenta para actualizar los selectores.';
      }
      this.isLoadingPlannerOptions = false;
    },
    async refreshAll() {
      await Promise.all([this.loadDay(), this.loadSchedules()]);
    },
    machineOptionLabel(machine) {
      const code = machine?.code || machine?.machine_code || 'Máquina sin código';
      const location = machine?.location_name || '';
      const type = machine?.machine_type_label || machine?.machine_type || '';
      return [code, location, type].filter(Boolean).join(' · ');
    },
    zoneOptionLabel(zone) {
      if (!zone) return '';
      return [zone.code, zone.name].filter(Boolean).join(' · ');
    },
    restockerOptionLabel(restocker) {
      if (!restocker) return '';
      const name = restocker.name || restocker.email || 'Reponedor sin nombre';
      return restocker.operator_code ? `${name} · ${restocker.operator_code}` : name;
    },
    triggerLabel(trigger) {
      return TRIGGER_LABELS[trigger] || trigger;
    },
    blockLabel(block) {
      return BLOCK_LABELS[block] || block || 'Sin bloque';
    },
    skipReasonLabel(code) {
      return SKIP_REASON_LABELS[code] || code || '—';
    },
    reassignmentReasonLabel(code) {
      return REASSIGNMENT_REASON_LABELS[code] || code || '—';
    },
    overrideTone(type) {
      return type === 'SKIPPED' ? 'vg-badge vg-badge-danger' : 'vg-badge vg-badge-warning';
    },
    shiftTone(status) {
      return status === 'ON_SHIFT' || status === 'MEAL_BREAK'
        ? 'vg-badge vg-badge-success'
        : 'vg-badge vg-badge-neutral';
    },
    formatDate(value) {
      return value ? String(value).slice(0, 10) : '—';
    },
    formatDistance(value) {
      return value === null || value === undefined ? 'Sin GPS' : `${Number(value).toFixed(1)} km`;
    },
    countRouteMachines(route) {
      return (route.stops || []).reduce((total, stop) => total + (stop.machines || []).length, 0);
    },
    openScheduleModal(item = null) {
      this.scheduleFormError = '';
      this.scheduleSuccess = '';
      if (item && item.schedule) {
        this.scheduleMode = 'edit';
        this.scheduleForm = {
          machine_id: item.machine_id,
          machine_code: item.machine_code || '',
          cadence_type: item.schedule.cadence_type || 'DAILY',
          day_of_week: item.schedule.day_of_week ? String(item.schedule.day_of_week) : '',
          anchor_date: item.schedule.anchor_date || '',
          preferred_time_window: item.schedule.preferred_time_window || 'ANY',
          include_weekends: Boolean(item.schedule.include_weekends),
          notes: item.schedule.notes || ''
        };
      } else {
        this.scheduleMode = 'create';
        this.scheduleForm = emptyScheduleForm();
      }
      this.showScheduleModal = true;
    },
    validateSchedule() {
      const machineId = Number(this.scheduleForm.machine_id);
      if (!Number.isInteger(machineId) || machineId <= 0) {
        this.scheduleFormError = 'Indica una máquina válida para la agenda.';
        return false;
      }

      const cadence = this.scheduleForm.cadence_type;
      if (cadence === 'WEEKLY') {
        const day = Number(this.scheduleForm.day_of_week);
        if (!Number.isInteger(day) || day < 1 || day > 7) {
          this.scheduleFormError = 'WEEKLY exige un día de la semana entre 1 y 7.';
          return false;
        }
        if (this.scheduleForm.anchor_date !== '') {
          this.scheduleFormError = 'WEEKLY no admite fecha ancla.';
          return false;
        }
      } else if (cadence === 'BIWEEKLY') {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(this.scheduleForm.anchor_date)) {
          this.scheduleFormError = 'BIWEEKLY exige una fecha ancla válida.';
          return false;
        }
        if (this.scheduleForm.day_of_week !== '') {
          this.scheduleFormError = 'BIWEEKLY no admite día de la semana.';
          return false;
        }
      } else {
        if (this.scheduleForm.day_of_week !== '' || this.scheduleForm.anchor_date !== '') {
          this.scheduleFormError = 'Esta cadencia no admite día ni fecha ancla.';
          return false;
        }
      }

      if (cadence !== 'DAILY' && this.scheduleForm.include_weekends) {
        this.scheduleFormError = 'Solo DAILY puede incluir fines de semana.';
        return false;
      }

      this.scheduleFormError = '';
      return true;
    },
    schedulePayload() {
      const payload = {
        cadence_type: this.scheduleForm.cadence_type,
        preferred_time_window: this.scheduleForm.preferred_time_window,
        include_weekends: this.scheduleForm.cadence_type === 'DAILY'
          ? Boolean(this.scheduleForm.include_weekends)
          : false,
        notes: this.scheduleForm.notes.trim() || null
      };
      if (this.scheduleForm.cadence_type === 'WEEKLY') {
        payload.day_of_week = Number(this.scheduleForm.day_of_week);
      }
      if (this.scheduleForm.cadence_type === 'BIWEEKLY') {
        payload.anchor_date = this.scheduleForm.anchor_date;
      }
      return payload;
    },
    async saveSchedule() {
      if (!this.validateSchedule()) return;
      this.isSavingSchedule = true;
      try {
        const machineId = Number(this.scheduleForm.machine_id);
        if (this.isEditingSchedule) {
          await api.coordinator.updateRestockSchedule(machineId, this.schedulePayload());
        } else {
          await api.coordinator.createRestockSchedule({ machine_id: machineId, ...this.schedulePayload() });
        }
        this.showScheduleModal = false;
        this.scheduleSuccess = 'Agenda guardada y versionada correctamente.';
        await this.loadSchedules();
        await this.loadDay();
      } catch (error) {
        this.scheduleFormError = error?.message || 'No se pudo guardar la agenda.';
      } finally {
        this.isSavingSchedule = false;
      }
    },
    async deactivateSchedule(item) {
      if (!item?.schedule?.id) return;
      this.scheduleError = '';
      try {
        await api.coordinator.deactivateRestockSchedule(item.schedule.id);
        this.scheduleSuccess = 'Agenda dada de baja lógicamente; su histórico permanece inmutable.';
        await this.loadSchedules();
        await this.loadDay();
      } catch (error) {
        this.scheduleError = error?.message || 'No se pudo dar de baja la agenda.';
      }
    },
    openBulkModal() {
      this.bulkForm = emptyBulkForm();
      this.bulkError = '';
      this.bulkResult = null;
      this.showBulkModal = true;
    },
    validateBulk() {
      const hasMachines = Array.isArray(this.bulkForm.machine_ids) && this.bulkForm.machine_ids.length > 0;
      const hasZone = this.bulkForm.zone_id !== '' && this.bulkForm.zone_id !== null;
      const hasType = this.bulkForm.machine_type !== '';
      if (!hasMachines && !hasZone && !hasType) {
        this.bulkError = 'Indica una zona, un tipo de máquina o una lista de máquinas.';
        return false;
      }
      if (this.bulkForm.cadence_type === 'WEEKLY') {
        const day = Number(this.bulkForm.day_of_week);
        if (!Number.isInteger(day) || day < 1 || day > 7) {
          this.bulkError = 'WEEKLY exige un día de la semana entre 1 y 7.';
          return false;
        }
      }
      if (this.bulkForm.cadence_type === 'BIWEEKLY' && !/^\d{4}-\d{2}-\d{2}$/.test(this.bulkForm.anchor_date)) {
        this.bulkError = 'BIWEEKLY exige una fecha ancla válida.';
        return false;
      }
      if (this.bulkForm.cadence_type !== 'DAILY' && this.bulkForm.include_weekends) {
        this.bulkError = 'Solo DAILY puede incluir fines de semana.';
        return false;
      }
      this.bulkError = '';
      return true;
    },
    async saveBulk() {
      if (!this.validateBulk()) return;
      this.isSavingBulk = true;
      try {
        const payload = {
          cadence_type: this.bulkForm.cadence_type,
          preferred_time_window: this.bulkForm.preferred_time_window,
          include_weekends: this.bulkForm.cadence_type === 'DAILY'
            ? Boolean(this.bulkForm.include_weekends)
            : false,
          overwrite_existing: Boolean(this.bulkForm.overwrite_existing),
          notes: this.bulkForm.notes.trim() || null
        };
        if (this.bulkForm.cadence_type === 'WEEKLY') payload.day_of_week = Number(this.bulkForm.day_of_week);
        if (this.bulkForm.cadence_type === 'BIWEEKLY') payload.anchor_date = this.bulkForm.anchor_date;

        const machineIds = [...new Set((Array.isArray(this.bulkForm.machine_ids) ? this.bulkForm.machine_ids : [])
          .map((value) => Number(value))
          .filter((value) => Number.isInteger(value) && value > 0))];
        if (machineIds.length > 0) payload.machine_ids = machineIds;
        if (this.bulkForm.zone_id !== '' && this.bulkForm.zone_id !== null) payload.zone_id = Number(this.bulkForm.zone_id);
        if (this.bulkForm.machine_type !== '') payload.machine_type = this.bulkForm.machine_type;

        this.bulkResult = unwrap(await api.coordinator.bulkRestockSchedules(payload)) || null;
        this.showBulkModal = false;
        this.scheduleSuccess = 'Configuración masiva aplicada de forma atómica.';
        await this.loadSchedules();
        await this.loadDay();
      } catch (error) {
        this.bulkError = error?.message || 'No se pudo aplicar la configuración masiva.';
      } finally {
        this.isSavingBulk = false;
      }
    },
    openSkipModal(machine) {
      this.openOverrideModal(machine, 'SKIPPED');
    },
    openReassignModal(machine) {
      this.openOverrideModal(machine, 'REASSIGNED');
    },
    openOverrideModal(machine, type) {
      const selected = machine && typeof machine === 'object' ? machine : { machine_id: machine };
      this.overrideForm = {
        ...emptyOverrideForm(),
        machine_id: Number(selected.machine_id ?? selected.id),
        machine_code: selected.machine_code || selected.code || '',
        service_date: this.serviceDate,
        override_type: type
      };
      this.overrideError = '';
      this.overrideSuccess = '';
      this.showOverrideModal = true;
    },
    normalizedReason() {
      return String(this.overrideForm.reason_text || '').trim().replace(/\s+/g, ' ');
    },
    alphanumericBlocks(text) {
      return (text.match(/[\p{L}\p{N}]+/gu) || []).length;
    },
    validateOverride() {
      const machineId = Number(this.overrideForm.machine_id);
      if (!Number.isInteger(machineId) || machineId <= 0) {
        this.overrideError = 'La máquina de la parada no es válida.';
        return false;
      }
      if (!/^\d{4}-\d{2}-\d{2}$/.test(this.overrideForm.service_date)) {
        this.overrideError = 'La fecha de servicio no es válida.';
        return false;
      }

      const reason = this.normalizedReason();
      if (this.overrideForm.override_type === 'SKIPPED') {
        if (reason.length < 20 || reason.length > 500 || this.alphanumericBlocks(reason) < 3) {
          this.overrideError = 'El motivo debe tener entre 20 y 500 caracteres reales y al menos 3 bloques alfanuméricos.';
          return false;
        }
      } else {
        const target = Number(this.overrideForm.target_restocker_id);
        if (!Number.isInteger(target) || target <= 0) {
          this.overrideError = 'Selecciona un reponedor receptor para el desvío.';
          return false;
        }
        if (reason.length > 500) {
          this.overrideError = 'La nota del desvío no puede superar 500 caracteres reales.';
          return false;
        }
      }

      this.overrideError = '';
      return true;
    },
    async saveOverride() {
      if (!this.validateOverride()) return;
      this.isSavingOverride = true;
      try {
        const reason = this.normalizedReason();
        const payload = {
          machine_id: Number(this.overrideForm.machine_id),
          service_date: this.overrideForm.service_date,
          override_type: this.overrideForm.override_type
        };
        if (this.overrideForm.override_type === 'SKIPPED') {
          payload.skip_reason_code = this.overrideForm.skip_reason_code;
          payload.reason_text = reason;
        } else {
          payload.target_restocker_id = Number(this.overrideForm.target_restocker_id);
          payload.reassignment_reason_code = this.overrideForm.reassignment_reason_code;
          if (reason !== '') payload.reason_text = reason;
        }

        await api.coordinator.createRestockRouteOverride(payload);
        this.showOverrideModal = false;
        this.overrideSuccess = payload.override_type === 'SKIPPED'
          ? 'La omisión se registró correctamente.'
          : 'El desvío se registró correctamente.';
        await this.loadDay();
      } catch (error) {
        this.overrideError = error?.message || 'No se pudo registrar la excepción de ruta.';
      } finally {
        this.isSavingOverride = false;
      }
    },
    async revokeOverride(override) {
      if (!override?.id) return;
      this.revokingOverrideId = override.id;
      this.overrideError = '';
      try {
        await api.coordinator.revokeRestockRouteOverride(override.id);
        this.overrideSuccess = 'La excepción quedó revocada y auditada.';
        await this.loadDay();
      } catch (error) {
        this.overrideError = error?.message || 'No se pudo revocar la excepción.';
      } finally {
        this.revokingOverrideId = null;
      }
    }
  },
  template: `
    <div class="vg-restock-route-planner" data-testid="coordinator-restock-route-planner">
      <div class="vg-action-group" style="margin-bottom: 16px; flex-wrap: wrap; justify-content: space-between;">
        <div>
          <h3 style="margin: 0; font-family: var(--font-display); font-size: var(--font-size-heading-lg); color: var(--color-ink-slate);">
            🗺️ Planificador y Rutas
          </h3>
          <p style="margin: 4px 0 0; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
            Tablero instantáneo de un único día, agendas versionadas y excepciones puntuales sin tocar la titularidad territorial.
          </p>
        </div>
        <div class="vg-action-group" style="gap: 6px; flex-wrap: wrap;">
          <input
            v-model="serviceDate"
            type="date"
            class="vg-input"
            aria-label="Fecha de servicio"
            data-testid="route-planner-service-date"
            @change="onServiceDateChange"
          />
          <button type="button" class="vg-btn vg-btn-secondary" data-testid="route-planner-refresh" @click="refreshAll">
            {{ isLoadingDay ? 'Cargando…' : '↻ Actualizar' }}
          </button>
          <button type="button" class="vg-btn vg-btn-primary" data-testid="route-planner-new-schedule" @click="openScheduleModal()">
            ＋ Nueva agenda
          </button>
          <button type="button" class="vg-btn vg-btn-secondary" data-testid="route-planner-bulk" @click="openBulkModal">
            ⚡ Alta masiva
          </button>
        </div>
      </div>

      <div v-if="dayError" class="vg-alert vg-alert-error" role="alert" data-testid="route-planner-day-error" style="margin-bottom: 14px;">
        ⚠️ {{ dayError }}
      </div>
      <div v-if="futureLabel" class="vg-alert vg-alert-info" role="status" data-testid="route-planner-future-badge" style="margin-bottom: 14px;">
        🗓️ {{ futureLabel }} · {{ serviceDate }}
      </div>
      <div v-if="plannerOptionsError" class="vg-alert vg-alert-error" role="alert" data-testid="route-planner-options-error" style="margin-bottom: 14px;">
        ⚠️ {{ plannerOptionsError }}
        <button type="button" class="vg-btn vg-btn-secondary vg-btn-sm ms-2" @click="loadPlannerOptions">Reintentar</button>
      </div>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px;" data-testid="route-planner-kpis">
        <div class="vg-card" style="padding: 14px;">
          <div class="vg-label">Programadas</div>
          <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-primary);">{{ kpis.scheduled_machines_count }}</div>
        </div>
        <div class="vg-card" style="padding: 14px;">
          <div class="vg-label">FEFO inyectadas</div>
          <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-error-text);">{{ kpis.injected_fefo_count }}</div>
        </div>
        <div class="vg-card" style="padding: 14px;">
          <div class="vg-label">Demanda inyectada</div>
          <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-warning-text);">{{ kpis.injected_demand_count }}</div>
        </div>
        <div class="vg-card" style="padding: 14px;">
          <div class="vg-label">Completadas</div>
          <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-success-text);">{{ kpis.completed_count }}</div>
        </div>
        <div class="vg-card" style="padding: 14px;">
          <div class="vg-label">Omitidas</div>
          <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-error-text);">{{ kpis.skipped_count }}</div>
        </div>
        <div class="vg-card" style="padding: 14px;">
          <div class="vg-label">Bloqueadas</div>
          <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-ink-slate);">{{ kpis.blocked_count }}</div>
        </div>
        <div class="vg-card" style="padding: 14px;">
          <div class="vg-label">Sin cobertura</div>
          <div style="font-family: var(--font-display); font-size: var(--font-size-display-sm); color: var(--color-warning-text);">{{ kpis.uncovered_count }}</div>
        </div>
      </div>

      <section data-testid="route-planner-uncovered" style="margin-bottom: 18px;">
        <div class="vg-action-group" style="justify-content: space-between; flex-wrap: wrap; margin-bottom: 8px;">
          <h4 style="margin: 0; font-family: var(--font-display); font-size: var(--font-size-heading-md); color: var(--color-ink-slate);">
            ⚠️ Paradas sin cobertura hoy
          </h4>
          <span class="vg-badge vg-badge-warning">{{ uncoveredStops.length }}</span>
        </div>
        <div v-if="hasUncovered" class="vg-card" style="padding: 0; overflow-x: auto;">
          <table class="vg-table" data-testid="route-planner-uncovered-table">
            <thead>
              <tr>
                <th>Máquina</th>
                <th>Sede / Zona</th>
                <th>Bloque</th>
                <th>Disparadores</th>
                <th>Acción</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="stop in uncoveredStops" :key="stop.machine_id">
                <td><strong>{{ stop.machine_code }}</strong></td>
                <td>{{ stop.location_name }}<div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ stop.zone_name || 'Sin zona' }}</div></td>
                <td>{{ blockLabel(stop.priority_block) }}</td>
                <td>
                  <span v-for="trigger in stop.triggers" :key="trigger" class="vg-badge vg-badge-neutral" style="margin-right: 4px;">{{ triggerLabel(trigger) }}</span>
                </td>
                <td>
                  <button type="button" class="vg-btn vg-btn-primary" :data-testid="'route-planner-uncovered-reassign-' + stop.machine_id" @click="openReassignModal(stop)">
                    Desviar
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="vg-card" style="padding: 14px; color: var(--color-ink-muted);">
          Toda la demanda del día tiene titular efectivo.
        </div>
      </section>

      <section data-testid="route-planner-routes" style="margin-bottom: 18px;">
        <h4 style="margin: 0 0 8px; font-family: var(--font-display); font-size: var(--font-size-heading-md); color: var(--color-ink-slate);">
          Rutas por reponedor
        </h4>
        <div v-if="routes.length === 0" class="vg-card" style="padding: 14px; color: var(--color-ink-muted);">
          No hay paradas pendientes para la fecha seleccionada.
        </div>
        <div v-for="route in routes" :key="route.restocker_id" class="vg-card" style="padding: 14px; margin-bottom: 12px;">
          <div class="vg-action-group" style="justify-content: space-between; flex-wrap: wrap; margin-bottom: 10px;">
            <div>
              <strong style="font-family: var(--font-display); font-size: var(--font-size-heading-md); color: var(--color-ink-slate);">{{ route.restocker_name }}</strong>
              <div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ route.operator_code || 'Sin código' }}</div>
            </div>
            <div class="vg-action-group" style="gap: 6px; flex-wrap: wrap;">
              <span :class="shiftTone(route.shift_status)">{{ route.shift_status || 'Sin turno' }}</span>
              <span class="vg-badge vg-badge-neutral">{{ countRouteMachines(route) }} máquinas</span>
              <span class="vg-badge vg-badge-neutral">{{ formatDistance(route.summary?.total_distance_km) }}</span>
            </div>
          </div>

          <div v-for="stop in route.stops" :key="stop.location_id + '-' + stop.priority_block" style="border-top: 1px solid var(--color-hairline); padding-top: 10px; margin-top: 10px;">
            <div class="vg-action-group" style="justify-content: space-between; flex-wrap: wrap; margin-bottom: 6px;">
              <strong>{{ stop.location_name }}</strong>
              <span class="vg-badge vg-badge-neutral">{{ blockLabel(stop.priority_block) }}</span>
            </div>
            <div v-for="machine in stop.machines" :key="machine.machine_id" class="vg-action-group" style="justify-content: space-between; flex-wrap: wrap; padding: 8px 0; border-top: 1px dashed var(--color-hairline);">
              <div style="min-width: 0;">
                <strong>{{ machine.machine_code }}</strong>
                <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px;">
                  <span v-for="trigger in machine.triggers" :key="trigger" class="vg-badge vg-badge-neutral">{{ triggerLabel(trigger) }}</span>
                  <span v-if="machine.has_fefo_alert" class="vg-badge vg-badge-danger">FEFO</span>
                  <span v-if="machine.has_expired_cold_batch" class="vg-badge vg-badge-danger">Lote vencido</span>
                  <span v-if="machine.empty_slots_count > 0" class="vg-badge vg-badge-warning">{{ machine.empty_slots_count }} vacíos</span>
                </div>
              </div>
              <div class="vg-action-group" style="gap: 4px; flex-wrap: wrap;">
                <button type="button" class="vg-btn vg-btn-secondary" :data-testid="'route-planner-skip-' + machine.machine_id" @click="openSkipModal(machine)">
                  ⏭️ Omitir
                </button>
                <button type="button" class="vg-btn vg-btn-secondary" :data-testid="'route-planner-reassign-' + machine.machine_id" @click="openReassignModal(machine)">
                  🔁 Desviar
                </button>
              </div>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; margin-top: 10px;">
            <div><span class="vg-label">Completadas</span>{{ route.summary?.completed_machines_count || 0 }}</div>
            <div><span class="vg-label">Omitidas</span>{{ route.summary?.skipped_machines_count || 0 }}</div>
            <div><span class="vg-label">Bloqueadas</span>{{ route.summary?.blocked_machines_count || 0 }}</div>
            <div><span class="vg-label">FEFO</span>{{ route.summary?.fefo_alerts_count || 0 }}</div>
          </div>
        </div>
      </section>

      <section data-testid="route-planner-overrides" style="margin-bottom: 18px;">
        <h4 style="margin: 0 0 8px; font-family: var(--font-display); font-size: var(--font-size-heading-md); color: var(--color-ink-slate);">
          Excepciones vivas del día
        </h4>
        <div v-if="activeOverrides.length === 0" class="vg-card" style="padding: 14px; color: var(--color-ink-muted);">
          No hay omisiones ni desvíos activos para esta fecha.
        </div>
        <div v-else class="vg-card" style="padding: 0; overflow-x: auto;">
          <table class="vg-table" data-testid="route-planner-overrides-table">
            <thead>
              <tr>
                <th>Máquina</th>
                <th>Tipo</th>
                <th>Motivo</th>
                <th>Nota</th>
                <th>Estado</th>
                <th>Acción</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="override in activeOverrides" :key="override.id">
                <td>{{ override.machine_code || '—' }}</td>
                <td><span :class="overrideTone(override.override_type)">{{ override.override_type_label || override.override_type }}</span></td>
                <td>{{ override.skip_reason_label || override.reassignment_reason_label || '—' }}</td>
                <td style="max-width: 320px;">{{ override.reason_text || '—' }}</td>
                <td>{{ override.status_label || override.status }}</td>
                <td>
                  <button
                    v-if="override.status === 'ACTIVE'"
                    type="button"
                    class="vg-btn vg-btn-secondary"
                    :disabled="revokingOverrideId === override.id"
                    :data-testid="'route-planner-revoke-' + override.id"
                    @click="revokeOverride(override)"
                  >
                    {{ revokingOverrideId === override.id ? 'Revocando…' : 'Revocar' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section data-testid="route-planner-schedules">
        <div class="vg-action-group" style="justify-content: space-between; flex-wrap: wrap; margin-bottom: 8px;">
          <h4 style="margin: 0; font-family: var(--font-display); font-size: var(--font-size-heading-md); color: var(--color-ink-slate);">
            Agendas periódicas
          </h4>
          <span class="vg-badge vg-badge-neutral">{{ scheduleTotal }} máquinas</span>
        </div>
        <div v-if="scheduleSuccess" class="vg-alert vg-alert-success" role="status" data-testid="route-planner-schedule-success" style="margin-bottom: 10px;">✅ {{ scheduleSuccess }}</div>
        <div v-if="scheduleError" class="vg-alert vg-alert-error" role="alert" data-testid="route-planner-schedule-error" style="margin-bottom: 10px;">⚠️ {{ scheduleError }}</div>

        <div class="vg-card" style="padding: 14px; margin-bottom: 10px;">
          <div class="vg-action-group" style="gap: 8px; flex-wrap: wrap; align-items: flex-end;">
            <div>
              <label class="vg-label" for="route-schedule-search">Buscar</label>
              <input id="route-schedule-search" v-model="scheduleFilters.search" class="vg-input" placeholder="Máquina o sede" data-testid="route-planner-schedule-search" @change="loadSchedules" />
            </div>
            <div>
              <label class="vg-label" for="route-schedule-cadence">Cadencia</label>
              <select id="route-schedule-cadence" v-model="scheduleFilters.cadence_type" class="vg-input" data-testid="route-planner-schedule-cadence" @change="loadSchedules">
                <option value="">Todas</option>
                <option v-for="option in scheduleCadenceOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
              </select>
            </div>
            <div>
              <label class="vg-label" for="route-schedule-has">Agenda</label>
              <select id="route-schedule-has" v-model="scheduleFilters.has_schedule" class="vg-input" data-testid="route-planner-schedule-has" @change="loadSchedules">
                <option value="">Todas</option>
                <option value="true">Con agenda</option>
                <option value="false">Sin agenda</option>
              </select>
            </div>
            <button type="button" class="vg-btn vg-btn-secondary" data-testid="route-planner-schedule-refresh" @click="loadSchedules">
              {{ isLoadingSchedules ? 'Cargando…' : '↻ Filtrar' }}
            </button>
          </div>
        </div>

        <div class="vg-card" style="padding: 0; overflow-x: auto;">
          <table class="vg-table" data-testid="route-planner-schedule-table">
            <thead>
              <tr>
                <th>Máquina</th>
                <th>Sede / Zona</th>
                <th>Cadencia</th>
                <th>Franja</th>
                <th>Próxima</th>
                <th>Estado</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in schedules" :key="item.machine_id">
                <td><strong>{{ item.machine_code }}</strong><div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ item.machine_type }}</div></td>
                <td>{{ item.location_name }}<div style="font-size: var(--font-size-caption); color: var(--color-ink-muted);">{{ item.zone_name || 'Sin zona' }}</div></td>
                <td>{{ item.schedule ? item.schedule.cadence_label : 'Sin agenda' }}</td>
                <td>{{ item.schedule ? item.schedule.preferred_time_window_label : '—' }}</td>
                <td>{{ formatDate(item.next_scheduled_date) }}</td>
                <td>
                  <span :class="item.has_schedule ? 'vg-badge vg-badge-success' : 'vg-badge vg-badge-neutral'">
                    {{ item.has_schedule ? 'Activa' : 'Sin agenda' }}
                  </span>
                </td>
                <td>
                  <div class="vg-action-group" style="gap: 4px; flex-wrap: wrap;">
                    <button type="button" class="vg-btn vg-btn-secondary" :data-testid="'route-planner-schedule-edit-' + item.machine_id" @click="openScheduleModal(item)">
                      {{ item.has_schedule ? 'Editar' : 'Crear' }}
                    </button>
                    <button v-if="item.has_schedule" type="button" class="vg-btn vg-btn-secondary" :data-testid="'route-planner-schedule-deactivate-' + item.machine_id" @click="deactivateSchedule(item)">
                      Baja
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!isLoadingSchedules && schedules.length === 0">
                <td colspan="7" style="text-align: center; padding: 24px; color: var(--color-ink-muted);">
                  No hay máquinas que coincidan con los filtros.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <ModalDialog v-model="showScheduleModal" :title="isEditingSchedule ? 'Versionar agenda' : 'Nueva agenda periódica'" subtitle="Cada cambio cierra la versión vigente e inserta una nueva fila inmutable (QA-2, Art. III.3)." size="lg">
        <div v-if="scheduleFormError" class="vg-alert vg-alert-error" role="alert" data-testid="route-planner-schedule-form-error" style="margin-bottom: 12px;">⚠️ {{ scheduleFormError }}</div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
          <div>
            <label class="vg-label" for="route-schedule-machine">Máquina</label>
            <select id="route-schedule-machine" v-model="scheduleForm.machine_id" class="vg-input" :disabled="isEditingSchedule" data-testid="route-planner-schedule-form-machine">
              <option value="" disabled>{{ isLoadingPlannerOptions ? 'Cargando máquinas…' : 'Selecciona una máquina…' }}</option>
              <option v-for="machine in scheduleMachineOptions" :key="machine.id" :value="machine.id">{{ machineOptionLabel(machine) }}</option>
            </select>
          </div>
          <div>
            <label class="vg-label" for="route-schedule-form-cadence">Cadencia</label>
            <select id="route-schedule-form-cadence" v-model="scheduleForm.cadence_type" class="vg-input" data-testid="route-planner-schedule-form-cadence">
              <option v-for="option in scheduleCadenceOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </div>
          <div v-if="scheduleForm.cadence_type === 'WEEKLY'">
            <label class="vg-label" for="route-schedule-day">Día ISO (1–7)</label>
            <input id="route-schedule-day" v-model="scheduleForm.day_of_week" type="number" min="1" max="7" class="vg-input" data-testid="route-planner-schedule-form-day" />
          </div>
          <div v-if="scheduleForm.cadence_type === 'BIWEEKLY'">
            <label class="vg-label" for="route-schedule-anchor">Fecha ancla</label>
            <input id="route-schedule-anchor" v-model="scheduleForm.anchor_date" type="date" class="vg-input" data-testid="route-planner-schedule-form-anchor" />
          </div>
          <div>
            <label class="vg-label" for="route-schedule-window">Franja</label>
            <select id="route-schedule-window" v-model="scheduleForm.preferred_time_window" class="vg-input" data-testid="route-planner-schedule-form-window">
              <option v-for="option in timeWindowOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </div>
          <div v-if="scheduleForm.cadence_type === 'DAILY'" style="display: flex; align-items: center; gap: 8px;">
            <input id="route-schedule-weekends" v-model="scheduleForm.include_weekends" type="checkbox" data-testid="route-planner-schedule-form-weekends" />
            <label class="vg-label" for="route-schedule-weekends" style="margin: 0;">Incluir fines de semana</label>
          </div>
        </div>
        <div style="margin-top: 12px;">
          <label class="vg-label" for="route-schedule-notes">Notas internas (opcional)</label>
          <textarea id="route-schedule-notes" v-model="scheduleForm.notes" class="vg-input" rows="2" maxlength="500" data-testid="route-planner-schedule-form-notes"></textarea>
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showScheduleModal = false">Cancelar</button>
          <button type="button" class="vg-btn vg-btn-primary" :disabled="isSavingSchedule" data-testid="route-planner-schedule-form-save" @click="saveSchedule">
            {{ isSavingSchedule ? 'Guardando…' : 'Guardar agenda' }}
          </button>
        </template>
      </ModalDialog>

      <ModalDialog v-model="showBulkModal" title="Alta masiva de agendas" subtitle="Todo o nada: el lote se aplica en una única transacción y respeta overwrite_existing (RF-01.3.2)." size="lg">
        <div v-if="bulkError" class="vg-alert vg-alert-error" role="alert" data-testid="route-planner-bulk-error" style="margin-bottom: 12px;">⚠️ {{ bulkError }}</div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
          <div>
            <label class="vg-label" for="route-bulk-zone">Zona</label>
            <select id="route-bulk-zone" v-model="bulkForm.zone_id" class="vg-input" data-testid="route-planner-bulk-zone">
              <option value="">Sin filtro de zona</option>
              <option v-for="zone in plannerZones" :key="zone.id" :value="zone.id">{{ zoneOptionLabel(zone) }}</option>
            </select>
          </div>
          <div>
            <label class="vg-label" for="route-bulk-type">Tipo de máquina</label>
            <input id="route-bulk-type" v-model="bulkForm.machine_type" class="vg-input" placeholder="SNACKS" data-testid="route-planner-bulk-type" />
          </div>
          <div style="grid-column: 1 / -1;">
            <span class="vg-label">Máquinas (opcional · {{ bulkForm.machine_ids.length }} seleccionadas)</span>
            <div style="max-height: 180px; overflow-y: auto; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); padding: 8px;" data-testid="route-planner-bulk-machine-list">
              <label v-for="machine in plannerMachines" :key="machine.id" style="display: flex; align-items: flex-start; gap: 8px; padding: 4px 0; font-size: var(--font-size-body-sm);">
                <input type="checkbox" :value="machine.id" v-model="bulkForm.machine_ids" :data-testid="'route-planner-bulk-machine-' + machine.id" />
                <span>{{ machineOptionLabel(machine) }}</span>
              </label>
              <p v-if="plannerMachines.length === 0" style="margin: 4px 0; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
                {{ isLoadingPlannerOptions ? 'Cargando máquinas…' : 'No hay máquinas activas disponibles.' }}
              </p>
            </div>
            <p style="margin: 4px 0 0; font-size: var(--font-size-caption); color: var(--color-ink-muted);">
              Marca máquinas concretas o deja la lista vacía para aplicar solo por zona y tipo.
            </p>
          </div>
          <div>
            <label class="vg-label" for="route-bulk-cadence">Cadencia</label>
            <select id="route-bulk-cadence" v-model="bulkForm.cadence_type" class="vg-input" data-testid="route-planner-bulk-cadence">
              <option v-for="option in scheduleCadenceOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </div>
          <div v-if="bulkForm.cadence_type === 'WEEKLY'">
            <label class="vg-label" for="route-bulk-day">Día ISO (1–7)</label>
            <input id="route-bulk-day" v-model="bulkForm.day_of_week" type="number" min="1" max="7" class="vg-input" data-testid="route-planner-bulk-day" />
          </div>
          <div v-if="bulkForm.cadence_type === 'BIWEEKLY'">
            <label class="vg-label" for="route-bulk-anchor">Fecha ancla</label>
            <input id="route-bulk-anchor" v-model="bulkForm.anchor_date" type="date" class="vg-input" data-testid="route-planner-bulk-anchor" />
          </div>
          <div>
            <label class="vg-label" for="route-bulk-window">Franja</label>
            <select id="route-bulk-window" v-model="bulkForm.preferred_time_window" class="vg-input" data-testid="route-planner-bulk-window">
              <option v-for="option in timeWindowOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </div>
          <div v-if="bulkForm.cadence_type === 'DAILY'" style="display: flex; align-items: center; gap: 8px;">
            <input id="route-bulk-weekends" v-model="bulkForm.include_weekends" type="checkbox" data-testid="route-planner-bulk-weekends" />
            <label class="vg-label" for="route-bulk-weekends" style="margin: 0;">Incluir fines de semana</label>
          </div>
          <div style="display: flex; align-items: center; gap: 8px;">
            <input id="route-bulk-overwrite" v-model="bulkForm.overwrite_existing" type="checkbox" data-testid="route-planner-bulk-overwrite" />
            <label class="vg-label" for="route-bulk-overwrite" style="margin: 0;">Sobrescribir agendas existentes</label>
          </div>
        </div>
        <div style="margin-top: 12px;">
          <label class="vg-label" for="route-bulk-notes">Notas internas (opcional)</label>
          <textarea id="route-bulk-notes" v-model="bulkForm.notes" class="vg-input" rows="2" maxlength="500" data-testid="route-planner-bulk-notes"></textarea>
        </div>
        <div v-if="bulkResult" class="vg-alert vg-alert-success" role="status" style="margin-top: 12px;">
          {{ bulkResult.matched_count }} coincidencias · {{ bulkResult.created_count }} creadas · {{ bulkResult.updated_count }} actualizadas · {{ bulkResult.skipped_count }} omitidas
        </div>
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showBulkModal = false">Cancelar</button>
          <button type="button" class="vg-btn vg-btn-primary" :disabled="isSavingBulk" data-testid="route-planner-bulk-save" @click="saveBulk">
            {{ isSavingBulk ? 'Aplicando…' : 'Aplicar lote' }}
          </button>
        </template>
      </ModalDialog>

      <ModalDialog v-model="showOverrideModal" :title="overrideTitle" subtitle="La excepción vive solo en la fecha indicada y queda auditada; la titularidad territorial no cambia (C1, Art. III.3)." size="md">
        <div v-if="overrideError" class="vg-alert vg-alert-error" role="alert" data-testid="route-planner-override-error" style="margin-bottom: 12px;">⚠️ {{ overrideError }}</div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px;">
          <div>
            <label class="vg-label">Máquina</label>
            <div class="vg-input" style="background: var(--color-surface-1);">{{ overrideForm.machine_code || 'Máquina de la parada' }}</div>
          </div>
          <div>
            <label class="vg-label" for="route-override-date">Fecha de servicio</label>
            <input id="route-override-date" v-model="overrideForm.service_date" type="date" class="vg-input" data-testid="route-planner-override-date" />
          </div>
        </div>

        <template v-if="overrideIsSkip">
          <div style="margin-top: 12px;">
            <label class="vg-label" for="route-override-skip-reason">Causa de la omisión</label>
            <select id="route-override-skip-reason" v-model="overrideForm.skip_reason_code" class="vg-input" data-testid="route-planner-override-skip-reason">
              <option v-for="option in skipReasonOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </div>
          <div style="margin-top: 12px;">
            <label class="vg-label" for="route-override-reason">Motivo (20–500 caracteres reales, ≥ 3 bloques)</label>
            <textarea id="route-override-reason" v-model="overrideForm.reason_text" class="vg-input" rows="4" maxlength="500" data-testid="route-planner-override-reason"></textarea>
          </div>
        </template>

        <template v-else>
          <div style="margin-top: 12px;">
            <label class="vg-label" for="route-override-target">Reponedor receptor</label>
            <select id="route-override-target" v-model="overrideForm.target_restocker_id" class="vg-input" data-testid="route-planner-override-target">
              <option value="" disabled>{{ isLoadingPlannerOptions ? 'Cargando reponedores…' : 'Selecciona un reponedor…' }}</option>
              <option v-for="restocker in plannerRestockers" :key="restocker.id" :value="restocker.id">{{ restockerOptionLabel(restocker) }}</option>
            </select>
          </div>
          <div style="margin-top: 12px;">
            <label class="vg-label" for="route-override-reassign-reason">Causa del desvío</label>
            <select id="route-override-reassign-reason" v-model="overrideForm.reassignment_reason_code" class="vg-input" data-testid="route-planner-override-reassign-reason">
              <option v-for="option in reassignmentReasonOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </div>
          <div style="margin-top: 12px;">
            <label class="vg-label" for="route-override-note">Nota opcional (≤ 500 caracteres)</label>
            <textarea id="route-override-note" v-model="overrideForm.reason_text" class="vg-input" rows="3" maxlength="500" data-testid="route-planner-override-note"></textarea>
          </div>
        </template>

        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="showOverrideModal = false">Cancelar</button>
          <button type="button" class="vg-btn vg-btn-primary" :disabled="isSavingOverride" data-testid="route-planner-override-save" @click="saveOverride">
            {{ isSavingOverride ? 'Guardando…' : 'Registrar excepción' }}
          </button>
        </template>
      </ModalDialog>
    </div>
  `
};

export default CoordinatorRestockRoutePlannerSubTab;
