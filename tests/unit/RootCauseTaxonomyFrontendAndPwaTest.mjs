/**
 * VendGuard - Root cause taxonomy frontend and PWA guard (RootCauseTaxonomyFrontendAndPwaTest.mjs)
 *
 * Certifica la Fase 6 del Módulo 25 sin navegador, sin red y sin dependencias npm:
 *
 *  - **Precaché PWA (D6, RF-01.2):** el App Shell incluye Chart.js local y los dos
 *    componentes de taxonomía, todos presentes en disco, y `/api/` sigue siendo
 *    `Network-Only` estricto (Art. V.4): ninguna respuesta de API puede acabar en
 *    `CacheStorage`.
 *  - **Transporte offline (RF-03, C5, EARS 03.3):** el paquete de ruta conserva
 *    `root_cause_taxonomy`, el encolado de `RESOLVE_INCIDENT` transporta la pareja
 *    `root_cause_id` / `standard_solution_id` y el volcado de conflicto conserva
 *    códigos y nombres aunque el catálogo cambie después.
 *  - **Interfaz del técnico (D1–D3, Art. V.1):** filtrado por categoría de la
 *    avería más las transversales `OTHER`, conmutador «Mostrar todas las
 *    categorías», preselección en un toque de la solución predeterminada y cero
 *    autorrelleno de `diagnosis` / `action_taken`.
 *  - **Pareto del coordinador (QA 2, QA 4, D6):** gráfico combinado Chart.js de
 *    doble eje Y, umbral 80/20, filtro por fabricante y exportación CSV, con los
 *    colores leídos de los tokens CSS computados.
 *  - **Opacidad del Portal de Sede (Art. V.4, QA 5):** las superficies visibles al
 *    responsable de ubicación y el albarán A4 no exponen ningún campo de taxonomía
 *    interna.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import {
  MUTATION_STATUS,
  OFFLINE_OPERATIONS,
  OfflineQueueError,
  OfflineStorage,
  STOP_TYPE,
  buildResolutionTaxonomyClassification,
  normalizeResolutionTaxonomySelection,
  normalizeSnapshot
} from '../../public/assets/js/utils/OfflineStorage.js';
import { OfflineSyncManager } from '../../public/assets/js/utils/OfflineSyncManager.js';

const PROJECT_ROOT = fileURLToPath(new URL('../../', import.meta.url));
const PUBLIC_ROOT = join(PROJECT_ROOT, 'public');

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

function publicFile(relativePath) {
  return join(PUBLIC_ROOT, relativePath.replace(/^\//, ''));
}

function readPublic(relativePath) {
  return readFileSync(publicFile(relativePath), 'utf8');
}

/** Quita comentarios para que las guardas no se satisfagan (ni fallen) por prosa. */
function stripComments(source) {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '');
}

/** Extrae el cuerpo de un método por su firma contando llaves, sin depender de sangría. */
function extractMethod(source, signature) {
  const start = source.indexOf(signature);
  if (start === -1) {
    return '';
  }

  const callEnd = source.indexOf(')', start);
  const open = source.indexOf('{', callEnd === -1 ? start : callEnd);
  if (open === -1) {
    return '';
  }

  let depth = 0;
  for (let index = open; index < source.length; index++) {
    if (source[index] === '{') {
      depth++;
    } else if (source[index] === '}') {
      depth--;
      if (depth === 0) {
        return source.slice(open + 1, index);
      }
    }
  }

  return '';
}

async function assertRejectsCode(description, operation, expectedCode) {
  try {
    await operation();
    assert(description, false, 'No lanzó ninguna excepción.');
  } catch (error) {
    assert(
      description,
      error instanceof OfflineQueueError && error.code === expectedCode,
      `Código recibido: ${error?.code ?? error?.message ?? 'desconocido'}`
    );
  }
}

// El motor y el almacén comparten el mismo entorno mínimo que ya usa la suite offline.
globalThis.localStorage = globalThis.localStorage ?? {
  _store: {},
  getItem(key) { return this._store[key] ?? null; },
  setItem(key, value) { this._store[key] = String(value); },
  removeItem(key) { delete this._store[key]; },
  clear() { this._store = {}; }
};
globalThis.window = globalThis.window ?? {
  location: { search: '', href: 'http://localhost/' },
  addEventListener() {},
  removeEventListener() {}
};

const NOW = Date.parse('2026-10-30T10:00:00.000Z');

