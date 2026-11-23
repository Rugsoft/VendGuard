/**
 * VendGuard - RestockerRouteMapModal
 *
 * Mapa móvil de la hoja de ruta del reponedor (Módulo 37, `RF-03.2`,
 * `RNF-04`, `QA-6`, Art. IV). Reutiliza la cartografía Web Mercator nativa y
 * el controlador compartido de zoom/pan, sin librerías, claves ni proveedores
 * de pago.
 *
 * El modal consume directamente el `route_plan` ya compuesto por el backend:
 * no vuelve a calcular la ruta ni inventa paradas. Las paradas sin coordenadas
 * válidas siguen visibles en la lista textual y se marcan «Sin GPS».
 *
 * Dogma Vanilla: Vue 3 Options API en ES Module nativo, sin dependencias.
 */

import { Mercator, computeTileWindow, buildMapTiles, projectToWindow } from '../utils/Mercator.js';
import { mapZoomPanState, MapZoomPanMethods, MAP_ZOOM_PAN_DEFAULTS } from '../utils/MapZoomPan.js';

export const RestockerRouteMapModal = {
  name: 'RestockerRouteMapModal',
  props: {
    modelValue: {
      type: Boolean,
      default: false
    },
    routePlan: {
      type: Object,
      default: null
    },
    serviceDate: {
      type: String,
      default: ''
    }
  },
  emits: ['update:modelValue'],
  data() {
    return {
      mapWindow: null,
      selectedStopOrder: null,
      previousFocus: null,
      routeMapKeydownHandler: null,
      ...mapZoomPanState()
    };
  },
  computed: {
    isVisible() {
      return this.modelValue;
    },
    stops() {
      return Array.isArray(this.routePlan?.stops) ? this.routePlan.stops : [];
    },
    origin() {
      return this.routePlan?.origin || null;
    },
    summary() {
      return this.routePlan?.summary || {
        pending_stops_count: 0,
        pending_machines_count: 0,
        total_distance_km: 0,
        unmapped_stops_count: 0
      };
    },
    fullRouteUrl() {
      return this.routePlan?.full_route_navigation_url || '';
    },
    waypointsTruncated() {
      return this.routePlan?.waypoints_truncated === true;
    },
    hasStops() {
      return this.stops.length > 0;
    },
    mapTiles() {
      return buildMapTiles(this.effectiveWindow() || this.computeTileWindow());
    },
    zoomMinScale() {
      return this.getMinScale();
    },
    zoomMaxScale() {
      return this.getMaxScale();
    },
    selectedStop() {
      if (this.selectedStopOrder === null) return null;
      return this.stops.find((stop) => Number(stop.stop_order) === Number(this.selectedStopOrder)) || null;
    },
    originLabel() {
      if (!this.origin) return 'Sin origen';
      return this.origin.source === 'GPS' ? 'GPS móvil' : (this.origin.address || 'Base Central');
    },
    distanceLabel() {
      const distance = this.summary.total_distance_km;
      if (distance === null || distance === undefined) return 'Sin GPS';
      return `${Number(distance).toFixed(1)} km`;
    }
  },
  watch: {
    modelValue(open) {
      if (open) {
        this.activateModal();
      } else {
        this.deactivateModal();
      }
    }
  },
  mounted() {
    if (this.isVisible) {
      this.activateModal();
    }
  },
  beforeUnmount() {
    this.unbindKeydown();
  },
  methods: {
    ...MapZoomPanMethods,
    activateModal() {
      if (typeof document !== 'undefined') {
        this.previousFocus = document.activeElement;
      }
      this.selectedStopOrder = null;
      this.mapWindow = this.computeTileWindow();
      this.resetView();
      this.bindKeydown();
      const focusClose = () => this.focusCloseButton();
      if (typeof this.$nextTick === 'function') {
        this.$nextTick(focusClose);
      } else {
        focusClose();
      }
    },
    deactivateModal() {
      this.unbindKeydown();
      this.restoreFocus();
    },
    bindKeydown() {
      if (typeof document === 'undefined' || this.routeMapKeydownHandler) return;
      this.routeMapKeydownHandler = (event) => this.handleKeydown(event);
      document.addEventListener('keydown', this.routeMapKeydownHandler);
    },
    unbindKeydown() {
      if (
        typeof document !== 'undefined'
        && this.routeMapKeydownHandler
        && typeof document.removeEventListener === 'function'
      ) {
        document.removeEventListener('keydown', this.routeMapKeydownHandler);
      }
      this.routeMapKeydownHandler = null;
    },
    focusCloseButton() {
      const closeButton = this.$refs?.routeMapCloseButton;
      if (closeButton && typeof closeButton.focus === 'function') closeButton.focus();
    },
    restoreFocus() {
      const trigger = this.previousFocus;
      this.previousFocus = null;
      if (!trigger || trigger.isConnected === false || typeof trigger.focus !== 'function') return;
      const focusTrigger = () => {
        if (trigger.isConnected === false) return;
        trigger.focus();
      };
      if (typeof this.$nextTick === 'function') this.$nextTick(focusTrigger);
      else focusTrigger();
    },
    focusableElements() {
      const root = this.$refs?.routeMapDialog;
      if (!root || typeof root.querySelectorAll !== 'function') return [];
      const selector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
      return Array.from(root.querySelectorAll(selector))
        .filter((element) => element && typeof element.focus === 'function');
    },
    handleKeydown(event) {
      if (!this.isVisible || !event) return;
      if (event.key === 'Escape') {
        if (typeof event.preventDefault === 'function') event.preventDefault();
        this.close();
        return;
      }
      if (event.key !== 'Tab') return;

      const elements = this.focusableElements();
      if (elements.length === 0) return;
      const first = elements[0];
      const last = elements[elements.length - 1];
      const active = typeof document !== 'undefined' ? document.activeElement : null;
      const root = this.$refs?.routeMapDialog;
      const inside = !!(active && root && typeof root.contains === 'function' && root.contains(active));

      if (event.shiftKey && (!inside || active === first)) {
        if (typeof event.preventDefault === 'function') event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (!inside || active === last)) {
        if (typeof event.preventDefault === 'function') event.preventDefault();
        first.focus();
      }
    },
    getBaseWindow() {
      return this.mapWindow || this.computeTileWindow();
    },
    getMinScale() {
      return MAP_ZOOM_PAN_DEFAULTS.MIN_SCALE;
    },
    getMaxScale() {
      return MAP_ZOOM_PAN_DEFAULTS.MAX_SCALE;
    },
    getRenderWidthPx() {
      if (typeof document === 'undefined') return 0;
      const canvas = document.querySelector('[data-testid="restocker-route-map-canvas"]');
      if (!canvas || !canvas.getBoundingClientRect) return 0;
      const width = Math.round(canvas.getBoundingClientRect().width);
      return width > 0 ? width : 0;
    },
    routePoints() {
      const points = [];
      if (this.origin && Number.isFinite(Number(this.origin.latitude)) && Number.isFinite(Number(this.origin.longitude))) {
        points.push({ lat: Number(this.origin.latitude), lng: Number(this.origin.longitude) });
      }
      for (const stop of this.stops) {
        if (this.stopHasGps(stop)) {
          points.push({ lat: Number(stop.latitude), lng: Number(stop.longitude) });
        }
      }
      return points;
    },
    computeTileWindow() {
      return computeTileWindow(this.routePoints());
    },
    stopHasGps(stop) {
      return stop?.has_valid_coordinates !== false
        && Number.isFinite(Number(stop?.latitude))
        && Number.isFinite(Number(stop?.longitude));
    },
    markerFor(stop) {
      if (!this.stopHasGps(stop)) return { x: 50, y: 50 };
      const window = this.effectiveWindow() || this.computeTileWindow();
      return projectToWindow(Number(stop.latitude), Number(stop.longitude), window);
    },
    originPoint() {
      if (!this.origin) return { x: 50, y: 50 };
      const window = this.effectiveWindow() || this.computeTileWindow();
      return projectToWindow(Number(this.origin.latitude), Number(this.origin.longitude), window);
    },
    stopColor(stop) {
      if (stop?.machines?.some((machine) => machine.has_fefo_alert)) return 'var(--color-error)';
      if (stop?.machines?.some((machine) => machine.active_l1_incident)) return 'var(--color-warning)';
      if (stop?.machines?.some((machine) => machine.is_temporary_reassignment)) return 'var(--color-info)';
      return 'var(--color-primary)';
    },
    stopLabel(stop) {
      return stop?.location_name || `Parada ${stop?.stop_order ?? ''}`.trim();
    },
    stopStatusLabel(stop) {
      if (stop?.has_valid_coordinates === false) return 'Sin GPS';
      return `${stop?.machines?.length || 0} máquina(s)`;
    },
    formatDistance(value) {
      return value === null || value === undefined ? 'Sin GPS' : `${Number(value).toFixed(1)} km`;
    },
    selectStop(stop) {
      if (this.suppressNextClick) {
        this.suppressNextClick = false;
        return;
      }
      this.selectedStopOrder = stop.stop_order;
      if (this.stopHasGps(stop)) {
        const point = this.markerFor(stop);
        this.focusOn(point.x / 100, point.y / 100);
      }
    },
    clearSelection() {
      this.selectedStopOrder = null;
    },
    hideTile(event) {
      if (event?.target?.style) event.target.style.display = 'none';
    },
    stopNavigationUrl(stop) {
      if (stop?.navigation_url) return stop.navigation_url;
      if (!this.stopHasGps(stop)) return '';
      return `https://www.google.com/maps/dir/?api=1&destination=${Number(stop.latitude)},${Number(stop.longitude)}&travelmode=driving`;
    },
    openNavigation(url) {
      if (!url) return;
      if (typeof window !== 'undefined' && typeof window.open === 'function') {
        window.open(url, '_blank', 'noopener');
      }
    },
    navigateToStop(stop) {
      this.openNavigation(this.stopNavigationUrl(stop));
    },
    navigateFullRoute() {
      this.openNavigation(this.fullRouteUrl);
    },
    close() {
      this.$emit('update:modelValue', false);
    }
  },
  template: `
    <div v-if="isVisible" class="route-map-backdrop" data-testid="restocker-route-map-modal" @click.self="close">
      <div ref="routeMapDialog" class="route-map-modal" role="dialog" aria-modal="true" aria-labelledby="restockerRouteMapTitle" tabindex="-1">
        <div class="route-map-header" style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
          <div>
            <h2 id="restockerRouteMapTitle" style="font-family: var(--font-display); font-size: 17px; margin: 0;">🗺️ Mapa de mi ruta</h2>
            <p style="font-size: 11px; margin: 2px 0 0 0; opacity: 0.9;">{{ serviceDate || 'Hoy' }} · {{ originLabel }}</p>
          </div>
          <button ref="routeMapCloseButton" type="button" class="vg-btn vg-btn-ghost vg-btn-ghost-inverse vg-btn-sm" data-testid="btn-close-route-map" aria-label="Cerrar mapa" @click="close">Cerrar</button>
        </div>

        <div class="route-map-body">
          <div v-if="!hasStops" class="text-center" style="padding: 28px 12px; color: var(--color-ink-muted);">
            <p style="font-weight: 600; margin: 0 0 4px 0;">No hay paradas pendientes para hoy.</p>
            <p style="font-size: 12px; margin: 0;">Las paradas aparecerán aquí cuando Coordinación las asigne.</p>
          </div>

          <template v-else>
            <div class="route-map-canvas-wrapper">
              <div
                class="route-map-canvas territorial-map-interactive"
                data-testid="restocker-route-map-canvas"
                @wheel.prevent="handleWheel"
                @dblclick.prevent="handleDblClick"
                @pointerdown="handlePointerDown"
                @pointermove="handlePointerMove"
                @pointerup="handlePointerUp"
                @pointercancel="handlePointerUp"
                @pointerleave="handlePointerLeave"
              >
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
                <svg class="route-map-overlay" viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label="Mapa de la ruta del reponedor">
                  <polyline
                    v-if="stops.length > 1"
                    :points="[originPoint(), ...stops.filter(stopHasGps).map(markerFor)].map(point => point.x + ',' + point.y).join(' ')"
                    class="route-map-line"
                  />
                  <g class="route-map-origin" :transform="'translate(' + originPoint().x + ', ' + originPoint().y + ')'">
                    <circle r="3.4" class="route-origin-dot" />
                    <polygon points="-1.4,-2 -1.4,2 2.2,0" class="route-origin-triangle" />
                  </g>
                  <g
                    v-for="stop in stops.filter(stopHasGps)"
                    :key="'marker-' + stop.stop_order"
                    class="route-map-marker"
                    :class="{ 'is-selected': selectedStopOrder === stop.stop_order }"
                    :transform="'translate(' + markerFor(stop).x + ', ' + markerFor(stop).y + ')'"
                    role="button"
                    tabindex="0"
                    :aria-label="'Parada ' + stop.stop_order + ': ' + stopLabel(stop)"
                    @click="selectStop(stop)"
                    @keydown.enter.prevent="selectStop(stop)"
                  >
                    <circle r="10" fill="transparent" class="route-marker-hit" />
                    <circle r="4.6" :fill="stopColor(stop)" class="route-marker-circle" />
                    <text y="1.6" text-anchor="middle" class="route-marker-text">{{ stop.stop_order }}</text>
                  </g>
                </svg>
                <div class="route-map-attribution">© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors</div>
                <div class="territorial-map-controls">
                  <button type="button" class="territorial-map-btn" data-testid="btn-route-map-zoom-in" :disabled="view.scale >= zoomMaxScale" @click="zoomIn" aria-label="Acercar el mapa">+</button>
                  <button type="button" class="territorial-map-btn" data-testid="btn-route-map-zoom-out" :disabled="view.scale <= zoomMinScale" @click="zoomOut" aria-label="Alejar el mapa">−</button>
                  <button type="button" class="territorial-map-btn" data-testid="btn-route-map-fit" :disabled="view.scale <= zoomMinScale" @click="resetView" aria-label="Ver la ruta completa">⤢</button>
                </div>
              </div>
              <div class="route-map-legend small">
                <span><i class="route-dot" style="background: var(--color-error);"></i> FEFO</span>
                <span><i class="route-dot" style="background: var(--color-warning);"></i> L1</span>
                <span><i class="route-dot" style="background: var(--color-info);"></i> Desvío</span>
                <span><i class="route-dot" style="background: var(--color-primary);"></i> Cadencia</span>
              </div>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px;">
              <span class="vg-badge vg-badge-neutral">{{ summary.pending_stops_count }} paradas</span>
              <span class="vg-badge vg-badge-neutral">{{ summary.pending_machines_count }} máquinas</span>
              <span class="vg-badge vg-badge-neutral">{{ distanceLabel }}</span>
              <span v-if="summary.unmapped_stops_count > 0" class="vg-badge vg-badge-warning">{{ summary.unmapped_stops_count }} sin GPS</span>
            </div>

            <div class="d-grid" style="margin-bottom: 10px;">
              <button type="button" class="vg-btn vg-btn-primary" data-testid="btn-route-map-full-navigation" :disabled="!fullRouteUrl" @click="navigateFullRoute">
                🚗 Abrir ruta completa en Google Maps
              </button>
            </div>
            <p v-if="waypointsTruncated" class="vg-alert vg-alert-warning" style="font-size: 11px; margin-bottom: 10px;">
              El navegador muestra las primeras paradas priorizadas; usa el botón individual para las siguientes.
            </p>

            <ol class="route-stop-list" style="list-style: none; padding: 0;">
              <li
                v-for="stop in stops"
                :key="'list-' + stop.stop_order"
                class="route-stop-card"
                :class="{ 'is-selected': selectedStopOrder === stop.stop_order }"
                data-testid="restocker-route-map-stop"
                @click="selectStop(stop)"
              >
                <span class="route-stop-badge" :style="{ background: stopColor(stop) }">{{ stop.stop_order }}</span>
                <span class="route-stop-main" style="min-width: 0;">
                  <strong style="display: block;">{{ stopLabel(stop) }}</strong>
                  <small style="display: block; color: var(--color-ink-muted);">{{ stop.address }}</small>
                  <small style="display: block;">{{ stopStatusLabel(stop) }} · {{ formatDistance(stop.distance_km) }}</small>
                </span>
              </li>
            </ol>

            <div v-if="selectedStop" class="route-stop-sheet">
              <div style="display: flex; justify-content: space-between; gap: 8px; align-items: flex-start;">
                <div>
                  <h3 style="font-family: var(--font-display); font-size: 15px; margin: 0 0 4px 0;">Parada {{ selectedStop.stop_order }} · {{ stopLabel(selectedStop) }}</h3>
                  <p style="font-size: 12px; color: var(--color-ink-muted); margin: 0 0 6px 0;">{{ selectedStop.address }}</p>
                  <p style="font-size: 12px; margin: 0;">{{ stopStatusLabel(selectedStop) }} · {{ formatDistance(selectedStop.distance_km) }}</p>
                </div>
                <button type="button" class="vg-btn vg-btn-ghost vg-btn-sm" @click="clearSelection">Cerrar</button>
              </div>
              <button
                type="button"
                class="vg-btn vg-btn-primary w-100"
                style="margin-top: 10px;"
                :disabled="!stopNavigationUrl(selectedStop)"
                @click="navigateToStop(selectedStop)"
              >
                🧭 Navegar con GPS
              </button>
            </div>
          </template>
        </div>
      </div>
    </div>
  `
};

export default RestockerRouteMapModal;
