/**
 * VendGuard - AdminMachinesTab Component (AdminMachinesTab.js)
 * 
 * Componente Vue 3 ESM para la Gestión y Mantenimiento del Parque de Máquinas (RF-02, RNF-05, Constitución Art. II).
 * 
 * Características:
 * 1. Tabla interactiva de máquinas con insignias sanitarias (resaltando perecederos y SLA <= 4h).
 * 2. Filtros cruzados reactivos: estado ('active', 'inactive', 'all'), sede asignada, tipología y búsqueda libre.
 * 3. Modal de Alta con validación de código único inmutable y alerta sanitaria inmediata al elegir perecederos.
 * 4. Modal de Edición con bloqueo preventivo de cambio de tipología ante averías activas o en garantía (EARS 2.5).
 * 5. Modal de Traslado físico entre sedes activas con salvaguarda constitucional de bloqueo ante tickets abiertos (EARS 2.7, 2.8).
 * 6. Diálogo de Baja Lógica (Soft Delete) con bloqueo estricto si cuenta con averías en curso o en periodo de garantía de 48h (EARS 2.10, 2.11).
 * 7. Diálogo de Reactivación Asistida con selector forzoso de nueva sede activa si la sede original fue dada de baja previa (EARS 2.13).
 * 8. Acceso integrado a códigos QR para previsualización e impresión de etiquetas identificativas (EARS 2.14).
 * 
 * Dogma Vanilla: Vue 3 Options API en módulos ESM nativos sin dependencias npm externas.
 */

import { api } from '../api.js';

export const MACHINE_TYPES = [
  { value: 'HOT_DRINKS', label: 'Bebidas calientes (Café e infusiones)', isPerishable: false, badgeClass: 'bg-info text-dark' },
  { value: 'COLD_DRINKS', label: 'Bebidas frías', isPerishable: false, badgeClass: 'bg-primary' },
  { value: 'SNACKS', label: 'Snacks y sólidos no perecederos', isPerishable: false, badgeClass: 'bg-secondary' },
  { value: 'PERISHABLE_FOOD', label: 'Alimentos Perecederos', isPerishable: true, badgeClass: 'bg-danger' },
  { value: 'COMBO', label: 'Mixta / Combinada', isPerishable: false, badgeClass: 'bg-dark' }
];

