/**
 * VendGuard - Shared preventive maintenance label helpers (utils/PreventiveLabels.js)
 *
 * Single source of truth in the browser for the Spanish labels and badge colours of the
 * preventive maintenance module (Módulo 05): order status, order type, inspection verdict,
 * checklist item status, machine sanitary status, validity traffic light and certificate
 * status.
 *
 * The coordinator orders tray and the preventive order detail modal both consume these
 * maps, so a label can never diverge between the table row and the ficha. The detail
 * contract already publishes server-computed `*_label` fields (API-First, Dualismo
 * Lingüístico); the helpers here are the localised fallbacks for the rows that only carry
 * the technical code, plus the visual palette of the design system.
 *
 * Dogma Vanilla: native ESM, pure functions, frozen data, no dependencies.
 */

/** Order lifecycle statuses, mirroring the `preventive_orders.status` enum (English keys). */
export const PREVENTIVE_ORDER_STATUSES = Object.freeze({
  PENDING_ASSIGNMENT: 'PENDING_ASSIGNMENT',
  SCHEDULED: 'SCHEDULED',
  IN_INSPECTION: 'IN_INSPECTION',
  COMPLETED: 'COMPLETED',
  EXPIRED: 'EXPIRED',
  CANCELLED: 'CANCELLED'
});

/** Inspection verdicts, mirroring `preventive_orders.result` (RF-PREV-04). */
export const PREVENTIVE_RESULTS = Object.freeze({
  CONFORME: 'CONFORME',
  CONFORME_CON_OBSERVACIONES: 'CONFORME_CON_OBSERVACIONES',
  NO_CONFORME: 'NO_CONFORME',
  NO_EVALUABLE_POR_CAUSA_EXTERNA: 'NO_EVALUABLE_POR_CAUSA_EXTERNA'
});

/** Checklist item statuses, mirroring `preventive_order_items.status` (RF-PREV-03). */
export const PREVENTIVE_ITEM_STATUSES = Object.freeze({
  PASS: 'PASS',
  WARN: 'WARN',
  FAIL: 'FAIL',
  NOT_APPLICABLE: 'NOT_APPLICABLE'
});

/** Sanitary traffic light of the machine, mirroring `machines.sanitary_status` (EARS 6.1). */
export const MACHINE_SANITARY_STATUSES = Object.freeze({
  OK: 'OK',
  ATTENTION_REQUIRED: 'ATTENTION_REQUIRED',
  EXPIRED: 'EXPIRED',
  QUARANTINE: 'QUARANTINE',
  SEASONAL_PAUSE: 'SEASONAL_PAUSE'
});

const STATUS_BADGES = Object.freeze({
  PENDING_ASSIGNMENT: { label: 'Pendiente Asignación', bg: '#fef3c7', color: '#92400e', icon: '⏳' },
  SCHEDULED: { label: 'Programada', bg: '#e0e7ff', color: '#3730a3', icon: '📅' },
  IN_INSPECTION: { label: 'En Inspección', bg: '#dbeafe', color: '#1e40af', icon: '🔍' },
  COMPLETED: { label: 'Completada', bg: '#dcfce7', color: '#166534', icon: '✅' },
  EXPIRED: { label: 'Vencida', bg: '#fee2e2', color: '#991b1b', icon: '🔴' },
  CANCELLED: { label: 'Cancelada', bg: '#f1f5f9', color: '#475569', icon: '✕' }
});

const RESULT_BADGES = Object.freeze({
  CONFORME: { label: 'Conforme', bg: '#dcfce7', color: '#166534' },
  CONFORME_CON_OBSERVACIONES: { label: 'Con Obs.', bg: '#fef3c7', color: '#854d0e' },
  NO_CONFORME: { label: 'No Conforme', bg: '#fee2e2', color: '#991b1b' },
  NO_EVALUABLE_POR_CAUSA_EXTERNA: { label: 'No Evaluable', bg: '#f1f5f9', color: '#475569' }
});

const ORDER_TYPE_LABELS = Object.freeze({
  ROUTINE: 'Ordinaria',
  REINSPECTION: 'Reinspección',
  MANUAL_EXTRA: 'Extraordinaria'
});

const ITEM_STATUS_BADGES = Object.freeze({
  PASS: { label: 'Conforme', bg: '#dcfce7', color: '#166534', icon: '✅' },
  WARN: { label: 'Con observaciones', bg: '#fef3c7', color: '#854d0e', icon: '⚠️' },
  FAIL: { label: 'No conforme', bg: '#fee2e2', color: '#991b1b', icon: '⛔' },
  NOT_APPLICABLE: { label: 'No aplicable', bg: '#f1f5f9', color: '#475569', icon: '—' }
});

