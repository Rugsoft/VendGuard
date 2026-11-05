/**
 * VendGuard - TroubleshootingGuideEditorPartsTest
 *
 * Certifica los hallazgos H-28-03 del Módulo 28:
 *   - una edición que no toca repuestos omite el campo y conserva las
 *     asociaciones inactivas que el servidor mantiene por compatibilidad;
 *   - una edición que toca la lista envía sólo piezas activas;
 *   - la interfaz muestra las piezas inactivas y permite retirarlas de forma
 *     explícita.
 *
 * Dogma Vanilla: Node ESM puro, sin dependencias externas.
 * Dualismo Lingüístico: aserciones en castellano, código en inglés.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { api } from '../../public/assets/js/api.js';
import { CoordinatorTroubleshootingGuidesTab as Tab } from '../../public/assets/js/components/CoordinatorTroubleshootingGuidesTab.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(here, '../..');
const source = fs.readFileSync(
  path.join(projectRoot, 'public/assets/js/components/CoordinatorTroubleshootingGuidesTab.js'),
  'utf8'
);

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

const { computed, methods } = Tab;

const activePart = { id: 1, part_code: 'ACT-01', name: 'Pieza activa', is_active: true };
const inactivePart = { id: 2, part_code: 'INA-02', name: 'Pieza inactiva', is_active: false };

function makeSubmitContext(overrides = {}) {
  return {
    isEditing: true,
    isSaving: false,
    isFormValid: true,
    formError: '',
    successMessage: '',
    partsTouched: false,
    originalSuggestedRootCauseId: 5,
    activeSpareParts: [activePart],
    form: {
      id: 31,
      machine_model: 'Necta Korinto',
      custom_model: '',
      breakdown_type: 'PRODUCT_JAM',
      title: 'Título de la guía',
      error_codes: [],
      steps: [
        { title: 'Paso uno', instruction: 'Instrucción suficientemente larga del primer paso.' },
        { title: 'Paso dos', instruction: 'Instrucción suficientemente larga del segundo paso.' }
      ],
      recommended_spare_part_ids: [1, 2],
      suggested_root_cause_id: 5
    },
    closeModal() {},
    async loadCatalog() {},
    ...overrides
  };
}

console.log('======================================================================');
console.log(' VendGuard: TroubleshootingGuideEditorPartsTest (Módulo 28, H-28-03)');
console.log('======================================================================');

const inactive = computed.inactiveRecommendedParts.call({
  isEditing: true,
  spareParts: [activePart, inactivePart],
  activeSpareParts: [activePart],
  form: { recommended_spare_part_ids: [1, 2] }
});

assert(
  '1.1 La pieza inactiva asociada se identifica para mostrarla en el editor',
  inactive.length === 1
    && inactive[0].id === 2
    && inactive[0].part_code === 'INA-02'
    && inactive[0].name === 'Pieza inactiva'
);

const removalContext = {
  form: { recommended_spare_part_ids: [1, 2] },
  partsTouched: false
};
methods.removeInactivePart.call(removalContext, 2);
assert(
  '1.2 Retirar una pieza inactiva es una acción explícita que marca la lista',
  removalContext.form.recommended_spare_part_ids.length === 1
    && removalContext.form.recommended_spare_part_ids[0] === 1
    && removalContext.partsTouched === true
);

const originalUpdate = api.updateTroubleshootingGuide;
let lastPayload = null;

try {
  api.updateTroubleshootingGuide = async (id, payload) => {
    lastPayload = { id, payload };
    return { id, ...payload };
  };

  await methods.submitForm.call(makeSubmitContext({ partsTouched: false }));
  assert(
    '2.1 Editar sin tocar repuestos omite el campo y conserva las piezas inactivas',
    lastPayload !== null
      && lastPayload.id === 31
      && !Object.prototype.hasOwnProperty.call(lastPayload.payload, 'recommended_spare_part_ids')
  );

  lastPayload = null;
  await methods.submitForm.call(makeSubmitContext({ partsTouched: true }));
  assert(
    '2.2 Editar tocando la lista envía sólo las piezas activas',
    lastPayload !== null
      && Array.isArray(lastPayload.payload.recommended_spare_part_ids)
      && lastPayload.payload.recommended_spare_part_ids.length === 1
      && lastPayload.payload.recommended_spare_part_ids[0] === 1
  );
} finally {
  api.updateTroubleshootingGuide = originalUpdate;
}

assert(
  '3.1 El editor declara el aviso de piezas inactivas y su retirada explícita',
  source.includes('data-testid="guide-inactive-parts-warning"')
    && source.includes('removeInactivePart(part.id)')
    && source.includes('Se conservarán mientras no modifiques la lista.')
);

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. EDICIÓN CON PIEZAS INACTIVAS CERTIFICADA.');
  console.log('======================================================================');
  process.exit(0);
}

console.log(` RESULTADO: FALLO EN ${failures} ASERCIONES.`);
console.log('======================================================================');
process.exit(1);
