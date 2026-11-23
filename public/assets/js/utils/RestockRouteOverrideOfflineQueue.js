/**
 * VendGuard - RestockRouteOverrideOfflineQueue
 *
 * Cola local de omisiones de parada del planificador M37 (`C3`, `D7`,
 * `RF-05.1.3`, Art. V.1, Art. V.4). Cada entrada queda aislada por
 * `restocker_id`, conserva el `client_mutation_id` idempotente y el payload
 * contractual, y puede marcarse como `CONFLICT` cuando el servidor rechaza la
 * mutación de forma irreversible.
 *
 * Además guarda un snapshot de la hoja de ruta por reponedor. El snapshot se
 * invalida al cambiar el día civil: una ruta de ayer no debe presentarse como
 * la ruta de hoy después de medianoche (`D7`).
 *
 * IndexedDB es el almacén real en navegador; en Node o sin `indexedDB` la cola
 * degrada a memoria para no inventar persistencia que el entorno no ofrece.
 *
 * Dogma Vanilla: módulo ES nativo, sin dependencias externas.
 */

export const RESTOCK_ROUTE_OVERRIDE_DB_NAME = 'vendguard-restock-route-overrides';
export const RESTOCK_ROUTE_OVERRIDE_STORE = 'override_queue';
export const RESTOCK_ROUTE_SNAPSHOT_STORE = 'route_snapshots';
export const RESTOCK_ROUTE_OVERRIDE_PENDING = 'PENDING';
export const RESTOCK_ROUTE_OVERRIDE_CONFLICT = 'CONFLICT';

const DB_VERSION = 1;
const BY_RESTOCKER = 'by_restocker';

function requireText(value, field) {
  const text = String(value ?? '').trim();
  if (text === '') {
    throw new Error(`RestockRouteOverrideOfflineQueue: ${field} is required.`);
  }
  return text;
}

/**
 * Unwraps a possibly reactive route plan (Vue Proxy) into a plain object that
 * IndexedDB can structured-clone. Proxies are not cloneable and make put() fail.
 */
