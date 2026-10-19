/**
 * VendGuard - TechnicianOpportunisticModal Component (TechnicianOpportunisticModal.js)
 *
 * Intervención oportunista in situ para el Técnico de Campo (Módulo 16, RF-01 a RF-05).
 *
 * El componente resuelve dos flujos sobre el catálogo de máquinas de una sede
 * habilitada:
 *
 * - Flujo A (`CREATE_NEW`): alta de una avería nueva nacida en el acto, ya en curso y
 *   asignada al técnico presente (RF-04).
 * - Flujo B (`ASSUME_DIRECT`, `ASSUME_WITH_CONFIRMATION`, `REOPEN_IN_SITU`): asunción
 *   de un expediente abierto, traspaso desde otro compañero o reapertura en garantía
 *   (RF-05), sin crear nunca un ticket duplicado (Art. V.2).
 *
 * Reglas de interfaz que no se negocian:
 *
 * - **Tres interacciones (RNF-02):** abrir el catálogo, elegir la máquina y confirmar.
 *   La categoría y el motivo de origen llegan con un valor por defecto razonable para
 *   que el alta express no obligue a recorrer el formulario entero.
 * - **Advertencia sanitaria (RF-03.1, Art. II):** si el backend declara paradas
 *   críticas o de cadena de frío en otra sede, la confirmación es expresa y bloquea el
 *   envío hasta que el técnico la marque.
 * - **Privacidad de la reclamación de monedas (RF-04.3, Art. V.4):** con
 *   `PAYMENT_SYSTEM` se recuerda invitar al usuario a escanear el QR exterior. El
 *   formulario **jamás** pide IBAN, teléfono ni datos de reembolso.
 * - **Nota de traspaso interna (RF-05.3, Art. V.4):** el campo solo viaja como
 *   `takeover_internal_note` y el servidor lo persiste como nota interna, invisible
 *   para la Sede y para el Ciudadano QR.
 *
 * Dogma Vanilla: componente Vue 3 con plantilla en cadena, sin dependencias nuevas ni
 * paso de compilación. Los colores entran siempre por token de diseño.
 */

import { api, ApiError } from '../api.js';
import { ModalDialog } from './ModalDialog.js';
import { IncidentBadge } from './IncidentBadge.js';
import { INCIDENT_CATEGORIES } from '../utils/IncidentStatusPermissions.js';

/** Límite constitucional de la fotografía adjunta (Art. V.5). */
const MAX_PHOTO_BYTES = 5 * 1024 * 1024;

/** Extensiones gráficas seguras admitidas por el Art. V.5. */
const SAFE_PHOTO_EXTENSIONS = Object.freeze(['jpg', 'jpeg', 'png', 'webp']);

/** Longitud mínima del síntoma descrito (RF-04.1.3) y del motivo de reincidencia (RF-05.4). */
const MIN_TEXT_LENGTH = 10;

/** Motivos de origen del alta in situ (RF-04.1.2). */
const ORIGIN_REASONS = Object.freeze([
  Object.freeze({ value: 'SITE_VERBAL_NOTICE', label: 'Aviso verbal en sede', hint: 'Alguien del edificio te lo ha comunicado en persona.' }),
  Object.freeze({ value: 'TECHNICIAN_DETECTION', label: 'Detección propia del técnico', hint: 'Lo has detectado tú durante la visita.' })
]);

/** Etiqueta corta de la acción disponible en cada máquina del catálogo. */
const ACTION_LABELS = Object.freeze({
  CREATE_NEW: 'Alta in situ',
  ASSUME_DIRECT: 'Asumir e iniciar',
  ASSUME_WITH_CONFIRMATION: 'Traspaso · confirmar',
  REOPEN_IN_SITU: 'Reabrir en garantía',
  CURRENT_TECHNICIAN_IN_PROGRESS: 'Ya en curso por ti',
  BLOCKED_OTHER_TECH_IN_PROGRESS: 'Compañero interviniendo'
});

/** Modo de formulario que corresponde a cada acción del catálogo. */
const MODE_BY_ACTION = Object.freeze({
  CREATE_NEW: 'CREATE_NEW',
  ASSUME_DIRECT: 'ASSUME',
  ASSUME_WITH_CONFIRMATION: 'ASSUME',
  REOPEN_IN_SITU: 'REOPEN',
  CURRENT_TECHNICIAN_IN_PROGRESS: 'CURRENT',
  BLOCKED_OTHER_TECH_IN_PROGRESS: 'BLOCKED'
});