const TAXONOMY = [
  {
    id: 4,
    code: 'COOL-04',
    category: 'COLD_DRINKS',
    name: 'Compresor bloqueado',
    description: 'El compresor no arranca o queda bloqueado.',
    is_active: true,
    solutions: [
      {
        id: 9,
        code: 'SOL-09',
        name: 'Sustituir compresor',
        is_default: true,
        is_active: true
      },
      {
        id: 10,
        code: 'SOL-10',
        name: 'Reiniciar protección térmica',
        is_default: false,
        is_active: true
      }
    ]
  },
  {
    id: 5,
    code: 'PAY-01',
    category: 'PAYMENT_SYSTEM',
    name: 'Lector de tarjetas bloqueado',
    is_active: true,
    solutions: [
      {
        id: 11,
        code: 'SOL-11',
        name: 'Reiniciar el terminal de pago',
        is_default: true,
        is_active: true
      }
    ]
  }
];

function bootstrap(rootCauseTaxonomy = TAXONOMY) {
  return {
    technician_id: 2,
    technician_email: 'jordi@vendguard.internal',
    technician_name: 'Jordi Técnico',
    synced_at: new Date(NOW).toISOString(),
    route: {
      stops: [{ incident_id: 101, id: 101, status: 'ASSIGNED', machine_type: 'COLD_DRINKS' }],
      enabled_locations: [],
      pending_critical_stops_other_locations: []
    },
    preventive_orders: [],
    preventive_checklists_by_order: {},
    spare_parts_catalog_by_machine: {},
    van_stock: { items: [] },
    root_cause_taxonomy: rootCauseTaxonomy
  };
}

function newStorage() {
  return new OfflineStorage({ indexedDB: null });
}

console.log('======================================================================');
console.log(' VendGuard: Root cause taxonomy frontend and PWA guard');
console.log('======================================================================\n');

console.log('--- 1. Precaché del App Shell y Network-Only para /api/ (D6, Art. V.4) ---');

const swSource = readPublic('/sw.js');
const shellMatch = /const APP_SHELL_ASSETS = \[([\s\S]*?)\n\];/.exec(swSource);
const shellAssets = shellMatch
  ? [...shellMatch[1].matchAll(/'([^']+)'/g)].map((match) => match[1])
  : [];
const shellSet = new Set(shellAssets);

const requiredShellAssets = [
  '/assets/js/vendor/chart.umd.min.js',
  '/assets/js/components/CoordinatorRootCauseParetoTab.js',
  '/assets/js/components/CoordinatorRootCauseCatalogModal.js'
];

assert(
  '1.1 El App Shell declara Chart.js local y los dos componentes de taxonomía',
  requiredShellAssets.every((asset) => shellSet.has(asset)),
  requiredShellAssets.filter((asset) => !shellSet.has(asset)).join(', ')
);
assert(
  '1.2 Los tres recursos nuevos existen en disco',
  requiredShellAssets.every((asset) => existsSync(publicFile(asset))),
  requiredShellAssets.filter((asset) => !existsSync(publicFile(asset))).join(', ')
);
assert(
  '1.3 La caché del shell está versionada y conserva la regla Network-Only de /api/',
  /const CACHE_NAME = 'vendguard-app-shell-v\d+'/.test(swSource)
    && /const API_PATH_PREFIX = '\/api\/'/.test(swSource)
    && /request\.method !== 'GET' \|\| isApiRequest\(request\.url\)/.test(swSource)
);
assert(
  '1.4 El corte Network-Only precede a cualquier escritura de caché en el handler fetch',
  (() => {
    const fetchHandler = swSource.slice(swSource.indexOf("self.addEventListener('fetch'"));
    return fetchHandler.indexOf('isApiRequest(request.url)') !== -1
      && fetchHandler.indexOf('isApiRequest(request.url)') < fetchHandler.indexOf('cache.put(');
  })()
);

console.log('\n--- 2. Transporte offline de la taxonomía (RF-03, C5, EARS 03.3) ---');

const preserved = normalizeSnapshot(bootstrap(), null);
assert(
  '2.1 El paquete route_snapshots conserva root_cause_taxonomy con sus soluciones',
  JSON.stringify(preserved.root_cause_taxonomy) === JSON.stringify(TAXONOMY)
);
assert(
  '2.2 Una copia sin catálogo lo declara como colección vacía, nunca como undefined',
  Array.isArray(normalizeSnapshot(bootstrap([]), null).root_cause_taxonomy)
    && normalizeSnapshot(bootstrap([]), null).root_cause_taxonomy.length === 0
);

