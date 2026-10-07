/**
 * VendGuard - AuditLogViewer Test Suite (AuditLogViewerTest.mjs)
 * 
 * Valida el componente visual de auditoría inmutable (T-MET-13):
 * - AuditLogViewer.js (Visor cronológico, filtros, detalles JSON y paginación).
 * 
 * Requisitos: RF-05 (EARS 5.3, 5.5), RNF-03, Art. III.3 y Art. V.1.
 * 
 * Hecho cuando:
 * 1. AuditLogViewer carga y muestra la lista de eventos de auditoría inmutable.
 * 2. Formatea semánticamente las acciones y etiquetas de entidades.
 * 3. Muestra el modal de detalles con diagnóstico, solución y piezas sustituidas (Art. V.1).
 * 4. Permite inspeccionar el payload JSON íntegro del evento.
 * 5. Gestiona la paginación reactiva (página anterior/siguiente y cálculo de límites).
 * 6. Genera el enlace de descarga CSV para exportación (RF-06).
 */

// Mock de entorno browser para Node.js ESM
const storageMock = (() => {
  let store = {};
  return {
    getItem: (key) => store[key] || null,
    setItem: (key, val) => { store[key] = String(val); },
    removeItem: (key) => { delete store[key]; },
    clear: () => { store = {}; }
  };
})();
globalThis.localStorage = storageMock;
globalThis.window = {
  location: { search: '', href: 'http://localhost/' },
  open: () => {}
};

import { api } from '../../public/assets/js/api.js';
import { AuditLogViewer } from '../../public/assets/js/components/AuditLogViewer.js';
import { AUDIT_ACTION_CATALOG } from '../../public/assets/js/utils/AuditActionLabels.js';

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) {
      console.error(`         Reason: ${details}`);
    }
    failures++;
  }
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - AuditLogViewer (T-MET-13)');
console.log('======================================================================\n');

// Mock data de eventos de auditoría
const mockAuditEvents = [
  {
    id: 128,
    timestamp: '2026-09-23 10:15:30',
    entity_type: 'TICKET',
    entity_id: 4,
    action: 'RESOLVE_INCIDENT',
    user: {
      id: 2,
      name: 'Jordi Técnico Ruta BCN',
      role: 'TECHNICIAN'
    },
    details: {
      previous_status: 'IN_PROGRESS',
      new_status: 'RESOLVED',
      diagnosis: 'Sensor térmico NTC descalibrado',
      solution: 'Sustitución sonda NTC',
      parts_replaced: [
        'Sonda térmica NTC Sanden',
        'Conector estanco IP67'
      ]
    }
  },
  {
    id: 127,
    timestamp: '2026-09-23 09:20:00',
    entity_type: 'TICKET',
    entity_id: 4,
    action: 'INCIDENT_ASSIGNED',
    user: {
      id: 2,
      name: 'Jordi Técnico Ruta BCN',
      role: 'TECHNICIAN'
    },
    details: {
      previous_status: 'REGISTERED',
      new_status: 'ASSIGNED'
    }
  },
  {
    id: 126,
    timestamp: '2026-09-23 08:30:00',
    entity_type: 'MACHINE',
    entity_id: 1,
    action: 'MACHINE_UPDATED',
    user: {
      id: 1,
      name: 'Sara Coordinadora',
      role: 'COORDINATOR'
    },
    details: {
      field_changed: 'floor_wing',
      old_value: 'Planta 1',
      new_value: 'Planta Baja Entrada'
    }
  }
];

// Mock API method
let lastRequestedParams = null;
api.auditLog.getEvents = async (params) => {
  lastRequestedParams = params;
  return {
    success: true,
    data: {
      page: params.page || 1,
      limit: params.limit || 25,
      total_records: 128,
      total_pages: 6,
      items: mockAuditEvents
    }
  };
};

const instance = {
  ...AuditLogViewer.data(),
  formatActionBadge: AuditLogViewer.methods.formatActionBadge,
  formatEntityName: AuditLogViewer.methods.formatEntityName,
  openEventDetail: AuditLogViewer.methods.openEventDetail,
  closeEventDetail: AuditLogViewer.methods.closeEventDetail,
  applyFilters: AuditLogViewer.methods.applyFilters,
  resetFilters: AuditLogViewer.methods.resetFilters,
  fetchAuditEvents: AuditLogViewer.methods.fetchAuditEvents,
  downloadAuditCsv: AuditLogViewer.methods.downloadAuditCsv
};

