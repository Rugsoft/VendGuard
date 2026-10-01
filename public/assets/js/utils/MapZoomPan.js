/**
 * VendGuard - Shared interactive map zoom/pan controller (utils/MapZoomPan.js)
 *
 * Reusable state + gesture methods for every interactive cartographic surface of the
 * application (coordinator territorial tab, technician route modal). The component owns
 * its base Mercator window (getBaseWindow) and its scale limits (getMinScale /
 * getMaxScale); this controller derives the effective (zoomed/panned) window from the
 * view state so tiles and markers always share the same isotropic scale.
 *
 * Behaviors:
 * 1. Anchored zoom: buttons, mouse wheel, double click and pinch keep the canvas point
 *    under the cursor/fingers visually fixed while zooming.
 * 2. One-pointer drag panning with a click-suppression flag so a gesture never fires an
 *    accidental marker click; two pointers pinch-zoom anchored at gesture start.
 * 3. Below the fitted scale (scale < 1) the whole base window stays visible: the center
 *    roams the full canvas so dragging still pans to the neighboring area (the adaptive
 *    tile window covers the extra viewport), and the tile zoom level drops adaptively
 *    (slippy-map style) so wider views request a handful of tiles instead of
 *    compounding their count.
 * 4. Pixel-aware tile choice (Leaflet style): when the host reports its rendered
 *    width, the tile level is picked so every standard 256px tile renders near its
 *    native size — wide panoramic canvases get sharper imagery instead of an
 *    upscaled, pixelated bitmap.
 *
 * Dogma Vanilla: native ESM, pure functions and plain objects, no dependencies.
 */

export const MAP_ZOOM_PAN_DEFAULTS = {
  MIN_SCALE: 1,
  MAX_SCALE: 12,
  WHEEL_FACTOR: 1.25,
  BUTTON_FACTOR: 1.5,
  DBLCLICK_FACTOR: 1.8,
  DRAG_THRESHOLD_PX: 6,
  // Tile zoom bounds: OSM serves z0..z19; never request below a readable z2.
  TILE_ZOOM_FLOOR: 2,
  TILE_ZOOM_CEILING: 19
};

/**
 * Interactive chrome that lives INSIDE the map surface on both maps: the zoom/fit
 * buttons, the marker pins (role="button") and the OpenStreetMap attribution link.
 */
const MAP_UI_TARGET_SELECTOR = 'button, a[href], input, select, textarea, [role="button"], [data-map-ui]';

/**
 * True when a press landed on that chrome instead of the draggable surface.
 */
function isMapUiTarget(target) {
  return Boolean(target && typeof target.closest === 'function' && target.closest(MAP_UI_TARGET_SELECTOR));
}

/**
 * Reactive data fields every interactive map component must expose in its data().
 */
export function mapZoomPanState() {
  return {
    // Interactive view state over the fitted window: scale 1 / centered = full fit
    view: { scale: 1, centerX: 0.5, centerY: 0.5 },
    isPanning: false,
    dragDistance: 0,
    suppressNextClick: false,
    pinchStartDistance: 0,
    pinchStartScale: 1,
    activePointers: new Map()
  };
}

/**
 * Gesture + view methods to spread into the component's methods. The host component
 * must provide getBaseWindow(), getMinScale() and getMaxScale().
 */
