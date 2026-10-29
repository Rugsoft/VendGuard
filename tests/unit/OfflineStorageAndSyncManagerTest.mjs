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
 *  - **Rescate de envíos interrumpidos (EARS 06.1, RF-04.2):** un elemento que
 *    quedó en `SYNCING` porque la pestaña murió vuelve a la cola; uno que está
 *    siendo enviado por otra pestaña en ese momento no se toca.
 *  - **Veredictos 4xx (RF-04.1, RF-05.1):** un rechazo definitivo del servidor
 *    (`404`, `400`, `410`, `413`) queda aislado como conflicto y no interrumpe
 *    el resto del FIFO; solo `408`, `425`, `429` y los `5xx` se reintentan.
 *  - **Coordinación entre pestañas (RF-04.1, EARS 06.1):** dos pestañas del
 *    mismo técnico nunca vacían la cola a la vez (`navigator.locks` más un
 *    mutex local), y la que cambia la bandeja avisa a la otra por
 *    `BroadcastChannel` para que relea su estado sin ecos.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network in tests.
 */

import {
  MUTATION_STATUS,
  OFFLINE_OPERATIONS,
  OFFLINE_PHOTO_MAX_COUNT,
  OFFLINE_PHOTO_MAX_SINGLE_BYTES,
  OFFLINE_PHOTO_MAX_TOTAL_BYTES,
  OFFLINE_SIGNATURE_MAX_BYTES,
  OfflineQueueError,
  OfflineStorage,
  SNAPSHOT_VALIDITY,
  STOP_TYPE,
  evaluateOfflineShift,
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

/**
 * Evidencia de prueba con su `Blob` real del tamaño pedido.
 *
 * Desde la DB v2 la foto se persiste binaria y su tamaño es el del archivo, no
 * el declarado: un doble con `base64` de cuatro caracteres ya no representa una
 * foto de 1 MB y falsearía la cuota.
 */
function photo(name, size) {
  return { name, type: 'image/jpeg', size, blob: new Blob([new Uint8Array(size)], { type: 'image/jpeg' }) };
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

let encodedReads = 0;
await assertThrowsCode(
  '2.12 Una foto que excede el límite se rechaza sin leer sus bytes (B2)',
  () => photoManager.enqueue({
    technicianId: 2,
    stopType: STOP_TYPE.INCIDENT,
    stopId: 101,
    operationType: 'ADD_INCIDENT_COMMENT',
    payload: { body: 'Comentario con una foto enorme' },
    photoFile: {
      name: 'enorme.jpg',
      type: 'image/jpeg',
      size: 6 * MB,
      arrayBuffer: async () => {
        encodedReads++;

        return new ArrayBuffer(0);
      }
    },
    machineType: 'PERISHABLE_FOOD'
  }),
  'OFFLINE_PHOTO_TOO_LARGE'
);
assert(
  '2.13 El codificador no lee la evidencia rechazada',
  encodedReads === 0,
  `lecturas=${encodedReads}`
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

console.log('\n--- 8. Copia automática del paquete y catálogo offline (EARS 02.1, 03.1) ---');

const refreshStorage = newStorage();
let bootstrapRequests = 0;
const refreshManager = new OfflineSyncManager({
  storage: refreshStorage,
  client: {
    technician: {
      async getOfflineBootstrap() {
        bootstrapRequests++;
        return bootstrap({
          technician_id: 4,
          technician_email: 'nuria@vendguard.internal',
          technician_name: 'Nuria Técnica',
          synced_at: new Date(NOW).toISOString()
        });
      }
    }
  },
  now: () => NOW
});

const refreshed = await refreshManager.refreshSnapshot(4);
const persisted = await refreshStorage.getSnapshot(4);

assert(
  '8.1 Al cargar con red se descarga el paquete consolidado y se guarda para el técnico (EARS 02.1)',
  bootstrapRequests === 1
    && refreshed.snapshot?.technician_id === 4
    && persisted?.technician_id === 4
    && persisted?.technician_email === 'nuria@vendguard.internal'
);
assert(
  '8.2 La copia guardada incluye ruta, preventivas, catálogo y furgoneta, no solo las paradas',
  Array.isArray(persisted?.route_envelope?.stops)
    && persisted.route_envelope.stops.length === 2
    && Array.isArray(persisted?.preventive_orders)
    && Object.prototype.hasOwnProperty.call(persisted, 'spare_parts_catalog_by_machine')
    && Array.isArray(persisted?.van_stock?.items)
);
assert(
  '8.3 La copia recién guardada nace FRESH y admite acciones sin conexión',
  refreshed.validity?.status === SNAPSHOT_VALIDITY.FRESH
    && refreshed.validity?.canEnqueueNewActions === true
);

const allowedOperations = Object.keys(OFFLINE_OPERATIONS).sort();
const expectedOperations = [
  'ADD_INCIDENT_COMMENT',
  'COMPLETE_PREVENTIVE',
  'PAUSE_INCIDENT_PARTS',
  'PAUSE_INCIDENT_PENDING_INFO',
  'REINSPECT_PREVENTIVE',
  'RESOLVE_INCIDENT',
  'RESUME_INCIDENT_PENDING_INFO',
  'START_INCIDENT',
  'START_PREVENTIVE'
].sort();

assert(
  '8.4 La cola sólo admite las nueve operaciones de parada del contrato (EARS 03.1)',
  JSON.stringify(allowedOperations) === JSON.stringify(expectedOperations),
  allowedOperations.join(',')
);
assert(
  '8.5 Las operaciones que exigen red en vivo no tienen variante offline (EARS 03.2)',
  !allowedOperations.some((code) => /CLAIM|RESTOCK|SHIFT|LOGIN|OPPORTUNISTIC/.test(code))
);
assert(
  '8.6 Cada operación declara su tipo de parada, método y ruta reales',
  OFFLINE_OPERATIONS.START_INCIDENT.stopType === STOP_TYPE.INCIDENT
    && OFFLINE_OPERATIONS.START_INCIDENT.method === 'PATCH'
    && OFFLINE_OPERATIONS.START_INCIDENT.path(105) === '/technician/incidents/105/start'
    && OFFLINE_OPERATIONS.RESOLVE_INCIDENT.method === 'POST'
    && OFFLINE_OPERATIONS.START_PREVENTIVE.stopType === STOP_TYPE.PREVENTIVE_ORDER
    && OFFLINE_OPERATIONS.START_PREVENTIVE.path(77) === '/technician/preventive/orders/77/start'
);

console.log('\n--- 9. Orden FIFO estricto y disparo de la cola (EARS 04.1) ---');

const fifoStorage = await seedStorage();
const fifoMutation = (sequenceOrder, stopId) => ({
  client_mutation_id: `fifo-${sequenceOrder}-${stopId}`,
  technician_id: 2,
  technician_email: 'jordi@vendguard.internal',
  sequence_order: sequenceOrder,
  status: MUTATION_STATUS.PENDING,
  stop_type: STOP_TYPE.INCIDENT,
  stop_id: stopId,
  operation_type: 'ADD_INCIDENT_COMMENT',
  is_state_transition: false,
  http_method: 'POST',
  endpoint: `/technician/incidents/${stopId}/comments`,
  client_occurred_at: new Date(NOW).toISOString(),
  chain_started_at: null,
  chain_ended_at: null,
  sanitary_seal_confirmed: false,
  payload: { body: `Comentario FIFO de la parada ${stopId}` },
  photo_attachment: null,
  provisional_parts_deducted: [],
  conflict: null
});

// Se insertan deliberadamente en orden 3, 1, 2: el orden de la bandeja no
// puede depender del orden de inserción ni del reloj del dispositivo.
await fifoStorage.putMutation(fifoMutation(3, 103));
await fifoStorage.putMutation(fifoMutation(1, 101));
await fifoStorage.putMutation(fifoMutation(2, 102));

const fifoList = await fifoStorage.listMutations(2);
assert(
  '9.1 La bandeja ordena por sequence_order aunque las inserciones lleguen desordenadas',
  fifoList.map((item) => item.sequence_order).join(',') === '1,2,3',
  fifoList.map((item) => item.sequence_order).join(',')
);

const fifoRequests = [];
const fifoManager = new OfflineSyncManager({
  storage: fifoStorage,
  client: {
    request: async (endpoint) => {
      fifoRequests.push(endpoint);
      return { ok: true };
    }
  },
  now: () => NOW,
  dispatch: () => {}
});

const fifoSummary = await fifoManager.processOutboxQueue(2);
assert(
  '9.2 El envío respeta el orden FIFO de la bandeja (101, 102, 103)',
  fifoRequests.length === 3
    && fifoRequests[0].includes('/incidents/101/')
    && fifoRequests[1].includes('/incidents/102/')
    && fifoRequests[2].includes('/incidents/103/')
    && fifoSummary.applied === 3
    && fifoSummary.pending === 0,
  JSON.stringify({ requests: fifoRequests, summary: fifoSummary })
);

console.log('\n--- 10. Comentario intermedio rechazado sin bloquear la resolución (EARS 05.1.2, QA 3) ---');

const rejectedCommentStorage = await seedStorage();
const rejectedRequests = [];
const rejectedManager = new OfflineSyncManager({
  storage: rejectedCommentStorage,
  client: {
    request: async (endpoint) => {
      rejectedRequests.push(endpoint);

      // Solo el comentario intermedio se rechaza con 4xx; el resto de la cola
      // debe continuar y la resolución posterior sí llegar al servidor.
      if (endpoint.endsWith('/comments')) {
        throw Object.assign(new Error('Comentario rechazado por el servidor.'), {
          status: 409,
          code: 'COMMENT_REJECTED'
        });
      }

      return { ok: true };
    }
  },
  now: () => NOW,
  dispatch: () => {}
});

const rejectedStart = await rejectedManager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 101,
  operationType: 'START_INCIDENT',
  payload: {}
});
const rejectedComment = await rejectedManager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 101,
  operationType: 'ADD_INCIDENT_COMMENT',
  payload: { body: 'Comentario que el servidor rechazará.' }
});
const rejectedResolve = await rejectedManager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 101,
  operationType: 'RESOLVE_INCIDENT',
  payload: {
    diagnosis: 'Compresor bloqueado y sustituido.',
    action_taken: 'Se sustituye el compresor y se valida el frío.'
  }
});
const rejectedOtherStop = await rejectedManager.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 102,
  operationType: 'START_INCIDENT',
  payload: {}
});

