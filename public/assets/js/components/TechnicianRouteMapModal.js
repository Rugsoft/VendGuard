/**
 * VendGuard - TechnicianRouteMapModal Component (TechnicianRouteMapModal.js)
 *
 * Interactive mobile route map modal for the field technician
 * (RF-MAP-05 to RF-MAP-08, RNF-MAP-02, RNF-MAP-03, RNF-MAP-06).
 *
 * Features:
 * 1. Responsive vertical mobile map rendering the ordered day route over standard public
 *    map tiles (OpenStreetMap) projected with native Web Mercator math — zero external
 *    libraries, zero API keys and zero paid services (constitutional Art. IV, plan.md §5).
 * 2. Sequential numbered circular markers (1, 2, 3...) with semantic institutional colors:
 *    amber in-progress, red critical perishable, blue ordinary incident, green preventive,
 *    grey completed with green check, and a vector start indicator for GPS position or
 *    Base Central.
 * 3. Tap a marker (or select a stop in the textual list) to open the stop summary sheet:
 *    site name, address, machine progress, urgency and "Navegar con GPS" button.
 * 4. One-tap Google Maps universal links per stop plus the full day route with waypoints;
 *    a non-intrusive notice explains waypoint truncation when the link limit is exceeded.
 * 5. Device GPS permission request; transparent fallback to Base Central with an informational
 *    notice when permissions are denied, the sensor is missing or the read fails (RF-MAP-06).
 * 6. Offline resilience (RNF-MAP-04): if a tile fails to load the broken image is hidden and
 *    the markers, route line and navigation buttons keep working on the fallback canvas.
 *
 * Dogma Vanilla: Vue 3 Options API in native ESM, no npm dependencies, no bundlers.
 */

import { api } from '../api.js';

const STOP_COLORS = {
  inProgress: '#f8b60f',
  critical: '#e02424',
  ordinary: '#2560ff',
  preventive: '#38bd7d',
  completed: '#c8cfda'
};

/**
 * Standard Web Mercator projection helpers (pure functions, no dependencies).
 * Fractions are expressed in zoom-0 world units within [0, 1].
 */
export const Mercator = {
  /**
   * Longitude to horizontal world fraction ([0 = -180°, 1 = +180°]).
   */
  lngToFraction(lng) {
    return (Number(lng) + 180) / 360;
  },
  /**
   * Latitude to vertical world fraction ([0 = north pole, 0.5 = equator, 1 = south pole])
   * using the standard spherical Mercator formula with the OSM latitude clamp.
   */
  latToFraction(lat) {
    const clamped = Math.max(Math.min(Number(lat), 85.0511), -85.0511);
    const radians = (clamped * Math.PI) / 180;
    return (1 - Math.log(Math.tan(radians) + 1 / Math.cos(radians)) / Math.PI) / 2;
  },
  /**
   * Longitude to world tile units at the given zoom level.
   */
  lngToWorldX(lng, zoom) {
    return this.lngToFraction(lng) * Math.pow(2, zoom);
  },
  /**
   * Latitude to world tile units at the given zoom level.
   */
  latToWorldY(lat, zoom) {
    return this.latToFraction(lat) * Math.pow(2, zoom);
  }
};

export { STOP_COLORS };