const SANITARY_STATUS_BADGES = Object.freeze({
  OK: { label: 'Operativa y vigente', bg: '#dcfce7', color: '#166534', icon: '🟢' },
  ATTENTION_REQUIRED: { label: 'Requiere atención', bg: '#fef3c7', color: '#854d0e', icon: '🟡' },
  EXPIRED: { label: 'Inspección vencida', bg: '#fee2e2', color: '#991b1b', icon: '🔴' },
  QUARANTINE: { label: 'Cuarentena sanitaria', bg: '#fee2e2', color: '#7f1d1d', icon: '🚫' },
  SEASONAL_PAUSE: { label: 'Pausa estacional', bg: '#f1f5f9', color: '#475569', icon: '🌙' }
});

const VALIDITY_BADGES = Object.freeze({
  VIGENTE: { label: 'Vigente', bg: '#dcfce7', color: '#166534', icon: '🟢' },
  PROXIMA_A_VENCER: { label: 'Próxima a vencer', bg: '#fef3c7', color: '#854d0e', icon: '🟡' },
  VENCIDA: { label: 'Vencida', bg: '#fee2e2', color: '#991b1b', icon: '🔴' },
  CUARENTENA: { label: 'Cuarentena sanitaria', bg: '#fee2e2', color: '#7f1d1d', icon: '🚫' },
  PAUSA_ESTACIONAL: { label: 'Pausa estacional', bg: '#f1f5f9', color: '#475569', icon: '🌙' },
  CERRADA: { label: 'Inspección cerrada', bg: '#e0e7ff', color: '#3730a3', icon: '🔒' }
});

const CERTIFICATE_STATUS_BADGES = Object.freeze({
  VALID: { label: 'Vigente', bg: '#dcfce7', color: '#166534' },
  SUSPENDED: { label: 'Suspendido cautelarmente', bg: '#fef3c7', color: '#854d0e' },
  REVOKED: { label: 'Revocado', bg: '#fee2e2', color: '#991b1b' }
});

const FALLBACK_BADGE = Object.freeze({ label: 'Desconocido', bg: '#f1f5f9', color: '#475569', icon: '•' });

/**
 * Resolves a badge descriptor for any of the module maps, falling back to the code
 * itself so an unmapped value is still visible instead of disappearing from the UI.
 */
function resolveBadge(map, value) {
  if (value === null || value === undefined || value === '') {
    return { ...FALLBACK_BADGE, label: 'Sin definir' };
  }
  return map[value] || { ...FALLBACK_BADGE, label: String(value) };
}

/** Badge of an order status (tray row and detail header). */
export function getOrderStatusBadge(status) {
  return resolveBadge(STATUS_BADGES, status);
}

/** Badge of an inspection verdict. Returns `null` when the order has no verdict yet. */
export function getOrderResultBadge(result) {
  if (!result) {
    return null;
  }
  return resolveBadge(RESULT_BADGES, result);
}

/** Spanish label of the order type. */
export function getOrderTypeLabel(orderType) {
  if (!orderType) {
    return 'Ordinaria';
  }
  return ORDER_TYPE_LABELS[orderType] || String(orderType);
}

/** Badge of a checklist item status. */
export function getChecklistItemBadge(status) {
  return resolveBadge(ITEM_STATUS_BADGES, status);
}

/** Badge of the machine sanitary traffic light. */
export function getSanitaryStatusBadge(status) {
  return resolveBadge(SANITARY_STATUS_BADGES, status);
}

/** Badge of the derived sanitary validity traffic light of the order. */
export function getValidityBadge(state) {
  return resolveBadge(VALIDITY_BADGES, state);
}

/** Badge of a sanitary certificate status. */
export function getCertificateStatusBadge(status) {
  return resolveBadge(CERTIFICATE_STATUS_BADGES, status);
}

export default {
  PREVENTIVE_ORDER_STATUSES,
  PREVENTIVE_RESULTS,
  PREVENTIVE_ITEM_STATUSES,
  MACHINE_SANITARY_STATUSES,
  getOrderStatusBadge,
  getOrderResultBadge,
  getOrderTypeLabel,
  getChecklistItemBadge,
  getSanitaryStatusBadge,
  getValidityBadge,
  getCertificateStatusBadge
};
