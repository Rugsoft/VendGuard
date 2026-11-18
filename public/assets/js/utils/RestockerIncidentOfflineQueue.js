/**
 * VendGuard - RestockerIncidentOfflineQueue
 *
 * Cola local de operaciones sobre avisos de Nivel 1 (`D3`, `C2`, `RNF-03`):
 * `START_L1_INCIDENT`, `RESOLVE_L1_INCIDENT` y `ESCALATE_INCIDENT_TO_SAT`.
 * Cada entrada queda aislada por `restocker_id` y conserva el
 * `client_mutation_id` idempotente, el payload y la foto opcional.
 *
 * IndexedDB es el almacén real del navegador; en Node o sin `indexedDB` la
 * cola degrada a memoria para no inventar persistencia inexistente.
 *
 * Dogma Vanilla: módulo ES nativo, sin dependencias externas.
 */

export const RESTOCKER_INCIDENT_DB_NAME = 'vendguard-restocker-incidents';
export const RESTOCKER_INCIDENT_STORE = 'incident_queue';
export const RESTOCKER_INCIDENT_PENDING = 'PENDING';
export const RESTOCKER_INCIDENT_CONFLICT = 'CONFLICT';

export const RESTOCKER_INCIDENT_OPERATIONS = Object.freeze({
  START_L1: 'START_L1_INCIDENT',
  RESOLVE_L1: 'RESOLVE_L1_INCIDENT',
  ESCALATE_TO_SAT: 'ESCALATE_INCIDENT_TO_SAT'
});

const DB_VERSION = 1;
const BY_RESTOCKER = 'by_restocker';
const SUPPORTED_OPERATIONS = new Set(Object.values(RESTOCKER_INCIDENT_OPERATIONS));

function requireText(value, field) {
  const text = String(value ?? '').trim();
  if (text === '') {
    throw new Error(`RestockerIncidentOfflineQueue: ${field} is required.`);
  }
  return text;
}

function requestResult(request) {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error ?? new Error('IndexedDB request failed.'));
  });
}

function transactionComplete(transaction) {
  return new Promise((resolve, reject) => {
    transaction.oncomplete = () => resolve();
    transaction.onerror = () => reject(transaction.error ?? new Error('IndexedDB transaction failed.'));
    transaction.onabort = () => reject(transaction.error ?? new Error('IndexedDB transaction aborted.'));
  });
}

export class RestockerIncidentOfflineQueue {
  constructor(options = {}) {
    const factory = options.indexedDB !== undefined
      ? options.indexedDB
      : (typeof globalThis !== 'undefined' ? globalThis.indexedDB : undefined);
    this.factory = factory ?? null;
    this.memory = this.factory === null ? new Map() : null;
    this.dbPromise = null;
  }

  isMemoryBacked() {
    return this.memory !== null;
  }

  /**
   * Encola una mutación idempotente por `client_mutation_id`.
   *
   * @param {{
   *   client_mutation_id: string,
   *   restocker_id: number|string,
   *   operation_type: string,
   *   incident_id?: number|string|null,
   *   machine_id: number|string,
   *   machine_code?: string|null,
   *   payload: Object,
   *   photo?: Blob|null,
   *   photo_name?: string|null,
   *   created_at?: string|null,
   *   sequence_order?: number|null
   * }} record
   * @returns {Promise<Object>}
   */
  async enqueue(record) {
    const clientMutationId = requireText(record?.client_mutation_id, 'client_mutation_id');
    const restockerId = Number(record?.restocker_id);
    const machineId = Number(record?.machine_id);
    const operationType = requireText(record?.operation_type, 'operation_type').toUpperCase();
    if (!Number.isInteger(restockerId) || restockerId < 1) {
      throw new Error('RestockerIncidentOfflineQueue: restocker_id must be a positive integer.');
    }
    if (!Number.isInteger(machineId) || machineId < 1) {
      throw new Error('RestockerIncidentOfflineQueue: machine_id must be a positive integer.');
    }
    if (!SUPPORTED_OPERATIONS.has(operationType)) {
      throw new Error('RestockerIncidentOfflineQueue: unsupported operation_type.');
    }
    if (record?.payload === null || typeof record?.payload !== 'object') {
      throw new Error('RestockerIncidentOfflineQueue: payload must be an object.');
    }

    const item = {
      client_mutation_id: clientMutationId,
      restocker_id: restockerId,
      operation_type: operationType,
      incident_id: record.incident_id !== undefined && record.incident_id !== null
        ? Number(record.incident_id)
        : null,
      machine_id: machineId,
      machine_code: record.machine_code ?? null,
      payload: record.payload,
      photo: record.photo ?? null,
      photo_name: record.photo_name ?? null,
      status: RESTOCKER_INCIDENT_PENDING,
      conflict_reason: null,
      created_at: String(record.created_at ?? new Date().toISOString()),
      sequence_order: Number(record.sequence_order ?? Date.now())
    };

    if (this.memory !== null) {
      this.memory.set(clientMutationId, item);
      return item;
    }

    const db = await this.open();
    const transaction = db.transaction(RESTOCKER_INCIDENT_STORE, 'readwrite');
    transaction.objectStore(RESTOCKER_INCIDENT_STORE).put(item);
    await transactionComplete(transaction);
    return item;
  }

