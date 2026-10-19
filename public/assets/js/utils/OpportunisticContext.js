/**
 * VendGuard - Contexto oportunista de la hoja de ruta (utils/OpportunisticContext.js)
 *
 * Funciones puras que las vistas necesitan para leer el contrato del Módulo 16
 * (`GET /api/technician/my-route?include_opportunistic_context=1`) sin volver a
 * derivar reglas dentro de las plantillas:
 *
 * - **Normalización del sobre.** El endpoint devuelve el array histórico de paradas
 *   cuando no se pide contexto y el envoltorio `{ stops, enabled_locations,
 *   pending_critical_stops_other_locations }` cuando sí. La vista de ruta debe
 *   aceptar las dos formas sin romper los contratos de los módulos 03, 07, 11 y 15
 *   (plan §5, decisión 3).
 * - **Tarjeta de sede recién visitada (RF-01.2).** Solo las sedes habilitadas
 *   únicamente por la ventana de gracia de 30 minutos llevan tarjeta temporal, con
 *   los minutos restantes ya calculados.
 * - **Agrupación de intervenciones de la misma sede (RF-02.1).** Varias máquinas
 *   `IN_PROGRESS` del mismo edificio se leen como una sola parada física ordenada por
 *   criticidad: primero urgencia, después el SLA más ajustado.
 * - **Insignias de trazabilidad (RF-06.4).** Traduce el enum del expediente a las dos
 *   etiquetas canónicas sin duplicar los textos en cada componente.
 *
 * Dogma Vanilla: ESM nativo, funciones puras, datos congelados y cero dependencias.
 */

import {
  INCIDENT_STATUSES,
  URGENCY_RANKS,
  normalizeIncidentStatus,
  normalizeUrgency
} from './IncidentStatusPermissions.js';

/** Segundos de la ventana de gracia tras resolver o pausar en una sede (RF-01.1.2). */
export const GRACE_WINDOW_SECONDS = 1800;

/** Tipo de habilitación que enciende la tarjeta temporal de sede recién visitada. */
export const GRACE_ENABLEMENT_TYPE = 'GRACE_WINDOW_30M';

/** Etiquetas canónicas de la modalidad oportunista (RF-06.4). */
export const OPPORTUNISTIC_MODE_LABELS = Object.freeze({
  NEW: 'Oportunista In Situ · Nueva',
  ASSUMED: 'Oportunista In Situ · Asumida'
});

/** Etiquetas del submotivo del alta in situ (RF-04.1.2). */
export const OPPORTUNISTIC_ORIGIN_LABELS = Object.freeze({
  SITE_VERBAL_NOTICE: 'Aviso verbal en sede',
  TECHNICIAN_DETECTION: 'Detección propia del técnico',
  EXISTING_TICKET_CLAIM: 'Asunción de ticket abierto in situ',
  WARRANTY_REOPEN_IN_SITU: 'Reapertura en garantía in situ'
});

/**
 * Alias anonimizado del técnico en las superficies externas a la empresa
 * (RF-06.3.1, Art. V.4). El nombre real nunca sale del canal interno.
 */
export const OFFICIAL_TECHNICIAN_ALIAS = 'Servicio Técnico Oficial';

/**
 * Normaliza la respuesta de la hoja de ruta a la forma del envoltorio del Módulo 16.
 *
 * Acepta el array histórico, el envoltorio (`stops`, `enabled_locations`, …) y el
 * sobre genérico `{ success, data }` que publican otros módulos. Nunca lanza: una
 * respuesta inesperada se lee como ruta vacía para que la pantalla del técnico siga
 * siendo operable.
 *
 * @param {*} response Respuesta cruda de `api.technician.getMyRoute()`.
 * @returns {{stops: Array<Object>, enabledLocations: Array<Object>, pendingCriticalStops: Array<Object>}}
 */
export function normalizeRouteEnvelope(response) {
  if (Array.isArray(response)) {
    return { stops: response, enabledLocations: [], pendingCriticalStops: [] };
  }

  if (!response || typeof response !== 'object') {
    return { stops: [], enabledLocations: [], pendingCriticalStops: [] };
  }

  // Envoltorio del módulo 16, o `data` genérico cuando el backend envuelve la ruta.
  const candidate = Array.isArray(response.stops) ? response : (response.data ?? null);
  if (Array.isArray(candidate)) {
    return { stops: candidate, enabledLocations: [], pendingCriticalStops: [] };
  }

  if (!candidate || typeof candidate !== 'object') {
    return { stops: [], enabledLocations: [], pendingCriticalStops: [] };
  }

  return {
    stops: Array.isArray(candidate.stops) ? candidate.stops : [],
    enabledLocations: Array.isArray(candidate.enabled_locations) ? candidate.enabled_locations : [],
    pendingCriticalStops: Array.isArray(candidate.pending_critical_stops_other_locations)
      ? candidate.pending_critical_stops_other_locations
      : []
  };
}

