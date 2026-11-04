/**
 * VendGuard - TroubleshootingGuideAccordionTest
 *
 * Certifica el comportamiento del acordeón de guías del Módulo 28 sin DOM y sin
 * dependencias: importa el componente Vue y ejercita sus métodos y computadas
 * con un contexto falso, tal como hacen las suites de otros componentes.
 *
 * Cubre los huecos declarados en la auditoría:
 *   1. `02.1.1` Buscador instantáneo por código y significado.
 *   2. `02.1.2` / `C6` Casillas locales por incidencia, sin red ni mutaciones.
 *   3. `02.2` Semáforo de stock sin importes.
 *   4. `02.3` Aplicación de la causa sugerida sin escribir diagnóstico ni acción.
 *   5. `QA 3` Orden canónico con la coincidencia exacta primero.
 *
 * Dogma Vanilla: Node ESM puro, sin dependencias externas.
 * Dualismo Lingüístico: aserciones en castellano, código en inglés.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { TroubleshootingGuideAccordion as Accordion } from '../../public/assets/js/components/TroubleshootingGuideAccordion.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(here, '../..');
const componentPath = path.join(projectRoot, 'public/assets/js/components/TroubleshootingGuideAccordion.js');
const componentSource = fs.readFileSync(componentPath, 'utf8');

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

// Almacén local falso: el componente sólo usa getItem/setItem.
const store = new Map();
globalThis.localStorage = {
  getItem: (key) => (store.has(key) ? store.get(key) : null),
  setItem: (key, value) => { store.set(key, String(value)); },
  removeItem: (key) => { store.delete(key); },
  clear: () => { store.clear(); }
};

const { computed, methods } = Accordion;

/** Contexto mínimo con el `this` que esperan métodos y computadas. */
function makeContext(overrides = {}) {
  const context = {
    incidentId: 7,
    guides: [],
    breakdownType: '',
    machineType: '',
    sanitaryWarning: false,
    selectedGuideId: null,
    showAllGuides: false,
    searchQuery: '',
    checkedSteps: {},
    $emit() {},
    ...overrides
  };

  Object.defineProperty(context, 'stepStorageKey', {
    configurable: true,
    get() {
      return computed.stepStorageKey.call(this);
    }
  });

  // `matchesSearch` y `selectedGuide` leen computadas: el contexto falso las
  // expone como getters para no duplicar la lógica del componente.
  Object.defineProperty(context, 'normalizedSearch', {
    configurable: true,
    get() {
      return computed.normalizedSearch.call(this);
    }
  });

  // `toggleStep` e `isStepChecked` delegan en `stepKey`.
  context.stepKey = function stepKey(guide, index) {
    return methods.stepKey.call(this, guide, index);
  };

  // `hasExactCategoryMatch` y `visibleGuides` leen la colección ordenada.
  Object.defineProperty(context, 'orderedGuides', {
    configurable: true,
    get() {
      return computed.orderedGuides.call(this);
    }
  });

  return context;
}

const cold = {
  id: 1,
  breakdown_type: 'TEMPERATURE_COLD',
  is_active: true,
  error_codes: [
    { code: 'E04', meaning: 'Sonda térmica NTC en circuito abierto' },
    { code: 'AL-TEMP', meaning: 'Temperatura de cabina alta' }
  ],
  steps: [
    { step_order: 1, title: 'Comprobar turbina', instruction: 'Verificar que la turbina gira libremente sin hielo.' }
  ],
  recommended_spare_parts: [
    { spare_part_id: 10, part_code: 'SOND-NTC-01', name: 'Sonda NTC', is_active: true, van_stock_quantity: 4 },
    { spare_part_id: 11, part_code: 'VENT-EVAP-01', name: 'Turbina', is_active: true, van_stock_quantity: 0 },
    { spare_part_id: 12, part_code: 'MOT-ESP-01', name: 'Motor', is_active: true, van_stock_quantity: -1 },
    { spare_part_id: 13, part_code: 'TERM-SEG-01', name: 'Termostato', is_active: false, van_stock_quantity: 2 }
  ],
  suggested_root_cause: {
    id: 5,
    code: 'RC-COLD-01',
    name: 'Sonda descalibrada',
    is_active: true,
    default_solution: { id: 9, name: 'Sustituir la sonda NTC' }
  }
};

const payment = { id: 2, breakdown_type: 'PAYMENT_SYSTEM', is_active: true, error_codes: [], steps: [], recommended_spare_parts: [] };
const jam = { id: 3, breakdown_type: 'PRODUCT_JAM', is_active: true, error_codes: [], steps: [], recommended_spare_parts: [] };
const other = { id: 4, breakdown_type: 'OTHER', is_active: true, error_codes: [], steps: [], recommended_spare_parts: [] };