const selection = normalizeResolutionTaxonomySelection({
  root_cause_id: '4',
  standard_solution_id: '9'
});
assert(
  '2.3 La pareja causa/solución se normaliza a enteros positivos',
  selection.root_cause_id === 4 && selection.standard_solution_id === 9
);

await assertRejectsCode(
  '2.4 Con catálogo local, una resolución sin pareja se rechaza antes de encolar',
  () => normalizeResolutionTaxonomySelection(
    { diagnosis: 'Compresor bloqueado.', action_taken: 'Se sustituye el compresor.' },
    { required: true }
  ),
  'MISSING_ROOT_CAUSE_TAXONOMY'
);

await assertRejectsCode(
  '2.5 Una pareja a medias también se rechaza',
  () => normalizeResolutionTaxonomySelection({ root_cause_id: 4 }, { required: false }),
  'MISSING_ROOT_CAUSE_TAXONOMY'
);

await assertRejectsCode(
  '2.6 Una solución de otra rama se detecta antes de sincronizar',
  () => buildResolutionTaxonomyClassification({ root_cause_taxonomy: TAXONOMY }, {
    root_cause_id: 4,
    standard_solution_id: 11
  }),
  'INCOMPATIBLE_STANDARD_SOLUTION'
);

const classification = buildResolutionTaxonomyClassification({ root_cause_taxonomy: TAXONOMY }, {
  root_cause_id: 4,
  standard_solution_id: 9
});
assert(
  '2.7 La clasificación congelada conserva códigos y nombres para el volcado de conflicto',
  classification.root_cause_code === 'COOL-04'
    && classification.root_cause_name === 'Compresor bloqueado'
    && classification.standard_solution_code === 'SOL-09'
    && classification.standard_solution_name === 'Sustituir compresor'
);

const storage = newStorage();
await storage.saveSnapshot(bootstrap());
const manager = new OfflineSyncManager({
  storage,
  client: {},
  now: () => NOW,
  dispatch: () => {}
});

const queuedResolution = await manager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 101,
  operationType: 'RESOLVE_INCIDENT',
  payload: {
    diagnosis: 'Compresor bloqueado y sin arranque tras el reinicio.',
    action_taken: 'Se sustituye el compresor y se valida el enfriamiento.',
    root_cause_id: 4,
    standard_solution_id: 9
  },
  machineType: 'COLD_DRINKS'
});

assert(
  '2.8 El encolado de RESOLVE_INCIDENT transporta root_cause_id y standard_solution_id',
  queuedResolution.payload.root_cause_id === 4
    && queuedResolution.payload.standard_solution_id === 9
    && queuedResolution.operation_type === 'RESOLVE_INCIDENT'
    && queuedResolution.status === MUTATION_STATUS.PENDING
    && OFFLINE_OPERATIONS.RESOLVE_INCIDENT.stopType === STOP_TYPE.INCIDENT
);
assert(
  '2.9 La mutación guarda la clasificación congelada junto al payload',
  queuedResolution.offline_root_cause_classification?.root_cause_code === 'COOL-04'
    && queuedResolution.offline_root_cause_classification?.standard_solution_code === 'SOL-09'
);

const extractedClassification = extractMethod(
  readPublic('/assets/js/utils/OfflineStorage.js'),
  'buildResolutionTaxonomyClassification(snapshot, payload = {})'
);
assert(
  '2.10 La clasificación nunca rellena diagnosis ni action_taken',
  extractedClassification !== ''
    && !/diagnosis\s*:/.test(extractedClassification)
    && !/action_taken\s*:/.test(extractedClassification)
);

console.log('\n--- 3. Contrato de interfaz del técnico (D1–D3, Art. V.1) ---');

const technicianSource = stripComments(readPublic('/assets/js/views/TechnicianRouteView.js'));
const availableRootCauses = extractMethod(technicianSource, 'availableRootCauses()');
const selectionHandler = extractMethod(technicianSource, 'handleRootCauseSelection(event)');
const resolveMethod = extractMethod(technicianSource, 'async submitResolve()');

