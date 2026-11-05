/**
 * VendGuard - TroubleshootingGuideCoverageUiTest
 *
 * Certifica `EARS 05.1` / `QA 1` en la superficie de Coordinación: las tres
 * tarjetas de cobertura leen el resumen global del servidor (`coverage`) y no
 * la tabla filtrada (`filteredGuides`), de modo que filtrar por modelo,
 * categoría, estado o búsqueda no las apaga ni las deja a cero.
 *
 * El componente se importa tal cual y se ejercitan sus computadas con un
 * contexto falso, sin DOM y sin dependencias externas.
 *
 * Dogma Vanilla: Node ESM puro.
 * Dualismo Lingüístico: aserciones en castellano, código en inglés.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { CoordinatorTroubleshootingGuidesTab as Tab } from '../../public/assets/js/components/CoordinatorTroubleshootingGuidesTab.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(here, '../..');
const componentPath = path.join(projectRoot, 'public/assets/js/components/CoordinatorTroubleshootingGuidesTab.js');
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

const { computed } = Tab;

/** Contexto mínimo con el `this` que esperan las computadas. */
function makeContext(overrides = {}) {
  const context = {
    coverage: {},
    guides: [],
    modelFilter: 'ALL',
    categoryFilter: 'ALL',
    statusFilter: 'ALL',
    searchQuery: '',
    ...overrides
  };

  // `totalActiveGuides` cae en `activeGuides` cuando el servidor no publica
  // cobertura; el contexto falso expone esa computada como getter.
  Object.defineProperty(context, 'activeGuides', {
    configurable: true,
    get() {
      return computed.activeGuides.call(this);
    }
  });

  return context;
}

const coverage = {
  total_active_guides: 8,
  total_guides: 9,
  covered_fleet_models_count: 5,
  total_active_fleet_models_count: 6,
  orphan_fleet_models_count: 1,
  orphan_fleet_models: [
    { machine_model: 'Fas Perla', machine_model_key: 'fas perla' }
  ]
};

const guides = [
  { id: 1, machine_model: 'Necta Korinto', machine_model_key: 'necta korinto', breakdown_type: 'TEMPERATURE_COLD', is_active: true },
  { id: 2, machine_model: 'Necta Korinto', machine_model_key: 'necta korinto', breakdown_type: 'PAYMENT_SYSTEM', is_active: true },
  { id: 3, machine_model: 'Fas Fast 900', machine_model_key: 'fas fast 900', breakdown_type: 'PRODUCT_JAM', is_active: false }
];

console.log('======================================================================');
console.log(' VendGuard: TroubleshootingGuideCoverageUiTest (Módulo 28, EARS 05.1)');
console.log('======================================================================');

// =====================================================================
console.log('\n--- 1. Las tarjetas leen el resumen global del servidor ---');

assert(
  '1.1 Total de guías activas sale de coverage aunque la tabla esté vacía',
  computed.totalActiveGuides.call(makeContext({ coverage, guides: [] })) === 8
);

assert(
  '1.2 Sin cobertura del servidor se usa el recuento local como respaldo',
  computed.totalActiveGuides.call(makeContext({ coverage: {}, guides })) === 2
);

assert(
  '1.3 Modelos cubiertos y total del parque salen de coverage',
  computed.coveredModels.call(makeContext({ coverage })) === 5
    && computed.totalFleetModels.call(makeContext({ coverage })) === 6
);

assert(
  '1.4 Los modelos huérfanos y su recuento salen de coverage',
  computed.orphanModels.call(makeContext({ coverage })).length === 1
    && computed.orphanCount.call(makeContext({ coverage })) === 1
);

// =====================================================================
console.log('\n--- 2. Inmunidad al filtrado de la tabla (QA 1) ---');

const snapshot = (context) => [
  computed.totalActiveGuides.call(context),
  computed.coveredModels.call(context),
  computed.totalFleetModels.call(context),
  computed.orphanCount.call(context)
].join('|');

const unfiltered = makeContext({ coverage, guides });
const filtered = makeContext({
  coverage,
  guides,
  modelFilter: 'FAS FAST 900',
  categoryFilter: 'OTHER',
  statusFilter: 'ACTIVE',
  searchQuery: 'sin-resultados'
});

