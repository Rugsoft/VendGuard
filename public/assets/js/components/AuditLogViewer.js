/**
 * VendGuard - AuditLogViewer Component (AuditLogViewer.js)
 * 
 * Visor cronológico reactivo e interactivo del Registro Inmutable de Auditoría (RF-05, EARS 5.3, 5.5).
 * Da estricto cumplimiento a la inmutabilidad de datos (RNF-03) y trazabilidad obligatoria de averías
 * y repuestos sustituidos (Art. III.3 y Art. V.1).
 * 
 * Características:
 * 1. Consulta cronológica con filtros por tipo de entidad, ID, acción, usuario y rango de fechas.
 * 2. Desglose amigable de transiciones de estado, diagnósticos técnicos y piezas sustituidas.
 * 3. Modal interactivo de inspección JSON para ver el payload íntegro de la auditoría.
 * 4. Paginación reactiva completa (página actual, límite de resultados y páginas totales).
 * 5. Descarga de datos en CSV con aviso del tope de seguridad de 10.000 filas (RF-06, EARS 6.2).
 * 
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias de npm).
 */

import { api } from '../api.js';

export const AuditLogViewer = {
  name: 'AuditLogViewer',
  data() {
    return {
      events: [],
      isLoading: false,
      errorMessage: '',
      // Filtros
      filterEntityType: '',
      filterEntityId: '',
      filterAction: '',
      filterUserId: '',
      filterFrom: '',
      filterTo: '',
      // Paginación
      currentPage: 1,
      limit: 25,
      totalRecords: 0,
      totalPages: 1,
      // Inspección de evento
      selectedEvent: null,
      showDetailModal: false
    };
  },
  mounted() {
    this.fetchAuditEvents();
  },
  computed: {
    hasActiveFilters() {
      return Boolean(
        this.filterEntityType ||
        this.filterEntityId ||
        this.filterAction ||
        this.filterUserId ||
        this.filterFrom ||
        this.filterTo
      );
    }
  },
  methods: {
    async fetchAuditEvents(page = 1) {
      this.isLoading = true;
      this.errorMessage = '';
      this.currentPage = page;

      try {
        const params = {
          page: this.currentPage,
          limit: this.limit
        };

        if (this.filterEntityType) params.entity_type = this.filterEntityType;
        if (this.filterEntityId) params.entity_id = this.filterEntityId;
        if (this.filterAction) params.action = this.filterAction;
        if (this.filterUserId) params.user_id = this.filterUserId;
        if (this.filterFrom) params.from = this.filterFrom;
        if (this.filterTo) params.to = this.filterTo;

        const res = await api.auditLog.getEvents(params);
        const data = res?.data !== undefined ? res.data : res;
        if (data && (data.items || data.total_records !== undefined)) {
          this.events = data.items || [];
          this.totalRecords = data.total_records || 0;
          this.totalPages = data.total_pages || 1;
        } else {
          this.events = [];
          this.totalRecords = 0;
          this.totalPages = 1;
        }
      } catch (err) {
        this.errorMessage = err.message || 'Error al cargar el registro de auditoría.';
        this.events = [];
      } finally {
        this.isLoading = false;
      }
    },
    applyFilters() {
      this.fetchAuditEvents(1);
    },
    resetFilters() {
      this.filterEntityType = '';
      this.filterEntityId = '';
      this.filterAction = '';
      this.filterUserId = '';
      this.filterFrom = '';
      this.filterTo = '';
      this.fetchAuditEvents(1);
    },
    openEventDetail(event) {
      this.selectedEvent = event;
      this.showDetailModal = true;
    },
    closeEventDetail() {
      this.selectedEvent = null;
      this.showDetailModal = false;
    },
    formatActionBadge(action) {
      const map = {
        'RESOLVE_INCIDENT': { label: 'Resolución de Avería', bg: '#dcfce7', color: '#15803d', border: '#86efac' },
        'START_INTERVENTION': { label: 'Inicio de Intervención', bg: '#e0f2fe', color: '#0369a1', border: '#7dd3fc' },
        'PAUSE_INTERVENTION': { label: 'Pausa por Repuestos', bg: '#fef3c7', color: '#92400e', border: '#fde68a' },
        'STATUS_CHANGE': { label: 'Cambio de Estado', bg: '#e5f2fc', color: '#2560ff', border: '#bfdbfe' },
        'ASSIGN_TECHNICIAN': { label: 'Asignación de Técnico', bg: '#f3e8ff', color: '#7e22ce', border: '#d8b4fe' },
        'REOPEN_TICKET': { label: 'Reapertura de Ticket', bg: '#ffedd5', color: '#c2410c', border: '#fdba74' },
        'CANCEL_INCIDENT': { label: 'Cancelación de Avería', bg: '#fee2e2', color: '#dc2626', border: '#fca5a5' },
        'CREATE_TICKET': { label: 'Creación de Ticket', bg: '#f1f5f9', color: '#334155', border: '#cbd5e1' },
        'UPDATE_MACHINE': { label: 'Modificación de Máquina', bg: '#f8fafc', color: '#475569', border: '#cbd5e1' },
        'UPDATE_LOCATION': { label: 'Modificación de Sede', bg: '#f8fafc', color: '#475569', border: '#cbd5e1' }
      };

      return map[action] || { label: action, bg: '#f1f5f9', color: '#475569', border: '#cbd5e1' };
    },
    formatEntityName(type, id) {
      if (type === 'TICKET') return `Avería #${id}`;
      if (type === 'MACHINE') return `Máquina #${id}`;
      if (type === 'LOCATION') return `Sede #${id}`;
      return `${type} #${id}`;
    },
    downloadAuditCsv() {
      const params = {};
      if (this.filterEntityType) params.entity_type = this.filterEntityType;
      if (this.filterEntityId) params.entity_id = this.filterEntityId;
      if (this.filterAction) params.action = this.filterAction;
      if (this.filterUserId) params.user_id = this.filterUserId;
      if (this.filterFrom) params.from = this.filterFrom;
      if (this.filterTo) params.to = this.filterTo;

      const url = api.auditLog.exportCsvUrl(params);
      window.open(url, '_blank');
    }
  },
  template: `
    <div class="vg-audit-viewer-card" style="background: #ffffff; border-radius: 8px; border: 1px solid #c8cfda; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden;">
      
      <!-- Cabecera de la Sección y Botón CSV -->
      <div style="padding: 16px 20px; border-bottom: 1px solid #c8cfda; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; background: #ffffff;">
        <div>
          <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #000000; display: flex; align-items: center; gap: 8px;">
            <span>🛡️ Registro Inmutable de Auditoría</span>
            <span style="font-size: 11px; padding: 2px 6px; background: #f1f5f9; color: #475569; border-radius: 4px; border: 1px solid #cbd5e1; font-weight: 600;">Append-Only (Art. III.3)</span>
          </h3>
          <p style="margin: 4px 0 0 0; font-size: 12px; color: #6c7e9d;">
            Trazabilidad permanente de todas las intervenciones técnicas, cambios de estado y modificaciones del parque.
          </p>
        </div>

        <div style="display: flex; gap: 8px; align-items: center;">
          <button
            type="button"
            @click="downloadAuditCsv"
            style="display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; background: #ffffff; border: 1px solid #c8cfda; border-radius: 4px; font-size: 13px; font-weight: 600; color: #2c333f; cursor: pointer; transition: all 0.2s ease;"
            title="Exportar archivo CSV con codificación UTF-8 BOM"
          >
            <span>📥 Exportar CSV</span>
            <span v-if="totalRecords > 10000" style="font-size: 10px; color: #b91c1c; font-weight: 700;" title="Tope de seguridad alcanzado">(Tope 10k)</span>
          </button>
        </div>
      </div>

      <!-- Barra de Filtros de Auditoría -->
      <div style="padding: 14px 20px; border-bottom: 1px solid #c8cfda; background: #f9fafb; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
        
        <!-- Filtro Entidad -->
        <select
          v-model="filterEntityType"
          @change="applyFilters"
          style="padding: 6px 10px; font-size: 13px; border: 1px solid #c8cfda; border-radius: 4px; background: #ffffff; color: #2c333f;"
        >
          <option value="">Todas las Entidades</option>
          <option value="TICKET">Incidencias / Tickets</option>
          <option value="MACHINE">Máquinas Vending</option>
          <option value="LOCATION">Sedes Clientes</option>
        </select>

        <!-- Filtro Acción -->
        <select
          v-model="filterAction"
          @change="applyFilters"
          style="padding: 6px 10px; font-size: 13px; border: 1px solid #c8cfda; border-radius: 4px; background: #ffffff; color: #2c333f;"
        >
          <option value="">Todas las Acciones</option>
          <option value="RESOLVE_INCIDENT">Resolución de Avería</option>
          <option value="START_INTERVENTION">Inicio de Intervención</option>
          <option value="PAUSE_INTERVENTION">Pausa por Repuestos</option>
          <option value="STATUS_CHANGE">Cambio de Estado</option>
          <option value="ASSIGN_TECHNICIAN">Asignación de Técnico</option>
          <option value="REOPEN_TICKET">Reapertura de Ticket</option>
          <option value="CANCEL_INCIDENT">Cancelación de Avería</option>
          <option value="CREATE_TICKET">Creación de Ticket</option>
        </select>

        <!-- Filtro ID Entidad -->
        <input
          type="number"
          v-model="filterEntityId"
          placeholder="ID Entidad..."
          @keyup.enter="applyFilters"
          style="width: 100px; padding: 6px 10px; font-size: 13px; border: 1px solid #c8cfda; border-radius: 4px; background: #ffffff;"
        />

        <!-- Filtro Rango de Fechas -->
        <input
          type="date"
          v-model="filterFrom"
          @change="applyFilters"
          title="Fecha Desde"
          style="padding: 5px 8px; font-size: 12px; border: 1px solid #c8cfda; border-radius: 4px; background: #ffffff;"
        />
        <span style="font-size: 12px; color: #6c7e9d;">a</span>
        <input
          type="date"
          v-model="filterTo"
          @change="applyFilters"
          title="Fecha Hasta"
          style="padding: 5px 8px; font-size: 12px; border: 1px solid #c8cfda; border-radius: 4px; background: #ffffff;"
        />

        <button
          type="button"
          @click="applyFilters"
          style="padding: 6px 12px; background: #2560ff; color: #ffffff; border: none; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer;"
        >
          Filtrar
        </button>

        <button
          v-if="hasActiveFilters"
          type="button"
          @click="resetFilters"
          style="padding: 6px 10px; background: transparent; border: 1px solid #c8cfda; border-radius: 4px; font-size: 13px; color: #6c7e9d; cursor: pointer;"
        >
          Limpiar
        </button>
      </div>

      <!-- Alerta si supera los 10.000 registros -->
      <div v-if="totalRecords > 10000" style="padding: 8px 20px; background: #fffbeb; border-bottom: 1px solid #fef3c7; color: #92400e; font-size: 12px; display: flex; align-items: center; gap: 8px;">
        <span>⚠️</span>
        <span>El registro supera los 10.000 eventos ({{ totalRecords }} totales). La exportación CSV descargará los 10.000 más recientes. Por favor acote sus filtros de fecha para mayor precisión.</span>
      </div>

      <!-- Mensaje de Error -->
      <div v-if="errorMessage" style="padding: 16px 20px; background: #fee2e2; color: #b91c1c; font-size: 13px; border-bottom: 1px solid #fca5a5;">
        {{ errorMessage }}
      </div>

      <!-- Estado de Carga -->
      <div v-if="isLoading" style="padding: 40px; text-align: center; color: #2560ff; font-weight: 600;">
        Cargando eventos de auditoría inmutable...
      </div>

      <!-- Tabla de Eventos -->
      <div v-else style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
          <thead>
            <tr style="background: #ffffff; border-bottom: 2px solid #c8cfda; color: #434c5f;">
              <th style="padding: 12px 16px; font-weight: 700; width: 80px;">Evento #</th>
              <th style="padding: 12px 16px; font-weight: 700; width: 150px;">Fecha y Hora</th>
              <th style="padding: 12px 16px; font-weight: 700; width: 140px;">Entidad</th>
              <th style="padding: 12px 16px; font-weight: 700;">Acción Registrada</th>
              <th style="padding: 12px 16px; font-weight: 700;">Usuario / Actor</th>
              <th style="padding: 12px 16px; font-weight: 700; text-align: right; width: 110px;">Detalles</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="events.length === 0">
              <td colspan="6" style="padding: 32px; text-align: center; color: #6c7e9d;">
                No se encontraron eventos de auditoría para los criterios seleccionados.
              </td>
            </tr>

            <tr
              v-for="ev in events"
              :key="ev.id"
              style="border-bottom: 1px solid #efefef; transition: background 0.15s ease;"
              onmouseover="this.style.backgroundColor='#f9fafb'"
              onmouseout="this.style.backgroundColor='#ffffff'"
            >
              <td style="padding: 12px 16px; font-family: monospace; color: #6c7e9d;">
                #{{ ev.id }}
              </td>
              <td style="padding: 12px 16px; font-size: 12px; color: #2c333f; white-space: nowrap;">
                {{ ev.timestamp }}
              </td>
              <td style="padding: 12px 16px; font-weight: 600; color: #000000;">
                {{ formatEntityName(ev.entity_type, ev.entity_id) }}
              </td>
              <td style="padding: 12px 16px;">
                <span
                  :style="{ display: 'inline-block', padding: '3px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: '700', backgroundColor: formatActionBadge(ev.action).bg, color: formatActionBadge(ev.action).color, border: '1px solid ' + formatActionBadge(ev.action).border }"
                >
                  {{ formatActionBadge(ev.action).label }}
                </span>
                
                <!-- Resumen de diagnóstico rápido si es resolución -->
                <div v-if="ev.details && ev.details.diagnosis" style="font-size: 11px; color: #434c5f; margin-top: 4px;">
                  🩺 <em>{{ ev.details.diagnosis }}</em>
                </div>
              </td>
              <td style="padding: 12px 16px;">
                <div style="font-weight: 600; color: #000000;">
                  {{ ev.user?.name || 'Sistema / QR Público' }}
                </div>
                <div style="font-size: 11px; color: #6c7e9d; margin-top: 2px;">
                  Rol: {{ ev.user?.role || 'SYSTEM' }}
                </div>
              </td>
              <td style="padding: 12px 16px; text-align: right;">
                <button
                  type="button"
                  @click="openEventDetail(ev)"
                  style="padding: 4px 8px; font-size: 12px; font-weight: 600; background: #e5f2fc; color: #2560ff; border: 1px solid #bfdbfe; border-radius: 4px; cursor: pointer;"
                >
                  Ver JSON 🔍
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Paginación Reactiva -->
      <div style="padding: 14px 20px; border-top: 1px solid #c8cfda; background: #f9fafb; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px;">
        <div style="font-size: 12px; color: #6c7e9d;">
          Mostrando página <strong>{{ currentPage }}</strong> de <strong>{{ totalPages }}</strong> (Total: <strong>{{ totalRecords }}</strong> eventos registrados)
        </div>

        <div style="display: flex; gap: 6px; align-items: center;">
          <button
            type="button"
            :disabled="currentPage <= 1 || isLoading"
            @click="fetchAuditEvents(currentPage - 1)"
            style="padding: 5px 12px; font-size: 12px; font-weight: 600; border: 1px solid #c8cfda; border-radius: 4px; background: #ffffff; color: #2c333f; cursor: pointer;"
            :style="{ opacity: currentPage <= 1 ? 0.5 : 1, cursor: currentPage <= 1 ? 'not-allowed' : 'pointer' }"
          >
            ← Anterior
          </button>
          <button
            type="button"
            :disabled="currentPage >= totalPages || isLoading"
            @click="fetchAuditEvents(currentPage + 1)"
            style="padding: 5px 12px; font-size: 12px; font-weight: 600; border: 1px solid #c8cfda; border-radius: 4px; background: #ffffff; color: #2c333f; cursor: pointer;"
            :style="{ opacity: currentPage >= totalPages ? 0.5 : 1, cursor: currentPage >= totalPages ? 'not-allowed' : 'pointer' }"
          >
            Siguiente →
          </button>
        </div>
      </div>

      <!-- Modal de Inspección de Evento y Payload JSON -->
      <div
        v-if="showDetailModal && selectedEvent"
        style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; justify-content: center; align-items: center; z-index: 1000; padding: 20px;"
      >
        <div style="background: #ffffff; border-radius: 8px; max-width: 650px; width: 100%; max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 4px 12px rgba(0,0,0,0.15); overflow: hidden;">
          
          <!-- Encabezado Modal -->
          <div style="padding: 16px 20px; border-bottom: 1px solid #c8cfda; display: flex; justify-content: space-between; align-items: center; background: #f9fafb;">
            <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #000000;">
              🔍 Evento de Auditoría #{{ selectedEvent.id }}
            </h4>
            <button
              type="button"
              @click="closeEventDetail"
              style="background: transparent; border: none; font-size: 18px; color: #6c7e9d; cursor: pointer;"
            >
              ✕
            </button>
          </div>

          <!-- Contenido Modal -->
          <div style="padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; font-size: 13px;">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background: #f1f5f9; padding: 12px; border-radius: 6px;">
              <div><strong>Marca de Tiempo:</strong> {{ selectedEvent.timestamp }}</div>
              <div><strong>Entidad:</strong> {{ formatEntityName(selectedEvent.entity_type, selectedEvent.entity_id) }}</div>
              <div><strong>Acción:</strong> {{ selectedEvent.action }}</div>
              <div><strong>Actor:</strong> {{ selectedEvent.user?.name || 'Sistema' }} ({{ selectedEvent.user?.role || 'SYSTEM' }})</div>
            </div>

            <!-- Resumen Técnico de Intervención (Constitución Art. V.1) -->
            <div v-if="selectedEvent.details && (selectedEvent.details.diagnosis || selectedEvent.details.solution)" style="background: #fffbf0; border: 1px solid #fef3c7; border-radius: 6px; padding: 12px;">
              <div style="font-weight: 700; color: #92400e; margin-bottom: 6px;">
                📋 Datos Técnicos de Resolución Obligatorios (Art. III.3 / Art. V.1)
              </div>
              <div v-if="selectedEvent.details.diagnosis" style="margin-bottom: 4px;">
                <strong>Diagnóstico:</strong> {{ selectedEvent.details.diagnosis }}
              </div>
              <div v-if="selectedEvent.details.solution" style="margin-bottom: 4px;">
                <strong>Solución:</strong> {{ selectedEvent.details.solution }}
              </div>
              <div v-if="selectedEvent.details.parts_replaced && selectedEvent.details.parts_replaced.length">
                <strong>Piezas Sustituidas:</strong>
                <ul style="margin: 4px 0 0 16px; padding: 0;">
                  <li v-for="(p, idx) in selectedEvent.details.parts_replaced" :key="idx">{{ p }}</li>
                </ul>
              </div>
            </div>

            <!-- Payload JSON Íntegro -->
            <div>
              <div style="font-weight: 700; color: #434c5f; margin-bottom: 6px;">
                Payload JSON Inmutable:
              </div>
              <pre style="background: #1e293b; color: #f8fafc; padding: 12px; border-radius: 6px; font-size: 11px; overflow-x: auto; max-height: 250px; font-family: monospace;">{{ JSON.stringify(selectedEvent, null, 2) }}</pre>
            </div>

          </div>

          <!-- Pie del Modal -->
          <div style="padding: 12px 20px; border-top: 1px solid #c8cfda; background: #f9fafb; text-align: right;">
            <button
              type="button"
              @click="closeEventDetail"
              style="padding: 6px 14px; background: #2560ff; color: #ffffff; border: none; border-radius: 4px; font-weight: 600; cursor: pointer;"
            >
              Cerrar
            </button>
          </div>

        </div>
      </div>

    </div>
  `
};

export default AuditLogViewer;
