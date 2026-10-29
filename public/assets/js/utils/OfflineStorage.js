/**
 * VendGuard - Copia local de ruta y bandeja de mutaciones (utils/OfflineStorage.js)
 *
 * Adaptador nativo sobre `IndexedDB` para que el técnico trabaje sin cobertura
 * (Módulo 24, RF-02, RF-03 y RF-06). Este módulo es la única fuente de verdad del
 * navegador sobre cuatro cosas que el servidor no puede decidir por él:
 *
 *  - **Aislamiento por técnico (`Art. V.4`)**: el almacén `route_snapshots` usa
 *    `technician_id` como clave, y `mutation_outbox` filtra siempre por ese
 *    identificador. Un dispositivo compartido nunca mezcla colas de dos cuentas.
 *  - **Vigencia de la copia (`D6`)**: `FRESH` hasta 4 h, aviso ámbar entre 4 h y
 *    12 h, y solo lectura a partir de 12 h. El paquete trae `warn_after_seconds`
 *    y `readonly_after_seconds` del servidor, así que la política no se
 *    reimplementa aquí: solo se compara la edad con los umbrales del contrato.
 *  - **Mutación optimista y stock provisional (`D5`)**: al encolar se refleja el
 *    nuevo estado en la parada local y se descuenta del saldo visible de la
 *    furgoneta lo que el técnico declara consumir, para que las paradas
 *    siguientes muestren el stock real. La reversión es exactamente simétrica.
 *  - **Atomicidad de la copia y de la cola (`H4`, `M2`)**: el alta de una
 *    mutación y su reflejo optimista viajan en **una sola** transacción de
 *    `IndexedDB`, igual que toda lectura-modificación-escritura del snapshot.
 *    Ni el cambio local puede quedar sin su entrada en la cola, ni dos
 *    escrituras concurrentes pueden pisarse y perder la de quien llegó primero.
 *  - **Cuota fotográfica (`D3`, `QA 3`)**: máximo 5 fotografías o 25 MB en la
 *    bandeja. El trazo PNG de la firma digital (`signature_png_base64`, exento)
 *    nunca se cuenta aquí: solo pesan los adjuntos de cámara o galería.
 *  - **Evidencia como `Blob` (`M3`, DB v2)**: la foto se guarda binaria, tal
 *    como la produce la cámara, en lugar de una cadena base64 un 33 % mayor.
 *  - **Retención y minimización (`M3`, `R4`)**: la copia solo persiste lo que el
 *    trabajo sin red necesita —se descartan el correo y el nombre del contacto
 *    de sede, que no se pintan en la ruta— y cualquier sesión ajena o caducada
 *    sin trabajo pendiente se purga del dispositivo compartido (`Art. V.4`).
 *    La actualización a la v2 pasa también por la poda las copias que la v1 ya
 *    había guardado, para que la minimización no dependa del siguiente paquete.
 *
 * Sin `IndexedDB` disponible (Node en las pruebas unitarias) el módulo degrada a
 * un respaldo en memoria con la misma interfaz, de modo que la lógica de cola y
 * de vigencia se certifica sin navegador y sin dependencias externas.
 *
 * Dogma Vanilla: ESM nativo, funciones puras, datos congelados, cero dependencias.
 */

/**
 * Nombre de la base local. Se conserva el de la v1 a propósito: renombrarla
 * dejaría huérfana la bandeja de quien ya tenía trabajo sin sincronizar.
 */
export const OFFLINE_DB_NAME = 'vendguard_offline_v1';

/**
 * Versión 2 de la base local (`M3`).
 *
 * El salto de la v1 a la v2 no toca el esquema: cambia el contenido de
 * `photo_attachment`, que pasa de `base64` a `blob`. La actualización convierte
 * las evidencias ya encoladas para no perder ni un archivo, y el campo `blob`
 * evita la copia base64 (un 33 % más de bytes y una cadena gigante en memoria).
 */
export const OFFLINE_DB_VERSION = 2;
export const ROUTE_SNAPSHOTS_STORE = 'route_snapshots';
export const MUTATION_OUTBOX_STORE = 'mutation_outbox';

/** Aviso ámbar de la copia local: 4,0 horas (`D6`, `RF-02.2`). */
export const SNAPSHOT_WARNING_AGE_MS = 4 * 60 * 60 * 1000;
/** Paso a solo lectura: 12,0 horas (`D6`, `RF-02.3`). */
export const SNAPSHOT_MAX_VALID_AGE_MS = 12 * 60 * 60 * 1000;

/**
 * Estados de turno que la copia local puede recordar (`EARS 03.1`).
 *
 * Iniciar una intervención sin red exige que el técnico siga en turno: al
 * recargar la PWA, el estado del turno se restaura desde la misma copia con la
 * que se pinta la ruta. La lista es cerrada para no persistir texto libre.
 */
export const OFFLINE_SHIFT_STATUSES = Object.freeze(['ON_SHIFT', 'MEAL_BREAK', 'OFF_SHIFT']);

/** Cuota de evidencias sin conexión (`D3`, `QA 3`, `Art. V.5`). */
export const OFFLINE_PHOTO_MAX_COUNT = 5;
export const OFFLINE_PHOTO_MAX_SINGLE_BYTES = 5 * 1024 * 1024;
export const OFFLINE_PHOTO_MAX_TOTAL_BYTES = 25 * 1024 * 1024;
/** Tope del trazo de firma, exento de la cuota porque cierra la intervención. */
export const OFFLINE_SIGNATURE_MAX_BYTES = 300 * 1024;

/** Extensiones y tipos gráficos seguros admitidos (`EARS 03.5.2`). */
export const OFFLINE_PHOTO_EXTENSIONS = Object.freeze(['jpg', 'jpeg', 'png', 'webp']);
export const OFFLINE_PHOTO_MIME_TYPES = Object.freeze(['image/jpeg', 'image/png', 'image/webp']);

/** Estados de vigencia de la copia local (`RF-02.2` a `RF-02.4`). */
export const SNAPSHOT_VALIDITY = Object.freeze({
  MISSING: 'MISSING',
  FRESH: 'FRESH',
  STALE_WARNING: 'STALE_WARNING',
  EXPIRED_READONLY: 'EXPIRED_READONLY'
});

/** Estados de un elemento de la bandeja (`D3`, `RF-05.1`). */
export const MUTATION_STATUS = Object.freeze({
  PENDING: 'PENDING',
  SYNCING: 'SYNCING',
  CONFLICT: 'CONFLICT'
});

/**
 * Margen a partir del cual un envío en `SYNCING` se considera huérfano.
 *
 * El estado solo existe mientras una pestaña está enviando. Si la pestaña muere
 * en ese instante —el sistema la mata, el móvil se apaga, se cierra el navegador—
 * el elemento se queda en `SYNCING` y nadie vuelve a mirarlo: la bandeja lo
 * enseña como «enviándose» para siempre y el cierre de sesión queda bloqueado
 * (`EARS 06.1`). El margen es holgado a propósito: por debajo de él puede haber
 * un envío legítimo de otra pestaña en curso (una foto de 5 MB tarda), y
 * reencolarlo sería enviarlo dos veces.
 */
export const SYNCING_STALE_AFTER_MS = 2 * 60 * 1000;

/**
 * Intentos de alta cuando otra pestaña se adelantó con el mismo turno (`H4`).
 *
 * El índice único `by_tech_sequence` aborta la transacción entera, incluida la
 * proyección: reintentar releyendo el máximo no duplica nada y evita perder el
 * trabajo. Con el cerrojo entre pestañas de `OfflineSyncManager` el reintento es
 * una red de seguridad, no el camino normal.
 */
export const ENQUEUE_SEQUENCE_RETRIES = 3;

/** Tipos de parada del catálogo cerrado (`D1`). */
export const STOP_TYPE = Object.freeze({
  INCIDENT: 'INCIDENT',
  PREVENTIVE_ORDER: 'PREVENTIVE_ORDER'
});

/**
 * Tipos de máquina bajo vigilancia sanitaria, espejo de
 * `MachineType::requiresSanitaryWatch()`.
 */
export const PERISHABLE_MACHINE_TYPES = Object.freeze(['PERISHABLE_FOOD', 'COMBO']);

