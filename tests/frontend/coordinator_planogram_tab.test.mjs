/**
 * VendGuard - CoordinatorPlanogramTab Test Suite
 *
 * Certifica la pestaña de catálogo y planograma del Módulo 32 (RF-05.1, D1, D2,
 * C1–C3, RNF-03):
 *   1. Catálogo con KPIs y filtros por texto, categoría, frío y estado.
 *   2. Alta/edición de producto con SKU bloqueado en edición y payload canónico.
 *   3. Baja/reactivación lógica.
 *   4. Selector de máquina filtrable y carga del planograma con semáforo.
 *   5. UPSERT de carril, retirada lógica, preset y clonación.
 *   6. Integración en CoordinatorDashboardView y cero hexadecimales nuevos.
 *
 * Dogma Vanilla: Node.js ESM nativo, sin dependencias externas.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const storageMock = (() => {
  let store = {};
  return {
    getItem: (key) => store[key] || null,
    setItem: (key, value) => { store[key] = String(value); },
    removeItem: (key) => { delete store[key]; },
    clear: () => { store = {}; }
  };
})();
globalThis.localStorage = storageMock;
globalThis.window = { location: { search: '', href: 'http://localhost/' } };

import { api } from '../../public/assets/js/api.js';
import {
  CoordinatorPlanogramTab,
  PRODUCT_CATEGORIES
} from '../../public/assets/js/components/CoordinatorPlanogramTab.js';
import { CoordinatorDashboardView } from '../../public/assets/js/views/CoordinatorDashboardView.js';

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
console.log(' VendGuard: Frontend Test Suite - CoordinatorPlanogramTab');
console.log('======================================================================\n');

const products = [
  {
    id: 1,
    sku: 'SAND-MIXTO-01',
    name: 'Sándwich mixto',
    category: 'PERISHABLE_FOOD',
    unit_type: 'UNIT',
    unit_cost_eur: 1.15,
    sale_price_eur: 2.40,
    unit_margin_eur: 1.25,
    requires_cold_chain: true,
    max_shelf_life_days: 5,
    is_active: true,
    active_slots_count: 2
  },
  {
    id: 2,
    sku: 'SNACK-PATAT-40',
    name: 'Patatas saladas',
    category: 'SNACK',
    unit_type: 'UNIT',
    unit_cost_eur: 0.55,
    sale_price_eur: 1.20,
    unit_margin_eur: 0.65,
    requires_cold_chain: false,
    max_shelf_life_days: null,
    is_active: true,
    active_slots_count: 4
  },
  {
    id: 3,
    sku: 'CAFE-GRANO-1K',
    name: 'Café en grano 1 kg',
    category: 'HOT_BEVERAGE_INGREDIENT',
    unit_type: 'GRAMS',
    unit_cost_eur: 7.25,
    sale_price_eur: 0,
    unit_margin_eur: -7.25,
    requires_cold_chain: false,
    max_shelf_life_days: null,
    is_active: false,
    active_slots_count: 1
  }
];

const machines = [
  {
    id: 10,
    code: 'VEND-0101',
    model: 'Jofemar Vision',
    machine_type: 'PERISHABLE_FOOD',
    location_id: 1,
    location_name: 'Hospital del Mar',
    zone_name: 'Zona Centro',
    floor_wing: 'Planta 1',
    is_active: true
  },
  {
    id: 11,
    code: 'VEND-0201',
    model: 'Necta Korinto',
    machine_type: 'HOT_DRINKS',
    location_id: 2,
    location_name: 'Torre Glòries',
    zone_name: 'Zona Norte',
    floor_wing: 'Planta 4',
    is_active: true
  },
  {
    id: 12,
    code: 'VEND-0301',
    model: 'Jofemar Vision',
    machine_type: 'PERISHABLE_FOOD',
    location_id: 3,
    location_name: 'Hospital de Bellvitge',
    zone_name: 'Zona Sur',
    floor_wing: 'Planta Baja',
    is_active: true
  }
];

const planogram = {
  machine: {
    id: 10,
    code: 'VEND-0101',
    model: 'Jofemar Vision',
    machine_type: 'PERISHABLE_FOOD',
    location_name: 'Hospital del Mar'
  },
  summary: {
    active_slots_count: 2,
    stockout_slots_count: 1,
    low_stock_slots_count: 1,
    fill_rate_percent: 37.5
  },
  slots: [
    {
      id: 1,
      slot_code: '11',
      product_id: 1,
      product_sku: 'SAND-MIXTO-01',
      product_name: 'Sándwich mixto',
      category: 'PERISHABLE_FOOD',
      requires_cold_chain: true,
      product_is_active: true,
      max_capacity: 10,
      current_estimated_qty: 0,
      min_threshold: 3,
      stock_status: 'STOCKOUT',
      stock_status_label: 'Agotado',
      is_active: true
    },
    {
      id: 2,
      slot_code: 'HOPPER_COFFEE',
      product_id: 3,
      product_sku: 'CAFE-GRANO-1K',
      product_name: 'Café en grano 1 kg',
      category: 'HOT_BEVERAGE_INGREDIENT',
      requires_cold_chain: false,
      product_is_active: false,
      max_capacity: 1000,
      current_estimated_qty: 500,
      min_threshold: 100,
      stock_status: 'LOW_STOCK',
      stock_status_label: 'Bajo umbral',
      is_active: true
    }
  ]
};

const calls = [];
api.coordinator.getProducts = async () => ({
  data: {
    kpis: {
      total_products: 3,
      active_products: 2,
      inactive_products: 1,
      cold_chain_products: 1,
      consumable_and_ingredient_products: 1
    },
    products
  }
});
api.coordinator.getMachines = async () => ({ data: machines });
api.coordinator.getMachinePlanogram = async (machineId) => ({
  data: { ...planogram, machine: { ...planogram.machine, id: Number(machineId) } }
});
api.coordinator.createProduct = async (payload) => {
  calls.push({ method: 'createProduct', payload });
  return { data: { ...payload, id: 99 } };
};
api.coordinator.updateProduct = async (productId, payload) => {
  calls.push({ method: 'updateProduct', productId, payload });
  return { data: { ...payload, id: productId } };
};
api.coordinator.changeProductStatus = async (productId, isActive) => {
  calls.push({ method: 'changeProductStatus', productId, isActive });
  return { data: { id: productId, is_active: isActive } };
};
api.coordinator.savePlanogramSlot = async (machineId, slotCode, payload) => {
  calls.push({ method: 'savePlanogramSlot', machineId, slotCode, payload });
  return { data: { slot_code: slotCode, ...payload } };
};
api.coordinator.deactivatePlanogramSlot = async (machineId, slotCode) => {
  calls.push({ method: 'deactivatePlanogramSlot', machineId, slotCode });
  return { data: { slot_code: slotCode, is_active: false } };
};
api.coordinator.applyPlanogramPreset = async (machineId, mode) => {
  calls.push({ method: 'applyPlanogramPreset', machineId, mode });
  return { data: planogram };
};
api.coordinator.clonePlanogram = async (machineId, sourceMachineId, mode) => {
  calls.push({ method: 'clonePlanogram', machineId, sourceMachineId, mode });
  return { data: { ...planogram, source_machine: { id: sourceMachineId } } };
};

function buildInstance() {
  const instance = { ...CoordinatorPlanogramTab.data() };
  Object.assign(instance, CoordinatorPlanogramTab.methods);
  for (const [key, getter] of Object.entries(CoordinatorPlanogramTab.computed)) {
    Object.defineProperty(instance, key, { get: getter });
  }
  return instance;
}

async function runTests() {
  const component = buildInstance();

  // ─────────────────────────────────────────────────────────────────────
  // 1. Catálogo y filtros
  // ─────────────────────────────────────────────────────────────────────
  console.log('--- 1. Catálogo y filtros ---');

  await component.loadProducts();
  assert('1.1 Carga tres productos y los KPIs del servicio', component.products.length === 3 && component.catalogKpis.active_products === 2);
  assert('1.2 La sub-vista inicial es el catálogo', component.activeSection === 'catalog');
  assert('1.3 Filtro por texto busca SKU y nombre', (() => {
    component.productFilters.search = 'sand';
    const filtered = component.filteredProducts;
    component.productFilters.search = '';
    return filtered.length === 1 && filtered[0].sku === 'SAND-MIXTO-01';
  })());
  assert('1.4 Filtro por categoría deja sólo snacks', (() => {
    component.productFilters.category = 'SNACK';
    const filtered = component.filteredProducts;
    component.productFilters.category = '';
    return filtered.length === 1 && filtered[0].category === 'SNACK';
  })());
  assert('1.5 Filtro de cadena de frío deja el perecedero', (() => {
    component.productFilters.requires_cold_chain = 'true';
    const filtered = component.filteredProducts;
    component.productFilters.requires_cold_chain = '';
    return filtered.length === 1 && filtered[0].requires_cold_chain === true;
  })());
  assert('1.6 Estado INACTIVE deja sólo el café dado de baja', (() => {
    component.productFilters.status = 'INACTIVE';
    const filtered = component.filteredProducts;
    component.productFilters.status = 'ACTIVE';
    return filtered.length === 1 && filtered[0].sku === 'CAFE-GRANO-1K';
  })());
  assert('1.7 El margen publicado se usa tal cual', component.productMargin(products[0]) === 1.25);
  assert('1.8 Las categorías del formulario cubren las cinco del dominio', PRODUCT_CATEGORIES.length === 5 && component.categoryLabel('SNACK') === 'Snack y sólido');

  // ─────────────────────────────────────────────────────────────────────
  // 2. Alta y edición de producto
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 2. Alta y edición de producto ---');

  component.openProductModal(products[0]);
  assert('2.1 Editar carga el formulario con id y SKU bloqueado', component.showProductModal === true && component.productForm.id === 1 && component.productForm.sku === 'SAND-MIXTO-01');
  assert('2.2 La categoría perecedera sólo admite UNIT', component.unitTypeOptions.length === 1 && component.unitTypeOptions[0].value === 'UNIT');

  component.openProductModal();
  component.productForm = {
    id: null,
    sku: 't11-nuevo',
    name: 'Vasos de prueba',
    category: 'CUP_STIRRER_CONSUMABLE',
    unit_type: 'SLEEVE',
    unit_cost_eur: '0.03',
    sale_price_eur: '0',
    requires_cold_chain: false,
    max_shelf_life_days: ''
  };
  await component.submitProduct();
  const createCall = calls.find((call) => call.method === 'createProduct');
  assert(
    '2.3 El alta envía SKU normalizado y números al API',
    Boolean(createCall)
    && createCall.payload.sku === 'T11-NUEVO'
    && createCall.payload.unit_cost_eur === 0.03
    && createCall.payload.max_shelf_life_days === null
  );

  component.openProductModal(products[1]);
  component.productForm.name = 'Patatas saladas premium';
  await component.submitProduct();
  const updateCall = calls.find((call) => call.method === 'updateProduct');
  assert('2.4 La edición llama a updateProduct con el id de la fila', Boolean(updateCall) && updateCall.productId === 2 && updateCall.payload.name === 'Patatas saladas premium');

  component.productForm = { ...component.productForm, requires_cold_chain: true, max_shelf_life_days: '' };
  assert('2.5 La validación local exige vida útil con cadena de frío', component.validateProductForm() === false && Boolean(component.productErrors.max_shelf_life_days));
  component.productForm = {
    ...component.productForm,
    category: 'SNACK',
    sale_price_eur: '0',
    requires_cold_chain: false,
    max_shelf_life_days: ''
  };
  assert('2.5a La venta directa exige PVP mayor que 0', component.validateProductForm() === false && Boolean(component.productErrors.sale_price_eur));
  component.productForm = {
    ...component.productForm,
    category: 'PERISHABLE_FOOD',
    sale_price_eur: '2.40',
    requires_cold_chain: false,
    max_shelf_life_days: ''
  };
  assert('2.5b El perecedero exige vida útil aunque no marque el frío', component.validateProductForm() === false && Boolean(component.productErrors.max_shelf_life_days));
  component.showProductModal = false;

  await component.toggleProductStatus(products[0]);
  const toggleCall = calls.find((call) => call.method === 'changeProductStatus');
  assert('2.6 Dar de baja envía is_active=false', Boolean(toggleCall) && toggleCall.productId === 1 && toggleCall.isActive === false);

  // ─────────────────────────────────────────────────────────────────────
  // 3. Máquina y planograma
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 3. Selector de máquina y planograma ---');

  await component.loadMachines();
  assert('3.1 Carga tres máquinas y selecciona la primera', component.machines.length === 3 && String(component.selectedMachineId) === '10');
  assert('3.2 El planograma carga el resumen y dos carriles', component.planogramSlots.length === 2 && component.planogramSummary.stockout_slots_count === 1);
  assert('3.3 El semáforo traduce los tres estados', component.stockStatusLabel('STOCKOUT') === 'Agotado' && component.stockStatusLabel('LOW_STOCK') === 'Bajo umbral' && component.stockStatusLabel('OK') === 'Correcto');
  assert('3.4 La barra de llenado se acota a 0–100', component.slotFillPercent({ max_capacity: 10, current_estimated_qty: 5 }) === 50 && component.slotFillPercent({ max_capacity: 0, current_estimated_qty: 5 }) === 0);
  assert('3.5 Filtro de sede deja la máquina de la sede 1', (() => {
    component.machineFilters.location_id = 1;
    const filtered = component.filteredMachines;
    component.machineFilters.location_id = '';
    return filtered.length === 1 && filtered[0].id === 10;
  })());
  assert('3.6 Filtro de tipología deja la máquina de bebidas calientes', (() => {
    component.machineFilters.machine_type = 'HOT_DRINKS';
    const filtered = component.filteredMachines;
    component.machineFilters.machine_type = '';
    return filtered.length === 1 && filtered[0].id === 11;
  })());

  // ─────────────────────────────────────────────────────────────────────
  // 4. Carriles, preset y clonación
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 4. Carriles, preset y clonación ---');

  component.openSlotModal(planogram.slots[0]);
  assert('4.1 Editar carril precarga sus cotas', component.slotForm.slot_code === '11' && component.slotForm.max_capacity === 10);
  component.slotForm.current_estimated_qty = 7;
  await component.submitSlot();
  const slotCall = calls.find((call) => call.method === 'savePlanogramSlot');
  assert(
    '4.2 Guardar carril llama al UPSERT con la existencia editada',
    Boolean(slotCall)
    && String(slotCall.machineId) === '10'
    && slotCall.slotCode === '11'
    && slotCall.payload.current_estimated_qty === 7
  );

  await component.deactivateSlot(planogram.slots[1]);
  const deactivateCall = calls.find((call) => call.method === 'deactivatePlanogramSlot');
  assert('4.3 Retirar carril usa la baja lógica del API', Boolean(deactivateCall) && deactivateCall.slotCode === 'HOPPER_COFFEE');

  component.presetStockMode = 'FULL';
  await component.applyPreset();
  const presetCall = calls.find((call) => call.method === 'applyPlanogramPreset');
  assert('4.4 La plantilla envía el modo FULL', Boolean(presetCall) && presetCall.mode === 'FULL');

  component.openCloneModal();
  assert('4.5 La clonación sólo ofrece máquinas de la misma tipología', component.cloneSourceOptions.length === 1 && component.cloneSourceOptions[0].id === 12);
  component.cloneSourceId = 12;
  component.cloneStockMode = 'ZERO';
  await component.submitClone();
  const cloneCall = calls.find((call) => call.method === 'clonePlanogram');
  assert('4.6 Clonar envía origen y modo de stock', Boolean(cloneCall) && cloneCall.sourceMachineId === 12 && cloneCall.mode === 'ZERO');

  // ─────────────────────────────────────────────────────────────────────
  // 5. Integración en el panel y sistema de diseño
  // ─────────────────────────────────────────────────────────────────────
  console.log('\n--- 5. Integración y sistema de diseño ---');

  assert('5.1 El dashboard registra CoordinatorPlanogramTab', CoordinatorDashboardView.components.CoordinatorPlanogramTab === CoordinatorPlanogramTab);
  assert('5.2 El dashboard incluye la pestaña tab-planogram', CoordinatorDashboardView.template.includes('data-testid="tab-planogram"'));
  assert('5.3 El dashboard renderiza la pestaña con v-else-if', CoordinatorDashboardView.template.includes('<CoordinatorPlanogramTab v-else-if="activeTab === \'planogram\'"'));
  assert('5.4 La sub-vista de catálogo existe en el template', CoordinatorPlanogramTab.template.includes('data-testid="planogram-catalog-section"'));
  assert('5.5 La sub-vista de matriz de carriles existe en el template', CoordinatorPlanogramTab.template.includes('data-testid="planogram-matrix-section"'));
  assert('5.6 El componente usa tokens CSS para colores', CoordinatorPlanogramTab.template.includes('var(--color-primary)') && CoordinatorPlanogramTab.template.includes('var(--color-error-text)'));

  const source = readFileSync(fileURLToPath(new URL('../../public/assets/js/components/CoordinatorPlanogramTab.js', import.meta.url)), 'utf8');
  assert('5.7 El componente no introduce ningún hexadecimal', !/#[0-9a-fA-F]{3,8}\b/.test(source));
  assert('5.8 El API expone las diez operaciones del módulo', [
    'getProducts',
    'createProduct',
    'updateProduct',
    'changeProductStatus',
    'getMachinePlanogram',
    'saveMachinePlanogram',
    'savePlanogramSlot',
    'deactivatePlanogramSlot',
    'applyPlanogramPreset',
    'clonePlanogram'
  ].every((method) => typeof api.coordinator[method] === 'function'));

  console.log('\n======================================================================');
  if (failures === 0) {
    console.log(` RESULTADO: ¡TODAS LAS PRUEBAS PASARON (${assertions} aserciones, 0 fallos)!`);
    console.log(' PESTAÑA DE CATÁLOGO Y PLANOGRAMA VERIFICADA SATISFACTORIAMENTE.');
  } else {
    console.error(` RESULTADO: ${failures} PRUEBA(S) FALLIDA(S) de ${assertions} evaluadas.`);
  }
  console.log('======================================================================\n');

  if (failures > 0) {
    process.exit(1);
  }
}

runTests().catch((error) => {
  console.error('Error fatal en ejecución de tests:', error);
  process.exit(1);
});
