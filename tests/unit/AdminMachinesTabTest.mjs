/**
 * VendGuard - AdminMachinesTab Test Suite (AdminMachinesTabTest.mjs)
 * 
 * Valida la funcionalidad reactiva y contratos del componente AdminMachinesTab (RF-02, RNF-05, Constitución Art. II).
 * 
 * Hecho cuando:
 * 1. Renderiza la tabla de máquinas con insignias de tipología sanitaria (resaltando perecederos con SLA <= 4h).
 * 2. Aplica filtros cruzados reactivos (estado, sede, tipología y búsqueda de texto).
 * 3. Gestiona modal de alta con validación de código único y alerta sanitaria inmediata.
 * 4. Gestiona modal de edición preservando código y sede inmutables, con bloqueo preventivo de cambio de tipología ante averías.
 * 5. Gestiona modal de traslado con selector de sedes activas y salvaguarda de bloqueo ante averías abiertas o garantía de 48h.
 * 6. Gestiona diálogo de baja lógica bloqueando ante tickets abiertos con código visible.
 * 7. Gestiona diálogo de reactivación asistida con selector forzoso de nueva sede si la original fue dada de baja.
 * 
 * Dogma Vanilla: Node.js nativo con módulos ESM y cero dependencias externas.
 */

// Mock de entorno navegador para Node.js ESM
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
  location: { search: '', href: 'http://localhost/' }
};

import { api } from '../../public/assets/js/api.js';
import { AdminMachinesTab, MACHINE_TYPES } from '../../public/assets/js/components/AdminMachinesTab.js';

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) {
      console.error(`         Motivo: ${details}`);
    }
    failures++;
  }
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - AdminMachinesTab (T-ADM-14)');
console.log('======================================================================\n');

// Fixtures maestras para pruebas
const mockLocations = [
  { id: 1, site_code: 'SEDE-BCN-01', name: 'Hospital del Mar', is_active: true },
  { id: 2, site_code: 'SEDE-MAD-01', name: 'Campus Chamartín', is_active: true },
  { id: 3, site_code: 'SEDE-SEV-01', name: 'Centro Logístico Sur', is_active: false } // Inactiva
];

const mockMachines = [
  {
    id: 101,
    code: 'VEND-BCN-101',
    model: 'Necta Canto Touch',
    machine_type: 'HOT_DRINKS',
    is_perishable: false,
    location_id: 1,
    location_name: 'Hospital del Mar',
    location_site_code: 'SEDE-BCN-01',
    floor_wing: 'Planta Baja - Hall',
    notes: 'Junto a cafetería',
    is_active: true,
    has_active_ticket: false,
    is_in_warranty: false,
    active_ticket_code: null,
    active_ticket_status: null
  },
  {
    id: 102,
    code: 'VEND-BCN-102',
    model: 'FAS Fast 1050',
    machine_type: 'PERISHABLE_FOOD',
    is_perishable: true,
    location_id: 1,
    location_name: 'Hospital del Mar',
    location_site_code: 'SEDE-BCN-01',
    floor_wing: 'Planta 1 - Maternidad',
    notes: 'Requiere temperatura <= 4C',
    is_active: true,
    has_active_ticket: true,
    is_in_warranty: false,
    active_ticket_code: 'INC-2026-0088',
    active_ticket_status: 'IN_PROGRESS'
  },
  {
    id: 103,
    code: 'VEND-MAD-201',
    model: 'Sanden Vendo G-Drink',
    machine_type: 'COLD_DRINKS',
    is_perishable: false,
    location_id: 2,
    location_name: 'Campus Chamartín',
    location_site_code: 'SEDE-MAD-01',
    floor_wing: 'Planta 2 - Biblioteca',
    notes: '',
    is_active: true,
    has_active_ticket: false,
    is_in_warranty: true,
    active_ticket_code: 'INC-2026-0077',
    active_ticket_status: 'RESOLVED'
  },
  {
    id: 104,
    code: 'VEND-SEV-301',
    model: 'Saeco Cristallo 400',
    machine_type: 'HOT_DRINKS',
    is_perishable: false,
    location_id: 3, // Ubicada en sede inactiva
    location_name: 'Centro Logístico Sur',
    location_site_code: 'SEDE-SEV-01',
    floor_wing: 'Planta 0 - Acceso Camiones',
    notes: 'Retirada a almacén',
    is_active: false,
    has_active_ticket: false,
    is_in_warranty: false,
    active_ticket_code: null,
    active_ticket_status: null
  }
];