console.log('======================================================================');
console.log(' VendGuard: TroubleshootingGuideAccordionTest (Módulo 28)');
console.log('======================================================================');

// =====================================================================
console.log('\n--- 1. Buscador instantáneo de códigos (02.1.1) ---');

const searchContext = makeContext();

searchContext.searchQuery = 'e04';
assert(
  '1.1 Un código en minúsculas encuentra su entrada',
  methods.matchesSearch.call(searchContext, cold) === true
);

searchContext.searchQuery = 'sonda';
assert(
  '1.2 Un fragmento del significado también encuentra la entrada',
  methods.matchesSearch.call(searchContext, cold) === true
);

searchContext.searchQuery = 'xyz-99';
assert(
  '1.3 Un texto que no existe no produce falsos positivos',
  methods.matchesSearch.call(searchContext, cold) === false
);

searchContext.searchQuery = '   ';
assert(
  '1.4 Sin texto de búsqueda, la guía siempre coincide',
  methods.matchesSearch.call(searchContext, cold) === true
);

// =====================================================================
console.log('\n--- 2. Casillas locales por incidencia (02.1.2 / C6) ---');

store.clear();
const toggleContext = makeContext();
methods.toggleStep.call(toggleContext, cold, 0);
assert(
  '2.1 Marcar un paso lo deja activo en el estado local',
  toggleContext.checkedSteps['1:1'] === true
);

const storageKey = 'vendguard:troubleshooting-guide-steps:7';
assert(
  '2.2 El estado se persiste bajo la clave de la incidencia',
  store.has(storageKey) && JSON.parse(store.get(storageKey))['1:1'] === true
);

methods.toggleStep.call(toggleContext, cold, 0);
assert(
  '2.3 Volver a pulsar desmarca y limpia el almacén',
  toggleContext.checkedSteps['1:1'] === undefined
    && JSON.parse(store.get(storageKey))['1:1'] === undefined
);

methods.toggleStep.call(toggleContext, cold, 0);
const reloadContext = makeContext();
methods.loadCheckedSteps.call(reloadContext);
assert(
  '2.4 Al reabrir la incidencia se recuperan las casillas',
  methods.isStepChecked.call(reloadContext, cold, 0) === true
);

const otherIncident = makeContext({ incidentId: 8 });
methods.loadCheckedSteps.call(otherIncident);
assert(
  '2.5 Otra incidencia no hereda las casillas marcadas',
  methods.isStepChecked.call(otherIncident, cold, 0) === false
);