  /**
   * Lista las operaciones del reponedor en orden FIFO.
   *
   * @param {number|string} restockerId
   * @param {Array<string>|null} [statuses=null]
   * @returns {Promise<Array<Object>>}
   */
  async list(restockerId, statuses = null) {
    const owner = Number(restockerId);
    if (!Number.isInteger(owner) || owner < 1) {
      return [];
    }

    const wanted = Array.isArray(statuses) ? statuses : null;
    let items;
    if (this.memory !== null) {
      items = [...this.memory.values()].filter((item) => Number(item.restocker_id) === owner);
    } else {
      const db = await this.open();
      const index = db.transaction(RESTOCKER_INCIDENT_STORE, 'readonly')
        .objectStore(RESTOCKER_INCIDENT_STORE)
        .index(BY_RESTOCKER);
      items = (await requestResult(index.getAll(owner))) ?? [];
    }

    return items
      .filter((item) => wanted === null || wanted.includes(item.status))
      .sort((left, right) => {
        const bySequence = Number(left.sequence_order || 0) - Number(right.sequence_order || 0);
        if (bySequence !== 0) return bySequence;
        return String(left.created_at || '').localeCompare(String(right.created_at || ''));
      });
  }

  async remove(clientMutationId) {
    const key = String(clientMutationId || '');
    if (key === '') return;

    if (this.memory !== null) {
      this.memory.delete(key);
      return;
    }

    const db = await this.open();
    const transaction = db.transaction(RESTOCKER_INCIDENT_STORE, 'readwrite');
    transaction.objectStore(RESTOCKER_INCIDENT_STORE).delete(key);
    await transactionComplete(transaction);
  }

  async markConflict(clientMutationId, reason) {
    const key = String(clientMutationId || '');
    if (key === '') return null;

    if (this.memory !== null) {
      const existing = this.memory.get(key);
      if (!existing) return null;
      const conflicted = {
        ...existing,
        status: RESTOCKER_INCIDENT_CONFLICT,
        conflict_reason: String(reason || 'La operación offline ya no puede aplicarse.')
      };
      this.memory.set(key, conflicted);
      return conflicted;
    }

    const db = await this.open();
    const readTransaction = db.transaction(RESTOCKER_INCIDENT_STORE, 'readonly');
    const existing = await requestResult(
      readTransaction.objectStore(RESTOCKER_INCIDENT_STORE).get(key)
    );
    if (!existing) return null;

    const conflicted = {
      ...existing,
      status: RESTOCKER_INCIDENT_CONFLICT,
      conflict_reason: String(reason || 'La operación offline ya no puede aplicarse.')
    };
    const writeTransaction = db.transaction(RESTOCKER_INCIDENT_STORE, 'readwrite');
    writeTransaction.objectStore(RESTOCKER_INCIDENT_STORE).put(conflicted);
    await transactionComplete(writeTransaction);
    return conflicted;
  }

  async clear(restockerId) {
    const owner = Number(restockerId);
    if (!Number.isInteger(owner) || owner < 1) return;

    if (this.memory !== null) {
      for (const [key, item] of this.memory) {
        if (Number(item.restocker_id) === owner) this.memory.delete(key);
      }
      return;
    }

    const db = await this.open();
    const transaction = db.transaction(RESTOCKER_INCIDENT_STORE, 'readwrite');
    const store = transaction.objectStore(RESTOCKER_INCIDENT_STORE);
    const keys = (await requestResult(store.index(BY_RESTOCKER).getAllKeys(owner))) ?? [];
    for (const key of keys) store.delete(key);
    await transactionComplete(transaction);
  }

  async open() {
    if (this.memory !== null) {
      throw new Error('RestockerIncidentOfflineQueue: IndexedDB is not available.');
    }
    if (this.dbPromise !== null) return this.dbPromise;

    this.dbPromise = new Promise((resolve, reject) => {
      const request = this.factory.open(RESTOCKER_INCIDENT_DB_NAME, DB_VERSION);
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(RESTOCKER_INCIDENT_STORE)) {
          const store = db.createObjectStore(RESTOCKER_INCIDENT_STORE, { keyPath: 'client_mutation_id' });
          store.createIndex(BY_RESTOCKER, 'restocker_id');
        }
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error ?? new Error('IndexedDB open failed.'));
      request.onblocked = () => reject(new Error('IndexedDB open blocked by another connection.'));
    });

    return this.dbPromise;
  }
}

export default RestockerIncidentOfflineQueue;
