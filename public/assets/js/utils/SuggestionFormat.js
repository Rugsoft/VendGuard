/**
 * VendGuard - SuggestionFormat (SuggestionFormat.js)
 *
 * Helpers de renderizado del motor de asignación asistida (Módulo 17, RF-04.3,
 * contrato §3.6). Consumidos por CoordinatorDashboardView y por el componente
 * AssignSuggestionPanel para que el desglose, la distancia y las insignias se
 * traduzcan igual en cualquier punto de entrada del panel de asignación.
 *
 * Dogma Vanilla: funciones puras, sin estado y sin dependencias externas.
 */

/** Rótulos en castellano de las insignias del motor (contrato §3.6). */
export function suggestionBadgeLabel(badge) {
  const labels = {
    RECOMENDADO: '⭐ Sugerido por Zona',
    GUARDIA_24_7: '🚨 Guardia 24/7',
    TITULAR_ZONA: 'Titular de zona',
    SUPLENTE_ZONA: 'Suplente de zona',
    EN_CURSO_OTRA_SEDE: '⚠️ En curso en otra sede',
    NO_DISPONIBLE: '⛔ No disponible',
    STOCK_COMPATIBLE: '✅ Stock compatible',
    STOCK_PARCIAL: '⚠️ Stock parcial',
    SIN_STOCK_COMPATIBLE: '⚠️ Sin stock en furgoneta'
  };
  return labels[badge] || String(badge);
}

/** Desglose legible de la puntuación del sugerido (RF-04.3): sólo lo distinto de cero. */
export function suggestionBreakdown(suggestion) {
  const breakdown = suggestion?.score_breakdown || {};
  const parts = [];
  if (Number(breakdown.territorial_affinity || 0) !== 0) {
    parts.push(`Afinidad ${Number(breakdown.territorial_affinity) > 0 ? '+' : ''}${breakdown.territorial_affinity}`);
  }
  if (Number(breakdown.onsite_presence || 0) !== 0) {
    parts.push(`In situ +${breakdown.onsite_presence}`);
  }
  if (Number(breakdown.same_zone_stops || 0) !== 0) {
    parts.push(`Paradas de zona +${breakdown.same_zone_stops}`);
  }
  if (Number(breakdown.active_workload_penalty || 0) !== 0) {
    parts.push(`Carga ${breakdown.active_workload_penalty}`);
  }
  if (Number(breakdown.inter_location_penalty || 0) !== 0) {
    parts.push(`Otra sede ${breakdown.inter_location_penalty}`);
  }
  if (Number(breakdown.cold_chain_on_call_bonus || 0) !== 0) {
    parts.push(`Guardia +${breakdown.cold_chain_on_call_bonus}`);
  }
  return parts.join(' · ');
}

/** Distancia de referencia del técnico, ya calculada por el servidor (RF-06.2). */
export function suggestionDistance(suggestion) {
  const raw = suggestion?.current_status?.distance_km;
  if (raw === null || raw === undefined || raw === '') {
    return '—';
  }
  const distance = Number(raw);
  return Number.isFinite(distance) ? `${distance.toFixed(1)} km` : '—';
}
