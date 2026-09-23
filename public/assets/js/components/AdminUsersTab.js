/**
 * VendGuard - AdminUsersTab Component (AdminUsersTab.js)
 * 
 * Componente Vue 3 ESM para la Gestión y Mantenimiento del Personal Interno (RF-03, RNF-05).
 * 
 * Características:
 * 1. Tabla interactiva de usuarios (Técnicos de Ruta y Coordinadores de Servicio) con badges de rol y estado.
 * 2. Visualización reactiva de la carga de trabajo técnica (recuento de averías activas asignadas).
 * 3. Filtros reactivos por estado ('active', 'inactive', 'all'), rol ('all', 'TECHNICIAN', 'COORDINATOR') y búsqueda libre.
 * 4. Modal de Alta con asignación de rol, credencial cifrada inicial (mínimo 8 caracteres) y validación de correo/teléfono.
 * 5. Modal de Edición de datos de contacto (nombre y teléfono), con inmutabilidad de correo y rol para evitar escalada de privilegios.
 * 6. Modal de Reseteo Administrativo de Contraseña (mínimo 8 caracteres) con invalidación segura de sesiones previas.
 * 7. Diálogo de Baja Lógica (Soft Delete) con triple bloqueo estricto:
 *    a) Auto-desactivación del propio usuario en sesión (EARS 3.5).
 *    b) Guardia mínima operativa: al menos 1 técnico y 1 coordinador activos (EARS 3.6).
 *    c) Averías asignadas pendientes en curso (EARS 3.7).
 * 8. Reactivación directa de técnicos y coordinadores dados de baja previa (EARS 3.10).
 * 
 * Dogma Vanilla: Vue 3 Options API en módulos ESM nativos sin dependencias npm externas.
 */

import { api } from '../api.js';
import { store } from '../store.js';

export const USER_ROLES = [
  { value: 'TECHNICIAN', label: 'Técnico de Ruta', badgeClass: 'bg-info text-dark' },
  { value: 'COORDINATOR', label: 'Coordinador de Servicios', badgeClass: 'bg-primary' }
];

