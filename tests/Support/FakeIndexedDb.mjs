/**
 * VendGuard · Doble mínimo de IndexedDB para la batería unitaria (H4, M2)
 *
 * La suite de almacenamiento usaba `{ indexedDB: null }` y certificaba el
 * respaldo en memoria, que es útil para la lógica de cola pero **no** ejercita
 * las transacciones donde vive el defecto que cierran H4 y M2: la ventana entre
 * leer y escribir, el rollback al abortar y la restricción única del índice
 * `by_tech_sequence`.
 *
 * Este doble implementa solo lo que `OfflineStorage` usa —`open`, `transaction`,
 * `objectStore`, `index`, `put`, `get`, `delete`, `getAll`, `getAllKeys`— con las
 * tres propiedades que importan:
 *
 *  1. **Aislamiento por transacción.** Cada `readwrite` trabaja sobre una copia
 *     de los almacenes tomada al abrirse; los cambios solo se publican al
 *     confirmar.
 *  2. **Rollback real.** Un aborto o una violación de restricción descarta la
 *     copia entera: no queda nada a medias.
 *  3. **Restricciones únicas al confirmar.** Dos transacciones que leyeron la
 *     misma versión y escribieron el mismo turno chocan al confirmar la segunda,
 *     que es exactamente la carrera que `enqueueMutation` reintenta.
 *
 * Sin dependencias externas (Art. IV.3): es código de prueba, no de producción.
 */

/** Error con la forma del DOMException que lanza el motor real. */
function constraintError(message) {
  const error = new Error(message);
  error.name = 'ConstraintError';

  return error;
}

/** Ejecuta una función en el siguiente macrotarea, como haría el motor. */
function soon(callback) {
  return setTimeout(callback, 0);
}

class FakeRequest {
  constructor() {
    this.result = undefined;
    this.error = null;
    this.onsuccess = null;
    this.onerror = null;
    this._settled = false;
    this._succeeded = false;
  }

  succeed(value) {
    this.result = value;
    this._settled = true;
    this._succeeded = true;
    soon(() => this.onsuccess?.());
  }

  fail(error) {
    this.error = error;
    this._settled = true;
    this._succeeded = false;
    soon(() => this.onerror?.());
  }
}

class FakeTransaction {
  /**
   * @param {FakeDatabase} database
   * @param {string[]} storeNames
   * @param {string} mode
   */
  constructor(database, storeNames, mode) {
    this.database = database;
    this.mode = mode;
    this.error = null;
    this.oncomplete = null;
    this.onerror = null;
    this.onabort = null;
    this._aborted = false;
    this._finished = false;
    this._timer = null;

    // Copia de trabajo: todo lo que escriba la transacción vive aquí hasta el
    // commit, de modo que abortar es simplemente no publicarla.
    this._working = new Map();
    this._baselineKeys = new Map();
    for (const name of storeNames) {
      const store = database.stores.get(name);
      this._working.set(name, {
        ...store,
        data: new Map([...store.data].map(([key, value]) => [key, structuredCloneValue(value)]))
      });
      this._baselineKeys.set(name, new Set(store.data.keys()));
    }

    this._scheduleCommit();
  }

  objectStore(name) {
    const store = this._working.get(name);
    if (!store) {
      throw new Error(`FakeIndexedDb: la transacción no incluye «${name}».`);
    }

    return new FakeObjectStore(this, store);
  }

  abort() {
    if (this._finished) {
      return;
    }

    this._aborted = true;
    this._finish();
  }

  /** Reprograma el commit detrás de la última operación registrada. */
  _scheduleCommit() {
    if (this._timer !== null) {
      clearTimeout(this._timer);
    }

    this._timer = soon(() => this._finish());
  }