const rejectedSummary = await rejectedManager.processOutboxQueue(2);
const rejectedCommentState = await rejectedCommentStorage.getMutation(rejectedComment.client_mutation_id);

assert(
  '10.1 El comentario rechazado con 409 queda en CONFLICT y no se descarta',
  rejectedCommentState?.status === MUTATION_STATUS.CONFLICT
    && rejectedCommentState?.conflict?.code === 'COMMENT_REJECTED'
);
assert(
  '10.2 La resolución posterior de la misma parada se envía y se retira de la cola',
  await rejectedCommentStorage.getMutation(rejectedResolve.client_mutation_id) === null
    && rejectedRequests.some((endpoint) => endpoint.endsWith('/incidents/101/resolve'))
);
assert(
  '10.3 La parada ajena continúa y la cola no se pausa por un rechazo 4xx',
  await rejectedCommentStorage.getMutation(rejectedOtherStop.client_mutation_id) === null
    && await rejectedCommentStorage.getMutation(rejectedStart.client_mutation_id) === null
    && rejectedSummary.applied === 3
    && rejectedSummary.conflicts === 1
    && rejectedSummary.pending === 0
    && rejectedSummary.interrupted === null,
  JSON.stringify(rejectedSummary)
);

console.log('\n--- 11. Turno activo restaurable sin red (EARS 03.1, EARS 06.2) ---');

