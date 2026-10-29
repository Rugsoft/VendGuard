/**
 * VendGuard · Atomicidad del alta de cola y de la copia local (H4, M2)
 *
 * Certifica contra un IndexedDB con transacciones reales (doble propio, sin
 * dependencias) las dos invariantes que el respaldo en memoria no puede probar:
 *
 *  1. **H4 — el alta y su reflejo optimista son indivisibles.** Un fallo en
 *     cualquier punto no deja ni cambio local sin cola ni cola sin cambio; y dos
 *     altas concurrentes que leyeron el mismo turno chocan en el índice único y
 *     la perdedora reintenta con el siguiente, sin perder su registro.
 *  2. **M2 — la copia se modifica en transacción.** Veinte escrituras
 *     concurrentes no se pisan, y un turno y una proyección simultáneos sobre la
 *     misma copia conservan los dos efectos.
 *
 * Uso: `node tests/unit/OfflineStorageAtomicEnqueueTest.mjs`
 */

import {
  MUTATION_STATUS,
  OfflineStorage,
  STOP_TYPE,
  applyOptimisticStopChange,
  revertProvisionalStockChange
} from '../../public/assets/js/utils/OfflineStorage.js';
import { createFakeIndexedDb } from '../Support/FakeIndexedDb.mjs';

const NOW = Date.parse('2026-10-29T09:00:00.000Z');
let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
    return;
  }

  console.error(`  [FAIL] ${description}`);
  if (details) {
    console.error(`         Motivo: ${details}`);
  }
  failures++;
}

function bootstrap(overrides = {}) {
  return {
    technician_id: 2,
    technician_email: 'jordi@vendguard.internal',
    technician_name: 'Jordi Técnico',
    synced_at: new Date(NOW).toISOString(),
    route: {
      stops: [
        { incident_id: 101, id: 101, status: 'ASSIGNED', machine_type: 'COLD_DRINKS' },
        { incident_id: 102, id: 102, status: 'ASSIGNED', machine_type: 'COLD_DRINKS' }
      ],
      enabled_locations: [],
      pending_critical_stops_other_locations: []
    },
    preventive_orders: [],
    preventive_checklists_by_order: {},
    spare_parts_catalog_by_machine: {},
    van_stock: { items: [{ spare_part_id: 7, quantity_available: 10 }] },
    ...overrides
  };
}

function mutationRecord(sequenceOrder, overrides = {}) {
  return {
    client_mutation_id: `mut_test_${sequenceOrder}_${Math.random().toString(16).slice(2, 8)}`,
    technician_id: 2,
    technician_email: 'jordi@vendguard.internal',
    sequence_order: sequenceOrder,
    status: MUTATION_STATUS.PENDING,
    stop_type: STOP_TYPE.INCIDENT,
    stop_id: 101,
    operation_type: 'START_INCIDENT',
    is_state_transition: true,
    http_method: 'PATCH',
    endpoint: '/technician/incidents/101/start',
    client_occurred_at: new Date(NOW).toISOString(),
    chain_started_at: null,
    chain_ended_at: null,
    sanitary_seal_confirmed: false,
    payload: {},
    photo_attachment: null,
    provisional_parts_deducted: [],
    conflict: null,
    ...overrides
  };
}

function newStorage(factory) {
  return new OfflineStorage({ indexedDB: factory });
}