/** Estados de ítem de checklist que exigen precinto físico in situ (`QA 1`). */
export const SANITARY_RISK_CHECKLIST_STATUSES = Object.freeze(['FAIL', 'NO_CONFORME', 'WARN']);

/**
 * Catálogo cerrado de operaciones encolables (`D1`) con su ruta real, método y
 * naturaleza de transición. `is_state_transition: false` solo para el comentario
 * intermedio, que puede fallar sin arrastrar el cierre posterior de la parada
 * (`RF-05.1.2`, `QA 3`).
 */
export const OFFLINE_OPERATIONS = Object.freeze({
  START_INCIDENT: Object.freeze({
    stopType: STOP_TYPE.INCIDENT,
    method: 'PATCH',
    isStateTransition: true,
    path: (stopId) => `/technician/incidents/${encodeURIComponent(stopId)}/start`
  }),
  RESUME_INCIDENT_PENDING_INFO: Object.freeze({
    stopType: STOP_TYPE.INCIDENT,
    method: 'POST',
    isStateTransition: true,
    path: (stopId) => `/technician/incidents/${encodeURIComponent(stopId)}/resume-pending-info`
  }),
  PAUSE_INCIDENT_PARTS: Object.freeze({
    stopType: STOP_TYPE.INCIDENT,
    method: 'PATCH',
    isStateTransition: true,
    path: (stopId) => `/technician/incidents/${encodeURIComponent(stopId)}/pause`
  }),
  PAUSE_INCIDENT_PENDING_INFO: Object.freeze({
    stopType: STOP_TYPE.INCIDENT,
    method: 'POST',
    isStateTransition: true,
    path: (stopId) => `/technician/incidents/${encodeURIComponent(stopId)}/pause-pending-info`
  }),
  RESOLVE_INCIDENT: Object.freeze({
    stopType: STOP_TYPE.INCIDENT,
    method: 'POST',
    isStateTransition: true,
    path: (stopId) => `/technician/incidents/${encodeURIComponent(stopId)}/resolve`
  }),
  ADD_INCIDENT_COMMENT: Object.freeze({
    stopType: STOP_TYPE.INCIDENT,
    method: 'POST',
    isStateTransition: false,
    path: (stopId) => `/technician/incidents/${encodeURIComponent(stopId)}/comments`
  }),
  START_PREVENTIVE: Object.freeze({
    stopType: STOP_TYPE.PREVENTIVE_ORDER,
    method: 'POST',
    isStateTransition: true,
    path: (stopId) => `/technician/preventive/orders/${encodeURIComponent(stopId)}/start`
  }),
  COMPLETE_PREVENTIVE: Object.freeze({
    stopType: STOP_TYPE.PREVENTIVE_ORDER,
    method: 'POST',
    isStateTransition: true,
    path: (stopId) => `/technician/preventive/orders/${encodeURIComponent(stopId)}/complete`
  }),
  REINSPECT_PREVENTIVE: Object.freeze({
    stopType: STOP_TYPE.PREVENTIVE_ORDER,
    method: 'POST',
    isStateTransition: true,
    path: (stopId) => `/technician/preventive/orders/${encodeURIComponent(stopId)}/reinspect`
  })
});

/**
 * Estado local que refleja cada operación al instante (`EARS 03.3.2`).
 * El comentario intermedio no cambia el estado de la parada.
 */
export const OPTIMISTIC_STATUS_BY_OPERATION = Object.freeze({
  START_INCIDENT: 'IN_PROGRESS',
  RESUME_INCIDENT_PENDING_INFO: 'IN_PROGRESS',
  PAUSE_INCIDENT_PARTS: 'PENDING_PARTS',
  PAUSE_INCIDENT_PENDING_INFO: 'PENDING_INFO',
  RESOLVE_INCIDENT: 'RESOLVED',
  ADD_INCIDENT_COMMENT: null,
  START_PREVENTIVE: 'IN_INSPECTION',
  COMPLETE_PREVENTIVE: 'COMPLETED',
  REINSPECT_PREVENTIVE: 'COMPLETED'
});

/** Textos normativos del módulo (castellano neutro, `RNF-04`). */
export const OFFLINE_MESSAGES = Object.freeze({
  STALE_WARNING:
    '⚠️ Tu copia de ruta lleva más de 4 horas sin actualizarse. Recupera cobertura cuanto antes, '
    + 'especialmente si atiendes máquinas de alimentos perecederos.',
  EXPIRED_READONLY:
    '🔒 Copia de ruta caducada (> 12 h sin conexión). Conéctate para actualizar tu ruta antes de '
    + 'registrar nuevas intervenciones.',
  SANITARY_SEAL_REQUIRED:
    'Debes confirmar que has precintado físicamente la máquina in situ antes de guardar.',
  PHOTO_QUOTA_EXCEEDED:
    'Se ha alcanzado el límite de seguridad sin conexión (máx. 5 fotografías o 25 MB en cola). '
    + 'Guarda sin foto o sincroniza primero.',
  PHOTO_TOO_LARGE:
    'La fotografía supera los 5 MB permitidos o está vacía. Reduce su tamaño o guarda la acción sin foto.',
  PHOTO_UNSUPPORTED_FORMAT:
    'Formato de imagen no admitido. Adjunta una fotografía JPG, PNG o WebP.',
  OPERATION_NOT_OFFLINE: 
    'Esta operación necesita conexión en vivo con el servidor. Recupera cobertura para completarla.',
  UNPARSEABLE_SNAPSHOT:
    'La copia local de ruta no tiene una fecha de sincronización válida. Conéctate para restaurarla.'
});

/**
 * Error de la cola sin conexión: viaja con código estable para que la interfaz
 * distinga el motivo exacto sin leer el texto (`EARS 03.5.2`).
 */
export class OfflineQueueError extends Error {
  /**
   * @param {string} code - Código estable (`SNAPSHOT_EXPIRED_READONLY`, `OFFLINE_PHOTO_QUOTA_EXCEEDED`...)
   * @param {string} message - Mensaje en castellano para el técnico
   * @param {Object|null} [details=null]
   */
  constructor(code, message, details = null) {
    super(message);
    this.name = 'OfflineQueueError';
    this.code = code;
    this.details = details;
  }
}

/**
 * Evalúa la vigencia de la copia local frente a cuatro umbrales del contrato
 * (`D6`, `RF-02.2` a `RF-02.4`).
 *
 * @param {Object|null} snapshot - Copia local guardada, o `null` si no existe.
 * @param {number} [nowMs=Date.now()] - Reloj de referencia (inyectable en pruebas).
 * @returns {{status: string, canEnqueueNewActions: boolean, ageMinutes: (number|null),
 *   warningMessage?: string, errorMessage?: string}}
 */
export function evaluateSnapshotValidity(snapshot, nowMs = Date.now()) {
  if (!snapshot) {
    return { status: SNAPSHOT_VALIDITY.MISSING, canEnqueueNewActions: false, ageMinutes: null };
  }

  const syncedAtMs = Date.parse(String(snapshot.synced_at ?? ''));
  if (!Number.isFinite(syncedAtMs)) {
    return {
      status: SNAPSHOT_VALIDITY.EXPIRED_READONLY,
      canEnqueueNewActions: false,
      ageMinutes: null,
      errorMessage: OFFLINE_MESSAGES.UNPARSEABLE_SNAPSHOT
    };
  }

  const ageMs = Math.max(0, nowMs - syncedAtMs);
  const ageMinutes = Math.floor(ageMs / 60000);

  if (ageMs <= SNAPSHOT_WARNING_AGE_MS) {
    return { status: SNAPSHOT_VALIDITY.FRESH, canEnqueueNewActions: true, ageMinutes };
  }

  if (ageMs <= SNAPSHOT_MAX_VALID_AGE_MS) {
    return {
      status: SNAPSHOT_VALIDITY.STALE_WARNING,
      canEnqueueNewActions: true,
      ageMinutes,
      warningMessage: OFFLINE_MESSAGES.STALE_WARNING
    };
  }

  return {
    status: SNAPSHOT_VALIDITY.EXPIRED_READONLY,
    canEnqueueNewActions: false,
    ageMinutes,
    errorMessage: OFFLINE_MESSAGES.EXPIRED_READONLY
  };
}