/**
 * Sedes que solo están habilitadas por la ventana de gracia de 30 minutos.
 *
 * Las que todavía tienen una parada activa (`ACTIVE_STOP`) no necesitan tarjeta
 * temporal: su punto de entrada es la propia parada de la ruta (RF-01.2).
 *
 * @param {Array<Object>} enabledLocations Lista `enabled_locations` del contrato.
 * @returns {Array<{location_id: number, location_name: string, location_address: string|null, grace_expires_at: string|null, grace_remaining_seconds: number, grace_remaining_minutes: number, last_intervention_reference: string|null}>}
 */
export function graceWindowLocations(enabledLocations) {
  if (!Array.isArray(enabledLocations)) {
    return [];
  }

  return enabledLocations
    .filter((location) => String(location?.enablement_type || '').toUpperCase() === GRACE_ENABLEMENT_TYPE)
    .map((location) => {
      const remaining = Number(location?.grace_remaining_seconds);
      const safeRemaining = Number.isFinite(remaining) && remaining > 0 ? Math.floor(remaining) : 0;

      return {
        location_id: Number(location?.location_id ?? 0),
        location_name: String(location?.location_name ?? ''),
        location_address: location?.location_address ?? null,
        grace_expires_at: location?.grace_expires_at ?? null,
        grace_remaining_seconds: safeRemaining,
        grace_remaining_minutes: Math.ceil(safeRemaining / 60),
        last_intervention_reference: location?.last_intervention_reference ?? null
      };
    })
    .filter((location) => location.location_id > 0);
}

/**
 * Minutos restantes de la ventana de gracia en el instante consultado.
 *
 * Se calcula también en cliente porque la tarjeta temporal se repinta con el reloj
 * del dispositivo: la cifra del servidor es la referencia de apertura, no un valor
 * congelado que envejezca mientras la pantalla está abierta.
 *
 * @param {{grace_expires_at?: string|null, grace_remaining_seconds?: number}} location Sede habilitada.
 * @param {number} [nowMs] Instante de referencia en milisegundos.
 * @returns {number} Minutos restantes (0 cuando ya expiró o no hay datos).
 */
export function graceRemainingMinutes(location, nowMs = Date.now()) {
  const expiresAt = location?.grace_expires_at;
  if (expiresAt) {
    const expiryMs = Date.parse(String(expiresAt));
    if (Number.isFinite(expiryMs)) {
      const deltaMs = expiryMs - nowMs;
      return deltaMs > 0 ? Math.ceil(deltaMs / 60000) : 0;
    }
  }

  const seconds = Number(location?.grace_remaining_seconds);
  return Number.isFinite(seconds) && seconds > 0 ? Math.ceil(seconds / 60) : 0;
}

/**
 * Agrupa las intervenciones `IN_PROGRESS` de una misma sede en una sola parada física
 * ordenada por prioridad estricta (RF-02.1): primero urgencia, después el SLA más
 * ajustado y, a igualdad, el ticket más antiguo.
 *
 * La agrupación es una lectura de la ruta, no una reordenación destructiva: el resto
 * de paradas conserva su sitio y la primera de cada grupo lleva a las demás en
 * `colocated_stops`.
 *
 * @param {Array<Object>} incidents Paradas de la hoja de ruta.
 * @returns {Array<Object>} Paradas con `colocated_stops` y `colocation_count`.
 */
export function groupColocatedStops(incidents) {
  if (!Array.isArray(incidents)) {
    return [];
  }

  const groups = new Map();
  const order = [];

  for (const incident of incidents) {
    const status = normalizeIncidentStatus(incident?.status);
    const locationId = Number(incident?.location?.id ?? incident?.location_id ?? 0);

    if (status !== INCIDENT_STATUSES.IN_PROGRESS || !(locationId > 0)) {
      order.push({ key: `solo-${incident?.id}`, incident, siblings: [] });
      continue;
    }

    const key = `loc-${locationId}`;
    if (!groups.has(key)) {
      groups.set(key, []);
      order.push({ key, incident: null, siblings: groups.get(key) });
    }
    groups.get(key).push(incident);
  }

  const result = [];

  for (const entry of order) {
    if (entry.incident !== null) {
      result.push({ ...entry.incident, colocated_stops: [], colocation_count: 1 });
      continue;
    }

    const sorted = [...entry.siblings].sort(compareStopsByPriority);
    sorted.forEach((incident, index) => {
      result.push({
        ...incident,
        colocated_stops: sorted.filter((_, other) => other !== index),
        colocation_count: sorted.length,
        colocation_primary: index === 0
      });
    });
  }

  return result;
}

