/**
 * VendGuard - MetricBreakdownTable Component (MetricBreakdownTable.js)
 * 
 * Tabla analítica reactiva para el desglose multidimensional de MTTR y volúmenes (RF-02, EARS 2.2 - 2.6).
 * 
 * Características:
 * 1. Pestañas de dimensión: Sedes Clientes, Técnicos de Ruta, Tipos de Máquina y Tipologías de Avería.
 * 2. Señalización sanitaria de Alimentos Perecederos (Art. II): distintivo prioritario y advertencia visual en rojo/amarillo si supera 4h.
 * 3. Identificación visual de entidades inactivas con el distintivo "(Inactivo)" (EARS 2.6).
 * 4. Buscador reactivo por texto para filtrar rápidamente las filas de la tabla activa.
 * 5. Indicadores claros de cumplimiento de acuerdos de nivel de servicio (SLA).
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias de npm).
 */

export const MetricBreakdownTable = {
  name: 'MetricBreakdownTable',
  props: {
    breakdown: {
      type: Object,
      default: () => ({})
    },
    isLoading: {
      type: Boolean,
      default: false
    }
  },
  data() {
    return {
      activeTab: 'location', // 'location' | 'technician' | 'machine_type' | 'category'
      searchQuery: ''
    };
  },
  computed: {
    locations() {
      return this.breakdown?.by_location || [];
    },
    technicians() {
      return this.breakdown?.by_technician || [];
    },
    machineTypes() {
      return this.breakdown?.by_machine_type || [];
    },
    categories() {
      return this.breakdown?.by_category || [];
    },
    filteredItems() {
      const q = this.searchQuery.trim().toLowerCase();

      if (this.activeTab === 'location') {
        return this.locations.filter(item => {
          if (!q) return true;
          const name = (item.location_name || '').toLowerCase();
          const code = (item.site_code || '').toLowerCase();
          return name.includes(q) || code.includes(q);
        });
      }

      if (this.activeTab === 'technician') {
        return this.technicians.filter(item => {
          if (!q) return true;
          const name = (item.technician_name || item.display_name || '').toLowerCase();
          return name.includes(q);
        });
      }

      if (this.activeTab === 'machine_type') {
        return this.machineTypes.filter(item => {
          if (!q) return true;
          const name = (item.display_name || item.machine_type || '').toLowerCase();
          return name.includes(q);
        });
      }

      if (this.activeTab === 'category') {
        return this.categories.filter(item => {
          if (!q) return true;
          const raw = (item.category || '').toLowerCase();
          const label = (this.formatCategoryName(item.category) || '').toLowerCase();
          return raw.includes(q) || label.includes(q);
        });
      }

      return [];
    }
  },
  methods: {
    formatCategoryName(category) {
      const map = {
        'TEMPERATURE_COLD': 'Refrigeración y Frío (Sanitario)',
        'PAYMENT_SYSTEM': 'Sistema de Pago y Billetero',
        'PRODUCT_JAM': 'Atasco Mecánico de Producto',
        'DISPLAY_KEYPAD': 'Pantalla, Teclado y Botonera',
        'POWER_ELECTRICAL': 'Alimentación Eléctrica / Apagada',
        'OTHER': 'Otras Incidencias'
      };
      return map[category] || category || 'General';
    },
    getSlaBadge(status, hours, target) {
      if (status === 'BREACHED') {
        return {
          label: 'Incumplido (> ' + target + 'h)',
          bg: '#fee2e2',
          color: '#b91c1c',
          border: '#fca5a5'
        };
      }
      if (status === 'COMPLIANT') {
        return {
          label: 'Cumple (≤ ' + target + 'h)',
          bg: '#dcfce7',
          color: '#15803d',
          border: '#86efac'
        };
      }
      return {
        label: 'Sin datos',
        bg: '#f1f5f9',
        color: '#64748b',
        border: '#cbd5e1'
      };
    }
  },
  template: `
    <div class="vg-breakdown-card" style="background: #ffffff; border-radius: 8px; border: 1px solid #c8cfda; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden;">
      
      <!-- Barra Superior: Selector de Dimensión y Buscador -->
      <div style="padding: 16px 20px; border-bottom: 1px solid #c8cfda; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; background: #f9fafb;">
        
        <!-- Pestañas de Dimensión -->
        <div style="display: flex; gap: 4px; background: #e5f2fc; padding: 3px; border-radius: 6px;">
          <button
            type="button"
            @click="activeTab = 'location'"
            :style="{ padding: '6px 12px', fontSize: '13px', fontWeight: '600', borderRadius: '4px', border: 'none', cursor: 'pointer', backgroundColor: activeTab === 'location' ? '#2560ff' : 'transparent', color: activeTab === 'location' ? '#ffffff' : '#2c333f', transition: 'all 0.2s ease' }"
          >
            🏢 Por Sede ({{ locations.length }})
          </button>
          <button
            type="button"
            @click="activeTab = 'technician'"
            :style="{ padding: '6px 12px', fontSize: '13px', fontWeight: '600', borderRadius: '4px', border: 'none', cursor: 'pointer', backgroundColor: activeTab === 'technician' ? '#2560ff' : 'transparent', color: activeTab === 'technician' ? '#ffffff' : '#2c333f', transition: 'all 0.2s ease' }"
          >
            🔧 Por Técnico ({{ technicians.length }})
          </button>
          <button
            type="button"
            @click="activeTab = 'machine_type'"
            :style="{ padding: '6px 12px', fontSize: '13px', fontWeight: '600', borderRadius: '4px', border: 'none', cursor: 'pointer', backgroundColor: activeTab === 'machine_type' ? '#2560ff' : 'transparent', color: activeTab === 'machine_type' ? '#ffffff' : '#2c333f', transition: 'all 0.2s ease' }"
          >
            🥪 Tipos de Máquina ({{ machineTypes.length }})
          </button>
          <button
            type="button"
            @click="activeTab = 'category'"
            :style="{ padding: '6px 12px', fontSize: '13px', fontWeight: '600', borderRadius: '4px', border: 'none', cursor: 'pointer', backgroundColor: activeTab === 'category' ? '#2560ff' : 'transparent', color: activeTab === 'category' ? '#ffffff' : '#2c333f', transition: 'all 0.2s ease' }"
          >
            ⚠️ Por Avería ({{ categories.length }})
          </button>
        </div>

        <!-- Buscador Reactivo -->
        <div style="position: relative; min-width: 220px;">
          <input
            type="text"
            v-model="searchQuery"
            placeholder="Filtrar resultados..."
            style="width: 100%; padding: 7px 12px 7px 30px; font-size: 13px; border: 1px solid #c8cfda; border-radius: 4px; outline: none;"
          />
          <span style="position: absolute; left: 10px; top: 8px; font-size: 13px; color: #6c7e9d;">🔍</span>
        </div>

      </div>

      <!-- Estado de Carga -->
      <div v-if="isLoading" style="padding: 40px; text-align: center; color: #2560ff; font-weight: 600;">
        Cargando desglose analítico...
      </div>

      <!-- Tabla de Datos -->
      <div v-else style="overflow-x: auto;">
        
        <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
          
          <!-- Encabezados de Columna -->
          <thead>
            <tr style="background: #ffffff; border-bottom: 2px solid #c8cfda; color: #434c5f;">
              <th style="padding: 12px 16px; font-weight: 700;">
                <span v-if="activeTab === 'location'">Sede Cliente</span>
                <span v-else-if="activeTab === 'technician'">Técnico Asignado</span>
                <span v-else-if="activeTab === 'machine_type'">Tipo de Máquina</span>
                <span v-else>Tipología de Avería</span>
              </th>
              <th style="padding: 12px 16px; font-weight: 700; text-align: center;">Incidencias Resueltas</th>
              <th style="padding: 12px 16px; font-weight: 700;">MTTR Formateado</th>
              <th style="padding: 12px 16px; font-weight: 700;">MTTR (Horas)</th>
              <th v-if="activeTab !== 'category' && activeTab !== 'technician'" style="padding: 12px 16px; font-weight: 700;">Objetivo SLA</th>
              <th v-if="activeTab !== 'category' && activeTab !== 'technician'" style="padding: 12px 16px; font-weight: 700;">Estado SLA</th>
            </tr>
          </thead>

          <!-- Cuerpo de la Tabla -->
          <tbody>
            
            <tr v-if="filteredItems.length === 0">
              <td :colspan="activeTab === 'category' || activeTab === 'technician' ? 4 : 6" style="padding: 32px; text-align: center; color: #6c7e9d;">
                No se encontraron registros para el filtro seleccionado.
              </td>
            </tr>

            <!-- 1. Filas de Sedes -->
            <template v-if="activeTab === 'location'">
              <tr
                v-for="item in filteredItems"
                :key="item.location_id"
                style="border-bottom: 1px solid #efefef; transition: background 0.15s ease;"
                :style="{ backgroundColor: !item.is_active ? '#fafafa' : '#ffffff' }"
              >
                <td style="padding: 12px 16px;">
                  <div style="font-weight: 600; color: #000000;">
                    {{ item.location_name }}
                    <span v-if="!item.is_active" style="display: inline-block; margin-left: 6px; padding: 2px 6px; font-size: 11px; background: #e2e8f0; color: #475569; border-radius: 4px; font-weight: 500;">(Inactivo)</span>
                  </div>
                  <div style="font-size: 11px; color: #6c7e9d; margin-top: 2px;">
                    Código: {{ item.site_code }}
                  </div>
                </td>
                <td style="padding: 12px 16px; text-align: center; font-weight: 600;">
                  {{ item.tickets_resolved }}
                </td>
                <td style="padding: 12px 16px; font-weight: 600; color: #2560ff;">
                  {{ item.mttr_formatted }}
                </td>
                <td style="padding: 12px 16px; color: #434c5f;">
                  {{ item.mttr_hours !== null && item.mttr_hours !== undefined ? item.mttr_hours + 'h' : 'N/A' }}
                </td>
                <td style="padding: 12px 16px; color: #6c7e9d;">
                  {{ item.sla_target_hours || 24.0 }}h
                </td>
                <td style="padding: 12px 16px;">
                  <span
                    :style="{ display: 'inline-block', padding: '3px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: '700', backgroundColor: getSlaBadge(item.sla_status, item.mttr_hours, item.sla_target_hours || 24.0).bg, color: getSlaBadge(item.sla_status, item.mttr_hours, item.sla_target_hours || 24.0).color, border: '1px solid ' + getSlaBadge(item.sla_status, item.mttr_hours, item.sla_target_hours || 24.0).border }"
                  >
                    {{ getSlaBadge(item.sla_status, item.mttr_hours, item.sla_target_hours || 24.0).label }}
                  </span>
                </td>
              </tr>
            </template>

            <!-- 2. Filas de Técnicos -->
            <template v-else-if="activeTab === 'technician'">
              <tr
                v-for="item in filteredItems"
                :key="item.technician_id"
                style="border-bottom: 1px solid #efefef;"
                :style="{ backgroundColor: !item.is_active ? '#fafafa' : '#ffffff' }"
              >
                <td style="padding: 12px 16px;">
                  <div style="font-weight: 600; color: #000000;">
                    {{ item.technician_name }}
                    <span v-if="!item.is_active" style="display: inline-block; margin-left: 6px; padding: 2px 6px; font-size: 11px; background: #e2e8f0; color: #475569; border-radius: 4px; font-weight: 500;">(Inactivo)</span>
                  </div>
                  <div style="font-size: 11px; color: #6c7e9d; margin-top: 2px;">
                    ID Técnico: #{{ item.technician_id }}
                  </div>
                </td>
                <td style="padding: 12px 16px; text-align: center; font-weight: 600;">
                  {{ item.tickets_resolved }}
                </td>
                <td style="padding: 12px 16px; font-weight: 600; color: #2560ff;">
                  {{ item.mttr_formatted }}
                </td>
                <td style="padding: 12px 16px; color: #434c5f;">
                  {{ item.mttr_hours !== null && item.mttr_hours !== undefined ? item.mttr_hours + 'h' : 'N/A' }}
                </td>
              </tr>
            </template>

            <!-- 3. Filas de Tipos de Máquina (con Marcado Especial Alimentos Perecederos) -->
            <template v-else-if="activeTab === 'machine_type'">
              <tr
                v-for="item in filteredItems"
                :key="item.machine_type"
                style="border-bottom: 1px solid #efefef;"
                :style="{ backgroundColor: item.is_perishable ? (item.sla_status === 'BREACHED' ? '#fff1f2' : '#fffbf0') : '#ffffff' }"
              >
                <td style="padding: 12px 16px;">
                  <div style="font-weight: 600; color: #000000; display: flex; align-items: center; gap: 6px;">
                    <span>{{ item.display_name }}</span>
                    <span v-if="item.is_perishable" style="display: inline-block; padding: 2px 6px; font-size: 11px; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; border-radius: 4px; font-weight: 700;">
                      🥪 Perecedero (Art. II)
                    </span>
                  </div>
                  <div style="font-size: 11px; color: #6c7e9d; margin-top: 2px;">
                    Clave: {{ item.machine_type }}
                  </div>
                </td>
                <td style="padding: 12px 16px; text-align: center; font-weight: 600;">
                  {{ item.tickets_resolved }}
                </td>
                <td style="padding: 12px 16px; font-weight: 600;" :style="{ color: item.is_perishable && item.sla_status === 'BREACHED' ? '#dc2626' : '#2560ff' }">
                  {{ item.mttr_formatted }}
                </td>
                <td style="padding: 12px 16px; font-weight: 600;" :style="{ color: item.is_perishable && item.sla_status === 'BREACHED' ? '#dc2626' : '#434c5f' }">
                  {{ item.mttr_hours !== null && item.mttr_hours !== undefined ? item.mttr_hours + 'h' : 'N/A' }}
                </td>
                <td style="padding: 12px 16px; font-weight: 600;" :style="{ color: item.is_perishable ? '#92400e' : '#6c7e9d' }">
                  {{ item.sla_target_hours || (item.is_perishable ? 4.0 : 24.0) }}h
                </td>
                <td style="padding: 12px 16px;">
                  <span
                    :style="{ display: 'inline-block', padding: '3px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: '700', backgroundColor: getSlaBadge(item.sla_status, item.mttr_hours, item.sla_target_hours || (item.is_perishable ? 4.0 : 24.0)).bg, color: getSlaBadge(item.sla_status, item.mttr_hours, item.sla_target_hours || (item.is_perishable ? 4.0 : 24.0)).color, border: '1px solid ' + getSlaBadge(item.sla_status, item.mttr_hours, item.sla_target_hours || (item.is_perishable ? 4.0 : 24.0)).border }"
                  >
                    {{ getSlaBadge(item.sla_status, item.mttr_hours, item.sla_target_hours || (item.is_perishable ? 4.0 : 24.0)).label }}
                  </span>
                </td>
              </tr>
            </template>

            <!-- 4. Filas de Tipologías de Avería -->
            <template v-else-if="activeTab === 'category'">
              <tr
                v-for="item in filteredItems"
                :key="item.category"
                style="border-bottom: 1px solid #efefef;"
              >
                <td style="padding: 12px 16px;">
                  <div style="font-weight: 600; color: #000000;">
                    {{ formatCategoryName(item.category) }}
                  </div>
                  <div style="font-size: 11px; color: #6c7e9d; margin-top: 2px;">
                    Código: {{ item.category }}
                  </div>
                </td>
                <td style="padding: 12px 16px; text-align: center; font-weight: 600;">
                  {{ item.tickets_resolved }}
                </td>
                <td style="padding: 12px 16px; font-weight: 600; color: #2560ff;">
                  {{ item.mttr_formatted }}
                </td>
                <td style="padding: 12px 16px; color: #434c5f;">
                  {{ item.mttr_minutes !== null ? (item.mttr_minutes / 60).toFixed(1) + 'h' : 'N/A' }}
                </td>
              </tr>
            </template>

          </tbody>

        </table>

      </div>

    </div>
  `
};

export default MetricBreakdownTable;