/**
 * Estado de turno restaurable desde la copia local (`EARS 03.1`).
 *
 * La restauración se ata a la vigencia de la copia y a la antigüedad del propio
 * registro: ni una copia caducada ni un turno anotado hace más de 12 h pueden
 * reabrir la puerta de una intervención sin red. Sin registro válido la vista
 * conserva su valor por defecto (`OFF_SHIFT`), que es el fallo seguro.
 *
 * @param {Object|null} snapshot - Copia local del técnico.
 * @param {number} [nowMs=Date.now()] - Reloj de referencia (inyectable en pruebas).
 * @returns {{status: (string|null), recordedAt: (string|null), ageMinutes: (number|null), restorable: boolean}}
 */
export function evaluateOfflineShift(snapshot, nowMs = Date.now()) {
  const record = snapshot?.offline_shift ?? null;
  const status = String(record?.status ?? '').toUpperCase();

  if (!OFFLINE_SHIFT_STATUSES.includes(status)) {
    return { status: null, recordedAt: null, ageMinutes: null, restorable: false };
  }

  const recordedAtMs = Date.parse(String(record?.recorded_at ?? ''));
  if (!Number.isFinite(recordedAtMs)) {
    return { status: null, recordedAt: null, ageMinutes: null, restorable: false };
  }

  const validity = evaluateSnapshotValidity(snapshot, nowMs);
  const copyIsUsable = validity.status === SNAPSHOT_VALIDITY.FRESH
    || validity.status === SNAPSHOT_VALIDITY.STALE_WARNING;
  const ageMs = Math.max(0, nowMs - recordedAtMs);

  return {
    status,
    recordedAt: new Date(recordedAtMs).toISOString(),
    ageMinutes: Math.floor(ageMs / 60000),
    restorable: copyIsUsable && ageMs <= SNAPSHOT_MAX_VALID_AGE_MS
  };
}

/**
 * ¿La operación exige el precinto físico in situ? (`EARS 03.4`, `QA 1`, `Art. II`)
 *
 * Espejo exacto de `OfflineSyncService`: sobre una máquina bajo vigilancia
 * sanitaria toda pausa es riesgo por definición, y en preventivas el riesgo lo
 * declara el propio checklist remitido. Ninguna otra operación encolada lo exige.
 *
 * @param {string} operationType
 * @param {Object} payload
 * @param {string|null} machineType
 * @returns {boolean}
 */
export function requiresSanitarySeal(operationType, payload = {}, machineType = null) {
  if (!PERISHABLE_MACHINE_TYPES.includes(String(machineType ?? '').toUpperCase())) {
    return false;
  }

  if (operationType === 'PAUSE_INCIDENT_PARTS' || operationType === 'PAUSE_INCIDENT_PENDING_INFO') {
    return true;
  }

  if (operationType === 'COMPLETE_PREVENTIVE') {
    const items = Array.isArray(payload?.items) ? payload.items : [];
    return items.some((item) => SANITARY_RISK_CHECKLIST_STATUSES.includes(
      String(item?.status ?? '').toUpperCase()
    ));
  }

  return false;
}

/**
 * Repuestos catalogados que la operación declara consumidos (`D5`, `EARS 03.3.3`).
 *
 * @param {string} operationType
 * @param {Object} payload
 * @returns {Array<{spare_part_id: number, quantity: number}>}
 */
export function extractConsumedCatalogParts(operationType, payload = {}) {
  if (operationType !== 'RESOLVE_INCIDENT' && operationType !== 'COMPLETE_PREVENTIVE') {
    return [];
  }

  const declared = Array.isArray(payload?.replaced_parts) ? payload.replaced_parts : [];

  return declared
    .map((part) => ({
      spare_part_id: Number(part?.spare_part_id ?? 0),
      quantity: Number(part?.quantity ?? 0)
    }))
    .filter((part) => Number.isInteger(part.spare_part_id) && part.spare_part_id > 0
      && Number.isInteger(part.quantity) && part.quantity > 0);
}

/**
 * Valida la fotografía antes de encolarla (`EARS 03.5.2`, `QA 3`, `Art. V.5`).
 *
 * La firma digital no entra aquí a propósito: viaja dentro de
 * `payload.service_sheet.signature_png_base64` y no consume ni cupo ni bytes.
 *
 * @param {{name?: string, type?: string, size?: number}|null} photo
 * @param {Array<Object>} activeItems - Elementos ya presentes en la bandeja del técnico.
 * @returns {{name: string, type: string, size: number}|null}
 * @throws {OfflineQueueError}
 */
export function validatePhotoAttachment(photo, activeItems = []) {
  if (!photo) {
    return null;
  }

  const name = String(photo.name ?? '');
  const type = String(photo.type ?? '').toLowerCase();
  const size = Number(photo.size ?? 0);
  const extension = name.includes('.') ? name.split('.').pop().toLowerCase() : '';

  if (!OFFLINE_PHOTO_MIME_TYPES.includes(type) && !OFFLINE_PHOTO_EXTENSIONS.includes(extension)) {
    throw new OfflineQueueError('OFFLINE_PHOTO_UNSUPPORTED_FORMAT', OFFLINE_MESSAGES.PHOTO_UNSUPPORTED_FORMAT);
  }

  if (!Number.isFinite(size) || size <= 0 || size > OFFLINE_PHOTO_MAX_SINGLE_BYTES) {
    throw new OfflineQueueError('OFFLINE_PHOTO_TOO_LARGE', OFFLINE_MESSAGES.PHOTO_TOO_LARGE);
  }

  const withPhoto = activeItems.filter((item) => item?.photo_attachment);
  const totalBytes = withPhoto.reduce(
    (sum, item) => sum + Number(item.photo_attachment.size ?? 0),
    0
  );

  if (withPhoto.length + 1 > OFFLINE_PHOTO_MAX_COUNT
    || totalBytes + size > OFFLINE_PHOTO_MAX_TOTAL_BYTES) {
    throw new OfflineQueueError('OFFLINE_PHOTO_QUOTA_EXCEEDED', OFFLINE_MESSAGES.PHOTO_QUOTA_EXCEEDED);
  }

  return { name, type, size };
}

/**
 * Serializa la evidencia fotográfica para que sobreviva al cierre de la pestaña.
 *
 * `IndexedDB` persiste `Blob` de forma nativa, así que la v2 guarda el archivo
 * binario tal cual: sin la cadena base64 (un 33 % más de bytes) y sin tener que
 * decodificarla en memoria al reenviar. El respaldo `base64` solo se usa en un
 * entorno sin `Blob` (pruebas antiguas o un navegador sin soporte).
 *
 * @param {(Blob|File|{name?: string, type?: string, size?: number, base64?: string}|null)} photo
 * @returns {Promise<{name: string, type: string, size: number, blob?: Blob, base64?: string}|null>}
 */
export async function serializePhoto(photo) {
  if (!photo) {
    return null;
  }

  const name = String(photo.name ?? 'evidencia.jpg');
  const type = String(photo.type ?? 'image/jpeg');
  const size = Number(photo.size ?? 0);

  if (typeof Blob !== 'undefined') {
    const blob = photo instanceof Blob ? photo : photoAttachmentBlob(photo);

    if (blob !== null) {
      return {
        name,
        type: String(blob.type || type),
        size: Number(blob.size ?? size),
        blob
      };
    }
  }

  return {
    name,
    type,
    size,
    base64: typeof photo.base64 === 'string' ? photo.base64 : await readAsBase64(photo)
  };
}

/**
 * Devuelve el `Blob` de una foto serializada, venga como `blob` (v2) o como
 * `base64` (v1 en un navegador sin `Blob`).
 *
 * @param {{type?: string, blob?: Blob, base64?: string}|null} attachment
 * @returns {Blob|null}
 */
export function photoAttachmentBlob(attachment) {
  if (!attachment) {
    return null;
  }

  if (typeof Blob !== 'undefined' && attachment.blob instanceof Blob) {
    return attachment.blob;
  }

  if (!attachment.base64 || typeof Blob === 'undefined' || typeof atob !== 'function') {
    return null;
  }

  const binary = atob(attachment.base64);
  const bytes = new Uint8Array(binary.length);
  for (let index = 0; index < binary.length; index++) {
    bytes[index] = binary.charCodeAt(index);
  }

  return new Blob([bytes], { type: attachment.type || 'image/jpeg' });
}

