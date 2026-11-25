/**
 * VendGuard - EVA-DTS offline queue and preview test
 *
 * Certifica T-DEX-11 (Módulo 39, `RF-06.1`, `RF-06.2`, `D6`, `C5`, `QA-5`):
 *
 *  1. `EvaDtsClientPreview.js` valida el sobre `DXS*`..`DXE*`, el tope de 1 MB,
 *     los bytes nulos y los controles peligrosos, y extrae solo `ID4`, `VA1` y
 *     códigos `EA1`/`EA2`. Nunca lee ni devuelve `CA3`, y oculta el importe VA1
 *     al Técnico (Art. V.4).
 *  2. `DexAuditOfflineQueue.js` persiste una cola FIFO aislada por operador en
 *     IndexedDB, con `client_mutation_id` idempotente y pre-filtro de encolado.
 *  3. El replay retira las altas correctas y los replays idempotentes, resuelve
 *     limpiamente `409 DEX_DUPLICATE_HASH`, aísla `422`/`403`/`409` en conflictos
 *     sin detener los ítems posteriores y conserva el ítem ante fallos de red.
 *     Los vínculos locales quedan en el conflicto para que M35 pueda desvincular
 *     el `dex_audit_id` fallido.
 *  4. El volcado de un conflicto se ejecuta en un toque y solo retira el ítem
 *     tras la confirmación de la función inyectada.
 *
 * Node.js native ESM, zero external dependencies, zero network.
 */

import {
  DEX_AUDIT_CONFLICT,
  DEX_AUDIT_PENDING,
  DexAuditOfflineQueue
} from '../../public/assets/js/utils/DexAuditOfflineQueue.js';
import {
  MAX_DEX_BYTES,
  inspectDexEnvelope,
  previewDex,
  previewDexFile
} from '../../public/assets/js/utils/EvaDtsClientPreview.js';
import { readFileSync } from 'node:fs';
import { createFakeIndexedDb } from '../Support/FakeIndexedDb.mjs';

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

function makeDex(overrides = {}) {
  const {
    sales = '12550',
    units = '250',
    id4 = '2',
    event = 'EG01'
  } = overrides;

  return [
    'DXS*COMM-DEX10*VA*DEX 6.0',
    'ID1*DEX10-OFFLINE',
    `ID4*${id4}`,
    `VA1*${sales}*${units}`,
    'CA3*500*10*2',
    'PA1*A1*150',
    'PA2*10*1000',
    'EA1*' + event + '*20261125*101500',
    'EA2*' + event + '*1*2*0*1',
    'DXE*FIN'
  ].join('\n');
}

function record(cmid, overrides = {}) {
  return {
    client_mutation_id: cmid,
    actor_id: 71,
    actor_role: 'RESTOCKER',
    machine_id: 501,
    machine_code: 'DEX10-OFFLINE',
    captured_at: '2026-11-25T09:00:00.000Z',
    raw_payload: makeDex(),
    original_filename: 'offline.dex',
    local_links: { cash_collection_id: 55 },
    sequence_order: 1,
    ...overrides
  };
}

function throwHttp(status, code, message = 'Rechazo de prueba') {
  throw { status, code, message };
}

console.log('======================================================================');
console.log(' VendGuard: EVA-DTS offline preview and queue');
console.log('======================================================================\n');

// ───────────────────────────────────────────────────────────────────────────
console.log('--- 1. Pre-filtro y micro-prelectura sin CA3 (RF-06.1, QA-5) ---');

const validDex = makeDex();
const restockerPreview = previewDex(validDex);
const technicianPreview = previewDex(validDex, { role: 'TECHNICIAN' });
const serializedPreview = JSON.stringify(restockerPreview);