export const AdminMachinesTab = {
  name: 'AdminMachinesTab',
  data() {
    return {
      machines: [],
      locations: [],
      isLoading: false,
      errorMessage: '',
      successMessage: '',

      // Filtros cruzados
      filterStatus: 'all', // 'all' | 'active' | 'inactive'
      filterLocation: '',  // '' | location_id
      filterType: '',      // '' | machine_type
      searchQuery: '',

      // Modal Alta
      showCreateModal: false,
      createForm: {
        code: '',
        location_id: '',
        model: '',
        machine_type: 'HOT_DRINKS',
        floor_wing: '',
        notes: ''
      },
      createErrors: {},
      isSubmittingCreate: false,

      // Modal Edición
      showEditModal: false,
      editForm: {
        id: null,
        code: '',
        location_id: null,
        location_name: '',
        model: '',
        machine_type: '',
        original_machine_type: '',
        floor_wing: '',
        notes: '',
        has_active_ticket: false,
        is_in_warranty: false,
        active_ticket_code: null
      },
      editErrors: {},
      isSubmittingEdit: false,

      // Modal Traslado
      showTransferModal: false,
      transferForm: {
        id: null,
        code: '',
        current_location_id: null,
        current_location_name: '',
        target_location_id: '',
        floor_wing: '',
        notes: '',
        has_active_ticket: false,
        is_in_warranty: false,
        active_ticket_code: null,
        active_ticket_status: null
      },
      transferErrors: {},
      isSubmittingTransfer: false,

      // Modal Confirmación Baja Lógica
      showDeactivateModal: false,
      machineToDeactivate: null,
      deactivateWarning: '',
      isSubmittingDeactivate: false,

      // Modal Reactivación Asistida
      showReactivateModal: false,
      reactivateForm: {
        id: null,
        code: '',
        original_location_id: null,
        original_location_name: '',
        is_original_location_active: false,
        target_location_id: '',
        floor_wing: ''
      },
      reactivateErrors: {},
      isSubmittingReactivate: false,

      // Modal Visor QR
      showQrModal: false,
      qrMachine: null
    };
  },
  computed: {
    /**
     * Filtra las máquinas reactivamente según estado, sede, tipología y texto libre.
     */
    filteredMachines() {
      return this.machines.filter(m => {
        // Filtro por estado
        if (this.filterStatus === 'active' && !m.is_active) {
          return false;
        }
        if (this.filterStatus === 'inactive' && m.is_active) {
          return false;
        }

        // Filtro por sede
        if (this.filterLocation !== '' && String(m.location_id) !== String(this.filterLocation)) {
          return false;
        }

        // Filtro por tipología
        if (this.filterType !== '' && m.machine_type !== this.filterType) {
          return false;
        }

        // Búsqueda por texto libre
        if (this.searchQuery.trim()) {
          const q = this.searchQuery.trim().toLowerCase();
          const matchCode = (m.code || '').toLowerCase().includes(q);
          const matchModel = (m.model || '').toLowerCase().includes(q);
          const matchLocation = (m.location_name || '').toLowerCase().includes(q);
          const matchSiteCode = (m.location_site_code || '').toLowerCase().includes(q);
          const matchFloor = (m.floor_wing || '').toLowerCase().includes(q);
          const matchNotes = (m.notes || '').toLowerCase().includes(q);

          if (!matchCode && !matchModel && !matchLocation && !matchSiteCode && !matchFloor && !matchNotes) {
            return false;
          }
        }

        return true;
      });
    },

    /**
     * Retorna únicamente las sedes que se encuentran en estado activo.
     */
    activeLocations() {
      return this.locations.filter(l => Boolean(l.is_active));
    },

    /**
     * Sedes disponibles para traslado (activas y distintas de la sede actual).
     */
    availableTransferLocations() {
      if (!this.transferForm.current_location_id) return this.activeLocations;
      return this.activeLocations.filter(l => l.id !== this.transferForm.current_location_id);
    },

    /**
     * Verifica si se puede confirmar el traslado (bloqueado si tiene avería o falta destino).
     */
    canConfirmTransfer() {
      if (!this.transferForm.id) return false;
      if (this.transferForm.has_active_ticket || this.transferForm.is_in_warranty) return false;
      return Boolean(this.transferForm.target_location_id && this.transferForm.floor_wing.trim());
    },

    /**
     * Verifica si se puede confirmar la baja de la máquina (bloqueada si tiene avería o garantía de 48h).
     */
    canConfirmDeactivate() {
      if (!this.machineToDeactivate) return false;
      return !this.machineToDeactivate.has_active_ticket && !this.machineToDeactivate.is_in_warranty;
    },

    /**
     * Verifica si se puede confirmar la reactivación asistida.
     * Si la sede original está inactiva, exige obligatoriamente target_location_id y floor_wing.
     */
    canConfirmReactivate() {
      if (!this.reactivateForm.id) return false;
      if (this.reactivateForm.is_original_location_active) {
        return true;
      }
      return Boolean(this.reactivateForm.target_location_id && this.reactivateForm.floor_wing.trim());
    },

    /**
     * Resumen de métricas de parque para la cabecera.
     */
    summaryMetrics() {
      const total = this.machines.length;
      const active = this.machines.filter(m => m.is_active).length;
      const inactive = total - active;
      const perishable = this.machines.filter(m => m.is_perishable || m.machine_type === 'PERISHABLE_FOOD').length;
      const inTrouble = this.machines.filter(m => m.has_active_ticket || m.is_in_warranty).length;
      return { total, active, inactive, perishable, inTrouble };
    }
  },
  mounted() {
    this.loadLocations();
    this.loadMachines();
  },
  methods: {
    /**
     * Carga el catálogo de sedes maestras para alimentar desplegables y filtros.
     */
    async loadLocations() {
      try {
        const res = await (api.admin ? api.admin.getLocations({ status: 'all' }) : api.get('/coordinator/locations?status=all'));
        this.locations = res.data || res || [];
      } catch (err) {
        console.error('Error al cargar sedes:', err);
      }
    },

    /**
     * Carga el listado de máquinas con los filtros actuales desde el backend.
     */
    async loadMachines() {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        const params = {};
        if (this.filterStatus !== 'all') {
          params.status = this.filterStatus;
        }
        if (this.filterLocation !== '') {
          params.location_id = this.filterLocation;
        }
        if (this.filterType !== '') {
          params.machine_type = this.filterType;
        }
        if (this.searchQuery.trim()) {
          params.search = this.searchQuery.trim();
        }

        const res = await (api.admin ? api.admin.getMachines(params) : api.get('/coordinator/machines', { params }));
        this.machines = res.data || res || [];
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar el catálogo de máquinas.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Valida la expresión regular del código de máquina (^[A-Z0-9-]{3,32}$).
     */
    validateMachineCode(code) {
      if (!code || !code.trim()) {
        return 'El código de máquina es obligatorio.';
      }
      const clean = code.trim().toUpperCase();
      if (!/^[A-Z0-9_-]{3,32}$/.test(clean)) {
        return 'El código debe tener entre 3 y 32 caracteres (alfanumérico y guiones).';
      }
      return null;
    },

    /**
     * Retorna la etiqueta legible de una tipología de máquina.
     */
    getTypeLabel(type) {
      const found = MACHINE_TYPES.find(t => t.value === type);
      return found ? found.label : type;
    },

    /**
     * Retorna la clase visual de insignia para la tipología de máquina.
     */
    getTypeBadgeClass(type) {
      const found = MACHINE_TYPES.find(t => t.value === type);
      return found ? found.badgeClass : 'bg-secondary';
    },

    /**
     * Determina si una tipología corresponde a alimentos perecederos.
     */
    isPerishable(type) {
      return type === 'PERISHABLE_FOOD';
    },

    /**
     * Abre el modal de alta de máquina.
     */
    openCreateModal() {
      this.createForm = {
        code: '',
        location_id: this.activeLocations.length > 0 ? this.activeLocations[0].id : '',
        model: '',
        machine_type: 'HOT_DRINKS',
        floor_wing: '',
        notes: ''
      };
      this.createErrors = {};
      this.showCreateModal = true;
    },

    /**
     * Cierra el modal de alta de máquina.
     */
    closeCreateModal() {
      this.showCreateModal = false;
      this.createErrors = {};
    },

    /**
     * Procesa el formulario de alta de máquina.
     */
    async submitCreate() {
      this.createErrors = {};

      const codeError = this.validateMachineCode(this.createForm.code);
      if (codeError) this.createErrors.code = codeError;

      if (!this.createForm.location_id) {
        this.createErrors.location_id = 'Debe seleccionar una sede activa receptora.';
      }
      if (!this.createForm.model || !this.createForm.model.trim()) {
        this.createErrors.model = 'El modelo técnico es obligatorio.';
      }
      if (!this.createForm.floor_wing || !this.createForm.floor_wing.trim()) {
        this.createErrors.floor_wing = 'La planta o ala de ubicación es obligatoria.';
      }

      if (Object.keys(this.createErrors).length > 0) {
        return;
      }

      this.isSubmittingCreate = true;
      try {
        const payload = {
          code: this.createForm.code.trim().toUpperCase(),
          location_id: Number(this.createForm.location_id),
          model: this.createForm.model.trim(),
          machine_type: this.createForm.machine_type,
          floor_wing: this.createForm.floor_wing.trim(),
          notes: this.createForm.notes ? this.createForm.notes.trim() : null
        };

        await (api.admin ? api.admin.createMachine(payload) : api.post('/coordinator/machines', payload));
        this.showSuccessNotification(`Máquina "${payload.code}" dada de alta exitosamente.`);
        this.closeCreateModal();
        await this.loadMachines();
      } catch (err) {
        if (err.data?.error?.code === 'MACHINE_ALREADY_EXISTS_INACTIVE') {
          this.createErrors.code = 'El código ya existe dado de baja previa. Puede reactivar la máquina desde el listado de inactivas.';
        } else {
          this.createErrors.general = err.message || 'Error al registrar la máquina.';
        }
      } finally {
        this.isSubmittingCreate = false;
      }
    },

    /**
     * Abre el modal de edición de una máquina.
     */
    openEditModal(machine) {
      this.editForm = {
        id: machine.id,
        code: machine.code,
        location_id: machine.location_id,
        location_name: machine.location_name || '',
        model: machine.model || '',
        machine_type: machine.machine_type,
        original_machine_type: machine.machine_type,
        floor_wing: machine.floor_wing || '',
        notes: machine.notes || '',
        has_active_ticket: Boolean(machine.has_active_ticket),
        is_in_warranty: Boolean(machine.is_in_warranty),
        active_ticket_code: machine.active_ticket_code || null
      };
      this.editErrors = {};
      this.showEditModal = true;
    },

    /**
     * Cierra el modal de edición.
     */
    closeEditModal() {
      this.showEditModal = false;
      this.editErrors = {};
    },

    /**
     * Procesa la edición de atributos de la máquina (EARS 2.4, 2.5).
     */
    async submitEdit() {
      this.editErrors = {};

      if (!this.editForm.model || !this.editForm.model.trim()) {
        this.editErrors.model = 'El modelo técnico es obligatorio.';
      }
      if (!this.editForm.floor_wing || !this.editForm.floor_wing.trim()) {
        this.editErrors.floor_wing = 'La planta o ala de ubicación es obligatoria.';
      }

      // Bloqueo preventivo en frontend si cambia la tipología con ticket activo/en garantía
      if (this.editForm.machine_type !== this.editForm.original_machine_type) {
        if (this.editForm.has_active_ticket || this.editForm.is_in_warranty) {
          this.editErrors.machine_type = `No se puede modificar la tipología sanitaria mientras la máquina tenga un ticket activo o en garantía (${this.editForm.active_ticket_code || 'vigente'}).`;
        }
      }

      if (Object.keys(this.editErrors).length > 0) {
        return;
      }

      this.isSubmittingEdit = true;
      try {
        const payload = {
          model: this.editForm.model.trim(),
          machine_type: this.editForm.machine_type,
          floor_wing: this.editForm.floor_wing.trim(),
          notes: this.editForm.notes ? this.editForm.notes.trim() : null
        };

        await (api.admin ? api.admin.updateMachine(this.editForm.id, payload) : api.patch(`/coordinator/machines/${this.editForm.id}`, payload));
        this.showSuccessNotification(`Máquina "${this.editForm.code}" actualizada correctamente.`);
        this.closeEditModal();
        await this.loadMachines();
      } catch (err) {
        this.editErrors.general = err.message || 'Error al actualizar la máquina.';
      } finally {
        this.isSubmittingEdit = false;
      }
    },

    /**
     * Abre el modal de traslado físico de sede (EARS 2.7, 2.8).
     */
    openTransferModal(machine) {
      this.transferForm = {
        id: machine.id,
        code: machine.code,
        current_location_id: machine.location_id,
        current_location_name: machine.location_name || '',
        target_location_id: '',
        floor_wing: machine.floor_wing || '',
        notes: '',
        has_active_ticket: Boolean(machine.has_active_ticket),
        is_in_warranty: Boolean(machine.is_in_warranty),
        active_ticket_code: machine.active_ticket_code || null,
        active_ticket_status: machine.active_ticket_status || null
      };
      this.transferErrors = {};
      this.showTransferModal = true;
    },

    /**
     * Cierra el modal de traslado.
     */
    closeTransferModal() {
      this.showTransferModal = false;
      this.transferErrors = {};
    },

    /**
     * Envía la solicitud de traslado entre sedes.
     */
    async submitTransfer() {
      this.transferErrors = {};

      if (!this.canConfirmTransfer) {
        if (this.transferForm.has_active_ticket || this.transferForm.is_in_warranty) {
          this.transferErrors.general = `Traslado bloqueado: La máquina tiene la incidencia activa ${this.transferForm.active_ticket_code}. Debe resolverse y cerrarse antes del traslado.`;
        } else if (!this.transferForm.target_location_id) {
          this.transferErrors.target_location_id = 'Debe seleccionar una sede destino activa.';
        } else if (!this.transferForm.floor_wing.trim()) {
          this.transferErrors.floor_wing = 'La nueva planta/ala es obligatoria.';
        }
        return;
      }

      this.isSubmittingTransfer = true;
      try {
        const payload = {
          target_location_id: Number(this.transferForm.target_location_id),
          floor_wing: this.transferForm.floor_wing.trim(),
          notes: this.transferForm.notes ? this.transferForm.notes.trim() : null
        };

        await (api.admin ? api.admin.transferMachine(this.transferForm.id, payload) : api.patch(`/coordinator/machines/${this.transferForm.id}/transfer`, payload));
        this.showSuccessNotification(`Máquina "${this.transferForm.code}" trasladada exitosamente.`);
        this.closeTransferModal();
        await this.loadMachines();
      } catch (err) {
        this.transferErrors.general = err.message || 'Error al trasladar la máquina.';
      } finally {
        this.isSubmittingTransfer = false;
      }
    },

    /**
     * Abre el diálogo de confirmación de baja lógica (EARS 2.10, 2.11).
     */
    openDeactivateModal(machine) {
      this.machineToDeactivate = machine;

      if (machine.has_active_ticket || machine.is_in_warranty) {
        this.deactivateWarning = `No es posible dar de baja esta máquina porque cuenta con la incidencia activa o en periodo de garantía ${machine.active_ticket_code || ''} (${machine.active_ticket_status || 'en curso'}). Todos los avisos deben estar cerrados antes de tramitar la baja.`;
      } else {
        this.deactivateWarning = '';
      }

      this.showDeactivateModal = true;
    },

    /**
     * Cierra el diálogo de baja lógica.
     */
    closeDeactivateModal() {
      this.showDeactivateModal = false;
      this.machineToDeactivate = null;
      this.deactivateWarning = '';
    },

    /**
     * Confirma la baja lógica de la máquina en backend.
     */
    async confirmDeactivate() {
      if (!this.machineToDeactivate || !this.canConfirmDeactivate) return;

      this.isSubmittingDeactivate = true;
      try {
        await (api.admin ? api.admin.deactivateMachine(this.machineToDeactivate.id) : api.patch(`/coordinator/machines/${this.machineToDeactivate.id}/deactivate`));
        this.showSuccessNotification(`Máquina "${this.machineToDeactivate.code}" dada de baja lógica correctamente.`);
        this.closeDeactivateModal();
        await this.loadMachines();
      } catch (err) {
        this.deactivateWarning = err.message || 'Error al dar de baja la máquina.';
      } finally {
        this.isSubmittingDeactivate = false;
      }
    },

    /**
     * Abre el diálogo de reactivación asistida (EARS 2.13).
     */
    openReactivateModal(machine) {
      // Determina si la sede original está activa
      const originalLocation = this.locations.find(l => l.id === machine.location_id);
      const isOriginalActive = originalLocation ? Boolean(originalLocation.is_active) : false;

      this.reactivateForm = {
        id: machine.id,
        code: machine.code,
        original_location_id: machine.location_id,
        original_location_name: machine.location_name || (originalLocation ? originalLocation.name : 'Sede desconocida'),
        is_original_location_active: isOriginalActive,
        target_location_id: isOriginalActive ? '' : (this.activeLocations.length > 0 ? this.activeLocations[0].id : ''),
        floor_wing: machine.floor_wing || ''
      };
      this.reactivateErrors = {};
      this.showReactivateModal = true;
    },

    /**
     * Cierra el modal de reactivación asistida.
     */
    closeReactivateModal() {
      this.showReactivateModal = false;
      this.reactivateErrors = {};
    },

    /**
     * Confirma la reactivación de la máquina (forzando nueva sede si la original está inactiva).
     */
    async submitReactivate() {
      this.reactivateErrors = {};

      if (!this.canConfirmReactivate) {
        if (!this.reactivateForm.target_location_id) {
          this.reactivateErrors.target_location_id = 'La sede original está dada de baja. Debe seleccionar una sede activa obligatoriamente.';
        }
        if (!this.reactivateForm.floor_wing.trim()) {
          this.reactivateErrors.floor_wing = 'La planta o ala de instalación es obligatoria.';
        }
        return;
      }

      this.isSubmittingReactivate = true;
      try {
        const payload = {};
        if (!this.reactivateForm.is_original_location_active || this.reactivateForm.target_location_id) {
          payload.target_location_id = Number(this.reactivateForm.target_location_id);
          payload.floor_wing = this.reactivateForm.floor_wing.trim();
        }

        await (api.admin ? api.admin.reactivateMachine(this.reactivateForm.id, payload) : api.patch(`/coordinator/machines/${this.reactivateForm.id}/reactivate`, payload));
        this.showSuccessNotification(`Máquina "${this.reactivateForm.code}" reactivada exitosamente.`);
        this.closeReactivateModal();
        await this.loadMachines();
      } catch (err) {
        this.reactivateErrors.general = err.message || 'Error al reactivar la máquina.';
      } finally {
        this.isSubmittingReactivate = false;
      }
    },

    /**
     * Abre el modal de etiqueta y visor QR (EARS 2.14).
     */
    openQrModal(machine) {
      this.qrMachine = machine;
      this.showQrModal = true;
    },

    /**
     * Cierra el modal de visor QR.
     */
    closeQrModal() {
      this.showQrModal = false;
      this.qrMachine = null;
    },

    /**
     * Muestra mensaje flotante temporal de confirmación.
     */
    showSuccessNotification(msg) {
      this.successMessage = msg;
      setTimeout(() => {
        if (this.successMessage === msg) {
          this.successMessage = '';
        }
      }, 4000);
    }
  },
  template: `
    <div class="admin-machines-tab">
      <!-- Barra Superior de Resumen y Acciones -->
      <div class="admin-tab-header card mb-4">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
          <div>
            <h3 class="h5 mb-1 text-primary fw-bold">🎰 Parque de Máquinas Dispensadoras</h3>
            <p class="text-muted small mb-0">
              Alta, traslado, inspección sanitaria y control de bajas lógicas conforme a la Constitución VendGuard (Art. II y III).
            </p>
          </div>
          <div>
            <button 
              type="button" 
              class="btn btn-primary d-inline-flex align-items-center gap-2"
              @click="openCreateModal"
            >
              <span>➕</span>
              <span>Nueva Máquina</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Notificaciones de Éxito o Error -->
      <div v-if="successMessage" class="alert alert-success alert-dismissible fade show" role="alert">
        <span>✅ {{ successMessage }}</span>
        <button type="button" class="btn-close" @click="successMessage = ''" aria-label="Cerrar"></button>
      </div>

      <div v-if="errorMessage" class="alert alert-danger alert-dismissible fade show" role="alert">
        <span>⚠️ {{ errorMessage }}</span>
        <button type="button" class="btn-close" @click="errorMessage = ''" aria-label="Cerrar"></button>
      </div>

      <!-- Barra de Filtros Cruzados y Búsqueda -->
      <div class="card mb-4 shadow-sm">
        <div class="card-body">
          <div class="row g-3 align-items-center">
            <!-- Búsqueda en tiempo real -->
            <div class="col-lg-4 col-md-6">
              <div class="input-group">
                <span class="input-group-text bg-white">🔍</span>
                <input 
                  type="text" 
                  class="form-control" 
                  placeholder="Buscar código, modelo, sede, ala..."
                  v-model="searchQuery"
                  @input="loadMachines"
                />
                <button 
                  v-if="searchQuery" 
                  class="btn btn-outline-secondary" 
                  type="button" 
                  @click="searchQuery = ''; loadMachines()"
                >✕</button>
              </div>
            </div>

            <!-- Filtro por Estado -->
            <div class="col-lg-2 col-md-3">
              <select class="form-select form-select-sm" v-model="filterStatus" @change="loadMachines">
                <option value="all">Todos los estados</option>
                <option value="active">Solo Activas</option>
                <option value="inactive">Solo Retiradas / Baja</option>
              </select>
            </div>

            <!-- Filtro por Sede -->
            <div class="col-lg-3 col-md-3">
              <select class="form-select form-select-sm" v-model="filterLocation" @change="loadMachines">
                <option value="">Todas las Sedes</option>
                <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                  {{ loc.name }} ({{ loc.site_code }})
                </option>
              </select>
            </div>

            <!-- Filtro por Tipología -->
            <div class="col-lg-3 col-md-6">
              <select class="form-select form-select-sm" v-model="filterType" @change="loadMachines">
                <option value="">Todas las tipologías</option>
                <option value="HOT_DRINKS">☕ Bebidas calientes</option>
                <option value="COLD_DRINKS">🥤 Bebidas frías</option>
                <option value="SNACKS">🥨 Snacks</option>
                <option value="PERISHABLE_FOOD">🥪 Perecederos (SLA ≤ 4h)</option>
                <option value="COMBO">🍱 Mixta / Combo</option>
              </select>
            </div>
          </div>

          <!-- Métricas de Resumen de Parque -->
          <div class="d-flex flex-wrap gap-3 mt-3 pt-3 border-top small text-muted">
            <span><strong>Total Máquinas:</strong> {{ summaryMetrics.total }}</span>
            <span class="text-success"><strong>Activas:</strong> {{ summaryMetrics.active }}</span>
            <span class="text-secondary"><strong>De Baja:</strong> {{ summaryMetrics.inactive }}</span>
            <span class="text-danger"><strong>Perecederos (Art. II):</strong> {{ summaryMetrics.perishable }}</span>
            <span class="text-warning"><strong>Con Avería/Garantía:</strong> {{ summaryMetrics.inTrouble }}</span>
          </div>
        </div>
      </div>

      <!-- Tabla de Máquinas -->
      <div class="card shadow-sm">
        <div class="card-body p-0">
          <div v-if="isLoading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Cargando...</span>
            </div>
            <p class="text-muted small mt-2">Cargando catálogo de máquinas...</p>
          </div>

          <div v-else-if="filteredMachines.length === 0" class="text-center py-5">
            <span class="fs-1">🎰</span>
            <h5 class="mt-3 text-muted">No se encontraron máquinas</h5>
            <p class="text-muted small">Pruebe a cambiar los criterios de búsqueda o filtros.</p>
          </div>

          <div v-else class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light text-uppercase small text-muted">
                <tr>
                  <th scope="col" class="ps-3" style="width: 140px;">Código</th>
                  <th scope="col" style="min-width: 180px;">Sede y Ubicación</th>
                  <th scope="col" style="min-width: 150px;">Modelo Técnico</th>
                  <th scope="col" style="min-width: 170px;">Tipología Sanitaria</th>
                  <th scope="col" style="width: 140px;">Estado Operativo</th>
                  <th scope="col" class="text-end pe-3" style="width: 220px;">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in filteredMachines" :key="m.id" :class="{'table-light opacity-75': !m.is_active}">
                  <!-- Código y QR -->
                  <td class="ps-3">
                    <div class="d-flex align-items-center gap-1">
                      <span class="font-monospace fw-bold text-dark">{{ m.code }}</span>
                      <button 
                        type="button" 
                        class="btn btn-sm btn-link p-0 text-muted" 
                        title="Ver etiqueta QR"
                        @click="openQrModal(m)"
                      >
                        📱
                      </button>
                    </div>
                  </td>

                  <!-- Sede y Ubicación interna -->
                  <td>
                    <div class="fw-semibold text-dark">{{ m.location_name }}</div>
                    <div class="text-muted small">
                      <span class="badge bg-light text-dark border me-1">{{ m.location_site_code }}</span>
                      <span>{{ m.floor_wing }}</span>
                    </div>
                  </td>

                  <!-- Modelo -->
                  <td>
                    <span class="fw-medium text-dark">{{ m.model }}</span>
                    <div v-if="m.notes" class="text-muted small text-truncate" style="max-width: 180px;" :title="m.notes">
                      📝 {{ m.notes }}
                    </div>
                  </td>

                  <!-- Tipología e Insignia Sanitaria -->
                  <td>
                    <span class="badge" :class="getTypeBadgeClass(m.machine_type)">
                      {{ getTypeLabel(m.machine_type) }}
                    </span>
                    <div v-if="m.is_perishable || m.machine_type === 'PERISHABLE_FOOD'" class="mt-1">
                      <span class="badge bg-danger-subtle text-danger border border-danger-subtle small fw-bold" title="Constitución Art. II: SLA <= 4.0h obligatorio para averías de refrigeración">
                        ⚠️ SLA ≤ 4.0h (Art. II)
                      </span>
                    </div>
                  </td>

                  <!-- Estado Operativo / Averías -->
                  <td>
                    <!-- Estado de Actividad General -->
                    <div v-if="!m.is_active">
                      <span class="badge bg-secondary">Retirada / Baja</span>
                    </div>
                    <!-- Estado con avería activa -->
                    <div v-else-if="m.has_active_ticket">
                      <span class="badge bg-danger" :title="'Incidencia activa: ' + (m.active_ticket_code || '')">
                        ⚠️ Avería: {{ m.active_ticket_code }}
                      </span>
                    </div>
                    <!-- Estado en periodo de garantía de 48h -->
                    <div v-else-if="m.is_in_warranty">
                      <span class="badge bg-warning text-dark" title="En periodo de garantía de 48 horas tras resolución">
                        🛡️ En Garantía 48h
                      </span>
                    </div>
                    <!-- Operativa normal -->
                    <div v-else>
                      <span class="badge bg-success">Operativa</span>
                    </div>
                  </td>

                  <!-- Acciones -->
                  <td class="text-end pe-3">
                    <div class="btn-group btn-group-sm">
                      <!-- Acciones para máquina activa -->
                      <template v-if="m.is_active">
                        <button 
                          type="button" 
                          class="btn btn-outline-primary"
                          title="Editar modelo y notas"
                          @click="openEditModal(m)"
                        >
                          ✏️ Editar
                        </button>
                        <button 
                          type="button" 
                          class="btn btn-outline-warning"
                          title="Trasladar a otra sede"
                          @click="openTransferModal(m)"
                        >
                          🚚 Trasladar
                        </button>
                        <button 
                          type="button" 
                          class="btn btn-outline-danger"
                          title="Dar de baja lógica"
                          @click="openDeactivateModal(m)"
                        >
                          🚫 Baja
                        </button>
                      </template>

                      <!-- Acciones para máquina inactiva -->
                      <template v-else>
                        <button 
                          type="button" 
                          class="btn btn-outline-success"
                          title="Reactivación asistida"
                          @click="openReactivateModal(m)"
                        >
                          🔄 Reactivar
                        </button>
                      </template>

                      <!-- Visor QR -->
                      <button 
                        type="button" 
                        class="btn btn-outline-secondary"
                        title="Ver código QR"
                        @click="openQrModal(m)"
                      >
                        QR
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- MODAL ALTA DE MÁQUINA -->
      <div v-if="showCreateModal" class="modal-backdrop fade show" style="background-color: rgba(0,0,0,0.5); position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: 1040;"></div>
      <div v-if="showCreateModal" class="modal fade show d-block" tabindex="-1" style="z-index: 1050;" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
          <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
              <h5 class="modal-title fw-bold">➕ Alta de Máquina Dispensadora</h5>
              <button type="button" class="btn-close btn-close-white" @click="closeCreateModal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <div v-if="createErrors.general" class="alert alert-danger mb-3 small">
                {{ createErrors.general }}
              </div>

              <form @submit.prevent="submitCreate">
                <div class="row g-3">
                  <!-- Código de máquina -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Código de Máquina <span class="text-danger">*</span></label>
                    <input 
                      type="text" 
                      class="form-control font-monospace text-uppercase" 
                      :class="{'is-invalid': createErrors.code}"
                      v-model="createForm.code"
                      placeholder="Ej: VEND-VAL-301"
                      maxlength="32"
                    />
                    <div v-if="createErrors.code" class="invalid-feedback small">{{ createErrors.code }}</div>
                    <div class="form-text small">Identificador físico inmutable tras el alta.</div>
                  </div>

                  <!-- Sede de instalación -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Sede Activa de Instalación <span class="text-danger">*</span></label>
                    <select 
                      class="form-select" 
                      :class="{'is-invalid': createErrors.location_id}"
                      v-model="createForm.location_id"
                    >
                      <option value="" disabled>Seleccione una sede activa...</option>
                      <option v-for="loc in activeLocations" :key="loc.id" :value="loc.id">
                        {{ loc.name }} ({{ loc.site_code }})
                      </option>
                    </select>
                    <div v-if="createErrors.location_id" class="invalid-feedback small">{{ createErrors.location_id }}</div>
                  </div>

                  <!-- Modelo técnico -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Modelo Técnico <span class="text-danger">*</span></label>
                    <input 
                      type="text" 
                      class="form-control" 
                      :class="{'is-invalid': createErrors.model}"
                      v-model="createForm.model"
                      placeholder="Ej: FAS Fast 1050"
                      maxlength="100"
                    />
                    <div v-if="createErrors.model" class="invalid-feedback small">{{ createErrors.model }}</div>
                  </div>

                  <!-- Tipología sanitaria -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Tipología de Dispensación <span class="text-danger">*</span></label>
                    <select class="form-select" v-model="createForm.machine_type">
                      <option value="HOT_DRINKS">☕ Bebidas calientes (Café e infusiones)</option>
                      <option value="COLD_DRINKS">🥤 Bebidas frías</option>
                      <option value="SNACKS">🥨 Snacks y no perecederos</option>
                      <option value="PERISHABLE_FOOD">🥪 Alimentos Perecederos (SLA ≤ 4h)</option>
                      <option value="COMBO">🍱 Mixta / Combinada</option>
                    </select>
                  </div>

                  <!-- Banner Informativo Sanitario Constitucional -->
                  <div v-if="createForm.machine_type === 'PERISHABLE_FOOD'" class="col-12">
                    <div class="alert alert-danger d-flex align-items-center gap-2 mb-0 py-2 small">
                      <span class="fs-5">⚠️</span>
                      <div>
                        <strong>Alerta Sanitaria Constitucional (Art. II):</strong> Las averías de refrigeración en máquinas con alimentos perecederos tienen un tiempo máximo de resolución de <strong>4.0 horas</strong>.
                      </div>
                    </div>
                  </div>

                  <!-- Planta / Ala -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Planta / Ala de Ubicación <span class="text-danger">*</span></label>
                    <input 
                      type="text" 
                      class="form-control" 
                      :class="{'is-invalid': createErrors.floor_wing}"
                      v-model="createForm.floor_wing"
                      placeholder="Ej: Planta 1 - Comedor de Personal"
                      maxlength="100"
                    />
                    <div v-if="createErrors.floor_wing" class="invalid-feedback small">{{ createErrors.floor_wing }}</div>
                  </div>

                  <!-- Notas adicionales -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Notas de Acceso o Técnicas</label>
                    <input 
                      type="text" 
                      class="form-control" 
                      v-model="createForm.notes"
                      placeholder="Ej: Enchufe protegido con magneto térmico"
                      maxlength="255"
                    />
                  </div>
                </div>

                <div class="modal-footer px-0 pb-0 pt-3 border-top mt-3">
                  <button type="button" class="btn btn-secondary" @click="closeCreateModal">Cancelar</button>
                  <button type="submit" class="btn btn-primary" :disabled="isSubmittingCreate">
                    <span v-if="isSubmittingCreate" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    <span>Registrar Máquina</span>
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- MODAL EDICIÓN DE MÁQUINA -->
      <div v-if="showEditModal" class="modal-backdrop fade show" style="background-color: rgba(0,0,0,0.5); position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: 1040;"></div>
      <div v-if="showEditModal" class="modal fade show d-block" tabindex="-1" style="z-index: 1050;" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
          <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
              <h5 class="modal-title fw-bold">✏️ Editar Máquina: {{ editForm.code }}</h5>
              <button type="button" class="btn-close btn-close-white" @click="closeEditModal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <div v-if="editErrors.general" class="alert alert-danger mb-3 small">
                {{ editErrors.general }}
              </div>

              <form @submit.prevent="submitEdit">
                <div class="row g-3">
                  <!-- Código inmutable -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Código de Máquina (Inmutable)</label>
                    <input type="text" class="form-control font-monospace bg-light" :value="editForm.code" disabled />
                    <div class="form-text small">El código identificativo no puede ser modificado.</div>
                  </div>

                  <!-- Sede actual (Solo lectura en edición) -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Sede Asignada Actual</label>
                    <input type="text" class="form-control bg-light" :value="editForm.location_name" disabled />
                    <div class="form-text small">Para cambiar de centro, use la acción específica de <strong>Traslado</strong>.</div>
                  </div>

                  <!-- Modelo -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Modelo Técnico <span class="text-danger">*</span></label>
                    <input 
                      type="text" 
                      class="form-control" 
                      :class="{'is-invalid': editErrors.model}"
                      v-model="editForm.model"
                      maxlength="100"
                    />
                    <div v-if="editErrors.model" class="invalid-feedback small">{{ editErrors.model }}</div>
                  </div>

                  <!-- Tipología (con bloqueo preventivo ante averías) -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Tipología Sanitaria <span class="text-danger">*</span></label>
                    <select 
                      class="form-select" 
                      :class="{'is-invalid': editErrors.machine_type}"
                      v-model="editForm.machine_type"
                      :disabled="editForm.has_active_ticket || editForm.is_in_warranty"
                    >
                      <option value="HOT_DRINKS">☕ Bebidas calientes</option>
                      <option value="COLD_DRINKS">🥤 Bebidas frías</option>
                      <option value="SNACKS">🥨 Snacks</option>
                      <option value="PERISHABLE_FOOD">🥪 Alimentos Perecederos (SLA ≤ 4h)</option>
                      <option value="COMBO">🍱 Mixta / Combinada</option>
                    </select>
                    <div v-if="editErrors.machine_type" class="invalid-feedback small">{{ editErrors.machine_type }}</div>
                    <div v-if="editForm.has_active_ticket || editForm.is_in_warranty" class="form-text text-warning small">
                      ⚠️ Tipología bloqueada: tiene avería activa o en garantía (EARS 2.5).
                    </div>
                  </div>

                  <!-- Alerta Sanitaria en edición -->
                  <div v-if="editForm.machine_type === 'PERISHABLE_FOOD'" class="col-12">
                    <div class="alert alert-danger py-2 small mb-0">
                      <strong>⚠️ Máquina de Perecederos (Art. II):</strong> Obligatoriedad de resolución de averías de frío en menos de 4.0h.
                    </div>
                  </div>

                  <!-- Planta / Ala -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Planta / Ala de Ubicación <span class="text-danger">*</span></label>
                    <input 
                      type="text" 
                      class="form-control" 
                      :class="{'is-invalid': editErrors.floor_wing}"
                      v-model="editForm.floor_wing"
                      maxlength="100"
                    />
                    <div v-if="editErrors.floor_wing" class="invalid-feedback small">{{ editErrors.floor_wing }}</div>
                  </div>

                  <!-- Notas -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Notas Técnicas</label>
                    <input 
                      type="text" 
                      class="form-control" 
                      v-model="editForm.notes"
                      maxlength="255"
                    />
                  </div>
                </div>

                <div class="modal-footer px-0 pb-0 pt-3 border-top mt-3">
                  <button type="button" class="btn btn-secondary" @click="closeEditModal">Cancelar</button>
                  <button type="submit" class="btn btn-primary" :disabled="isSubmittingEdit">
                    <span v-if="isSubmittingEdit" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    <span>Actualizar Máquina</span>
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- MODAL TRASLADO DE SEDE -->
      <div v-if="showTransferModal" class="modal-backdrop fade show" style="background-color: rgba(0,0,0,0.5); position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: 1040;"></div>
      <div v-if="showTransferModal" class="modal fade show d-block" tabindex="-1" style="z-index: 1050;" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow">
            <div class="modal-header bg-warning text-dark">
              <h5 class="modal-title fw-bold">🚚 Traslado Físico de Máquina: {{ transferForm.code }}</h5>
              <button type="button" class="btn-close" @click="closeTransferModal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <!-- Alerta bloqueante si tiene averías abiertas o en garantía -->
              <div v-if="transferForm.has_active_ticket || transferForm.is_in_warranty" class="alert alert-danger d-flex align-items-start gap-2 mb-3">
                <span class="fs-4">🛑</span>
                <div>
                  <h6 class="fw-bold mb-1">Traslado Bloqueado por Seguridad Operativa</h6>
                  <p class="small mb-0">
                    No se puede trasladar la máquina mientras tenga una avería activa o en periodo de garantía de 48 horas (Ticket: <strong>{{ transferForm.active_ticket_code || 'activo' }}</strong>, estado: <strong>{{ transferForm.active_ticket_status || 'en curso' }}</strong>).
                    Conforme a la norma (EARS 2.8), el ticket debe estar cerrado definitivamente antes del movimiento físico.
                  </p>
                </div>
              </div>

              <div v-if="transferErrors.general" class="alert alert-danger mb-3 small">
                {{ transferErrors.general }}
              </div>

              <form @submit.prevent="submitTransfer">
                <div class="mb-3">
                  <label class="form-label small fw-bold">Sede Origen Actual</label>
                  <input type="text" class="form-control bg-light" :value="transferForm.current_location_name" disabled />
                </div>

                <div class="mb-3">
                  <label class="form-label small fw-bold">Sede Activa de Destino <span class="text-danger">*</span></label>
                  <select 
                    class="form-select" 
                    :class="{'is-invalid': transferErrors.target_location_id}"
                    v-model="transferForm.target_location_id"
                    :disabled="!canConfirmTransfer && (transferForm.has_active_ticket || transferForm.is_in_warranty)"
                  >
                    <option value="" disabled>Seleccione una sede activa de destino...</option>
                    <option v-for="loc in availableTransferLocations" :key="loc.id" :value="loc.id">
                      {{ loc.name }} ({{ loc.site_code }})
                    </option>
                  </select>
                  <div v-if="transferErrors.target_location_id" class="invalid-feedback small">{{ transferErrors.target_location_id }}</div>
                </div>

                <div class="mb-3">
                  <label class="form-label small fw-bold">Nueva Planta / Ala <span class="text-danger">*</span></label>
                  <input 
                    type="text" 
                    class="form-control" 
                    :class="{'is-invalid': transferErrors.floor_wing}"
                    v-model="transferForm.floor_wing"
                    placeholder="Ej: Planta 0 - Sala de Espera"
                    :disabled="!canConfirmTransfer && (transferForm.has_active_ticket || transferForm.is_in_warranty)"
                    maxlength="100"
                  />
                  <div v-if="transferErrors.floor_wing" class="invalid-feedback small">{{ transferErrors.floor_wing }}</div>
                </div>

                <div class="mb-3">
                  <label class="form-label small fw-bold">Notas Justificativas del Traslado</label>
                  <textarea 
                    class="form-control" 
                    v-model="transferForm.notes" 
                    rows="2"
                    placeholder="Ej: Autorizado por gerencia técnica..."
                    :disabled="!canConfirmTransfer && (transferForm.has_active_ticket || transferForm.is_in_warranty)"
                  ></textarea>
                </div>

                <div class="modal-footer px-0 pb-0 pt-3 border-top">
                  <button type="button" class="btn btn-secondary" @click="closeTransferModal">Cancelar</button>
                  <button 
                    type="submit" 
                    class="btn btn-warning text-dark fw-bold" 
                    :disabled="!canConfirmTransfer || isSubmittingTransfer"
                  >
                    <span v-if="isSubmittingTransfer" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    <span>Confirmar Traslado</span>
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- MODAL CONFIRMACIÓN DE BAJA LÓGICA -->
      <div v-if="showDeactivateModal && machineToDeactivate" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5); z-index: 1050;" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
              <h5 class="modal-title fw-bold">⚠️ Confirmar Baja Lógica de Máquina</h5>
              <button type="button" class="btn-close btn-close-white" @click="closeDeactivateModal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <!-- Alerta bloqueante si contiene averías activas -->
              <div v-if="!canConfirmDeactivate" class="alert alert-danger d-flex align-items-start gap-2 mb-3">
                <span class="fs-4">🛑</span>
                <div>
                  <h6 class="fw-bold mb-1">Baja Bloqueada por Avería en Curso</h6>
                  <p class="small mb-0">{{ deactivateWarning }}</p>
                </div>
              </div>

              <!-- Explicación de baja lógica si no tiene averías -->
              <div v-else class="alert alert-warning mb-3 small">
                <span>
                  Está a punto de dar de baja la máquina <strong>{{ machineToDeactivate.code }}</strong> ({{ machineToDeactivate.model }}).
                  Conforme a la Constitución (Art. III.1), los datos no se destruyen y el código QR mostrará aviso informativo público bloqueando nuevos reportes.
                </span>
              </div>

              <p class="small text-muted mb-0">
                <strong>Código:</strong> {{ machineToDeactivate.code }}<br>
                <strong>Sede actual:</strong> {{ machineToDeactivate.location_name }} ({{ machineToDeactivate.floor_wing }})
              </p>
            </div>
            <div class="modal-footer border-top">
              <button type="button" class="btn btn-secondary" @click="closeDeactivateModal">Cancelar</button>
              <button 
                type="button" 
                class="btn btn-danger" 
                :disabled="!canConfirmDeactivate || isSubmittingDeactivate"
                @click="confirmDeactivate"
              >
                <span v-if="isSubmittingDeactivate" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <span>Confirmar Baja</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- MODAL REACTIVACIÓN ASISTIDA (EARS 2.13) -->
      <div v-if="showReactivateModal" class="modal-backdrop fade show" style="background-color: rgba(0,0,0,0.5); position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: 1040;"></div>
      <div v-if="showReactivateModal" class="modal fade show d-block" tabindex="-1" style="z-index: 1050;" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
              <h5 class="modal-title fw-bold">🔄 Reactivación Asistida: {{ reactivateForm.code }}</h5>
              <button type="button" class="btn-close btn-close-white" @click="closeReactivateModal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <div v-if="reactivateErrors.general" class="alert alert-danger mb-3 small">
                {{ reactivateErrors.general }}
              </div>

              <!-- Notificación si la sede original está inactiva -->
              <div v-if="!reactivateForm.is_original_location_active" class="alert alert-warning d-flex align-items-start gap-2 mb-3">
                <span class="fs-4">⚠️</span>
                <div>
                  <h6 class="fw-bold mb-1">Sede Original Inactiva</h6>
                  <p class="small mb-0">
                    La sede original (<strong>{{ reactivateForm.original_location_name }}</strong>) se encuentra actualmente dada de baja.
                    Según la norma (EARS 2.13), para reactivar la máquina <strong>debe seleccionar forzosamente una nueva sede activa</strong> y especificar su ubicación física.
                  </p>
                </div>
              </div>

              <div v-else class="alert alert-info small mb-3">
                La sede original <strong>{{ reactivateForm.original_location_name }}</strong> se encuentra activa. Puede reactivar la máquina en dicha sede o seleccionar un nuevo centro.
              </div>

              <form @submit.prevent="submitReactivate">
                <!-- Selector forzoso de nueva sede activa si la original está de baja -->
                <div class="mb-3">
                  <label class="form-label small fw-bold">
                    Sede Receptora Activa 
                    <span v-if="!reactivateForm.is_original_location_active" class="text-danger">*</span>
                  </label>
                  <select 
                    class="form-select" 
                    :class="{'is-invalid': reactivateErrors.target_location_id}"
                    v-model="reactivateForm.target_location_id"
                  >
                    <option v-if="reactivateForm.is_original_location_active" value="">
                      Mantener sede actual ({{ reactivateForm.original_location_name }})
                    </option>
                    <option v-else value="" disabled>Seleccione una sede activa receptora...</option>
                    <option v-for="loc in activeLocations" :key="loc.id" :value="loc.id">
                      {{ loc.name }} ({{ loc.site_code }})
                    </option>
                  </select>
                  <div v-if="reactivateErrors.target_location_id" class="invalid-feedback small">{{ reactivateErrors.target_location_id }}</div>
                </div>

                <div class="mb-3">
                  <label class="form-label small fw-bold">
                    Planta / Ala de Instalación 
                    <span v-if="!reactivateForm.is_original_location_active" class="text-danger">*</span>
                  </label>
                  <input 
                    type="text" 
                    class="form-control" 
                    :class="{'is-invalid': reactivateErrors.floor_wing}"
                    v-model="reactivateForm.floor_wing"
                    placeholder="Ej: Planta Baja - Entrada Principal"
                    maxlength="100"
                  />
                  <div v-if="reactivateErrors.floor_wing" class="invalid-feedback small">{{ reactivateErrors.floor_wing }}</div>
                </div>

                <div class="modal-footer px-0 pb-0 pt-3 border-top">
                  <button type="button" class="btn btn-secondary" @click="closeReactivateModal">Cancelar</button>
                  <button 
                    type="submit" 
                    class="btn btn-success" 
                    :disabled="!canConfirmReactivate || isSubmittingReactivate"
                  >
                    <span v-if="isSubmittingReactivate" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    <span>Reactivar Máquina</span>
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- MODAL VISOR QR (EARS 2.14) -->
      <div v-if="showQrModal && qrMachine" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5); z-index: 1050;" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow text-center">
            <div class="modal-header bg-light">
              <h5 class="modal-title fw-bold text-dark">📱 Código QR: {{ qrMachine.code }}</h5>
              <button type="button" class="btn-close" @click="closeQrModal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body py-4">
              <div class="mb-3">
                <img 
                  :src="'/api/qr/view/' + qrMachine.code" 
                  :alt="'QR ' + qrMachine.code" 
                  style="width: 220px; height: 220px; border: 1px solid #dee2e6; border-radius: 8px; padding: 10px;"
                />
              </div>
              <h6 class="fw-bold mb-1">{{ qrMachine.location_name }}</h6>
              <p class="text-muted small mb-3">{{ qrMachine.floor_wing }} ({{ qrMachine.model }})</p>

              <div class="d-flex justify-content-center gap-2">
                <a :href="'/api/qr/download/' + qrMachine.code" download class="btn btn-outline-primary btn-sm">
                  ⬇️ Descargar SVG
                </a>
                <a :href="'/api/qr/view/' + qrMachine.code" target="_blank" class="btn btn-outline-secondary btn-sm">
                  🖨️ Imprimir
                </a>
              </div>
            </div>
            <div class="modal-footer border-top justify-content-center">
              <button type="button" class="btn btn-secondary" @click="closeQrModal">Cerrar</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
};

export default AdminMachinesTab;