export const TechnicianRouteMapModal = {
  name: 'TechnicianRouteMapModal',
  props: {
    /**
     * Controls modal visibility (v-model).
     */
    modelValue: {
      type: Boolean,
      default: false
    }
  },
  emits: ['update:modelValue'],
  data() {
    return {
      isLoading: false,
      errorMessage: '',
      routeData: null,
      selectedStopOrder: null,
      // Cached cartographic window (zoom + tile-space viewport) for the current route
      mapWindow: null,
      // Device GPS state (RF-MAP-06)
      deviceCoordinates: null,
      gpsNotice: '',
      gpsPermission: 'unknown', // 'granted' | 'denied' | 'unavailable' | 'unknown'
      // Hard watchdog: some embedded browsers/webviews never invoke the geolocation
      // callbacks at all, so the app must not trust the browser-side timeout alone.
      gpsWatchdogMs: 5000
    };
  },
  computed: {
    isVisible() {
      return this.modelValue;
    },
    origin() {
      return (this.routeData && this.routeData.origin) || null;
    },
    stops() {
      return (this.routeData && this.routeData.stops) || [];
    },
    hasStops() {
      return this.stops.length > 0;
    },
    fullRouteUrl() {
      return (this.routeData && this.routeData.full_route_navigation_url) || '';
    },
    waypointsTruncated() {
      return Boolean(this.routeData && this.routeData.waypoints_truncated);
    },
    summary() {
      return (this.routeData && this.routeData.summary) || { total_stops: 0, total_tasks: 0, total_critical: 0, estimated_total_distance_km: 0 };
    },
    /**
     * Standard public map tiles covering the route viewport (RF-MAP-07, plan.md §4.1).
     */
    mapTiles() {
      return this.buildMapTiles();
    },
    selectedStop() {
      if (this.selectedStopOrder === null) return null;
      return this.stops.find(stop => stop.order === this.selectedStopOrder) || null;
    },
    originLabel() {
      if (!this.origin) return '';
      return this.origin.source === 'GPS' ? 'Ubicación actual del técnico (GPS móvil)' : (this.origin.address || 'Base Central');
    },
    isBaseCentralFallback() {
      return Boolean(this.origin && this.origin.source !== 'GPS');
    },
    showBaseCentralNotice() {
      return this.isBaseCentralFallback && this.gpsPermission !== 'unknown' && !this.isLoading;
    }
  },
  watch: {
    modelValue(open) {
      if (open) {
        this.openMap();
      }
    }
  },
  methods: {
    close() {
      this.$emit('update:modelValue', false);
    },
    async openMap() {
      this.errorMessage = '';
      this.selectedStopOrder = null;
      this.gpsNotice = '';
      await this.resolveDevicePosition();
      await this.loadRouteMap();
    },
    /**
     * Requests the device GPS position once (RF-MAP-06). A denial, missing sensor or
     * read error transparently falls back to Base Central inside the API.
     */
    resolveDevicePosition() {
      this.deviceCoordinates = null;
      this.gpsPermission = 'unknown';
      if (typeof navigator === 'undefined' || !navigator.geolocation || typeof navigator.geolocation.getCurrentPosition !== 'function') {
        this.gpsPermission = 'unavailable';
        return Promise.resolve();
      }
      return new Promise(resolve => {
        let settled = false;
        const watchdog = setTimeout(() => {
          if (!settled) {
            settled = true;
            this.gpsPermission = 'unavailable';
            resolve();
          }
        }, this.gpsWatchdogMs);
        navigator.geolocation.getCurrentPosition(
          position => {
            if (settled) return;
            settled = true;
            clearTimeout(watchdog);
            this.deviceCoordinates = {
              origin_lat: position.coords.latitude,
              origin_lng: position.coords.longitude
            };
            this.gpsPermission = 'granted';
            resolve();
          },
          () => {
            if (settled) return;
            settled = true;
            clearTimeout(watchdog);
            this.gpsPermission = 'denied';
            resolve();
          },
          { timeout: 8000, maximumAge: 60000 }
        );
      });
    },
    /**
     * Loads the ordered route from the backend with the device origin when available.
     */
    async loadRouteMap() {
      this.isLoading = true;
      this.errorMessage = '';
      try {
        const response = await api.technician.getRouteMap(this.deviceCoordinates || {});
        const payload = response && response.data ? response.data : response;
        this.routeData = payload || { origin: null, stops: [], full_route_navigation_url: '', waypoints_truncated: false, summary: {} };
        this.mapWindow = this.computeTileWindow();
        if (this.isBaseCentralFallback && (this.gpsPermission === 'denied' || this.gpsPermission === 'unavailable')) {
          this.gpsNotice = 'Ruta calculada desde Base Central (GPS móvil no disponible).';
        }
      } catch (err) {
        this.routeData = { origin: null, stops: [], full_route_navigation_url: '', waypoints_truncated: false, summary: {} };
        this.mapWindow = null;
        this.errorMessage = (err && err.message) || 'No se pudo cargar el mapa de la ruta. Comprueba tu conexión.';
      } finally {
        this.isLoading = false;
      }
    },
    /**
     * Collects the finite geographic points (origin + stops) of the current route.
     */
    routePoints() {
      const points = [];
      if (this.origin && Number.isFinite(Number(this.origin.latitude)) && Number.isFinite(Number(this.origin.longitude))) {
        points.push({ lat: Number(this.origin.latitude), lng: Number(this.origin.longitude) });
      }
      for (const stop of this.stops) {
        const lat = Number(stop.location.latitude);
        const lng = Number(stop.location.longitude);
        if (Number.isFinite(lat) && Number.isFinite(lng)) {
          points.push({ lat, lng });
        }
      }
      return points;
    },
    /**
     * Computes the square Web Mercator window (in tile units) that frames the route with
     * a fixed padding. Deterministic: same coordinates always yield the same zoom and view.
     */
    computeTileWindow() {
      const points = this.routePoints();
      if (points.length === 0) {
        return null;
      }
      const fractionsX = points.map(point => Mercator.lngToFraction(point.lng));
      const fractionsY = points.map(point => Mercator.latToFraction(point.lat));
      const minSpanFraction = 0.000012; // ~500 m minimum framing for a single-stop day
      const spanX = Math.max(Math.max(...fractionsX) - Math.min(...fractionsX), minSpanFraction);
      const spanY = Math.max(Math.max(...fractionsY) - Math.min(...fractionsY), minSpanFraction);
      const sideFraction = Math.max(spanX, spanY) * 1.6; // content occupies ~62% of the canvas
      let zoom = Math.round(Math.log2(1 / sideFraction));
      zoom = Math.max(6, Math.min(16, zoom));
      let sideTiles = sideFraction * Math.pow(2, zoom);
      if (sideTiles < 0.9 && zoom < 16) {
        zoom += 1;
        sideTiles = sideFraction * Math.pow(2, zoom);
      }
      const centerX = ((Math.max(...fractionsX) + Math.min(...fractionsX)) / 2) * Math.pow(2, zoom);
      const centerY = ((Math.max(...fractionsY) + Math.min(...fractionsY)) / 2) * Math.pow(2, zoom);
      return {
        zoom,
        sideTiles,
        leftEdge: centerX - sideTiles / 2,
        topEdge: centerY - sideTiles / 2
      };
    },
    /**
     * Builds the list of standard public tiles covering the viewport. Percentages are
     * relative to the square canvas; edge tiles may overflow and are clipped by CSS.
     */
    buildMapTiles() {
      const mapWindow = this.mapWindow || this.computeTileWindow();
      if (!mapWindow) {
        return [];
      }
      const worldSize = Math.pow(2, mapWindow.zoom);
      const tilePercent = 100 / mapWindow.sideTiles;
      const startX = Math.floor(mapWindow.leftEdge);
      const endX = Math.floor(mapWindow.leftEdge + mapWindow.sideTiles);
      const startY = Math.floor(mapWindow.topEdge);
      const endY = Math.floor(mapWindow.topEdge + mapWindow.sideTiles);
      const tiles = [];
      for (let tx = startX; tx <= endX; tx++) {
        if (tx < 0 || tx > worldSize - 1) continue;
        for (let ty = startY; ty <= endY; ty++) {
          if (ty < 0 || ty > worldSize - 1) continue;
          tiles.push({
            key: mapWindow.zoom + '/' + tx + '/' + ty,
            url: `https://tile.openstreetmap.org/${mapWindow.zoom}/${tx}/${ty}.png`,
            leftPct: ((tx - mapWindow.leftEdge) / mapWindow.sideTiles) * 100,
            topPct: ((ty - mapWindow.topEdge) / mapWindow.sideTiles) * 100,
            widthPct: tilePercent,
            heightPct: tilePercent
          });
        }
      }
      return tiles;
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
     * Semantic institutional color for each stop (RF-MAP-07).
     */
    stopColor(stop) {
      if (stop.status === 'IN_PROGRESS') {
        return STOP_COLORS.inProgress;
      }
      if (stop.status === 'COMPLETED') {
        return STOP_COLORS.completed;
      }
      if (stop.is_critical) {
        return STOP_COLORS.critical;
      }
      if (stop.is_preventive_only) {
        return STOP_COLORS.preventive;
      }
      return STOP_COLORS.ordinary;
    },
    stopStatusLabel(stop) {
      const labels = {
        IN_PROGRESS: 'En Curso',
        COMPLETED: 'Completada',
        PENDING: 'Pendiente'
      };
      return labels[stop.status] || 'Pendiente';
    },
    progressLabel(stop) {
      return `${stop.completed_tasks || 0} de ${stop.total_tasks || 0} completadas`;
    },
    /**
     * Returns marker canvas coordinates (percent of the square map canvas).
     */
    markerFor(stop) {
      const mapWindow = this.mapWindow || this.computeTileWindow();
      if (!mapWindow || !Number.isFinite(Number(stop.location.latitude)) || !Number.isFinite(Number(stop.location.longitude))) {
        return { x: 50, y: 50 };
      }
      const worldX = Mercator.lngToWorldX(Number(stop.location.longitude), mapWindow.zoom);
      const worldY = Mercator.latToWorldY(Number(stop.location.latitude), mapWindow.zoom);
      return {
        x: ((worldX - mapWindow.leftEdge) / mapWindow.sideTiles) * 100,
        y: ((worldY - mapWindow.topEdge) / mapWindow.sideTiles) * 100
      };
    },
    originPoint() {
      const mapWindow = this.mapWindow || this.computeTileWindow();
      if (!mapWindow || !this.origin || !Number.isFinite(Number(this.origin.latitude)) || !Number.isFinite(Number(this.origin.longitude))) {
        return { x: 50, y: 50 };
      }
      const worldX = Mercator.lngToWorldX(Number(this.origin.longitude), mapWindow.zoom);
      const worldY = Mercator.latToWorldY(Number(this.origin.latitude), mapWindow.zoom);
      return {
        x: ((worldX - mapWindow.leftEdge) / mapWindow.sideTiles) * 100,
        y: ((worldY - mapWindow.topEdge) / mapWindow.sideTiles) * 100
      };
    },
    /**
     * Selects a stop: highlights the marker and opens the summary sheet (RF-MAP-07).
     */
    selectStop(stop) {
      this.selectedStopOrder = stop.order;
    },
    clearSelection() {
      this.selectedStopOrder = null;
    },
    stopNavigationUrl(stop) {
      if (stop.navigation_url) {
        return stop.navigation_url;
      }
      const lat = Number(stop.location.latitude);
      const lng = Number(stop.location.longitude);
      return `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}&travelmode=driving`;
    },
    openNavigation(url) {
      if (!url) return;
      if (typeof window !== 'undefined' && window.open) {
        window.open(url, '_blank', 'noopener');
      }
    },
    navigateToStop(stop) {
      this.openNavigation(this.stopNavigationUrl(stop));
    },
    navigateFullRoute() {
      this.openNavigation(this.fullRouteUrl);
    }
  },
  template: `
  <div v-if="isVisible" class="route-map-backdrop" @click.self="close">
    <div class="route-map-modal" role="dialog" aria-modal="true" aria-labelledby="routeMapTitle">
      <div class="route-map-header d-flex align-items-center justify-content-between">
        <h5 id="routeMapTitle" class="fw-bold mb-0">🗺️ Mi Ruta de Hoy</h5>
        <button type="button" class="btn-close btn-close-white" aria-label="Cerrar mapa" @click="close"></button>
      </div>

      <div class="route-map-body">
        <div v-if="isLoading" class="text-center py-5">
          <div class="spinner-border text-primary" role="status"></div>
          <p class="mt-3 mb-0 small">Calculando la secuencia óptima de paradas…</p>
        </div>

        <div v-else-if="errorMessage" class="alert alert-danger py-2 small mb-0">
          {{ errorMessage }}
        </div>

        <template v-else>
          <div v-if="showBaseCentralNotice" class="alert alert-info py-2 small mb-2">
            📍 {{ gpsNotice }}
          </div>

          <div v-if="!hasStops" class="empty-route text-center py-5">
            <p class="fw-bold mb-1">No tienes paradas asignadas para la ruta de hoy.</p>
            <p class="text-muted small mb-0">Disfruta de tu jornada; aquí verás tu mapa cuando se te asignen intervenciones.</p>
          </div>

          <template v-else>
            <!-- Standard public map tiles (OpenStreetMap) with the interactive SVG overlay:
                 light, keyless, zero external libraries (Art. IV, plan.md §5) -->
            <div class="route-map-canvas-wrapper">
              <div class="route-map-canvas">
                <div class="route-tile-layer" aria-hidden="true">
                  <img
                    v-for="tile in mapTiles"
                    :key="tile.key"
                    class="route-tile"
                    :src="tile.url"
                    alt=""
                    draggable="false"
                    :style="{ left: tile.leftPct + '%', top: tile.topPct + '%', width: tile.widthPct + '%', height: tile.heightPct + '%' }"
                    @error="hideTile"
                    @dragstart.prevent
                  />
                </div>
                <svg class="route-map-overlay" viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label="Mapa de ruta del día con paradas numeradas">
                  <polyline
                    v-if="stops.length > 1"
                    :points="[
                      originPoint(),
                      ...stops.map(stop => markerFor(stop))
                    ].map(point => point.x + ',' + point.y).join(' ')"
                    class="route-map-line"
                  />
                  <g
                    class="route-map-origin"
                    :transform="'translate(' + originPoint().x + ', ' + originPoint().y + ')'"
                  >
                    <circle r="3.4" class="route-origin-dot" />
                    <polygon points="-1.4,-2 -1.4,2 2.2,0" class="route-origin-triangle" />
                  </g>
                  <g
                    v-for="stop in stops"
                    :key="'marker-' + stop.order"
                    class="route-map-marker"
                    :class="{ 'is-selected': selectedStopOrder === stop.order }"
                    :transform="'translate(' + markerFor(stop).x + ', ' + markerFor(stop).y + ')'"
                    role="button"
                    tabindex="0"
                    :aria-label="'Parada ' + stop.order + ': ' + stop.location.name"
                    @click="selectStop(stop)"
                    @keydown.enter.prevent="selectStop(stop)"
                  >
                    <circle r="4.6" :fill="stopColor(stop)" class="route-marker-circle" />
                    <text v-if="stop.status !== 'COMPLETED'" y="1.6" text-anchor="middle" class="route-marker-text">{{ stop.order }}</text>
                    <text v-else y="1.6" text-anchor="middle" class="route-marker-check">✔</text>
                  </g>
                </svg>
                <div class="route-map-attribution">© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors</div>
              </div>
              <div class="route-map-legend small">
                <span><i class="route-dot" style="background:#f8b60f"></i> En curso</span>
                <span><i class="route-dot" style="background:#e02424"></i> Crítica</span>
                <span><i class="route-dot" style="background:#2560ff"></i> Ordinaria</span>
                <span><i class="route-dot" style="background:#38bd7d"></i> Preventiva</span>
                <span><i class="route-dot" style="background:#c8cfda"></i> Completada</span>
              </div>
            </div>

            <!-- Full-day navigation with waypoints (RF-MAP-08) -->
            <div class="d-grid mb-3">
              <button type="button" class="btn btn-primary" @click="navigateFullRoute">
                🚗 Abrir ruta completa en Google Maps
              </button>
            </div>
            <div v-if="waypointsTruncated" class="alert alert-warning py-2 small mb-3">
              Mostrando las primeras {{ summary.total_stops }} paradas priorizadas en el navegador; use el botón individual para las paradas siguientes.
            </div>

            <!-- Ordered stop list synced with the map (RF-MAP-07) -->
            <ol class="route-stop-list list-unstyled">
              <li
                v-for="stop in stops"
                :key="'list-' + stop.order"
                class="route-stop-card"
                :class="{ 'is-selected': selectedStopOrder === stop.order }"
                @click="selectStop(stop)"
              >
                <span class="route-stop-badge" :style="{ background: stopColor(stop) }">
                  <template v-if="stop.status === 'COMPLETED'">✔</template>
                  <template v-else>{{ stop.order }}</template>
                </span>
                <span class="route-stop-main">
                  <strong>{{ stop.location.name }}</strong>
                  <small class="text-muted d-block">{{ stop.location.address }}</small>
                  <small class="d-block">{{ progressLabel(stop) }} · {{ stopStatusLabel(stop) }}</small>
                </span>
                <span class="route-stop-distance text-muted small" v-if="stop.distance_from_previous_km !== null && stop.distance_from_previous_km !== undefined">
                  {{ stop.distance_from_previous_km }} km
                </span>
              </li>
            </ol>

            <!-- Stop summary sheet (RF-MAP-07) -->
            <div v-if="selectedStop" class="route-stop-sheet">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <h6 class="fw-bold mb-1">
                    Parada {{ selectedStop.order }} · {{ selectedStop.location.name }}
                  </h6>
                  <p class="small text-muted mb-1">{{ selectedStop.location.address }}</p>
                  <p class="small mb-1">
                    <span class="badge route-urgency-badge" :style="{ background: stopColor(selectedStop) }">
                      {{ stopStatusLabel(selectedStop) }}
                    </span>
                    <span class="ms-2">{{ progressLabel(selectedStop) }}</span>
                  </p>
                </div>
                <button type="button" class="btn-close" aria-label="Cerrar ficha" @click="clearSelection"></button>
              </div>
              <ul class="small text-muted ps-3 mb-2">
                <li v-for="task in selectedStop.tasks" :key="task.type + '-' + task.id">
                  {{ task.type === 'PREVENTIVE' ? 'Preventivo' : 'Avería' }} · {{ task.machine_code }} · {{ task.floor_location || selectedStop.location.name }}
                </li>
              </ul>
              <button type="button" class="btn btn-primary w-100" @click="navigateToStop(selectedStop)">
                🧭 Navegar con GPS
              </button>
            </div>
          </template>
        </template>
      </div>
    </div>
  </div>
  `
};
