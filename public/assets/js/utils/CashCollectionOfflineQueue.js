/**
 * VendGuard - CashCollectionOfflineQueue
 *
 * Cola local de recaudaciones independientes y cargas de cambio del reponedor
 * (`RF-05.1`, `D4`, `C4`). Cada entrada queda aislada por `restocker_id` y
 * conserva el `client_mutation_id`, el payload firmado y el estado de
 * sincronización para poder volcar a auditoría los conflictos irreversibles.
 *
 * IndexedDB es el almacén real en navegador; en Node o en un navegador sin
 * `indexedDB` la cola degrada a memoria para que la vista siga siendo
 * ejecutable en pruebas y no invente persistencia que el entorno no ofrece.
 *
 * Dogma Vanilla: módulo ES nativo, sin dependencias externas.
 */

export const CASH_COLLECTION_DB_NAME = 'vendguard-cash-collections';
export const CASH_COLLECTION_STORE = 'cash_collection_queue';
export const CASH_COLLECTION_PENDING = 'PENDING';
export const CASH_COLLECTION_CONFLICT = 'CONFLICT';

const DB_VERSION = 1;
const BY_RESTOCKER = 'by_restocker';

/**
 * @param {*} value
 * @param {string} field
 * @returns {string}
 */
function requireText(value, field) {
  const text = String(value ?? '').trim();
  if (text === '') {
    throw new Error(`CashCollectionOfflineQueue: ${field} is required.`);
  }

  return text;
}

/**
 * @param {IDBRequest} request
 * @returns {Promise<*>}
 */
function requestResult(request) {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error ?? new Error('IndexedDB request failed.'));
  });
}

/**
 * @param {IDBTransaction} transaction
 * @returns {Promise<void>}
 */
function transactionComplete(transaction) {
  return new Promise((resolve, reject) => {
    transaction.oncomplete = () => resolve();
    transaction.onerror = () => reject(transaction.error ?? new Error('IndexedDB transaction failed.'));
    transaction.onabort = () => reject(transaction.error ?? new Error('IndexedDB transaction aborted.'));
  });
}

export class CashCollectionOfflineQueue {
  /**
   * @param {{indexedDB?: (IDBFactory|null)}} [options={}]
   */
  constructor(options = {}) {
    const factory = options.indexedDB !== undefined
      ? options.indexedDB
      : (typeof globalThis !== 'undefined' ? globalThis.indexedDB : undefined);

    this.factory = factory ?? null;
    this.memory = this.factory === null ? new Map() : null;
    this.dbPromise = null;
  }

  /** ¿La cola usa el respaldo en memoria de un entorno sin IndexedDB? */
  isMemoryBacked() {
    return this.memory !== null;
  }

  /**
   * Encola una recaudación. El registro es idempotente por `client_mutation_id`.
   *
   * @param {{
   *   client_mutation_id: string,
   *   restocker_id: number|string,
   *   machine_id: number|string,
   *   machine_code?: string|null,
   *   payload: Object,
   *   created_at?: string|null,
   *   sequence_order?: number|null
   * }} record
   * @returns {Promise<Object>}
   */
  async enqueue(record) {
    const clientMutationId = requireText(record?.client_mutation_id, 'client_mutation_id');
    const restockerId = Number(record?.restocker_id);
    const machineId = Number(record?.machine_id);
    if (!Number.isInteger(restockerId) || restockerId < 1) {
      throw new Error('CashCollectionOfflineQueue: restocker_id must be a positive integer.');
    }
    if (!Number.isInteger(machineId) || machineId < 1) {
      throw new Error('CashCollectionOfflineQueue: machine_id must be a positive integer.');
    }
    if (record?.payload === null || typeof record?.payload !== 'object') {
      throw new Error('CashCollectionOfflineQueue: payload must be an object.');
    }

    const item = {
      client_mutation_id: clientMutationId,
      restocker_id: restockerId,
      machine_id: machineId,
      machine_code: record.machine_code ?? null,
      payload: record.payload,
      status: CASH_COLLECTION_PENDING,
      conflict_reason: null,
      created_at: String(record.created_at ?? new Date().toISOString()),
      sequence_order: Number(record.sequence_order ?? Date.now())
    };

    if (this.memory !== null) {
      this.memory.set(clientMutationId, item);
      return item;
    }

    const db = await this.open();
    const transaction = db.transaction(CASH_COLLECTION_STORE, 'readwrite');
    transaction.objectStore(CASH_COLLECTION_STORE).put(item);
    await transactionComplete(transaction);

    return item;
  }