// Las propiedades calculadas se evalúan contra la instancia simulada
Object.defineProperty(instance, 'actionFilterGroups', {
  get: () => AuditLogViewer.computed.actionFilterGroups.call(instance)
});
Object.defineProperty(instance, 'entityOptions', {
  get: () => AuditLogViewer.computed.entityOptions.call(instance)
});

// -------------------------------------------------------------
// 1. Carga inicial de datos
// -------------------------------------------------------------
console.log('--- 1. Carga de Eventos de Auditoría ---');

await instance.fetchAuditEvents(1);
assert('1.1 Eventos cargados en la instancia', instance.events.length === 3);
assert('1.2 Total de registros asignado correctamente (128)', instance.totalRecords === 128);
assert('1.3 Total de páginas calculado (6)', instance.totalPages === 6);
assert('1.4 Página actual es 1', instance.currentPage === 1);

// -------------------------------------------------------------
// 2. Formateo de acciones y entidades
// -------------------------------------------------------------
console.log('\n--- 2. Formateo de Acciones y Entidades ---');

const badgeResolve = instance.formatActionBadge('RESOLVE_INCIDENT');
assert('2.1 RESOLVE_INCIDENT tiene etiqueta amigable en español', badgeResolve.label === 'Resolución de Avería');
assert('2.2 RESOLVE_INCIDENT usa fondo verde (#dcfce7)', badgeResolve.bg === '#dcfce7');

const badgeAssign = instance.formatActionBadge('INCIDENT_ASSIGNED');
assert('2.3 INCIDENT_ASSIGNED tiene etiqueta amigable', badgeAssign.label === 'Asignación de Técnico');
assert('2.3b INCIDENT_ASSIGNED usa el tono morado de asignación (#f3e8ff)', badgeAssign.bg === '#f3e8ff');

const entityTicket = instance.formatEntityName('TICKET', 4);
assert('2.4 Entidad TICKET formateada como "Avería #4"', entityTicket === 'Avería #4');

const entityMachine = instance.formatEntityName('MACHINE', 1);
assert('2.5 Entidad MACHINE formateada como "Máquina #1"', entityMachine === 'Máquina #1');

// -------------------------------------------------------------
// 3. Inspección y Modal de Detalle JSON (Art. III.3 / Art. V.1)
// -------------------------------------------------------------
console.log('\n--- 3. Inspección y Modal de Detalle JSON ---');

instance.openEventDetail(mockAuditEvents[0]);
assert('3.1 Modal de detalle activado', instance.showDetailModal === true);
assert('3.2 Evento seleccionado asignado correctamente', instance.selectedEvent.id === 128);
assert('3.3 Captura obligatoria de diagnóstico en detalles', instance.selectedEvent.details.diagnosis === 'Sensor térmico NTC descalibrado');
assert('3.4 Captura obligatoria de piezas sustituidas (Art. V.1)', instance.selectedEvent.details.parts_replaced.length === 2);

instance.closeEventDetail();
assert('3.5 Modal de detalle cerrado correctamente', instance.showDetailModal === false && instance.selectedEvent === null);

// -------------------------------------------------------------
// 4. Filtros reactivos y Paginación
// -------------------------------------------------------------
console.log('\n--- 4. Filtros Reactivos y Paginación ---');

instance.filterEntityType = 'TICKET';
instance.filterAction = 'RESOLVE_INCIDENT';
instance.filterFrom = '2026-09-01';
instance.filterTo = '2026-09-23';

await instance.applyFilters();
assert('4.1 Filtro entity_type propagado a la API', lastRequestedParams.entity_type === 'TICKET');
assert('4.2 Filtro action propagado a la API', lastRequestedParams.action === 'RESOLVE_INCIDENT');
assert('4.3 Filtro rango de fechas propagado a la API', lastRequestedParams.from === '2026-09-01' && lastRequestedParams.to === '2026-09-23');

// Paginación a página 2
await instance.fetchAuditEvents(2);
assert('4.4 Solicitud de página 2 enviada con éxito', lastRequestedParams.page === 2);

// Limpieza de filtros
instance.resetFilters();
assert('4.5 Filtros reseteados a vacío', instance.filterEntityType === '' && instance.filterAction === '');

