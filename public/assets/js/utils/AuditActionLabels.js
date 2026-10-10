/**
 * VendGuard - Shared audit catalogue (utils/AuditActionLabels.js)
 *
 * Single source of truth in the browser for the Spanish label, badge tone and dropdown
 * group of every `audit_log.action` value the backend can write, plus the Spanish label of
 * every `audit_log.entity_type`.
 *
 * The coordinator's audit viewer (AuditLogViewer.js) consumes this module for both the row
 * badge and the filter options, so badge and filter can no longer diverge from each other
 * (they used to be two hand-kept lists). And tests/unit/AuditActionCatalogTest.php holds the
 * module to the vocabulary the backend really writes - the literals passed to
 * `AuditLogger::log*Event()` plus the values the seeders insert into `audit_log` - so a
 * phantom option, a filter that can never match a row, breaks the suite instead of silently
 * returning nothing (RF-05, EARS 5.3/5.5).
 *
 * Entries labelled "(registro de demo)" are written only by database/DemoMetricsSeeder.php:
 * the application still does not audit ticket creation, intervention start nor automatic
 * closing, and those seeded rows are labelled rather than hidden from the coordinator.
 *
 * Dogma Vanilla: native ESM, pure functions, frozen data, no dependencies.
 */

/** Dropdown groups of the action filter, in display order. */
export const AUDIT_ACTION_GROUPS = Object.freeze({
  ticket: 'Incidencias y Tickets',
  machine: 'Parque de Máquinas',
  location: 'Sedes Clientes',
  user: 'Usuarios Internos',
  preventive: 'Mantenimiento Preventivo',
  sanitary: 'Sanidad Alimentaria',
  refund: 'Reintegros y Efectivo',
  spare_parts: 'Repuestos',
  qr: 'Etiquetas QR'
});

/** Badge palette of the design system, keyed by semantic tone. */
const TONES = Object.freeze({
  neutral: { bg: '#f1f5f9', color: '#334155', border: '#cbd5e1' },
  success: { bg: '#dcfce7', color: '#15803d', border: '#86efac' },
  danger: { bg: '#fee2e2', color: '#dc2626', border: '#fca5a5' },
  warning: { bg: '#fef3c7', color: '#92400e', border: '#fde68a' },
  info: { bg: '#e0f2fe', color: '#0369a1', border: '#7dd3fc' },
  primary: { bg: '#e5f2fc', color: '#2560ff', border: '#bfdbfe' },
  purple: { bg: '#f3e8ff', color: '#7e22ce', border: '#d8b4fe' }
});

/** Canonical Spanish label of every `audit_log.entity_type`. */
export const AUDIT_ENTITY_LABELS = Object.freeze({
  TICKET: 'Avería / Ticket',
  MACHINE: 'Máquina Vending',
  LOCATION: 'Sede Cliente',
  USER: 'Usuario Interno',
  PREVENTIVE_ORDER: 'Orden Preventiva',
  SANITARY_CERTIFICATE: 'Certificado Sanitario',
  REFUND_REQUEST: 'Expediente de Reintegro',
  UNCLAIMED_CASH_FINDING: 'Efectivo No Reclamado'
});

/**
 * Every action the backend can persist into `audit_log`.
 * Kept to one entry per line: the drift guard parses this array.
 */
