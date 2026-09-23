/**
 * VendGuard - CoordinatorFleetTab Component (CoordinatorFleetTab.js)
 * 
 * Pestaña de Gestión y Catálogo del Parque de Sedes y Máquinas para Coordinación (RF-FLEET-01, RF-FLEET-02, RF-FLEET-03).
 * 
 * Características:
 * 1. Selector y buscador de sedes clientes con conteo de máquinas instaladas.
 * 2. Visualización integral de todas las máquinas físicas de la sede (operativas y averiadas).
 * 3. Botón directo "🏷️ Imprimir QR" en cada máquina para abrir el modal individual (RF-01).
 * 4. Botón "📄 Etiquetas de Sede (A4)" contextualizado a la sede activa (RF-02).
 * 5. Señalización sanitaria de máquinas de alimentos perecederos (Art. II Constitución).
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias de npm).
 */

import { api } from '../api.js';

export const CoordinatorFleetTab = {
  name: 'CoordinatorFleetTab',
  emits: ['open-qr', 'open-batch-print'],
  data() {
    return {
      locations: [],
      selectedLocationId: null,
      selectedLocation: null,
      machines: [],
      isLoadingLocations: false,
      isLoadingMachines: false,
      errorMessage: '',
      searchQuery: '',
      filterStatus: 'ALL' // 'ALL' | 'OPERATIONAL' | 'INCIDENT' | 'PERISHABLE'
    };
  },
  computed: {
    filteredMachines() {
      return this.machines.filter(m => {
        // Filtro por texto de búsqueda (código, modelo o planta)
        if (this.searchQuery.trim()) {
          const q = this.searchQuery.trim().toLowerCase();
          const matchCode = (m.code || '').toLowerCase().includes(q);
          const matchModel = (m.model || '').toLowerCase().includes(q);
          const matchFloor = (m.floor_wing || '').toLowerCase().includes(q);
          if (!matchCode && !matchModel && !matchFloor) {
            return false;
          }
        }

        // Filtro por estado operativo o tipo perecedero
        if (this.filterStatus === 'OPERATIONAL') {
          return m.operational_status === 'OPERATIONAL';
        }
        if (this.filterStatus === 'INCIDENT') {
          return m.operational_status === 'ACTIVE_INCIDENT' || m.operational_status === 'IN_WARRANTY';
        }
        if (this.filterStatus === 'PERISHABLE') {
          return m.is_perishable === true;
        }

        return true;
      });
    },
    metrics() {
      const total = this.machines.length;
      const operational = this.machines.filter(m => m.operational_status === 'OPERATIONAL').length;
      const incidents = this.machines.filter(m => m.operational_status === 'ACTIVE_INCIDENT').length;
      const perishables = this.machines.filter(m => m.is_perishable === true).length;
      return { total, operational, incidents, perishables };
    }
  },
  mounted() {
    this.loadLocations();
  },
  methods: {
    /**
     * Carga el catálogo de sedes activas (RF-FLEET-02)
     */
    async loadLocations() {
      this.isLoadingLocations = true;
      this.errorMessage = '';

      try {
        const res = await api.coordinator.getLocations();
        this.locations = res.data || res || [];
        if (this.locations.length > 0 && !this.selectedLocationId) {
          this.selectLocation(this.locations[0].id);
        }
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar el catálogo de sedes.';
      } finally {
        this.isLoadingLocations = false;
      }
    },

    /**
     * Selecciona una sede activa y carga su parque de máquinas
     */
    async selectLocation(locationId) {
      this.selectedLocationId = Number(locationId);
      this.selectedLocation = this.locations.find(l => l.id === this.selectedLocationId) || null;
      await this.loadMachines(this.selectedLocationId);
    },

    /**
     * Carga las máquinas de la sede seleccionada (RF-FLEET-03)
     */
    async loadMachines(locationId) {
      if (!locationId) return;

      this.isLoadingMachines = true;
      this.errorMessage = '';

      try {
        const res = await api.coordinator.getLocationMachines(locationId);
        const data = res.data || res || {};
        this.machines = data.machines || [];
        if (data.location) {
          this.selectedLocation = data.location;
        }
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar las máquinas de la sede.';
      } finally {
        this.isLoadingMachines = false;
      }
    },

    /**
     * Emite el evento para abrir el modal individual de etiqueta QR
     */
    handleOpenQr(machine) {
      this.$emit('open-qr', {
        id: machine.id,
        code: machine.code,
        model: machine.model,
        machine_type: machine.machine_type,
        floor_wing: machine.floor_wing,
        location: this.selectedLocation
      });
    },

    /**
     * Emite el evento para abrir la vista de impresión en lote A4
     */
    handleOpenBatchPrint() {
      if (!this.selectedLocation) return;
      this.$emit('open-batch-print', {
        locationId: this.selectedLocation.id,
        locationName: this.selectedLocation.name || this.selectedLocation.site_code
      });
    }
  },
  template: `
    <div class="coordinator-fleet-tab" style="padding-top: 8px;">
      <!-- Mensaje de error general -->
      <div
        v-if="errorMessage"
        style="background-color: #fee2e2; border: 1px solid #ef4444; color: #991b1b; padding: 12px 16px; border-radius: var(--radius-card, 8px); margin-bottom: 16px; font-size: 14px;"
      >
        ⚠️ {{ errorMessage }}
      </div>

      <!-- Barra Superior: Selector de Sedes y Acciones de Lote -->
      <div
        class="vg-card"
        style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px 20px; margin-bottom: 20px;"
      >
        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px;">
          <!-- Selector de Sede -->
          <div style="display: flex; align-items: center; gap: 12px; flex: 1 1 320px;">
            <label for="fleet-location-select" style="font-weight: 600; font-size: 13px; color: var(--color-slate, #2c333f); white-space: nowrap;">
              🏢 Sede Cliente:
            </label>
            <select
              id="fleet-location-select"
              class="vg-input"
              style="height: 38px; font-size: 14px; font-weight: 500;"
              :value="selectedLocationId"
              @change="selectLocation($event.target.value)"
              :disabled="isLoadingLocations"
            >
              <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                {{ loc.site_code }} · {{ loc.name }} ({{ loc.machine_count ?? 0 }} máquinas)
              </option>
            </select>
          </div>

          <!-- Botón de Lote A4 de la sede -->
          <div>
            <button
              type="button"
              class="vg-btn vg-btn-primary"
              style="height: 38px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;"
              @click="handleOpenBatchPrint"
              :disabled="!selectedLocation || machines.length === 0"
              title="Emitir hoja A4 con todos los códigos QR de esta sede"
              data-testid="fleet-btn-batch-print"
            >
              📄 Etiquetas de Sede (A4)
            </button>
          </div>
        </div>

        <!-- Ficha de detalles de la sede activa -->
        <div
          v-if="selectedLocation"
          style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--color-hairline, #c8cfda); display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; font-size: 13px; color: var(--color-ink-muted, #6c7e9d);"
        >
          <div>
            <span style="color: var(--color-slate, #2c333f); font-weight: 600;">📍 Dirección:</span>
            {{ selectedLocation.address || 'Sin dirección registrada' }}
          </div>
          <div>
            <span style="color: var(--color-slate, #2c333f); font-weight: 600;">📞 Teléfono Maestro:</span>
            {{ selectedLocation.contact_phone || 'No configurado' }}
          </div>
          <div style="font-weight: 600; color: var(--color-primary, #2560ff);">
            📊 Total: {{ metrics.total }} máquina(s) instaladas
          </div>
        </div>
      </div>

      <!-- Barra de Filtros Rápidos de Máquinas -->
      <div
        style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px;"
      >
        <!-- Buscador por texto -->
        <div style="flex: 1 1 240px; max-width: 320px;">
          <input
            v-model="searchQuery"
            type="text"
            class="vg-input"
            placeholder="Buscar por código (ej: VEND-0101), modelo o planta..."
            style="height: 34px; font-size: 13px;"
          />
        </div>

        <!-- Botones de filtro rápido -->
        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
          <button
            type="button"
            class="vg-btn"
            :class="filterStatus === 'ALL' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 32px; font-size: 12px; padding: 0 10px;"
            @click="filterStatus = 'ALL'"
          >
            Todas ({{ metrics.total }})
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="filterStatus === 'OPERATIONAL' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 32px; font-size: 12px; padding: 0 10px;"
            @click="filterStatus = 'OPERATIONAL'"
          >
            🟢 Operativas ({{ metrics.operational }})
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="filterStatus === 'INCIDENT' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 32px; font-size: 12px; padding: 0 10px;"
            @click="filterStatus = 'INCIDENT'"
          >
            🔴 Con Avería ({{ metrics.incidents }})
          </button>
          <button
            type="button"
            class="vg-btn"
            :class="filterStatus === 'PERISHABLE' ? 'vg-btn-primary' : 'vg-btn-secondary'"
            style="height: 32px; font-size: 12px; padding: 0 10px;"
            @click="filterStatus = 'PERISHABLE'"
          >
            ❄️ Alimentos Frescos ({{ metrics.perishables }})
          </button>
        </div>
      </div>

      <!-- Estado de carga -->
      <div v-if="isLoadingMachines" style="text-align: center; padding: 40px; color: var(--color-ink-muted, #6c7e9d);">
        <p>Cargando máquinas de la sede...</p>
      </div>

      <!-- Mensaje si no hay máquinas -->
      <div
        v-else-if="filteredMachines.length === 0"
        class="vg-card"
        style="text-align: center; padding: 40px; border-radius: var(--radius-card, 8px); color: var(--color-ink-muted, #6c7e9d);"
      >
        <p style="font-size: 15px; margin: 0;">No se encontraron máquinas que coincidan con los filtros seleccionados.</p>
      </div>

      <!-- Cuadrícula de Máquinas del Parque -->
      <div
        v-else
        style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px;"
      >
        <div
          v-for="mach in filteredMachines"
          :key="mach.id"
          class="vg-card"
          style="background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 16px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: var(--shadow-card, 0 1px 3px rgba(0, 0, 0, 0.04));"
          :data-machine-code="mach.code"
        >
          <!-- Parte Superior de la Tarjeta -->
          <div>
            <!-- Código y Badge de Estado -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
              <strong style="font-family: monospace; font-size: 16px; color: var(--color-ink, #000000);">
                {{ mach.code }}
              </strong>
              
              <!-- Badges de estado operativo -->
              <span
                v-if="mach.operational_status === 'OPERATIONAL'"
                style="background-color: #dcfce7; color: #166534; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px;"
              >
                🟢 En Servicio
              </span>
              <span
                v-else-if="mach.operational_status === 'ACTIVE_INCIDENT'"
                style="background-color: #fee2e2; color: #991b1b; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px;"
              >
                🔴 Avería #{{ mach.active_incident?.ticket_code || 'ACTIVA' }}
              </span>
              <span
                v-else-if="mach.operational_status === 'IN_WARRANTY'"
                style="background-color: #fef9c3; color: #854d0e; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px;"
              >
                🟡 En Garantía (48h)
              </span>
            </div>

            <!-- Modelo y Tipo -->
            <div style="font-size: 14px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 4px;">
              {{ mach.model }}
            </div>
            
            <!-- Ubicación en el edificio -->
            <div style="font-size: 12px; color: var(--color-ink-muted, #6c7e9d); margin-bottom: 10px;">
              📍 {{ mach.floor_wing || 'Ubicación no detallada' }}
            </div>

            <!-- Alerta Sanitaria de Alimentos Perecederos (Art. II) -->
            <div
              v-if="mach.is_perishable"
              style="background-color: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; border-radius: 4px; padding: 4px 8px; font-size: 11px; font-weight: 600; margin-bottom: 12px; display: inline-flex; align-items: center; gap: 4px;"
            >
              ❄️ Alimentos Frescos / Cadena de Frío
            </div>
          </div>

          <!-- Pie de la Tarjeta con Botón de Acción QR -->
          <div style="margin-top: 14px; padding-top: 10px; border-top: 1px solid var(--color-hairline, #c8cfda); display: flex; align-items: center; justify-content: flex-end;">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              style="height: 32px; font-size: 12px; padding: 0 12px; display: inline-flex; align-items: center; gap: 6px; font-weight: 600;"
              @click="handleOpenQr(mach)"
              :title="'Previsualizar, personalizar e imprimir etiqueta de la máquina ' + mach.code"
              :data-testid="'btn-qr-' + mach.code"
            >
              🏷️ Imprimir QR
            </button>
          </div>
        </div>
      </div>
    </div>
  `
};