export const TechnicianOpportunisticModal = {
  name: 'TechnicianOpportunisticModal',
  components: {
    ModalDialog,
    IncidentBadge
  },
  props: {
    /** v-model estándar de la casa. */
    modelValue: {
      type: Boolean,
      default: false
    },
    /**
     * Alias de apertura declarado por el plan (`:is-open`). Se admite además de
     * `modelValue` para que la vista de ruta pueda montarlo con cualquiera de los dos
     * contratos sin envoltorios artificiales.
     */
    isOpen: {
      type: Boolean,
      default: false
    },
    /** Sede habilitada sobre la que se consulta el parque. */
    locationId: {
      type: [Number, String],
      default: null
    },
    /** Nombre de la sede para la cabecera (el backend lo reafirma al cargar). */
    locationName: {
      type: String,
      default: ''
    }
  },
  emits: ['update:modelValue', 'update:isOpen', 'close', 'created', 'assumed'],
  data() {
    return {
      // Catálogo y avisos del servidor
      loading: false,
      catalogLoaded: false,
      catalogError: '',
      machines: [],
      location: null,
      activeInterventionConflict: null,
      coldChainWarning: null,

      // Selección y formulario
      selectedMachineId: null,
      mode: '',
      category: 'PRODUCT_JAM',
      originReason: 'SITE_VERBAL_NOTICE',
      informantName: '',
      description: '',
      photoFile: null,
      photoError: '',
      confirmColdChainOverride: false,
      takeoverConfirmed: false,
      takeoverNote: '',
      reopenReason: '',

      // Estado de la petición y mensajes
      submitting: false,
      errorMessage: '',
      noticeMessage: '',

      // Margen de tolerancia del formulario abierto (RF-01.3)
      formOpenedAt: null,
      formOpenedWithinGrace: false
    };
  },
  computed: {
    /** Apertura efectiva: `v-model` o el alias `:is-open`. */
    dialogOpen() {
      return this.modelValue === true || this.isOpen === true;
    },
    displayLocationName() {
      return this.location?.name || this.locationName || 'la sede actual';
    },
    categories() {
      return INCIDENT_CATEGORIES;
    },
    originReasons() {
      return ORIGIN_REASONS;
    },
    selectedMachine() {
      return this.machines.find((machine) => Number(machine.id) === Number(this.selectedMachineId)) || null;
    },
    selectedAction() {
      return this.selectedMachine?.opportunistic_action || '';
    },
    activeIncident() {
      return this.selectedMachine?.active_incident || null;
    },
    isCreateMode() {
      return this.mode === 'CREATE_NEW';
    },
    isAssumeMode() {
      return this.mode === 'ASSUME';
    },
    isReopenMode() {
      return this.mode === 'REOPEN';
    },
    isBlockedByOtherTechnician() {
      return this.mode === 'BLOCKED';
    },
    isCurrentTechnicianInProgress() {
      return this.mode === 'CURRENT';
    },
    requiresTakeoverConfirmation() {
      return this.isAssumeMode && this.selectedAction === 'ASSUME_WITH_CONFIRMATION';
    },
    requiresColdChainConfirmation() {
      return this.coldChainWarning?.requires_confirmation === true;
    },
    coldChainStops() {
      return Array.isArray(this.coldChainWarning?.pending_critical_stops)
        ? this.coldChainWarning.pending_critical_stops
        : [];
    },
    isPaymentSystemCategory() {
      return this.category === 'PAYMENT_SYSTEM';
    },
    /**
     * 3.ª reincidencia: el servidor creará un expediente sucesor limpio y cerrará el
     * anterior como Avería Crónica (RF-05.4.2). La interfaz lo advierte antes de enviar.
     */
    willTriggerChronicReplacement() {
      if (!this.isReopenMode) {
        return false;
      }

      return this.activeIncident?.will_trigger_chronic_replacement === true
        || Number(this.activeIncident?.reopen_count || 0) >= 2;
    },
    modeTitle() {
      switch (this.mode) {
        case 'CREATE_NEW': return 'Alta oportunista in situ';
        case 'ASSUME': return 'Asunción oportunista in situ';
        case 'REOPEN': return 'Reapertura en garantía';
        case 'CURRENT': return 'Intervención ya en curso';
        case 'BLOCKED': return 'Máquina ocupada por un compañero';
        default: return 'Otra máquina en esta sede';
      }
    },
    submitLabel() {
      if (this.submitting) {
        return 'Enviando…';
      }
      switch (this.mode) {
        case 'CREATE_NEW': return 'Crear e iniciar';
        case 'ASSUME': return 'Asumir e iniciar ahora';
        case 'REOPEN': return this.willTriggerChronicReplacement ? 'Crear expediente sucesor' : 'Reabrir e iniciar';
        default: return 'Confirmar';
      }
    },
    canSubmit() {
      if (this.submitting || !this.selectedMachine) {
        return false;
      }
      if (this.mode === 'CREATE_NEW' || this.mode === 'ASSUME' || this.mode === 'REOPEN') {
        return this.requiresColdChainConfirmation ? this.confirmColdChainOverride === true : true;
      }

      return false;
    },
    formOpenedAtLabel() {
      if (!this.formOpenedAt) {
        return '';
      }

      try {
        return new Date(this.formOpenedAt).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
      } catch (error) {
        return '';
      }
    }
  },
  watch: {
    dialogOpen: {
      immediate: false,
      handler(isOpen) {
        if (isOpen) {
          this.handleOpened();
        } else {
          this.resetState();
        }
      }
    }
  },
  methods: {
    /**
     * Primer toque del flujo: registra el instante de apertura (para el margen de
     * tolerancia de RF-01.3) y consulta el parque de la sede.
     */
    async handleOpened() {
      this.formOpenedAt = new Date().toISOString();
      // El catálogo solo se sirve sobre una sede habilitada, así que el formulario ha
      // nacido dentro de la ventana válida y puede acogerse al margen de +5 minutos.
      this.formOpenedWithinGrace = true;
      this.resetForm();
      await this.loadCatalog();
    },

    async loadCatalog() {
      if (this.locationId === null || this.locationId === undefined || this.locationId === '') {
        this.catalogError = 'No se ha indicado la sede sobre la que consultar el parque.';
        return;
      }

      this.loading = true;
      this.catalogError = '';
      try {
        const data = await api.technician.getOpportunisticMachines(this.locationId);
        this.applyCatalog(data);
      } catch (error) {
        this.catalogError = error instanceof ApiError
          ? error.message
          : 'No se pudo consultar el parque de máquinas de la sede.';
      } finally {
        this.loading = false;
      }
    },

    /** Vuelca el contrato `LocationOpportunisticCatalogDto` en el estado del componente. */
    applyCatalog(data) {
      const payload = data && typeof data === 'object' ? data : {};
      this.location = payload.location || { id: this.locationId, name: this.locationName };
      this.machines = Array.isArray(payload.machines) ? payload.machines : [];
      this.coldChainWarning = payload.cold_chain_route_warning || null;
      this.activeInterventionConflict = payload.active_intervention_conflict || null;
      this.catalogLoaded = true;
    },

    /**
     * Segundo toque: elige la máquina y decide el flujo. Cambiar de máquina descarta
     * los datos del flujo anterior para no arrastrar una nota de traspaso o un motivo
     * de reincidencia a otro expediente.
     */
    selectMachine(machine) {
      if (!machine || machine.opportunistic_action === 'BLOCKED_OTHER_TECH_IN_PROGRESS') {
        this.selectedMachineId = machine?.id ?? null;
        this.mode = 'BLOCKED';
        this.errorMessage = '';
        return;
      }

      this.selectedMachineId = machine.id;
      this.mode = MODE_BY_ACTION[machine.opportunistic_action] || '';
      this.takeoverConfirmed = false;
      this.takeoverNote = '';
      this.reopenReason = '';
      this.description = '';
      this.informantName = '';
      this.photoFile = null;
      this.photoError = '';
      this.errorMessage = '';
      this.noticeMessage = '';

      if (this.mode === 'CREATE_NEW') {
        // Alta express: categoría y origen ya vienen elegidos (RNF-02).
        this.category = 'PRODUCT_JAM';
        this.originReason = 'SITE_VERBAL_NOTICE';
      }
    },

    resetForm() {
      this.selectedMachineId = null;
      this.mode = '';
      this.category = 'PRODUCT_JAM';
      this.originReason = 'SITE_VERBAL_NOTICE';
      this.informantName = '';
      this.description = '';
      this.photoFile = null;
      this.photoError = '';
      this.confirmColdChainOverride = false;
      this.takeoverConfirmed = false;
      this.takeoverNote = '';
      this.reopenReason = '';
      this.errorMessage = '';
      this.noticeMessage = '';
    },

    resetState() {
      this.machines = [];
      this.location = null;
      this.activeInterventionConflict = null;
      this.coldChainWarning = null;
      this.catalogLoaded = false;
      this.catalogError = '';
      this.loading = false;
      this.submitting = false;
      this.formOpenedAt = null;
      this.formOpenedWithinGrace = false;
      this.resetForm();
    },

    /** Etiqueta corta de la acción disponible en una máquina del catálogo. */
    actionLabel(machine) {
      return ACTION_LABELS[machine?.opportunistic_action] || 'Seleccionar';
    },

    /** Solo las máquinas con acción real son accionables; el bloqueo se muestra, no se pulsa. */
    isMachineSelectable(machine) {
      return machine?.opportunistic_action !== 'BLOCKED_OTHER_TECH_IN_PROGRESS';
    },

    handlePhotoChange(event) {
      const file = event?.target?.files?.[0] || null;
      this.photoError = '';
      if (!file) {
        this.photoFile = null;
        return;
      }

      const validationError = this.validatePhoto(file);
      if (validationError) {
        this.photoError = validationError;
        this.photoFile = null;
        if (event?.target) {
          event.target.value = '';
        }
        return;
      }

      this.photoFile = file;
    },

    /**
     * Guarda de cliente del Art. V.5. El servidor vuelve a verificar cabecera binaria y
     * tamaño: esto solo evita que el técnico espere una subida condenada a fallar.
     */
    validatePhoto(file) {
      if (!file) {
        return '';
      }
      if (Number(file.size) > MAX_PHOTO_BYTES) {
        return 'La fotografía supera el límite de 5 MB del Art. V.5.';
      }

      const name = String(file.name || '');
      const extension = name.includes('.') ? name.split('.').pop().toLowerCase() : '';
      if (!SAFE_PHOTO_EXTENSIONS.includes(extension)) {
        return 'Formato no admitido: solo .jpg, .jpeg, .png o .webp (Art. V.5).';
      }

      return '';
    },

    buildCreatePayload() {
      const informantName = this.originReason === 'SITE_VERBAL_NOTICE'
        ? this.informantName.trim().slice(0, 100)
        : '';

      return {
        machine_id: Number(this.selectedMachineId),
        category: this.category,
        origin_reason: this.originReason,
        informant_name: informantName || null,
        description: this.description.trim(),
        confirm_cold_chain_override: this.requiresColdChainConfirmation === true
          && this.confirmColdChainOverride === true,
        form_opened_within_grace: this.formOpenedWithinGrace === true
      };
    },

    buildAssumePayload() {
      const payload = {
        confirm_cold_chain_override: this.requiresColdChainConfirmation === true
          && this.confirmColdChainOverride === true,
        form_opened_within_grace: this.formOpenedWithinGrace === true
      };

      if (this.requiresTakeoverConfirmation) {
        payload.confirm_takeover_from_other_tech = this.takeoverConfirmed === true;
        const note = this.takeoverNote.trim().slice(0, 500);
        if (note !== '') {
          // Nota estrictamente interna (Art. V.4): el servidor la guarda como
          // comentario `is_internal = TRUE`, nunca visible para la Sede.
          payload.takeover_internal_note = note;
        }
      }

      return payload;
    },

    buildReopenPayload() {
      return {
        reopen_reason: this.reopenReason.trim(),
        confirm_cold_chain_override: this.requiresColdChainConfirmation === true
          && this.confirmColdChainOverride === true,
        form_opened_within_grace: this.formOpenedWithinGrace === true
      };
    },

    /** Construye el multipart solo cuando hay fotografía; sin ella viaja JSON limpio. */
    toRequestBody(payload) {
      if (!this.photoFile) {
        return payload;
      }

      const form = new FormData();
      Object.entries(payload).forEach(([key, value]) => {
        if (value === null || value === undefined) {
          return;
        }
        if (typeof value === 'boolean') {
          form.append(key, value ? '1' : '0');
        } else {
          form.append(key, String(value));
        }
      });
      form.append('photo', this.photoFile);

      return form;
    },

    /**
     * Tercer toque: valida y confirma el flujo elegido.
     * @returns {Promise<boolean>} `true` si el servidor aceptó la operación.
     */
    async submit() {
      if (this.submitting) {
        return false;
      }

      this.errorMessage = '';
      this.noticeMessage = '';

      if (!this.selectedMachine) {
        this.errorMessage = 'Selecciona primero la máquina sobre la que intervienes.';
        return false;
      }

      if (this.requiresColdChainConfirmation && !this.confirmColdChainOverride) {
        this.errorMessage = 'Antes de continuar debes confirmar expresamente que atiendes esta avería aunque tengas una parada crítica o de cadena de frío pendiente en otra sede (Art. II).';
        return false;
      }

      if (this.mode === 'CREATE_NEW') {
        return this.submitCreate();
      }
      if (this.mode === 'ASSUME') {
        return this.submitAssume();
      }
      if (this.mode === 'REOPEN') {
        return this.submitReopen();
      }
      if (this.isBlockedByOtherTechnician) {
        this.errorMessage = 'Otro compañero ya está interviniendo activamente esta máquina (Art. V.3). No es posible asumirla hasta que la pause o la resuelva.';
        return false;
      }
      if (this.isCurrentTechnicianInProgress) {
        this.errorMessage = 'Esta máquina ya está en curso bajo tu titularidad: continúa la intervención desde la parada de tu ruta.';
        return false;
      }

      this.errorMessage = 'No hay ninguna acción oportunista disponible sobre la máquina seleccionada.';
      return false;
    },

    async submitCreate() {
      if (this.description.trim().length < MIN_TEXT_LENGTH) {
        this.errorMessage = 'Describe el síntoma observado con al menos 10 caracteres.';
        return false;
      }
      if (this.photoError !== '') {
        this.errorMessage = this.photoError;
        return false;
      }

      this.submitting = true;
      try {
        const payload = this.buildCreatePayload();
        const data = await api.technician.createOpportunisticIncident(this.toRequestBody(payload));
        this.noticeMessage = 'Avería registrada e iniciada en curso.';
        this.$emit('created', data);
        this.close();
        return true;
      } catch (error) {
        this.handleApiError(error);
        return false;
      } finally {
        this.submitting = false;
      }
    },

    async submitAssume() {
      if (this.requiresTakeoverConfirmation && !this.takeoverConfirmed) {
        this.errorMessage = 'Confirma el traspaso del expediente que tenía asignado tu compañero antes de continuar (RF-05.3).';
        return false;
      }

      this.submitting = true;
      try {
        const data = await api.technician.assumeOpportunisticIncident(
          this.activeIncident?.id ?? this.selectedMachine?.active_incident?.id,
          this.buildAssumePayload()
        );
        this.noticeMessage = 'Expediente asumido e iniciado en curso.';
        this.$emit('assumed', data);
        this.close();
        return true;
      } catch (error) {
        this.handleApiError(error);
        return false;
      } finally {
        this.submitting = false;
      }
    },

    async submitReopen() {
      if (this.reopenReason.trim().length < MIN_TEXT_LENGTH) {
        this.errorMessage = 'Explica el motivo de la reincidencia con al menos 10 caracteres (RF-05.4).';
        return false;
      }

      this.submitting = true;
      try {
        const data = await api.technician.assumeOpportunisticIncident(
          this.activeIncident?.id ?? this.selectedMachine?.active_incident?.id,
          this.buildReopenPayload()
        );
        this.noticeMessage = data?.chronic_replacement_created === true
          ? 'Avería crónica cerrada y nuevo expediente sucesor iniciado en curso.'
          : 'Expediente reabierto e iniciado en curso.';
        this.$emit('assumed', data);
        this.close();
        return true;
      } catch (error) {
        this.handleApiError(error);
        return false;
      } finally {
        this.submitting = false;
      }
    },

    /**
     * Traduce los códigos del contrato del Módulo 16 a una salida comprensible sin
     * perder lo ya redactado por el técnico (RF-01.3).
     */
    handleApiError(error) {
      const code = error instanceof ApiError ? error.code : '';
      const details = (error instanceof ApiError && error.details) ? error.details : {};
      const rawMessage = error instanceof ApiError ? String(error.message || '') : '';
      // El transporte fabrica «API request failed with status N» cuando el servidor no
      // manda texto. Ese mensaje no es para el técnico: si llega, mandan las frases
      // operativas de cada código de negocio.
      const genericMessage = `API request failed with status ${error?.status}`;
      const serverMessage = rawMessage !== '' && rawMessage !== genericMessage ? rawMessage : '';

      switch (code) {
        case 'ACTIVE_INCIDENT_ALREADY_EXISTS_ASSUME_INSTEAD': {
          const existing = details.existing_incident || details.incident || null;
          this.applyDuplicateIncident(existing, details.opportunistic_action || '');
          return;
        }
        case 'COLD_CHAIN_ROUTE_WARNING_CONFIRMATION_REQUIRED':
          this.coldChainWarning = {
            requires_confirmation: true,
            pending_critical_stops: Array.isArray(details.pending_critical_stops)
              ? details.pending_critical_stops
              : this.coldChainStops
          };
          this.confirmColdChainOverride = false;
          this.errorMessage = serverMessage || 'Hay una parada crítica o de cadena de frío esperando en otra sede. Confirma expresamente que continúas aquí (Art. II).';
          return;
        case 'TAKEOVER_CONFIRMATION_REQUIRED':
          this.takeoverConfirmed = false;
          this.errorMessage = serverMessage || 'El expediente está asignado a otro compañero. Confirma el traspaso antes de continuar (RF-05.3).';
          return;
        case 'ACTIVE_INTERVENTION_AT_DIFFERENT_LOCATION': {
          const conflict = details.active_conflict || {};
          const label = conflict.ticket_code
            ? ` Deja primero en orden el expediente ${conflict.ticket_code} de otra sede.`
            : ' Primero debes cerrar o pausar la intervención que dejaste abierta en otra sede.';
          this.errorMessage = (serverMessage || 'Mantienes una intervención en curso en otra sede.') + label;
          return;
        }
        case 'INCIDENT_ALREADY_IN_PROGRESS_BY_OTHER_TECHNICIAN':
          this.errorMessage = serverMessage || 'Otro compañero ya está interviniendo esta máquina en este momento (Art. V.3).';
          return;
        case 'REOPEN_WINDOW_EXPIRED':
          this.errorMessage = serverMessage || 'La ventana de garantía de 48 horas ya ha expirado: no es posible reabrir este expediente (Art. V.6).';
          return;
        case 'REOPEN_REASON_TOO_SHORT':
          this.errorMessage = serverMessage || 'El motivo de la reincidencia debe tener al menos 10 caracteres (RF-05.4).';
          return;
        case 'LOCATION_NOT_ENABLED_FOR_OPPORTUNISTIC':
          this.errorMessage = serverMessage || 'Esta sede ya no está habilitada para una intervención oportunista (RF-01.1).';
          return;
        case 'CASH_REFUND_PRIVACY_VIOLATION':
          this.errorMessage = serverMessage || 'El parte del técnico nunca recoge datos bancarios: invita al usuario a usar el QR exterior (Art. V.4).';
          return;
        case 'MACHINE_INACTIVE':
          this.errorMessage = serverMessage || 'La máquina está dada de baja definitiva y no admite intervenciones (RF-01.5).';
          return;
        default:
          this.errorMessage = serverMessage || 'No se pudo completar la intervención oportunista. Inténtalo de nuevo.';
      }

      if (details && Array.isArray(details.form_data)) {
        // El contrato preserva el formulario ante un fallo de subida; el cliente ya
        // conserva los campos en memoria, así que basta con no limpiarlos.
        this.noticeMessage = 'Se ha conservado lo que llevabas escrito.';
      }
    },

    /**
     * Colisión concurrente en el alta: la máquina ya tenía expediente. En lugar de
     * obligar a recargar, se cambia la misma vista al Flujo B con el ticket recibido.
     */
    applyDuplicateIncident(existing, opportunisticAction) {
      if (!existing) {
        this.errorMessage = 'La máquina ya tiene un expediente activo. Cierra esta ventana y vuelve a abrir el catálogo para asumirlo (Art. V.2).';
        return;
      }

      const machine = this.machines.find((item) => Number(item.id) === Number(existing.machine_id))
        || this.selectedMachine;

      if (!machine) {
        this.errorMessage = 'La máquina ya tiene un expediente activo en este momento (Art. V.2).';
        return;
      }

      machine.active_incident = existing;
      machine.opportunistic_action = opportunisticAction || 'ASSUME_WITH_CONFIRMATION';
      this.selectedMachineId = machine.id;
      this.mode = MODE_BY_ACTION[machine.opportunistic_action] || 'ASSUME';
      this.takeoverConfirmed = false;
      this.errorMessage = '';
      this.noticeMessage = 'Esta máquina ya tenía un expediente abierto: puedes asumirlo ahora sin duplicar el aviso (Art. V.2).';
    },

    /** Cierra el diálogo sin perder la coherencia de los dos contratos de apertura. */
    close() {
      this.$emit('update:modelValue', false);
      this.$emit('update:isOpen', false);
      this.$emit('close');
    },

    handleVisibilityChange(value) {
      if (!value) {
        this.close();
      }
    }
  },
  template: `
    <ModalDialog
      :model-value="dialogOpen"
      :title="'Otra máquina en ' + displayLocationName"
      :subtitle="modeTitle + ' · máximo 3 toques: abrir, elegir máquina y confirmar (RNF-02)'"
      size="lg"
      @update:model-value="handleVisibilityChange"
    >
      <div
        style="display: flex; flex-direction: column; gap: 14px;"
        data-testid="technician-opportunistic-modal"
      >

        <!-- Estado de carga y error del catálogo -->
        <p
          v-if="loading"
          style="margin: 0; font-size: 13px; color: var(--color-ink-muted);"
          data-testid="opportunistic-loading"
        >
          Consultando el parque de máquinas de la sede…
        </p>
        <p
          v-if="catalogError"
          class="vg-alert vg-alert-error"
          style="margin: 0;"
          role="alert"
          data-testid="opportunistic-catalog-error"
        >
          {{ catalogError }}
        </p>

        <!-- Advertencia sanitaria expresa (RF-03.1, Art. II) -->
        <section
          v-if="requiresColdChainConfirmation"
          class="vg-alert vg-alert-warning"
          style="margin: 0; display: flex; flex-direction: column; gap: 8px;"
          role="alert"
          data-testid="opportunistic-cold-chain-banner"
        >
          <strong style="font-size: 13px;">⚠️ Prioridad sanitaria pendiente en otra sede (Art. II)</strong>
          <p style="margin: 0; font-size: 12px;">
            Tienes {{ coldChainStops.length }} parada(s) crítica(s) o de cadena de frío esperando. Si te quedas aquí,
            Coordinación recibirá un aviso inmediato para supervisar el tiempo o reasignar la emergencia (RF-03.2).
          </p>
          <ul style="margin: 0; padding-left: 18px; font-size: 12px;">
            <li v-for="stop in coldChainStops" :key="stop.incident_id">
              <strong>{{ stop.machine_code }}</strong> · {{ stop.location_name }}
              <span v-if="stop.ticket_code"> · {{ stop.ticket_code }}</span>
              <span v-if="stop.sla_target_at"> · SLA {{ stop.sla_target_at }}</span>
            </li>
          </ul>
          <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 12px; font-weight: 600;">
            <input
              type="checkbox"
              v-model="confirmColdChainOverride"
              data-testid="opportunistic-cold-chain-confirm"
              style="margin-top: 2px;"
            />
            <span>Confirmo expresamente que atiendo esta avería in situ aunque la urgencia sanitaria siga pendiente en otra sede.</span>
          </label>
        </section>

        <!-- Aviso neutro tras una colisión resuelta sin recargar -->
        <p
          v-if="noticeMessage"
          class="vg-alert vg-alert-info"
          style="margin: 0;"
          data-testid="opportunistic-notice"
        >
          {{ noticeMessage }}
        </p>

        <!-- Error de la operación: nunca borra lo redactado -->
        <p
          v-if="errorMessage"
          class="vg-alert vg-alert-error"
          style="margin: 0;"
          role="alert"
          data-testid="opportunistic-error"
        >
          {{ errorMessage }}
        </p>

        <p
          v-if="activeInterventionConflict"
          class="vg-alert vg-alert-warning"
          style="margin: 0; font-size: 12px;"
          data-testid="opportunistic-route-conflict"
        >
          Tienes una intervención abierta en otra sede. Cierra o pausa primero ese trabajo antes de iniciar otra avería (RF-02.2).
        </p>

        <!-- Paso 1 y 2: catálogo de máquinas de la sede -->
        <div v-if="machines.length > 0" style="display: flex; flex-direction: column; gap: 8px;">
          <p style="margin: 0; font-size: 12px; font-weight: 600; color: var(--color-ink-muted);">
            1 · Elige la máquina
          </p>
          <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px;">
            <button
              v-for="machine in machines"
              :key="machine.id"
              type="button"
              class="vg-btn vg-btn-secondary"
              :class="{ 'vg-btn-primary': Number(selectedMachineId) === Number(machine.id) }"
              :disabled="!isMachineSelectable(machine)"
              style="height: auto; min-height: 105px; text-align: left; display: flex; flex-direction: column; align-items: flex-start; justify-content: flex-start; gap: 6px; padding: 12px 14px; white-space: normal;"
              :data-testid="'opportunistic-machine-' + machine.id"
              @click="selectMachine(machine)"
            >
              <span style="font-weight: 700; font-size: 13px;">{{ machine.code }}</span>
              <span style="font-size: 12px;">{{ machine.model }} · {{ machine.machine_type }}</span>
              <span style="font-size: 11px; color: var(--color-ink-muted);">{{ machine.floor_wing }}</span>
              <span style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 2px;">
                <IncidentBadge
                  v-if="machine.active_incident"
                  :value="machine.active_incident.status"
                  type="status"
                  size="sm"
                />
                <IncidentBadge
                  v-else
                  value="Sin expediente"
                  type="status"
                  size="sm"
                />
                <span
                  v-if="machine.is_blocked_no_access"
                  style="font-size: 11px; font-weight: 600; color: var(--color-warning-text);"
                >Acceso bloqueado 72 h</span>
                <span
                  v-if="machine.sanitary_status && machine.sanitary_status !== 'OK'"
                  style="font-size: 11px; font-weight: 600; color: var(--color-error-text);"
                >{{ machine.sanitary_status }}</span>
              </span>
              <span style="font-size: 11px; font-weight: 600; color: var(--color-primary-dark);">
                {{ actionLabel(machine) }}
              </span>
            </button>
          </div>
        </div>

        <p
          v-else-if="catalogLoaded && !loading"
          style="margin: 0; font-size: 13px;"
          data-testid="opportunistic-empty-catalog"
        >
          Esta sede no tiene máquinas operativas disponibles para una intervención oportunista.
        </p>

        <!-- Paso 3A · Flujo A: alta oportunista (RF-04) -->
        <section
          v-if="isCreateMode"
          style="display: flex; flex-direction: column; gap: 10px;"
          data-testid="opportunistic-create-form"
        >
          <p style="margin: 0; font-size: 12px; font-weight: 600; color: var(--color-ink-muted);">
            2 · Describe la avería nueva
          </p>

          <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600;">
            Categoría del fallo
            <select class="vg-input" v-model="category" data-testid="opportunistic-category">
              <option v-for="item in categories" :key="item.value" :value="item.value">
                {{ item.icon }} {{ item.label }}
              </option>
            </select>
          </label>

          <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600;">
            Motivo de origen in situ
            <select class="vg-input" v-model="originReason" data-testid="opportunistic-origin-reason">
              <option v-for="item in originReasons" :key="item.value" :value="item.value">
                {{ item.label }}
              </option>
            </select>
          </label>

          <label
            v-if="originReason === 'SITE_VERBAL_NOTICE'"
            style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600;"
          >
            Nombre o cargo del informante (opcional)
            <input
              class="vg-input"
              type="text"
              maxlength="100"
              v-model="informantName"
              placeholder="Ej.: Marta Gómez (Conserjería)"
              data-testid="opportunistic-informant"
            />
            <span style="font-weight: 400; color: var(--color-ink-muted);">
              Visible para la Sede y para Coordinación; nunca en las vistas públicas del QR (Art. V.4).
            </span>
          </label>

          <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600;">
            Descripción del síntoma (mínimo 10 caracteres)
            <textarea
              class="vg-input"
              rows="3"
              maxlength="2000"
              v-model="description"
              placeholder="Qué has visto o te han comunicado al llegar a la máquina"
              data-testid="opportunistic-description"
            ></textarea>
            <span style="font-weight: 400; color: var(--color-ink-muted);">
              Esta descripción la lee la Sede. Las apreciaciones de taller van en las notas internas.
            </span>
          </label>

          <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600;">
            Fotografía de evidencia (opcional · máx. 5 MB · JPG, PNG o WEBP)
            <input
              class="vg-input"
              type="file"
              accept=".jpg,.jpeg,.png,.webp"
              @change="handlePhotoChange"
              data-testid="opportunistic-photo"
            />
          </label>
          <p
            v-if="photoError"
            style="margin: 0; font-size: 12px; font-weight: 600; color: var(--color-error-text);"
            data-testid="opportunistic-photo-error"
          >
            {{ photoError }}
          </p>

          <!-- Privacidad de la reclamación de monedas (RF-04.3, Art. V.4) -->
          <div
            v-if="isPaymentSystemCategory"
            class="vg-alert vg-alert-info"
            style="margin: 0; display: flex; flex-direction: column; gap: 4px;"
            data-testid="opportunistic-payment-privacy"
          >
            <strong style="font-size: 13px;">💳 Monedas retenidas: no recojas datos del consumidor</strong>
            <p style="margin: 0; font-size: 12px;">
              Este parte solo registra la avería técnica del monedero o billetero. Nunca pidas IBAN ni teléfono:
              invita al usuario a escanear el <strong>código QR exterior</strong> de la máquina para tramitar su
              reembolso de forma privada (Art. V.4).
            </p>
          </div>
        </section>

        <!-- Paso 3B · Flujo B: asunción desde otro técnico (RF-05.3) -->
        <section
          v-if="isAssumeMode && activeIncident"
          style="display: flex; flex-direction: column; gap: 10px;"
          data-testid="opportunistic-assume-form"
        >
          <div class="vg-card" style="padding: 10px 12px;">
            <p style="margin: 0; font-size: 13px; font-weight: 700;">
              {{ activeIncident.ticket_code }} · {{ activeIncident.category }}
            </p>
            <p style="margin: 4px 0 0 0; font-size: 12px;">
              {{ activeIncident.description }}
            </p>
            <p style="margin: 4px 0 0 0; font-size: 12px; color: var(--color-ink-muted);">
              Estado {{ activeIncident.status }} · Urgencia {{ activeIncident.urgency }}
              <span v-if="activeIncident.assigned_technician_name">
                · Asignada a {{ activeIncident.assigned_technician_name }}
              </span>
            </p>
          </div>

          <template v-if="requiresTakeoverConfirmation">
            <p
              class="vg-alert vg-alert-warning"
              style="margin: 0; font-size: 12px;"
              data-testid="opportunistic-takeover-warning"
            >
              Este expediente lo tiene asignado <strong>{{ activeIncident.assigned_technician_name || 'otro compañero' }}</strong>.
              Al asumirlo quedarás como único responsable activo (Art. V.3) y se avisará al técnico saliente por correo.
            </p>

            <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 12px; font-weight: 600;">
              <input
                type="checkbox"
                v-model="takeoverConfirmed"
                data-testid="opportunistic-takeover-confirm"
                style="margin-top: 2px;"
              />
              <span>Confirmo el traspaso por presencia física en la sede.</span>
            </label>

            <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600;">
              Nota interna de traspaso (opcional · máx. 500 caracteres)
              <textarea
                class="vg-input"
                rows="2"
                maxlength="500"
                v-model="takeoverNote"
                placeholder="Motivo del traspaso, estado en que queda la máquina…"
                data-testid="opportunistic-takeover-note"
              ></textarea>
              <span style="font-weight: 400; color: var(--color-ink-muted);">
                Estrictamente interna: la Sede y el Ciudadano QR nunca la ven (Art. V.4).
              </span>
            </label>
          </template>
        </section>

        <!-- Paso 3C · Reapertura en garantía (RF-05.4) -->
        <section
          v-if="isReopenMode && activeIncident"
          style="display: flex; flex-direction: column; gap: 10px;"
          data-testid="opportunistic-reopen-form"
        >
          <div class="vg-card" style="padding: 10px 12px;">
            <p style="margin: 0; font-size: 13px; font-weight: 700;">
              {{ activeIncident.ticket_code }} · en garantía
            </p>
            <p style="margin: 4px 0 0 0; font-size: 12px;">
              {{ activeIncident.description }}
            </p>
            <p style="margin: 4px 0 0 0; font-size: 12px; color: var(--color-ink-muted);">
              Reincidencias registradas: {{ activeIncident.reopen_count || 0 }}
            </p>
          </div>

          <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600;">
            Motivo de la reincidencia (mínimo 10 caracteres)
            <textarea
              class="vg-input"
              rows="3"
              maxlength="1000"
              v-model="reopenReason"
              placeholder="El fallo persiste: describe qué vuelve a fallar y desde cuándo"
              data-testid="opportunistic-reopen-reason"
            ></textarea>
          </label>

          <p
            v-if="willTriggerChronicReplacement"
            class="vg-alert vg-alert-warning"
            style="margin: 0; font-size: 12px;"
            data-testid="opportunistic-chronic-warning"
          >
            ⚠️ Será la 3.ª reincidencia: el expediente actual se cerrará como <strong>Avería Crónica</strong>
            y se creará automáticamente un nuevo expediente limpio, ya en curso y a tu nombre (RF-05.4.2).
          </p>
        </section>

        <!-- Estados sin acción posible -->
        <p
          v-if="isBlockedByOtherTechnician"
          class="vg-alert vg-alert-error"
          style="margin: 0; font-size: 12px;"
          data-testid="opportunistic-blocked"
        >
          Un compañero ya está interviniendo activamente esta máquina (Art. V.3). No se puede asumir un trabajo que
          otra persona tiene en curso; elige otra máquina de la sede.
        </p>
        <p
          v-if="isCurrentTechnicianInProgress"
          class="vg-alert vg-alert-info"
          style="margin: 0; font-size: 12px;"
          data-testid="opportunistic-current"
        >
          Esta máquina ya está en curso bajo tu titularidad. Continúa la intervención desde la parada de tu ruta
          para no duplicar el trabajo.
        </p>
      </div>

      <template #footer>
        <span style="margin-right: auto; font-size: 11px; color: var(--color-ink-muted);">
          <span v-if="formOpenedAtLabel">Formulario abierto a las {{ formOpenedAtLabel }} · margen de tolerancia 5 min</span>
        </span>
        <button type="button" class="vg-btn vg-btn-secondary" @click="close" data-testid="opportunistic-cancel">
          Cancelar
        </button>
        <button
          type="button"
          class="vg-btn vg-btn-primary"
          :disabled="!canSubmit"
          @click="submit"
          data-testid="opportunistic-submit"
        >
          {{ submitLabel }}
        </button>
      </template>
    </ModalDialog>
  `
};

export default TechnicianOpportunisticModal;