assert(
  '1.1 El sobre DXS/DXE válido se acepta con su tamaño real en UTF-8',
  restockerPreview.ok === true
    && restockerPreview.valid === true
    && restockerPreview.bytes === new TextEncoder().encode(validDex).byteLength
    && restockerPreview.errors.length === 0
);
assert(
  '1.2 ID4 y VA1 se leen con la escala decimal correcta',
  restockerPreview.id4_decimal_places === 2
    && restockerPreview.va1_total_sales_eur === 125.5
    && restockerPreview.va1_total_vend_units === 250
);
assert(
  '1.3 El Reponedor recibe VA1 como prefill y los códigos EA1/EA2',
  restockerPreview.events.length === 2
    && restockerPreview.events.every((event) => event.is_thermal_alert === true)
    && restockerPreview.events.map((event) => event.event_code).join(',') === 'EG01,EG01'
);
assert(
  '1.4 La prelectura no expone CA3, importes por carril ni raw_payload',
  !serializedPreview.includes('CA3')
    && !serializedPreview.includes('ca3')
    && !serializedPreview.includes('cashbox')
    && !serializedPreview.includes('raw_payload')
    && !serializedPreview.includes('PA2')
);
const publicEnvelope = inspectDexEnvelope(validDex);
assert(
  '1.4b La inspección pública tampoco devuelve las líneas ni el bloque CA3',
  publicEnvelope.ok === true
    && Object.prototype.hasOwnProperty.call(publicEnvelope, 'lines') === false
    && !JSON.stringify(publicEnvelope).includes('CA3')
);
assert(
  '1.5 El Técnico no recibe el importe VA1 pero sí las unidades y los eventos',
  Object.prototype.hasOwnProperty.call(technicianPreview, 'va1_total_sales_eur') === false
    && technicianPreview.va1_total_vend_units === 250
    && technicianPreview.events.length === 2
);
assert(
  '1.6 Los saltos CRLF se normalizan y el sobre sigue siendo válido',
  previewDex(validDex.replace(/\n/g, '\r\n')).ok === true
);

const oversized = 'DXS*' + 'A'.repeat(MAX_DEX_BYTES);
const oversizedPreview = previewDex(oversized);
const nullPreview = previewDex('DXS*\nID4*2\nVA1*100*1\n\0\nDXE*FIN');
const badEnvelope = previewDex('NO-DXS\nVA1*100*1\nNO-DXE');
const missingClose = previewDex('DXS*\nVA1*100*1');

assert(
  '1.7 Un fichero de más de 1 MB se rechaza antes de encolar',
  oversizedPreview.ok === false
    && oversizedPreview.errors[0].code === 'DEX_FILE_TOO_LARGE'
    && oversizedPreview.bytes > MAX_DEX_BYTES
);
assert(
  '1.8 Un byte nulo se rechaza como texto inválido',
  nullPreview.ok === false
    && nullPreview.errors[0].code === 'DEX_INVALID_TEXT_PAYLOAD'
);
assert(
  '1.9 La apertura o el cierre incorrectos se rechazan como envoltorio inválido',
  badEnvelope.ok === false
    && badEnvelope.errors[0].code === 'DEX_INVALID_ENVELOPE'
    && missingClose.ok === false
    && missingClose.errors[0].code === 'DEX_INVALID_ENVELOPE'
);

const filePreview = await previewDexFile(new Blob([validDex], { type: 'text/plain' }));
assert(
  '1.10 La variante File/Blob valida antes de leer y conserva la prelectura',
  filePreview.ok === true
    && filePreview.va1_total_sales_eur === 125.5
    && filePreview.events.length === 2
);

const swSource = readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8');
assert(
  '1.11 El App Shell v34 precachea el pre-filtro y la cola .dex',
  /const CACHE_NAME = 'vendguard-app-shell-v34'/.test(swSource)
    && swSource.includes("'/assets/js/utils/EvaDtsClientPreview.js'")
    && swSource.includes("'/assets/js/utils/DexAuditOfflineQueue.js'")
);

// ───────────────────────────────────────────────────────────────────────────
console.log('\n--- 2. Cola FIFO IndexedDB aislada por operador (D6, C5) ---');

const factory = createFakeIndexedDb();
const queue = new DexAuditOfflineQueue({ indexedDB: factory });

await queue.enqueue(record('dex10_offline_0001', { sequence_order: 1 }));
await queue.enqueue(record('dex10_offline_0002', {
  sequence_order: 2,
  raw_payload: makeDex({ sales: '12600', units: '251' })
}));
await queue.enqueue(record('dex10_offline_0003', {
  sequence_order: 3,
  raw_payload: makeDex({ sales: '12650', units: '252' })
}));
await queue.enqueue(record('dex10_offline_0004', {
  actor_id: 72,
  sequence_order: 1,
  raw_payload: makeDex({ sales: '12700', units: '253' })
}));

