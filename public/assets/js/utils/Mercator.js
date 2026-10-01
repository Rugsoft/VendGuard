/**
 * VendGuard - Shared Web Mercator cartography helpers (utils/Mercator.js)
 *
 * Pure projection math and standard-map-tile windowing shared by every cartographic
 * surface of the application (technician route modal, coordinator territorial tab).
 * Standard public tiles (OpenStreetMap) with zero libraries, zero API keys and zero
 * paid providers (constitutional Art. IV, plan.md §5).
 *
 * Dogma Vanilla: native ESM, pure functions, no dependencies, no bundlers.
 */

/**
 * Standard Web Mercator projection helpers (pure functions).
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

/**
 * Computes the square Web Mercator window (in tile units) that frames the given
 * geographic points with a fixed padding. Deterministic: the same coordinates always
 * yield the same zoom and viewport.
 *
 * @param {Array<{lat:number, lng:number}>} points Finite geographic points to frame.
 * @param {Object} [options]
 * @param {number} [options.zoomCeiling=16] Maximum OSM zoom level allowed.
 * @param {number} [options.paddingFactor=1.6] Window side vs. content span factor
 *        (content occupies ~1/paddingFactor of the square side; panoramic canvases that
 *        crop the central band must use a factor above 1 / band ratio).
 * @returns {{zoom:number, sideTiles:number, leftEdge:number, topEdge:number}|null}
 */
export function computeTileWindow(points, { zoomCeiling = 16, paddingFactor = 1.6 } = {}) {
  const finite = (points || []).filter(
    point => Number.isFinite(Number(point.lat)) && Number.isFinite(Number(point.lng))
  );
  if (finite.length === 0) {
    return null;
  }
  const fractionsX = finite.map(point => Mercator.lngToFraction(point.lng));
  const fractionsY = finite.map(point => Mercator.latToFraction(point.lat));
  const minSpanFraction = 0.000012; // ~500 m minimum framing for a single-point day
  const spanX = Math.max(Math.max(...fractionsX) - Math.min(...fractionsX), minSpanFraction);
  const spanY = Math.max(Math.max(...fractionsY) - Math.min(...fractionsY), minSpanFraction);
  const sideFraction = Math.max(spanX, spanY) * paddingFactor; // content occupies ~1/paddingFactor of the canvas
  let zoom = Math.round(Math.log2(1 / sideFraction));
  zoom = Math.max(6, Math.min(zoomCeiling, zoom));
  let sideTiles = sideFraction * Math.pow(2, zoom);
  if (sideTiles < 0.9 && zoom < zoomCeiling) {
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
}

/**
 * Builds the list of standard public tiles covering the viewport. Percentages are
 * relative to the square canvas; edge tiles may overflow and are clipped by CSS.
 *
 * @param {{zoom:number, sideTiles:number, leftEdge:number, topEdge:number}} mapWindow
 * @returns {Array<{key:string, url:string, leftPct:number, topPct:number, widthPct:number, heightPct:number}>}
 */
export function buildMapTiles(mapWindow) {
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
}

/**
 * Projects a geographic point into canvas percentages within the given tile window.
 *
 * @returns {{x:number, y:number}}
 */
export function projectToWindow(lat, lng, mapWindow) {
  if (!mapWindow || !Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) {
    return { x: 50, y: 50 };
  }
  const worldX = Mercator.lngToWorldX(Number(lng), mapWindow.zoom);
  const worldY = Mercator.latToWorldY(Number(lat), mapWindow.zoom);
  return {
    x: ((worldX - mapWindow.leftEdge) / mapWindow.sideTiles) * 100,
    y: ((worldY - mapWindow.topEdge) / mapWindow.sideTiles) * 100
  };
}