assert(
  '3.1 El selector filtra por la categoría de la avería y añade las transversales OTHER',
  availableRootCauses.includes('cause?.category')
    && availableRootCauses.includes('category === preferredCategory || category === \'OTHER\'')
);
assert(
  '3.2 El conmutador «Mostrar todas las categorías» abre el catálogo completo',
  technicianSource.includes('showAllRootCauseCategories')
    && technicianSource.includes('Mostrar todas las categorías')
    && availableRootCauses.includes('this.showAllRootCauseCategories')
);
assert(
  '3.3 Un toque en la causa preselecciona su solución predeterminada activa',
  selectionHandler.includes('solutions.find((solution) => solution.is_default === true)')
    && selectionHandler.includes('this.selectedStandardSolutionId = preferred')
);
assert(
  '3.4 La preselección no autorrellena el diagnóstico ni la acción correctiva',
  selectionHandler !== ''
    && !/this\.resolveDiagnosis\s*=/.test(selectionHandler)
    && !/this\.resolveAction\s*=/.test(selectionHandler)
);
assert(
  '3.5 Los textos libres se validan por separado antes de la puerta de taxonomía',
  resolveMethod.includes('const diag = this.resolveDiagnosis.trim()')
    && resolveMethod.includes('const act = this.resolveAction.trim()')
    && resolveMethod.includes('diag.length < 20 || act.length < 20')
    && resolveMethod.indexOf('diag.length < 20 || act.length < 20')
      < resolveMethod.indexOf('this.isTaxonomySelectionValid')
);
assert(
  '3.6 El payload envía la pareja elegida sin derivarla de los textos',
  resolveMethod.includes('root_cause_id: rootCauseId')
    && resolveMethod.includes('standard_solution_id: standardSolutionId')
    && resolveMethod.includes('diagnosis: diag')
    && resolveMethod.includes('action_taken: act')
);
assert(
  '3.7 La selección incompleta bloquea el cierre con un mensaje propio',
  resolveMethod.includes('Selecciona una causa raíz y su solución estandarizada')
    && technicianSource.includes('isTaxonomySelectionValid()')
);

console.log('\n--- 4. Pareto 80/20 del coordinador (QA 2, QA 4, D6) ---');

const paretoSource = readPublic('/assets/js/components/CoordinatorRootCauseParetoTab.js');
assert(
  '4.1 Instancia un gráfico Chart.js combinado de barras y líneas',
  paretoSource.includes('new ChartConstructor(canvas')
    && paretoSource.includes("type: 'bar'")
    && paretoSource.includes("type: 'line'")
);
assert(
  '4.2 Declara dos ejes Y y el porcentaje acumulado acotado a 100',
  paretoSource.includes("yAxisID: 'yCount'")
    && paretoSource.includes("yAxisID: 'yCumulative'")
    && paretoSource.includes("position: 'right'")
    && paretoSource.includes('max: 100')
);
assert(
  '4.3 Dibuja el umbral 80/20 y marca la Zona Vital del informe',
  paretoSource.includes('Umbral 80/20')
    && paretoSource.includes('labels.map(() => 80)')
    && paretoSource.includes('is_vital_few === true')
);
assert(
  '4.4 Expone el filtro por fabricante y limpia el modelo incompatible',
  paretoSource.includes('filters.manufacturer')
    && paretoSource.includes('handleManufacturerChange')
    && paretoSource.includes('models_by_manufacturer')
);
assert(
  '4.5 Exporta el CSV mediante la API del coordinador',
  paretoSource.includes('exportRootCausesParetoCsv')
    && paretoSource.includes('data-testid="pareto-export-csv"')
);
assert(
  '4.6 Los colores del gráfico se resuelven desde tokens CSS computados, sin literales hexadecimales',
  paretoSource.includes('getComputedStyle')
    && paretoSource.includes('--color-primary')
    && !/#[0-9a-fA-F]{3,8}\b/.test(paretoSource)
);

console.log('\n--- 5. Opacidad de taxonomía en Sede, QR y Albarán A4 (Art. V.4, QA 5) ---');

const privacySurfaces = [
  '/assets/js/views/LocationPortalView.js',
  '/assets/js/views/QrReportView.js',
  '/assets/js/views/QrBatchPrintView.js',
  '/assets/js/components/ServiceSheetModal.js'
];
const internalTaxonomyPattern = /root_cause|standard_solution|RootCause|StandardSolution|root-cause|standard-solution|taxonom/i;

privacySurfaces.forEach((surface, index) => {
  const source = stripComments(readPublic(surface));
  const match = source.match(internalTaxonomyPattern);
  assert(
    `5.${index + 1} ${surface.split('/').pop()} no expone ningún campo ni etiqueta de taxonomía interna`,
    match === null,
    match?.[0] ?? ''
  );
});

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
console.log('======================================================================');

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. T-TAXONOMY-15 CUMPLIDA.');
  process.exit(0);
} else {
  console.error(` RESULTADO: FALLO EN ${failures} ASERCIONES.`);
  process.exit(1);
}