export const AUDIT_ACTION_CATALOG = Object.freeze([
  { code: 'INCIDENT_ASSIGNED', label: 'Asignación de Técnico', tone: 'purple', group: 'ticket' },
  { code: 'INCIDENT_REASSIGNED', label: 'Reasignación de Técnico', tone: 'purple', group: 'ticket' },
  { code: 'INCIDENT_CANCELLED', label: 'Cancelación de Avería', tone: 'danger', group: 'ticket' },
  { code: 'INCIDENT_COMMENT_ADDED', label: 'Comentario en el Hilo', tone: 'info', group: 'ticket' },
  { code: 'REOPEN_TICKET', label: 'Reapertura de Ticket', tone: 'warning', group: 'ticket' },
  { code: 'RESOLVE_INCIDENT', label: 'Resolución de Avería', tone: 'success', group: 'ticket' },
  { code: 'PAUSE_PENDING_PARTS', label: 'Pausa por Repuestos', tone: 'warning', group: 'ticket' },
  { code: 'PREVENTIVE_TRIGGERED_INCIDENT', label: 'Aviso Abierto por Preventivo', tone: 'primary', group: 'ticket' },
  { code: 'URGENCY_ESCALATED_CRITICAL_BY_PREVENTIVE', label: 'Urgencia Elevada por Preventivo', tone: 'danger', group: 'ticket' },
  { code: 'TICKET_CREATED', label: 'Ticket Creado (registro de demo)', tone: 'neutral', group: 'ticket' },
  { code: 'INTERVENTION_STARTED', label: 'Inicio de Intervención (registro de demo)', tone: 'info', group: 'ticket' },
  { code: 'TICKET_RESOLVED', label: 'Ticket Resuelto (registro de demo)', tone: 'success', group: 'ticket' },
  { code: 'TICKET_AUTO_CLOSED', label: 'Cierre Automático (registro de demo)', tone: 'neutral', group: 'ticket' },
  { code: 'TECHNICIAN_ASSIGNED', label: 'Técnico Asignado (registro de demo)', tone: 'purple', group: 'ticket' },
  { code: 'MACHINE_CREATED', label: 'Alta de Máquina', tone: 'success', group: 'machine' },
  { code: 'MACHINE_UPDATED', label: 'Modificación de Máquina', tone: 'neutral', group: 'machine' },
  { code: 'MACHINE_TRANSFERRED', label: 'Traslado de Máquina', tone: 'info', group: 'machine' },
  { code: 'MACHINE_DEACTIVATED', label: 'Baja de Máquina', tone: 'danger', group: 'machine' },
  { code: 'MACHINE_REACTIVATED', label: 'Reactivación de Máquina', tone: 'success', group: 'machine' },
  { code: 'MACHINE_BLOCKED_NO_ACCESS', label: 'Máquina Bloqueada por Falta de Acceso', tone: 'danger', group: 'machine' },
  { code: 'MACHINE_UNBLOCKED_BY_ACCESS_CONFIRMATION', label: 'Máquina Desbloqueada por Confirmación de Acceso', tone: 'success', group: 'machine' },
  { code: 'LOCATION_CREATED', label: 'Alta de Sede', tone: 'success', group: 'location' },
  { code: 'LOCATION_UPDATED', label: 'Modificación de Sede', tone: 'neutral', group: 'location' },
  { code: 'LOCATION_DEACTIVATED', label: 'Baja de Sede', tone: 'danger', group: 'location' },
  { code: 'LOCATION_REACTIVATED', label: 'Reactivación de Sede', tone: 'success', group: 'location' },
  { code: 'LOCATION_ACCESS_CODE_ISSUED', label: 'Emisión de Clave de Centro', tone: 'success', group: 'location' },
  { code: 'LOCATION_ACCESS_CODE_REISSUED', label: 'Reemisión de Clave de Centro', tone: 'warning', group: 'location' },
  { code: 'LOCATION_INSPECTED', label: 'Inspección de Sede (registro de demo)', tone: 'info', group: 'location' },
  { code: 'USER_CREATED', label: 'Alta de Usuario', tone: 'success', group: 'user' },
  { code: 'USER_UPDATED', label: 'Modificación de Usuario', tone: 'neutral', group: 'user' },
  { code: 'USER_DEACTIVATED', label: 'Baja de Usuario', tone: 'danger', group: 'user' },
  { code: 'USER_REACTIVATED', label: 'Reactivación de Usuario', tone: 'success', group: 'user' },
  { code: 'USER_PASSWORD_RESET', label: 'Restablecimiento de Contraseña', tone: 'warning', group: 'user' },
  { code: 'CREATE_PREVENTIVE_ORDER', label: 'Alta de Orden Preventiva', tone: 'primary', group: 'preventive' },
  { code: 'ASSIGN_PREVENTIVE_ORDER', label: 'Asignación de Orden Preventiva', tone: 'purple', group: 'preventive' },
  { code: 'START_PREVENTIVE_INSPECTION', label: 'Inicio de Inspección Preventiva', tone: 'info', group: 'preventive' },
  { code: 'EVALUATE_PREVENTIVE_CHECKLIST', label: 'Evaluación de Checklist Preventivo', tone: 'success', group: 'preventive' },
  { code: 'CLAIM_PREVENTIVE_ORDER', label: 'Reclamación de Orden Preventiva', tone: 'warning', group: 'preventive' },
  { code: 'CANCEL_PREVENTIVE_ORDER', label: 'Cancelación de Orden Preventiva', tone: 'danger', group: 'preventive' },
  { code: 'REINSPECTION_COMPLETED', label: 'Reinspección Completada', tone: 'success', group: 'preventive' },
  { code: 'PREVENTIVE_REPLACED_PARTS', label: 'Repuestos Sustituidos en Preventivo', tone: 'info', group: 'preventive' },
  { code: 'UPDATE_MACHINE_PREVENTIVE_CONFIG', label: 'Configuración Preventiva de Máquina', tone: 'neutral', group: 'preventive' },
  { code: 'UPDATE_PREVENTIVE_TYPE_SETTINGS', label: 'Ajustes de Tipo Preventivo', tone: 'neutral', group: 'preventive' },
  { code: 'SET_SEASONAL_PAUSE', label: 'Pausa Estacional Activada', tone: 'warning', group: 'preventive' },
  { code: 'RESUME_SEASONAL_PAUSE', label: 'Pausa Estacional Finalizada', tone: 'success', group: 'preventive' },
  { code: 'ISSUE_SANITARY_CERTIFICATE', label: 'Emisión de Certificado Sanitario', tone: 'success', group: 'sanitary' },
  { code: 'SUSPEND_SANITARY_CERTIFICATE', label: 'Suspensión de Certificado Sanitario', tone: 'danger', group: 'sanitary' },
  { code: 'SANITARY_QUARANTINE_AUTO_TRIGGERED', label: 'Cuarentena Sanitaria Automática', tone: 'danger', group: 'sanitary' },
  { code: 'REFUND_CASE_CREATED', label: 'Alta de Expediente de Reintegro', tone: 'primary', group: 'refund' },
  { code: 'REFUND_CONTACT_RECTIFIED', label: 'Contacto del Reintegro Rectificado', tone: 'warning', group: 'refund' },
  { code: 'REFUND_INSPECTED', label: 'Inspección de Reintegro', tone: 'info', group: 'refund' },
  { code: 'REFUND_APPROVED', label: 'Reintegro Aprobado', tone: 'success', group: 'refund' },
  { code: 'REFUND_REJECTED', label: 'Reintegro Rechazado', tone: 'danger', group: 'refund' },
  { code: 'REFUND_PICKUP_PIN_REJECTED', label: 'PIN de Recogida Rechazado', tone: 'danger', group: 'refund' },
  { code: 'REFUND_DELIVERED_IN_HAND', label: 'Reintegro Entregado en Mano', tone: 'success', group: 'refund' },
  { code: 'REFUND_PAID_DIGITAL', label: 'Reintegro Pagado en Digital', tone: 'success', group: 'refund' },
  { code: 'UNCLAIMED_CASH_RECORDED', label: 'Efectivo No Reclamado Registrado', tone: 'warning', group: 'refund' },
  { code: 'REFUND_DETACHED_BY_INACTIVITY', label: 'Reintegro Desvinculado por Inactividad', tone: 'warning', group: 'refund' },
  { code: 'EXPORT_SPARE_PARTS_CSV', label: 'Exportación CSV de Repuestos', tone: 'neutral', group: 'spare_parts' },
  { code: 'QR_LABEL_GENERATED', label: 'Etiqueta QR Generada (registro de demo)', tone: 'neutral', group: 'qr' }
]);

