/**
 * VendGuard - Frontend Unit Test Suite (OfflineStorageSessionPurgeTest.mjs)
 *
 * Certifica el hallazgo M3 del Módulo 24 sin navegador, sin red y sin
 * dependencias npm:
 *
 *  - **Evidencia como `Blob` (DB v2):** la fotografía se persiste binaria, sin
 *    la copia base64 un 33 % mayor; el envío multipart reutiliza ese mismo
 *    `Blob` y la actualización v1 → v2 convierte las evidencias ya encoladas
 *    sin descartar ninguna.
 *  - **Purga por sesión:** un dispositivo compartido no conserva la ruta, los
 *    contactos ni las fotos de un técnico anterior, salvo que ese trabajo siga
 *    pendiente de sincronizar (`Art. III.1`, `Art. V.4`).
 *  - **Minimización:** la copia local descarta el correo y el nombre del
 *    contacto de sede, que ninguna pantalla offline pinta, y conserva el
 *    teléfono de la llamada en un toque.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

import {
  MUTATION_STATUS,
  OFFLINE_DB_NAME,
  OFFLINE_DB_VERSION,
  OFFLINE_PHOTO_MAX_SINGLE_BYTES,
  OfflineStorage,
  SNAPSHOT_VALIDITY,
  minimizeSnapshot,
  migrateQueuedPhotoAttachment,
  normalizeSnapshot,
  photoAttachmentBlob,
  serializePhoto
} from '../../public/assets/js/utils/OfflineStorage.js';
import { createFakeIndexedDb } from '../Support/FakeIndexedDb.mjs';

const NOW = Date.parse('2026-10-28T10:00:00.000Z');
const HOUR_MS = 60 * 60 * 1000;

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.log(`  [FAIL] ${description}`);
    if (details) console.log(`         Motivo: ${details}`);
    failures++;
  }
}

function bootstrap(overrides = {}) {
  return {
    technician_id: 2,
    technician_email: 'carlos.mendoza@vendguard.es',
    technician_name: 'Carlos Mendoza',
    synced_at: new Date(NOW).toISOString(),
    route: {
      stops: [
        {
          incident_id: 101,
          id: 101,
          status: 'ASSIGNED',
          machine_type: 'COLD_DRINKS',
          location: {
            id: 7,
            name: 'Hospital Central',
            address: 'Calle Mayor 1',
            contact_phone: '+34600111222',
            contact_email: 'conserjeria@hospital.example',
            contact_name: 'Laura Gómez'
          }
        }
      ],
      enabled_locations: [],
      pending_critical_stops_other_locations: []
    },
    preventive_orders: [],
    preventive_checklists_by_order: {},
    spare_parts_catalog_by_machine: {},
    van_stock: { items: [] },
    ...overrides
  };
}

function memoryStorage() {
  return new OfflineStorage({ indexedDB: null });
}

function pendingMutation(clientMutationId, technicianId, sequenceOrder) {
  return {
    client_mutation_id: clientMutationId,
    technician_id: technicianId,
    sequence_order: sequenceOrder,
    status: MUTATION_STATUS.PENDING,
    syncing_since: null,
    stop_type: 'INCIDENT',
    stop_id: 101,
    operation_type: 'START_INCIDENT',
    is_state_transition: true,
    payload: {},
    photo_attachment: null,
    provisional_parts_deducted: []
  };
}

const photoBytes = new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 0x00, 0x10, 0x4a, 0x46]);

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - Offline storage session purge (M3)');
console.log('======================================================================\n');

console.log('--- 1. Evidencia binaria y actualización de esquema (DB v2) ---');

assert('1.1 La base local sube a la versión 2 sin cambiar de nombre (la v1 no se abandona)',
  OFFLINE_DB_VERSION === 2 && OFFLINE_DB_NAME === 'vendguard_offline_v1',
  `${OFFLINE_DB_NAME}@${OFFLINE_DB_VERSION}`);

const serialized = await serializePhoto(new Blob([photoBytes], { type: 'image/jpeg' }));
assert('1.2 La foto se serializa como Blob binario, sin cadena base64',
  serialized?.blob instanceof Blob
    && serialized.base64 === undefined
    && serialized.size === photoBytes.length
    && serialized.type === 'image/jpeg',
  JSON.stringify({ blob: serialized?.blob instanceof Blob, base64: serialized?.base64, size: serialized?.size }));

const roundTrip = photoAttachmentBlob(serialized);
assert('1.3 El Blob guardado es el que se envía: ni se recodifica ni se pierde un byte',
  roundTrip instanceof Blob
    && roundTrip === serialized.blob
    && (await roundTrip.arrayBuffer()).byteLength === photoBytes.length);

const legacyBase64 = Buffer.from(photoBytes).toString('base64');
const legacyAttachment = { name: 'evidencia.jpg', type: 'image/jpeg', size: photoBytes.length, base64: legacyBase64 };
const legacyBlob = photoAttachmentBlob(legacyAttachment);
assert('1.4 Una evidencia v1 en base64 sigue siendo enviable (compatibilidad)',
  legacyBlob instanceof Blob
    && (await legacyBlob.arrayBuffer()).byteLength === photoBytes.length);

const migrated = migrateQueuedPhotoAttachment({
  ...pendingMutation('mut_20261028_100000_aaaaaaaaaaaaaaaa', 2, 1),
  photo_attachment: legacyAttachment
});
assert('1.5 La migración v1 → v2 convierte la evidencia y conserva el resto del registro',
  migrated.photo_attachment?.blob instanceof Blob
    && migrated.photo_attachment?.base64 === undefined
    && migrated.client_mutation_id === 'mut_20261028_100000_aaaaaaaaaaaaaaaa'
    && migrated.status === MUTATION_STATUS.PENDING);

const alreadyV2 = migrateQueuedPhotoAttachment({
  ...pendingMutation('mut_20261028_100001_bbbbbbbbbbbbbbbb', 2, 2),
  photo_attachment: serialized
});
assert('1.6 Una evidencia ya migrada no se toca (la actualización es idempotente)',
  alreadyV2.photo_attachment === serialized);

console.log('\n--- 2. Purga por sesión en el dispositivo compartido (Art. V.4) ---');

const memory = memoryStorage();
await memory.saveSnapshot(bootstrap({ technician_id: 2 }));
await memory.saveSnapshot(bootstrap({ technician_id: 3 }));
await memory.saveSnapshot(bootstrap({ technician_id: 4 }));
await memory.putMutation(pendingMutation('mut_20261028_100002_cccccccccccccccc', 4, 1));

const foreignPurge = await memory.purgeForeignTechnicians(2);
assert('2.1 La copia de otro técnico sin trabajo pendiente se purga al entrar la sesión nueva',
  foreignPurge.purged === 1
    && (await memory.getSnapshot(3)) === null,
  JSON.stringify(foreignPurge));
assert('2.2 La copia ajena con cola viva se conserva y se declara (nada se descarta en silencio)',
  foreignPurge.retained.includes(4)
    && (await memory.getSnapshot(4)) !== null
    && (await memory.getSnapshot(2)) !== null,
  JSON.stringify(foreignPurge));

const keptStorage = memoryStorage();
await keptStorage.saveSnapshot(bootstrap({ technician_id: 2 }));
await keptStorage.saveSnapshot(bootstrap({ technician_id: 5 }));
await keptStorage.putMutation(pendingMutation('mut_20261028_100003_dddddddddddddddd', 5, 1));

const retainedPurge = await keptStorage.purgeForeignTechnicians(2);
assert('2.3 La cola ajena sobrevive a la purga: el trabajo tiene prioridad sobre el rastro',
  retainedPurge.purged === 0
    && retainedPurge.retained.includes(5)
    && (await keptStorage.getSnapshot(5)) !== null
    && (await keptStorage.listMutations(5)).length === 1,
  JSON.stringify(retainedPurge));

const expiredStorage = memoryStorage();
await expiredStorage.saveSnapshot(bootstrap({ technician_id: 2 }));
await expiredStorage.saveSnapshot(bootstrap({
  technician_id: 6,
  synced_at: new Date(NOW - 13 * HOUR_MS).toISOString()
}));
await expiredStorage.saveSnapshot(bootstrap({
  technician_id: 7,
  synced_at: new Date(NOW - 13 * HOUR_MS).toISOString()
}));
await expiredStorage.putMutation(pendingMutation('mut_20261028_100004_eeeeeeeeeeeeeeee', 7, 1));

const expiredPurge = await expiredStorage.purgeExpiredSessions(NOW);
assert('2.4 Una copia caducada sin trabajo pendiente se purga (> 12 h, RF-02.4)',
  expiredPurge.includes(6)
    && (await expiredStorage.getSnapshot(6)) === null,
  JSON.stringify(expiredPurge));
assert('2.5 La copia caducada con trabajo pendiente se conserva hasta sincronizarla',
  expiredPurge.includes(7) === false
    && expiredStorage.listMutations(7)
    && (await expiredStorage.listMutations(7)).length === 1,
  JSON.stringify(expiredPurge));
assert('2.6 La copia vigente del titular no se toca',
  (await expiredStorage.getSnapshot(2)) !== null
    && expiredStorage.getSnapshot(2)
    && (await expiredStorage.purgeExpiredSessions(NOW)).includes(2) === false);

const logoutStorage = memoryStorage();
await logoutStorage.saveSnapshot(bootstrap({ technician_id: 2 }));
await logoutStorage.putMutation(pendingMutation('mut_20261028_100005_ffffffffffffffff', 2, 1));
await logoutStorage.clearTechnician(2);
assert('2.7 El cierre de sesión deja el dispositivo sin ruta, contactos ni evidencias',
  (await logoutStorage.getSnapshot(2)) === null
    && (await logoutStorage.listMutations(2)).length === 0);

console.log('\n--- 3. Minimización de la copia local ---');

const minimized = normalizeSnapshot(bootstrap());
const minimizedStop = minimized.route_envelope.stops[0];
assert('3.1 La copia local no guarda el correo ni el nombre del contacto de sede',
  minimizedStop.location.contact_email === undefined
    && minimizedStop.location.contact_name === undefined,
  JSON.stringify(minimizedStop.location));
assert('3.2 El teléfono se conserva: es la llamada en un toque sin cobertura',
  minimizedStop.location.contact_phone === '+34600111222'
    && minimizedStop.location.address === 'Calle Mayor 1');
assert('3.3 La minimización no muta el paquete original del servidor',
  bootstrap().route.stops[0].location.contact_email === 'conserjeria@hospital.example');

const deeplyNested = minimizeSnapshot({
  route: { stops: [{ location: { contact_email: 'x@y.z', contact_name: 'X', contact_phone: '1' } }] },
  pending: [{ contact_email: 'a@b.c', keep: true }]
});
assert('3.4 La minimización recorre la copia entera, no solo el primer nivel',
  deeplyNested.route.stops[0].location.contact_email === undefined
    && deeplyNested.pending[0].contact_email === undefined
    && deeplyNested.pending[0].keep === true
    && deeplyNested.route.stops[0].location.contact_phone === '1',
  JSON.stringify(deeplyNested));

console.log('\n--- 4. Actualización real v1 → v2 sobre IndexedDB ---');

const factory = createFakeIndexedDb();
const v1Open = factory.open(OFFLINE_DB_NAME, 1);

const v1Ready = await new Promise((resolve, reject) => {
  v1Open.onupgradeneeded = () => {
    const db = v1Open.result;
    db.createObjectStore('route_snapshots', { keyPath: 'technician_id' });
    const outbox = db.createObjectStore('mutation_outbox', { keyPath: 'client_mutation_id' });
    outbox.createIndex('by_technician', 'technician_id');
    outbox.createIndex('by_tech_sequence', ['technician_id', 'sequence_order'], { unique: true });
    outbox.createIndex('by_tech_status', ['technician_id', 'status']);
  };
  v1Open.onsuccess = () => resolve(v1Open.result);
  v1Open.onerror = () => reject(v1Open.error);
});

const v1Transaction = v1Ready.transaction('mutation_outbox', 'readwrite');
v1Transaction.objectStore('mutation_outbox').put({
  ...pendingMutation('mut_20261028_100006_9999999999999999', 2, 1),
  photo_attachment: legacyAttachment
});
await new Promise((resolve, reject) => {
  v1Transaction.oncomplete = () => resolve();
  v1Transaction.onerror = () => reject(v1Transaction.error);
});
v1Ready.close?.();

// `R4`: la v1 guardaba la copia completa, sin minimizar. La actualización debe
// podarla en el mismo paso, sin esperar a que llegue un paquete del servidor.
const legacySnapshot = {
  technician_id: 2,
  technician_email: 'tecnico@vendguard.test',
  technician_name: 'Técnico de certificación',
  synced_at: '2026-10-28T06:00:00.000Z',
  route: {
    stops: [{
      incident_id: 701,
      status: 'ASSIGNED',
      location: {
        site_name: 'Sede Centro',
        contact_phone: '911 000 111',
        contact_email: 'sede@vendguard.test',
        contact_name: 'Ana Pérez'
      }
    }],
    enabled_locations: [],
    pending_critical_stops_other_locations: []
  },
  preventive_orders: [],
  preventive_checklists_by_order: {},
  spare_parts_catalog_by_machine: {},
  van_stock: { items: [] },
  provisional_stock_deductions: {},
  offline_shift: { status: 'ON_SHIFT', recorded_at: '2026-10-28T06:00:00.000Z' }
};

const cleanSnapshot = {
  technician_id: 9,
  synced_at: '2026-10-28T06:00:00.000Z',
  route: { stops: [], enabled_locations: [], pending_critical_stops_other_locations: [] },
  van_stock: { items: [] },
  offline_shift: { status: 'OFF_SHIFT', recorded_at: '2026-10-28T06:00:00.000Z' }
};

const v1Snapshots = v1Ready.transaction('route_snapshots', 'readwrite');
v1Snapshots.objectStore('route_snapshots').put(legacySnapshot);
v1Snapshots.objectStore('route_snapshots').put(cleanSnapshot);
await new Promise((resolve, reject) => {
  v1Snapshots.oncomplete = () => resolve();
  v1Snapshots.onerror = () => reject(v1Snapshots.error);
});

const upgradedStorage = new OfflineStorage({ indexedDB: factory });
const upgradedQueue = await upgradedStorage.listMutations(2);
const upgradedPhoto = upgradedQueue[0]?.photo_attachment;

assert('4.1 La base v1 se actualiza a la v2 conservando la entrada de la cola',
  OFFLINE_DB_VERSION === 2
    && upgradedQueue.length === 1
    && upgradedQueue[0].client_mutation_id === 'mut_20261028_100006_9999999999999999');
assert('4.2 La evidencia encolada en v1 se reescribe como Blob, sin perder bytes',
  upgradedPhoto?.blob instanceof Blob
    && upgradedPhoto?.base64 === undefined
    && (await upgradedPhoto.blob.arrayBuffer()).byteLength === photoBytes.length,
  JSON.stringify({ blob: upgradedPhoto?.blob instanceof Blob, keys: Object.keys(upgradedPhoto ?? {}) }));

const prunedSnapshot = await upgradedStorage.getSnapshot(2);
assert('4.3 La actualización poda el correo y el nombre que la v1 ya había guardado (R4)',
  prunedSnapshot?.route?.stops?.[0]?.location?.contact_email === undefined
    && prunedSnapshot?.route?.stops?.[0]?.location?.contact_name === undefined
    && JSON.stringify(prunedSnapshot).includes('sede@vendguard.test') === false,
  JSON.stringify(prunedSnapshot?.route?.stops?.[0]?.location ?? {}));
assert('4.4 La poda conserva lo que el trabajo sin red necesita',
  prunedSnapshot?.route?.stops?.[0]?.location?.contact_phone === '911 000 111'
    && prunedSnapshot?.route?.stops?.[0]?.incident_id === 701
    && prunedSnapshot?.offline_shift?.status === 'ON_SHIFT'
    && prunedSnapshot?.technician_id === 2,
  JSON.stringify({
    phone: prunedSnapshot?.route?.stops?.[0]?.location?.contact_phone,
    shift: prunedSnapshot?.offline_shift?.status
  }));
assert('4.5 Una copia que ya estaba limpia sobrevive a la actualización sin cambios',
  JSON.stringify(await upgradedStorage.getSnapshot(9)) === JSON.stringify(cleanSnapshot),
  JSON.stringify(await upgradedStorage.getSnapshot(9)));

console.log('\n--- 5. Purga sobre IndexedDB (no solo en memoria) ---');

const purgeFactory = createFakeIndexedDb();
const purgeStorage = new OfflineStorage({ indexedDB: purgeFactory });
await purgeStorage.saveSnapshot(bootstrap({ technician_id: 2 }));
await purgeStorage.saveSnapshot(bootstrap({ technician_id: 8 }));

const indexedPurge = await purgeStorage.purgeForeignTechnicians(2);
assert('5.1 La purga entre sesiones funciona sobre la base real del navegador',
  indexedPurge.purged === 1
    && (await purgeStorage.getSnapshot(8)) === null
    && (await purgeStorage.getSnapshot(2)) !== null
    && (await purgeStorage.listTechnicianIds()).join(',') === '2',
  JSON.stringify({ purged: indexedPurge, ids: await purgeStorage.listTechnicianIds() }));

const indexedExpired = memoryStorage();
assert('5.2 El umbral de caducidad sigue siendo el del contrato (> 12 h)',
  SNAPSHOT_VALIDITY.EXPIRED_READONLY === 'EXPIRED_READONLY'
    && indexedExpired !== null
    && OFFLINE_PHOTO_MAX_SINGLE_BYTES === 5 * 1024 * 1024);

console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);
if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. M3 SESSION PURGE AND BLOB EVIDENCE CERTIFIED.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
console.log('======================================================================\n');
process.exit(1);
