/**
 * VendGuard - CoordinatorPreventiveOrdersTab (CoordinatorPreventiveOrdersTab.js)
 * 
 * Componente Vue 3 ESM para la gestión de Órdenes de Mantenimiento Preventivo (RF-PREV-02).
 * 
 * Características:
 * 1. Tabla reactiva de órdenes de inspección con filtros por estado, sede y búsqueda de texto.
 * 2. Visualización clara de estados (PENDING_ASSIGNMENT, SCHEDULED, IN_INSPECTION, COMPLETED, EXPIRED, CANCELLED).
 * 3. Distintivo higiénico para máquinas de alimentos perecederos (Art. II Constitución).
 * 4. Modal de Asignación Técnica con selector de técnico y fecha programada.
 * 5. Modal de Cancelación Lógica Justificada (Art. III: Soft Delete sin borrado destructivo).
 * 6. Modal de Creación Manual o Extraordinaria de orden preventiva.
 * 7. Botón de Generación Rápida de Preventivos Inminentes (horizonte de 5 días).
 * 
 * Dogma Vanilla: Cero dependencias externas (Vue 3 ESM nativo).
 * Dualismo Lingüístico: Código en inglés, interfaz y mensajes en español.
 */

import { api } from '../api.js';

import { CoordinatorPreventiveOrderDetailModal } from './CoordinatorPreventiveOrderDetailModal.js';
import {
  getOrderResultBadge,
  getOrderStatusBadge,
  getOrderTypeLabel
} from '../utils/PreventiveLabels.js';