/**
 * Campos de contacto que la copia local no necesita (`M3`, `Art. V.4`).
 *
 * El teléfono se conserva porque la tarjeta ofrece la llamada en un toque y sin
 * red es la única forma de avisar a la sede. El correo y el nombre del contacto
 * no se pintan en ninguna pantalla offline: guardarlos sería acumular datos
 * personales en un dispositivo que se comparte entre turnos.
 */
export const SNAPSHOT_CONTACT_PII_FIELDS = Object.freeze(['contact_email', 'contact_name']);

/**
 * Reduce la copia local a lo operativo (`M3`): elimina los campos de contacto
 * que no se usan sin red, recorriendo la copia sin mutarla.
 *
 * @param {Object|null} value
 * @returns {Object|null}
 */
export function minimizeSnapshot(value) {
  if (value === null || value === undefined || typeof value !== 'object') {
    return value ?? null;
  }

  if (typeof Blob !== 'undefined' && value instanceof Blob) {
    return value;
  }

  if (Array.isArray(value)) {
    return value.map((item) => minimizeSnapshot(item));
  }

  const next = {};
  for (const [key, item] of Object.entries(value)) {
    if (SNAPSHOT_CONTACT_PII_FIELDS.includes(key)) {
      continue;
    }
    next[key] = minimizeSnapshot(item);
  }

  return next;
}

/**
 * Convierte una entrada de la bandeja de la v1 a la forma de la v2 (`M3`).
 *
 * La migración de esquema no puede perder una evidencia: si el `base64` se deja
 * decodificar, el registro se reescribe con su `Blob`; si no, se conserva tal
 * cual y el envío lo decodifica en el último momento.
 *
 * @param {Object} record
 * @returns {Object}
 */
export function migrateQueuedPhotoAttachment(record) {
  const attachment = record?.photo_attachment ?? null;

  if (attachment === null || typeof attachment.base64 !== 'string' || attachment.base64 === '') {
    return record;
  }

  const blob = photoAttachmentBlob(attachment);
  if (blob === null) {
    return record;
  }

  return {
    ...record,
    photo_attachment: {
      name: String(attachment.name ?? 'evidencia.jpg'),
      type: String(blob.type || attachment.type || 'image/jpeg'),
      size: Number(blob.size ?? attachment.size ?? 0),
      blob
    }
  };
}

/**
 * Normaliza el paquete de bootstrap a la forma que exige el contrato §3.1.
 *
 * Los instantes de aviso y caducidad se derivan de `synced_at` más los segundos
 * que manda el servidor: la PWA nunca decide la política por su cuenta (`D6`).
 * Si se refresca la copia, los descuentos provisionales pendientes se conservan
 * y se vuelven a aplicar sobre el saldo nuevo de la furgoneta.
 *
 * @param {Object} bootstrap - Respuesta de `GET /api/technician/offline/bootstrap`
 * @param {Object|null} [previous=null] - Copia local anterior del mismo técnico
 * @returns {Object}
 * @throws {OfflineQueueError}
 */
export function normalizeSnapshot(bootstrap, previous = null) {
  const technicianId = Number(bootstrap?.technician_id);
  if (!Number.isInteger(technicianId) || technicianId < 1) {
    throw new OfflineQueueError(
      'INVALID_BOOTSTRAP_SNAPSHOT',
      'El paquete de ruta no identifica al técnico autenticado.'
    );
  }

  const syncedAtMs = Date.parse(String(bootstrap?.synced_at ?? ''));
  if (!Number.isFinite(syncedAtMs)) {
    throw new OfflineQueueError(
      'INVALID_BOOTSTRAP_SNAPSHOT',
      'El paquete de ruta no declara un instante de sincronización válido.'
    );
  }

  const warnSeconds = positiveNumberOr(bootstrap?.warn_after_seconds, SNAPSHOT_WARNING_AGE_MS / 1000);
  const readonlySeconds = positiveNumberOr(
    bootstrap?.readonly_after_seconds,
    SNAPSHOT_MAX_VALID_AGE_MS / 1000
  );
  const route = bootstrap?.route ?? {};

  const snapshot = {
    technician_id: technicianId,
    technician_email: bootstrap?.technician_email ?? null,
    technician_name: bootstrap?.technician_name ?? null,
    synced_at: new Date(syncedAtMs).toISOString(),
    warn_after_at: new Date(syncedAtMs + warnSeconds * 1000).toISOString(),
    readonly_after_at: new Date(syncedAtMs + readonlySeconds * 1000).toISOString(),
    route_envelope: {
      stops: Array.isArray(route?.stops) ? route.stops : [],
      enabled_locations: Array.isArray(route?.enabled_locations) ? route.enabled_locations : [],
      pending_critical_stops_other_locations: Array.isArray(route?.pending_critical_stops_other_locations)
        ? route.pending_critical_stops_other_locations
        : []
    },
    preventive_orders: Array.isArray(bootstrap?.preventive_orders) ? bootstrap.preventive_orders : [],
    preventive_checklists_by_order: bootstrap?.preventive_checklists_by_order ?? {},
    spare_parts_catalog_by_machine: bootstrap?.spare_parts_catalog_by_machine ?? {},
    van_stock: normalizeVanStock(bootstrap?.van_stock),
    provisional_stock_deductions: { ...(previous?.provisional_stock_deductions ?? {}) },
    // Campo local, ausente del paquete del servidor: refrescar la ruta no debe
    // borrar el turno que el técnico ya tenía anotado en este dispositivo.
    offline_shift: normalizeOfflineShift(previous?.offline_shift)
  };

  // El saldo que llega del servidor aún no conoce los consumos encolados: se
  // vuelven a descontar para que el stock visible siga siendo el real.
  for (const part of provisionalParts(previous)) {
    decrementAvailable(snapshot.van_stock, part);
  }

  // Minimización antes de persistir (`M3`): lo que no se necesita sin red no se
  // guarda, y menos aún en un dispositivo compartido entre turnos.
  return minimizeSnapshot(snapshot);
}

/**
 * Refleja al instante el estado que produce cada operación y descuenta el stock
 * provisional declarado (`D5`, `EARS 03.3.2` y `EARS 03.3.3`).
 *
 * No muta la copia recibida: devuelve una nueva, para que la vista pueda
 * comparar antes y después sin sorpresas.
 *
 * @param {Object} snapshot
 * @param {{stopType: string, stopId: number|string, operationType: string, payload?: Object}} change
 * @returns {Object|null}
 */
export function applyOptimisticStopChange(snapshot, change) {
  if (!snapshot) {
    return null;
  }

  const targetId = Number(change?.stopId ?? 0);
  const operationType = String(change?.operationType ?? '');
  const nextStatus = OPTIMISTIC_STATUS_BY_OPERATION[operationType] ?? null;
  const parts = extractConsumedCatalogParts(operationType, change?.payload ?? {});

  const next = {
    ...snapshot,
    route_envelope: {
      ...(snapshot.route_envelope ?? {}),
      stops: Array.isArray(snapshot.route_envelope?.stops) ? [...snapshot.route_envelope.stops] : []
    },
    preventive_orders: Array.isArray(snapshot.preventive_orders) ? [...snapshot.preventive_orders] : [],
    van_stock: normalizeVanStock(snapshot.van_stock),
    provisional_stock_deductions: { ...(snapshot.provisional_stock_deductions ?? {}) }
  };

  if (nextStatus !== null) {
    if (change?.stopType === STOP_TYPE.PREVENTIVE_ORDER) {
      next.preventive_orders = next.preventive_orders.map((order) => (
        Number(order?.id) === targetId ? { ...order, status: nextStatus } : order
      ));
    } else {
      next.route_envelope.stops = next.route_envelope.stops.map((stop) => (
        Number(stop?.incident_id ?? stop?.id) === targetId ? { ...stop, status: nextStatus } : stop
      ));
    }
  }

  applyStockDeduction(next, parts);
  return next;
}

