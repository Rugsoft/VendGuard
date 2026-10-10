/**
 * VendGuard - Shared incident status permission helpers (utils/IncidentStatusPermissions.js)
 *
 * Frontend mirror of the server-side incident lifecycle rules: the canonical status
 * enum (Core/Domain/ValueObject/IncidentStatus.php), the active-status set
 * (Application/Service/CoordinatorIncidentDetailService::ACTIVE_STATUSES) and the
 * state-machine guards of the coordinator endpoints (assignable = REGISTERED/REOPENED,
 * reassignable = ASSIGNED/IN_PROGRESS/PENDING_PARTS, cancellation = active only).
 *
 * Single source of truth in the browser for every surface that classifies a triage
 * row without holding its server-computed permissions: the coordinator tray quick
 * actions (EARS 5.5 / EARS 6.4), the tray metrics and filters, the SLA breach
 * detector and the territorial bulk-assign grouping. The incident detail modal does
 * NOT use these predicates: it consumes the authoritative `permissions` object the
 * API computes per ticket (API-First), so no duplicated decision exists there.
 *
 * If the lifecycle ever changes, the canonical edit is PHP (IncidentStatus +
 * CoordinatorIncidentDetailService) and this mirror plus its unit suite are updated
 * in the same commit. Never write literal status lists outside this module.
 *
 * Dogma Vanilla: native ESM, pure functions, frozen data, no dependencies.
 */

/** Canonical statuses, mirroring IncidentStatus.php (English canonical keys). */
export const INCIDENT_STATUSES = Object.freeze({
  REGISTERED: 'REGISTERED',
  ASSIGNED: 'ASSIGNED',
  IN_PROGRESS: 'IN_PROGRESS',
  PENDING_PARTS: 'PENDING_PARTS',
  PENDING_INFO: 'PENDING_INFO',
  RESOLVED: 'RESOLVED',
  REOPENED: 'REOPENED',
  CLOSED: 'CLOSED',
  CANCELLED: 'CANCELLED'
});

/** Statuses that accept an initial assignment (EARS 5.1, EARS 5.5). */
export const ASSIGNABLE_STATUSES = Object.freeze(['REGISTERED', 'REOPENED']);

/** Statuses with a live responsible technician: reassignment flow (RF-07.3). */
export const REASSIGNABLE_STATUSES = Object.freeze(['ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS']);

/**
 * Open statuses of the lifecycle, mirroring CoordinatorIncidentDetailService::ACTIVE_STATUSES.
 * PENDING_INFO is an active, non-terminal state: the SLA clock is frozen but the ticket
 * stays open, so the quick "Descartar" (RF-04.3) and the site reply remain reachable.
 */