// Helper para crear instancia del componente con datos iniciales
function createComponentInstance(customData = {}) {
  const data = typeof AdminMachinesTab.data === 'function' ? AdminMachinesTab.data() : AdminMachinesTab.data;
  const instance = {
    ...data,
    ...customData
  };

  // Bind computeds con getters
  for (const [key, fn] of Object.entries(AdminMachinesTab.computed || {})) {
    Object.defineProperty(instance, key, {
      get() { return fn.call(instance); },
      configurable: true
    });
  }

  // Bind methods
  for (const [key, fn] of Object.entries(AdminMachinesTab.methods || {})) {
    instance[key] = fn.bind(instance);
  }

  return instance;
}

// --------------------------------------------------------------------
// BLOQUE 1: Estado Inicial, Tipologías y Validaciones Sanitarias
// --------------------------------------------------------------------
console.log('--- BLOQUE 1: Estado Inicial, Tipologías y Validaciones Sanitarias ---');

{
  const comp = createComponentInstance();

  assert('1.1 Inicializa con lista de máquinas vacía', Array.isArray(comp.machines) && comp.machines.length === 0);
  assert('1.2 Filtro inicial de estado es "all"', comp.filterStatus === 'all');
  assert('1.3 Filtro de sede inicial es "" (todas)', comp.filterLocation === '');
  assert('1.4 Filtro de tipología inicial es "" (todas)', comp.filterType === '');
  assert('1.5 Modales cerrados por defecto', !comp.showCreateModal && !comp.showEditModal && !comp.showTransferModal && !comp.showDeactivateModal && !comp.showReactivateModal);

  // Tipologías y reglas sanitarias (Constitución Art. II)
  assert('1.6 isPerishable identifica PERISHABLE_FOOD como perecedero', comp.isPerishable('PERISHABLE_FOOD') === true);
  assert('1.7 isPerishable identifica HOT_DRINKS como no perecedero', comp.isPerishable('HOT_DRINKS') === false);
  assert('1.8 isPerishable identifica COLD_DRINKS como no perecedero', comp.isPerishable('COLD_DRINKS') === false);
  assert('1.9 getTypeBadgeClass asigna badge rojo (bg-danger) a alimentos perecederos', comp.getTypeBadgeClass('PERISHABLE_FOOD') === 'bg-danger');

  // Validaciones de código de máquina
  assert('1.10 validateMachineCode rechaza código vacío', comp.validateMachineCode('') !== null);
  assert('1.11 validateMachineCode rechaza código menor a 3 caracteres', comp.validateMachineCode('AB') !== null);
  assert('1.12 validateMachineCode rechaza caracteres especiales prohibidos', comp.validateMachineCode('VEND#01!') !== null);
  assert('1.13 validateMachineCode acepta código alfanumérico válido con guiones', comp.validateMachineCode('VEND-VAL-301') === null);
}

// --------------------------------------------------------------------
// BLOQUE 2: Filtros Cruzados y Reactividad
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 2: Filtros Cruzados y Reactividad ---');

{
  const comp = createComponentInstance({
    machines: [...mockMachines],
    locations: [...mockLocations]
  });

  assert('2.1 activeLocations filtra correctamente solo sedes con is_active=true', comp.activeLocations.length === 2 && comp.activeLocations.every(l => l.is_active));

  // Filtro por estado
  comp.filterStatus = 'active';
  assert('2.2 filteredMachines filtra solo máquinas activas', comp.filteredMachines.length === 3 && comp.filteredMachines.every(m => m.is_active));

  comp.filterStatus = 'inactive';
  assert('2.3 filteredMachines filtra solo máquinas de baja', comp.filteredMachines.length === 1 && comp.filteredMachines[0].code === 'VEND-SEV-301');

  comp.filterStatus = 'all';

  // Filtro por sede
  comp.filterLocation = 1;
  assert('2.4 filteredMachines filtra máquinas por sede específica', comp.filteredMachines.length === 2 && comp.filteredMachines.every(m => m.location_id === 1));

  comp.filterLocation = '';

  // Filtro por tipología sanitaria
  comp.filterType = 'PERISHABLE_FOOD';
  assert('2.5 filteredMachines filtra máquinas por alimentos perecederos', comp.filteredMachines.length === 1 && comp.filteredMachines[0].code === 'VEND-BCN-102');

  comp.filterType = '';

  // Filtro por búsqueda
  comp.searchQuery = 'Necta';
  assert('2.6 filteredMachines busca por modelo técnico', comp.filteredMachines.length === 1 && comp.filteredMachines[0].model.includes('Necta'));

  comp.searchQuery = 'Maternidad';
  assert('2.7 filteredMachines busca por planta/ala', comp.filteredMachines.length === 1 && comp.filteredMachines[0].code === 'VEND-BCN-102');

  comp.searchQuery = '';

  // Métricas de resumen de parque
  const metrics = comp.summaryMetrics;
  assert('2.8 summaryMetrics calcula total de máquinas correctamente', metrics.total === 4);
  assert('2.9 summaryMetrics calcula máquinas activas e inactivas', metrics.active === 3 && metrics.inactive === 1);
  assert('2.10 summaryMetrics calcula máquinas perecederas (Art. II)', metrics.perishable === 1);
  assert('2.11 summaryMetrics calcula máquinas con avería activa o en garantía', metrics.inTrouble === 2);
}

