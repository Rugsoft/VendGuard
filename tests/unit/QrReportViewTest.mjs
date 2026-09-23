/**
 * VendGuard - QrReportView Test Suite (QrReportViewTest.mjs)
 * 
 * Valida la Tarea T-QR-13 (RF-03, RF-04, RF-05 y Artículos II y V de la Constitución de VendGuard).
 * 
 * Valida la condición "Hecho cuando:":
 * 1. Al montar QrReportView con una máquina limpia se muestra la máquina bloqueada y el banner sanitario en perecederos.
 * 2. Ante avería activa muestra el panel público y formulario de comentario adicional.
 * 3. Tras reportar muestra la confirmación con el ticket (#TICK-XXXX o #INC-XXXX).
 * 4. Gestión de concurrencia y unificación amigable de reportes simultáneos (EARS 4.5).
 * 5. Pantalla de cortesía ante máquina no encontrada o inactiva (EARS 5.2).
 */

// Mock de localStorage y window para entorno Node.js ESM sin DOM
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
  location: {
    search: '?qr=VEND-0101',
    href: 'http://localhost/?qr=VEND-0101'
  }
};

import { api } from '../../public/assets/js/api.js';
import { QrReportView, INCIDENT_CATEGORIES } from '../../public/assets/js/views/QrReportView.js';
import { App } from '../../public/assets/js/app.js';

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
console.log(' VendGuard: Frontend Test Suite - QrReportView (T-QR-13)');
console.log('======================================================================\n');

// Mock data fixtures
const cleanPerishableMachine = {
  id: 1,
  code: 'VEND-0101',
  model: 'Sanden Vendo G-Drink',
  machine_type: 'PERISHABLE_FOOD',
  floor_wing: 'Planta Baja - Urgencias',
  is_perishable: true,
  notes: 'Máquina de sándwiches y lácteos frescos'
};

const cleanHotDrinksMachine = {
  id: 2,
  code: 'VEND-0102',
  model: 'Bianchi Gaia Espresso',
  machine_type: 'HOT_DRINKS',
  floor_wing: 'Planta 1 - Sala Médica',
  is_perishable: false,
  notes: 'Café en grano y bebidas calientes'
};

const mockLocation = {
  id: 1,
  site_code: 'SEDE-BCN-01',
  name: 'Hospital del Mar - Edificio Central',
  contact_phone: '600111222'
};

const activeIncidentData = {
  ticket_code: 'INC-2026-0042',
  category: 'TEMPERATURE_COLD',
  public_status: 'IN_PROGRESS',
  status_label: 'Técnico interviniendo en la máquina',
  reported_at: '2026-09-23 08:15:00'
};

