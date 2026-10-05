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
  RESOLVED: 'RESOLVED',
  REOPENED: 'REOPENED',
  CLOSED: 'CLOSED',
  CANCELLED: 'CANCELLED'
});

/** Statuses that accept an initial assignment (EARS 5.1, EARS 5.5). */
export const ASSIGNABLE_STATUSES = Object.freeze(['REGISTERED', 'REOPENED']);

/** Statuses with a live responsible technician: reassignment flow (RF-07.3). */
export const REASSIGNABLE_STATUSES = Object.freeze(['ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS']);

/** Open statuses of the lifecycle, mirroring CoordinatorIncidentDetailService::ACTIVE_STATUSES. */
export const ACTIVE_STATUSES = Object.freeze(['REGISTERED', 'REOPENED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS']);

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
