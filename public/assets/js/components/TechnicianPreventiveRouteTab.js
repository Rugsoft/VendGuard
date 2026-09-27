/**
 * VendGuard - TechnicianPreventiveRouteTab (TechnicianPreventiveRouteTab.js)
 * 
 * Componente móvil Vue 3 ESM para la gestión de ruta preventiva del Técnico de Campo (RF-PREV-02).
 * 
 * Características:
 * 1. Mobile-first layout táctil vertical optimizado para smartphone (RNF-01).
 * 2. Visualización y agrupación de inspecciones preventivas asignadas (SCHEDULED, IN_INSPECTION, EXPIRED).
 * 3. Visita Oportunista (EARS 2.3): Destaca órdenes preventivas pendientes (PENDING_ASSIGNMENT)
 *    en la sede física donde el técnico se encuentra trabajando y permite su autoasignación inmediata con un solo toque.
 * 4. Control de inicio de inspección in situ (transición formal a IN_INSPECTION).
 * 5. Señalización higiénica prioritaria para máquinas de alimentos perecederos (Art. II).
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias externas).
 * Dualismo Lingüístico: Código en inglés, interfaz y mensajes en español.
 */

import { api } from '../api.js';

export const TechnicianPreventiveRouteTab = {
  name: 'TechnicianPreventiveRouteTab',
  props: {
    currentLocationId: {
      type: [Number, String],
      default: null
    }
  },
  emits: ['open-checklist', 'order-claimed', 'inspection-started', 'refresh'],
  data() {
    return {
      orders: [],
      locations: [],
      selectedLocationId: this.currentLocationId ? Number(this.currentLocationId) : null,
      isLoading: false,
      actionLoadingId: null,
      errorMessage: '',
      successMessage: ''
    };
  },
  computed: {
    /**
     * Órdenes asignadas directamente al técnico autenticado
     */
    assignedOrders() {
      return this.orders.filter(o => 
        ['SCHEDULED', 'IN_INSPECTION', 'EXPIRED'].includes(o.status)
      );
    },

    /**
     * Órdenes pendientes en la sede actual disponibles para Visita Oportunista (EARS 2.3)
     */
    opportunisticOrders() {
      return this.orders.filter(o => 
        o.status === 'PENDING_ASSIGNMENT'
      );
    },

    /**
     * Métricas de resumen para la cabecera móvil
     */
    metrics() {
      const assignedList = this.assignedOrders || [];
      const assigned = assignedList.length;
      const inInspection = assignedList.filter(o => o.status === 'IN_INSPECTION').length;
      const opportunistic = (this.opportunisticOrders || []).length;
      const expired = assignedList.filter(o => o.status === 'EXPIRED').length;

      return { assigned, inInspection, opportunistic, expired };
    }
  },
  watch: {
    currentLocationId(newVal) {
      if (newVal) {
        this.selectedLocationId = Number(newVal);
        this.loadRoute();
      }
    }
  },
  mounted() {
    this.loadInitialData();
  },
  methods: {
    async loadInitialData() {
      await Promise.all([
        this.loadLocations(),
        this.loadRoute()
      ]);
    },

    /**
     * Carga las sedes activas para el selector de ubicación in situ
     */
    async loadLocations() {
      try {
        const res = await api.coordinator.getLocations();
        this.locations = res?.data || res || [];
      } catch (err) {
        console.warn('No se pudieron cargar las sedes para el selector in situ:', err);
      }
    },

    /**
     * Carga la ruta de preventivos del técnico, opcionalmente filtrando por sede in situ (EARS 2.3)
     */
    async loadRoute() {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        const res = await api.technician.getPreventiveRoute(this.selectedLocationId);
        this.orders = res?.data || (Array.isArray(res) ? res : []);
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar la ruta preventiva.';
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * Cambia la sede in situ seleccionada y recarga la ruta
     */
    onLocationChange() {
      this.loadRoute();
    },

    /**
     * Autoasignación inmediata con un solo toque (Visita Oportunista, EARS 2.3)
     */
    async claimOrder(order) {
      this.actionLoadingId = order.id;
      this.errorMessage = '';
      this.successMessage = '';

      try {
        await api.technician.claimPreventiveOrder(order.id);
        this.successMessage = `¡Visita oportunista confirmada! Orden ${order.order_code} incorporada a tu ruta.`;
        this.$emit('order-claimed', order);
        await this.loadRoute();
      } catch (err) {
        this.errorMessage = err.message || 'No fue posible autoasignar la orden preventiva.';
      } finally {
        this.actionLoadingId = null;
      }
    },

    /**
     * Inicia formalmente la inspección in situ transicionando a IN_INSPECTION
     */
    async startInspection(order) {
      this.actionLoadingId = order.id;
      this.errorMessage = '';

      try {
        await api.technician.startPreventiveInspection(order.id);
        this.$emit('inspection-started', order);
        this.$emit('open-checklist', { ...order, status: 'IN_INSPECTION' });
        await this.loadRoute();
      } catch (err) {
        this.errorMessage = err.message || 'Error al iniciar la inspección in situ.';
      } finally {
        this.actionLoadingId = null;
      }
    },

    /**
     * Abre el checklist normativo (si ya está IN_INSPECTION)
     */
    openChecklist(order) {
      this.$emit('open-checklist', order);
    },

    /**
     * Formatea el estado de la orden
     */
    getStatusChip(status) {
      const map = {
        PENDING_ASSIGNMENT: { label: 'Pendiente en Sede', bg: '#fef3c7', color: '#92400e', icon: '⏳' },
        SCHEDULED: { label: 'Programada', bg: '#e0e7ff', color: '#3730a3', icon: '📅' },
        IN_INSPECTION: { label: 'En Inspección', bg: '#dbeafe', color: '#1e40af', icon: '🔍' },
        EXPIRED: { label: 'Vencida Prioritaria', bg: '#fee2e2', color: '#991b1b', icon: '🔴' }
      };
      return map[status] || { label: status, bg: '#f1f5f9', color: '#475569', icon: '•' };
    }
  },
  template: `
    <div class="vg-technician-preventive-tab" style="display: flex; flex-direction: column; gap: 14px;">
      <!-- 1. Selector de Sede Actual / In Situ (EARS 2.3) -->
      <div style="background: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 12px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); display: flex; flex-direction: column; gap: 8px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
          <span style="font-size: 12px; font-weight: 700; color: #1e293b; text-transform: uppercase; display: flex; align-items: center; gap: 6px;">
            <span>📍</span> Tu Ubicación Actual (In Situ)
          </span>
          <button
            type="button"
            class="vg-btn"
            style="background: #f1f5f9; border: 1px solid #cbd5e1; padding: 4px 8px; font-size: 11px; border-radius: 4px; cursor: pointer;"
            @click="loadRoute"
            :disabled="isLoading"
            title="Recargar ruta"
          >
            🔄 Actualizar
          </button>
        </div>

        <select
          v-model="selectedLocationId"
          @change="onLocationChange"
          class="vg-input"
          style="width: 100%; height: 40px; padding: 0 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px; font-weight: 500; background: #f8fafc;"
          data-testid="select-tech-location"
        >
          <option :value="null">Todas las sedes (Ruta asignada general)</option>
          <option v-for="loc in locations" :key="loc.id" :value="loc.id">
            🏢 {{ loc.site_code }} - {{ loc.name }}
          </option>
        </select>
        <small style="font-size: 11px; color: #64748b;">
          💡 Selecciona el edificio donde te encuentras para descubrir preventivos pendientes y autoasignártelos (Visita Oportunista).
        </small>
      </div>

      <!-- Alertas en pantalla -->
      <div v-if="successMessage" style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 10px 12px; border-radius: 6px; font-size: 13px; display: flex; justify-content: space-between; align-items: center;">
        <span>✅ {{ successMessage }}</span>
        <button type="button" @click="successMessage = ''" style="background: none; border: none; font-size: 14px; cursor: pointer; color: #166534;">✕</button>
      </div>

      <div v-if="errorMessage" style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 10px 12px; border-radius: 6px; font-size: 13px; display: flex; justify-content: space-between; align-items: center;">
        <span>⚠️ {{ errorMessage }}</span>
        <button type="button" @click="errorMessage = ''" style="background: none; border: none; font-size: 14px; cursor: pointer; color: #991b1b;">✕</button>
      </div>

      <!-- 2. Resumen rápido de chips de ruta móvil -->
      <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px; text-align: center;">
          <span style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; display: block;">Asignadas</span>
          <strong style="font-size: 18px; color: #1e293b;">{{ metrics.assigned }}</strong>
        </div>

        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 8px; text-align: center;">
          <span style="font-size: 10px; font-weight: 700; color: #166534; text-transform: uppercase; display: block;">En Curso</span>
          <strong style="font-size: 18px; color: #15803d;">{{ metrics.inInspection }}</strong>
        </div>

        <div style="background: #fefce8; border: 1px solid #fef08a; border-radius: 6px; padding: 8px; text-align: center;">
          <span style="font-size: 10px; font-weight: 700; color: #854d0e; text-transform: uppercase; display: block;">Oportunistas</span>
          <strong style="font-size: 18px; color: #a16207;">{{ metrics.opportunistic }}</strong>
        </div>
      </div>

      <!-- 3. SECCIÓN DESTACADA: VISITAS OPORTUNISTAS (EARS 2.3) -->
      <div
        v-if="opportunisticOrders.length > 0"
        style="background: #fffbeb; border: 2px dashed #f59e0b; border-radius: 8px; padding: 14px; display: flex; flex-direction: column; gap: 10px;"
        data-testid="opportunistic-section"
      >
        <div style="display: flex; justify-content: space-between; align-items: center;">
          <div style="display: flex; align-items: center; gap: 6px;">
            <span style="font-size: 18px;">⚡</span>
            <strong style="font-size: 14px; color: #92400e;">
              Visita Oportunista in situ ({{ opportunisticOrders.length }})
            </strong>
          </div>
          <span style="font-size: 10px; font-weight: bold; background: #fef3c7; color: #b45309; padding: 2px 6px; border-radius: 4px;">
            EARS 2.3
          </span>
        </div>

        <p style="margin: 0; font-size: 12px; color: #78350f;">
          Hay máquinas pendientes de revisión en este mismo edificio. Aprovecha tu estancia para autoasignártelas inmediatamente:
        </p>

        <!-- Tarjetas de órdenes oportunistas -->
        <div style="display: flex; flex-direction: column; gap: 8px;">
          <div
            v-for="order in opportunisticOrders"
            :key="order.id"
            style="background: #ffffff; border: 1px solid #fde68a; border-radius: 6px; padding: 12px; display: flex; flex-direction: column; gap: 8px;"
            data-testid="opportunistic-card"
          >
            <div style="display: flex; justify-content: space-between; align-items: baseline;">
              <div>
                <strong style="font-size: 14px; color: #1e293b;">{{ order.machine?.code || ('ID #' + order.machine_id) }}</strong>
                <span style="font-size: 12px; color: #64748b; margin-left: 6px;">{{ order.machine?.model }}</span>
              </div>
              <span
                v-if="order.machine?.is_perishable || order.machine?.machine_type === 'PERISHABLE_FOOD'"
                style="background: #fee2e2; color: #991b1b; font-size: 10px; font-weight: bold; padding: 1px 6px; border-radius: 4px;"
              >
                🥩 Perecedero
              </span>
            </div>

            <div style="font-size: 12px; color: #475569;">
              📍 {{ order.location?.name }} — {{ order.machine?.floor_wing || 'Ubicación general' }}
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: #854d0e;">
              <span>⏰ Límite sanitario: <strong>{{ order.due_date }}</strong></span>
            </div>

            <!-- Botón de autoasignación inmediata con un solo toque (EARS 2.3) -->
            <button
              type="button"
              class="vg-btn"
              style="width: 100%; min-height: 44px; background: #d97706; color: #ffffff; font-weight: 700; font-size: 13px; border-radius: 6px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 2px;"
              :disabled="actionLoadingId === order.id"
              @click="claimOrder(order)"
              data-testid="btn-claim-order"
            >
              <span v-if="actionLoadingId === order.id">⏳ Autoasignando...</span>
              <span v-else>⚡ Autoasignar in situ (1 toque)</span>
            </button>
          </div>
        </div>
      </div>

      <!-- 4. LISTADO PRINCIPAL: MIS INSPECCIONES ASIGNADAS -->
      <div style="display: flex; flex-direction: column; gap: 10px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
          <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: #1e293b; text-transform: uppercase;">
            📋 Mis Inspecciones en Ruta ({{ assignedOrders.length }})
          </h4>
        </div>

        <div v-if="isLoading" style="text-align: center; padding: 30px; color: #64748b; font-size: 13px;">
          ⏳ Cargando ruta preventiva...
        </div>

        <div v-else-if="assignedOrders.length === 0" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 30px 16px; text-align: center; color: #64748b;">
          🎉 No tienes inspecciones preventivas pendientes de realización en esta ruta.
        </div>

        <!-- Tarjetas de órdenes asignadas -->
        <div v-else style="display: flex; flex-direction: column; gap: 10px;">
          <div
            v-for="order in assignedOrders"
            :key="order.id"
            style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); display: flex; flex-direction: column; gap: 10px;"
            :style="{
              borderLeft: order.status === 'EXPIRED' ? '4px solid #ef4444' : order.status === 'IN_INSPECTION' ? '4px solid #2560ff' : '4px solid #10b981'
            }"
            data-testid="assigned-order-card"
          >
            <!-- Cabecera de la tarjeta -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
              <div>
                <div style="display: flex; align-items: center; gap: 6px;">
                  <strong style="font-size: 15px; color: #0f172a;">{{ order.machine?.code || ('ID #' + order.machine_id) }}</strong>
                  <span
                    v-if="order.machine?.is_perishable || order.machine?.machine_type === 'PERISHABLE_FOOD'"
                    style="background: #fee2e2; color: #991b1b; font-size: 10px; font-weight: bold; padding: 1px 6px; border-radius: 4px;"
                  >
                    🥩 Perecedero
                  </span>
                </div>
                <div style="font-size: 12px; color: #64748b;">{{ order.machine?.model }}</div>
              </div>

              <!-- Chip de Estado -->
              <span
                :style="{
                  background: getStatusChip(order.status).bg,
                  color: getStatusChip(order.status).color,
                  padding: '3px 8px',
                  borderRadius: '12px',
                  fontSize: '11px',
                  fontWeight: '700'
                }"
              >
                {{ getStatusChip(order.status).icon }} {{ getStatusChip(order.status).label }}
              </span>
            </div>

            <!-- Ubicación -->
            <div style="font-size: 13px; color: #334155; display: flex; align-items: center; gap: 6px;">
              <span>🏢</span>
              <span><strong>{{ order.location?.name }}</strong> — {{ order.machine?.floor_wing || 'Planta general' }}</span>
            </div>

            <!-- Datos de programación y orden -->
            <div style="background: #f8fafc; padding: 8px 10px; border-radius: 6px; font-size: 12px; display: flex; justify-content: space-between; align-items: center; color: #475569;">
              <span>Orden: <strong>{{ order.order_code }}</strong></span>
              <span :style="{ color: order.status === 'EXPIRED' ? '#dc2626' : '#475569', fontWeight: order.status === 'EXPIRED' ? '700' : 'normal' }">
                Límite: {{ order.due_date }}
              </span>
            </div>

            <!-- Botones de Acción Móviles (Targets táctiles >= 44px, RNF-01) -->
            <div style="display: flex; gap: 8px; margin-top: 4px;">
              <!-- Caso 1: Orden SCHEDULED o EXPIRED -> Iniciar Inspección -->
              <button
                v-if="['SCHEDULED', 'EXPIRED'].includes(order.status)"
                type="button"
                class="vg-btn"
                style="flex: 1; min-height: 44px; background: #2560ff; color: #ffffff; font-weight: 700; font-size: 14px; border-radius: 6px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;"
                :disabled="actionLoadingId === order.id"
                @click="startInspection(order)"
                data-testid="btn-start-inspection"
              >
                <span v-if="actionLoadingId === order.id">⏳ Iniciando...</span>
                <span v-else>▶️ Iniciar Inspección</span>
              </button>

              <!-- Caso 2: Orden ya IN_INSPECTION -> Continuar Checklist -->
              <button
                v-else-if="order.status === 'IN_INSPECTION'"
                type="button"
                class="vg-btn"
                style="flex: 1; min-height: 44px; background: #16a34a; color: #ffffff; font-weight: 700; font-size: 14px; border-radius: 6px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;"
                @click="openChecklist(order)"
                data-testid="btn-open-checklist"
              >
                📝 Completar Checklist Sanitario
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
};
export default TechnicianPreventiveRouteTab;