async function runTests() {
  // =====================================================================
  // CASO 1: Montaje con Máquina Limpia de Alimentos Perecederos
  // =====================================================================
  console.log('--- Caso 1: Máquina Limpia Perecedera (Banner Sanitario y Bloqueo) ---');

  api.qr.scan = async (code) => {
    return {
      success: true,
      data: {
        status_mode: 'CAN_REPORT',
        machine: cleanPerishableMachine,
        location: mockLocation,
        active_incident: null
      }
    };
  };

  const instance1 = {
    ...QrReportView.data(),
    code: 'VEND-0101',
    site: 'SEDE-BCN-01',
    $emit: () => {}
  };
  Object.assign(instance1, QrReportView.methods);

  // Vincular computeds
  for (const [key, getter] of Object.entries(QrReportView.computed)) {
    Object.defineProperty(instance1, key, { get: getter });
  }

  await instance1.resolveMachine();

  assert('1.1 Estado resuelto como CAN_REPORT', instance1.statusMode === 'CAN_REPORT');
  assert('1.2 Máquina resuelta es VEND-0101', instance1.machine?.code === 'VEND-0101');
  assert('1.3 Máquina es perecedera (isPerishable === true)', instance1.isPerishable === true);
  assert('1.4 Sede resuelta correctamente', instance1.location?.site_code === 'SEDE-BCN-01');
  assert('1.5 Categoría inicializada automáticamente a TEMPERATURE_COLD en perecederos', instance1.category === 'TEMPERATURE_COLD');
  assert('1.6 activeIncident es nulo en máquina limpia', instance1.activeIncident === null);
  assert('1.7 submitted es false inicialmente', instance1.submitted === false);

  // =====================================================================
  // CASO 2: Envío de Reporte y Pantalla de Confirmación con Ticket
  // =====================================================================
  console.log('\n--- Caso 2: Envío de Reporte y Confirmación de Ticket ---');

  instance1.description = 'Los yogures están calientes y la máquina marca 14 grados.';
  instance1.reporterPhone = '655443322';
  instance1.retainedMoney = '2.50';

  assert('2.1 Descripción válida (>= 5 caracteres)', instance1.isDescriptionValid === true);

  // Mock de api.qr.report
  api.qr.report = async (payload) => {
    return {
      success: true,
      data: {
        ticket_code: 'INC-2026-0099',
        status: 'REGISTERED',
        urgency: 'CRITICAL',
        merged: false
      },
      message: 'Incidencia registrada con éxito.'
    };
  };

  await instance1.submitReport();

  assert('2.2 submitted es true tras enviar reporte', instance1.submitted === true);
  assert('2.3 Ticket generado registrado en submittedTicket', instance1.submittedTicket?.ticket_code === 'INC-2026-0099');
  assert('2.4 formattedTicketCode añade prefijo # (#INC-2026-0099)', instance1.formattedTicketCode === '#INC-2026-0099');
  assert('2.5 Estado del ticket es REGISTERED', instance1.submittedTicket?.status === 'REGISTERED');
  assert('2.6 merged es false en ticket nuevo', instance1.submittedTicket?.merged === false);

  // =====================================================================
  // CASO 3: Máquina con Avería Activa Preexistente (EARS 4.1, 4.2 / Art. V.4)
  // =====================================================================
  console.log('\n--- Caso 3: Máquina con Avería Activa y Blindaje de Privacidad ---');

  api.qr.scan = async (code) => {
    return {
      success: true,
      data: {
        status_mode: 'ACTIVE_INCIDENT',
        machine: cleanHotDrinksMachine,
        location: mockLocation,
        active_incident: activeIncidentData
      }
    };
  };

  const instance2 = {
    ...QrReportView.data(),
    code: 'VEND-0102',
    site: 'SEDE-BCN-01',
    $emit: () => {}
  };
  Object.assign(instance2, QrReportView.methods);
  for (const [key, getter] of Object.entries(QrReportView.computed)) {
    Object.defineProperty(instance2, key, { get: getter });
  }

  await instance2.resolveMachine();

  assert('3.1 statusMode es ACTIVE_INCIDENT', instance2.statusMode === 'ACTIVE_INCIDENT');
  assert('3.2 activeIncident está presente con ticket_code', instance2.activeIncident?.ticket_code === 'INC-2026-0042');
  assert('3.3 activeIncident contiene status_label público', instance2.activeIncident?.status_label === 'Técnico interviniendo en la máquina');
  assert('3.4 Art. V.4: NO expone assigned_technician ni datos internos', instance2.activeIncident?.assigned_technician === undefined);
  assert('3.5 isPerishable es false para HOT_DRINKS', instance2.isPerishable === false);

  // Aportar comentario adicional a avería activa (EARS 4.2)
  instance2.showCommentForm = true;
  instance2.commentText = 'Añado que también sale un poco de agua por debajo.';
  instance2.commentReporterName = 'Dra. Carmen';

  api.qr.report = async (payload) => {
    return {
      success: true,
      data: {
        ticket_code: 'INC-2026-0042',
        merged: true
      },
      message: 'Observaciones añadidas al ticket existente.'
    };
  };

  await instance2.submitAdditionalComment();

  assert('3.6 commentSubmitted es true tras enviar observación adicional', instance2.commentSubmitted === true);
  assert('3.7 submittedTicket unificado con el mismo ticket', instance2.submittedTicket?.ticket_code === 'INC-2026-0042');

  // =====================================================================
  // CASO 4: Manejo de Concurrencia y Envíos Casi Simultáneos (EARS 4.5)
  // =====================================================================
  console.log('\n--- Caso 4: Manejo Amigable de Concurrencia (EARS 4.5) ---');

  const instance3 = {
    ...QrReportView.data(),
    code: 'VEND-0101',
    site: 'SEDE-BCN-01',
    description: 'La máquina se ha tragado 2 euros y no da producto.',
    machine: cleanPerishableMachine,
    $emit: () => {}
  };
  Object.assign(instance3, QrReportView.methods);
  for (const [key, getter] of Object.entries(QrReportView.computed)) {
    Object.defineProperty(instance3, key, { get: getter });
  }

  // Simulamos que al enviar el formulario, otro usuario ya creó el ticket 1 segundo antes
  api.qr.report = async () => {
    return {
      success: true,
      data: {
        ticket_code: 'INC-2026-0088',
        status: 'REGISTERED',
        merged: true
      },
      message: 'Otro usuario acaba de reportar una avería en esta máquina hace un momento (Ticket INC-2026-0088). Hemos registrado tus observaciones en dicho ticket.'
    };
  };

  await instance3.submitReport();

  assert('4.1 submitted es true tras fusión concurrente', instance3.submitted === true);
  assert('4.2 submittedTicket.merged es true', instance3.submittedTicket?.merged === true);
  assert('4.3 Mensaje amigable de fusión en submittedTicket', instance3.submittedTicket?.message.includes('Otro usuario'));
  assert('4.4 formattedTicketCode contiene el ticket unificado', instance3.formattedTicketCode === '#INC-2026-0088');

  // =====================================================================
  // CASO 5: Máquina No Encontrada o Inactiva (EARS 5.2)
  // =====================================================================
  console.log('\n--- Caso 5: Máquina No Encontrada o Inactiva (EARS 5.2) ---');

  api.qr.scan = async () => {
    throw new Error('Máquina no identificada o temporalmente fuera de servicio. Si necesitas asistencia, contacta con el servicio técnico.');
  };

  const instance4 = {
    ...QrReportView.data(),
    code: 'VEND-9999',
    $emit: () => {}
  };
  Object.assign(instance4, QrReportView.methods);
  for (const [key, getter] of Object.entries(QrReportView.computed)) {
    Object.defineProperty(instance4, key, { get: getter });
  }

  await instance4.resolveMachine();

  assert('5.1 statusMode es NOT_FOUND', instance4.statusMode === 'NOT_FOUND');
  assert('5.2 loadError contiene mensaje de cortesía', instance4.loadError.includes('Máquina no identificada o temporalmente fuera de servicio'));
  assert('5.3 loading es false', instance4.loading === false);

  // =====================================================================
  // CASO 6: Detección de ?qr=... en app.js y Enrutamiento Directo (T-QR-15)
  // =====================================================================
  console.log('\n--- Caso 6: Detección de ?qr=... en app.js y Enrutamiento Directo (T-QR-15) ---');

  // 6.1 Detección de ?qr=VEND-0101
  globalThis.window.location.search = '?qr=VEND-0101';
  const appInstance1 = {
    ...App.data()
  };
  App.created.call(appInstance1);

  assert('6.1 Cargar /?qr=VEND-0101 activa currentView === "qr"', appInstance1.currentView === 'qr');
  assert('6.2 qrMachineCode se asigna a "VEND-0101"', appInstance1.qrMachineCode === 'VEND-0101');
  assert('6.3 qrSiteCode es cadena vacía si no se especifica', appInstance1.qrSiteCode === '');

  // 6.2 Detección de ?qr=VEND-0102 con site=SEDE-BCN-01
  globalThis.window.location.search = '?qr=VEND-0102&site=SEDE-BCN-01';
  const appInstance2 = {
    ...App.data()
  };
  App.created.call(appInstance2);

  assert('6.4 ?qr=VEND-0102&site=SEDE-BCN-01 extrae máquina y sede', 
    appInstance2.currentView === 'qr' && 
    appInstance2.qrMachineCode === 'VEND-0102' && 
    appInstance2.qrSiteCode === 'SEDE-BCN-01');

  // 6.3 Compatibilidad con parámetro ?code=VEND-0103
  globalThis.window.location.search = '?code=VEND-0103';
  const appInstance3 = {
    ...App.data()
  };
  App.created.call(appInstance3);

  assert('6.5 Soporte de compatibilidad con ?code=VEND-0103', 
    appInstance3.currentView === 'qr' && appInstance3.qrMachineCode === 'VEND-0103');

  // 6.4 Verificación de plantilla de App: Ocultación de barras administrativas
  assert('6.6 App registra QrReportView en components', App.components.QrReportView === QrReportView);
  assert('6.7 AppNavbar está condicionado a v-if="currentView !== \'qr\'"', 
    App.template.includes('<AppNavbar v-if="currentView !== \'qr\'"'));
  assert('6.8 Profile Selector Bar está condicionado a v-if="currentView !== \'qr\'"', 
    App.template.includes('v-if="currentView !== \'qr\'"') && App.template.includes('Quick Testing Bar'));
  assert('6.9 Footer está condicionado a v-if="currentView !== \'qr\'"', 
    App.template.includes('<footer v-if="currentView !== \'qr\'"'));
  assert('6.10 QrReportView se renderiza condicionalmente con :code y :site', 
    App.template.includes('<QrReportView') && 
    App.template.includes('v-if="currentView === \'qr\'"') &&
    App.template.includes(':code="qrMachineCode"'));

  // =====================================================================
  // Resumen Final
  // =====================================================================
  console.log('\n======================================================================');
  if (failures === 0) {
    console.log(` RESULTADO: ¡TODAS LAS PRUEBAS PASARON (${assertions} aserciones, 0 fallos)!`);
  } else {
    console.error(` RESULTADO: ${failures} PRUEBA(S) FALLIDA(S) de ${assertions} evaluadas.`);
  }
  console.log('======================================================================\n');

  if (failures > 0) {
    process.exit(1);
  }
}

runTests().catch(err => {
  console.error('Error fatal en ejecución de tests:', err);
  process.exit(1);
});