const actor71Queue = await queue.list(71);
const actor72Queue = await queue.list(72);
assert(
  '2.1 Cada operador ve solo su cola y el orden es FIFO',
  actor71Queue.length === 3
    && actor72Queue.length === 1
    && actor71Queue.map((item) => item.client_mutation_id).join(',')
      === 'dex10_offline_0001,dex10_offline_0002,dex10_offline_0003'
    && actor71Queue.every((item) => item.operation_type === 'IMPORT_DEX_AUDIT')
);
assert(
  '2.2 El registro conserva el .dex, la captura y los vínculos locales',
  actor71Queue[0].raw_payload === makeDex()
    && actor71Queue[0].captured_at === '2026-11-25T09:00:00.000Z'
    && actor71Queue[0].local_links.cash_collection_id === 55
    && actor71Queue[0].status === DEX_AUDIT_PENDING
);

const reopenedQueue = new DexAuditOfflineQueue({ indexedDB: factory });
const persisted = await reopenedQueue.list(71);
assert(
  '2.3 IndexedDB conserva la cola entre instancias (no solo memoria)',
  persisted.length === 3 && persisted[2].client_mutation_id === 'dex10_offline_0003'
);

let invalidMutationError = null;
try {
  await queue.enqueue(record('short', { sequence_order: 9 }));
} catch (error) {
  invalidMutationError = error;
}
let invalidDexError = null;
try {
  await queue.enqueue(record('dex10_offline_invalid', {
    sequence_order: 10,
    raw_payload: 'NO-DXS\nVA1*100*1\nNO-DXE'
  }));
} catch (error) {
  invalidDexError = error;
}
assert(
  '2.4 El encolado rechaza client_mutation_id y .dex inválidos con código propio',
  invalidMutationError?.code === 'DEX_AUDIT_OFFLINE_INVALID'
    && invalidDexError?.code === 'DEX_INVALID_ENVELOPE'
);

await queue.clear(72);
assert(
  '2.5 El logout de un operador no borra la cola del otro',
  (await queue.list(72)).length === 0 && (await queue.list(71)).length === 3
);

// ───────────────────────────────────────────────────────────────────────────
console.log('\n--- 3. Replay: idempotencia, duplicado limpio y conflictos aislados (RF-06.2) ---');

const syncQueue = new DexAuditOfflineQueue({ indexedDB: createFakeIndexedDb() });
const calls = [];
const conflicts = [];
const notices = [];

const syncCases = [
  ['dex10_sync_ok0001', async () => ({ audit: { id: 1 }, idempotent_replay: false })],
  ['dex10_sync_duplicate', async () => throwHttp(409, 'DEX_DUPLICATE_HASH')],
  ['dex10_sync_invalid', async () => throwHttp(422, 'DEX_INVALID_ENVELOPE')],
  ['dex10_sync_sanitary', async () => throwHttp(409, 'SANITARY_DEX_BLOCKED')],
  ['dex10_sync_forbidden', async () => throwHttp(403, 'MACHINE_OUTSIDE_RESTOCKER_ZONES')],
  ['dex10_sync_mutation', async () => throwHttp(409, 'CLIENT_MUTATION_ID_ALREADY_USED')],
  ['dex10_sync_replay', async () => ({ audit: { id: 7 }, idempotent_replay: true })],
  ['dex10_sync_after', async () => ({ audit: { id: 8 }, idempotent_replay: false })]
];

for (let index = 0; index < syncCases.length; index++) {
  const [cmid] = syncCases[index];
  await syncQueue.enqueue(record(cmid, {
    sequence_order: index + 1,
    local_links: { cash_collection_id: 700 + index, incident_id: 900 + index }
  }));
}

const summary = await syncQueue.replay(71, async (item) => {
  calls.push(item.client_mutation_id);
  const found = syncCases.find(([cmid]) => cmid === item.client_mutation_id);
  return found[1]();
}, {
  onConflict: async (item) => { conflicts.push(item); },
  onNotice: async (notice) => { notices.push(notice); }
});