function toPlainRoutePlan(routePlan) {
  try {
    const plain = JSON.parse(JSON.stringify(routePlan));
    if (plain === null || typeof plain !== 'object') {
      throw new Error('route_plan is not an object');
    }
    return plain;
  } catch (error) {
    throw new Error('RestockRouteOverrideOfflineQueue: route_plan must be a JSON-serializable object.');
  }
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

export class RestockRouteOverrideOfflineQueue {
  /**
   * @param {{indexedDB?: (IDBFactory|null)}} [options={}]
   */
  constructor(options = {}) {
    const factory = options.indexedDB !== undefined
      ? options.indexedDB
      : (typeof globalThis !== 'undefined' ? globalThis.indexedDB : undefined);

    this.factory = factory ?? null;
    this.memory = this.factory === null ? new Map() : null;
    this.snapshots = this.factory === null ? new Map() : null;
    this.dbPromise = null;
  }

  /** ¿La cola usa el respaldo en memoria de un entorno sin IndexedDB? */
  isMemoryBacked() {
    return this.memory !== null;
  }

  /**
   * Encola una omisión. El registro es idempotente por `client_mutation_id`.
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
      throw new Error('RestockRouteOverrideOfflineQueue: restocker_id must be a positive integer.');
    }
    if (!Number.isInteger(machineId) || machineId < 1) {
      throw new Error('RestockRouteOverrideOfflineQueue: machine_id must be a positive integer.');
    }
    if (record?.payload === null || typeof record?.payload !== 'object') {
      throw new Error('RestockRouteOverrideOfflineQueue: payload must be an object.');
    }

    const item = {
      client_mutation_id: clientMutationId,
      restocker_id: restockerId,
      machine_id: machineId,
      machine_code: record.machine_code ?? null,
      payload: record.payload,
      status: RESTOCK_ROUTE_OVERRIDE_PENDING,
      conflict_reason: null,
      created_at: String(record.created_at ?? new Date().toISOString()),
      sequence_order: Number(record.sequence_order ?? Date.now())
    };

    if (this.memory !== null) {
      this.memory.set(clientMutationId, item);
      return item;
    }

    const db = await this.open();
    const transaction = db.transaction(RESTOCK_ROUTE_OVERRIDE_STORE, 'readwrite');
    transaction.objectStore(RESTOCK_ROUTE_OVERRIDE_STORE).put(item);
    await transactionComplete(transaction);

    return item;
  }

  /**
   * Lista las omisiones de un reponedor en orden FIFO.
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
      const index = db.transaction(RESTOCK_ROUTE_OVERRIDE_STORE, 'readonly')
        .objectStore(RESTOCK_ROUTE_OVERRIDE_STORE)
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
    const transaction = db.transaction(RESTOCK_ROUTE_OVERRIDE_STORE, 'readwrite');
    transaction.objectStore(RESTOCK_ROUTE_OVERRIDE_STORE).delete(key);
    await transactionComplete(transaction);
  }

  /**
   * Marca una omisión como conflicto irresoluble y conserva el motivo.
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
        status: RESTOCK_ROUTE_OVERRIDE_CONFLICT,
        conflict_reason: String(reason || 'La omisión offline ya no puede aplicarse.')
      };
      this.memory.set(key, conflicted);
      return conflicted;
    }

    const db = await this.open();
    const readTransaction = db.transaction(RESTOCK_ROUTE_OVERRIDE_STORE, 'readonly');
    const existing = await requestResult(
      readTransaction.objectStore(RESTOCK_ROUTE_OVERRIDE_STORE).get(key)
    );
    if (!existing) return null;

    const conflicted = {
      ...existing,
      status: RESTOCK_ROUTE_OVERRIDE_CONFLICT,
      conflict_reason: String(reason || 'La omisión offline ya no puede aplicarse.')
    };
    const writeTransaction = db.transaction(RESTOCK_ROUTE_OVERRIDE_STORE, 'readwrite');
    writeTransaction.objectStore(RESTOCK_ROUTE_OVERRIDE_STORE).put(conflicted);
    await transactionComplete(writeTransaction);

    return conflicted;
  }

  /**
   * Guarda la hoja de ruta vigente para recuperación sin red.
   *
   * @param {number|string} restockerId
   * @param {string} serviceDate
   * @param {Object} routePlan
   * @param {string|null} [savedAt=null]
   * @returns {Promise<Object>}
   */
  async saveSnapshot(restockerId, serviceDate, routePlan, savedAt = null) {
    const owner = Number(restockerId);
    if (!Number.isInteger(owner) || owner < 1) {
      throw new Error('RestockRouteOverrideOfflineQueue: restocker_id must be a positive integer.');
    }
    const date = requireText(serviceDate, 'service_date');
    if (routePlan === null || typeof routePlan !== 'object') {
      throw new Error('RestockRouteOverrideOfflineQueue: route_plan must be an object.');
    }

    const snapshot = {
      restocker_id: owner,
      service_date: date,
      route_plan: toPlainRoutePlan(routePlan),
      saved_at: String(savedAt ?? new Date().toISOString())
    };

    if (this.snapshots !== null) {
      this.snapshots.set(owner, snapshot);
      return snapshot;
    }

    const db = await this.open();
    const transaction = db.transaction(RESTOCK_ROUTE_SNAPSHOT_STORE, 'readwrite');
    transaction.objectStore(RESTOCK_ROUTE_SNAPSHOT_STORE).put(snapshot);
    await transactionComplete(transaction);

    return snapshot;
  }

  /**
   * Devuelve el snapshot solo si pertenece al día civil solicitado. Un snapshot
   * de otro día se elimina para que nunca se proyecte como la ruta de hoy.
   *
   * @param {number|string} restockerId
   * @param {string} today
   * @returns {Promise<Object|null>}
   */
  async loadSnapshot(restockerId, today) {
    const owner = Number(restockerId);
    if (!Number.isInteger(owner) || owner < 1) return null;
    const currentDate = String(today || '').slice(0, 10);
    if (currentDate === '') return null;

    let snapshot;
    if (this.snapshots !== null) {
      snapshot = this.snapshots.get(owner) ?? null;
    } else {
      const db = await this.open();
      snapshot = await requestResult(
        db.transaction(RESTOCK_ROUTE_SNAPSHOT_STORE, 'readonly')
          .objectStore(RESTOCK_ROUTE_SNAPSHOT_STORE)
          .get(owner)
      ) ?? null;
    }

    if (!snapshot) return null;
    if (String(snapshot.service_date || '').slice(0, 10) !== currentDate) {
      await this.invalidateSnapshot(owner);
      return null;
    }
    return snapshot;
  }

  /**
   * @param {number|string} restockerId
   * @returns {Promise<void>}
   */
  async invalidateSnapshot(restockerId) {
    const owner = Number(restockerId);
    if (!Number.isInteger(owner) || owner < 1) return;

    if (this.snapshots !== null) {
      this.snapshots.delete(owner);
      return;
    }

    const db = await this.open();
    const transaction = db.transaction(RESTOCK_ROUTE_SNAPSHOT_STORE, 'readwrite');
    transaction.objectStore(RESTOCK_ROUTE_SNAPSHOT_STORE).delete(owner);
    await transactionComplete(transaction);
  }

  /**
   * Vacía cola y snapshot del reponedor (logout, baja de cuenta).
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
      this.snapshots.delete(owner);
      return;
    }

    const db = await this.open();
    const transaction = db.transaction(RESTOCK_ROUTE_OVERRIDE_STORE, 'readwrite');
    const store = transaction.objectStore(RESTOCK_ROUTE_OVERRIDE_STORE);
    const keys = (await requestResult(store.index(BY_RESTOCKER).getAllKeys(owner))) ?? [];
    for (const key of keys) {
      store.delete(key);
    }
    await transactionComplete(transaction);
    await this.invalidateSnapshot(owner);
  }

  /** @returns {Promise<IDBDatabase>} */
  async open() {
    if (this.memory !== null) {
      throw new Error('RestockRouteOverrideOfflineQueue: IndexedDB is not available.');
    }
    if (this.dbPromise !== null) {
      return this.dbPromise;
    }

    this.dbPromise = new Promise((resolve, reject) => {
      const request = this.factory.open(RESTOCK_ROUTE_OVERRIDE_DB_NAME, DB_VERSION);

      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(RESTOCK_ROUTE_OVERRIDE_STORE)) {
          const store = db.createObjectStore(RESTOCK_ROUTE_OVERRIDE_STORE, { keyPath: 'client_mutation_id' });
          store.createIndex(BY_RESTOCKER, 'restocker_id');
        }
        if (!db.objectStoreNames.contains(RESTOCK_ROUTE_SNAPSHOT_STORE)) {
          db.createObjectStore(RESTOCK_ROUTE_SNAPSHOT_STORE, { keyPath: 'restocker_id' });
        }
      };

      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error ?? new Error('IndexedDB open failed.'));
    });

    return this.dbPromise;
  }
}

export default RestockRouteOverrideOfflineQueue;
