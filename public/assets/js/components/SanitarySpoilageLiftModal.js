/**
 * VendGuard - SanitarySpoilageLiftModal (SanitarySpoilageLiftModal.js)
 *
 * Acta de Merma y Destrucción de Lotes por Cuarentena Sanitaria (Módulo 29,
 * RF-01 a RF-04, D1-D3, C1-C3, QA 1-QA 4).
 *
 * Qué resuelve:
 * 1. El levantamiento es un acto explícito y en línea (D1, C3): sin conexión no
 *    se envía ni se difiere, porque la numeración oficial y el certificado
 *    higiénico exigen la central.
 * 2. La autoría física se conserva (QA 1): el técnico firma como certificador
 *    in situ y, cuando registra Coordinación, el selector de técnico ejecutor
 *    es obligatorio.
 * 3. El techo térmico se muestra en vivo según la tipología (QA 3, Art. II):
 *    ≤ 4,0 °C en perecederos y mixtas, ≤ 8,0 °C en bebidas frías y solo el
 *    rango físico en las no refrigeradas.
 * 4. La fotografía es obligatoria incluso con 0 unidades (D3, Art. V.5); con
 *    máquina vacía se exige la confirmación expresa y los lotes y el método
 *    viajan de oficio como SIN_STOCK_AFECTADO / NOT_APPLICABLE_EMPTY.
 * 5. Las observaciones llevan el aviso expreso de privacidad del Art. V.4 y
 *    nunca se piden importes: el coste lo calcula el servidor.
 *
 * Dogma Vanilla: Vue 3 ESM puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, interfaz y mensajes en español.
 */

import { api } from '../api.js';
import { ModalDialog } from './ModalDialog.js';

const MAX_PHOTO_BYTES = 5 * 1024 * 1024;
const ALLOWED_PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const ALLOWED_PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

const DISPOSAL_OPTIONS = [
  {
    value: 'ON_SITE_ORGANIC_SEALED',
    label: 'Destrucción e inutilización en contenedor sellado in situ'
  },
  {
    value: 'RETURN_TO_CENTRAL_BIOHAZARD',
    label: 'Retirada precintada a base central para baja sanitaria'
  },
  {
    value: 'AUTHORIZED_WASTE_MANAGER',
    label: 'Entrega a gestor autorizado de residuos orgánicos'
  }
];