async function seeded(factory) {
  const storage = newStorage(factory);
  await storage.saveSnapshot(bootstrap());

  return storage;
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - Atomic queue enqueue and snapshot writes');
console.log('======================================================================\n');

// ---------------------------------------------------------------------------
console.log('--- 1. El alta y su proyección son indivisibles (H4) ---');

const factory1 = createFakeIndexedDb();
const storage1 = await seeded(factory1);

const first = await storage1.enqueueMutation(
  2,
  ({ sequenceOrder }) => mutationRecord(sequenceOrder, { client_mutation_id: 'mut_atomic_001' })
);

const queuedAfterFirst = await storage1.listMutations(2);
const snapshotAfterFirst = await storage1.getSnapshot(2);
const projectedStop = snapshotAfterFirst.route_envelope.stops
  .find((stop) => Number(stop.incident_id) === 101);

assert(
  '1.1 El alta entra en la cola con el primer turno',
  first.sequence_order === 1 && queuedAfterFirst.length === 1,
  `order=${first.sequence_order}, cola=${queuedAfterFirst.length}`
);
assert(
  '1.2 La proyección optimista queda en la misma copia',
  projectedStop?.status === 'IN_PROGRESS',
  `estado=${projectedStop?.status}`
);

// Un fallo del constructor de la mutación aborta la transacción entera.
let aborted = null;
try {
  await storage1.enqueueMutation(2, () => {
    throw new Error('fallo simulado del alta');
  });
} catch (error) {
  aborted = error;
}

const queueAfterAbort = await storage1.listMutations(2);
const snapshotAfterAbort = await storage1.getSnapshot(2);
assert(
  '1.3 Un alta abortada no deja registro ni cambio local',
  aborted !== null
    && queueAfterAbort.length === 1
    && snapshotAfterAbort.route_envelope.stops.find((stop) => Number(stop.incident_id) === 101)?.status === 'IN_PROGRESS',
  `excepción=${aborted?.message ?? 'ninguna'}, cola=${queueAfterAbort.length}`
);

// Dos pestañas que leyeron el mismo turno: la segunda choca en el índice único
// y el reintento le asigna el siguiente.
const factory2 = createFakeIndexedDb();
const tabA = await seeded(factory2);
const tabB = newStorage(factory2);

const [tabAMutation, tabBMutation] = await Promise.all([
  tabA.enqueueMutation(2, ({ sequenceOrder }) => mutationRecord(sequenceOrder, {
    client_mutation_id: 'mut_tab_a_001'
  })),
  tabB.enqueueMutation(2, ({ sequenceOrder }) => mutationRecord(sequenceOrder, {
    client_mutation_id: 'mut_tab_b_001',
    stop_id: 102
  }))
]);

const crossTabQueue = await tabA.listMutations(2);
const orders = crossTabQueue.map((item) => Number(item.sequence_order)).sort((left, right) => left - right);
assert(
  '1.4 Dos altas concurrentes en pestañas distintas conservan las dos filas',
  crossTabQueue.length === 2
    && orders[0] === 1
    && orders[1] === 2,
  `cola=${crossTabQueue.length}, turnos=${orders.join(',')}`
);
assert(
  '1.5 La perdedora de la carrera se queda con el turno siguiente, no con un error',
  tabBMutation.sequence_order === 2 || tabAMutation.sequence_order === 2,
  `a=${tabAMutation.sequence_order}, b=${tabBMutation.sequence_order}`
);

// ---------------------------------------------------------------------------
console.log('\n--- 2. La copia se lee-modifica-escribe en transacción (M2) ---');

const factory3 = createFakeIndexedDb();
const storage3 = await seeded(factory3);

await Promise.all(Array.from({ length: 20 }, () => storage3.mutateSnapshot(2, (snapshot) => ({
  ...snapshot,
  counter: Number(snapshot?.counter ?? 0) + 1
}))));

const afterIncrements = await storage3.getSnapshot(2);
assert(
  '2.1 Veinte escrituras concurrentes no pierden ninguna',
  afterIncrements.counter === 20,
  `contador=${afterIncrements.counter}`
);

// Turno y proyección a la vez: cada una lee la copia de la otra, no la inicial.
await Promise.all([
  storage3.saveShiftStatus(2, 'ON_SHIFT', NOW),
  storage3.applyOptimisticMutation(2, {
    stop_type: STOP_TYPE.INCIDENT,
    stop_id: 102,
    operation_type: 'START_INCIDENT',
    payload: {}
  })
]);

const afterMixed = await storage3.getSnapshot(2);
const secondStop = afterMixed.route_envelope.stops.find((stop) => Number(stop.incident_id) === 102);
assert(
  '2.2 Un turno y una proyección simultáneos conservan los dos efectos',
  afterMixed.offline_shift?.status === 'ON_SHIFT' && secondStop?.status === 'IN_PROGRESS',
  `turno=${afterMixed.offline_shift?.status}, parada=${secondStop?.status}`
);

// La reversión de stock también participa de la misma puerta.
await storage3.mutateSnapshot(2, (snapshot) => applyOptimisticStopChange(snapshot, {
  stopType: STOP_TYPE.INCIDENT,
  stopId: 102,
  operationType: 'RESOLVE_INCIDENT',
  payload: { replaced_parts: [{ spare_part_id: 7, quantity: 2 }] }
}));
const afterDeduction = await storage3.getSnapshot(2);
const deducted = afterDeduction.van_stock.items.find((item) => Number(item.spare_part_id) === 7);

await storage3.revertProvisionalStock(2, [{ spare_part_id: 7, quantity: 2 }]);
const afterRevert = await storage3.getSnapshot(2);
const restored = afterRevert.van_stock.items.find((item) => Number(item.spare_part_id) === 7);

assert(
  '2.3 La deducción y la reversión de stock cuadran sobre la copia transaccional',
  deducted?.quantity_available === 8 && restored?.quantity_available === 10,
  `tras descuento=${deducted?.quantity_available}, tras reversión=${restored?.quantity_available}`
);

// El mutador asíncrono se rechaza en vez de romper la transacción en silencio.
let asyncMutatorError = null;
try {
  await storage3.mutateSnapshot(2, async (snapshot) => snapshot);
} catch (error) {
  asyncMutatorError = error;
}
assert(
  '2.4 Un mutador asíncrono se rechaza con código propio',
  asyncMutatorError?.code === 'SNAPSHOT_MUTATOR_ASYNC',
  `código=${asyncMutatorError?.code ?? 'ninguno'}`
);

// La copia sigue intacta tras el rechazo.
const afterAsyncReject = await storage3.getSnapshot(2);
assert(
  '2.5 El rechazo del mutador asíncrono no altera la copia',
  afterAsyncReject.counter === 20 && afterAsyncReject.offline_shift?.status === 'ON_SHIFT'
);

// ---------------------------------------------------------------------------
console.log('\n--- 3. Rollback de la proyección revierte el descuento provisional ---');

const factory4 = createFakeIndexedDb();
const storage4 = await seeded(factory4);

await storage4.mutateSnapshot(2, (snapshot) => revertProvisionalStockChange(snapshot, []));
const untouched = await storage4.getSnapshot(2);
assert(
  '3.1 Una reversión vacía no toca la copia',
  untouched.van_stock.items.find((item) => Number(item.spare_part_id) === 7)?.quantity_available === 10
);

console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);
console.log(
  failures === 0
    ? ' RESULT: 100% IN GREEN. T-OFFLINE-14 ATOMIC STORAGE CERTIFIED.'
    : ' RESULT: FAILURES DETECTED IN ATOMIC STORAGE.'
);
console.log('======================================================================');

process.exit(failures === 0 ? 0 : 1);
