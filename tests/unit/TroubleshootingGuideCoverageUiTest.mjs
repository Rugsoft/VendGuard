/**
 * VendGuard - TroubleshootingGuideCoverageUiTest
 *
 * Certifica `EARS 05.1` / `QA 1` en la superficie de Coordinación: las tres
 * tarjetas de cobertura leen el resumen global del servidor (`coverage`) y no
 * la tabla filtrada (`filteredGuides`), de modo que filtrar por modelo,
 * categoría, estado o búsqueda no las apaga ni las deja a cero.
 *
 * Desde `H-28-06`, modelo, categoría, estado y búsqueda viajan al servidor; la
 * tabla no reimplementa el filtrado en el navegador y el selector de modelos
 * conserva las opciones vistas en cualquier respuesta.
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
const indexHtmlSource = fs.readFileSync(path.join(projectRoot, 'public/index.html'), 'utf8');

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
  '2.2 La tabla pinta las filas ya filtradas por el servidor sin volver a filtrarlas',
  computed.filteredGuides.call(unfiltered).length === 3
    && computed.filteredGuides.call(filtered).length === 3
);

const kpiStart = componentSource.indexOf('vg-guides-tab__kpis');
const kpiEnd = componentSource.indexOf('vg-guides-tab__toolbar', kpiStart);
const kpiBlock = componentSource.slice(kpiStart, kpiEnd > kpiStart ? kpiEnd : kpiStart + 3000);

assert(
  '2.3 El bloque de las tres tarjetas no referencia las filas filtradas',
  kpiBlock.length > 0 && !kpiBlock.includes('filteredGuides')
);

assert(
  '2.4 La cobertura se carga desde coverage_summary y styles.css se enlaza globalmente',
  componentSource.includes('catalog?.coverage_summary')
    && indexHtmlSource.includes('href="/assets/css/styles.css"')
    && !componentSource.includes('href="/assets/css/styles.css"')
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
console.log('\n--- 3. Filtros delegados al servidor (H-28-06) ---');

const defaultFilters = computed.catalogFilters.call(makeContext());
assert(
  '3.1 Sin filtros, el payload deja modelo y categoría vacíos y el estado en ALL',
  defaultFilters.machine_model === ''
    && defaultFilters.breakdown_type === ''
    && defaultFilters.status === 'ALL'
    && defaultFilters.search === ''
);

const activeFilters = computed.catalogFilters.call(makeContext({
  modelFilter: 'Necta Korinto',
  categoryFilter: 'PAYMENT_SYSTEM',
  statusFilter: 'ACTIVE',
  searchQuery: '  MDB-02  '
}));
assert(
  '3.2 Modelo, categoría, estado y búsqueda viajan al servidor',
  activeFilters.machine_model === 'Necta Korinto'
    && activeFilters.breakdown_type === 'PAYMENT_SYSTEM'
    && activeFilters.status === 'ACTIVE'
    && activeFilters.search === 'MDB-02'
);

assert(
  '3.3 La tabla no reimplementa la búsqueda por código, significado o paso',
  !componentSource.includes('.flatMap((entry) => [entry.code, entry.meaning])')
);

assert(
  '3.4 La recarga filtra en el servidor con el payload vigente',
  componentSource.includes('api.getCoordinatorTroubleshootingGuides(this.catalogFilters)')
);

assert(
  '3.5 La búsqueda se espera antes de consultar al servidor',
  componentSource.includes('scheduleGuideReload')
    && componentSource.includes('setTimeout(() => {')
    && componentSource.includes('}, 250);')
);

console.log('\n--- 4. Modelo normalizado y selector estable (C5) ---');

const normalizedContext = makeContext({
  guides: [],
  modelFilter: '  NECTA   KORINTO  '
});
assert(
  '4.1 El modelo viaja íntegro al servidor, que normaliza la clave (C5)',
  computed.catalogFilters.call(normalizedContext).machine_model === '  NECTA   KORINTO  '
);
assert(
  '4.2 El selector agrupa variantes de escritura vistas en el catálogo',
  computed.modelOptions.call(makeContext({
    knownGuideModels: ['Necta  Korinto', 'necta korinto'],
    models: []
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