// --------------------------------------------------------------------
// BLOQUE 3: Modal de Alta de Máquina y Alerta Sanitaria
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 3: Modal de Alta de Máquina y Alerta Sanitaria ---');

{
  const comp = createComponentInstance({
    locations: [...mockLocations]
  });

  comp.openCreateModal();
  assert('3.1 openCreateModal abre el modal de alta', comp.showCreateModal === true);
  assert('3.2 openCreateModal asigna la primera sede activa por defecto', comp.createForm.location_id === 1);

  // Intento de envío con campos vacíos
  comp.createForm.code = '';
  comp.submitCreate();
  assert('3.3 submitCreate bloquea envío si faltan campos obligatorios', comp.createErrors.code && comp.createErrors.model && comp.createErrors.floor_wing);

  // Mock de api.admin.createMachine
  let capturedPayload = null;
  api.admin.createMachine = async (payload) => {
    capturedPayload = payload;
    return { success: true, data: { id: 105, ...payload, is_active: true } };
  };

  comp.createForm.code = 'vend-val-301';
  comp.createForm.location_id = 2;
  comp.createForm.model = 'FAS Fast 1050';
  comp.createForm.machine_type = 'PERISHABLE_FOOD';
  comp.createForm.floor_wing = 'Planta 1 - Sala Médicos';
  comp.createForm.notes = 'Protegida con disyuntor';

  await comp.submitCreate();
  assert('3.4 submitCreate envía payload normalizado en mayúsculas a api.admin.createMachine', capturedPayload !== null && capturedPayload.code === 'VEND-VAL-301');
  assert('3.5 submitCreate envía tipología perecedera y ubicación correctas', capturedPayload.machine_type === 'PERISHABLE_FOOD' && capturedPayload.location_id === 2);
  assert('3.6 Modal de alta se cierra tras creación exitosa', comp.showCreateModal === false);
}

// --------------------------------------------------------------------
// BLOQUE 4: Modal de Edición y Bloqueo de Tipología (EARS 2.4, 2.5)
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 4: Modal de Edición y Bloqueo de Tipología (EARS 2.4, 2.5) ---');

{
  const comp = createComponentInstance();

  // Máquina con avería activa (VEND-BCN-102)
  const troubleMachine = mockMachines.find(m => m.code === 'VEND-BCN-102');
  comp.openEditModal(troubleMachine);

  assert('4.1 openEditModal carga los datos de la máquina', comp.showEditModal === true && comp.editForm.code === 'VEND-BCN-102');
  assert('4.2 editForm detecta que tiene avería activa', comp.editForm.has_active_ticket === true);

  // Intento de modificar la tipología sanitaria teniendo avería activa
  comp.editForm.machine_type = 'HOT_DRINKS'; // Cambia de PERISHABLE_FOOD a HOT_DRINKS
  comp.submitEdit();

  assert('4.3 submitEdit bloquea cambio de tipología si tiene avería activa (EARS 2.5)', Boolean(comp.editErrors.machine_type));

  // Máquina sin averías (VEND-BCN-101)
  const cleanMachine = mockMachines.find(m => m.code === 'VEND-BCN-101');
  comp.openEditModal(cleanMachine);

  let capturedEditPayload = null;
  api.admin.updateMachine = async (id, payload) => {
    capturedEditPayload = { id, ...payload };
    return { success: true, data: { id, ...payload } };
  };

  comp.editForm.model = 'Necta Canto Touch V2';
  comp.editForm.floor_wing = 'Planta Baja - Cafetería Central';
  comp.editForm.notes = 'Actualizado por revisión periódica';

  await comp.submitEdit();
  assert('4.4 submitEdit permite actualizar máquina operativa sin averías', capturedEditPayload !== null && capturedEditPayload.id === 101);
  assert('4.5 submitEdit envía nuevo modelo y planta a la API', capturedEditPayload.model === 'Necta Canto Touch V2');
  assert('4.6 Modal de edición se cierra tras éxito', comp.showEditModal === false);
}