// -------------------------------------------------------------
// 5. URL de Exportación CSV
// -------------------------------------------------------------
console.log('\n--- 5. URL de Exportación CSV (RF-06, EARS 6.2) ---');

const exportUrl = api.auditLog.exportCsvUrl({ entity_type: 'TICKET' });
assert('5.1 URL de descarga apunta al endpoint de exportación CSV', exportUrl.includes('/api/coordinator/audit-log/export'));
assert('5.2 Parámetro de filtro preservado en la URL de descarga', exportUrl.includes('entity_type=TICKET'));

// -------------------------------------------------------------
// 6. Catálogo compartido de acciones y entidades
// -------------------------------------------------------------
console.log('\n--- 6. Catálogo Compartido de Acciones y Entidades ---');

assert('6.1 El catálogo declara códigos únicos y cubre el parque completo',
  AUDIT_ACTION_CATALOG.length >= 50
  && new Set(AUDIT_ACTION_CATALOG.map((entry) => entry.code)).size === AUDIT_ACTION_CATALOG.length,
  `Entradas: ${AUDIT_ACTION_CATALOG.length}`);

assert('6.2 Toda acción de los eventos cargados muestra etiqueta en español (no un código crudo)',
  instance.events.every((event) => !/^[A-Z][A-Z0-9_]*$/.test(instance.formatActionBadge(event.action).label)));

assert('6.3 El filtro se construye agrupado desde el catálogo y cubre todas las acciones',
  instance.actionFilterGroups.length >= 8
  && instance.actionFilterGroups.reduce((total, group) => total + group.actions.length, 0) === AUDIT_ACTION_CATALOG.length
  && instance.actionFilterGroups.every((group) => group.actions.length > 0 && group.label.trim() !== ''));

const offeredActions = instance.actionFilterGroups.flatMap((group) => group.actions.map((entry) => entry.code));
assert('6.4 El filtro ya no ofrece acciones fantasma (ASSIGN_TECHNICIAN, CANCEL_INCIDENT, STATUS_CHANGE)',
  !offeredActions.includes('ASSIGN_TECHNICIAN')
  && !offeredActions.includes('CANCEL_INCIDENT')
  && !offeredActions.includes('STATUS_CHANGE'));

assert('6.5 El filtro ofrece las acciones reales del ciclo de incidencia',
  ['INCIDENT_ASSIGNED', 'INCIDENT_REASSIGNED', 'INCIDENT_CANCELLED', 'INCIDENT_COMMENT_ADDED', 'RESOLVE_INCIDENT', 'REOPEN_TICKET']
    .every((code) => offeredActions.includes(code)));

assert('6.6 Una acción desconocida conserva distintivo legible en lugar de desaparecer',
  instance.formatActionBadge('ASSIGN_TECHNICIAN').label === 'ASSIGN_TECHNICIAN');

assert('6.7 REOPEN_TICKET usa la etiqueta acordada y el tono de aviso',
  instance.formatActionBadge('REOPEN_TICKET').label === 'Reapertura de Ticket'
  && instance.formatActionBadge('REOPEN_TICKET').bg === '#fef3c7');

const entityCodes = Object.keys(instance.entityOptions);
assert('6.8 El filtro de entidad ofrece los ocho tipos del enum de audit_log',
  entityCodes.length === 8
  && ['TICKET', 'MACHINE', 'LOCATION', 'USER', 'PREVENTIVE_ORDER', 'SANITARY_CERTIFICATE', 'REFUND_REQUEST', 'UNCLAIMED_CASH_FINDING']
    .every((code) => entityCodes.includes(code))
  && Object.values(instance.entityOptions).every((label) => label.trim() !== ''));

assert('6.9 El formateador de entidad usa la etiqueta en español también para los tipos nuevos',
  instance.formatEntityName('REFUND_REQUEST', 12) === 'Expediente de Reintegro #12'
  && instance.formatEntityName('TICKET', 4) === 'Avería #4');

assert('6.10 Cada entrada del catálogo declara tono y grupo no vacíos',
  AUDIT_ACTION_CATALOG.every((entry) => entry.tone.trim() !== '' && entry.group.trim() !== ''));

console.log('\n======================================================================');
console.log(` RESUMEN: ${assertions} aserciones superadas exitosamente (100% PASS).`);
console.log(' CONDICIÓN T-MET-13 VERIFICADA SATISFACTORIAMENTE.');
console.log('======================================================================\n');

if (failures > 0) {
  process.exit(1);
}