/**
 * Orden estricto entre dos paradas de la misma sede (RF-02.1): urgencia, luego SLA y
 * por último antigüedad del expediente.
 *
 * @param {Object} left
 * @param {Object} right
 * @returns {number}
 */
export function compareStopsByPriority(left, right) {
  const urgencyLeft = URGENCY_RANKS[normalizeUrgency(left?.urgency)] ?? 99;
  const urgencyRight = URGENCY_RANKS[normalizeUrgency(right?.urgency)] ?? 99;
  if (urgencyLeft !== urgencyRight) {
    return urgencyLeft - urgencyRight;
  }

  const slaLeft = slaSortValue(left);
  const slaRight = slaSortValue(right);
  if (slaLeft !== slaRight) {
    return slaLeft - slaRight;
  }

  const createdLeft = Date.parse(String(left?.created_at ?? '')) || Number.MAX_SAFE_INTEGER;
  const createdRight = Date.parse(String(right?.created_at ?? '')) || Number.MAX_SAFE_INTEGER;

  return createdLeft - createdRight;
}

/**
 * Valor ordenable del SLA: la marca objetivo más próxima gana. Un expediente sin
 * `sla_target_at` queda al final del grupo.
 *
 * @param {Object} incident
 * @returns {number}
 */
export function slaSortValue(incident) {
  const target = Date.parse(String(incident?.sla_target_at ?? ''));
  return Number.isFinite(target) ? target : Number.MAX_SAFE_INTEGER;
}

/**
 * Etiqueta canónica de la modalidad oportunista de una parada, o `null` cuando el
 * expediente nació por el canal ordinario (RF-06.4).
 *
 * @param {Object|null|undefined} incident
 * @returns {string|null}
 */
export function opportunisticModeLabel(incident) {
  const direct = incident?.opportunistic_mode_label;
  if (typeof direct === 'string' && direct.trim() !== '') {
    return direct;
  }

  const mode = String(incident?.opportunistic_mode ?? '').trim().toUpperCase();

  return OPPORTUNISTIC_MODE_LABELS[mode] ?? null;
}

/**
 * Etiqueta del submotivo oportunista de un expediente, o cadena vacía cuando el
 * ticket no nació in situ o el motivo no consta.
 *
 * @param {Object|null|undefined} incident
 * @returns {string}
 */
export function opportunisticOriginLabel(incident) {
  const direct = incident?.opportunistic_origin_label;
  if (typeof direct === 'string' && direct.trim() !== '') {
    return direct;
  }

  const reason = String(incident?.opportunistic_origin_reason ?? '').trim().toUpperCase();

  return OPPORTUNISTIC_ORIGIN_LABELS[reason] ?? '';
}

/**
 * Alias público del técnico que atiende el expediente (Art. V.4):
 * `Servicio Técnico Oficial · Operador #[Código]` cuando consta el identificador y
 * solo el nombre institucional cuando no.
 *
 * @param {Object|null|undefined} incident
 * @param {number|string|null} [technicianId] Identificador a usar si el expediente no lo trae.
 * @returns {string}
 */
export function officialTechnicianAlias(incident, technicianId = null) {
  const raw = technicianId ?? incident?.assigned_technician_id ?? null;
  const parsed = Number.parseInt(String(raw ?? ''), 10);

  if (!Number.isFinite(parsed) || parsed <= 0) {
    return OFFICIAL_TECHNICIAN_ALIAS;
  }

  return `${OFFICIAL_TECHNICIAN_ALIAS} · Operador #${String(parsed).padStart(2, '0')}`;
}

/**
 * Resumen de las paradas críticas o de cadena de frío que esperan al técnico en otras
 * sedes (RF-03.1). La vista de ruta lo usa para el aviso previo; la confirmación
 * expresa sigue viviendo en el modal de intervención.
 *
 * @param {Array<Object>} pendingCriticalStops
 * @returns {{count: number, perishable_count: number, next_sla_at: string|null}}
 */
export function summarizePendingCriticalStops(pendingCriticalStops) {
  if (!Array.isArray(pendingCriticalStops) || pendingCriticalStops.length === 0) {
    return { count: 0, perishable_count: 0, next_sla_at: null };
  }

  let perishable = 0;
  let nextSla = null;

  for (const stop of pendingCriticalStops) {
    if (String(stop?.machine_type ?? '').toUpperCase() === 'PERISHABLE_FOOD') {
      perishable++;
    }
    const sla = Date.parse(String(stop?.sla_target_at ?? ''));
    if (Number.isFinite(sla) && (nextSla === null || sla < nextSla)) {
      nextSla = sla;
    }
  }

  return {
    count: pendingCriticalStops.length,
    perishable_count: perishable,
    next_sla_at: nextSla !== null ? new Date(nextSla).toISOString() : null
  };
}