// --------------------------------------------------------------------
// BLOQUE 5: Modal de Traslado Físico (EARS 2.7, 2.8)
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 5: Modal de Traslado Físico (EARS 2.7, 2.8) ---');

{
  const comp = createComponentInstance({
    locations: [...mockLocations]
  });

  // Traslado de máquina con avería activa (VEND-BCN-102)
  const brokenMachine = mockMachines.find(m => m.code === 'VEND-BCN-102');
  comp.openTransferModal(brokenMachine);

  assert('5.1 openTransferModal abre el modal de traslado', comp.showTransferModal === true);
  assert('5.2 transferForm detecta avería activa de la máquina', comp.transferForm.has_active_ticket === true);
  assert('5.3 canConfirmTransfer es false ante máquina con avería activa (EARS 2.8)', comp.canConfirmTransfer === false);

  // Traslado de máquina en garantía de 48h (VEND-MAD-201)
  const warrantyMachine = mockMachines.find(m => m.code === 'VEND-MAD-201');
  comp.openTransferModal(warrantyMachine);
  assert('5.4 canConfirmTransfer es false ante máquina en garantía de 48h (EARS 2.8)', comp.canConfirmTransfer === false);

  // Traslado de máquina operativa limpia (VEND-BCN-101)
  const cleanMachine = mockMachines.find(m => m.code === 'VEND-BCN-101');
  comp.openTransferModal(cleanMachine);

  assert('5.5 availableTransferLocations excluye la sede actual y sedes inactivas', comp.availableTransferLocations.length === 1 && comp.availableTransferLocations[0].id === 2);
  assert('5.6 canConfirmTransfer es false mientras no se seleccione sede destino', comp.canConfirmTransfer === false);

  comp.transferForm.target_location_id = 2;
  comp.transferForm.floor_wing = 'Planta 1 - Pasillo Central';
  assert('5.7 canConfirmTransfer es true cuando tiene destino activo y planta', comp.canConfirmTransfer === true);

  let capturedTransferPayload = null;
  api.admin.transferMachine = async (id, payload) => {
    capturedTransferPayload = { id, ...payload };
    return { success: true, data: { id, location_id: payload.target_location_id } };
  };

  await comp.submitTransfer();
  assert('5.8 submitTransfer invoca api.admin.transferMachine con target_location_id y floor_wing', capturedTransferPayload !== null && capturedTransferPayload.id === 101 && capturedTransferPayload.target_location_id === 2);
  assert('5.9 Modal de traslado se cierra tras éxito', comp.showTransferModal === false);
}

// --------------------------------------------------------------------
// BLOQUE 6: Diálogo de Confirmación de Baja Lógica (EARS 2.10, 2.11)
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 6: Diálogo de Confirmación de Baja Lógica (EARS 2.10, 2.11) ---');

{
  const comp = createComponentInstance();

  // Baja de máquina con avería activa (VEND-BCN-102)
  const brokenMachine = mockMachines.find(m => m.code === 'VEND-BCN-102');
  comp.openDeactivateModal(brokenMachine);

  assert('6.1 openDeactivateModal abre el modal de baja', comp.showDeactivateModal === true);
  assert('6.2 deactivateWarning advierte del bloqueo por ticket activo', comp.deactivateWarning.includes('INC-2026-0088'));
  assert('6.3 canConfirmDeactivate es false ante ticket activo (EARS 2.10)', comp.canConfirmDeactivate === false);

  // Intento de confirmar baja bloqueada
  comp.confirmDeactivate();
  assert('6.4 confirmDeactivate rechaza la llamada si canConfirmDeactivate es false', comp.isSubmittingDeactivate === false);

  // Baja de máquina operativa limpia (VEND-BCN-101)
  const cleanMachine = mockMachines.find(m => m.code === 'VEND-BCN-101');
  comp.openDeactivateModal(cleanMachine);

  assert('6.5 canConfirmDeactivate es true para máquina operativa sin averías', comp.canConfirmDeactivate === true);
  assert('6.6 deactivateWarning está vacío para máquina limpia', comp.deactivateWarning === '');

  let deactivatedId = null;
  api.admin.deactivateMachine = async (id) => {
    deactivatedId = id;
    return { success: true, data: { id, is_active: false } };
  };

  await comp.confirmDeactivate();
  assert('6.7 confirmDeactivate invoca api.admin.deactivateMachine', deactivatedId === 101);
  assert('6.8 Modal de baja se cierra tras éxito', comp.showDeactivateModal === false);
}

// --------------------------------------------------------------------
// BLOQUE 7: Reactivación Asistida de Máquina (EARS 2.13)
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 7: Reactivación Asistida de Máquina (EARS 2.13) ---');

