/**
 * VendGuard - SiteSanitaryStatusTab (SiteSanitaryStatusTab.js)
 * 
 * Pestaña de Estado Sanitario y Semáforos Higiénicos para el Portal de Responsable de Sede (RF-PREV-06, RF-PREV-07, Art. II, Art. V.4).
 * Cumple con:
 * - EARS 6.2: Muestra el semáforo sanitario de cada máquina del edificio, fecha de última desinfección y último registro térmico.
 * - EARS 7.1 y 7.2: Acciones directas para consultar y descargar el Certificado Sanitario Individual y el Certificado Global de Sede.
 * - EARS 7.3: Alerta visual prominente ante certificados en estado SUSPENDIDO o máquinas en CUARENTENA (Art. II).
 * - Dogma Vanilla: Vue 3 ESM reactivo, sin dependencias externas.
 * - Dualismo Lingüístico: Lógica y nombres de métodos en inglés; textos, semáforos y mensajes en español.
 */

import { api } from '../api.js';

export const SiteSanitaryStatusTab = {
  name: 'SiteSanitaryStatusTab',
  emits: ['view-certificate', 'view-global-certificate'],
  data() {
    return {
      loading: false,
      error: '',
      statusData: null,
      activeFilter: 'all', // 'all' | 'quarantine_expired' | 'valid'
      searchQuery: ''
    };
  },
  computed: {
    location() {
      return this.statusData?.location || {};
    },
    globalStatus() {
      return this.statusData?.global_status || 'CONFORME';
    },
    hasQuarantineOrExpired() {
      return Boolean(this.statusData?.has_quarantine_or_expired);
    },
    machines() {
      return this.statusData?.machines || [];
    },
    counts() {
      const list = this.machines;
      return {
        total: list.length,
        green: list.filter(m => m.semaphore === 'GREEN').length,
        yellow: list.filter(m => m.semaphore === 'YELLOW').length,
        red: list.filter(m => m.semaphore === 'RED').length,
        quarantine: list.filter(m => m.semaphore === 'QUARANTINE').length,
        pause: list.filter(m => m.semaphore === 'SEASONAL_PAUSE').length
      };
    },
    filteredMachines() {
      let result = this.machines;

      // Filter by status
      if (this.activeFilter === 'quarantine_expired') {
        result = result.filter(m => m.semaphore === 'QUARANTINE' || m.semaphore === 'RED');
      } else if (this.activeFilter === 'valid') {
        result = result.filter(m => m.semaphore === 'GREEN' || m.semaphore === 'YELLOW');
      }

      // Filter by search query
      if (this.searchQuery.trim()) {
        const q = this.searchQuery.toLowerCase().trim();
        result = result.filter(m => 
          (m.code && m.code.toLowerCase().includes(q)) ||
          (m.model && m.model.toLowerCase().includes(q)) ||
          (m.floor_wing && m.floor_wing.toLowerCase().includes(q)) ||
          (m.machine_type && m.machine_type.toLowerCase().includes(q))
        );
      }

      return result;
    }
  },
  mounted() {
    this.loadStatus();
  },
  methods: {
    async loadStatus() {
      this.loading = true;
      this.error = '';
      try {
        const data = await api.site.getSanitaryStatus();
        this.statusData = data;
      } catch (err) {
        this.error = err.message || 'No se pudo cargar el estado higiénico-sanitario de la sede.';
      } finally {
        this.loading = false;
      }
    },

    setFilter(filter) {
      this.activeFilter = filter;
    },

    getSemaphoreBadge(semaphore) {
      switch (semaphore) {
        case 'GREEN':
          return {
            label: 'VIGENTE',
            icon: '🟢',
            color: '#15803d',
            bg: '#dcfce7',
            border: '#86efac'
          };
        case 'YELLOW':
          return {
            label: 'PRÓXIMA A VENCER',
            icon: '🟡',
            color: '#b45309',
            bg: '#fef3c7',
            border: '#fcd34d'
          };
        case 'RED':
          return {
            label: 'VENCIDA',
            icon: '🔴',
            color: '#b91c1c',
            bg: '#fee2e2',
            border: '#fca5a5'
          };
        case 'QUARANTINE':
          return {
            label: 'CUARENTENA SANITARIA',
            icon: '🛑',
            color: '#ffffff',
            bg: '#dc2626',
            border: '#b91c1c'
          };
        case 'SEASONAL_PAUSE':
          return {
            label: 'PAUSA ESTACIONAL',
            icon: '⏸️',
            color: '#475569',
            bg: '#f1f5f9',
            border: '#cbd5e1'
          };
        default:
          return {
            label: semaphore || 'DESCONOCIDO',
            icon: '⚪',
            color: '#64748b',
            bg: '#f8fafc',
            border: '#e2e8f0'
          };
      }
    },

    formatDate(dateStr) {
      if (!dateStr) return 'N/D';
      try {
        const d = new Date(dateStr);
        return d.toLocaleDateString('es-ES', {
          day: '2-digit',
          month: '2-digit',
          year: 'numeric'
        });
      } catch (e) {
        return dateStr;
      }
    },

    onViewCertificate(machine) {
      this.$emit('view-certificate', machine.code);
    },

    onViewGlobalCertificate() {
      this.$emit('view-global-certificate');
    }
  },
  template: `
    <div class="vg-site-sanitary-tab">
      <!-- Top Action Bar & Site Global Status -->
      <div
        class="vg-card"
        style="border-radius: var(--radius-card, 8px); padding: 18px 22px; margin-bottom: 20px; background: #ffffff; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px;"
      >
        <div>
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
            <span style="font-size: 20px;">🛡️</span>
            <h2 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 19px; font-weight: 700; color: var(--color-ink, #000000); margin: 0;">
              Control Higiénico-Sanitario y Certificados
            </h2>
          </div>
          <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-slate, #2c333f); margin: 0;">
            Supervisión periódica oficial de desinfección, control térmico de alimentos (Art. II) y certificados vigentes.
          </p>
        </div>

        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
          <!-- Global Certificate Button -->
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            style="border-radius: var(--radius-interactive, 4px); font-size: 13px; height: 36px; display: inline-flex; align-items: center; gap: 6px;"
            :disabled="loading"
            @click="onViewGlobalCertificate"
          >
            <span>📄 Certificado Global de Sede</span>
          </button>

          <!-- Refresh Button -->
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            style="border-radius: var(--radius-interactive, 4px); font-size: 13px; height: 36px;"
            :disabled="loading"
            @click="loadStatus"
          >
            <span v-if="!loading">🔄 Actualizar</span>
            <span v-else>Cargando...</span>
          </button>
        </div>
      </div>

      <!-- Global Verdict Conditioned Alert (Art. II / RF-PREV-07) -->
      <div
        v-if="hasQuarantineOrExpired"
        style="background-color: #fee2e2; border: 2px solid #f87171; color: #991b1b; padding: 16px 20px; border-radius: var(--radius-card, 8px); margin-bottom: 20px; display: flex; align-items: flex-start; gap: 14px;"
        role="alert"
      >
        <div style="font-size: 24px; line-height: 1;">⚠️</div>
        <div>
          <div style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 15px; font-weight: 800; text-transform: uppercase;">
            Dictamen General de Sede: CONDICIONADO
          </div>
          <p style="margin: 4px 0 0; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; line-height: 1.5;">
            Se ha detectado al menos una máquina en <strong>cuarentena sanitaria</strong> o con inspección reglamentaria <strong>vencida</strong>.
            La aptitud higiénica global del edificio se encuentra condicionada hasta la completa subsanación de las incidencias técnicas.
          </p>
        </div>
      </div>

      <div
        v-else-if="statusData && !hasQuarantineOrExpired"
        style="background-color: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 12px 18px; border-radius: var(--radius-card, 8px); margin-bottom: 20px; display: flex; align-items: center; gap: 10px;"
      >
        <span style="font-size: 18px;">✅</span>
        <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 13.5px; font-weight: 600;">
          Dictamen General de Sede: CONFORME. Todas las máquinas cumplen con el protocolo higiénico-sanitario vigente.
        </div>
      </div>

      <!-- KPI Summary Pills -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-bottom: 20px;">
        <!-- Total -->
        <div style="background: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 10px 14px; text-align: center;">
          <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b;">Total Máquinas</div>
          <div style="font-size: 20px; font-weight: 800; color: #0f172a;">{{ counts.total }}</div>
        </div>
        <!-- Green -->
        <div style="background: #ffffff; border: 1px solid #86efac; border-radius: var(--radius-card, 8px); padding: 10px 14px; text-align: center;">
          <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #166534;">🟢 Vigentes</div>
          <div style="font-size: 20px; font-weight: 800; color: #15803d;">{{ counts.green }}</div>
        </div>
        <!-- Yellow -->
        <div style="background: #ffffff; border: 1px solid #fcd34d; border-radius: var(--radius-card, 8px); padding: 10px 14px; text-align: center;">
          <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #b45309;">🟡 Próximas</div>
          <div style="font-size: 20px; font-weight: 800; color: #d97706;">{{ counts.yellow }}</div>
        </div>
        <!-- Red -->
        <div style="background: #ffffff; border: 1px solid #fca5a5; border-radius: var(--radius-card, 8px); padding: 10px 14px; text-align: center;">
          <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #b91c1c;">🔴 Vencidas</div>
          <div style="font-size: 20px; font-weight: 800; color: #dc2626;">{{ counts.red }}</div>
        </div>
        <!-- Quarantine -->
        <div style="background: #fee2e2; border: 2px solid #ef4444; border-radius: var(--radius-card, 8px); padding: 10px 14px; text-align: center;">
          <div style="font-size: 11px; text-transform: uppercase; font-weight: 800; color: #991b1b;">🛑 Cuarentenas</div>
          <div style="font-size: 20px; font-weight: 800; color: #b91c1c;">{{ counts.quarantine }}</div>
        </div>
        <!-- Pause -->
        <div v-if="counts.pause > 0" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: var(--radius-card, 8px); padding: 10px 14px; text-align: center;">
          <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #475569;">⏸️ En Pausa</div>
          <div style="font-size: 20px; font-weight: 800; color: #475569;">{{ counts.pause }}</div>
        </div>
      </div>

      <!-- Filters & Search Toolbar -->
      <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px;">
        <!-- Filter Tabs -->
        <div style="display: flex; gap: 6px; background-color: #f3f4f6; padding: 4px; border-radius: var(--radius-interactive, 4px);">
          <button
            type="button"
            class="vg-btn"
            :style="{
              backgroundColor: activeFilter === 'all' ? '#ffffff' : 'transparent',
              color: activeFilter === 'all' ? 'var(--color-primary, #2560ff)' : 'var(--color-slate, #2c333f)',
              boxShadow: activeFilter === 'all' ? '0 1px 2px rgba(0,0,0,0.05)' : 'none',
              height: '32px',
              fontSize: '13px',
              padding: '0 12px',
              borderRadius: 'var(--radius-interactive, 4px)'
            }"
            @click="setFilter('all')"
          >
            Todas ({{ counts.total }})
          </button>
          <button
            type="button"
            class="vg-btn"
            :style="{
              backgroundColor: activeFilter === 'quarantine_expired' ? '#ffffff' : 'transparent',
              color: activeFilter === 'quarantine_expired' ? '#b91c1c' : 'var(--color-slate, #2c333f)',
              boxShadow: activeFilter === 'quarantine_expired' ? '0 1px 2px rgba(0,0,0,0.05)' : 'none',
              height: '32px',
              fontSize: '13px',
              padding: '0 12px',
              borderRadius: 'var(--radius-interactive, 4px)'
            }"
            @click="setFilter('quarantine_expired')"
          >
            Cuarentena / Vencidas ({{ counts.quarantine + counts.red }})
          </button>
          <button
            type="button"
            class="vg-btn"
            :style="{
              backgroundColor: activeFilter === 'valid' ? '#ffffff' : 'transparent',
              color: activeFilter === 'valid' ? '#15803d' : 'var(--color-slate, #2c333f)',
              boxShadow: activeFilter === 'valid' ? '0 1px 2px rgba(0,0,0,0.05)' : 'none',
              height: '32px',
              fontSize: '13px',
              padding: '0 12px',
              borderRadius: 'var(--radius-interactive, 4px)'
            }"
            @click="setFilter('valid')"
          >
            Vigentes ({{ counts.green + counts.yellow }})
          </button>
        </div>

        <!-- Search Input -->
        <div style="flex: 1; max-width: 280px;">
          <input
            v-model="searchQuery"
            type="text"
            class="vg-input"
            placeholder="Buscar por código o modelo..."
            style="height: 34px; font-size: 13px; border-radius: var(--radius-interactive, 4px);"
          />
        </div>
      </div>

      <!-- Loading / Error / Empty States -->
      <div v-if="loading && machines.length === 0" style="text-align: center; padding: 48px;">
        <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 15px; color: var(--color-slate, #2c333f);">
          Cargando estado higiénico y semáforos sanitarios...
        </p>
      </div>

      <div
        v-else-if="error"
        style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 16px; border-radius: var(--radius-card, 8px); margin-bottom: 24px; text-align: center;"
      >
        <p style="margin: 0 0 10px 0;">{{ error }}</p>
        <button type="button" class="vg-btn vg-btn-secondary" @click="loadStatus">Reintentar</button>
      </div>

      <div
        v-else-if="filteredMachines.length === 0"
        style="text-align: center; padding: 48px; background-color: #ffffff; border: 1px dashed var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px);"
      >
        <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-muted, #6c7e9d); margin: 0;">
          No se encontraron máquinas con el filtro seleccionado.
        </p>
      </div>

      <!-- Sanitary Table / Cards -->
      <div v-else class="vg-card" style="border-radius: var(--radius-card, 8px); overflow: hidden; background: #ffffff;">
        <div style="overflow-x: auto;">
          <table style="width: 100%; border-collapse: collapse; font-family: var(--font-body, Inter, sans-serif); font-size: 13px;">
            <thead>
              <tr style="background-color: #f8fafc; border-bottom: 1px solid var(--color-hairline, #c8cfda); text-align: left;">
                <th style="padding: 12px 16px; font-weight: 600; color: #475569;">Máquina</th>
                <th style="padding: 12px 16px; font-weight: 600; color: #475569;">Ubicación</th>
                <th style="padding: 12px 16px; font-weight: 600; color: #475569; text-align: center;">Semáforo Sanitario</th>
                <th style="padding: 12px 16px; font-weight: 600; color: #475569;">Última Desinfección</th>
                <th style="padding: 12px 16px; font-weight: 600; color: #475569;">Temperatura Sonda</th>
                <th style="padding: 12px 16px; font-weight: 600; color: #475569;">Vigencia</th>
                <th style="padding: 12px 16px; font-weight: 600; color: #475569; text-align: right;">Certificado Oficial</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="mach in filteredMachines"
                :key="mach.code"
                :style="{
                  borderBottom: '1px solid #f1f5f9',
                  backgroundColor: mach.semaphore === 'QUARANTINE' ? '#fff5f5' : '#ffffff'
                }"
              >
                <!-- Machine Code & Model -->
                <td style="padding: 12px 16px;">
                  <div style="font-family: monospace; font-weight: 700; color: #0f172a; font-size: 13.5px;">
                    {{ mach.code }}
                  </div>
                  <div style="font-size: 12px; color: #64748b;">
                    {{ mach.model || 'Vending' }}
                  </div>
                </td>

                <!-- Location -->
                <td style="padding: 12px 16px; color: #334155;">
                  {{ mach.floor_wing || 'Planta General' }}
                </td>

                <!-- Semaphore Badge -->
                <td style="padding: 12px 16px; text-align: center;">
                  <span
                    :style="{
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: '5px',
                      padding: '4px 10px',
                      borderRadius: '16px',
                      fontSize: '11px',
                      fontWeight: '700',
                      letterSpacing: '0.03em',
                      backgroundColor: getSemaphoreBadge(mach.semaphore).bg,
                      color: getSemaphoreBadge(mach.semaphore).color,
                      border: '1px solid ' + getSemaphoreBadge(mach.semaphore).border
                    }"
                  >
                    <span>{{ getSemaphoreBadge(mach.semaphore).icon }}</span>
                    <span>{{ getSemaphoreBadge(mach.semaphore).label }}</span>
                  </span>
                  <div v-if="mach.notice" style="font-size: 11px; color: #b91c1c; margin-top: 4px; font-weight: 600;">
                    {{ mach.notice }}
                  </div>
                </td>

                <!-- Last Inspection / Disinfection -->
                <td style="padding: 12px 16px; color: #334155;">
                  {{ formatDate(mach.last_inspection_date) }}
                </td>

                <!-- Probe Temperature -->
                <td style="padding: 12px 16px;">
                  <span
                    v-if="mach.last_temperature_celsius !== null && mach.last_temperature_celsius !== undefined"
                    :style="{
                      fontWeight: '700',
                      color: mach.last_temperature_celsius > 4.0 ? '#dc2626' : '#15803d'
                    }"
                  >
                    {{ mach.last_temperature_celsius }} °C
                  </span>
                  <span v-else style="color: #94a3b8;">N/A</span>
                </td>

                <!-- Valid Until -->
                <td style="padding: 12px 16px; color: #334155;">
                  <span v-if="mach.valid_until">
                    {{ formatDate(mach.valid_until) }}
                  </span>
                  <span v-else-if="mach.semaphore === 'QUARANTINE'" style="color: #dc2626; font-weight: 700;">
                    Bloqueada
                  </span>
                  <span v-else style="color: #94a3b8;">-</span>
                </td>

                <!-- Certificate Action Button -->
                <td style="padding: 12px 16px; text-align: right;">
                  <button
                    type="button"
                    class="vg-btn vg-btn-secondary"
                    style="font-size: 12px; height: 30px; padding: 0 10px; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 5px;"
                    @click="onViewCertificate(mach)"
                  >
                    <span>📜 Ver Certificado</span>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  `
};

export default SiteSanitaryStatusTab;
