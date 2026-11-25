/**
 * VendGuard - DexAuditOfflineQueue
 *
 * Cola FIFO Offline de altas `.dex` del Módulo 39 (`RF-06.2`, `C5`, `D6`,
 * `QA-5`, Art. III.3, Art. V.4).
 *
 * Cada operador (`RESTOCKER` o `TECHNICIAN`) tiene su propio espacio en
 * `IndexedDB`; la clave primaria es el `client_mutation_id` y el registro
 * conserva el `.dex` crudo, la captura, los vínculos locales y el estado.
 *
 * Reglas de replay:
 *  - un alta correcta o un replay idempotente retira el ítem;
 *  - `409 DEX_DUPLICATE_HASH` se resuelve en silencio con un aviso;
 *  - `422`, `403` y los `409` de conflicto pasan a la bandeja sin detener la
 *    cola; el ítem conserva sus vínculos locales para que la integración M35
 *    pueda desvincular el `dex_audit_id` fallido;
 *  - un fallo de red, autenticación, timeout o servidor deja el ítem PENDING y
 *    detiene el replay para reintentarlo más tarde.
 *
 * El volcado de un conflicto se delega a una función inyectada para que esta
 * utilidad no conozca endpoints ni roles concretos.
 *
 * Dogma Vanilla: módulo ES nativo, sin dependencias externas (Art. IV.3).
 */

import { inspectDexEnvelope } from './EvaDtsClientPreview.js';

export const DEX_AUDIT_DB_NAME = 'vendguard-dex-audits';
export const DEX_AUDIT_STORE = 'dex_audit_queue';
export const DEX_AUDIT_OPERATION = 'IMPORT_DEX_AUDIT';
export const DEX_AUDIT_PENDING = 'PENDING';
export const DEX_AUDIT_CONFLICT = 'CONFLICT';

export const DEX_AUDIT_ROLES = Object.freeze({
  RESTOCKER: 'RESTOCKER',
  TECHNICIAN: 'TECHNICIAN'
});

const DB_VERSION = 1;
const BY_ACTOR = 'by_actor';
const CLIENT_MUTATION_ID = /^[A-Za-z0-9_.:-]{8,64}$/;
const IMPORT_SOURCES = Object.freeze({
  RESTOCKER: 'RESTOCKER_ROUTE',
  TECHNICIAN: 'TECHNICIAN_ROUTE'
});
const LOCAL_LINK_FIELDS = Object.freeze([
  'restock_visit_id',
  'cash_collection_id',
  'incident_id'
]);

export class DexAuditOfflineQueueError extends Error {
  /**
   * @param {string} code
   * @param {string} message
   * @param {Object|null} [details=null]
   */
  constructor(code, message, details = null) {
    super(message);
    this.name = 'DexAuditOfflineQueueError';
    this.code = code;
    this.details = details;
  }
}

/**
 * @param {*} value
 * @param {string} field
 * @returns {string}
 */
function requireText(value, field) {
  const text = String(value ?? '').trim();
  if (text === '') {
    throw new DexAuditOfflineQueueError(
      'DEX_AUDIT_OFFLINE_INVALID',
      `DexAuditOfflineQueue: ${field} is required.`,
      { field }
    );
  }

  return text;
}

/**
 * @param {*} value
 * @param {string} field
 * @returns {number}
 */
function positiveInteger(value, field) {
  const number = Number(value);
  if (!Number.isInteger(number) || number < 1) {
    throw new DexAuditOfflineQueueError(
      'DEX_AUDIT_OFFLINE_INVALID',
      `DexAuditOfflineQueue: ${field} must be a positive integer.`,
      { field }
    );
  }

  return number;
}

/**
 * @param {Object|null|undefined} links
 * @returns {Object}
 */
