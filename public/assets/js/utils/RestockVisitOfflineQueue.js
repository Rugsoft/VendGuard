/**
 * VendGuard - RestockVisitOfflineQueue
 *
 * Cola local de visitas de reposición in situ para el reponedor (`D4`, `C3`,
 * `RF-04.1`, `RF-04.2`). Cada entrada queda aislada por `restocker_id` y
 * conserva el `client_mutation_id`, el payload firmado y, si existe, el `Blob`
 * de la fotografía para subirlo al recuperar cobertura.
 *
 * IndexedDB es el almacén real en navegador; en Node o en un navegador sin
 * `indexedDB` la cola degrada a memoria para que la vista siga siendo
 * ejecutable en pruebas y no invente persistencia que el entorno no ofrece.
 *
 * Dogma Vanilla: módulo ES nativo, sin dependencias externas.
 */

export const RESTOCK_VISIT_DB_NAME = 'vendguard-restock-visits';
export const RESTOCK_VISIT_STORE = 'visit_queue';
export const RESTOCK_VISIT_PENDING = 'PENDING';
export const RESTOCK_VISIT_CONFLICT = 'CONFLICT';

const DB_VERSION = 1;
const BY_RESTOCKER = 'by_restocker';

/**
 * @param {*} record
 * @returns {string}
 */
function requireText(value, field) {
  const text = String(value ?? '').trim();
  if (text === '') {
    throw new Error(`RestockVisitOfflineQueue: ${field} is required.`);
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

export class RestockVisitOfflineQueue {
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
   * Encola una visita. El registro es idempotente por `client_mutation_id`.
   *
   * @param {{
   *   client_mutation_id: string,
   *   restocker_id: number|string,
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
    if (!Number.isInteger(restockerId) || restockerId < 1) {
      throw new Error('RestockVisitOfflineQueue: restocker_id must be a positive integer.');
    }
    if (!Number.isInteger(machineId) || machineId < 1) {
      throw new Error('RestockVisitOfflineQueue: machine_id must be a positive integer.');
    }
    if (record?.payload === null || typeof record?.payload !== 'object') {
      throw new Error('RestockVisitOfflineQueue: payload must be an object.');
    }

    const item = {
      client_mutation_id: clientMutationId,
      restocker_id: restockerId,
      machine_id: machineId,
      machine_code: record.machine_code ?? null,
      payload: record.payload,
      photo: record.photo ?? null,
      photo_name: record.photo_name ?? null,
      status: RESTOCK_VISIT_PENDING,
      conflict_reason: null,
      created_at: String(record.created_at ?? new Date().toISOString()),
      sequence_order: Number(record.sequence_order ?? Date.now())
    };

    if (this.memory !== null) {
      this.memory.set(clientMutationId, item);
      return item;
    }

    const db = await this.open();
    const transaction = db.transaction(RESTOCK_VISIT_STORE, 'readwrite');
    transaction.objectStore(RESTOCK_VISIT_STORE).put(item);
    await transactionComplete(transaction);

    return item;
  }

  /**
   * Lista las visitas de un reponedor en orden FIFO.
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
      const index = db.transaction(RESTOCK_VISIT_STORE, 'readonly')
        .objectStore(RESTOCK_VISIT_STORE)
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
    const transaction = db.transaction(RESTOCK_VISIT_STORE, 'readwrite');
    transaction.objectStore(RESTOCK_VISIT_STORE).delete(key);
    await transactionComplete(transaction);
  }

  /**
   * Marca una visita como conflicto irresoluble y conserva el motivo.
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
        status: RESTOCK_VISIT_CONFLICT,
        conflict_reason: String(reason || 'La visita offline ya no puede aplicarse.')
      };
      this.memory.set(key, conflicted);
      return conflicted;
    }

    const db = await this.open();
    const readTransaction = db.transaction(RESTOCK_VISIT_STORE, 'readonly');
    const existing = await requestResult(
      readTransaction.objectStore(RESTOCK_VISIT_STORE).get(key)
    );
    if (!existing) {
      return null;
    }

    const conflicted = {
      ...existing,
      status: RESTOCK_VISIT_CONFLICT,
      conflict_reason: String(reason || 'La visita offline ya no puede aplicarse.')
    };
    const writeTransaction = db.transaction(RESTOCK_VISIT_STORE, 'readwrite');
    writeTransaction.objectStore(RESTOCK_VISIT_STORE).put(conflicted);
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
    const transaction = db.transaction(RESTOCK_VISIT_STORE, 'readwrite');
    const store = transaction.objectStore(RESTOCK_VISIT_STORE);
    const keys = (await requestResult(store.index(BY_RESTOCKER).getAllKeys(owner))) ?? [];
    for (const key of keys) {
      store.delete(key);
    }
    await transactionComplete(transaction);
  }

  /** @returns {Promise<IDBDatabase>} */
  async open() {
    if (this.memory !== null) {
      throw new Error('RestockVisitOfflineQueue: IndexedDB is not available.');
    }
    if (this.dbPromise !== null) {
      return this.dbPromise;
    }

    this.dbPromise = new Promise((resolve, reject) => {
      const request = this.factory.open(RESTOCK_VISIT_DB_NAME, DB_VERSION);

      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(RESTOCK_VISIT_STORE)) {
          const store = db.createObjectStore(RESTOCK_VISIT_STORE, { keyPath: 'client_mutation_id' });
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

export default RestockVisitOfflineQueue;