/**
 * Devuelve a la furgoneta el stock que se descontó de forma provisional cuando
 * una transición acaba en conflicto (`RF-05.1.1`).
 *
 * @param {Object} snapshot
 * @param {Array<{spare_part_id: number, quantity: number}>} parts
 * @returns {Object|null}
 */
export function revertProvisionalStockChange(snapshot, parts = []) {
  if (!snapshot) {
    return null;
  }

  const next = {
    ...snapshot,
    van_stock: normalizeVanStock(snapshot.van_stock),
    provisional_stock_deductions: { ...(snapshot.provisional_stock_deductions ?? {}) }
  };

  for (const part of parts) {
    const key = String(part.spare_part_id);
    const pending = (next.provisional_stock_deductions[key] ?? 0) - Number(part.quantity);

    if (pending > 0) {
      next.provisional_stock_deductions[key] = pending;
    } else {
      delete next.provisional_stock_deductions[key];
    }

    const item = next.van_stock.items.find(
      (candidate) => Number(candidate?.spare_part_id) === Number(part.spare_part_id)
    );
    if (item) {
      item.quantity_available = Number(item.quantity_available ?? 0) + Number(part.quantity);
    }
  }

  return next;
}

/**
 * Validez mínima del saldo de furgoneta para poder descontar sin romper la vista.
 *
 * @param {Object|null} vanStock
 * @returns {{items: Array<Object>, active_dispatched_order: (Object|null), last_confirmed_order: (Object|null)}}
 */
export function normalizeVanStock(vanStock) {
  const items = Array.isArray(vanStock?.items)
    ? vanStock.items.map((item) => ({ ...item }))
    : [];

  return {
    items,
    active_dispatched_order: vanStock?.active_dispatched_order ?? null,
    last_confirmed_order: vanStock?.last_confirmed_order ?? null
  };
}

/**
 * Reduce el turno persistido a la forma segura del contrato local.
 *
 * Un valor desconocido o una fecha ilegible se descartan: es preferible perder
 * el atajo sin red a abrir la puerta del turno con datos corruptos.
 *
 * @param {{status?: string, recorded_at?: string}|null|undefined} record
 * @returns {{status: string, recorded_at: string}|null}
 */
function normalizeOfflineShift(record) {
  const status = String(record?.status ?? '').toUpperCase();
  const recordedAtMs = Date.parse(String(record?.recorded_at ?? ''));

  if (!OFFLINE_SHIFT_STATUSES.includes(status) || !Number.isFinite(recordedAtMs)) {
    return null;
  }

  return { status, recorded_at: new Date(recordedAtMs).toISOString() };
}

/**
 * Adaptador de almacenamiento local aislado por técnico.
 *
 * Todas las operaciones son asíncronas: `IndexedDB` lo es, y el respaldo en
 * memoria conserva la misma firma para que la lógica de cola no cambie entre
 * navegador y pruebas.
 */
export class OfflineStorage {
  /**
   * @param {{indexedDB?: (IDBFactory|null)}} [options={}]
   */
  constructor(options = {}) {
    const factory = options.indexedDB !== undefined
      ? options.indexedDB
      : (typeof globalThis !== 'undefined' ? globalThis.indexedDB : undefined);

    this.factory = factory ?? null;
    this.memory = this.factory === null ? { snapshots: new Map(), outbox: new Map() } : null;
    this.dbPromise = null;
    // Turno de escritura de la copia local dentro de esta pestaña (`M2`).
    //
    // IndexedDB ya aísla cada transacción, pero el patrón `leer → transformar →
    // escribir` abría una ventana entre el `get` y el `put`: dos acciones del
    // mismo hilo pedían la versión anterior y la segunda borraba la primera.
    // Encadenarlas convierte esa ventana en una cola estricta.
    this.snapshotChain = Promise.resolve();
  }

  /** ¿Está funcionando con el respaldo en memoria? (solo pruebas y entornos sin IndexedDB). */
  isMemoryBacked() {
    return this.memory !== null;
  }

  /**
   * Guarda la copia local del técnico a partir del paquete de bootstrap.
   * @param {Object} bootstrap
   * @returns {Promise<Object>} Copia normalizada ya persistida
   */
  async saveSnapshot(bootstrap) {
    const technicianId = Number(bootstrap?.technician_id);

    // Un paquete sin técnico válido no llega a tocar el almacén: el normalizador
    // es quien lanza el error del contrato (`INVALID_BOOTSTRAP_SNAPSHOT`).
    if (!Number.isInteger(technicianId) || technicianId < 1) {
      return normalizeSnapshot(bootstrap, null);
    }

    return this.mutateSnapshot(technicianId, (previous) => normalizeSnapshot(bootstrap, previous));
  }

  /**
   * Lee, transforma y guarda la copia local dentro de una sola transacción (`M2`).
   *
   * El mutador recibe la copia vigente —o `null`— y devuelve la nueva, o
   * `null`/`undefined` para no tocar nada. Debe ser **síncrono**: una promesa
   * intermedia dejaría que `IndexedDB` cerrase la transacción antes de escribir,
   * que es exactamente la pérdida de escritura que esta puerta existe para no
   * permitir.
   *
   * Dentro de la pestaña las llamadas se encadenan; entre pestañas el cerrojo de
   * `OfflineSyncManager` las serializa.
   *
   * @param {number} technicianId
   * @param {(snapshot: (Object|null)) => (Object|null|undefined)} mutator
   * @returns {Promise<Object|null>} Copia resultante
   */
  async mutateSnapshot(technicianId, mutator) {
    const key = Number(technicianId);

    if (!Number.isInteger(key) || key < 1) {
      throw new OfflineQueueError(
        'INVALID_BOOTSTRAP_SNAPSHOT',
        'La copia local exige un técnico autenticado.'
      );
    }

    const run = () => this.runSnapshotMutation(key, mutator);
    const operation = this.snapshotChain.then(run, run);
    // La cadena no se contamina con el fallo: una escritura rechazada no puede
    // dejar bloqueadas las siguientes.
    this.snapshotChain = operation.then(() => undefined, () => undefined);

    return operation;
  }

  /**
   * @param {number} technicianId
   * @param {(snapshot: (Object|null)) => (Object|null|undefined)} mutator
   * @returns {Promise<Object|null>}
   */
  async runSnapshotMutation(technicianId, mutator) {
    if (this.memory !== null) {
      const current = this.memory.snapshots.get(technicianId) ?? null;
      const next = mutator(current);

      if (next === null || next === undefined) {
        return current;
      }

      this.memory.snapshots.set(technicianId, next);

      return next;
    }

    const db = await this.open();
    const transaction = db.transaction(ROUTE_SNAPSHOTS_STORE, 'readwrite');

    try {
      const store = transaction.objectStore(ROUTE_SNAPSHOTS_STORE);
      const current = (await requestResult(store.get(technicianId))) ?? null;
      const next = mutator(current);

      if (next !== null && next !== undefined && typeof next.then === 'function') {
        throw new OfflineQueueError(
          'SNAPSHOT_MUTATOR_ASYNC',
          'El mutador de la copia local debe ser síncrono para no romper la transacción.'
        );
      }

      if (next !== null && next !== undefined) {
        store.put(next);
      }

      await transactionComplete(transaction);

      return next ?? current;
    } catch (error) {
      if (transaction.error === null) {
        try {
          transaction.abort();
        } catch { /* La transacción ya estaba cerrada. */ }
      }

      throw error;
    }
  }

  /**
   * @param {number} technicianId
   * @returns {Promise<Object|null>}
   */
  async getSnapshot(technicianId) {
    const key = Number(technicianId);
    if (this.memory !== null) {
      return this.memory.snapshots.get(key) ?? null;
    }

    const db = await this.open();
    const request = db.transaction(ROUTE_SNAPSHOTS_STORE, 'readonly')
      .objectStore(ROUTE_SNAPSHOTS_STORE)
      .get(key);

    return (await requestResult(request)) ?? null;
  }