  _finish() {
    if (this._finished) {
      return;
    }

    this._finished = true;
    clearTimeout(this._timer);

    if (this._aborted || this.error !== null) {
      this._aborted = true;
      soon(() => {
        this.onabort?.();
        this.onerror?.();
      });

      return;
    }

    // Verificación de restricciones únicas al confirmar: lo escrito por otras
    // transacciones después de abrirse ésta también cuenta. Es exactamente la
    // carrera que `enqueueMutation` reintenta.
    for (const [name, working] of this._working) {
      const committed = this.database.stores.get(name);
      const baseline = this._baselineKeys.get(name) ?? new Set();
      const merged = new Map(working.data);
      for (const [key, value] of committed.data) {
        if (!baseline.has(key)) {
          merged.set(key, value);
        }
      }

      for (const [indexName, index] of working.indexes) {
        if (!index.unique) {
          continue;
        }

        const seen = new Map();
        for (const [primaryKey, value] of merged) {
          const indexKey = indexKeyOf(value, index.keyPath);
          if (indexKey === null) {
            continue;
          }
          const fingerprint = JSON.stringify(indexKey);
          if (seen.has(fingerprint)) {
            this._aborted = true;
            this.error = constraintError(
              `FakeIndexedDb: índice único «${name}.${indexName}» violado por dos filas.`
            );
            soon(() => {
              this.onabort?.();
              this.onerror?.();
            });

            return;
          }
          seen.set(fingerprint, String(primaryKey));
        }
      }
    }

    for (const [name, working] of this._working) {
      this.database.stores.get(name).data = working.data;
    }

    soon(() => this.oncomplete?.());
  }
}

class FakeObjectStore {
  /**
   * @param {FakeTransaction} transaction
   * @param {Object} store
   */
  constructor(transaction, store) {
    this.transaction = transaction;
    this.store = store;
    this.keyPath = store.keyPath;
  }

  put(value) {
    const request = new FakeRequest();
    const store = this.store;

    try {
      const key = primaryKeyOf(value, store.keyPath);
      if (key === null) {
        throw new Error('FakeIndexedDb: el registro no contiene su clave primaria.');
      }

      for (const index of store.indexes.values()) {
        if (!index.unique) {
          continue;
        }

        const candidate = indexKeyOf(value, index.keyPath);
        if (candidate === null) {
          continue;
        }

        const fingerprint = JSON.stringify(candidate);
        for (const [otherKey, otherValue] of store.data) {
          if (String(otherKey) === String(key)) {
            continue;
          }
          if (JSON.stringify(indexKeyOf(otherValue, index.keyPath)) === fingerprint) {
            throw constraintError(`FakeIndexedDb: índice único «${store.name}» violado.`);
          }
        }
      }

      store.data.set(key, structuredCloneValue(value));
      request.succeed(key);
      this.transaction._scheduleCommit();
    } catch (error) {
      this.transaction.error = this.transaction.error ?? error;
      this.transaction._aborted = true;
      request.fail(error);
      this.transaction._scheduleCommit();
    }

    return request;
  }

  get(key) {
    const request = new FakeRequest();
    request.succeed(structuredCloneValue(this.store.data.get(key) ?? undefined));
    this.transaction._scheduleCommit();

    return request;
  }

  delete(key) {
    const request = new FakeRequest();
    this.store.data.delete(key);
    request.succeed(undefined);
    this.transaction._scheduleCommit();

    return request;
  }

  index(name) {
    const index = this.store.indexes.get(name);
    if (!index) {
      throw new Error(`FakeIndexedDb: no existe el índice «${this.store.name}.${name}».`);
    }

    return new FakeIndex(this, index);
  }
}

class FakeIndex {
  constructor(store, index) {
    this.store = store;
    this.index = index;
  }

  getAll(key) {
    const request = new FakeRequest();
    const rows = [];
    for (const value of this.store.store.data.values()) {
      const candidate = indexKeyOf(value, this.index.keyPath);
      if (key === undefined || JSON.stringify(candidate) === JSON.stringify(key)) {
        rows.push(structuredCloneValue(value));
      }
    }
    request.succeed(rows);
    this.store.transaction._scheduleCommit();

    return request;
  }

