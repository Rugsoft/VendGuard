/**
 * VendGuard - TroubleshootingGuideSurfacesGuardTest
 *
 * Guarda de las superficies del Módulo 28 que la auditoría dejó certificadas
 * sólo por inspección:
 *   6. `02.3` / `QA 4` El handler de la causa sugerida abre el catálogo completo
 *      cuando la causa es de otra categoría, preselecciona causa y solución y
 *      no escribe en diagnóstico ni acción (Art. V.1).
 *   7. `03.2` Opacidad total del módulo en Sede, QR y Albarán A4 (Art. V.4).
 *   8. `RNF-01` Renderizado por interpolación: sin `v-html`, `innerHTML` ni
 *      librerías de Markdown en los componentes del módulo.
 *
 * Dogma Vanilla: Node ESM puro, sin dependencias externas.
 * Dualismo Lingüístico: aserciones en castellano, código en inglés.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(here, '../..');

let assertions = 0;
let failures = 0;

function assert(title, condition, detail = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${title}`);
    return;
  }
  failures++;
  console.log(`  [FAIL] ${title}`);
  if (detail !== '') {
    console.log(`         Motivo: ${detail}`);
  }
}

const read = (relativePath) => fs.readFileSync(path.join(projectRoot, relativePath), 'utf8');

/** Extrae un método de un objeto de opciones Vue hasta su cierre a 4 espacios. */
function extractMethod(source, signature) {
  const start = source.indexOf(signature);
  if (start === -1) {
    return '';
  }
  const end = source.indexOf('\n    },', start);

  return source.slice(start, end === -1 ? undefined : end);
}

const coordinator = read('public/assets/js/components/CoordinatorIncidentDetailModal.js');
const technician = read('public/assets/js/views/TechnicianRouteView.js');
const accordion = read('public/assets/js/components/TroubleshootingGuideAccordion.js');
const adminTab = read('public/assets/js/components/CoordinatorTroubleshootingGuidesTab.js');

console.log('======================================================================');
console.log(' VendGuard: TroubleshootingGuideSurfacesGuardTest (Módulo 28)');
console.log('======================================================================');

// =====================================================================
console.log('\n--- 6. Aplicación de la causa sugerida entre categorías (02.3 / QA 4) ---');

const coordinatorHandler = extractMethod(coordinator, 'async applyGuideRootCause(payload = {})');
const technicianHandler = extractMethod(technician, 'async applyGuideRootCause(incident, payload = {})');

assert('6.1 El detalle de Coordinación declara el handler', coordinatorHandler !== '');
assert(
  '6.2 Coordinación abre el catálogo completo sólo al cambiar de categoría',
  coordinatorHandler.includes('showAllRootCauseCategories = true')
    && coordinatorHandler.includes('causeCategory !== incidentCategory')
);
assert(
  '6.3 Coordinación preselecciona la causa y su solución',
  coordinatorHandler.includes('this.selectedRootCauseId = rootCauseId')
    && coordinatorHandler.includes('this.selectedStandardSolutionId = preferred')
);
assert('6.4 La ruta del técnico declara el mismo handler', technicianHandler !== '');
assert(
  '6.5 El técnico abre el catálogo completo sólo al cambiar de categoría',
  technicianHandler.includes('showAllRootCauseCategories = true')
    && technicianHandler.includes('causeCategory !== incidentCategory')
);
assert(
  '6.6 El técnico preselecciona la causa y su solución',
  technicianHandler.includes('this.selectedRootCauseId = rootCauseId')
    && technicianHandler.includes('this.selectedStandardSolutionId = preferred')
);
assert(
  '6.7 Ninguno de los dos handlers escribe diagnóstico ni acción (Art. V.1)',
  !/\bthis\.(resolveDiagnosis|resolveAction|diagnosis|action_taken)\s*=/.test(coordinatorHandler)
    && !/\bthis\.(resolveDiagnosis|resolveAction|diagnosis|action_taken)\s*=/.test(technicianHandler)
);

// =====================================================================
console.log('\n--- 7. Opacidad en Sede, QR y Albarán A4 (03.2 / Art. V.4) ---');

const privateSurfaces = [
  ['7.1 El Portal de Sede no expone guías', 'public/assets/js/views/LocationPortalView.js'],
  ['7.2 El reporte QR no expone guías', 'public/assets/js/views/QrReportView.js'],
  ['7.3 La impresión QR no expone guías', 'public/assets/js/views/QrBatchPrintView.js'],
  ['7.4 El Albarán de Servicio A4 no expone guías', 'public/assets/js/components/ServiceSheetModal.js']
];

const forbiddenTokens = /troubleshooting|TROUBLESHOOTING|error_codes|suggested_root_cause|Guía Rápida de Modelo/;

for (const [title, relativePath] of privateSurfaces) {
  assert(title, !forbiddenTokens.test(read(relativePath)), relativePath);
}

// =====================================================================
console.log('\n--- 8. Renderizado seguro sin inyección (RNF-01) ---');

assert('8.1 El acordeón no usa el atributo `v-html`', !/\sv-html\s*=/.test(accordion));
assert('8.2 El acordeón no escribe HTML crudo', !/innerHTML/.test(accordion));
assert(
  '8.3 Ningún componente del módulo importa un procesador de Markdown',
  ![accordion, adminTab].some((source) => /\bfrom\s+['"](marked|markdown-it|showdown|remark)/.test(source))
);
assert('8.4 El panel de Coordinación tampoco usa `v-html`', !/\sv-html\s*=/.test(adminTab));
assert(
  '8.5 Las instrucciones se interpolan como texto',
  accordion.includes('{{ step.instruction }}')
);
assert(
  '8.6 Los códigos y significados se interpolan como texto',
  accordion.includes('{{ entry.code }}') && accordion.includes('{{ entry.meaning }}')
);

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. SUPERFICIES DEL MÓDULO 28 CERTIFICADAS.');
  console.log('======================================================================');
  process.exit(0);
}

console.log(` RESULTADO: FALLO EN ${failures} ASERCIONES.`);
console.log('======================================================================');
process.exit(1);
