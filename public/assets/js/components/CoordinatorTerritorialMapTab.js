/**
 * VendGuard - CoordinatorTerritorialMapTab Component (CoordinatorTerritorialMapTab.js)
 *
 * Coordinator territorial triage map (RF-MAP-09, RNF-MAP-02, RNF-MAP-06).
 *
 * Features:
 * 1. Large-format panoramic map of the whole territory rendering every site with unresolved
 *    incidents or preventive orders over standard public map tiles (OpenStreetMap) projected
 *    with the shared vanilla Web Mercator utility — zero libraries, zero API keys and zero
 *    paid providers (constitutional Art. IV, plan.md §5).
 * 2. Site markers with a numeric badge counting pending machines at that building, and
 *    semantic colors: red for critical perishable risk, blue for ordinary incidents,
 *    green for preventive-only sites.
 * 3. Multi-technician detection: sites worked concurrently by more than one technician show
 *    a distinctive badge listing their names to support one-click consolidated reassignment.
 * 4. Reactive filters by assigned technician, critical-only (perishable cold chain) and
 *    unassigned-only; markers re-render immediately when the filter changes.
 * 5. Clicking an unassigned site starts the technical assignment flow for its incidents.
 *
 * Dogma Vanilla: Vue 3 Options API in native ESM, no npm dependencies, no bundlers.
 */

import { api } from '../api.js';
import { computeTileWindow, buildMapTiles, projectToWindow } from '../utils/Mercator.js';

const MARKER_COLORS = {
  critical: '#e02424',
  ordinary: '#2560ff',
  preventive: '#38bd7d',
  unassigned: '#f8b60f'
};

/**
 * Panoramic canvas height ratio: the SVG overlay uses a 100 x 62 viewBox, so marker
 * percentages from the square Mercator window are scaled by this factor. The window
 * is computed with a padding factor above 1/0.62 so the cropped central band keeps
 * every site inside the visible area.
 */
const TERRITORIAL_CANVAS_HEIGHT_RATIO = 0.62;
const TERRITORIAL_WINDOW_PADDING_FACTOR = 1.8;

export { MARKER_COLORS, TERRITORIAL_CANVAS_HEIGHT_RATIO };