  /**
   * Vigencia de la copia del técnico, lista para pintar el aviso o bloquear la
   * captura de nuevas acciones (`RF-02.2` a `RF-02.4`).
   *
   * @param {number} technicianId
   * @param {number} [nowMs=Date.now()]
   * @returns {Promise<Object>}
   */
  async getValidity(technicianId, nowMs = Date.now()) {
    return evaluateSnapshotValidity(await this.getSnapshot(technicianId), nowMs);
  }

  /**
   * Anota el estado de turno observado para poder restaurarlo sin red
   * (`EARS 03.1`, `EARS 06.2`).
   *
   * Solo se escribe si el técnico ya tiene copia local: sin ruta no hay
   * intervención que iniciar y el registro quedaría huérfano.
   *
   * @param {number} technicianId
   * @param {string} status - `ON_SHIFT` | `MEAL_BREAK` | `OFF_SHIFT`
   * @param {number} [nowMs=Date.now()]
   * @returns {Promise<Object|null>} Copia actualizada, o `null` si no hay copia
   */
  async saveShiftStatus(technicianId, status, nowMs = Date.now()) {
    const normalized = String(status ?? '').toUpperCase();
    if (!OFFLINE_SHIFT_STATUSES.includes(normalized)) {
      return null;
    }

    return this.mutateSnapshot(technicianId, (snapshot) => {
      if (snapshot === null) {
        return null;
      }

      return {
        ...snapshot,
        offline_shift: {
          status: normalized,
          recorded_at: new Date(nowMs).toISOString()
        }
      };
    });
  }

  /**
   * @param {Object} mutation
   * @returns {Promise<Object>}
   */
  async putMutation(mutation) {
    if (this.memory !== null) {
      this.memory.outbox.set(String(mutation.client_mutation_id), mutation);
      return mutation;
    }

    const db = await this.open();
    const transaction = db.transaction(MUTATION_OUTBOX_STORE, 'readwrite');
    transaction.objectStore(MUTATION_OUTBOX_STORE).put(mutation);
    await transactionComplete(transaction);

    return mutation;
  }

  /**
   * @param {string} clientMutationId
   * @returns {Promise<Object|null>}
   */
  async getMutation(clientMutationId) {
    const key = String(clientMutationId);
    if (this.memory !== null) {
      return this.memory.outbox.get(key) ?? null;
    }

    const db = await this.open();
    const request = db.transaction(MUTATION_OUTBOX_STORE, 'readonly')
      .objectStore(MUTATION_OUTBOX_STORE)
      .get(key);

    return (await requestResult(request)) ?? null;
  }

  /**
   * @param {string} clientMutationId
   * @returns {Promise<void>}
   */
  async deleteMutation(clientMutationId) {
    const key = String(clientMutationId);
    if (this.memory !== null) {
      this.memory.outbox.delete(key);
      return;
    }

    const db = await this.open();
    const transaction = db.transaction(MUTATION_OUTBOX_STORE, 'readwrite');
    transaction.objectStore(MUTATION_OUTBOX_STORE).delete(key);
    await transactionComplete(transaction);
  }

  /**
   * Elementos de la bandeja del técnico en orden FIFO estricto (`RF-04.1`).
   *
   * @param {number} technicianId
   * @param {Array<string>|null} [statuses=null] - Filtrar por estado; `null` devuelve todos.
   * @returns {Promise<Array<Object>>}
   */
  async listMutations(technicianId, statuses = null) {
    const key = Number(technicianId);
    const wanted = Array.isArray(statuses) ? statuses : null;
    const matches = (mutation) => wanted === null || wanted.includes(mutation.status);

    let items;
    if (this.memory !== null) {
      items = [...this.memory.outbox.values()].filter((item) => Number(item.technician_id) === key);
    } else {
      const db = await this.open();
      const index = db.transaction(MUTATION_OUTBOX_STORE, 'readonly')
        .objectStore(MUTATION_OUTBOX_STORE)
        .index('by_technician');

      items = (await requestResult(index.getAll(key))) ?? [];
    }

    return items
      .filter(matches)
      .sort((left, right) => Number(left.sequence_order) - Number(right.sequence_order));
  }

  /**
   * Da de alta una mutación y su reflejo optimista en una sola transacción (`H4`).
   *
   * Antes se calculaba el `sequence_order`, se escribía la proyección y, por
   * último, se insertaba la entrada. Una colisión del índice único
   * `by_tech_sequence` —dos altas concurrentes que leyeron el mismo máximo— dejaba
   * el cambio local aplicado y la cola sin su registro: la parada aparecía
   * iniciada o resuelta y nadie la enviaba nunca.
   *
   * Ahora el número de secuencia, el alta, el cierre del inicio previo y la
   * proyección comparten transacción: o queda todo, o no queda nada. Si otra
   * pestaña se adelantó con el mismo número, el motor aborta por restricción y se
   * reintenta releyendo el máximo.
   *
   * @param {number} technicianId
   * @param {(context: {sequenceOrder: number}) => Object} build Construye el registro
   *        definitivo con el número de secuencia ya asignado.
   * @param {{closePriorStart?: (Object|null)}} [options={}] Inicio previo de la
   *        cadena, que se cierra con `chain_ended_at` en la misma transacción.
   * @returns {Promise<Object>} Registro encolado
   */
  async enqueueMutation(technicianId, build, options = {}) {
    const key = Number(technicianId);

    if (!Number.isInteger(key) || key < 1) {
      throw new OfflineQueueError(
        'INVALID_TECHNICIAN',
        'La bandeja sin conexión exige un técnico autenticado.'
      );
    }

    const closePrior = options?.closePriorStart ?? null;
    const project = (snapshot, record) => applyOptimisticStopChange(snapshot, {
      stopType: record.stop_type,
      stopId: record.stop_id,
      operationType: record.operation_type,
      payload: record.payload ?? {}
    });

    if (this.memory !== null) {
      const items = [...this.memory.outbox.values()]
        .filter((item) => Number(item.technician_id) === key);
      const highest = items.reduce((max, item) => Math.max(max, Number(item.sequence_order ?? 0)), 0);
      const record = build({ sequenceOrder: highest + 1 });
      const snapshot = this.memory.snapshots.get(key) ?? null;
      const projected = snapshot === null ? null : project(snapshot, record);

      if (closePrior !== null) {
        this.memory.outbox.set(String(closePrior.client_mutation_id), closePrior);
      }
      this.memory.outbox.set(String(record.client_mutation_id), record);
      if (projected !== null) {
        this.memory.snapshots.set(key, projected);
      }

      return record;
    }

    const db = await this.open();

    for (let attempt = 0; attempt < ENQUEUE_SEQUENCE_RETRIES; attempt++) {
      try {
        return await this.insertQueuedMutation(db, key, build, closePrior, project);
      } catch (error) {
        if (error?.name !== 'ConstraintError') {
          throw error;
        }
      }
    }

    throw new OfflineQueueError(
      'OFFLINE_SEQUENCE_EXHAUSTED',
      'No se pudo reservar un turno en la bandeja del técnico. Reintenta la acción.'
    );
  }

  /**
   * Intento único de alta dentro de una transacción sobre los dos almacenes.
   *
   * @param {IDBDatabase} db
   * @param {number} technicianId
   * @param {(context: {sequenceOrder: number}) => Object} build
   * @param {(Object|null)} closePrior
   * @param {(snapshot: Object, record: Object) => Object} project
   * @returns {Promise<Object>}
   */
  async insertQueuedMutation(db, technicianId, build, closePrior, project) {
    const transaction = db.transaction([MUTATION_OUTBOX_STORE, ROUTE_SNAPSHOTS_STORE], 'readwrite');

    try {
      const outbox = transaction.objectStore(MUTATION_OUTBOX_STORE);
      const snapshots = transaction.objectStore(ROUTE_SNAPSHOTS_STORE);
      const existing = (await requestResult(outbox.index('by_technician').getAll(technicianId))) ?? [];
      const highest = existing.reduce((max, item) => Math.max(max, Number(item?.sequence_order ?? 0)), 0);
      const record = build({ sequenceOrder: highest + 1 });

      const snapshot = (await requestResult(snapshots.get(technicianId))) ?? null;
      const projected = snapshot === null ? null : project(snapshot, record);

      if (closePrior !== null) {
        outbox.put(closePrior);
      }
      outbox.put(record);
      if (projected !== null) {
        snapshots.put(projected);
      }

      await transactionComplete(transaction);

      return record;
    } catch (error) {
      if (transaction.error === null) {
        try {
          transaction.abort();
        } catch { /* La transacción ya estaba cerrada. */ }
      }

      throw transaction.error ?? error;
    }
  }

