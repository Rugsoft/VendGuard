/**
 * VendGuard - CoordinatorSparePartsTab Test Suite (CoordinatorSparePartsTabTest.mjs)
 * 
 * Valida la funcionalidad reactiva y los requisitos de:
 * CoordinatorSparePartsTab.js (Módulo M2: RF-REP-01, RF-REP-02, RNF-REP-03, docs/design.md).
 * 
 * Hecho cuando:
 * 1. Carga el catálogo maestro de repuestos y los modelos de máquinas dispensadoras.
 * 2. Calcula métricas reactivas (total, activos, inactivos, coste medio de referencia).
 * 3. Aplica filtros reactivos por búsqueda de texto, categoría técnica, modelo y estado operativo.
 * 4. Gestiona el ciclo de vida del modal de alta validando campos obligatorios y formato de datos.
 * 5. Gestiona la selección dinámica y adición de modelos compatibles.
 * 6. Gestiona la edición de especificaciones y actualización de costes de referencia.
 * 7. Permite conmutar el estado activo/inactivo (baja lógica y reactivación sin borrado físico, Art. III).
 * 8. Respeta las directrices visuales de docs/design.md (#2560ff, radios 4px/8px).
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
import { CoordinatorSparePartsTab } from '../../public/assets/js/components/CoordinatorSparePartsTab.js';

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
console.log(' VendGuard: Frontend Test Suite - CoordinatorSparePartsTab (T-SPARE-14)');
console.log('======================================================================\n');

// Mock Fixtures
const mockCatalogData = [
  {
    id: 1,
    part_code: 'VALV-SOL-01',
    name: 'Electroválvula 24V Ceme 2 Vías',
    category: 'HYDRAULIC',
    category_label: 'Hidráulica y Presión',
    manufacturer: 'Ceme',
    reference_cost: 28.50,
    is_active: true,
    compatible_models: ['Sanden Vendo G-Drink', 'Necta Krea'],
    notes: 'Rosca estándar 1/8'
  },
  {
    id: 2,
    part_code: 'SOND-NTC-02',
    name: 'Sonda de Temperatura NTC 10K',
    category: 'THERMAL',
    category_label: 'Térmico y Refrigeración',
    manufacturer: 'Carel',
    reference_cost: 14.20,
    is_active: true,
    compatible_models: ['Sanden Vendo G-Drink'],
    notes: 'Conector bipolar JST'
  },
  {
    id: 3,
    part_code: 'BOM-ULK-03',
    name: 'Bomba Ulka EX5 230V 48W',
    category: 'HYDRAULIC',
    category_label: 'Hidráulica y Presión',
    manufacturer: 'Ulka',
    reference_cost: 32.00,
    is_active: false,
    compatible_models: ['Bianchi BVM 952', 'Necta Krea'],
    notes: 'Desactivada temporalmente por cambio de proveedor'
  },
  {
    id: 4,
    part_code: 'LEC-MON-04',
    name: 'Lector Monedero MDB Gryphon',
    category: 'PAYMENT_SYSTEM',
    category_label: 'Sistemas de Pago',
    manufacturer: 'CPI',
    reference_cost: 185.00,
    is_active: true,
    compatible_models: ['Sanden Vendo G-Drink', 'Bianchi BVM 952', 'Necta Krea', 'FAS Perla'],
    notes: 'Firmware v2.4'
  }
];

const mockModelsData = [
  'Bianchi BVM 952',
  'FAS Perla',
  'Necta Krea',
  'Sanden Vendo G-Drink'
];

let apiCreatePayload = null;
let apiUpdatePayload = null;
let apiToggleStatusPayload = null;

// Mock de llamadas API
api.coordinator.getSparePartsCatalog = async () => ({ success: true, data: mockCatalogData });
api.coordinator.getSparePartModels = async () => ({ success: true, data: mockModelsData });

api.coordinator.createSparePart = async (payload) => {
  apiCreatePayload = payload;
  const newPart = {
    id: 10,
    ...payload,
    is_active: true
  };
  mockCatalogData.push(newPart);
  return { success: true, data: newPart };
};

api.coordinator.updateSparePart = async (id, payload) => {
  apiUpdatePayload = { id, payload };
  const idx = mockCatalogData.findIndex(p => p.id === id);
  if (idx !== -1) {
    mockCatalogData[idx] = { ...mockCatalogData[idx], ...payload };
  }
  return { success: true, data: mockCatalogData[idx] };
};

api.coordinator.toggleSparePartStatus = async (id, isActive) => {
  apiToggleStatusPayload = { id, isActive };
  const part = mockCatalogData.find(p => p.id === id);
  if (part) {
    part.is_active = isActive;
  }
  return { success: true, data: { id, is_active: isActive } };
};

// Instanciación del componente
function createComponent() {
  const comp = {
    ...CoordinatorSparePartsTab.data(),
    ...CoordinatorSparePartsTab.methods,
    _emitted: {},
    $emit(eventName, payload) {
      this._emitted[eventName] = this._emitted[eventName] || [];
      this._emitted[eventName].push(payload !== undefined ? payload : true);
    }
  };

  // Enlazar getters computados
  for (const [key, getter] of Object.entries(CoordinatorSparePartsTab.computed)) {
    Object.defineProperty(comp, key, {
      get: getter,
      configurable: true
    });
  }

  return comp;
}

const tab = createComponent();

// =========================================================================
// BLOQUE 1: Carga Inicial de Datos y Métricas de Catálogo
// =========================================================================
console.log('--- BLOQUE 1: Carga Inicial de Datos y Métricas ---');

await tab.loadParts();
assert('1.1 loadParts carga 4 repuestos en el catálogo', tab.parts.length === 4);

await tab.loadModels();
assert('1.2 loadModels carga 4 modelos de máquinas', tab.models.length === 4);

const metrics = tab.metrics;
assert('1.3 metrics.total contabiliza 4 repuestos', metrics.total === 4);
assert('1.4 metrics.active contabiliza 3 repuestos activos', metrics.active === 3);
assert('1.5 metrics.inactive contabiliza 1 repuesto inactivo (baja lógica)', metrics.inactive === 1);
assert('1.6 metrics.averageCost calcula el coste medio correcto',
  Math.abs(metrics.averageCost - ((28.5 + 14.2 + 32.0 + 185.0) / 4)) < 0.01);

// =========================================================================
// BLOQUE 2: Filtros Reactivos Multicriterio
// =========================================================================
console.log('\n--- BLOQUE 2: Filtros Reactivos Multicriterio ---');

// 2.1 Búsqueda por texto (código)
tab.searchQuery = 'valv';
let filtered = tab.filteredParts;
assert('2.1 Buscador por código encuentra VALV-SOL-01 de forma insensible a mayúsculas',
  filtered.length === 1 && filtered[0].part_code === 'VALV-SOL-01');

// 2.2 Búsqueda por fabricante
tab.searchQuery = 'Carel';
filtered = tab.filteredParts;
assert('2.2 Buscador por fabricante encuentra repuesto de Carel',
  filtered.length === 1 && filtered[0].name.includes('Carel') === false && filtered[0].manufacturer === 'Carel');

// 2.3 Búsqueda por notas técnicas
tab.searchQuery = 'Rosca';
filtered = tab.filteredParts;
assert('2.3 Buscador por texto en notas técnicas encuentra VALV-SOL-01',
  filtered.length === 1 && filtered[0].part_code === 'VALV-SOL-01');

tab.searchQuery = ''; // Reset búsqueda

// 2.4 Filtro por categoría técnica
tab.categoryFilter = 'HYDRAULIC';
filtered = tab.filteredParts;
assert('2.4 Filtro por categoría HYDRAULIC retorna 2 repuestos hidráulicos',
  filtered.length === 2 && filtered.every(p => p.category === 'HYDRAULIC'));

tab.categoryFilter = 'PAYMENT_SYSTEM';
filtered = tab.filteredParts;
assert('2.5 Filtro por categoría PAYMENT_SYSTEM retorna 1 repuesto de pago',
  filtered.length === 1 && filtered[0].part_code === 'LEC-MON-04');

tab.categoryFilter = 'ALL'; // Reset categoría

// 2.6 Filtro por modelo de máquina compatible
tab.modelFilter = 'Bianchi BVM 952';
filtered = tab.filteredParts;
assert('2.6 Filtro por modelo Bianchi BVM 952 retorna 2 repuestos compatibles',
  filtered.length === 2 && filtered.every(p => p.compatible_models.includes('Bianchi BVM 952')));

tab.modelFilter = 'ALL'; // Reset modelo

// 2.7 Filtro por estado operativo (Activo/Inactivo)
tab.statusFilter = 'ACTIVE';
filtered = tab.filteredParts;
assert('2.7 Filtro de estado ACTIVE retorna los 3 repuestos operativos para campo',
  filtered.length === 3 && filtered.every(p => p.is_active === true));

tab.statusFilter = 'INACTIVE';
filtered = tab.filteredParts;
assert('2.8 Filtro de estado INACTIVE retorna el repuesto en baja lógica',
  filtered.length === 1 && filtered[0].part_code === 'BOM-ULK-03');

// 2.9 Combinación de filtros simultáneos
tab.categoryFilter = 'HYDRAULIC';
tab.statusFilter = 'ACTIVE';
filtered = tab.filteredParts;
assert('2.9 Combinación categoría HYDRAULIC + estado ACTIVE retorna únicamente 1 repuesto activo',
  filtered.length === 1 && filtered[0].part_code === 'VALV-SOL-01');

// Reset de todos los filtros
tab.categoryFilter = 'ALL';
tab.statusFilter = 'ALL';
tab.modelFilter = 'ALL';
tab.searchQuery = '';

// =========================================================================
// BLOQUE 3: Modal de Alta de Repuesto y Validaciones de Entrada
// =========================================================================
console.log('\n--- BLOQUE 3: Modal de Alta y Validaciones de Entrada ---');

// 3.1 Apertura de modal de creación
tab.openCreateModal();
assert('3.1 openCreateModal inicializa el modal en modo alta', tab.showModal === true && tab.isEditing === false);
assert('3.2 openCreateModal limpia el formulario', tab.formData.part_code === '' && tab.formData.compatible_models.length === 0);

// 3.2 Validación: Código vacío
tab.formData.part_code = '   ';
await tab.savePart();
assert('3.3 Rechaza alta si el código de repuesto está vacío', tab.modalError.includes('El código de repuesto es obligatorio'));

// 3.3 Validación: Denominación vacía
tab.formData.part_code = 'JUN-TOR-05';
tab.formData.name = '';
await tab.savePart();
assert('3.4 Rechaza alta si la denominación técnica está vacía', tab.modalError.includes('La denominación técnica del repuesto es obligatoria'));

// 3.4 Validación: Fabricante vacío
tab.formData.name = 'Junta Tórica Caldera EPDM';
tab.formData.manufacturer = '';
await tab.savePart();
assert('3.5 Rechaza alta si el fabricante está vacío', tab.modalError.includes('El fabricante o proveedor del repuesto es obligatorio'));

// 3.5 Validación: Coste de referencia inválido o negativo
tab.formData.manufacturer = 'Gaco';
tab.formData.reference_cost = -5;
await tab.savePart();
assert('3.6 Rechaza alta si el coste de referencia es negativo', tab.modalError.includes('El coste de referencia debe ser un valor numérico mayor o igual a 0'));

// 3.6 Validación: Sin modelos compatibles asociados
tab.formData.reference_cost = '3.75';
tab.formData.compatible_models = [];
await tab.savePart();
assert('3.7 Rechaza alta si no se asocia al menos un modelo compatible', tab.modalError.includes('Debe asociar al menos un modelo de máquina compatible'));

// 3.7 Asignación dinámica de modelos compatibles
tab.toggleModelSelection('Sanden Vendo G-Drink');
assert('3.8 toggleModelSelection añade modelo a la lista', tab.formData.compatible_models.includes('Sanden Vendo G-Drink'));

tab.toggleModelSelection('Necta Krea');
assert('3.9 toggleModelSelection añade segundo modelo compatible', tab.formData.compatible_models.length === 2);

// Añadir modelo personalizado que no existía
tab.customModelInput = 'Jofemar Coffeemar G250';
tab.addCustomModel();
assert('3.10 addCustomModel añade nuevo modelo a formData y al listado general de modelos',
  tab.formData.compatible_models.includes('Jofemar Coffeemar G250') && tab.models.includes('Jofemar Coffeemar G250'));
assert('3.11 addCustomModel limpia el campo de texto', tab.customModelInput === '');

// Eliminar un modelo
tab.removeModel('Necta Krea');
assert('3.12 removeModel elimina el modelo seleccionado', !tab.formData.compatible_models.includes('Necta Krea'));

// 3.8 Guardado exitoso de alta
tab.formData.category = 'CONSUMABLE';
tab.formData.notes = 'Soporta hasta 140°C';
await tab.savePart();

assert('3.13 savePart ejecuta api.coordinator.createSparePart con datos normalizados',
  apiCreatePayload?.part_code === 'JUN-TOR-05' &&
  apiCreatePayload?.name === 'Junta Tórica Caldera EPDM' &&
  apiCreatePayload?.reference_cost === 3.75 &&
  apiCreatePayload?.compatible_models.length === 2);

assert('3.14 savePart cierra el modal de alta', tab.showModal === false);
assert('3.15 savePart emite evento part-saved', (tab._emitted['part-saved'] || []).length > 0);

// =========================================================================
// BLOQUE 4: Modal de Edición de Repuesto Existente
// =========================================================================
console.log('\n--- BLOQUE 4: Modal de Edición de Repuesto Existente ---');

const partToEdit = tab.parts.find(p => p.id === 1);
tab.openEditModal(partToEdit);

assert('4.1 openEditModal activa modo edición', tab.showModal === true && tab.isEditing === true && tab.editingPartId === 1);
assert('4.2 openEditModal precarga los datos del repuesto',
  tab.formData.part_code === 'VALV-SOL-01' && tab.formData.reference_cost === 28.5);

// Modificar coste de referencia y notas
tab.formData.reference_cost = '29.90';
tab.formData.notes = 'Actualizado precio tarifa proveedor 2026';
await tab.savePart();

assert('4.3 savePart invoca api.coordinator.updateSparePart con ID 1', apiUpdatePayload?.id === 1);
assert('4.4 payload de actualización contiene nuevo coste y notas',
  apiUpdatePayload?.payload?.reference_cost === 29.90 &&
  apiUpdatePayload?.payload?.notes === 'Actualizado precio tarifa proveedor 2026');

// =========================================================================
// BLOQUE 5: Conmutador de Estado Activo/Inactivo (Baja Lógica / Reactivación)
// =========================================================================
console.log('\n--- BLOQUE 5: Conmutador de Estado Activo/Inactivo (Baja Lógica Art. III) ---');

// 5.1 Desactivar repuesto activo (VALV-SOL-01)
const activePart = tab.parts.find(p => p.part_code === 'VALV-SOL-01');
assert('5.1 Estado inicial del repuesto es activo', activePart.is_active === true);

await tab.toggleStatus(activePart);
assert('5.2 toggleStatus llama a la API con is_active: false',
  apiToggleStatusPayload?.id === activePart.id && apiToggleStatusPayload?.isActive === false);
assert('5.3 El estado local del repuesto pasa a false', activePart.is_active === false);
assert('5.4 Emite evento status-toggled indicando baja lógica',
  tab._emitted['status-toggled']?.some(e => e.id === activePart.id && e.is_active === false));

// 5.2 Reactivar repuesto desactivado (VALV-SOL-01)
await tab.toggleStatus(activePart);
assert('5.5 toggleStatus llama a la API con is_active: true',
  apiToggleStatusPayload?.id === activePart.id && apiToggleStatusPayload?.isActive === true);
assert('5.6 El estado local del repuesto vuelve a true', activePart.is_active === true);
assert('5.7 Emite evento status-toggled indicando reactivación',
  tab._emitted['status-toggled']?.some(e => e.id === activePart.id && e.is_active === true));

// =========================================================================
// BLOQUE 6: Formato y Cumplimiento con Directrices Visuales (Docs/design.md)
// =========================================================================
console.log('\n--- BLOQUE 6: Formato y Directrices Visuales docs/design.md ---');

// 6.1 Formato de moneda
assert('6.1 formatPrice formatea importe en euros correctamente (28,50 €)', tab.formatPrice(28.5) === '28,50 €');
assert('6.2 formatPrice maneja cero correctamente (0,00 €)', tab.formatPrice(0) === '0,00 €');

// 6.2 Etiquetas legibles de categorías técnicas
assert('6.3 getCategoryLabel traduce HYDRAULIC a "Hidráulica y Presión"', tab.getCategoryLabel('HYDRAULIC') === 'Hidráulica y Presión');
assert('6.4 getCategoryLabel traduce THERMAL a "Térmico y Refrigeración"', tab.getCategoryLabel('THERMAL') === 'Térmico y Refrigeración');
assert('6.5 getCategoryLabel traduce PAYMENT_SYSTEM a "Sistemas de Pago"', tab.getCategoryLabel('PAYMENT_SYSTEM') === 'Sistemas de Pago');

// 6.3 Navegación a analítica
tab.handleOpenAnalytics();
assert('6.6 handleOpenAnalytics emite evento open-analytics para cambio de pestaña',
  (tab._emitted['open-analytics'] || []).length > 0);

// 6.4 Verificación de tokens visuales en el template
const templateStr = CoordinatorSparePartsTab.template;
assert('6.7 Template incluye color primario #2560ff (Azul eléctrico Docker)', templateStr.includes('#2560ff'));
assert('6.8 Template incluye radio conservador de 4px en botones e inputs', templateStr.includes('4px'));
assert('6.9 Template incluye radio de 8px en tarjetas y modales', templateStr.includes('8px'));
assert('6.10 Template incluye data-testid para automatización de pruebas',
  templateStr.includes('data-testid="spare-parts-search-input"') &&
  templateStr.includes('data-testid="btn-open-create-part"') &&
  templateStr.includes('data-testid="spare-part-modal"'));

console.log('\n======================================================================');
console.log(` RESULTADOS TEST: ${assertions} aserciones ejecutadas.`);
console.log(` FALLOS: ${failures}`);
console.log('======================================================================');

if (failures > 0) {
  process.exit(1);
} else {
  console.log('✅ TODAS LAS PRUEBAS DE CoordinatorSparePartsTabTest.mjs HAN PASADO AL 100% EN VERDE.');
  process.exit(0);
}