const CATALOG_BY_CODE = Object.freeze(
  AUDIT_ACTION_CATALOG.reduce((index, entry) => {
    index[entry.code] = entry;
    return index;
  }, {})
);

const FALLBACK_TONE = TONES.neutral;

/**
 * Spanish label of an action code. An unmapped code is shown as-is instead of
 * disappearing, so an unforeseen value stays visible to the coordinator.
 */
export function getAuditActionLabel(action) {
  if (!action) {
    return 'Sin acción';
  }
  const entry = CATALOG_BY_CODE[action];
  return entry ? entry.label : String(action);
}

/** Badge descriptor (label plus palette) of an action code. */
export function getAuditActionBadge(action) {
  const entry = CATALOG_BY_CODE[action];
  const tone = entry ? (TONES[entry.tone] || FALLBACK_TONE) : FALLBACK_TONE;
  return { label: getAuditActionLabel(action), ...tone };
}

/** Grouped catalogue of the action filter, in display order. */
export function getAuditActionGroups() {
  return Object.keys(AUDIT_ACTION_GROUPS)
    .map((key) => ({
      key,
      label: AUDIT_ACTION_GROUPS[key],
      actions: AUDIT_ACTION_CATALOG.filter((entry) => entry.group === key)
    }))
    .filter((group) => group.actions.length > 0);
}

/** Spanish label of an entity type of `audit_log`. */
export function getAuditEntityLabel(entityType) {
  if (!entityType) {
    return 'Entidad desconocida';
  }
  return AUDIT_ENTITY_LABELS[entityType] || String(entityType);
}

export default {
  AUDIT_ACTION_GROUPS,
  AUDIT_ACTION_CATALOG,
  AUDIT_ENTITY_LABELS,
  getAuditActionLabel,
  getAuditActionBadge,
  getAuditActionGroups,
  getAuditEntityLabel
};