assert(
  '2.6 El componente no llama a la red ni importa el cliente de API',
  !/\bfetch\s*\(/.test(componentSource) && !/api\.js/.test(componentSource),
  'El marcado de pasos debe ser 100 % local (C6).'
);

// =====================================================================
console.log('\n--- 3. Semáforo de stock sin importes (02.2 / Art. V.4) ---');

const [inStock, emptyStock, negativeStock, inactivePart] = cold.recommended_spare_parts;
assert(
  '3.1 Un saldo positivo anuncia las unidades disponibles',
  methods.stockLabel(inStock) === '✅ En tu furgoneta: 4 uds.'
);
assert(
  '3.2 El saldo cero se declara como falta de stock',
  methods.stockLabel(emptyStock) === '⚠️ Sin stock en tu furgoneta'
);
assert(
  '3.3 El saldo negativo también se declara como falta de stock',
  methods.stockLabel(negativeStock) === '⚠️ Sin stock en tu furgoneta'
);
assert(
  '3.4 Una pieza desactivada se identifica como inactiva',
  methods.stockLabel(inactivePart) === 'Inactiva en catálogo'
);
assert(
  '3.5 Ninguna etiqueta expone importes monetarios',
  [inStock, emptyStock, negativeStock, inactivePart]
    .every((part) => !/[€$]|EUR|USD/.test(methods.stockLabel(part)))
);
assert(
  '3.6 Los tonos distinguen disponible, sin stock e inactiva',
  methods.stockClass(inStock) === 'vg-chip-success'
    && methods.stockClass(emptyStock) === 'vg-chip-warning'
    && methods.stockClass(inactivePart) === 'vg-chip-danger'
);

// =====================================================================
console.log('\n--- 4. Aplicar causa sugerida sin autorrelleno (02.3 / Art. V.1) ---');

const emitted = [];
const causeContext = makeContext({ $emit: (event, payload) => emitted.push({ event, payload }) });
methods.applySuggestedRootCause.call(causeContext, cold);
assert(
  '4.1 La causa se emite con su guía de origen',
  emitted.length === 1
    && emitted[0].event === 'apply-root-cause'
    && emitted[0].payload.rootCause.id === 5
    && emitted[0].payload.guide.id === 1
);

const silentContext = makeContext({ $emit: (event, payload) => emitted.push({ event, payload }) });
methods.applySuggestedRootCause.call(silentContext, other);
assert('4.2 Una guía sin causa sugerida no emite nada', emitted.length === 1);
assert(
  '4.3 El componente no escribe el diagnóstico ni la acción',
  causeContext.diagnosis === undefined && causeContext.action_taken === undefined
);
assert(
  '4.4 El fuente no contiene asignaciones a diagnosis ni action_taken',
  !/\bthis\.(diagnosis|action_taken)\s*=/.test(componentSource)
);

// =====================================================================
console.log('\n--- 5. Orden canónico del conmutador (QA 3) ---');

const orderingContext = makeContext({
  guides: [other, jam, cold, payment],
  breakdownType: 'PAYMENT_SYSTEM'
});
assert(
  '5.1 La coincidencia exacta sube a la primera posición',
  computed.orderedGuides.call(orderingContext).map((guide) => guide.id).join(',') === '2,1,3,4'
);

const canonicalContext = makeContext({
  guides: [other, jam, cold, payment],
  breakdownType: ''
});
assert(
  '5.2 Sin categoría solicitada rige la prioridad sanitaria',
  computed.orderedGuides.call(canonicalContext).map((guide) => guide.id).join(',') === '1,2,3,4'
);

const fallbackContext = makeContext({
  guides: [other, payment, cold],
  breakdownType: 'PRODUCT_JAM'
});
assert(
  '5.3 Si no hay coincidencia exacta se ofrece la primera del orden canónico',
  computed.orderedGuides.call(fallbackContext).map((guide) => guide.id).join(',') === '1,2,4'
);

// =====================================================================
console.log('\n--- 6. Aviso cuando no hay ficha de la categoría reportada (01.2.2) ---');

const noExactContext = makeContext({
  guides: [cold, payment, other],
  breakdownType: 'PRODUCT_JAM'
});
assert(
  '6.1 Sin coincidencia exacta, el aviso se enciende',
  computed.hasExactCategoryMatch.call(noExactContext) === false
);
assert(
  '6.2 La guía mostrada es la primera del orden canónico',
  computed.orderedGuides.call(noExactContext)[0].id === 1
);

const exactContext = makeContext({
  guides: [cold, payment, other],
  breakdownType: 'PAYMENT_SYSTEM'
});
assert(
  '6.3 Con coincidencia exacta, el aviso se apaga',
  computed.hasExactCategoryMatch.call(exactContext) === true
);
assert(
  '6.4 Sin categoría reportada no se muestra el aviso',
  computed.hasExactCategoryMatch.call(makeContext({ guides: [cold], breakdownType: '' })) === true
);
assert(
  '6.5 El fuente declara el estado informativo y su texto',
  /data-testid="guide-no-exact-category"/.test(componentSource)
    && componentSource.includes('No hay una ficha específica para la categoría reportada')
);
assert(
  '6.6 El aviso convive con el estado de modelo sin guías, sin sustituirlo',
  componentSource.includes('data-testid="guide-empty"')
    && componentSource.indexOf('guide-no-exact-category') < componentSource.indexOf('data-testid="guide-empty"')
);

// =====================================================================
console.log('\n--- 7. Banner sanitario prioritario (01.3 / Art. II) ---');

assert(
  '7.1 Una máquina perecedera enciende el banner',
  computed.showSanitaryBanner.call(makeContext({ machineType: 'PERISHABLE_FOOD' })) === true
);
assert(
  '7.2 Una guía de frío enciende el banner',
  computed.showSanitaryBanner.call(makeContext({ guides: [cold] })) === true
);
assert(
  '7.3 La categoría reportada de frío enciende el banner',
  computed.showSanitaryBanner.call(makeContext({ breakdownType: 'TEMPERATURE_COLD' })) === true
);
assert(
  '7.4 El prop explícito fuerza el banner',
  computed.showSanitaryBanner.call(makeContext({ sanitaryWarning: true })) === true
);
assert(
  '7.5 Una máquina y guías sin riesgo no lo encienden',
  computed.showSanitaryBanner.call(makeContext({ guides: [payment], machineType: 'SNACKS' })) === false
);
assert(
  '7.6 La plantilla lo declara como alerta con su testid y texto de frío',
  componentSource.includes('data-testid="guide-sanitary-warning"')
    && componentSource.includes('Alerta sanitaria prioritaria')
);

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. ACORDEÓN DE GUÍAS CERTIFICADO.');
  console.log('======================================================================');
  process.exit(0);
}

console.log(` RESULTADO: FALLO EN ${failures} ASERCIONES.`);
console.log('======================================================================');
process.exit(1);