export const AdminUsersTab = {
  name: 'AdminUsersTab',
  data() {
    return {
      users: [],
      isLoading: false,
      errorMessage: '',
      successMessage: '',

      // Filtros
      filterStatus: 'all', // 'all' | 'active' | 'inactive'
      filterRole: 'all',   // 'all' | 'TECHNICIAN' | 'COORDINATOR'
      searchQuery: '',

      // Modal Alta
      showCreateModal: false,
      createForm: {
        name: '',
        email: '',
        role: 'TECHNICIAN',
        phone: '',
        password: ''
      },
      createErrors: {},
      isSubmittingCreate: false,

      // Modal Edición
      showEditModal: false,
      editForm: {
        id: null,
        name: '',
        email: '',
        role: '',
        role_label: '',
        phone: ''
      },
      editErrors: {},
      isSubmittingEdit: false,

      // Modal Reseteo de Contraseña
      showResetPasswordModal: false,
      resetPasswordForm: {
        id: null,
        name: '',
        email: '',
        new_password: ''
      },
      resetPasswordErrors: {},
      isSubmittingResetPassword: false,

      // Modal Confirmación Baja Lógica
      showDeactivateModal: false,
      userToDeactivate: null,
      deactivateWarning: '',
      isSubmittingDeactivate: false,

      // Reactivación en curso
      isReactivatingId: null
    };
  },
  computed: {
    /**
     * Usuario coordinador en sesión actual desde el store reactivo.
     */
    currentUser() {
      return store?.state?.user || null;
    },

    /**
     * Filtra los usuarios reactivamente por estado, rol y texto de búsqueda.
     */
    filteredUsers() {
      return this.users.filter(u => {
        // Filtro por estado
        if (this.filterStatus === 'active' && !u.is_active) {
          return false;
        }
        if (this.filterStatus === 'inactive' && u.is_active) {
          return false;
        }

        // Filtro por rol
        if (this.filterRole !== 'all' && u.role !== this.filterRole) {
          return false;
        }

        // Filtro por búsqueda
        if (this.searchQuery.trim()) {
          const q = this.searchQuery.trim().toLowerCase();
          const matchName = (u.name || '').toLowerCase().includes(q);
          const matchEmail = (u.email || '').toLowerCase().includes(q);
          const matchPhone = (u.phone || '').toLowerCase().includes(q);
          const matchRole = (u.role_label || u.role || '').toLowerCase().includes(q);

          if (!matchName && !matchEmail && !matchPhone && !matchRole) {
            return false;
          }
        }

        return true;
      });
    },

    /**
     * Conteo de técnicos activos en el sistema.
     */
    activeTechniciansCount() {
      return this.users.filter(u => u.is_active && u.role === 'TECHNICIAN').length;
    },

    /**
     * Conteo de coordinadores activos en el sistema.
     */
    activeCoordinatorsCount() {
      return this.users.filter(u => u.is_active && u.role === 'COORDINATOR').length;
    },

    /**
     * Comprueba si el usuario seleccionado para baja puede ser desactivado (EARS 3.5, 3.6, 3.7).
     */
    canConfirmDeactivate() {
      if (!this.userToDeactivate) return false;

      // 1. Auto-desactivación bloqueada
      if (this.currentUser && Number(this.userToDeactivate.id) === Number(this.currentUser.id)) {
        return false;
      }

      // 2. Bloqueo por averías asignadas pendientes
      const pendingCount = Number(this.userToDeactivate.active_assigned_incidents_count || 0);
      if (this.userToDeactivate.role === 'TECHNICIAN' && pendingCount > 0) {
        return false;
      }

      // 3. Bloqueo por guardia mínima operativa
      if (this.userToDeactivate.role === 'TECHNICIAN' && this.activeTechniciansCount <= 1) {
        return false;
      }
      if (this.userToDeactivate.role === 'COORDINATOR' && this.activeCoordinatorsCount <= 1) {
        return false;
      }

      return true;
    },

    /**
     * Resumen de métricas de personal para la cabecera.
     */
    summaryMetrics() {
      const total = this.users.length;
      const active = this.users.filter(u => u.is_active).length;
      const inactive = total - active;
      const technicians = this.users.filter(u => u.role === 'TECHNICIAN').length;
      const coordinators = this.users.filter(u => u.role === 'COORDINATOR').length;
      const activeAssignedTroubles = this.users.reduce((acc, u) => acc + Number(u.active_assigned_incidents_count || 0), 0);
      return { total, active, inactive, technicians, coordinators, activeAssignedTroubles };
    }
  },
  mounted() {
    this.loadUsers();
  },
  methods: {
    /**
     * Carga el listado de personal interno desde la API administrativa (RF-03).
     */
    async loadUsers() {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        const params = {};
        if (this.filterStatus !== 'all') {
          params.status = this.filterStatus;
        }
        if (this.filterRole !== 'all') {
          params.role = this.filterRole;
        }
        if (this.searchQuery.trim()) {
          params.search = this.searchQuery.trim();
        }

        const res = await (api.admin ? api.admin.getUsers(params) : api.get('/coordinator/users', { params }));
        this.users = res.data || res || [];
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar el personal interno.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Valida formato de correo electrónico.
     */
    validateEmail(email) {
      if (!email || !email.trim()) {
        return 'El correo electrónico es obligatorio.';
      }
      const clean = email.trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(clean)) {
        return 'Introduzca una dirección de correo electrónico válida.';
      }
      return null;
    },

    /**
     * Valida formato de teléfono español (9 dígitos).
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
     * Valida robustez mínima de contraseña (al menos 8 caracteres).
     */
    validatePassword(password) {
      if (!password || password.length < 8) {
        return 'La contraseña debe contener al menos 8 caracteres.';
      }
      return null;
    },

    /**
     * Retorna la etiqueta legible del rol de usuario.
     */
    getRoleLabel(role) {
      const found = USER_ROLES.find(r => r.value === role);
      return found ? found.label : role;
    },

    /**
     * Retorna la clase de estilo para el badge de rol.
     */
    getRoleBadgeClass(role) {
      const found = USER_ROLES.find(r => r.value === role);
      return found ? found.badgeClass : 'bg-secondary';
    },

    /**
     * Abre el modal de alta de personal interno.
     */
    openCreateModal() {
      this.createForm = {
        name: '',
        email: '',
        role: 'TECHNICIAN',
        phone: '',
        password: ''
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
     * Envía la solicitud de alta de nuevo usuario.
     */
    async submitCreate() {
      this.createErrors = {};

      if (!this.createForm.name || !this.createForm.name.trim()) {
        this.createErrors.name = 'El nombre completo es obligatorio.';
      }

      const emailError = this.validateEmail(this.createForm.email);
      if (emailError) this.createErrors.email = emailError;

      const phoneError = this.validatePhone(this.createForm.phone);
      if (phoneError) this.createErrors.phone = phoneError;

      const passError = this.validatePassword(this.createForm.password);
      if (passError) this.createErrors.password = passError;

      if (Object.keys(this.createErrors).length > 0) {
        return;
      }

      this.isSubmittingCreate = true;
      try {
        const payload = {
          name: this.createForm.name.trim(),
          email: this.createForm.email.trim().toLowerCase(),
          role: this.createForm.role,
          phone: this.createForm.phone ? this.createForm.phone.trim().replace(/\s+/g, '') : null,
          password: this.createForm.password
        };

        await (api.admin ? api.admin.createUser(payload) : api.post('/coordinator/users', payload));
        this.showSuccessNotification(`Usuario "${payload.name}" dado de alta exitosamente.`);
        this.closeCreateModal();
        await this.loadUsers();
      } catch (err) {
        if (err.data?.error?.code === 'USER_ALREADY_EXISTS_INACTIVE') {
          this.createErrors.email = 'El correo ya pertenece a un usuario dado de baja previa. Puede reactivarlo desde el filtro de inactivos.';
        } else {
          this.createErrors.general = err.message || 'Error al dar de alta al usuario.';
        }
      } finally {
        this.isSubmittingCreate = false;
      }
    },

    /**
     * Abre el modal de edición de datos de contacto de usuario.
     */
    openEditModal(user) {
      this.editForm = {
        id: user.id,
        name: user.name,
        email: user.email,
        role: user.role,
        role_label: user.role_label || this.getRoleLabel(user.role),
        phone: user.phone || ''
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
     * Envía la actualización de datos de contacto.
     */
    async submitEdit() {
      this.editErrors = {};

      if (!this.editForm.name || !this.editForm.name.trim()) {
        this.editErrors.name = 'El nombre completo es obligatorio.';
      }

      const phoneError = this.validatePhone(this.editForm.phone);
      if (phoneError) this.editErrors.phone = phoneError;

      if (Object.keys(this.editErrors).length > 0) {
        return;
      }

      this.isSubmittingEdit = true;
      try {
        const payload = {
          name: this.editForm.name.trim(),
          phone: this.editForm.phone ? this.editForm.phone.trim().replace(/\s+/g, '') : null
        };

        await (api.admin ? api.admin.updateUser(this.editForm.id, payload) : api.patch(`/coordinator/users/${this.editForm.id}`, payload));
        this.showSuccessNotification(`Datos de "${this.editForm.name}" actualizados correctamente.`);
        this.closeEditModal();
        await this.loadUsers();
      } catch (err) {
        this.editErrors.general = err.message || 'Error al actualizar el usuario.';
      } finally {
        this.isSubmittingEdit = false;
      }
    },

    /**
     * Abre el modal de reseteo administrativo de contraseña (EARS 3.4).
     */
    openResetPasswordModal(user) {
      this.resetPasswordForm = {
        id: user.id,
        name: user.name,
        email: user.email,
        new_password: ''
      };
      this.resetPasswordErrors = {};
      this.showResetPasswordModal = true;
    },

    /**
     * Cierra el modal de reseteo de contraseña.
     */
    closeResetPasswordModal() {
      this.showResetPasswordModal = false;
      this.resetPasswordErrors = {};
    },

    /**
     * Envía la solicitud de reseteo de contraseña.
     */
    async submitResetPassword() {
      this.resetPasswordErrors = {};

      const passError = this.validatePassword(this.resetPasswordForm.new_password);
      if (passError) {
        this.resetPasswordErrors.new_password = passError;
        return;
      }

      this.isSubmittingResetPassword = true;
      try {
        await (api.admin ? api.admin.resetUserPassword(this.resetPasswordForm.id, this.resetPasswordForm.new_password) : api.patch(`/coordinator/users/${this.resetPasswordForm.id}/reset-password`, { new_password: this.resetPasswordForm.new_password }));
        this.showSuccessNotification(`Contraseña restablecida exitosamente para ${this.resetPasswordForm.name}.`);
        this.closeResetPasswordModal();
      } catch (err) {
        this.resetPasswordErrors.general = err.message || 'Error al restablecer la contraseña.';
      } finally {
        this.isSubmittingResetPassword = false;
      }
    },

    /**
     * Abre el diálogo de confirmación de baja lógica (EARS 3.5, 3.6, 3.7).
     */
    openDeactivateModal(user) {
      this.userToDeactivate = user;

      // 1. Auto-desactivación
      if (this.currentUser && Number(user.id) === Number(this.currentUser.id)) {
        this.deactivateWarning = 'Acción denegada: No puede desactivar su propio usuario en sesión activa.';
      }
      // 2. Averías asignadas pendientes
      else if (user.role === 'TECHNICIAN' && Number(user.active_assigned_incidents_count || 0) > 0) {
        const count = user.active_assigned_incidents_count;
        this.deactivateWarning = `No es posible dar de baja al técnico porque tiene ${count} avería(s) activa(s) asignada(s) pendiente(s). Reasigne sus averías desde el panel de triaje antes de darlo de baja.`;
      }
      // 3. Guardia mínima
      else if (user.role === 'TECHNICIAN' && this.activeTechniciansCount <= 1) {
        this.deactivateWarning = 'Guardia mínima: No se puede dar de baja al último técnico activo en la plataforma.';
      }
      else if (user.role === 'COORDINATOR' && this.activeCoordinatorsCount <= 1) {
        this.deactivateWarning = 'Guardia mínima: No se puede dar de baja al único coordinador activo en la plataforma.';
      }
      else {
        this.deactivateWarning = '';
      }

      this.showDeactivateModal = true;
    },

    /**
     * Cierra el diálogo de baja lógica.
     */
    closeDeactivateModal() {
      this.showDeactivateModal = false;
      this.userToDeactivate = null;
      this.deactivateWarning = '';
    },

    /**
     * Confirma la baja lógica del usuario en backend.
     */
    async confirmDeactivate() {
      if (!this.userToDeactivate || !this.canConfirmDeactivate) return;

      this.isSubmittingDeactivate = true;
      try {
        await (api.admin ? api.admin.deactivateUser(this.userToDeactivate.id) : api.patch(`/coordinator/users/${this.userToDeactivate.id}/deactivate`));
        this.showSuccessNotification(`Usuario "${this.userToDeactivate.name}" dado de baja lógica correctamente.`);
        this.closeDeactivateModal();
        await this.loadUsers();
      } catch (err) {
        this.deactivateWarning = err.message || 'Error al desactivar al usuario.';
      } finally {
        this.isSubmittingDeactivate = false;
      }
    },

    /**
     * Reactiva a un usuario previamente dado de baja lógica (EARS 3.10).
     */
    async reactivateUser(user) {
      if (!user || user.is_active) return;

      this.isReactivatingId = user.id;
      try {
        await (api.admin ? api.admin.reactivateUser(user.id) : api.patch(`/coordinator/users/${user.id}/reactivate`));
        this.showSuccessNotification(`Usuario "${user.name}" reactivado exitosamente.`);
        await this.loadUsers();
      } catch (err) {
        this.errorMessage = err.message || 'Error al reactivar al usuario.';
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
    <div class="admin-users-tab">
      <!-- Barra Superior de Resumen y Acciones -->
      <div class="admin-tab-header card mb-4">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
          <div>
            <h3 class="h5 mb-1 text-primary fw-bold">🧑‍🔧 Directorio de Personal Interno</h3>
            <p class="text-muted small mb-0">
              Administración de Técnicos de Ruta y Coordinadores. Reseteo de credenciales, control de averías asignadas y salvaguardas de servicio.
            </p>
          </div>
          <div>
            <button 
              type="button" 
              class="btn btn-primary d-inline-flex align-items-center gap-2"
              @click="openCreateModal"
            >
              <span>➕</span>
              <span>Nuevo Usuario</span>
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
            <div class="col-lg-5 col-md-6">
              <div class="input-group">
                <span class="input-group-text bg-white">🔍</span>
                <input 
                  type="text" 
                  class="form-control" 
                  placeholder="Buscar por nombre, correo o teléfono..."
                  v-model="searchQuery"
                  @input="loadUsers"
                />
                <button 
                  v-if="searchQuery" 
                  class="btn btn-outline-secondary" 
                  type="button" 
                  @click="searchQuery = ''; loadUsers()"
                >✕</button>
              </div>
            </div>

            <!-- Filtro por Estado -->
            <div class="col-lg-3 col-md-3">
              <div class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 small text-muted text-nowrap">Estado:</label>
                <select class="form-select form-select-sm" v-model="filterStatus" @change="loadUsers">
                  <option value="all">Todos los estados</option>
                  <option value="active">Solo Activos</option>
                  <option value="inactive">Solo Bajas</option>
                </select>
              </div>
            </div>

            <!-- Filtro por Rol -->
            <div class="col-lg-4 col-md-3">
              <div class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 small text-muted text-nowrap">Rol:</label>
                <select class="form-select form-select-sm" v-model="filterRole" @change="loadUsers">
                  <option value="all">Todos los roles</option>
                  <option value="TECHNICIAN">🧑‍🔧 Técnicos de Ruta</option>
                  <option value="COORDINATOR">📋 Coordinadores</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Métricas de Resumen de Personal -->
          <div class="d-flex flex-wrap gap-3 mt-3 pt-3 border-top small text-muted">
            <span><strong>Total Plantilla:</strong> {{ summaryMetrics.total }}</span>
            <span class="text-success"><strong>Activos:</strong> {{ summaryMetrics.active }}</span>
            <span class="text-secondary"><strong>De Baja:</strong> {{ summaryMetrics.inactive }}</span>
            <span class="text-info text-dark"><strong>Técnicos:</strong> {{ summaryMetrics.technicians }}</span>
            <span class="text-primary"><strong>Coordinadores:</strong> {{ summaryMetrics.coordinators }}</span>
            <span class="text-warning"><strong>Averías Asignadas en Curso:</strong> {{ summaryMetrics.activeAssignedTroubles }}</span>
          </div>
        </div>
      </div>

      <!-- Tabla de Personal Interno -->
      <div class="card shadow-sm">
        <div class="card-body p-0">
          <div v-if="isLoading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Cargando...</span>
            </div>
            <p class="text-muted small mt-2">Cargando directorio de personal...</p>
          </div>

          <div v-else-if="filteredUsers.length === 0" class="text-center py-5">
            <span class="fs-1">🧑‍🔧</span>
            <h5 class="mt-3 text-muted">No se encontraron usuarios</h5>
            <p class="text-muted small">Pruebe a modificar los filtros o el texto de búsqueda.</p>
          </div>

          <div v-else class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light text-uppercase small text-muted">
                <tr>
                  <th scope="col" class="ps-3" style="min-width: 200px;">Nombre y Contacto</th>
                  <th scope="col" style="min-width: 150px;">Rol</th>
                  <th scope="col" style="min-width: 140px;">Carga de Averías</th>
                  <th scope="col" style="width: 120px;">Estado</th>
                  <th scope="col" class="text-end pe-3" style="width: 250px;">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="u in filteredUsers" :key="u.id" :class="{'table-light opacity-75': !u.is_active}">
                  <!-- Nombre y Contacto -->
                  <td class="ps-3">
                    <div class="d-flex align-items-center gap-2">
                      <div class="fw-bold text-dark">{{ u.name }}</div>
                      <span v-if="currentUser && currentUser.id === u.id" class="badge bg-primary-subtle text-primary border border-primary-subtle small">Tú</span>
                    </div>
                    <div class="text-muted small">
                      <span>✉️ {{ u.email }}</span>
                      <span v-if="u.phone" class="ms-2">📞 {{ u.phone }}</span>
                    </div>
                  </td>

                  <!-- Rol -->
                  <td>
                    <span class="badge" :class="getRoleBadgeClass(u.role)">
                      {{ u.role_label || getRoleLabel(u.role) }}
                    </span>
                  </td>

                  <!-- Carga de Averías Asignadas -->
                  <td>
                    <template v-if="u.role === 'TECHNICIAN'">
                      <span 
                        v-if="Number(u.active_assigned_incidents_count || 0) > 0" 
                        class="badge bg-warning text-dark fw-bold"
                        :title="u.active_assigned_incidents_count + ' averías asignadas pendientes'"
                      >
                        ⚠️ {{ u.active_assigned_incidents_count }} avería(s)
                      </span>
                      <span v-else class="badge bg-success-subtle text-success">
                        0 pendientes
                      </span>
                    </template>
                    <template v-else>
                      <span class="text-muted small">-</span>
                    </template>
                  </td>

                  <!-- Estado Operativo -->
                  <td>
                    <span v-if="u.is_active" class="badge bg-success">Activo</span>
                    <span v-else class="badge bg-secondary">Baja Lógica</span>
                  </td>

                  <!-- Acciones -->
                  <td class="text-end pe-3">
                    <div class="btn-group btn-group-sm">
                      <!-- Acciones para usuario activo -->
                      <template v-if="u.is_active">
                        <button 
                          type="button" 
                          class="btn btn-outline-primary"
                          title="Editar datos de contacto"
                          @click="openEditModal(u)"
                        >
                          ✏️ Editar
                        </button>
                        <button 
                          type="button" 
                          class="btn btn-outline-secondary"
                          title="Restablecer contraseña de acceso"
                          @click="openResetPasswordModal(u)"
                        >
                          🔑 Clave
                        </button>
                        <button 
                          type="button" 
                          class="btn btn-outline-danger"
                          title="Dar de baja lógica"
                          @click="openDeactivateModal(u)"
                        >
                          🚫 Baja
                        </button>
                      </template>

                      <!-- Acciones para usuario inactivo -->
                      <template v-else>
                        <button 
                          type="button" 
                          class="btn btn-outline-success"
                          title="Reactivar usuario"
                          :disabled="isReactivatingId === u.id"
                          @click="reactivateUser(u)"
                        >
                          <span v-if="isReactivatingId === u.id" class="spinner-border spinner-border-sm me-1" role="status"></span>
                          <span>🔄 Reactivar</span>
                        </button>
                      </template>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- MODAL ALTA DE USUARIO -->
      <div v-if="showCreateModal" class="modal-backdrop fade show" style="background-color: rgba(0,0,0,0.5); position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: 1040;"></div>
      <div v-if="showCreateModal" class="modal fade show d-block" tabindex="-1" style="z-index: 1050;" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
          <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
              <h5 class="modal-title fw-bold">➕ Alta de Personal Interno</h5>
              <button type="button" class="btn-close btn-close-white" @click="closeCreateModal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <div v-if="createErrors.general" class="alert alert-danger mb-3 small">
                {{ createErrors.general }}
              </div>

              <form @submit.prevent="submitCreate">
                <div class="row g-3">
                  <!-- Nombre Completo -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Nombre Completo <span class="text-danger">*</span></label>
                    <input 
                      type="text" 
                      class="form-control" 
                      :class="{'is-invalid': createErrors.name}"
                      v-model="createForm.name"
                      placeholder="Ej: Laura Martínez"
                      maxlength="100"
                    />
                    <div v-if="createErrors.name" class="invalid-feedback small">{{ createErrors.name }}</div>
                  </div>

                  <!-- Correo Electrónico -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Correo Electrónico <span class="text-danger">*</span></label>
                    <input 
                      type="email" 
                      class="form-control font-monospace" 
                      :class="{'is-invalid': createErrors.email}"
                      v-model="createForm.email"
                      placeholder="laura.tec@vendguard.internal"
                      maxlength="100"
                    />
                    <div v-if="createErrors.email" class="invalid-feedback small">{{ createErrors.email }}</div>
                    <div class="form-text small">Identificador inmutable para inicio de sesión.</div>
                  </div>

                  <!-- Rol Operativo -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Rol Operativo <span class="text-danger">*</span></label>
                    <select class="form-select" v-model="createForm.role">
                      <option value="TECHNICIAN">🧑‍🔧 Técnico de Ruta</option>
                      <option value="COORDINATOR">📋 Coordinador de Servicios</option>
                    </select>
                    <div class="form-text small">El rol es inmutable tras el alta para prevenir escaladas accidentales.</div>
                  </div>

                  <!-- Teléfono Móvil -->
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Teléfono Móvil (Avisos)</label>
                    <input 
                      type="tel" 
                      class="form-control font-monospace" 
                      :class="{'is-invalid': createErrors.phone}"
                      v-model="createForm.phone"
                      placeholder="Ej: 611223344"
                      maxlength="15"
                    />
                    <div v-if="createErrors.phone" class="invalid-feedback small">{{ createErrors.phone }}</div>
                  </div>

                  <!-- Contraseña Inicial -->
                  <div class="col-12">
                    <label class="form-label small fw-bold">Contraseña Inicial <span class="text-danger">*</span></label>
                    <input 
                      type="password" 
                      class="form-control font-monospace" 
                      :class="{'is-invalid': createErrors.password}"
                      v-model="createForm.password"
                      placeholder="Mínimo 8 caracteres..."
                      maxlength="100"
                    />
                    <div v-if="createErrors.password" class="invalid-feedback small">{{ createErrors.password }}</div>
                    <div class="form-text small">Se almacenará mediante hash Bcrypt seguro (RNF-04).</div>
                  </div>
                </div>

                <div class="modal-footer px-0 pb-0 pt-3 border-top mt-3">
                  <button type="button" class="btn btn-secondary" @click="closeCreateModal">Cancelar</button>
                  <button type="submit" class="btn btn-primary" :disabled="isSubmittingCreate">
                    <span v-if="isSubmittingCreate" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    <span>Dar de Alta Usuario</span>
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- MODAL EDICIÓN DE CONTACTO -->
      <div v-if="showEditModal" class="modal-backdrop fade show" style="background-color: rgba(0,0,0,0.5); position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: 1040;"></div>
      <div v-if="showEditModal" class="modal fade show d-block" tabindex="-1" style="z-index: 1050;" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
              <h5 class="modal-title fw-bold">✏️ Editar Usuario: {{ editForm.name }}</h5>
              <button type="button" class="btn-close btn-close-white" @click="closeEditModal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <div v-if="editErrors.general" class="alert alert-danger mb-3 small">
                {{ editErrors.general }}
              </div>

              <form @submit.prevent="submitEdit">
                <!-- Correo (Inmutable) -->
                <div class="mb-3">
                  <label class="form-label small fw-bold">Correo Electrónico (Inmutable)</label>
                  <input type="text" class="form-control font-monospace bg-light" :value="editForm.email" disabled />
                </div>

                <!-- Rol (Inmutable) -->
                <div class="mb-3">
                  <label class="form-label small fw-bold">Rol Operativo (Inmutable)</label>
                  <input type="text" class="form-control bg-light" :value="editForm.role_label" disabled />
                </div>

                <!-- Nombre Completo -->
                <div class="mb-3">
                  <label class="form-label small fw-bold">Nombre Completo <span class="text-danger">*</span></label>
                  <input 
                    type="text" 
                    class="form-control" 
                    :class="{'is-invalid': editErrors.name}"
                    v-model="editForm.name"
                    maxlength="100"
                  />
                  <div v-if="editErrors.name" class="invalid-feedback small">{{ editErrors.name }}</div>
                </div>

                <!-- Teléfono Móvil -->
                <div class="mb-3">
                  <label class="form-label small fw-bold">Teléfono Móvil</label>
                  <input 
                    type="tel" 
                    class="form-control font-monospace" 
                    :class="{'is-invalid': editErrors.phone}"
                    v-model="editForm.phone"
                    maxlength="15"
                  />
                  <div v-if="editErrors.phone" class="invalid-feedback small">{{ editErrors.phone }}</div>
                </div>

                <div class="modal-footer px-0 pb-0 pt-3 border-top">
                  <button type="button" class="btn btn-secondary" @click="closeEditModal">Cancelar</button>
                  <button type="submit" class="btn btn-primary" :disabled="isSubmittingEdit">
                    <span v-if="isSubmittingEdit" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    <span>Actualizar Usuario</span>
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- MODAL RESETEO DE CONTRASEÑA (EARS 3.4) -->
      <div v-if="showResetPasswordModal" class="modal-backdrop fade show" style="background-color: rgba(0,0,0,0.5); position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: 1040;"></div>
      <div v-if="showResetPasswordModal" class="modal fade show d-block" tabindex="-1" style="z-index: 1050;" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow">
            <div class="modal-header bg-secondary text-white">
              <h5 class="modal-title fw-bold">🔑 Restablecer Contraseña: {{ resetPasswordForm.name }}</h5>
              <button type="button" class="btn-close btn-close-white" @click="closeResetPasswordModal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <div class="alert alert-info small mb-3">
                <span>
                  El restablecimiento de contraseña actualizará de forma segura la credencial del usuario e invalidará sus tokens previos para forzar su reautenticación. No afectará a sus averías asignadas ni al historial de la plataforma.
                </span>
              </div>

              <div v-if="resetPasswordErrors.general" class="alert alert-danger mb-3 small">
                {{ resetPasswordErrors.general }}
              </div>

              <form @submit.prevent="submitResetPassword">
                <div class="mb-3">
                  <label class="form-label small fw-bold">Nueva Contraseña <span class="text-danger">*</span></label>
                  <input 
                    type="password" 
                    class="form-control font-monospace" 
                    :class="{'is-invalid': resetPasswordErrors.new_password}"
                    v-model="resetPasswordForm.new_password"
                    placeholder="Mínimo 8 caracteres..."
                    maxlength="100"
                  />
                  <div v-if="resetPasswordErrors.new_password" class="invalid-feedback small">{{ resetPasswordErrors.new_password }}</div>
                </div>

                <div class="modal-footer px-0 pb-0 pt-3 border-top">
                  <button type="button" class="btn btn-secondary" @click="closeResetPasswordModal">Cancelar</button>
                  <button type="submit" class="btn btn-primary" :disabled="isSubmittingResetPassword">
                    <span v-if="isSubmittingResetPassword" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    <span>Actualizar Contraseña</span>
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- MODAL CONFIRMACIÓN DE BAJA LÓGICA -->
      <div v-if="showDeactivateModal && userToDeactivate" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5); z-index: 1050;" role="dialog" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
              <h5 class="modal-title fw-bold">⚠️ Confirmar Baja Lógica de Personal</h5>
              <button type="button" class="btn-close btn-close-white" @click="closeDeactivateModal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <!-- Alerta bloqueante si no puede desactivarse -->
              <div v-if="!canConfirmDeactivate" class="alert alert-danger d-flex align-items-start gap-2 mb-3">
                <span class="fs-4">🛑</span>
                <div>
                  <h6 class="fw-bold mb-1">Baja Bloqueada por Salvaguarda Operativa</h6>
                  <p class="small mb-0">{{ deactivateWarning }}</p>
                </div>
              </div>

              <!-- Mensaje informativo normal si está permitido -->
              <div v-else class="alert alert-warning mb-3 small">
                <span>
                  Está a punto de dar de baja lógica al usuario <strong>{{ userToDeactivate.name }}</strong> ({{ userToDeactivate.email }}).
                  Conforme a la Constitución (Art. III.1), su historial de averías resueltas permanecerá intacto y la cuenta podrá ser reactivada en cualquier momento.
                </span>
              </div>

              <p class="small text-muted mb-0">
                <strong>Rol:</strong> {{ userToDeactivate.role_label || getRoleLabel(userToDeactivate.role) }}<br>
                <strong>Averías activas asignadas:</strong> {{ userToDeactivate.active_assigned_incidents_count || 0 }}
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

export default AdminUsersTab;