  getAllKeys(key) {
    const request = new FakeRequest();
    const keys = [];
    for (const [primaryKey, value] of this.store.store.data) {
      const candidate = indexKeyOf(value, this.index.keyPath);
      if (key === undefined || JSON.stringify(candidate) === JSON.stringify(key)) {
        keys.push(primaryKey);
      }
    }
    request.succeed(keys);
    this.store.transaction._scheduleCommit();

    return request;
  }
}

class FakeObjectStoreNames {
  constructor(database) {
    this.database = database;
  }

  contains(name) {
    return this.database.stores.has(name);
  }
}

class FakeDatabase {
  constructor(version) {
    this.version = version;
    this.stores = new Map();
    this.objectStoreNames = new FakeObjectStoreNames(this);
  }

  createObjectStore(name, options = {}) {
    this.stores.set(name, {
      name,
      keyPath: options.keyPath ?? 'id',
      indexes: new Map(),
      data: new Map()
    });

    return {
      createIndex: (indexName, keyPath, indexOptions = {}) => {
        this.stores.get(name).indexes.set(indexName, {
          name: indexName,
          keyPath,
          unique: indexOptions.unique === true
        });
      }
    };
  }

  transaction(storeNames, mode = 'readonly') {
    const names = Array.isArray(storeNames) ? storeNames : [storeNames];
    for (const name of names) {
      if (!this.stores.has(name)) {
        throw new Error(`FakeIndexedDb: no existe el almacén «${name}».`);
      }
    }

    return new FakeTransaction(this, names, mode);
  }
}

class FakeFactory {
  constructor() {
    this.databases = new Map();
    this.version = 0;
  }

  open(name, version) {
    const request = new FakeRequest();
    const requested = Number(version ?? 1);
    const existing = this.databases.get(name);
    const database = existing ?? new FakeDatabase(0);

    if (existing === undefined) {
      this.databases.set(name, database);
    }

    const needsUpgrade = requested > database.version;
    request.result = database;

    // Una sola macrotarea reproduce el orden del motor: primero la actualización
    // de esquema (si toca) y después el `onsuccess` que abre la conexión.
    soon(() => {
      if (needsUpgrade) {
        database.version = requested;
        request.onupgradeneeded?.({ oldVersion: 0, newVersion: requested, target: request });
      }

      request.onsuccess?.();
    });

    return request;
  }
}

/** Clave primaria de un registro según su `keyPath` (simple o compuesto). */
function primaryKeyOf(value, keyPath) {
  if (Array.isArray(keyPath)) {
    return keyPath.map((segment) => value?.[segment]).join('::');
  }

  const key = value?.[keyPath];

  return key === undefined || key === null ? null : key;
}

/** Valor de un índice según su `keyPath`; `null` cuando el registro no lo declara. */
function indexKeyOf(value, keyPath) {
  if (Array.isArray(keyPath)) {
    const parts = keyPath.map((segment) => value?.[segment]);
    if (parts.some((part) => part === undefined || part === null)) {
      return null;
    }

    return parts;
  }

  const key = value?.[keyPath];

  return key === undefined || key === null ? null : key;
}

/** Copia profunda suficiente para valores JSON con `Blob` opcional. */
function structuredCloneValue(value) {
  if (value === undefined || value === null) {
    return value;
  }

  if (typeof structuredClone === 'function') {
    try {
      return structuredClone(value);
    } catch {
      // Los dobles de prueba pueden traer funciones; cae al copiado JSON.
    }
  }

  return JSON.parse(JSON.stringify(value));
}

/** Crea una fábrica de `IndexedDB` apta para `new OfflineStorage({ indexedDB })`. */
export function createFakeIndexedDb() {
  return new FakeFactory();
}

export default createFakeIndexedDb;