export const SanitarySpoilageLiftModal = {
  name: 'SanitarySpoilageLiftModal',
  components: {
    ModalDialog
  },
  props: {
    modelValue: {
      type: Boolean,
      default: false
    },
    machine: {
      type: Object,
      default: null
    },
    incidentId: {
      type: [Number, String],
      default: null
    },
    userRole: {
      type: String,
      default: 'TECHNICIAN'
    },
    technicians: {
      type: Array,
      default: () => []
    }
  },
  emits: ['update:modelValue', 'close', 'lifted', 'open-official-act'],
  data() {
    return {
      cabinTempInput: '',
      restoredTempInput: '',
      discardedUnitsInput: '',
      emptyMachineConfirmed: false,
      batchCodes: '',
      disposalMethod: '',
      sanitaryNotes: '',
      executionTechnicianId: '',
      photoFile: null,
      photoName: '',
      photoError: '',
      errorMessage: '',
      isSubmitting: false,
      isLoadingContext: false,
      contextError: '',
      machineContext: null,
      disposalOptions: DISPOSAL_OPTIONS,
      availableTechnicians: [],
      technicianLoadError: '',
      liftedResult: null,
      isOnline: typeof navigator === 'undefined' ? true : navigator.onLine !== false
    };
  },
  computed: {
    isOpen: {
      get() {
        return this.modelValue;
      },
      set(value) {
        this.$emit('update:modelValue', value);
      }
    },
    isCoordinator() {
      return String(this.userRole || '').toUpperCase() === 'COORDINATOR';
    },
    machineId() {
      const id = Number(this.machine?.id ?? 0);
      return Number.isInteger(id) && id > 0 ? id : 0;
    },
    machineCode() {
      return String(this.machineContext?.machine_code || this.machine?.code || 'Máquina');
    },
    effectiveMachineType() {
      return String(
        this.machineContext?.machine_type
        || this.machine?.machine_type
        || this.machine?.machine_type_snapshot
        || ''
      ).toUpperCase();
    },
    typeLabel() {
      switch (this.effectiveMachineType) {
        case 'PERISHABLE_FOOD':
          return 'alimentos perecederos';
        case 'COMBO':
          return 'máquina mixta';
        case 'COLD_DRINKS':
          return 'bebidas frías';
        default:
          return 'esta tipología';
      }
    },
    temperatureCeiling() {
      if (this.effectiveMachineType === 'PERISHABLE_FOOD' || this.effectiveMachineType === 'COMBO') {
        return 4.0;
      }
      if (this.effectiveMachineType === 'COLD_DRINKS') {
        return 8.0;
      }
      return null;
    },
    ceilingLabel() {
      if (this.temperatureCeiling === null) {
        return 'Sin techo específico: ambas lecturas deben quedar dentro del rango físico de −5,0 °C a +35,0 °C.';
      }
      return `Techo sanitario de reapertura: ≤ ${this.formatDecimal(this.temperatureCeiling)} °C para ${this.typeLabel}.`;
    },
    cabinTemperature() {
      return this.parseDecimal(this.cabinTempInput);
    },
    restoredTemperature() {
      return this.parseDecimal(this.restoredTempInput);
    },
    discardedUnits() {
      const raw = String(this.discardedUnitsInput ?? '').trim();
      if (raw === '' || !/^\d+$/.test(raw)) {
        return null;
      }
      const value = Number.parseInt(raw, 10);
      return Number.isInteger(value) ? value : null;
    },
    isEmptyMachine() {
      return this.discardedUnits === 0;
    },
    hasUnits() {
      return this.discardedUnits !== null && this.discardedUnits > 0;
    },
    isUnitsValid() {
      return this.discardedUnits !== null
        && this.discardedUnits >= 0
        && this.discardedUnits <= 500;
    },
    isCabinValid() {
      return this.cabinTemperature !== null
        && this.cabinTemperature >= -5.0
        && this.cabinTemperature <= 35.0;
    },
    isRestoredWithinPhysicalRange() {
      return this.restoredTemperature !== null
        && this.restoredTemperature >= -5.0
        && this.restoredTemperature <= 35.0;
    },
    isRestoredWithinCeiling() {
      if (!this.isRestoredWithinPhysicalRange) {
        return false;
      }
      if (this.temperatureCeiling === null) {
        return true;
      }
      return this.restoredTemperature <= this.temperatureCeiling;
    },
    isRestoredValid() {
      return this.isRestoredWithinPhysicalRange && this.isRestoredWithinCeiling;
    },
    normalizedBatchCodes() {
      return String(this.batchCodes || '').trim().replace(/\s+/g, ' ');
    },
    batchCodesLength() {
      return [...this.normalizedBatchCodes].length;
    },
    isBatchValid() {
      return this.batchCodesLength >= 3
        && this.batchCodesLength <= 500
        && /[\p{L}\p{N}]{2,}/u.test(this.normalizedBatchCodes);
    },
    normalizedNotes() {
      return String(this.sanitaryNotes || '').trim().replace(/\s+/g, ' ');
    },
    notesLength() {
      return [...this.normalizedNotes].length;
    },
    notesBlockCount() {
      const blocks = this.normalizedNotes.match(/[\p{L}\p{N}]+/gu);
      return Array.isArray(blocks) ? blocks.length : 0;
    },
    isNotesValid() {
      return this.notesLength >= 20
        && this.notesLength <= 1000
        && this.notesBlockCount >= 3;
    },
    isExecutionTechnicianValid() {
      if (!this.isCoordinator) {
        return true;
      }
      const id = Number(this.executionTechnicianId);
      return Number.isInteger(id) && id > 0;
    },
    isQuarantine() {
      return this.machineContext?.is_in_quarantine === true;
    },
    hasOpenIncidents() {
      return this.machineContext?.has_open_incidents === true;
    },
    contextRecords() {
      return Array.isArray(this.machineContext?.records) ? this.machineContext.records : [];
    },
    canSubmit() {
      return this.isOnline
        && !this.isSubmitting
        && !this.isLoadingContext
        && this.contextError === ''
        && this.machineContext !== null
        && this.isQuarantine
        && !this.hasOpenIncidents
        && this.isCabinValid
        && this.isRestoredValid
        && this.isUnitsValid
        && (this.isEmptyMachine
          ? this.emptyMachineConfirmed
          : this.isBatchValid && this.disposalMethod !== '')
        && Boolean(this.photoFile)
        && this.isNotesValid
        && this.isExecutionTechnicianValid
        && this.machineId > 0;
    },
    modalSubtitle() {
      return `${this.machineCode}${this.machine?.model ? ` · ${this.machine.model}` : ''}`;
    },
    liftedRecordId() {
      const id = Number(this.liftedResult?.spoilage_record?.id ?? 0);
      return Number.isInteger(id) && id > 0 ? id : null;
    }
  },
  watch: {
    modelValue(open) {
      if (open) {
        this.resetForm();
        this.loadMachineContext();
        this.loadTechnicianOptions();
      }
    }
  },
  mounted() {
    if (typeof window !== 'undefined') {
      window.addEventListener('online', this.syncConnectivity);
      window.addEventListener('offline', this.syncConnectivity);
    }
  },
  beforeUnmount() {
    if (typeof window !== 'undefined') {
      window.removeEventListener('online', this.syncConnectivity);
      window.removeEventListener('offline', this.syncConnectivity);
    }
  },
  methods: {
    close() {
      if (this.isSubmitting) {
        return;
      }
      this.$emit('update:modelValue', false);
      this.$emit('close');
    },

    syncConnectivity() {
      this.isOnline = typeof navigator === 'undefined' ? true : navigator.onLine !== false;
    },

    resetForm() {
      this.cabinTempInput = '';
      this.restoredTempInput = '';
      this.discardedUnitsInput = '';
      this.emptyMachineConfirmed = false;
      this.batchCodes = '';
      this.disposalMethod = '';
      this.sanitaryNotes = '';
      this.executionTechnicianId = '';
      this.photoFile = null;
      this.photoName = '';
      this.photoError = '';
      this.errorMessage = '';
      this.isSubmitting = false;
      this.liftedResult = null;
      this.machineContext = null;
      this.contextError = '';
      this.technicianLoadError = '';
      this.syncConnectivity();
    },

    /**
     * Lee el estado sanitario real de la máquina y sus actas previas.
     * El historial también alimenta el acceso al Acta Oficial A4 (QA 3).
     */
    async loadMachineContext() {
      if (this.machineId <= 0) {
        this.contextError = 'No se pudo identificar la máquina de la parada.';
        return;
      }
      if (!this.isOnline) {
        this.contextError = 'Sin conexión no es posible verificar el estado sanitario ni emitir el acta oficial (C3).';
        return;
      }

      this.isLoadingContext = true;
      this.contextError = '';

      try {
        const response = await api.technician.getMachineSanitarySpoilageRecords(this.machineId);
        const data = response?.data || response || {};
        this.machineContext = {
          machine_code: String(data.machine_code || this.machine?.code || ''),
          machine_type: String(data.machine_type || ''),
          is_in_quarantine: data.is_in_quarantine === true,
          has_open_incidents: data.has_open_incidents === true,
          records: Array.isArray(data.records) ? data.records : []
        };
      } catch (error) {
        this.machineContext = null;
        this.contextError = error?.message || 'No se pudo verificar el estado sanitario de la máquina.';
      } finally {
        this.isLoadingContext = false;
      }
    },

    /**
     * QA 1: Coordinación debe indicar quién ejecutó la retirada in situ. La
     * vista de técnico no necesita esta lista; el panel de Coordinación puede
     * pasarla ya cargada y, si no, se solicita al servidor.
     */
    async loadTechnicianOptions() {
      if (!this.isCoordinator) {
        return;
      }
      if (Array.isArray(this.technicians) && this.technicians.length > 0) {
        this.availableTechnicians = this.technicians;
        return;
      }

      this.technicianLoadError = '';
      try {
        const response = await api.coordinator.getUsers({ role: 'TECHNICIAN' });
        const payload = response?.data ?? response ?? {};
        const rows = Array.isArray(payload)
          ? payload
          : (payload.users || payload.items || []);
        this.availableTechnicians = (Array.isArray(rows) ? rows : []).filter((user) => {
          const role = String(user?.role || '').toUpperCase();
          return role === 'TECHNICIAN' && user?.is_active !== false;
        });
      } catch (error) {
        this.technicianLoadError = error?.message || 'No se pudo cargar la lista de técnicos activos.';
      }
    },

    parseDecimal(rawValue) {
      if (rawValue === null || rawValue === undefined || rawValue === '') {
        return null;
      }
      const value = Number.parseFloat(String(rawValue).replace(',', '.'));
      return Number.isFinite(value) ? value : null;
    },

    formatDecimal(value) {
      return Number(value).toFixed(1).replace('.', ',');
    },

    formatDate(value) {
      if (!value) {
        return '—';
      }
      const parsed = new Date(String(value).replace(' ', 'T'));
      if (Number.isNaN(parsed.getTime())) {
        return String(value);
      }
      return parsed.toLocaleDateString('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
      });
    },

    handlePhotoChange(event) {
      this.photoError = '';
      const file = event?.target?.files?.[0] || null;
      if (!file) {
        this.photoFile = null;
        this.photoName = '';
        return;
      }

      const extension = String(file.name || '').split('.').pop().toLowerCase();
      const hasValidType = file.type === '' || ALLOWED_PHOTO_TYPES.includes(file.type);
      const hasValidExtension = ALLOWED_PHOTO_EXTENSIONS.includes(extension);

      if (!hasValidType || !hasValidExtension) {
        this.photoFile = null;
        this.photoName = '';
        this.photoError = 'La evidencia debe ser una imagen JPG, PNG o WebP verificable.';
        if (event?.target) {
          event.target.value = '';
        }
        return;
      }

      if (file.size > MAX_PHOTO_BYTES) {
        this.photoFile = null;
        this.photoName = '';
        this.photoError = 'La fotografía supera el límite de 5 MB (Art. V.5).';
        if (event?.target) {
          event.target.value = '';
        }
        return;
      }

      this.photoFile = file;
      this.photoName = file.name;
    },

    emitOpenOfficialAct(recordId) {
      const id = Number(recordId);
      if (Number.isInteger(id) && id > 0) {
        this.$emit('open-official-act', id);
      }
    },

    validateBeforeSubmit() {
      if (!this.isOnline) {
        return 'La reapertura sanitaria exige conexión activa con la central (C3).';
      }
      if (this.isLoadingContext) {
        return 'Espera a que termine la verificación del estado sanitario.';
      }
      if (this.contextError) {
        return this.contextError;
      }
      if (!this.machineContext) {
        return 'No se pudo verificar el estado sanitario de la máquina.';
      }
      if (!this.isQuarantine) {
        return 'La máquina no se encuentra actualmente en cuarentena sanitaria.';
      }
      if (this.hasOpenIncidents) {
        return 'La máquina conserva una avería correctiva abierta; debe resolverse antes de certificar la reapertura sanitaria (C1).';
      }
      if (!this.isCabinValid) {
        return 'La temperatura de retirada debe estar dentro del rango físico de −5,0 °C a +35,0 °C.';
      }
      if (!this.isRestoredWithinPhysicalRange) {
        return 'La temperatura estabilizada debe estar dentro del rango físico de −5,0 °C a +35,0 °C.';
      }
      if (!this.isRestoredWithinCeiling) {
        return `La temperatura estabilizada supera el techo sanitario de ${this.formatDecimal(this.temperatureCeiling)} °C para ${this.typeLabel} (Art. II).`;
      }
      if (!this.isUnitsValid) {
        return 'Las unidades destruidas deben ser un número entero entre 0 y 500.';
      }
      if (this.isEmptyMachine && !this.emptyMachineConfirmed) {
        return 'Confirma expresamente que la máquina no contenía producto perecedero expuesto.';
      }
      if (this.hasUnits && !this.isBatchValid) {
        return 'Los códigos de lote deben tener entre 3 y 500 caracteres e incluir un bloque alfanumérico real (QA 4).';
      }
      if (this.hasUnits && this.disposalMethod === '') {
        return 'Selecciona el método de destrucción aplicado.';
      }
      if (!this.photoFile) {
        return 'La fotografía de evidencia es obligatoria (D3, Art. V.5).';
      }
      if (!this.isNotesValid) {
        return 'Las observaciones deben tener entre 20 y 1000 caracteres y al menos 3 palabras reales (Art. V.1).';
      }
      if (!this.isExecutionTechnicianValid) {
        return 'Selecciona el técnico activo que ejecutó físicamente la retirada in situ (QA 1).';
      }
      return '';
    },

    async submit() {
      this.errorMessage = '';
      const validationError = this.validateBeforeSubmit();
      if (validationError !== '') {
        this.errorMessage = validationError;
        return;
      }

      this.isSubmitting = true;

      try {
        const payload = {
          incident_id: this.incidentId !== null && this.incidentId !== '' ? Number(this.incidentId) : null,
          maintenance_order_id: null,
          discarded_units_count: this.discardedUnits,
          empty_machine_confirmed: this.isEmptyMachine,
          affected_batch_codes: this.isEmptyMachine ? 'SIN_STOCK_AFECTADO' : this.normalizedBatchCodes,
          cabin_temp_at_removal: this.cabinTemperature,
          restored_stabilized_temp_c: this.restoredTemperature,
          disposal_method: this.isEmptyMachine ? 'NOT_APPLICABLE_EMPTY' : this.disposalMethod,
          sanitary_notes: this.normalizedNotes
        };

        if (this.isCoordinator) {
          payload.technician_id = Number(this.executionTechnicianId);
        }

        const response = await api.technician.liftSanitaryQuarantineWithSpoilage(
          this.machineId,
          payload,
          this.photoFile,
          this.photoFile?.name || null
        );
        const result = response?.data || response || {};
        this.liftedResult = result;
        this.$emit('lifted', result);
      } catch (error) {
        this.errorMessage = error?.message || 'No se pudo registrar el acta de merma y levantar la cuarentena.';
      } finally {
        this.isSubmitting = false;
      }
    }
  },
  template: `
    <ModalDialog
      v-model="isOpen"
      title="Acta de Merma APPCC"
      :subtitle="modalSubtitle"
      size="lg"
      :close-on-backdrop="!isSubmitting"
      :show-close-button="!isSubmitting"
      @close="close"
    >
      <div data-testid="spoilage-lift-modal">
        <div
          v-if="!isOnline"
          role="alert"
          style="background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); padding: 10px 12px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 13px; margin-bottom: 14px; line-height: 1.45;"
        >
          ⛔ Sin conexión: la reapertura sanitaria y la emisión del acta oficial exigen conexión activa con la central (C3).
        </div>

        <!-- Resultado: el acta ya está emitida -->
        <div v-if="liftedResult" data-testid="spoilage-lift-success" style="display: flex; flex-direction: column; gap: 12px;">
          <div style="background-color: var(--color-success-bg); border: 1px solid var(--color-success); color: var(--color-success-text); padding: 14px; border-radius: var(--radius-card); font-family: var(--font-body); font-size: 13px; line-height: 1.5;">
            <div style="font-family: var(--font-display); font-size: 16px; font-weight: 600; margin-bottom: 6px;">
              ✅ Cuarentena levantada y acta emitida
            </div>
            <div>
              Acta oficial: <strong>{{ liftedResult.spoilage_record?.record_code || '—' }}</strong>
            </div>
            <div>
              Certificado higiénico: <strong>{{ liftedResult.certificate?.certificate_code || liftedResult.spoilage_record?.certificate_code || '—' }}</strong>
            </div>
            <div>
              La máquina recupera el estado sanitario <strong>OK</strong> y el código QR vuelve a estar operativo.
            </div>
          </div>
          <div style="font-family: var(--font-body); font-size: 13px; color: var(--color-ink-muted);">
            La nueva acta ya está disponible en el historial de la máquina y en el registro APPCC de Coordinación.
          </div>
        </div>

        <template v-else>
          <div v-if="isLoadingContext" role="status" style="font-family: var(--font-body); font-size: 13px; color: var(--color-ink-muted); padding: 12px 0;">
            ⏳ Verificando el estado sanitario de la máquina...
          </div>

          <div
            v-else-if="contextError"
            role="alert"
            style="background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); padding: 10px 12px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 13px; margin-bottom: 14px; line-height: 1.45;"
          >
            {{ contextError }}
            <div style="margin-top: 10px;">
              <button type="button" class="vg-btn vg-btn-secondary vg-btn-sm" @click="loadMachineContext">Reintentar</button>
            </div>
          </div>

          <div
            v-else-if="hasOpenIncidents"
            role="alert"
            data-testid="spoilage-open-incident"
            style="background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); padding: 10px 12px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 13px; margin-bottom: 14px; line-height: 1.45;"
          >
            ⚠️ La máquina conserva una avería correctiva abierta. Debe resolverse antes de certificar la reapertura sanitaria (C1).
          </div>

          <div
            v-else-if="machineContext && !isQuarantine"
            role="alert"
            data-testid="spoilage-not-quarantine"
            style="background-color: var(--color-info-bg); border: 1px solid var(--color-info-border); color: var(--color-info-text); padding: 10px 12px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 13px; margin-bottom: 14px; line-height: 1.45;"
          >
            ℹ️ La máquina no está actualmente en cuarentena sanitaria, por lo que no procede emitir una nueva acta. Puedes consultar las actas anteriores en el historial.
          </div>

          <form v-if="machineContext" @submit.prevent="submit">
            <!-- QA 1: autoría física del técnico ejecutor -->
            <div v-if="isCoordinator" style="margin-bottom: 14px;">
              <label for="spoilage-technician" style="display: block; font-family: var(--font-body); font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;">
                Técnico ejecutor in situ <span style="color: var(--color-error-text);">*</span>
              </label>
              <select id="spoilage-technician" v-model="executionTechnicianId" class="vg-select" :disabled="isSubmitting" style="min-height: 44px;">
                <option value="">Selecciona el técnico que realizó la retirada…</option>
                <option v-for="technician in availableTechnicians" :key="technician.id" :value="String(technician.id)">
                  {{ technician.name || technician.email || ('Técnico #' + technician.id) }}
                </option>
              </select>
              <div v-if="technicianLoadError" role="alert" style="font-family: var(--font-body); font-size: 12px; color: var(--color-error-text); margin-top: 4px;">
                {{ technicianLoadError }}
              </div>
            </div>

            <!-- Lecturas térmicas -->
            <div style="background-color: var(--color-surface-1); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 12px; margin-bottom: 14px;">
              <div style="font-family: var(--font-display); font-size: 15px; font-weight: 600; color: var(--color-slate); margin-bottom: 4px;">
                Verificación térmica
              </div>
              <div style="font-family: var(--font-body); font-size: 12px; color: var(--color-ink-muted); margin-bottom: 10px; line-height: 1.45;">
                {{ ceilingLabel }}
              </div>
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <div>
                  <label for="spoilage-cabin-temp" style="display: block; font-family: var(--font-body); font-size: 12px; font-weight: 600; color: var(--color-slate); margin-bottom: 4px;">
                    Temperatura de cabina al retirar (°C) <span style="color: var(--color-error-text);">*</span>
                  </label>
                  <input
                    id="spoilage-cabin-temp"
                    v-model="cabinTempInput"
                    class="vg-input"
                    type="number"
                    step="0.1"
                    min="-5"
                    max="35"
                    inputmode="decimal"
                    :disabled="isSubmitting"
                    placeholder="Ej: 11,4"
                    style="min-height: 44px;"
                    data-testid="spoilage-cabin-temp"
                  />
                </div>
                <div>
                  <label for="spoilage-restored-temp" style="display: block; font-family: var(--font-body); font-size: 12px; font-weight: 600; color: var(--color-slate); margin-bottom: 4px;">
                    Temperatura estabilizada de reapertura (°C) <span style="color: var(--color-error-text);">*</span>
                  </label>
                  <input
                    id="spoilage-restored-temp"
                    v-model="restoredTempInput"
                    class="vg-input"
                    type="number"
                    step="0.1"
                    min="-5"
                    max="35"
                    inputmode="decimal"
                    :disabled="isSubmitting"
                    placeholder="Ej: 3,2"
                    style="min-height: 44px;"
                    data-testid="spoilage-restored-temp"
                  />
                </div>
              </div>
              <div
                v-if="restoredTemperature !== null && !isRestoredWithinPhysicalRange"
                role="alert"
                style="margin-top: 8px; font-family: var(--font-body); font-size: 12px; color: var(--color-error-text);"
              >
                La lectura debe quedar entre −5,0 °C y +35,0 °C.
              </div>
              <div
                v-else-if="restoredTemperature !== null && !isRestoredWithinCeiling"
                role="alert"
                data-testid="spoilage-ceiling-alert"
                style="margin-top: 8px; font-family: var(--font-body); font-size: 12px; color: var(--color-error-text);"
              >
                {{ formatDecimal(restoredTemperature) }} °C supera el techo de {{ formatDecimal(temperatureCeiling) }} °C. No se puede levantar la cuarentena (Art. II).
              </div>
              <div
                v-else-if="restoredTemperature !== null && isRestoredValid"
                style="margin-top: 8px; font-family: var(--font-body); font-size: 12px; color: var(--color-success-text);"
              >
                ✅ Lectura conforme para la reapertura sanitaria.
              </div>
            </div>

            <!-- Unidades, lotes y destrucción -->
            <div style="background-color: var(--color-surface-1); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 12px; margin-bottom: 14px;">
              <div style="font-family: var(--font-display); font-size: 15px; font-weight: 600; color: var(--color-slate); margin-bottom: 10px;">
                Producto destruido
              </div>

              <div style="margin-bottom: 10px;">
                <label for="spoilage-units" style="display: block; font-family: var(--font-body); font-size: 12px; font-weight: 600; color: var(--color-slate); margin-bottom: 4px;">
                  Unidades perecederas destruidas (0 a 500) <span style="color: var(--color-error-text);">*</span>
                </label>
                <input
                  id="spoilage-units"
                  v-model="discardedUnitsInput"
                  class="vg-input"
                  type="number"
                  step="1"
                  min="0"
                  max="500"
                  inputmode="numeric"
                  :disabled="isSubmitting"
                  placeholder="Ej: 14"
                  style="min-height: 44px;"
                  data-testid="spoilage-units"
                />
              </div>

              <template v-if="isEmptyMachine">
                <label style="display: flex; align-items: flex-start; gap: 10px; min-height: 44px; cursor: pointer; font-family: var(--font-body); font-size: 13px; color: var(--color-slate); line-height: 1.45;">
                  <input type="checkbox" v-model="emptyMachineConfirmed" :disabled="isSubmitting" style="width: 20px; height: 20px; margin-top: 2px; flex: 0 0 auto;" data-testid="spoilage-empty-confirmed" />
                  <span>Confirmo que la máquina no contenía producto perecedero expuesto.</span>
                </label>
                <div style="font-family: var(--font-body); font-size: 12px; color: var(--color-ink-muted); line-height: 1.45;">
                  Los lotes se registrarán de oficio como «Sin stock afectado» y el método como «No aplicable (máquina vacía)».
                </div>
              </template>

              <template v-else-if="hasUnits">
                <div style="margin-bottom: 10px;">
                  <label for="spoilage-batches" style="display: block; font-family: var(--font-body); font-size: 12px; font-weight: 600; color: var(--color-slate); margin-bottom: 4px;">
                    Códigos de lote afectados (3 a 500 caracteres) <span style="color: var(--color-error-text);">*</span>
                  </label>
                  <input
                    id="spoilage-batches"
                    v-model="batchCodes"
                    class="vg-input"
                    type="text"
                    maxlength="500"
                    :disabled="isSubmitting"
                    placeholder="Ej: LOTE-2026-11A (8 uds), LOTE-2026-11B (6 uds)"
                    style="min-height: 44px;"
                    data-testid="spoilage-batches"
                  />
                  <div v-if="normalizedBatchCodes !== '' && !isBatchValid" style="font-family: var(--font-body); font-size: 12px; color: var(--color-error-text); margin-top: 4px;">
                    Incluye al menos un bloque alfanumérico real de dos o más caracteres.
                  </div>
                </div>

                <div>
                  <label for="spoilage-disposal" style="display: block; font-family: var(--font-body); font-size: 12px; font-weight: 600; color: var(--color-slate); margin-bottom: 4px;">
                    Método de destrucción <span style="color: var(--color-error-text);">*</span>
                  </label>
                  <select id="spoilage-disposal" v-model="disposalMethod" class="vg-select" :disabled="isSubmitting" style="min-height: 44px;" data-testid="spoilage-disposal">
                    <option value="">Selecciona el método aplicado…</option>
                    <option v-for="option in disposalOptions" :key="option.value" :value="option.value">
                      {{ option.label }}
                    </option>
                  </select>
                </div>
              </template>
            </div>

            <!-- Evidencia fotográfica -->
            <div style="margin-bottom: 14px;">
              <label for="spoilage-photo" style="display: block; font-family: var(--font-body); font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;">
                Fotografía de evidencia (JPG, PNG o WebP · máx. 5 MB) <span style="color: var(--color-error-text);">*</span>
              </label>
              <input
                id="spoilage-photo"
                type="file"
                accept=".jpg,.jpeg,.png,.webp"
                :disabled="isSubmitting"
                style="min-height: 44px; width: 100%; font-family: var(--font-body); font-size: 13px;"
                data-testid="spoilage-photo"
                @change="handlePhotoChange"
              />
              <div v-if="photoName" style="font-family: var(--font-body); font-size: 12px; color: var(--color-ink-muted); margin-top: 4px;">
                Adjunta: {{ photoName }}
              </div>
              <div v-if="photoError" role="alert" style="font-family: var(--font-body); font-size: 12px; color: var(--color-error-text); margin-top: 4px;">
                {{ photoError }}
              </div>
              <div style="font-family: var(--font-body); font-size: 12px; color: var(--color-ink-muted); margin-top: 4px; line-height: 1.45;">
                Obligatoria incluso con 0 unidades: muestra los lotes inutilizados o el interior vacío de la cabina (D3, Art. V.5).
              </div>
            </div>

            <!-- Observaciones de higienización -->
            <div style="margin-bottom: 14px;">
              <label for="spoilage-notes" style="display: block; font-family: var(--font-body); font-size: 13px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;">
                Observaciones de higienización (20 a 1000 caracteres) <span style="color: var(--color-error-text);">*</span>
              </label>
              <textarea
                id="spoilage-notes"
                v-model="sanitaryNotes"
                class="vg-textarea"
                rows="3"
                maxlength="1000"
                :disabled="isSubmitting"
                placeholder="Describe la retirada, la inutilización y la higienización efectuadas in situ…"
                data-testid="spoilage-notes"
              ></textarea>
              <div style="display: flex; justify-content: space-between; gap: 8px; font-family: var(--font-body); font-size: 12px; color: var(--color-ink-muted); margin-top: 4px;">
                <span>{{ notesLength }} / 1000 caracteres · {{ notesBlockCount }} palabras</span>
                <span v-if="notesLength > 0 && !isNotesValid" style="color: var(--color-error-text);">Mínimo 20 caracteres y 3 palabras reales</span>
              </div>
              <div
                data-testid="spoilage-privacy-notice"
                style="background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); border-radius: var(--radius-interactive); padding: 8px 10px; font-family: var(--font-body); font-size: 12px; line-height: 1.45; margin-top: 8px;"
              >
                <strong>Aviso de privacidad:</strong> este texto se imprimirá en el Acta Oficial A4 para Sanidad. No incluya importes económicos, teléfonos personales ni notas internas de taller.
              </div>
            </div>

            <!-- Historial de actas de la máquina (QA 3) -->
            <div v-if="contextRecords.length > 0" style="margin-bottom: 14px;">
              <div style="font-family: var(--font-display); font-size: 15px; font-weight: 600; color: var(--color-slate); margin-bottom: 6px;">
                Actas emitidas de esta máquina
              </div>
              <div style="display: flex; flex-direction: column; gap: 6px;">
                <div
                  v-for="record in contextRecords"
                  :key="record.id"
                  style="display: flex; align-items: center; justify-content: space-between; gap: 8px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); padding: 8px 10px;"
                >
                  <div style="font-family: var(--font-body); font-size: 12px; color: var(--color-slate); line-height: 1.4;">
                    <strong>{{ record.record_code }}</strong>
                    <div style="color: var(--color-ink-muted);">
                      {{ formatDate(record.created_at) }} · {{ record.discarded_units_count }} uds. · {{ record.certificate_code }}
                    </div>
                  </div>
                  <button type="button" class="vg-btn vg-btn-secondary vg-btn-sm" :disabled="isSubmitting" @click="emitOpenOfficialAct(record.id)">
                    Acta A4
                  </button>
                </div>
              </div>
            </div>

            <div
              v-if="errorMessage"
              role="alert"
              data-testid="spoilage-error-msg"
              style="background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); padding: 10px 12px; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 13px; margin-bottom: 14px; line-height: 1.45;"
            >
              ⚠️ {{ errorMessage }}
            </div>
          </form>
        </template>
      </div>

      <template #footer>
        <template v-if="liftedResult">
          <button v-if="liftedRecordId" type="button" class="vg-btn vg-btn-secondary" @click="emitOpenOfficialAct(liftedRecordId)">
            📄 Ver Acta Oficial A4
          </button>
          <button type="button" class="vg-btn vg-btn-primary" @click="close">Cerrar</button>
        </template>
        <template v-else>
          <button type="button" class="vg-btn vg-btn-secondary" :disabled="isSubmitting" @click="close">Cancelar</button>
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            :disabled="!canSubmit"
            data-testid="spoilage-submit"
            @click="submit"
          >
            {{ isSubmitting ? 'Registrando acta…' : 'Registrar Acta y Levantar Cuarentena' }}
          </button>
        </template>
      </template>
    </ModalDialog>
  `
};

export default SanitarySpoilageLiftModal;