assert(
  '2.1 Las cuatro cifras no cambian al filtrar la tabla',
  snapshot(unfiltered) === snapshot(filtered) && snapshot(unfiltered) === '8|5|6|1',
  `sin filtro: ${snapshot(unfiltered)} · con filtro: ${snapshot(filtered)}`
);

assert(
  '2.2 El filtro sí reduce las filas de la tabla',
  computed.filteredGuides.call(unfiltered).length === 3
    && computed.filteredGuides.call(filtered).length === 0
);

const kpiStart = componentSource.indexOf('vg-guides-tab__kpis');
const kpiEnd = componentSource.indexOf('vg-guides-tab__toolbar', kpiStart);
const kpiBlock = componentSource.slice(kpiStart, kpiEnd > kpiStart ? kpiEnd : kpiStart + 3000);

assert(
  '2.3 El bloque de las tres tarjetas no referencia las filas filtradas',
  kpiBlock.length > 0 && !kpiBlock.includes('filteredGuides')
);

assert(
  '2.4 La cobertura se carga desde coverage_summary y la pestaña enlaza styles.css',
  componentSource.includes('catalog?.coverage_summary')
    && componentSource.includes('href="/assets/css/styles.css"')
);

assert(
  '2.5 Las tres tarjetas declaran sus testids',
  ['guides-kpi-active', 'guides-kpi-covered', 'guides-kpi-orphans']
    .every((testid) => kpiBlock.includes(`data-testid="${testid}"`))
);

assert(
  '2.6 Cada modelo huérfano ofrece crear su guía preseleccionada',
  componentSource.includes('@click="openCreate(orphan.machine_model)"')
);

assert(
  '2.7 Las computadas de cobertura existen y son funciones',
  ['totalActiveGuides', 'coveredModels', 'totalFleetModels', 'orphanModels', 'orphanCount']
    .every((name) => typeof computed[name] === 'function')
);

// =====================================================================
console.log('\n--- 3. Búsqueda textual por título, código, significado y paso (05.1) ---');

const searchableGuide = {
  id: 20,
  machine_model: 'Necta Korinto',
  machine_model_key: 'necta korinto',
  breakdown_type: 'PAYMENT_SYSTEM',
  is_active: true,
  title: 'Lector MDB sin respuesta',
  error_codes: [{ code: 'MDB-02', meaning: 'Validador de monedas sin comunicación' }],
  steps: [{ title: 'Comprobar el bus', instruction: 'Revisar el latiguillo del módulo de pago y su conector.' }]
};
const searchContext = makeContext({ guides: [searchableGuide] });

searchContext.searchQuery = 'latiguillo';
assert(
  '3.1 Una palabra de la instrucción de un paso encuentra la guía',
  computed.filteredGuides.call(searchContext).length === 1
);
searchContext.searchQuery = 'validador';
assert(
  '3.2 El significado de un código también forma parte del índice',
  computed.filteredGuides.call(searchContext).length === 1
);
searchContext.searchQuery = 'mdb-02';
assert(
  '3.3 El código de error sigue encontrando la guía',
  computed.filteredGuides.call(searchContext).length === 1
);
searchContext.searchQuery = 'sin coincidencias';
assert(
  '3.4 La búsqueda no produce falsos positivos',
  computed.filteredGuides.call(searchContext).length === 0
);

console.log('\n--- 4. Filtro de modelo normalizado (C5) ---');

const normalizedContext = makeContext({
  guides: [searchableGuide],
  modelFilter: '  NECTA   KORINTO  '
});
assert(
  '4.1 Mayúsculas y espacios repetidos no rompen el filtro de modelo',
  computed.filteredGuides.call(normalizedContext).length === 1
);
assert(
  '4.2 El selector agrupa variantes de escritura del mismo modelo',
  computed.modelOptions.call(makeContext({
    guides: [searchableGuide],
    models: ['Necta  Korinto', 'necta korinto']
  })).length === 1
);

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. COBERTURA DE COORDINACIÓN INMUNE AL FILTRO.');
  console.log('======================================================================');
  process.exit(0);
}

console.log(` RESULTADO: FALLO EN ${failures} ASERCIONES.`);
console.log('======================================================================');
process.exit(1);
