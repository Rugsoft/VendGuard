/**
 * VendGuard - AdminUsersTab Test Suite (AdminUsersTabTest.mjs)
 * 
 * Valida la funcionalidad reactiva y contratos del componente AdminUsersTab (RF-03, RNF-05).
 * 
 * Hecho cuando:
 * 1. Renderiza la plantilla técnica y de coordinación mostrando roles y recuento de averías activas asignadas.
 * 2. Gestiona modal de alta con validación de contraseña (mínimo 8 caracteres) y correo.
 * 3. Gestiona modal de edición preservando email y rol inmutables.
 * 4. Gestiona modal de reseteo administrativo de contraseña invalidando credenciales previas de forma segura.
 * 5. Gestiona diálogo de baja lógica con triple bloqueo estricto (auto-desactivación, guardia mínima y averías pendientes).
 * 6. Permite reactivar usuarios inactivos fluidamente.
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
import { store } from '../../public/assets/js/store.js';
import { AdminUsersTab, USER_ROLES } from '../../public/assets/js/components/AdminUsersTab.js';

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
console.log(' VendGuard: Frontend Test Suite - AdminUsersTab (T-ADM-15)');
console.log('======================================================================\n');

// Fixtures maestras para pruebas
const mockUsers = [
  {
    id: 1,
    name: 'Elena Coordinadora',
    email: 'elena.coord@vendguard.internal',
    role: 'COORDINATOR',
    role_label: 'Coordinador de Servicios',
    phone: '688001122',
    is_active: true,
    active_assigned_incidents_count: 0,
    created_at: '2026-09-01 08:00:00'
  },
  {
    id: 2,
    name: 'Carlos Rodríguez',
    email: 'carlos.tecnico@vendguard.internal',
    role: 'TECHNICIAN',
    role_label: 'Técnico de Ruta',
    phone: '677998811',
    is_active: true,
    active_assigned_incidents_count: 2, // Con averías activas asignadas
    created_at: '2026-09-01 08:00:00'
  },
  {
    id: 3,
    name: 'Lucas Martínez',
    email: 'lucas.tecnico@vendguard.internal',
    role: 'TECHNICIAN',
    role_label: 'Técnico de Ruta',
    phone: '655443322',
    is_active: true,
    active_assigned_incidents_count: 0, // Sin averías asignadas
    created_at: '2026-09-05 10:00:00'
  },
  {
    id: 4,
    name: 'Tomás Baja',
    email: 'tomas.baja@vendguard.internal',
    role: 'TECHNICIAN',
    role_label: 'Técnico de Ruta',
    phone: '611223344',
    is_active: false, // Dado de baja previa
    active_assigned_incidents_count: 0,
    created_at: '2026-08-10 09:00:00'
  }
];

// Asignar coordinador Elena como usuario en sesión
store.setInternalSession({
  id: 1,
  name: 'Elena Coordinadora',
  email: 'elena.coord@vendguard.internal',
  role: 'COORDINATOR'
}, 'auth_token_coordinator_mock');

// Helper para crear instancia del componente
function createComponentInstance(customData = {}) {
  const data = typeof AdminUsersTab.data === 'function' ? AdminUsersTab.data() : AdminUsersTab.data;
  const instance = {
    ...data,
    ...customData
  };

  for (const [key, fn] of Object.entries(AdminUsersTab.computed || {})) {
    Object.defineProperty(instance, key, {
      get() { return fn.call(instance); },
      configurable: true
    });
  }

  for (const [key, fn] of Object.entries(AdminUsersTab.methods || {})) {
    instance[key] = fn.bind(instance);
  }

  return instance;
}

// --------------------------------------------------------------------
// BLOQUE 1: Estado Inicial y Validaciones de Entrada
// --------------------------------------------------------------------
console.log('--- BLOQUE 1: Estado Inicial y Validaciones de Entrada ---');

{
  const comp = createComponentInstance();

  assert('1.1 Inicializa con lista de usuarios vacía', Array.isArray(comp.users) && comp.users.length === 0);
  assert('1.2 Filtro inicial de estado es "all"', comp.filterStatus === 'all');
  assert('1.3 Filtro de rol inicial es "all"', comp.filterRole === 'all');
  assert('1.4 Modales cerrados por defecto', !comp.showCreateModal && !comp.showEditModal && !comp.showResetPasswordModal && !comp.showDeactivateModal);

  // Validaciones de correo electrónico
  assert('1.5 validateEmail rechaza email vacío', comp.validateEmail('') !== null);
  assert('1.6 validateEmail rechaza email sin @ o sin dominio', comp.validateEmail('carlos.internal') !== null);
  assert('1.7 validateEmail acepta dirección de correo corporativa válida', comp.validateEmail('carlos.tec@vendguard.internal') === null);

  // Validaciones de teléfono
  assert('1.8 validatePhone acepta null o vacío (campo opcional)', comp.validatePhone('') === null);
  assert('1.9 validatePhone rechaza teléfono con menos de 9 dígitos', comp.validatePhone('6779988') !== null);
  assert('1.10 validatePhone rechaza teléfono que no comience por 6, 7, 8 o 9', comp.validatePhone('123456789') !== null);
  assert('1.11 validatePhone acepta teléfono español válido de 9 dígitos', comp.validatePhone('677998811') === null);

  // Validaciones de contraseña
  assert('1.12 validatePassword rechaza contraseña menor a 8 caracteres', comp.validatePassword('1234567') !== null);
  assert('1.13 validatePassword acepta contraseña de 8 o más caracteres', comp.validatePassword('PasswordSegura2026!') === null);

  // Roles y etiquetas
  assert('1.14 getRoleLabel retorna Técnico de Ruta para TECHNICIAN', comp.getRoleLabel('TECHNICIAN') === 'Técnico de Ruta');
  assert('1.15 getRoleLabel retorna Coordinador de Servicios para COORDINATOR', comp.getRoleLabel('COORDINATOR') === 'Coordinador de Servicios');
  assert('1.16 getRoleBadgeClass asigna bg-info para TECHNICIAN y bg-primary para COORDINATOR', comp.getRoleBadgeClass('TECHNICIAN') === 'bg-info text-dark' && comp.getRoleBadgeClass('COORDINATOR') === 'bg-primary');
}

// --------------------------------------------------------------------
// BLOQUE 2: Carga de Datos y Filtros Reactivos
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 2: Carga de Datos y Filtros Reactivos ---');

{
  const comp = createComponentInstance({
    users: [...mockUsers]
  });

  // Filtro por estado
  comp.filterStatus = 'active';
  assert('2.1 filteredUsers filtra solo usuarios activos', comp.filteredUsers.length === 3 && comp.filteredUsers.every(u => u.is_active));

  comp.filterStatus = 'inactive';
  assert('2.2 filteredUsers filtra solo usuarios de baja', comp.filteredUsers.length === 1 && comp.filteredUsers[0].name === 'Tomás Baja');

  comp.filterStatus = 'all';

  // Filtro por rol
  comp.filterRole = 'TECHNICIAN';
  assert('2.3 filteredUsers filtra solo técnicos de ruta', comp.filteredUsers.length === 3 && comp.filteredUsers.every(u => u.role === 'TECHNICIAN'));

  comp.filterRole = 'COORDINATOR';
  assert('2.4 filteredUsers filtra solo coordinadores', comp.filteredUsers.length === 1 && comp.filteredUsers[0].role === 'COORDINATOR');

  comp.filterRole = 'all';

  // Filtro por texto de búsqueda
  comp.searchQuery = 'Carlos';
  assert('2.5 filteredUsers busca por nombre', comp.filteredUsers.length === 1 && comp.filteredUsers[0].name.includes('Carlos'));

  comp.searchQuery = 'elena.coord';
  assert('2.6 filteredUsers busca por correo', comp.filteredUsers.length === 1 && comp.filteredUsers[0].email.includes('elena.coord'));

  comp.searchQuery = '655443322';
  assert('2.7 filteredUsers busca por teléfono', comp.filteredUsers.length === 1 && comp.filteredUsers[0].name === 'Lucas Martínez');

  comp.searchQuery = '';

  // Conteos y métricas de resumen
  assert('2.8 activeTechniciansCount contabiliza técnicos activos', comp.activeTechniciansCount === 2);
  assert('2.9 activeCoordinatorsCount contabiliza coordinadores activos', comp.activeCoordinatorsCount === 1);

  const metrics = comp.summaryMetrics;
  assert('2.10 summaryMetrics calcula total de plantilla', metrics.total === 4);
  assert('2.11 summaryMetrics calcula activos e inactivos', metrics.active === 3 && metrics.inactive === 1);
  assert('2.12 summaryMetrics calcula técnicos y coordinadores', metrics.technicians === 3 && metrics.coordinators === 1);
  assert('2.13 summaryMetrics calcula recuento total de averías asignadas', metrics.activeAssignedTroubles === 2);
}

// --------------------------------------------------------------------
// BLOQUE 3: Modal de Alta de Nuevo Personal
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 3: Modal de Alta de Nuevo Personal ---');

{
  const comp = createComponentInstance();

  comp.openCreateModal();
  assert('3.1 openCreateModal abre el modal de alta', comp.showCreateModal === true);
  assert('3.2 openCreateModal inicializa con rol TECHNICIAN por defecto', comp.createForm.role === 'TECHNICIAN');

  // Intento de envío con campos vacíos
  comp.submitCreate();
  assert('3.3 submitCreate bloquea envío si faltan nombre, email o clave', comp.createErrors.name && comp.createErrors.email && comp.createErrors.password);

  let capturedCreatePayload = null;
  api.admin.createUser = async (payload) => {
    capturedCreatePayload = payload;
    return { success: true, data: { id: 5, ...payload, is_active: true } };
  };

  comp.createForm.name = 'Santiago Gómez';
  comp.createForm.email = 'SANTIAGO.TEC@vendguard.internal';
  comp.createForm.role = 'TECHNICIAN';
  comp.createForm.phone = '655223344';
  comp.createForm.password = 'PasswordSegura2026!';

  await comp.submitCreate();
  assert('3.4 submitCreate envía payload normalizado en minúsculas a api.admin.createUser', capturedCreatePayload !== null && capturedCreatePayload.email === 'santiago.tec@vendguard.internal');
  assert('3.5 submitCreate envía contraseña segura y teléfono', capturedCreatePayload.password === 'PasswordSegura2026!' && capturedCreatePayload.phone === '655223344');
  assert('3.6 Modal de alta se cierra tras éxito', comp.showCreateModal === false);
}

// --------------------------------------------------------------------
// BLOQUE 4: Modal de Edición de Contacto (Inmutabilidad de Rol e Email)
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 4: Modal de Edición de Contacto (Inmutabilidad de Rol e Email) ---');

{
  const comp = createComponentInstance();

  const userToEdit = mockUsers.find(u => u.name === 'Carlos Rodríguez');
  comp.openEditModal(userToEdit);

  assert('4.1 openEditModal abre el modal de edición', comp.showEditModal === true);
  assert('4.2 editForm contiene los datos de contacto del usuario', comp.editForm.name === 'Carlos Rodríguez' && comp.editForm.email === 'carlos.tecnico@vendguard.internal');

  // Intento de dejar nombre en blanco
  comp.editForm.name = '';
  comp.submitEdit();
  assert('4.3 submitEdit bloquea si el nombre queda vacío', Boolean(comp.editErrors.name));

  let capturedEditPayload = null;
  api.admin.updateUser = async (id, payload) => {
    capturedEditPayload = { id, ...payload };
    return { success: true, data: { id, ...payload } };
  };

  comp.editForm.name = 'Carlos Rodríguez Morales';
  comp.editForm.phone = '677998800';

  await comp.submitEdit();
  assert('4.4 submitEdit envía actualización de nombre y teléfono a api.admin.updateUser', capturedEditPayload !== null && capturedEditPayload.id === 2 && capturedEditPayload.phone === '677998800');
  assert('4.5 Modal de edición se cierra tras éxito', comp.showEditModal === false);
}

// --------------------------------------------------------------------
// BLOQUE 5: Modal de Reseteo Administrativo de Contraseña (EARS 3.4)
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 5: Modal de Reseteo Administrativo de Contraseña (EARS 3.4) ---');

{
  const comp = createComponentInstance();

  const user = mockUsers.find(u => u.name === 'Carlos Rodríguez');
  comp.openResetPasswordModal(user);

  assert('5.1 openResetPasswordModal abre el modal de reseteo', comp.showResetPasswordModal === true);
  assert('5.2 resetPasswordForm contiene id y nombre de usuario', comp.resetPasswordForm.id === 2 && comp.resetPasswordForm.name === 'Carlos Rodríguez');

  // Contraseña demasiado corta
  comp.resetPasswordForm.new_password = 'corta';
  comp.submitResetPassword();
  assert('5.3 submitResetPassword bloquea si la contraseña tiene menos de 8 caracteres', Boolean(comp.resetPasswordErrors.new_password));

  let capturedReset = null;
  api.admin.resetUserPassword = async (id, newPassword) => {
    capturedReset = { id, newPassword };
    return { success: true, message: 'Contraseña restablecida exitosamente.' };
  };

  comp.resetPasswordForm.new_password = 'NuevaClaveValida2026!';
  await comp.submitResetPassword();

  assert('5.4 submitResetPassword invoca api.admin.resetUserPassword', capturedReset !== null && capturedReset.id === 2 && capturedReset.newPassword === 'NuevaClaveValida2026!');
  assert('5.5 Modal de reseteo se cierra tras éxito', comp.showResetPasswordModal === false);
}

// --------------------------------------------------------------------
// BLOQUE 6: Diálogo de Baja Lógica y Bloqueos (EARS 3.5, 3.6, 3.7)
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 6: Diálogo de Baja Lógica y Bloqueos (EARS 3.5, 3.6, 3.7) ---');

{
  const comp = createComponentInstance({
    users: [...mockUsers]
  });

  // 1. Caso Auto-desactivación (Elena en sesión intentando darse de baja a sí misma)
  const selfUser = mockUsers.find(u => u.id === 1);
  comp.openDeactivateModal(selfUser);

  assert('6.1 openDeactivateModal abre diálogo de baja', comp.showDeactivateModal === true);
  assert('6.2 canConfirmDeactivate es false ante intento de auto-desactivación (EARS 3.5)', comp.canConfirmDeactivate === false);
  assert('6.3 deactivateWarning advierte del bloqueo de auto-desactivación', comp.deactivateWarning.includes('No puede desactivar su propio usuario'));

  // 2. Caso Bloqueo por Averías Asignadas Pendientes (Carlos con 2 averías)
  const busyTechnician = mockUsers.find(u => u.name === 'Carlos Rodríguez');
  comp.openDeactivateModal(busyTechnician);

  assert('6.4 canConfirmDeactivate es false ante técnico con averías pendientes (EARS 3.7)', comp.canConfirmDeactivate === false);
  assert('6.5 deactivateWarning advierte del número exacto de averías pendientes', comp.deactivateWarning.includes('2 avería(s) activa(s) asignada(s)'));

  // Intento de forzar confirmación bloqueada
  comp.confirmDeactivate();
  assert('6.6 confirmDeactivate rechaza la llamada cuando canConfirmDeactivate es false', comp.isSubmittingDeactivate === false);

  // 3. Caso Baja Permitida (Lucas, técnico sin averías pendientes y habiendo otro técnico activo)
  const freeTechnician = mockUsers.find(u => u.name === 'Lucas Martínez');
  comp.openDeactivateModal(freeTechnician);

  assert('6.7 canConfirmDeactivate es true para técnico sin averías y con guardia mínima cubierta', comp.canConfirmDeactivate === true);
  assert('6.8 deactivateWarning está vacío cuando la baja está permitida', comp.deactivateWarning === '');

  let deactivatedUserId = null;
  api.admin.deactivateUser = async (id) => {
    deactivatedUserId = id;
    return { success: true, data: { id, is_active: false } };
  };

  await comp.confirmDeactivate();
  assert('6.9 confirmDeactivate invoca api.admin.deactivateUser para usuario libre', deactivatedUserId === 3);
  assert('6.10 Modal de baja se cierra tras éxito', comp.showDeactivateModal === false);
}

// --------------------------------------------------------------------
// BLOQUE 7: Reactivación y Template HTML
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 7: Reactivación y Template HTML ---');

{
  const comp = createComponentInstance({
    users: [...mockUsers]
  });

  const inactiveUser = mockUsers.find(u => u.name === 'Tomás Baja');

  let reactivatedUserId = null;
  api.admin.reactivateUser = async (id) => {
    reactivatedUserId = id;
    return { success: true, data: { id, is_active: true } };
  };

  await comp.reactivateUser(inactiveUser);
  assert('7.1 reactivateUser invoca api.admin.reactivateUser para usuario inactivo (EARS 3.10)', reactivatedUserId === 4);

  // Verificación de elementos del template
  const template = AdminUsersTab.template;
  assert('7.2 Template contiene tabla semántica con columnas de rol y averías', template.includes('<table') && template.includes('Carga de Averías') && template.includes('Rol'));
  assert('7.3 Template contiene barra de filtros (estado, rol, texto)', template.includes('filterStatus') && template.includes('filterRole') && template.includes('searchQuery'));
  assert('7.4 Template contiene modal de alta con campo de contraseña', template.includes('Alta de Personal Interno') && template.includes('type="password"'));
  assert('7.5 Template contiene modal de edición con inmutabilidad de email y rol', template.includes('Correo Electrónico (Inmutable)') && template.includes('Rol Operativo (Inmutable)'));
  assert('7.6 Template contiene modal de reseteo de contraseña', template.includes('Restablecer Contraseña') && template.includes('resetPasswordForm'));
  assert('7.7 Template contiene modal de confirmación de baja con advertencia interactiva', template.includes('Confirmar Baja Lógica de Personal') && template.includes('canConfirmDeactivate'));
}

// --------------------------------------------------------------------
// RESUMEN FINAL
// --------------------------------------------------------------------
console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: ¡TODAS LAS PRUEBAS FRONTEND PASARON EXITOSAMENTE (${assertions} aserciones)!`);
  console.log(' CONDICIÓN T-ADM-15 CUMPLIDA.');
  process.exit(0);
} else {
  console.error(` RESULTADO: ${failures} PRUEBAS FALLARON de un total de ${assertions} aserciones.`);
  process.exit(1);
}