const shiftStorage = await seedStorage();
const noSnapshotShift = await newStorage().saveShiftStatus(99, 'ON_SHIFT', NOW);
assert('11.1 Sin copia local no se anota un turno huérfano', noSnapshotShift === null);

const savedShift = await shiftStorage.saveShiftStatus(2, 'ON_SHIFT', NOW);
assert(
  '11.2 El turno se persiste junto a la copia del titular',
  savedShift?.offline_shift?.status === 'ON_SHIFT'
    && savedShift?.offline_shift?.recorded_at === new Date(NOW).toISOString(),
  JSON.stringify(savedShift?.offline_shift)
);

const freshShift = evaluateOfflineShift(savedShift, NOW + 11 * HOUR_MS);
assert(
  '11.3 Dentro de las 12 h la intervención sin red sigue habilitada por el turno',
  freshShift.restorable === true && freshShift.status === 'ON_SHIFT'
);

const expiredShift = evaluateOfflineShift(savedShift, NOW + 12 * HOUR_MS + 1);
assert(
  '11.4 Pasadas las 12 h el turno no se restaura (fallo seguro)',
  expiredShift.restorable === false
);

const refreshedShiftSnapshot = await shiftStorage.saveSnapshot(bootstrap({
  synced_at: new Date(NOW + 6 * HOUR_MS).toISOString()
}));
assert(
  '11.5 Refrescar la ruta no borra el turno anotado en el dispositivo',
  refreshedShiftSnapshot.offline_shift?.status === 'ON_SHIFT',
  JSON.stringify(refreshedShiftSnapshot.offline_shift)
);

