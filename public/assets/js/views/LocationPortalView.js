/**
 * VendGuard - LocationPortalView (LocationPortalView.js)
 * 
 * Portal for Location Responsible (RF-01, RF-02, RNF-02, T-34).
 * Allows the informant/concierge to:
 * 1. Log in swiftly using site code (e.g. 'SEDE-BCN-01') without passwords.
 * 2. View all machines installed in their building laid out in 8px card grid.
 * 3. Clearly differentiate between operational machines and those with active incidents.
 * 4. Filter by status (All, Active Incidents, Operational).
 * 5. Trigger report, comment or reopen workflows.
 */

import { api } from '../api.js';
import { store } from '../store.js';
import { MachineCard } from '../components/MachineCard.js';

export const LocationPortalView = {
  name: 'LocationPortalView',
  components: {
    MachineCard
  },
  emits: ['report-incident', 'add-comment', 'reopen-incident'],
  data() {
    return {
      siteCodeInput: '',
      loginError: '',
      isLoggingIn: false,
      machines: [],
      isLoadingMachines: false,
      machinesError: '',
      activeFilter: 'all' // 'all' | 'incident' | 'operational'
    };
  },
  computed: {
    isAuthenticated() {
      return store.isSiteSession && store.state.location !== null;
    },
    location() {
      return store.state.location;
    },
    filteredMachines() {
      if (this.activeFilter === 'incident') {
        return this.machines.filter(m => m.active_incident !== null);
      }
      if (this.activeFilter === 'operational') {
        return this.machines.filter(m => m.active_incident === null);
      }
      return this.machines;
    },
    totalCount() {
      return this.machines.length;
    },
    incidentCount() {
      return this.machines.filter(m => m.active_incident !== null).length;
    },
    operationalCount() {
      return this.machines.filter(m => m.active_incident === null).length;
    }
  },
  mounted() {
    if (this.isAuthenticated && this.location?.site_code) {
      this.loadMachines();
    }
  },
  methods: {
    /**
     * Authenticates user via site code (RF-01)
     */
    async handleSiteLogin() {
      const code = this.siteCodeInput.trim().toUpperCase();
      if (!code) {
        this.loginError = 'Por favor, introduzca un código de sede válido (ej: SEDE-BCN-01).';
        return;
      }

      this.loginError = '';
      this.isLoggingIn = true;
      store.setLoading(true);

      try {
        const response = await api.auth.siteLogin(code);
        store.setSiteSession(response.location, response.token);
        this.siteCodeInput = '';
        await this.loadMachines();
      } catch (err) {
        this.loginError = err.message || 'Código de sede no reconocido. Contacte con el servicio técnico.';
      } finally {
        this.isLoggingIn = false;
        store.setLoading(false);
      }
    },

    /**
     * Loads machines for the current authenticated location
     */
    async loadMachines() {
      if (!this.location?.site_code) return;

      this.isLoadingMachines = true;
      this.machinesError = '';
      store.setLoading(true);

      try {
        const data = await api.locations.getMachines(this.location.site_code);
        this.machines = Array.isArray(data) ? data : [];
      } catch (err) {
        this.machinesError = err.message || 'Error al cargar las máquinas de la sede.';
      } finally {
        this.isLoadingMachines = false;
        store.setLoading(false);
      }
    },

    setFilter(filter) {
      this.activeFilter = filter;
    },

    onReport(machine) {
      this.$emit('report-incident', machine);
    },

    onComment(machine) {
      this.$emit('add-comment', machine);
    },

    onReopen(machine) {
      this.$emit('reopen-incident', machine);
    }
  },
  template: `
    <div class="vg-location-portal" style="max-width: 1200px; margin: 0 auto; padding: 24px 16px;">
      <!-- ================================================================= -->
      <!-- STATE A: UNAUTHENTICATED SITE LOGIN FORM                          -->
      <!-- ================================================================= -->
      <div
        v-if="!isAuthenticated"
        style="max-width: 440px; margin: 40px auto; background-color: #ffffff; border: 1px solid var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px); padding: 32px 24px; box-shadow: var(--shadow-card, 0 1px 3px rgba(0, 0, 0, 0.04));"
      >
        <div style="text-align: center; margin-bottom: 24px;">
          <div
            style="width: 48px; height: 48px; border-radius: var(--radius-interactive, 4px); background-color: var(--color-primary, #2560ff); display: inline-flex; align-items: center; justify-content: center; color: #ffffff; font-size: 24px; font-weight: 700; margin-bottom: 12px; font-family: var(--font-display, 'DM Sans', sans-serif);"
          >
            V
          </div>
          <h2 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 22px; font-weight: 700; color: var(--color-ink, #000000); margin: 0;">
            Portal del Responsable
          </h2>
          <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-muted, #6c7e9d); margin-top: 6px;">
            Introduce el código alfanumérico de tu sede para gestionar las máquinas de tu centro.
          </p>
        </div>

        <form @submit.prevent="handleSiteLogin">
          <!-- Site Code Input -->
          <div style="margin-bottom: 16px;">
            <label
              for="site-code-input"
              style="display: block; font-family: var(--font-body, Inter, sans-serif); font-size: 13px; font-weight: 600; color: var(--color-slate, #2c333f); margin-bottom: 6px;"
            >
              Código de Sede / Edificio
            </label>
            <input
              id="site-code-input"
              v-model="siteCodeInput"
              type="text"
              class="vg-input"
              placeholder="Ej: SEDE-BCN-01"
              autocapitalize="characters"
              style="text-transform: uppercase; font-family: monospace; font-size: 15px; font-weight: 600; letter-spacing: 0.05em; border-radius: var(--radius-interactive, 4px);"
              :disabled="isLoggingIn"
              required
            />
          </div>

          <!-- Error Alert -->
          <div
            v-if="loginError"
            style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 10px 12px; border-radius: var(--radius-interactive, 4px); font-size: 13px; margin-bottom: 16px; font-family: var(--font-body, Inter, sans-serif);"
            role="alert"
          >
            {{ loginError }}
          </div>

          <!-- Submit Button -->
          <button
            type="submit"
            class="vg-btn vg-btn-primary"
            style="width: 100%; height: 40px; font-size: 15px; border-radius: var(--radius-interactive, 4px);"
            :disabled="isLoggingIn"
          >
            <span v-if="!isLoggingIn">Acceder a mi sede</span>
            <span v-else>Verificando sede...</span>
          </button>
        </form>

        <div style="text-align: center; margin-top: 20px; font-size: 12px; color: var(--color-ink-muted, #6c7e9d); font-family: var(--font-body, Inter, sans-serif);">
          ¿No conoces el código de tu centro? Consulta la etiqueta frontal de cualquiera de las máquinas de vending.
        </div>
      </div>

      <!-- ================================================================= -->
      <!-- STATE B: AUTHENTICATED LOCATION DASHBOARD                         -->
      <!-- ================================================================= -->
      <div v-else>
        <!-- Location Header Banner (8px card radius) -->
        <div
          class="vg-card"
          style="border-radius: var(--radius-card, 8px); margin-bottom: 24px; padding: 20px 24px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px;"
        >
          <div>
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
              <span
                style="background-color: var(--color-primary, #2560ff); color: #ffffff; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 700; padding: 3px 8px; border-radius: var(--radius-interactive, 4px);"
              >
                {{ location.site_code }}
              </span>
              <h2 style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 20px; font-weight: 700; color: var(--color-ink, #000000); margin: 0;">
                {{ location.name }}
              </h2>
            </div>
            <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-slate, #2c333f); margin: 0;">
              📍 {{ location.address }} · Contacto: {{ location.contact_name || 'No especificado' }}
            </p>
          </div>

          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            style="border-radius: var(--radius-interactive, 4px); font-size: 13px;"
            :disabled="isLoadingMachines"
            @click="loadMachines"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;" aria-hidden="true">
              <polyline points="23 4 23 10 17 10"></polyline>
              <polyline points="1 20 1 14 7 14"></polyline>
              <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
            </svg>
            Actualizar estado
          </button>
        </div>

        <!-- Filter Tabs & Stats -->
        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px;">
          <!-- Tabs -->
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
              Todas ({{ totalCount }})
            </button>
            <button
              type="button"
              class="vg-btn"
              :style="{
                backgroundColor: activeFilter === 'incident' ? '#ffffff' : 'transparent',
                color: activeFilter === 'incident' ? '#b91c1c' : 'var(--color-slate, #2c333f)',
                boxShadow: activeFilter === 'incident' ? '0 1px 2px rgba(0,0,0,0.05)' : 'none',
                height: '32px',
                fontSize: '13px',
                padding: '0 12px',
                borderRadius: 'var(--radius-interactive, 4px)'
              }"
              @click="setFilter('incident')"
            >
              Con Avería ({{ incidentCount }})
            </button>
            <button
              type="button"
              class="vg-btn"
              :style="{
                backgroundColor: activeFilter === 'operational' ? '#ffffff' : 'transparent',
                color: activeFilter === 'operational' ? '#15803d' : 'var(--color-slate, #2c333f)',
                boxShadow: activeFilter === 'operational' ? '0 1px 2px rgba(0,0,0,0.05)' : 'none',
                height: '32px',
                fontSize: '13px',
                padding: '0 12px',
                borderRadius: 'var(--radius-interactive, 4px)'
              }"
              @click="setFilter('operational')"
            >
              Operativas ({{ operationalCount }})
            </button>
          </div>

          <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-muted, #6c7e9d);">
            Mostrando {{ filteredMachines.length }} de {{ totalCount }} máquinas
          </div>
        </div>

        <!-- Machines Grid (8px cards) -->
        <div v-if="isLoadingMachines && machines.length === 0" style="text-align: center; padding: 48px;">
          <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 15px; color: var(--color-slate, #2c333f);">
            Cargando el parque de máquinas del centro...
          </p>
        </div>

        <div
          v-else-if="machinesError"
          style="background-color: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 16px; border-radius: var(--radius-card, 8px); margin-bottom: 24px; text-align: center;"
        >
          <p style="margin: 0 0 10px 0;">{{ machinesError }}</p>
          <button type="button" class="vg-btn vg-btn-secondary" @click="loadMachines">Reintentar</button>
        </div>

        <div
          v-else-if="filteredMachines.length === 0"
          style="text-align: center; padding: 48px; background-color: #ffffff; border: 1px dashed var(--color-hairline, #c8cfda); border-radius: var(--radius-card, 8px);"
        >
          <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 14px; color: var(--color-ink-muted, #6c7e9d); margin: 0;">
            No se han encontrado máquinas con el filtro seleccionado.
          </p>
        </div>

        <!-- Responsive Card Grid with 8px border radius cards -->
        <div
          v-else
          class="vg-machine-grid"
          style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;"
        >
          <MachineCard
            v-for="m in filteredMachines"
            :key="m.id"
            :machine="m"
            @report="onReport"
            @comment="onComment"
            @reopen="onReopen"
          />
        </div>
      </div>
    </div>
  `
};

export default LocationPortalView;