  /**
   * Lista las recaudaciones de un reponedor en orden FIFO.
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
      const index = db.transaction(CASH_COLLECTION_STORE, 'readonly')
        .objectStore(CASH_COLLECTION_STORE)
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

  /**
   * @param {string} clientMutationId
   * @returns {Promise<void>}
   */
  async remove(clientMutationId) {
    const key = String(clientMutationId || '');
    if (key === '') return;

    if (this.memory !== null) {
      this.memory.delete(key);
      return;
    }

    const db = await this.open();
    const transaction = db.transaction(CASH_COLLECTION_STORE, 'readwrite');
    transaction.objectStore(CASH_COLLECTION_STORE).delete(key);
    await transactionComplete(transaction);
  }

  /**
   * Marca una recaudación como conflicto irresoluble y conserva el motivo.
   *
   * @param {string} clientMutationId
   * @param {string} reason
   * @returns {Promise<Object|null>}
   */
  async markConflict(clientMutationId, reason) {
    const key = String(clientMutationId || '');
    if (key === '') return null;

    if (this.memory !== null) {
      const existing = this.memory.get(key);
      if (!existing) return null;
      const conflicted = {
        ...existing,
        status: CASH_COLLECTION_CONFLICT,
        conflict_reason: String(reason || 'La recaudación offline ya no puede aplicarse.')
      };
      this.memory.set(key, conflicted);
      return conflicted;
    }

    const db = await this.open();
    const readTransaction = db.transaction(CASH_COLLECTION_STORE, 'readonly');
    const existing = await requestResult(
      readTransaction.objectStore(CASH_COLLECTION_STORE).get(key)
    );
    if (!existing) {
      return null;
    }

    const conflicted = {
      ...existing,
      status: CASH_COLLECTION_CONFLICT,
      conflict_reason: String(reason || 'La recaudación offline ya no puede aplicarse.')
    };
    const writeTransaction = db.transaction(CASH_COLLECTION_STORE, 'readwrite');
    writeTransaction.objectStore(CASH_COLLECTION_STORE).put(conflicted);
    await transactionComplete(writeTransaction);

    return conflicted;
  }

  /**
   * Vacía la cola del reponedor (logout o cambio de cuenta).
   *
   * @param {number|string} restockerId
   * @returns {Promise<void>}
   */
  async clear(restockerId) {
    const owner = Number(restockerId);
    if (!Number.isInteger(owner) || owner < 1) return;

    if (this.memory !== null) {
      for (const [key, item] of this.memory) {
        if (Number(item.restocker_id) === owner) {
          this.memory.delete(key);
        }
      }
      return;
    }

    const db = await this.open();
    const transaction = db.transaction(CASH_COLLECTION_STORE, 'readwrite');
    const store = transaction.objectStore(CASH_COLLECTION_STORE);
    const keys = (await requestResult(store.index(BY_RESTOCKER).getAllKeys(owner))) ?? [];
    for (const key of keys) {
      store.delete(key);
    }
    await transactionComplete(transaction);
  }

  /** @returns {Promise<IDBDatabase>} */
  async open() {
    if (this.memory !== null) {
      throw new Error('CashCollectionOfflineQueue: IndexedDB is not available.');
    }
    if (this.dbPromise !== null) {
      return this.dbPromise;
    }

    this.dbPromise = new Promise((resolve, reject) => {
      const request = this.factory.open(CASH_COLLECTION_DB_NAME, DB_VERSION);

      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(CASH_COLLECTION_STORE)) {
          const store = db.createObjectStore(CASH_COLLECTION_STORE, { keyPath: 'client_mutation_id' });
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

export default CashCollectionOfflineQueue;