const unknownShift = await shiftStorage.saveShiftStatus(2, 'EN_OTRO_SITIO', NOW);
assert('11.6 Un estado de turno desconocido no se persiste', unknownShift === null);

const expiredCopyStorage = await newStorage();
await expiredCopyStorage.saveSnapshot(bootstrap({ synced_at: new Date(NOW - 13 * HOUR_MS).toISOString() }));
const expiredCopyShift = await expiredCopyStorage.saveShiftStatus(2, 'MEAL_BREAK', NOW);
assert(
  '11.7 Una copia caducada no puede reactivar el turno ni siquiera recién anotado',
  evaluateOfflineShift(expiredCopyShift, NOW).restorable === false
);

await shiftStorage.clearTechnician(2);
assert(
  '11.8 Cerrar sesión limpia la copia y con ella el turno recordado (EARS 06.5)',
  await shiftStorage.getSnapshot(2) === null
);

console.log('\n--- 12. Rescate de envíos interrumpidos y veredictos 4xx (H1, H2) ---');

const recoveryStorage = await seedStorage();
const interruptedMutation = (id, sequenceOrder, status, syncingSince, stopId) => ({
  client_mutation_id: id,
  technician_id: 2,
  technician_email: 'jordi@vendguard.internal',
  sequence_order: sequenceOrder,
  status,
  stop_type: STOP_TYPE.INCIDENT,
  stop_id: stopId,
  operation_type: 'ADD_INCIDENT_COMMENT',
  is_state_transition: false,
  http_method: 'POST',
  endpoint: `/technician/incidents/${stopId}/comments`,
  client_occurred_at: new Date(NOW).toISOString(),
  chain_started_at: null,
  chain_ended_at: null,
  syncing_since: syncingSince,
  sanitary_seal_confirmed: false,
  payload: { body: `Comentario de la parada ${stopId}` },
  photo_attachment: null,
  provisional_parts_deducted: [],
  conflict: null
});

// Un envío de hace media hora (la pestaña murió), otro sin marca de inicio
// (murió entre las dos escrituras) y uno vivo de otra pestaña en este momento.
await recoveryStorage.putMutation(interruptedMutation(
  'stuck-syncing-01',
  1,
  MUTATION_STATUS.SYNCING,
  new Date(NOW - 30 * 60 * 1000).toISOString(),
  201
));
await recoveryStorage.putMutation(interruptedMutation('stuck-syncing-02', 2, MUTATION_STATUS.SYNCING, null, 202));
await recoveryStorage.putMutation(interruptedMutation(
  'live-syncing-03',
  3,
  MUTATION_STATUS.SYNCING,
  new Date(NOW - 5 * 1000).toISOString(),
  203
));

const recoveredFromCrash = await recoveryStorage.recoverInterruptedMutations(2, NOW);
assert(
  '12.1 Un envío huérfano vuelve a PENDING y el que otra pestaña está enviando ahora no se toca',
  recoveredFromCrash.length === 2
    && recoveredFromCrash.every((item) => item.status === MUTATION_STATUS.PENDING
      && item.syncing_since === null)
    && (await recoveryStorage.getMutation('live-syncing-03'))?.status === MUTATION_STATUS.SYNCING,
  recoveredFromCrash.map((item) => item.client_mutation_id).join(',')
);

const recoveredRequests = [];
const recoveryManager = new OfflineSyncManager({
  storage: recoveryStorage,
  client: {
    request: async (endpoint) => {
      recoveredRequests.push(endpoint);
      return { ok: true };
    }
  },
  now: () => NOW,
  dispatch: () => {}
});