export const ACTIVE_STATUSES = Object.freeze(['REGISTERED', 'REOPENED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 'PENDING_INFO']);

/** Statuses that end the lifecycle with a formal historical SLA balance. */
export const TERMINAL_STATUSES = Object.freeze(['RESOLVED', 'CLOSED', 'CANCELLED']);

/**
 * Bilingual alias map: localized Spanish labels and canonical English keys collapse
 * into the canonical English status. Unknown keys survive upper-cased, matching the
 * tolerant policy the coordinator tray used before this module existed.
 */
export const CANONICAL_STATUS_MAP = Object.freeze({
  REGISTERED: 'REGISTERED',
  REGISTRADA: 'REGISTERED',
  ASSIGNED: 'ASSIGNED',
  ASIGNADA: 'ASSIGNED',
  IN_PROGRESS: 'IN_PROGRESS',
  EN_CURSO: 'IN_PROGRESS',
  PENDING_PARTS: 'PENDING_PARTS',
  PENDIENTE_REPUESTO: 'PENDING_PARTS',
  PENDIENTE_REPUESTOS: 'PENDING_PARTS',
  PENDING_INFO: 'PENDING_INFO',
  PENDIENTE_INFORMACION: 'PENDING_INFO',
  PENDIENTE_DE_INFORMACION: 'PENDING_INFO',
  RESOLVED: 'RESOLVED',
  RESUELTA: 'RESOLVED',
  REOPENED: 'REOPENED',
  REABIERTA: 'REOPENED',
  CLOSED: 'CLOSED',
  CERRADA: 'CLOSED',
  CANCELLED: 'CANCELLED',
  CANCELADA: 'CANCELLED'
});

/**
 * Normalizes any status value (localized Spanish or canonical English, any casing,
 * stray whitespace, null/undefined) into the canonical English status. Unknown
 * values come back upper-cased and trimmed, never empty-punished.
 */
export function normalizeIncidentStatus(value) {
  const upper = String(value || '').trim().toUpperCase();
  return CANONICAL_STATUS_MAP[upper] || upper;
}

/**
 * True when the incident's canonical status accepts an initial assignment
 * (EARS 5.5): quick "Asignar" is offered only for REGISTERED/REOPENED tickets.
 */
export function canQuickAssign(incident) {
  return ASSIGNABLE_STATUSES.includes(normalizeIncidentStatus(incident?.status));
}

/**
 * True when the incident's canonical status is still active, so the destructive
 * quick "Descartar" is offered (EARS 6.4). Unknown or empty statuses fail safe
 * by hiding the destructive action.
 */
export function canQuickCancel(incident) {
  return canQuickCancelStatus(normalizeIncidentStatus(incident?.status));
}

/**
 * Status-only variant of the EARS 6.4 predicate, for code paths that already hold
 * the canonical status string (metrics, bulk grouping). Unknown or empty fails safe.
 */
export function canQuickCancelStatus(status) {
  return ACTIVE_STATUSES.includes(status);
}

/** True when the canonical status closes the lifecycle (RESOLVED/CLOSED/CANCELLED). */
export function isTerminalStatus(value) {
  return TERMINAL_STATUSES.includes(normalizeIncidentStatus(value));
}

/** True when the canonical status is RESOLVED (used for warranty window evaluation). */
export function isResolvedStatus(value) {
  return normalizeIncidentStatus(value) === INCIDENT_STATUSES.RESOLVED;
}

/** True when the canonical status is PENDING_INFO (waiting for site access/information). */
export function isPendingInfoStatus(value) {
  return normalizeIncidentStatus(value) === INCIDENT_STATUSES.PENDING_INFO;
}

/** True when the canonical status is one of the open lifecycle statuses. */
export function isActiveStatus(value) {
  return ACTIVE_STATUSES.includes(normalizeIncidentStatus(value));
}

/**
 * True while the ticket still waits for a responsible: an assignable status OR an
 * open ticket without an assigned technician. Mirrors the SLA breach detector rule
 * (CRITICAL pending > 60 minutes) of the coordinator tray.
 */
export function isPendingAssignment(valueOrIncident, assignedTechnicianId) {
  const incident = (valueOrIncident !== null && typeof valueOrIncident === 'object')
    ? valueOrIncident
    : { status: valueOrIncident, assigned_technician_id: arguments.length > 1 ? assignedTechnicianId : undefined };
  const status = normalizeIncidentStatus(incident?.status);
  return ASSIGNABLE_STATUSES.includes(status) || !incident?.assigned_technician_id;
}

// =====================================================================
// Localized labels + badge presentation (single shared source)
// =====================================================================

/**
 * Localized Spanish labels for the lifecycle statuses, mirroring the vocabulary
 * already used across the UI. RESOLVED keeps its tray wording (warranty window).
 */
export const STATUS_LABELS = Object.freeze({
  REGISTERED: 'Registrada',
  ASSIGNED: 'Asignada',
  IN_PROGRESS: 'En curso',
  PENDING_PARTS: 'Pendiente repuesto',
  PENDING_INFO: 'Pendiente información',
  RESOLVED: 'Resuelta (Garantía)',
  REOPENED: 'Reabierta',
  CLOSED: 'Cerrada',
  CANCELLED: 'Cancelada'
});

/** Canonical urgency levels, mirroring UrgencyLevel.php (English canonical keys). */
export const URGENCY_LEVELS = Object.freeze({
  CRITICAL: 'CRITICAL',
  HIGH: 'HIGH',
  MEDIUM: 'MEDIUM',
  LOW: 'LOW'
});

/** Priority ranks for deterministic sorting (1 = highest priority / CRITICAL first). */
export const URGENCY_RANKS = Object.freeze({
  [URGENCY_LEVELS.CRITICAL]: 1,
  [URGENCY_LEVELS.HIGH]: 2,
  [URGENCY_LEVELS.MEDIUM]: 3,
  [URGENCY_LEVELS.LOW]: 4
});

/**
 * Localized Spanish labels for urgencies. CRITICAL uses the short badge variant:
 * the full PHP label ('Crítica (Riesgo Alimentario)') is served by the API where
 * there is room for it; compact badges keep 'Crítica'.
 */
export const URGENCY_LABELS = Object.freeze({
  CRITICAL: 'Crítica',
  HIGH: 'Alta',
  MEDIUM: 'Media',
  LOW: 'Baja'
});

/** Spanish aliases for urgencies (accent-free keys; see normalizeBadgeKey). */
const URGENCY_KEY_ALIASES = Object.freeze({
  CRITICAL: 'CRITICAL',
  CRITICA: 'CRITICAL',
  HIGH: 'HIGH',
  ALTA: 'HIGH',
  MEDIUM: 'MEDIUM',
  MEDIA: 'MEDIUM',
  LOW: 'LOW',
  BAJA: 'LOW'
});

/**
 * Normalizes any urgency value (Spanish or English, casing, whitespace, accents)
 * into canonical English UrgencyLevel key. Unknown values return upper-cased.
 */
export function normalizeUrgency(value) {
  const key = normalizeBadgeKey(value);
  return URGENCY_KEY_ALIASES[key] || key;
}

/** True when the urgency level is CRITICAL. */
export function isCriticalUrgency(value) {
  return normalizeUrgency(value) === URGENCY_LEVELS.CRITICAL;
}

/**
 * Amber technical tokens (RNF-04), declared once. The MEDIUM urgency badge, the
 * PENDING_INFO status badge, and the MachineCard/LocationPortalView pending info banner
 * share them, so the paused state introduces no new hardcoded color to the design-token
 * debt ratchet.
 */
export const AMBER_TECHNICAL_TOKENS = Object.freeze({ bg: '#fef9c3', color: '#854d0e', border: '#fde047' });

/**
 * Rango térmico plausible del recinto de una máquina (RF-03.5.2, Constitución Art. II).
 *
 * Espejo en el cliente de `ResolutionValidator::MIN_PLAUSIBLE_TEMPERATURE_C` y
 * `MAX_PLAUSIBLE_TEMPERATURE_C` del servidor. Se declara una sola vez para que el bloque
 * de declaraciones sanitarias del cierre no pueda desincronizarse del validador que
 * manda: el técnico no debe poder teclear una lectura que el servidor rechazará después.
 */
export const SANITARY_TEMPERATURE_RANGE = Object.freeze({ min: -40.0, max: 80.0 });

/**
 * Estado higiénico-sanitario que enciende el bloqueo cautelar por riesgo térmico
 * (RF-03.5.1, Constitución Art. II).
 *
 * Espejo en el cliente del valor que el servidor publica en `machines.sanitary_status`
 * y que `QrScanService` lee para servir el modo SANITARY_QUARANTINE. El portal de sede
 * lo recibe en cada máquina de su parque y lo pinta como distintivo de riesgo, de modo
 * que técnico, coordinador y responsable de sede leen la misma cuarentena.
 */
export const SANITARY_STATUS_QUARANTINE = 'QUARANTINE';

/**
 * Distintivo literal del riesgo térmico que manda exhibir RF-03.5.1.
 *
 * El texto es contrato: se muestra idéntico en el escaneo QR público y en la tarjeta
 * de la máquina del portal de sede, y las aserciones lo anclan al literal de la
 * especificación en ambos canales para que una divergencia de copy rompa la batería.
 */
export const SANITARY_THERMAL_RISK_LABEL = 'Riesgo térmico: máquina en cuarentena preventiva';

/**
 * ¿La máquina está en cuarentena sanitaria?
 *
 * @param {string|undefined|null} value Valor crudo de `machine.sanitary_status`.
 * @returns {boolean}
 */
export function isSanitaryQuarantine(value) {
  return typeof value === 'string' && value.trim().toUpperCase() === SANITARY_STATUS_QUARANTINE;
}

/** Docker-design palette per urgency (semantic colors + CSS class hook). */
export const BADGE_URGENCY_PALETTE = Object.freeze({
  CRITICAL: Object.freeze({ cssClass: 'vg-badge-critical', bg: '#fee2e2', color: '#dc2626', border: '#fca5a5' }),
  HIGH: Object.freeze({ cssClass: 'vg-badge-high', bg: '#ffedd5', color: '#c2410c', border: '#fdba74' }),
  MEDIUM: Object.freeze({ cssClass: 'vg-badge-medium', ...AMBER_TECHNICAL_TOKENS }),
  LOW: Object.freeze({ cssClass: 'vg-badge-low', bg: '#dbeafe', color: '#1d4ed8', border: '#93c5fd' })
});

/** Docker-design palette per lifecycle status. */
export const BADGE_STATUS_PALETTE = Object.freeze({
  REGISTERED: Object.freeze({ bg: '#e5f2fc', color: '#003db5', border: '#9ec5fe' }),
  ASSIGNED: Object.freeze({ bg: '#ede9fe', color: '#6d28d9', border: '#c4b5fd' }),
  IN_PROGRESS: Object.freeze({ bg: '#fef3c7', color: '#92400e', border: '#fcd34d' }),
  PENDING_PARTS: Object.freeze({ bg: '#ffedd5', color: '#c2410c', border: '#fdba74' }),
  // Amber technical tokens (RNF-04), shared with the MEDIUM urgency badge: the frozen
  // SLA reads as "waiting on the site", never as a resolved or failed ticket.
  PENDING_INFO: AMBER_TECHNICAL_TOKENS,
  RESOLVED: Object.freeze({ bg: '#eaf8f1', color: '#065f46', border: '#86efac' }),
  REOPENED: Object.freeze({ bg: '#fee2e2', color: '#b91c1c', border: '#fca5a5' }),
  CLOSED: Object.freeze({ bg: '#f3f4f6', color: '#4b5563', border: '#d1d5db' }),
  CANCELLED: Object.freeze({ bg: '#f3f4f6', color: '#9ca3af', border: '#e5e7eb' })
});

/**
 * Badge-key normalization: trims, upper-cases, strips diacritics (NFD) and folds
 * whitespace into underscores, exactly the tolerant policy IncidentBadge used
 * before this module existed ('CRÍTICA' -> 'CRITICA', 'En curso' -> 'EN_CURSO').
 */
export function normalizeBadgeKey(value) {
  if (!value) {
    return '';
  }
  return String(value)
    .trim()
    .toUpperCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, '_');
}

/**
 * Resolves the full badge presentation (palette + localized label) for a value in
 * one of the three modes ('urgency' | 'status' | 'auto'; auto tries urgency first,
 * then status, the historical precedence of IncidentBadge). Returns null when
 * nothing matches so every consumer keeps its own fallback rendering (unknown
 * values are never invented).
 */
export function resolveBadgeConfig(value, type = 'auto') {
  const key = normalizeBadgeKey(value);
  if (!key) {
    return null;
  }

  if (type === 'urgency') {
    const urgency = URGENCY_KEY_ALIASES[key];
    return urgency ? { ...BADGE_URGENCY_PALETTE[urgency], label: URGENCY_LABELS[urgency] } : null;
  }

  if (type === 'status') {
    const status = CANONICAL_STATUS_MAP[key] || key;
    return BADGE_STATUS_PALETTE[status]
      ? { ...BADGE_STATUS_PALETTE[status], label: STATUS_LABELS[status] || status }
      : null;
  }

  const urgency = URGENCY_KEY_ALIASES[key];
  if (urgency) {
    return { ...BADGE_URGENCY_PALETTE[urgency], label: URGENCY_LABELS[urgency] };
  }
  const status = CANONICAL_STATUS_MAP[key] || key;
  return BADGE_STATUS_PALETTE[status]
    ? { ...BADGE_STATUS_PALETTE[status], label: STATUS_LABELS[status] || status }
    : null;
}

/** Localized Spanish labels for machine types, mirroring MachineType.php. */
export const MACHINE_TYPE_LABELS = Object.freeze({
  HOT_DRINKS: 'Café / Calientes',
  COLD_DRINKS: 'Bebidas Frías',
  SNACKS: 'Snacks y Aperitivos',
  PERISHABLE_FOOD: 'Comida Perecedera',
  COMBO: 'Máquina Mixta'
});

/** Friendly icon per machine type (presentation only). */
export const MACHINE_TYPE_ICONS = Object.freeze({
  HOT_DRINKS: '☕',
  COLD_DRINKS: '🥤',
  SNACKS: '🥨',
  PERISHABLE_FOOD: '🥪',
  COMBO: '📦'
});

/** Sanitary rule: only PERISHABLE_FOOD machines carry the food-risk SLA. */
export function isPerishableMachineType(value) {
  return String(value || '').trim().toUpperCase() === 'PERISHABLE_FOOD';
}

// =====================================================================
// Incident categories (mirrors IncidentCategory.php)
// =====================================================================

/** Canonical incident categories, mirroring IncidentCategory.php (English canonical keys). */
export const INCIDENT_CATEGORIES = Object.freeze([
  Object.freeze({
    value: 'TEMPERATURE_COLD',
    label: 'Temperatura / Pérdida de frío',
    description: 'Fallo en motor frigorífico o máquina marcando temperatura alta.',
    icon: '❄️'
  }),
  Object.freeze({
    value: 'PAYMENT_SYSTEM',
    label: 'Fallo en medios de pago',
    description: 'No acepta monedas, tarjeta bancaria o billetero atascado.',
    icon: '💳'
  }),
  Object.freeze({
    value: 'PRODUCT_JAM',
    label: 'Atasco de producto en espiral',
    description: 'El motor gira pero el producto queda atrapado en el carril.',
    icon: '⚠️'
  }),
  Object.freeze({
    value: 'ELECTRICAL_OFF',
    label: 'Máquina apagada / Sin suministro',
    description: 'Pantalla apagada, sin iluminación ni respuesta eléctrica.',
    icon: '🔌'
  }),
  Object.freeze({
    value: 'OTHER',
    label: 'Otro motivo',
    description: 'Cualquier otra anomalía no clasificada anteriormente.',
    icon: '📝'
  })
]);