{
  const comp = createComponentInstance({
    locations: [...mockLocations]
  });

  // Caso A: Máquina inactiva cuya sede original sigue activa (simulado)
  const inactiveMachineActiveLoc = {
    ...mockMachines[3],
    location_id: 1, // Hospital del Mar (activa)
    location_name: 'Hospital del Mar'
  };

  comp.openReactivateModal(inactiveMachineActiveLoc);
  assert('7.1 openReactivateModal detecta sede original activa', comp.reactivateForm.is_original_location_active === true);
  assert('7.2 canConfirmReactivate permite reactivación directa en sede original activa', comp.canConfirmReactivate === true);

  // Caso B: Máquina inactiva cuya sede original está INACTIVA (VEND-SEV-301 en Sede 3 inactiva)
  const inactiveMachineDeadLoc = mockMachines.find(m => m.code === 'VEND-SEV-301');
  comp.openReactivateModal(inactiveMachineDeadLoc);

  assert('7.3 openReactivateModal detecta sede original inactiva (EARS 2.13)', comp.reactivateForm.is_original_location_active === false);
  
  // Limpia target_location_id y floor_wing para probar validación forzosa
  comp.reactivateForm.target_location_id = '';
  comp.reactivateForm.floor_wing = '';
  assert('7.4 canConfirmReactivate es false si falta sede receptora activa o planta', comp.canConfirmReactivate === false);

  // Asignación de nueva sede activa forzosa
  comp.reactivateForm.target_location_id = 2; // Campus Chamartín
  comp.reactivateForm.floor_wing = 'Planta Baja - Entrada Principal';
  assert('7.5 canConfirmReactivate es true con nueva sede activa y planta', comp.canConfirmReactivate === true);

  let capturedReactivatePayload = null;
  api.admin.reactivateMachine = async (id, payload) => {
    capturedReactivatePayload = { id, ...payload };
    return { success: true, data: { id, is_active: true, location_id: payload.target_location_id } };
  };

  await comp.submitReactivate();
  assert('7.6 submitReactivate envía target_location_id y floor_wing forzosos a api.admin.reactivateMachine', capturedReactivatePayload !== null && capturedReactivatePayload.target_location_id === 2);
  assert('7.7 Modal de reactivación se cierra tras éxito', comp.showReactivateModal === false);
}

// --------------------------------------------------------------------
// BLOQUE 8: Template HTML y Elementos Constitucionales
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 8: Template HTML y Elementos Constitucionales ---');

{
  const template = AdminMachinesTab.template;

  assert('8.1 Template contiene tabla semántica de máquinas', template.includes('<table') && template.includes('Código') && template.includes('Tipología Sanitaria'));
  assert('8.2 Template contiene insignia y distintivo de perecederos (Art. II)', template.includes('Art. II') && template.includes('SLA ≤ 4.0h'));
  assert('8.3 Template contiene barra de filtros cruzados (estado, sede, tipología, texto)', template.includes('filterStatus') && template.includes('filterLocation') && template.includes('filterType') && template.includes('searchQuery'));
  assert('8.4 Template contiene modal de alta con advertencia sanitaria dinámica', template.includes('Alta de Máquina') && template.includes('PERISHABLE_FOOD'));
  assert('8.5 Template contiene modal de edición con inmutabilidad de código', template.includes('Código de Máquina (Inmutable)'));
  assert('8.6 Template contiene modal de traslado con advertencia de bloqueo operativo', template.includes('Traslado Físico de Máquina') && template.includes('Traslado Bloqueado'));
  assert('8.7 Template contiene diálogo de confirmación de baja lógica', template.includes('Confirmar Baja Lógica de Máquina') && template.includes('canConfirmDeactivate'));
  assert('8.8 Template contiene diálogo de reactivación asistida con aviso de sede inactiva', template.includes('Reactivación Asistida') && template.includes('Sede Original Inactiva'));
  assert('8.9 Template contiene modal visor de código QR', template.includes('/api/qr/view/') && template.includes('/api/qr/download/'));
}

// --------------------------------------------------------------------
// RESUMEN FINAL
// --------------------------------------------------------------------
console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: ¡TODAS LAS PRUEBAS FRONTEND PASARON EXITOSAMENTE (${assertions} aserciones)!`);
  console.log(' CONDICIÓN T-ADM-14 CUMPLIDA.');
  process.exit(0);
} else {
  console.error(` RESULTADO: ${failures} PRUEBAS FALLARON de un total de ${assertions} aserciones.`);
  process.exit(1);
}
