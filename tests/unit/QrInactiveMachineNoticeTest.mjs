/**
 * VendGuard - QrInactiveMachineNotice Test Suite (QrInactiveMachineNoticeTest.mjs)
 * 
 * Valida la funcionalidad reactiva y contratos de QrInactiveMachineNotice y QrReportView (RF-04, EARS 2.12).
 * 
 * Hecho cuando:
 * La lectura ciudadana de un código QR físico de una máquina retirada despliega la tarjeta informativa
 * visual amigable indicando "Máquina temporalmente retirada o fuera de servicio" y ocultando completamente
 * el formulario de reporte de averías.
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
import { QrInactiveMachineNotice } from '../../public/assets/js/components/QrInactiveMachineNotice.js';
import { QrReportView } from '../../public/assets/js/views/QrReportView.js';

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
console.log(' VendGuard: Frontend Test Suite - QrInactiveMachineNotice (T-ADM-16)');
console.log('======================================================================\n');

// --------------------------------------------------------------------
// BLOQUE 1: Componente QrInactiveMachineNotice - Props y Computadas
// --------------------------------------------------------------------
console.log('--- BLOQUE 1: Componente QrInactiveMachineNotice - Props y Computadas ---');

{
  // Instancia básica con defaults
  const noticeInstance1 = {
    machine: {},
    location: null,
    message: 'Esta máquina de vending se encuentra temporalmente retirada o fuera de servicio. No es posible registrar nuevas incidencias sobre este dispositivo.',
    supportPhone: '',
    $emit: () => {}
  };
  for (const [key, getter] of Object.entries(QrInactiveMachineNotice.computed)) {
    Object.defineProperty(noticeInstance1, key, { get: getter });
  }

  assert('1.1 locationName retorna cadena vacía si no hay localización', noticeInstance1.locationName === '');
  assert('1.2 effectivePhone retorna cadena vacía si no hay teléfono', noticeInstance1.effectivePhone === '');
  assert('1.3 displayFloorWing retorna cadena vacía si no hay planta/ala', noticeInstance1.displayFloorWing === '');

  // Instancia con datos de máquina y sede completos
  const noticeInstance2 = {
    machine: {
      code: 'VEND-BCN-101',
      model: 'Necta Canto Touch',
      machine_type_label: 'Bebidas calientes (Café e infusiones)',
      floor_wing: 'Planta Baja - Hall'
    },
    location: {
      name: 'Hospital del Mar',
      contact_phone: '900123456'
    },
    message: 'Máquina en mantenimiento preventivo.',
    supportPhone: '900999888',
    $emit: () => {}
  };
  for (const [key, getter] of Object.entries(QrInactiveMachineNotice.computed)) {
    Object.defineProperty(noticeInstance2, key, { get: getter });
  }

  assert('1.4 locationName extrae el nombre de la sede', noticeInstance2.locationName === 'Hospital del Mar');
  assert('1.5 effectivePhone prioriza supportPhone sobre location.contact_phone', noticeInstance2.effectivePhone === '900999888');
  assert('1.6 displayFloorWing formatea entre paréntesis la ubicación física', noticeInstance2.displayFloorWing === '(Planta Baja - Hall)');

  // Emisión de evento go-home
  let emittedEvent = null;
  noticeInstance2.$emit = (ev) => { emittedEvent = ev; };
  QrInactiveMachineNotice.methods.handleGoHome.call(noticeInstance2);
  assert('1.7 handleGoHome emite evento go-home', emittedEvent === 'go-home');
}

// --------------------------------------------------------------------
// BLOQUE 2: Plantilla HTML y Elementos de Bloqueo
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 2: Plantilla HTML y Elementos de Bloqueo ---');

{
  const tmpl = QrInactiveMachineNotice.template;

  assert('2.1 Template contiene contenedor con data-testid="inactive-machine-card"', tmpl.includes('data-testid="inactive-machine-card"'));
  assert('2.2 Template contiene título "Máquina Fuera de Servicio"', tmpl.includes('Máquina Fuera de Servicio'));
  assert('2.3 Template contiene chip de máquina con código y modelo', tmpl.includes('machine?.code') && tmpl.includes('machine?.model'));
  assert('2.4 Template contiene badge visual "Inactiva"', tmpl.includes('badge bg-secondary') && tmpl.includes('Inactiva'));
  assert('2.5 Template contiene aviso explícito de que el formulario está deshabilitado', tmpl.includes('El formulario de averías está deshabilitado para esta máquina'));
  assert('2.6 Template contiene enlace telefónico de asistencia técnica', tmpl.includes("tel:") && tmpl.includes('effectivePhone'));
  assert('2.7 Template contiene botón para volver a la portada con data-testid="btn-inactive-home"', tmpl.includes('data-testid="btn-inactive-home"') && tmpl.includes('handleGoHome'));
}

// --------------------------------------------------------------------
// BLOQUE 3: Integración en QrReportView ante Escaneo de Máquina Inactiva (RF-04)
// --------------------------------------------------------------------
console.log('\n--- BLOQUE 3: Integración en QrReportView ante Escaneo de Máquina Inactiva (RF-04) ---');

{
  // Simular respuesta del backend para máquina inactiva según contrato (T-ADM-11 / admin_crud_contracts.md 3.4.1)
  api.qr.scan = async (code) => {
    return {
      success: true,
      data: {
        code: code,
        status: 'INACTIVE',
        status_mode: 'INACTIVE',
        is_active: false,
        model: 'FAS Fast 1050',
        machine_type_label: 'Alimentos Perecederos',
        location_name: 'Campus Chamartín',
        floor_wing: 'Planta 1 - Comedor',
        message: 'Esta máquina de vending se encuentra temporalmente retirada o fuera de servicio. No es posible registrar nuevas incidencias sobre este dispositivo.',
        allow_reporting: false,
        support_phone: '900123456'
      }
    };
  };

  const reportViewInstance = {
    ...QrReportView.data(),
    code: 'VEND-INACTIVE-01',
    site: '',
    $emit: () => {}
  };
  Object.assign(reportViewInstance, QrReportView.methods);
  for (const [key, getter] of Object.entries(QrReportView.computed)) {
    Object.defineProperty(reportViewInstance, key, { get: getter });
  }

  await reportViewInstance.resolveMachine();

  assert('3.1 statusMode se establece en "INACTIVE"', reportViewInstance.statusMode === 'INACTIVE');
  assert('3.2 loading se completa y pasa a false', reportViewInstance.loading === false);
  assert('3.3 machine.is_active es false', reportViewInstance.machine?.is_active === false);
  assert('3.4 machine.code es VEND-INACTIVE-01', reportViewInstance.machine?.code === 'VEND-INACTIVE-01');
  assert('3.5 machine.model es FAS Fast 1050', reportViewInstance.machine?.model === 'FAS Fast 1050');
  assert('3.6 location.name es Campus Chamartín', reportViewInstance.location?.name === 'Campus Chamartín');
  assert('3.7 inactiveNoticeMessage contiene el mensaje oficial de advertencia de fuera de servicio', reportViewInstance.inactiveNoticeMessage.includes('retirada o fuera de servicio'));
  assert('3.8 activeIncident es null', reportViewInstance.activeIncident === null);

  // Verificación de la plantilla de QrReportView
  const viewTmpl = QrReportView.template;
  assert('3.9 QrReportView registra QrInactiveMachineNotice en components', QrReportView.components?.QrInactiveMachineNotice === QrInactiveMachineNotice);
  assert('3.10 QrReportView renderiza <QrInactiveMachineNotice v-else-if="statusMode === \'INACTIVE\'"', viewTmpl.includes('statusMode === \'INACTIVE\'') && viewTmpl.includes('<QrInactiveMachineNotice'));
  assert('3.11 El formulario normal de reporte de averías está condicionado a v-else (se oculta completamente en INACTIVE)', viewTmpl.includes('qr-flow-container'));
}

// --------------------------------------------------------------------
// RESUMEN FINAL
// --------------------------------------------------------------------
console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: ¡TODAS LAS PRUEBAS FRONTEND PASARON EXITOSAMENTE (${assertions} aserciones)!`);
  console.log(' CONDICIÓN T-ADM-16 CUMPLIDA.');
  process.exit(0);
} else {
  console.error(` RESULTADO: ${failures} PRUEBAS FALLARON de un total de ${assertions} aserciones.`);
  process.exit(1);
}