  /**
   * Devuelve a la cola los envíos que quedaron huérfanos en `SYNCING`.
   *
   * Un elemento en `SYNCING` sin la operación viva detrás es trabajo que nunca
   * volvería a enviarse: `processOutboxQueue` solo recoge `PENDING` y el cierre
   * de sesión lo cuenta como vivo. Reencolarlo es seguro porque el servidor es
   * idempotente por `(technician_id, client_mutation_id)`: si el envío llegó a
   * aplicarse antes de morir la pestaña, el reenvío recibe la respuesta original
   * y no mueve stock, dinero ni estado (`RF-04.2`).
   *
   * @param {number} technicianId
   * @param {number} [nowMs=Date.now()]
   * @param {number} [staleAfterMs=SYNCING_STALE_AFTER_MS]
   * @returns {Promise<Array<Object>>} Elementos devueltos a `PENDING`
   */
  async recoverInterruptedMutations(
    technicianId,
    nowMs = Date.now(),
    staleAfterMs = SYNCING_STALE_AFTER_MS
  ) {
    const items = await this.listMutations(technicianId, [MUTATION_STATUS.SYNCING]);
    const recovered = [];

    for (const item of items) {
      const startedAt = Date.parse(String(item?.syncing_since ?? ''));
      // Sin marca de inicio no hay forma de saber si el envío sigue vivo, y una
      // marca en el futuro solo puede venir de un reloj que retrocedió después:
      // ambos casos se tratan como huérfanos, que es el fallo seguro (el reenvío
      // es idempotente).
      const age = Number.isNaN(startedAt) || startedAt > nowMs
        ? Number.POSITIVE_INFINITY
        : nowMs - startedAt;

      if (age < staleAfterMs) {
        continue;
      }

      const requeued = { ...item, status: MUTATION_STATUS.PENDING, syncing_since: null };
      await this.putMutation(requeued);
      recovered.push(requeued);
    }

    return recovered;
  }

  /**
   * Aplica la mutación optimista a la copia local del técnico (`D5`).
   * @param {number} technicianId
   * @param {Object} mutation
   * @returns {Promise<Object|null>} Copia actualizada, o `null` si no hay copia local
   */
  async applyOptimisticMutation(technicianId, mutation) {
    return this.mutateSnapshot(technicianId, (snapshot) => {
      if (snapshot === null) {
        return null;
      }

      return applyOptimisticStopChange(snapshot, {
        stopType: mutation.stop_type,
        stopId: mutation.stop_id,
        operationType: mutation.operation_type,
        payload: mutation.payload ?? {}
      });
    });
  }

  /**
   * Revierte el descuento provisional de una transición rechazada (`RF-05.1.1`).
   * @param {number} technicianId
   * @param {Array<{spare_part_id: number, quantity: number}>} parts
   * @returns {Promise<Object|null>}
   */
  async revertProvisionalStock(technicianId, parts = []) {
    if (!Array.isArray(parts) || parts.length === 0) {
      return this.getSnapshot(technicianId);
    }

    return this.mutateSnapshot(technicianId, (snapshot) => (
      snapshot === null ? null : revertProvisionalStockChange(snapshot, parts)
    ));
  }

  /**
   * Borra la copia local y la bandeja del técnico (`EARS 06.5`, `QA 5`).
   * @param {number} technicianId
   * @returns {Promise<void>}
   */
  async clearTechnician(technicianId) {
    const key = Number(technicianId);

    if (this.memory !== null) {
      this.memory.snapshots.delete(key);
      for (const [id, item] of this.memory.outbox) {
        if (Number(item.technician_id) === key) {
          this.memory.outbox.delete(id);
        }
      }
      return;
    }

    const db = await this.open();
    const transaction = db.transaction([ROUTE_SNAPSHOTS_STORE, MUTATION_OUTBOX_STORE], 'readwrite');
    transaction.objectStore(ROUTE_SNAPSHOTS_STORE).delete(key);

    const outbox = transaction.objectStore(MUTATION_OUTBOX_STORE);
    const request = outbox.index('by_technician').getAllKeys(key);
    request.onsuccess = () => {
      for (const id of request.result ?? []) {
        outbox.delete(id);
      }
    };

    await transactionComplete(transaction);
  }

  /**
   * Identificadores de técnico con copia local en este dispositivo.
   *
   * @returns {Promise<Array<number>>}
   */
  async listTechnicianIds() {
    if (this.memory !== null) {
      return [...this.memory.snapshots.keys()].map(Number);
    }

    const db = await this.open();
    const keys = await requestResult(
      db.transaction(ROUTE_SNAPSHOTS_STORE, 'readonly')
        .objectStore(ROUTE_SNAPSHOTS_STORE)
        .getAllKeys()
    );

    return (keys ?? []).map(Number);
  }

  /**
   * Purga los datos de sesiones ajenas al técnico que acaba de entrar (`M3`).
   *
   * Un dispositivo de ruta se comparte entre turnos: en cuanto otra cuenta
   * inicia sesión, la copia de la anterior —ruta, contactos y evidencias— deja
   * de tener dueño en ese aparato. Se borra solo cuando ya no conserva trabajo
   * pendiente: una cola viva es trabajo del técnico y no se descarta nunca en
   * silencio (`Art. III.1`). Lo que se conserva se devuelve para poder avisarlo.
   *
   * @param {number} technicianId Técnico de la sesión actual
   * @returns {Promise<{purged: number, retained: Array<number>}>}
   */
  async purgeForeignTechnicians(technicianId) {
    const keeper = Number(technicianId);
    const ids = await this.listTechnicianIds();
    const retained = [];
    let purged = 0;

    for (const id of ids) {
      if (id === keeper) {
        continue;
      }

      if ((await this.listMutations(id)).length > 0) {
        retained.push(id);
        continue;
      }

      await this.clearTechnician(id);
      purged++;
    }

    return { purged, retained };
  }

  /**
   * Purga copias caducadas que ya no tienen nada que enviar (`M3`, `RF-02.4`).
   *
   * Pasadas las 12 h la copia es de solo lectura: no admite acciones nuevas y
   * solo sirve para sincronizar lo que ya está en la bandeja. Cuando además la
   * bandeja está vacía, conservarla únicamente acumula datos personales.
   *
   * @param {number} [nowMs=Date.now()]
   * @returns {Promise<Array<number>>} Técnicos purgados
   */
  async purgeExpiredSessions(nowMs = Date.now()) {
    const ids = await this.listTechnicianIds();
    const purged = [];

    for (const id of ids) {
      const validity = evaluateSnapshotValidity(await this.getSnapshot(id), nowMs);

      if (validity.status !== SNAPSHOT_VALIDITY.EXPIRED_READONLY) {
        continue;
      }

      if ((await this.listMutations(id)).length > 0) {
        continue;
      }

      await this.clearTechnician(id);
      purged.push(id);
    }

    return purged;
  }

