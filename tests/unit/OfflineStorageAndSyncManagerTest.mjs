/**
 * VendGuard - Frontend Unit Test Suite (OfflineStorageAndSyncManagerTest.mjs)
 *
 * Certifica el almacenamiento local y el motor de cola del Modo Sin Conexión
 * (Módulo 24, T-OFFLINE-13) sin navegador, sin red y sin dependencias npm:
 *
 *  - **Vigencia de la copia (D6, RF-02.2–02.4):** `FRESH` hasta 4 h, aviso ámbar
 *    entre 4 h y 12 h y bloqueo de nuevas acciones por encima de 12 h, con los
 *    umbrales exactos del contrato y una copia ausente o ilegible que nunca
 *    habilita el encolado.
 *  - **Cuota fotográfica (D3, QA 3, Art. V.5):** máximo 5 fotografías y 5 MB por
 *    archivo, con la firma digital fuera del cupo porque viaja en el payload y no
 *    como adjunto.
 *  - **Encadenamiento optimista (D5, EARS 03.3):** una parada conserva el
 *    `chain_started_at` del primer paso, refleja el estado final y descuenta el
 *    stock de la furgoneta; la reversión devuelve la cantidad y elimina la deuda
 *    provisional.
 *  - **Aislamiento de conflictos (QA 3, Art. III.3):** un rechazo de transición
 *    bloquea los pasos de estado posteriores de esa parada, pero no los
 *    comentarios intermedios ni las paradas ajenas; nada se borra en silencio.
 *  - **Volcado en un toque (QA 1, RF-05.3):** el conflicto se vuelca con su
 *    resumen de trabajo y solo desaparece de la bandeja tras la confirmación del
 *    servidor; no existe una vía de descarte silencioso.
 *  - **Bloqueo de cierre de sesión (D4, EARS 06.1):** con cola viva el técnico no
 *    puede salir de la aplicación, y con la bandeja vacía la copia local se
 *    limpia antes de cerrar.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

import {
  MUTATION_STATUS,
  OFFLINE_PHOTO_MAX_COUNT,
  OFFLINE_PHOTO_MAX_SINGLE_BYTES,
  OFFLINE_PHOTO_MAX_TOTAL_BYTES,
  OFFLINE_SIGNATURE_MAX_BYTES,
  OfflineQueueError,
  OfflineStorage,
  SNAPSHOT_VALIDITY,
  STOP_TYPE,
  evaluateSnapshotValidity,
  requiresSanitarySeal,
  validatePhotoAttachment
} from '../../public/assets/js/utils/OfflineStorage.js';
import {
  OfflineSyncManager,
  buildOfflineWorkSummary
} from '../../public/assets/js/utils/OfflineSyncManager.js';

globalThis.localStorage = {
  _store: {},
  getItem(key) { return this._store[key] ?? null; },
  setItem(key, value) { this._store[key] = String(value); },
  removeItem(key) { delete this._store[key]; },
  clear() { this._store = {}; }
};
globalThis.window = {
  location: { search: '', href: 'http://localhost/' },
  addEventListener() {},
  removeEventListener() {}
};

const NOW = Date.parse('2026-10-28T10:00:00.000Z');
const HOUR_MS = 60 * 60 * 1000;
const MB = 1024 * 1024;

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

function assertThrowsCode(description, operation, expectedCode) {
  try {
    const result = operation();
    if (result && typeof result.then === 'function') {
      return result.then(
        () => assert(description, false, 'No lanzó ninguna excepción.'),
        (error) => assert(
          description,
          error instanceof OfflineQueueError && error.code === expectedCode,
          `Código recibido: ${error?.code ?? error?.message ?? 'desconocido'}`
        )
      );
    }
    assert(description, false, 'No lanzó ninguna excepción.');
  } catch (error) {
    assert(
      description,
      error instanceof OfflineQueueError && error.code === expectedCode,
      `Código recibido: ${error?.code ?? error?.message ?? 'desconocido'}`
    );
  }

  return Promise.resolve();
}

function bootstrap(overrides = {}) {
  return {
    technician_id: 2,
    technician_email: 'jordi@vendguard.internal',
    technician_name: 'Jordi Técnico',
    synced_at: new Date(NOW).toISOString(),
    route: {
      stops: [
        { incident_id: 101, id: 101, status: 'ASSIGNED', machine_type: 'PERISHABLE_FOOD' },
        { incident_id: 102, id: 102, status: 'ASSIGNED', machine_type: 'COLD_DRINKS' }
      ],
      enabled_locations: [],
      pending_critical_stops_other_locations: []
    },
    preventive_orders: [{ id: 900, status: 'SCHEDULED', machine_type: 'COMBO' }],
    preventive_checklists_by_order: {},
    spare_parts_catalog_by_machine: {},
    van_stock: { items: [{ spare_part_id: 7, quantity_available: 10 }] },
    ...overrides
  };
}

function newStorage() {
  return new OfflineStorage({ indexedDB: null });
}

function photo(name, size) {
  return { name, type: 'image/jpeg', size, base64: 'AAAA' };
}

async function seedStorage(overrides = {}) {
  const storage = newStorage();
  await storage.saveSnapshot(bootstrap(overrides));

  return storage;
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - Offline storage and sync manager');
console.log('======================================================================\n');

console.log('--- 1. Vigencia de la copia local (D6, RF-02.2 a RF-02.4) ---');

const fresh = evaluateSnapshotValidity({ synced_at: new Date(NOW).toISOString() }, NOW + 4 * HOUR_MS);
assert('1.1 A las 4 h exactas la copia sigue FRESH y admite acciones', fresh.status === SNAPSHOT_VALIDITY.FRESH && fresh.canEnqueueNewActions === true);

const stale = evaluateSnapshotValidity({ synced_at: new Date(NOW).toISOString() }, NOW + 4 * HOUR_MS + 1);
assert(
  '1.2 A las 4 h + 1 ms pasa a STALE_WARNING con aviso al técnico',
  stale.status === SNAPSHOT_VALIDITY.STALE_WARNING
    && stale.canEnqueueNewActions === true
    && typeof stale.warningMessage === 'string'
    && stale.warningMessage.length > 0
);
assert('1.3 El aviso ámbar conserva la antigüedad en minutos', stale.ageMinutes === 240, `ageMinutes=${stale.ageMinutes}`);

const staleEdge = evaluateSnapshotValidity({ synced_at: new Date(NOW).toISOString() }, NOW + 12 * HOUR_MS);
assert('1.4 A las 12 h exactas todavía es STALE_WARNING (el corte es > 12 h)', staleEdge.status === SNAPSHOT_VALIDITY.STALE_WARNING);

const expired = evaluateSnapshotValidity({ synced_at: new Date(NOW).toISOString() }, NOW + 12 * HOUR_MS + 1);
assert(
  '1.5 Superadas las 12 h la copia es EXPIRED_READONLY y no admite acciones',
  expired.status === SNAPSHOT_VALIDITY.EXPIRED_READONLY
    && expired.canEnqueueNewActions === false
    && typeof expired.errorMessage === 'string'
    && expired.errorMessage.length > 0
);

const missing = evaluateSnapshotValidity(null, NOW);
assert('1.6 Sin copia local no se puede encolar nada', missing.status === SNAPSHOT_VALIDITY.MISSING && missing.canEnqueueNewActions === false);

const unparseable = evaluateSnapshotValidity({ synced_at: 'no-es-una-fecha' }, NOW);
assert(
  '1.7 Una fecha de sincronización ilegible bloquea por seguridad',
  unparseable.status === SNAPSHOT_VALIDITY.EXPIRED_READONLY && unparseable.canEnqueueNewActions === false
);

console.log('\n--- 2. Cuota fotográfica y firma digital (D3, QA 3, Art. V.5) ---');

assert('2.1 El cupo es de 5 fotografías', OFFLINE_PHOTO_MAX_COUNT === 5);
assert('2.2 Cada fotografía admite hasta 5 MB', OFFLINE_PHOTO_MAX_SINGLE_BYTES === 5 * MB);
assert('2.3 El total en cola admite hasta 25 MB', OFFLINE_PHOTO_MAX_TOTAL_BYTES === 25 * MB);
assert('2.4 La firma PNG tiene un límite propio y no consume el cupo fotográfico', OFFLINE_SIGNATURE_MAX_BYTES === 300 * 1024);

assert(
  '2.5 Una fotografía válida se normaliza',
  validatePhotoAttachment(photo('evidencia.jpg', MB), [])?.size === MB
);

assertThrowsCode(
  '2.6 Un formato no admitido se rechaza',
  () => validatePhotoAttachment({ name: 'evidencia.exe', type: 'application/x-msdownload', size: 1024 }, []),
  'OFFLINE_PHOTO_UNSUPPORTED_FORMAT'
);

assertThrowsCode(
  '2.7 Una fotografía vacía o mayor de 5 MB se rechaza',
  () => validatePhotoAttachment(photo('enorme.jpg', 5 * MB + 1), []),
  'OFFLINE_PHOTO_TOO_LARGE'
);

const fivePhotos = Array.from({ length: 5 }, (_, index) => ({
  photo_attachment: { size: MB, name: `foto-${index}.jpg` }
}));
assertThrowsCode(
  '2.8 La sexta fotografía supera el cupo de 5',
  () => validatePhotoAttachment(photo('sexta.jpg', 1024), fivePhotos),
  'OFFLINE_PHOTO_QUOTA_EXCEEDED'
);

const photoStorage = await seedStorage();
const photoManager = new OfflineSyncManager({
  storage: photoStorage,
  client: {},
  now: () => NOW,
  dispatch: () => {}
});

for (let index = 1; index <= 5; index++) {
  await photoManager.enqueue({
    technicianId: 2,
    stopType: STOP_TYPE.INCIDENT,
    stopId: 101,
    operationType: 'ADD_INCIDENT_COMMENT',
    payload: { body: `Comentario con evidencia ${index}` },
    photoFile: photo(`evidencia-${index}.jpg`, MB),
    machineType: 'PERISHABLE_FOOD'
  });
}

const photoSummary = await photoManager.getQueueSummary(2);
assert(
  '2.9 Las cinco fotografías se encolan y el resumen cuenta 5 MB',
  photoSummary.photoCount === 5 && photoSummary.photoBytes === 5 * MB,
  `photoCount=${photoSummary.photoCount}, photoBytes=${photoSummary.photoBytes}`
);

await assertThrowsCode(
  '2.10 El motor rechaza la sexta fotografía antes de persistirla',
  () => photoManager.enqueue({
    technicianId: 2,
    stopType: STOP_TYPE.INCIDENT,
    stopId: 101,
    operationType: 'ADD_INCIDENT_COMMENT',
    payload: { body: 'Comentario con una sexta foto' },
    photoFile: photo('sexta.jpg', 1024),
    machineType: 'PERISHABLE_FOOD'
  }),
  'OFFLINE_PHOTO_QUOTA_EXCEEDED'
);

const signedMutation = await photoManager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 101,
  operationType: 'RESOLVE_INCIDENT',
  payload: {
    diagnosis: 'Compresor bloqueado y sustituido.',
    action_taken: 'Se sustituye el compresor y se valida el frío.',
    service_sheet: {
      conformity_type: 'SIGNED',
      signer_name: 'Marta Sede',
      signature_png_base64: 'iVBORw0KGgo='.repeat(1024)
    }
  },
  machineType: 'PERISHABLE_FOOD'
});

const afterSignature = await photoManager.getQueueSummary(2);
assert(
  '2.11 La firma digital no consume cupo ni bytes fotográficos',
  signedMutation.payload.service_sheet.signature_png_base64.length > 0
    && afterSignature.photoCount === 5
    && afterSignature.photoBytes === 5 * MB
    && afterSignature.total === 6,
  `photoCount=${afterSignature.photoCount}, photoBytes=${afterSignature.photoBytes}, total=${afterSignature.total}`
);

console.log('\n--- 3. Encadenamiento optimista y stock provisional (D5, EARS 03.3) ---');

const chainStorage = await seedStorage();
const chainManager = new OfflineSyncManager({
  storage: chainStorage,
  client: {},
  now: () => NOW,
  dispatch: () => {}
});

const start = await chainManager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 101,
  operationType: 'START_INCIDENT',
  payload: {},
  machineType: 'PERISHABLE_FOOD'
});

const resolution = await chainManager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 101,
  operationType: 'RESOLVE_INCIDENT',
  payload: {
    diagnosis: 'Compresor bloqueado y sustituido.',
    action_taken: 'Se sustituye el compresor y se valida el frío.',
    replaced_parts: [{ spare_part_id: 7, quantity: 2 }]
  },
  machineType: 'PERISHABLE_FOOD'
});

assert(
  '3.1 El segundo paso de la misma parada hereda el chain_started_at del primero',
  resolution.chain_started_at === start.client_occurred_at,
  `start=${resolution.chain_started_at}, esperado=${start.client_occurred_at}`
);

const closedStart = await chainStorage.getMutation(start.client_mutation_id);
assert(
  '3.2 El inicio previo se cierra con el instante del paso final',
  closedStart?.chain_ended_at === resolution.client_occurred_at
);

const chainedSnapshot = await chainStorage.getSnapshot(2);
const chainedStop = chainedSnapshot.route_envelope.stops.find((stop) => Number(stop.incident_id) === 101);
assert('3.3 La parada refleja el estado final RESOLVED sin esperar al servidor', chainedStop?.status === 'RESOLVED');
assert(
  '3.4 El stock de furgoneta se descuenta de forma provisional',
  chainedSnapshot.van_stock.items[0].quantity_available === 8
    && Number(chainedSnapshot.provisional_stock_deductions['7']) === 2,
  `disponible=${chainedSnapshot.van_stock.items[0].quantity_available}`
);

await chainStorage.revertProvisionalStock(2, resolution.provisional_parts_deducted);
const revertedSnapshot = await chainStorage.getSnapshot(2);
assert(
  '3.5 La reversión devuelve la cantidad y limpia la deuda provisional',
  revertedSnapshot.van_stock.items[0].quantity_available === 10
    && Object.keys(revertedSnapshot.provisional_stock_deductions).length === 0,
  `disponible=${revertedSnapshot.van_stock.items[0].quantity_available}`
);

console.log('\n--- 4. Aislamiento por técnico (Art. V.3, Art. V.4) ---');

const isolationStorage = await seedStorage();
await isolationStorage.saveSnapshot(bootstrap({ technician_id: 3, technician_email: 'otro@vendguard.internal' }));
const isolationManager = new OfflineSyncManager({
  storage: isolationStorage,
  client: {},
  now: () => NOW,
  dispatch: () => {}
});

await isolationManager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 101,
  operationType: 'ADD_INCIDENT_COMMENT',
  payload: { body: 'Trabajo del técnico 2' }
});
await isolationManager.enqueue({
  technicianId: 3,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 102,
  operationType: 'ADD_INCIDENT_COMMENT',
  payload: { body: 'Trabajo del técnico 3' }
});

const tech2Queue = await isolationStorage.listMutations(2);
const tech3Queue = await isolationStorage.listMutations(3);
assert(
  '4.1 Cada bandeja contiene solo las operaciones de su técnico',
  tech2Queue.length === 1
    && tech3Queue.length === 1
    && tech2Queue[0].payload.body === 'Trabajo del técnico 2'
    && tech3Queue[0].payload.body === 'Trabajo del técnico 3'
);

await isolationStorage.clearTechnician(2);
assert(
  '4.2 Limpiar la copia de un técnico no toca la de otro en el mismo dispositivo',
  await isolationStorage.getSnapshot(2) === null
    && (await isolationStorage.listMutations(2)).length === 0
    && (await isolationStorage.listMutations(3)).length === 1
    && await isolationStorage.getSnapshot(3) !== null
);

console.log('\n--- 5. Precinto sanitario obligatorio (QA 1, EARS 03.4, Art. II) ---');

assert(
  '5.1 Una pausa en máquina perecedera exige precinto',
  requiresSanitarySeal('PAUSE_INCIDENT_PARTS', {}, 'PERISHABLE_FOOD') === true
    && requiresSanitarySeal('PAUSE_INCIDENT_PENDING_INFO', {}, 'COMBO') === true
);
assert(
  '5.2 La pausa en una máquina sin riesgo no exige precinto',
  requiresSanitarySeal('PAUSE_INCIDENT_PARTS', {}, 'COLD_DRINKS') === false
);
assert(
  '5.3 Una preventiva desfavorable exige precinto y una favorable no',
  requiresSanitarySeal('COMPLETE_PREVENTIVE', { items: [{ status: 'FAIL' }] }, 'PERISHABLE_FOOD') === true
    && requiresSanitarySeal('COMPLETE_PREVENTIVE', { items: [{ status: 'PASS' }] }, 'PERISHABLE_FOOD') === false
);

const sealStorage = await seedStorage();
const sealManager = new OfflineSyncManager({
  storage: sealStorage,
  client: {},
  now: () => NOW,
  dispatch: () => {}
});

await assertThrowsCode(
  '5.4 El motor no encola una pausa sin precinto confirmado',
  () => sealManager.enqueue({
    technicianId: 2,
    stopType: STOP_TYPE.INCIDENT,
    stopId: 101,
    operationType: 'PAUSE_INCIDENT_PARTS',
    payload: { pending_parts_reason: 'Compresor sin stock' },
    machineType: 'PERISHABLE_FOOD'
  }),
  'SANITARY_SEAL_CONFIRMATION_REQUIRED'
);

const sealedPause = await sealManager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 101,
  operationType: 'PAUSE_INCIDENT_PARTS',
  payload: { pending_parts_reason: 'Compresor sin stock' },
  machineType: 'PERISHABLE_FOOD',
  sanitarySealConfirmed: true
});
assert(
  '5.5 Con el precinto confirmado la pausa entra en la bandeja',
  sealedPause.sanitary_seal_confirmed === true && sealedPause.is_perishable_machine === true
);

console.log('\n--- 6. Conflictos aislados por parada y volcado obligatorio (QA 1, QA 3, Art. III) ---');

const conflictStorage = await seedStorage();
const requestCalls = [];
const dumpCalls = [];
const conflictManager = new OfflineSyncManager({
  storage: conflictStorage,
  client: {
    request: async (endpoint) => {
      requestCalls.push(endpoint);
      if (endpoint.includes('/incidents/101/start')) {
        throw Object.assign(new Error('La avería fue reasignada a otro compañero.'), {
          status: 409,
          code: 'INCIDENT_REASSIGNED'
        });
      }
      return { ok: true };
    },
    technician: {
      dumpOfflineConflict: async (payload, photoBlob, photoName) => {
        dumpCalls.push({ payload, photoBlob, photoName });
        return { status: 'DUMPED' };
      }
    }
  },
  now: () => NOW,
  dispatch: () => {}
});

const conflictingStart = await conflictManager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 101,
  operationType: 'START_INCIDENT',
  payload: { notes: 'Diagnóstico in situ: compresor bloqueado.' }
});
const intermediateComment = await conflictManager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 101,
  operationType: 'ADD_INCIDENT_COMMENT',
  payload: { body: 'Comentario intermedio con el responsable de sede.' }
});
const blockedPause = await conflictManager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 101,
  operationType: 'PAUSE_INCIDENT_PARTS',
  payload: { pending_parts_reason: 'Compresor sin stock' }
});
const otherStopStart = await conflictManager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 102,
  operationType: 'START_INCIDENT',
  payload: {}
});

const queueResult = await conflictManager.processOutboxQueue(2);

assert(
  '6.1 Un 409 no borra la operación: queda en CONFLICT para volcarla',
  (await conflictStorage.getMutation(conflictingStart.client_mutation_id))?.status === MUTATION_STATUS.CONFLICT
);

const sameStopConflict = await conflictStorage.getMutation(blockedPause.client_mutation_id);
assert(
  '6.2 El paso de estado posterior de la misma parada se bloquea sin llamar al servidor',
  sameStopConflict?.status === MUTATION_STATUS.CONFLICT && sameStopConflict?.conflict?.code === 'STATE_CHAIN_BLOCKED'
);

const commentAfterConflict = await conflictStorage.getMutation(intermediateComment.client_mutation_id);
assert(
  '6.3 El comentario intermedio se aplica pese al conflicto de la transición (QA 3)',
  commentAfterConflict === null
);

const otherStopAfterConflict = await conflictStorage.getMutation(otherStopStart.client_mutation_id);
assert(
  '6.4 El conflicto de una parada no arrastra a otra parada del mismo técnico',
  otherStopAfterConflict === null
);

assert(
  '6.5 El resumen del procesado cuenta 2 aplicadas, 2 conflictos y 0 pendientes',
  queueResult.applied === 2
    && queueResult.conflicts === 2
    && queueResult.pending === 0
    && queueResult.interrupted === null,
  JSON.stringify(queueResult)
);

assert(
  '6.6 La pausa bloqueada nunca llegó a enviarse y el comentario sí',
  requestCalls.length === 3
    && !requestCalls.some((endpoint) => endpoint.includes('/pause')),
  requestCalls.join(', ')
);

const workSummary = buildOfflineWorkSummary(conflictingStart);
assert(
  '6.7 El resumen de trabajo conserva el diagnóstico declarado in situ',
  typeof workSummary.notes === 'string' && workSummary.notes.includes('compresor bloqueado')
);

const dumpResult = await conflictManager.dumpConflict(conflictingStart.client_mutation_id);
assert(
  '6.8 El volcado en un toque envía el trabajo y el motivo del conflicto',
  dumpResult?.status === 'DUMPED'
    && dumpCalls.length === 1
    && dumpCalls[0].payload.client_mutation_id === conflictingStart.client_mutation_id
    && dumpCalls[0].payload.stop_id === 101
    && typeof dumpCalls[0].payload.conflict_reason === 'string'
    && dumpCalls[0].payload.conflict_reason.length > 0
    && dumpCalls[0].payload.work_summary.notes.includes('compresor bloqueado')
);

assert(
  '6.9 La operación solo sale de la bandeja tras confirmar el servidor',
  await conflictStorage.getMutation(conflictingStart.client_mutation_id) === null
);

const stillPending = await conflictManager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 102,
  operationType: 'ADD_INCIDENT_COMMENT',
  payload: { body: 'Comentario pendiente de la próxima reconexión.' }
});
await assertThrowsCode(
  '6.10 No se puede volcar una operación que sigue pendiente y no está en conflicto',
  () => conflictManager.dumpConflict(stillPending.client_mutation_id),
  'OFFLINE_MUTATION_NOT_IN_CONFLICT'
);
await assertThrowsCode(
  '6.11 Una operación ya ausente de la bandeja tampoco se puede volcar',
  () => conflictManager.dumpConflict(otherStopStart.client_mutation_id),
  'OFFLINE_MUTATION_NOT_FOUND'
);

assert(
  '6.12 No existe ninguna vía de descarte silencioso en el motor',
  typeof conflictManager.discardConflict === 'undefined'
    && typeof conflictManager.discard === 'undefined'
    && typeof conflictManager.dropConflict === 'undefined'
);

console.log('\n--- 7. Bloqueo de cierre de sesión con cola viva (D4, EARS 06.1, EARS 06.5) ---');

const { AppNavbar } = await import('../../public/assets/js/components/AppNavbar.js');
const { store, setInternalSession } = await import('../../public/assets/js/store.js');

function navbarInstance(offlineQueue, clearedIds = []) {
  const emitted = [];
  const instance = {
    offlineQueue,
    isTechnicianSession: true,
    user: { id: 2, role: 'TECHNICIAN' },
    logoutBlockedMessage: '',
    offlineStorage: {
      clearTechnician: async (technicianId) => { clearedIds.push(technicianId); }
    },
    $emit: (eventName, payload) => { emitted.push({ eventName, payload }); }
  };

  return { instance, emitted };
}

setInternalSession({ id: 2, name: 'Jordi Técnico', email: 'jordi@vendguard.internal', role: 'TECHNICIAN' }, 'token_offline');

const blocked = navbarInstance({ pending: 1, syncing: 1, conflicts: 1, total: 3 });
AppNavbar.methods.handleLogout.call(blocked.instance);

assert(
  '7.1 Con 3 operaciones vivas la sesión no se cierra',
  store.isAuthenticated === true && store.state.token === 'token_offline'
);
assert(
  '7.2 Se emite logout-blocked con el recuento para que la vista lo explique',
  blocked.emitted.some((entry) => entry.eventName === 'logout-blocked'
    && entry.payload.total === 3
    && entry.payload.pending === 1
    && entry.payload.conflicts === 1)
);
assert(
  '7.3 El mensaje detalla pendientes y conflictos sin ofrecer un descarte',
  blocked.instance.logoutBlockedMessage.includes('3 operación')
    && blocked.instance.logoutBlockedMessage.includes('1 pendiente')
    && blocked.instance.logoutBlockedMessage.includes('1 en conflicto')
);

const clearedIds = [];
const allowed = navbarInstance({ pending: 0, syncing: 0, conflicts: 0, total: 0 }, clearedIds);
setInternalSession({ id: 2, name: 'Jordi Técnico', email: 'jordi@vendguard.internal', role: 'TECHNICIAN' }, 'token_offline');
AppNavbar.methods.handleLogout.call(allowed.instance);
await Promise.resolve();

assert(
  '7.4 Con la bandeja vacía la sesión se cierra y se emite logout',
  store.isAuthenticated === false
    && store.state.token === null
    && allowed.emitted.some((entry) => entry.eventName === 'logout')
);
assert(
  '7.5 Al cerrar con la bandeja vacía se limpia la copia local del técnico',
  clearedIds.length === 1 && clearedIds[0] === 2,
  `clearedIds=${clearedIds.join(',')}`
);

console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. T-OFFLINE-13 STORAGE AND SYNC CERTIFIED.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
console.log('======================================================================\n');
process.exit(1);