export const MapZoomPanMethods = {
  /**
   * Hook: the fitted (scale 1) Mercator window of the current content. Must return
   * { zoom, sideTiles, leftEdge, topEdge } or null when nothing is loaded yet.
   */
  getBaseWindow() {
    return null;
  },
  /**
   * Hook: lower scale bound. May be dynamic (e.g. a guaranteed geographic floor).
   */
  getMinScale() {
    return MAP_ZOOM_PAN_DEFAULTS.MIN_SCALE;
  },
  /**
   * Hook: upper scale bound.
   */
  getMaxScale() {
    return MAP_ZOOM_PAN_DEFAULTS.MAX_SCALE;
  },
  /**
   * Hook (optional): rendered canvas width in CSS pixels. When provided (> 0) the tile
   * zoom level is chosen pixel-aware, Leaflet style, so each standard 256px tile is
   * displayed at roughly its native size across the canvas — wide panoramic canvases
   * get sharper imagery than a square content-fit window would produce. Return 0 to
   * fall back to the pure scale-relative tile choice.
   */
  getRenderWidthPx() {
    return 0;
  },
  /**
   * Derives the effective tile-space window from the fitted window and the current
   * interactive view state. At scale 1 the fitted window is returned untouched. The
   * visible rectangle is always the [centerX ± 1/(2·scale)] fraction of the fitted
   * window, so the zoom anchor and panning stay exact. The tile zoom level adapts
   * slippy-map style to keep a ~1-tile rendering resolution at every scale: it rises
   * while zooming in (each 256px tile is never stretched, so OSM serves sharper
   * imagery instead of a pixelated enlarged bitmap) and drops while zooming out below
   * the fitted scale (the wider window keeps a handful of tiles instead of
   * compounding their count at the fitted resolution).
   */
  effectiveWindow() {
    const base = this.getBaseWindow();
    if (!base) {
      return base;
    }
    // Read the full view state up front: computed consumers (the map tile layers)
    // track only the fields read during their first evaluation, and the early return
    // below fires while the canvas is still unmeasured (loading spinner), which would
    // leave centerX/centerY untracked and freeze tile panning at the fitted scale.
    const scale = this.view.scale;
    const centerX = this.view.centerX;
    const centerY = this.view.centerY;
    const widthPx = typeof this.getRenderWidthPx === 'function' ? Number(this.getRenderWidthPx()) || 0 : 0;
    if (scale === 1 && widthPx <= 0) {
      // Content-fit hosts without a measurable canvas keep the fitted window verbatim.
      return base;
    }
    const spanFraction = base.sideTiles / Math.pow(2, base.zoom);
    const desiredFraction = spanFraction / scale;
    let idealZoom;
    if (widthPx > 0) {
      // Pixel-aware slippy choice: sideTiles ~= widthPx / 256 keeps every standard
      // tile near its native 256px render size. It already tracks the scale (the
      // scale factor sits inside the logarithm), so zooming in raises the level and
      // zooming out lowers it with no extra math.
      idealZoom = Math.log2((widthPx * scale) / (256 * spanFraction));
    } else {
      // Content-fit fallback (no measurable canvas): keep ~1 tile across the window.
      idealZoom = Math.log2(1 / desiredFraction);
    }
    const zoom = Math.max(MAP_ZOOM_PAN_DEFAULTS.TILE_ZOOM_FLOOR, Math.min(MAP_ZOOM_PAN_DEFAULTS.TILE_ZOOM_CEILING, Math.round(idealZoom)));
    const worldBase = Math.pow(2, base.zoom);
    const worldNew = Math.pow(2, zoom);
    const sideTiles = (base.sideTiles / scale) / worldBase * worldNew;
    return {
      zoom,
      sideTiles,
      leftEdge: ((base.leftEdge + (centerX - 1 / (2 * scale)) * base.sideTiles) / worldBase) * worldNew,
      topEdge: ((base.topEdge + (centerY - 1 / (2 * scale)) * base.sideTiles) / worldBase) * worldNew
    };
  },
/**
 * Keeps the view from losing the content while panning. Above the fitted scale the
 * visible rect must stay inside the fitted window: the center is held to the band
 * [1/(2·scale), 1 - 1/(2·scale)] so zooming in cannot slide the content off-canvas.
 * At or below the fitted scale the canvas already shows at least the whole fit, so the
 * center is NOT clamped: the viewport is a slippy map that slides onto any neighboring
 * area. Any clamp here is an artificial edge that freezes sustained dragging — a
 * full-width swipe covers one fitted-window width, so even a generous margin is
 * exhausted after two real-world gestures and the map looks broken while the gesture
 * still works. resetView() (the fit control) recovers the framing.
 */
clampCenter(value, scale) {
  const center = Number(value);
  if (!Number.isFinite(center)) {
    return 0.5;
  }
  const halfVisible = 1 / (2 * scale);
  if (halfVisible < 0.5) {
    return Math.min(Math.max(center, halfVisible), 1 - halfVisible);
  }
  return center;
},
  /**
   * Core zoom: sets a new scale keeping the base-window point under the canvas anchor
   * (nx, ny in [0,1]) visually fixed. The host component translates ny if its canvas
   * crops a band of the square window (panoramic canvases) before calling this.
   */
  zoomToPoint(scale, nx, ny) {
    const base = this.getBaseWindow();
    if (!base) {
      return;
    }
    const nextScale = Math.min(Math.max(Number(scale) || 1, this.getMinScale()), this.getMaxScale());
    if (nextScale === this.view.scale) {
      return;
    }
    const worldX = this.view.centerX + (Number(nx) - 0.5) / this.view.scale;
    const worldY = this.view.centerY + (Number(ny) - 0.5) / this.view.scale;
    this.view.scale = nextScale;
    this.view.centerX = this.clampCenter(worldX - (Number(nx) - 0.5) / nextScale, nextScale);
    this.view.centerY = this.clampCenter(worldY - (Number(ny) - 0.5) / nextScale, nextScale);
  },
  /**
   * Pans the view by raw pixel deltas so the content always follows the finger 1:1
   * at every zoom level (slippy behaviour). The square Mercator world renders
   * width-px per window-normalized unit on both axes (isotropic projection).
   *
   * The center is expressed in fitted-window units while the canvas shows
   * `baseSideTiles / scale` of them across `canvasWidthPx`, so a pixel delta has to be
   * divided by the canvas width AND by the scale. Leaving the scale factor out made the
   * window move `dragPx * scale` screen pixels: the metropolitan view (0.15x) crawled
   * 15px for a 100px drag and a close-up (8x) threw the map 800px away.
   */
  panBy(dxPx, dyPx, canvasWidthPx) {
    const width = Number(canvasWidthPx) || 1;
    const scale = Math.max(Number(this.view.scale) || 1, 1e-6);
    const unitsPerPx = 1 / (width * scale);
    this.view.centerX = this.clampCenter(this.view.centerX - dxPx * unitsPerPx, this.view.scale);
    this.view.centerY = this.clampCenter(this.view.centerY - dyPx * unitsPerPx, this.view.scale);
  },
  /**
   * Fits the whole content back into the canvas: fitted scale 1, centered.
   */
  resetView() {
    this.view.scale = 1;
    this.view.centerX = 0.5;
    this.view.centerY = 0.5;
  },
  zoomIn() {
    this.zoomToPoint(this.view.scale * MAP_ZOOM_PAN_DEFAULTS.BUTTON_FACTOR, 0.5, 0.5);
  },
  zoomOut() {
    this.zoomToPoint(this.view.scale / MAP_ZOOM_PAN_DEFAULTS.BUTTON_FACTOR, 0.5, 0.5);
  },
  /**
   * Mouse wheel zoom anchored at the cursor position.
   */
  handleWheel(event) {
    const rect = event.currentTarget.getBoundingClientRect();
    const nx = (event.clientX - rect.left) / rect.width;
    const ny = (event.clientY - rect.top) / rect.height;
    const factor = event.deltaY < 0 ? MAP_ZOOM_PAN_DEFAULTS.WHEEL_FACTOR : 1 / MAP_ZOOM_PAN_DEFAULTS.WHEEL_FACTOR;
    this.zoomToPoint(this.view.scale * factor, nx, ny);
  },
  /**
   * Double click / double tap zoom anchored at the cursor position.
   */
  handleDblClick(event) {
    const rect = event.currentTarget.getBoundingClientRect();
    const nx = (event.clientX - rect.left) / rect.width;
    const ny = (event.clientY - rect.top) / rect.height;
    this.zoomToPoint(this.view.scale * MAP_ZOOM_PAN_DEFAULTS.DBLCLICK_FACTOR, nx, ny);
  },
  /**
   * Pointer bookkeeping for drag and pinch: one pointer pans, two pinch-zoom.
   *
   * A press that lands on the map chrome (zoom/fit buttons, marker pins, attribution
   * link) is left untouched: capturing that pointer would retarget the derived click to
   * the canvas, so the control would look dead, and cancelling its default would strip
   * the browser event sequence buttons rely on.
   */
  handlePointerDown(event) {
    if (event.pointerType === 'mouse' && event.button !== 0) {
      return;
    }
    if (isMapUiTarget(event.target)) {
      return;
    }
    if (typeof event.preventDefault === 'function') {
      event.preventDefault();
    }
    if (event.currentTarget.setPointerCapture) {
      try { event.currentTarget.setPointerCapture(event.pointerId); } catch (e) { /* noop */ }
    }
    this.suppressNextClick = false;
    this.activePointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    if (this.activePointers.size === 1) {
      this.isPanning = true;
      this.dragDistance = 0;
    } else {
      this.isPanning = false;
      this.pinchStartDistance = this.activePointersDistance();
      this.pinchStartScale = this.view.scale;
    }
  },
  handlePointerMove(event) {
    if (!this.activePointers.has(event.pointerId)) {
      return;
    }
    const previous = this.activePointers.get(event.pointerId);
    this.activePointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    if (this.activePointers.size >= 2 && this.pinchStartDistance > 0) {
      const rect = event.currentTarget.getBoundingClientRect();
      const points = [...this.activePointers.values()];
      const distance = Math.hypot(points[0].x - points[1].x, points[0].y - points[1].y);
      const nx = ((points[0].x + points[1].x) / 2 - rect.left) / rect.width;
      const ny = ((points[0].y + points[1].y) / 2 - rect.top) / rect.height;
      // Spreading fingers widens the distance and therefore zooms in; both the
      // distance and the scale are anchored at gesture start to avoid compounding
      const targetScale = this.pinchStartScale * (distance / this.pinchStartDistance);
      this.zoomToPoint(targetScale, nx, ny);
    } else if (this.activePointers.size === 1 && this.isPanning) {
      const rect = event.currentTarget.getBoundingClientRect();
      const dx = event.clientX - previous.x;
      const dy = event.clientY - previous.y;
      this.dragDistance += Math.abs(dx) + Math.abs(dy);
      this.panBy(dx, dy, rect.width);
    }
  },
  handlePointerUp(event) {
    this.activePointers.delete(event.pointerId);
    if (this.activePointers.size < 2) {
      this.pinchStartDistance = 0;
      this.pinchStartScale = 1;
    }
    if (this.activePointers.size === 0) {
      this.isPanning = false;
      if (this.dragDistance > MAP_ZOOM_PAN_DEFAULTS.DRAG_THRESHOLD_PX) {
        this.suppressNextClick = true;
      }
    }
  },
  /**
   * A leave event must not fight pointer capture: while the canvas holds the capture the
   * gesture legitimately flows outside the element (the finger or cursor slides over the
   * page chrome), and aborting there is what makes a swipe look like it stopped working.
   * Only a real release (pointerup / pointercancel) ends a captured drag. Embedded
   * webviews without capture still need the leave as an end-of-gesture signal, so it is
   * honoured whenever no capture is active.
   */
  handlePointerLeave(event) {
    const target = event.currentTarget;
    if (target && typeof target.hasPointerCapture === 'function'
      && target.hasPointerCapture(event.pointerId) === true) {
      return;
    }
    this.handlePointerUp(event);
  },
  activePointersDistance() {
    const points = [...this.activePointers.values()];
    if (points.length < 2) {
      return 0;
    }
    return Math.hypot(points[0].x - points[1].x, points[0].y - points[1].y);
  }
};