  /**
   * Abre (una sola vez) la base local con sus dos almacenes e índices (`§3.1`, `§3.2`).
   * @returns {Promise<IDBDatabase>}
   */
  async open() {
    if (this.memory !== null) {
      throw new OfflineQueueError('INDEXEDDB_UNAVAILABLE', 'Este entorno no dispone de IndexedDB.');
    }

    if (this.dbPromise === null) {
      this.dbPromise = new Promise((resolve, reject) => {
        const request = this.factory.open(OFFLINE_DB_NAME, OFFLINE_DB_VERSION);

        request.onupgradeneeded = (event) => {
          const db = request.result;

          if (!db.objectStoreNames.contains(ROUTE_SNAPSHOTS_STORE)) {
            db.createObjectStore(ROUTE_SNAPSHOTS_STORE, { keyPath: 'technician_id' });
          }

          if (!db.objectStoreNames.contains(MUTATION_OUTBOX_STORE)) {
            const outbox = db.createObjectStore(MUTATION_OUTBOX_STORE, { keyPath: 'client_mutation_id' });
            outbox.createIndex('by_technician', 'technician_id');
            outbox.createIndex('by_tech_sequence', ['technician_id', 'sequence_order'], { unique: true });
            outbox.createIndex('by_tech_status', ['technician_id', 'status']);
          }

          // v1 → v2 (`M3`): la evidencia pasa de `base64` a `blob` sin perder
          // ninguna foto que estuviera esperando al servidor.
          if (Number(event?.oldVersion ?? 0) < 2) {
            migrateOutboxPhotosToV2(request);
            // Y las copias que la v1 guardó antes de que existiera la
            // minimización se limpian en el mismo paso (`R4`).
            minimizeStoredSnapshots(request);
          }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
      });
    }

    return this.dbPromise;
  }
}

/**
 * Reescribe las evidencias de la bandeja en la forma de la v2 durante la
 * actualización de esquema (`M3`).
 *
 * Recorre el almacén con un cursor dentro de la misma transacción
 * `versionchange`: si algo falla, la actualización se aborta y el navegador
 * conserva la v1 intacta, que es el fallo seguro.
 *
 * @param {IDBOpenDBRequest} request
 * @returns {void}
 */
function migrateOutboxPhotosToV2(request) {
  const transaction = request?.transaction;

  if (!transaction || typeof transaction.objectStore !== 'function') {
    return;
  }

  let outbox;
  try {
    outbox = transaction.objectStore(MUTATION_OUTBOX_STORE);
  } catch (error) {
    return;
  }

  if (outbox === null || outbox === undefined || typeof outbox.openCursor !== 'function') {
    return;
  }

  const cursorRequest = outbox.openCursor();
  cursorRequest.onsuccess = () => {
    const cursor = cursorRequest.result;

    if (!cursor) {
      return;
    }

    const migrated = migrateQueuedPhotoAttachment(cursor.value);
    if (migrated !== cursor.value) {
      cursor.update(migrated);
    }

    cursor.continue();
  };
}

/**
 * Re-minimiza las copias de ruta que la v1 guardó sin minimizar (`R4`).
 *
 * La minimización de `M3` se aplica al normalizar el paquete del servidor, así
 * que una instalación que actualizaba conservaba el correo y el nombre del
 * contacto de sede ya escritos hasta el siguiente bootstrap; y sin cobertura ese
 * bootstrap puede no llegar en días. La limpieza se hace aquí, dentro de la
 * misma transacción `versionchange` que migra la evidencia: la actualización y
 * la poda son un solo paso atómico y, si algo falla, el navegador conserva la
 * v1 intacta (el fallo seguro de la migración).
 *
 * Una fila que ya está limpia no se reescribe, y el error de una fila concreta
 * no tumba la actualización: se conserva tal cual y el siguiente paquete la
 * normaliza. La cola no se toca.
 *
 * @param {IDBOpenDBRequest} request
 * @returns {void}
 */
function minimizeStoredSnapshots(request) {
  const transaction = request?.transaction;

  if (!transaction || typeof transaction.objectStore !== 'function') {
    return;
  }

  let snapshots;
  try {
    snapshots = transaction.objectStore(ROUTE_SNAPSHOTS_STORE);
  } catch (error) {
    // Base recién creada: el almacén existe, pero no hay nada que podar.
    return;
  }

  if (snapshots === null || snapshots === undefined || typeof snapshots.openCursor !== 'function') {
    return;
  }

  const cursorRequest = snapshots.openCursor();
  cursorRequest.onerror = (event) => {
    // Una copia ilegible no puede impedir el arranque: la cola, que es lo único
    // que no se puede perder, vive en su propio almacén.
    event?.preventDefault?.();
  };
  cursorRequest.onsuccess = () => {
    const cursor = cursorRequest.result;

    if (!cursor) {
      return;
    }

    const current = cursor.value;

    if (current !== null && typeof current === 'object' && !Array.isArray(current)) {
      try {
        const minimized = minimizeSnapshot(current);

        if (JSON.stringify(minimized) !== JSON.stringify(current)) {
          const updateRequest = cursor.update(minimized);
          updateRequest.onerror = (event) => event?.preventDefault?.();
        }
      } catch (error) {
        // Fila no escribible o no serializable: se conserva como estaba.
        // Nunca se aborta la actualización por una copia; la cola es lo único
        // que no se puede perder.
      }
    }

    cursor.continue();
  };
}

/** Envuelve una petición de `IndexedDB` en una promesa. */
function requestResult(request) {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

/** Espera el cierre de una transacción (commit real, no solo la petición). */
function transactionComplete(transaction) {
  return new Promise((resolve, reject) => {
    transaction.oncomplete = () => resolve();
    transaction.onerror = () => reject(transaction.error);
    transaction.onabort = () => reject(transaction.error);
  });
}

/** Descuenta del saldo visible y acumula el descuento provisional por repuesto. */
function applyStockDeduction(snapshot, parts) {
  for (const part of parts) {
    const key = String(part.spare_part_id);
    snapshot.provisional_stock_deductions[key] =
      (snapshot.provisional_stock_deductions[key] ?? 0) + Number(part.quantity);

    decrementAvailable(snapshot.van_stock, part);
  }
}

/** Baja unidades del saldo visible de una línea, sin dejarlo en negativo. */
function decrementAvailable(vanStock, part) {
  const item = vanStock.items.find(
    (candidate) => Number(candidate?.spare_part_id) === Number(part.spare_part_id)
  );

  if (item) {
    item.quantity_available = Math.max(0, Number(item.quantity_available ?? 0) - Number(part.quantity));
  }
}

/** Descuentos pendientes de una copia anterior, en la forma del extractor. */
function provisionalParts(previous) {
  return Object.entries(previous?.provisional_stock_deductions ?? {})
    .map(([sparePartId, quantity]) => ({
      spare_part_id: Number(sparePartId),
      quantity: Number(quantity)
    }))
    .filter((part) => Number.isInteger(part.spare_part_id) && part.spare_part_id > 0
      && Number.isInteger(part.quantity) && part.quantity > 0);
}

/** Número positivo con respaldo cuando el servidor no lo envía. */
function positiveNumberOr(value, fallback) {
  const parsed = Number(value);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

/** Lee el contenido binario de la foto como base64, sin dependencias. */
async function readAsBase64(photo) {
  if (typeof photo.arrayBuffer !== 'function' || typeof btoa !== 'function') {
    return '';
  }

  const bytes = new Uint8Array(await photo.arrayBuffer());
  let binary = '';
  const chunkSize = 0x8000;

  for (let offset = 0; offset < bytes.length; offset += chunkSize) {
    binary += String.fromCharCode(...bytes.subarray(offset, offset + chunkSize));
  }

  return btoa(binary);
}

export default {
  OFFLINE_DB_NAME,
  OFFLINE_DB_VERSION,
  ROUTE_SNAPSHOTS_STORE,
  MUTATION_OUTBOX_STORE,
  SNAPSHOT_WARNING_AGE_MS,
  SNAPSHOT_MAX_VALID_AGE_MS,
  OFFLINE_SHIFT_STATUSES,
  OFFLINE_PHOTO_MAX_COUNT,
  OFFLINE_PHOTO_MAX_SINGLE_BYTES,
  OFFLINE_PHOTO_MAX_TOTAL_BYTES,
  OFFLINE_SIGNATURE_MAX_BYTES,
  SNAPSHOT_VALIDITY,
  MUTATION_STATUS,
  SYNCING_STALE_AFTER_MS,
  STOP_TYPE,
  PERISHABLE_MACHINE_TYPES,
  OFFLINE_OPERATIONS,
  OPTIMISTIC_STATUS_BY_OPERATION,
  OFFLINE_MESSAGES,
  OfflineQueueError,
  OfflineStorage,
  evaluateSnapshotValidity,
  evaluateOfflineShift,
  requiresSanitarySeal,
  extractConsumedCatalogParts,
  validatePhotoAttachment,
  normalizeSnapshot,
  normalizeVanStock,
  applyOptimisticStopChange,
  revertProvisionalStockChange,
  serializePhoto,
  photoAttachmentBlob,
  minimizeSnapshot,
  migrateQueuedPhotoAttachment
};
