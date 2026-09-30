/**
 * VendGuard - TechnicianRouteMapModal Component (TechnicianRouteMapModal.js)
 *
 * Interactive mobile route map modal for the field technician
 * (RF-MAP-05 to RF-MAP-08, RNF-MAP-02, RNF-MAP-03, RNF-MAP-06).
 *
 * Features:
 * 1. Responsive vertical mobile map rendering the ordered day route with light standard SVG
 *    (zero external tile providers or paid APIs: constitutional Art. IV).
 * 2. Sequential numbered circular markers (1, 2, 3...) with semantic institutional colors:
 *    amber in-progress, red critical perishable, blue ordinary incident, green preventive,
 *    grey completed with green check, and start indicator for GPS position or Base Central.
 * 3. Tap a marker (or select a stop in the textual list) to center the view and open the stop
 *    summary sheet: site name, address, machine progress, urgency and "Navegar con GPS" button.
 * 4. One-tap Google Maps universal links per stop plus the full day route with waypoints;
 *    a non-intrusive notice explains waypoint truncation when the link limit is exceeded.
 * 5. Device GPS permission request; transparent fallback to Base Central with an informational
 *    notice when permissions are denied, the sensor is missing or the read fails (RF-MAP-06).
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
      // Device GPS state (RF-MAP-06)
      deviceCoordinates: null,
      gpsNotice: '',
      gpsPermission: 'unknown' // 'granted' | 'denied' | 'unavailable' | 'unknown'
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
        navigator.geolocation.getCurrentPosition(
          position => {
            this.deviceCoordinates = {
              origin_lat: position.coords.latitude,
              origin_lng: position.coords.longitude
            };
            this.gpsPermission = 'granted';
            resolve();
          },
          () => {
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
        if (this.isBaseCentralFallback && this.gpsPermission === 'denied') {
          this.gpsNotice = 'Ruta calculada desde Base Central (GPS móvil no disponible).';
        }
      } catch (err) {
        this.routeData = { origin: null, stops: [], full_route_navigation_url: '', waypoints_truncated: false, summary: {} };
        this.errorMessage = (err && err.message) || 'No se pudo cargar el mapa de la ruta. Comprueba tu conexión.';
      } finally {
        this.isLoading = false;
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
     * Returns marker screen coordinates (percent) for the SVG projection.
     */
    markerPosition(stop) {
      return this.projectedStops().find(item => item.order === stop.order) || { x: 50, y: 50 };
    },
    originPosition() {
      return this.projectedStops().originPosition;
    },
    /**
     * Projects geographic coordinates into the light SVG canvas (percent values).
     * Deterministic nearest-neighbor framing with a fixed padding; no external tiles.
     */
    projectedStops() {
      const points = [];
      if (this.origin && Number.isFinite(Number(this.origin.latitude))) {
        points.push({ latitude: Number(this.origin.latitude), longitude: Number(this.origin.longitude) });
      }
      for (const stop of this.stops) {
        points.push({ latitude: Number(stop.location.latitude), longitude: Number(stop.location.longitude) });
      }
      if (points.length === 0) {
        return { originPosition: { x: 50, y: 50 }, positions: {} };
      }
      const latitudes = points.map(point => point.latitude);
      const longitudes = points.map(point => point.longitude);
      let minLat = Math.min(...latitudes);
      let maxLat = Math.max(...latitudes);
      let minLng = Math.min(...longitudes);
      let maxLng = Math.max(...longitudes);
      const spanLat = Math.max(maxLat - minLat, 0.01);
      const spanLng = Math.max(maxLng - minLng, 0.01);
      // Keep the canvas square-ish: align the smaller span around its center.
      const padding = 14;
      const usable = 100 - padding * 2;
      const center = (minLat + maxLat) / 2;
      if (spanLng > spanLat) {
        minLat = center - spanLng / 2;
        maxLat = center + spanLng / 2;
      } else {
        const centerLng = (minLng + maxLng) / 2;
        minLng = centerLng - spanLat / 2;
        maxLng = centerLng + spanLat / 2;
      }
      const spanLatFinal = maxLat - minLat;
      const spanLngFinal = maxLng - minLng;
      const project = (latitude, longitude) => ({
        x: padding + ((longitude - minLng) / spanLngFinal) * usable,
        y: 100 - padding - ((latitude - minLat) / spanLatFinal) * usable
      });
      const positions = {};
      for (const stop of this.stops) {
        positions[stop.order] = project(Number(stop.location.latitude), Number(stop.location.longitude));
      }
      const originPosition = this.origin && Number.isFinite(Number(this.origin.latitude))
        ? project(Number(this.origin.latitude), Number(this.origin.longitude))
        : { x: 50, y: 50 };
      return { originPosition, positions };
    },
    markerFor(stop) {
      const projection = this.projectedStops();
      return projection.positions[stop.order] || { x: 50, y: 50 };
    },
    originPoint() {
      return this.projectedStops().originPosition;
    },
    /**
     * Selects a stop: centers the view marker and opens the summary sheet (RF-MAP-07).
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
            <!-- Interactive SVG map: light, standard, zero external tiles (Art. IV) -->
            <div class="route-map-canvas-wrapper">
              <svg class="route-map-canvas" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Mapa de ruta del día con paradas numeradas">
                <polyline
                  v-if="stops.length > 1"
                  :points="[
                    originPoint,
                    ...stops.map(stop => markerFor(stop))
                  ].map(point => point.x + ',' + point.y).join(' ')"
                  class="route-map-line"
                />
                <g
                  class="route-map-origin"
                  :transform="'translate(' + originPoint.x + ', ' + originPoint.y + ')'"
                >
                  <circle r="3.4" class="route-origin-dot" />
                  <text y="1.2" text-anchor="middle" class="route-origin-icon">▶</text>
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