const recoverySummary = await recoveryManager.processOutboxQueue(2);
assert(
  '12.2 La cola reenvía lo rescatado y deja fuera el envío que sigue vivo',
  recoverySummary.applied === 2
    && recoveredRequests.length === 2
    && recoveredRequests.every((endpoint) => !endpoint.includes('/incidents/203/')),
  JSON.stringify({ requests: recoveredRequests, summary: recoverySummary })
);

const recoveryQueue = await recoveryManager.getQueueSummary(2);
assert(
  '12.3 El envío vivo sigue contando como en curso, no como pendiente',
  recoveryQueue.syncing === 1 && recoveryQueue.pending === 0 && recoveryQueue.total === 1,
  JSON.stringify(recoveryQueue)
);

const verdictStorage = await seedStorage();
const verdictMutation = (sequenceOrder, stopId, overrides = {}) => ({
  ...interruptedMutation(`verdict-${sequenceOrder}-${stopId}`, sequenceOrder, MUTATION_STATUS.PENDING, null, stopId),
  ...overrides
});

await verdictStorage.putMutation(verdictMutation(1, 301));
await verdictStorage.putMutation(verdictMutation(2, 302));
await verdictStorage.putMutation(verdictMutation(3, 303));
await verdictStorage.putMutation(verdictMutation(4, 304, { photo_attachment: photo('evidencia.jpg', MB) }));
await verdictStorage.putMutation(verdictMutation(5, 305));