export const CoordinatorTerritorialMapTab = {
  name: 'CoordinatorTerritorialMapTab',
  props: {
    /**
     * Currently signed-in coordinator, used to authorize the assignment flow (RF-MAP-09).
     */
    currentUser: {
      type: Object,
      default: null
    }
  },
  emits: ['assign-incidents'],
  data() {
    return {
      sites: [],
      technicians: [],
      isLoading: false,
      errorMessage: '',
      // Cached cartographic window (zoom + tile-space viewport) for the current sites
      mapWindow: null,
      filters: {
        technician_id: '',
        is_critical_only: false,
        unassigned_only: false
      }
    };
  },
  computed: {
    /**
     * Sites already filtered server-side according to the reactive filters (RF-MAP-09).
     */
    visibleSites() {
      return this.sites;
    },
    criticalSiteCount() {
      return this.sites.filter(site => site.has_perishable_risk).length;
    },
    multiTechnicianSiteCount() {
      return this.sites.filter(site => site.is_multi_technician).length;
    },
    totalActiveTasks() {
      return this.sites.reduce((sum, site) => sum + Number(site.total_incidents || 0) + Number(site.total_preventives || 0), 0);
    },
    /**
     * Standard public map tiles covering the territorial viewport (RF-MAP-09, plan.md §5).
     */
    mapTiles() {
      return buildMapTiles(this.mapWindow || this.computeTerritorialWindow());
    },
    /**
     * Exposed panoramic ratio so templates and tests share the same constant.
     */
    canvasHeightRatio() {
      return TERRITORIAL_CANVAS_HEIGHT_RATIO;
    }
  },
  watch: {
    'filters.technician_id'() {
      this.loadTerritorialData();
    },
    'filters.is_critical_only'() {
      this.loadTerritorialData();
    },
    'filters.unassigned_only'() {
      this.loadTerritorialData();
    }
  },
  mounted() {
    this.loadTerritorialData();
  },
  methods: {
    /**
     * Loads the territorial matrix applying the current reactive filters (RF-MAP-09).
     */
    async loadTerritorialData() {
      this.isLoading = true;
      this.errorMessage = '';
      const params = {};
      if (this.filters.technician_id !== '' && this.filters.technician_id !== null && this.filters.technician_id !== undefined) {
        params.technician_id = this.filters.technician_id;
      }
      if (this.filters.is_critical_only) {
        params.is_critical_only = 1;
      }
      if (this.filters.unassigned_only) {
        params.unassigned_only = 1;
      }
      try {
        const response = await api.map.getActiveIncidents(params);
        const payload = response && response.data ? response.data : response;
        this.sites = Array.isArray(payload && payload.locations) ? payload.locations : [];
        this.mapWindow = this.computeTerritorialWindow();
      } catch (err) {
        this.sites = [];
        this.mapWindow = null;
        this.errorMessage = (err && err.message) || 'No se pudo cargar el mapa territorial. Comprueba tu conexión.';
      } finally {
        this.isLoading = false;
      }
    },
    /**
     * Computes the square Web Mercator window framing every active site of the territory.
     */
    computeTerritorialWindow() {
      return computeTileWindow(
        this.sites.map(site => ({ lat: Number(site.latitude), lng: Number(site.longitude) })),
        { paddingFactor: TERRITORIAL_WINDOW_PADDING_FACTOR }
      );
    },
    /**
     * Hides a broken tile image without touching the marker overlay (RNF-MAP-04).
     */
    hideTile(event) {
      if (event && event.target && event.target.style) {
        event.target.style.display = 'none';
      }
    },
    /**
     * Maps a square-window percentage onto the visible central band of the panoramic
     * canvas ([0, 100]). Vertical only: horizontally the window already spans the full
     * canvas width, so X percentages pass through unchanged.
     */
    territorialBandCrop(value) {
      const ratio = TERRITORIAL_CANVAS_HEIGHT_RATIO;
      return (Number(value) - (1 - ratio) * 50) / ratio;
    },
    /**
     * Inline style for a standard tile inside the panoramic canvas: left passes through
     * untouched, top is band-cropped, and the square tile stretches vertically by 1/ratio
     * because the canvas crops the central Mercator band.
     */
    territorialTileStyle(tile) {
      const ratio = TERRITORIAL_CANVAS_HEIGHT_RATIO;
      return {
        left: tile.leftPct + '%',
        top: this.territorialBandCrop(tile.topPct) + '%',
        width: tile.widthPct + '%',
        height: (tile.heightPct / ratio) + '%'
      };
    },
    /**
     * Semantic marker color per site (RF-MAP-09 / RNF-MAP-06).
     */
    siteColor(site) {
      if (site.has_perishable_risk) {
        return MARKER_COLORS.critical;
      }
      if (Number(site.total_incidents || 0) > 0) {
        return MARKER_COLORS.ordinary;
      }
      return MARKER_COLORS.preventive;
    },
    sitePendingCount(site) {
      return Number(site.total_incidents || 0) + Number(site.total_preventives || 0);
    },
    technicianNames(site) {
      return (site.assigned_technicians || []).map(tech => tech.name).join(' / ');
    },
    /**
     * Projects geographic coordinates into the panoramic canvas percentages. The square
     * Mercator window is vertically scaled by the canvas height ratio (100 x 62 viewBox).
     */
    /**
     * Projects geographic coordinates into the panoramic SVG overlay (viewBox 100 x 62).
     * X passes through from the square Mercator window; Y is band-cropped to the visible
     * central band and scaled into the 62-unit viewBox keeping the isotropic scale.
     */
    sitePosition(site) {
      const mapWindow = this.mapWindow || this.computeTerritorialWindow();
      const projected = projectToWindow(Number(site.latitude), Number(site.longitude), mapWindow);
      return {
        x: projected.x,
        y: this.territorialBandCrop(projected.y) * TERRITORIAL_CANVAS_HEIGHT_RATIO
      };
    },
    /**
     * Marks unassigned sites so they can start the assignment flow (RF-MAP-09).
     */
    isUnassignedSite(site) {
      return Boolean(site.has_unassigned);
    },
    /**
     * Clicking an unassigned site opens the technical assignment flow for its tickets.
     */
    handleSiteClick(site) {
      if (!this.isUnassignedSite(site)) {
        return;
      }
      this.$emit('assign-incidents', { locationId: site.location_id, siteCode: site.site_code });
    }
  },
  template: `
  <div class="territorial-map-tab">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="fw-bold mb-0">🗺️ Mapa Territorial de Averías</h5>
      <button type="button" class="btn btn-outline-primary btn-sm" data-testid="btn-refresh-territorial" @click="loadTerritorialData" :disabled="isLoading">
        <span v-if="isLoading" class="spinner-border spinner-border-sm me-1" role="status"></span>
        🔄 Actualizar
      </button>
    </div>

    <div v-if="errorMessage" class="alert alert-danger py-2 small" role="alert">
      ⚠️ {{ errorMessage }}
    </div>

    <!-- Reactive triage filters (RF-MAP-09) -->
    <div class="territorial-filters">
      <label class="small fw-bold" for="territorial-technician-filter">Técnico asignado</label>
      <select id="territorial-technician-filter" v-model="filters.technician_id" class="form-select form-select-sm" data-testid="filter-territorial-technician">
        <option value="">Todos los técnicos</option>
        <option v-for="tech in technicians" :key="tech.id" :value="tech.id">{{ tech.name }}</option>
      </select>
      <div class="form-check form-check-inline ms-2">
        <input class="form-check-input" type="checkbox" id="territorial-critical-only" v-model="filters.is_critical_only" data-testid="filter-territorial-critical" />
        <label class="form-check-label small" for="territorial-critical-only">Solo cadena de frío (críticas)</label>
      </div>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" id="territorial-unassigned-only" v-model="filters.unassigned_only" data-testid="filter-territorial-unassigned" />
        <label class="form-check-label small" for="territorial-unassigned-only">Solo sin asignar</label>
      </div>
    </div>

    <div v-if="isLoading" class="text-center py-5">
      <div class="spinner-border text-primary" role="status"></div>
      <p class="mt-3 mb-0 small">Cargando el mapa territorial del parque…</p>
    </div>

    <template v-else>
      <div v-if="sites.length === 0" class="territorial-empty text-center py-5">
        <p class="fw-bold mb-1">No hay averías ni preventivos activos en el territorio.</p>
        <p class="text-muted small mb-0">El mapa se poblará automáticamente cuando se registren intervenciones.</p>
      </div>

      <template v-else>
        <div class="territorial-map-summary small text-muted mb-2" data-testid="territorial-summary">
          {{ sites.length }} sede(s) con actividad · {{ totalActiveTasks }} tarea(s) activa(s) · {{ criticalSiteCount }} crítica(s) · {{ multiTechnicianSiteCount }} con multi-técnico
        </div>

        <!-- Panoramic standard public map tiles (OpenStreetMap) with the interactive SVG
             overlay: keyless, zero external libraries (Art. IV, plan.md §5) -->
        <div class="territorial-map-canvas-wrapper">
          <div class="territorial-map-canvas">
            <div class="territorial-tile-layer" aria-hidden="true">
              <img
                v-for="tile in mapTiles"
                :key="tile.key"
                class="territorial-tile"
                :src="tile.url"
                alt=""
                draggable="false"
                :style="territorialTileStyle(tile)"
                @error="hideTile"
                @dragstart.prevent
              />
            </div>
            <svg class="territorial-map-overlay" viewBox="0 0 100 62" preserveAspectRatio="none" role="img" aria-label="Mapa territorial de sedes con averías activas">
            <g
              v-for="site in sites"
              :key="site.location_id"
              class="territorial-marker"
              :class="{ 'is-unassigned': isUnassignedSite(site), 'is-multi-technician': site.is_multi_technician }"
              :transform="'translate(' + sitePosition(site).x + ', ' + sitePosition(site).y + ')'"
              role="button"
              tabindex="0"
              :aria-label="'Sede ' + site.name + ': ' + sitePendingCount(site) + ' tarea(s) pendientes' + (isUnassignedSite(site) ? '. Sin asignar.' : '')"
              @click="handleSiteClick(site)"
              @keydown.enter.prevent="handleSiteClick(site)"
            >
              <circle r="3.6" :fill="siteColor(site)" class="territorial-marker-circle" />
              <circle v-if="isUnassignedSite(site)" r="5.4" class="territorial-marker-unassigned-ring" />
              <text y="-5" text-anchor="middle" class="territorial-marker-badge">{{ sitePendingCount(site) }}</text>
              <text v-if="site.is_multi_technician" y="9.4" text-anchor="middle" class="territorial-marker-multi-badge">👥</text>
            </g>
            </svg>
            <div class="territorial-map-attribution">© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors</div>
          </div>
          <div class="territorial-map-legend small">
            <span><i class="route-dot" style="background:#e02424"></i> Crítica (perecederos)</span>
            <span><i class="route-dot" style="background:#2560ff"></i> Ordinaria</span>
            <span><i class="route-dot" style="background:#38bd7d"></i> Preventiva</span>
            <span><i class="route-dot" style="background:#f8b60f"></i> Sin asignar</span>
          </div>
        </div>

        <!-- Site detail cards with technician and multi-technician badges -->
        <div class="territorial-site-list">
          <div
            v-for="site in sites"
            :key="'card-' + site.location_id"
            class="territorial-site-card"
            :class="{ 'is-unassigned': isUnassignedSite(site) }"
          >
            <span class="territorial-site-badge" :style="{ background: siteColor(site) }">{{ sitePendingCount(site) }}</span>
            <span class="territorial-site-main">
              <strong>{{ site.name }}</strong>
              <small class="text-muted d-block">{{ site.address }}</small>
              <small class="d-block" :class="{ 'text-danger fw-bold': site.is_multi_technician }">
                <template v-if="site.is_multi_technician">👥 {{ technicianNames(site) }}</template>
                <template v-else-if="site.assigned_technicians && site.assigned_technicians.length > 0">{{ technicianNames(site) }}</template>
                <template v-else>Sin asignar</template>
              </small>
            </span>
            <button
              v-if="isUnassignedSite(site)"
              type="button"
              class="btn btn-primary btn-sm"
              data-testid="btn-assign-site"
              @click="handleSiteClick(site)"
            >
              Asignar técnico
            </button>
          </div>
        </div>
      </template>
    </template>
  </div>
  `
};
