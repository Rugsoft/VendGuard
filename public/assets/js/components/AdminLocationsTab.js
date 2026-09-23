/**
 * VendGuard - AdminLocationsTab Component (AdminLocationsTab.js)
 * 
 * Componente Vue 3 ESM para la Gestión y Administración Integral de Sedes Clientes (RF-01, RNF-05).
 * 
 * Características:
 * 1. Tabla interactiva de sedes con filtros por estado ('active', 'inactive', 'all') y búsqueda en tiempo real.
 * 2. Visualización clara de métricas de sede: conteo de máquinas activas y totales instaladas.
 * 3. Modal de Alta de Sede con código de sede inmutable en mayúsculas y validación estricta de teléfono.
 * 4. Modal de Edición de datos descriptivos de sede (nombre, dirección, persona y teléfono de contacto).
 * 5. Diálogo modal interactivo de confirmación de baja lógica con advertencia y bloqueo ante máquinas activas (EARS 1.4).
 * 6. Reactivación directa de sedes inactivas con actualización reactiva instantánea.
 * 
 * Dogma Vanilla: Vue 3 Options API en módulos ESM nativos sin dependencias npm externas.
 */

import { api } from '../api.js';

export const AdminLocationsTab = {
  name: 'AdminLocationsTab',
  data() {
    return {
      locations: [],
      isLoading: false,
      errorMessage: '',
      successMessage: '',
      filterStatus: 'all', // 'all' | 'active' | 'inactive'
      searchQuery: '',

      // Modal Alta
      showCreateModal: false,
      createForm: {
        site_code: '',
        name: '',
        address: '',
        contact_name: '',
        contact_phone: ''
      },
      createErrors: {},
      isSubmittingCreate: false,

      // Modal Edición
      showEditModal: false,
      editForm: {
        id: null,
        site_code: '',
        name: '',
        address: '',
        contact_name: '',
        contact_phone: ''
      },
      editErrors: {},
      isSubmittingEdit: false,

      // Modal Confirmación Baja Lógica
      showDeactivateModal: false,
      locationToDeactivate: null,
      deactivateWarning: '',
      isSubmittingDeactivate: false,

      // Estado de reactivación en curso
      isReactivatingId: null
    };
  },
  computed: {
    /**
     * Filtra la lista de sedes en memoria si es necesario o sirve como respaldo local.
     */
    filteredLocations() {
      return this.locations.filter(loc => {
        // Filtro por estado
        if (this.filterStatus === 'active' && !loc.is_active) {
          return false;
        }
        if (this.filterStatus === 'inactive' && loc.is_active) {
          return false;
        }

        // Filtro por búsqueda
        if (this.searchQuery.trim()) {
          const q = this.searchQuery.trim().toLowerCase();
          const matchCode = (loc.site_code || '').toLowerCase().includes(q);
          const matchName = (loc.name || '').toLowerCase().includes(q);
          const matchAddress = (loc.address || '').toLowerCase().includes(q);
          const matchContact = (loc.contact_name || '').toLowerCase().includes(q);
          const matchPhone = (loc.contact_phone || '').toLowerCase().includes(q);

          if (!matchCode && !matchName && !matchAddress && !matchContact && !matchPhone) {
            return false;
          }
        }

        return true;
      });
    },

    /**
     * Comprueba si la sede a desactivar puede ser dada de baja (bloqueada si tiene máquinas activas).
     */
    canConfirmDeactivate() {
      if (!this.locationToDeactivate) return false;
      const activeCount = Number(this.locationToDeactivate.active_machines_count || this.locationToDeactivate.machine_count || 0);
      return activeCount === 0;
    },

    /**
     * Resumen de contadores de sedes para visualización rápida.
     */
    summaryMetrics() {
      const total = this.locations.length;
      const active = this.locations.filter(l => l.is_active).length;
      const inactive = total - active;
      const totalMachines = this.locations.reduce((acc, l) => acc + Number(l.active_machines_count || l.machine_count || 0), 0);
      return { total, active, inactive, totalMachines };
    }
  },
  mounted() {
    this.loadLocations();
  },
  methods: {
    /**
     * Carga el listado de sedes desde el backend administrativo (RF-01).
     */
    async loadLocations() {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        const params = {};
        if (this.filterStatus !== 'all') {
          params.status = this.filterStatus;
        }
        if (this.searchQuery.trim()) {
          params.search = this.searchQuery.trim();
        }

        const res = await (api.admin ? api.admin.getLocations(params) : api.get('/coordinator/locations', { params }));
        this.locations = res.data || res || [];
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar las sedes administrativas.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Valida el formato del código de sede (Mayúsculas, guiones, alfanumérico).
     */
    validateSiteCode(code) {
      if (!code || !code.trim()) {
        return 'El código de sede es obligatorio.';
      }
      const clean = code.trim().toUpperCase();
      if (!/^[A-Z0-9_-]{3,32}$/.test(clean)) {
        return 'El código debe tener entre 3 y 32 caracteres (letras, números y guiones).';
      }
      return null;
    },

    /**
     * Valida formato de teléfono de 9 dígitos en España.
     */
    validatePhone(phone) {
      if (!phone || !phone.trim()) return null;
      const clean = phone.trim().replace(/\s+/g, '');
      if (!/^[6789]\d{8}$/.test(clean)) {
        return 'El teléfono debe contener 9 dígitos y comenzar por 6, 7, 8 o 9.';
      }
      return null;
    },

    /**
     * Abre el modal de alta de nueva sede.
     */
    openCreateModal() {
      this.createForm = {
        site_code: '',
        name: '',
        address: '',
        contact_name: '',
        contact_phone: ''
      };
      this.createErrors = {};
      this.showCreateModal = true;
    },

    /**
     * Cierra el modal de alta.
     */
    closeCreateModal() {
      this.showCreateModal = false;
      this.createErrors = {};
    },

    /**
     * Envía la solicitud de alta de sede.
     */
    async submitCreate() {
      this.createErrors = {};

      const codeError = this.validateSiteCode(this.createForm.site_code);
      if (codeError) this.createErrors.site_code = codeError;

      if (!this.createForm.name || !this.createForm.name.trim()) {
        this.createErrors.name = 'El nombre de la sede es obligatorio.';
      }
      if (!this.createForm.address || !this.createForm.address.trim()) {
        this.createErrors.address = 'La dirección de la sede es obligatoria.';
      }

      const phoneError = this.validatePhone(this.createForm.contact_phone);
      if (phoneError) this.createErrors.contact_phone = phoneError;

      if (Object.keys(this.createErrors).length > 0) {
        return;
      }

      this.isSubmittingCreate = true;
      try {
        const payload = {
          site_code: this.createForm.site_code.trim().toUpperCase(),
          name: this.createForm.name.trim(),
          address: this.createForm.address.trim(),
          contact_name: this.createForm.contact_name ? this.createForm.contact_name.trim() : null,
          contact_phone: this.createForm.contact_phone ? this.createForm.contact_phone.trim().replace(/\s+/g, '') : null
        };

        await (api.admin ? api.admin.createLocation(payload) : api.post('/coordinator/locations', payload));
        this.showSuccessNotification(`Sede "${payload.site_code}" dada de alta exitosamente.`);
        this.closeCreateModal();
        await this.loadLocations();
      } catch (err) {
        if (err.code === 'INACTIVE_RECORD_COLLISION' || err.code === 'LOCATION_ALREADY_EXISTS_ACTIVE') {
          this.createErrors.site_code = err.message || 'El código de sede ya existe en el sistema.';
        } else {
          this.createErrors.general = err.message || 'Error al crear la sede.';
        }
      } finally {
        this.isSubmittingCreate = false;
      }
    },

    /**
     * Abre el modal de edición de sede.
     */
    openEditModal(location) {
      this.editForm = {
        id: location.id,
        site_code: location.site_code,
        name: location.name,
        address: location.address,
        contact_name: location.contact_name || '',
        contact_phone: location.contact_phone || ''
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
     * Envía la edición de sede.
     */
    async submitEdit() {
      this.editErrors = {};

      if (!this.editForm.name || !this.editForm.name.trim()) {
        this.editErrors.name = 'El nombre de la sede es obligatorio.';
      }
      if (!this.editForm.address || !this.editForm.address.trim()) {
        this.editErrors.address = 'La dirección de la sede es obligatoria.';
      }

      const phoneError = this.validatePhone(this.editForm.contact_phone);
      if (phoneError) this.editErrors.contact_phone = phoneError;

      if (Object.keys(this.editErrors).length > 0) {
        return;
      }

      this.isSubmittingEdit = true;
      try {
        const payload = {
          name: this.editForm.name.trim(),
          address: this.editForm.address.trim(),
          contact_name: this.editForm.contact_name ? this.editForm.contact_name.trim() : null,
          contact_phone: this.editForm.contact_phone ? this.editForm.contact_phone.trim().replace(/\s+/g, '') : null
        };

        await (api.admin ? api.admin.updateLocation(this.editForm.id, payload) : api.patch(`/coordinator/locations/${this.editForm.id}`, payload));
        this.showSuccessNotification(`Sede "${this.editForm.site_code}" actualizada correctamente.`);
        this.closeEditModal();
        await this.loadLocations();
      } catch (err) {
        this.editErrors.general = err.message || 'Error al actualizar la sede.';
      } finally {
        this.isSubmittingEdit = false;
      }
    },

    /**
     * Abre el diálogo de confirmación de baja lógica para una sede.
     */
    openDeactivateModal(location) {
      this.locationToDeactivate = location;
      const activeCount = Number(location.active_machines_count || location.machine_count || 0);

      if (activeCount > 0) {
        this.deactivateWarning = `No es posible dar de baja esta sede porque tiene ${activeCount} máquina(s) operativa(s) activa(s). Reubique o dé de baja las máquinas antes de desactivar la sede.`;
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
      this.locationToDeactivate = null;
      this.deactivateWarning = '';
    },

    /**
     * Confirma la baja lógica de la sede en backend (EARS 1.4).
     */
    async confirmDeactivate() {
      if (!this.locationToDeactivate || !this.canConfirmDeactivate) return;

      this.isSubmittingDeactivate = true;
      try {
        await (api.admin ? api.admin.deactivateLocation(this.locationToDeactivate.id) : api.patch(`/coordinator/locations/${this.locationToDeactivate.id}/deactivate`));
        this.showSuccessNotification(`Sede "${this.locationToDeactivate.site_code}" dada de baja lógica correctamente.`);
        this.closeDeactivateModal();
        await this.loadLocations();
      } catch (err) {
        this.deactivateWarning = err.message || 'Error al desactivar la sede.';
      } finally {
        this.isSubmittingDeactivate = false;
      }
    },

    /**
     * Reactiva una sede previamente dada de baja lógica (EARS 1.6).
     */
    async reactivateLocation(location) {
      if (!location || location.is_active) return;

      this.isReactivatingId = location.id;
      try {
        await (api.admin ? api.admin.reactivateLocation(location.id) : api.patch(`/coordinator/locations/${location.id}/reactivate`));
        this.showSuccessNotification(`Sede "${location.site_code}" reactivada exitosamente.`);
        await this.loadLocations();
      } catch (err) {
        this.errorMessage = err.message || 'Error al reactivar la sede.';
      } finally {
        this.isReactivatingId = null;
      }
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
    <div class="admin-locations-tab">
      <!-- Barra Superior de Resumen y Acciones -->
      <div class="admin-tab-header card mb-4">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
          <div>
            <h3 class="h5 mb-1 text-primary fw-bold">🏢 Gestión y Catálogo de Sedes</h3>
            <p class="text-muted small mb-0">
              Alta, edición y control de sedes clientes. Cero borrado físico conforme a la Constitución (Art. III.1).
            </p>
          </div>
          <div>
            <button 
              type="button" 
              class="btn btn-primary d-inline-flex align-items-center gap-2"
              @click="openCreateModal"
            >
              <span>➕</span>
              <span>Nueva Sede</span>
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

      <!-- Barra de Filtros y Búsqueda -->
      <div class="card mb-4 shadow-sm">
        <div class="card-body">
          <div class="row g-3 align-items-center">
            <div class="col-md-5">
              <div class="input-group">
                <span class="input-group-text bg-white">🔍</span>
                <input 
                  type="text" 
                  class="form-control" 
                  placeholder="Buscar por código, nombre, dirección o contacto..."
                  v-model="searchQuery"
                  @input="loadLocations"
                />
                <button 
                  v-if="searchQuery" 
                  class="btn btn-outline-secondary" 
                  type="button" 
                  @click="searchQuery = ''; loadLocations()"
                >✕</button>
              </div>
            </div>

            <div class="col-md-4">
              <div class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 small text-muted text-nowrap">Estado:</label>
                <select class="form-select form-select-sm" v-model="filterStatus" @change="loadLocations">
                  <option value="all">Todas las Sedes</option>
                  <option value="active">Solo Sedes Activas</option>
                  <option value="inactive">Solo Sedes de Baja</option>
                </select>
              </div>
            </div>

            <div class="col-md-3 text-md-end text-muted small">
              <span class="badge bg-light text-dark border">
                Total: {{ summaryMetrics.total }} | Activas: {{ summaryMetrics.active }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Tabla de Sedes -->
      <div class="card shadow-sm mb-4">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light text-secondary small text-uppercase">
              <tr>
                <th scope="col" style="min-width: 130px;">Código</th>
                <th scope="col" style="min-width: 220px;">Nombre y Dirección</th>
                <th scope="col" style="min-width: 180px;">Contacto</th>
                <th scope="col" class="text-center" style="min-width: 110px;">Máquinas</th>
                <th scope="col" class="text-center" style="min-width: 100px;">Estado</th>
                <th scope="col" class="text-end" style="min-width: 180px;">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <!-- Spinner de carga -->
              <tr v-if="isLoading">
                <td colspan="6" class="text-center py-4 text-muted">
                  <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                  <span>Cargando sedes...</span>
                </td>
              </tr>

              <!-- Sin resultados -->
              <tr v-else-if="filteredLocations.length === 0">
                <td colspan="6" class="text-center py-5 text-muted">
                  <div class="mb-2 fs-3">🏢</div>
                  <p class="mb-0 fw-semibold">No se encontraron sedes coincidentes.</p>
                  <small>Modifique los filtros o registre una nueva sede.</small>
                </td>
              </tr>

              <!-- Filas de datos -->
              <tr v-else v-for="loc in filteredLocations" :key="loc.id" :class="{'table-light text-muted': !loc.is_active}">
                <!-- Código -->
                <td>
                  <span class="badge bg-dark font-monospace text-wrap">{{ loc.site_code }}</span>
                </td>

                <!-- Nombre y Dirección -->
                <td>
                  <div class="fw-bold text-truncate" style="max-width: 280px;" :title="loc.name">{{ loc.name }}</div>
                  <div class="small text-muted text-truncate" style="max-width: 280px;" :title="loc.address">
                    📍 {{ loc.address }}
                  </div>
                </td>

                <!-- Contacto -->
                <td>
                  <div v-if="loc.contact_name" class="small fw-semibold text-truncate" style="max-width: 180px;">
                    👤 {{ loc.contact_name }}
                  </div>
                  <div v-if="loc.contact_phone" class="small text-muted font-monospace">
                    📞 {{ loc.contact_phone }}
                  </div>
                  <span v-if="!loc.contact_name && !loc.contact_phone" class="text-muted small fst-italic">Sin datos</span>
                </td>

                <!-- Conteo de Máquinas -->
                <td class="text-center">
                  <span 
                    class="badge rounded-pill"
                    :class="(loc.active_machines_count || loc.machine_count || 0) > 0 ? 'bg-primary' : 'bg-secondary'"
                    :title="(loc.active_machines_count || loc.machine_count || 0) + ' máquinas activas instaladas'"
                  >
                    {{ loc.active_machines_count || loc.machine_count || 0 }}
                  </span>
                </td>

                <!-- Estado Activo / Inactivo -->
                <td class="text-center">
                  <span v-if="loc.is_active" class="badge bg-success">Activa</span>
                  <span v-else class="badge bg-danger" title="Dada de baja lógica">Inactiva</span>
                </td>

                <!-- Acciones -->
                <td class="text-end">
                  <div class="btn-group btn-group-sm" role="group">
                    <!-- Editar -->
                    <button 
                      type="button" 
                      class="btn btn-outline-secondary"
                      title="Editar datos descriptivos de la sede"
                      @click="openEditModal(loc)"
                    >
                      ✏️ Editar
                    </button>

                    <!-- Reactivar (si está inactiva) -->
                    <button 
                      v-if="!loc.is_active" 
                      type="button" 
                      class="btn btn-outline-success"
                      title="Reactivar sede dada de baja"
                      :disabled="isReactivatingId === loc.id"
                      @click="reactivateLocation(loc)"
                    >
                      <span v-if="isReactivatingId === loc.id" class="spinner-border spinner-border-sm me-1" role="status"></span>
                      <span>🔄 Reactivar</span>
                    </button>

                    <!-- Dar de Baja (si está activa) -->
                    <button 
                      v-else 
                      type="button" 
                      class="btn btn-outline-danger"
                      title="Dar de baja lógica a la sede"
                      @click="openDeactivateModal(loc)"
                    >
                      🗑️ Dar de baja
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- MODAL ALTA DE SEDE -->
      <div v-if="showCreateModal" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5);" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
              <h5 class="modal-title fw-bold">➕ Alta de Nueva Sede</h5>
              <button type="button" class="btn-close btn-close-white" @click="closeCreateModal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <div v-if="createErrors.general" class="alert alert-danger py-2 small mb-3">
                {{ createErrors.general }}
              </div>

              <form @submit.prevent="submitCreate">
                <!-- Código de sede -->
                <div class="mb-3">
                  <label class="form-label small fw-bold">Código de Sede (Inmutable) <span class="text-danger">*</span></label>
                  <input 
                    type="text" 
                    class="form-control font-monospace" 
                    :class="{'is-invalid': createErrors.site_code}"
                    placeholder="Ej: SEDE-MAD-01" 
                    v-model="createForm.site_code"
                    maxlength="32"
                    required
                  />
                  <div v-if="createErrors.site_code" class="invalid-feedback small">
                    {{ createErrors.site_code }}
                  </div>
                  <small class="text-muted form-text">Código alfanumérico único permanente. Se guardará en mayúsculas.</small>
                </div>

                <!-- Nombre -->
                <div class="mb-3">
                  <label class="form-label small fw-bold">Nombre de la Sede <span class="text-danger">*</span></label>
                  <input 
                    type="text" 
                    class="form-control" 
                    :class="{'is-invalid': createErrors.name}"
                    placeholder="Ej: Hospital Clínico - Edificio Sur" 
                    v-model="createForm.name"
                    maxlength="150"
                    required
                  />
                  <div v-if="createErrors.name" class="invalid-feedback small">{{ createErrors.name }}</div>
                </div>

                <!-- Dirección -->
                <div class="mb-3">
                  <label class="form-label small fw-bold">Dirección Completa <span class="text-danger">*</span></label>
                  <input 
                    type="text" 
                    class="form-control" 
                    :class="{'is-invalid': createErrors.address}"
                    placeholder="Ej: Calle Gran Vía 28, 28013 Madrid" 
                    v-model="createForm.address"
                    maxlength="255"
                    required
                  />
                  <div v-if="createErrors.address" class="invalid-feedback small">{{ createErrors.address }}</div>
                </div>

                <!-- Contacto -->
                <div class="row g-2">
                  <div class="col-md-6 mb-3">
                    <label class="form-label small fw-bold">Persona de Contacto</label>
                    <input 
                      type="text" 
                      class="form-control" 
                      placeholder="Ej: Marta Gómez" 
                      v-model="createForm.contact_name"
                      maxlength="100"
                    />
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label small fw-bold">Teléfono de Contacto</label>
                    <input 
                      type="tel" 
                      class="form-control font-monospace" 
                      :class="{'is-invalid': createErrors.contact_phone}"
                      placeholder="Ej: 600112233" 
                      v-model="createForm.contact_phone"
                      maxlength="15"
                    />
                    <div v-if="createErrors.contact_phone" class="invalid-feedback small">{{ createErrors.contact_phone }}</div>
                  </div>
                </div>

                <div class="modal-footer px-0 pb-0 pt-3 border-top">
                  <button type="button" class="btn btn-secondary" @click="closeCreateModal">Cancelar</button>
                  <button type="submit" class="btn btn-primary" :disabled="isSubmittingCreate">
                    <span v-if="isSubmittingCreate" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    <span>Guardar Sede</span>
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- MODAL EDICIÓN DE SEDE -->
      <div v-if="showEditModal" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5);" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow">
            <div class="modal-header bg-secondary text-white">
              <h5 class="modal-title fw-bold">✏️ Editar Sede</h5>
              <button type="button" class="btn-close btn-close-white" @click="closeEditModal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <div v-if="editErrors.general" class="alert alert-danger py-2 small mb-3">
                {{ editErrors.general }}
              </div>

              <form @submit.prevent="submitEdit">
                <!-- Código de sede solo lectura -->
                <div class="mb-3">
                  <label class="form-label small fw-bold">Código de Sede</label>
                  <div class="input-group">
                    <span class="input-group-text bg-light text-muted">🔒</span>
                    <input 
                      type="text" 
                      class="form-control font-monospace bg-light" 
                      :value="editForm.site_code" 
                      disabled
                    />
                  </div>
                  <small class="text-muted form-text">El código de sede es inmutable y no puede modificarse tras el alta.</small>
                </div>

                <!-- Nombre -->
                <div class="mb-3">
                  <label class="form-label small fw-bold">Nombre de la Sede <span class="text-danger">*</span></label>
                  <input 
                    type="text" 
                    class="form-control" 
                    :class="{'is-invalid': editErrors.name}"
                    v-model="editForm.name"
                    maxlength="150"
                    required
                  />
                  <div v-if="editErrors.name" class="invalid-feedback small">{{ editErrors.name }}</div>
                </div>

                <!-- Dirección -->
                <div class="mb-3">
                  <label class="form-label small fw-bold">Dirección Completa <span class="text-danger">*</span></label>
                  <input 
                    type="text" 
                    class="form-control" 
                    :class="{'is-invalid': editErrors.address}"
                    v-model="editForm.address"
                    maxlength="255"
                    required
                  />
                  <div v-if="editErrors.address" class="invalid-feedback small">{{ editErrors.address }}</div>
                </div>

                <!-- Contacto -->
                <div class="row g-2">
                  <div class="col-md-6 mb-3">
                    <label class="form-label small fw-bold">Persona de Contacto</label>
                    <input 
                      type="text" 
                      class="form-control" 
                      v-model="editForm.contact_name"
                      maxlength="100"
                    />
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label small fw-bold">Teléfono de Contacto</label>
                    <input 
                      type="tel" 
                      class="form-control font-monospace" 
                      :class="{'is-invalid': editErrors.contact_phone}"
                      v-model="editForm.contact_phone"
                      maxlength="15"
                    />
                    <div v-if="editErrors.contact_phone" class="invalid-feedback small">{{ editErrors.contact_phone }}</div>
                  </div>
                </div>

                <div class="modal-footer px-0 pb-0 pt-3 border-top">
                  <button type="button" class="btn btn-secondary" @click="closeEditModal">Cancelar</button>
                  <button type="submit" class="btn btn-primary" :disabled="isSubmittingEdit">
                    <span v-if="isSubmittingEdit" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    <span>Actualizar Sede</span>
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- MODAL CONFIRMACIÓN DE BAJA LÓGICA -->
      <div v-if="showDeactivateModal && locationToDeactivate" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5);" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
              <h5 class="modal-title fw-bold">⚠️ Confirmar Baja Lógica de Sede</h5>
              <button type="button" class="btn-close btn-close-white" @click="closeDeactivateModal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <!-- Alerta bloqueante si contiene máquinas activas -->
              <div v-if="!canConfirmDeactivate" class="alert alert-danger d-flex align-items-start gap-2 mb-3">
                <span class="fs-4">🛑</span>
                <div>
                  <h6 class="fw-bold mb-1">Operación Bloqueada por Integridad del Parque</h6>
                  <p class="small mb-0">{{ deactivateWarning }}</p>
                </div>
              </div>

              <!-- Explicación de baja lógica si está vacía -->
              <div v-else class="alert alert-warning mb-3 small">
                <span>
                  Está a punto de dar de baja lógica la sede <strong>{{ locationToDeactivate.site_code }}</strong> ({{ locationToDeactivate.name }}).
                  Conforme a la Constitución (Art. III.1), los registros históricos no se eliminarán físicamente y la sede podrá ser reactivada en cualquier momento.
                </span>
              </div>

              <p class="small text-muted mb-0">
                <strong>Código:</strong> {{ locationToDeactivate.site_code }}<br>
                <strong>Máquinas asociadas activas:</strong> {{ locationToDeactivate.active_machines_count || locationToDeactivate.machine_count || 0 }}
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
    </div>
  `
};

export default AdminLocationsTab;