function normalizeLocalLinks(links) {
  if (links === null || links === undefined) {
    return {};
  }
  if (typeof links !== 'object' || Array.isArray(links)) {
    throw new DexAuditOfflineQueueError(
      'DEX_AUDIT_OFFLINE_INVALID',
      'DexAuditOfflineQueue: local_links must be an object.'
    );
  }

  const normalized = {};
  for (const field of LOCAL_LINK_FIELDS) {
    if (links[field] === null || links[field] === undefined) {
      continue;
    }
    normalized[field] = positiveInteger(links[field], `local_links.${field}`);
  }

  return normalized;
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

/**
 * @param {*} response
 * @returns {boolean}
 */
function isIdempotentReplay(response) {
  return response !== null
    && typeof response === 'object'
    && response.idempotent_replay === true;
}

/**
 * Normaliza un error del cliente HTTP (`ApiError`) o de un importador de test.
 *
 * @param {*} error
 * @returns {{code: string, message: string, http_status: number}}
 */
function conflictFrom(error) {
  const status = Number(error?.status ?? error?.http_status ?? 0);

  return {
    code: String(error?.code ?? error?.error_code ?? 'DEX_AUDIT_SYNC_FAILED'),
    message: String(error?.message ?? 'El volcado .dex no pudo importarse.'),
    http_status: Number.isFinite(status) ? status : 0
  };
}

/**
 * Un 4xx es un rechazo de negocio y aísla el ítem. Los 401/408/429 y los 5xx
 * son fallos transitorios: la cola debe conservarlos para el siguiente intento.
 *
 * @param {{http_status: number}} conflict
 * @returns {boolean}
 */
function isIsolatableConflict(conflict) {
  const status = conflict.http_status;

  return status >= 400
    && status < 500
    && status !== 401
    && status !== 408
    && status !== 429;
}

export class DexAuditOfflineQueue {
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
   * Encola una importación `.dex`; el `client_mutation_id` la hace idempotente.
   *
   * @param {{
   *   client_mutation_id: string,
   *   actor_id: number|string,
   *   actor_role: string,
   *   machine_id: number|string,
   *   machine_code?: string|null,
   *   captured_at: string,
   *   raw_payload: string,
   *   original_filename?: string|null,
   *   local_links?: Object|null,
   *   created_at?: string|null,
   *   sequence_order?: number|null
   * }} record
   * @returns {Promise<Object>}
   */
  async enqueue(record) {
    const clientMutationId = requireText(record?.client_mutation_id, 'client_mutation_id');
    if (!CLIENT_MUTATION_ID.test(clientMutationId)) {
      throw new DexAuditOfflineQueueError(
        'DEX_AUDIT_OFFLINE_INVALID',
        'DexAuditOfflineQueue: client_mutation_id must match ^[A-Za-z0-9_.:-]{8,64}$.',
        { client_mutation_id: clientMutationId }
      );
    }

    const actorId = positiveInteger(record?.actor_id, 'actor_id');
    const role = requireText(record?.actor_role, 'actor_role').toUpperCase();
    if (role !== DEX_AUDIT_ROLES.RESTOCKER && role !== DEX_AUDIT_ROLES.TECHNICIAN) {
      throw new DexAuditOfflineQueueError(
        'DEX_AUDIT_OFFLINE_INVALID',
        'DexAuditOfflineQueue: actor_role must be RESTOCKER or TECHNICIAN.',
        { actor_role: role }
      );
    }

    const machineId = positiveInteger(record?.machine_id, 'machine_id');
    const capturedAt = requireText(record?.captured_at, 'captured_at');
    if (Number.isNaN(Date.parse(capturedAt))) {
      throw new DexAuditOfflineQueueError(
        'DEX_AUDIT_OFFLINE_INVALID',
        'DexAuditOfflineQueue: captured_at must be a valid date.',
        { captured_at: capturedAt }
      );
    }

    const rawPayload = typeof record?.raw_payload === 'string' ? record.raw_payload : '';
    const inspection = inspectDexEnvelope(rawPayload);
    if (!inspection.ok) {
      throw new DexAuditOfflineQueueError(
        inspection.errors[0]?.code ?? 'DEX_INVALID_ENVELOPE',
        inspection.errors[0]?.message ?? 'El volcado .dex no supera el pre-filtro Offline.',
        inspection.errors[0]?.details ?? null
      );
    }

    const item = {
      client_mutation_id: clientMutationId,
      operation_type: DEX_AUDIT_OPERATION,
      actor_id: actorId,
      actor_role: role,
      import_source: IMPORT_SOURCES[role],
      machine_id: machineId,
      machine_code: record.machine_code ?? null,
      captured_at: capturedAt,
      original_filename: String(record.original_filename ?? 'volcado.dex'),
      local_links: normalizeLocalLinks(record.local_links),
      raw_payload: rawPayload,
      status: DEX_AUDIT_PENDING,
      conflict: null,
      conflict_reason: null,
      created_at: String(record.created_at ?? new Date().toISOString()),
      sequence_order: Number(record.sequence_order ?? Date.now())
    };

    await this.put(item);

    return item;
  }

  /**
   * @param {Object} item
   * @returns {Promise<void>}
   */
  async put(item) {
    if (this.memory !== null) {
      this.memory.set(item.client_mutation_id, item);
      return;
    }

    const db = await this.open();
    const transaction = db.transaction(DEX_AUDIT_STORE, 'readwrite');
    transaction.objectStore(DEX_AUDIT_STORE).put(item);
    await transactionComplete(transaction);
  }

  /**
   * Lista las importaciones de un operador en orden FIFO.
   *
   * @param {number|string} actorId
   * @param {Array<string>|null} [statuses=null]
   * @returns {Promise<Array<Object>>}
   */
  async list(actorId, statuses = null) {
    const owner = Number(actorId);
    if (!Number.isInteger(owner) || owner < 1) {
      return [];
    }

    const wanted = Array.isArray(statuses) ? statuses : null;
    let items;
    if (this.memory !== null) {
      items = [...this.memory.values()].filter((item) => Number(item.actor_id) === owner);
    } else {
      const db = await this.open();
      const index = db.transaction(DEX_AUDIT_STORE, 'readonly')
        .objectStore(DEX_AUDIT_STORE)
        .index(BY_ACTOR);
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
   * @returns {Promise<Object|null>}
   */
  async get(clientMutationId) {
    const key = String(clientMutationId || '');
    if (key === '') return null;

    if (this.memory !== null) {
      return this.memory.get(key) ?? null;
    }

    const db = await this.open();
    const item = await requestResult(
      db.transaction(DEX_AUDIT_STORE, 'readonly').objectStore(DEX_AUDIT_STORE).get(key)
    );

    return item ?? null;
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
    const transaction = db.transaction(DEX_AUDIT_STORE, 'readwrite');
    transaction.objectStore(DEX_AUDIT_STORE).delete(key);
    await transactionComplete(transaction);
  }

  /**
   * Mueve un ítem a la bandeja de conflictos preservando el motivo exacto.
   *
   * @param {string} clientMutationId
   * @param {string|{code?: string, message?: string, http_status?: number}} conflict
   * @returns {Promise<Object|null>}
   */
  async markConflict(clientMutationId, conflict) {
    const key = String(clientMutationId || '');
    if (key === '') return null;

    const existing = await this.get(key);
    if (existing === null) return null;

    const normalized = typeof conflict === 'string'
      ? { code: 'DEX_AUDIT_SYNC_FAILED', message: conflict, http_status: 0 }
      : conflictFrom(conflict);
    const conflicted = {
      ...existing,
      status: DEX_AUDIT_CONFLICT,
      conflict: normalized,
      conflict_reason: normalized.message
    };

    await this.put(conflicted);

    return conflicted;
  }

  /**
   * Replay secuencial FIFO de las altas pendientes de un operador.
   *
   * `importer` debe devolver la respuesta de la API (o un objeto con
   * `idempotent_replay`) y lanzar un error con `status`/`code` cuando falle.
   *
   * @param {number|string} actorId
   * @param {(item: Object) => Promise<*>} importer
   * @param {{onConflict?: Function, onNotice?: Function}} [options={}]
   * @returns {Promise<{
   *   applied: number, replayed: number, duplicates: number, conflicts: number,
   *   pending: number, interrupted: (Object|null), notices: Array<Object>
   * }>}
   */
  async replay(actorId, importer, options = {}) {
    if (typeof importer !== 'function') {
      throw new DexAuditOfflineQueueError(
        'DEX_AUDIT_OFFLINE_INVALID',
        'DexAuditOfflineQueue: importer must be a function.'
      );
    }

    const queue = await this.list(actorId, [DEX_AUDIT_PENDING]);
    const summary = {
      applied: 0,
      replayed: 0,
      duplicates: 0,
      conflicts: 0,
      pending: queue.length,
      interrupted: null,
      notices: []
    };

    for (const item of queue) {
      try {
        const response = await importer(item);
        await this.remove(item.client_mutation_id);
        summary.pending--;
        if (isIdempotentReplay(response)) {
          summary.replayed++;
        } else {
          summary.applied++;
        }
        continue;
      } catch (error) {
        const conflict = conflictFrom(error);

        // Mismo fichero ya registrado: no es un conflicto que requiera operario.
        if (conflict.http_status === 409 && conflict.code === 'DEX_DUPLICATE_HASH') {
          await this.remove(item.client_mutation_id);
          summary.pending--;
          summary.duplicates++;
          const notice = {
            code: 'DEX_DUPLICATE_HASH',
            message: 'Volcado ya registrado previamente en la máquina',
            client_mutation_id: item.client_mutation_id
          };
          summary.notices.push(notice);
          if (typeof options.onNotice === 'function') {
            await options.onNotice(notice, item);
          }
          continue;
        }

        if (isIsolatableConflict(conflict)) {
          const conflicted = await this.markConflict(item.client_mutation_id, conflict);
          summary.pending--;
          summary.conflicts++;
          if (typeof options.onConflict === 'function') {
            await options.onConflict(conflicted, item);
          }
          continue;
        }

        // Sin red, sesión caducada, timeout o servidor caído: no se descarta nada.
        summary.interrupted = {
          ...conflict,
          client_mutation_id: item.client_mutation_id
        };
        break;
      }
    }

    return summary;
  }

  /**
   * Volcado en un toque de un conflicto ya aislado. La función inyectada decide
   * el endpoint y el payload; la cola solo retira el ítem tras su confirmación.
   *
   * @param {string} clientMutationId
   * @param {(item: Object) => Promise<*>} dumper
   * @returns {Promise<*>}
   */
  async dumpConflict(clientMutationId, dumper) {
    if (typeof dumper !== 'function') {
      throw new DexAuditOfflineQueueError(
        'DEX_AUDIT_OFFLINE_INVALID',
        'DexAuditOfflineQueue: dumper must be a function.'
      );
    }

    const item = await this.get(clientMutationId);
    if (item === null) {
      throw new DexAuditOfflineQueueError(
        'DEX_AUDIT_CONFLICT_NOT_FOUND',
        'El volcado .dex ya no está en la bandeja de este dispositivo.'
      );
    }
    if (item.status !== DEX_AUDIT_CONFLICT) {
      throw new DexAuditOfflineQueueError(
        'DEX_AUDIT_NOT_IN_CONFLICT',
        'Solo se puede volcar un .dex marcado como conflicto.'
      );
    }

    const response = await dumper(item);
    await this.remove(item.client_mutation_id);

    return response;
  }

  /**
   * Vacía la cola del operador (logout o cambio de cuenta).
   *
   * @param {number|string} actorId
   * @returns {Promise<void>}
   */
  async clear(actorId) {
    const owner = Number(actorId);
    if (!Number.isInteger(owner) || owner < 1) return;

    if (this.memory !== null) {
      for (const [key, item] of this.memory) {
        if (Number(item.actor_id) === owner) {
          this.memory.delete(key);
        }
      }
      return;
    }

    const db = await this.open();
    const transaction = db.transaction(DEX_AUDIT_STORE, 'readwrite');
    const store = transaction.objectStore(DEX_AUDIT_STORE);
    const keys = (await requestResult(store.index(BY_ACTOR).getAllKeys(owner))) ?? [];
    for (const key of keys) {
      store.delete(key);
    }
    await transactionComplete(transaction);
  }

  /** @returns {Promise<IDBDatabase>} */
  async open() {
    if (this.memory !== null) {
      throw new DexAuditOfflineQueueError(
        'INDEXEDDB_UNAVAILABLE',
        'DexAuditOfflineQueue: IndexedDB is not available.'
      );
    }
    if (this.dbPromise !== null) {
      return this.dbPromise;
    }

    this.dbPromise = new Promise((resolve, reject) => {
      const request = this.factory.open(DEX_AUDIT_DB_NAME, DB_VERSION);

      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(DEX_AUDIT_STORE)) {
          const store = db.createObjectStore(DEX_AUDIT_STORE, { keyPath: 'client_mutation_id' });
          store.createIndex(BY_ACTOR, 'actor_id');
        }
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error ?? new Error('IndexedDB open failed.'));
      request.onblocked = () => reject(new Error('IndexedDB open blocked by another connection.'));
    });

    return this.dbPromise;
  }
}

export default DexAuditOfflineQueue;