export const CoordinatorPreventiveOrdersTab = {
  name: 'CoordinatorPreventiveOrdersTab',
  components: {
    CoordinatorPreventiveOrderDetailModal
  },
  emits: ['order-assigned', 'order-cancelled', 'order-created', 'open-incident-detail'],
  data() {
    return {
      orders: [],
      locations: [],
      technicians: [],
      machines: [],
      isLoading: false,
      isActionLoading: false,
      errorMessage: '',
      successMessage: '',

      // Filtros
      filterStatus: '',
      filterLocationId: '',
      filterSearch: '',
      page: 1,
      perPage: 50,
      totalCount: 0,

      // Modal de Asignación
      showAssignModal: false,
      selectedOrderToAssign: null,
      assignForm: {
        technician_id: '',
        scheduled_date: ''
      },
      assignError: '',

      // Modal de Detalle Integral de la Orden (RF-PD-01, solo consulta)
      showDetailModal: false,
      detailOrderId: null,

      // Modal de Cancelación (Art. III)
      showCancelModal: false,
      selectedOrderToCancel: null,
      cancelForm: {
        reason: ''
      },
      cancelError: '',

      // Modal de Creación Manual
      showCreateModal: false,
      createForm: {
        machine_id: '',
        order_type: 'MANUAL_EXTRA',
        scheduled_date: '',
        due_date: '',
        assigned_technician_id: '',
        notes: ''
      },
      createError: ''
    };
  },
  computed: {
    filteredOrders() {
      return this.orders.filter(order => {
        // Filtro por estado
        if (this.filterStatus && order.status !== this.filterStatus) {
          return false;
        }

        // Filtro por sede
        if (this.filterLocationId && Number(order.location_id) !== Number(this.filterLocationId)) {
          return false;
        }

        // Filtro por texto
        if (this.filterSearch.trim()) {
          const q = this.filterSearch.trim().toLowerCase();
          const matchCode = (order.order_code || '').toLowerCase().includes(q);
          const machData = order.machine || {};
          const matchMachCode = (machData.code || '').toLowerCase().includes(q);
          const matchMachModel = (machData.model || '').toLowerCase().includes(q);
          const locData = order.location || {};
          const matchLoc = (locData.name || '').toLowerCase().includes(q);
          const techData = order.technician || {};
          const matchTech = (techData.name || '').toLowerCase().includes(q);

          if (!matchCode && !matchMachCode && !matchMachModel && !matchLoc && !matchTech) {
            return false;
          }
        }

        return true;
      });
    }
  },
  mounted() {
    this.loadInitialData();
  },
  methods: {
    async loadInitialData() {
      await Promise.all([
        this.loadOrders(),
        this.loadLocations(),
        this.loadTechnicians(),
        this.loadMachines()
      ]);
    },

    /**
     * Carga el listado de órdenes preventivas desde la API
     */
    async loadOrders() {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        const filters = {
          page: this.page,
          per_page: this.perPage
        };
        if (this.filterStatus) filters.status = this.filterStatus;
        if (this.filterLocationId) filters.location_id = this.filterLocationId;
        if (this.filterSearch.trim()) filters.search = this.filterSearch.trim();

        const res = await api.coordinator.getPreventiveOrders(filters);
        this.orders = Array.isArray(res) ? res : (res?.data || []);
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar el listado de órdenes preventivas.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Carga el catálogo de sedes
     */
    async loadLocations() {
      try {
        const res = await api.coordinator.getLocations();
        this.locations = Array.isArray(res) ? res : (res?.data || []);
      } catch (err) {
        console.warn('No se pudieron cargar las sedes para filtros:', err);
      }
    },

    /**
     * Carga el personal técnico activo disponible (EARS 3.9: el personal dado de baja
     * queda excluido de las listas de asignación). Sin listas simuladas.
     */
    async loadTechnicians() {
      try {
        const getter = api.coordinator.getUsers || api.admin.getUsers;
        const res = await getter({ status: 'active', role: 'TECHNICIAN' });
        this.technicians = Array.isArray(res) ? res : (res?.data || []);
      } catch (err) {
        console.warn('No se pudieron cargar los técnicos:', err);
      }
    },

    /**
     * Carga el parque de máquinas para asignaciones y creación manual
     */
    async loadMachines() {
      try {
        const getter = api.coordinator.getMachines || api.admin.getMachines;
        const res = await getter();
        this.machines = Array.isArray(res) ? res : (res?.data || []);
      } catch (err) {
        console.warn('No se pudieron cargar las máquinas:', err);
      }
    },

    /**
     * Generar órdenes para máquinas próximas a vencer (RF-PREV-02)
     */
    async handleGenerateDue() {
      this.isActionLoading = true;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        const res = await api.coordinator.generateDuePreventiveOrders(5);
        const count = res?.data?.orders_generated_count ?? res?.orders_generated_count ?? 0;
        this.successMessage = `Se han generado ${count} nuevas órdenes preventivas inminentes.`;
        await this.loadOrders();
      } catch (err) {
        this.errorMessage = err.message || 'Error al generar las órdenes preventivas.';
      } finally {
        this.isActionLoading = false;
      }
    },

    // =========================================================================
    // MODAL DE ASIGNACIÓN
    // =========================================================================

    openAssignModal(order) {
      this.selectedOrderToAssign = order;
      this.assignForm.technician_id = order.assigned_technician_id || '';
      this.assignForm.scheduled_date = order.scheduled_date || new Date().toISOString().split('T')[0];
      this.assignError = '';
      this.showAssignModal = true;
    },

    closeAssignModal() {
      this.showAssignModal = false;
      this.selectedOrderToAssign = null;
      this.assignError = '';
    },

    async submitAssignOrder() {
      if (!this.assignForm.technician_id) {
        this.assignError = 'Debe seleccionar un técnico de ruta.';
        return;
      }
      if (!this.assignForm.scheduled_date) {
        this.assignError = 'Debe indicar la fecha programada de inspección.';
        return;
      }

      this.isActionLoading = true;
      this.assignError = '';

      try {
        await api.coordinator.assignPreventiveOrder(
          this.selectedOrderToAssign.id,
          this.assignForm.technician_id,
          this.assignForm.scheduled_date
        );

        this.successMessage = `Orden ${this.selectedOrderToAssign.order_code} asignada correctamente.`;
        this.closeAssignModal();
        await this.loadOrders();
        this.$emit('order-assigned');
      } catch (err) {
        this.assignError = err.message || 'No fue posible asignar la orden preventiva.';
      } finally {
        this.isActionLoading = false;
      }
    },

    // =========================================================================
    // MODAL DE CANCELACIÓN LÓGICA (Art. III)
    // =========================================================================

    openCancelModal(order) {
      this.selectedOrderToCancel = order;
      this.cancelForm.reason = '';
      this.cancelError = '';
      this.showCancelModal = true;
    },

    closeCancelModal() {
      this.showCancelModal = false;
      this.selectedOrderToCancel = null;
      this.cancelError = '';
    },

    async submitCancelOrder() {
      const reason = this.cancelForm.reason.trim();
      if (!reason) {
        this.cancelError = 'Debe indicar obligatoriamente el motivo justificado de cancelación (Art. III).';
        return;
      }

      this.isActionLoading = true;
      this.cancelError = '';

      try {
        await api.coordinator.cancelPreventiveOrder(this.selectedOrderToCancel.id, reason);
        this.successMessage = `Orden ${this.selectedOrderToCancel.order_code} cancelada lógicamente (Art. III).`;
        this.closeCancelModal();
        await this.loadOrders();
        this.$emit('order-cancelled');
      } catch (err) {
        this.cancelError = err.message || 'No fue posible cancelar la orden preventiva.';
      } finally {
        this.isActionLoading = false;
      }
    },

    // =========================================================================
    // MODAL DE CREACIÓN MANUAL
    // =========================================================================

    async openCreateModal() {
      this.createForm = {
        machine_id: '',
        order_type: 'MANUAL_EXTRA',
        scheduled_date: new Date().toISOString().split('T')[0],
        due_date: new Date(Date.now() + 7 * 86400000).toISOString().split('T')[0],
        assigned_technician_id: '',
        notes: ''
      };
      this.createError = '';
      this.showCreateModal = true;

      // Cargar máquinas si no están cargadas
      if (this.machines.length === 0) {
        await this.loadMachines();
      }
    },

    closeCreateModal() {
      this.showCreateModal = false;
      this.createError = '';
    },

    async submitCreateOrder() {
      if (!this.createForm.machine_id) {
        this.createError = 'Debe seleccionar una máquina.';
        return;
      }

      this.isActionLoading = true;
      this.createError = '';

      try {
        const payload = {
          machine_id: Number(this.createForm.machine_id),
          order_type: this.createForm.order_type,
          scheduled_date: this.createForm.scheduled_date || undefined,
          due_date: this.createForm.due_date || undefined,
          notes: this.createForm.notes || undefined
        };
        if (this.createForm.assigned_technician_id) {
          payload.assigned_technician_id = Number(this.createForm.assigned_technician_id);
        }

        await api.coordinator.createPreventiveOrder(payload);
        this.successMessage = 'Orden preventiva creada con éxito.';
        this.closeCreateModal();
        await this.loadOrders();
        this.$emit('order-created');
      } catch (err) {
        this.createError = err.message || 'No se pudo crear la orden preventiva.';
      } finally {
        this.isActionLoading = false;
      }
    },

    // =========================================================================
    // HELPERS VISUALES
    // =========================================================================

    /**
     * Delegado en el util compartido: la pestaña y la ficha de detalle muestran
     * exactamente las mismas etiquetas y colores (fuente única, RF-PD-02).
     */
    getStatusBadge(status) {
      return getOrderStatusBadge(status);
    },

    getResultBadge(result) {
      return getOrderResultBadge(result);
    },

    getOrderTypeLabel(type) {
      return getOrderTypeLabel(type);
    },

    /**
     * Abre la ficha integral de la orden seleccionada (RF-PD-01.1). Es una lectura:
     * no altera la orden ni sus dependencias de escritura de la fila.
     */
    openDetailModal(order) {
      if (!order) {
        return;
      }
      this.detailOrderId = order.id ?? order.order_code ?? null;
      this.showDetailModal = true;
    },

    closeDetailModal() {
      this.showDetailModal = false;
      this.detailOrderId = null;
    },

    /**
     * La ficha preventiva solicita el salto a la avería correctiva vinculada
     * (RF-PD-07.2): la vista de Coordinación abre el modal de detalle de incidencias.
     */
    handleOpenIncidentDetail(incidentId) {
      this.$emit('open-incident-detail', incidentId);
    }
  },
  template: `
    <div class="vg-preventive-orders-tab" style="display: flex; flex-direction: column; gap: 20px;">
      <!-- 1. Cabecera y Barra de Filtros / Acciones -->
      <div style="background: #ffffff; padding: 20px; border-radius: var(--radius-card, 8px); border: 1px solid var(--color-border, #e2e8f0); box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; flex-direction: column; gap: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
          <div>
            <h3 style="margin: 0 0 4px; font-size: 18px; font-family: var(--font-display, 'DM Sans', sans-serif); color: var(--color-ink, #1e293b);">
              📋 Órdenes de Mantenimiento Preventivo
            </h3>
            <p style="margin: 0; font-size: 13px; color: var(--color-ink-muted, #64748b);">
              Gestión centralizada de revisiones periódicas, asignación técnica y cancelaciones lógicas (RF-PREV-02, Art. III).
            </p>
          </div>

          <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button
              type="button"
              class="vg-btn"
              style="background: #2560ff; color: #ffffff; border: 1px solid #1a4cd8; height: 38px; padding: 0 14px; font-weight: 600; border-radius: var(--radius-interactive, 4px); cursor: pointer; display: inline-flex; align-items: center; gap: 6px;"
              :disabled="isActionLoading"
              @click="handleGenerateDue"
              data-testid="btn-orders-generate-due"
            >
              ⚡ Generar Preventivos (5d)
            </button>

            <button
              type="button"
              class="vg-btn"
              style="background: #ffffff; color: var(--color-ink, #1e293b); border: 1px solid var(--color-border, #cbd5e1); height: 38px; padding: 0 14px; font-weight: 600; border-radius: var(--radius-interactive, 4px); cursor: pointer; display: inline-flex; align-items: center; gap: 6px;"
              @click="openCreateModal"
              data-testid="btn-create-order"
            >
              ➕ Nueva Orden Manual
            </button>

            <button
              type="button"
              class="vg-btn"
              style="background: #f8fafc; color: var(--color-ink, #334155); border: 1px solid var(--color-border, #cbd5e1); height: 38px; padding: 0 12px; font-weight: 600; border-radius: var(--radius-interactive, 4px); cursor: pointer;"
              :disabled="isLoading"
              @click="loadOrders"
              title="Recargar órdenes"
            >
              🔄
            </button>
          </div>
        </div>

        <!-- Barra de Filtros -->
        <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
          <!-- Filtro por Estado -->
          <div style="display: flex; flex-direction: column; gap: 4px; min-width: 170px;">
            <label style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Estado</label>
            <select
              v-model="filterStatus"
              @change="loadOrders"
              class="vg-input"
              style="height: 36px; padding: 0 8px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 13px;"
              data-testid="filter-status"
            >
              <option value="">Todos los estados</option>
              <option value="PENDING_ASSIGNMENT">⏳ Pendiente Asignación</option>
              <option value="SCHEDULED">📅 Programada</option>
              <option value="IN_INSPECTION">🔍 En Inspección</option>
              <option value="COMPLETED">✅ Completada</option>
              <option value="EXPIRED">🔴 Vencida</option>
              <option value="CANCELLED">✕ Cancelada</option>
            </select>
          </div>

          <!-- Filtro por Sede -->
          <div style="display: flex; flex-direction: column; gap: 4px; min-width: 180px;">
            <label style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Sede / Edificio</label>
            <select
              v-model="filterLocationId"
              @change="loadOrders"
              class="vg-input"
              style="height: 36px; padding: 0 8px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 13px;"
              data-testid="filter-location"
            >
              <option value="">Todas las sedes</option>
              <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                {{ loc.site_code }} - {{ loc.name }}
              </option>
            </select>
          </div>

          <!-- Buscador por Texto -->
          <div style="display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 200px;">
            <label style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Búsqueda</label>
            <input
              type="text"
              v-model="filterSearch"
              placeholder="Buscar por código (PREV-, VEND-), modelo, sede..."
              class="vg-input"
              style="height: 36px; padding: 0 10px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 13px;"
              @keyup.enter="loadOrders"
              data-testid="filter-search"
            />
          </div>
        </div>
      </div>

      <!-- Alertas de éxito y error -->
      <div v-if="successMessage" style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 12px 16px; border-radius: var(--radius-interactive, 4px); font-size: 14px; display: flex; justify-content: space-between; align-items: center;">
        <span>✅ {{ successMessage }}</span>
        <button type="button" @click="successMessage = ''" style="background: none; border: none; font-size: 16px; cursor: pointer; color: #166534;">✕</button>
      </div>

      <div v-if="errorMessage" style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 12px 16px; border-radius: var(--radius-interactive, 4px); font-size: 14px; display: flex; justify-content: space-between; align-items: center;">
        <span>⚠️ {{ errorMessage }}</span>
        <button type="button" @click="errorMessage = ''" style="background: none; border: none; font-size: 16px; cursor: pointer; color: #991b1b;">✕</button>
      </div>

      <!-- 2. Tabla de Órdenes Preventivas -->
      <div style="background: #ffffff; border-radius: var(--radius-card, 8px); border: 1px solid var(--color-border, #e2e8f0); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div v-if="isLoading" style="text-align: center; padding: 40px; color: #64748b;">
          ⏳ Cargando órdenes preventivas...
        </div>

        <div v-else-if="filteredOrders.length === 0" style="text-align: center; padding: 40px; color: #64748b;" data-testid="orders-empty">
          📭 No se encontraron órdenes preventivas que coincidan con los filtros seleccionados.
        </div>

        <div v-else style="overflow-x: auto;">
          <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;" data-testid="orders-table">
            <thead>
              <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; color: #475569; font-weight: 700;">
                <th style="padding: 12px 16px;">Código</th>
                <th style="padding: 12px 16px;">Máquina / Tipo</th>
                <th style="padding: 12px 16px;">Sede / Ubicación</th>
                <th style="padding: 12px 16px;">Estado</th>
                <th style="padding: 12px 16px;">Tipo Orden</th>
                <th style="padding: 12px 16px;">Programada / Límite</th>
                <th style="padding: 12px 16px;">Técnico Asignado</th>
                <th style="padding: 12px 16px;">Dictamen</th>
                <th style="padding: 12px 16px; text-align: right;">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="order in filteredOrders"
                :key="order.id"
                style="border-bottom: 1px solid #e2e8f0; transition: background 0.15s ease;"
                :style="{ background: order.status === 'EXPIRED' ? '#fff5f5' : order.is_quarantine_triggered ? '#fdf2f8' : 'transparent' }"
                data-testid="order-row"
              >
                <!-- Código de Orden -->
                <td style="padding: 12px 16px; font-weight: 700; color: #1e293b;">
                  {{ order.order_code }}
                </td>

                <!-- Máquina / Tipo -->
                <td style="padding: 12px 16px;">
                  <div style="font-weight: 600; color: #0f172a;">
                    {{ order.machine?.code || ('ID #' + order.machine_id) }}
                  </div>
                  <div style="font-size: 12px; color: #64748b;">
                    {{ order.machine?.model || '' }}
                  </div>
                  <!-- Distintivo Perecedero (Art. II) -->
                  <span
                    v-if="order.machine?.is_perishable || order.machine?.machine_type === 'PERISHABLE_FOOD'"
                    style="display: inline-block; font-size: 10px; font-weight: bold; background: #fee2e2; color: #991b1b; padding: 1px 6px; border-radius: 4px; margin-top: 2px;"
                    title="Alimento Perecedero - Prioridad Máxima (Art. II)"
                  >
                    🥩 Perecedero
                  </span>
                </td>

                <!-- Sede / Ubicación -->
                <td style="padding: 12px 16px;">
                  <div style="font-weight: 500; color: #1e293b;">
                    {{ order.location?.name || ('Sede #' + order.location_id) }}
                  </div>
                  <div style="font-size: 12px; color: #64748b;">
                    {{ order.location?.floor_wing || order.machine?.floor_wing || '' }}
                  </div>
                </td>

                <!-- Estado -->
                <td style="padding: 12px 16px;">
                  <span
                    :style="{
                      background: getStatusBadge(order.status).bg,
                      color: getStatusBadge(order.status).color,
                      padding: '4px 8px',
                      borderRadius: '12px',
                      fontSize: '11px',
                      fontWeight: '700',
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: '4px'
                    }"
                  >
                    <span>{{ getStatusBadge(order.status).icon }}</span>
                    {{ getStatusBadge(order.status).label }}
                  </span>
                </td>

                <!-- Tipo Orden -->
                <td style="padding: 12px 16px; color: #475569;">
                  {{ getOrderTypeLabel(order.order_type) }}
                </td>

                <!-- Fechas -->
                <td style="padding: 12px 16px; font-size: 12px;">
                  <div>📅 {{ order.scheduled_date || 'Sin fecha' }}</div>
                  <div style="color: #64748b;">⏰ Límite: {{ order.due_date || 'N/A' }}</div>
                </td>

                <!-- Técnico Asignado -->
                <td style="padding: 12px 16px;">
                  <div v-if="order.technician?.name || order.assigned_technician_id" style="font-weight: 500; color: #1e293b;">
                    👤 {{ order.technician?.name || ('Técnico #' + order.assigned_technician_id) }}
                    <span v-if="order.technician?.operator_code" style="font-size: 11px; color: #64748b; margin-left: 4px;">
                      ({{ order.technician.operator_code }})
                    </span>
                  </div>
                  <div v-else style="font-style: italic; color: #94a3b8; font-size: 12px;">
                    Sin asignar
                  </div>
                </td>

                <!-- Dictamen / Resultado -->
                <td style="padding: 12px 16px;">
                  <span
                    v-if="getResultBadge(order.result)"
                    :style="{
                      background: getResultBadge(order.result).bg,
                      color: getResultBadge(order.result).color,
                      padding: '3px 8px',
                      borderRadius: '4px',
                      fontSize: '11px',
                      fontWeight: '700'
                    }"
                  >
                    {{ getResultBadge(order.result).label }}
                  </span>
                  <span v-else style="color: #94a3b8; font-size: 12px;">—</span>
                </td>

                <!-- Acciones -->
                <td style="padding: 12px 16px; text-align: right; white-space: nowrap;">
                  <!-- Botón Ver detalle (RF-PD-01): lectura pura, disponible en cualquier estado -->
                  <button
                    type="button"
                    class="vg-btn"
                    style="background: #eef2ff; color: #1e3a8a; border: 1px solid #c7d2fe; padding: 4px 8px; font-size: 12px; border-radius: 4px; cursor: pointer; margin-right: 6px;"
                    @click.stop="openDetailModal(order)"
                    title="Abrir la ficha integral de la orden preventiva"
                    data-testid="btn-view-order-detail"
                  >
                    🔍 Ver detalle
                  </button>

                  <!-- Botón Asignar -->
                  <button
                    v-if="['PENDING_ASSIGNMENT', 'SCHEDULED', 'EXPIRED'].includes(order.status)"
                    type="button"
                    class="vg-btn"
                    style="background: #f1f5f9; color: #1e293b; border: 1px solid #cbd5e1; padding: 4px 8px; font-size: 12px; border-radius: 4px; cursor: pointer; margin-right: 6px;"
                    @click="openAssignModal(order)"
                    title="Asignar o reprogramar técnico"
                    data-testid="btn-assign-order"
                  >
                    👤 Asignar
                  </button>

                  <!-- Botón Cancelar Lógico (Art. III) -->
                  <button
                    v-if="!['COMPLETED', 'CANCELLED'].includes(order.status)"
                    type="button"
                    class="vg-btn"
                    style="background: #fff5f5; color: #b91c1c; border: 1px solid #fecaca; padding: 4px 8px; font-size: 12px; border-radius: 4px; cursor: pointer;"
                    @click="openCancelModal(order)"
                    title="Cancelar orden con justificación histórica"
                    data-testid="btn-cancel-order"
                  >
                    ✕ Cancelar
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ================================================================= -->
      <!-- MODAL: ASIGNACIÓN TÉCNICA (RF-PREV-02, EARS 2.2)                   -->
      <!-- ================================================================= -->
      <!-- ================================================================= -->
      <!-- MODAL: FICHA INTEGRAL DE LA ORDEN (RF-PD-01, solo consulta)         -->
      <!-- ================================================================= -->
      <CoordinatorPreventiveOrderDetailModal
        :is-open="showDetailModal"
        :order-id="detailOrderId"
        @close="closeDetailModal"
        @open-incident-detail="handleOpenIncidentDetail"
      />

      <div
        v-if="showAssignModal"
        style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px;"
        data-testid="modal-assign"
      >
        <div style="background: #ffffff; width: 100%; max-width: 480px; border-radius: 8px; padding: 24px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); display: flex; flex-direction: column; gap: 16px;">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 18px; color: #1e293b;">
              👤 Asignar Orden Preventiva
            </h3>
            <button type="button" @click="closeAssignModal" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #64748b;">✕</button>
          </div>

          <p style="margin: 0; font-size: 13px; color: #64748b;">
            Asignación de la orden <strong>{{ selectedOrderToAssign?.order_code }}</strong> para la máquina
            <strong>{{ selectedOrderToAssign?.machine?.code || ('ID #' + selectedOrderToAssign?.machine_id) }}</strong>.
          </p>

          <div v-if="assignError" style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 10px; border-radius: 4px; font-size: 13px;">
            ⚠️ {{ assignError }}
          </div>

          <!-- Selector de Técnico -->
          <div style="display: flex; flex-direction: column; gap: 6px;">
            <label style="font-size: 13px; font-weight: 600; color: #334155;">Técnico de Ruta Responsable *</label>
            <select
              v-model="assignForm.technician_id"
              class="vg-input"
              style="height: 38px; padding: 0 10px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 14px;"
              data-testid="select-assign-tech"
            >
              <option value="">Seleccione un técnico...</option>
              <option v-for="tech in technicians" :key="tech.id" :value="tech.id">
                {{ tech.name }} ({{ tech.operator_code || ('ID #' + tech.id) }})
              </option>
            </select>
          </div>

          <!-- Fecha Programada -->
          <div style="display: flex; flex-direction: column; gap: 6px;">
            <label style="font-size: 13px; font-weight: 600; color: #334155;">Fecha de Visita Programada *</label>
            <input
              type="date"
              v-model="assignForm.scheduled_date"
              class="vg-input"
              style="height: 38px; padding: 0 10px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 14px;"
              data-testid="input-assign-date"
            />
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px;">
            <button
              type="button"
              class="vg-btn"
              style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; height: 38px; padding: 0 16px; border-radius: 4px; cursor: pointer;"
              @click="closeAssignModal"
            >
              Cancelar
            </button>

            <button
              type="button"
              class="vg-btn"
              style="background: #2560ff; color: #ffffff; border: 1px solid #1a4cd8; height: 38px; padding: 0 16px; font-weight: 600; border-radius: 4px; cursor: pointer;"
              :disabled="isActionLoading"
              @click="submitAssignOrder"
              data-testid="btn-confirm-assign"
            >
              <span v-if="isActionLoading">Asignando...</span>
              <span v-else>Confirmar Asignación</span>
            </button>
          </div>
        </div>
      </div>

      <!-- ================================================================= -->
      <!-- MODAL: CANCELACIÓN LÓGICA (Art. III Constitución)                 -->
      <!-- ================================================================= -->
      <div
        v-if="showCancelModal"
        style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px;"
        data-testid="modal-cancel"
      >
        <div style="background: #ffffff; width: 100%; max-width: 480px; border-radius: 8px; padding: 24px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); display: flex; flex-direction: column; gap: 16px;">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 18px; color: #991b1b; display: flex; align-items: center; gap: 6px;">
              <span>✕</span> Cancelar Orden Preventiva
            </h3>
            <button type="button" @click="closeCancelModal" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #64748b;">✕</button>
          </div>

          <div style="background: #f8fafc; border-left: 4px solid #0284c7; padding: 10px 14px; font-size: 12px; color: #334155; border-radius: 2px;">
            ℹ️ <strong>Inviolabilidad de Datos (Art. III):</strong> La orden no se eliminará físicamente de la base de datos.
            Quedará registrada lógicamente como <code>CANCELLED</code> preservando su trazabilidad histórica completa.
          </div>

          <div v-if="cancelError" style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 10px; border-radius: 4px; font-size: 13px;">
            ⚠️ {{ cancelError }}
          </div>

          <!-- Motivo de cancelación obligatorio -->
          <div style="display: flex; flex-direction: column; gap: 6px;">
            <label style="font-size: 13px; font-weight: 600; color: #334155;">
              Motivo Justificado de Cancelación * (ej: traslado físico de máquina, baja de centro)
            </label>
            <textarea
              v-model="cancelForm.reason"
              rows="3"
              class="vg-input"
              style="padding: 8px 10px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 13px; resize: vertical;"
              placeholder="Describa de forma detallada el motivo por el cual se anula esta orden preventiva..."
              data-testid="input-cancel-reason"
            ></textarea>
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px;">
            <button
              type="button"
              class="vg-btn"
              style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; height: 38px; padding: 0 16px; border-radius: 4px; cursor: pointer;"
              @click="closeCancelModal"
            >
              Volver
            </button>

            <button
              type="button"
              class="vg-btn"
              style="background: #dc2626; color: #ffffff; border: 1px solid #b91c1c; height: 38px; padding: 0 16px; font-weight: 600; border-radius: 4px; cursor: pointer;"
              :disabled="isActionLoading"
              @click="submitCancelOrder"
              data-testid="btn-confirm-cancel"
            >
              <span v-if="isActionLoading">Cancelando...</span>
              <span v-else>Confirmar Cancelación Lógica</span>
            </button>
          </div>
        </div>
      </div>

      <!-- ================================================================= -->
      <!-- MODAL: CREACIÓN MANUAL EXTRAORDINARIA                             -->
      <!-- ================================================================= -->
      <div
        v-if="showCreateModal"
        style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px;"
        data-testid="modal-create"
      >
        <div style="background: #ffffff; width: 100%; max-width: 500px; border-radius: 8px; padding: 24px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); display: flex; flex-direction: column; gap: 16px;">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 18px; color: #1e293b;">
              ➕ Nueva Orden Preventiva Manual
            </h3>
            <button type="button" @click="closeCreateModal" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #64748b;">✕</button>
          </div>

          <div v-if="createError" style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 10px; border-radius: 4px; font-size: 13px;">
            ⚠️ {{ createError }}
          </div>

          <!-- Selector de Máquina -->
          <div style="display: flex; flex-direction: column; gap: 6px;">
            <label style="font-size: 13px; font-weight: 600; color: #334155;">Máquina Dispensadora *</label>
            <select
              v-model="createForm.machine_id"
              class="vg-input"
              style="height: 38px; padding: 0 10px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 14px;"
              data-testid="select-create-machine"
            >
              <option value="">Seleccione una máquina...</option>
              <option v-for="m in machines" :key="m.id" :value="m.id">
                {{ m.code }} - {{ m.model }} ({{ m.machine_type }})
              </option>
            </select>
          </div>

          <!-- Tipo de Orden -->
          <div style="display: flex; flex-direction: column; gap: 6px;">
            <label style="font-size: 13px; font-weight: 600; color: #334155;">Tipo de Orden</label>
            <select
              v-model="createForm.order_type"
              class="vg-input"
              style="height: 38px; padding: 0 10px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 14px;"
            >
              <option value="MANUAL_EXTRA">Extraordinaria</option>
              <option value="ROUTINE">Rutinaria</option>
              <option value="REINSPECTION">Reinspección</option>
            </select>
          </div>

          <!-- Fechas -->
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div style="display: flex; flex-direction: column; gap: 6px;">
              <label style="font-size: 13px; font-weight: 600; color: #334155;">Fecha Programada</label>
              <input
                type="date"
                v-model="createForm.scheduled_date"
                class="vg-input"
                style="height: 38px; padding: 0 10px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 14px;"
              />
            </div>
            <div style="display: flex; flex-direction: column; gap: 6px;">
              <label style="font-size: 13px; font-weight: 600; color: #334155;">Fecha Límite</label>
              <input
                type="date"
                v-model="createForm.due_date"
                class="vg-input"
                style="height: 38px; padding: 0 10px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 14px;"
              />
            </div>
          </div>

          <!-- Técnico Asignado (Opcional) -->
          <div style="display: flex; flex-direction: column; gap: 6px;">
            <label style="font-size: 13px; font-weight: 600; color: #334155;">Técnico Asignado (Opcional)</label>
            <select
              v-model="createForm.assigned_technician_id"
              class="vg-input"
              style="height: 38px; padding: 0 10px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 14px;"
            >
              <option value="">Dejar pendiente de asignación</option>
              <option v-for="t in technicians" :key="t.id" :value="t.id">
                {{ t.name }}
              </option>
            </select>
          </div>

          <!-- Notas -->
          <div style="display: flex; flex-direction: column; gap: 6px;">
            <label style="font-size: 13px; font-weight: 600; color: #334155;">Notas Operativas</label>
            <textarea
              v-model="createForm.notes"
              rows="2"
              class="vg-input"
              style="padding: 8px 10px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 13px; resize: vertical;"
              placeholder="Instrucciones especiales para la inspección preventiva..."
            ></textarea>
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px;">
            <button
              type="button"
              class="vg-btn"
              style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; height: 38px; padding: 0 16px; border-radius: 4px; cursor: pointer;"
              @click="closeCreateModal"
            >
              Cancelar
            </button>

            <button
              type="button"
              class="vg-btn"
              style="background: #2560ff; color: #ffffff; border: 1px solid #1a4cd8; height: 38px; padding: 0 16px; font-weight: 600; border-radius: 4px; cursor: pointer;"
              :disabled="isActionLoading"
              @click="submitCreateOrder"
              data-testid="btn-confirm-create"
            >
              <span v-if="isActionLoading">Creando...</span>
              <span v-else>Crear Orden</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  `
};
export default CoordinatorPreventiveOrdersTab;