const verdictFailures = { 301: 404, 302: 400, 303: 410, 304: 413 };
const verdictRequests = [];
const verdictManager = new OfflineSyncManager({
  storage: verdictStorage,
  client: {
    request: async (endpoint) => {
      verdictRequests.push(endpoint);
      const stopId = Number((endpoint.match(/incidents\/(\d+)\//) ?? [])[1] ?? 0);
      const status = verdictFailures[stopId];

      if (status !== undefined) {
        throw Object.assign(new Error(`Rechazo ${status} de la parada ${stopId}`), {
          status,
          code: `HTTP_${status}`
        });
      }

      return { ok: true };
    }
  },
  now: () => NOW,
  dispatch: () => {}
});

const verdictSummary = await verdictManager.processOutboxQueue(2);
assert(
  '12.4 Un 4xx permanente no interrumpe la cola: las operaciones posteriores se envían',
  verdictSummary.interrupted === null && verdictSummary.applied === 1 && verdictSummary.conflicts === 4,
  JSON.stringify(verdictSummary)
);
assert(
  '12.5 404, 400 y 410 quedan en conflicto para volcarlos, no como caída de red',
  (await verdictStorage.getMutation('verdict-1-301'))?.status === MUTATION_STATUS.CONFLICT
    && (await verdictStorage.getMutation('verdict-2-302'))?.status === MUTATION_STATUS.CONFLICT
    && (await verdictStorage.getMutation('verdict-3-303'))?.status === MUTATION_STATUS.CONFLICT
);

const oversizedItem = await verdictStorage.getMutation('verdict-4-304');
assert(
  '12.6 El 413 retira solo la evidencia y conserva el trabajo para el volcado',
  oversizedItem?.photo_attachment === null
    && oversizedItem?.conflict?.message.includes('fotografía')
    && oversizedItem?.payload?.body.includes('304'),
  JSON.stringify({ photo: oversizedItem?.photo_attachment, conflict: oversizedItem?.conflict })
);
assert(
  '12.7 La operación posterior al último rechazo se aplicó y salió de la bandeja',
  await verdictStorage.getMutation('verdict-5-305') === null
);

const transientStorage = await seedStorage();
await transientStorage.putMutation(verdictMutation(1, 401));
await transientStorage.putMutation(verdictMutation(2, 402));
const transientRequests = [];
const transientManager = new OfflineSyncManager({
  storage: transientStorage,
  client: {
    request: async (endpoint) => {
      transientRequests.push(endpoint);
      throw Object.assign(new Error('Servicio no disponible'), { status: 500, code: 'SERVER_ERROR' });
    }
  },
  now: () => NOW,
  dispatch: () => {}
});

const transientSummary = await transientManager.processOutboxQueue(2);
assert(
  '12.8 Un 500 sigue siendo transitorio: la cola se interrumpe y el trabajo queda pendiente',
  transientSummary.interrupted === 'network'
    && transientSummary.applied === 0
    && (await transientStorage.getMutation('verdict-1-401'))?.status === MUTATION_STATUS.PENDING
    && (await transientStorage.getMutation('verdict-2-402'))?.status === MUTATION_STATUS.PENDING
    && transientRequests.length === 1,
  JSON.stringify(transientSummary)
);

const driftedStorage = await seedStorage();
await driftedStorage.putMutation(interruptedMutation(
  'drifted-syncing-05',
  1,
  MUTATION_STATUS.SYNCING,
  new Date(NOW + HOUR_MS).toISOString(),
  205
));
const driftedRecovered = await driftedStorage.recoverInterruptedMutations(2, NOW);
assert(
  '12.9 Un sello de envío en el futuro (reloj que retrocedió después) también se rescata',
  driftedRecovered.length === 1 && driftedRecovered[0].status === MUTATION_STATUS.PENDING
);

console.log('\n--- 13. Coordinación entre pestañas (RF-04.1, EARS 06.1) ---');

/** Web Locks de mentira con la misma semántica: exclusión y cola de espera. */
function createFakeLocks() {
  const state = { requests: 0, concurrent: 0, maxConcurrent: 0 };
  let tail = Promise.resolve();

  state.lock = {
    request: (name, optionsOrCallback, maybeCallback) => {
      const callback = typeof optionsOrCallback === 'function' ? optionsOrCallback : maybeCallback;
      state.requests++;
      const run = tail.then(async () => {
        state.concurrent++;
        state.maxConcurrent = Math.max(state.maxConcurrent, state.concurrent);
        try {
          return await callback();
        } finally {
          state.concurrent--;
        }
      });
      tail = run.then(() => undefined, () => undefined);

      return run;
    }
  };

  return state;
}

/** `BroadcastChannel` de mentira: entrega a las demás pestañas, nunca al emisor. */
function createChannelBus() {
  const channels = [];
  const posts = [];

  return {
    posts,
    open() {
      const channel = {
        listeners: [],
        addEventListener(type, handler) {
          if (type === 'message') {
            this.listeners.push(handler);
          }
        },
        postMessage(message) {
          posts.push(message);
          for (const peer of channels) {
            if (peer === channel) {
              continue;
            }
            for (const listener of peer.listeners) {
              listener({ data: { ...message } });
            }
          }
        }
      };
      channels.push(channel);

      return channel;
    }
  };
}

const lockState = createFakeLocks();
const lockStorage = await seedStorage();
for (const order of [1, 2, 3]) {
  await lockStorage.putMutation(interruptedMutation(
    `lock-${order}`,
    order,
    MUTATION_STATUS.PENDING,
    null,
    500 + order
  ));
}
const lockSends = [];
const lockManager = new OfflineSyncManager({
  storage: lockStorage,
  client: {
    request: async (endpoint) => {
      lockSends.push(endpoint);
      await new Promise((resolve) => setTimeout(resolve, 5));
      return { ok: true };
    }
  },
  locks: lockState.lock,
  channel: null,
  now: () => NOW,
  dispatch: () => {}
});

const [lockRunA, lockRunB] = await Promise.all([
  lockManager.processOutboxQueue(2),
  lockManager.processOutboxQueue(2)
]);
assert(
  '13.1 Dos vaciados simultáneos de la misma pestaña no duplican envíos',
  lockSends.length === 3 && lockRunA.applied + lockRunB.applied === 3,
  JSON.stringify({ sends: lockSends.length, a: lockRunA, b: lockRunB })
);
assert(
  '13.2 El cerrojo del navegador se toma una vez por operación de bandeja',
  lockState.requests === 2 && lockState.maxConcurrent === 1,
  JSON.stringify(lockState)
);

const tabLocks = createFakeLocks();
const tabStorage = await seedStorage();
for (const order of [1, 2, 3, 4]) {
  await tabStorage.putMutation(interruptedMutation(
    `tab-${order}`,
    order,
    MUTATION_STATUS.PENDING,
    null,
    600 + order
  ));
}
let tabActive = 0;
let tabMaxActive = 0;
const tabSends = [];
const tabClient = {
  request: async (endpoint) => {
    tabActive++;
    tabMaxActive = Math.max(tabMaxActive, tabActive);
    tabSends.push(endpoint);
    await new Promise((resolve) => setTimeout(resolve, 5));
    tabActive--;
    return { ok: true };
  }
};
const tabManagerA = new OfflineSyncManager({
  storage: tabStorage,
  client: tabClient,
  locks: tabLocks.lock,
  channel: null,
  now: () => NOW,
  dispatch: () => {}
});
const tabManagerB = new OfflineSyncManager({
  storage: tabStorage,
  client: tabClient,
  locks: tabLocks.lock,
  channel: null,
  now: () => NOW,
  dispatch: () => {}
});

const [tabRunA, tabRunB] = await Promise.all([
  tabManagerA.processOutboxQueue(2),
  tabManagerB.processOutboxQueue(2)
]);
assert(
  '13.3 Dos pestañas del mismo técnico no vacían la cola a la vez',
  tabMaxActive === 1 && tabSends.length === 4 && tabRunA.applied + tabRunB.applied === 4,
  JSON.stringify({ maxActive: tabMaxActive, sends: tabSends.length, a: tabRunA, b: tabRunB })
);

const fallbackStorage = await seedStorage();
for (const order of [1, 2]) {
  await fallbackStorage.putMutation(interruptedMutation(
    `fallback-${order}`,
    order,
    MUTATION_STATUS.PENDING,
    null,
    700 + order
  ));
}
let fallbackActive = 0;
let fallbackMaxActive = 0;
const fallbackSends = [];
const fallbackManager = new OfflineSyncManager({
  storage: fallbackStorage,
  client: {
    request: async (endpoint) => {
      fallbackActive++;
      fallbackMaxActive = Math.max(fallbackMaxActive, fallbackActive);
      fallbackSends.push(endpoint);
      await new Promise((resolve) => setTimeout(resolve, 5));
      fallbackActive--;
      return { ok: true };
    }
  },
  locks: null,
  channel: null,
  now: () => NOW,
  dispatch: () => {}
});

const [fallbackRunA, fallbackRunB] = await Promise.all([
  fallbackManager.processOutboxQueue(2),
  fallbackManager.processOutboxQueue(2)
]);
assert(
  '13.4 Sin Web Locks el mutex local sigue serializando los vaciados',
  fallbackMaxActive === 1
    && fallbackSends.length === 2
    && fallbackRunA.applied + fallbackRunB.applied === 2,
  JSON.stringify({ maxActive: fallbackMaxActive, sends: fallbackSends.length })
);

const channelBus = createChannelBus();
const localEvents = [];
const peerEvents = [];
const localTab = new OfflineSyncManager({
  storage: await seedStorage(),
  client: {},
  locks: null,
  channel: channelBus.open(),
  now: () => NOW,
  dispatch: (detail) => localEvents.push(detail)
});
const peerTab = new OfflineSyncManager({
  storage: await seedStorage(),
  client: {},
  locks: null,
  channel: channelBus.open(),
  now: () => NOW,
  dispatch: (detail) => peerEvents.push(detail)
});

await localTab.enqueue({
  technicianId: 2,
  stopType: STOP_TYPE.INCIDENT,
  stopId: 102,
  operationType: 'START_INCIDENT',
  payload: {},
  machineType: 'COLD_DRINKS'
});
assert(
  '13.5 La pestaña que encola avisa a la otra, que relee su bandeja',
  channelBus.posts.length === 1
    && channelBus.posts[0].type === 'queue_changed'
    && channelBus.posts[0].cause === 'enqueued'
    && channelBus.posts[0].technician_id === 2
    && peerEvents.length === 1
    && peerEvents[0].type === 'queue_changed'
    && peerEvents[0].remote === true
    && peerEvents[0].technicianId === 2,
  JSON.stringify({ posts: channelBus.posts, peer: peerEvents })
);
assert(
  '13.6 El aviso recibido no se reemite (sin eco entre pestañas)',
  channelBus.posts.length === 1 && localEvents.length === 1 && peerEvents.length === 1,
  JSON.stringify({ posts: channelBus.posts.length, local: localEvents.length, peer: peerEvents.length })
);

localTab.announce({ type: 'syncing', technicianId: 2, mutation: null });
assert(
  '13.7 Un anuncio que no cambia la bandeja no viaja a otras pestañas',
  channelBus.posts.length === 1,
  JSON.stringify(channelBus.posts)
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