assert(
  '3.1 El replay procesa toda la cola aunque haya conflictos intermedios',
  calls.length === syncCases.length
    && calls[calls.length - 1] === 'dex10_sync_after'
    && summary.applied === 2
    && summary.replayed === 1
    && summary.duplicates === 1
    && summary.conflicts === 4
    && summary.pending === 0
    && summary.interrupted === null
);
assert(
  '3.2 DEX_DUPLICATE_HASH se retira con aviso y no entra en conflictos',
  notices.length === 1
    && notices[0].code === 'DEX_DUPLICATE_HASH'
    && notices[0].message === 'Volcado ya registrado previamente en la máquina'
    && (await syncQueue.get('dex10_sync_duplicate')) === null
    && !conflicts.some((item) => item.client_mutation_id === 'dex10_sync_duplicate')
);
assert(
  '3.3 422/409/403 quedan en CONFLICT con su motivo exacto y sus vínculos locales',
  conflicts.length === 4
    && conflicts.every((item) => item.status === DEX_AUDIT_CONFLICT)
    && conflicts.every((item) => typeof item.conflict_reason === 'string' && item.conflict_reason.length > 0)
    && conflicts.find((item) => item.client_mutation_id === 'dex10_sync_invalid')?.conflict.code === 'DEX_INVALID_ENVELOPE'
    && conflicts.find((item) => item.client_mutation_id === 'dex10_sync_invalid')?.local_links.cash_collection_id === 702
    && conflicts.find((item) => item.client_mutation_id === 'dex10_sync_sanitary')?.conflict.code === 'SANITARY_DEX_BLOCKED'
    && conflicts.find((item) => item.client_mutation_id === 'dex10_sync_forbidden')?.conflict.code === 'MACHINE_OUTSIDE_RESTOCKER_ZONES'
    && conflicts.find((item) => item.client_mutation_id === 'dex10_sync_mutation')?.conflict.code === 'CLIENT_MUTATION_ID_ALREADY_USED'
);
assert(
  '3.4 Las altas correctas y el replay idempotente se retiran sin duplicar filas',
  (await syncQueue.get('dex10_sync_ok0001')) === null
    && (await syncQueue.get('dex10_sync_replay')) === null
    && (await syncQueue.get('dex10_sync_after')) === null
);
assert(
  '3.5 La bandeja conserva exactamente los cuatro conflictos',
  (await syncQueue.list(71, [DEX_AUDIT_CONFLICT])).length === 4
    && (await syncQueue.list(71, [DEX_AUDIT_PENDING])).length === 0
);

const dumpedConflict = await syncQueue.dumpConflict(
  'dex10_sync_invalid',
  async (item) => ({ dumped: true, conflict: item.conflict.code })
);
assert(
  '3.6 El volcado en un toque confirma el conflicto y retira el ítem',
  dumpedConflict.dumped === true
    && (await syncQueue.get('dex10_sync_invalid')) === null
    && (await syncQueue.list(71, [DEX_AUDIT_CONFLICT])).length === 3
);

let notInConflictError = null;
try {
  await syncQueue.dumpConflict('dex10_sync_ok0001', async () => ({ dumped: true }));
} catch (error) {
  notInConflictError = error;
}
assert(
  '3.7 Un ítem que no está en conflicto no se puede volcar',
  notInConflictError?.code === 'DEX_AUDIT_CONFLICT_NOT_FOUND'
);

const networkQueue = new DexAuditOfflineQueue({ indexedDB: createFakeIndexedDb() });
await networkQueue.enqueue(record('dex10_network_0001', { sequence_order: 1 }));
const networkSummary = await networkQueue.replay(71, async () => {
  throw new Error('Sin cobertura');
});
assert(
  '3.8 Un fallo de red deja el ítem PENDING para el siguiente intento',
  networkSummary.interrupted?.code === 'DEX_AUDIT_SYNC_FAILED'
    && networkSummary.pending === 1
    && (await networkQueue.list(71, [DEX_AUDIT_PENDING])).length === 1
);

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
console.log(failures === 0
  ? ' RESULTADO: 100% EN VERDE. T-DEX-11 (preview y cola Offline .dex) CERTIFICADO.'
  : ' RESULTADO: FALLOS DETECTADOS EN EL PRE-FILTRO O LA COLA OFFLINE .DEX.');
console.log('======================================================================');

if (failures > 0) {
  process.exitCode = 1;
}
